<?php
declare(strict_types=1);

/**
 * Hoe goed werkt RzSense zelf? (1.146.0)
 * - SLO's: hoe snel komt een melding na een storing (doel: binnen 5 minuten), en hoe lang waren agents stil.
 * - "Na de actie": werkt wat RzSense zelf doet ook echt? Zelf herstellen (hoe vaak lukt het), automatische blokkades
 *   (hoe vaak heeft iemand er een vroegtijdig opgeheven: waarschijnlijk onterecht) en slimme drempels (gaven ze daarna
 *   méér warnings dan ervoor?).
 * Uitkomsten van zelf herstellen (heal_log) en gaten in het contact met agents (agent_gaps) worden vanaf 1.146.0 bijgehouden.
 */
final class Quality
{
    /** Doel: een melding binnen zoveel minuten na het begin van een storing */
    public const NOTIFY_TARGET_MIN = 5;

    public static function recordHeal(int $hostId, int $checkId, bool $ok, string $msg): void
    {
        q('INSERT INTO heal_log (host_id, check_id, ok, msg, at) VALUES (?, ?, ?, ?, ?)', [$hostId, $checkId, $ok ? 1 : 0, mb_substr($msg, 0, 200), now()]);
    }

    /** Een rapport na een stilte langer dan de grens voor "agent stil": het gat bewaren */
    public static function recordAgentGap(array $host, int $now): void
    {
        $prev = $host['agent_seen'] ? strtotime((string) $host['agent_seen']) : 0;
        $limit = agent_stale_seconds(isset($host['agent_tick']) ? (int) $host['agent_tick'] : null);
        if ($prev > 0 && $now - $prev > $limit) {
            q('INSERT INTO agent_gaps (host_id, gap_from, gap_to) VALUES (?, ?, ?)', [$host['id'], date('Y-m-d H:i:s', $prev), date('Y-m-d H:i:s', $now)]);
        }
    }

