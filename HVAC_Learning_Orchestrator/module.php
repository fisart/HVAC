<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       2.41 (Status model + robust logging + flapMap fix)
 * @date          2025-08-08
 */

class HVAC_Learning_Orchestrator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('ZoningManagerID', 0);
        $this->RegisterPropertyInteger('AdaptiveControlID', 0);
        $this->RegisterPropertyString('CalibrationPlan', '[]');

        $this->RegisterAttributeString('CalibrationStatus', 'Idle'); // Idle|Running|Done
        $this->RegisterAttributeInteger('CurrentStageIndex', 0);
        $this->RegisterAttributeInteger('CurrentActionIndex', 0);
        $this->RegisterAttributeString('OriginalTargetTemps', '{}');

        $this->RegisterVariableString('CalibrationPlanHTML', 'Calibration Plan (HTML)', '~HTMLBox', 10);

        // Timer (calls public wrapper with module prefix ORCH_)
        $this->RegisterTimer('CalibrationTimer', 0, 'ORCH_RunNextStep($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            // Kernel not ready → do nothing (don’t spam logs here)
            return;
        }

        $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
        $aid = (int)$this->ReadPropertyInteger('AdaptiveControlID');

        // Only 202 (red) when links are actually missing.
        if ($zid === 0 || $aid === 0) {
            $this->LogMessage("ORCH status=202 (missing link): ZDM_ID={$zid}, AC_ID={$aid}", KL_ERROR);
            $this->SetStatus(202);
            return;
        }

        // When links exist, always stay green (even if Running/Done)
        $cal = $this->ReadAttributeString('CalibrationStatus');
        $this->LogMessage("ORCH status=102 (links ok) CalibrationStatus={$cal}", KL_MESSAGE);
        $this->SetStatus(102);
        $this->uiSetCalStatus($this->ReadAttributeString('CalibrationStatus') ?: 'Idle');

        // Optional: sanity checks for callable APIs (warn only)
        $hasZDM = function_exists('ZDM_SetOverrideMode') && function_exists('ZDM_CommandSystem')
               && function_exists('ZDM_CommandFlaps') && function_exists('ZDM_GetRoomConfigurations');
        if (!$hasZDM) {
            $this->LogMessage('ORCH warning: ZDM_* API functions not available (check module prefix or reload modules)', KL_WARNING);
        }
        $hasAC = function_exists('ACIPS_SetMode') && function_exists('ACIPS_ResetLearning')
              && function_exists('ACIPS_ForceActionAndLearn') && function_exists('ACIPS_GetActionPairs');
        if (!$hasAC) {
            $this->LogMessage('ORCH warning: ACIPS_* API functions not available (check module prefix or reload modules)', KL_WARNING);
        }
    }

    public function GetConfigurationForm()
    {
        return @file_get_contents(__DIR__ . '/form.json') ?: '{}';
    }

    // ===== UI Actions =====

    public function ProposePlan()
    {
        $zoningID   = (int)$this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = (int)$this->ReadPropertyInteger('AdaptiveControlID');

        if ($zoningID === 0 || $adaptiveID === 0) {
            $this->LogMessage('ORCH ProposePlan: missing links (status=202)', KL_ERROR);
            echo "Error: Core Module Links must be set first.";
            return;
        }

        $plan = $this->generateProposedPlan($zoningID, $adaptiveID);
        if (!empty($plan)) {
            IPS_SetProperty($this->InstanceID, 'CalibrationPlan', json_encode($plan, JSON_UNESCAPED_UNICODE));
            if (IPS_HasChanges($this->InstanceID)) {
                IPS_ApplyChanges($this->InstanceID);
            }
            $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML($plan));
            $this->LogMessage('ORCH ProposePlan: new plan generated', KL_MESSAGE);
            echo "New calibration plan proposed. The form will now refresh.";
        } else {
            $this->LogMessage('ORCH ProposePlan: failed to generate a valid plan', KL_ERROR);
            $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML([]));
            echo "Error: Failed to generate a valid calibration plan. Check logs.";
        }
    }

    public function StartCalibration()
    {
        $zoningID   = (int)$this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = (int)$this->ReadPropertyInteger('AdaptiveControlID');

        $this->LogMessage("ORCH: StartCalibration triggered. ZDM={$zoningID}, ADAPT={$adaptiveID}", KL_MESSAGE);

        if ($zoningID === 0 || $adaptiveID === 0) {
            $this->LogMessage("ORCH: Missing links – cannot start calibration (ZDM/ADAPT is 0).", KL_ERROR);
            $this->SetStatus(202);
            return;
        }

        // Ensure we have a non-empty plan (generate if needed)
        $plan = $this->getCalibrationPlan();
        if (empty($plan)) {
            $this->LogMessage('ORCH: No plan found – generating a proposed plan now.', KL_WARNING);
            $newPlan = $this->generateProposedPlan($zoningID, $adaptiveID);
            if (!empty($newPlan)) {
                IPS_SetProperty($this->InstanceID, 'CalibrationPlan', json_encode($newPlan, JSON_UNESCAPED_UNICODE));
                if (IPS_HasChanges($this->InstanceID)) {
                    IPS_ApplyChanges($this->InstanceID);
                }
                $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML($newPlan));
                $plan = $this->getCalibrationPlan(); // reload structured
            }
        }

        if (empty($plan)) {
            $this->LogMessage('ORCH: Cannot start – calibration plan is still empty.', KL_ERROR);
            $this->SetStatus(202);
            return;
        }

        // Initialize calibration state
        $this->WriteAttributeString('CalibrationStatus', 'Running');
        $this->WriteAttributeInteger('CurrentStageIndex', 0);
        $this->WriteAttributeInteger('CurrentActionIndex', 0);
        $this->SetStatus(102); // keep green
        $this->LogMessage('--- ORCH: Starting System Calibration ---', KL_MESSAGE);

        // Start timer with ZDM interval
        $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());

        // Save original targets (if available)
        if (method_exists($this, 'saveOriginalTargets')) {
            $this->saveOriginalTargets();
        }

        // Enable ZDM override
        if (function_exists('ZDM_SetOverrideMode')) {
            $this->LogMessage("ORCH: Sending Override=true to ZDM instance {$zoningID}", KL_MESSAGE);
            $overrideValue = true;
            $this->LogMessage("ORCH: DEBUG_ORCH_SEND_OVERRIDE target={$zoningID} value=true", KL_MESSAGE);
            ZDM_SetOverrideMode($zoningID, $overrideValue);

            $vid = @IPS_GetObjectIDByIdent('OverrideActive', $zoningID);
            if ($vid) {
                $val = (bool) @GetValue($vid);
                $this->LogMessage('ORCH: ZDM OverrideActive after StartCalibration = ' . ($val ? 'true' : 'false'), KL_MESSAGE);
            } else {
                $this->LogMessage('ORCH: OverrideActive var not found in ZDM instance (after StartCalibration)', KL_WARNING);
            }
        } else {
            $this->LogMessage('ORCH: ZDM_SetOverrideMode() not available', KL_ERROR);
        }

        // Optional: set ACIPS mode
        if (function_exists('ACIPS_SetMode')) {
            ACIPS_SetMode($adaptiveID, 'orchestrated');
            $this->LogMessage('ORCH: ACIPS_SetMode(orchestrated) requested', KL_MESSAGE);
        } else {
            $this->LogMessage('ORCH: ACIPS_SetMode() not available', KL_WARNING);
        }

        // Kick first step
        $this->RunNextStep();

        if (method_exists($this, 'ReloadForm')) {
            $this->ReloadForm();
        }
    }


   public function StopCalibration()
    {
        // Timer sicher ausschalten
        $this->SetTimerInterval('CalibrationTimer', 0);
        $zoningID   = (int)$this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = (int)$this->ReadPropertyInteger('AdaptiveControlID');

        // Ensure timer is off while stopping
        $this->SetTimerInterval('CalibrationTimer', 0);


        // 1) Override im ZDM ausschalten
        if ($zoningID > 0) {
            if (function_exists('ZDM_SetOverrideMode')) {
                $this->LogMessage("ORCH: Sending Override=false to ZDM instance {$zoningID}", KL_MESSAGE);
                // before calling ZDM_SetOverrideMode(...)
                $overrideValue = false;
                $this->LogMessage("ORCH: DEBUG_ORCH_SEND_OVERRIDE target={$zoningID} value=" . ($overrideValue ? 'true' : 'false'), KL_MESSAGE);
                ZDM_SetOverrideMode($zoningID, $overrideValue);

                // Verifikation: OverrideActive in der ZDM-Instanz prüfen
                $vid = @IPS_GetObjectIDByIdent('OverrideActive', $zoningID);
                if ($vid) {
                    $val = (bool) @GetValue($vid);
                    $this->LogMessage('ORCH: ZDM OverrideActive after StopCalibration = ' . ($val ? 'true' : 'false'), KL_MESSAGE);
                } else {
                    $this->LogMessage('ORCH: OverrideActive var not found in ZDM instance (after StopCalibration)', KL_WARNING);
                }
            } else {
                $this->LogMessage('ORCH: ZDM_SetOverrideMode() not available', KL_ERROR);
            }
        } else {
            $this->LogMessage("ORCH: No ZDM instance linked – cannot set Override=false", KL_WARNING);
        }

        // 2) Adaptive: neutralen Schritt lernen lassen + Modus zurücksetzen
        if ($adaptiveID > 0) {
            if (function_exists('ACIPS_ForceActionAndLearn')) {
                // WICHTIG: 2. Argument ist Pflicht → neutraler Output "0:0"
                $this->LogMessage('ORCH: ACIPS_ForceActionAndLearn("0:0") requested', KL_MESSAGE);
                ACIPS_ForceActionAndLearn($adaptiveID, '0:0');
                // ZDM hart auf 0:0 setzen (unabhängig von Min-Grenzen im Adaptive-Modul)
                if ($zoningID > 0 && function_exists('ZDM_CommandSystem')) {
                    $this->LogMessage('ORCH: Forcing ZDM system output to 0:0', KL_MESSAGE);
                    ZDM_CommandSystem($zoningID, 0, 0);
                }

            } else {
                $this->LogMessage('ORCH StopCalibration: ACIPS_ForceActionAndLearn() not available', KL_ERROR);
            }

            if (function_exists('ACIPS_SetMode')) {
                ACIPS_SetMode($adaptiveID, 'cooperative');
                $this->LogMessage('ORCH: ACIPS_SetMode(cooperative) requested', KL_MESSAGE);
            } else {
                $this->LogMessage('ORCH StopCalibration: ACIPS_SetMode() not available', KL_ERROR);
            }
        } else {
            $this->LogMessage('ORCH: No Adaptive instance linked – skipping AC reset', KL_WARNING);
        }

        // 3) Original-Zieltemperaturen wiederherstellen (falls implementiert)
        if (method_exists($this, 'restoreOriginalTargets')) {
            $this->restoreOriginalTargets();
        }

        // 4) Status / UI
        $this->uiSetCalStatus('Done');
        $this->SetStatus(102); // grün bleiben
        $this->LogMessage('ORCH: Calibration finished (status=Done).', KL_MESSAGE);

        if (method_exists($this, 'ReloadForm')) {
            $this->ReloadForm();
        }
    }



    public function RunNextStep()
    {
        // Only operate while running
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') {
            $this->LogMessage('ORCH RunNextStep: ignored (status not Running)', KL_WARNING);
            return;
        }

        // Load and validate plan
        $plan = $this->getCalibrationPlan();
        if (!is_array($plan) || count($plan) === 0) {
            $this->LogMessage('ORCH RunNextStep: empty or invalid plan → stopping.', KL_ERROR);
            $this->StopCalibration();
            return;
        }

        // Read indices (clamp to sane bounds)
        $stageIdx  = max(0, (int)$this->ReadAttributeInteger('CurrentStageIndex'));
        $actionIdx = max(0, (int)$this->ReadAttributeInteger('CurrentActionIndex'));

        if ($stageIdx >= count($plan)) {
            $this->LogMessage('--- ORCH: Calibration Plan Finished Successfully! ---', KL_MESSAGE);
            $this->StopCalibration();
            return;
        }

        $currentStage = $plan[$stageIdx] ?? null;
        if (!is_array($currentStage)) {
            $this->LogMessage("ORCH RunNextStep: stage {$stageIdx} invalid → skipping to next stage.", KL_WARNING);
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
            $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());
            return;
        }

        $actions = isset($currentStage['actions']) && is_array($currentStage['actions']) ? $currentStage['actions'] : [];
        if (empty($actions)) {
            $this->LogMessage("ORCH RunNextStep: stage {$stageIdx} has no actions → skipping to next stage.", KL_WARNING);
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
            $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());
            return;
        }

        // If we're beyond the last action of this stage, advance to next stage
        if ($actionIdx >= count($actions)) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
            $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());
            return;
        }

        // Stage header logging + setup (only once per stage)
        if ($actionIdx === 0) {
            $stageName = (string)($currentStage['name'] ?? 'Unnamed');
            $this->LogMessage(sprintf('--- ORCH: Starting Stage %d/%d: %s ---',
                $stageIdx + 1, count($plan), $stageName), KL_MESSAGE);

            // Convert flaps to {"Room": bool}
            $flapMap = [];
            foreach (($currentStage['setup']['flaps'] ?? []) as $f) {
                if (isset($f['name'])) {
                    $flapMap[(string)$f['name']] = (bool)($f['open'] ?? false);
                }
            }
            $this->LogMessage('ORCH flapMap → ' . json_encode($flapMap, JSON_UNESCAPED_UNICODE), KL_MESSAGE);

            $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
            if ($zid > 0 && function_exists('ZDM_CommandFlaps')) {
                ZDM_CommandFlaps(
                    $zid,
                    (string)($currentStage['name'] ?? 'calibration'),
                    json_encode($flapMap, JSON_UNESCAPED_UNICODE)
                );
            } else {
                $this->LogMessage('ORCH RunNextStep: ZDM_CommandFlaps() not available or ZDM link missing', KL_ERROR);
            }

            // Artificial targets for active rooms
            $this->setArtificialTargets($currentStage);
            IPS_Sleep(5000); // settle time
        }

        // Execute next action
        $action = (string)($actions[$actionIdx] ?? '');
        if ($action === '' || strpos($action, ':') === false) {
            $this->LogMessage("ORCH RunNextStep: invalid action at {$stageIdx}:{$actionIdx} → skipping.", KL_WARNING);
        } else {
            // Reflect to ZDM outputs (so physical relays follow)
            $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
            if ($zid > 0 && function_exists('ZDM_CommandSystem')) {
                [$p, $f] = array_map('intval', explode(':', $action, 2));
                @ZDM_CommandSystem($zid, $p, $f);
                $this->LogMessage('ORCH: zdm_command_system ' . json_encode(['p'=>$p,'f'=>$f], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), KL_MESSAGE);
            }

            // Learning step in ACIPS
            $adaptiveID = (int)$this->ReadPropertyInteger('AdaptiveControlID');
            if ($adaptiveID > 0 && function_exists('ACIPS_ForceActionAndLearn')) {
                $result = @json_decode(ACIPS_ForceActionAndLearn($adaptiveID, $action), true);
                $this->LogMessage(
                    "ORCH Step {$stageIdx}:{$actionIdx} | Action: {$action} | Reward: " .
                    number_format((float)($result['reward'] ?? 0), 2),
                    KL_DEBUG
                );
            } else {
                $this->LogMessage('ORCH RunNextStep: ACIPS_ForceActionAndLearn() not available or Adaptive link missing', KL_ERROR);
            }
        }

        // Advance indices
        $actionIdx++;
        if ($actionIdx >= count($actions)) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
        } else {
            $this->WriteAttributeInteger('CurrentActionIndex', $actionIdx);
        }

        // Schedule next tick using ZDM interval
        $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());
    }


    private function uiSetCalStatus(string $s): void
    {
        $this->WriteAttributeString('CalibrationStatus', $s);
        try {
            $this->UpdateFormField('CalStatusLabel', 'label', 'Calibration: ' . $s);
        } catch (\Throwable $e) {
            // Form might be closed; ignore
        }
    }
    private function getZDMProcessingInterval(): int
    {
        $zdmID = (int)$this->ReadPropertyInteger('ZoningManagerID');
        if ($zdmID <= 0 || !IPS_InstanceExists($zdmID)) {
            $this->LogMessage('ORCH: No ZDM instance linked, using default 60s', KL_WARNING);
            return 60000; // fallback to 60 seconds in milliseconds
        }
        
        try {
            $intervalSec = (int)IPS_GetProperty($zdmID, 'TimerInterval');
            $intervalMs = max(1000, $intervalSec * 1000); // convert to ms, minimum 1s
            $this->LogMessage("ORCH: Using ZDM interval: {$intervalSec}s ({$intervalMs}ms)", KL_DEBUG);
            return $intervalMs;
        } catch (Exception $e) {
            $this->LogMessage('ORCH: Failed to read ZDM interval, using default 60s: ' . $e->getMessage(), KL_WARNING);
            return 60000;
        }
    }
    // ===== Plan helpers =====

    private function generateProposedPlan(int $zoningID, int $adaptiveID): array
    {
        if (!function_exists('ZDM_GetRoomConfigurations')) {
            $this->LogMessage('ORCH generateProposedPlan: ZDM_GetRoomConfigurations() not available', KL_ERROR);
            return [];
        }
        if (!function_exists('ACIPS_GetActionPairs')) {
            $this->LogMessage('ORCH generateProposedPlan: ACIPS_GetActionPairs() not available', KL_ERROR);
            return [];
        }

        $roomConfig = json_decode(ZDM_GetRoomConfigurations($zoningID), true);
        $roomNames  = is_array($roomConfig) ? array_values(array_filter(array_map(function($r){
            return isset($r['name']) ? (string)$r['name'] : '';
        }, $roomConfig), function($n){ return $n !== '' && $n !== '0'; })) : [];

        if (empty($roomNames)) {
            $this->LogMessage('ORCH generateProposedPlan: no rooms found', KL_ERROR);
            return [];
        }

        // === NEW: take allowed actions directly from ACIPS, excluding 0:0
        $allActions = json_decode(ACIPS_GetActionPairs($adaptiveID), true);
        $allowed = [];
        if (is_array($allActions)) {
            foreach ($allActions as $a) {
                $a = (string)$a;
                if ($a === '0:0') continue;
                if (strpos($a, ':') === false) continue;
                [$p,$f] = array_map('intval', explode(':', $a, 2));
                $allowed[] = ['k'=>$a, 'p'=>$p, 'f'=>$f, 'load'=>$p+$f];
            }
        }
        // sort by total load, then power, then fan (smoother ramps)
        usort($allowed, function($A, $B) {
            if ($A['load'] !== $B['load']) return $A['load'] <=> $B['load'];
            if ($A['p']    !== $B['p'])    return $A['p']    <=> $B['p'];
            return $A['f'] <=> $B['f'];
        });


        // choose a representative subset: low/mid/high, or use all if small
        $keys = array_column($allowed, 'k');
        if (count($keys) > 8) {
            // pick 6 evenly spread actions
            $pick = [];
            for ($i=0; $i<6; $i++) {
                $idx = (int)round($i * (count($keys)-1) / 5);
                $pick[$keys[$idx]] = true;
            }
            $keys = array_keys($pick);
        }
        $keys = $this->orderActionsSmooth($keys);
        $actionPatternString = implode(', ', $keys);
        // fallback if ACIPS had none
        if (empty($keys)) $keys = ['55:50','80:80','100:100'];

        $actionPatternString = implode(', ', $keys);

        $finalPlan   = [];
        $stageCounter = 1;

        foreach ($roomNames as $roomToTest) {
            $finalPlan[] = [
                'stageName'    => sprintf("Stage %d: Single Zone (%s)", $stageCounter++, $roomToTest),
                'flapConfig'   => $this->generateFlapConfigString($roomNames, [$roomToTest]),
                'targetOffset' => "-1.5",
                'actionPattern'=> $actionPatternString
            ];
        }
        if (count($roomNames) > 1) {
            $combo = [$roomNames[0], $roomNames[1]];
            $finalPlan[] = [
                'stageName'    => sprintf("Stage %d: Combo Test (%s)", $stageCounter++, implode(' & ', $combo)),
                'flapConfig'   => $this->generateFlapConfigString($roomNames, $combo),
                'targetOffset' => "-3.0",
                'actionPattern'=> $actionPatternString
            ];
        }
        if (count($roomNames) > 2) {
            $combo = [$roomNames[0], end($roomNames)];
            $finalPlan[] = [
                'stageName'    => sprintf("Stage %d: Combo Test (%s)", $stageCounter++, implode(' & ', $combo)),
                'flapConfig'   => $this->generateFlapConfigString($roomNames, $combo),
                'targetOffset' => "-3.0",
                'actionPattern'=> $actionPatternString
            ];
        }

        $allFlapsTrue = $this->generateFlapConfigString($roomNames, $roomNames);
        $finalPlan[] = [
            'stageName'    => sprintf("Stage %d: High Demand (All Zones)", $stageCounter++),
            'flapConfig'   => $allFlapsTrue,
            'targetOffset' => "-5.0",
            'actionPattern'=> $actionPatternString
        ];
        $finalPlan[] = [
            'stageName'    => sprintf("Stage %d: Coil Stress Test", $stageCounter++),
            'flapConfig'   => $allFlapsTrue,
            'targetOffset' => "-6.0",
            'actionPattern'=> "100:90, 100:50, 100:30, 75:30, 75:90" // keep explicit stress path
        ];

        $this->LogMessage('ORCH generateProposedPlan: plan created with '.count($finalPlan).' stages', KL_MESSAGE);
        return $finalPlan;
    }
    private function orderActionsSmooth(array $keys): array
    {
        $acts = [];
        foreach ($keys as $k) {
            if (strpos($k, ':') === false) continue;
            [$p,$f] = array_map('intval', explode(':', $k, 2));
            $acts[] = ['k'=>$k, 'p'=>$p, 'f'=>$f, 'load'=>$p+$f];
        }
        if (empty($acts)) return [];

        // start from the lowest load
        usort($acts, fn($a,$b) => $a['load'] <=> $b['load']);

        $seq = [];
        $used = array_fill(0, count($acts), false);
        $seq[] = $acts[0];
        $used[0] = true;

        while (count($seq) < count($acts)) {
            $last = end($seq);
            $bestI = -1; $bestD = PHP_INT_MAX; $bestLoad = PHP_INT_MAX;
            foreach ($acts as $i => $a) {
                if ($used[$i]) continue;
                $dp = $a['p'] - $last['p'];
                $df = $a['f'] - $last['f'];
                $d  = $dp*$dp + $df*$df;               // squared distance
                if ($d < $bestD || ($d === $bestD && $a['load'] < $bestLoad)) {
                    $bestD = $d; $bestI = $i; $bestLoad = $a['load'];
                }
            }
            $seq[] = $acts[$bestI];
            $used[$bestI] = true;
        }

        return array_map(fn($a) => $a['k'], $seq);
    }

    private function generateFlapConfigString(array $all, array $active): string
    {
        $parts = [];
        $activeMap = array_flip($active);
        foreach ($all as $name) {
            $parts[] = isset($activeMap[$name]) ? "{$name}=true" : "{$name}=false";
        }
        return implode(', ', $parts);
    }

    private function getCalibrationPlan(): array
    {
        $planConfig = json_decode($this->ReadPropertyString('CalibrationPlan'), true);
        if (!is_array($planConfig)) return [];
        $structured = [];
        foreach ($planConfig as $stage) {
            $flaps = [];
            foreach (explode(',', (string)($stage['flapConfig'] ?? '')) as $part) {
                $kv = explode('=', trim($part), 2);
                if (count($kv) === 2) {
                    $flaps[] = ['name' => trim($kv[0]), 'open' => (strtolower(trim($kv[1])) === 'true')];
                }
            }
            $actions = array_filter(array_map('trim', explode(',', (string)($stage['actionPattern'] ?? ''))));
            $structured[] = [
                'name'  => (string)($stage['stageName'] ?? 'Unnamed'),
                'setup' => [
                    'flaps'        => $flaps,
                    'targetOffset' => (float)($stage['targetOffset'] ?? 0)
                ],
                'actions' => $actions
            ];
        }
        return $structured;
    }
    private function GeneratePlanHTML(array $plan): string
    {
        $html = '<!DOCTYPE html><html><head><title>HVAC Plan</title><style>body{font-family:sans-serif;font-size:14px;margin:10px;}table{width:100%;border-collapse:collapse;margin-top:15px;}th,td{border:1px solid #dee2e6;padding:10px 12px;text-align:left;word-break:break-word;}thead{background-color:#e9ecef;}h2{color:#343a40;border-bottom:2px solid #ced4da;padding-bottom:5px;}</style></head><body><h2>Generated Plan</h2>';
        if (empty($plan)) return $html . '<p>No plan generated.</p></body></html>';
        $html .= '<table><thead><tr><th>Stage Name</th><th>Flap Config</th><th>Offset</th><th>Action Pattern</th></tr></thead><tbody>';
        foreach ($plan as $stage) {
            $html .= sprintf('<tr><td>%s</td><td>%s</td><td>%s &deg;C</td><td>%s</td></tr>',
                htmlspecialchars($stage['stageName'] ?? 'N/A'),
                htmlspecialchars($stage['flapConfig'] ?? 'N/A'),
                htmlspecialchars($stage['targetOffset'] ?? 'N/A'),
                htmlspecialchars($stage['actionPattern'] ?? 'N/A')
            );
        }
        return $html . '</tbody></table></body></html>';
    }
    private function getRoomLinks(): array
    {
        $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
        if (!function_exists('ZDM_GetRoomConfigurations')) {
            $this->LogMessage('ORCH getRoomLinks: ZDM_GetRoomConfigurations() not available', KL_ERROR);
            return [];
        }
        $roomConfig = json_decode(ZDM_GetRoomConfigurations($zid), true);
        if (!is_array($roomConfig)) {
            $this->LogMessage('ORCH getRoomLinks: invalid room configuration', KL_ERROR);
            return [];
        }

        $linkMap = [];
        foreach ($roomConfig as $room) {
            if (!empty($room['name'])) {
                $linkMap[(string)$room['name']] = [
                    'tempID'   => (int)($room['tempID']   ?? 0),
                    'targetID' => (int)($room['targetID'] ?? 0)
                ];
            }
        }
        return $linkMap;
    }

    private function saveOriginalTargets()
    {
        $targets = [];
        foreach ($this->getRoomLinks() as $links) {
            $tid = (int)($links['targetID'] ?? 0);
            if ($tid > 0 && IPS_VariableExists($tid)) {
                $targets[$tid] = GetValue($tid);
            }
        }
        $this->WriteAttributeString('OriginalTargetTemps', json_encode($targets, JSON_UNESCAPED_UNICODE));
        $this->LogMessage('ORCH saved original targets: '.count($targets), KL_MESSAGE);
    }

    private function restoreOriginalTargets()
    {
        $targets = json_decode($this->ReadAttributeString('OriginalTargetTemps'), true);
        if (!is_array($targets)) return;
        $n = 0;
        foreach ($targets as $id => $val) {
            $id = (int)$id;
            if (IPS_VariableExists($id)) {
                @SetValue($id, $val);
                $n++;
            }
        }
        $this->LogMessage('ORCH restored original targets: '.$n, KL_MESSAGE);
    }

    private function setArtificialTargets(array $stage)
    {
        $this->restoreOriginalTargets();

        $activeRooms = [];
        foreach (($stage['setup']['flaps'] ?? []) as $flap) {
            if (!empty($flap['name']) && !empty($flap['open'])) {
                $activeRooms[(string)$flap['name']] = true;
            }
        }

        $offset = (float)($stage['setup']['targetOffset'] ?? 0);
        $count  = 0;
        foreach ($this->getRoomLinks() as $name => $links) {
            $tid = (int)($links['targetID'] ?? 0);
            $sid = (int)($links['tempID']   ?? 0);
            if (isset($activeRooms[$name]) && $tid > 0 && $sid > 0 && IPS_VariableExists($tid) && IPS_VariableExists($sid)) {
                @SetValue($tid, @GetValue($sid) + $offset);
                $count++;
            }
        }
        $this->LogMessage('ORCH set artificial targets: '.$count.' rooms, offset='.$offset, KL_MESSAGE);
    }

    // ===== Public wrapper for timer (required by RegisterTimer) =====

    
}
