<?php
declare(strict_types=1);

/**
 * 1.170.0: drie checks voor het netwerk, vanaf de server van RzSense (WireGuard via de agent):
 * - domain: wanneer verloopt een domein? Via RDAP (de opvolger van WHOIS), met de lijst van IANA welke server bij welke
 *   extensie hoort. Sommige registries (zoals .nl) publiceren geen verloopdatum: dan controleert RzSense of het domein nog
 *   geregistreerd en actief is. Hooguit eens per 12 uur opgevraagd.
 * - mqtt: werkt de broker echt? Inloggen, een bericht sturen en het zelf weer ontvangen (de rondgang in ms), en eventueel
 *   een topic bekijken (bv. zigbee2mqtt/bridge/state moet "online" bevatten). MQTT 3.1.1, eventueel via TLS.
 * - vpn: Gluetun via zijn control server (draait de VPN, welk publiek IP, lekt hij niet via je eigen IP), of WireGuard via
 *   de agent (hoe lang geleden de laatste handshake per peer was; agent 1.75.0, als root).
 */
final class NetChecks
{
    public const VPN_METRICS = ['gluetun', 'wg'];
    private const RDAP_BOOT = 'https://data.iana.org/rdap/dns.json';

    public static function run(array $check, array $host): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        return match ($check['type']) {
            'domain' => self::domain($check, $o),
            'mqtt' => self::mqtt($check, $host, $o),
            'vpn' => ($check['metric'] ?? '') === 'wg' ? self::wireguard($check, $host) : self::gluetun($check, $host, $o),
            default => Checks::result(STATUS_UNKNOWN, null, t('Onbekend checktype')),
        };
    }

    // ------------------------------------------------------------------ Domein (RDAP)

    /** Domeinnaam uit wat de gebruiker invulde (ook een URL of met een punt erachter); null = ongeldig */
    public static function domainName(string $in): ?string
    {
        $in = strtolower(trim($in));
        if (str_contains($in, '://')) {
            $in = (string) parse_url($in, PHP_URL_HOST);
        }
        $in = rtrim(preg_replace('/^www\./', '', $in) ?? '', '.');
        if ($in !== '' && preg_match('/[^\x20-\x7e]/', $in) && function_exists('idn_to_ascii')) {
            $in = (string) idn_to_ascii($in, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }
        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $in) ? $in : null;
    }

    private static function domain(array $check, array $o): array
    {
        $name = self::domainName((string) $check['target']);
        if ($name === null) {
            return Checks::result(STATUS_UNKNOWN, null, t('Ongeldige domeinnaam'));
        }
        $cache = is_array($o['_rdap'] ?? null) && ($o['_rdap']['name'] ?? '') === $name ? $o['_rdap'] : null;
        $note = '';
        if (!$cache || (int) ($cache['at'] ?? 0) < time() - 43200) {
            try {
                $fresh = self::rdap($name);
                $cache = $fresh + ['name' => $name, 'at' => time()];
                $o['_rdap'] = $cache;
                if ((int) ($check['id'] ?? 0) > 0) {
                    q('UPDATE checks SET options = ? WHERE id = ?', [json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $check['id']]);
                }
            } catch (RuntimeException $e) {
                if (!$cache) {
                    return Checks::result(STATUS_UNKNOWN, null, t('RDAP niet bereikbaar') . ': ' . mb_substr($e->getMessage(), 0, 150));
                }
                $note = ' · ' . t('laatste gegevens van {t}', ['t' => date('d-m-Y H:i', (int) $cache['at'])]);
            }
        }
        return self::domainResult($check, $cache, $note);
    }

    /** Het oordeel uit de RDAP-gegevens (los, zodat het te testen is zonder netwerk) */
    public static function domainResult(array $check, array $d, string $note = ''): array
    {
        if (!empty($d['missing'])) {
            return Checks::result(STATUS_CRIT, null, t('Domein {d} niet gevonden bij de registry: niet (meer) geregistreerd?', ['d' => $d['name'] ?? '']) . $note);
        }
        $status = array_map('strtolower', (array) ($d['status'] ?? []));
        $bad = array_values(array_filter($status, static fn($s) => (bool) preg_match('/pending ?delete|redemption|quarantine|inactive|expired|hold/', $s)));
        $parts = [];
        if (!empty($d['registrar'])) {
            $parts[] = (string) $d['registrar'];
        }
        $exp = isset($d['exp']) ? (int) $d['exp'] : null;
        $days = null;
        $st = STATUS_OK;
        if ($exp) {
            $days = (int) floor(($exp - time()) / 86400);
            $parts[] = $days < 0 ? t('verlopen op {d}', ['d' => date('d-m-Y', $exp)]) : t('verloopt op {d} (over {n} dagen)', ['d' => date('d-m-Y', $exp), 'n' => $days]);
            $warn = $check['warn_threshold'] !== null ? (float) $check['warn_threshold'] : 30.0;
            $crit = $check['crit_threshold'] !== null ? (float) $check['crit_threshold'] : 14.0;
            $st = $days <= $crit ? STATUS_CRIT : ($days <= $warn ? STATUS_WARN : STATUS_OK);
        } else {
            $parts[] = t('de registry publiceert geen verloopdatum; RzSense let op de status');
        }
        if ($bad) {
            $st = STATUS_CRIT;
            array_unshift($parts, t('status: {s}', ['s' => implode(', ', $bad)]));
        }
        return Checks::result($st, $days === null ? null : (float) $days, t('Geregistreerd') . ' · ' . implode(' · ', $parts) . $note);
    }

    /** RDAP-gegevens van een domein: verloopdatum, status, registrar; missing = niet gevonden */
    private static function rdap(string $name): array
    {
        $labels = explode('.', $name);
        $base = self::rdapBase((string) end($labels));
        if ($base === null) {
            throw new RuntimeException(t('geen RDAP-server bekend voor .{tld}', ['tld' => end($labels)]));
        }
        // sub.example.nl: de registry kent alleen example.nl; kortere namen proberen
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $try = implode('.', array_slice($labels, $i));
            [$code, $body] = self::get(rtrim($base, '/') . '/domain/' . rawurlencode($try), ['Accept: application/rdap+json, application/json']);
            if ($code === 404) {
                continue;
            }
            if ($code !== 200) {
                throw new RuntimeException('HTTP ' . $code);
            }
            $j = json_decode($body, true);
            if (!is_array($j)) {
                throw new RuntimeException(t('onleesbaar antwoord'));
            }
            return self::rdapParse($j);
        }
        return ['missing' => true];
    }

    /** @param array<string, mixed> $j */
    public static function rdapParse(array $j): array
    {
        $exp = null;
        foreach ((array) ($j['events'] ?? []) as $e) {
            if (in_array(strtolower((string) ($e['eventAction'] ?? '')), ['expiration', 'registrar expiration'], true) && ($t = strtotime((string) ($e['eventDate'] ?? '')))) {
                $exp = $exp === null ? $t : min($exp, $t);
            }
        }
        $registrar = '';
        foreach ((array) ($j['entities'] ?? []) as $ent) {
            if (in_array('registrar', (array) ($ent['roles'] ?? []), true)) {
                foreach ((array) ($ent['vcardArray'][1] ?? []) as $v) {
                    if (($v[0] ?? '') === 'fn' && is_string($v[3] ?? null)) {
                        $registrar = mb_substr($v[3], 0, 80);
                    }
                }
            }
        }
        return ['exp' => $exp, 'status' => array_slice(array_map('strval', (array) ($j['status'] ?? [])), 0, 10), 'registrar' => $registrar];
    }

    /** Welke RDAP-server hoort bij een extensie (lijst van IANA, 7 dagen bewaard) */
    private static function rdapBase(string $tld): ?string
    {
        $boot = json_decode((string) setting('rdap_boot', ''), true);
        if (!is_array($boot) || (int) ($boot['at'] ?? 0) < time() - 7 * 86400) {
            [$code, $body] = self::get(self::RDAP_BOOT);
            $j = $code === 200 ? json_decode($body, true) : null;
            if (is_array($j) && is_array($j['services'] ?? null)) {
                $map = [];
                foreach ($j['services'] as $svc) {
                    $url = '';
                    foreach ((array) ($svc[1] ?? []) as $u) {
                        if (is_string($u) && str_starts_with($u, 'https://')) {
                            $url = $u;
                            break;
                        }
                    }
                    foreach ((array) ($svc[0] ?? []) as $t) {
                        if ($url !== '' && is_string($t)) {
                            $map[strtolower($t)] = $url;
                        }
                    }
                }
                $boot = ['at' => time(), 'map' => $map];
                save_setting('rdap_boot', json_encode($boot, JSON_UNESCAPED_SLASHES));
            } elseif (!is_array($boot)) {
                throw new RuntimeException(t('lijst van RDAP-servers (IANA) niet bereikbaar'));
            }
        }
        return $boot['map'][strtolower($tld)] ?? null;
    }

    // ------------------------------------------------------------------ MQTT

    private static function mqtt(array $check, array $host, array $o): array
    {
        $tls = !empty($o['tls']);
        $target = trim((string) $check['target']) ?: (string) $host['address'];
        [$h, $port] = self::hostPort($target, $tls ? 8883 : 1883);
        if ($h === null) {
            return Checks::result(STATUS_UNKNOWN, null, t('Ongeldig adres'));
        }
        if ($why = ExtraChecks::urlBlocked('http://' . (str_contains($h, ':') ? "[$h]" : $h) . '/')) {
            return Checks::result(STATUS_UNKNOWN, null, $why);
        }
        $watch = trim((string) ($o['topic'] ?? ''));
        $rt = ($o['rt'] ?? 1) ? 'rzmon/check/' . (int) ($check['id'] ?? 0) . '/' . bin2hex(random_bytes(4)) : '';
        try {
            $r = self::mqttRun($h, $port, $tls, (string) ($o['user'] ?? ''), (string) ($o['pass'] ?? ''), $rt, $watch, 5.0);
        } catch (RuntimeException $e) {
            return Checks::result(STATUS_CRIT, null, $e->getMessage());
        }
        $parts = [sprintf(t('Ingelogd in %.0f ms'), $r['connect_ms'])];
        $status = STATUS_OK;
        $value = $r['connect_ms'];
        if ($rt !== '') {
            if ($r['rt_ms'] === null) {
                $status = STATUS_CRIT;
                $parts[] = t('eigen testbericht kwam niet terug (rechten op rzmon/check/#?)');
            } else {
                $value = $r['rt_ms'];
                $parts[] = sprintf(t('rondgang %.0f ms'), $r['rt_ms']);
                $status = Checks::threshold($r['rt_ms'], $check);
            }
        }
        if ($watch !== '') {
            $want = trim((string) ($o['expect'] ?? ''));
            if ($r['watch'] === null) {
                $status = max($status, STATUS_WARN);
                $parts[] = t('geen bericht op {t} binnen 5 s (is het bericht bewaard, "retained"?)', ['t' => $watch]);
            } else {
                $shown = mb_substr(preg_replace('/\s+/', ' ', $r['watch']) ?? '', 0, 80);
                $ok = $want === '' || mb_stripos($r['watch'], $want) !== false;
                $parts[] = "$watch = $shown" . ($ok ? '' : ' (' . t('verwacht: {w}', ['w' => $want]) . ')');
                if (!$ok) {
                    $status = STATUS_CRIT;
                }
            }
        }
        return Checks::result($status, round($value, 1), implode(' · ', $parts));
    }

    /**
     * Eén MQTT 3.1.1-sessie: verbinden en inloggen, abonneren, eigen bericht sturen en ontvangen, een topic lezen.
     * @return array{connect_ms: float, rt_ms: ?float, watch: ?string}
     */
    public static function mqttRun(string $h, int $port, bool $tls, string $user, string $pass, string $rtTopic, string $watch, float $timeout): array
    {
        $t0 = microtime(true);
        $ctx = stream_context_create($tls ? ['ssl' => ['peer_name' => $h, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]] : []);
        $addr = ($tls ? 'tls://' : 'tcp://') . (str_contains($h, ':') ? "[$h]" : $h) . ':' . $port;
        $s = @stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new RuntimeException(t('Geen verbinding met {a}', ['a' => "$h:$port"]) . ($errstr !== '' ? ": $errstr" : ''));
        }
        $deadline = $t0 + $timeout;
        stream_set_timeout($s, (int) ceil($timeout));
        $str = static fn(string $v): string => pack('n', strlen($v)) . $v;
        try {
            $flags = 0x02 | ($user !== '' ? 0x80 : 0) | ($user !== '' && $pass !== '' ? 0x40 : 0);
            $payload = $str('rzmon-' . bin2hex(random_bytes(5))) . ($user !== '' ? $str($user) : '') . ($user !== '' && $pass !== '' ? $str($pass) : '');
            self::mqttSend($s, 0x10, $str('MQTT') . chr(4) . chr($flags) . pack('n', 30) . $payload);
            [$type, $body] = self::mqttRead($s, $deadline);
            if ($type !== 0x20 || strlen($body) < 2) {
                throw new RuntimeException(t('Geen MQTT-broker op {a}', ['a' => "$h:$port"]));
            }
            $rc = ord($body[1]);
            if ($rc !== 0) {
                throw new RuntimeException([1 => t('Broker kent MQTT 3.1.1 niet'), 2 => t('Client-ID geweigerd'), 3 => t('Broker niet beschikbaar'),
                    4 => t('Gebruikersnaam of wachtwoord fout'), 5 => t('Geen toegang: gebruikersnaam of wachtwoord fout, of inloggen is verplicht')][$rc] ?? t('Verbinding geweigerd ({n})', ['n' => $rc]));
            }
            $connectMs = (microtime(true) - $t0) * 1000;
            $pid = 1;
            foreach (array_filter([$rtTopic, $watch]) as $topic) {
                self::mqttSend($s, 0x82, pack('n', $pid++) . $str($topic) . chr(0));
            }
            $sent = null;
            $rtMs = null;
            $seen = null;
            $acks = 0;
            $need = count(array_filter([$rtTopic, $watch]));
            while (microtime(true) < $deadline && ($acks < $need || ($rtTopic !== '' && $rtMs === null) || ($watch !== '' && $seen === null))) {
                [$type, $body] = self::mqttRead($s, $deadline, true);
                if ($type === -1) {
                    break;   // tijd om: wat er niet kwam, telt als niet ontvangen
                }
                if ($type === 0x90) {
                    $acks++;
                    if (ord($body[strlen($body) - 1]) === 0x80) {
                        throw new RuntimeException(t('Abonneren geweigerd (rechten op het topic?)'));
                    }
                    if ($acks === $need && $rtTopic !== '' && $sent === null) {
                        $sent = microtime(true);
                        self::mqttSend($s, 0x30, $str($rtTopic) . 'rzmon');
                    }
                } elseif (($type & 0xF0) === 0x30) {
                    $len = unpack('n', substr($body, 0, 2))[1];
                    $topic = substr($body, 2, $len);
                    $msg = substr($body, 2 + $len + ((($type >> 1) & 3) > 0 ? 2 : 0));
                    if ($topic === $rtTopic && $sent !== null) {
                        $rtMs = (microtime(true) - $sent) * 1000;
                    } elseif ($watch !== '' && $topic !== $rtTopic && $seen === null) {
                        $seen = $msg;
                    }
                }
            }
            @fwrite($s, "\xE0\x00");
            return ['connect_ms' => $connectMs, 'rt_ms' => $rtMs, 'watch' => $seen];
        } finally {
            fclose($s);
        }
    }

    /** @param resource $s */
    private static function mqttSend($s, int $type, string $body): void
    {
        $len = strlen($body);
        $rl = '';
        do {
            $b = $len % 128;
            $len = intdiv($len, 128);
            $rl .= chr($b | ($len > 0 ? 0x80 : 0));
        } while ($len > 0);
        if (@fwrite($s, chr($type) . $rl . $body) === false) {
            throw new RuntimeException(t('Verbinding verbroken'));
        }
    }

    /**
     * @param resource $s
     * @return array{0: int, 1: string}
     */
    private static function mqttRead($s, float $deadline, bool $mayTimeOut = false): array
    {
        // Wachten op een nieuw pakket; met $mayTimeOut is "niets binnen de tijd" geen fout maar [-1, '']
        $left = $deadline - microtime(true);
        $r = [$s];
        $w = $e = null;
        if (stream_get_meta_data($s)['unread_bytes'] === 0 && ($left <= 0 || @stream_select($r, $w, $e, (int) floor(max(0, $left)), (int) ((max(0, $left) - floor(max(0, $left))) * 1e6)) === 0)) {
            if ($mayTimeOut) {
                return [-1, ''];
            }
            throw new RuntimeException(t('Geen antwoord binnen de tijd'));
        }
        $read = static function (int $n) use ($s, $deadline): string {
            $buf = '';
            while (strlen($buf) < $n) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException(t('Geen antwoord binnen de tijd'));
                }
                $chunk = fread($s, $n - strlen($buf));
                if ($chunk === false || ($chunk === '' && feof($s))) {
                    throw new RuntimeException(t('Verbinding verbroken door de broker'));
                }
                $buf .= $chunk;
            }
            return $buf;
        };
        $type = ord($read(1));
        $len = 0;
        $mul = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = ord($read(1));
            $len += ($b & 127) * $mul;
            $mul *= 128;
            if (!($b & 128)) {
                break;
            }
        }
        if ($len > 1048576) {
            throw new RuntimeException(t('Bericht te groot'));
        }
        return [$type, $len ? $read($len) : ''];
    }

    // ------------------------------------------------------------------ VPN: Gluetun

    private static function gluetun(array $check, array $host, array $o): array
    {
        $base = rtrim(trim((string) $check['target']) ?: 'http://' . $host['address'] . ':8000', '/');
        if ($why = ExtraChecks::urlBlocked($base)) {
            return Checks::result(STATUS_UNKNOWN, null, $why);
        }
        $hdr = !empty($o['pass']) ? ['X-API-Key: ' . $o['pass']] : [];
        try {
            [$code, $body] = self::get("$base/v1/vpn/status", $hdr, 5);
            if ($code === 404) {
                [$code, $body] = self::get("$base/v1/openvpn/status", $hdr, 5);   // oudere Gluetun
            }
            if ($code === 401 || $code === 403) {
                return Checks::result(STATUS_CRIT, null, t('De control server van Gluetun vraagt een API-sleutel (of de route staat niet open)'));
            }
            $vpn = (string) (json_decode($body, true)['status'] ?? '');
            [$c2, $b2] = self::get("$base/v1/publicip/ip", $hdr, 5);
            $ip = $c2 === 200 ? (json_decode($b2, true) ?: []) : [];
        } catch (RuntimeException $e) {
            return Checks::result(STATUS_CRIT, null, t('Control server van Gluetun niet bereikbaar') . ': ' . mb_substr($e->getMessage(), 0, 150));
        }
        return self::gluetunResult($vpn, $ip, self::ownPublicIp(), trim((string) ($o['country'] ?? '')));
    }

    /** Het oordeel over Gluetun (los, zodat het te testen is) */
    public static function gluetunResult(string $vpn, array $ip, ?string $own, string $country): array
    {
        if ($vpn !== 'running') {
            return Checks::result(STATUS_CRIT, null, t('VPN draait niet') . ($vpn !== '' ? " ($vpn)" : ''));
        }
        $pub = (string) ($ip['public_ip'] ?? '');
        if ($pub === '') {
            return Checks::result(STATUS_WARN, null, t('VPN draait, maar nog geen publiek IP bekend'));
        }
        $where = implode(', ', array_filter([(string) ($ip['country'] ?? ''), (string) ($ip['city'] ?? '')]));
        $msg = t('VPN draait') . " · $pub" . ($where !== '' ? " · $where" : '') . (!empty($ip['organization']) ? ' · ' . $ip['organization'] : '');
        if ($own !== null && $pub === $own) {
            return Checks::result(STATUS_CRIT, null, t('VPN lekt: het verkeer gaat via je eigen IP {ip}', ['ip' => $own]));
        }
        if ($country !== '' && mb_stripos((string) ($ip['country'] ?? ''), $country) === false) {
            return Checks::result(STATUS_WARN, null, $msg . ' · ' . t('verwacht land: {c}', ['c' => $country]));
        }
        return Checks::result(STATUS_OK, null, $msg);
    }

    /** Het publieke IP van de internetlijn van RzSense zelf (Cloudflare), 15 minuten bewaard; null = onbekend */
    private static function ownPublicIp(): ?string
    {
        $c = json_decode((string) setting('own_public_ip', ''), true);
        if (is_array($c) && (int) ($c['at'] ?? 0) > time() - 900) {
            return $c['ip'] ?: null;
        }
        $ip = '';
        try {
            [$code, $body] = self::get('https://1.1.1.1/cdn-cgi/trace', [], 5);
            if ($code === 200 && preg_match('/^ip=([0-9a-f:.]+)$/mi', $body, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
                $ip = $m[1];
            }
        } catch (RuntimeException) {
            // onbekend: dan geen lekcontrole
        }
        save_setting('own_public_ip', json_encode(['at' => time(), 'ip' => $ip]));
        return $ip ?: null;
    }

    // ------------------------------------------------------------------ VPN: WireGuard (via de agent)

    private static function wireguard(array $check, array $host): array
    {
        if ($stale = Checks::agentStale($host)) {
            return $stale;
        }
        $list = json_decode((string) Checks::agentMetric($host, 'wg'), true);
        if (!is_array($list)) {
            return Checks::result(STATUS_UNKNOWN, null, t('Agent meldt geen WireGuard (agent 1.75.0 als root nodig, en het commando wg)'));
        }
        return self::wgResult($check, $list);
    }

    /** Het oordeel over WireGuard-peers: de oudste handshake telt (leeg doel = alle peers, "wg0" = alle peers van wg0) */
    public static function wgResult(array $check, array $list): array
    {
        $want = trim((string) $check['target']);
        $peers = array_values(array_filter($list, static fn($p) => $want === '' || ($p['id'] ?? '') === $want || ($p['iface'] ?? '') === $want));
        if (!$peers) {
            return Checks::result(STATUS_UNKNOWN, null, $want === '' ? t('Geen WireGuard-peers gevonden') : t('Peer of interface {p} niet gevonden', ['p' => $want]));
        }
        $worst = null;
        foreach ($peers as $p) {
            $hs = (int) ($p['hs'] ?? -1);
            if ($worst === null || $hs < 0 || ($worst['hs'] >= 0 && $hs > $worst['hs'])) {
                $worst = ['hs' => $hs] + $p;
            }
        }
        $label = ($worst['iface'] ?? '') . (count($peers) > 1 || $want !== ($worst['iface'] ?? '') ? ' · peer ' . ($worst['peer'] ?? '?') : '');
        if ($worst['hs'] < 0) {
            return Checks::result(STATUS_CRIT, null, $label . ' · ' . t('nog nooit een handshake: de tunnel is niet opgebouwd'));
        }
        $traffic = isset($worst['rx'], $worst['tx']) ? ' · ↓ ' . Checks::bytes((float) $worst['rx']) . ' ↑ ' . Checks::bytes((float) $worst['tx']) : '';
        $msg = $label . ' · ' . t('laatste handshake {t} geleden', ['t' => SnmpMonitor::duration($worst['hs'])]) . $traffic
            . (count($peers) > 1 ? ' · ' . t('{n} peers', ['n' => count($peers)]) : '') . (!empty($worst['endpoint']) ? ' · ' . $worst['endpoint'] : '');
        return Checks::result(Checks::threshold((float) $worst['hs'], $check), (float) $worst['hs'], $msg);
    }

    // ------------------------------------------------------------------ hulp

    /** @return array{0: ?string, 1: int} */
    private static function hostPort(string $target, int $default): array
    {
        if (preg_match('/^\[([0-9a-f:]+)\](?::(\d{1,5}))?$/i', $target, $m)) {
            return [$m[1], (int) ($m[2] ?? $default) ?: $default];
        }
        if (substr_count($target, ':') === 1) {
            [$h, $p] = explode(':', $target);
            return [preg_match('/^[A-Za-z0-9._-]+$/', $h) && ctype_digit($p) && (int) $p > 0 && (int) $p < 65536 ? $h : null, (int) $p];
        }
        return [preg_match('/^[A-Za-z0-9._-]+$/', $target) || filter_var($target, FILTER_VALIDATE_IP) ? $target : null, $default];
    }

    /** @return array{0: int, 1: string} */
    private static function get(string $url, array $headers = [], int $timeout = 10): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code === 0) {
            throw new RuntimeException($err ?: t('geen verbinding'));
        }
        return [$code, (string) $raw];
    }
}
