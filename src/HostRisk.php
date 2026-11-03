<?php
declare(strict_types=1);

/**
 * Risico per server (1.144.0): één score 0–100, overal dezelfde (dashboard, serverkaart, REST API, MQTT).
 * Opgebouwd uit wat RzMon al weet, elk onderdeel met een plafond, zodat één ding niet alles bepaalt:
 *   open incidenten              tot 35   (15 per critical, 5 per warning)
 *   kwetsbaarheden               tot 30   (de hoogste risicoscore van een CVE × 0,3 als het pakket op het netwerk luistert, anders × 0,15)
 *   hardening                    tot 15   ((100 − hardening-score) × 0,15)
 *   agent                        tot 15   (stil: 10, onversleuteld: 5)
 *   afwijkingen (IDS, 7 dagen)   tot 10   (2 per afwijking)
 * Hoog vanaf 70, midden vanaf 40. Geaccepteerde kwetsbaarheden en opgeloste incidenten tellen niet.
 */
final class HostRisk
{
    /** @return array<int, array{score: int, level: string, factors: list<array{0: int, 1: string}>}> */
    public static function all(bool $fresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$fresh) {
            return $cache;
        }
        $hosts = q('SELECT id, name, mode, enc, agent_seen, agent_tick FROM hosts WHERE enabled = 1')->fetchAll();
        $f = [];
        foreach ($hosts as $h) {
            $f[(int) $h['id']] = [];
        }
        // Open incidenten
        foreach (q("SELECT host_id, SUM(severity >= 2) AS c, SUM(severity = 1) AS w FROM incidents WHERE status <> 'resolved' AND host_id IS NOT NULL GROUP BY host_id") as $r) {
            $pts = min(35, 15 * (int) $r['c'] + 5 * (int) $r['w']);
            if ($pts && isset($f[(int) $r['host_id']])) {
                $f[(int) $r['host_id']][] = [$pts, t('{n} open incident(en)', ['n' => (int) $r['c'] + (int) $r['w']])];
            }
        }
        // Kwetsbaarheden: de ergste, en of hij van buiten bereikbaar is
        if (Vulns::enabled()) {
            $exp = [];
            foreach (q('SELECT host_id, exposed FROM vuln_inventory') as $r) {
                foreach ((array) json_decode((string) $r['exposed'], true) as $e) {
                    if (isset($e[0])) {
                        $exp[(int) $r['host_id']][(string) $e[0]] = true;
                    }
                }
            }
            $worst = [];
            foreach (q('SELECT f.host_id, f.package, f.source, f.cve, COALESCE(c.severity, 0) AS sev, c.cvss, c.kev, c.epss FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve') as $v) {
                $hid = (int) $v['host_id'];
                if (!isset($f[$hid]) || Vulns::ignored((string) $v['cve'], (string) $v['package'], $hid)) {
                    continue;
                }
                $bins = array_map('trim', explode(',', (string) $v['source']));
                $isExp = isset($exp[$hid][$v['package']]) || (bool) array_intersect($bins, array_keys($exp[$hid] ?? []));
                $r = Vulns::risk((int) $v['sev'], $v['cvss'] !== null ? (float) $v['cvss'] : null, (bool) $v['kev'], $v['epss'] !== null ? (float) $v['epss'] : null, $isExp);
                $pts = (int) round($r * ($isExp ? 0.3 : 0.15));
                if ($pts > ($worst[$hid][0] ?? 0)) {
                    $worst[$hid] = [$pts, $isExp ? t('{cve} in {p}, bereikbaar via het netwerk (risico {r})', ['cve' => $v['cve'], 'p' => $v['package'], 'r' => $r])
                        : t('{cve} in {p} (risico {r})', ['cve' => $v['cve'], 'p' => $v['package'], 'r' => $r])];
                }
            }
            foreach ($worst as $hid => $w) {
                $f[$hid][] = [min(30, $w[0]), $w[1]];
            }
        }
        // Hardening
        foreach (Hardening::scores() as $hid => $s) {
            if (isset($f[$hid]) && $s['score'] !== null && $s['score'] < 100) {
                $f[$hid][] = [(int) min(15, round((100 - $s['score']) * 0.15)), t('hardening {s}/100', ['s' => $s['score']])];
            }
        }
        // Agent: stil of onversleuteld
        $staleMin = max(2, (int) setting('agent_stale_minutes', 5));
        foreach ($hosts as $h) {
            if ($h['mode'] !== 'agent') {
                continue;
            }
            $hid = (int) $h['id'];
            if (!$h['agent_seen'] || strtotime((string) $h['agent_seen']) < time() - $staleMin * 60) {
                $f[$hid][] = [10, t('agent stil')];
            }
            if (AgentCrypto::hostMode($h) === 'off') {
                $f[$hid][] = [5, t('agent onversleuteld')];
            }
        }
        // Afwijkingen van de laatste week (IDS)
        foreach (q("SELECT host_id, COUNT(*) AS n FROM ids_incidents WHERE type = 'anomaly' AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY host_id") as $r) {
            if (isset($f[(int) $r['host_id']])) {
                $f[(int) $r['host_id']][] = [min(10, 2 * (int) $r['n']), t('{n} afwijking(en) deze week', ['n' => (int) $r['n']])];
            }
        }
        $cache = [];
        foreach ($f as $hid => $list) {
            usort($list, static fn($a, $b) => $b[0] <=> $a[0]);
            $score = (int) min(100, array_sum(array_column($list, 0)));
            $cache[$hid] = ['score' => $score, 'level' => self::level($score), 'factors' => $list];
        }
        return $cache;
    }

    public static function level(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');
    }

    /** Voor de REST API en MQTT: score, niveau en de redenen als tekst */
    public static function of(int $hostId): array
    {
        $r = self::all()[$hostId] ?? ['score' => 0, 'level' => 'low', 'factors' => []];
        return ['score' => $r['score'], 'level' => $r['level'], 'reasons' => array_map(static fn($x) => $x[1], $r['factors'])];
    }
}
