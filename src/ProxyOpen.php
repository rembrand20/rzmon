<?php
declare(strict_types=1);

/**
 * Van buiten bij RzSense, ook als de reverse proxy de site van RzSense "alleen vanaf mijn netwerk" laat (standaard).
 *
 * 1.272.0 (agent 1.106.0): de proxy volgt de manier van inloggen van buiten die RzSense al heeft, de toegestane apparaten
 * (Stealth, 1.86.0): elk toegestaan apparaat krijgt naast zijn apparaatsleutel een eigen proxy-tag (cookie "pxt"). Alleen
 * precies die tags staan in de proxy (regel "0 proxytags <site> <tags>"); daarachter controleert RzSense zelf de sleutel,
 * het land, het inloggen en de 2FA. Intrekken haalt de tag weg. De noodlink (?nood= en ?door= op de hoofdpagina) mag ook
 * langs de proxy; een gebruikte noodlink geeft een tijdelijke tag voor de duur van de noodlink.
 * Daarnaast altijd door: bevestigen, statuspagina en heartbeats (elk met een eigen token, 1.271.0).
 */
final class ProxyOpen
{
    public const COOKIE = 'pxt';
    public const AGENT = '1.106.0';
    /** 1.274.0 (agent 1.108.0): per site "toegestane apparaten mogen er van buiten bij" */
    public const DEV_AGENT = '1.108.0';
    private const DOMAIN_MARK = 'pxd';
    private const MAX_TAGS = 300;
    private const DOOR_MINUTES = 15;

    /**
     * 1.271.0 (agent 1.105.0): paden van RzSense die van buiten altijd bereikbaar moeten zijn, ook als de site alleen vanaf mijn
     * netwerk mag. Ze hebben elk hun eigen geheime token: bevestigen vanuit Pushover, de statuspagina met geheime link en
     * heartbeats van buiten. assets/app.css is de opmaak van de bevestigpagina.
     */
    public const PASS = ['/ack.php', '/status.php', '/hb.php', '/assets/app.css'];
    public const PASS_AGENT = '1.105.0';

    /** De site(s) van RzSense op deze proxy: naam of app erachter is rzsense/rzmon, of de site is het adres van RzSense */
    public static function sites(array $sites): array
    {
        $base = strtolower((string) parse_url((string) setting('base_url', ''), PHP_URL_HOST));
        $out = [];
        foreach ($sites as $s) {
            $name = strtolower((string) ($s['site'] ?? ''));
            $hay = $name . ' ' . strtolower((string) ($s['up'] ?? ''));
            $match = preg_match('/rzsense|rzmon/', $hay) === 1;
            if (!$match && $base !== '' && $name !== '') {
                $re = '/^' . str_replace(['\*'], ['[^.]+'], preg_quote($name, '/')) . '$/';
                $match = preg_match($re, $base) === 1;
            }
            if ($match && ($s['enabled'] ?? true) && ($s['lan_mode'] ?? 'off') !== 'off') {
                $out[] = (string) $s['site'];
            }
        }
        return array_values(array_unique($out));
    }

    /** De sites van RzSense op alle proxyservers */
    public static function rzSitesAll(): array
    {
        $out = [];
        foreach (q('SELECT id, name, agent_version FROM hosts WHERE proxy_role = 1')->fetchAll() as $h) {
            $rep = ProxyGuard::report((int) $h['id']);
            $sites = self::sites(ProxyGuard::sites((int) $h['id'], $rep));
            if ($sites) {
                $out[] = ['id' => (int) $h['id'], 'host' => (string) $h['name'], 'agent' => (string) $h['agent_version'], 'sites' => $sites,
                    'synced' => $rep && ($rep['state'] ?? '') === 'ok' && ($rep['applied'] ?? '') === ProxyGuard::hash((int) $h['id'])];
            }
        }
        return $out;
    }

    /** Staat RzSense ergens achter een proxy die alleen mijn netwerk toelaat? */
    public static function behindProxy(): bool
    {
        return ProxyGuard::enabled() && self::rzSitesAll() !== [];
    }

    // ------------------------------------------------------------------ proxy-tags

