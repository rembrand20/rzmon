<?php
declare(strict_types=1);

/**
 * Bewaartermijnen (1.136.0): elke meting zo lang als *Historie bewaren* (retention_days, standaard 30 dagen), daarna
 * per uur (results_hourly, standaard 180 dagen) en per dag (stats_daily van Analyse, standaard 2 jaar).
 * Een ruwe meting gaat pas weg als zijn uur in results_hourly staat (hourly_rolled = eerste uur dat nog niet klaar is).
 * Daarnaast elke dag de grootte van de database, om te melden als die ongewoon hard groeit.
 */
final class Retention
{
    public const HOURLY_DAYS = 180;
    public const DAILY_DAYS = 730;
    /** Zoveel dagen aan uurgemiddelden per keer (de eerste keer is er misschien een maand in te halen) */
    private const DAYS_PER_RUN = 7;

    public static function rawDays(): int
    {
        return max(1, (int) setting('retention_days', 30));
    }

    public static function hourlyDays(): int
    {
        return max(7, min(3650, (int) setting('hourly_days', (string) self::HOURLY_DAYS)));
    }

    public static function dailyDays(): int
    {
        return max(30, min(3650, (int) setting('daily_days', (string) self::DAILY_DAYS)));
    }

    /** Uurgemiddelden bijwerken tot het huidige (nog lopende) uur. @return int aantal verwerkte uren */
    public static function rollup(): int
    {
        $end = date('Y-m-d H:00:00');
        $done = (string) setting('hourly_rolled', '');
        if ($done === '') {
            $min = q('SELECT MIN(created_at) FROM results')->fetchColumn();
            $done = $min ? date('Y-m-d H:00:00', strtotime((string) $min)) : $end;
        }
        $hours = 0;
        for ($i = 0; $done < $end && $i < self::DAYS_PER_RUN; $i++) {
            $to = min(date('Y-m-d H:00:00', strtotime($done) + 86400), $end);
            // Via checks → results: zo gebruikt MariaDB de index (check_id, created_at) per check
            q("INSERT INTO results_hourly (check_id, hour, n, n_warn, n_crit, v_avg, v_min, v_max)
               SELECT r.check_id, DATE_FORMAT(r.created_at, '%Y-%m-%d %H:00:00') AS h, COUNT(*), SUM(r.status = 1), SUM(r.status = 2), AVG(r.value), MIN(r.value), MAX(r.value)
               FROM checks c JOIN results r ON r.check_id = c.id AND r.created_at >= ? AND r.created_at < ?
               GROUP BY r.check_id, h
               ON DUPLICATE KEY UPDATE n = VALUES(n), n_warn = VALUES(n_warn), n_crit = VALUES(n_crit), v_avg = VALUES(v_avg), v_min = VALUES(v_min), v_max = VALUES(v_max)",
                [$done, $to]);
            $hours += (int) ((strtotime($to) - strtotime($done)) / 3600);
            $done = $to;
            save_setting('hourly_rolled', $done);
        }
        return $hours;
    }

    /** Ruwe metingen (alleen als het uur al samengevat is), uurgemiddelden en daggegevens ouder dan ingesteld weg */
    public static function purge(): void
    {
        $cut = date('Y-m-d H:i:s', time() - self::rawDays() * 86400);
        $rolled = (string) setting('hourly_rolled', '');
        if ($rolled !== '' && $rolled < $cut) {
            $cut = $rolled;   // nog niet samengevat: nog niet weggooien
        }
        if ($rolled !== '') {
            q('DELETE FROM results WHERE created_at < ?', [$cut]);
        }
        q('DELETE FROM results_hourly WHERE hour < NOW() - INTERVAL ? DAY', [self::hourlyDays()]);
        q('DELETE FROM stats_daily WHERE day < CURDATE() - INTERVAL ? DAY', [self::dailyDays()]);
        // 1.171.0: AI-adviezen 90 dagen, en herstelacties uit een advies die niet meer gelden
        q('DELETE FROM ai_advice WHERE created_at < NOW() - INTERVAL 90 DAY');
        q('UPDATE checks SET ai_exec = NULL, ai_exec_at = NULL WHERE ai_exec IS NOT NULL AND ai_exec_at < NOW() - INTERVAL 1 HOUR');
    }

    /** Eén keer per dag de grootte van de database onthouden (30 dagen) */
    public static function track(): void
    {
        $hist = json_decode((string) setting('db_size_hist', ''), true) ?: [];
        $today = date('Y-m-d');
        if (isset($hist[$today])) {
            return;
        }
        $hist[$today] = self::dbSize();
        ksort($hist);
        save_setting('db_size_hist', json_encode(array_slice($hist, -30, null, true)));
    }

    public static function dbSize(): int
    {
        return (int) q('SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
    }

    /**
     * Groei van de laatste 7 dagen: [nu, groei per dag, ongewoon]. Ongewoon = meer dan 30% erbij in een week en minstens
     * 100 MB, zodat een kleine database die normaal vult niet meteen een melding geeft. Null zonder een week aan gegevens.
     */
    public static function growth(): ?array
    {
        $hist = json_decode((string) setting('db_size_hist', ''), true) ?: [];
        if (count($hist) < 2) {
            return null;
        }
        $days = array_keys($hist);
        $last = end($days);
        $from = null;
        foreach ($days as $d) {
            if (strtotime($d) >= strtotime($last) - 7 * 86400) {
                $from = $d;
                break;
            }
        }
        $span = max(1, (int) round((strtotime($last) - strtotime((string) $from)) / 86400));
        $now = (int) $hist[$last];
        $grow = $now - (int) $hist[$from];
        return ['now' => $now, 'per_day' => (int) round($grow / $span), 'days' => $span,
            'unusual' => $span >= 3 && $grow > 100 * 1048576 && $grow > 0.3 * (int) $hist[$from]];
    }
}
