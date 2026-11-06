<?php

declare(strict_types=1);

/**
 * 1.200.0 (agent 1.81.0): databasecheck voor MariaDB/MySQL en PostgreSQL (checktype 'mysql', zoals sinds 1.138.0).
 *
 * Meten kan op twee manieren (options.via):
 *  - 'agent': de agent leest de database lokaal uit (socket of in een container), zonder dat de database open op het netwerk
 *    hoeft; zonder gebruiker als root (MariaDB) of postgres (PostgreSQL), dus zonder wachtwoord;
 *  - 'rzmon': RzMon maakt zelf verbinding via het netwerk (PDO), zoals vóór 1.200.0.
 * Beide leveren dezelfde ruwe sleutel/waarde-lijst; normalize() maakt daar één beeld van, check() beoordeelt het.
 * Eén verbinding = één meting (sleutel), ook als er meerdere checks op staan: een overzicht en losse waarden (metric).
 * Alles is alleen lezen: status, verbindingen, lopende query's, replicatie, grootte. Nooit data.
 */
final class Db
{
    /** Wat een check bewaakt (kolom metric; leeg = overzicht) met de eenheid van de meetwaarde */
    public const UNITS = ['overview' => 'ms', 'conn' => '%', 'active' => "query's", 'qps' => '/s', 'slow' => '/min', 'cache' => '%', 'repl' => 's',
        'size' => 'GB', 'longq' => 's', 'deadlocks' => 'deadlocks', 'tmpdisk' => '%', 'wrap' => '%', 'idle' => 'verbindingen'];
    /** Korte naam per waarde (voor de naam van een nieuwe check) */
    public const LABELS = ['conn' => 'Verbindingen', 'active' => 'Actieve query\'s', 'qps' => 'Query\'s per seconde', 'slow' => 'Trage query\'s', 'cache' => 'Cache-hit',
        'repl' => 'Replicatie', 'size' => 'Grootte', 'longq' => 'Langste query', 'deadlocks' => 'Deadlocks', 'tmpdisk' => 'Tijdelijke tabellen op schijf',
        'wrap' => 'Wraparound', 'idle' => 'Idle in transaction'];
    /** Alleen bij deze database */
    public const ONLY = ['slow' => 'mysql', 'tmpdisk' => 'mysql', 'wrap' => 'pgsql', 'idle' => 'pgsql'];

    private const MY_STATUS = ['Uptime', 'Threads_connected', 'Threads_running', 'Questions', 'Slow_queries', 'Aborted_connects', 'Innodb_buffer_pool_reads',
        'Innodb_buffer_pool_read_requests', 'Innodb_deadlocks', 'Innodb_row_lock_current_waits', 'Created_tmp_tables', 'Created_tmp_disk_tables',
        'Max_used_connections', 'wsrep_cluster_status', 'wsrep_cluster_size', 'wsrep_local_state_comment', 'wsrep_ready'];
    private const MY_REPL = ['Slave_IO_Running', 'Slave_SQL_Running', 'Seconds_Behind_Master', 'Replica_IO_Running', 'Replica_SQL_Running', 'Seconds_Behind_Source',
        'Last_IO_Error', 'Last_SQL_Error', 'Master_Host', 'Source_Host'];
    /** Dezelfde query's als de agent (76-database.sh), één per keer via PDO */
    private const PG_QUERIES = [
        "SELECT 'version', current_setting('server_version')",
        "SELECT 'uptime', floor(extract(epoch from now() - pg_postmaster_start_time()))::bigint",
        "SELECT 'max_conn', current_setting('max_connections')",
        "SELECT 'conn', count(*) FROM pg_stat_activity WHERE backend_type = 'client backend'",
        "SELECT 'active', count(*) FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid()",
        "SELECT 'idle_tx', count(*) FROM pg_stat_activity WHERE state LIKE 'idle in transaction%' AND now() - state_change > interval '5 minutes'",
        "SELECT 'xact', sum(xact_commit + xact_rollback) FROM pg_stat_database",
        "SELECT 'blks_hit', sum(blks_hit) FROM pg_stat_database",
        "SELECT 'blks_read', sum(blks_read) FROM pg_stat_database",
        "SELECT 'deadlocks', sum(deadlocks) FROM pg_stat_database",
        "SELECT 'temp_files', sum(temp_files) FROM pg_stat_database",
        "SELECT 'wrap_age', max(age(datfrozenxid)) FROM pg_database",
        "SELECT 'lock_wait', count(*) FROM pg_locks WHERE NOT granted",
        "SELECT 'recovery', pg_is_in_recovery()::int",
        "SELECT 'repl_lag', COALESCE(floor(extract(epoch from now() - pg_last_xact_replay_timestamp()))::bigint, -1) WHERE pg_is_in_recovery()",
        "SELECT 'replicas', count(*) FROM pg_stat_replication",
        "SELECT 'arch_failed', failed_count FROM pg_stat_archiver",
        "SELECT 'size:' || datname, pg_database_size(datname) FROM pg_database WHERE datallowconn AND NOT datistemplate",
        "SELECT 'longq', floor(extract(epoch from now() - query_start))::bigint || '|' || coalesce(usename, '') || '|' || coalesce(datname, '') || '|' || translate(encode(convert_to(left(query, 300), 'UTF8'), 'base64'), E'\\n', '') FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid() AND now() - query_start > interval '5 seconds' ORDER BY query_start LIMIT 5",
    ];
    /** Grenzen die niet per check in te stellen zijn (het overzicht) */
    private const CONN_WARN = 90, CONN_CRIT = 98, LAG_WARN = 300, LAG_CRIT = 1800, WRAP_WARN = 50, WRAP_CRIT = 75;

