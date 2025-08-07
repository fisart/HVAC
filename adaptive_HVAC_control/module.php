<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       3.3 (Final Hardened Version)
 * @date          2025-08-07
 */

class adaptive_HVAC_control extends IPSModule
{
    private const OPTIMISTIC_INIT = 1.0;

    // --- IPS Core Functions ---
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('ManualOverride', false);
        $this->RegisterPropertyInteger('LogLevel', 3);
        $this->RegisterPropertyFloat('Alpha', 0.1);
        $this->RegisterPropertyFloat('Gamma', 0.9);
        $this->RegisterPropertyFloat('DecayRate', 0.001);
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
        $this->RegisterPropertyString('CustomPowerLevels', '30,55,75,100');
        $this->RegisterPropertyInteger('FanStep', 20);
        $this->RegisterPropertyString('CustomFanSpeeds', '');
        $this->RegisterPropertyString('OperatingMode', 'cooperative');
        $this->RegisterAttributeString('LastOperatingMode', 'cooperative');
        $this->RegisterAttributeString('QTable', json_encode([]));
        $this->RegisterAttributeFloat('Epsilon', 0.4);
        $this->RegisterVariableFloat("CurrentEpsilon", "Current Epsilon", "", 1);
        $this->RegisterVariableString("QTableJSON", "Q-Table (JSON)", "~TextBox", 2);
        $this->RegisterVariableString("QTableHTML", "Q-Table Visualization", "~HTMLBox", 3);
        $this->RegisterTimer('ProcessCoolingLogic', 0, 'ACIPS_ProcessCoolingLogic($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) return;
        
        $currentMode = $this->ReadPropertyString('OperatingMode');
        if ($this->ReadAttributeString('LastOperatingMode') !== $currentMode) {
            $this->Log('Operating Mode has changed. It is STRONGLY recommended to reset learning.', KL_WARNING);
            $this->WriteAttributeString('LastOperatingMode', $currentMode);
        }
        
        $this->SetTimerInterval('ProcessCoolingLogic', ($currentMode === 'orchestrated') ? 0 : $this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->UpdateVisualization();
        $this->SetStatus(($this->ReadPropertyInteger('PowerOutputLink') === 0 || $this->ReadPropertyInteger('ACActiveLink') === 0) ? 104 : 102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $customPowerLevels = $this->ReadPropertyString('CustomPowerLevels');
        $customFanSpeeds = $this->ReadPropertyString('CustomFanSpeeds');
        
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'PowerStep') $element['visible'] = empty($customPowerLevels);
            if ($element['type'] === 'ExpansionPanel') {
                foreach ($element['items'] as &$item) {
                    if (isset($item['name']) && $item['name'] == 'FanStep') $item['visible'] = empty($customFanSpeeds);
                }
            }
        }
        return json_encode($form);
    }
    
    // --- Public API Functions ---

    public function ProcessCoolingLogic()
    {
        if ($this->ReadPropertyString('OperatingMode') === 'orchestrated' || $this->ReadPropertyBoolean('ManualOverride')) return;
        $this->ExecuteLearningCycle(null);
    }

    public function ResetLearning()
    {
        $this->WriteAttributeString('QTable', json_encode([]));
        $this->SetBuffer('MetaData', json_encode([]));
        $this->WriteAttributeFloat('Epsilon', 0.4);
        $this->UpdateVisualization();
        $this->Log('Learning has been reset by the user.', KL_MESSAGE);
    }
    
    public function UpdateVisualization()
    {
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->SetValue("QTableJSON", $this->ReadAttributeString('QTable'));
        $this->SetValue("QTableHTML", $this->GenerateQTableHTML());
    }

    public function GetActionPairs(): string
    {
        return json_encode($this->_getActionPairs());
    }

    public function SetMode(string $mode)
    {
        IPS_SetProperty($this->InstanceID, 'OperatingMode', $mode);
        if (IPS_HasChanges($this->InstanceID)) IPS_ApplyChanges($this->InstanceID);
    }

    public function ForceActionAndLearn(string $forcedAction): string
    {
        if ($this->ReadPropertyString('OperatingMode') !== 'orchestrated') {
            return json_encode(['error' => 'Not in Orchestrated mode']);
        }
        return json_encode($this->ExecuteLearningCycle($forcedAction));
    }

