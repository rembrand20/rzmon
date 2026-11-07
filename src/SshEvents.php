<?php
declare(strict_types=1);

/**
 * SSH-pogingen en -sessies (RzSense 1.152.0, agent 1.74.0).
 *
 * De agent stuurt de losse mislukte pogingen (tijd, IP, gebruiker, bronpoort) en bij een geslaagde login de bronpoort;
 * het einde van de sessie ("Disconnected from user …") koppelt RzSense aan de login met hetzelfde IP en dezelfde poort.
 * Voor de aanvalskaart (wanneer precies), de popup (elke poging) en IDS → Toegang → SSH (alle sessies). 30 dagen bewaard.
 * Oudere agents sturen alleen de opgetelde pogingen: die staan in ids_attempts en access_log, zoals altijd.
 */
final class SshEvents
{
    public const KEEP_DAYS = 30;

    /** Het auth-deel van een agentrapport verwerken. @return int aantal opgeslagen regels */
    public static function store(array $host, array $auth): int
    {
        $hid = (int) $host['id'];
        $when = static fn($t) => date('Y-m-d H:i:s', max(time() - 86400, min(time(), (int) $t ?: time())));
        $str = static fn($v, int $max) => mb_substr(trim((string) $v), 0, $max);
        $port = static fn($p) => is_numeric($p) && (int) $p > 0 && (int) $p <= 65535 ? (int) $p : null;
        $n = 0;
        foreach (array_slice((array) ($auth['events'] ?? []), 0, 300) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $k = (string) ($e['k'] ?? '');
            $ip = $str($e['ip'] ?? '', 45);
            if (($k !== 'ssh' && $k !== 'ssh_end') || @inet_pton($ip) === false) {
                continue;
            }
            $user = $str($e['u'] ?? '?', 64) ?: '?';
            if ($k === 'ssh') {
                if (AuthEvents::ignored([$user], $ip)) {
                    continue;
                }
                q('INSERT INTO ssh_events (host_id, at, ok, ip, username, port, method) VALUES (?, ?, 1, ?, ?, ?, ?)',
                    [$hid, $when($e['t'] ?? 0), $ip, $user, $port($e['p'] ?? null), $str($e['m'] ?? '', 30) ?: null]);
                $n++;
            } elseif (($p = $port($e['p'] ?? null)) !== null) {
                // Einde van de sessie: de laatste open login van dit IP en deze poort (vóór het einde)
                $end = $when($e['t'] ?? 0);
                $n += q('UPDATE ssh_events SET closed_at = ? WHERE host_id = ? AND ok = 1 AND ip = ? AND port = ? AND closed_at IS NULL AND at <= ?
                         ORDER BY id DESC LIMIT 1', [$end, $hid, $ip, $p, $end])->rowCount();
            }
        }
        $rows = [];
        foreach (array_slice((array) ($auth['tries'] ?? []), 0, 200) as $t) {
            $ip = is_array($t) ? $str($t['ip'] ?? '', 45) : '';
            if ($ip === '' || @inet_pton($ip) === false) {
                continue;
            }
            $user = $str($t['u'] ?? '?', 64) ?: '?';
            if (AuthEvents::ignored([$user], $ip)) {
                continue;
            }
            array_push($rows, $hid, $when($t['t'] ?? 0), $ip, $user, $port($t['p'] ?? null));
        }
        if ($rows) {
            $cnt = intdiv(count($rows), 5);
            q('INSERT INTO ssh_events (host_id, at, ok, ip, username, port) VALUES ' . implode(',', array_fill(0, $cnt, '(?, ?, 0, ?, ?, ?)')), $rows);
            $n += $cnt;
        }
        return $n;
    }

    /** Ouder dan 30 dagen: weg (vanuit de dagelijkse opruiming) */
    public static function purge(): void
    {
        q('DELETE FROM ssh_events WHERE at < NOW() - INTERVAL ? DAY', [self::KEEP_DAYS]);
    }

    /**
     * Geslaagde SSH-sessies (IDS → Toegang → SSH → Gelukt), nieuwste eerst, met de duur (of "nog bezig").
     * Per IP ook het aantal mislukte pogingen in dezelfde periode: "eerst 40 keer mis, toen binnen" valt zo op.
     */
    public static function sessions(int $days = 7, int $limit = 1000): array
    {
        $days = max(1, min(self::KEEP_DAYS, $days));
        $rows = q("SELECT s.id, s.host_id, h.name AS host, s.at, s.ip, s.username, s.port, s.method, s.closed_at
                     FROM ssh_events s JOIN hosts h ON h.id = s.host_id
                    WHERE s.ok = 1 AND s.at >= NOW() - INTERVAL $days DAY ORDER BY s.at DESC, s.id DESC LIMIT " . max(1, min(5000, $limit)))->fetchAll();
        $fails = [];
        foreach (q("SELECT host_id, ip, COUNT(*) AS n FROM ssh_events WHERE ok = 0 AND at >= NOW() - INTERVAL $days DAY GROUP BY host_id, ip")->fetchAll() as $f) {
            $fails[$f['host_id'] . '|' . $f['ip']] = (int) $f['n'];
        }
        return array_map(static function ($r) use ($fails) {
            $internal = Ids::internalIp((string) $r['ip']);
            return ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'host' => $r['host'], 'at' => $r['at'], 'ip' => $r['ip'], 'user' => $r['username'],
                'port' => $r['port'] !== null ? (int) $r['port'] : null, 'method' => $r['method'], 'closed_at' => $r['closed_at'],
                'secs' => $r['closed_at'] !== null ? max(0, strtotime((string) $r['closed_at']) - strtotime((string) $r['at'])) : null,
                'internal' => $internal, 'cc' => $internal ? null : IpIntel::country((string) $r['ip']),
                'fails' => $fails[$r['host_id'] . '|' . $r['ip']] ?? 0];
        }, $rows);
    }
}
