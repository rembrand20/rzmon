<?php
declare(strict_types=1);

/**
 * Het eigen (self-signed) certificaat van RzMon op poort 443 (1.70.0): het adres van de agents bij https op het IP-adres,
 * en de nooddeur. Bekijken, downloaden (met je wachtwoord) en vernieuwen: een nieuwe sleutel (EC P-256), 2 jaar geldig,
 * met de hostnaam, alle IP-adressen van de server, het beheeradres en eigen namen. Vernieuwen gebeurt als root: via de
 * hulpdienst (cert.request → install.php --renew-cert --helper) of in de terminal. Agents met een vastgezet certificaat
 * gaan vanzelf mee: ze krijgen de nieuwe vingerafdruk in een versleuteld antwoord (zie AgentCrypto::pin).
 *
 * Vanaf 1.78.0 in twee stappen als er agents (1.53.0+) op het eigen certificaat vastgezet zijn: eerst klaarzetten
 * (prepare: nieuw certificaat naast het oude, *.next), de agents krijgen de nieuwe vingerafdruk erbij, en pas als ze
 * die allemaal hebben (of na NEXT_DAYS dagen, of met "Nu wisselen") wordt het nieuwe certificaat gebruikt (activate).
 * Zo is er geen moment waarop een agent het certificaat weigert.
 */
final class OwnCert
{
    public const DAYS = 730;
    /** Uiterlijk zoveel dagen na het klaarzetten wisselen, ook als niet alle agents de nieuwe vingerafdruk hebben */
    public const NEXT_DAYS = 7;
    private const VHOSTS = ['/etc/httpd/conf.d/rzmon.conf', '/etc/apache2/sites-enabled/rzmon.conf', '/etc/apache2/sites-available/rzmon.conf'];

    /** Waar staan certificaat en sleutel? config.php (ssl_cert/ssl_key), anders uit de virtuele host van install-rocky.sh */
    public static function paths(): ?array
    {
        if (config('ssl_cert') && config('ssl_key')) {
            return ['cert' => (string) config('ssl_cert'), 'key' => (string) config('ssl_key'), 'from' => 'config.php'];
        }
        foreach (self::VHOSTS as $f) {
            $conf = @file_get_contents($f);
            if ($conf && preg_match('/^\s*SSLCertificateFile\s+(\S+)/mi', $conf, $c) && preg_match('/^\s*SSLCertificateKeyFile\s+(\S+)/mi', $conf, $k)) {
                return ['cert' => trim($c[1], '"'), 'key' => trim($k[1], '"'), 'from' => $f];
            }
        }
        return null;
    }

