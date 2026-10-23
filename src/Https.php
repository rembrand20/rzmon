<?php
declare(strict_types=1);

/**
 * Veilig beheer via https (1.68.0):
 * - een reverse proxy (SWAG, Nginx Proxy Manager …) herkennen: X-Forwarded-For en X-Forwarded-Proto gelden alleen van een
 *   adres bij Vertrouwde proxy's; proxy-kopteksten van een ander adres = bezoeker van buiten (en een ⚠️-melding);
 * - "Beheer alleen via https": de webinterface via http gaat naar het beheeradres, met HSTS (eerst 1 dag, na 7 dagen
 *   zonder problemen 1 jaar). Agents, hun downloads en de REST API blijven via http werken;
 * - de nooddeur: https op het IP-adres van RzMon zelf (self-signed), alleen vanaf je interne netwerken;
 * - inloggen via http weigeren, en 2FA verplicht van buiten je interne netwerken;
 * - het certificaat van het beheeradres dagelijks controleren: ℹ️ 14 dagen voor het verloopt, ⚠️ als het verlopen of ongeldig is.
 * Noodcommando: php install/install.php --https-off
 */
final class Https
{
    private const HSTS_TEST = 86400;        // de eerste 7 dagen: 1 dag
    private const HSTS_FULL = 31536000;     // daarna: 1 jaar
    private const TEST_DAYS = 7;
    public const WARN_DAYS = 14;            // ℹ️ zoveel dagen voor het certificaat verloopt
    private const PROXY_HEADERS = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED'];
    /** Verzoeken van agents (en de downloads voor het installeren): nooit doorsturen, ook niet bij "alleen https" */
    private const AGENT_ACTIONS = ['ping', 'agent', 'agent_tests', 'agent_check', 'agent_pair', 'agent_pkg_progress', 'agent_script', 'agent_install'];

    // ------------------------------------------------------------------ het verzoek

