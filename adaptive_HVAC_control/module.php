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
        $this->RegisterAttributeString('QTable', json_encode([]));
        $this->RegisterAttributeString('MetaData', json_encode([]));
        $this->RegisterAttributeFloat('Epsilon', 0.3);
        $this->RegisterTimer('ProcessCoolingLogic', 0, 'adaptive_HVAC_control_ProcessCoolingLogic($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('ProcessCoolingLogic', 120 * 1000);
        if ($this->ReadPropertyInteger('PowerOutputLink') === 0 || $this->ReadPropertyInteger('ACActiveLink') === 0) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus(102);
        }
    }

    public function ProcessCoolingLogic()
    {
        if ($this->ReadPropertyBoolean('ManualOverride')) {
            $this->SetStatus(200); return;
        }
        if (!GetValue($this->ReadPropertyInteger('ACActiveLink'))) {
            $this->SetStatus(201);
            RequestAction($this->ReadPropertyInteger('PowerOutputLink'), 0);
            RequestAction($this->ReadPropertyInteger('FanOutputLink'), 0);
            return;
        }
        $this->SetStatus(102);
        $Q = json_decode($this->ReadAttributeString('QTable'), true);
        $meta = json_decode($this->ReadAttributeString('MetaData'), true) ?: [];
        $minCoil = GetValue($this->ReadPropertyInteger('MinCoilTempLink'));
        $coilTemp = GetValue($this->ReadPropertyInteger('CoilTempLink'));
        $hysteresis = $this->ReadPropertyFloat('Hysteresis');
        $prevState = $meta['state'] ?? null;
        $prevAction = $meta['action'] ?? $this->getActionPairs()[0];
        $prevTs = $meta['ts'] ?? null;
        $prev_WAD = $meta['WAD'] ?? 0;
        $prev_D_cold = $meta['D_cold'] ?? 0;
        $prevCoilTemp = $meta['coilTemp'] ?? $coilTemp;
        $monitoredRooms = json_decode($this->ReadPropertyString('MonitoredRooms'), true);
        if (empty($monitoredRooms)) {
            $this->SendDebug('ERROR', 'No rooms configured. Please add rooms to the list in the instance form.', 0);
            $this->SetStatus(104);
            return;
        }
        $coilState = max($coilTemp, $minCoil + $hysteresis);
        list($cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $rawD_cold) = $this->discretizeState($monitoredRooms, $coilState, $minCoil);
        $coilTrendBin = $this->getCoilTrendBin($coilTemp, $prevCoilTemp);
        $state = "$dBin|$cBin|$oBin|$coilTrendBin|$hotRoomCountBin";
        $r = 0;
        $progress = $prev_WAD - $rawWAD;
        $r += $progress * 10;
        $r += -max(0, $minCoil - $coilState) * 2;
        list($prevP, $prevF) = explode(':', $prevAction);
        $r += -0.01 * (intval($prevP) + intval($prevF));
        $r += -$prev_D_cold * 5;
        if ($prevState !== null && $prevTs !== null) {
            $dt = max(1, (time() - intval($prevTs)) / 60);
            $dt = min($dt, 10);
            $this->updateQ($Q, $state, $prevState, $prevAction, $r, $dt);
        }
        $action = $this->choose($Q, $this->ReadAttributeFloat('Epsilon'), $prevAction, $state);
        list($P, $F) = explode(':', $action);
        RequestAction($this->ReadPropertyInteger('PowerOutputLink'), intval($P));
        RequestAction($this->ReadPropertyInteger('FanOutputLink'), intval($F));
        $this->SendDebug('INFO', "State: $state, Reward: $r, Action: P=$P F=$F", 0);
        $newMeta = [ 'state' => $state, 'action' => $action, 'ts' => time(), 'WAD' => $rawWAD, 'D_cold' => $rawD_cold, 'coilTemp' => $coilTemp ];
        $this->WriteAttributeString('QTable', json_encode($Q));
        $this->WriteAttributeString('MetaData', json_encode($newMeta));
        if ($action !== '0:0') {
            $this->decayEpsilon();
        }
    }

    public function ResetLearning() {
        $this->WriteAttributeString('QTable', json_encode([]));
        $this->WriteAttributeString('MetaData', json_encode([]));
        $this->WriteAttributeFloat('Epsilon', 0.4);
        $this->SendDebug('RESET', 'Learning has been reset by the user.', 0);
        echo "Learning has been reset!";
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
            return $availableActions[array_rand($availableActions)];
        }
        $qValuesForAvailable = array_intersect_key($Q[$state], array_flip($availableActions));
        if (empty($qValuesForAvailable)) return $lastAction;
        $maxV = max($qValuesForAvailable);
        $bestActions = array_keys($qValuesForAvailable, $maxV);
        return $bestActions[array_rand($bestActions)];
    }

    private function decayEpsilon() {
        $eps = $this->ReadAttributeFloat('Epsilon');
        $dec = $this->ReadPropertyFloat('DecayRate');
        $this->WriteAttributeFloat('Epsilon', max(0.01, $eps * (1 - $dec)));
    }

    private function getActionPairs(): array {
        return ["0:0", "30:30", "30:50", "30:70", "60:40", "60:60", "60:80", "80:70", "80:90", "100:80", "100:100"];
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
    
    // --- MODIFIED FUNCTION ---
    private function discretizeState(array $monitoredRooms, float $coil, float $min): array {
        $weightedDeviationSum = 0.0; $totalSizeOfHotRooms = 0.0; $D_cold = 0.0; $hotRoomCount = 0;
        
        foreach ($monitoredRooms as $room) {
            $tempID = $room['tempID'] ?? 0;
            $targetID = $room['targetID'] ?? 0;
            $demandID = $room['demandID'] ?? 0; // Get the optional demand variable ID
            $roomSize = $room['size'] ?? 1;
            $threshold = $room['threshold'] ?? $this->ReadPropertyFloat('Hysteresis');

            // --- CORE LOGIC CHANGE ---
            // Check if the room should be considered at all
            if ($tempID > 0 && $targetID > 0) {
                // If a demand variable is set, check its value. If it's 1, skip this room.
                if ($demandID > 0 && IPS_ObjectExists($demandID) && GetValue($demandID) == 1) {
                    continue; // Skip to the next room
                }
                
                // If we are here, the room is active. Proceed with temperature checks.
                $temp = GetValue($tempID);
                $target = GetValue($targetID);
                $deviation = $temp - $target;

                $D_cold = max($D_cold, max(0, -$deviation));
                
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
        return [$cBin, $dBin, $oBin, $hotRoomCountBin, $rawWAD, $rawD_cold];
    }
    
    private function getCoilTrendBin(float $currentCoil, float $previousCoil): int {
        $delta = $currentCoil - $previousCoil;
        if ($delta < -0.2) { return -1; } elseif ($delta > 0.2) { return 1; }
        return 0;
    }
}