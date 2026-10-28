<?php
declare(strict_types=1);

/**
 * Dreigingsinformatie van internet (1.112.0, Instellingen → IDS → Dreigingsinformatie). RzMon vraagt na wat bekend is:
 *
 * - per IP-adres (bij een IDS-incident of een nieuwe uitgaande verbinding):
 *   - AbuseIPDB (met je eigen API-sleutel): hoe vaak gemeld, door hoeveel mensen, soort netwerk, provider;
 *   - GreyNoise (community, gratis): scant dit IP het hele internet, is het een bekende dienst (Google, Shodan …);
 *   - Shodan InternetDB (gratis): open poorten, lekken en labels (c2, malware …) van dat IP zelf;
 *   - lijsten die RzMon dagelijks ophaalt: Tor-uitgangen, abuse.ch Feodo Tracker (botnet-C2, bij de blocklists)
 *     en, met een abuse.ch-sleutel, ThreatFox;
 * - per bestand (nieuw programma of script, sha256): CIRCL hashlookup (bekende goede bestanden) en, met een
 *   abuse.ch-sleutel, MalwareBazaar (bekende malware);
 * - per domein (AdGuard, ronde 4): URLhaus (abuse.ch) en ThreatFox.
 *
 * Uit alles komt een oordeel (score 0–100: kwaadaardig, verdacht, scanner, onschuldig, Tor). Opzoeken gaat via een
 * wachtrij in de cron (niet tijdens het rapport van een agent), met een cache van een dag en een limiet per dag.
 * Daarna: het oordeel bij het incident, een bekende aanvaller automatisch blokkeren (modus Blokkeren), een login van
 * een bekende aanvaller critical maken, een webaanval van een onschuldige scanner afhandelen, en (als je dat aanzet)
 * geblokkeerde aanvallers melden bij AbuseIPDB. Er gaat alleen het IP-adres naar buiten (bij melden ook de soort
 * aanval), nooit namen van servers of gebruikers.
 */
final class ThreatIntel
{
    /** Voor de tests: fn(string $method, string $url, array $headers, ?string $body): array{0: int, 1: string} */
    public static ?Closure $http = null;
    private const URLS = [
        'abuse' => 'https://api.abuseipdb.com/api/v2/',
        'greynoise' => 'https://api.greynoise.io/v3/community/',
        'shodan' => 'https://internetdb.shodan.io/',
        'circl' => 'https://hashlookup.circl.lu/lookup/sha256/',
        'bazaar' => 'https://mb-api.abuse.ch/api/v1/',
        'threatfox' => 'https://threatfox-api.abuse.ch/api/v1/',
        'tor' => 'https://check.torproject.org/torbulkexitlist',
        'urlhaus' => 'https://urlhaus.abuse.ch/downloads/hostfile/',
    ];
    /** Hooguit zoveel opzoekingen per dag (de gratis limieten, met marge) */
    private const DAILY = ['abuse' => 900, 'greynoise' => 45, 'shodan' => 2000, 'circl' => 2000, 'bazaar' => 2000, 'report' => 900];
    /** AbuseIPDB-categorieën bij melden (https://www.abuseipdb.com/categories) */
    private const REPORT_CAT = ['ssh' => [18, 22], 'web' => [21], 'scan' => [14], 'other' => [15]];
    private static array $mem = [];

    public static function on(): bool
    {
        return Ids::enabled() && setting('ti_on', '1') === '1';
    }

    private static function key(string $name): string
    {
        return trim((string) setting($name, ''));
    }

    // ------------------------------------------------------------------ opzoeken (uit de cache)

    /** Wat er over dit IP bekend is (cache van een dag), of null. $live: nu opzoeken als het er niet is (venster van een incident). */
    public static function ip(string $ip, bool $live = false): ?array
    {
        if (!self::on() || @inet_pton($ip) === false || Ids::internalIp($ip) || ip_in_list($ip, PRIVATE_NETWORKS)) {
            return null;
        }
        $row = q('SELECT * FROM ti_ip WHERE ip = ? AND checked_at > NOW() - INTERVAL 1 DAY', [$ip])->fetch();
        if (!$row && $live) {
            return self::lookupIp($ip);
        }
        return $row ? (json_decode((string) $row['data'], true) ?: []) + ['score' => (int) $row['score'], 'verdict' => $row['verdict'], 'checked_at' => $row['checked_at']] : null;
    }

