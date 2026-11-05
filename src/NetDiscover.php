<?php
declare(strict_types=1);

/**
 * 1.183.0: Netwerk → Ontdekken. Houdt bij wat er op het netwerk verandert en wanneer een apparaat online is,
 * en stelt checks voor apparaten die nog niet in RzMon staan.
 *
 * - net_events: nieuw apparaat, ander IP-adres, nieuwe of gesloten poorten, nieuwe domeinen (AdGuard), al dagen weg, weer terug;
 * - net_presence: per apparaat per dag een bitmasker van de uren waarin het gezien is (24 bits);
 * - voorstellen: welke checks bij een apparaat passen (soort + open poorten), met één klik toe te voegen.
 */
final class NetDiscover
{
    public const GONE_DAYS = 3;          // zo lang niet gezien = "al dagen weg"
    public const LEARN_DAYS = 7;         // zo lang leren welke domeinen normaal zijn
    public const KEEP_EVENTS = 90;
    public const KEEP_PRESENCE = 30;
    public const EVENT_KINDS = ['new', 'ip', 'ports_new', 'ports_gone', 'domains', 'gone', 'back', 'traffic', 'traffic_night'];   // 1.186.0: verkeer

    /** Poorten die een check waard zijn: poort => [soort check, label, doel] (doel: {ip} wordt ingevuld) */
    private const PORT_CHECKS = [
        80 => ['http', 'Web (http)', 'http://{ip}/'],
        443 => ['http', 'Web (https)', 'https://{ip}/'],
        5000 => ['http', 'Beheerpagina (5000)', 'http://{ip}:5000/'],
        5001 => ['http', 'Beheerpagina (5001)', 'https://{ip}:5001/'],
        8006 => ['http', 'Proxmox', 'https://{ip}:8006/'],
        8123 => ['http', 'Home Assistant', 'http://{ip}:8123/'],
        32400 => ['http', 'Plex', 'http://{ip}:32400/identity'],
        22 => ['tcp', 'SSH', '22'],
        53 => ['tcp', 'DNS', '53'],
        445 => ['tcp', 'Bestandsdeling (SMB)', '445'],
        548 => ['tcp', 'Bestandsdeling (AFP)', '548'],
        554 => ['tcp', 'Camerabeeld (RTSP)', '554'],
        631 => ['tcp', 'Printen (IPP)', '631'],
        9100 => ['tcp', 'Printen (RAW)', '9100'],
        1883 => ['tcp', 'MQTT', '1883'],
        8883 => ['tcp', 'MQTT (TLS)', '8883'],
        3306 => ['tcp', 'MariaDB/MySQL', '3306'],
        5432 => ['tcp', 'PostgreSQL', '5432'],
    ];
    /** Soorten die je meestal wilt bewaken (telefoons en laptops niet: die gaan vaak weg) */
    private const WATCH_KINDS = ['router', 'network', 'server', 'nas', 'printer', 'camera', 'smarthome', 'iot', 'media'];
    /** Soort in de netwerkscan → soort apparaat in RzMon */
    private const TO_HOST = ['router' => 'router', 'network' => 'switch', 'server' => 'server', 'nas' => 'nas', 'printer' => 'printer', 'camera' => 'camera',
        'smarthome' => 'smarthome', 'iot' => 'smarthome', 'media' => 'other'];

    // ------------------------------------------------------------------ vastleggen

    public static function event(int $deviceId, string $kind, array $detail = []): void
    {
        if (!in_array($kind, self::EVENT_KINDS, true)) {
            return;
        }
        try {
            q('INSERT INTO net_events (device_id, kind, detail, created_at) VALUES (?, ?, ?, ?)',
                [$deviceId, $kind, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, now()]);
        } catch (Throwable) {
            // tabel nog niet aangemaakt (vóór de migratie): niet erg
        }
    }

