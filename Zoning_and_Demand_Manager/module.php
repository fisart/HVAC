<?php
/**
 * Zoning_and_Demand_Manager
 *
 * Version: 1.3.1 (Startup guard + safe JSON decode)
 * Vendor:  Artur Fischer & AI Consultant
 *
 * Kurzbeschreibung:
 *  - Steuert Zonenklappen (boolean/linear) anhand Ist/Soll + Hysterese
 *  - Unterdrückt Kühlung bei geöffneten Fenstern/Türen (mit Debounce)
 *  - Liefert Aggregates (Anzahl aktive Räume, max ΔT, Fensterstatus)
 *  - Bietet Orchestrator-APIs für Override, System- und Flap-Kommandos
 *  - Reentranzschutz via Semaphore
 *  - Sämtliche Logs im Symcon-Meldungsfenster (LogMessage)
 */

declare(strict_types=1);

class Zoning_and_Demand_Manager extends IPSModule
{
    // ---------- Lifecycle ----------

    public function Create()
    {
        parent::Create();

        // Basis-Properties (sollten zu deiner form.json passen)
        $this->RegisterPropertyBoolean('StandaloneMode', false);
        $this->RegisterPropertyInteger('TimerInterval', 60); // Sekunden
        $this->RegisterPropertyFloat('Hysteresis', 0.5);
        $this->RegisterPropertyInteger('StandalonePowerVar', 0); // optional: Integer 0..100
        $this->RegisterPropertyInteger('StandaloneFanVar', 0);   // optional: Integer 0..100

        // Systemweite Verknüpfungen (IDs von Variablen/Aktoren)
        $this->RegisterPropertyInteger('HeatingActiveLink', 0);
        $this->RegisterPropertyInteger('VentilationActiveLink', 0);
        $this->RegisterPropertyInteger('MainACOnOffLink', 0);     // bool oder integer (0..100)
        $this->RegisterPropertyInteger('MainFanControlLink', 0);  // bool oder integer (0..100)
        $this->RegisterPropertyInteger('MainACPowerLink', 0);
        $this->RegisterPropertyInteger('MainFanSpeedLink', 0);
                // ---- Neue Properties für Notabschaltung ----
        $this->RegisterPropertyInteger('CoilTemperatureLink', 0);
        $this->RegisterPropertyFloat('EmergencyShutdownTemp', 1.0);
        $this->RegisterPropertyFloat('EmergencyRestartTemp', 5.0);
        $this->RegisterAttributeInteger('CoilTempEventID', 0);
        $this->RegisterPropertyInteger('EmergencyFanPercent', 60); // 0..100
        $this->RegisterPropertyBoolean('EmergencyCloseFlaps', false);


        // ---- Attribute ----
        $this->RegisterAttributeBoolean('EmergencyShutdownActive', false); // NEU: Merker für Not-Aus-Zustand
        // Standalone-Konstanten (optional)
        $this->RegisterPropertyInteger('ConstantPower', 80);      // 0..100
        $this->RegisterPropertyInteger('ConstantFanSpeed', 80);   // 0..100

        // Raumliste (JSON aus der Form)
        $this->RegisterPropertyString('ControlledRooms', '[]');

        // ---- Neue Properties ----
        $this->RegisterPropertyInteger('WindowDebounceSec', 10); // 0..120 Sekunden
        $this->RegisterPropertyInteger('LogLevel', 3);           // 0=ERROR,1=WARN,2=INFO,3=DEBUG
        // Adaptive (ACIPS) cooperation
        $this->RegisterPropertyInteger('AdaptiveInstanceID', 0);
        // ---- Attribute ----
        $this->RegisterAttributeString('WindowStable', '{}');    // {roomName:{open:bool, ts:int}}
        $this->RegisterAttributeString('LastAggregates', '{}');  // Cache/Debug
        $this->RegisterAttributeString('LastOrchestratorFlaps', '[]');
        $this->RegisterAttributeString('RoomHystState', '{}'); // per-room latched demand (bool)

        // ---- Status-Variablen ----
        $this->RegisterVariableBoolean('OverrideActive', 'Override active', '~Alert', 10);
        $this->DisableAction('OverrideActive'); // nur Anzeige

        // ---- Timer ----
        $this->RegisterTimer('ZDM_Timer', 0, 'ZDM_ProcessZoning($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            // Re-run ApplyChanges when kernel becomes ready
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            // Keep timer off until ready
            $this->SetTimerInterval('ZDM_Timer', 0);
            return;
        }

        $this->SetStatus(102);

        $intervalMs = max(1, (int)$this->ReadPropertyInteger('TimerInterval')) * 1000;
        $this->SetTimerInterval('ZDM_Timer', $intervalMs);

        $this->log(2, 'apply_changes', [
            'interval_s' => (int)$this->ReadPropertyInteger('TimerInterval'),
            'hyst'       => (float)$this->ReadPropertyFloat('Hysteresis')
        ]);
        $this->refreshOverrideIndicator();
        $this->ensureCoilTempEvent();


    }

