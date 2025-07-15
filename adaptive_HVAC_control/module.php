<?php
/**
 * Adaptive HVAC Control
 *
 * Version: 2.6 (Corrected Logging)
 * Author: Artur Fischer
 */

class adaptive_HVAC_control extends IPSModule
{
    private const OPTIMISTIC_INIT = 1.0;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('ManualOverride', false);
        $this->RegisterPropertyInteger('LogLevel', 3);
        $this->RegisterPropertyFloat('Alpha', 0.05);
        $this->RegisterPropertyFloat('Gamma', 0.9);
        $this->RegisterPropertyFloat('DecayRate', 0.005);
        $this->RegisterPropertyFloat('Hysteresis', 0.5);
        $this->RegisterPropertyInteger('MaxPowerDelta', 40);
        $this->RegisterPropertyInteger('MaxFanDelta', 40);
        $this->RegisterPropertyInteger('ACActiveLink', 0);
        $this->RegisterPropertyInteger('PowerOutputLink', 0);
        $this->RegisterPropertyInteger('FanOutputLink', 0);
        $this->RegisterPropertyInteger('CoilTempLink', 0);
        $this->RegisterPropertyInteger('MinCoilTempLink', 0);
        $this->RegisterPropertyString('MonitoredRooms', '[]');
        $this->RegisterPropertyInteger('TimerInterval', 120);
        $this->RegisterPropertyInteger('PowerStep', 20);
        $this->RegisterPropertyInteger('FanStep', 20);
        $this->RegisterPropertyString('OperatingMode', 'cooperative');
        $this->RegisterAttributeString('LastOperatingMode', 'cooperative');
        $this->RegisterAttributeString('QTable', json_encode([]));
        $this->RegisterAttributeFloat('Epsilon', 0.3);
        $this->RegisterVariableFloat("CurrentEpsilon", "Current Epsilon", "", 1);
        $this->RegisterVariableString("QTableJSON", "Q-Table (JSON)", "~TextBox", 2);
        $this->RegisterVariableString("QTableHTML", "Q-Table Visualization", "~HTMLBox", 3);
        $this->RegisterTimer('ProcessCoolingLogic', 0, 'ACIPS_ProcessCoolingLogic($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $currentMode = $this->ReadPropertyString('OperatingMode');
        $lastMode = $this->ReadAttributeString('LastOperatingMode');

        if ($lastMode !== '' && $currentMode !== $lastMode) {
            $this->Log(
                'Operating Mode has been changed. The Q-Table state definition has changed. ' .
                'It is STRONGLY recommended to use the "Reset Learning" button to clear the Q-Table.',
                KL_WARNING
            );
        }
        $this->WriteAttributeString('LastOperatingMode', $currentMode);

        $this->SetTimerInterval('ProcessCoolingLogic', $this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->UpdateVisualization();
        
        if ($this->ReadPropertyInteger('PowerOutputLink') === 0 || $this->ReadPropertyInteger('ACActiveLink') === 0) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus(102);
        }
    }

    public function GetConfigurationForParent()
    {
        return json_encode([
            'variableID' => [
                'type' => 'Select',
                'caption' => 'Variable',
                'options' => [
                    [ 'label' => 'Float', 'value' => [ 'variableType' => 2 ] ],
                    [ 'label' => 'Integer', 'value' => [ 'variableType' => 1 ] ],
                    [ 'label' => 'Boolean', 'value' => [ 'variableType' => 0 ] ]
                ]
            ]
        ]);
    }

