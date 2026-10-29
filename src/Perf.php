<?php
declare(strict_types=1);

/**
 * Snelheidsmeter (1.119.0): hoe lang verzoeken aan RzMon (interface, API, agents) en de onderdelen van een cron-ronde
 * duren. Per uur en per naam opgeteld in perf_stats (één regel per verzoek bijwerken; twee dagen bewaard), zodat je bij
 * Instellingen → Overzicht → Gezondheid ziet waar de tijd zit. Uit te zetten met perf_on.
 */
final class Perf
{
    private static float $lap = 0.0;
    private static ?float $start = null;
    private static int $bytes = 0;
    /** @var array<string, float> onderdelen van een agentrapport (1.129.0) */
    private static array $parts = [];
    private static ?float $partAt = null;

    public static function on(): bool
    {
        try {
            return setting('perf_on', '1') === '1';
        } catch (Throwable) {
            return false;
        }
    }

    /** Begin van een verzoek: aan het eind (ook na exit) de duur vastleggen. $kind: a = interface/API, g = agent */
    public static function request(string $kind, string $name): void
    {
        self::$start = microtime(true);
        register_shutdown_function(static function () use ($kind, $name): void {
            if (self::$start !== null && self::on()) {
                self::add($kind, $name, (microtime(true) - self::$start) * 1000, self::$bytes);
                foreach (self::$parts as $part => $ms) {
                    self::add('r', $part, $ms, 0);
                }
            }
        });
    }

    /**
     * Agentrapport (1.129.0): de tijd sinds het vorige onderdeel op naam van dit onderdeel zetten, zodat je bij
     * Gezondheid ziet wat RzMon met een rapport zo lang bezighoudt (IDS, endpoint-bewaking, checks …)
     */
    public static function part(string $name): void
    {
        $now = microtime(true);
        $from = self::$partAt ?? self::$start;
        if ($from !== null) {
            self::$parts[$name] = (self::$parts[$name] ?? 0.0) + ($now - $from) * 1000;
        }
        self::$partAt = $now;
    }

    /** Grootte van het antwoord (voor de tabel) */
    public static function bytes(int $n): void
    {
        self::$bytes = $n;
    }

    /** Cron: de tijd sinds de vorige lap op naam van dit onderdeel zetten */
    public static function lap(string $name): void
    {
        $now = microtime(true);
        if (self::$lap > 0 && self::on()) {
            self::add('c', $name, ($now - self::$lap) * 1000, 0);
        }
        self::$lap = $now;
    }

    private static function add(string $kind, string $name, float $ms, int $bytes): void
    {
        try {
            q('INSERT INTO perf_stats (hour, kind, name, n, total_ms, max_ms, bytes) VALUES (?, ?, ?, 1, ?, ?, ?)
               ON DUPLICATE KEY UPDATE n = n + 1, total_ms = total_ms + VALUES(total_ms), max_ms = GREATEST(max_ms, VALUES(max_ms)), bytes = bytes + VALUES(bytes)',
                [date('Y-m-d H:00:00'), $kind, mb_substr(preg_replace('/[^a-z0-9_:.-]/i', '', $name) ?: '?', 0, 40), (int) round($ms), (int) round($ms), $bytes]);
        } catch (Throwable) {
            // tabel nog niet aangemaakt (vlak na een update): niet meten
        }
    }

    public static function purge(): void
    {
        q('DELETE FROM perf_stats WHERE hour < NOW() - INTERVAL 2 DAY');
    }

    /** Overzicht van de laatste 24 uur voor de systeemcheck */
    public static function report(): array
    {
        $rows = static fn(string $kind, int $limit) => array_map(static fn($r) => ['name' => $r['name'], 'n' => (int) $r['n'], 'avg' => (int) round($r['total'] / max(1, $r['n'])),
            'max' => (int) $r['max'], 'total' => (int) $r['total'], 'kb' => (int) round($r['bytes'] / max(1, $r['n']) / 1024)],
            q('SELECT name, SUM(n) AS n, SUM(total_ms) AS total, MAX(max_ms) AS max, SUM(bytes) AS bytes FROM perf_stats
               WHERE kind = ? AND hour >= NOW() - INTERVAL 24 HOUR GROUP BY name ORDER BY total DESC LIMIT ' . $limit, [$kind])->fetchAll());
        $runs = max(1, (int) q("SELECT SUM(n) FROM perf_stats WHERE kind = 'c' AND name = 'checks' AND hour >= NOW() - INTERVAL 24 HOUR")->fetchColumn());
        $cron = array_map(static fn($r) => $r + ['per_run' => (int) round($r['total'] / $runs)], $rows('c', 20));
        $op = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        return [
            'on' => self::on(),
            'api' => $rows('a', 12),
            'agent' => $rows('g', 6),
            'parts' => $rows('r', 20),
            'cron' => $cron,
            'opcache' => is_array($op) ? ['on' => (bool) ($op['opcache_enabled'] ?? false), 'hit' => round((float) ($op['opcache_statistics']['opcache_hit_rate'] ?? 0), 1),
                'mem' => (int) ($op['memory_usage']['used_memory'] ?? 0), 'full' => (bool) ($op['cache_full'] ?? false)] : ['on' => false],
            'tables' => q('SELECT table_name AS name, data_length + index_length AS bytes, data_free AS free, table_rows AS `rows` FROM information_schema.TABLES
                           WHERE table_schema = DATABASE() ORDER BY bytes DESC LIMIT 12')->fetchAll(),
        ];
    }

    /**
     * Database optimaliseren: de grootste tabellen opnieuw opbouwen (ruimte van verwijderde rijen terug, indexen weer
     * compact). Alleen tabellen van meer dan 1 MB met ongebruikte ruimte; duurt even, dus niet tijdens een cron-ronde.
     * @return array<int, array{name: string, before: int, after: int}>
     */
    public static function optimize(): array
    {
        @set_time_limit(600);
        $out = [];
        foreach (q('SELECT table_name AS name, data_length + index_length AS bytes, data_free AS free FROM information_schema.TABLES
                    WHERE table_schema = DATABASE() AND data_length + index_length > 1048576 ORDER BY bytes DESC LIMIT 15')->fetchAll() as $t) {
            if (!preg_match('/^[a-z0-9_]+$/', (string) $t['name'])) {
                continue;
            }
            db()->query('OPTIMIZE TABLE `' . $t['name'] . '`')->fetchAll();
            $after = (int) q('SELECT data_length + index_length FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?', [$t['name']])->fetchColumn();
            $out[] = ['name' => (string) $t['name'], 'before' => (int) $t['bytes'], 'after' => $after];
        }
        save_setting('perf_optimized_at', now());
        return $out;
    }
}
