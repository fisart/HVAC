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

        $this->RegisterPropertyInteger('MaxPowerDelta', 40);
        $this->RegisterPropertyInteger('MaxFanDelta', 40);

        $this->RegisterPropertyInteger('PowerOutputLink', 0); // kept for legacy; not used directly
        $this->RegisterPropertyInteger('FanOutputLink', 0);   // kept for legacy; not used directly
        $this->RegisterPropertyInteger('TimerInterval', 60);

        // Actions/Granularity
        $this->RegisterPropertyString('CustomPowerLevels', '0,40,80,100');
        $this->RegisterPropertyInteger('PowerStep', 20);
        $this->RegisterPropertyString('CustomFanSpeeds', '0,40,80,100');
        $this->RegisterPropertyInteger('FanStep', 20);

        // Compressor and Fan non linearities
        $this->RegisterPropertyFloat('CompAlpha', 2.4);   // compressor nonlinearity p^alpha (1.3–1.6)
        $this->RegisterPropertyFloat('FanBeta',  1.2);   // fan nonlinearity (rank^beta)
        $this->RegisterPropertyFloat('FanWeight',0.25);  // relative weight of fan vs compressor

        // Sensors
        $this->RegisterAttributeFloat('CoilNoisePerMin', 0.0); // from calibration (e.g. 0.00825)
        $this->RegisterAttributeInteger('LastTrendBin', 0);    // -1/0/+1 hysteresis memory

        // Weights
        $this->RegisterPropertyFloat('W_Comfort', 1.0);
        $this->RegisterPropertyFloat('W_Energy', 0.01);
        $this->RegisterPropertyFloat('W_ChangePenalty', 0.002);
        $this->RegisterPropertyFloat('W_Progress', 10.0);
        $this->RegisterPropertyFloat('W_Freeze', 2.0);
        $this->RegisterPropertyFloat('W_Trend', 2.0);
        $this->RegisterPropertyFloat('TrendEps', 0.10);
        $this->RegisterPropertyFloat('W_Window', 0.0); // keep 0.0 to disable


        // Epsilon
        $this->RegisterPropertyFloat('EpsilonStart', 0.40);
        $this->RegisterPropertyFloat('EpsilonMin',   0.05);
        $this->RegisterPropertyFloat('EpsilonDecay', 0.995);

        // Coil-Safety
        $this->RegisterPropertyFloat('MinCoilTempLearning', 2.0);     // °C
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
                'FanOutputLink'   => $this->ReadPropertyInteger('FanOutputLink')
            ]);
           // Respect ZDM hard emergency immediately (do not send 0:0)
            $agg0 = $this->fetchZDMAggregates();
            if (is_array($agg0) && !empty($agg0['emergencyActive'])) {
                $this->log(1, 'skip_tick_emergency_from_zdm', ['coil'=>$agg0['coilTemp'] ?? null]);
                return;
            }

            // Manual override disables learning/action
            if ($this->ReadPropertyBoolean('ManualOverride')) {
                $this->log(2, 'manual_override_active');
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
            $this->log(3, 'state_N_from_ZDM', ['N' => (int)($state['numActiveRooms'] ?? -1)]);

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
                'stateKey' => $sKeyNew,              // << use bucketed key here
                'action'   => $p . ':' . $f,
                'wad'      => $metrics['rawWAD'] ?? 0.0,
                'coil'    => ($metrics['coilTemp'] ?? null),
                'maxDelta' => $state['maxDelta'],
                'ts'       => time()
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        } finally {
            IPS_SemaphoreLeave('ADHVAC_' . $this->InstanceID);
        }
    }
  

    // -------------------- Orchestrator API --------------------
    public function ForceActionAndLearn(string $pair): string
    {
        // Block forced actions during ZDM emergency to preserve emergency fan
        $agg0 = $this->fetchZDMAggregates();
        if (is_array($agg0) && !empty($agg0['emergencyActive'])) {
            $this->log(1, 'force_blocked_emergency_from_zdm', [
                'pair' => $pair,
                'coil' => $agg0['coilTemp'] ?? null
            ]);
            return json_encode(['ok' => false, 'err' => 'emergency_active']);
        }
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
        $this->log(3, 'state_N_from_ZDM', ['N' => (int)($state['numActiveRooms'] ?? -1)]);

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
            'stateKey' => $sKeyNew,              // << use bucketed key here
            'action'   => $p . ':' . $f,
            'wad'      => $metrics['rawWAD'] ?? 0.0,
            'coil'     => ($metrics['coilTemp'] ?? null),
            'maxDelta' => $state['maxDelta'],
            'ts'       => time()
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

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
    private function fanStepToNorm(int $f): float
    {
        // Map your discrete steps to 0..1 by rank (10<30<50<70<90).
        $steps = [10, 30, 50, 70, 90];
        $i = array_search($f, $steps, true);
        if ($i === false) {
            // If a non-standard value slips through, place it into the order
            $steps[] = $f; sort($steps, SORT_NUMERIC);
            $i = array_search($f, $steps, true);
        }
        $n = count($steps) - 1;
        return $n > 0 ? max(0.0, min(1.0, $i / $n)) : 0.0;
    }

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
        $agg = $this->fetchZDMAggregates();

        $numActive = is_array($agg) ? (int)($agg['numActiveRooms'] ?? 0) : 0;
        $maxDelta  = is_array($agg) ? (float)($agg['maxDeltaT'] ?? 0.0)   : 0.0;
        $coil      = (is_array($agg) && is_numeric($agg['coilTemp'] ?? null))
                        ? (float)$agg['coilTemp'] : null;

        return [
            'numActiveRooms' => $numActive,
            'maxDelta'       => round($maxDelta, 2),
            'coilTemp'       => is_numeric($coil) ? round($coil, 2) : null
        ];
    }




