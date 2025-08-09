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
        // ---> FIX: extra runtime guard
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->log(1, 'kernel_not_ready_skip');
            return;
        }

        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        try {
            // Override aktiv? Dann keine Autologik
            $override = GetValue($this->GetIDForIdent('OverrideActive'));
            $this->log(2, 'DEBUG_OVERRIDE_CHECK', ['override_value' => (bool)$override]);
            if ($override) {
                // In Override: keine Auto-Steuerung, aber Aggregates berechnen,
                // damit ADHVAC gültigen Bedarf sieht (Klappen⇒aktiv).
                $this->log(2, 'override_active_mode_process');
                $this->GetAggregates();
                return;
            }

            // Heizung/Lüftung aktiv? (blockiert Kühlung)
            if ($this->isBlockedByHeatingOrVentilation()) {
                $this->log(2, 'blocked_by_heating_or_ventilation');
                $this->systemOff();
                // Klappen optional schließen:
                $this->applyAllFlaps(false);
                return;
            }

            $rooms = $this->getRooms();
            if (!$rooms) {
                $this->log(2, 'no_rooms_configured');
                return;
            }

            // Raumweise entscheiden & Klappen setzen
            $anyDemand = false;

            foreach ($rooms as $room) {
                $name = (string)($room['name'] ?? 'room');

                // 1) Fenster-Check (hat immer Vorrang)
                $winOpen = $this->isWindowOpenStable($room);
                if ($winOpen) {
                    $this->setFlap($room, false);
                    $this->log(3, 'room_window_open_flap_closed', ['room'=>$name]);
                    continue;
                }

                // 2) Bedarfsausgabe (Integer-Link in der Raumkonfiguration)
                //    unterstützte Keys: demandID | bedarfID | bedarfsausgabeID
                // 2) Bedarfsausgabe (Integer-Link in der Raumkonfiguration)
                // 2) Bedarfsausgabe (Integer-Link in der Raumkonfiguration)
                $demVarID = (int)($room['demandID'] ?? $room['bedarfID'] ?? $room['bedarfsausgabeID'] ?? 0);

                if ($demVarID > 0 && IPS_VariableExists($demVarID)) {
                    $dem = (int)@GetValue($demVarID);

                    if ($dem === 2 || $dem === 3) {
                        // Bedarf -> Klappe AUF
                        $this->setFlap($room, true);
                        $anyDemand = true;
                        $this->log(2, 'room_demand_flag_on_flap_open', ['room'=>$name, 'dem'=>$dem]);
                    } else {
                        // dem=0 oder 1 -> kein Bedarf -> Klappe ZU
                        $this->setFlap($room, false);
                        $this->log(2, 'room_demand_flag_off_flap_closed', ['room'=>$name, 'dem'=>$dem]);
                    }
                    continue; // kein ΔT-Fallback wenn Bedarfs-Var vorhanden
                } else {
                    // keine Bedarf-Variable konfiguriert -> ΔT/Hysterese-Fallback
                    $this->log(3, 'room_no_demand_var_fallback_delta', ['room'=>$name]);
                }


                // 3) ΔT/Hysterese-Fallback (nur wenn Bedarfsausgabe nicht 0/1/2 war)
                $ist  = $this->GetFloat((int)($room['tempID'] ?? 0));
                $soll = $this->GetFloat((int)($room['targetID'] ?? 0));

                if (!is_finite($ist) || !is_finite($soll)) {
                    $this->log(1, 'invalid_temp_values', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll]);
                    $this->setFlap($room, false);
                    continue;
                }

                $hyst  = (float)$this->ReadPropertyFloat('Hysteresis');
                $delta = $ist - $soll; // >0 = Ist über Soll => Kühlbedarf

                if ($delta > $hyst) {
                    $this->setFlap($room, true);
                    $anyDemand = true;
                    $this->log(3, 'room_cooling_needed_flap_open', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll, 'delta'=>$delta]);
                } elseif ($delta < -$hyst) {
                    $this->setFlap($room, false);
                    $this->log(3, 'room_below_setpoint_flap_closed', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll, 'delta'=>$delta]);
                } else {
                    $this->log(3, 'room_in_deadband_keep', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll, 'delta'=>$delta]);
                }
            }

            // --- Instrumentation: System-Entscheidung loggen
            $this->log(2, 'DECIDE_SYSTEM', [
                'anyDemand'      => $anyDemand,
                'standaloneMode' => (bool)$this->ReadPropertyBoolean('StandaloneMode')
            ]);

            // System schalten (Standalone oder Adaptive)
            if ($anyDemand) {
                if ($this->ReadPropertyBoolean('StandaloneMode')) {
                    $this->log(2, 'system_branch', ['mode' => 'standalone_on']);
                    $this->systemOnStandalone();
                } else {
                    $this->log(2, 'system_branch', ['mode' => 'adaptive_on']);
                    $this->systemSetPercent(1, 1); // bool-Relais -> TRUE
                    $this->log(2, 'system_on_adaptive_toggle');
                }
            } else {
                $this->log(2, 'system_branch', ['mode' => 'off']);
                $this->systemOff();
            }

            // Aggregates berechnen (weiterhin ΔT-basiert)
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
        // Kalibrierungsmodus erkennen: Override aktiv + Orchestrator-Flap-Map vorhanden
        $override = GetValue($this->GetIDForIdent('OverrideActive'));
        $flapMap  = json_decode($this->ReadAttributeString('LastOrchestratorFlaps') ?: '[]', true);
        $calibMode = ($override && is_array($flapMap) && !empty($flapMap));

        foreach ($rooms as $r) {
            $name = (string)($r['name'] ?? 'room');
            $ist  = $this->GetFloat((int)($r['tempID'] ?? 0));
            $soll = $this->GetFloat((int)($r['targetID'] ?? 0));
            $win  = $this->isWindowOpenStable($r);
            $anyWindow = $anyWindow || $win;
            $effectiveDemand = false;

            if ($calibMode) {
                // Im Kalibrierungsmodus zählen offene Klappen (laut Plan) als "Bedarf"
                $nameStr = (string)($r['name'] ?? '');
                if ($nameStr !== '' && array_key_exists($nameStr, $flapMap)) {
                    $effectiveDemand = (bool)$flapMap[$nameStr]; // true = Klappe offen
                }
            } else {
                // --- bestehende Logik unverändert: Bedarfsausgabe 2/3, sonst ΔT-Fallback ---
                $demVarID = (int)($r['demandID'] ?? $r['bedarfID'] ?? $r['bedarfsausgabeID'] ?? 0);
                $hasDemandVar = ($demVarID > 0 && IPS_VariableExists($demVarID));
                if ($hasDemandVar) {
                    $dem = (int)@GetValue($demVarID);
                    $effectiveDemand = ($dem === 2 || $dem === 3);
                } else {
                    if (is_finite($ist) && is_finite($soll)) {
                        $effectiveDemand = (($ist - $soll) > $hyst);
                    }
                }
            }

            // Fenster-offen blockiert immer (bleibt unverändert)
            if ($win) {
                $effectiveDemand = false;
            }
           
            // Override: offene Klappe als aktiver Bedarf werten
            $effectiveDemand = false;
            $override = GetValue($this->GetIDForIdent('OverrideActive'));
            if ($override) {
                $flapVarID = (int)($r['flapID'] ?? 0);
                $flapType  = strtolower((string)($r['flapType'] ?? 'boolean'));
                if ($flapVarID > 0 && IPS_VariableExists($flapVarID)) {
                    if ($flapType === 'linear') {
                        $effectiveDemand = ((int)@GetValue($flapVarID)) > 0; // >0 => offen
                    } else {
                        $effectiveDemand = $this->toBool(@GetValue($flapVarID));
                    }
                }
            }

            // Nur wenn Override nicht bereits Bedarf gesetzt hat, normale Logik nutzen
            if (!$effectiveDemand) {
                // (bestehende Logik: Bedarfsausgabe 2/3, sonst ΔT-Fallback)
            }

            // effektiver Bedarf: erst Bedarfsausgabe (1/2), sonst ΔT-Fallback
            $demVarID = (int)($r['demandID'] ?? $r['bedarfID'] ?? $r['bedarfsausgabeID'] ?? 0);
            $hasDemandVar = ($demVarID > 0 && IPS_VariableExists($demVarID));

            if ($hasDemandVar) {
                $dem = (int)@GetValue($demVarID);
                $effectiveDemand = ($dem === 2 || $dem === 3); // neue Regel
            } else {
                if (is_finite($ist) && is_finite($soll)) {
                    $effectiveDemand = (($ist - $soll) > $hyst);
                }
            }

            // Fenster offen blockiert immer
            if ($win) {
                $effectiveDemand = false;
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
        $this->log(3, 'agg', $agg);

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
        $power = $this->clamp($power, 0, 100);
        $fan   = $this->clamp($fan,   0, 100);

        $acVar  = (int)$this->ReadPropertyInteger('MainACOnOffLink');
        $fanVar = (int)$this->ReadPropertyInteger('MainFanControlLink');

        // Instrumentation
        $this->log(3, 'system_set_percent', [
            'power' => $power,
            'fan'   => $fan,
            'acVar' => $acVar,
            'fanVar'=> $fanVar
        ]);

        if ($acVar > 0)  $this->writeVarSmart($acVar,  $power);
        if ($fanVar > 0) $this->writeVarSmart($fanVar, $fan);
    }

    private function writeVarSmart(int $varID, $value): void
    {
        if ($varID <= 0) return;

        $vinfo = IPS_GetVariable($varID);
        $vt = $vinfo['VariableType'] ?? -1;
        $hasAction = ($vinfo['VariableAction'] ?? 0) !== 0;

        // Instrumentation
        $this->log(3, 'writeVarSmart', [
            'varID'     => $varID,
            'varType'   => $vt,
            'hasAction' => $hasAction,
            'value'     => $value
        ]);

        switch ($vt) {
            case 0: // boolean
                if (is_numeric($value)) {
                    $value = ((int)$value) >= 1;
                } elseif (!is_bool($value)) {
                    $value = (string)$value === 'true';
                }
                @RequestAction($varID, (bool)$value);
                break;
            case 1: // integer
                @RequestAction($varID, (int)$value);
                break;
            case 2: // float
                @RequestAction($varID, (float)$value);
                break;
            case 3: // string
                @RequestAction($varID, (string)$value);
                break;
            default:
                // Fallback: direkter SetValue (wenn kein Action-Handler)
                @SetValue($varID, $value);
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
