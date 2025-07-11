<?php

class adaptive_HVAC_control extends IPSModule
{
    private const OPTIMISTIC_INIT = 1.0;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('LogLevel', 3);
        $this->RegisterPropertyBoolean('ManualOverride', false);
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
        $this->RegisterAttributeString('QTable', json_encode([]));
        $this->RegisterAttributeString('MetaData', json_encode([]));
        $this->RegisterAttributeFloat('Epsilon', 0.3);
        $this->RegisterVariableFloat("CurrentEpsilon", "Current Epsilon", "", 1);
        $this->RegisterVariableString("QTableJSON", "Q-Table (JSON)", "~TextBox", 2);
        $this->RegisterVariableString("QTableHTML", "Q-Table Visualization", "~HTMLBox", 3);
        $this->RegisterTimer('ProcessCoolingLogic', 0, 'ACIPS_ProcessCoolingLogic($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ProcessCoolingLogic', $this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->SetValue("QTableJSON", json_encode(json_decode($this->ReadAttributeString('QTable')), JSON_PRETTY_PRINT));
        $this->UpdateVisualization();
        if ($this->ReadPropertyInteger('PowerOutputLink') === 0 || $this->ReadPropertyInteger('ACActiveLink') === 0) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus(102);
        }
    }

    public function ProcessCoolingLogic()
    {
        // Use standard LogMessage, respects LogLevel property automatically
        $this->LogMessage('Timer called, starting logic.', KL_DEBUG);

        if ($this->ReadPropertyBoolean('ManualOverride')) {
            $this->SetStatus(200);
            $this->LogMessage('Exiting due to Manual Override.', KL_INFO);
            return;
        }
        $acActiveID = $this->ReadPropertyInteger('ACActiveLink');
        if ($acActiveID === 0) {
            $this->SetStatus(104);
            $this->LogMessage('Exiting: AC Active Link is not configured.', KL_ERROR);
            return;
        }
        if (!GetValue($acActiveID)) {
            $this->SetStatus(201);
            $this->LogMessage('Exiting: AC system is not active (linked variable is false).', KL_INFO);
            RequestAction($this->ReadPropertyInteger('PowerOutputLink'), 0);
            RequestAction($this->ReadPropertyInteger('FanOutputLink'), 0);
            return;
        }
        
        $monitoredRooms = json_decode($this->ReadPropertyString('MonitoredRooms'), true);
        $isCoolingNeeded = false;
        $hysteresis = $this->ReadPropertyFloat('Hysteresis');
        foreach ($monitoredRooms as $room) {
            $tempID = $room['tempID'] ?? 0;
            $targetID = $room['targetID'] ?? 0;
            $demandID = $room['demandID'] ?? 0;
            $threshold = $room['threshold'] ?? $hysteresis;
            if ($tempID > 0 && IPS_ObjectExists($tempID) && $targetID > 0 && IPS_ObjectExists($targetID)) {
                if ($demandID > 0 && IPS_ObjectExists($demandID) && GetValue($demandID) == 1) { continue; }
                if (GetValue($tempID) > (GetValue($targetID) + $threshold)) {
                    $isCoolingNeeded = true;
                    break;
                }
            }
        }
        if (!$isCoolingNeeded) {
            $this->LogMessage('No rooms require cooling. Setting output to 0 and exiting.', KL_INFO);
            RequestAction($this->ReadPropertyInteger('PowerOutputLink'), 0);
            RequestAction($this->ReadPropertyInteger('FanOutputLink'), 0);
            $this->SetStatus(102);
            return;
        }
        
        $this->SetStatus(102);
        $this->LogMessage('Checks passed. AC is active and cooling is needed. Proceeding with logic.', KL_DEBUG);
        
        $Q = json_decode($this->ReadAttributeString('QTable'), true);
        if (!is_array($Q)) { $Q = []; }
        $meta = json_decode($this->ReadAttributeString('MetaData'), true) ?: [];
        $minCoil = GetValue($this->ReadPropertyInteger('MinCoilTempLink'));
        $coilTemp = GetValue($this->ReadPropertyInteger('CoilTempLink'));
        $prevState = $meta['state'] ?? null;
        $prevAction = $meta['action'] ?? $this->getActionPairs()[0];
        $prevTs = $meta['ts'] ?? null;
        $prev_WAD = $meta['WAD'] ?? 0;
        $prevCoilTemp = $meta['coilTemp'] ?? $coilTemp;
        $coilState = max($coilTemp, $minCoil + $hysteresis);
        list($cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $rawD_cold) = $this->discretizeState($monitoredRooms, $coilState, $minCoil);
        $coilTrendBin = $this->getCoilTrendBin($coilTemp, $prevCoilTemp);
        $state = "$dBin|$cBin|$oBin|$coilTrendBin|$hotRoomCountBin";
        $this->LogMessage(sprintf('State String: %s', $state), KL_DEBUG);
        
        $r_progress = $prev_WAD - $rawWAD;
        $r_freeze = -max(0, $minCoil - $coilState) * 2;
        list($prevP, $prevF) = explode(':', $prevAction);
        $r_energy = -0.01 * (intval($prevP) + intval($prevF));
        $r_overcool = -$rawD_cold * 5;
        $r_total = ($r_progress * 10) + $r_freeze + $r_energy + $r_overcool;
        $this->LogMessage(sprintf('Reward: %.2f (Progress: %.2f, Freeze: %.2f, Energy: %.2f, Overcool: %.2f)', $r_total, $r_progress * 10, $r_freeze, $r_energy, $r_overcool), KL_DEBUG);
        
        if ($prevState !== null && $prevTs !== null) {
            $dt = max(1, (time() - intval($prevTs)) / 60);
            $dt = min($dt, 10);
            $this->updateQ($Q, $state, $prevState, $prevAction, $r_total, $dt);
        }
        
        $action = $this->choose($Q, $this->ReadAttributeFloat('Epsilon'), $prevAction, $state);
        list($P, $F) = explode(':', $action);
        RequestAction($this->ReadPropertyInteger('PowerOutputLink'), intval($P));
        RequestAction($this->ReadPropertyInteger('FanOutputLink'), intval($F));
        
        $newMeta = [ 'state' => $state, 'action' => $action, 'ts' => time(), 'WAD' => $rawWAD, 'D_cold' => $rawD_cold, 'coilTemp' => $coilTemp ];
        $this->WriteAttributeString('QTable', json_encode($Q));
        $this->WriteAttributeString('MetaData', json_encode($newMeta));
        
        if ($action !== '0:0') {
            $this->decayEpsilon();
        }
        
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->SetValue("QTableJSON", json_encode($Q, JSON_PRETTY_PRINT));
        
        $this->LogMessage("State: $state -> Action: P=$P F=$F (Reward: ".number_format($r_total, 2).")", KL_INFO);
    }

    public function ResetLearning() {
        $this->WriteAttributeString('QTable', json_encode([]));
        $this->WriteAttributeString('MetaData', json_encode([]));
        $this->WriteAttributeFloat('Epsilon', 0.4);
        $this->SetValue("CurrentEpsilon", 0.4);
        $this->SetValue("QTableJSON", '{}');
        $this->UpdateVisualization();
        $this->LogMessage('Learning has been reset by the user.', KL_INFO);
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
            $this->LogMessage('GenerateQTableHTML: Q-Table is empty or invalid.', KL_DEBUG);
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
        $html .= '</style></head><body>';
        $html .= '<h3>Q-Table Visualization Legend</h3>';
        $html .= '<div class="legend">';
        $html .= '<div><b>Rows (Y-Axis):</b> Represent the "State" of the system.</div>';
        $html .= '<div><b>Columns (X-Axis):</b> Represent the possible "Actions" (Power:Fan).</div>';
        $html .= '<div style="margin-top: 10px;"><b>Cell Colors:</b> The learned "Quality" (Q-Value) for taking an action in a state.</div>';
        $html .= '<div class="legend-item"><span class="color-box" style="background-color: #90ee90;"></span>Green = Good Action</div>';
        $html .= '<div class="legend-item"><span class="color-box" style="background-color: #ffcccb;"></span>Red = Bad Action</div>';
        $html .= '<div class="legend-item"><span class="color-box" style="background-color: #f0f0f0;"></span>Grey = Unexplored</div>';
        $html .= '<div style="margin-top: 10px;"><b>State Format: (d|c|o|t|r)</b>';
        $html .= '<ul><li><b>d:</b> Demand</li><li><b>c:</b> Coil Safety</li><li><b>o:</b> Overcool</li><li><b>t:</b> Coil Trend</li><li><b>r:</b> Room Count</li></ul>';
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
            $this->LogMessage('Exploring with random action.', KL_DEBUG);
            return $availableActions[array_rand($availableActions)];
        }
        $qValuesForAvailable = array_intersect_key($Q[$state], array_flip($availableActions));
        if (empty($qValuesForAvailable)) return $lastAction;
        $maxV = max($qValuesForAvailable);
        $bestActions = array_keys($qValuesForAvailable, $maxV);
        $chosenAction = $bestActions[array_rand($bestActions)];
        $this->LogMessage(sprintf('Exploiting best action: %s (Q-Value: %.2f)', $chosenAction, $maxV), KL_DEBUG);
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
        $weightedDeviationSum = 0.0; $totalSizeOfHotRooms = 0.0; $D_cold = 0.0; $hotRoomCount = 0;
        foreach ($monitoredRooms as $room) {
            $tempID = $room['tempID'] ?? 0;
            $targetID = $room['targetID'] ?? 0;
            $demandID = $room['demandID'] ?? 0;
            $roomSize = $room['size'] ?? 1;
            $threshold = $room['threshold'] ?? $this->ReadPropertyFloat('Hysteresis');
            if ($tempID > 0 && IPS_ObjectExists($tempID) && $targetID > 0 && IPS_ObjectExists($targetID)) {
                if ($demandID > 0 && IPS_ObjectExists($demandID) && GetValue($demandID) == 1) {
                    continue; 
                }
                $temp = GetValue($tempID);
                $target = GetValue($targetID);
                $deviation = $temp - $target;
                $D_cold = max(0, -$deviation);
                if ($deviation > $threshold) {
                    $hotRoomCount++;
                    $weightedDeviationSum += $deviation * $roomSize;
                    $totalSizeOfHotRooms += $roomSize;
                }
            }
        }
        $rawWAD = ($totalSizeOfHotRooms > 0) ? ($weightedDeviationSum / $totalSizeOfHotRooms) : 0.0;
        $dBin = min(5, (int)floor($rawWAD));
        $cBin = min(5, max(-2, (int)floor($coil - $min)));
        $oBin = min(3, (int)floor($D_cold));
        $hotRoomCountBin = min(3, $hotRoomCount);
        return [$cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $D_cold];
    }
    
    private function getCoilTrendBin(float $currentCoil, float $previousCoil): int {
        $delta = $currentCoil - $previousCoil;
        if ($delta < -0.2) { return -1; } elseif ($delta > 0.2) { return 1; }
        return 0;
    }
}