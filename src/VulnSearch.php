<?php
declare(strict_types=1);

/**
 * 1.248.0: kwetsbaarheden doorzoeken en vanuit een CVE of een programma bekijken.
 * - search(): één zoekvak voor servers, CVE's en software;
 * - where(): per CVE alle servers, in groepen (kwetsbaar, niet kwetsbaar, niet van toepassing, onbekend, geaccepteerd);
 * - software(): per programma de versies per besturingssysteem, de open CVE's en vanaf welke versie het veilig is;
 * - csv(): dezelfde gegevens als bestand voor Excel.
 * Alles komt uit wat RzSense al heeft (vuln_inventory, vuln_findings, vuln_resolved); er gaat niets naar buiten.
 */
final class VulnSearch
{
    /** Servers die bij Kwetsbaarheden horen, met hun softwarelijst (zoals Vulns::overview) */
    private static function hosts(): array
    {
        $ex = Vulns::excluded();
        $out = [];
        foreach (q('SELECT h.id, h.name, h.mode, h.agent_update_via, i.mgr, i.os_id, i.os_version, i.os_name, i.ecosystem, i.packages, i.exposed, i.scanned_at, i.scan_error
                    FROM hosts h LEFT JOIN vuln_inventory i ON i.host_id = h.id WHERE h.enabled = 1 ORDER BY h.name') as $h) {
            if (in_array((int) $h['id'], $ex, true) || ($h['mgr'] === null && ($h['mode'] === 'remote' || $h['agent_update_via'] === 'ha'))) {
                continue;
            }
            $h['id'] = (int) $h['id'];
            $h['os'] = trim((string) ($h['os_name'] ?: trim($h['os_id'] . ' ' . $h['os_version']))) ?: '?';
            // Onbekend: geen softwarelijst, nog niet gescand, scan mislukt of distributie niet ondersteund
            $h['unknown'] = $h['mgr'] === null ? t('geen softwarelijst')
                : ($h['scan_error'] ? (string) $h['scan_error'] : ($h['scanned_at'] === null ? t('nog niet gescand') : null));
            $out[$h['id']] = $h;
        }
        return $out;
    }

    /** Programmanaam en versie van een regel uit de softwarelijst: Debian/Ubuntu per bronpakket, rpm per pakket (zoals de bevindingen) */
    private static function prog(array $p, string $mgr): array
    {
        return $mgr === 'dpkg' ? [(string) ($p[3] ?? $p[0]), (string) ($p[4] ?? $p[1])] : [(string) $p[0], (string) $p[1]];
    }

    /** Programma's op een server: naam => [versie, pakketten] */
    private static function progs(array $h): array
    {
        $out = [];
        foreach ((array) json_decode((string) $h['packages'], true) as $p) {
            [$name, $ver] = self::prog($p, (string) $h['mgr']);
            $out[$name] ??= [$ver, []];
            $out[$name][1][] = (string) $p[0];
        }
        return $out;
    }

    /** Open bevindingen met CVE-gegevens (ook geaccepteerde, met een vlag) */
    private static function findings(?string $cve = null): array
    {
        Vulns::forgetIgnored();
        $rows = q('SELECT f.host_id, f.package, f.version, f.cve, f.fixed_version, f.first_seen, COALESCE(c.severity, 0) AS severity, c.cvss, c.kev, c.epss
                   FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve' . ($cve !== null ? ' WHERE f.cve = ?' : ''), $cve !== null ? [$cve] : [])->fetchAll();
        foreach ($rows as &$r) {
            $r['host_id'] = (int) $r['host_id'];
            $r['severity'] = (int) $r['severity'];
            $r['kev'] = (bool) $r['kev'];
            $r['cvss'] = $r['cvss'] !== null ? (float) $r['cvss'] : null;
            $r['epss'] = $r['epss'] !== null ? (float) $r['epss'] : null;
            $r['ignored'] = Vulns::ignored((string) $r['cve'], (string) $r['package'], $r['host_id']);
        }
        unset($r);
        return $rows;
    }

    /** Pakketten waar een programma op het netwerk luistert, per server */
    private static function exposed(array $h): array
    {
        $map = [];
        $progOf = [];
        foreach ((array) json_decode((string) $h['packages'], true) as $p) {
            $progOf[(string) $p[0]] = self::prog($p, (string) $h['mgr'])[0];
        }
        foreach ((array) json_decode((string) ($h['exposed'] ?? ''), true) as $e) {
            if (is_array($e) && isset($e[0])) {
                $map[$progOf[(string) $e[0]] ?? (string) $e[0]][] = ($e[1] ?? '') . '/' . ($e[2] ?? 'tcp');
            }
        }
        return $map;
    }

    private static function cmp(string $mgr, string $a, string $b): int
    {
        return $mgr === 'dpkg' ? Vulns::cmpDeb($a, $b) : Vulns::cmpRpm($a, $b);
    }

    // ------------------------------------------------------------------ zoeken

    /** Eén zoekvak: servers, CVE's en software die erop lijken (hooguit 8 per soort) */
    public static function search(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if (mb_strlen($q) < 2) {
            return ['servers' => [], 'cves' => [], 'software' => []];
        }
        $hosts = self::hosts();
        $fs = array_filter(self::findings(), static fn($f) => !$f['ignored']);
        $open = [];
        foreach ($fs as $f) {
            $open[$f['host_id']][$f['cve']] = true;
        }
        $servers = [];
        foreach ($hosts as $h) {
            if (str_contains(mb_strtolower($h['name']), $q)) {
                $servers[] = ['id' => $h['id'], 'name' => $h['name'], 'os' => $h['os'], 'open' => count($open[$h['id']] ?? [])];
            }
        }
        // CVE's: de open en opgeloste op je servers, plus wat RzSense kent (vuln_cve)
        $cves = [];
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        foreach (q('SELECT c.cve, c.severity, c.kev, (SELECT COUNT(DISTINCT f.host_id) FROM vuln_findings f WHERE f.cve = c.cve) AS hosts
                    FROM vuln_cve c WHERE c.cve LIKE ? ORDER BY hosts DESC, c.cve DESC LIMIT 8', [$like]) as $c) {
            $cves[] = ['cve' => $c['cve'], 'severity' => (int) $c['severity'], 'kev' => (bool) $c['kev'], 'hosts' => (int) $c['hosts']];
        }
        // Software: elk programma op een server, met hoeveel servers en open CVE's
        $soft = [];
        foreach ($hosts as $h) {
            if ($h['mgr'] === null) {
                continue;
            }
            foreach (self::progs($h) as $name => [$ver, $bins]) {
                if (str_contains(mb_strtolower($name), $q) || array_filter($bins, static fn($b) => str_contains(mb_strtolower($b), $q))) {
                    $soft[$name]['hosts'][$h['id']] = true;
                }
            }
        }
        $openPkg = [];
        foreach ($fs as $f) {
            $openPkg[$f['package']][$f['cve']] = true;
        }
        $software = [];
        foreach ($soft as $name => $s) {
            $software[] = ['name' => (string) $name, 'hosts' => count($s['hosts']), 'open' => count($openPkg[$name] ?? [])];
        }
        usort($software, static fn($a, $b) => [$b['open'], (int) !str_starts_with(mb_strtolower($a['name']), $q), $b['hosts']] <=> [$a['open'], (int) !str_starts_with(mb_strtolower($b['name']), $q), $a['hosts']]
            ?: strcmp($a['name'], $b['name']));
        return ['servers' => array_slice($servers, 0, 8), 'cves' => $cves, 'software' => array_slice($software, 0, 8)];
    }

    // ------------------------------------------------------------------ waar staat een CVE

    /**
     * Per server: kwetsbaar (vuln), niet kwetsbaar met het pakket erop (safe), niet van toepassing (na), onbekend (unknown), geaccepteerd (accepted).
     * Welke pakketten erbij horen: uit de bevindingen, uit wat eerder opgelost is, en uit het advies zelf (OSV).
     */
    public static function where(string $cve): array
    {
        $hosts = self::hosts();
        $fs = self::findings($cve);
        $pkgs = array_fill_keys(array_column($fs, 'package'), true);
        $resolved = [];
        foreach (q('SELECT host_id, package, version, MAX(resolved_at) AS at FROM vuln_resolved WHERE cve = ? GROUP BY host_id, package, version', [$cve]) as $r) {
            $resolved[(int) $r['host_id']] = ['package' => $r['package'], 'version' => $r['version'], 'at' => $r['at']];
            $pkgs[$r['package']] = true;
        }
        foreach (q('SELECT data FROM vuln_cache WHERE vuln_id = ? OR vuln_id IN (SELECT DISTINCT vuln_id FROM vuln_findings WHERE cve = ?)', [$cve, $cve]) as $row) {
            foreach ((array) (json_decode((string) $row['data'], true)['affected'] ?? []) as $a) {
                if (!empty($a['package']['name'])) {
                    $pkgs[(string) $a['package']['name']] = true;
                }
            }
        }
        $byHost = [];
        foreach ($fs as $f) {
            $byHost[$f['host_id']][] = $f;
        }
        $groups = ['vuln' => [], 'accepted' => [], 'safe' => [], 'na' => [], 'unknown' => []];
        foreach ($hosts as $h) {
            $base = ['host_id' => $h['id'], 'host' => $h['name'], 'os' => $h['os']];
            if (isset($byHost[$h['id']])) {
                $exp = self::exposed($h);
                foreach ($byHost[$h['id']] as $f) {
                    $groups[$f['ignored'] ? 'accepted' : 'vuln'][] = $base + ['package' => $f['package'], 'version' => $f['version'], 'fixed' => $f['fixed_version'],
                        'exposed' => $exp[$f['package']] ?? [], 'since' => $f['first_seen']];
                }
                continue;
            }
            if ($h['unknown'] !== null) {
                $groups['unknown'][] = $base + ['why' => $h['unknown']];
                continue;
            }
            $have = array_intersect_key(self::progs($h), $pkgs);
            if ($have) {
                $name = (string) array_key_first($have);
                $groups['safe'][] = $base + ['package' => $name, 'version' => $have[$name][0], 'resolved' => $resolved[$h['id']]['at'] ?? null];
            } else {
                $groups['na'][] = $base + ['resolved' => $resolved[$h['id']]['at'] ?? null];
            }
        }
        return ['cve' => $cve, 'packages' => array_keys($pkgs), 'groups' => $groups];
    }

    // ------------------------------------------------------------------ software

    /**
     * Programma's over al je servers. $all = false: alleen met open CVE's (niet geaccepteerd).
     * Per programma de versies per besturingssysteem, op welke servers, de open CVE's per versie en "veilig vanaf".
     */
    public static function software(bool $all = false): array
    {
        $hosts = self::hosts();
        $fs = self::findings();
        $open = [];   // [host][programma] => bevindingen
        $acc = [];
        foreach ($fs as $f) {
            if ($f['ignored']) {
                $acc[$f['package']][$f['cve']] = true;
            } else {
                $open[$f['host_id']][$f['package']][] = $f;
            }
        }
        $progs = [];
        foreach ($hosts as $h) {
            if ($h['mgr'] === null || $h['unknown'] !== null) {
                continue;   // niet gescand of mislukt: daar weet RzSense niets over (staat apart onder "onbekend")
            }
            $exp = self::exposed($h);
            foreach (self::progs($h) as $name => [$ver, $bins]) {
                $hf = $open[$h['id']][$name] ?? [];
                if (!$all && !$hf) {
                    continue;
                }
                $p = &$progs[$name];
                $p['name'] = (string) $name;
                $og = &$p['os'][$h['os']];
                $og['mgr'] = $h['mgr'];
                $og['hosts'][] = ['host_id' => $h['id'], 'host' => $h['name'], 'version' => $ver, 'packages' => $bins, 'exposed' => $exp[$name] ?? [],
                    'cves' => array_map(static fn($f) => ['cve' => $f['cve'], 'severity' => $f['severity'], 'kev' => $f['kev'], 'fixed' => $f['fixed_version']], $hf)];
                unset($p, $og);
            }
        }
        $list = [];
        foreach ($progs as $name => $p) {
            $cves = [];
            $osOut = [];
            $hostsN = 0;
            $exposed = false;
            foreach ($p['os'] as $os => $g) {
                $versions = [];
                $safe = null;
                $nofix = false;
                foreach ($g['hosts'] as $hh) {
                    $hostsN++;
                    $exposed = $exposed || ($hh['exposed'] && $hh['cves']);
                    $versions[$hh['version']][] = $hh;
                    foreach ($hh['cves'] as $c) {
                        $cves[$c['cve']] = ['cve' => $c['cve'], 'severity' => $c['severity'], 'kev' => $c['kev']];
                        if ($c['fixed'] === null) {
                            $nofix = true;
                        } elseif ($safe === null || self::cmp($g['mgr'], $c['fixed'], $safe) > 0) {
                            $safe = $c['fixed'];
                        }
                    }
                }
                uksort($versions, fn($a, $b) => self::cmp($g['mgr'], (string) $b, (string) $a));   // nieuwste eerst
                $vOut = [];
                foreach ($versions as $v => $hs) {
                    $vc = [];
                    foreach ($hs as $hh) {
                        foreach ($hh['cves'] as $c) {
                            $vc[$c['cve']] = $c;
                        }
                    }
                    $vOut[] = ['version' => (string) $v, 'hosts' => $hs, 'cves' => array_values($vc)];
                }
                $osOut[] = ['os' => (string) $os, 'versions' => $vOut, 'safe' => $safe, 'nofix' => $nofix];
            }
            usort($osOut, static fn($a, $b) => strcmp($a['os'], $b['os']));
            $sev = array_fill(0, 5, 0);
            foreach ($cves as $c) {
                $sev[$c['severity']]++;
            }
            $list[] = ['name' => $name, 'hosts' => $hostsN, 'os' => $osOut, 'sev' => $sev, 'open' => count($cves), 'kev' => (bool) array_filter($cves, fn($c) => $c['kev']),
                'exposed' => $exposed, 'accepted' => count($acc[$name] ?? []),
                'spread' => array_sum(array_map(static fn($o) => count($o['versions']) > 1 ? 1 : 0, $osOut)) > 0];
        }
        usort($list, static fn($a, $b) => [$b['kev'], $b['sev'][4], $b['sev'][3], $b['open'], $b['hosts']] <=> [$a['kev'], $a['sev'][4], $a['sev'][3], $a['open'], $a['hosts']]
            ?: strcmp($a['name'], $b['name']));
        return ['all' => $all, 'programs' => $list, 'hosts' => count($hosts),
            'unknown' => array_values(array_map(static fn($h) => ['host' => $h['name'], 'why' => $h['unknown']], array_filter($hosts, static fn($h) => $h['unknown'] !== null)))];
    }

    // ------------------------------------------------------------------ CSV (Excel)

    private const SEV = ['onbekend', 'low', 'medium', 'high', 'critical'];

    /** CSV met ; (Excel in het Nederlands), UTF-8 met BOM, zonder formules */
    public static function csv(array $head, array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        $safe = static fn(array $r) => array_map(static fn($c) => preg_match('/^[=+\-@\t\r]/', (string) $c) ? "'" . $c : (string) $c, $r);
        fputcsv($f, $head, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($f, $safe($r), ';', '"', '');
        }
        rewind($f);
        return "\xEF\xBB\xBF" . (string) stream_get_contents($f);
    }

    /** Alle open kwetsbaarheden, één regel per server, pakket en CVE ($accepted: ook de geaccepteerde) */
    public static function csvFindings(bool $accepted = false): array
    {
        $hosts = self::hosts();
        $rows = [];
        foreach (self::findings() as $f) {
            if (!isset($hosts[$f['host_id']]) || ($f['ignored'] && !$accepted)) {
                continue;
            }
            $h = $hosts[$f['host_id']];
            $exp = isset(self::exposed($h)[$f['package']]);
            $rows[] = [$h['name'], $h['os'], $f['package'], $f['version'], $f['cve'], self::SEV[$f['severity']] ?? '', $f['cvss'] !== null ? str_replace('.', ',', (string) $f['cvss']) : '',
                $f['kev'] ? 'ja' : 'nee', $f['epss'] !== null ? str_replace('.', ',', (string) round($f['epss'] * 100, 2)) : '', $exp ? 'ja' : 'nee',
                (string) $f['fixed_version'], Vulns::risk($f['severity'], $f['cvss'], $f['kev'], $f['epss'], $exp), $f['ignored'] ? 'geaccepteerd' : 'open', substr((string) $f['first_seen'], 0, 10)];
        }
        usort($rows, static fn($a, $b) => [$a[0], -$a[11], $a[2]] <=> [$b[0], -$b[11], $b[2]]);
        return [[t('server'), t('besturingssysteem'), t('pakket'), t('versie'), 'CVE', t('ernst'), 'CVSS', t('actief misbruikt'), t('kans op misbruik (%)'),
            t('bereikbaar'), t('opgelost in'), t('risico'), t('status'), t('gevonden op')], $rows];
    }

    /** Het software-overzicht als tabel: één regel per programma, besturingssysteem en versie */
    public static function csvSoftware(bool $all = false): array
    {
        $rows = [];
        foreach (self::software($all)['programs'] as $p) {
            foreach ($p['os'] as $o) {
                foreach ($o['versions'] as $v) {
                    $sev = array_count_values(array_map(static fn($c) => $c['severity'], $v['cves']));
                    $rows[] = [$p['name'], $o['os'], $v['version'], count($v['hosts']), implode(', ', array_column($v['hosts'], 'host')),
                        count($v['cves']), $sev[4] ?? 0, $sev[3] ?? 0, $sev[2] ?? 0, $sev[1] ?? 0, array_filter($v['cves'], fn($c) => $c['kev']) ? 'ja' : 'nee',
                        $v['cves'] ? ($o['safe'] ?? t('nog geen oplossing')) : t('veilig'), implode(' ', array_column($v['cves'], 'cve'))];
                }
            }
        }
        return [[t('programma'), t('besturingssysteem'), t('versie'), t('servers'), t('welke servers'), t('open CVE\'s'), 'critical', 'high', 'medium', 'low',
            t('actief misbruikt'), t('veilig vanaf'), 'CVE\'s'], $rows];
    }

    /** Eén CVE: per server in welke groep hij valt */
    public static function csvWhere(string $cve): array
    {
        $w = self::where($cve);
        $label = ['vuln' => t('kwetsbaar'), 'accepted' => t('geaccepteerd'), 'safe' => t('niet kwetsbaar'), 'na' => t('niet van toepassing'), 'unknown' => t('onbekend')];
        $rows = [];
        foreach ($w['groups'] as $g => $list) {
            foreach ($list as $x) {
                $rows[] = [$cve, $x['host'], $x['os'], $label[$g], $x['package'] ?? '', $x['version'] ?? '', $x['fixed'] ?? '', implode(' ', $x['exposed'] ?? []),
                    substr((string) ($x['resolved'] ?? ''), 0, 10), $x['why'] ?? ''];
            }
        }
        return [['CVE', t('server'), t('besturingssysteem'), t('status'), t('pakket'), t('versie'), t('opgelost in'), t('bereikbaar op'), t('opgelost op'), t('reden')], $rows];
    }
}
