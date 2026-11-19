<?php
declare(strict_types=1);

/**
 * 1.290.0: van buitenaf (aanvalsoppervlak). Wat staat er van je eigen netwerk open op internet?
 *
 * Doelen: je eigen publieke IP-adres (Cloudflare-trace, zoals bij de VPN-check), de domeinen van je reverse proxy (SWAG)
 * en wat je zelf toevoegt. Domeinen worden IP-adressen; alleen openbare adressen tellen.
 *
 * Bronnen (elk aan of uit):
 * - Shodan InternetDB (gratis, zonder sleutel): wat Shodan de afgelopen weken van buiten zag: poorten, software (CPE),
 *   bekende kwetsbaarheden (CVE) en labels. Alleen je publieke IP-adressen gaan daarheen, niets anders.
 * - Vanuit RzSense zelf: een TCP-verbinding naar elke poort (geen aanval, geen gegevens). Gaat meestal via je router
 *   "rondje terug" (NAT-loopback): wat RzSense zo ziet, is niet altijd wat een aanvaller ziet. Daarom met een waarschuwing.
 * - (1.290.x) een meetpunt buiten je netwerk: een agent op een VPS of op een andere plek.
 *
 * Wat je goedkeurt (bv. 443 voor SWAG) is "verwacht". Een nieuwe poort die niet verwacht is: een melding. Een risicovolle
 * dienst (SSH, RDP, databases, Home Assistant, Proxmox …) altijd. Nieuwe CVE's die Shodan bij je IP ziet: ook een melding.
 */
final class Exposure
{
    /** De poorten die standaard gescand worden (vanuit RzSense): de bekendste diensten, ook die je liever niet open hebt */
    public const TOP = [21, 22, 23, 25, 53, 80, 81, 88, 110, 111, 135, 139, 143, 389, 443, 445, 465, 500, 554, 587, 631, 636, 993, 995, 1194, 1433,
        1521, 1723, 1883, 1900, 2049, 2082, 2083, 2086, 2087, 2096, 2222, 2375, 2376, 3000, 3128, 3306, 3389, 4443, 4444, 4500, 5000, 5001, 5060,
        5432, 5601, 5800, 5900, 5901, 6379, 6443, 7443, 8000, 8006, 8008, 8080, 8081, 8083, 8088, 8096, 8123, 8181, 8200, 8291, 8443, 8448, 8834,
        8880, 8888, 9000, 9001, 9090, 9091, 9100, 9200, 9443, 10000, 10443, 11211, 19999, 27017, 32400, 51820, 61208];
    /** Risicovol als dit van buiten open staat: altijd een melding, ook als hij al eerder open stond (tot je hem goedkeurt) */
    public const RISKY = [21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 135 => 'Windows RPC', 139 => 'NetBIOS', 445 => 'SMB (Windows-delen)',
        1433 => 'SQL Server', 1521 => 'Oracle', 1883 => 'MQTT', 2049 => 'NFS', 2375 => 'Docker API (zonder TLS)', 2376 => 'Docker API',
        3306 => 'MariaDB/MySQL', 3389 => 'Remote Desktop', 5432 => 'PostgreSQL', 5900 => 'VNC', 5901 => 'VNC', 6379 => 'Redis', 6443 => 'Kubernetes API',
        8006 => 'Proxmox', 8123 => 'Home Assistant', 8291 => 'MikroTik Winbox', 9090 => 'Cockpit', 9100 => 'Printer (JetDirect)', 9200 => 'Elasticsearch',
        10000 => 'Webmin', 11211 => 'Memcached', 19999 => 'Netdata', 27017 => 'MongoDB', 61208 => 'Glances'];
    public const NAMES = [53 => 'DNS', 80 => 'HTTP', 81 => 'HTTP', 110 => 'POP3', 143 => 'IMAP', 443 => 'HTTPS', 465 => 'SMTPS', 500 => 'IPsec', 554 => 'RTSP (camera)',
        587 => 'SMTP', 631 => 'IPP (printer)', 993 => 'IMAPS', 995 => 'POP3S', 1194 => 'OpenVPN', 1723 => 'PPTP', 1900 => 'UPnP', 3000 => 'web (o.a. Grafana)',
        3128 => 'proxy', 4443 => 'HTTPS', 5000 => 'web (o.a. Synology)', 5001 => 'Synology (https)', 5060 => 'SIP', 5601 => 'Kibana', 8000 => 'web', 8080 => 'web',
        8096 => 'Jellyfin', 8443 => 'HTTPS', 8448 => 'Matrix', 8834 => 'Nessus', 8880 => 'web', 8888 => 'web', 9000 => 'web (o.a. Portainer)', 9443 => 'Portainer',
        32400 => 'Plex', 51820 => 'WireGuard'] + self::RISKY;
    private const MAX_IPS = 32;
    private const MAX_PORTS = 2000;
    private const UA = 'RzSense-exposure';

