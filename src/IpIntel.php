<?php
declare(strict_types=1);

/**
 * Wat RzMon weet over een IP-adres (1.82.0, pagina 🛡️ IDS):
 *
 * - Bekende aanvallers: dagelijks de openbare lijsten Spamhaus DROP (IPv4 en IPv6) en FireHOL level 1. In de modus
 *   blokkeren zetten de agents ze in hun firewall; in de modus leren krijgen logins en incidenten het label
 *   ☠️ "bekende aanvaller". Privé- en gereserveerde adressen (je LAN) worden er altijd uit gefilterd.
 * - Land per IP: maandelijks de gratis database DB-IP Lite (land, CC BY 4.0). Daarna gaat er per opzoeking niets naar
 *   buiten. Een SSH-login van buiten vanuit een land dat niet bij "normale landen" staat, is critical.
 *
 * Beide als bereiken (begin en eind, binair zoals inet_pton) in een eigen tabel; overlappende bereiken worden bij het
 * inlezen samengevoegd, zodat opzoeken één indexstap is (het dichtstbijzijnde begin, en dan het eind controleren).
 */
final class IpIntel
{
    public const SOURCES = [
        'spamhaus4' => ['label' => 'Spamhaus DROP', 'url' => 'https://www.spamhaus.org/drop/drop_v4.json'],
        'spamhaus6' => ['label' => 'Spamhaus DROPv6', 'url' => 'https://www.spamhaus.org/drop/drop_v6.json'],
        'firehol1' => ['label' => 'FireHOL level 1', 'url' => 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset'],
    ];
    /** Nooit als aanvaller: privé, loopback, link-local, CGNAT, documentatie, multicast en gereserveerd */
    private const RESERVED = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3',
        '::/8', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32'];
    private const GEO_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';
    /** Hooguit zoveel bereiken naar de agents (de lijsten zijn samen zo'n 6.000) */
    public const MAX_AGENT = 20000;

    private static array $cache = [];

    public static function blocklistsOn(): bool
    {
        return Ids::enabled() && setting('ids_blocklists', '1') === '1';
    }

    public static function geoOn(): bool
    {
        return Ids::enabled() && setting('ids_geo', '1') === '1';
    }

    /** Normale landen (hoofdletters), bv. ['NL'] */
    public static function allowedCountries(): array
    {
        return array_values(array_filter(array_map('strtoupper', parse_list((string) setting('ids_geo_allowed', 'NL'))), fn($c) => (bool) preg_match('/^[A-Z]{2}$/', $c)));
    }

    // ------------------------------------------------------------------ opzoeken

    /** Op een bekende lijst? @return string|null de naam van de lijst */
    public static function bad(string $ip): ?string
    {
        if (!self::blocklistsOn()) {
            return null;
        }
        $src = self::find('ip_blocklist', 'src', $ip);
        return $src !== null ? (self::SOURCES[$src]['label'] ?? $src) : null;
    }

    /** Landcode (NL), of null */
    public static function country(string $ip): ?string
    {
        if (!self::geoOn()) {
            return null;
        }
        return self::find('ip_geo', 'cc', $ip);
    }

    /** "🇩🇪 DE" */
    public static function flag(?string $cc): string
    {
        if ($cc === null || !preg_match('/^[A-Z]{2}$/', $cc)) {
            return '';
        }
        return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65) . " $cc";
    }

