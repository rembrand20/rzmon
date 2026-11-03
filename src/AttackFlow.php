<?php
declare(strict_types=1);

/**
 * Attack Flow (RzMon 1.152.0): aanvaller → doelwit → dienst → afloop, op basis van de aanvalskaart (Ids::flows).
 *
 * Belangrijk onderscheid: een lijn is "waargenomen" als iets het verkeer echt zag (auth.log, de log van een webdienst,
 * Suricata, de firewall/honeypot of de agent die een verbinding zag). "Afgeleid" is een verband dat RzMon uit
 * meldingen legt (een keten, dreigingsinformatie, of "deze server werd aangevallen en valt nu zelf aan"). Zo wordt een
 * vermoeden nooit als bewezen verkeer getoond.
 */
final class AttackFlow
{
    /** Soorten incidenten waarvan het verkeer echt is gezien, en door wie */
    private const OBSERVED = [
        'bruteforce' => 'auth.log', 'breakin' => 'auth.log', 'rootlogin' => 'auth.log', 'newip' => 'auth.log', 'ssh_login' => 'auth.log',
        'decoy_user' => 'auth.log', 'ssh' => 'auth.log', 'web_attack' => 'weblog', 'web_cve' => 'weblog', 'web_payload' => 'weblog',
        'suricata' => 'Suricata', 'decoy_port' => 'firewall', 'outbound' => 'agent', 'out_bad' => 'agent', 'out_country' => 'agent',
        'revshell' => 'agent', 'edr' => 'agent',
    ];
    /** Daarna is er ingelogd */
    private const LOGIN = ['breakin', 'ssh_login', 'newip', 'rootlogin'];

