<?php
declare(strict_types=1);

/**
 * Dashboard (1.134.0): de startpagina met alles samen: monitoring, IDS, kwetsbaarheden en netwerk.
 * De trends en de lijst "wat nu aanpakken" komen uit Analyse (zelfde cache van 5 minuten); de tellers van nu
 * worden hier live opgehaald. De servers en checks zelf komen in de browser uit `state` (die is er al).
 * Alleen-lezen-accounts zien wat ze elders ook zien: monitoring en IDS, geen analyse, CVE's of netwerkapparaten.
 */
final class Home
{
    public const PERIODS = ['7d', '30d', '90d'];

    /** @param bool $full gebruiker of beheerder (alles); anders alleen-lezen */
    public static function data(bool $full, string $period, bool $fresh = false): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : '7d';
        $out = ['period' => $period, 'full' => $full, 'ids' => self::ids(), 'events' => self::events($full), 'resources' => self::resources(), 'incidents' => self::incidents()];
        if (!$full) {
            return $out;
        }
        $r = Analytics::cached($period, $fresh);
        $out += [
            'from' => $r['from'], 'to' => $r['to'], 'since' => $r['since'],
            'score' => $r['kpi']['score'],
            'avail' => ['now' => $r['kpi']['avail'][0], 'prev' => $r['kpi']['avail'][1], 'series' => $r['avail_series']],
            'incidents' => ['now' => $r['kpi']['incidents'][0], 'prev' => $r['kpi']['incidents'][1]],
            'todo' => $r['todo'],
        ];
        $out['ids'] += ['series' => ['blk' => $r['security']['series']['blk'], 'warn' => $r['security']['series']['ids_warn'], 'crit' => $r['security']['series']['ids']],
            'blk_period' => $r['security']['blk_total']];
        $out['vulns'] = Vulns::enabled() ? self::vulns($r['security']['vulns']['series'] ?? []) : null;
        $out['net'] = ($r['network']['on'] ?? false) ? self::net($r['network']) : null;
        return $out;
    }

    /** 1.135.0: CPU en RAM over alle servers, gemiddeld per uur over de laatste 24 uur (24 waarden, null = geen meting) */
    private static function resources(): array
    {
        $now = intdiv(time(), 3600);
        $out = ['cpu' => array_fill(0, 24, null), 'ram' => array_fill(0, 24, null), 'hosts' => 0, 'start' => ($now - 23) * 3600];
        foreach (q("SELECT c.type, FLOOR(UNIX_TIMESTAMP(r.created_at) / 3600) AS b, AVG(r.value) AS v
                    FROM checks c JOIN results r ON r.check_id = c.id AND r.created_at >= NOW() - INTERVAL 24 HOUR
                    WHERE c.type IN ('cpu', 'ram') AND c.enabled = 1 AND r.value IS NOT NULL GROUP BY c.type, b") as $row) {
            $i = (int) $row['b'] - ($now - 23);
            if ($i >= 0 && $i < 24) {
                $out[$row['type']][$i] = round((float) $row['v'], 1);
            }
        }
        $out['hosts'] = (int) q("SELECT COUNT(DISTINCT host_id) FROM checks WHERE type IN ('cpu', 'ram') AND enabled = 1")->fetchColumn();
        return $out;
    }

    /** 1.135.0: de laatste IDS-incidenten (open eerst), voor het paneel "Recente incidenten" */
    private static function incidents(): array
    {
        $rows = q("SELECT i.id, i.severity, i.title, i.status, i.hits, i.last_seen, h.name AS host FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                   WHERE i.last_seen >= NOW() - INTERVAL 14 DAY ORDER BY i.status = 'open' DESC, i.severity DESC, i.last_seen DESC LIMIT 6")->fetchAll();
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'sev' => (int) $r['severity'], 'title' => (string) $r['title'], 'open' => $r['status'] === 'open',
            'hits' => (int) $r['hits'], 'host' => (string) $r['host'], 'at' => (string) $r['last_seen']], $rows);
    }

    /** Open incidenten (warning/critical) en wat er vandaag en nu geblokkeerd is */
    private static function ids(): array
    {
        $open = [1 => 0, 2 => 0];
        foreach (q("SELECT severity, COUNT(*) AS n FROM ids_incidents WHERE status = 'open' GROUP BY severity") as $row) {
            $open[min(2, max(1, (int) $row['severity']))] += (int) $row['n'];
        }
        return ['open' => $open, 'open_total' => $open[1] + $open[2],
            'blk_today' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE created_at >= CURDATE()')->fetchColumn(),
            'blk_now' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchColumn()];
    }

    /** Open kwetsbaarheden per ernst (uniek per server en CVE, zonder genegeerde), met een oplossing, in KEV en de servers met de meeste */
    private static function vulns(array $series): array
    {
        $open = "NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.host_id IS NULL OR i.host_id = f.host_id) AND (i.until IS NULL OR i.until >= CURDATE()))";
        $sev = [4 => 0, 3 => 0, 2 => 0, 1 => 0, 0 => 0];
        $fix = 0;
        foreach (q("SELECT COALESCE(c.severity, 0) AS s, COUNT(DISTINCT f.host_id, f.cve) AS n,
                    COUNT(DISTINCT CASE WHEN f.fixed_version IS NOT NULL THEN CONCAT(f.host_id, '|', f.cve) END) AS fix
                    FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve WHERE $open GROUP BY s") as $row) {
            $sev[min(4, (int) $row['s'])] += (int) $row['n'];
            $fix += (int) $row['fix'];
        }
        $kev = (int) q("SELECT COUNT(DISTINCT f.host_id, f.cve) FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve AND c.kev = 1 WHERE $open")->fetchColumn();
        $hosts = q("SELECT h.name, COUNT(DISTINCT f.cve) AS n FROM vuln_findings f JOIN hosts h ON h.id = f.host_id WHERE $open
                    GROUP BY h.id, h.name ORDER BY n DESC LIMIT 3")->fetchAll();
        return ['sev' => $sev, 'total' => array_sum($sev), 'fix' => $fix, 'kev' => $kev,
            'hosts' => array_map(static fn($h) => ['name' => (string) $h['name'], 'n' => (int) $h['n']], $hosts), 'series' => $series];
    }

    /** Netwerkapparaten: totaal, nu online (laatste uur gezien), nieuw, geblokkeerd, wifi; plus AdGuard */
    private static function net(array $n): array
    {
        $row = q("SELECT COUNT(*) AS total, SUM(last_seen >= NOW() - INTERVAL 1 HOUR) AS online FROM net_devices WHERE status <> 'ignored'")->fetch() ?: [];
        $ag = $n['adguard'] ?? null;
        return ['total' => (int) ($row['total'] ?? 0), 'online' => (int) ($row['online'] ?? 0), 'new' => (int) $n['new_open'], 'blocked' => (int) $n['blocked'],
            'wifi' => array_sum($n['aps'] ?? []), 'aps' => count($n['aps'] ?? []), 'risky' => count($n['risky'] ?? []),
            'adguard' => $ag && (int) ($ag['queries'] ?? 0) > 0 ? ['queries' => (int) $ag['queries'], 'blocked' => (int) $ag['blocked']] : null,
            'series' => $n['series']['total'] ?? []];
    }

    /** De laatste gebeurtenissen van alle soorten door elkaar (meldingen, IDS, beveiliging, systeeminfo) */
    private static function events(bool $full): array
    {
        $kinds = $full ? "'notify', 'ids', 'sec', 'info'" : "'notify', 'ids'";
        $rows = q("SELECT a.id, a.kind, a.status, a.title, a.created_at, COALESCE(h.name, hc.name) AS host
                   FROM alerts a LEFT JOIN hosts h ON h.id = a.host_id LEFT JOIN checks c ON c.id = a.check_id LEFT JOIN hosts hc ON hc.id = c.host_id
                   WHERE a.kind IN ($kinds) ORDER BY a.id DESC LIMIT 200")->fetchAll();
        // Systeeminfo (ingelogd, bijgewerkt …) hooguit 3 keer, anders verdringt het de echte gebeurtenissen
        $keep = [];
        $info = 0;
        foreach ($rows as $r) {
            if (($r['kind'] !== 'info' || ++$info <= 3) && count($keep) < 8) {
                $keep[] = $r;
            }
        }
        $rows = $keep;
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'status' => (int) $r['status'],
            'title' => (string) $r['title'], 'host' => $r['host'] !== null ? (string) $r['host'] : null, 'at' => (string) $r['created_at']], $rows);
    }
}