    // ---------- Public (Timer) ----------

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            // Kernel is ready → arm timer now
            $this->ApplyChanges();
        }
    }

    public function ProcessZoning(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->log(1, 'kernel_not_ready_skip');
            return;
        }

        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        $shouldTickACIPS = false;
        try {
            // =================================================================
            // === NOT-AUS-LOGIK (höchste Priorität) | AC AUS, LÜFTER WEITER ===
            // =================================================================
            $coilSensorID = (int)$this->ReadPropertyInteger('CoilTemperatureLink');
            if ($coilSensorID > 0) {
                $shutdownTemp = (float)$this->ReadPropertyFloat('EmergencyShutdownTemp');
                $restartTemp  = (float)$this->ReadPropertyFloat('EmergencyRestartTemp');
                $coilTemp     = $this->GetFloat($coilSensorID);
                $isShutdown   = (bool)$this->ReadAttributeBoolean('EmergencyShutdownActive');

                // neue Parameter: Lüfter-Notbetrieb & optional Klappen schließen
                $fanPct = $this->readEmergencyFanPercent();
                $closeFlaps = (bool)$this->ReadPropertyBoolean('EmergencyCloseFlaps');

                if ($isShutdown) {
                    // Wir SIND im Not-Aus-Zustand. Prüfe, ob wir wieder starten dürfen.
                    if (is_finite($coilTemp) && $coilTemp >= $restartTemp) {
                        $this->WriteAttributeBoolean('EmergencyShutdownActive', false);
                        $this->log(2, 'emergency_shutdown_ended', ['coilTemp' => $coilTemp, 'restartTemp' => $restartTemp]);
                        // weiter mit normaler Logik
                    } else {
                        // immer noch zu kalt → AC aus, Lüfter im Notbetrieb
                        $this->log(1, 'emergency_shutdown_maintained', ['coilTemp' => $coilTemp, 'restartTemp' => $restartTemp]);
                        $this->systemSetPercent(0, $fanPct);
                        if ($closeFlaps) { $this->applyAllFlaps(false); }
                        return;
                    }
                } else {
                    // NORMAL-Betrieb. Prüfe, ob Not-Aus aktivieren.
                    if (is_finite($coilTemp) && $coilTemp <= $shutdownTemp) {
                        $this->WriteAttributeBoolean('EmergencyShutdownActive', true);
                        $this->log(0, 'EMERGENCY_SHUTDOWN_ACTIVATED', ['coilTemp' => $coilTemp, 'shutdownTemp' => $shutdownTemp]);
                        $this->systemSetPercent(0, $fanPct);
                        if ($closeFlaps) { $this->applyAllFlaps(false); }
                        return;
                    }
                }
            }
            // === ENDE NOT-AUS-LOGIK ===
            // =================================================================

            // Override-Modus blockiert die normale Regelung
            $override = GetValue($this->GetIDForIdent('OverrideActive'));
            if ($override) {
                $this->log(2, 'override_active_mode_process');
                $this->GetAggregates();
                return;
            }

            if ($this->isBlockedByHeatingOrVentilation()) {
                $this->log(2, 'blocked_by_heating_or_ventilation');
                $this->systemSetPercent(0, 0); // hier auch Lüfter aus, da kein Notfall
                $this->applyAllFlaps(false);
                return;
            }

            $rooms = $this->getRooms();
            if (!$rooms) {
                $this->log(2, 'no_rooms_configured');
                return;
            }

            // ---- Zustandshysterese (pro Raum) ----
            $anyDemand = false;
            $hyst = (float)$this->ReadPropertyFloat('Hysteresis');
            $hmap = $this->getHystState(); // { roomName => bool }

            foreach ($rooms as $room) {
                $name = (string)($room['name'] ?? 'room');
                $coolingPhaseVarID  = (int)($room['coolingPhaseInfoID'] ?? 0);
                $airSollStatusVarID = (int)($room['airSollStatusID'] ?? 0);
                $demandVarID        = (int)($room['demandID'] ?? 0);

                // Fenster-Override hat Vorrang
                if ($this->isWindowOpenStable($room)) {
                    $this->setFlap($room, false);
                    if ($coolingPhaseVarID > 0) $this->writeVarSmart($coolingPhaseVarID, 3);
                    if ($demandVarID > 0)       $this->writeVarSmart($demandVarID, 0);
                    $hmap[$name] = false; // Hysterese-Zustand zurücksetzen
                    $this->log(3, 'room_window_open_flap_closed', ['room' => $name]);
                    continue;
                }

                $roomMode = ($airSollStatusVarID > 0) ? $this->GetInt($airSollStatusVarID) : 3;
                $roomHasDemand = false;

                if ($roomMode === 2) {
                    // Direkter Vergleich (ohne Hysterese), Hysterese-Map nur spiegeln
                    $ist  = $this->GetFloat((int)($room['tempID'] ?? 0));
                    $soll = $this->GetFloat((int)($room['targetID'] ?? 0));
                    $roomHasDemand = (is_finite($ist) && is_finite($soll) && $ist > $soll);
                    $hmap[$name] = $roomHasDemand;
                    if (!$roomHasDemand && $airSollStatusVarID > 0) {
                        $this->writeVarSmart($airSollStatusVarID, 1);
                    }
                } elseif ($roomMode === 3) {
                    // Echte Hysterese mit Gedächtnis:
                    //  - Wenn vorher AUS: AN bei (ist - soll) >= hyst
                    //  - Wenn vorher AN : AN bis (ist - soll) <= 0
                    $ist  = $this->GetFloat((int)($room['tempID'] ?? 0));
                    $soll = $this->GetFloat((int)($room['targetID'] ?? 0));
                    $prev = (bool)($hmap[$name] ?? false);

                    if (!is_finite($ist) || !is_finite($soll)) {
                        $this->log(1, 'hyst_invalid_values', ['room'=>$name,'ist'=>$ist,'soll'=>$soll]);
                        $roomHasDemand = false;
                    } else {
                        $delta = $ist - $soll;
                        $roomHasDemand = $prev ? ($delta > 0.0) : ($delta >= $hyst);
                        $this->log(3, 'hyst_eval', [
                            'room'=>$name,'prev'=>$prev,'ist'=>$ist,'soll'=>$soll,
                            'delta'=>$delta,'on_thr'=>$hyst,'off_thr'=>0.0,'new'=>$roomHasDemand
                        ]);
                    }
                    $hmap[$name] = $roomHasDemand;
                } else {
                    // Mode 1 oder unbekannt: kein Bedarf
                    $hmap[$name] = false;
                }

                if ($roomHasDemand) {
                    $this->setFlap($room, true);
                    $anyDemand = true;
                    if ($demandVarID > 0)       $this->writeVarSmart($demandVarID, 3);
                    if ($coolingPhaseVarID > 0) $this->writeVarSmart($coolingPhaseVarID, 2);
                    $this->log(2, 'room_demand_on', ['room' => $name, 'mode' => $roomMode]);
                } else {
                    $this->setFlap($room, false);
                    if ($demandVarID > 0)       $this->writeVarSmart($demandVarID, 0);
                    if ($coolingPhaseVarID > 0) $this->writeVarSmart($coolingPhaseVarID, 0);
                    $this->log(2, 'room_demand_off', ['room' => $name, 'mode' => $roomMode]);
                }
            }

            // Persistiere Hysterese-Zustände einmal pro Tick
            $this->setHystState($hmap);

            $this->log(2, 'DECIDE_SYSTEM', ['anyDemand' => $anyDemand]);
            if ($anyDemand) {
                if ($this->ReadPropertyBoolean('StandaloneMode')) {
                    $this->systemOnStandalone();
                } else {
                    // Kooperativer Modus: minimal anfordern, ACIPS passt an
                    $this->systemSetPercent(1, 1);
                    $shouldTickACIPS = !(bool)$this->ReadAttributeBoolean('EmergencyShutdownActive');
                }
            } else {
                $this->systemSetPercent(0, 0);
            }

            $this->GetAggregates();

        } finally {
            $this->guardLeave();
        }

        // ---- ACIPS außerhalb der Semaphore triggern (Deadlock vermeiden) ----
        if ($shouldTickACIPS) {
            $acipsID = (int)$this->ReadPropertyInteger('AdaptiveInstanceID');
            if ($acipsID > 0 && IPS_InstanceExists($acipsID)) {
                @IPS_RunScriptText('ACIPS_ProcessLearning(' . $acipsID . ');');
                $this->log(3, 'triggered_acips_tick', ['acipsID' => $acipsID]);
            } else {
                $this->log(1, 'acips_not_configured_for_trigger', ['acipsID' => $acipsID]);
            }
        }
    }
   


    // ---------- Public (Orchestrator APIs) ----------

    /**
     * Orchestrator setzt/löscht Override.
     */
    public function SetOverrideMode(bool $on): void
    {
           // NEUER WÄCHTER:
        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $this->log(2, 'ignore_set_override', ['reason' => 'Standalone Mode is active']);
            return; // Befehl ignorieren und Funktion sofort verlassen
        }

        // Trace caller + requested value
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown';
        $this->log(2, 'DEBUG_OVERRIDE_SET_CALL', ['caller' => $caller, 'on' => $on]);

        if (!$this->guardEnter()) {
            $this->log(1, 'guard_timeout');
            return;
        }
        try {
            // Before/after variable verification
            $vid = $this->GetIDForIdent('OverrideActive');
            $old = GetValue($vid);
            $this->log(2, 'DEBUG_BEFORE_SET', ['old_value' => (bool)$old]);

            SetValue($vid, $on);
            $this->refreshOverrideIndicator();

            $new = GetValue($vid);
            $this->log(2, 'DEBUG_AFTER_SET', ['new_value' => (bool)$new]);

            // UI + standard log
            $this->ReloadForm();
            $this->log(2, 'override_set', ['on' => (bool)$on]);

            // Safety neutralization when enabling override
            if ($on) {
                $this->systemOff();
                $this->applyAllFlaps(false);
            }
        } finally {
            $this->guardLeave();
        }
    }

    /**
     * Orchestrator kommandiert Klappen explizit: ["Living Room"=>true, "Kitchen"=>false, ...]
     */
    public function CommandFlaps(string $stageName, string $flapConfigJson): void
    {

          // NEUER WÄCHTER:
        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $this->log(2, 'ignore_command_flaps', ['reason' => 'Standalone Mode is active', 'stage' => $stageName]);
            return; // Befehl ignorieren und Funktion sofort verlassen
        }
        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        try {
            $cfg = json_decode($flapConfigJson, true);
            if (!is_array($cfg)) {
                $this->log(0, 'invalid_flap_config', ['json' => $flapConfigJson]);
                return;
            }
            $rooms = $this->getRoomsByName();
            foreach ($cfg as $name => $open) {
                $nameStr = (string)$name;
                if (!isset($rooms[$nameStr])) {
                    $this->log(1, 'flap_room_not_found', ['room' => $nameStr]);
                    continue;
                }
                $this->setFlap($rooms[$nameStr], (bool)$open);
            }
            $this->WriteAttributeString('LastOrchestratorFlaps', $flapConfigJson);
            $this->log(2, 'orchestrator_flaps_applied', ['stage' => $stageName, 'cfg' => $cfg]);
        } finally {
            $this->guardLeave();
        }
    }

    /**
     * Orchestrator kommandiert Systemleistung (Power/Fan in %; 0..100).
     * Bei ungültigen Werten wird geklemmt.
     */
    public function CommandSystem(int $powerPercent, int $fanPercent): void
    {
            // NEUER WÄCHTER:
        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $this->log(2, 'ignore_command_system', ['reason' => 'Standalone Mode is active', 'power' => $powerPercent, 'fan' => $fanPercent]);
            return; // Befehl ignorieren und Funktion sofort verlassen
        }
        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        try {
            $p = $this->clamp($powerPercent, 0, 100);
            $f = $this->clamp($fanPercent, 0, 100);
            $this->systemSetPercent($p, $f);
            $this->log(2, 'orchestrator_system_applied', ['power' => $p, 'fan' => $f]);
        } finally {
            $this->guardLeave();
        }
    }

    // ---------- Public (Aggregates) ----------

    /**
     * Aggregates für Adaptive-Modul / Debug.
     * Rückgabe: JSON {"numActiveRooms":N,"maxDeltaT":x.xx,"anyWindowOpen":bool,"activeRooms":[...]}
     */
    public function GetAggregates(): string
    {
        $rooms = $this->getRooms();
        $numActive   = 0;
        $maxDeltaT   = 0.0;
        $anyWindow   = false;
        $activeRooms = [];

        $hyst = (float)$this->ReadPropertyFloat('Hysteresis');
        $hmap = $this->getHystState(); // latched hysteresis states { roomName => bool }

        // --- Kalibrierungsmodus erkennen ---
        $override = GetValue($this->GetIDForIdent('OverrideActive'));
        $rawFlapMap = json_decode($this->ReadAttributeString('LastOrchestratorFlaps') ?: '[]', true);
        $flapMap = [];
        if (is_array($rawFlapMap)) {
            foreach ($rawFlapMap as $k => $v) {
                $flapMap[mb_strtolower(trim((string)$k))] = $this->toBool($v);
            }
        }
        $calibMode = ($override && !empty($flapMap));

        $this->log(3, 'agg_start', [
            'override'  => $override,
            'calibMode' => $calibMode,
            'flapMap'   => $flapMap
        ]);

        foreach ($rooms as $r) {
            $name    = (string)($r['name'] ?? 'room');
            $nameKey = mb_strtolower(trim($name));

            $ist  = $this->GetFloat((int)($r['tempID'] ?? 0));
            $soll = $this->GetFloat((int)($r['targetID'] ?? 0));
            $win  = $this->isWindowOpenStable($r);
            $anyWindow = $anyWindow || $win;

            $effectiveDemand = false;

            if ($calibMode) {
                // --- Im Kalibrierungsmodus nur den Plan verwenden ---
                if (array_key_exists($nameKey, $flapMap)) {
                    $effectiveDemand = $flapMap[$nameKey];
                    $this->log(3, 'agg_calib_demand', ['room' => $name, 'demand' => $effectiveDemand]);
                } else {
                    $this->log(1, 'agg_calib_room_not_in_plan', ['room' => $name]);
                }
            } else {
                // --- Normale Logik ---
                $demVarID = (int)($r['demandID'] ?? $r['bedarfID'] ?? $r['bedarfsausgabeID'] ?? 0);
                if ($demVarID > 0 && IPS_VariableExists($demVarID)) {
                    $dem = (int)@GetValue($demVarID);
                    $effectiveDemand = ($dem === 2 || $dem === 3);
                    $this->log(3, 'agg_demand_var', ['room' => $name, 'dem' => $dem, 'effectiveDemand' => $effectiveDemand]);
                } elseif (array_key_exists($name, $hmap)) {
                    // Verwende den gelatchten Hysterese-Zustand, falls vorhanden
                    $effectiveDemand = (bool)$hmap[$name];
                    $this->log(3, 'agg_hyst_state_used', ['room' => $name, 'state' => $effectiveDemand]);
                } elseif (is_finite($ist) && is_finite($soll)) {
                    // Letzter Rückfall: einmalige Schwellenprüfung (ON-Schwelle)
                    $effectiveDemand = (($ist - $soll) >= $hyst);
                    $this->log(3, 'agg_delta_fallback', ['room' => $name, 'ist' => $ist, 'soll' => $soll, 'eff' => $effectiveDemand]);
                }
            }

            // Fenster-offen blockiert immer
            if ($win && $effectiveDemand) {
                $effectiveDemand = false;
                $this->log(2, 'agg_window_override', ['room' => $name]);
            }

            if ($effectiveDemand) {
                $numActive++;
                $activeRooms[] = $name;
                if (is_finite($ist) && is_finite($soll)) {
                    $delta = abs($ist - $soll);
                    $maxDeltaT = max($maxDeltaT, $delta);
                }
            }
        }

        $agg = [
            'numActiveRooms' => $numActive,
            'maxDeltaT'      => round($maxDeltaT, 2),
            'anyWindowOpen'  => $anyWindow,
            'activeRooms'    => $activeRooms
        ];
        $this->WriteAttributeString('LastAggregates', json_encode($agg));
        $this->log(3, 'agg_result', $agg);

        return json_encode($agg);
    }

    public function HandleCoilBelowThreshold(float $coilTemp, float $threshold): void
    {
        $this->WriteAttributeBoolean('EmergencyShutdownActive', true);
        $this->log(0, 'EMERGENCY_BY_EVENT', ['coilTemp'=>$coilTemp, 'threshold'=>$threshold]);

        $fanPct = $this->readEmergencyFanPercent();

        if ($this->guardEnter()) {
            try {
                $this->log(2, 'emergency_fan_setting', ['fanPct'=>$fanPct]);
                $this->systemSetPercent(0, $fanPct); // AC off, fan at % 
                if ($this->ReadPropertyBoolean('EmergencyCloseFlaps')) {
                    $this->applyAllFlaps(false);
                }
            } finally {
                $this->guardLeave();
            }
        } else {
            $this->log(2, 'emergency_fan_setting', ['fanPct'=>$fanPct]);
            $this->systemSetPercent(0, $fanPct);
            if ($this->ReadPropertyBoolean('EmergencyCloseFlaps')) {
                $this->applyAllFlaps(false);
            }
        }
    }




    public function GetRoomConfigurations(): string
    {
        return $this->ReadPropertyString('ControlledRooms');
    }

    // ---------- Intern: Flaps/System ----------

    private function applyAllFlaps(bool $open): void
    {
        $rooms = $this->getRooms();
        foreach ($rooms as $r) {
            $this->setFlap($r, $open);
        }
    }
    private function readStandalonePercent(string $propName, int $fallbackConst): int
    {
        $vid = (int)$this->ReadPropertyInteger($propName);
        if ($vid > 0 && IPS_VariableExists($vid)) {
            $val = @GetValue($vid);
            if (is_numeric($val)) {
                $pct = $this->clamp((int)$val, 0, 100);
                // nur bei Bedarf sichtbar machen: woher kam der Wert?
                $this->log(3, 'standalone_source', ['prop'=>$propName,'src'=>'var','varID'=>$vid,'value'=>$pct]);
                return $pct;
            }
        }
        $pct = $this->clamp($fallbackConst, 0, 100);
        $this->log(3, 'standalone_source', ['prop'=>$propName,'src'=>'const','value'=>$pct]);
        return $pct;
    }
    private function ensureCoilTempEvent(): void
    {
        $coilVarID = (int)$this->ReadPropertyInteger('CoilTemperatureLink');
        $eid       = (int)$this->ReadAttributeInteger('CoilTempEventID');

        // If no coil sensor configured, disable existing event (if any)
        if ($coilVarID <= 0 || !IPS_VariableExists($coilVarID)) {
            if ($eid > 0 && IPS_EventExists($eid)) {
                IPS_SetEventActive($eid, false);
            }
            return;
        }

        // Create or fix event: must be a *triggered* event (type 0)
        if ($eid <= 0 || !IPS_EventExists($eid)) {
            $eid = IPS_CreateEvent(0); // 0 = Triggered Event
            IPS_SetParent($eid, $this->InstanceID);
            IPS_SetName($eid, 'ZDM: CoilTemp ≤ Abschalttemperatur');
            $this->WriteAttributeInteger('CoilTempEventID', $eid);
        } else {
            $info  = IPS_GetEvent($eid);
            $etype = isset($info['EventType']) ? (int)$info['EventType'] : -1;
            if ($etype !== 0) {
                // wrong type -> recreate as triggered
                IPS_DeleteEvent($eid);
                $eid = IPS_CreateEvent(0); // 0 = Triggered Event
                IPS_SetParent($eid, $this->InstanceID);
                IPS_SetName($eid, 'ZDM: CoilTemp ≤ Abschalttemperatur');
                $this->WriteAttributeInteger('CoilTempEventID', $eid);
            }
        }

        // Bind to coil variable: trigger on each update
        IPS_SetEventTrigger($eid, 0 /* OnUpdate */, $coilVarID);

        // Event script: compare against EmergencyShutdownTemp and call module handler
        $script = '/* Auto-generated by ZDM */
    $inst = ' . $this->InstanceID . ';
    $var  = ' . $coilVarID . ';
    $val  = @GetValue($var);
    $thr  = (float)IPS_GetProperty($inst, "EmergencyShutdownTemp");
    if (is_numeric($val) && ((float)$val) <= $thr) {
        @ZDM_HandleCoilBelowThreshold($inst, (float)$val, $thr);
    }';
        IPS_SetEventScript($eid, $script);
        IPS_SetEventActive($eid, true);

        // (Optional) keep tree tidy
        // IPS_SetHidden($eid, true);
    }

    private function readEmergencyFanPercent(): int
    {
        // Falls Property fehlt oder 0 zurückkommt → sinnvoller Default 60
        $v = (int)@$this->ReadPropertyInteger('EmergencyFanPercent');
        if ($v <= 0 || $v > 100) $v = 60;
        return $this->clamp($v, 0, 100);
    }

    /**
     * Setzt eine Klappe je nach Typ (boolean/linear).
     * Erwartete Felder je Raum:
     *  - flapID (int)
     *  - flapType ('boolean'|'linear')
     *  - flapOpenValue/flapClosedValue (bei boolean)
     *  - flapOpenLinear/flapClosedLinear (bei linear, 0..100)
     */
    private function setFlap(array $room, bool $open): void
    {
        $varID = (int)($room['flapID'] ?? 0);
        if ($varID <= 0) {
            $this->log(1, 'flap_var_missing', ['room' => $room['name'] ?? 'room']);
            return;
        }

        $type = strtolower((string)($room['flapType'] ?? 'boolean'));

        if ($type === 'linear') {
            // linear: always send 0..100 integer
            $pctOpen  = $this->toPercent($room['flapOpenLinear']  ?? 100);
            $pctClose = $this->toPercent($room['flapClosedLinear'] ?? 0);
            $val = $open ? $pctOpen : $pctClose; // int
            $this->writeVarSmart($varID, $val);
            $this->log(3, 'flap_set_linear', ['room'=>$room['name'] ?? 'room', 'value'=>$val]);
        } else {
            // boolean: always send true/false (never strings)
            $valOpen  = $this->toBool($room['flapOpenValue']   ?? true);
            $valClose = $this->toBool($room['flapClosedValue'] ?? false);
            $val = $open ? $valOpen : $valClose; // bool
            $this->writeVarSmart($varID, $val);
            $this->log(3, 'flap_set_boolean', ['room'=>$room['name'] ?? 'room', 'value'=>$val]);
        }
    }

    private function GetInt(int $varID): int
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return PHP_INT_MIN;
        $v = @GetValue($varID);
        return is_numeric($v) ? (int)$v : PHP_INT_MIN;
    }

    private function toBool($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return ((int)$v) >= 1;
        $s = mb_strtolower(trim((string)$v));
        return in_array($s, ['1','true','on','open','auf','yes','ja'], true);
    }

    private function toPercent($v): int
    {
        $n = (int)$v;
        return $this->clamp($n, 0, 100);
    }


    private function systemOnStandalone(): void
    {
        // Werte aus Variablen, sonst auf Konstanten zurückfallen
        $p = $this->readStandalonePercent('StandalonePowerVar',    (int)$this->ReadPropertyInteger('ConstantPower'));
        $f = $this->readStandalonePercent('StandaloneFanVar',      (int)$this->ReadPropertyInteger('ConstantFanSpeed'));

        $this->systemSetPercent($p, $f);
        $this->log(2, 'system_on_standalone', ['power'=>$p, 'fan'=>$f]);
    }


    private function systemOff(): void
    {
        $this->systemSetPercent(0, 0);
        $this->log(2, 'system_off');
    }

    private function systemSetPercent(int $power, int $fan): void
    {
        // Werte auf 0-100 begrenzen (bleibt gleich)
        $power = $this->clamp($power, 0, 100);
        $fan   = $this->clamp($fan,   0, 100);

        // Lese ALLE vier relevanten Variablen-IDs aus der Konfiguration
        $acSwitchVar  = (int)$this->ReadPropertyInteger('MainACOnOffLink');
        $fanSwitchVar = (int)$this->ReadPropertyInteger('MainFanControlLink');
        $acPowerVar   = (int)$this->ReadPropertyInteger('MainACPowerLink');
        $fanSpeedVar  = (int)$this->ReadPropertyInteger('MainFanSpeedLink');

        // Bestimme den Ein/Aus-Zustand. AN, wenn Leistung > 0.
        $isAcOn  = ($power > 0);
        $isFanOn = ($fan > 0);

        // Logge die vollständige Absicht
        $this->log(3, 'system_set_percent_extended', [
            'power_val' => $power,
            'fan_val'   => $fan,
            'acSwitchID'  => $acSwitchVar,
            'fanSwitchID' => $fanSwitchVar,
            'acPowerID'   => $acPowerVar,
            'fanSpeedID'  => $fanSpeedVar,
            'computed_isAcOn'  => $isAcOn,
            'computed_isFanOn' => $isFanOn
        ]);

        // Sende die Befehle
        if ($acSwitchVar > 0)  $this->writeVarSmart($acSwitchVar,  $isAcOn);  // Sendet true/false
        if ($acPowerVar > 0)   $this->writeVarSmart($acPowerVar,   $power);   // Sendet 0-100
        if ($fanSwitchVar > 0) $this->writeVarSmart($fanSwitchVar, $isFanOn); // Sendet true/false
        if ($fanSpeedVar > 0)  $this->writeVarSmart($fanSpeedVar,  $fan);     // Sendet 0-100
    }

    private function writeVarSmart(int $varID, $value): void
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) {
            return;
        }

        $vinfo = IPS_GetVariable($varID);
        $varType = $vinfo['VariableType'] ?? -1;

        // Wir konvertieren den Wert in den Zieldatentyp, bevor wir ihn senden.
        $finalValue = $value;
        switch ($varType) {
            case 0: // Boolean
                if (is_numeric($value)) {
                    $finalValue = ((int)$value) != 0;
                } elseif (is_string($value)) {
                    $finalValue = in_array(strtolower((string)$value), ['true', '1', 'on'], true);
                }
                $finalValue = (bool)$finalValue;
                break;
            case 1: // Integer
                $finalValue = (int)$value;
                break;
            case 2: // Float
                $finalValue = (float)$value;
                break;
            case 3: // String
                $finalValue = (string)$value;
                break;
        }

        // Logge die Absicht, bevor der Befehl gesendet wird.
        $this->log(3, 'writeVarSmart_execute_requestaction', [
            'varID'          => $varID,
            'targetVarType'  => $varType,
            'originalValue'  => $value,
            'finalValueSent' => $finalValue
        ]);

        // Führe IMMER RequestAction aus.
        // Ein @-Zeichen ist hier sinnvoll, um den Lauf nicht zu stoppen,
        // falls eine Variable temporär keine Aktion hat. Der Fehler wird im Log sichtbar.
        RequestAction($varID, $finalValue);
    }

    // ---------- Intern: Fenster/Door (Debounce) ----------

    // ---> FIXED: tolerant & safe against early startup and bad JSON
    private function isWindowOpenStable(array $room): bool
    {
        $debounce = max(0, (int)$this->ReadPropertyInteger('WindowDebounceSec'));
        $name = (string)($room['name'] ?? 'room');

        $map = $this->getWindowStableMap();   // robust: [] on error
        $raw = $this->isWindowOpenRaw($room);
        $now = time();

        // current stable state for this room (default to current raw)
        $st = $map[$name] ?? ['open' => (bool)$raw, 'ts' => $now];

        if ((bool)$raw !== (bool)$st['open']) {
            // state changed → commit only after debounce time
            if (($now - (int)$st['ts']) >= $debounce) {
                $st = ['open' => (bool)$raw, 'ts' => $now];
                $map[$name] = $st;
                $this->setWindowStableMap($map);
                $this->log(2, 'window_state_committed', ['room'=>$name, 'open'=>$st['open']]);
            }
            // else: keep old stable state until debounce expires
        } else {
            // same state → refresh timestamp occasionally
            if (($now - (int)$st['ts']) > 3600) {
                $st['ts'] = $now;
                $map[$name] = $st;
                $this->setWindowStableMap($map);
            }
        }

        return (bool)$st['open'];
    }

    /**
     * RAW-Check: Prüft Kategorie auf "irgendein Fenster offen".
     * Heuristik:
     *  - Boolean-Variablen: TRUE => offen
     *  - Integer/Float: >0 => offen
     *  - Strings: "open"/"auf" => offen
     */
    private function isWindowOpenRaw(array $room): bool
    {
        $catID = (int)($room['windowCatID'] ?? 0);
        $name  = (string)($room['name'] ?? 'room');

        if ($catID <= 0 || !IPS_CategoryExists($catID)) {
            // Nur bei echter Fehlkonfiguration loggen
            $this->log(1, 'win_raw_no_category', ['room'=>$name, 'catID'=>$catID]);
            return false;
        }

        foreach ($this->flattenCategoryVars($catID) as $vid) {
            if (!IPS_VariableExists($vid)) {
                // Zielvariable existiert nicht (z. B. gelöschtes Target einer Link-Referenz)
                $this->log(1, 'win_var_disappeared', ['room'=>$name, 'varID'=>$vid]);
                continue;
            }

            $vinfo = IPS_GetVariable($vid);
            $v     = @GetValue($vid);
            $vt    = (int)($vinfo['VariableType'] ?? -1);

            // Profil zuerst (offen/geschlossen, auf/zu, etc.)
            $byProfile = $this->isOpenByProfile($vid, $v, $vinfo);
            if ($byProfile !== null) {
                if ($byProfile === true) return true;
                continue;
            }

            // Fallback-Heuristik
            $open =
                ($vt === 0) ? ((bool)$v === true) :
                (($vt === 1 || $vt === 2) ? ((float)$v > 0.0) :
                $this->strContainsAny(mb_strtolower(trim((string)$v)), ['open','auf','offen','geöffnet','true','1']));

            if ($open) return true;
        }

        return false;
    }

    private function getHystState(): array
    {
        $raw = @$this->ReadAttributeString('RoomHystState');
        if (!is_string($raw) || $raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->log(1, 'hyststate_json_invalid', ['raw'=>$raw]);
            return [];
        }
        return $data;
    }

    private function setHystState(array $m): void
    {
        $json = json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->log(1, 'hyststate_json_encode_failed');
            return;
        }
        $this->WriteAttributeString('RoomHystState', $json);
    }

    /**
     * Stateful cooling hysteresis:
     *  - If previously OFF:  turn ON when (ist - soll) >= hyst
     *  - If previously ON:   stay ON while (ist - soll) > 0, turn OFF when <= 0
     */
    private function decideHysteresisCooling(string $roomName, float $ist, float $soll, float $hyst, bool $prevOn): bool
    {
        if (!is_finite($ist) || !is_finite($soll)) {
            $this->log(1, 'hyst_invalid_values', ['room'=>$roomName,'ist'=>$ist,'soll'=>$soll]);
            return false; // fail-safe OFF
        }
        $delta = $ist - $soll;

        $newOn = $prevOn
            ? ($delta > 0.0)          // OFF when cooled to/below setpoint
            : ($delta >= $hyst);      // ON when exceeding ON threshold

        $this->log(3, 'hyst_eval', [
            'room'=>$roomName, 'prev'=>$prevOn, 'ist'=>$ist, 'soll'=>$soll,
            'delta'=>$delta, 'on_thr'=>$hyst, 'off_thr'=>0.0, 'new'=>$newOn
        ]);
        return $newOn;
    }

    /**
     * Collect variable IDs contained in a category, following links and (optionally) subcategories.
     * - Supports: variables directly under the category
     * - Supports: links to variables (resolves TargetID)
     * - Supports: links to categories and nested categories (recurses)
     */
    private function flattenCategoryVars(int $catID, array &$visited = []): array
    {
        if (!IPS_CategoryExists($catID)) return [];
        if (isset($visited[$catID])) return [];
        $visited[$catID] = true;

        $out = [];
        foreach (IPS_GetChildrenIDs($catID) as $cid) {
            if (IPS_VariableExists($cid)) {
                $out[] = $cid;
                continue;
            }
            if (IPS_LinkExists($cid)) {
                $tID = (int) (@IPS_GetLink($cid)['TargetID'] ?? 0);
                if ($tID > 0 && IPS_VariableExists($tID)) {
                    $out[] = $tID;
                    continue;
                }
                if ($tID > 0 && IPS_CategoryExists($tID)) {
                    $out = array_merge($out, $this->flattenCategoryVars($tID, $visited));
                    continue;
                }
                // Nur warnen, wenn Link auf nichts/kein Objekt zeigt
                $this->log(1, 'win_cat_link_target_missing', ['parentCat'=>$catID, 'childID'=>$cid, 'target'=>$tID]);
                continue;
            }
            if (IPS_CategoryExists($cid)) {
                $out = array_merge($out, $this->flattenCategoryVars($cid, $visited));
            }
        }
        return array_values(array_unique($out));
    }

    // ---------- Public: Diagnostics (SSOT) ----------

    /** Map of room name -> resolved window sensor variable IDs (links + nested cats already resolved). */
    public function GetWindowVarIDs(): string
    {
        $out = [];
        foreach ($this->getRooms() as $r) {
            $name = (string)($r['name'] ?? 'room');
            $cat  = (int)($r['windowCatID'] ?? 0);
            $out[$name] = ($cat > 0) ? $this->flattenCategoryVars($cat) : [];
        }
        return json_encode($out);
    }

    public function DebugWindowCategory(string $roomName): void
    {
        $rooms = $this->getRoomsByName();
        if (!isset($rooms[$roomName])) {
            $this->log(1, 'debug_win_room_not_found', ['room'=>$roomName]);
            return;
        }
        $r = $rooms[$roomName];
        $catID = (int)($r['windowCatID'] ?? 0);
        $this->log(2, 'debug_win_start', ['room'=>$roomName, 'catID'=>$catID]);

        $vars = $this->flattenCategoryVars($catID);
        foreach ($vars as $vid) {
            if (!IPS_VariableExists($vid)) continue;
            $v  = @GetValue($vid);
            $vt = IPS_GetVariable($vid)['VariableType'] ?? -1;
            $this->log(2, 'debug_win_var', ['room'=>$roomName, 'varID'=>$vid, 'type'=>$vt, 'value'=>$v]);
        }
        $this->log(2, 'debug_win_done', ['room'=>$roomName, 'count'=>count($vars)]);
    }
    private function isOpenByProfile(int $varID, $value, array $vinfo): ?bool
    {
        // Profilname bestimmen (Custom hat Vorrang)
        $profile = $vinfo['VariableCustomProfile'] ?: ($vinfo['VariableProfile'] ?? '');
        if ($profile === '' || !IPS_VariableProfileExists($profile)) {
            return null; // kein Profil → keine Aussage
        }

        $pi = IPS_GetVariableProfile($profile);
        $assocs = $pi['Associations'] ?? [];
        if (!is_array($assocs) || empty($assocs)) {
            return null;
        }

        // Aktuellen Wert als Zahl (wenn möglich)
        $valNum = is_numeric($value) ? (float)$value : null;

        // Direktzuordnung: Wert == Association.Value → Label auswerten
        foreach ($assocs as $a) {
            $label = mb_strtolower((string)($a['Name'] ?? ''));
            $aval  = (float)($a['Value'] ?? NAN);

           if ($valNum !== null && $valNum === $aval) {
                if ($this->strContainsAny($label, ['open','auf','offen','geöffnet'])) return true;
                if ($this->strContainsAny($label, ['closed','zu','geschlossen']))     return false;
                // Label enthält weder offen noch geschlossen → keine Aussage
                return null;
            }
        }

        return null; // keine eindeutige Profil-Aussage
    }
    private function refreshOverrideIndicator(): void
    {
        $vid  = @$this->GetIDForIdent('OverrideActive');
        $isOn = ($vid && @GetValue($vid));
        try {
            // korrektes Form-Feld + Property
            $this->UpdateFormField('OverrideActive', 'caption', $isOn ? 'Ja' : 'Nein');
        } catch (\Throwable $e) {
            /* Form evtl. nicht offen – ignorieren */
        }
    }

    private function strContainsAny(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);
        foreach ($needles as $n) {
            if ($n !== '' && mb_strpos($haystack, $n) !== false) return true;
        }
        return false;
    }

    // ---> FIXED: safe attribute read + json decode
    private function getWindowStableMap(): array
    {
        $raw = @$this->ReadAttributeString('WindowStable');

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->log(1, 'windowstable_json_invalid', ['raw' => $raw]);
            return [];
        }
        return $data;
    }

    private function setWindowStableMap(array $m): void
    {
        $json = json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->log(1, 'windowstable_json_encode_failed');
            return;
        }
        $this->WriteAttributeString('WindowStable', $json);
    }

    // ---------- Intern: Hilfen ----------

    private function isBlockedByHeatingOrVentilation(): bool
    {
        $heat = (int)$this->ReadPropertyInteger('HeatingActiveLink');
        $vent = (int)$this->ReadPropertyInteger('VentilationActiveLink');

        $h = ($heat > 0) ? $this->varTruthy($heat) : false;
        $v = ($vent > 0) ? $this->varTruthy($vent) : false;

        return ($h || $v);
    }

    private function varTruthy(int $varID): bool
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return false;
        $v = @GetValue($varID);
        if (is_bool($v))   return $v;
        if (is_numeric($v))return ((float)$v) > 0;
        if (is_string($v)) return in_array(mb_strtolower(trim($v)), ['1','true','on','open','auf'], true);
        return false;
    }

    private function getRooms(): array
    {
        $json = $this->ReadPropertyString('ControlledRooms') ?: '[]';
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    private function getRoomsByName(): array
    {
        $out = [];
        foreach ($this->getRooms() as $r) {
            $n = (string)($r['name'] ?? '');
            if ($n !== '') $out[$n] = $r;
        }
        return $out;
    }

    private function GetFloat(int $varID): float
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return NAN;
        $v = @GetValue($varID);
        return is_numeric($v) ? (float)$v : NAN;
    }

    private function clamp(int $val, int $min, int $max): int
    {
        if ($val < $min) return $min;
        if ($val > $max) return $max;
        return $val;
    }

    // ---------- Semaphore & Logging ----------

    private function guardEnter(): bool
    {
        $key = 'ZDM_' . $this->InstanceID;
        return IPS_SemaphoreEnter($key, 1000);
    }

    private function guardLeave(): void
    {
        IPS_SemaphoreLeave('ZDM_' . $this->InstanceID);
    }

    /**
     * Einheitliches Logging in die Symcon-Meldungen
     * $lvl: 0=ERROR, 1=WARN, 2=INFO, 3=DEBUG
     */
    private function log(int $lvl, string $event, array $data = []): void
    {
        $cfg = (int)$this->ReadPropertyInteger('LogLevel');
        if ($lvl > $cfg) return;

        $line = json_encode(
            ['t'=>time(),'lvl'=>$lvl,'ev'=>$event,'data'=>$data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $prio = KL_MESSAGE;
        if ($lvl === 0) $prio = KL_ERROR;
        elseif ($lvl === 1) $prio = KL_WARNING;

        $this->LogMessage("ZDM ".$line, $prio);

        // Optional zusätzlich Debug-Konsole:
        // $this->SendDebug('ZDM', $line, 0);
    }
}
