<?php
declare(strict_types=1);

final class Monitor
{
    private const CHECK_SELECT = 'SELECT c.*, h.name AS host_name, h.address, h.mode, h.agent_seen, h.agent_version, h.agent_tick, h.id AS hid, h.enabled AS host_enabled,
                h.parent_id, h.snmp AS host_snmp, h.kind_auto AS host_kind_auto, h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                FROM checks c JOIN hosts h ON h.id = c.host_id';

    /** Voert alle checks uit die aan de beurt zijn (of alles bij $force). */
    public static function runDue(bool $force = false): int
    {
        return I18n::forNotify(fn() => self::runDueInLang($force));
    }

    private static function runDueInLang(bool $force = false): int
    {
        $sql = self::CHECK_SELECT . ' WHERE c.enabled = 1 AND h.enabled = 1';
        if (!$force) {
            $iv = self::intervalSql();
            $sql .= " AND (c.last_checked IS NULL OR c.last_checked <= NOW() - INTERVAL ($iv - LEAST(15, $iv DIV 4)) SECOND)";
        }
        $rows = q($sql)->fetchAll();

        // Bovenliggende servers (router, switch…) eerst, en per server de ping eerst:
        // dan weten afhankelijke checks in dezelfde run al of hun route werkt.
        $depth = self::hostDepths();
        usort($rows, fn($a, $b) => [$depth[$a['hid']] ?? 0, $a['type'] !== 'ping', $a['id']]
            <=> [$depth[$b['hid']] ?? 0, $b['type'] !== 'ping', $b['id']]);

        foreach ($rows as $row) {
            self::runOne($row);
        }
        self::releaseQuiet();
        self::flushQueue();
        return count($rows);
    }

    /** Checks die RzMon zelf uitvoert en die bij een probleem sneller mogen (slim interval) */
    private const FAST_TYPES = ['ping', 'tcp', 'http', 'snmp'];

