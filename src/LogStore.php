<?php
declare(strict_types=1);

/**
 * Logs van servers (1.210.0, agent 1.83.0): de agent leest de nieuwe regels van de bronnen die je per server kiest,
 * pakt ze elke paar minuten gecomprimeerd en versleuteld in en stuurt ze los van het rapport (actie agent_logs).
 * RzMon maskeert geheimen, herkent het niveau en de tijd, en zet de regels in een tabel per dag (logd_JJJJMMDD), als
 * gecomprimeerde blokken: per bron hooguit 5000 regels per blok, met gzip (gemeten: 8× kleiner dan de ruwe logs; een rij
 * per regel met een zoekindex werd juist groter dan de logs zelf). Per blok staan de tijden en het aantal warnings en
 * errors erbij, zodat zoeken alleen de blokken uitpakt die ertoe doen. Een oude dag gaat in één keer weg.
 *
 * Pakketje (na uitpakken), één regel per regel, tab-gescheiden:
 *   rzlogs1 <tab> kenmerk <tab> tijd van de agent <tab> weggegooid (bytes, omdat de wachtrij vol was) <tab> tijdzone (+0100)
 *   L <tab> bron-id <tab> tijd van lezen (epoch) <tab> tekst
 *   S <tab> bron-id <tab> ok|err <tab> aantal bestanden <tab> uitleg
 */
final class LogStore
{
    /** Soorten bronnen: bestand of map met *, de journal, de journal van één service, een container, Home Assistant */
    public const KINDS = ['file', 'journal', 'unit', 'docker', 'ha'];
    /** Niveaus: 0 alles, 1 info en erger, 2 warning en erger, 3 alleen errors (filter op de agent) */
    public const LEVELS = [0, 1, 2, 3];
    private const MAX_LINE = 8000;
    private const BLOCK_LINES = 5000;
    private const BLOCK_BYTES = 2000000;
    /** @var array<string, bool> dagtabellen die al bestaan */
    private static array $tables = [];

    public static function enabled(): bool
    {
        return (string) setting('logs_on', '1') === '1';
    }

    public static function days(): int
    {
        return max(1, min(365, (int) setting('logs_days', '14')));
    }

    public static function maxBytes(): int
    {
        return (int) (max(0.1, min(10000, (float) setting('logs_max_gb', '5'))) * 1e9);   // GB en MB zoals op het scherm (1000)
    }

    public static function dayLimit(): int
    {
        return max(1, min(100000, (int) setting('logs_day_mb', '200'))) * 1000000;
    }

    /** Elke … seconden een pakketje: de keuze voor deze server, anders de algemene (1-60 minuten) */
    public static function intervalFor(array $host): int
    {
        $m = (int) ($host['logs_interval'] ?? 0) ?: (int) setting('logs_interval', '5');
        return 60 * max(1, min(60, $m));
    }