    public static function on(): bool
    {
        return setting('exposure_on', '0') === '1' && (setting('exposure_shodan', '0') === '1' || setting('exposure_self', '0') === '1');
    }

    // ------------------------------------------------------------------ doelen

    /** Openbaar adres? (geen privé, lokaal, gereserveerd) */
    public static function publicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function validDomain(string $d): bool
    {
        return strlen($d) <= 253 && (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d);
    }

    /**
     * De domeinen van je reverse proxy (alle sites van alle servers met de rol reverse proxy)
     * @return list<string>
     */
    public static function proxyDomains(): array
    {
        $out = [];
        try {
            foreach (q('SELECT id FROM hosts WHERE proxy_role = 1 AND enabled = 1')->fetchAll(PDO::FETCH_COLUMN) as $hid) {
                foreach (ProxyGuard::sites((int) $hid) as $s) {
                    foreach ((array) ($s['names'] ?? []) as $n) {
                        $n = strtolower(trim((string) $n));
                        if (self::validDomain($n) && !str_starts_with($n, '*.')) {
                            $out[$n] = true;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // geen reverse proxy (of nog niet gemeld)
        }
        return array_slice(array_keys($out), 0, 100);
    }

    /**
     * Alle doelen: automatisch (je IP, je proxydomeinen) en zelf toegevoegd.
     * @return list<array{value: string, kind: string, source: string, id: ?int, enabled: bool, note: string}>
     */
    public static function targets(): array
    {
        $out = [];
        if (setting('exposure_auto', '1') === '1') {
            if (($ip = NetChecks::ownPublicIp()) !== null) {
                $out[$ip] = ['value' => $ip, 'kind' => 'ip', 'source' => 'auto', 'id' => null, 'enabled' => true, 'note' => t('je eigen publieke IP-adres')];
            }
            foreach (self::proxyDomains() as $d) {
                $out[$d] = ['value' => $d, 'kind' => 'domain', 'source' => 'auto', 'id' => null, 'enabled' => true, 'note' => t('van je reverse proxy')];
            }
        }
        foreach (q('SELECT * FROM exposure_targets ORDER BY value')->fetchAll() as $r) {
            $out[$r['value']] = ['value' => (string) $r['value'], 'kind' => (string) $r['kind'], 'source' => 'manual', 'id' => (int) $r['id'],
                'enabled' => (bool) $r['enabled'], 'note' => (string) $r['note']];
        }
        return array_values($out);
    }

    /** Doel toevoegen (een openbaar IP-adres of een domein). Geeft een fout of null. */
    public static function addTarget(string $v, string $note, string $by): ?string
    {
        $v = strtolower(trim($v));
        $kind = filter_var($v, FILTER_VALIDATE_IP) ? 'ip' : 'domain';
        if ($kind === 'ip' && !self::publicIp($v)) {
            return t('Alleen openbare IP-adressen: dit is een adres in je eigen netwerk');
        }
        if ($kind === 'domain' && !self::validDomain($v)) {
            return t('Geen geldig IP-adres of domein');
        }
        if ((int) q('SELECT COUNT(*) FROM exposure_targets')->fetchColumn() >= 50) {
            return t('Hooguit 50 eigen doelen');
        }
        q('INSERT INTO exposure_targets (value, kind, note, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note), enabled = 1',
            [$v, $kind, mb_substr(trim($note), 0, 200), mb_substr($by, 0, 100)]);
        return null;
    }

    /**
     * De IP-adressen die gescand worden, met de namen die erbij horen
     * @return array<string, list<string>>
     */
    public static function ips(): array
    {
        $ips = [];
        foreach (self::targets() as $t) {
            if (!$t['enabled']) {
                continue;
            }
            if ($t['kind'] === 'ip') {
                $ips[$t['value']] ??= [];
                continue;
            }
            foreach (self::resolve($t['value']) as $ip) {
                $ips[$ip][] = $t['value'];
            }
        }
        $ips = array_filter($ips, static fn($n, $ip) => self::publicIp((string) $ip), ARRAY_FILTER_USE_BOTH);
        return array_slice($ips, 0, self::MAX_IPS, true);
    }

    /** @return list<string> openbare IPv4- en IPv6-adressen van een domein */
    private static function resolve(string $d): array
    {
        $out = [];
        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($d, $type) ?: [] as $r) {
                $ip = (string) ($r['ip'] ?? $r['ipv6'] ?? '');
                if (self::publicIp($ip)) {
                    $out[] = $ip;
                }
            }
        }
        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------------ poorten

    /** @return list<int> de poorten om te scannen: de standaardlijst, of wat je zelf opgeeft (bv. "1-1024, 8000-8999") */
    public static function portList(?string $spec = null): array
    {
        $spec = trim($spec ?? (string) setting('exposure_ports', ''));
        if ($spec === '') {
            return self::TOP;
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $spec) ?: [] as $p) {
            if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $p, $m)) {
                for ($i = max(1, (int) $m[1]); $i <= min(65535, (int) $m[2]) && count($out) < self::MAX_PORTS; $i++) {
                    $out[$i] = true;
                }
            } elseif (ctype_digit($p) && (int) $p >= 1 && (int) $p <= 65535) {
                $out[(int) $p] = true;
            }
            if (count($out) >= self::MAX_PORTS) {
                break;
            }
        }
        ksort($out);
        return $out ? array_keys($out) : self::TOP;
    }

