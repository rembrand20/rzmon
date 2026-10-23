<?php
declare(strict_types=1);

/**
 * Kwetsbaarheden: welke software staat er op de servers en welke bekende kwetsbaarheden (CVE's) horen daarbij.
 *
 * - De agent (1.47.0+) stuurt zijn pakketlijst mee (dpkg of rpm, met de distributie); voor de RzMon-server leest RzMon hem zelf.
 * - RzMon vraagt de kwetsbaarheden op bij OSV.dev (alleen pakketnaam, versie en distributie; geen servernamen of IP's).
 *   OSV kent de adviezen van de distributies zelf, dus ook teruggezette reparaties (backports): geen vals alarm bij "oude" versies.
 * - Aanvullend: actief misbruikt (CISA KEV), kans op misbruik (EPSS) en zo nodig de CVSS-score van NVD.
 * - Scannen: elke dag op het ingestelde tijdstip, en meteen als de pakketlijst van een server verandert (via cron).
 * Aan/uit en instellingen: Instellingen → 🐞 Kwetsbaarheden (vuln_enabled, vuln_time, vuln_min_severity, vuln_exclude).
 */
final class Vulns
{
    /** Ernst: 0 = onbekend, 1 = low, 2 = medium, 3 = high, 4 = critical */
    public const SEVERITY = [0 => 'onbekend', 1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];
    public const AGENT_MIN = '1.47.0';
    public const PROGRESS_MIN = '1.48.0';   // agent die tijdens het bijwerken zijn voortgang meldt
    private const OSV = 'https://api.osv.dev/v1';
    private const KEV = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
    private const EPSS = 'https://api.first.org/data/v1/epss';
    private const NVD = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    public static function enabled(): bool
    {
        return setting('vuln_enabled', '0') === '1';
    }

    /** @return int[] servers die niet gescand worden */
    public static function excluded(): array
    {
        return array_map('intval', array_filter(explode(',', (string) setting('vuln_exclude', ''))));
    }

    // ------------------------------------------------------------------ distributie → OSV

    /** Ecosysteem in OSV voor deze distributie, of null als die (nog) niet ondersteund wordt */
    public static function ecosystem(string $id, string $version): ?string
    {
        $major = explode('.', $version)[0];
        return match (true) {
            in_array($id, ['debian', 'raspbian'], true) && ctype_digit($major) => "Debian:$major",
            $id === 'ubuntu' && preg_match('/^(\d\d)\.(\d\d)$/', $version, $m) === 1
                => 'Ubuntu:' . $version . ($m[2] === '04' && (int) $m[1] % 2 === 0 ? ':LTS' : ''),
            $id === 'rocky' && ctype_digit($major) => "Rocky Linux:$major",
            // RHEL en CentOS Stream: dezelfde pakketten en versies als AlmaLinux, dus de adviezen van AlmaLinux
            in_array($id, ['almalinux', 'rhel', 'centos'], true) && ctype_digit($major) => "AlmaLinux:$major",
            $id === 'alpine' && preg_match('/^(\d+\.\d+)/', $version, $m) === 1 => 'Alpine:v' . $m[1],
            default => null,
        };
    }

    // ------------------------------------------------------------------ softwarelijst opslaan

    /**
     * Rapport van de agent: softwarelijst opslaan en de uitkomst van "bijwerken" verwerken.
     * @return array velden voor het antwoord aan de agent (packages_ok / packages_resend / pkg_upgrade)
     */
    public static function agentReport(array $host, array $in): array
    {
        $out = [];
        if (isset($in['pkg_result']) && is_array($in['pkg_result'])) {
            self::upgradeResult($host, $in['pkg_result']);
        }
        $p = $in['packages'] ?? null;
        if (is_array($p) && preg_match('/^[0-9a-f]{16}$/', (string) ($p['hash'] ?? ''))) {
            if (!self::enabled()) {
                $out['packages_ok'] = true;   // uit: niets bewaren, en de agent hoeft de lijst niet steeds opnieuw te sturen
            } elseif (is_array($p['data'] ?? null)) {
                self::storeInventory((int) $host['id'], (string) $p['hash'], $p['data']);
                $out['packages_ok'] = true;
            } elseif (q('SELECT hash FROM vuln_inventory WHERE host_id = ?', [$host['id']])->fetchColumn() === $p['hash']) {
                $out['packages_ok'] = true;
            } else {
                $out['packages_resend'] = true;   // RzMon heeft deze stand niet (bv. net aangezet): alles opnieuw
            }
        }
        if ($u = self::pendingUpgrade((int) $host['id'])) {
            $out['pkg_upgrade'] = $u;
        }
        return $out;
    }

