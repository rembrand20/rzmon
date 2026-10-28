<?php
declare(strict_types=1);

/**
 * DNS-controle via AdGuard Home (1.113.0): elke minuut leest RzMon de nieuwe regels uit de query-log van je
 * AdGuard-instanties (Koppelingen → AdGuard) en kijkt per apparaat:
 * - vraagt het een domein op dat bij URLhaus of ThreatFox als malware/botnet bekend is (lijsten van de
 *   dreigingsinformatie), of dat AdGuard zelf als gevaarlijk tegenhield (Safe Browsing);
 * - wijst het antwoord naar een bekende aanvaller (blocklists, ThreatFox);
 * - lijkt het op een DNS-tunnel (veel lange, unieke namen onder één domein) of op malware die domeinen verzint
 *   (veel willekeurige namen die niet bestaan).
 * Zo zie je ook apparaten zonder agent: camera's, tv's, IoT. Is het apparaat een van je servers, dan telt het ook
 * mee voor de ketens. Er gaat niets naar buiten: alles wordt vergeleken met de lijsten op de RzMon-server.
 */
final class DnsWatch
{
    /** Voor de tests: fn(string $base, ?string $olderThan): array{0: ?array, 1: ?string} [antwoord, fout] */
    public static ?Closure $fetch = null;
    /** Domeinen waar lange, unieke namen normaal zijn (CDN's, clouddiensten, omgekeerde DNS, Plex) */
    private const LONG_OK = '/(^|\.)(in-addr\.arpa|ip6\.arpa|home\.arpa|local|lan|amazonaws\.com|cloudfront\.net|akamaiedge\.net|akamai\.net|akadns\.net|edgekey\.net|'
        . 'googlevideo\.com|gvt1\.com|1e100\.net|azureedge\.net|trafficmanager\.net|msedge\.net|cloudflare\.net|cloudflare-dns\.com|fastly\.net|apple\.com|icloud\.com|'
        . 'aaplimg\.com|spotify\.com|plex\.direct|ts\.net|tailscale\.com|sophosxl\.net|spamhaus\.org|barracudacentral\.org|duckdns\.org|nflxvideo\.net)$/';
    private const TUNNEL_MIN = 40;
    private const DGA_MIN = 30;

    public static function on(): bool
    {
        return Ids::enabled() && setting('dns_on', '1') === '1' && NetLinks::adguardOn();
    }

    private static function state(): array
    {
        return json_decode((string) q("SELECT value FROM settings WHERE name = 'dns_state'")->fetchColumn(), true) ?: [];
    }

    private static function setState(array $set): void
    {
        save_setting('dns_state', json_encode(array_replace(self::state(), $set)));
    }

    /** Elke minuut: de nieuwe regels van elke AdGuard-instantie */
    public static function cron(): ?string
    {
        if (!self::on()) {
            return null;
        }
        $st = self::state();
        $last = (array) ($st['last'] ?? []);
        $errors = [];
        $total = 0;
        $found = 0;
        foreach (NetLinks::adguardUrls() as $base) {
            $since = (float) ($last[$base] ?? 0);
            $entries = [];
            $older = null;
            $newest = $since;
            for ($page = 0; $page < 4; $page++) {
                [$j, $e] = self::$fetch ? (self::$fetch)($base, $older) : NetLinks::ag($base, 'querylog?limit=500' . ($older ? '&older_than=' . rawurlencode($older) : ''));
                if ($e) {
                    $errors[] = $e;
                    break;
                }
                $data = (array) ($j['data'] ?? []);
                $done = false;
                foreach ($data as $q) {
                    $t = self::time((string) ($q['time'] ?? ''));
                    if ($t <= $since) {
                        $done = true;
                        break;
                    }
                    $newest = max($newest, $t);
                    $entries[] = $q;
                }
                if ($done || $since === 0.0 || count($data) < 500 || empty($j['oldest'])) {
                    break;
                }
                $older = (string) $j['oldest'];
            }
            $last[$base] = $newest;
            if ($since > 0.0) {   // de eerste keer alleen vastleggen waar we zijn
                $total += count($entries);
                $found += self::check($entries);
            }
        }
        $day = date('Y-m-d');
        $today = ($st['day'] ?? '') === $day;
        self::setState(['last' => $last, 'at' => time(), 'day' => $day, 'n' => ($today ? (int) ($st['n'] ?? 0) : 0) + $total,
            'hits' => ($today ? (int) ($st['hits'] ?? 0) : 0) + $found, 'error' => $errors ? implode(' · ', $errors) : null]);
        return $found ? "DNS: $found verdacht" : null;
    }

