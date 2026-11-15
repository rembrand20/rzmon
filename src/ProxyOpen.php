<?php
declare(strict_types=1);

/**
 * 1.270.0: tijdelijk toegang van buiten tot sites achter de reverse proxy die "alleen mijn netwerk" staan (zoals RzSense zelf).
 *
 * Op de site van RzSense laat de proxy één pad door vanaf overal: /rz-open/ (agent 1.104.0, regel "0 proxyopen <site>").
 * Daar log je in met wachtwoord én 2FA; daarna telt je huidige IP een paar uur als "mijn netwerk" (in de regel proxylan)
 * en kom je overal bij. Het gaat vanzelf weer dicht; je krijgt er een melding van en kunt het op de pagina Reverse proxy intrekken.
 */
final class ProxyOpen
{
    public const PATH = '/rz-open/';
    public const AGENT = '1.104.0';
    private const MAX = 20;            // hooguit zoveel IP's tegelijk open
    private const FAILS = 5;           // mislukte pogingen per IP per 15 minuten
    private const OPENS_PER_DAY = 10;  // per gebruiker

    public static function enabled(): bool
    {
        return setting('proxy_open_on', '1') === '1';
    }

    public static function hours(): int
    {
        return max(1, min(72, (int) setting('proxy_open_hours', '8')));
    }

    /** De IP's die nu open staan (voor de regel proxylan) */
    public static function ips(): array
    {
        if (!self::enabled()) {
            return [];
        }
        return array_values(array_filter(array_map('strval', q('SELECT DISTINCT ip FROM proxy_open WHERE revoked_at IS NULL AND until > NOW() ORDER BY until DESC LIMIT ' . self::MAX)
            ->fetchAll(PDO::FETCH_COLUMN)), static fn($ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP)));
    }

