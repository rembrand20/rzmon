<?php
/**
 * RzMon REST API v1 (alleen lezen)
 *
 * Authenticatie: header "Authorization: Bearer <sleutel>" of "X-API-Key: <sleutel>"
 * (of ?key=<sleutel>, minder veilig omdat het in logs belandt).
 *
 * Endpoints (pad via PATH_INFO of ?r=):
 *   GET /v1.php/status                     samenvatting, algehele gezondheid + status per host
 *   GET /v1.php/hosts                      alle hosts met hun checks
 *   GET /v1.php/hosts/{id}                 één host met checks
 *   GET /v1.php/checks                     alle checks  (?status=0-3, ?type=disk, ?host_id=1)
 *   GET /v1.php/checks/{id}                één check
 *   GET /v1.php/checks/{id}/history        meetwaarden (?hours=24, max 720)
 *   GET /v1.php/alerts                     meldingen (?limit=50, max 500)
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, X-API-Key');

function respond($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (!is_configured()) {
    respond(['error' => 'RzMon is nog niet ingesteld'], 503);
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    respond(null, 204);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Alleen GET wordt ondersteund'], 405);
}

// ---------- Authenticatie ----------
$key = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m)) {
    $key = $m[1];
}
$key = $key ?: ($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ''));
$apiKey = $key !== '' ? q('SELECT id FROM api_keys WHERE key_hash = ?', [hash('sha256', $key)])->fetch() : false;
if (!$apiKey) {
    usleep(300000);
    respond(['error' => 'Ongeldige of ontbrekende API-sleutel'], 401);
}
q('UPDATE api_keys SET last_used = ? WHERE id = ?', [now(), $apiKey['id']]);

// ---------- Helpers ----------
function iso(?string $dt): ?string
{
    return $dt ? date('c', strtotime($dt)) : null;
}

function fmtCheck(array $c): array
{
    return [
        'id' => (int) $c['id'],
        'host_id' => (int) $c['host_id'],
        'host' => $c['host_name'],
        'name' => $c['name'],
        'type' => $c['type'],
        'target' => $c['target'] !== '' ? $c['target'] : null,
        'enabled' => (bool) $c['enabled'],
        'status' => (int) $c['status'],
        'status_text' => STATUS_LABELS[(int) $c['status']],
        'value' => $c['last_value'] !== null ? (float) $c['last_value'] : null,
        'unit' => check_unit($c),
        'metric' => $c['metric'],
        'message' => $c['last_message'],
        'warn_threshold' => $c['warn_threshold'] !== null ? (float) $c['warn_threshold'] : null,
        'crit_threshold' => $c['crit_threshold'] !== null ? (float) $c['crit_threshold'] : null,
        'interval_sec' => (int) $c['interval_sec'],
        'fail_count' => (int) $c['fail_count'],
        'last_checked' => iso($c['last_checked']),
        'in_maintenance' => ($m = Monitor::maintenanceFor($c)) !== null,
        'maintenance_until' => $m && empty($m['auto']) ? iso($m['until']) : null,
        'maintenance_auto' => !empty($m['auto']),   // automatisch na herhaalde meldingen, tot opgelost
        'suppressed_by' => $c['suppressed_by'] !== null ? (int) $c['suppressed_by'] : null,
        'problem_since' => iso($c['problem_since']),
        'acknowledged' => $c['acked_at'] !== null,
        'acknowledged_at' => iso($c['acked_at']),
        'acknowledged_by' => $c['acked_by'],
        'ack_comment' => $c['ack_comment'],
    ];
}

/** Ergste status van actieve checks (CRIT > WARN > UNKNOWN > OK); checks in onderhoud tellen niet mee. */
function worst(array $checks): int
{
    $rank = [STATUS_OK => 0, STATUS_UNKNOWN => 1, STATUS_WARN => 2, STATUS_CRIT => 3];
    $worst = null;
    $maintenance = false;
    foreach ($checks as $c) {
        $maintenance = $maintenance || ($c['enabled'] && $c['in_maintenance']);
        if ($c['enabled'] && !$c['in_maintenance'] && ($worst === null || $rank[$c['status']] > $rank[$worst])) {
            $worst = $c['status'];
        }
    }
    // Alles in onderhoud = bewust stil, geen onbekende toestand
    return $worst ?? ($maintenance ? STATUS_OK : STATUS_UNKNOWN);
}

