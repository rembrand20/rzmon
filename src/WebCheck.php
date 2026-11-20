<?php
declare(strict_types=1);

/**
 * 1.299.0: lichte webcheck per site achter de reverse proxy (A tot F).
 * RzSense vraagt zelf https://<site>/ op (en http:// om te zien of die doorstuurt) en kijkt naar het certificaat en de
 * beveiligingsheaders. Geen externe dienst: alleen een verzoek naar je eigen site, met een gewone certificaatcontrole.
 */
final class WebCheck
{
    /** Onderdelen met hun maximale punten (samen 100) */
    public const PARTS = ['tls' => 20, 'redirect' => 10, 'hsts' => 15, 'csp' => 15, 'frame' => 10, 'nosniff' => 10, 'referrer' => 5, 'perms' => 5, 'leak' => 10];

    /** Een geldige sitenaam (geen jokerteken, geen pad) */
    public static function validSite(string $s): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?:\.[a-z0-9-]{1,63})+$/i', $s) && !str_starts_with($s, '*');
    }

    /**
     * Welke naam opvragen voor een site uit de proxy: de eerste echte naam, of bij "naam.*" (SWAG) de naam met je domein
     * (instelling webcheck_domain, anders het domein van het adres van RzSense). Null als dat niet lukt.
     * @param array{site: string, names?: list<string>} $s
     */
    public static function target(array $s): ?string
    {
        $names = array_map('strtolower', array_merge($s['names'] ?? [], [$s['site']]));
        foreach ($names as $n) {
            if (self::validSite($n)) {
                return $n;
            }
        }
        $dom = strtolower(trim((string) setting('webcheck_domain', ''), ". \t"));
        if (!self::validSite($dom)) {
            $dom = ProxyOpen::parentDomain((string) parse_url((string) setting('base_url', ''), PHP_URL_HOST));
        }
        foreach ($names as $n) {
            if ($dom !== '' && preg_match('/^([a-z0-9-]{1,63})\.\*$/', $n, $m) && self::validSite($m[1] . '.' . $dom)) {
                return $m[1] . '.' . $dom;
            }
        }
        return null;
    }

    public static function grade(int $n, bool $tlsOk): string
    {
        if (!$tlsOk) {
            return 'F';
        }
        return $n >= 90 ? 'A' : ($n >= 75 ? 'B' : ($n >= 60 ? 'C' : ($n >= 45 ? 'D' : ($n >= 30 ? 'E' : 'F'))));
    }

    /**
     * Beoordeel de headers van een antwoord (kleine letters als sleutel). Los van het ophalen, zodat het te testen is.
     * @param array<string, string> $h
     * @return array{n: int, grade: string, parts: list<array{k: string, got: int, max: int, ok: bool, note: string}>}
     */
    public static function score(array $h, bool $tlsOk, bool $redirect, ?int $certDays): array
    {
        $p = [];
        $add = static function (string $k, int $got, string $note) use (&$p): void {
            $p[] = ['k' => $k, 'got' => $got, 'max' => self::PARTS[$k], 'ok' => $got >= self::PARTS[$k], 'note' => $note];
        };
        $add('tls', $tlsOk ? ($certDays !== null && $certDays < 14 ? 10 : 20) : 0,
            $tlsOk ? ($certDays !== null ? t('certificaat nog {d} dagen geldig', ['d' => $certDays]) : t('geldig certificaat')) : t('https werkt niet of het certificaat klopt niet'));
        $add('redirect', $redirect ? 10 : 0, $redirect ? t('http stuurt door naar https') : t('http stuurt niet door naar https'));
        $hsts = (int) (preg_match('/max-age\s*=\s*"?(\d+)/i', $h['strict-transport-security'] ?? '', $m) ? $m[1] : 0);
        $add('hsts', $hsts >= 15552000 ? 15 : ($hsts > 0 ? 8 : 0), $hsts ? t('HSTS {d} dagen', ['d' => intdiv($hsts, 86400)]) : t('geen HSTS'));
        $csp = $h['content-security-policy'] ?? '';
        $weak = $csp !== '' && (str_contains($csp, "'unsafe-inline'") && !preg_match("/'nonce-|'strict-dynamic'/", $csp) || preg_match('/(?:default|script)-src[^;]*\s\*(?:\s|;|$)/', $csp));
        $add('csp', $csp === '' ? 0 : ($weak ? 8 : 15), $csp === '' ? t('geen Content-Security-Policy') : ($weak ? t('Content-Security-Policy, maar met unsafe-inline of *') : t('Content-Security-Policy')));
        $frame = isset($h['x-frame-options']) || str_contains($csp, 'frame-ancestors');
        $add('frame', $frame ? 10 : 0, $frame ? t('niet in een frame van een andere site') : t('geen X-Frame-Options of frame-ancestors'));
        $ns = strtolower(trim($h['x-content-type-options'] ?? '')) === 'nosniff';
        $add('nosniff', $ns ? 10 : 0, $ns ? 'nosniff' : t('geen X-Content-Type-Options: nosniff'));
        $add('referrer', isset($h['referrer-policy']) ? 5 : 0, isset($h['referrer-policy']) ? 'Referrer-Policy: ' . mb_substr($h['referrer-policy'], 0, 60) : t('geen Referrer-Policy'));
        $add('perms', isset($h['permissions-policy']) ? 5 : 0, isset($h['permissions-policy']) ? 'Permissions-Policy' : t('geen Permissions-Policy'));
        $leak = [];
        if (preg_match('/\d/', $h['server'] ?? '')) {
            $leak[] = 'Server: ' . mb_substr($h['server'], 0, 60);
        }
        if (isset($h['x-powered-by'])) {
            $leak[] = 'X-Powered-By: ' . mb_substr($h['x-powered-by'], 0, 60);
        }
        if (isset($h['x-aspnet-version'])) {
            $leak[] = 'X-AspNet-Version';
        }
        $add('leak', $leak ? 0 : 10, $leak ? t('verraadt de software: {x}', ['x' => implode(', ', $leak)]) : t('verraadt geen versies'));
        $n = (int) array_sum(array_column($p, 'got'));
        return ['n' => $n, 'grade' => self::grade($n, $tlsOk), 'parts' => $p];
    }

    /**
     * Eén verzoek, zonder doorsturen te volgen; altijd met certificaatcontrole.
     * @return array{code: int, headers: array<string, string>, err: string, next: string, days: ?int}
     */
    private static function fetch(string $url): array
    {
        $hdr = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_RANGE => '0-0', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'RzSense-webcheck', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CERTINFO => true,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$hdr): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    if ($k !== '' && count($hdr) < 80) {
                        $hdr[$k] = isset($hdr[$k]) ? $hdr[$k] . ', ' . trim($v) : trim($v);
                    }
                } elseif (preg_match('#^HTTP/#', $line)) {
                    $hdr = [];   // een nieuw antwoord (bv. na 100 Continue)
                }
                return strlen($line);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $days = null;
        $ci = curl_getinfo($ch, CURLINFO_CERTINFO);
        if (isset($ci[0]['Expire date']) && ($t = strtotime((string) $ci[0]['Expire date']))) {
            $days = (int) floor(($t - time()) / 86400);
        }
        curl_close($ch);
        return ['code' => $code, 'headers' => $hdr, 'err' => $err, 'next' => $next, 'days' => $days];
    }

    /**
     * Controleer een site nu en bewaar het resultaat.
     * @return array<string, mixed>
     */
    public static function run(int $hostId, string $key): array
    {
        $found = array_values(array_filter(ProxyGuard::sites($hostId, ProxyGuard::report($hostId)), static fn($s) => strtolower((string) $s['site']) === strtolower($key)));
        if (!$found) {
            return ['error' => t('Die site staat niet in de proxy')];
        }
        $site = self::target($found[0]);
        if ($site === null) {
            return ['error' => t('Geen volledige naam voor deze site. Vul bij Instellingen → Reverse proxy het domein in voor namen als naam.*')];
        }
        if ($why = ExtraChecks::urlBlocked("https://$site/")) {
            return ['error' => $why];
        }
        $s = self::fetch("https://$site/");
        $tlsOk = $s['code'] > 0 && $s['err'] === '';
        $h = $s['headers'];
        // Stuurt / door naar een pad op dezelfde site (bv. een inlogpagina), dan telt het antwoord daarvan
        if ($tlsOk && $s['code'] >= 300 && $s['code'] < 400 && ($u = parse_url($s['next'])) && strtolower($u['host'] ?? '') === $site && ($u['scheme'] ?? '') === 'https') {
            $s2 = self::fetch($s['next']);
            if ($s2['code'] > 0 && $s2['err'] === '') {
                $h = $s2['headers'] + array_intersect_key($h, ['strict-transport-security' => 1]);
            }
        }
        $p = self::fetch("http://$site/");
        $redirect = $p['code'] >= 300 && $p['code'] < 400 && str_starts_with(strtolower($p['next']), 'https://');
        $r = self::score($h, $tlsOk, $redirect, $s['days']) + ['site' => $site, 'key' => strtolower($key), 'code' => $s['code'], 'err' => $tlsOk ? '' : mb_substr($s['err'] ?: t('geen antwoord'), 0, 200),
            'at' => date('c')];
        q('INSERT INTO web_check (host_id, site, grade, score, result, checked_at) VALUES (?, ?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE host_id = VALUES(host_id), grade = VALUES(grade), score = VALUES(score), result = VALUES(result), checked_at = NOW()',
            [$hostId, strtolower($key), $r['grade'], $r['n'], json_encode($r, JSON_UNESCAPED_UNICODE)]);
        return $r;
    }

    /**
     * Laatste resultaten per site van een server.
     * @return array<string, array<string, mixed>>
     */
    public static function forHost(int $hostId): array
    {
        $out = [];
        foreach (q('SELECT site, result FROM web_check WHERE host_id = ?', [$hostId]) as $r) {
            $out[(string) $r['site']] = json_decode((string) $r['result'], true) ?: [];
        }
        return $out;
    }

    /** Eén keer per dag elke site van een proxy controleren (hooguit 20 per ronde) */
    public static function cron(): void
    {
        if (setting('webcheck_on', '1') !== '1' || setting('proxy_on', '0') !== '1') {
            return;
        }
        $done = 0;
        foreach (q('SELECT id FROM hosts WHERE mode = \'agent\' AND proxy_role = 1') as $h) {
            $hid = (int) $h['id'];
            $last = [];
            foreach (q('SELECT site, checked_at FROM web_check WHERE host_id = ?', [$hid]) as $r) {
                $last[(string) $r['site']] = strtotime((string) $r['checked_at']);
            }
            foreach (ProxyGuard::sites($hid, ProxyGuard::report($hid)) as $s) {
                $key = strtolower((string) $s['site']);
                if (self::target($s) === null || ($last[$key] ?? 0) > time() - 86400) {
                    continue;
                }
                self::run($hid, $key);
                if (++$done >= 20) {
                    return;
                }
            }
        }
        q('DELETE FROM web_check WHERE checked_at < NOW() - INTERVAL 30 DAY');
    }
}
