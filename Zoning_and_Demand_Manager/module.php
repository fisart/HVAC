<?php
/**
 * @file          module.php
 * @author        Artur Fischer (Original Concept), Symcon Module Specialist (Refinement)
 * @version       2.5
 * @date          2023-10-27
 *
 * @brief         Zoning & Demand Manager for multi-zone HVAC systems.
 *
 * This module is the master rule engine for a multi-zone HVAC system.
 * It operates in two modes:
 * 1.  Adaptive Mode: Determines which rooms need cooling and sets a "demand"
 *     variable for each. It then turns the main AC system on or off, allowing a
 *     separate "Adaptive Cooling Controller" module to determine the optimal
 *     power and fan speed.
 * 2.  Standalone Mode: Acts as a powerful rule-based thermostat. When cooling
 *     is needed, it turns on the AC system and sets a user-defined, fixed
 *     power and fan speed.
 *
 * Key Features:
 * -   Controls individual room flaps based on target temperature, window status,
 *     and user-defined cooling modes (ON, OFF, AUTO).
 * -   System-wide overrides to disable cooling when heating or ventilation is active.
 * -   Special mode for master bedroom ventilation.
 * -   Supports both boolean (On/Off) and linear (0-100%) flap actuators.
 * -   Uses a configurable hysteresis value for temperature checks.
 * -   Robustly checks for open windows/doors via a category of links.
 * -   Avoids error suppression for stable and debuggable operation.
 *
 */

