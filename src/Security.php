<?php
declare(strict_types=1);

/**
 * Beveiliging van RzMon zelf (1.57.0):
 * - geblokkeerde IP-adressen en netwerken: heel RzMon (webpagina, inloggen, API en agents) geeft dan 403;
 * - ⚠️ niet-geautoriseerde verbindingen (agent met een fout token of een verkeerde sleutel, een verkeerde
 *   API-sleutel, 3 mislukte logins binnen 10 minuten): in Meldingen en altijd naar Pushover;
 * - ℹ️ logins in RzMon en agents die (opnieuw) verbinden: altijd in Meldingen, de rest volgt de rij Info.
 */
final class Security
{
    /** Per IP en soort hooguit één ⚠️-melding (met Pushover) per uur; daarna loopt alleen de teller op */
    private const SEC_WINDOW = 3600;

    // ------------------------------------------------------------------ blokkades

    /** Netwerk of adres netjes maken: 203.0.113.7, 203.0.113.0/24, 2001:db8::/32. @return string|null null = ongeldig */
    public static function normalize(string $net): ?string
    {
        $net = trim($net);
        [$ip, $bits] = array_pad(explode('/', $net, 2), 2, null);
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return null;
        }
        $max = strlen($bin) === 4 ? 32 : 128;
        if ($bits === null || $bits === '') {
            return inet_ntop($bin);
        }
        if (!ctype_digit($bits) || (int) $bits < 1 || (int) $bits > $max) {
            return null;
        }
        // Netwerkadres: de bits achter het prefix op 0 (203.0.113.7/24 → 203.0.113.0/24)
        $b = (int) $bits;
        $out = '';
        for ($i = 0; $i < strlen($bin); $i++) {
            $keep = max(0, min(8, $b - $i * 8));
            $out .= chr(ord($bin[$i]) & (0xFF << (8 - $keep)) & 0xFF);
        }
        return inet_ntop($out) . ($b === $max ? '' : "/$b");
    }

    /** @return array|null de blokkade waar dit IP onder valt */
    public static function blockFor(string $ip): ?array
    {
        if ($ip === '') {
            return null;
        }
        foreach (q('SELECT id, net FROM rz_blocks WHERE until IS NULL OR until > NOW()')->fetchAll() as $b) {
            if (ip_in_list($ip, [$b['net']])) {
                return $b;
            }
        }
        return null;
    }

    /** Aan het begin van elk webverzoek (niet voor cron): geblokkeerd? Dan 403 en verder niets. */
    public static function enforce(): void
    {
        try {
            $ip = client_ip();
            $b = self::blockFor($ip);
        } catch (Throwable) {
            return;   // database nog niet klaar (setup, update): niet blokkeren
        }
        if (!$b) {
            return;
        }
        q('UPDATE rz_blocks SET hits = hits + 1, last_hit = ? WHERE id = ?', [now(), $b['id']]);
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit("403 – geblokkeerd\n");
    }

    /**
     * Blokkeren. Nooit de server zelf, en met de hand ook niet jezelf. $until: tot wanneer (null = blijvend);
     * $auto: door de inlogbeveiliging (dan is "jezelf" juist de bedoeling: dat is degene die het probeert).
     * @return true|string
     */
    public static function block(string $net, string $reason, string $by, ?string $until = null, bool $auto = false): true|string
    {
        $n = self::normalize($net);
        if ($n === null) {
            return t('Geen geldig IP-adres of netwerk: {n}', ['n' => mb_substr($net, 0, 64)]);
        }
        $mine = client_ip();
        foreach (array_filter([$auto ? '' : $mine, '127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '']) as $own) {
            if (ip_in_list($own, [$n])) {
                return $own === $mine
                    ? t('Daarmee blokkeer je jezelf ({ip}); dat kan niet', ['ip' => $mine])
                    : t('Daarmee blokkeer je de RzMon-server zelf ({ip}); dat kan niet', ['ip' => $own]);
            }
        }
        q('INSERT INTO rz_blocks (net, reason, created_by, created_at, until, auto, hits, last_hit) VALUES (?, ?, ?, ?, ?, ?, 0, NULL)
           ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by), created_at = VALUES(created_at), until = VALUES(until), auto = VALUES(auto)',
            [$n, mb_substr(trim($reason), 0, 200) ?: null, mb_substr($by, 0, 64), now(), $until, $auto ? 1 : 0]);
        AgentCommands::audit($by, 'IP geblokkeerd', $n . ($reason !== '' ? " ($reason)" : ''));
        // ℹ️ in Meldingen: wat, door wie, waarom en tot wanneer
        Monitor::infoAlways(null, null, '⛔ ' . t('IP geblokkeerd: {net}', ['net' => $n]), fn() => t('{net} is geblokkeerd door {by}', ['net' => $n, 'by' => $auto ? t('de inlogbeveiliging') : $by])
            . (trim($reason) !== '' ? ' · ' . t('reden') . ': ' . trim($reason) : '') . ' · ' . ($until ? t('tot {t}', ['t' => date('d-m H:i', strtotime($until))]) : t('tot een beheerder deblokkeert')),
            str_contains($n, '/') ? null : $n);
        return true;
    }

    public static function unblock(int $id, string $by): void
    {
        $net = q('SELECT net FROM rz_blocks WHERE id = ?', [$id])->fetchColumn();
        if ($net !== false) {
            q('DELETE FROM rz_blocks WHERE id = ?', [$id]);
            AgentCommands::audit($by, 'Blokkade opgeheven', (string) $net);
            self::liftedInfo((string) $net, t('door {by}', ['by' => $by]));
        }
    }

    /** ℹ️ in Meldingen: een IP-blokkade is opgeheven (met de hand of verlopen) */
    private static function liftedInfo(string $net, string $how): void
    {
        Monitor::infoAlways(null, null, '✅ ' . t('Blokkade opgeheven: {net}', ['net' => $net]),
            fn() => t('{net} heeft weer toegang tot RzMon', ['net' => $net]) . " · $how", str_contains($net, '/') ? null : $net);
    }

    /**
     * Elke minuut (cron): verlopen blokkades opruimen, met een ℹ️-melding. Ook zonder deze run gelden ze niet meer
     * (blockFor en accountLocked kijken zelf naar de tijd); dit zorgt alleen voor de melding en een nette lijst.
     */
    public static function expire(): void
    {
        foreach (q('SELECT id, net FROM rz_blocks WHERE until IS NOT NULL AND until <= ?', [now()])->fetchAll() as $b) {
            q('DELETE FROM rz_blocks WHERE id = ?', [$b['id']]);
            self::liftedInfo((string) $b['net'], t('de ingestelde tijd is voorbij'));
        }
        foreach (q('SELECT id, username FROM users WHERE locked_at IS NOT NULL AND locked_until IS NOT NULL AND locked_until <= ?', [now()])->fetchAll() as $u) {
            q('UPDATE users SET locked_at = NULL, locked_until = NULL, locked_ip = NULL WHERE id = ?', [$u['id']]);
            self::unlockedInfo((string) $u['username'], t('de ingestelde tijd is voorbij'));
        }
    }

    /** ℹ️ in Meldingen: een account kan weer inloggen */
    private static function unlockedInfo(string $user, string $how): void
    {
        Monitor::infoAlways(null, null, '🔓 ' . t('Account gedeblokkeerd: {user}', ['user' => $user]),
            fn() => t('{user} kan weer overal inloggen', ['user' => $user]) . " · $how");
    }

    public static function blocks(): array
    {
        return q('SELECT id, net, reason, created_by, created_at, hits, last_hit, until, auto FROM rz_blocks ORDER BY created_at DESC')->fetchAll();
    }

    // ------------------------------------------------------------------ ⚠️ niet-geautoriseerd

    /**
     * Niet-geautoriseerde poging. $what: agent | api | login. Eén regel per IP en soort per uur (de teller loopt op);
     * alleen de eerste gaat naar Pushover, ook tijdens stille uren.
     */
    public static function unauthorized(string $what, string $reason, array $extra = []): void
    {
        try {
            $ip = client_ip();
            $label = ['agent' => t('agent'), 'api' => 'REST API', 'login' => t('inloggen')][$what] ?? $what;
            $key = "$what|$ip";
            $prev = q("SELECT id, detail FROM alerts WHERE kind = 'sec' AND ip = ? AND created_at >= ? AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.key')) = ?
                       ORDER BY id DESC LIMIT 1", [mb_substr($ip, 0, 45), date('Y-m-d H:i:s', time() - self::SEC_WINDOW), $key])->fetch();
            $msg = trim($reason . ($extra ? ' · ' . implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($extra), $extra)) : ''));
            if ($prev) {
                $d = json_decode((string) $prev['detail'], true) ?: [];
                $d['n'] = (int) ($d['n'] ?? 1) + 1;
                $d['last'] = now();
                q('UPDATE alerts SET title = ?, message = ?, detail = ? WHERE id = ?', [
                    mb_substr('⚠️ ' . t('Niet-geautoriseerde verbinding') . " ({$d['n']}×): $label – $ip", 0, 250),
                    mb_substr($msg, 0, 1000), json_encode($d, JSON_UNESCAPED_UNICODE), $prev['id']]);
                return;
            }
            I18n::forNotify(function () use ($what, $ip, $msg, $key, $label) {
                $title = '⚠️ ' . t('Niet-geautoriseerde verbinding') . ": $label – $ip";
                $sent = null;
                $err = '';
                if (setting('pushover_enabled', '0') === '1') {
                    $base = rtrim((string) config('base_url', ''), '/');
                    $r = Pushover::send($title, $msg . "\n" . date('d-m-Y H:i:s'), max(0, min(1, (int) setting('pushover_priority_warn', 0))), null, null,
                        $base !== '' ? ['url' => "$base/#alerts", 'url_title' => t('Open RzMon')] : []);
                    $sent = $r['ok'];
                    $err = $r['ok'] ? '' : ' [' . t('niet verzonden') . ': Pushover: ' . $r['error'] . ']';
                }
                q("INSERT INTO alerts (check_id, status, title, message, sent_ok, kind, ip, detail) VALUES (NULL, ?, ?, ?, ?, 'sec', ?, ?)", [
                    STATUS_WARN, mb_substr($title, 0, 250), mb_substr($msg . $err, 0, 1000), $sent ? 1 : 0, mb_substr($ip, 0, 45),
                    json_encode(['key' => $key, 'what' => $what, 'n' => 1, 'last' => now()], JSON_UNESCAPED_UNICODE)]);
            });
        } catch (Throwable) {
            // Nooit het verzoek zelf laten mislukken door een melding
        }
    }

    // ------------------------------------------------------------------ logins in RzMon

    /** Geslaagde login (wachtwoord, met 2FA, of een onthouden apparaat) */
    public static function loginOk(array $u, string $how): void
    {
        $ip = client_ip();
        try {
            q("DELETE FROM security_fails WHERE kind = 'login' AND user = ?", [(string) $u['username']]);   // gelukt: opnieuw tellen
        } catch (Throwable) {
        }
        $dev = function_exists('device_name') ? device_name() : '';
        Monitor::infoAlways(null, null, 'ℹ️ ' . t('Ingelogd: {user}', ['user' => $u['username']]),
            fn() => t('{user} logde in vanaf {ip} ({how})', ['user' => $u['username'], 'ip' => $ip, 'how' => t($how)]) . ($dev !== '' ? " · $dev" : ''), $ip);
    }

    // ------------------------------------------------------------------ inlogbeveiliging

    /** Instellingen: na zoveel pogingen binnen zoveel minuten; account en/of IP; hoe lang (0 = tot een beheerder deblokkeert) */
    public static function lockConfig(): array
    {
        return [
            'fails' => max(1, min(50, (int) setting('login_lock_fails', '3'))),
            'window' => max(1, min(1440, (int) setting('login_lock_window', '10'))),
            'account' => setting('login_lock_account', '1') === '1',
            'ip' => setting('login_lock_ip', '1') === '1',
            'minutes' => max(0, min(525600, (int) setting('login_lock_minutes', '60'))),
        ];
    }

    /** Komt dit verzoek uit een van de lokale netwerken (Instellingen → Toegang)? */
    public static function fromLan(): bool
    {
        return ip_in_list(client_ip(), trusted_networks());
    }

    /** Is dit account (nu nog) geblokkeerd? @param array $u met locked_at en locked_until */
    public static function accountLocked(array $u): bool
    {
        return !empty($u['locked_at']) && (empty($u['locked_until']) || strtotime((string) $u['locked_until']) > time());
    }

    /** Beheerder deblokkeert een account (en de telling begint opnieuw) */
    public static function unlockUser(int $id, string $by): void
    {
        $name = q('SELECT username FROM users WHERE id = ?', [$id])->fetchColumn();
        if ($name === false) {
            return;
        }
        $was = q('SELECT locked_at, locked_until FROM users WHERE id = ?', [$id])->fetch();
        q('UPDATE users SET locked_at = NULL, locked_until = NULL, locked_ip = NULL WHERE id = ?', [$id]);
        q("DELETE FROM security_fails WHERE kind = 'login' AND user = ?", [$name]);
        AgentCommands::audit($by, 'Account gedeblokkeerd', (string) $name);
        if ($was && self::accountLocked($was)) {
            self::unlockedInfo((string) $name, t('door {by}', ['by' => $by]));
        }
    }

    /**
     * Mislukte login. Losse pogingen: ℹ️ (per IP één regel per uur met een teller). Zoveel als ingesteld binnen de tijd:
     * per account (vanaf welk IP dan ook) wordt het account geblokkeerd, per IP (van buiten het LAN) het IP; ⚠️ + Pushover.
     * $exists: of de gebruikersnaam bestaat (anders valt er geen account te blokkeren).
     */
    public static function loginFailed(string $username, string $why, bool $exists = false): void
    {
        try {
            $ip = client_ip();
            $cfg = self::lockConfig();
            $user = mb_substr(trim($username), 0, 64) ?: '?';
            $since = date('Y-m-d H:i:s', time() - $cfg['window'] * 60);
            q("INSERT INTO security_fails (ip, kind, user, created_at) VALUES (?, 'login', ?, ?)", [mb_substr($ip, 0, 45), $user, now()]);
            $nIp = (int) q("SELECT COUNT(*) FROM security_fails WHERE ip = ? AND kind = 'login' AND created_at >= ?", [mb_substr($ip, 0, 45), $since])->fetchColumn();
            $nUser = $exists ? (int) q("SELECT COUNT(*) FROM security_fails WHERE user = ? AND kind = 'login' AND created_at >= ?", [$user, $since])->fetchColumn() : 0;
            $until = $cfg['minutes'] > 0 ? date('Y-m-d H:i:s', time() + $cfg['minutes'] * 60) : null;
            $done = [];
            if ($cfg['account'] && $exists && $nUser >= $cfg['fails']
                && q('UPDATE users SET locked_at = ?, locked_until = ?, locked_ip = ? WHERE username = ? AND (locked_at IS NULL OR (locked_until IS NOT NULL AND locked_until <= NOW()))',
                    [now(), $until, mb_substr($ip, 0, 45), $user])->rowCount() > 0) {
                $done[] = '🔒 ' . t('account {user} geblokkeerd', ['user' => $user]);
                Monitor::infoAlways(null, null, '🔒 ' . t('Account geblokkeerd: {user}', ['user' => $user]),
                    fn() => t('Account {user} is geblokkeerd na {n} mislukte logins binnen {m} minuten (laatste vanaf {ip})', ['user' => $user, 'n' => $nUser, 'm' => $cfg['window'], 'ip' => $ip])
                        . ' · ' . ($until ? t('tot {t}', ['t' => date('d-m H:i', strtotime($until))]) : t('tot een beheerder deblokkeert')) . ' · ' . t('vanaf het lokale netwerk kan inloggen nog wel'), $ip);
            }
            if ($cfg['ip'] && $nIp >= $cfg['fails'] && !self::fromLan() && !self::blockFor($ip)
                && self::block($ip, t('automatisch: {n} mislukte logins ({user})', ['n' => $nIp, 'user' => $user]), 'automatisch', $until, true) === true) {
                $done[] = '⛔ ' . t('IP {ip} geblokkeerd', ['ip' => $ip]);
            }
            if ($done || max($nIp, $nUser) >= $cfg['fails']) {
                self::unauthorized('login', t('{n} mislukte logins binnen {m} minuten', ['n' => max($nIp, $nUser), 'm' => $cfg['window']])
                    . ($done ? ' · ' . implode(' · ', $done) . ($until ? ' ' . t('tot {t}', ['t' => date('d-m H:i', strtotime($until))]) : ' (' . t('tot een beheerder deblokkeert') . ')') : ''),
                    [t('gebruiker') => $user, t('laatste') => t($why)]);
                return;
            }
            // ℹ️, opgeteld per IP per uur
            $prev = q("SELECT id, detail FROM alerts WHERE kind = 'info' AND ip = ? AND created_at >= ? AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.key')) = 'loginfail'
                       ORDER BY id DESC LIMIT 1", [mb_substr($ip, 0, 45), date('Y-m-d H:i:s', time() - 3600)])->fetch();
            if ($prev) {
                $d = json_decode((string) $prev['detail'], true) ?: [];
                $d['n'] = (int) ($d['n'] ?? 1) + 1;
                $d['users'] = array_slice(array_values(array_unique(array_merge((array) ($d['users'] ?? []), [$user]))), 0, 10);
                q('UPDATE alerts SET title = ?, message = ?, detail = ? WHERE id = ?', [
                    mb_substr('ℹ️ ' . t('Mislukte login') . " ({$d['n']}×) – $ip", 0, 250),
                    mb_substr(t('Mislukte login vanaf {ip}: {why}', ['ip' => $ip, 'why' => t($why)]) . ' · ' . t('gebruiker') . ': ' . implode(', ', $d['users']), 0, 1000),
                    json_encode($d, JSON_UNESCAPED_UNICODE), $prev['id']]);
                return;
            }
            Monitor::infoAlways(null, null, 'ℹ️ ' . t('Mislukte login') . " – $ip",
                fn() => t('Mislukte login vanaf {ip}: {why}', ['ip' => $ip, 'why' => t($why)]) . ' · ' . t('gebruiker') . ": $user", $ip,
                ['key' => 'loginfail', 'n' => 1, 'users' => [$user]]);
        } catch (Throwable) {
        }
    }

    // ------------------------------------------------------------------ agents

    /**
     * ℹ️ als een agent verbindt: de eerste keer, na stil te zijn geweest, of vanaf een ander IP-adres.
     * $prev = de server vóór dit rapport (agent_seen, agent_ip, agent_tick).
     */
    public static function agentConnected(array $prev, bool $encrypted): void
    {
        $ip = client_ip();
        $seen = $prev['agent_seen'] ?? null;
        $why = match (true) {
            !$seen => t('verbindt voor het eerst'),
            strtotime((string) $seen) < time() - agent_stale_seconds(isset($prev['agent_tick']) ? (int) $prev['agent_tick'] : null)
                => t('verbindt weer (stil sinds {t})', ['t' => date('d-m H:i', strtotime((string) $seen))]),
            ($prev['agent_ip'] ?? '') !== '' && $prev['agent_ip'] !== $ip => t('verbindt vanaf een ander IP-adres (was {old})', ['old' => $prev['agent_ip']]),
            default => null,
        };
        if ($why === null) {
            return;
        }
        Monitor::infoAlways((int) $prev['id'], (string) $prev['name'], 'ℹ️ ' . t('Agent verbonden: {name}', ['name' => $prev['name']]),
            fn() => t('Agent op {name} {why}', ['name' => $prev['name'], 'why' => $why]) . " · IP $ip · " . ($encrypted ? '🔒 ' . t('versleuteld') : '🔓 ' . t('niet versleuteld')), $ip);
    }

    public static function purge(): void
    {
        q('DELETE FROM security_fails WHERE created_at < NOW() - INTERVAL 1 DAY');
        q('DELETE FROM agent_nonces WHERE created_at < NOW() - INTERVAL 1 HOUR');
    }
}