    /** Dit uur gezien, voor deze apparaten */
    public static function presence(array $ids, ?int $ts = null): void
    {
        $ts ??= time();
        $day = date('Y-m-d', $ts);
        $bit = 1 << (int) date('G', $ts);
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id > 0) {
                q('INSERT INTO net_presence (device_id, day, hours) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE hours = hours | VALUES(hours)', [$id, $day, $bit]);
            }
        }
    }

    /** Na een lichte ronde: wat al dagen weg is (alleen vaste MAC-adressen, niet genegeerd) */
    public static function afterSweep(array $aliveIds): void
    {
        try {
            self::presence($aliveIds);
            $rows = q("SELECT id, ip, info, last_seen FROM net_devices WHERE last_seen < NOW() - INTERVAL " . self::GONE_DAYS . " DAY
                AND last_seen >= NOW() - INTERVAL 30 DAY AND status <> 'ignored' AND random_mac = 0")->fetchAll();
            foreach ($rows as $r) {
                $info = json_decode((string) $r['info'], true) ?: [];
                if (!empty($info['gone'])) {
                    continue;
                }
                $info['gone'] = 1;
                q('UPDATE net_devices SET info = ? WHERE id = ?', [json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $r['id']]);
                self::event((int) $r['id'], 'gone', ['ip' => $r['ip'], 'last' => $r['last_seen']]);
            }
        } catch (Throwable) {
        }
    }

    /** Bij een bestaand apparaat in de lichte ronde: ander IP-adres, of weer terug. Past $info aan. */
    public static function seen(array $row, string $ip, array &$info): void
    {
        if ($row['ip'] !== $ip) {
            self::event((int) $row['id'], 'ip', ['from' => $row['ip'], 'to' => $ip]);
        }
        if (!empty($info['gone'])) {
            unset($info['gone']);
            $days = (int) floor((time() - (int) strtotime((string) $row['last_seen'])) / 86400);
            self::event((int) $row['id'], 'back', ['days' => $days]);
        }
    }

    /** Na de diepere ronde: welke poorten zijn er bij gekomen of weg ($old null = eerste keer) */
    public static function ports(int $id, ?array $old, array $new): void
    {
        if ($old === null) {
            return;
        }
        $old = array_map('intval', $old);
        $new = array_map('intval', $new);
        $add = array_values(array_diff($new, $old));
        $gone = array_values(array_diff($old, $new));
        if ($add) {
            self::event($id, 'ports_new', ['ports' => $add, 'risky' => array_values(array_intersect($add, NetScan::RISKY))]);
        }
        if ($gone) {
            self::event($id, 'ports_gone', ['ports' => $gone]);
        }
    }

    /** Hoofddomein (voorbeeld.nl uit cdn.api.voorbeeld.nl; ook co.uk en com.au) */
    public static function baseDomain(string $d): string
    {
        $p = explode('.', strtolower(trim($d, '.')));
        $n = count($p);
        if ($n <= 2) {
            return implode('.', $p);
        }
        $two = $p[$n - 2] . '.' . $p[$n - 1];
        return in_array($two, ['co.uk', 'org.uk', 'ac.uk', 'com.au', 'net.au', 'co.nz', 'com.br', 'co.jp', 'com.tr', 'co.za'], true)
            ? $p[$n - 3] . '.' . $two : $two;
    }

    /**
     * Domeinen uit AdGuard: de eerste dagen leren, daarna een gebeurtenis bij nieuwe hoofddomeinen. Past $info aan.
     * @param array<string,int> $domains domein => aantal
     */
    public static function domains(int $id, array &$info, array $domains, ?int $now = null): void
    {
        $now ??= time();
        if (!$domains) {
            return;
        }
        $bases = [];
        foreach ($domains as $d => $n) {
            $b = self::baseDomain((string) $d);
            if ($b !== '' && str_contains($b, '.') && !preg_match('/\.(arpa|local|lan|home|internal)$/', $b)) {
                $bases[$b] = ($bases[$b] ?? 0) + (int) $n;
            }
        }
        $dom = (array) ($info['dom'] ?? []);
        $since = (int) ($dom['since'] ?? 0) ?: $now;
        $set = (array) ($dom['set'] ?? []);
        $new = array_values(array_diff(array_keys($bases), $set));
        if ($new && $since <= $now - self::LEARN_DAYS * 86400) {
            arsort($bases);
            $new = array_values(array_filter(array_keys($bases), fn($b) => in_array($b, $new, true)));
            self::event($id, 'domains', ['domains' => array_slice($new, 0, 8), 'n' => count($new)]);
        }
        $info['dom'] = ['since' => $since, 'set' => array_slice(array_values(array_unique(array_merge($set, array_keys($bases)))), -400)];
    }

    public static function purge(): void
    {
        try {
            q('DELETE FROM net_events WHERE created_at < NOW() - INTERVAL ' . self::KEEP_EVENTS . ' DAY');
            q('DELETE FROM net_presence WHERE day < CURDATE() - INTERVAL ' . self::KEEP_PRESENCE . ' DAY');
            q('DELETE e FROM net_events e LEFT JOIN net_devices d ON d.id = e.device_id WHERE d.id IS NULL');
            q('DELETE p FROM net_presence p LEFT JOIN net_devices d ON d.id = p.device_id WHERE d.id IS NULL');
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------------ voorstellen

    /** Checks die bij dit apparaat passen: [[key, type, label, target, cols]] */
    public static function suggest(array $d): array
    {
        $ip = (string) $d['ip'];
        $out = [['key' => 'ping', 'type' => 'ping', 'label' => 'Ping', 'cols' => ['type' => 'ping', 'target' => '', 'name' => 'Ping', 'warn_threshold' => 100, 'crit_threshold' => 500]]];
        $ports = array_map('intval', (array) ($d['ports'] ?? []));
        foreach (self::PORT_CHECKS as $port => [$type, $label, $target]) {
            if (!in_array($port, $ports, true)) {
                continue;
            }
            if ($port === 80 && in_array(443, $ports, true)) {
                continue;   // https gaat voor
            }
            $tgt = str_replace('{ip}', $ip, $target);
            $lbl = t($label);
            $cols = $type === 'http'
                ? ['type' => 'http', 'target' => $tgt, 'name' => $lbl, 'warn_threshold' => 1000, 'crit_threshold' => 3000, 'verify_ssl' => 0]
                : ['type' => 'tcp', 'target' => $tgt, 'name' => $lbl, 'warn_threshold' => 200, 'crit_threshold' => 1000];
            $out[] = ['key' => 'p' . $port, 'type' => $type, 'label' => $lbl, 'target' => $tgt, 'cols' => $cols];
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    /** Apparaten die een voorstel krijgen: niet in RzMon, niet genegeerd, van een soort die je meestal bewaakt (of met een dienst), de laatste 7 dagen gezien */
    public static function suggestions(array $devices): array
    {
        $out = [];
        $week = time() - 7 * 86400;
        foreach ($devices as $d) {
            $kind = (string) ($d['kind_user'] ?: $d['kind']);
            if ($d['host'] || $d['self'] || $d['status'] === 'ignored' || $d['blocked'] || strtotime((string) $d['last_seen']) < $week) {
                continue;
            }
            $sug = self::suggest($d);
            if (!in_array($kind, self::WATCH_KINDS, true) && count($sug) < 2) {
                continue;   // telefoon, laptop of onbekend zonder diensten: niet voorstellen
            }
            $out[] = ['id' => (int) $d['id'], 'label' => $d['label'], 'ip' => $d['ip'], 'kind' => $kind, 'host_kind' => self::TO_HOST[$kind] ?? '',
                'checks' => array_map(fn($s) => ['key' => $s['key'], 'type' => $s['type'], 'label' => $s['label'], 'target' => $s['target'] ?? ''], $sug),
                'snmp' => in_array($kind, ['router', 'network', 'nas', 'printer'], true)];
        }
        usort($out, fn($a, $b) => [array_search($a['kind'], self::WATCH_KINDS, true) === false, -count($a['checks'])]
            <=> [array_search($b['kind'], self::WATCH_KINDS, true) === false, -count($b['checks'])]);
        return $out;
    }

    /** Apparaat in RzMon zetten met de gekozen checks; geeft het id van de nieuwe server */
    public static function adopt(int $deviceId, array $keys, string $name, string $by): int
    {
        $d = null;
        foreach (NetScan::list() as $x) {
            if ((int) $x['id'] === $deviceId) {
                $d = $x;
                break;
            }
        }
        if (!$d) {
            throw new InvalidArgumentException(t('Apparaat niet gevonden'));
        }
        if ($d['host']) {
            throw new InvalidArgumentException(t('Dit apparaat staat al in RzMon als {name}', ['name' => $d['host']['name']]));
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name) ?? ''), 0, 100) ?: (string) $d['label'];
        $ip = (string) $d['ip'];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(t('Geen geldig IP-adres'));
        }
        $kind = (string) ($d['kind_user'] ?: $d['kind']);
        q("INSERT INTO hosts (name, address, mode, enabled) VALUES (?, ?, 'remote', 1)", [$name, $ip]);
        $hostId = (int) db()->lastInsertId();
        if (isset(self::TO_HOST[$kind])) {   // alle soorten in TO_HOST bestaan in HostKind::KINDS
            q('UPDATE hosts SET kind = ? WHERE id = ?', [self::TO_HOST[$kind], $hostId]);
        }
        $keys = array_map('strval', $keys) ?: ['ping'];
        foreach (self::suggest($d) as $s) {
            if (in_array($s['key'], $keys, true)) {
                Templates::insertCheck($hostId, $s['cols']);
            }
        }
        q("UPDATE net_devices SET status = 'known', name = COALESCE(name, ?) WHERE id = ?", [$name !== (string) $d['label'] ? $name : null, $deviceId]);
        ChangeLog::record('host', $hostId, 'create', $name, null, ChangeLog::hostRow($hostId));
        return $hostId;
    }

    // ------------------------------------------------------------------ voor de pagina

    /** Gebeurtenissen van de laatste $days dagen, nieuwste eerst */
    public static function events(int $days = 30, int $limit = 300): array
    {
        $rows = q('SELECT e.id, e.device_id, e.kind, e.detail, e.created_at FROM net_events e WHERE e.created_at >= NOW() - INTERVAL ' . max(1, min(90, $days)) . ' DAY
            ORDER BY e.created_at DESC, e.id DESC LIMIT ' . max(1, min(1000, $limit)))->fetchAll();
        return array_map(fn($r) => ['id' => (int) $r['id'], 'device' => (int) $r['device_id'], 'kind' => $r['kind'],
            'detail' => json_decode((string) $r['detail'], true) ?: [], 'at' => $r['created_at']], $rows);
    }

    /** Aanwezigheid van de laatste $days dagen: [device_id => [dag => uren-bitmasker]] */
    public static function presenceGrid(int $days = 7): array
    {
        $out = [];
        foreach (q('SELECT device_id, day, hours FROM net_presence WHERE day >= CURDATE() - INTERVAL ' . max(1, min(30, $days - 1)) . ' DAY')->fetchAll() as $r) {
            $out[(int) $r['device_id']][(string) $r['day']] = (int) $r['hours'];
        }
        return $out;
    }

    /** Nieuwe apparaten per dag (laatste 30 dagen) */
    public static function newPerDay(): array
    {
        $out = [];
        foreach (q("SELECT DATE(first_seen) d, COUNT(*) n FROM net_devices WHERE first_seen >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(first_seen)")->fetchAll() as $r) {
            $out[(string) $r['d']] = (int) $r['n'];
        }
        return $out;
    }
}
