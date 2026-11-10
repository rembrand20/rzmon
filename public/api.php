<?php
declare(strict_types=1);

/** 1.224.1: acties die met GET mogen (alleen lezen of downloaden). Al het andere moet een POST met CSRF-token zijn. */
const API_GET = [
    'access_info', 'actlog', 'actlog_csv', 'advice_list', 'eol', 'ransom_info', 'ransom_overview', 'apps', 'apps_info', 'ioc_get', 'ioc_hunts', 'patch', 'agent_cert', 'agent_cert_renew_status', 'agent_certs', 'agent_commands', 'agent_procs', 'agent_selftest_get',
    'agent_services', 'agent_status', 'agent_url_options', 'ai_get', 'ai_info', 'alerts', 'analytics', 'api_keys', 'availability', 'backup_download',
    'backup_info', 'blocks_overview', 'changelog', 'check_info', 'checks_all', 'cleanup', 'connections', 'contacts', 'containers', 'db_info',
    'db_rotate_status', 'discover', 'edr_feed', 'edr_host', 'edr_rules', 'exc_export', 'exc_list', 'exc_proposals', 'export', 'features',
    'harden_detail', 'hibp_info', 'hibp_overview', 'history', 'home', 'host_exposure', 'https_info', 'ids', 'ids_access', 'ids_anom',
    'ids_attack_flow', 'ids_flows', 'ids_incident_info', 'ids_ip_events', 'ids_mitre', 'ids_protect', 'incident_info', 'incidents', 'inet_state',
    'insight_analyses', 'insights', 'ip_info', 'ip_timeline', 'ipintel_status', 'log_context', 'log_export', 'log_facets', 'log_found',
    'log_overview', 'log_search', 'log_source_lines', 'log_sources', 'log_watches', 'mds', 'my_access', 'my_logins', 'net_devices', 'net_discover',
    'net_domains', 'net_dpi', 'net_traffic_device', 'path_info', 'paths', 'podman', 'pve_overview', 'pw_reset_info', 'quiet_get', 'report',
    'report_preview', 'reports_get', 'rev', 'security', 'self_status', 'sessions', 'sessions_all', 'settings', 'settings_health', 'setup_info',
    'smart_proposals', 'snmp_discover', 'snmp_global', 'ssh_sessions', 'state', 'stats', 'status_get', 'stealth_info', 'templates', 'ti_status',
    'trends', 'truenas_overview', 'update_info', 'update_status', 'users', 'vuln_detail', 'vuln_packages', 'vuln_sbom', 'vuln_upgrade_status',
    'vulns', 'web_sites', 'weblog_status',
];

require __DIR__ . '/../src/bootstrap.php';
/** 1.226.0: acties die één server veranderen en in het logboek van die server komen (met wat er veranderde) */
const HOST_LOGGED = ['host_tick', 'host_realtime', 'host_enc', 'host_conn', 'host_info_agent', 'host_token', 'host_logs_interval', 'host_truenas_fw', 'edr_host_set', 'agent_commands_set'];
const HOST_LOGGED_LABEL = ['host_tick' => '⏱ Rapporteren', 'host_realtime' => '⚡ Realtime', 'host_enc' => '🔐 Versleuteling', 'host_conn' => '🔗 Verbinding', 'host_info_agent' => 'ℹ️ Info bij agent-updates',
    'host_token' => '↻ Token', 'host_logs_interval' => '📜 Logs', 'host_truenas_fw' => '🧱 TrueNAS-firewall', 'edr_host_set' => '🛡️ Endpoint-bewaking', 'agent_commands_set' => '🖥️ Commando-checks'];
require __DIR__ . '/../src/auth.php';
define('RZ_API', true);
require __DIR__ . '/../src/api/functies.php';   // 1.225.0: hulpfuncties (out, input …)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$GLOBALS['agent_req'] = $action === 'ping' || str_starts_with((string) $action, 'agent');
if (is_configured()) {
    Perf::request($GLOBALS['agent_req'] ? 'g' : 'a', (string) $action);   // snelheidsmeter (1.119.0)
    // 1.221.1: na een update eerst de database bijwerken, ook als een agent als eerste binnenkomt (anders "Unknown column")
    Installer::ensureSchema();
}

// Taal: vóór het inloggen die van dit apparaat of de browser; een agent (geen gebruiker) krijgt de taal van
// meldingen, want wat hij oplevert (checkberichten, IDS) gaat naar iedereen. Na het inloggen: die van de gebruiker.
I18n::init(null);
if (str_starts_with($action, 'agent_') && is_configured()) {
    try {
        I18n::set(I18n::notifyLang());
    } catch (Throwable) {
        // database nog niet klaar: dan de standaard
    }
}

require __DIR__ . '/../src/api/agent.php';   // 1.225.0: agents en installatiescript (zonder sessie)
require __DIR__ . '/../src/api/login.php';   // inloggen en wachtwoord vergeten