private function computeRoomMetrics(): array
    {
        // Prefer ZDM aggregates (SSOT). Fall back safely if fields are missing.
        $agg = $this->fetchZDMAggregates();

        $rawWAD   = is_array($agg) && array_key_exists('rawWAD', $agg)       ? (float)$agg['rawWAD']       : 0.0;
        $hotRooms = is_array($agg) && array_key_exists('hotRooms', $agg)     ? (int)$agg['hotRooms']       : (int)($agg['numActiveRooms'] ?? 0);
        $maxDev   = is_array($agg) && array_key_exists('maxDev', $agg)       ? (float)$agg['maxDev']       : (float)($agg['maxDeltaT'] ?? 0.0);
        $D_cold   = is_array($agg) && array_key_exists('D_cold', $agg)       ? (float)$agg['D_cold']       : 0.0;
        $coilAgg  = is_array($agg) && array_key_exists('coilTemp', $agg)     ? $agg['coilTemp']            : null;

        
        $agg = $this->fetchZDMAggregates();
        $coil = (is_array($agg) && is_numeric($agg['coilTemp'] ?? null)) ? (float)$agg['coilTemp'] : null;
        return [
        'rawWAD'   => (float)($agg['rawWAD']  ?? 0.0),
        'hotRooms' => (int)  ($agg['hotRooms']?? 0),
        'maxDev'   => (float)($agg['maxDev']  ?? 0.0),
        'D_cold'   => (float)($agg['D_cold']  ?? 0.0),
        'coilTemp' => $coil
        ];

    }


    private function calculateReward(array $state, array $action, ?array $metrics = null, ?array $prevMeta = null): float
    {
        // Weights (from form.json)
        $wComfort  = (float)$this->ReadPropertyFloat('W_Comfort');
        $wEnergy   = (float)$this->ReadPropertyFloat('W_Energy');
        $wChange   = (float)$this->ReadPropertyFloat('W_ChangePenalty');
        $wProgress = (float)$this->ReadPropertyFloat('W_Progress');
        $wFreeze   = (float)$this->ReadPropertyFloat('W_Freeze');
        $wTrend    = (float)$this->ReadPropertyFloat('W_Trend');
        $epsTrend  = (float)$this->ReadPropertyFloat('TrendEps');   // deadband in °C/min
        $wWindow   = (float)$this->ReadPropertyFloat('W_Window');

        // Minutes since previous step (normalize all "per-time" effects)
        $dtm = $this->getStepMinutes(); // e.g., 2.0 for 2 min

        // --- Comfort (per-minute penalty for current max ΔT) ---
        $comfort = -$wComfort * (float)($state['maxDelta'] ?? 0.0) * $dtm;

        // --- Non-linear energy proxy (per-minute) ---
        // Compressor share grows super-linearly; fan uses discrete rank (10/30/50/70/90 → 0..1)
        $pn    = max(0.0, min(1.0, ((float)($action['p'] ?? 0)) / 100.0));
        $fn    = $this->fanStepToNorm((int)($action['f'] ?? 0));
        $alpha = (float)$this->ReadPropertyFloat('CompAlpha'); // e.g., 2.2–2.6
        $beta  = (float)$this->ReadPropertyFloat('FanBeta');   // e.g., 1.2
        $wf    = (float)$this->ReadPropertyFloat('FanWeight'); // e.g., 0.25

        $energyPct = pow($pn, $alpha) + $wf * pow($fn, $beta);
        $energy    = -$wEnergy * $energyPct * $dtm;

        // --- Window penalty (per-minute if any window is open) ---
        $windowPenalty = 0.0;
        if ($wWindow > 0 && !empty($state['anyWindowOpen'])) {
            $windowPenalty = -$wWindow * $dtm;
        }

        // --- Change penalty (per step; discourage large jumps) ---
        $penalty = 0.0;
        if (preg_match('/^(\d+):(\d+)$/', $this->ReadAttributeString('LastAction'), $m)) {
            $dp = abs(((int)$m[1]) - (int)($action['p'] ?? 0));
            $df = abs(((int)$m[2]) - (int)($action['f'] ?? 0));
            $penalty = -$wChange * ($dp + $df);
        }

        // --- Progress shaping (delta of WAD; already a difference → no time scaling) ---
        $progress = 0.0;
        if ($metrics && $prevMeta && isset($prevMeta['wad'])) {
            $progress = $wProgress * (((float)$prevMeta['wad']) - ((float)($metrics['rawWAD'] ?? 0.0)));
        }

        // --- Freeze risk (per-minute penalty below min learning coil temp) ---
        $freeze = 0.0;
        if ($metrics) {
            $minL = (float)$this->ReadPropertyFloat('MinCoilTempLearning');
            $coil = $metrics['coilTemp'];
            if (is_numeric($coil) && $coil < $minL) {
                $freeze = -$wFreeze * ($minL - (float)$coil) * $dtm;
            }
        }

        // --- Comfort trend (compare rate in °C/min against deadband, then scale back by minutes) ---
        $trend = 0.0;
        if ($prevMeta && isset($prevMeta['maxDelta']) && isset($state['maxDelta'])) {
            $delta = ((float)$prevMeta['maxDelta']) - ((float)$state['maxDelta']);  // >0 = improving
            $rate  = $delta / max(0.001, $dtm);                                     // °C per minute
            if (abs($rate) > $epsTrend) {
                $trend = $wTrend * $rate * $dtm; // equals wTrend * delta, with per-minute deadband
            }
        }

        // Sum and clamp (keep updates stable)
        $r = $comfort + $energy + $windowPenalty + $penalty + $progress + $freeze + $trend;
        $r = max(-1.5, min($r, 0.25));

        // Debug (optional): visible only at LogLevel>=3
        $this->log(3, 'r_parts', [
            'dtm'      => $dtm,
            'Δ'        => $state['maxDelta'] ?? null,
            'p'        => $action['p'] ?? null,
            'f'        => $action['f'] ?? null,
            'comfort'  => $comfort,
            'energy'   => $energy,
            'penalty'  => $penalty,
            'progress' => $progress,
            'freeze'   => $freeze,
            'trend'    => $trend,
            'wadPrev'  => $prevMeta['wad']        ?? null,   // << add
            'wadNow'   => $metrics['rawWAD']      ?? null,   // << add
            'sum'      => $comfort+$energy+$penalty+$progress+$freeze+$trend
        ]);


        return (float)round($r, 4);
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
        // Build the SAME bucketed key used for updates
        $prevMeta = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
        $prevCoil = (is_array($prevMeta) && isset($prevMeta['coil']))
            ? $prevMeta['coil']
            : ($state['coilTemp'] ?? null);

        $sKey = $this->stateKeyBuckets($state, $prevCoil);

        $q = $this->loadQTable();

        $bestVal = -INF;
        $cands = [];
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
        // 1) Emergency cutoff from ZDM → hard stop
        $agg = $this->fetchZDMAggregates();
        if (is_array($agg) && !empty($agg['emergencyActive'])) {
            $this->log(1, 'coil_emergency_from_zdm', ['coil' => $agg['coilTemp'] ?? null]);
            return false;
        }

        // 2) Optional learning freeze protection (soft gate) based on ZDM coil
        if (!$this->ReadPropertyBoolean('AbortOnCoilFreeze')) {
            return true;
        }

        $coil = (is_array($agg) && is_numeric($agg['coilTemp'] ?? null)) ? (float)$agg['coilTemp'] : null;
        if (!is_numeric($coil)) {
            // No coil info available → don't block learning
            return true;
        }

        $minLearning = (float)$this->ReadPropertyFloat('MinCoilTempLearning');
        if ($coil <= $minLearning) {
            $this->log(1, 'coil_below_learning_min', ['coil' => $coil, 'min' => $minLearning]);
            return false;
        }

        // 3) Drop-rate watchdog (uses ZDM coil)
        $now  = time();
        $key  = 'coil_last';
        $last = $this->GetBuffer($key);
        if ($last) {
            $obj = json_decode($last, true);
            if (isset($obj['t'], $obj['v']) && is_numeric($obj['t']) && is_numeric($obj['v'])) {
                $dt   = max(1, $now - (int)$obj['t']);
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

        // Labels map (hash → label)
        $labels = json_decode($this->ReadAttributeString('StateLabels') ?: '{}', true);
        if (!is_array($labels)) $labels = [];

        // Heatmap range
        $minQ = 0.0; $maxQ = 0.0;
        foreach ($q as $sa) {
            if (!is_array($sa)) continue;
            foreach ($sa as $v) { $v = (float)$v; $minQ = min($minQ, $v); $maxQ = max($maxQ, $v); }
        }

        // Read weights for the header
        $w = [
            'Comfort (ΔT)'        => (float)$this->ReadPropertyFloat('W_Comfort'),
            'Energy per %'        => (float)$this->ReadPropertyFloat('W_Energy'),
            'Change per % step'   => (float)$this->ReadPropertyFloat('W_ChangePenalty'),
            'Progress (WAD)'      => (float)$this->ReadPropertyFloat('W_Progress'),
            'Freeze risk'         => (float)$this->ReadPropertyFloat('W_Freeze'),
            'Trend (Δ maxΔT)'     => (float)$this->ReadPropertyFloat('W_Trend'),
            'Trend deadband (°C)' => (float)$this->ReadPropertyFloat('TrendEps'),
            'Window penalty'      => (float)$this->ReadPropertyFloat('W_Window'),
        ];

        // Bucket info (for the collapsible “Bucket definitions”)
        $deltaEdges = [0.3, 0.6, 1.0, 1.5, 2.5, 3.5, 5.0]; // must match binD()
        $minL = (float)$this->ReadPropertyFloat('MinCoilTempLearning');  // used by binC()

        // >>> Adjustable width for the "State" column (in px)
        $stateColWidth = 220;

        // Basic styles (kept as before; only width added for the state column)
        $html = '<style>
        .qtbl{border-collapse:collapse;min-width:100%;width:max-content;table-layout:fixed}
        .qtbl th,.qtbl td{border:1px solid #ccc;padding:6px 8px;text-align:center;font:500 13px/1.3 system-ui,Segoe UI,Roboto,sans-serif}
        .qtbl th{background:#f2f2f2;position:sticky;top:0;z-index:1}
        .qtbl td.state,.qtbl th.state{font-weight:600;text-align:left;background:#fafafa;position:sticky;left:0;z-index:1;white-space:nowrap;width:'.$stateColWidth.'px;min-width:'.$stateColWidth.'px;max-width:'.$stateColWidth.'px}
        .legend{font:700 15px/1.4 system-ui,Segoe UI,Roboto,sans-serif;margin:10px 6px 6px}
        .help{font:500 13px/1.5 system-ui,Segoe UI,Roboto,sans-serif;margin:0 6px 10px;color:#333}
        details{margin:6px 6px 10px}
        details > summary{cursor:pointer;font:600 13px/1.4 system-ui,Segoe UI,Roboto,sans-serif}
        .weights{font:500 13px/1.4 system-ui,Segoe UI,Roboto,sans-serif}
        .weights-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin:8px 0 2px}
        .chip{border:1px solid #ddd;border-radius:8px;padding:6px 8px;background:#fff}
        .chip b{display:block;font-weight:700;margin-bottom:2px}
        /* NEW: scroll wrapper to enable vertical scrolling when tall */
        .qtbl-wrap{max-height:70vh;overflow:auto;border:1px solid #ddd;margin:6px 0}
        </style>';

        // Explanation (unchanged) + NEW: live reward weights
        $html .= '<div class="legend">How to read this table</div>
        <div class="help">
        Each row is a <b>state</b>, each column is an <b>action</b> (<code>Power:Fan</code> in %).<br>
        Cells show the learned Q-value (higher is better). Colors highlight negatives for quick scanning.<br><br>
        <b>State label format:</b> <code>N=… | Δ=… | Coil=…</code><br>
        • <b>N</b>: number of active rooms with cooling demand.<br>
        • <b>Δ</b>: maximum temperature overshoot above the setpoint (rounded).<br>
        • <b>Coil</b>: current coil temperature (rounded).
        </div>';

        // NEW: a collapsible block with the current reward weights
        $html .= '<details class="weights" open>
            <summary>Current reward weights</summary>
            <div class="weights-grid">';
        foreach ($w as $label => $val) {
            $fmt = (abs($val) < 0.1) ? number_format($val, 4) : number_format($val, 2);
            $html .= '<div class="chip"><b>'.htmlspecialchars($label).'</b>'.$fmt.'</div>';
        }
        $html .= '  </div>
        </details>';

        // Collapsible bucket definitions (unchanged idea)
        $html .= '<details>
        <summary>Bucket definitions</summary>
        <div class="help">
            <b>Δ (comfort) buckets:</b> ';
        $ranges = [];
        $prev = 0.0;
        foreach ($deltaEdges as $i => $edge) {
            $ranges[] = sprintf('%d: %.1f–%.1f°C', $i, $prev, $edge);
            $prev = $edge;
        }
        $ranges[] = sprintf('%d: &gt;%.1f°C', count($deltaEdges), end($deltaEdges));
        $html .= implode(' &nbsp;|&nbsp; ', $ranges);
        $html .= '<br><b>Coil buckets (margin to MinLearning='.$minL.'°C):</b> 
        -3: ≤ -2.0, -2: (-2.0..-1.0], -1: (-1.0..-0.3], 0: (-0.3..+0.3), 
        1: (0.3..1.0), 2: (1.0..2.0), 3: &gt; 2.0<br>
        <b>Trend:</b> -1 falling (&lt;-0.2°C), 0 flat (±0.2°C), 1 rising (&gt;+0.2°C)
        </div>
        </details>';

        // Build table (2 decimals as agreed)
        $html .= '<div class="qtbl-wrap"><table class="qtbl"><thead><tr><th class="state">State</th>';
        foreach ($actions as $a) $html .= '<th>'.htmlspecialchars($a).'</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($q as $sKey => $sa) {
            $rowLabel = $labels[$sKey] ?? $sKey; // show label if known; fallback to key/hash
            $html .= '<tr><td class="state">'.htmlspecialchars($rowLabel).'</td>';

            foreach ($actions as $a) {
                $val = isset($sa[$a]) ? (float)$sa[$a] : 0.0;
                // heatmap color (light green for ≥0, light red for <0)
                $color = '#f0f0f0';
                if ($maxQ != $minQ) {
                    if ($val >= 0) {
                        $p = ($maxQ > 0) ? ($val / $maxQ) : 0.0;
                        $shade = (int)round(230 - 110 * $p); // 230→120
                        $color = sprintf('#%02x%02x%02x', $shade, 255, $shade);
                    } else {
                        $p = ($minQ < 0) ? ($val / $minQ) : 0.0; // val/minQ in [0..1]
                        $shade = (int)round(230 - 140 * $p); // 230→90
                        $color = sprintf('#%02x%02x%02x', 255, $shade, $shade);
                    }
                }
                $html .= '<td style="background:'.$color.'">'.number_format($val, 2).'</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }



    /* ---------- Bucketing helpers + readable state key (ADD) ---------- */
    private function getNFromZDM(): int
    {
        $zdmID = (int)$this->ReadPropertyInteger('ZDM_InstanceID');
        if ($zdmID <= 0) return -1;

        // Prefer array variant if available
        if (function_exists('ZDM_GetEffectiveDemandArray')) {
            $agg = @ZDM_GetEffectiveDemandArray($zdmID);
        } else {
            if (!function_exists('ZDM_GetEffectiveDemand')) return -1;
            $j = @ZDM_GetEffectiveDemand($zdmID);
            $agg = is_string($j) ? json_decode($j, true) : null;
        }

        if (is_array($agg) && isset($agg['N'])) {
            return (int)$agg['N'];
        }
        return -1;
    }


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

    private function getStepMinutes(): float
    {
        $now  = time();
        $prev = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
        $last = (is_array($prev) && isset($prev['ts'])) ? (int)$prev['ts'] : ($now - max(60, (int)$this->ReadPropertyInteger('TimerInterval')));
        return max(1, $now - $last) / 60.0;
    }

    private function binT(?float $coil, ?float $prevCoil): int
    {
        if (!is_numeric($coil) || !is_numeric($prevCoil)) return 0;

        // slope in °C per minute (no rounding)
        $dtm   = $this->getStepMinutes();
        if ($dtm <= 0) return 0;
        $slope = ((float)$coil - (float)$prevCoil) / $dtm;

        // threshold per minute = max(base, 3σ_noise)
        $base  = (float)$this->ReadPropertyFloat('TrendEps');               // interpret as °C/min
        $noise = (float)$this->ReadAttributeFloat('CoilNoisePerMin');       // set via calibration
        $thr   = max($base, 3.0 * $noise);

        // Schmitt hysteresis to prevent chatter
        $lastT = (int)$this->ReadAttributeInteger('LastTrendBin');          // -1/0/+1
        $hyst  = 0.5 * $thr;
        $up    =  $thr - ($lastT ===  1 ? $hyst : 0.0);
        $down  = -$thr + ($lastT === -1 ? $hyst : 0.0);

        $t = $lastT;
        if     ($slope >= $up)   $t =  1;
        elseif ($slope <= $down) $t = -1;

        if ($t !== $lastT) $this->WriteAttributeInteger('LastTrendBin', $t);
        return $t;
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