    // --- Core Q-Learning Engine ---

    private function ExecuteLearningCycle(?string $forcedAction): array
    {
        // 1. Check Preconditions
        $acActiveID = $this->ReadPropertyInteger('ACActiveLink');
        if ($acActiveID === 0 || !IPS_VariableExists($acActiveID) || !GetValue($acActiveID)) {
            return $this->ShutdownSystem(['state' => 'inactive', 'reward' => 0]);
        }
        
        // 2. Assess Cooling Need
        $monitoredRooms = json_decode($this->ReadPropertyString('MonitoredRooms'), true);
        if (!$this->IsCoolingNeeded($monitoredRooms) && $forcedAction === null) {
            return $this->ShutdownSystem(['state' => 'idle', 'reward' => 0]);
        }
        
        // 3. Get State
        $Q = json_decode($this->ReadAttributeString('QTable'), true);
        $meta = json_decode($this->GetBuffer('MetaData'), true) ?: [];
        $currentState = $this->getCurrentState($monitoredRooms, $meta);

        // 4. Calculate Reward & Update Q-Table
        $reward = 0;
        if (isset($meta['state']) && isset($meta['action'])) {
            $reward = $this->calculateReward($currentState, $meta);
            $this->updateQ($Q, $currentState['string'], $meta['state'], $meta['action'], $reward, $meta['ts'] ?? time());
        }
        
        // 5. Choose and Execute Action
        $action = $forcedAction ?? $this->chooseAction($Q, $meta['action'] ?? '0:0', $currentState['string']);
        list($P, $F) = array_map('intval', explode(':', $action));
        RequestAction($this->ReadPropertyInteger('PowerOutputLink'), $P);
        RequestAction($this->ReadPropertyInteger('FanOutputLink'), $F);
        
        // 6. Persist State
        $this->WriteAttributeString('QTable', json_encode($Q));
        $this->SetBuffer('MetaData', json_encode(['state' => $currentState['string'], 'action' => $action, 'ts' => time(), 'rawWAD' => $currentState['rawWAD'], 'coilTemp' => $currentState['coilTemp']]));
        if ($action !== '0:0' && $forcedAction === null) $this->decayEpsilon();
        
        $this->Log("State: {$currentState['string']} -> Action: $action (Reward: ".number_format($reward, 2).")", KL_MESSAGE);
        return ['state' => $currentState['string'], 'reward' => $reward];
    }
    
    // --- Helper Functions for Q-Learning ---

    private function getCurrentState(array $rooms, array $meta): array
    {
        $coilTemp = GetValue($this->ReadPropertyInteger('CoilTempLink'));
        $minCoil = GetValue($this->ReadPropertyInteger('MinCoilTempLink'));
        
        list($cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD) = $this->discretizeRoomState($rooms);
        $coilTrendBin = $this->getCoilTrendBin($coilTemp, $meta['coilTemp'] ?? $coilTemp);
        
        $stateString = ($this->ReadPropertyString('OperatingMode') === 'standalone')
            ? "$dBin|$cBin|$oBin|$coilTrendBin|$hotRoomCountBin"
            : "$dBin|$cBin|$coilTrendBin|$hotRoomCountBin";
            
        return ['string' => $stateString, 'rawWAD' => $rawWAD, 'coilTemp' => $coilTemp, 'minCoil' => $minCoil];
    }
    
    private function calculateReward(array $currentState, array $meta): float
    {
        list($prevP, $prevF) = array_map('intval', explode(':', $meta['action']));
        $r_progress = ($meta['rawWAD'] ?? 0) - $currentState['rawWAD'];
        $r_freeze = -max(0, $currentState['minCoil'] - $currentState['coilTemp']) * 2;
        $r_energy = $this->calculateEnergyReward($prevP, $prevF);
        return ($r_progress * 10) + $r_freeze + $r_energy;
    }
    
