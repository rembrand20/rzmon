<?php
/**
 * RzMon REST API v1
 *
 * Authenticatie: header "Authorization: Bearer <sleutel>" of "X-API-Key: <sleutel>"
 * (of ?key=<sleutel>, minder veilig omdat het in logs belandt).
 * Elke sleutel heeft rechten (Instellingen → API-sleutels): read (status lezen), ids_read (IDS lezen),
 * ids_block (IP's blokkeren/deblokkeren) en checks (bevestigen, onderhoud, nu checken); eventueel
 * "alleen vanaf" bepaalde IP's/netwerken, en een vervaldatum.
 *
 * Endpoints (pad via PATH_INFO of ?r=):
 *   GET /v1.php/status                     samenvatting, algehele gezondheid + status per host
 *   GET /v1.php/hosts                      alle hosts met hun checks
 *   GET /v1.php/hosts/{id}                 één host met checks
 *   GET /v1.php/checks                     alle checks  (?status=0-3, ?type=disk, ?host_id=1)
 *   GET /v1.php/checks/{id}                één check
 *   GET /v1.php/checks/{id}/history        meetwaarden (?hours=24, max 720)
 *   GET /v1.php/alerts                     meldingen (?limit=50, max 500)
 *   GET /v1.php/ids                        IDS-overzicht                                   [ids_read]
 *   GET /v1.php/ids/incidents              incidenten (?status=open, ?severity=2, ?host_id, ?limit)  [ids_read]
 *   GET /v1.php/ids/blocks                 actieve blokkades (?all=1: ook opgeheven/verlopen)      [ids_read]
 *   GET /v1.php/ids/access                 logins, sudo, su (?host_id, ?type=ssh, ?failed=1, ?limit) [ids_read]
 *   POST /v1.php/ids/block                 {"host"|"host_id", "ip", "hours" (0 = permanent), "reason"} [ids_block]
 *   POST /v1.php/ids/unblock               {"id"} of {"host"|"host_id", "ip"}               [ids_block]
 *   POST /v1.php/checks/{id}/ack           {"comment"}                                      [checks]
 *   POST /v1.php/checks/{id}/unack                                                          [checks]
 *   POST /v1.php/checks/{id}/maintenance   {"minutes" of "until", "reason"}; minutes 0 = einde  [checks]
 *   POST /v1.php/hosts/{id}/maintenance    idem, voor een hele server                       [checks]
 *   POST /v1.php/checks/{id}/run           nu checken                                       [checks]
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

function respond($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

I18n::init(null);
if (!is_configured()) {
    respond(['error' => t('RzMon is nog niet ingesteld')], 503);
}
I18n::set(I18n::notifyLang());   // de API spreekt de taal van meldingen en checkberichten
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    respond(null, 204);
}
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    respond(['error' => t('Alleen GET en POST worden ondersteund')], 405);
}

// ---------- Authenticatie ----------
$key = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '', $m)) {
    $key = $m[1];
}
$key = $key ?: ($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ''));
$apiKey = $key !== '' ? q('SELECT id, name, rights, allow_from, expires_at FROM api_keys WHERE key_hash = ?', [hash('sha256', $key)])->fetch() : false;
if (!$apiKey) {
    usleep(300000);
    respond(['error' => t('Ongeldige of ontbrekende API-sleutel')], 401);
}
if ($apiKey['expires_at'] !== null && strtotime($apiKey['expires_at']) <= time()) {
    respond(['error' => t('Deze API-sleutel is verlopen op {date}; verleng hem in RzMon (Instellingen → API-sleutels)', ['date' => date('d-m-Y', strtotime($apiKey['expires_at']))])], 401);
}
if ((string) $apiKey['allow_from'] !== '' && !ip_in_list(client_ip(), parse_list($apiKey['allow_from']))) {
    respond(['error' => t('Deze API-sleutel mag niet gebruikt worden vanaf {ip}', ['ip' => client_ip()])], 403);
}
q('UPDATE api_keys SET last_used = ? WHERE id = ?', [now(), $apiKey['id']]);
$rights = explode(',', (string) $apiKey['rights']);
/** Stopt met 403 als de sleutel dit recht niet heeft. */
function need(string $right): void
{
    global $rights, $apiKey;
    $label = t(['read' => 'Status lezen', 'ids_read' => 'IDS lezen', 'ids_block' => 'IDS blokkeren', 'checks' => 'Checks bedienen'][$right] ?? $right);
    if (!in_array($right, $rights, true)) {
        respond(['error' => t('Deze sleutel ({name}) heeft het recht "{right}" niet', ['name' => $apiKey['name'], 'right' => $label])], 403);
    }
}
/** Wie het deed, voor het logboek en de blokkadelijst */
$by = 'API: ' . $apiKey['name'];

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
        'status_streak' => (int) ($c['status_streak'] ?? 0),
        'notify_fails' => Monitor::failsNeeded($c),
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

