<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       2.1 (Robust Form Update)
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

        $this->RegisterAttributeString('CalibrationStatus', 'Idle'); // Idle, Running, Done
        $this->RegisterAttributeInteger('CurrentStageIndex', 0);
        $this->RegisterAttributeInteger('CurrentActionIndex', 0);
        $this->RegisterAttributeString('OriginalTargetTemps', '{}');

        $this->RegisterVariableString('CalibrationPlanHTML', 'Calibration Plan (HTML)', '~HTMLBox', 10);

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
        return file_get_contents(__DIR__ . '/form.json');
    }

    // --- Public User-Facing Functions ---

    public function ProposePlan()
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        if ($zoningID == 0 || $adaptiveID == 0) {
            echo "Error: Please set and save the 'Core Module Links' before generating a plan.";
            return;
        }

        $proposedPlan = $this->generateProposedPlan($zoningID, $adaptiveID);
        
        if (!empty($proposedPlan)) {
            // --- MODIFIED & MORE ROBUST METHOD TO UPDATE THE FORM ---
            // 1. Set the property value directly
            IPS_SetProperty($this->InstanceID, 'CalibrationPlan', json_encode($proposedPlan));

            // 2. Check if there are changes and apply them to force a form refresh
            if (IPS_HasChanges($this->InstanceID)) {
                IPS_ApplyChanges($this->InstanceID);
            }
            // --- END OF MODIFICATION ---

            // Update the HTML view as before
            $planHtml = $this->GeneratePlanHTML($proposedPlan);
            $this->SetValue('CalibrationPlanHTML', $planHtml);
            
            // The message to the user now reflects the page reload
            echo "A new calibration plan has been proposed. The form will now refresh to display it.";

        } else {
            echo "Error: Failed to generate a valid calibration plan. Check the module's message log for detailed errors.";
            $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML([]));
        }
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
        $this->LogMessage("--- Calibration Stopped Manually---", KL_MESSAGE);
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
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') return;

        $plan = $this->getCalibrationPlan();
        $stageIdx = $this->ReadAttributeInteger('CurrentStageIndex');
        $actionIdx = $this->ReadAttributeInteger('CurrentActionIndex');

        if ($stageIdx >= count($plan)) {
            $this->LogMessage("--- Calibration Plan Finished Successfully! ---", KL_MESSAGE);
            $this->StopCalibration(); // This will reset modes and targets
            return;
        }

        $currentStage = $plan[$stageIdx];
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');

        if ($actionIdx === 0) {
            $this->LogMessage("--- Starting Calibration Stage " . ($stageIdx + 1) . "/" . count($plan) . ": " . $currentStage['name'] . " ---", KL_MESSAGE);
            ZDM_CommandFlaps($zoningID, json_encode($currentStage['setup']['flaps']));
            $this->setArtificialTargets($currentStage);
            IPS_Sleep(5000); 
        }

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

    // --- Private Helper Functions ---

    /**
     * @brief Creates a comprehensive, multi-stage calibration plan.
     */
    private function generateProposedPlan(int $zoningID, int $adaptiveID): array
    {
        $this->LogMessage("--- Starting Dynamic Plan Generation ---", KL_MESSAGE);

        // Fetch room names
        $roomConfig = json_decode(ZDM_GetRoomConfigurations($zoningID), true);
        if (!is_array($roomConfig)) {
            $this->LogMessage("Plan Gen ERROR: Failed to decode room JSON.", KL_ERROR);
            return [];
        }
        $roomNames = array_column($roomConfig, 'name');
        if (empty($roomNames)) {
            $this->LogMessage("Plan Gen ERROR: No rooms configured in Zoning Manager.", KL_ERROR);
            return [];
        }
        $this->LogMessage("Found rooms: " . implode(', ', $roomNames), KL_DEBUG);

        // Fetch and filter action patterns
        $allActions = json_decode(ACIPS_GetActionPairs($adaptiveID), true);
        if (!is_array($allActions)) {
            $this->LogMessage("Plan Gen ERROR: Failed to decode action pairs JSON.", KL_ERROR);
            return [];
        }
        $actionPattern = array_values(array_filter($allActions, function($action) {
            list($p, $f) = explode(':', $action);
            return $p != 0 && in_array((string)$p, ['30', '75', '100'], true) && in_-array((string)$f, ['30', '70', '90'], true);
        }));
        if (empty($actionPattern)) $actionPattern = ['55:50', '100:100'];
        $actionPatternString = implode(', ', $actionPattern);

        // --- Plan Building Logic ---
        $finalPlan = [];
        $stageCounter = 1;

        // Stage Group 1: Individual Room Tests
        $this->LogMessage("Generating individual zone test stages...", KL_MESSAGE);
        foreach ($roomNames as $roomToTest) {
            $flapConfig = $this->generateFlapConfigString($roomNames, [$roomToTest]);
            $finalPlan[] = [
                'stageName'    => sprintf("Stage %d: Single Zone (%s)", $stageCounter++, $roomToTest),
                'flapConfig'   => $flapConfig,
                'targetOffset' => "-1.5",
                'actionPattern'=> $actionPatternString
            ];
        }

        // Stage Group 2: Room Combination Tests
        $this->LogMessage("Generating combination test stages...", KL_MESSAGE);
        if (count($roomNames) > 1) {
            // First two rooms
            $combo = [$roomNames[0], $roomNames[1]];
            $flapConfig = $this->generateFlapConfigString($roomNames, $combo);
            $finalPlan[] = [
                'stageName'    => sprintf("Stage %d: Combo Test (%s)", $stageCounter++, implode(' & ', $combo)),
                'flapConfig'   => $flapConfig,
                'targetOffset' => "-3.0",
                'actionPattern'=> $actionPatternString
            ];
        }
        if (count($roomNames) > 2) {
            // First and last room (tests disparate locations)
            $combo = [$roomNames[0], end($roomNames)];
            $flapConfig = $this->generateFlapConfigString($roomNames, $combo);
            $finalPlan[] = [
                'stageName'    => sprintf("Stage %d: Combo Test (%s)", $stageCounter++, implode(' & ', $combo)),
                'flapConfig'   => $flapConfig,
                'targetOffset' => "-3.0",
                'actionPattern'=> $actionPatternString
            ];
        }
        
        // Stage Group 3: Full Load and Stress Tests
        $this->LogMessage("Generating full load and stress test stages...", KL_MESSAGE);
        $allFlapsTrue = $this->generateFlapConfigString($roomNames, $roomNames);

        // High Demand (All Zones)
        $finalPlan[] = [
            'stageName'    => sprintf("Stage %d: High Demand (All Zones)", $stageCounter++),
            'flapConfig'   => $allFlapsTrue,
            'targetOffset' => "-5.0",
            'actionPattern'=> $actionPatternString
        ];
        
        // Coil Stress Test
        $finalPlan[] = [
            'stageName'    => sprintf("Stage %d: Coil Stress Test", $stageCounter++),
            'flapConfig'   => $allFlapsTrue,
            'targetOffset' => "-6.0",
            'actionPattern'=> "100:90, 100:50, 100:30, 75:30, 75:90"
        ];
        
        $this->LogMessage("--- Dynamic plan generation complete. " . count($finalPlan) . " stages created. ---", KL_MESSAGE);
        return $finalPlan;
    }

    /**
     * @brief Helper to generate a flap configuration string.
     * @param array $allRoomNames All available rooms.
     * @param array $activeRooms The rooms whose flaps should be open.
     * @return string The formatted string for the form.
     */
    private function generateFlapConfigString(array $allRoomNames, array $activeRooms): string
    {
        $configParts = [];
        $activeRoomsMap = array_flip($activeRooms); // For quick lookups
        foreach ($allRoomNames as $name) {
            $configParts[] = isset($activeRoomsMap[$name]) ? "{$name}=true" : "{$name}=false";
        }
        return implode(', ', $configParts);
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
        $roomConfigJson = ZDM_GetRoomConfigurations($zoningID);
        $roomConfig = json_decode($roomConfigJson, true);
        if (!is_array($roomConfig)) return [];
        
        $linkMap = [];
        foreach ($roomConfig as $room) {
            if (!empty($room['name'])) {
                $linkMap[$room['name']] = ['tempID' => $room['tempID'] ?? 0, 'targetID' => $room['targetID'] ?? 0];
            }
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

        $this->restoreOriginalTargets();

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

    private function GeneratePlanHTML(array $plan): string
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>HVAC Calibration Plan</title><style>
            body { font-family: sans-serif; font-size: 14px; margin: 0; padding: 10px; background-color: #f8f9fa; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); background-color: #ffffff; }
            th, td { border: 1px solid #dee2e6; padding: 10px 12px; text-align: left; word-break: break-word; }
            thead { background-color: #e9ecef; } th { font-weight: 600; }
            tbody tr:nth-child(odd) { background-color: #f2f2f2; }
            h2 { color: #343a40; border-bottom: 2px solid #ced4da; padding-bottom: 5px; }
        </style></head><body><h2>Generated Calibration Plan</h2>';

        if (empty($plan)) {
            $html .= '<p>No plan has been generated yet. Click "Generate Proposed Plan" to create one.</p></body></html>';
            return $html;
        }

        $html .= '<table><thead><tr><th>Stage Name</th><th>Flap Configuration</th><th>Target Offset</th><th>Action Pattern</th></tr></thead><tbody>';

        foreach ($plan as $stage) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($stage['stageName'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($stage['flapConfig'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($stage['targetOffset'] ?? 'N/A') . ' &deg;C</td>';
            $html .= '<td>' . htmlspecialchars($stage['actionPattern'] ?? 'N/A') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';
        return $html;
    }
}