    /**
     * Welke poorten nemen verbindingen aan? Naast elkaar (hooguit 200 tegelijk), elk met een korte tijdslimiet.
     * @param list<int> $ports
     * @return list<int>
     */
    public static function tcpScan(string $ip, array $ports, float $timeout = 1.5): array
    {
        $open = [];
        $host = str_contains($ip, ':') ? "[$ip]" : $ip;
        foreach (array_chunk($ports, 200) as $chunk) {
            $socks = [];
            foreach ($chunk as $p) {
                $s = @stream_socket_client("tcp://$host:$p", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if ($s !== false) {
                    $socks[$p] = $s;
                }
            }
            $end = microtime(true) + $timeout;
            while ($socks && ($left = $end - microtime(true)) > 0) {
                $r = null;
                $w = array_values($socks);
                $e = null;
                $ready = @stream_select($r, $w, $e, 0, (int) ($left * 1e6));
                if ($ready === false || $ready === 0) {
                    break;   // fout, of tijd op: wat nog niet klaar is, is dicht (of gefilterd)
                }
                foreach ($w as $s) {
                    $p = array_search($s, $socks, true);
                    if ($p === false) {
                        continue;
                    }
                    // Klaar met verbinden: gelukt als er een andere kant is
                    if (@stream_socket_get_name($s, true) !== false) {
                        $open[] = (int) $p;
                    }
                    fclose($s);
                    unset($socks[$p]);
                }
            }
            foreach ($socks as $s) {
                fclose($s);
            }
        }
        sort($open);
        return $open;
    }

    /**
     * Shodan InternetDB: wat zag Shodan van buiten? null = onbereikbaar; [] = niets bekend.
     * @return array{ports: list<int>, cpes: list<string>, vulns: list<string>, tags: list<string>, hostnames: list<string>}|null
     */
    public static function shodan(string $ip): ?array
    {
        $ch = curl_init('https://internetdb.shodan.io/' . rawurlencode($ip));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => self::UA . '/' . RZMON_VERSION, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 404) {
            return ['ports' => [], 'cpes' => [], 'vulns' => [], 'tags' => [], 'hostnames' => []];
        }
        if (!is_string($body) || $code !== 200) {
            return null;
        }
        return self::parseShodan((string) $body);
    }