class Zoning_and_Demand_Manager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterPropertyInteger('TimerInterval', 60);
        $this->RegisterPropertyFloat('Hysteresis', 0.5);
        $this->RegisterPropertyBoolean('StandaloneMode', false);
        $this->RegisterPropertyInteger('ConstantFanSpeed', 80);
        $this->RegisterPropertyInteger('ConstantPower', 80);
        $this->RegisterPropertyInteger('HeatingActiveLink', 0);
        $this->RegisterPropertyInteger('VentilationActiveLink', 0);
        $this->RegisterPropertyInteger('MainFanControlLink', 0);
        $this->RegisterPropertyInteger('MainACOnOffLink', 0);
        $this->RegisterPropertyInteger('MasterBedSpecialModeLink', 0);
        $this->RegisterPropertyInteger('MainStatusTextLink', 0);
        $this->RegisterPropertyString('ControlledRooms', '[]');
        $this->RegisterTimer('ProcessZoning', 0, 'ZDM_ProcessZoning($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->SetTimerInterval('ProcessZoning', $this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetStatus(102);
    }

    public function ProcessZoning()
    {
        if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("--- START Zoning & Demand Check ---", KL_MESSAGE);
        if ($this->IsSystemOverridden()) {
            $this->SwitchSystemOff();
            if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("--- END: System Override Active ---", KL_MESSAGE);
            return;
        }
        $rooms = json_decode($this->ReadPropertyString('ControlledRooms'), true);
        $isAnyRoomDemandingCooling = false;
        foreach ($rooms as $roomConfig) {
            $roomDemandsCooling = $this->ProcessRoom($roomConfig);
            if ($roomDemandsCooling) {
                $isAnyRoomDemandingCooling = true;
            }
        }
        $this->ImplementFinalMeasures($isAnyRoomDemandingCooling);
        if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("--- END Zoning & Demand Check ---", KL_MESSAGE);
    }

    private function ProcessRoom(array $roomConfig): bool
    {
        $roomName = $roomConfig['name'] ?? '';
        if (empty($roomName)) return false;
        if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("Processing room: {$roomName}", KL_MESSAGE);
        $coolingMode = $this->GetCoolingModeForRoom($roomConfig);
        $currentPhase = $this->GetCoolingPhaseInfo($roomConfig['coolingPhaseInfoID']);
        if ($this->IsWindowOpen($roomConfig)) {
            $this->LogMessage("{$roomName}: Window/Door is open. Deactivating room.", KL_MESSAGE);
            $this->DeactivateRoom($roomConfig, 3);
            return false;
        }
        if ($coolingMode === 'OFF') {
            $this->LogMessage("{$roomName}: Mode is OFF. Deactivating room.", KL_MESSAGE);
            $this->DeactivateRoom($roomConfig, 0);
            return false;
        }
        if ($coolingMode === 'ON') {
            if ($currentPhase !== 0 && !$this->IsRoomTooHigh($roomConfig)) {
                $this->LogMessage("{$roomName}: Mode is ON, but target already reached. Deactivating permanently for this cycle.", KL_MESSAGE);
                $this->DeactivateRoom($roomConfig, $currentPhase);
                return false;
            }
        }
        if ($this->IsRoomTooHigh($roomConfig)) {
            $this->LogMessage("{$roomName}: Temperature is high, cooling is required.", KL_MESSAGE);
            $phase = ($coolingMode === 'ON') ? 1 : 2;
            $this->ActivateRoom($roomConfig, $phase);
            return true;
        }
        $this->LogMessage("{$roomName}: Temperature is OK. Deactivating room.", KL_MESSAGE);
        $this->DeactivateRoom($roomConfig, 0);
        return false;
    }

    private function GetCoolingModeForRoom(array $roomConfig): string {
        $sollStatusID = $roomConfig['airSollStatusID'] ?? 0;
        if ($sollStatusID > 0 && IPS_VariableExists($sollStatusID)) {
            switch (GetValueInteger($sollStatusID)) {
                case 1: return 'OFF';
                case 2: return 'ON';
                case 3: return 'AUTO';
            }
        }
        return 'AUTO';
    }
    
    private function GetCoolingPhaseInfo(int $phaseID): int {
        if ($phaseID > 0 && IPS_VariableExists($phaseID)) {
            return GetValueInteger($phaseID);
        }
        return 0;
    }

    private function IsSystemOverridden(): bool {
        $heatingID = $this->ReadPropertyInteger('HeatingActiveLink');
        if ($heatingID > 0 && IPS_VariableExists($heatingID) && GetValueBoolean($heatingID)) {
            $this->SetStatus(201);
            $this->LogMessage("System Override: Heating is ON.", KL_WARNING);
            return true;
        }
        $ventID = $this->ReadPropertyInteger('VentilationActiveLink');
        if ($ventID > 0 && IPS_VariableExists($ventID) && GetValueBoolean($ventID)) {
            $this->SetStatus(201);
            $this->LogMessage("System Override: Ventilation is ON.", KL_WARNING);
            return true;
        }
        $this->SetStatus(102);
        return false;
    }

    private function IsWindowOpen(array $roomConfig): bool {
        $catID = $roomConfig['windowCatID'] ?? 0;
        if ($catID <= 0 || !IPS_CategoryExists($catID)) {
            return false;
        }
        foreach (IPS_GetChildrenIDs($catID) as $childID) {
            // Preferred method: Check for a Link to a sensor
            if (IPS_LinkExists($childID)) {
                $linkInfo = IPS_GetLink($childID);
                $targetID = $linkInfo['TargetID'];
                if (IPS_VariableExists($targetID) && GetValue($targetID)) {
                    return true;
                }
            }
            // Fallback for direct variables in the category
            else if (IPS_VariableExists($childID) && GetValue($childID)) {
                return true;
            }
        }
        return false;
    }

    private function IsRoomTooHigh(array $roomConfig): bool {
        $tempID = $roomConfig['tempID'] ?? 0;
        $targetID = $roomConfig['targetID'] ?? 0;
        if ($tempID > 0 && IPS_VariableExists($tempID) && $targetID > 0 && IPS_VariableExists($targetID)) {
            $temp = GetValueFloat($tempID);
            $target = GetValueFloat($targetID);
            $hysteresis = $this->ReadPropertyFloat('Hysteresis'); // Use configurable property
            return $temp > ($target + $hysteresis);
        }
        return false;
    }

    private function ActivateRoom(array $roomConfig, int $phaseInfo) {
        $this->SetFlapState($roomConfig, true);
        $this->SetDemandState($roomConfig['demandID'], 1);
        $this->SetCoolingPhaseInfo($roomConfig['coolingPhaseInfoID'], $phaseInfo);
    }

    private function DeactivateRoom(array $roomConfig, int $phaseInfo) {
        $this->SetFlapState($roomConfig, false);
        $this->SetDemandState($roomConfig['demandID'], 0);
        $this->SetCoolingPhaseInfo($roomConfig['coolingPhaseInfoID'], $phaseInfo);
    }
    
    private function SetFlapState(array $roomConfig, bool $shouldBeOpen) {
        $flapID = $roomConfig['flapID'] ?? 0;
        if ($flapID <= 0 || !IPS_VariableExists($flapID)) return;
        $flapType = $roomConfig['flapType'] ?? 'boolean';
        $openValue = $roomConfig['flapOpenValue'] ?? 'true';
        $closedValue = $roomConfig['flapClosedValue'] ?? 'false';
        $valueToSet = $shouldBeOpen ? $openValue : $closedValue;
        $currentValue = GetValue($flapID);
        if ($flapType == 'boolean') {
            $targetValue = (strtolower((string)$valueToSet) === 'true');
            if ($currentValue != $targetValue) {
                RequestAction($flapID, $targetValue);
                if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("Setting boolean flap for '{$roomConfig['name']}' to " . ($shouldBeOpen ? "OPEN" : "CLOSED"), KL_MESSAGE);
            }
        } else { // Assumes 'linear'
            $targetValue = intval($valueToSet);
            if ($currentValue != $targetValue) {
                RequestAction($flapID, $targetValue);
                if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("Setting linear flap for '{$roomConfig['name']}' to value {$targetValue} (" . ($shouldBeOpen ? "OPEN" : "CLOSED") . ")", KL_MESSAGE);
            }
        }
    }

    private function SetDemandState(int $demandID, int $state) {
        if ($demandID > 0 && IPS_VariableExists($demandID) && GetValueInteger($demandID) != $state) {
            SetValueInteger($demandID, $state);
        }
    }

    private function SetCoolingPhaseInfo(int $phaseID, int $state) {
        if ($phaseID > 0 && IPS_VariableExists($phaseID) && GetValueInteger($phaseID) != $state) {
            SetValueInteger($phaseID, $state);
        }
    }

    private function ImplementFinalMeasures(bool $isAnyRoomDemandingCooling) {
        $statusTextID = $this->ReadPropertyInteger('MainStatusTextLink');
        if ($isAnyRoomDemandingCooling) {
            $this->SwitchSystemOn();
            if ($statusTextID > 0 && IPS_VariableExists($statusTextID)) {
                SetValueString($statusTextID, "Cooling Activated");
            }
        } else {
            $this->SwitchSystemOff();
            if ($statusTextID > 0 && IPS_VariableExists($statusTextID)) {
                SetValueString($statusTextID, "Cooling DEACTIVATED");
            }
            $specialModeID = $this->ReadPropertyInteger('MasterBedSpecialModeLink');
            if ($specialModeID > 0 && IPS_VariableExists($specialModeID) && GetValueInteger($specialModeID) != 0) {
                $rooms = json_decode($this->ReadPropertyString('ControlledRooms'), true);
                foreach ($rooms as $room) {
                    if (($room['name'] ?? '') == 'Master Bed Room') { 
                        $this->LogMessage("Master Bedroom special mode active. Opening flap.", KL_MESSAGE);
                        $this->SetFlapState($room, true);
                        break;
                    }
                }
                $this->SwitchFan(true);
            }
        }
    }

    private function SetAcState(bool $on) {
        $acID = $this->ReadPropertyInteger('MainACOnOffLink');
        if ($acID <= 0 || !IPS_VariableExists($acID)) return;

        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $value = $on ? $this->ReadPropertyInteger('ConstantPower') : 0;
            RequestAction($acID, $value);
        } else {
            RequestAction($acID, $on);
        }
    }
    
    private function SetFanState(bool $on) {
        $fanID = $this->ReadPropertyInteger('MainFanControlLink');
        if ($fanID <= 0 || !IPS_VariableExists($fanID)) return;
        
        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $value = $on ? $this->ReadPropertyInteger('ConstantFanSpeed') : 0;
            RequestAction($fanID, $value);
        } else {
            RequestAction($fanID, $on);
        }
    }

    private function SwitchSystemOn() {
        $this->SetAcState(true);
        $this->SetFanState(true);
        $mode = $this->ReadPropertyBoolean('StandaloneMode') ? "Standalone" : "Adaptive";
        $this->LogMessage("Main AC System switched ON. Mode: {$mode}.", KL_MESSAGE);
    }

    private function SwitchSystemOff() {
        $this->SetAcState(false);
        $this->SetFanState(false);
        $this->LogMessage("Main AC System switched OFF.", KL_MESSAGE);
    }
    
    private function SwitchFan(bool $state) {
        $this->SetFanState($state);
    }
}
