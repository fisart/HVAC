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

            if ($this->ReadPropertyBoolean('ManualOverride')) {
                $this->log(2, 'manual_override_active');
                return;
            }
            if (!$this->isTruthyVar($this->ReadPropertyInteger('ACActiveLink'))) {
                $this->applyAction(0, 0);  // ensure outputs off when AC inactive
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

            if (!$this->coilProtectionOk()) {
                $this->applyAction(0, 0);
                return;
            }

            // Build state and compute metrics for reward shaping
            $state   = $this->buildStateVector();
            $label = $this->formatStateLabel($state);
            $this->rememberStateLabel($this->stateKey($state), $label);
            $metrics = $this->computeRoomMetrics();

            // Transition update: (prev state,action) -> current state
            $prev = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
            if (is_array($prev) && isset($prev['stateKey'], $prev['action'])) {
                $rewardPrev = $this->calculateReward($state, $this->pairToArray($prev['action']), $metrics, $prev);
                $this->qlearnUpdateTransition($prev['stateKey'], $prev['action'], $rewardPrev, $this->stateKey($state));
            }

            // Select next action; avoid 0:0 when demand exists
            $allowedKeys = array_keys($this->getAllowedActionPairs());
            $hasDemand = ($state['numActiveRooms'] ?? 0) > 0 || $hasZdm;
            if ($hasDemand) {
                $allowedKeys = array_values(array_filter($allowedKeys, fn($k) => $k !== '0:0'));
            }
            [$p, $f] = $this->selectActionEpsilonGreedy($state, $allowedKeys);
            [$p, $f] = $this->limitDeltas($p, $f);

            // Apply action
            $this->applyAction($p, $f);

            // Epsilon, persist, diag
            $this->annealEpsilon();
            $this->persistQTableIfNeeded();
            $this->UpdateVisualization();

            // Stash meta for next transition
            $this->SetBuffer('MetaData', json_encode([
                'stateKey' => $this->stateKey($state),
                'action'   => $p . ':' . $f,
                'wad'      => $metrics['rawWAD'] ?? 0.0,
                'coil'     => $metrics['coilTemp'],
                'ts'       => time()
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        } finally {
            IPS_SemaphoreLeave('ADHVAC_' . $this->InstanceID);
        }
    }

    // -------------------- Orchestrator API --------------------

    public function ForceActionAndLearn(string $pair): string
    {
        // Demand & safety
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

        // Transition update first
        $state   = $this->buildStateVector();
        $label = $this->formatStateLabel($state);
        $this->rememberStateLabel($this->stateKey($state), $label);
        $metrics = $this->computeRoomMetrics();
        $prev    = json_decode($this->GetBuffer('MetaData') ?: '[]', true);
        if (is_array($prev) && isset($prev['stateKey'], $prev['action'])) {
            $rewardPrev = $this->calculateReward($state, $this->pairToArray($prev['action']), $metrics, $prev);
            $this->qlearnUpdateTransition($prev['stateKey'], $prev['action'], $rewardPrev, $this->stateKey($state));
        }

        // Apply forced action, persist, diag
        $this->applyAction($p, $f);
        $this->WriteAttributeString('LastAction', $p.':'.$f);
        $this->persistQTableIfNeeded();
        $this->UpdateVisualization();

        // Seed next transition
        $this->SetBuffer('MetaData', json_encode([
            'stateKey' => $this->stateKey($state),
            'action'   => $p . ':' . $f,
            'wad'      => $metrics['rawWAD'] ?? 0.0,
            'coil'     => $metrics['coilTemp'],
            'ts'       => time()
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return json_encode(['ok'=>true, 'applied'=>['p'=>$p,'f'=>$f]]);
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
    private function formatStateLabel(array $s): string
    {
        $n = (int)($s['numActiveRooms'] ?? 0);
        $d = is_numeric($s['maxDelta'] ?? null) ? number_format((float)$s['maxDelta'], 1) : 'n/a';
        $w = (int)($s['anyWindowOpen'] ?? 0);
        $c = ($s['coilTemp'] === null) ? 'n/a' : number_format((float)$s['coilTemp'], 1).'°C';
        return "N={$n} | Δ={$d} | W={$w} | Coil={$c}";
    }

    private function rememberStateLabel(string $sKey, string $label): void
    {
        $raw = $this->ReadAttributeString('StateLabels') ?: '{}';
        $map = json_decode($raw, true);
        if (!is_array($map)) $map = [];
        $map[$sKey] = $label;

        // prune if too large (preserve insertion order)
        if (count($map) > 300) {
            $map = array_slice($map, -200, null, true);
        }
        $this->WriteAttributeString('StateLabels',
            json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
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
        $window  = -0.5 * (int)($state['anyWindowOpen'] ?? 0);

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

        return (float)round($comfort + $energy + $window + $penalty + $progress + $freeze, 4);
    }

    private function bestActionForState(array $state, array $allowedKeys): string
    {
        $q = $this->loadQTable();
        $sKey = $this->stateKey($state);

        $bestVal = -INF;
        $cands = [];
        foreach ($allowedKeys as $k) {
            $val = $q[$sKey][$k] ?? 0.0;
            if ($val > $bestVal) { $bestVal = $val; $cands = [$k]; }
            elseif (abs($val - $bestVal) < 1e-9) { $cands[] = $k; }
        }
        if (count($cands) > 1) {
            // Prefer higher p+f to avoid 0:0 bias, then random among top2
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
        if (!is_array($q) || empty($q)) return '<p>Q-Table is empty.</p>';

        ksort($q);
        $actions = array_keys($this->getAllowedActionPairs());

        $html = '<style>body{font-family:sans-serif;font-size:12px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px;text-align:center}th{background:#f2f2f2;position:sticky;top:0}td.state{text-align:left;font-weight:bold;background:#f8f8f8;position:sticky;left:0}</style>';
        $html .= '<table><thead><tr><th class="state">State</th>';
        foreach ($actions as $a) $html .= '<th>'.htmlspecialchars($a).'</th>';
        $html .= '</tr></thead><tbody>';

        $minQ = 0; $maxQ = 0;
        foreach ($q as $sa) if (is_array($sa)) foreach ($sa as $v) { $minQ = min($minQ,$v); $maxQ = max($maxQ,$v); }

        foreach ($q as $s => $sa) {
            $labels = json_decode($this->ReadAttributeString('StateLabels') ?: '{}', true);
            $rowLabel = is_array($labels) && isset($labels[$s]) ? $labels[$s] : $s;
            $html .= '<tr><td class="state">'.htmlspecialchars($rowLabel).'</td>';

            foreach ($actions as $a) {
                $val = $sa[$a] ?? 0.0;
                $color = '#f0f0f0';
                if ($maxQ != $minQ) {
                    if ($val >= 0) { $p = ($maxQ>0)?($val/$maxQ):0; $color = sprintf('#%02x%02x%02x', 255-(int)(100*$p),255,255-(int)(100*$p)); }
                    else { $p = ($minQ<0)?($val/$minQ):0; $color = sprintf('#%02x%02x%02x',255,255-(int)(150*$p),255-(int)(150*$p)); }
                }
                $html .= '<td style="background:'.$color.'">'.number_format($val,2).'</td>';
            }
            $html .= '</tr>';
        }
        return $html.'</tbody></table>';
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
