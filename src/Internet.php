<?php
declare(strict_types=1);

/**
 * Internet (1.157.0): bewaakt de internetlijn vanaf de server van RzMon. Eén check per meting (kolom metric):
 * - ping: pingtijd, jitter en pakketverlies naar 1.1.1.1 en 8.8.8.8, en je eigen router (zo zie je of het binnen of buiten zit);
 * - resp: responstijd van een paar bekende sites (DNS, verbinden, TLS, eerste antwoord);
 * - down / up: snelheid in Mbit/s uit de laatste snelheidstest. Die draait op vaste tijden (Instellingen → Monitoring → Internet,
 *   standaard 4× per dag) via speed.cloudflare.com, en vergelijkt met je abonnement (standaard 1000/1000 Mbit/s).
 */
final class Internet
{
    public const METRICS = ['ping', 'resp', 'down', 'up'];
    public const TIMES = '03:30,09:30,13:30,17:30';
    public const PING_TARGETS = ['1.1.1.1', '8.8.8.8'];
    public const RESP_URLS = ['https://www.google.com/generate_204', 'https://www.cloudflare.com/cdn-cgi/trace'];
    private const STREAMS = 4;            // parallelle verbindingen bij de snelheidstest
    private const CHUNK = 25000000;       // bytes per verzoek
    private const MAX_SECONDS = 10.0;     // per richting
    private const WARMUP = 0.5;           // de eerste halve seconde telt niet mee (opstarten van TCP)
    /** @var array<string, mixed>|null */
    private static ?array $lastRow = null;

    public static function run(array $check): array
    {
        return match ((string) ($check['metric'] ?? '')) {
            'ping' => self::ping($check),
            'resp' => self::resp($check),
            'down', 'up' => self::speed($check, (string) $check['metric']),
            default => Checks::result(STATUS_UNKNOWN, null, t('Kies wat je van internet wilt bewaken')),
        };
    }

    // ------------------------------------------------------------------ Instellingen

    /** Abonnement in Mbit/s */
    public static function plan(string $dir): float
    {
        return max(1.0, (float) setting($dir === 'up' ? 'inet_up' : 'inet_down', '1000'));
    }

    /** @return array{0: int, 1: int} warning en critical, in procent van het abonnement */
    public static function pct(): array
    {
        $w = max(1, min(100, (int) setting('inet_warn_pct', '70')));
        $c = max(1, min($w, (int) setting('inet_crit_pct', '40')));
        return [$w, $c];
    }

    /** Tijden van de snelheidstest (gesorteerd, uniek, HH:MM) */
    public static function times(?string $raw = null): array
    {
        preg_match_all('/\b([01]?\d|2[0-3])[:.]([0-5]\d)\b/', $raw ?? (string) setting('inet_times', self::TIMES), $m, PREG_SET_ORDER);
        $out = array_values(array_unique(array_map(static fn($x) => sprintf('%02d:%02d', (int) $x[1], (int) $x[2]), $m)));
        sort($out);
        return array_slice($out, 0, 24);
    }

    /** Netjes maken voor opslaan (Instellingen): lege of foute invoer = de standaard */
    public static function cleanTimes(string $raw): string
    {
        $t = self::times($raw);
        return $t ? implode(',', $t) : self::TIMES;
    }

    private static function speedBase(): string
    {
        $u = rtrim((string) setting('inet_speed_url', 'https://speed.cloudflare.com'), '/');
        return preg_match('#^https?://[^\s/]+(/[^\s]*)?$#', $u) ? $u : 'https://speed.cloudflare.com';
    }

    /** Megabytes per richting waarna de test stopt (ook bij een snelle lijn blijft het verbruik zo beperkt) */
    private static function capBytes(): int
    {
        return max(10, min(2000, (int) setting('inet_speed_mb', '250'))) * 1000000;
    }

    // ------------------------------------------------------------------ Ping

    /** Standaardgateway van de server van RzMon (uit /proc/net/route), of null */
    public static function gateway(): ?string
    {
        $lines = @file('/proc/net/route', FILE_IGNORE_NEW_LINES) ?: [];
        foreach (array_slice($lines, 1) as $l) {
            $f = preg_split('/\s+/', trim($l)) ?: [];
            if (($f[1] ?? '') === '00000000' && isset($f[2]) && preg_match('/^[0-9A-Fa-f]{8}$/', $f[2])) {
                $ip = long2ip((int) hexdec(implode('', array_reverse(str_split($f[2], 2)))));
                if ($ip !== '0.0.0.0') {
                    return $ip;
                }
            }
        }
        return null;
    }

