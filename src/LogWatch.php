<?php

declare(strict_types=1);

/**
 * 1.212.0: vaste zoekacties op de logs die een melding geven.
 *
 * - "Te veel treffers": bij binnenkomst van een logpakket telt RzMon de nieuwe regels die op een zoekactie passen
 *   (per minuut, in log_watch_counts) en meldt als er in het venster minstens de drempel is.
 * - "Geen treffers": elke minuut (cron) kijkt RzMon of er in het venster nog een treffer was.
 * - Per server apart (host_id) of alle servers samen (host_id 0); de stand per zoekactie in log_watch_state.
 * - Herstel: een melding gaat dicht als een heel venster lang de drempel niet gehaald werd (of er weer een treffer is).
 * - Rustperiode: tussen twee meldingen van dezelfde zoekactie (en server) minstens zoveel minuten.
 */
final class LogWatch
{
    public const MAX = 50;
    public const WINDOWS = [1, 5, 15, 60, 1440];              // te veel treffers: minuten
    public const WINDOWS_NONE = [60, 360, 1560, 10080];       // geen treffers: 1 uur, 6 uur, 26 uur, 7 dagen
    public const RESTS = [15, 30, 60, 240, 1440];
    private const MAX_AGE = 3600;                             // oudere regels (laat binnen) tellen niet voor een melding
    private const SLOW_MS = 50;
    private const SLOW_TIMES = 3;

    /** @var array<int, array>|null */
    private static ?array $cache = null;

    public static function reset(): void
    {
        self::$cache = null;
    }

    /** Alle zoekacties, met de stand per server */
    public static function all(): array
    {
        $out = [];
        foreach (q('SELECT * FROM log_watches ORDER BY name, id')->fetchAll() as $w) {
            $out[(int) $w['id']] = self::row($w);
        }
        foreach (q('SELECT s.*, h.name AS host FROM log_watch_state s LEFT JOIN hosts h ON h.id = s.host_id')->fetchAll() as $s) {
            if (isset($out[(int) $s['watch_id']])) {
                $out[(int) $s['watch_id']]['state'][] = [
                    'host_id' => (int) $s['host_id'], 'host' => (int) $s['host_id'] === 0 ? null : $s['host'], 'open' => (bool) $s['open'],
                    'opened_at' => $s['opened_at'], 'last_sent' => $s['last_sent'], 'last_hit' => $s['last_hit'], 'peak' => (int) $s['peak'],
                ];
            }
        }
        // Treffers per uur, laatste 24 uur (voor het grafiekje)
        $now = time();
        $from = intdiv($now, 3600) * 3600 - 23 * 3600;
        foreach (q('SELECT watch_id, FLOOR(minute / 60) AS hr, SUM(n) AS n FROM log_watch_counts WHERE minute >= ? GROUP BY watch_id, hr', [intdiv($from, 60)]) as $r) {
            $i = (int) $r['hr'] - intdiv($from, 3600);
            if (isset($out[(int) $r['watch_id']]) && $i >= 0 && $i < 24) {
                $out[(int) $r['watch_id']]['hours'][$i] = (int) $r['n'];
            }
        }
        $last = q("SELECT log_watch, MAX(created_at) AS at FROM alerts WHERE log_watch IS NOT NULL AND (status <> 0 OR kind = 'info') GROUP BY log_watch")->fetchAll();
        foreach ($last as $r) {
            $id = (int) explode(':', (string) $r['log_watch'])[0];
            if (isset($out[$id]) && ($out[$id]['last_alert'] === null || $r['at'] > $out[$id]['last_alert'])) {
                $out[$id]['last_alert'] = $r['at'];
            }
        }
        return array_values($out);
    }

    private static function row(array $w): array
    {
        return [
            'id' => (int) $w['id'], 'name' => $w['name'], 'q' => $w['q'], 'mode' => $w['mode'], 'threshold' => (int) $w['threshold'], 'win' => (int) $w['win'],
            'per_host' => (bool) $w['per_host'], 'severity' => (int) $w['severity'], 'rest' => (int) $w['rest'], 'recover' => (bool) $w['recover'],
            'enabled' => (bool) $w['enabled'], 'created_by' => $w['created_by'], 'created_at' => $w['created_at'], 'off_reason' => $w['off_reason'],
            'state' => [], 'hours' => array_fill(0, 24, 0), 'last_alert' => null,
        ];
    }

