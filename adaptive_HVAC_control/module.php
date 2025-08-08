<?php
/**
 * Adaptive HVAC Control A
 *
 * Version: 2.8 (Epsilon-Schedule, Coil-Watchdog, ZDM Aggregates, Atomic Q-Persist, Console Logging)
 * Author:  Artur Fischer & AI Consultant
 *
 * Kurz:
 *  - Q-Learning-basierte Stellgrößenwahl (Power/Fan)
 *  - Epsilon-Exploration mit Start/Min/Decay
 *  - Coil-Schutz (MinTemp/DropRate) → Aktion 0:0 bei Gefahr
 *  - ZDM-Aggregate optional als State-Features
 *  - Q-Table atomar in Datei (optional) oder Attribut
 *  - Alle Logs im Symcon-Meldungsfenster (IPS_LogMessage via $this->LogMessage)
 */

declare(strict_types=1);

class adaptive_HVAC_control extends IPSModule
{
    // -------------------- Lifecycle --------------------

    public function Create()
    {
        parent::Create();

        // Bestehende Properties (aus deiner Form)
        $this->RegisterPropertyBoolean('ManualOverride', false);
        $this->RegisterPropertyInteger('LogLevel', 3);                 // 0=ERROR,1=WARN,2=INFO,3=DEBUG

        $this->RegisterPropertyFloat('Alpha', 0.05);
        $this->RegisterPropertyFloat('Gamma', 0.90);
        $this->RegisterPropertyFloat('DecayRate', 0.005);

        $this->RegisterPropertyFloat('Hysteresis', 0.5);
        $this->RegisterPropertyInteger('MaxPowerDelta', 40);
        $this->RegisterPropertyInteger('MaxFanDelta', 40);

        $this->RegisterPropertyInteger('ACActiveLink', 0);
        $this->RegisterPropertyInteger('PowerOutputLink', 0);
        $this->RegisterPropertyInteger('FanOutputLink', 0);

        $this->RegisterPropertyInteger('TimerInterval', 60);

        // Actions/Granularität
        $this->RegisterPropertyString('CustomPowerLevels', '0,40,80,100');
        $this->RegisterPropertyInteger('PowerStep', 20);
        $this->RegisterPropertyString('CustomFanSpeeds', '0,40,80,100');
        $this->RegisterPropertyInteger('FanStep', 20);

        // Sensoren
        $this->RegisterPropertyInteger('CoilTempLink', 0);

        // Monitored Rooms (aus deiner Form)
        $this->RegisterPropertyString('MonitoredRooms', '[]');

        // -------------------- NEU (Änderung 1) --------------------

        // Exploration / Epsilon
        $this->RegisterPropertyFloat('EpsilonStart', 0.40);
        $this->RegisterPropertyFloat('EpsilonMin',   0.05);
        $this->RegisterPropertyFloat('EpsilonDecay', 0.995);

        // Coil / Safety
        $this->RegisterPropertyFloat('MinCoilTemp', 2.0);          // °C
        $this->RegisterPropertyFloat('MaxCoilDropRate', 1.5);      // K/min
        $this->RegisterPropertyBoolean('AbortOnCoilFreeze', true);

        // ZDM-Integration
        $this->RegisterPropertyInteger('ZDM_InstanceID', 0);

        // Q-Table Persistenz (optional Datei)
        $this->RegisterPropertyString('QTablePath', '');

        // Attribute
        $this->RegisterAttributeFloat('Epsilon', 0.0);
        $this->RegisterAttributeString('QTable', '{}');            // Fallback, wenn kein Dateipfad
        $this->RegisterAttributeString('LastAction', '0:0');

        // Timer
        $this->RegisterTimer('LearningTimer', 0, 'ADHVAC_ProcessLearning($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $ok = true;
        $this->SetStatus($ok ? 102 : 202);

        // Timer aktivieren (kein orchestrated-Stop hier, damit rückwärtskompatibel)
        $intervalMs = max(1000, (int)$this->ReadPropertyInteger('TimerInterval') * 1000);
        $this->SetTimerInterval('LearningTimer', $intervalMs);

        // Initial Epsilon, falls 0
        if ((float)$this->ReadAttributeFloat('Epsilon') <= 0.0) {
            $this->initExploration(); // Änderung 3
        }

        $this->log(2, 'apply_changes', ['interval_ms'=>$intervalMs]);
    }

    // -------------------- Public Timer-Entry --------------------

    public function ADHVAC_ProcessLearning()
    {
        $this->ProcessLearning();
    }

    public function ProcessLearning(): void
    {
        // Abbruch, wenn manuell
        if ($this->ReadPropertyBoolean('ManualOverride')) {
            $this->log(2, 'manual_override_active');
            return;
        }
        // AC aktiv?
        if (!$this->isTruthyVar($this->ReadPropertyInteger('ACActiveLink'))) {
            $this->log(3, 'ac_inactive_skip');
            return;
        }

        // Coil-Schutz (Änderung 2)
        if (!$this->coilProtectionOk()) {
            $this->applyAction(0, 0);
            return;
        }

        // STATE aufbauen
        $state = $this->buildStateVector(); // inkl. optionaler ZDM-Aggregate (Änderung 4)

        // Aktion wählen (epsilon-greedy)
        [$p, $f] = $this->selectActionEpsilonGreedy($state);

        // Delta-Limits beachten
        [$p, $f] = $this->limitDeltas($p, $f);

        // Anwenden
        $this->applyAction($p, $f);

        // Reward berechnen
        $reward = $this->calculateReward($state, ['p'=>$p, 'f'=>$f]);

        // Q-Update
        $this->qlearnUpdate($state, $p, $f, $reward, /*explore=*/true);

        // Epsilon annealen (Änderung 3)
        $this->annealEpsilon();

        // Persistenz (Änderung 5)
        $this->persistQTableIfNeeded();
    }

    // -------------------- Orchestrator-API (Forcing) --------------------

    /**
     * Forciert eine Aktion "P:F" (z. B. "55:70") und lernt aus dem Outcome.
     * Exploration ist in diesem Pfad **aus**.
     */
    public function ACIPS_ForceActionAndLearn(string $pair): string
    {
        // Coil-Schutz
        if (!$this->coilProtectionOk()) {
            $this->applyAction(0, 0);
            return json_encode(['ok'=>false,'err'=>'coil_protection']);
        }

        $act = $this->validateActionPair($pair);
        if (!$act) {
            $this->log(1, 'force_invalid_pair', ['pair'=>$pair]);
            return json_encode(['ok'=>false,'err'=>'invalid_pair']);
        }
        [$p, $f] = $act;
        [$p, $f] = $this->limitDeltas($p, $f);

        $this->applyAction($p, $f);

        $state  = $this->buildStateVector();
        $reward = $this->calculateReward($state, ['p'=>$p, 'f'=>$f]);

        // Q-Update ohne Exploration
        $this->qlearnUpdate($state, $p, $f, $reward, /*explore=*/false);
        $this->WriteAttributeString('LastAction', $p.':'.$f);
        $this->persistQTableIfNeeded();

        return json_encode(['ok'=>true, 'applied'=>['p'=>$p,'f'=>$f], 'reward'=>$reward]);
    }

    // -------------------- Learning Core --------------------

    private function selectActionEpsilonGreedy(array $state): array
    {
        $pairs = $this->getAllowedActionPairs();
        if (empty($pairs)) return [0, 0];

        $eps = (float)$this->ReadAttributeFloat('Epsilon');
        if (mt_rand() / mt_getrandmax() < $eps) {
            // Explore: zufällige erlaubte Aktion
            $key = array_rand($pairs);
            [$p, $f] = array_map('intval', explode(':', $key));
            $this->log(3, 'select_explore', ['pair'=>$key,'epsilon'=>$eps]);
            return [$p, $f];
        }

        // Exploit: bestes Q
        $bestKey = $this->bestActionForState($state, array_keys($pairs));
        [$p, $f] = array_map('intval', explode(':', $bestKey));
        $this->log(3, 'select_exploit', ['pair'=>$bestKey,'epsilon'=>$eps]);
        return [$p, $f];
    }

    private function qlearnUpdate(array $state, int $p, int $f, float $reward, bool $explore): void
    {
        $alpha = (float)$this->ReadPropertyFloat('Alpha');
        $gamma = (float)$this->ReadPropertyFloat('Gamma');

        $q = $this->loadQTable();

        $sKey = $this->stateKey($state);
        $aKey = $p.':'.$f;

        if (!isset($q[$sKey])) $q[$sKey] = [];
        if (!isset($q[$sKey][$aKey])) $q[$sKey][$aKey] = 0.0;

        // Nächster State (vereinfachend: gleich dem aktuellen; falls du nextState hast, hier einsetzen)
        $maxNext = 0.0;
        foreach ($q[$sKey] as $ak => $val) {
            if ($val > $maxNext) $maxNext = $val;
        }

        $old = $q[$sKey][$aKey];
        $new = (1 - $alpha) * $old + $alpha * ($reward + $gamma * $maxNext);
        $q[$sKey][$aKey] = $new;

        $this->storeQTable($q);
        $this->WriteAttributeString('LastAction', $aKey);
        $this->log(3, 'q_update', ['state'=>$sKey, 'a'=>$aKey, 'old'=>$old, 'new'=>$new, 'r'=>$reward, 'explore'=>$explore]);
    }

    private function buildStateVector(): array
    {
        $rooms = $this->getRooms();
        $hyst  = (float)$this->ReadPropertyFloat('Hysteresis');

        $numActive = 0;
        $maxDelta  = 0.0;
        $anyWindow = false;

        foreach ($rooms as $r) {
            $ist  = $this->getFloat((int)($r['tempID'] ?? 0));
            $soll = $this->getFloat((int)($r['targetID'] ?? 0));
            $win  = $this->roomWindowOpen($r);

            if (is_finite($ist) && is_finite($soll)) {
                $delta = $ist - $soll;
                if (!$win && $delta > $hyst) {
                    $numActive++;
                    $maxDelta = max($maxDelta, $delta);
                }
            }
            $anyWindow = $anyWindow || $win;
        }

        // ZDM Aggregates (Änderung 4)
        $agg = $this->fetchZDMAggregates();
        if ($agg) {
            $numActive = max($numActive, (int)($agg['numActiveRooms'] ?? 0));
            $maxDelta  = max($maxDelta, (float)($agg['maxDeltaT'] ?? 0.0));
            $anyWindow = $anyWindow || !empty($agg['anyWindowOpen']);
        }

        // Coil Temp (falls vorhanden)
        $coil = $this->getFloat($this->ReadPropertyInteger('CoilTempLink'));

        return [
            'numActiveRooms' => $numActive,
            'maxDelta'       => round($maxDelta, 2),
            'anyWindowOpen'  => (int)$anyWindow,
            'coilTemp'       => is_finite($coil) ? round($coil, 2) : null
        ];
    }

    private function calculateReward(array $state, array $action): float
    {
        // Beispielhaft:
        // - Nähe an 0 (maxDelta -> 0) positiv
        // - Hohe Leistung/Lüfter leicht negativ (Energie)
        // - Fenster offen negativ
        $comfort = -($state['maxDelta'] ?? 0.0);               // je kleiner desto besser
        $energy  = -0.01 * (($action['p'] ?? 0) + ($action['f'] ?? 0));
        $window  = -0.5 * (int)($state['anyWindowOpen'] ?? 0);

        // Anti-Flapping
        $penalty = 0.0;
        if (preg_match('/^(\d+):(\d+)$/', $this->ReadAttributeString('LastAction'), $m)) {
            $dp = abs(((int)$m[1]) - ($action['p'] ?? 0));
            $df = abs(((int)$m[2]) - ($action['f'] ?? 0));
            $penalty = -0.002 * ($dp + $df);
        }

        $reward = $comfort + $energy + $window + $penalty;
        return (float)round($reward, 4);
    }

    // -------------------- Coil / Safety (Änderung 2) --------------------

    private function coilProtectionOk(): bool
    {
        if (!$this->ReadPropertyBoolean('AbortOnCoilFreeze')) return true;

        $link = (int)$this->ReadPropertyInteger('CoilTempLink');
        if ($link <= 0 || !IPS_VariableExists($link)) return true;

        $coil = @GetValue($link);
        if (!is_numeric($coil)) return true;

        $coil = (float)$coil;
        $min  = (float)$this->ReadPropertyFloat('MinCoilTemp');
        if ($coil <= $min) {
            $this->log(1, 'coil_below_min', ['coil'=>$coil,'min'=>$min]);
            return false;
        }

        // Abfallrate (K/min) (einfacher Prädiktor)
        $now  = time();
        $key  = 'coil_last';
        $last = $this->GetBuffer($key);
        if ($last) {
            $obj = json_decode($last, true);
            if (isset($obj['t'],$obj['v']) && is_numeric($obj['t']) && is_numeric($obj['v'])) {
                $dt = max(1, $now - (int)$obj['t']);
                $rate = ((float)$obj['v'] - $coil) * 60.0 / $dt; // positiv = fällt
                $maxDrop = (float)$this->ReadPropertyFloat('MaxCoilDropRate');
                if ($rate > $maxDrop) {
                    $this->log(1, 'coil_drop_rate', ['rate_K_min'=>$rate,'max'=>$maxDrop]);
                    return false;
                }
            }
        }
        $this->SetBuffer($key, json_encode(['t'=>$now,'v'=>$coil]));
        return true;
    }

    // -------------------- Epsilon (Änderung 3) --------------------

    private function initExploration(): void
    {
        $this->WriteAttributeFloat('Epsilon', (float)$this->ReadPropertyFloat('EpsilonStart'));
        $this->log(2, 'epsilon_init', ['eps'=>$this->ReadPropertyFloat('EpsilonStart')]);
    }

    private function annealEpsilon(): void
    {
        $eps   = (float)$this->ReadAttributeFloat('Epsilon');
        if ($eps <= 0.0) $eps = (float)$this->ReadPropertyFloat('EpsilonStart');
        $emin  = (float)$this->ReadPropertyFloat('EpsilonMin');
        $decay = (float)$this->ReadPropertyFloat('EpsilonDecay');
        $eps   = max($emin, $eps * $decay);
        $this->WriteAttributeFloat('Epsilon', $eps);
        $this->log(3, 'epsilon_anneal', ['eps'=>$eps]);
    }

    // -------------------- ZDM Aggregates (Änderung 4) --------------------

    private function fetchZDMAggregates(): ?array
    {
        $iid = (int)$this->ReadPropertyInteger('ZDM_InstanceID');
        if ($iid <= 0 || !IPS_InstanceExists($iid)) return null;
        try {
            $json = @ZDM_GetAggregates($iid);
            $arr  = json_decode((string)$json, true);
            return is_array($arr) ? $arr : null;
        } catch (\Throwable $e) {
            $this->log(1, 'zdm_agg_err', ['msg'=>$e->getMessage()]);
            return null;
        }
    }

    // -------------------- Q-Table Persistenz (Änderung 5) --------------------

    private function persistQTableIfNeeded(): void
    {
        $q = $this->loadQTable();
        $this->saveQTableAtomic(json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function loadQTable(): array
    {
        $path = trim($this->ReadPropertyString('QTablePath'));
        if ($path !== '' && is_file($path)) {
            $s = @file_get_contents($path);
            $j = json_decode((string)$s, true);
            if (is_array($j)) return $j;
        }
        $j = json_decode($this->ReadAttributeString('QTable'), true);
        return is_array($j) ? $j : [];
    }

    private function storeQTable(array $q): void
    {
        // Cache in Attribut (für UI), finaler Save in persistQTableIfNeeded()
        $this->WriteAttributeString('QTable', json_encode($q, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function saveQTableAtomic(string $json): void
    {
        $path = trim($this->ReadPropertyString('QTablePath'));
        if ($path === '') {
            // Attribut als Fallback
            $this->WriteAttributeString('QTable', $json);
            return;
        }
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            $this->log(0, 'q_save_tmp_failed', ['tmp'=>$tmp]);
            return;
        }
        if (!@rename($tmp, $path)) {
            $this->log(0, 'q_save_rename_failed', ['from'=>$tmp,'to'=>$path]);
            @unlink($tmp);
            return;
        }
        $this->log(2, 'q_saved', ['path'=>$path]);
    }

    // -------------------- Actions / Outputs --------------------

    private function applyAction(int $p, int $f): void
    {
        $p = max(0, min(100, $p));
        $f = max(0, min(100, $f));
        $this->setPercent($this->ReadPropertyInteger('PowerOutputLink'), $p);
        $this->setPercent($this->ReadPropertyInteger('FanOutputLink'),   $f);
        $this->WriteAttributeString('LastAction', $p.':'.$f);
        $this->log(2, 'apply_action', ['p'=>$p,'f'=>$f]);
    }

    private function limitDeltas(int $p, int $f): array
    {
        if (preg_match('/^(\d+):(\d+)$/', $this->ReadAttributeString('LastAction'), $m)) {
            $lp = (int)$m[1]; $lf = (int)$m[2];
            $dpMax = max(0, (int)$this->ReadPropertyInteger('MaxPowerDelta'));
            $dfMax = max(0, (int)$this->ReadPropertyInteger('MaxFanDelta'));
            if (abs($p - $lp) > $dpMax) $p = ($p > $lp) ? $lp + $dpMax : $lp - $dpMax;
            if (abs($f - $lf) > $dfMax) $f = ($f > $lf) ? $lf + $dfMax : $lf - $dfMax;
        }
        return [$p, $f];
    }

    private function setPercent(int $varID, int $val): void
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return;
        $vt = IPS_GetVariable($varID)['VariableType'] ?? -1;
        switch ($vt) {
            case 0: @RequestAction($varID, $val >= 1); break; // bool → on/off
            case 1: @RequestAction($varID, (int)$val); break; // int
            case 2: @RequestAction($varID, (float)$val); break; // float
            case 3: @RequestAction($varID, (string)$val); break; // string
            default: @SetValue($varID, $val); break;
        }
    }

    // -------------------- Allowed Actions --------------------

    private function getAllowedActionPairs(): array
    {
        // Aus Custom-Listen bauen (Fallback auf gleichmäßige Steps)
        $powers = $this->parseIntList($this->ReadPropertyString('CustomPowerLevels'), 0, 100, $this->ReadPropertyInteger('PowerStep'));
        $fans   = $this->parseIntList($this->ReadPropertyString('CustomFanSpeeds'),   0, 100, $this->ReadPropertyInteger('FanStep'));

        $map = [];
        foreach ($powers as $p) {
            foreach ($fans as $f) {
                $map[$p.':'.$f] = true;
            }
        }
        return $map;
    }

    private function validateActionPair(string $pair): ?array
    {
        if (!preg_match('/^\s*(\d{1,3})\s*:\s*(\d{1,3})\s*$/', $pair, $m)) return null;
        $p = min(100, max(0, (int)$m[1]));
        $f = min(100, max(0, (int)$m[2]));

        $allowed = $this->getAllowedActionPairs();
        if (!$allowed) return [$p, $f];

        $key = $p.':'.$f;
        if (isset($allowed[$key])) return [$p, $f];

        // Nächstliegende erlaubte Aktion
        $best = $this->nearestAction($p, $f, array_keys($allowed));
        [$p, $f] = array_map('intval', explode(':', $best));
        $this->log(1, 'action_adjusted_to_allowed', ['req'=>$key,'adj'=>$best]);
        return [$p, $f];
    }

    private function nearestAction(int $p, int $f, array $keys): string
    {
        $best = null; $bd = PHP_INT_MAX;
        foreach ($keys as $k) {
            [$ap, $af] = array_map('intval', explode(':', $k));
            $d = ($ap - $p) * ($ap - $p) + ($af - $f) * ($af - $f);
            if ($d < $bd) { $bd = $d; $best = $k; }
        }
        return $best ?? '0:0';
    }

    private function bestActionForState(array $state, array $allowedKeys): string
    {
        $q = $this->loadQTable();
        $sKey = $this->stateKey($state);

        $bestKey = $allowedKeys[0] ?? '0:0';
        $bestVal = -INF;

        foreach ($allowedKeys as $k) {
            $val = $q[$sKey][$k] ?? 0.0;
            if ($val > $bestVal) { $bestVal = $val; $bestKey = $k; }
        }
        return $bestKey;
    }

    // -------------------- Utils --------------------

    private function stateKey(array $s): string
    {
        // einfache Serialisierung; für mehr Stabilität ggf. binning/rounding nutzen
        return md5(json_encode($s));
    }

    private function parseIntList(string $csv, int $min, int $max, int $fallbackStep): array
    {
        $arr = array_filter(array_map('trim', explode(',', $csv)), 'strlen');
        $out = [];
        foreach ($arr as $x) {
            if (is_numeric($x)) {
                $v = (int)$x;
                if ($v >= $min && $v <= $max) $out[$v] = true;
            }
        }
        if (empty($out)) {
            // fallback auf Schritte
            for ($v = $min; $v <= $max; $v += max(1, (int)$fallbackStep)) $out[$v] = true;
        }
        ksort($out, SORT_NUMERIC);
        return array_keys($out);
    }

    private function getRooms(): array
    {
        $json = $this->ReadPropertyString('MonitoredRooms') ?: '[]';
        $arr  = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    private function roomWindowOpen(array $room): bool
    {
        // Wenn du Fenster pro Raum hast, hier anschließen; sonst false
        // (Fensterlogik liegt im ZDM; hier nur Platzhalter)
        return false;
    }

    private function getFloat(int $varID): float
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return NAN;
        $v = @GetValue($varID);
        return is_numeric($v) ? (float)$v : NAN;
    }

    private function isTruthyVar(int $varID): bool
    {
        if ($varID <= 0 || !IPS_VariableExists($varID)) return false;
        $v = @GetValue($varID);
        if (is_bool($v))   return $v;
        if (is_numeric($v))return ((float)$v) > 0;
        if (is_string($v)) return in_array(mb_strtolower(trim($v)), ['1','true','on'], true);
        return false;
    }

    // -------------------- Logging (Änderung 6) --------------------

    /**
     * $lvl: 0=ERROR, 1=WARN, 2=INFO, 3=DEBUG
     */
    private function log(int $lvl, string $event, array $data = []): void
    {
        $cfg = (int)$this->ReadPropertyInteger('LogLevel');
        if ($lvl > $cfg) return;

        $line = json_encode(
            ['t'=>time(),'lvl'=>$lvl,'ev'=>$event,'data'=>$data],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $prio = KL_MESSAGE;
        if ($lvl === 0) $prio = KL_ERROR;
        elseif ($lvl === 1) $prio = KL_WARNING;

        $this->LogMessage("ADHVAC ".$line, $prio);
        // Optional zusätzlich:
        // $this->SendDebug('ADHVAC', $line, 0);
    }
}