    /** Op een van de lijsten (Tor, ThreatFox)? Zonder iets naar buiten te sturen. @return array{src: string, info: string}|null */
    public static function listed(string $kind, string $value): ?array
    {
        $r = q('SELECT src, info FROM ti_list WHERE kind = ? AND value = ? LIMIT 1', [$kind, mb_strtolower($value)])->fetch();
        return $r ?: null;
    }

    /** Domein (of een bovenliggend domein) op URLhaus of ThreatFox? */
    public static function domain(string $domain): ?array
    {
        $d = rtrim(mb_strtolower(trim($domain)), '.');
        for ($i = 0; $d !== '' && $i < 6; $i++) {
            if ($r = self::listed('d', $d)) {
                return $r + ['domain' => $d];
            }
            $d = str_contains($d, '.') ? substr($d, strpos($d, '.') + 1) : '';
            if (!str_contains($d, '.')) {
                break;   // nooit alleen een topdomein (com, nl)
            }
        }
        return null;
    }

    /** Alle bronnen voor één IP, en het oordeel; bewaard in ti_ip */
    public static function lookupIp(string $ip): array
    {
        $d = ['ip' => $ip, 'src' => []];
        if (($k = self::key('ti_abuseipdb_key')) !== '' && self::budget('abuse')) {
            [$code, $body] = self::request('GET', self::url('abuse') . 'check?' . http_build_query(['ipAddress' => $ip, 'maxAgeInDays' => 90]), ['Key: ' . $k, 'Accept: application/json']);
            $j = json_decode($body, true)['data'] ?? null;
            if ($code === 200 && is_array($j)) {
                $d['abuse'] = ['score' => (int) ($j['abuseConfidenceScore'] ?? 0), 'reports' => (int) ($j['totalReports'] ?? 0), 'users' => (int) ($j['numDistinctUsers'] ?? 0),
                    'usage' => mb_substr((string) ($j['usageType'] ?? ''), 0, 60), 'isp' => mb_substr((string) ($j['isp'] ?? ''), 0, 80), 'domain' => mb_substr((string) ($j['domain'] ?? ''), 0, 80),
                    'cc' => (string) ($j['countryCode'] ?? ''), 'tor' => !empty($j['isTor']), 'white' => !empty($j['isWhitelisted']), 'last' => (string) ($j['lastReportedAt'] ?? '')];
                $d['src'][] = 'AbuseIPDB';
            } else {
                self::error('abuse', $code, $body);
            }
        }
        if (setting('ti_greynoise', '1') === '1' && self::budget('greynoise')) {
            $k = self::key('ti_greynoise_key');
            [$code, $body] = self::request('GET', self::url('greynoise') . rawurlencode($ip), $k !== '' ? ['key: ' . $k, 'Accept: application/json'] : ['Accept: application/json']);
            $j = json_decode($body, true);
            if (($code === 200 || $code === 404) && is_array($j)) {
                $d['gn'] = ['noise' => !empty($j['noise']), 'riot' => !empty($j['riot']), 'class' => (string) ($j['classification'] ?? ''), 'name' => mb_substr((string) ($j['name'] ?? ''), 0, 80),
                    'last' => (string) ($j['last_seen'] ?? '')];
                $d['src'][] = 'GreyNoise';
            } else {
                self::error('greynoise', $code, $body);
            }
        }
        if (setting('ti_shodan', '1') === '1' && self::budget('shodan')) {
            [$code, $body] = self::request('GET', self::url('shodan') . rawurlencode($ip), ['Accept: application/json']);
            $j = json_decode($body, true);
            if ($code === 200 && is_array($j)) {
                $d['shodan'] = ['ports' => array_slice(array_map('intval', (array) ($j['ports'] ?? [])), 0, 30), 'tags' => array_slice(array_map('strval', (array) ($j['tags'] ?? [])), 0, 10),
                    'vulns' => array_slice(array_map('strval', (array) ($j['vulns'] ?? [])), 0, 20), 'nvulns' => count((array) ($j['vulns'] ?? [])),
                    'hostnames' => array_slice(array_map('strval', (array) ($j['hostnames'] ?? [])), 0, 5)];
                $d['src'][] = 'Shodan';
            } elseif ($code === 404) {
                $d['shodan'] = ['ports' => [], 'tags' => [], 'vulns' => [], 'nvulns' => 0, 'hostnames' => []];
                $d['src'][] = 'Shodan';
            } else {
                self::error('shodan', $code, $body);
            }
        }
        $lists = array_column(q("SELECT src, info FROM ti_list WHERE kind = 'i' AND value = ?", [$ip])->fetchAll(), 'info', 'src');
        $d['tor'] = isset($lists['tor']) || !empty($d['abuse']['tor']);
        if (isset($lists['threatfox'])) {
            $d['threatfox'] = $lists['threatfox'];
        }
        $d['list'] = IpIntel::bad($ip);
        $d += self::verdict($d);
        if ($d['src'] || $d['list'] || $d['tor'] || isset($d['threatfox'])) {
            q('INSERT INTO ti_ip (ip, checked_at, score, verdict, data) VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), score = VALUES(score), verdict = VALUES(verdict), data = VALUES(data)',
                [$ip, now(), $d['score'], $d['verdict'], json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
        return $d + ['checked_at' => now()];
    }

    /** Het oordeel: score, soort en waarom (in gewone woorden) */
    public static function verdict(array $d): array
    {
        $score = 0;
        $why = [];
        $benign = false;
        if (isset($d['abuse'])) {
            $a = $d['abuse'];
            $score = max($score, $a['score']);
            if ($a['reports'] > 0) {
                $why[] = t('AbuseIPDB {s}% ({n} meldingen van {u} mensen)', ['s' => $a['score'], 'n' => $a['reports'], 'u' => $a['users']]);
            }
            $benign = $benign || $a['white'];
        }
        if (isset($d['gn'])) {
            $g = $d['gn'];
            if ($g['riot']) {
                $benign = true;
                $why[] = t('GreyNoise: bekende dienst ({name})', ['name' => $g['name'] ?: '?']);
            } elseif ($g['class'] === 'malicious') {
                $score = max($score, 80);
                $why[] = t('GreyNoise: kwaadaardig');
            } elseif ($g['class'] === 'benign') {
                $benign = true;
                $why[] = t('GreyNoise: onschuldige scanner ({name})', ['name' => $g['name'] ?: '?']);
            } elseif ($g['noise']) {
                $why[] = t('GreyNoise: scant het hele internet');
            }
        }
        if (isset($d['shodan'])) {
            $bad = array_intersect($d['shodan']['tags'], ['c2', 'malware', 'compromised', 'botnet']);
            if ($bad) {
                $score = max($score, 80);
                $why[] = t('Shodan: {tags}', ['tags' => implode(', ', $bad)]);
            }
            if ($d['shodan']['nvulns'] > 0) {
                $why[] = t('Shodan: {n} lekken op dit IP zelf (misschien zelf gehackt)', ['n' => $d['shodan']['nvulns']]);
            }
        }
        if (!empty($d['list'])) {
            $score = max($score, 90);
            $why[] = '☠️ ' . $d['list'];
        }
        if (!empty($d['threatfox'])) {
            $score = max($score, 90);
            $why[] = t('ThreatFox: {what}', ['what' => $d['threatfox']]);
        }
        if (!empty($d['tor'])) {
            $why[] = t('Tor-uitgang');
        }
        $min = max(1, min(100, (int) setting('ti_block_score', '80')));
        $verdict = match (true) {
            $score >= $min => 'malicious',
            $benign && $score < 50 => 'benign',
            $score >= 40 => 'suspicious',
            !empty($d['tor']) => 'tor',
            !empty($d['gn']['noise']) => 'scanner',
            default => $d['src'] ? 'clean' : 'unknown',
        };
        return ['score' => $score, 'verdict' => $verdict, 'why' => $why];
    }

    /** Korte tekst voor bij een incident */
    public static function summary(array $d): string
    {
        $label = ['malicious' => t('bekende aanvaller'), 'suspicious' => t('verdacht'), 'benign' => t('onschuldig'), 'scanner' => t('scanner'), 'tor' => 'Tor',
            'clean' => t('niet bekend als aanvaller'), 'unknown' => t('onbekend')][$d['verdict']] ?? $d['verdict'];
        return '🌐 ' . $label . ($d['why'] ? ': ' . implode(', ', array_slice($d['why'], 0, 3)) : '');
    }

    // ------------------------------------------------------------------ bestanden

    /** Wat er over een bestand bekend is (cache 7 dagen), of null */
    public static function hash(string $sha): ?array
    {
        $row = q('SELECT * FROM ti_hash WHERE sha = ? AND checked_at > NOW() - INTERVAL 7 DAY', [strtolower($sha)])->fetch();
        return $row ? (json_decode((string) $row['data'], true) ?: []) + ['verdict' => $row['verdict']] : null;
    }

    public static function lookupHash(string $sha): array
    {
        $sha = strtolower($sha);
        $d = ['sha' => $sha, 'src' => []];
        if (setting('ti_circl', '1') === '1' && self::budget('circl')) {
            [$code, $body] = self::request('GET', self::url('circl') . $sha, ['Accept: application/json']);
            $j = json_decode($body, true);
            if ($code === 200 && is_array($j)) {
                $d['circl'] = ['name' => mb_substr((string) ($j['FileName'] ?? ''), 0, 120), 'source' => mb_substr((string) ($j['source'] ?? ($j['db'] ?? '')), 0, 40),
                    'trust' => (int) ($j['hashlookup:trust'] ?? 0), 'bad' => !empty($j['KnownMalicious'])];
                $d['src'][] = 'CIRCL';
            } elseif ($code === 404) {
                $d['src'][] = 'CIRCL';
            } else {
                self::error('circl', $code, $body);
            }
        }
        if (($k = self::key('ti_abusech_key')) !== '' && self::budget('bazaar')) {
            [$code, $body] = self::request('POST', self::url('bazaar'), ['Auth-Key: ' . $k], http_build_query(['query' => 'get_info', 'hash' => $sha]));
            $j = json_decode($body, true);
            if ($code === 200 && is_array($j)) {
                if (($j['query_status'] ?? '') === 'ok' && is_array($j['data'][0] ?? null)) {
                    $m = $j['data'][0];
                    $d['bazaar'] = ['family' => mb_substr((string) ($m['signature'] ?? ''), 0, 60), 'type' => mb_substr((string) ($m['file_type'] ?? ''), 0, 20),
                        'tags' => array_slice(array_map('strval', (array) ($m['tags'] ?? [])), 0, 8), 'first' => (string) ($m['first_seen'] ?? '')];
                }
                $d['src'][] = 'MalwareBazaar';
            } else {
                self::error('bazaar', $code, $body);
            }
        }
        $d['verdict'] = match (true) {
            isset($d['bazaar']) || !empty($d['circl']['bad']) => 'malware',
            isset($d['circl']) => 'known_good',
            default => $d['src'] ? 'unknown' : 'unchecked',
        };
        if ($d['src']) {
            q('INSERT INTO ti_hash (sha, checked_at, verdict, data) VALUES (?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), verdict = VALUES(verdict), data = VALUES(data)',
                [$sha, now(), $d['verdict'], json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
        return $d;
    }

    // ------------------------------------------------------------------ wachtrij

    /** Opzoeken in de cron. $kind: i = IP, h = sha256. $ctx: wat het was (voor een nieuw incident) */
    public static function queue(string $kind, string $value, int $hostId, ?int $incidentId = null, string $ctx = ''): void
    {
        if (!self::on()) {
            return;
        }
        if ($kind === 'i' && (@inet_pton($value) === false || Ids::internalIp($value) || ip_in_list($value, PRIVATE_NETWORKS))) {
            return;
        }
        if ($kind === 'h' && !preg_match('/^[0-9a-f]{64}$/i', $value)) {
            return;
        }
        q('INSERT INTO ti_queue (kind, value, host_id, incident_id, ctx, added_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$kind, strtolower($value), $hostId, $incidentId, mb_substr($ctx, 0, 250), now()]);
    }

    /** Elke minuut: de lijsten (dagelijks), de wachtrij (hooguit 30) en melden bij AbuseIPDB */
    public static function cron(): ?string
    {
        if (!self::on()) {
            return null;
        }
        $msg = [];
        $st = self::state();
        if ((int) ($st['lists_ok'] ?? 0) < time() - 86400 && (int) ($st['lists_try'] ?? 0) < time() - 3600) {
            $msg[] = self::updateLists();
        }
        $n = 0;
        foreach (q('SELECT * FROM ti_queue ORDER BY id LIMIT 30')->fetchAll() as $item) {
            q('DELETE FROM ti_queue WHERE id = ?', [$item['id']]);
            $host = q('SELECT * FROM hosts WHERE id = ?', [$item['host_id']])->fetch();
            if (!$host) {
                continue;
            }
            $item['kind'] === 'h' ? self::handleHash($host, $item) : self::handleIp($host, $item);
            Chain::check($host);
            $n++;
        }
        if ($n) {
            $msg[] = "Dreigingsinformatie: $n opgezocht";
        }
        if ($r = self::reportCron()) {
            $msg[] = $r;
        }
        q('DELETE FROM ti_queue WHERE added_at < NOW() - INTERVAL 1 DAY');
        return $msg ? implode(' · ', $msg) : null;
    }

    private static function handleIp(array $host, array $item): void
    {
        $ip = (string) $item['value'];
        $d = self::ip($ip) ?? self::lookupIp($ip);
        $inc = $item['incident_id'] ? q('SELECT * FROM ids_incidents WHERE id = ?', [$item['incident_id']])->fetch() : null;
        $sum = self::summary($d);
        if ($inc && !str_contains((string) $inc['detail'], '🌐') && $d['verdict'] !== 'unknown') {
            q('UPDATE ids_incidents SET detail = ? WHERE id = ?', [mb_substr($inc['detail'] . ' · ' . $sum, 0, 1000), $inc['id']]);
        }
        if ($d['verdict'] === 'malicious') {
            $done = [];
            if (Ids::blocking() && setting('ti_block', '1') === '1' && ($b = Ids::autoBlock($host, $ip, t('Bekende aanvaller: {why}', ['why' => implode(', ', array_slice($d['why'], 0, 2))])))) {
                $done[] = "⛔ $ip $b";
            }
            if ($inc && in_array($inc['type'], ['ssh_login', 'newip', 'rootlogin'], true) && setting('ti_escalate', '1') === '1') {
                Ids::escalate((int) $inc['id'], t('Ingelogd vanaf een bekende aanvaller ({why})', ['why' => implode(', ', array_slice($d['why'], 0, 2))]));
            }
            if (!$inc) {
                // Geen incident (een nieuwe verbinding naar buiten): nu wel, want het is een bekende aanvaller
                $inc = ['id' => Ids::raise($host, 2, 'ti_ip', $ip, t('Verbinding met een bekende aanvaller: {ip}', ['ip' => $ip]) . ($item['ctx'] !== '' ? ' · ' . $item['ctx'] : ''),
                    $sum . ($done ? ' · ' . implode(', ', $done) : ''), $ip), 'type' => 'ti_ip'];
            } elseif ($done) {
                q('UPDATE ids_incidents SET detail = CONCAT(detail, ?) WHERE id = ?', [' · ' . implode(', ', $done), $inc['id']]);
            }
            Chain::signal($host, 'ids', 'ti_bad', $inc['type'] === 'ti_ip' ? ['command-and-control'] : ['initial-access'], $inc['type'] === 'ti_ip' ? ['T1071'] : [],
                t('Bekende aanvaller: {ip} ({why})', ['ip' => $ip, 'why' => implode(', ', array_slice($d['why'], 0, 2))]), $inc['type'] === 'ti_ip' ? 80 : 50, $ip,
                $inc['id'] ? (int) $inc['id'] : null, "ti|$ip", null, 60);
        } elseif ($d['verdict'] === 'benign' && $inc && $inc['status'] === 'open' && in_array($inc['type'], ['web_attack', 'web_payload'], true)
            && setting('ti_close_benign', '1') === '1') {
            q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE id = ?", ['RzMon (' . t('onschuldige scanner') . ')', $inc['id']]);
        }
    }

    private static function handleHash(array $host, array $item): void
    {
        $sha = (string) $item['value'];
        $d = self::hash($sha) ?? self::lookupHash($sha);
        $inc = $item['incident_id'] ? q('SELECT * FROM ids_incidents WHERE id = ?', [$item['incident_id']])->fetch() : null;
        if ($d['verdict'] === 'known_good' && $inc && !str_contains((string) $inc['detail'], '✔ CIRCL')) {
            q('UPDATE ids_incidents SET detail = ? WHERE id = ?', [mb_substr($inc['detail'] . ' · ✔ CIRCL: ' . t('bekend bestand ({name})', ['name' => $d['circl']['name'] ?: $d['circl']['source']]), 0, 1000), $inc['id']]);
        }
        if ($d['verdict'] !== 'malware') {
            return;
        }
        $fam = $d['bazaar']['family'] ?? '' ?: t('malware');
        $id = Ids::raise($host, 2, 'ti_malware', $sha, t('Bekende malware: {fam} · {path}', ['fam' => $fam, 'path' => $item['ctx'] ?: substr($sha, 0, 16)]),
            t('Dit bestand staat bij {src} als malware. Verwijder het, zoek uit hoe het binnenkwam en controleer de server.', ['src' => isset($d['bazaar']) ? 'MalwareBazaar' : 'CIRCL'])
            . ' · sha256 ' . substr($sha, 0, 16) . '…');
        Edr::evidence($id, 'file', ['path' => $item['ctx'], 'sha' => $sha, '_ti' => $d], 'ti-malware', ['title' => t('Bekende malware: {fam}', ['fam' => $fam]), 'src' => 'ti',
            'tags' => ['T1204.002'], 'author' => '', 'url' => isset($d['bazaar']) ? 'https://bazaar.abuse.ch/sample/' . $sha . '/' : '', 'what' => '', 'fp' => []]);
        Chain::signal($host, 'ids', 'ti_malware', ['execution'], ['T1204.002', 'T1105'], t('Bekende malware: {fam}', ['fam' => $fam]), 80, null, $id, "ti|$sha", null, 60);
    }

    // ------------------------------------------------------------------ melden bij AbuseIPDB

    /** Geblokkeerde aanvallers (automatisch, het laatste uur) melden; hooguit één keer per dag per IP */
    private static function reportCron(): ?string
    {
        if (setting('ti_report', '0') !== '1' || ($k = self::key('ti_abuseipdb_key')) === '') {
            return null;
        }
        $n = 0;
        foreach (q("SELECT b.ip, b.reason FROM ids_blocks b LEFT JOIN ti_ip t ON t.ip = b.ip
                    WHERE b.auto = 1 AND b.created_at >= NOW() - INTERVAL 1 HOUR AND (t.reported_at IS NULL OR t.reported_at < NOW() - INTERVAL 1 DAY)
                    GROUP BY b.ip, b.reason LIMIT 10")->fetchAll() as $b) {
            $ip = (string) $b['ip'];
            if (Ids::internalIp($ip) || Ids::protectedIp($ip) || !self::budget('report')) {
                continue;
            }
            $r = mb_strtolower((string) $b['reason']);
            $cat = match (true) {
                str_contains($r, 'ssh') || str_contains($r, 'brute') || str_contains($r, 'honeypot-gebruiker') || str_contains($r, 'honeypot user') => 'ssh',
                str_contains($r, 'web') || str_contains($r, 'swag') => 'web',
                str_contains($r, 'poort') || str_contains($r, 'port') => 'scan',
                default => 'other',
            };
            $comment = ['ssh' => 'SSH brute force / login attempts', 'web' => 'Web application attack', 'scan' => 'Port scan / honeypot port', 'other' => 'Attack detected'][$cat] . ' (RzMon IDS)';
            [$code, $body] = self::request('POST', self::url('abuse') . 'report', ['Key: ' . $k, 'Accept: application/json'],
                http_build_query(['ip' => $ip, 'categories' => implode(',', self::REPORT_CAT[$cat]), 'comment' => $comment]));
            if ($code === 200 || $code === 429) {   // 429: binnen 15 minuten al gemeld (door ons): ook goed
                q('INSERT INTO ti_ip (ip, checked_at, score, verdict, data, reported_at) VALUES (?, ?, 0, ?, NULL, ?) ON DUPLICATE KEY UPDATE reported_at = VALUES(reported_at)',
                    [$ip, '2000-01-01 00:00:00', 'unknown', now()]);
                $n += $code === 200 ? 1 : 0;
            } else {
                self::error('report', $code, $body);
            }
        }
        if ($n) {
            self::count('reported_total', $n);
        }
        return $n ? "AbuseIPDB: $n gemeld" : null;
    }

    // ------------------------------------------------------------------ lijsten (dagelijks)

    public static function updateLists(): string
    {
        self::setState(['lists_try' => time()]);
        $out = [];
        $errors = [];
        $lists = [];
        if (setting('ti_tor', '1') === '1') {
            [$code, $body] = self::request('GET', self::url('tor'), []);
            if ($code === 200) {
                $lists['tor'] = ['i', array_map(static fn($ip) => [$ip, ''], array_filter(array_map('trim', preg_split('/\R/', $body)), static fn($l) => @inet_pton($l) !== false))];
            } else {
                $errors[] = 'Tor';
            }
        }
        if (setting('ti_urlhaus', '1') === '1') {
            $k = self::key('ti_abusech_key');
            [$code, $body] = self::request('GET', self::url('urlhaus'), $k !== '' ? ['Auth-Key: ' . $k] : []);
            if ($code === 200) {
                $dom = [];
                foreach (preg_split('/\R/', $body) as $l) {
                    if (preg_match('/^\s*(?:127\.0\.0\.1|0\.0\.0\.0)\s+([a-z0-9._-]{3,253})\s*$/i', $l, $m) && str_contains($m[1], '.')) {
                        $dom[] = [mb_strtolower($m[1]), 'URLhaus'];
                    }
                }
                $lists['urlhaus'] = ['d', $dom];
            } else {
                $errors[] = 'URLhaus';
            }
        }
        if (($k = self::key('ti_abusech_key')) !== '') {
            [$code, $body] = self::request('POST', self::url('threatfox'), ['Auth-Key: ' . $k, 'Content-Type: application/json'], json_encode(['query' => 'get_iocs', 'days' => 7]));
            $j = json_decode($body, true);
            if ($code === 200 && is_array($j['data'] ?? null)) {
                $ips = [];
                $dom = [];
                foreach ($j['data'] as $ioc) {
                    $info = mb_substr(trim(($ioc['malware_printable'] ?? '') . ' ' . ($ioc['threat_type'] ?? '')), 0, 120);
                    $v = (string) ($ioc['ioc'] ?? '');
                    if (($ioc['ioc_type'] ?? '') === 'ip:port' && @inet_pton(explode(':', $v)[0]) !== false) {
                        $ips[] = [explode(':', $v)[0], $info];
                    } elseif (($ioc['ioc_type'] ?? '') === 'domain') {
                        $dom[] = [mb_strtolower($v), $info];
                    }
                }
                $lists['threatfox'] = ['i', $ips];
                $lists['threatfox_d'] = ['d', $dom];
            } else {
                $errors[] = 'ThreatFox';
            }
        }
        $n = [];
        foreach ($lists as $src => [$kind, $rows]) {
            $name = $src === 'threatfox_d' ? 'threatfox' : $src;
            db()->beginTransaction();
            q('DELETE FROM ti_list WHERE kind = ? AND src = ?', [$kind, $name]);
            foreach (array_chunk($rows, 500) as $chunk) {
                q('INSERT IGNORE INTO ti_list (kind, value, src, info) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)')),
                    array_merge(...array_map(static fn($r) => [$kind, mb_substr($r[0], 0, 253), $name, mb_substr((string) $r[1], 0, 120)], $chunk)));
            }
            db()->commit();
            $n[$src] = count($rows);
            $out[] = "$name: " . count($rows);
        }
        self::setState(['lists_ok' => $lists ? time() : (self::state()['lists_ok'] ?? null), 'lists_n' => $n + (self::state()['lists_n'] ?? []),
            'lists_error' => $errors ? t('niet op te halen: {list}', ['list' => implode(', ', $errors)]) : null]);
        return 'Dreigingslijsten: ' . ($out ? implode(', ', $out) : '–') . ($errors ? ' (niet: ' . implode(', ', $errors) . ')' : '');
    }

    // ------------------------------------------------------------------ stand, limieten, testen

    private static function state(): array
    {
        return json_decode((string) q("SELECT value FROM settings WHERE name = 'ti_state'")->fetchColumn(), true) ?: [];
    }

    private static function setState(array $set): void
    {
        save_setting('ti_state', json_encode(array_replace(self::state(), $set)));
    }

    /** Nog ruimte vandaag voor deze bron? (en telt er één bij) */
    private static function budget(string $p): bool
    {
        $st = self::state();
        $day = date('Y-m-d');
        $used = ($st['day'] ?? '') === $day ? (int) ($st['used'][$p] ?? 0) : 0;
        if ($used >= self::DAILY[$p] || (int) ($st['pause'][$p] ?? 0) > time()) {
            return false;
        }
        $usedAll = ($st['day'] ?? '') === $day ? (array) ($st['used'] ?? []) : [];
        $usedAll[$p] = $used + 1;
        self::setState(['day' => $day, 'used' => $usedAll]);
        return true;
    }

    private static function count(string $k, int $n): void
    {
        self::setState([$k => (int) (self::state()[$k] ?? 0) + $n]);
    }

    /** Fout van een bron onthouden; bij 401/403/429 die bron een uur (429: tot morgen) overslaan */
    private static function error(string $p, int $code, string $body): void
    {
        $st = self::state();
        $err = (array) ($st['errors'] ?? []);
        $msg = json_decode($body, true)['errors'][0]['detail'] ?? (json_decode($body, true)['message'] ?? '');
        $err[$p] = ['at' => time(), 'msg' => $code ? "HTTP $code" . ($msg !== '' ? ': ' . mb_substr((string) $msg, 0, 120) : '') : t('niet bereikbaar')];
        $pause = (array) ($st['pause'] ?? []);
        if (in_array($code, [401, 403, 429], true)) {
            $pause[$p] = $code === 429 ? strtotime('tomorrow') : time() + 3600;
        }
        self::setState(['errors' => $err, 'pause' => $pause]);
    }

    /** Stand voor de instellingen */
    public static function status(): array
    {
        $st = self::state();
        $today = ($st['day'] ?? '') === date('Y-m-d');
        $n = [];
        foreach (q('SELECT src, COUNT(*) AS n FROM ti_list GROUP BY src')->fetchAll() as $r) {
            $n[$r['src']] = (int) $r['n'];
        }
        return ['lists_at' => isset($st['lists_ok']) ? date('Y-m-d H:i', (int) $st['lists_ok']) : null, 'lists' => $n, 'lists_error' => $st['lists_error'] ?? null,
            'used' => $today ? (array) ($st['used'] ?? []) : [], 'limits' => self::DAILY,
            'errors' => array_map(static fn($e) => ['at' => date('Y-m-d H:i', (int) $e['at']), 'msg' => $e['msg']], (array) ($st['errors'] ?? [])),
            'reported' => (int) ($st['reported_total'] ?? 0), 'cached' => (int) q('SELECT COUNT(*) FROM ti_ip WHERE checked_at > NOW() - INTERVAL 1 DAY')->fetchColumn(),
            'malicious' => (int) q("SELECT COUNT(*) FROM ti_ip WHERE verdict = 'malicious' AND checked_at > NOW() - INTERVAL 7 DAY")->fetchColumn(),
            'has' => ['abuse' => self::key('ti_abuseipdb_key') !== '', 'greynoise' => self::key('ti_greynoise_key') !== '', 'abusech' => self::key('ti_abusech_key') !== '']];
    }

    /** Werkt de sleutel / de bron? (knop Testen) @return array{ok: bool, msg: string} */
    public static function test(string $p): array
    {
        $ip = '8.8.8.8';
        [$code, $body] = match ($p) {
            'abuse' => self::request('GET', self::url('abuse') . 'check?' . http_build_query(['ipAddress' => $ip]), ['Key: ' . self::key('ti_abuseipdb_key'), 'Accept: application/json']),
            'greynoise' => self::request('GET', self::url('greynoise') . $ip, self::key('ti_greynoise_key') !== '' ? ['key: ' . self::key('ti_greynoise_key')] : []),
            'shodan' => self::request('GET', self::url('shodan') . $ip, []),
            'abusech' => self::request('POST', self::url('bazaar'), ['Auth-Key: ' . self::key('ti_abusech_key')], http_build_query(['query' => 'get_recent', 'selector' => 'time'])),
            default => [0, ''],
        };
        if ($code === 200) {
            if ($p === 'abuse') {
                $h = json_decode($body, true)['data'] ?? [];
                return ['ok' => true, 'msg' => t('AbuseIPDB werkt (8.8.8.8: {s}%, {isp})', ['s' => (int) ($h['abuseConfidenceScore'] ?? 0), 'isp' => (string) ($h['isp'] ?? '?')])];
            }
            return ['ok' => true, 'msg' => t('{p} werkt', ['p' => ['greynoise' => 'GreyNoise', 'shodan' => 'Shodan InternetDB', 'abusech' => 'abuse.ch'][$p] ?? $p])];
        }
        $msg = json_decode($body, true)['errors'][0]['detail'] ?? (json_decode($body, true)['message'] ?? (json_decode($body, true)['query_status'] ?? ''));
        return ['ok' => false, 'msg' => ($code === 401 || $code === 403 ? t('Sleutel geweigerd') : ($code ? "HTTP $code" : t('niet bereikbaar'))) . ($msg !== '' ? ': ' . mb_substr((string) $msg, 0, 150) : '')];
    }

    // ------------------------------------------------------------------ HTTP

    /** Ander adres (alleen om te testen): config.php 'ti_urls' => ['abuse' => 'http://127.0.0.1:…/'] */
    private static function url(string $k): string
    {
        return (string) (((array) config('ti_urls', []))[$k] ?? self::URLS[$k]);
    }

    /** @return array{0: int, 1: string} */
    private static function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (self::$http) {
            return (self::$http)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => str_contains($url, 'urlhaus') || str_contains($url, 'threatfox') ? 120 : 10, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION,
            CURLOPT_HTTPHEADER => $headers] + ($method === 'POST' ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) $body] : []));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $code, is_string($raw) ? $raw : ''];
    }

    public static function purge(): void
    {
        q('DELETE FROM ti_ip WHERE checked_at < NOW() - INTERVAL 30 DAY AND (reported_at IS NULL OR reported_at < NOW() - INTERVAL 30 DAY)');
        q('DELETE FROM ti_hash WHERE checked_at < NOW() - INTERVAL 30 DAY');
    }
}
