<?php
declare(strict_types=1);

/**
 * 1.155.0: slimme analyses voor de pagina Analyse. Elke analyse combineert gegevens uit verschillende bronnen
 * (agent, CVE-scan, IDS, netwerkscan, checks, instellingen) tot één advies: wat er is, waarom het telt (met het
 * bewijs uit je eigen gegevens) en wat je eraan doet. Een advies heeft een vaste sleutel, zodat RzSense bijhoudt wanneer
 * het verscheen, of je het hebt afgehandeld en wanneer het vanzelf is verdwenen (opgelost).
 *
 * Score: per onderdeel 100 min de open adviezen (hoog 18, middel 8, laag 2); de totaalscore is het gemiddelde.
 * Meldingen: alleen bij een CVE die actief misbruikt wordt (CISA KEV) of kritiek is (CVSS ≥ 9) met een hoge kans op
 * misbruik (EPSS ≥ 50%), en alleen als die een server van jou raakt; één keer per advies.
 */
final class Insights
{
    public const DOMAINS = ['access', 'exposure', 'vulns', 'detect', 'network', 'recovery'];
    private const PEN = [2 => 18, 1 => 8, 0 => 2];
    /** CVE's per server die al als kwetsbare dienst gemeld zijn (niet nog eens als belangrijke CVE) */
    private static array $covered = [];
    /** Poorten die je liever niet open naar internet hebt: naam en of het extra gevaarlijk is */
    private const SENSITIVE = [22 => ['SSH', false], 23 => ['Telnet', true], 445 => ['SMB', true], 139 => ['NetBIOS', true], 3389 => ['RDP', true], 5900 => ['VNC', true],
        8006 => ['Proxmox', false], 9090 => ['Cockpit', false], 3306 => ['MySQL/MariaDB', true], 5432 => ['PostgreSQL', true], 6379 => ['Redis', true],
        27017 => ['MongoDB', true], 9200 => ['Elasticsearch', true], 2375 => ['Docker API', true], 8123 => ['Home Assistant', false], 21 => ['FTP', true]];
    /** Einde van de ondersteuning per besturingssysteem (os_id → versie → datum) */
    private const EOL = [
        'debian' => ['8' => '2020-06-30', '9' => '2022-06-30', '10' => '2024-06-30', '11' => '2026-08-31', '12' => '2028-06-30'],
        'raspbian' => ['9' => '2022-06-30', '10' => '2024-06-30', '11' => '2026-08-31', '12' => '2028-06-30'],
        'ubuntu' => ['16.04' => '2021-04-30', '18.04' => '2023-05-31', '20.04' => '2025-05-31', '22.04' => '2027-04-30', '24.04' => '2029-05-31',
            '23.04' => '2024-01-25', '23.10' => '2024-07-11', '24.10' => '2025-07-10', '25.04' => '2026-01-15'],
        'centos' => ['7' => '2024-06-30', '8' => '2021-12-31'],
        'rocky' => ['8' => '2029-05-31', '9' => '2032-05-31', '10' => '2035-05-31'], 'almalinux' => ['8' => '2029-05-31', '9' => '2032-05-31'],
        'rhel' => ['7' => '2024-06-30', '8' => '2029-05-31', '9' => '2032-05-31'],
        'alpine' => ['3.16' => '2024-05-23', '3.17' => '2024-11-22', '3.18' => '2025-05-09', '3.19' => '2025-11-01', '3.20' => '2026-04-01', '3.21' => '2026-11-01'],
        'fedora' => ['38' => '2024-05-21', '39' => '2024-11-26', '40' => '2025-05-13', '41' => '2025-12-15'],
    ];
    /**
     * Wat een dienst zelf vertelt (banner van de netwerkscan) tegenover een versie die veilig is.
     * [patroon, programma, kleinste veilige versie of null (elke versie), CVE, ernst 0–2, uitleg]
     */
    private const BANNERS = [
        ['/OpenSSH[_ ](\d+\.\d+)/i', 'OpenSSH', '9.8', 'CVE-2024-6387', 2, 'regreSSHion: een aanvaller kan zonder in te loggen code uitvoeren als root (OpenSSH 8.5–9.7, en ouder dan 4.4)'],
        ['/dropbear[_ ](\d{4}\.\d+)/i', 'Dropbear SSH', '2016.74', 'CVE-2016-7406', 2, 'een aanvaller kan code uitvoeren via een kwaadaardige gebruikersnaam'],
        ['/vsftpd 2\.3\.4/i', 'vsftpd 2.3.4', null, 'CVE-2011-2523', 2, 'deze versie bevat een achterdeur die iedereen een shell geeft'],
        ['/Exim (\d+\.\d+(?:\.\d+)?)/i', 'Exim', '4.96.1', 'CVE-2023-42115', 2, 'een aanvaller kan zonder in te loggen code uitvoeren'],
        ['/Apache\/2\.4\.(49|50)\b/i', 'Apache 2.4.49/2.4.50', null, 'CVE-2021-41773', 2, 'bestanden buiten de webmap lezen en code uitvoeren; wordt actief misbruikt'],
        ['/Apache\/(2\.4\.\d+)/i', 'Apache httpd', '2.4.60', 'CVE-2024-38476', 1, 'een aanvaller kan via de webserver gegevens lekken of code laten uitvoeren'],
        ['/nginx\/(\d+\.\d+\.\d+)/i', 'nginx', '1.20.1', 'CVE-2021-23017', 1, 'een fout in de DNS-resolver kan de webserver laten crashen of overnemen'],
        ['/lighttpd\/(\d+\.\d+\.\d+)/i', 'lighttpd', '1.4.67', 'CVE-2022-41556', 1, 'aanvallers kunnen de webserver laten vastlopen'],
        ['/GoAhead[- \/]?(?:Webs)?\/?(\d+\.\d+\.\d+)?/i', 'GoAhead', '5.1.5', 'CVE-2021-42342', 1, 'veel gebruikt in camera\'s en routers; oude versies laten code uitvoeren'],
        ['/\bBoa\/[\d.]+/i', 'Boa', null, 'CVE-2017-9833', 2, 'een webserver die sinds 2005 niet meer wordt onderhouden (vaak in camera\'s en routers), met bekende lekken'],
    ];

    // ------------------------------------------------------------------ voor de pagina

    /** Alles voor de pagina: score, onderdelen, adviezen met hun status, de geschiedenis en wat er onlangs is opgelost */
    public static function page(): array
    {
        $list = self::cached();
        self::sync($list, false);
        $since = (int) setting('insight_first', '0');
        if (!$since) {
            save_setting('insight_first', (string) ($since = time()));
        }
        $state = [];
        foreach (q('SELECT * FROM insight_state')->fetchAll() as $r) {
            $state[$r['k']] = $r;
        }
        foreach ($list as $i => $a) {
            $s = $state[$a['key']] ?? null;
            $status = $s['status'] ?? null;
            if ($status === 'later' && $s['later_until'] !== null && strtotime((string) $s['later_until']) < time()) {
                $status = null;
            }
            $list[$i] += ['status' => $status, 'status_by' => $s['status_by'] ?? null, 'status_at' => $s['status_at'] ?? null,
                'first_seen' => $s['first_seen'] ?? now(), 'new' => $s && strtotime((string) $s['first_seen']) > max(time() - 2 * 86400, $since + 3600)];
        }
        $score = self::score($list);
        $resolved = q("SELECT k, title, sev, dom, resolved_at FROM insight_state WHERE resolved_at IS NOT NULL AND resolved_at >= NOW() - INTERVAL 30 DAY ORDER BY resolved_at DESC LIMIT 30")->fetchAll();
        return ['score' => $score['total'], 'domains' => $score['domains'], 'advices' => $list,
            'history' => array_map(static fn($r) => ['day' => $r['day'], 'total' => (int) $r['total']], q('SELECT day, total FROM insight_score WHERE day >= CURDATE() - INTERVAL 180 DAY ORDER BY day')->fetchAll()),
            'resolved' => array_map(static fn($r) => ['key' => $r['k'], 'title' => $r['title'], 'dom' => $r['dom'], 'gain' => (int) round(self::PEN[(int) $r['sev']] / count(self::DOMAINS)), 'at' => $r['resolved_at']], $resolved),
            'at' => now()];
    }

    /** Berekend in de taal van de gebruiker, hooguit eens per 5 minuten */
    private static function cacheFile(): string
    {
        return sys_get_temp_dir() . '/rzmon-insights-' . md5(SM_ROOT . '|' . I18n::lang() . '|' . (string) q('SELECT DATABASE()')->fetchColumn()) . '.json';
    }

    /** 1.241.0: de bewaarde adviezen vergeten (de volgende keer opnieuw berekenen) */
    public static function forget(): void
    {
        @unlink(self::cacheFile());
    }

    private static function cached(bool $fresh = false): array
    {
        $cf = self::cacheFile();
        if (!$fresh && is_file($cf) && filemtime($cf) > time() - 300 && is_array($c = json_decode((string) file_get_contents($cf), true))) {
            return $c;
        }
        $list = self::compute();
        @file_put_contents($cf, json_encode($list));
        return $list;
    }

    /** Status van een advies: done (gedaan), later (7 dagen niet tonen), na (niet van toepassing), open (weer gewoon) */
    public static function setStatus(string $key, string $status, string $by): bool
    {
        if (!preg_match('/^[a-z0-9_|.:\/\-]{3,190}$/i', $key) || !in_array($status, ['done', 'later', 'na', 'open'], true)) {
            return false;
        }
        q('INSERT INTO insight_state (k, first_seen, last_seen) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE k = k', [$key]);
        q('UPDATE insight_state SET status = ?, status_by = ?, status_at = NOW(), later_until = ? WHERE k = ?',
            [$status === 'open' ? null : $status, $status === 'open' ? null : $by, $status === 'later' ? date('Y-m-d H:i:s', time() + 7 * 86400) : null, $key]);
        return true;
    }

