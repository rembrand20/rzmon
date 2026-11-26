<?php
declare(strict_types=1);

/**
 * 1.328.0: Netwerk → Netwerksnelheid. Eén overzicht van de snelheid van je netwerk en je internetlijn, als keten
 * van de bron naar het doel (in de stijl van de traceroute), per schakel met vertraging, linksnelheid en gemeten doorvoer.
 *
 * - Een **route** (speed_routes) gaat van een bron naar een doel. Bron: RzSense zelf (src_host_id NULL) of een server
 *   met de agent. Doel: internet, een server in RzSense of een adres. Standaard is er één route: RzSense → Internet.
 * - Vanaf een server met de agent (ook als de agent op de RzSense-server zelf draait) loopt de meting via een eigen
 *   pad-check (check_id, alleen informatief): hops met mtr of traceroute, de netwerkkaart, LLDP en iperf3 naar het doel.
 * - Zonder agent op de RzSense-server meet RzSense zelf: de netwerkkaart, de hops (mtr of traceroute als die er zijn,
 *   anders alleen de router en het doel met ping) en, naar een server, iperf3 (als dat op de RzSense-server staat).
 * - SNMP vult de snelheid van switch- en routerpoorten aan: op welke poort de bron zit (de MAC-tabel van de switch),
 *   de poort richting de router en de WAN-poort van de router. UniFi (de pad-check) doet dat al zelf.
 * - Internet: de snelheidstest van de check Internet, vergeleken met je abonnement.
 * - Wifi: elk apparaat met de agent meldt zijn eigen wifi (agent 1.122.0, Windows-agent 0.7.0); met UniFi alle
 *   access points en wifi-apparaten.
 * - Een melding (systeemmelding net_speed) alleen als de route dat mag: de smalste schakel onder de grens, een link die
 *   terugvalt of het doel onbereikbaar.
 */
final class NetSpeed
{
    public const EVERY = [3600, 21600, 86400];
    public const INET_TARGET = '1.1.1.1';
    private const LAT_EVERY = 600;     // RzSense zelf: vertraging per hop elke 10 minuten
    private const SAMPLE_GAP = 540;    // hooguit één meting per 9 minuten bewaren
    private const TP_WINDOW = 150;     // zo lang mag de ontvanger op het doel klaarstaan (s)
    private const SNMP_EVERY = 600;    // SNMP-gegevens van een switch of router: elke 10 minuten
    private const WEAK = -72;          // wifi: zwak signaal vanaf (dBm)
    private const BAD = -80;
    private const OID_IF = '1.3.6.1.2.1.2.2.1';
    private const OID_IFX = '1.3.6.1.2.1.31.1.1.1';
    private const OID_FDB = '1.3.6.1.2.1.17.4.3.1.2';            // dot1dTpFdbPort: MAC → bridgepoort
    private const OID_QFDB = '1.3.6.1.2.1.17.7.1.2.2.1.2';       // dot1qTpFdbPort: VLAN.MAC → bridgepoort
    private const OID_BPIF = '1.3.6.1.2.1.17.1.4.1.2';           // dot1dBasePortIfIndex: bridgepoort → interface

    // ------------------------------------------------------------------ routes

    /** De standaardroute RzSense → Internet, één keer (verwijder je hem, dan komt hij niet terug) */
    public static function seed(): void
    {
        if (setting('speed_seeded', '0') === '1') {
            return;
        }
        if (!(int) q('SELECT COUNT(*) FROM speed_routes')->fetchColumn()) {
            q("INSERT INTO speed_routes (name, dst_kind) VALUES ('', 'internet')");
        }
        save_setting('speed_seeded', '1');
    }

