<?php

/**
 * Zoning & Demand Manager (ZoningDemandManager)
 *
 * Version: 2.0
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
        // Register all user-configurable properties
        $this->RegisterPropertyString('OperatingMode', 'adaptive');
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterPropertyInteger('TimerInterval', 60);
        
        // System-wide links
        $this->RegisterPropertyInteger('HeatingActiveLink', 0);
        $this->RegisterPropertyInteger('MainACOnOffLink', 0);
        $this->RegisterPropertyInteger('MainFanControlLink', 0);
        
        // Standalone Mode Properties
        $this->RegisterPropertyInteger('StandalonePowerLink', 0);
        $this->RegisterPropertyInteger('StandaloneFanLink', 0);
        $this->RegisterPropertyInteger('StandalonePowerValue', 80);
        $this->RegisterPropertyInteger('StandaloneFanValue', 80);
        
        // Room List
        $this->RegisterPropertyString('ControlledRooms', '[]');
        
        $this->RegisterTimer('ProcessZoning', 0, 'ZDM_ProcessZoning($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ProcessZoning', $this->ReadPropertyInteger('TimerInterval') * 1000);
        
        $acLink = $this->ReadPropertyInteger('MainACOnOffLink');
        if ($acLink === 0) {
            $this->SetStatus(104); // Not configured
        } else {
            $this->SetStatus(102); // OK
        }
    }
    
    public function GetConfigurationForParent()
    {
        // This optional function helps the "Select Variable" dialog in child instances
        // It is not strictly required for operation but is good practice.
        // Returning an empty JSON is sufficient to prevent errors if not needed.
        return json_encode([]);
    }

    /**
     * Main logic loop called by the timer.
     */
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
            if ($this->ProcessRoom($roomConfig)) {
                $isAnyRoomDemandingCooling = true;
            }
        }

        $this->ImplementFinalMeasures($isAnyRoomDemandingCooling);

        if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("--- END Zoning & Demand Check ---", KL_MESSAGE);
    }

    /**
     * Contains the decision logic for a single room.
     * @return bool True if this room is actively demanding cooling.
     */
    private function ProcessRoom(array $roomConfig): bool
    {
        $roomName = $roomConfig['name'] ?? '';
        if (empty($roomName) || empty($roomConfig['tempID']) || empty($roomConfig['targetID'])) return false;

        if ($this->IsWindowOpen($roomConfig)) {
            $this->LogMessage("{$roomName}: Window/Door is open. Deactivating room.", KL_MESSAGE);
            $this->DeactivateRoom($roomConfig);
            return false;
        }

        if ($this->IsRoomTooCold($roomConfig)) {
             $this->LogMessage("{$roomName}: Temperature is too low. Deactivating room.", KL_MESSAGE);
             $this->DeactivateRoom($roomConfig);
             return false;
        }

        if ($this->IsRoomTooHigh($roomConfig)) {
            $this->LogMessage("{$roomName}: Temperature is high, cooling is required.", KL_MESSAGE);
            $this->ActivateRoom($roomConfig);
            return true;
        }
        
        $this->LogMessage("{$roomName}: Temperature is OK. Deactivating room.", KL_MESSAGE);
        $this->DeactivateRoom($roomConfig);
        return false;
    }

    // ===================================================================
    //  LOGIC HELPERS
    // ===================================================================

    private function IsSystemOverridden(): bool
    {
        $heatingID = $this->ReadPropertyInteger('HeatingActiveLink');
        if ($heatingID > 0 && @GetValueBoolean($heatingID)) {
            $this->SetStatus(201);
            $this->LogMessage("System Override: Heating is ON.", KL_WARNING);
            return true;
        }
        
        $this->SetStatus(102);
        return false;
    }

    private function IsWindowOpen(array $roomConfig): bool
    {
        $catID = $roomConfig['windowCatID'] ?? 0;
        if ($catID <= 0 || !@IPS_CategoryExists($catID)) return false;

        foreach(IPS_GetChildrenIDs($catID) as $childID) {
            if (@IPS_VariableExists($childID) && GetValueBoolean($childID)) {
                return true;
            }
        }
        return false;
    }

    private function IsRoomTooCold(array $roomConfig): bool
    {
        $temp = @GetValueFloat($roomConfig['tempID']);
        $target = @GetValueFloat($roomConfig['targetID']);
        $hysteresis = 0.5;
        return $temp <= ($target - $hysteresis);
    }
    
    private function IsRoomTooHigh(array $roomConfig): bool
    {
        $temp = @GetValueFloat($roomConfig['tempID']);
        $target = @GetValueFloat($roomConfig['targetID']);
        $hysteresis = 0.5;
        return $temp > ($target + $hysteresis);
    }

    // ===================================================================
    //  ACTION HELPERS
    // ===================================================================

    private function ActivateRoom(array $roomConfig)
    {
        $this->SetFlapState($roomConfig, true);
        $this->SetDemandState($roomConfig['demandID'], 1); // Demand ON for Adaptive Module
    }

    private function DeactivateRoom(array $roomConfig)
    {
        $this->SetFlapState($roomConfig, false);
        $this->SetDemandState($roomConfig['demandID'], 0); // Demand OFF for Adaptive Module
    }

    private function SetFlapState(array $roomConfig, bool $shouldBeOpen)
    {
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

    private function SetDemandState(int $demandID, int $state)
    {
        if ($demandID > 0 && @IPS_VariableExists($demandID)) {
            if(@GetValueInteger($demandID) != $state) SetValueInteger($demandID, $state);
        }
    }
    
    private function ImplementFinalMeasures(bool $isAnyRoomDemandingCooling)
    {
        $operatingMode = $this->ReadPropertyString('OperatingMode');

        if ($isAnyRoomDemandingCooling) {
            if ($operatingMode == 'standalone') {
                $this->SwitchSystemOnStandalone();
            } else { // adaptive
                $this->SwitchSystemOnAdaptive();
            }
        } else {
            $this->SwitchSystemOff();
        }
    }

    private function SwitchSystemOnAdaptive()
    {
        $this->LogMessage("System ON (Requesting Adaptive Control)", KL_MESSAGE);
        $acID = $this->ReadPropertyInteger('MainACOnOffLink');
        if ($acID > 0) @RequestAction($acID, true);
        $fanID = $this->ReadPropertyInteger('MainFanControlLink');
        if ($fanID > 0) @RequestAction($fanID, true);
    }

    private function SwitchSystemOnStandalone()
    {
        $this->LogMessage("System ON (Standalone Mode)", KL_MESSAGE);
        $powerLink = $this->ReadPropertyInteger('StandalonePowerLink');
        $fanLink = $this->ReadPropertyInteger('StandaloneFanLink');
        $powerValue = $this->ReadPropertyInteger('StandalonePowerValue');
        $fanValue = $this->ReadPropertyInteger('StandaloneFanValue');

        if ($powerLink > 0) @RequestAction($powerLink, $powerValue);
        if ($fanLink > 0) @RequestAction($fanLink, $fanValue);
        
        $this->SwitchSystemOnAdaptive(); // Also turn on main switches
    }

    private function SwitchSystemOff()
    {
        $this->LogMessage("Switching Main AC System OFF.", KL_MESSAGE);
        $acID = $this->ReadPropertyInteger('MainACOnOffLink');
        if ($acID > 0) @RequestAction($acID, false);
        $fanID = $this->ReadPropertyInteger('MainFanControlLink');
        if ($fanID > 0) @RequestAction($fanID, false);
        
        // Also ensure standalone values are set to 0
        $powerLink = $this->ReadPropertyInteger('StandalonePowerLink');
        if ($powerLink > 0) @RequestAction($powerLink, 0);
        $fanLink = $this->ReadPropertyInteger('StandaloneFanLink');
        if ($fanLink > 0) @RequestAction($fanLink, 0);
    }
}
