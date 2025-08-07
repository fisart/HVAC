<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       2.3 (Hard Stop Command)
 * @date          2025-08-07
 */

class HVAC_Learning_Orchestrator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('ZoningManagerID', 0);
        $this->RegisterPropertyInteger('AdaptiveControlID', 0);
        $this->RegisterPropertyString('CalibrationPlan', '[]');
        $this->RegisterAttributeString('CalibrationStatus', 'Idle');
        $this->RegisterAttributeInteger('CurrentStageIndex', 0);
        $this->RegisterAttributeInteger('CurrentActionIndex', 0);
        $this->RegisterAttributeString('OriginalTargetTemps', '{}');
        $this->RegisterVariableString('CalibrationPlanHTML', 'Calibration Plan (HTML)', '~HTMLBox', 10);
        $this->RegisterTimer('CalibrationTimer', 0, 'ORCH_RunNextStep($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        $status = 102; // Idle
        if ($this->ReadPropertyInteger('ZoningManagerID') == 0 || $this->ReadPropertyInteger('AdaptiveControlID') == 0) $status = 202;
        else if ($this->ReadAttributeString('CalibrationStatus') == 'Running') $status = 201;
        else if ($this->ReadAttributeString('CalibrationStatus') == 'Done') $status = 203;
        $this->SetStatus($status);
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    public function ProposePlan()
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        if ($zoningID == 0 || $adaptiveID == 0) {
            echo "Error: Core Module Links must be set first.";
            return;
        }

        $proposedPlan = $this->generateProposedPlan($zoningID, $adaptiveID);
        
        if (!empty($proposedPlan)) {
            IPS_SetProperty($this->InstanceID, 'CalibrationPlan', json_encode($proposedPlan));
            if (IPS_HasChanges($this->InstanceID)) IPS_ApplyChanges($this->InstanceID);
            $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML($proposedPlan));
            echo "New calibration plan proposed. The form will now refresh.";
        } else {
            echo "Error: Failed to generate a valid calibration plan. Check logs.";
            $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML([]));
        }
    }

    public function StartCalibration()
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        if ($zoningID == 0 || $adaptiveID == 0) return;

        $this->LogMessage("--- Starting System Calibration ---", KL_MESSAGE);
        $this->WriteAttributeString('CalibrationStatus', 'Running');
        $this->SetStatus(201);
        $this->saveOriginalTargets();
        ACIPS_SetMode($adaptiveID, 'orchestrated');
        ACIPS_ResetLearning($adaptiveID);
        ZDM_SetOverrideMode($zoningID, true);
        ZDM_CommandSystem($zoningID, true, true);
        $this->RunNextStep();
        $this->SetTimerInterval('CalibrationTimer', 120 * 1000);
    }

    public function StopCalibration()
    {
        $this->LogMessage("--- Calibration Stopped ---", KL_MESSAGE);
        $this->SetTimerInterval('CalibrationTimer', 0);
        $this->restoreOriginalTargets();
        
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        if ($zoningID > 0) {
            ZDM_CommandSystem($zoningID, false, false);
            ZDM_SetOverrideMode($zoningID, false);
        }
        
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        if ($adaptiveID > 0) {
            // --- NEW: Explicitly command power and fan to 0 ---
            // Force the final action to be "0:0" before changing modes.
            // This ensures the value-based variables are reset correctly.
            ACIPS_ForceActionAndLearn($adaptiveID, '0:0');
            // --- END OF NEW CODE ---

            // Now, set the mode back to cooperative for normal operation
            ACIPS_SetMode($adaptiveID, 'cooperative');
        }

        $this->WriteAttributeString('CalibrationStatus', 'Done');
        $this->SetStatus(203);
    }
    
    public function RunNextStep()
    {
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') return;
        $plan = $this->getCalibrationPlan();
        $stageIdx = $this->ReadAttributeInteger('CurrentStageIndex');
        $actionIdx = $this->ReadAttributeInteger('CurrentActionIndex');

        if ($stageIdx >= count($plan)) {
            $this->LogMessage("--- Calibration Plan Finished Successfully! ---", KL_MESSAGE);
            $this->StopCalibration();
            return;
        }

        $currentStage = $plan[$stageIdx];
        if ($actionIdx === 0) {
            $this->LogMessage("--- Starting Stage " . ($stageIdx + 1) . "/" . count($plan) . ": " . $currentStage['name'] . " ---", KL_MESSAGE);
            ZDM_CommandFlaps($this->ReadPropertyInteger('ZoningManagerID'), json_encode($currentStage['setup']['flaps']));
            $this->setArtificialTargets($currentStage);
            IPS_Sleep(5000); 
        }

        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        $result = json_decode(ACIPS_ForceActionAndLearn($adaptiveID, $currentStage['actions'][$actionIdx]), true);
        $this->LogMessage("Step {$stageIdx}:{$actionIdx} | Action: {$currentStage['actions'][$actionIdx]} | State: " . ($result['state'] ?? 'N/A') . ", Reward: " . number_format($result['reward'] ?? 0, 2), KL_DEBUG);

        $actionIdx++;
        if ($actionIdx >= count($currentStage['actions'])) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
        } else {
            $this->WriteAttributeInteger('CurrentActionIndex', $actionIdx);
        }
    }

    private function generateProposedPlan(int $zoningID, int $adaptiveID): array
    {
        $roomConfig = json_decode(ZDM_GetRoomConfigurations($zoningID), true);
        $roomNames = is_array($roomConfig) ? array_column($roomConfig, 'name') : [];
        if (empty($roomNames)) return [];
        
        $allActions = json_decode(ACIPS_GetActionPairs($adaptiveID), true);
        if (!is_array($allActions)) return [];
        
        $actionPattern = array_values(array_filter($allActions, function($action) {
            list($p, $f) = explode(':', $action);
            return $p != 0 && in_array((string)$p, ['30', '75', '100'], true) && in_array((string)$f, ['30', '70', '90'], true);
        }));
        if (empty($actionPattern)) $actionPattern = ['55:50', '100:100'];
        $actionPatternString = implode(', ', $actionPattern);

        $finalPlan = [];
        $stageCounter = 1;

        foreach ($roomNames as $roomToTest) {
            $finalPlan[] = ['stageName' => sprintf("Stage %d: Single Zone (%s)", $stageCounter++, $roomToTest),'flapConfig' => $this->generateFlapConfigString($roomNames, [$roomToTest]),'targetOffset' => "-1.5",'actionPattern'=> $actionPatternString];
        }
        if (count($roomNames) > 1) {
            $combo = [$roomNames[0], $roomNames[1]];
            $finalPlan[] = ['stageName' => sprintf("Stage %d: Combo Test (%s)", $stageCounter++, implode(' & ', $combo)),'flapConfig' => $this->generateFlapConfigString($roomNames, $combo),'targetOffset' => "-3.0",'actionPattern'=> $actionPatternString];
        }
        if (count($roomNames) > 2) {
            $combo = [$roomNames[0], end($roomNames)];
            $finalPlan[] = ['stageName' => sprintf("Stage %d: Combo Test (%s)", $stageCounter++, implode(' & ', $combo)),'flapConfig' => $this->generateFlapConfigString($roomNames, $combo),'targetOffset' => "-3.0",'actionPattern'=> $actionPatternString];
        }
        
        $allFlapsTrue = $this->generateFlapConfigString($roomNames, $roomNames);
        $finalPlan[] = ['stageName' => sprintf("Stage %d: High Demand (All Zones)", $stageCounter++),'flapConfig' => $allFlapsTrue,'targetOffset' => "-5.0",'actionPattern'=> $actionPatternString];
        $finalPlan[] = ['stageName' => sprintf("Stage %d: Coil Stress Test", $stageCounter++),'flapConfig' => $allFlapsTrue,'targetOffset' => "-6.0",'actionPattern'=> "100:90, 100:50, 100:30, 75:30, 75:90"];
        return $finalPlan;
    }

    private function generateFlapConfigString(array $all, array $active): string
    {
        $parts = [];
        $activeMap = array_flip($active);
        foreach ($all as $name) $parts[] = isset($activeMap[$name]) ? "{$name}=true" : "{$name}=false";
        return implode(', ', $parts);
    }

    private function getCalibrationPlan(): array
    {
        $planConfig = json_decode($this->ReadPropertyString('CalibrationPlan'), true);
        if (!is_array($planConfig)) return [];
        $structuredPlan = [];
        foreach ($planConfig as $stage) {
            $flaps = [];
            foreach (explode(',', $stage['flapConfig'] ?? '') as $part) {
                $kv = explode('=', trim($part));
                if (count($kv) === 2) $flaps[] = ['name' => trim($kv[0]), 'open' => (strtolower(trim($kv[1])) === 'true')];
            }
            $actions = array_map('trim', explode(',', $stage['actionPattern'] ?? ''));
            $structuredPlan[] = ['name' => $stage['stageName'] ?? 'Unnamed', 'setup' => ['flaps' => $flaps, 'targetOffset' => floatval($stage['targetOffset'] ?? 0)], 'actions' => array_filter($actions)];
        }
        return $structuredPlan;
    }
    
    private function getRoomLinks(): array
    {
        $roomConfig = json_decode(ZDM_GetRoomConfigurations($this->ReadPropertyInteger('ZoningManagerID')), true);
        if (!is_array($roomConfig)) return [];
        $linkMap = [];
        foreach ($roomConfig as $room) {
            if (!empty($room['name'])) $linkMap[$room['name']] = ['tempID' => $room['tempID'] ?? 0, 'targetID' => $room['targetID'] ?? 0];
        }
        return $linkMap;
    }

    private function saveOriginalTargets()
    {
        $targets = [];
        foreach ($this->getRoomLinks() as $links) {
            if (($links['targetID'] > 0) && IPS_VariableExists($links['targetID'])) {
                 $targets[$links['targetID']] = GetValue($links['targetID']);
            }
        }
        $this->WriteAttributeString('OriginalTargetTemps', json_encode($targets));
    }

    private function restoreOriginalTargets()
    {
        $targets = json_decode($this->ReadAttributeString('OriginalTargetTemps'), true);
        if (!is_array($targets)) return;
        foreach ($targets as $id => $val) {
            if (IPS_VariableExists($id)) {
                SetValue($id, $val);
            }
        }
    }

    private function setArtificialTargets(array $stage)
    {
        $this->restoreOriginalTargets();
        $activeRooms = [];
        foreach ($stage['setup']['flaps'] as $flap) if ($flap['open']) $activeRooms[$flap['name']] = true;
        foreach ($this->getRoomLinks() as $name => $links) {
            if (isset($activeRooms[$name]) && ($links['targetID'] > 0) && ($links['tempID'] > 0) && IPS_VariableExists($links['targetID']) && IPS_VariableExists($links['tempID'])) {
                SetValue($links['targetID'], GetValue($links['tempID']) + $stage['setup']['targetOffset']);
            }
        }
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
}