    private static function find(string $table, string $col, string $ip): ?string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return null;
        }
        $k = "$table|$bin";
        if (!array_key_exists($k, self::$cache)) {
            try {
                $r = q("SELECT $col, end_ip FROM $table WHERE fam = ? AND start_ip <= ? ORDER BY start_ip DESC LIMIT 1", [strlen($bin) === 4 ? 4 : 6, $bin])->fetch();
            } catch (Throwable) {
                $r = false;
            }
            self::$cache[$k] = $r && strcmp((string) $r['end_ip'], $bin) >= 0 ? (string) $r[$col] : null;
        }
        return self::$cache[$k];
    }

    // ------------------------------------------------------------------ naar de agents

    /** Bereiken als CIDR voor de firewall van de agents (alleen in de modus blokkeren), zonder je eigen "nooit blokkeren" */
    public static function agentNets(): array
    {
        if (!self::blocklistsOn() || !Ids::blocking()) {
            return [];
        }
        $allow = array_map([self::class, 'range'], Whitelist::values('ip'));
        $out = [];
        foreach (q('SELECT cidr, start_ip, end_ip FROM ip_blocklist ORDER BY fam, start_ip LIMIT ' . self::MAX_AGENT)->fetchAll() as $r) {
            foreach ($allow as $a) {
                if ($a && strlen($a[1]) === strlen($r['start_ip']) && strcmp($a[1], $r['end_ip']) <= 0 && strcmp($r['start_ip'], $a[2]) <= 0) {
                    continue 2;   // bevat een adres dat nooit geblokkeerd mag worden
                }
            }
            $out[] = $r['cidr'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ bijwerken (cron)

    /** Elke minuut: de lijsten dagelijks, het land maandelijks (of eerder als het mislukte, na een uur opnieuw) */
    public static function cron(): ?string
    {
        $msg = [];
        $st = json_decode((string) setting('ipintel_state', ''), true) ?: [];
        if (self::blocklistsOn() && (int) ($st['bl_ok'] ?? 0) < time() - 86400 && (int) ($st['bl_try'] ?? 0) < time() - 3600) {
            $msg[] = self::updateBlocklists();
        }
        $st = json_decode((string) setting('ipintel_state', ''), true) ?: [];
        if (self::geoOn() && (int) ($st['geo_try'] ?? 0) < time() - 3600 && (int) ($st['geo_ok'] ?? 0) < time() - 30 * 86400) {
            $msg[] = self::updateGeo();
        }
        return $msg ? implode(' · ', $msg) : null;
    }

    private static function state(array $set): void
    {
        $st = json_decode((string) q("SELECT value FROM settings WHERE name = 'ipintel_state'")->fetchColumn(), true) ?: [];
        save_setting('ipintel_state', json_encode(array_replace($st, $set)));
    }

    /** @return string wat er gebeurde */
    public static function updateBlocklists(): string
    {
        self::state(['bl_try' => time()]);
        $ranges = [];
        $per = [];
        $errors = [];
        foreach (self::SOURCES as $src => $s) {
            $raw = self::get(self::url($src, $s['url']));
            if ($raw === null) {
                $errors[] = $s['label'];
                continue;
            }
            $n = 0;
            foreach (self::parseList($src, $raw) as $cidr) {
                $r = self::range($cidr);
                if ($r && !self::reserved($r)) {
                    $ranges[] = [$r[0], $r[1], $r[2], $src];
                    $n++;
                }
            }
            $per[$src] = $n;
        }
        if (!$ranges) {
            self::state(['bl_error' => t('niet op te halen: {list}', ['list' => implode(', ', $errors)])]);
            return 'Blocklists: niet op te halen';
        }
        $merged = self::merge($ranges);
        db()->beginTransaction();
        q('DELETE FROM ip_blocklist');
        foreach (array_chunk($merged, 500) as $chunk) {
            q('INSERT INTO ip_blocklist (fam, start_ip, end_ip, cidr, src) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')),
                array_merge(...array_map(fn($r) => [$r[0], $r[1], $r[2], self::cidr($r), $r[3]], $chunk)));
        }
        db()->commit();
        self::state(['bl_ok' => time(), 'bl_n' => count($merged), 'bl_per' => $per, 'bl_error' => $errors ? t('niet op te halen: {list}', ['list' => implode(', ', $errors)]) : null]);
        return 'Blocklists: ' . count($merged) . ' bereiken';
    }

    /** DB-IP Lite (land), van deze maand of anders de vorige */
    public static function updateGeo(): string
    {
        self::state(['geo_try' => time()]);
        $tmp = tempnam(sys_get_temp_dir(), 'rzgeo');
        $ok = false;
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $month) {
            if (self::get(self::url('geo', sprintf(self::GEO_URL, $month)), $tmp) !== null) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            @unlink($tmp);
            self::state(['geo_error' => t('DB-IP Lite niet op te halen')]);
            return 'Land per IP: niet op te halen';
        }
        $gz = @gzopen($tmp, 'rb');
        if (!$gz) {
            @unlink($tmp);
            self::state(['geo_error' => t('DB-IP Lite: geen geldig bestand')]);
            return 'Land per IP: geen geldig bestand';
        }
        // In een nieuwe tabel opbouwen en dan wisselen: tijdens het inlezen werkt opzoeken gewoon door
        q('DROP TABLE IF EXISTS ip_geo_new');
        q('CREATE TABLE ip_geo_new LIKE ip_geo');
        $batch = [];
        $n = 0;
        $flush = static function () use (&$batch, &$n) {
            if ($batch) {
                q('INSERT INTO ip_geo_new (fam, start_ip, end_ip, cc) VALUES ' . implode(',', array_fill(0, count($batch), '(?, ?, ?, ?)')), array_merge(...$batch));
                $n += count($batch);
                $batch = [];
            }
        };
        while (($line = gzgets($gz, 200)) !== false) {
            $p = str_getcsv(trim($line), ',', '"', '');
            if (count($p) < 3 || !preg_match('/^[A-Z]{2}$/', $p[2])) {
                continue;
            }
            $a = @inet_pton($p[0]);
            $b = @inet_pton($p[1]);
            if ($a === false || $b === false || strlen($a) !== strlen($b)) {
                continue;
            }
            $batch[] = [strlen($a) === 4 ? 4 : 6, $a, $b, $p[2]];
            if (count($batch) >= 1000) {
                $flush();
            }
        }
        $flush();
        gzclose($gz);
        @unlink($tmp);
        if ($n < 1000) {
            q('DROP TABLE IF EXISTS ip_geo_new');
            self::state(['geo_error' => t('DB-IP Lite: te weinig regels ({n})', ['n' => $n])]);
            return "Land per IP: te weinig regels ($n)";
        }
        q('DROP TABLE IF EXISTS ip_geo_old');
        q('RENAME TABLE ip_geo TO ip_geo_old, ip_geo_new TO ip_geo');
        q('DROP TABLE ip_geo_old');
        self::state(['geo_ok' => time(), 'geo_n' => $n, 'geo_error' => null]);
        return "Land per IP: $n bereiken";
    }

    /** Stand voor de instellingen */
    public static function status(): array
    {
        $st = json_decode((string) setting('ipintel_state', ''), true) ?: [];
        return ['bl_at' => isset($st['bl_ok']) ? date('Y-m-d H:i', (int) $st['bl_ok']) : null, 'bl_n' => (int) ($st['bl_n'] ?? 0), 'bl_error' => $st['bl_error'] ?? null,
            'geo_at' => isset($st['geo_ok']) ? date('Y-m-d H:i', (int) $st['geo_ok']) : null, 'geo_n' => (int) ($st['geo_n'] ?? 0), 'geo_error' => $st['geo_error'] ?? null];
    }

    // ------------------------------------------------------------------ hulpfuncties

    /** Ander adres (alleen om te testen): config.php 'ipintel_urls' => ['firehol1' => 'file:///…', 'geo' => …] */
    private static function url(string $key, string $default): string
    {
        $o = (array) config('ipintel_urls', []);
        return (string) ($o[$key] ?? $default);
    }

    /** Ophalen (tekst, of naar een bestand). @return string|null */
    private static function get(string $url, ?string $file = null): ?string
    {
        $ch = curl_init($url);
        $fh = $file !== null ? fopen($file, 'wb') : null;
        curl_setopt_array($ch, [CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION] + ($fh ? [CURLOPT_FILE => $fh] : [CURLOPT_RETURNTRANSFER => true]));
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        if ($raw === false || ($code >= 400) || ($code === 0 && !str_starts_with($url, 'file://'))) {
            return null;
        }
        return $fh ? $file : (string) $raw;
    }

    /** CIDR's uit een lijst: Spamhaus (JSON per regel, "cidr") of FireHOL (één per regel, # = commentaar) */
    private static function parseList(string $src, string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line[0] === '{') {
                $j = json_decode($line, true);
                if (is_array($j) && isset($j['cidr'])) {
                    $out[] = (string) $j['cidr'];
                }
                continue;
            }
            $out[] = preg_split('/[\s;]+/', $line)[0];
        }
        return $out;
    }

    /** "1.2.3.0/24" (of een los adres) → [fam, begin, eind] binair, of null */
    public static function range(string $cidr): ?array
    {
        [$ip, $len] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return null;
        }
        $bits = strlen($bin) * 8;
        $len = $len === null || $len === '' ? $bits : (int) $len;
        if ($len < 0 || $len > $bits) {
            return null;
        }
        $start = '';
        $end = '';
        for ($i = 0; $i < strlen($bin); $i++) {
            $keep = max(0, min(8, $len - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $start .= chr(ord($bin[$i]) & $mask);
            $end .= chr((ord($bin[$i]) & $mask) | (~$mask & 0xFF));
        }
        return [strlen($bin) === 4 ? 4 : 6, $start, $end];
    }

    private static function reserved(array $r): bool
    {
        static $res = null;
        $res ??= array_map([self::class, 'range'], self::RESERVED);
        foreach ($res as $x) {
            if ($x[0] === $r[0] && strcmp($x[1], $r[2]) <= 0 && strcmp($r[1], $x[2]) <= 0) {
                return true;
            }
        }
        return false;
    }

    /** Overlappende bereiken samenvoegen (per familie), de eerste lijst blijft de bron */
    private static function merge(array $ranges): array
    {
        usort($ranges, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = [];
        foreach ($ranges as $r) {
            $last = $out ? $out[count($out) - 1] : null;
            if ($last && $last[0] === $r[0] && strcmp($r[1], $last[2]) <= 0) {
                if (strcmp($r[2], $last[2]) > 0) {
                    $out[count($out) - 1][2] = $r[2];
                    $out[count($out) - 1][4] = true;   // samengevoegd: geen nette CIDR meer
                }
                continue;
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Het bereik als tekst voor nftables: CIDR als het precies past, anders "begin-eind" */
    private static function cidr(array $r): string
    {
        $a = inet_ntop($r[1]);
        $b = inet_ntop($r[2]);
        if ($a === $b) {
            return $a;
        }
        // Past het precies in één CIDR? (begin heeft de juiste nullen, eind de juiste enen)
        $bits = strlen($r[1]) * 8;
        for ($len = 0; $len <= $bits; $len++) {
            $x = self::range("$a/$len");
            if ($x && $x[1] === $r[1] && $x[2] === $r[2]) {
                return "$a/$len";
            }
        }
        return "$a-$b";
    }
}
