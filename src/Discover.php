<?php
declare(strict_types=1);

/**
 * Wat er nog niet bewaakt wordt (1.95.0). Uit wat de agent al meldt: disks, services die draaien en automatisch starten,
 * containers, certificaten en poorten die van buiten bereikbaar zijn. Alleen een voorstel: jij voegt toe of negeert
 * (per server, in hosts.discover_ignore). Wat al een check heeft, telt niet mee.
 */
final class Discover
{
    /** Services die op bijna elke server draaien en geen eigen check nodig hebben */
    private const SVC_NOISE = '/^(systemd-|getty@|serial-getty@|user@|dbus|polkit|rsyslog|auditd|irqbalance|kmod|lvm2|multipathd|udisks|accounts-daemon|ModemManager|NetworkManager|snapd|'
        . 'unattended-upgrades|apparmor|ufw|firewalld|tuned|rtkit|wpa_supplicant|cloud-|qemu-guest-agent|open-vm-tools|vgauth|e2scrub|fstrim|plymouth|blk-availability|'
        . 'console-|keyboard-|setvtrgb|dm-event|lxcfs|atd|smartd|mdmonitor|sysstat|mcelog|nfs-|rpcbind|gssproxy|crond?$|chronyd?$|ntp|systemd$|selinux|import-state|nis-domainname|loadmodules)/i';

    /** Poorten waar meestal TLS achter zit (https, mail, LDAPS, DNS-over-TLS, MQTT, WinRM, Kubernetes) */
    private const TLS_PORTS = [443, 8443, 9443, 993, 995, 465, 636, 853, 5986, 8883, 6443];

    private static function ignored(array $host): array
    {
        return array_flip((array) (json_decode((string) ($host['discover_ignore'] ?? ''), true) ?: []));
    }

