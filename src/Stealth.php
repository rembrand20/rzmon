<?php
declare(strict_types=1);

/**
 * Onzichtbaar van buiten (1.86.0). Twee schakelaars bij Instellingen → Toegang en inlogbeveiliging:
 * - stealth_hide (standaard aan): RzMon is van buiten niet als RzMon te herkennen. Geen naam of versie bij ping, het
 *   agent-script alleen voor bekende agents of met de downloadsleutel, en geweigerd of onbekend krijgt een gewone 404
 *   (zoals nginx die geeft). De reden staat dan wel in RzMon (Meldingen, agentlog).
 * - stealth_ui (standaard uit): de webinterface en het inloggen werken van buiten alleen op een toegestaan apparaat
 *   (apparaatsleutel als cookie, per gebruiker), eventueel alleen vanuit je normale landen. Anders: dezelfde 404.
 *   Agents, heartbeats, ack-links en de REST API werken gewoon. Sleutel kwijt: een noodlink (stealth_door).
 * - stealth_requests (1.102.0, standaard aan): een browser die zo geweigerd wordt, krijgt (bij dezelfde 404) een wachtende
 *   sleutel in een cookie met een neutrale naam en komt op de lijst *Wachtende apparaten*. Een beheerder kan dat apparaat
 *   toestaan voor één gebruiker: de wachtende sleutel wordt dan de apparaatsleutel. Alleen browsers uit je normale landen.
 */
final class Stealth
{
    public const COOKIE = 'rzmon_device';
    private const DOOR_MINUTES = 15;
    private const DOOR_PER_HOUR = 3;
    /** Wachtende sleutel van een geweigerd apparaat: een neutrale naam, die niet verraadt dat hier RzMon draait */
    public const PENDING_COOKIE = 'sid';
    private const REQ_PER_HOUR = 3;      // nieuwe wachtende apparaten per IP per uur
    private const REQ_MAX = 100;         // hooguit zoveel wachtende tegelijk
    private const REQ_DAYS = 7;          // daarna vervalt een wachtend apparaat
    private static ?string $pendingKey = null;

    public static function hide(): bool
    {
        return self::setting('stealth_hide', '1') === '1';
    }

    public static function uiLocked(): bool
    {
        return self::setting('stealth_ui', '0') === '1';
    }

    private static function setting(string $name, string $default): string
    {
        try {
            return is_configured() ? (string) setting($name, $default) : '0';
        } catch (Throwable) {
            return '0';   // database nog niet klaar: niets verbergen (anders kom je de setup niet in)
        }
    }

    /** Van buiten je interne netwerken (Instellingen → Toegang: Lokale netwerken)? */
    public static function outside(): bool
    {
        return PHP_SAPI !== 'cli' && !Https::internal();
    }

