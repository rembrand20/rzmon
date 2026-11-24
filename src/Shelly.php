<?php
declare(strict_types=1);

/**
 * 1.321.0: Shelly's uitlezen (stroom en oververhitting). RzSense vraagt elke Shelly rechtstreeks via zijn lokale API,
 * zonder cloud, Home Assistant of MQTT ertussen, en schakelt nooit iets.
 * - Gen 2, 3 en 4 (Plus, Pro, Mini): GET /rpc/Shelly.GetStatus (met wachtwoord: digest, gebruiker admin);
 * - Gen 1: GET /status (met wachtwoord: basic).
 * De generatie volgt uit GET /shelly (zonder wachtwoord).
 *
 * De check: de waarde is de interne temperatuur van de Shelly (°C; warning/critical zijn de grenzen), critical bij de
 * oververhittingsmelding van de Shelly zelf. Daarnaast het vermogen: een eigen grens, het relais dat aan moet staan, en
 * afwijkingen van het normale verbruik. Dat leert RzSense per apparaat per uur van de week (twee weken); vanaf een week
 * meten meldt hij: onverwacht 0 W, veel meer dan normaal, of aan terwijl het normaal uit is.
 */
final class Shelly
{
    /** Pas na zoveel dagen meten worden afwijkingen gemeld */
    public const LEARN_DAYS = 7;
    private const KEEP_DAYS = 15;
    private const ON_W = 5.0;

    /**
     * De opties van een check (met standaardwaarden)
     * @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    public static function opts(array $check): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        return ['user' => (string) ($o['user'] ?? ''), 'pass' => (string) ($o['pass'] ?? ''), 'pmax' => isset($o['pmax']) && $o['pmax'] !== '' ? (float) $o['pmax'] : null,
            'anom' => (string) ($o['anom'] ?? '15'), 'zero' => (int) ($o['zero'] ?? 1), 'on' => (int) ($o['on'] ?? 1), 'relay' => (int) ($o['relay'] ?? 0)];
    }

    /**
     * Basisadres van de Shelly: het doel (IP[:poort]) of het adres van de server
     * @param array<string, mixed> $check
     * @param array<string, mixed> $host
     */
    public static function base(array $check, array $host): ?string
    {
        $t = trim((string) ($check['target'] ?? '')) ?: trim((string) ($host['address'] ?? ''));
        $t = preg_replace('#^https?://#i', '', rtrim($t, '/'));
        return preg_match('/^(\[[0-9a-fA-F:]+\]|[A-Za-z0-9._-]{1,253})(:\d{1,5})?$/', (string) $t) ? 'http://' . $t : null;
    }

