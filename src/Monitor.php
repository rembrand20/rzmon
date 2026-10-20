<?php
declare(strict_types=1);

final class Monitor
{
    private const CHECK_SELECT = 'SELECT c.*, h.name AS host_name, h.address, h.mode, h.agent_seen, h.agent_version, h.id AS hid,
                h.parent_id, h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                FROM checks c JOIN hosts h ON h.id = c.host_id';

    /** Voert alle checks uit die aan de beurt zijn (of alles bij $force). */
    public static function runDue(bool $force = false): int
    {
        $sql = self::CHECK_SELECT . ' WHERE c.enabled = 1 AND h.enabled = 1';
        if (!$force) {
            $sql .= ' AND (c.last_checked IS NULL OR c.last_checked <= NOW() - INTERVAL c.interval_sec SECOND + INTERVAL 15 SECOND)';
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
        self::flushQueue();
        return count($rows);
    }

    public static function runCheckById(int $id): ?array
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
        ];
        $res = Checks::run($row, $host);
        if ($row['type'] === 'disk') {
            $res = Stats::applyDiskForecast($row, $res);
        }
        self::process($row, $res);
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
        $rows = q("SELECT type, status FROM checks WHERE host_id = ? AND enabled = 1 AND type IN ('ping','tcp','http')", [$hostId])->fetchAll();
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
        $notified = (int) $check['notified_status'];
        $notifiedAt = $check['notified_at'];
        $message = $res['message'];

        q('INSERT INTO results (check_id, status, value, vals) VALUES (?, ?, ?, ?)',
            [$check['id'], $status, $res['value'], !empty($res['vals']) ? json_encode($res['vals']) : null]);

