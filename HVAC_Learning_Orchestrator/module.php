<?php
/**
 * @file          module.php
 * @author        Artur Fischer & AI Consultant
 * @version       2.4 (Console-visible errors, hard-stop, guarded calls)
 * @date          2025-08-08
 */

declare(strict_types=1);

class HVAC_Learning_Orchestrator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('ZoningManagerID', 0);
        $this->RegisterPropertyInteger('AdaptiveControlID', 0);
        $this->RegisterPropertyString('CalibrationPlan', '[]');

        $this->RegisterAttributeString('CalibrationStatus', 'Idle');
        $this->RegisterAttributeInteger('CurrentStageIndex', 0);
        $this->RegisterAttributeInteger('CurrentActionIndex', 0);
        $this->RegisterAttributeString('OriginalTargetTemps', '{}');

        $this->RegisterVariableString('CalibrationPlanHTML', 'Calibration Plan (HTML)', '~HTMLBox', 10);

        // Timer targets the public method RunNextStep via prefix "ORCH"
        $this->RegisterTimer('CalibrationTimer', 0, 'ORCH_RunNextStep($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // Validate core links
        $errs = $this->validateCoreLinks();
        if (!empty($errs)) {
            $msg = 'Configuration error: ' . json_encode($errs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->SetStatus(202);
            $this->reportError($msg);
            return;
        }

        // Status according to calibration run-state
        $status = 102; // Idle
        $stAttr = $this->ReadAttributeString('CalibrationStatus');
        if ($stAttr === 'Running') {
            $status = 201;
        } elseif ($stAttr === 'Done') {
            $status = 203;
        }
        $this->SetStatus($status);
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    // ----------------------- UI Actions -----------------------

    public function ProposePlan()
    {
        // Check links first
        $errs = $this->validateCoreLinks();
        if (!empty($errs)) {
            $msg = 'ProposePlan aborted: ' . json_encode($errs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->reportError($msg);
            return;
        }

        $zoningID   = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        $plan = $this->generateProposedPlan($zoningID, $adaptiveID);
        if (empty($plan)) {
            $this->reportError('Failed to generate a valid calibration plan (empty result). Check ZDM/ADHVAC functions and data.');
            $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML([]));
            return;
        }

        IPS_SetProperty($this->InstanceID, 'CalibrationPlan', json_encode($plan));
        if (IPS_HasChanges($this->InstanceID)) {
            IPS_ApplyChanges($this->InstanceID);
        }
        $this->SetValue('CalibrationPlanHTML', $this->GeneratePlanHTML($plan));
        echo 'New calibration plan proposed. The form will now refresh.';
    }

    public function StartCalibration()
    {
        // Validate links
        $errs = $this->validateCoreLinks();
        if (!empty($errs)) {
            $this->SetStatus(202);
            $this->reportError('StartCalibration aborted: ' . json_encode($errs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        $zoningID   = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        // Guard availability of global functions
        if (!function_exists('ZDM_SetOverrideMode') || !function_exists('ZDM_CommandSystem')) {
            $this->SetStatus(202);
            $this->reportError('ZDM functions missing (ZDM_SetOverrideMode/ZDM_CommandSystem). Is the ZDM module active?');
            return;
        }
        if (!function_exists('ACIPS_SetMode') || !function_exists('ACIPS_ResetLearning') || !function_exists('ACIPS_ForceActionAndLearn')) {
            $this->SetStatus(202);
            $this->reportError('ADHVAC functions missing (ACIPS_SetMode/ResetLearning/ForceActionAndLearn). Is the Adaptive module active?');
            return;
        }

        $this->LogMessage('--- Starting System Calibration ---', KL_MESSAGE);

        $this->WriteAttributeString('CalibrationStatus', 'Running');
        $this->WriteAttributeInteger('CurrentStageIndex', 0);
        $this->WriteAttributeInteger('CurrentActionIndex', 0);
        $this->SetStatus(201);

        // Snapshot current targets
        $this->saveOriginalTargets();

        // Prepare modules
        try {
            ACIPS_SetMode($adaptiveID, 'orchestrated');
        } catch (\Throwable $e) {
            $this->reportError('ACIPS_SetMode failed: ' . $e->getMessage());
            return;
        }

        try {
            ACIPS_ResetLearning($adaptiveID);
        } catch (\Throwable $e) {
            $this->reportError('ACIPS_ResetLearning failed: ' . $e->getMessage());
            return;
        }

        try {
            ZDM_SetOverrideMode($zoningID, true);
        } catch (\Throwable $e) {
            $this->reportError('ZDM_SetOverrideMode(true) failed: ' . $e->getMessage());
            return;
        }

        try {
            // bring system up to guarantee airflow for first steps
            ZDM_CommandSystem($zoningID, 100, 100);
        } catch (\Throwable $e) {
            $this->reportError('ZDM_CommandSystem(100,100) failed: ' . $e->getMessage());
            return;
        }

        // Kick off first step immediately, then arm timer
        $this->RunNextStep();
        $this->SetTimerInterval('CalibrationTimer', 120 * 1000);
    }

    public function StopCalibration()
    {
        $this->LogMessage('--- Calibration Stopped ---', KL_MESSAGE);
        $this->SetTimerInterval('CalibrationTimer', 0);

        // Restore original targets regardless of errors below
        $this->restoreOriginalTargets();

        $zoningID   = $this->ReadPropertyInteger('ZoningManagerID');
        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');

        if ($zoningID > 0 && IPS_InstanceExists($zoningID) && function_exists('ZDM_CommandSystem')) {
            try {
                // zero system
                ZDM_CommandSystem($zoningID, 0, 0);
            } catch (\Throwable $e) {
                $this->reportError('ZDM_CommandSystem(0,0) failed: ' . $e->getMessage());
            }
        }

        if ($zoningID > 0 && IPS_InstanceExists($zoningID) && function_exists('ZDM_SetOverrideMode')) {
            try {
                ZDM_SetOverrideMode($zoningID, false);
            } catch (\Throwable $e) {
                $this->reportError('ZDM_SetOverrideMode(false) failed: ' . $e->getMessage());
            }
        }

        if ($adaptiveID > 0 && IPS_InstanceExists($adaptiveID)) {
            if (function_exists('ACIPS_ForceActionAndLearn')) {
                try {
                    // ensure final hard stop at ADHVAC side
                    ACIPS_ForceActionAndLearn($adaptiveID, '0:0');
                } catch (\Throwable $e) {
                    $this->reportError('ACIPS_ForceActionAndLearn("0:0") failed: ' . $e->getMessage());
                }
            } else {
                $this->reportError('ACIPS_ForceActionAndLearn not available to stop outputs.');
            }

            if (function_exists('ACIPS_SetMode')) {
                try {
                    ACIPS_SetMode($adaptiveID, 'cooperative');
                } catch (\Throwable $e) {
                    $this->reportError('ACIPS_SetMode("cooperative") failed: ' . $e->getMessage());
                }
            } else {
                $this->reportError('ACIPS_SetMode not available to return to normal operation.');
            }
        }

        $this->WriteAttributeString('CalibrationStatus', 'Done');
        $this->SetStatus(203);
    }

    public function RunNextStep()
    {
        if ($this->ReadAttributeString('CalibrationStatus') !== 'Running') {
            // not an error; just ignore
            return;
        }

        $plan = $this->getCalibrationPlan();
        if (empty($plan)) {
            $this->reportError('RunNextStep aborted: empty or invalid calibration plan.');
            $this->StopCalibration();
            return;
        }

        $stageIdx  = $this->ReadAttributeInteger('CurrentStageIndex');
        $actionIdx = $this->ReadAttributeInteger('CurrentActionIndex');

        if ($stageIdx >= count($plan)) {
            $this->LogMessage('--- Calibration Plan Finished Successfully! ---', KL_MESSAGE);
            $this->StopCalibration();
            return;
        }

        $currentStage = $plan[$stageIdx];

        // First entry into a stage: set flaps and targets
        if ($actionIdx === 0) {
            $this->LogMessage('--- Starting Stage ' . ($stageIdx + 1) . '/' . count($plan) . ': ' . ($currentStage['name'] ?? 'Unnamed') . ' ---', KL_MESSAGE);

            $zoningID = $this->ReadPropertyInteger('ZoningManagerID');
            if (function_exists('ZDM_CommandFlaps')) {
                try {
                    ZDM_CommandFlaps(
                        $zoningID,
                        (string)($currentStage['name'] ?? 'calibration'),
                        json_encode($currentStage['setup']['flaps'])
                    );
                } catch (\Throwable $e) {
                    $this->reportError('ZDM_CommandFlaps failed: ' . $e->getMessage());
                    $this->StopCalibration();
                    return;
                }
            } else {
                $this->reportError('ZDM_CommandFlaps not available.');
                $this->StopCalibration();
                return;
            }

            $this->setArtificialTargets($currentStage);
            IPS_Sleep(5000);
        }

        $adaptiveID = $this->ReadPropertyInteger('AdaptiveControlID');
        $pair = (string)($currentStage['actions'][$actionIdx] ?? '');
        if ($pair === '') {
            $this->reportError('Missing action pair in stage index ' . $stageIdx . ', action ' . $actionIdx . '.');
            $this->StopCalibration();
            return;
        }

        if (!function_exists('ACIPS_ForceActionAndLearn')) {
            $this->reportError('ACIPS_ForceActionAndLearn not available.');
            $this->StopCalibration();
            return;
        }

        try {
            $result = json_decode((string)ACIPS_ForceActionAndLearn($adaptiveID, $pair), true);
        } catch (\Throwable $e) {
            $this->reportError('ACIPS_ForceActionAndLearn("' . $pair . '") failed: ' . $e->getMessage());
            $this->StopCalibration();
            return;
        }

        $this->LogMessage(
            sprintf(
                'Step %d:%d | Action: %s | Reward: %s',
                $stageIdx,
                $actionIdx,
                $pair,
                number_format((float)($result['reward'] ?? 0), 2)
            ),
            KL_DEBUG
        );

        // Advance indices
        $actionIdx++;
        if ($actionIdx >= count($currentStage['actions'])) {
            $this->WriteAttributeInteger('CurrentStageIndex', $stageIdx + 1);
            $this->WriteAttributeInteger('CurrentActionIndex', 0);
        } else {
            $this->WriteAttributeInteger('CurrentActionIndex', $actionIdx);
        }
    }

    // ----------------------- Helpers (Plan) -----------------------

    private function generateProposedPlan(int $zoningID, int $adaptiveID): array
    {
        // Guard ZDM function
        if (!function_exists('ZDM_GetRoomConfigurations')) {
            $this->reportError('ZDM_GetRoomConfigurations() not available.');
            return [];
        }

        $roomConfig = [];
        try {
            $roomConfig = json_decode((string)ZDM_GetRoomConfigurations($zoningID), true);
        } catch (\Throwable $e) {
            $this->reportError('ZDM_GetRoomConfigurations failed: ' . $e->getMessage());
            return [];
        }

        $roomNames = is_array($roomConfig) ? array_column($roomConfig, 'name') : [];
        if (empty($roomNames)) {
            $this->reportError('No rooms returned from ZDM_GetRoomConfigurations.');
            return [];
        }

        // Guard ADHVAC function
        if (!function_exists('ACIPS_GetActionPairs')) {
            $this->reportError('ACIPS_GetActionPairs() not available.');
            return [];
        }

        $allActions = [];
        try {
            $allActions = json_decode((string)ACIPS_GetActionPairs($adaptiveID), true);
        } catch (\Throwable $e) {
            $this->reportError('ACIPS_GetActionPairs failed: ' . $e->getMessage());
            return [];
        }

        if (!is_array($allActions)) {
            $this->reportError('ACIPS_GetActionPairs returned non-array.');
            return [];
        }

        // Prefer a subset; otherwise fallback
        $actionPattern = array_values(array_filter($allActions, function ($action) {
            $parts = explode(':', (string)$action);
            if (count($parts) !== 2) return false;
            [$p, $f] = $parts;
            return $p !== '0' && in_array((string)$p, ['30', '75', '100'], true) && in_array((string)$f, ['30', '70', '90'], true);
        }));
        if (empty($actionPattern)) {
            $actionPattern = ['55:50', '100:100'];
        }
        $actionPatternString = implode(', ', $actionPattern);

        $finalPlan   = [];
        $stageCount  = 1;

        // Single-zone stages
        foreach ($roomNames as $roomToTest) {
            $finalPlan[] = [
                'stageName'    => sprintf('Stage %d: Single Zone (%s)', $stageCount++, $roomToTest),
                'flapConfig'   => $this->generateFlapConfigString($roomNames, [$roomToTest]),
                'targetOffset' => '-1.5',
                'actionPattern'=> $actionPatternString
            ];
        }

        // Two simple combos if possible
        if (count($roomNames) > 1) {
            $combo = [$roomNames[0], $roomNames[1]];
            $finalPlan[] = [
                'stageName'    => sprintf('Stage %d: Combo Test (%s)', $stageCount++, implode(' & ', $combo)),
                'flapConfig'   => $this->generateFlapConfigString($roomNames, $combo),
                'targetOffset' => '-3.0',
                'actionPattern'=> $actionPatternString
            ];
        }
        if (count($roomNames) > 2) {
            $combo = [$roomNames[0], end($roomNames)];
            $finalPlan[] = [
                'stageName'    => sprintf('Stage %d: Combo Test (%s)', $stageCount++, implode(' & ', $combo)),
                'flapConfig'   => $this->generateFlapConfigString($roomNames, $combo),
                'targetOffset' => '-3.0',
                'actionPattern'=> $actionPatternString
            ];
        }

        // High demand / stress
        $allFlapsTrue = $this->generateFlapConfigString($roomNames, $roomNames);
        $finalPlan[]  = [
            'stageName'    => sprintf('Stage %d: High Demand (All Zones)', $stageCount++),
            'flapConfig'   => $allFlapsTrue,
            'targetOffset' => '-5.0',
            'actionPattern'=> $actionPatternString
        ];
        $finalPlan[]  = [
            'stageName'    => sprintf('Stage %d: Coil Stress Test', $stageCount++),
            'flapConfig'   => $allFlapsTrue,
            'targetOffset' => '-6.0',
            'actionPattern'=> '100:90, 100:50, 100:30, 75:30, 75:90'
        ];

        return $finalPlan;
    }

    private function generateFlapConfigString(array $all, array $active): string
    {
        $parts = [];
        $activeMap = array_flip($active);
        foreach ($all as $name) {
            $parts[] = isset($activeMap[$name]) ? "{$name}=true" : "{$name}=false";
        }
        return implode(', ', $parts);
    }

    private function getCalibrationPlan(): array
    {
        $planConfig = json_decode($this->ReadPropertyString('CalibrationPlan'), true);
        if (!is_array($planConfig)) {
            return [];
        }
        $structured = [];
        foreach ($planConfig as $stage) {
            $flaps = [];
            foreach (explode(',', (string)($stage['flapConfig'] ?? '')) as $part) {
                $kv = explode('=', trim($part));
                if (count($kv) === 2) {
                    $flaps[] = [
                        'name' => trim($kv[0]),
                        'open' => (mb_strtolower(trim($kv[1])) === 'true')
                    ];
                }
            }
            $actions = array_filter(array_map('trim', explode(',', (string)($stage['actionPattern'] ?? ''))));
            $structured[] = [
                'name'  => (string)($stage['stageName'] ?? 'Unnamed'),
                'setup' => [
                    'flaps'        => $flaps,
                    'targetOffset' => (float)($stage['targetOffset'] ?? 0)
                ],
                'actions' => $actions
            ];
        }
        return $structured;
    }

    private function getRoomLinks(): array
    {
        $zdmID = $this->ReadPropertyInteger('ZoningManagerID');

        if (!function_exists('ZDM_GetRoomConfigurations')) {
            $this->reportError('ZDM_GetRoomConfigurations() not available in getRoomLinks().');
            return [];
        }

        try {
            $roomConfig = json_decode((string)ZDM_GetRoomConfigurations($zdmID), true);
        } catch (\Throwable $e) {
            $this->reportError('ZDM_GetRoomConfigurations failed in getRoomLinks(): ' . $e->getMessage());
            return [];
        }

        if (!is_array($roomConfig)) return [];

        $linkMap = [];
        foreach ($roomConfig as $room) {
            if (!empty($room['name'])) {
                $linkMap[$room['name']] = [
                    'tempID'   => (int)($room['tempID'] ?? 0),
                    'targetID' => (int)($room['targetID'] ?? 0)
                ];
            }
        }
        return $linkMap;
    }

    private function saveOriginalTargets(): void
    {
        $targets = [];
        foreach ($this->getRoomLinks() as $links) {
            if (($links['targetID'] > 0) && IPS_VariableExists($links['targetID'])) {
                $targets[$links['targetID']] = GetValue($links['targetID']);
            }
        }
        $this->WriteAttributeString('OriginalTargetTemps', json_encode($targets));
    }

    private function restoreOriginalTargets(): void
    {
        $targets = json_decode($this->ReadAttributeString('OriginalTargetTemps'), true);
        if (!is_array($targets)) return;
        foreach ($targets as $id => $val) {
            if (IPS_VariableExists((int)$id)) {
                @SetValue((int)$id, $val);
            }
        }
    }

    private function setArtificialTargets(array $stage): void
    {
        $this->restoreOriginalTargets();

        $activeRooms = [];
        foreach ((array)($stage['setup']['flaps'] ?? []) as $flap) {
            if (!empty($flap['name']) && !empty($flap['open'])) {
                $activeRooms[$flap['name']] = true;
            }
        }

        foreach ($this->getRoomLinks() as $name => $links) {
            $tID = $links['targetID'] ?? 0;
            $iID = $links['tempID'] ?? 0;
            if (isset($activeRooms[$name]) && $tID > 0 && $iID > 0 && IPS_VariableExists($tID) && IPS_VariableExists($iID)) {
                @SetValue($tID, GetValue($iID) + (float)($stage['setup']['targetOffset'] ?? 0));
            }
        }
    }

    private function GeneratePlanHTML(array $plan): string
    {
        $html = '<!DOCTYPE html><html><head><title>HVAC Plan</title><style>body{font-family:sans-serif;font-size:14px;margin:10px;}table{width:100%;border-collapse:collapse;margin-top:15px;}th,td{border:1px solid #dee2e6;padding:10px 12px;text-align:left;word-break:break-word;}thead{background-color:#e9ecef;}h2{color:#343a40;border-bottom:2px solid #ced4da;padding-bottom:5px;}</style></head><body><h2>Generated Plan</h2>';
        if (empty($plan)) return $html . '<p>No plan generated.</p></body></html>';

        $html .= '<table><thead><tr><th>Stage Name</th><th>Flap Config</th><th>Offset</th><th>Action Pattern</th></tr></thead><tbody>';
        foreach ($plan as $stage) {
            $html .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s &deg;C</td><td>%s</td></tr>',
                htmlspecialchars((string)($stage['stageName'] ?? 'N/A')),
                htmlspecialchars((string)($stage['flapConfig'] ?? 'N/A')),
                htmlspecialchars((string)($stage['targetOffset'] ?? 'N/A')),
                htmlspecialchars((string)($stage['actionPattern'] ?? 'N/A'))
            );
        }
        return $html . '</tbody></table></body></html>';
    }

    // ----------------------- Validation & Error Reporting -----------------------

    private function validateCoreLinks(): array
    {
        $errs = [];
        $zdm = (int)$this->ReadPropertyInteger('ZoningManagerID');
        $adv = (int)$this->ReadPropertyInteger('AdaptiveControlID');

        if ($zdm <= 0 || !IPS_InstanceExists($zdm)) {
            $errs[] = 'ZoningManagerID not set or instance does not exist.';
        }
        if ($adv <= 0 || !IPS_InstanceExists($adv)) {
            $errs[] = 'AdaptiveControlID not set or instance does not exist.';
        }
        return $errs;
    }

    /**
     * Emit error to (1) module log, (2) console Messages window, (3) instance editor (echo).
     */
    private function reportError(string $msg): void
    {
        $this->LogMessage('ORCH ERROR: ' . $msg, KL_ERROR); // module log
        IPS_LogMessage('ORCH', $msg);                       // console Messages window
        echo $msg;                                          // instance editor output
    }
}