    /** Voor de pagina Reverse proxy */
    public static function active(): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'ip' => $r['ip'], 'user' => $r['username'], 'device' => $r['device'],
            'created_at' => $r['created_at'], 'until' => $r['until']],
            q('SELECT o.*, u.username FROM proxy_open o LEFT JOIN users u ON u.id = o.user_id WHERE o.revoked_at IS NULL AND o.until > NOW() ORDER BY o.until DESC LIMIT 50')->fetchAll());
    }

    /**
     * 1.271.0 (agent 1.105.0): paden van RzSense die van buiten altijd bereikbaar moeten zijn, ook als de site alleen vanaf mijn
     * netwerk mag. Ze hebben elk hun eigen geheime token: bevestigen vanuit Pushover, de statuspagina met geheime link en
     * heartbeats van buiten. assets/app.css is de opmaak van de bevestigpagina.
     */
    public const PASS = ['/ack.php', '/status.php', '/hb.php', '/assets/app.css'];
    public const PASS_AGENT = '1.105.0';

    /** De sites van RzSense op alle proxyservers (voor de waarschuwing) */
    public static function rzSitesAll(): array
    {
        $out = [];
        foreach (q('SELECT id, name, agent_version FROM hosts WHERE proxy_role = 1')->fetchAll() as $h) {
            $sites = self::sites(ProxyGuard::sites((int) $h['id'], ProxyGuard::report((int) $h['id'])));
            if ($sites) {
                $out[] = ['host' => (string) $h['name'], 'agent' => (string) $h['agent_version'], 'sites' => $sites];
            }
        }
        return $out;
    }

    /**
     * 1.271.0: staat RzSense op de proxy alleen open voor mijn netwerk terwijl er de laatste 60 dagen van buiten is ingelogd of
     * gewerkt? Dan krijg je onderweg 403. null = niets aan de hand.
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
        $old = array_filter($rz, static fn($r) => version_compare($r['agent'], self::AGENT, '<'));
        $way = self::enabled() && !$old;
        $names = implode(', ', array_merge(...array_column($rz, 'sites')));
        return $way
            ? ['status' => 'ok', 'n' => count($outside), 'text' => t('RzSense ({s}) staat op de proxy alleen open voor je eigen netwerk, en je gebruikte het {n}× van buiten. Onderweg kom je erin via /rz-open/ (wachtwoord + 2FA).', ['s' => $names, 'n' => count($outside)])]
            : ['status' => 'warn', 'n' => count($outside), 'text' => t('RzSense ({s}) staat op de proxy alleen open voor je eigen netwerk, maar je gebruikte het {n}× van buiten: daar krijg je nu 403. Zet Tijdelijk toegang van buiten aan (agent 1.104.0 op de proxy), of zet de site op "Adminpaden alleen mijn netwerk".', ['s' => $names, 'n' => count($outside)])];
    }

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

    /** Te veel mislukte pogingen vanaf dit IP? */
    public static function throttled(string $ip): bool
    {
        return (int) q("SELECT COUNT(*) FROM security_fails WHERE ip = ? AND kind = 'open' AND created_at >= NOW() - INTERVAL 15 MINUTE", [mb_substr($ip, 0, 45)])->fetchColumn() >= self::FAILS;
    }

    public static function failed(string $ip, string $user, string $why, bool $exists): void
    {
        q("INSERT INTO security_fails (ip, kind, user, created_at) VALUES (?, 'open', ?, ?)", [mb_substr($ip, 0, 45), mb_substr($user, 0, 64) ?: '?', now()]);
        Security::loginFailed($user, $why, $exists);   // dezelfde vergrendeling en IP-blokkade als bij gewoon inloggen
    }

    /**
     * Inloggen met wachtwoord + 2FA en het IP openzetten.
     * @return array{ok:bool,error?:string,until?:string}
     */
    public static function attempt(string $username, string $password, string $code, string $ip, string $device): array
    {
        $deny = ['ok' => false, 'error' => t('Onjuiste gegevens. Gebruik je gebruikersnaam, wachtwoord en de code uit je authenticator-app.')];
        if (!self::enabled() || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => t('Tijdelijk toegang van buiten staat uit.')];
        }
        if (Security::blockFor($ip) || self::throttled($ip)) {
            return ['ok' => false, 'error' => t('Te veel pogingen. Probeer het over een kwartier opnieuw.')];
        }
        $u = q('SELECT id, username, password_hash, totp_secret, totp_last_step, locked_at, locked_until, local_only FROM users WHERE username = ?', [$username])->fetch();
        $ok = password_verify($password, $u ? (string) $u['password_hash'] : '$2y$10$0IjxnWsq1E9mapltBUPRsukncwuqi3XyC40ho25urRmNpbYij5Qtu');
        if (!$u || !$ok) {
            self::failed($ip, $username, $u ? 'toegang van buiten: verkeerd wachtwoord' : 'toegang van buiten: onbekende gebruiker', (bool) $u);
            usleep(500000);
            return $deny;
        }
        if (Security::accountLocked($u) || (int) $u['local_only'] || !$u['totp_secret']) {
            self::failed($ip, $username, 'toegang van buiten: account niet toegestaan', true);
            return ['ok' => false, 'error' => !$u['totp_secret'] ? t('Alleen met tweestapsverificatie (2FA). Zet die eerst aan bij Mijn account, vanaf je eigen netwerk.') : $deny['error']];
        }
        $step = Totp::verify((string) $u['totp_secret'], preg_replace('/\D/', '', $code) ?? '', $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null);
        if ($step === null) {
            self::failed($ip, $username, 'toegang van buiten: verkeerde 2FA-code', true);
            usleep(500000);
            return $deny;
        }
        q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
        if ((int) q('SELECT COUNT(*) FROM proxy_open WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY', [$u['id']])->fetchColumn() >= self::OPENS_PER_DAY) {
            return ['ok' => false, 'error' => t('Vandaag al te vaak opengezet. Probeer het morgen weer, of vanaf je eigen netwerk.')];
        }
        $until = date('Y-m-d H:i:s', time() + self::hours() * 3600);
        q('DELETE FROM proxy_open WHERE created_at < NOW() - INTERVAL 30 DAY');
        q('INSERT INTO proxy_open (user_id, ip, device, created_at, until) VALUES (?, ?, ?, NOW(), ?)', [$u['id'], $ip, mb_substr($device, 0, 120), $until]);
        Monitor::sysInfo('proxy_open', null, null, '🔓 ' . t('Toegang van buiten: {ip}', ['ip' => $ip]),
            fn() => t('{user} zette {ip} open tot {t} ({dev}). Niet jij? Trek het in op de pagina Reverse proxy en wijzig je wachtwoord.',
                ['user' => $u['username'], 'ip' => $ip, 't' => date('d-m H:i', strtotime($until)), 'dev' => $device ?: '?']), $ip);
        ActionLog::add(null, 'user', 'proxy', t('Toegang van buiten'), t('{ip} tijdelijk toegelaten tot {t}', ['ip' => $ip, 't' => date('d-m H:i', strtotime($until))]), true, '', null, (string) $u['username']);
        return ['ok' => true, 'until' => $until];
    }

    public static function revoke(int $id, string $by): bool
    {
        $ok = q('UPDATE proxy_open SET revoked_at = NOW(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL', [mb_substr($by, 0, 64), $id])->rowCount() > 0;
        if ($ok) {
            ActionLog::add(null, 'user', 'proxy', t('Toegang van buiten ingetrokken'), '', true, '', null, $by);
        }
        return $ok;
    }
}
