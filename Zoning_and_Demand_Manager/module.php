<?php

class Zoning_and_Demand_Manager extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Register all user-configurable properties from form.json
        $this->RegisterPropertyBoolean('Debug', false);
        $this->RegisterPropertyInteger('TimerInterval', 60);
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
        $this->SetTimerInterval('ProcessZoning', $this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetStatus(102); // Default to active, checks will update it
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
        
        // Ensure required IDs are set before proceeding
        if (empty($roomConfig['tempID']) || empty($roomConfig['targetID'])) {
            return false;
        }

        if ($this->ReadPropertyBoolean('Debug')) $this->LogMessage("Processing room: {$roomName}", KL_MESSAGE);

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

    private function IsSystemOverridden(): bool
    {
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
        $temp = GetValueFloat($roomConfig['tempID']);
        $target = GetValueFloat($roomConfig['targetID']);
        $hysteresis = 0.5;
        return $temp <= ($target - $hysteresis);
    }
    
    private function IsRoomTooHigh(array $roomConfig): bool
    {
        $temp = GetValueFloat($roomConfig['tempID']);
        $target = GetValueFloat($roomConfig['targetID']);
        $hysteresis = 0.5;
        return $temp > ($target + $hysteresis);
    }

    private function ActivateRoom(array $roomConfig)
    {
        $this->SetFlapState($roomConfig, true);
        $this->SetDemandState($roomConfig['demandID'], 1);
    }

    private function DeactivateRoom(array $roomConfig)
    {
        $this->SetFlapState($roomConfig, false);
        $this->SetDemandState($roomConfig['demandID'], 0);
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
            if(GetValueInteger($demandID) != $state) SetValueInteger($demandID, $state);
        }
    }
    
    private function ImplementFinalMeasures(bool $isAnyRoomDemandingCooling)
    {
        $statusTextID = $this->ReadPropertyInteger('MainStatusTextLink');

        if ($isAnyRoomDemandingCooling) {
            $this->SwitchSystemOn();
            if ($statusTextID > 0 && @IPS_VariableExists($statusTextID)) @SetValueString($statusTextID, "Cooling Activated");
        } else {
            $this->SwitchSystemOff();
            if ($statusTextID > 0 && @IPS_VariableExists($statusTextID)) @SetValueString($statusTextID, "Cooling DEACTIVATED");
            
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

    private function SwitchSystemOn()
    {
        $this->LogMessage("Main AC System switched ON.", KL_MESSAGE);
        $acID = $this->ReadPropertyInteger('MainACOnOffLink');
        if ($acID > 0) @RequestAction($acID, true);
        $this->SwitchFan(true);
    }

    private function SwitchSystemOff()
    {
        $this->LogMessage("Main AC System switched OFF.", KL_MESSAGE);
        $acID = $this->ReadPropertyInteger('MainACOnOffLink');
        $this->SwitchFan(false);
        if ($acID > 0) @RequestAction($acID, false);
    }
    
    private function SwitchFan(bool $state)
    {
        $fanID = $this->ReadPropertyInteger('MainFanControlLink');
        if ($fanID > 0) @RequestAction($fanID, $state);
    }
}
