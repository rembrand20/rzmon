<?php
declare(strict_types=1);

/**
 * Whitelist (1.83.0): wat nooit geblokkeerd wordt. Eén lijst voor heel RzMon:
 * - ip: IP-adres of netwerk; geen IP-blokkade in RzMon (inloggen) en geen IDS-blokkade op welke server dan ook
 *   (ook niet via de lijsten met bekende aanvallers);
 * - user: account in RzMon dat nooit vergrendeld wordt (het IP van de aanvaller wordt wel geblokkeerd);
 * - decoy: naam die geen lokaas is (valt af van de lokaas-namen, op alle servers).
 * Een regel geldt 1, 7 of 30 dagen, of altijd (until NULL). Op de whitelist zetten heft de passende blokkades meteen op.
 */
final class Whitelist
{
    public const KINDS = ['ip', 'user', 'decoy'];
    public const DAYS = [1, 7, 30, 0];
    private static ?array $cache = null;

    /** @return array de geldige regels (nieuwste eerst) */
    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = q('SELECT id, kind, value, reason, created_by, created_at, until FROM whitelist WHERE until IS NULL OR until > NOW() ORDER BY created_at DESC')->fetchAll();
            } catch (Throwable) {
                self::$cache = [];   // tabel nog niet aangemaakt (vlak voor de update)
            }
        }
        return self::$cache;
    }

    /** @return string[] de waarden van één soort */
    public static function values(string $kind): array
    {
        return array_values(array_map(static fn($r) => (string) $r['value'], array_filter(self::all(), static fn($r) => $r['kind'] === $kind)));
    }

    public static function ip(string $ip): bool
    {
        return $ip !== '' && ip_in_list($ip, self::values('ip'));
    }

    public static function user(string $username): bool
    {
        return in_array(mb_strtolower($username), array_map('mb_strtolower', self::values('user')), true);
    }

    /** @return array|null de regel waar dit IP (of netwerk) onder valt */
    public static function ruleFor(string $net): ?array
    {
        $ip = explode('/', $net)[0];
        foreach (self::all() as $r) {
            if ($r['kind'] === 'ip' && ip_in_list($ip, [$r['value']])) {
                return $r;
            }
        }
        return null;
    }

    /** Waarde controleren en netjes maken. @return string waarde, of ['error' => …] */
    public static function clean(string $kind, string $value): string|array
    {
        $value = trim($value);
        if ($kind === 'ip') {
            $n = Security::normalize($value);
            if ($n === null) {
                return ['error' => t('Geen geldig IP-adres of netwerk: {n}', ['n' => mb_substr($value, 0, 64)])];
            }
            $bits = str_contains($n, '/') ? (int) explode('/', $n)[1] : 128;
            if ($bits < (str_contains($n, ':') ? 32 : 16)) {
                return ['error' => t('Dat netwerk is te groot voor de whitelist (IPv4 hooguit /16, IPv6 hooguit /32)')];
            }
            return $n;
        }
        if ($kind === 'user') {
            $u = q('SELECT username FROM users WHERE username = ?', [$value])->fetchColumn();
            return $u === false ? ['error' => t('Dat account bestaat niet')] : (string) $u;
        }
        if ($kind === 'decoy') {
            $v = mb_strtolower($value);
            return preg_match('/^[a-z0-9._-]{1,32}$/', $v) ? $v : ['error' => t('Geen geldige gebruikersnaam')];
        }
        return ['error' => t('Ongeldige keuze')];
    }

    /**
     * Op de whitelist zetten en de passende blokkades meteen opheffen. $days: 1, 7, 30 of 0 (altijd).
     * @return array ['ok' => true, 'lifted' => n] of ['error' => …]
     */
    public static function add(string $kind, string $value, int $days, string $reason, string $by): array
    {
        $v = self::clean($kind, $value);
        if (is_array($v)) {
            return $v;
        }
        if (!in_array($days, self::DAYS, true)) {
            return ['error' => t('Ongeldige keuze')];
        }
        $until = $days ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $reason = mb_substr(trim($reason), 0, 200);
        q('INSERT INTO whitelist (kind, value, reason, created_by, created_at, until) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by), created_at = VALUES(created_at), until = VALUES(until)',
            [$kind, $v, $reason ?: null, mb_substr($by, 0, 64), now(), $until]);
        self::$cache = null;
        AgentCommands::audit($by, 'Whitelist: toegevoegd', self::label($kind) . " $v · " . ($days ? "$days d" : 'altijd') . ($reason !== '' ? " ($reason)" : ''));
        return ['ok' => true, 'value' => $v, 'lifted' => self::lift($kind, $v, $by)];
    }

    /** De blokkades opheffen die de nieuwe regel raakt. @return int aantal */
    private static function lift(string $kind, string $v, string $by): int
    {
        $n = 0;
        $how = t('{by}, via de whitelist', ['by' => $by]);
        if ($kind === 'ip') {
            foreach (q('SELECT id, net FROM rz_blocks')->fetchAll() as $b) {
                // Een los adres in de regel, of precies dit netwerk (een groter geblokkeerd netwerk blijft; alleen dit deel mag erdoor)
                if ($b['net'] === $v || (!str_contains((string) $b['net'], '/') && ip_in_list((string) $b['net'], [$v]))) {
                    Security::unblock((int) $b['id'], $how);
                    $n++;
                }
            }
            foreach (q('SELECT id, ip FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW())')->fetchAll() as $b) {
                if (ip_in_list((string) $b['ip'], [$v])) {
                    Ids::unblock((int) $b['id'], $how);
                    $n++;
                }
            }
        } elseif ($kind === 'user') {
            $u = q('SELECT id, locked_at, locked_until FROM users WHERE username = ?', [$v])->fetch();
            if ($u && Security::accountLocked($u)) {
                Security::unlockUser((int) $u['id'], $how);
                $n++;
            }
        } else {
            foreach (q('SELECT id FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW()) AND decoy_user = ?', [$v])->fetchAll() as $b) {
                Ids::unblock((int) $b['id'], $how);
                $n++;
            }
        }
        return $n;
    }

    public static function remove(int $id, string $by): bool
    {
        $r = q('SELECT kind, value FROM whitelist WHERE id = ?', [$id])->fetch();
        if (!$r) {
            return false;
        }
        q('DELETE FROM whitelist WHERE id = ?', [$id]);
        self::$cache = null;
        AgentCommands::audit($by, 'Whitelist: verwijderd', self::label((string) $r['kind']) . ' ' . $r['value']);
        return true;
    }

    private static function label(string $kind): string
    {
        return ['ip' => 'IP', 'user' => 'account', 'decoy' => 'geen lokaas'][$kind] ?? $kind;
    }

    /** Elke minuut (cron): verlopen regels opruimen */
    public static function expire(): void
    {
        q('DELETE FROM whitelist WHERE until IS NOT NULL AND until <= ?', [now()]);
    }

    /** Update naar 1.83.0: de lijst "Nooit blokkeren" van het IDS (ids_allow) eenmalig overnemen; de instelling zelf blijft staan */
    public static function migrate(PDO $pdo): void
    {
        if ($pdo->query("SELECT 1 FROM settings WHERE name = 'whitelist_migrated'")->fetchColumn()) {
            return;
        }
        $allow = (string) $pdo->query("SELECT value FROM settings WHERE name = 'ids_allow'")->fetchColumn();
        $st = $pdo->prepare("INSERT IGNORE INTO whitelist (kind, value, reason, created_by) VALUES ('ip', ?, ?, 'update')");
        foreach (parse_list($allow) as $x) {
            if (($n = Security::normalize($x)) !== null) {
                $st->execute([$n, 'Nooit blokkeren (IDS)']);
            }
        }
        $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('whitelist_migrated', '1')");
    }
}