$user = current_user();
// Het CSRF-token van deze sessie gaat met elk antwoord mee (1.84.2). Is de sessie intussen vernieuwd (bv. na een
// tijd stil opnieuw ingelogd via "onthoud mij", met een nieuw token), dan neemt de pagina het nieuwe over.
header('X-CSRF-Token: ' . csrf_token());
security_headers(false);   // 1.143.0: nosniff e.d. (geen HTML, dus geen CSP)
if (!$user) {
    out(['error' => t('Niet ingelogd')], 401);
}
if ($method !== 'GET' && !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    out(['error' => t('Ongeldig CSRF token'), 'csrf' => true], 403);
}
// 1.224.1: GET alleen om te lezen. Anders kon een andere website iets laten veranderen met een gewone link of een plaatje
// (bij "onthoud mij" of automatisch inloggen op het LAN gaat de sessie gewoon mee). En nooit vanaf een andere website.
if ($method === 'GET' && (!in_array($action, API_GET, true) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site')) {
    out(['error' => t('Deze actie kan zo niet: gebruik de knop in RzSense')], 405);
}

// Wachtwoord staat in een datalek: eerst een nieuw kiezen (alleen dat, uitloggen en de taal mogen)
if (must_change_password($user) && !in_array($action, ['pw_forced', 'logout', 'lang_set'], true)) {
    out(['error' => t('Je wachtwoord staat in een datalek: kies eerst een nieuw wachtwoord'), 'must_change' => true], 403);
}

// Rechten per actie. Alles wat hier niet staat, is alleen voor beheerders.
$need = match ($action) {
    'home', 'mds', 'self_status', 'state', 'availability', 'containers', 'path_info', 'paths', 'ai_get', 'rev', 'contacts', 'alerts', 'ids', 'ids_incident_info', 'ids_flows', 'ids_attack_flow', 'web_sites', 'ids_ip_events', 'ssh_sessions', 'edr_feed', 'ids_anom', 'ids_mitre', 'ids_protect', 'ip_info', 'ids_access', 'harden_detail', 'ip_timeline', 'check_info', 'history', 'stats', 'podman', 'logout', 'insights', 'insight_analyses', 'trends', 'password', 'users', 'totp_setup', 'totp_confirm', 'totp_reset',
    'devices', 'device_forget', 'device_forget_all', 'sessions', 'my_access', 'device_trust', 'device_revoke', 'device_rename',
    'passkey_reg_options', 'passkey_reg_finish', 'passkey_delete', 'passkey_rename', 'session_logout', 'sessions_logout_user', 'user_email', 'lang_set', 'my_logins', 'pw_forced', 'prefs_save' => 'readonly',
    'net_devices', 'pve_overview', 'truenas_overview', 'analytics', 'smart_proposals', 'ack', 'ack_host', 'unack', 'alert_seen', 'log_sources', 'log_source_lines', 'log_search', 'log_context', 'log_export', 'log_facets', 'log_overview', 'log_watches', 'log_watch_save', 'log_watch_toggle', 'log_watch_delete', 'log_watch_test', 'check_run', 'run_all', 'maintenance_set', 'port_scan', 'vulns', 'vuln_packages', 'vuln_detail', 'vuln_ignore', 'vuln_unignore', 'vuln_upgrade_status', 'vuln_sbom', 'vuln_buzz_now', 'incidents', 'incident_info', 'incident_set', 'report', 'host_exposure', 'insight_set', 'ai_ask', 'ai_run', 'ai_feedback', 'path_tp_now', 'reports_get', 'report_me', 'report_preview', 'settings_health', 'changelog', 'actlog', 'actlog_csv', 'eol', 'ransom_info', 'ransom_overview', 'apps', 'apps_info', 'ioc_get', 'ioc_hunts', 'patch', 'advice_list', 'features', 'net_discover', 'net_traffic_device', 'net_dpi', 'quiet_now' => 'user',
    default => 'admin',
};
if (!has_role($user, $need)) {
    out(['error' => t('Daar heb je geen rechten voor (je bent {role})', ['role' => mb_strtolower(t(ROLE_LABELS[$user['role']] ?? '?'))])], 403);
}
$isAdmin = has_role($user, 'admin');
I18n::init($user);
// 1.119.0: alleen lezen? Dan de sessie meteen vrijgeven. PHP laat andere verzoeken van dezelfde browser anders wachten
// tot dit verzoek klaar is; één trage pagina maakte zo alles traag.
if ($method === 'GET' && in_array($action, ['insights', 'insight_analyses', 'trends', 'home', 'self_status', 'state', 'availability', 'containers', 'path_info', 'paths', 'ai_get', 'rev', 'contacts', 'alerts', 'ids', 'ids_incident_info', 'ids_flows', 'ids_attack_flow', 'web_sites', 'ids_ip_events', 'ssh_sessions', 'edr_feed', 'ids_anom', 'ids_mitre', 'ids_protect', 'ip_info',
    'ids_access', 'harden_detail', 'ip_timeline', 'check_info', 'history', 'stats', 'analytics', 'vulns', 'vuln_packages', 'vuln_detail', 'net_devices', 'pve_overview', 'truenas_overview', 'incidents', 'incident_info', 'report',
    'smart_proposals', 'setup_info', 'settings', 'edr_rules', 'ti_status'], true)) {
    session_write_close();
}
// Wijzigingslogboek (1.97.0): instellingen vóór en na elke wijziging van een beheerder vergelijken
if ($isAdmin && $method === 'POST' && !in_array($action, ['prefs_save', 'check_try', 'changelog_undo', 'state'], true)) {
    $settingsBefore = ChangeLog::settingsSnapshot();
    register_shutdown_function(static function () use ($settingsBefore, $action): void {
        try {
            ChangeLog::settingsDiff($settingsBefore, ChangeLog::settingsSnapshot(), $action);
        } catch (Throwable) {
        }
    });
}

// 1.226.0: logboek per server: een wijziging aan één server via de Agent Manager (rapporteren, realtime, EDR, verbinding,
// commando's, logs …) als wijziging van die server, met terugzetten. host_save, host_snmp en host_watchdog doen dat zelf al.
if ($isAdmin && $method === 'POST' && in_array($action, HOST_LOGGED, true)) {
    $hostIn = input();
    $hostLogId = (int) ($hostIn['host_id'] ?? $hostIn['id'] ?? 0);
    $hostBefore = $hostLogId > 0 ? ChangeLog::hostRow($hostLogId) : null;
    if ($hostBefore) {
        register_shutdown_function(static function () use ($hostBefore, $hostLogId, $action): void {
            try {
                $after = ChangeLog::hostRow($hostLogId);
                if (!$after || http_response_code() >= 400) {
                    return;
                }
                $b = $a = [];
                foreach ($after as $k => $v) {
                    if (($hostBefore[$k] ?? null) != $v && !preg_match('/^(agent_seen|agent_ip|last_|status|enc_seen|agent_conn|agent_nonce|agent_url_at|agent_url_status|isolate_state|vuln_upgrade_status)/', (string) $k)
                        && !preg_match('/(token|_key|pass|secret|community|snmp)/i', (string) $k)) {
                        $b[$k] = $hostBefore[$k] ?? null;
                        $a[$k] = $v;
                    }
                }
                if ($a) {
                    ChangeLog::record('host', $hostLogId, 'update', $hostBefore['name'] . ' (' . t(HOST_LOGGED_LABEL[$action]) . ')', $b + ['name' => $hostBefore['name']], $a + ['name' => $after['name']]);
                }
            } catch (Throwable) {
            }
        });
    }
}

/** Niet-beheerders mogen alleen hun eigen account (2FA) beheren. */
$ownOnly = static function (int $id) use ($user, $isAdmin): void {
    if (!$isAdmin && $id !== $user['id']) {
        out(['error' => t('Je kunt alleen je eigen account beheren')], 403);
    }
};

/**
 * Bevestiging voor een gevoelige actie: je eigen wachtwoord, of een woord typen
 * als je automatisch beheerder bent via het LAN (dan heb je geen wachtwoord ingevuld).
 */
$confirm = static function (string $word) use ($user): void {
    $c = (string) (input()['confirm'] ?? '');
    // 1.206.0: per rol een vinkje in plaats van het wachtwoord (zolang je het de laatste N minuten invulde)
    if ($c === Confirm::SURE) {
        if (Confirm::canSkip($user, $word)) {
            return;
        }
        header('X-RzMon-Confirm-Left: ' . max(0, Confirm::until() - time()));   // een account-zaak telt niet: de rest blijft met het vinkje
        out(['error' => Confirm::refusal($user, $word), 'need_password' => true], 422);
    }
    if ($c === '' && Confirm::canSkip($user, $word)) {
        out(['error' => t('Vink "Ik weet het zeker" aan om te bevestigen')], 422);
    }
    if (is_lan_session()) {
        if ($c !== $word && $c !== t($word)) {   // het Nederlandse woord, of het woord in de taal van de gebruiker
            out(['error' => t('Typ {word} om te bevestigen', ['word' => t($word)])], 422);
        }
        return;
    }
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
    if (!$hash || !password_verify($c, $hash)) {
        usleep(500000);
        out(['error' => t('Je wachtwoord klopt niet')], 422);
    }
    Confirm::passwordEntered();
    header('X-RzMon-Confirm-Left: ' . max(0, Confirm::until() - time()));
};

/**
 * Een check uit het formulier controleren (1.94.0): de kolommen voor de tabel checks, en of er een nieuwe heartbeat-URL moet komen.
 * Gebruikt door check_save, sjablonen (template_item_save) en kopiëren. Bij een fout: out() met de foutmelding.
 */
$checkCols = static function (array $in, bool $dry = false) use ($user): array {   // $dry: alleen proberen (geen logboek)
            $type = $in['type'] ?? '';
            if (!in_array($type, ['ping', 'tcp', 'http', 'ssl', 'disk', 'service', 'cpu', 'ram', 'podman', 'container', 'log', 'ha', 'command', 'snmp', 'reboot', 'heartbeat', 'process', 'pve',
                'dns', 'ntp', 'smart', 'ups', 'mysql', 'internet', 'domain', 'mqtt', 'vpn', 'truenas', 'path', 'traffic'], true)) {
                out(['error' => t('Ongeldig type')], 422);
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
                if ($prev !== $command && !$dry) {
                    AgentCommands::audit($user['username'], empty($in['id']) ? 'commando-check aangemaakt' : 'commando-check gewijzigd',
                        'server ' . (int) ($in['host_id'] ?? 0) . ': ' . $cmdCfg['cmd'] . ' → ' . AgentCommands::MATCHES[$cmdCfg['match']] . ' "' . $cmdCfg['expect'] . '"');
                }
            }
            $metric = null;   // container: één check met status + gekozen resources (options.res)
            if ($type === 'ha') {
                $metric = (string) ($in['metric'] ?? '');
                if (!in_array($metric, ['backup', 'integrations', 'repairs', 'reboot', 'disklife', 'osupdate', 'usb'], true)) {
                    out(['error' => t('Kies wat je van Home Assistant wilt bewaken')], 422);
                }
                if ($metric === 'usb' && !preg_match('/^[A-Za-z0-9._:+@-]{1,150}$/', trim((string) ($in['target'] ?? '')))) {
                    out(['error' => t('Kies het USB-apparaat uit de lijst')], 422);
                }
            }
            $target = trim((string) ($in['target'] ?? ''));
            // 1.170.0: VPN via Gluetun (control server) of WireGuard (agent)
            if ($type === 'vpn') {
                $metric = (string) ($in['metric'] ?? '');
                if (!in_array($metric, NetChecks::VPN_METRICS, true)) {
                    out(['error' => t('Kies Gluetun of WireGuard')], 422);
                }
                if ($metric === 'gluetun' && $target !== '' && ExtraChecks::urlBlocked($target) !== null) {
                    out(['error' => t('Vul het adres van de control server in, bv. http://192.168.1.20:8000')], 422);
                }
                if ($metric === 'wg' && $target !== '' && !preg_match('/^[\w.-]{1,30}(:[A-Za-z0-9]{1,12})?$/', $target)) {
                    out(['error' => t('Kies een peer uit de lijst, of laat leeg voor alle peers')], 422);
                }
            }
            if ($type === 'domain') {
                $dn = NetChecks::domainName($target);
                if ($dn === null) {
                    out(['error' => t('Vul een domeinnaam in, bv. example.nl')], 422);
                }
                $target = $dn;
                $metric = ($in['metric'] ?? '') === 'mail' ? 'mail' : null;   // 1.227.0: verloopdatum of e-mailbeveiliging
            }
            // 1.200.0: database (MariaDB/MySQL of PostgreSQL): overzicht of één waarde
            if ($type === 'mysql') {
                $metric = (string) ($in['metric'] ?? '');
                $metric = $metric === 'overview' ? '' : $metric;
                if ($metric !== '' && !array_key_exists($metric, Db::UNITS)) {
                    out(['error' => t('Kies wat je van de database wilt bewaken')], 422);
                }
                $metric = $metric === '' ? null : $metric;
                if ($target !== '' && !preg_match('/^[A-Za-z0-9._-]+(:\d{1,5})?$/', $target)) {
                    out(['error' => t('Ongeldig adres')], 422);
                }
            }
            if ($type === 'mqtt' && $target !== '' && !preg_match('/^(\[[0-9a-fA-F:]+\]|[A-Za-z0-9._-]+)(:\d{1,5})?$/', $target)) {
                out(['error' => t('Ongeldig adres')], 422);
            }
            // Internet (1.157.0): ping, responstijd, download of upload; altijd vanaf de server van RzSense
            if ($type === 'internet') {
                $metric = (string) ($in['metric'] ?? '');
                if (!in_array($metric, Internet::METRICS, true)) {
                    out(['error' => t('Kies wat je van internet wilt bewaken')], 422);
                }
                $target = '';
            }
            // 1.187.0: verkeer van een apparaat (UniFi): snelheid of hoeveelheid vandaag; doel = MAC- of IP-adres (leeg = adres van de server)
            if ($type === 'traffic') {
                $metric = (string) ($in['metric'] ?? 'rate');
                if (!in_array($metric, ['rate', 'up_rate', 'day', 'up_day'], true)) {
                    out(['error' => t('Kies wat je van het verkeer wilt bewaken')], 422);
                }
                if ($target !== '' && NetScan::normMac($target) === null && !filter_var($target, FILTER_VALIDATE_IP)) {
                    out(['error' => t('Vul een MAC- of IP-adres in, of laat het leeg')], 422);
                }
            }
            // 1.174.0: TrueNAS (agent 1.77.0): meldingen, taken, apps, update of schijftemperaturen
            if ($type === 'truenas') {
                $metric = (string) ($in['metric'] ?? 'alerts');
                if (!in_array($metric, Truenas::METRICS, true)) {
                    out(['error' => t('Kies wat je van TrueNAS wilt bewaken')], 422);
                }
                if (mb_strlen($target) > 100 || preg_match('/[\x00-\x1f]/', $target)) {
                    out(['error' => t('Ongeldige naam')], 422);
                }
                if (in_array($metric, ['alerts', 'update'], true)) {
                    $target = '';
                }
            }
            // Proxmox VE (1.105.0): wat, en voor een guest de VMID
            if ($type === 'pve') {
                $metric = (string) ($in['metric'] ?? 'overview');
                if (!in_array($metric, Pve::METRICS, true)) {
                    out(['error' => t('Kies wat je van Proxmox wilt bewaken')], 422);
                }
                if ($metric === 'guest' && !preg_match('/^\d{1,9}$/', $target)) {
                    out(['error' => t('Vul de VMID van de guest in (bv. 100)')], 422);
                }
                if ($metric === 'backups' && $target !== '' && !preg_match('/^\d{1,9}$/', $target)) {
                    out(['error' => t('Laat leeg voor alle guests, of vul één VMID in')], 422);
                }
                if ($metric === 'storage' && $target !== '' && !preg_match('/^[A-Za-z0-9._-]{1,60}$/', $target)) {
                    out(['error' => t('Ongeldige naam van de opslag')], 422);
                }
                if (in_array($metric, ['overview', 'zfs'], true)) {
                    $target = '';
                }
            }
            // SNMP: wat (metric) en, waar nodig, de index uit de discovery of de eigen OID
            $snmpLow = false;
            if ($type === 'snmp') {
                $metric = (string) ($in['metric'] ?? '');
                $sm = SnmpMonitor::METRICS[$metric] ?? null;
                if (!$sm) {
                    out(['error' => t('Kies wat je via SNMP wilt bewaken')], 422);
                }
                if ($metric === 'oid') {
                    $target = ltrim($target, '.');
                    if (!preg_match('/^1(\.\d{1,10}){3,60}$/', $target)) {
                        out(['error' => t('Geef een numerieke OID, bv. 1.3.6.1.4.1.6574.1.2.0 (namen als sysName.0 werken niet)')], 422);
                    }
                } elseif ($sm['index'] === 'name') {
                    // UniFi: band of SSID als doel (leeg = totaal, alleen bij clients)
                    if (($target === '' && $metric !== 'unifi_clients') || mb_strlen($target) > 100 || preg_match('/[\x00-\x1f]/', $target)) {
                        out(['error' => t('Kies de band of SSID via ＋ SNMP-checks')], 422);
                    }
                } elseif ($sm['index'] && !preg_match('/^\d{1,10}(\.\d{1,10}){0,10}$/', $target)) {
                    out(['error' => t('Kies de interface, schijf of voorraad via ＋ SNMP-checks')], 422);
                } elseif (!$sm['index']) {
                    $target = '';
                }
                $snmpLow = $sm['low'];
            }
            if ($type === 'tcp' && ((int) $target < 1 || (int) $target > 65535)) {
                out(['error' => t('Geef een geldige poort op (1-65535)')], 422);
            }
            if ($type === 'service' && !preg_match('/^[A-Za-z0-9@._:-]+$/', $target)) {
                out(['error' => t('Geef een geldige servicenaam op')], 422);
            }
            if ($type === 'container' && !preg_match('#^([a-z_][a-z0-9_.-]*/)?[A-Za-z0-9][A-Za-z0-9_.-]*$#', $target)) {
                out(['error' => t('Geef de naam van een container op (rootless: gebruiker/naam)')], 422);
            }
            $warn = num($in['warn_threshold'] ?? null);
            $crit = num($in['crit_threshold'] ?? null);
            $options = null;
            if ($type === 'snmp' && $metric === 'oid') {
                // Eigen OID: getal, teller (per seconde) of tekst vergelijken
                $mode = in_array($in['oid_mode'] ?? '', ['gauge', 'counter', 'text'], true) ? $in['oid_mode'] : 'gauge';
                $o = ['mode' => $mode];
                if ($mode === 'text') {
                    $o['match'] = in_array($in['oid_match'] ?? '', ['equals', 'contains', 'not_contains', 'regex'], true) ? $in['oid_match'] : 'equals';
                    $o['expect'] = mb_substr(trim((string) ($in['oid_expect'] ?? '')), 0, 150);
                    $o['fail'] = ($in['oid_fail'] ?? '') === 'warn' ? 'warn' : 'crit';
                    if ($o['match'] === 'regex' && @preg_match('~' . str_replace('~', '\\~', $o['expect']) . '~i', '') === false) {
                        out(['error' => t('De regex klopt niet')], 422);
                    }
                    $warn = $crit = null;
                } else {
                    $scale = num($in['oid_scale'] ?? null);
                    if ($scale !== null && $scale != 1) {
                        $o['scale'] = $scale;
                    }
                    $unit = mb_substr(trim((string) ($in['oid_unit'] ?? '')), 0, 12);
                    if ($unit !== '') {
                        $o['unit'] = $unit;
                    }
                    if (!empty($in['oid_low'])) {
                        $o['low'] = 1;
                        $snmpLow = true;
                    }
                }
                $options = json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($type === 'snmp' && $metric === 'ups_status') {
                $warn = $crit = null;   // UPS-status: geen drempels
            }
            if ($type === 'ssl' || $type === 'ups' || $type === 'domain' || $snmpLow) {   // 1.138.0: ook de lading van een UPS; 1.170.0: dagen tot een domein verloopt
                // Hier geldt "minder = slechter" (dagen, batterij, toner…)
                if ($warn !== null && $crit !== null && $warn < $crit) {
                    out(['error' => $type === 'ssl' ? t('Bij een certificaat moet de warning-drempel (dagen) hoger zijn dan de critical-drempel')
                        : t('Hier is lager slechter: de warning-drempel moet hoger zijn dan de critical-drempel')], 422);
                }
            } elseif ($warn !== null && $crit !== null && $warn > $crit) {
                out(['error' => t('Warning-drempel moet lager zijn dan critical-drempel')], 422);
            }
            if ($type === 'ssl' && $target !== '' && !preg_match('#^(https?://)?(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.\-]+)(:\d{1,5})?/?$#', $target)
                && !preg_match('#^/[^\s\'"]+$#', $target)) {
                out(['error' => t('Geef een host op, eventueel met poort (example.com of example.com:8443), of het pad van een certificaatbestand dat de agent meldt')], 422);
            }
            if (in_array($type, ['ping', 'cpu', 'ram', 'podman', 'ha', 'command', 'reboot'], true)) {
                $target = '';
            }
            if ($type === 'command' || $type === 'reboot') {
                $warn = $crit = null;   // geen drempels: een commando vergelijkt tekst, een herstart geeft alleen info
            }
            if ($type === 'ha' && $metric === 'repairs') {
                $warn = $crit = null;   // systeemstatus: unhealthy = critical, de rest warning
            }
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
                        out(['error' => t('Vul bij {what} minstens één drempel in', ['what' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'][$k]])], 422);
                    }
                    if ($w !== null && $c !== null && $w > $c) {
                        out(['error' => t('Bij {what} moet de warning-drempel lager zijn dan de critical-drempel', ['what' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'disk'][$k]])], 422);
                    }
                    $res[$k] = [$w, $c];
                }
                $options = json_encode(['res' => (object) $res]);
                // Eén check per container: bestaat er al een, dan die aanpassen
                $dup = q("SELECT id FROM checks WHERE host_id = ? AND type = 'container' AND target = ? AND id <> ?",
                    [(int) ($in['host_id'] ?? q('SELECT host_id FROM checks WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn()), $target, (int) ($in['id'] ?? 0)])->fetchColumn();
                if ($dup) {
                    out(['error' => t('Container {name} wordt al bewaakt. Pas die check aan (✎).', ['name' => $target]), 'existing_id' => (int) $dup], 409);
                }
            }
            if ($type === 'log') {
                // Bron: een logbestand (absoluut pad), het systemlog (journal), het log van één service, of kernelmeldingen
                if (!preg_match('#^(/[A-Za-z0-9._/@+-]{1,250}|journal|journal:[A-Za-z0-9@._:-]{1,100}|kernel|ha:(host|core|supervisor|addon:[a-z0-9_]{1,100}))$#', $target)) {
                    out(['error' => t('Bron: geef een logbestand (bv. /var/log/secure), "journal", "journal:sshd" of "kernel"; bij Home Assistant "ha:host", "ha:core", "ha:supervisor" of "ha:addon:<slug>"')], 422);
                }
                $pattern = trim((string) ($in['pattern'] ?? ''));
                if ($pattern === '' || mb_strlen($pattern) > 300) {
                    out(['error' => t('Geef aan waarnaar gezocht moet worden (maximaal 300 tekens)')], 422);
                }
                if (@preg_match('~' . str_replace('~', '\\~', $pattern) . '~i', '') === false) {
                    out(['error' => t('Het zoekpatroon klopt niet (regex). Tip: gebruik | tussen alternatieven, bv. Failed password|Invalid user')], 422);
                }
                $options = json_encode(['pattern' => $pattern, 'preset' => mb_substr((string) ($in['preset'] ?? ''), 0, 30)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($type === 'service') {
                $testType = in_array($in['test_type'] ?? '', ['url', 'port'], true) ? $in['test_type'] : '';
                $testTarget = trim((string) ($in['test_target'] ?? ''));
                if ($testType === 'url' && !preg_match('#^https?://[^\s]+$#i', $testTarget)) {
                    out(['error' => t('Extra test: geef een URL die met http:// of https:// begint, bv. http://localhost:8080/health')], 422);
                }
                if ($testType === 'port' && !preg_match('#^(([A-Za-z0-9.\-]+|\[[0-9a-fA-F:]+\]):)?\d{1,5}$#', $testTarget)) {
                    out(['error' => t('Extra test: geef een poort (8080) of host:poort (localhost:8080)')], 422);
                }
                // Eigen poorten/sockets: "8080, 127.0.0.1:9000, [::1]:80, /run/php-fpm/www.sock" (max. 10)
                $ports = [];
                foreach (preg_split('/[\s,;]+/', trim((string) ($in['ports'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $pt) {
                    $isSocket = preg_match('#^/[A-Za-z0-9._/@+-]{1,200}$#', $pt);
                    $isPort = preg_match('#^(([A-Za-z0-9.\-]+|\[[0-9a-fA-F:]+\]):)?(\d{1,5})$#', $pt, $pm) && (int) $pm[3] >= 1 && (int) $pm[3] <= 65535;
                    if (!$isSocket && !$isPort) {
                        out(['error' => t('Poort of socket "{p}" klopt niet. Gebruik bv. 8080, 127.0.0.1:9000 of /run/php-fpm/www.sock', ['p' => $pt])], 422);
                    }
                    $ports[] = $pt;
                }
                if (count($ports) > 10) {
                    out(['error' => t('Geef maximaal 10 poorten of sockets op')], 422);
                }
                $options = json_encode(['deep' => ($in['deep'] ?? true) ? 1 : 0] + ($ports ? ['ports' => implode(' ', array_unique($ports))] : []) + ($testType !== '' ? [
                    'test_type' => $testType, 'test_target' => mb_substr($testTarget, 0, 300),
                    'test_expect' => mb_substr(trim((string) ($in['test_expect'] ?? '')), 0, 100),
                ] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            // Heartbeat (1.77.0): naam voor rzmon-hb, schema, speling, maximale duur; de geheime URL maakt RzSense zelf
            $hbNew = false;
            if ($type === 'heartbeat') {
                if (!preg_match(Heartbeat::NAME, $target)) {
                    out(['error' => t('Geef een naam voor de heartbeat: letters, cijfers, punt, - of _ (bv. backup-db)')], 422);
                }
                $hid = (int) ($in['host_id'] ?? q('SELECT host_id FROM checks WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn());
                if (q("SELECT id FROM checks WHERE host_id = ? AND type = 'heartbeat' AND target = ? AND id <> ?", [$hid, $target, (int) ($in['id'] ?? 0)])->fetchColumn()) {
                    out(['error' => t('Deze server heeft al een heartbeat met de naam {n}', ['n' => $target])], 409);
                }
                $hb = Heartbeat::clean($in);
                if (is_string($hb)) {
                    out(['error' => $hb], 422);
                }
                if (!isset($hb['unit'])) {
                    $warn = $crit = null;   // drempels alleen voor een meegestuurde waarde
                }
                $options = json_encode($hb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $hbNew = !empty($in['hb_new_token']);
            }
            // 1.177.0: pad-check: doel, tussenliggende apparaten, doorvoertest en wanneer melden
            if ($type === 'path') {
                if (!Paths::okHost($target)) {
                    out(['error' => t('Vul het doel in: een IP-adres of hostnaam, bv. 1.1.1.1')], 422);
                }
                $via = [];
                foreach (preg_split('/[\s,;]+/', is_array($in['via'] ?? null) ? implode(',', $in['via']) : (string) ($in['via'] ?? '')) ?: [] as $v) {
                    if ($v !== '' && !Paths::okHost($v)) {
                        out(['error' => t('Ongeldig tussenliggend apparaat: {v}', ['v' => mb_substr($v, 0, 40)])], 422);
                    }
                    if ($v !== '') {
                        $via[] = $v;
                    }
                }
                $options = json_encode(['via' => array_slice(array_values(array_unique($via)), 0, 6), 'tp' => !empty($in['tp']) ? 1 : 0, 'tp_host' => (int) ($in['tp_host'] ?? 0),
                    'tp_every' => in_array((int) ($in['tp_every'] ?? 3600), [0, 3600, 86400], true) ? (int) ($in['tp_every'] ?? 3600) : 3600,
                    'tp_min' => max(1, min(100000, (int) ($in['tp_min'] ?? 200))), 'loss_warn' => max(1, min(100, (int) ($in['loss_warn'] ?? 5))),
                    'loss_crit' => max(1, min(100, (int) ($in['loss_crit'] ?? 20))), 'link' => !empty($in['link']) ? 1 : 0, 'hop' => !empty($in['hop']) ? 1 : 0,
                    'change' => !empty($in['change']) ? 1 : 0]);
            }
            // Proces (1.98.0): op naam of op een patroon in de opdrachtregel, eventueel van één gebruiker; aantal en CPU/RAM
            if ($type === 'process') {
                $pmode = ($in['proc_mode'] ?? '') === 'pattern' ? 'pattern' : 'name';
                if ($pmode === 'name' && !preg_match('/^[A-Za-z0-9@._:+-]{1,100}$/', $target)) {
                    out(['error' => t('Geef de naam van het proces, bv. nginx of python3 (of kies "patroon" voor een deel van de opdrachtregel)')], 422);
                }
                if ($pmode === 'pattern' && ($target === '' || mb_strlen($target) > 200 || preg_match('/[\t\n\r]/', $target) || @preg_match('~' . str_replace('~', '\\~', $target) . '~', '') === false)) {
                    out(['error' => t('Het patroon klopt niet (regex), bv. python3 .*main\.py')], 422);
                }
                $puser = trim((string) ($in['proc_user'] ?? ''));
                if ($puser !== '' && !preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/i', $puser)) {
                    out(['error' => t('Ongeldige gebruikersnaam')], 422);
                }
                $pmin = trim((string) ($in['proc_min'] ?? '')) === '' ? null : max(1, min(10000, (int) $in['proc_min']));
                $pmax = trim((string) ($in['proc_max'] ?? '')) === '' ? null : max(1, min(10000, (int) $in['proc_max']));
                if ($pmin !== null && $pmax !== null && $pmin > $pmax) {
                    out(['error' => t('Het minimum mag niet hoger zijn dan het maximum')], 422);
                }
                $res = [];
                foreach (['cpu', 'mem'] as $k) {
                    $r = $in['res'][$k] ?? null;
                    if (is_array($r) && !empty($r['on'])) {
                        $w = num($r['warn'] ?? null);
                        $c = num($r['crit'] ?? null);
                        if ($w !== null && $c !== null && $w > $c) {
                            out(['error' => t('Bij {what} moet de warning-drempel lager zijn dan de critical-drempel', ['what' => $k === 'cpu' ? 'CPU' : 'RAM'])], 422);
                        }
                        $res[$k] = [$w, $c];
                    }
                }
                $options = json_encode(['mode' => $pmode] + ($puser !== '' ? ['user' => $puser] : []) + ($pmin !== null ? ['min' => $pmin] : []) + ($pmax !== null ? ['max' => $pmax] : [])
                    + ($res ? ['res' => $res] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $warn = $crit = null;
            }
            if ($type === 'pve' && $metric === 'backups') {
                $per = trim(mb_substr((string) ($in['pve_per'] ?? ''), 0, 500));
                $options = $per !== '' ? json_encode(['per' => $per]) : null;
            }
            // Certificaat (1.98.0): vanaf RzSense of vanaf de server zelf (agent); leeg = automatisch (bestand of localhost → agent)
            if ($type === 'ssl') {
                if (preg_match('/\s/', $target)) {
                    out(['error' => t('Een pad of adres zonder spaties')], 422);
                }
                $from = in_array($in['cert_from'] ?? '', ['agent', 'rzmon'], true) ? $in['cert_from'] : '';
                $options = $from !== '' ? json_encode(['from' => $from]) : null;
            }
            // 1.138.0: opties van de nieuwe checks; HTTP met methode, body en verwachte inhoud
            if ($type === 'http') {
                $method = in_array($in['http_method'] ?? 'GET', ['GET', 'POST', 'HEAD', 'PUT'], true) ? $in['http_method'] : 'GET';
                $ho = array_filter(['method' => $method !== 'GET' ? $method : null, 'body' => in_array($method, ['POST', 'PUT'], true) ? mb_substr((string) ($in['http_body'] ?? ''), 0, 4000) : null,
                    'ctype' => in_array($method, ['POST', 'PUT'], true) && preg_match('#^[\w.+-]+/[\w.+-]+$#', (string) ($in['http_ctype'] ?? '')) ? $in['http_ctype'] : null,
                    'expect' => mb_substr(trim((string) ($in['http_expect'] ?? '')), 0, 200), 'json' => mb_substr(trim((string) ($in['http_json'] ?? '')), 0, 100),
                    'json_value' => mb_substr(trim((string) ($in['http_json_value'] ?? '')), 0, 200)], static fn($v) => $v !== null && $v !== '');
                if (isset($ho['json']) && !preg_match('/^\$?\.?[\w-]+(\.[\w-]+)*$/', $ho['json'])) {
                    out(['error' => t('JSON-veld: namen met punten ertussen, bv. status of data.0.ok')], 422);
                }
                $options = $ho ? json_encode($ho, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            }
            if ($type === 'dns') {
                if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/', $target)) {
                    out(['error' => t('Vul een naam in, bv. example.com')], 422);
                }
                $srv = trim((string) ($in['dns_server'] ?? ''));
                if ($srv !== '' && !filter_var($srv, FILTER_VALIDATE_IP)) {
                    out(['error' => t('DNS-server moet een IP-adres zijn')], 422);
                }
                $options = json_encode(array_filter(['rtype' => isset(ExtraChecks::DNS_TYPES[$in['dns_type'] ?? '']) ? $in['dns_type'] : 'A', 'server' => $srv,
                    'expect' => mb_substr(trim((string) ($in['dns_expect'] ?? '')), 0, 200)], static fn($v) => $v !== ''), JSON_UNESCAPED_SLASHES);
            }
            // 1.170.0: MQTT (gebruiker, wachtwoord, TLS, rondgang, topic) en VPN (API-sleutel van Gluetun, land); geheimen blijven bij ••••
            if (in_array($type, ['mqtt', 'vpn'], true)) {
                $old = (int) ($in['id'] ?? 0) ? json_decode((string) q('SELECT options FROM checks WHERE id = ?', [(int) $in['id']])->fetchColumn(), true) ?: [] : [];
                $pass = (string) ($in[$type === 'vpn' ? 'vpn_key' : 'net_pass'] ?? '');
                $pass = $pass === ExtraChecks::MASK ? (string) ($old['pass'] ?? '') : mb_substr($pass, 0, 200);
                $no = $type === 'mqtt'
                    ? ['user' => mb_substr(trim((string) ($in['net_user'] ?? '')), 0, 80), 'pass' => $pass, 'tls' => !empty($in['mqtt_tls']) ? 1 : 0, 'rt' => !empty($in['mqtt_rt']) ? 1 : 0,
                        'topic' => mb_substr(trim((string) ($in['mqtt_topic'] ?? '')), 0, 200), 'expect' => mb_substr(trim((string) ($in['mqtt_expect'] ?? '')), 0, 100)]
                    : ($metric === 'gluetun' ? ['pass' => $pass, 'country' => mb_substr(trim((string) ($in['vpn_country'] ?? '')), 0, 60)] : []);
                if ($type === 'mqtt' && preg_match('/[\x00-\x1f]/', $no['topic'])) {
                    out(['error' => t('Ongeldig topic')], 422);
                }
                $options = $no ? json_encode($no, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            }
            if ($type === 'domain') {
                $old = (int) ($in['id'] ?? 0) ? json_decode((string) q('SELECT options FROM checks WHERE id = ?', [(int) $in['id']])->fetchColumn(), true) ?: [] : [];
                if ($metric === 'mail') {   // 1.227.0: DKIM-selectors, geen mail, STARTTLS testen
                    $sels = array_slice(array_values(array_unique(array_filter(preg_split('/[\s,;]+/', strtolower((string) ($in['mail_dkim'] ?? ''))) ?: [],
                        static fn($s) => (bool) preg_match('/^[a-z0-9._-]{1,63}$/', $s)))), 0, 10);
                    $mo = ['dkim' => $sels, 'nomail' => !empty($in['mail_nomail']) ? 1 : 0, 'starttls' => !empty($in['mail_starttls']) ? 1 : 0];
                    $options = json_encode($mo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);   // gegevens opnieuw opzoeken na een wijziging
                } else {
                    $options = isset($old['_rdap']) && ($old['_rdap']['name'] ?? '') === $target ? json_encode(['_rdap' => $old['_rdap']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                }
            }
            if ($type === 'mysql') {
                $old = (int) ($in['id'] ?? 0) ? json_decode((string) q('SELECT options FROM checks WHERE id = ?', [(int) $in['id']])->fetchColumn(), true) ?: [] : [];
                $pass = (string) ($in['db_pass'] ?? '');
                // 1.200.0: soort database, via RzSense of de agent, lokaal of in een container, en wanneer een query "lang" is
                $via = ($in['db_via'] ?? 'rzmon') === 'agent' ? 'agent' : 'rzmon';
                $where = ($in['db_where'] ?? 'local') === 'container' ? 'container' : 'local';
                $ct = trim((string) ($in['db_container'] ?? ''));
                if ($via === 'agent' && $where === 'container' && !preg_match('/^([A-Za-z0-9_.-]{1,32}\/)?[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $ct)) {
                    out(['error' => t('Kies de container met de database')], 422);
                }
                $dbUser = mb_substr(trim((string) ($in['db_user'] ?? '')), 0, 80);
                if ($via === 'rzmon' && $dbUser === '') {
                    out(['error' => t('Vul een gebruiker in (bv. een eigen gebruiker met alleen leesrechten)')], 422);
                }
                if ($dbUser !== '' && !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $dbUser)) {
                    out(['error' => t('Ongeldige gebruiker')], 422);
                }
                $options = json_encode(['engine' => ($in['db_engine'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql', 'via' => $via, 'where' => $where, 'container' => $where === 'container' ? $ct : '',
                    'user' => $dbUser, 'pass' => $pass === ExtraChecks::MASK ? (string) ($old['pass'] ?? '') : mb_substr($pass, 0, 200),
                    'db' => preg_replace('/[^\w$-]/', '', mb_substr((string) ($in['db_name'] ?? ''), 0, 64)), 'longq' => max(10, min(86400, (int) ($in['db_longq'] ?? 300)))],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($type === 'pve' && trim((string) ($in['name'] ?? '')) === '') {
                $g = $metric === 'guest' ? q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [(int) ($in['host_id'] ?? 0), "pveg:$target"])->fetchColumn() : false;
                $g = $g ? json_decode((string) $g, true) : null;
                $in['name'] = ['overview' => 'Proxmox', 'guest' => $g ? ($g['kind'] === 'ct' ? '📦 ' : '🖥️ ') . ($g['name'] ?: "VM $target") : "VM $target",
                    'backups' => $target !== '' ? t('Back-up {id}', ['id' => $target]) : t('Proxmox-back-ups'), 'storage' => t('Proxmox-opslag') . ($target !== '' ? " $target" : ''), 'zfs' => 'ZFS'][$metric];
            }
            $name = trim((string) ($in['name'] ?? '')) ?: ['ping' => 'Ping', 'tcp' => 'Poort', 'process' => t('Proces'), 'http' => 'HTTP', 'dns' => 'DNS', 'ntp' => t('Tijd'), 'smart' => t('Schijfgezondheid'), 'ups' => 'UPS', 'mysql' => (($in['db_engine'] ?? '') === 'pgsql' ? 'PostgreSQL' : 'MariaDB') . ($metric ? ' · ' . t(Db::LABELS[$metric] ?? $metric) : ''), 'domain' => $metric === 'mail' ? t('E-mailbeveiliging') : t('Domein'), 'mqtt' => 'MQTT', 'vpn' => $metric === 'wg' ? 'WireGuard' : 'Gluetun',
                'truenas' => ['alerts' => t('TrueNAS-meldingen'), 'tasks' => t('TrueNAS-taken'), 'apps' => t('TrueNAS-apps'), 'update' => t('TrueNAS-update'), 'temp' => t('Schijftemperatuur')][$metric] ?? 'TrueNAS', 'ssl' => 'Certificaat', 'disk' => 'Disk', 'service' => 'Service', 'podman' => 'Podman',
                'container' => '📦', 'cpu' => 'CPU', 'ram' => 'RAM', 'log' => 'Log', 'command' => 'Commando', 'reboot' => t('Herstart'), 'heartbeat' => 'Heartbeat', 'snmp' => t(SnmpMonitor::METRICS[$metric]['label'] ?? 'SNMP'),
                'ha' => ['backup' => 'HA back-up', 'integrations' => 'HA integraties', 'repairs' => 'HA systeemstatus', 'reboot' => 'HA OS herstart',
                    'disklife' => 'SD-kaart/eMMC', 'osupdate' => 'HA OS-update', 'usb' => 'USB'][$metric] ?? 'HA'][$type] . ($target !== '' ? " $target" : '');
            // Eigen meldregels voor deze check (null = de algemene uit Instellingen → Alarmgedrag)
            $notify = null;
            if (is_array($in['notify'] ?? null)) {
                $notify = json_encode(['rules' => NotifyRules::cleanRules((array) ($in['notify']['rules'] ?? [])),
                    'channels' => NotifyRules::cleanChannels((array) ($in['notify']['channels'] ?? []))]
                    + (is_array($in['notify']['flap'] ?? null) ? ['flap' => NotifyRules::cleanFlap($in['notify']['flap'])] : []));
            }
            // Stille uren: null = algemeen, of eigen vensters / nooit stil
            $quiet = is_array($in['quiet'] ?? null) && ($qo = NotifyRules::cleanQuietOwn($in['quiet'])) !== null ? json_encode($qo) : null;
            // Omgekeerde status (alleen bij werkt-het-wel-of-niet-checks): status als hij slaagt / mislukt
            $revOk = in_array($type, Checks::REVERSIBLE, true) && in_array((int) ($in['reverse_ok'] ?? 0), [STATUS_WARN, STATUS_CRIT], true) ? (int) $in['reverse_ok'] : null;
            $revFail = $revOk !== null ? max(STATUS_OK, min(STATUS_CRIT, (int) ($in['reverse_fail'] ?? STATUS_OK))) : null;
            // Melden na zoveel mislukte checks achter elkaar; leeg = de algemene instelling
            $fails = trim((string) ($in['notify_fails'] ?? '')) === '' ? null : max(1, min(100, (int) $in['notify_fails']));
            // 1.185.0: status pas na N controles; leeg = de algemene instelling
            $cf = trim((string) ($in['confirm_fails'] ?? '')) === '' ? null : max(1, min(100, (int) $in['confirm_fails']));
            $co = trim((string) ($in['confirm_ok'] ?? '')) === '' ? null : max(1, min(100, (int) $in['confirm_ok']));
    return [['type' => $type, 'target' => $target, 'name' => mb_substr($name, 0, 100), 'warn_threshold' => $warn, 'crit_threshold' => $crit,
        'interval_sec' => max(30, (int) ($in['interval_sec'] ?? 60)), 'enabled' => !empty($in['enabled']) ? 1 : 0, 'verify_ssl' => ($in['verify_ssl'] ?? true) ? 1 : 0,
        'metric' => $metric, 'options' => $options, 'notify' => $notify, 'quiet' => $quiet, 'command' => $command, 'reverse_ok' => $revOk, 'reverse_fail' => $revFail,
        'notify_fails' => $fails, 'info_only' => !empty($in['info_only']) ? 1 : 0, 'confirm_fails' => $cf, 'confirm_ok' => $co], $hbNew];
};

// 1.225.0: de acties per onderwerp, elk een eigen switch in src/api/. Elke actie stopt met out(); komt er niets
// overeen, dan het volgende bestand, en tot slot "onbekende actie".
foreach (['10-monitoring', '20-agents', '30-sjablonen', '40-toegang', '50-edr', '60-account', '70-ids', '80-systeem'] as $rzApiPart) {
    require __DIR__ . '/../src/api/' . $rzApiPart . '.php';
}
out(['error' => t('Onbekende actie')], 404);