    /**
     * Invoer schoonmaken.
     * @return array|string de rij, of een foutmelding
     */
    public static function clean(array $in): array|string
    {
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 100));
        $qs = trim(mb_substr((string) ($in['q'] ?? ''), 0, 500));
        if ($name === '') {
            return t('Geef de zoekactie een naam');
        }
        if ($qs === '') {
            return t('Vul een zoekopdracht in');
        }
        $p = LogStore::parseQuery($qs);
        if ($p['error']) {
            return $p['error'];
        }
        $mode = ($in['mode'] ?? 'many') === 'none' ? 'none' : 'many';
        if ($mode === 'many' && !$p['words'] && !$p['re'] && $p['level'] === null && !$p['not']) {
            return t('Zoek op iets: met alleen server: of bron: telt elke regel mee');
        }
        $win = (int) ($in['win'] ?? 0);
        $wins = $mode === 'none' ? self::WINDOWS_NONE : self::WINDOWS;
        if (!in_array($win, $wins, true)) {
            $win = $mode === 'none' ? 1560 : 5;
        }
        $rest = (int) ($in['rest'] ?? 30);
        return [
            'name' => $name, 'q' => $qs, 'mode' => $mode, 'threshold' => $mode === 'none' ? 1 : max(1, min(100000, (int) ($in['threshold'] ?? 10))), 'win' => $win,
            'per_host' => !isset($in['per_host']) || !empty($in['per_host']) ? 1 : 0,
            'severity' => in_array((int) ($in['severity'] ?? STATUS_WARN), [STATUS_OK, STATUS_WARN, STATUS_CRIT], true) ? (int) ($in['severity'] ?? STATUS_WARN) : STATUS_WARN,
            'rest' => in_array($rest, self::RESTS, true) ? $rest : 30,
            'recover' => !isset($in['recover']) || !empty($in['recover']) ? 1 : 0,
        ];
    }

    /** Bewaren (nieuw of bestaand); geeft het id of een foutmelding */
    public static function save(?int $id, array $in, string $by): int|string
    {
        $r = self::clean($in);
        if (is_string($r)) {
            return $r;
        }
        if ($id) {
            $old = q('SELECT * FROM log_watches WHERE id = ?', [$id])->fetch();
            if (!$old) {
                return t('Deze zoekactie bestaat niet meer');
            }
            q('UPDATE log_watches SET name = ?, q = ?, mode = ?, threshold = ?, win = ?, per_host = ?, severity = ?, rest = ?, recover = ?, slow = 0, off_reason = NULL WHERE id = ?',
                [$r['name'], $r['q'], $r['mode'], $r['threshold'], $r['win'], $r['per_host'], $r['severity'], $r['rest'], $r['recover'], $id]);
            if ($old['q'] !== $r['q'] || $old['mode'] !== $r['mode'] || (int) $old['per_host'] !== $r['per_host']) {
                // Andere zoekopdracht: opnieuw beginnen met tellen (een open melding blijft open tot het rustig is)
                q('DELETE FROM log_watch_counts WHERE watch_id = ?', [$id]);
                q('UPDATE log_watch_state SET last_hit = NULL, since = NOW() WHERE watch_id = ?', [$id]);
            }
        } else {
            if ((int) q('SELECT COUNT(*) FROM log_watches')->fetchColumn() >= self::MAX) {
                return t('Hooguit {n} vaste zoekacties', ['n' => self::MAX]);
            }
            q('INSERT INTO log_watches (name, q, mode, threshold, win, per_host, severity, rest, recover, enabled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [$r['name'], $r['q'], $r['mode'], $r['threshold'], $r['win'], $r['per_host'], $r['severity'], $r['rest'], $r['recover'], mb_substr($by, 0, 100)]);
            $id = (int) db()->lastInsertId();
        }
        self::reset();
        return $id;
    }

    public static function setEnabled(int $id, bool $on): void
    {
        q('UPDATE log_watches SET enabled = ?, slow = 0, off_reason = NULL WHERE id = ?', [$on ? 1 : 0, $id]);
        if (!$on) {
            q('UPDATE log_watch_state SET open = 0 WHERE watch_id = ?', [$id]);   // uit: geen open melding meer (zonder herstelmelding)
        } else {
            q('UPDATE log_watch_state SET since = NOW() WHERE watch_id = ?', [$id]);
        }
        self::reset();
    }

    public static function delete(int $id): void
    {
        q('DELETE FROM log_watch_counts WHERE watch_id = ?', [$id]);
        q('DELETE FROM log_watch_state WHERE watch_id = ?', [$id]);
        q('DELETE FROM log_watches WHERE id = ?', [$id]);
        self::reset();
    }

    /** Ingeschakelde zoekacties met hun ontlede zoekopdracht en bereik (gebufferd per run) */
    private static function active(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        foreach (q('SELECT * FROM log_watches WHERE enabled = 1')->fetchAll() as $w) {
            $p = LogStore::parseQuery((string) $w['q']);
            if ($p['error']) {
                continue;
            }
            [$hosts, $sources] = LogStore::scope($p, [], []);
            self::$cache[(int) $w['id']] = ['w' => $w, 'p' => $p, 'hosts' => $hosts, 'sources' => $sources];
        }
        return self::$cache;
    }

    /**
     * Na het opslaan van een logpakket: de nieuwe regels tellen.
     * @param array<string, array<int, list<array{0: int, 1: int, 2: string}>>> $byDay dag → bron → [tijd, niveau, tekst]
     */
    public static function onIngest(array $host, array $byDay): void
    {
        $list = self::active();
        if (!$list || !$byDay) {
            return;
        }
        $hostId = (int) $host['id'];
        $now = time();
        foreach ($list as $id => $a) {
            if ($a['hosts'] && !in_array($hostId, $a['hosts'], true)) {
                continue;
            }
            $t0 = microtime(true);
            $perMin = [];
            $sample = [];
            $last = 0;
            foreach ($byDay as $bySrc) {
                foreach ($bySrc as $sid => $rows) {
                    if ($a['sources'] && !in_array((int) $sid, $a['sources'], true)) {
                        continue;
                    }
                    foreach ($rows as [$ts, $lvl, $msg]) {
                        if (!LogStore::levelOk($a['p']['level'], $lvl) || !LogStore::lineMatch($msg, $a['p'])) {
                            continue;
                        }
                        $last = max($last, min($ts, $now));
                        if ($ts < $now - self::MAX_AGE || $ts > $now + 300) {
                            continue;   // laat binnengekomen (agent was offline): niet alsnog melden
                        }
                        $m = intdiv(min($ts, $now), 60);
                        $perMin[$m] = ($perMin[$m] ?? 0) + 1;
                        $sample[] = [$ts, (int) $sid, $msg];
                    }
                }
            }
            $ms = (microtime(true) - $t0) * 1000;
            if ($ms > self::SLOW_MS) {
                self::slow((int) $id, $a['w'], (int) round($ms));
            } elseif ((int) $a['w']['slow'] > 0) {
                q('UPDATE log_watches SET slow = 0 WHERE id = ?', [$id]);
                self::$cache[$id]['w']['slow'] = 0;
            }
            if ($last === 0) {
                continue;
            }
            $key = (int) $a['w']['per_host'] ? $hostId : 0;
            foreach ($perMin as $m => $n) {
                q('INSERT INTO log_watch_counts (watch_id, host_id, minute, n) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)', [$id, $hostId, $m, $n]);
            }
            // Laatste treffer: in PHP vergelijken (MariaDB 11 weigert FROM_UNIXTIME(0) in een tijdzone voor UTC)
            $st = self::state((int) $id, $key);
            if ($st['last_hit'] === null || strtotime((string) $st['last_hit']) < $last) {
                q('UPDATE log_watch_state SET last_hit = ? WHERE watch_id = ? AND host_id = ?', [date('Y-m-d H:i:s', $last), $id, $key]);
            }
            if ($a['w']['mode'] === 'none') {
                self::recoverNone($a['w'], $key);
                continue;
            }
            if ($perMin) {
                usort($sample, static fn($x, $y) => $y[0] <=> $x[0]);
                self::evaluate($a['w'], $key, array_slice($sample, 0, 200));
            }
        }
    }

    /** Te traag (een zware regex): na een paar keer uitzetten, met een melding */
    private static function slow(int $id, array $w, int $ms): void
    {
        $n = (int) $w['slow'] + 1;
        self::$cache[$id]['w']['slow'] = $n;
        if ($n < self::SLOW_TIMES) {
            q('UPDATE log_watches SET slow = ? WHERE id = ?', [$n, $id]);
            return;
        }
        q("UPDATE log_watches SET enabled = 0, slow = 0, off_reason = 'slow' WHERE id = ?", [$id]);
        unset(self::$cache[$id]);
        Monitor::sysInfo('logs_watch_off', null, null, '📜 ' . t('Logs: zoekactie {name} uitgezet', ['name' => $w['name']]),
            fn() => t('De vaste zoekactie {name} kostte {ms} ms per logpakket (meer dan {max} ms, {n} keer achter elkaar) en is uitgezet. Maak de zoekopdracht eenvoudiger (minder of geen regex) en zet hem weer aan op de pagina Logs › Vaste zoekacties.', [
                'name' => $w['name'], 'ms' => $ms, 'max' => self::SLOW_MS, 'n' => self::SLOW_TIMES]));
    }

    /** Stand van een zoekactie (en server) ophalen; maakt hem aan als hij er nog niet is */
    private static function state(int $id, int $key): array
    {
        $s = q('SELECT * FROM log_watch_state WHERE watch_id = ? AND host_id = ?', [$id, $key])->fetch();
        if (!$s) {
            q('INSERT IGNORE INTO log_watch_state (watch_id, host_id, open, since) VALUES (?, ?, 0, NOW())', [$id, $key]);
            $s = q('SELECT * FROM log_watch_state WHERE watch_id = ? AND host_id = ?', [$id, $key])->fetch();
        }
        return $s ?: ['open' => 0, 'last_sent' => null, 'opened_at' => null, 'peak' => 0, 'last_hit' => null, 'since' => now(), 'alert_id' => null];
    }

    /** Treffers in het venster (per server of alle servers samen) */
    private static function count(array $w, int $key, ?int $now = null): int
    {
        $from = intdiv(($now ?? time()) - (int) $w['win'] * 60, 60) + 1;
        return (int) q('SELECT COALESCE(SUM(n), 0) FROM log_watch_counts WHERE watch_id = ? AND minute >= ?' . ($key ? ' AND host_id = ?' : ''),
            $key ? [$w['id'], $from, $key] : [$w['id'], $from])->fetchColumn();
    }

    /** Te veel treffers: drempel gehaald? Dan een melding (tenzij er al een open is of de rustperiode loopt) */
    private static function evaluate(array $w, int $key, array $sample): void
    {
        $n = self::count($w, $key);
        if ($n < (int) $w['threshold']) {
            return;
        }
        $s = self::state((int) $w['id'], $key);
        if ((int) $s['open']) {
            if ($n > (int) $s['peak']) {
                q('UPDATE log_watch_state SET peak = ? WHERE watch_id = ? AND host_id = ?', [$n, $w['id'], $key]);
            }
            return;
        }
        if ($s['last_sent'] !== null && strtotime((string) $s['last_sent']) > time() - (int) $w['rest'] * 60) {
            return;   // rustperiode
        }
        self::fire($w, $key, $n, $sample);
    }

    /** Geen treffers: een treffer is binnen → een open melding gaat dicht */
    private static function recoverNone(array $w, int $key): void
    {
        $s = self::state((int) $w['id'], $key);
        if ((int) $s['open']) {
            self::close($w, $key, $s, true);
        }
    }

    /** Elke minuut (cron): herstel bij te veel treffers, en de controle op geen treffers */
    public static function cron(): void
    {
        $list = self::active();
        if (!$list) {
            return;
        }
        $now = time();
        foreach ($list as $id => $a) {
            $w = $a['w'];
            if ($w['mode'] === 'many') {
                foreach (q('SELECT * FROM log_watch_state WHERE watch_id = ? AND open = 1', [$id])->fetchAll() as $s) {
                    $key = (int) $s['host_id'];
                    // Een heel venster rustig (en minstens zo lang open)? Dan dicht.
                    $quietFor = strtotime((string) $s['opened_at']) <= $now - (int) $w['win'] * 60 && self::count($w, $key) < (int) $w['threshold'];
                    if ($quietFor && ((int) $w['recover'] || strtotime((string) $s['opened_at']) <= $now - (int) $w['rest'] * 60)) {
                        self::close($w, $key, $s, (bool) (int) $w['recover']);
                    }
                }
                continue;
            }
            // Geen treffers: welke servers horen erbij (met een ingeschakelde bron in het bereik)?
            $keys = [];
            if ((int) $w['per_host']) {
                $sql = 'SELECT DISTINCT s.host_id FROM log_sources s JOIN hosts h ON h.id = s.host_id WHERE s.enabled = 1 AND h.enabled = 1';
                $args = [];
                if ($a['hosts']) {
                    $sql .= ' AND s.host_id IN (' . implode(',', array_fill(0, count($a['hosts']), '?')) . ')';
                    $args = array_merge($args, $a['hosts']);
                }
                if ($a['sources']) {
                    $sql .= ' AND s.id IN (' . implode(',', array_fill(0, count($a['sources']), '?')) . ')';
                    $args = array_merge($args, $a['sources']);
                }
                $keys = array_map('intval', array_column(q($sql, $args)->fetchAll(), 'host_id'));
            } else {
                $keys = [0];
            }
            foreach ($keys as $key) {
                $s = self::state((int) $id, $key);
                if ((int) $s['open']) {
                    continue;
                }
                $since = max(strtotime((string) $w['created_at']), strtotime((string) ($s['since'] ?? $w['created_at'])), $s['last_hit'] ? strtotime((string) $s['last_hit']) : 0);
                if ($since > $now - (int) $w['win'] * 60) {
                    continue;
                }
                if ($s['last_sent'] !== null && strtotime((string) $s['last_sent']) > $now - (int) $w['rest'] * 60) {
                    continue;
                }
                self::fire($w, $key, 0, [], $s['last_hit'] !== null ? (string) $s['last_hit'] : null);
            }
        }
    }

    /** Oude tellingen weg (ouder dan het grootste venster plus wat speling) */
    public static function purge(): void
    {
        q('DELETE FROM log_watch_counts WHERE minute < ?', [intdiv(time() - 2 * 86400, 60)]);
        q('DELETE s FROM log_watch_state s LEFT JOIN log_watches w ON w.id = s.watch_id WHERE w.id IS NULL');
    }

    private static function hostName(int $key): string
    {
        return $key ? (string) (q('SELECT name FROM hosts WHERE id = ?', [$key])->fetchColumn() ?: '?') : t('alle servers');
    }

    private static function winText(int $min): string
    {
        return $min % 1440 === 0 ? t('{n} dag(en)', ['n' => $min / 1440]) : ($min % 60 === 0 ? t('{n} uur', ['n' => $min / 60]) : t('{n} min', ['n' => $min]));
    }

    /** Melding versturen en de stand op open zetten (info: geen open stand, alleen de rustperiode) */
    private static function fire(array $w, int $key, int $n, array $sample, ?string $lastHit = null): void
    {
        I18n::forNotify(function () use ($w, $key, $n, $sample, $lastHit) {
            $host = self::hostName($key);
            $sev = (int) $w['severity'];
            $label = [STATUS_OK => 'ℹ️ INFO', STATUS_WARN => '🟠 WARNING', STATUS_CRIT => '🔴 CRITICAL'][$sev];
            $title = "$label: $host – " . t('Logs: {name}', ['name' => $w['name']]);
            $srcNames = [];
            foreach (q('SELECT s.id, s.name, h.name AS host FROM log_sources s JOIN hosts h ON h.id = s.host_id')->fetchAll() as $r) {
                $srcNames[(int) $r['id']] = [$r['host'], $r['name']];
            }
            $lines = [];
            foreach (array_slice($sample, 0, 3) as [$ts, $sid, $msg]) {
                $lines[] = [$ts, $srcNames[$sid][0] ?? '?', $srcNames[$sid][1] ?? '?', mb_substr($msg, 0, 400)];
            }
            $ip = null;
            $ipN = 0;
            if ($sample) {
                $ips = [];
                foreach ($sample as $x) {
                    if (preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', $x[2], $m)) {
                        $ips[$m[1]] = ($ips[$m[1]] ?? 0) + 1;
                    }
                }
                arsort($ips);
                if ($ips && reset($ips) >= 2) {
                    $ip = (string) key($ips);
                    $ipN = (int) reset($ips);
                }
            }
            if ($w['mode'] === 'none') {
                $message = t('Geen treffer voor {q} in {win}.', ['q' => $w['q'], 'win' => self::winText((int) $w['win'])])
                    . ($lastHit ? ' ' . t('Laatste treffer: {t}.', ['t' => date('d-m-Y H:i', (int) strtotime($lastHit))]) : ' ' . t('Nog nooit een treffer gezien.'));
            } else {
                $message = t('{n} treffers in {win} (drempel {thr}).', ['n' => $n, 'win' => self::winText((int) $w['win']), 'thr' => $w['threshold']])
                    . ($ip ? ' ' . t('Meeste van {ip} ({n}×).', ['ip' => $ip, 'n' => $ipN]) : '')
                    . ($lines ? "\n" . t('Laatste: {l}', ['l' => mb_substr($lines[0][3], 0, 200)]) : '');
            }
            $detail = ['watch' => (int) $w['id'], 'name' => $w['name'], 'q' => $w['q'], 'mode' => $w['mode'], 'n' => $n, 'thr' => (int) $w['threshold'],
                'win' => (int) $w['win'], 'host_id' => $key, 'lines' => $lines, 'top_ip' => $ip, 'top_n' => $ipN];
            $alertId = self::send($sev, $title, $message, $key ?: null, "{$w['id']}:$key", $detail, $ip, $host, 'problem');
            if ($sev === STATUS_OK) {
                q('UPDATE log_watch_state SET last_sent = NOW() WHERE watch_id = ? AND host_id = ?', [$w['id'], $key]);
            } else {
                q('UPDATE log_watch_state SET open = 1, opened_at = NOW(), last_sent = NOW(), peak = ?, alert_id = ? WHERE watch_id = ? AND host_id = ?', [$n, $alertId, $w['id'], $key]);
            }
        });
    }

    /** Open melding dicht; met $recovery een herstelmelding */
    private static function close(array $w, int $key, array $s, bool $recovery): void
    {
        q('UPDATE log_watch_state SET open = 0 WHERE watch_id = ? AND host_id = ?', [$w['id'], $key]);
        if (!$recovery || (int) $w['severity'] === STATUS_OK) {
            return;
        }
        I18n::forNotify(function () use ($w, $key, $s) {
            $host = self::hostName($key);
            $title = '✅ ' . t('HERSTELD') . ": $host – " . t('Logs: {name}', ['name' => $w['name']]);
            $dur = max(60, time() - (int) strtotime((string) $s['opened_at']));
            $message = $w['mode'] === 'none' ? t('Er is weer een treffer voor {q}.', ['q' => $w['q']])
                : t('Weer onder de drempel ({thr} in {win}). Hoogste aantal: {peak}. Duurde {d} min.', ['thr' => $w['threshold'], 'win' => self::winText((int) $w['win']),
                    'peak' => (int) $s['peak'], 'd' => (int) round($dur / 60)]);
            self::send(STATUS_OK, $title, $message, $key ?: null, "{$w['id']}:$key", ['watch' => (int) $w['id'], 'name' => $w['name'], 'q' => $w['q'], 'host_id' => $key, 'recovery' => true], null, $host, 'recovery');
        });
    }

    /**
     * Via de gewone kanalen (Pushover, e-mail, Home Assistant). Stille uren (van de server, of de algemene) houden hem
     * tegen, behalve critical als het profiel dat doorlaat; hij komt dan wel op de pagina Meldingen.
     */
    private static function send(int $status, string $title, string $message, ?int $hostId, string $key, array $detail, ?string $ip, string $hostName, string $event): int
    {
        $h = $hostId ? q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch() : null;
        $quiet = $h ? Quiet::forHost($h) : Quiet::global();
        $hold = Quiet::until($quiet) !== null && !($status === STATUS_CRIT && !empty($quiet['crit']));
        $errors = $hold ? [t('stille uren')] : [];
        $pushover = false;
        $email = null;
        $webhook = null;
        if (!$hold) {
            if (Push::enabled()) {
                $prio = $event === 'recovery' ? 0 : ($status === STATUS_CRIT ? min(1, (int) setting('pushover_priority_crit', 1)) : ($status === STATUS_WARN ? 0 : -1));
                $r = Push::send($title, $message . "\n" . date('d-m-Y H:i:s'), $prio);
                $pushover = $r['ok'];
                if ($r['error']) {
                    $errors[] = $r['error'];
                }
            }
            if (setting('email_enabled', '0') === '1' && (setting('email_mode', 'always') !== 'fallback' || !$pushover)) {
                $base = rtrim((string) config('base_url', ''), '/');
                $r = Mailer::send($title, $message . "\n\n" . t('Tijd') . ': ' . date('d-m-Y H:i:s') . ($base !== '' ? "\n\nRzMon: $base/#logs" : ''));
                $email = $r['ok'];
                if (!$r['ok']) {
                    $errors[] = 'E-mail: ' . $r['error'];
                }
            }
            if (setting('ha_webhook_enabled', '0') === '1' && (setting('ha_webhook_events', 'all') !== 'crit' || $status === STATUS_CRIT || $event === 'recovery')) {
                $r = Webhook::send(['source' => 'rzmon', 'event' => $status === STATUS_OK && $event !== 'recovery' ? 'info' : $event, 'status' => $status,
                    'status_text' => STATUS_LABELS[$status], 'host' => $hostId ? $hostName : null, 'host_id' => $hostId, 'check' => null, 'log_search' => $detail['name'] ?? null,
                    'title' => $title, 'message' => $message, 'time' => date('c'), 'url' => config('base_url', '') ?: null]);
                $webhook = $r['ok'];
                if (!$r['ok']) {
                    $errors[] = 'Webhook: ' . $r['error'];
                }
            }
        }
        $kind = $status === STATUS_OK && $event !== 'recovery' ? 'info' : 'logs';
        q('INSERT INTO alerts (check_id, host_id, status, title, message, sent_ok, webhook_ok, email_ok, kind, ip, detail, log_watch) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $hostId, $status, mb_substr($title, 0, 250),
            mb_substr($message . ($errors ? ' [' . t('niet verzonden') . ': ' . implode('; ', $errors) . ']' : ''), 0, 1000),
            $pushover ? 1 : 0, $webhook === null ? null : ($webhook ? 1 : 0), $email === null ? null : ($email ? 1 : 0), $kind,
            $ip, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $key,
        ]);
        return (int) db()->lastInsertId();
    }

    /**
     * Proef: hoe vaak had deze zoekactie de afgelopen 24 uur gemeld? Met de treffers per 5 minuten (voor de grafiek)
     * en de tijden van de meldingen. Telt per minuut en loopt het venster minuut voor minuut door, zoals het echt zou gaan.
     */
    public static function simulate(array $in): array
    {
        $r = self::clean($in);
        if (is_string($r)) {
            return ['error' => $r];
        }
        $p = LogStore::parseQuery($r['q']);
        [$hosts, $sources, $src] = LogStore::scope($p, [], []);
        $now = time();
        $from = $now - 86400;
        $per = [];   // sleutel → minuut → aantal
        $last = [];  // sleutel → laatste treffer
        $res = LogStore::scan($p, $from - ($r['mode'] === 'none' ? 0 : $r['win'] * 60), $now, $hosts, $sources, 5.0,
            function (int $ts, int $hostId) use (&$per, &$last, $r) {
                $k = $r['per_host'] ? $hostId : 0;
                $m = intdiv($ts, 60);
                $per[$k][$m] = ($per[$k][$m] ?? 0) + 1;
                $last[$k] = max($last[$k] ?? 0, $ts);
            });
        $names = [];
        foreach ($src as $s) {
            $names[(int) $s['host_id']] = $s['host'];
        }
        $bucket = 300;
        $start = intdiv($from, $bucket) * $bucket;
        $nb = (int) ceil(($now - $start + 1) / $bucket);
        $hist = array_fill(0, $nb, 0);
        $maxWin = array_fill(0, $nb, 0);   // hoogste telling in het venster binnen dat tijdvak (voor de drempel)
        $fires = [];
        foreach ($per as $k => $mins) {
            foreach ($mins as $m => $n) {
                $i = intdiv($m * 60 - $start, $bucket);
                if (isset($hist[$i])) {
                    $hist[$i] += $n;
                }
            }
            if ($r['mode'] !== 'many') {
                continue;
            }
            $open = false;
            $openedAt = 0;
            $lastSent = 0;
            $sum = 0;
            $win = $r['win'];
            for ($m = intdiv($from, 60); $m <= intdiv($now, 60); $m++) {
                $sum += $mins[$m] ?? 0;
                $sum -= $mins[$m - $win] ?? 0;
                $i = intdiv($m * 60 - $start, $bucket);
                if (isset($maxWin[$i]) && $sum > $maxWin[$i]) {
                    $maxWin[$i] = $sum;
                }
                $t = $m * 60;
                if ($open) {
                    if ($t - $openedAt >= $win * 60 && $sum < $r['threshold'] && ($r['recover'] || $t - $openedAt >= $r['rest'] * 60)) {
                        $open = false;
                    }
                    continue;
                }
                if ($sum >= $r['threshold'] && $t - $lastSent >= $r['rest'] * 60) {
                    $fires[] = ['ts' => $t, 'host' => $k ? ($names[$k] ?? '?') : null, 'n' => $sum];
                    $lastSent = $t;
                    if ($r['severity'] !== STATUS_OK) {
                        $open = true;
                        $openedAt = $t;
                    }
                }
            }
        }
        if ($r['mode'] === 'none') {
            // Geen treffers: zou hij nu melden? En wanneer was de laatste?
            $keys = $r['per_host'] ? array_values(array_unique(array_map(static fn($s) => (int) $s['host_id'], array_filter($src, static fn($s) =>
                (!$hosts || in_array((int) $s['host_id'], $hosts, true)) && (!$sources || in_array((int) $s['id'], $sources, true)))))) : [0];
            foreach ($keys as $k) {
                $lh = $last[$k] ?? null;
                if ($lh === null || $lh < $now - $r['win'] * 60) {
                    $fires[] = ['ts' => $now, 'host' => $k ? ($names[$k] ?? '?') : null, 'n' => 0, 'last' => $lh];
                }
            }
        }
        usort($fires, static fn($a, $b) => $a['ts'] <=> $b['ts']);
        $byHost = [];
        foreach ($fires as $f) {
            $byHost[$f['host'] ?? ''] = ($byHost[$f['host'] ?? ''] ?? 0) + 1;
        }
        return ['fires' => $fires, 'count' => count($fires), 'by_host' => $byHost, 'hist' => $hist, 'max_win' => $maxWin, 'bucket' => $bucket, 'start' => $start,
            'threshold' => $r['threshold'], 'mode' => $r['mode'], 'last' => $last ? max($last) : null, 'partial' => $res['partial'], 'ms' => $res['ms']];
    }
}
