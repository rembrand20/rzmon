<?php

declare(strict_types=1);

/**
 * 1.264.0 (agent 1.102.0): statistieken bovenaan de pagina Reverse proxy. De agent telt in het proxylog (rzsense.log of
 * Nginx Proxy Manager) per site de verzoeken per statuscode en slechte bots, de aangevallen paden (zonder querystring) en
 * nieuwe unieke bezoekers van vandaag. Alleen tellingen; geen logregels, geen querystrings. Daarbij komen de cijfers die
 * RzSense al had: WAF, virtual patching, IDS-blokkades, ClamAV en Suricata.
 *
 * Waarom een verzoek is tegengehouden:
 *   429 = rate limit · 444 = geen bekende hostnaam · 403 met een slechte bot = bots · 403 op een pad dat alleen vanaf je
 *   netwerk mag = alleen-LAN · 403 op een dichtgezet pad = virtual patching · de WAF (uit zijn eigen meldingen) · de rest
 *   van de 403 = overig (de app zelf of een andere regel).
 */
final class ProxyStats
{
    public const PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30];
    private const COLS = ['total', 's2', 's3', 's4', 's403', 's404', 's429', 's444', 's5', 'bot'];

    public static function fromAgent(array $host, array $stat): void
    {
        $hid = (int) $host['id'];
        $hour = date('Y-m-d H:00:00');
        $day = date('Y-m-d');
        $site = static fn($s) => mb_substr(preg_replace('/[^A-Za-z0-9._*:()\- -]/', '', (string) $s) ?: '-', 0, 190);
        $n = 0;
        foreach ((array) ($stat['t'] ?? []) as $s => $v) {
            if (!is_array($v) || ++$n > 80) {
                continue;
            }
            $vals = array_map(static fn($i) => max(0, min(10000000, (int) ($v[$i] ?? 0))), range(0, 9));
            if ($vals[0] === 0) {
                continue;
            }
            q('INSERT INTO proxy_traffic (host_id, hour, site, ' . implode(', ', self::COLS) . ') VALUES (?, ?, ?, ' . implode(', ', array_fill(0, 10, '?')) . ')
               ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($c) => "$c = $c + VALUES($c)", self::COLS)),
                array_merge([$hid, $hour, $site($s)], $vals));
        }
        foreach (array_slice((array) ($stat['p'] ?? []), 0, 60) as $p) {
            if (!is_array($p) || count($p) < 6) {
                continue;
            }
            // Pad zonder querystring (de agent haalt hem al weg) en zonder tokens; alleen leesbare tekens
            $path = mb_substr((string) preg_replace('/[?#;].*$/', '', Inspect::redact((string) $p[1])), 0, 120);
            if ($path === '' || $path[0] !== '/') {
                continue;
            }
            $v = array_map(static fn($x) => max(0, min(10000000, (int) $x)), [$p[2], $p[3], $p[4], $p[5]]);
            q('INSERT INTO proxy_paths (host_id, day, site, path, hits, blocked, s403, bot) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), blocked = blocked + VALUES(blocked), s403 = s403 + VALUES(s403), bot = bot + VALUES(bot)',
                array_merge([$hid, $day, $site($p[0]), $path], $v));
        }
        $u = max(0, min(1000000, (int) ($stat['u'] ?? 0)));
        if ($u > 0) {
            q('INSERT INTO proxy_uniq (host_id, day, n) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)', [$hid, $day, $u]);
        }
    }

    public static function purge(): void
    {
        // 62 dagen: 30 dagen tonen en vergelijken met de 30 dagen ervoor
        q('DELETE FROM proxy_traffic WHERE hour < NOW() - INTERVAL 62 DAY');
        q('DELETE FROM proxy_paths WHERE day < CURDATE() - INTERVAL 62 DAY');
        q('DELETE FROM proxy_uniq WHERE day < CURDATE() - INTERVAL 62 DAY');
    }

    /**
     * Alles voor het blok bovenaan: tegels (met de vorige periode), redenen, statuscodes, top-lijsten en een grafiek.
     * @param string $period 24h, 7d of 30d
     * @param string $site   leeg = alle sites
     */
    public static function summary(string $period = '24h', string $site = '', ?int $now = null): array
    {
        $days = self::PERIODS[$period] ?? 1;
        $now ??= time();
        $from = date('Y-m-d H:i:s', $now - $days * 86400);
        $prevFrom = date('Y-m-d H:i:s', $now - 2 * $days * 86400);
        $hosts = array_map('intval', q('SELECT id FROM hosts WHERE proxy_role = 1')->fetchAll(PDO::FETCH_COLUMN));
        $out = ['period' => $period, 'site' => $site, 'has_data' => false, 'sites' => [], 'hosts' => count($hosts)];
        if (!$hosts) {
            return $out;
        }
        $hin = implode(',', $hosts);
        $sw = $site !== '' ? ' AND site = ?' : '';
        $sa = $site !== '' ? [$site] : [];
        $out['sites'] = array_map('strval', q("SELECT site FROM proxy_traffic WHERE host_id IN ($hin) AND hour >= ? AND site NOT IN ('-', '(overig)')
            GROUP BY site ORDER BY SUM(total) DESC LIMIT 60", [date('Y-m-d H:i:s', $now - 30 * 86400)])->fetchAll(PDO::FETCH_COLUMN));
        $sum = static function (string $a, string $b) use ($hin, $sw, $sa): array {
            $r = q('SELECT ' . implode(', ', array_map(static fn($c) => "COALESCE(SUM($c), 0) AS $c", self::COLS)) . " FROM proxy_traffic
                    WHERE host_id IN ($hin) AND hour >= ? AND hour < ?$sw", array_merge([$a, $b], $sa))->fetch() ?: [];
            return array_map('intval', $r);
        };
        $cur = $sum($from, date('Y-m-d H:i:s', $now + 3600));
        $prev = $sum($prevFrom, $from);
        $out['has_data'] = ($cur['total'] ?? 0) > 0 || ($prev['total'] ?? 0) > 0;

        // Redenen van de 403's uit de paden: alleen-LAN en dichtgezette paden (per proxyserver de eigen sites en regels)
        $lan = 0;
        $vp = 0;
        foreach ($hosts as $hid) {
            $sites = ProxyGuard::sites($hid);
            $active = array_map(static fn($r) => ['site' => $r['site'], 'path' => $r['path'], 'expired' => false],
                q('SELECT site, path FROM proxy_vpatch WHERE host_id = ? AND (expires_at IS NULL OR expires_at > NOW())', [$hid])->fetchAll());
            foreach (q("SELECT site, path, SUM(s403) AS s403, SUM(bot) AS bot FROM proxy_paths WHERE host_id = ? AND day >= ? AND s403 > 0$sw GROUP BY site, path",
                array_merge([$hid, date('Y-m-d', $now - ($days - 1) * 86400)], $sa)) as $r) {
                $n = max(0, (int) $r['s403'] - (int) $r['bot']);
                if (!$n) {
                    continue;
                }
                $s = Waf::matchSite((string) $r['site'], $sites);
                if ($s && $s['enabled'] && ($s['lan_mode'] === 'site' || ($s['lan_mode'] === 'paths' && self::underAny((string) $r['path'], (string) $s['lan_paths'])))) {
                    $lan += $n;
                } elseif (VPatch::covered($active, $s ? (string) $s['site'] : (string) $r['site'], (string) $r['path'])) {
                    $vp += $n;
                }
            }
        }
        $wafSiteW = $site !== '' ? ' AND site = ?' : '';
        $waf = (int) q("SELECT COUNT(*) FROM waf_events WHERE host_id IN ($hin) AND blocked = 1 AND at >= ?$wafSiteW", array_merge([$from], $sa))->fetchColumn();
        $wafPrev = (int) q("SELECT COUNT(*) FROM waf_events WHERE host_id IN ($hin) AND blocked = 1 AND at >= ? AND at < ?$wafSiteW", array_merge([$prevFrom, $from], $sa))->fetchColumn();
        $other = max(0, $cur['s403'] - $cur['bot'] - $lan - $vp - $waf);
        $blocked = $cur['s403'] + $cur['s429'] + $cur['s444'];
        $blockedPrev = $prev['s403'] + $prev['s429'] + $prev['s444'];
        $pct = static fn(int $a, int $b) => $b > 0 ? round($a * 100 / $b, 1) : 0.0;
        $out['reasons'] = array_values(array_filter([
            ['k' => 'waf', 'n' => min($waf, $cur['s403'] ?: $waf)],
            ['k' => 'vpatch', 'n' => $vp],
            ['k' => 'lan', 'n' => $lan],
            ['k' => 'bots', 'n' => $cur['bot']],
            ['k' => 'rate', 'n' => $cur['s429']],
            ['k' => 'nohost', 'n' => $cur['s444']],
            ['k' => 'other', 'n' => $other],
        ], static fn($r) => $r['n'] > 0));
        foreach ($out['reasons'] as &$r) {
            $r['pct'] = $pct($r['n'], max(1, $blocked));
        }
        unset($r);

        // Buiten het proxylog: firewall (IDS), ClamAV, Suricata (alleen bij alle sites)
        $ids = q("SELECT COUNT(*) AS ips, COALESCE(SUM(hits), 0) AS hits FROM ids_blocks WHERE created_at >= ? OR (removed_at IS NULL AND (until IS NULL OR until > NOW()))", [$from])->fetch();
        $out['extra'] = $site !== '' ? null : [
            'ids_ips' => (int) ($ids['ips'] ?? 0), 'ids_hits' => (int) ($ids['hits'] ?? 0),
            'av' => (int) q("SELECT COUNT(*) FROM av_events WHERE host_id IN ($hin) AND status = 'quarantined' AND at >= ?", [$from])->fetchColumn(),
            'nids' => (int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id IN ($hin) AND type = 'suricata' AND last_seen >= ?", [$from])->fetchColumn(),
        ];

        // Aanvallers: verdachte IP's (incidenten van het type web) en hoeveel daarvan nu geblokkeerd zijn
        $att = q("SELECT ip, MAX(cc) AS cc, SUM(hits) AS hits FROM ids_incidents WHERE type IN ('web_attack', 'web_payload', 'web_cve') AND last_seen >= ? AND ip IS NOT NULL AND ip <> ''
                  GROUP BY ip ORDER BY hits DESC LIMIT 500", [$from])->fetchAll();
        $blockedIps = array_flip(array_map('strval', q('SELECT DISTINCT ip FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchAll(PDO::FETCH_COLUMN)));
        $attBlocked = count(array_filter($att, static fn($r) => isset($blockedIps[(string) $r['ip']])));
        $attPrev = (int) q("SELECT COUNT(DISTINCT ip) FROM ids_incidents WHERE type IN ('web_attack', 'web_payload', 'web_cve') AND last_seen >= ? AND last_seen < ?", [$prevFrom, $from])->fetchColumn();
        $uniq = (int) q("SELECT COALESCE(SUM(n), 0) FROM proxy_uniq WHERE host_id IN ($hin) AND day >= ?", [date('Y-m-d', $now - ($days - 1) * 86400)])->fetchColumn();
        $uniqPrev = (int) q("SELECT COALESCE(SUM(n), 0) FROM proxy_uniq WHERE host_id IN ($hin) AND day >= ? AND day < ?",
            [date('Y-m-d', $now - (2 * $days - 1) * 86400), date('Y-m-d', $now - ($days - 1) * 86400)])->fetchColumn();
        $out['tiles'] = [
            'total' => ['n' => $cur['total'], 'prev' => $prev['total']],
            'passed' => ['n' => $cur['total'] - $blocked, 'pct' => $pct($cur['total'] - $blocked, $cur['total']), 'prev_pct' => $pct($prev['total'] - $blockedPrev, $prev['total'])],
            'blocked' => ['n' => $blocked, 'pct' => $pct($blocked, $cur['total']), 'prev' => $blockedPrev, 'prev_pct' => $pct($blockedPrev, $prev['total'])],
            'visitors' => $site !== '' ? null : ['n' => $uniq, 'prev' => $uniqPrev],
            'attackers' => ['n' => count($att), 'blocked' => $attBlocked, 'pct' => $pct($attBlocked, count($att)), 'prev' => $attPrev],
            'waf' => ['n' => $waf, 'prev' => $wafPrev],
        ];
        $c4 = $cur['s4'] + $cur['s403'] + $cur['s404'] + $cur['s429'] + $cur['s444'];
        $out['status'] = ['2xx' => $cur['s2'], '3xx' => $cur['s3'], '4xx' => $c4, '5xx' => $cur['s5']];
        $out['status_pct'] = array_map(static fn($n) => $pct($n, $cur['total']), $out['status']);

        // Top: aangevallen paden, sites, landen en IP's
        $out['paths'] = array_map(static fn($r) => ['site' => $r['site'], 'path' => $r['path'], 'hits' => (int) $r['hits'], 'blocked' => (int) $r['blocked'],
            'pct' => $pct((int) $r['blocked'], (int) $r['hits'])],
            q("SELECT site, path, SUM(hits) AS hits, SUM(blocked) AS blocked FROM proxy_paths WHERE host_id IN ($hin) AND day >= ?$sw
               GROUP BY site, path ORDER BY hits DESC LIMIT 10", array_merge([date('Y-m-d', $now - ($days - 1) * 86400)], $sa))->fetchAll());
        $out['top_sites'] = array_map(static fn($r) => ['site' => $r['site'], 'total' => (int) $r['t'], 'err_pct' => $pct((int) $r['e'], (int) $r['t']),
            'blocked_pct' => $pct((int) $r['b'], (int) $r['t']), 'err5_pct' => $pct((int) $r['s5'], (int) $r['t'])],
            q("SELECT site, SUM(total) AS t, SUM(s4 + s403 + s404 + s429 + s444 + s5) AS e, SUM(s403 + s429 + s444) AS b, SUM(s5) AS s5 FROM proxy_traffic
               WHERE host_id IN ($hin) AND hour >= ?$sw GROUP BY site ORDER BY t DESC LIMIT 10", array_merge([$from], $sa))->fetchAll());
        $cc = [];
        foreach ($att as $r) {
            $k = (string) ($r['cc'] ?: '??');
            $cc[$k] = ($cc[$k] ?? 0) + (int) $r['hits'];
        }
        arsort($cc);
        $ccTotal = max(1, array_sum($cc));
        $out['countries'] = array_map(static fn($k, $n) => ['cc' => $k, 'hits' => $n, 'pct' => $pct($n, $ccTotal)], array_keys(array_slice($cc, 0, 8, true)), array_slice($cc, 0, 8, true));
        $out['ips'] = array_map(static fn($r) => ['ip' => $r['ip'], 'cc' => $r['cc'], 'hits' => (int) $r['hits'], 'blocked' => isset($blockedIps[(string) $r['ip']])], array_slice($att, 0, 8));

        // Grafiek: per uur (24 uur) of per dag (7 en 30 dagen): doorgelaten en tegengehouden
        $fmt = $days === 1 ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        $rows = [];
        foreach (q("SELECT DATE_FORMAT(hour, '$fmt') AS b, SUM(total) AS t, SUM(s403 + s429 + s444) AS bl FROM proxy_traffic WHERE host_id IN ($hin) AND hour >= ?$sw GROUP BY b",
            array_merge([$from], $sa)) as $r) {
            $rows[$r['b']] = [(int) $r['t'], (int) $r['bl']];
        }
        $chart = [];
        $n = $days === 1 ? 24 : $days;
        for ($i = $n - 1; $i >= 0; $i--) {
            $b = $days === 1 ? date('Y-m-d H:00', $now - $i * 3600) : date('Y-m-d', $now - $i * 86400);
            [$t, $bl] = $rows[$b] ?? [0, 0];
            $chart[] = ['b' => $b, 'passed' => $t - $bl, 'blocked' => $bl];
        }
        $out['chart'] = $chart;
        return $out;
    }

    /** Valt dit pad onder een van de paden (komma-lijst), als begin, zonder hoofdlettergevoeligheid */
    private static function underAny(string $path, string $list): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $list))) as $p) {
            if ($p !== '' && stripos($path, $p) === 0) {
                return true;
            }
        }
        return false;
    }
}