    /** @return array{ports: list<int>, cpes: list<string>, vulns: list<string>, tags: list<string>, hostnames: list<string>} */
    public static function parseShodan(string $json): array
    {
        $j = json_decode($json, true);
        $j = is_array($j) ? $j : [];
        $strs = static fn($a, string $re) => array_values(array_slice(array_filter(array_map('strval', (array) $a), static fn($s) => (bool) preg_match($re, $s)), 0, 200));
        return ['ports' => array_values(array_filter(array_map('intval', (array) ($j['ports'] ?? [])), static fn($p) => $p >= 1 && $p <= 65535)),
            'cpes' => $strs($j['cpes'] ?? [], '/^cpe:[\w:.\/\-~%*+]{3,200}$/'), 'vulns' => $strs($j['vulns'] ?? [], '/^CVE-\d{4}-\d{4,7}$/'),
            'tags' => $strs($j['tags'] ?? [], '/^[\w -]{1,40}$/'), 'hostnames' => $strs($j['hostnames'] ?? [], '/^[A-Za-z0-9.-]{1,253}$/')];
    }

    // ------------------------------------------------------------------ uitvoeren

    /** Cron: als het tijd is (instelbaar, standaard elke 24 uur) */
    public static function cron(): ?string
    {
        if (!self::on()) {
            return null;
        }
        $hours = max(1, (int) setting('exposure_hours', '24'));
        if (strtotime((string) setting('exposure_last_run', '')) > time() - $hours * 3600 + 120) {
            return null;
        }
        return self::run();
    }

    /** Eén ronde: alle bronnen, dan vergelijken en melden */
    public static function run(): string
    {
        save_setting('exposure_last_run', now());
        $ips = self::ips();
        if (!$ips) {
            save_setting('exposure_last_error', t('Geen doelen: je publieke IP is onbekend en er zijn geen domeinen'));
            return t('Van buitenaf: geen doelen');
        }
        $info = json_decode((string) setting('exposure_ipinfo', '{}'), true) ?: [];
        $errors = [];
        $seen = [];   // bron => [ip => [poorten]]
        if (setting('exposure_shodan', '0') === '1') {
            foreach (array_keys($ips) as $ip) {
                $r = self::shodan((string) $ip);
                if ($r === null) {
                    $errors[] = "Shodan ($ip): " . t('niet bereikbaar');
                    continue;
                }
                $seen['shodan'][$ip] = $r['ports'];
                $prevV = (array) ($info[$ip]['vulns'] ?? []);
                $info[$ip] = ['cpes' => $r['cpes'], 'vulns' => $r['vulns'], 'tags' => $r['tags'], 'hostnames' => $r['hostnames'], 'at' => now(),
                    'new_vulns' => array_values(array_diff($r['vulns'], $prevV)), 'first' => !isset($info[$ip])];
            }
        }
        if (setting('exposure_self', '0') === '1') {
            $ports = self::portList();
            foreach (array_keys($ips) as $ip) {
                $seen['self'][$ip] = self::tcpScan((string) $ip, $ports);
            }
        }
        save_setting('exposure_ipinfo', (string) json_encode(array_intersect_key($info, $ips)));
        save_setting('exposure_last_error', implode('; ', $errors));
        $new = self::store($seen, $ips);
        self::alert($new, $info, $ips);
        $n = (int) q('SELECT COUNT(DISTINCT ip, port) FROM exposure WHERE gone_at IS NULL')->fetchColumn();
        return t('Van buitenaf: {n} open poort(en) op {i} adres(sen)', ['n' => $n, 'i' => count($ips)]) . ($new ? ' · ' . t('{n} nieuw', ['n' => count($new)]) : '');
    }