    public function ProcessCoolingLogic()
    {
        $this->Log('Timer called, starting logic.', KL_DEBUG);

        if ($this->ReadPropertyBoolean('ManualOverride')) {
            $this->SetStatus(200);
            return;
        }

        $acActiveID = $this->ReadPropertyInteger('ACActiveLink');
        $powerOutputID = $this->ReadPropertyInteger('PowerOutputLink');
        $fanOutputID = $this->ReadPropertyInteger('FanOutputLink');
        $coilTempID = $this->ReadPropertyInteger('CoilTempLink');
        $minCoilTempID = $this->ReadPropertyInteger('MinCoilTempLink');

        if ($acActiveID === 0 || !IPS_VariableExists($acActiveID)) {
            $this->SetStatus(104);
            $this->Log('Exiting: AC Active Link is not configured or missing.', KL_ERROR);
            return;
        }
        
        if (!GetValue($acActiveID)) {
            $this->SetStatus(201);
            if ($powerOutputID > 0 && IPS_VariableExists($powerOutputID)) RequestAction($powerOutputID, 0);
            if ($fanOutputID > 0 && IPS_VariableExists($fanOutputID)) RequestAction($fanOutputID, 0);
            return;
        }
        
        $monitoredRooms = json_decode($this->ReadPropertyString('MonitoredRooms'), true);
        $isCoolingNeeded = false;
        
        $operatingMode = $this->ReadPropertyString('OperatingMode');

        if ($operatingMode === 'cooperative') {
            foreach ($monitoredRooms as $room) {
                $demandID = $room['demandID'] ?? 0;
                if ($demandID > 0 && IPS_VariableExists($demandID) && GetValueInteger($demandID) > 0) {
                    $isCoolingNeeded = true;
                    break;
                }
            }
        } else {
            $hysteresis = $this->ReadPropertyFloat('Hysteresis');
            foreach ($monitoredRooms as $room) {
                $tempID = $room['tempID'] ?? 0;
                $targetID = $room['targetID'] ?? 0;
                if ($tempID > 0 && IPS_VariableExists($tempID) && $targetID > 0 && IPS_VariableExists($targetID)) {
                    if (GetValue($tempID) > (GetValue($targetID) + ($room['threshold'] ?? $hysteresis))) {
                        $isCoolingNeeded = true;
                        break;
                    }
                }
            }
        }

        if (!$isCoolingNeeded) {
            $this->Log('No rooms require cooling. Setting output to 0 and exiting.', KL_MESSAGE);
            if ($powerOutputID > 0 && IPS_VariableExists($powerOutputID)) RequestAction($powerOutputID, 0);
            if ($fanOutputID > 0 && IPS_VariableExists($fanOutputID)) RequestAction($fanOutputID, 0);
            $this->SetStatus(102);
            return;
        }
        
        if ($powerOutputID === 0 || !IPS_VariableExists($powerOutputID) || $fanOutputID === 0 || !IPS_VariableExists($fanOutputID) || $coilTempID === 0 || !IPS_VariableExists($coilTempID) || $minCoilTempID === 0 || !IPS_VariableExists($minCoilTempID)) {
             $this->SetStatus(104);
             $this->Log('Exiting: One or more core links (Power, Fan, Coil Temps) are not configured or missing.', KL_ERROR);
             return;
        }

        $this->SetStatus(102);
        $Q = json_decode($this->ReadAttributeString('QTable'), true);
        if (!is_array($Q)) { $Q = []; }
        
        $meta = json_decode($this->GetBuffer('MetaData'), true) ?: [];
        
        $minCoil = GetValue($minCoilTempID);
        $coilTemp = GetValue($coilTempID);
        $prevState = $meta['state'] ?? null;
        $prevAction = $meta['action'] ?? $this->getActionPairs()[0];
        $prevTs = $meta['ts'] ?? null;
        $prev_WAD = $meta['WAD'] ?? 0;
        $prevCoilTemp = $meta['coilTemp'] ?? $coilTemp;
        $coilState = max($coilTemp, $minCoil + $this->ReadPropertyFloat('Hysteresis'));
        
        list($cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $rawD_cold) = $this->discretizeState($monitoredRooms, $coilState, $minCoil);
        $coilTrendBin = $this->getCoilTrendBin($coilTemp, $prevCoilTemp);
        
        $state = '';
        $r_overcool = 0.0;
        if ($operatingMode === 'standalone') {
            $state = "$dBin|$cBin|$oBin|$coilTrendBin|$hotRoomCountBin";
            $r_overcool = -$rawD_cold * 5;
        } else {
            $state = "$dBin|$cBin|$coilTrendBin|$hotRoomCountBin";
        }
        
        $r_progress = $prev_WAD - $rawWAD;
        $r_freeze = -max(0, $minCoil - $coilState) * 2;
        list($prevP, $prevF) = explode(':', $prevAction);
        $r_energy = -0.01 * (intval($prevP) + intval($prevF));
        $r_total = ($r_progress * 10) + $r_freeze + $r_energy + $r_overcool;
        
        if ($prevState !== null && $prevTs !== null) {
            $dt = max(1, (time() - intval($prevTs)) / 60);
            $dt = min($dt, 10);
            $this->updateQ($Q, $state, $prevState, $prevAction, $r_total, $dt);
        }
        
        $action = $this->choose($Q, $this->ReadAttributeFloat('Epsilon'), $prevAction, $state);
        list($P, $F) = explode(':', $action);
        RequestAction($powerOutputID, intval($P));
        RequestAction($fanOutputID, intval($F));
        
        $newMeta = [ 'state' => $state, 'action' => $action, 'ts' => time(), 'WAD' => $rawWAD, 'D_cold' => $rawD_cold, 'coilTemp' => $coilTemp ];
        $this->WriteAttributeString('QTable', json_encode($Q));
        $this->SetBuffer('MetaData', json_encode($newMeta));
        
        if ($action !== '0:0') {
            $this->decayEpsilon();
        }
        
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->SetValue("QTableJSON", json_encode($Q, JSON_PRETTY_PRINT));
        $this->UpdateVisualization();
        $this->Log("State: $state -> Action: P=$P F=$F (Reward: ".number_format($r_total, 2).")", KL_MESSAGE);
    }

