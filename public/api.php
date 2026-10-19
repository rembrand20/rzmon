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

// ---------- Agent en installatiescript (openbaar: bevatten niets geheims) ----------
// Zo kan een server de agent rechtstreeks van RzMon installeren, ook als de
// GitHub-repository privé is.
if ($action === 'ping') {
    // Voor de verbindingstest van de agent: "draait hier RzMon?"
    out(['ok' => true, 'app' => 'RzMon', 'configured' => is_configured(), 'time' => date('c')]);
}
if ($action === 'agent_script' || $action === 'agent_install') {
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(SM_ROOT . '/agent/' . ($action === 'agent_script' ? 'rzmon-agent.sh' : 'install-agent.sh'));
    exit;
}

if (!is_configured()) {
    out(['error' => 'RzMon is nog niet ingesteld', 'setup' => true], 503);
}

// ---------- Agent endpoint (token-auth, geen sessie) ----------
/** Zoekt de server bij een agent-token; bij een fout: logboek + 403 met de reden. */
function agent_host(): array
{
    $token = trim((string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''));
    $ip = client_ip();
    $host = $token !== '' ? q('SELECT id, name, mode FROM hosts WHERE agent_token = ?', [$token])->fetch() : false;
    $reason = match (true) {
        $token === '' => 'geen token meegestuurd',
        !$host => 'onbekend token (verkeerd gekopieerd, of in RzMon vernieuwd?)',
        $host['mode'] !== 'agent' => "server '{$host['name']}' staat niet op type 'Remote met agent'",
        default => null,
    };
    if ($reason !== null) {
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'] ?? null, mb_substr($ip, 0, 45), $reason]);
        q('DELETE FROM agent_log WHERE created_at < NOW() - INTERVAL 7 DAY');
        out(['error' => "Geweigerd: $reason", 'reason' => $reason], 403);
    }
    return $host;
}

// Alleen het token controleren, zonder gegevens te versturen (verbindingstest)
if ($action === 'agent_check') {
    $host = agent_host();
    out(['ok' => true, 'host' => $host['name'], 'your_ip' => client_ip()]);
}

