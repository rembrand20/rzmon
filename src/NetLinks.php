<?php
declare(strict_types=1);

/**
 * Koppelingen voor de netwerkscan (1.104.0): UniFi en AdGuard Home.
 * - UniFi (Network-app, ook versie 9/10, op een UniFi OS-console of zelf gehost): de clientlijst met namen, wifi-netwerk
 *   en access point, en een client blokkeren (weren). Lezen kan met een API-sleutel; blokkeren vraagt een lokaal account.
 * - AdGuard Home (één of meer, bv. gesynchroniseerd): namen van clients, met welke domeinen een apparaat praat, en een
 *   IP-adres op de lijst "Niet toegestane clients" zetten (weren). Gesynchroniseerde instanties krijgen het allemaal.
 * Alles met korte time-outs: een controller die niet antwoordt, houdt de scan niet op.
 */
final class NetLinks
{
    private const TIMEOUT = 8;

    // ------------------------------------------------------------------ UniFi

    public static function unifiOn(): bool
    {
        return setting('unifi_url', '') !== '' && (self::unifiAccount() || setting('unifi_key', '') !== '');
    }

    private static function unifiAccount(): bool
    {
        return setting('unifi_user', '') !== '' && setting('unifi_pass', '') !== '';
    }

    /** Kan RzMon via UniFi blokkeren? (alleen met een lokaal account) */
    public static function unifiCanBlock(): bool
    {
        return setting('unifi_url', '') !== '' && self::unifiAccount();
    }

    /** @var array{ch: CurlHandle, base: string, csrf: string, mode: string}|null */
    private static ?array $u = null;

