<?php
declare(strict_types=1);

/**
 * Tijdelijk sudo (1.231.0, agent 1.93.0): een beheerder geeft een gebruiker op één server voor een korte tijd sudo
 * (standaard 30 minuten, hooguit sudo_max, 4 uur), met zijn wachtwoord en, als hij 2FA heeft, zijn code. De agent zet
 * een eigen bestand in /etc/sudoers.d (door visudo gecontroleerd) en haalt het op tijd weg, ook als RzSense even weg
 * is; een systemd-timer is het vangnet als de agent stopt. Intrekken kan altijd. Alles komt in het logboek.
 * Aan/uit: sudo_on (Instellingen → Beveiliging → Tijdelijk sudo of Overzicht → Functies).
 */
final class Sudo
{
    public const AGENT = '1.93.0';
    public const DURATIONS = [15, 30, 60, 120, 240];

    public static function enabled(): bool
    {
        return setting('sudo_on', '1') === '1';
    }

    public static function maxMinutes(): int
    {
        return max(5, min(240, (int) setting('sudo_max', '240')));
    }

    public static function validUser(string $u): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u) && $u !== 'root';
    }

    /** Regels voor de agent: wat nu geldt */
    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $out = [];
        foreach (q("SELECT id, username, UNIX_TIMESTAMP(until) AS u FROM sudo_grants WHERE host_id = ? AND ended_at IS NULL AND until > NOW() AND state IN ('pending', 'active') LIMIT 20", [$hostId])->fetchAll() as $g) {
            $out[] = implode("\t", [0, 'sudo', (int) $g['id'], $g['username'], (int) $g['u']]);
        }
        return $out;
    }

    /** Geven. @return int het id */
    public static function grant(array $host, string $user, int $minutes, string $reason, string $by): int
    {
        $user = strtolower(trim($user));
        if (!self::enabled()) {
            throw new InvalidArgumentException(t('Tijdelijk sudo staat uit (Instellingen → Beveiliging → Tijdelijk sudo)'));
        }
        if (!self::validUser($user)) {
            throw new InvalidArgumentException(t('Ongeldige gebruikersnaam (en root kan niet)'));
        }
        if (empty($host['agent_version']) || version_compare((string) $host['agent_version'], self::AGENT, '<') || ($host['agent_update_via'] ?? '') === 'ha') {
            throw new InvalidArgumentException(t('agent {v} of nieuwer nodig (op Linux, als root)', ['v' => self::AGENT]));
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException(t('Geef een reden (die komt in het logboek)'));
        }
        $minutes = max(5, min(self::maxMinutes(), $minutes));
        // Een eerdere voor dezelfde gebruiker op deze server vervalt (de nieuwe gaat voor)
        q("UPDATE sudo_grants SET ended_at = NOW(), ended_by = ?, state = 'replaced' WHERE host_id = ? AND username = ? AND ended_at IS NULL", [$by, $host['id'], $user]);
        q("INSERT INTO sudo_grants (host_id, username, until, reason, by_user, created_at, state) VALUES (?, ?, NOW() + INTERVAL ? MINUTE, ?, ?, NOW(), 'pending')",
            [$host['id'], $user, $minutes, mb_substr($reason, 0, 200), $by]);
        $id = (int) db()->lastInsertId();
        ActionLog::by($by, (int) $host['id'], 'sudo', t('Tijdelijk sudo gevraagd'), t('{u} voor {m} minuten · {r}', ['u' => $user, 'm' => $minutes, 'r' => $reason]));
        return $id;
    }

    public static function revoke(int $id, string $by): void
    {
        $g = q('SELECT * FROM sudo_grants WHERE id = ?', [$id])->fetch();
        if (!$g || $g['ended_at']) {
            return;
        }
        q("UPDATE sudo_grants SET ended_at = NOW(), ended_by = ?, state = 'revoked' WHERE id = ?", [$by, $id]);
        ActionLog::by($by, (int) $g['host_id'], 'sudo', t('Tijdelijk sudo ingetrokken'), (string) $g['username']);
    }

    /** Wat de agent meldt: actief, weggehaald of mislukt */
    public static function fromAgent(array $host, array $in): void
    {
        foreach (array_slice((array) ($in['sudo'] ?? []), 0, 40) as $r) {
            if (!is_array($r) || !(int) ($r['id'] ?? 0)) {
                continue;
            }
            $id = (int) $r['id'];
            $g = q('SELECT * FROM sudo_grants WHERE id = ? AND host_id = ?', [$id, $host['id']])->fetch();
            if (!$g) {
                continue;
            }
            $st = (string) ($r['st'] ?? '');
            if ($st === 'active' && $g['state'] === 'pending') {
                q("UPDATE sudo_grants SET state = 'active' WHERE id = ?", [$id]);
            } elseif ($st === 'removed') {
                q("UPDATE sudo_grants SET state = IF(state IN ('pending', 'active'), 'expired', state), ended_at = COALESCE(ended_at, NOW()) WHERE id = ?", [$id]);
            } elseif ($st === 'error' && in_array($g['state'], ['pending', 'active'], true)) {
                q("UPDATE sudo_grants SET state = 'failed', detail = ?, ended_at = NOW() WHERE id = ?", [mb_substr((string) ($r['d'] ?? ''), 0, 200), $id]);
            }
        }
    }

    /** Elke minuut: verlopen aanvragen afsluiten (de agent haalt het bestand zelf weg) */
    public static function cron(): void
    {
        q("UPDATE sudo_grants SET state = 'expired', ended_at = until WHERE ended_at IS NULL AND until <= NOW()");
    }

    public static function list(?int $hostId = null, int $limit = 100): array
    {
        $rows = q('SELECT g.*, h.name AS host FROM sudo_grants g JOIN hosts h ON h.id = g.host_id' . ($hostId ? ' WHERE g.host_id = ' . $hostId : '')
            . ' ORDER BY g.id DESC LIMIT ' . max(1, min(500, $limit)))->fetchAll();
        return array_map(static fn($g) => ['id' => (int) $g['id'], 'host_id' => (int) $g['host_id'], 'host' => $g['host'], 'user' => $g['username'],
            'until' => $g['until'], 'reason' => $g['reason'], 'by' => $g['by_user'], 'at' => $g['created_at'], 'state' => $g['state'], 'detail' => (string) $g['detail'],
            'ended_at' => $g['ended_at'], 'ended_by' => $g['ended_by'], 'left' => $g['ended_at'] ? 0 : max(0, strtotime((string) $g['until']) - time())], $rows);
    }

    /** Voor de Agent Manager: aanvragen en bekende gebruikers (die eerder via SSH inlogden) */
    public static function info(array $host): array
    {
        $users = q("SELECT DISTINCT username FROM ssh_events WHERE host_id = ? AND ok = 1 AND username <> 'root' AND at > NOW() - INTERVAL 90 DAY ORDER BY username LIMIT 30", [$host['id']])->fetchAll(PDO::FETCH_COLUMN);
        return ['on' => self::enabled(), 'max' => self::maxMinutes(), 'durations' => array_values(array_filter(self::DURATIONS, static fn($m) => $m <= self::maxMinutes())),
            'agent_ok' => !empty($host['agent_version']) && version_compare((string) $host['agent_version'], self::AGENT, '>=') && ($host['agent_update_via'] ?? '') !== 'ha',
            'grants' => self::list((int) $host['id'], 20), 'users' => array_values(array_filter($users, [self::class, 'validUser']))];
    }

    public static function purge(): void
    {
        q('DELETE FROM sudo_grants WHERE created_at < NOW() - INTERVAL 400 DAY');
    }
}