if ($action === 'agent') {
    $host = agent_host();
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
    q('UPDATE hosts SET agent_seen = ?, agent_ip = ? WHERE id = ?', [$now, mb_substr(client_ip(), 0, 45), $host['id']]);
    out(['ok' => true, 'host' => $host['name'], 'received' => [
        'disks' => count($in['disks'] ?? []), 'services' => count($in['services'] ?? []), 'ports' => count($in['ports'] ?? []),
    ]]);
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
        $hosts = q('SELECT id, name, address, mode, parent_id, agent_token, agent_seen, enabled, maintenance_until, maintenance_reason
                    FROM hosts ORDER BY name')->fetchAll();
        $checks = q('SELECT c.*, h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                     FROM checks c JOIN hosts h ON h.id = c.host_id ORDER BY c.name')->fetchAll();
        $global = Monitor::globalMaintenance();
        $agent = q("SELECT host_id, metric FROM agent_data")->fetchAll();
        $byHost = [];
        foreach ($checks as $c) {
            // Effectief onderhoud (globaal, server of check) voor de weergave
            $c['maintenance'] = Monitor::maintenanceFor($c);
            unset($c['host_maintenance_until'], $c['host_maintenance_reason']);
            $byHost[$c['host_id']][] = $c;
        }
        $metrics = [];
        foreach ($agent as $a) {
            $metrics[$a['host_id']][] = $a['metric'];
        }
        foreach ($hosts as &$h) {
            $h['checks'] = $byHost[$h['id']] ?? [];
            $h['metrics'] = $metrics[$h['id']] ?? [];
            $own = $h['maintenance_until'] && strtotime($h['maintenance_until']) > time()
                ? ['scope' => 'host', 'until' => $h['maintenance_until'], 'reason' => (string) $h['maintenance_reason']] : null;
            $h['maintenance'] = $global ?? $own;
        }
        unset($h);
        $alerts = q('SELECT a.*, c.name AS check_name FROM alerts a LEFT JOIN checks c ON c.id = a.check_id
                     ORDER BY a.id DESC LIMIT 50')->fetchAll();
        $lastRun = q('SELECT MAX(last_checked) FROM checks')->fetchColumn();
        out(['hosts' => $hosts, 'alerts' => $alerts, 'last_run' => $lastRun, 'user' => $user['username'],
             'base_url' => config('base_url', ''), 'lan_session' => is_lan_session(), 'maintenance' => $global]);

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
        $parent = !empty($in['parent_id']) ? (int) $in['parent_id'] : null;
        if ($parent !== null) {
            if (!q('SELECT 1 FROM hosts WHERE id = ?', [$parent])->fetchColumn()) {
                out(['error' => 'Bovenliggende server bestaat niet'], 422);
            }
            // Geen kringen: de parent mag niet (indirect) onder deze server hangen
            for ($p = $parent, $i = 0; $p !== null && $i < 50; $i++) {
                if (!empty($in['id']) && $p === (int) $in['id']) {
                    out(['error' => 'Een server kan niet van zichzelf afhankelijk zijn (ook niet via een andere server)'], 422);
                }
                $next = q('SELECT parent_id FROM hosts WHERE id = ?', [$p])->fetchColumn();
                $p = $next ? (int) $next : null;
            }
        }
        if (!empty($in['id'])) {
            q('UPDATE hosts SET name = ?, address = ?, mode = ?, enabled = ?, parent_id = ? WHERE id = ?', [$name, $addr, $mode, $enabled, $parent, (int) $in['id']]);
            $id = (int) $in['id'];
        } else {
            q('INSERT INTO hosts (name, address, mode, enabled, parent_id) VALUES (?, ?, ?, ?, ?)', [$name, $addr, $mode, $enabled, $parent]);
            $id = (int) db()->lastInsertId();
            if (!empty($in['add_ping'])) {
                q("INSERT INTO checks (host_id, type, name, warn_threshold, crit_threshold) VALUES (?, 'ping', 'Ping', 100, 500)", [$id]);
            }
        }
        if ($mode === 'agent') {
            q('UPDATE hosts SET agent_token = COALESCE(agent_token, ?) WHERE id = ?', [bin2hex(random_bytes(20)), $id]);
        }
        out(['ok' => true, 'id' => $id]);

    case 'agent_status':
        $id = (int) ($_GET['id'] ?? 0);
        $h = q('SELECT agent_seen, agent_ip, mode FROM hosts WHERE id = ?', [$id])->fetch();
        if (!$h) {
            out(['error' => 'Server niet gevonden'], 404);
        }
        // Geweigerde pogingen (laatste 24 uur, en ná het laatste geslaagde rapport): voor deze
        // server of met een onbekend token. Gelijke pogingen samengevoegd met een aantal.
        $fails = q('SELECT ip, reason, MAX(created_at) AS created_at, COUNT(*) AS n FROM agent_log
                    WHERE (host_id = ? OR host_id IS NULL) AND created_at >= NOW() - INTERVAL 1 DAY
                      AND (? IS NULL OR created_at > ?)
                    GROUP BY ip, reason ORDER BY MAX(id) DESC LIMIT 6', [$id, $h['agent_seen'], $h['agent_seen']])->fetchAll();
        out(['seen' => $h['agent_seen'], 'ip' => $h['agent_ip'], 'mode' => $h['mode'], 'failures' => $fails, 'now' => now()]);

    case 'host_token':
        q('UPDATE hosts SET agent_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), (int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'host_delete':
        $id = (int) (input()['id'] ?? 0);
        // Afhankelijke servers hangen daarna aan de parent van de verwijderde server
        $grand = q('SELECT parent_id FROM hosts WHERE id = ?', [$id])->fetchColumn() ?: null;
        q('UPDATE hosts SET parent_id = ? WHERE parent_id = ?', [$grand, $id]);
        q('UPDATE checks SET suppressed_by = NULL WHERE suppressed_by = ?', [$id]);
        q('DELETE FROM hosts WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'ack':
        $in = input();
        if (!Monitor::acknowledge((int) ($in['id'] ?? 0), $user['username'], (string) ($in['comment'] ?? ''))) {
            out(['error' => 'Deze check heeft geen storing (meer)'], 422);
        }
        out(['ok' => true]);

    case 'ack_host':
        // Alle onbevestigde storingen van een server in één keer
        $in = input();
        $n = 0;
        foreach (q('SELECT id FROM checks WHERE host_id = ? AND enabled = 1 AND status <> 0 AND acked_at IS NULL', [(int) ($in['id'] ?? 0)])->fetchAll() as $c) {
            $n += Monitor::acknowledge((int) $c['id'], $user['username'], (string) ($in['comment'] ?? '')) ? 1 : 0;
        }
        out(['ok' => true, 'count' => $n]);

    case 'unack':
        Monitor::unacknowledge((int) (input()['id'] ?? 0));
        out(['ok' => true]);

    case 'maintenance_set':
        // scope: all | host | check ; minutes = 0 beëindigt het onderhoud
        $in = input();
        $scope = (string) ($in['scope'] ?? '');
        $minutes = (int) ($in['minutes'] ?? 0);
        if (!empty($in['until'])) {
            $ts = strtotime((string) $in['until']);
            if (!$ts || $ts <= time()) {
                out(['error' => 'Kies een eindtijd in de toekomst'], 422);
            }
            $minutes = (int) ceil(($ts - time()) / 60);
        }
        if ($minutes < 0 || $minutes > 60 * 24 * 90) {
            out(['error' => 'Onderhoud kan maximaal 90 dagen duren'], 422);
        }
        $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        $reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 200) ?: null;
        $id = (int) ($in['id'] ?? 0);
        match ($scope) {
            'all' => [save_setting('maintenance_until', (string) $until), save_setting('maintenance_reason', (string) $reason)],
            'host' => q('UPDATE hosts SET maintenance_until = ?, maintenance_reason = ? WHERE id = ?', [$until, $reason, $id]),
            'check' => q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ? WHERE id = ?', [$until, $reason, $id]),
            default => out(['error' => 'Onbekend bereik'], 422),
        };
        out(['ok' => true, 'until' => $until]);

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
        unset($s['ack_secret']);
        out($s);

    case 'settings_save':
        $in = input();
        $allowed = ['pushover_enabled', 'pushover_user', 'pushover_token', 'pushover_priority_crit', 'pushover_priority_warn',
            'pushover_sound', 'alert_after', 'repeat_minutes', 'notify_warn', 'notify_recovery', 'agent_stale_minutes', 'retention_days',
            'ha_webhook_enabled', 'ha_webhook_url', 'ha_webhook_verify_ssl', 'ha_webhook_events', 'notify_recovery_acked'];
        if (($in['ha_webhook_url'] ?? '') !== '' && !preg_match('#^https?://\S+$#i', trim((string) $in['ha_webhook_url']))) {
            out(['error' => 'Webhook-URL moet met http:// of https:// beginnen'], 422);
        }
        if (($in['ha_webhook_enabled'] ?? '0') === '1' && trim((string) ($in['ha_webhook_url'] ?? '')) === '') {
            out(['error' => 'Vul een webhook-URL in of zet de webhook uit'], 422);
        }
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

    case 'webhook_test':
        $in = input();
        $url = trim((string) ($in['ha_webhook_url'] ?? '')) ?: null;
        $verify = isset($in['ha_webhook_verify_ssl']) ? $in['ha_webhook_verify_ssl'] === '1' : null;
        $fake = ['id' => 0, 'hid' => 0, 'host_name' => 'RzMon', 'name' => 'Testmelding', 'type' => 'ping', 'target' => ''];
        $res = Webhook::send(Monitor::webhookPayload($fake, ['value' => null], STATUS_OK, '🧪 RzMon test',
            'Als je dit in Home Assistant ziet, werkt de webhook! 🎉', 'test', STATUS_OK), $url, $verify);
        out($res, $res['ok'] ? 200 : 422);

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

    default:
        out(['error' => 'Onbekende actie'], 404);
}
