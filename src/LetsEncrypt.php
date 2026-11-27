<?php
declare(strict_types=1);

/**
 * Let's Encrypt-certificaat voor het beheeradres (Instellingen → Toegang tot RzSense → Beheer van buiten → 🔒 Let's Encrypt).
 *
 * Aanvragen gaat met certbot (EPEL op Rocky, Debian in de container) via HTTP-01 met --webroot: Let's Encrypt haalt een
 * controlebestand op via http://<domein>/.well-known/acme-challenge/…, dat de webserver uit WEBROOT geeft. Certbot
 * verandert zelf niets aan de webserver; dat doet RzSense hier, met een test vooraf en terugzetten als het misgaat.
 *
 * Koppelen: een eigen virtuele host op de domeinnaam (SNI), náást het eigen certificaat van RzSense:
 * - gewone installatie (install-rocky.sh, Apache): zz-rzmon-letsencrypt.conf, een kopie van het 443-blok van rzmon.conf
 *   met de domeinnaam en het Let's Encrypt-certificaat. De naam begint met zz, zodat rzmon.conf de standaard op poort
 *   443 blijft: agents die via het IP-adres verbinden (zonder SNI) zien zo nog steeds het eigen, vastgezette certificaat;
 * - container: een eigen https-poort in nginx (RZMON_TLS_PORT, standaard 9443; de firewall van de host stuurt 443 daarheen).
 *
 * Alles wat root nodig heeft (certbot, Apache) gaat via de hulpdienst: de webpagina zet alleen een verzoek neer
 * (acme.request); systemd start dan als root `php install/install.php --acme --helper`, dat alles opnieuw controleert.
 * In de container draait RzSense zonder root en doet het dat zelf, als www-data, op de achtergrond.
 * Vernieuwen: elke dag vraagt RzSense certbot om te vernieuwen (certbot doet pas iets 30 dagen voor het verloopt).
 */
final class LetsEncrypt
{
    public const WEBROOT = Installer::HELPER_DIR . '/acme';
    public const APACHE_MAIN = '/etc/httpd/conf.d/rzmon.conf';
    public const APACHE_LE = '/etc/httpd/conf.d/zz-rzmon-letsencrypt.conf';
    public const NGINX_LE = '/tmp/rzsense-le.conf';
    public const NGINX_MAIN = '/tmp/rzsense-nginx.conf';
    public const TLS_PORT = 9443;           // container: standaard https-poort voor Let's Encrypt
    public const RENEW_DAYS = 30;           // certbot vernieuwt vanaf zoveel dagen voor het verloopt
    private const HB = 15;                  // het statusbestand wordt minstens zo vaak bijgewerkt terwijl het loopt
    private const ACTIONS = ['test', 'issue', 'renew', 'force', 'remove', 'deploy'];

    // ------------------------------------------------------------------ waar en hoe

    /** container | apache | none */
    public static function mode(): string
    {
        if (rz_container()) {
            return 'container';
        }
        return is_file(self::APACHE_MAIN) ? 'apache' : 'none';
    }

    /** Hoe het hier kan: direct (container), helper (hulpdienst als root), cli (alleen in de terminal), none */
    public static function via(): string
    {
        $m = self::mode();
        if ($m === 'container') {
            return 'direct';
        }
        if ($m === 'none') {
            return 'none';
        }
        $h = Installer::HELPER_DIR;
        return is_file("$h/acme.installed") && is_writable($h) ? 'helper' : 'cli';
    }

    public static function cliCommand(string $action = 'issue', string $domain = ''): string
    {
        return 'sudo php ' . (realpath(SM_ROOT) ?: SM_ROOT) . '/install/install.php --acme ' . $action . ($domain !== '' ? ' ' . $domain : '');
    }

    /** De poort waarop https met het Let's Encrypt-certificaat binnenkomt (op de server zelf) */
    public static function tlsPort(): int
    {
        if (self::mode() !== 'container') {
            return 443;
        }
        $p = (int) (getenv('RZMON_TLS_PORT') ?: self::TLS_PORT);
        return $p > 0 && $p < 65536 ? $p : self::TLS_PORT;
    }

    /** De poort waarop http binnenkomt (op de server zelf): daar haalt Let's Encrypt het controlebestand */
    public static function httpPort(): int
    {
        if (self::mode() !== 'container') {
            return 80;
        }
        $p = (int) getenv('RZMON_WEB_PORT');
        return $p > 0 && $p < 65536 ? $p : 8080;
    }

    /** Map van certbot: standaard /etc/letsencrypt, in de container in het datavolume */
    public static function configDir(): string
    {
        return self::mode() === 'container' ? Installer::HELPER_DIR . '/letsencrypt' : '/etc/letsencrypt';
    }

    /** @return array{cert: string, key: string} */
    public static function certFiles(string $domain): array
    {
        $d = self::configDir() . '/live/' . $domain;
        return ['cert' => "$d/fullchain.pem", 'key' => "$d/privkey.pem"];
    }

    public static function certbot(): ?string
    {
        foreach (['/usr/bin/certbot', '/usr/local/bin/certbot', '/snap/bin/certbot'] as $c) {
            if (is_executable($c)) {
                return $c;
            }
        }
        return null;
    }