    /** Score per onderdeel en totaal. Niet van toepassing telt niet mee; "later" wel. */
    public static function score(array $list): array
    {
        $pen = array_fill_keys(self::DOMAINS, 0);
        foreach ($list as $a) {
            if (($a['status'] ?? null) === 'na') {
                continue;
            }
            $pen[$a['dom']] += self::PEN[$a['sev']];
        }
        $dom = array_map(static fn($p) => max(0, 100 - min(100, $p)), $pen);
        return ['total' => (int) round(array_sum($dom) / count($dom)), 'domains' => $dom];
    }

    // ------------------------------------------------------------------ cron

    /** Elke 15 minuten: opnieuw berekenen, status bijhouden, kritieke CVE's melden; elke nacht de score bewaren */
    public static function cron(): ?string
    {
        if ((int) setting('insight_at', '0') > time() - 900) {
            return null;
        }
        save_setting('insight_at', (string) time());
        $list = self::compute();
        if (!(int) setting('insight_first', '0')) {
            save_setting('insight_first', (string) time());
        }
        $new = self::sync($list, true);
        $sent = self::notify($list);
        if (setting('insight_score_day', '') !== date('Y-m-d')) {
            save_setting('insight_score_day', date('Y-m-d'));
            $sc = self::score(array_map(static fn($a) => $a + ['status' => null], $list));
            q('INSERT INTO insight_score (day, total, domains) VALUES (CURDATE(), ?, ?) ON DUPLICATE KEY UPDATE total = VALUES(total), domains = VALUES(domains)',
                [$sc['total'], json_encode($sc['domains'])]);
        }
        return 'Analyse: ' . count($list) . ' adviezen' . ($new ? ", $new nieuw" : '') . ($sent ? ", $sent gemeld" : '');
    }

