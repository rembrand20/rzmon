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
        // 1.119.0: 7 en 30 dagen uit de dagtotalen (stats_daily, elk uur bijgewerkt) plus vandaag uit de metingen; zo
        // hoeft niet elke keer de hele meetgeschiedenis doorzocht te worden. Zonder dagtotalen: zoals vroeger.
        $fresh = (int) setting('stats_daily_at', '0') > time() - 7200 && q("SELECT 1 FROM stats_daily WHERE metric = 'h_n' LIMIT 1")->fetchColumn();
        $pct = static fn($ok, $n) => (int) $n > 0 ? round((int) $ok / (int) $n * 100, 3) : null;
        if (!$fresh) {
            return self::uptimeFull($pct);
        }
        // Vandaag en de laatste 24 uur: per check via de index (check_id, created_at)
        $live = [];
        foreach (q("SELECT c.host_id, c.type = 'ping' AS is_ping,
                SUM(r.created_at >= NOW() - INTERVAL 1 DAY) AS n1, SUM(r.created_at >= NOW() - INTERVAL 1 DAY AND r.status <> 2) AS ok1,
                SUM(r.created_at >= CURDATE()) AS nt, SUM(r.created_at >= CURDATE() AND r.status <> 2) AS okt
            FROM checks c JOIN results r ON r.check_id = c.id AND r.created_at >= LEAST(NOW() - INTERVAL 1 DAY, CURDATE())
            WHERE c.enabled = 1 AND c.type IN ('ping', 'tcp', 'http') AND c.reverse_ok IS NULL
            GROUP BY c.host_id, is_ping") as $r) {
            $live[(int) $r['host_id']][(int) $r['is_ping']] = $r;
        }
        $days = [];
        foreach (q("SELECT ref, metric, SUM(CASE WHEN day >= CURDATE() - INTERVAL 6 DAY THEN v ELSE 0 END) AS v7, SUM(v) AS v30 FROM stats_daily
                    WHERE metric IN ('h_n', 'h_ok') AND day >= CURDATE() - INTERVAL 29 DAY AND day < CURDATE() GROUP BY ref, metric") as $r) {
            $days[(int) $r['ref']][$r['metric']] = [(float) $r['v7'], (float) $r['v30']];
        }
        $out = [];
        foreach (array_unique(array_merge(array_keys($live), array_keys($days))) as $hid) {
            $l = $live[$hid][1] ?? $live[$hid][0] ?? ['n1' => 0, 'ok1' => 0, 'nt' => 0, 'okt' => 0];   // ping heeft voorrang
            $dn = $days[$hid]['h_n'] ?? [0, 0];
            $dk = $days[$hid]['h_ok'] ?? [0, 0];
            $out[$hid] = ['h24' => $pct($l['ok1'], $l['n1']), 'd7' => $pct($dk[0] + (int) $l['okt'], $dn[0] + (int) $l['nt']), 'd30' => $pct($dk[1] + (int) $l['okt'], $dn[1] + (int) $l['nt'])];
        }
        return $out;
    }

    /** Zoals vóór 1.119.0: alles uit de metingen (als er nog geen dagtotalen zijn) */
    private static function uptimeFull(Closure $pct): array
    {
        $rows = q("SELECT c.host_id, c.type = 'ping' AS is_ping,
                SUM(r.created_at >= NOW() - INTERVAL 1 DAY) AS n1, SUM(r.created_at >= NOW() - INTERVAL 1 DAY AND r.status <> 2) AS ok1,
                SUM(r.created_at >= NOW() - INTERVAL 7 DAY) AS n7, SUM(r.created_at >= NOW() - INTERVAL 7 DAY AND r.status <> 2) AS ok7,
                COUNT(*) AS n30, SUM(r.status <> 2) AS ok30
            FROM checks c JOIN results r ON r.check_id = c.id AND r.created_at >= NOW() - INTERVAL 30 DAY
            WHERE c.enabled = 1 AND c.type IN ('ping', 'tcp', 'http') AND c.reverse_ok IS NULL
            GROUP BY c.host_id, is_ping")->fetchAll();
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['host_id']][(int) $r['is_ping']] = $r;
        }
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
        // Per check via de index (check_id, created_at), niet door de hele tabel (1.119.0)
        $rows = q('SELECT r.check_id, FLOOR((UNIX_TIMESTAMP(r.created_at) - ?) / 1800) AS b, AVG(r.value) AS v,
                          MAX(CASE r.status WHEN 2 THEN 3 WHEN 1 THEN 2 WHEN 3 THEN 1 ELSE 0 END) AS r
                   FROM checks c JOIN results r ON r.check_id = c.id AND r.created_at >= FROM_UNIXTIME(?) GROUP BY r.check_id, b', [$start, $start])->fetchAll();
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
        $slope = self::diskTrend($checkId);
        if ($slope === null) {
            return null;
        }
        // Pas vanaf 0,05% per dag spreken we van "groeien"; anders is het ruis
        $days = $slope > 0.05 && $current < 100 ? (100 - $current) / $slope : null;
        return ['per_day' => round($slope, 3), 'days_to_full' => $days !== null ? round($days, 1) : null];
    }

    /**
     * Groei per dag over 7 dagen (lineaire regressie), of null bij te weinig geschiedenis. 1.131.0: hooguit eens per half
     * uur opnieuw uit de meetwaarden (ongeveer 10.000 per disk); daartussen de bewaarde waarde. De trend verandert in een
     * half uur nauwelijks, en zo leest niet elke beoordeling een week aan metingen.
     */
    private static function diskTrend(int $checkId): ?float
    {
        $c = q('SELECT trend_per_day, trend_at FROM checks WHERE id = ?', [$checkId])->fetch();
        if ($c && $c['trend_at'] !== null && strtotime((string) $c['trend_at']) > time() - 1800) {
            return $c['trend_per_day'] !== null ? (float) $c['trend_per_day'] : null;
        }
        $r = q('SELECT COUNT(*) AS n, SUM(x) AS sx, SUM(value) AS sy, SUM(x * x) AS sxx, SUM(x * value) AS sxy, MIN(x) AS first
                FROM (SELECT (UNIX_TIMESTAMP(created_at) - UNIX_TIMESTAMP()) / 86400 AS x, value FROM results
                      WHERE check_id = ? AND value IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY) t', [$checkId])->fetch();
        $n = (int) $r['n'];
        $den = $n * (float) $r['sxx'] - (float) $r['sx'] ** 2;
        $slope = $n < 10 || (float) $r['first'] > -1 || abs($den) < 1e-9 ? null : ($n * (float) $r['sxy'] - (float) $r['sx'] * (float) $r['sy']) / $den;
        if ($c) {
            q('UPDATE checks SET trend_per_day = ?, trend_at = NOW() WHERE id = ?', [$slope, $checkId]);
        }
        return $slope;
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
        $when = $days < 1 ? t('binnen een dag') : t('over ~{n} dagen', ['n' => (int) round($days)]);
        $rate = '(+' . I18n::dec((string) round($f['per_day'], 2)) . '%/' . t('dag') . ')';
        $limit = (int) setting('disk_forecast_days', 7);
        if ($limit > 0 && $days <= $limit && $res['status'] === STATUS_OK) {
            $res['status'] = STATUS_WARN;
            $res['message'] = t('Raakt {when} vol', ['when' => $when]) . " $rate: " . $res['message'];
        } else {
            $res['message'] .= ' · ' . t('vol {when}', ['when' => $when]) . " $rate";
        }
        return $res;
    }
}