    /** Alle IP-adressen van deze server (zonder loopback en link-local) */
    public static function serverIps(): array
    {
        $ips = [];
        $out = (string) @shell_exec(rz_sh('ip -o addr show scope global 2>/dev/null'));
        if (preg_match_all('/\sinet6?\s+([0-9a-f.:]+)\//i', $out, $m)) {
            $ips = $m[1];
        }
        if (!$ips) {
            $ips = preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) ?: [];
        }
        return array_values(array_unique(array_filter($ips, static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP)
            && !ip_in_list($ip, ['127.0.0.0/8', '::1/128', 'fe80::/10']))));
    }

    /** Eigen namen en IP-adressen (Instellingen → Agents → 🔐 Certificaat) */
    public static function extraNames(): array
    {
        return parse_list((string) setting('cert_extra_names', ''));
    }

    public static function validName(string $n): bool
    {
        return (bool) filter_var($n, FILTER_VALIDATE_IP)
            || (bool) preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $n);
    }

    /** De namen in een nieuw certificaat: hostnaam, localhost, IP-adressen, het beheeradres en de Basis-URL, plus eigen namen */
    public static function names(): array
    {
        $host = strtolower((string) gethostname());
        $names = array_filter([$host, 'localhost', Https::adminHost(), strtolower((string) parse_url((string) config('base_url', ''), PHP_URL_HOST))]);
        $names = array_merge($names, self::serverIps(), ['127.0.0.1'], array_map('strtolower', self::extraNames()));
        return array_values(array_unique(array_filter($names, [self::class, 'validName'])));
    }

    /** Vingerafdruk van de publieke sleutel zoals curl --pinnedpubkey hem wil (sha256, base64) */
    public static function spkiPin(string $pem): ?string
    {
        $pub = openssl_pkey_get_public($pem);
        $d = $pub ? openssl_pkey_get_details($pub) : null;
        if (!$d || empty($d['key'])) {
            return null;
        }
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $d['key']));
        return base64_encode(hash('sha256', $der, true));
    }

    /** Het certificaat op poort 443 van RzMon zelf (zoals de agents en de nooddeur het zien), met de PEM en de pin */
    public static function current(): array
    {
        $c = Https::fetchCert('127.0.0.1', 443, 'localhost', true);
        if (!empty($c['pem'])) {
            $c['pin'] = self::spkiPin($c['pem']);
        }
        return $c;
    }

    /** Nieuw sleutelpaar en certificaat. @return array{0:string,1:string} [certificaat-PEM, sleutel-PEM] */
    public static function generate(array $names, int $days = self::DAYS): array
    {
        $dns = array_values(array_filter($names, static fn($n) => !filter_var($n, FILTER_VALIDATE_IP)));
        $cn = $dns[0] ?? ($names[0] ?? 'rzmon');
        $san = implode(',', array_map(static fn($n) => (filter_var($n, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:') . $n, $names));
        $cnf = tempnam(sys_get_temp_dir(), 'rzcert');
        file_put_contents($cnf, "[req]\ndistinguished_name = dn\nx509_extensions = v3\n[dn]\n[v3]\nsubjectAltName = $san\n"
            . "basicConstraints = critical, CA:FALSE\nkeyUsage = critical, digitalSignature\nextendedKeyUsage = serverAuth\nsubjectKeyIdentifier = hash\n");
        try {
            $opt = ['config' => $cnf, 'digest_alg' => 'sha256'];
            // Sleutel zonder ons eigen configbestand: daarmee keurt PHP een EC-sleutel af ("at least 384 bits")
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $csr = $key ? openssl_csr_new(['commonName' => $cn, 'organizationName' => 'RzMon'], $key, $opt) : false;
            $x509 = $csr ? openssl_csr_sign($csr, null, $key, $days, $opt + ['x509_extensions' => 'v3'], random_int(1, PHP_INT_MAX)) : false;
            if (!$x509 || !openssl_x509_export($x509, $crt) || !openssl_pkey_export($key, $pem)) {
                throw new RuntimeException(t('Certificaat maken mislukt: {e}', ['e' => (string) openssl_error_string()]));
            }
        } finally {
            @unlink($cnf);
        }
        return [$crt, $pem];
    }

    /** Webserver: eerst de configuratie testen, dan herladen. @return string|null fout */
    private static function reload(): ?string
    {
        $cmd = (string) config('ssl_reload', '');   // alleen voor bijzondere opstellingen (en om te testen)
        if ($cmd === '') {
            foreach (['httpd' => 'apachectl -t', 'apache2' => 'apache2ctl -t', 'nginx' => 'nginx -t'] as $svc => $test) {
                if (trim((string) @shell_exec(rz_sh('systemctl is-active ' . $svc . ' 2>/dev/null'))) === 'active') {
                    exec(rz_sh("$test 2>&1"), $o, $rc);
                    if ($rc !== 0) {
                        return t('De configuratie van de webserver klopt niet: {e}', ['e' => mb_substr(trim(implode(' ', $o)), 0, 300)]);
                    }
                    $cmd = "systemctl reload $svc";
                    break;
                }
            }
            if ($cmd === '') {
                return t('Geen draaiende webserver gevonden (httpd, apache2 of nginx); herstart hem zelf');
            }
        }
        exec(rz_sh("$cmd 2>&1"), $o2, $rc2);
        return $rc2 === 0 ? null : t('Herladen mislukt: {e}', ['e' => mb_substr(trim(implode(' ', $o2)), 0, 300)]);
    }

    /**
     * Vernieuwen (als root, of als de bestanden schrijfbaar zijn). Bij een fout wordt het oude certificaat teruggezet.
     * @return array ok, error, names, to, fp, pin
     */
    public static function renew(string $by): array
    {
        $p = self::paths();
        if (!$p) {
            return ['ok' => false, 'error' => t('Waar het certificaat staat, is niet bekend. Zet ssl_cert en ssl_key in config.php.')];
        }
        $names = self::names();
        try {
            [$crt, $key] = self::generate($names);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        save_setting('own_cert_next', '');   // een klaargezet certificaat vervalt
        return self::install($p, $crt, $key, $names, $by);
    }

    /** Certificaat en sleutel op hun plek zetten (met reservekopie) en de webserver herladen; bij een fout terugzetten */
    private static function install(array $p, string $crt, string $key, array $names, string $by): array
    {
        $old = [$p['cert'] => @file_get_contents($p['cert']), $p['key'] => @file_get_contents($p['key'])];
        $stamp = date('Ymd-His');
        foreach ($old as $f => $content) {
            if ($content !== false) {
                @copy($f, "$f.rzmon-$stamp");   // reservekopie naast het origineel
                @chmod("$f.rzmon-$stamp", 0600);
            }
        }
        // Op dezelfde plek overschrijven: eigenaar, rechten en SELinux-context blijven
        $w1 = @file_put_contents($p['key'], $key);
        @chmod($p['key'], 0600);
        $w2 = $w1 !== false ? @file_put_contents($p['cert'], $crt) : false;
        $err = $w1 === false || $w2 === false ? t('Kan {f} niet schrijven (als root uitvoeren)', ['f' => $w1 === false ? $p['key'] : $p['cert']]) : self::reload();
        if ($err !== null) {
            foreach ($old as $f => $content) {
                if ($content !== false) {
                    @file_put_contents($f, $content);
                }
            }
            if ($w1 !== false) {
                self::reload();
            }
            return ['ok' => false, 'error' => $err];
        }
        $info = openssl_x509_parse($crt) ?: [];
        usleep(1500000);   // de webserver even de tijd geven (herladen gaat op de achtergrond)
        save_setting('agent_pin_at', '0');   // vingerafdruk voor de agents opnieuw bepalen
        save_setting('own_pin_cache', '');
        AgentCommands::audit($by, 'Certificaat van RzMon vernieuwd', implode(', ', $names));
        Monitor::sysInfo('cert', null, null, '🔐 ' . t('Certificaat van RzMon vernieuwd'),
            fn() => t('Nieuw certificaat (2 jaar geldig, tot {d}) voor {names}, door {by}. Agents met een vastgezet certificaat krijgen de nieuwe vingerafdruk vanzelf.',
                ['d' => date('d-m-Y', (int) ($info['validTo_time_t'] ?? 0)), 'names' => implode(', ', $names), 'by' => $by]));
        try {
            Https::check();
        } catch (Throwable) {
        }
        return ['ok' => true, 'error' => null, 'names' => $names, 'to' => (int) ($info['validTo_time_t'] ?? 0), 'pin' => self::spkiPin($crt)];
    }

    // ------------------------------------------------------------------ in twee stappen (1.78.0)

    /** Het klaargezette certificaat (pin, names, to, at, by, deadline), of null */
    public static function next(): ?array
    {
        $n = json_decode((string) setting('own_cert_next', ''), true);
        return is_array($n) && !empty($n['pin']) ? $n : null;
    }

    /**
     * Agents (1.53.0+, laatste dag gezien) die een adres met het eigen certificaat gebruiken, met of ze de nieuwe
     * vingerafdruk al hebben. @return list<array{name:string, ready:bool}>
     */
    public static function pinnedAgents(): array
    {
        $cur = AgentCrypto::ownPins()[0] ?? '';
        $next = self::next()['pin'] ?? '';
        $out = [];
        foreach (q("SELECT name, agent_version, agent_url_pin, agent_url_fb_pin FROM hosts WHERE mode = 'agent' AND enabled = 1 AND agent_seen > ? ORDER BY name",
            [date('Y-m-d H:i:s', time() - 86400)])->fetchAll() as $h) {
            $specs = (string) $h['agent_url_pin'] . ';' . (string) $h['agent_url_fb_pin'];
            if (!$h['agent_version'] || version_compare($h['agent_version'], AgentCrypto::PINURL_MIN, '<')
                || ($cur === '' || !str_contains($specs, "sha256//$cur")) && ($next === '' || !str_contains($specs, "sha256//$next"))) {
                continue;
            }
            $out[] = ['name' => $h['name'], 'ready' => $next !== '' && str_contains($specs, "sha256//$next")];
        }
        return $out;
    }

    /** Stap 1: nieuw certificaat klaarzetten naast het huidige (cert.next / key.next); de agents krijgen de vingerafdruk */
    public static function prepare(string $by): array
    {
        $p = self::paths();
        if (!$p) {
            return ['ok' => false, 'error' => t('Waar het certificaat staat, is niet bekend. Zet ssl_cert en ssl_key in config.php.')];
        }
        $names = self::names();
        try {
            [$crt, $key] = self::generate($names);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $old = umask(077);
        $w = @file_put_contents($p['key'] . '.next', $key) !== false && @file_put_contents($p['cert'] . '.next', $crt) !== false;
        umask($old);
        if (!$w) {
            return ['ok' => false, 'error' => t('Kan {f} niet schrijven (als root uitvoeren)', ['f' => $p['cert'] . '.next'])];
        }
        $info = openssl_x509_parse($crt) ?: [];
        $n = ['pin' => self::spkiPin($crt), 'names' => $names, 'to' => (int) ($info['validTo_time_t'] ?? 0), 'at' => time(), 'by' => $by,
            'deadline' => time() + self::NEXT_DAYS * 86400];
        save_setting('own_cert_next', json_encode($n));
        AgentCommands::audit($by, 'Nieuw certificaat van RzMon klaargezet', implode(', ', $names));
        Monitor::sysInfo('cert', null, null, '🔐 ' . t('Nieuw certificaat van RzMon klaargezet'),
            fn() => t('De agents krijgen eerst de nieuwe vingerafdruk. Zodra ze die allemaal hebben (uiterlijk {d}) gaat RzMon het nieuwe certificaat gebruiken. Door {by}.',
                ['d' => date('d-m-Y', $n['deadline']), 'by' => $by]));
        return ['ok' => true, 'error' => null, 'prepared' => true, 'names' => $names, 'to' => $n['to'], 'pin' => $n['pin'], 'deadline' => $n['deadline']];
    }

    /** Stap 2: het klaargezette certificaat gaan gebruiken */
    public static function activate(string $by): array
    {
        $p = self::paths();
        $n = self::next();
        if (!$p || !$n) {
            return ['ok' => false, 'error' => t('Er staat geen nieuw certificaat klaar')];
        }
        $crt = (string) @file_get_contents($p['cert'] . '.next');
        $key = (string) @file_get_contents($p['key'] . '.next');
        if ($crt === '' || $key === '' || self::spkiPin($crt) !== $n['pin']) {
            save_setting('own_cert_next', '');
            return ['ok' => false, 'error' => t('Het klaargezette certificaat is niet meer te vinden; vernieuw opnieuw')];
        }
        $r = self::install($p, $crt, $key, (array) $n['names'], $by);
        if ($r['ok']) {
            save_setting('own_cert_next', '');
            @unlink($p['cert'] . '.next');
            @unlink($p['key'] . '.next');
        }
        return $r;
    }

    /**
     * Elke minuut (cron): staat er een certificaat klaar en hebben alle agents de nieuwe vingerafdruk (of is de termijn
     * om)? Dan wisselen: zelf, via de hulpdienst (als root), of een melding met het commando voor de terminal.
     */
    public static function cron(): ?string
    {
        $n = self::next();
        if (!$n) {
            return null;
        }
        $agents = self::pinnedAgents();
        $ready = !array_filter($agents, static fn($a) => !$a['ready']);
        if (!$ready && time() < (int) $n['deadline']) {
            return null;
        }
        if ((int) ($n['asked'] ?? 0) > time() - 600) {
            return null;   // net gevraagd (hulpdienst of melding): even wachten
        }
        $n['asked'] = time();
        save_setting('own_cert_next', json_encode($n));
        $via = self::via();
        if ($via === 'direct') {
            $r = self::activate('RzMon');
            return $r['ok'] ? 'Nieuw certificaat in gebruik' : 'Certificaat wisselen mislukt: ' . $r['error'];
        }
        if ($via === 'helper') {
            $h = Installer::HELPER_DIR;
            @file_put_contents("$h/cert.request", json_encode(['nonce' => bin2hex(random_bytes(16)), 'by' => 'RzMon', 'action' => 'activate']));
            return 'Nieuw certificaat: wisselen gevraagd aan de hulpdienst';
        }
        if (empty($n['told'])) {
            $n['told'] = time();
            save_setting('own_cert_next', json_encode($n));
            Monitor::sysInfo('cert', null, null, '🔐 ' . t('Nieuw certificaat van RzMon kan in gebruik'),
                fn() => t('Alle agents hebben de nieuwe vingerafdruk. Voer op de server uit: {cmd}', ['cmd' => self::cliCommand() . ' --activate']));
        }
        return null;
    }

    /** Hoe vernieuwen hier kan: helper (hulpdienst), direct (bestanden schrijfbaar), of alleen in de terminal */
    public static function via(): string
    {
        $h = Installer::HELPER_DIR;
        if (is_file("$h/cert.installed") && is_writable($h)) {
            return 'helper';
        }
        $p = self::paths();
        return $p && is_writable($p['cert']) && is_writable($p['key']) ? 'direct' : 'cli';
    }

    public static function cliCommand(): string
    {
        return 'sudo php ' . realpath(SM_ROOT) . '/install/install.php --renew-cert';
    }
}