    /**
     * GET met tijdslimiet
     * @return array{code: int, body: string, err: string}
     */
    public static function get(string $url, string $user = '', string $pass = '', bool $digest = false, int $timeout = 6): array
    {
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'RzSense'];
        if ($pass !== '') {
            $opt[CURLOPT_HTTPAUTH] = $digest ? CURLAUTH_DIGEST : CURLAUTH_BASIC;
            $opt[CURLOPT_USERPWD] = ($user !== '' ? $user : ($digest ? 'admin' : 'admin')) . ':' . $pass;
        }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $out = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : '', 'err' => curl_error($ch)];
        curl_close($ch);
        return $out;
    }

    /**
     * Wat voor Shelly is dit (GET /shelly)?
     * @param array<string, mixed> $j
     * @return array{gen: int, model: string, name: string, fw: string, auth: bool}|null
     */
    public static function info(array $j): ?array
    {
        if (isset($j['gen']) && (int) $j['gen'] >= 2) {
            return ['gen' => (int) $j['gen'], 'model' => self::s($j['model'] ?? $j['app'] ?? ''), 'name' => self::s($j['name'] ?? ''), 'fw' => self::s($j['ver'] ?? $j['fw_id'] ?? ''),
                'auth' => !empty($j['auth_en'])];
        }
        if (isset($j['type']) && isset($j['mac'])) {
            return ['gen' => 1, 'model' => self::s($j['type']), 'name' => self::s($j['name'] ?? ''), 'fw' => self::s($j['fw'] ?? ''), 'auth' => !empty($j['auth'])];
        }
        return null;
    }

    /** Getal kort voor in een bericht (hooguit één decimaal) */
    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function s(mixed $v, int $n = 80): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f]/', '', is_scalar($v) ? (string) $v : '') ?? '', 0, $n);
    }

    /**
     * Status omzetten naar één vorm
     * @param array<string, mixed> $s
     * @return array<string, mixed>
     */
    public static function parse(array $s, int $gen): array
    {
        $out = ['w' => null, 'wh' => null, 'tc' => null, 'overtemp' => false, 'errors' => [], 'relays' => [], 'v' => null, 'rssi' => null, 'uptime' => null, 'update' => '', 'phases' => []];
        $addW = static function (&$o, $v) { if (is_numeric($v)) { $o['w'] = ($o['w'] ?? 0.0) + (float) $v; } };
        $addWh = static function (&$o, $v) { if (is_numeric($v)) { $o['wh'] = ($o['wh'] ?? 0.0) + (float) $v; } };
        $temp = static function (&$o, $v) { if (is_numeric($v) && (float) $v > -40 && (float) $v < 200) { $o['tc'] = max($o['tc'] ?? -100.0, (float) $v); } };
        if ($gen >= 2) {
            foreach ($s as $key => $c) {
                if (!is_array($c)) {
                    continue;
                }
                [$kind, $idx] = array_pad(explode(':', (string) $key, 2), 2, '0');
                if (in_array($kind, ['switch', 'cover', 'light', 'pm1', 'rgb', 'rgbw'], true)) {
                    $addW($out, $c['apower'] ?? null);
                    $addWh($out, $c['aenergy']['total'] ?? null);
                    $temp($out, $c['temperature']['tC'] ?? null);
                    if ($kind === 'switch' && isset($c['output'])) {
                        $out['relays'][] = (bool) $c['output'];
                    }
                    if ($out['v'] === null && is_numeric($c['voltage'] ?? null)) {
                        $out['v'] = (float) $c['voltage'];
                    }
                } elseif ($kind === 'em') {
                    $addW($out, $c['total_act_power'] ?? null);
                    foreach (['a', 'b', 'c'] as $ph) {
                        if (is_numeric($c[$ph . '_act_power'] ?? null)) {
                            $out['phases'][] = (float) $c[$ph . '_act_power'];
                        }
                    }
                    if ($out['v'] === null && is_numeric($c['a_voltage'] ?? null)) {
                        $out['v'] = (float) $c['a_voltage'];
                    }
                } elseif ($kind === 'em1') {
                    $addW($out, $c['act_power'] ?? null);
                    if ($out['v'] === null && is_numeric($c['voltage'] ?? null)) {
                        $out['v'] = (float) $c['voltage'];
                    }
                } elseif ($kind === 'emdata') {
                    $addWh($out, $c['total_act'] ?? null);
                } elseif ($kind === 'em1data') {
                    $addWh($out, $c['total_act_energy'] ?? null);
                } elseif ($kind === 'temperature' && (int) $idx < 100) {
                    $temp($out, $c['tC'] ?? null);   // eigen sensor van het apparaat (100+ = sensoren van de Plus Add-on: ruimte, niet het apparaat)
                } elseif ($kind === 'wifi') {
                    $out['rssi'] = is_numeric($c['rssi'] ?? null) ? (int) $c['rssi'] : null;
                } elseif ($kind === 'sys') {
                    $out['uptime'] = is_numeric($c['uptime'] ?? null) ? (int) $c['uptime'] : null;
                    $out['update'] = self::s($c['available_updates']['stable']['version'] ?? '', 40);
                }
                foreach ((array) ($c['errors'] ?? []) as $e) {
                    if (is_string($e) && preg_match('/^[a-z_]{2,30}$/', $e) && !in_array($e, $out['errors'], true)) {
                        $out['errors'][] = $e;
                    }
                }
            }
            $out['overtemp'] = in_array('overtemp', $out['errors'], true);
        } else {
            foreach ((array) ($s['meters'] ?? []) as $m) {
                if (is_array($m)) {
                    $addW($out, $m['power'] ?? null);
                    if (is_numeric($m['total'] ?? null)) {
                        $addWh($out, (float) $m['total'] / 60);   // Gen 1: wattminuten
                    }
                }
            }
            foreach ((array) ($s['emeters'] ?? []) as $m) {
                if (is_array($m)) {
                    $addW($out, $m['power'] ?? null);
                    $addWh($out, $m['total'] ?? null);
                    if (is_numeric($m['power'] ?? null)) {
                        $out['phases'][] = (float) $m['power'];
                    }
                    if ($out['v'] === null && is_numeric($m['voltage'] ?? null)) {
                        $out['v'] = (float) $m['voltage'];
                    }
                }
            }
            if (count($out['phases']) < 2) {
                $out['phases'] = [];
            }
            foreach ((array) ($s['relays'] ?? []) as $r) {
                if (is_array($r) && isset($r['ison'])) {
                    $out['relays'][] = (bool) $r['ison'];
                }
            }
            $temp($out, $s['temperature'] ?? $s['tmp']['tC'] ?? null);
            $out['overtemp'] = !empty($s['overtemperature']);
            if ($out['overtemp']) {
                $out['errors'][] = 'overtemp';
            }
            if (!empty($s['overpower']) || array_filter((array) ($s['relays'] ?? []), static fn($r) => is_array($r) && !empty($r['overpower']))) {
                $out['errors'][] = 'overpower';
            }
            $out['rssi'] = is_numeric($s['wifi_sta']['rssi'] ?? null) ? (int) $s['wifi_sta']['rssi'] : null;
            $out['uptime'] = is_numeric($s['uptime'] ?? null) ? (int) $s['uptime'] : null;
            $out['update'] = !empty($s['update']['has_update']) ? self::s($s['update']['new_version'] ?? 'ja', 40) : '';
        }
        if ($out['tc'] !== null && $out['tc'] < -50) {
            $out['tc'] = null;
        }
        return $out;
    }

    /**
     * De check uitvoeren
     * @param array<string, mixed> $check
     * @param array<string, mixed> $host
     * @return array<string, mixed>
     */
    public static function check(array $check, array $host): array
    {
        $base = self::base($check, $host);
        if ($base === null) {
            return Checks::result(STATUS_UNKNOWN, null, t('Geen geldig adres voor de Shelly (IP-adres of naam, eventueel met :poort)'));
        }
        $o = self::opts($check);
        $r = self::get("$base/shelly");
        $info = $r['code'] === 200 ? self::info(json_decode($r['body'], true) ?: []) : null;
        if ($info === null) {
            return Checks::result(STATUS_CRIT, null, $r['code'] === 0 ? t('Shelly geeft geen antwoord ({e})', ['e' => mb_substr($r['err'], 0, 80)])
                : t('Geen Shelly op dit adres (HTTP {c})', ['c' => $r['code']]));
        }
        $r = $info['gen'] >= 2 ? self::get("$base/rpc/Shelly.GetStatus", 'admin', $o['pass'], true) : self::get("$base/status", $o['user'], $o['pass']);
        if ($r['code'] === 401) {
            return Checks::result(STATUS_UNKNOWN, null, t('De Shelly vraagt een wachtwoord: vul het in bij de check'));
        }
        $st = $r['code'] === 200 ? json_decode($r['body'], true) : null;
        if (!is_array($st)) {
            return Checks::result(STATUS_CRIT, null, t('Shelly geeft geen status (HTTP {c})', ['c' => $r['code']]));
        }
        $p = self::parse($st, $info['gen']);
        self::store((int) $check['id'], $info, $p);
        return self::judge($check, $o, $info, $p, self::anomaly((int) $check['id'], $o, time()));
    }

    /**
     * Oordeel over één meting
     * @param array<string, mixed> $check
     * @param array<string, mixed> $o
     * @param array<string, mixed> $info
     * @param array<string, mixed> $p
     * @param array{k: string, text: string}|null $anom
     * @return array<string, mixed>
     */
    public static function judge(array $check, array $o, array $info, array $p, ?array $anom): array
    {
        $warn = is_numeric($check['warn_threshold'] ?? null) ? (float) $check['warn_threshold'] : 70.0;
        $crit = is_numeric($check['crit_threshold'] ?? null) ? (float) $check['crit_threshold'] : 80.0;
        $status = STATUS_OK;
        $why = [];
        if ($p['overtemp']) {
            $status = STATUS_CRIT;
            $why[] = t('de Shelly meldt zelf oververhitting');
        } elseif ($p['tc'] !== null && $p['tc'] >= $crit) {
            $status = STATUS_CRIT;
            $why[] = t('te warm (grens {c} °C)', ['c' => self::n($crit)]);
        } elseif ($p['tc'] !== null && $p['tc'] >= $warn) {
            $status = STATUS_WARN;
            $why[] = t('warm (grens {c} °C)', ['c' => self::n($warn)]);
        }
        foreach (array_diff($p['errors'], ['overtemp']) as $e) {
            $status = max($status, STATUS_WARN);
            $why[] = ['overpower' => t('te veel vermogen'), 'overvoltage' => t('te hoge spanning'), 'undervoltage' => t('te lage spanning'),
                'overcurrent' => t('te veel stroom')][$e] ?? t('fout: {e}', ['e' => $e]);
        }
        if ($o['pmax'] !== null && $p['w'] !== null && $p['w'] > $o['pmax']) {
            $status = max($status, STATUS_WARN);
            $why[] = t('meer dan {w} W', ['w' => self::n($o['pmax'])]);
        }
        if ($o['relay'] && $p['relays'] && !in_array(true, $p['relays'], true)) {
            $status = STATUS_CRIT;
            $why[] = t('het relais staat uit');
        }
        if ($anom !== null) {
            $status = max($status, STATUS_WARN);
            $why[] = $anom['text'];
        }
        $parts = [];
        if ($p['w'] !== null) {
            $parts[] = self::n(round($p['w'])) . ' W';
        }
        if ($p['tc'] !== null) {
            $parts[] = self::n(round($p['tc'], 1)) . ' °C';
        }
        if ($p['relays']) {
            $parts[] = in_array(true, $p['relays'], true) ? t('relais aan') : t('relais uit');
        }
        $parts[] = $info['model'] . ' · Gen ' . $info['gen'];
        if ($p['update'] !== '') {
            $parts[] = t('firmware-update klaar');
        }
        $msg = ($why ? ucfirst(implode(' · ', $why)) . ' · ' : '') . implode(' · ', $parts);
        return Checks::result($status, $p['tc'] !== null ? round($p['tc'], 1) : null, $msg);
    }

    /**
     * Meting bewaren (voor het leren, de grafieken en het overzicht)
     * @param array<string, mixed> $info
     * @param array<string, mixed> $p
     */
    public static function store(int $checkId, array $info, array $p, ?int $at = null): void
    {
        $at ??= time();
        q('INSERT INTO shelly_samples (check_id, at, w, wh, tc) VALUES (?, ?, ?, ?, ?)',
            [$checkId, date('Y-m-d H:i:s', $at), $p['w'] !== null ? round($p['w'], 1) : null, $p['wh'] !== null ? round($p['wh'], 1) : null, $p['tc'] !== null ? round($p['tc'], 1) : null]);
        q('INSERT INTO shelly_state (check_id, gen, model, fw, name, last, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE gen = VALUES(gen), model = VALUES(model), fw = VALUES(fw), name = VALUES(name), last = VALUES(last), updated_at = VALUES(updated_at)',
            [$checkId, $info['gen'], $info['model'], $info['fw'], $info['name'], json_encode($p, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s', $at)]);
    }

    /**
     * Normaal verbruik voor dit uur van de week, uit de vorige weken (niet vandaag)
     * @return array{lo: float, hi: float, on: float, days: int}|null
     */
    public static function band(int $checkId, int $ts): ?array
    {
        $dow = (int) date('N', $ts);
        $hour = (int) date('G', $ts);
        $rows = q('SELECT w, DATE(at) AS d FROM shelly_samples WHERE check_id = ? AND at >= ? AND at < ? AND w IS NOT NULL
                   AND WEEKDAY(at) + 1 = ? AND HOUR(at) = ?', [$checkId, date('Y-m-d 00:00:00', $ts - 14 * 86400), date('Y-m-d 00:00:00', $ts), $dow, $hour])->fetchAll();
        $first = q('SELECT MIN(at) FROM shelly_samples WHERE check_id = ?', [$checkId])->fetchColumn();
        if (!$first || strtotime((string) $first) > $ts - self::LEARN_DAYS * 86400 || count($rows) < 20) {
            return null;
        }
        $w = array_map(static fn($r) => (float) $r['w'], $rows);
        sort($w);
        $pct = static fn(float $q) => $w[(int) floor($q * (count($w) - 1))];
        return ['lo' => $pct(0.05), 'hi' => $pct(0.95), 'on' => count(array_filter($w, static fn($x) => $x >= self::ON_W)) / count($w),
            'days' => count(array_unique(array_column($rows, 'd')))];
    }

    /**
     * Afwijking van het normale verbruik?
     * @param array<string, mixed> $o
     * @return array{k: string, text: string}|null
     */
    public static function anomaly(int $checkId, array $o, int $ts): ?array
    {
        if ($o['anom'] === 'off') {
            return null;
        }
        $b = self::band($checkId, $ts);
        if ($b === null) {
            return null;
        }
        $min = max(5, (int) $o['anom']);
        $win = q('SELECT w FROM shelly_samples WHERE check_id = ? AND at > ? AND w IS NOT NULL ORDER BY at', [$checkId, date('Y-m-d H:i:s', $ts - $min * 60)])
            ->fetchAll(PDO::FETCH_COLUMN);
        if (count($win) < max(3, intdiv($min, 2))) {
            return null;   // te weinig metingen in het venster
        }
        $win = array_map('floatval', $win);
        $range = self::n(round($b['lo'])) . '–' . self::n(round($b['hi'])) . ' W';
        // Aan terwijl het normaal uit is (bv. de wasmachine 's nachts)
        if ($o['on'] && $b['hi'] < self::ON_W && min($win) >= 20) {
            return ['k' => 'on', 'text' => t('al {m} min {w} W, terwijl dit uur normaal uit is', ['m' => $min, 'w' => self::n(round(end($win)))])];
        }
        // Veel meer dan normaal, de hele tijd
        if (min($win) > $b['hi'] * 1.5 + 20) {
            return ['k' => 'high', 'text' => t('al {m} min meer dan normaal: {w} W (normaal {r})', ['m' => $min, 'w' => self::n(round(end($win))), 'r' => $range])];
        }
        // Onverwacht 0 W: normaal staat hij dit uur een flink deel van de tijd aan (vriezer, pomp); kijk dan minstens een uur
        if ($o['zero'] && $b['on'] >= 0.2) {
            $zmin = max(60, $min);
            $zw = $zmin === $min ? $win : array_map('floatval', q('SELECT w FROM shelly_samples WHERE check_id = ? AND at > ? AND w IS NOT NULL', [$checkId, date('Y-m-d H:i:s', $ts - $zmin * 60)])
                ->fetchAll(PDO::FETCH_COLUMN));
            if (count($zw) >= $zmin / 2 && max($zw) < 1) {
                return ['k' => 'zero', 'text' => t('al {m} min 0 W, terwijl hij dit uur normaal aan gaat (normaal {r})', ['m' => $zmin, 'r' => $range])];
            }
        }
        return null;
    }

    /** Elke nacht: oude metingen weg */
    public static function cleanup(): void
    {
        q('DELETE FROM shelly_samples WHERE at < ?', [date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]);
    }

    /** kWh vandaag uit de energieteller (een teller die terug gaat, bv. na een herstart, telt vanaf daar verder) */
    public static function kwhToday(int $checkId, ?int $ts = null): ?float
    {
        $ts ??= time();
        $wh = q('SELECT wh FROM shelly_samples WHERE check_id = ? AND at >= ? AND wh IS NOT NULL ORDER BY at', [$checkId, date('Y-m-d 00:00:00', $ts)])->fetchAll(PDO::FETCH_COLUMN);
        if (count($wh) < 2) {
            return null;
        }
        $sum = 0.0;
        for ($i = 1, $n = count($wh); $i < $n; $i++) {
            $d = (float) $wh[$i] - (float) $wh[$i - 1];
            $sum += $d >= 0 ? $d : 0.0;
        }
        return round($sum / 1000, 2);
    }

    /** @return array<string, mixed> het overzicht (Monitoring → Shelly's) */
    public static function overview(): array
    {
        $now = time();
        $out = [];
        foreach (q("SELECT c.id, c.name, c.status, c.last_message, c.last_value, c.warn_threshold, c.crit_threshold, c.options, h.id AS host_id, h.name AS host, h.address,
                    s.gen, s.model, s.last, s.updated_at FROM checks c JOIN hosts h ON h.id = c.host_id LEFT JOIN shelly_state s ON s.check_id = c.id
                    WHERE c.type = 'shelly' AND c.enabled = 1 AND h.enabled = 1 ORDER BY h.name")->fetchAll() as $r) {
            $p = json_decode((string) $r['last'], true) ?: [];
            $b = self::band((int) $r['id'], $now);
            $o = self::opts($r);
            $out[] = ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'name' => $r['host'], 'check' => $r['name'], 'address' => $r['address'],
                'status' => $r['status'] !== null ? (int) $r['status'] : null, 'message' => (string) $r['last_message'],
                'gen' => $r['gen'] !== null ? (int) $r['gen'] : null, 'model' => (string) $r['model'], 'at' => $r['updated_at'],
                'w' => $p['w'] ?? null, 'tc' => $p['tc'] ?? null, 'relays' => $p['relays'] ?? [], 'phases' => $p['phases'] ?? [], 'rssi' => $p['rssi'] ?? null,
                'update' => $p['update'] ?? '', 'overtemp' => !empty($p['overtemp']), 'kwh' => self::kwhToday((int) $r['id'], $now),
                'band' => $b, 'warn' => $r['warn_threshold'] !== null ? (float) $r['warn_threshold'] : 70.0, 'crit' => $r['crit_threshold'] !== null ? (float) $r['crit_threshold'] : 80.0,
                'anom' => self::anomaly((int) $r['id'], $o, $now), 'learning' => $b === null];
        }
        $w = array_sum(array_map(static fn($x) => (float) ($x['w'] ?? 0), $out));
        $warm = null;
        foreach ($out as $x) {
            if ($x['tc'] !== null && ($warm === null || $x['tc'] > $warm['tc'])) {
                $warm = $x;
            }
        }
        return ['devices' => $out, 'w' => round($w), 'kwh' => round(array_sum(array_map(static fn($x) => (float) ($x['kwh'] ?? 0), $out)), 1),
            'anomalies' => count(array_filter($out, static fn($x) => $x['anom'] !== null)), 'warmest' => $warm ? ['name' => $warm['name'], 'tc' => $warm['tc']] : null];
    }

    /** @return array<string, mixed> vandaag per 5 minuten (vermogen en temperatuur) en de normale band per uur, voor de grafieken */
    public static function day(int $checkId): array
    {
        $ts = time();
        $pts = q("SELECT FLOOR(UNIX_TIMESTAMP(at) / 300) * 300 AS t, AVG(w) AS w, MAX(w) AS wmax, MAX(tc) AS tc FROM shelly_samples
                  WHERE check_id = ? AND at >= ? GROUP BY t ORDER BY t", [$checkId, date('Y-m-d 00:00:00', $ts)])->fetchAll();
        $bands = [];
        for ($h = 0; $h <= (int) date('G', $ts); $h++) {
            $b = self::band($checkId, strtotime(date('Y-m-d', $ts) . sprintf(' %02d:30:00', $h)));
            $bands[] = $b ? ['h' => $h, 'lo' => $b['lo'], 'hi' => $b['hi']] : ['h' => $h, 'lo' => null, 'hi' => null];
        }
        return ['points' => array_map(static fn($p) => ['t' => (int) $p['t'], 'w' => $p['w'] !== null ? round((float) $p['w'], 1) : null,
            'wmax' => $p['wmax'] !== null ? round((float) $p['wmax'], 1) : null, 'tc' => $p['tc'] !== null ? round((float) $p['tc'], 1) : null], $pts), 'bands' => $bands];
    }

    /**
     * Shelly's op het netwerk zoeken: de apparaten die de netwerkscan kent (en eventueel een eigen adres), gevraagd naar
     * GET /shelly: de gevonden Shelly's, met of ze al bewaakt worden
     * @return list<array<string, mixed>>
     */
    public static function discover(string $extra = ''): array
    {
        $ips = q("SELECT ip FROM net_devices WHERE ip <> '' AND last_seen >= NOW() - INTERVAL 7 DAY ORDER BY ip")->fetchAll(PDO::FETCH_COLUMN);
        foreach (parse_list($extra) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        $ips = array_slice(array_values(array_unique(array_filter($ips, static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP)))), 0, 512);
        $have = [];
        foreach (q("SELECT h.address, c.target FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.type = 'shelly'")->fetchAll() as $r) {
            $have[trim((string) ($r['target'] ?: $r['address']))] = true;
        }
        $found = [];
        foreach (array_chunk($ips, 32) as $chunk) {
            $mh = curl_multi_init();
            $hs = [];
            foreach ($chunk as $ip) {
                $ch = curl_init('http://' . (str_contains($ip, ':') ? "[$ip]" : $ip) . '/shelly');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_USERAGENT => 'RzSense']);
                curl_multi_add_handle($mh, $ch);
                $hs[$ip] = $ch;
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($running && $st === CURLM_OK);
            foreach ($hs as $ip => $ch) {
                $body = (string) curl_multi_getcontent($ch);
                if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && ($info = self::info(json_decode($body, true) ?: []))) {
                    $found[] = ['ip' => $ip, 'have' => isset($have[$ip])] + $info;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        return $found;
    }

    /**
     * Gevonden Shelly's toevoegen: per Shelly een server (alleen netwerk, soort Smart home) met een Shelly-check.
     * @param list<array<string, mixed>> $list [{ip, name, pass}]
     * @return array{added: int, skipped: list<string>}
     */
    public static function add(array $list): array
    {
        $added = 0;
        $skip = [];
        foreach (array_slice($list, 0, 100) as $d) {
            $ip = (string) ($d['ip'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (q("SELECT 1 FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.type = 'shelly' AND (h.address = ? OR c.target = ?)", [$ip, $ip])->fetchColumn()) {
                $skip[] = $ip;
                continue;
            }
            $name = trim(mb_substr((string) ($d['name'] ?? ''), 0, 60)) ?: 'Shelly ' . $ip;
            $hid = (int) (q('SELECT id FROM hosts WHERE address = ? LIMIT 1', [$ip])->fetchColumn() ?: 0);
            if (!$hid) {
                q("INSERT INTO hosts (name, address, mode, enabled, kind) VALUES (?, ?, 'remote', 1, 'smarthome')", [$name, $ip]);
                $hid = (int) db()->lastInsertId();
            }
            $pass = mb_substr((string) ($d['pass'] ?? ''), 0, 200);
            q("INSERT INTO checks (host_id, type, name, target, warn_threshold, crit_threshold, interval_sec, options, enabled) VALUES (?, 'shelly', 'Shelly', '', 70, 80, 60, ?, 1)",
                [$hid, json_encode(array_filter(['pass' => $pass, 'anom' => '15', 'zero' => 1, 'on' => 1]), JSON_UNESCAPED_UNICODE)]);
            $added++;
        }
        return ['added' => $added, 'skipped' => $skip];
    }
}