    /** De tags die de proxy doorlaat (toegestane apparaten en lopende noodlinks) */
    public static function tags(): array
    {
        q('DELETE FROM proxy_tags WHERE until IS NOT NULL AND until < NOW() - INTERVAL 1 DAY');
        return array_values(array_filter(array_map('strval', q('SELECT tag FROM proxy_tags WHERE until IS NULL OR until > NOW() ORDER BY created_at DESC LIMIT ' . self::MAX_TAGS)
            ->fetchAll(PDO::FETCH_COLUMN)), static fn($t) => preg_match('/^[0-9a-f]{32}$/', $t) === 1));
    }

    /** 1.274.0: alleen de tags van toegestane apparaten (geen noodlink): die gelden ook op andere sites met de schakelaar aan */
    public static function deviceTags(): array
    {
        return array_values(array_filter(array_map('strval', q('SELECT tag FROM proxy_tags WHERE device_id IS NOT NULL AND until IS NULL ORDER BY created_at DESC LIMIT ' . self::MAX_TAGS)
            ->fetchAll(PDO::FETCH_COLUMN)), static fn($t) => preg_match('/^[0-9a-f]{32}$/', $t) === 1));
    }

    /**
     * 1.274.0: het domein waarop de tag ook moet gelden (ha.voorbeeld.nl naast rzsense.voorbeeld.nl), alleen als er een site
     * is met "toegestane apparaten" aan (of dat de standaard is). Het adres van RzSense zonder het eerste deel; '' = alleen RzSense.
     */
    public static function cookieDomain(): string
    {
        if (setting('proxy_def_devices', '0') !== '1' && !q('SELECT 1 FROM proxy_sites WHERE devices = 1 AND enabled = 1 LIMIT 1')->fetchColumn()) {
            return '';
        }
        return self::parentDomain((string) parse_url((string) setting('base_url', ''), PHP_URL_HOST));
    }

    public static function parentDomain(string $host): string
    {
        $host = strtolower(trim($host, '.[]'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')
            || !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host)) {
            return '';
        }
        $parts = explode('.', $host);
        return count($parts) >= 3 ? implode('.', array_slice($parts, 1)) : $host;
    }

    /** Een toegestaan apparaat dat RzSense nu herkent: zorg dat het een tag heeft en de browser die als cookie heeft */
    public static function deviceSeen(array $dev): void
    {
        $tag = (string) q('SELECT tag FROM proxy_tags WHERE device_id = ? AND until IS NULL LIMIT 1', [$dev['id']])->fetchColumn();
        if (!preg_match('/^[0-9a-f]{32}$/', $tag)) {
            $tag = bin2hex(random_bytes(16));
            q('INSERT INTO proxy_tags (tag, device_id, user_id, created_at) VALUES (?, ?, ?, NOW())', [$tag, $dev['id'], $dev['user_id']]);
        }
        if (($_COOKIE[self::COOKIE] ?? '') !== $tag || ($_COOKIE[self::DOMAIN_MARK] ?? '') !== self::cookieDomain()) {
            self::cookie($tag, 365 * 86400, true);
        }
    }

    /** Gebruikte noodlink: een tijdelijke tag voor deze browser */
    public static function doorTag(int $userId): void
    {
        $tag = bin2hex(random_bytes(16));
        q('INSERT INTO proxy_tags (tag, device_id, user_id, created_at, until) VALUES (?, NULL, ?, NOW(), ?)', [$tag, $userId, date('Y-m-d H:i:s', time() + self::DOOR_MINUTES * 60)]);
        self::cookie($tag, self::DOOR_MINUTES * 60);
    }

    /** Apparaat ingetrokken: zijn tag weg (de proxy sluit bij de volgende ronde van de agent) */
    public static function deviceRevoked(int $deviceId): void
    {
        q('DELETE FROM proxy_tags WHERE device_id = ?', [$deviceId]);
    }

    private static function cookie(string $tag, int $ttl, bool $device = false): void
    {
        if (headers_sent()) {
            return;
        }
        // Pad "/": de proxy moet de tag bij elk verzoek naar deze site zien (pagina, API, opmaak)
        $o = ['expires' => time() + $ttl, 'path' => '/', 'httponly' => true, 'secure' => Https::isHttps(), 'samesite' => 'Lax'];
        setcookie(self::COOKIE, $tag, $o);
        if (!$device) {
            return;
        }
        // 1.274.0: ook voor de andere sites onder hetzelfde domein (alleen de apparaattag, niet die van de noodlink). Naast de
        // gewone cookie: weigert de browser dit domein, dan blijft RzSense zelf gewoon werken.
        $dom = self::cookieDomain();
        if ($dom !== '') {
            setcookie(self::COOKIE, $tag, $o + ['domain' => $dom]);
        }
        setcookie(self::DOMAIN_MARK, $dom, $o);
    }

