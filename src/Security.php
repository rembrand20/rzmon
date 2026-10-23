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
    /** Mislukte logins: zoveel binnen zoveel seconden vanaf één IP = ⚠️ */
    private const LOGIN_FAILS = 3;
    private const LOGIN_WINDOW = 600;

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
        foreach (q('SELECT id, net FROM rz_blocks')->fetchAll() as $b) {
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

    /** Blokkeren. Nooit jezelf of de server zelf. @return true|string */
    public static function block(string $net, string $reason, string $by): true|string
    {
        $n = self::normalize($net);
        if ($n === null) {
            return t('Geen geldig IP-adres of netwerk: {n}', ['n' => mb_substr($net, 0, 64)]);
        }
        $mine = client_ip();
        foreach (array_filter([$mine, '127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? '']) as $own) {
            if (ip_in_list($own, [$n])) {
                return $own === $mine
                    ? t('Daarmee blokkeer je jezelf ({ip}); dat kan niet', ['ip' => $mine])
                    : t('Daarmee blokkeer je de RzMon-server zelf ({ip}); dat kan niet', ['ip' => $own]);
            }
        }
        q('INSERT INTO rz_blocks (net, reason, created_by, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [$n, mb_substr(trim($reason), 0, 200) ?: null, mb_substr($by, 0, 64), now()]);
        AgentCommands::audit($by, 'IP geblokkeerd', $n . ($reason !== '' ? " ($reason)" : ''));
        return true;
    }

    public static function unblock(int $id, string $by): void
    {
        $net = q('SELECT net FROM rz_blocks WHERE id = ?', [$id])->fetchColumn();
        if ($net !== false) {
            q('DELETE FROM rz_blocks WHERE id = ?', [$id]);
            AgentCommands::audit($by, 'Blokkade opgeheven', (string) $net);
        }
    }

    public static function blocks(): array
    {
        return q('SELECT id, net, reason, created_by, created_at, hits, last_hit FROM rz_blocks ORDER BY created_at DESC')->fetchAll();
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
        $dev = function_exists('device_name') ? device_name() : '';
        Monitor::infoAlways(null, null, 'ℹ️ ' . t('Ingelogd: {user}', ['user' => $u['username']]),
            fn() => t('{user} logde in vanaf {ip} ({how})', ['user' => $u['username'], 'ip' => $ip, 'how' => t($how)]) . ($dev !== '' ? " · $dev" : ''), $ip);
    }

    /** Mislukte login. Losse pogingen: ℹ️ (per IP één regel per uur met teller); 3 binnen 10 minuten: ⚠️ met Pushover. */
    public static function loginFailed(string $username, string $why): void
    {
        try {
            $ip = client_ip();
            q("INSERT INTO security_fails (ip, kind, created_at) VALUES (?, 'login', ?)", [mb_substr($ip, 0, 45), now()]);
            $n = (int) q("SELECT COUNT(*) FROM security_fails WHERE ip = ? AND kind = 'login' AND created_at >= ?",
                [mb_substr($ip, 0, 45), date('Y-m-d H:i:s', time() - self::LOGIN_WINDOW)])->fetchColumn();
            $user = mb_substr(trim($username), 0, 64) ?: '?';
            if ($n >= self::LOGIN_FAILS) {
                self::unauthorized('login', t('{n} mislukte logins binnen 10 minuten', ['n' => $n]), [t('gebruiker') => $user, t('laatste') => t($why)]);
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