    /** Is de bron geldig? Dan de nette vorm, anders null. Bestanden: een absoluut pad (met * of ?), zonder "..". */
    public static function cleanSpec(string $kind, string $spec): ?string
    {
        $spec = trim($spec);
        return match ($kind) {
            'file' => preg_match('#^/[A-Za-z0-9._@+/*?\[\]-]{1,299}$#', $spec) && !str_contains($spec, '..') && !str_contains($spec, '//') ? $spec : null,
            'journal' => '',
            'unit' => preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', $spec) ? $spec : null,
            'docker' => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $spec) ? $spec : null,
            'ha' => preg_match('/^(host|core|supervisor|addon:[a-z0-9_]{1,64})$/', $spec) ? $spec : null,
            default => null,
        };
    }

    // ------------------------------------------------------------------ naar de agent

    /** Regels voor de lijst van de agent (agent_tests): bronnen, hoe vaak, en of de daglimiet bereikt is */
    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $host = q('SELECT id, mode, logs_interval FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$host || $host['mode'] !== 'agent') {
            return [];
        }
        $out = [];
        foreach (q('SELECT id, kind, spec, level FROM log_sources WHERE host_id = ? AND enabled = 1 ORDER BY id', [$hostId]) as $s) {
            if (self::cleanSpec((string) $s['kind'], (string) $s['spec']) !== null) {
                $out[] = implode("\t", [$s['id'], 'logsrc', $s['kind'], $s['spec'] === '' ? '-' : $s['spec'], (int) $s['level']]);
            }
        }
        if (!$out) {
            return [];
        }
        array_unshift($out, implode("\t", [0, 'logship', self::intervalFor($host)]));
        if (self::limitReached($hostId)) {
            $out[] = implode("\t", [0, 'logpause', strtotime('tomorrow')]);   // tot middernacht niets meer lezen; daarna verder waar hij was
        }
        return $out;
    }

    /** Daglimiet bereikt: vol, of er moest vandaag al iets weg omdat het niet meer paste */
    public static function limitReached(int $hostId): bool
    {
        $r = q('SELECT COALESCE(SUM(bytes), 0) AS b, COALESCE(SUM(dropped), 0) AS x FROM log_daily WHERE host_id = ? AND day = CURDATE()', [$hostId])->fetch();
        return (int) $r['b'] >= self::dayLimit() || (int) $r['x'] > 0;
    }

    public static function usedToday(int $hostId): int
    {
        return (int) q('SELECT COALESCE(SUM(bytes), 0) FROM log_daily WHERE host_id = ? AND day = CURDATE()', [$hostId])->fetchColumn();
    }

    // ------------------------------------------------------------------ ontvangen

    /** Een pakketje van de agent opslaan. @return array antwoord voor de agent */
    public static function ingest(array $host, string $raw): array
    {
        $hostId = (int) $host['id'];
        $rows = explode("\n", $raw);
        $head = explode("\t", rtrim((string) array_shift($rows), "\r"));
        if (($head[0] ?? '') !== 'rzlogs1' || !preg_match('/^[A-Za-z0-9._-]{4,40}$/', (string) ($head[1] ?? ''))) {
            return ['ok' => false, 'error' => t('geen geldig logpakket')];
        }
        $batch = (string) $head[1];
        $agentNow = (int) ($head[2] ?? 0) ?: time();
        $tz = preg_match('/^[+-]\d{4}$/', (string) ($head[4] ?? '')) ? (string) $head[4] : null;   // tijden zonder zone zijn in de tijd van de server
        if (q('SELECT 1 FROM log_batches WHERE host_id = ? AND batch = ?', [$hostId, $batch])->fetchColumn()) {
            return ['ok' => true, 'batch' => $batch, 'stored' => 0, 'dup' => true];   // al binnen (antwoord ging verloren): de agent mag hem weggooien
        }
        $sources = [];
        foreach (q('SELECT id, kind, enabled FROM log_sources WHERE host_id = ?', [$hostId]) as $s) {
            $sources[(int) $s['id']] = $s;
        }
        $limit = self::dayLimit();
        $used = self::usedToday($hostId);
        $wasOver = $used >= $limit;
        $mask = (string) setting('logs_mask', '1') === '1' ? self::maskPatterns() : null;
        $per = [];      // bron → [regels, bytes, weggegooid]
        $byDay = [];    // dag → rijen
        $stored = 0;
        $statusSeen = [];
        foreach ($rows as $line) {
            if ($line === '') {
                continue;
            }
            $f = explode("\t", $line, 5);
            $sid = (int) ($f[1] ?? 0);
            if (!isset($sources[$sid])) {
                continue;
            }
            if ($f[0] === 'S') {
                $statusSeen[$sid] = [($f[2] ?? '') === 'ok' ? 'ok' : 'err', isset($f[3]) && $f[3] !== '' ? (int) $f[3] : null, mb_substr((string) ($f[4] ?? ''), 0, 300)];
                continue;
            }
            if ($f[0] !== 'L' || (int) $sources[$sid]['enabled'] !== 1) {
                continue;
            }
            $text = implode("\t", array_slice($f, 3));
            $text = rtrim($text, "\r");
            if (trim($text) === '') {
                continue;
            }
            $per[$sid] ??= [0, 0, 0];
            $bytes = strlen($text);
            if ($used + $bytes > $limit) {
                $per[$sid][2]++;
                continue;
            }
            $used += $bytes;
            $read = (int) ($f[2] ?? 0) ?: $agentNow;
            $ts = self::stamp($text, $read, $tz);
            if ($sources[$sid]['kind'] === 'docker') {
                $text = preg_replace('/^\d{4}-\d\d-\d\dT[\d:.]+(Z|[+-]\d\d:?\d\d)\s/', '', $text) ?? $text;   // de tijd die docker --timestamps ervoor zet
            }
            if ($mask !== null) {
                $text = self::mask($text, $mask);
            }
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
            $text = mb_strcut($text, 0, self::MAX_LINE, 'UTF-8');
            $byDay[date('Y-m-d', $ts)][$sid][] = [$ts, self::level($text), $text];
            $per[$sid][0]++;
            $per[$sid][1] += $bytes;
            $stored++;
        }
        // Dagtabellen eerst (CREATE TABLE sluit een lopende transactie af)
        $tables = [];
        foreach (array_keys($byDay) as $day) {
            $tables[$day] = self::table((string) $day);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($byDay as $day => $bySrc) {
                foreach ($bySrc as $sid => $list) {
                    foreach (self::blocks($list) as $b) {
                        self::insertBlock($tables[$day], $hostId, (int) $sid, $b);
                    }
                }
            }
            foreach ($per as $sid => [$n, $b, $d]) {
                q('INSERT INTO log_daily (host_id, source_id, day, `lines`, bytes, dropped) VALUES (?, ?, CURDATE(), ?, ?, ?)
                   ON DUPLICATE KEY UPDATE `lines` = `lines` + VALUES(`lines`), bytes = bytes + VALUES(bytes), dropped = dropped + VALUES(dropped)', [$hostId, $sid, $n, $b, $d]);
                if ($n > 0) {
                    q('UPDATE log_sources SET last_at = NOW() WHERE id = ?', [$sid]);
                }
            }
            foreach ($statusSeen as $sid => [$st, $files, $msg]) {
                q('UPDATE log_sources SET status = ?, files = ?, status_msg = ? WHERE id = ?', [$st, $files, $msg !== '' ? $msg : null, $sid]);
            }
            q('INSERT IGNORE INTO log_batches (host_id, batch, at) VALUES (?, ?, NOW())', [$hostId, $batch]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $dropped = array_sum(array_column($per, 2));
        if (!$wasOver && $dropped > 0) {
            // Daglimiet net bereikt: één melding, met de bron die het meeste stuurde
            $top = q('SELECT s.name, d.bytes FROM log_daily d JOIN log_sources s ON s.id = d.source_id WHERE d.host_id = ? AND d.day = CURDATE() ORDER BY d.bytes DESC LIMIT 1', [$hostId])->fetch();
            Monitor::sysInfo('logs_limit', $hostId, (string) $host['name'], '📜 ' . t('Logs: daglimiet bereikt op {name}', ['name' => $host['name']]),
                fn() => t('{name} stuurde vandaag {mb} MB aan logs (limiet {lim} MB). Tot middernacht worden er geen logs meer opgeslagen; daarna gaat de agent verder waar hij was.', [
                    'name' => $host['name'], 'mb' => round($used / 1e6), 'lim' => round($limit / 1e6)]) . ($top ? ' ' . t('Meeste regels: {src}.', ['src' => $top['name']]) : ''));
        }
        return ['ok' => true, 'batch' => $batch, 'stored' => $stored, 'dropped' => $dropped];
    }

    /** Niveau uit de tekst: 0 debug, 1 info, 2 warning, 3 error, 4 critical */
    public static function level(string $s): int
    {
        $h = substr($s, 0, 300);
        return match (true) {
            (bool) preg_match('/\b(emerg(ency)?|alert|crit(ical)?|fatal|panic)\b/i', $h) => 4,
            (bool) preg_match('/\b(err(or)?|errors|exception|failed|failure)\b|\[e\]|\s[EF]\s/i', $h) => 3,
            (bool) preg_match('/\b(warn(ing)?|notice)\b|\[w\]/i', $h) => 2,
            (bool) preg_match('/\b(debug|trace|verbose)\b/i', $h) => 0,
            default => 1,
        };
    }

    /**
     * Tijd van de regel: ISO (journal, docker, de meeste programma's), syslog ("Nov  7 11:02:14") of nginx/apache
     * ("[07/Nov/2026:11:02:14 +0100]"). Niets herkend, of onwaarschijnlijk (meer dan 3 dagen eerder of later): de tijd van lezen.
     */
    public static function stamp(string $s, int $read, ?string $tz = null): int
    {
        $h = substr($s, 0, 120);
        $ts = null;
        $zone = $tz !== null ? " $tz" : '';
        if (preg_match('/^\[?(\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:[.,]\d+)?)(Z|\s?[+-]\d\d:?\d\d)?/', $h, $m)) {
            $ts = strtotime(str_replace(',', '.', $m[1]) . (($m[2] ?? '') !== '' ? $m[2] : $zone)) ?: null;
        } elseif (preg_match('/^([A-Z][a-z]{2})\s+(\d{1,2}) (\d\d:\d\d:\d\d)\b/', $h, $m)) {
            $ts = strtotime("$m[2] $m[1] " . date('Y', $read) . " $m[3]$zone") ?: null;
            if ($ts !== null && $ts > $read + 86400) {
                $ts = strtotime('-1 year', $ts) ?: null;   // december-regel die in januari binnenkomt
            }
        } elseif (preg_match('#\[(\d\d/[A-Z][a-z]{2}/\d{4}:\d\d:\d\d:\d\d [+-]\d{4})\]#', $h, $m)) {
            $d = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[1]);
            $ts = $d ? $d->getTimestamp() : null;
        }
        return $ts !== null && abs($ts - $read) <= 3 * 86400 ? $ts : $read;
    }

    /** Eigen patronen voor het maskeren (één per regel, als reguliere expressie); ongeldige worden overgeslagen */
    public static function maskPatterns(): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) setting('logs_mask_extra', '')) ?: [] as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) > 200) {
                continue;
            }
            $re = '~' . str_replace('~', '\~', $p) . '~iu';
            if (@preg_match($re, '') !== false) {
                $out[] = $re;
            }
        }
        return $out;
    }

    /** Wachtwoorden, tokens, sleutels en e-mailadressen vervangen door •••• (zelfde regels als bij AI-advies, plus de eigen) */
    public static function mask(string $s, array $extra = []): string
    {
        $s = Ai::scrub($s);
        $s = preg_replace('/\beyJ[\w-]{8,}\.[\w-]{8,}\.[\w-]{8,}\b/', '••••', $s) ?? $s;   // JWT
        $s = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '••••@••••', $s) ?? $s;
        foreach ($extra as $re) {
            $s = @preg_replace($re, '••••', $s) ?? $s;
        }
        return $s;
    }

    // ------------------------------------------------------------------ dagtabellen

    /** Naam van de tabel voor een dag; maakt hem aan als hij er nog niet is (gecomprimeerd, anders gewoon) */
    public static function table(string $day): string
    {
        $t = 'logd_' . str_replace('-', '', $day);
        if (!preg_match('/^logd_\d{8}$/', $t)) {
            throw new RuntimeException('dag');
        }
        if (isset(self::$tables[$t])) {
            return $t;
        }
        // Eén rij per blok: de regels zelf zijn met gzip gecomprimeerd (data), de rest is om te kunnen kiezen welke blokken je uitpakt
        db()->exec("CREATE TABLE IF NOT EXISTS `$t` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, host_id INT UNSIGNED NOT NULL, source_id INT UNSIGNED NOT NULL,
            ts_from DATETIME NOT NULL, ts_to DATETIME NOT NULL, n INT UNSIGNED NOT NULL, n_warn INT UNSIGNED NOT NULL DEFAULT 0, n_err INT UNSIGNED NOT NULL DEFAULT 0,
            raw INT UNSIGNED NOT NULL, data MEDIUMBLOB NOT NULL, INDEX idx_src (host_id, source_id, ts_from), INDEX idx_ts (ts_from)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$tables[$t] = true;
        return $t;
    }

    /** Regels [tijd, niveau, tekst] in blokken: hooguit 5000 regels of 2 MB per blok, op tijd gesorteerd */
    private static function blocks(array $list): array
    {
        usort($list, static fn($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        $cur = [];
        $size = 0;
        foreach ($list as $r) {
            $len = strlen($r[2]) + 16;
            if ($cur && (count($cur) >= self::BLOCK_LINES || $size + $len > self::BLOCK_BYTES)) {
                $out[] = $cur;
                $cur = [];
                $size = 0;
            }
            $cur[] = $r;
            $size += $len;
        }
        if ($cur) {
            $out[] = $cur;
        }
        return $out;
    }

    private static function insertBlock(string $table, int $hostId, int $sid, array $rows): void
    {
        $text = '';
        $warn = $err = 0;
        foreach ($rows as [$ts, $lvl, $msg]) {
            $text .= $ts . "\t" . $lvl . "\t" . str_replace(["\n", "\r"], ' ', $msg) . "\n";
            $warn += $lvl === 2 ? 1 : 0;
            $err += $lvl >= 3 ? 1 : 0;
        }
        q("INSERT INTO `$table` (host_id, source_id, ts_from, ts_to, n, n_warn, n_err, raw, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $hostId, $sid, date('Y-m-d H:i:s', $rows[0][0]), date('Y-m-d H:i:s', $rows[count($rows) - 1][0]), count($rows), $warn, $err, strlen($text),
            gzcompress($text, 6)]);
    }

    /** Een blok uitpakken: [[tijd, niveau, tekst], …] */
    public static function unpack(string $data): array
    {
        $out = [];
        $text = @gzuncompress($data);
        if ($text === false) {
            return [];
        }
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            $f = explode("\t", $line, 3);
            if (count($f) === 3) {
                $out[] = [(int) $f[0], (int) $f[1], $f[2]];
            }
        }
        return $out;
    }

    /** Alle dagtabellen, oudste eerst: [naam, dag, bytes, regels, gecomprimeerd] */
    public static function dayTables(): array
    {
        $out = [];
        foreach (q("SELECT TABLE_NAME AS n, DATA_LENGTH + INDEX_LENGTH AS b, DATA_LENGTH AS d, INDEX_LENGTH AS i
                    FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'logd\\_%' ORDER BY TABLE_NAME")->fetchAll() as $r) {
            if (preg_match('/^logd_(\d{4})(\d\d)(\d\d)$/', (string) $r['n'], $m)) {
                $out[] = ['name' => $r['n'], 'day' => "$m[1]-$m[2]-$m[3]", 'bytes' => (int) $r['b'], 'data' => (int) $r['d'], 'index' => (int) $r['i']];
            }
        }
        return $out;
    }

    /** Opruimen: dagen ouder dan de bewaartermijn, en de oudste dagen als het totaal boven het maximum komt */
    public static function purge(): void
    {
        $keep = date('Y-m-d', strtotime('-' . (self::days() - 1) . ' days'));
        $tables = self::dayTables();
        foreach ($tables as $i => $t) {
            if ($t['day'] < $keep) {
                db()->exec("DROP TABLE IF EXISTS `{$t['name']}`");
                unset($tables[$i], self::$tables[$t['name']]);
            }
        }
        $tables = array_values($tables);
        $total = array_sum(array_column($tables, 'bytes'));
        $dropped = [];
        while ($total > self::maxBytes() && count($tables) > 1) {
            $t = array_shift($tables);
            db()->exec("DROP TABLE IF EXISTS `{$t['name']}`");
            unset(self::$tables[$t['name']]);
            $total -= $t['bytes'];
            $dropped[] = $t['day'];
        }
        if ($dropped) {
            Monitor::sysInfo('logs_full', null, null, '📜 ' . t('Logs: maximum bereikt'), fn() => t('De logs kwamen boven {gb} GB. De oudste dag(en) zijn weggegooid: {days}.', [
                'gb' => setting('logs_max_gb', '5'), 'days' => implode(', ', $dropped)]));
        }
        q('DELETE FROM log_batches WHERE at < NOW() - INTERVAL 3 DAY');
        q('DELETE FROM log_daily WHERE day < CURDATE() - INTERVAL ? DAY', [self::days() + 1]);
    }

    // ------------------------------------------------------------------ beheren en overzicht

    /** Bronnen van een server, met wat er vandaag binnenkwam */
    public static function sources(int $hostId): array
    {
        $out = [];
        foreach (q('SELECT s.*, COALESCE(d.`lines`, 0) AS lines_today, COALESCE(d.bytes, 0) AS bytes_today, COALESCE(d.dropped, 0) AS dropped_today
                    FROM log_sources s LEFT JOIN log_daily d ON d.source_id = s.id AND d.host_id = s.host_id AND d.day = CURDATE()
                    WHERE s.host_id = ? ORDER BY s.name, s.id', [$hostId]) as $s) {
            $out[] = ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'spec' => $s['spec'], 'level' => (int) $s['level'],
                'enabled' => (int) $s['enabled'] === 1, 'status' => $s['status'], 'status_msg' => $s['status_msg'], 'files' => $s['files'] !== null ? (int) $s['files'] : null,
                'last_at' => $s['last_at'], 'lines_today' => (int) $s['lines_today'], 'bytes_today' => (int) $s['bytes_today'], 'dropped_today' => (int) $s['dropped_today']];
        }
        return $out;
    }

    /** De laatste regels van een bron (vandaag en gisteren) */
    public static function lastLines(int $sourceId, int $n = 8): array
    {
        $src = q('SELECT host_id FROM log_sources WHERE id = ?', [$sourceId])->fetch();
        if (!$src) {
            return [];
        }
        $out = [];
        $have = array_column(self::dayTables(), 'name');
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $day) {
            $t = 'logd_' . str_replace('-', '', $day);
            if (!in_array($t, $have, true) || count($out) >= $n) {
                continue;
            }
            foreach (q("SELECT data FROM `$t` WHERE host_id = ? AND source_id = ? ORDER BY ts_to DESC, id DESC LIMIT 3", [$src['host_id'], $sourceId])->fetchAll() as $r) {
                foreach (array_reverse(self::unpack((string) $r['data'])) as [$ts, $lvl, $msg]) {
                    if (count($out) >= $n) {
                        break 2;
                    }
                    $out[] = ['ts' => date('Y-m-d H:i:s', $ts), 'level' => $lvl, 'msg' => mb_substr($msg, 0, 600)];
                }
            }
        }
        return $out;
    }

    /** Bron toevoegen of wijzigen. @return int|string id, of een foutmelding */
    public static function save(int $hostId, array $in): int|string
    {
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            return t('Kies een soort bron');
        }
        $spec = self::cleanSpec($kind, (string) ($in['spec'] ?? ''));
        if ($spec === null) {
            return match ($kind) {
                'file' => t('Vul een volledig pad in, bv. /var/log/nginx/*.log (zonder ..)'),
                'unit' => t('Vul de naam van de service in, bv. mariadb.service'),
                'docker' => t('Vul de naam van de container in'),
                default => t('Kies wat je uit Home Assistant wilt: host, core, supervisor of addon:<slug>'),
            };
        }
        $name = trim((string) ($in['name'] ?? '')) ?: ($kind === 'journal' ? t('Systeem (journal)') : basename(str_replace('*', 'alle', $spec)));
        $level = (int) ($in['level'] ?? 0);
        $level = in_array($level, self::LEVELS, true) ? $level : 0;
        $id = (int) ($in['id'] ?? 0);
        if ($id) {
            $own = q('SELECT spec, kind FROM log_sources WHERE id = ? AND host_id = ?', [$id, $hostId])->fetch();
            if (!$own) {
                return t('Deze bron bestaat niet (meer)');
            }
            q('UPDATE log_sources SET name = ?, kind = ?, spec = ?, level = ?, enabled = ? WHERE id = ?', [mb_substr($name, 0, 100), $kind, $spec, $level, empty($in['enabled']) ? 0 : 1, $id]);
            if ($own['spec'] !== $spec || $own['kind'] !== $kind) {
                q('UPDATE log_sources SET status = NULL, status_msg = NULL, files = NULL WHERE id = ?', [$id]);
            }
            return $id;
        }
        if ((int) q('SELECT COUNT(*) FROM log_sources WHERE host_id = ?', [$hostId])->fetchColumn() >= 50) {
            return t('Hooguit 50 bronnen per server');
        }
        q('INSERT INTO log_sources (host_id, name, kind, spec, level, enabled) VALUES (?, ?, ?, ?, ?, 1)', [$hostId, mb_substr($name, 0, 100), $kind, $spec, $level]);
        return (int) db()->lastInsertId();
    }

    public static function setEnabled(int $id, bool $on): void
    {
        q('UPDATE log_sources SET enabled = ? WHERE id = ?', [$on ? 1 : 0, $id]);
    }

    /** Bron verwijderen; de regels die al binnen zijn blijven tot ze verlopen */
    public static function delete(int $id): void
    {
        q('DELETE FROM log_sources WHERE id = ?', [$id]);
    }

    /** Voor de beheerportal: opslag en per server wat er binnenkomt */
    public static function overview(): array
    {
        $tables = self::dayTables();
        $used = array_sum(array_column($tables, 'bytes'));
        $first = $tables ? $tables[0]['day'] : date('Y-m-d');
        $raw = (int) q('SELECT COALESCE(SUM(bytes), 0) FROM log_daily WHERE day >= ?', [$first])->fetchColumn();
        $perDay = [];
        foreach (q('SELECT day, SUM(bytes) AS b, SUM(`lines`) AS n FROM log_daily WHERE day >= CURDATE() - INTERVAL 13 DAY GROUP BY day ORDER BY day') as $r) {
            $perDay[] = ['day' => $r['day'], 'bytes' => (int) $r['b'], 'lines' => (int) $r['n']];
        }
        $todayStored = 0;
        foreach ($tables as $t) {
            if ($t['day'] === date('Y-m-d')) {
                $todayStored = $t['bytes'];
            }
        }
        $ratio = $used > 0 && $raw > 0 ? $raw / $used : null;
        $rawDays = array_filter(array_column($perDay, 'bytes'));
        $avgStored = $rawDays && $ratio ? array_sum($rawDays) / count($rawDays) / $ratio : null;
        $hosts = [];
        $limit = self::dayLimit();
        foreach (q("SELECT h.id, h.name, h.mode, h.logs_interval, COUNT(s.id) AS n, SUM(s.enabled) AS n_on, SUM(s.enabled = 1 AND s.status = 'err') AS n_err, MAX(s.last_at) AS last_at
                    FROM hosts h LEFT JOIN log_sources s ON s.host_id = h.id WHERE h.mode = 'agent' GROUP BY h.id ORDER BY h.name") as $h) {
            $d = q('SELECT COALESCE(SUM(`lines`), 0) AS n, COALESCE(SUM(bytes), 0) AS b, COALESCE(SUM(dropped), 0) AS x FROM log_daily WHERE host_id = ? AND day = CURDATE()', [$h['id']])->fetch();
            $hosts[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'sources' => (int) $h['n'], 'sources_on' => (int) $h['n_on'], 'errors' => (int) $h['n_err'],
                'last_at' => $h['last_at'], 'lines_today' => (int) $d['n'], 'bytes_today' => (int) $d['b'], 'dropped_today' => (int) $d['x'],
                'limit' => $limit, 'paused' => (int) $d['b'] >= $limit || (int) $d['x'] > 0, 'interval' => self::intervalFor($h) / 60, 'own_interval' => $h['logs_interval'] !== null];
        }
        return [
            'on' => self::enabled(), 'days' => self::days(), 'max' => self::maxBytes(), 'day_limit' => $limit,
            'used' => $used, 'data' => array_sum(array_column($tables, 'data')), 'index' => array_sum(array_column($tables, 'index')), 'today' => $todayStored,
            'rows' => (int) q('SELECT COALESCE(SUM(`lines`), 0) FROM log_daily WHERE day >= ?', [$first])->fetchColumn(), 'raw' => $raw,
            'ratio' => $ratio !== null ? round($ratio, 1) : null,
            'tables' => count($tables), 'per_day' => $perDay, 'avg_stored' => $avgStored !== null ? (int) $avgStored : null,
            'days_left' => $avgStored ? (int) floor(max(0, self::maxBytes() - $used) / $avgStored) : null,
            'hosts' => $hosts,
        ];
    }
}
