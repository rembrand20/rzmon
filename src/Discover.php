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
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND (metric LIKE 'disk:%' OR metric LIKE 'container:%' OR metric LIKE 'cert:%' OR metric IN ('svclist', 'ports'))", [$hid]) as $r) {
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
            if ($kind === 'cert' && !isset($have["ssl:$what"])) {
                $add("cert:$what", 'cert', '🔒 ' . $what, t('Certificaat'), ['type' => 'ssl', 'target' => $what, 'name' => t('Certificaat {n}', ['n' => basename($what)]),
                    'warn_threshold' => 21, 'crit_threshold' => 7, 'interval_sec' => 3600]);
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
        $seen = [];
        foreach ((array) json_decode((string) ($data['ports'] ?? '[]'), true) as $p) {
            $port = (int) ($p['port'] ?? 0);
            $addr = (string) ($p['addr'] ?? '');
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