    /** Softwarelijst van een server opslaan; bij een andere lijst wordt hij opnieuw gescand */
    public static function storeInventory(int $hostId, string $hash, array $d): void
    {
        $s = static fn($v, int $max = 150) => mb_substr(preg_replace('/[\x00-\x1f"\\\\]/', '', (string) $v), 0, $max);
        $mgr = in_array($d['mgr'] ?? '', ['dpkg', 'rpm'], true) ? $d['mgr'] : null;
        if ($mgr === null) {
            return;
        }
        $list = [];
        foreach (array_slice((array) ($d['list'] ?? []), 0, 30000) as $r) {
            if (is_array($r) && ($name = $s($r[0] ?? '')) !== '' && ($ver = $s($r[1] ?? '', 120)) !== '') {
                $list[] = [$name, $ver, $s($r[2] ?? '', 20), $s($r[3] ?? '') ?: $name, $s($r[4] ?? '', 120) ?: $ver];
            }
        }
        $exposed = [];
        foreach (array_slice((array) ($d['exposed'] ?? []), 0, 200) as $e) {
            if (is_array($e) && ($n = $s($e[0] ?? '')) !== '') {
                $exposed[] = [$n, (int) ($e[1] ?? 0), ($e[2] ?? '') === 'udp' ? 'udp' : 'tcp'];
            }
        }
        $restart = array_values(array_filter(array_map(fn($x) => $s($x, 100), array_slice((array) ($d['restart'] ?? []), 0, 50))));
        $os = (array) ($d['os'] ?? []);
        $osId = strtolower($s($os['id'] ?? '', 40));
        $osVer = $s($os['version'] ?? '', 40);
        $prev = q('SELECT hash FROM vuln_inventory WHERE host_id = ?', [$hostId])->fetchColumn();
        q('INSERT INTO vuln_inventory (host_id, mgr, os_id, os_version, os_name, ecosystem, hash, packages, exposed, restart_svc, kernel_running, kernel_latest, updated_at, scanned_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
           ON DUPLICATE KEY UPDATE mgr = VALUES(mgr), os_id = VALUES(os_id), os_version = VALUES(os_version), os_name = VALUES(os_name), ecosystem = VALUES(ecosystem),
             hash = VALUES(hash), packages = VALUES(packages), exposed = VALUES(exposed), restart_svc = VALUES(restart_svc), kernel_running = VALUES(kernel_running),
             kernel_latest = VALUES(kernel_latest), updated_at = VALUES(updated_at), scanned_at = IF(?, scanned_at, NULL)', [
            $hostId, $mgr, $osId, $osVer, $s($os['name'] ?? '', 120), self::ecosystem($osId, $osVer), $hash,
            json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), json_encode($exposed), json_encode($restart),
            $s($d['kernel']['running'] ?? '', 100) ?: null, $s($d['kernel']['latest'] ?? '', 100) ?: null, now(), $prev === $hash ? 1 : 0,   // zelfde lijst: niet opnieuw scannen
        ]);
    }

    /** De RzMon-server zelf: pakketlijst direct uitlezen (één keer per dag, of als de pakketdatabase veranderde) */
    private static function localInventory(): void
    {
        foreach (q("SELECT id FROM hosts WHERE mode = 'local' AND enabled = 1")->fetchAll(PDO::FETCH_COLUMN) as $hid) {
            $db = is_file('/var/lib/dpkg/status') ? '/var/lib/dpkg/status' : (is_file('/var/lib/rpm/rpmdb.sqlite') ? '/var/lib/rpm/rpmdb.sqlite' : null);
            $at = q('SELECT UNIX_TIMESTAMP(updated_at) FROM vuln_inventory WHERE host_id = ?', [$hid])->fetchColumn();
            if ($at && (int) $at > time() - 86400 && (!$db || filemtime($db) < (int) $at)) {
                continue;
            }
            if (!function_exists('shell_exec')) {
                return;
            }
            if ($db === '/var/lib/dpkg/status') {
                $mgr = 'dpkg';
                $raw = (string) shell_exec("dpkg-query -W -f='\${db:Status-Abbrev}\\t\${Package}\\t\${Version}\\t\${Architecture}\\t\${source:Package}\\t\${source:Version}\\n' 2>/dev/null");
                $rows = [];
                foreach (explode("\n", $raw) as $l) {
                    $f = explode("\t", $l);
                    if (count($f) >= 6 && str_starts_with($f[0], 'ii')) {
                        $rows[] = [$f[1], $f[2], $f[3], $f[4], $f[5]];
                    }
                }
            } else {
                $mgr = 'rpm';
                $raw = (string) shell_exec("rpm -qa --qf '%{NAME}\\t%{EPOCHNUM}:%{VERSION}-%{RELEASE}\\t%{ARCH}\\t%{SOURCERPM}\\n' 2>/dev/null");
                $rows = [];
                foreach (explode("\n", $raw) as $l) {
                    $f = explode("\t", $l);
                    if (count($f) >= 4 && $f[0] !== 'gpg-pubkey') {
                        $v = preg_replace('/^0:/', '', $f[1]);
                        $rows[] = [$f[0], $v, $f[2], preg_replace('/-[^-]+-[^-]+\.src\.rpm$/', '', $f[3]), $v];
                    }
                }
            }
            if (!$rows) {
                continue;
            }
            $os = @parse_ini_file('/etc/os-release') ?: [];
            $kern = array_map(fn($f) => substr(basename($f), 8), glob('/boot/vmlinuz-*') ?: []);
            usort($kern, 'version_compare');
            $d = ['mgr' => $mgr, 'os' => ['id' => $os['ID'] ?? '', 'version' => $os['VERSION_ID'] ?? '', 'name' => $os['PRETTY_NAME'] ?? ''],
                'kernel' => ['running' => php_uname('r'), 'latest' => end($kern) ?: ''], 'restart' => [], 'exposed' => [], 'list' => $rows];
            self::storeInventory((int) $hid, substr(sha1(json_encode($d)), 0, 16), $d);
        }
    }

    // ------------------------------------------------------------------ cron

    /** Elke minuut (cron): softwarelijst van de RzMon-server, dagelijkse scan, gewijzigde servers scannen, aanvullen, melden */
    public static function cron(int $budget = 45): ?string
    {
        if (!self::enabled() || (int) q("SELECT GET_LOCK('rzmon_vulns', 0)")->fetchColumn() !== 1) {
            return null;
        }
        $end = time() + $budget;
        $msg = null;
        try {
            self::localInventory();
            // Dagelijks: alles opnieuw (nieuwe CVE's voor dezelfde pakketten) en de lijst "actief misbruikt" verversen
            if (date('H:i') >= (string) setting('vuln_time', '03:30') && setting('vuln_daily', '') !== date('Y-m-d')) {
                save_setting('vuln_daily', date('Y-m-d'));
                q('UPDATE vuln_inventory SET scanned_at = NULL');
                self::refreshKev();
            }
            $n = 0;
            $ex = self::excluded();
            // Nog niet gescand, of de vorige keer mislukt (dan elk uur opnieuw proberen)
            foreach (q('SELECT host_id FROM vuln_inventory WHERE scanned_at IS NULL OR (scan_error IS NOT NULL AND ecosystem IS NOT NULL AND scanned_at < NOW() - INTERVAL 1 HOUR)
                        ORDER BY scanned_at IS NOT NULL, updated_at')->fetchAll(PDO::FETCH_COLUMN) as $hid) {
                if (time() >= $end) {
                    break;
                }
                if (in_array((int) $hid, $ex, true)) {
                    continue;
                }
                $r = self::scanHost((int) $hid);
                $n++;
                if ($r !== null) {
                    $msg = $r;
                }
            }
            if (time() < $end) {
                self::enrich($end);
            }
            self::notifyNew();
            if ($n) {
                save_setting('vuln_last_scan', now());
            }
            return $msg ?? ($n ? t('Kwetsbaarheden: {n} server(s) gescand', ['n' => $n]) : null);
        } finally {
            q("DO RELEASE_LOCK('rzmon_vulns')");
        }
    }

    /** Eén server scannen. @return string|null foutmelding */
    public static function scanHost(int $hostId): ?string
    {
        $inv = q('SELECT * FROM vuln_inventory WHERE host_id = ?', [$hostId])->fetch();
        if (!$inv) {
            return null;
        }
        $done = static fn(?string $err) => q('UPDATE vuln_inventory SET scanned_at = ?, scan_error = ? WHERE host_id = ?', [now(), $err, $hostId]);
        $eco = $inv['ecosystem'];
        if (!$eco) {
            q('DELETE FROM vuln_findings WHERE host_id = ?', [$hostId]);
            $done(t('Deze distributie wordt nog niet ondersteund: {os}', ['os' => $inv['os_name'] ?: $inv['os_id'] ?: '?']));
            return null;
        }
        // Welke vragen aan OSV: Debian/Ubuntu per bronpakket (zo staan de adviezen erin), rpm/apk per pakket
        $groups = [];
        foreach ((array) json_decode((string) $inv['packages'], true) as [$name, $ver, , $src, $srcVer]) {
            $key = $inv['mgr'] === 'dpkg' ? [$src, $srcVer] : [$name, $ver];
            $groups[$key[0] . "\t" . $key[1]][] = $name;
        }
        $keys = array_keys($groups);
        $hits = [];
        try {
            foreach (array_chunk($keys, 1000) as $chunk) {
                $q = array_map(fn($k) => ['package' => ['name' => explode("\t", $k)[0], 'ecosystem' => $eco], 'version' => explode("\t", $k)[1]], $chunk);
                $r = self::http('POST', self::OSV . '/querybatch', ['queries' => $q]);
                foreach ((array) ($r['results'] ?? []) as $i => $res) {
                    foreach ((array) ($res['vulns'] ?? []) as $v) {
                        if (isset($v['id'])) {
                            $hits[$chunk[$i]][(string) $v['id']] = (string) ($v['modified'] ?? '');
                        }
                    }
                }
            }
            $all = [];
            foreach ($hits as $ids) {
                $all += $ids;
            }
            self::fetchVulns($all);
        } catch (VulnFetchError $e) {
            $done(t('OSV.dev niet bereikbaar') . ': ' . $e->getMessage());
            return t('Kwetsbaarheden: OSV.dev niet bereikbaar') . ': ' . $e->getMessage();
        } catch (Throwable $e) {
            $done(mb_substr(t('Scannen mislukt') . ': ' . $e->getMessage(), 0, 300));
            return t('Kwetsbaarheden: scannen mislukt') . ': ' . $e->getMessage();
        }

        $cache = [];
        if ($hits) {
            $ids = array_keys(array_merge(...array_values($hits)));
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (q('SELECT vuln_id, data FROM vuln_cache WHERE vuln_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
                    $cache[$row['vuln_id']] = json_decode((string) $row['data'], true) ?: [];
                }
            }
        }
        $old = [];
        foreach (q('SELECT id, package, vuln_id FROM vuln_findings WHERE host_id = ?', [$hostId]) as $f) {
            $old[$f['package'] . "\t" . $f['vuln_id']] = (int) $f['id'];
        }
        $keep = [];
        $ins = db()->prepare('INSERT INTO vuln_findings (host_id, package, version, source, vuln_id, cve, fixed_version, first_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE version = VALUES(version), source = VALUES(source), cve = VALUES(cve), fixed_version = VALUES(fixed_version)');
        foreach ($hits as $key => $ids) {
            [$pkg, $ver] = explode("\t", $key);
            foreach ($ids as $vid => $_) {
                $rec = $cache[$vid] ?? ['id' => $vid];
                $cve = self::primaryCve($rec);
                self::cveFromRecord($cve, $rec);
                $ins->execute([$hostId, $pkg, $ver, mb_substr(implode(', ', array_unique($groups[$key])), 0, 150), $vid, $cve,
                    self::fixedVersion($rec, $eco, $pkg, $ver, $inv['mgr']), now()]);
                $keep[$pkg . "\t" . $vid] = true;
            }
        }
        $gone = array_diff_key($old, $keep);
        foreach (array_chunk(array_values($gone), 500) as $chunk) {
            q('DELETE FROM vuln_findings WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
        $done(null);
        return null;
    }

    /** Gegevens van kwetsbaarheden ophalen die nog niet (of in een oudere versie) in de cache staan */
    private static function fetchVulns(array $modifiedById): void
    {
        $need = [];
        foreach (array_chunk(array_map('strval', array_keys($modifiedById)), 500) as $chunk) {
            $have = q('SELECT vuln_id, modified FROM vuln_cache WHERE vuln_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($chunk as $id) {
                if (!isset($have[$id]) || ($modifiedById[$id] !== '' && $have[$id] !== $modifiedById[$id])) {
                    $need[] = (string) $id;
                }
            }
        }
        $st = db()->prepare('INSERT INTO vuln_cache (vuln_id, modified, data, fetched_at) VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE modified = VALUES(modified), data = VALUES(data), fetched_at = VALUES(fetched_at)');
        foreach (array_chunk($need, 10) as $chunk) {
            foreach (self::httpMulti(array_map(fn($id) => self::OSV . '/vulns/' . rawurlencode($id), $chunk)) as $i => $rec) {
                if (is_array($rec) && isset($rec['id'])) {
                    $st->execute([$chunk[$i], (string) ($rec['modified'] ?? ''), json_encode(self::trim($rec), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now()]);
                }
            }
        }
    }

    /** Alleen bewaren wat RzMon gebruikt (zonder de soms enorme lijsten met versies) */
    private static function trim(array $r): array
    {
        $aff = [];
        foreach (array_slice((array) ($r['affected'] ?? []), 0, 100) as $a) {
            $events = [];
            foreach ((array) ($a['ranges'] ?? []) as $rg) {
                if (in_array($rg['type'] ?? '', ['ECOSYSTEM', 'SEMVER'], true)) {
                    $events = array_merge($events, array_slice((array) ($rg['events'] ?? []), 0, 50));
                }
            }
            $aff[] = ['eco' => (string) ($a['package']['ecosystem'] ?? ''), 'name' => (string) ($a['package']['name'] ?? ''), 'events' => $events,
                'sev' => (string) ($a['ecosystem_specific']['severity'] ?? $a['database_specific']['severity'] ?? '')];
        }
        $refs = [];
        foreach (array_slice((array) ($r['references'] ?? []), 0, 20) as $ref) {
            if (preg_match('#^https?://#', (string) ($ref['url'] ?? ''))) {
                $refs[] = ['type' => (string) ($ref['type'] ?? 'WEB'), 'url' => (string) $ref['url']];
            }
        }
        return [
            'id' => (string) $r['id'],
            'summary' => mb_substr((string) ($r['summary'] ?? ''), 0, 300),
            'details' => mb_substr((string) ($r['details'] ?? ''), 0, 4000),
            'aliases' => array_values(array_slice((array) ($r['aliases'] ?? []), 0, 20)),
            'published' => (string) ($r['published'] ?? ''),
            'modified' => (string) ($r['modified'] ?? ''),
            'severity' => array_values(array_slice((array) ($r['severity'] ?? []), 0, 5)),
            'db_sev' => (string) ($r['database_specific']['severity'] ?? ''),
            'affected' => $aff,
            'references' => $refs,
        ];
    }

    /** De CVE van een advies (RLSA-…, DSA-…, USN-… hebben er een of meer als alias); anders het eigen nummer */
    public static function primaryCve(array $rec): string
    {
        $id = (string) ($rec['id'] ?? '');
        if (preg_match('/CVE-\d{4}-\d+$/', $id, $m)) {   // ook UBUNTU-CVE-…, DEBIAN-CVE-…
            return $m[0];
        }
        foreach ((array) ($rec['aliases'] ?? []) as $a) {
            if (preg_match('/^CVE-\d{4}-\d+$/', (string) $a)) {
                return (string) $a;
            }
        }
        return $id;
    }

    /** Ernst en CVSS uit het advies bewaren bij de CVE (als die er nog geen heeft) */
    private static function cveFromRecord(string $cve, array $rec): void
    {
        [$sev, $score, $vector] = self::recordSeverity($rec);
        $kev = in_array($cve, self::kevList(), true) ? 1 : 0;
        q('INSERT INTO vuln_cve (cve, severity, cvss, vector, kev) VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE severity = IF(severity = 0 OR (cvss IS NULL AND VALUES(cvss) IS NOT NULL), VALUES(severity), severity),
             cvss = COALESCE(cvss, VALUES(cvss)), vector = COALESCE(vector, VALUES(vector)), kev = GREATEST(kev, VALUES(kev))',
            [$cve, $sev, $score, $vector, $kev]);
    }

    /** @return array{0: int, 1: float|null, 2: string|null} ernst (0-4), CVSS-score, vector */
    public static function recordSeverity(array $rec): array
    {
        foreach ((array) ($rec['severity'] ?? []) as $s) {
            if (str_starts_with((string) ($s['type'] ?? ''), 'CVSS_V3') && ($score = self::cvss3((string) ($s['score'] ?? ''))) !== null) {
                return [self::fromScore($score), $score, (string) $s['score']];
            }
        }
        $words = array_merge([(string) ($rec['db_sev'] ?? '')], array_map(fn($s) => (string) ($s['score'] ?? ''), (array) ($rec['severity'] ?? [])),
            array_map(fn($a) => (string) ($a['sev'] ?? ''), (array) ($rec['affected'] ?? [])));
        $best = 0;
        foreach ($words as $w) {
            $best = max($best, self::fromWord($w));
        }
        return [$best, null, null];
    }

    public static function fromScore(float $s): int
    {
        return $s >= 9 ? 4 : ($s >= 7 ? 3 : ($s >= 4 ? 2 : ($s > 0 ? 1 : 0)));
    }

    /** Ernst in woorden (Ubuntu, Red Hat/Alma/Rocky, GitHub…) */
    public static function fromWord(string $w): int
    {
        return match (strtolower(trim($w))) {
            'critical' => 4,
            'high', 'important' => 3,
            'medium', 'moderate' => 2,
            'low', 'negligible', 'unimportant' => 1,
            default => 0,
        };
    }

    /** CVSS 3.x basisscore uit de vector (CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H → 9.8) */
    public static function cvss3(string $vector): ?float
    {
        if (!preg_match('#^CVSS:3\.[01]/#', $vector)) {
            return null;
        }
        $m = [];
        foreach (explode('/', substr($vector, 9)) as $part) {
            [$k, $v] = array_pad(explode(':', $part, 2), 2, '');
            $m[$k] = $v;
        }
        $w = [
            'AV' => ['N' => .85, 'A' => .62, 'L' => .55, 'P' => .2], 'AC' => ['L' => .77, 'H' => .44], 'UI' => ['N' => .85, 'R' => .62],
            'CIA' => ['H' => .56, 'L' => .22, 'N' => 0],
        ];
        foreach (['AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A'] as $k) {
            if (!isset($m[$k])) {
                return null;
            }
        }
        $changed = $m['S'] === 'C';
        $pr = ['N' => .85, 'L' => $changed ? .68 : .62, 'H' => $changed ? .5 : .27][$m['PR']] ?? null;
        $av = $w['AV'][$m['AV']] ?? null;
        $ac = $w['AC'][$m['AC']] ?? null;
        $ui = $w['UI'][$m['UI']] ?? null;
        [$c, $i, $a] = [$w['CIA'][$m['C']] ?? null, $w['CIA'][$m['I']] ?? null, $w['CIA'][$m['A']] ?? null];
        if (in_array(null, [$pr, $av, $ac, $ui, $c, $i, $a], true)) {
            return null;
        }
        $iss = 1 - (1 - $c) * (1 - $i) * (1 - $a);
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
        if ($impact <= 0) {
            return 0.0;
        }
        $expl = 8.22 * $av * $ac * $pr * $ui;
        $up = static fn(float $x) => ceil(round($x * 100000) / 10000) / 10;   // naar boven afronden op één decimaal (CVSS 3.1)
        return $up(min($changed ? 1.08 * ($impact + $expl) : $impact + $expl, 10));
    }

    /** De versie waarin het is opgelost voor dit pakket: de laagste "fixed" boven de geïnstalleerde versie, of null */
    public static function fixedVersion(array $rec, string $eco, string $pkg, string $installed, string $mgr): ?string
    {
        $fixed = [];
        foreach ((array) ($rec['affected'] ?? []) as $a) {
            if ($a['name'] !== $pkg || ($a['eco'] !== $eco && !str_starts_with($a['eco'], $eco) && !str_starts_with($eco, $a['eco']))) {
                continue;
            }
            foreach ($a['events'] as $e) {
                if (isset($e['fixed']) && $e['fixed'] !== '') {
                    $fixed[] = (string) $e['fixed'];
                }
            }
        }
        if (!$fixed) {
            return null;
        }
        $cmp = $mgr === 'dpkg' ? [self::class, 'cmpDeb'] : [self::class, 'cmpRpm'];
        usort($fixed, $cmp);
        foreach ($fixed as $f) {
            if ($cmp($f, $installed) > 0) {
                return mb_substr($f, 0, 120);
            }
        }
        return mb_substr((string) end($fixed), 0, 120);
    }

    // ------------------------------------------------------------------ versies vergelijken

    /** Debian-versies vergelijken (epoch:upstream-revisie, met ~ vóór alles), zoals dpkg */
    public static function cmpDeb(string $a, string $b): int
    {
        $split = static function (string $v): array {
            $e = 0;
            if (preg_match('/^(\d+):(.*)$/', $v, $m)) {
                [$e, $v] = [(int) $m[1], $m[2]];
            }
            $p = strrpos($v, '-');
            return $p === false ? [$e, $v, ''] : [$e, substr($v, 0, $p), substr($v, $p + 1)];
        };
        [$ea, $ua, $ra] = $split($a);
        [$eb, $ub, $rb] = $split($b);
        return $ea <=> $eb ?: self::debPart($ua, $ub) ?: self::debPart($ra, $rb);
    }

    private static function debPart(string $a, string $b): int
    {
        $ord = static fn(string $c): int => $c === '' ? 0 : ($c === '~' ? -1 : (ctype_alpha($c) ? ord($c) : (ctype_digit($c) ? 0 : ord($c) + 256)));
        while ($a !== '' || $b !== '') {
            while (($a !== '' && !ctype_digit($a[0])) || ($b !== '' && !ctype_digit($b[0]))) {
                $x = $ord($a[0] ?? '');
                $y = $ord($b[0] ?? '');
                if ($x !== $y) {
                    return $x <=> $y;
                }
                $a = substr($a, 1);
                $b = substr($b, 1);
            }
            preg_match('/^\d*/', $a, $ma);
            preg_match('/^\d*/', $b, $mb);
            $na = ltrim($ma[0], '0');
            $nb = ltrim($mb[0], '0');
            if ($na !== $nb) {
                return strlen($na) <=> strlen($nb) ?: strcmp($na, $nb) <=> 0;
            }
            $a = substr($a, strlen($ma[0]));
            $b = substr($b, strlen($mb[0]));
        }
        return 0;
    }

    /** RPM-versies vergelijken (epoch:versie-release), zoals rpmvercmp */
    public static function cmpRpm(string $a, string $b): int
    {
        $split = static function (string $v): array {
            $e = 0;
            if (preg_match('/^(\d+):(.*)$/', $v, $m)) {
                [$e, $v] = [(int) $m[1], $m[2]];
            }
            $p = strrpos($v, '-');
            return $p === false ? [$e, $v, ''] : [$e, substr($v, 0, $p), substr($v, $p + 1)];
        };
        [$ea, $va, $ra] = $split($a);
        [$eb, $vb, $rb] = $split($b);
        return $ea <=> $eb ?: self::rpmVer($va, $vb) ?: self::rpmVer($ra, $rb);
    }

    private static function rpmVer(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        while ($a !== '' || $b !== '') {
            $a = (string) preg_replace('/^[^a-zA-Z0-9~^]+/', '', $a);
            $b = (string) preg_replace('/^[^a-zA-Z0-9~^]+/', '', $b);
            foreach (['~' => -1, '^' => 1] as $ch => $_) {
                if (($a[0] ?? '') === $ch || ($b[0] ?? '') === $ch) {
                    if ($ch === '^' && ($a === '' || $b === '')) {
                        return $a === '' ? -1 : 1;
                    }
                    if (($a[0] ?? '') !== $ch) {
                        return $ch === '~' ? 1 : -1;
                    }
                    if (($b[0] ?? '') !== $ch) {
                        return $ch === '~' ? -1 : 1;
                    }
                    $a = substr($a, 1);
                    $b = substr($b, 1);
                    continue 2;
                }
            }
            if ($a === '' || $b === '') {
                break;
            }
            $digit = ctype_digit($a[0]);
            $re = $digit ? '/^\d+/' : '/^[a-zA-Z]+/';
            preg_match($re, $a, $m1);
            if (!preg_match($re, $b, $m2)) {
                return $digit ? 1 : -1;
            }
            if ($digit) {
                $x = ltrim($m1[0], '0');
                $y = ltrim($m2[0], '0');
                $c = strlen($x) <=> strlen($y) ?: strcmp($x, $y) <=> 0;
            } else {
                $c = strcmp($m1[0], $m2[0]) <=> 0;
            }
            if ($c !== 0) {
                return $c;
            }
            $a = substr($a, strlen($m1[0]));
            $b = substr($b, strlen($m2[0]));
        }
        return $a === '' && $b === '' ? 0 : ($a === '' ? -1 : 1);
    }

    // ------------------------------------------------------------------ aanvullen: actief misbruikt, EPSS, NVD

    /** @return string[] CVE's die actief misbruikt worden (CISA KEV, dagelijks ververst) */
    private static function kevList(): array
    {
        static $list = null;
        return $list ??= array_keys((array) json_decode((string) setting('vuln_kev', '{}'), true));
    }

    private static function refreshKev(): void
    {
        try {
            $r = self::http('GET', self::KEV);
        } catch (VulnFetchError) {
            return;   // morgen opnieuw
        }
        $map = [];
        foreach ((array) ($r['vulnerabilities'] ?? []) as $v) {
            if (preg_match('/^CVE-\d{4}-\d+$/', (string) ($v['cveID'] ?? ''))) {
                $map[$v['cveID']] = (string) ($v['dateAdded'] ?? '');
            }
        }
        if (!$map) {
            return;
        }
        save_setting('vuln_kev', json_encode($map));
        foreach (array_chunk(array_keys($map), 500) as $chunk) {
            q('UPDATE vuln_cve SET kev = 1 WHERE cve IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
        foreach (q('SELECT cve FROM vuln_cve WHERE kev = 1 AND kev_added IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $cve) {
            if (!empty($map[$cve])) {
                q('UPDATE vuln_cve SET kev_added = ? WHERE cve = ?', [$map[$cve], $cve]);
            }
        }
    }

    /** EPSS (wekelijks per CVE) en de CVSS-score van NVD voor CVE's zonder ernst (NVD: hooguit 5 per 30 s zonder sleutel) */
    private static function enrich(int $end): void
    {
        $cves = q("SELECT cve FROM vuln_cve WHERE cve LIKE 'CVE-%' AND (epss_at IS NULL OR epss_at < NOW() - INTERVAL 7 DAY)
                   AND cve IN (SELECT cve FROM vuln_findings) LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($cves, 100) as $chunk) {
            if (time() >= $end) {
                return;
            }
            try {
                $r = self::http('GET', self::EPSS . '?cve=' . implode(',', $chunk));
            } catch (VulnFetchError) {
                break;
            }
            $got = [];
            foreach ((array) ($r['data'] ?? []) as $d) {
                $got[(string) ($d['cve'] ?? '')] = (float) ($d['epss'] ?? 0);
            }
            foreach ($chunk as $cve) {
                q('UPDATE vuln_cve SET epss = ?, epss_at = ? WHERE cve = ?', [isset($got[$cve]) ? round($got[$cve], 5) : null, now(), $cve]);
            }
        }
        $todo = q("SELECT cve FROM vuln_cve WHERE cve LIKE 'CVE-%' AND cvss IS NULL AND (nvd_at IS NULL OR nvd_at < NOW() - INTERVAL 14 DAY)
                   AND cve IN (SELECT cve FROM vuln_findings) ORDER BY nvd_at IS NOT NULL, cve DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($todo as $i => $cve) {
            if (time() + 7 >= $end) {
                return;
            }
            if ($i > 0) {
                sleep(7);
            }
            try {
                $r = self::http('GET', self::NVD . '?cveId=' . rawurlencode($cve));
            } catch (VulnFetchError) {
                return;
            }
            $m = $r['vulnerabilities'][0]['cve']['metrics'] ?? [];
            $d = null;
            foreach (['cvssMetricV31', 'cvssMetricV30', 'cvssMetricV40', 'cvssMetricV2'] as $k) {
                if (!empty($m[$k][0]['cvssData']['baseScore'])) {
                    $d = $m[$k][0]['cvssData'];
                    break;
                }
            }
            if ($d) {
                q('UPDATE vuln_cve SET cvss = ?, vector = COALESCE(vector, ?), severity = ?, nvd_at = ? WHERE cve = ?',
                    [round((float) $d['baseScore'], 1), mb_substr((string) ($d['vectorString'] ?? ''), 0, 200) ?: null, self::fromScore((float) $d['baseScore']), now(), $cve]);
            } else {
                q('UPDATE vuln_cve SET nvd_at = ? WHERE cve = ?', [now(), $cve]);
            }
        }
    }

    /** ℹ️ Info bij nieuwe critical of actief misbruikte kwetsbaarheden: één bericht per server */
    private static function notifyNew(): void
    {
        $rows = q("SELECT f.id, f.host_id, f.package, f.cve, c.severity, c.kev FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve
                   WHERE f.notified = 0 AND f.first_seen >= NOW() - INTERVAL 7 DAY AND (c.severity = 4 OR c.kev = 1)")->fetchAll();
        $byHost = [];
        foreach ($rows as $r) {
            if (!self::ignored($r['cve'], $r['package'], (int) $r['host_id'])) {
                $byHost[(int) $r['host_id']][] = $r;
            }
        }
        foreach ($byHost as $hid => $list) {
            $host = q('SELECT id, name FROM hosts WHERE id = ?', [$hid])->fetch();
            if ($host) {
                Monitor::infoHost($host + ['info_agent' => 1], function () use ($list) {
                    $uniq = [];
                    foreach ($list as $r) {
                        $uniq[$r['cve']] ??= $r['cve'] . ' (' . $r['package'] . ', ' . ((int) $r['severity'] === 4 ? 'critical' : t(self::SEVERITY[(int) $r['severity']]))
                            . ((int) $r['kev'] ? ', ' . t('actief misbruikt') : '') . ')';
                    }
                    $n = count($uniq);
                    return ($n === 1 ? t('Nieuwe kwetsbaarheid') : t('{n} nieuwe kwetsbaarheden', ['n' => $n])) . ': '
                        . implode(' · ', array_slice($uniq, 0, 5)) . ($n > 5 ? ' · ' . t('en {n} meer', ['n' => $n - 5]) : '');
                }, 'vuln_new');
            }
        }
        q('UPDATE vuln_findings SET notified = 1 WHERE notified = 0 AND (first_seen < NOW() - INTERVAL 7 DAY OR id IN (SELECT id FROM (SELECT f.id FROM vuln_findings f
           JOIN vuln_cve c ON c.cve = f.cve WHERE f.notified = 0 AND (c.severity = 4 OR c.kev = 1)) x))');
    }

    // ------------------------------------------------------------------ negeren

    public static function ignored(string $cve, string $package, int $hostId): bool
    {
        static $list = null;
        $list ??= q('SELECT cve, package, host_id FROM vuln_ignore')->fetchAll();
        foreach ($list as $i) {
            if ($i['cve'] === $cve && ($i['package'] === null || $i['package'] === $package) && ($i['host_id'] === null || (int) $i['host_id'] === $hostId)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ bijwerken via de agent

    /** Mag RzMon deze server via de agent laten bijwerken? @return string|null de reden waarom niet */
    public static function upgradeBlocked(array $host): ?string
    {
        return match (true) {
            $host['mode'] !== 'agent' => t('alleen bij servers met een agent'),
            ($host['agent_update_via'] ?? '') === 'ha' => t('Home Assistant werk je bij via Home Assistant zelf'),
            !$host['agent_version'] || version_compare((string) $host['agent_version'], self::AGENT_MIN, '<') => t('agent {v} of nieuwer nodig', ['v' => self::AGENT_MIN]),
            AgentCommands::modeFor($host) !== 'root' => t("commando's als root staan uit voor deze server (Instellingen → 🖥️ Commando-checks, of de Agent Manager)"),
            default => null,
        };
    }

    /** Opdracht klaarzetten: deze pakketten bijwerken (namen worden gecontroleerd tegen de softwarelijst) */
    public static function requestUpgrade(array $host, array $pkgs, string $by): true|string
    {
        if ($why = self::upgradeBlocked($host)) {
            return $why;
        }
        $inv = q('SELECT packages FROM vuln_inventory WHERE host_id = ?', [$host['id']])->fetchColumn();
        $known = $inv ? array_flip(array_column((array) json_decode((string) $inv, true), 0)) : [];
        $pkgs = array_values(array_unique(array_filter($pkgs, fn($p) => is_string($p) && isset($known[$p]) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_:~-]{0,99}$/', $p))));
        if (!$pkgs) {
            return t('Geen geldige pakketten om bij te werken');
        }
        $req = ['id' => bin2hex(random_bytes(8)), 'pkgs' => array_slice($pkgs, 0, 200), 'by' => $by, 'at' => now(), 'sent' => null];
        q('UPDATE hosts SET vuln_upgrade_req = ?, vuln_upgrade_status = ? WHERE id = ?',
            [json_encode($req), json_encode(['state' => 'queued', 'at' => now(), 'pkgs' => $req['pkgs']]), $host['id']]);
        return true;
    }

    /** Opdracht voor de agent (één keer meesturen; na 30 minuten zonder antwoord vervalt hij) */
    private static function pendingUpgrade(int $hostId): ?array
    {
        $req = json_decode((string) q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$hostId])->fetchColumn(), true);
        if (!is_array($req)) {
            return null;
        }
        if ($req['sent'] !== null) {
            if (strtotime((string) $req['sent']) < time() - 1800) {
                q('UPDATE hosts SET vuln_upgrade_req = NULL, vuln_upgrade_status = ? WHERE id = ?',
                    [json_encode(['state' => 'error', 'at' => now(), 'pkgs' => $req['pkgs'], 'log' => t('Geen antwoord van de agent na 30 minuten')]), $hostId]);
            }
            return null;
        }
        $req['sent'] = now();
        q('UPDATE hosts SET vuln_upgrade_req = ?, vuln_upgrade_status = ? WHERE id = ?',
            [json_encode($req), json_encode(['state' => 'running', 'at' => now(), 'sent' => now(), 'pkgs' => $req['pkgs']]), $hostId]);
        return ['id' => $req['id'], 'pkgs' => $req['pkgs']];
    }

    /** Voortgang van de agent tijdens het bijwerken (stap, procent, pakket, laatste uitvoer), of de uitkomst als hij klaar is */
    public static function upgradeProgress(array $host, array $p): void
    {
        if (!empty($p['done'])) {
            self::upgradeResult($host, $p);
            return;
        }
        $req = json_decode((string) q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$host['id']])->fetchColumn(), true);
        if (!is_array($req) || ($p['id'] ?? '') !== $req['id']) {
            return;
        }
        $st = json_decode((string) q('SELECT vuln_upgrade_status FROM hosts WHERE id = ?', [$host['id']])->fetchColumn(), true) ?: [];
        $clean = static fn($v, int $max) => mb_substr(preg_replace('/[\x00-\x1f]/', '', (string) $v), 0, $max);
        $stage = in_array($p['stage'] ?? '', ['update', 'download', 'install', 'verify'], true) ? $p['stage'] : 'update';
        $st = [
            'state' => 'running', 'at' => now(), 'sent' => $st['sent'] ?? $req['sent'] ?? now(), 'pkgs' => $req['pkgs'],
            'stage' => $stage, 'pct' => max((int) ($st['pct'] ?? 0), min(99, max(0, (int) ($p['pct'] ?? 0)))),   // de balk gaat niet terug
            'detail' => $clean($p['detail'] ?? '', 120), 'count' => preg_match('#^\d{1,5}/\d{1,5}$#', (string) ($p['count'] ?? '')) ? $p['count'] : '',
            'log' => mb_substr((string) base64_decode((string) ($p['log_b64'] ?? ''), true), -800),
        ];
        q('UPDATE hosts SET vuln_upgrade_status = ? WHERE id = ?', [json_encode($st, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $host['id']]);
    }

    private static function upgradeResult(array $host, array $r): void
    {
        $req = json_decode((string) q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$host['id']])->fetchColumn(), true);
        if (!is_array($req) || ($r['id'] ?? '') !== $req['id']) {
            return;
        }
        $log = mb_substr((string) base64_decode((string) ($r['log_b64'] ?? ''), true), -1500);
        $ok = !empty($r['ok']);
        $prev = json_decode((string) q('SELECT vuln_upgrade_status FROM hosts WHERE id = ?', [$host['id']])->fetchColumn(), true) ?: [];
        q('UPDATE hosts SET vuln_upgrade_req = NULL, vuln_upgrade_status = ? WHERE id = ?',
            [json_encode(['state' => $ok ? 'ok' : 'error', 'at' => now(), 'sent' => $prev['sent'] ?? $req['sent'], 'pkgs' => $req['pkgs'], 'log' => AgentText::tr($log)],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $host['id']]);
        AgentCommands::audit((string) $req['by'], $ok ? 'Pakketten bijgewerkt' : 'Pakketten bijwerken mislukt', "{$host['name']}: " . implode(' ', $req['pkgs']));
        // De nieuwe softwarelijst komt met het volgende rapport; daarna scant RzMon vanzelf opnieuw (andere lijst)
        $pkgs = implode(' ', array_slice($req['pkgs'], 0, 20)) . (count($req['pkgs']) > 20 ? ' …' : '');
        $last = trim((string) strrchr("\n" . trim($log), "\n"));
        Monitor::infoHost(['info_agent' => 1] + $host, fn() => $ok
            ? '✔ ' . t('Pakketten bijgewerkt: {p}', ['p' => $pkgs])
            : '✘ ' . t('Pakketten bijwerken mislukt: {p}', ['p' => $pkgs]) . ($last !== '' ? ' – ' . mb_substr(AgentText::tr($last), 0, 200) : ''), 'vuln_upgrade');
    }

    /** Het commando om het zelf te doen, per pakketbeheerder */
    public static function upgradeCommand(string $mgr, array $pkgs): string
    {
        $p = implode(' ', $pkgs);
        return $mgr === 'rpm' ? "sudo dnf upgrade $p" : "sudo apt update && sudo apt install --only-upgrade $p";
    }

    // ------------------------------------------------------------------ voor de pagina

    /** Alles voor de pagina Kwetsbaarheden: per server de softwarelijst-gegevens en de bevindingen */
    public static function overview(): array
    {
        $ex = self::excluded();
        $hosts = [];
        $rows = q('SELECT h.id, h.name, h.mode, h.agent_version, h.agent_update_via, h.commands, h.vuln_upgrade_status, i.mgr, i.os_name, i.ecosystem, i.packages, i.exposed,
                          i.restart_svc, i.kernel_running, i.kernel_latest, i.updated_at, i.scanned_at, i.scan_error
                   FROM hosts h LEFT JOIN vuln_inventory i ON i.host_id = h.id WHERE h.enabled = 1 ORDER BY h.name')->fetchAll();
        $srcOf = [];
        foreach ($rows as $h) {
            if (in_array((int) $h['id'], $ex, true) || ($h['mgr'] === null && ($h['mode'] === 'remote' || $h['agent_update_via'] === 'ha'))) {
                continue;   // uitgesloten, een netwerkapparaat of de Home Assistant-add-on (geen softwarelijst)
            }
            $list = (array) json_decode((string) $h['packages'], true);
            $bin2src = [];
            foreach ($list as $p) {
                $bin2src[$p[0]] = $p[3];
            }
            $srcOf[(int) $h['id']] = $bin2src;
            $exposed = [];
            foreach ((array) json_decode((string) $h['exposed'], true) as [$pkg, $port, $proto]) {
                $key = $h['mgr'] === 'dpkg' ? ($bin2src[$pkg] ?? $pkg) : $pkg;
                $exposed[$key][] = "$port/$proto";
            }
            $hosts[] = [
                'id' => (int) $h['id'], 'name' => $h['name'], 'mode' => $h['mode'], 'mgr' => $h['mgr'], 'os' => $h['os_name'], 'ecosystem' => $h['ecosystem'],
                'packages' => count($list), 'updated_at' => $h['updated_at'], 'scanned_at' => $h['scanned_at'], 'error' => $h['scan_error'],
                'reboot' => $h['kernel_latest'] && $h['kernel_running'] && $h['kernel_latest'] !== $h['kernel_running'] ? $h['kernel_latest'] : null,
                'kernel' => $h['kernel_running'], 'restart' => (array) json_decode((string) $h['restart_svc'], true), 'exposed' => $exposed,
                'agent_old' => $h['mode'] === 'agent' && (!$h['agent_version'] || version_compare((string) $h['agent_version'], self::AGENT_MIN, '<')),
                'upgrade_blocked' => self::upgradeBlocked($h), 'upgrade' => json_decode((string) $h['vuln_upgrade_status'], true),
            ];
        }
        $ids = array_column($hosts, 'id');
        $findings = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (q("SELECT f.id, f.host_id, f.package, f.version, f.source, f.vuln_id, f.cve, f.fixed_version, f.first_seen,
                               COALESCE(c.severity, 0) AS severity, c.cvss, c.kev, c.epss, vc.data
                        FROM vuln_findings f LEFT JOIN vuln_cve c ON c.cve = f.cve LEFT JOIN vuln_cache vc ON vc.vuln_id = f.vuln_id
                        WHERE f.host_id IN ($in) ORDER BY f.host_id, f.package", $ids) as $f) {
                $rec = json_decode((string) $f['data'], true) ?: [];
                $findings[] = [
                    'id' => (int) $f['id'], 'host_id' => (int) $f['host_id'], 'package' => $f['package'], 'version' => $f['version'], 'binaries' => $f['source'],
                    'vuln_id' => $f['vuln_id'], 'cve' => $f['cve'], 'fixed' => $f['fixed_version'], 'first_seen' => $f['first_seen'],
                    'severity' => (int) $f['severity'], 'cvss' => $f['cvss'] !== null ? (float) $f['cvss'] : null, 'kev' => (bool) $f['kev'],
                    'epss' => $f['epss'] !== null ? (float) $f['epss'] : null, 'summary' => $rec['summary'] ?? '',
                    'ignored' => self::ignored($f['cve'], $f['package'], (int) $f['host_id']),
                ];
            }
        }
        return ['enabled' => true, 'hosts' => $hosts, 'findings' => $findings, 'min_severity' => (int) setting('vuln_min_severity', '0'),
            'last_scan' => setting('vuln_last_scan', null), 'scan_time' => setting('vuln_time', '03:30'),
            'ignore' => q('SELECT i.*, h.name AS host_name FROM vuln_ignore i LEFT JOIN hosts h ON h.id = i.host_id ORDER BY i.created_at DESC')->fetchAll()];
    }

    /**
     * Tellers per server (REST API, MQTT): unieke CVE's per ernst, actief misbruikt, en hoeveel daarvan bij een bereikbaar pakket.
     * Geaccepteerde kwetsbaarheden tellen niet mee.
     */
    public static function summary(?array $o = null): array
    {
        $o ??= self::overview();
        $out = [];
        foreach ($o['hosts'] as $h) {
            $fs = array_filter($o['findings'], fn($f) => $f['host_id'] === $h['id'] && !$f['ignored']);
            $uniq = static fn(callable $fn) => count(array_unique(array_column(array_filter($fs, $fn), 'cve')));
            $out[] = [
                'host_id' => $h['id'], 'host' => $h['name'], 'os' => $h['os'], 'packages' => $h['packages'], 'scanned_at' => $h['scanned_at'], 'error' => $h['error'],
                'critical' => $uniq(fn($f) => $f['severity'] === 4), 'high' => $uniq(fn($f) => $f['severity'] === 3),
                'medium' => $uniq(fn($f) => $f['severity'] === 2), 'low' => $uniq(fn($f) => $f['severity'] === 1), 'unknown' => $uniq(fn($f) => $f['severity'] === 0),
                'exploited' => $uniq(fn($f) => $f['kev']), 'exposed' => $uniq(fn($f) => isset($h['exposed'][$f['package']]) && $f['severity'] >= 3),
                'fixable' => $uniq(fn($f) => $f['fixed'] !== null), 'reboot_required' => $h['reboot'] !== null || (bool) $h['restart'],
            ];
        }
        return $out;
    }

    /** Meer over één kwetsbaarheid (CVE of advies): gegevens, adviezen, welke servers, en hoe op te lossen */
    public static function detail(string $cve): ?array
    {
        $c = q('SELECT * FROM vuln_cve WHERE cve = ?', [$cve])->fetch();
        $rows = q('SELECT f.*, h.name AS host_name, h.mode, h.agent_version, h.agent_update_via, h.commands, i.mgr, i.ecosystem, i.packages
                   FROM vuln_findings f JOIN hosts h ON h.id = f.host_id LEFT JOIN vuln_inventory i ON i.host_id = f.host_id WHERE f.cve = ? ORDER BY h.name', [$cve])->fetchAll();
        if (!$c && !$rows) {
            return null;
        }
        $recs = [];
        foreach (array_unique(array_merge([$cve], array_column($rows, 'vuln_id'))) as $vid) {
            $d = q('SELECT data FROM vuln_cache WHERE vuln_id = ?', [$vid])->fetchColumn();
            if ($d) {
                $recs[$vid] = json_decode((string) $d, true);
            }
        }
        $first = $recs ? reset($recs) : [];
        $hosts = [];
        foreach ($rows as $r) {
            $bins = self::binaries((array) json_decode((string) $r['packages'], true), (string) $r['mgr'], [$r['package']]);
            $hosts[] = ['host_id' => (int) $r['host_id'], 'host' => $r['host_name'], 'package' => $r['package'], 'version' => $r['version'], 'fixed' => $r['fixed_version'],
                'vuln_id' => $r['vuln_id'], 'ignored' => self::ignored($cve, $r['package'], (int) $r['host_id']),
                'command' => $r['fixed_version'] ? self::upgradeCommand((string) $r['mgr'], $bins) : null, 'upgrade_blocked' => self::upgradeBlocked($r)];
        }
        $links = [];
        if (str_starts_with($cve, 'CVE-')) {
            $links[] = ['label' => 'NVD', 'url' => 'https://nvd.nist.gov/vuln/detail/' . $cve];
        }
        foreach ($recs as $vid => $rec) {
            $links[] = ['label' => 'OSV · ' . $vid, 'url' => 'https://osv.dev/vulnerability/' . rawurlencode((string) $vid)];
            foreach ((array) ($rec['references'] ?? []) as $ref) {
                if (in_array($ref['type'], ['ADVISORY', 'FIX', 'WEB', 'REPORT'], true) && count($links) < 14) {
                    $links[] = ['label' => $ref['type'], 'url' => $ref['url']];
                }
            }
        }
        return [
            'cve' => $cve, 'severity' => (int) ($c['severity'] ?? 0), 'cvss' => isset($c['cvss']) ? (float) $c['cvss'] : null, 'vector' => $c['vector'] ?? null,
            'epss' => isset($c['epss']) ? (float) $c['epss'] : null, 'kev' => !empty($c['kev']), 'kev_added' => $c['kev_added'] ?? null,
            'summary' => $first['summary'] ?? '', 'details' => $first['details'] ?? '', 'published' => $first['published'] ?? '',
            'aliases' => array_values(array_unique(array_merge(...array_map(fn($r) => (array) ($r['aliases'] ?? []), array_values($recs ?: [[]]))))),
            'hosts' => $hosts, 'links' => array_values(array_unique($links, SORT_REGULAR)),
        ];
    }

    /** Volledige softwarelijst van een server, met welke pakketten kwetsbaar zijn */
    public static function packages(int $hostId): array
    {
        $inv = q('SELECT mgr, packages FROM vuln_inventory WHERE host_id = ?', [$hostId])->fetch();
        if (!$inv) {
            return [];
        }
        $bad = [];
        foreach (q('SELECT package, COUNT(*) n FROM vuln_findings WHERE host_id = ? GROUP BY package', [$hostId]) as $r) {
            $bad[$r['package']] = (int) $r['n'];
        }
        return array_map(fn($p) => ['name' => $p[0], 'version' => $p[1], 'arch' => $p[2], 'source' => $p[3],
            'vulns' => $bad[$inv['mgr'] === 'dpkg' ? $p[3] : $p[0]] ?? 0], (array) json_decode((string) $inv['packages'], true));
    }

    /** De te installeren pakketnamen bij deze bevindingen (Debian/Ubuntu: alle pakketten van dat bronpakket) */
    public static function binaries(array $list, string $mgr, array $names): array
    {
        $names = array_flip($names);
        $out = [];
        foreach ($list as $p) {
            if (isset($names[$mgr === 'dpkg' ? $p[3] : $p[0]])) {
                $out[] = $p[0];
            }
        }
        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------------ HTTP

    /** @return array het antwoord (JSON) */
    private static function http(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        ] + ($method === 'POST' ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)] : []));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code >= 400 || $code === 0) {
            throw new VulnFetchError($err ?: "HTTP $code");
        }
        return (array) json_decode((string) $raw, true);
    }

    /** Meerdere GET-verzoeken tegelijk. @return array<int, array|null> */
    private static function httpMulti(array $urls): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($urls as $i => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            curl_multi_add_handle($mh, $ch);
            $hs[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($hs as $i => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $out[$i] = $code === 200 ? json_decode((string) curl_multi_getcontent($ch), true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        if ($urls && !array_filter($out)) {
            throw new VulnFetchError(t('geen antwoord'));
        }
        return $out;
    }
}

/** Een externe bron (OSV.dev, CISA, FIRST, NVD) gaf geen (goed) antwoord; andere fouten zijn geen netwerkprobleem */
final class VulnFetchError extends RuntimeException
{
}