    private static function proxies(): array
    {
        try {
            return is_configured() ? parse_list((string) setting('trusted_proxies', '')) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Komt dit verzoek binnen via een vertrouwde reverse proxy? */
    public static function viaProxy(): bool
    {
        $p = self::proxies();
        return $p && ip_in_list((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $p);
    }

    /** Proxy-kopteksten van een adres dat niet bij Vertrouwde proxy's staat: dan weten we niet wie de bezoeker is */
    public static function unknownProxy(): bool
    {
        if (PHP_SAPI === 'cli' || self::viaProxy()) {
            return false;
        }
        foreach (self::PROXY_HEADERS as $h) {
            if (!empty($_SERVER[$h])) {
                return true;
            }
        }
        return false;
    }

    private static function first(string $header): string
    {
        return strtolower(trim(explode(',', (string) ($_SERVER[$header] ?? ''))[0]));
    }

    /** Kwam dit verzoek via https binnen (rechtstreeks, of via een vertrouwde proxy die dat meldt)? */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::viaProxy() && self::first('HTTP_X_FORWARDED_PROTO') === 'https';
    }

    /** Vertrouwde proxy die geen X-Forwarded-Proto meestuurt (dan zien we https niet) */
    public static function proxyNoProto(): bool
    {
        return self::viaProxy() && self::first('HTTP_X_FORWARDED_PROTO') === '' && empty($_SERVER['HTTPS']);
    }

    private static function bareHost(string $h): string
    {
        $h = strtolower(trim($h));
        if (preg_match('/^\[([^\]]+)\]/', $h, $m)) {
            return $m[1];
        }
        return (string) preg_replace('/:\d+$/', '', $h);
    }

    /** De naam waarmee de browser RzMon opende (via een vertrouwde proxy: X-Forwarded-Host) */
    public static function host(): string
    {
        $h = self::viaProxy() ? self::first('HTTP_X_FORWARDED_HOST') : '';
        return self::bareHost($h !== '' ? $h : (string) ($_SERVER['HTTP_HOST'] ?? ''));
    }

    public static function adminUrl(): string
    {
        return rtrim((string) setting('admin_url', ''), '/');
    }

    public static function adminHost(?string $url = null): string
    {
        $u = $url ?? self::adminUrl();
        return $u === '' ? '' : self::bareHost((string) parse_url($u, PHP_URL_HOST));
    }

    /** Is RzMon nu via het beheeradres met https geopend? */
    public static function onAdminUrl(?string $url = null): bool
    {
        $a = self::adminHost($url);
        return $a !== '' && self::isHttps() && self::host() === $a;
    }

    /** Komt de bezoeker uit een van je interne netwerken (Instellingen → Toegang)? Via een onbekende proxy: nooit. */
    public static function internal(): bool
    {
        try {
            return !self::unknownProxy() && ip_in_list(client_ip(), parse_list((string) setting('trusted_networks', '')));
        } catch (Throwable) {
            return false;
        }
    }

    public static function on(): bool
    {
        return setting('https_only', '0') === '1' && self::adminHost() !== '';
    }

    /** Is 2FA nodig voor deze bezoeker (van buiten je interne netwerken, als dat aan staat)? */
    public static function mfaRequired(): bool
    {
        return setting('mfa_external', '0') === '1' && !self::internal();
    }

    /** Inloggen via http geweigerd? */
    public static function loginBlocked(): bool
    {
        return setting('http_login_block', '0') === '1' && !self::isHttps();
    }

    /** HSTS: eerst 1 dag; na 7 dagen zonder problemen met het certificaat 1 jaar */
    public static function hstsAge(): int
    {
        $since = strtotime((string) setting('https_since', '')) ?: time();
        return time() - $since >= self::TEST_DAYS * 86400 ? self::HSTS_FULL : self::HSTS_TEST;
    }

    /** Wanneer HSTS naar 1 jaar gaat (null = al zo) */
    public static function hstsFullAt(): ?int
    {
        $since = strtotime((string) setting('https_since', '')) ?: time();
        $at = $since + self::TEST_DAYS * 86400;
        return $at > time() ? $at : null;
    }

    /** Het IP-adres van de nooddeur (https rechtstreeks op RzMon); '' = onbekend */
    public static function serverIp(): string
    {
        $s = json_decode((string) setting('https_state', ''), true) ?: [];
        $ip = (string) ($s['server_ip'] ?? '');
        if ($ip === '') {
            $ip = (string) gethostbyname((string) gethostname());
        }
        return filter_var($ip, FILTER_VALIDATE_IP) && !ip_in_list($ip, ['127.0.0.0/8', '::1/128']) ? $ip : '';
    }

    /** Link van de nooddeur */
    public static function door(): string
    {
        return 'https://' . (self::serverIp() ?: t('<IP-adres van RzMon>')) . '/';
    }

    // ------------------------------------------------------------------ bij elk webverzoek

    /** Aan het begin van elk webverzoek (na de blokkades): doorsturen naar https, HSTS, en een onbekende proxy opmerken. */
    public static function enforce(): void
    {
        try {
            self::noteUnknownProxy();
            $https = self::isHttps();
            if (!self::on()) {
                // Stond het eerder aan? Dan de browser laten vergeten dat hij alleen https mocht (anders 1 jaar vast)
                if ($https && setting('hsts_sent', '0') === '1') {
                    header('Strict-Transport-Security: max-age=0');
                }
                return;
            }
            $admin = self::adminHost();
        } catch (Throwable) {
            return;   // database nog niet klaar (setup, update): niets doen
        }
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === 'v1.php' || ($script === 'api.php' && in_array((string) ($_GET['action'] ?? ''), self::AGENT_ACTIONS, true))) {
            return;   // REST API (eigen sleutel) en agents (versleuteld) mogen via http blijven
        }
        $host = self::host();
        if ($https) {
            if ($host === $admin) {
                header('Strict-Transport-Security: max-age=' . self::hstsAge());
                if (setting('hsts_sent', '0') !== '1') {
                    save_setting('hsts_sent', '1');
                }
                return;
            }
            if (self::internal()) {
                return;   // de nooddeur: https op het IP-adres van RzMon, alleen van binnen
            }
            self::redirect();
        }
        if ($host === $admin) {
            return;   // via de proxy, maar die meldt geen https: niet doorsturen (dat gaf een lus); zie "Hoe staat het ervoor?"
        }
        self::redirect();
    }

    private static function redirect(): never
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $url = self::adminUrl() . '/' . ($script === 'index.php' ? '' : $script) . ($qs !== '' ? "?$qs" : '');
        header('Cache-Control: no-store');
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => t('RzMon is alleen via https te beheren: open {url}', ['url' => self::adminUrl()]), 'https' => self::adminUrl()], JSON_UNESCAPED_UNICODE));
    }

    /** ⚠️ eenmaal per dag (per adres): verkeer via een proxy die niet bij Vertrouwde proxy's staat */
    private static function noteUnknownProxy(): void
    {
        if (!self::unknownProxy()) {
            return;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $d = json_decode((string) setting('proxy_unknown', ''), true) ?: [];
        if (($d['ip'] ?? '') === $ip && (int) ($d['at'] ?? 0) > time() - 86400) {
            return;
        }
        save_setting('proxy_unknown', json_encode(['ip' => $ip, 'at' => time()]));
        Monitor::notifySystem('⚠️ ' . t('Verkeer via een onbekende proxy: {ip}', ['ip' => $ip]),
            t('Er komen verzoeken met proxy-kopteksten (X-Forwarded-For) binnen van {ip}, maar dat adres staat niet bij Vertrouwde proxy\'s. RzMon behandelt deze bezoekers daarom als extern (2FA verplicht, nooit inloggen zonder wachtwoord). Is dit je reverse proxy (bv. SWAG)? Zet het adres dan bij Instellingen → Toegang → Reverse proxy IP\'s.', ['ip' => $ip]),
            STATUS_WARN, 'problem');
    }

    // ------------------------------------------------------------------ certificaat

    /**
     * Certificaat ophalen bij $connect:$port met naam $name (SNI en naamcontrole).
     * @return array ok, trusted, error, cn, names, issuer, from, to, days, fp, self_signed
     */
    public static function fetchCert(string $connect, int $port, string $name): array
    {
        $get = static function (bool $verify) use ($connect, $port, $name): array {
            $ctx = stream_context_create(['ssl' => [
                'capture_peer_cert' => true, 'verify_peer' => $verify, 'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $name,
            ]]);
            $addr = filter_var($connect, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$connect]" : $connect;
            set_error_handler(static fn() => true);
            $fp = stream_socket_client("ssl://$addr:$port", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
            $last = error_get_last()['message'] ?? '';
            restore_error_handler();
            if (!$fp) {
                return [null, trim((string) preg_replace('/^.*?: /', '', $errstr ?: $last)) ?: t('geen verbinding')];
            }
            $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
            fclose($fp);
            return [$cert ?: null, null];
        };
        [$x509, $err] = $get(true);
        $trusted = $x509 !== null;
        if (!$x509) {
            [$x509, $err2] = $get(false);
            if (!$x509) {
                return ['ok' => false, 'error' => $err2 ?? $err];
            }
        }
        $i = openssl_x509_parse($x509) ?: [];
        $names = [];
        foreach (explode(',', (string) ($i['extensions']['subjectAltName'] ?? '')) as $n) {
            $n = trim(preg_replace('/^(DNS|IP Address):/', '', trim($n)));
            if ($n !== '') {
                $names[] = $n;
            }
        }
        $flat = static fn($v) => is_array($v) ? implode(', ', $v) : (string) $v;
        $issuer = trim($flat($i['issuer']['O'] ?? '') . (isset($i['issuer']['CN']) ? ' ' . $flat($i['issuer']['CN']) : '')) ?: '?';
        $to = (int) ($i['validTo_time_t'] ?? 0);
        $fp = strtoupper((string) openssl_x509_fingerprint($x509, 'sha256'));
        return [
            'ok' => true, 'trusted' => $trusted, 'error' => $trusted ? null : t('niet vertrouwd of de naam klopt niet'),
            'cn' => $flat($i['subject']['CN'] ?? '?'), 'names' => array_slice($names, 0, 20), 'issuer' => $issuer,
            'from' => (int) ($i['validFrom_time_t'] ?? 0), 'to' => $to, 'days' => (int) floor(($to - time()) / 86400),
            'fp' => implode(':', str_split($fp, 2)), 'self_signed' => ($i['subject'] ?? null) == ($i['issuer'] ?? null),
        ];
    }

    private static function isPrivate(string $ip): bool
    {
        return ip_in_list($ip, PRIVATE_NETWORKS);
    }

    /**
     * Controle van het beheeradres (certificaat en DNS) en de nooddeur. Met $notify (cron, dagelijks):
     * ℹ️ 14 dagen voor het certificaat verloopt, ⚠️ als het verlopen of ongeldig is, ✅ als het weer goed is.
     */
    public static function check(bool $notify = false, ?string $url = null): array
    {
        $prev = json_decode((string) setting('https_state', ''), true) ?: [];
        $s = ['at' => time(), 'server_ip' => $prev['server_ip'] ?? '', 'warned' => $prev['warned'] ?? false, 'soon_to' => $prev['soon_to'] ?? 0];
        $url ??= self::adminUrl();   // $url: net opgeslagen (setting() onthoudt per verzoek de oude waarde)
        if ($url !== '') {
            $h = self::adminHost($url);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $ips = filter_var($h, FILTER_VALIDATE_IP) ? [$h] : (gethostbynamel($h) ?: []);
            $s['dns'] = ['ips' => $ips, 'private' => $ips && count(array_filter($ips, [self::class, 'isPrivate'])) === count($ips)];
            $s['admin'] = ['url' => $url] + self::fetchCert($h, $port, $h);
        }
        $s['local'] = self::fetchCert('127.0.0.1', 443, $s['server_ip'] ?: 'localhost');
        $a = $s['admin'] ?? null;
        $problem = $a ? (!$a['ok'] ? t('geen https-verbinding met {url}: {err}', ['url' => $url, 'err' => $a['error']])
            : (!$a['trusted'] ? t('het certificaat is niet vertrouwd of de naam klopt niet ({issuer})', ['issuer' => $a['issuer']])
                : ($a['days'] < 0 ? t('het certificaat is verlopen op {d}', ['d' => date('d-m-Y', $a['to'])]) : null))) : null;
        $s['problem'] = $problem;
        // In de testfase van HSTS: bij een probleem opnieuw 7 dagen wachten voor HSTS naar 1 jaar gaat
        if ($problem !== null && self::on() && self::hstsFullAt() !== null) {
            save_setting('https_since', now());
        }
        if ($notify && $a) {
            $door = self::door();
            if ($problem !== null && !$s['warned']) {
                Monitor::notifySystem('⚠️ ' . t('Certificaat van RzMon: probleem'), ucfirst($problem) . '. '
                    . t('Kun je RzMon niet meer openen? Gebruik vanaf je thuisnetwerk de nooddeur {door} (self-signed: kies "toch doorgaan").', ['door' => $door]), STATUS_WARN, 'problem');
                $s['warned'] = true;
            } elseif ($problem === null && $s['warned']) {
                Monitor::notifySystem('✅ ' . t('Certificaat van RzMon weer in orde'), t('{url}: geldig tot {d} ({issuer})', ['url' => $url, 'd' => date('d-m-Y', $a['to']), 'issuer' => $a['issuer']]), STATUS_OK, 'recovery');
                $s['warned'] = false;
            }
            if ($problem === null && $a['days'] <= self::WARN_DAYS && (int) $s['soon_to'] !== $a['to']) {
                Monitor::sysInfo('cert', null, null, '🔐 ' . t('Certificaat verloopt over {n} dagen', ['n' => $a['days']]),
                    fn() => t('Het certificaat van {url} verloopt op {d} ({issuer}). Normaal vernieuwt je reverse proxy (SWAG) het rond 30 dagen van tevoren; kijk in de log van je proxy waarom dat niet lukt.',
                        ['url' => $url, 'd' => date('d-m-Y', $a['to']), 'issuer' => $a['issuer']]));
                $s['soon_to'] = $a['to'];
            }
        }
        save_setting('https_state', json_encode($s, JSON_UNESCAPED_UNICODE));
        save_setting('https_check_at', now());
        return $s;
    }

    /** Vanuit cron: één keer per dag (alleen als er een beheeradres is) */
    public static function cron(): ?string
    {
        if (self::adminUrl() === '' || strtotime((string) setting('https_check_at', '')) > time() - 86400) {
            return null;
        }
        $s = self::check(true);
        return 'Certificaat: ' . ($s['problem'] ?? (isset($s['admin']['days']) ? "nog {$s['admin']['days']} dagen geldig" : 'gecontroleerd'));
    }
}
