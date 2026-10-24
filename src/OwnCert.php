<?php
declare(strict_types=1);

/**
 * Het eigen (self-signed) certificaat van RzMon op poort 443 (1.70.0): het adres van de agents bij https op het IP-adres,
 * en de nooddeur. Bekijken, downloaden (met je wachtwoord) en vernieuwen: een nieuwe sleutel (EC P-256), 2 jaar geldig,
 * met de hostnaam, alle IP-adressen van de server, het beheeradres en eigen namen. Vernieuwen gebeurt als root: via de
 * hulpdienst (cert.request → install.php --renew-cert --helper) of in de terminal. Agents met een vastgezet certificaat
 * gaan vanzelf mee: ze krijgen de nieuwe vingerafdruk in een versleuteld antwoord (zie AgentCrypto::pin).
 */
final class OwnCert
{
    public const DAYS = 730;
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
        $out = (string) @shell_exec('ip -o addr show scope global 2>/dev/null');
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
                if (trim((string) @shell_exec('systemctl is-active ' . $svc . ' 2>/dev/null')) === 'active') {
                    exec("$test 2>&1", $o, $rc);
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
        exec("$cmd 2>&1", $o2, $rc2);
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