    /** Wachtpagina na de noodlink: de proxy neemt de tijdelijke tag binnen een minuut over; ververst zichzelf */
    public static function waitPage(): never
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        echo '<!doctype html><html lang="' . $e(I18n::lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="refresh" content="10"><title>RzSense</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#0d121b;color:#e6ebf4;padding:24px}'
            . 'main{max-width:380px;text-align:center}p{color:#94a0b6}</style></head><body><main><h1>🚪 ' . $e(t('Noodlink geopend')) . '</h1><p>'
            . $e(t('De reverse proxy neemt je tijdelijke sleutel over; dat duurt hooguit een minuut. Deze pagina ververst vanzelf en opent dan RzSense.')) . '</p></main></body></html>';
        exit;
    }

    /** Heeft elke proxy met de site van RzSense de laatste regels (en dus de nieuwe tags) al doorgevoerd? */
    public static function synced(): bool
    {
        foreach (self::rzSitesAll() as $r) {
            if (!$r['synced']) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------ waarschuwing

    /**
     * Staat RzSense op de proxy alleen open voor mijn netwerk terwijl het de laatste 60 dagen van buiten werd gebruikt?
     * null = niets aan de hand.
     * @return array{status:string,text:string,n:int}|null
     */
    public static function selfCheck(): ?array
    {
        if (!ProxyGuard::enabled()) {
            return null;
        }
        $rz = self::rzSitesAll();
        if (!$rz) {
            return null;
        }
        $lan = ProxyGuard::lanNets();
        $ips = array_unique(array_merge(
            q('SELECT DISTINCT ip FROM login_log WHERE ok = 1 AND created_at >= NOW() - INTERVAL 60 DAY LIMIT 500')->fetchAll(PDO::FETCH_COLUMN),
            q("SELECT DISTINCT ip FROM user_sessions WHERE ip <> '' AND last_seen >= NOW() - INTERVAL 60 DAY LIMIT 500")->fetchAll(PDO::FETCH_COLUMN)));
        $outside = array_values(array_filter(array_map('strval', $ips), static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) && !is_private_ip($ip) && !ip_in_list($ip, $lan)));
        if (!$outside) {
            return null;
        }
        $n = count($outside);
        $names = implode(', ', array_merge(...array_column($rz, 'sites')));
        $old = array_column(array_filter($rz, static fn($r) => version_compare($r['agent'], self::AGENT, '<')), 'host');
        if ($old) {
            return ['status' => 'warn', 'n' => $n, 'text' => t('RzSense ({s}) staat op de proxy alleen open voor je eigen netwerk, maar je gebruikte het {n}× van buiten: daar krijg je 403. Werk de agent op {hosts} bij naar {v}; dan komen je toegestane apparaten en de noodlink weer langs de proxy.',
                ['s' => $names, 'n' => $n, 'hosts' => implode(', ', $old), 'v' => self::AGENT])];
        }
        $devs = (int) q('SELECT COUNT(*) FROM trusted_devices')->fetchColumn();
        if ($devs === 0) {
            return ['status' => 'warn', 'n' => $n, 'text' => t('RzSense ({s}) staat op de proxy alleen open voor je eigen netwerk, maar je gebruikte het {n}× van buiten, en er is nog geen toegestaan apparaat. Sta je telefoon thuis toe bij Mijn account → Toegestane apparaten; onderweg kom je anders alleen binnen met de noodlink.',
                ['s' => $names, 'n' => $n])];
        }
        $noTag = (int) q('SELECT COUNT(*) FROM trusted_devices d WHERE NOT EXISTS (SELECT 1 FROM proxy_tags t WHERE t.device_id = d.id)')->fetchColumn();
        return ['status' => 'ok', 'n' => $n, 'text' => t('RzSense ({s}) staat op de proxy alleen open voor je eigen netwerk. Je {d} toegestane apparaten komen er van buiten langs; een nieuw apparaat sta je thuis toe of via de noodlink.', ['s' => $names, 'd' => $devs])
            . ($noTag ? ' ' . t('{n} apparaat/apparaten moet RzSense nog één keer openen (thuis, of via de noodlink) om de sleutel voor de proxy te krijgen.', ['n' => $noTag]) : '')];
    }
}
