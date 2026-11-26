<?php
declare(strict_types=1);

/**
 * 1.333.0: de controles van Instellingen → Status en functies (meldkanaal, back-up, 2FA, agents, https …). Stond in de
 * API; nu een eigen klasse, zodat ook het blok Advies op het dashboard ze kan tonen.
 */
final class SetHealth
{
    /**
     * key, status (ok/info/warn/bad), title, detail, section (de kaart in de beheerportal) en soms act + label of code
     * @return list<array<string, mixed>>
     */
    public static function items(): array
    {
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
        // 1.271.0: RzSense van buiten gebruikt, maar de proxy laat het alleen vanaf mijn netwerk toe
        if (($pc = ProxyOpen::selfCheck()) !== null && $pc['status'] === 'warn') {
            $add('proxy_self', 'warn', t('RzSense van buiten'), $pc['text'], 'proxy');
        }
        $locks = setting('login_lock_account', '1') === '1' || setting('login_lock_ip', '1') === '1';
        $add('lock', $locks ? 'ok' : 'warn', t('Inlogbeveiliging'), $locks
            ? t('blokkeren na {n} mislukte pogingen', ['n' => (int) setting('login_lock_fails', '3')]) : t('staat uit: wachtwoorden raden wordt niet afgeremd'), 'access');
        $cache = json_decode((string) setting('update_cache', ''), true) ?: [];
        $newer = !empty($cache['latest']) && version_compare($cache['latest'], RZMON_VERSION, '>');
        $add('update', $newer ? 'warn' : 'ok', t('RzSense-versie'), $newer
            ? t('{v} staat klaar (je hebt {cur})', ['v' => $cache['latest'], 'cur' => RZMON_VERSION]) : t('{cur} is de nieuwste', ['cur' => RZMON_VERSION]), 'update');
        $agents = q("SELECT name, os, agent_version, enc, enc_seen, agent_key_seen, agent_ip_new FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen IS NOT NULL")->fetchAll();
        if ($agents) {
            $old = array_column(array_filter($agents, static fn($h) => $h['agent_version'] && version_compare($h['agent_version'], agent_latest($h['os']), '<')), 'name');
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
            $sg['nosodium'] ? t('RzSense kan de handtekening niet controleren: de PHP-extensie sodium ontbreekt. Rocky: sudo dnf install epel-release, daarna sudo dnf install php-sodium en sudo systemctl restart php-fpm (of draai install-rocky.sh opnieuw). De agents controleren hem zelf wel.')
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
        return $items;
    }
}