    private function updateQ(array &$Q, string $sNew, string $sOld, string $aOld, float $r, int $prevTs)
    {
        $actions = $this->_getActionPairs();
        if (!isset($Q[$sOld])) $Q[$sOld] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        if (!isset($Q[$sNew])) $Q[$sNew] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        
        $oldQ = $Q[$sOld][$aOld] ?? self::OPTIMISTIC_INIT;
        $maxFutureQ = empty($Q[$sNew]) ? 0.0 : max($Q[$sNew]);
        $dt = max(1, (time() - $prevTs) / 60);
        
        $Q[$sOld][$aOld] = $oldQ + $this->ReadPropertyFloat('Alpha') * (($r * $dt) + $this->ReadPropertyFloat('Gamma') * $maxFutureQ - $oldQ);
    }
    
    private function chooseAction(array &$Q, string $lastAction, string $state): string
    {
        $availableActions = $this->getAvailableActions($lastAction);
        if ((mt_rand() / mt_getrandmax()) < $this->ReadAttributeFloat('Epsilon')) {
            return $availableActions[array_rand($availableActions)];
        }
        
        if (!isset($Q[$state])) $Q[$state] = array_fill_keys($this->_getActionPairs(), self::OPTIMISTIC_INIT);
        
        $qValues = array_intersect_key($Q[$state], array_flip($availableActions));
        if (empty($qValues)) return $lastAction;
        
        $maxVal = max($qValues);
        $bestActions = array_keys($qValues, $maxVal);
        return $bestActions[array_rand($bestActions)];
    }

    private function getAvailableActions(string $lastAction): array
    {
        $allActions = $this->_getActionPairs();
        if ($lastAction === '0:0') return $allActions;
        
        list($lastP, $lastF) = array_map('intval', explode(':', $lastAction));
        $maxPDelta = $this->ReadPropertyInteger('MaxPowerDelta');
        $maxFDelta = $this->ReadPropertyInteger('MaxFanDelta');
        
        $available = array_filter($allActions, function($pair) use ($lastP, $lastF, $maxPDelta, $maxFDelta) {
            list($p, $f) = array_map('intval', explode(':', $pair));
            return (abs($p - $lastP) <= $maxPDelta && abs($f - $lastF) <= $maxFDelta);
        });
        
        return !empty($available) ? array_values($available) : [$lastAction];
    }

    private function _getActionPairs(): array
    {
        $getLevels = function(string $customProp, int $stepProp): array {
            $levels = [];
            $custom = trim($this->ReadPropertyString($customProp));
            if (!empty($custom)) {
                $levels = array_map('intval', explode(',', $custom));
            } else {
                for ($i = $stepProp; $i <= 100; $i += $stepProp) $levels[] = $i;
            }
            return array_unique(array_filter($levels, fn($l) => $l > 0));
        };

        $powerLevels = $getLevels('CustomPowerLevels', 20);
        $fanLevels = $getLevels('CustomFanSpeeds', 20);
        
        $actions = ['0:0'];
        foreach ($powerLevels as $p) {
            foreach ($fanLevels as $f) $actions[] = "$p:$f";
        }
        return array_unique($actions);
    }

    // --- State & Utility Functions ---

    private function IsCoolingNeeded($rooms): bool
    {
        if (!is_array($rooms)) return false;
        $isStandalone = $this->ReadPropertyString('OperatingMode') === 'standalone';
        
        foreach ($rooms as $room) {
            if ($isStandalone) {
                if (($room['tempID'] ?? 0) > 0 && ($room['targetID'] ?? 0) > 0 && GetValue($room['tempID']) > (GetValue($room['targetID']) + ($room['threshold'] ?? 0.5))) return true;
            } else {
                if (($room['demandID'] ?? 0) > 0 && IPS_VariableExists($room['demandID']) && GetValueInteger($room['demandID']) > 0) return true;
            }
        }
        return false;
    }

    private function discretizeRoomState(array $rooms): array
    {
        $wDevSum = 0.0; $totalSize = 0.0; $D_cold = 0.0; $hotRooms = 0; $maxDev = 0.0;
        if (is_array($rooms)) {
            foreach ($rooms as $room) {
                if (!($room['tempID'] > 0 && $room['targetID'] > 0)) continue;
                $dev = GetValue($room['tempID']) - GetValue($room['targetID']);
                if ($dev > 0) $maxDev = max($maxDev, $dev);
                if ($dev < 0) $D_cold = max($D_cold, -$dev);
                if ($dev > ($room['threshold'] ?? 0.5)) {
                    $hotRooms++;
                    $wDevSum += $dev * ($room['size'] ?? 10);
                    $totalSize += ($room['size'] ?? 10);
                }
            }
        }
        $rawWAD = ($totalSize > 0) ? ($wDevSum / $totalSize) : 0.0;
        return [min(5,(int)floor($maxDev)), min(3,(int)floor($D_cold)), min(4,$hotRooms), $rawWAD];
    }
    
