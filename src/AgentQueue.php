<?php
declare(strict_types=1);

/**
 * Agent 1.69.0: hartslag tijdens een lange run en de wachtrij (store-and-forward).
 * - Hartslag ("beat"): duurt een run langer dan de tik, dan meldt de agent elke tik "ik ben bezig". RzSense telt dat als
 *   contact (de agent is niet stil), maar laat zijn gegevens staan tot het echte rapport komt. Een run die al een kwartier
 *   bezig is, telt niet meer: dan hangt er iets en mag de melding "agent stil" gewoon komen.
 * - Wachtrij ("backlog"): metingen van rapporten die niet aankwamen (RzSense of het netwerk even weg). Die komen als
 *   meetpunten in de grafieken van de CPU-, RAM- en diskchecks, op hun echte tijd; de status van nu verandert er niet door.
 *   Hetzelfde meetmoment telt maar één keer (het tijdstip is het kenmerk van een meting).
 */
final class AgentQueue
{
    /** Langer bezig dan dit: de hartslag telt niet meer als contact */
    public const BUSY_MAX = 900;
    /** Ouder dan dit wordt niet meer ingehaald */
    private const BACKLOG_MAX_AGE = 2 * 86400;

    /** Hartslag verwerken: true = als contact geteld */
    public static function beat(array $host, array $in): bool
    {
        $since = (int) ($in['busy_since'] ?? 0);
        if ($since <= 0 || $since > time() + 60 || time() - $since > self::BUSY_MAX) {
            q('UPDATE hosts SET agent_busy = NULL WHERE id = ?', [$host['id']]);
            return false;
        }
        q('UPDATE hosts SET agent_seen = ?, agent_busy = ? WHERE id = ?', [now(), date('Y-m-d H:i:s', $since), $host['id']]);
        return true;
    }

    /**
     * Ingehaalde metingen als meetpunten bij de checks van deze server (CPU, RAM, disk). @return int aantal punten
     * @param array<int, array> $items [{t, cpu, mem: {total, avail}, disks: [{mount, total, avail}]}]
     */
    public static function backlog(array $host, array $items): int
    {
        $checks = q("SELECT id, type, target, warn_threshold, crit_threshold FROM checks WHERE host_id = ? AND enabled = 1 AND type IN ('cpu', 'ram', 'disk')",
            [$host['id']])->fetchAll();
        if (!$checks || !$items) {
            return 0;
        }
        $tick = agent_tick(isset($host['agent_tick']) ? (int) $host['agent_tick'] : null);
        $n = 0;
        $oldest = null;
        foreach (array_slice($items, 0, 500) as $it) {
            $t = (int) ($it['t'] ?? 0);
            if ($t <= time() - self::BACKLOG_MAX_AGE || $t > time()) {
                continue;
            }
            $at = date('Y-m-d H:i:s', $t);
            foreach ($checks as $c) {
                $v = self::value($c, $it);
                if ($v === null) {
                    continue;
                }
                // Al een meting rond dit moment (bv. dit punt kwam eerder al binnen): niet dubbel
                if (q('SELECT 1 FROM results WHERE check_id = ? AND created_at BETWEEN ? - INTERVAL ? SECOND AND ? + INTERVAL ? SECOND LIMIT 1',
                    [$c['id'], $at, max(5, intdiv($tick, 3)), $at, max(5, intdiv($tick, 3))])->fetchColumn()) {
                    continue;
                }
                $st = Checks::threshold($v, $c);   // 1.224.3: dezelfde drempels als de check zelf (geen eigen kopie)
                q('INSERT INTO results (check_id, status, value, created_at) VALUES (?, ?, ?, ?)', [$c['id'], $st, $v, $at]);
                $n++;
                $oldest = min($oldest ?? $t, $t);
            }
        }
        // 1.224.3: ingehaalde metingen van uren die al samengevat zijn: die uren opnieuw uitrekenen (anders ontbreken ze
        // later in de grafieken van een maand of langer, juist het gat dat ze moesten vullen)
        if ($n && isset($oldest)) {
            $h = date('Y-m-d H:00:00', $oldest);
            q("UPDATE settings SET value = ? WHERE name = 'hourly_rolled' AND value > ?", [$h, $h]);
        }
        return $n;
    }

    /** Waarde van een check uit één ingehaalde meting (zoals de check hem zelf zou berekenen), of null */
    private static function value(array $c, array $it): ?float
    {
        if ($c['type'] === 'cpu') {
            return is_numeric($it['cpu'] ?? null) ? round((float) $it['cpu'], 1) : null;
        }
        if ($c['type'] === 'ram') {
            $tot = (float) ($it['mem']['total'] ?? 0);
            return $tot > 0 ? round(($tot - (float) ($it['mem']['avail'] ?? 0)) / $tot * 100, 1) : null;
        }
        $path = $c['target'] ?: '/';
        foreach ((array) ($it['disks'] ?? []) as $d) {
            if (($d['mount'] ?? '') === $path && (float) ($d['total'] ?? 0) > 0) {
                return round(((float) $d['total'] - (float) ($d['avail'] ?? 0)) / (float) $d['total'] * 100, 1);
            }
        }
        return null;
    }
}