        // Onderdrukken: een server erboven is onbereikbaar → dat is al gemeld
        $upstream = $status !== STATUS_OK ? self::upstreamDown((int) $check['hid']) : null;
        if ($upstream) {
            $message .= " (onderdrukt: {$upstream['name']} is onbereikbaar)";
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
        q('UPDATE checks SET status = ?, last_value = ?, last_message = ?, last_checked = ?, fail_count = ?, suppressed_by = ?, problem_since = ?, status_since = ? WHERE id = ?', [
            $status, $res['value'], mb_substr($message, 0, 500), now(), $failCount, $upstream['id'] ?? null, $problemSince, $statusSince, $check['id'],
        ]);

        $s = settings();
        $title = sprintf('%s – %s', $check['host_name'], $check['name']);
        $cfg = NotifyRules::forCheck($check);

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

        if ($silenced) {
            // niets versturen
        } elseif ($flapEvent === 'start') {
            if (!$acked) {
                self::enqueue($check, $status, 'flap', $notified, "🔀 FLAPPEND: $title",
                    "$title staat te flappen: {$flapChanges}× van status gewisseld in {$flap['window']} min (nu " . STATUS_LABELS[$status] . '). '
                    . "Per status komt er nog hooguit één melding, tot hij {$flap['stable']} min stabiel is.\n$message", $prioOf($status), $res['value']);
            }
            $notified = $status;
            $notifiedAt = now();
        } elseif ($flapping) {
            // Per status (warning, critical, onbekend, hersteld) hooguit één melding zolang hij flapt
            $r = $cfg['rules'][NotifyRules::KEYS[$status]];
            $on = $r['mode'] !== 'never' && ($status !== STATUS_UNKNOWN || ($s['notify_unknown'] ?? '1') === '1');
            if ($status !== $notified && !in_array($status, $flapSent, true)) {
                if ($on && !$acked) {
                    self::enqueue($check, $status, $status === STATUS_OK ? 'recovery' : 'problem', $notified,
                        $status === STATUS_OK ? "✅ HERSTELD: $title" : sprintf('%s %s: %s', $iconOf[$status], STATUS_LABELS[$status], $title),
                        $message . ' (flapt: verdere wisselingen worden niet gemeld)', $prioOf($status), $res['value']);
                    $notified = $status;
                    $notifiedAt = now();
                }
                $flapSent[] = $status;
            }
        } elseif ($flapEvent === 'stable') {
            if (!$acked || $status === STATUS_OK) {
                self::enqueue($check, $status, 'stable', $notified, "{$iconOf[$status]} STABIEL: $title",
                    "$title flapt niet meer: al {$flap['stable']} min " . STATUS_LABELS[$status] . ".\n$message", $prioOf($status), $res['value']);
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
                    self::enqueue($check, STATUS_OK, 'recovery', $notified, "✅ HERSTELD: $title",
                        $message . ($autoEnded ? "\nAutomatisch onderhoud beëindigd." : ''), -1, $res['value']);
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
            $due = $enabled && ($isRepeat
                ? $r['repeat'] > 0 && $notifiedAt !== null && strtotime($notifiedAt) <= time() - $r['repeat'] * 60
                : $r['mode'] === 'now' || $age >= $r['min'] * 60);
            if ($due) {
                $isWarnish = $status !== STATUS_CRIT;
                $icon = [STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'][$status];
                $prio = (int) ($isWarnish ? ($s['pushover_priority_warn'] ?? 0) : ($s['pushover_priority_crit'] ?? 1));
                $extra = '';
                if ($status === STATUS_CRIT && in_array($check['type'], ['ping', 'tcp', 'http'], true)) {
                    $children = (int) q('SELECT COUNT(*) FROM hosts WHERE parent_id = ? AND enabled = 1', [$check['hid']])->fetchColumn();
                    if ($children > 0 && self::hostUnreachable((int) $check['hid'])) {
                        $extra = "\nMeldingen van $children afhankelijke server(s) worden onderdrukt.";
                    }
                }
                self::enqueue($check, $status, $isRepeat ? 'reminder' : 'problem', $notified,
                    sprintf('%s %s: %s', $icon, STATUS_LABELS[$status], $title), $message . $extra, $prio, $res['value']);
                $notified = $status;
                $notifiedAt = now();
            }
        }

        q('UPDATE checks SET notified_status = ?, notified_at = ?, flapping_since = ?, flap_notified = ?, flap_ended_at = ? WHERE id = ?', [
            $notified, $notifiedAt, $flapping ? ($check['flapping_since'] ?: now()) : null, $flapping ? implode(',', array_unique($flapSent)) : null,
            $flapEvent === 'stable' ? date('Y-m-d H:i:s', time() - $age) : $check['flap_ended_at'], $check['id'],
        ]);
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
        $win = max(0, (int) setting('notify_group_seconds', 120));
        foreach (q('SELECT host_id, UNIX_TIMESTAMP(MIN(created_at)) AS first FROM notify_queue GROUP BY host_id')->fetchAll() as $h) {
            if ($win > 0 && (int) $h['first'] > time() - $win) {
                continue;   // nog even wachten op meer meldingen van deze server
            }
            $items = [];
            foreach (q('SELECT * FROM notify_queue WHERE host_id = ? ORDER BY id', [$h['host_id']])->fetchAll() as $it) {
                // Eerst uit de wachtrij halen: zo verstuurt een tweede proces (cron + "Nu checken") hem niet nog eens
                if (q('DELETE FROM notify_queue WHERE id = ?', [$it['id']])->rowCount() !== 1) {
                    continue;
                }
                $c = q(self::CHECK_SELECT . ' WHERE c.id = ?', [$it['check_id']])->fetch();
                if ($c) {
                    $items[] = ['item' => $it, 'check' => $c];
                }
            }
            if ($win === 0 || count($items) === 1) {
                foreach ($items as $x) {
                    self::sendItem($x['check'], $x['item']);
                }
            } elseif ($items) {
                self::sendGroup($items);
            }
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

    /** Meerdere meldingen van één server in één bericht, met de ergste status in de titel. */
    private static function sendGroup(array $items): void
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
                'recovery' => 'hersteld',
                'reminder' => 'nog steeds ' . STATUS_LABELS[$st],
                'flap' => '🔀 flapt, nu ' . STATUS_LABELS[$st],
                'stable' => 'weer stabiel, ' . STATUS_LABELS[$st],
                default => STATUS_LABELS[$st],
            };
            $lines[] = $icon[$st] . " {$c['name']} ($what): " . strtok($it['message'], "\n");
            if ($st !== STATUS_OK && ($u = self::ackUrl($c))) {
                $acks[] = "✔ {$c['name']}: $u";
            }
        }
        $n = count($items);
        $title = array_keys($events) === ['recovery'] ? "✅ HERSTELD: $host – $n checks"
            : (array_keys($events) === ['reminder'] ? "🔁 $host: nog steeds $n problemen" : "{$icon[$worst]} " . STATUS_LABELS[$worst] . ": $host – $n meldingen");
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
            $r = Mailer::send($title, $body . "\n\nTijd: " . date('d-m-Y H:i:s')
                . ($acks ? "\n\nBevestigen (geen meldingen meer voor die storing):\n" . implode("\n", $acks) : '')
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
                mb_substr($it['message'] . " (gebundeld: $title)" . ($errors ? ' [niet verzonden: ' . implode('; ', $errors) . ']' : ''), 0, 1000),
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
        $reason = "Automatisch na $sent meldingen";
        q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 1 WHERE id = ?', [self::AUTO_UNTIL, $reason, $check['id']]);
        self::notify($check, $res, $status, "🔧 ONDERHOUD: $title",
            "Na $sent meldingen staat deze check automatisch in onderhoud: geen meldingen meer tot hij weer OK is. "
            . 'De andere checks van deze server melden gewoon door. Beëindigen kan in RzMon (🔧).', -1, 'maintenance', $status, 'maint');
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
            $body = $message . "\n\nTijd: " . date('d-m-Y H:i:s')
                . ($ackUrl ? "\n\n✔ Bevestigen (geen meldingen meer voor deze storing):\n$ackUrl" : '')
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
            $errors[] = 'geen meldkanaal ingeschakeld';
        }

        q('INSERT INTO alerts (check_id, status, title, message, sent_ok, webhook_ok, email_ok, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $check['id'], $status, mb_substr($title, 0, 250),
            mb_substr($message . ($errors ? ' [niet verzonden: ' . implode('; ', $errors) . ']' : ''), 0, 1000),
            $pushover ? 1 : 0, $webhook === null ? null : ($webhook ? 1 : 0), $email === null ? null : ($email ? 1 : 0), $kind,
        ]);
    }