    private function getCoilTrendBin(float $current, float $prev): int {
        $delta = $current - $prev;
        if ($delta < -0.2) return -1; elseif ($delta > 0.2) return 1;
        return 0;
    }
    
    private function calculateEnergyReward(int $p, int $f): float {
        if ($p==0 && $f==0) return 0.0;
        $pn = $p/100.0; $fn = $f/100.0;
        return -0.05 * (0.6*((0.4*$pn)+0.6*pow($pn-0.5,2)) + 0.4*pow($fn,3));
    }
    
    private function decayEpsilon()
    {
        $this->WriteAttributeFloat('Epsilon', max(0.01, $this->ReadAttributeFloat('Epsilon') * (1 - $this->ReadPropertyFloat('DecayRate'))));
    }

    private function ShutdownSystem(array $returnValue): array
    {
        RequestAction($this->ReadPropertyInteger('PowerOutputLink'), 0);
        RequestAction($this->ReadPropertyInteger('FanOutputLink'), 0);
        return $returnValue;
    }

    private function GenerateQTableHTML(): string
    {
        $qTable = json_decode($this->ReadAttributeString('QTable'), true);
        // --- HARDENING ---
        if (!is_array($qTable) || empty($qTable)) return '<p>Q-Table is empty or not yet initialized.</p>';
        
        ksort($qTable);
        $actions = $this->_getActionPairs();
        $html = '<!DOCTYPE html><html><head><style>body{font-family:sans-serif;font-size:12px;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ccc;padding:4px;text-align:center;}th{background-color:#f2f2f2;position:sticky;top:0;}td.state-col{text-align:left;font-weight:bold;background-color:#f8f8f8;position:sticky;left:0;}</style></head><body><table><thead><tr><th class="state-col">State</th>';
        foreach ($actions as $action) $html .= "<th>{$action}</th>";
        $html .= '</tr></thead><tbody>';
        
        $minQ = 0; $maxQ = 0;
        // --- HARDENING ---
        if (is_array($qTable)) {
            foreach ($qTable as $stateActions) {
                if (is_array($stateActions)) { // Check inner element too
                    foreach ($stateActions as $qValue) {
                        if ($qValue != self::OPTIMISTIC_INIT) {
                            $minQ = min($minQ, $qValue);
                            $maxQ = max($maxQ, $qValue);
                        }
                    }
                }
            }
        }
        
        // --- HARDENING ---
        if (is_array($qTable)) {
            foreach ($qTable as $state => $stateActions) {
                $html .= "<tr><td class='state-col'>{$state}</td>";
                if(is_array($stateActions)) {
                    foreach ($actions as $action) {
                        $qValue = $stateActions[$action] ?? self::OPTIMISTIC_INIT;
                        // Color generation logic...
                        $color = '#f0f0f0'; // Default grey for unexplored
                        if ($qValue != self::OPTIMISTIC_INIT) {
                             if ($maxQ == $minQ) $color = '#90ee90'; // All same value, make green
                             else if ($qValue >= 0) {
                                 $p = ($maxQ > 0) ? ($qValue / $maxQ) : 0;
                                 $color = sprintf('#%02x%02x%02x', 255 - (int)(100 * $p), 255, 255 - (int)(100 * $p));
                             } else {
                                 $p = ($minQ < 0) ? ($qValue / $minQ) : 0;
                                 $color = sprintf('#%02x%02x%02x', 255, 255 - (int)(150 * $p), 255 - (int)(150 * $p));
                             }
                        }
                        $html .= sprintf('<td style="background-color:%s;">%.2f</td>', $color, $qValue);
                    }
                }
                $html .= '</tr>';
            }
        }
        
        return $html . '</tbody></table></body></html>';
    }
    
    private function Log(string $m, int $l): void {
        if ($this->ReadPropertyInteger('LogLevel') >= $l) $this->LogMessage($m, ($l == 4 ? KL_DEBUG : KL_MESSAGE));
    }
}