    /** @param array{days?: int, mins?: int, host?: int, cc?: string, cat?: string, dir?: string, top?: int} $o */
    public static function build(array $o): array
    {
        $f = Ids::flows($o + ['top' => 12]);
        $days = (int) $f['days'];
        $since = date('Y-m-d H:i:s', time() - ($f['mins'] ? $f['mins'] * 60 : $days * 86400));
        // Welk IP hoort bij welke server (een eigen server als bron = interne aanvaller)
        $hostByIp = [];
        foreach (q('SELECT id, name, address, agent_ip FROM hosts')->fetchAll() as $h) {
            foreach ([$h['agent_ip'], $h['address']] as $a) {
                $a = trim((string) $a);
                if ($a !== '' && @inet_pton($a) !== false && !in_array($a, ['127.0.0.1', '::1'], true)) {
                    $hostByIp[$a] ??= ['id' => (int) $h['id'], 'name' => (string) $h['name']];
                }
            }
        }
        $ips = array_column($f['sources'], 'ip');
        $in = $ips ? implode(',', array_fill(0, count($ips), '?')) : "''";
        // Blokkades in of na de periode (ook opgeheven: dan zie je in de tijdlijn wanneer)
        $blocks = [];
        foreach ($ips ? q("SELECT ip, host_id, created_at FROM ids_blocks WHERE ip IN ($in) AND (created_at >= ? OR removed_at IS NULL) ORDER BY created_at",
            array_merge($ips, [$since]))->fetchAll() : [] as $b) {
            $blocks[$b['ip']][] = $b;
        }
        foreach ($f['sources'] as &$s) {
            $s['host'] = $s['internal'] ? ($hostByIp[$s['ip']] ?? null) : null;
        }
        unset($s);
        $srcBlocked = array_column($f['sources'], 'blocked', 'ip');
        foreach ($f['lines'] as $i => $l) {
            $types = $l['types'] ?? [];
            $via = null;
            foreach ($types as $t) {
                if (isset(self::OBSERVED[$t])) {
                    $via = self::OBSERVED[$t];
                    break;
                }
            }
            if ($via === null && in_array('ti_ip', $types, true) && str_contains((string) $l['title'], '→')) {
                $via = 'agent';
            }
            $obs = $via !== null;
            $blockAt = null;
            foreach ($blocks[$l['ip']] ?? [] as $b) {
                if ((int) $b['host_id'] === $l['host_id'] || $blockAt === null) {
                    $blockAt = $b['created_at'];
                    if ((int) $b['host_id'] === $l['host_id']) {
                        break;
                    }
                }
            }
            $outcome = match (true) {
                (bool) array_intersect($types, self::LOGIN) => ['k' => 'login'],
                !empty($srcBlocked[$l['ip']]) => ['k' => 'blocked', 'at' => $blockAt],
                !$obs => ['k' => 'unconfirmed'],
                default => ['k' => 'open'],
            };
            $f['lines'][$i] += ['obs' => $obs, 'via' => $via ?? (in_array('chain', $types, true) ? 'keten' : 'RzMon'), 'outcome' => $outcome];
        }
        // Afgeleid: een server die eerst werd aangevallen en daarna zelf (als intern IP) een bron is
        $pivots = [];
        foreach ($f['sources'] as $s) {
            if (empty($s['host'])) {
                continue;
            }
            $first = min(array_map(static fn($l) => $l['first'], array_filter($f['lines'], static fn($l) => $l['ip'] === $s['ip'])) ?: [$s['last']]);
            $hit = array_filter($f['lines'], static fn($l) => $l['host_id'] === $s['host']['id'] && !$l['internal'] && $l['first'] <= $first);
            if ($hit) {
                $prev = min(array_column($hit, 'first'));
                $pivots[] = ['host_id' => $s['host']['id'], 'ip' => $s['ip'], 'minutes' => (int) round((strtotime($first) - strtotime($prev)) / 60), 'obs' => false];
            }
        }
        // Tijdlijn: losse SSH-pogingen en logins, de opgetelde pogingen van oudere agents, blokkades en meldingen
        $events = [];
        if ($ips) {
            $seen = [];
            foreach (q("SELECT host_id, ip, at, ok, username FROM ssh_events WHERE ip IN ($in) AND at >= ? ORDER BY at LIMIT 3000", array_merge($ips, [$since]))->fetchAll() as $e) {
                $events[] = ['at' => $e['at'], 'ip' => $e['ip'], 'host_id' => (int) $e['host_id'], 'k' => (int) $e['ok'] ? 'login' : 'try', 'n' => 1, 'u' => $e['username']];
                $seen[$e['host_id'] . '|' . $e['ip']] = true;
            }
            foreach (q("SELECT host_id, ip, at, n FROM ids_attempts WHERE ip IN ($in) AND at >= ? ORDER BY at LIMIT 2000", array_merge($ips, [$since]))->fetchAll() as $e) {
                if (!isset($seen[$e['host_id'] . '|' . $e['ip']])) {
                    $events[] = ['at' => $e['at'], 'ip' => $e['ip'], 'host_id' => (int) $e['host_id'], 'k' => 'try', 'n' => (int) $e['n']];
                }
            }
            foreach ($blocks as $ip => $list) {
                foreach ($list as $b) {
                    if ($b['created_at'] >= $since) {
                        $events[] = ['at' => $b['created_at'], 'ip' => $ip, 'host_id' => (int) $b['host_id'], 'k' => 'block', 'n' => 1];
                    }
                }
            }
        }
        foreach ($f['lines'] as $i => $l) {
            $events[] = ['at' => $l['first'], 'ip' => $l['ip'], 'host_id' => $l['host_id'], 'k' => 'inc', 'n' => 1, 'line' => $i, 'obs' => $l['obs']];
        }
        usort($events, static fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $r = $f + ['pivots' => $pivots, 'events' => array_slice($events, -3000), 'since' => $since, 'now' => now()];
        $r['live'] = self::live($r, (int) ($o['host'] ?? 0));
        return $r;
    }

    /**
     * 1.153.0: de live weergave: wie valt nu het hardst aan (laatste 15 minuten) en wat deed het IDS, hoeveel aanvallers
     * zijn bezig, de IDS-acties van het laatste uur, de nieuwste gebeurtenissen (ticker) en de top 10 van de periode.
     * Per lijn het tempo (pogingen per minuut) en of hij nieuw is, per server of hij op dit moment geraakt wordt.
     */
    private static function live(array $r, int $hostId): array
    {
        $now = time();
        $ts = static fn($s) => (int) strtotime((string) $s);
        $q15 = $now - 900;
        // Pogingen per IP en per lijn in de laatste 15 minuten, per minuut (voor het lijntje)
        $perIp = [];
        $perLine = [];
        $spark = [];
        $bars = array_fill(0, 8, 0);
        $hitNow = [];
        $seenTry = [];
        $isBlocked = array_column($r['sources'], 'blocked', 'ip');
        foreach ($r['events'] as $e) {
            $t = $ts($e['at']);
            if ($e['k'] === 'try' || $e['k'] === 'login') {
                $seenTry[$e['ip']] = true;
            }
            if ($t < $q15 || $e['k'] === 'block') {
                continue;
            }
            $n = max(1, (int) $e['n']);
            if ($e['k'] === 'try' || $e['k'] === 'login') {
                $perIp[$e['ip']] = ($perIp[$e['ip']] ?? 0) + $n;
                $perLine[$e['ip'] . '|' . $e['host_id']] = ($perLine[$e['ip'] . '|' . $e['host_id']] ?? 0) + $n;
                $m = min(14, intdiv($t - $q15, 60));
                $spark[$e['ip']] ??= array_fill(0, 15, 0);
                $spark[$e['ip']][$m] += $n;
                $bars[min(7, intdiv($t - $q15, 113))] += $n;
            }
            if ($t >= $now - 120 && empty($isBlocked[$e['ip']])) {   // een geblokkeerde aanvaller raakt de server niet meer
                $hitNow[$e['host_id']] = true;
            }
        }
        // Meldingen zonder losse pogingen (web, Suricata, honeypot): het tempo schatten uit het aantal en de duur
        $lines = [];
        foreach ($r['lines'] as $i => $l) {
            $k = $l['ip'] . '|' . $l['host_id'];
            $last = $ts($l['last']);
            $first = $ts($l['first']);
            if (!isset($seenTry[$l['ip']]) && $last >= $q15) {
                $est = max(1, (int) round($l['hits'] * 900 / max(900, $last - $first)));
                $perLine[$k] = ($perLine[$k] ?? 0) + $est;
                $perIp[$l['ip']] = ($perIp[$l['ip']] ?? 0) + $est;
                $bars[7] += $est;
            }
            if ($last >= $now - 120 && $l['outcome']['k'] !== 'blocked') {
                $hitNow[$l['host_id']] = true;
            }
            $lines[$i] = ['rate' => round(($perLine[$k] ?? 0) / 15, 1), 'fresh' => $first >= $now - 300, 'recent' => $last >= $q15];
        }
        $names = array_column($r['targets'], 'name', 'id');
        $src = array_column($r['sources'], null, 'ip');
        // Grootste aanvaller nu (anders: de grootste van de periode)
        arsort($perIp);
        $topIp = array_key_first($perIp);
        if ($topIp === null && $r['sources']) {
            $by = $r['sources'];
            usort($by, static fn($a, $b) => $b['hits'] <=> $a['hits']);
            $topIp = $by[0]['ip'];
        }
        $top = null;
        if ($topIp !== null && isset($src[$topIp])) {
            $s = $src[$topIp];
            $mine = array_values(array_filter($r['lines'], static fn($l) => $l['ip'] === $topIp));
            usort($mine, static fn($a, $b) => $b['hits'] <=> $a['hits']);
            $blk = q("SELECT b.host_id, h.name, MIN(b.created_at) AS at, SUM(b.hits) AS hits FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                       WHERE b.ip = ? AND b.removed_at IS NULL AND (b.until IS NULL OR b.until > NOW()) GROUP BY b.host_id, h.name ORDER BY at", [$topIp])->fetchAll();
            $on = array_map('intval', array_column($blk, 'host_id'));
            $notOn = [];
            foreach ($mine as $l) {
                if (!in_array($l['host_id'], $on, true) && $l['dir'] !== 'o') {
                    $notOn[$l['host_id']] = $names[$l['host_id']] ?? '?';
                }
            }
            $users = array_column(q("SELECT username, COUNT(*) AS n FROM ssh_events WHERE ip = ? AND ok = 0 AND at >= ? AND username <> '' GROUP BY username ORDER BY n DESC LIMIT 3",
                [$topIp, $r['since']])->fetchAll(), 'username');
            $top = ['ip' => $topIp, 'cc' => $s['cc'], 'internal' => $s['internal'], 'sev' => $s['sev'], 'hits' => $s['hits'], 'now' => (bool) $perIp,
                'per_min' => round(($perIp[$topIp] ?? 0) / 15, 1), 'spark' => $spark[$topIp] ?? array_fill(0, 15, 0), 'users' => $users,
                'line' => $mine ? ['svc' => $mine[0]['svc'], 'port' => $mine[0]['port'], 'proto' => $mine[0]['proto'], 'host' => $names[$mine[0]['host_id']] ?? '?',
                    'cats' => $mine[0]['cats'], 'outcome' => $mine[0]['outcome'], 'dir' => $mine[0]['dir']] : null,
                'ntargets' => count(array_unique(array_column($mine, 'host_id'))),
                'blocks' => array_map(static fn($b) => ['host' => $b['name'], 'at' => $b['at'], 'hits' => (int) $b['hits']], $blk),
                'not_on' => array_values($notOn), 'blocked_rz' => $s['blocked_rz']];
        }
        // IDS-acties van het laatste uur
        $hf = $hostId ? ' AND b.host_id = ' . $hostId : '';
        $kf = $hostId ? ' AND k.host_id = ' . $hostId : '';
        $lastBlock = q("SELECT b.ip, b.created_at AS at, h.name AS host, b.auto FROM ids_blocks b JOIN hosts h ON h.id = b.host_id WHERE 1=1$hf ORDER BY b.created_at DESC LIMIT 1")->fetch() ?: null;
        $lastKill = q("SELECT k.created_at AS at, h.name AS host, k.dkey FROM ids_kills k JOIN hosts h ON h.id = k.host_id WHERE 1=1$kf ORDER BY k.created_at DESC LIMIT 1")->fetch() ?: null;
        $last = null;
        if ($lastBlock && (!$lastKill || $lastBlock['at'] >= $lastKill['at'])) {
            $last = ['k' => 'block', 'at' => $lastBlock['at'], 'host' => $lastBlock['host'], 'ip' => $lastBlock['ip']];
        } elseif ($lastKill) {
            $last = ['k' => 'kill', 'at' => $lastKill['at'], 'host' => $lastKill['host'], 'what' => (string) $lastKill['dkey']];
        }
        $actions = [
            'blocks' => (int) q("SELECT COUNT(*) FROM ids_blocks b WHERE b.created_at >= NOW() - INTERVAL 1 HOUR$hf")->fetchColumn(),
            'kills' => (int) q("SELECT COUNT(*) FROM ids_kills k WHERE k.created_at >= NOW() - INTERVAL 1 HOUR$kf")->fetchColumn(),
            'open' => (int) q("SELECT COUNT(*) FROM ids_incidents i WHERE i.status = 'open' AND i.last_seen >= NOW() - INTERVAL 1 HOUR" . ($hostId ? ' AND i.host_id = ' . $hostId : ''))->fetchColumn(),
            'last' => $last,
        ];
        // Ticker: de nieuwste gebeurtenissen (hooguit een dag terug)
        $tick = [];
        $sh = $hostId ? ' AND e.host_id = ' . $hostId : '';
        foreach (q("SELECT e.at, e.ok, e.ip, e.username AS u, e.port, e.method, h.name AS host FROM ssh_events e JOIN hosts h ON h.id = e.host_id
                    WHERE e.at >= NOW() - INTERVAL 1 DAY$sh ORDER BY e.at DESC LIMIT 30")->fetchAll() as $e) {
            $tick[] = ['at' => $e['at'], 'k' => (int) $e['ok'] ? 'login' : 'try', 'ip' => $e['ip'], 'host' => $e['host'], 'u' => $e['u'], 'method' => $e['method'],
                'blocked' => !empty($src[$e['ip']]['blocked'])];
        }
        foreach (q("SELECT b.created_at AS at, b.ip, h.name AS host, b.reason FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                    WHERE b.created_at >= NOW() - INTERVAL 1 DAY$hf ORDER BY b.created_at DESC LIMIT 15")->fetchAll() as $b) {
            $tick[] = ['at' => $b['at'], 'k' => 'block', 'ip' => $b['ip'], 'host' => $b['host'], 'what' => $b['reason']];
        }
        foreach (q("SELECT i.id, i.created_at AS at, i.ip, i.title, i.severity, i.dir, i.dport, h.name AS host FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                    WHERE i.created_at >= NOW() - INTERVAL 1 DAY AND i.type NOT IN ('chain', 'anomaly')" . ($hostId ? ' AND i.host_id = ' . $hostId : '') . '
                    ORDER BY i.created_at DESC LIMIT 15')->fetchAll() as $i) {
            $tick[] = ['at' => $i['at'], 'k' => $i['dir'] === 'o' ? 'out' : 'inc', 'ip' => (string) $i['ip'], 'host' => $i['host'], 'what' => $i['title'], 'sev' => (int) $i['severity'],
                'port' => $i['dport'] !== null ? (int) $i['dport'] : null, 'id' => (int) $i['id']];
        }
        usort($tick, static fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        // Top 10 van de periode, met een trendlijntje (12 stukjes van de periode)
        $t0 = $ts($r['since']);
        $span = max(60, $now - $t0);
        $trend = [];
        foreach ($r['events'] as $e) {
            if ($e['k'] === 'block') {
                continue;
            }
            $trend[$e['ip']] ??= array_fill(0, 12, 0);
            $trend[$e['ip']][max(0, min(11, intdiv(($ts($e['at']) - $t0) * 12, $span)))] += max(1, (int) $e['n']);
        }
        $by = $r['sources'];
        usort($by, static fn($a, $b) => $b['hits'] <=> $a['hits']);
        $top10 = [];
        foreach (array_slice($by, 0, 10) as $s) {
            $mine = array_values(array_filter($r['lines'], static fn($l) => $l['ip'] === $s['ip']));
            usort($mine, static fn($a, $b) => $b['hits'] <=> $a['hits']);
            $tg = array_values(array_unique(array_map(static fn($l) => $names[$l['host_id']] ?? '?', $mine)));
            $ks = array_column(array_column($mine, 'outcome'), 'k');
            $top10[] = ['ip' => $s['ip'], 'cc' => $s['cc'], 'internal' => $s['internal'], 'sev' => $s['sev'], 'hits' => $s['hits'], 'targets' => $tg,
                'svc' => $mine ? $mine[0]['svc'] : null, 'port' => $mine ? $mine[0]['port'] : null, 'dir' => $mine ? $mine[0]['dir'] : 'i',
                'trend' => $trend[$s['ip']] ?? array_fill(0, 12, 0), 'up' => ($perIp[$s['ip']] ?? 0) > 0,
                'state' => in_array('login', $ks, true) ? 'login' : ($s['blocked'] ? 'blocked' : (in_array('open', $ks, true) ? 'open' : 'unconfirmed'))];
        }
        return ['at' => now(), 'top' => $top, 'busy' => ['active' => count($perIp), 'per_min' => round(array_sum($perIp) / 15, 1), 'bars' => $bars],
            'actions' => $actions, 'lines' => $lines, 'hit' => array_map('intval', array_keys($hitNow)), 'tick' => array_slice($tick, 0, 40), 'top10' => $top10,
            'motion' => max(0, min(2, (int) setting('af_motion', '2')))];
    }

    /**
     * Alles over één IP-adres voor de popup: elke gebeurtenis (SSH-poging, login, einde sessie, bewijs van Suricata, de
     * agent of een webdienst, blokkades en meldingen), waar het geblokkeerd is en waar niet, de meldingen en waarom.
     */
    public static function ipEvents(string $ip, int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $rows = [];
        $hostName = array_column(q('SELECT id, name FROM hosts')->fetchAll(), 'name', 'id');
        $sshHosts = [];
        foreach (q('SELECT * FROM ssh_events WHERE ip = ? AND at >= ? ORDER BY at DESC LIMIT 1000', [$ip, $since])->fetchAll() as $e) {
            $h = $hostName[$e['host_id']] ?? '?';
            $sshHosts[(int) $e['host_id']] = true;
            if ((int) $e['ok']) {
                $rows[] = ['at' => $e['at'], 'k' => 'login', 'host' => $h, 'sport' => $e['port'] !== null ? (int) $e['port'] : null, 'dport' => 22, 'proto' => 'TCP',
                    'what' => t('ingelogd als {u}', ['u' => $e['username']]) . ($e['method'] ? ' (' . $e['method'] . ')' : ''), 'via' => 'auth.log', 'st' => 'login'];
                if ($e['closed_at'] !== null) {
                    $rows[] = ['at' => $e['closed_at'], 'k' => 'end', 'host' => $h, 'sport' => $e['port'] !== null ? (int) $e['port'] : null, 'dport' => 22, 'proto' => 'TCP',
                        'what' => t('sessie van {u} beëindigd', ['u' => $e['username']]), 'via' => 'auth.log', 'st' => 'info'];
                }
            } else {
                $rows[] = ['at' => $e['at'], 'k' => 'fail', 'host' => $h, 'sport' => $e['port'] !== null ? (int) $e['port'] : null, 'dport' => 22, 'proto' => 'TCP',
                    'what' => t('mislukte login als {u}', ['u' => $e['username']]), 'via' => 'auth.log', 'st' => 'fail'];
            }
        }
        // Oudere agents: alleen opgeteld per rapport
        foreach (q('SELECT host_id, at, n FROM ids_attempts WHERE ip = ? AND at >= ? ORDER BY at DESC LIMIT 300', [$ip, $since])->fetchAll() as $e) {
            if (!isset($sshHosts[(int) $e['host_id']])) {
                $rows[] = ['at' => $e['at'], 'k' => 'fail', 'n' => (int) $e['n'], 'host' => $hostName[$e['host_id']] ?? '?', 'sport' => null, 'dport' => 22, 'proto' => 'TCP',
                    'what' => t('{n} mislukte SSH-logins', ['n' => (int) $e['n']]), 'via' => 'auth.log', 'st' => 'fail'];
            }
        }
        $incs = q('SELECT i.*, h.name AS host FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.ip = ? AND i.last_seen >= ? ORDER BY i.severity DESC, i.last_seen DESC LIMIT 50',
            [$ip, $since])->fetchAll();
        foreach ($incs as $i) {
            $rows[] = ['at' => $i['created_at'], 'k' => 'inc', 'host' => $i['host'], 'sport' => null, 'dport' => $i['dport'] !== null ? (int) $i['dport'] : null,
                'proto' => $i['proto'] !== null ? strtoupper((string) $i['proto']) : null, 'what' => $i['title'], 'via' => 'RzMon', 'st' => 'detect', 'id' => (int) $i['id']];
        }
        // Bewijs bij de meldingen (Suricata, verbindingen van de agent, webverzoeken)
        if ($incs) {
            $ids = array_column($incs, 'id');
            $byInc = array_column($incs, null, 'id');
            foreach (q('SELECT * FROM ids_evidence WHERE incident_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY at DESC LIMIT 300', $ids)->fetchAll() as $e) {
                $d = json_decode((string) $e['data'], true) ?: [];
                $ev = (array) ($d['ev'] ?? []);
                $h = $byInc[$e['incident_id']]['host'] ?? '?';
                $row = match ((string) $e['kind']) {
                    'sur' => ['sport' => (int) ($ev['sport'] ?? 0) ?: null, 'dport' => (int) ($ev['dport'] ?? 0) ?: null, 'proto' => (string) ($ev['proto'] ?? ''),
                        'what' => (string) ($ev['sig'] ?? 'Suricata') . (!empty($ev['sid']) ? ' (sid ' . (int) $ev['sid'] . ')' : ''), 'via' => 'Suricata',
                        'st' => ($ev['act'] ?? '') === 'blocked' ? 'stopped' : 'seen'],
                    'net' => ['sport' => null, 'dport' => (int) ($ev['rp'] ?? 0) ?: null, 'proto' => 'TCP',
                        'what' => t('verbinding door {exe}', ['exe' => (string) ($ev['exe'] ?? ($ev['comm'] ?? '?'))]), 'via' => 'agent', 'st' => 'seen'],
                    'web' => ['sport' => null, 'dport' => 443, 'proto' => 'TCP',
                        'what' => trim((string) ($ev['method'] ?? '') . ' ' . mb_substr((string) ($ev['uri'] ?? ''), 0, 120)) . (!empty($ev['status']) ? ' → ' . (int) $ev['status'] : ''),
                        'via' => 'weblog', 'st' => (int) ($ev['status'] ?? 0) >= 200 && (int) ($ev['status'] ?? 0) < 400 ? 'fail' : 'seen'],
                    default => null,
                };
                if ($row) {
                    $rows[] = $row + ['at' => $e['at'], 'k' => 'ev', 'host' => $h, 'rule' => (string) $e['rule']];
                }
            }
        }
        $active = [];
        foreach (q('SELECT b.*, h.name AS host FROM ids_blocks b JOIN hosts h ON h.id = b.host_id WHERE b.ip = ? AND (b.created_at >= ? OR b.removed_at IS NULL) ORDER BY b.created_at DESC LIMIT 50',
            [$ip, $since])->fetchAll() as $b) {
            $rows[] = ['at' => $b['created_at'], 'k' => 'block', 'host' => $b['host'], 'sport' => null, 'dport' => null, 'proto' => null,
                'what' => t('IP geblokkeerd') . ($b['until'] ? ' ' . t('tot {t}', ['t' => $b['until']]) : ' ' . t('(blijvend)')) . ($b['reason'] !== '' ? ' · ' . $b['reason'] : ''), 'via' => 'RzMon', 'st' => 'action'];
            if ($b['removed_at'] !== null) {
                $rows[] = ['at' => $b['removed_at'], 'k' => 'unblock', 'host' => $b['host'], 'sport' => null, 'dport' => null, 'proto' => null,
                    'what' => t('blokkade opgeheven'), 'via' => 'RzMon', 'st' => 'info'];
            } elseif ($b['until'] === null || $b['until'] > now()) {
                $active[] = ['host' => $b['host'], 'host_id' => (int) $b['host_id'], 'at' => $b['created_at'], 'until' => $b['until'], 'hits' => (int) $b['hits'], 'reason' => $b['reason']];
            }
        }
        usort($rows, static fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        $internal = Ids::internalIp($ip);
        $main = $incs[0] ?? null;
        $blockedOn = array_column($active, 'host_id');
        $notOn = $internal ? [] : array_column(q("SELECT name FROM hosts WHERE mode = 'agent' AND enabled = 1" . ($blockedOn ? ' AND id NOT IN (' . implode(',', array_map('intval', $blockedOn)) . ')' : '') . ' ORDER BY name')->fetchAll(), 'name');
        $fails = count(array_filter($rows, static fn($r) => $r['k'] === 'fail' && !isset($r['n']))) + array_sum(array_map(static fn($r) => $r['n'] ?? 0, array_filter($rows, static fn($r) => $r['k'] === 'fail')));
        return [
            'ip' => $ip, 'days' => $days, 'internal' => $internal, 'cc' => $internal ? null : (IpIntel::country($ip) ?? ($main['cc'] ?? null)), 'bad' => $internal ? null : IpIntel::bad($ip),
            'rows' => array_slice($rows, 0, 500), 'truncated' => count($rows) > 500,
            'counts' => ['fails' => $fails, 'logins' => count(array_filter($rows, static fn($r) => $r['k'] === 'login')),
                'stopped' => array_sum(array_column($active, 'hits')), 'incidents' => count($incs)],
            'blocks' => $active, 'blocked_rz' => !$internal && Security::blockFor($ip) !== null, 'not_blocked_on' => $notOn,
            'incidents' => array_map(static fn($i) => ['id' => (int) $i['id'], 'title' => $i['title'], 'type' => $i['type'], 'sev' => (int) $i['severity'], 'host' => $i['host'],
                'created' => $i['created_at'], 'last' => $i['last_seen'], 'hits' => (int) $i['hits'], 'status' => $i['status'], 'observed' => isset(self::OBSERVED[$i['type']])], $incs),
            'explain' => $main ? Protect::describe($main) : null,
        ];
    }
}
