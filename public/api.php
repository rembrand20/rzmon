<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array
{
    static $in = null;
    return $in ??= (json_decode((string) file_get_contents('php://input'), true) ?: []);
}

function num($v): ?float
{
    return ($v === '' || $v === null) ? null : (float) $v;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if (!is_configured()) {
    out(['error' => 'RzMon is nog niet ingesteld', 'setup' => true], 503);
}

// ---------- Agent endpoint (token-auth, geen sessie) ----------
if ($action === 'agent') {
    $token = $_SERVER['HTTP_X_AGENT_TOKEN'] ?? '';
    $host = $token !== '' ? q("SELECT id FROM hosts WHERE agent_token = ? AND mode = 'agent'", [$token])->fetch() : false;
    if (!$host) {
        out(['error' => 'Ongeldig token'], 403);
    }
    $in = input();
    $now = now();
    $st = db()->prepare('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)');
    foreach (($in['disks'] ?? []) as $d) {
        if (!empty($d['mount'])) {
            $st->execute([$host['id'], mb_substr('disk:' . $d['mount'], 0, 190),
                json_encode(['total' => (float) ($d['total'] ?? 0), 'avail' => (float) ($d['avail'] ?? 0)]), $now]);
        }
    }
    if (isset($in['cpu']['pct'])) {
        $st->execute([$host['id'], 'cpu', json_encode([
            'pct' => (float) $in['cpu']['pct'],
            'load' => array_map('floatval', array_slice((array) ($in['cpu']['load'] ?? []), 0, 3)),
            'cores' => (int) ($in['cpu']['cores'] ?? 0),
        ]), $now]);
    }
    if (!empty($in['mem']['total'])) {
        $st->execute([$host['id'], 'mem', json_encode(array_map('floatval', array_intersect_key(
            (array) $in['mem'], array_flip(['total', 'avail', 'swap_total', 'swap_free'])))), $now]);
    }
    if (isset($in['ports']) && is_array($in['ports'])) {
        $ports = [];
        foreach (array_slice($in['ports'], 0, 500) as $lp) {
            $port = (int) ($lp['port'] ?? 0);
            if ($port > 0 && $port < 65536) {
                $ports[] = ['port' => $port, 'addr' => mb_substr((string) ($lp['addr'] ?? ''), 0, 64),
                    'proc' => mb_substr((string) ($lp['proc'] ?? ''), 0, 64)];
            }
        }
        $st->execute([$host['id'], 'ports', json_encode($ports), $now]);
    }
    foreach (($in['services'] ?? []) as $name => $state) {
        $st->execute([$host['id'], mb_substr('service:' . $name, 0, 190), mb_substr((string) $state, 0, 255), $now]);
    }
    q('DELETE FROM agent_data WHERE host_id = ? AND updated_at < ?', [$host['id'], $now]);
    q('UPDATE hosts SET agent_seen = ? WHERE id = ?', [$now, $host['id']]);
    out(['ok' => true]);
}

// ---------- Login ----------
if ($action === 'login' && $method === 'POST') {
    $in = input();
    if (login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''))) {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    out(['error' => 'Onjuiste gebruikersnaam of wachtwoord'], 401);
}

$user = current_user();
if (!$user) {
    out(['error' => 'Niet ingelogd'], 401);
}
if ($method !== 'GET' && !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    out(['error' => 'Ongeldig CSRF token'], 403);
}

