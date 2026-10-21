<?php
declare(strict_types=1);

/**
 * Algehele gezondheid van het netwerk: één toestand, een score van 0–100% en de tellers.
 * Gebruikt door het dashboard (via de state-API), MQTT (sensor "Algehele status") en de REST API.
 *
 *   ok        🟢 Alles in orde   – geen open problemen
 *   attention 🟠 Aandacht nodig  – alleen warnings/onbekend, of problemen die bevestigd of in onderhoud zijn
 *   outage    🔴 Storing         – een onbevestigde critical of een onbereikbare server
 *
 * Score: gemiddelde over de servers. Per server 100 min strafpunten per actieve storing
 * (critical 25, warning 10, onbekend 5; bevestigd de helft), een onbereikbare server telt als 0
 * (bevestigd 50). Servers die helemaal in onderhoud staan tellen niet mee.
 */
final class Health
{
    public const LABELS = ['ok' => 'Alles in orde', 'attention' => 'Aandacht nodig', 'outage' => 'Storing'];
    private const PENALTY = [STATUS_OK => 0, STATUS_WARN => 10, STATUS_CRIT => 25, STATUS_UNKNOWN => 5];

    /** @param array<int, array>|null $uptime Stats::uptime() voor de beschikbaarheid over 24 uur (optioneel) */
    public static function current(?array $uptime = null): array
    {
        $hosts = q('SELECT id, name, mode, agent_seen FROM hosts WHERE enabled = 1 ORDER BY name')->fetchAll();
        $checks = q('SELECT c.id, c.host_id, c.name, c.type, c.status, c.acked_at, c.problem_since, c.flapping_since, c.suppressed_by,
                            c.maintenance_until, c.maintenance_reason, c.maintenance_auto,
                            h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                     FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND h.enabled = 1')->fetchAll();
        foreach ($checks as &$c) {
            $c['in_maint'] = Monitor::maintenanceFor($c) !== null;
        }
        unset($c);
        return self::compute($hosts, $checks, $uptime);
    }

    /**
     * @param array $hosts  ingeschakelde servers (id, name, mode, agent_seen)
     * @param array $checks ingeschakelde checks met in_maint (bool)
     */
    public static function compute(array $hosts, array $checks, ?array $uptime = null): array
    {
        $byHost = [];
        foreach ($checks as $c) {
            $byHost[(int) $c['host_id']][] = $c;
        }
        $stale = time() - (int) setting('agent_stale_minutes', 5) * 60;
        $counts = ['ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0, 'maintenance' => 0];
        $keys = [STATUS_OK => 'ok', STATUS_WARN => 'warning', STATUS_CRIT => 'critical', STATUS_UNKNOWN => 'unknown'];
        $state = 'ok';
        $raise = static function (string $to) use (&$state): void {
            if ($to === 'outage' || $state === 'ok') {
                $state = $to;
            }
        };
        $scores = [];
        $down = [];
        $silent = [];
        $open = 0;
        $oldest = null;
        $flapping = 0;
        $hostsTotal = 0;

        foreach ($hosts as $h) {
            $hid = (int) $h['id'];
            $hc = $byHost[$hid] ?? [];
            if (!$hc) {
                continue;
            }
            $hostsTotal++;
            $active = array_filter($hc, fn($c) => !$c['in_maint']);
            foreach ($hc as $c) {
                if ($c['in_maint']) {
                    $counts['maintenance']++;
                    if ((int) $c['status'] !== STATUS_OK) {
                        $raise('attention');
                    }
                }
            }
            if (!$active) {
                continue;   // helemaal in onderhoud: telt niet mee in de score
            }
            // Onbereikbaar: ping CRITICAL, achter een uitgevallen server, of de agent rapporteert niet meer
            $agentSilent = $h['mode'] === 'agent' && ($h['agent_seen'] === null || strtotime($h['agent_seen']) < $stale);
            $reason = $agentSilent ? 'agent' : null;
            $acked = true;
            foreach ($active as $c) {
                if ($c['suppressed_by'] !== null || ($c['type'] === 'ping' && (int) $c['status'] === STATUS_CRIT)) {
                    $reason ??= $c['suppressed_by'] !== null ? 'parent' : 'ping';
                    $acked = $acked && $c['acked_at'] !== null;
                }
            }
            $unreachable = $reason !== null;
            if ($agentSilent) {
                $silent[] = ['id' => $hid, 'name' => $h['name']];
                $acked = $acked && (bool) array_filter($active, fn($c) => (int) $c['status'] !== STATUS_OK && $c['acked_at'] !== null);
            }

            $score = 100;
            foreach ($active as $c) {
                $st = (int) $c['status'];
                $counts[$keys[$st] ?? 'unknown']++;
                $flapping += $c['flapping_since'] !== null ? 1 : 0;
                if ($st === STATUS_OK) {
                    continue;
                }
                $isAcked = $c['acked_at'] !== null;
                $score -= (self::PENALTY[$st] ?? 5) * ($isAcked ? .5 : 1);
                $raise($st === STATUS_CRIT && !$isAcked ? 'outage' : 'attention');
                if (!$isAcked) {
                    $open++;
                    $since = $c['problem_since'] ?: null;
                    if ($since && ($oldest === null || $since < $oldest['since'])) {
                        $oldest = ['host_id' => $hid, 'host' => $h['name'], 'check_id' => (int) $c['id'], 'check' => $c['name'],
                            'status' => $st, 'since' => $since];
                    }
                }
            }
            if ($unreachable) {
                $down[] = ['id' => $hid, 'name' => $h['name'], 'reason' => $reason, 'acknowledged' => $acked];   // reason: ping, agent of parent
                $score = $acked ? min($score, 50) : 0;
                $raise($acked ? 'attention' : 'outage');
            }
            $scores[] = max(0, $score);
        }

        $avail = null;
        if ($uptime) {
            $vals = array_filter(array_map(fn($h) => $uptime[(int) $h['id']]['h24'] ?? null, $hosts), fn($v) => $v !== null);
            $avail = $vals ? round(array_sum($vals) / count($vals), 2) : null;
        }
        $active = $counts['ok'] + $counts['warning'] + $counts['critical'] + $counts['unknown'];
        return [
            'state' => $state,
            'label' => self::LABELS[$state],
            'score' => $scores ? (int) round(array_sum($scores) / count($scores)) : 100,
            'counts' => $counts,
            'checks_total' => $active,
            'checks_ok' => $counts['ok'],
            'hosts_total' => $hostsTotal,
            'hosts_up' => $hostsTotal - count($down),
            'down' => $down,
            'agents_silent' => $silent,
            'open_problems' => $open,
            'oldest_problem' => $oldest,
            'flapping' => $flapping,
            'availability_24h' => $avail,
            'quiet_until' => ($q = NotifyRules::quietUntil(NotifyRules::quietGlobal())) ? date('Y-m-d H:i:s', $q) : null,   // stille uren (algemeen)
        ];
    }
}
