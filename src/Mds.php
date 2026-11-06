<?php

declare(strict_types=1);

/**
 * 1.194.0: mDS (Mobile device summary): de gegevens voor de samenvatting op een telefoon die niet al in "state" zitten.
 * Beveiliging (IDS, logins, kwetsbaarheden, datalekken) en netwerk (internetlijn, maandverbruik, nieuwe apparaten,
 * top 3 verkeer vandaag). Elk onderdeel apart: lukt er één niet (module uit, tabel nog niet gevuld), dan blijft dat leeg.
 */
final class Mds
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        return ['at' => time(), 'sec' => self::safe(static fn() => self::sec()), 'net' => self::safe(static fn() => self::net())];
    }

    private static function safe(callable $f): mixed
    {
        try {
            return $f();
        } catch (Throwable $e) {
            error_log('RzMon mDS: ' . $e->getMessage());
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function sec(): array
    {
        $open = [1 => 0, 2 => 0];
        foreach (q("SELECT severity, COUNT(*) AS n FROM ids_incidents WHERE status = 'open' GROUP BY severity") as $r) {
            $open[min(2, max(1, (int) $r['severity']))] += (int) $r['n'];
        }
        $out = [
            'inc' => $open[1] + $open[2], 'inc_crit' => $open[2],
            'blk_today' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE created_at >= CURDATE()')->fetchColumn(),
            'blk_now' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchColumn(),
            'logins' => (int) q("SELECT COUNT(*) FROM access_log WHERE kind = 'auth' AND created_at >= CURDATE()")->fetchColumn(),
            'fails' => (int) q("SELECT COALESCE(SUM(COALESCE(JSON_EXTRACT(detail, '$.n'), 1)), 0) FROM access_log WHERE kind = 'authfail' AND created_at >= CURDATE()")->fetchColumn(),
            'vulns' => null, 'leaks' => null,
        ];
        if (Vulns::enabled()) {
            $open = "NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.host_id IS NULL OR i.host_id = f.host_id) AND (i.until IS NULL OR i.until >= CURDATE()))";
            $out['vulns'] = [
                'crit' => (int) q("SELECT COUNT(DISTINCT f.host_id, f.cve) FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve AND c.severity >= 4 WHERE $open")->fetchColumn(),
                'kev' => (int) q("SELECT COUNT(DISTINCT f.host_id, f.cve) FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve AND c.kev = 1 WHERE $open")->fetchColumn(),
            ];
        }
        if (Hibp::enabled()) {
            $out['leaks'] = (int) q('SELECT COUNT(*) FROM hibp_breaches WHERE handled_at IS NULL')->fetchColumn();
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function net(): array
    {
        $out = ['inet' => null, 'wan' => null, 'new' => null, 'today' => null, 'top' => []];
        $in = self::safe(static fn() => Internet::state());
        if (is_array($in) && $in['host'] && $in['last']) {
            $l = $in['last'];
            $out['inet'] = ['down' => $l['down'] ?? null, 'up' => $l['up'] ?? null, 'lat' => $l['lat'] ?? null, 'at' => $l['at'] ?? null, 'err' => $l['err'] ?? null,
                'plan' => Internet::plan('down') ?: null];
        }
        if (NetScan::enabled()) {
            $out['new'] = NetScan::newCount();
            $w = self::safe(static fn() => NetTraffic::wanMonth());
            if (is_array($w) && ((int) $w['rx'] + (int) $w['tx']) > 0) {
                $out['wan'] = ['used' => (int) $w['rx'] + (int) $w['tx'], 'cap' => $w['cap'] ?? null, 'forecast' => $w['forecast'] ?? null];
            }
            $t = self::safe(static fn() => NetTraffic::totals());
            if (is_array($t) && isset($t['today'])) {
                $out['today'] = (int) $t['today'][0] + (int) $t['today'][1];
            }
            $sum = self::safe(static fn() => NetTraffic::summary());
            if (is_array($sum) && $sum) {
                uasort($sum, static fn($a, $b) => ((int) $b['rx'] + (int) $b['tx']) <=> ((int) $a['rx'] + (int) $a['tx']));
                $ids = array_slice(array_keys($sum), 0, 3);
                $names = [];
                foreach (q('SELECT id, COALESCE(NULLIF(name, \'\'), NULLIF(hostname, \'\'), NULLIF(vendor, \'\'), ip) AS nm FROM net_devices WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
                    $names[(int) $r['id']] = (string) $r['nm'];
                }
                foreach ($ids as $id) {
                    $b = (int) $sum[$id]['rx'] + (int) $sum[$id]['tx'];
                    if ($b > 0) {
                        $out['top'][] = ['id' => (int) $id, 'name' => $names[(int) $id] ?? ('#' . $id), 'bytes' => $b];
                    }
                }
            }
        }
        return $out;
    }
}