// ---------- Schrijven (POST) ----------
if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : $_POST;
    /** Server uit "host_id" of "host" (naam, hoofdletters maken niet uit) */
    $hostOf = static function (array $b): ?array {
        if (!empty($b['host_id'])) {
            return q('SELECT id, name, mode FROM hosts WHERE id = ?', [(int) $b['host_id']])->fetch() ?: null;
        }
        if (isset($b['host']) && (string) $b['host'] !== '') {
            return q('SELECT id, name, mode FROM hosts WHERE LOWER(name) = LOWER(?)', [trim((string) $b['host'])])->fetch() ?: null;
        }
        return null;
    };
    /** Onderhoud: minuten of eindtijd; 0 minuten = einde. @return string|null eindtijd */
    $maintUntil = static function (array $b): ?string {
        $minutes = (int) ($b['minutes'] ?? 0);
        if (!empty($b['until'])) {
            $ts = strtotime((string) $b['until']);
            if (!$ts || $ts <= time()) {
                respond(['error' => t('Kies een eindtijd in de toekomst')], 422);
            }
            $minutes = (int) ceil(($ts - time()) / 60);
        }
        if ($minutes < 0 || $minutes > 60 * 24 * 90) {
            respond(['error' => t('Onderhoud kan maximaal 90 dagen duren')], 422);
        }
        return $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
    };
    switch (true) {
        case $parts === ['ids', 'block']:
            need('ids_block');
            $h = $hostOf($body);
            if (!$h) {
                respond(['error' => t('Onbekende server: geef "host" (naam) of "host_id"')], 404);
            }
            $ip = trim((string) ($body['ip'] ?? ''));
            if (@inet_pton($ip) === false) {
                respond(['error' => t('Ongeldig IP-adres')], 422);
            }
            // Via de API strenger dan met de hand: nooit je LAN, RzMon zelf of de lijst "Nooit blokkeren"
            if (Ids::protectedIp($ip)) {
                respond(['error' => t('{ip} wordt via de API niet geblokkeerd: LAN-adres, RzMon zelf of op de lijst "Nooit blokkeren"', ['ip' => $ip])], 422);
            }
            $hours = max(0, min(8760, (int) ($body['hours'] ?? 24)));
            $existing = q('SELECT id, until FROM ids_blocks WHERE host_id = ? AND ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW())', [$h['id'], $ip])->fetch();
            if ($existing) {
                respond(['ok' => true, 'already' => true, 'id' => (int) $existing['id'], 'active' => Ids::blocking(), 'until' => iso($existing['until'])], 200);
            }
            $r = Ids::block((int) $h['id'], $ip, $hours ?: null, mb_substr(trim((string) ($body['reason'] ?? '')), 0, 200) ?: t('via de API'), $by, client_ip());
            if ($r !== true) {
                respond(['error' => $r], 422);
            }
            $b = q('SELECT id, until FROM ids_blocks WHERE host_id = ? AND ip = ? ORDER BY id DESC LIMIT 1', [$h['id'], $ip])->fetch();
            AgentCommands::audit($by, 'IDS: geblokkeerd', "$ip op {$h['name']}" . ($hours ? " voor $hours uur" : ' permanent') . ' (vanaf ' . client_ip() . ')');
            respond(['ok' => true, 'id' => (int) $b['id'], 'host' => $h['name'], 'ip' => $ip, 'active' => Ids::blocking(), 'until' => iso($b['until']),
                'note' => Ids::blocking() ? null : (Ids::enabled() ? t('Het IDS staat op "leren"') : t('Het IDS staat uit'))
                    . ': ' . t('de blokkade is vastgelegd en wordt actief zodra het IDS aan staat op "blokkeren"')], 201);

        case $parts === ['ids', 'unblock']:
            need('ids_block');
            $id = (int) ($body['id'] ?? 0);
            if (!$id) {
                $h = $hostOf($body);
                $id = $h ? (int) q('SELECT id FROM ids_blocks WHERE host_id = ? AND ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW()) ORDER BY id DESC LIMIT 1',
                    [$h['id'], trim((string) ($body['ip'] ?? ''))])->fetchColumn() : 0;
            }
            $b = $id ? Ids::unblock($id, $by) : null;
            if (!$b) {
                respond(['error' => t('Geen (actieve) blokkade gevonden')], 404);
            }
            AgentCommands::audit($by, 'IDS: gedeblokkeerd', "{$b['ip']} op " . (q('SELECT name FROM hosts WHERE id = ?', [$b['host_id']])->fetchColumn() ?: "server {$b['host_id']}") . ' (vanaf ' . client_ip() . ')');
            respond(['ok' => true, 'id' => (int) $b['id'], 'ip' => $b['ip']]);

        case count($parts) === 3 && $parts[0] === 'checks' && ctype_digit($parts[1]) && in_array($parts[2], ['ack', 'unack', 'maintenance', 'run'], true):
            need('checks');
            $id = (int) $parts[1];
            $c = q('SELECT c.id, c.name, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
            if (!$c) {
                respond(['error' => t('Check niet gevonden')], 404);
            }
            if ($parts[2] === 'ack') {
                if (!Monitor::acknowledge($id, $by, mb_substr((string) ($body['comment'] ?? ''), 0, 200))) {
                    respond(['error' => t('Deze check heeft geen storing (meer)')], 422);
                }
                AgentCommands::audit($by, 'Storing bevestigd', "{$c['host']} – {$c['name']}");
                respond(['ok' => true]);
            }
            if ($parts[2] === 'unack') {
                Monitor::unacknowledge($id);
                respond(['ok' => true]);
            }
            if ($parts[2] === 'maintenance') {
                $until = $maintUntil($body);
                q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 0 WHERE id = ?',
                    [$until, mb_substr(trim((string) ($body['reason'] ?? '')), 0, 200) ?: null, $id]);
                AgentCommands::audit($by, 'Onderhoud', "{$c['host']} – {$c['name']}: " . ($until ? "tot $until" : 'beëindigd'));
                respond(['ok' => true, 'until' => iso($until)]);
            }
            $r = Monitor::runCheckById($id);
            respond(['ok' => true, 'status' => (int) ($r['status'] ?? 3), 'status_text' => STATUS_LABELS[(int) ($r['status'] ?? 3)], 'message' => $r['message'] ?? '']);

        case count($parts) === 3 && $parts[0] === 'hosts' && ctype_digit($parts[1]) && $parts[2] === 'maintenance':
            need('checks');
            $h = q('SELECT id, name FROM hosts WHERE id = ?', [(int) $parts[1]])->fetch();
            if (!$h) {
                respond(['error' => t('Server niet gevonden')], 404);
            }
            $until = $maintUntil($body);
            q('UPDATE hosts SET maintenance_until = ?, maintenance_reason = ? WHERE id = ?', [$until, mb_substr(trim((string) ($body['reason'] ?? '')), 0, 200) ?: null, $h['id']]);
            AgentCommands::audit($by, 'Onderhoud', "{$h['name']}: " . ($until ? "tot $until" : 'beëindigd'));
            respond(['ok' => true, 'until' => iso($until)]);

        default:
            respond(['error' => t('Onbekend endpoint voor POST'), 'endpoints' => ['/ids/block', '/ids/unblock', '/checks/{id}/ack', '/checks/{id}/unack',
                '/checks/{id}/maintenance', '/checks/{id}/run', '/hosts/{id}/maintenance']], 404);
    }
}

