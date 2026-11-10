<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): de agents (rapport, tests, koppelen, script) en het installatiescript; zonder sessie.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 */
defined('RZ_API') || exit;

// ---------- Agent en installatiescript (openbaar: bevatten niets geheims) ----------
// Zo kan een server de agent rechtstreeks van RzSense installeren, ook als de
// GitHub-repository privé is.
if ($action === 'ping') {
    // Voor de verbindingstest van de agent: "draait hier RzSense?". Van buiten (niet herkenbaar) alleen voor bekende agents.
    if (!Stealth::pingFull()) {
        Stealth::notFound();
    }
    out(['ok' => true, 'app' => 'RzMon', 'agent_version' => AGENT_LATEST, 'version' => RZMON_VERSION, 'configured' => is_configured(), 'time' => date('c')]);
}
// 1.139.0: handtekening (ed25519, base64) van de agent en het installatiescript, gemaakt in GitHub; de agent controleert hem
if ($action === 'agent_script_sig' || $action === 'agent_install_sig') {
    if (!Stealth::scriptAllowed()) {
        Stealth::notFound();
    }
    $f = SM_ROOT . '/agent/' . ($action === 'agent_script_sig' ? 'rzmon-agent.sh.sig' : 'install-agent.sh.sig');
    if (!is_file($f)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo trim((string) file_get_contents($f));
    exit;
}
if ($action === 'agent_script' || $action === 'agent_install') {
    // Van buiten (niet herkenbaar): alleen voor bekende agents of met de downloadsleutel uit de installatiecommando's
    if (!Stealth::scriptAllowed()) {
        Stealth::notFound();
    }
    header('Content-Type: text/x-shellscript; charset=utf-8');
    header('Cache-Control: no-store');
    // Het installatiescript krijgt de downloadsleutel mee, zodat het daarna ook de agent zelf kan ophalen
    echo $action === 'agent_install' && is_configured() ? Stealth::installScript() : file_get_contents(SM_ROOT . '/agent/' . ($action === 'agent_script' ? 'rzmon-agent.sh' : 'install-agent.sh'));
    exit;
}

if (!is_configured()) {
    out(['error' => t('RzSense is nog niet ingesteld'), 'setup' => true], 503);
}

// ---------- Agent endpoint (token-auth, geen sessie) ----------

// Extra tests (URL of poort) die bij service-checks van deze server zijn ingesteld, voor de agent.
// Platte tekst, één test per regel: id <tab> type <tab> doel <tab> verwachte tekst.
/**
 * Wat de agent van RzSense moet weten, één regel per item (tab-gescheiden):
 * welke services hij bewaakt, logzoekopdrachten, eigen poorten van services en extra tests.
 */

// Koppelen met een koppelcode (agent 1.50.0+): het bericht is versleuteld met een sleutel uit de koppelcode (X-Agent-Pair:
// het kenmerk daarvan). Het antwoord, ook versleuteld, bevat de eigen sleutel van de agent. De code werkt één keer.
if ($action === 'agent_pair') {
    $pid = (string) ($_SERVER['HTTP_X_AGENT_PAIR'] ?? '');
    $host = preg_match('/^[0-9a-f]{16}$/', $pid) ? AgentCrypto::pairHost($pid) : null;
    $ip = client_ip();
    $deny = static function (string $reason, ?array $h = null) use ($ip): never {
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$h['id'] ?? null, mb_substr($ip, 0, 45), mb_substr($reason, 0, 255)]);
        Security::unauthorized('agent', $reason, $h ? [t('server') => $h['name']] : []);
        out(['error' => t('Geweigerd') . ": $reason", 'reason' => $reason], 403);
    };
    if (!$host) {
        $deny(t('ongeldige of verlopen koppelcode'));
    }
    if ($host['mode'] !== 'agent') {
        $deny(t("server '{name}' staat niet op type 'Remote met agent'", ['name' => $host['name']]), $host);
    }
    try {
        [$plain, $pkey, $nonce] = AgentCrypto::open((string) file_get_contents('php://input'), [$host['pair_key']]);
    } catch (RuntimeException $e) {
        $deny($e->getMessage(), $host);
    }
    $in = json_decode($plain, true) ?: [];
    $GLOBALS['agent_seal'] = [$pkey, $nonce];
    // Nieuwe sleutel (de vorige vervalt meteen), het IP-adres van nu wordt het bekende, de code is op
    $key = AgentCrypto::newHostKey((int) $host['id'], false);
    // 1.100.0: met de eigen sleutel is het token niet meer nodig (agent_token = NULL)
    q('UPDATE hosts SET agent_key_seen = 1, agent_token = NULL, pair_key = NULL, pair_until = NULL, agent_ips = ?, agent_ip_new = NULL, agent_ip_new_at = NULL WHERE id = ?',
        [mb_substr($ip, 0, 45), $host['id']]);
    $ver = preg_match('/^\d+\.\d+\.\d+$/', (string) ($in['agent_version'] ?? '')) ? $in['agent_version'] : '?';
    AgentCommands::audit('agent', 'Agent gekoppeld', "{$host['name']} vanaf $ip (agent $ver)");
    Monitor::sysInfo('agent_connect', (int) $host['id'], (string) $host['name'], 'ℹ️ ' . t('Agent gekoppeld: {name}', ['name' => $host['name']]),
        fn() => t('Agent op {name} is gekoppeld met een koppelcode en heeft een eigen sleutel', ['name' => $host['name']]) . " · IP $ip · agent $ver", $ip);
    out(['ok' => true, 'host' => $host['name'], 'agent_key' => $key, 'pin' => AgentCrypto::pin(false, true, $host) ?? '',
        'agent_sha256' => hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh')]);
}

if ($action === 'agent_tests') {
    $host = agent_host();
    // Eigen sleutel: ondertekend verzoek. Anders (token) een agent met de algemene sleutel (header X-Agent-Enc: kenmerk:nonce).
    // Met een sleutel gaat de lijst versleuteld terug.
    if ($auth = agent_get_auth($host, 'agent_tests')) {
        [$key, $m[2]] = $auth;
    } else {
        $hdr = (string) ($_SERVER['HTTP_X_AGENT_ENC'] ?? '');
        $key = preg_match('/^([0-9a-f]{16}):([0-9a-f]{32})$/', $hdr, $m) ? AgentCrypto::keyById($m[1]) : null;
    }
    if ($key === null && AgentCrypto::hostMode($host) === 'required') {
        Security::unauthorized('agent', t('onversleuteld bericht, maar versleuteling is verplicht voor deze server'), [t('server') => $host['name']]);
        out(['error' => t('Geweigerd')], 403);
    }
    header('Content-Type: text/plain; charset=utf-8');
    $text = agent_tests_text((int) $host['id'], $key !== null);
    echo $key !== null ? AgentCrypto::seal($text, $key, $m[2]) : $text;
    exit;
}

// Alleen het token controleren, zonder gegevens te versturen (verbindingstest)
if ($action === 'agent_check') {
    $host = agent_host();
    if ($auth = agent_get_auth($host, 'agent_check')) {
        $GLOBALS['agent_seal'] = $auth;   // antwoord versleuteld: zo weet de agent dat hij echt met RzSense praat
    }
    out(['ok' => true, 'host' => $host['name'], 'your_ip' => client_ip(), 'own_key' => (bool) $auth]);
}

// Voortgang van "pakketten bijwerken" (agent 1.48.0+): elke paar seconden tijdens apt/dnf, en aan het eind de uitkomst
if ($action === 'agent_pkg_progress') {
    $host = agent_host();
    agent_body($host);
    Vulns::upgradeProgress($host, input());
    out(['ok' => true]);
}

// 1.210.0: logs van de agent (agent 1.83.0), los van het rapport: gecomprimeerd en altijd versleuteld (onversleuteld: geweigerd)
if ($action === 'agent_logs') {
    $host = agent_host();
    if (!agent_body($host)) {
        out(['error' => t('Logs worden alleen versleuteld aangenomen')], 403);
    }
    if (!LogStore::enabled()) {
        out(['ok' => false, 'off' => true]);
    }
    out(LogStore::ingest($host, raw_input()));
}

if ($action === 'agent') {
    $host = agent_host();
    $encrypted = agent_body($host);
    $in = input();
    if (!$in) {
        // Agent 1.20.0–1.20.3 zette servicenamen als systemd-fsck@dev-disk-by\x2duuid… ongewijzigd in de JSON.
        // Zo'n losse backslash herstellen, dan blijft die agent werken (en kan hij met de knop bijgewerkt worden).
        $raw = raw_input();
        $fixed = preg_replace_callback('/\\\\(.?)/s', static fn($m) => $m[1] !== '' && str_contains('"\\\\/bfnrtu', $m[1]) ? $m[0] : '\\' . $m[0], $raw);
        $in = json_decode((string) $fixed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE) ?: [];
    }
    $salvaged = [];
    if (!$in) {
        // Nog steeds onleesbaar: per onderdeel lezen en alleen wat kapot is weglaten (1.99.2)
        $jsonErr = json_last_error_msg();
        [$in, $salvaged] = agent_json_salvage(raw_input());
        if (!isset($in['agent_version'])) {
            $in = [];   // te weinig over om als rapport te gebruiken
        }
    }
    if (!$in) {
        // Onleesbaar rapport: niets wegschrijven (anders lijken alle gegevens verdwenen) en de reden loggen
        $reason = 'onleesbaar rapport (JSON: ' . ($jsonErr ?? json_last_error_msg()) . ($salvaged ? ', bij ' . implode(', ', array_column($salvaged, 'key')) : '') . '); werk de agent bij';
        q('INSERT INTO agent_log (host_id, ip, reason) VALUES (?, ?, ?)', [$host['id'], mb_substr(client_ip(), 0, 45), $reason]);
        out(['error' => "Geweigerd: $reason"], 400);
    }
    Perf::part('rapport:ontvangen');   // 1.129.0: aanmelden, ontsleutelen en inlezen
    // 1.137.0 (agent 1.69.0): hartslag tijdens een lange run: alleen "ik leef", de gegevens blijven staan
    if (!empty($in['beat'])) {
        out(['ok' => true, 'beat' => AgentQueue::beat($host, $in)]);
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
    // Opstarttijd (agent 1.46.0+): voor de check Herstart
    if (isset($in['boot']) && is_numeric($in['boot']) && (int) $in['boot'] > 0) {
        $st->execute([$host['id'], 'boot', (string) (int) $in['boot'], $now]);
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
            // De hele keten (agent 1.59.0): hoeveel certificaten, en welk het eerst verloopt
            'chain_n' => (int) ($c['chain_n'] ?? 0),
            'chain_min' => isset($c['chain_min']) && is_numeric($c['chain_min']) ? (int) $c['chain_min'] : null,
            'chain_cn' => mb_substr((string) ($c['chain_cn'] ?? ''), 0, 200),
        ], JSON_UNESCAPED_UNICODE), $now]);
    }
    // 1.213.0 (agent 1.84.0): welke logs er op de server zijn, om in de Agent Manager uit te kiezen
    if (isset($in['log_scan']) && is_array($in['log_scan'])) {
        LogStore::storeScan((int) $host['id'], $in['log_scan']);
    }
    // Gevonden certificaatbestanden (agent 1.59.0), om bij een SSL-check uit te kiezen
    if (isset($in['cert_found']) && is_array($in['cert_found'])) {
        $found = [];
        foreach (array_slice($in['cert_found'], 0, 300) as $c) {
            $path = (string) ($c['path'] ?? '');
            if (is_array($c) && preg_match('#^/[^\s]{1,250}$#', $path)) {
                $found[] = ['path' => $path, 'cn' => mb_substr((string) ($c['cn'] ?? ''), 0, 120), 'issuer' => mb_substr((string) ($c['issuer'] ?? ''), 0, 120),
                    'not_after' => is_numeric($c['not_after'] ?? null) ? (int) $c['not_after'] : null, 'n' => (int) ($c['n'] ?? 1),
                    'chain_min' => is_numeric($c['chain_min'] ?? null) ? (int) $c['chain_min'] : null];
            }
        }
        $st->execute([$host['id'], 'certfound', json_encode($found, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
    }
    // Processen (agent 1.59.0): per proces-check de meting, en een lijst van wat er draait (om uit te kiezen)
    foreach (array_slice((array) ($in['procs'] ?? []), 0, 200, true) as $pid => $pv) {
        if (ctype_digit((string) $pid) && is_array($pv)) {
            $st->execute([$host['id'], 'proc:' . (int) $pid, json_encode(['n' => (int) ($pv['n'] ?? 0), 'pids' => mb_substr((string) ($pv['pids'] ?? ''), 0, 200),
                'cpu' => (float) ($pv['cpu'] ?? 0), 'rss' => (int) ($pv['rss'] ?? 0), 'start' => is_numeric($pv['start'] ?? null) ? (int) $pv['start'] : null]), $now]);
        }
    }
    if (isset($in['proc_list']) && is_array($in['proc_list'])) {
        $pl = [];
        foreach (array_slice($in['proc_list'], 0, 300) as $e) {
            if (is_array($e) && preg_match('/^[^\x00-\x1f]{1,64}$/u', (string) ($e[0] ?? ''))) {
                $pl[] = [(string) $e[0], (int) ($e[1] ?? 1), mb_substr((string) ($e[2] ?? ''), 0, 32), mb_substr((string) ($e[3] ?? ''), 0, 120)];
            }
        }
        usort($pl, static fn($a, $b) => strcasecmp($a[0], $b[0]));
        $st->execute([$host['id'], 'proclist', json_encode($pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
    }
    // Podman: overzicht, en per container en pod een eigen regel (sleutel: naam, of gebruiker/naam bij rootless)
    // 1.174.0 (agent 1.77.0): TrueNAS SCALE via midclt: meldingen, taken, apps, update, schijftemperaturen
    if (isset($in['truenas']) && is_array($in['truenas'])) {
        try {
            Truenas::fromAgent($host, $in['truenas'], $st, $now);
        } catch (Throwable $e) {
            error_log('RzSense: TrueNAS-gegevens niet verwerkt: ' . $e->getMessage());
        }
    }
    // 1.177.0 (agent 1.78.0): pad-checks, doorvoer en de iperf3-ontvanger
    try {
        Paths::fromAgent($host, $in);
        Db::fromAgent($host, $in, $now);   // 1.200.0 (agent 1.81.0): databases
    } catch (Throwable $e) {
        error_log('RzSense: pad-gegevens niet verwerkt: ' . $e->getMessage());
    }
    // 1.188.0 (agent 1.80.0): verkeer van de netwerkkaarten, voor Netwerk → Verkeer als UniFi deze server niet ziet
    try {
        NetTraffic::fromAgent($host, $in);
    } catch (Throwable $e) {
        error_log('RzSense: netwerkverkeer van de agent niet verwerkt: ' . $e->getMessage());
    }
    // 1.178.0 (agent 1.79.0): antwoord op 🧪 Test
    try {
        AgentTest::fromAgent($host, $in);
    } catch (Throwable $e) {
        error_log('RzSense: zelftest niet verwerkt: ' . $e->getMessage());
    }
    // Proxmox VE (agent 1.61.0): guests, opslag, ZFS, back-ups en taken
    if (isset($in['pve']) && is_array($in['pve'])) {
        try {
            Pve::fromAgent($host, $in['pve'], $st, $now);
        } catch (Throwable $e) {
            error_log('RzSense: Proxmox-gegevens niet verwerkt: ' . $e->getMessage());
        }
    }
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
    // Logbestanden: treffers per rapport bijhouden (24 uur), zodat RzSense over een periode kan tellen
    if (isset($in['logs']) && is_array($in['logs'])) {
        $prevLogs = [];
        foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'logrun:%'", [$host['id']]) as $r) {
            $prevLogs[substr($r['metric'], 7)] = json_decode($r['value'], true) ?: [];
        }
        foreach (array_slice($in['logs'], 0, 100, true) as $id => $l) {
            if (!ctype_digit((string) $id) || !is_array($l)) {
                continue;
            }
            if (!empty($l['skip'])) {
                // Niet aan de beurt (interval van de check): vorige stand bewaren
                q("UPDATE agent_data SET updated_at = ? WHERE host_id = ? AND metric = ?", [$now, $host['id'], 'logrun:' . $id]);
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
    Perf::part('rapport:meetwaarden');
    // Logins, sudo en su: naar de pagina Meldingen
    if (is_array($in['auth'] ?? null)) {
        AuthEvents::store($host, $in['auth']);
        SshEvents::store($host, $in['auth']);   // 1.152.0: losse SSH-pogingen en sessies (agent 1.74.0)
    }
    Perf::part('rapport:logins');
    // IDS: vergelijken met de basismeting, incidenten, brute force blokkeren
    if (is_array($in['ids'] ?? null)) {
        Ids::process($host, $in['ids'], (array) ($in['auth'] ?? []), (array) ($in['ports'] ?? []));
    }
    // Isoleren en gebruikers vergrendelen (agent 1.57.0): de stand bij de agent
    Ids::agentState($host, $in);
    Perf::part('rapport:ids');
    // Logs van webdiensten (agent 1.58.0): SWAG, Home Assistant, Vaultwarden
    if (is_array($in['weblog'] ?? null)) {
        WebLog::process($host, $in['weblog']);
        // 1.110.0: payload-inspectie van verdachte verzoeken (SWAG)
        if (is_array($in['weblog']['swag']['req'] ?? null)) {
            Inspect::requests($host, $in['weblog']['swag']['req']);
        }
    }
    Perf::part('rapport:weblogs');
    // 1.110.0: endpoint-sensoren (processen, netwerk, bestanden, containers)
    $edrResp = is_array($in['edr'] ?? null) ? Edr::process($host, $in['edr']) : [];
    // 1.111.0: vormen de signalen van deze server (regels, afwijkingen, IDS) samen een keten?
    Perf::part('rapport:edr');
    Chain::check($host);
    Perf::part('rapport:ketens');
    // Commando-checks: uitvoer en exitcode (RzSense vergelijkt met het verwachte antwoord)
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
        if (in_array($k, ['backup', 'integrations', 'repairs', 'os'], true) && is_array($v)) {
            $st->execute([$host['id'], "ha:$k", json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
    }
    // Wat er voor realtime nodig is (inotify-tools, EPEL op Rocky/Alma/RHEL)
    if (is_array($in['tools'] ?? null)) {
        $tl = $in['tools'];
        $st->execute([$host['id'], 'tools', json_encode(['inotify' => !empty($tl['inotify']), 'epel' => isset($tl['epel']) ? (bool) $tl['epel'] : null,
            'family' => ($tl['family'] ?? '') === 'rhel' ? 'rhel' : 'other', 'no_epel' => !empty($tl['no_epel'])]), $now]);
    }
    // Realtime-watcher: draait hij, en volgt hij het systemlog en de bestanden?
    if (is_array($in['realtime'] ?? null)) {
        $rt = $in['realtime'];
        $st->execute([$host['id'], 'realtime', json_encode(['state' => mb_substr((string) ($rt['state'] ?? ''), 0, 20), 'since' => (int) ($rt['since'] ?? 0),
            'journal' => mb_substr((string) ($rt['journal'] ?? ''), 0, 20), 'files' => mb_substr((string) ($rt['files'] ?? ''), 0, 20)]), $now]);
    }
    // Home Assistant: USB-apparaten (Zigbee-/Z-Wave-sticks, Bluetooth), elk een eigen regel om te kiezen en te bewaken
    foreach (array_slice((array) ($in['usb'] ?? []), 0, 50) as $u) {
        $uid = (string) ($u['id'] ?? '');
        if (is_array($u) && preg_match('/^[A-Za-z0-9._:+@-]{1,150}$/', $uid)) {
            $st->execute([$host['id'], 'usb:' . $uid, json_encode(['name' => mb_substr((string) ($u['name'] ?? ''), 0, 100),
                'vendor' => mb_substr((string) ($u['vendor'] ?? ''), 0, 100), 'path' => mb_substr((string) ($u['path'] ?? ''), 0, 250)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
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
    // 1.137.0 (agent 1.69.0): hoe lang de run duurde (per onderdeel) en wat de agent hier kan, voor de Agent Manager
    if (isset($in['timing']['total']) && is_numeric($in['timing']['total'])) {
        $parts = [];
        foreach (array_slice((array) ($in['timing']['parts'] ?? []), 0, 60, true) as $k => $ms) {
            if (preg_match('/^[a-z_]{1,30}$/', (string) $k) && is_numeric($ms)) {
                $parts[$k] = (int) $ms;
            }
        }
        arsort($parts);
        $st->execute([$host['id'], 'timing', json_encode(['total' => (int) $in['timing']['total'], 'parts' => $parts]), $now]);
    }
    if (in_array($in['update_sig'] ?? '', ['ok', 'old_openssl', 'off', 'bad'], true)) {
        $st->execute([$host['id'], 'update_sig', (string) $in['update_sig'], $now]);   // 1.139.0: handtekening bij de laatste update
    }
    if (isset($in['caps']) && is_array($in['caps'])) {
        $st->execute([$host['id'], 'caps', json_encode(array_values(array_filter(array_slice($in['caps'], 0, 40), static fn($c) => is_string($c) && preg_match('/^[a-z0-9-]{1,20}$/', $c)))), $now]);
    }
    $backlogN = is_array($in['backlog'] ?? null) ? AgentQueue::backlog($host, $in['backlog']) : 0;
    // 1.138.0 (agent 1.70.0): klok, schijfgezondheid en UPS; per schijf en per UPS ook een regel om uit te kiezen in het formulier
    if (is_array($in['time'] ?? null)) {
        $st->execute([$host['id'], 'time', json_encode(['src' => mb_substr((string) ($in['time']['src'] ?? ''), 0, 20), 'sync' => !empty($in['time']['sync']),
            'offset_ms' => is_numeric($in['time']['offset_ms'] ?? null) ? (float) $in['time']['offset_ms'] : null]), $now]);
    }
    foreach (['smart' => 'dev', 'ups' => 'name', 'wg' => 'id'] as $k => $key) {   // 1.170.0 (agent 1.75.0): WireGuard-peers
        if (!is_array($in[$k] ?? null)) {
            continue;
        }
        $list = array_values(array_filter(array_slice($in[$k], 0, 32), static fn($x) => is_array($x) && preg_match('#^[\w/@.:-]{1,100}$#', (string) ($x[$key] ?? ''))));
        $st->execute([$host['id'], $k, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now]);
        foreach ($list as $x) {
            $st->execute([$host['id'], mb_substr("$k:" . $x[$key], 0, 190), json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now]);
        }
    }
    if (isset($in['diag'])) {
        Ai::fromAgent($host, $in['diag']);   // 1.171.0: uitkomst van een diagnose voor AI-advies
    }
    // Deels onleesbaar rapport (1.99.2): wat er werd weggelaten en waarom, voor de Agent Manager
    if ($salvaged) {
        $st->execute([$host['id'], 'report_warn', json_encode($salvaged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now]);
    }
    // 1.107.0: zelf herstellen (uitkomsten, herstelcommando's op de server) en de waakhond
    Heal::fromAgent($host, $in, $st, $now);
    // Vorige stand van de verbindingscontrole (1.100.0), vóór de oude regels verdwijnen
    $prevSec = q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'sec_state'", [$host['id']])->fetchColumn();
    Paths::cleanAgentData((int) $host['id'], $now);   // 1.189.2: oude regels weg, de pad-metingen blijven
    $agentVersion = preg_match('/^\d+\.\d+\.\d+$/', (string) ($in['agent_version'] ?? '')) ? $in['agent_version'] : null;
    // ℹ️ als de agent (opnieuw) verbindt: de eerste keer, na stil te zijn geweest, of vanaf een ander IP
    Security::agentConnected($host, $encrypted);
    // 1.146.0: was de agent langer stil dan de grens? Dan het gat bewaren (SLO "agents stil")
    Quality::recordAgentGap($host, time());
    // Contactmomenten (1.99.0): de laatste 21, om het echte ritme te zien
    $beats = array_slice([...array_filter(explode(',', (string) ($host['agent_beats'] ?? ''))), (string) time()], -21);
    q('UPDATE hosts SET agent_seen = ?, agent_busy = NULL, agent_ip = ?, agent_version = ?, enc_seen = ?, agent_beats = ? WHERE id = ?',
        [$now, mb_substr(client_ip(), 0, 45), $agentVersion, $encrypted ? 1 : 0, implode(',', $beats), $host['id']]);
    // Andere versie dan vorige keer: bijgewerkt (met de knop, automatisch, via de add-on winkel of met de hand) → info
    if ($agentVersion !== null && $host['agent_version'] !== null && $agentVersion !== $host['agent_version']) {
        $from = (string) $host['agent_version'];
        Monitor::infoHost($host, fn() => t('Agent bijgewerkt van {from} naar {to}', ['from' => $from, 'to' => $agentVersion]));
    }
    $resp = ['ok' => true, 'host' => $host['name'], 'rzmon_version' => RZMON_VERSION, 'gz' => AgentCrypto::canUnzip() ? 1 : 0, 'received' => [
        'disks' => count($in['disks'] ?? []), 'services' => count($in['services'] ?? []), 'ports' => count($in['ports'] ?? []),
        'certs' => count((array) ($in['certs'] ?? [])), 'containers' => count((array) ($in['podman']['containers'] ?? [])),
    ] + ($backlogN ? ['backlog' => $backlogN] : [])];
    $resp['tests_rev'] = agent_tests_rev((int) $host['id'], $encrypted);
    // 1.107.0: herstelcommando's (alleen versleuteld) en, voor de waakhond, hoe lang de checks al niet gedraaid hebben
    if ($encrypted && ($run = Heal::runList((int) $host['id']))) {
        $resp['heal_run'] = $run;
    }
    // 1.110.0: processen die RzSense meteen laat stoppen (alleen versleuteld; anders via de testlijst, bij de volgende run)
    if ($encrypted && $edrResp) {
        $resp += $edrResp;
    }
    if ((int) ($host['watchdog'] ?? 0) === 1) {
        $resp['cron_age'] = Heal::cronAge();
    }
    // 1.205.0: RzSense wordt zo bijgewerkt: de agent houdt zijn metingen vast tot de vrijgave (alleen versleuteld)
    try {
        $resp += UpdateHold::forAgent($host, $encrypted, $in);
    } catch (Throwable $e) {
        error_log('RzSense: update-aankondiging: ' . $e->getMessage());
    }
    $resp += agent_enc_fields($host, $agentVersion);
    if ($u = agent_url_report($host, $in, $agentVersion, $encrypted)) {
        $resp['url_set'] = $u;
    }
    // Verbindingscontrole (1.100.0): instellingen op de server, SERVICES omzetten, ⚠️/✅ bij een wisseling; de uitkomst
    // gaat mee terug voor rzmon-agent-setup --status
    try {
        $resp['sec'] = AgentAudit::fromAgent($host, $in, $st, $now, $prevSec === false ? null : (string) $prevSec);
    } catch (Throwable $e) {
        error_log('RzSense: verbindingscontrole mislukt: ' . $e->getMessage());
    }
    // Agent 1.53.0: per adres hoe hij het moet controleren (vastzetten of gewoon); alleen versleuteld
    if ($encrypted && $agentVersion && version_compare($agentVersion, AgentCrypto::PINURL_MIN, '>=')) {
        $pins = [];
        foreach ([(string) ($in['url'] ?? ''), (string) ($in['url_fallback'] ?? '')] as $u2) {
            $u2 = rtrim($u2, '/');
            if ($u2 !== '' && AgentCrypto::validUrl($u2) && ($s = AgentCrypto::urlSpec($u2, false)) !== null) {
                $pins[$u2] = $s;
            }
        }
        if ($pins) {
            $resp['url_pins'] = $pins;
        }
    }
    // Heartbeats van rzmon-hb op de server (agent 1.52.0); namen zonder check gaan terug naar het log van de agent
    if (!empty($in['hb']) && is_array($in['hb'])) {
        $unknown = Heartbeat::fromAgent($host, $in['hb']);
        if ($unknown) {
            $resp['hb_unknown'] = $unknown;
        }
    }
    Perf::part('rapport:overig');
    // Soort apparaat (1.75.0): de HA-add-on is smart home, een gewone agent een server
    HostKind::setAuto((int) $host['id'], !empty($in['ha']) || ($in['update']['via'] ?? '') === 'ha' ? 'smarthome' : 'server');
    // Agent-checks van deze server meteen beoordelen (niet wachten op de volgende cron-minuut)
    try {
        Monitor::runAgentHost((int) $host['id']);
        Paths::runFresh($now);   // 1.191.1: pad-check met een nieuwe meting meteen beoordelen
    } catch (Throwable $e) {
        error_log('RzSense: agent-checks direct beoordelen mislukt: ' . $e->getMessage());
    }
    Perf::part('rapport:checks');
    if ($u = agent_update_instruction($host['id'], $agentVersion, $in)) {
        $resp['update'] = $u;
    }
    // Kwetsbaarheden: softwarelijst opslaan, uitkomst van "bijwerken", en eventueel een nieuwe opdracht
    try {
        $resp += Vulns::agentReport($host, $in);
    } catch (Throwable $e) {
        error_log('RzSense: softwarelijst verwerken mislukt: ' . $e->getMessage());
    }
    Perf::part('rapport:software');
    out($resp);
}
