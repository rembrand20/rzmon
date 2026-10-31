<?php
declare(strict_types=1);

/**
 * Analyse (1.106.0): trends door de tijd, voor het tabblad 📊 Analyse en het maandrapport per e-mail.
 * - stats_daily: dagtotalen (een jaar bewaard), zodat trends langer gaan dan de bewaartermijn van de metingen.
 *   Elk uur wordt vandaag bijgewerkt; de eerste keer worden alle dagen berekend die nog in de metingen staan.
 *   metric/ref: c_n, c_warn, c_crit, c_avg, c_max, c_chg, c_tocrit (per check) · h_n, h_ok (beschikbaarheid per server)
 *   · a_<soort>, a_push, a_mail, a_hook (meldingen) · ids_1, ids_2, auth_fail, auth_ok, blk, blk_auto (beveiliging)
 *   · net_total, net_new, vuln_open, vuln_kev, cnt_hosts, cnt_checks (stand van die dag, alleen vanaf nu).
 * - report(): alles voor één periode, met de vorige periode van dezelfde lengte ernaast.
 */
final class Analytics
{
    private const KEEP_DAYS = 400;

    // ------------------------------------------------------------------ dagtotalen

    /** Vanuit cron: elk uur vandaag (en gisteren nog één keer af), de eerste keer alles wat er is */
    public static function cron(): ?string
    {
        $last = (int) setting('stats_daily_at', '0');
        if ($last > time() - 3600) {
            return null;
        }
        $first = !q('SELECT 1 FROM stats_daily LIMIT 1')->fetchColumn();
        $from = $first ? (string) (q('SELECT DATE(MIN(created_at)) FROM results')->fetchColumn() ?: date('Y-m-d')) : date('Y-m-d', strtotime('-1 day', $last ?: time()));
        $n = self::rollup($from, date('Y-m-d'));
        self::snapshot(date('Y-m-d'));
        q('DELETE FROM stats_daily WHERE day < CURDATE() - INTERVAL ' . self::KEEP_DAYS . ' DAY');
        save_setting('stats_daily_at', (string) time());
        if (NetLinks::adguardOn()) {
            self::adguardStats();
        }
        return $first ? "Analyse: $n dagen berekend" : null;
    }