switch ($action) {
    case 'logout':
        logout();
        out(['ok' => true]);

    case 'state':
        $hosts = q('SELECT id, name, address, mode, agent_token, agent_seen, enabled FROM hosts ORDER BY name')->fetchAll();
        $checks = q('SELECT * FROM checks ORDER BY name')->fetchAll();
        $agent = q("SELECT host_id, metric FROM agent_data")->fetchAll();
        $byHost = [];
        foreach ($checks as $c) {
            $byHost[$c['host_id']][] = $c;
        }
        $metrics = [];
        foreach ($agent as $a) {
            $metrics[$a['host_id']][] = $a['metric'];
        }
        foreach ($hosts as &$h) {
            $h['checks'] = $byHost[$h['id']] ?? [];
            $h['metrics'] = $metrics[$h['id']] ?? [];
        }
        unset($h);
        $alerts = q('SELECT a.*, c.name AS check_name FROM alerts a LEFT JOIN checks c ON c.id = a.check_id
                     ORDER BY a.id DESC LIMIT 50')->fetchAll();
        $lastRun = q('SELECT MAX(last_checked) FROM checks')->fetchColumn();
        out(['hosts' => $hosts, 'alerts' => $alerts, 'last_run' => $lastRun, 'user' => $user['username'],
             'base_url' => config('base_url', ''), 'lan_session' => is_lan_session()]);

    case 'history':
        $id = (int) ($_GET['check_id'] ?? 0);
        $hours = min(24 * 30, max(1, (int) ($_GET['hours'] ?? 24)));
        $rows = q('SELECT UNIX_TIMESTAMP(created_at) AS t, status AS s, value AS v FROM results
                   WHERE check_id = ? AND created_at >= NOW() - INTERVAL ? HOUR ORDER BY created_at', [$id, $hours])->fetchAll();
        // Downsamplen naar max ~300 punten
        $step = max(1, (int) ceil(count($rows) / 300));
        $pts = [];
        for ($i = 0; $i < count($rows); $i += $step) {
            $slice = array_slice($rows, $i, $step);
            $vals = array_filter(array_column($slice, 'v'), fn($v) => $v !== null);
            $pts[] = [
                't' => (int) $slice[0]['t'],
                's' => max(array_map('intval', array_column($slice, 's'))),
                'v' => $vals ? array_sum($vals) / count($vals) : null,
            ];
        }
        $up = $rows ? count(array_filter($rows, fn($r) => (int) $r['s'] !== STATUS_CRIT)) / count($rows) * 100 : null;
        out(['points' => $pts, 'uptime' => $up]);

    case 'host_save':
        $in = input();
        $mode = in_array($in['mode'] ?? '', ['local', 'agent', 'remote'], true) ? $in['mode'] : 'remote';
        $name = trim((string) ($in['name'] ?? ''));
        $addr = trim((string) ($in['address'] ?? ''));
        if ($name === '' || $addr === '' || !preg_match('/^[A-Za-z0-9.:\-\[\]]+$/', $addr)) {
            out(['error' => 'Naam en geldig adres (hostname/IP) zijn verplicht'], 422);
        }
        $enabled = !empty($in['enabled']) ? 1 : 0;
        if (!empty($in['id'])) {
            q('UPDATE hosts SET name = ?, address = ?, mode = ?, enabled = ? WHERE id = ?', [$name, $addr, $mode, $enabled, (int) $in['id']]);
            $id = (int) $in['id'];
        } else {
            q('INSERT INTO hosts (name, address, mode, enabled) VALUES (?, ?, ?, ?)', [$name, $addr, $mode, $enabled]);
            $id = (int) db()->lastInsertId();
            if (!empty($in['add_ping'])) {
                q("INSERT INTO checks (host_id, type, name, warn_threshold, crit_threshold) VALUES (?, 'ping', 'Ping', 100, 500)", [$id]);
            }
        }
        if ($mode === 'agent') {
            q('UPDATE hosts SET agent_token = COALESCE(agent_token, ?) WHERE id = ?', [bin2hex(random_bytes(20)), $id]);
        }
        out(['ok' => true, 'id' => $id]);

    case 'host_token':
        q('UPDATE hosts SET agent_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), (int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'host_delete':
        q('DELETE FROM hosts WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'check_save':
        $in = input();
        $type = $in['type'] ?? '';
        if (!in_array($type, ['ping', 'tcp', 'http', 'disk', 'service', 'cpu', 'ram'], true)) {
            out(['error' => 'Ongeldig type'], 422);
        }
        $target = trim((string) ($in['target'] ?? ''));
        if ($type === 'tcp' && ((int) $target < 1 || (int) $target > 65535)) {
            out(['error' => 'Geef een geldige poort op (1-65535)'], 422);
        }
        if ($type === 'service' && !preg_match('/^[A-Za-z0-9@._:-]+$/', $target)) {
            out(['error' => 'Geef een geldige servicenaam op'], 422);
        }
        $warn = num($in['warn_threshold'] ?? null);
        $crit = num($in['crit_threshold'] ?? null);
        if ($warn !== null && $crit !== null && $warn > $crit) {
            out(['error' => 'Warning-drempel moet lager zijn dan critical-drempel'], 422);
        }
        if (in_array($type, ['ping', 'cpu', 'ram'], true)) {
            $target = '';
        }
        $name = trim((string) ($in['name'] ?? '')) ?: ['ping' => 'Ping', 'tcp' => 'Poort', 'http' => 'HTTP', 'disk' => 'Disk', 'service' => 'Service',
            'cpu' => 'CPU', 'ram' => 'RAM'][$type] . ($target !== '' ? " $target" : '');
        $params = [$type, $target, mb_substr($name, 0, 100), $warn, $crit,
            max(30, (int) ($in['interval_sec'] ?? 60)), !empty($in['enabled']) ? 1 : 0];
        if (!empty($in['id'])) {
            q('UPDATE checks SET type = ?, target = ?, name = ?, warn_threshold = ?, crit_threshold = ?, interval_sec = ?, enabled = ? WHERE id = ?',
                [...$params, (int) $in['id']]);
            $id = (int) $in['id'];
        } else {
            q('INSERT INTO checks (type, target, name, warn_threshold, crit_threshold, interval_sec, enabled, host_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [...$params, (int) ($in['host_id'] ?? 0)]);
            $id = (int) db()->lastInsertId();
        }
        Monitor::runCheckById($id);
        out(['ok' => true, 'id' => $id]);

    case 'check_delete':
        q('DELETE FROM checks WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'check_run':
        $res = Monitor::runCheckById((int) (input()['id'] ?? 0));
        out(['ok' => $res !== null, 'result' => $res]);

    case 'run_all':
        out(['ok' => true, 'count' => Monitor::runDue(true)]);

    case 'settings':
        $s = settings();
        $s['pushover_token'] = $s['pushover_token'] !== '' ? '••••••••' : '';
        out($s);

    case 'settings_save':
        $in = input();
        $allowed = ['pushover_enabled', 'pushover_user', 'pushover_token', 'pushover_priority_crit', 'pushover_priority_warn',
            'pushover_sound', 'alert_after', 'repeat_minutes', 'notify_warn', 'notify_recovery', 'agent_stale_minutes', 'retention_days'];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            if ($k === 'pushover_token' && $v === '••••••••') {
                continue;
            }
            save_setting($k, $v);
        }
        out(['ok' => true]);

    case 'pushover_test':
        $in = input();
        $token = ($in['pushover_token'] ?? '') === '••••••••' || ($in['pushover_token'] ?? '') === ''
            ? (string) setting('pushover_token', '') : (string) $in['pushover_token'];
        $res = Pushover::send('🧪 RzMon test', 'Als je dit leest werkt Pushover! 🎉', 0,
            trim((string) ($in['pushover_user'] ?? '')) ?: null, $token ?: null);
        out($res, $res['ok'] ? 200 : 422);

    case 'access_info':
        out([
            'lan_no_login' => setting('lan_no_login', '0') === '1',
            'trusted_networks' => (string) setting('trusted_networks', ''),
            'trusted_proxies' => (string) setting('trusted_proxies', ''),
            'client_ip' => client_ip(),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'client_trusted' => ip_in_list(client_ip(), trusted_networks()),
            'lan_session' => is_lan_session(),
        ]);

    case 'access_save':
        $in = input();
        $networks = parse_list((string) ($in['trusted_networks'] ?? ''));
        $proxies = parse_list((string) ($in['trusted_proxies'] ?? ''));
        foreach (array_merge($networks, $proxies) as $cidr) {
            [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            $max = str_contains($ip, ':') ? 128 : 32;
            if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $max))) {
                out(['error' => "Ongeldig netwerk: $cidr (gebruik bv. 192.168.1.0/24 of 10.0.0.5)"], 422);
            }
        }
        $enable = !empty($in['lan_no_login']);
        if ($enable && !$networks) {
            out(['error' => 'Geef minimaal één lokaal netwerk op'], 422);
        }
        foreach ($networks as $cidr) {
            if (str_ends_with($cidr, '/0')) {
                out(['error' => "$cidr zou iedereen op internet zonder wachtwoord binnenlaten"], 422);
            }
        }
        save_setting('lan_no_login', $enable ? '1' : '0');
        save_setting('trusted_networks', implode("\n", $networks));
        save_setting('trusted_proxies', implode("\n", $proxies));
        out(['ok' => true]);

    case 'port_scan':
        $in = input();
        $host = q('SELECT id, address, mode FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$host) {
            out(['error' => 'Server niet gevonden'], 404);
        }
        try {
            $ports = PortScan::presetPorts((string) ($in['preset'] ?? 'common'), (string) ($in['ports'] ?? ''));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        if (!$ports) {
            out(['error' => 'Geen poorten opgegeven'], 422);
        }
        session_write_close(); // andere verzoeken niet blokkeren tijdens de scan
        set_time_limit(180);
        $start = microtime(true);
        try {
            $open = PortScan::scan($host['address'], $ports, max(0.3, min(5.0, (float) ($in['timeout'] ?? 1.5))));
        } catch (RuntimeException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        $monitored = array_map('intval', q("SELECT target FROM checks WHERE host_id = ? AND type = 'tcp'", [$host['id']])->fetchAll(PDO::FETCH_COLUMN));
        $listening = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'ports'", [$host['id']])->fetchColumn(), true) ?: [];
        foreach ($listening as &$lp) {
            $lp['service'] = PortScan::serviceName((int) $lp['port']);
        }
        unset($lp);
        out([
            'scanned' => count($ports),
            'seconds' => round(microtime(true) - $start, 2),
            'open' => $open,
            'monitored' => $monitored,
            'listening' => $host['mode'] === 'agent' ? $listening : null,
        ]);

    case 'api_keys':
        out(q('SELECT id, name, key_hint, last_used, created_at FROM api_keys ORDER BY id')->fetchAll());

    case 'api_key_create':
        $name = trim((string) (input()['name'] ?? ''));
        if ($name === '') {
            out(['error' => 'Geef de sleutel een naam (bv. "Home Assistant")'], 422);
        }
        $key = 'rzm_' . bin2hex(random_bytes(20));
        q('INSERT INTO api_keys (name, key_hash, key_hint) VALUES (?, ?, ?)', [
            mb_substr($name, 0, 100), hash('sha256', $key), substr($key, 0, 8) . '…',
        ]);
        out(['ok' => true, 'key' => $key]);

    case 'api_key_delete':
        q('DELETE FROM api_keys WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'password':
        $in = input();
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!$row || !password_verify((string) ($in['current'] ?? ''), $row['password_hash'])) {
            out(['error' => 'Huidig wachtwoord onjuist'], 422);
        }
        if (strlen((string) ($in['new'] ?? '')) < 8) {
            out(['error' => 'Nieuw wachtwoord moet minimaal 8 tekens zijn'], 422);
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $in['new'], PASSWORD_DEFAULT), $user['id']]);
        out(['ok' => true]);

    case 'agent_script':
        header('Content-Type: text/plain; charset=utf-8');
        readfile(SM_ROOT . '/agent/rzmon-agent.sh');
        exit;

    default:
        out(['error' => 'Onbekende actie'], 404);
}
