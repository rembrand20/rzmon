<?php
declare(strict_types=1);

/**
 * Logs van webdiensten (1.89.0, agent 1.58.0): de reverse proxy (nginx-log, bv. SWAG), Home Assistant en Vaultwarden. De agent stuurt per minuut
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
    public const SOURCES = ['swag' => 'Reverse proxy (nginx)', 'ha' => 'Home Assistant', 'vw' => 'Vaultwarden'];
    /** 1.222.0: soorten reverse proxy die de agent herkent (agent 1.86.0) */
    public const PROXIES = ['swag' => 'SWAG', 'npm' => 'Nginx Proxy Manager', 'nginx' => 'nginx'];
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
            if ($src === 'swag' && isset($w['kind'])) {   // 1.222.0: welke proxy, en of de log de site en de dienst heeft (agent 1.86.0)
                q('UPDATE weblog_state SET kind = ?, ctr = ?, full = ? WHERE host_id = ? AND src = ?', [isset(self::PROXIES[$w['kind']]) ? $w['kind'] : 'nginx',
                    mb_substr((string) ($w['ctr'] ?? ''), 0, 64) ?: null, (int) !empty($w['full']), $hid, $src]);
            }
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
        // 1.222.1: tokens en wachtwoorden in een adres weglakken (?token=***) vóór een incident, melding of de kaart per site
        $paths = array_map(static fn($p) => mb_substr(Inspect::redact((string) $p), 0, 120), array_slice($paths, 0, 8));
        $shown = implode(' · ', array_slice($paths, 0, 5));
        // 1.222.0: per site het aantal, hoeveel een gewoon antwoord kregen, en de dienst erachter (agent 1.86.0)
        $sites = self::sitesOf($v[6] ?? null);
        if ($sites) {
            self::record($hid, $ip, $sites, (string) ($paths[0] ?? ''));
        }
        $wp = $sites ? ['ua' => $ua, 'sites' => $sites] : null;
        if (setting('wl_paths', '1') === '1' && ($hit = current(array_filter($paths, static fn($p) => (bool) preg_match(self::ATTACK, $p))))) {
            Ids::webAttack($host, 'swag', $ip, t('Web: aanvalspad {path} vanaf {ip}', ['path' => $hit, 'ip' => $ip]),
                t('Alleen aanvallers vragen dit op. Paden: {paths}', ['paths' => $shown]), t('Web: aanvalspad {path}', ['path' => $hit]), $wp);
            return;
        }
        if (setting('wl_ua', '1') === '1' && $ua !== '') {
            Ids::webAttack($host, 'swag', $ip, t('Web: scanner {ua} vanaf {ip}', ['ua' => $ua, 'ip' => $ip]),
                t('{n} verzoeken met de scanner {ua}. Paden: {paths}', ['n' => $total, 'ua' => $ua, 'paths' => $shown ?: '–']), t('Web: scanner {ua}', ['ua' => $ua]), $wp);
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
                Ids::webAttack($host, 'swag', $ip, t('Web: {what} vanaf {ip}', ['what' => $what, 'ip' => $ip]),
                    t('{n} in {m} minuten (grens {limit}). Paden: {paths}', ['n' => $sum, 'm' => $m, 'limit' => $limit, 'paths' => $shown ?: '–']), t('Web: {what}', ['what' => $what]), $wp);
                return;
            }
        }
    }

    /** Voor de instellingen: waar de logs gevonden zijn, per server; 1.222.0 met de soort proxy, of de log de site heeft, en het aantal sites */
    public static function status(): array
    {
        $rows = q('SELECT s.host_id, s.src, s.path, s.err, s.hint, s.seen_at, s.kind, s.ctr, s.full, h.name AS host FROM weblog_state s JOIN hosts h ON h.id = s.host_id
                  WHERE s.seen_at >= NOW() - INTERVAL 1 DAY ORDER BY s.src, h.name')->fetchAll();
        $sites = [];
        foreach (q("SELECT host_id, COUNT(DISTINCT site) AS n, SUM(n) AS req FROM web_sites WHERE hour >= NOW() - INTERVAL 1 DAY AND site <> '' GROUP BY host_id")->fetchAll() as $r) {
            $sites[(int) $r['host_id']] = [(int) $r['n'], (int) $r['req']];
        }
        foreach ($rows as &$r) {
            $r['label'] = $r['src'] === 'swag' ? (self::PROXIES[$r['kind'] ?? ''] ?? null) : null;
            $r['full'] = (bool) (int) ($r['full'] ?? 0);
            [$r['sites'], $r['req']] = $sites[(int) $r['host_id']] ?? [0, 0];
            unset($r['host_id']);
        }
        return $rows;
    }

    /** Sites uit het rapport van de agent: [[site, dienst, container, aantal, gewoon antwoord], …]; site '' = geen site (je IP-adres, 444) */
    private static function sitesOf(mixed $list): array
    {
        $out = [];
        foreach (array_slice(is_array($list) ? $list : [], 0, 4) as $x) {
            if (!is_array($x) || count($x) < 5) {
                continue;
            }
            $site = strtolower(trim((string) $x[0]));
            if ($site === '-' || $site === '_' || @inet_pton(trim($site, '[]')) !== false || !preg_match('/^[a-z0-9.\-]{1,190}$/', $site)) {
                $site = '';   // geen (bekende) site: iemand vroeg naar je IP-adres
            }
            $up = preg_replace('/[^A-Za-z0-9.:\[\]_\-]/', '', (string) $x[1]);
            $out[] = [$site, $up === '-' ? '' : mb_substr((string) $up, 0, 80), mb_substr((string) preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $x[2]), 0, 64),
                max(0, (int) $x[3]), max(0, (int) $x[4])];
        }
        return $out;
    }

    /** Per uur, site en aanvaller optellen (voor "Aanvallen per site") */
    private static function record(int $hid, string $ip, array $sites, string $top): void
    {
        $hour = date('Y-m-d H:00:00');
        foreach ($sites as [$site, $up, $ctr, $n, $ok]) {
            q('INSERT INTO web_sites (host_id, hour, site, ip, n, ok, up, ctr, top) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE n = n + VALUES(n), ok = ok + VALUES(ok), up = IF(VALUES(up) <> \'\', VALUES(up), up),
                 ctr = IF(VALUES(ctr) <> \'\', VALUES(ctr), ctr), top = IF(top = \'\', VALUES(top), top)',
                [$hid, $hour, $site, mb_substr($ip, 0, 45), $n, $ok, $up, $ctr, mb_substr($top, 0, 255)]);
        }
    }

    /**
     * De dienst achter de proxy, in gewone woorden: de container (die de agent kent), of een van je servers (op IP-adres),
     * of anders het adres zoals de proxy het noemt.
     * @return array{name: string, where: string}
     */
    public static function service(string $up, string $ctr, string $proxyHost): array
    {
        if ($ctr !== '') {
            return ['name' => $ctr, 'where' => $proxyHost];
        }
        $h = preg_replace('/:\d+$/', '', trim($up, '[]'));
        if ($h === '' || $h === null) {
            return ['name' => '', 'where' => ''];
        }
        if (@inet_pton($h) !== false) {
            static $byIp = null;
            if ($byIp === null) {
                $byIp = [];
                foreach (q('SELECT name, address FROM hosts')->fetchAll() as $x) {
                    $byIp[strtolower((string) $x['address'])] = (string) $x['name'];
                }
            }
            if (isset($byIp[$h])) {
                return ['name' => $byIp[$h], 'where' => $up];
            }
            return ['name' => $up, 'where' => ''];
        }
        return ['name' => $h, 'where' => $proxyHost];   // bv. Nginx Proxy Manager: de naam van de container
    }

    /** IDS → Overzicht: aanvallen per site (uur(en) terug), met de dienst erachter en of iets een gewoon antwoord kreeg */
    public static function sites(int $hours = 24): array
    {
        $hours = max(1, min(24 * 7, $hours));
        $rows = q("SELECT w.site, w.host_id, h.name AS proxy, SUM(w.n) AS n, SUM(w.ok) AS ok, COUNT(DISTINCT w.ip) AS ips, MAX(w.up) AS up, MAX(w.ctr) AS ctr,
                   SUBSTRING_INDEX(GROUP_CONCAT(w.top ORDER BY w.n DESC SEPARATOR '\n'), '\n', 1) AS top
                   FROM web_sites w JOIN hosts h ON h.id = w.host_id WHERE w.hour >= ? GROUP BY w.site, w.host_id, h.name ORDER BY (w.site = '') ASC, n DESC LIMIT 25",
            [date('Y-m-d H:00:00', time() - ($hours - 1) * 3600)])->fetchAll();
        $kinds = [];
        foreach (q("SELECT host_id, kind FROM weblog_state WHERE src = 'swag'")->fetchAll() as $k) {
            $kinds[(int) $k['host_id']] = self::PROXIES[$k['kind'] ?? ''] ?? null;
        }
        $out = [];
        foreach ($rows as $r) {
            $svc = self::service((string) $r['up'], (string) $r['ctr'], (string) $r['proxy']);
            $out[] = ['site' => $r['site'], 'proxy' => $r['proxy'], 'kind' => $kinds[(int) $r['host_id']] ?? null, 'n' => (int) $r['n'], 'ok' => (int) $r['ok'],
                'ips' => (int) $r['ips'], 'top' => $r['top'], 'svc' => $svc['name'], 'svc_where' => $svc['where']];
        }
        return ['hours' => $hours, 'sites' => $out];
    }

    /**
     * Het pad van een webaanval voor het incidentvenster (1.222.0): aanvaller → reverse proxy → site → dienst, met een oordeel.
     * Zonder site in de log (alleen de gewone access-log): alleen aanvaller → proxy, met een tip.
     */
    public static function path(array $inc): ?array
    {
        if (($inc['type'] ?? '') !== 'web_attack' || !str_starts_with((string) $inc['dkey'], 'swag|')) {
            return null;
        }
        $w = json_decode((string) ($inc['wpath'] ?? ''), true) ?: [];
        $st = q("SELECT kind, ctr, full FROM weblog_state WHERE host_id = ? AND src = 'swag'", [$inc['host_id']])->fetch() ?: [];
        $ip = (string) ($inc['ip'] ?? '');
        $blocked = $ip !== '' && (bool) q('SELECT 1 FROM ids_blocks WHERE ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW()) LIMIT 1', [$ip])->fetchColumn();
        $proxy = (string) ($inc['host_name'] ?? '');
        $sites = [];
        $n = 0;
        $ok = 0;
        $fwd = 0;
        foreach ((array) ($w['sites'] ?? []) as [$site, $up, $ctr, $sn, $sok]) {
            $svc = self::service((string) $up, (string) $ctr, $proxy);
            $sites[] = ['site' => (string) $site, 'up' => (string) $up, 'svc' => $svc['name'], 'svc_where' => $svc['where'], 'n' => (int) $sn, 'ok' => (int) $sok];
            $n += (int) $sn;
            $ok += (int) $sok;
            $fwd += $site !== '' && $up !== '' ? 1 : 0;
        }
        $label = self::PROXIES[$st['kind'] ?? ''] ?? t('Reverse proxy');
        $verdict = null;
        if ($sites) {
            $svcName = $sites[0]['svc'] ?: ($sites[0]['site'] ?: $label);
            $verdict = !$fwd ? ['lvl' => 'ok', 'text' => t('Niet gelukt: {proxy} heeft de verzoeken zelf geweigerd. Er is niets doorgestuurd naar een dienst.', ['proxy' => $label])]
                : ($ok === 0 ? ['lvl' => 'ok', 'text' => t('Waarschijnlijk niet gelukt: alle {n} verzoeken kwamen aan bij {svc}, maar kregen een foutcode (zoals 404 of 401). Geen enkel verzoek kreeg een gewoon antwoord.', ['n' => $n, 'svc' => $svcName])]
                : ['lvl' => 'warn', 'text' => t('Let op: {ok} van de {n} verzoeken kregen een gewoon antwoord (2xx of 3xx) van {svc}. Kijk of de aanvaller iets gevonden heeft.', ['ok' => $ok, 'n' => $n, 'svc' => $svcName])]);
        }
        return ['ip' => $ip, 'cc' => $inc['cc'] ?? null, 'ua' => (string) ($w['ua'] ?? ''), 'blocked' => $blocked, 'hits' => (int) ($inc['hits'] ?? 1),
            'proxy' => ['label' => $label, 'host' => $proxy, 'ctr' => (string) ($st['ctr'] ?? '')], 'full' => (bool) (int) ($st['full'] ?? 0),
            'sites' => $sites, 'verdict' => $verdict, 'kind' => $st['kind'] ?? null];
    }

    public static function purge(): void
    {
        q('DELETE FROM weblog_attempts WHERE at < NOW() - INTERVAL 1 DAY');
        q('DELETE FROM web_sites WHERE hour < NOW() - INTERVAL 8 DAY');   // 1.222.0
    }
}