    /**
     * Melding die niet bij een check hoort (bv. een mislukte back-up), via dezelfde meldkanalen.
     * @param string $event problem | recovery
     */
    public static function notifySystem(string $title, string $message, int $status, string $event): void
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
            mb_substr($message . ($errors ? ' [niet verzonden: ' . implode('; ', $errors) . ']' : ''), 0, 1000),
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

    public static function webhookPayload(array $check, array $res, int $status, string $title, string $message, string $event, int $previous): array
    {
        return [
            'source' => 'rzmon',
            'event' => $event,                       // problem | reminder | recovery | test
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
        $c = q('SELECT c.id, c.name, c.status, c.acked_at, c.pushover_receipt, h.name AS host_name
                FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$checkId])->fetch();
        if (!$c || (int) $c['status'] === STATUS_OK) {
            return false;
        }
        if ($c['acked_at'] === null) {
            $comment = mb_substr(trim($comment), 0, 200);
            q('UPDATE checks SET acked_at = ?, acked_by = ?, ack_comment = ? WHERE id = ?', [now(), mb_substr($by, 0, 64), $comment ?: null, $checkId]);
            q("INSERT INTO alerts (check_id, status, title, message, sent_ok, kind) VALUES (?, ?, ?, ?, 0, 'ack')", [
                $checkId, (int) $c['status'], mb_substr("✔ BEVESTIGD: {$c['host_name']} – {$c['name']}", 0, 250),
                mb_substr("Bevestigd door $by" . ($comment ? ": $comment" : '') . '. Geen meldingen meer tot herstel.', 0, 1000),
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

    public static function purge(): void
    {
        $days = max(1, (int) setting('retention_days', 30));
        q('DELETE FROM results WHERE created_at < NOW() - INTERVAL ? DAY', [$days]);
        q('DELETE FROM alerts WHERE created_at < NOW() - INTERVAL ? DAY', [$days * 3]);
        q('DELETE FROM agent_log WHERE created_at < NOW() - INTERVAL 7 DAY');
        q('DELETE FROM remember_tokens WHERE last_used < NOW() - INTERVAL ? DAY', [REMEMBER_DAYS]);
        PasswordReset::purge();
    }
}
