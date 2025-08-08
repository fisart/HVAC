<?php
/**
 * Zoning_and_Demand_Manager
 *
 * Version: 1.3 (Debounce + Aggregates + Console Logging + Orchestrator APIs)
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

        // Konfiguration prüfen (Minimal-Check)
        $ok = true;
        // (Optional mehr Checks hinzufügen, z.B. ob ControlledRooms nicht leer)
        $this->SetStatus($ok ? 102 : 202);

        $intervalMs = max(1, (int)$this->ReadPropertyInteger('TimerInterval')) * 1000;
        $this->SetTimerInterval('ZDM_Timer', $intervalMs);

        $this->log(2, 'apply_changes', [
            'interval_s' => (int)$this->ReadPropertyInteger('TimerInterval'),
            'hyst'       => (float)$this->ReadPropertyFloat('Hysteresis')
        ]);
    }

    // ---------- Public (Timer) ----------

    public function ZDM_ProcessZoning()
    {
        $this->ProcessZoning();
    }

    public function ProcessZoning(): void
    {
        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        try {
            // Override aktiv? Dann keine Autologik
            $override = GetValue($this->GetIDForIdent('OverrideActive'));
            if ($override) {
                $this->log(2, 'override_active_skip_cycle');
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
                $ist  = $this->GetFloat((int)($room['tempID'] ?? 0));
                $soll = $this->GetFloat((int)($room['targetID'] ?? 0));

                $winOpen = $this->isWindowOpenStable($room);
                if ($winOpen) {
                    $this->setFlap($room, false);
                    $this->log(3, 'room_window_open_flap_closed', ['room'=>$name]);
                    continue;
                }

                if (!is_finite($ist) || !is_finite($soll)) {
                    $this->log(1, 'invalid_temp_values', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll]);
                    $this->setFlap($room, false);
                    continue;
                }

                $hyst = (float)$this->ReadPropertyFloat('Hysteresis');
                $delta = $ist - $soll; // >0 = Ist über Soll => Kühlbedarf
                if ($delta > $hyst) {
                    // Kühlen (Flap auf)
                    $this->setFlap($room, true);
                    $anyDemand = true;
                    $this->log(3, 'room_cooling_needed_flap_open', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll, 'delta'=>$delta]);
                } elseif ($delta < -$hyst) {
                    // Deutlich unter Soll -> sicher schließen
                    $this->setFlap($room, false);
                    $this->log(3, 'room_below_setpoint_flap_closed', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll, 'delta'=>$delta]);
                } else {
                    // Im deadband -> keine Änderung (optional schließen)
                    $this->log(3, 'room_in_deadband_keep', ['room'=>$name, 'ist'=>$ist, 'soll'=>$soll, 'delta'=>$delta]);
                }
            }

            // System schalten (Standalone optional)
            if ($this->ReadPropertyBoolean('StandaloneMode')) {
                if ($anyDemand) {
                    $this->systemOnStandalone();
                } else {
                    $this->systemOff();
                }
            }

            // Aggregates berechnen (optional für Adaptive-Modul)
            $this->ZDM_GetAggregates();

        } finally {
            $this->guardLeave();
        }
    }

    // ---------- Public (Orchestrator APIs) ----------

    /**
     * Orchestrator setzt/löscht Override.
     */
    public function ZDM_SetOverrideMode(bool $on): void
    {
        if (!$this->guardEnter()) { $this->log(1, 'guard_timeout'); return; }
        try {
            SetValue($this->GetIDForIdent('OverrideActive'), $on);
            $this->ReloadForm();
            $this->log(2, 'override_set', ['on' => $on]);

            if ($on) {
                // Im Zweifel alles neutralisieren:
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
    public function ZDM_CommandFlaps(string $stageName, string $flapConfigJson): void
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
    public function ZDM_CommandSystem(int $powerPercent, int $fanPercent): void
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
    public function ZDM_GetAggregates(): string
    {
        $rooms = $this->getRooms();
        $numActive = 0;
        $maxDeltaT = 0.0;
        $anyWindow = false;
        $activeRooms = [];

        $hyst = (float)$this->ReadPropertyFloat('Hysteresis');

        foreach ($rooms as $r) {
            $ist  = $this->GetFloat((int)($r['tempID'] ?? 0));
            $soll = $this->GetFloat((int)($r['targetID'] ?? 0));
            $win  = $this->isWindowOpenStable($r);

            if (is_finite($ist) && is_finite($soll)) {
                $delta = $ist - $soll; // >0 = Kühlbedarf
                if (!$win && ($delta > $hyst)) {
                    $numActive++;
                    $activeRooms[] = (string)($r['name'] ?? 'room');
                    $maxDeltaT = max($maxDeltaT, abs($delta));
                }
            }
            $anyWindow = $anyWindow || $win;
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
            $pctOpen  = $this->clamp((int)($room['flapOpenLinear'] ?? 100), 0, 100);
            $pctClose = $this->clamp((int)($room['flapClosedLinear'] ?? 0),   0, 100);
            $val = $open ? $pctOpen : $pctClose;
            $this->writeVarSmart($varID, $val);
            $this->log(3, 'flap_set_linear', ['room'=>$room['name'] ?? 'room', 'value'=>$val]);
        } else {
            // boolean
            $valOpen  = $room['flapOpenValue']   ?? true;
            $valClose = $room['flapClosedValue'] ?? false;
            $val = $open ? $valOpen : $valClose;
            $this->writeVarSmart($varID, $val);
            $this->log(3, 'flap_set_boolean', ['room'=>$room['name'] ?? 'room', 'value'=>$val]);
        }
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

        if ($acVar > 0)  $this->writeVarSmart($acVar,  $power);
        if ($fanVar > 0) $this->writeVarSmart($fanVar, $fan);
    }

    private function writeVarSmart(int $varID, $value): void
    {
        if ($varID <= 0) return;
        $vt = IPS_GetVariable($varID)['VariableType'] ?? -1;

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

    private function isWindowOpenStable(array $room): bool
    {
        $debounce = max(0, (int)$this->ReadPropertyInteger('WindowDebounceSec'));
        $name = (string)($room['name'] ?? 'room');
        $map = $this->getWindowStableMap();

        $raw = $this->isWindowOpenRaw($room);
        $now = time();

        $st = $map[$name] ?? ['open' => $raw, 'ts' => $now];
        if ($raw !== $st['open']) {
            if (($now - $st['ts']) >= $debounce) {
                $st = ['open' => $raw, 'ts' => $now];
                $map[$name] = $st;
                $this->setWindowStableMap($map);
                $this->log(2, 'window_state_committed', ['room'=>$name, 'open'=>$st['open']]);
            } else {
                // innerhalb Debounce → alten stabilen Zustand halten
            }
        } else {
            // Refresh gelegentlich
            if (($now - $st['ts']) > 3600) {
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

    private function getWindowStableMap(): array
    {
        return json_decode($this->ReadAttributeString('WindowStable'), true) ?: [];
    }

    private function setWindowStableMap(array $m): void
    {
        $this->WriteAttributeString('WindowStable', json_encode($m));
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