    private static function put(string $day, string $metric, int $ref, float $v): void
    {
        static $st = null;
        $st ??= db()->prepare('INSERT INTO stats_daily (day, metric, ref, v) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute([$day, $metric, $ref, $v]);
    }

    /** Dagtotalen van $from tot en met $to opnieuw berekenen (één keer door de tabellen). @return int aantal dagen */
    public static function rollup(string $from, string $to): int
    {
        $a = $from . ' 00:00:00';
        $b = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        q('DELETE FROM stats_daily WHERE day BETWEEN ? AND ? AND metric NOT IN (' . self::snapList() . ')', [$from, $to]);
        $days = [];
        foreach (q('SELECT DATE(created_at) AS d, check_id, COUNT(*) AS n, SUM(status = 1) AS w, SUM(status = 2) AS c, AVG(value) AS av, MAX(value) AS mx
                    FROM results WHERE created_at >= ? AND created_at < ? GROUP BY d, check_id', [$a, $b]) as $r) {
            $d = (string) $r['d'];
            $id = (int) $r['check_id'];
            $days[$d] = true;
            self::put($d, 'c_n', $id, (float) $r['n']);
            self::put($d, 'c_warn', $id, (float) $r['w']);
            self::put($d, 'c_crit', $id, (float) $r['c']);
            if ($r['av'] !== null) {
                self::put($d, 'c_avg', $id, round((float) $r['av'], 3));
                self::put($d, 'c_max', $id, round((float) $r['mx'], 3));
            }
        }
        foreach (q('SELECT DATE(created_at) AS d, check_id, COUNT(*) AS n, SUM(to_status = 2) AS c FROM status_changes
                    WHERE created_at >= ? AND created_at < ? GROUP BY d, check_id', [$a, $b]) as $r) {
            self::put((string) $r['d'], 'c_chg', (int) $r['check_id'], (float) $r['n']);
            self::put((string) $r['d'], 'c_tocrit', (int) $r['check_id'], (float) $r['c']);
        }
        // Beschikbaarheid per server: ping, anders TCP en HTTP (zoals Stats::uptime); niet-CRITICAL telt als bereikbaar
        $up = [];
        foreach (q("SELECT DATE(r.created_at) AS d, c.host_id, c.type = 'ping' AS p, COUNT(*) AS n, SUM(r.status <> 2) AS ok
                    FROM results r JOIN checks c ON c.id = r.check_id
                    WHERE c.type IN ('ping', 'tcp', 'http') AND c.reverse_ok IS NULL AND r.created_at >= ? AND r.created_at < ?
                    GROUP BY d, c.host_id, p", [$a, $b]) as $r) {
            $up[(string) $r['d']][(int) $r['host_id']][(int) $r['p']] = [(float) $r['n'], (float) $r['ok']];
        }
        foreach ($up as $d => $hosts) {
            foreach ($hosts as $hid => $k) {
                [$n, $ok] = $k[1] ?? $k[0];
                self::put($d, 'h_n', $hid, $n);
                self::put($d, 'h_ok', $hid, $ok);
            }
        }
        foreach (q("SELECT DATE(created_at) AS d, kind, COUNT(*) AS n, SUM(sent_ok = 1) AS p, SUM(email_ok = 1) AS m, SUM(webhook_ok = 1) AS h
                    FROM alerts WHERE created_at >= ? AND created_at < ? GROUP BY d, kind", [$a, $b]) as $r) {
            $d = (string) $r['d'];
            self::put($d, 'a_' . preg_replace('/[^a-z]/', '', (string) $r['kind']), 0, (float) $r['n']);
            foreach (['push' => 'p', 'mail' => 'm', 'hook' => 'h'] as $k => $col) {
                $cur = (float) (q('SELECT v FROM stats_daily WHERE day = ? AND metric = ? AND ref = 0', [$d, "a_$k"])->fetchColumn() ?: 0);
                self::put($d, "a_$k", 0, $cur + (float) $r[$col]);
            }
        }
        foreach (q('SELECT DATE(created_at) AS d, severity, COUNT(*) AS n FROM ids_incidents WHERE created_at >= ? AND created_at < ? GROUP BY d, severity', [$a, $b]) as $r) {
            self::put((string) $r['d'], 'ids_' . min(2, (int) $r['severity']), 0, (float) $r['n']);
        }
        foreach (q("SELECT DATE(created_at) AS d, kind, COUNT(*) AS n FROM access_log WHERE created_at >= ? AND created_at < ? GROUP BY d, kind", [$a, $b]) as $r) {
            self::put((string) $r['d'], $r['kind'] === 'authfail' ? 'auth_fail' : 'auth_ok', 0, (float) $r['n']);
        }
        foreach (q('SELECT DATE(created_at) AS d, COUNT(DISTINCT ip) AS n, COUNT(DISTINCT CASE WHEN auto = 1 THEN ip END) AS au FROM ids_blocks
                    WHERE created_at >= ? AND created_at < ? GROUP BY d', [$a, $b]) as $r) {
            self::put((string) $r['d'], 'blk', 0, (float) $r['n']);
            self::put((string) $r['d'], 'blk_auto', 0, (float) $r['au']);
        }
        foreach (q('SELECT DATE(first_seen) AS d, COUNT(*) AS n FROM net_devices WHERE first_seen >= ? AND first_seen < ? GROUP BY d', [$a, $b]) as $r) {
            self::put((string) $r['d'], 'net_new', 0, (float) $r['n']);
        }
        return count($days);
    }

    private static function snapList(): string
    {
        return "'net_total', 'vuln_open', 'vuln_kev', 'cnt_hosts', 'cnt_checks'";
    }

    /** Stand van vandaag: aantallen die je niet achteraf kunt uitrekenen */
    private static function snapshot(string $day): void
    {
        self::put($day, 'cnt_hosts', 0, (float) q('SELECT COUNT(*) FROM hosts WHERE enabled = 1')->fetchColumn());
        self::put($day, 'cnt_checks', 0, (float) q('SELECT COUNT(*) FROM checks WHERE enabled = 1')->fetchColumn());
        self::put($day, 'net_total', 0, (float) q('SELECT COUNT(*) FROM net_devices WHERE last_seen >= NOW() - INTERVAL 1 DAY')->fetchColumn());
        if (Vulns::enabled()) {
            $v = q("SELECT COUNT(DISTINCT f.host_id, f.cve) AS n, COUNT(DISTINCT CASE WHEN c.kev = 1 THEN CONCAT(f.host_id, f.cve) END) AS k
                    FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve
                    WHERE NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.host_id IS NULL OR i.host_id = f.host_id))")->fetch();
            self::put($day, 'vuln_open', 0, (float) $v['n']);
            self::put($day, 'vuln_kev', 0, (float) $v['k']);
        }
    }

    /** AdGuard Home: de top-clients en -geblokkeerde domeinen van de statistiek (elk uur, voor het blok Netwerk) */
    private static function adguardStats(): void
    {
        try {
            save_setting('adguard_stats', json_encode(NetLinks::adguardStats(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------------ rapport

    /** Waarden per dag (metric, ref), opgeteld over refs als $ref null is: [dag => waarde] */
    private static function series(string $from, string $to, string $metric, ?int $ref = null): array
    {
        $rows = $ref === null
            ? q('SELECT day, SUM(v) AS v FROM stats_daily WHERE metric = ? AND day BETWEEN ? AND ? GROUP BY day', [$metric, $from, $to])->fetchAll(PDO::FETCH_KEY_PAIR)
            : q('SELECT day, v FROM stats_daily WHERE metric = ? AND ref = ? AND day BETWEEN ? AND ?', [$metric, $ref, $from, $to])->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $out[$d] = isset($rows[$d]) ? round((float) $rows[$d], 3) : 0.0;
        }
        return $out;
    }

    private static function sum(string $from, string $to, string $metric): float
    {
        return (float) q('SELECT COALESCE(SUM(v), 0) FROM stats_daily WHERE metric = ? AND day BETWEEN ? AND ?', [$metric, $from, $to])->fetchColumn();
    }

    /** Beschikbaarheid (%) over alle servers, of null */
    private static function availability(string $from, string $to): ?float
    {
        $n = self::sum($from, $to, 'h_n');
        return $n > 0 ? round(self::sum($from, $to, 'h_ok') / $n * 100, 3) : null;
    }

    /** Hoe lang een storing duurde (van niet-OK naar OK), uit de statuswissels: [gemiddelde seconden, aantal, per server] */
    private static function mttr(string $from, string $to): array
    {
        $open = [];
        $dur = [];
        $byHost = [];
        $rows = q('SELECT s.check_id, s.from_status, s.to_status, UNIX_TIMESTAMP(s.created_at) AS t, c.host_id FROM status_changes s JOIN checks c ON c.id = s.check_id
                   WHERE s.created_at >= ? AND s.created_at < ? + INTERVAL 1 DAY ORDER BY s.check_id, s.created_at LIMIT 50000', [$from, $to])->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['check_id'];
            if ((int) $r['to_status'] === 2 && !isset($open[$id])) {
                $open[$id] = (int) $r['t'];
            } elseif ((int) $r['to_status'] === 0 && isset($open[$id])) {
                $s = (int) $r['t'] - $open[$id];
                $dur[] = $s;
                $byHost[(int) $r['host_id']][] = $s;
                unset($open[$id]);
            }
        }
        $avg = $dur ? (int) round(array_sum($dur) / count($dur)) : null;
        return [$avg, count($dur), array_map(fn($l) => ['avg' => (int) round(array_sum($l) / count($l)), 'n' => count($l)], $byHost)];
    }

    /** @param string $period 1d|7d|30d|90d|365d of "van..tot" (Y-m-d..Y-m-d) */
    /** report() met een cache van 5 minuten (per periode en taal); Analyse en het Dashboard (1.134.0) delen hem */
    public static function cached(string $period, bool $fresh = false): array
    {
        $cf = sys_get_temp_dir() . '/rzmon-analytics-' . md5(SM_ROOT . '|' . $period . '|' . I18n::lang()) . '.json';
        if (!$fresh && is_file($cf) && filemtime($cf) > time() - 300 && ($c = json_decode((string) file_get_contents($cf), true))) {
            return $c + ['cached' => true];
        }
        if (!q('SELECT 1 FROM stats_daily LIMIT 1')->fetchColumn()) {
            @set_time_limit(300);
            self::cron();   // de eerste keer meteen berekenen, niet op cron wachten
        }
        $r = self::report($period);
        @file_put_contents($cf, json_encode($r));
        return $r;
    }

    public static function report(string $period): array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $period, $m) && $m[1] <= $m[2]) {
            [$from, $to] = [$m[1], $m[2]];
        } else {
            $days = ['1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365][$period] ?? 30;
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime("-" . ($days - 1) . ' days'));
        }
        $len = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $pFrom = date('Y-m-d', strtotime("$from -$len days"));
        $pTo = date('Y-m-d', strtotime("$from -1 day"));
        [$mttr, $nInc, $mttrHost] = self::mttr($from, $to);
        [$pMttr] = self::mttr($pFrom, $pTo);
        $alertKinds = ['a_notify', 'a_ids', 'a_sec'];
        $alerts = array_sum(array_map(fn($k) => self::sum($from, $to, $k), $alertKinds));
        $pAlerts = array_sum(array_map(fn($k) => self::sum($pFrom, $pTo, $k), $alertKinds));
        $sec = self::sum($from, $to, 'ids_1') + self::sum($from, $to, 'ids_2');
        $pSec = self::sum($pFrom, $pTo, 'ids_1') + self::sum($pFrom, $pTo, 'ids_2');
        $hosts = q('SELECT id, name FROM hosts')->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [
            'from' => $from, 'to' => $to, 'days' => $len, 'prev' => [$pFrom, $pTo],
            'since' => q('SELECT MIN(day) FROM stats_daily')->fetchColumn() ?: null,
            'kpi' => [
                'avail' => [self::availability($from, $to), self::availability($pFrom, $pTo), self::availSeries($from, $to)],
                'incidents' => [self::sum($from, $to, 'c_tocrit'), self::sum($pFrom, $pTo, 'c_tocrit'), self::series($from, $to, 'c_tocrit')],
                'mttr' => [$mttr, $pMttr, $nInc],
                'alerts' => [$alerts, $pAlerts, self::series($from, $to, 'a_notify')],
                'security' => [$sec, $pSec, self::series($from, $to, 'ids_2')],
            ],
            'avail_series' => self::availSeries($from, $to),
        ];
        $out['reliability'] = self::reliability($from, $to, $hosts, $mttrHost);
        $out['capacity'] = self::capacity($from, $to, $hosts);
        $out['security'] = self::security($from, $to, $hosts);
        $out['network'] = self::network($from, $to);
        $out['backups'] = self::backups($hosts);
        $out['noise'] = self::noise($from, $to);
        $out['todo'] = self::todo($out);
        $out['kpi']['score'] = self::score($out['todo']);
        return $out;
    }

    /** Beschikbaarheid per dag over alle servers (%) */
    private static function availSeries(string $from, string $to): array
    {
        $n = self::series($from, $to, 'h_n');
        $ok = self::series($from, $to, 'h_ok');
        $out = [];
        foreach ($n as $d => $v) {
            $out[$d] = $v > 0 ? round($ok[$d] / $v * 100, 2) : null;
        }
        return $out;
    }

    private static function reliability(string $from, string $to, array $hosts, array $mttrHost): array
    {
        $per = [];
        foreach (q('SELECT ref, SUM(CASE WHEN metric = \'h_n\' THEN v END) AS n, SUM(CASE WHEN metric = \'h_ok\' THEN v END) AS ok, COUNT(DISTINCT day) AS d
                    FROM stats_daily WHERE metric IN (\'h_n\', \'h_ok\') AND day BETWEEN ? AND ? GROUP BY ref', [$from, $to]) as $r) {
            if (!isset($hosts[(int) $r['ref']]) || (float) $r['n'] <= 0) {
                continue;
            }
            $pct = (float) $r['ok'] / (float) $r['n'] * 100;
            // Onbereikbaar in minuten: alleen over de dagen waarop er gemeten is
            $per[] = ['host_id' => (int) $r['ref'], 'name' => $hosts[(int) $r['ref']], 'pct' => round($pct, 3),
                'down_min' => (int) round((100 - $pct) / 100 * min((int) $r['d'], self::days($from, $to)) * 1440), 'mttr' => $mttrHost[(int) $r['ref']] ?? null];
        }
        usort($per, fn($a, $b) => $a['pct'] <=> $b['pct']);
        // Heatmap: storingen (naar CRITICAL) per weekdag en uur
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        foreach (q('SELECT WEEKDAY(created_at) AS w, HOUR(created_at) AS h, COUNT(*) AS n FROM status_changes WHERE to_status = 2 AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY
                    GROUP BY w, h', [$from, $to]) as $r) {
            $heat[(int) $r['w']][(int) $r['h']] = (int) $r['n'];
        }
        // Lastigste checks: vaakst van status gewisseld, en het deel van de metingen dat niet OK was
        $top = q("SELECT c.id, c.name, c.type, h.id AS host_id, h.name AS host, SUM(CASE WHEN s.metric = 'c_chg' THEN s.v END) AS chg,
                         SUM(CASE WHEN s.metric = 'c_tocrit' THEN s.v END) AS crit, SUM(CASE WHEN s.metric = 'c_n' THEN s.v END) AS n,
                         SUM(CASE WHEN s.metric IN ('c_warn', 'c_crit') THEN s.v END) AS bad
                  FROM stats_daily s JOIN checks c ON c.id = s.ref JOIN hosts h ON h.id = c.host_id
                  WHERE s.metric IN ('c_chg', 'c_tocrit', 'c_n', 'c_warn', 'c_crit') AND s.day BETWEEN ? AND ? AND c.info_only = 0
                  GROUP BY c.id HAVING chg > 0 OR bad > 0 ORDER BY chg DESC, bad DESC LIMIT 10", [$from, $to])->fetchAll();
        foreach ($top as &$t) {
            $t['bad_pct'] = (float) $t['n'] > 0 ? round((float) $t['bad'] / (float) $t['n'] * 100, 1) : 0;
            $t['last'] = q('SELECT message, created_at FROM status_changes WHERE check_id = ? AND to_status <> 0 ORDER BY id DESC LIMIT 1', [$t['id']])->fetch() ?: null;
        }
        unset($t);
        // Responstijden: ping- en HTTP-checks, gemiddelde per dag
        $resp = [];
        foreach (q("SELECT c.id, c.name, c.type, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND c.type IN ('ping', 'http') ORDER BY h.name LIMIT 30")->fetchAll() as $c) {
            $s = self::series($from, $to, 'c_avg', (int) $c['id']);
            $vals = array_filter($s, fn($v) => $v > 0);
            if (!$vals) {
                continue;
            }
            $half = (int) floor(count($vals) / 2);
            $first = array_slice($vals, 0, max(1, $half));
            $last = array_slice($vals, $half);
            $resp[] = $c + ['series' => $s, 'avg' => round(array_sum($vals) / count($vals), 1), 'max' => max($vals),
                'trend' => $half > 0 && array_sum($first) > 0 ? round((array_sum($last) / count($last)) / (array_sum($first) / count($first)) * 100 - 100) : null];
        }
        usort($resp, fn($a, $b) => $b['avg'] <=> $a['avg']);
        // Herstarts: info-meldingen van de uptime-check
        $reboots = q("SELECT h.name, COUNT(*) AS n FROM status_changes s JOIN checks c ON c.id = s.check_id JOIN hosts h ON h.id = c.host_id
                      WHERE c.type = 'reboot' AND s.created_at >= ? AND s.created_at < ? + INTERVAL 1 DAY GROUP BY h.id ORDER BY n DESC LIMIT 10", [$from, $to])->fetchAll();
        return ['hosts' => $per, 'heat' => $heat, 'top' => $top, 'resp' => array_slice($resp, 0, 12), 'reboots' => $reboots];
    }

    private static function days(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    }

    private static function capacity(string $from, string $to, array $hosts): array
    {
        $disks = [];
        foreach (q("SELECT c.id, c.target, h.name AS host, h.id AS host_id, (SELECT value FROM results r WHERE r.check_id = c.id ORDER BY r.id DESC LIMIT 1) AS cur
                    FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.type = 'disk' AND c.enabled = 1")->fetchAll() as $d) {
            if ($d['cur'] === null) {
                continue;
            }
            $f = Stats::diskForecast((int) $d['id'], (float) $d['cur']);
            $disks[] = ['id' => (int) $d['id'], 'host' => $d['host'], 'mount' => $d['target'], 'pct' => round((float) $d['cur'], 1),
                'per_day' => $f['per_day'] ?? null, 'days' => $f['days_to_full'] ?? null, 'series' => self::series($from, $to, 'c_avg', (int) $d['id'])];
        }
        usort($disks, fn($a, $b) => [$a['days'] ?? 99999, -$a['pct']] <=> [$b['days'] ?? 99999, -$b['pct']]);
        $res = [];
        foreach (q("SELECT c.id, c.type, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND c.type IN ('cpu', 'ram') ORDER BY h.name")->fetchAll() as $c) {
            $avg = q("SELECT AVG(v) FROM stats_daily WHERE metric = 'c_avg' AND ref = ? AND day BETWEEN ? AND ?", [$c['id'], $from, $to])->fetchColumn();
            $max = q("SELECT MAX(v) FROM stats_daily WHERE metric = 'c_max' AND ref = ? AND day BETWEEN ? AND ?", [$c['id'], $from, $to])->fetchColumn();
            if ($avg === null) {
                continue;
            }
            $res[$c['host']][$c['type']] = ['avg' => round((float) $avg, 1), 'max' => round((float) $max, 1)];
        }
        $pve = [];
        foreach (q("SELECT d.host_id, d.value, h.name FROM agent_data d JOIN hosts h ON h.id = d.host_id WHERE d.metric LIKE 'pveg:%'")->fetchAll() as $r) {
            $g = json_decode((string) $r['value'], true);
            if ($g && $g['status'] === 'running') {
                $pve[] = ['host' => $r['name'], 'vmid' => $g['vmid'], 'name' => $g['name'], 'kind' => $g['kind'], 'cpu' => $g['cpu'], 'mem' => $g['mem'], 'maxmem' => $g['maxmem']];
            }
        }
        usort($pve, fn($a, $b) => $b['mem'] <=> $a['mem']);
        return ['disks' => array_slice($disks, 0, 20), 'res' => $res, 'pve' => array_slice($pve, 0, 10),
            'growth' => ['hosts' => self::series($from, $to, 'cnt_hosts'), 'checks' => self::series($from, $to, 'cnt_checks')],
            'now' => ['hosts' => count($hosts), 'checks' => (int) q('SELECT COUNT(*) FROM checks WHERE enabled = 1')->fetchColumn()]];
    }

    private static function security(string $from, string $to, array $hosts): array
    {
        $b = [$from . ' 00:00:00', date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00'];
        $cc = q('SELECT cc, COUNT(*) AS n FROM ids_incidents WHERE created_at >= ? AND created_at < ? AND cc IS NOT NULL GROUP BY cc ORDER BY n DESC LIMIT 12', $b)->fetchAll();
        $ips = q('SELECT ip, MAX(cc) AS cc, COUNT(*) AS n, SUM(hits) AS hits, COUNT(DISTINCT host_id) AS hosts FROM ids_incidents
                  WHERE created_at >= ? AND created_at < ? AND ip IS NOT NULL GROUP BY ip ORDER BY hits DESC LIMIT 10', $b)->fetchAll();
        foreach ($ips as &$i) {
            $i['blocked'] = (bool) q('SELECT 1 FROM ids_blocks WHERE ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW())', [$i['ip']])->fetchColumn()
                || (bool) q('SELECT 1 FROM rz_blocks WHERE net = ?', [$i['ip']])->fetchColumn();
        }
        unset($i);
        $targets = array_map(fn($r) => ['name' => $hosts[(int) $r['host_id']] ?? '?', 'n' => (int) $r['n']],
            q('SELECT host_id, COUNT(*) AS n FROM ids_incidents WHERE created_at >= ? AND created_at < ? GROUP BY host_id ORDER BY n DESC LIMIT 8', $b)->fetchAll());
        $types = q('SELECT type, COUNT(*) AS n FROM ids_incidents WHERE created_at >= ? AND created_at < ? GROUP BY type ORDER BY n DESC LIMIT 10', $b)->fetchAll();
        $logins = ['ok' => 0, 'outside' => 0, 'fail' => 0, 'night' => 0];
        foreach (q("SELECT kind, detail, HOUR(created_at) AS h FROM access_log WHERE created_at >= ? AND created_at < ? AND type IN ('ssh', 'console') LIMIT 20000", $b) as $r) {
            if ($r['kind'] === 'authfail') {
                $logins['fail']++;
                continue;
            }
            $logins['ok']++;
            $ip = (string) ((json_decode((string) $r['detail'], true) ?: [])['ip'] ?? '');
            if ($ip !== '' && !ip_in_list($ip, PRIVATE_NETWORKS)) {
                $logins['outside']++;
            }
            if ((int) $r['h'] < 6) {
                $logins['night']++;
            }
        }
        $vulns = null;
        if (Vulns::enabled()) {
            $sev = q("SELECT COALESCE(c.severity, 0) AS s, COUNT(DISTINCT f.host_id, f.cve) AS n, COUNT(DISTINCT CASE WHEN f.fixed_version IS NOT NULL THEN CONCAT(f.host_id, f.cve) END) AS fix
                      FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve
                      WHERE NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.host_id IS NULL OR i.host_id = f.host_id)) GROUP BY s")->fetchAll();
            $vulns = ['by_sev' => $sev, 'series' => self::series($from, $to, 'vuln_open'), 'kev' => self::series($from, $to, 'vuln_kev')];
        }
        $hard = [];
        foreach (q('SELECT host_id, SUM(ok = 1) AS ok, SUM(ok IS NOT NULL) AS n FROM ids_hardening WHERE ignored = 0 GROUP BY host_id')->fetchAll() as $r) {
            if ((int) $r['n'] > 0 && isset($hosts[(int) $r['host_id']])) {
                $hard[] = ['name' => $hosts[(int) $r['host_id']], 'score' => (int) round((int) $r['ok'] / (int) $r['n'] * 100)];
            }
        }
        usort($hard, fn($a, $b) => $a['score'] <=> $b['score']);
        return ['series' => ['auth_fail' => self::series($from, $to, 'auth_fail'), 'ids' => self::series($from, $to, 'ids_2'),
                'ids_warn' => self::series($from, $to, 'ids_1'), 'blk' => self::series($from, $to, 'blk')],
            'blk_auto' => self::sum($from, $to, 'blk_auto'), 'blk_total' => self::sum($from, $to, 'blk'),
            'countries' => $cc, 'ips' => $ips, 'targets' => $targets, 'types' => $types, 'logins' => $logins, 'vulns' => $vulns, 'hardening' => $hard];
    }

    private static function network(string $from, string $to): array
    {
        if (!NetScan::enabled()) {
            return ['on' => false];
        }
        $devs = NetScan::list();
        $kinds = [];
        $vendors = [];
        $aps = [];
        $ssids = [];
        $risky = [];
        foreach ($devs as $d) {
            if ($d['status'] === 'ignored') {
                continue;
            }
            $k = $d['kind_user'] ?: $d['kind'];
            $kinds[$k] = ($kinds[$k] ?? 0) + 1;
            if ($d['vendor']) {
                $vendors[$d['vendor']] = ($vendors[$d['vendor']] ?? 0) + 1;
            }
            $u = $d['info']['unifi'] ?? null;
            if ($u && !empty($u['ap'])) {
                $aps[$u['ap']] = ($aps[$u['ap']] ?? 0) + 1;
            }
            if ($u && !empty($u['essid'])) {
                $ssids[$u['essid']] = ($ssids[$u['essid']] ?? 0) + 1;
            }
            if ($d['risky']) {
                $risky[] = ['id' => (int) $d['id'], 'label' => $d['label'], 'ip' => $d['ip'], 'ports' => $d['risky']];
            }
        }
        arsort($kinds);
        arsort($vendors);
        arsort($aps);
        arsort($ssids);
        return ['on' => true, 'total' => count($devs), 'new_open' => count(array_filter($devs, fn($d) => $d['status'] === 'new')),
            'blocked' => count(array_filter($devs, fn($d) => $d['blocked'])),
            'series' => ['total' => self::series($from, $to, 'net_total'), 'new' => self::series($from, $to, 'net_new')],
            'kinds' => $kinds, 'vendors' => array_slice($vendors, 0, 10, true), 'aps' => $aps, 'ssids' => $ssids, 'risky' => $risky,
            'adguard' => json_decode((string) setting('adguard_stats', ''), true) ?: null];
    }

    private static function backups(array $hosts): array
    {
        $pve = [];
        foreach (q("SELECT d.host_id, d.value FROM agent_data d WHERE d.metric LIKE 'pveg:%'")->fetchAll() as $r) {
            $g = json_decode((string) $r['value'], true);
            if ($g && !$g['template']) {
                $pve[] = ['host' => $hosts[(int) $r['host_id']] ?? '?', 'vmid' => $g['vmid'], 'name' => $g['name'], 'kind' => $g['kind'],
                    'last' => $g['bk_last'], 'job' => $g['job'], 'onboot' => $g['onboot']];
            }
        }
        usort($pve, fn($a, $b) => ($a['last'] ?? 0) <=> ($b['last'] ?? 0));
        $tasks = [];
        foreach (q("SELECT d.host_id, d.value FROM agent_data d WHERE d.metric = 'pve'")->fetchAll() as $r) {
            $n = json_decode((string) $r['value'], true) ?: [];
            $tasks[] = ['host' => $hosts[(int) $r['host_id']] ?? '?', 'last' => $n['last_task'] ?? null, 'fail' => $n['last_fail'] ?? null, 'jobs' => count($n['jobs'] ?? [])];
        }
        $hb = q("SELECT c.id, c.name, h.name AS host, c.status, c.last_message AS msg, s.data FROM checks c JOIN hosts h ON h.id = c.host_id
                 LEFT JOIN hb_state s ON s.check_id = c.id WHERE c.type = 'heartbeat' AND c.enabled = 1 ORDER BY c.status DESC, c.name")->fetchAll();
        foreach ($hb as &$x) {
            $d = json_decode((string) ($x['data'] ?? ''), true) ?: [];
            $x['last'] = $d['end'] ?? null;
            $x['result'] = $d['last'] ?? null;
            $x['dur'] = $d['dur'] ?? null;
            unset($x['data']);
        }
        unset($x);
        $own = Backup::list()[0]['at'] ?? null;
        return ['pve' => $pve, 'tasks' => $tasks, 'hb' => $hb, 'rzmon' => $own, 'tasks_rz' => array_values(array_filter(Tasks::list(), fn($t) => $t['status'] === 'bad' || $t['status'] === 'warn'))];
    }

    private static function noise(string $from, string $to): array
    {
        // Snelle herstellers: van OK naar niet-OK en binnen 5 minuten weer OK (kandidaten voor "melden na N fouten")
        $quick = [];
        $prev = [];
        foreach (q('SELECT s.check_id, s.to_status, UNIX_TIMESTAMP(s.created_at) AS t FROM status_changes s WHERE s.created_at >= ? AND s.created_at < ? + INTERVAL 1 DAY
                    ORDER BY s.check_id, s.created_at LIMIT 50000', [$from, $to]) as $r) {
            $id = (int) $r['check_id'];
            if ((int) $r['to_status'] !== 0) {
                $prev[$id] ??= (int) $r['t'];
            } elseif (isset($prev[$id])) {
                if ((int) $r['t'] - $prev[$id] <= 300) {
                    $quick[$id] = ($quick[$id] ?? 0) + 1;
                }
                unset($prev[$id]);
            }
        }
        arsort($quick);
        $top = [];
        foreach (array_slice($quick, 0, 5, true) as $id => $n) {
            if ($c = q('SELECT c.id, c.name, h.name AS host, c.notify_fails FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch()) {
                $top[] = $c + ['n' => $n];
            }
        }
        $byCheck = q("SELECT a.check_id, c.name, h.name AS host, COUNT(*) AS n FROM alerts a JOIN checks c ON c.id = a.check_id JOIN hosts h ON h.id = c.host_id
                      WHERE a.kind = 'notify' AND a.created_at >= ? AND a.created_at < ? + INTERVAL 1 DAY GROUP BY a.check_id ORDER BY n DESC LIMIT 5", [$from, $to])->fetchAll();
        return ['series' => ['push' => self::series($from, $to, 'a_push'), 'mail' => self::series($from, $to, 'a_mail'), 'hook' => self::series($from, $to, 'a_hook'),
                'notify' => self::series($from, $to, 'a_notify'), 'info' => self::series($from, $to, 'a_info')],
            'totals' => ['push' => self::sum($from, $to, 'a_push'), 'mail' => self::sum($from, $to, 'a_mail'), 'hook' => self::sum($from, $to, 'a_hook'),
                'notify' => self::sum($from, $to, 'a_notify'), 'info' => self::sum($from, $to, 'a_info'), 'ids' => self::sum($from, $to, 'a_ids'), 'sec' => self::sum($from, $to, 'a_sec')],
            'quick' => $top, 'top' => $byCheck];
    }

    /** Wat nu aanpakken: de belangrijkste punten, gesorteerd (2 = dringend, 1 = aandacht, 0 = tip) */
    private static function todo(array $r): array
    {
        $out = [];
        foreach ($r['capacity']['disks'] as $d) {
            if ($d['days'] !== null && $d['days'] <= 30) {
                $out[] = [$d['days'] <= 7 ? 2 : 1, '💽', t('Disk {m} op {h} raakt vol over ~{n} dagen ({p}%)', ['m' => $d['mount'], 'h' => $d['host'], 'n' => (int) round($d['days']), 'p' => $d['pct']]), 'dashboard'];
            } elseif ($d['pct'] >= 90) {
                $out[] = [2, '💽', t('Disk {m} op {h} is {p}% vol', ['m' => $d['mount'], 'h' => $d['host'], 'p' => $d['pct']]), 'dashboard'];
            }
        }
        foreach ($r['backups']['pve'] as $g) {
            $label = ($g['kind'] === 'ct' ? 'CT' : 'VM') . " {$g['vmid']} ({$g['name']})";
            if (!$g['last']) {
                $out[] = [2, '💾', t('{g} op {h} heeft nog nooit een back-up gehad', ['g' => $label, 'h' => $g['host']]), 'dashboard'];
            } elseif ($g['last'] < time() - 7 * 86400) {
                $out[] = [1, '💾', t('{g} op {h}: laatste back-up {n} dagen geleden', ['g' => $label, 'h' => $g['host'], 'n' => intdiv(time() - $g['last'], 86400)]), 'dashboard'];
            } elseif ($g['job'] === 0) {
                $out[] = [1, '💾', t('{g} op {h} zit in geen enkele back-uptaak', ['g' => $label, 'h' => $g['host']]), 'dashboard'];
            }
        }
        $flap = 0;
        foreach ($r['reliability']['top'] as $c) {
            // Vaak = gemiddeld meer dan één wissel per dag, en minstens 10; hooguit 5 van deze punten
            if ((float) $c['chg'] >= max(10, $r['days'] * 1.5) && ++$flap <= 5) {
                $out[] = [1, '🔁', t('{c} op {h} wisselt vaak van status ({n}× in deze periode): zet "melden na N fouten" hoger of pas de drempels aan', ['c' => $c['name'], 'h' => $c['host'], 'n' => (int) $c['chg']]), 'check:' . $c['id']];
            }
        }
        foreach ($r['reliability']['hosts'] as $h) {
            if ($h['pct'] < 99) {
                $out[] = [$h['pct'] < 95 ? 2 : 1, '🌐', t('{h} was {p}% bereikbaar (~{m} min onbereikbaar)', ['h' => $h['name'], 'p' => I18n::dec((string) round($h['pct'], 2)), 'm' => $h['down_min']]), 'dashboard'];
            }
        }
        if ($r['network']['on'] ?? false) {
            foreach ($r['network']['risky'] as $d) {
                $out[] = [1, '⚠️', t('{d} ({ip}) heeft een riskante poort open: {p}', ['d' => $d['label'], 'ip' => $d['ip'], 'p' => implode(', ', $d['ports'])]), 'net'];
            }
            if ($r['network']['new_open'] > 0) {
                $out[] = [0, '🆕', t('{n} nieuwe apparaten op je netwerk nog niet beoordeeld', ['n' => $r['network']['new_open']]), 'net'];
            }
        }
        if ($v = $r['security']['vulns']) {
            $fix = array_sum(array_map(fn($x) => (int) $x['fix'], $v['by_sev']));
            $crit = array_sum(array_map(fn($x) => (int) $x['s'] >= 4 ? (int) $x['n'] : 0, $v['by_sev']));
            if ($fix > 0) {
                $out[] = [$crit > 0 ? 2 : 1, '🐞', t('{n} kwetsbaarheden hebben een oplossing: bijwerken ({c} critical)', ['n' => $fix, 'c' => $crit]), 'vulns'];
            }
        }
        foreach ($r['security']['hardening'] as $h) {
            if ($h['score'] < 70) {
                $out[] = [1, '🧰', t('Hardening-score van {h} is {s}/100', ['h' => $h['name'], 's' => $h['score']]), 'ids'];
            }
        }
        $openCrit = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE status = 'open' AND severity = 2")->fetchColumn();
        if ($openCrit > 0) {
            $out[] = [2, '🛡️', t('{n} critical IDS-incidenten staan nog open', ['n' => $openCrit]), 'ids'];
        }
        foreach ($r['noise']['quick'] as $c) {
            if ($c['n'] >= 3) {
                $out[] = [0, '🔕', t('{c} op {h} herstelde {n}× binnen 5 minuten: met "melden na 3 fouten" krijg je minder ruis', ['c' => $c['name'], 'h' => $c['host'], 'n' => $c['n']]), 'check:' . $c['id']];
            }
        }
        foreach ($r['backups']['hb'] as $x) {
            if ((int) $x['status'] === 2) {
                $out[] = [2, '💓', t('Heartbeat {c} ({h}) is te laat of mislukt', ['c' => preg_replace('/^heartbeat\s+/i', '', (string) $x['name']), 'h' => $x['host']]), 'check:' . $x['id']];
            }
        }
        $lastBk = $r['backups']['rzmon'];
        if (!$lastBk || strtotime((string) $lastBk) < time() - 8 * 86400) {
            $out[] = [1, '🗄️', $lastBk ? t('De laatste back-up van RzMon zelf is van {d}', ['d' => date('d-m-Y', strtotime((string) $lastBk))]) : t('Er is nog geen back-up van RzMon zelf'), 'settings/backup'];
        }
        // 1.108.0: slimme voorstellen die openstaan (melden na N, vaste onderhoudsvensters, afhankelijkheden)
        if ($nAdv = Advice::openCount()) {
            $out[] = [0, '💡', t('{n} slimme voorstel(len) om te bekijken (melden na N, vaste onderhoudsvensters, afhankelijkheden)', ['n' => $nAdv]), 'settings/advice'];
        }
        // 1.107.0: handmatige checks die met slimme drempels flink minder warnings hadden gegeven
        $gain = array_filter(Smart::proposals(), static fn($p) => $p['gain'] >= 3);
        if ($gain) {
            $out[] = [0, '🧠', t('{n} check(s) zouden met slimme drempels minder warnings geven (bv. {c} op {h}: {a} → {b} dagen)',
                ['n' => count($gain), 'c' => $gain[0]['name'], 'h' => $gain[0]['host'], 'a' => $gain[0]['fixed'], 'b' => $gain[0]['smart']]), 'settings/smart'];
        }
        usort($out, fn($a, $b) => $b[0] <=> $a[0]);
        return array_map(fn($x) => ['sev' => $x[0], 'icon' => $x[1], 'text' => $x[2], 'go' => $x[3]], array_slice($out, 0, 25));
    }

    /** Score 0–100: begint op 100; dringend kost 10 (hooguit 50), aandacht 4 (hooguit 35), tips 1 (hooguit 15) */
    private static function score(array $todo): int
    {
        $n = [0, 0, 0];
        foreach ($todo as $t) {
            $n[$t['sev']]++;
        }
        return max(0, 100 - min(50, $n[2] * 10) - min(35, $n[1] * 4) - min(15, $n[0]));
    }

    // ------------------------------------------------------------------ maandrapport

    public static function reportOn(): bool
    {
        return setting('report_monthly', '0') === '1';
    }

    /** Vanuit cron: op de 1e van de maand (na 07:00) het rapport van de vorige maand versturen */
    public static function monthlyCron(): ?string
    {
        if (!self::reportOn() || (int) date('j') !== 1 || (int) date('G') < 7) {
            return null;
        }
        $month = date('Y-m', strtotime('first day of last month'));
        if (setting('report_last', '') === $month) {
            return null;
        }
        save_setting('report_last', $month);
        $r = self::sendMonthly($month);
        return 'Maandrapport ' . $month . ': ' . ($r['ok'] ? 'verstuurd' : 'mislukt (' . ($r['error'] ?? '?') . ')');
    }

    /** Het rapport van een maand (YYYY-MM) als tekst, per e-mail. @return array{ok: bool, error?: string} */
    public static function sendMonthly(string $month): array
    {
        $from = "$month-01";
        $to = date('Y-m-t', strtotime($from));
        $r = I18n::forNotify(fn() => self::report("$from..$to"));
        $body = I18n::forNotify(fn() => self::monthlyText($r, $month));
        $subject = I18n::forNotify(fn() => t('RzMon-maandrapport {m}', ['m' => strftime_month($month)]));
        return Mailer::send($subject, $body);
    }

    private static function pctDelta(?float $now, ?float $prev, bool $lowerIsBetter = false): string
    {
        if ($now === null || $prev === null || $prev == 0.0) {
            return '';
        }
        $d = ($now - $prev) / $prev * 100;
        $arrow = $d > 0 ? '▲' : ($d < 0 ? '▼' : '=');
        return " ($arrow " . abs((int) round($d)) . '% ' . t('t.o.v. vorige maand') . ')';
    }

    private static function monthlyText(array $r, string $month): string
    {
        $k = $r['kpi'];
        $dur = fn(?int $s) => $s === null ? '–' : ($s >= 3600 ? I18n::dec((string) round($s / 3600, 1)) . ' ' . t('uur') : max(1, (int) round($s / 60)) . ' min');
        $l = [t('RzMon-maandrapport {m}', ['m' => strftime_month($month)]), str_repeat('=', 40), '',
            t('Score: {s}/100', ['s' => $k['score']]),
            t('Beschikbaarheid: {p}%', ['p' => $k['avail'][0] !== null ? I18n::dec((string) round($k['avail'][0], 2)) : '–']) . self::pctDelta($k['avail'][0], $k['avail'][1]),
            t('Storingen (naar critical): {n}', ['n' => (int) $k['incidents'][0]]) . self::pctDelta($k['incidents'][0], $k['incidents'][1], true),
            t('Gemiddelde hersteltijd: {t}', ['t' => $dur($k['mttr'][0])]),
            t('Meldingen: {n}', ['n' => (int) $k['alerts'][0]]),
            t('IDS-incidenten (critical): {n}', ['n' => (int) $k['security'][0]]), ''];
        $l[] = t('Minst bereikbaar:');
        foreach (array_slice($r['reliability']['hosts'], 0, 5) as $h) {
            $l[] = "  • {$h['name']}: " . I18n::dec((string) round($h['pct'], 2)) . '% (~' . $h['down_min'] . ' min)';
        }
        $l[] = '';
        $l[] = t('Wat nu aanpakken:');
        foreach (array_slice($r['todo'], 0, 10) as $t) {
            $l[] = '  ' . ['ℹ️', '⚠️', '🔴'][$t['sev']] . ' ' . $t['text'];
        }
        if (!$r['todo']) {
            $l[] = '  ✔ ' . t('niets dringends');
        }
        $l[] = '';
        $l[] = t('Meer in RzMon: tabblad 📊 Analyse.');
        return implode("\n", $l);
    }
}

/** "oktober 2026" in de taal van meldingen */
function strftime_month(string $month): string
{
    $names = I18n::lang() === 'en'
        ? ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
        : ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    return $names[(int) substr($month, 5, 2) - 1] . ' ' . substr($month, 0, 4);
}
