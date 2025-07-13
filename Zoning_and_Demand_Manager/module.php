<?php

// This is the minimal version of /var/lib/symcon/modules/HVAC/Air_Control_for_Cooling/module.php
// Its only purpose is to test if the module can be created.

class Zoning_and_Demand_Manager extends IPSModule
{
    public function Create()
    {
        // This is the bare minimum required for a Create function.
        // It calls the parent function from the Symcon SDK.
        parent::Create();
    }

    public function ApplyChanges()
    {
        // This is the bare minimum for ApplyChanges.
        // It calls the parent function and sets the status to active (102).
        parent::ApplyChanges();
        $this->SetStatus(102);
    }
}