    /** Uitvoer van ping lezen: tijden per antwoord en het verlies in procent */
    public static function parsePing(string $out): array
    {
        preg_match_all('/time[=<]\s*([\d.]+)\s*ms/', $out, $m);
        $times = array_map('floatval', $m[1]);
        $loss = preg_match('/([\d.]+)% packet loss/', $out, $l) ? (float) $l[1] : ($times ? 0.0 : 100.0);
        $jit = 0.0;
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $jit += abs($times[$i] - $times[$i - 1]);
        }
        return ['times' => $times, 'avg' => $times ? array_sum($times) / count($times) : null, 'loss' => $loss,
            'jitter' => count($times) > 1 ? $jit / (count($times) - 1) : 0.0, 'sent' => max(1, (int) (preg_match('/(\d+) packets transmitted/', $out, $s) ? $s[1] : 5))];
    }

    /** Een paar adressen tegelijk pingen (5 keer, binnen ~2 seconden) */
    private static function pingMany(array $targets): array
    {
        $bin = (string) config('ping_bin', '/bin/ping');
        $procs = [];
        foreach ($targets as $t) {
            $p = proc_open(rz_sh(sprintf('%s -n -c 5 -i 0.2 -W 2 %s 2>&1', escapeshellcmd($bin), escapeshellarg($t))), [1 => ['pipe', 'w']], $pipes);
            if (is_resource($p)) {
                $procs[$t] = [$p, $pipes[1]];
            }
        }
        $out = [];
        foreach ($procs as $t => [$p, $pipe]) {
            $out[$t] = self::parsePing((string) stream_get_contents($pipe));
            fclose($pipe);
            proc_close($p);
        }
        return $out;
    }

    private static function ping(array $check): array
    {
        $gw = self::gateway();
        $res = self::pingMany(array_merge(self::PING_TARGETS, $gw ? [$gw] : []));
        return self::pingResult($check, $res, $gw);
    }

    /** Uitslag van de ping-check uit de losse metingen (apart, zodat de tests hem zonder netwerk kunnen nagaan) */
    public static function pingResult(array $check, array $res, ?string $gw): array
    {
        $ext = array_intersect_key($res, array_flip(self::PING_TARGETS));
        $ok = array_filter($ext, static fn($r) => $r['avg'] !== null);
        $gwr = $gw !== null ? ($res[$gw] ?? null) : null;
        $gwTxt = $gwr && $gwr['avg'] !== null ? t('router {ms} ms', ['ms' => self::ms($gwr['avg'])]) : null;
        if (!$ok) {
            if ($gwr && $gwr['avg'] !== null) {
                return Checks::result(STATUS_CRIT, null, t('Geen internet: je router ({ip}) antwoordt wel ({ms} ms), {t} niet', ['ip' => $gw, 'ms' => self::ms($gwr['avg']), 't' => implode(' en ', self::PING_TARGETS)]));
            }
            if ($gw !== null) {
                return Checks::result(STATUS_CRIT, null, t('Geen internet, en je router ({ip}) antwoordt ook niet: het probleem zit in je eigen netwerk', ['ip' => $gw]));
            }
            return Checks::result(STATUS_CRIT, null, t('Geen internet: {t} antwoorden niet', ['t' => implode(' en ', self::PING_TARGETS)]));
        }
        uasort($ok, static fn($a, $b) => $a['avg'] <=> $b['avg']);
        $best = reset($ok);
        $lost = $sent = 0;
        foreach ($ext as $r) {
            $sent += $r['sent'];
            $lost += $r['sent'] * $r['loss'] / 100;
        }
        $loss = $sent ? round($lost / $sent * 100) : 0;
        $parts = [];
        foreach ($ext as $ip => $r) {
            $parts[] = $ip . ' ' . ($r['avg'] !== null ? self::ms($r['avg']) . ' ms' : t('geen antwoord'));
        }
        $msg = t('{ms} ms ({p}) · jitter {j} ms · verlies {l}%', ['ms' => self::ms($best['avg']), 'p' => implode(' · ', $parts), 'j' => self::ms($best['jitter']), 'l' => $loss])
            . ($gwTxt ? ' · ' . $gwTxt : '');
        $status = Checks::threshold($best['avg'], $check);
        if ($loss >= 20 || count($ok) < count($ext)) {
            $status = max($status, STATUS_WARN);
        }
        return Checks::result($status, round($best['avg'], 2), $msg);
    }

    private static function ms(float $v): string
    {
        return I18n::dec((string) ($v < 10 ? round($v, 1) : round($v)));
    }

    // ------------------------------------------------------------------ Responstijd

    private static function resp(array $check): array
    {
        $mh = curl_multi_init();
        $hs = [];
        foreach (self::RESP_URLS as $u) {
            $h = curl_init($u);
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FRESH_CONNECT => true,
                CURLOPT_FORBID_REUSE => true, CURLOPT_DNS_CACHE_TIMEOUT => 0, CURLOPT_NOBODY => false, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            curl_multi_add_handle($mh, $h);
            $hs[$u] = $h;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK);
        $res = [];
        foreach ($hs as $u => $h) {
            $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
            $res[$u] = $code > 0 && $code < 500 ? ['dns' => curl_getinfo($h, CURLINFO_NAMELOOKUP_TIME) * 1000, 'conn' => curl_getinfo($h, CURLINFO_CONNECT_TIME) * 1000,
                'tls' => curl_getinfo($h, CURLINFO_APPCONNECT_TIME) * 1000, 'ttfb' => curl_getinfo($h, CURLINFO_STARTTRANSFER_TIME) * 1000]
                : ['error' => curl_error($h) ?: 'HTTP ' . $code];
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
        }
        curl_multi_close($mh);
        return self::respResult($check, $res);
    }

    public static function respResult(array $check, array $res): array
    {
        $ok = array_filter($res, static fn($r) => isset($r['ttfb']));
        $host = static fn(string $u) => preg_replace('/^www\./', '', (string) parse_url($u, PHP_URL_HOST));
        if (!$ok) {
            return Checks::result(STATUS_CRIT, null, t('Geen antwoord van {s}', ['s' => implode(' en ', array_map($host, array_keys($res)))]) . ': '
                . mb_substr(implode('; ', array_column($res, 'error')), 0, 150));
        }
        $avg = static fn(string $k) => array_sum(array_column($ok, $k)) / count($ok);
        $ttfb = $avg('ttfb');
        $parts = [];
        foreach ($res as $u => $r) {
            $parts[] = $host($u) . ' ' . (isset($r['ttfb']) ? self::ms($r['ttfb']) . ' ms' : t('geen antwoord'));
        }
        $msg = t('{ms} ms gem. (DNS {d} · verbinden {c} · TLS {t} · eerste antwoord {f}) · {p}', ['ms' => self::ms($ttfb), 'd' => self::ms($avg('dns')),
            'c' => self::ms(max(0, $avg('conn') - $avg('dns'))), 't' => self::ms(max(0, $avg('tls') - $avg('conn'))), 'f' => self::ms($ttfb), 'p' => implode(' · ', $parts)]);
        $status = Checks::threshold($ttfb, $check);
        if (count($ok) < count($res)) {
            $status = max($status, STATUS_WARN);
        }
        return Checks::result($status, round($ttfb, 1), $msg);
    }

    // ------------------------------------------------------------------ Snelheid

    /** De laatste snelheidstest, of null */
    public static function last(): ?array
    {
        if (self::$lastRow === null) {
            self::$lastRow = q('SELECT * FROM inet_speed ORDER BY id DESC LIMIT 1')->fetch() ?: [];
        }
        return self::$lastRow ?: null;
    }

    private static function speed(array $check, string $dir): array
    {
        $r = self::last();
        if (!$r) {
            return Checks::result(STATUS_UNKNOWN, null, t('Nog geen snelheidstest; de eerste volgt binnen een paar minuten'));
        }
        $when = strtotime((string) $r['at']) ?: 0;
        if ($r[$dir] === null) {
            return Checks::result(STATUS_UNKNOWN, null, t('Snelheidstest van {t} mislukt: {e}', ['t' => date('H:i', $when), 'e' => (string) $r['err']]));
        }
        if ($when < time() - 26 * 3600) {
            return Checks::result(STATUS_UNKNOWN, (float) $r[$dir], t('De laatste snelheidstest is van {t}', ['t' => date('d-m H:i', $when)]));
        }
        $v = (float) $r[$dir];
        $plan = self::plan($dir);
        [$w, $c] = self::pct();
        $p = $v / $plan * 100;
        $status = $p < $c ? STATUS_CRIT : ($p < $w ? STATUS_WARN : STATUS_OK);
        $msg = t('{a} {v} Mbit/s ({p}% van {plan}) · test van {t}', ['a' => $dir === 'down' ? '↓' : '↑', 'v' => (string) round($v), 'p' => round($p),
            'plan' => (string) round($plan), 't' => date('H:i', $when)])
            . ($r['colo'] ? ' ' . t('via {c}', ['c' => $r['colo']]) : '')
            . ($r['lat'] !== null ? ' · ' . t('ping {ms} ms', ['ms' => self::ms((float) $r['lat'])]) : '');
        return Checks::result($status, round($v, 1), $msg);
    }

    /** Is er een check die de snelheid gebruikt? */
    public static function wanted(): bool
    {
        return (bool) q("SELECT 1 FROM checks WHERE type = 'internet' AND metric IN ('down', 'up') AND enabled = 1 LIMIT 1")->fetchColumn();
    }

    /** Het laatste geplande moment tot nu (vandaag, of anders gisteren de laatste tijd) */
    public static function lastSlot(int $now): ?int
    {
        $times = self::times();
        if (!$times) {
            return null;
        }
        $best = null;
        foreach ([0, -1] as $day) {
            foreach ($times as $hm) {
                $t = strtotime(date('Y-m-d', $now + $day * 86400) . ' ' . $hm . ':00');
                if ($t !== false && $t <= $now && ($best === null || $t > $best)) {
                    $best = $t;
                }
            }
            if ($best !== null) {
                break;
            }
        }
        return $best;
    }

    /** Volgende geplande test */
    public static function nextSlot(int $now): ?int
    {
        foreach ([0, 1] as $day) {
            foreach (self::times() as $hm) {
                $t = strtotime(date('Y-m-d', $now + $day * 86400) . ' ' . $hm . ':00');
                if ($t !== false && $t > $now) {
                    return $t;
                }
            }
        }
        return null;
    }

    public static function due(?int $now = null): bool
    {
        $now ??= time();
        if (!self::wanted()) {
            return false;
        }
        $running = strtotime((string) setting('inet_speed_running', '')) ?: 0;
        if ($running > $now - 600) {
            return false;   // er loopt al een test (of hij is net vastgelopen)
        }
        $last = self::last();
        if (!$last) {
            return true;    // nog nooit getest: meteen
        }
        $slot = self::lastSlot($now);
        return $slot !== null && (strtotime((string) $last['at']) ?: 0) < $slot;
    }

    /** Elke minuut (cron, na de checks): de snelheidstest als het tijd is */
    public static function cron(): ?string
    {
        if (!self::due()) {
            return null;
        }
        $r = self::speedtest();
        return $r['err'] === null
            ? sprintf('Snelheidstest: ↓ %.0f / ↑ %.0f Mbit/s', $r['down'], $r['up'])
            : 'Snelheidstest mislukt: ' . $r['err'];
    }

    /**
     * De snelheidstest: eerst de pingtijd naar de testserver, dan download en upload, elk met 4 verbindingen tegelijk, tot 10 seconden
     * of het ingestelde aantal megabytes. Slaat de uitslag op en geeft hem terug.
     */
    public static function speedtest(): array
    {
        save_setting('inet_speed_running', now());
        @set_time_limit(90);
        $base = self::speedBase();
        $row = ['down' => null, 'up' => null, 'lat' => null, 'mb_down' => null, 'mb_up' => null, 'colo' => null, 'err' => null];
        try {
            [$row['lat'], $row['colo']] = self::latency($base);
            [$row['down'], $bytes] = self::transfer($base, 'down');
            $row['mb_down'] = round($bytes / 1e6, 1);
            [$row['up'], $bytes] = self::transfer($base, 'up');
            $row['mb_up'] = round($bytes / 1e6, 1);
        } catch (Throwable $e) {
            $row['err'] = mb_substr($e->getMessage(), 0, 250);
        }
        q('INSERT INTO inet_speed (at, down, up, lat, mb_down, mb_up, colo, err) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [now(), $row['down'], $row['up'], $row['lat'], $row['mb_down'], $row['mb_up'], $row['colo'], $row['err']]);
        save_setting('inet_speed_running', '');
        self::$lastRow = null;
        return $row;
    }

    private static function curl(string $url): CurlHandle
    {
        $h = curl_init($url);
        if ($h === false) {
            throw new RuntimeException('curl');
        }
        curl_setopt_array($h, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        return $h;
    }

    /** @return array{0: ?float, 1: ?string} pingtijd naar de testserver (beste van 3, eerste antwoord) en de locatie (uit cf-ray) */
    private static function latency(string $base): array
    {
        $best = null;
        $colo = null;
        for ($i = 0; $i < 3; $i++) {
            $h = self::curl($base . '/__down?bytes=0');
            curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$colo) {
                if (preg_match('/^cf-ray:\s*\S+-([A-Z]{3})\s*$/i', $line, $m)) {
                    $colo = strtoupper($m[1]);
                }
                return strlen($line);
            }]);
            curl_exec($h);
            $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 400) {
                $err = curl_error($h) ?: 'HTTP ' . $code;
                curl_close($h);
                throw new RuntimeException(t('testserver niet bereikbaar ({e})', ['e' => $err]));
            }
            // Eerste antwoord min het opzetten van de verbinding: zo goed als de pingtijd
            $ms = (curl_getinfo($h, CURLINFO_STARTTRANSFER_TIME) - curl_getinfo($h, CURLINFO_APPCONNECT_TIME) ?: curl_getinfo($h, CURLINFO_STARTTRANSFER_TIME)) * 1000;
            $best = $best === null ? $ms : min($best, $ms);
            curl_close($h);
        }
        return [$best !== null ? round($best, 1) : null, $colo];
    }

    /** @return array{0: float, 1: int} Mbit/s en het aantal bytes */
    private static function transfer(string $base, string $dir): array
    {
        $cap = self::capBytes();
        $block = random_bytes(65536);
        $total = 0;
        $t0 = microtime(true);
        $mark = null;   // [tijd, bytes] na de opwarmtijd
        $stop = false;
        $mh = curl_multi_init();
        $add = static function () use ($mh, $base, $dir, $block, &$total, &$stop) {
            $sent = 0;
            $done = 0;
            if ($dir === 'down') {
                $h = self::curl($base . '/__down?bytes=' . self::CHUNK);
                curl_setopt($h, CURLOPT_WRITEFUNCTION, static function ($h, string $d) use (&$total, &$stop): int {
                    $total += strlen($d);
                    return $stop ? 0 : strlen($d);
                });
            } else {
                $h = self::curl($base . '/__up');
                curl_setopt_array($h, [CURLOPT_UPLOAD => true, CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_INFILESIZE => self::CHUNK,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Expect:'], CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_READFUNCTION => static function ($h, $fd, int $len) use ($block, &$sent, &$stop): string {
                        if ($stop) {
                            return '';
                        }
                        $n = min($len, strlen($block), self::CHUNK - $sent);
                        $sent += $n;
                        return $n > 0 ? substr($block, 0, $n) : '';
                    },
                    // Tellen wat echt verstuurd is (niet wat in de buffer staat)
                    CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => static function ($h, $dt, $dn, $ut, int $un) use (&$total, &$stop, &$done): int {
                        $total += max(0, $un - $done);   // $done: wat deze verbinding al had verstuurd
                        $done = max($done, $un);
                        return $stop ? 1 : 0;
                    }]);
            }
            curl_multi_add_handle($mh, $h);
        };
        for ($i = 0; $i < self::STREAMS; $i++) {
            $add();
        }
        $errors = [];
        do {
            curl_multi_exec($mh, $running);
            while ($info = curl_multi_info_read($mh)) {
                $h = $info['handle'];
                $code = (int) curl_getinfo($h, CURLINFO_HTTP_CODE);
                if ($info['result'] !== CURLE_OK && !$stop) {
                    $errors[] = curl_error($h) ?: 'curl ' . $info['result'];
                } elseif ($code >= 400) {
                    $errors[] = 'HTTP ' . $code;
                }
                curl_multi_remove_handle($mh, $h);
                curl_close($h);
                if (!$stop && count($errors) < 3) {
                    $add();
                }
            }
            $el = microtime(true) - $t0;
            if ($mark === null && $el >= self::WARMUP) {
                $mark = [$el, $total];
            }
            if ($el >= self::MAX_SECONDS || $total >= $cap) {
                $stop = true;
            }
            if (count($errors) >= 3 && $total === 0) {
                break;
            }
            if ($running) {
                curl_multi_select($mh, 0.1);
            }
        } while (!$stop || $running);
        $el = microtime(true) - $t0;
        curl_multi_close($mh);
        if ($total === 0) {
            throw new RuntimeException(($dir === 'down' ? t('download') : t('upload')) . ': ' . ($errors[0] ?? t('geen gegevens')));
        }
        // Na de opwarmtijd meten; was het meeste toen al binnen (heel snelle lijn of kleine test), dan alles
        $mbit = $mark !== null && $el - $mark[0] >= 1.0 && $mark[1] < $total * 0.7
            ? ($total - $mark[1]) * 8 / ($el - $mark[0]) / 1e6
            : $total * 8 / max(0.001, $el) / 1e6;
        return [round($mbit, 1), $total];
    }

    /** Snelheidstesten van de laatste dagen (voor de grafiek), oudste eerst */
    public static function history(int $days = 30): array
    {
        return array_map(static fn($r) => ['at' => strtotime((string) $r['at']), 'down' => $r['down'] !== null ? (float) $r['down'] : null,
            'up' => $r['up'] !== null ? (float) $r['up'] : null, 'lat' => $r['lat'] !== null ? (float) $r['lat'] : null, 'err' => $r['err']],
            q('SELECT at, down, up, lat, err FROM inet_speed WHERE at >= NOW() - INTERVAL ? DAY ORDER BY at', [$days])->fetchAll());
    }

    public static function purge(): void
    {
        q('DELETE FROM inet_speed WHERE at < NOW() - INTERVAL 400 DAY');
    }

    /** Voor Instellingen → Monitoring → Internet */
    public static function state(): array
    {
        $host = q("SELECT h.id, h.name FROM hosts h JOIN checks c ON c.host_id = h.id WHERE c.type = 'internet' ORDER BY h.id LIMIT 1")->fetch() ?: null;
        $last = self::last();
        $next = self::wanted() ? self::nextSlot(time()) : null;
        return ['host' => $host ? ['id' => (int) $host['id'], 'name' => $host['name']] : null, 'last' => $last ? [
            'at' => strtotime((string) $last['at']), 'down' => $last['down'] !== null ? (float) $last['down'] : null, 'up' => $last['up'] !== null ? (float) $last['up'] : null,
            'lat' => $last['lat'] !== null ? (float) $last['lat'] : null, 'mb' => (float) $last['mb_down'] + (float) $last['mb_up'], 'colo' => $last['colo'], 'err' => $last['err']] : null,
            'next' => $next, 'times' => self::times(), 'gateway' => self::gateway(), 'history' => self::history(30),
            'running' => (strtotime((string) setting('inet_speed_running', '')) ?: 0) > time() - 120];
    }

    /**
     * Eén klik (Instellingen → Internet): een apparaat "Internet" met de vier checks. Bestaat er al een, dan alleen de ontbrekende checks.
     * @return int het id van het apparaat
     */
    public static function install(): int
    {
        $hid = (int) q("SELECT h.id FROM hosts h JOIN checks c ON c.host_id = h.id WHERE c.type = 'internet' ORDER BY h.id LIMIT 1")->fetchColumn();
        if (!$hid) {
            q("INSERT INTO hosts (name, address, mode, kind) VALUES (?, '1.1.1.1', 'remote', 'internet')", [t('Internet')]);
            $hid = (int) db()->lastInsertId();
            ChangeLog::record('host', $hid, 'create', '🌐 ' . t('Internet-check toegevoegd'), null, ChangeLog::hostRow($hid));
        }
        $have = q("SELECT metric FROM checks WHERE host_id = ? AND type = 'internet'", [$hid])->fetchAll(PDO::FETCH_COLUMN);
        $defs = [
            'down' => [t('Download'), null, null, 300],
            'up' => [t('Upload'), null, null, 300],
            'ping' => [t('Ping'), 50, 150, 60],
            'resp' => [t('Responstijd'), 500, 2000, 300],
        ];
        foreach ($defs as $m => [$name, $w, $c, $iv]) {
            if (in_array($m, $have, true)) {
                continue;
            }
            q("INSERT INTO checks (host_id, type, metric, name, target, warn_threshold, crit_threshold, interval_sec, thresh_mode) VALUES (?, 'internet', ?, ?, '', ?, ?, ?, ?)",
                [$hid, $m, $name, $w, $c, $iv, in_array($m, ['ping', 'resp'], true) ? 'auto' : 'manual']);
        }
        return $hid;
    }
}