// ---------- IDS lezen ----------
if (($parts[0] ?? '') === 'ids') {
    need('ids_read');
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
    $hostName = static fn(array $r) => $r['host_name'] ?? null;
    switch (true) {
        case $parts === ['ids']:
            $open = q("SELECT host_id, severity, COUNT(*) AS n FROM ids_incidents WHERE status = 'open' GROUP BY host_id, severity")->fetchAll();
            $blocks = Ids::activeBlocks();
            $hosts = q("SELECT h.id, h.name, h.realtime, s.baseline_until, s.last_report, s.fw_state FROM hosts h LEFT JOIN ids_state s ON s.host_id = h.id
                        WHERE h.mode = 'agent' AND h.enabled = 1 ORDER BY h.name")->fetchAll();
            $count = static fn(int $hid, int $sev) => array_sum(array_map(fn($o) => (int) $o['host_id'] === $hid && (int) $o['severity'] === $sev ? (int) $o['n'] : 0, $open));
            respond([
                'enabled' => Ids::enabled(), 'mode' => (string) setting('ids_mode', 'learn'),
                'open' => ['critical' => array_sum(array_map(fn($o) => (int) $o['severity'] === 2 ? (int) $o['n'] : 0, $open)),
                           'warning' => array_sum(array_map(fn($o) => (int) $o['severity'] === 1 ? (int) $o['n'] : 0, $open))],
                'blocks_active' => count($blocks),
                'hosts' => array_map(fn($h) => ['id' => (int) $h['id'], 'name' => $h['name'],
                    'learning' => $h['baseline_until'] !== null && strtotime($h['baseline_until']) > time(), 'last_report' => iso($h['last_report']),
                    'firewall' => $h['fw_state'] ?: null, 'realtime' => (bool) $h['realtime'],
                    'open' => ['critical' => $count((int) $h['id'], 2), 'warning' => $count((int) $h['id'], 1)],
                    'blocks' => count(array_filter($blocks, fn($b) => (int) $b['host_id'] === (int) $h['id']))], $hosts),
            ]);

        case $parts === ['ids', 'incidents']:
            $where = ['1'];
            $params = [];
            if (in_array($_GET['status'] ?? '', ['open', 'done'], true)) {
                $where[] = 'i.status = ?';
                $params[] = $_GET['status'];
            }
            if (in_array($_GET['severity'] ?? '', ['1', '2'], true)) {
                $where[] = 'i.severity = ?';
                $params[] = (int) $_GET['severity'];
            }
            if (!empty($_GET['host_id'])) {
                $where[] = 'i.host_id = ?';
                $params[] = (int) $_GET['host_id'];
            }
            $rows = q('SELECT i.*, h.name AS host_name FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE ' . implode(' AND ', $where)
                . " ORDER BY i.created_at DESC, i.id DESC LIMIT $limit", $params)->fetchAll();
            respond(array_map(fn($i) => ['id' => (int) $i['id'], 'host_id' => (int) $i['host_id'], 'host' => $hostName($i),
                'severity' => (int) $i['severity'], 'severity_text' => Ids::SEVERITY[(int) $i['severity']] ?? '?', 'type' => $i['type'],
                'title' => $i['title'], 'detail' => $i['detail'], 'ip' => $i['ip'], 'hits' => (int) $i['hits'], 'status' => $i['status'],
                'handled_by' => $i['handled_by'], 'handled_at' => iso($i['handled_at']), 'time' => iso($i['created_at']), 'last_seen' => iso($i['last_seen'])], $rows));

        case $parts === ['ids', 'blocks']:
            $rows = !empty($_GET['all'])
                ? q("SELECT b.*, h.name AS host_name FROM ids_blocks b JOIN hosts h ON h.id = b.host_id ORDER BY b.id DESC LIMIT $limit")->fetchAll()
                : Ids::activeBlocks();
            respond(array_map(fn($b) => ['id' => (int) $b['id'], 'host_id' => (int) $b['host_id'], 'host' => $hostName($b), 'ip' => $b['ip'],
                'reason' => $b['reason'], 'automatic' => (bool) $b['auto'], 'by' => $b['created_by'], 'since' => iso($b['created_at']),
                'until' => iso($b['until']), 'permanent' => $b['until'] === null,
                'active' => $b['removed_at'] === null && ($b['until'] === null || strtotime($b['until']) > time()),
                'packets' => (int) ($b['hits'] ?? 0), 'removed_at' => iso($b['removed_at'] ?? null), 'removed_by' => $b['removed_by'] ?? null], $rows));

        case $parts === ['ids', 'access']:
            $where = ['1'];
            $params = [];
            if (!empty($_GET['host_id'])) {
                $where[] = 'a.host_id = ?';
                $params[] = (int) $_GET['host_id'];
            }
            if (in_array($_GET['type'] ?? '', ['ssh', 'console', 'sudo', 'su'], true)) {
                $where[] = 'a.type = ?';
                $params[] = $_GET['type'];
            }
            if (!empty($_GET['failed'])) {
                $where[] = "a.kind = 'authfail'";
            }
            $rows = q('SELECT a.*, h.name AS host_name FROM access_log a JOIN hosts h ON h.id = a.host_id WHERE ' . implode(' AND ', $where)
                . " ORDER BY a.created_at DESC, a.id DESC LIMIT $limit", $params)->fetchAll();
            $rows = array_values(array_filter($rows, fn($r) => !AuthEvents::rowIgnored($r)));
            respond(array_map(function ($r) use ($hostName) {
                $d = json_decode((string) $r['detail'], true) ?: [];
                return ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'host' => $hostName($r), 'time' => iso($r['created_at']),
                    'type' => $r['type'], 'failed' => $r['kind'] === 'authfail', 'count' => (int) ($d['n'] ?? 1),
                    'user' => $d['user'] ?? null, 'users' => $d['users'] ?? null, 'as' => ($d['as'] ?? '') !== '' ? $d['as'] : null,
                    'ip' => ($d['ip'] ?? '') !== '' ? $d['ip'] : null, 'command' => ($d['cmd'] ?? '') !== '' ? $d['cmd'] : null, 'message' => $r['message']];
            }, $rows));

        default:
            respond(['error' => t('Onbekend endpoint'), 'endpoints' => ['/ids', '/ids/incidents', '/ids/blocks', '/ids/access']], 404);
    }
}

// ---------- Status lezen ----------
need('read');

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
                $hl['quiet_until'] = iso($hl['quiet_until']);
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
        $h ? respond($h[0]) : respond(['error' => t('Host niet gevonden')], 404);

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
        $c ? respond($c[0]) : respond(['error' => t('Check niet gevonden')], 404);

    case count($parts) === 3 && $parts[0] === 'checks' && ctype_digit($parts[1]) && $parts[2] === 'history':
        $id = (int) $parts[1];
        if (!loadChecks('c.id = ?', [$id])) {
            respond(['error' => t('Check niet gevonden')], 404);
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
        respond(['error' => t('Onbekend endpoint'), 'endpoints' => [
            '/status', '/hosts', '/hosts/{id}', '/checks', '/checks/{id}', '/checks/{id}/history', '/alerts',
            '/ids', '/ids/incidents', '/ids/blocks', '/ids/access',
        ]], 404);
}