function loadChecks(string $where = '1', array $params = []): array
{
    $rows = q("SELECT c.*, h.name AS host_name, h.maintenance_until AS host_maintenance_until,
                      h.maintenance_reason AS host_maintenance_reason FROM checks c JOIN hosts h ON h.id = c.host_id
               WHERE $where ORDER BY h.name, c.name", $params)->fetchAll();
    return array_map('fmtCheck', $rows);
}

function loadHosts(?int $id = null): array
{
    $hosts = q('SELECT id, name, address, mode, parent_id, enabled, agent_seen, agent_version, maintenance_until FROM hosts' . ($id ? ' WHERE id = ?' : '') . ' ORDER BY name',
        $id ? [$id] : [])->fetchAll();
    $checks = loadChecks($id ? 'c.host_id = ?' : '1', $id ? [$id] : []);
    $uptime = Stats::uptime();
    $out = [];
    foreach ($hosts as $h) {
        $hc = array_values(array_filter($checks, fn($c) => $c['host_id'] === (int) $h['id']));
        $st = worst($hc);
        $out[] = [
            'id' => (int) $h['id'],
            'name' => $h['name'],
            'address' => $h['address'],
            'mode' => $h['mode'],
            'enabled' => (bool) $h['enabled'],
            'status' => $st,
            'status_text' => STATUS_LABELS[$st],
            'agent_seen' => iso($h['agent_seen']),
            'agent_version' => $h['agent_version'],
            'parent_id' => $h['parent_id'] !== null ? (int) $h['parent_id'] : null,
            'in_maintenance' => ($m = Monitor::globalMaintenance()
                ?? ($h['maintenance_until'] && strtotime($h['maintenance_until']) > time() ? ['until' => $h['maintenance_until']] : null)) !== null,
            'maintenance_until' => $m ? iso($m['until']) : null,
            'uptime' => $uptime[(int) $h['id']] ?? null,   // % bereikbaar: h24, d7, d30
            'checks' => $hc,
        ];
    }
    return $out;
}

// ---------- Routing ----------
$path = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    // Sommige webserverconfiguraties (bv. Nginx met try_files) geven geen PATH_INFO door:
    // dan het pad zelf uit de URL halen (/v1.php/checks/3 → /checks/3)
    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && str_starts_with($uri, $script . '/')) {
        $path = substr($uri, strlen($script));
    }
}
$path = trim($path !== '' ? $path : (string) ($_GET['r'] ?? ''), '/');
$parts = $path === '' ? [] : explode('/', $path);