    /** Inloggen (UniFi OS of zelf gehoste Network-app). @return string|null fout */
    private static function unifiLogin(): ?string
    {
        if (self::$u) {
            return null;
        }
        $url = rtrim((string) setting('unifi_url', ''), '/');
        if (!preg_match('#^https?://[^/\s]+$#', $url)) {
            return t('UniFi: vul het adres van de controller in, bv. https://192.168.2.1');
        }
        $ch = curl_init();
        $csrf = '';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_COOKIEFILE => '',
            CURLOPT_SSL_VERIFYPEER => setting('unifi_verify_ssl', '0') === '1', CURLOPT_SSL_VERIFYHOST => setting('unifi_verify_ssl', '0') === '1' ? 2 : 0,
            CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$csrf) {
                if (preg_match('/^x-(?:updated-)?csrf-token:\s*(\S+)/i', $h, $m)) {
                    $csrf = $m[1];
                }
                return strlen($h);
            }]);
        $body = json_encode(['username' => (string) setting('unifi_user', ''), 'password' => (string) setting('unifi_pass', ''), 'remember' => false]);
        // Allebei proberen: UniFi OS (UDM, UCG, Cloud Key Gen2+) en de zelf gehoste Network-app. Een klassieke controller kent
        // /api/auth/login niet en antwoordt dan 401 "LoginRequired": dat zegt niets over het wachtwoord (1.105.1).
        $tried = [];
        foreach (['unifios' => '/api/auth/login', 'classic' => '/api/login'] as $mode => $path) {
            curl_setopt_array($ch, [CURLOPT_URL => $url . $path, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($raw === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return t('UniFi niet bereikbaar: {e}', ['e' => $err]);
            }
            if ($code === 200) {
                // Klassieke controller: de CSRF-sleutel staat in een cookie (csrf_token), niet in een header
                if ($csrf === '') {
                    foreach (curl_getinfo($ch, CURLINFO_COOKIELIST) ?: [] as $c) {
                        $f = explode("\t", $c);
                        if (($f[5] ?? '') === 'csrf_token' && ($f[6] ?? '') !== '') {
                            $csrf = $f[6];
                        }
                    }
                }
                self::$u = ['ch' => $ch, 'base' => $url . ($mode === 'unifios' ? '/proxy/network' : ''), 'csrf' => &$csrf, 'mode' => $mode];
                return null;
            }
            $j = json_decode((string) $raw, true);
            $tried[$mode] = [$code, (string) ($j['meta']['msg'] ?? $j['error'] ?? $j['message'] ?? $j['code'] ?? '')];
        }
        curl_close($ch);
        $msgs = strtolower(implode(' ', array_column($tried, 1)));
        if (str_contains($msgs, '2fa') || str_contains($msgs, 'mfa') || str_contains($msgs, 'token_required')) {
            return t('UniFi vraagt voor dit account een tweede stap (2FA of e-mailcode); maak een lokaal account zonder 2FA');
        }
        // Wachtwoord fout: klassiek "api.err.Invalid" (400), UniFi OS 401/403 met een eigen melding (niet LoginRequired)
        $wrong = (($tried['classic'][0] ?? 0) === 400 && str_contains($msgs, 'invalid'))
            || (in_array($tried['unifios'][0] ?? 0, [401, 403], true) && ($tried['unifios'][1] ?? '') !== '' && !str_contains(strtolower($tried['unifios'][1]), 'loginrequired'));
        $detail = implode(' · ', array_map(fn($m, $r) => "$m: HTTP {$r[0]}" . ($r[1] !== '' ? " {$r[1]}" : ''), array_keys($tried), $tried));
        return ($wrong ? t('UniFi: gebruikersnaam of wachtwoord klopt niet (gebruik een lokaal account, zonder 2FA)') : t('UniFi: inloggen lukt niet; klopt het adres (met poort, bv. :8443)?'))
            . " ($detail)";
    }

    /** Verzoek aan de klassieke API (/api/s/<site>/…). @return array{0: ?array, 1: ?string} [data, fout] */
    private static function unifiCall(string $path, ?array $post = null): array
    {
        if ($e = self::unifiLogin()) {
            return [null, $e];
        }
        $u = self::$u;
        $site = preg_replace('/[^A-Za-z0-9_-]/', '', (string) setting('unifi_site', 'default')) ?: 'default';
        $h = ['Content-Type: application/json', 'Accept: application/json'];
        if ($u['csrf'] !== '') {
            $h[] = 'X-CSRF-Token: ' . $u['csrf'];
        }
        curl_setopt_array($u['ch'], [CURLOPT_URL => "{$u['base']}/api/s/$site/$path", CURLOPT_HTTPHEADER => $h] + ($post !== null
            ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post)] : [CURLOPT_HTTPGET => true]));
        $raw = curl_exec($u['ch']);
        $code = (int) curl_getinfo($u['ch'], CURLINFO_RESPONSE_CODE);
        $j = json_decode((string) $raw, true);
        if ($raw === false || $code >= 400 || !is_array($j)) {
            return [null, t('UniFi: {path} gaf HTTP {c}', ['path' => $path, 'c' => $code]) . ($code === 401 || $code === 403 ? ' · ' . t('heeft het account genoeg rechten (beheerder)?') : '')];
        }
        if (($j['meta']['rc'] ?? 'ok') !== 'ok') {
            return [null, 'UniFi: ' . ($j['meta']['msg'] ?? '?')];
        }
        return [(array) ($j['data'] ?? []), null];
    }

    /** Met een API-sleutel (Network 9+): de officiële Integration API, alleen lezen */
    private static function unifiKeyClients(): array
    {
        $url = rtrim((string) setting('unifi_url', ''), '/') . '/proxy/network/integration/v1';
        $get = static function (string $path) use ($url): array {
            $ch = curl_init($url . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => setting('unifi_verify_ssl', '0') === '1', CURLOPT_SSL_VERIFYHOST => setting('unifi_verify_ssl', '0') === '1' ? 2 : 0,
                CURLOPT_HTTPHEADER => ['X-API-KEY: ' . setting('unifi_key', ''), 'Accept: application/json'], CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $j = json_decode((string) $raw, true);
            if ($raw === false) {
                throw new RuntimeException(t('UniFi niet bereikbaar: {e}', ['e' => $err]));
            }
            if ($code >= 400 || !is_array($j)) {
                throw new RuntimeException(t('UniFi: API-sleutel geweigerd of verkeerd adres (HTTP {c})', ['c' => $code]));
            }
            return $j;
        };
        try {
            $sites = (array) ($get('/sites')['data'] ?? []);
            $want = (string) setting('unifi_site', 'default');
            $site = null;
            foreach ($sites as $s) {
                if (($s['internalReference'] ?? '') === $want || ($s['name'] ?? '') === $want) {
                    $site = $s['id'];
                }
            }
            $site ??= $sites[0]['id'] ?? null;
            if (!$site) {
                return ['ok' => false, 'error' => t('UniFi: geen site gevonden'), 'clients' => []];
            }
            $out = [];
            for ($off = 0; $off < 2000; $off += 200) {
                $page = (array) ($get("/sites/$site/clients?limit=200&offset=$off")['data'] ?? []);
                foreach ($page as $c) {
                    if ($mac = NetScan::normMac($c['macAddress'] ?? null)) {
                        $out[$mac] = ['name' => (string) ($c['name'] ?? ''), 'hostname' => '', 'ip' => (string) ($c['ipAddress'] ?? ''),
                            'wired' => ($c['type'] ?? '') === 'WIRED', 'essid' => '', 'ap' => '', 'blocked' => false];
                    }
                }
                if (count($page) < 200) {
                    break;
                }
            }
            return ['ok' => true, 'mode' => 'apikey', 'clients' => $out];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'clients' => []];
        }
    }

    /**
     * Alle clients (ook die nu offline zijn maar bekend), met naam, wifi-netwerk, access point en of ze geblokkeerd zijn.
     * @return array{ok: bool, error?: string, mode?: string, clients: array<string, array>}
     */
    public static function unifiClients(): array
    {
        if (!self::unifiOn()) {
            return ['ok' => false, 'error' => t('UniFi is niet ingesteld'), 'clients' => []];
        }
        if (!self::unifiAccount()) {
            return self::unifiKeyClients();
        }
        [$sta, $err] = self::unifiCall('stat/sta');
        if ($err) {
            return ['ok' => false, 'error' => $err, 'clients' => []];
        }
        [$devs] = self::unifiCall('stat/device-basic');
        $aps = [];
        foreach ((array) $devs as $d) {
            if ($m = NetScan::normMac($d['mac'] ?? null)) {
                $aps[$m] = (string) ($d['name'] ?? $d['model'] ?? '');
            }
        }
        [$known] = self::unifiCall('rest/user');   // bekende clients, ook offline (met de blokkade)
        $out = [];
        foreach ((array) $known as $c) {
            if ($mac = NetScan::normMac($c['mac'] ?? null)) {
                $out[$mac] = ['name' => (string) ($c['name'] ?? ''), 'hostname' => (string) ($c['hostname'] ?? ''), 'ip' => (string) ($c['last_ip'] ?? ''),
                    'wired' => !empty($c['is_wired']), 'essid' => '', 'ap' => '', 'blocked' => !empty($c['blocked']), 'online' => false];
            }
        }
        foreach ((array) $sta as $c) {
            if ($mac = NetScan::normMac($c['mac'] ?? null)) {
                $out[$mac] = array_replace($out[$mac] ?? [], ['name' => (string) ($c['name'] ?? ($out[$mac]['name'] ?? '')), 'hostname' => (string) ($c['hostname'] ?? ''),
                    'ip' => (string) ($c['ip'] ?? ''), 'wired' => !empty($c['is_wired']), 'essid' => (string) ($c['essid'] ?? ''),
                    'ap' => $aps[NetScan::normMac($c['ap_mac'] ?? null) ?? ''] ?? '', 'blocked' => !empty($c['blocked']), 'online' => true]);
            }
        }
        return ['ok' => true, 'mode' => self::$u['mode'], 'clients' => $out, 'devices' => $aps];
    }

    /** Client blokkeren of weer toelaten op het wifi (op MAC-adres). @return true|string */
    public static function unifiBlock(string $mac, bool $block): true|string
    {
        if (!self::unifiCanBlock()) {
            return t('UniFi: blokkeren kan alleen met een lokaal account (gebruikersnaam en wachtwoord), niet met alleen een API-sleutel');
        }
        [, $err] = self::unifiCall('cmd/stamgr', ['cmd' => $block ? 'block-sta' : 'unblock-sta', 'mac' => $mac]);
        return $err ?? true;
    }

    // ------------------------------------------------------------------ AdGuard Home

    /** Adressen van de AdGuard Home-instanties (één per regel) */
    public static function adguardUrls(): array
    {
        return array_values(array_filter(array_map(fn($u) => rtrim($u, '/'), parse_list((string) setting('adguard_urls', ''))),
            fn($u) => (bool) preg_match('#^https?://[^/\s]+(/\S*)?$#', $u)));
    }

    public static function adguardOn(): bool
    {
        return (bool) self::adguardUrls();
    }

    /** @return array{0: mixed, 1: ?string} [antwoord, fout] */
    private static function ag(string $base, string $path, ?array $post = null): array
    {
        $ch = curl_init($base . '/control/' . $path);
        $user = (string) setting('adguard_user', '');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => setting('adguard_verify_ssl', '0') === '1', CURLOPT_SSL_VERIFYHOST => setting('adguard_verify_ssl', '0') === '1' ? 2 : 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION]
            + ($user !== '' ? [CURLOPT_USERPWD => $user . ':' . setting('adguard_pass', '')] : [])
            + ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post)] : []));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $host = (string) parse_url($base, PHP_URL_HOST);
        if ($raw === false) {
            return [null, t('AdGuard {h} niet bereikbaar: {e}', ['h' => $host, 'e' => $err])];
        }
        if ($code === 401 || $code === 403) {
            return [null, t('AdGuard {h}: gebruikersnaam of wachtwoord klopt niet', ['h' => $host])];
        }
        if ($code >= 400) {
            return [null, t('AdGuard {h}: HTTP {c}', ['h' => $host, 'c' => $code])];
        }
        return [$raw === '' ? [] : json_decode((string) $raw, true), null];
    }

    /** Namen van clients uit AdGuard: [ip => naam] en [mac => naam] (eigen clients gaan voor automatisch gevonden) */
    public static function adguardNames(): array
    {
        $ips = [];
        $macs = [];
        $errors = [];
        foreach (self::adguardUrls() as $base) {
            [$j, $e] = self::ag($base, 'clients');
            if ($e) {
                $errors[] = $e;
                continue;
            }
            foreach ((array) ($j['auto_clients'] ?? []) as $c) {
                if (filter_var($c['ip'] ?? '', FILTER_VALIDATE_IP) && ($c['name'] ?? '') !== '') {
                    $ips[$c['ip']] ??= (string) $c['name'];
                }
            }
            foreach ((array) ($j['clients'] ?? []) as $c) {
                foreach ((array) ($c['ids'] ?? []) as $id) {
                    if (filter_var($id, FILTER_VALIDATE_IP)) {
                        $ips[$id] = (string) $c['name'];
                    } elseif ($m = NetScan::normMac($id)) {
                        $macs[$m] = (string) $c['name'];
                    }
                }
            }
        }
        return ['ips' => $ips, 'macs' => $macs, 'errors' => $errors];
    }

    /** Met welke domeinen praat dit IP-adres? Uit de query-log van alle instanties (laatste 1000 per instantie). */
    public static function adguardDomains(string $ip): array
    {
        $dom = [];
        $total = 0;
        $blocked = 0;
        $errors = [];
        $first = null;
        foreach (self::adguardUrls() as $base) {
            [$j, $e] = self::ag($base, 'querylog?limit=1000&search=' . rawurlencode($ip));
            if ($e) {
                $errors[] = $e;
                continue;
            }
            foreach ((array) ($j['data'] ?? []) as $q) {
                if (($q['client'] ?? '') !== $ip) {
                    continue;
                }
                $name = strtolower(rtrim((string) ($q['question']['name'] ?? ''), '.'));
                if ($name === '') {
                    continue;
                }
                $isBlocked = str_starts_with((string) ($q['reason'] ?? ''), 'Filtered');
                $d = &$dom[$name];
                $d['n'] = ($d['n'] ?? 0) + 1;
                $d['blocked'] = ($d['blocked'] ?? false) || $isBlocked;
                $d['last'] = max((string) ($d['last'] ?? ''), (string) ($q['time'] ?? ''));
                unset($d);
                $total++;
                $blocked += (int) $isBlocked;
                $first = $first === null || ($q['time'] ?? '') < $first ? (string) ($q['time'] ?? '') : $first;
            }
        }
        uasort($dom, fn($a, $b) => $b['n'] <=> $a['n']);
        $list = [];
        foreach (array_slice($dom, 0, 40, true) as $name => $d) {
            $list[] = ['domain' => $name, 'n' => $d['n'], 'blocked' => $d['blocked'], 'last' => $d['last']];
        }
        return ['domains' => $list, 'total' => $total, 'blocked' => $blocked, 'unique' => count($dom), 'since' => $first, 'errors' => $errors];
    }

    /** IP-adres op (of van) de lijst "Niet toegestane clients", op elke instantie. @return true|string */
    public static function adguardDisallow(string $ip, bool $add): true|string
    {
        if (!self::adguardOn()) {
            return t('AdGuard Home is niet ingesteld');
        }
        $errors = [];
        foreach (self::adguardUrls() as $base) {
            [$j, $e] = self::ag($base, 'access/list');
            if ($e) {
                $errors[] = $e;
                continue;
            }
            $dis = array_values(array_filter((array) ($j['disallowed_clients'] ?? []), fn($x) => $x !== $ip));
            if ($add) {
                $dis[] = $ip;
            }
            [, $e] = self::ag($base, 'access/set', ['allowed_clients' => (array) ($j['allowed_clients'] ?? []), 'disallowed_clients' => $dis,
                'blocked_hosts' => (array) ($j['blocked_hosts'] ?? [])]);
            if ($e) {
                $errors[] = $e;
            }
        }
        return $errors ? implode(' · ', $errors) : true;
    }

    /** Statistiek van AdGuard Home (1.106.0, voor 📊 Analyse): verzoeken, geblokkeerd, top-clients en top-geblokkeerde domeinen, alle instanties samen */
    public static function adguardStats(): array
    {
        $out = ['queries' => 0, 'blocked' => 0, 'clients' => [], 'blocked_domains' => [], 'at' => time()];
        foreach (self::adguardUrls() as $base) {
            [$j, $e] = self::ag($base, 'stats');
            if ($e || !is_array($j)) {
                continue;
            }
            $out['queries'] += (int) ($j['num_dns_queries'] ?? 0);
            $out['blocked'] += (int) ($j['num_blocked_filtering'] ?? 0);
            foreach (['top_clients' => 'clients', 'top_blocked_domains' => 'blocked_domains'] as $src => $dst) {
                foreach ((array) ($j[$src] ?? []) as $row) {
                    foreach ((array) $row as $k => $n) {
                        $out[$dst][(string) $k] = ($out[$dst][(string) $k] ?? 0) + (int) $n;
                    }
                }
            }
        }
        foreach (['clients', 'blocked_domains'] as $k) {
            arsort($out[$k]);
            $out[$k] = array_slice($out[$k], 0, 10, true);
        }
        return $out;
    }

    // ------------------------------------------------------------------ testen (instellingen)

    public static function test(): array
    {
        $out = [];
        if (setting('unifi_url', '') !== '') {
            $u = self::unifiClients();
            $out['unifi'] = $u['ok']
                ? ['ok' => true, 'msg' => t('{n} clients gevonden ({mode})', ['n' => count($u['clients']), 'mode' => ['unifios' => 'UniFi OS', 'classic' => t('Network-app'), 'apikey' => t('API-sleutel')][$u['mode']] ?? $u['mode']])
                    . ' · ' . (self::unifiCanBlock() ? t('blokkeren kan') : t('blokkeren kan niet (alleen API-sleutel)'))]
                : ['ok' => false, 'msg' => $u['error']];
        }
        foreach (self::adguardUrls() as $base) {
            [$j, $e] = self::ag($base, 'status');
            $out['adguard'][] = $e ? ['ok' => false, 'msg' => $e]
                : ['ok' => true, 'msg' => parse_url($base, PHP_URL_HOST) . ': AdGuard Home ' . ($j['version'] ?? '?') . (empty($j['protection_enabled']) ? ' · ' . t('bescherming staat uit') : '')];
        }
        return $out;
    }

    /** IP-adressen van de controller en de AdGuard-servers (nooit weren) */
    public static function ownIps(): array
    {
        $out = [];
        foreach (array_merge([(string) setting('unifi_url', '')], self::adguardUrls()) as $u) {
            $h = (string) parse_url($u, PHP_URL_HOST);
            if ($h !== '') {
                $out = array_merge($out, filter_var($h, FILTER_VALIDATE_IP) ? [$h] : (gethostbynamel($h) ?: []));
            }
        }
        return array_values(array_unique($out));
    }
}
