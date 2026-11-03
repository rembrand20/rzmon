<?php
declare(strict_types=1);

/**
 * 1.154.0: het tweede dashboard, "Command center". Alles in één oogopslag: beveiliging (SSH-logins, mislukte pogingen,
 * blokkades, sudo/su, webaanvallen, uitgaand), de toppers (grootste SSH- en IDS-blokkade, grootste aanvaller, meest
 * aangevallen server, land), wie er inlogt, en de monitoring (hoeveel checks er liepen, welke het vaakst fout ging en
 * welke de meeste meldingen gaf). Steeds vergeleken met de periode ervoor. Alleen wat RzMon al bewaart.
 */
final class CommandCenter
{
    public const PERIODS = ['today', '7d', '30d', '90d'];

    /** @return array{0: string, 1: string, 2: int} begin, begin van de vorige periode, lengte in seconden */
    private static function range(string $p): array
    {
        if ($p === 'today') {
            $from = strtotime('today');
            return [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $from - 86400), max(60, time() - $from)];
        }
        $len = (int) $p * 86400;
        return [date('Y-m-d H:i:s', time() - $len), date('Y-m-d H:i:s', time() - 2 * $len), $len];
    }

    public static function data(string $period, string $seen = ''): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'today';
        [$from, $prev] = self::range($period);
        $out = ['period' => $period, 'from' => $from, 'sec' => self::security($from, $prev), 'top' => self::toppers($from), 'logins' => self::logins($from),
            'mon' => self::monitoring($from, $prev, $period), 'motion' => max(0, min(2, (int) setting('af_motion', '2')))];
        if ($seen !== '' && strtotime($seen) !== false && strtotime($seen) > time() - 90 * 86400) {
            $out['seen'] = self::since(date('Y-m-d H:i:s', (int) strtotime($seen)));
        }
        return $out;
    }

    /** Een getal in deze periode en in de periode ervoor (voor "↑ 38 %") */
    private static function pair(string $sql, string $from, string $prev, array $params = []): array
    {
        $now = (int) q($sql, array_merge([$from, now()], $params))->fetchColumn();
        $before = (int) q($sql, array_merge([$prev, $from], $params))->fetchColumn();
        return ['n' => $now, 'prev' => $before];
    }

    private static function security(string $from, string $prev): array
    {
        $ssh = "'" . implode("','", Ids::SSH_TYPES) . "'";
        $ip = "JSON_UNQUOTE(JSON_EXTRACT(detail, '$.ip'))";
        $login = self::pair("SELECT COUNT(*) FROM access_log WHERE kind = 'auth' AND type = 'ssh' AND created_at >= ? AND created_at < ?", $from, $prev);
        $login['users'] = (int) q("SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(detail, '$.user'))) FROM access_log WHERE kind = 'auth' AND type = 'ssh' AND created_at >= ?", [$from])->fetchColumn();
        // Nieuwe IP-adressen: vandaag/deze periode ingelogd, de 90 dagen ervoor niet
        $login['new_ips'] = (int) q("SELECT COUNT(DISTINCT $ip) FROM access_log a WHERE kind = 'auth' AND type = 'ssh' AND created_at >= ? AND $ip IS NOT NULL AND $ip <> ''
            AND NOT EXISTS (SELECT 1 FROM access_log b WHERE b.kind = 'auth' AND b.type = 'ssh' AND b.created_at < ? AND b.created_at >= ? - INTERVAL 90 DAY
                            AND JSON_UNQUOTE(JSON_EXTRACT(b.detail, '$.ip')) = JSON_UNQUOTE(JSON_EXTRACT(a.detail, '$.ip')))", [$from, $from, $from])->fetchColumn();
        $fail = self::pair("SELECT COALESCE(SUM(COALESCE(JSON_EXTRACT(detail, '$.n'), 1)), 0) FROM access_log WHERE kind = 'authfail' AND type = 'ssh' AND created_at >= ? AND created_at < ?", $from, $prev);
        $fail['ips'] = (int) q("SELECT COUNT(DISTINCT $ip) FROM access_log WHERE kind = 'authfail' AND type = 'ssh' AND created_at >= ?", [$from])->fetchColumn();
        $sshBlk = self::pair("SELECT COUNT(*) FROM ids_blocks b WHERE b.created_at >= ? AND b.created_at < ? AND b.ip IN (SELECT ip FROM ids_incidents WHERE type IN ($ssh) AND ip IS NOT NULL)", $from, $prev);
        $sshBlk['active'] = (int) q("SELECT COUNT(*) FROM ids_blocks b WHERE b.removed_at IS NULL AND (b.until IS NULL OR b.until > NOW()) AND b.ip IN (SELECT ip FROM ids_incidents WHERE type IN ($ssh) AND ip IS NOT NULL)")->fetchColumn();
        $blk = self::pair('SELECT COUNT(*) FROM ids_blocks WHERE created_at >= ? AND created_at < ?', $from, $prev);
        $blk['active'] = (int) q('SELECT COUNT(*) FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchColumn();
        $blk['hosts'] = (int) q('SELECT COUNT(DISTINCT host_id) FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchColumn();
        $stopped = (int) q('SELECT COALESCE(SUM(hits), 0) FROM ids_blocks WHERE created_at >= ? OR removed_at IS NULL', [$from])->fetchColumn();
        $sudo = self::pair("SELECT COUNT(*) FROM access_log WHERE kind = 'auth' AND type IN ('sudo', 'su') AND created_at >= ? AND created_at < ?", $from, $prev);
        $sudo['users'] = (int) q("SELECT COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(detail, '$.user'))) FROM access_log WHERE kind = 'auth' AND type IN ('sudo', 'su') AND created_at >= ?", [$from])->fetchColumn();
        $sudo['fail'] = (int) q("SELECT COUNT(*) FROM access_log WHERE kind = 'authfail' AND type IN ('sudo', 'su') AND created_at >= ?", [$from])->fetchColumn();
        $web = self::pair("SELECT COALESCE(SUM(hits), 0) FROM ids_incidents WHERE type IN ('web_attack', 'web_cve', 'web_payload') AND last_seen >= ? AND last_seen < ?", $from, $prev);
        $web['ips'] = (int) q("SELECT COUNT(DISTINCT ip) FROM ids_incidents WHERE type IN ('web_attack', 'web_cve', 'web_payload') AND last_seen >= ?", [$from])->fetchColumn();
        $out = self::pair("SELECT COUNT(*) FROM ids_incidents WHERE dir = 'o' AND last_seen >= ? AND last_seen < ?", $from, $prev);
        $out['last'] = (string) (q("SELECT title FROM ids_incidents WHERE dir = 'o' AND last_seen >= ? ORDER BY last_seen DESC LIMIT 1", [$from])->fetchColumn() ?: '');
        $inc = self::pair('SELECT COUNT(*) FROM ids_incidents WHERE created_at >= ? AND created_at < ?', $from, $prev);
        $inc['crit'] = (int) q('SELECT COUNT(*) FROM ids_incidents WHERE created_at >= ? AND severity >= 2', [$from])->fetchColumn();
        return ['ssh_login' => $login, 'ssh_fail' => $fail, 'ssh_blk' => $sshBlk, 'ids_blk' => $blk, 'stopped' => $stopped, 'sudo' => $sudo, 'web' => $web, 'out' => $out, 'ids_inc' => $inc];
    }

    /** De toppers: grootste SSH-blokkade, grootste IDS-blokkade, grootste aanvaller, meest aangevallen server en land */
    private static function toppers(string $from): array
    {
        $ssh = "'" . implode("','", Ids::SSH_TYPES) . "'";
        $block = static function (string $where) {
            $b = q("SELECT b.ip, b.hits, b.created_at, b.reason, h.name AS host FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                     WHERE b.removed_at IS NULL AND (b.until IS NULL OR b.until > NOW()) $where ORDER BY b.hits DESC, b.created_at DESC LIMIT 1")->fetch();
            if (!$b) {
                return null;
            }
            $on = array_column(q('SELECT DISTINCT h.name FROM ids_blocks b JOIN hosts h ON h.id = b.host_id WHERE b.ip = ? AND b.removed_at IS NULL AND (b.until IS NULL OR b.until > NOW())', [$b['ip']])->fetchAll(), 'name');
            $hit = array_column(q('SELECT DISTINCT h.name FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.ip = ?', [$b['ip']])->fetchAll(), 'name');
            $users = array_column(q("SELECT username, COUNT(*) AS n FROM ssh_events WHERE ip = ? AND ok = 0 AND username <> '' GROUP BY username ORDER BY n DESC LIMIT 3", [$b['ip']])->fetchAll(), 'username');
            $hits = (int) q('SELECT COALESCE(SUM(hits), 0) FROM ids_blocks WHERE ip = ? AND removed_at IS NULL', [$b['ip']])->fetchColumn();
            return ['ip' => $b['ip'], 'cc' => Ids::internalIp((string) $b['ip']) ? 'LAN' : (IpIntel::country((string) $b['ip']) ?? ((string) q("SELECT cc FROM ids_incidents WHERE ip = ? AND cc IS NOT NULL AND cc <> '' LIMIT 1", [$b['ip']])->fetchColumn() ?: '??')), 'hits' => $hits, 'at' => $b['created_at'],
                'reason' => (string) $b['reason'], 'on' => $on, 'not_on' => array_values(array_diff($hit, $on)), 'users' => $users];
        };
        $att = q("SELECT ip, MAX(cc) AS cc, SUM(hits) AS hits, COUNT(DISTINCT host_id) AS hosts, MAX(severity) AS sev,
                         SUBSTRING_INDEX(GROUP_CONCAT(title ORDER BY hits DESC SEPARATOR '\n'), '\n', 1) AS title,
                         SUBSTRING_INDEX(GROUP_CONCAT(type ORDER BY hits DESC), ',', 1) AS type
                    FROM ids_incidents WHERE ip IS NOT NULL AND ip <> '' AND type NOT IN ('chain', 'anomaly') AND last_seen >= ?
                   GROUP BY ip ORDER BY hits DESC LIMIT 1", [$from])->fetch() ?: null;
        if ($att) {
            $att = ['ip' => $att['ip'], 'cc' => Ids::internalIp((string) $att['ip']) ? 'LAN' : ((string) $att['cc'] ?: '??'), 'hits' => (int) $att['hits'], 'hosts' => (int) $att['hosts'],
                'sev' => (int) $att['sev'], 'title' => (string) $att['title'], 'cat' => Ids::flowCat((string) $att['type'], ''),
                'blocked' => (bool) q('SELECT 1 FROM ids_blocks WHERE ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW()) LIMIT 1', [$att['ip']])->fetchColumn()];
        }
        $host = q("SELECT h.id, h.name, SUM(i.hits) AS hits, COUNT(DISTINCT i.ip) AS ips, GROUP_CONCAT(DISTINCT i.service) AS svc
                     FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.ip IS NOT NULL AND i.ip <> '' AND i.type NOT IN ('chain', 'anomaly') AND i.last_seen >= ?
                    GROUP BY h.id, h.name ORDER BY hits DESC LIMIT 1", [$from])->fetch() ?: null;
        if ($host) {
            $host = ['id' => (int) $host['id'], 'name' => $host['name'], 'hits' => (int) $host['hits'], 'ips' => (int) $host['ips'],
                'svc' => array_values(array_filter(explode(',', (string) $host['svc']))), 'blocks' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE host_id = ? AND removed_at IS NULL', [$host['id']])->fetchColumn()];
        }
        $cc = [];
        $tot = 0;
        foreach (q("SELECT ip, cc, SUM(hits) AS hits FROM ids_incidents WHERE ip IS NOT NULL AND ip <> '' AND type NOT IN ('chain', 'anomaly') AND last_seen >= ? GROUP BY ip, cc", [$from]) as $r) {
            $c = Ids::internalIp((string) $r['ip']) ? 'LAN' : ((string) $r['cc'] ?: '??');
            $cc[$c] ??= ['cc' => $c, 'hits' => 0, 'ips' => 0];
            $cc[$c]['hits'] += (int) $r['hits'];
            $cc[$c]['ips']++;
            $tot += (int) $r['hits'];
        }
        usort($cc, static fn($a, $b) => $b['hits'] <=> $a['hits']);
        $cc = array_map(static fn($c) => $c + ['pct' => $tot ? (int) round($c['hits'] * 100 / $tot) : 0], array_slice($cc, 0, 4));
        return ['ssh_block' => $block("AND b.ip IN (SELECT ip FROM ids_incidents WHERE type IN ($ssh) AND ip IS NOT NULL)"), 'ids_block' => $block(''),
            'attacker' => $att, 'host' => $host, 'countries' => $cc];
    }

    /** Wie er inlogt: de nieuwste SSH-, console-, sudo- en su-regels (gelukt), met "nieuw IP" */
    private static function logins(string $from): array
    {
        $rows = q("SELECT a.created_at, a.type, a.detail, h.name AS host FROM access_log a JOIN hosts h ON h.id = a.host_id
                    WHERE a.kind = 'auth' AND a.created_at >= ? ORDER BY a.created_at DESC LIMIT 40", [$from])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r['detail'], true) ?: [];
            if (AuthEvents::rowIgnored(['detail' => $r['detail']] + $r)) {
                continue;
            }
            $ip = (string) ($d['ip'] ?? '');
            $new = $r['type'] === 'ssh' && $ip !== '' && !q("SELECT 1 FROM access_log WHERE kind = 'auth' AND type = 'ssh' AND created_at < ? AND created_at >= ? - INTERVAL 90 DAY
                AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.ip')) = ? LIMIT 1", [$r['created_at'], $r['created_at'], $ip])->fetchColumn();
            $out[] = ['at' => $r['created_at'], 'type' => $r['type'], 'host' => $r['host'], 'user' => (string) ($d['user'] ?? ($d['users'][0] ?? '?')), 'ip' => $ip,
                'm' => (string) ($d['m'] ?? $d['method'] ?? ''), 'as' => (string) ($d['as'] ?? ''), 'cmd' => mb_substr((string) ($d['cmd'] ?? ''), 0, 80),
                'cc' => (string) ($d['cc'] ?? ''), 'new' => $new];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    /**
     * Monitoring: hoeveel checks er actief zijn, hoe vaak ze liepen, hoe vaak het fout ging (warning of critical),
     * welke check het vaakst liep, het vaakst fout ging en de meeste meldingen gaf. Uit de uurtotalen (results_hourly)
     * plus de metingen van het lopende uur.
     */
    private static function monitoring(string $from, string $prev, string $period): array
    {
        $hour = date('Y-m-d H:00:00');
        $active = (int) q('SELECT COUNT(*) FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND h.enabled = 1')->fetchColumn();
        $types = q('SELECT c.type, COUNT(*) AS n FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND h.enabled = 1 GROUP BY c.type ORDER BY n DESC')->fetchAll();
        // Per check: aantal keer gelopen en fout (uurtotalen vanaf het begin van de periode + het lopende uur)
        $per = [];
        $add = static function (array $rows) use (&$per) {
            foreach ($rows as $r) {
                $k = (int) $r['check_id'];
                $per[$k] ??= ['n' => 0, 'fail' => 0, 'crit' => 0];
                $per[$k]['n'] += (int) $r['n'];
                $per[$k]['fail'] += (int) $r['f'];
                $per[$k]['crit'] += (int) $r['c'];
            }
        };
        $add(q('SELECT check_id, SUM(n) AS n, SUM(n_warn + n_crit) AS f, SUM(n_crit) AS c FROM results_hourly WHERE hour >= ? AND hour < ? GROUP BY check_id',
            [date('Y-m-d H:00:00', (int) strtotime($from)), $hour])->fetchAll());
        $add(q('SELECT r.check_id, COUNT(*) AS n, SUM(r.status IN (1, 2)) AS f, SUM(r.status = 2) AS c FROM checks c JOIN results r ON r.check_id = c.id AND r.created_at >= ? GROUP BY r.check_id',
            [max($hour, $from)])->fetchAll());
        $runs = array_sum(array_column($per, 'n'));
        $fails = array_sum(array_column($per, 'fail'));
        $runsPrev = (int) q('SELECT COALESCE(SUM(n), 0) FROM results_hourly WHERE hour >= ? AND hour < ?', [date('Y-m-d H:00:00', (int) strtotime($prev)), date('Y-m-d H:00:00', (int) strtotime($from))])->fetchColumn();
        $failsPrev = (int) q('SELECT COALESCE(SUM(n_warn + n_crit), 0) FROM results_hourly WHERE hour >= ? AND hour < ?', [date('Y-m-d H:00:00', (int) strtotime($prev)), date('Y-m-d H:00:00', (int) strtotime($from))])->fetchColumn();
        $info = [];
        if ($per) {
            $ids = array_keys($per);
            foreach (q('SELECT c.id, c.name, c.type, c.status, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id IN (' . implode(',', array_map('intval', $ids)) . ')') as $c) {
                $info[(int) $c['id']] = ['id' => (int) $c['id'], 'name' => $c['name'], 'type' => $c['type'], 'host' => $c['host'], 'status' => (int) $c['status']];
            }
        }
        $rank = static function (string $by, int $n) use ($per, $info) {
            $list = array_filter($per, static fn($x, $k) => isset($info[$k]) && $x[$by] > 0, ARRAY_FILTER_USE_BOTH);
            uasort($list, static fn($a, $b) => $b[$by] <=> $a[$by]);
            $out = [];
            foreach (array_slice($list, 0, $n, true) as $k => $x) {
                $out[] = $info[$k] + $x + ['pct' => $x['n'] ? round($x['fail'] * 100 / $x['n'], 1) : 0];
            }
            return $out;
        };
        // Meldingen (uitgestuurd naar je kanalen) per check
        $alerts = self::pair('SELECT COUNT(*) FROM alerts WHERE check_id IS NOT NULL AND created_at >= ? AND created_at < ?', $from, $prev);
        $fired = [];
        foreach (q('SELECT a.check_id, COUNT(*) AS n, SUM(a.status = 2) AS crit, MAX(a.created_at) AS last, c.name, h.name AS host FROM alerts a
                      JOIN checks c ON c.id = a.check_id JOIN hosts h ON h.id = c.host_id WHERE a.created_at >= ? GROUP BY a.check_id, c.name, h.name ORDER BY n DESC LIMIT 5', [$from]) as $r) {
            $fired[] = ['id' => (int) $r['check_id'], 'name' => $r['name'], 'host' => $r['host'], 'n' => (int) $r['n'], 'crit' => (int) $r['crit'], 'last' => $r['last']];
        }
        // Per dag (of per uur bij vandaag): gelopen en fout, voor het lijntje
        $fmt = $period === 'today' ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        $series = [];
        foreach (q("SELECT DATE_FORMAT(hour, '$fmt') AS k, SUM(n) AS n, SUM(n_warn + n_crit) AS f FROM results_hourly WHERE hour >= ? GROUP BY k ORDER BY k", [date('Y-m-d H:00:00', (int) strtotime($from))]) as $r) {
            $series[] = ['k' => $r['k'], 'n' => (int) $r['n'], 'f' => (int) $r['f']];
        }
        return ['active' => $active, 'types' => array_map(static fn($t) => ['type' => $t['type'], 'n' => (int) $t['n']], $types),
            'runs' => ['n' => $runs, 'prev' => $runsPrev], 'fails' => ['n' => $fails, 'prev' => $failsPrev], 'ok_pct' => $runs ? round(100 - $fails * 100 / $runs, 2) : null,
            'checks_ran' => count($per), 'most_run' => $rank('n', 1)[0] ?? null, 'most_failed' => $rank('fail', 5), 'alerts' => $alerts, 'fired' => $fired, 'series' => $series];
    }

    /** "Sinds je laatste bezoek": nieuwe kritieke meldingen en incidenten, blokkades en logins */
    private static function since(string $seen): array
    {
        return ['at' => $seen,
            'crit' => (int) q('SELECT COUNT(*) FROM alerts WHERE status = 2 AND created_at >= ?', [$seen])->fetchColumn()
                + (int) q('SELECT COUNT(*) FROM ids_incidents WHERE severity >= 2 AND created_at >= ?', [$seen])->fetchColumn(),
            'alerts' => (int) q('SELECT COUNT(*) FROM alerts WHERE created_at >= ?', [$seen])->fetchColumn(),
            'blocks' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE created_at >= ?', [$seen])->fetchColumn(),
            'logins' => (int) q("SELECT COUNT(*) FROM access_log WHERE kind = 'auth' AND type = 'ssh' AND created_at >= ?", [$seen])->fetchColumn()];
    }
}
