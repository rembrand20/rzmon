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
    out(['ok' => true, 'app' => 'RzMon', 'version' => RZMON_VERSION, 'configured' => is_configured(), 'time' => date('c')]);
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
        out(['error' => "Geweigerd: $reason", 'reason' => $reason], 403);
    }
    return $host;
}

// Extra tests (URL of poort) die bij service-checks van deze server zijn ingesteld, voor de agent.
// Platte tekst, één test per regel: id <tab> type <tab> doel <tab> verwachte tekst.
/**
 * Wat de agent van RzMon moet weten, één regel per item (tab-gescheiden):
 * welke services hij bewaakt, logzoekopdrachten, eigen poorten van services en extra tests.
 */
function agent_tests_text(int $hostId): string
{
    $out = [];
    // Services met een service-check: id <tab> svc <tab> servicenaam
    foreach (q("SELECT id, target FROM checks WHERE host_id = ? AND type = 'service' AND enabled = 1", [$hostId]) as $c) {
        if (preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', $c['target'])) {
            $out[] = implode("\t", [$c['id'], 'svc', $c['target']]);
        }
    }
    // Logbestanden doorzoeken: id <tab> log <tab> bron <tab> zoekpatroon
    foreach (q("SELECT id, target, options FROM checks WHERE host_id = ? AND type = 'log' AND enabled = 1", [$hostId]) as $c) {
        $o = json_decode((string) $c['options'], true) ?: [];
        if (($o['pattern'] ?? '') !== '') {
            $out[] = implode("\t", [$c['id'], 'log', $c['target'], str_replace(["\t", "\n", "\r"], ' ', $o['pattern'])]);
        }
    }
    foreach (q("SELECT id, target, options FROM checks WHERE host_id = ? AND type = 'service' AND enabled = 1 AND options IS NOT NULL", [$hostId]) as $c) {
        $o = json_decode((string) $c['options'], true) ?: [];
        if (($o['ports'] ?? '') !== '' && ($o['deep'] ?? 1)) {
            // Zelf opgegeven poorten/sockets van de service (target = servicenaam)
            $out[] = implode("\t", [$c['id'], 'ports', $c['target'], $o['ports']]);
        }
        if (in_array($o['test_type'] ?? '', ['url', 'port'], true) && ($o['test_target'] ?? '') !== '') {
            $out[] = implode("\t", [$c['id'], $o['test_type'], str_replace(["\t", "\n"], ' ', $o['test_target']), str_replace(["\t", "\n"], ' ', (string) ($o['test_expect'] ?? ''))]);
        }
    }
    // Logins, sudo en su melden (Instellingen → Alarmgedrag): 0 <tab> auth <tab> 1
    if (AuthEvents::enabled()) {
        $out[] = implode("\t", [0, 'auth', 1]);
    }
    // Commando-checks: id <tab> cmd <tab> commando (base64) <tab> time-out <tab> nobody|root <tab> interval
    $mode = AgentCommands::modeFor(q('SELECT commands FROM hosts WHERE id = ?', [$hostId])->fetch() ?: []);
    if ($mode !== 'off') {
        foreach (q("SELECT id, command, interval_sec FROM checks WHERE host_id = ? AND type = 'command' AND enabled = 1", [$hostId]) as $c) {
            $cfg = json_decode((string) $c['command'], true);
            if (is_array($cfg) && ($cfg['cmd'] ?? '') !== '') {
                $out[] = implode("\t", [$c['id'], 'cmd', base64_encode($cfg['cmd']), (int) ($cfg['timeout'] ?? 10), $mode, (int) $c['interval_sec']]);
            }
        }
    }
    return $out ? implode("\n", $out) . "\n" : '';
}

/** Kenmerk van die lijst: verandert het, dan haalt de agent hem meteen opnieuw op. */
function agent_tests_rev(int $hostId): string
{
    // Zelfde inhoud als het cachebestand van de agent (die zet er één regeleinde achter)
    return substr(sha1(rtrim(agent_tests_text($hostId), "\n") . "\n"), 0, 12);
}

if ($action === 'agent_tests') {
    $host = agent_host();
    header('Content-Type: text/plain; charset=utf-8');
    echo agent_tests_text((int) $host['id']);
    exit;
}

// Alleen het token controleren, zonder gegevens te versturen (verbindingstest)
if ($action === 'agent_check') {
    $host = agent_host();
    out(['ok' => true, 'host' => $host['name'], 'your_ip' => client_ip()]);
}

