<?php
declare(strict_types=1);

/**
 * Gezondheid van RzMon zelf (1.96.0), voor Instellingen → Systeem → Systeemcheck: grootte en groei van de database
 * (met een voorspelling wanneer de disk vol is), hoe lang een cron-ronde duurt, de traagste checks en PHP-fouten.
 * PHP-fouten (waarschuwingen en erger) komen samengevat per soort in app_errors (7 dagen).
 */
final class SelfCheck
{
    private static bool $inHandler = false;

    /** Foutafhandeling aanzetten (bootstrap): niets aan het normale gedrag veranderen, alleen bijhouden */
    public static function register(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
            if ($no & (E_WARNING | E_USER_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR) && error_reporting() & $no) {
                self::log($no === E_WARNING || $no === E_USER_WARNING ? 'warning' : 'error', $msg, $file, $line);
            }
            return false;   // PHP handelt de fout gewoon verder af
        });
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::log('fatal', (string) $e['message'], (string) $e['file'], (int) $e['line']);
            }
        });
    }

    public static function log(string $level, string $msg, string $file, int $line): void
    {
        if (self::$inHandler || !is_configured()) {
            return;
        }
        self::$inHandler = true;
        try {
            $file = str_replace((string) realpath(SM_ROOT) . '/', '', $file);
            $msg = mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 500);
            $url = PHP_SAPI === 'cli' ? 'cron' : mb_substr(strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . (isset($_GET['action']) ? '?action=' . $_GET['action'] : ''), 0, 200);
            q('INSERT INTO app_errors (hash, level, message, file, line, url, count, first_at, last_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
               ON DUPLICATE KEY UPDATE count = count + 1, last_at = NOW(), url = VALUES(url)',
                [sha1("$level|$file|$line|$msg"), $level, $msg, mb_substr($file, 0, 200), $line, $url]);
        } catch (Throwable) {
            // geen database: niets bijhouden
        } finally {
            self::$inHandler = false;
        }
    }

    /** Duur van deze cron-ronde bewaren (laatste 60) */
    public static function cronDone(float $seconds): void
    {
        $d = array_slice((array) (json_decode((string) q("SELECT value FROM settings WHERE name = 'cron_durations'")->fetchColumn(), true) ?: []), -59);
        $d[] = round($seconds, 1);
        save_setting('cron_durations', json_encode($d));
    }

    /** Eén keer per dag: grootte van de database vastleggen (30 dagen), en oude fouten opruimen */
    public static function daily(): void
    {
        if (setting('selfcheck_day', '') === date('Y-m-d')) {
            return;
        }
        save_setting('selfcheck_day', date('Y-m-d'));
        $hist = (array) (json_decode((string) setting('db_size_hist', ''), true) ?: []);
        $hist[date('Y-m-d')] = self::dbBytes();
        save_setting('db_size_hist', json_encode(array_slice($hist, -30, null, true)));
        q('DELETE FROM app_errors WHERE last_at < NOW() - INTERVAL 7 DAY');
    }

    private static function dbBytes(): int
    {
        return (int) q('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
    }

    public static function report(): array
    {
        // Database: grootte, grootste tabellen, groei per dag (over de bewaarde dagen) en ruimte op de disk
        $tables = q('SELECT table_name AS name, data_length + index_length AS bytes, table_rows AS `rows` FROM information_schema.TABLES
                     WHERE table_schema = DATABASE() ORDER BY bytes DESC LIMIT 6')->fetchAll();
        $size = self::dbBytes();
        $hist = (array) (json_decode((string) setting('db_size_hist', ''), true) ?: []);
        $growth = null;
        if (count($hist) >= 2) {
            $days = array_keys($hist);
            $span = max(1, (strtotime(end($days)) - strtotime($days[0])) / 86400);
            $growth = (int) round((end($hist) - reset($hist)) / $span);
        }
        $datadir = (string) (q('SELECT @@datadir')->fetchColumn() ?: '');
        $free = @disk_free_space(is_dir($datadir) ? $datadir : SM_ROOT);
        $full = $growth && $growth > 0 && $free ? (int) floor($free / $growth) : null;
        // Cron: duur van de laatste rondes, en of hij uitloopt (langer dan een minuut)
        $dur = array_map('floatval', (array) (json_decode((string) setting('cron_durations', ''), true) ?: []));
        $cron = $dur ? ['last' => end($dur), 'avg' => round(array_sum($dur) / count($dur), 1), 'max' => max($dur), 'n' => count($dur),
            'over' => count(array_filter($dur, static fn($d) => $d >= 55)), 'overlap_at' => setting('cron_overlap_at', '') ?: null] : null;
        $slow = q('SELECT c.id, c.name, c.type, c.run_ms, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id
                   WHERE c.enabled = 1 AND c.run_ms >= 100 ORDER BY c.run_ms DESC LIMIT 8')->fetchAll();
        $errors = q('SELECT level, message, file, line, url, count, first_at, last_at FROM app_errors WHERE last_at >= NOW() - INTERVAL 1 DAY ORDER BY last_at DESC LIMIT 20')->fetchAll();
        return ['db' => ['size' => $size, 'tables' => $tables, 'growth_day' => $growth, 'free' => $free === false ? null : (int) $free, 'full_days' => $full, 'days' => count($hist)],
            'cron' => $cron, 'slow' => $slow, 'errors' => $errors];
    }
}