    /**
     * Tussenstand per check (vorige tellers, grootte, het beeld voor de popup) in agent_data 'dbst:<id>': de opties van een
     * check zijn daar te klein voor (VARCHAR 500).
     * @return array<string, mixed>
     */
    public static function state(array $check): array
    {
        $v = q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [(int) $check['host_id'], 'dbst:' . (int) $check['id']])->fetchColumn();
        return $v !== false ? (json_decode((string) $v, true) ?: []) : [];
    }

    private static function saveState(array $check, array $st): void
    {
        if ((int) ($check['id'] ?? 0) <= 0) {
            return;
        }
        q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [(int) $check['host_id'], 'dbst:' . (int) $check['id'], json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), now()]);
    }

    /** @var array<string, array<string, mixed>> metingen via RzMon in deze run (één per verbinding) */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function opts(array $check): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        $o['engine'] = ($o['engine'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql';
        $o['via'] = ($o['via'] ?? 'rzmon') === 'agent' ? 'agent' : 'rzmon';
        $o['where'] = ($o['where'] ?? 'local') === 'container' ? 'container' : 'local';
        $o['container'] = (string) ($o['container'] ?? '');
        $o['user'] = (string) ($o['user'] ?? '');
        $o['pass'] = (string) ($o['pass'] ?? '');
        $o['db'] = (string) ($o['db'] ?? '');
        $o['longq'] = max(10, (int) ($o['longq'] ?? 300));
        return $o;
    }

    public static function metric(array $check): string
    {
        $m = (string) ($check['metric'] ?? '');
        return array_key_exists($m, self::UNITS) ? $m : 'overview';
    }

    /** Sleutel van de verbinding: checks met dezelfde verbinding delen één meting */
    public static function key(array $o, string $target = ''): string
    {
        return substr(sha1(implode('|', [$o['engine'], $o['via'], $o['where'], $o['container'], $o['user'], md5($o['pass']), $o['db'], $o['via'] === 'rzmon' ? $target : ''])), 0, 12);
    }

    /**
     * Regels voor de agent: id <tab> db <tab> engine <tab> local|container <tab> container <tab> gebruiker <tab> wachtwoord (base64)
     * <tab> database <tab> sleutel <tab> interval. Het wachtwoord alleen als de verbinding versleuteld is ($secure).
     * @return list<string>
     */
    public static function agentLines(int $hostId, bool $secure): array
    {
        $out = [];
        foreach (q("SELECT id, options, interval_sec FROM checks WHERE host_id = ? AND type = 'mysql' AND enabled = 1", [$hostId]) as $c) {
            $o = self::opts($c);
            if ($o['via'] !== 'agent') {
                continue;
            }
            $dash = static fn(string $v) => $v === '' ? '-' : str_replace(["\t", "\n", "\r"], '', $v);
            $out[] = implode("\t", [(int) $c['id'], 'db', $o['engine'], $o['where'], $dash($o['container']), $dash($o['user']),
                $secure && $o['pass'] !== '' ? base64_encode($o['pass']) : '-', $dash($o['db']), self::key($o), max(30, (int) $c['interval_sec'])]);
        }
        return $out;
    }

    /** Meting van de agent bewaren (agent_data 'db:<sleutel>') */
    public static function fromAgent(array $host, array $in, string $now): void
    {
        if (!isset($in['db']) || !is_array($in['db'])) {
            return;
        }
        $st = db()->prepare('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)');
        foreach (array_slice($in['db'], 0, 20, true) as $key => $r) {
            if (!preg_match('/^[0-9a-f]{12}$/', (string) $key) || !is_array($r)) {
                continue;
            }
            $kv = [];
            foreach (array_slice((array) ($r['kv'] ?? []), 0, 150, true) as $k => $v) {
                if (preg_match('/^[A-Za-z_:][\w:.$-]{0,90}$/', (string) $k) && is_scalar($v)) {
                    $kv[(string) $k] = mb_substr((string) $v, 0, 500);
                }
            }
            $st->execute([$host['id'], 'db:' . $key, json_encode(['ok' => !empty($r['ok']), 'ms' => (int) ($r['ms'] ?? 0), 'err' => mb_substr((string) ($r['err'] ?? ''), 0, 300),
                'at' => (int) ($r['at'] ?? time()), 'kv' => $kv], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }

    // ------------------------------------------------------------------ meten via RzMon (PDO)

    /** @return array{ok: bool, ms: int, err: string, at: int, kv: array<string, string>} */
    private static function fetchRzmon(array $check, array $host, array $o, array &$state): array
    {
        $target = trim((string) $check['target']) ?: (string) $host['address'];
        $def = $o['engine'] === 'pgsql' ? '5432' : '3306';
        [$h, $port] = str_contains($target, ':') && !str_contains($target, '::') ? explode(':', $target, 2) + [1 => $def] : [$target, $def];
        $fail = static fn(string $e, int $ms = 0) => ['ok' => false, 'ms' => $ms, 'err' => $e, 'at' => time(), 'kv' => []];
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $h) || !ctype_digit((string) $port)) {
            return $fail(t('Ongeldig adres'));
        }
        if ($o['user'] === '') {
            return $fail(t('Vul een gebruiker in (bv. een eigen gebruiker met alleen leesrechten)'));
        }
        $key = self::key($o, "$h:$port");
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if ($o['engine'] === 'pgsql' && !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            return self::$cache[$key] = $fail(t('PHP op de RzMon-server mist pdo_pgsql (installeer php-pgsql), of meet via de agent'));
        }
        $t0 = microtime(true);
        try {
            $dsn = $o['engine'] === 'pgsql' ? "pgsql:host=$h;port=$port;dbname=" . ($o['db'] !== '' ? $o['db'] : 'postgres') . ';connect_timeout=5'
                : "mysql:host=$h;port=$port" . ($o['db'] !== '' ? ';dbname=' . $o['db'] : '');
            $pdo = new PDO($dsn, $o['user'], $o['pass'], [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            return self::$cache[$key] = $fail(mb_substr(preg_replace('/^SQLSTATE\[\w+\] \[\d+\] /', '', $e->getMessage()), 0, 200), (int) ((microtime(true) - $t0) * 1000));
        }
        $kv = [];
        $rows = static function (string $sql) use ($pdo): array {
            try {
                return $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);
            } catch (Throwable) {
                return [];   // geen recht (bv. replicatie of processlist): dat deel blijft leeg
            }
        };
        $nl = 0;
        if ($o['engine'] === 'mysql') {
            foreach ($rows('SHOW GLOBAL STATUS') as $r) {
                if (in_array($r[0], self::MY_STATUS, true)) {
                    $kv[$r[0]] = (string) $r[1];
                }
            }
            foreach ($rows('SELECT @@max_connections, VERSION(), @@read_only') as $r) {
                [$kv['v:max_connections'], $kv['v:version'], $kv['v:read_only']] = array_map('strval', $r);
            }
            foreach ($rows("SELECT TIME, IFNULL(USER, ''), IFNULL(DB, ''), LEFT(INFO, 300) FROM information_schema.PROCESSLIST WHERE COMMAND NOT IN ('Sleep', 'Daemon', 'Binlog Dump', 'Binlog Dump GTID')
                    AND INFO IS NOT NULL AND ID <> CONNECTION_ID() AND TIME >= 5 ORDER BY TIME DESC LIMIT 5") as $r) {
                $kv['longq:' . (++$nl)] = $r[0] . '|' . $r[1] . '|' . $r[2] . '|' . base64_encode((string) $r[3]);
            }
            foreach (['SHOW SLAVE STATUS', 'SHOW REPLICA STATUS'] as $sql) {
                try {
                    $r = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable) {
                    $r = [];
                }
                foreach ($r as $f => $v) {
                    if (in_array($f, self::MY_REPL, true)) {
                        $kv["repl:$f"] = (string) $v;
                    }
                }
            }
            // Grootte per database hooguit eens per half uur
            $sz = $state['sz'] ?? null;
            if (!is_array($sz) || ($sz['t'] ?? 0) < time() - 1800) {
                $sz = ['t' => time(), 's' => []];
                foreach ($rows("SELECT table_schema, COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables
                        WHERE table_schema NOT IN ('information_schema', 'performance_schema', 'sys', 'mysql') GROUP BY table_schema") as $r) {
                    $sz['s'][(string) $r[0]] = (string) $r[1];
                }
                $state['sz'] = $sz;
            }
            foreach ((array) $sz['s'] as $d => $b) {
                $kv["size:$d"] = (string) $b;
            }
        } else {
            foreach (self::PG_QUERIES as $sql) {
                foreach ($rows($sql) as $r) {
                    if ($r[0] === 'longq') {
                        $kv['longq:' . (++$nl)] = (string) $r[1];
                    } else {
                        $kv[(string) $r[0]] = (string) $r[1];
                    }
                }
            }
        }
        return self::$cache[$key] = ['ok' => $kv !== [], 'ms' => (int) round((microtime(true) - $t0) * 1000), 'err' => $kv ? '' : t('Geen antwoord op de query\'s'), 'at' => time(), 'kv' => $kv];
    }

    // ------------------------------------------------------------------ één beeld van beide databases

    /**
     * Ruwe sleutels → één beeld. Tellers (c_*) lopen op sinds de start; check() maakt er verschillen per keer van.
     * @param array<string, string> $kv
     * @return array<string, mixed>
     */
    public static function normalize(string $engine, array $kv): array
    {
        $n = static fn(string $k) => isset($kv[$k]) && is_numeric($kv[$k]) ? (float) $kv[$k] : null;
        $m = ['engine' => $engine, 'sizes' => [], 'longq' => [], 'repl' => null, 'galera' => null];
        foreach ($kv as $k => $v) {
            if (str_starts_with($k, 'size:') && is_numeric($v)) {
                $m['sizes'][substr($k, 5)] = (int) $v;
            }
            if (str_starts_with($k, 'longq:')) {
                $p = explode('|', $v, 4);
                if (count($p) === 4 && is_numeric($p[0])) {
                    $q = base64_decode($p[3], true);
                    $m['longq'][] = ['sec' => (int) $p[0], 'user' => $p[1], 'db' => $p[2], 'q' => mb_substr(preg_replace('/\s+/', ' ', $q === false ? '' : mb_convert_encoding($q, 'UTF-8', 'UTF-8')), 0, 300)];
                }
            }
        }
        usort($m['longq'], static fn($a, $b) => $b['sec'] <=> $a['sec']);
        arsort($m['sizes']);
        $m['size'] = $m['sizes'] ? array_sum($m['sizes']) : null;
        if ($engine === 'mysql') {
            $m += ['version' => $kv['v:version'] ?? null, 'uptime' => $n('Uptime'), 'conn' => $n('Threads_connected'), 'max_conn' => $n('v:max_connections'), 'active' => $n('Threads_running'),
                'c_queries' => $n('Questions'), 'c_slow' => $n('Slow_queries'), 'c_aborted' => $n('Aborted_connects'), 'c_reads' => $n('Innodb_buffer_pool_reads'),
                'c_req' => $n('Innodb_buffer_pool_read_requests'), 'c_dead' => $n('Innodb_deadlocks'), 'lock_wait' => $n('Innodb_row_lock_current_waits'),
                'c_tmp' => $n('Created_tmp_tables'), 'c_tmpdisk' => $n('Created_tmp_disk_tables'), 'read_only' => ($kv['v:read_only'] ?? '0') === '1'];
            $io = $kv['repl:Replica_IO_Running'] ?? $kv['repl:Slave_IO_Running'] ?? null;
            if ($io !== null) {
                $lag = $kv['repl:Seconds_Behind_Source'] ?? $kv['repl:Seconds_Behind_Master'] ?? null;
                $m['repl'] = ['role' => 'replica', 'io' => $io === 'Yes', 'sql' => ($kv['repl:Replica_SQL_Running'] ?? $kv['repl:Slave_SQL_Running'] ?? '') === 'Yes',
                    'lag' => is_numeric($lag) ? (int) $lag : null, 'from' => $kv['repl:Source_Host'] ?? $kv['repl:Master_Host'] ?? '',
                    'err' => trim(($kv['repl:Last_IO_Error'] ?? '') . ' ' . ($kv['repl:Last_SQL_Error'] ?? ''))];
            }
            // Galera alleen als het cluster echt aan staat (zonder Galera meldt MariaDB "Disconnected" met grootte 0)
            if (isset($kv['wsrep_cluster_status']) && ((int) ($kv['wsrep_cluster_size'] ?? 0) > 0 || $kv['wsrep_cluster_status'] === 'non-Primary')) {
                $m['galera'] = ['status' => $kv['wsrep_cluster_status'], 'size' => (int) ($kv['wsrep_cluster_size'] ?? 0), 'state' => $kv['wsrep_local_state_comment'] ?? ''];
            }
        } else {
            $hit = $n('blks_hit');
            $read = $n('blks_read');
            $m += ['version' => $kv['version'] ?? null, 'uptime' => $n('uptime'), 'conn' => $n('conn'), 'max_conn' => $n('max_conn'), 'active' => $n('active'),
                'c_queries' => $n('xact'), 'c_reads' => $read, 'c_req' => $hit !== null && $read !== null ? $hit + $read : null, 'c_dead' => $n('deadlocks'),
                'c_tmpdisk' => $n('temp_files'), 'lock_wait' => $n('lock_wait'), 'idle_tx' => $n('idle_tx'), 'c_arch' => $n('arch_failed'),
                'wrap_pct' => $n('wrap_age') !== null ? round($n('wrap_age') / 2147483648 * 100, 1) : null];
            if (($kv['recovery'] ?? '0') === '1') {
                $lag = $n('repl_lag');
                $m['repl'] = ['role' => 'replica', 'io' => true, 'sql' => true, 'lag' => $lag !== null && $lag >= 0 ? (int) $lag : null, 'from' => '', 'err' => ''];
            } elseif ((int) ($n('replicas') ?? 0) > 0) {
                $m['repl'] = ['role' => 'primary', 'replicas' => (int) $n('replicas')];
            }
        }
        $m['conn_pct'] = $m['conn'] !== null && $m['max_conn'] ? round($m['conn'] / $m['max_conn'] * 100, 1) : null;
        return $m;
    }

    // ------------------------------------------------------------------ de check

    public static function check(array $check, array $host): array
    {
        $o = self::opts($check);
        $metric = self::metric($check);
        if (isset(self::ONLY[$metric]) && self::ONLY[$metric] !== $o['engine']) {
            return Checks::result(STATUS_UNKNOWN, null, t('Dit kan alleen bij {e}', ['e' => self::ONLY[$metric] === 'pgsql' ? 'PostgreSQL' : 'MariaDB/MySQL']));
        }
        $state = self::state($check);
        if ($o['via'] === 'agent') {
            if ($host['mode'] !== 'agent') {
                return Checks::result(STATUS_UNKNOWN, null, t('Meten via de agent kan alleen op een server met de agent; kies "RzMon maakt verbinding"'));
            }
            if ($stale = Checks::agentStale($host)) {
                return $stale;
            }
            $raw = Checks::agentMetric($host, 'db:' . self::key($o));
            if ($raw === null) {
                return Checks::result(STATUS_UNKNOWN, null, t('De agent meldt deze database nog niet (agent 1.81.0 of nieuwer; binnen ~2 minuten na het toevoegen)'));
            }
            $r = json_decode($raw, true) ?: [];
            if (($r['at'] ?? 0) < time() - max(600, 3 * (int) $check['interval_sec'])) {
                return Checks::result(STATUS_UNKNOWN, null, t('De laatste meting van de agent is te oud'));
            }
        } else {
            $r = self::fetchRzmon($check, $host, $o, $state);
        }
        $name = $o['engine'] === 'pgsql' ? 'PostgreSQL' : 'MariaDB';
        if (empty($r['ok'])) {
            $err = (string) ($r['err'] ?? '');
            if ($o['via'] === 'agent' && $o['pass'] !== '' && empty($host['enc'])) {
                $err .= ' · ' . t('een wachtwoord stuurt RzMon alleen naar een versleutelde agent');
            }
            // Het overzicht meldt een storing; losse waarden zijn dan onbekend (geen dubbele melding)
            return Checks::result($metric === 'overview' ? STATUS_CRIT : STATUS_UNKNOWN, null, t('{db}: geen verbinding', ['db' => $name]) . ($err !== '' ? ": $err" : ''));
        }
        $m = self::normalize($o['engine'], (array) ($r['kv'] ?? []));
        // Verschil met de vorige keer (tellers lopen op sinds de start; een kortere uptime = herstart)
        $prev = is_array($state['prev'] ?? null) ? $state['prev'] : null;
        $restart = $prev && $m['uptime'] !== null && isset($prev['up']) && $m['uptime'] < $prev['up'];
        $dt = $prev && !$restart ? max(1, time() - (int) $prev['t']) : null;
        $d = static fn(string $k) => $dt !== null && isset($prev[$k]) && $m[$k] !== null ? max(0.0, $m[$k] - (float) $prev[$k]) : null;
        $dq = $d('c_queries');
        $dRead = $d('c_reads');
        $dReq = $d('c_req');
        $calc = [
            'qps' => $dq !== null ? round($dq / $dt, 1) : null,
            'slow' => ($s = $d('c_slow')) !== null ? round($s / $dt * 60, 2) : null,
            'slow_n' => $d('c_slow'),
            'deadlocks' => $d('c_dead'),
            'aborted' => $d('c_aborted'),
            'arch' => $d('c_arch'),
            'cache' => $dReq !== null && $dReq >= 1000 ? round((1 - $dRead / $dReq) * 100, 2)
                : ($m['c_req'] ? round((1 - (float) $m['c_reads'] / $m['c_req']) * 100, 2) : null),
            'tmpdisk' => ($t = $d('c_tmp')) !== null && $t >= 20 ? round((float) $d('c_tmpdisk') / $t * 100, 1) : null,
        ];
        $state['prev'] = ['t' => time(), 'up' => $m['uptime']] + array_filter(array_intersect_key($m, array_flip(['c_queries', 'c_slow', 'c_dead', 'c_aborted', 'c_arch', 'c_reads', 'c_req', 'c_tmp', 'c_tmpdisk'])), static fn($v) => $v !== null);
        // Het beeld van nu voor de popup (zonder de tellers)
        $state['m'] = ['m' => array_diff_key($m, array_flip(['c_queries', 'c_slow', 'c_dead', 'c_aborted', 'c_arch', 'c_reads', 'c_req', 'c_tmp', 'c_tmpdisk'])), 'calc' => $calc,
            'ms' => (int) ($r['ms'] ?? 0), 'at' => time()];
        self::saveState($check, $state);
        $res = $metric === 'overview' ? self::overview($check, $o, $m, $calc, (int) ($r['ms'] ?? 0), $name) : self::single($check, $o, $metric, $m, $calc);
        if ($restart && $metric === 'overview') {
            $res['info'] = t('{db} is herstart ({d} geleden)', ['db' => $name, 'd' => SnmpMonitor::duration((int) $m['uptime'])]);
        }
        return $res;
    }

    /** Het overzicht: de ergste van alle onderdelen; de meetwaarde is de tijd van de meting (ms) */
    private static function overview(array $check, array $o, array $m, array $calc, int $ms, string $name): array
    {
        $st = Checks::threshold((float) $ms, $check);
        $bad = [];
        $up = static function (int $s, string $why) use (&$st, &$bad): void {
            $st = max($st, $s);
            $bad[] = $why;
        };
        if ($m['conn_pct'] !== null && $m['conn_pct'] >= self::CONN_WARN) {
            $up($m['conn_pct'] >= self::CONN_CRIT ? STATUS_CRIT : STATUS_WARN, t('{p}% van de verbindingen in gebruik', ['p' => I18n::dec((string) $m['conn_pct'])]));
        }
        $r = $m['repl'];
        if ($r && $r['role'] === 'replica') {
            if (!$r['io'] || !$r['sql']) {
                $up(STATUS_CRIT, t('Replicatie staat stil') . ($r['err'] !== '' ? ': ' . mb_substr($r['err'], 0, 120) : ''));
            } elseif ($r['lag'] !== null && $r['lag'] >= self::LAG_WARN) {
                $up($r['lag'] >= self::LAG_CRIT ? STATUS_CRIT : STATUS_WARN, t('Replica loopt {d} achter', ['d' => SnmpMonitor::duration($r['lag'])]));
            }
        }
        if ($m['galera'] && $m['galera']['status'] !== 'Primary') {
            $up(STATUS_CRIT, t('Galera-cluster: {s}', ['s' => $m['galera']['status']]));
        }
        $lq = $m['longq'][0] ?? null;
        if ($lq && $lq['sec'] >= $o['longq']) {
            $up(STATUS_WARN, t('Query loopt al {d}', ['d' => SnmpMonitor::duration($lq['sec'])]) . ($lq['user'] !== '' ? " ({$lq['user']}" . ($lq['db'] !== '' ? "@{$lq['db']}" : '') . ')' : ''));
        }
        if (!empty($m['idle_tx'])) {
            $up(STATUS_WARN, t('{n} verbinding(en) al > 5 min "idle in transaction" (houdt locks vast)', ['n' => (int) $m['idle_tx']]));
        }
        if (($m['wrap_pct'] ?? null) !== null && $m['wrap_pct'] >= self::WRAP_WARN) {
            $up($m['wrap_pct'] >= self::WRAP_CRIT ? STATUS_CRIT : STATUS_WARN, t('Transactie-ID {p}% op weg naar wraparound: VACUUM nodig', ['p' => I18n::dec((string) $m['wrap_pct'])]));
        }
        if (!empty($calc['arch'])) {
            $up(STATUS_WARN, t('WAL-archivering mislukt ({n}× sinds de vorige check)', ['n' => (int) $calc['arch']]));
        }
        $parts = [($m['engine'] === 'pgsql' ? 'PostgreSQL' : (stripos((string) $m['version'], 'mariadb') !== false ? 'MariaDB' : 'MySQL')) . ($m['version'] ? ' ' . preg_replace('/[ (-].*$/', '', (string) $m['version']) : '')];
        if ($m['conn'] !== null) {
            $parts[] = t('{n} van {m} verbindingen', ['n' => (int) $m['conn'], 'm' => (int) $m['max_conn']]);
        }
        if ($calc['qps'] !== null) {
            $parts[] = t('{n} query\'s/s', ['n' => I18n::dec((string) $calc['qps'])]);
        }
        if ($m['repl'] && $m['repl']['role'] === 'replica' && $m['repl']['io'] && $m['repl']['sql'] && $m['repl']['lag'] !== null && $m['repl']['lag'] < self::LAG_WARN) {
            $parts[] = t('replica {s} s achter', ['s' => $m['repl']['lag']]);
        } elseif ($m['repl'] && $m['repl']['role'] === 'primary') {
            $parts[] = t('{n} replica\'s', ['n' => $m['repl']['replicas']]);
        }
        if ($m['size'] !== null) {
            $parts[] = self::bytes($m['size']);
        }
        if (!empty($calc['slow_n'])) {
            $parts[] = t('{n} trage query\'s', ['n' => (int) $calc['slow_n']]);
        }
        if (!empty($calc['deadlocks'])) {
            $parts[] = t('{n} deadlocks', ['n' => (int) $calc['deadlocks']]);
        }
        if ($m['uptime'] !== null) {
            $parts[] = t('draait {d}', ['d' => SnmpMonitor::duration((int) $m['uptime'])]);
        }
        $msg = ($bad ? implode(' · ', $bad) . ' — ' : '') . implode(' · ', $parts);
        return Checks::result($st, (float) $ms, $msg);
    }

    /** Eén waarde met de drempels van de check */
    private static function single(array $check, array $o, string $metric, array $m, array $calc): array
    {
        $lq = $m['longq'][0] ?? null;
        $size = $o['db'] !== '' && isset($m['sizes'][$o['db']]) ? $m['sizes'][$o['db']] : $m['size'];
        [$v, $msg] = match ($metric) {
            'conn' => [$m['conn_pct'], t('{n} van {m} verbindingen', ['n' => (int) $m['conn'], 'm' => (int) $m['max_conn']])],
            'active' => [$m['active'], t('{n} query\'s tegelijk bezig', ['n' => (int) $m['active']])],
            'qps' => [$calc['qps'], $calc['qps'] === null ? t('Eerste meting; vanaf de volgende het aantal per seconde') : t('{n} query\'s per seconde', ['n' => I18n::dec((string) $calc['qps'])])],
            'slow' => [$calc['slow'], $calc['slow'] === null ? t('Eerste meting') : t('{n} trage query\'s sinds de vorige check', ['n' => (int) $calc['slow_n']])],
            'cache' => [$calc['cache'], $calc['cache'] === null ? t('Nog te weinig gelezen') : t('{p}% uit het geheugen gelezen', ['p' => I18n::dec((string) $calc['cache'])])],
            'repl' => [$m['repl'] && $m['repl']['role'] === 'replica' ? $m['repl']['lag'] : null, ''],
            'size' => [$size !== null ? round($size / 1e9, 3) : null, $size !== null ? ($o['db'] !== '' && isset($m['sizes'][$o['db']]) ? $o['db'] . ': ' : t('Alle databases') . ': ') . self::bytes($size) : t('Grootte onbekend (geen rechten?)')],
            'longq' => [$lq ? (float) $lq['sec'] : 0.0, $lq ? t('Langste query: {d}', ['d' => SnmpMonitor::duration($lq['sec'])]) . ' · ' . mb_substr($lq['q'], 0, 120) : t('Geen lange query\'s')],
            'deadlocks' => [$calc['deadlocks'], $calc['deadlocks'] === null ? t('Eerste meting') : t('{n} deadlocks sinds de vorige check', ['n' => (int) $calc['deadlocks']])],
            'tmpdisk' => [$calc['tmpdisk'], $calc['tmpdisk'] === null ? t('Nog te weinig tijdelijke tabellen') : t('{p}% van de tijdelijke tabellen op schijf', ['p' => I18n::dec((string) $calc['tmpdisk'])])],
            'wrap' => [$m['wrap_pct'] ?? null, t('Transactie-ID {p}% op weg naar wraparound', ['p' => I18n::dec((string) ($m['wrap_pct'] ?? 0))])],
            'idle' => [$m['idle_tx'] ?? null, t('{n} verbinding(en) al > 5 min "idle in transaction"', ['n' => (int) ($m['idle_tx'] ?? 0)])],
            default => [null, ''],
        };
        if ($metric === 'repl') {
            $r = $m['repl'];
            if (!$r) {
                return Checks::result(STATUS_OK, null, t('Geen replicatie op deze database'));
            }
            if ($r['role'] === 'primary') {
                return Checks::result(STATUS_OK, null, t('Primair, {n} replica\'s verbonden', ['n' => $r['replicas']]));
            }
            if (!$r['io'] || !$r['sql']) {
                return Checks::result(STATUS_CRIT, null, t('Replicatie staat stil') . ($r['err'] !== '' ? ': ' . mb_substr($r['err'], 0, 200) : ''));
            }
            $msg = $r['lag'] === null ? t('Replica, vertraging onbekend') : t('Replica loopt {s} s achter', ['s' => $r['lag']]) . ($r['from'] !== '' ? ' (' . t('bron {h}', ['h' => $r['from']]) . ')' : '');
        }
        if ($v === null) {
            return Checks::result(STATUS_OK, null, $msg);
        }
        $low = $metric === 'cache';
        if ($low) {
            $w = $check['warn_threshold'];
            $c = $check['crit_threshold'];
            $st = $c !== null && $v <= (float) $c ? STATUS_CRIT : ($w !== null && $v <= (float) $w ? STATUS_WARN : STATUS_OK);
        } else {
            $st = Checks::threshold((float) $v, $check);
        }
        return Checks::result($st, (float) $v, $msg);
    }

    private static function bytes(float|int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1000 && $i < 4) {
            $b /= 1000;
            $i++;
        }
        return I18n::dec((string) round($b, $b < 10 && $i ? 1 : 0)) . ' ' . $u[$i];
    }
}
