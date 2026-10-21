<?php
declare(strict_types=1);

/** Statistieken uit de meetgeschiedenis: beschikbaarheid, trends en diskvoorspelling. */
final class Stats
{
    /**
     * Beschikbaarheid per server over 24 uur, 7 en 30 dagen (percentage metingen dat niet CRITICAL was).
     * Gebaseerd op de ping-checks; heeft een server geen ping, dan op TCP- en HTTP-checks.
     * @return array<int, array{h24: ?float, d7: ?float, d30: ?float}>
     */
    public static function uptime(): array
    {
        $rows = q("SELECT c.host_id, c.type = 'ping' AS is_ping,
                SUM(r.created_at >= NOW() - INTERVAL 1 DAY) AS n1, SUM(r.created_at >= NOW() - INTERVAL 1 DAY AND r.status <> 2) AS ok1,
                SUM(r.created_at >= NOW() - INTERVAL 7 DAY) AS n7, SUM(r.created_at >= NOW() - INTERVAL 7 DAY AND r.status <> 2) AS ok7,
                COUNT(*) AS n30, SUM(r.status <> 2) AS ok30
            FROM results r JOIN checks c ON c.id = r.check_id
            WHERE c.enabled = 1 AND c.type IN ('ping', 'tcp', 'http') AND c.reverse_ok IS NULL AND r.created_at >= NOW() - INTERVAL 30 DAY
            GROUP BY c.host_id, is_ping")->fetchAll();
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['host_id']][(int) $r['is_ping']] = $r;
        }
        $pct = static fn($ok, $n) => (int) $n > 0 ? round((int) $ok / (int) $n * 100, 3) : null;
        $out = [];
        foreach ($by as $hid => $kinds) {
            $r = $kinds[1] ?? $kinds[0];   // ping heeft voorrang
            $out[$hid] = ['h24' => $pct($r['ok1'], $r['n1']), 'd7' => $pct($r['ok7'], $r['n7']), 'd30' => $pct($r['ok30'], $r['n30'])];
        }
        return $out;
    }

    /**
     * Verloop per check over de laatste 24 uur in 48 blokjes van een half uur (voor de minigrafieken).
     * @return array<int, array<int, array{0: ?float, 1: int}|null>> per check: [gemiddelde waarde, slechtste status] of null
     */
    public static function sparks(): array
    {
        $start = time() - 86400;
        $rows = q('SELECT check_id, FLOOR((UNIX_TIMESTAMP(created_at) - ?) / 1800) AS b, AVG(value) AS v,
                          MAX(CASE status WHEN 2 THEN 3 WHEN 1 THEN 2 WHEN 3 THEN 1 ELSE 0 END) AS r
                   FROM results WHERE created_at >= FROM_UNIXTIME(?) GROUP BY check_id, b', [$start, $start])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $b = (int) $r['b'];
            if ($b < 0 || $b > 47) {
                continue;
            }
            $out[(int) $r['check_id']] ??= array_fill(0, 48, null);
            $out[(int) $r['check_id']][$b] = [$r['v'] !== null ? round((float) $r['v'], 2) : null, [0, 3, 1, 2][(int) $r['r']]];
        }
        return $out;
    }

    /**
     * Trend van een disk over de laatste 7 dagen (lineaire regressie).
     * @return array{per_day: float, days_to_full: ?float}|null null als er te weinig geschiedenis is (< 1 dag)
     */
    public static function diskForecast(int $checkId, float $current): ?array
    {
        $r = q('SELECT COUNT(*) AS n, SUM(x) AS sx, SUM(value) AS sy, SUM(x * x) AS sxx, SUM(x * value) AS sxy, MIN(x) AS first
                FROM (SELECT (UNIX_TIMESTAMP(created_at) - UNIX_TIMESTAMP()) / 86400 AS x, value FROM results
                      WHERE check_id = ? AND value IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY) t', [$checkId])->fetch();
        $n = (int) $r['n'];
        if ($n < 10 || (float) $r['first'] > -1) {
            return null;
        }
        $den = $n * (float) $r['sxx'] - (float) $r['sx'] ** 2;
        if (abs($den) < 1e-9) {
            return null;
        }
        $slope = ($n * (float) $r['sxy'] - (float) $r['sx'] * (float) $r['sy']) / $den;
        // Pas vanaf 0,05% per dag spreken we van "groeien"; anders is het ruis
        $days = $slope > 0.05 && $current < 100 ? (100 - $current) / $slope : null;
        return ['per_day' => round($slope, 3), 'days_to_full' => $days !== null ? round($days, 1) : null];
    }

    /** Diskvoorspelling in het checkresultaat verwerken: tekst erbij en eventueel WARNING. */
    public static function applyDiskForecast(array $check, array $res): array
    {
        if ($res['value'] === null || $res['status'] === STATUS_UNKNOWN) {
            return $res;
        }
        $f = self::diskForecast((int) $check['id'], (float) $res['value']);
        if (!$f || $f['days_to_full'] === null || $f['days_to_full'] > 365) {
            return $res;
        }
        $days = $f['days_to_full'];
        $when = $days < 1 ? 'binnen een dag' : 'over ~' . (int) round($days) . ' dagen';
        $rate = sprintf('(+%s%%/dag)', str_replace('.', ',', (string) round($f['per_day'], 2)));
        $limit = (int) setting('disk_forecast_days', 7);
        if ($limit > 0 && $days <= $limit && $res['status'] === STATUS_OK) {
            $res['status'] = STATUS_WARN;
            $res['message'] = "Raakt $when vol $rate: " . $res['message'];
        } else {
            $res['message'] .= " · vol $when $rate";
        }
        return $res;
    }
}