switch (true) {
    case $parts === [] || $parts === ['status']:
        $hosts = loadHosts();
        $counts = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0, 'maintenance' => 0, 'acknowledged' => 0];
        $keys = ['ok', 'warning', 'critical', 'unknown'];
        $all = [];
        $enabled = [];
        foreach ($hosts as $h) {
            if (!$h['enabled']) {
                continue;
            }
            foreach ($h['checks'] as $c) {
                if ($c['enabled']) {
                    $enabled[] = $c;
                }
                if ($c['enabled'] && $c['in_maintenance']) {
                    $counts['maintenance']++;
                } elseif ($c['enabled']) {
                    $counts[$keys[$c['status']]]++;
                    $counts['acknowledged'] += $c['acknowledged'] ? 1 : 0;
                    $all[] = $c;
                }
            }
        }
        $overall = worst($enabled);
        respond([
            'app' => 'RzMon',
            'version' => RZMON_VERSION,
            'generated_at' => date('c'),
            'status' => $overall,
            'status_text' => STATUS_LABELS[$overall],
            'counts' => $counts,
            'health' => (function (array $hl): array {   // algehele gezondheid: state, score (0-100), tellers
                if ($hl['oldest_problem']) {
                    $hl['oldest_problem']['since'] = iso($hl['oldest_problem']['since']);
                }
                return $hl;
            })(Health::current(Stats::uptime())),
            'maintenance' => ($g = Monitor::globalMaintenance()) ? ['until' => iso($g['until']), 'reason' => $g['reason'] ?: null] : null,
            'problems' => array_values(array_filter($all, fn($c) => $c['status'] !== STATUS_OK)),
            'hosts' => array_map(fn($h) => array_diff_key($h, ['checks' => 1]), $hosts),
        ]);

    case $parts === ['hosts']:
        respond(loadHosts());

    case count($parts) === 2 && $parts[0] === 'hosts' && ctype_digit($parts[1]):
        $h = loadHosts((int) $parts[1]);
        $h ? respond($h[0]) : respond(['error' => 'Host niet gevonden'], 404);

    case $parts === ['checks']:
        $where = ['1'];
        $params = [];
        if (isset($_GET['status']) && ctype_digit((string) $_GET['status'])) {
            $where[] = 'c.status = ?';
            $params[] = (int) $_GET['status'];
        }
        if (!empty($_GET['type'])) {
            $where[] = 'c.type = ?';
            $params[] = (string) $_GET['type'];
        }
        if (!empty($_GET['host_id'])) {
            $where[] = 'c.host_id = ?';
            $params[] = (int) $_GET['host_id'];
        }
        respond(loadChecks(implode(' AND ', $where), $params));

    case count($parts) === 2 && $parts[0] === 'checks' && ctype_digit($parts[1]):
        $c = loadChecks('c.id = ?', [(int) $parts[1]]);
        $c ? respond($c[0]) : respond(['error' => 'Check niet gevonden'], 404);

    case count($parts) === 3 && $parts[0] === 'checks' && ctype_digit($parts[1]) && $parts[2] === 'history':
        $id = (int) $parts[1];
        if (!loadChecks('c.id = ?', [$id])) {
            respond(['error' => 'Check niet gevonden'], 404);
        }
        $hours = min(720, max(1, (int) ($_GET['hours'] ?? 24)));
        $rows = q('SELECT created_at, status, value FROM results
                   WHERE check_id = ? AND created_at >= NOW() - INTERVAL ? HOUR ORDER BY created_at', [$id, $hours])->fetchAll();
        respond([
            'check_id' => $id,
            'hours' => $hours,
            'results' => array_map(fn($r) => [
                'time' => iso($r['created_at']),
                'status' => (int) $r['status'],
                'value' => $r['value'] !== null ? (float) $r['value'] : null,
            ], $rows),
        ]);

    case $parts === ['alerts']:
        $limit = min(500, max(1, (int) ($_GET['limit'] ?? 50)));
        $rows = q("SELECT a.id, a.check_id, a.status, a.title, a.message, a.sent_ok, a.created_at
                   FROM alerts a ORDER BY a.id DESC LIMIT $limit")->fetchAll();
        respond(array_map(fn($a) => [
            'id' => (int) $a['id'],
            'check_id' => $a['check_id'] !== null ? (int) $a['check_id'] : null,
            'status' => (int) $a['status'],
            'status_text' => STATUS_LABELS[(int) $a['status']],
            'title' => $a['title'],
            'message' => $a['message'],
            'pushover_sent' => (bool) $a['sent_ok'],
            'time' => iso($a['created_at']),
        ], $rows));

    default:
        respond(['error' => 'Onbekend endpoint', 'endpoints' => [
            '/status', '/hosts', '/hosts/{id}', '/checks', '/checks/{id}', '/checks/{id}/history', '/alerts',
        ]], 404);
}