    /**
     * SLO's over een periode (Y-m-d t/m Y-m-d).
     * Tijd tot melding: van de overgang naar critical tot de eerste melding (kanaal) voor die check. Storingen waarvoor
     * (volgens je meldregels) nooit een melding kwam, tellen niet mee. Let op: "melden na X minuten" telt hier mee.
     */
    public static function slo(string $from, string $to): array
    {
        $delays = [];
        foreach (q("SELECT s.check_id, s.created_at,
                           (SELECT MIN(a.created_at) FROM alerts a WHERE a.check_id = s.check_id AND a.kind = 'notify' AND a.status = 2
                              AND a.created_at >= s.created_at AND a.created_at < s.created_at + INTERVAL 1 DAY) AS sent
                    FROM status_changes s WHERE s.to_status = 2 AND s.created_at >= ? AND s.created_at < ? + INTERVAL 1 DAY
                    ORDER BY s.id DESC LIMIT 5000", [$from, $to]) as $r) {
            if ($r['sent']) {
                $delays[] = max(0, strtotime((string) $r['sent']) - strtotime((string) $r['created_at']));
            }
        }
        sort($delays);
        $n = count($delays);
        $within = count(array_filter($delays, static fn($d) => $d <= self::NOTIFY_TARGET_MIN * 60));
        $pick = static fn(float $p) => $n ? $delays[(int) min($n - 1, floor($p * ($n - 1) + 0.5))] : null;
        // Agents: hoe vaak en hoe lang stil
        $gaps = q('SELECT g.host_id, h.name, COUNT(*) AS n, SUM(TIMESTAMPDIFF(SECOND, g.gap_from, g.gap_to)) AS secs, MAX(TIMESTAMPDIFF(SECOND, g.gap_from, g.gap_to)) AS worst
                   FROM agent_gaps g JOIN hosts h ON h.id = g.host_id WHERE g.gap_to >= ? AND g.gap_from < ? + INTERVAL 1 DAY GROUP BY g.host_id, h.name ORDER BY secs DESC', [$from, $to])->fetchAll();
        // Nu nog stil (het gat is nog niet gesloten): telt mee vanaf het laatste contact (of het begin van de periode)
        $start = strtotime($from);
        $end = min(time(), strtotime($to) + 86400);
        foreach (q("SELECT id, name, agent_seen, agent_tick FROM hosts WHERE enabled = 1 AND mode = 'agent'") as $h) {
            $seen = $h['agent_seen'] ? strtotime((string) $h['agent_seen']) : null;
            if ($seen === null || time() - $seen <= agent_stale_seconds(isset($h['agent_tick']) ? (int) $h['agent_tick'] : null) || $seen >= $end) {
                continue;
            }
            $secs = $end - max($seen, $start);
            if ($secs <= 0) {
                continue;
            }
            $found = false;
            foreach ($gaps as &$g) {
                if ((int) $g['host_id'] === (int) $h['id']) {
                    $g['n'] = (int) $g['n'] + 1;
                    $g['secs'] = (int) $g['secs'] + $secs;
                    $g['worst'] = max((int) $g['worst'], $secs);
                    $found = true;
                }
            }
            unset($g);
            if (!$found) {
                $gaps[] = ['host_id' => (int) $h['id'], 'name' => $h['name'], 'n' => 1, 'secs' => $secs, 'worst' => $secs];
            }
        }
        usort($gaps, static fn($a, $b) => (int) $b['secs'] <=> (int) $a['secs']);
        $agents = (int) q("SELECT COUNT(*) FROM hosts WHERE enabled = 1 AND mode = 'agent'")->fetchColumn();
        $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
        $silent = array_sum(array_map(static fn($g) => (int) $g['secs'], $gaps));
        return [
            'notify' => ['n' => $n, 'within' => $within, 'pct' => $n ? round($within / $n * 100, 1) : null, 'p50' => $pick(0.5), 'p90' => $pick(0.9),
                'target_min' => self::NOTIFY_TARGET_MIN],
            'agents' => ['agents' => $agents, 'gaps' => array_sum(array_map(static fn($g) => (int) $g['n'], $gaps)), 'silent_s' => $silent,
                'coverage' => $agents ? round(max(0, 100 - $silent / ($agents * max(3600, $end - $start)) * 100), 2) : null,
                'hosts' => array_map(static fn($g) => ['host' => $g['name'], 'n' => (int) $g['n'], 'secs' => (int) $g['secs'], 'worst' => (int) $g['worst']], array_slice($gaps, 0, 8))],
        ];
    }

    /** Na de actie: zelf herstellen, automatische blokkades en slimme drempels, over de laatste $days dagen */
    public static function loops(int $days = 30): array
    {
        // Zelf herstellen per check
        $heal = q('SELECT l.check_id, c.name, h.name AS host, COUNT(*) AS n, SUM(l.ok) AS ok FROM heal_log l JOIN checks c ON c.id = l.check_id JOIN hosts h ON h.id = l.host_id
                   WHERE l.at >= NOW() - INTERVAL ? DAY GROUP BY l.check_id, c.name, h.name ORDER BY (COUNT(*) - SUM(l.ok)) DESC, n DESC LIMIT 10', [$days])->fetchAll();
        $hn = array_sum(array_map(static fn($r) => (int) $r['n'], $heal));
        $hok = array_sum(array_map(static fn($r) => (int) $r['ok'], $heal));
        // Automatische blokkades: vroegtijdig opgeheven (door iemand of via de whitelist) = waarschijnlijk onterecht
        $b = q('SELECT COUNT(*) AS n, SUM(removed_at IS NOT NULL AND (until IS NULL OR removed_at < until)) AS early FROM ids_blocks WHERE auto = 1 AND created_at >= NOW() - INTERVAL ? DAY', [$days])->fetch();
        $early = q('SELECT b.ip, h.name AS host, b.reason, b.removed_by, b.created_at FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                    WHERE b.auto = 1 AND b.created_at >= NOW() - INTERVAL ? DAY AND b.removed_at IS NOT NULL AND (b.until IS NULL OR b.removed_at < b.until)
                    ORDER BY b.created_at DESC LIMIT 8', [$days])->fetchAll();
        // Slimme drempels: warnings in de 14 dagen voor en na het aanzetten
        $smart = [];
        foreach (q("SELECT l.ref_id, MIN(l.at) AS at FROM change_log l WHERE l.kind = 'check' AND l.action = 'smart' AND l.at >= NOW() - INTERVAL 90 DAY
                    AND l.at <= NOW() - INTERVAL 3 DAY GROUP BY l.ref_id LIMIT 200") as $r) {
            $at = (string) $r['at'];
            $span = min(14, max(3, (int) floor((time() - strtotime($at)) / 86400)));
            $cnt = static fn(string $op) => (int) q("SELECT COUNT(*) FROM status_changes WHERE check_id = ? AND to_status IN (1, 2)
                AND created_at $op ? AND created_at " . ($op === '<' ? '>= ? - INTERVAL ? DAY' : '< ? + INTERVAL ? DAY'), [$r['ref_id'], $at, $at, $span])->fetchColumn();
            $before = $cnt('<');
            $after = $cnt('>=');
            if ($after > $before && $after >= 3) {
                $c = q('SELECT c.id, c.name, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$r['ref_id']])->fetch();
                if ($c) {
                    $smart[] = ['id' => (int) $c['id'], 'name' => $c['name'], 'host' => $c['host'], 'before' => $before, 'after' => $after, 'days' => $span, 'since' => $at];
                }
            }
        }
        usort($smart, static fn($a, $b) => ($b['after'] - $b['before']) <=> ($a['after'] - $a['before']));
        return [
            'days' => $days,
            'heal' => ['n' => $hn, 'ok' => $hok, 'pct' => $hn ? round($hok / $hn * 100, 1) : null,
                'checks' => array_map(static fn($r) => ['id' => (int) $r['check_id'], 'name' => $r['name'], 'host' => $r['host'], 'n' => (int) $r['n'], 'ok' => (int) $r['ok']], $heal)],
            'blocks' => ['n' => (int) ($b['n'] ?? 0), 'early' => (int) ($b['early'] ?? 0),
                'pct' => (int) ($b['n'] ?? 0) ? round((int) $b['early'] / (int) $b['n'] * 100, 1) : null, 'list' => $early],
            'smart' => array_slice($smart, 0, 8),
        ];
    }

    public static function purge(): void
    {
        q('DELETE FROM heal_log WHERE at < NOW() - INTERVAL 400 DAY');
        q('DELETE FROM agent_gaps WHERE gap_to < NOW() - INTERVAL 400 DAY');
    }
}
