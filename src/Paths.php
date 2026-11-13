<?php
declare(strict_types=1);

/**
 * 1.177.0 (agent 1.78.0): pad-check. De agent op de bron meet het pad naar een doel: elke hop (mtr of traceroute),
 * de opgegeven tussenliggende apparaten (een switch zie je niet in een traceroute, wel met een ping), de eigen
 * netwerkkaart en of de server virtueel draait. RzSense voegt UniFi toe (op welke switchpoort, met welke snelheid,
 * en de uplink van die switch) en plant de doorvoertest met iperf3: de agent op het doel start kort een ontvanger
 * die na één meting stopt, daarna meet de bron 3 seconden.
 *
 * Opslag: agent_data "path:<id>" (laatste meting), "pathtp:<id>" (laatste doorvoer), "pathreq:<id>" (lopende
 * doorvoertest, bij de bron), "pathmax:<id>" (hoogste linksnelheden ooit, om een zakking te zien), "iperfs" (bij het doel);
 * path_runs (metingen van de laatste dagen, per hop) en path_events (wat er veranderde).
 */
final class Paths
{
    public const PORT = 5201;
    private const TP_WINDOW = 150;   // zo lang mag de ontvanger op het doel klaarstaan (s)

    /** @return array<string, mixed> */
    public static function opts(array $check): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        $via = array_values(array_filter(array_map('trim', (array) ($o['via'] ?? [])), static fn($v) => self::okHost($v)));
        return [
            'via' => array_slice($via, 0, 6),
            'tp' => !array_key_exists('tp', $o) || !empty($o['tp']),
            'tp_host' => (int) ($o['tp_host'] ?? 0),
            'tp_every' => in_array((int) ($o['tp_every'] ?? 3600), [0, 3600, 86400], true) ? (int) ($o['tp_every'] ?? 3600) : 3600,
            'tp_min' => isset($o['tp_min']) && $o['tp_min'] !== '' ? max(1, (int) $o['tp_min']) : 200,
            'loss_warn' => isset($o['loss_warn']) ? max(1, min(100, (int) $o['loss_warn'])) : 5,
            'loss_crit' => isset($o['loss_crit']) ? max(1, min(100, (int) $o['loss_crit'])) : 20,
            'link' => !array_key_exists('link', $o) || !empty($o['link']),
            'hop' => !array_key_exists('hop', $o) || !empty($o['hop']),
            'change' => !empty($o['change']),
        ];
    }

    public static function okHost(string $h): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9.:_-]{1,253}$/', $h) && !str_starts_with($h, '-');
    }

    public static function isPrivate(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function data(int $hostId, string $metric): ?array
    {
        $v = q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [$hostId, $metric])->fetchColumn();
        $d = $v !== false ? json_decode((string) $v, true) : null;
        return is_array($d) ? $d : null;
    }

    private static function put(int $hostId, string $metric, ?array $value): void
    {
        if ($value === null) {
            q('DELETE FROM agent_data WHERE host_id = ? AND metric = ?', [$hostId, $metric]);
            return;
        }
        q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$hostId, $metric, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now()]);
    }

    /**
     * 1.189.2: na een rapport van de agent de regels opruimen die dat rapport niet ververste. Wat RzSense zelf bijhoudt
     * en niet in elk rapport zit, blijft staan: de pad-metingen (de agent meet maar elke paar minuten; anders bleef de
     * pad-check op onbekend staan) en de agent-test. Die van verwijderde pad-checks gaan wel weg.
     */
    public static function cleanAgentData(int $hostId, string $now): void
    {
        $keep = '^(path|pathtp|pathmax|pathreq):[0-9]+$';
        q("DELETE FROM agent_data WHERE host_id = ? AND updated_at < ? AND metric NOT REGEXP '$keep' AND metric NOT IN ('iperfs', 'selftest', 'selftest_req', 'iperf_inst', 'logscan', 'logbrowse')", [$hostId, $now]);   // 1.213.0: logscan komt maar één keer per dag mee
        $ids = array_map('intval', q("SELECT id FROM checks WHERE host_id = ? AND type = 'path'", [$hostId])->fetchAll(PDO::FETCH_COLUMN));
        foreach (q("SELECT metric FROM agent_data WHERE host_id = ? AND metric REGEXP '$keep'", [$hostId])->fetchAll(PDO::FETCH_COLUMN) as $m) {
            if (!in_array((int) substr((string) $m, strpos((string) $m, ':') + 1), $ids, true)) {
                self::put($hostId, (string) $m, null);
            }
        }
    }

    // ------------------------------------------------------------------ opdrachten voor de agent

    /** Regels voor de agent: de pad-checks van deze server, een doorvoertest die klaarstaat, en een ontvanger als dit het doel is */
    public static function agentLines(int $hostId): array
    {
        $out = [];
        $port = self::port();
        $needTp = false;
        foreach (q("SELECT * FROM checks WHERE host_id = ? AND type = 'path' AND enabled = 1", [$hostId]) as $c) {
            $o = self::opts($c);
            $needTp = $needTp || ($o['tp'] && $o['tp_every'] > 0);
            if (!self::okHost((string) $c['target'])) {
                continue;
            }
            $out[] = implode("\t", [$c['id'], 'path', $c['target'], $o['via'] ? implode(',', $o['via']) : '-', max(60, (int) $c['interval_sec'])]);
            // Doorvoertest: plannen als het tijd is, en doorgeven zodra de ontvanger op het doel draait
            $req = self::data($hostId, 'pathreq:' . $c['id']);
            if (!$req && $o['tp'] && $o['tp_every'] > 0) {
                $last = self::data($hostId, 'pathtp:' . $c['id']);
                if (!$last || (int) ($last['at'] ?? 0) < time() - $o['tp_every'] + 30) {
                    $req = self::request((int) $c['id'], $hostId, $o);
                }
            }
            if ($req && (int) $req['until'] < time()) {
                self::put($hostId, 'pathreq:' . $c['id'], null);
                self::put($hostId, 'pathtp:' . $c['id'], ['at' => time(), 'to' => $req['ip'], 'err' => t('de ontvanger op {h} startte niet op tijd (draait de agent daar, met iperf3?)', ['h' => $req['name']])]);
                continue;
            }
            if ($req) {
                $srv = self::data((int) $req['host'], 'iperfs');
                if ($srv && (int) ($srv['started'] ?? 0) >= (int) $req['at'] - 5) {
                    $out[] = implode("\t", [$c['id'], 'pathtp', $req['ip'], $port, $req['key']]);
                }
            }
        }
        // Dit apparaat is het doel van een doorvoertest: kort een ontvanger starten
        $until = 0;
        foreach (q("SELECT value FROM agent_data WHERE metric LIKE 'pathreq:%'") as $r) {
            $q = json_decode((string) $r['value'], true);
            if (is_array($q) && (int) ($q['host'] ?? 0) === $hostId && (int) ($q['until'] ?? 0) > time()) {
                $until = max($until, (int) $q['until']);
            }
        }
        if ($until) {
            $out[] = implode("\t", [0, 'iperfs', $port, $until]);
        }
        // 1.192.0: deze server doet een doorvoertest maar heeft geen iperf3: de agent installeert het
        if ($needTp && !self::hasIperf($hostId)) {
            self::ensureIperf($hostId);
        }
        return $out;
    }

    /** Heeft de agent op deze server iperf3? (null = nog niet bekend: geen lijst van de agent) */
    public static function hasIperf(int $hostId): ?bool
    {
        $caps = self::data($hostId, 'caps');
        return $caps === null ? null : in_array('iperf3', $caps, true);
    }

    /**
     * 1.192.0: iperf3 laten installeren door de agent (via dezelfde weg als pakketten bijwerken: apt of dnf, als root,
     * met voortgang en een melding achteraf). Niet bij Home Assistant, TrueNAS (nooit apt) of zonder commando's als root,
     * niet terwijl er al iets loopt, en hooguit eens per 12 uur per server (ook na een mislukte poging). Uit te zetten met
     * de instelling iperf_install. Geeft true als de opdracht klaarstaat of net klaargezet is.
     */
    public static function ensureIperf(int $hostId): bool
    {
        if (setting('iperf_install', '1') !== '1' || self::hasIperf($hostId) !== false) {
            return false;
        }
        $h = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || Vulns::upgradeBlocked($h)) {
            return false;
        }
        $m = self::data($hostId, 'iperf_inst');
        if ($m && (int) ($m['at'] ?? 0) > time() - 43200) {
            return empty($m['err']) && (int) $m['at'] > time() - 1800;   // net aangevraagd: nog bezig
        }
        if (!empty($h['vuln_upgrade_req'])) {
            return false;   // er loopt al een opdracht (bijwerken of installeren): die niet overschrijven
        }
        $r = Vulns::requestInstall($h, ['iperf3'], 'RzSense (doorvoertest)', true);
        self::put($hostId, 'iperf_inst', ['at' => time(), 'err' => $r === true ? '' : (string) $r]);
        return $r === true;
    }

    public static function port(): int
    {
        $p = (int) setting('iperf_port', (string) self::PORT);
        return $p >= 1024 && $p <= 65535 ? $p : self::PORT;
    }

    /** Een doorvoertest aanvragen (ook met de knop): naar het gekozen apparaat, of automatisch een server met iperf3 in hetzelfde netwerk */
    public static function request(int $checkId, int $hostId, ?array $o = null): ?array
    {
        $o ??= self::opts(q('SELECT * FROM checks WHERE id = ?', [$checkId])->fetch() ?: []);
        $t = self::tpTarget($hostId, $o['tp_host']);
        if (!$t) {
            // 1.192.0: nog geen ontvanger met iperf3: op de beste kandidaat installeren (hetzelfde netwerk eerst)
            $cand = !$o['tp_host'] ? self::tpTarget($hostId, 0, true) : null;
            if ($cand && self::ensureIperf((int) $cand['id'])) {
                self::put($hostId, 'pathtp:' . $checkId, ['at' => time(), 'err' => t('iperf3 wordt geïnstalleerd op {h} (de ontvanger); de doorvoertest volgt daarna vanzelf', ['h' => $cand['name']])]);
                return null;
            }
            self::put($hostId, 'pathtp:' . $checkId, ['at' => time(), 'err' => t('Geen apparaat om naartoe te meten: zet bij een andere server met de agent en iperf3 "Mag doorvoertest ontvangen" aan')]);
            return null;
        }
        // 1.192.0: de gekozen ontvanger mist iperf3: daar installeren
        if (!self::hasIperf((int) $t['id']) && self::ensureIperf((int) $t['id'])) {
            self::put($hostId, 'pathtp:' . $checkId, ['at' => time(), 'err' => t('iperf3 wordt geïnstalleerd op {h} (de ontvanger); de doorvoertest volgt daarna vanzelf', ['h' => $t['name']])]);
            return null;
        }
        $req = ['key' => bin2hex(random_bytes(8)), 'at' => time(), 'until' => time() + self::TP_WINDOW, 'host' => (int) $t['id'], 'ip' => $t['address'], 'name' => $t['name']];
        self::put($hostId, 'pathreq:' . $checkId, $req);
        return $req;
    }

    /** @return array<string, mixed>|null */
    private static function tpTarget(int $srcId, int $wanted, bool $anyCaps = false): ?array
    {
        $src = q('SELECT address FROM hosts WHERE id = ?', [$srcId])->fetchColumn();
        $rows = q("SELECT h.*, c.value AS caps FROM hosts h LEFT JOIN agent_data c ON c.host_id = h.id AND c.metric = 'caps'
                   WHERE h.mode = 'agent' AND h.enabled = 1 AND h.iperf_ok = 1 AND h.id <> ?", [$srcId])->fetchAll();
        $best = null;
        $score = -1;
        foreach ($rows as $r) {
            if (!filter_var($r['address'], FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($wanted && (int) $r['id'] === $wanted) {
                return $r;
            }
            if ($wanted || (!$anyCaps && !in_array('iperf3', json_decode((string) $r['caps'], true) ?: [], true))) {
                continue;
            }
            if ($anyCaps && Vulns::upgradeBlocked($r)) {
                continue;   // 1.192.0: daar kan de agent niets installeren (Home Assistant, TrueNAS, geen root)
            }
            // Zelfde netwerk (eerste drie delen van het adres) gaat voor
            $s = is_string($src) && implode('.', array_slice(explode('.', $src), 0, 3)) === implode('.', array_slice(explode('.', $r['address']), 0, 3)) ? 2 : 1;
            if ($s > $score) {
                [$best, $score] = [$r, $s];
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------ van de agent

    /** @var int[] 1.191.1: pad-checks met een nieuwe meting in dit rapport */
    private static array $fresh = [];

    /**
     * 1.191.1: pad-checks met een verse meting meteen beoordelen (anders bleef een nieuwe check tot het volgende
     * interval op "Wacht op de eerste meting"). Niet als die check in dit rapport al beoordeeld is: dan zou
     * "status bevestigen" dubbel tellen.
     */
    public static function runFresh(string $since): void
    {
        foreach (array_unique(self::$fresh) as $id) {
            $claim = q('UPDATE checks SET last_checked = NOW() WHERE id = ? AND enabled = 1 AND (last_checked IS NULL OR last_checked < ?)', [$id, $since]);
            if ($claim->rowCount() === 1) {
                Monitor::runCheckById($id);
            }
        }
        self::$fresh = [];
    }

    /** Pad-metingen, doorvoer en de iperf3-ontvanger van een rapport opslaan */
    public static function fromAgent(array $host, array $in): void
    {
        $hid = (int) $host['id'];
        if (isset($in['iperfs']) && is_array($in['iperfs'])) {
            $i = $in['iperfs'];
            self::put($hid, 'iperfs', ['started' => (int) ($i['started'] ?? 0), 'port' => (int) ($i['port'] ?? 0), 'err' => mb_substr((string) ($i['err'] ?? ''), 0, 120), 'at' => time()]);
        }
        if (!isset($in['path']) || !is_array($in['path'])) {
            return;
        }
        $mine = q("SELECT id FROM checks WHERE host_id = ? AND type = 'path'", [$hid])->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_slice($in['path'], 0, 20, true) as $id => $p) {
            if (!ctype_digit((string) $id) || !in_array((int) $id, array_map('intval', $mine), true) || !is_array($p)) {
                continue;
            }
            $id = (int) $id;
            if (isset($p['tp']) && is_array($p['tp'])) {
                $req = self::data($hid, 'pathreq:' . $id);
                if ($req && ($p['tp']['key'] ?? '') === $req['key']) {
                    self::put($hid, 'pathreq:' . $id, null);
                }
                $tp = ['at' => time(), 'to' => mb_substr((string) ($p['tp']['to'] ?? ''), 0, 60), 'name' => $req['name'] ?? '',
                    'mbit' => isset($p['tp']['mbit']) && is_numeric($p['tp']['mbit']) ? round((float) $p['tp']['mbit'], 1) : null,
                    'err' => mb_substr((string) ($p['tp']['err'] ?? ''), 0, 160)];
                self::put($hid, 'pathtp:' . $id, $tp);
                if ($tp['mbit'] !== null) {
                    q('INSERT INTO path_runs (check_id, at, kind, data) VALUES (?, NOW(), ?, ?)', [$id, 'tp', json_encode(['mbit' => $tp['mbit'], 'to' => $tp['to']])]);
                }
            }
            if (!empty($p['old']) || empty($p['at'])) {
                continue;
            }
            $hops = [];
            foreach (array_slice((array) ($p['hops'] ?? []), 0, 40) as $h) {
                if (is_array($h)) {
                    $ip = (string) ($h['ip'] ?? '');
                    $hops[] = ['n' => (int) ($h['n'] ?? 0), 'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '', 'loss' => self::num($h['loss'] ?? null),
                        'avg' => self::num($h['avg'] ?? null), 'best' => self::num($h['best'] ?? null), 'worst' => self::num($h['worst'] ?? null), 'jit' => self::num($h['jit'] ?? null)];
                }
            }
            $ping = static fn($x) => is_array($x) && filter_var((string) ($x['ip'] ?? ''), FILTER_VALIDATE_IP) || (is_array($x) && self::okHost((string) ($x['ip'] ?? '')))
                ? ['ip' => (string) $x['ip'], 'loss' => self::num($x['loss'] ?? null), 'avg' => self::num($x['avg'] ?? null), 'best' => self::num($x['best'] ?? null),
                    'worst' => self::num($x['worst'] ?? null), 'jit' => self::num($x['jit'] ?? null)] : null;
            $d = [
                'at' => time(), 'tool' => in_array($p['tool'] ?? '', ['mtr', 'traceroute'], true) ? $p['tool'] : '', 'dst' => mb_substr((string) ($p['dst'] ?? ''), 0, 253),
                'dev' => mb_substr((string) ($p['dev'] ?? ''), 0, 32), 'src' => filter_var((string) ($p['src'] ?? ''), FILTER_VALIDATE_IP) ? $p['src'] : '',
                'gw' => filter_var((string) ($p['gw'] ?? ''), FILTER_VALIDATE_IP) ? $p['gw'] : '', 'speed' => isset($p['speed']) && is_numeric($p['speed']) && $p['speed'] > 0 ? (int) $p['speed'] : null,
                'virt' => preg_match('/^[a-z0-9-]{1,20}$/', (string) ($p['virt'] ?? '')) ? (string) $p['virt'] : '', 'hops' => $hops,
                'via' => array_values(array_filter(array_map($ping, array_slice((array) ($p['via'] ?? []), 0, 6)))), 'ping' => $ping($p['ping'] ?? null),
                'lldp' => is_array($p['lldp'] ?? null) ? ['sys' => mb_substr((string) ($p['lldp']['sys'] ?? ''), 0, 80), 'port' => mb_substr((string) ($p['lldp']['port'] ?? ''), 0, 80)] : null,
            ];
            $prev = self::data($hid, 'path:' . $id);
            self::put($hid, 'path:' . $id, $d);
            self::$fresh[] = $id;   // 1.191.1: meteen beoordelen, niet wachten op het volgende interval
            q('INSERT INTO path_runs (check_id, at, kind, data) VALUES (?, NOW(), ?, ?)', [$id, 'run', json_encode(['h' => array_map(static fn($h) => [$h['ip'], $h['avg'], $h['loss']], $hops),
                'v' => array_map(static fn($v) => [$v['ip'], $v['avg'], $v['loss']], $d['via']), 'd' => $d['ping'] ? [$d['ping']['avg'], $d['ping']['loss']] : null])]);
            // Pad gewijzigd: een andere reeks (bekende) hops dan de vorige meting
            $sig = static fn($x) => implode('>', array_filter(array_map(static fn($h) => $h['ip'], (array) ($x['hops'] ?? []))));
            if ($prev && $sig($prev) !== '' && $sig($d) !== '' && $sig($prev) !== $sig($d) && count((array) $prev['hops']) > 0) {
                $diff = self::diff((array) $prev['hops'], $hops);
                self::event($id, 'path', $diff);
                $c = q('SELECT c.*, h.name AS host_name FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
                if ($c && self::opts($c)['change']) {
                    Monitor::sysInfo('path_change', $hid, (string) $c['host_name'], '🛣️ ' . $c['host_name'] . ' – ' . $c['name'], fn() => t('Pad gewijzigd: {d}', ['d' => $diff]));
                }
            }
        }
        q('DELETE FROM path_runs WHERE at < NOW() - INTERVAL 3 DAY');
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private static function diff(array $a, array $b): string
    {
        $out = [];
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $x = $a[$i]['ip'] ?? '';
            $y = $b[$i]['ip'] ?? '';
            if ($x !== $y && ($x !== '' || $y !== '')) {
                $out[] = t('hop {n}: {a} → {b}', ['n' => $i + 1, 'a' => $x ?: '?', 'b' => $y ?: '?']);
            }
        }
        return implode(', ', array_slice($out, 0, 3)) ?: t('andere route');
    }

    public static function event(int $checkId, string $kind, string $text): void
    {
        q('INSERT INTO path_events (check_id, at, kind, text) VALUES (?, NOW(), ?, ?)', [$checkId, $kind, mb_substr($text, 0, 300)]);
        q('DELETE FROM path_events WHERE check_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM path_events WHERE check_id = ? ORDER BY id DESC LIMIT 50) x)', [$checkId, $checkId]);
    }

    // ------------------------------------------------------------------ UniFi

    /** Switches en poorten uit UniFi, 5 minuten bewaard (null zonder UniFi met account) */
    public static function unifi(bool $fresh = false): ?array
    {
        $c = json_decode((string) setting('path_unifi_cache', ''), true);
        if (!$fresh && is_array($c) && (int) ($c['at'] ?? 0) > time() - 300) {
            return $c['d'];
        }
        $d = null;
        try {
            $d = NetLinks::unifiPorts();
        } catch (Throwable $e) {
            $d = ['switches' => [], 'clients' => [], 'error' => $e->getMessage()];
        }
        save_setting('path_unifi_cache', json_encode(['at' => time(), 'd' => $d]));
        return $d;
    }

    /** Waar zit dit IP in UniFi: switch, poort en snelheid, en de uplink van die switch */
    private static function unifiLink(?array $u, string $ip): ?array
    {
        if (!$u || $ip === '' || !isset($u['clients'][$ip])) {
            return null;
        }
        $cl = $u['clients'][$ip];
        $sw = $u['switches'][$cl['sw']] ?? null;
        if (!$sw) {
            return null;
        }
        $port = $sw['ports'][$cl['port']] ?? null;
        $upIdx = (int) ($sw['uplink']['local'] ?? 0);
        if (!$upIdx) {
            foreach ($sw['ports'] as $p) {
                if ($p['uplink']) {
                    $upIdx = $p['idx'];
                    break;
                }
            }
        }
        $up = $sw['ports'][$upIdx] ?? null;
        $upSpeed = $up['speed'] ?? (int) ($sw['uplink']['speed'] ?? 0);
        return ['switch' => $sw['name'], 'ip' => $sw['ip'], 'model' => $sw['model'], 'port' => $cl['port'], 'port_name' => $port['name'] ?? '',
            'speed' => $port['speed'] ?? null, 'errors' => $port ? $port['rx_err'] + $port['tx_err'] : null,
            'uplink_port' => $upIdx ?: null, 'uplink_speed' => $upSpeed ?: null, 'uplink_errors' => $up ? $up['rx_err'] + $up['tx_err'] : null,
            'uplink_to' => isset($u['switches'][$sw['uplink']['mac'] ?? '']) ? $u['switches'][$sw['uplink']['mac']]['name'] : ''];
    }

    // ------------------------------------------------------------------ beoordelen

    /**
     * Het pad met namen en per knooppunt een status, in de volgorde van links naar rechts:
     * bron, virtuele laag, switch (UniFi of LLDP), de opgegeven tussenliggende apparaten, de hops en het doel.
     * @return array<string, mixed>|null
     */
    public static function model(array $check, array $host): ?array
    {
        $d = self::data((int) $host['id'], 'path:' . $check['id']);
        if (!$d) {
            return null;
        }
        $o = self::opts($check);
        $u = self::unifi();
        $names = self::names();
        $tp = self::data((int) $host['id'], 'pathtp:' . $check['id']);
        $nodes = [];
        $nodes[] = ['kind' => 'src', 'name' => (string) $host['name'], 'ip' => $d['src'] ?: (string) $host['address'], 'speed' => $d['speed'], 'dev' => $d['dev'], 'st' => 'g'];
        if ($d['virt'] !== '' && $d['virt'] !== 'docker') {
            $parent = !empty($host['parent_id']) ? q('SELECT name FROM hosts WHERE id = ?', [$host['parent_id']])->fetchColumn() : false;
            $nodes[] = ['kind' => 'virt', 'name' => $parent ? $parent . ' · bridge' : t('virtuele laag'), 'ip' => $d['virt'], 'st' => 'v'];
        }
        $ul = self::unifiLink($u, $d['src'] ?: (string) $host['address']);
        $max = self::data((int) $host['id'], 'pathmax:' . $check['id']) ?: [];
        $slow = [];
        if ($ul) {
            $st = 'g';
            foreach (['speed' => 'port', 'uplink_speed' => 'uplink'] as $k => $lbl) {
                if (($ul[$k] ?? 0) > 0) {
                    if (($max[$lbl] ?? 0) > $ul[$k]) {
                        $st = 'w';
                        $slow[] = $lbl === 'port'
                            ? t('poort {p} van {s} staat op {a}, was {b}', ['p' => $ul['port'], 's' => $ul['switch'], 'a' => self::speed($ul[$k]), 'b' => self::speed($max[$lbl])])
                            : t('uplink van {s} (poort {p}) staat op {a}, was {b}', ['p' => $ul['uplink_port'], 's' => $ul['switch'], 'a' => self::speed($ul[$k]), 'b' => self::speed($max[$lbl])]);
                    } elseif ($ul[$k] < 1000 && ($max[$lbl] ?? 0) === 0) {
                        $st = 'w';
                        $slow[] = t('{w} van {s} staat op {a}', ['w' => $lbl === 'port' ? t('poort {p}', ['p' => $ul['port']]) : t('uplink'), 's' => $ul['switch'], 'a' => self::speed($ul[$k])]);
                    }
                }
            }
            $nodes[] = ['kind' => 'switch', 'name' => $ul['switch'], 'ip' => $ul['ip'], 'via' => 'unifi', 'port' => $ul['port'], 'speed' => $ul['speed'],
                'uplink_port' => $ul['uplink_port'], 'uplink_speed' => $ul['uplink_speed'], 'errors' => $ul['errors'], 'uplink_errors' => $ul['uplink_errors'], 'st' => $st];
        } elseif (!empty($d['lldp']['sys'])) {
            $nodes[] = ['kind' => 'switch', 'name' => $d['lldp']['sys'], 'ip' => '', 'via' => 'lldp', 'port' => $d['lldp']['port'], 'st' => 'g'];
        }
        // Linksnelheid van de eigen netwerkkaart
        if ($d['speed'] && ($max['nic'] ?? 0) > $d['speed']) {
            $nodes[0]['st'] = 'w';
            $slow[] = t('netwerkkaart {d} staat op {a}, was {b}', ['d' => $d['dev'], 'a' => self::speed($d['speed']), 'b' => self::speed($max['nic'])]);
        }
        $lw = $o['loss_warn'];
        $down = [];
        foreach ($d['via'] as $v) {
            $st = $v['loss'] !== null && $v['loss'] >= 100 ? 'c' : ($v['loss'] !== null && $v['loss'] >= $lw ? 'w' : 'g');
            if ($st === 'c') {
                $down[] = $names[$v['ip']] ?? $v['ip'];
            }
            $nodes[] = ['kind' => 'via', 'name' => $names[$v['ip']] ?? $v['ip'], 'ip' => $v['ip'], 'avg' => $v['avg'], 'loss' => $v['loss'], 'best' => $v['best'],
                'worst' => $v['worst'], 'jit' => $v['jit'], 'st' => $st];
        }
        $dst = $d['ping'];
        $hops = $d['hops'];
        $last = end($hops) ?: null;
        if ($last && $dst && $last['ip'] === $dst['ip']) {
            array_pop($hops);
        }
        // Verlies op een tussenhop telt alleen als de hops erna ook verliezen (providers beantwoorden pings met lage voorrang)
        $dstLoss = $dst['loss'] ?? null;
        foreach ($hops as $i => $h) {
            $priv = self::isPrivate($h['ip']);
            $later = array_slice($hops, $i + 1);
            $realLoss = $h['loss'] !== null && $h['loss'] >= $lw && ($dstLoss === null || $dstLoss >= $lw) && !array_filter($later, static fn($x) => ($x['loss'] ?? 100) < $lw);
            $st = $h['ip'] === '' ? 'h' : ($realLoss ? 'w' : ($priv ? 'g' : 'x'));
            $nodes[] = ['kind' => $h['ip'] === '' ? 'hidden' : ($priv ? ($h['ip'] === $d['gw'] ? 'router' : 'hop') : 'ext'), 'n' => $h['n'],
                'name' => $names[$h['ip']] ?? ($h['ip'] === '' ? t('geen antwoord') : $h['ip']), 'ip' => $h['ip'], 'avg' => $h['avg'], 'loss' => $h['loss'], 'best' => $h['best'],
                'worst' => $h['worst'], 'jit' => $h['jit'], 'intern' => $priv, 'st' => $st];
        }
        $w = $check['warn_threshold'] !== null ? (float) $check['warn_threshold'] : null;
        $cr = $check['crit_threshold'] !== null ? (float) $check['crit_threshold'] : null;
        $dSt = !$dst || $dst['loss'] === null || $dst['loss'] >= 100 ? 'c'
            : ($dst['loss'] >= $o['loss_crit'] || ($cr !== null && $dst['avg'] !== null && $dst['avg'] >= $cr) ? 'c'
            : ($dst['loss'] >= $lw || ($w !== null && $dst['avg'] !== null && $dst['avg'] >= $w) ? 'w' : 'g'));
        $nodes[] = ['kind' => 'dst', 'name' => $names[(string) ($dst['ip'] ?? $d['dst'])] ?? $d['dst'], 'ip' => $dst['ip'] ?? $d['dst'], 'avg' => $dst['avg'] ?? null,
            'loss' => $dst['loss'] ?? null, 'best' => $dst['best'] ?? null, 'worst' => $dst['worst'] ?? null, 'jit' => $dst['jit'] ?? null, 'st' => $dSt];
        // Hoogste snelheden onthouden (om een zakking te zien)
        $nm = ['nic' => max((int) ($max['nic'] ?? 0), (int) ($d['speed'] ?? 0)), 'port' => max((int) ($max['port'] ?? 0), (int) ($ul['speed'] ?? 0)),
            'uplink' => max((int) ($max['uplink'] ?? 0), (int) ($ul['uplink_speed'] ?? 0))];
        if ($nm != $max && array_filter($nm)) {
            self::put((int) $host['id'], 'pathmax:' . $check['id'], $nm);
        }
        $req = self::data((int) $host['id'], 'pathreq:' . $check['id']);
        return ['at' => $d['at'], 'tool' => $d['tool'], 'dst' => $d['dst'], 'nodes' => $nodes, 'slow' => $slow, 'down' => $down, 'tp' => $tp,
            'tp_busy' => $req !== null, 'hops' => count($d['hops']), 'intern' => count(array_filter($nodes, static fn($n) => in_array($n['kind'], ['virt', 'switch', 'via', 'router', 'hop'], true))),
            'unifi' => $u === null ? null : ($u['error'] ?? null ? ['error' => $u['error']] : ['ok' => true]), 'ul' => $ul, 'opts' => $o];
    }

    public static function speed(int|float $mbit): string
    {
        return $mbit >= 1000 ? I18n::dec((string) round($mbit / 1000, 1)) . ' Gbit' : (int) $mbit . ' Mbit';
    }

    /** Namen bij IP-adressen: servers in RzSense en apparaten uit de netwerkscan */
    private static function names(): array
    {
        static $n = null;
        if ($n !== null) {
            return $n;
        }
        $n = [];
        foreach (q("SELECT ip, COALESCE(NULLIF(name, ''), NULLIF(hostname, '')) AS nm FROM net_devices WHERE ip <> ''") as $r) {
            if ($r['nm']) {
                $n[$r['ip']] = (string) $r['nm'];
            }
        }
        foreach (q('SELECT address, name FROM hosts') as $r) {
            $n[(string) $r['address']] = (string) $r['name'];
        }
        return $n;
    }

    /** 1.190.0: soort knooppunt → één letter in path_sum (lichtspoor in de tegels) */
    private const KIND_LETTER = ['src' => 's', 'virt' => 'v', 'switch' => 'w', 'via' => 'p', 'hidden' => 'h', 'router' => 'r', 'hop' => 'o', 'ext' => 'e', 'dst' => 'd'];

    /** De check: [status, waarde (ms naar het doel), melding] plus vals en een korte samenvatting voor de tegels */
    public static function check(array $check, array $host): array
    {
        if ($host['mode'] !== 'agent') {
            return Checks::result(STATUS_UNKNOWN, null, t('Een pad-check meet vanaf een server met de agent'));
        }
        if ($stale = Checks::agentStale($host)) {
            return $stale;
        }
        $m = self::model($check, $host);
        if (!$m) {
            return Checks::result(STATUS_UNKNOWN, null, t('Wacht op de eerste meting (agent 1.78.0 of nieuwer nodig)'));
        }
        if ((int) $m['at'] < time() - max(600, 3 * (int) $check['interval_sec'] + 120)) {
            return Checks::result(STATUS_UNKNOWN, null, t('Geen verse meting van het pad sinds {t}', ['t' => date('d-m H:i', (int) $m['at'])]));
        }
        $o = $m['opts'];
        $dst = end($m['nodes']);
        $status = STATUS_OK;
        $why = [];
        $up = static function (int $s) use (&$status) { $status = max($status, $s); };
        if ($dst['st'] === 'c') {
            $up(STATUS_CRIT);
            $why[] = $dst['loss'] === null || $dst['loss'] >= 100 ? t('doel {d} onbereikbaar', ['d' => $m['dst']]) : t('{d}: {ms} ms, {l}% verlies', ['d' => $m['dst'], 'ms' => I18n::dec((string) $dst['avg']), 'l' => (int) $dst['loss']]);
        } elseif ($dst['st'] === 'w') {
            $up(STATUS_WARN);
            $why[] = t('{d}: {ms} ms, {l}% verlies', ['d' => $m['dst'], 'ms' => I18n::dec((string) ($dst['avg'] ?? 0)), 'l' => (int) ($dst['loss'] ?? 0)]);
        }
        if ($o['hop'] && $m['down']) {
            $up(STATUS_CRIT);
            $why[] = t('reageert niet: {l}', ['l' => implode(', ', $m['down'])]);
        }
        foreach ($m['nodes'] as $n) {
            if ($n['kind'] === 'via' && $n['st'] === 'w' && $o['hop']) {
                $up(STATUS_WARN);
                $why[] = t('{n}: {l}% verlies', ['n' => $n['name'], 'l' => (int) $n['loss']]);
            }
        }
        if ($o['link'] && $m['slow']) {
            $up(STATUS_WARN);
            $why[] = t('linksnelheid: {l}', ['l' => implode('; ', $m['slow'])]);
        }
        $tp = $m['tp'];
        $mbit = $tp && isset($tp['mbit']) && (int) ($tp['at'] ?? 0) > time() - max(7200, 2 * $o['tp_every']) ? (float) $tp['mbit'] : null;
        if ($mbit !== null && $o['tp'] && $mbit < $o['tp_min']) {
            $up(STATUS_WARN);
            $why[] = t('doorvoer {m} Mbit/s (onder {n})', ['m' => I18n::dec((string) round($mbit)), 'n' => $o['tp_min']]);
        }
        $ms = $dst['avg'] !== null ? (float) $dst['avg'] : null;
        $summary = t('{h} hops', ['h' => $m['hops']]) . ($ms !== null ? ' · ' . I18n::dec((string) round($ms, 1)) . ' ms' : '') . ' · ' . t('{l}% verlies', ['l' => (int) ($dst['loss'] ?? 100)])
            . ($mbit !== null ? ' · ' . I18n::dec((string) round($mbit)) . ' Mbit/s' : '');
        $msg = ($why ? implode(' · ', $why) . ' · ' : '') . $summary;
        $code = implode('', array_map(static fn($n) => $n['kind'] === 'dst' ? ($n['st'] === 'g' ? 'd' : $n['st']) : $n['st'], $m['nodes']));
        // 1.190.0: per knooppunt ook de soort (k), voor de labels in het lichtspoor; past het niet in 250 tekens, dan eerst de uitleg korter
        $kinds = implode('', array_map(static fn($n) => self::KIND_LETTER[$n['kind']] ?? 'o', $m['nodes']));
        $sumOf = static fn(int $wl, bool $k) => json_encode(['d' => mb_substr($code, 0, 40), 'ms' => $ms !== null ? round($ms, 1) : null, 'h' => $m['hops'], 'mb' => $mbit !== null ? round($mbit) : null,
            'w' => mb_substr($why[0] ?? '', 0, $wl), 't' => mb_substr($m['dst'], 0, 40), 'l' => isset($dst['loss']) ? (int) $dst['loss'] : null] + ($k ? ['k' => mb_substr($kinds, 0, 40)] : []), JSON_UNESCAPED_UNICODE);
        $sum = $sumOf(70, true);
        foreach ([[30, true], [0, true], [30, false]] as [$wl, $k]) {
            if (strlen((string) $sum) <= 250) {
                break;
            }
            $sum = $sumOf($wl, $k);
        }
        return ['status' => $status, 'value' => $ms, 'message' => $msg, 'vals' => array_filter(['ms' => $ms, 'loss' => $dst['loss'] ?? null, 'mbit' => $mbit], static fn($v) => $v !== null) ?: null,
            'path_sum' => strlen((string) $sum) <= 250 ? $sum : null];
    }

    // ------------------------------------------------------------------ voor de interface

    /** Popup: het pad, per hop de 24 uur, de doorvoer per uur en wat er veranderde */
    public static function info(int $checkId): ?array
    {
        $c = q('SELECT c.*, h.name AS host_name FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ? AND c.type = \'path\'', [$checkId])->fetch();
        if (!$c) {
            return null;
        }
        $host = q('SELECT * FROM hosts WHERE id = ?', [$c['host_id']])->fetch();
        $m = self::model($c, $host);
        // Per hop (op IP) de vertraging per uur over 24 uur
        $hist = [];
        $tp = [];
        foreach (q('SELECT UNIX_TIMESTAMP(at) AS t, kind, data FROM path_runs WHERE check_id = ? AND at >= NOW() - INTERVAL 1 DAY ORDER BY at', [$checkId]) as $r) {
            $x = json_decode((string) $r['data'], true) ?: [];
            $slot = (int) floor((time() - (int) $r['t']) / 3600);
            $slot = 23 - min(23, max(0, $slot));
            if ($r['kind'] === 'tp') {
                $tp[] = ['t' => (int) $r['t'], 'mbit' => (float) ($x['mbit'] ?? 0)];
                continue;
            }
            foreach (array_merge((array) ($x['h'] ?? []), (array) ($x['v'] ?? [])) as $h) {
                if (($h[0] ?? '') === '') {
                    continue;
                }
                $hist[$h[0]][$slot][] = [$h[1], $h[2]];
            }
            if (!empty($x['d'])) {
                $hist['dst'][$slot][] = [$x['d'][0], $x['d'][1]];
            }
        }
        $lw = self::opts($c)['loss_warn'];
        $bars = [];
        foreach ($hist as $ip => $slots) {
            $b = array_fill(0, 24, null);
            foreach ($slots as $s => $vals) {
                $loss = max(array_map(static fn($v) => (float) ($v[1] ?? 0), $vals));
                $b[$s] = $loss >= 100 ? 2 : ($loss >= $lw ? 1 : 0);
            }
            $bars[$ip] = $b;
        }
        $ev = q('SELECT at, kind, text FROM path_events WHERE check_id = ? ORDER BY id DESC LIMIT 10', [$checkId])->fetchAll();
        $srv = null;
        $req = self::data((int) $c['host_id'], 'pathreq:' . $checkId);
        if ($req) {
            $srv = self::data((int) $req['host'], 'iperfs');
        }
        $caps = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'caps'", [$c['host_id']])->fetchColumn(), true) ?: [];
        return ['check' => ['id' => (int) $c['id'], 'name' => $c['name'], 'host' => $c['host_name'], 'target' => $c['target']], 'model' => $m, 'bars' => $bars, 'tp_hist' => $tp,
            'events' => $ev, 'req' => $req, 'srv' => $srv, 'caps' => array_values(array_intersect(['mtr', 'traceroute', 'iperf3', 'lldpctl'], $caps))];
    }

    /** Monitoring → Netwerkpaden: alle pad-checks met hun pad, en de switchpoorten uit UniFi */
    public static function overview(): array
    {
        $paths = [];
        foreach (q("SELECT c.*, h.name AS host_name FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.type = 'path' ORDER BY h.name, c.name") as $c) {
            $host = q('SELECT * FROM hosts WHERE id = ?', [$c['host_id']])->fetch();
            $m = $host ? self::model($c, $host) : null;
            $paths[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'host_id' => (int) $c['host_id'], 'host' => $c['host_name'], 'target' => $c['target'],
                'status' => (int) $c['status'], 'enabled' => (int) $c['enabled'], 'value' => $c['last_value'] !== null ? (float) $c['last_value'] : null, 'message' => $c['last_message'], 'model' => $m];
        }
        $u = self::unifi();
        $ports = [];
        foreach (($u['switches'] ?? []) as $sw) {
            foreach ($sw['ports'] as $p) {
                if (!$p['up']) {
                    continue;
                }
                $to = '';
                foreach ($u['clients'] as $ip => $cl) {
                    if ($cl['sw'] === $sw['mac'] && $cl['port'] === $p['idx']) {
                        $to = ($cl['name'] ?: $ip) . ($to ? ' …' : '');
                    }
                }
                if ($p['uplink']) {
                    $to = t('uplink') . ($sw['uplink']['mac'] && isset($u['switches'][$sw['uplink']['mac']]) ? ' → ' . $u['switches'][$sw['uplink']['mac']]['name'] : '');
                }
                $ports[] = ['switch' => $sw['name'], 'port' => $p['idx'], 'name' => $p['name'], 'speed' => $p['speed'], 'full' => $p['full'], 'errors' => $p['rx_err'] + $p['tx_err'],
                    'uplink' => $p['uplink'], 'to' => $to];
            }
        }
        usort($ports, static fn($a, $b) => $a['speed'] <=> $b['speed'] ?: $b['errors'] <=> $a['errors']);
        return ['paths' => $paths, 'ports' => array_slice($ports, 0, 60), 'unifi' => $u === null ? 'off' : (($u['error'] ?? null) ? $u['error'] : 'ok'),
            'changes7' => (int) q("SELECT COUNT(*) FROM path_events WHERE kind = 'path' AND at >= NOW() - INTERVAL 7 DAY")->fetchColumn()];
    }
}
