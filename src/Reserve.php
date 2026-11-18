<?php
declare(strict_types=1);

/**
 * 1.286.0: reserve-RzSense. Een tweede RzSense (bv. een container op een andere machine) die een kopie bijhoudt van de
 * hoofdserver, zodat hij later het werk kan overnemen (1.287.0).
 *
 * - Koppelen: op de hoofdserver een koppelcode maken (1 uur geldig), op de reserve het adres van de hoofdserver en die
 *   code invullen. Daarna delen ze een geheime sleutel; alles tussen de twee is daarmee versleuteld (sodium secretbox)
 *   en ondertekend (HMAC), bovenop https.
 * - De reserve doet al het werk: elke minuut vraagt hij de hoofdserver hoe het gaat (dat is meteen de controle of hij
 *   bereikbaar is) en haalt hij nieuwe kopieën op, in stukken van 16 MB, een paar per minuut. De hoofdserver hoeft de
 *   reserve dus niet te kunnen bereiken.
 * - De hoofdserver maakt elke 15 minuten een kleine kopie (servers, checks, agentsleutels, instellingen, gebruikers;
 *   zonder geschiedenis en logs) en één keer per nacht een volledige kopie (met geschiedenis).
 * - Op de reserve blijven eigen dingen staan: sessies, onthouden apparaten en passkeys (horen bij zijn eigen adres), en
 *   de instellingen voor zijn eigen adres, proxy, https, toegang, back-ups en updates.
 * - Een reserve in stand-by doet verder niets: geen checks, geen meldingen, geen rapporten. Hij neemt nog niets over;
 *   dat komt in 1.287.0.
 */
final class Reserve
{
    /** Zo groot is één stuk bij het ophalen (ruim onder de 50 MB) */
    public const CHUNK = 16 * 1024 * 1024;
    /** Zoveel stukken per minuut (rustig: ~48 MB per minuut) */
    private const CHUNKS_PER_RUN = 3;
    private const SNAP_MINUTES = 15;
    private const MAX_SKEW = 300;
    private const CODE_TTL = 3600;
    private const UA = 'RzSense-reserve';

    /** Tabellen die bij de reserve van hemzelf blijven (nooit overschreven) */
    public const LOCAL_TABLES = ['php_sessions', 'agent_nonces', 'remember_tokens', 'user_sessions', 'trusted_devices', 'passkeys', 'jobs', 'rate_hits',
        'security_fails', 'login_log', 'stealth_doors', 'password_resets', 'password_reset_log', 'device_requests', 'notify_queue'];
    /** Bij de kleine kopie (elke 15 minuten) ook niet: geschiedenis, gebeurtenissen en gedownloade lijsten (die komen 's nachts mee) */
    public const SNAP_SKIP = ['alerts', 'status_changes', 'change_log', 'ids_signals', 'ids_evidence', 'app_errors', 'heal_log', 'block_log', 'path_events',
        'path_runs', 'log_batches', 'log_daily', 'net_presence', 'net_counters', 'net_traffic_day', 'net_wan_day', 'proxy_traffic', 'proxy_uniq', 'waf_events',
        'av_events', 'inet_speed', 'stats_daily', 'ti_ip', 'ti_hash', 'ti_list', 'ti_queue', 'vuln_cve', 'vuln_findings', 'vuln_inventory', 'vuln_buzz',
        'vuln_resolved', 'hibp_breaches', 'hibp_catalog', 'ip_info', 'dns_seen', 'edr_seen', 'app_seen', 'agent_gaps', 'incident_notes', 'incident_refs',
        'ids_triage', 'ioc_hunts', 'ai_advice', 'insight_score', 'insight_seen', 'log_watch_counts'];
    /** Instellingen die bij de reserve van hemzelf blijven (LIKE: \_ = een echt liggend streepje, % = de rest) */
    public const LOCAL_SETTINGS = ['reserve\_%', 'backup\_%', 'https\_%', 'hsts\_%', 'stealth\_%', 'update\_%', 'admin\_url', 'http\_login\_block',
        'trusted\_networks', 'trusted\_proxies', 'lan\_no\_login', 'lan\_as\_admin', 'url\_trust', 'cron\_overlap\_at'];

    public static function role(): string
    {
        $r = (string) setting('reserve_role', '');
        return in_array($r, ['primary', 'reserve'], true) ? $r : '';
    }

    /** Deze RzSense is een reserve die (nog) niets overneemt: dan doet de cron alleen het bijhouden */
    public static function standby(): bool
    {
        return self::role() === 'reserve' && (string) setting('reserve_key', '') !== '';
    }