    /**
     * Uitkomsten bewaren; wat een bron niet meer ziet: weg. Geeft de nieuwe (ip, poort) terug (nog door geen enkele bron gezien).
     * @param array<string, array<string, list<int>>> $seen
     * @param array<string, list<string>> $ips
     * @return list<array{ip: string, port: int}>
     */
    public static function store(array $seen, array $ips): array
    {
        $new = [];
        $now = now();
        foreach ($seen as $src => $byIp) {
            foreach ($byIp as $ip => $ports) {
                foreach ($ports as $p) {
                    $known = (int) q('SELECT COUNT(*) FROM exposure WHERE ip = ? AND port = ? AND gone_at IS NULL', [$ip, $p])->fetchColumn();
                    if (!$known) {
                        $new["$ip:$p"] = ['ip' => (string) $ip, 'port' => (int) $p];
                    }
                    q('INSERT INTO exposure (ip, port, source, names, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), names = VALUES(names), gone_at = NULL, first_seen = IF(gone_at IS NULL, first_seen, VALUES(first_seen))',
                        [$ip, $p, $src, mb_substr(implode(' ', $ips[$ip] ?? []), 0, 500), $now, $now]);
                }
                // Deze bron ziet ze niet meer
                $keep = $ports ?: [0];
                q('UPDATE exposure SET gone_at = ? WHERE source = ? AND ip = ? AND gone_at IS NULL AND port NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')',
                    array_merge([$now, $src, $ip], $keep));
            }
        }
        // Adressen die geen doel meer zijn: weg
        if ($ips) {
            q('UPDATE exposure SET gone_at = ? WHERE gone_at IS NULL AND ip NOT IN (' . implode(',', array_fill(0, count($ips), '?')) . ')', array_merge([$now], array_keys($ips)));
        }
        return array_values($new);
    }

    /** Is deze poort op dit adres goedgekeurd (verwacht)? */
    public static function expected(string $ip, int $port): bool
    {
        return (bool) q("SELECT 1 FROM exposure_ok WHERE port = ? AND (ip = ? OR ip = '*')", [$port, $ip])->fetchColumn();
    }

    /**
     * Meldingen: nieuwe poorten die niet verwacht zijn, risicovolle diensten, nieuwe CVE's.
     * @param list<array{ip: string, port: int}> $new
     * @param array<string, mixed> $info
     * @param array<string, list<string>> $ips
     */
    private static function alert(array $new, array $info, array $ips): void
    {
        $lines = [];
        $crit = false;
        $first = setting('exposure_baseline', '') === '';
        foreach ($new as $n) {
            if (self::expected($n['ip'], $n['port'])) {
                continue;
            }
            $risky = isset(self::RISKY[$n['port']]);
            $crit = $crit || $risky;
            $where = $n['ip'] . (($ips[$n['ip']] ?? []) ? ' (' . implode(', ', array_slice($ips[$n['ip']], 0, 3)) . ')' : '');
            $lines[] = ($risky ? '⚠️ ' : '🆕 ') . t('Poort {p} ({s}) staat open op internet: {w}', ['p' => $n['port'], 's' => self::NAMES[$n['port']] ?? '?', 'w' => $where]);
        }
        foreach ($info as $ip => $i) {
            if (!empty($i['new_vulns']) && empty($i['first']) && isset($ips[$ip])) {
                $lines[] = '🐞 ' . t('Shodan ziet nieuwe kwetsbaarheden op {ip}: {v}', ['ip' => $ip, 'v' => implode(', ', array_slice((array) $i['new_vulns'], 0, 8))]);
            }
        }
        if ($first) {
            save_setting('exposure_baseline', now());   // de eerste ronde is de beginstand: wel een overzicht, geen alarm per poort
            if ($lines) {
                I18n::forNotify(static fn() => Monitor::notifySystem('🌍 ' . t('Van buitenaf: eerste scan'),
                    t('Dit staat er nu open op internet. Keur goed wat zo hoort (bv. 443 voor je reverse proxy); daarna meldt RzSense alleen nog wat nieuw is.') . "\n" . implode("\n", array_slice($lines, 0, 20)),
                    $crit ? STATUS_WARN : STATUS_OK, 'system'));
            }
            return;
        }
        if (!$lines || setting('exposure_notify', '1') !== '1') {
            return;
        }
        I18n::forNotify(static fn() => Monitor::notifySystem('🌍 ' . t('Van buitenaf: er staat iets nieuws open'), implode("\n", array_slice($lines, 0, 20)),
            $crit ? STATUS_CRIT : STATUS_WARN, 'system'));
    }

    // ------------------------------------------------------------------ voor het scherm

    /** @return array<string, mixed> */
    public static function overview(): array
    {
        $info = json_decode((string) setting('exposure_ipinfo', '{}'), true) ?: [];
        $rows = [];
        foreach (q('SELECT ip, port, GROUP_CONCAT(source ORDER BY source) AS sources, MAX(names) AS names, MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen
                    FROM exposure WHERE gone_at IS NULL GROUP BY ip, port ORDER BY ip, port')->fetchAll() as $r) {
            $p = (int) $r['port'];
            $rows[] = ['ip' => $r['ip'], 'port' => $p, 'service' => self::NAMES[$p] ?? '', 'risky' => isset(self::RISKY[$p]), 'sources' => explode(',', (string) $r['sources']),
                'names' => (string) $r['names'], 'first_seen' => $r['first_seen'], 'last_seen' => $r['last_seen'], 'expected' => self::expected((string) $r['ip'], $p)];
        }
        $gone = q('SELECT ip, port, MAX(gone_at) AS gone_at FROM exposure WHERE gone_at > NOW() - INTERVAL 30 DAY AND (ip, port) NOT IN (SELECT ip, port FROM exposure WHERE gone_at IS NULL)
                   GROUP BY ip, port ORDER BY gone_at DESC LIMIT 20')->fetchAll();
        return ['on' => setting('exposure_on', '0') === '1', 'shodan' => setting('exposure_shodan', '0') === '1', 'self' => setting('exposure_self', '0') === '1',
            'auto' => setting('exposure_auto', '1') === '1', 'hours' => (int) setting('exposure_hours', '24'), 'ports' => (string) setting('exposure_ports', ''),
            'notify' => setting('exposure_notify', '1') === '1', 'last_run' => (string) setting('exposure_last_run', ''), 'error' => (string) setting('exposure_last_error', ''),
            'targets' => self::targets(), 'rows' => $rows, 'gone' => $gone, 'ipinfo' => $info, 'n_ports' => count(self::portList()), 'n_top' => count(self::TOP),
            'ok' => q('SELECT ip, port, note, created_by, created_at FROM exposure_ok ORDER BY port')->fetchAll()];
    }

    /** Goedkeuren (verwacht) of weer intrekken. $ip '*' = op elk adres */
    public static function setExpected(string $ip, int $port, bool $on, string $by, string $note = ''): ?string
    {
        if ($port < 1 || $port > 65535 || ($ip !== '*' && !self::publicIp($ip))) {
            return t('Ongeldige poort of adres');
        }
        if ($on) {
            q('INSERT INTO exposure_ok (ip, port, note, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE note = VALUES(note)', [$ip, $port, mb_substr($note, 0, 200), mb_substr($by, 0, 100)]);
        } else {
            q('DELETE FROM exposure_ok WHERE ip = ? AND port = ?', [$ip, $port]);
        }
        return null;
    }
}
