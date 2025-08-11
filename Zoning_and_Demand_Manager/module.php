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

        // Systemweite Verknüpfungen (IDs von Variablen/Aktoren)
        $this->RegisterPropertyInteger('HeatingActiveLink', 0);
        $this->RegisterPropertyInteger('VentilationActiveLink', 0);
        $this->RegisterPropertyInteger('MainACOnOffLink', 0);     // bool oder integer (0..100)
        $this->RegisterPropertyInteger('MainFanControlLink', 0);  // bool oder integer (0..100)
        $this->RegisterPropertyInteger('MainACPowerLink', 0);
        $this->RegisterPropertyInteger('MainFanSpeedLink', 0);
        // Standalone-Konstanten (optional)
        $this->RegisterPropertyInteger('ConstantPower', 80);      // 0..100
        $this->RegisterPropertyInteger('ConstantFanSpeed', 80);   // 0..100

        // Raumliste (JSON aus der Form)
        $this->RegisterPropertyString('ControlledRooms', '[]');

        // ---- Neue Properties ----
        $this->RegisterPropertyInteger('WindowDebounceSec', 10); // 0..120 Sekunden
        $this->RegisterPropertyInteger('LogLevel', 3);           // 0=ERROR,1=WARN,2=INFO,3=DEBUG

        // ---- Attribute ----
        $this->RegisterAttributeString('WindowStable', '{}');    // {roomName:{open:bool, ts:int}}
        $this->RegisterAttributeString('LastAggregates', '{}');  // Cache/Debug
        $this->RegisterAttributeString('LastOrchestratorFlaps', '[]');


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

        // HIER IST DIE KORREKTUR: `$this->guardEnter()` statt `$this.guardEnter()`
        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        try {
            $override = GetValue($this->GetIDForIdent('OverrideActive'));
            if ($override) {
                $this->log(2, 'override_active_mode_process');
                $this->GetAggregates();
                return;
            }

            if ($this->isBlockedByHeatingOrVentilation()) {
                $this->log(2, 'blocked_by_heating_or_ventilation');
                $this->systemOff();
                $this->applyAllFlaps(false);
                return;
            }

            $rooms = $this->getRooms();
            if (!$rooms) {
                $this->log(2, 'no_rooms_configured');
                return;
            }

            $anyDemand = false;

            foreach ($rooms as $room) {
                $name = (string)($room['name'] ?? 'room');
                $coolingPhaseVarID = (int)($room['coolingPhaseInfoID'] ?? 0);
                $airSollStatusVarID = (int)($room['airSollStatusID'] ?? 0);
                $demandVarID = (int)($room['demandID'] ?? 0);

                // 1) Fenster-Check (hat immer Vorrang)
                if ($this->isWindowOpenStable($room)) {
                    $this->setFlap($room, false);
                    if ($coolingPhaseVarID > 0) $this->writeVarSmart($coolingPhaseVarID, 3); // On Hold Window Open
                    if ($demandVarID > 0) $this->writeVarSmart($demandVarID, 0);
                    $this->log(3, 'room_window_open_flap_closed', ['room'=>$name]);
                    continue;
                }

                // 2) PRIMÄRE LOGIK: Prüfe den vom Benutzer gesetzten "Air Soll Status"
                $roomMode = ($airSollStatusVarID > 0) ? $this->GetInt($airSollStatusVarID) : 3; // Fallback auf AUTO
                $roomHasDemand = false;

                if ($roomMode === 2) { // AC ON (Einmalige Anforderung)
                    $ist = $this->GetFloat((int)($room['tempID'] ?? 0));
                    $soll = $this->GetFloat((int)($room['targetID'] ?? 0));
                    if (is_finite($ist) && is_finite($soll) && $ist > $soll) {
                        $roomHasDemand = true;
                    } else {
                        if ($airSollStatusVarID > 0) $this->writeVarSmart($airSollStatusVarID, 1); // AC OFF
                    }
                } elseif ($roomMode === 3) { // AC AUTO (Automatische Regelung)
                    $ist = $this->GetFloat((int)($room['tempID'] ?? 0));
                    $soll = $this->GetFloat((int)($room['targetID'] ?? 0));
                    $hyst = (float)$this->ReadPropertyFloat('Hysteresis');
                    if (is_finite($ist) && is_finite($soll) && ($ist - $soll) > $hyst) {
                        $roomHasDemand = true;
                    }
                }

                // 3) FINALE ENTSCHEIDUNG für diesen Raum
                if ($roomHasDemand) {
                    $this->setFlap($room, true);
                    $anyDemand = true;
                    if ($demandVarID > 0) $this->writeVarSmart($demandVarID, 3);
                    if ($coolingPhaseVarID > 0) $this->writeVarSmart($coolingPhaseVarID, 2); // Automatic Cooling ACTIVE
                    $this->log(2, 'room_demand_on', ['room' => $name, 'mode' => $roomMode]);
                } else {
                    $this->setFlap($room, false);
                    if ($demandVarID > 0) $this->writeVarSmart($demandVarID, 0);
                    if ($coolingPhaseVarID > 0) $this->writeVarSmart($coolingPhaseVarID, 0); // Ready for Cooling
                    $this->log(2, 'room_demand_off', ['room' => $name, 'mode' => $roomMode]);
                }
            }

            // --- Der Rest der Funktion bleibt unverändert ---
            $this->log(2, 'DECIDE_SYSTEM', ['anyDemand' => $anyDemand]);
            if ($anyDemand) {
                if ($this->ReadPropertyBoolean('StandaloneMode')) {
                    $this->systemOnStandalone();
                } else {
                    $this->systemSetPercent(1, 1);
                }
            } else {
                $this->systemOff();
            }

            $this->GetAggregates();

        } finally {
            $this->guardLeave();
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
            $name = (string)($r['name'] ?? 'room');
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
                } elseif (is_finite($ist) && is_finite($soll)) {
                    $effectiveDemand = (($ist - $soll) > $hyst);
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
        $p = $this->clamp((int)$this->ReadPropertyInteger('ConstantPower'), 0, 100);
        $f = $this->clamp((int)$this->ReadPropertyInteger('ConstantFanSpeed'), 0, 100);
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
        
        // PRÄZISERE PRÜFUNG: Wir verwenden RequestAction NUR, wenn ein EIGENES Aktionsskript existiert.
        $hasCustomAction = ($vinfo['VariableCustomAction'] ?? 0) !== 0;

        $this->log(3, 'writeVarSmart_debug', [
            'varID'           => $varID,
            'varType'         => $varType,
            'hasCustomAction' => $hasCustomAction, // Logge das Ergebnis der neuen Prüfung
            'value'           => $value
        ]);

        // Wenn ein eigenes Skript da ist, soll es die Arbeit machen.
        if ($hasCustomAction) {
            $this->log(3, 'writeVarSmart_execute', ['method' => 'RequestAction', 'varID' => $varID, 'value' => $value]);
            RequestAction($varID, $value);
            return;
        }

        // In ALLEN ANDEREN Fällen setzen wir den Wert direkt und typsicher.
        // Das deckt Statusvariablen UND Variablen mit reinen Anzeige-Profilen ab.
        $this->log(3, 'writeVarSmart_execute', ['method' => 'SetValue (type-specific)', 'varID' => $varID, 'value' => $value]);

        switch ($varType) {
            case 0: // Boolean
                $finalValue = $value;
                if (is_numeric($value)) {
                    $finalValue = ((int)$value) >= 1;
                } elseif (is_string($value)) {
                    $finalValue = in_array(strtolower((string)$value), ['true', '1', 'on'], true);
                }
                // Verhindere unnötiges Schreiben, wenn der Wert bereits stimmt
                if (GetValueBoolean($varID) !== (bool)$finalValue) {
                    SetValueBoolean($varID, (bool)$finalValue);
                }
                break;

            case 1: // Integer
                // Verhindere unnötiges Schreiben, wenn der Wert bereits stimmt
                if (GetValueInteger($varID) !== (int)$value) {
                    $this->log(1, 'Error cant write var', ['varID' => $varID, 'varType' => $varType]);
                    SetValueInteger($varID, (int)$value);
                }
                break;

            case 2: // Float
                // Verhindere unnötiges Schreiben, wenn der Wert bereits stimmt
                if (GetValueFloat($varID) !== (float)$value) {
                    SetValueFloat($varID, (float)$value);
                }
                break;

            case 3: // String
                // Verhindere unnötiges Schreiben, wenn der Wert bereits stimmt
                if (GetValueString($varID) !== (string)$value) {
                    SetValueString($varID, (string)$value);
                }
                break;

            default:
                $this->log(1, 'writeVarSmart_unknown_type', ['varID' => $varID, 'varType' => $varType]);
                SetValue($varID, $value);
                break;
        }
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
        if ($catID <= 0 || !IPS_CategoryExists($catID)) return false;

        $children = IPS_GetChildrenIDs($catID);
        foreach ($children as $cid) {
            if (!IPS_VariableExists($cid)) continue;
            $v = @GetValue($cid);
            $vt = IPS_GetVariable($cid)['VariableType'] ?? -1;

            if ($vt === 0) { // BOOL
                if ((bool)$v === true) return true;
            } elseif ($vt === 1 || $vt === 2) { // INT/FLOAT
                if ((float)$v > 0.0) return true;
            } elseif ($vt === 3) { // STRING
                $s = mb_strtolower(trim((string)$v));
                if ($s === 'open' || $s === 'auf' || $s === 'true' || $s === '1') return true;
            }
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
