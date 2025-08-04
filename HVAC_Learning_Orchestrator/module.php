<?php
/**
 * @file          module.php
 * @author        Artur Fischer
 * @version       1.0
 * @date          2025-8-4
 *
 * @brief         HVAC Learning Orchestrator for self-tuning control systems.
 *
 * This module acts as the "Conductor" for a multi-module HVAC system. It
 * contains a configurable, timer-driven state machine to execute an automated
 * "Commissioning and Self-Tuning" (CST) routine.
 *
 * It takes control of the Zoning and Adaptive modules, forces the system into
 * various states (e.g., high/low demand), executes a pattern of actions, and
 * commands the adaptive module to learn from the outcomes. This process seeds
 * the AI's Q-Table with high-quality, diverse data in hours instead of weeks.
 *
 */

class HVAC_Learning_Orchestrator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('ZoningManagerID', 0);
        $this->RegisterPropertyInteger('AdaptiveControlID', 0);
        $this->RegisterPropertyString('RoomConfigLinks', '[]');
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

    // --- Public User-Facing Functions ---

    public function StartCalibration()
    {
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        if ($zoningID == 0 || $adaptiveID == 0) {
            $this->LogMessage("Cannot start calibration: Module links are not configured.", KL_ERROR);
            echo "Error: Module links are not configured.";
            return;
        }

        $this->LogMessage("--- Starting System Calibration ---", KL_MESSAGE);

        // 1. Set status and reset indices
        $this->WriteAttributeString('CalibrationStatus', 'Running');
        $this->WriteAttributeInteger('CurrentStageIndex', 0);
        $this->WriteAttributeInteger('CurrentActionIndex', 0);
        $this->SetStatus(201);

        // 2. Save original target temperatures
        $this->saveOriginalTargets();

        // 3. Command other modules to enter orchestrated/override mode
        ZDM_SetOverrideMode($zoningID, true);
        ACIPS_SetMode($adaptiveID, 'orchestrated');
        ACIPS_ResetLearning($adaptiveID);
        ZDM_CommandSystem($zoningID, true, true); // Turn on main system

        // 4. Execute the first step immediately
        $this->RunNextStep();

        // 5. Activate the timer for all subsequent steps
        $this->SetTimerInterval('CalibrationTimer', 120 * 1000);
    }

    public function StopCalibration()
    {
        $this->LogMessage("--- Stopping Calibration ---", KL_MESSAGE);

        // 1. Stop the timer
        $this->SetTimerInterval('CalibrationTimer', 0);
        
        // 2. Restore original target temperatures
        $this->restoreOriginalTargets();

        // 3. Release the other modules
        $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        if ($zoningID > 0) {
            ZDM_CommandSystem($zoningID, false, false);
            ZDM_SetOverrideMode($zoningID, false);
        }
        if ($adaptiveID > 0) {
            ACIPS_SetMode($adaptiveID, 'cooperative');
        }

        // 4. Update status
        $this->WriteAttributeString('CalibrationStatus', 'Done');
        $this->SetStatus(203);
    }
    
    // --- Core State Machine Logic ---

    public function RunNextStep()
    {
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') {
            return; // Safety check
        }

        $plan = $this->getCalibrationPlan();
        $stageIdx = $this->ReadAttributeInteger('CurrentStageIndex');
        $actionIdx = $this->ReadAttributeInteger('CurrentActionIndex');

        // Check for completion of the entire plan
        if ($stageIdx >= count($plan)) {
            $this->StopCalibration();
            return;
        }

        $currentStage = $plan[$stageIdx];

        // Setup for a new stage
        if ($actionIdx === 0) {
            $this->LogMessage("Starting Calibration Stage: " . $currentStage['name'], KL_MESSAGE);
            // Set artificial target temperatures
            $this->setArtificialTargets($currentStage);
            // Set flap configuration
            $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
            ZDM_CommandFlaps($zoningID, json_encode($currentStage['setup']['flaps']));
            // Give system a moment to settle after changing targets/flaps
            IPS_Sleep(5000); 
        }

        // Get the action for the current step
        $currentAction = $currentStage['actions'][$actionIdx];

        // Command the Brain to execute and learn
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        $resultJson = ACIPS_ForceActionAndLearn($adaptiveID, $currentAction);
        $result = json_decode($resultJson, true);
        $this->LogMessage("Step {$stageIdx}:{$actionIdx} | Action: {$currentAction} | Result State: {$result['state']}, Reward: {$result['reward']}", KL_DEBUG);

        // Update the state memory for the NEXT step
        $nextActionIdx = $actionIdx + 1;
        if ($nextActionIdx >= count($currentStage['actions'])) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
        } else {
            $this->WriteAttributeInteger('CurrentActionIndex', $nextActionIdx);
        }
    }

    // --- Private Helper Functions ---

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
                if (count($kv) === 2) {
                    $flaps[] = ['name' => trim($kv[0]), 'open' => (strtolower(trim($kv[1])) === 'true')];
                }
            }

            $actions = array_map('trim', explode(',', $stage['actionPattern'] ?? ''));
            
            $structuredPlan[] = [
                'name'    => $stage['stageName'] ?? 'Unnamed Stage',
                'setup'   => ['flaps' => $flaps, 'targetOffset' => floatval($stage['targetOffset'] ?? 0)],
                'actions' => array_filter($actions)
            ];
        }
        return $structuredPlan;
    }

    private function saveOriginalTargets()
    {
        $roomLinks = json_decode($this->ReadPropertyString('RoomConfigLinks'), true);
        $originalTargets = [];
        foreach ($roomLinks as $room) {
            $targetID = $room['targetID'] ?? 0;
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
            if (IPS_VariableExists($targetID)) {
                SetValue($targetID, $value);
            }
        }
        $this->WriteAttributeString('OriginalTargetTemps', '{}'); // Clear saved values
    }

    private function setArtificialTargets(array $stage)
    {
        $this->restoreOriginalTargets(); // Always restore before setting new ones
        $this->saveOriginalTargets();    // Re-save for this stage

        $roomLinks = json_decode($this->ReadPropertyString('RoomConfigLinks'), true);
        $offset = $stage['setup']['targetOffset'];

        foreach ($roomLinks as $room) {
            $targetID = $room['targetID'] ?? 0;
            $tempID = $room['tempID'] ?? 0;
            if ($targetID > 0 && IPS_VariableExists($targetID) && $tempID > 0 && IPS_VariableExists($tempID)) {
                $currentTemp = GetValue($tempID);
                SetValue($targetID, $currentTemp + $offset);
            }
        }
    }
}
