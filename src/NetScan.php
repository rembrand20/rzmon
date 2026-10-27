<?php
declare(strict_types=1);

/**
 * Netwerkscan (1.103.0): wat er allemaal op je lokale netwerken zit (Instellingen → Toegang → Lokale netwerken).
 * - Elk uur een lichte ronde: een ping naar elk adres, daarna de ARP-tabel van de RzMon-server (MAC-adressen, ook van
 *   apparaten die niet op ping antwoorden) en de DNS-naam van nieuwe apparaten.
 * - Een paar keer per dag een diepere ronde: open poorten, NetBIOS-naam, SSDP/UPnP (merk en model), mDNS (als avahi-browse
 *   er is) en de titel van de webpagina. Daaruit een gok wat voor apparaat het is.
 * - De fabrikant uit het MAC-adres: de lijst van Wireshark, maandelijks bijgewerkt (tabel mac_vendors).
 * Alleen netwerken met privé-adressen (RFC 1918), hooguit /22 per netwerk; nooit internet. Standaard uit.
 */
final class NetScan
{
    public const MAX_HOSTS = 1024;   // grootste netwerk dat gescand wordt (/22)
    private const MANUF_URL = 'https://www.wireshark.org/download/automated/data/manuf';
    /** Poorten voor de diepere ronde: wat het apparaat is, en wat er (riskant) openstaat */
    public const DEEP_PORTS = [21, 22, 23, 25, 53, 80, 81, 139, 443, 445, 515, 548, 554, 631, 1400, 1883, 1900, 2375, 3000, 3306, 3389, 5000, 5001,
        5353, 5432, 5900, 6053, 6379, 7000, 8006, 8008, 8009, 8080, 8123, 8443, 8883, 9000, 9100, 9200, 9443, 11211, 27017, 32400, 49152, 62078];
    /** Open poorten die aandacht vragen */
    public const RISKY = [21, 23, 2375, 3389, 5900, 6379, 9200, 11211, 27017];

    /** Waarom een open poort aandacht vraagt (vertaald) */
    public static function riskyReasons(): array
    {
        return [
            21 => t('FTP: wachtwoorden gaan onversleuteld over het netwerk'),
            23 => t('Telnet: onversleuteld en vaak met een standaardwachtwoord; geliefd bij botnets (Mirai)'),
            2375 => t('Docker-API zonder TLS: wie erbij kan, is root op die machine'),
            3389 => t('Extern bureaublad (RDP): alleen openzetten als het nodig is, nooit naar internet'),
            5900 => t('VNC: vaak zonder of met een zwak wachtwoord'),
            6379 => t('Redis: standaard zonder wachtwoord'),
            9200 => t('Elasticsearch: standaard zonder wachtwoord'),
            11211 => t('Memcached: zonder wachtwoord'),
            27017 => t('MongoDB: soms zonder wachtwoord'),
        ];
    }
    private const NAME_LEN = 100;

    public static function enabled(): bool
    {
        return setting('net_scan', '0') === '1';
    }

    // ------------------------------------------------------------------ netwerken

    /**
     * De netwerken uit Lokale netwerken die gescand worden, en wat er wordt overgeslagen (met de reden).
     * @return array{scan: list<string>, skip: list<array{net: string, why: string}>}
     */
    public static function networks(): array
    {
        $scan = [];
        $skip = [];
        foreach (parse_list((string) setting('trusted_networks', '')) as $net) {
            if (!preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $net, $m) || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $skip[] = ['net' => $net, 'why' => str_contains($net, ':') ? t('IPv6 wordt niet gescand') : t('geen netwerk (los adres)')];
                continue;
            }
            $bits = (int) $m[2];
            if (ip_in_cidr($m[1], '127.0.0.0/8')) {
                $skip[] = ['net' => $net, 'why' => t('de server zelf (loopback)')];
            } elseif (!ip_in_list($m[1], ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'])) {
                $skip[] = ['net' => $net, 'why' => t('geen privé-netwerk')];
            } elseif ($bits < 22 || $bits > 30) {
                $skip[] = ['net' => $net, 'why' => $bits < 22 ? t('te groot (meer dan {n} adressen)', ['n' => self::MAX_HOSTS]) : t('te klein')];
            } else {
                $scan[] = long2ip(ip2long($m[1]) & (-1 << (32 - $bits))) . '/' . $bits;
            }
        }
        return ['scan' => array_values(array_unique($scan)), 'skip' => $skip];
    }

    /** Alle adressen in een netwerk, zonder netwerk- en broadcastadres */
    private static function hostsOf(string $cidr): array
    {
        [$net, $bits] = explode('/', $cidr);
        $start = ip2long($net);
        $n = 2 ** (32 - (int) $bits);
        $out = [];
        for ($i = 1; $i < $n - 1; $i++) {
            $out[] = long2ip($start + $i);
        }
        return $out;
    }

