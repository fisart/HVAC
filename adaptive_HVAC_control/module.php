<?php
/**
 * Adaptive HVAC Control (Unified)
 *
 * Version: 3.5 (ZDM master + transition Q-learning + visualization + safety)
 * Author:  Artur Fischer & AI Consultant
 *
 * - ZDM orchestrates ticks (ACIPS timer disabled)
 * - Q-Learning with epsilon (start/min/decay)
 * - Proper transition update (prev → new) using MetaData buffer
 * - Reward shaping: comfort, energy, window, change penalty, progress (WAD), freeze penalty
 * - Coil safety gates: emergency cutoff, learning min, drop-rate watchdog
 * - Uses ZDM aggregates for demand/rooms; commands hardware via ZDM only
 * - Q-Table atomic file persistence (optional) or attribute
 * - Visualization variables: CurrentEpsilon, QTableJSON, QTableHTML
 */

declare(strict_types=1);

class adaptive_HVAC_control extends IPSModule
{
    // -------------------- Lifecycle --------------------

    public function Create()
    {
        parent::Create();

        // Core properties
        $this->RegisterPropertyBoolean('ManualOverride', false);
        $this->RegisterPropertyInteger('LogLevel', 3); // 0=ERROR,1=WARN,2=INFO,3=DEBUG

        $this->RegisterPropertyFloat('Alpha', 0.05);
        $this->RegisterPropertyFloat('Gamma', 0.90);
        $this->RegisterPropertyFloat('DecayRate', 0.005);

        $this->RegisterPropertyFloat('Hysteresis', 0.5);
        $this->RegisterPropertyInteger('MaxPowerDelta', 40);
        $this->RegisterPropertyInteger('MaxFanDelta', 40);

        $this->RegisterPropertyInteger('ACActiveLink', 0);
        $this->RegisterPropertyInteger('PowerOutputLink', 0); // kept for legacy; not used directly
        $this->RegisterPropertyInteger('FanOutputLink', 0);   // kept for legacy; not used directly
        $this->RegisterPropertyInteger('TimerInterval', 60);

        // Actions/Granularity
        $this->RegisterPropertyString('CustomPowerLevels', '0,40,80,100');
        $this->RegisterPropertyInteger('PowerStep', 20);
        $this->RegisterPropertyString('CustomFanSpeeds', '0,40,80,100');
        $this->RegisterPropertyInteger('FanStep', 20);

        // Sensors
        $this->RegisterPropertyInteger('CoilTempLink', 0);

        // Rooms
        $this->RegisterPropertyString('MonitoredRooms', '[]');

        // Epsilon
        $this->RegisterPropertyFloat('EpsilonStart', 0.40);
        $this->RegisterPropertyFloat('EpsilonMin',   0.05);
        $this->RegisterPropertyFloat('EpsilonDecay', 0.995);

        // Coil-Safety
        $this->RegisterPropertyFloat('MinCoilTempLearning', 2.0);     // °C
        $this->RegisterPropertyInteger('EmergencyCoilTempLink', 0);   // emergency cutoff variable link
        $this->RegisterPropertyFloat('MaxCoilDropRate', 1.5);         // K/min
        $this->RegisterPropertyBoolean('AbortOnCoilFreeze', true);

        // ZDM Integration
        $this->RegisterPropertyInteger('ZDM_InstanceID', 0);

        // Q-Table persistence (optional file)
        $this->RegisterPropertyString('QTablePath', '');

        // Attributes / buffers
        $this->RegisterAttributeFloat('Epsilon', 0.0);
        $this->RegisterAttributeString('QTable', '{}');
        $this->RegisterAttributeString('LastAction', '0:0');
        $this->RegisterAttributeBoolean('MigratedNaming', false);
        $this->SetBuffer('MetaData', json_encode([])); // prev state/action/ts + metrics

        // Timer → wrapper via module.json prefix (assumed "ACIPS")
        $this->RegisterTimer('LearningTimer', 0, 'ACIPS_ProcessLearning($_IPS[\'TARGET\']);');

        // Operating mode (kept for API compatibility)
        $this->RegisterPropertyInteger('OperatingMode', 2); // 0=Cooling, 1=Heating, 2=Auto/Cooperative

        // Diagnostics / Visualization
        $this->RegisterVariableFloat('CurrentEpsilon', 'Current Epsilon', '', 1);
        $this->RegisterVariableString('QTableJSON', 'Q-Table (JSON)', '~TextBox', 2);
        $this->RegisterVariableString('QTableHTML', 'Q-Table Visualization', '~HTMLBox', 3);
        $this->RegisterAttributeString('StateLabels', '{}');
    
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetStatus(102);
        // ZDM is master → disable internal timer
        $this->SetTimerInterval('LearningTimer', 0);

        if ((float)$this->ReadAttributeFloat('Epsilon') <= 0.0) {
            $this->initExploration();
        }
        $this->UpdateVisualization();
    }