    public static function dir(): string
    {
        return Backup::dir() . '/reserve';
    }

    // ------------------------------------------------------------------ sleutels en enveloppen

    private static function key(): string
    {
        $k = (string) setting('reserve_key', '');
        return preg_match('/^[0-9a-f]{64}$/', $k) ? (string) hex2bin($k) : '';
    }

    private static function sub(string $key, string $what): string
    {
        return hash_hmac('sha256', "rzsense-reserve-$what", $key, true);
    }

    /** Sleutel uit een koppelcode (alleen bij het koppelen) */
    public static function codeKey(string $code): string
    {
        return hash('sha256', 'rzsense-reserve-pair:' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''), true);
    }

    public static function seal(string $data, string $key): string
    {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $n . sodium_crypto_secretbox($data, $n, self::sub($key, 'box'));
    }

    public static function open(string $box, string $key): ?string
    {
        if (strlen($box) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES || strlen($key) !== 32) {
            return null;
        }
        $r = sodium_crypto_secretbox_open(substr($box, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($box, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::sub($key, 'box'));
        return $r === false ? null : $r;
    }

    public static function sign(string $key, string $op, int $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "rzsense-reserve\n$ts\n$nonce\n$op\n" . hash('sha256', $body), self::sub($key, 'mac'));
    }

    // ------------------------------------------------------------------ hoofdserver: koppelcode en verzoeken van de reserve

    /** Nieuwe koppelcode (de oude vervalt); alleen de hoofdserver */
    public static function newCode(): string
    {
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $c = '';
        for ($i = 0; $i < 20; $i++) {
            $c .= $alpha[random_int(0, 31)];
        }
        save_setting('reserve_pair_code', $c);
        save_setting('reserve_pair_until', (string) (time() + self::CODE_TTL));
        return implode('-', str_split($c, 5));
    }

    /**
     * Een verzoek van de reserve (api.php?action=agent_reserve&op=…). Geeft het versleutelde antwoord, of null (= 404).
     * @param array<string, string> $h kopregels (kleine letters)
     */
    public static function handle(string $op, string $body, array $h): ?string
    {
        $ts = (int) ($h['x-rz-reserve-ts'] ?? 0);
        $nonce = (string) ($h['x-rz-reserve-nonce'] ?? '');
        $sig = (string) ($h['x-rz-reserve-sig'] ?? '');
        if (abs(time() - $ts) > self::MAX_SKEW || !preg_match('/^[0-9a-f]{32}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
            return null;
        }
        if ($op === 'pair') {
            $code = (string) setting('reserve_pair_code', '');
            if ($code === '' || (int) setting('reserve_pair_until', '0') < time() || !hash_equals(self::sign(self::codeKey($code), 'pair', $ts, $nonce, $body), $sig)) {
                return null;
            }
            $in = json_decode($body, true);
            $key = random_bytes(32);
            save_setting('reserve_key', bin2hex($key));
            save_setting('reserve_role', 'primary');
            save_setting('reserve_pair_code', '');
            save_setting('reserve_pair_until', '0');
            save_setting('reserve_url', mb_substr((string) ($in['url'] ?? ''), 0, 300));
            save_setting('reserve_version', mb_substr((string) ($in['version'] ?? ''), 0, 20));
            save_setting('reserve_paired_at', now());
            save_setting('reserve_seen', now());
            save_setting('reserve_snap', '');
            save_setting('reserve_full', '');
            save_setting('reserve_full_first', '1');   // meteen een volledige kopie, daarna 's nachts
            save_setting('reserve_ip', client_ip());
            return self::seal((string) json_encode(['key' => bin2hex($key), 'url' => public_url(), 'version' => RZMON_VERSION]), self::codeKey($code));
        }
        $key = self::key();
        if (self::role() !== 'primary' || $key === '' || !hash_equals(self::sign($key, $op, $ts, $nonce, $body), $sig) || !self::nonceFresh($nonce)) {
            return null;
        }
        $in = json_decode($body, true);
        $in = is_array($in) ? $in : [];
        if ($op === 'hello') {
            save_setting('reserve_seen', now());
            save_setting('reserve_version', mb_substr((string) ($in['version'] ?? ''), 0, 20));
            save_setting('reserve_url', mb_substr((string) ($in['url'] ?? ''), 0, 300));
            save_setting('reserve_status', mb_substr((string) json_encode($in['status'] ?? null), 0, 2000));
            save_setting('reserve_ip', client_ip());
            return self::seal((string) json_encode(['version' => RZMON_VERSION, 'url' => public_url(), 'time' => time(),
                'snap' => self::fileInfo('snap'), 'full' => self::fileInfo('full'), 'pass' => (string) setting('backup_pass', '')]), $key);
        }
        if ($op === 'chunk') {
            $id = (string) ($in['id'] ?? '');
            $off = (int) ($in['off'] ?? -1);
            $f = self::dir() . '/' . $id;
            if (!preg_match('/^(snap|full)-\d{8}-\d{6}-[0-9a-f]{8}\.zip$/', $id) || $off < 0 || !is_file($f) || $off > (int) filesize($f)) {
                return self::seal('', $key);
            }
            $fh = fopen($f, 'rb');
            fseek($fh, $off);
            $data = (string) fread($fh, self::CHUNK);
            fclose($fh);
            return self::seal($data, $key);
        }
        return null;
    }

    /** Een nonce maar één keer (tegen herhalen van een verzoek) */
    private static function nonceFresh(string $nonce): bool
    {
        $seen = json_decode((string) setting('reserve_nonces', '[]'), true) ?: [];
        $now = time();
        $seen = array_filter($seen, static fn($t) => $t > $now - 2 * self::MAX_SKEW);
        if (isset($seen[$nonce])) {
            return false;
        }
        $seen[$nonce] = $now;
        save_setting('reserve_nonces', (string) json_encode(array_slice($seen, -500, null, true)));
        return true;
    }

    /** @return array{id: string, size: int, sha: string, at: int}|null de laatste kopie van deze soort */
    private static function fileInfo(string $kind): ?array
    {
        $i = json_decode((string) setting("reserve_$kind", ''), true);
        if (!is_array($i) || !is_file(self::dir() . '/' . ($i['id'] ?? ''))) {
            return null;
        }
        return ['id' => (string) $i['id'], 'size' => (int) $i['size'], 'sha' => (string) $i['sha'], 'at' => (int) $i['at']];
    }

    /**
     * Hoofdserver, elke minuut: de kleine kopie elke 15 minuten, de volledige één keer per nacht (standaard om 3 uur).
     * Alleen als er een reserve is die de laatste dag nog iets vroeg.
     */
    public static function primaryCron(): ?string
    {
        if (self::role() !== 'primary' || self::key() === '' || strtotime((string) setting('reserve_seen', '')) < time() - 86400) {
            return null;
        }
        if (setting('reserve_build_error', '') !== '' && strtotime((string) setting('reserve_build_try', '')) > time() - 600) {
            return null;   // mislukt: pas na 10 minuten opnieuw
        }
        save_setting('reserve_build_try', now());
        $out = [];
        $snap = self::fileInfo('snap');
        if ($snap === null || $snap['at'] < time() - self::SNAP_MINUTES * 60 + 30) {
            $out[] = self::build('snap');
        }
        $full = self::fileInfo('full');
        $hour = (int) setting('reserve_full_hour', '3');
        if ($full === null ? (int) date('G') === $hour || (int) setting('reserve_full_first', '1') === 1 : $full['at'] < time() - 22 * 3600 && (int) date('G') === $hour) {
            save_setting('reserve_full_first', '0');   // na het koppelen meteen één volledige kopie, daarna 's nachts
            $out[] = self::build('full');
        }
        return $out ? implode('; ', $out) : null;
    }

    /** Een kopie maken voor de reserve; de vorige blijft nog even staan (de reserve kan er middenin zitten) */
    public static function build(string $kind): string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return t('Reserve: kan de map {d} niet maken', ['d' => $dir]);
        }
        $id = sprintf('%s-%s-%s.zip', $kind, date('Ymd-His'), bin2hex(random_bytes(4)));
        $skip = $kind === 'snap' ? array_merge(self::LOCAL_TABLES, self::SNAP_SKIP) : self::LOCAL_TABLES;
        $r = Backup::export("$dir/$id", $kind === 'full', $kind === 'snap', $skip);
        if (!$r['ok']) {
            save_setting('reserve_build_error', mb_substr((string) $r['error'], 0, 300));
            return t('Reserve: kopie maken mislukt: {e}', ['e' => $r['error']]);
        }
        save_setting("reserve_$kind", (string) json_encode(['id' => $id, 'size' => $r['size'], 'sha' => hash_file('sha256', "$dir/$id"), 'at' => time()]));
        save_setting('reserve_build_error', '');
        // Opruimen: van elke soort de laatste twee
        foreach (['snap', 'full'] as $k) {
            $files = glob("$dir/$k-*.zip") ?: [];
            rsort($files);
            foreach (array_slice($files, 2) as $old) {
                @unlink($old);
            }
        }
        return $kind === 'snap' ? t('Reserve: kleine kopie klaar ({s})', ['s' => self::mb($r['size'])]) : t('Reserve: volledige kopie klaar ({s})', ['s' => self::mb($r['size'])]);
    }

    public static function unpair(): void
    {
        foreach (['reserve_key', 'reserve_role', 'reserve_url', 'reserve_version', 'reserve_seen', 'reserve_snap', 'reserve_full', 'reserve_primary_url',
            'reserve_dl', 'reserve_error', 'reserve_status', 'reserve_pair_code'] as $k) {
            save_setting($k, '');
        }
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (is_file($f) && preg_match('/^(snap|full)-[\w-]+\.zip(\.part)?$/', basename($f))) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------------ reserve: koppelen, contact en ophalen

    /**
     * Een verzoek aan de hoofdserver.
     * @param array<string, mixed> $data
     * @return array{ok: bool, body: ?string, error: ?string, code: int}
     */
    private static function call(string $base, string $op, array $data, string $key, int $timeout = 30): array
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        $ts = time();
        $nonce = bin2hex(random_bytes(16));
        $url = rtrim($base, '/') . '/api.php?action=agent_reserve&op=' . rawurlencode($op);
        if (!preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#i', rtrim($base, '/'))) {
            return ['ok' => false, 'body' => null, 'error' => t('Ongeldig adres'), 'code' => 0];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Rz-Reserve-Ts: $ts", "X-Rz-Reserve-Nonce: $nonce",
                'X-Rz-Reserve-Sig: ' . self::sign($key, $op, $ts, $nonce, $body)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => self::UA . '/' . RZMON_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            return ['ok' => false, 'body' => null, 'error' => t('Hoofdserver niet bereikbaar') . ": $err", 'code' => 0];
        }
        if ($code !== 200) {
            return ['ok' => false, 'body' => null, 'error' => $code === 404 ? t('De hoofdserver weigert (verkeerde code of sleutel, of ontkoppeld)') : "HTTP $code", 'code' => $code];
        }
        $plain = self::open((string) $res, $key);
        return $plain === null ? ['ok' => false, 'body' => null, 'error' => t('Antwoord van de hoofdserver klopt niet'), 'code' => $code]
            : ['ok' => true, 'body' => $plain, 'error' => null, 'code' => $code];
    }

    /** Op de reserve: koppelen met de hoofdserver. Geeft een foutmelding of null. */
    public static function pair(string $primaryUrl, string $code): ?string
    {
        $primaryUrl = rtrim(trim($primaryUrl), '/');
        if (!preg_match('#^https?://#i', $primaryUrl)) {
            return t('Vul het adres van de hoofdserver in, met https://');
        }
        if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '') !== 20) {
            return t('De koppelcode heeft 20 tekens (bv. ABCDE-FGHJK-LMNPQ-RSTUV)');
        }
        if (self::role() === 'primary') {
            return t('Deze RzSense is zelf een hoofdserver met een reserve');
        }
        $ck = self::codeKey($code);
        $r = self::call($primaryUrl, 'pair', ['url' => public_url(), 'version' => RZMON_VERSION], $ck);
        if (!$r['ok']) {
            return $r['error'];
        }
        $j = json_decode((string) $r['body'], true);
        if (!is_array($j) || !preg_match('/^[0-9a-f]{64}$/', (string) ($j['key'] ?? ''))) {
            return t('Antwoord van de hoofdserver klopt niet');
        }
        save_setting('reserve_key', (string) $j['key']);
        save_setting('reserve_role', 'reserve');
        save_setting('reserve_primary_url', $primaryUrl);
        save_setting('reserve_primary_version', (string) ($j['version'] ?? ''));
        save_setting('reserve_paired_at', now());
        save_setting('reserve_snap_at', '0');
        save_setting('reserve_full_at', '0');
        save_setting('reserve_dl', '');
        save_setting('reserve_error', '');
        return null;
    }

    /** Op de reserve, elke minuut: contact met de hoofdserver, en kopieën ophalen en terugzetten */
    public static function reserveCron(): ?string
    {
        if (!self::standby()) {
            return null;
        }
        $key = self::key();
        $base = (string) setting('reserve_primary_url', '');
        $r = self::call($base, 'hello', ['version' => RZMON_VERSION, 'url' => public_url(), 'status' => self::localStatus()], $key, 20);
        if (!$r['ok']) {
            save_setting('reserve_error', mb_substr((string) $r['error'], 0, 300));
            save_setting('reserve_primary_down_since', setting('reserve_primary_down_since', '') ?: now());
            return 'Reserve: ' . $r['error'];
        }
        $m = json_decode((string) $r['body'], true) ?: [];
        save_setting('reserve_primary_seen', now());
        save_setting('reserve_primary_down_since', '');
        save_setting('reserve_primary_version', (string) ($m['version'] ?? ''));
        save_setting('reserve_clock_skew', (string) ((int) ($m['time'] ?? time()) - time()));
        if (version_compare(RZMON_VERSION, (string) ($m['version'] ?? '0'), '<')) {
            save_setting('reserve_error', t('De reserve heeft RzSense {r}, de hoofdserver {p}: werk de reserve eerst bij (nieuwe image)', ['r' => RZMON_VERSION, 'p' => $m['version'] ?? '?']));
            return null;
        }
        save_setting('reserve_error', '');
        return self::download($m, $key, $base);
    }

    /** @return array<string, mixed> wat de reserve aan de hoofdserver laat zien */
    private static function localStatus(): array
    {
        $dl = json_decode((string) setting('reserve_dl', ''), true);
        return ['snap_at' => (int) setting('reserve_snap_at', '0'), 'full_at' => (int) setting('reserve_full_at', '0'),
            'dl' => is_array($dl) ? ['kind' => $dl['kind'] ?? '', 'off' => (int) ($dl['off'] ?? 0), 'size' => (int) ($dl['size'] ?? 0)] : null,
            'apply_error' => (string) setting('reserve_apply_error', '')];
    }

    /** @param array<string, mixed> $m wat de hoofdserver heeft */
    private static function download(array $m, string $key, string $base): ?string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return t('Reserve: kan de map {d} niet maken', ['d' => $dir]);
        }
        $dl = json_decode((string) setting('reserve_dl', ''), true);
        if (!is_array($dl)) {
            // Wat is er nieuw? Eerst de volledige kopie, anders de kleine
            $pick = null;
            foreach (['full', 'snap'] as $k) {
                $i = $m[$k] ?? null;
                if ($pick === null && is_array($i) && (int) $i['at'] > (int) setting("reserve_{$k}_at", '0')) {
                    $pick = ['kind' => $k, 'id' => (string) $i['id'], 'size' => (int) $i['size'], 'sha' => (string) $i['sha'], 'at' => (int) $i['at'], 'off' => 0];
                }
            }
            if ($pick === null) {
                return null;
            }
            $dl = $pick;
            @unlink("$dir/{$dl['kind']}.part");
        }
        $part = "$dir/{$dl['kind']}.part";
        for ($i = 0; $i < self::CHUNKS_PER_RUN && $dl['off'] < $dl['size']; $i++) {
            $r = self::call($base, 'chunk', ['id' => $dl['id'], 'off' => $dl['off']], $key, 120);
            if (!$r['ok'] || $r['body'] === '' || $r['body'] === null) {
                // Weg bij de hoofdserver (een nieuwere is er al): opnieuw beginnen bij de volgende ronde
                save_setting('reserve_dl', '');
                @unlink($part);
                return t('Reserve: ophalen afgebroken ({e})', ['e' => $r['error'] ?? t('bestand weg')]);
            }
            if (file_put_contents($part, $r['body'], FILE_APPEND) === false) {
                save_setting('reserve_dl', '');
                @unlink($part);
                return t('Reserve: schrijven mislukt (schijf vol?)');
            }
            $dl['off'] += strlen($r['body']);
            save_setting('reserve_dl', (string) json_encode($dl));
        }
        if ($dl['off'] < $dl['size']) {
            return null;   // de volgende minuut verder
        }
        save_setting('reserve_dl', '');
        if (!hash_equals((string) $dl['sha'], (string) hash_file('sha256', $part))) {
            @unlink($part);
            return t('Reserve: kopie beschadigd onderweg, opnieuw');
        }
        $res = self::apply($part, (string) ($m['pass'] ?? ''));
        @unlink($part);
        if ($res !== null) {
            save_setting('reserve_apply_error', mb_substr($res, 0, 300));
            return t('Reserve: terugzetten mislukt: {e}', ['e' => $res]);
        }
        save_setting('reserve_apply_error', '');
        save_setting("reserve_{$dl['kind']}_at", (string) $dl['at']);
        save_setting("reserve_{$dl['kind']}_applied", now());
        if ($dl['kind'] === 'full') {
            save_setting('reserve_snap_at', '0');   // daarna meteen weer de nieuwste kleine kopie
        }
        return $dl['kind'] === 'full' ? t('Reserve: volledige kopie teruggezet') : t('Reserve: kleine kopie teruggezet');
    }

    /** Een kopie van de hoofdserver terugzetten; eigen instellingen en tabellen blijven. Geeft een foutmelding of null. */
    public static function apply(string $zip, string $pass): ?string
    {
        $like = implode(' OR ', array_fill(0, count(self::LOCAL_SETTINGS), 'name LIKE ?'));
        $mine = q("SELECT name, value FROM settings WHERE $like", self::LOCAL_SETTINGS)->fetchAll();
        $r = Backup::restore($zip, $pass !== '' ? $pass : null, false, self::LOCAL_SETTINGS);
        if (!$r['ok']) {
            return (string) $r['error'];
        }
        // Wat de hoofdserver in deze instellingen had (bv. zijn eigen adres), hoort hier niet
        q("DELETE FROM settings WHERE $like", self::LOCAL_SETTINGS);
        foreach ($mine as $s) {
            save_setting((string) $s['name'], (string) $s['value']);
        }
        unset($GLOBALS['rz_settings']);
        return null;
    }

    // ------------------------------------------------------------------ voor het scherm

    /** @return array<string, mixed> */
    public static function overview(): array
    {
        $role = self::role();
        $o = ['role' => $role, 'paired' => self::key() !== '', 'version' => RZMON_VERSION, 'url' => public_url(), 'chunk_mb' => self::CHUNK >> 20];
        $ts = static fn($t) => (int) $t > 0 ? date('Y-m-d H:i:s', (int) $t) : '';   // tijden in de tijd van de server, zoals overal
        if ($role === 'primary') {
            $st = json_decode((string) setting('reserve_status', ''), true);
            $o += ['reserve_url' => (string) setting('reserve_url', ''), 'reserve_version' => (string) setting('reserve_version', ''),
                'seen' => (string) setting('reserve_seen', ''), 'paired_at' => (string) setting('reserve_paired_at', ''),
                'snap' => self::fileInfo('snap'), 'full' => self::fileInfo('full'), 'build_error' => (string) setting('reserve_build_error', ''),
                'status' => is_array($st) ? ['snap_at' => $ts($st['snap_at'] ?? 0), 'full_at' => $ts($st['full_at'] ?? 0), 'dl' => $st['dl'] ?? null,
                    'apply_error' => (string) ($st['apply_error'] ?? '')] : null, 'full_hour' => (int) setting('reserve_full_hour', '3')];
            foreach (['snap', 'full'] as $k) {
                if ($o[$k] !== null) {
                    $o[$k]['at'] = $ts($o[$k]['at']);
                }
            }
        } elseif ($role === 'reserve') {
            $dl = json_decode((string) setting('reserve_dl', ''), true);
            $o += ['primary_url' => (string) setting('reserve_primary_url', ''), 'primary_version' => (string) setting('reserve_primary_version', ''),
                'primary_seen' => (string) setting('reserve_primary_seen', ''), 'down_since' => (string) setting('reserve_primary_down_since', ''),
                'paired_at' => (string) setting('reserve_paired_at', ''), 'error' => (string) setting('reserve_error', ''),
                'apply_error' => (string) setting('reserve_apply_error', ''),
                'snap_at' => $ts(setting('reserve_snap_at', '0')), 'full_at' => $ts(setting('reserve_full_at', '0')),
                'snap_applied' => (string) setting('reserve_snap_applied', ''), 'full_applied' => (string) setting('reserve_full_applied', ''),
                'dl' => is_array($dl) ? ['kind' => $dl['kind'], 'off' => (int) $dl['off'], 'size' => (int) $dl['size']] : null];
        } else {
            $until = (int) setting('reserve_pair_until', '0');
            $o['code_until'] = $until > time() && setting('reserve_pair_code', '') !== '' ? date('c', $until) : null;
        }
        return $o;
    }

    private static function mb(int $b): string
    {
        return $b >= 1 << 30 ? round($b / (1 << 30), 1) . ' GB' : ($b >= 1 << 20 ? (int) round($b / (1 << 20)) . ' MB' : max(1, (int) round($b / 1024)) . ' KB');
    }
}
