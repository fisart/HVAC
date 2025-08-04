<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       1.2 (Final & Corrected)
 * @date          2023-10-27
 */

class HVAC_Learning_Orchestrator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('ZoningManagerID', 0);
        $this->RegisterPropertyInteger('AdaptiveControlID', 0);
        $this->RegisterPropertyString('CalibrationPlan', '[]');

        $this->RegisterAttributeString('CalibrationStatus', 'Idle'); // Idle, Running, Done
        $this->RegisterAttributeInteger('CurrentStageIndex', 0);
        $this->RegisterAttributeInteger('CurrentActionIndex', 0);
        $this->RegisterAttributeString('OriginalTargetTemps', '{}');

        $this->RegisterTimer('CalibrationTimer', 0, 'ORCH_RunNextStep($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        if ($this->ReadPropertyInteger('ZoningManagerID') == 0 || $this->ReadPropertyInteger('AdaptiveControlID') == 0) {
            $this->SetStatus(202); // Missing links
        } else if ($this->ReadAttributeString('CalibrationStatus') == 'Running') {
            $this->SetStatus(201);
        } else if ($this->ReadAttributeString('CalibrationStatus') == 'Done') {
            $this->SetStatus(203);
        } else {
            $this->SetStatus(102); // Idle
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        if ($this->ReadPropertyInteger('ZoningManagerID') == 0 || $this->ReadPropertyInteger('AdaptiveControlID') == 0) {
            $form['elements'][] = [ 'type' => 'Label', 'label' => 'Please set the Core Module Links and click "Apply" before generating a plan.', 'bold' => true, 'color' => '#FF0000' ];
        }
        
        return json_encode($form);
    }

    // --- Public User-Facing Functions ---

    public function ProposePlan()
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        if ($zoningID == 0 || $adaptiveID == 0) {
            echo "Error: Please set and save the 'Core Module Links' before trying to generate a plan.";
            return;
        }

        $proposedPlan = $this->generateProposedPlan($zoningID, $adaptiveID);
        $planJson = json_encode($proposedPlan);

        $this->UpdateFormField('CalibrationPlan', 'value', $planJson);
        
        echo "A new plan has been proposed. Review the details and click 'Apply' at the top to save it.";
    }

    public function StartCalibration()
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        if ($zoningID == 0 || $adaptiveID == 0) {
            echo "Error: Module links are not configured.";
            return;
        }

        $this->LogMessage("--- Starting System Calibration ---", KL_MESSAGE);

        $this->WriteAttributeString('CalibrationStatus', 'Running');
        $this->WriteAttributeInteger('CurrentStageIndex', 0);
        $this->WriteAttributeInteger('CurrentActionIndex', 0);
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
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        if ($zoningID > 0) {
            ZDM_CommandSystem($zoningID, false, false);
            ZDM_SetOverrideMode($zoningID, false);
        }
        if ($adaptiveID > 0) {
            ACIPS_SetMode($adaptiveID, 'cooperative');
        }

        $this->WriteAttributeString('CalibrationStatus', 'Done');
        $this->SetStatus(203);
    }
    
    public function RunNextStep()
    {
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') {
            return;
        }

        $plan = $this->getCalibrationPlan();
        $stageIdx = $this->ReadAttributeInteger('CurrentStageIndex');
        $actionIdx = $this->ReadAttributeInteger('CurrentActionIndex');

        if ($stageIdx >= count($plan)) {
            $this->StopCalibration();
            return;
        }

        $currentStage = $plan[$stageIdx];
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');

        if ($actionIdx === 0) {
            $this->LogMessage("Starting Calibration Stage: " . $currentStage['name'], KL_MESSAGE);
            ZDM_CommandFlaps($zoningID, json_encode($currentStage['setup']['flaps']));
            IPS_Sleep(5000); 
        }

        $this->setArtificialTargets($currentStage);
        $currentAction = $currentStage['actions'][$actionIdx];
        
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        $resultJson = ACIPS_ForceActionAndLearn($adaptiveID, $currentAction);
        $result = json_decode($resultJson, true);
        $this->LogMessage("Step {$stageIdx}:{$actionIdx} | Action: {$currentAction} | Result State: {$result['state']}, Reward: " . number_format($result['reward'], 2), KL_DEBUG);

        $nextActionIdx = $actionIdx + 1;
        if ($nextActionIdx >= count($currentStage['actions'])) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
        } else {
            $this->WriteAttributeInteger('CurrentActionIndex', $nextActionIdx);
        }
    }

    private function generateProposedPlan(int $zoningID, int $adaptiveID): array
    {
        $roomNames = [];
        $roomConfigJson = ZDM_GetRoomConfigurations($zoningID);
        $roomConfig = json_decode($roomConfigJson, true);
        if (is_array($roomConfig)) {
            foreach ($roomConfig as $room) {
                if (!empty($room['name'])) $roomNames[] = $room['name'];
            }
        }
        if (empty($roomNames)) {
            $this->LogMessage("Plan generation failed: No rooms configured in Zoning Manager.", KL_WARNING);
            return [];
        }

        $allActionsJson = ACIPS_GetActionPairs($adaptiveID);
        $allActions = json_decode($allActionsJson, true);
        
        $actionPattern = array_values(array_filter($allActions, function($action) {
            list($p, $f) = explode(':', $action);
            return $p != 0 && in_array($p, ['30', '75', '100']) && in_array($f, ['30', '70', '90']);
        }));
        if (empty($actionPattern)) $actionPattern = ['55:50', '100:100'];
        $actionPatternString = implode(', ', $actionPattern);

        $firstRoom = $roomNames[0];
        $allFlapsTrue = implode(', ', array_map(function($name) { return "$name=true"; }, $roomNames));
        
        $singleFlapConfigParts = [];
        foreach($roomNames as $index => $name) {
            $singleFlapConfigParts[] = ($index === 0) ? "$name=true" : "$name=false";
        }
        $singleFlapConfig = implode(', ', $singleFlapConfigParts);

        return [
            ['stageName' => "Stage 1: Low Demand Test (1 Zone)", 'flapConfig' => $singleFlapConfig, 'targetOffset' => "-0.5", 'actionPattern' => $actionPatternString ],
            ['stageName' => "Stage 2: High Demand Test (All Zones)", 'flapConfig' => $allFlapsTrue, 'targetOffset' => "-5.0", 'actionPattern' => $actionPatternString ],
            ['stageName' => "Stage 3: Coil Stress Test", 'flapConfig' => $allFlapsTrue, 'targetOffset' => "-5.0", 'actionPattern' => "100:90, 100:50, 100:30, 100:10, 75:10, 30:90"]
        ];
    }

    private function getCalibrationPlan(): array
    {
        $planConfig = json_decode($this->ReadPropertyString('CalibrationPlan'), true);
        if (!is_array($planConfig)) return [];

        $structuredPlan = [];
        foreach ($planConfig as $stage) {
            $flaps = [];
            $flapParts = explode(',', $stage['flapConfig'] ?? '');
            foreach ($flapParts as $part) {
                $kv = explode('=', trim($part));
                if (count($kv) === 2) $flaps[] = ['name' => trim($kv[0]), 'open' => (strtolower(trim($kv[1])) === 'true')];
            }
            $actions = array_map('trim', explode(',', $stage['actionPattern'] ?? ''));
            $structuredPlan[] = ['name' => $stage['stageName'] ?? 'Unnamed Stage', 'setup' => ['flaps' => $flaps, 'targetOffset' => floatval($stage['targetOffset'] ?? 0)], 'actions' => array_filter($actions)];
        }
        return $structuredPlan;
    }
    
    private function getRoomLinks(): array
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        if ($zoningID == 0) return [];
        // Use try-catch for robustness in case the other module is unavailable
        try {
            $roomConfigJson = ZDM_GetRoomConfigurations($zoningID);
            $roomConfig = json_decode($roomConfigJson, true);
        } catch (Exception $e) {
            $this->LogMessage("Failed to get room configurations from Zoning Manager: " . $e->getMessage(), KL_ERROR);
            return [];
        }

        if (!is_array($roomConfig)) return [];
        
        $linkMap = [];
        foreach ($roomConfig as $room) {
            $linkMap[$room['name']] = ['tempID' => $room['tempID'] ?? 0, 'targetID' => $room['targetID'] ?? 0];
        }
        return $linkMap;
    }

    private function saveOriginalTargets()
    {
        $roomLinks = $this->getRoomLinks();
        $originalTargets = [];
        foreach ($roomLinks as $links) {
            $targetID = $links['targetID'];
            if ($targetID > 0 && IPS_VariableExists($targetID)) {
                $originalTargets[$targetID] = GetValue($targetID);
            }
        }
        $this->WriteAttributeString('OriginalTargetTemps', json_encode($originalTargets));
    }

    private function restoreOriginalTargets()
    {
        $originalTargets = json_decode($this->ReadAttributeString('OriginalTargetTemps'), true);
        if (!is_array($originalTargets)) return;

        foreach ($originalTargets as $targetID => $value) {
            if (IPS_VariableExists($targetID)) SetValue($targetID, $value);
        }
        $this->WriteAttributeString('OriginalTargetTemps', '{}');
    }

    private function setArtificialTargets(array $stage)
    {
        $roomLinks = $this->getRoomLinks();
        $offset = $stage['setup']['targetOffset'];
        $flapConfig = $stage['setup']['flaps'];

        $activeRooms = [];
        foreach ($flapConfig as $flap) {
            if ($flap['open']) $activeRooms[$flap['name']] = true;
        }

        // Restore all to original to clear previous stage's settings
        $originalTargets = json_decode($this->ReadAttributeString('OriginalTargetTemps'), true);
        if (is_array($originalTargets)) {
             foreach ($originalTargets as $targetID => $value) {
                if (IPS_VariableExists($targetID)) SetValue($targetID, $value);
            }
        }

        // Now set artificial targets only for active rooms
        foreach ($roomLinks as $name => $links) {
            if (isset($activeRooms[$name])) {
                $targetID = $links['targetID'];
                $tempID = $links['tempID'];
                if ($targetID > 0 && IPS_VariableExists($targetID) && $tempID > 0 && IPS_VariableExists($tempID)) {
                    $currentTemp = GetValue($tempID);
                    SetValue($targetID, $currentTemp + $offset);
                }
            }
        }
    }
}
