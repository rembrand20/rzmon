<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: beveiliging van de agents, versleuteling, blokkades, certificaat.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var array<string, mixed> $user
 * @var callable(int): void $ownOnly
 * @var callable(string): void $confirm
 * @var callable(array<string, mixed>, bool=): array{array<string, mixed>, bool} $checkCols
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------- Beveiliging: versleuteling van de agents en blokkades (alleen beheerders) ----------
    // Instellingen → Hoe staat het ervoor?: punten om na te lopen, elk met het onderdeel waar je het regelt
    case 'connections':   // 1.91.0: Instellingen → Overzicht → Verbindingen en taken
        out(['inbound' => Tasks::inbound(), 'tasks' => Tasks::list(), 'now' => now()]);

    case 'settings_health':
        $items = [];
        // $extra (1.78.0): act + label (knop om het meteen te regelen) of code (om te kopiëren)
        $add = static function (string $key, string $st, string $title, string $detail, string $section, array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'status' => $st, 'title' => $title, 'detail' => $detail, 'section' => $section] + $extra;
        };
        $po = Push::names();
        $mail = setting('email_enabled', '0') === '1' && setting('smtp_host', '') !== '' && setting('mail_to', '') !== '';
        $add('channels', $po !== '' || $mail ? 'ok' : 'bad', t('Meldkanaal'), $po !== '' || $mail
            ? implode(' + ', array_filter([$po, $mail ? t('e-mail') : '']))
            : t('Pushover, ntfy en e-mail staan uit: storingen zie je alleen op het dashboard'), 'channels');
        $last = Backup::list()[0]['at'] ?? '';
        $age = $last !== '' ? (time() - strtotime($last)) / 86400 : null;
        $auto = setting('backup_freq', 'off') !== 'off';
        $add('backup', $auto && $age !== null && $age < 8 ? 'ok' : 'warn', t('Back-up'),
            ($auto ? t('automatisch') : t('niet automatisch')) . ' · ' . ($last !== '' ? t('laatste: {d}', ['d' => $last]) : t('nog geen back-up')), 'backup');
        // Accounts die alleen lokaal mogen inloggen (bv. een noodaccount) tellen niet mee: van buiten kan daar niemand mee in
        $no2fa = q("SELECT username FROM users WHERE role = 'admin' AND local_only = 0 AND (totp_secret IS NULL OR totp_secret = '') ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
        $local = q("SELECT username FROM users WHERE role = 'admin' AND local_only = 1 AND (totp_secret IS NULL OR totp_secret = '') ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
        $admins = (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($admins > 0) {
            $add('2fa', $no2fa ? 'warn' : 'ok', t('Tweestapsverificatie'), ($no2fa
                ? t('beheerders zonder 2FA: {names}', ['names' => implode(', ', $no2fa)]) : t('alle beheerders hebben 2FA'))
                . ($local ? ' · ' . t('alleen lokaal, zonder 2FA: {names}', ['names' => implode(', ', $local)]) : ''), 'users');
        }
        $locks = setting('login_lock_account', '1') === '1' || setting('login_lock_ip', '1') === '1';
        $add('lock', $locks ? 'ok' : 'warn', t('Inlogbeveiliging'), $locks
            ? t('blokkeren na {n} mislukte pogingen', ['n' => (int) setting('login_lock_fails', '3')]) : t('staat uit: wachtwoorden raden wordt niet afgeremd'), 'access');
        $cache = json_decode((string) setting('update_cache', ''), true) ?: [];
        $newer = !empty($cache['latest']) && version_compare($cache['latest'], RZMON_VERSION, '>');
        $add('update', $newer ? 'warn' : 'ok', t('RzSense-versie'), $newer
            ? t('{v} staat klaar (je hebt {cur})', ['v' => $cache['latest'], 'cur' => RZMON_VERSION]) : t('{cur} is de nieuwste', ['cur' => RZMON_VERSION]), 'update');
        $agents = q("SELECT name, agent_version, enc, enc_seen, agent_key_seen, agent_ip_new FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL")->fetchAll();
        if ($agents) {
            $old = array_column(array_filter($agents, static fn($h) => $h['agent_version'] && version_compare($h['agent_version'], AGENT_LATEST, '<')), 'name');
            $add('agents', $old ? 'warn' : 'ok', t('Agents bijgewerkt'), $old
                ? t('{n} van {t} op een oudere versie: {names}', ['n' => count($old), 't' => count($agents), 'names' => implode(', ', array_slice($old, 0, 5)) . (count($old) > 5 ? ' …' : '')])
                : t('alle {t} op {v}', ['t' => count($agents), 'v' => AGENT_LATEST]), 'agents');
            $plain = array_column(array_filter($agents, static fn($h) => (int) $h['enc_seen'] !== 1), 'name');
            // 1.149.3: zonder algemene sleutel is het alleen een probleem als er nog agents zonder eigen sleutel zijn. Na
            // "Alle agents opruimen" wist RzSense die sleutel juist bewust (alle agents hebben dan hun eigen sleutel).
            $ownAll = !array_filter($agents, static fn($h) => (int) $h['agent_key_seen'] !== 1);
            $modeOff = AgentCrypto::globalMode() === 'off';
            $off = $modeOff || (AgentCrypto::key() === '' && !$ownAll);
            $add('enc', $off ? 'warn' : ($plain ? 'warn' : 'ok'), t('Versleuteling van de agents'), $off
                ? ($modeOff ? t('staat uit') : t('nog geen algemene sleutel, en niet alle agents hebben een eigen sleutel')) : ($plain
                    ? t('{n} onversleuteld: {names}', ['n' => count($plain), 'names' => implode(', ', array_slice($plain, 0, 5)) . (count($plain) > 5 ? ' …' : '')])
                    : ($ownAll ? t('alle agents versleuteld met hun eigen sleutel') : t('alle agents versleuteld'))), 'enc');
            $nokey = array_column(array_filter($agents, static fn($h) => (int) $h['agent_key_seen'] !== 1), 'name');
            $add('ownkey', $nokey ? 'warn' : 'ok', t('Eigen sleutel per agent'), $nokey
                ? t('{n} nog zonder eigen sleutel: {names} (agent {v} nodig; anders opnieuw koppelen)', ['n' => count($nokey), 'v' => AgentCrypto::OWNKEY_MIN,
                    'names' => implode(', ', array_slice($nokey, 0, 5)) . (count($nokey) > 5 ? ' …' : '')])
                : t('alle agents hebben een eigen sleutel'), 'enc');
            // Verbinding (1.72.0): via http, of via het reserve-adres
            $conn = q("SELECT name, agent_conn, agent_fb_at FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL")->fetchAll();
            $viaFb = array_column(array_filter($conn, static fn($h) => !empty($h['agent_fb_at'])), 'name');
            $viaHttp = array_column(array_filter($conn, static fn($h) => $h['agent_conn'] === 'http' && empty($h['agent_fb_at'])), 'name');
            $add('agent_https', $viaFb ? 'bad' : ($viaHttp ? 'warn' : 'ok'), t('Agents via https'), $viaFb
                ? t('via het reserve-adres: {names} (werkt de reverse proxy?)', ['names' => implode(', ', $viaFb)])
                : ($viaHttp ? t('{n} nog via http: {names}', ['n' => count($viaHttp), 'names' => implode(', ', array_slice($viaHttp, 0, 5)) . (count($viaHttp) > 5 ? ' …' : '')])
                    : t('alle agents via https')), 'enc');
            // Alle agents al via https, maar "alleen via https" staat nog uit (1.78.0): voorstellen om het aan te zetten
            if (!$viaFb && !$viaHttp && setting('agent_https_only', '0') !== '1') {
                $add('agent_https_only', 'warn', t('Agents alleen via https'),
                    t('alle agents verbinden al via https; zet "alleen via https" aan, dan weigert RzSense rapporten die via http komen'), 'enc',
                    ['act' => 'agent_https_only', 'label' => t('Aanzetten')]);
            }
            // Verbindingscontrole (1.100.0): overbodige instellingen, gewijzigd script, tokens; en alleen agents met eigen sleutel
            $audit = AgentAudit::all();
            $viaLabel = t('Via https');   // heeft hieronder een eigen punt
            foreach ($audit as &$a) {
                $a['issues'] = array_values(array_diff($a['issues'], [$viaLabel]));
            }
            unset($a);
            $issues = array_filter($audit, static fn($a) => (bool) $a['issues']);
            $fix = array_filter($audit, static fn($a) => $a['fixable']);
            $add('agent_audit', $issues ? 'warn' : 'ok', t('Verbindingscontrole agents'), $issues
                ? implode(' · ', array_map(static fn($a) => $a['name'] . ': ' . implode(', ', $a['issues']), array_slice($issues, 0, 4))) . (count($issues) > 4 ? ' …' : '')
                : t('alle {t} agents in orde (sleutel, versleuteling, versie, instellingen, script)', ['t' => count($audit)]), 'agents',
                $fix ? ['act' => 'agents_cleanup', 'label' => '🧹 ' . t('Alles opruimen')] : []);
            if (!$nokey && setting('agent_ownkey_only', '0') !== '1') {
                $add('agent_ownkey_only', 'warn', t('Alleen agents met een eigen sleutel'),
                    t('alle agents hebben een eigen sleutel; zet dit aan, dan weigert RzSense agents die zich met alleen een token melden (koppelen met een koppelcode blijft kunnen)'), 'enc',
                    ['act' => 'agent_ownkey_only', 'label' => t('Aanzetten')]);
            }
            $newip = array_column(array_filter($agents, static fn($h) => !empty($h['agent_ip_new'])), 'name');
            if ($newip) {
                $add('agent_ip', 'bad', t('Agent van een nieuw IP-adres'), t('geweigerd, wacht op je toestemming: {names}', ['names' => implode(', ', $newip)]), 'enc');
            }
        }
        // Beheer via https (1.68.0)
        $hs = json_decode((string) setting('https_state', ''), true) ?: [];
        $adminUrl = Https::adminUrl();
        $add('https', Https::on() ? 'ok' : 'warn', t('Beheer via https'), Https::on()
            ? t('alleen via https') . ' · HSTS ' . (Https::hstsFullAt() ? t('1 dag (testfase tot {d})', ['d' => date('d-m', (int) Https::hstsFullAt())]) : t('1 jaar'))
            : ($adminUrl === '' ? t('geen beheeradres ingesteld: de beheerpagina is via http bereikbaar') : t('de beheerpagina is ook via http bereikbaar')), 'https');
        if ($adminUrl !== '' && isset($hs['admin'])) {
            $c = $hs['admin'];
            $add('cert', $hs['problem'] ?? null ? 'bad' : ($c['days'] <= Https::WARN_DAYS ? 'warn' : 'ok'), t('Certificaat'), ($hs['problem'] ?? null)
                ? ucfirst((string) $hs['problem']) : ($c['days'] <= Https::WARN_DAYS ? t('verloopt over {n} dagen ({d})', ['n' => $c['days'], 'd' => date('d-m-Y', $c['to'])])
                    : t('geldig tot {d} ({issuer})', ['d' => date('d-m-Y', $c['to']), 'issuer' => $c['issuer']])), 'https');
        }
        if ($adminUrl !== '' && isset($hs['dns'])) {
            $ips = implode(', ', (array) $hs['dns']['ips']);
            $add('dns', $hs['dns']['private'] ? 'ok' : 'warn', t('Lokale DNS-rewrite'), !$hs['dns']['ips']
                ? t('{host} is vanaf de RzSense-server niet te vinden', ['host' => Https::adminHost()])
                : ($hs['dns']['private'] ? t('{host} wijst intern naar {ip}', ['host' => Https::adminHost(), 'ip' => $ips])
                    : t('{host} wijst naar {ip} (openbaar): zet een DNS-rewrite naar het LAN-IP van je proxy', ['host' => Https::adminHost(), 'ip' => $ips])), 'https');
        }
        // De proxy stuurt door via http (1.78.0): het laatste stuk (proxy → RzSense) is dan onversleuteld
        $leg = json_decode((string) setting('proxy_http_leg', ''), true) ?: [];
        $legOk = (int) ((json_decode((string) setting('proxy_https_leg', ''), true) ?: [])['at'] ?? 0);
        if ((int) ($leg['at'] ?? 0) > time() - 7 * 86400 && (int) $leg['at'] > $legOk) {
            $sip = Https::serverIp() ?: (OwnCert::serverIps()[0] ?? t('<IP-adres van RzSense>'));
            $add('proxy_leg', 'warn', t('Proxy → RzSense via http'),
                t('je proxy ({ip}) stuurt het verkeer via http door naar RzSense: wachtwoorden en sessies gaan het laatste stuk onversleuteld over je netwerk. Laat de proxy doorsturen naar https (voor SWAG: deze regels in rzmon.subdomain.conf)', ['ip' => $leg['ip']]),
                'https', ['code' => "set \$upstream_app $sip;\nset \$upstream_port 443;\nset \$upstream_proto https;\nproxy_pass \$upstream_proto://\$upstream_app:\$upstream_port;"]);
        }
        // Inloggen via http (1.78.0): de knop zet "Inloggen via http weigeren" aan (alleen als je nu via https zit)
        if (setting('http_login_block', '0') !== '1') {
            $add('http_login', 'warn', t('Inloggen via http'),
                t('toegestaan: wie RzSense via http opent, stuurt zijn wachtwoord leesbaar over het netwerk (ook via wifi)')
                . (Https::isHttps() ? '' : ' · ' . t('open RzSense via https om het hier met één klik te weigeren')), 'https',
                Https::isHttps() ? ['act' => 'http_login_block', 'label' => t('Weigeren')] : []);
        }
        // Tip (1.78.0): via een IP-adres geopend terwijl er een beheeradres is: overal hetzelfde adres met een DNS-rewrite
        $vh = Https::host();
        if ($adminUrl !== '' && filter_var($vh, FILTER_VALIDATE_IP) && ip_in_list($vh, PRIVATE_NETWORKS)) {
            $add('split_dns', 'info', t('Tip: overal hetzelfde adres'),
                t('je opent RzSense nu via {ip}. Laat je DNS (bv. AdGuard: Filters → DNS-herschrijvingen) {host} naar het LAN-IP van je proxy wijzen; dan gebruik je thuis en onderweg {url}, met een geldig certificaat en zonder waarschuwingen',
                    ['ip' => $vh, 'host' => Https::adminHost(), 'url' => $adminUrl]), 'https');
        }
        $pu = json_decode((string) setting('proxy_unknown', ''), true) ?: [];
        if ((int) ($pu['at'] ?? 0) > time() - 7 * 86400 && !ip_in_list((string) $pu['ip'], parse_list((string) setting('trusted_proxies', '')))) {
            $add('proxy', 'bad', t('Reverse proxy'), t('verkeer via {ip}, maar dat staat niet bij Vertrouwde proxy\'s: die bezoekers gelden als extern', ['ip' => $pu['ip']]), 'access');
        } elseif (Https::proxyNoProto()) {
            $add('proxy', 'warn', t('Reverse proxy'), t('je proxy meldt geen https (X-Forwarded-Proto ontbreekt): de sessiecookie is dan niet beveiligd'), 'https');
        } elseif (parse_list((string) setting('trusted_proxies', ''))) {
            $add('proxy', 'ok', t('Reverse proxy'), t('vertrouwd: {ips}', ['ips' => implode(', ', parse_list((string) setting('trusted_proxies', '')))]), 'access');
        }
        if ($adminUrl !== '') {
            $noMfa = q("SELECT username FROM users WHERE totp_secret IS NULL OR totp_secret = '' ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);
            $mfaOn = setting('mfa_external', '0') === '1';
            $add('mfa_ext', !$mfaOn ? 'warn' : ($noMfa ? 'info' : 'ok'), t('2FA van buiten'), !$mfaOn
                ? t('niet verplicht: van buiten is een wachtwoord genoeg')
                : ($noMfa ? t('verplicht · zonder 2FA alleen thuis: {names}', ['names' => implode(', ', array_slice($noMfa, 0, 5)) . (count($noMfa) > 5 ? ' …' : '')]) : t('verplicht · alle gebruikers hebben 2FA')), 'https');
        }
        // Datalekken (1.69.0)
        if (Hibp::enabled()) {
            $hs = Hibp::summary();
            $add('hibp_pw', $hs['pw_users'] ? 'bad' : 'ok', t('Gelekte wachtwoorden'), $hs['pw_users']
                ? t('in een datalek: {names} (moeten bij het inloggen een nieuw kiezen)', ['names' => implode(', ', $hs['pw_users'])])
                : t('geen bekende gelekte wachtwoorden'), 'hibp');
            $add('hibp_mail', !Hibp::hasKey() ? 'info' : (setting('hibp_last_error', '') !== '' ? 'warn' : ($hs['with_passwords'] ? 'bad' : ($hs['open'] ? 'warn' : 'ok'))),
                t('Gelekte e-mailadressen'), !Hibp::hasKey() ? t('geen API-sleutel: alleen wachtwoorden worden gecontroleerd')
                : (setting('hibp_last_error', '') !== '' ? (string) setting('hibp_last_error', '')
                    : ($hs['open'] ? t('{n} open lekken op {e} adressen ({p} met wachtwoorden)', ['n' => $hs['open'], 'e' => $hs['emails'], 'p' => $hs['with_passwords']])
                        : t('{n} adressen gecontroleerd, geen open lekken', ['n' => $hs['checked']]))), 'hibp');
        }
        if (!NetScan::enabled()) {   // 1.103.0
            $add('netscan', 'info', t('Netwerkscan'), t('staat uit: zet hem aan om te zien wat er allemaal op je netwerk zit'), 'netscan');
        } elseif (($nnew = NetScan::newCount()) > 0) {
            $add('netscan', 'warn', t('Netwerkscan'), t('{n} nieuwe apparaten nog niet beoordeeld (tabblad Netwerk)', ['n' => $nnew]), 'netscan');
        } else {
            $add('netscan', 'ok', t('Netwerkscan'), t('alle apparaten beoordeeld'), 'netscan');
        }
        if (Stealth::uiLocked() && ($nreq = Stealth::requestCount()) > 0) {   // 1.102.0
            $add('device_requests', 'warn', t('Wachtende apparaten'), t('{n} apparaat/apparaten van buiten geweigerd: toestaan, negeren of blokkeren', ['n' => $nreq]), 'outside');
        }
        $add('vulns', Vulns::enabled() ? 'ok' : 'info', t('Kwetsbaarheden'), Vulns::enabled() ? t('scan staat aan') : t('scan staat uit'), 'vulns');
        $add('ids', Ids::enabled() ? 'ok' : 'info', 'IDS', Ids::enabled() ? t('staat aan') : t('staat uit'), 'ids');
        // 1.131.0: geheugen van MariaDB (buffer pool). De standaard is 128 MB; past de database er niet in, dan leest elke
        // query over meetwaarden van de schijf en wordt alles traag (agentrapporten, cron, grafieken)
        try {
            $pool = (int) db()->query('SELECT @@innodb_buffer_pool_size')->fetchColumn();
            $dbSize = (int) q('SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
            $mem = (int) (preg_match('/^MemTotal:\s+(\d+)/m', (string) @file_get_contents('/proc/meminfo'), $m) ? $m[1] * 1024 : 0);
            $advice = $mem > 0 ? max(256, min((int) floor($mem * 0.4 / 1048576 / 128) * 128, 8192)) : 1024;   // ±40% van het geheugen, in stappen van 128 MB
            if ($pool > 0 && $dbSize > $pool * 0.8 && $pool < $advice * 1048576) {
                $add('db_pool', 'warn', t('Geheugen van de database'),
                    t('MariaDB gebruikt {pool} geheugen voor een database van {db}: veel query\'s lezen dan van de schijf en RzSense wordt traag. Zet in /etc/my.cnf.d/rzmon.cnf onder [mysqld]: innodb_buffer_pool_size = {adv}M en herstart MariaDB.',
                        ['pool' => Checks::bytes($pool), 'db' => Checks::bytes($dbSize), 'adv' => $advice]), 'db');
            } else {
                $add('db_pool', 'ok', t('Geheugen van de database'), t('{pool} voor een database van {db}', ['pool' => Checks::bytes($pool), 'db' => Checks::bytes($dbSize)]), 'db');
            }
        } catch (Throwable) {
            // geen rechten op information_schema: niets melden
        }
        // 1.139.0: is de agent die RzSense uitlevert ondertekend door de ontwikkelaar (GitHub)?
        $sg = AgentSign::status();
        $add('agent_sign', $sg['valid'] ? 'ok' : ($sg['nosodium'] ? 'warn' : ($sg['signed'] ? 'bad' : 'warn')), t('Ondertekende agent'),
            $sg['nosodium'] ? t('RzSense kan de handtekening niet controleren: de PHP-extensie sodium ontbreekt (Rocky: sudo dnf install php-sodium, daarna sudo systemctl restart php-fpm). De agents controleren hem zelf wel.')
            : ($sg['valid'] ? t('agent en installatiescript zijn ondertekend; agents controleren dat bij elke update')
                : ($sg['signed'] ? t('handtekening klopt niet bij {f}: agents weigeren deze update', ['f' => implode(', ', array_keys(array_filter($sg['files'], static fn($x) => $x === 'bad')))])
                    : t('nog niet ondertekend: de GitHub Action "Agent ondertekenen" heeft nog niet gedraaid, of het geheim AGENT_SIGN_KEY ontbreekt; agents 1.71.0 en nieuwer werken pas bij met een handtekening'))), 'agents');
        // 1.136.0: groeit de database ongewoon hard? (meer dan 30% en 100 MB in een week)
        if (($g = Retention::growth()) && $g['unusual']) {
            $add('db_growth', 'warn', t('Groei van de database'), t('{now}, de laatste {d} dagen {per} per dag erbij. Kijk bij Database en server welke tabel groeit, of bewaar korter.',
                ['now' => Checks::bytes($g['now']), 'd' => $g['days'], 'per' => Checks::bytes($g['per_day'])]), 'db');
        }
        $tl = array_filter(Tasks::list(), static fn($o) => in_array($o['status'], ['bad', 'warn'], true));
        $bad = array_filter($tl, static fn($o) => $o['status'] === 'bad');
        $add('tasks', $bad ? 'bad' : ($tl ? 'warn' : 'ok'), t('Achtergrondtaken'), $tl ? implode(', ', array_column($tl, 'label')) : t('alles loopt'), 'conn');
        out(['items' => $items]);

    case 'security':
        $key = AgentCrypto::key();
        $until = (int) setting('enc_key_old_until', '0');
        out([
            'enc' => ['mode' => AgentCrypto::globalMode(), 'key' => $key, 'key_id' => $key !== '' ? AgentCrypto::keyId($key) : null,
                'old_until' => $until > time() ? date('Y-m-d H:i:s', $until) : null, 'agent_min' => AgentCrypto::AGENT_MIN],
            'agent_tick' => agent_tick(null),
            'hosts' => array_map(static fn($h) => array_diff_key($h, ['agent_beats' => 1]) + ['key_days' => $h['agent_key_at'] ? (int) floor((time() - strtotime($h['agent_key_at'])) / 86400) : null,
                'contact' => agent_contact($h), 'truenas' => Truenas::is((int) $h['id'])],   // 1.174.0: TrueNAS apart tonen
                q("SELECT id, name, agent_version, agent_seen, agent_tick, realtime, agent_beats, agent_ip, enc, enc_seen, agent_key_seen, agent_key_at, agent_ips, agent_ip_new, agent_ip_new_at, pin_mode, ip_lock,
                          agent_https, agent_conn, agent_fb_at, agent_url, agent_url_fb, agent_url_want, agent_url_status, agent_url_at,
                          agent_url_pin, agent_url_fb_pin, (pair_until > NOW()) AS pairing FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll()),
            'conn' => ['key_days' => (int) setting('agent_key_days', '30'), 'pin' => setting('agent_pin', '1') === '1', 'ip_lock' => setting('agent_ip_lock', '1') === '1',
                'https_only' => setting('agent_https_only', '0') === '1', 'url_min' => AgentCrypto::URL_MIN, 'base_url' => rtrim((string) config('base_url', ''), '/'),
                'pin_value' => AgentCrypto::pin(false, false, ['pin_mode' => 'on']), 'https' => Https::isHttps(),
                'ownkey_min' => AgentCrypto::OWNKEY_MIN, 'pinurl_min' => AgentCrypto::PINURL_MIN, 'admin_url' => Https::adminUrl(),
                'ownkey_only' => setting('agent_ownkey_only', '0') === '1'],
            'audit' => (object) AgentAudit::all(),
            'blocks' => Security::blocks(),
            'my_ip' => client_ip(),
            'now_ts' => time(),
            'agent_latest' => AGENT_LATEST,   // 1.131.3: het overzicht kan er al zijn voordat de rest van de pagina geladen is
        ]);

    case 'enc_mode':
        $m = (string) (input()['mode'] ?? '');
        if (!in_array($m, AgentCrypto::MODES, true)) {
            out(['error' => t('Ongeldige keuze')], 422);
        }
        save_setting('enc_mode', $m);
        AgentCommands::audit($user['username'], 'Versleuteling agents', $m);
        out(['ok' => true]);

    case 'enc_generate':
        $confirm('SLEUTEL');
        out(['ok' => true, 'key' => AgentCrypto::generate($user['username'])]);

    case 'host_enc':
        $in = input();
        $m = (string) ($in['enc'] ?? '');
        if ($m !== '' && !in_array($m, AgentCrypto::MODES, true)) {
            out(['error' => t('Ongeldige keuze')], 422);
        }
        $name = q("SELECT name FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['host_id'] ?? 0)])->fetchColumn();
        if ($name === false) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        q('UPDATE hosts SET enc = ? WHERE id = ?', [$m === '' ? null : $m, (int) $in['host_id']]);
        AgentCommands::audit($user['username'], 'Versleuteling agent', "$name: " . ($m ?: 'algemeen'));
        out(['ok' => true]);

    // Beveiligde verbinding: sleutels vernieuwen na X dagen, certificaat vastzetten, vast IP-adres.
    // Iets uitzetten maakt de verbinding minder veilig: dan om je wachtwoord vragen.
    case 'conn_save':
        $in = input();
        $days = (int) ($in['key_days'] ?? 30);
        if (!in_array($days, [0, 7, 30, 90], true)) {
            out(['error' => t('Ongeldige keuze')], 422);
        }
        $pin = !empty($in['pin']) ? '1' : '0';
        $lock = !empty($in['ip_lock']) ? '1' : '0';
        $https = array_key_exists('https_only', $in) ? (!empty($in['https_only']) ? '1' : '0') : setting('agent_https_only', '0');
        if (($pin === '0' && setting('agent_pin', '1') === '1') || ($lock === '0' && setting('agent_ip_lock', '1') === '1')
            || ($https === '0' && setting('agent_https_only', '0') === '1')) {
            $confirm('UITZETTEN');
        }
        // Alleen via https aanzetten: alleen als er geen agent zonder reserve-adres meer via http komt (anders valt hij weg)
        if ($https === '1' && setting('agent_https_only', '0') !== '1' && empty($in['force'])) {
            $plain = q("SELECT name FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_conn = 'http' AND agent_fb_at IS NULL AND (agent_https IS NULL OR agent_https <> 'off')")->fetchAll(PDO::FETCH_COLUMN);
            if ($plain) {
                out(['error' => t('Deze agents komen nog via http: {names}. Zet ze eerst over (🌐 Adres), of zet "alleen via https" per server uit.', ['names' => implode(', ', $plain)]), 'plain' => $plain], 409);
            }
        }
        // Alleen agents met een eigen sleutel (1.100.0): aan alleen als alle agents er een hebben (anders vallen ze weg)
        $ownOnly = array_key_exists('ownkey_only', $in) ? (!empty($in['ownkey_only']) ? '1' : '0') : setting('agent_ownkey_only', '0');
        if ($ownOnly === '0' && setting('agent_ownkey_only', '0') === '1') {
            $confirm('UITZETTEN');
        }
        if ($ownOnly === '1' && setting('agent_ownkey_only', '0') !== '1') {
            $nokey = q("SELECT name FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_key_seen <> 1")->fetchAll(PDO::FETCH_COLUMN);
            if ($nokey) {
                out(['error' => t('Deze agents hebben nog geen eigen sleutel: {names}. Koppel ze eerst met een koppelcode.', ['names' => implode(', ', $nokey)])], 409);
            }
        }
        foreach (['agent_key_days' => (string) $days, 'agent_pin' => $pin, 'agent_ip_lock' => $lock, 'agent_https_only' => $https, 'agent_ownkey_only' => $ownOnly] as $k => $v) {
            if (setting($k, '') !== $v) {
                save_setting($k, $v);
                AgentCommands::audit($user['username'], 'Beveiligde verbinding', "$k = $v");
            }
        }
        save_setting('agent_pin_at', '0');   // vingerafdruk opnieuw bepalen
        out(['ok' => true]);

    // Koppelcode voor een server (15 minuten geldig) en wat er nodig is voor het installatiecommando
    case 'agent_pair_code':
        $id = (int) (input()['id'] ?? 0);
        $ph = q("SELECT name, pin_mode FROM hosts WHERE id = ? AND mode = 'agent'", [$id])->fetch();
        if (!$ph) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $name = $ph['name'];
        $p = AgentCrypto::pairCreate($id);
        AgentCommands::audit($user['username'], 'Koppelcode gemaakt', (string) $name);
        // 1.130.0: nog geen vingerafdruk bekend (bv. alleen via http gekeken)? Dan nu bepalen: een commando met https moet
        // het eigen certificaat van RzSense kunnen vastzetten
        $pin = AgentCrypto::pin(false, false, $ph) ?? '';
        if ($pin === '' && AgentCrypto::pinOn($ph)) {
            $pin = AgentCrypto::pin(true, true, $ph) ?? '';
        }
        out($p + ['setup_sha256' => Stealth::installSha(), 'agent_sha256' => hash_file('sha256', SM_ROOT . '/agent/rzmon-agent.sh'),
            'pin' => $pin, 'minutes' => AgentCrypto::PAIR_MINUTES]);

    // Per server: certificaat vastzetten en vast IP-adres ('' = algemene instelling, on, off).
    // Uitzetten (waar het nu geldt) maakt de verbinding minder veilig: dan om je wachtwoord vragen.
    case 'host_conn':
        $in = input();
        $h = q("SELECT id, name, pin_mode, ip_lock, agent_https FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $new = $h;
        foreach (['pin_mode' => 'pin', 'ip_lock' => 'ip_lock', 'agent_https' => 'https'] as $col => $k) {
            if (array_key_exists($k, $in)) {
                $v = (string) $in[$k];
                if (!in_array($v, ['', 'on', 'off'], true)) {
                    out(['error' => t('Ongeldige keuze')], 422);
                }
                $new[$col] = $v === '' ? null : $v;
            }
        }
        if ((AgentCrypto::pinOn($h) && !AgentCrypto::pinOn($new)) || (AgentCrypto::ipLockOn($h) && !AgentCrypto::ipLockOn($new))
            || (AgentCrypto::httpsOnly($h) && !AgentCrypto::httpsOnly($new))) {
            $confirm('UITZETTEN');
        }
        q('UPDATE hosts SET pin_mode = ?, ip_lock = ?, agent_https = ? WHERE id = ?', [$new['pin_mode'], $new['ip_lock'], $new['agent_https'], $h['id']]);
        AgentCommands::audit($user['username'], 'Beveiligde verbinding per server', "{$h['name']}: vastzetten " . ($new['pin_mode'] ?? 'algemeen') . ', IP-slot ' . ($new['ip_lock'] ?? 'algemeen'));
        out(['ok' => true]);

    // Adressen om uit te kiezen (1.78.0): IP-adressen van RzSense (https met het eigen certificaat, of http), de namen die
    // daarbij horen (DNS), de hostnaam, eigen namen uit het certificaat en het beheeradres; elk met wat er op staat
    case 'agent_url_options':
        $path = rtrim((string) parse_url((string) config('base_url', ''), PHP_URL_PATH), '/');
        $cand = [];
        $ips = OwnCert::serverIps();
        if (($sip = Https::serverIp()) !== '' && !in_array($sip, $ips, true)) {
            array_unshift($ips, $sip);
        }
        foreach (array_slice($ips, 0, 4) as $ip) {
            $h = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$ip]" : $ip;
            $cand["https://$h$path"] = 'ip';
            $rev = @gethostbyaddr($ip);
            if (is_string($rev) && $rev !== $ip && OwnCert::validName($rev) && str_contains($rev, '.')) {
                $cand["https://$rev$path"] = 'dns';
            }
        }
        $hn = strtolower((string) gethostname());
        if (str_contains($hn, '.') && OwnCert::validName($hn)) {
            $cand["https://$hn$path"] = 'host';
        }
        foreach (OwnCert::extraNames() as $n) {
            if (!str_starts_with($n, '*') && !filter_var($n, FILTER_VALIDATE_IP)) {
                $cand['https://' . strtolower($n) . $path] = 'cert';
            }
        }
        if (Https::adminUrl() !== '') {
            $cand[Https::adminUrl()] = 'admin';
        }
        foreach (array_slice($ips, 0, 4) as $ip) {
            $cand['http://' . (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$ip]" : $ip) . $path] = 'ip';
        }
        $list = [];
        foreach (array_slice($cand, 0, 12, true) as $u => $src) {
            $list[] = ['url' => $u, 'source' => $src] + AgentCrypto::urlTrust($u);
        }
        out(['options' => $list, 'pinurl_min' => AgentCrypto::PINURL_MIN, 'url_min' => AgentCrypto::URL_MIN]);

    // Een adres testen (vanaf RzSense): welk certificaat staat erop?
    case 'agent_url_test':
        $u = rtrim(trim((string) (input()['url'] ?? '')), '/');
        out(['url' => $u] + AgentCrypto::urlTrust($u, true));

    // Adres van RzSense voor een agent (of alle agents) wijzigen: de agent test het nieuwe adres eerst (1.72.0)
    case 'agent_url_set':
        $in = input();
        $url = rtrim(trim((string) ($in['url'] ?? '')), '/');
        $fb = rtrim(trim((string) ($in['fallback'] ?? '')), '/');
        if (!AgentCrypto::validUrl($url)) {
            out(['error' => t('Ongeldig adres: gebruik https://naam of http://adres')], 422);
        }
        if ($fb !== '' && (!AgentCrypto::validUrl($fb) || $fb === $url)) {
            out(['error' => t('Ongeldig reserve-adres (leeg laten mag)')], 422);
        }
        // Wat staat er op die adressen (1.78.0)? Een onbekend certificaat: weigeren. Eigen certificaat: agent 1.53.0 nodig.
        $own = [];
        foreach (array_filter([$url, $fb]) as $u) {
            $tr = AgentCrypto::urlTrust($u, true);
            if ($tr['kind'] === 'other') {
                out(['error' => t('Op {url} staat een onbekend certificaat (niet van RzSense en niet vertrouwd); de agents zouden het weigeren', ['url' => $u])], 422);
            }
            if ($tr['kind'] === 'own') {
                $own[] = $u;
            }
        }
        $all = ($in['id'] ?? '') === 'all';
        $rows = q("SELECT id, name, agent_version, agent_url, agent_url_fb FROM hosts WHERE mode = 'agent'" . ($all ? '' : ' AND id = ?'), $all ? [] : [(int) ($in['id'] ?? 0)])->fetchAll();
        $n = 0;
        $skip = [];
        $min = $own ? AgentCrypto::PINURL_MIN : AgentCrypto::URL_MIN;
        foreach ($rows as $r) {
            if (!$r['agent_version'] || version_compare($r['agent_version'], $min, '<')) {
                $skip[] = $r['name'];
                continue;
            }
            // Reserve-adres: opgegeven, anders (met "keepfb") het adres dat de agent nu gebruikt
            $f = $fb !== '' ? $fb : (!empty($in['keep_fb']) && $r['agent_url'] && $r['agent_url'] !== $url ? $r['agent_url'] : '');
            q('UPDATE hosts SET agent_url_want = ?, agent_url_want_fb = ?, agent_url_status = ?, agent_url_at = ? WHERE id = ?',
                [$url, $f, '⏳ ' . t('Wacht op de agent (test {url})', ['url' => $url]), now(), $r['id']]);
            $n++;
        }
        if (!$n) {
            out(['error' => t('Agent {v} of nieuwer nodig (eerst bijwerken)', ['v' => $min])], 422);
        }
        AgentCommands::audit($user['username'], 'Adres van RzSense voor agents', ($all ? 'alle' : $rows[0]['name']) . ": $url" . ($fb !== '' ? " (reserve $fb)" : ''));
        out(['ok' => true, 'n' => $n, 'skipped' => $skip]);

    case 'agent_url_cancel':
        q('UPDATE hosts SET agent_url_want = NULL, agent_url_want_fb = NULL, agent_url_status = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'agent_key_renew':
        $id = (int) (input()['id'] ?? 0);
        $h = q("SELECT name, agent_key FROM hosts WHERE id = ? AND mode = 'agent'", [$id])->fetch();
        if (!$h || !$h['agent_key']) {
            out(['error' => t('Deze server heeft nog geen eigen sleutel')], 409);
        }
        AgentCrypto::newHostKey($id, true);
        AgentCommands::audit($user['username'], 'Sleutel van de agent vernieuwd', $h['name'] . ' (de vorige nog 24 uur geldig)');
        out(['ok' => true]);

    case 'agent_ip_allow':
        $ip = AgentCrypto::ipAllow((int) (input()['id'] ?? 0), $user['username']);
        $ip !== null ? out(['ok' => true, 'ip' => $ip]) : out(['error' => t('Er wacht geen nieuw IP-adres')], 409);

    case 'agent_ip_reset':
        $id = (int) (input()['id'] ?? 0);
        q('UPDATE hosts SET agent_ips = NULL, agent_ip_new = NULL, agent_ip_new_at = NULL WHERE id = ?', [$id]);
        AgentCommands::audit($user['username'], 'IP-adressen van de agent vergeten', (string) $id);
        out(['ok' => true]);

    // ---------- Certificaat van RzSense voor de agents (1.70.0): bekijken, downloaden, vernieuwen ----------
    case 'agent_cert':
        $base = rtrim((string) config('base_url', ''), '/');
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        $local = OwnCert::current();
        unset($local['pem']);
        $agentCert = null;
        if ($scheme === 'https') {
            $h = (string) parse_url($base, PHP_URL_HOST);
            $agentCert = Https::fetchCert($h, (int) (parse_url($base, PHP_URL_PORT) ?: 443), $h, true);
        }
        // De vingerafdruk die de agents vastzetten: alleen bij https met een certificaat dat niet al vertrouwd is
        $pin = $agentCert && $agentCert['ok'] && !$agentCert['trusted'] && !empty($agentCert['pem']) ? OwnCert::spkiPin($agentCert['pem']) : '';
        unset($agentCert['pem']);
        out([
            'base' => $base, 'scheme' => $scheme ?: null, 'agent_cert' => $agentCert, 'local' => $local,
            'own' => $agentCert && !empty($local['fp']) && $agentCert['ok'] && $agentCert['fp'] === $local['fp'],
            'pin_on' => setting('agent_pin', '1') === '1', 'pin' => $pin,
            'hosts' => q("SELECT id, name, agent_version, agent_seen, pin_mode, agent_pin_sent, agent_pin_sent_at, agent_url, agent_url_pin, agent_url_fb_pin FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll(),
            'paths' => OwnCert::paths(), 'via' => OwnCert::via(), 'cli' => OwnCert::cliCommand(),
            'names' => OwnCert::names(), 'extra' => implode("\n", OwnCert::extraNames()), 'days' => OwnCert::DAYS, 'warn_days' => Https::WARN_DAYS,
            'next' => OwnCert::next(), 'pinned' => OwnCert::pinnedAgents(), 'next_days' => OwnCert::NEXT_DAYS,
        ]);

    case 'agent_cert_names':
        $list = [];
        foreach (parse_list((string) (input()['names'] ?? '')) as $n) {
            if (!OwnCert::validName($n)) {
                out(['error' => t('Geen geldige naam of IP-adres: {n}', ['n' => mb_substr($n, 0, 100)])], 422);
            }
            $list[strtolower($n)] = true;
        }
        save_setting('cert_extra_names', implode("\n", array_keys(array_slice($list, 0, 30, true))));
        out(['ok' => true]);

    case 'agent_cert_download':
        $confirm('DOWNLOADEN');
        $c = OwnCert::current();
        if (empty($c['pem'])) {
            out(['error' => t('Geen certificaat op poort 443 van RzSense')], 404);
        }
        AgentCommands::audit($user['username'], 'Certificaat van RzSense gedownload', $c['fp'] ?? '');
        out(['ok' => true, 'pem' => $c['pem'], 'name' => (preg_replace('/[^a-z0-9.-]/i', '_', (string) gethostname()) ?: 'rzmon') . '.crt']);

    // Vernieuwen (1.78.0): met vastgezette agents eerst klaarzetten (prepare), anders meteen (renew). "Nu wisselen":
    // activate (het klaargezette certificaat) of now (meteen een nieuw, zonder te wachten op de agents).
    case 'agent_cert_renew':
    case 'agent_cert_activate':
        $in = input();
        $confirm($action === 'agent_cert_activate' ? 'WISSELEN' : 'VERNIEUWEN');
        $what = $action === 'agent_cert_activate' ? 'activate' : (!empty($in['now']) || !OwnCert::pinnedAgents() ? 'renew' : 'prepare');
        $via = OwnCert::via();
        if ($via === 'helper') {
            // Via de hulpdienst (als root): verzoek neerzetten, het resultaat komt binnen een paar seconden
            $h = Installer::HELPER_DIR;
            @unlink("$h/cert.result");
            $nonce = bin2hex(random_bytes(16));
            if (file_put_contents("$h/cert.request", json_encode(['nonce' => $nonce, 'by' => $user['username'], 'action' => $what])) === false) {
                out(['error' => t('Kan geen verzoek neerzetten in {dir}', ['dir' => $h])], 500);
            }
            $_SESSION['cert_renew'] = ['nonce' => $nonce, 'at' => time()];
            out(['ok' => true, 'pending' => true]);
        }
        if ($via === 'direct') {
            $r = $what === 'activate' ? OwnCert::activate($user['username']) : ($what === 'prepare' ? OwnCert::prepare($user['username']) : OwnCert::renew($user['username']));
            out($r, $r['ok'] ? 200 : 422);
        }
        $cmd = OwnCert::cliCommand() . ($what === 'activate' ? ' --activate' : ($what === 'renew' ? ' --now' : ''));
        out(['error' => t('Vernieuwen kan hier alleen als root. Voer op de server uit: {cmd}', ['cmd' => $cmd]), 'cli' => $cmd], 409);

    case 'agent_cert_cancel':
        if (OwnCert::next()) {
            save_setting('own_cert_next', '');
            AgentCommands::audit($user['username'], 'Klaargezet certificaat van RzSense geannuleerd', '');
        }
        out(['ok' => true]);

    case 'agent_cert_renew_status':
        $p = $_SESSION['cert_renew'] ?? null;
        if (!$p) {
            out(['error' => t('Er wordt geen certificaat vernieuwd')], 404);
        }
        $res = json_decode((string) @file_get_contents(Installer::HELPER_DIR . '/cert.result'), true);
        if ($res && hash_equals($p['nonce'], (string) ($res['nonce'] ?? ''))) {
            @unlink(Installer::HELPER_DIR . '/cert.result');
            unset($_SESSION['cert_renew'], $res['nonce']);
            out($res, $res['ok'] ? 200 : 422);
        }
        if (time() - $p['at'] > 45) {
            unset($_SESSION['cert_renew']);
            @unlink(Installer::HELPER_DIR . '/cert.request');
            out(['error' => t('De hulpdienst reageert niet. Controleer op de server: systemctl status rzmon-cert.path')], 504);
        }
        out(['pending' => true]);

    case 'block_add':
        $in = input();
        $r = Security::block((string) ($in['net'] ?? ''), (string) ($in['reason'] ?? ''), $user['username']);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 422);

    case 'block_remove':
        Security::unblock((int) (input()['id'] ?? 0), $user['username']);
        out(['ok' => true]);

    // Blokkades en whitelist (1.83.0)
    case 'blocks_overview':
        out(Security::overview());

    case 'block_lift':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $ok = match ((string) ($in['src'] ?? '')) {
            'rz' => Security::unblock($id, $user['username']),
            'user' => Security::unlockUser($id, $user['username']),
            'ids' => (static function () use ($id, $user): bool {
                $b = Ids::unblock($id, $user['username']);
                $b && AgentCommands::audit($user['username'], 'IDS: gedeblokkeerd', "{$b['ip']} op server {$b['host_id']}");
                return (bool) $b;
            })(),
            default => false,
        };
        $ok ? out(['ok' => true]) : out(['error' => t('Blokkade niet gevonden')], 404);

    case 'whitelist_add':
        $in = input();
        $confirm('WHITELIST');
        $r = Whitelist::add((string) ($in['kind'] ?? ''), (string) ($in['value'] ?? ''), (int) ($in['days'] ?? 0), (string) ($in['reason'] ?? ''), $user['username']);
        isset($r['error']) ? out($r, 422) : out($r);

    case 'whitelist_remove':
        Whitelist::remove((int) (input()['id'] ?? 0), $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'host_info_agent':
        $in = input();
        q('UPDATE hosts SET info_agent = ? WHERE id = ?', [!empty($in['on']) ? 1 : 0, (int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], 'Info bij agent-updates', (!empty($in['on']) ? 'aan' : 'uit') . ' voor server ' . (int) ($in['id'] ?? 0));
        out(['ok' => true]);

    // Hoe vaak de agent van deze server rapporteert (0 = algemene instelling)
    case 'host_tick':
        $in = input();
        $t = (int) ($in['tick'] ?? 0);
        if ($t !== 0 && !in_array($t, AGENT_TICKS, true)) {
            out(['error' => t('Kies 30, 60, 120 of 300 seconden')], 422);
        }
        q('UPDATE hosts SET agent_tick = ? WHERE id = ?', [$t ?: null, (int) ($in['id'] ?? 0)]);
        out(['ok' => true, 'tick' => agent_tick($t ?: null)]);

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
                if ($h['agent_version'] === null || version_compare($h['agent_version'], AGENT_LATEST, '<')) {
                    $ids[] = (int) $h['id'];
                }
            }
        } else {
            $ids = q("SELECT id FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['id'] ?? 0)])->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                out(['error' => t('Geen agent-server gevonden')], 404);
            }
        }
        foreach ($ids as $id) {
            q("UPDATE hosts SET agent_update_req = ?, agent_update_sent = NULL, agent_update_status = '⏳ Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport' WHERE id = ?", [now(), $id]);
        }
        out(['ok' => true, 'count' => count($ids)]);

    // 🧹 Opruimen (1.100.0): één server (id) of alle agents. Tokens en (bij alle) de algemene sleutel wissen, agents opnieuw
    // laten installeren met een reservekopie en opgeruimde instellingen
    case 'agents_cleanup':
        $in = input();
        $hid = !empty($in['id']) ? (int) $in['id'] : null;
        if ($hid === null) {
            $confirm('OPRUIMEN');
        }
        $r = AgentAudit::cleanup($hid);
        AgentCommands::audit($user['username'], 'Agents opruimen', ($hid ? "server $hid" : 'alle') . ': ' . json_encode($r));
        ChangeLog::record('system', $hid, 'info', t('Agents opgeruimd'), null, $r);
        out(['ok' => true] + $r);

    case 'agent_update_cancel':
        q('UPDATE hosts SET agent_update_req = NULL, agent_update_sent = NULL, agent_update_status = NULL WHERE id = ?', [(int) (input()['id'] ?? 0)]);
        out(['ok' => true]);

    case 'agent_status':
        $id = (int) ($_GET['id'] ?? 0);
        $h = q('SELECT agent_seen, agent_busy, agent_ip, agent_version, mode, agent_update_req, agent_update_status, agent_update_via, realtime, watchdog, watchdog_min, enc_seen FROM hosts WHERE id = ?', [$id])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        // Geweigerde pogingen (laatste 24 uur, en ná het laatste geslaagde rapport): voor deze
        // server of met een onbekend token. Gelijke pogingen samengevoegd met een aantal.
        $fails = q('SELECT ip, reason, MAX(created_at) AS created_at, COUNT(*) AS n FROM agent_log
                    WHERE (host_id = ? OR host_id IS NULL) AND created_at >= NOW() - INTERVAL 1 DAY
                      AND (? IS NULL OR created_at > ?)
                    GROUP BY ip, reason ORDER BY MAX(id) DESC LIMIT 6', [$id, $h['agent_seen'], $h['agent_seen']])->fetchAll();
        out(['seen' => $h['agent_seen'], 'ip' => $h['agent_ip'], 'mode' => $h['mode'], 'failures' => $fails, 'now' => now(),
             'agent_version' => $h['agent_version'], 'version' => AGENT_LATEST,
             'update' => ['pending' => $h['agent_update_req'] !== null, 'status' => $h['agent_update_status'], 'via' => $h['agent_update_via']],
             'audit' => AgentAudit::items(q('SELECT ' . AgentAudit::HOST_COLS . ' FROM hosts WHERE id = ?', [$id])->fetch() ?: [], AgentAudit::cfg($id)),
             'report_warn' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'report_warn'", [$id])->fetchColumn(), true),
             // 1.137.0: duur van de laatste run per onderdeel, wat de agent kan, en of hij nu bezig is (hartslag)
             'timing' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'timing'", [$id])->fetchColumn(), true),
             'caps' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'caps'", [$id])->fetchColumn(), true) ?: [],
             'busy' => $h['agent_busy'],
             'update_sig' => q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'update_sig'", [$id])->fetchColumn() ?: null,
             'realtime' => ['on' => (bool) $h['realtime'], 'status' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'realtime'", [$id])->fetchColumn(), true),
                 'tools' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'tools'", [$id])->fetchColumn(), true)],
             'watchdog' => ['on' => (bool) $h['watchdog'], 'min' => (int) ($h['watchdog_min'] ?: 5), 'enc' => (bool) $h['enc_seen'],
                 'pushover' => Push::names() !== '', 'push' => Push::names()],
             'heal_cmds' => json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'heal_cmds'", [$id])->fetchColumn(), true) ?: []]);

    case 'path_info':
        // 1.177.0: popup van een pad-check
        $pi = Paths::info((int) ($_GET['id'] ?? 0));
        $pi === null ? out(['error' => t('Deze check bestaat niet meer')], 404) : out($pi);

    case 'paths':
        // 1.177.0: Monitoring → Netwerkpaden
        out(Paths::overview());

    case 'path_tp_now':
        // 1.177.0: doorvoertest nu (bij de volgende ronde van de agents)
        $pc = q("SELECT id, host_id FROM checks WHERE id = ? AND type = 'path'", [(int) (input()['id'] ?? 0)])->fetch();
        if (!$pc) {
            out(['error' => t('Deze check bestaat niet meer')], 404);
        }
        $rq = Paths::request((int) $pc['id'], (int) $pc['host_id']);
        out($rq ? ['ok' => true, 'to' => $rq['name']] : ['error' => t('Geen apparaat om naartoe te meten: zet bij een andere server met de agent en iperf3 "Mag doorvoertest ontvangen" aan')], $rq ? 200 : 422);

    case 'containers':
        // 1.176.0: Monitoring → Containers: alle containers van alle servers (Podman en Docker), met hun status en check
        out(Containers::overview());

    case 'podman':
        // Overzicht van alle containers die de agent van deze server meldt
        $id = (int) ($_GET['id'] ?? 0);
        $sum = q("SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = 'podman'", [$id])->fetch();
        if (!$sum) {
            out(['error' => t('Deze server meldt (nog) geen Podman')], 404);
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
        ActionLog::by((string) ($user['username'] ?? ''), (int) (input()['id'] ?? 0), 'agent', t('Nieuw token gemaakt'), t('het oude token werkt niet meer'));
        out(['ok' => true]);

    // 1.226.0: logboek per server (acties en wijzigingen); host_id 0 = alle servers
    case 'actlog':
    case 'actlog_csv':
        $f = ['host_id' => (int) ($_GET['host_id'] ?? 0), 'module' => preg_replace('/[^a-z]/', '', (string) ($_GET['module'] ?? '')),
            'source' => in_array($_GET['source'] ?? '', ActionLog::SOURCES, true) ? (string) $_GET['source'] : '', 'days' => (int) ($_GET['days'] ?? 30),
            'q' => (string) ($_GET['q'] ?? ''), 'ok' => (string) ($_GET['ok'] ?? ''), 'limit' => $action === 'actlog_csv' ? 1000 : (int) ($_GET['limit'] ?? 300)];
        $rows = ActionLog::list($f);
        if ($action === 'actlog_csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="rzsense-logboek-' . date('Ymd-His') . '.csv"');
            echo "\xEF\xBB\xBF" . ActionLog::csv($rows);
            exit;
        }
        out(['items' => $rows, 'days' => ActionLog::days(), 'fails' => ActionLog::failsPerHost(), 'modules' => ActionLog::MODULES]);

    case 'actlog_days':
        $d = (int) (input()['days'] ?? 365);
        save_setting('actlog_days', (string) (in_array($d, [30, 90, 180, 365, 730, 1095, 3650], true) ? $d : 365));
        save_setting('changelog_days', (string) ActionLog::days());   // 1.235.0: één bewaartermijn voor het hele Logboek (ook wijzigingen)
        out(['ok' => true, 'days' => ActionLog::days()]);

    case 'host_delete':
        $id = (int) (input()['id'] ?? 0);
        // Afhankelijke servers hangen daarna aan de parent van de verwijderde server
        $grand = q('SELECT parent_id FROM hosts WHERE id = ?', [$id])->fetchColumn() ?: null;
        q('UPDATE hosts SET parent_id = ? WHERE parent_id = ?', [$grand, $id]);
        q('UPDATE checks SET suppressed_by = NULL WHERE suppressed_by = ?', [$id]);
        if ($row = ChangeLog::hostRow($id, true)) {
            ChangeLog::record('host', $id, 'delete', (string) $row['name'], $row, null);
        }
        q('DELETE FROM hosts WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'ack':
        $in = input();
        if (!Monitor::acknowledge((int) ($in['id'] ?? 0), $user['username'], (string) ($in['comment'] ?? ''))) {
            out(['error' => t('Deze check heeft geen storing (meer)')], 422);
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

    // 1.210.0: logbronnen per server (Agent Manager) en het overzicht (beheerportal)
    case 'log_sources':
        $hid = (int) ($_GET['host_id'] ?? 0);
        out(['sources' => LogStore::sources($hid), 'interval' => q('SELECT logs_interval FROM hosts WHERE id = ?', [$hid])->fetchColumn() ?: null,
            'default_interval' => (int) setting('logs_interval', '5'), 'on' => LogStore::enabled(), 'day_limit' => LogStore::dayLimit(), 'used_today' => LogStore::usedToday($hid)]);

    case 'log_source_lines':
        out(['lines' => LogStore::lastLines((int) ($_GET['id'] ?? 0))]);

    // 1.213.0: logs die de agent op de server vond, om uit te kiezen; opnieuw zoeken; meerdere tegelijk toevoegen
    case 'log_found':
        out(LogStore::found((int) ($_GET['host_id'] ?? 0)));

    case 'log_scan':
        q("UPDATE hosts SET logscan_req = logscan_req + 1 WHERE id = ? AND mode = 'agent'", [(int) (input()['host_id'] ?? 0)]);
        out(['ok' => true]);

    case 'log_found_add':
        $in = input();
        $hid = (int) ($in['host_id'] ?? 0);
        if (!q("SELECT 1 FROM hosts WHERE id = ? AND mode = 'agent'", [$hid])->fetchColumn()) {
            out(['error' => t('Logs verzamelen kan alleen op een server met de agent')], 422);
        }
        $added = 0;
        $errors = [];
        foreach (array_slice((array) ($in['items'] ?? []), 0, 50) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $kind = (string) ($it['kind'] ?? '');
            $spec = (string) ($it['spec'] ?? '');
            if (q('SELECT 1 FROM log_sources WHERE host_id = ? AND kind = ? AND spec = ?', [$hid, $kind, $kind === 'journal' ? '' : $spec])->fetchColumn()) {
                continue;   // al verzameld
            }
            $r = LogStore::save($hid, ['kind' => $kind, 'spec' => $spec, 'name' => mb_substr((string) ($it['name'] ?? ''), 0, 100)]);
            if (is_string($r)) {
                $errors[] = $spec . ': ' . $r;
            } else {
                $added++;
            }
        }
        if ($added) {
            AgentCommands::audit((string) $user['username'], 'Logbronnen toegevoegd (gevonden)', q('SELECT name FROM hosts WHERE id = ?', [$hid])->fetchColumn() . ': ' . $added);
        }
        out(['ok' => true, 'added' => $added, 'errors' => $errors]);

    case 'log_source_save':
        $in = input();
        $hid = (int) ($in['host_id'] ?? 0);
        if (!q("SELECT 1 FROM hosts WHERE id = ? AND mode = 'agent'", [$hid])->fetchColumn()) {
            out(['error' => t('Logs verzamelen kan alleen op een server met de agent')], 422);
        }
        $r = LogStore::save($hid, $in);
        if (is_string($r)) {
            out(['error' => $r], 422);
        }
        AgentCommands::audit((string) $user['username'], 'Logbron opgeslagen', q('SELECT name FROM hosts WHERE id = ?', [$hid])->fetchColumn() . ': ' . ($in['kind'] ?? '') . ' ' . ($in['spec'] ?? ''));
        out(['ok' => true, 'id' => $r]);

    case 'log_source_toggle':
        $in = input();
        LogStore::setEnabled((int) ($in['id'] ?? 0), !empty($in['on']));
        out(['ok' => true]);

    case 'log_source_delete':
        LogStore::delete((int) (input()['id'] ?? 0));
        out(['ok' => true]);

    case 'host_logs_interval':
        $in = input();
        $m = $in['minutes'] ?? null;
        q('UPDATE hosts SET logs_interval = ? WHERE id = ?', [$m === null || $m === '' ? null : max(1, min(60, (int) $m)), (int) ($in['id'] ?? 0)]);
        out(['ok' => true]);

    case 'log_overview':
        out(LogStore::overview());

    // 1.212.0: vaste zoekacties die een melding geven (beheerder en gebruiker)
    case 'log_watches':
        out(['watches' => LogWatch::all(), 'max' => LogWatch::MAX, 'windows' => LogWatch::WINDOWS, 'windows_none' => LogWatch::WINDOWS_NONE, 'rests' => LogWatch::RESTS]);

    case 'log_watch_save':
        $in = input();
        $r = LogWatch::save(!empty($in['id']) ? (int) $in['id'] : null, $in, (string) $user['username']);
        if (is_string($r)) {
            out(['error' => $r], 422);
        }
        AgentCommands::audit((string) $user['username'], empty($in['id']) ? 'Vaste zoekactie gemaakt' : 'Vaste zoekactie gewijzigd', mb_substr((string) ($in['name'] ?? ''), 0, 100) . ': ' . mb_substr((string) ($in['q'] ?? ''), 0, 200));
        out(['ok' => true, 'id' => $r]);

    case 'log_watch_toggle':
        $in = input();
        LogWatch::setEnabled((int) ($in['id'] ?? 0), !empty($in['on']));
        out(['ok' => true]);

    case 'log_watch_delete':
        $in = input();
        $name = q('SELECT name FROM log_watches WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn();
        LogWatch::delete((int) ($in['id'] ?? 0));
        AgentCommands::audit((string) $user['username'], 'Vaste zoekactie verwijderd', (string) $name);
        out(['ok' => true]);

    case 'log_watch_test':
        $r = LogWatch::simulate(input());
        out($r, isset($r['error']) ? 422 : 200);

    // 1.211.0: zoeken in de logs (beheerder en gebruiker)
    case 'log_search':
    case 'log_export':
        $in = $_GET;
        $r = LogStore::search(['q' => (string) ($in['q'] ?? ''), 'from' => (int) ($in['from'] ?? 0), 'to' => (int) ($in['to'] ?? 0),
            'hosts' => array_filter(array_map('intval', explode(',', (string) ($in['hosts'] ?? '')))),
            'sources' => array_filter(array_map('intval', explode(',', (string) ($in['sources'] ?? '')))),
            'level' => ($in['level'] ?? '') === '' ? null : (int) $in['level'], 'limit' => $action === 'log_export' ? 5000 : (int) ($in['limit'] ?? 100)]);
        if (isset($r['error'])) {
            out($r, 422);
        }
        if ($action === 'log_search') {
            out($r);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rzmon-logs-' . date('Y-m-d-His') . '.csv"');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");   // BOM: Excel leest het dan als UTF-8
        fputcsv($fh, [t('Tijd'), 'Server', t('Bron'), t('Niveau'), t('Regel')], ';', '"', '\\');
        // 1.224.1: een logregel kan van een aanvaller komen: begint een cel met = + - @ (of tab/return), dan een ' ervoor,
        // anders voert een spreadsheet hem uit als formule
        $cell = static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
        foreach ($r['hits'] as $h) {
            fputcsv($fh, array_map($cell, [$h['time'], $h['host'], $h['source'], ['debug', 'info', 'warning', 'error', 'critical'][$h['level']] ?? '', $h['msg']]), ';', '"', '\\');
        }
        fclose($fh);
        exit;

    case 'log_context':
        $c = LogStore::context((string) ($_GET['ref'] ?? ''));
        if ($c === null) {
            out(['error' => t('Deze regel bestaat niet meer (verlopen?)')], 404);
        }
        out($c);

    case 'log_facets':
        out(['sources' => LogStore::facets(), 'days' => LogStore::days()]);

    // 1.208.0: een open storing is bekeken op de meldingenpagina; de golf stopt voor iedereen (en komt terug als het erger wordt)
    case 'alert_seen':
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) (input()['ids'] ?? []))))), 0, 200);
        if ($ids) {
            q('UPDATE alerts SET seen_at = NOW(), seen_by = ? WHERE seen_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
                array_merge([(string) $user['username']], $ids));
        }
        out(['ok' => true, 'by' => (string) $user['username'], 'at' => date('Y-m-d H:i:s')]);

    case 'maintenance_set':
        // scope: all | host | check ; minutes = 0 beëindigt het onderhoud
        $in = input();
        $scope = (string) ($in['scope'] ?? '');
        $minutes = (int) ($in['minutes'] ?? 0);
        if (!empty($in['until'])) {
            $ts = strtotime((string) $in['until']);
            if (!$ts || $ts <= time()) {
                out(['error' => t('Kies een eindtijd in de toekomst')], 422);
            }
            $minutes = (int) ceil(($ts - time()) / 60);
        }
        if ($minutes < 0 || $minutes > 60 * 24 * 90) {
            out(['error' => t('Onderhoud kan maximaal 90 dagen duren')], 422);
        }
        $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        $reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 200) ?: null;
        $id = (int) ($in['id'] ?? 0);
        if ($scope === 'all') {
            save_setting('maintenance_until', (string) $until);
            save_setting('maintenance_reason', (string) $reason);
        } elseif ($scope === 'host') {
            q('UPDATE hosts SET maintenance_until = ?, maintenance_reason = ? WHERE id = ?', [$until, $reason, $id]);
        } elseif ($scope === 'check') {
            // Handmatig ingesteld of beëindigd: niet (meer) automatisch
            q('UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 0 WHERE id = ?', [$until, $reason, $id]);
        } else {
            out(['error' => t('Onbekend bereik')], 422);
        }
        out(['ok' => true, 'until' => $until]);

    case 'check_save':
        $in = input();
        [$cols, $hbNew] = $checkCols($in);
        $checkBefore = !empty($in['id']) ? ChangeLog::checkRow((int) $in['id']) : null;
        if (!empty($in['id'])) {
            // Uit een sjabloon (1.94.0): met de hand aangepast = los van het sjabloon, tot "terug naar het sjabloon"
            Templates::updateCheck((int) $in['id'], $cols, true);
            $id = (int) $in['id'];
        } else {
            $id = Templates::insertCheck((int) ($in['host_id'] ?? 0), $cols);
        }
        Smart::saveForm($id, $in);   // 1.107.0: drempelmodus, vangnet en gevoeligheid
        if (array_key_exists('heal', $in)) {   // 1.107.0: zelf herstellen (alleen op een server met de agent)
            $hc = q('SELECT c.type, h.mode FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
            q('UPDATE checks SET heal = ?, heal_state = NULL WHERE id = ?', [$hc && $hc['mode'] === 'agent' ? Heal::clean($hc, $in['heal']) : null, $id]);
        }
        if ($cols['type'] === 'heartbeat') {
            // Geheime URL: bij het aanmaken, of nieuw op verzoek (de oude werkt dan meteen niet meer)
            q('UPDATE checks SET hb_token = ? WHERE id = ? AND (hb_token IS NULL OR ?)', [Heartbeat::newToken(), $id, $hbNew ? 1 : 0]);
        }
        $checkAfter = ChangeLog::checkRow($id);
        ChangeLog::record('check', $id, $checkBefore ? 'update' : 'create', ($checkAfter['_host'] ?? '') . ' · ' . $cols['name'], $checkBefore, $checkAfter);
        Monitor::runCheckById($id);
        out(['ok' => true, 'id' => $id]);
}
