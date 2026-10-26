<?php
declare(strict_types=1);

/**
 * Logs van webdiensten (1.89.0, agent 1.58.0): SWAG (nginx), Home Assistant en Vaultwarden. De agent stuurt per minuut
 * per IP een samenvatting; hier staan de scenario's (elk aan of uit bij Instellingen → IDS):
 * - bekend aanvalspad met een foutcode (/.env, /.git/, wp-login.php, phpmyadmin …): meteen;
 * - scanner (sqlmap, nikto, nuclei …): meteen;
 * - veel 404's, veel 401/403, of verzoeken zonder (bekende) hostnaam (444) binnen de tijd;
 * - mislukte logins in Home Assistant of Vaultwarden binnen de tijd.
 * Een treffer is een IDS-incident; in de modus blokkeren (en met "blokkeren" aan) ook een automatische blokkade, die
 * via "automatische blokkades op alle servers" ook op de server met SWAG terechtkomt. Je eigen netwerk nooit.
 */
final class WebLog
{
    public const SOURCES = ['swag' => 'SWAG', 'ha' => 'Home Assistant', 'vw' => 'Vaultwarden'];
    /** Paden die alleen aanvallers proberen (alleen geteld met een foutcode: een site die het echt heeft, geeft 200) */
    private const ATTACK = '#(/\.env|/\.git/|/\.aws/|/\.ssh/|/\.htpasswd|wp-login\.php|/wp-admin|xmlrpc\.php|phpmyadmin|/pma/|/cgi-bin/|/boaform|/vendor/phpunit|eval-stdin|/actuator|/hnap1|setup\.cgi|/solr/|/owa/|/autodiscover/|/remote/login|/etc/passwd|\.\./|/manager/html|/wls-wsat|/\.vscode/|/server-status|/geoserver|/telescope)#i';

    public static function enabled(string $src): bool
    {
        return setting("weblog_$src", '1') === '1';
    }

    private static function n(string $name, int $default): int
    {
        return max(1, min(10000, (int) setting($name, (string) $default)));
    }

    /** Regels voor de agent: welke logs, en waar (leeg of auto = zelf zoeken) */
    public static function agentLines(): array
    {
        $out = [];
        foreach (array_keys(self::SOURCES) as $src) {
            if (self::enabled($src)) {
                $p = trim((string) setting("weblog_{$src}_path", 'auto')) ?: 'auto';
                $out[] = implode("\t", [0, 'weblog', $src, preg_replace('/\s+/', '', $p)]);
            }
        }
        return $out;
    }

    /** Nooit: je eigen netwerk (privé en je lokale netwerken) en de whitelist */
    private static function skip(string $ip): bool
    {
        return @inet_pton($ip) === false || ip_in_list($ip, PRIVATE_NETWORKS) || ip_in_list($ip, parse_list((string) setting('trusted_networks', ''))) || Whitelist::ip($ip);
    }

    /** Pogingen tellen binnen de tijd (wl_minutes) */
    private static function count(int $hid, string $src, string $kind, string $ip, int $n): int
    {
        if ($n > 0) {
            q('INSERT INTO weblog_attempts (host_id, src, kind, ip, n, at) VALUES (?, ?, ?, ?, ?, ?)', [$hid, $src, $kind, mb_substr($ip, 0, 45), $n, now()]);
        }
        return (int) q('SELECT COALESCE(SUM(n), 0) FROM weblog_attempts WHERE host_id = ? AND src = ? AND kind = ? AND ip = ? AND at >= ?',
            [$hid, $src, $kind, $ip, date('Y-m-d H:i:s', time() - 60 * self::n('wl_minutes', 10))])->fetchColumn();
    }

