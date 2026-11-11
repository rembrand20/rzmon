<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: beheer via https, datalekken, gebruikers en 2FA, onzichtbaar van buiten.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var array<string, mixed> $user
 * @var bool $isAdmin
 * @var callable(string): void $confirm
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------- Beheer via https (1.68.0): beheeradres, alleen https, inloggen via http weigeren, 2FA van buiten ----------
    case 'https_info':
        $state = json_decode((string) setting('https_state', ''), true) ?: [];
        // Het IP-adres van RzSense zelf (voor de nooddeur), zoals de browser of proxy het bereikte
        $sa = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        if (filter_var($sa, FILTER_VALIDATE_IP) && !ip_in_list($sa, ['127.0.0.0/8', '::1/128']) && ($state['server_ip'] ?? '') !== $sa) {
            $state['server_ip'] = $sa;
            save_setting('https_state', json_encode($state, JSON_UNESCAPED_UNICODE));
        }
        $full = Https::hstsFullAt();
        out([
            'admin_url' => Https::adminUrl(), 'https_only' => setting('https_only', '0') === '1', 'http_login_block' => setting('http_login_block', '0') === '1',
            'mfa_external' => setting('mfa_external', '0') === '1',
            'req' => ['https' => Https::isHttps(), 'host' => Https::host(), 'on_admin' => Https::onAdminUrl(), 'via_proxy' => Https::viaProxy(),
                'unknown_proxy' => Https::unknownProxy(), 'no_proto' => Https::proxyNoProto(), 'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
                'client_ip' => client_ip(), 'internal' => Https::internal()],
            'proxies' => parse_list((string) setting('trusted_proxies', '')),
            'proxy_unknown' => json_decode((string) setting('proxy_unknown', ''), true) ?: null,
            'hsts' => Https::on() ? ['age' => Https::hstsAge(), 'full_at' => $full ? date('Y-m-d H:i', $full) : null] : null,
            'state' => $state ?: null, 'checked_at' => setting('https_check_at', '') ?: null,
            'door' => Https::door(),
            'no2fa' => q("SELECT username FROM users WHERE totp_secret IS NULL OR totp_secret = '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN),
            'my_2fa' => (bool) q("SELECT totp_secret IS NOT NULL AND totp_secret <> '' FROM users WHERE id = ?", [$user['id']])->fetchColumn(),
            'warn_days' => Https::WARN_DAYS,
        ]);

    case 'https_check':
        out(['ok' => true, 'state' => Https::check()]);

    case 'https_save':
        $in = input();
        $url = rtrim(trim((string) ($in['admin_url'] ?? Https::adminUrl())), '/');
        if ($url !== '' && !preg_match('~^https://[a-z0-9.\-]+(:\d{1,5})?(/[^?#\s]*)?$~i', $url)) {
            out(['error' => t('Beheeradres: gebruik https:// met een naam, bv. https://rzmon.jouwdomein.nl')], 422);
        }
        $only = !empty($in['https_only']);
        $block = !empty($in['http_login_block']);
        $mfa = !empty($in['mfa_external']);
        $was = ['only' => setting('https_only', '0') === '1', 'block' => setting('http_login_block', '0') === '1', 'mfa' => setting('mfa_external', '0') === '1'];
        $urlChanged = $url !== Https::adminUrl();
        // Minder veilig maken: bevestigen met je wachtwoord
        if (($was['only'] && !$only) || ($was['block'] && !$block) || ($was['mfa'] && !$mfa)) {
            $confirm('UITZETTEN');
        }
        if ($only && $url === '') {
            out(['error' => t('Vul eerst het beheeradres in')], 422);
        }
        if ($only && (!$was['only'] || $urlChanged)) {
            // Alleen aanzetten als het nu al werkt: je zit via het beheeradres met https, en het certificaat klopt
            if (!Https::onAdminUrl($url)) {
                out(['error' => t('Open RzSense eerst via {url} en zet het daar aan. Zo weet je zeker dat het werkt voordat http dicht gaat.', ['url' => $url])
                    . (Https::proxyNoProto() ? ' ' . t('Je proxy meldt geen https (X-Forwarded-Proto ontbreekt).') : '')
                    . (Https::unknownProxy() ? ' ' . t('Je komt via een proxy die niet bij Vertrouwde proxy\'s staat.') : '')], 422);
            }
            $s = Https::check(false, $url);
            if ($s['problem'] !== null) {
                Https::check();
                out(['error' => t('Niet aangezet: {p}. RzSense controleert het certificaat zelf; kan de RzSense-server {url} niet bereiken, zet de DNS-rewrite dan ook voor de RzSense-server (of een regel in /etc/hosts).', ['p' => $s['problem'], 'url' => $url])], 422);
            }
            save_setting('https_since', now());
        }
        if ($block && !$was['block'] && !Https::isHttps()) {
            out(['error' => t('Zet dit aan terwijl je RzSense via https open hebt; anders sluit je jezelf nu buiten')], 422);
        }
        if ($mfa && !$was['mfa'] && !Https::internal()
            && !q("SELECT totp_secret IS NOT NULL AND totp_secret <> '' FROM users WHERE id = ?", [$user['id']])->fetchColumn()) {
            out(['error' => t('Je zit nu buiten je interne netwerken en je eigen account heeft nog geen 2FA: stel eerst 2FA in (Mijn account)')], 422);
        }
        foreach (['admin_url' => $url, 'https_only' => $only ? '1' : '0', 'http_login_block' => $block ? '1' : '0', 'mfa_external' => $mfa ? '1' : '0'] as $k => $v) {
            if (setting($k, '') !== $v) {
                save_setting($k, $v);
                AgentCommands::audit($user['username'], 'Beheer via https', "$k = $v");
            }
        }
        if ($urlChanged && $url !== '' && !$only) {
            Https::check(false, $url);
        }
        out(['ok' => true]);

    // ---------- Datalekken via Have I Been Pwned (1.69.0) ----------
    case 'hibp_info':
        out([
            'enabled' => Hibp::enabled(), 'threshold' => Hibp::threshold(), 'has_key' => Hibp::hasKey(),
            'emails' => (string) setting('hibp_emails', ''), 'domain' => Hibp::domain(), 'rpm' => (int) setting('hibp_rpm', '10'),
            'last_error' => (string) setting('hibp_last_error', '') ?: null, 'domain_error' => (string) setting('hibp_domain_error', '') ?: null,
            'check_at' => setting('hibp_check_at', '') ?: null, 'domain_at' => setting('hibp_domain_at', '') ?: null,
            'count' => count(Hibp::emails()), 'summary' => Hibp::enabled() ? Hibp::summary() : null,
        ]);

    case 'hibp_save':
        $in = input();
        if (array_key_exists('enabled', $in) && !empty($in['enabled']) !== Hibp::enabled()) {
            save_setting('hibp_enabled', !empty($in['enabled']) ? '1' : '0');
            AgentCommands::audit($user['username'], 'Datalekken', 'controle ' . (!empty($in['enabled']) ? 'aan' : 'uit'));
        }
        if (isset($in['threshold'])) {
            save_setting('hibp_threshold', (string) max(1, min(1000000, (int) $in['threshold'])));
        }
        if (isset($in['emails'])) {
            $list = [];
            foreach (parse_list((string) $in['emails']) as $e) {
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                    out(['error' => t('Ongeldig e-mailadres: {e}', ['e' => mb_substr($e, 0, 100)])], 422);
                }
                $list[strtolower($e)] = true;
            }
            save_setting('hibp_emails', implode("\n", array_keys($list)));
        }
        if (isset($in['domain'])) {
            $d = strtolower(trim((string) $in['domain']));
            if ($d !== '' && !preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) {
                out(['error' => t('Ongeldig domein (bv. jouwdomein.nl)')], 422);
            }
            if ($d !== Hibp::domain()) {
                save_setting('hibp_domain', $d);
                save_setting('hibp_domain_at', '');
                save_setting('hibp_domain_error', '');
            }
        }
        if (isset($in['key'])) {
            $key = trim((string) $in['key']);
            if ($key !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/', $key)) {
                out(['error' => t('Dat lijkt geen API-sleutel van HIBP (32 letters en cijfers)')], 422);
            }
            save_setting('hibp_key', $key);
            save_setting('hibp_last_error', '');
            save_setting('hibp_wait_until', '0');
            AgentCommands::audit($user['username'], 'Datalekken', $key === '' ? 'API-sleutel verwijderd' : 'API-sleutel ingesteld');
        }
        out(['ok' => true]);

    case 'hibp_test':
        $key = trim((string) (input()['key'] ?? ''));
        try {
            $s = Hibp::subscription($key !== '' ? $key : null);
            out(['ok' => true, 'name' => $s['SubscriptionName'] ?? '?', 'until' => $s['SubscribedUntil'] ?? null, 'rpm' => $s['Rpm'] ?? null,
                'domain_max' => $s['DomainSearchMaxBreachedAccounts'] ?? null]);
        } catch (Throwable $e) {
            out(['error' => 'HIBP: ' . $e->getMessage()], 422);
        }

    // Nu controleren: het domein en een eerste ronde adressen (de rest volgt via cron)
    case 'hibp_run':
        if (!Hibp::enabled() || !Hibp::hasKey()) {
            out(['error' => t('Zet de controle aan en vul een API-sleutel in')], 422);
        }
        q('UPDATE hibp_emails SET checked_at = NULL');
        save_setting('hibp_domain_at', '');
        save_setting('hibp_wait_until', '0');
        $msg = Hibp::cron(8);
        out(['ok' => true, 'message' => $msg, 'left' => count(array_filter(Hibp::emails(), static fn($s) => $s !== 'domain'))
            - (int) q("SELECT COUNT(*) FROM hibp_emails WHERE checked_at IS NOT NULL AND source <> 'domain'")->fetchColumn()]);

    case 'hibp_overview':
        if (!Hibp::enabled()) {
            out(['error' => t('De controle op datalekken staat uit')], 422);
        }
        out(Hibp::overview());

    case 'hibp_handle':
        $in = input();
        $done = !empty($in['handled']);
        q('UPDATE hibp_breaches SET handled_at = ?, handled_by = ? WHERE email = ? AND name = ?',
            [$done ? now() : null, $done ? $user['username'] : null, (string) ($in['email'] ?? ''), (string) ($in['name'] ?? '')]);
        out(['ok' => true]);

    case 'port_scan':
        $in = input();
        $host = q('SELECT id, address, mode FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$host) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        try {
            $ports = PortScan::presetPorts((string) ($in['preset'] ?? 'common'), (string) ($in['ports'] ?? ''));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        if (!$ports) {
            out(['error' => t('Geen poorten opgegeven')], 422);
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
        out(q('SELECT id, name, key_hint, last_used, created_at, rights, allow_from, expires_at, created_by FROM api_keys ORDER BY id')->fetchAll());

    // Sleutel maken of aanpassen: rechten, alleen vanaf (IP's/netwerken) en geldigheid (6, 12 of 24 maanden)
    case 'api_key_create':
    case 'api_key_update':
        $in = input();
        $rights = array_values(array_intersect(API_RIGHTS, array_map('strval', (array) ($in['rights'] ?? []))));
        if (!$rights) {
            out(['error' => t('Kies minstens één recht')], 422);
        }
        // 1.239.0: "@lan" = de lokale netwerken (blijft gekoppeld); een ongeldig adres geeft een melding in plaats van stil te verdwijnen
        $fromList = parse_list(mb_substr((string) ($in['allow_from'] ?? ''), 0, 500));
        if ($bad = array_values(array_filter($fromList, static fn($x) => $x !== '@lan' && !valid_ip_or_net($x)))) {
            out(['error' => t('Geen geldig IP-adres of netwerk: {v}', ['v' => implode(', ', array_slice($bad, 0, 3))])], 422);
        }
        $from = implode(' ', array_slice($fromList, 0, 20));
        $months = (int) ($in['months'] ?? 0);
        if ($months && !in_array($months, [6, 12, 24], true)) {
            out(['error' => t('Kies 6, 12 of 24 maanden')], 422);
        }
        if ($action === 'api_key_create') {
            $name = trim((string) ($in['name'] ?? ''));
            if ($name === '') {
                out(['error' => t('Geef de sleutel een naam (bv. "Home Assistant")')], 422);
            }
            if (in_array('ids_block', $rights, true)) {
                $confirm('BLOKKEREN');
            }
            $key = 'rzm_' . bin2hex(random_bytes(20));
            q('INSERT INTO api_keys (name, key_hash, key_hint, rights, allow_from, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                mb_substr($name, 0, 100), hash('sha256', $key), substr($key, 0, 8) . '…', implode(',', $rights), $from ?: null,
                date('Y-m-d H:i:s', strtotime('+' . ($months ?: 12) . ' months')), $user['username'],
            ]);
            AgentCommands::audit($user['username'], 'API-sleutel gemaakt', "$name: " . implode(', ', $rights) . ($from ? " · alleen vanaf $from" : '') . ' · ' . ($months ?: 12) . ' maanden');
            out(['ok' => true, 'key' => $key]);
        }
        $k = q('SELECT * FROM api_keys WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
        if (!$k) {
            out(['error' => t('Sleutel niet gevonden')], 404);
        }
        // Blokkeren erbij geven vraagt om je wachtwoord, net als bij een nieuwe sleutel
        if (in_array('ids_block', $rights, true) && !in_array('ids_block', explode(',', (string) $k['rights']), true)) {
            $confirm('BLOKKEREN');
        }
        q('UPDATE api_keys SET rights = ?, allow_from = ?, expires_at = ? WHERE id = ?', [implode(',', $rights), $from ?: null,
            $months ? date('Y-m-d H:i:s', strtotime("+$months months")) : $k['expires_at'], $k['id']]);
        AgentCommands::audit($user['username'], 'API-sleutel aangepast', "{$k['name']}: " . implode(', ', $rights) . ($from ? " · alleen vanaf $from" : '')
            . ($months ? " · verlengd met $months maanden" : ''));
        out(['ok' => true]);

    case 'api_key_delete':
        $k = q('SELECT name FROM api_keys WHERE id = ?', [(int) (input()['id'] ?? 0)])->fetchColumn();
        q('DELETE FROM api_keys WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        if ($k !== false) {
            AgentCommands::audit($user['username'], 'API-sleutel ingetrokken', (string) $k);
        }
        out(['ok' => true]);

    // ---------- Gebruikers en tweestapsverificatie ----------
    case 'users':
        $rows = q('SELECT id, username, email, role, totp_secret IS NOT NULL AS totp, created_at, last_login, locked_at, locked_until, locked_ip,
                          local_only, hibp, pw_pwned, pw_pwned_at,
                          (SELECT COUNT(*) FROM remember_tokens r WHERE r.user_id = users.id) AS remembered FROM users'
            . ($isAdmin ? '' : ' WHERE id = ' . (int) $user['id']) . ' ORDER BY username')->fetchAll();
        $perUser = [];
        foreach (session_list($isAdmin ? null : (int) $user['id']) as $s) {
            if ($s['user_id'] !== null) {
                $perUser[$s['user_id']] = ($perUser[$s['user_id']] ?? 0) + 1;
            }
        }
        foreach ($rows as &$r) {
            $r['devices'] = $perUser[(int) $r['id']] ?? 0;   // ingelogde apparaten (1.79.0)
            $r['totp'] = (bool) $r['totp'];
            $r['me'] = (int) $r['id'] === $user['id'];
            $r['local_only'] = (bool) $r['local_only'];
            $r['hibp'] = (bool) $r['hibp'];
            $r['locked'] = Security::accountLocked($r);
            if (!$r['locked']) {
                $r['locked_at'] = $r['locked_until'] = $r['locked_ip'] = null;
            }
        }
        unset($r);
        out(['users' => $rows, 'hibp' => Hibp::enabled(), 'internal' => Https::internal()]);

    // Per account: alleen vanaf de interne netwerken, en de datalek-controle (alleen beheerders)
    case 'user_flags':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $u = q('SELECT id, username, role, local_only, hibp FROM users WHERE id = ?', [$id])->fetch();
        if (!$u) {
            out(['error' => t('Gebruiker niet gevonden')], 404);
        }
        if (array_key_exists('local_only', $in)) {
            $lo = !empty($in['local_only']);
            if ($lo && $id === $user['id'] && !Https::internal()) {
                out(['error' => t('Je zit nu buiten je interne netwerken: dan sluit je jezelf buiten')], 422);
            }
            if ($lo && $u['role'] === 'admin' && !(int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0 AND id <> ?", [$id])->fetchColumn()) {
                out(['error' => t('Er moet minstens één beheerder overblijven die ook van buiten mag inloggen')], 422);
            }
            q('UPDATE users SET local_only = ? WHERE id = ?', [$lo ? 1 : 0, $id]);
            AgentCommands::audit($user['username'], 'Alleen lokaal inloggen', "{$u['username']} = " . ($lo ? 'aan' : 'uit'));
        }
        if (array_key_exists('hibp', $in)) {
            $on = !empty($in['hibp']);
            // Uit: niets meer controleren en geen verplicht wijzigen; aan: bij de volgende login opnieuw controleren
            q('UPDATE users SET hibp = ?, pw_pwned = NULL, pw_pwned_at = NULL, pw_must_change = 0 WHERE id = ?', [$on ? 1 : 0, $id]);
            AgentCommands::audit($user['username'], 'Datalek-controle', "{$u['username']} = " . ($on ? 'aan' : 'uit'));
        }
        out(['ok' => true]);

    // Voorkeuren van de gebruiker (1.71.0): open_mode (closed | smart), fx (filters per pagina), fxviews (opgeslagen filters)
    case 'prefs_save':
        if (empty($user['id'])) {
            out(['error' => t('Zonder account worden voorkeuren alleen in deze browser bewaard')], 422);
        }
        $in = input();
        $key = (string) ($in['key'] ?? '');
        if (!in_array($key, ['open_mode', 'fx', 'fxviews', 'dash_filter', 'alert_filter', 'vuln_open', 'theme', 'side', 'dash', 'dash_layout', 'dash_seen', 'mon_glow', 'mon_anim', 'mon_glass', 'mon_loader', 'mon_vivid', 'layout', 'pages', 'ids_modern', 'af_motion', 'ui', 'side_w'], true)) {
            out(['error' => t('Onbekende voorkeur')], 422);
        }
        $prefs = json_decode((string) q('SELECT prefs FROM users WHERE id = ?', [$user['id']])->fetchColumn(), true) ?: [];
        $prefs[$key] = match ($key) {
            'open_mode' => $in['value'] === 'smart' ? 'smart' : 'closed',
            'theme' => in_array($in['value'] ?? '', ['light', 'dark'], true) ? $in['value'] : 'auto',   // 1.135.0: thema per gebruiker
            'side' => ($in['value'] ?? '') === 'mini' ? 'mini' : 'full',   // 1.150.0: zijbalk ingeklapt (alleen iconen)
            'dash' => ($in['value'] ?? '') === 'cc' ? 'cc' : 'classic',
            'mon_glow', 'mon_anim', 'mon_glass', 'mon_loader', 'mon_vivid', 'ids_modern' => ($in['value'] ?? '') === 'off' ? 'off' : 'on',
            'af_motion' => (string) max(0, min(2, (int) ($in['value'] ?? 2))),
            'side_w' => ($w = (int) ($in['value'] ?? 0)) >= 180 && $w <= 380 ? $w : 0,   // 1.167.0: breedte van de zijbalk (0 = standaard)   // 1.162.0: beweging van de Attack Flow, per account
            'ui' => clean_ui_prefs((array) ($in['value'] ?? [])),   // 1.162.0: weergave-keuzes die eerst per apparaat waren
            'layout' => in_array($in['value'] ?? '', ['blox', 'l1nes', 'compact', 'cc'], true) ? $in['value'] : 'blox',   // 1.158.0: lay-out per account
            'pages' => clean_page_layouts((array) ($in['value'] ?? [])),   // 1.158.0: indeling per pagina in CC   // 1.157.0: rode gloed en live-animaties op Monitoring   // 1.154.0: welk dashboard (klassiek of Command center)
            'dash_seen' => date('Y-m-d H:i:s'),   // 1.154.0: laatste bezoek aan het dashboard ("sinds je laatste bezoek")
            // 1.154.0: indeling van het Command center: volgorde en verborgen kaarten (alleen bekende namen)
            'dash_layout' => ['order' => array_values(array_unique(array_filter(array_map(static fn($k) => preg_replace('/[^a-z_]/', '', (string) $k), (array) ($in['value']['order'] ?? []))))),
                'hide' => array_values(array_unique(array_filter(array_map(static fn($k) => preg_replace('/[^a-z_]/', '', (string) $k), (array) ($in['value']['hide'] ?? [])))))],
            'dash_filter', 'alert_filter' => preg_match('/^[a-z:]{1,20}$/', (string) $in['value']) ? (string) $in['value'] : 'all',   // keuze bovenaan het dashboard (1.75.0) en de meldingen (1.84.0)
            // Open blokken op Kwetsbaarheden (servers) en Datalekken (e-mailadressen), 1.93.0
            'vuln_open' => ['h' => array_slice(array_values(array_unique(array_map('intval', (array) ($in['value']['h'] ?? [])))), -500),
                'e' => array_slice(array_values(array_unique(array_map(static fn($e) => mb_substr((string) $e, 0, 254), (array) ($in['value']['e'] ?? [])))), -500)],
            default => (array) ($in['value'] ?? []),
        };
        $json = json_encode($prefs, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 200000) {
            out(['error' => t('Te veel opgeslagen filters')], 422);
        }
        q('UPDATE users SET prefs = ? WHERE id = ?', [$json, $user['id']]);
        out(['ok' => true]);

    // Nieuw wachtwoord kiezen omdat het huidige in een datalek staat; een gelekt wachtwoord wordt hier geweigerd
    case 'pw_forced':
        $new = (string) (input()['new'] ?? '');
        if (strlen($new) < 8) {
            out(['error' => t('Nieuw wachtwoord moet minimaal 8 tekens zijn')], 422);
        }
        $hash = (string) q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
        if (password_verify($new, $hash)) {
            out(['error' => t('Kies een ander wachtwoord dan je huidige')], 422);
        }
        $n = Hibp::pwnedCount($new);
        if ($n !== null && $n >= Hibp::threshold()) {
            out(['error' => t('Dit wachtwoord staat ook in datalekken ({n}×). Kies een ander, bv. een zin van een paar woorden.', ['n' => I18n::lang() === 'en' ? number_format($n) : number_format($n, 0, ',', '.')])], 422);
        }
        q('UPDATE users SET password_hash = ?, pw_changed_at = ?, pw_must_change = 0, pw_pwned = ?, pw_pwned_at = ? WHERE id = ?',
            [hash_password($new), now(), $n === null ? null : 0, $n === null ? null : now(), $user['id']]);
        $_SESSION['auth_at'] = time();
        session_logout('user', (int) $user['id']);   // andere sessies en onthouden apparaten; deze blijft
        out(['ok' => true]);

    // ---------- Onzichtbaar van buiten (1.86.0): toegestane apparaten en passkeys ----------
    case 'my_access':
        $uid = (int) ($user['id'] ?? 0);
        $dev = Stealth::device();
        out([
            'devices' => $uid ? Stealth::devices($uid) : [], 'passkeys' => $uid ? Passkey::list($uid) : [],
            'current' => $dev && (int) $dev['user_id'] === $uid ? (int) $dev['id'] : null,
            'can_trust' => $uid > 0 && !is_lan_session() && (!Stealth::outside() || Stealth::viaDoor($uid)),
            'internal' => !Stealth::outside(), 'ui_locked' => Stealth::uiLocked(), 'door' => setting('stealth_door', '1') === '1',
            'passkey_possible' => Passkey::possible(), 'passkeys_enabled' => Passkey::enabled(), 'rp_id' => Passkey::rpId(),
            'door_url' => $uid ? public_url() . '/?nood=' . rawurlencode((string) $user['username']) : null,
        ]);

    case 'device_trust':
        $uid = (int) ($user['id'] ?? 0);
        if (!$uid || is_lan_session()) {
            out(['error' => t('Log in met je eigen account')], 403);
        }
        if (Stealth::outside() && !Stealth::viaDoor($uid)) {
            out(['error' => t('Een apparaat toestaan kan alleen thuis (vanaf je eigen netwerk), of na een noodlink')], 403);
        }
        $confirm('TOESTAAN');
        out(['ok' => true, 'id' => Stealth::trust($user, (string) (input()['name'] ?? ''))]);

    case 'device_revoke':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Stealth::revoke((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'device_rename':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Stealth::rename((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], (string) ($in['name'] ?? '')) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'passkey_reg_options':
        if (empty($user['id']) || is_lan_session()) {
            out(['error' => t('Log in met je eigen account')], 403);
        }
        if (!Passkey::possible()) {
            out(['error' => t('Passkeys werken alleen via https met een domeinnaam (bv. je beheeradres), niet via een IP-adres')], 422);
        }
        $confirm('PASSKEY');
        out(Passkey::registerOptions($user));

    case 'passkey_reg_finish':
        $in = input();
        $dev = Stealth::device();
        $r = Passkey::registerFinish($user, $in, $dev && (int) $dev['user_id'] === (int) $user['id'] ? (int) $dev['id'] : null);
        is_int($r) ? out(['ok' => true, 'id' => $r]) : out(['error' => $r], 422);

    case 'passkey_delete':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Passkey::remove((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'passkey_rename':
        $in = input();
        $all = $isAdmin && !empty($in['admin']);
        Passkey::rename((int) ($in['id'] ?? 0), $all ? null : (int) $user['id'], (string) ($in['name'] ?? '')) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    // Beheerders: alle toegestane apparaten en passkeys, en de instellingen
    case 'stealth_info':
        out([
            'hide' => Stealth::hide(), 'ui' => Stealth::uiLocked(), 'countries' => setting('stealth_countries', '1') === '1', 'door' => setting('stealth_door', '1') === '1',
            'passkeys' => Passkey::enabled(), 'allowed_countries' => IpIntel::allowedCountries(), 'geo_data' => IpIntel::lookupCountry('8.8.8.8') !== null,
            'devices_all' => Stealth::devices(null), 'passkeys_all' => Passkey::list(null), 'internal' => !Stealth::outside(),
            'requests_on' => setting('stealth_requests', '1') === '1', 'requests' => Stealth::requests(),
            'users' => q('SELECT id, username, role FROM users ORDER BY username')->fetchAll(),
        ]);

    // 1.103.0: netwerkscan – alle apparaten op je lokale netwerken
    case 'net_devices':
        $nets = NetScan::networks();
        out(['enabled' => NetScan::enabled(), 'devices' => NetScan::list(), 'traffic' => NetTraffic::summary() ?: (object) [], 'traffic_totals' => NetTraffic::totals(), 'traffic_state' => NetTraffic::state(), 'wan' => NetTraffic::wanMonth(), 'state' => NetScan::state(), 'networks' => $nets,
            'risky_reasons' => NetScan::riskyReasons(), 'kinds' => NetScan::KINDS, 'now' => now(), 'vendors' => (int) q('SELECT COUNT(*) FROM mac_vendors')->fetchColumn(),
            'links' => ['unifi' => NetLinks::unifiOn(), 'unifi_block' => NetLinks::unifiCanBlock(), 'adguard' => NetLinks::adguardOn()], 'my_ip' => client_ip()]);

    // 1.106.0: 📊 Analyse – alles voor één periode (5 minuten bewaard per periode), en het maandrapport
    case 'pick_sources':   // 1.239.0: waar slimme invulvelden uit kunnen kiezen (alleen lezen)
        out(Pick::sources());

    case 'advice_list':   // 1.108.0: slimme voorstellen en vaste onderhoudsvensters
        out(['list' => Advice::list(), 'windows' => Advice::windows(), 'last' => (string) setting('advice_day', ''), 'auto' => setting('alert_auto', '1') === '1']);

    case 'advice_decide':
        $in = input();
        try {
            out(['ok' => true, 'message' => Advice::decide((int) ($in['id'] ?? 0), !empty($in['apply']))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'inet_state':   // 1.157.0: Instellingen → Monitoring → Internet
        out(Internet::state());

    case 'inet_install':   // het apparaat "Internet" met de vier checks
        $hid = Internet::install();
        out(['ok' => true, 'host_id' => $hid, 'message' => t('Internet-check toegevoegd')]);

    case 'inet_speed_now':   // nu een snelheidstest (duurt tot ~25 seconden)
        session_write_close();
        $r = Internet::speedtest();
        if ($r['err'] !== null) {
            out(['error' => t('Snelheidstest mislukt: {e}', ['e' => $r['err']])], 422);
        }
        out(['ok' => true, 'result' => $r, 'message' => t('↓ {d} / ↑ {u} Mbit/s', ['d' => (string) round((float) $r['down']), 'u' => (string) round((float) $r['up'])])]);

    case 'advice_run':   // nu opnieuw bepalen (anders elke nacht)
        out(['ok' => true, 'n' => Advice::cron(true)]);

    case 'window_save':
        try {
            out(['ok' => true, 'id' => Advice::windowSave(input())]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'window_delete':
        Advice::windowDelete((int) (input()['id'] ?? 0));
        out(['ok' => true]);
}