    /** Voorstellen voor één server: [{key, kind, label, detail, data (voor check_save)}] */
    public static function forHost(array $host): array
    {
        if ($host['mode'] !== 'agent' && $host['mode'] !== 'local') {
            return [];
        }
        $hid = (int) $host['id'];
        $have = [];
        foreach (q('SELECT type, target FROM checks WHERE host_id = ?', [$hid])->fetchAll() as $c) {
            $have[$c['type'] . ':' . $c['target']] = true;
        }
        $ign = self::ignored($host);
        $data = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND (metric LIKE 'disk:%' OR metric LIKE 'container:%' OR metric LIKE 'cert:%' OR metric LIKE 'smart:%' OR metric LIKE 'ups:%' OR metric LIKE 'wg:%' OR metric IN ('svclist', 'ports', 'certfound', 'time'))", [$hid]) as $r) {
            $data[$r['metric']] = $r['value'];
        }
        $out = [];
        $add = static function (string $key, string $kind, string $label, string $detail, array $check) use (&$out, $ign): void {
            if (!isset($ign[$key])) {
                $out[] = ['key' => $key, 'kind' => $kind, 'label' => $label, 'detail' => $detail, 'data' => $check];
            }
        };
        foreach ($data as $metric => $value) {
            [$kind, $what] = explode(':', $metric, 2) + [1 => ''];
            if ($kind === 'disk' && !isset($have["disk:$what"])) {
                $add("disk:$what", 'disk', t('Disk {m}', ['m' => $what]), t('Diskruimte'), ['type' => 'disk', 'target' => $what, 'name' => "Disk $what", 'warn_threshold' => 80, 'crit_threshold' => 90]);
            }
            if ($kind === 'container' && !isset($have["container:$what"])) {
                $add("container:$what", 'container', '📦 ' . $what, t('Container'), ['type' => 'container', 'target' => $what, 'name' => "📦 $what",
                    'res' => ['cpu' => ['on' => false], 'mem' => ['on' => false], 'disk' => ['on' => false]]]);
            }
            // 1.138.0: schijfgezondheid per schijf (niet als er al een check voor alle schijven is), UPS, en de klok
            if ($kind === 'smart' && !isset($have["smart:$what"]) && !isset($have['smart:']) && empty((json_decode((string) $value, true) ?: [])['error'])) {
                $m = (json_decode((string) $value, true) ?: [])['model'] ?? '';
                $add("smart:$what", 'smart', '💽 ' . $what, t('Schijfgezondheid') . ($m !== '' ? " · $m" : ''), ['type' => 'smart', 'target' => $what,
                    'name' => t('Schijf {d}', ['d' => basename($what)]), 'warn_threshold' => 55, 'crit_threshold' => 65, 'interval_sec' => 1800]);
            }
            if ($kind === 'ups' && !isset($have["ups:$what"]) && !isset($have['ups:'])) {
                $add("ups:$what", 'ups', '🔋 ' . $what, 'UPS', ['type' => 'ups', 'target' => $what, 'name' => "UPS $what", 'warn_threshold' => 50, 'crit_threshold' => 20]);
            }
            // 1.170.0 (agent 1.75.0): één VPN-check per WireGuard-interface (de oudste handshake van de peers telt)
            if ($kind === 'wg' && ($iface = explode(':', $what)[0]) !== '' && !isset($have["vpn:$iface"]) && !isset($have['vpn:'])) {
                $have["vpn:$iface"] = true;
                $add("wg:$iface", 'vpn', '🔐 ' . $iface, 'WireGuard', ['type' => 'vpn', 'metric' => 'wg', 'target' => $iface, 'name' => "WireGuard $iface",
                    'warn_threshold' => 300, 'crit_threshold' => 900]);
            }
            if ($metric === 'time' && !isset($have['ntp:'])) {
                $add('ntp', 'ntp', '🕐 ' . t('Klok'), t('Tijd (NTP)'), ['type' => 'ntp', 'target' => '', 'name' => t('Tijd'), 'warn_threshold' => 100, 'crit_threshold' => 1000, 'interval_sec' => 300]);
            }
            if ($kind === 'cert' && !isset($have["ssl:$what"])) {
                $add("cert:$what", 'cert', '🔒 ' . $what, t('Certificaat'), ['type' => 'ssl', 'target' => $what, 'name' => t('Certificaat {n}', ['n' => basename($what)]),
                    'warn_threshold' => 21, 'crit_threshold' => 7, 'interval_sec' => 3600]);
            }
        }
        // Certificaatbestanden die de agent vond (1.98.0); verlopen of zelfgemaakte CA's ook, je kiest zelf
        foreach ((array) json_decode((string) ($data['certfound'] ?? '[]'), true) as $c) {
            $path = (string) ($c['path'] ?? '');
            if ($path === '' || isset($have["ssl:$path"]) || isset($data["cert:$path"])) {
                continue;
            }
            $na = isset($c['not_after']) ? (int) $c['not_after'] : null;
            if ($na && $na < time() - 30 * 86400) {
                continue;   // al lang verlopen: een oud of voorbeeldbestand (in de keuzelijst staat hij nog wel)
            }
            $add("cert:$path", 'cert', '🔒 ' . $path, t('Certificaat') . (($c['cn'] ?? '') !== '' ? ' · ' . $c['cn'] : '') . ($na ? ' · ' . t('geldig tot {d}', ['d' => date('d-m-Y', $na)]) : ''),
                ['type' => 'ssl', 'target' => $path, 'name' => t('Certificaat {n}', ['n' => ($c['cn'] ?? '') ?: basename($path)]), 'warn_threshold' => 21, 'crit_threshold' => 7, 'interval_sec' => 3600]);
        }
        // 1.200.0: databases: een draaiende MariaDB/MySQL- of PostgreSQL-service of -container zonder databasecheck (via de agent)
        $dbHave = [];
        foreach (q("SELECT options FROM checks WHERE host_id = ? AND type = 'mysql'", [$hid]) as $c) {
            $o = Db::opts($c);
            $dbHave[$o['engine'] . ':' . ($o['via'] === 'agent' && $o['where'] === 'container' ? $o['container'] : '')] = true;
        }
        $dbAdd = static function (string $eng, string $ct, string $label) use (&$dbHave, $add, $host): void {
            if (isset($dbHave["$eng:$ct"]) || $host['mode'] !== 'agent') {
                return;
            }
            $dbHave["$eng:$ct"] = true;
            $name = ($eng === 'pgsql' ? 'PostgreSQL' : 'MariaDB') . ($ct !== '' ? ' ' . basename($ct) : '');
            $add("db:$eng:$ct", 'db', $label, ($eng === 'pgsql' ? 'PostgreSQL' : 'MariaDB / MySQL') . ' · ' . t('via de agent, zonder wachtwoord'),
                ['type' => 'mysql', 'metric' => 'overview', 'db_engine' => $eng, 'db_via' => 'agent', 'db_where' => $ct !== '' ? 'container' : 'local', 'db_container' => $ct,
                    'name' => $name, 'warn_threshold' => 500, 'crit_threshold' => 2000, 'interval_sec' => 60]);
        };
        foreach ((array) json_decode((string) ($data['svclist'] ?? '[]'), true) as $s) {
            [$sname, $state] = array_pad((array) $s, 2, null);
            if ($state === 'active' && preg_match('/^(mariadb|mysql|mysqld|postgresql(@[\w.-]+)?)$/', (string) $sname, $mm)) {
                $dbAdd(str_starts_with($mm[1], 'postgresql') ? 'pgsql' : 'mysql', '', '🗄️ ' . $sname);
            }
        }
        foreach ($data as $metric => $value) {
            if (str_starts_with($metric, 'container:')) {
                $cv = json_decode((string) $value, true) ?: [];
                $img = strtolower((string) ($cv['image'] ?? ''));
                if (preg_match('#(^|/)(mariadb|mysql|percona|postgres|postgis|timescale)[^/]*(:|$)#', $img, $mm) && ($cv['state'] ?? 'running') === 'running') {
                    $dbAdd(in_array($mm[2], ['postgres', 'postgis', 'timescale'], true) ? 'pgsql' : 'mysql', substr($metric, 10), '📦 ' . substr($metric, 10) . " ($img)");
                }
            }
        }
        // Services die nu draaien en bij het opstarten starten
        foreach ((array) json_decode((string) ($data['svclist'] ?? '[]'), true) as $s) {
            [$name, $state, $enabled] = array_pad((array) $s, 3, null);
            if ($state === 'active' && $enabled && !preg_match(self::SVC_NOISE, (string) $name) && !isset($have["service:$name"])) {
                $add("service:$name", 'service', (string) $name, t('Service'), ['type' => 'service', 'target' => $name, 'name' => (string) $name, 'deep' => true]);
            }
        }
        // Poorten die van buiten bereikbaar zijn (niet alleen op 127.0.0.1); RzMon kijkt of ze open staan
        $seen = $tls = [];
        foreach ((array) json_decode((string) ($data['ports'] ?? '[]'), true) as $p) {
            $port = (int) ($p['port'] ?? 0);
            $addr = (string) ($p['addr'] ?? '');
            // TLS-poort (ook alleen op 127.0.0.1): het certificaat erachter, gelezen door de agent op de server zelf
            if (in_array($port, self::TLS_PORTS, true) && !isset($tls[$port]) && !isset($have["ssl:localhost:$port"]) && !isset($have["ssl:127.0.0.1:$port"])) {
                $tls[$port] = true;
                $proc = (string) ($p['proc'] ?? '');
                $add("tls:$port", 'cert', '🔒 localhost:' . $port . ($proc !== '' ? " ($proc)" : ''), t('Certificaat achter poort {p}', ['p' => $port]),
                    ['type' => 'ssl', 'target' => "localhost:$port", 'name' => t('Certificaat poort {p}', ['p' => $port]), 'warn_threshold' => 21, 'crit_threshold' => 7,
                        'interval_sec' => 3600, 'cert_from' => 'agent', 'verify_ssl' => false]);
            }
            if ($port < 1 || $port >= 32768 || isset($seen[$port]) || str_starts_with($addr, '127.') || $addr === '::1' || isset($have["tcp:$port"])) {
                continue;
            }
            $seen[$port] = true;
            $proc = (string) ($p['proc'] ?? '');
            $add("port:$port", 'port', t('Poort {p}', ['p' => $port]) . ($proc !== '' ? " ($proc)" : ''), t('Bereikbaar van buiten de server'),
                ['type' => 'tcp', 'target' => (string) $port, 'name' => t('Poort {p}', ['p' => $port]) . ($proc !== '' ? " $proc" : ''), 'warn_threshold' => 500, 'crit_threshold' => 2000]);
        }
        return $out;
    }

    /** Aantal voorstellen per server (voor de knop op het dashboard) */
    public static function counts(): array
    {
        $n = [];
        foreach (q("SELECT * FROM hosts WHERE enabled = 1 AND mode IN ('agent', 'local')")->fetchAll() as $h) {
            $c = count(self::forHost($h));
            if ($c) {
                $n[(int) $h['id']] = $c;
            }
        }
        return $n;
    }

    /** Voorstellen negeren (of weer tonen) */
    public static function ignore(int $hostId, array $keys, bool $on): void
    {
        $host = q('SELECT discover_ignore FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$host) {
            return;
        }
        $list = (array) (json_decode((string) $host['discover_ignore'], true) ?: []);
        $keys = array_map(static fn($k) => mb_substr((string) $k, 0, 200), $keys);
        // Weer tonen zonder sleutels: alles wat genegeerd was
        $list = $on ? array_values(array_unique([...$list, ...$keys])) : ($keys ? array_values(array_diff($list, $keys)) : []);
        q('UPDATE hosts SET discover_ignore = ? WHERE id = ?', [$list ? json_encode(array_slice($list, -1000), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $hostId]);
    }
}