    /** "2026-10-28T10:00:00.123456789+01:00" → unix-tijd met fracties */
    private static function time(string $t): float
    {
        $t = (string) preg_replace('/\.(\d{6})\d+/', '.$1', $t);
        $d = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $t) ?: DateTime::createFromFormat('Y-m-d\TH:i:sP', $t);
        return $d ? (float) $d->format('U.u') : 0.0;
    }

    /** Regels uit de query-log beoordelen. @return int aantal meldingen */
    public static function check(array $entries): int
    {
        $n = 0;
        $long = [];
        $nx = [];
        $names = [];
        foreach ($entries as $q) {
            $client = (string) ($q['client'] ?? '');
            $domain = strtolower(rtrim((string) ($q['question']['name'] ?? ''), '.'));
            if ($client === '' || $domain === '' || @inet_pton($client) === false) {
                continue;
            }
            $names[$client] ??= trim((string) ($q['client_info']['name'] ?? ''));
            $reason = (string) ($q['reason'] ?? '');
            $blocked = str_starts_with($reason, 'Filtered');
            $why = null;
            if ($hit = ThreatIntel::domain($domain)) {
                $why = ['src' => $hit['src'] === 'urlhaus' ? 'URLhaus' : 'ThreatFox', 'info' => $hit['info'] !== '' && $hit['info'] !== 'URLhaus' ? $hit['info'] : t('malware')];
            } elseif ($reason === 'FilteredSafeBrowsing') {
                $why = ['src' => 'AdGuard Safe Browsing', 'info' => t('malware of phishing')];
            } else {
                foreach ((array) ($q['answer'] ?? []) as $a) {
                    $ip = (string) ($a['value'] ?? '');
                    if (in_array($a['type'] ?? '', ['A', 'AAAA'], true) && @inet_pton($ip) !== false && !Ids::internalIp($ip)) {
                        $tf = ThreatIntel::listed('i', $ip);
                        $bad = IpIntel::bad($ip) ?? ($tf && $tf['src'] === 'threatfox' ? 'ThreatFox (' . $tf['info'] . ')' : null);
                        if ($bad !== null) {
                            $why = ['src' => $bad, 'info' => t('wijst naar {ip}', ['ip' => $ip])];
                            break;
                        }
                    }
                }
            }
            if ($why) {
                $n += self::raise($client, $names[$client], 'dns_bad', "$client|$domain", $blocked ? 1 : 2,
                    t('{who} vroeg {domain} op ({src})', ['who' => self::who($client, $names[$client]), 'domain' => $domain, 'src' => $why['src']]),
                    $why['info'] . ' · ' . ($blocked ? t('AdGuard blokkeerde het') : t('AdGuard liet het door')) . ' · ' . t('Waarschijnlijk is dit apparaat besmet, of opende iemand een gevaarlijke link.'),
                    $blocked ? 50 : 80, ['T1071.004']);
            }
            // Statistiek voor tunnel en verzonnen domeinen
            $labels = explode('.', $domain);
            $base = self::base($domain);
            if (!preg_match(self::LONG_OK, $domain) && count($labels) > 2 && strlen($labels[0]) >= 25) {
                $long[$client][$base][$labels[0]] = true;
            }
            if (($q['status'] ?? '') === 'NXDOMAIN' && !preg_match(self::LONG_OK, $domain) && self::random($labels[count($labels) > 1 ? count($labels) - 2 : 0])) {
                $nx[$client][$domain] = true;
            }
        }
        foreach ($long as $client => $bases) {
            foreach ($bases as $base => $subs) {
                if (count($subs) >= self::TUNNEL_MIN) {
                    $n += self::raise($client, $names[$client] ?? '', 'dns_odd', "$client|tunnel|$base", 1,
                        t('Mogelijk DNS-tunnel: {who} → {n} lange namen onder {base}', ['who' => self::who($client, $names[$client] ?? ''), 'n' => count($subs), 'base' => $base]),
                        t('Veel unieke, lange namen onder één domein: zo smokkelt malware gegevens via DNS naar buiten. Soms doet een clouddienst of beveiligingssoftware dit ook; klik dan "Dit is normaal".'),
                        40, ['T1071.004', 'T1048']);
                }
            }
        }
        foreach ($nx as $client => $doms) {
            if (count($doms) >= self::DGA_MIN) {
                $n += self::raise($client, $names[$client] ?? '', 'dns_odd', "$client|dga", 1,
                    t('Veel willekeurige domeinen die niet bestaan: {who} ({n})', ['who' => self::who($client, $names[$client] ?? ''), 'n' => count($doms)]),
                    t('Zo zoekt malware zijn server: hij verzint domeinnamen tot er een bestaat. Voorbeelden: {list}', ['list' => implode(', ', array_slice(array_keys($doms), 0, 3))]),
                    40, ['T1568.002']);
            }
        }
        return $n;
    }

    /** Hoofddomein, ongeveer (voorbeeld.nl, voorbeeld.co.uk) */
    private static function base(string $domain): string
    {
        $l = explode('.', $domain);
        $k = count($l) >= 3 && strlen($l[count($l) - 1]) === 2 && in_array($l[count($l) - 2], ['co', 'com', 'org', 'net', 'ac', 'gov', 'edu'], true) ? 3 : 2;
        return implode('.', array_slice($l, -$k));
    }

    /** Ziet een label eruit als verzonnen? (lang, weinig klinkers of veel cijfers) */
    private static function random(string $label): bool
    {
        $len = strlen($label);
        if ($len < 10) {
            return false;
        }
        $vowels = preg_match_all('/[aeiou]/', $label);
        $digits = preg_match_all('/\d/', $label);
        return $vowels / $len < 0.25 || $digits / $len > 0.3;
    }

    private static function who(string $ip, string $name): string
    {
        $host = self::host($ip);
        return $host ? $host['name'] : ($name !== '' ? "$name ($ip)" : $ip);
    }

    /** Is het apparaat een van je servers? */
    private static function host(string $ip): ?array
    {
        static $cache = [];
        return $cache[$ip] ??= (q("SELECT * FROM hosts WHERE address = ? OR CONCAT(' ', COALESCE(agent_ips, ''), ' ') LIKE ? LIMIT 1", [$ip, "% $ip %"])->fetch() ?: null);
    }

    /** Incident (bij de server zelf, of anders bij RzMon), en een signaal als het een server is */
    private static function raise(string $client, string $name, string $type, string $key, int $sev, string $title, string $detail, int $score, array $techs): int
    {
        $host = self::host($client);
        $on = $host ?: (q("SELECT * FROM hosts WHERE mode = 'local' ORDER BY id LIMIT 1")->fetch() ?: q('SELECT * FROM hosts ORDER BY id LIMIT 1')->fetch());
        if (!$on) {
            return 0;
        }
        if (Ids::isRzmon($client)) {   // RzMon zelf (1.114.0): toegestaan, alleen loggen
            Edr::allowed($on, 'd', "dns|$key", ['title' => $title, 'detail' => $detail]);
            return 0;
        }
        $id = Ids::raise($on, $sev, $type, $key, $title, $detail);
        if ($host) {
            Chain::signal($host, 'ids', $type, ['command-and-control'], $techs, $title, $score, null, $id, "dns|$key", null, 60);
        }
        return 1;
    }

    /** Stand voor de instellingen */
    public static function status(): array
    {
        $st = self::state();
        $today = ($st['day'] ?? '') === date('Y-m-d');
        return ['on' => self::on(), 'adguard' => NetLinks::adguardOn(), 'at' => isset($st['at']) ? date('Y-m-d H:i', (int) $st['at']) : null,
            'n' => $today ? (int) ($st['n'] ?? 0) : 0, 'hits' => $today ? (int) ($st['hits'] ?? 0) : 0, 'error' => $st['error'] ?? null];
    }
}