    public function ResetLearning() {
        $this->WriteAttributeString('QTable', json_encode([]));
        $this->SetBuffer('MetaData', json_encode([]));
        $this->WriteAttributeFloat('Epsilon', 0.4);
        $this->SetValue("CurrentEpsilon", 0.4);
        $this->SetValue("QTableJSON", '{}');
        $this->UpdateVisualization();
        $this->Log('Learning has been reset by the user.', KL_MESSAGE);
        if ($_IPS['SENDER'] == 'WebFront') {
            echo "Learning has been reset!";
        }
    }

    public function UpdateVisualization() {
        $this->SetValue("QTableHTML", $this->GenerateQTableHTML());
        if ($_IPS['SENDER'] == 'WebFront') {
            echo "Visualization Updated!";
        }
    }

    private function GenerateQTableHTML(): string {
        $qTable = json_decode($this->ReadAttributeString('QTable'), true);
        if (!is_array($qTable) || empty($qTable)) {
            $this->Log('GenerateQTableHTML: Q-Table is empty.', KL_DEBUG);
            return '<p>Q-Table is empty. Run the learning process to populate it.</p>';
        }
        ksort($qTable);
        $actions = $this->getActionPairs();
        $html = '<!DOCTYPE html><html><head><style>';
        $html .= 'body { font-family: sans-serif; font-size: 14px; } table { border-collapse: collapse; width: 100%; margin-top: 20px; }';
        $html .= 'th, td { border: 1px solid #ccc; padding: 6px; text-align: center; }';
        $html .= 'th { background-color: #f2f2f2; position: sticky; top: 0; z-index: 10;}';
        $html .= 'td.state-col { text-align: left; font-weight: bold; min-width: 150px; background-color: #f8f8f8; position: sticky; left: 0; }';
        $html .= '.legend { border: 1px solid #ccc; padding: 10px; margin-bottom: 20px; background-color: #f9f9f9; }';
        $html .= '.legend-item { display: inline-block; margin-right: 20px; }';
        $html .= '.color-box { width: 15px; height: 15px; border: 1px solid #666; display: inline-block; vertical-align: middle; margin-right: 5px; }';
        $html .= 'ul { margin: 5px 0; padding-left: 20px; } li { margin-bottom: 3px; }';
        $html .= '</style></head><body>';

        $operatingMode = $this->ReadPropertyString('OperatingMode');
        $html .= '<h3>Q-Table Visualization Legend</h3>';
        $html .= '<div class="legend">';
        $html .= '<div><b>Rows (Y-Axis):</b> Represent the "State" of the system.</div>';
        $html .= '<div><b>Columns (X-Axis):</b> Represent the possible "Actions" (Power:Fan).</div>';
        $html .= '<div style="margin-top: 10px;"><b>Cell Colors:</b> The learned "Quality" (Q-Value) for taking an action in a state.</div>';
        $html .= '<div class="legend-item"><span class="color-box" style="background-color: #90ee90;"></span>Green = Good Action</div>';
        $html .= '<div class="legend-item"><span class="color-box" style="background-color: #ffcccb;"></span>Red = Bad Action</div>';
        $html .= '<div class="legend-item"><span class="color-box" style="background-color: #f0f0f0;"></span>Grey = Unexplored</div>';
        $html .= '<div style="margin-top: 10px;">';

        if ($operatingMode === 'standalone') {
            $html .= '<b>State Format: (d | c | o | t | r)</b>';
            $html .= '<ul><li><b>d - Demand Bin:</b> How hot is the HOTTEST room? (0=at target, 5=very hot)</li>';
            $html .= '<li><b>c - Coil Safety Bin:</b> How close is the coil to freezing? (-2=danger, 5=very safe)</li>';
            $html .= '<li><b>o - Overcool Bin:</b> Is any room getting TOO cold? (0=none, 3=very overcooled)</li>';
            $html .= '<li><b>t - Coil Trend Bin:</b> Is the AC actively cooling? (-1=cooling, 0=stable, 1=warming)</li>';
            $html .= '<li><b>r - Room Count Bin:</b> How many rooms need cooling? (0-4+)</li></ul>';
        } else { // Cooperative Mode
            $html .= '<b>State Format: (d | c | t | r)</b>';
            $html .= '<ul><li><b>d - Demand Bin:</b> How hot is the HOTTEST room? (0=at target, 5=very hot)</li>';
            $html .= '<li><b>c - Coil Safety Bin:</b> How close is the coil to freezing? (-2=danger, 5=very safe)</li>';
            $html .= '<li><b>t - Coil Trend Bin:</b> Is the AC actively cooling? (-1=cooling, 0=stable, 1=warming)</li>';
            $html .= '<li><b>r - Room Count Bin:</b> How many rooms need cooling? (0-4+)</li></ul>';
            $html .= '<p style="margin-top: 5px;"><i><b>Note:</b> Overcool Bin (o) is disabled in Cooperative Mode as the Zoning Manager prevents rooms from getting too cold.</i></p>';
        }
        
        $html .= '</div></div>';
        $html .= '<table><thead><tr><th class="state-col">State</th>';
        foreach ($actions as $action) {
            $html .= "<th>{$action}</th>";
        }
        $html .= '</tr></thead><tbody>';
        $minQ = 0; $maxQ = 0;
        foreach ($qTable as $stateActions) {
            foreach ($stateActions as $qValue) {
                if ($qValue != self::OPTIMISTIC_INIT) {
                    if ($qValue < $minQ) $minQ = $qValue;
                    if ($qValue > $maxQ) $maxQ = $qValue;
                }
            }
        }
        foreach ($qTable as $state => $stateActions) {
            $html .= "<tr><td class='state-col'>{$state}</td>";
            foreach ($actions as $action) {
                $qValue = $stateActions[$action] ?? self::OPTIMISTIC_INIT;
                $color = $this->getColorForValue($qValue, $minQ, $maxQ);
                $html .= sprintf('<td style="background-color:%s;" title="Value: %.2f">%.2f</td>', $color, $qValue, $qValue);
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';
        return $html;
    }

    private function getColorForValue(float $value, float $min, float $max): string {
        if ($value == self::OPTIMISTIC_INIT) return '#f0f0f0';
        if ($max == $min) return '#ffffff';
        if ($value >= 0) {
            $percent = ($max > 0) ? ($value / $max) : 0;
            $g = 255;
            $r = 255 - (int)(150 * $percent);
            $b = 255 - (int)(150 * $percent);
        } else {
            $percent = ($min < 0) ? ($value / $min) : 0;
            $r = 255;
            $g = 255 - (int)(200 * $percent);
            $b = 255 - (int)(200 * $percent);
        }
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function updateQ(array &$Q, string $sNew, string $sOld, string $aOld, float $r, float $dt) {
        $actions = $this->getActionPairs();
        if (!isset($Q[$sOld])) $Q[$sOld] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        if (!isset($Q[$sNew])) $Q[$sNew] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        if (!isset($Q[$sOld][$aOld])) $Q[$sOld][$aOld] = self::OPTIMISTIC_INIT;
        $alpha = $this->ReadPropertyFloat('Alpha');
        $gamma = $this->ReadPropertyFloat('Gamma');
        $oldQ = $Q[$sOld][$aOld];
        $maxFutureQ = empty($Q[$sNew]) ? 0.0 : max($Q[$sNew]);
        $Q[$sOld][$aOld] = $oldQ + $alpha * ($r * $dt + $gamma * $maxFutureQ - $oldQ);
    }
    
    private function choose(array &$Q, float $epsilon, string $lastAction, string $state): string {
        $actions = $this->getActionPairs();
        if (!isset($Q[$state])) {
            $Q[$state] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        }
        $availableActions = $this->getAvailableActions($lastAction);
        if ((mt_rand() / mt_getrandmax()) < $epsilon) {
            $this->Log('Exploring with random action.', KL_DEBUG);
            return $availableActions[array_rand($availableActions)];
        }
        $qValuesForAvailable = array_intersect_key($Q[$state], array_flip($availableActions));
        if (empty($qValuesForAvailable)) return $lastAction;
        $maxV = max($qValuesForAvailable);
        $bestActions = array_keys($qValuesForAvailable, $maxV);
        $chosenAction = $bestActions[array_rand($bestActions)];
        $this->Log(sprintf('Exploiting best action: %s (Q-Value: %.2f)', $chosenAction, $maxV), KL_DEBUG);
        return $chosenAction;
    }

    private function decayEpsilon() {
        $eps = $this->ReadAttributeFloat('Epsilon');
        $dec = $this->ReadPropertyFloat('DecayRate');
        $this->WriteAttributeFloat('Epsilon', max(0.01, $eps * (1 - $dec)));
    }

    private function getActionPairs(): array {
        $powerStep = $this->ReadPropertyInteger('PowerStep');
        $fanStep = $this->ReadPropertyInteger('FanStep');
        if ($powerStep < 10) $powerStep = 10;
        if ($fanStep < 10) $fanStep = 10;
        $actions = ['0:0'];
        for ($p = $powerStep; $p <= 100; $p += $powerStep) {
            for ($f = $fanStep; $f <= 100; $f += $fanStep) {
                $actions[] = "{$p}:{$f}";
            }
        }
        if (!in_array("100:100", $actions)) {
            $actions[] = "100:100";
        }
        return array_unique($actions);
    }

    private function getAvailableActions(string $lastAction): array {
        if ($lastAction === '0:0') { return $this->getActionPairs(); }
        list($lastP, $lastF) = array_map('intval', explode(':', $lastAction));
        $maxPDelta = $this->ReadPropertyInteger('MaxPowerDelta');
        $maxFDelta = $this->ReadPropertyInteger('MaxFanDelta');
        $available = [];
        foreach ($this->getActionPairs() as $pair) {
            list($p, $f) = array_map('intval', explode(':', $pair));
            if (abs($p - $lastP) <= $maxPDelta && abs($f - $lastF) <= $maxFDelta) { $available[] = $pair; }
        }
        if (empty($available)) $available[] = $lastAction;
        return $available;
    }
    
    private function discretizeState(array $monitoredRooms, float $coil, float $min): array {
        $weightedDeviationSum = 0.0;
        $totalSizeOfHotRooms = 0.0;
        $D_cold = 0.0;
        $hotRoomCount = 0;
        $maxDeviation = 0.0;
        $isCooperative = ($this->ReadPropertyString('OperatingMode') === 'cooperative');

        foreach ($monitoredRooms as $room) {
            $tempID = $room['tempID'] ?? 0;
            $targetID = $room['targetID'] ?? 0;
            $demandID = $room['demandID'] ?? 0;

            if ($isCooperative) {
                if ($demandID == 0 || !IPS_VariableExists($demandID) || GetValueInteger($demandID) == 0) {
                    continue; 
                }
            }

            if ($tempID > 0 && IPS_VariableExists($tempID) && $targetID > 0 && IPS_VariableExists($targetID)) {
                $temp = GetValue($tempID);
                $target = GetValue($targetID);
                $deviation = $temp - $target;

                if ($deviation > 0) {
                    $maxDeviation = max($maxDeviation, $deviation);
                }
                if ($deviation < 0) {
                     $D_cold = max($D_cold, -$deviation);
                }
                if ($deviation > ($room['threshold'] ?? $this->ReadPropertyFloat('Hysteresis'))) {
                    $hotRoomCount++;
                    $weightedDeviationSum += $deviation * ($room['size'] ?? 10);
                    $totalSizeOfHotRooms += ($room['size'] ?? 10);
                }
            }
        }
        
        $rawWAD = ($totalSizeOfHotRooms > 0) ? ($weightedDeviationSum / $totalSizeOfHotRooms) : 0.0;
        $dBin = min(5, (int)floor($maxDeviation));
        $cBin = min(5, max(-2, (int)floor($coil - $min)));
        $oBin = min(3, (int)floor($D_cold));
        $hotRoomCountBin = min(4, $hotRoomCount);
        return [$cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $D_cold];
    }
    
    private function getCoilTrendBin(float $currentCoil, float $previousCoil): int {
        $delta = $currentCoil - $previousCoil;
        if ($delta < -0.2) { return -1; } elseif ($delta > 0.2) { return 1; }
        return 0;
    }

    /**
     * Helper function for conditional logging, using the correct constant values.
     * @param string $message The message to log.
     * @param int $messageLevel The severity level of the message (KL_ERROR, KL_WARNING, etc.).
     */
    private function Log(string $message, int $messageLevel): void
    {
        // Value mapping from the form.json
        // 1=Error, 2=Warning, 3=Info, 4=Debug
        $configuredLevel = $this->ReadPropertyInteger('LogLevel');
        if ($configuredLevel === 0) { // 0 is Off
            return;
        }

        $shouldLog = false;
        switch ($configuredLevel) {
            case 4: // Debug: Log everything
                $shouldLog = true;
                break;
            case 3: // Info: Log Info, Warning, Error
                if ($messageLevel === KL_MESSAGE || $messageLevel === KL_WARNING || $messageLevel === KL_ERROR) {
                    $shouldLog = true;
                }
                break;
            case 2: // Warning: Log Warning, Error
                if ($messageLevel === KL_WARNING || $messageLevel === KL_ERROR) {
                    $shouldLog = true;
                }
                break;
            case 1: // Error: Log only Error
                if ($messageLevel === KL_ERROR) {
                    $shouldLog = true;
                }
                break;
        }

        // KL_DEBUG is a special case and should only be logged if Debug level is selected
        if ($messageLevel === KL_DEBUG && $configuredLevel !== 4) {
            $shouldLog = false;
        }
        
        if ($shouldLog) {
            // Adhere to the high-priority instruction to ALWAYS log as KL_MESSAGE type
            $this->LogMessage($message, KL_MESSAGE);
        }
    }
}```