    private static function inScope(string $ip, array $nets): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ip_in_list($ip, $nets);
    }

    // ------------------------------------------------------------------ cron

    /** Elke minuut vanuit cron: de lichte ronde elk uur, de diepere een paar keer per dag, de fabrikantenlijst maandelijks */
    public static function cron(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $lock = @fopen(sys_get_temp_dir() . '/rzmon-netscan-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'x') . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;   // de vorige scan loopt nog
        }
        $msg = [];
        self::expireBlocks();
        $st = self::state();
        if ((int) ($st['vendors_ok'] ?? 0) < time() - 30 * 86400 && (int) ($st['vendors_try'] ?? 0) < time() - 3600) {
            $msg[] = self::updateVendors();
        }
        $every = max(15, min(1440, (int) setting('net_sweep_minutes', '60'))) * 60;
        if ((int) ($st['sweep_at'] ?? 0) < time() - $every + 30) {
            $msg[] = self::sweep();
        }
        $deep = max(1, min(24, (int) setting('net_deep_per_day', '4')));
        if ((int) ($st['deep_at'] ?? 0) < time() - intdiv(86400, $deep) + 30) {
            $msg[] = self::deep();
        }
        flock($lock, LOCK_UN);
        return $msg ? implode(' · ', $msg) : null;
    }

    public static function state(array $set = []): array
    {
        $st = json_decode((string) q("SELECT value FROM settings WHERE name = 'net_state'")->fetchColumn(), true) ?: [];
        if ($set) {
            $st = array_replace($st, $set);
            save_setting('net_state', json_encode($st, JSON_UNESCAPED_UNICODE));
        }
        return $st;
    }

    // ------------------------------------------------------------------ lichte ronde

    /** Ping naar elk adres, daarna de ARP-tabel; nieuwe apparaten met hun DNS-naam en fabrikant */
    public static function sweep(): string
    {
        $t0 = microtime(true);
        self::state(['sweep_try' => time()]);
        $nets = self::networks()['scan'];
        if (!$nets) {
            self::state(['sweep_at' => time(), 'sweep_error' => t('Geen netwerk om te scannen: zet je netwerk (bv. 192.168.1.0/24) bij Toegang → Lokale netwerken')]);
            return 'Netwerkscan: geen netwerk';
        }
        $alive = [];
        foreach ($nets as $net) {
            foreach (self::pingSweep($net) as $ip) {
                $alive[$ip] = null;
            }
        }
        // MAC-adressen: de ARP-tabel (ook apparaten die niet op ping antwoorden, maar wel op ARP) en de eigen netwerkkaarten
        foreach (self::arpTable() + self::ownInterfaces() as $ip => $mac) {
            if (self::inScope($ip, $nets)) {
                $alive[$ip] = $mac;
            }
        }
        // UniFi (1.104.0): clients die nu verbonden zijn, ook als ze niet op ping of ARP antwoorden (slapende telefoons)
        $links = self::linkData();
        foreach ($links['unifi'] as $mac => $c) {
            if (!empty($c['online']) && self::inScope((string) $c['ip'], $nets)) {
                $alive[$c['ip']] ??= $mac;
            }
        }
        $first = !q('SELECT 1 FROM net_devices LIMIT 1')->fetchColumn();
        $new = [];
        foreach ($alive as $ip => $mac) {
            if ($d = self::upsert($ip, $mac, $first)) {
                $new[] = $d;
            }
        }
        // DNS-naam van nieuwe apparaten, en de namen uit UniFi en AdGuard
        self::enrichBasic(array_column($new, 'id'));
        self::applyLinks($links);
        if ($new && !$first) {
            self::notifyNew(array_column($new, 'id'));
        }
        self::state(['sweep_at' => time(), 'sweep_error' => '', 'sweep_n' => count($alive), 'sweep_ms' => (int) ((microtime(true) - $t0) * 1000),
            'links_error' => implode(' · ', $links['errors'])]);
        return 'Netwerkscan: ' . count($alive) . ' apparaten, ' . count($new) . ' nieuw';
    }

    /** IP-adressen die op ping antwoorden (fping als die er is, anders ping, 64 tegelijk) */
    private static function pingSweep(string $cidr): array
    {
        $ips = self::hostsOf($cidr);
        $out = [];
        if ($fping = self::bin('fping')) {
            exec(sprintf('%s -a -q -r 0 -t 400 %s 2>/dev/null', escapeshellarg($fping), implode(' ', array_map('escapeshellarg', $ips))), $out);
        } else {
            $ping = (string) config('ping_bin', '/bin/ping');
            $cmd = sprintf("xargs -P 64 -n 1 sh -c '%s -c 1 -W 1 -n \"$0\" >/dev/null 2>&1 && echo \"$0\"'", str_replace("'", '', $ping));
            $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($p)) {
                fwrite($pipes[0], implode("\n", $ips) . "\n");
                fclose($pipes[0]);
                $out = preg_split('/\s+/', trim((string) stream_get_contents($pipes[1])));
                fclose($pipes[1]);
                proc_close($p);
            }
        }
        return array_values(array_filter($out, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    }

    /** ARP-tabel van deze server: [ip => mac], alleen volledige regels */
    public static function arpTable(): array
    {
        $out = [];
        $raw = @file_get_contents('/proc/net/arp');
        foreach (preg_split('/\R/', (string) $raw) as $i => $line) {
            $f = preg_split('/\s+/', trim($line));
            if ($i === 0 || count($f) < 4 || $f[2] !== '0x2') {
                continue;
            }
            if (($mac = self::normMac($f[3])) !== null) {
                $out[$f[0]] = $mac;
            }
        }
        return $out;
    }

    /** Is dit een adres van de RzMon-server zelf? (1.105.2) */
    public static function isOwnIp(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', '::1'], true) || array_key_exists($ip, self::ownInterfaces());
    }

    /** IPv4-adressen van deze server zelf, met het MAC-adres van die netwerkkaart */
    private static function ownInterfaces(): array
    {
        $out = [];
        exec('ip -o -4 addr show 2>/dev/null', $lines);
        foreach ($lines as $l) {
            if (preg_match('#^\d+:\s+(\S+?)(?:@\S+)?\s+inet\s+(\d+\.\d+\.\d+\.\d+)/#', $l, $m) && $m[1] !== 'lo') {
                $out[$m[2]] = self::normMac((string) @file_get_contents('/sys/class/net/' . basename($m[1]) . '/address'));
            }
        }
        return $out;
    }

    public static function normMac(?string $mac): ?string
    {
        $mac = strtolower(trim((string) $mac));
        if (!preg_match('/^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$/', $mac) || $mac === '00:00:00:00:00:00' || $mac === 'ff:ff:ff:ff:ff:ff') {
            return null;
        }
        return str_replace('-', ':', $mac);
    }

    /** Willekeurig (privé) MAC-adres, zoals telefoons per wifi-netwerk gebruiken: het "lokaal beheerd"-bit */
    public static function randomMac(?string $mac): bool
    {
        return $mac !== null && (hexdec($mac[1]) & 2) === 2;
    }

    /** Apparaat bijwerken of toevoegen. @return array|null het nieuwe apparaat */
    private static function upsert(string $ip, ?string $mac, bool $first): ?array
    {
        $now = now();
        $row = null;
        if ($mac !== null) {
            $row = q('SELECT * FROM net_devices WHERE mac = ?', [$mac])->fetch()
                ?: q('SELECT * FROM net_devices WHERE mac IS NULL AND ip = ?', [$ip])->fetch() ?: null;
        } else {
            $row = q('SELECT * FROM net_devices WHERE ip = ? ORDER BY mac IS NULL DESC, last_seen DESC LIMIT 1', [$ip])->fetch() ?: null;
        }
        if ($row) {
            $info = json_decode((string) $row['info'], true) ?: [];
            if ($row['ip'] !== $ip) {   // ander IP-adres: de vorige onthouden
                $info['ips'] = array_slice(array_values(array_unique(array_merge([$row['ip']], (array) ($info['ips'] ?? [])))), 0, 5);
            }
            q('UPDATE net_devices SET ip = ?, mac = COALESCE(?, mac), last_seen = ?, info = ? WHERE id = ?',
                [$ip, $mac, $now, json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row['id']]);
            return null;
        }
        q('INSERT INTO net_devices (mac, ip, random_mac, vendor, status, first_seen, last_seen, info) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$mac, $ip, (int) self::randomMac($mac), $mac && !self::randomMac($mac) ? self::vendor($mac) : null, 'new', $now, $now, '{}']);
        return ['id' => (int) db()->lastInsertId(), 'ip' => $ip, 'mac' => $mac, 'first' => $first];
    }

    /** DNS-naam (2 s per adres, 16 tegelijk) */
    private static function enrichBasic(array $ids): void
    {
        if (!$ids) {
            return;
        }
        $rows = q('SELECT id, ip FROM net_devices WHERE id IN (' . implode(',', array_map('intval', array_slice($ids, 0, 512))) . ')')->fetchAll();
        $names = self::reverseDns(array_column($rows, 'ip'));
        foreach ($rows as $r) {
            if (isset($names[$r['ip']])) {
                q('UPDATE net_devices SET hostname = ? WHERE id = ?', [mb_substr($names[$r['ip']], 0, self::NAME_LEN), $r['id']]);
            }
        }
    }

    /** [ip => naam] via de DNS-server van deze server (bv. AdGuard Home, die het weer bij je router vraagt) */
    public static function reverseDns(array $ips): array
    {
        $ips = array_values(array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP)));
        if (!$ips) {
            return [];
        }
        $p = proc_open("xargs -P 16 -n 1 sh -c 'timeout 2 getent hosts \"$0\" 2>/dev/null'", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            return [];
        }
        fwrite($pipes[0], implode("\n", $ips) . "\n");
        fclose($pipes[0]);
        $raw = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        $out = [];
        foreach (preg_split('/\R/', $raw) as $l) {
            $f = preg_split('/\s+/', trim($l));
            if (count($f) >= 2 && filter_var($f[0], FILTER_VALIDATE_IP) && $f[1] !== $f[0] && preg_match('/^[A-Za-z0-9._-]+$/', $f[1])) {
                $out[$f[0]] = $f[1];
            }
        }
        return $out;
    }

    /** ℹ️ Nieuw apparaat op het netwerk (Systeeminfo); bij veel tegelijk één samengevat bericht */
    private static function notifyNew(array $ids): void
    {
        $rows = q('SELECT * FROM net_devices WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll();
        if (!$rows) {
            return;
        }
        if (count($rows) > 5) {
            Monitor::sysInfo('net_new', null, null, '🆕 ' . t('{n} nieuwe apparaten op je netwerk', ['n' => count($rows)]),
                fn() => implode("\n", array_map(fn($r) => '• ' . self::label($r) . " · {$r['ip']}", array_slice($rows, 0, 15))) . "\n" . t('Zie het tabblad Netwerk.'));
            return;
        }
        foreach ($rows as $r) {
            Monitor::sysInfo('net_new', null, null, '🆕 ' . t('Nieuw apparaat op je netwerk: {name}', ['name' => self::label($r)]),
                fn() => implode(' · ', array_filter([$r['ip'], $r['mac'] ? strtoupper($r['mac']) : null, $r['vendor'], (int) $r['random_mac'] ? t('privé-adres (willekeurig MAC)') : null]))
                    . "\n" . t('Bekend of niet? Zie het tabblad Netwerk.'), $r['ip']);
        }
    }

    /** Naam om te tonen: zelf gegeven, DNS, NetBIOS, mDNS, UPnP, anders de fabrikant */
    public static function label(array $r): string
    {
        $info = is_array($r['info'] ?? null) ? $r['info'] : (json_decode((string) ($r['info'] ?? ''), true) ?: []);
        foreach ([$r['name'] ?? '', $info['unifi']['name'] ?? '', $info['adguard'] ?? '', $r['hostname'] ?? '', $info['unifi']['hostname'] ?? '', $info['netbios'] ?? '',
            $info['mdns']['name'] ?? '', $info['ssdp']['name'] ?? ''] as $n) {
            if (trim((string) $n) !== '') {
                return preg_replace('/\.(local|lan|home|fritz\.box|localdomain)$/i', '', trim((string) $n));
            }
        }
        return $r['vendor'] ? t('apparaat van {v}', ['v' => $r['vendor']]) : t('onbekend apparaat');
    }

    // ------------------------------------------------------------------ diepere ronde

    /** Poorten, NetBIOS, SSDP, mDNS en webpagina-titel van alles wat de laatste 24 uur gezien is */
    public static function deep(): string
    {
        $t0 = microtime(true);
        self::state(['deep_try' => time()]);
        $nets = self::networks()['scan'];
        $rows = q('SELECT * FROM net_devices WHERE last_seen >= NOW() - INTERVAL 1 DAY ORDER BY last_seen DESC LIMIT 512')->fetchAll();
        $rows = array_values(array_filter($rows, fn($r) => self::inScope($r['ip'], $nets)));
        if (!$rows) {
            self::state(['deep_at' => time()]);
            return 'Netwerkscan diep: niets gezien';
        }
        $ips = array_column($rows, 'ip');
        $netbios = self::netbios($ips);
        $ssdp = self::ssdp();
        $mdns = self::mdns();
        $dns = self::reverseDns($ips);
        $ports = [];
        foreach ($ips as $ip) {
            try {
                $ports[$ip] = array_column(PortScan::scan($ip, self::DEEP_PORTS, 0.6, 100), 'port');
            } catch (Throwable) {
                $ports[$ip] = [];
            }
        }
        $http = self::httpTitles($ports);
        $gw = self::gateway();
        foreach ($rows as $r) {
            $ip = $r['ip'];
            $info = json_decode((string) $r['info'], true) ?: [];
            if (isset($netbios[$ip])) {
                $info['netbios'] = $netbios[$ip];
            }
            if (isset($ssdp[$ip])) {
                $info['ssdp'] = $ssdp[$ip];
            }
            if (isset($mdns[$ip])) {
                $info['mdns'] = $mdns[$ip];
            }
            if (isset($http[$ip])) {
                $info['http'] = $http[$ip];
            }
            $info['gateway'] = $ip === $gw;
            $p = $ports[$ip] ?? [];
            $r['info'] = $info;
            $r['hostname'] = $dns[$ip] ?? $r['hostname'];
            if ($r['mac'] && !$r['vendor'] && !self::randomMac($r['mac'])) {   // een willekeurig MAC-adres heeft geen fabrikant
                $r['vendor'] = self::vendor($r['mac']);
            }
            q('UPDATE net_devices SET hostname = ?, vendor = ?, ports = ?, kind = ?, info = ?, deep_at = ? WHERE id = ?', [
                $r['hostname'] !== null ? mb_substr((string) $r['hostname'], 0, self::NAME_LEN) : null, $r['vendor'], json_encode($p), self::guessKind($r, $p),
                json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), $r['id']]);
        }
        self::state(['deep_at' => time(), 'deep_n' => count($rows), 'deep_ms' => (int) ((microtime(true) - $t0) * 1000)]);
        return 'Netwerkscan diep: ' . count($rows) . ' apparaten';
    }

    /** Standaardgateway van deze server (je router) */
    private static function gateway(): ?string
    {
        exec('ip -4 route show default 2>/dev/null', $l);
        return preg_match('/via (\d+\.\d+\.\d+\.\d+)/', implode("\n", $l), $m) ? $m[1] : null;
    }

    /** NetBIOS-naam (Windows, NAS, Samba): één UDP-vraag per adres, samen hooguit 1,5 s */
    public static function netbios(array $ips): array
    {
        // Vraag naar de naamtabel (NBSTAT) voor "*"
        $q = pack('n', 0x5a5a) . "\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00" . "\x20" . 'CK' . str_repeat('AA', 15) . "\x00" . "\x00\x21\x00\x01";
        $socks = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client("udp://$ip:137", $e, $es, 1);
            if ($s) {
                stream_set_blocking($s, false);
                @fwrite($s, $q);
                $socks[(int) $s] = [$s, $ip];
            }
        }
        $out = [];
        $end = microtime(true) + 1.5;
        while ($socks && ($left = $end - microtime(true)) > 0) {
            $read = array_column($socks, 0);
            $w = $x = null;
            if (!@stream_select($read, $w, $x, 0, (int) ($left * 1e6))) {
                break;
            }
            foreach ($read as $s) {
                [, $ip] = $socks[(int) $s];
                $name = self::parseNbstat((string) @fread($s, 2048));
                if ($name !== null) {
                    $out[$ip] = $name;
                }
                fclose($s);
                unset($socks[(int) $s]);
            }
        }
        foreach ($socks as [$s]) {
            fclose($s);
        }
        return $out;
    }

    /** Eerste unieke werkstation-/servernaam uit een NBSTAT-antwoord */
    public static function parseNbstat(string $p): ?string
    {
        if (strlen($p) < 57) {
            return null;
        }
        $off = 56;   // 12 kop + 34 naam + 2 type + 2 klasse + 4 ttl + 2 lengte
        $n = ord($p[$off]);
        $off++;
        for ($i = 0; $i < $n && $off + 18 <= strlen($p); $i++, $off += 18) {
            $name = rtrim(substr($p, $off, 15));
            $suffix = ord($p[$off + 15]);
            $group = (ord($p[$off + 16]) & 0x80) !== 0;
            if (!$group && in_array($suffix, [0x00, 0x20], true) && preg_match('/^[\x21-\x7e][\x20-\x7e]*$/', $name)) {
                return $name;
            }
        }
        return null;
    }

    /** SSDP/UPnP: wie antwoordt op een M-SEARCH, met merk en model uit de beschrijving. [ip => [name, maker, model, type, server]] */
    public static function ssdp(): array
    {
        $s = @stream_socket_client('udp://239.255.255.250:1900', $e, $es, 1);
        if (!$s) {
            return [];
        }
        stream_set_blocking($s, false);
        @fwrite($s, "M-SEARCH * HTTP/1.1\r\nHOST: 239.255.255.250:1900\r\nMAN: \"ssdp:discover\"\r\nMX: 2\r\nST: ssdp:all\r\n\r\n");
        $loc = [];
        $server = [];
        $end = microtime(true) + 3;
        while (($left = $end - microtime(true)) > 0) {
            $r = [$s];
            $w = $x = null;
            if (!@stream_select($r, $w, $x, 0, (int) ($left * 1e6))) {
                break;
            }
            $msg = @stream_socket_recvfrom($s, 4096, 0, $peer);
            $ip = preg_replace('/:\d+$/', '', (string) $peer);
            if ($msg === false || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (preg_match('/^LOCATION:\s*(http:\/\/\S+)/mi', $msg, $m) && !isset($loc[$ip])) {
                $loc[$ip] = trim($m[1]);
            }
            if (preg_match('/^SERVER:\s*(.+)$/mi', $msg, $m)) {
                $server[$ip] = mb_substr(trim($m[1]), 0, 120);
            }
        }
        fclose($s);
        $out = [];
        foreach (array_slice($loc, 0, 40, true) as $ip => $url) {
            // Alleen de beschrijving van het apparaat zelf ophalen, niet ergens anders heen
            if (parse_url($url, PHP_URL_HOST) !== $ip) {
                continue;
            }
            $xml = self::fetch($url, 2);
            if ($xml !== null && ($d = self::parseUpnp($xml))) {
                $out[$ip] = $d + ['server' => $server[$ip] ?? ''];
            }
        }
        foreach ($server as $ip => $sv) {
            $out[$ip] ??= ['name' => '', 'maker' => '', 'model' => '', 'type' => '', 'server' => $sv];
        }
        return $out;
    }

    public static function parseUpnp(string $xml): ?array
    {
        $g = static fn(string $tag) => preg_match('#<' . $tag . '>([^<]{1,200})</' . $tag . '>#i', $xml, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1) : '';
        $d = ['name' => $g('friendlyName'), 'maker' => $g('manufacturer'), 'model' => trim($g('modelName') . ' ' . $g('modelNumber')),
            'type' => preg_replace('/^urn:[^:]+:device:([^:]+):\d+$/', '$1', $g('deviceType'))];
        return array_filter($d) ? $d : null;
    }

    /** mDNS/Bonjour via avahi-browse (als die er is): [ip => [name, types[]]] */
    public static function mdns(): array
    {
        if (!($bin = self::bin('avahi-browse'))) {
            return [];
        }
        exec('timeout 8 ' . escapeshellarg($bin) . ' -aprt 2>/dev/null', $lines);
        return self::parseAvahi($lines);
    }

    public static function parseAvahi(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            // =;eth0;IPv4;Woonkamer;_googlecast._tcp;local;Chromecast-abc.local;192.168.2.40;8009;"…"
            $f = str_getcsv($l, ';', '"', '\\');
            if (count($f) < 9 || $f[0] !== '=' || $f[2] !== 'IPv4' || !filter_var($f[7], FILTER_VALIDATE_IP)) {
                continue;
            }
            $ip = $f[7];
            $name = preg_replace_callback('/\\\\(\d{3})/', fn($m) => chr((int) $m[1]), $f[3]);
            $out[$ip]['name'] ??= mb_substr($name, 0, self::NAME_LEN);
            $out[$ip]['host'] ??= mb_substr($f[6], 0, self::NAME_LEN);
            $out[$ip]['types'][] = $f[4];
        }
        foreach ($out as &$o) {
            $o['types'] = array_values(array_unique($o['types']));
        }
        return $out;
    }

    /** Titel van de webpagina (http/https, 2 s, alles tegelijk) */
    private static function httpTitles(array $ports): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($ports as $ip => $p) {
            foreach ([[443, 'https'], [8443, 'https'], [80, 'http'], [8080, 'http']] as [$port, $scheme]) {
                if (in_array($port, $p, true)) {
                    $ch = curl_init("$scheme://$ip:$port/");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION,
                        CURLOPT_HEADER => true, CURLOPT_RANGE => '0-65535', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
                    curl_multi_add_handle($mh, $ch);
                    $hs[] = [$ch, $ip, $port];
                    break;
                }
            }
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($hs as [$ch, $ip, $port]) {
            $raw = (string) curl_multi_getcontent($ch);
            $title = preg_match('#<title[^>]*>\s*([^<]{1,150})#i', $raw, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)) : '';
            $server = preg_match('/^Server:\s*(.+)$/mi', $raw, $m) ? mb_substr(trim($m[1]), 0, 80) : '';
            if ($title !== '' || $server !== '') {
                $out[$ip] = ['port' => $port, 'title' => mb_substr(preg_replace('/\s+/', ' ', $title), 0, 120), 'server' => $server];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function fetch(string $url, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_RANGE => '0-65535', CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $raw !== false && $code < 400 ? (string) $raw : null;
    }

    private static function bin(string $name): ?string
    {
        foreach (['/usr/bin/', '/usr/sbin/', '/bin/', '/sbin/', '/usr/local/bin/'] as $d) {
            if (is_executable($d . $name)) {
                return $d . $name;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ wat voor apparaat

    /** Soort apparaat (sleutel voor het icoon) uit fabrikant, namen, poorten en diensten */
    public static function guessKind(array $r, array $ports): string
    {
        $info = is_array($r['info'] ?? null) ? $r['info'] : (json_decode((string) ($r['info'] ?? ''), true) ?: []);
        $v = strtolower((string) ($r['vendor'] ?? ''));
        $names = strtolower(implode(' ', array_filter([$r['hostname'] ?? '', $info['unifi']['name'] ?? '', $info['unifi']['hostname'] ?? '', $info['adguard'] ?? '', $info['netbios'] ?? '', $info['mdns']['name'] ?? '', $info['mdns']['host'] ?? '',
            $info['ssdp']['name'] ?? '', $info['ssdp']['model'] ?? '', $info['ssdp']['maker'] ?? '', $info['ssdp']['type'] ?? '', $info['http']['title'] ?? ''])));
        $types = ' ' . implode(' ', (array) ($info['mdns']['types'] ?? [])) . ' ';
        $has = static fn(int ...$p) => (bool) array_intersect($p, $ports);
        $re = static fn(string $re, string $s) => (bool) preg_match($re, $s);
        return match (true) {
            !empty($info['gateway']) => 'router',
            $re('/ubiquiti|unifi/', $v . ' ' . $names) => 'network',
            $re('/mikrotik|tp-?link.*(switch|router)|netgear|zyxel|cisco|aruba|juniper|routerboard/', $v . ' ' . $names) => 'network',
            $has(631, 9100, 515) || str_contains($types, '_ipp.') || str_contains($types, '_printer.') || $re('/printer|laserjet|officejet|brother|epson|canon/', $names) => 'printer',
            $has(554) || $re('/hikvision|dahua|reolink|axis comm|amcrest|foscam|ezviz|camera|cam\b|doorbell/', $v . ' ' . $names) => 'camera',
            $has(8123) || $re('/home assistant|homeassistant/', $names) => 'smarthome',
            $re('/synology|qnap|truenas|diskstation|nas\b/', $v . ' ' . $names) || $has(5000, 5001) && $has(445) => 'nas',
            $has(8006) || $re('/proxmox|pve/', $names) => 'server',
            $re('/espressif|shelly|tuya|sonoff|tasmota|esphome|signify|philips lighting|hue|ikea|aqara|lumi|xiaomi|tado|nuki|wiz|meross|govee|broadlink/', $v . ' ' . $names) || $has(6053) => 'iot',
            $re('/sonos|chromecast|google.?tv|roku|samsung.*tv|lg.*tv|webos|tizen|bravia|apple ?tv|nvidia shield|media|renderer|denon|marantz|yamaha|bose/', $v . ' ' . $names)
                || str_contains($types, '_googlecast.') || str_contains($types, '_airplay.') || $has(8008, 8009) => 'media',
            $re('/iphone|ipad|android|galaxy|pixel|oneplus|huawei|redmi|phone/', $names) || $has(62078) || (!empty($r['random_mac']) && !$ports) => 'phone',
            $re('/raspberry/', $v) || $has(22) && !$has(3389, 445) => 'server',
            $has(3389, 445, 139, 5900) || $re('/desktop|laptop|macbook|imac|\bpc\b|workstation/', $names) => 'computer',
            $re('/apple/', $v) => 'computer',
            default => 'unknown',
        };
    }

    // ------------------------------------------------------------------ fabrikant (MAC → naam)

    public static function vendor(string $mac): ?string
    {
        $hex = strtoupper(str_replace(':', '', $mac));
        try {
            $r = q('SELECT vendor FROM mac_vendors WHERE prefix IN (?, ?, ?) ORDER BY LENGTH(prefix) DESC LIMIT 1', [substr($hex, 0, 9), substr($hex, 0, 7), substr($hex, 0, 6)])->fetchColumn();
        } catch (Throwable) {
            return null;
        }
        return $r !== false ? (string) $r : null;
    }

    /** Lijst van Wireshark ophalen (maandelijks) en de fabrikant van bekende apparaten bijwerken */
    public static function updateVendors(): string
    {
        self::state(['vendors_try' => time()]);
        $url = (string) (((array) config('netscan_urls', []))['manuf'] ?? self::MANUF_URL);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $rows = $raw !== false ? self::parseManuf((string) $raw) : [];
        if (count($rows) < 100) {
            self::state(['vendors_error' => t('fabrikantenlijst niet op te halen ({url})', ['url' => parse_url($url, PHP_URL_HOST) ?: $url])]);
            return 'Fabrikanten: niet op te halen';
        }
        db()->beginTransaction();
        q('DELETE FROM mac_vendors');
        foreach (array_chunk($rows, 1000) as $chunk) {
            q('INSERT IGNORE INTO mac_vendors (prefix, vendor) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?)')), array_merge(...$chunk));
        }
        db()->commit();
        foreach (q('SELECT id, mac FROM net_devices WHERE mac IS NOT NULL AND vendor IS NULL AND random_mac = 0')->fetchAll() as $d) {
            if ($v = self::vendor((string) $d['mac'])) {
                q('UPDATE net_devices SET vendor = ? WHERE id = ?', [$v, $d['id']]);
            }
        }
        self::state(['vendors_ok' => time(), 'vendors_n' => count($rows), 'vendors_error' => '']);
        return 'Fabrikanten: ' . count($rows);
    }

    /** Wireshark manuf: "00:00:0C<tab>Cisco<tab>Cisco Systems, Inc" of "00:1B:C5:00:00:00/36<tab>…". @return list<array{0: string, 1: string}> */
    public static function parseManuf(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $f = explode("\t", $line);
            if (count($f) < 2 || !preg_match('#^([0-9A-Fa-f]{2}(?:[:-][0-9A-Fa-f]{2}){2,5})(?:/(\d+))?$#', trim($f[0]), $m)) {
                continue;
            }
            $hex = strtoupper(preg_replace('/[:-]/', '', $m[1]));
            $bits = isset($m[2]) ? (int) $m[2] : 24;
            if (!in_array($bits, [24, 28, 36], true)) {
                continue;
            }
            $name = trim($f[2] ?? '') !== '' ? trim($f[2]) : trim($f[1]);
            $out[] = [substr($hex, 0, intdiv($bits, 4)), mb_substr($name, 0, 120)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ voor de pagina

    /** Alle apparaten, met of ze in RzMon staan en wat er riskant openstaat */
    public static function list(): array
    {
        $hosts = q('SELECT id, name, address, agent_ips FROM hosts')->fetchAll();
        $byAddr = [];
        foreach ($hosts as $h) {
            $byAddr[strtolower((string) $h['address'])] = $h;
            foreach (parse_list((string) ($h['agent_ips'] ?? '')) as $ip) {
                $byAddr[$ip] ??= $h;
            }
        }
        $out = [];
        $own = array_keys(self::ownInterfaces());
        foreach (q('SELECT * FROM net_devices ORDER BY INET_ATON(ip)')->fetchAll() as $r) {
            $r['info'] = json_decode((string) $r['info'], true) ?: [];
            $r['ports'] = array_map('intval', json_decode((string) $r['ports'], true) ?: []);
            $r['blocked'] = json_decode((string) ($r['blocked'] ?? ''), true) ?: null;
            $h = $byAddr[$r['ip']] ?? ($r['hostname'] ? ($byAddr[strtolower((string) $r['hostname'])] ?? $byAddr[strtolower(preg_replace('/\..*$/', '', (string) $r['hostname']))] ?? null) : null);
            $r['host'] = $h ? ['id' => (int) $h['id'], 'name' => $h['name']] : null;
            $r['self'] = in_array($r['ip'], $own, true);   // de RzMon-server zelf
            if ($r['self']) {
                $r['host'] ??= ['id' => 0, 'name' => 'RzMon'];
                $r['kind'] = 'server';
            }
            $r['label'] = $r['self'] && !$r['name'] ? t('RzMon-server (deze server)') : self::label($r);
            $r['risky'] = array_values(array_intersect(self::RISKY, $r['ports']));
            if ($r['kind'] === 'nas' || $r['kind'] === 'server' || $r['host']) {
                $r['risky'] = array_values(array_diff($r['risky'], [3389]));   // RDP op een server: normaal
            }
            $out[] = $r;
        }
        return $out;
    }

    public static function newCount(): int
    {
        try {
            return (int) q("SELECT COUNT(*) FROM net_devices WHERE status = 'new'")->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Bekend, negeren, naam, soort of notitie */
    public static function save(int $id, array $in, string $by): bool
    {
        $r = q('SELECT * FROM net_devices WHERE id = ?', [$id])->fetch();
        if (!$r) {
            return false;
        }
        $set = [];
        if (isset($in['status']) && in_array($in['status'], ['new', 'known', 'ignored'], true)) {
            $set['status'] = $in['status'];
        }
        if (array_key_exists('name', $in)) {
            $set['name'] = mb_substr(trim((string) $in['name']), 0, self::NAME_LEN) ?: null;
        }
        if (isset($in['kind_user'])) {
            $set['kind_user'] = in_array($in['kind_user'], self::KINDS, true) ? $in['kind_user'] : null;
        }
        if (array_key_exists('notes', $in)) {
            $set['notes'] = mb_substr(trim((string) $in['notes']), 0, 500) ?: null;
        }
        if (!$set) {
            return true;
        }
        q('UPDATE net_devices SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $id]);
        AgentCommands::audit($by, 'Netwerkapparaat bijgewerkt', self::label($r) . " ({$r['ip']}): " . json_encode($set, JSON_UNESCAPED_UNICODE));
        return true;
    }

    public const KINDS = ['router', 'network', 'server', 'nas', 'computer', 'phone', 'media', 'printer', 'camera', 'iot', 'smarthome', 'unknown'];

    /** Alle nieuwe als bekend markeren */
    public static function approveAll(string $by): int
    {
        $n = q("UPDATE net_devices SET status = 'known' WHERE status = 'new'")->rowCount();
        AgentCommands::audit($by, 'Netwerkapparaten bekend', "$n");
        return $n;
    }

    public static function forget(int $id, string $by): bool
    {
        $r = q('SELECT * FROM net_devices WHERE id = ?', [$id])->fetch();
        if ($r) {
            q('DELETE FROM net_devices WHERE id = ?', [$id]);
            AgentCommands::audit($by, 'Netwerkapparaat vergeten', self::label($r) . " ({$r['ip']})");
        }
        return (bool) $r;
    }

    // ------------------------------------------------------------------ UniFi en AdGuard (1.104.0)

    /** Clients uit UniFi en namen uit AdGuard (fouten apart, de scan gaat gewoon door) */
    private static function linkData(): array
    {
        $out = ['unifi' => [], 'ag_ips' => [], 'ag_macs' => [], 'errors' => []];
        try {
            if (NetLinks::unifiOn()) {
                $u = NetLinks::unifiClients();
                $u['ok'] ? $out['unifi'] = $u['clients'] : $out['errors'][] = $u['error'];
            }
            if (NetLinks::adguardOn()) {
                $a = NetLinks::adguardNames();
                $out['ag_ips'] = $a['ips'];
                $out['ag_macs'] = $a['macs'];
                array_push($out['errors'], ...$a['errors']);
            }
        } catch (Throwable $e) {
            $out['errors'][] = $e->getMessage();
        }
        return $out;
    }

    /** Namen, wifi-netwerk, access point en blokkade uit UniFi en AdGuard bij de apparaten zetten */
    private static function applyLinks(array $links): void
    {
        if (!$links['unifi'] && !$links['ag_ips'] && !$links['ag_macs']) {
            return;
        }
        foreach (q('SELECT id, mac, ip, info FROM net_devices')->fetchAll() as $r) {
            $info = json_decode((string) $r['info'], true) ?: [];
            $before = $info;
            if ($r['mac'] && isset($links['unifi'][$r['mac']])) {
                $c = $links['unifi'][$r['mac']];
                $info['unifi'] = array_filter(['name' => $c['name'], 'hostname' => $c['hostname'], 'essid' => $c['essid'], 'ap' => $c['ap'], 'sw' => $c['sw'] ?? '',
                    'wired' => $c['wired'], 'blocked' => $c['blocked']], fn($v) => $v !== '' && $v !== null);
            }
            $ag = ($r['mac'] ? ($links['ag_macs'][$r['mac']] ?? null) : null) ?? ($links['ag_ips'][$r['ip']] ?? null);
            if ($ag !== null && $ag !== '') {
                $info['adguard'] = mb_substr($ag, 0, self::NAME_LEN);
            }
            if ($info !== $before) {
                q('UPDATE net_devices SET info = ? WHERE id = ?', [json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']]);
            }
        }
    }

    /** Waarom dit apparaat niet geweerd mag worden, of null */
    public static function cannotBlock(array $r, string $byIp): ?string
    {
        $ip = (string) $r['ip'];
        $own = array_keys(self::ownInterfaces());
        return match (true) {
            $ip === $byIp => t('Dit is het apparaat waarmee je nu in RzMon zit'),
            in_array($ip, $own, true) => t('Dit is de RzMon-server zelf'),
            $ip === self::gateway() => t('Dit is je router (standaardgateway); dan valt je hele netwerk om'),
            in_array($ip, NetLinks::ownIps(), true) => t('Dit is je UniFi-controller of een AdGuard-server; die weren sluit je zelf buiten'),
            (bool) q("SELECT 1 FROM hosts WHERE address = ? OR CONCAT(' ', COALESCE(agent_ips, ''), ' ') LIKE ? LIMIT 1", [$ip, '% ' . $ip . ' %'])->fetchColumn()
                => t('Dit apparaat staat in RzMon als server; haal het daar eerst weg'),
            Whitelist::ip($ip) => t('{ip} staat op de whitelist (IDS → Blokkades)', ['ip' => $ip]),
            default => null,
        };
    }

    /**
     * Een apparaat weren: van het wifi (UniFi, op MAC), geen DNS meer (AdGuard, op IP) en geen toegang tot RzMon en je
     * servers (op IP). $days 0 = tot je het weer toelaat. @return array{ok: bool, done: list<string>, errors: list<string>}
     */
    public static function block(int $id, array $via, int $days, string $reason, string $by, string $byIp): array
    {
        $r = q('SELECT * FROM net_devices WHERE id = ?', [$id])->fetch();
        if (!$r) {
            return ['ok' => false, 'done' => [], 'errors' => [t('Niet gevonden')]];
        }
        if ($why = self::cannotBlock($r, $byIp)) {
            return ['ok' => false, 'done' => [], 'errors' => [$why]];
        }
        $ip = (string) $r['ip'];
        $until = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $reason = mb_substr(trim($reason) ?: t('geweerd van het netwerk'), 0, 200);
        $done = [];
        $errors = [];
        if (!empty($via['unifi'])) {
            if (!$r['mac']) {
                $errors[] = t('UniFi: geen MAC-adres bekend');
            } elseif (($e = NetLinks::unifiBlock((string) $r['mac'], true)) === true) {
                $done[] = 'unifi';
            } else {
                $errors[] = $e;
            }
        }
        if (!empty($via['adguard'])) {
            ($e = NetLinks::adguardDisallow($ip, true)) === true ? $done[] = 'adguard' : $errors[] = $e;
        }
        if (!empty($via['rzmon'])) {
            $e = Security::block($ip, $reason . ' (' . self::label($r) . ')', $by, $until);
            if ($e === true || str_contains((string) $e, 'al ')) {
                $done[] = 'rzmon';
            } else {
                $errors[] = 'RzMon: ' . $e;
            }
            foreach (q("SELECT id FROM hosts WHERE mode = 'agent' AND enabled = 1")->fetchAll(PDO::FETCH_COLUMN) as $hid) {
                Ids::block((int) $hid, $ip, $days > 0 ? $days * 24 : null, $reason, $by, $byIp);   // al geblokkeerd: geen probleem
            }
        }
        if ($done) {
            $b = ['at' => now(), 'until' => $until, 'by' => $by, 'via' => $done, 'ip' => $ip, 'mac' => $r['mac'], 'reason' => $reason];
            q('UPDATE net_devices SET blocked = ? WHERE id = ?', [json_encode($b, JSON_UNESCAPED_UNICODE), $id]);
            AgentCommands::audit($by, 'Netwerkapparaat geweerd', self::label($r) . " ($ip, " . ($r['mac'] ?? '-') . '): ' . implode(', ', $done) . ($until ? " tot $until" : ' tot weer toegelaten'));
            Monitor::sysInfo('ip_block', null, null, '⛔ ' . t('Apparaat geweerd: {name}', ['name' => self::label($r)]),
                fn() => t('{name} ({ip}) is geweerd via {via} door {by}', ['name' => self::label($r), 'ip' => $ip, 'via' => implode(', ', self::viaLabels($done)), 'by' => $by])
                    . ($until ? ' · ' . t('tot {t}', ['t' => date('d-m-Y H:i', strtotime($until))]) : ''), $ip);
        }
        return ['ok' => (bool) $done, 'done' => $done, 'errors' => $errors];
    }

    public static function viaLabels(array $via): array
    {
        return array_map(fn($v) => ['unifi' => t('UniFi (wifi)'), 'adguard' => t('AdGuard (DNS)'), 'rzmon' => t('RzMon en je servers')][$v] ?? $v, $via);
    }

    /** Weer toelaten: alles terugdraaien wat bij het weren gedaan is */
    public static function unblock(int $id, string $by, bool $auto = false): array
    {
        $r = q('SELECT * FROM net_devices WHERE id = ?', [$id])->fetch();
        $b = $r ? json_decode((string) ($r['blocked'] ?? ''), true) : null;
        if (!$b) {
            return ['ok' => false, 'errors' => [t('Niet geweerd')]];
        }
        $errors = [];
        $ip = (string) ($b['ip'] ?? $r['ip']);
        if (in_array('unifi', $b['via'], true) && !empty($b['mac']) && ($e = NetLinks::unifiBlock((string) $b['mac'], false)) !== true) {
            $errors[] = $e;
        }
        if (in_array('adguard', $b['via'], true) && ($e = NetLinks::adguardDisallow($ip, false)) !== true) {
            $errors[] = $e;
        }
        if (in_array('rzmon', $b['via'], true)) {
            foreach (q('SELECT id FROM rz_blocks WHERE net = ?', [$ip])->fetchAll(PDO::FETCH_COLUMN) as $bid) {
                Security::unblock((int) $bid, $by);
            }
            foreach (q('SELECT id FROM ids_blocks WHERE ip = ? AND removed_at IS NULL', [$ip])->fetchAll(PDO::FETCH_COLUMN) as $bid) {
                Ids::unblock((int) $bid, $by);
            }
        }
        q('UPDATE net_devices SET blocked = NULL WHERE id = ?', [$id]);
        AgentCommands::audit($by, 'Netwerkapparaat weer toegelaten', self::label($r) . " ($ip)" . ($auto ? ' (verlopen)' : '') . ($errors ? ' · ' . implode(' · ', $errors) : ''));
        Monitor::sysInfo('ip_unblock', null, null, '✅ ' . t('Apparaat weer toegelaten: {name}', ['name' => self::label($r)]),
            fn() => t('{name} ({ip}) mag weer op het netwerk', ['name' => self::label($r), 'ip' => $ip]) . ($auto ? ' (' . t('de tijd was om') . ')' : ' · ' . t('door {by}', ['by' => $by]))
                . ($errors ? "\n⚠️ " . implode(' · ', $errors) : ''), $ip);
        return ['ok' => !$errors, 'errors' => $errors];
    }

    /** Vanuit cron: verlopen weringen opheffen */
    public static function expireBlocks(): void
    {
        foreach (q("SELECT id, blocked FROM net_devices WHERE blocked IS NOT NULL")->fetchAll() as $r) {
            $b = json_decode((string) $r['blocked'], true) ?: [];
            if (!empty($b['until']) && strtotime((string) $b['until']) <= time()) {
                self::unblock((int) $r['id'], 'RzMon', true);
            }
        }
    }

    /** Opruimen: apparaten die 90 dagen niet gezien zijn (behalve bekende met een eigen naam) */
    public static function purge(): void
    {
        try {
            q("DELETE FROM net_devices WHERE last_seen < NOW() - INTERVAL 90 DAY AND blocked IS NULL AND NOT (status = 'known' AND name IS NOT NULL)");
        } catch (Throwable) {
        }
    }
}