    /** Een gewone 404, zoals nginx die geeft: niets dat naar RzMon wijst */
    public static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/html');
        header('Cache-Control: no-store');
        header_remove('X-CSRF-Token');
        header_remove('Set-Cookie');
        if (self::$pendingKey !== null) {   // nieuw geweigerd apparaat (1.102.0): de wachtende sleutel
            self::setCookie(self::PENDING_COOKIE, self::$pendingKey);
        }
        echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
        exit;
    }

    /** Geweigerd of onbekend van buiten: met "niet herkenbaar" aan een 404, anders niets (de aanroeper geeft zijn eigen fout) */
    public static function denyOutside(): void
    {
        if (self::hide() && self::outside()) {
            self::notFound();
        }
    }

    // ------------------------------------------------------------------ downloadsleutel voor het agent-script

    /** Sleutel voor de installatiecommando's (in de Agent Manager); zonder deze sleutel van buiten geen script */
    public static function dlKey(): string
    {
        $k = (string) q("SELECT value FROM settings WHERE name = 'agent_dl_key'")->fetchColumn();
        if (!preg_match('/^[0-9a-f]{32}$/', $k)) {
            $k = bin2hex(random_bytes(16));
            save_setting('agent_dl_key', $k);
        }
        return $k;
    }

    /** Het installatiescript zoals RzMon het uitlevert: met de downloadsleutel erin (zo haalt het daarna de agent op) */
    public static function installScript(): string
    {
        $s = (string) file_get_contents(SM_ROOT . '/agent/install-agent.sh');
        return str_replace("\nDL_KEY=''\n", "\nDL_KEY='" . self::dlKey() . "'\n", $s);
    }

    /** SHA-256 van dat script (voor de controle in de installatiecommando's en bij bijwerken) */
    public static function installSha(): string
    {
        return hash('sha256', self::installScript());
    }

    /** Mag dit verzoek het agent-script ophalen? Intern, een bekende agent (zijn IP), of met de downloadsleutel */
    public static function scriptAllowed(): bool
    {
        if (!self::hide() || !self::outside()) {
            return true;
        }
        $k = (string) ($_GET['k'] ?? '');
        if ($k !== '' && hash_equals(self::dlKey(), $k)) {
            return true;
        }
        $ip = client_ip();
        foreach (q("SELECT agent_ips FROM hosts WHERE mode = 'agent' AND agent_ips IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $ips) {
            if (in_array($ip, preg_split('/\s+/', trim((string) $ips)), true)) {
                return true;
            }
        }
        return false;
    }

    /** Mag ping zeggen dat dit RzMon is (naam, versie)? Intern, of een bekende agent */
    public static function pingFull(): bool
    {
        return !self::hide() || !self::outside() || self::scriptAllowed();
    }

    // ------------------------------------------------------------------ webinterface van buiten

    /** Het toegestane apparaat van deze browser (geldige sleutel), of null */
    public static function device(): ?array
    {
        static $dev = false;
        if ($dev !== false) {
            return $dev;
        }
        $c = (string) ($_COOKIE[self::COOKIE] ?? '');
        $dev = null;
        if (preg_match('/^[0-9a-f]{64}$/', $c)) {
            $dev = q('SELECT d.*, u.username FROM trusted_devices d JOIN users u ON u.id = d.user_id WHERE d.key_hash = ?', [hash('sha256', $c)])->fetch() ?: null;
        }
        // Door een beheerder toegestaan (1.102.0): de wachtende sleutel is nu de apparaatsleutel; zet hem als gewone cookie
        $p = (string) ($_COOKIE[self::PENDING_COOKIE] ?? '');
        if ($dev === null && preg_match('/^[0-9a-f]{64}$/', $p)) {
            $dev = q('SELECT d.*, u.username FROM trusted_devices d JOIN users u ON u.id = d.user_id WHERE d.key_hash = ?', [hash('sha256', $p)])->fetch() ?: null;
            if ($dev !== null && !headers_sent()) {
                self::cookie($p);
                self::setCookie(self::PENDING_COOKIE, null);
            }
        }
        return $dev;
    }

    /** Land van dit verzoek binnen je normale landen? Onbekend land (geen landgegevens): ja */
    public static function countryOk(): bool
    {
        if (self::setting('stealth_countries', '1') !== '1') {
            return true;
        }
        $cc = IpIntel::lookupCountry(client_ip());
        return $cc === null || in_array($cc, IpIntel::allowedCountries(), true);
    }

    /** Geopend met een noodlink (in deze sessie, nog geldig)? @return int|null voor welke gebruiker */
    private static function doorUser(): ?int
    {
        start_session();
        $d = $_SESSION['stealth_door'] ?? null;
        return $d && (int) $d['until'] > time() ? (int) $d['user'] : null;
    }

    /** Mag deze browser de webinterface (en het inloggen) zien? */
    public static function uiAllowed(): bool
    {
        if (!self::uiLocked() || !self::outside()) {
            return true;
        }
        return (self::device() !== null && self::countryOk()) || self::doorUser() !== null;
    }

    /**
     * Aan het begin van index.php, setup.php en api.php (behalve de agents): van buiten zonder toegestaan apparaat een 404.
     * Hier ook de noodlink: ?nood=gebruiker vraagt hem aan (altijd 404), ?door=sleutel opent de deur voor 15 minuten.
     */
    public static function gate(): void
    {
        if (!self::uiLocked() || !self::outside()) {
            return;
        }
        if (isset($_GET['door']) && self::doorUse((string) $_GET['door'])) {
            header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'));
            exit;
        }
        if (isset($_GET['nood'])) {
            self::doorRequest((string) $_GET['nood']);
            self::notFound();
        }
        if (!self::uiAllowed()) {
            self::recordRequest();
            self::notFound();
        }
        if ($dev = self::device()) {
            self::seen($dev);
        }
    }

    /** Mag deze gebruiker hier ingelogd zijn? Van buiten met de webinterface op slot: alleen op een eigen toegestaan apparaat */
    public static function userAllowed(int $userId): bool
    {
        if (!self::uiLocked() || !self::outside()) {
            return true;
        }
        $dev = self::device();
        return ($dev !== null && (int) $dev['user_id'] === $userId && self::countryOk()) || self::doorUser() === $userId;
    }

    private static function seen(array $dev): void
    {
        if (strtotime((string) $dev['last_seen']) < time() - 300) {
            $ip = client_ip();
            q('UPDATE trusted_devices SET last_seen = ?, last_ip = ?, last_cc = ? WHERE id = ?', [now(), mb_substr($ip, 0, 45), IpIntel::lookupCountry($ip), $dev['id']]);
        }
    }

    // ------------------------------------------------------------------ toegestane apparaten

    /** Dit apparaat (deze browser) toestaan van buiten, voor deze gebruiker. Zet de cookie (1 jaar). @return int id */
    public static function trust(array $user, string $name): int
    {
        $key = bin2hex(random_bytes(32));
        $ip = client_ip();
        $name = mb_substr(trim($name), 0, 100) ?: device_name();
        q('INSERT INTO trusted_devices (user_id, name, key_hash, created_at, created_ip, last_seen, last_ip, last_cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$user['id'], $name, hash('sha256', $key), now(), mb_substr($ip, 0, 45), now(), mb_substr($ip, 0, 45), IpIntel::lookupCountry($ip)]);
        $id = (int) db()->lastInsertId();
        // Een vorige sleutel van deze browser vervalt (anders twee regels voor één apparaat)
        if ($old = self::device()) {
            q('DELETE FROM trusted_devices WHERE id = ?', [$old['id']]);
        }
        self::cookie($key);
        AgentCommands::audit($user['username'], 'Apparaat toegestaan van buiten', $name);
        Monitor::sysInfo('device_trust', null, null, '📱 ' . t('Apparaat toegestaan van buiten: {name}', ['name' => $name]),
            fn() => t('{user} heeft {name} toegestaan om van buiten je netwerk RzMon te openen', ['user' => $user['username'], 'name' => $name]) . " · IP $ip", $ip);
        return $id;
    }

    public static function cookie(?string $key): void
    {
        self::setCookie(self::COOKIE, $key);
    }

    /** Cookie van een jaar (of weghalen met null), alleen voor RzMon en niet leesbaar voor JavaScript */
    private static function setCookie(string $name, ?string $key): void
    {
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
        setcookie($name, $key ?? '', ['expires' => $key !== null ? time() + 365 * 86400 : time() - 3600, 'path' => $path,
            'httponly' => true, 'secure' => Https::isHttps(), 'samesite' => 'Lax']);
    }

    /** Intrekken (en de passkeys van dat apparaat). $userId: alleen als het van die gebruiker is (null = beheerder) */
    public static function revoke(int $id, ?int $userId, string $by): bool
    {
        $d = q('SELECT d.*, u.username FROM trusted_devices d JOIN users u ON u.id = d.user_id WHERE d.id = ?' . ($userId !== null ? ' AND d.user_id = ?' : ''),
            $userId !== null ? [$id, $userId] : [$id])->fetch();
        if (!$d) {
            return false;
        }
        q('DELETE FROM passkeys WHERE device_id = ?', [$id]);
        q('DELETE FROM trusted_devices WHERE id = ?', [$id]);
        AgentCommands::audit($by, 'Apparaat ingetrokken', "{$d['username']}: {$d['name']}");
        Monitor::sysInfo('device_trust', null, null, '📵 ' . t('Apparaat ingetrokken: {name}', ['name' => $d['name']]),
            fn() => t('{name} van {user} kan RzMon niet meer van buiten openen (door {by})', ['name' => $d['name'], 'user' => $d['username'], 'by' => $by]));
        return true;
    }

    public static function rename(int $id, ?int $userId, string $name): bool
    {
        $name = mb_substr(trim($name), 0, 100);
        return $name !== '' && q('UPDATE trusted_devices SET name = ? WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : ''),
            $userId !== null ? [$name, $id, $userId] : [$name, $id])->rowCount() > 0;
    }

    /** Lijst (van één gebruiker, of iedereen), met of het deze browser is en welke passkeys erbij horen */
    public static function devices(?int $userId): array
    {
        $cur = self::device();
        $rows = q('SELECT d.id, d.user_id, u.username, d.name, d.created_at, d.created_ip, d.last_seen, d.last_ip, d.last_cc,
                          (SELECT COUNT(*) FROM passkeys p WHERE p.device_id = d.id) AS passkeys
                   FROM trusted_devices d JOIN users u ON u.id = d.user_id' . ($userId !== null ? ' WHERE d.user_id = ?' : '') . ' ORDER BY u.username, d.last_seen DESC',
            $userId !== null ? [$userId] : [])->fetchAll();
        foreach ($rows as &$r) {
            $r['current'] = $cur !== null && (int) $cur['id'] === (int) $r['id'];
        }
        return $rows;
    }

    // ------------------------------------------------------------------ wachtende apparaten (1.102.0)

    /** Ziet dit verzoek eruit als een mens die de webinterface opent (en niet als een scanner of bot)? */
    private static function looksHuman(): bool
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'index.php'
            && str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html')
            && str_starts_with($ua, 'Mozilla/5.0') && preg_match('#(Safari|Firefox|Chrome|Edg)/#', $ua)
            && !preg_match('#bot|crawl|spider|slurp|headless|preview|scan|curl|wget|python|go-http|java/|okhttp|facebookexternalhit|zgrab|nmap|censys|shodan#i', $ua);
    }

    /** Geweigerd door "alleen toegestane apparaten": op de lijst Wachtende apparaten zetten (of bijwerken) */
    private static function recordRequest(): void
    {
        try {
            if (self::setting('stealth_requests', '1') !== '1' || !self::looksHuman()) {
                return;
            }
            $ip = client_ip();
            $cc = IpIntel::lookupCountry($ip);
            $allowed = IpIntel::allowedCountries();
            if ($cc !== null && $allowed && !in_array($cc, $allowed, true)) {
                return;   // alleen vanuit je normale landen
            }
            $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $p = (string) ($_COOKIE[self::PENDING_COOKIE] ?? '');
            if (preg_match('/^[0-9a-f]{64}$/', $p)
                && q('UPDATE device_requests SET last_seen = ?, last_ip = ?, last_cc = ?, hits = hits + 1, ua = ? WHERE key_hash = ?',
                    [now(), mb_substr($ip, 0, 45), $cc, $ua, hash('sha256', $p)])->rowCount() > 0) {
                return;   // al bekend (wachtend of genegeerd): alleen bijwerken
            }
            if ((int) q('SELECT COUNT(*) FROM device_requests WHERE created_ip = ? AND created_at >= NOW() - INTERVAL 1 HOUR', [$ip])->fetchColumn() >= self::REQ_PER_HOUR
                || (int) q("SELECT COUNT(*) FROM device_requests WHERE status = 'pending'")->fetchColumn() >= self::REQ_MAX) {
                return;
            }
            $key = bin2hex(random_bytes(32));
            $name = device_name();
            q('INSERT INTO device_requests (key_hash, name, ua, created_at, created_ip, last_seen, last_ip, last_cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [hash('sha256', $key), mb_substr($name, 0, 100), $ua, now(), mb_substr($ip, 0, 45), now(), mb_substr($ip, 0, 45), $cc]);
            self::$pendingKey = $key;
            Monitor::sysInfo('device_request', null, null, '📱 ' . t('Geweigerd apparaat van buiten: {name}', ['name' => $name]),
                fn() => t('{name} probeerde RzMon te openen vanaf {ip} en kreeg een 404. Toestaan kan bij Instellingen → Van buiten → Wachtende apparaten.',
                    ['name' => $name, 'ip' => $ip . ($cc ? " ($cc)" : '')]), $ip);
        } catch (Throwable $e) {
            error_log('RzMon: wachtend apparaat vastleggen mislukt: ' . $e->getMessage());
        }
    }

    /** Wachtende apparaten (nieuwste eerst) */
    public static function requests(): array
    {
        return q("SELECT id, name, ua, created_at, created_ip, last_seen, last_ip, last_cc, hits FROM device_requests
                  WHERE status = 'pending' AND last_seen >= NOW() - INTERVAL " . self::REQ_DAYS . ' DAY ORDER BY last_seen DESC')->fetchAll();
    }

    public static function requestCount(): int
    {
        return (int) q("SELECT COUNT(*) FROM device_requests WHERE status = 'pending' AND last_seen >= NOW() - INTERVAL " . self::REQ_DAYS . ' DAY')->fetchColumn();
    }

    /** Een wachtend apparaat toestaan voor één gebruiker: de wachtende sleutel wordt de apparaatsleutel. @return string|null fout */
    public static function approveRequest(int $id, int $userId, string $name, string $by): ?string
    {
        $r = q("SELECT * FROM device_requests WHERE id = ? AND status = 'pending'", [$id])->fetch();
        $u = q('SELECT id, username FROM users WHERE id = ?', [$userId])->fetch();
        if (!$r || !$u) {
            return t('Niet gevonden');
        }
        $name = mb_substr(trim($name), 0, 100) ?: (string) $r['name'];
        q('INSERT INTO trusted_devices (user_id, name, key_hash, created_at, created_ip, last_seen, last_ip, last_cc) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$u['id'], $name, $r['key_hash'], now(), $r['last_ip'], $r['last_seen'], $r['last_ip'], $r['last_cc']]);
        q("UPDATE device_requests SET status = 'approved', handled_by = ?, handled_at = ? WHERE id = ?", [mb_substr($by, 0, 64), now(), $id]);
        AgentCommands::audit($by, 'Wachtend apparaat toegestaan', "{$u['username']}: $name ({$r['last_ip']})");
        Monitor::sysInfo('device_trust', null, null, '📱 ' . t('Apparaat toegestaan van buiten: {name}', ['name' => $name]),
            fn() => t('{by} heeft {name} ({ip}) toegestaan voor {user}; bij het volgende bezoek krijgt het het inlogscherm', ['by' => $by, 'name' => $name, 'ip' => $r['last_ip'], 'user' => $u['username']]),
            (string) $r['last_ip']);
        return null;
    }

    /** Negeren: niet meer op de lijst en geen meldingen meer van dit apparaat */
    public static function ignoreRequest(int $id, string $by): bool
    {
        $ok = q("UPDATE device_requests SET status = 'ignored', handled_by = ?, handled_at = ? WHERE id = ? AND status = 'pending'", [mb_substr($by, 0, 64), now(), $id])->rowCount() > 0;
        if ($ok) {
            AgentCommands::audit($by, 'Wachtend apparaat genegeerd', (string) $id);
        }
        return $ok;
    }

    // ------------------------------------------------------------------ noodlink

    /** ?nood=gebruiker: noodlink naar het e-mailadres van die gebruiker (en Pushover, voor een beheerder). Geeft niets terug. */
    private static function doorRequest(string $username): void
    {
        if (self::setting('stealth_door', '1') !== '1') {
            return;
        }
        $u = q('SELECT id, username, email, role FROM users WHERE username = ?', [mb_substr(trim($username), 0, 64)])->fetch();
        $ip = client_ip();
        if (!$u || (int) q('SELECT COUNT(*) FROM stealth_doors WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR', [$u['id']])->fetchColumn() >= self::DOOR_PER_HOUR) {
            return;
        }
        $token = bin2hex(random_bytes(24));
        q('INSERT INTO stealth_doors (user_id, token_hash, created_at, expires_at, ip) VALUES (?, ?, ?, ?, ?)',
            [$u['id'], hash('sha256', $token), now(), date('Y-m-d H:i:s', time() + self::DOOR_MINUTES * 60), mb_substr($ip, 0, 45)]);
        $base = (Https::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $link = "$base?door=$token";
        $cc = IpIntel::lookupCountry($ip);
        $msg = I18n::forNotify(fn() => t('Noodlink voor {user}: {link}', ['user' => $u['username'], 'link' => $link]) . "\n\n"
            . t('Eén keer te gebruiken, {n} minuten geldig. Aangevraagd vanaf {ip}.', ['n' => self::DOOR_MINUTES, 'ip' => $ip . ($cc ? " ($cc)" : '')]) . ' '
            . t('Was jij dit niet? Dan kun je dit negeren: zonder de link komt niemand binnen.'));
        $sent = [];
        if ((string) $u['email'] !== '' && setting('email_enabled', '0') === '1' && Mailer::send(I18n::forNotify(fn() => t('RzMon noodlink')), $msg, null, (string) $u['email'])['ok']) {
            $sent[] = t('e-mail');
        }
        if ($u['role'] === 'admin' && setting('pushover_enabled', '0') === '1' && Pushover::send(I18n::forNotify(fn() => t('RzMon noodlink')), $msg, 0)['ok']) {
            $sent[] = 'Pushover';
        }
        Monitor::sysInfo('stealth_door', null, null, '🚪 ' . t('Noodlink aangevraagd: {user}', ['user' => $u['username']]),
            fn() => t('Noodlink voor {user} aangevraagd vanaf {ip}', ['user' => $u['username'], 'ip' => $ip . ($cc ? " ($cc)" : '')]) . ' · '
                . ($sent ? t('verstuurd via {via}', ['via' => implode(', ', $sent)]) : t('niet verstuurd: geen e-mailadres of kanaal')), $ip);
    }

    /** ?door=sleutel: geldig → de webinterface 15 minuten open voor deze browser, alleen voor die gebruiker */
    private static function doorUse(string $token): bool
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return false;
        }
        $d = q('SELECT d.*, u.username FROM stealth_doors d JOIN users u ON u.id = d.user_id WHERE d.token_hash = ? AND d.used_at IS NULL AND d.expires_at > ?',
            [hash('sha256', $token), now()])->fetch();
        if (!$d) {
            return false;
        }
        q('UPDATE stealth_doors SET used_at = ? WHERE id = ?', [now(), $d['id']]);
        start_session();
        $_SESSION['stealth_door'] = ['user' => (int) $d['user_id'], 'until' => time() + self::DOOR_MINUTES * 60];
        $ip = client_ip();
        Monitor::sysInfo('stealth_door', null, null, '🚪 ' . t('Noodlink gebruikt: {user}', ['user' => $d['username']]),
            fn() => t('De noodlink van {user} is gebruikt vanaf {ip}; inloggen kan nu {n} minuten', ['user' => $d['username'], 'ip' => $ip, 'n' => self::DOOR_MINUTES]), $ip);
        return true;
    }

    /** Geopend met de noodlink: dan mag dit apparaat (na inloggen) ook van buiten toegestaan worden */
    public static function viaDoor(int $userId): bool
    {
        return self::doorUser() === $userId;
    }

    public static function purge(): void
    {
        q('DELETE FROM stealth_doors WHERE created_at < NOW() - INTERVAL 7 DAY');
        q("DELETE FROM device_requests WHERE (status = 'pending' AND last_seen < NOW() - INTERVAL " . self::REQ_DAYS . " DAY) OR last_seen < NOW() - INTERVAL 30 DAY");
    }
}
