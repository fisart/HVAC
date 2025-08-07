<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       1.2 (Debugging & HTML Output)
 * @date          2023-10-28
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

        // New variable to store the HTML visualization of the plan
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

        // Generate the plan array using the function with detailed logging
        $proposedPlan = $this->generateProposedPlan($zoningID, $adaptiveID);
        
        // Check if the plan was generated successfully before proceeding
        if (!empty($proposedPlan)) {
            // Existing logic: Update the form field for the user to see and edit
            $this->UpdateFormField('CalibrationPlan', 'value', json_encode($proposedPlan));

            // --- NEW LOGIC ---
            // Generate the HTML version of the plan
            $planHtml = $this->GeneratePlanHTML($proposedPlan);

            // Save the HTML to the instance variable for webhook/WebFront access
            $this->SetValue('CalibrationPlanHTML', $planHtml);
            // --- END OF NEW LOGIC ---

            echo "A new calibration plan has been proposed successfully! The list below is updated, and the HTML visualization is now available.";
        } else {
            // This message will be shown if the generation function returns an empty array
            echo "Error: Failed to generate a valid calibration plan. Check the module's message log for detailed errors.";
            
            // Also clear the HTML visualization on failure
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

    // --- Private Helper Functions ---

    private function generateProposedPlan(int $zoningID, int $adaptiveID): array
    {
        // Step 1: Log function entry and parameters
        $this->LogMessage("--- generateProposedPlan: Starting plan generation. ZoningID: {$zoningID}, AdaptiveID: {$adaptiveID}", KL_MESSAGE);

        // Step 2: Get and log room configurations
        $roomNames = [];
        $roomConfigJson = ZDM_GetRoomConfigurations($zoningID);
        $this->LogMessage("generateProposedPlan: Raw room config JSON from Zoning Manager: " . substr($roomConfigJson, 0, 500) . "...", KL_DEBUG);
        $roomConfig = json_decode($roomConfigJson, true);

        if (!is_array($roomConfig)) {
            $this->LogMessage("generateProposedPlan CRITICAL ERROR: Failed to decode JSON from Zoning Manager. Aborting.", KL_ERROR);
            return [];
        }

        foreach ($roomConfig as $room) {
            if (!empty($room['name'])) {
                $roomNames[] = $room['name'];
            }
        }
        
        if (empty($roomNames)) {
            $this->LogMessage("generateProposedPlan ERROR: No room names were parsed from the Zoning Manager's configuration. Aborting.", KL_ERROR);
            return [];
        }
        $this->LogMessage("generateProposedPlan: Successfully parsed room names: " . implode(', ', $roomNames), KL_MESSAGE);

        // Step 3: Get and log action pairs
        $allActionsJson = ACIPS_GetActionPairs($adaptiveID);
        $this->LogMessage("generateProposedPlan: Raw action pairs JSON from Adaptive Controller: {$allActionsJson}", KL_DEBUG);
        $allActions = json_decode($allActionsJson, true);
        
        if (!is_array($allActions)) {
            $this->LogMessage("generateProposedPlan CRITICAL ERROR: Could not decode action pairs JSON from Adaptive Controller. Aborting.", KL_ERROR);
            return [];
        }
        $this->LogMessage("generateProposedPlan: Successfully parsed " . count($allActions) . " action pairs.", KL_MESSAGE);

        // Step 4: Filter actions and log the result
        $actionPattern = array_values(array_filter($allActions, function($action) {
            list($p, $f) = explode(':', $action);
            return $p != 0 && in_array((string)$p, ['30', '75', '100'], true) && in_array((string)$f, ['30', '70', '90'], true);
        }));

        $this->LogMessage("generateProposedPlan: Filtered action pattern resulted in: [" . implode(', ', $actionPattern) . "]", KL_MESSAGE);

        if (empty($actionPattern)) {
            $this->LogMessage("generateProposedPlan WARNING: Action pattern is empty after filtering. Falling back to default pattern.", KL_WARNING);
            $actionPattern = ['55:50', '100:100'];
        }
        $actionPatternString = implode(', ', $actionPattern);
        $this->LogMessage("generateProposedPlan: Final action pattern string for stages 1 & 2: '{$actionPatternString}'", KL_DEBUG);

        // Step 5: Generate and log flap configuration strings
        $allFlapsTrue = implode(', ', array_map(function($name) { return "{$name}=true"; }, $roomNames));
        $this->LogMessage("generateProposedPlan: Generated 'All Flaps True' config: '{$allFlapsTrue}'", KL_DEBUG);
        
        $singleFlapConfigParts = [];
        foreach ($roomNames as $index => $name) {
            $singleFlapConfigParts[] = ($index === 0) ? "{$name}=true" : "{$name}=false";
        }
        $singleFlapConfig = implode(', ', $singleFlapConfigParts);
        $this->LogMessage("generateProposedPlan: Generated 'Single Flap' config: '{$singleFlapConfig}'", KL_DEBUG);

        // Step 6: Construct and log the final plan
        $finalPlan = [
            ['stageName' => "Stage 1: Low Demand Test (1 Zone)", 'flapConfig' => $singleFlapConfig, 'targetOffset' => "-0.5", 'actionPattern' => $actionPatternString],
            ['stageName' => "Stage 2: High Demand Test (All Zones)", 'flapConfig' => $allFlapsTrue, 'targetOffset' => "-5.0", 'actionPattern' => $actionPatternString],
            ['stageName' => "Stage 3: Coil Stress Test", 'flapConfig' => $allFlapsTrue, 'targetOffset' => "-5.0", 'actionPattern' => "100:90, 100:50, 100:30, 100:10, 75:10, 30:90"]
        ];

        $this->LogMessage("generateProposedPlan: --- Final Proposed Plan Structure ---", KL_MESSAGE);
        $this->LogMessage(print_r($finalPlan, true), KL_DEBUG);
        $this->LogMessage("--- generateProposedPlan: Plan generation complete. ---", KL_MESSAGE);

        return $finalPlan;
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

        // Before setting new targets, first restore all to original to clear previous stage's settings
        $originalTargets = json_decode($this->ReadAttributeString('OriginalTargetTemps'), true);
        if (is_array($originalTargets)) {
             foreach ($originalTargets as $targetID => $value) {
                if (IPS_VariableExists($targetID)) SetValue($targetID, $value);
            }
        }

        // Now set the artificial targets only for the active rooms in this stage
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

    /**
     * @brief Formats a calibration plan array into a self-contained HTML table.
     * @param array $plan The calibration plan array.
     * @return string The generated HTML.
     */
    private function GeneratePlanHTML(array $plan): string
    {
        // Basic CSS for a clean look
        $html = '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>HVAC Calibration Plan</title>
            <style>
                body { font-family: sans-serif; font-size: 14px; margin: 0; padding: 10px; background-color: #f8f9fa; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); background-color: #ffffff; }
                th, td { border: 1px solid #dee2e6; padding: 10px 12px; text-align: left; word-break: break-word; }
                thead { background-color: #e9ecef; }
                th { font-weight: 600; }
                tbody tr:nth-child(odd) { background-color: #f2f2f2; }
                h2 { color: #343a40; border-bottom: 2px solid #ced4da; padding-bottom: 5px; }
            </style>
        </head>
        <body>
            <h2>Generated Calibration Plan</h2>';

        if (empty($plan)) {
            $html .= '<p>No plan has been generated yet or the generation failed. Click "Generate Proposed Plan" to create one.</p>';
            $html .= '</body></html>';
            return $html;
        }

        $html .= '<table>
                    <thead>
                        <tr>
                            <th>Stage Name</th>
                            <th>Flap Configuration</th>
                            <th>Target Offset</th>
                            <th>Action Pattern</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($plan as $stage) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($stage['stageName'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($stage['flapConfig'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($stage['targetOffset'] ?? 'N/A') . ' &deg;C</td>';
            $html .= '<td>' . htmlspecialchars($stage['actionPattern'] ?? 'N/A') . '</td>';
            $html .= '</tr>';
        }

        $html .= '    </tbody>
                  </table>
                </body>
                </html>';

        return $html;
    }
}
