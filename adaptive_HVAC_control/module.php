<?php
/**
 * Adaptive HVAC Control
 *
 * Version: 3.2 (Hardened)
 * Author: Artur Fischer
 * Co-Author / Review: AI Consultant
 */

class adaptive_HVAC_control extends IPSModule
{
    private const OPTIMISTIC_INIT = 1.0;

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
        $lastMode = $this->ReadAttributeString('LastOperatingMode');
        if ($lastMode !== '' && $currentMode !== $lastMode) {
            $this->Log('Operating Mode has changed. It is STRONGLY recommended to reset learning.', KL_WARNING);
        }
        $this->WriteAttributeString('LastOperatingMode', $currentMode);
        if ($this->ReadPropertyString('OperatingMode') === 'orchestrated') {
            $this->SetTimerInterval('ProcessCoolingLogic', 0);
        } else {
            $this->SetTimerInterval('ProcessCoolingLogic', $this->ReadPropertyInteger('TimerInterval') * 1000);
        }
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->UpdateVisualization();
        $this->SetStatus(($this->ReadPropertyInteger('PowerOutputLink') === 0 || $this->ReadPropertyInteger('ACActiveLink') === 0) ? 104 : 102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $customPowerLevels = $this->ReadPropertyString('CustomPowerLevels');
        foreach ($form['elements'] as &$element) {
            if (isset($element['name']) && $element['name'] == 'PowerStep') $element['visible'] = empty($customPowerLevels);
        }
        $customFanSpeeds = $this->ReadPropertyString('CustomFanSpeeds');
        foreach ($form['elements'] as &$element) {
             if ($element['type'] === 'ExpansionPanel') {
                foreach ($element['items'] as &$item) {
                    if (isset($item['name']) && $item['name'] == 'FanStep') $item['visible'] = empty($customFanSpeeds);
                }
            }
        }
        return json_encode($form);
    }

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
        $this->SetValue("CurrentEpsilon", 0.4);
        $this->UpdateVisualization();
        $this->Log('Learning has been reset by the user.', KL_MESSAGE);
    }
    
    public function UpdateVisualization()
    {
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

    private function ExecuteLearningCycle(?string $forcedAction): array
    {
        $acActiveID = $this->ReadPropertyInteger('ACActiveLink');
        $powerOutputID = $this->ReadPropertyInteger('PowerOutputLink');
        $fanOutputID = $this->ReadPropertyInteger('FanOutputLink');
        if ($acActiveID === 0 || !IPS_VariableExists($acActiveID)) return ['state' => 'error', 'reward' => 0];
        if (!GetValue($acActiveID)) {
            if ($powerOutputID > 0 && IPS_VariableExists($powerOutputID)) RequestAction($powerOutputID, 0);
            if ($fanOutputID > 0 && IPS_VariableExists($fanOutputID)) RequestAction($fanOutputID, 0);
            return ['state' => 'inactive', 'reward' => 0];
        }
        
        $monitoredRooms = json_decode($this->ReadPropertyString('MonitoredRooms'), true);
        if (!is_array($monitoredRooms)) $monitoredRooms = [];
        
        $isCoolingNeeded = false;
        if ($this->ReadPropertyString('OperatingMode') !== 'standalone') {
            foreach ($monitoredRooms as $room) {
                if (($room['demandID'] ?? 0) > 0 && IPS_VariableExists($room['demandID']) && GetValueInteger($room['demandID']) > 0) $isCoolingNeeded = true;
            }
        } else {
            foreach ($monitoredRooms as $room) {
                if (($room['tempID'] ?? 0) > 0 && ($room['targetID'] ?? 0) > 0 && GetValue($room['tempID']) > (GetValue($room['targetID']) + ($room['threshold'] ?? 0.5))) $isCoolingNeeded = true;
            }
        }

        if (!$isCoolingNeeded && $forcedAction === null) {
            if ($powerOutputID > 0 && IPS_VariableExists($powerOutputID)) RequestAction($powerOutputID, 0);
            if ($fanOutputID > 0 && IPS_VariableExists($fanOutputID)) RequestAction($fanOutputID, 0);
            return ['state' => 'idle', 'reward' => 0];
        }
        
        $coilTempID = $this->ReadPropertyInteger('CoilTempLink');
        $minCoilTempID = $this->ReadPropertyInteger('MinCoilTempLink');
        if ($powerOutputID === 0 || $fanOutputID === 0 || $coilTempID === 0 || $minCoilTempID === 0) return ['state' => 'error', 'reward' => 0];

        $Q = json_decode($this->ReadAttributeString('QTable'), true);
        if (!is_array($Q)) $Q = [];
        $meta = json_decode($this->GetBuffer('MetaData'), true) ?: [];
        $minCoil = GetValue($minCoilTempID);
        $coilTemp = GetValue($coilTempID);
        list($cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $rawD_cold) = $this->discretizeState($monitoredRooms, $coilTemp, $minCoil);
        $coilTrendBin = $this->getCoilTrendBin($coilTemp, $meta['coilTemp'] ?? $coilTemp);
        
        $state = ($this->ReadPropertyString('OperatingMode') === 'standalone') ? "$dBin|$cBin|$oBin|$coilTrendBin|$hotRoomCountBin" : "$dBin|$cBin|$coilTrendBin|$hotRoomCountBin";
        
        $r_total = 0;
        if (($meta['state'] ?? null) !== null && ($meta['action'] ?? null) !== null) {
            list($prevP, $prevF) = explode(':', $meta['action']);
            $r_progress = ($meta['WAD'] ?? 0) - $rawWAD;
            $r_freeze = -max(0, $minCoil - $coilTemp) * 2;
            $r_energy = $this->calculateEnergyReward(intval($prevP), intval($prevF));
            $r_overcool = ($this->ReadPropertyString('OperatingMode') === 'standalone') ? (-$rawD_cold * 5) : 0;
            $r_total = ($r_progress * 10) + $r_freeze + $r_energy + $r_overcool;
            $dt = max(1, (time() - intval($meta['ts'] ?? time())) / 60);
            $this->updateQ($Q, $state, $meta['state'], $meta['action'], $r_total, $dt);
        }
        
        $action = ($forcedAction !== null) ? $forcedAction : $this->choose($Q, $this->ReadAttributeFloat('Epsilon'), $meta['action'] ?? '0:0', $state);
        list($P, $F) = explode(':', $action);
        RequestAction($powerOutputID, intval($P));
        RequestAction($fanOutputID, intval($F));
        
        $this->WriteAttributeString('QTable', json_encode($Q));
        $this->SetBuffer('MetaData', json_encode([ 'state' => $state, 'action' => $action, 'ts' => time(), 'WAD' => $rawWAD, 'coilTemp' => $coilTemp ]));
        if ($action !== '0:0' && $forcedAction === null) $this->decayEpsilon();
        $this->SetValue("CurrentEpsilon", $this->ReadAttributeFloat('Epsilon'));
        $this->Log("State: $state -> Action: P=$P F=$F (Reward: ".number_format($r_total, 2).")", KL_MESSAGE);
        return ['state' => $state, 'reward' => $r_total];
    }

    private function GenerateQTableHTML(): string
    {
        $qTable = json_decode($this->ReadAttributeString('QTable'), true);
        if (!is_array($qTable) || empty($qTable)) return '<p>Q-Table is empty.</p>';
        ksort($qTable);
        $actions = $this->getActionPairs();
        $html = '<!DOCTYPE html><html><head><style>body{font-family:sans-serif;font-size:14px;}table{border-collapse:collapse;width:100%;margin-top:20px;}th,td{border:1px solid #ccc;padding:6px;text-align:center;}th{background-color:#f2f2f2;position:sticky;top:0;z-index:10;}td.state-col{text-align:left;font-weight:bold;min-width:150px;background-color:#f8f8f8;position:sticky;left:0;}</style></head><body><table><thead><tr><th class="state-col">State</th>';
        foreach ($actions as $action) $html .= "<th>{$action}</th>";
        $html .= '</tr></thead><tbody>';
        $minQ = 0; $maxQ = 0;
        foreach ($qTable as $stateActions) {
            foreach ($stateActions as $qValue) {
                if ($qValue != self::OPTIMISTIC_INIT) {
                    $minQ = min($minQ, $qValue);
                    $maxQ = max($maxQ, $qValue);
                }
            }
        }
        foreach ($qTable as $state => $stateActions) {
            $html .= "<tr><td class='state-col'>{$state}</td>";
            foreach ($actions as $action) {
                $qValue = $stateActions[$action] ?? self::OPTIMISTIC_INIT;
                $color = '#f0f0f0';
                if ($qValue != self::OPTIMISTIC_INIT) {
                    if ($qValue >= 0) {
                        $p = ($maxQ > 0) ? ($qValue / $maxQ) : 0;
                        $color = sprintf('#%02x%02x%02x', 255 - (150 * $p), 255, 255 - (150 * $p));
                    } else {
                        $p = ($minQ < 0) ? ($qValue / $minQ) : 0;
                        $color = sprintf('#%02x%02x%02x', 255, 255 - (200 * $p), 255 - (200 * $p));
                    }
                }
                $html .= sprintf('<td style="background-color:%s;">%.2f</td>', $color, $qValue);
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></body></html>';
    }

    private function updateQ(array &$Q, string $sNew, string $sOld, string $aOld, float $r, float $dt)
    {
        $actions = $this->getActionPairs();
        if (!isset($Q[$sOld])) $Q[$sOld] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        if (!isset($Q[$sNew])) $Q[$sNew] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        $oldQ = $Q[$sOld][$aOld] ?? self::OPTIMISTIC_INIT;
        $maxFutureQ = empty($Q[$sNew]) ? 0.0 : max($Q[$sNew]);
        $Q[$sOld][$aOld] = $oldQ + $this->ReadPropertyFloat('Alpha') * ($r * $dt + $this->ReadPropertyFloat('Gamma') * $maxFutureQ - $oldQ);
    }
    
    private function choose(array &$Q, float $epsilon, string $lastAction, string $state): string
    {
        if ((mt_rand() / mt_getrandmax()) < $epsilon) return $this->getAvailableActions($lastAction)[array_rand($this->getAvailableActions($lastAction))];
        $actions = $this->getActionPairs();
        if (!isset($Q[$state])) $Q[$state] = array_fill_keys($actions, self::OPTIMISTIC_INIT);
        $availableActions = $this->getAvailableActions($lastAction);
        $qValues = array_intersect_key($Q[$state], array_flip($availableActions));
        if (empty($qValues)) return $lastAction;
        return array_keys($qValues, max($qValues))[0];
    }

    private function decayEpsilon()
    {
        $this->WriteAttributeFloat('Epsilon', max(0.01, $this->ReadAttributeFloat('Epsilon') * (1 - $this->ReadPropertyFloat('DecayRate'))));
    }

    private function _getActionPairs(): array
    {
        $powerLevels = [];
        $customPower = trim($this->ReadPropertyString('CustomPowerLevels'));
        if (!empty($customPower)) {
            foreach (explode(',', $customPower) as $p) if (intval($p) > 0) $powerLevels[] = intval($p);
        } else {
            for ($p = 20; $p <= 100; $p += 20) $powerLevels[] = $p;
        }
        $fanLevels = [];
        $customFan = trim($this->ReadPropertyString('CustomFanSpeeds'));
        if (!empty($customFan)) {
            foreach (explode(',', $customFan) as $f) if (intval($f) > 0) $fanLevels[] = intval($f);
        } else {
            for ($f = 20; $f <= 100; $f += 20) $fanLevels[] = $f;
        }
        $actions = ['0:0'];
        if (!is_array($powerLevels) || !is_array($fanLevels)) return $actions;
        foreach ($powerLevels as $p) {
            foreach ($fanLevels as $f) $actions[] = "{$p}:{$f}";
        }
        return array_unique($actions);
    }
    
    private function getAvailableActions(string $lastAction): array
    {
        if ($lastAction === '0:0') return $this->getActionPairs();
        list($lastP, $lastF) = array_map('intval', explode(':', $lastAction));
        $maxPDelta = $this->ReadPropertyInteger('MaxPowerDelta');
        $maxFDelta = $this->ReadPropertyInteger('MaxFanDelta');
        $available = [];
        $allPairs = $this->getActionPairs();
        if (!is_array($allPairs)) return [$lastAction];
        foreach ($allPairs as $pair) {
            list($p, $f) = array_map('intval', explode(':', $pair));
            if (abs($p - $lastP) <= $maxPDelta && abs($f - $lastF) <= $maxFDelta) $available[] = $pair;
        }
        return !empty($available) ? $available : [$lastAction];
    }
    
    private function discretizeState(array $monitoredRooms, float $coil, float $min): array
    {
        $wDevSum = 0.0; $totalSize = 0.0; $D_cold = 0.0; $hotRooms = 0; $maxDev = 0.0;
        foreach ($monitoredRooms as $room) {
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
        $rawWAD = ($totalSize > 0) ? ($wDevSum / $totalSize) : 0.0;
        return [min(5,max(-2,(int)floor($coil - $min))), min(5,(int)floor($maxDev)), min(3,(int)floor($D_cold)), min(4,$hotRooms), $rawWAD, $D_cold];
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

    private function Log(string $m, int $l): void {
        if ($this->ReadPropertyInteger('LogLevel') >= $l) $this->LogMessage($m, ($l == 4 ? KL_DEBUG : KL_MESSAGE));
    }
}
