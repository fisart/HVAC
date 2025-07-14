<?php
/**
 * Zoning & Demand Manager (ZoningDemandManager)
 *
 * Version: 2.2
 * Author: Artur Fischer
 *
 * This module is the master rule engine for a multi-zone HVAC system.
 * It operates in two modes:
 * 1. Adaptive Mode: Determines which rooms need cooling and sets a "demand"
 *    variable for each. It then turns the main AC system on or off, allowing a
 *    separate "Adaptive Cooling Controller" module to determine the optimal
 *    power and fan speed.
 * 2. Standalone Mode: Acts as a simple rule-based thermostat. When cooling
 *    is needed, it turns on the AC system and sets a user-defined, fixed
 *    power and fan speed.
 *
 * It controls individual room flaps based on temperature, window status,
 * and system-wide overrides (e.g., heating is active).
 */

class Zoning_and_Demand_Manager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterPropertyInteger('TimerInterval', 60);

        // --- NEW Properties for Standalone Mode ---
        $this->RegisterPropertyBoolean('StandaloneMode', false);
        $this->RegisterPropertyInteger('ConstantFanSpeed', 0);
        $this->RegisterPropertyInteger('ConstantPower', 0);
        
        $this->RegisterPropertyInteger('HeatingActiveLink', 0);
        $this->RegisterPropertyInteger('VentilationActiveLink', 0);
        $this->RegisterPropertyInteger('MainFanControlLink', 0);
        $this->RegisterPropertyInteger('MainACOnOffLink', 0);
        $this->RegisterPropertyInteger('MasterBedSpecialModeLink', 0);
        $this->RegisterPropertyInteger('MainStatusTextLink', 0);
        $this->RegisterPropertyString('ControlledRooms', '[]');

        // Correct timer registration using the module prefix
        $this->RegisterTimer('ProcessZoning', 0, 'ZDM_ProcessZoning($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ProcessZoning', $this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetStatus(102); // Set to active status
    }

    /**
     * This function is called by IP-Symcon to display the configuration form.
     * It dynamically shows or hides the constant value settings based on the StandaloneMode checkbox.
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // Get the current state of the StandaloneMode property
        $isStandalone = $this->ReadPropertyBoolean('StandaloneMode');

        // Find the elements for constant values and set their visibility
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && ($element['name'] == 'ConstantFanSpeed' || $element['name'] == 'ConstantPower')) {
                $element['visible'] = $isStandalone;
            }
        }
        
        return json_encode($form);
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

        // Universal deactivation checks
        if ($this->IsWindowOpen($roomConfig)) {
            $this->LogMessage("{$roomName}: Window/Door is open. Deactivating room.", KL_MESSAGE);
            $this->DeactivateRoom($roomConfig, 3); // Phase 3 = Window Open
            return false;
        }
        if ($coolingMode === 'OFF') {
            $this->LogMessage("{$roomName}: Mode is OFF. Deactivating room.", KL_MESSAGE);
            $this->DeactivateRoom($roomConfig, 0); // Phase 0 = Off
            return false;
        }

        // Mode-specific logic
        if ($coolingMode === 'ON') { // One-shot cooling
            if ($currentPhase !== 0 && !$this->IsRoomTooHigh($roomConfig)) {
                $this->LogMessage("{$roomName}: Mode is ON, but target already reached. Deactivating permanently for this cycle.", KL_MESSAGE);
                $this->DeactivateRoom($roomConfig, $currentPhase);
                return false;
            }
        }
        
        if ($this->IsRoomTooHigh($roomConfig)) {
            $this->LogMessage("{$roomName}: Temperature is high, cooling is required.", KL_MESSAGE);
            $phase = ($coolingMode === 'ON') ? 1 : 2; // Phase 1 for ON, 2 for AUTO
            $this->ActivateRoom($roomConfig, $phase);
            return true;
        }

        $this->LogMessage("{$roomName}: Temperature is OK. Deactivating room.", KL_MESSAGE);
        $this->DeactivateRoom($roomConfig, 0); // Reset phase to 0
        return false;
    }

    private function GetCoolingModeForRoom(array $roomConfig): string
    {
        $sollStatusID = $roomConfig['airSollStatusID'] ?? 0;
        if ($sollStatusID <= 0 || !@IPS_VariableExists($sollStatusID)) {
            return 'AUTO';
        }
        
        switch (@GetValueInteger($sollStatusID)) {
            case 1: return 'OFF';
            case 2: return 'ON';
            case 3: return 'AUTO';
            default: return 'OFF';
        }
    }
    
    private function GetCoolingPhaseInfo(int $phaseID): int
    {
        if ($phaseID > 0 && @IPS_VariableExists($phaseID)) {
            return @GetValueInteger($phaseID);
        }
        return 0;
    }

    private function IsSystemOverridden(): bool {
        $heatingID = $this->ReadPropertyInteger('HeatingActiveLink');
        if ($heatingID > 0 && @GetValueBoolean($heatingID)) {
            $this->SetStatus(201);
            $this->LogMessage("System Override: Heating is ON.", KL_WARNING);
            return true;
        }
        $ventID = $this->ReadPropertyInteger('VentilationActiveLink');
        if ($ventID > 0 && @GetValueBoolean($ventID)) {
            $this->SetStatus(201);
            $this->LogMessage("System Override: Ventilation is ON.", KL_WARNING);
            return true;
        }
        $this->SetStatus(102);
        return false;
    }
    private function IsWindowOpen(array $roomConfig): bool {
        $catID = $roomConfig['windowCatID'] ?? 0;
        if ($catID <= 0 || !@IPS_CategoryExists($catID)) {
            return false;
        }
        // Get all children of the specified category
        foreach (IPS_GetChildrenIDs($catID) as $childID) {
            // Check if the child is a Link
            if (IPS_LinkExists($childID)) {
                $linkInfo = IPS_GetLink($childID);
                $targetID = $linkInfo['TargetID'];
                // Check if the link's target is a variable
                if (@IPS_VariableExists($targetID)) {
                    // Use generic GetValue(). PHP's truthiness check handles both boolean (true)
                    // and integer (non-zero) types correctly without warnings.
                    if (GetValue($targetID)) {
                        return true; // A window/door is open
                    }
                }
            }
        }
        return false; // All windows/doors are closed
    }
    private function IsRoomTooCold(array $roomConfig): bool {
        $temp = @GetValueFloat($roomConfig['tempID']);
        $target = @GetValueFloat($roomConfig['targetID']);
        $hysteresis = 0.5;
        return $temp <= ($target - $hysteresis);
    }
    private function IsRoomTooHigh(array $roomConfig): bool {
        $temp = @GetValueFloat($roomConfig['tempID']);
        $target = @GetValueFloat($roomConfig['targetID']);
        $hysteresis = 0.5;
        return $temp > ($target + $hysteresis);
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
        if ($flapID <= 0 || !@IPS_VariableExists($flapID)) return;
        $flapType = $roomConfig['flapType'] ?? 'boolean';
        $openValue = $roomConfig['flapOpenValue'] ?? 'true';
        $closedValue = $roomConfig['flapClosedValue'] ?? 'false';
        $valueToSet = $shouldBeOpen ? $openValue : $closedValue;
        if ($flapType == 'boolean') {
            $valueToSet = (strtolower((string)$valueToSet) === 'true');
        } else {
            $valueToSet = intval($valueToSet);
        }
        if (@GetValue($flapID) != $valueToSet) {
            RequestAction($flapID, $valueToSet);
            if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("Setting flap for '{$roomConfig['name']}' to " . ($shouldBeOpen ? "OPEN" : "CLOSED"), KL_MESSAGE);
        }
    }
    private function SetDemandState(int $demandID, int $state) {
        if ($demandID > 0 && @IPS_VariableExists($demandID)) {
            if(@GetValueInteger($demandID) != $state) SetValueInteger($demandID, $state);
        }
    }
    private function SetCoolingPhaseInfo(int $phaseID, int $state) {
        if ($phaseID > 0 && @IPS_VariableExists($phaseID)) {
             if(@GetValueInteger($phaseID) != $state) SetValueInteger($phaseID, $state);
        }
    }
    private function ImplementFinalMeasures(bool $isAnyRoomDemandingCooling) {
        $statusTextID = $this->ReadPropertyInteger('MainStatusTextLink');
        if ($isAnyRoomDemandingCooling) {
            $this->SwitchSystemOn();
            if ($statusTextID > 0) @SetValueString($statusTextID, "Cooling Activated");
        } else {
            $this->SwitchSystemOff();
            if ($statusTextID > 0) @SetValueString($statusTextID, "Cooling DEACTIVATED");
            $specialModeID = $this->ReadPropertyInteger('MasterBedSpecialModeLink');
            if ($specialModeID > 0 && @GetValueInteger($specialModeID) != 0) {
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

    // --- NEW: Mode-aware helper function to control the AC ---
    private function SetAcState(bool $on) {
        $acID = $this->ReadPropertyInteger('MainACOnOffLink');
        if ($acID <= 0) return;

        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $value = $on ? $this->ReadPropertyInteger('ConstantPower') : 0;
            @RequestAction($acID, $value);
        } else {
            @RequestAction($acID, $on);
        }
    }
    
    // --- NEW: Mode-aware helper function to control the Fan ---
    private function SetFanState(bool $on) {
        $fanID = $this->ReadPropertyInteger('MainFanControlLink');
        if ($fanID <= 0) return;
        
        if ($this->ReadPropertyBoolean('StandaloneMode')) {
            $value = $on ? $this->ReadPropertyInteger('ConstantFanSpeed') : 0;
            @RequestAction($fanID, $value);
        } else {
            @RequestAction($fanID, $on);
        }
    }

    // --- REFACTORED: Use new helper functions ---
    private function SwitchSystemOn() {
        $this->SetAcState(true);
        $this->SetFanState(true);
        $mode = $this->ReadPropertyBoolean('StandaloneMode') ? "Standalone" : "Adaptive";
        $this->LogMessage("Main AC System switched ON. Mode: {$mode}.", KL_MESSAGE);
    }

    // --- REFACTORED: Use new helper functions ---
    private function SwitchSystemOff() {
        $this->SetAcState(false);
        $this->SetFanState(false);
        $this->LogMessage("Main AC System switched OFF.", KL_MESSAGE);
    }
    
    // --- REFACTORED: Use new helper function ---
    private function SwitchFan(bool $state) {
        $this->SetFanState($state);
    }
}