    public static function process(array $host, array $weblog): void
    {
        $hid = (int) $host['id'];
        foreach (self::SOURCES as $src => $label) {
            if (!is_array($weblog[$src] ?? null)) {
                continue;
            }
            $w = $weblog[$src];
            $hint = null;
            $ips = (array) ($w['ips'] ?? []);
            // Home Assistant achter SWAG ziet zonder use_x_forwarded_for alleen het IP van SWAG (een intern adres)
            if ($src === 'ha' && $ips && !array_filter(array_keys($ips), static fn($ip) => !ip_in_list((string) $ip, PRIVATE_NETWORKS))) {
                $hint = t('Home Assistant ziet bij mislukte logins alleen interne adressen. Staat het achter SWAG, zet dan in configuration.yaml onder http: use_x_forwarded_for: true en trusted_proxies (het IP van SWAG); anders is een aanvaller niet te herkennen.');
            }
            q('INSERT INTO weblog_state (host_id, src, path, err, hint, seen_at) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE path = VALUES(path), err = VALUES(err), hint = COALESCE(VALUES(hint), hint), seen_at = VALUES(seen_at)',
                [$hid, $src, mb_substr((string) ($w['path'] ?? ''), 0, 255), isset($w['err']) ? mb_substr((string) $w['err'], 0, 255) : null, $hint, now()]);
            if (!Ids::enabled() || !self::enabled($src)) {
                continue;
            }
            foreach (array_slice($ips, 0, 300, true) as $ip => $v) {
                $ip = (string) $ip;
                if (self::skip($ip)) {
                    continue;
                }
                if ($src === 'swag') {
                    self::swag($host, $ip, (array) $v);
                } else {
                    $n = self::count($hid, $src, 'login', $ip, (int) $v);
                    $limit = self::n("wl_{$src}_n", 5);
                    if ($n >= $limit) {
                        Ids::webAttack($host, $src, $ip, t('{src}: {n} mislukte logins vanaf {ip}', ['src' => $label, 'n' => $n, 'ip' => $ip]),
                            t('{n} mislukte logins in {m} minuten (grens {limit}).', ['n' => $n, 'm' => self::n('wl_minutes', 10), 'limit' => $limit]),
                            t('{src}: {n} mislukte logins', ['src' => $label, 'n' => $n]));
                    }
                }
            }
        }
    }

    /** SWAG: [aantal, 404, 401/403, 444, scanner, [paden met een foutcode]] */
    private static function swag(array $host, string $ip, array $v): void
    {
        $hid = (int) $host['id'];
        [$total, $c404, $c40x, $c444, $ua, $paths] = [(int) ($v[0] ?? 0), (int) ($v[1] ?? 0), (int) ($v[2] ?? 0), (int) ($v[3] ?? 0), (string) ($v[4] ?? ''), (array) ($v[5] ?? [])];
        $paths = array_map(static fn($p) => mb_substr((string) $p, 0, 120), array_slice($paths, 0, 8));
        $shown = implode(' · ', array_slice($paths, 0, 5));
        if (setting('wl_paths', '1') === '1' && ($hit = current(array_filter($paths, static fn($p) => (bool) preg_match(self::ATTACK, $p))))) {
            Ids::webAttack($host, 'swag', $ip, t('SWAG: aanvalspad {path} vanaf {ip}', ['path' => $hit, 'ip' => $ip]),
                t('Alleen aanvallers vragen dit op. Paden: {paths}', ['paths' => $shown]), t('SWAG: aanvalspad {path}', ['path' => $hit]));
            return;
        }
        if (setting('wl_ua', '1') === '1' && $ua !== '') {
            Ids::webAttack($host, 'swag', $ip, t('SWAG: scanner {ua} vanaf {ip}', ['ua' => $ua, 'ip' => $ip]),
                t('{n} verzoeken met de scanner {ua}. Paden: {paths}', ['n' => $total, 'ua' => $ua, 'paths' => $shown ?: '–']), t('SWAG: scanner {ua}', ['ua' => $ua]));
            return;
        }
        $m = self::n('wl_minutes', 10);
        foreach ([['wl_404', '404', $c404, 20, t('veel 404 (zoekt naar lekken)')], ['wl_auth', 'auth', $c40x, 10, t('veel 401/403 (raadt wachtwoorden)')],
                     ['wl_nohost', 'nohost', $c444, 5, t('verzoeken zonder bekende hostnaam (scant je IP-adres)')]] as [$key, $kind, $n, $def, $what]) {
            if (setting($key, '1') !== '1') {
                continue;
            }
            $sum = self::count($hid, 'swag', $kind, $ip, $n);
            $limit = self::n("{$key}_n", $def);
            if ($n > 0 && $sum >= $limit) {
                Ids::webAttack($host, 'swag', $ip, t('SWAG: {what} vanaf {ip}', ['what' => $what, 'ip' => $ip]),
                    t('{n} in {m} minuten (grens {limit}). Paden: {paths}', ['n' => $sum, 'm' => $m, 'limit' => $limit, 'paths' => $shown ?: '–']), t('SWAG: {what}', ['what' => $what]));
                return;
            }
        }
    }

    /** Voor de instellingen: waar de logs gevonden zijn, per server */
    public static function status(): array
    {
        return q('SELECT s.src, s.path, s.err, s.hint, s.seen_at, h.name AS host FROM weblog_state s JOIN hosts h ON h.id = s.host_id
                  WHERE s.seen_at >= NOW() - INTERVAL 1 DAY ORDER BY s.src, h.name')->fetchAll();
    }

    public static function purge(): void
    {
        q('DELETE FROM weblog_attempts WHERE at < NOW() - INTERVAL 1 DAY');
    }
}
