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
            return;
        }

        // Set status and initialize calibration

        $this->uiSetCalStatus('Running');
        $this->SetStatus(102); // keep healthy code

        $this->WriteAttributeInteger('CurrentStageIndex', 0);
        $this->WriteAttributeInteger('CurrentActionIndex', 0);
        $this->SetStatus(102);
        $this->LogMessage('--- ORCH: Starting System Calibration ---', KL_MESSAGE);

        // Start timer with ZDM interval
        $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());
        // Originalziele sichern (falls vorhanden)
        if (method_exists($this, 'saveOriginalTargets')) {
            $this->saveOriginalTargets();
        }

        // ZDM Override einschalten
        if (function_exists('ZDM_SetOverrideMode')) {
            $this->LogMessage("ORCH: Sending Override=true to ZDM instance {$zoningID}", KL_MESSAGE);
            // before calling ZDM_SetOverrideMode(...)
            $overrideValue = true;
            $this->LogMessage("ORCH: DEBUG_ORCH_SEND_OVERRIDE target={$zoningID} value=" . ($overrideValue ? 'true' : 'false'), KL_MESSAGE);
            ZDM_SetOverrideMode($zoningID, $overrideValue);

            // Verifikation: OverrideActive in der ZDM-Instanz prüfen
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

        // Adaptive-Modus (optional, falls API vorhanden)
        if (function_exists('ACIPS_SetMode')) {
            ACIPS_SetMode($adaptiveID, 'orchestrated');
            $this->LogMessage('ORCH: ACIPS_SetMode(orchestrated) requested', KL_MESSAGE);
        } else {
            $this->LogMessage('ORCH: ACIPS_SetMode() not available', KL_WARNING);
        }

        // Hier deine bestehende Startlogik weiterlaufen lassen (Plan generieren, Timer/Step starten, etc.)
        if (method_exists($this, 'ProposePlan')) {
            // optional/sofern gewünscht: Plan neu erzeugen
            // $this->ProposePlan();
        }
        if (method_exists($this, 'RunNextStep')) {
            $this->RunNextStep();
        }

        // UI aktualisieren
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
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') {
            $this->LogMessage('ORCH RunNextStep: ignored (status not Running)', KL_WARNING);
            return;
        }

        // NEW: pause when ZDM reports emergency; do not advance indices
        $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
        if ($zid > 0 && function_exists('ZDM_GetAggregates')) {
            $agg = @json_decode(@ZDM_GetAggregates($zid), true);
            if (is_array($agg) && !empty($agg['emergencyActive'])) {
                $this->LogMessage('ORCH RunNextStep: paused (ZDM emergencyActive)', KL_WARNING);
                // keep showing Running, just wait for next tick
                $this->SetTimerInterval('CalibrationTimer', $this->getZDMProcessingInterval());
                return;
            }
        }

        // (optional, keeps label fresh while stepping)
        $this->uiSetCalStatus('Running');

        $currentStage = $plan[$stageIdx];

        if ($actionIdx === 0) {
            $this->LogMessage(sprintf('--- ORCH: Starting Stage %d/%d: %s ---',
                $stageIdx + 1, count($plan), (string)($currentStage['name'] ?? 'Unnamed')), KL_MESSAGE);

            // Convert list-of-objects to {"Room Name": bool}
            $flapMap = [];
            foreach (($currentStage['setup']['flaps'] ?? []) as $f) {
                if (isset($f['name'])) {
                    $flapMap[(string)$f['name']] = (bool)($f['open'] ?? false);
                }
            }
            $this->LogMessage('ORCH flapMap → ' . json_encode($flapMap, JSON_UNESCAPED_UNICODE), KL_MESSAGE);

            $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
            if (function_exists('ZDM_CommandFlaps')) {
                ZDM_CommandFlaps(
                    $zid,
                    (string)($currentStage['name'] ?? 'calibration'),
                    json_encode($flapMap, JSON_UNESCAPED_UNICODE)
                );
            } else {
                $this->LogMessage('ORCH RunNextStep: ZDM_CommandFlaps() not available', KL_ERROR);
            }

            $this->setArtificialTargets($currentStage);
            IPS_Sleep(5000); // allow flaps/targets to settle
        }

        $adaptiveID = (int)$this->ReadPropertyInteger('AdaptiveControlID');
        if (function_exists('ACIPS_ForceActionAndLearn')) {
            $action = (string)($currentStage['actions'][$actionIdx] ?? '');
            // Ensure physical AC/Fan relays follow the step action (ZDM handles the real outputs)
            if ($action !== '' && strpos($action, ':') !== false) {
                list($p, $f) = array_map('intval', explode(':', $action, 2));
                $zid = (int)$this->ReadPropertyInteger('ZoningManagerID');
                if ($zid > 0 && function_exists('ZDM_CommandSystem')) {
                    @ZDM_CommandSystem($zid, $p, $f);
                    $this->LogMessage(
                        'ORCH: zdm_command_system ' . json_encode(['p'=>$p,'f'=>$f], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                        KL_MESSAGE
                    );
                } else {
                    $this->LogMessage('ORCH: ZDM_CommandSystem() not available or ZDM link missing', KL_WARNING);
                }
            }


            $result = @json_decode(ACIPS_ForceActionAndLearn($adaptiveID, $action), true);
            $this->LogMessage(
                "ORCH Step {$stageIdx}:{$actionIdx} | Action: {$action} | Reward: " . number_format((float)($result['reward'] ?? 0), 2),
                KL_DEBUG
            );
        } else {
            $this->LogMessage('ORCH RunNextStep: ACIPS_ForceActionAndLearn() not available', KL_ERROR);
        }

        // advance indices
        $actionIdx++;
        if ($actionIdx >= count($currentStage['actions'])) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
        } else {
            $this->WriteAttributeInteger('CurrentActionIndex', $actionIdx);
        }
    
        // Set next timer interval (always use ZDM interval)
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
        // sort by total load (ascending) for smooth ramps
        usort($allowed, fn($A,$B) => $A['load'] <=> $B['load']);

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