    // -------------------- Public APIs for Orchestrator/UI --------------------

    /** Normalize mode: 0=Cooling, 1=Heating, 2=Auto/Cooperative */
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
            $mode = array_key_exists($key, $map) ? $map[$key] : 2;
        }
        if (!is_int($mode)) $mode = 2;
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

    /** Expose allowed "P:F" pairs for plan generation */
    public function GetActionPairs(): string
    {
        $allowed = $this->getAllowedActionPairs(); // map "P:F" => true
        return json_encode(array_keys($allowed));
    }

    /** Manual property read helper */
    public function TestReadMyProperties()
    {
        $powerID = $this->ReadPropertyInteger('PowerOutputLink');
        $fanID = $this->ReadPropertyInteger('FanOutputLink');
        $this->log(2, 'MANUAL_PROPERTY_TEST', [
            'PowerOutputLink_Read' => $powerID,
            'FanOutputLink_Read' => $fanID
        ]);
    }

    /** Optional external hook (kept) */
    public function CommandSystem(int $powerPercent, int $fanPercent): void
    {
        $powerPercent = max(0, min(100, $powerPercent));
        $fanPercent   = max(0, min(100, $fanPercent));
        $this->applyAction($powerPercent, $fanPercent);
    }

    public function UpdateVisualization(): void
    {
        try { $this->SetValue('CurrentEpsilon', (float)$this->ReadAttributeFloat('Epsilon')); } catch (\Throwable $e) {}
        try { $this->SetValue('QTableJSON', json_encode($this->loadQTable(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); } catch (\Throwable $e) {}
        try { $this->SetValue('QTableHTML', $this->GenerateQTableHTML()); } catch (\Throwable $e) {}
        $this->log(3, 'update_visualization');
    }

    // -------------------- Timer target (called by ZDM) --------------------

    public function ProcessLearning(): void
    {
        // Overlap guard
        if (!IPS_SemaphoreEnter('ADHVAC_' . $this->InstanceID, 0)) {
            $this->log(2, 'skip_overlapping_tick');
            return;
        }
        try {
            $this->log(3, 'process_learning_start', [
                'PowerOutputLink' => $this->ReadPropertyInteger('PowerOutputLink'),
                'FanOutputLink'   => $this->ReadPropertyInteger('FanOutputLink'),
                'ACActiveLink'    => $this->ReadPropertyInteger('ACActiveLink')
            ]);

            // Manual override disables learning/action
            if ($this->ReadPropertyBoolean('ManualOverride')) {
                $this->log(2, 'manual_override_active');
                return;
            }

            // AC inactive → ensure outputs are off
            if (!$this->isTruthyVar($this->ReadPropertyInteger('ACActiveLink'))) {
                $this->applyAction(0, 0);
                $this->log(3, 'ac_inactive_skip');
                return;
            }

            // ZDM demand gate
            $hasZdm = $this->hasZdmCoolingDemand();
            if (!$hasZdm) {
                $this->applyAction(0, 0);
                $this->log(2, 'zdm_no_demand_idle');
                return;
            }

            // Coil safety
            if (!$this->coilProtectionOk()) {
                $this->applyAction(0, 0);
                return;
            }

            // ---- Build current state and readable bucket key ----
            $state    = $this->buildStateVector();
            $label    = $this->formatStateLabel($state);

            $prevMeta = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
            $prevCoil = (is_array($prevMeta) && isset($prevMeta['coil']))
                ? (float)$prevMeta['coil']
                : ($state['coilTemp'] ?? null);

            // Compute readable/bucket key (no md5)
            $sKeyNew  = $this->stateKeyBuckets($state, is_numeric($prevCoil) ? (float)$prevCoil : null);

            // Remember label for this row
            $this->rememberStateLabel($sKeyNew, $label);

            // Metrics for reward shaping
            $metrics  = $this->computeRoomMetrics();

            // ---- Transition update: (prev state, action) -> current bucketed state ----
            if (is_array($prevMeta) && isset($prevMeta['stateKey'], $prevMeta['action'])) {
                $rewardPrev = $this->calculateReward($state, $this->pairToArray($prevMeta['action']), $metrics, $prevMeta);
                $this->qlearnUpdateTransition($prevMeta['stateKey'], $prevMeta['action'], $rewardPrev, $sKeyNew);
            }

            // ---- Select next action; avoid 0:0 while demand exists ----
            $allowedKeys = array_keys($this->getAllowedActionPairs());
            $hasDemand   = ($state['numActiveRooms'] ?? 0) > 0 || $hasZdm;
            if ($hasDemand) {
                $allowedKeys = array_values(array_filter($allowedKeys, fn($k) => $k !== '0:0'));
            }

            [$p, $f] = $this->selectActionEpsilonGreedy($state, $allowedKeys);
            [$p, $f] = $this->limitDeltas($p, $f);
            if ($adj = $this->validateActionPair($p . ':' . $f)) {
                [$p, $f] = $adj;
            }
            // ---- Apply action ----
            $this->applyAction($p, $f);

            // ---- Housekeeping: epsilon, persistence, UI ----
            $this->annealEpsilon();
            $this->persistQTableIfNeeded();
            $this->UpdateVisualization();

            // ---- Stash meta for next transition (store BUCKET KEY!) ----
            $this->SetBuffer('MetaData', json_encode([
                'stateKey' => $sKeyNew,                 // <-- bucket key
                'action'   => $p . ':' . $f,
                'wad'      => $metrics['rawWAD'] ?? 0.0,
                'coil'     => $metrics['coilTemp'],
                'maxDelta' => $state['maxDelta'],
                'ts'       => time()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        } finally {
            IPS_SemaphoreLeave('ADHVAC_' . $this->InstanceID);
        }
    }
  

    // -------------------- Orchestrator API --------------------
    public function ForceActionAndLearn(string $pair): string
    {
        // Demand & safety gates
        if (!$this->hasZdmCoolingDemand()) {
            $this->applyAction(0, 0);
            return json_encode(['ok' => false, 'err' => 'zdm_no_demand']);
        }
        if (!$this->coilProtectionOk()) {
            $this->applyAction(0, 0);
            return json_encode(['ok' => false, 'err' => 'coil_protection']);
        }

        // Validate/limit action
        $act = $this->validateActionPair($pair);
        if (!$act) {
            $this->log(1, 'force_invalid_pair', ['pair' => $pair]);
            return json_encode(['ok' => false, 'err' => 'invalid_pair']);
        }
        [$p, $f] = $act;
        [$p, $f] = $this->limitDeltas($p, $f);
        if ($adj = $this->validateActionPair($p . ':' . $f)) {
            [$p, $f] = $adj;
        }
        // ---- Build current state and readable bucket key ----
        $state    = $this->buildStateVector();
        $label    = $this->formatStateLabel($state);

        $prevMeta = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
        $prevCoil = (is_array($prevMeta) && isset($prevMeta['coil']))
            ? (float)$prevMeta['coil']
            : ($state['coilTemp'] ?? null);

        $sKeyNew  = $this->stateKeyBuckets($state, is_numeric($prevCoil) ? (float)$prevCoil : null);
        $this->rememberStateLabel($sKeyNew, $label);

        $metrics  = $this->computeRoomMetrics();

        // ---- Transition update using previous meta ----
        if (is_array($prevMeta) && isset($prevMeta['stateKey'], $prevMeta['action'])) {
            $rewardPrev = $this->calculateReward($state, $this->pairToArray($prevMeta['action']), $metrics, $prevMeta);
            $this->qlearnUpdateTransition($prevMeta['stateKey'], $prevMeta['action'], $rewardPrev, $sKeyNew);
        }

        // ---- Apply forced action, persist, UI ----
        $this->applyAction($p, $f);
        $this->WriteAttributeString('LastAction', $p . ':' . $f);
        $this->persistQTableIfNeeded();
        $this->UpdateVisualization();

        // ---- Seed next transition (store BUCKET KEY!) ----
        $this->SetBuffer('MetaData', json_encode([
            'stateKey' => $sKeyNew,                 // <-- bucket key
            'action'   => $p . ':' . $f,
            'wad'      => $metrics['rawWAD'] ?? 0.0,
            'coil'     => $metrics['coilTemp'],
            'maxDelta' => $state['maxDelta'],
            'ts'       => time()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return json_encode(['ok' => true, 'applied' => ['p' => $p, 'f' => $f]]);
    }
  

    public function ResetLearning(): void
    {
        $this->initExploration();
        $this->storeQTable([]); // clear
        $this->SetBuffer('MetaData', json_encode([]));
        $this->persistQTableIfNeeded();
        $this->UpdateVisualization();
        $this->log(2, 'reset_learning');
    }

    // -------------------- Learning Core --------------------

    private function selectActionEpsilonGreedy(array $state, ?array $allowedKeys = null): array
    {
        $pairsMap = $this->getAllowedActionPairs();
        $keys = $allowedKeys ?? array_keys($pairsMap);
        if (empty($keys)) return [0, 0];

        $eps = (float)$this->ReadAttributeFloat('Epsilon');
        if (mt_rand() / mt_getrandmax() < $eps) {
            $key = $keys[array_rand($keys)];
            [$p, $f] = array_map('intval', explode(':', $key));
            $this->log(3, 'select_explore', ['pair'=>$key,'epsilon'=>$eps]);
            return [$p, $f];
        }

        $bestKey = $this->bestActionForState($state, $keys);
        [$p, $f] = array_map('intval', explode(':', $bestKey));
        $this->log(3, 'select_exploit', ['pair'=>$bestKey,'epsilon'=>$eps]);
        return [$p, $f];
    }

    private function qlearnUpdateTransition(string $sPrev, string $aPrev, float $reward, string $sNew): void
    {
        $alpha = (float)$this->ReadPropertyFloat('Alpha');
        $gamma = (float)$this->ReadPropertyFloat('Gamma');
        $q = $this->loadQTable();

        if (!isset($q[$sPrev])) $q[$sPrev] = [];
        if (!isset($q[$sPrev][$aPrev])) $q[$sPrev][$aPrev] = 0.0;
        if (!isset($q[$sNew])) $q[$sNew] = [];

        $maxFuture = 0.0;
        foreach ($q[$sNew] as $v) if ($v > $maxFuture) $maxFuture = $v;

        $old = $q[$sPrev][$aPrev];
        $new = (1 - $alpha) * $old + $alpha * ($reward + $gamma * $maxFuture);
        $q[$sPrev][$aPrev] = $new;

        $this->storeQTable($q);
        $this->log(3, 'q_update_transition', ['sPrev'=>$sPrev,'aPrev'=>$aPrev,'sNew'=>$sNew,'old'=>$old,'new'=>$new,'r'=>$reward]);
    }



    private function buildStateVector(): array
    {
        $rooms = $this->getRooms();
        $hyst  = (float)$this->ReadPropertyFloat('Hysteresis');

        $numActive = 0;
        $maxDelta  = 0.0;

        foreach ($rooms as $r) {
            $ist  = $this->getFloat((int)($r['tempID'] ?? 0));
            $soll = $this->getFloat((int)($r['targetID'] ?? 0));
            if (is_finite($ist) && is_finite($soll)) {
                $delta = $ist - $soll;
                if ($delta > $hyst) {
                    $numActive++;
                    $maxDelta = max($maxDelta, $delta);
                }
            }
        }

        // ZDM aggregates (still used for active rooms / max ΔT)
        $agg = $this->fetchZDMAggregates();
        if ($agg) {
            $numActive = max($numActive, (int)($agg['numActiveRooms'] ?? 0));
            $maxDelta  = max($maxDelta, (float)($agg['maxDeltaT'] ?? 0.0));
        }

        $coil = $this->getFloat($this->ReadPropertyInteger('CoilTempLink'));

        return [
            'numActiveRooms' => $numActive,
            'maxDelta'       => round($maxDelta, 2),
            'coilTemp'       => is_finite($coil) ? round($coil, 2) : null
        ];
    }


    private function computeRoomMetrics(): array
    {
        $rooms = $this->getRooms();
        $wDevSum = 0.0; $totalSize = 0.0; $D_cold = 0.0; $hotRooms = 0; $maxDev = 0.0;

        foreach ($rooms as $r) {
            $tid = (int)($r['tempID'] ?? 0);
            $sid = (int)($r['targetID'] ?? 0);
            if ($tid <= 0 || $sid <= 0 || !IPS_VariableExists($tid) || !IPS_VariableExists($sid)) continue;

            $ist  = (float)@GetValue($tid);
            $soll = (float)@GetValue($sid);
            $dev  = $ist - $soll;

            if ($dev > 0)  $maxDev = max($maxDev, $dev);
            if ($dev < 0)  $D_cold = max($D_cold, -$dev);

            $th = (float)($r['threshold'] ?? 0.5);
            if ($dev > $th) {
                $hotRooms++;
                $wDevSum   += $dev * (float)($r['size'] ?? 10.0);
                $totalSize += (float)($r['size'] ?? 10.0);
            }
        }
        $rawWAD = ($totalSize > 0.0) ? ($wDevSum / $totalSize) : 0.0;

        $coil = $this->getFloat($this->ReadPropertyInteger('CoilTempLink'));

        return [
            'rawWAD'   => round($rawWAD, 3),
            'hotRooms' => $hotRooms,
            'maxDev'   => round($maxDev, 3),
            'D_cold'   => round($D_cold, 3),
            'coilTemp' => is_finite($coil) ? round($coil, 3) : null
        ];
    }
    private function calculateReward(array $state, array $action, ?array $metrics = null, ?array $prevMeta = null): float
    {
        // Base from v2.8.3
        $comfort = -($state['maxDelta'] ?? 0.0);
        $energy  = -0.01 * (($action['p'] ?? 0) + ($action['f'] ?? 0));
        $window  = -0.5 * (int)($state['anyWindowOpen'] ?? 0);  // keep as-is; remove later if you drop window entirely

        $penalty = 0.0;
        if (preg_match('/^(\d+):(\d+)$/', $this->ReadAttributeString('LastAction'), $m)) {
            $dp = abs(((int)$m[1]) - ($action['p'] ?? 0));
            $df = abs(((int)$m[2]) - ($action['f'] ?? 0));
            $penalty = -0.002 * ($dp + $df);
        }

        // Shaping from v3.4
        $progress = 0.0;
        if ($metrics && $prevMeta && isset($prevMeta['wad'])) {
            $progress = (($prevMeta['wad'] ?? 0.0) - ($metrics['rawWAD'] ?? 0.0)) * 10.0;
        }

        $freeze = 0.0;
        if ($metrics) {
            $minL = (float)$this->ReadPropertyFloat('MinCoilTempLearning');
            $coil = $metrics['coilTemp'];
            if (is_numeric($coil) && $coil < $minL) {
                $freeze = -2.0 * ($minL - $coil);
            }
        }

        // --- NEW: comfort trend bonus/penalty (no new state) ---
        // Uses change in maxDelta: if maxDelta decreased, we are improving.
        $trend = 0.0;
        if ($prevMeta && isset($prevMeta['maxDelta']) && isset($state['maxDelta'])) {
            $delta = ((float)$prevMeta['maxDelta']) - ((float)$state['maxDelta']); // >0 = getting better
            $epsTrend = 0.10;  // ignore tiny noise (<0.1°C)
            $kTrend   = 2.0;   // weight
            if (abs($delta) > $epsTrend) {
                $trend = $kTrend * $delta; // positive if improving, negative if worsening
            }
        }

        return (float)round($comfort + $energy + $window + $penalty + $progress + $freeze + $trend, 4);
    }
 
    /**
     * Build a human friendly label from a state vector.
     * Adjust formatting if you change your state definition.
     */
    private function formatStateLabel(array $s): string
    {
        $n = (int)($s['numActiveRooms'] ?? 0);
        $d = is_numeric($s['maxDelta'] ?? null) ? number_format((float)$s['maxDelta'], 1) : 'n/a';
        $c = ($s['coilTemp'] === null) ? 'n/a' : number_format((float)$s['coilTemp'], 1) . '°C';
        return "N={$n} | Δ={$d} | Coil={$c}";
    }

    /**
     * Persist a mapping from Q-table key (hash) → human label.
     * Safe to call repeatedly.
     */
    private function rememberStateLabel(string $sKey, string $label): void
    {
        $raw = $this->ReadAttributeString('StateLabels') ?: '{}';
        $map = json_decode($raw, true);
        if (!is_array($map)) $map = [];

        $map[$sKey] = $label;

        // keep map bounded
        if (count($map) > 300) {
            // preserve insertion order
            $map = array_slice($map, -200, null, true);
        }

        $this->WriteAttributeString(
            'StateLabels',
            json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }


    private function bestActionForState(array $state, array $allowedKeys): string
    {
        $q = $this->loadQTable();

        // Use previous coil to compute the same bucket key we use for learning
        $prev = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
        $prevCoil = (is_array($prev) && isset($prev['coil'])) ? $prev['coil'] : ($state['coilTemp'] ?? null);

        // BUCKETED key (replaces: $sKey = $this->stateKey($state);)
        $sKey = $this->stateKeyBuckets($state, $prevCoil);

        $bestVal = -INF; $cands = [];
        foreach ($allowedKeys as $k) {
            $val = $q[$sKey][$k] ?? 0.0;
            if ($val > $bestVal) { $bestVal = $val; $cands = [$k]; }
            elseif (abs($val - $bestVal) < 1e-9) { $cands[] = $k; }
        }
        if (count($cands) > 1) {
            usort($cands, function($a,$b){
                [$ap,$af]=array_map('intval', explode(':',$a));
                [$bp,$bf]=array_map('intval', explode(':',$b));
                return ($bp+$bf) <=> ($ap+$af);
            });
            $top = array_slice($cands, 0, 2);
            return $top[array_rand($top)];
        }
        return $cands[0] ?? ($allowedKeys[0] ?? '0:0');
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
            return null; // ZDM not linked
        }

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

        if (array_key_exists('coolingDemand', $agg)) {
            $cd = $agg['coolingDemand'];
            if (is_bool($cd)) return $cd;
            if (is_numeric($cd)) return ((float)$cd) > 0.0;
            if (is_string($cd)) return in_array(mb_strtolower(trim($cd)), ['1','true','on','yes'], true);
        }

        $numActive = (int)($agg['numActiveRooms'] ?? 0);
        $totalCooling = (float)($agg['totalCoolingDemand'] ?? ($agg['totalDemand'] ?? 0.0));

        return ($numActive > 0) || ($totalCooling > 0.0);
    }

    // -------------------- Q-Table Persistence --------------------

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

        // Snap to grid here as well (extra safety)
        if ($adj = $this->validateActionPair($p . ':' . $f)) {
            [$p, $f] = $adj;
        }

        $this->commandZDM($p, $f);
        $this->WriteAttributeString('LastAction', $p.':'.$f);
        $this->log(2, 'apply_action', ['p' => $p, 'f' => $f]);
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
    public function MigrateQTableToAllowed(): void
    {
        $allowed = array_keys($this->getAllowedActionPairs());
        $allowedSet = array_fill_keys($allowed, true);

        $q = $this->loadQTable();
        $migrated = [];

        foreach ($q as $sKey => $row) {
            if (!is_array($row)) continue;
            foreach ($row as $act => $val) {
                $to = isset($allowedSet[$act]) ? $act : $this->nearestActionKey($act, $allowed);
                if (!isset($migrated[$sKey][$to])) {
                    $migrated[$sKey][$to] = (float)$val;
                } else {
                    // keep the larger (less negative) value; change to average if you prefer
                    $migrated[$sKey][$to] = max($migrated[$sKey][$to], (float)$val);
                }
            }
        }
        $this->storeQTable($migrated);
        $this->persistQTableIfNeeded();
        $this->UpdateVisualization();
        $this->log(2, 'qtable_migrated_to_allowed', ['actions'=>implode(',', $allowed)]);
    }

    private function nearestActionKey(string $act, array $allowed): string
    {
        if (!preg_match('/^\s*(\d{1,3})\s*:\s*(\d{1,3})\s*$/', $act, $m)) return $allowed[0];
        $p = (int)$m[1]; $f = (int)$m[2];

        $best = $allowed[0]; $bd = PHP_INT_MAX;
        foreach ($allowed as $k) {
            [$ap,$af] = array_map('intval', explode(':', $k));
            $d = ($ap - $p)*($ap - $p) + ($af - $f)*($af - $f);
            if ($d < $bd) { $bd = $d; $best = $k; }
        }
        return $best;
    }

    public function setPercent(int $val)
    {
        $this->log(3, 'setPercent_redirect', ['val' => $val]);
        $lastAction = $this->ReadAttributeString('LastAction');
        $lastFan = preg_match('/^(\d+):(\d+)$/', $lastAction, $m) ? (int)$m[2] : 0;
        $this->commandZDM($val, $lastFan);
    }

    public function setFanSpeed(int $val)
    {
        $this->log(3, 'setFanSpeed_redirect', ['val' => $val]);
        $lastAction = $this->ReadAttributeString('LastAction');
        $lastPower  = preg_match('/^(\d+):(\d+)$/', $lastAction, $m) ? (int)$m[1] : 0;
        $this->commandZDM($lastPower, $val);
    }

    private function commandZDM(int $power, int $fan): void
    {
        $zdm_id = (int)$this->ReadPropertyInteger('ZDM_InstanceID');

        if ($zdm_id <= 0 || !@IPS_InstanceExists($zdm_id)) {
            $this->log(1, 'command_zdm_failed', ['reason' => 'ZDM_InstanceID not configured or invalid', 'p' => $power, 'f' => $fan]);
            return;
        }
        if (!function_exists('ZDM_CommandSystem')) {
            $this->log(1, 'command_zdm_failed', ['reason' => 'ZDM_CommandSystem() API not available', 'zdm_id' => $zdm_id]);
            return;
        }

        try {
            $this->log(3, 'command_zdm_sending', ['zdm_id' => $zdm_id, 'p' => $power, 'f' => $fan]);
            ZDM_CommandSystem($zdm_id, $power, $fan);
            $this->log(3, 'command_zdm_success', ['zdm_id' => $zdm_id, 'p' => $power, 'f' => $fan]);
        } catch (Exception $e) {
            $this->log(0, 'command_zdm_error', [
                'zdm_id' => $zdm_id,
                'p' => $power,
                'f' => $fan,
                'error_message' => $e->getMessage()
            ]);
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
        // Always allow hard-off
        $map['0:0'] = true;
        return $map;
    }

    private function validateActionPair(string $pair): ?array
    {
        if (!preg_match('/^\s*(\d{1,3})\s*:\s*(\d{1,3})\s*$/', $pair, $m)) return null;
        $p = min(100, max(0, (int)$m[1]));
        $f = min(100, max(0, (int)$m[2]));

        if ($p === 0 && $f === 0) return [0, 0];

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
        // can be augmented via ZDM if needed
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

    private function pairToArray(string $pair): array
    {
        if (preg_match('/^(\d+):(\d+)$/', $pair, $m)) return ['p'=>(int)$m[1], 'f'=>(int)$m[2]];
        return ['p'=>0, 'f'=>0];
    }

    private function GenerateQTableHTML(): string
    {
        $q = $this->loadQTable();
        if (!is_array($q) || empty($q)) {
            return '<p style="font:500 14px/1.4 system-ui,Segoe UI,Roboto,sans-serif;margin:8px 0;">Q-Table is empty.</p>';
        }

        ksort($q);
        $actions = array_keys($this->getAllowedActionPairs());

        // labels map (Q-state key → human label). Falls back to the key if unknown.
        $labels = json_decode($this->ReadAttributeString('StateLabels') ?: '{}', true);
        if (!is_array($labels)) $labels = [];

        // Heatmap range
        $minQ = 0.0; $maxQ = 0.0;
        foreach ($q as $sa) {
            if (!is_array($sa)) continue;
            foreach ($sa as $v) {
                $v = (float)$v;
                if ($v < $minQ) $minQ = $v;
                if ($v > $maxQ) $maxQ = $v;
            }
        }

        // ---------- Styles ----------
        $html = '<style>
        :root{
            --fs: clamp(12px, 1.05vw, 14px);
            --fs-state: clamp(12px, 1.2vw, 16px);
            --cell-pad: 6px 8px;
            --sticky-bg: #fafafa;
            --head-bg: #f2f2f2;
            --grid: #ccc;
        }
        .qt-wrap{max-width:100%; overflow:auto;}
        .qtbl{border-collapse:collapse; width:100%; table-layout:fixed}
        .qtbl th,.qtbl td{border:1px solid var(--grid); padding:var(--cell-pad); text-align:center; font:500 var(--fs)/1.3 system-ui, Segoe UI, Roboto, sans-serif; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
        .qtbl th{background:var(--head-bg); position:sticky; top:0; z-index:2}
        .qtbl td.state,.qtbl th.state{position:sticky; left:0; z-index:3; background:var(--sticky-bg); text-align:left; font-weight:600; font-size:var(--fs-state); white-space:normal; word-break:break-word; max-width:28ch;}
        .qtbl td.num{white-space:nowrap}
        .legend, .acc{margin:10px 6px 8px; font:600 14px/1.4 system-ui, Segoe UI, Roboto, sans-serif}
        .acc summary{cursor:pointer; list-style: disclosure-closed; padding:6px 4px; border-radius:6px; background:#f7f7f7; border:1px solid #e6e6e6}
        .acc[open] summary{list-style: disclosure-open; background:#f3f3f3}
        .acc .body{font:500 13px/1.55 system-ui, Segoe UI, Roboto, sans-serif; color:#333; padding:8px 10px 10px}
        .kbd{font:600 12px/1 monospace; padding:1px 4px; border:1px solid #ddd; border-radius:4px; background:#f9f9f9}
        </style>';

        // ---------- Collapsible help ----------
        $html .= '
        <details class="acc"><summary>How to read this table</summary>
        <div class="body">
            Each row is a <b>state</b>, each column is an <b>action</b> (<span class="kbd">Power:Fan</span> in %).<br>
            Cells show the learned Q-value (higher is better). Red highlights negatives, green highlights positives.
            Values are shown with <b>2 decimals</b>; hover to see the exact value (5 decimals).
            <br><br>
            <b>Human state label:</b> <span class="kbd">N=… | Δ=… | Coil=…</span><br>
            • <b>N</b>: number of active rooms with cooling demand.<br>
            • <b>Δ</b>: maximum overshoot above setpoint (°C).<br>
            • <b>Coil</b>: coil temperature (°C).<br>
            If a readable label is not yet known, the technical state key is shown.
        </div>
        </details>

        <details class="acc"><summary>Bucket key format (what the learner uses)</summary>
        <div class="body">
            Internally, states are bucketed to keep the table compact. The key format is
            <span class="kbd">N&lt;n&gt;|D&lt;d&gt;|C&lt;c&gt;|T&lt;t&gt;</span>:
            <ul style="margin:6px 0 0 18px">
            <li><b>N</b>: active rooms → 0, 1, 2, 3, 4 (means 4 or more)</li>
            <li><b>D</b>: ΔT bin index (see ranges below)</li>
            <li><b>C</b>: coil margin bin relative to learning min (see ranges below)</li>
            <li><b>T</b>: coil trend (−1 cooling, 0 stable, +1 warming)</li>
            </ul>
        </div>
        </details>

        <details class="acc"><summary>Bucket ranges (Δ, Coil, Trend)</summary>
        <div class="body">
            <b>Δ (D-bin, overshoot °C):</b> edges at 0.3, 0.6, 1.0, 1.5, 2.5, 3.5, 5.0<br>
            D0 ≤ 0.3, D1 ≤ 0.6, D2 ≤ 1.0, D3 ≤ 1.5, D4 ≤ 2.5, D5 ≤ 3.5, D6 ≤ 5.0, D7 &gt; 5.0<br><br>
            <b>Coil margin (C-bin):</b> margin = <span class="kbd">coilTemp − MinCoilTempLearning</span><br>
            C-3 ≤ −2.0, C-2 ≤ −1.0, C-1 ≤ −0.3, C0 &lt; 0.3, C1 &lt; 1.0, C2 &lt; 2.0, C3 ≥ 2.0<br><br>
            <b>Trend (T-bin):</b> Δcoil = <span class="kbd">coil − prevCoil</span> → T−1 &lt; −0.2, T0 ∈ [−0.2,+0.2], T+1 &gt; +0.2.
        </div>
        </details>';

        // ---------- Table ----------
        $html .= '<div class="qt-wrap"><table class="qtbl"><thead><tr><th class="state">State</th>';
        foreach ($actions as $a) {
            $html .= '<th>'.htmlspecialchars($a).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($q as $sKey => $sa) {
            if (!is_array($sa)) $sa = [];
            $rowLabel = $labels[$sKey] ?? $sKey;
            $title = ($rowLabel === $sKey) ? ' title="State key: '.$sKey.'"' : ' title="State key: '.$sKey.'"';
            $html .= '<tr><td class="state"'.$title.'>'.htmlspecialchars($rowLabel).'</td>';

            foreach ($actions as $a) {
                $val = isset($sa[$a]) ? (float)$sa[$a] : 0.0;

                // heat color
                $color = '#f0f0f0';
                if ($maxQ != $minQ) {
                    if ($val >= 0) {
                        $p = ($maxQ > 0) ? ($val / $maxQ) : 0.0; // 0..1
                        $shade = (int)round(230 - 110 * $p);     // 230→120
                        $color = sprintf('#%02x%02x%02x', $shade, 255, $shade);
                    } else {
                        $p = ($minQ < 0) ? ($val / $minQ) : 0.0; // 0..1 (since val and minQ are negative)
                        $shade = (int)round(230 - 140 * $p);     // 230→90
                        $color = sprintf('#%02x%02x%02x', 255, $shade, $shade);
                    }
                }

                $html .= '<td class="num" style="background:'.$color.'" title="'.htmlspecialchars(sprintf('%.5f', $val)).'">'
                    . number_format($val, 2)
                    . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }




    /* ---------- Bucketing helpers + readable state key (ADD) ---------- */

    private function binN(int $n): int {
        if ($n <= 0) return 0;
        if ($n == 1) return 1;
        if ($n == 2) return 2;
        if ($n == 3) return 3;
        return 4; // 4+
    }

    private function binD(float $d): int {
        // ΔT (°C) edges tuned for HVAC response
        $edges = [0.3, 0.6, 1.0, 1.5, 2.5, 3.5, 5.0];
        foreach ($edges as $i => $e) {
            if ($d <= $e) return $i;      // 0..6
        }
        return count($edges);             // 7+
    }

    private function binC(?float $coil, float $minL): int {
        if (!is_numeric($coil)) return 0; // unknown → neutral
        $m = $coil - $minL;               // margin to learning min
        if ($m <= -2.0) return -3;
        if ($m <= -1.0) return -2;
        if ($m <= -0.3) return -1;
        if ($m <   0.3) return  0;
        if ($m <   1.0) return  1;
        if ($m <   2.0) return  2;
        return 3;
    }

    private function binT(?float $coil, ?float $prevCoil): int {
        if (!is_numeric($coil) || !is_numeric($prevCoil)) return 0;
        $d = $coil - $prevCoil;
        if ($d < -0.2) return -1;
        if ($d >  0.2) return  1;
        return 0;
    }

    /** New readable state key: N|D|C|T (no window bin) */
    private function stateKeyBuckets(array $raw, ?float $prevCoil = null): string {
        $n = $this->binN((int)($raw['numActiveRooms'] ?? 0));
        $d = $this->binD((float)($raw['maxDelta'] ?? 0.0));
        $c = $this->binC(($raw['coilTemp'] ?? null), (float)$this->ReadPropertyFloat('MinCoilTempLearning'));
        $t = $this->binT(($raw['coilTemp'] ?? null), $prevCoil);
        return "N{$n}|D{$d}|C{$c}|T{$t}";
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
