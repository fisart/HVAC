<?php
/**
 * Adaptive HVAC Control 
 *
 * Version: 2.8.3 (GetActionPairs API + ZDM demand gate + mode mapping)
 * Author:  Artur Fischer & AI Consultant
 *
 * - Q-Learning mit Epsilon (Start/Min/Decay)
 * - Coil-Schutz:
 *     * Emergency cutoff via EmergencyCoilTempLink (hard stop, z.B. ≤ 0 °C)
 *     * Learning limit via MinCoilTempLearning (z.B. 2 °C)
 *     * Drop-rate Watchdog
 * - ZDM-Aggregate optional im State
 * - Q-Table atomar in Datei (optional) oder Attribut
 * - Konsistentes Logging in Meldungen
 */

declare(strict_types=1);

class adaptive_HVAC_control extends IPSModule
{
    // -------------------- Lifecycle --------------------

    public function Create()
    {
        parent::Create();

        // Bestehende Properties
        $this->RegisterPropertyBoolean('ManualOverride', false);
        $this->RegisterPropertyInteger('LogLevel', 3); // 0=ERROR,1=WARN,2=INFO,3=DEBUG

        $this->RegisterPropertyFloat('Alpha', 0.05);
        $this->RegisterPropertyFloat('Gamma', 0.90);
        $this->RegisterPropertyFloat('DecayRate', 0.005);

        $this->RegisterPropertyFloat('Hysteresis', 0.5);
        $this->RegisterPropertyInteger('MaxPowerDelta', 40);
        $this->RegisterPropertyInteger('MaxFanDelta', 40);

        $this->RegisterPropertyInteger('ACActiveLink', 0);
        $this->RegisterPropertyInteger('PowerOutputLink', 0);
        $this->RegisterPropertyInteger('FanOutputLink', 0);

        $this->RegisterPropertyInteger('TimerInterval', 60);

        // Actions/Granularität
        $this->RegisterPropertyString('CustomPowerLevels', '0,40,80,100');
        $this->RegisterPropertyInteger('PowerStep', 20);
        $this->RegisterPropertyString('CustomFanSpeeds', '0,40,80,100');
        $this->RegisterPropertyInteger('FanStep', 20);

        // Sensoren
        $this->RegisterPropertyInteger('CoilTempLink', 0);

        // Räume
        $this->RegisterPropertyString('MonitoredRooms', '[]');

        // --- NEU: Epsilon ---
        $this->RegisterPropertyFloat('EpsilonStart', 0.40);
        $this->RegisterPropertyFloat('EpsilonMin',   0.05);
        $this->RegisterPropertyFloat('EpsilonDecay', 0.995);

        // --- NEU: Coil-Safety (neue Namen) ---
        $this->RegisterPropertyFloat('MinCoilTempLearning', 2.0);     // learning limit, °C
        $this->RegisterPropertyInteger('EmergencyCoilTempLink', 0);   // emergency cutoff variable link

        // --- ALT (deprecated) für Migration ---
        $this->RegisterPropertyFloat('MinCoilTemp', 2.0);             // will migrate into MinCoilTempLearning
        $this->RegisterPropertyInteger('MinCoilTempLink', 0);         // will migrate into EmergencyCoilTempLink

        // --- Weitere Schutz-Parameter ---
        $this->RegisterPropertyFloat('MaxCoilDropRate', 1.5);         // K/min
        $this->RegisterPropertyBoolean('AbortOnCoilFreeze', true);

        // ZDM-Integration
        $this->RegisterPropertyInteger('ZDM_InstanceID', 0);

        // Q-Table Persistenz (optional Datei)
        $this->RegisterPropertyString('QTablePath', '');

        // Attribute
        $this->RegisterAttributeFloat('Epsilon', 0.0);
        $this->RegisterAttributeString('QTable', '{}');
        $this->RegisterAttributeString('LastAction', '0:0');

        // Timer → wrapper via module.json prefix (assumed "ACIPS")
        $this->RegisterTimer('LearningTimer', 0, 'ACIPS_ProcessLearning($_IPS[\'TARGET\']);');
        $this->RegisterAttributeBoolean('MigratedNaming', false);

        // Operating mode for Orchestrator
        $this->RegisterPropertyInteger('OperatingMode', 2); // 0=Cooling, 1=Heating, 2=Auto/Cooperative
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // ---- one-time migration (no recursion) ----
        $migrated = (bool)$this->ReadAttributeBoolean('MigratedNaming');
        if (!$migrated) {
            $changed = false;

            // MinCoilTemp -> MinCoilTempLearning (only if user had a non-default old value)
            $minLearn = (float)$this->ReadPropertyFloat('MinCoilTempLearning');
            $minOld   = (float)$this->ReadPropertyFloat('MinCoilTemp');
            if (abs($minLearn - 2.0) < 0.0001 && abs($minOld - 2.0) > 0.0001) {
                IPS_SetProperty($this->InstanceID, 'MinCoilTempLearning', $minOld);
                $changed = true;
            }

            // MinCoilTempLink -> EmergencyCoilTempLink (only if new is empty, old set)
            $emergLink = (int)$this->ReadPropertyInteger('EmergencyCoilTempLink');
            $oldLink   = (int)$this->ReadPropertyInteger('MinCoilTempLink');
            if ($emergLink === 0 && $oldLink > 0) {
                IPS_SetProperty($this->InstanceID, 'EmergencyCoilTempLink', $oldLink);
                $changed = true;
            }

            if ($changed) {
                $this->WriteAttributeBoolean('MigratedNaming', true);
                @IPS_ApplyChanges($this->InstanceID); // re-enter ONCE with migrated props
                return; // avoid continuing in the pre-migration context
            } else {
                // no migration needed; mark as done to avoid checking again
                $this->WriteAttributeBoolean('MigratedNaming', true);
            }
        }

        // ---- normal ApplyChanges flow ----
        $this->SetStatus(102);
        $intervalMs = max(1000, (int)$this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetTimerInterval('LearningTimer', $intervalMs);

        if ((float)$this->ReadAttributeFloat('Epsilon') <= 0.0) {
            $this->initExploration();
        }

        $this->log(2, 'apply_changes', ['interval_ms' => $intervalMs]);
    }

    // -------------------- Public APIs for Orchestrator/UI --------------------

    /**
     * Accepts int or string and normalizes to 0..2
     * 0=Cooling, 1=Heating, 2=Auto/Cooperative
     */
    public function SetMode(string $mode): void
    {
        $map = [
            '0' => 0, 'cool' => 0, 'cooling' => 0,
            '1' => 1, 'heat' => 1, 'heating' => 1,
            '2' => 2, 'auto' => 2,
            'cooperative' => 2, 'standalone' => 2, 'orchestrated' => 2
        ];

        if (is_string($mode)) {
            $key = mb_strtolower(trim($mode));
            if (array_key_exists($key, $map)) {
                $mode = $map[$key];
            } else {
                $this->log(1, 'set_mode_unknown_label', ['label' => $mode]);
                $mode = 2;
            }
        }
        if (!is_int($mode)) {
            $this->log(1, 'set_mode_type_error', ['given' => gettype($mode)]);
            $mode = 2;
        }
        if ($mode < 0) $mode = 0;
        if ($mode > 2) $mode = 2;

        IPS_SetProperty($this->InstanceID, 'OperatingMode', $mode);
        @IPS_ApplyChanges($this->InstanceID);

        $this->log(2, 'set_mode', ['mode' => $mode]);
    }

    public function GetMode(): int
    {
        return (int)$this->ReadPropertyInteger('OperatingMode');
    }

    /**
     * Expose all allowed "P:F" pairs for plan generation
     * Global wrapper => ACIPS_GetActionPairs($id)
     */
    public function GetActionPairs(): string
    {
        $allowed = $this->getAllowedActionPairs(); // map "P:F" => true
        return json_encode(array_keys($allowed));
    }

    /**
     * Simple external command hook (optional for Orchestrator)
     */
    public function CommandSystem(int $powerPercent, int $fanPercent): void
    {
        $powerPercent = max(0, min(100, $powerPercent));
        $fanPercent   = max(0, min(100, $fanPercent));
        $this->applyAction($powerPercent, $fanPercent);
    }

    // -------------------- Timer target (called via ACIPS_ProcessLearning wrapper) --------------------

    public function ProcessLearning(): void
    {
        if ($this->ReadPropertyBoolean('ManualOverride')) {
            $this->log(2, 'manual_override_active');
            return;
        }
        if (!$this->isTruthyVar($this->ReadPropertyInteger('ACActiveLink'))) {
            $this->applyAction(0, 0);  // Wichtig: Ausgänge auf 0 setzen wenn AC inaktiv
            $this->log(3, 'ac_inactive_skip');
            return;
        }

        // --- ZDM demand gate ---
        if (!$this->hasZdmCoolingDemand()) {
            $this->applyAction(0, 0);                  // ensure outputs are off
            $this->log(2, 'zdm_no_demand_idle');       // do not learn on idle
            return;
        }

        if (!$this->coilProtectionOk()) {
            $this->applyAction(0, 0);
            return;
        }

        $state = $this->buildStateVector();

        [$p, $f] = $this->selectActionEpsilonGreedy($state);
        [$p, $f] = $this->limitDeltas($p, $f);

        $this->applyAction($p, $f);

        $reward = $this->calculateReward($state, ['p'=>$p, 'f'=>$f]);

        $this->qlearnUpdate($state, $p, $f, $reward, /*explore=*/true);

        $this->annealEpsilon();
        $this->persistQTableIfNeeded();
    }

    // -------------------- Orchestrator API (global wrapper: ACIPS_ForceActionAndLearn) --------------------

    public function ForceActionAndLearn(string $pair): string
    {
        // --- ZDM demand gate for forced actions ---
        if (!$this->hasZdmCoolingDemand()) {
            $this->applyAction(0, 0);
            return json_encode(['ok'=>false,'err'=>'zdm_no_demand']);
        }

        if (!$this->coilProtectionOk()) {
            $this->applyAction(0, 0);
            return json_encode(['ok'=>false,'err'=>'coil_protection']);
        }

        $act = $this->validateActionPair($pair);
        if (!$act) {
            $this->log(1, 'force_invalid_pair', ['pair'=>$pair]);
            return json_encode(['ok'=>false,'err'=>'invalid_pair']);
        }
        [$p, $f] = $act;
        [$p, $f] = $this->limitDeltas($p, $f);

        $this->applyAction($p, $f);

        $state  = $this->buildStateVector();
        $reward = $this->calculateReward($state, ['p'=>$p, 'f'=>$f]);

        $this->qlearnUpdate($state, $p, $f, $reward, /*explore=*/false);
        $this->WriteAttributeString('LastAction', $p.':'.$f);
        $this->persistQTableIfNeeded();

        return json_encode(['ok'=>true, 'applied'=>['p'=>$p,'f'=>$f], 'reward'=>$reward]);
    }

    // -------------------- Buttons (placeholders so form actions work) --------------------

    public function ResetLearning(): void
    {
        $this->initExploration();
        $this->storeQTable([]); // clear
        $this->persistQTableIfNeeded();
        $this->log(2, 'reset_learning');
    }

    public function UpdateVisualization(): void
    {
        // Hook for your UI; keep as no-op for now
        $this->log(3, 'update_visualization');
    }

    // -------------------- Learning Core --------------------

    private function selectActionEpsilonGreedy(array $state): array
    {
        $pairs = $this->getAllowedActionPairs();
        if (empty($pairs)) return [0, 0];

        $eps = (float)$this->ReadAttributeFloat('Epsilon');
        if (mt_rand() / mt_getrandmax() < $eps) {
            $key = array_rand($pairs);
            [$p, $f] = array_map('intval', explode(':', $key));
            $this->log(3, 'select_explore', ['pair'=>$key,'epsilon'=>$eps]);
            return [$p, $f];
        }

        $bestKey = $this->bestActionForState($state, array_keys($pairs));
        [$p, $f] = array_map('intval', explode(':', $bestKey));
        $this->log(3, 'select_exploit', ['pair'=>$bestKey,'epsilon'=>$eps]);
        return [$p, $f];
    }

    private function qlearnUpdate(array $state, int $p, int $f, float $reward, bool $explore): void
    {
        $alpha = (float)$this->ReadPropertyFloat('Alpha');
        $gamma = (float)$this->ReadPropertyFloat('Gamma');

        $q = $this->loadQTable();

        $sKey = $this->stateKey($state);
        $aKey = $p.':'.$f;

        if (!isset($q[$sKey])) $q[$sKey] = [];
        if (!isset($q[$sKey][$aKey])) $q[$sKey][$aKey] = 0.0;

        $maxNext = 0.0;
        foreach ($q[$sKey] as $val) {
            if ($val > $maxNext) $maxNext = $val;
        }

        $old = $q[$sKey][$aKey];
        $new = (1 - $alpha) * $old + $alpha * ($reward + $gamma * $maxNext);
        $q[$sKey][$aKey] = $new;

        $this->storeQTable($q);
        $this->WriteAttributeString('LastAction', $aKey);
        $this->log(3, 'q_update', ['state'=>$sKey, 'a'=>$aKey, 'old'=>$old, 'new'=>$new, 'r'=>$reward, 'explore'=>$explore]);
    }

    private function buildStateVector(): array
    {
        $rooms = $this->getRooms();
        $hyst  = (float)$this->ReadPropertyFloat('Hysteresis');

        $numActive = 0;
        $maxDelta  = 0.0;
        $anyWindow = false;

        foreach ($rooms as $r) {
            $ist  = $this->getFloat((int)($r['tempID'] ?? 0));
            $soll = $this->getFloat((int)($r['targetID'] ?? 0));
            $win  = $this->roomWindowOpen($r);

            if (is_finite($ist) && is_finite($soll)) {
                $delta = $ist - $soll;
                if (!$win && $delta > $hyst) {
                    $numActive++;
                    $maxDelta = max($maxDelta, $delta);
                }
            }
            $anyWindow = $anyWindow || $win;
        }

        // ZDM Aggregates
        $agg = $this->fetchZDMAggregates();
        if ($agg) {
            $numActive = max($numActive, (int)($agg['numActiveRooms'] ?? 0));
            $maxDelta  = max($maxDelta, (float)($agg['maxDeltaT'] ?? 0.0));
            $anyWindow = $anyWindow || !empty($agg['anyWindowOpen']);
        }

        // Coil Temp
        $coil = $this->getFloat($this->ReadPropertyInteger('CoilTempLink'));

        return [
            'numActiveRooms' => $numActive,
            'maxDelta'       => round($maxDelta, 2),
            'anyWindowOpen'  => (int)$anyWindow,
            'coilTemp'       => is_finite($coil) ? round($coil, 2) : null
        ];
    }

    private function calculateReward(array $state, array $action): float
    {
        $comfort = -($state['maxDelta'] ?? 0.0);
        $energy  = -0.01 * (($action['p'] ?? 0) + ($action['f'] ?? 0));
        $window  = -0.5 * (int)($state['anyWindowOpen'] ?? 0);

        $penalty = 0.0;
        if (preg_match('/^(\d+):(\d+)$/', $this->ReadAttributeString('LastAction'), $m)) {
            $dp = abs(((int)$m[1]) - ($action['p'] ?? 0));
            $df = abs(((int)$m[2]) - ($action['f'] ?? 0));
            $penalty = -0.002 * ($dp + $df);
        }

        return (float)round($comfort + $energy + $window + $penalty, 4);
    }

    // -------------------- Coil / Safety --------------------

    private function coilProtectionOk(): bool
    {
        // 1) Emergency cutoff via linked variable (hard stop)
        $emergencyLink = (int)$this->ReadPropertyInteger('EmergencyCoilTempLink');
        if ($emergencyLink > 0 && IPS_VariableExists($emergencyLink)) {
            $emergencyTemp = @GetValue($emergencyLink);
            if (is_numeric($emergencyTemp) && $emergencyTemp <= 0.0) {
                $this->log(0, 'coil_emergency_cutoff', ['temp' => (float)$emergencyTemp]);
                return false;
            }
        }

        // 2) Learning coil freeze protection (soft limit)
        if (!$this->ReadPropertyBoolean('AbortOnCoilFreeze')) return true;

        $coilLink = (int)$this->ReadPropertyInteger('CoilTempLink');
        if ($coilLink <= 0 || !IPS_VariableExists($coilLink)) return true;

        $coil = @GetValue($coilLink);
        if (!is_numeric($coil)) return true;

        $coil = (float)$coil;
        $minLearning = (float)$this->ReadPropertyFloat('MinCoilTempLearning');
        if ($coil <= $minLearning) {
            $this->log(1, 'coil_below_learning_min', ['coil' => $coil, 'min' => $minLearning]);
            return false;
        }

        // 3) Drop rate protection
        $now  = time();
        $key  = 'coil_last';
        $last = $this->GetBuffer($key);
        if ($last) {
            $obj = json_decode($last, true);
            if (isset($obj['t'],$obj['v']) && is_numeric($obj['t']) && is_numeric($obj['v'])) {
                $dt = max(1, $now - (int)$obj['t']);
                $rate = ((float)$obj['v'] - $coil) * 60.0 / $dt; // positive = falling
                $maxDrop = (float)$this->ReadPropertyFloat('MaxCoilDropRate');
                if ($rate > $maxDrop) {
                    $this->log(1, 'coil_drop_rate', ['rate_K_min' => $rate, 'max' => $maxDrop]);
                    return false;
                }
            }
        }
        $this->SetBuffer($key, json_encode(['t' => $now, 'v' => $coil]));

        return true;
    }

    // -------------------- Epsilon --------------------

    private function initExploration(): void
    {
        $this->WriteAttributeFloat('Epsilon', (float)$this->ReadPropertyFloat('EpsilonStart'));
        $this->log(2, 'epsilon_init', ['eps'=>$this->ReadPropertyFloat('EpsilonStart')]);
    }

    private function annealEpsilon(): void
    {
        $eps   = (float)$this->ReadAttributeFloat('Epsilon');
        if ($eps <= 0.0) $eps = (float)$this->ReadPropertyFloat('EpsilonStart');
        $emin  = (float)$this->ReadPropertyFloat('EpsilonMin');
        $decay = (float)$this->ReadPropertyFloat('EpsilonDecay');
        $eps   = max($emin, $eps * $decay);
        $this->WriteAttributeFloat('Epsilon', $eps);
        $this->log(3, 'epsilon_anneal', ['eps'=>$eps]);
    }

    // -------------------- ZDM Aggregates --------------------

    private function fetchZDMAggregates(): ?array
    {
        $iid = (int)$this->ReadPropertyInteger('ZDM_InstanceID');
        if ($iid <= 0 || !IPS_InstanceExists($iid)) {
            return null; // ZDM nicht verknüpft
        }
    
        // Funktion existiert nicht → ZDM-Modul nicht geladen/registriert
        if (!function_exists('ZDM_GetAggregates')) {
            $this->log(1, 'zdm_agg_err', ['msg' => 'ZDM_GetAggregates() not available']);
            return null;
        }
    
        try {
            $json = @ZDM_GetAggregates($iid);
            $arr  = json_decode((string)$json, true);
            return is_array($arr) ? $arr : null;
        } catch (\Throwable $e) {
            $this->log(1, 'zdm_agg_err', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    // -------- ZDM demand detection --------

    private function hasZdmCoolingDemand(): bool
    {
        $iid = (int)$this->ReadPropertyInteger('ZDM_InstanceID');
        if ($iid <= 0 || !IPS_InstanceExists($iid)) {
            // No ZDM linked → do not block
            return true;
        }

        $agg = $this->fetchZDMAggregates();
        if (!is_array($agg)) {
            // If we can't read aggregates, don't block
            return true;
        }

        // explicit flag if provided
        if (array_key_exists('coolingDemand', $agg)) {
            $cd = $agg['coolingDemand'];
            if (is_bool($cd)) return $cd;
            if (is_numeric($cd)) return ((float)$cd) > 0.0;
            if (is_string($cd)) return in_array(mb_strtolower(trim($cd)), ['1','true','on','yes'], true);
        }

        // fallback: num active rooms or total demand
        $numActive = (int)($agg['numActiveRooms'] ?? 0);
        $totalCooling = (float)($agg['totalCoolingDemand'] ?? ($agg['totalDemand'] ?? 0.0));

        return ($numActive > 0) || ($totalCooling > 0.0);
    }

    // -------------------- Q-Table Persistenz --------------------

    private function persistQTableIfNeeded(): void
    {
        $q = $this->loadQTable();
        $this->saveQTableAtomic(json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function loadQTable(): array
    {
        $path = trim($this->ReadPropertyString('QTablePath'));
        if ($path !== '' && is_file($path)) {
            $s = @file_get_contents($path);
            $j = json_decode((string)$s, true);
            if (is_array($j)) return $j;
        }
        $j = json_decode($this->ReadAttributeString('QTable'), true);
        return is_array($j) ? $j : [];
    }

    private function storeQTable(array $q): void
    {
        $this->WriteAttributeString('QTable', json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function saveQTableAtomic(string $json): void
    {
        $path = trim($this->ReadPropertyString('QTablePath'));
        if ($path === '') {
            $this->WriteAttributeString('QTable', $json);
            return;
        }
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            $this->log(0, 'q_save_tmp_failed', ['tmp'=>$tmp]);
            return;
        }
        if (!@rename($tmp, $path)) {
            $this->log(0, 'q_save_rename_failed', ['from'=>$tmp,'to'=>$path]);
            @unlink($tmp);
            return;
        }
        $this->log(2, 'q_saved', ['path'=>$path]);
    }

    // -------------------- Actions / Outputs --------------------

    private function applyAction(int $p, int $f): void
    {
        $p = max(0, min(100, $p));
        $f = max(0, min(100, $f));
        $powerVarID = $this->ReadPropertyInteger('PowerOutputLink');
        $fanVarID = $this->ReadPropertyInteger('FanOutputLink');
        $this->setPercent($powerVarID, $p);
        $this->setPercent($fanVarID, $f);
        $this->WriteAttributeString('LastAction', $p.':'.$f);
        $this->log(2, 'apply_action', ['p'=>$p,'f'=>$f, 'powerVarID'=>$powerVarID, 'fanVarID'=>$fanVarID]);
    }

    private function limitDeltas(int $p, int $f): array
    {
        if (preg_match('/^(\d+):(\d+)$/', $this->ReadAttributeString('LastAction'), $m)) {
            $lp = (int)$m[1]; $lf = (int)$m[2];
            $dpMax = max(0, (int)$this->ReadPropertyInteger('MaxPowerDelta'));
            $dfMax = max(0, (int)$this->ReadPropertyInteger('MaxFanDelta'));
            if (abs($p - $lp) > $dpMax) $p = ($p > $lp) ? $lp + $dpMax : $lp - $dpMax;
            if (abs($f - $lf) > $dfMax) $f = ($f > $lf) ? $lf + $dfMax : $lf - $dfMax;
        }
        return [$p, $f];
    }

    private function setPercent(int $varID, int $val): void
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return;
        $vt = IPS_GetVariable($varID)['VariableType'] ?? -1;
        switch ($vt) {
            case 0: @RequestAction($varID, $val >= 1); break;   // bool → on/off
            case 1: @RequestAction($varID, (int)$val); break;   // int
            case 2: @RequestAction($varID, (float)$val); break; // float
            case 3: @RequestAction($varID, (string)$val); break;// string
            default: @SetValue($varID, $val); break;
        }
    }

    // -------------------- Allowed Actions --------------------

    private function getAllowedActionPairs(): array
    {
        $powers = $this->parseIntList($this->ReadPropertyString('CustomPowerLevels'), 0, 100, $this->ReadPropertyInteger('PowerStep'));
        $fans   = $this->parseIntList($this->ReadPropertyString('CustomFanSpeeds'),   0, 100, $this->ReadPropertyInteger('FanStep'));
    
        $map = [];
        foreach ($powers as $p) {
            foreach ($fans as $f) {
                $map[$p.':'.$f] = true;
            }
        }
    
        // --- ALWAYS allow hard-off ---
        $map['0:0'] = true;
    
        return $map;
    }
    private function validateActionPair(string $pair): ?array
    {
        if (!preg_match('/^\s*(\d{1,3})\s*:\s*(\d{1,3})\s*$/', $pair, $m)) return null;
        $p = min(100, max(0, (int)$m[1]));
        $f = min(100, max(0, (int)$m[2]));
    
        // --- ALWAYS allow hard-off ---
        if ($p === 0 && $f === 0) {
            return [0, 0];
        }
    
        $allowed = $this->getAllowedActionPairs();
        if (!$allowed) return [$p, $f];
    
        $key = $p.':'.$f;
        if (isset($allowed[$key])) return [$p, $f];
    
        $best = $this->nearestAction($p, $f, array_keys($allowed));
        [$p, $f] = array_map('intval', explode(':', $best));
        $this->log(1, 'action_adjusted_to_allowed', ['req'=>$key,'adj'=>$best]);
        return [$p, $f];
    }

    private function nearestAction(int $p, int $f, array $keys): string
    {
        $best = null; $bd = PHP_INT_MAX;
        foreach ($keys as $k) {
            [$ap, $af] = array_map('intval', explode(':', $k));
            $d = ($ap - $p) * ($ap - $p) + ($af - $f) * ($af - $f);
            if ($d < $bd) { $bd = $d; $best = $k; }
        }
        return $best ?? '0:0';
    }

    private function bestActionForState(array $state, array $allowedKeys): string
    {
        $q = $this->loadQTable();
        $sKey = $this->stateKey($state);

        $bestKey = $allowedKeys[0] ?? '0:0';
        $bestVal = -INF;

        foreach ($allowedKeys as $k) {
            $val = $q[$sKey][$k] ?? 0.0;
            if ($val > $bestVal) { $bestVal = $val; $bestKey = $k; }
        }
        return $bestKey;
    }

    // -------------------- Utils --------------------

    private function stateKey(array $s): string
    {
        return md5(json_encode($s));
    }

    private function parseIntList(string $csv, int $min, int $max, int $fallbackStep): array
    {
        $arr = array_filter(array_map('trim', explode(',', $csv)), 'strlen');
        $out = [];
        foreach ($arr as $x) {
            if (is_numeric($x)) {
                $v = (int)$x;
                if ($v >= $min && $v <= $max) $out[$v] = true;
            }
        }
        if (empty($out)) {
            for ($v = $min; $v <= $max; $v += max(1, (int)$fallbackStep)) $out[$v] = true;
        }
        ksort($out, SORT_NUMERIC);
        return array_keys($out);
    }

    private function getRooms(): array
    {
        $json = $this->ReadPropertyString('MonitoredRooms') ?: '[]';
        $arr  = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    private function roomWindowOpen(array $room): bool
    {
        // Fensterlogik ggf. via ZDM
        return false;
    }

    private function getFloat(int $varID): float
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return NAN;
        $v = @GetValue($varID);
        return is_numeric($v) ? (float)$v : NAN;
    }

    private function isTruthyVar(int $varID): bool
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return false;
        $v = @GetValue($varID);
        if (is_bool($v))   return $v;
        if (is_numeric($v))return ((float)$v) > 0;
        if (is_string($v)) return in_array(mb_strtolower(trim($v)), ['1','true','on'], true);
        return false;
    }

    // -------------------- Logging --------------------

    private function log(int $lvl, string $event, array $data = [])
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

        $this->LogMessage("ADHVAC ".$line, $prio);
        // Optional: $this->SendDebug('ADHVAC', $line, 0);
    }
}