    /** Een domeinnaam waarvoor Let's Encrypt een certificaat geeft: geen IP-adres, geen *, minstens één punt */
    public static function validDomain(string $d): bool
    {
        $d = strtolower($d);
        if ($d === '' || strlen($d) > 253 || filter_var($d, FILTER_VALIDATE_IP) || !str_contains($d, '.')) {
            return false;
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $d)) {
            return false;
        }
        return !preg_match('/\.(local|lan|home|internal|localdomain|arpa|test|invalid|example)$/', $d) && $d !== 'localhost';
    }

    // ------------------------------------------------------------------ stand

    /** @return array<string, mixed> wat er aan staat: domain, active, to, fp, issued_at, renewed_at, error, error_at */
    public static function state(): array
    {
        $s = json_decode((string) self::fresh('le_state'), true);
        return is_array($s) ? $s : [];
    }

    /** @param array<string, mixed> $s */
    private static function saveState(array $s): void
    {
        save_setting('le_state', $s ? (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
        save_setting('le_active', !empty($s['active']) ? '1' : '0');   // voor Status en functies
    }

    /** Een instelling rechtstreeks uit de database (een lang lopende taak ziet anders een oude waarde) */
    private static function fresh(string $name): string
    {
        try {
            return (string) (q('SELECT value FROM settings WHERE name = ?', [$name])->fetchColumn() ?: '');
        } catch (Throwable) {
            return (string) setting($name, '');
        }
    }

    /** @return array<string, mixed>|null voortgang van de laatste opdracht */
    public static function status(): ?array
    {
        $s = json_decode((string) @file_get_contents(Installer::HELPER_DIR . '/acme.status'), true);
        if (!is_array($s)) {
            return null;
        }
        // Loopt hij nog? Het bestand wordt elke paar seconden bijgewerkt; anders is hij gestopt
        if (($s['state'] ?? '') === 'running' && (int) @filemtime(Installer::HELPER_DIR . '/acme.status') < time() - 4 * self::HB) {
            $s['state'] = 'error';
            $s['message'] = t('De opdracht is onverwacht gestopt (zie het logboek hieronder)');
        }
        // Verzoek neergezet, maar de hulpdienst pakt het niet op
        if (($s['state'] ?? '') === 'queued' && (int) ($s['at'] ?? 0) < time() - 60 && is_file(Installer::HELPER_DIR . '/acme.request')) {
            $s['state'] = 'error';
            $s['message'] = t('De hulpdienst reageert niet. Controleer op de server: systemctl status rzmon-acme.path');
        }
        $s['log'] = self::logTail();
        return $s;
    }

    private static function logTail(int $lines = 40): string
    {
        $l = @file(Installer::HELPER_DIR . '/acme.log', FILE_IGNORE_NEW_LINES) ?: [];
        return implode("\n", array_slice($l, -$lines));
    }

    /** @return array<string, mixed> voor de kaart in de interface */
    public static function info(): array
    {
        $st = self::state();
        $mode = self::mode();
        $domain = (string) ($st['domain'] ?? '') ?: Https::adminHost();
        $cert = null;
        if (!empty($st['active']) && !empty($st['domain'])) {
            $cert = Https::fetchCert('127.0.0.1', self::tlsPort(), (string) $st['domain']);
        }
        $main = $mode === 'apache' ? (string) @file_get_contents(self::APACHE_MAIN) : '';
        return [
            'mode' => $mode, 'via' => self::via(), 'cli' => self::cliCommand('issue', $domain), 'dir' => realpath(SM_ROOT) ?: SM_ROOT,
            'domain' => $domain, 'email' => (string) setting('le_email', ''), 'state' => $st ?: null, 'cert' => $cert,
            'status' => self::status(), 'check' => json_decode((string) setting('le_check', ''), true) ?: null,
            'certbot' => self::certbot() !== null, 'tls_port' => self::tlsPort(), 'http_port' => self::httpPort(),
            'apache_443' => $mode !== 'apache' || self::apacheVhost($main, 'x.invalid', 'c', 'k') !== null,
            'apache_alias' => $mode !== 'apache' || str_contains($main, '/.well-known/acme-challenge/'),
            'server_ips' => OwnCert::serverIps(), 'via_proxy' => Https::viaProxy(),
            'admin_url' => Https::adminUrl(), 'https_only' => setting('https_only', '0') === '1',
            'renew_days' => self::RENEW_DAYS, 'warn_days' => Https::WARN_DAYS,
        ];
    }

    // ------------------------------------------------------------------ voorcontrole (vanuit de webpagina, zonder root)

    /**
     * Kan Let's Encrypt het domein bereiken? Openbare DNS (via Cloudflare, zoals Let's Encrypt het ziet) tegenover
     * het publieke IP van deze internetlijn, en IPv6. De echte proef is de proefaanvraag (test).
     * @return array<string, mixed>
     */
    public static function precheck(string $domain): array
    {
        $r = ['at' => time(), 'domain' => $domain, 'a' => [], 'aaaa' => [], 'public_ip' => null, 'local' => [], 'notes' => []];
        if (!self::validDomain($domain)) {
            $r['notes'][] = ['bad', t('Geen geldige domeinnaam voor Let\'s Encrypt (een naam als rzsense.jouwdomein.nl; geen IP-adres en geen .local of .lan)')];
            return $r;
        }
        $r['a'] = self::doh($domain, 'A');
        $r['aaaa'] = self::doh($domain, 'AAAA');
        $r['local'] = gethostbynamel($domain) ?: [];
        try {
            $r['public_ip'] = NetChecks::ownPublicIp();
        } catch (Throwable) {
            $r['public_ip'] = null;
        }
        $pub = (string) $r['public_ip'];
        if ($r['a'] === null) {
            $r['notes'][] = ['warn', t('De openbare DNS kon niet worden opgevraagd (geen internet vanaf de server?). De proefaanvraag laat zien of het werkt.')];
        } elseif (!$r['a'] && !$r['aaaa']) {
            $r['notes'][] = ['bad', t('{d} staat niet in de openbare DNS. Maak bij je domeinprovider een A-record {d} → {ip}.', ['d' => $domain, 'ip' => $pub ?: t('je publieke IP-adres')])];
        } elseif ($pub !== '' && filter_var($pub, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !in_array($pub, (array) $r['a'], true)) {
            $r['notes'][] = ['bad', t('{d} wijst in de openbare DNS naar {a}, maar het publieke IP-adres van deze internetlijn is {ip}. Pas het A-record aan bij je domeinprovider.', ['d' => $domain, 'a' => implode(', ', (array) $r['a']) ?: '-', 'ip' => $pub])];
        } else {
            $r['notes'][] = ['good', t('Openbare DNS: {d} → {a}', ['d' => $domain, 'a' => implode(', ', array_merge((array) $r['a'], (array) $r['aaaa']))])
                . ($pub !== '' ? ' (' . t('het publieke IP-adres van deze internetlijn') . ')' : '')];
        }
        if ($r['aaaa']) {
            $r['notes'][] = ['warn', t('Er is ook een IPv6-adres (AAAA-record: {ip}). Let\'s Encrypt probeert IPv6 eerst: zorg dat poort 80 en 443 ook via IPv6 bij RzSense uitkomen, of haal het AAAA-record weg.', ['ip' => implode(', ', (array) $r['aaaa'])])];
        }
        if ($r['local'] && count(array_filter($r['local'], 'is_private_ip')) > 0) {
            $r['notes'][] = ['good', t('Op je eigen netwerk wijst {d} naar {ip} (DNS-rewrite): zo gebruik je thuis hetzelfde adres en kan RzSense zijn eigen certificaat controleren.', ['d' => $domain, 'ip' => implode(', ', $r['local'])])];
        }
        if (Https::viaProxy()) {
            $r['notes'][] = ['warn', t('Je opent RzSense nu via een reverse proxy. Dan hoort het certificaat meestal bij je proxy (SWAG doet Let\'s Encrypt zelf). Hier aanvragen werkt alleen als poort 80 en 443 van het domein rechtstreeks bij RzSense uitkomen.')];
        }
        save_setting('le_check', (string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $r;
    }

    /** @return list<string>|null openbare DNS via DNS-over-HTTPS (Cloudflare); null = niet op te vragen */
    private static function doh(string $name, string $type): ?array
    {
        $ch = curl_init('https://1.1.1.1/dns-query?name=' . rawurlencode($name) . '&type=' . $type);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json'], CURLOPT_USERAGENT => 'RzSense/' . RZMON_VERSION]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $j = $code === 200 && is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j)) {
            return null;
        }
        $want = $type === 'A' ? 1 : 28;
        $out = [];
        foreach ((array) ($j['Answer'] ?? []) as $a) {
            if ((int) ($a['type'] ?? 0) === $want && filter_var((string) ($a['data'] ?? ''), FILTER_VALIDATE_IP)) {
                $out[] = (string) $a['data'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ verzoek vanuit de webpagina

    /**
     * Opdracht geven: test (proefaanvraag bij de testserver), issue (aanvragen en koppelen), renew, force (nu vernieuwen),
     * remove (loskoppelen; terug naar het eigen certificaat).
     * @return array<string, mixed>
     */
    public static function request(string $action, string $domain, string $email, string $by): array
    {
        if (!in_array($action, self::ACTIONS, true) || $action === 'deploy') {
            return ['ok' => false, 'error' => t('Onbekende opdracht')];
        }
        $domain = strtolower(trim($domain));
        $email = trim($email);
        if ($action === 'remove' || $action === 'renew' || $action === 'force') {
            $domain = (string) (self::state()['domain'] ?? '');
            if ($domain === '') {
                return ['ok' => false, 'error' => t('Er is nog geen Let\'s Encrypt-certificaat gekoppeld')];
            }
        } elseif (!self::validDomain($domain)) {
            return ['ok' => false, 'error' => t('Geen geldige domeinnaam voor Let\'s Encrypt (een naam als rzsense.jouwdomein.nl; geen IP-adres en geen .local of .lan)')];
        }
        if ($email !== '' && !valid_email($email)) {
            return ['ok' => false, 'error' => t('Geen geldig e-mailadres')];
        }
        $via = self::via();
        if ($via === 'none') {
            return ['ok' => false, 'error' => t('Hier kan RzSense geen certificaat koppelen: geen installatie met install-rocky.sh (Apache) en geen container. Vraag het certificaat aan bij je reverse proxy of webserver.')];
        }
        if ($via === 'cli') {
            return ['ok' => false, 'error' => t('Hiervoor is de hulpdienst nodig (als root). Installeer die met: sudo bash {dir}/install/install-helper.sh, of voer op de server uit: {cmd}',
                ['dir' => realpath(SM_ROOT) ?: SM_ROOT, 'cmd' => self::cliCommand($action === 'force' ? 'renew --force' : $action, $domain)]), 'cli' => self::cliCommand($action, $domain)];
        }
        $cur = self::status();
        if ($cur && in_array($cur['state'] ?? '', ['running', 'queued'], true)) {
            return ['ok' => false, 'error' => t('Er loopt al een opdracht; wacht tot die klaar is')];
        }
        if ($action !== 'remove') {
            save_setting('le_email', $email);
        }
        $h = Installer::HELPER_DIR;
        $req = ['nonce' => bin2hex(random_bytes(16)), 'action' => $action, 'domain' => $domain, 'email' => $email, 'by' => $by, 'at' => time()];
        self::writeStatus(['state' => 'queued', 'action' => $action, 'domain' => $domain, 'step' => 'queued', 'message' => t('Wacht op de start…'), 'pct' => 2, 'at' => time(), 'by' => $by]);
        @file_put_contents("$h/acme.log", '');
        $tmp = "$h/acme.request.tmp";
        if (file_put_contents($tmp, (string) json_encode($req)) === false || !rename($tmp, "$h/acme.request")) {
            return ['ok' => false, 'error' => t('Kan geen verzoek neerzetten in {dir}', ['dir' => $h])];
        }
        if ($via === 'direct') {
            // Container: zelf op de achtergrond (een aanvraag duurt tot een minuut)
            $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
            exec(escapeshellarg($php) . ' ' . escapeshellarg(SM_ROOT . '/install/install.php') . ' --acme --helper >/dev/null 2>&1 &');
        }
        AgentCommands::audit($by, 'Let\'s Encrypt', "$action $domain");
        return ['ok' => true, 'pending' => true];
    }

    // ------------------------------------------------------------------ de opdracht zelf (root, of www-data in de container)

    /** Het verzoek van de webpagina uitvoeren (install.php --acme --helper) */
    public static function runRequest(): int
    {
        $file = Installer::HELPER_DIR . '/acme.request';
        $raw = @file_get_contents($file);
        @unlink($file);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($d) || !preg_match('/^[a-f0-9]{32}$/', (string) ($d['nonce'] ?? ''))) {
            return 0;
        }
        $action = in_array($d['action'] ?? '', self::ACTIONS, true) ? (string) $d['action'] : '';
        $by = mb_substr((string) preg_replace('/[^\w.@-]/u', '', (string) ($d['by'] ?? '')), 0, 64) ?: 'webinterface';
        if ($action === '') {
            return 0;
        }
        $r = self::run($action, (string) ($d['domain'] ?? ''), (string) ($d['email'] ?? ''), $by);
        return $r['ok'] ? 0 : 1;
    }

    /**
     * Uitvoeren: alle gegevens worden hier opnieuw gecontroleerd (het verzoek komt van de webserver).
     * @return array<string, mixed> ok, error, message
     */
    public static function run(string $action, string $domain, string $email, string $by): array
    {
        $domain = strtolower(trim($domain));
        $email = trim($email);
        $st = self::state();
        if (in_array($action, ['renew', 'force', 'remove', 'deploy'], true)) {
            $domain = (string) ($st['domain'] ?? '');
        }
        $ctx = ['action' => $action, 'domain' => $domain, 'by' => $by, 'started' => time()];
        $step = static function (string $s, string $msg, int $pct) use (&$ctx): void {
            self::writeStatus(['state' => 'running', 'step' => $s, 'message' => $msg, 'pct' => $pct, 'at' => time()] + $ctx);
            self::log($msg);
        };
        $fail = static function (string $msg, string $hint = '') use (&$ctx, $action, $by, $domain): array {
            self::writeStatus(['state' => 'error', 'message' => $msg, 'hint' => $hint, 'pct' => 100, 'at' => time()] + $ctx);
            self::log('✘ ' . $msg . ($hint !== '' ? " – $hint" : ''));
            if ($action !== 'test') {
                $s = self::state();
                $s['error'] = $msg . ($hint !== '' ? " ($hint)" : '');
                $s['error_at'] = time();
                self::saveState($s);
            }
            AgentCommands::audit($by, 'Let\'s Encrypt mislukt', "$action $domain: $msg");
            return ['ok' => false, 'error' => $msg, 'hint' => $hint];
        };

        if ($domain === '' || !self::validDomain($domain)) {
            return $action === 'deploy' && $domain === '' ? ['ok' => true, 'message' => 'niets te doen'] : $fail(t('Geen geldige domeinnaam'));
        }
        if ($email !== '' && !valid_email($email)) {
            return $fail(t('Geen geldig e-mailadres'));
        }
        $mode = self::mode();
        if ($mode === 'none') {
            return $fail(t('Geen installatie met install-rocky.sh (Apache) en geen container'));
        }
        if ($mode === 'apache' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return $fail(t('Dit moet als root (sudo)'));
        }

        if ($action === 'remove') {
            $step('unlink', t('Loskoppelen: terug naar het eigen certificaat'), 30);
            $u = self::undeploy();
            if (!$u['ok']) {
                return $fail($u['error']);
            }
            if (($cb = self::certbot()) !== null) {
                self::exec(array_merge([$cb, 'delete', '--cert-name', $domain, '--non-interactive'], self::dirArgs()), 120);
            }
            self::saveState([]);
            self::writeStatus(['state' => 'ok', 'message' => t('Losgekoppeld. Via {d} krijg je nu weer het eigen certificaat van RzSense.', ['d' => $domain]), 'pct' => 100, 'at' => time()] + $ctx);
            AgentCommands::audit($by, 'Let\'s Encrypt losgekoppeld', $domain);
            return ['ok' => true];
        }
        if ($action === 'deploy') {
            if (empty($st['active'])) {
                return ['ok' => true, 'message' => 'niet actief'];
            }
            $r = self::deploy($domain);
            return $r['ok'] ? $r : $fail($r['error']);
        }

        // 1. voorwaarden
        $step('check', t('Controleren'), 5);
        if ($mode === 'apache') {
            $main = (string) @file_get_contents(self::APACHE_MAIN);
            if (self::apacheVhost($main, $domain, 'c', 'k') === null) {
                return $fail(t('Geen https-blok (poort 443) in {f}', ['f' => self::APACHE_MAIN]), t('Voer install-rocky.sh opnieuw uit zonder --no-https'));
            }
        }
        $cb = self::certbot();
        if ($cb === null && $mode === 'apache' && is_executable('/usr/bin/dnf')) {
            $step('install', t('certbot installeren (dnf install certbot, uit EPEL)'), 10);
            $x = self::exec(['/usr/bin/dnf', '-y', 'install', 'certbot'], 300);
            if ($x['code'] !== 0) {
                self::exec(['/usr/bin/dnf', '-y', 'install', 'epel-release'], 300);
                self::exec(['/usr/bin/dnf', '-y', 'install', 'certbot'], 300);
            }
            $cb = self::certbot();
        }
        if ($cb === null) {
            return $fail(t('certbot is niet geïnstalleerd'), $mode === 'container' ? t('Gebruik een nieuwere image van RzSense') : 'sudo dnf -y install epel-release && sudo dnf -y install certbot');
        }

        // 2. geeft de webserver de controlebestanden? (zelf getest, voordat Let's Encrypt het probeert)
        $step('webroot', t('Controleren of de webserver de controlebestanden van Let\'s Encrypt geeft'), 15);
        $w = self::webrootTest($domain);
        if (!$w['ok']) {
            return $fail($w['error'], $mode === 'apache' ? t('Voer install-rocky.sh opnieuw uit (de webserver krijgt dan /.well-known/acme-challenge/)') : t('Start de container opnieuw met een nieuwere image'));
        }

        $files = self::certFiles($domain);
        $before = self::fileFp($files['cert']);
        $base = array_merge(['--non-interactive'], self::dirArgs());
        if ($action === 'test' || $action === 'issue') {
            $args = array_merge([$cb, 'certonly'], $base, ['--webroot', '-w', self::WEBROOT, '-d', $domain, '--cert-name', $domain,
                '--agree-tos', '--key-type', 'ecdsa', '--keep-until-expiring'],
                $email !== '' ? ['--email', $email, '--no-eff-email'] : ['--register-unsafely-without-email']);
            if ($action === 'test') {
                $args[] = '--dry-run';
            }
            $step('certbot', $action === 'test' ? t('Proefaanvraag bij de testserver van Let\'s Encrypt (er komt geen echt certificaat)') : t('Certificaat aanvragen bij Let\'s Encrypt voor {d}', ['d' => $domain]), 30);
        } else {
            if (!is_file($files['cert']) && $mode === 'container') {
                return $fail(t('Geen certificaat gevonden in {f}', ['f' => $files['cert']]), t('Vraag het opnieuw aan'));
            }
            $args = array_merge([$cb, 'renew'], $base, ['--cert-name', $domain], $action === 'force' ? ['--force-renewal'] : []);
            $step('certbot', $action === 'force' ? t('Nu vernieuwen bij Let\'s Encrypt') : t('Vernieuwen (alleen als het binnen {n} dagen verloopt)', ['n' => self::RENEW_DAYS]), 30);
        }
        $x = self::exec($args, 300, static function (): void {
            touch(Installer::HELPER_DIR . '/acme.status');
        });
        if ($x['code'] !== 0) {
            [$why, $hint] = self::explain($x['out'], $domain);
            return $fail($why, $hint);
        }
        if ($action === 'test') {
            $msg = t('De proef is gelukt: Let\'s Encrypt kan {d} bereiken. Klik nu op Aanvragen en koppelen.', ['d' => $domain]);
            self::writeStatus(['state' => 'ok', 'message' => $msg, 'pct' => 100, 'at' => time()] + $ctx);
            self::log('✔ ' . $msg);
            return ['ok' => true, 'message' => $msg];
        }
        $after = self::fileFp($files['cert']);
        $changed = $after !== null && $after !== $before;

        // 3. koppelen (bij een nieuw certificaat, of als het nog niet gekoppeld was)
        $st = self::state();
        $wasOther = !empty($st['domain']) && $st['domain'] !== $domain;
        if ($changed || empty($st['active']) || $wasOther || $action === 'issue') {
            $step('deploy', $mode === 'apache' ? t('Koppelen aan Apache (test met apachectl configtest, dan herladen)') : t('Koppelen aan nginx (test met nginx -t, dan herladen)'), 75);
            $r = self::deploy($domain, $changed);   // nieuw certificaat: altijd herladen, ook als de instelling gelijk blijft
            if (!$r['ok']) {
                return $fail($r['error'], t('De vorige instelling is teruggezet: er is niets veranderd'));
            }
        }
        // Een ander domein dan eerst: het oude certificaat opruimen
        if ($wasOther && self::validDomain((string) $st['domain'])) {
            self::exec(array_merge([$cb, 'delete', '--cert-name', (string) $st['domain']], $base), 120);
        }

        // 4. controleren wat de webserver nu laat zien
        $step('verify', t('Controleren of {d} het nieuwe certificaat laat zien', ['d' => $domain]), 90);
        $seen = null;
        for ($i = 0; $i < 5; $i++) {
            $seen = Https::fetchCert('127.0.0.1', self::tlsPort(), $domain);
            if (!empty($seen['ok']) && $after !== null && str_replace(':', '', (string) $seen['fp']) === $after) {
                break;
            }
            sleep(1);
        }
        $info = $after !== null ? self::fileInfo($files['cert']) : null;
        $new = ['domain' => $domain, 'active' => true, 'to' => $info['to'] ?? null, 'fp' => $after, 'issuer' => $info['issuer'] ?? null,
            'issued_at' => ($action === 'issue' && ($changed || $wasOther)) || empty($st['issued_at']) ? time() : $st['issued_at'], 'renewed_at' => $changed ? time() : ($st['renewed_at'] ?? null),
            'checked_at' => time(), 'error' => null, 'error_at' => null];
        self::saveState($new);
        $shown = !empty($seen['ok']) && $after !== null && str_replace(':', '', (string) $seen['fp']) === $after;
        $msg = $changed ? t('Klaar: {d} heeft een certificaat van Let\'s Encrypt, geldig tot {to}.', ['d' => $domain, 'to' => isset($info['to']) ? date('d-m-Y', (int) $info['to']) : '?'])
            : t('Het certificaat is nog geldig tot {to}; vernieuwen was niet nodig.', ['to' => isset($info['to']) ? date('d-m-Y', (int) $info['to']) : '?']);
        if (!$shown) {
            $msg .= ' ' . t('Let op: de webserver liet bij de controle nog een ander certificaat zien ({err}).', ['err' => (string) ($seen['error'] ?? ($seen['issuer'] ?? '?'))]);
        }
        self::writeStatus(['state' => 'ok', 'message' => $msg, 'pct' => 100, 'at' => time(), 'changed' => $changed] + $ctx);
        self::log('✔ ' . $msg);
        if ($changed) {
            AgentCommands::audit($by, 'Let\'s Encrypt-certificaat ' . ($action === 'issue' ? 'gekoppeld' : 'vernieuwd'), $domain . (isset($info['to']) ? ' (tot ' . date('d-m-Y', (int) $info['to']) . ')' : ''));
        }
        // Het beheeradres meteen opnieuw controleren (Beheer van buiten)
        try {
            if (Https::adminHost() === $domain) {
                Https::check();
            }
        } catch (Throwable) {
        }
        return ['ok' => true, 'message' => $msg, 'changed' => $changed];
    }

    /** @return list<string> */
    private static function dirArgs(): array
    {
        if (self::mode() !== 'container') {
            return [];
        }
        return ['--config-dir', self::configDir(), '--work-dir', '/tmp/rzsense-certbot/work', '--logs-dir', '/tmp/rzsense-certbot/logs'];
    }

    /**
     * Zelf een controlebestand neerzetten en via http op de server zelf ophalen, met het domein als Host
     * @return array<string, mixed>
     */
    private static function webrootTest(string $domain): array
    {
        $dir = self::WEBROOT . '/.well-known/acme-challenge';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return ['ok' => false, 'error' => t('Kan de map {d} niet maken', ['d' => $dir])];
        }
        @chmod(self::WEBROOT, 0755);
        @chmod(self::WEBROOT . '/.well-known', 0755);
        @chmod($dir, 0755);
        $tok = 'rzsense-test-' . bin2hex(random_bytes(8));
        file_put_contents("$dir/$tok", $tok);
        @chmod("$dir/$tok", 0644);
        $ch = curl_init('http://127.0.0.1:' . self::httpPort() . '/.well-known/acme-challenge/' . $tok);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Host: ' . $domain], CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        @unlink("$dir/$tok");
        if ($code === 200 && trim((string) $body) === $tok) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => t('De webserver geeft de controlebestanden niet (http://{d}/.well-known/acme-challenge/ gaf {code})', ['d' => $domain, 'code' => $code ?: t('geen antwoord')])];
    }

    /**
     * Uitleg bij een fout van certbot, in gewone taal
     * @return array{0: string, 1: string}
     */
    public static function explain(string $out, string $domain): array
    {
        $o = strtolower($out);
        if (str_contains($o, 'too many') || str_contains($o, 'ratelimit') || str_contains($o, 'rate limit')) {
            return [t('Let\'s Encrypt weigert tijdelijk: te veel aanvragen voor dit domein'), t('Wacht een uur (of tot een week bij 5 dezelfde certificaten). Gebruik intussen de proefaanvraag.')];
        }
        if (str_contains($o, 'nxdomain') || str_contains($o, 'no valid ip addresses') || str_contains($o, 'dns problem')) {
            return [t('{d} staat niet (goed) in de openbare DNS', ['d' => $domain]), t('Maak bij je domeinprovider een A-record naar je publieke IP-adres. Een nieuw record kan tot een uur duren.')];
        }
        if (str_contains($o, 'timeout during connect') || str_contains($o, 'connection refused') || str_contains($o, 'likely firewall problem')) {
            return [t('Let\'s Encrypt kan poort 80 van {d} niet bereiken', ['d' => $domain]), t('Zet in je router poort 80 (en 443) door naar RzSense, en open ze in de firewall van de server. Zie het stappenplan hieronder.')];
        }
        if (str_contains($o, 'unauthorized') || str_contains($o, 'invalid response') || str_contains($o, '404')) {
            return [t('Let\'s Encrypt kwam ergens anders uit dan bij RzSense'), t('Poort 80 van je publieke IP-adres gaat naar een ander apparaat (of een AAAA-record wijst naar iets anders). Controleer de port-forward in je router.')];
        }
        if (str_contains($o, 'caa record')) {
            return [t('Een CAA-record bij je domein staat Let\'s Encrypt niet toe'), t('Voeg bij je domeinprovider een CAA-record toe voor letsencrypt.org, of haal het weg.')];
        }
        if (str_contains($o, 'another instance of certbot')) {
            return [t('certbot draait al (een andere aanvraag of vernieuwing)'), t('Probeer het over een paar minuten opnieuw.')];
        }
        $last = '';
        foreach (array_reverse(explode("\n", trim($out))) as $l) {
            if (preg_match('/(error|detail|problem|fail)/i', $l)) {
                $last = trim($l);
                break;
            }
        }
        return [t('Aanvragen mislukt') . ($last !== '' ? ': ' . mb_substr($last, 0, 300) : ''), t('Zie het logboek hieronder.')];
    }

    // ------------------------------------------------------------------ koppelen aan de webserver

    /**
     * De virtuele host voor het domein, gemaakt uit het 443-blok van rzmon.conf (zodat hij dezelfde regels heeft):
     * ServerName wordt het domein, ServerAlias en het eigen certificaat gaan eruit. null = geen 443-blok.
     */
    public static function apacheVhost(string $main, string $domain, string $cert, string $key): ?string
    {
        if (!preg_match('~^[ \t]*<VirtualHost\s+[^>]*:443\s*>.*?^[ \t]*</VirtualHost>~msi', $main, $m)) {
            return null;
        }
        $out = [];
        foreach (explode("\n", $m[0]) as $l) {
            if (preg_match('/^\s*(ServerAlias|SSLCertificateChainFile)\b/i', $l) || preg_match('/^\s*#.*(self-signed|Let\'s Encrypt)/i', $l)) {
                continue;
            }
            if (preg_match('/^(\s*)ServerName\s/i', $l, $s)) {
                $l = $s[1] . 'ServerName ' . $domain;
            } elseif (preg_match('/^(\s*)SSLCertificateFile\s/i', $l, $s)) {
                $l = $s[1] . 'SSLCertificateFile    ' . $cert;
            } elseif (preg_match('/^(\s*)SSLCertificateKeyFile\s/i', $l, $s)) {
                $l = $s[1] . 'SSLCertificateKeyFile ' . $key;
            } elseif (preg_match('/^(\s*)(ErrorLog|CustomLog)(\s+)(\S*?)rzmon_ssl_/i', $l)) {
                $l = str_replace('rzmon_ssl_', 'rzmon_le_', $l);
            }
            $out[] = $l;
        }
        return "# RzSense – Let's Encrypt voor $domain (aangemaakt door RzSense; niet zelf wijzigen)\n"
            . "# Gemaakt uit het 443-blok van rzmon.conf. rzmon.conf blijft de standaard op poort 443 (agents op het IP-adres).\n"
            . "# Loskoppelen: Instellingen → Toegang tot RzSense → Beheer van buiten → Let's Encrypt, of: sudo php install/install.php --acme remove\n"
            . implode("\n", $out) . "\n";
    }

    /** nginx-blok voor de container: https op de eigen poort met het Let's Encrypt-certificaat */
    public static function nginxConf(string $domain, int $port, string $cert, string $key, bool $ipv6): string
    {
        return "# RzSense – Let's Encrypt voor $domain (aangemaakt door RzSense; niet zelf wijzigen)\n"
            . "server {\n"
            . "    listen $port ssl http2;\n"
            . ($ipv6 ? "    listen [::]:$port ssl http2;\n" : '')
            . "    server_name $domain;\n"
            . "    ssl_certificate     $cert;\n"
            . "    ssl_certificate_key $key;\n"
            . "    ssl_protocols TLSv1.2 TLSv1.3;\n"
            . "    ssl_session_cache shared:rzle:1m;\n"
            . "    include /tmp/rzsense-site.conf;\n"
            . "}\n";
    }

    /**
     * @param bool $reload ook herladen als de instelling niet verandert (nieuw certificaat in dezelfde bestanden)
     * @return array<string, mixed> ok, error
     */
    public static function deploy(string $domain, bool $reload = false): array
    {
        $f = self::certFiles($domain);
        if (self::mode() === 'container') {
            if (!is_readable($f['cert']) || !is_readable($f['key'])) {
                return ['ok' => false, 'error' => t('Certificaat niet gevonden in {f}', ['f' => dirname($f['cert'])])];
            }
            return self::swap(self::NGINX_LE, self::nginxConf($domain, self::tlsPort(), $f['cert'], $f['key'], is_file('/proc/net/if_inet6')),
                ['nginx', '-t', '-q', '-e', '/dev/stderr', '-c', self::NGINX_MAIN], ['nginx', '-s', 'reload', '-e', '/dev/stderr', '-c', self::NGINX_MAIN], $reload);
        }
        if (!is_file($f['cert']) || !is_file($f['key'])) {
            return ['ok' => false, 'error' => t('Certificaat niet gevonden in {f}', ['f' => dirname($f['cert'])])];
        }
        $v = self::apacheVhost((string) @file_get_contents(self::APACHE_MAIN), $domain, $f['cert'], $f['key']);
        if ($v === null) {
            return ['ok' => false, 'error' => t('Geen https-blok (poort 443) in {f}', ['f' => self::APACHE_MAIN])];
        }
        $ctl = is_executable('/usr/sbin/apachectl') ? '/usr/sbin/apachectl' : 'apachectl';
        return self::swap(self::APACHE_LE, $v, [$ctl, 'configtest'], ['systemctl', 'reload', 'httpd'], $reload);
    }

    /**
     * Terug naar alleen het eigen certificaat
     * @return array<string, mixed>
     */
    public static function undeploy(): array
    {
        if (self::mode() === 'container') {
            return self::swap(self::NGINX_LE, null, ['nginx', '-t', '-q', '-e', '/dev/stderr', '-c', self::NGINX_MAIN], ['nginx', '-s', 'reload', '-e', '/dev/stderr', '-c', self::NGINX_MAIN]);
        }
        $ctl = is_executable('/usr/sbin/apachectl') ? '/usr/sbin/apachectl' : 'apachectl';
        return self::swap(self::APACHE_LE, null, [$ctl, 'configtest'], ['systemctl', 'reload', 'httpd']);
    }

    /**
     * Configuratiebestand vervangen (of weghalen bij null), testen en herladen. Gaat de test mis, dan de vorige terug.
     * @param list<string> $test
     * @param list<string> $reload
     * @param bool $always ook testen en herladen als het bestand gelijk blijft
     * @return array<string, mixed>
     */
    private static function swap(string $file, ?string $content, array $test, array $reload, bool $always = false): array
    {
        $old = is_file($file) ? (string) file_get_contents($file) : null;
        if ($content === $old && !$always) {
            return ['ok' => true, 'same' => true];
        }
        if ($content === $old) {
            // niets te schrijven: alleen testen en herladen
        } elseif ($content === null) {
            @unlink($file);
        } elseif (file_put_contents("$file.tmp", $content) === false || !@chmod("$file.tmp", 0644) || !rename("$file.tmp", $file)) {
            return ['ok' => false, 'error' => t('Kan {f} niet schrijven', ['f' => $file])];
        }
        $restore = static function () use ($file, $old): void {
            if ($old === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $old);
            }
        };
        if (self::mode() === 'container' && !is_file(self::NGINX_MAIN)) {
            return ['ok' => true];   // nginx start nog (bij het opstarten van de container): hij leest het bestand zelf
        }
        $t = self::exec($test, 60);
        if ($t['code'] !== 0) {
            $restore();
            return ['ok' => false, 'error' => t('De webserver keurt de nieuwe instelling af: {err}', ['err' => mb_substr(trim($t['out']), -400)])];
        }
        $r = self::exec($reload, 60);
        if ($r['code'] !== 0) {
            $restore();
            self::exec($reload, 60);
            return ['ok' => false, 'error' => t('Herladen van de webserver mislukt: {err}', ['err' => mb_substr(trim($r['out']), -400)])];
        }
        self::log($content === null ? "$file weggehaald, webserver herladen" : "$file geschreven, webserver herladen");
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ elke dag (cron)

    /** Container bij het opstarten (container/init.php): het nginx-blok terugzetten, nginx draait nog niet */
    public static function boot(): ?string
    {
        $st = self::state();
        if (empty($st['active']) || empty($st['domain'])) {
            return null;
        }
        $d = (string) $st['domain'];
        $f = self::certFiles($d);
        if (self::mode() !== 'container' || !self::validDomain($d)) {
            return null;
        }
        if (!is_readable($f['cert']) || !is_readable($f['key'])) {
            @unlink(self::NGINX_LE);
            return "Let's Encrypt niet gekoppeld: certificaat niet gevonden in " . dirname($f['cert']);
        }
        // nginx draait nog niet: alleen het bestand neerzetten (nginx leest het zo meteen zelf)
        file_put_contents(self::NGINX_LE, self::nginxConf($d, self::tlsPort(), $f['cert'], $f['key'], is_file('/proc/net/if_inet6')));
        return "Let's Encrypt: https op poort " . self::tlsPort() . " voor $d";
    }

    /** Elke dag één keer vernieuwen vragen (certbot doet pas iets 30 dagen voor het verloopt) */
    public static function cron(): ?string
    {
        $st = self::state();
        if (empty($st['active']) || empty($st['domain']) || self::via() === 'cli' || self::via() === 'none') {
            return null;
        }
        if ((int) self::fresh('le_cron_at') > time() - 86400 || (int) date('G') < 3) {
            return null;
        }
        save_setting('le_cron_at', (string) time());
        $cur = self::status();
        if ($cur && in_array($cur['state'] ?? '', ['running', 'queued'], true)) {
            return null;
        }
        // Al lang een fout? Dan één melding (het beheeradres meldt zelf als het certificaat bijna verloopt)
        $to = (int) ($st['to'] ?? 0);
        if (!empty($st['error']) && $to > 0 && $to - time() < 14 * 86400 && (int) ($st['told'] ?? 0) < time() - 3 * 86400) {
            $st['told'] = time();
            self::saveState($st);
            Monitor::notifySystem('⚠️ ' . t('Let\'s Encrypt: vernieuwen lukt niet'), t('Het certificaat van {d} verloopt op {to} en vernieuwen mislukt: {err}', ['d' => $st['domain'], 'to' => date('d-m-Y', $to), 'err' => $st['error']]), STATUS_WARN, 'problem');
        }
        $r = self::request('renew', (string) $st['domain'], (string) setting('le_email', ''), 'RzMon');
        return $r['ok'] ? "Let's Encrypt: vernieuwen gevraagd voor {$st['domain']}" : "Let's Encrypt: {$r['error']}";
    }

    // ------------------------------------------------------------------ hulpjes

    /** @param array<string, mixed> $s */
    private static function writeStatus(array $s): void
    {
        $f = Installer::HELPER_DIR . '/acme.status';
        @file_put_contents("$f.tmp", (string) json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod("$f.tmp", 0660);
        if (($g = @filegroup(Installer::HELPER_DIR)) !== false) {
            @chgrp("$f.tmp", $g);
        }
        @rename("$f.tmp", $f);
    }

    private static function log(string $line): void
    {
        $f = Installer::HELPER_DIR . '/acme.log';
        @file_put_contents($f, '[' . date('H:i:s') . '] ' . $line . "\n", FILE_APPEND);
        @chmod($f, 0660);
        if (($g = @filegroup(Installer::HELPER_DIR)) !== false) {
            @chgrp($f, $g);
        }
    }

    /**
     * Een programma uitvoeren zonder shell; uitvoer naar het logboek
     * @param list<string> $cmd
     * @return array{code: int, out: string}
     */
    private static function exec(array $cmd, int $timeout, ?callable $tick = null): array
    {
        $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($p)) {
            self::log('✘ ' . basename($cmd[0]) . ': ' . t('kan niet starten'));
            return ['code' => 127, 'out' => ''];
        }
        stream_set_blocking($pipes[1], false);
        $out = '';
        $end = time() + $timeout;
        $last = time();
        while (true) {
            $chunk = (string) fread($pipes[1], 8192);
            if ($chunk !== '') {
                $out .= $chunk;
                foreach (explode("\n", rtrim($chunk)) as $l) {
                    if (trim($l) !== '') {
                        self::log('  ' . mb_substr($l, 0, 400));
                    }
                }
            }
            $s = proc_get_status($p);
            if (!$s['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                proc_close($p);
                return ['code' => (int) $s['exitcode'], 'out' => $out];
            }
            if (time() > $end) {
                proc_terminate($p);
                fclose($pipes[1]);
                proc_close($p);
                self::log('✘ ' . t('duurde te lang; gestopt'));
                return ['code' => 124, 'out' => $out . "\ntimeout"];
            }
            if ($tick && time() - $last >= 5) {
                $tick();
                $last = time();
            }
            usleep(200000);
        }
    }

    /** SHA-256 van het eerste certificaat in een PEM-bestand (hoofdletters, zonder dubbele punten) */
    private static function fileFp(string $file): ?string
    {
        $pem = is_readable($file) ? (string) file_get_contents($file) : '';
        $fp = $pem !== '' ? @openssl_x509_fingerprint($pem, 'sha256') : false;
        return is_string($fp) ? strtoupper($fp) : null;
    }

    /** @return array{to: int, issuer: string}|null */
    private static function fileInfo(string $file): ?array
    {
        $i = @openssl_x509_parse((string) @file_get_contents($file));
        if (!is_array($i)) {
            return null;
        }
        $iss = $i['issuer'] ?? [];
        return ['to' => (int) ($i['validTo_time_t'] ?? 0), 'issuer' => trim((string) ($iss['O'] ?? '') . ' ' . (string) ($iss['CN'] ?? ''))];
    }
}
