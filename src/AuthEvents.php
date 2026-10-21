<?php
declare(strict_types=1);

/**
 * Logins (SSH, console), sudo en su van de agents, op de pagina IDS → Toegang (tabel access_log, kind auth/authfail).
 * Alleen tonen, niet versturen. Aan/uit en uitzonderingen: Instellingen → 🛡️ IDS (auth_events, auth_ignore).
 * Mislukte pogingen komen opgeteld binnen; per server, soort en IP/gebruiker één regel per uur.
 */
final class AuthEvents
{
    private const LABELS = ['ssh' => 'SSH-login', 'console' => 'Console-login', 'sudo' => 'sudo', 'su' => 'su'];
    private const METHODS = ['publickey' => 'sleutel', 'password' => 'wachtwoord', 'keyboard-interactive/pam' => 'wachtwoord', 'gssapi-with-mic' => 'Kerberos'];

    public static function enabled(): bool
    {
        return setting('auth_events', '1') === '1';
    }

    /** Gebruikers en IP-adressen (of netwerken) die niet getoond worden. */
    public static function ignored(array $users, string $ip): bool
    {
        $list = parse_list((string) setting('auth_ignore', ''));
        if (!$list) {
            return false;
        }
        // IP-adressen en netwerken (192.168.2.5, 10.0.0.0/8, fe80::/10) apart van gebruikersnamen
        $nets = array_filter($list, fn($x) => @inet_pton(explode('/', $x)[0]) !== false);
        if ($ip !== '' && ip_in_list($ip, $nets)) {
            return true;
        }
        $users = array_filter($users, fn($u) => $u !== '' && $u !== '?');
        return $users && !array_diff($users, $list);   // alleen genegeerde gebruikers
    }

    /** Rapport van de agent verwerken. @return int aantal nieuwe of bijgewerkte regels */
    public static function store(array $host, array $in): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $name = (string) $host['name'];
        $n = 0;
        $str = static fn($v, int $max = 100) => mb_substr(trim((string) $v), 0, $max);
        $when = static fn($t) => date('Y-m-d H:i:s', max(time() - 86400, min(time(), (int) $t ?: time())));

        foreach (array_slice((array) ($in['events'] ?? []), 0, 300) as $e) {
            $k = (string) ($e['k'] ?? '');
            if (!is_array($e) || !isset(self::LABELS[$k])) {
                continue;
            }
            $u = $str($e['u'] ?? '?') ?: '?';
            $ip = $str($e['ip'] ?? '', 45);
            if (self::ignored([$u], $ip)) {
                continue;
            }
            $as = $str($e['as'] ?? '');
            [$title, $msg] = match ($k) {
                'ssh' => ["🔑 SSH-login: $u op $name", "vanaf $ip (" . (self::METHODS[$e['m'] ?? ''] ?? $str($e['m'] ?? '', 40)) . ')'],
                'console' => ["🖥️ Console-login: $u op $name", 'aan de console'],
                'sudo' => ["⚡ sudo: $u op $name", "als $as: " . $str($e['cmd'] ?? '', 500)
                    . (($e['pwd'] ?? '') !== '' ? ' · in ' . $str($e['pwd'], 200) : '') . (($e['tty'] ?? '') !== '' ? ' · ' . $str($e['tty'], 30) : '')],
                'su' => ["🔀 su: $u op $name → $as", "$u werd $as"],
            };
            self::insert($host, 'auth', $title, $msg, $when($e['t'] ?? 0),
                ['type' => $k, 'user' => $u, 'ip' => $ip, 'as' => $as, 'cmd' => $str($e['cmd'] ?? '', 500)]);
            $n++;
        }

        foreach (array_slice((array) ($in['fails'] ?? []), 0, 100) as $f) {
            $k = (string) ($f['k'] ?? '');
            if (!is_array($f) || !isset(self::LABELS[$k])) {
                continue;
            }
            $count = max(1, (int) ($f['n'] ?? 1));
            $users = array_values(array_unique(array_map(fn($u) => $str($u, 64), array_slice((array) ($f['users'] ?? []), 0, 8))));
            $ip = $str($f['ip'] ?? '', 45);
            if (self::ignored($users, $ip)) {
                continue;
            }
            $as = $str($f['as'] ?? '');
            $cmd = $str($f['cmd'] ?? '', 300);
            // Zelfde soort mislukking op dezelfde server binnen een uur: optellen bij de bestaande regel
            $key = $k . '|' . match ($k) { 'ssh' => $ip, 'console' => $str($f['tty'] ?? ''), 'sudo' => ($users[0] ?? '') . "|$as|$cmd", 'su' => ($users[0] ?? '') . "|$as" };
            $prev = q("SELECT id, detail FROM access_log WHERE host_id = ? AND kind = 'authfail' AND created_at >= NOW() - INTERVAL 1 HOUR
                       AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.key')) = ? ORDER BY id DESC LIMIT 1", [$host['id'], $key])->fetch();
            if ($prev) {
                $d = json_decode((string) $prev['detail'], true) ?: [];
                $count += (int) ($d['n'] ?? 0);
                $users = array_slice(array_values(array_unique(array_merge((array) ($d['users'] ?? []), $users))), 0, 10);
            }
            $who = implode(', ', $users) ?: '?';
            $times = $count === 1 ? '' : "{$count}× ";
            [$title, $msg] = match ($k) {
                'ssh' => ["❌ {$times}mislukte SSH-login op $name", "vanaf $ip · gebruiker" . (count($users) === 1 ? '' : 's') . ": $who"],
                'console' => ["❌ {$times}mislukte console-login op $name", "gebruiker: $who" . (($f['tty'] ?? '') !== '' ? ' · ' . $str($f['tty'], 30) : '')],
                'sudo' => ["❌ {$times}mislukte sudo: $who op $name", $str($f['why'] ?? 'geweigerd', 150) . ($as !== '' ? " · als $as" : '') . ($cmd !== '' ? ": $cmd" : '')],
                'su' => ["❌ {$times}mislukte su: $who op $name → $as", 'verkeerd wachtwoord'],
            };
            $detail = ['type' => $k, 'key' => $key, 'n' => $count, 'users' => $users, 'ip' => $ip, 'as' => $as, 'cmd' => $cmd, 'last' => $when($f['t'] ?? 0)];
            if ($prev) {
                q('UPDATE access_log SET title = ?, message = ?, detail = ? WHERE id = ?',
                    [mb_substr($title, 0, 250), mb_substr($msg, 0, 1000), json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $prev['id']]);
            } else {
                self::insert($host, 'authfail', $title, $msg, $when($f['t'] ?? 0), $detail);
            }
            $n++;
        }
        return $n;
    }

    private static function insert(array $host, string $kind, string $title, string $msg, string $at, array $detail): void
    {
        q('INSERT INTO access_log (host_id, kind, type, title, message, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $host['id'], $kind, $detail['type'], mb_substr($title, 0, 250), mb_substr($msg, 0, 1000),
            json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $at,
        ]);
    }

    /** Is deze regel (nu) genegeerd? Voor regels die al bewaard waren voordat iets aan de lijst werd toegevoegd. */
    public static function rowIgnored(array $row): bool
    {
        $d = json_decode((string) $row['detail'], true) ?: [];
        return self::ignored(isset($d['users']) ? (array) $d['users'] : [(string) ($d['user'] ?? '')], (string) ($d['ip'] ?? ''));
    }
}