    /**
     * Status bijwerken: nieuwe adviezen krijgen een eerste datum, verdwenen adviezen zijn opgelost (en "gedaan" is
     * daarmee bevestigd). Komt een opgelost advies terug, dan telt het weer als open. @return int aantal nieuwe adviezen
     */
    private static function sync(array $list, bool $resolve): int
    {
        $keys = [];
        $new = 0;
        foreach ($list as $a) {
            $keys[] = $a['key'];
            $st = q('INSERT INTO insight_state (k, title, sev, dom, first_seen, last_seen) VALUES (?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE title = VALUES(title), sev = VALUES(sev), dom = VALUES(dom), last_seen = NOW(),
                       status = IF(resolved_at IS NOT NULL AND status = \'done\', NULL, status), resolved_at = NULL',
                [$a['key'], mb_substr($a['title'], 0, 250), $a['sev'], $a['dom']]);
            $new += $st->rowCount() === 1 ? 1 : 0;
        }
        if ($resolve) {
            $in = $keys ? implode(',', array_fill(0, count($keys), '?')) : "''";
            q("UPDATE insight_state SET resolved_at = NOW() WHERE resolved_at IS NULL AND title IS NOT NULL AND k NOT IN ($in)", $keys);
            q('DELETE FROM insight_state WHERE resolved_at < NOW() - INTERVAL 180 DAY');
        }
        return $new;
    }

    /** Kritiek en wereldwijd bekend (KEV, of CVSS ≥ 9 met EPSS ≥ 50%): één melding per advies */
    private static function notify(array $list): int
    {
        $n = 0;
        foreach ($list as $a) {
            if (empty($a['crit'])) {
                continue;
            }
            $done = q('SELECT notified_at FROM insight_state WHERE k = ?', [$a['key']])->fetchColumn();
            if ($done) {
                continue;
            }
            q('UPDATE insight_state SET notified_at = NOW() WHERE k = ?', [$a['key']]);
            $why = implode("\n", array_map(static fn($w) => '• ' . strip_tags($w), array_slice($a['why'], 0, 4)));
            Monitor::sysInfo('advice_crit', $a['host_id'] ?? null, $a['host'] ?? null, '🚨 ' . $a['title'], static fn() => $why . "\n" . t('Advies: {s}', ['s' => strip_tags((string) $a['do'])]));
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ de analyses

    /** Alle adviezen, belangrijkste eerst */
    public static function compute(): array
    {
        $c = self::context();
        self::$covered = [];
        $out = [];
        foreach (['vulnService', 'vulnImportant', 'updatesBehind', 'eol', 'hardening', 'exposed', 'logins', 'idsMode', 'sharedBlocks', 'repeaters',
            'newPorts', 'outbound', 'devices', 'banners', 'unmonitored', 'agents', 'rzmon', 'trend', 'recovery'] as $rule) {
            try {
                array_push($out, ...self::$rule($c));
            } catch (Throwable $e) {
                // Eén analyse die misgaat (bijvoorbeeld een ontbrekende tabel) mag de rest niet tegenhouden
                error_log('RzSense analyse ' . $rule . ': ' . $e->getMessage());
            }
        }
        $eff = ['click' => 1, '5' => 5, '10' => 10, '15' => 15, '30' => 30, '60' => 60];
        foreach ($out as $i => $a) {
            $out[$i]['gain'] = (int) max(1, round(self::PEN[$a['sev']] / count(self::DOMAINS)));
            $out[$i]['rank'] = $a['sev'] * 1000 + $a['risk'] * 5 + (int) round(100 / ($eff[$a['effort']] ?? 30));
        }
        usort($out, static fn($a, $b) => $b['rank'] <=> $a['rank']);
        return array_slice($out, 0, 80);
    }

    /** Eén advies; why = bewijs (HTML-veilig gemaakt in de browser), do = de stap in één zin, cmds = [[label, commando]] */
    private static function a(string $key, string $dom, int $sev, int $risk, string $effort, string $title, array $why, string $do, array $cmds = [], array $acts = [], array $extra = []): array
    {
        return ['key' => mb_substr($key, 0, 190), 'dom' => $dom, 'sev' => max(0, min(2, $sev)), 'risk' => max(0, min(100, $risk)), 'effort' => $effort, 'title' => $title,
            'why' => array_values(array_filter($why, static fn($w) => $w !== '' && $w !== null)), 'do' => $do, 'cmds' => $cmds, 'acts' => $acts] + $extra;
    }

    /** Gegevens die meerdere analyses nodig hebben, één keer opgehaald */
    private static function context(): array
    {
        $hosts = [];
        foreach (q('SELECT id, name, address, agent_ip, mode, kind, kind_auto, agent_version, agent_seen, enc, agent_update_via, commands FROM hosts WHERE enabled = 1')->fetchAll() as $h) {
            $h['id'] = (int) $h['id'];
            $hosts[$h['id']] = $h;
        }
        $inv = [];
        foreach (q('SELECT host_id, mgr, os_id, os_version, os_name, packages, exposed, restart_svc, kernel_running, kernel_latest FROM vuln_inventory')->fetchAll() as $r) {
            $inv[(int) $r['host_id']] = $r;
        }
        $ports = [];
        foreach (q("SELECT host_id, value FROM agent_data WHERE metric = 'ports'")->fetchAll() as $r) {
            $ports[(int) $r['host_id']] = json_decode((string) $r['value'], true) ?: [];
        }
        // Aanvallen van buiten per server en poort (30 dagen)
        $attacked = [];
        foreach (q("SELECT host_id, dport, ip, cc, SUM(hits) AS hits FROM ids_incidents WHERE dport IS NOT NULL AND (dir IS NULL OR dir <> 'o') AND ip IS NOT NULL AND ip <> ''
                    AND last_seen >= NOW() - INTERVAL 30 DAY GROUP BY host_id, dport, ip, cc LIMIT 20000")->fetchAll() as $r) {
            if (Ids::internalIp((string) $r['ip'])) {
                continue;
            }
            $k = (int) $r['host_id'] . ':' . (int) $r['dport'];
            $attacked[$k] ??= ['hits' => 0, 'ips' => 0, 'cc' => []];
            $attacked[$k]['hits'] += (int) $r['hits'];
            $attacked[$k]['ips']++;
            if ($r['cc']) {
                $attacked[$k]['cc'][$r['cc']] = ($attacked[$k]['cc'][$r['cc']] ?? 0) + (int) $r['hits'];
            }
        }
        $allHits = array_sum(array_column($attacked, 'hits'));
        return ['hosts' => $hosts, 'inv' => $inv, 'ports' => $ports, 'attacked' => $attacked, 'all_hits' => $allHits, 'deps' => Urgency::dependents(),
            'vulns' => Vulns::enabled() ? self::findings() : []];
    }

    /** Alle bevindingen met de CVE-gegevens (zonder genegeerde) */
    private static function findings(): array
    {
        $out = [];
        foreach (q('SELECT f.host_id, f.package, f.version, f.source, f.vuln_id, f.cve, f.fixed_version, f.first_seen, COALESCE(c.severity, 0) AS sev, c.cvss, c.kev, c.kev_added, c.epss
                      FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve')->fetchAll() as $f) {
            if (Vulns::ignored((string) $f['cve'], (string) $f['package'], (int) $f['host_id'])) {
                continue;
            }
            $f['host_id'] = (int) $f['host_id'];
            $f['cvss'] = $f['cvss'] !== null ? (float) $f['cvss'] : null;
            $f['epss'] = $f['epss'] !== null ? (float) $f['epss'] : null;
            $f['kev'] = (bool) $f['kev'];
            $out[] = $f;
        }
        return $out;
    }

    /** Korte uitleg bij een kwetsbaarheid (uit de OSV-gegevens die RzSense al heeft) */
    private static function summary(string $vulnId): string
    {
        static $cache = [];
        if (!isset($cache[$vulnId])) {
            $d = json_decode((string) q('SELECT data FROM vuln_cache WHERE vuln_id = ?', [$vulnId])->fetchColumn(), true) ?: [];
            $s = trim((string) ($d['summary'] ?? ''));
            if ($s === '') {
                $s = trim((string) preg_replace('/\s+/', ' ', mb_substr((string) ($d['details'] ?? ''), 0, 220)));
            }
            $cache[$vulnId] = mb_substr($s, 0, 220);
        }
        return $cache[$vulnId];
    }

    private static function cveFacts(array $f): string
    {
        return implode(' · ', array_filter([
            $f['cvss'] !== null ? 'CVSS ' . I18n::dec((string) $f['cvss']) : '',
            $f['epss'] !== null ? t('kans op misbruik {p}% (EPSS)', ['p' => I18n::dec((string) round($f['epss'] * 100, 1))]) : '',
            $f['kev'] ? ($f['kev_added'] ? t('actief misbruikt sinds {d} (CISA KEV)', ['d' => date('d-m-Y', (int) strtotime((string) $f['kev_added']))]) : t('actief misbruikt (CISA KEV)')) : '',
        ]));
    }

    private static function worldwide(array $f): bool
    {
        return $f['kev'] || (($f['cvss'] ?? 0) >= 9 && ($f['epss'] ?? 0) >= 0.5);
    }

    private static function cmd(array $c, int $hid, array $pkgs): array
    {
        $mgr = (string) ($c['inv'][$hid]['mgr'] ?? 'deb');
        $os = (string) ($c['inv'][$hid]['os_id'] ?? '');
        if ($os === 'alpine') {
            return [t('Bijwerken op {h}', ['h' => $c['hosts'][$hid]['name'] ?? '?']), 'sudo apk upgrade ' . implode(' ', $pkgs)];
        }
        return [t('Bijwerken op {h}', ['h' => $c['hosts'][$hid]['name'] ?? '?']), Vulns::upgradeCommand($mgr, $pkgs)];
    }

    private static function upgradeAct(array $c, int $hid, array $pkgs): ?array
    {
        $h = $c['hosts'][$hid] ?? null;
        return $h && $h['mode'] === 'agent' && Vulns::upgradeBlocked($h) === null ? ['k' => 'upgrade', 'host_id' => $hid, 'pkgs' => array_values(array_unique($pkgs))] : null;
    }

    /** 1. Kwetsbare dienst: open poort → programma → pakket → CVE → opgelost-in → aanvallen van buiten → afhankelijke servers */
    private static function vulnService(array $c): array
    {
        $out = [];
        $byHostPkg = [];
        foreach ($c['vulns'] as $f) {
            $byHostPkg[$f['host_id']][(string) $f['package']][] = $f;
            foreach (array_filter(array_map('trim', explode(',', (string) $f['source']))) as $src) {
                $byHostPkg[$f['host_id']][$src][] = $f;
            }
        }
        $local = static fn(string $a) => preg_match('/^(127\.|::1$|\[::1\]|localhost)/', $a) === 1;
        foreach ($c['ports'] as $hid => $plist) {
            if (!isset($c['hosts'][$hid])) {
                continue;
            }
            $h = $c['hosts'][$hid];
            $pkgOf = [];
            foreach ((array) json_decode((string) ($c['inv'][$hid]['exposed'] ?? ''), true) as $e) {
                if (isset($e[0], $e[1])) {
                    $pkgOf[(int) $e[1]] = (string) $e[0];
                }
            }
            $seen = [];
            foreach ($plist as $p) {
                $port = (int) ($p['port'] ?? 0);
                $addr = (string) ($p['addr'] ?? '');
                $pkg = $pkgOf[$port] ?? null;
                if ($port <= 0 || $local($addr) || $pkg === null || empty($byHostPkg[$hid][$pkg])) {
                    continue;
                }
                $seen[$pkg]['ports'][] = $port;
                $seen[$pkg]['proc'] = (string) ($p['proc'] ?? $pkg);
                $seen[$pkg]['addr'] = $addr;
            }
            foreach ($seen as $pkg => $s) {
                $fs = array_values(array_filter($byHostPkg[$hid][$pkg], static fn($f) => $f['fixed_version'] !== null));
                if (!$fs) {
                    continue;
                }
                $atk = ['hits' => 0, 'ips' => 0];
                foreach (array_unique($s['ports']) as $pt) {
                    $atk['hits'] += $c['attacked']["$hid:$pt"]['hits'] ?? 0;
                    $atk['ips'] += $c['attacked']["$hid:$pt"]['ips'] ?? 0;
                }
                usort($fs, static fn($a, $b) => Vulns::risk((int) $b['sev'], $b['cvss'], $b['kev'], $b['epss'], true) <=> Vulns::risk((int) $a['sev'], $a['cvss'], $a['kev'], $a['epss'], true));
                $w = $fs[0];
                $risk = Vulns::risk((int) $w['sev'], $w['cvss'], $w['kev'], $w['epss'], true) + ($atk['hits'] ? 10 : 0);
                if ($risk < 35) {
                    continue;
                }
                $deps = (int) ($c['deps'][$hid] ?? 0);
                $cves = array_values(array_unique(array_filter(array_column($fs, 'cve'))));
                foreach ($cves as $cv) {
                    self::$covered[$cv][$hid] = true;
                }
                $sum = self::summary((string) $w['vuln_id']);
                $ports = implode(', ', array_unique($s['ports']));
                $act = self::upgradeAct($c, $hid, [$pkg]);
                $out[] = self::a("vuln_service|$hid|$pkg", 'vulns', $risk >= 70 ? 2 : ($risk >= 45 ? 1 : 0), $risk, $act ? 'click' : '10',
                    t('{p} op {h} is verouderd en kwetsbaar: {c}', ['p' => $pkg, 'h' => $h['name'], 'c' => $w['cve'] ?: $w['vuln_id']]),
                    [t('{proc} luistert op poort {ports} ({addr})', ['proc' => $s['proc'], 'ports' => $ports, 'addr' => $s['addr'] ?: '*']),
                     t('Geïnstalleerd: {p} {v}; opgelost in {f}', ['p' => $pkg, 'v' => $w['version'], 'f' => $w['fixed_version']]),
                     ($w['cve'] ?: $w['vuln_id']) . ($sum !== '' ? ': ' . $sum : '') . ' (' . self::cveFacts($w) . ')',
                     count($cves) > 1 ? t('Nog {n} andere kwetsbaarheden in dit pakket worden met dezelfde update opgelost', ['n' => count($cves) - 1]) : '',
                     $atk['hits'] ? t('Poort {p} is de afgelopen 30 dagen {n} keer van buiten aangevallen, vanaf {i} IP-adressen', ['p' => $ports, 'n' => $atk['hits'], 'i' => $atk['ips']])
                        : t('Poort {p} is de afgelopen 30 dagen niet van buiten aangevallen', ['p' => $ports]),
                     $deps ? t('{n} andere servers hangen af van {h}', ['n' => $deps, 'h' => $h['name']]) : ''],
                    t('Installeer de update van {p} en herstart de dienst.', ['p' => $pkg]), [self::cmd($c, $hid, [$pkg])], array_values(array_filter([$act, ['k' => 'cve', 'cve' => $w['cve']]])),
                    ['host_id' => $hid, 'host' => $h['name'], 'cve' => $w['cve'], 'crit' => self::worldwide($w)]);
            }
        }
        return $out;
    }

    /** 2. Belangrijke CVE: actief misbruikt, kritiek met grote kans op misbruik, of net op de KEV-lijst gekomen */
    private static function vulnImportant(array $c): array
    {
        $by = [];
        foreach ($c['vulns'] as $f) {
            $imp = $f['kev'] || (($f['cvss'] ?? 0) >= 9 && ($f['epss'] ?? 0) >= 0.1) || ($f['epss'] ?? 0) >= 0.5;
            if (!$imp || !$f['cve'] || !isset($c['hosts'][$f['host_id']]) || isset(self::$covered[$f['cve']][$f['host_id']])) {
                continue;
            }
            $by[$f['cve']][] = $f;
        }
        $out = [];
        foreach ($by as $cve => $fs) {
            $w = $fs[0];
            $hosts = [];
            $pk = [];
            $first = $w['first_seen'];
            foreach ($fs as $f) {
                $hosts[$f['host_id']] = $c['hosts'][$f['host_id']]['name'];
                $pk[$f['host_id']][] = (string) $f['package'];
                $first = min($first, $f['first_seen']);
            }
            $new = strtotime((string) $first) > time() - 7 * 86400 || ($w['kev_added'] && strtotime((string) $w['kev_added']) > time() - 14 * 86400);
            $risk = Vulns::risk((int) $w['sev'], $w['cvss'], $w['kev'], $w['epss'], false) + min(10, count($hosts) * 2);
            $sum = self::summary((string) $w['vuln_id']);
            $pkgs = array_values(array_unique(array_merge(...array_values($pk))));
            $fixed = array_values(array_unique(array_filter(array_column($fs, 'fixed_version'))));
            $why = [($sum !== '' ? $sum : $cve) . ' (' . self::cveFacts($w) . ')',
                $w['kev'] ? t('Aanvallers gebruiken deze kwetsbaarheid nu al: hij staat op de lijst van CISA met actief misbruikte kwetsbaarheden') : '',
                t('Treft {p} op {h}', ['p' => implode(', ', array_slice($pkgs, 0, 4)), 'h' => implode(', ', $hosts)]),
                $fixed ? t('Opgelost in {f}', ['f' => implode(', ', array_slice($fixed, 0, 3))]) : t('Er is nog geen update; houd de leverancier in de gaten'),
                $new ? t('Nieuw: sinds {d} bekend bij RzSense', ['d' => date('d-m-Y', (int) strtotime((string) $first))]) : ''];
            $cmds = [];
            $acts = [];
            foreach (array_slice(array_keys($hosts), 0, 4) as $hid) {
                $cmds[] = self::cmd($c, $hid, array_values(array_unique($pk[$hid])));
                if ($a = self::upgradeAct($c, $hid, $pk[$hid])) {
                    $acts[] = $a + ['label' => $hosts[$hid]];
                }
            }
            $acts[] = ['k' => 'cve', 'cve' => $cve];
            $hk = array_keys($hosts);
            $out[] = self::a("vuln_important|$cve", 'vulns', self::worldwide($w) ? 2 : 1, $risk, $fixed ? (count($acts) > 1 ? 'click' : '10') : '30',
                ($new ? t('Nieuw: ') : '') . t('Belangrijke kwetsbaarheid {c} op {n} server(s): installeer de update', ['c' => $cve, 'n' => count($hosts)]),
                $why, $fixed ? t('Installeer de update op {h}, want {r}.', ['h' => implode(', ', $hosts), 'r' => $w['kev'] ? t('aanvallers misbruiken deze kwetsbaarheid al') : t('de kans op misbruik is groot')])
                    : t('Beperk de toegang tot {p} tot er een update is.', ['p' => implode(', ', array_slice($pkgs, 0, 3))]), $cmds, $acts,
                ['host_id' => count($hk) === 1 ? $hk[0] : null, 'host' => count($hk) === 1 ? $hosts[$hk[0]] : null, 'cve' => $cve, 'crit' => self::worldwide($w) && $fixed !== []]);
        }
        usort($out, static fn($a, $b) => $b['risk'] <=> $a['risk']);
        return array_slice($out, 0, 12);
    }

    /** 3. Updates lopen achter of een herstart is nodig */
    private static function updatesBehind(array $c): array
    {
        $out = [];
        $old = [];
        foreach ($c['vulns'] as $f) {
            if ($f['fixed_version'] !== null && strtotime((string) $f['first_seen']) < time() - 14 * 86400) {
                $old[$f['host_id']][(string) $f['package']] = true;
            }
        }
        foreach ($old as $hid => $pk) {
            if (!isset($c['hosts'][$hid]) || count($pk) < 3) {
                continue;
            }
            $act = self::upgradeAct($c, $hid, array_keys($pk));
            $out[] = self::a("updates_behind|$hid", 'vulns', count($pk) >= 15 ? 1 : 0, 30 + min(30, count($pk)), $act ? 'click' : '10',
                t('{h}: {n} pakketten hebben al meer dan 2 weken een beveiligingsupdate', ['h' => $c['hosts'][$hid]['name'], 'n' => count($pk)]),
                [t('Onder andere {p}', ['p' => implode(', ', array_slice(array_keys($pk), 0, 6))]), t('Hoe langer een bekende kwetsbaarheid openstaat, hoe groter de kans dat iemand hem vindt')],
                t('Werk de server bij, of zet automatische beveiligingsupdates aan.'), [self::cmd($c, $hid, array_slice(array_keys($pk), 0, 30))], array_values(array_filter([$act])),
                ['host_id' => $hid, 'host' => $c['hosts'][$hid]['name']]);
        }
        foreach ($c['inv'] as $hid => $i) {
            if (!isset($c['hosts'][$hid])) {
                continue;
            }
            $rk = $i['kernel_latest'] && $i['kernel_running'] && $i['kernel_latest'] !== $i['kernel_running'];
            $rs = (string) $i['restart_svc'];
            $svc = str_starts_with(ltrim($rs), '[') ? array_values(array_filter(array_map('strval', (array) json_decode($rs, true))))
                : array_values(array_filter(array_map('trim', explode(',', $rs))));
            if (!$rk && !$svc) {
                continue;
            }
            $out[] = self::a("restart|$hid", 'vulns', $rk ? 1 : 0, $rk ? 45 : 25, '5',
                $rk ? t('{h} moet herstarten: de nieuwe kernel wordt nog niet gebruikt', ['h' => $c['hosts'][$hid]['name']])
                    : t('{h}: {n} diensten draaien nog met oude bestanden', ['h' => $c['hosts'][$hid]['name'], 'n' => count($svc)]),
                [$rk ? t('Geïnstalleerd: kernel {l}; in gebruik: {r}', ['l' => $i['kernel_latest'], 'r' => $i['kernel_running']]) : '',
                 $rk ? t('Beveiligingsupdates van de kernel werken pas na een herstart') : '',
                 $svc ? t('Opnieuw starten: {s}', ['s' => implode(', ', array_slice($svc, 0, 8))]) : ''],
                $rk ? t('Herstart de server op een rustig moment.') : t('Herstart deze diensten.'),
                [[t('Herstarten'), $rk ? 'sudo systemctl reboot' : 'sudo systemctl restart ' . implode(' ', array_slice($svc, 0, 8))]], [], ['host_id' => $hid, 'host' => $c['hosts'][$hid]['name']]);
        }
        return $out;
    }

    /** 4. Besturingssysteem zonder (of binnenkort zonder) beveiligingsupdates */
    private static function eol(array $c): array
    {
        $out = [];
        foreach ($c['inv'] as $hid => $i) {
            $id = strtolower((string) $i['os_id']);
            $ver = (string) $i['os_version'];
            $list = self::EOL[$id] ?? null;
            if (!$list || !isset($c['hosts'][$hid])) {
                continue;
            }
            $key = isset($list[$ver]) ? $ver : (isset($list[(string) (int) $ver]) ? (string) (int) $ver : null);
            if ($key === null && preg_match('/^(\d+\.\d+)/', $ver, $m) && isset($list[$m[1]])) {
                $key = $m[1];
            }
            if ($key === null) {
                continue;
            }
            $end = strtotime($list[$key]);
            $days = (int) floor(($end - time()) / 86400);
            if ($days > 120) {
                continue;
            }
            $name = (string) ($i['os_name'] ?: "$id $ver");
            $out[] = self::a("eol|$hid", 'vulns', $days < 0 ? 2 : 1, $days < 0 ? 75 : 50, '60',
                $days < 0 ? t('{h} draait {o}, dat geen beveiligingsupdates meer krijgt', ['h' => $c['hosts'][$hid]['name'], 'o' => $name])
                    : t('{h}: {o} krijgt over {n} dagen geen beveiligingsupdates meer', ['h' => $c['hosts'][$hid]['name'], 'o' => $name, 'n' => $days]),
                [t('Einde ondersteuning: {d}', ['d' => date('d-m-Y', $end)]), t('Nieuwe kwetsbaarheden worden daarna niet meer opgelost; de CVE-scan kan ze ook niet meer goed beoordelen')],
                t('Plan een upgrade naar een ondersteunde versie.'), [], [], ['host_id' => $hid, 'host' => $c['hosts'][$hid]['name']]);
        }
        return $out;
    }

    /** 5. Hardening: per punt één advies voor alle servers waar het niet goed staat, met het bewijs uit de aanvallen */
    private static function hardening(array $c): array
    {
        if (!Hardening::enabled()) {
            return [];
        }
        $bad = [];
        foreach (q('SELECT host_id, item, value FROM ids_hardening WHERE ok = 0 AND ignored = 0')->fetchAll() as $r) {
            if (isset($c['hosts'][(int) $r['host_id']])) {
                $bad[$r['item']][(int) $r['host_id']] = $c['hosts'][(int) $r['host_id']]['name'];
            }
        }
        $dom = ['ssh_pw' => 'access', 'ssh_root' => 'access', 'ssh_empty' => 'access', 'empty_pw' => 'access', 'sudo_nopw' => 'access', 'firewall' => 'exposure',
            'open_db' => 'exposure', 'docker_sock' => 'exposure', 'autoupdate' => 'vulns', 'tmp_noexec' => 'detect', 'selinux' => 'detect'];
        $sevOf = ['ssh_pw' => 1, 'ssh_root' => 1, 'ssh_empty' => 2, 'empty_pw' => 2, 'open_db' => 2, 'docker_sock' => 2, 'firewall' => 1, 'autoupdate' => 1, 'sudo_nopw' => 0, 'tmp_noexec' => 0, 'selinux' => 0];
        $out = [];
        foreach ($bad as $item => $hosts) {
            $help = Hardening::help($item);
            [$title] = Hardening::text($item);
            $ids = array_keys($hosts);
            $in = implode(',', array_map('intval', $ids));
            $why = [$help['what'] . ' ' . t('Op: {h}.', ['h' => implode(', ', $hosts)]), $help['why']];
            $sev = $sevOf[$item] ?? 1;
            $exposed22 = array_sum(array_map(static fn($h) => $c['attacked']["$h:22"]['hits'] ?? 0, $ids));
            if ($item === 'ssh_pw' || $item === 'ssh_root') {
                $fail = (int) q("SELECT COALESCE(SUM(COALESCE(JSON_EXTRACT(detail, '$.n'), 1)), 0) FROM access_log WHERE kind = 'authfail' AND type = 'ssh' AND host_id IN ($in) AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
                if ($item === 'ssh_pw') {
                    $pw = (int) q("SELECT COUNT(*) FROM access_log WHERE kind = 'auth' AND type = 'ssh' AND host_id IN ($in) AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.m')) IN ('password', 'keyboard-interactive/pam') AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
                    $all = (int) q("SELECT COUNT(*) FROM access_log WHERE kind = 'auth' AND type = 'ssh' AND host_id IN ($in) AND created_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
                    $why[] = $fail ? t('{n} mislukte SSH-pogingen in 30 dagen op deze servers: zolang wachtwoorden werken, blijven ze raden', ['n' => $fail]) : '';
                    $why[] = $all ? ($pw ? t('{p} van de {a} logins gebruikten een wachtwoord; zet eerst een sleutel voor die accounts', ['p' => $pw, 'a' => $all])
                        : t('Alle {a} logins van de afgelopen 30 dagen gebruikten al een sleutel: je mist er niets aan', ['a' => $all])) : '';
                } else {
                    $root = (int) q("SELECT COUNT(*) FROM ssh_events WHERE ok = 0 AND username = 'root' AND host_id IN ($in)")->fetchColumn();
                    $why[] = $root ? t('{n} pogingen om als root in te loggen in de laatste 30 dagen', ['n' => $root]) : '';
                    $why[] = $fail ? t('{n} mislukte SSH-pogingen in totaal', ['n' => $fail]) : '';
                }
                if ($exposed22) {
                    $sev = 2;
                    $why[] = t('Poort 22 wordt van buiten aangevallen: deze servers zijn vanaf internet bereikbaar');
                }
            }
            $out[] = self::a("hard|$item", $dom[$item] ?? 'access', $sev, [2 => 75, 1 => 50, 0 => 25][$sev] + ($exposed22 ? 10 : 0), in_array($item, ['selinux', 'tmp_noexec', 'firewall'], true) ? '15' : '5',
                $title . ' (' . (count($hosts) > 1 ? t('nog niet op {n} servers: {l}', ['n' => count($hosts), 'l' => implode(', ', array_slice($hosts, 0, 4))]) : t('nog niet op {h}', ['h' => reset($hosts)])) . ')',
                $why, $help['fix'], $help['cmds'], [],
                ['host_id' => count($ids) === 1 ? $ids[0] : null, 'host' => count($ids) === 1 ? reset($hosts) : null, 'check' => $help['check'], 'note' => $help['caution']]);
        }
        return $out;
    }

    /** 6. Gevoelige dienst van buiten bereikbaar (blijkt uit aanvallen van buiten op die poort) */
    private static function exposed(array $c): array
    {
        $out = [];
        $by = [];
        foreach ($c['attacked'] as $k => $a) {
            [$hid, $port] = array_map('intval', explode(':', $k));
            if (!isset(self::SENSITIVE[$port], $c['hosts'][$hid]) || $a['hits'] < 20) {
                continue;
            }
            $by[$port][$hid] = $a;
        }
        $allow = IpIntel::allowedCountries();
        foreach ($by as $port => $hs) {
            [$svc, $danger] = self::SENSITIVE[$port];
            $hits = array_sum(array_column($hs, 'hits'));
            $cc = [];
            foreach ($hs as $a) {
                foreach ($a['cc'] as $k => $n) {
                    $cc[$k] = ($cc[$k] ?? 0) + $n;
                }
            }
            arsort($cc);
            $foreign = array_sum(array_filter($cc, static fn($n, $k) => !in_array($k, $allow, true), ARRAY_FILTER_USE_BOTH));
            $names = array_map(static fn($h) => $c['hosts'][$h]['name'], array_keys($hs));
            $share = $c['all_hits'] ? (int) round($hits * 100 / $c['all_hits']) : 0;
            $out[] = self::a("exposed|$port", 'exposure', $danger ? 2 : 1, ($danger ? 70 : 50) + min(15, (int) ($hits / 200)), '30',
                t('{s} (poort {p}) is van internet bereikbaar op {h}', ['s' => $svc, 'p' => $port, 'h' => implode(', ', $names)]),
                [t('{n} aanvallen van buiten in 30 dagen op poort {p}: {s}% van alle aanvallen', ['n' => $hits, 'p' => $port, 's' => $share]),
                 $cc ? t('Vooral uit {l}', ['l' => implode(', ', array_map(static fn($k, $n) => "$k ($n)", array_slice(array_keys($cc), 0, 4), array_slice(array_values($cc), 0, 4)))]) : '',
                 $foreign && $hits ? t('{p}% komt uit landen die niet op je lijst van toegestane landen staan', ['p' => (int) round($foreign * 100 / $hits)]) : '',
                 $danger ? t('{s} hoort nooit open naar internet: het wordt veel aangevallen en heeft een geschiedenis van ernstige lekken', ['s' => $svc]) : ''],
                $danger ? t('Zet de poort dicht in je router of firewall; gebruik een VPN (bijvoorbeeld WireGuard) als je er van buiten bij moet.')
                    : t('Laat {s} alleen via een VPN toe, of beperk het tot je eigen land (GeoIP) of vaste IP-adressen.', ['s' => $svc]),
                [], [['k' => 'go', 'go' => 'settings/ids_attack', 'label' => t('Landen blokkeren instellen')]]);
        }
        return $out;
    }

    /** 7. Verdachte logins: vanaf een ander land, met een wachtwoord van buiten, of kort na een brute-force-aanval */
    private static function logins(array $c): array
    {
        $out = [];
        $allow = IpIntel::allowedCountries();
        foreach (q("SELECT a.host_id, a.created_at, a.detail FROM access_log a WHERE a.kind = 'auth' AND a.type = 'ssh' AND a.created_at >= NOW() - INTERVAL 7 DAY ORDER BY a.created_at DESC LIMIT 500")->fetchAll() as $r) {
            $d = json_decode((string) $r['detail'], true) ?: [];
            $ip = (string) ($d['ip'] ?? '');
            $hid = (int) $r['host_id'];
            if ($ip === '' || Ids::internalIp($ip) || !isset($c['hosts'][$hid]) || AuthEvents::rowIgnored(['detail' => $r['detail']])) {
                continue;
            }
            $cc = (string) ($d['cc'] ?? '') ?: (IpIntel::country($ip) ?? '');
            $pw = in_array($d['m'] ?? '', ['password', 'keyboard-interactive/pam'], true);
            $net = implode('.', array_slice(explode('.', $ip), 0, 3)) . '.';
            $brute = str_contains($ip, '.') && (int) q("SELECT COUNT(*) FROM access_log WHERE kind = 'authfail' AND type = 'ssh' AND host_id = ? AND created_at BETWEEN ? - INTERVAL 1 HOUR AND ?
                AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.ip')) LIKE ?", [$hid, $r['created_at'], $r['created_at'], $net . '%'])->fetchColumn() > 0;
            $foreign = $cc !== '' && !in_array($cc, $allow, true);
            if (!$foreign && !$pw && !$brute) {
                continue;
            }
            $u = (string) ($d['user'] ?? '?');
            $key = "login|$hid|$u|$ip";
            if (isset($out[$key])) {
                continue;
            }
            $sev = $brute ? 2 : ($foreign ? 1 : 0);
            $out[$key] = self::a($key, 'access', $sev, $brute ? 85 : ($foreign ? 60 : 35), '10',
                t('Controleer de login van {u} op {h} vanaf {ip}', ['u' => $u, 'h' => $c['hosts'][$hid]['name'], 'ip' => $ip . ($cc ? " ($cc)" : '')]),
                [t('Op {t}', ['t' => date('d-m-Y H:i', (int) strtotime((string) $r['created_at']))]),
                 $foreign ? t('Uit {c}: dat land staat niet op je lijst van toegestane landen', ['c' => $cc]) : '',
                 $pw ? t('Ingelogd met een wachtwoord in plaats van een sleutel') : '',
                 $brute ? t('Uit hetzelfde netwerk werd in het uur ervoor geprobeerd wachtwoorden te raden: dit kan een geslaagde inbraak zijn') : ''],
                $brute ? t('Was jij dit niet? Blokkeer het IP, verander het wachtwoord van {u} en kijk wat er na de login gebeurde.', ['u' => $u])
                    : t('Was jij dit? Markeer het dan als gedaan. Zo niet: blokkeer het IP en verander het wachtwoord.'),
                [], [['k' => 'ip', 'ip' => $ip], ['k' => 'block', 'ip' => $ip]], ['host_id' => $hid, 'host' => $c['hosts'][$hid]['name']]);
        }
        return array_slice(array_values($out), 0, 8);
    }

    /** 8. IDS staat uit of alleen op leren */
    private static function idsMode(array $c): array
    {
        $n = (int) q('SELECT COALESCE(SUM(hits), 0) FROM ids_incidents WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetchColumn();
        if (!Ids::enabled()) {
            return [self::a('ids_off', 'detect', 2, 70, 'click', t('Het IDS staat uit'), [t('Aanvallen worden dan niet herkend en niet tegengehouden')],
                t('Zet het IDS aan.'), [], [['k' => 'ids_on']])];
        }
        if (Ids::blocking()) {
            return [];
        }
        return [self::a('ids_learn', 'detect', $n > 50 ? 1 : 0, 55, 'click', t('Het IDS staat op Leren: aanvallen worden alleen gemeld'),
            [t('{n} aanvalspogingen in de laatste 30 dagen zijn niet tegengehouden', ['n' => $n]), t('RzSense, je eigen netwerk en de whitelist worden nooit geblokkeerd')],
            t('Zet het IDS op Blokkeren.'), [], [['k' => 'ids_block']])];
    }

    /** 9. Gedeelde blokkades staan uit terwijl aanvallers meerdere servers proberen */
    private static function sharedBlocks(array $c): array
    {
        if (setting('ids_block_shared', '0') === '1' || !Ids::enabled()) {
            return [];
        }
        $multi = (int) q("SELECT COUNT(*) FROM (SELECT ip FROM ids_incidents WHERE ip IS NOT NULL AND ip <> '' AND (dir IS NULL OR dir <> 'o') AND last_seen >= NOW() - INTERVAL 30 DAY
                           GROUP BY ip HAVING COUNT(DISTINCT host_id) > 1) x")->fetchColumn();
        if ($multi < 2) {
            return [];
        }
        return [self::a('shared_blocks', 'detect', 1, 50, 'click', t('Zet gedeelde blokkades aan'),
            [t('{n} IP-adressen vielen in de laatste 30 dagen meer dan één server aan', ['n' => $multi]), t('Nu wordt een aanvaller alleen geblokkeerd op de server waar hij werd betrapt')],
            t('Met gedeelde blokkades wordt een automatische blokkade meteen op al je servers gezet.'), [], [['k' => 'set', 'name' => 'ids_block_shared', 'value' => '1']])];
    }

    /** 10. Aanvallers die terugkomen nadat hun blokkade verliep */
    private static function repeaters(array $c): array
    {
        $rows = q("SELECT b2.ip FROM ids_blocks b1 JOIN ids_blocks b2 ON b2.ip = b1.ip AND b2.created_at > COALESCE(b1.removed_at, b1.until, b1.created_at)
                    WHERE b2.created_at >= NOW() - INTERVAL 60 DAY GROUP BY b2.ip")->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) < 2) {
            return [];
        }
        $days = (int) setting('ids_repeat_days', '7');
        return [self::a('repeaters', 'detect', 1, 45, 'click', t('{n} aanvallers kwamen terug nadat hun blokkade verliep', ['n' => count($rows)]),
            [t('Onder andere {l}', ['l' => implode(', ', array_slice($rows, 0, 4))]), t('Bij herhaling wordt nu {d} dagen geblokkeerd', ['d' => $days])],
            t('Blokkeer herhalers langer, bijvoorbeeld 30 dagen.'), [], $days < 30 ? [['k' => 'set', 'name' => 'ids_repeat_days', 'value' => '30']] : [['k' => 'go', 'go' => 'ids/blk']])];
    }

    /** 11. Nieuwe open poorten: op een server (agent) of een netwerkapparaat (scan), vergeleken met eerder */
    private static function newPorts(array $c): array
    {
        $init = (int) setting('insight_seen_init', '0');
        if (!$init) {
            save_setting('insight_seen_init', (string) time());
        }
        $out = [];
        $mark = static function (string $k): int {
            q('INSERT IGNORE INTO insight_seen (k, first_seen) VALUES (?, NOW())', [$k]);
            return (int) strtotime((string) q('SELECT first_seen FROM insight_seen WHERE k = ?', [$k])->fetchColumn());
        };
        $ready = $init && $init < time() - 2 * 86400;   // de eerste twee dagen alleen leren wat er al open staat
        $local = static fn(string $a) => preg_match('/^(127\.|::1$|\[::1\]|localhost)/', $a) === 1;
        foreach ($c['ports'] as $hid => $list) {
            if (!isset($c['hosts'][$hid])) {
                continue;
            }
            foreach ($list as $p) {
                $port = (int) ($p['port'] ?? 0);
                if ($port <= 0 || $local((string) ($p['addr'] ?? ''))) {
                    continue;
                }
                $first = $mark("hp|$hid|$port");
                if ($ready && $first > time() - 3 * 86400) {
                    $out[] = self::a("newport|h$hid|$port", 'exposure', isset(self::SENSITIVE[$port]) ? 1 : 0, 40, '5',
                        t('Nieuwe open poort {p} op {h}', ['p' => $port, 'h' => $c['hosts'][$hid]['name']]),
                        [t('Sinds {d} luistert {proc} op {a}:{p}', ['d' => date('d-m-Y H:i', (int) $first), 'proc' => (string) ($p['proc'] ?? '?'), 'a' => (string) ($p['addr'] ?? '*'), 'p' => $port]),
                         t('Een poort die op alle netwerken luistert, is bereikbaar voor elk apparaat in je netwerk, en misschien van internet')],
                        t('Hoort dit zo? Markeer het als gedaan. Zo niet: stop de dienst of laat hem alleen op 127.0.0.1 luisteren.'), [[t('Wie luistert er'), "sudo ss -ltnp 'sport = :$port'"]], [],
                        ['host_id' => $hid, 'host' => $c['hosts'][$hid]['name']]);
                }
            }
        }
        if (NetScan::enabled()) {
            foreach (NetScan::list() as $d) {
                if ($d['status'] === 'ignored' || empty($d['mac'])) {
                    continue;
                }
                foreach ((array) $d['ports'] as $port) {
                    $port = (int) $port;
                    $first = $mark('dp|' . $d['mac'] . "|$port");
                    if ($ready && $first > time() - 3 * 86400) {
                        $out[] = self::a('newport|d' . $d['mac'] . "|$port", 'network', isset(self::SENSITIVE[$port]) ? 1 : 0, 35, '5',
                            t('Nieuwe open poort {p} op {d}', ['p' => $port, 'd' => $d['label']]),
                            [t('De netwerkscan zag poort {p} op {ip} voor het eerst op {t}', ['p' => $port, 'ip' => $d['ip'], 't' => date('d-m-Y H:i', (int) $first)])],
                            t('Hoort dit zo? Markeer het als gedaan. Zo niet: zet de dienst uit in de instellingen van het apparaat.'), [], [['k' => 'go', 'go' => 'net']]);
                    }
                }
            }
        }
        return array_slice($out, 0, 10);
    }

    /** 12. Een eigen apparaat praat met een bekende aanvaller of een verdachte bestemming */
    private static function outbound(array $c): array
    {
        $out = [];
        foreach (q("SELECT host_id, ip, cc, MAX(severity) AS sev, SUM(hits) AS hits, MAX(dport) AS dport, SUBSTRING_INDEX(GROUP_CONCAT(title ORDER BY last_seen DESC SEPARATOR '\n'), '\n', 1) AS title,
                           MAX(last_seen) AS last FROM ids_incidents WHERE dir = 'o' AND last_seen >= NOW() - INTERVAL 14 DAY AND ip IS NOT NULL GROUP BY host_id, ip, cc ORDER BY hits DESC LIMIT 8")->fetchAll() as $r) {
            $hid = (int) $r['host_id'];
            $h = $c['hosts'][$hid] ?? null;
            if (!$h) {
                continue;
            }
            $iot = in_array(HostKind::group($h), ['camera', 'smarthome'], true);
            $out[] = self::a("outbound|$hid|{$r['ip']}", 'network', (int) $r['sev'] >= 2 ? 2 : 1, (int) $r['sev'] >= 2 ? 85 : 60, '15',
                t('{h} maakt verbinding met {ip}{cc}', ['h' => $h['name'], 'ip' => $r['ip'], 'cc' => $r['cc'] ? " ({$r['cc']})" : '']),
                [(string) $r['title'], t('{n} keer in 14 dagen, laatst op {t}', ['n' => (int) $r['hits'], 't' => date('d-m-Y H:i', (int) strtotime((string) $r['last']))]),
                 $iot ? t('Een camera of slim apparaat dat zelf naar buiten belt, is vaak overgenomen of stuurt gegevens naar de fabrikant') : ''],
                $iot ? t('Blokkeer het adres en zet camera\'s en slimme apparaten in een eigen netwerk (VLAN) zonder toegang tot internet of je servers.')
                    : t('Blokkeer het adres en kijk welk programma de verbinding maakt.'),
                [], [['k' => 'block', 'ip' => (string) $r['ip']], ['k' => 'ip', 'ip' => (string) $r['ip']]], ['host_id' => $hid, 'host' => $h['name']]);
        }
        return $out;
    }

    /** 13. Netwerkapparaten: riskante poorten en apparaten die nog niet beoordeeld zijn */
    private static function devices(array $c): array
    {
        if (!NetScan::enabled()) {
            return [];
        }
        $out = [];
        $new = 0;
        foreach (NetScan::list() as $d) {
            if ($d['status'] === 'ignored') {
                continue;
            }
            $new += $d['status'] === 'new' ? 1 : 0;
            if ($d['risky']) {
                $names = array_map(static fn($p) => (self::SENSITIVE[(int) $p][0] ?? 'poort') . " $p", $d['risky']);
                $out[] = self::a('risky|' . ($d['mac'] ?: $d['ip']), 'network', array_intersect([23, 2375, 445, 3389, 5900, 21], $d['risky']) ? 1 : 0, 45, '10',
                    t('{d} ({ip}) heeft een riskante poort open: {p}', ['d' => $d['label'], 'ip' => $d['ip'], 'p' => implode(', ', $names)]),
                    [t('Gezien door de netwerkscan'), in_array(23, $d['risky'], true) ? t('Telnet stuurt wachtwoorden onversleuteld over het netwerk') : '',
                     in_array(445, $d['risky'], true) ? t('SMB wordt veel gebruikt door ransomware om zich te verspreiden') : ''],
                    t('Zet de dienst uit in de instellingen van het apparaat, of scherm hem af in je firewall.'), [], [['k' => 'go', 'go' => 'net']]);
            }
        }
        if ($new) {
            $out[] = self::a('net_new', 'network', $new >= 3 ? 1 : 0, 30, '5', t('{n} nieuwe apparaten op je netwerk zijn nog niet beoordeeld', ['n' => $new]),
                [t('Een onbekend apparaat kan een gast zijn, maar ook iemand die op je netwerk is gekomen')], t('Bekijk ze en geef ze een naam, of weer ze.'), [], [['k' => 'go', 'go' => 'net']]);
        }
        return $out;
    }

    /** 14. Verouderde software op apparaten zonder agent, uit wat de dienst zelf vertelt (banner) */
    private static function banners(array $c): array
    {
        if (!NetScan::enabled()) {
            return [];
        }
        $own = [];
        foreach ($c['hosts'] as $h) {
            if ($h['mode'] === 'agent') {
                foreach ([$h['address'], $h['agent_ip']] as $a) {
                    $own[(string) $a] = true;
                }
            }
        }
        $out = [];
        foreach (NetScan::list() as $d) {
            if ($d['status'] === 'ignored' || isset($own[$d['ip']]) || ($d['host'] && ($c['hosts'][(int) $d['host']['id']]['mode'] ?? '') === 'agent') || !empty($d['self'])) {
                continue;   // servers met een agent: daar kijkt de CVE-scan naar de echte pakketversies
            }
            $texts = array_map('strval', (array) ($d['info']['banners'] ?? []));
            if (!empty($d['info']['http']['server'])) {
                $texts[(int) ($d['info']['http']['port'] ?? 80)] = (string) $d['info']['http']['server'];
            }
            foreach ($texts as $port => $txt) {
                foreach (self::BANNERS as [$re, $prog, $min, $cve, $sev, $why]) {
                    if (!preg_match($re, $txt, $m)) {
                        continue;
                    }
                    $ver = $m[1] ?? '';
                    if ($min !== null && ($ver === '' || version_compare($ver, $min, '>='))) {
                        break;
                    }
                    if ($prog === 'OpenSSH' && version_compare($ver, '8.5', '<') && version_compare($ver, '4.4', '>=')) {
                        $why = 'OpenSSH ouder dan 8.5 heeft andere bekende lekken en krijgt geen updates meer van de makers';
                        $sev = 1;
                    }
                    $out[] = self::a('banner|' . ($d['mac'] ?: $d['ip']) . "|$port", 'network', $sev, $sev >= 2 ? 75 : 50, '30',
                        t('{d} draait een verouderde {p}{v}', ['d' => $d['label'], 'p' => $prog, 'v' => $ver !== '' && $min !== null ? ' ' . $ver : '']),
                        [t('De dienst op poort {p} meldt zichzelf als: {t}', ['p' => $port, 't' => mb_substr($txt, 0, 80)]),
                         "$cve: " . t($why), $min !== null ? t('Veilig vanaf versie {v}', ['v' => $min]) : '',
                         t('Dit apparaat heeft geen agent: de versie komt uit de netwerkscan en kan een fabriekspatch missen')],
                        t('Zoek een firmware-update van de fabrikant. Is die er niet meer, schakel de dienst dan uit of zet het apparaat in een afgeschermd netwerk.'),
                        [], [['k' => 'go', 'go' => 'net'], ['k' => 'cve', 'cve' => $cve]]);
                    break;
                }
            }
        }
        return array_slice($out, 0, 10);
    }

    /** 15. Diensten die worden aangevallen maar niet bewaakt, en servers zonder basis-checks */
    private static function unmonitored(array $c): array
    {
        $out = [];
        $types = [];
        foreach (q('SELECT host_id, type FROM checks WHERE enabled = 1')->fetchAll() as $r) {
            $types[(int) $r['host_id']][$r['type']] = true;
        }
        foreach (q("SELECT host_id, COUNT(*) AS n FROM ids_incidents WHERE type IN ('web_attack', 'web_cve', 'web_payload') AND last_seen >= NOW() - INTERVAL 30 DAY GROUP BY host_id")->fetchAll() as $r) {
            $hid = (int) $r['host_id'];
            if (isset($c['hosts'][$hid]) && empty($types[$hid]['ssl'])) {
                $out[] = self::a("nossl|$hid", 'recovery', 0, 25, 'click', t('{h}: webdiensten worden aangevallen, maar het certificaat wordt niet bewaakt', ['h' => $c['hosts'][$hid]['name']]),
                    [t('{n} webaanvallen in 30 dagen', ['n' => (int) $r['n']]), t('Een verlopen certificaat zie je anders pas als iemand klaagt')],
                    t('Voeg een SSL-check toe.'), [], [['k' => 'go', 'go' => 'dashboard?server=' . rawurlencode((string) $c['hosts'][$hid]['name'])]], ['host_id' => $hid, 'host' => $c['hosts'][$hid]['name']]);
            }
        }
        foreach ($c['hosts'] as $hid => $h) {
            if ($h['mode'] === 'agent' && empty($types[$hid]['disk'])) {
                $out[] = self::a("nodisk|$hid", 'recovery', 0, 20, 'click', t('{h} heeft geen diskcheck', ['h' => $h['name']]),
                    [t('Een volle disk legt diensten stil en kan logs en back-ups laten mislukken')], t('Voeg een diskcheck toe (de agent levert de gegevens al).'),
                    [], [['k' => 'go', 'go' => 'dashboard?server=' . rawurlencode((string) $h['name'])]], ['host_id' => $hid, 'host' => $h['name']]);
            }
        }
        return array_slice($out, 0, 8);
    }

    /** 16. Agents: stil, oud of niet versleuteld */
    private static function agents(array $c): array
    {
        $out = [];
        $old = [];
        foreach ($c['hosts'] as $hid => $h) {
            if ($h['mode'] !== 'agent') {
                continue;
            }
            if ($h['agent_version'] && version_compare((string) $h['agent_version'], AGENT_LATEST, '<')) {
                $old[] = $h['name'] . ' (' . $h['agent_version'] . ')';
            }
            if ($h['agent_seen'] && strtotime((string) $h['agent_seen']) < time() - 6 * 3600) {
                $out[] = self::a("agent_silent|$hid", 'detect', 1, 45, '10', t('De agent op {h} rapporteert niet meer', ['h' => $h['name']]),
                    [t('Laatste rapport: {t}', ['t' => date('d-m-Y H:i', (int) strtotime((string) $h['agent_seen']))]), t('Zolang de agent stil is, ziet het IDS niets op deze server')],
                    t('Controleer of de server aan staat en de agent draait.'), [[t('Status van de agent'), 'sudo systemctl status rzmon-agent.timer']], [],
                    ['host_id' => $hid, 'host' => $h['name']]);
            }
        }
        if ($old) {
            $out[] = self::a('agents_old', 'detect', 0, 25, 'click', t('{n} agents draaien een oudere versie', ['n' => count($old)]),
                [implode(', ', array_slice($old, 0, 6)), t('Nieuwste versie: {v}', ['v' => AGENT_LATEST])], t('Werk de agents bij.'), [], [['k' => 'go', 'go' => 'settings/agents']]);
        }
        return $out;
    }

    /** 17. RzSense zelf: beheerders zonder tweestapsverificatie */
    private static function rzmon(array $c): array
    {
        $rows = q("SELECT u.username FROM users u WHERE u.role = 'admin' AND (u.totp_secret IS NULL OR u.totp_secret = '') AND NOT EXISTS (SELECT 1 FROM passkeys p WHERE p.user_id = u.id)")->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) {
            return [];
        }
        return [self::a('rz_2fa', 'access', 1, 45, '5', t('{n} beheerder(s) van RzSense zonder tweestapsverificatie', ['n' => count($rows)]),
            [implode(', ', $rows), t('Een beheerder kan het IDS uitzetten en commando\'s op je servers laten uitvoeren')],
            t('Zet tweestapsverificatie (TOTP) of een passkey aan bij Mijn account.'), [], [['k' => 'go', 'go' => 'account']])];
    }

    /** 18. Trend: veel meer aanvallen dan de week ervoor */
    private static function trend(array $c): array
    {
        $now = (int) q('SELECT COALESCE(SUM(hits), 0) FROM ids_incidents WHERE last_seen >= NOW() - INTERVAL 7 DAY')->fetchColumn();
        $prev = (int) q('SELECT COALESCE(SUM(hits), 0) FROM ids_incidents WHERE last_seen >= NOW() - INTERVAL 14 DAY AND last_seen < NOW() - INTERVAL 7 DAY')->fetchColumn();
        if ($now < 100 || $now < $prev * 1.5) {
            return [];
        }
        $top = q("SELECT COALESCE(NULLIF(service, ''), CONCAT('poort ', dport)) AS s, SUM(hits) AS n FROM ids_incidents WHERE last_seen >= NOW() - INTERVAL 7 DAY AND dport IS NOT NULL GROUP BY s ORDER BY n DESC LIMIT 1")->fetch();
        $pct = $prev ? (int) round(($now - $prev) * 100 / $prev) : 100;
        return [self::a('trend|' . date('o-W'), 'detect', 0, 30, '5', t('Deze week {p}% meer aanvallen dan de week ervoor', ['p' => $pct]),
            [t('{n} aanvalspogingen in 7 dagen (de week ervoor {m})', ['n' => $now, 'm' => $prev]), $top ? t('Vooral op {s}', ['s' => $top['s']]) : ''],
            t('Kijk in de aanvalskaart wie het is; overweeg die dienst af te schermen.'), [], [['k' => 'go', 'go' => 'ids/map']])];
    }

    /** 19. Herstel en monitoring: wat Analyse al vond (back-ups, disks, heartbeats, ruis) */
    private static function recovery(array $c): array
    {
        $r = Analytics::cached('30d');
        $out = [];
        foreach ($r['capacity']['disks'] ?? [] as $d) {
            if (($d['days'] !== null && $d['days'] <= 30) || $d['pct'] >= 90) {
                $out[] = self::a("disk|{$d['host']}|{$d['mount']}", 'recovery', ($d['days'] !== null && $d['days'] <= 7) || $d['pct'] >= 95 ? 2 : 1, 50, '15',
                    $d['days'] !== null && $d['days'] <= 30 ? t('Disk {m} op {h} is over ~{n} dagen vol', ['m' => $d['mount'], 'h' => $d['host'], 'n' => (int) round($d['days'])])
                        : t('Disk {m} op {h} is {p}% vol', ['m' => $d['mount'], 'h' => $d['host'], 'p' => $d['pct']]),
                    [t('Nu {p}% vol', ['p' => $d['pct']]), t('Een volle disk legt diensten, logs en back-ups stil')], t('Ruim op of vergroot de disk.'), [], [['k' => 'go', 'go' => 'analytics']]);
            }
        }
        foreach ($r['backups']['pve'] ?? [] as $g) {
            $label = ($g['kind'] === 'ct' ? 'CT' : 'VM') . " {$g['vmid']} ({$g['name']})";
            if (!$g['last'] || $g['last'] < time() - 7 * 86400) {
                $out[] = self::a("pvebackup|{$g['host']}|{$g['vmid']}", 'recovery', $g['last'] ? 1 : 2, 55, '15',
                    $g['last'] ? t('{g} op {h}: laatste back-up {n} dagen geleden', ['g' => $label, 'h' => $g['host'], 'n' => intdiv(time() - (int) $g['last'], 86400)])
                        : t('{g} op {h} heeft nog nooit een back-up gehad', ['g' => $label, 'h' => $g['host']]),
                    [t('Zonder recente back-up ben je bij ransomware of een kapotte disk alles kwijt')], t('Neem deze guest op in een back-uptaak van Proxmox.'), [], []);
            }
        }
        foreach ($r['backups']['hb'] ?? [] as $x) {
            if ((int) $x['status'] === 2) {
                $out[] = self::a("hb|{$x['id']}", 'recovery', 1, 50, '15', t('Heartbeat {c} ({h}) is te laat of mislukt', ['c' => preg_replace('/^heartbeat\s+/i', '', (string) $x['name']), 'h' => $x['host']]),
                    [t('Vaak een back-up of script dat niet meer draait')], t('Kijk waarom het script niet meer meldt.'), [], [['k' => 'go', 'go' => 'check:' . $x['id']]]);
            }
        }
        $last = $r['backups']['rzmon'] ?? null;
        if (!$last || strtotime((string) $last) < time() - 8 * 86400) {
            $out[] = self::a('rz_backup', 'recovery', 1, 40, '5', $last ? t('De laatste back-up van RzSense zelf is van {d}', ['d' => date('d-m-Y', (int) strtotime((string) $last))]) : t('Er is nog geen back-up van RzSense zelf'),
                [t('Daarin staan al je checks, instellingen en sleutels van de agents')], t('Zet automatische back-ups aan.'), [], [['k' => 'go', 'go' => 'settings/backup']]);
        }
        foreach (array_slice($r['noise']['quick'] ?? [], 0, 3) as $q) {
            if ($q['n'] >= 3) {
                $out[] = self::a("noise|{$q['id']}", 'recovery', 0, 15, 'click', t('{c} op {h} gaf {n}× vals alarm', ['c' => $q['name'], 'h' => $q['host'], 'n' => $q['n']]),
                    [t('Telkens binnen 5 minuten weer goed'), t('Te veel meldingen zorgen ervoor dat je de echte mist')], t('Zet bij deze check "melden na 3 fouten".'), [], [['k' => 'go', 'go' => 'check:' . $q['id']]]);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ analyses (de patronen achter de adviezen)

    /** Aanvallen: wanneer, welke gebruikersnamen, werken de blokkades, en het aanvalsoppervlak per server */
    public static function analyses(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        // Wanneer: per weekdag (0 = maandag) en uur, uit de losse SSH-pogingen en de meldingen
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        foreach (q("SELECT WEEKDAY(at) AS d, HOUR(at) AS h, COUNT(*) AS n FROM ssh_events WHERE ok = 0 AND at >= ? GROUP BY d, h", [$since])->fetchAll() as $r) {
            $heat[(int) $r['d']][(int) $r['h']] += (int) $r['n'];
        }
        foreach (q("SELECT WEEKDAY(created_at) AS d, HOUR(created_at) AS h, SUM(hits) AS n FROM ids_incidents WHERE created_at >= ? AND type NOT IN ('bruteforce', 'chain', 'anomaly') GROUP BY d, h", [$since])->fetchAll() as $r) {
            $heat[(int) $r['d']][(int) $r['h']] += (int) $r['n'];
        }
        // Gebruikersnamen: welke worden geprobeerd, en bestaan ze (er is ooit mee ingelogd)?
        $known = array_flip(array_merge(['root'], q('SELECT DISTINCT username FROM ssh_events WHERE ok = 1')->fetchAll(PDO::FETCH_COLUMN),
            q("SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(detail, '$.user')) FROM access_log WHERE kind = 'auth' AND type = 'ssh'")->fetchAll(PDO::FETCH_COLUMN)));
        $users = q("SELECT username AS u, COUNT(*) AS n FROM ssh_events WHERE ok = 0 AND at >= ? AND username <> '' GROUP BY username ORDER BY n DESC LIMIT 10", [$since])->fetchAll();
        $utot = (int) q("SELECT COUNT(*) FROM ssh_events WHERE ok = 0 AND at >= ?", [$since])->fetchColumn();
        $users = array_map(static fn($r) => ['u' => $r['u'], 'n' => (int) $r['n'], 'pct' => $utot ? round($r['n'] * 100 / $utot, 1) : 0, 'exists' => isset($known[$r['u']])], $users);
        // Werken de blokkades: pogingen in het uur voor en na een blokkade, tijd tot de blokkade, herhalers
        $before = $after = 0;
        $ttb = [];
        foreach (q('SELECT ip, created_at FROM ids_blocks WHERE created_at >= ? ORDER BY created_at DESC LIMIT 200', [$since])->fetchAll() as $b) {
            $at = (string) $b['created_at'];
            $before += (int) q('SELECT COUNT(*) FROM ssh_events WHERE ip = ? AND ok = 0 AND at BETWEEN ? - INTERVAL 1 HOUR AND ?', [$b['ip'], $at, $at])->fetchColumn();
            $after += (int) q('SELECT COUNT(*) FROM ssh_events WHERE ip = ? AND ok = 0 AND at > ? AND at <= ? + INTERVAL 1 HOUR', [$b['ip'], $at, $at])->fetchColumn();
            $first = q('SELECT MIN(at) FROM ssh_events WHERE ip = ? AND ok = 0 AND at BETWEEN ? - INTERVAL 1 DAY AND ?', [$b['ip'], $at, $at])->fetchColumn();
            if ($first) {
                $ttb[] = max(0, strtotime($at) - strtotime((string) $first));
            }
        }
        $rep = (int) q("SELECT COUNT(DISTINCT b2.ip) FROM ids_blocks b1 JOIN ids_blocks b2 ON b2.ip = b1.ip AND b2.created_at > COALESCE(b1.removed_at, b1.until, b1.created_at) WHERE b2.created_at >= ?", [$since])->fetchColumn();
        // Poorten onder vuur
        $ports = q("SELECT dport, COALESCE(MAX(NULLIF(service, '')), '') AS svc, SUM(hits) AS n, COUNT(DISTINCT ip) AS ips FROM ids_incidents WHERE dport IS NOT NULL AND (dir IS NULL OR dir <> 'o') AND last_seen >= ?
                    GROUP BY dport ORDER BY n DESC LIMIT 8", [$since])->fetchAll();
        // Aanvalsoppervlak per server
        $hard = Hardening::scores();
        $atk = [];
        foreach (q("SELECT host_id, dport, ip, SUM(hits) AS n FROM ids_incidents WHERE dport IS NOT NULL AND (dir IS NULL OR dir <> 'o') AND last_seen >= ? GROUP BY host_id, dport, ip", [$since])->fetchAll() as $r) {
            if (!Ids::internalIp((string) $r['ip'])) {
                $atk[(int) $r['host_id']][(int) $r['dport']] = ($atk[(int) $r['host_id']][(int) $r['dport']] ?? 0) + (int) $r['n'];
            }
        }
        $surface = [];
        foreach (q("SELECT id, name, mode FROM hosts WHERE enabled = 1 AND mode = 'agent' ORDER BY name")->fetchAll() as $h) {
            $ex = HostRisk::exposure((int) $h['id']);
            if (!$ex) {
                continue;
            }
            $worst = null;
            foreach ($ex['ports'] as $p) {
                if ($p['vuln'] && (!$worst || $p['vuln']['risk'] > $worst['risk'])) {
                    $worst = $p['vuln'] + ['port' => $p['port']];
                }
            }
            $surface[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'ports' => array_map(static fn($p) => ['port' => $p['port'], 'proc' => $p['proc'], 'out' => isset($atk[(int) $h['id']][$p['port']])], array_slice($ex['ports'], 0, 12)),
                'worst' => $worst, 'attacks' => array_sum($atk[(int) $h['id']] ?? []), 'hardening' => $hard[(int) $h['id']]['score'] ?? null, 'risk' => $ex['risk']['score'] ?? 0];
        }
        usort($surface, static fn($a, $b) => $b['risk'] <=> $a['risk'] ?: $b['attacks'] <=> $a['attacks']);
        return ['days' => $days, 'heat' => $heat, 'users' => $users, 'tries' => $utot,
            'blocks' => ['before' => $before, 'after' => $after, 'drop' => $before ? (int) round(($before - $after) * 100 / $before) : null,
                'ttb' => $ttb ? (int) round(array_sum($ttb) / count($ttb)) : null, 'repeat' => $rep],
            'ports' => array_map(static fn($r) => ['port' => (int) $r['dport'], 'svc' => $r['svc'], 'n' => (int) $r['n'], 'ips' => (int) $r['ips']], $ports), 'surface' => $surface];
    }
}
