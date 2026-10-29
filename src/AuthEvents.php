<?php
declare(strict_types=1);

/**
 * Logins (SSH, console), sudo en su van de agents, op de pagina IDS → Toegang (tabel access_log, kind auth/authfail).
 * Alleen tonen, niet versturen. Aan/uit en uitzonderingen: Instellingen → IDS → Algemeen (auth_events, auth_ignore).
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
                'ssh' => ['🔑 ' . t('SSH-login: {u} op {host}', ['u' => $u, 'host' => $name]),
                    t('vanaf {ip}', ['ip' => $ip]) . ' (' . (isset(self::METHODS[$e['m'] ?? '']) ? t(self::METHODS[$e['m']]) : $str($e['m'] ?? '', 40)) . ')'],
                'console' => ['🖥️ ' . t('Console-login: {u} op {host}', ['u' => $u, 'host' => $name]), t('aan de console')],
                'sudo' => ['⚡ ' . t('sudo: {u} op {host}', ['u' => $u, 'host' => $name]), t('als {as}', ['as' => $as]) . ': ' . $str($e['cmd'] ?? '', 500)
                    . (($e['pwd'] ?? '') !== '' ? ' · ' . t('in') . ' ' . $str($e['pwd'], 200) : '') . (($e['tty'] ?? '') !== '' ? ' · ' . $str($e['tty'], 30) : '')],
                'su' => ['🔀 ' . t('su: {u} op {host}', ['u' => $u, 'host' => $name]) . " → $as", t('{u} werd {as}', ['u' => $u, 'as' => $as])],
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
                'ssh' => ["❌ $times" . t('mislukte SSH-login op {host}', ['host' => $name]),
                    t('vanaf {ip}', ['ip' => $ip]) . ' · ' . (count($users) === 1 ? t('gebruiker') : t('gebruikers')) . ": $who"],
                'console' => ["❌ $times" . t('mislukte console-login op {host}', ['host' => $name]),
                    t('gebruiker') . ": $who" . (($f['tty'] ?? '') !== '' ? ' · ' . $str($f['tty'], 30) : '')],
                'sudo' => ["❌ $times" . t('mislukte sudo: {u} op {host}', ['u' => $who, 'host' => $name]),
                    (isset($f['why']) ? $str($f['why'], 150) : t('geweigerd')) . ($as !== '' ? ' · ' . t('als {as}', ['as' => $as]) : '') . ($cmd !== '' ? ": $cmd" : '')],
                'su' => ["❌ $times" . t('mislukte su: {u} op {host}', ['u' => $who, 'host' => $name]) . " → $as", t('verkeerd wachtwoord')],
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
        // Land en bekende aanvaller (1.82.0, als de IDS dat bijhoudt)
        if (($detail['ip'] ?? '') !== '') {
            $detail['cc'] = IpIntel::country((string) $detail['ip']);
            $detail['bad'] = IpIntel::bad((string) $detail['ip']);
        }
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