if ($action === 'agent') {
    $host = agent_host();
    $in = input();
    if (!$in) {
        // Agent 1.20.0–1.20.3 zette servicenamen als systemd-fsck@dev-disk-by\x2duuid… ongewijzigd in de JSON.
        // Zo'n losse backslash herstellen, dan blijft die agent werken (en kan hij met de knop bijgewerkt worden).
        $raw = (string) file_get_contents('php://input');
        $fixed = preg_replace_callback('/\\\\(.?)/s', static fn($m) => $m[1] !== '' && str_contains('"\\\\/bfnrtu', $m[1]) ? $m[0] : '\\' . $m[0], $raw);
        $in = json_decode((string) $fixed, true) ?: [];
    }
    if (!$in) {
        // Onleesbaar rapport: niets wegschrijven (anders lijken alle gegevens verdwenen) en de reden loggen
        $reason = 'onleesbaar rapport (JSON: ' . json_last_error_msg() . '); werk de agent bij';
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'], mb_substr(client_ip(), 0, 45), $reason]);
        out(['error' => "Geweigerd: $reason"], 400);
    }
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
    // Certificaten (bestanden of host:poort) die de agent heeft uitgelezen
    foreach (array_slice((array) ($in['certs'] ?? []), 0, 100) as $c) {
        $src = trim((string) ($c['source'] ?? ''));
        if ($src === '' || mb_strlen($src) > 180) {
            continue;
        }
        $st->execute([$host['id'], 'cert:' . $src, json_encode([
            'cn' => mb_substr((string) ($c['cn'] ?? ''), 0, 200),
            'issuer' => mb_substr((string) ($c['issuer'] ?? ''), 0, 200),
            'not_after' => isset($c['not_after']) && is_numeric($c['not_after']) ? (int) $c['not_after'] : null,
            'verify' => isset($c['verify']) ? mb_substr((string) $c['verify'], 0, 120) : null,
            'error' => mb_substr((string) ($c['error'] ?? ''), 0, 200),
        ], JSON_UNESCAPED_UNICODE), $now]);
    }
    // Podman: overzicht, en per container en pod een eigen regel (sleutel: naam, of gebruiker/naam bij rootless)
    if (isset($in['podman']) && is_array($in['podman'])) {
        $pd = $in['podman'];
        $key = static fn(array $x): string => mb_substr((($x['user'] ?? 'root') !== 'root' ? $x['user'] . '/' : '') . ($x['name'] ?? ''), 0, 150);
        $summary = array_intersect_key($pd, array_flip(['version', 'error', 'users', 'images', 'images_size', 'containers_size', 'volumes_size', 'reclaimable', 'pods']));
        $summary['containers'] = [];
        foreach (array_slice((array) ($pd['containers'] ?? []), 0, 300) as $c) {
            if (!is_array($c) || ($c['name'] ?? '') === '') {
                continue;
            }
            $c = array_intersect_key($c, array_flip(['name', 'user', 'pod', 'image', 'state', 'status', 'health', 'exit_code', 'restarts',
                'started', 'cpu', 'mem', 'mem_limit', 'net_in', 'net_out', 'blk_in', 'blk_out', 'pids', 'size_rw', 'volumes']));
            $st->execute([$host['id'], 'container:' . $key($c), json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
            // Kort overzicht voor de Podman-check (zonder de meetwaarden)
            $summary['containers'][] = array_intersect_key($c, array_flip(['name', 'user', 'pod', 'state', 'health', 'exit_code']));
        }
        $st->execute([$host['id'], 'podman', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
    }
    // Services: details van de diepere controle, met een uur herstart-historie (voor "≥ 3 per uur")
    if (isset($in['svc']) && is_array($in['svc'])) {
        $prev = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'svcinfo:%'", [$host['id']]) as $r) {
            $prev[substr($r['metric'], 8)] = json_decode($r['value'], true) ?: [];
        }
        foreach (array_slice($in['svc'], 0, 100, true) as $name => $d) {
            if (!is_array($d) || !preg_match('/^[A-Za-z0-9@._:-]{1,150}$/', (string) $name)) {
                continue;
            }
            $d = array_intersect_key($d, array_flip(['sub', 'type', 'pid', 'restarts', 'since', 'ports', 'ports_fail', 'manual', 'sockets', 'sockets_fail', 'probe', 'probe_rc',
                'probe_msg', 'log_errors', 'log_last', 'custom_rc', 'custom_msg']));
            $hist = array_values(array_filter($prev[$name]['hist'] ?? [], fn($h) => is_array($h) && $h[0] >= time() - 3900));
            if (isset($d['restarts']) && is_numeric($d['restarts'])) {
                $hist[] = [time(), (int) $d['restarts']];
            }
            $d['hist'] = array_slice($hist, -70);
            $st->execute([$host['id'], 'svcinfo:' . $name, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Logbestanden: treffers per rapport bijhouden (24 uur), zodat RzMon over een periode kan tellen
    if (isset($in['logs']) && is_array($in['logs'])) {
        $prevLogs = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'logrun:%'", [$host['id']]) as $r) {
            $prevLogs[substr($r['metric'], 7)] = json_decode($r['value'], true) ?: [];
        }
        foreach (array_slice($in['logs'], 0, 100, true) as $id => $l) {
            if (!ctype_digit((string) $id) || !is_array($l)) {
                continue;
            }
            $p = $prevLogs[$id] ?? [];
            $n = max(0, (int) ($l['new'] ?? 0));
            $ips = [];
            foreach (array_slice((array) ($l['ips'] ?? []), 0, 3) as $ip) {
                if (is_array($ip) && filter_var($ip[0] ?? '', FILTER_VALIDATE_IP)) {
                    $ips[$ip[0]] = (int) ($ip[1] ?? 0);
                }
            }
            $hist = array_values(array_filter($p['hist'] ?? [], fn($h) => is_array($h) && $h[0] >= time() - 86400));
            $hist[] = $n > 0 ? [time(), $n, $ips] : [time(), 0];
            $lines = $p['lines'] ?? [];
            foreach (array_slice((array) ($l['lines'] ?? []), -5) as $line) {
                $lines[] = [time(), mb_substr((string) $line, 0, 300)];
            }
            $st->execute([$host['id'], 'logrun:' . $id, json_encode([
                'hist' => array_slice($hist, -1500),
                'lines' => array_slice($lines, -5),
                'last_hit' => $n > 0 ? time() : ($p['last_hit'] ?? null),
                'error' => mb_substr((string) ($l['error'] ?? ''), 0, 250),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Logins, sudo en su: naar de pagina Meldingen
    if (is_array($in['auth'] ?? null)) {
        AuthEvents::store($host, $in['auth']);
    }
    // Commando-checks: uitvoer en exitcode (RzMon vergelijkt met het verwachte antwoord)
    foreach ((array) ($in['cmd'] ?? []) as $id => $r) {
        if (ctype_digit((string) $id) && is_array($r)) {
            $st->execute([$host['id'], 'cmd:' . $id, json_encode([
                'rc' => isset($r['rc']) ? (int) $r['rc'] : null,
                'out' => mb_substr((string) ($r['out'] ?? ''), 0, 4096),
                'err' => mb_substr((string) ($r['err'] ?? ''), 0, 500),
                'ms' => (int) ($r['ms'] ?? 0),
                'as' => ($r['as'] ?? '') === 'root' ? 'root' : 'nobody',
                'at' => (int) ($r['at'] ?? 0),
                'blocked' => mb_substr((string) ($r['blocked'] ?? ''), 0, 200),
                'error' => mb_substr((string) ($r['error'] ?? ''), 0, 200),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now]);
        }
    }
    foreach ((array) ($in['svc_tests'] ?? []) as $id => $t) {
        if (ctype_digit((string) $id) && is_array($t)) {
            $st->execute([$host['id'], 'svctest:' . $id, json_encode(['ok' => !empty($t['ok']), 'msg' => mb_substr((string) ($t['msg'] ?? ''), 0, 200)], JSON_UNESCAPED_UNICODE), $now]);
        }
    }
    foreach (($in['services'] ?? []) as $name => $state) {
        $st->execute([$host['id'], mb_substr('service:' . $name, 0, 190), mb_substr((string) $state, 0, 255), $now]);
    }
    // Home Assistant-add-on: laatste back-up, integraties en systeemstatus
    foreach ((array) ($in['ha'] ?? []) as $k => $v) {
        if (in_array($k, ['backup', 'integrations', 'repairs'], true) && is_array($v)) {
            $st->execute([$host['id'], "ha:$k", json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Overzicht van de services op de server (om bij "Check toevoegen" uit te kiezen): [naam, status, enabled]
    if (isset($in['svc_list']) && is_array($in['svc_list'])) {
        $list = [];
        foreach (array_slice($in['svc_list'], 0, 1000) as $e) {
            if (is_array($e) && preg_match('/^[A-Za-z0-9@._:-]{1,100}$/', (string) ($e[0] ?? ''))) {
                $list[] = [(string) $e[0], preg_replace('/[^a-z-]/', '', (string) ($e[1] ?? '')), (int) !empty($e[2])];
            }
        }
        usort($list, static fn($a, $b) => strcmp($a[0], $b[0]));
        $st->execute([$host['id'], 'svclist', json_encode($list), $now]);
    }
    q('DELETE FROM agent_data WHERE host_id = ? AND updated_at < ?', [$host['id'], $now]);
    $agentVersion = preg_match('/^\d+\.\d+\.\d+$/', (string) ($in['agent_version'] ?? '')) ? $in['agent_version'] : null;
    q('UPDATE hosts SET agent_seen = ?, agent_ip = ?, agent_version = ? WHERE id = ?', [$now, mb_substr(client_ip(), 0, 45), $agentVersion, $host['id']]);
    $resp = ['ok' => true, 'host' => $host['name'], 'rzmon_version' => RZMON_VERSION, 'received' => [
        'disks' => count($in['disks'] ?? []), 'services' => count($in['services'] ?? []), 'ports' => count($in['ports'] ?? []),
        'certs' => count((array) ($in['certs'] ?? [])), 'containers' => count((array) ($in['podman']['containers'] ?? [])),
    ]];
    $resp['tests_rev'] = agent_tests_rev((int) $host['id']);
    if ($u = agent_update_instruction($host['id'], $agentVersion, $in)) {
        $resp['update'] = $u;
    }
    out($resp);
}

/**
 * Bijwerken op afstand: verwerkt wat de agent over updates meldt en bepaalt of hij nu moet bijwerken.
 * De agent haalt dan zelf de nieuwe versie bij RzMon op en controleert die met de SHA-256 hieronder.
 * @return array|null opdracht voor de agent, of null
 */
function agent_update_instruction(int $hostId, ?string $version, array $in): ?array
{
    $via = (string) ($in['update']['via'] ?? '');
    if ($via === 'test') {
        return null;   // verbindingstest (rzmon-agent-setup --test): niets veranderen
    }
    $via = in_array($via, ['systemd', 'direct', 'off', 'ha', 'none'], true) ? $via : null;   // null: agent van vóór 1.19.0
    $h = q('SELECT agent_update_req, agent_update_sent, agent_update_status FROM hosts WHERE id = ?', [$hostId])->fetch();
    $status = null;
    $req = $h['agent_update_req'];
    $sent = $h['agent_update_sent'];

    // Uitkomst van een eerdere update ("ok 1.18.0 1.19.0" of "error <reden>")
    $result = trim((string) ($in['update_result'] ?? ''));
    if ($result !== '') {
        $status = preg_match('/^ok (\S*) (\S+)/', $result, $m)
            ? '✔ ' . date('d-m H:i') . ' bijgewerkt' . ($m[1] !== '' && $m[1] !== $m[2] ? " van $m[1]" : '') . " naar $m[2]"
            : '✘ ' . date('d-m H:i') . ' bijwerken mislukt: ' . mb_substr(preg_replace('/^error\s*/', '', $result), 0, 200);
        if (str_starts_with($result, 'ok')) {
            $sent = null;   // gelukt: een volgende RzMon-versie mag meteen weer automatisch
        }
        $req = null;
    } elseif ($req !== null && $sent !== null && strtotime($sent) < time() - 600) {
        $status = '✘ Geen antwoord van de update na 10 minuten. Kijk op de server: journalctl -u "rzmon-agent-update-*"';
        $req = null;
    }

    $outdated = $version === null || version_compare($version, RZMON_VERSION, '<');
    $wanted = ($req !== null && $sent === null) || ($req === null && $outdated && setting('agent_auto_update', '0') === '1'
        && ($sent === null || strtotime($sent) < time() - 6 * 3600));   // automatisch: hooguit elke 6 uur een poging
    $instruction = null;
    if ($wanted && in_array($via, ['systemd', 'direct'], true)) {
        $instruction = [
            'version' => RZMON_VERSION,
            'agent_sha256' => hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh'),
            'setup_sha256' => hash_file('sha256', SM_ROOT . '/agent/install-agent.sh'),
        ];
        $sent = now();
        $req ??= now();
        $status = '⏳ Bijwerken naar ' . RZMON_VERSION . ' gestart…';
    } elseif ($req !== null && $sent === null) {
        // Gevraagd, maar deze agent kan het niet: uitleggen waarom
        $status = '✘ ' . match ($via) {
            'off' => 'Bijwerken op afstand staat uit op deze server (REMOTE_UPDATE=0 in /etc/default/rzmon-agent)',
            'ha' => 'Home Assistant-add-on: bijwerken via de add-on winkel',
            'none' => 'De agent kan zichzelf hier niet bijwerken (geen root of rzmon-agent-setup ontbreekt): sudo rzmon-agent-setup --update',
            default => 'Deze agent kent bijwerken op afstand nog niet. Werk hem één keer bij op de server: sudo rzmon-agent-setup --update',
        };
        $req = null;
    }
    q('UPDATE hosts SET agent_update_req = ?, agent_update_sent = ?, agent_update_status = COALESCE(?, agent_update_status), agent_update_via = ? WHERE id = ?',
        [$req, $sent, $status, $via, $hostId]);
    return $instruction;
}

// ---------- Login ----------
if ($action === 'login' && $method === 'POST') {
    $in = input();
    $r = login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''), !empty($in['remember']));
    if ($r === 'totp') {
        out(['ok' => true, 'totp_required' => true]);
    }
    if ($r === 'ok') {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    out(['error' => 'Onjuiste gebruikersnaam of wachtwoord'], 401);
}
if ($action === 'login_totp' && $method === 'POST') {
    $r = login_totp((string) (input()['code'] ?? ''));
    if ($r === true) {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    out(['error' => $r], 401);
}

// ---------- Wachtwoord vergeten (zonder login) ----------
if ($action === 'pw_forgot' && $method === 'POST') {
    // Altijd hetzelfde antwoord: niemand kan zo uitzoeken welke accounts er bestaan
    $why = PasswordReset::unavailable();
    $send = $why === null ? PasswordReset::request((string) (input()['ident'] ?? '')) : null;
    if ($why !== null) {
        out(['ok' => true, 'no_mail' => true]);   // geen mail mogelijk: "vraag een beheerder"
    }
    http_response_code(200);
    echo json_encode(['ok' => true]);
    // De mail pas na het antwoord versturen: zo duurt het antwoord even lang, of het account nu bestaat of niet
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if ($send) {
        $send();
    }
    exit;
}
if ($action === 'pw_reset_check' && $method === 'POST') {
    $r = PasswordReset::check((string) (input()['token'] ?? ''));
    $r ? out(['ok' => true] + $r) : out(['error' => 'Deze link is verlopen of al gebruikt. Vraag een nieuwe aan via "Wachtwoord vergeten?".'], 410);
}
if ($action === 'pw_reset' && $method === 'POST') {
    $in = input();
    $r = PasswordReset::reset((string) ($in['token'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['code'] ?? ''));
    $r === true ? out(['ok' => true]) : out(['error' => $r], 422);
}

$user = current_user();
if (!$user) {
    out(['error' => 'Niet ingelogd'], 401);
}
if ($method !== 'GET' && !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    out(['error' => 'Ongeldig CSRF token'], 403);
}

// Rechten per actie. Alles wat hier niet staat, is alleen voor beheerders.
$need = match ($action) {
    'state', 'alerts', 'history', 'stats', 'podman', 'logout', 'password', 'users', 'totp_setup', 'totp_confirm', 'totp_reset',
    'devices', 'device_forget', 'device_forget_all', 'user_email' => 'readonly',
    'ack', 'ack_host', 'unack', 'check_run', 'run_all', 'maintenance_set', 'port_scan' => 'user',
    default => 'admin',
};
if (!has_role($user, $need)) {
    out(['error' => 'Daar heb je geen rechten voor (je bent ' . mb_strtolower(ROLE_LABELS[$user['role']] ?? '?') . ')'], 403);
}
$isAdmin = has_role($user, 'admin');
/** Niet-beheerders mogen alleen hun eigen account (2FA) beheren. */
$ownOnly = static function (int $id) use ($user, $isAdmin): void {
    if (!$isAdmin && $id !== $user['id']) {
        out(['error' => 'Je kunt alleen je eigen account beheren'], 403);
    }
};

/**
 * Bevestiging voor een gevoelige actie: je eigen wachtwoord, of een woord typen
 * als je automatisch beheerder bent via het LAN (dan heb je geen wachtwoord ingevuld).
 */
$confirm = static function (string $word) use ($user): void {
    $c = (string) (input()['confirm'] ?? '');
    if (is_lan_session()) {
        if ($c !== $word) {
            out(['error' => "Typ $word om te bevestigen"], 422);
        }
        return;
    }
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
    if (!$hash || !password_verify($c, $hash)) {
        usleep(500000);
        out(['error' => 'Je wachtwoord klopt niet'], 422);
    }
};

switch ($action) {
    case 'logout':
        logout();
        out(['ok' => true]);

    case 'state':
        $hosts = q('SELECT id, name, address, mode, parent_id, agent_token, agent_seen, agent_version, agent_update_req, agent_update_status, enabled, maintenance_until, maintenance_reason
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
        if (!$isAdmin) {
            // Agent-tokens zijn geheim: alleen beheerders installeren agents
            foreach ($hosts as &$h) {
                $h['agent_token'] = null;
            }
            unset($h);
        }
        $lastRun = q('SELECT MAX(last_checked) FROM checks')->fetchColumn();
        out(['hosts' => $hosts, 'last_run' => $lastRun, 'user' => $user['username'],
             'base_url' => config('base_url', ''), 'lan_session' => is_lan_session(), 'maintenance' => $global,
             'version' => RZMON_VERSION, 'role' => $user['role'], 'user_id' => $user['id'], 'health' => Health::current(),
             // Voor beheerders: label "⬆ x.y.z" bovenin als er een nieuwere versie is
             'update' => $isAdmin && ($uc = json_decode((string) setting('update_cache', ''), true)) && !empty($uc['latest'])
                 && version_compare($uc['latest'], RZMON_VERSION, '>') ? $uc['latest'] : null]);

    // Meldingen van de bewaartermijn (Instellingen → Alarmgedrag), nieuwste eerst; de pagina groepeert per dag
    case 'alerts':
        $days = Monitor::alertDays();
        $rows = q('SELECT a.*, c.name AS check_name, h.name AS host_name FROM alerts a
                   LEFT JOIN checks c ON c.id = a.check_id LEFT JOIN hosts h ON h.id = COALESCE(c.host_id, a.host_id)
                   WHERE a.created_at >= CURDATE() - INTERVAL ? DAY ORDER BY a.created_at DESC, a.id DESC LIMIT 10000', [$days - 1])->fetchAll();
        out(['days' => $days, 'alerts' => $rows, 'today' => date('Y-m-d'), 'truncated' => count($rows) >= 10000]);

    case 'history':
        $id = (int) ($_GET['check_id'] ?? 0);
        $hours = min(24 * 30, max(1, (int) ($_GET['hours'] ?? 24)));
        $rows = q('SELECT UNIX_TIMESTAMP(created_at) AS t, status AS s, value AS v, vals AS x FROM results
                   WHERE check_id = ? AND created_at >= NOW() - INTERVAL ? HOUR ORDER BY created_at', [$id, $hours])->fetchAll();
        // Downsamplen naar max ~300 punten
        $step = max(1, (int) ceil(count($rows) / 300));
        $pts = [];
        for ($i = 0; $i < count($rows); $i += $step) {
            $slice = array_slice($rows, $i, $step);
            $vals = array_filter(array_column($slice, 'v'), fn($v) => $v !== null);
            // Container: per meting (CPU, RAM, disk) het gemiddelde
            $x = [];
            foreach ($slice as $r) {
                foreach ((array) json_decode((string) $r['x'], true) as $k => $xv) {
                    if (is_numeric($xv)) {
                        $x[$k][] = (float) $xv;
                    }
                }
            }
            $pts[] = [
                't' => (int) $slice[0]['t'],
                's' => max(array_map('intval', array_column($slice, 's'))),
                'v' => $vals ? array_sum($vals) / count($vals) : null,
            ] + ($x ? ['x' => array_map(fn($a) => round(array_sum($a) / count($a), 2), $x)] : []);
        }
        $up = $rows ? count(array_filter($rows, fn($r) => (int) $r['s'] !== STATUS_CRIT)) / count($rows) * 100 : null;
        $type = q('SELECT type, last_value, metric, options FROM checks WHERE id = ?', [$id])->fetch();
        // Container: welke metingen bewaakt worden, met eenheid en drempels (een lijn per meting)
        $series = null;
        if ($type && $type['type'] === 'container' && $type['metric'] === null) {
            $series = [];
            foreach ((array) ((json_decode((string) $type['options'], true) ?: [])['res'] ?? []) as $k => $t) {
                $series[] = ['key' => $k, 'label' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'Disk'][$k] ?? $k,
                    'unit' => $k === 'disk' ? 'MB' : '%', 'warn' => $t[0] ?? null, 'crit' => $t[1] ?? null];
            }
        }
        $forecast = $type && $type['type'] === 'disk' && $type['last_value'] !== null ? Stats::diskForecast($id, (float) $type['last_value']) : null;
        $logLines = null;
        if ($type && $type['type'] === 'log') {
            $hid = q('SELECT host_id FROM checks WHERE id = ?', [$id])->fetchColumn();
            $lr = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = ?", [$hid, "logrun:$id"])->fetchColumn(), true);
            $logLines = array_map(fn($l) => ['time' => date('Y-m-d H:i:s', (int) $l[0]), 'line' => $l[1]], $lr['lines'] ?? []);
        }
        out(['points' => $pts, 'uptime' => $up, 'forecast' => $forecast, 'log_lines' => $logLines, 'series' => $series]);

    case 'stats':
        // Beschikbaarheid per server en minigrafieken per check; 2 minuten bewaard
        $cache = json_decode((string) setting('stats_cache', ''), true);
        if (!$cache || ($cache['at'] ?? 0) < time() - 120) {
            $cache = ['at' => time(), 'uptime' => Stats::uptime(), 'sparks' => Stats::sparks()];
            save_setting('stats_cache', json_encode($cache));
        }
        out($cache);

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

    case 'agent_services':
        // Services op een agent-server, om bij "Check toevoegen" uit te kiezen
        $v = q("SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = 'svclist'", [(int) ($_GET['id'] ?? 0)])->fetch();
        out(['list' => $v ? json_decode($v['value'], true) : null, 'updated' => $v['updated_at'] ?? null]);

    case 'agent_update':
        // Eén server (id) of alle verouderde agents (all): bij het volgende rapport werkt de agent zichzelf bij
        $in = input();
        if (!empty($in['all'])) {
            $ids = [];
            foreach (q("SELECT id, agent_version FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL") as $h) {
                if ($h['agent_version'] === null || version_compare($h['agent_version'], RZMON_VERSION, '<')) {
                    $ids[] = (int) $h['id'];
                }
            }
        } else {
            $ids = q("SELECT id FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['id'] ?? 0)])->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                out(['error' => 'Geen agent-server gevonden'], 404);
            }
        }
        foreach ($ids as $id) {
            q("UPDATE hosts SET agent_update_req = ?, agent_update_sent = NULL, agent_update_status = '⏳ Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport' WHERE id = ?", [now(), $id]);
        }
        out(['ok' => true, 'count' => count($ids)]);

    case 'agent_update_cancel':
        q('UPDATE hosts SET agent_update_req = NULL, agent_update_sent = NULL, agent_update_status = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'agent_status':
        $id = (int) ($_GET['id'] ?? 0);
        $h = q('SELECT agent_seen, agent_ip, agent_version, mode, agent_update_req, agent_update_status, agent_update_via FROM hosts WHERE id = ?', [$id])->fetch();
        if (!$h) {
            out(['error' => 'Server niet gevonden'], 404);
        }
        // Geweigerde pogingen (laatste 24 uur, en ná het laatste geslaagde rapport): voor deze
        // server of met een onbekend token. Gelijke pogingen samengevoegd met een aantal.
        $fails = q('SELECT ip, reason, MAX(created_at) AS created_at, COUNT(*) AS n FROM agent_log
                    WHERE (host_id = ? OR host_id IS NULL) AND created_at >= NOW() - INTERVAL 1 DAY
                      AND (? IS NULL OR created_at > ?)
                    GROUP BY ip, reason ORDER BY MAX(id) DESC LIMIT 6', [$id, $h['agent_seen'], $h['agent_seen']])->fetchAll();
        out(['seen' => $h['agent_seen'], 'ip' => $h['agent_ip'], 'mode' => $h['mode'], 'failures' => $fails, 'now' => now(),
             'agent_version' => $h['agent_version'], 'version' => RZMON_VERSION,
             'update' => ['pending' => $h['agent_update_req'] !== null, 'status' => $h['agent_update_status'], 'via' => $h['agent_update_via']]]);

    case 'podman':
        // Overzicht van alle containers die de agent van deze server meldt
        $id = (int) ($_GET['id'] ?? 0);
        $sum = q("SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = 'podman'", [$id])->fetch();
        if (!$sum) {
            out(['error' => 'Deze server meldt (nog) geen Podman'], 404);
        }
        $containers = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'container:%' ORDER BY metric", [$id]) as $r) {
            $containers[] = ['key' => substr($r['metric'], 10)] + (json_decode($r['value'], true) ?: []);
        }
        $summary = json_decode($sum['value'], true) ?: [];
        unset($summary['containers']);
        out(['summary' => $summary, 'updated_at' => $sum['updated_at'], 'containers' => $containers,
             'checks' => q("SELECT id, type, target, metric, options FROM checks WHERE host_id = ? AND type IN ('podman', 'container')", [$id])->fetchAll()]);

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
            // Handmatig ingesteld of beëindigd: niet (meer) automatisch
            'check' => q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 0 WHERE id = ?', [$until, $reason, $id]),
            default => out(['error' => 'Onbekend bereik'], 422),
        };
        out(['ok' => true, 'until' => $until]);

    case 'check_save':
        $in = input();
        $type = $in['type'] ?? '';
        if (!in_array($type, ['ping', 'tcp', 'http', 'ssl', 'disk', 'service', 'cpu', 'ram', 'podman', 'container', 'log', 'ha', 'command'], true)) {
            out(['error' => 'Ongeldig type'], 422);
        }
        // Commando-check: commando, verwacht antwoord, manier van vergelijken, time-out en status bij afwijking
        $command = null;
        if ($type === 'command') {
            $cmdCfg = AgentCommands::clean((array) ($in['command'] ?? []));
            if (is_string($cmdCfg)) {
                out(['error' => $cmdCfg], 422);
            }
            $command = json_encode($cmdCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $prev = !empty($in['id']) ? q('SELECT command FROM checks WHERE id = ?', [(int) $in['id']])->fetchColumn() : null;
            if ($prev !== $command) {
                AgentCommands::audit($user['username'], empty($in['id']) ? 'commando-check aangemaakt' : 'commando-check gewijzigd',
                    'server ' . (int) ($in['host_id'] ?? 0) . ': ' . $cmdCfg['cmd'] . ' → ' . AgentCommands::MATCHES[$cmdCfg['match']] . ' "' . $cmdCfg['expect'] . '"');
            }
        }
        $metric = null;   // container: één check met status + gekozen resources (options.res)
        if ($type === 'ha') {
            $metric = (string) ($in['metric'] ?? '');
            if (!in_array($metric, ['backup', 'integrations', 'repairs'], true)) {
                out(['error' => 'Kies wat je van Home Assistant wilt bewaken'], 422);
            }
        }
        $target = trim((string) ($in['target'] ?? ''));
        if ($type === 'tcp' && ((int) $target < 1 || (int) $target > 65535)) {
            out(['error' => 'Geef een geldige poort op (1-65535)'], 422);
        }
        if ($type === 'service' && !preg_match('/^[A-Za-z0-9@._:-]+$/', $target)) {
            out(['error' => 'Geef een geldige servicenaam op'], 422);
        }
        if ($type === 'container' && !preg_match('#^([a-z_][a-z0-9_.-]*/)?[A-Za-z0-9][A-Za-z0-9_.-]*$#', $target)) {
            out(['error' => 'Geef de naam van een container op (rootless: gebruiker/naam)'], 422);
        }
        $warn = num($in['warn_threshold'] ?? null);
        $crit = num($in['crit_threshold'] ?? null);
        if ($type === 'ssl') {
            // Hier geldt "minder dagen = slechter"
            if ($warn !== null && $crit !== null && $warn < $crit) {
                out(['error' => 'Bij een certificaat moet de warning-drempel (dagen) hoger zijn dan de critical-drempel'], 422);
            }
        } elseif ($warn !== null && $crit !== null && $warn > $crit) {
            out(['error' => 'Warning-drempel moet lager zijn dan critical-drempel'], 422);
        }
        if ($type === 'ssl' && $target !== '' && !preg_match('#^(https?://)?(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.\-]+)(:\d{1,5})?/?$#', $target)
            && !preg_match('#^/[^\s\'"]+$#', $target)) {
            out(['error' => 'Geef een host op, eventueel met poort (example.com of example.com:8443), of het pad van een certificaatbestand dat de agent meldt'], 422);
        }
        if (in_array($type, ['ping', 'cpu', 'ram', 'podman', 'ha', 'command'], true)) {
            $target = '';
        }
        if ($type === 'command') {
            $warn = $crit = null;
        }
        if ($type === 'ha' && $metric === 'repairs') {
            $warn = $crit = null;   // systeemstatus: unhealthy = critical, de rest warning
        }
        $options = null;
        if ($type === 'container') {
            // Status altijd; CPU, RAM en disk alleen als aangevinkt, elk met eigen drempels
            $warn = $crit = null;
            $res = [];
            foreach (['cpu' => '%', 'mem' => '%', 'disk' => 'MB'] as $k => $u) {
                $r = $in['res'][$k] ?? null;
                if (!is_array($r) || empty($r['on'])) {
                    continue;
                }
                $w = num($r['warn'] ?? null);
                $c = num($r['crit'] ?? null);
                if ($w === null && $c === null) {
                    out(['error' => 'Vul bij ' . ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'][$k] . ' minstens één drempel in'], 422);
                }
                if ($w !== null && $c !== null && $w > $c) {
                    out(['error' => 'Bij ' . ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'][$k] . ' moet de warning-drempel lager zijn dan de critical-drempel'], 422);
                }
                $res[$k] = [$w, $c];
            }
            $options = json_encode(['res' => (object) $res]);
            // Eén check per container: bestaat er al een, dan die aanpassen
            $dup = q("SELECT id FROM checks WHERE host_id = ? AND type = 'container' AND target = ? AND id <> ?",
                [(int) ($in['host_id'] ?? q('SELECT host_id FROM checks WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn()), $target, (int) ($in['id'] ?? 0)])->fetchColumn();
            if ($dup) {
                out(['error' => "Container $target wordt al bewaakt. Pas die check aan (✎).", 'existing_id' => (int) $dup], 409);
            }
        }
        if ($type === 'log') {
            // Bron: een logbestand (absoluut pad), het systemlog (journal), het log van één service, of kernelmeldingen
            if (!preg_match('#^(/[A-Za-z0-9._/@+-]{1,250}|journal|journal:[A-Za-z0-9@._:-]{1,100}|kernel)$#', $target)) {
                out(['error' => 'Bron: geef een logbestand (bv. /var/log/secure), "journal", "journal:sshd" of "kernel"'], 422);
            }
            $pattern = trim((string) ($in['pattern'] ?? ''));
            if ($pattern === '' || mb_strlen($pattern) > 300) {
                out(['error' => 'Geef aan waarnaar gezocht moet worden (maximaal 300 tekens)'], 422);
            }
            if (@preg_match('~' . str_replace('~', '\\~', $pattern) . '~i', '') === false) {
                out(['error' => 'Het zoekpatroon klopt niet (regex). Tip: gebruik | tussen alternatieven, bv. Failed password|Invalid user'], 422);
            }
            $options = json_encode(['pattern' => $pattern, 'preset' => mb_substr((string) ($in['preset'] ?? ''), 0, 30)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($type === 'service') {
            $testType = in_array($in['test_type'] ?? '', ['url', 'port'], true) ? $in['test_type'] : '';
            $testTarget = trim((string) ($in['test_target'] ?? ''));
            if ($testType === 'url' && !preg_match('#^https?://[^\s]+$#i', $testTarget)) {
                out(['error' => 'Extra test: geef een URL die met http:// of https:// begint, bv. http://localhost:8080/health'], 422);
            }
            if ($testType === 'port' && !preg_match('#^(([A-Za-z0-9.\-]+|\[[0-9a-fA-F:]+\]):)?\d{1,5}$#', $testTarget)) {
                out(['error' => 'Extra test: geef een poort (8080) of host:poort (localhost:8080)'], 422);
            }
            // Eigen poorten/sockets: "8080, 127.0.0.1:9000, [::1]:80, /run/php-fpm/www.sock" (max. 10)
            $ports = [];
            foreach (preg_split('/[\s,;]+/', trim((string) ($in['ports'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $pt) {
                $isSocket = preg_match('#^/[A-Za-z0-9._/@+-]{1,200}$#', $pt);
                $isPort = preg_match('#^(([A-Za-z0-9.\-]+|\[[0-9a-fA-F:]+\]):)?(\d{1,5})$#', $pt, $pm) && (int) $pm[3] >= 1 && (int) $pm[3] <= 65535;
                if (!$isSocket && !$isPort) {
                    out(['error' => "Poort of socket \"$pt\" klopt niet. Gebruik bv. 8080, 127.0.0.1:9000 of /run/php-fpm/www.sock"], 422);
                }
                $ports[] = $pt;
            }
            if (count($ports) > 10) {
                out(['error' => 'Geef maximaal 10 poorten of sockets op'], 422);
            }
            $options = json_encode(['deep' => ($in['deep'] ?? true) ? 1 : 0] + ($ports ? ['ports' => implode(' ', array_unique($ports))] : []) + ($testType !== '' ? [
                'test_type' => $testType, 'test_target' => mb_substr($testTarget, 0, 300),
                'test_expect' => mb_substr(trim((string) ($in['test_expect'] ?? '')), 0, 100),
            ] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $name = trim((string) ($in['name'] ?? '')) ?: ['ping' => 'Ping', 'tcp' => 'Poort', 'http' => 'HTTP', 'ssl' => 'Certificaat', 'disk' => 'Disk', 'service' => 'Service', 'podman' => 'Podman',
            'container' => '📦', 'cpu' => 'CPU', 'ram' => 'RAM', 'log' => 'Log', 'command' => 'Commando',
            'ha' => ['backup' => 'HA back-up', 'integrations' => 'HA integraties', 'repairs' => 'HA systeemstatus'][$metric] ?? 'HA'][$type] . ($target !== '' ? " $target" : '');
        // Eigen meldregels voor deze check (null = de algemene uit Instellingen → Alarmgedrag)
        $notify = null;
        if (is_array($in['notify'] ?? null)) {
            $notify = json_encode(['rules' => NotifyRules::cleanRules((array) ($in['notify']['rules'] ?? [])),
                'channels' => NotifyRules::cleanChannels((array) ($in['notify']['channels'] ?? []))]
                + (is_array($in['notify']['flap'] ?? null) ? ['flap' => NotifyRules::cleanFlap($in['notify']['flap'])] : []));
        }
        // Stille uren: null = algemeen, of eigen vensters / nooit stil
        $quiet = is_array($in['quiet'] ?? null) && ($qo = NotifyRules::cleanQuietOwn($in['quiet'])) !== null ? json_encode($qo) : null;
        $params = [$type, $target, mb_substr($name, 0, 100), $warn, $crit,
            max(30, (int) ($in['interval_sec'] ?? 60)), !empty($in['enabled']) ? 1 : 0, ($in['verify_ssl'] ?? true) ? 1 : 0, $metric, $options, $notify, $quiet, $command];
        if (!empty($in['id'])) {
            q('UPDATE checks SET type = ?, target = ?, name = ?, warn_threshold = ?, crit_threshold = ?, interval_sec = ?, enabled = ?, verify_ssl = ?, metric = ?, options = ?, notify = ?, quiet = ?, command = ? WHERE id = ?',
                [...$params, (int) $in['id']]);
            $id = (int) $in['id'];
        } else {
            q('INSERT INTO checks (type, target, name, warn_threshold, crit_threshold, interval_sec, enabled, verify_ssl, metric, options, notify, quiet, command, host_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
        // Wachtwoorden en tokens nooit terugsturen, alleen of ze ingevuld zijn
        foreach (SECRET_SETTINGS as $k) {
            $s[$k] = ($s[$k] ?? '') !== '' ? '••••••••' : '';
        }
        unset($s['ack_secret'], $s['mqtt_topics'], $s['mqtt_hash'], $s['stats_cache'], $s['update_cache']);
        $s['alerts_days'] = (string) Monitor::alertDays();
        out($s);

    case 'settings_save':
        $in = input();
        $allowed = ['pushover_enabled', 'pushover_user', 'pushover_token', 'pushover_priority_crit', 'pushover_priority_warn',
            'pushover_sound', 'alert_after', 'repeat_minutes', 'notify_warn', 'notify_recovery', 'agent_stale_minutes', 'retention_days', 'alerts_days', 'auth_events', 'auth_ignore',
            'ha_webhook_enabled', 'ha_webhook_url', 'ha_webhook_verify_ssl', 'ha_webhook_events', 'notify_recovery_acked',
            'email_enabled', 'email_mode', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_user', 'smtp_pass', 'smtp_verify_ssl', 'mail_from', 'mail_to',
            'mqtt_enabled', 'mqtt_host', 'mqtt_port', 'mqtt_tls', 'mqtt_verify_ssl', 'mqtt_user', 'mqtt_pass', 'mqtt_prefix', 'mqtt_base_topic',
            'disk_forecast_days', 'auto_maint_enabled', 'auto_maint_after', 'log_window', 'agent_auto_update',
            'backup_freq', 'backup_time', 'backup_weekday', 'backup_keep', 'backup_history', 'backup_pass',
            'notify_rules', 'notify_channels', 'notify_unknown', 'notify_group_seconds', 'notify_flap', 'notify_quiet',
            'update_auto', 'update_time', 'update_backup'];
        if (isset($in['update_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['update_time'])) {
            out(['error' => 'Tijdstip voor automatisch bijwerken: gebruik uu:mm, bv. 04:00'], 422);
        }
        if (isset($in['notify_quiet'])) {
            $in['notify_quiet'] = json_encode(NotifyRules::cleanQuiet((array) (is_string($in['notify_quiet']) ? json_decode($in['notify_quiet'], true) : $in['notify_quiet'])));
        }
        if (isset($in['notify_flap'])) {
            $in['notify_flap'] = json_encode(NotifyRules::cleanFlap((array) (is_string($in['notify_flap']) ? json_decode($in['notify_flap'], true) : $in['notify_flap'])));
        }
        // Meldregels en kanalen per status: alleen geldige waarden opslaan
        if (isset($in['notify_rules'])) {
            $in['notify_rules'] = json_encode(array_replace(NotifyRules::defaults()['rules'],
                NotifyRules::cleanRules((array) (is_string($in['notify_rules']) ? json_decode($in['notify_rules'], true) : $in['notify_rules']))));
        }
        if (isset($in['notify_channels'])) {
            $in['notify_channels'] = json_encode(array_replace(NotifyRules::defaults()['channels'],
                NotifyRules::cleanChannels((array) (is_string($in['notify_channels']) ? json_decode($in['notify_channels'], true) : $in['notify_channels']))));
        }
        if (isset($in['auth_ignore'])) {
            $in['auth_ignore'] = implode(' ', array_slice(parse_list(mb_substr((string) $in['auth_ignore'], 0, 2000)), 0, 100));
        }
        if (isset($in['alerts_days'])) {
            $in['alerts_days'] = (string) max(1, min(3650, (int) $in['alerts_days']));
        }
        if (isset($in['notify_group_seconds'])) {
            $in['notify_group_seconds'] = (string) max(0, min(3600, (int) $in['notify_group_seconds']));
        }
        if (($in['ha_webhook_url'] ?? '') !== '' && !preg_match('#^https?://\S+$#i', trim((string) $in['ha_webhook_url']))) {
            out(['error' => 'Webhook-URL moet met http:// of https:// beginnen'], 422);
        }
        if (($in['ha_webhook_enabled'] ?? '0') === '1' && trim((string) ($in['ha_webhook_url'] ?? '')) === '') {
            out(['error' => 'Vul een webhook-URL in of zet de webhook uit'], 422);
        }
        if (($in['email_enabled'] ?? '0') === '1' && (trim((string) ($in['smtp_host'] ?? '')) === '' || trim((string) ($in['mail_to'] ?? '')) === '')) {
            out(['error' => 'Vul de SMTP-server en een ontvanger in, of zet e-mail uit'], 422);
        }
        if (($in['mqtt_enabled'] ?? '0') === '1' && trim((string) ($in['mqtt_host'] ?? '')) === '') {
            out(['error' => 'Vul de MQTT-server in, of zet MQTT uit'], 422);
        }
        foreach (['mqtt_prefix', 'mqtt_base_topic'] as $k) {
            if (isset($in[$k]) && !preg_match('#^[A-Za-z0-9_\-/]{1,64}$#', trim((string) $in[$k]))) {
                out(['error' => 'Een MQTT-topic mag alleen letters, cijfers, _ - en / bevatten'], 422);
            }
        }
        if (isset($in['backup_freq']) && !in_array($in['backup_freq'], ['off', 'daily', 'weekly', 'monthly'], true)) {
            out(['error' => 'Ongeldige back-upfrequentie'], 422);
        }
        if (isset($in['backup_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['backup_time'])) {
            out(['error' => 'Vul de back-uptijd in als uu:mm, bv. 03:15'], 422);
        }
        if (isset($in['backup_keep']) && ((int) $in['backup_keep'] < 1 || (int) $in['backup_keep'] > 365)) {
            out(['error' => 'Aantal back-ups bewaren: 1 tot 365'], 422);
        }
        if (isset($in['backup_weekday']) && ((int) $in['backup_weekday'] < 1 || (int) $in['backup_weekday'] > 7)) {
            out(['error' => 'Ongeldige dag voor de back-up'], 422);
        }
        if (($in['backup_pass'] ?? '') !== '' && $in['backup_pass'] !== '••••••••' && mb_strlen((string) $in['backup_pass']) < 8) {
            out(['error' => 'Het back-upwachtwoord moet minstens 8 tekens hebben'], 422);
        }
        // Schema gewijzigd: tellen vanaf nu (anders volgt meteen een "ingehaalde" back-up)
        foreach (['backup_freq', 'backup_time', 'backup_weekday'] as $k) {
            if (isset($in[$k]) && (string) $in[$k] !== (string) setting($k, '')) {
                save_setting('backup_last_auto', now());
                save_setting('backup_retry_at', '');
                break;
            }
        }
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            if (in_array($k, SECRET_SETTINGS, true) && $v === '••••••••') {
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

    case 'email_test':
        // Test met de (nog niet opgeslagen) waarden uit het formulier
        $in = input();
        $cfg = Mailer::config();
        foreach (array_keys($cfg) as $k) {
            if (isset($in[$k]) && !($k === 'smtp_pass' && $in[$k] === '••••••••')) {
                $cfg[$k] = trim((string) $in[$k]);
            }
        }
        session_write_close();
        $res = Mailer::send('🧪 RzMon test', "Als je dit leest, werken e-mailmeldingen van RzMon! 🎉\n\nVerstuurd op " . date('d-m-Y H:i:s') . '.', $cfg);
        out($res, $res['ok'] ? 200 : 422);

    case 'mqtt_test':
        $in = input();
        $cfg = Mqtt::config();
        foreach (array_keys($cfg) as $k) {
            if (isset($in[$k]) && !($k === 'mqtt_pass' && $in[$k] === '••••••••')) {
                $cfg[$k] = trim((string) $in[$k]);
            }
        }
        session_write_close();
        $r = Mqtt::publishAll($cfg, true);
        if (!$r['ok']) {
            out(['error' => $r['error']], 422);
        }
        out(['ok' => true, 'message' => "Verbonden ✔ — {$r['entities']} sensoren voor {$r['hosts']} server(s) naar Home Assistant gestuurd"]);

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
            'lan_as_admin' => setting('lan_as_admin', '0') === '1',
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
        save_setting('lan_as_admin', !empty($in['lan_as_admin']) ? '1' : '0');
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

    // ---------- Gebruikers en tweestapsverificatie ----------
    case 'users':
        $rows = q('SELECT id, username, email, role, totp_secret IS NOT NULL AS totp, created_at, last_login,
                          (SELECT COUNT(*) FROM remember_tokens r WHERE r.user_id = users.id) AS devices FROM users'
            . ($isAdmin ? '' : ' WHERE id = ' . (int) $user['id']) . ' ORDER BY username')->fetchAll();
        foreach ($rows as &$r) {
            $r['totp'] = (bool) $r['totp'];
            $r['me'] = (int) $r['id'] === $user['id'];
        }
        unset($r);
        out($rows);

    case 'user_create':
        $in = input();
        $email = trim((string) ($in['email'] ?? ''));
        try {
            if (q('SELECT 1 FROM users WHERE username = ?', [trim((string) ($in['username'] ?? ''))])->fetchColumn()) {
                throw new InvalidArgumentException('Deze gebruikersnaam bestaat al');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ongeldig e-mailadres');
            }
            if ($email !== '' && q('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)', [$email])->fetchColumn()) {
                throw new InvalidArgumentException('Dit e-mailadres hoort al bij een ander account');
            }
            Installer::saveUser(trim((string) ($in['username'] ?? '')), (string) ($in['password'] ?? ''),
                in_array($in['role'] ?? '', array_keys(ROLES), true) ? $in['role'] : 'user');
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        $id = (int) db()->lastInsertId();
        if ($email !== '') {
            q('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
        }
        out(['ok' => true, 'id' => $id]);

    case 'user_password':
        $in = input();
        if (strlen((string) ($in['password'] ?? '')) < 8) {
            out(['error' => 'Wachtwoord moet minimaal 8 tekens zijn'], 422);
        }
        q('UPDATE users SET password_hash = ?, pw_changed_at = ? WHERE id = ?', [password_hash((string) $in['password'], PASSWORD_DEFAULT), now(), (int) ($in['id'] ?? 0)]);
        if ((int) ($in['id'] ?? 0) === $user['id']) {
            $_SESSION['auth_at'] = time();   // eigen account: deze sessie blijft
        }
        // Nieuw wachtwoord gezet door een beheerder: onthouden apparaten van die gebruiker vergeten
        q('DELETE FROM remember_tokens WHERE user_id = ?', [(int) ($in['id'] ?? 0)]);
        out(['ok' => true]);

    case 'user_email':
        // E-mailadres voor "wachtwoord vergeten" (beheerder: iedereen; anderen: alleen zichzelf)
        $id = (int) (input()['id'] ?? 0);
        $ownOnly($id);
        $email = trim((string) (input()['email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            out(['error' => 'Ongeldig e-mailadres'], 422);
        }
        if ($email !== '' && q('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?', [$email, $id])->fetchColumn()) {
            out(['error' => 'Dit e-mailadres hoort al bij een ander account'], 422);
        }
        q('UPDATE users SET email = ? WHERE id = ?', [$email !== '' ? $email : null, $id]);
        out(['ok' => true]);

    case 'pw_reset_info':
        out(['unavailable' => PasswordReset::unavailable(),
             'log' => q('SELECT l.created_at, l.ident, l.ip, l.event, u.username FROM password_reset_log l
                         LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 50')->fetchAll()]);

    case 'user_role':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $role = (string) ($in['role'] ?? '');
        if (!isset(ROLES[$role])) {
            out(['error' => 'Onbekende rol'], 422);
        }
        if ($id === $user['id']) {
            out(['error' => 'Je kunt je eigen rol niet wijzigen (laat een andere beheerder dat doen)'], 422);
        }
        if ($role !== 'admin' && q("SELECT role FROM users WHERE id = ?", [$id])->fetchColumn() === 'admin'
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            out(['error' => 'Er moet minimaal één beheerder overblijven'], 422);
        }
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        out(['ok' => true]);

    case 'user_delete':
        $id = (int) (input()['id'] ?? 0);
        if ($id === $user['id']) {
            out(['error' => 'Je kunt jezelf niet verwijderen'], 422);
        }
        if ((int) q('SELECT COUNT(*) FROM users')->fetchColumn() <= 1) {
            out(['error' => 'Er moet minimaal één gebruiker overblijven'], 422);
        }
        if (q("SELECT role FROM users WHERE id = ?", [$id])->fetchColumn() === 'admin'
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            out(['error' => 'Er moet minimaal één beheerder overblijven'], 422);
        }
        q('DELETE FROM users WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'totp_setup':
        // Stap 1: nieuw geheim (nog niet actief) → QR-code scannen
        $ownOnly((int) (input()['id'] ?? 0));
        $u = q('SELECT id, username FROM users WHERE id = ?', [(int) (input()['id'] ?? 0)])->fetch();
        if (!$u) {
            out(['error' => 'Gebruiker niet gevonden'], 404);
        }
        $secret = Totp::newSecret();
        $_SESSION['totp_setup'][(int) $u['id']] = $secret;
        out(['ok' => true, 'secret' => $secret, 'uri' => Totp::uri($secret, $u['username'])]);

    case 'totp_confirm':
        // Stap 2: pas actief na een geldige code, zodat je jezelf niet buitensluit
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $ownOnly($id);
        $secret = $_SESSION['totp_setup'][$id] ?? null;
        if (!$secret) {
            out(['error' => 'Begin opnieuw met instellen'], 422);
        }
        $step = Totp::verify($secret, (string) ($in['code'] ?? ''));
        if ($step === null) {
            out(['error' => 'Code klopt niet. Staat de klok van je telefoon goed?'], 422);
        }
        q('UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?', [$secret, $step, $id]);
        unset($_SESSION['totp_setup'][$id]);
        out(['ok' => true]);

    // ---------- Onthouden apparaten ----------
    case 'devices':
        $uid = (int) ($_GET['user_id'] ?? $user['id']);
        $ownOnly($uid);
        $rows = q('SELECT id, device, ip, created_at, last_used FROM remember_tokens WHERE user_id = ? ORDER BY last_used DESC', [$uid])->fetchAll();
        foreach ($rows as &$r) {
            $r['current'] = (int) $r['id'] === (int) ($_SESSION['remember_id'] ?? 0);
        }
        unset($r);
        out($rows);

    case 'device_forget':
        $id = (int) (input()['id'] ?? 0);
        $owner = q('SELECT user_id FROM remember_tokens WHERE id = ?', [$id])->fetchColumn();
        if ($owner === false) {
            out(['error' => 'Apparaat niet gevonden'], 404);
        }
        $ownOnly((int) $owner);
        q('DELETE FROM remember_tokens WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'device_forget_all':
        $uid = (int) (input()['user_id'] ?? $user['id']);
        $ownOnly($uid);
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]);
        out(['ok' => true]);

    case 'totp_reset':
        $ownOnly((int) (input()['id'] ?? 0));
        q('UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    // ---------- Databasewachtwoord vernieuwen (alleen beheerders) ----------
    case 'db_info':
        $h = Installer::HELPER_DIR;
        $root = realpath(SM_ROOT);
        $db = config('db');
        out([
            'helper' => is_file("$h/helper.installed") && is_writable($h),
            'direct' => Installer::configWritable() || is_writable(SM_CONFIG_FILE),
            'db' => ['host' => $db['host'], 'name' => $db['name'], 'user' => $db['user']],
            'cli_cmd' => "sudo php $root/install/install.php --rotate-db-password",
            'helper_cmd' => "sudo bash $root/install/install-helper.sh",
        ]);

    // ---------------------------------------------------------- Commando's op agents
    case 'agent_commands':
        $hosts = q("SELECT id, name, commands FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll();
        out([
            'global' => (string) setting('agent_commands', 'off'),
            'hosts' => array_map(fn($h) => ['id' => (int) $h['id'], 'name' => $h['name'], 'own' => $h['commands'], 'mode' => AgentCommands::modeFor($h)], $hosts),
            'log' => q('SELECT username, action, detail, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 20')->fetchAll(),
        ]);

    case 'agent_commands_set':
        $in = input();
        $mode = (string) ($in['mode'] ?? '');
        $hostId = (int) ($in['host_id'] ?? 0);
        if ($hostId > 0) {
            $h = q("SELECT id, name, commands FROM hosts WHERE id = ? AND mode = 'agent'", [$hostId])->fetch();
            if (!$h) {
                out(['error' => 'Server niet gevonden'], 404);
            }
            if ($mode !== 'global' && !isset(AgentCommands::MODES[$mode])) {
                out(['error' => 'Ongeldige keuze'], 422);
            }
            $from = AgentCommands::modeFor($h);
            $to = AgentCommands::modeFor(['commands' => $mode === 'global' ? null : $mode]);
            if (AgentCommands::widens($from, $to)) {
                $confirm('TOESTAAN');
            }
            q('UPDATE hosts SET commands = ? WHERE id = ?', [$mode === 'global' ? null : $mode, $hostId]);
            AgentCommands::audit($user['username'], "commando's per server", "{$h['name']}: " . ($mode === 'global' ? 'algemene instelling' : AgentCommands::MODES[$mode]) . " (was: " . AgentCommands::MODES[$from] . ')');
        } else {
            if (!isset(AgentCommands::MODES[$mode])) {
                out(['error' => 'Ongeldige keuze'], 422);
            }
            $from = (string) setting('agent_commands', 'off');
            if (AgentCommands::widens($from, $mode)) {
                $confirm('TOESTAAN');
            }
            save_setting('agent_commands', $mode);
            AgentCommands::audit($user['username'], "commando's algemeen", AgentCommands::MODES[$mode] . ' (was: ' . (AgentCommands::MODES[$from] ?? $from) . ')');
        }
        out(['ok' => true]);

    // ---------------------------------------------------------- RzMon bijwerken
    case 'update_info':
        $cache = Updater::check(!empty($_GET['force']));
        out([
            'current' => RZMON_VERSION,
            'latest' => $cache['latest'] ?? null,
            'available' => !empty($cache['latest']) && version_compare($cache['latest'], RZMON_VERSION, '>'),
            'checked_at' => isset($cache['checked_at']) ? date('Y-m-d H:i:s', (int) $cache['checked_at']) : null,
            'error' => $cache['error'] ?? null,
            'notes' => $cache['notes'] ?? [],
            'unavailable' => Updater::unavailable(),
            'status' => Updater::status(),
            'source' => implode('@', Updater::source()),
            'install_dir' => SM_ROOT,
        ]);

    case 'update_start':
        $backup = !empty(input()['backup']);
        save_setting('update_backup', $backup ? '1' : '0');   // de schakelaar onthouden
        $r = Updater::request($backup, $user['username']);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 409);

    case 'update_status':
        out(['status' => Updater::status(), 'version' => RZMON_VERSION,
            'log' => is_readable(Installer::HELPER_DIR . '/update.log') ? mb_substr((string) file_get_contents(Installer::HELPER_DIR . '/update.log'), -6000) : '']);

    case 'db_rotate':
        $confirm('VERNIEUWEN');
        $h = Installer::HELPER_DIR;
        if (is_file("$h/helper.installed") && is_writable($h)) {
            // Via de hulpdienst (als root): verzoek neerzetten, het resultaat komt binnen een paar seconden
            @unlink("$h/rotate.result");
            $nonce = bin2hex(random_bytes(16));
            if (file_put_contents("$h/rotate.request", $nonce) === false) {
                out(['error' => "Kan geen verzoek neerzetten in $h"], 500);
            }
            $_SESSION['db_rotate'] = ['nonce' => $nonce, 'at' => time()];
            out(['ok' => true, 'pending' => true]);
        }
        if (Installer::configWritable() || is_writable(SM_CONFIG_FILE)) {
            session_write_close();
            $r = Installer::rotateDbPassword();
            out($r, $r['ok'] ? 200 : 422);
        }
        out(['error' => 'De RzMon-map is vergrendeld en de hulpdienst is niet geïnstalleerd', 'need_helper' => true], 409);

    case 'db_rotate_status':
        $p = $_SESSION['db_rotate'] ?? null;
        $h = Installer::HELPER_DIR;
        if (!$p) {
            out(['error' => 'Geen wachtwoordwissel bezig'], 404);
        }
        $res = json_decode((string) @file_get_contents("$h/rotate.result"), true);
        if ($res && hash_equals($p['nonce'], (string) ($res['nonce'] ?? ''))) {
            // Eén keer tonen: resultaat meteen weg
            @unlink("$h/rotate.result");
            unset($_SESSION['db_rotate'], $res['nonce'], $res['at']);
            out($res, $res['ok'] ? 200 : 422);
        }
        if (time() - $p['at'] > 30) {
            unset($_SESSION['db_rotate']);
            @unlink("$h/rotate.request");
            out(['error' => 'De hulpdienst reageert niet. Controleer op de server: systemctl status rzmon-helper.path'], 504);
        }
        out(['pending' => true]);

    // ---------- Back-up en herstel (alleen beheerders) ----------
    case 'backup_info':
        $root = realpath(SM_ROOT);
        $unlock = (int) ($_SESSION['backup_unlock'] ?? 0) + 600 - time();
        $next = Backup::nextSlot();
        out([
            'problem' => Backup::problem(),
            'fix_cmd' => Backup::fixCommand(),
            'dir' => Backup::dir(),
            'encryption' => Backup::encryptionSupported(),
            'has_pass' => (string) setting('backup_pass', '') !== '',
            'next' => $next ? date('Y-m-d H:i:s', $next) : null,
            'last_auto' => setting('backup_last_auto'),
            'last_error' => (string) setting('backup_last_error', ''),
            'unlocked' => max(0, $unlock),
            'max_upload' => Backup::MAX_UPLOAD,
            'list' => Backup::problem() === null ? Backup::list() : [],
            'cli_backup' => "sudo php $root/install/install.php --backup",
            'cli_restore' => "sudo php $root/install/install.php --restore /pad/naar/rzmon-backup-….zip",
        ]);

    case 'backup_create':
        session_write_close();   // andere verzoeken van deze gebruiker niet blokkeren
        $r = Backup::create('manual', isset(input()['history']) ? (bool) input()['history'] : null);
        out($r, $r['ok'] ? 200 : 422);

    case 'backup_delete':
        if (!Backup::delete((string) (input()['file'] ?? ''))) {
            out(['error' => 'Back-up niet gevonden'], 404);
        }
        out(['ok' => true]);

    case 'backup_unlock':
        // Downloaden kan 10 minuten na het bevestigen (de zip bevat config.php met het databasewachtwoord)
        $confirm('DOWNLOADEN');
        $_SESSION['backup_unlock'] = time();
        out(['ok' => true, 'unlocked' => 600]);

    case 'backup_download':
        $path = Backup::path((string) ($_GET['file'] ?? ''));
        if ((int) ($_SESSION['backup_unlock'] ?? 0) < time() - 600) {
            out(['error' => 'Bevestig eerst met je wachtwoord'], 403);
        }
        if ($path === null) {
            out(['error' => 'Back-up niet gevonden'], 404);
        }
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;

    case 'backup_upload':
        // De zip komt in stukken van 1 MB (ruwe body), zie app.js
        session_write_close();
        $r = Backup::uploadChunk((string) ($_GET['id'] ?? ''), (int) ($_GET['offset'] ?? -1),
            (string) file_get_contents('php://input'), ($_GET['final'] ?? '') === '1');
        out($r, $r['ok'] ? 200 : 422);

    case 'backup_restore':
        $confirm('HERSTELLEN');
        $path = Backup::path((string) (input()['file'] ?? ''));
        if ($path === null) {
            out(['error' => 'Back-up niet gevonden'], 404);
        }
        session_write_close();
        $r = Backup::restore($path, trim((string) (input()['zip_pass'] ?? '')) ?: null);
        out($r, $r['ok'] ? 200 : 422);

    case 'password':
        if ($user['id'] === 0) {
            out(['error' => 'Je bent automatisch ingelogd (lokaal netwerk). Log in met je eigen account om je wachtwoord te wijzigen.'], 422);
        }
        $in = input();
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!$row || !password_verify((string) ($in['current'] ?? ''), $row['password_hash'])) {
            out(['error' => 'Huidig wachtwoord onjuist'], 422);
        }
        if (strlen((string) ($in['new'] ?? '')) < 8) {
            out(['error' => 'Nieuw wachtwoord moet minimaal 8 tekens zijn'], 422);
        }
        // Andere sessies van dit account worden uitgelogd (pw_changed_at); deze blijft
        q('UPDATE users SET password_hash = ?, pw_changed_at = ? WHERE id = ?', [password_hash((string) $in['new'], PASSWORD_DEFAULT), now(), $user['id']]);
        $_SESSION['auth_at'] = time();
        // Eigen wachtwoord gewijzigd: alle andere onthouden apparaten vergeten (dit apparaat blijft)
        q('DELETE FROM remember_tokens WHERE user_id = ? AND id <> ?', [$user['id'], (int) ($_SESSION['remember_id'] ?? 0)]);
        out(['ok' => true]);

    default:
        out(['error' => 'Onbekende actie'], 404);
}
