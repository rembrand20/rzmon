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

    /**
     * 1.197.0: netwerk in het kort voor de Home Assistant-kaart (GET /v1.php/network): één oordeel met de redenen,
     * internetlijn, maandverbruik, apparaten, netwerkpaden en (alleen met het recht IDS lezen) de IDS-tellers.
     * @return array<string, mixed>
     */
    public static function network(bool $ids): array
    {
        $net = self::safe(static fn() => self::net()) ?: ['inet' => null, 'wan' => null, 'new' => null, 'today' => null, 'top' => []];
        $why = [];
        $worst = 0;
        $flag = static function (int $st, string $txt) use (&$why, &$worst): void {
            $worst = max($worst, $st);
            $why[] = ['status' => $st, 'text' => $txt];
        };

        // Internetlijn: de checks van het apparaat Internet (ping, responstijd, download, upload)
        $inet = $net['inet'];
        $ic = self::safe(static fn() => q("SELECT metric, status, last_message FROM checks WHERE type = 'internet' AND enabled = 1")->fetchAll()) ?: [];
        if ($ic) {
            $st = 0;
            foreach ($ic as $c) {
                $s = (int) $c['status'];
                if ($s === STATUS_CRIT || $s === STATUS_WARN) {
                    $st = max($st, $s === STATUS_CRIT ? 2 : 1);
                }
            }
            $inet ??= ['down' => null, 'up' => null, 'lat' => null, 'at' => null, 'err' => null, 'plan' => null];
            $inet['status'] = $st;
            $inet['plan_up'] = Internet::plan('up');
            $inet['host'] = (string) q("SELECT h.name FROM hosts h JOIN checks c ON c.host_id = h.id WHERE c.type = 'internet' ORDER BY h.id LIMIT 1")->fetchColumn();
            if ($st) {
                $flag($st, $st === 2 ? t('Internetlijn heeft een storing') : t('Internetlijn is traag'));
            }
        }

        // Maandverbruik: waarschuwen als de verwachting boven de limiet komt
        $wan = $net['wan'];
        if ($wan && !empty($wan['cap'])) {
            $wan['pct'] = (int) round(100 * $wan['used'] / $wan['cap']);
            if ($wan['used'] >= $wan['cap']) {
                $flag(2, t('Maandlimiet bereikt'));
            } elseif (!empty($wan['forecast']) && $wan['forecast'] > $wan['cap']) {
                $flag(1, t('Maandlimiet wordt deze maand gehaald'));
            }
        }

        // Apparaten (netwerkscan)
        $dev = null;
        if (NetScan::enabled()) {
            $r = self::safe(static fn() => q("SELECT COUNT(*) AS total, SUM(last_seen >= NOW() - INTERVAL 1 HOUR) AS online FROM net_devices WHERE status <> 'ignored'")->fetch()) ?: [];
            $dev = ['total' => (int) ($r['total'] ?? 0), 'online' => (int) ($r['online'] ?? 0), 'new' => (int) $net['new'], 'today' => $net['today'], 'top' => $net['top']];
            if ($dev['new']) {
                $flag(1, t('{n} nieuwe apparaten', ['n' => $dev['new']]));
            }
        }

        // Netwerkpaden: status, ms, verlies en hops uit path_sum
        $paths = [];
        foreach (self::safe(static fn() => q("SELECT c.id, c.name, c.status, c.path_sum, c.last_value, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id
                WHERE c.type = 'path' AND c.enabled = 1 ORDER BY c.status = 2 DESC, c.status = 1 DESC, h.name, c.name")->fetchAll()) ?: [] as $c) {
            $s = json_decode((string) $c['path_sum'], true) ?: [];
            $st = (int) $c['status'];
            $paths[] = ['name' => $c['name'], 'host' => $c['host'], 'status' => $st, 'ms' => $s['ms'] ?? ($c['last_value'] !== null ? (float) $c['last_value'] : null),
                'loss' => $s['l'] ?? null, 'hops' => $s['h'] ?? null, 'mbit' => $s['mb'] ?? null, 'why' => ($s['w'] ?? '') ?: null];
            if ($st === STATUS_CRIT || $st === STATUS_WARN) {
                $flag($st === STATUS_CRIT ? 2 : 1, $c['host'] . ' · ' . (($s['w'] ?? '') !== '' ? $s['w'] : $c['name']));
            }
        }

        $sec = null;
        if ($ids) {
            $x = self::safe(static fn() => self::sec());
            if (is_array($x)) {
                $sec = ['inc' => $x['inc'], 'inc_crit' => $x['inc_crit'], 'blk_today' => $x['blk_today'], 'blk_now' => $x['blk_now']];
                if ($x['inc_crit']) {
                    $flag(2, t('{n} kritieke incidenten', ['n' => $x['inc_crit']]));
                } elseif ($x['inc']) {
                    $flag(1, t('{n} open incidenten', ['n' => $x['inc']]));
                }
            }
        }

        usort($why, static fn($a, $b) => $b['status'] <=> $a['status']);
        $label = [t('Alles in orde'), t('Let op'), t('Storing')][$worst];
        return ['at' => date('c'), 'status' => $worst, 'status_text' => $label, 'problems' => $why, 'internet' => $inet, 'month' => $wan,
            'devices' => $dev, 'paths' => $paths, 'ids' => $sec, 'version' => RZMON_VERSION];
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