    /** @return list<array<string, mixed>> alle routes, met de namen van bron en doel */
    public static function routes(): array
    {
        self::seed();
        $hosts = self::hosts();
        $out = [];
        foreach (q('SELECT * FROM speed_routes ORDER BY id')->fetchAll() as $r) {
            $out[] = self::describe($r, $hosts);
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function hosts(): array
    {
        $out = [];
        foreach (q('SELECT id, name, address, mode, enabled, iperf_ok FROM hosts')->fetchAll() as $h) {
            $out[(int) $h['id']] = $h;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<int, array<string, mixed>> $hosts
     * @return array<string, mixed>
     */
    private static function describe(array $r, array $hosts): array
    {
        $src = $r['src_host_id'] ? (string) ($hosts[(int) $r['src_host_id']]['name'] ?? '?') : 'RzSense';
        $dst = match ((string) $r['dst_kind']) {
            'host' => (string) ($hosts[(int) $r['dst_host_id']]['name'] ?? '?'),
            'addr' => (string) $r['dst_addr'],
            default => t('Internet'),
        };
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'] !== '' ? (string) $r['name'] : "$src → $dst", 'own_name' => (string) $r['name'],
            'src' => $src, 'dst' => $dst, 'src_host_id' => $r['src_host_id'] ? (int) $r['src_host_id'] : null, 'dst_kind' => (string) $r['dst_kind'],
            'dst_host_id' => $r['dst_host_id'] ? (int) $r['dst_host_id'] : null, 'dst_addr' => (string) $r['dst_addr'], 'every_sec' => (int) $r['every_sec'],
            'tp' => (int) $r['tp'], 'notify' => (int) $r['notify'], 'notify_min' => $r['notify_min'] !== null ? (int) $r['notify_min'] : null,
            'wan_if' => $r['wan_if'], 'enabled' => (int) $r['enabled'], 'check_id' => $r['check_id'] ? (int) $r['check_id'] : null];
    }

    /**
     * Een route opslaan (nieuw of bestaand) en de pad-check erbij bijwerken.
     * @param array<string, mixed> $in
     */
    public static function save(array $in, string $who): int
    {
        self::seed();
        $id = (int) ($in['id'] ?? 0);
        $src = (int) ($in['src_host_id'] ?? 0) ?: null;
        $hosts = self::hosts();
        if ($src !== null && (($hosts[$src]['mode'] ?? '') !== 'agent')) {
            throw new InvalidArgumentException(t('Kies als bron RzSense of een server met de agent'));
        }
        $kind = in_array($in['dst_kind'] ?? '', ['internet', 'host', 'addr'], true) ? (string) $in['dst_kind'] : 'internet';
        $dstHost = null;
        $addr = '';
        if ($kind === 'host') {
            $dstHost = (int) ($in['dst_host_id'] ?? 0);
            if (!isset($hosts[$dstHost])) {
                throw new InvalidArgumentException(t('Kies een server als doel'));
            }
            if ($dstHost === $src) {
                throw new InvalidArgumentException(t('Bron en doel zijn hetzelfde'));
            }
        } elseif ($kind === 'addr') {
            $addr = trim((string) ($in['dst_addr'] ?? ''));
            if (!Paths::okHost($addr)) {
                throw new InvalidArgumentException(t('Vul een geldig IP-adres of een naam in'));
            }
        }
        $every = in_array((int) ($in['every_sec'] ?? 3600), self::EVERY, true) ? (int) $in['every_sec'] : 3600;
        $min = isset($in['notify_min']) && $in['notify_min'] !== '' ? max(1, min(100000, (int) $in['notify_min'])) : null;
        $wan = preg_match('/^\d{1,10}$/', (string) ($in['wan_if'] ?? '')) ? (string) $in['wan_if'] : null;
        $name = mb_substr(trim((string) preg_replace('/[\x00-\x1f<>]/u', '', (string) ($in['name'] ?? ''))), 0, 100);
        $vals = [$name, $src, $kind, $dstHost, $addr, $every, !empty($in['tp']) ? 1 : 0, !empty($in['notify']) ? 1 : 0, $min, $wan, !array_key_exists('enabled', $in) || !empty($in['enabled']) ? 1 : 0];
        if ($id) {
            $old = q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch();
            if (!$old) {
                throw new InvalidArgumentException(t('Niet gevonden'));
            }
            q('UPDATE speed_routes SET name = ?, src_host_id = ?, dst_kind = ?, dst_host_id = ?, dst_addr = ?, every_sec = ?, tp = ?, notify = ?, notify_min = ?, wan_if = ?, enabled = ?' .
                // andere bron of doel: de vorige metingen horen bij een ander pad
                ((int) $old['src_host_id'] !== (int) $src || $old['dst_kind'] !== $kind || (int) $old['dst_host_id'] !== (int) $dstHost || $old['dst_addr'] !== $addr
                    ? ', last_data = NULL, last_tp = NULL, last_run = NULL, last_tp_at = NULL, last_state = NULL' : '') . ' WHERE id = ?', [...$vals, $id]);
        } else {
            if ((int) q('SELECT COUNT(*) FROM speed_routes')->fetchColumn() >= 30) {
                throw new InvalidArgumentException(t('Hooguit 30 routes'));
            }
            q('INSERT INTO speed_routes (name, src_host_id, dst_kind, dst_host_id, dst_addr, every_sec, tp, notify, notify_min, wan_if, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            $id = (int) db()->lastInsertId();
        }
        $r = q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch();
        self::syncCheck($r);
        AgentCommands::audit($who, 'Netwerksnelheid: route opgeslagen', self::describe($r, self::hosts())['name']);
        return $id;
    }

    public static function delete(int $id, string $who): bool
    {
        $r = q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch();
        if (!$r) {
            return false;
        }
        if ($r['check_id']) {
            q("DELETE FROM checks WHERE id = ? AND type = 'path'", [$r['check_id']]);
        }
        q('DELETE FROM speed_routes WHERE id = ?', [$id]);
        AgentCommands::audit($who, 'Netwerksnelheid: route verwijderd', self::describe($r, self::hosts())['name']);
        return true;
    }

    /**
     * Draait de agent op de RzSense-server zelf? Dan meet die (meer gegevens: mtr als root, LLDP, iperf3).
     * @return array<string, mixed>|null
     */
    public static function localAgent(): ?array
    {
        $ips = NetScan::ownIps();
        if (!$ips) {
            return null;
        }
        $in = implode(',', array_fill(0, count($ips), '?'));
        return q("SELECT * FROM hosts WHERE mode = 'agent' AND enabled = 1 AND address IN ($in) ORDER BY id LIMIT 1", $ips)->fetch() ?: null;
    }

    /** @param array<string, mixed> $r */
    private static function target(array $r): string
    {
        return match ((string) $r['dst_kind']) {
            'host' => (string) q('SELECT address FROM hosts WHERE id = ?', [(int) $r['dst_host_id']])->fetchColumn(),
            'addr' => (string) $r['dst_addr'],
            default => self::INET_TARGET,
        };
    }

    /**
     * De pad-check van een route vanaf een server met de agent: aanmaken of bijwerken (alleen informatief; de route meldt zelf).
     * @param array<string, mixed> $r
     */
    public static function syncCheck(array $r): ?int
    {
        $srcId = $r['src_host_id'] ? (int) $r['src_host_id'] : (int) (self::localAgent()['id'] ?? 0);
        $cid = (int) $r['check_id'];
        $target = self::target($r);
        if (!$srcId || !Paths::okHost($target)) {
            if ($cid) {
                q("DELETE FROM checks WHERE id = ? AND type = 'path'", [$cid]);
                q('UPDATE speed_routes SET check_id = NULL WHERE id = ?', [$r['id']]);
            }
            return null;
        }
        $host = (string) $r['dst_kind'] === 'host';
        $opts = json_encode(['tp' => $host && (int) $r['tp'] === 1, 'tp_host' => $host ? (int) $r['dst_host_id'] : 0, 'tp_every' => (int) $r['every_sec'],
            'link' => true, 'hop' => true, 'change' => false, 'speed_route' => (int) $r['id']]);
        $name = mb_substr(t('Netwerksnelheid → {d}', ['d' => self::describe($r, self::hosts())['dst']]), 0, 100);
        if ($cid && q("SELECT 1 FROM checks WHERE id = ? AND host_id = ? AND type = 'path'", [$cid, $srcId])->fetchColumn()) {
            q('UPDATE checks SET target = ?, options = ?, name = ?, info_only = 1, enabled = ? WHERE id = ?', [$target, $opts, $name, (int) $r['enabled'], $cid]);
            return $cid;
        }
        if ($cid) {
            q("DELETE FROM checks WHERE id = ? AND type = 'path'", [$cid]);   // andere bron
        }
        q("INSERT INTO checks (host_id, type, target, name, interval_sec, info_only, enabled, options) VALUES (?, 'path', ?, ?, 300, 1, ?, ?)", [$srcId, $target, $name, (int) $r['enabled'], $opts]);
        $cid = (int) db()->lastInsertId();
        q('UPDATE speed_routes SET check_id = ? WHERE id = ?', [$cid, $r['id']]);
        return $cid;
    }

    // ------------------------------------------------------------------ meten vanaf RzSense zelf

    /** @return array{0: string, 1: string} netwerkkaart en standaardgateway (uit /proc/net/route) */
    private static function defaultRoute(): array
    {
        foreach (array_slice(@file('/proc/net/route', FILE_IGNORE_NEW_LINES) ?: [], 1) as $l) {
            $f = preg_split('/\s+/', trim($l)) ?: [];
            if (($f[1] ?? '') === '00000000' && isset($f[2]) && preg_match('/^[0-9A-Fa-f]{8}$/', $f[2])) {
                $ip = long2ip((int) hexdec(implode('', array_reverse(str_split($f[2], 2)))));
                return [(string) $f[0], $ip !== '0.0.0.0' ? $ip : ''];
            }
        }
        return ['', ''];
    }

    private static function run(string $cmd): string
    {
        $o = [];
        exec(rz_sh('LC_ALL=C ' . $cmd), $o);
        return implode("\n", $o);
    }

    /** Draait RzSense in een container of een VM? ('' = op ijzer of onbekend) */
    private static function virt(): string
    {
        if (is_file('/.dockerenv') || is_file('/run/.containerenv')) {
            return 'container';
        }
        $v = strtolower(trim((string) @file_get_contents('/sys/class/dmi/id/sys_vendor')) . ' ' . trim((string) @file_get_contents('/sys/class/dmi/id/product_name')));
        foreach (['qemu' => 'kvm', 'kvm' => 'kvm', 'vmware' => 'vmware', 'virtualbox' => 'virtualbox', 'xen' => 'xen', 'virtual machine' => 'hyperv', 'proxmox' => 'kvm'] as $k => $n) {
            if (str_contains($v, $k)) {
                return $n;
            }
        }
        return '';
    }

    /**
     * mtr-rapport lezen (zoals de agent): "  1.|-- 192.168.2.1   0.0%   5   0.4   0.5   0.3   0.8   0.1"
     * @return list<array<string, mixed>>
     */
    public static function parseMtr(string $out): array
    {
        $h = [];
        foreach (explode("\n", $out) as $l) {
            $f = preg_split('/\s+/', trim($l)) ?: [];
            if (count($f) < 9 || !preg_match('/^(\d+)\.\|--$/', $f[0], $m)) {
                continue;
            }
            $ip = filter_var($f[1], FILTER_VALIDATE_IP) ? $f[1] : '';
            $n = static fn(string $v): ?float => $ip !== '' && is_numeric($v) ? round((float) $v, 2) : null;
            $h[] = ['n' => (int) $m[1], 'ip' => $ip, 'loss' => (float) rtrim($f[2], '%'), 'avg' => $n($f[5]), 'best' => $n($f[6]), 'worst' => $n($f[7]), 'jit' => $n($f[8])];
        }
        return array_slice($h, 0, 40);
    }

    /**
     * traceroute -n lezen: " 1  192.168.2.1  0.512 ms  0.433 ms  0.401 ms" of " 2  * * *"
     * @return list<array<string, mixed>>
     */
    public static function parseTraceroute(string $out): array
    {
        $h = [];
        foreach (explode("\n", $out) as $l) {
            if (!preg_match('/^\s*(\d+)\s+(.*)$/', $l, $m)) {
                continue;
            }
            $tok = preg_split('/\s+/', trim($m[2])) ?: [];
            $times = [];
            $ip = '';
            foreach ($tok as $i => $t) {
                if (($tok[$i + 1] ?? '') === 'ms' && is_numeric($t)) {
                    $times[] = (float) $t;
                } elseif ($ip === '' && filter_var($t, FILTER_VALIDATE_IP)) {
                    $ip = $t;
                }
            }
            $stars = substr_count($m[2], '*');
            $n = count($times) + $stars;
            $h[] = ['n' => (int) $m[1], 'ip' => $ip, 'loss' => $n ? round($stars * 100 / $n, 1) : 100.0, 'avg' => $times ? round(array_sum($times) / count($times), 2) : null,
                'best' => $times ? min($times) : null, 'worst' => $times ? max($times) : null, 'jit' => null];
        }
        return array_slice($h, 0, 40);
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} hulpmiddel en hops */
    private static function trace(string $t): array
    {
        if (!Paths::okHost($t)) {
            return ['', []];
        }
        foreach (['/usr/sbin/mtr', '/usr/bin/mtr', '/usr/local/sbin/mtr'] as $b) {
            if (is_executable($b) && ($h = self::parseMtr(self::run(sprintf('timeout 30 %s -r -w -n -c 5 %s 2>/dev/null', $b, escapeshellarg($t)))))) {
                return ['mtr', $h];
            }
        }
        foreach (['/usr/bin/traceroute', '/usr/sbin/traceroute', '/bin/traceroute'] as $b) {
            if (is_executable($b) && ($h = self::parseTraceroute(self::run(sprintf('timeout 40 %s -n -q 3 -w 1 -m 20 %s 2>/dev/null', $b, escapeshellarg($t)))))) {
                return ['traceroute', $h];
            }
        }
        return ['', []];
    }

    /**
     * @param array<string, mixed> $p uitslag van Internet::parsePing
     * @return array<string, mixed>
     */
    private static function pingRow(string $ip, array $p): array
    {
        $t = (array) ($p['times'] ?? []);
        return ['ip' => $ip, 'loss' => (float) $p['loss'], 'avg' => $p['avg'] !== null ? round((float) $p['avg'], 2) : null, 'best' => $t ? min($t) : null,
            'worst' => $t ? max($t) : null, 'jit' => isset($p['jitter']) ? round((float) $p['jitter'], 2) : null];
    }

    /**
     * Het pad meten vanaf RzSense zelf (geen agent op deze server) en bewaren bij de route
     * @param array<string, mixed> $r
     */
    public static function measureLocal(array $r): void
    {
        $target = self::target($r);
        if (!Paths::okHost($target)) {
            return;
        }
        [$dev, $gw] = self::defaultRoute();
        $dev = basename($dev);
        $src = '';
        if ($dev !== '' && preg_match('/\binet\s+(\d+\.\d+\.\d+\.\d+)\//', self::run('ip -o -4 addr show dev ' . escapeshellarg($dev) . ' 2>/dev/null'), $m)) {
            $src = $m[1];
        }
        $virt = self::virt();
        $speed = $dev !== '' && $virt !== 'container' ? (int) trim((string) @file_get_contents("/sys/class/net/$dev/speed")) : 0;   // in een container: de veth, niet de echte kaart
        [$tool, $hops] = self::trace($target);
        $ping = Internet::pingMany(array_values(array_unique(array_filter([$target, $hops ? '' : $gw]))));
        if (!$hops && $gw !== '' && isset($ping[$gw])) {
            $hops = [['n' => 1] + self::pingRow($gw, $ping[$gw])];   // zonder mtr of traceroute: de router als enige hop
        }
        $d = ['at' => time(), 'tool' => $tool, 'dst' => $target, 'dev' => $dev, 'src' => $src, 'gw' => $gw, 'speed' => $speed > 0 ? $speed : null, 'virt' => $virt,
            'hops' => $hops, 'via' => [], 'ping' => isset($ping[$target]) ? self::pingRow($target, $ping[$target]) : null, 'lldp' => null,
            'mac' => $dev !== '' ? NetScan::normMac((string) @file_get_contents("/sys/class/net/$dev/address")) : null];
        $old = json_decode((string) $r['last_data'], true) ?: [];
        $max = (array) ($old['max'] ?? []);
        $max['nic'] = max((int) ($max['nic'] ?? 0), $speed);
        q('UPDATE speed_routes SET last_data = ?, last_run = NOW() WHERE id = ?', [json_encode(['d' => $d, 'max' => $max], JSON_UNESCAPED_SLASHES), $r['id']]);
    }

    private static function iperfBin(): ?string
    {
        foreach (['/usr/bin/iperf3', '/usr/local/bin/iperf3', '/bin/iperf3'] as $b) {
            if (is_executable($b)) {
                return $b;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $tp */
    private static function setTp(int $routeId, array $tp): void
    {
        q('UPDATE speed_routes SET last_tp = ?, last_tp_at = NOW() WHERE id = ?', [json_encode(['at' => time()] + $tp, JSON_UNESCAPED_UNICODE), $routeId]);
    }

    /**
     * Doorvoer vanaf RzSense zelf naar een server met de agent: de agent daar start kort een iperf3-ontvanger (zoals bij de
     * pad-check), daarna meet RzSense 3 seconden. Verloopt over een paar rondes van cron.
     * @param array<string, mixed> $r
     */
    private static function localTp(array $r, bool $force = false): void
    {
        if ((string) $r['dst_kind'] !== 'host' || !(int) $r['tp'] || !(int) $r['dst_host_id']) {
            return;
        }
        $hid = (int) $r['dst_host_id'];
        $key = 'speedreq:' . (int) $r['id'];
        $h = q('SELECT * FROM hosts WHERE id = ?', [$hid])->fetch();
        if (!$h) {
            return;
        }
        $req = AgentData::get($hid, $key);
        if ($req) {
            if ((int) $req['until'] < time()) {
                AgentData::put($hid, $key, null);
                self::setTp((int) $r['id'], ['to' => $req['ip'], 'err' => t('de ontvanger op {h} startte niet op tijd (draait de agent daar, met iperf3?)', ['h' => $h['name']])]);
                return;
            }
            $srv = AgentData::get($hid, 'iperfs');
            if ($srv && (int) ($srv['started'] ?? 0) >= (int) $req['at'] - 5 && ($bin = self::iperfBin())) {
                AgentData::put($hid, $key, null);
                $out = self::run(sprintf('timeout 15 %s -c %s -p %d -t 3 -J --connect-timeout 3000 2>/dev/null', $bin, escapeshellarg((string) $req['ip']), Paths::port()));
                $j = json_decode($out, true);
                $bps = $j['end']['sum_received']['bits_per_second'] ?? null;
                self::setTp((int) $r['id'], is_numeric($bps) ? ['to' => $req['ip'], 'mbit' => round((float) $bps / 1e6, 1)]
                    : ['to' => $req['ip'], 'err' => mb_substr((string) ($j['error'] ?? t('geen uitkomst van iperf3')), 0, 160)]);
            }
            return;
        }
        $due = $force || !$r['last_tp_at'] || strtotime((string) $r['last_tp_at']) < time() - (int) $r['every_sec'] + 30;
        if (!$due) {
            return;
        }
        if (!self::iperfBin()) {
            self::setTp((int) $r['id'], ['err' => t('iperf3 ontbreekt op de RzSense-server (Rocky: sudo dnf install iperf3; Debian/Ubuntu: sudo apt install iperf3)')]);
            return;
        }
        if ($h['mode'] !== 'agent' || !(int) $h['iperf_ok']) {
            self::setTp((int) $r['id'], ['err' => t('Zet bij {h} "Mag doorvoertest ontvangen" aan (formulier van de server)', ['h' => $h['name']])]);
            return;
        }
        if (Paths::hasIperf($hid) === false) {
            self::setTp((int) $r['id'], ['err' => Paths::ensureIperf($hid) ? t('iperf3 wordt geïnstalleerd op {h} (de ontvanger); de doorvoertest volgt daarna vanzelf', ['h' => $h['name']])
                : t('iperf3 ontbreekt op {h}', ['h' => $h['name']])]);
            return;
        }
        AgentData::put($hid, $key, ['key' => bin2hex(random_bytes(8)), 'at' => time(), 'until' => time() + self::TP_WINDOW, 'host' => $hid, 'ip' => (string) $h['address'], 'name' => (string) $h['name']]);
        q('UPDATE speed_routes SET last_tp_at = NOW() WHERE id = ?', [$r['id']]);
    }

    // ------------------------------------------------------------------ SNMP

    /** Een MAC uit de index van de MAC-tabel ("0.17.50.1.2.3") */
    public static function idxMac(string $idx): ?string
    {
        $p = explode('.', $idx);
        if (count($p) < 6) {
            return null;
        }
        $p = array_slice($p, -6);
        foreach ($p as $x) {
            if (!ctype_digit($x) || (int) $x > 255) {
                return null;
            }
        }
        return NetScan::normMac(implode(':', array_map(static fn($x) => sprintf('%02x', (int) $x), $p)));
    }

    /**
     * Switches en routers met SNMP in RzSense (die op een pad kunnen liggen)
     * @return list<array<string, mixed>>
     */
    private static function snmpHosts(): array
    {
        $gw = self::defaultRoute()[1];
        $out = [];
        foreach (q("SELECT * FROM hosts WHERE enabled = 1 AND snmp IS NOT NULL AND snmp <> ''")->fetchAll() as $h) {
            if (SnmpMonitor::config($h['snmp']) && (in_array($h['kind'] ?? '', ['router', 'switch', 'ap', 'firewall'], true) || $h['address'] === $gw
                || q("SELECT 1 FROM speed_routes WHERE wan_if IS NOT NULL LIMIT 1")->fetchColumn())) {
                $out[] = $h;
            }
        }
        return $out;
    }

    /**
     * De interfaces en de MAC-tabel van een switch of router via SNMP bewaren (alleen vanuit cron: kan seconden duren)
     * @param array<string, mixed> $h
     */
    public static function snmpRefresh(array $h): void
    {
        $old = AgentData::get((int) $h['id'], 'speed_snmp') ?: [];
        try {
            $c = SnmpMonitor::open($h);
            if (!$c) {
                return;
            }
            $descr = $c->walk(self::OID_IF . '.2');
            $name = $c->walk(self::OID_IFX . '.1');
            $alias = $c->walk(self::OID_IFX . '.18');
            $high = $c->walk(self::OID_IFX . '.15');
            $type = $c->walk(self::OID_IF . '.3');
            $oper = $c->walk(self::OID_IF . '.8');
            $ie = $c->walk(self::OID_IF . '.14');
            $oe = $c->walk(self::OID_IF . '.20');
            $ifs = [];
            foreach ($descr + $name as $i => $_) {
                if ((int) ($type[$i] ?? 0) === 24 || count($ifs) >= 200) {
                    continue;   // loopback
                }
                $ifs[(string) $i] = ['n' => mb_substr((string) (($name[$i] ?? '') !== '' ? $name[$i] : ($descr[$i] ?? $i)), 0, 60), 'a' => mb_substr((string) ($alias[$i] ?? ''), 0, 60),
                    's' => (int) ($high[$i] ?? 0), 'up' => (int) ($oper[$i] ?? 2) === 1, 'e' => (int) ($ie[$i] ?? 0) + (int) ($oe[$i] ?? 0)];
            }
            $bp = $c->walk(self::OID_BPIF);
            $fdb = [];
            foreach ($c->walk(self::OID_FDB) as $idx => $port) {
                if (($mac = self::idxMac((string) $idx)) !== null) {
                    $fdb[$mac] = (string) ($bp[$port] ?? $port);
                }
            }
            if (!$fdb) {
                foreach ($c->walk(self::OID_QFDB) as $idx => $port) {
                    if (($mac = self::idxMac((string) $idx)) !== null) {
                        $fdb[$mac] = (string) ($bp[$port] ?? $port);
                    }
                }
            }
            $max = (array) ($old['max'] ?? []);
            foreach ($ifs as $i => $x) {
                if ($x['up'] && $x['s'] > 0) {
                    $max[$i] = max((int) ($max[$i] ?? 0), $x['s']);
                }
            }
            AgentData::put((int) $h['id'], 'speed_snmp', ['at' => time(), 'if' => $ifs, 'fdb' => array_slice($fdb, 0, 4000, true), 'max' => $max, 'err' => '']);
        } catch (Throwable $e) {
            AgentData::put((int) $h['id'], 'speed_snmp', ['at' => time(), 'err' => mb_substr($e->getMessage(), 0, 200)] + $old);
        }
    }

    /**
     * Bewaarde SNMP-gegevens per IP-adres
     * @return array<string, array<string, mixed>>
     */
    private static function snmpByIp(): array
    {
        $out = [];
        foreach (q("SELECT h.id, h.name, h.address, h.kind, a.value FROM agent_data a JOIN hosts h ON h.id = a.host_id WHERE a.metric = 'speed_snmp'")->fetchAll() as $r) {
            $d = json_decode((string) $r['value'], true);
            if (is_array($d) && !empty($d['if'])) {
                $out[(string) $r['address']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'd' => $d];
            }
        }
        return $out;
    }

    /**
     * De WAN-poort van een router: gekozen bij de route, anders geraden uit de naam
     * @param array<string, mixed> $d
     */
    private static function wanIf(array $d, ?string $pick): ?string
    {
        if ($pick !== null && isset($d['if'][$pick])) {
            return $pick;
        }
        foreach ((array) ($d['if'] ?? []) as $i => $x) {
            if (!empty($x['up']) && preg_match('/\b(wan|ppp|pppoe|dsl|vdsl|internet|fiber|glasvezel|ont)\d*\b/i', $x['n'] . ' ' . $x['a'])) {
                return (string) $i;
            }
        }
        return null;
    }

    private static function macOf(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }
        $m = q('SELECT mac FROM net_devices WHERE ip = ? AND mac IS NOT NULL ORDER BY last_seen DESC LIMIT 1', [$ip])->fetchColumn();
        return $m ? (string) $m : null;
    }

    // ------------------------------------------------------------------ de keten

    /**
     * Het padmodel van een route (Paths::model), van de pad-check of van de eigen meting van RzSense.
     * @param array<string, mixed> $r
     * @return array<string, mixed>|null
     */
    private static function pathModel(array $r): ?array
    {
        $cid = (int) $r['check_id'];
        if ($cid) {
            $c = q("SELECT * FROM checks WHERE id = ? AND type = 'path'", [$cid])->fetch();
            $h = $c ? q('SELECT * FROM hosts WHERE id = ?', [$c['host_id']])->fetch() : null;
            if ($c && $h) {
                $m = Paths::model($c, $h);
                return $m ? $m + ['mac' => self::macOf((string) ($m['src'] ?: $h['address']))] : null;
            }
        }
        if ($r['src_host_id']) {
            return null;
        }
        $x = json_decode((string) $r['last_data'], true);
        if (!is_array($x) || empty($x['d'])) {
            return null;
        }
        $host = (string) $r['dst_kind'] === 'host';
        $check = ['id' => 0, 'warn_threshold' => null, 'crit_threshold' => null,
            'options' => json_encode(['tp' => $host && (int) $r['tp'] === 1, 'link' => true, 'hop' => true])];
        $m = Paths::model($check, ['id' => 0, 'name' => 'RzSense', 'address' => (string) ($x['d']['src'] ?? ''), 'parent_id' => null],
            ['d' => $x['d'], 'tp' => json_decode((string) $r['last_tp'], true) ?: null, 'max' => (array) ($x['max'] ?? [])]);
        if ($m && ($m['max'] ?? null) && $m['max'] != ($x['max'] ?? [])) {
            $x['max'] = $m['max'];
            q('UPDATE speed_routes SET last_data = ? WHERE id = ?', [json_encode($x, JSON_UNESCAPED_SLASHES), $r['id']]);
        }
        return $m ? $m + ['mac' => $x['d']['mac'] ?? null] : null;
    }

    /**
     * De keten van een route: knooppunten en de lijnen ertussen, met per lijn vertraging, linksnelheid (met bron), gemeten
     * doorvoer, fouten en een status; daarnaast de tegels, de smalste schakel en wat er mis is.
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $m
     * @return array<string, mixed>
     */
    public static function chain(array $r, ?array $m = null): array
    {
        $m ??= self::pathModel($r);
        $inet = (string) $r['dst_kind'] === 'internet';
        $last = Internet::last();
        [$warnPct] = Internet::pct();
        $planD = Internet::plan('down');
        $planU = Internet::plan('up');
        $hints = [];
        $probs = [];
        if (!$m) {
            return ['nodes' => [], 'links' => [], 'at' => null, 'tool' => '', 'hints' => [self::waitHint($r)], 'problems' => [], 'tiles' => self::tiles($r, null, $last, $planD, $planU, null), 'verdict' => null];
        }
        $snmp = self::snmpByIp();
        $gw = (string) ($m['gw'] ?? '');
        $srcMac = $m['mac'] ?? null;
        // De poort "naar buiten" van een switch: richting het doel als dat in je netwerk zit, anders richting de router
        $dstIp = (string) (end($m['nodes'])['ip'] ?? '');
        $gwMac = (!$inet && Paths::isPrivate($dstIp) ? self::macOf($dstIp) : null) ?? self::macOf($gw);
        $nodes = [];
        $links = [];
        $carry = null;   // [Mbit, bron, fouten, status] voor de volgende lijn
        $link = static function (array $l) use (&$links): void {
            $links[] = $l + ['ms' => null, 'loss' => null, 'spd' => null, 'spd_src' => '', 'err' => null, 'st' => 'g', 'note' => '', 'tp' => null, 'down' => null, 'up' => null];
        };
        // Switches met SNMP waar de bron in de MAC-tabel staat (een switch zie je niet in een traceroute)
        $autoSw = [];
        $seen = array_filter(array_map(static fn($n) => (string) ($n['ip'] ?? ''), (array) $m['nodes']));
        if ($srcMac) {
            foreach ($snmp as $ip => $s) {
                if (in_array($ip, $seen, true) || $ip === $gw || !isset($s['d']['fdb'][$srcMac])) {
                    continue;
                }
                $in = (string) $s['d']['fdb'][$srcMac];
                $out = $gwMac && isset($s['d']['fdb'][$gwMac]) ? (string) $s['d']['fdb'][$gwMac] : null;
                if ($out === $in) {
                    continue;   // bron en router achter dezelfde poort: deze switch ligt er niet tussen
                }
                $cnt = array_count_values(array_map('strval', (array) $s['d']['fdb']));
                if ($out === null && $cnt) {
                    arsort($cnt);
                    $out = (string) array_key_first($cnt);   // de poort met de meeste MAC-adressen = de uplink
                    $out = $out === $in ? null : $out;
                }
                $autoSw[] = ['ip' => $ip, 's' => $s, 'in' => $in, 'out' => $out, 'edge' => $cnt[$in] ?? 1];
            }
            usort($autoSw, static fn($a, $b) => $a['edge'] <=> $b['edge']);   // de switch met de minste apparaten op die poort zit het dichtst bij
        }
        $port = static function (array $s, ?string $i): ?array {
            if ($i === null || !isset($s['d']['if'][$i])) {
                return null;
            }
            $x = $s['d']['if'][$i];
            $fall = ($s['d']['max'][$i] ?? 0) > $x['s'] && $x['s'] > 0;
            return ['name' => $x['n'], 's' => $x['s'] ?: null, 'e' => $x['e'], 'fall' => $fall, 'max' => (int) ($s['d']['max'][$i] ?? 0)];
        };
        $snmpSwitch = function (string $ip, array $s, string $in, ?string $out) use (&$nodes, &$carry, $link, $port, &$probs): void {
            $pi = $port($s, $in);
            $po = $port($s, $out);
            $spd = $pi['s'] ?? null;
            if ($carry && $carry[0] && (!$spd || $carry[0] < $spd)) {
                $spd = $carry[0];
            }
            $fall = $pi && $pi['fall'];
            if ($fall) {
                $probs[] = ['fall:' . $ip . ':' . $in, t('poort {p} van {s} staat op {a}, was {b}', ['p' => $pi['name'], 's' => $s['name'], 'a' => Paths::speed((int) $pi['s']), 'b' => Paths::speed($pi['max'])])];
            }
            $link(['spd' => $spd, 'spd_src' => $pi ? 'snmp' : ($carry[1] ?? ''), 'err' => $pi['e'] ?? null, 'st' => $fall ? 'w' : 'g',
                'note' => $pi ? t('poort {p}', ['p' => $pi['name']]) : '']);
            $nodes[] = ['k' => 'switch', 'name' => $s['name'], 'ip' => $ip, 'sub' => $pi ? t('poort {a}', ['a' => $pi['name']]) . ($po ? ' → ' . $po['name'] : '') : '', 'st' => $fall ? 'w' : 'g', 'src' => 'snmp'];
            $carry = $po ? [$po['s'], 'snmp', $po['e'], $po['fall'] ? 'w' : 'g', $po['fall'] ? 'fall:' . $ip . ':' . $out : null, $po] : null;
            if ($po && $po['fall']) {
                $probs[] = ['fall:' . $ip . ':' . $out, t('uplink van {s} (poort {p}) staat op {a}, was {b}', ['p' => $po['name'], 's' => $s['name'], 'a' => Paths::speed((int) $po['s']), 'b' => Paths::speed($po['max'])])];
            }
        };
        $autoDone = false;
        $isp = false;
        $carryLink = static function (array $extra) use (&$carry, $link): void {
            $link($extra + ['spd' => $carry[0] ?? null, 'spd_src' => $carry[1] ?? '', 'err' => $carry[2] ?? null, 'st' => $carry[3] ?? 'g']);
            $carry = null;
        };
        foreach ((array) $m['nodes'] as $n) {
            $k = (string) $n['kind'];
            if (!$autoDone && !in_array($k, ['src', 'virt', 'switch'], true)) {
                foreach ($autoSw as $a) {
                    $snmpSwitch($a['ip'], $a['s'], $a['in'], $a['out']);
                }
                $autoDone = true;
            }
            switch ($k) {
                case 'src':
                    $nodes[] = ['k' => 'src', 'name' => (string) $n['name'], 'ip' => (string) $n['ip'], 'sub' => trim(($n['dev'] ?? '') . (!empty($n['speed']) ? ' · ' . Paths::speed((int) $n['speed']) : '')), 'st' => $n['st']];
                    $carry = !empty($n['speed']) ? [(int) $n['speed'], 'agent', null, $n['st'] === 'w' ? 'w' : 'g'] : null;
                    break;
                case 'virt':
                    $link(['note' => t('virtueel')]);
                    $nodes[] = ['k' => 'virt', 'name' => (string) $n['name'], 'ip' => '', 'sub' => (string) $n['ip'], 'st' => 'g'];
                    break;
                case 'switch':   // UniFi of LLDP (uit de pad-check)
                    $spd = !empty($n['speed']) ? (int) $n['speed'] : null;
                    if ($carry && $carry[0] && (!$spd || $carry[0] < $spd)) {
                        $spd = $carry[0];
                    }
                    $link(['spd' => $spd, 'spd_src' => ($n['via'] ?? '') === 'unifi' && !empty($n['speed']) ? 'unifi' : ($carry[1] ?? ''), 'err' => $n['errors'] ?? null, 'st' => $n['st'] === 'w' ? 'w' : 'g',
                        'note' => !empty($n['port']) ? t('poort {p}', ['p' => $n['port']]) : '']);
                    $nodes[] = ['k' => 'switch', 'name' => (string) $n['name'], 'ip' => (string) $n['ip'], 'sub' => !empty($n['port']) ? t('poort {a}', ['a' => $n['port']]) . (!empty($n['uplink_port']) ? ' → ' . $n['uplink_port'] : '') : '',
                        'st' => $n['st'], 'src' => (string) ($n['via'] ?? '')];
                    $carry = !empty($n['uplink_speed']) ? [(int) $n['uplink_speed'], 'unifi', $n['uplink_errors'] ?? null, 'g'] : null;
                    break;
                case 'via':   // zelf opgegeven tussenliggend apparaat
                    if (isset($snmp[$n['ip']]) && $srcMac && isset($snmp[$n['ip']]['d']['fdb'][$srcMac])) {
                        $s = $snmp[$n['ip']];
                        $snmpSwitch((string) $n['ip'], $s, (string) $s['d']['fdb'][$srcMac], $gwMac ? ($s['d']['fdb'][$gwMac] ?? null) : null);
                        $links[count($links) - 1]['ms'] = $n['avg'];
                        $links[count($links) - 1]['loss'] = $n['loss'];
                        break;
                    }
                    $carryLink(['ms' => $n['avg'], 'loss' => $n['loss']]);
                    $nodes[] = ['k' => 'switch', 'name' => (string) $n['name'], 'ip' => (string) $n['ip'], 'sub' => '', 'st' => $n['st']];
                    break;
                case 'router':
                case 'hop':
                    $s = $snmp[$n['ip']] ?? null;
                    $lan = $s && $srcMac && isset($s['d']['fdb'][$srcMac]) ? $port($s, (string) $s['d']['fdb'][$srcMac]) : null;
                    $extra = ['ms' => $n['avg'], 'loss' => $n['loss']];
                    if ($lan && $lan['s'] && (!$carry || !$carry[0] || $lan['s'] < $carry[0])) {
                        $extra += ['spd' => $lan['s'], 'spd_src' => 'snmp', 'err' => $lan['e']];
                    }
                    $carryLink($extra);
                    $wan = null;
                    if ($k === 'router' && $s) {
                        $wi = self::wanIf($s['d'], $r['wan_if'] !== null ? (string) $r['wan_if'] : null);
                        $wan = $port($s, $wi);
                        if ($wan && $wan['fall']) {
                            $probs[] = ['fall:wan', t('WAN-poort van {s} staat op {a}, was {b}', ['s' => $s['name'], 'a' => Paths::speed((int) $wan['s']), 'b' => Paths::speed($wan['max'])])];
                        }
                    }
                    $nodes[] = ['k' => $k === 'router' ? 'router' : 'hop', 'name' => (string) $n['name'] === (string) $n['ip'] && $k === 'router' ? t('Router') : (string) $n['name'], 'ip' => (string) $n['ip'],
                        'sub' => $wan ? t('WAN {p}', ['p' => $wan['name']]) . ($wan['s'] ? ' · ' . Paths::speed((int) $wan['s']) : '') : '', 'st' => $n['st'], 'src' => $s ? 'snmp' : ''];
                    if ($k === 'router') {
                        $carry = $wan && $wan['s'] ? [$wan['s'], 'snmp', $wan['e'], $wan['fall'] ? 'w' : 'g'] : null;
                        if ($inet && !$wan) {
                            $hints[] = $s ? t('Welke poort van de router de WAN-poort is, weet RzSense niet: kies hem bij Routes beheren.')
                                : t('De snelheid van je internetaansluiting (de WAN-poort) ziet RzSense als je router SNMP aan heeft en in RzSense staat.');
                        }
                    }
                    break;
                case 'ext':
                case 'hidden':
                    if ($isp) {
                        break;   // de hops van de provider samen als één knooppunt
                    }
                    $isp = true;
                    $carryLink(['ms' => $n['avg'], 'loss' => $n['loss']]);
                    $nodes[] = ['k' => 'isp', 'name' => t('Provider'), 'ip' => (string) $n['ip'], 'sub' => isset($n['n']) ? t('hop {n}', ['n' => $n['n']]) : '', 'st' => 'g'];
                    break;
                case 'dst':
                    $l = ['ms' => $n['avg'], 'loss' => $n['loss'], 'st' => in_array($n['st'], ['w', 'c'], true) ? 'w' : 'g'];
                    if ($inet) {
                        $dn = $last && $last['down'] !== null && !$last['err'] ? (float) $last['down'] : null;
                        $upv = $last && $last['up'] !== null && !$last['err'] ? (float) $last['up'] : null;
                        $l += ['down' => $dn !== null ? round($dn) : null, 'up' => $upv !== null ? round($upv) : null, 'spd_src' => 'test'];
                        if (($dn !== null && $dn < $planD * $warnPct / 100) || ($upv !== null && $upv < $planU * $warnPct / 100)) {
                            $l['st'] = 'w';
                        }
                        if ($carry && $carry[0]) {
                            $l['wan'] = $carry[0];
                        }
                        $carry = null;
                        $nodes[] = ['k' => 'net', 'name' => t('Internet'), 'ip' => $last && $last['colo'] ? 'Cloudflare ' . $last['colo'] : (string) $n['ip'], 'sub' => '', 'st' => $l['st']];
                    } else {
                        $l += ['spd' => $carry[0] ?? null, 'spd_src' => $carry[1] ?? '', 'err' => $carry[2] ?? null];
                        $carry = null;
                        $nodes[] = ['k' => 'dst', 'name' => (string) $n['name'], 'ip' => (string) $n['ip'], 'sub' => '', 'st' => $n['st']];
                    }
                    $link($l);
                    if ($n['st'] === 'c') {
                        $probs[] = ['unreach', $n['loss'] === null || $n['loss'] >= 100 ? t('{d} is onbereikbaar', ['d' => $n['name']]) : t('{d}: {l}% verlies', ['d' => $n['name'], 'l' => (int) $n['loss']])];
                    }
                    break;
            }
        }
        // Wat de pad-check al zag zakken (UniFi-poorten, de netwerkkaart)
        foreach ((array) ($m['slow'] ?? []) as $i => $s) {
            $probs[] = ['fall:path:' . $i, (string) $s];
        }
        // Gemeten doorvoer (iperf3) op de laatste lijn naar een server
        $tp = $m['tp'] ?? null;
        if (!$inet && $links && $tp && isset($tp['mbit'])) {
            $links[count($links) - 1]['tp'] = (float) $tp['mbit'];
        }
        foreach ($links as $i => $l) {
            if (($l['spd'] ?? null) === null && ($l['down'] ?? null) === null && ($l['tp'] ?? null) === null && ($l['note'] ?? '') !== t('virtueel')) {
                $links[$i]['u'] = true;   // snelheid onbekend
            }
        }
        $tiles = self::tiles($r, $m, $last, $planD, $planU, $tp);
        // De smalste schakel
        $cand = [];
        foreach ($links as $i => $l) {
            if ($l['spd']) {
                $cand[] = [(float) $l['spd'], t('de lijn {a} → {b}', ['a' => $nodes[$i]['name'] ?? '?', 'b' => $nodes[$i + 1]['name'] ?? '?']), 'link', $i];
            }
            if ($l['tp']) {
                $cand[] = [(float) $l['tp'], t('de gemeten doorvoer naar {d}', ['d' => $nodes[$i + 1]['name'] ?? '?']), 'tp', $i];
            }
            if ($l['down']) {
                $cand[] = [(float) $l['down'], t('de download van je internetlijn'), 'down', $i];
            }
            if ($l['up']) {
                $cand[] = [(float) $l['up'], t('de upload van je internetlijn'), 'up', $i];
            }
            if (!empty($l['wan'])) {
                $cand[] = [(float) $l['wan'], t('de WAN-poort van je router'), 'link', $i];
            }
        }
        $verdict = null;
        if ($cand) {
            usort($cand, static fn($a, $b) => $a[0] <=> $b[0]);
            [$v, $what, $kind, $li] = $cand[0];
            $links[$li]['narrow'] = $kind;
            $val = I18n::dec((string) round($v)) . ' Mbit/s';
            if ($kind === 'down' || $kind === 'up') {
                $plan = $kind === 'down' ? $planD : $planU;
                $pct = (int) round($v / $plan * 100);
                $ok = $pct >= $warnPct;
                $verdict = ['st' => $ok ? 'i' : 'w', 'text' => t('Smalste schakel: {w} ({v}, {p}% van je abonnement van {a} Mbit/s).', ['w' => $what, 'v' => $val, 'p' => $pct, 'a' => (int) $plan])
                    . ' ' . ($ok ? t('Dat is wat je abonnement geeft; in je eigen netwerk zit het niet.') : t('Dat is minder dan je mag verwachten: kijk of het ook met een kabel zo is, en meld het anders bij je provider.'))];
                if (!$ok) {
                    $probs[] = ['inet_' . $kind, t('{w}: {v}, maar {p}% van je abonnement', ['w' => $kind === 'down' ? t('download') : t('upload'), 'v' => $val, 'p' => $pct])];
                }
            } else {
                $verdict = ['st' => 'i', 'text' => t('Smalste schakel: {w} ({v}).', ['w' => $what, 'v' => $val])];
            }
            if ($r['notify_min'] !== null && $v < (int) $r['notify_min']) {
                $probs[] = ['min', t('smalste schakel {v}, onder je grens van {n} Mbit/s', ['v' => $val, 'n' => (int) $r['notify_min']])];
            }
        }
        if ($probs && (!$verdict || $verdict['st'] !== 'w')) {
            $verdict = ['st' => 'w', 'text' => ($verdict ? $verdict['text'] . ' ' : '') . implode(' · ', array_map(static fn($p) => $p[1], $probs))];
        }
        if ($inet && !$last) {
            $hints[] = t('Nog geen snelheidstest van je internetlijn: klik op Nu meten, of voeg de check Internet toe (Instellingen → Netwerk → Internetcheck).');
        }
        if (($m['tool'] ?? '') === '' && !$r['check_id']) {
            $hints[] = t('Op de RzSense-server staan mtr en traceroute niet: je ziet alleen de router en het doel. Installeer mtr (Rocky: sudo dnf install mtr) voor elke hop.');
        }
        if ($tp && !empty($tp['err'])) {
            $hints[] = t('Doorvoertest: {e}', ['e' => $tp['err']]);
        }
        return ['nodes' => $nodes, 'links' => $links, 'at' => (int) ($m['at'] ?? 0), 'tool' => (string) ($m['tool'] ?? ''), 'hints' => array_values(array_unique($hints)),
            'problems' => array_map(static fn($p) => ['k' => $p[0], 't' => $p[1]], $probs), 'tiles' => $tiles, 'verdict' => $verdict, 'tp_busy' => !empty($m['tp_busy'])];
    }

    /** @param array<string, mixed> $r */
    private static function waitHint(array $r): string
    {
        if ($r['src_host_id'] || $r['check_id']) {
            return t('Wacht op de eerste meting van de agent (binnen een paar minuten; agent 1.78.0 of nieuwer nodig).');
        }
        return t('Wacht op de eerste meting (binnen een paar minuten), of klik op Nu meten.');
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed>|null $m
     * @param array<string, mixed>|null $last
     * @param array<string, mixed>|null $tp
     * @return array<string, mixed>
     */
    private static function tiles(array $r, ?array $m, ?array $last, float $planD, float $planU, ?array $tp): array
    {
        $dst = $m ? end($m['nodes']) : null;
        $ok = $last && !$last['err'];
        return ['down' => $ok && $last['down'] !== null ? round((float) $last['down']) : null, 'up' => $ok && $last['up'] !== null ? round((float) $last['up']) : null,
            'plan_down' => (int) $planD, 'plan_up' => (int) $planU, 'warn_pct' => Internet::pct()[0], 'test_at' => $last ? strtotime((string) $last['at']) : null, 'test_err' => $last['err'] ?? null,
            'ms' => $dst ? $dst['avg'] : null, 'jit' => $dst ? $dst['jit'] : null, 'loss' => $dst ? $dst['loss'] : null,
            'tp' => $tp['mbit'] ?? null, 'tp_at' => $tp['at'] ?? null, 'tp_to' => $tp['name'] ?? ($tp['to'] ?? null), 'tp_err' => $tp['err'] ?? null];
    }

    // ------------------------------------------------------------------ voor de pagina

    /** @return array<string, mixed> de route met keten, verloop per schakel en de tegels */
    public static function view(int $id, int $days = 7): array
    {
        $routes = self::routes();
        $r = $id ? q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch() : null;
        $r = $r ?: q('SELECT * FROM speed_routes WHERE enabled = 1 ORDER BY id LIMIT 1')->fetch();
        if (!$r) {
            return ['routes' => $routes, 'route' => null];
        }
        $days = $days === 30 ? 30 : 7;
        $c = self::chain($r);
        // Verloop: per lijn de vertraging (of de gemeten doorvoer), en de internetlijn uit de snelheidstests
        $n = count($c['links']);
        $series = array_fill(0, $n, []);
        $tpS = [];
        $span = $days * 86400;
        $buckets = 48;
        $rows = q('SELECT UNIX_TIMESTAMP(at) AS t, data FROM speed_samples WHERE route_id = ? AND at >= NOW() - INTERVAL ? DAY ORDER BY at', [$r['id'], $days])->fetchAll();
        foreach ($rows as $row) {
            $x = json_decode((string) $row['data'], true) ?: [];
            $b = (int) min($buckets - 1, floor(($span - (time() - (int) $row['t'])) / $span * $buckets));
            foreach ((array) ($x['l'] ?? []) as $i => $l) {
                if ($i < $n && isset($l[0])) {
                    $series[$i][$b][] = (float) $l[0];
                }
            }
            if (isset($x['tp'])) {
                $tpS[$b][] = (float) $x['tp'];
            }
        }
        $flat = static function (array $s) use ($buckets): array {
            $out = [];
            for ($i = 0; $i < $buckets; $i++) {
                $out[] = isset($s[$i]) ? round(array_sum($s[$i]) / count($s[$i]), 2) : null;
            }
            return $out;
        };
        foreach ($c['links'] as $i => &$l) {
            $l['hist'] = $flat($series[$i]);
        }
        unset($l);
        $inet = [];
        foreach (Internet::history($days) as $h) {
            if ($h['err'] === null) {
                $b = (int) min($buckets - 1, floor(($span - (time() - (int) $h['at'])) / $span * $buckets));
                $inet['down'][$b][] = (float) $h['down'];
                $inet['up'][$b][] = (float) $h['up'];
            }
        }
        $routeD = self::describe($r, self::hosts());
        if ((string) $r['dst_kind'] === 'internet' || !$r['src_host_id']) {
            // Bij de router: de interfaces die SNMP kent (om de WAN-poort te kiezen)
            $gw = '';
            foreach ($c['nodes'] as $nd) {
                if ($nd['k'] === 'router') {
                    $gw = (string) $nd['ip'];
                    break;
                }
            }
            $s = $gw !== '' ? (self::snmpByIp()[$gw] ?? null) : null;
            $routeD['router_ifs'] = $s ? array_map(static fn($i, $x) => ['i' => (string) $i, 'n' => $x['n'] . ($x['a'] !== '' ? ' (' . $x['a'] . ')' : '') . ($x['s'] ? ' · ' . Paths::speed((int) $x['s']) : '') . ($x['up'] ? '' : ' · down')],
                array_keys($s['d']['if']), array_values($s['d']['if'])) : [];
        }
        return ['routes' => $routes, 'route' => $routeD, 'days' => $days] + $c + ['hist' => ['tp' => $flat($tpS), 'down' => $flat($inet['down'] ?? []), 'up' => $flat($inet['up'] ?? [])],
            'running' => (strtotime((string) setting('inet_speed_running', '')) ?: 0) > time() - 120];
    }

    /** @return array<string, mixed>|null kort, voor de tegel op het dashboard */
    public static function tile(): ?array
    {
        self::seed();
        $r = q('SELECT * FROM speed_routes WHERE enabled = 1 ORDER BY id LIMIT 1')->fetch();
        if (!$r) {
            return null;
        }
        $c = self::chain($r);
        return ['route' => self::describe($r, self::hosts())['name'], 'tiles' => $c['tiles'], 'verdict' => $c['verdict'], 'problems' => count($c['problems'])];
    }

    // ------------------------------------------------------------------ elke minuut

    /** Meten (RzSense zelf), SNMP bijwerken, metingen bewaren en melden */
    public static function cron(bool $force = false, int $only = 0): ?string
    {
        self::seed();
        $done = [];
        // SNMP: switches en routers, hooguit 4 per ronde
        $n = 0;
        foreach (self::snmpHosts() as $h) {
            $d = AgentData::get((int) $h['id'], 'speed_snmp');
            if ($n < 4 && (!$d || (int) ($d['at'] ?? 0) < time() - self::SNMP_EVERY)) {
                self::snmpRefresh($h);
                $n++;
            }
        }
        $local = self::localAgent();
        foreach (q('SELECT * FROM speed_routes WHERE enabled = 1' . ($only ? ' AND id = ' . $only : ''))->fetchAll() as $r) {
            try {
                if ($r['src_host_id'] || $local) {
                    $cid = (int) $r['check_id'];
                    if (!$cid || !q('SELECT 1 FROM checks WHERE id = ?', [$cid])->fetchColumn()) {
                        $r['check_id'] = self::syncCheck($r);
                    }
                } else {
                    if ($r['check_id']) {
                        $r['check_id'] = self::syncCheck($r);   // de agent op deze server is weg
                    }
                    if ($force || !$r['last_run'] || strtotime((string) $r['last_run']) < time() - self::LAT_EVERY) {
                        self::measureLocal($r);
                        $done[] = (int) $r['id'];
                        $r = q('SELECT * FROM speed_routes WHERE id = ?', [$r['id']])->fetch();
                    }
                    self::localTp($r, $force);
                }
                self::sample($r);
            } catch (Throwable $e) {
                error_log('RzSense: netwerksnelheid ' . $r['id'] . ': ' . $e->getMessage());
            }
        }
        if (random_int(1, 60) === 1) {
            q('DELETE FROM speed_samples WHERE at < NOW() - INTERVAL 35 DAY');
            q('DELETE FROM wifi_samples WHERE at < NOW() - INTERVAL 35 DAY');
        }
        return $done ? 'Netwerksnelheid gemeten: ' . count($done) . ' route(s)' : null;
    }

    /**
     * Een nieuwe meting bewaren (hooguit één per 9 minuten) en zo nodig melden
     * @param array<string, mixed> $r
     */
    private static function sample(array $r): void
    {
        $m = self::pathModel($r);
        if (!$m) {
            return;
        }
        $lastAt = (int) q('SELECT UNIX_TIMESTAMP(MAX(at)) FROM speed_samples WHERE route_id = ?', [$r['id']])->fetchColumn();
        if ((int) $m['at'] <= $lastAt || $lastAt > time() - self::SAMPLE_GAP) {
            return;
        }
        $c = self::chain($r, $m);
        $data = ['l' => array_map(static fn($l) => [$l['ms'], $l['spd'], $l['tp']], $c['links']), 'k' => substr(md5(implode('>', array_column($c['nodes'], 'ip'))), 0, 8)];
        if ($c['tiles']['tp'] !== null && (int) ($c['tiles']['tp_at'] ?? 0) > $lastAt) {
            $data['tp'] = $c['tiles']['tp'];
        }
        q('INSERT INTO speed_samples (route_id, at, data) VALUES (?, NOW(), ?)', [$r['id'], json_encode($data)]);
        self::notify($r, $c);
    }

    /**
     * Melden als de route dat mag: één keer bij een nieuw probleem, en één keer als het weer goed is.
     * @param array<string, mixed> $r
     * @param array<string, mixed> $c
     */
    private static function notify(array $r, array $c): void
    {
        $keys = array_column($c['problems'], 'k');
        sort($keys);
        $state = $keys ? substr(md5(implode('|', $keys)), 0, 16) : '';
        if ($state === (string) $r['last_state']) {
            return;
        }
        q('UPDATE speed_routes SET last_state = ? WHERE id = ?', [$state !== '' ? $state : null, $r['id']]);
        if (!(int) $r['notify']) {
            return;
        }
        $name = self::describe($r, self::hosts())['name'];
        $hid = $r['src_host_id'] ? (int) $r['src_host_id'] : null;
        if ($state !== '') {
            Monitor::sysInfo('net_speed', $hid, null, '⚡ ' . t('Netwerksnelheid: {r}', ['r' => $name]), fn() => implode("\n", array_column($c['problems'], 't')));
        } elseif ((string) $r['last_state'] !== '') {
            Monitor::sysInfo('net_speed', $hid, null, '✅ ' . t('Netwerksnelheid weer in orde: {r}', ['r' => $name]), fn() => t('Geen schakel valt meer terug en de smalste schakel is weer boven de grens.'));
        }
    }

    /**
     * Nu meten (knop, alleen beheerders): het pad, de doorvoertest en bij internet de snelheidstest.
     * @return string wat er gebeurde
     */
    public static function measureNow(int $id): string
    {
        $r = q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch();
        if (!$r) {
            throw new InvalidArgumentException(t('Niet gevonden'));
        }
        $out = [];
        $viaAgent = $r['src_host_id'] || self::localAgent();
        if ($viaAgent) {
            $cid = (int) $r['check_id'] ?: (int) self::syncCheck($r);
            $c = $cid ? q('SELECT * FROM checks WHERE id = ?', [$cid])->fetch() : null;
            if ($c && (string) $r['dst_kind'] === 'host' && (int) $r['tp']) {
                $out[] = Paths::request($cid, (int) $c['host_id']) ? t('doorvoertest gevraagd (binnen een paar minuten)') : t('doorvoertest kon niet starten (zie de keten)');
            }
            $out[] = t('het pad meet de agent elke 5 minuten');
        } else {
            self::measureLocal($r);
            $r = q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch();
            self::localTp($r, true);
            $out[] = t('pad gemeten');
            if ((string) $r['dst_kind'] === 'host' && (int) $r['tp']) {
                $out[] = t('doorvoertest gevraagd (binnen een paar minuten)');
            }
        }
        if ((string) $r['dst_kind'] === 'internet') {
            $s = Internet::speedtest();
            $out[] = $s['err'] === null ? t('snelheidstest ↓ {d} / ↑ {u} Mbit/s', ['d' => (string) round((float) $s['down']), 'u' => (string) round((float) $s['up'])])
                : t('snelheidstest mislukt: {e}', ['e' => $s['err']]);
        }
        q('DELETE FROM speed_samples WHERE route_id = ? AND at >= NOW() - INTERVAL 10 MINUTE', [$id]);   // de nieuwe meting meteen bewaren
        self::sample(q('SELECT * FROM speed_routes WHERE id = ?', [$id])->fetch());
        return implode(' · ', $out);
    }

    // ------------------------------------------------------------------ wifi

    /**
     * Wifi van een apparaat met de agent opslaan (agent 1.122.0, Windows-agent 0.7.0)
     * @param array<string, mixed> $host
     * @param array<string, mixed> $in
     */
    public static function fromAgent(array $host, array $in): void
    {
        if (!isset($in['wifi']) || !is_array($in['wifi'])) {
            return;
        }
        $w = $in['wifi'];
        if (!empty($w['noiw'])) {
            self::ensureIw((int) $host['id']);   // agent 1.123.0: wifi-kaart zonder iw
            return;
        }
        $num = static fn($v, float $lo, float $hi): ?float => is_numeric($v) && (float) $v >= $lo && (float) $v <= $hi ? round((float) $v, 1) : null;
        $d = ['ssid' => mb_substr((string) preg_replace('/[\x00-\x1f]/u', '', (string) ($w['ssid'] ?? '')), 0, 64), 'bssid' => NetScan::normMac((string) ($w['bssid'] ?? '')),
            'if' => mb_substr((string) preg_replace('/[^\w.@ -]/u', '', (string) ($w['if'] ?? '')), 0, 40), 'band' => in_array((string) ($w['band'] ?? ''), ['2.4', '5', '6'], true) ? (string) $w['band'] : '',
            'chan' => ($c = $num($w['chan'] ?? null, 1, 233)) !== null ? (int) $c : null, 'width' => ($x = $num($w['width'] ?? null, 5, 320)) !== null ? (int) $x : null,
            'sig' => ($s = $num($w['sig'] ?? null, -110, 0)) !== null ? (int) $s : null, 'tx' => $num($w['tx'] ?? null, 0.1, 50000), 'rx' => $num($w['rx'] ?? null, 0.1, 50000),
            'std' => preg_match('/^[a-z0-9]{1,6}$/i', (string) ($w['std'] ?? '')) ? strtolower((string) $w['std']) : ''];
        if ($d['band'] === '' && $d['chan'] !== null) {
            $d['band'] = $d['chan'] <= 14 ? '2.4' : '5';
        }
        if ($d['bssid'] === null && $d['ssid'] === '') {
            return;
        }
        AgentData::put((int) $host['id'], 'wifi', $d);
        AgentData::put((int) $host['id'], 'iw_inst', null);   // iw is er (weer): geen oude foutmelding meer
        $rate = max((float) ($d['tx'] ?? 0), (float) ($d['rx'] ?? 0));
        $lastAt = (int) q('SELECT UNIX_TIMESTAMP(MAX(at)) FROM wifi_samples WHERE host_id = ?', [$host['id']])->fetchColumn();
        if ($lastAt < time() - self::SAMPLE_GAP) {
            q('INSERT INTO wifi_samples (host_id, at, rate, sig) VALUES (?, NOW(), ?, ?)', [$host['id'], $rate > 0 ? (int) round($rate) : null, $d['sig']]);
        }
    }

    /**
     * 1.329.0: iw laten installeren door de agent (zoals iperf3 bij de doorvoertest): via apt of dnf, als root, met voortgang
     * en een melding achteraf. Niet bij Home Assistant, TrueNAS of zonder commando's als root, niet terwijl er al iets
     * loopt, en hooguit eens per 12 uur per apparaat. Uit te zetten met de instelling iw_install.
     */
    public static function ensureIw(int $hostId): bool
    {
        if (setting('iw_install', '1') !== '1') {
            return false;
        }
        $h = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || $h['mode'] !== 'agent' || !empty($h['vuln_upgrade_req']) || Vulns::upgradeBlocked($h)) {
            return false;
        }
        $m = AgentData::get($hostId, 'iw_inst');
        if ($m && (int) ($m['at'] ?? 0) > time() - 43200) {
            return false;
        }
        $r = Vulns::requestInstall($h, ['iw'], 'RzSense (wifi)', true);
        AgentData::put($hostId, 'iw_inst', ['at' => time(), 'err' => $r === true ? '' : (string) $r]);
        return $r === true;
    }

    /** dBm naar 0–4 streepjes */
    public static function bars(?int $sig): int
    {
        return $sig === null ? 0 : ($sig >= -55 ? 4 : ($sig >= -65 ? 3 : ($sig >= self::WEAK ? 2 : 1)));
    }

    /**
     * Wifi per access point: met UniFi alle access points en apparaten, anders de apparaten met de agent (gegroepeerd per
     * access point op het BSSID).
     * @return array<string, mixed>
     */
    public static function wifi(int $days = 7): array
    {
        $days = $days === 30 ? 30 : 7;
        $agents = [];
        foreach (q("SELECT h.id, h.name, h.address, a.value, UNIX_TIMESTAMP(a.updated_at) AS t FROM agent_data a JOIN hosts h ON h.id = a.host_id WHERE a.metric = 'wifi' AND a.updated_at >= NOW() - INTERVAL 1 DAY")->fetchAll() as $r) {
            $d = json_decode((string) $r['value'], true);
            if (is_array($d)) {
                $agents[(int) $r['id']] = ['host_id' => (int) $r['id'], 'name' => (string) $r['name'], 'ip' => (string) $r['address'], 'at' => (int) $r['t']] + $d;
            }
        }
        // Gemeten doorvoer: de laatste iperf3 van een route vanaf dit apparaat
        $tp = [];
        foreach (q('SELECT r.src_host_id, r.check_id, r.last_tp FROM speed_routes r WHERE r.src_host_id IS NOT NULL')->fetchAll() as $r) {
            $x = $r['check_id'] ? AgentData::get((int) $r['src_host_id'], 'pathtp:' . $r['check_id']) : null;
            if ($x && isset($x['mbit'])) {
                $tp[(int) $r['src_host_id']] = max($tp[(int) $r['src_host_id']] ?? 0, (float) $x['mbit']);
            }
        }
        // Verloop van de verbindingssnelheid
        $hist = [];
        $span = $days * 86400;
        if ($agents) {
            $in = implode(',', array_map('intval', array_keys($agents)));
            foreach (q("SELECT host_id, UNIX_TIMESTAMP(at) AS t, rate FROM wifi_samples WHERE host_id IN ($in) AND at >= NOW() - INTERVAL ? DAY ORDER BY at", [$days])->fetchAll() as $s) {
                $b = (int) min(47, floor(($span - (time() - (int) $s['t'])) / $span * 48));
                if ($s['rate'] !== null) {
                    $hist[(int) $s['host_id']][$b][] = (int) $s['rate'];
                }
            }
        }
        $flat = static function (array $s): array {
            $o = [];
            for ($i = 0; $i < 48; $i++) {
                $o[] = isset($s[$i]) ? (int) round(array_sum($s[$i]) / count($s[$i])) : null;
            }
            return $o;
        };
        $client = static fn(array $a) => ['name' => $a['name'], 'host_id' => $a['host_id'] ?? null, 'ip' => $a['ip'] ?? '', 'band' => $a['band'] ?? '', 'chan' => $a['chan'] ?? null,
            'width' => $a['width'] ?? null, 'ssid' => $a['ssid'] ?? '', 'rate' => ($r = max((float) ($a['tx'] ?? 0), (float) ($a['rx'] ?? 0))) > 0 ? (int) round($r) : null,
            'tx' => $a['tx'] ?? null, 'rx' => $a['rx'] ?? null, 'sig' => $a['sig'] ?? null, 'bars' => self::bars($a['sig'] ?? null),
            'weak' => isset($a['sig']) && $a['sig'] < self::WEAK, 'bad' => isset($a['sig']) && $a['sig'] < self::BAD, 'std' => $a['std'] ?? ''];
        $uni = null;
        try {
            $uni = NetLinks::unifiOn() ? self::unifiWifiCached() : null;
        } catch (Throwable) {
            $uni = null;
        }
        $aps = [];
        if ($uni && $uni['aps']) {
            foreach ($uni['aps'] as $mac => $ap) {
                $aps[$mac] = $ap + ['clients' => [], 'src' => 'unifi'];
            }
            $byIp = [];
            foreach ($agents as $a) {
                $byIp[$a['ip']] = $a;
            }
            foreach ($uni['clients'] as $c) {
                if (!isset($aps[$c['ap']])) {
                    continue;
                }
                $a = $byIp[$c['ip']] ?? null;
                $row = $client($c + ['host_id' => $a['host_id'] ?? null]);
                $row['tp'] = $a ? ($tp[$a['host_id']] ?? null) : null;
                $row['hist'] = $a ? $flat($hist[$a['host_id']] ?? []) : [];
                $aps[$c['ap']]['clients'][] = $row;
            }
        } else {
            foreach ($agents as $a) {
                $key = $a['bssid'] ? substr($a['bssid'], 0, 14) : 'ssid:' . $a['ssid'];   // de eerste 5 delen: de radio's van één access point
                if (!isset($aps[$key])) {
                    $aps[$key] = ['name' => self::apName($a['bssid']), 'mac' => $a['bssid'], 'uplink' => null, 'clients' => [], 'src' => 'agent'];
                }
                $row = $client($a);
                $row['tp'] = $tp[$a['host_id']] ?? null;
                $row['hist'] = $flat($hist[$a['host_id']] ?? []);
                $aps[$key]['clients'][] = $row;
            }
        }
        foreach ($aps as &$ap) {
            usort($ap['clients'], static fn($a, $b) => ($a['sig'] ?? 0) <=> ($b['sig'] ?? 0));   // zwakste eerst
            $ap['weak'] = count(array_filter($ap['clients'], static fn($c) => $c['weak']));
        }
        unset($ap);
        // 1.329.0: apparaten met een wifi-kaart waar iw niet geïnstalleerd kon worden
        $noIw = [];
        foreach (q("SELECT h.name, a.value FROM agent_data a JOIN hosts h ON h.id = a.host_id WHERE a.metric = 'iw_inst'")->fetchAll() as $r) {
            $x = json_decode((string) $r['value'], true);
            if (is_array($x) && ($x['err'] ?? '') !== '') {
                $noIw[] = ['name' => (string) $r['name'], 'err' => (string) $x['err']];
            }
        }
        return ['aps' => array_values($aps), 'unifi' => NetLinks::unifiOn(), 'agents' => count($agents), 'no_iw' => $noIw];
    }

    /** Naam bij een BSSID: een apparaat uit de netwerkscan met (bijna) hetzelfde MAC-adres, anders "Access point" + het begin */
    private static function apName(?string $bssid): string
    {
        if ($bssid === null) {
            return t('Access point');
        }
        $n = q("SELECT COALESCE(NULLIF(name, ''), NULLIF(hostname, '')) FROM net_devices WHERE mac LIKE ? AND COALESCE(NULLIF(name, ''), NULLIF(hostname, '')) IS NOT NULL ORDER BY last_seen DESC LIMIT 1",
            [substr($bssid, 0, 14) . '%'])->fetchColumn();
        return $n ? (string) $n : t('Access point {m}', ['m' => substr($bssid, 0, 8) . '…']);
    }

    /** @return array<string, mixed>|null UniFi-wifi, 5 minuten bewaard */
    private static function unifiWifiCached(): ?array
    {
        $c = json_decode((string) setting('speed_unifi_wifi', ''), true);
        if (is_array($c) && (int) ($c['at'] ?? 0) > time() - 300) {
            return $c['d'];
        }
        $d = NetLinks::unifiWifi();
        save_setting('speed_unifi_wifi', (string) json_encode(['at' => time(), 'd' => $d]));
        return $d;
    }
}