    /**
     * Interval van een check in SQL (1.108.0, slim interval, Instellingen → Monitoring → Advies): bij een probleem
     * (of een mislukte meting) sneller (smart_fast, standaard 20 s, alleen checks die RzMon zelf doet), na een uur stabiel OK
     * trager (hooguit smart_slow × het interval en nooit trager dan een uur, tenzij het interval zelf al langer is).
     */
    private static function intervalSql(): string
    {
        if (setting('smart_interval', '0') !== '1') {
            return 'c.interval_sec';
        }
        $fast = max(10, min(300, (int) setting('smart_fast', '20')));
        $slow = max(1, min(10, (int) setting('smart_slow', '2')));
        $types = "'" . implode("','", self::FAST_TYPES) . "'";
        return "(CASE WHEN c.type IN ($types) AND (c.status <> 0 OR c.fail_count > 0) THEN LEAST(c.interval_sec, $fast)
                 WHEN c.status = 0 AND c.fail_count = 0 AND c.flapping_since IS NULL AND c.status_since < NOW() - INTERVAL 1 HOUR
                 THEN GREATEST(c.interval_sec, LEAST(c.interval_sec * $slow, 3600)) ELSE c.interval_sec END)";
    }

    /**
     * Tussen twee cron-minuten (1.108.0, slim interval): checks met een probleem die sneller moeten, opnieuw uitvoeren
     * tot $deadline (unix-tijd). Zo kan "elke 20 s" ook met een cron die eens per minuut start.
     */
    public static function runFastLoop(float $deadline): int
    {
        if (setting('smart_interval', '0') !== '1') {
            return 0;
        }
        $n = 0;
        $types = "'" . implode("','", self::FAST_TYPES) . "'";
        while (microtime(true) < $deadline - 5) {
            sleep(5);
            $iv = self::intervalSql();
            $rows = q(self::CHECK_SELECT . " WHERE c.enabled = 1 AND h.enabled = 1 AND c.type IN ($types) AND (c.status <> 0 OR c.fail_count > 0)
                      AND c.last_checked <= NOW() - INTERVAL ($iv - LEAST(15, $iv DIV 4)) SECOND")->fetchAll();
            foreach ($rows as $row) {
                if (microtime(true) > $deadline) {
                    break;
                }
                I18n::forNotify(fn() => self::runOne($row));
                $n++;
            }
            if ($rows) {
                self::flushQueue();
            }
        }
        return $n;
    }

    /** Checks die op gegevens van de agent draaien (niet ping/poort/HTTP/certificaat: die doet RzMon zelf). */
    private const AGENT_TYPES = ['disk', 'service', 'cpu', 'ram', 'podman', 'container', 'log', 'command', 'ha', 'pve'];

    /**
     * Net binnengekomen rapport van een agent: de agent-checks van die server die aan de beurt zijn meteen
     * beoordelen, in plaats van bij de volgende cron-minuut. Een check wordt eerst "geclaimd" (last_checked),
     * zodat cron hem niet tegelijk ook doet.
     */
    public static function runAgentHost(int $hostId): int
    {
        return I18n::forNotify(fn() => self::runAgentHostInLang($hostId));
    }

    private static function runAgentHostInLang(int $hostId): int
    {
        $types = "'" . implode("','", self::AGENT_TYPES) . "'";
        $iv = self::intervalSql();
        $rows = q(self::CHECK_SELECT . " WHERE c.host_id = ? AND c.enabled = 1 AND h.enabled = 1 AND h.mode = 'agent' AND c.type IN ($types)
                  AND (c.last_checked IS NULL OR c.last_checked <= NOW() - INTERVAL ($iv - LEAST(15, $iv DIV 4)) SECOND)", [$hostId])->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            $claim = q('UPDATE checks SET last_checked = NOW() WHERE id = ? AND (last_checked <=> ?)', [$row['id'], $row['last_checked']]);
            if ($claim->rowCount() !== 1) {
                continue;   // net door cron of een ander rapport gedaan
            }
            self::runOne($row);
            $n++;
        }
        if ($n) {
            self::flushQueue();
        }
        return $n;
    }

    public static function runCheckById(int $id): ?array
    {
        return I18n::forNotify(fn() => self::runCheckByIdInLang($id));
    }

    private static function runCheckByIdInLang(int $id): ?array
    {
        $row = q(self::CHECK_SELECT . ' WHERE c.id = ?', [$id])->fetch();
        $res = $row ? self::runOne($row) : null;
        self::flushQueue();
        return $res;
    }

    private static function runOne(array $row): array
    {
        $host = [
            'id' => $row['hid'],
            'name' => $row['host_name'],
            'address' => $row['address'],
            'mode' => $row['mode'],
            'agent_seen' => $row['agent_seen'],
            'agent_version' => $row['agent_version'] ?? null,
            'agent_tick' => $row['agent_tick'] ?? null,
            'snmp' => $row['host_snmp'] ?? null,
            'kind_auto' => $row['host_kind_auto'] ?? null,
        ];
        $t0 = microtime(true);
        $row = Smart::apply($row);   // 1.107.0: geleerde drempels van dit uur (slim / slim met vangnet)
        $res = Checks::run($row, $host);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        // 1.131.0: wat er na de meting wordt opgeslagen (resultaat, status, afwijking, voorspelling) in één transactie
        return in_tx(static function () use ($row, $res, $ms): array {
            q('UPDATE checks SET run_ms = ? WHERE id = ?', [$ms, $row['id']]);   // traagste checks (systeemcheck)
            if ($row['type'] === 'disk') {
                $res = Stats::applyDiskForecast($row, $res);
                self::diskJump($row, $res);
            }
            Smart::anomaly($row, $res);   // 1.108.0: afwijking bij checks zonder drempels
            $res = Smart::annotate($row, Checks::reverse($row, $res));
            self::process($row, $res);
            return $res;
        });
    }

    /**
     * Check proberen zonder op te slaan (1.95.0, "🧪 Nu proberen" in het formulier): de kolommen uit het formulier op deze
     * server (of over de bestaande check heen), uitgevoerd in een transactie die daarna wordt teruggedraaid.
     */
    public static function tryCheck(int $hostId, int $checkId, array $cols): array
    {
        $base = $checkId ? q(self::CHECK_SELECT . ' WHERE c.id = ?', [$checkId])->fetch() : false;
        if (!$base) {
            $h = q('SELECT id AS hid, name AS host_name, address, mode, agent_seen, agent_version, agent_tick, enabled AS host_enabled, parent_id, snmp AS host_snmp,
                    kind_auto AS host_kind_auto FROM hosts WHERE id = ?', [$hostId])->fetch();
            if (!$h) {
                throw new InvalidArgumentException(t('Server bestaat niet'));
            }
            // Alle kolommen van een check, leeg (zoals een nieuwe)
            $empty = array_fill_keys(q('SHOW COLUMNS FROM checks')->fetchAll(PDO::FETCH_COLUMN), null);
            $base = array_merge($empty, $h, ['id' => 0, 'host_id' => $hostId, 'status' => STATUS_UNKNOWN, 'fail_count' => 0, 'status_streak' => 0]);
        }
        $row = array_merge($base, $cols);
        $host = ['id' => $row['hid'], 'name' => $row['host_name'], 'address' => $row['address'], 'mode' => $row['mode'], 'agent_seen' => $row['agent_seen'],
            'agent_version' => $row['agent_version'] ?? null, 'agent_tick' => $row['agent_tick'] ?? null, 'snmp' => $row['host_snmp'] ?? null, 'kind_auto' => $row['host_kind_auto'] ?? null];
        db()->beginTransaction();
        try {
            $row = Smart::apply($row);
            $res = Smart::annotate($row, Checks::reverse($row, Checks::run($row, $host)));
        } finally {
            db()->rollBack();
        }
        return $res;
    }

    // ------------------------------------------------------------------ onderhoud

    /** Globaal onderhoud (alles), of null. */
    public static function globalMaintenance(): ?array
    {
        $until = (string) setting('maintenance_until', '');
        if ($until !== '' && strtotime($until) > time()) {
            return ['scope' => 'all', 'until' => $until, 'reason' => (string) setting('maintenance_reason', '')];
        }
        return null;
    }

    /**
     * Actief onderhoud voor een check (globaal, server of check zelf), of null.
     * Verwacht de kolommen maintenance_until/-reason en host_maintenance_until/-reason.
     */
    public static function maintenanceFor(array $check): ?array
    {
        if ($g = self::globalMaintenance()) {
            return $g;
        }
        foreach (['host' => 'host_maintenance_', 'check' => 'maintenance_'] as $scope => $prefix) {
            $until = $check[$prefix . 'until'] ?? null;
            if ($until && strtotime($until) > time()) {
                return ['scope' => $scope, 'until' => $until, 'reason' => (string) ($check[$prefix . 'reason'] ?? ''),
                    'auto' => $scope === 'check' && !empty($check['maintenance_auto'])];
            }
        }
        // 1.108.0: vast onderhoudsvenster (elke dag of op één weekdag)
        $hid = (int) ($check['hid'] ?? $check['host_id'] ?? 0);
        if (!empty($check['id']) && $hid && ($w = Advice::windowFor((int) $check['id'], $hid))) {
            return ['scope' => 'window', 'until' => $w['until'], 'reason' => $w['reason'], 'auto' => false];
        }
        return null;
    }

    // ------------------------------------------------------------------ afhankelijkheden

    /** Diepte van elke server in de afhankelijkheidsboom (0 = geen parent). */
    public static function hostDepths(): array
    {
        $parents = [];
        foreach (q('SELECT id, parent_id FROM hosts') as $h) {
            $parents[(int) $h['id']] = $h['parent_id'] !== null ? (int) $h['parent_id'] : null;
        }
        $depth = [];
        foreach (array_keys($parents) as $id) {
            $d = 0;
            $seen = [$id => true];
            for ($p = $parents[$id]; $p !== null && isset($parents[$p]) && !isset($seen[$p]) && $d < 20; $p = $parents[$p]) {
                $seen[$p] = true;
                $d++;
            }
            $depth[$id] = $d;
        }
        return $depth;
    }

    /**
     * Is een server onbereikbaar? Met ping-checks: alle ping-checks CRITICAL.
     * Zonder ping: alle TCP/HTTP-checks CRITICAL. Zonder netwerkchecks: nooit.
     */
    public static function hostUnreachable(int $hostId): bool
    {
        $rows = q("SELECT type, status FROM checks WHERE host_id = ? AND enabled = 1 AND type IN ('ping','tcp','http') AND reverse_ok IS NULL AND info_only = 0", [$hostId])->fetchAll();
        $pings = array_filter($rows, fn($r) => $r['type'] === 'ping');
        $relevant = $pings ?: $rows;
        if (!$relevant) {
            return false;
        }
        foreach ($relevant as $r) {
            if ((int) $r['status'] !== STATUS_CRIT) {
                return false;
            }
        }
        return true;
    }

    /** Eerste onbereikbare server boven deze server in de keten, of null. */
    public static function upstreamDown(int $hostId): ?array
    {
        $seen = [$hostId => true];
        $current = $hostId;
        for ($i = 0; $i < 20; $i++) {
            $parent = q('SELECT p.id, p.name, p.enabled FROM hosts h JOIN hosts p ON p.id = h.parent_id WHERE h.id = ?', [$current])->fetch();
            if (!$parent || isset($seen[(int) $parent['id']])) {
                return null;
            }
            if ((int) $parent['enabled'] === 1 && self::hostUnreachable((int) $parent['id'])) {
                return ['id' => (int) $parent['id'], 'name' => $parent['name']];
            }
            $seen[(int) $parent['id']] = true;
            $current = (int) $parent['id'];
        }
        return null;
    }

    // ------------------------------------------------------------------ verwerking

    private static function process(array $check, array $res): void
    {
        $status = $res['status'];
        $failCount = $status === STATUS_OK ? 0 : (int) $check['fail_count'] + 1;
        // Hoe vaak achter elkaar deze status (per status: van warning naar critical begint de telling opnieuw)
        $streak = $status === STATUS_OK ? 0 : ($status === (int) $check['status'] ? (int) ($check['status_streak'] ?? 0) + 1 : 1);
        $notified = (int) $check['notified_status'];
        $notifiedAt = $check['notified_at'];
        $message = $res['message'];

        q('INSERT INTO results (check_id, status, value, vals) VALUES (?, ?, ?, ?)',
            [$check['id'], $status, $res['value'], !empty($res['vals']) ? json_encode($res['vals']) : null]);

        // Onderdrukken: een server erboven is onbereikbaar → dat is al gemeld
        $upstream = $status !== STATUS_OK ? self::upstreamDown((int) $check['hid']) : null;
        if ($upstream) {
            $message .= ' (' . t('onderdrukt: {name} is onbereikbaar', ['name' => $upstream['name']]) . ')';
        }
        // Automatisch onderhoud (na herhaalde meldingen) stopt vanzelf zodra de check weer OK is;
        // dan gaat de herstelmelding hieronder gewoon uit.
        $autoEnded = false;
        if ($status === STATUS_OK && !empty($check['maintenance_auto'])) {
            q('UPDATE checks SET maintenance_until = NULL, maintenance_reason = NULL, maintenance_auto = 0 WHERE id = ?', [$check['id']]);
            $check['maintenance_until'] = $check['maintenance_reason'] = null;
            $check['maintenance_auto'] = 0;
            $autoEnded = true;
        }
        // Onderhoud: checks draaien door, maar er gaan geen meldingen uit. notified_status
        // blijft staan, zodat na afloop alsnog gemeld wordt als er dan nog iets mis is.
        $silenced = $upstream !== null || self::maintenanceFor($check) !== null;

        // Begin van de huidige storing: hoort bij de bevestig-link (zie ackToken)
        $problemSince = $status === STATUS_OK ? null : ($check['problem_since'] ?: now());
        $check['problem_since'] = $problemSince;
        $acked = $check['acked_at'] !== null;
        // Sinds wanneer geldt deze status? Voor "pas melden na X minuten": elke status heeft zijn eigen klok
        $statusSince = $status !== (int) $check['status'] || !$check['status_since'] ? now() : $check['status_since'];
        $age = time() - (int) strtotime($statusSince);

        // Status eerst opslaan: meldingen kijken (via hostUnreachable) naar de actuele status
        q('UPDATE checks SET status = ?, last_value = ?, last_message = ?, last_checked = ?, fail_count = ?, status_streak = ?, suppressed_by = ?, problem_since = ?, status_since = ? WHERE id = ?', [
            $status, $res['value'], mb_substr($message, 0, 500), now(), $failCount, $streak, $upstream['id'] ?? null, $problemSince, $statusSince, $check['id'],
        ]);
        // Statuswijziging bewaren (popup van de check), met de reden als er zeker geen melding uitgaat
        if ($status !== (int) $check['status']) {
            $note = $upstream ? t('onderdrukt: {name} is onbereikbaar', ['name' => $upstream['name']])
                : (self::maintenanceFor($check) !== null ? t('onderhoud') : ($acked && $status !== STATUS_OK ? t('storing bevestigd') : null));
            q('INSERT INTO status_changes (check_id, from_status, to_status, message, value, note) VALUES (?, ?, ?, ?, ?, ?)',
                [$check['id'], (int) $check['status'], $status, mb_substr($res['message'], 0, 500), $res['value'], $note]);
        }

        // Info van de check zelf (herstart, vernieuwd certificaat); onderdrukt door een onbereikbare server erboven: niet melden
        if (!empty($res['info']) && $upstream === null) {
            self::infoCheck($check, (string) $res['info']);
        }

        $s = settings();
        $title = sprintf('%s – %s', $check['host_name'], $check['name']);
        $cfg = NotifyRules::forCheck($check);

        // Info-check: nooit een storing; bij een echte statuswissel (na het ingestelde aantal mislukte checks) één info-melding
        if ((int) ($check['info_only'] ?? 0) === 1) {
            if ($upstream === null && $status !== $notified && ($status === STATUS_OK || self::failsReached($check, $streak, $age))) {
                self::infoCheck($check, ($status === STATUS_OK ? t('weer OK') : t('nu {status}', ['status' => STATUS_LABELS[$status]])) . ': ' . $message);
                $notified = $status;
            }
            q('UPDATE checks SET notified_status = ?, notified_at = NULL, flapping_since = NULL, flap_notified = NULL WHERE id = ?', [$notified, $check['id']]);
            return;
        }

        // Weer OK: Emergency-herhaling van Pushover meteen stoppen
        if ($status === STATUS_OK && $check['pushover_receipt']) {
            Pushover::cancel($check['pushover_receipt']);
            q('UPDATE checks SET pushover_receipt = NULL WHERE id = ?', [$check['id']]);
        }

        // Anti-flapping: wisselt een check vaak van status, dan één melding "staat te flappen", daarna per
        // status hooguit één melding, tot hij een tijd stabiel is (Instellingen → 🔔 Meldingen)
        $flap = $cfg['flap'];
        $flapping = $flap['on'] && $check['flapping_since'] !== null;
        $flapSent = $flapping && (string) $check['flap_notified'] !== '' ? array_map('intval', explode(',', (string) $check['flap_notified'])) : [];
        $flapEvent = null;
        $flapChanges = 0;
        if ($flap['on'] && !$flapping && $status !== (int) $check['status']) {
            // Wisselingen binnen het venster, maar niet van vóór het moment dat hij de vorige keer weer stabiel werd
            $sts = q('SELECT status FROM results WHERE check_id = ? AND created_at >= NOW() - INTERVAL ? MINUTE AND created_at >= ? ORDER BY id',
                [$check['id'], $flap['window'], $check['flap_ended_at'] ?? '1970-01-01'])->fetchAll(PDO::FETCH_COLUMN);
            for ($i = 1; $i < count($sts); $i++) {
                $flapChanges += (int) $sts[$i] !== (int) $sts[$i - 1] ? 1 : 0;
            }
            if ($flapChanges >= $flap['changes']) {
                [$flapping, $flapEvent, $flapSent] = [true, 'start', [$status]];
            }
        } elseif ($flapping && $age >= $flap['stable'] * 60) {
            [$flapping, $flapEvent] = [false, 'stable'];
        }
        $prioOf = static fn(int $st) => $st === STATUS_OK ? -1 : (int) ($st === STATUS_CRIT ? ($s['pushover_priority_crit'] ?? 1) : ($s['pushover_priority_warn'] ?? 0));
        $iconOf = [STATUS_OK => '✅', STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'];

        // Ook bij flappen geldt "melden na N mislukte checks"; herstel alleen als er een probleem gemeld was
        $countOk = $status === STATUS_OK ? $notified !== STATUS_OK : self::failsReached($check, $streak, $age);
        if ($silenced) {
            // niets versturen
        } elseif ($flapEvent === 'start' && !$countOk) {
            // flapt, maar nog te kort mis: stil bijhouden; de melding komt pas als het aantal gehaald is
            $flapSent = [];
        } elseif ($flapEvent === 'start') {
            if (!$acked) {
                self::enqueue($check, $status, 'flap', $notified, '🔀 ' . t('FLAPPEND') . ": $title",
                    t('{title} staat te flappen: {n}× van status gewisseld in {window} min (nu {status}). Per status komt er nog hooguit één melding, tot hij {stable} min stabiel is.',
                        ['title' => $title, 'n' => $flapChanges, 'window' => $flap['window'], 'status' => STATUS_LABELS[$status], 'stable' => $flap['stable']]) . "\n$message", $prioOf($status), $res['value']);
            }
            $notified = $status;
            $notifiedAt = now();
        } elseif ($flapping) {
            // Per status (warning, critical, onbekend, hersteld) hooguit één melding zolang hij flapt
            $r = $cfg['rules'][NotifyRules::KEYS[$status]];
            $on = $r['mode'] !== 'never' && ($status !== STATUS_UNKNOWN || ($s['notify_unknown'] ?? '1') === '1');
            if ($status !== $notified && !in_array($status, $flapSent, true) && $countOk) {
                if ($on && !$acked) {
                    self::enqueue($check, $status, $status === STATUS_OK ? 'recovery' : 'problem', $notified,
                        $status === STATUS_OK ? '✅ ' . t('HERSTELD') . ": $title" : sprintf('%s %s: %s', $iconOf[$status], STATUS_LABELS[$status], $title),
                        $message . ' (' . t('flapt: verdere wisselingen worden niet gemeld') . ')', $prioOf($status), $res['value']);
                    $notified = $status;
                    $notifiedAt = now();
                }
                $flapSent[] = $status;
            }
        } elseif ($flapEvent === 'stable') {
            // Stil geflapt (niets gemeld) en nu stabiel OK: dan ook geen "stabiel"-melding
            if ((!$acked || $status === STATUS_OK) && ($status !== STATUS_OK || $flapSent || $notified !== STATUS_OK)) {
                self::enqueue($check, $status, 'stable', $notified, "{$iconOf[$status]} " . t('STABIEL') . ": $title",
                    t('{title} flapt niet meer: al {min} min {status}.', ['title' => $title, 'min' => $flap['stable'], 'status' => STATUS_LABELS[$status]]) . "\n$message", $prioOf($status), $res['value']);
            }
            $notified = $status;
            $notifiedAt = $status === STATUS_OK ? null : now();
            if ($status === STATUS_OK && $acked) {
                q('UPDATE checks SET acked_at = NULL, acked_by = NULL, ack_comment = NULL WHERE id = ?', [$check['id']]);
            }
        } elseif ($status === STATUS_OK) {
            if ($notified !== STATUS_OK) {
                // Er was een probleem gemeld: herstelmelding direct, of pas als het X minuten OK blijft.
                // Gaat het in die tijd weer mis, dan blijft het dezelfde storing (notified_status blijft staan).
                $r = $cfg['rules']['recovery'];
                $wanted = $r['mode'] !== 'never' && (!$acked || ($s['notify_recovery_acked'] ?? '0') === '1');
                if ($autoEnded || ($wanted && ($r['mode'] === 'now' || $age >= $r['min'] * 60))) {
                    self::enqueue($check, STATUS_OK, 'recovery', $notified, '✅ ' . t('HERSTELD') . ": $title",
                        $message . ($autoEnded ? "\n" . t('Automatisch onderhoud beëindigd.') : ''), -1, $res['value']);
                    $notified = STATUS_OK;
                    $notifiedAt = null;
                } elseif (!$wanted) {
                    $notified = STATUS_OK;
                    $notifiedAt = null;
                }
            }
            // Storing echt voorbij: bevestiging vervalt
            if ($notified === STATUS_OK && $acked) {
                q('UPDATE checks SET acked_at = NULL, acked_by = NULL, ack_comment = NULL WHERE id = ?', [$check['id']]);
            }
        } elseif ($acked) {
            // Bevestigd: geen meldingen meer voor deze storing (ook niet bij erger worden)
        } else {
            $r = $cfg['rules'][NotifyRules::KEYS[$status]];
            $enabled = $r['mode'] !== 'never' && ($status !== STATUS_UNKNOWN || ($s['notify_unknown'] ?? '1') === '1');
            $isRepeat = $status === $notified;
            // Eerste melding: de regel (direct / na X minuten) én zoveel mislukte checks achter elkaar in deze status
            $due = $enabled && ($isRepeat
                ? $r['repeat'] > 0 && $notifiedAt !== null && strtotime($notifiedAt) <= time() - $r['repeat'] * 60
                : ($r['mode'] === 'now' || $age >= $r['min'] * 60) && self::failsReached($check, $streak, $age));
            // 1.107.0: eerst het herstelcommando op de server proberen; pas melden als dat niet helpt (of te lang duurt)
            if ($due && !$isRepeat && Heal::hold($check)) {
                $due = false;
            }
            if ($due) {
                $isWarnish = $status !== STATUS_CRIT;
                $icon = [STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'][$status];
                $prio = (int) ($isWarnish ? ($s['pushover_priority_warn'] ?? 0) : ($s['pushover_priority_crit'] ?? 1));
                $extra = '';
                if ($status === STATUS_CRIT && in_array($check['type'], ['ping', 'tcp', 'http'], true) && $check['reverse_ok'] === null) {
                    $children = (int) q('SELECT COUNT(*) FROM hosts WHERE parent_id = ? AND enabled = 1', [$check['hid']])->fetchColumn();
                    if ($children > 0 && self::hostUnreachable((int) $check['hid'])) {
                        $extra = "\n" . t('Meldingen van {n} afhankelijke server(s) worden onderdrukt.', ['n' => $children]);
                    }
                }
                // 1.108.0: wat er vlak ervoor gebeurde op deze server (update, herstart, wijziging): een hint naar de oorzaak
                if (!$isRepeat && ($why = Context::recent($check)) !== '') {
                    $extra .= "\n🕵️ " . $why;
                }
                self::enqueue($check, $status, $isRepeat ? 'reminder' : 'problem', $notified,
                    sprintf('%s %s: %s', $icon, STATUS_LABELS[$status], $title), $message . $extra, $prio, $res['value']);
                $notified = $status;
                $notifiedAt = now();
            } elseif ($enabled && $status === $notified && in_array($status, [STATUS_WARN, STATUS_CRIT], true) && (int) ($check['escalated'] ?? 0) === 0
                && ($esc = self::escalation($check, $status, $age)) !== null) {
                // 1.108.0: escaleren: al zo lang open en niet bevestigd → nog een keer, een stap hoger
                self::enqueue($check, $status, 'escalate', $notified, '⏫ ' . t('ESCALATIE') . ': ' . sprintf('%s – %s', STATUS_LABELS[$status], $title),
                    t('Al {m} min {s} en niet bevestigd.', ['m' => (int) round($age / 60), 's' => STATUS_LABELS[$status]]) . "\n" . $message, $esc, $res['value']);
                q('UPDATE checks SET escalated = 1 WHERE id = ?', [$check['id']]);
            }
        }
        // Een nieuwe storing (of herstel) mag weer escaleren
        if ((int) ($check['escalated'] ?? 0) === 1 && ($status === STATUS_OK || $status !== (int) $check['status'])) {
            q('UPDATE checks SET escalated = 0 WHERE id = ?', [$check['id']]);
        }

        q('UPDATE checks SET notified_status = ?, notified_at = ?, flapping_since = ?, flap_notified = ?, flap_ended_at = ? WHERE id = ?', [
            $notified, $notifiedAt, $flapping ? ($check['flapping_since'] ?: now()) : null, $flapping ? implode(',', array_unique($flapSent)) : null,
            $flapEvent === 'stable' ? date('Y-m-d H:i:s', time() - $age) : $check['flap_ended_at'], $check['id'],
        ]);
    }

    /**
     * Escalatie (1.108.0, Instellingen → Meldingen → Meldregels en stille uren): na zoveel minuten open en onbevestigd de prioriteit van de herhaling,
     * of null (uit, nog niet, of stille uren als dat zo ingesteld is). Warning → de prioriteit van critical; critical → de ingestelde.
     */
    private static function escalation(array $check, int $status, int $age): ?int
    {
        if (setting('esc_on', '0') !== '1') {
            return null;
        }
        $min = (int) setting($status === STATUS_CRIT ? 'esc_crit_min' : 'esc_warn_min', $status === STATUS_CRIT ? '15' : '30');
        if ($min <= 0 || $age < $min * 60) {
            return null;
        }
        if (setting('esc_quiet', '1') === '1' && NotifyRules::quietUntil(NotifyRules::quietFor($check)) !== null) {
            return null;   // stille uren: niet escaleren (na afloop komt de samenvatting)
        }
        return $status === STATUS_CRIT ? max(-1, min(2, (int) setting('esc_crit_prio', '2'))) : max(0, min(2, (int) setting('pushover_priority_crit', '1')));
    }

    /**
     * Plotselinge groei van een disk (1.108.0): zoveel procentpunten erbij in het laatste uur → ℹ️ (hooguit eens per 6 uur).
     * Naast "vol over X dagen": dit vangt een log of download die uit de hand loopt.
     */
    private static function diskJump(array $row, array $res): void
    {
        if (setting('disk_jump_on', '1') !== '1' || !is_numeric($res['value'] ?? null)) {
            return;
        }
        $prev = q('SELECT value FROM results WHERE check_id = ? AND created_at <= NOW() - INTERVAL 55 MINUTE AND created_at >= NOW() - INTERVAL 90 MINUTE AND value IS NOT NULL
                   ORDER BY id DESC LIMIT 1', [$row['id']])->fetchColumn();
        $jump = $prev === false ? 0.0 : (float) $res['value'] - (float) $prev;
        $key = 'disk_jump_' . $row['id'];
        if ($jump < max(1.0, (float) setting('disk_jump_pct', '5')) || strtotime((string) setting($key, '')) > time() - 6 * 3600) {
            return;
        }
        save_setting($key, now());
        $now = (float) $res['value'];
        self::sysInfo('disk_jump', (int) $row['hid'], (string) $row['host_name'], '💾 ' . $row['host_name'] . ' – ' . $row['name'],
            fn() => t('Groeit hard: +{j} procentpunt in een uur (nu {n}%). Een log of download die uit de hand loopt?', ['j' => I18n::dec((string) round($jump, 1)), 'n' => I18n::dec((string) round($now, 1))]));
    }

    /**
     * Is "melden na N" bereikt? N keer achter elkaar deze status. Met slim interval (1.108.2) checkt RzMon bij een probleem
     * sneller (bv. elke 20 s); dan telt het ook in tijd: minstens zo lang als N gewone checks zouden duren
     * ((N-1) × het gewone interval, met wat speling voor de cron). Zo blijft een korte hapering stil, net als zonder slim interval.
     */
    public static function failsReached(array $check, int $streak, int $age): bool
    {
        return $streak >= self::failsNeeded($check) && $age >= self::failsWindow($check);
    }

    /**
     * Zo lang moet het met slim interval minstens mis zijn: (N-1) × het gewone interval, min wat speling.
     * 0 zonder slim interval, bij N = 1, en bij checks die niet sneller gaan (agent, heartbeat …).
     */
    public static function failsWindow(array $check): int
    {
        if (setting('smart_interval', '0') !== '1' || !in_array($check['type'] ?? '', self::FAST_TYPES, true)) {
            return 0;
        }
        $iv = max(10, (int) ($check['interval_sec'] ?? 60));
        return max(0, (self::failsNeeded($check) - 1) * $iv - min(15, intdiv($iv, 4)));
    }

    /** Zoveel mislukte checks achter elkaar voordat er een melding uitgaat: van de check zelf, anders algemeen */
    public static function failsNeeded(array $check): int
    {
        return max(1, min(100, (int) ($check['notify_fails'] ?? 0) ?: (int) setting('notify_fails', '1')));
    }

    // ------------------------------------------------------------------ wachtrij en bundelen

    /** Melding in de wachtrij; flushQueue() verstuurt hem (per server gebundeld). */
    private static function enqueue(array $check, int $status, string $event, int $previous, string $title, string $message, int $priority, $value): void
    {
        q('INSERT INTO notify_queue (host_id, check_id, status, event, previous, title, message, priority, value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $check['hid'], $check['id'], $status, $event, $previous, mb_substr($title, 0, 250), mb_substr($message, 0, 1000), $priority,
            is_numeric($value) ? (float) $value : null,
        ]);
    }

    /**
     * Wachtrij versturen. Per server: is de oudste melding ouder dan het bundelvenster (Instellingen →
     * Alarmgedrag, standaard 120 s), dan gaat alles van die server in één bericht. 0 = niet bundelen.
     */
    public static function flushQueue(): void
    {
        I18n::forNotify(fn() => self::flushQueueInLang());
    }

    private static function flushQueueInLang(): void
    {
        $win = max(0, (int) setting('notify_group_seconds', 120));
        $groups = q('SELECT host_id, UNIX_TIMESTAMP(MIN(created_at)) AS first, MAX(status <> 0) AS problem FROM notify_queue GROUP BY host_id')->fetchAll();
        $due = array_filter($groups, static fn($h) => $win === 0 || (int) $h['first'] <= time() - $win);
        if (!$due) {
            return;   // nog even wachten op meer meldingen
        }
        // 1.107.0: gaan er meerdere servers tegelijk mis, dan één bericht voor allemaal, met de waarschijnlijke oorzaak
        if ($win > 0 && Bundle::on() && count(array_filter($groups, static fn($h) => (int) $h['problem'])) >= 2) {
            $items = [];
            foreach ($groups as $h) {
                array_push($items, ...self::takeQueued((int) $h['host_id']));
            }
            if (count(array_unique(array_map(static fn($x) => $x['check']['hid'], $items))) >= 2) {
                self::sendGroup($items, Bundle::title($items, Bundle::cause($items)));
            } elseif (count($items) === 1) {
                self::sendItem($items[0]['check'], $items[0]['item']);
            } elseif ($items) {
                self::sendGroup($items);
            }
            return;
        }
        foreach ($due as $h) {
            $items = self::takeQueued((int) $h['host_id']);
            if ($win === 0 || count($items) === 1) {
                foreach ($items as $x) {
                    self::sendItem($x['check'], $x['item']);
                }
            } elseif ($items) {
                self::sendGroup($items);
            }
        }
    }

    /** Meldingen van één server uit de wachtrij halen (behalve wat in de stille uren valt) */
    private static function takeQueued(int $hostId): array
    {
        $items = [];
        foreach (q('SELECT * FROM notify_queue WHERE host_id = ? ORDER BY id', [$hostId])->fetchAll() as $it) {
            // Eerst uit de wachtrij halen: zo verstuurt een tweede proces (cron + "Nu checken") hem niet nog eens
            if (q('DELETE FROM notify_queue WHERE id = ?', [$it['id']])->rowCount() !== 1) {
                continue;
            }
            $c = q(self::CHECK_SELECT . ' WHERE c.id = ?', [$it['check_id']])->fetch();
            if ($c && NotifyRules::quietUntil(NotifyRules::quietFor($c)) !== null) {
                // Stille uren: niet versturen. Onthouden wat de gebruiker het laatst kreeg; na afloop volgt één samenvatting.
                q('UPDATE checks SET quiet_held = COALESCE(quiet_held, ?) WHERE id = ?', [(int) $it['previous'], $c['id']]);
            } elseif ($c) {
                $items[] = ['item' => $it, 'check' => $c];
            }
        }
        return $items;
    }

    /**
     * Einde van de stille uren: wat er tijdens de stilte gemeld had moeten worden, in één bericht.
     * Alleen wat nu nog mis is ("nog open sinds 02:14"), en herstel van een probleem dat je vóór de stilte al kreeg.
     * Wat 's nachts misging en vanzelf herstelde, komt er niet in.
     */
    public static function releaseQuiet(): void
    {
        I18n::forNotify(fn() => self::releaseQuietInLang());
    }

    private static function releaseQuietInLang(): void
    {
        $s = settings();
        $items = [];
        foreach (q(self::CHECK_SELECT . ' WHERE c.quiet_held IS NOT NULL ORDER BY h.name, c.name')->fetchAll() as $c) {
            if (NotifyRules::quietUntil(NotifyRules::quietFor($c)) !== null
                || q('UPDATE checks SET quiet_held = NULL WHERE id = ? AND quiet_held IS NOT NULL', [$c['id']])->rowCount() !== 1) {
                continue;   // nog stil, of een ander proces is ermee bezig
            }
            if (!(int) $c['enabled'] || !(int) $c['host_enabled'] || $c['acked_at'] !== null || self::maintenanceFor($c) !== null || $c['suppressed_by'] !== null) {
                continue;
            }
            $st = (int) $c['status'];
            $held = (int) $c['quiet_held'];
            $rules = NotifyRules::forCheck($c)['rules'];
            if ($st !== STATUS_OK) {
                if ($rules[NotifyRules::KEYS[$st]]['mode'] === 'never' || ($st === STATUS_UNKNOWN && ($s['notify_unknown'] ?? '1') !== '1')) {
                    continue;
                }
                $since = $c['problem_since'] ? date('H:i', strtotime($c['problem_since'])) : '?';
                $prio = (int) ($st === STATUS_CRIT ? ($s['pushover_priority_crit'] ?? 1) : ($s['pushover_priority_warn'] ?? 0));
                $items[] = ['check' => $c, 'item' => ['status' => $st, 'event' => 'quiet', 'previous' => $held, 'priority' => $prio, 'value' => $c['last_value'],
                    'title' => sprintf('%s %s: %s – %s', [STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'][$st], STATUS_LABELS[$st], $c['host_name'], $c['name']),
                    'message' => t('Nog open sinds {t}', ['t' => $since]) . ': ' . $c['last_message']]];
            } elseif ($held !== STATUS_OK && $rules['recovery']['mode'] !== 'never') {
                $items[] = ['check' => $c, 'item' => ['status' => STATUS_OK, 'event' => 'recovery', 'previous' => $held, 'priority' => -1, 'value' => $c['last_value'],
                    'title' => '✅ ' . t('HERSTELD') . ": {$c['host_name']} – {$c['name']}", 'message' => (string) $c['last_message']]];
            }
        }
        if ($items) {
            $open = count(array_filter($items, fn($x) => $x['item']['status'] !== STATUS_OK));
            $fixed = count($items) - $open;
            self::sendGroup($items, '🌅 ' . t('Na de stille uren') . ': ' . implode(', ', array_filter([
                $open ? ($open === 1 ? t('1 probleem open') : t('{n} problemen open', ['n' => $open])) : '', $fixed ? t('{n} hersteld', ['n' => $fixed]) : ''])));
        }
    }

    private static function channelsFor(array $check, int $status): array
    {
        return NotifyRules::forCheck($check)['channels'][NotifyRules::KEYS[$status]];
    }

    private static function sendItem(array $check, array $it): void
    {
        $status = (int) $it['status'];
        self::notify($check, ['value' => $it['value']], $status, $it['title'], $it['message'], (int) $it['priority'], $it['event'],
            (int) $it['previous'], 'notify', self::channelsFor($check, $status));
        if (in_array($it['event'], ['problem', 'reminder'], true)) {
            self::autoMaintenance($check, ['value' => $it['value']], $status, sprintf('%s – %s', $check['host_name'], $check['name']));
        }
    }

    /** Meerdere meldingen in één bericht (per server, of na de stille uren van meerdere servers), met de ergste status in de titel. */
    private static function sendGroup(array $items, ?string $title = null): void
    {
        $rank = [STATUS_OK => 0, STATUS_UNKNOWN => 1, STATUS_WARN => 2, STATUS_CRIT => 3];
        $icon = [STATUS_OK => '✅', STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'];
        $host = $items[0]['check']['host_name'];
        $worst = STATUS_OK;
        $events = [];
        $prio = -2;
        $channels = array_fill_keys(NotifyRules::CHANNELS, false);
        $lines = [];
        $acks = [];
        $multi = $title !== null || count(array_unique(array_map(fn($x) => $x['check']['hid'], $items))) > 1;   // servernaam erbij
        foreach ($items as $x) {
            [$it, $c] = [$x['item'], $x['check']];
            $st = (int) $it['status'];
            $worst = $rank[$st] > $rank[$worst] ? $st : $worst;
            $events[$it['event']] = true;
            $prio = max($prio, (int) $it['priority']);
            foreach (self::channelsFor($c, $st) as $k => $on) {
                $channels[$k] = $channels[$k] || $on;
            }
            $what = match ($it['event']) {
                'recovery' => t('hersteld'),
                'quiet' => STATUS_LABELS[$st],
                'reminder' => t('nog steeds {status}', ['status' => STATUS_LABELS[$st]]),
                'flap' => '🔀 ' . t('flapt, nu {status}', ['status' => STATUS_LABELS[$st]]),
                'stable' => t('weer stabiel, {status}', ['status' => STATUS_LABELS[$st]]),
                'escalate' => '⏫ ' . t('nog niet bevestigd, {status}', ['status' => STATUS_LABELS[$st]]),
                default => STATUS_LABELS[$st],
            };
            $lines[] = $icon[$st] . ' ' . ($multi ? "{$c['host_name']} – " : '') . "{$c['name']} ($what): " . strtok($it['message'], "\n");
            if ($st !== STATUS_OK && ($u = self::ackUrl($c))) {
                $acks[] = '✔ ' . ($multi ? "{$c['host_name']} – " : '') . "{$c['name']}: $u";
            }
        }
        $n = count($items);
        $title ??= array_keys($events) === ['recovery'] ? '✅ ' . t('HERSTELD') . ": $host – $n checks"
            : (array_keys($events) === ['reminder'] ? "🔁 $host: " . t('nog steeds {n} problemen', ['n' => $n]) : "{$icon[$worst]} " . STATUS_LABELS[$worst] . ": $host – " . t('{n} meldingen', ['n' => $n]));
        $body = implode("\n", $lines);
        $errors = [];

        $pushover = null;
        if (setting('pushover_enabled', '0') === '1' && $channels['pushover']) {
            $base = rtrim((string) config('base_url', ''), '/');
            $r = Pushover::send($title, $body . "\n" . date('d-m-Y H:i:s'), $prio, null, null, $base !== '' ? ['url' => "$base/", 'url_title' => 'Open RzMon'] : []);
            $pushover = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'Pushover: ' . $r['error'];
            }
            if (!empty($r['receipt'])) {
                foreach ($items as $x) {
                    if ((int) $x['item']['status'] === STATUS_CRIT) {
                        q('UPDATE checks SET pushover_receipt = ? WHERE id = ?', [$r['receipt'], $x['check']['id']]);
                    }
                }
            }
        }
        $email = null;
        if (setting('email_enabled', '0') === '1' && $channels['email'] && (setting('email_mode', 'always') !== 'fallback' || $pushover !== true)) {
            $base = rtrim((string) config('base_url', ''), '/');
            $r = Mailer::send($title, $body . "\n\n" . t('Tijd') . ': ' . date('d-m-Y H:i:s')
                . ($acks ? "\n\n" . t('Bevestigen (geen meldingen meer voor die storing):') . "\n" . implode("\n", $acks) : '')
                . ($base !== '' ? "\n\nRzMon: $base/" : ''));
            $email = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'E-mail: ' . $r['error'];
            }
        }
        // Home Assistant krijgt wel een bericht per check (automatiseringen kijken naar de check)
        foreach ($items as $x) {
            [$it, $c] = [$x['item'], $x['check']];
            $st = (int) $it['status'];
            $hook = null;
            if (setting('ha_webhook_enabled', '0') === '1' && self::channelsFor($c, $st)['webhook']) {
                $r = Webhook::send(self::webhookPayload($c, ['value' => $it['value']], $st, $it['title'], $it['message'], $it['event'], (int) $it['previous']));
                $hook = $r['ok'];
            }
            q('INSERT INTO alerts (check_id, status, title, message, sent_ok, webhook_ok, email_ok, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $c['id'], $st, mb_substr($it['title'], 0, 250),
                mb_substr($it['message'] . ' (' . t('gebundeld') . ": $title)" . ($errors ? ' [' . t('niet verzonden') . ': ' . implode('; ', $errors) . ']' : ''), 0, 1000),
                $pushover ? 1 : 0, $hook === null ? null : ($hook ? 1 : 0), $email === null ? null : ($email ? 1 : 0), 'notify',
            ]);
            if (in_array($it['event'], ['problem', 'reminder'], true)) {
                self::autoMaintenance($c, ['value' => $it['value']], $st, sprintf('%s – %s', $c['host_name'], $c['name']));
            }
        }
    }

    /**
     * Na N verstuurde meldingen voor dezelfde storing: deze check in onderhoud tot hij weer OK is
     * (of tot iemand het onderhoud beëindigt). De rest van de server blijft gewoon melden.
     */
    private static function autoMaintenance(array $check, array $res, int $status, string $title): void
    {
        $after = (int) setting('auto_maint_after', 3);
        if (setting('auto_maint_enabled', '1') !== '1' || $after < 1 || !$check['problem_since']) {
            return;
        }
        // Alleen meldingen die echt zijn aangekomen (minstens één kanaal gelukt)
        $sent = (int) q("SELECT COUNT(*) FROM alerts WHERE check_id = ? AND kind = 'notify' AND status <> 0 AND created_at >= ?
                         AND (sent_ok = 1 OR webhook_ok = 1 OR email_ok = 1)", [$check['id'], $check['problem_since']])->fetchColumn();
        if ($sent < $after) {
            return;
        }
        $reason = t('Automatisch na {n} meldingen', ['n' => $sent]);
        q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 1 WHERE id = ?', [self::AUTO_UNTIL, $reason, $check['id']]);
        self::notify($check, $res, $status, '🔧 ' . t('ONDERHOUD') . ": $title",
            t('Na {n} meldingen staat deze check automatisch in onderhoud: geen meldingen meer tot hij weer OK is. De andere checks van deze server melden gewoon door. Beëindigen kan in RzMon (🔧).', ['n' => $sent]),
            -1, 'maintenance', $status, 'maint');
    }

    /** "Tot het opgelost is": een datum ver weg; het onderhoud stopt bij herstel of handmatig. */
    public const AUTO_UNTIL = '2099-12-31 23:59:59';

    /** Verstuurt een melding via alle ingeschakelde kanalen en logt hem. */
    /** @param array|null $channels kanalen voor deze melding (null = alle ingeschakelde, zoals bij onderhoudsberichten) */
    private static function notify(array $check, array $res, int $status, string $title, string $message, int $priority, string $event, int $previous, string $kind = 'notify', ?array $channels = null): void
    {
        $errors = [];

        $ackUrl = $status !== STATUS_OK ? self::ackUrl($check) : null;

        $pushover = null;
        if (setting('pushover_enabled', '0') === '1' && ($channels === null || $channels['pushover'])) {
            $r = Pushover::send($title, $message . "\n" . date('d-m-Y H:i:s'), $priority, null, null,
                $ackUrl ? ['url' => $ackUrl, 'url_title' => '✔ Bevestigen'] : []);
            $pushover = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'Pushover: ' . $r['error'];
            }
            if (!empty($r['receipt'])) {
                // Emergency: later nagaan of hij in de Pushover-app bevestigd is
                q('UPDATE checks SET pushover_receipt = ? WHERE id = ?', [$r['receipt'], $check['id']]);
            }
        }

        // E-mail: altijd, of alleen als reserve wanneer Pushover uit staat of mislukt
        $email = null;
        if (setting('email_enabled', '0') === '1' && ($channels === null || $channels['email']) && (setting('email_mode', 'always') !== 'fallback' || $pushover !== true)) {
            $base = rtrim((string) config('base_url', ''), '/');
            $body = $message . "\n\n" . t('Tijd') . ': ' . date('d-m-Y H:i:s')
                . ($ackUrl ? "\n\n✔ " . t('Bevestigen (geen meldingen meer voor deze storing):') . "\n$ackUrl" : '')
                . ($base !== '' ? "\n\nRzMon: $base/" : '');
            $r = Mailer::send($title, $body);
            $email = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'E-mail: ' . $r['error'];
            }
        }

        $webhook = null;
        if ($channels === null ? self::webhookWanted($status, $event) : setting('ha_webhook_enabled', '0') === '1' && $channels['webhook']) {
            $r = Webhook::send(self::webhookPayload($check, $res, $status, $title, $message, $event, $previous));
            $webhook = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'Webhook: ' . $r['error'];
            }
        }

        if ($pushover === null && $webhook === null && $email === null) {
            $errors[] = t('geen meldkanaal ingeschakeld');
        }

        q('INSERT INTO alerts (check_id, status, title, message, sent_ok, webhook_ok, email_ok, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $check['id'], $status, mb_substr($title, 0, 250),
            mb_substr($message . ($errors ? ' [' . t('niet verzonden') . ': ' . implode('; ', $errors) . ']' : ''), 0, 1000),
            $pushover ? 1 : 0, $webhook === null ? null : ($webhook ? 1 : 0), $email === null ? null : ($email ? 1 : 0), $kind,
        ]);
    }

    /**
     * Melding die niet bij een check hoort (bv. een mislukte back-up), via dezelfde meldkanalen.
     * @param string $event problem | recovery
     */
    public static function notifySystem(string $title, string $message, int $status, string $event): void
    {
        I18n::forNotify(fn() => self::notifySystemInLang($title, $message, $status, $event));
    }

    private static function notifySystemInLang(string $title, string $message, int $status, string $event): void
    {
        $errors = [];
        $sent = false;
        if (setting('pushover_enabled', '0') === '1') {
            $prio = $status === STATUS_OK ? 0 : min(1, (int) setting('pushover_priority_crit', 1));   // geen emergency: niets te bevestigen
            $r = Pushover::send($title, $message . "\n" . date('d-m-Y H:i:s'), $prio);
            $sent = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'Pushover: ' . $r['error'];
            }
        }
        $email = null;
        if (setting('email_enabled', '0') === '1' && (setting('email_mode', 'always') !== 'fallback' || !$sent)) {
            $base = rtrim((string) config('base_url', ''), '/');
            $r = Mailer::send($title, $message . "\n\nTijd: " . date('d-m-Y H:i:s') . ($base !== '' ? "\n\nRzMon: $base/" : ''));
            $email = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'E-mail: ' . $r['error'];
            }
        }
        $webhook = null;
        if (self::webhookWanted($status, $event)) {
            $r = Webhook::send(['source' => 'rzmon', 'event' => $event, 'status' => $status, 'status_text' => STATUS_LABELS[$status],
                'host' => null, 'check' => null, 'title' => $title, 'message' => $message, 'time' => date('c'), 'url' => config('base_url', '') ?: null]);
            $webhook = $r['ok'];
            if (!$r['ok']) {
                $errors[] = 'Webhook: ' . $r['error'];
            }
        }
        q('INSERT INTO alerts (check_id, status, title, message, sent_ok, webhook_ok, email_ok, kind) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)', [
            $status, mb_substr($title, 0, 250),
            mb_substr($message . ($errors ? ' [' . t('niet verzonden') . ': ' . implode('; ', $errors) . ']' : ''), 0, 1000),
            $sent ? 1 : 0, $webhook === null ? null : ($webhook ? 1 : 0), $email === null ? null : ($email ? 1 : 0), 'system',
        ]);
    }

    private static function webhookWanted(int $status, string $event): bool
    {
        if (setting('ha_webhook_enabled', '0') !== '1') {
            return false;
        }
        // "crit": alleen CRITICAL-problemen en herstel na een melding
        return setting('ha_webhook_events', 'all') !== 'crit' || $status === STATUS_CRIT || $event === 'recovery';
    }

    // ------------------------------------------------------------------ info

    /**
     * Info-melding van een check (herstart, vernieuwd certificaat): volgt de info-regel van de check (✎ → 🔔 Meldingen),
     * anders de algemene. Gaat direct (niet gebundeld) met lage prioriteit; tijdens stille uren, onderhoud of als info
     * uit staat niet verstuurd, maar wel op de pagina Meldingen.
     */
    private static function infoCheck(array $check, string $message): void
    {
        $cfg = NotifyRules::forCheck($check);
        $hold = self::maintenanceFor($check) !== null ? t('onderhoud')
            : (NotifyRules::quietUntil(NotifyRules::quietFor($check)) !== null ? t('stille uren') : null);
        self::sendInfo($check, (int) $check['hid'], "ℹ️ INFO: {$check['host_name']} – {$check['name']}", $message,
            $cfg['rules']['info']['mode'] === 'now', $cfg['channels']['info'], $hold);
    }

    /** Info bij een server (agent bijgewerkt of mislukt, kwetsbaarheden): de rij van die melding bij ℹ️ Systeeminfo */
    public static function infoHost(array $host, callable $message, string $event = 'agent_update'): void
    {
        // Agent-updates per server uit te zetten (Agent Manager): dan alleen nog op de meldingenpagina
        $pageOnly = in_array($event, ['agent_update', 'agent_update_fail'], true) && (int) ($host['info_agent'] ?? 1) !== 1;
        self::sysInfo($event, (int) $host['id'], (string) $host['name'], 'ℹ️ INFO: ' . $host['name'], $message, null, null, $pageOnly);
    }

    /** Info van RzMon zelf (bijgewerkt) */
    public static function infoSystem(callable $message, string $event = 'rzmon_update'): void
    {
        self::sysInfo($event, null, null, 'ℹ️ INFO: RzMon', $message);
    }

    /**
     * Systeeminfo (geen check) versturen volgens zijn eigen rij bij Instellingen → 🔔 Meldingen → ℹ️ Systeeminfo:
     * Pushover (stil of normaal), e-mail, Home Assistant en de meldingenpagina. Tijdens stille uren gaat er niets naar
     * buiten, maar komt de melding wel op de pagina (als die aangevinkt is). $cfg/$test: voor de testknop.
     */
    public static function sysInfo(string $event, ?int $hostId, ?string $hostName, string $title, callable $message, ?string $ip = null, ?array $detail = null,
        bool $pageOnly = false, ?array $cfg = null, bool $test = false): void
    {
        try {
            I18n::forNotify(function () use ($event, $hostId, $hostName, $title, $message, $ip, $detail, $pageOnly, $cfg, $test) {
                $c = $cfg ?? (NotifyRules::sysinfo()[$event] ?? null);
                if (!$c) {
                    return;
                }
                if ($pageOnly) {
                    $c['pushover'] = $c['email'] = $c['webhook'] = false;
                }
                $hold = !$test && ($c['pushover'] || $c['email'] || $c['webhook']) && NotifyRules::quietUntil(NotifyRules::quietGlobal()) !== null ? t('stille uren') : null;
                self::sendInfo(null, $hostId, $title, $message(), true, $c, $hold, $hostName, false, $ip, $detail, (int) ($c['prio'] ?? -1));
            });
        } catch (Throwable) {
            // Een melding mag een login of rapport nooit laten mislukken
        }
    }

    /** @param string|null $hold reden om niet te versturen (stille uren, onderhoud) */
    private static function sendInfo(?array $check, ?int $hostId, string $title, string $message, bool $on, array $channels, ?string $hold, ?string $hostName = null,
        bool $always = false, ?string $ip = null, ?array $detail = null, int $prio = -1): void
    {
        $errors = [];
        [$pushover, $email, $webhook] = [null, null, null];
        if (!$on) {
            if (!$always) {
                $errors[] = t('info staat uit');
            }
        } elseif ($hold !== null) {
            $errors[] = $hold;
        } else {
            if (setting('pushover_enabled', '0') === '1' && $channels['pushover']) {
                $r = Pushover::send($title, $message . "\n" . date('d-m-Y H:i:s'), $prio);   // -1 = stil (geen geluid of trilling), 0 = normaal
                $pushover = $r['ok'];
                if (!$r['ok']) {
                    $errors[] = 'Pushover: ' . $r['error'];
                }
            }
            if (setting('email_enabled', '0') === '1' && $channels['email'] && (setting('email_mode', 'always') !== 'fallback' || $pushover !== true)) {
                $base = rtrim((string) config('base_url', ''), '/');
                $r = Mailer::send($title, $message . "\n\n" . t('Tijd') . ': ' . date('d-m-Y H:i:s') . ($base !== '' ? "\n\nRzMon: $base/" : ''));
                $email = $r['ok'];
                if (!$r['ok']) {
                    $errors[] = 'E-mail: ' . $r['error'];
                }
            }
            if (setting('ha_webhook_enabled', '0') === '1' && $channels['webhook']) {
                $payload = $check ? self::webhookPayload($check, ['value' => $check['last_value'] ?? null], STATUS_OK, $title, $message, 'info', (int) $check['status'])
                    : ['source' => 'rzmon', 'event' => 'info', 'status' => STATUS_OK, 'status_text' => STATUS_LABELS[STATUS_OK], 'host' => $hostName, 'host_id' => $hostId,
                        'check' => null, 'title' => $title, 'message' => $message, 'time' => date('c'), 'url' => config('base_url', '') ?: null];
                $r = Webhook::send($payload);
                $webhook = $r['ok'];
                if (!$r['ok']) {
                    $errors[] = 'Webhook: ' . $r['error'];
                }
            }
        }
        if (!$always && ($channels[NotifyRules::PAGE] ?? true) === false) {
            return;   // niet op de pagina Meldingen (kanaal uit)
        }
        q('INSERT INTO alerts (check_id, host_id, status, title, message, sent_ok, webhook_ok, email_ok, kind, ip, detail) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $check['id'] ?? null, $hostId, STATUS_OK, mb_substr($title, 0, 250),
            mb_substr($message . ($errors ? ' [' . t('niet verzonden') . ': ' . implode('; ', $errors) . ']' : ''), 0, 1000),
            $pushover ? 1 : 0, $webhook === null ? null : ($webhook ? 1 : 0), $email === null ? null : ($email ? 1 : 0), 'info',
            $ip !== null ? mb_substr($ip, 0, 45) : null, $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public static function webhookPayload(array $check, array $res, int $status, string $title, string $message, string $event, int $previous): array
    {
        return [
            'source' => 'rzmon',
            'event' => $event,                       // problem | reminder | recovery | info | test
            'status' => $status,
            'status_text' => STATUS_LABELS[$status],
            'previous_status' => $previous,
            'previous_status_text' => STATUS_LABELS[$previous] ?? null,
            'host' => $check['host_name'],
            'host_id' => (int) $check['hid'],
            'check' => $check['name'],
            'check_id' => (int) $check['id'],
            'type' => $check['type'],
            'target' => $check['target'] !== '' ? $check['target'] : null,
            'value' => is_float($res['value']) ? round($res['value'], 2) : $res['value'],
            'unit' => check_unit($check),
            'title' => $title,
            'message' => $message,
            'time' => date('c'),
            'url' => config('base_url', '') ?: null,
            'ack_url' => $status !== STATUS_OK && $event !== 'test' ? self::ackUrl($check) : null,
        ];
    }

    // ------------------------------------------------------------------ bevestigen

    /** Geheime sleutel voor bevestig-links (wordt eenmalig aangemaakt). */
    private static function ackSecret(): string
    {
        $secret = (string) setting('ack_secret', '');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            save_setting('ack_secret', $secret);
        }
        return $secret;
    }

    /**
     * Token voor de bevestig-link: geldt alleen voor deze check en deze storing
     * (problem_since). Na herstel of een nieuwe storing werkt een oude link niet meer.
     */
    public static function ackToken(int $checkId, ?string $problemSince): ?string
    {
        if (!$problemSince) {
            return null;
        }
        return substr(hash_hmac('sha256', "ack|$checkId|$problemSince", self::ackSecret()), 0, 32);
    }

    public static function ackUrl(array $check): ?string
    {
        $base = rtrim((string) config('base_url', ''), '/');
        $token = self::ackToken((int) $check['id'], $check['problem_since'] ?? null);
        return $base !== '' && $token ? "$base/ack.php?c={$check['id']}&t=$token" : null;
    }

    /** Bevestigt een storing. Geeft false als de check (inmiddels) OK is. */
    public static function acknowledge(int $checkId, string $by, string $comment = ''): bool
    {
        return I18n::forNotify(fn() => self::acknowledgeInLang($checkId, $by, $comment));
    }

    private static function acknowledgeInLang(int $checkId, string $by, string $comment = ''): bool
    {
        $c = q('SELECT c.id, c.name, c.status, c.acked_at, c.pushover_receipt, h.name AS host_name
                FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$checkId])->fetch();
        if (!$c || (int) $c['status'] === STATUS_OK) {
            return false;
        }
        if ($c['acked_at'] === null) {
            $comment = mb_substr(trim($comment), 0, 200);
            q('UPDATE checks SET acked_at = ?, acked_by = ?, ack_comment = ? WHERE id = ?', [now(), mb_substr($by, 0, 64), $comment ?: null, $checkId]);
            q("INSERT INTO alerts (check_id, status, title, message, sent_ok, kind) VALUES (?, ?, ?, ?, 0, 'ack')", [
                $checkId, (int) $c['status'], mb_substr('✔ ' . t('BEVESTIGD') . ": {$c['host_name']} – {$c['name']}", 0, 250),
                mb_substr(t('Bevestigd door {who}', ['who' => $by]) . ($comment ? ": $comment" : '') . '. ' . t('Geen meldingen meer tot herstel.'), 0, 1000),
            ]);
        }
        if ($c['pushover_receipt']) {
            Pushover::cancel($c['pushover_receipt']);
            q('UPDATE checks SET pushover_receipt = NULL WHERE id = ?', [$checkId]);
        }
        return true;
    }

    public static function unacknowledge(int $checkId): void
    {
        q('UPDATE checks SET acked_at = NULL, acked_by = NULL, ack_comment = NULL WHERE id = ?', [$checkId]);
    }

    /** Emergency-meldingen die in de Pushover-app bevestigd zijn, ook in RzMon bevestigen. */
    public static function pollReceipts(): void
    {
        if (setting('pushover_enabled', '0') !== '1') {
            return;
        }
        foreach (q('SELECT id, pushover_receipt FROM checks WHERE pushover_receipt IS NOT NULL LIMIT 20')->fetchAll() as $c) {
            $r = Pushover::receipt($c['pushover_receipt']);
            if (!$r) {
                continue;
            }
            if ((int) ($r['acknowledged'] ?? 0) === 1) {
                $device = (string) ($r['acknowledged_by_device'] ?? '');
                self::acknowledge((int) $c['id'], 'Pushover' . ($device !== '' ? " ($device)" : ''));
            }
            if ((int) ($r['acknowledged'] ?? 0) === 1 || (int) ($r['expired'] ?? 0) === 1) {
                q('UPDATE checks SET pushover_receipt = NULL WHERE id = ?', [$c['id']]);
            }
        }
    }

    /** Meldingen bewaren (dagen): de instelling, of zolang die niet gezet is historie × 3 (zoals vóór 1.32.0). */
    public static function alertDays(): int
    {
        return max(1, min(3650, (int) setting('alerts_days', max(1, (int) setting('retention_days', 30)) * 3)));
    }

    public static function purge(): void
    {
        $days = max(1, (int) setting('retention_days', 30));
        q('DELETE FROM results WHERE created_at < NOW() - INTERVAL ? DAY', [$days]);
        q('DELETE FROM alerts WHERE created_at < NOW() - INTERVAL ? DAY', [self::alertDays()]);
        q('DELETE FROM status_changes WHERE created_at < NOW() - INTERVAL ? DAY', [self::alertDays()]);
        q('DELETE FROM access_log WHERE created_at < NOW() - INTERVAL ? DAY', [self::alertDays()]);
        q('DELETE FROM agent_log WHERE created_at < NOW() - INTERVAL 7 DAY');
        q('DELETE FROM remember_tokens WHERE last_used < NOW() - INTERVAL ? DAY', [REMEMBER_DAYS]);
        q('DELETE FROM user_sessions WHERE last_seen < NOW() - INTERVAL 2 DAY');   // allang verlopen sessies
        // Tot 1.92.2: elke controle zonder cookies (curl, een HTTP-check op RzMon zelf) gaf een LAN-sessie die nooit terugkwam
        q("DELETE FROM user_sessions WHERE how = 'lan' AND last_seen <= created_at + INTERVAL 1 MINUTE AND created_at < NOW() - INTERVAL 10 MINUTE");
        PasswordReset::purge();
        ChangeLog::purge();   // wijzigingslogboek: bewaartermijn instelbaar (1.97.0)
        Security::purge();
        Ids::purge();
        Edr::purge();
    }
}
