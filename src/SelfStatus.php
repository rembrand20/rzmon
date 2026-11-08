<?php
declare(strict_types=1);

/**
 * De RzSense-kern (1.219.0): hoe gaat het met de server waar RzSense zelf op draait?
 * CPU, geheugen, disk (met voorspelling), database, cron-ronde en fouten, plus één oordeel.
 * Draait er een agent op dezelfde server, dan komen CPU en geheugen van de agent (nauwkeuriger, zeker in een container).
 * Alleen lezen: /proc, de disk van de database en wat RzSense al bijhoudt. Er wordt niets uitgevoerd.
 */
final class SelfStatus
{
    public static function get(): array
    {
        $host = (string) (gethostname() ?: '?');
        $agent = self::agentHost($host);
        $box = self::inContainer();
        // CPU en geheugen: van de agent als die er is en vers is, anders zelf uit /proc
        $cpu = $mem = null;
        $src = 'self';
        if ($agent) {
            $c = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'cpu'", [$agent['id']])->fetchColumn(), true);
            $m = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'mem'", [$agent['id']])->fetchColumn(), true);
            if (isset($c['pct'])) {
                $cpu = ['pct' => round(max(0, min(100, (float) $c['pct'])), 1), 'load' => array_map('floatval', array_slice((array) ($c['load'] ?? []), 0, 3)), 'cores' => (int) ($c['cores'] ?? 0)];
            }
            if (!empty($m['total'])) {
                $mem = ['total' => (float) $m['total'], 'used' => (float) $m['total'] - (float) ($m['avail'] ?? 0),
                    'swap' => !empty($m['swap_total']) ? round(((float) $m['swap_total'] - (float) ($m['swap_free'] ?? 0)) / (float) $m['swap_total'] * 100) : null];
            }
            if ($cpu || $mem) {
                $src = 'agent';
            }
        }
        $cpu ??= self::procCpu();
        $mem ??= self::procMem();
        if ($mem) {
            $mem['pct'] = round($mem['used'] / max(1, $mem['total']) * 100, 1);
        }
        // Disk waar de database staat (anders waar RzSense staat), met groei per dag uit een dagelijkse meting
        $dir = (string) (q('SELECT @@datadir')->fetchColumn() ?: '');
        $dir = is_dir($dir) ? $dir : SM_ROOT;
        $total = @disk_total_space($dir);
        $free = @disk_free_space($dir);
        $disk = null;
        if ($total && $free !== false) {
            $used = $total - $free;
            $hist = (array) (json_decode((string) setting('self_disk_hist', ''), true) ?: []);
            $today = date('Y-m-d');
            if (!isset($hist[$today])) {
                $hist[$today] = (int) $used;
                $hist = array_slice($hist, -15, null, true);
                save_setting('self_disk_hist', (string) json_encode($hist));
            }
            $growth = null;
            if (count($hist) >= 3) {
                $days = array_keys($hist);
                $span = max(1, (strtotime((string) end($days)) - strtotime((string) $days[0])) / 86400);
                $growth = (int) round(((int) end($hist) - (int) reset($hist)) / $span);
            }
            $disk = ['pct' => round($used / $total * 100, 1), 'used' => (int) $used, 'total' => (int) $total, 'growth_day' => $growth,
                'full_days' => $growth && $growth > 0 ? (int) floor($free / $growth) : null, 'path' => $dir === SM_ROOT ? '/' : rtrim($dir, '/')];
        }
        // Database
        $dbSize = (int) q('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
        $dbHist = (array) (json_decode((string) setting('db_size_hist', ''), true) ?: []);
        $dbGrowth = null;
        if (count($dbHist) >= 2) {
            $days = array_keys($dbHist);
            $span = max(1, (strtotime((string) end($days)) - strtotime((string) $days[0])) / 86400);
            $dbGrowth = (int) round(((int) end($dbHist) - (int) reset($dbHist)) / $span);
        }
        $top = q('SELECT table_name AS name, data_length + index_length AS bytes FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY bytes DESC LIMIT 2')->fetchAll();
        // Cron: duur van de laatste ronde, wanneer hij liep en of hij uitloopt
        $dur = array_map('floatval', (array) (json_decode((string) setting('cron_durations', ''), true) ?: []));
        $lastRun = strtotime((string) setting('cron_last_run', '')) ?: null;
        $cron = ['last' => $dur ? end($dur) : null, 'avg' => $dur ? round(array_sum($dur) / count($dur), 1) : null,
            'over' => count(array_filter($dur, static fn($d) => $d >= 55)), 'ago' => $lastRun ? max(0, time() - $lastRun) : null];
        // Fouten van RzSense zelf (24 uur)
        $err = q("SELECT COALESCE(SUM(count), 0) AS n, COALESCE(SUM(level = 'fatal'), 0) AS fatal FROM app_errors WHERE last_at >= NOW() - INTERVAL 1 DAY")->fetch() ?: ['n' => 0, 'fatal' => 0];
        $up = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
        $out = [
            'host' => $host, 'os' => self::osName(), 'version' => trim((string) @file_get_contents(SM_ROOT . '/VERSION')),
            'uptime' => $up > 0 ? (int) $up : null, 'src' => $src, 'agent' => $agent ? $agent['name'] : null, 'approx' => $src === 'self' && $box,
            'cpu' => $cpu, 'mem' => $mem, 'disk' => $disk,
            'db' => ['size' => $dbSize, 'growth_day' => $dbGrowth, 'top' => array_map(static fn($r) => ['name' => (string) $r['name'], 'bytes' => (int) $r['bytes']], $top)],
            'cron' => $cron, 'errors' => ['n' => (int) $err['n'], 'fatal' => (int) $err['fatal']],
        ];
        [$out['status'], $out['issues']] = self::verdict($out);
        return $out;
    }

    /** Oordeel: 0 gezond, 1 aandacht, 2 probleem, met de redenen (kort, Nederlands; vertaald in de interface) */
    public static function verdict(array $s): array
    {
        $st = 0;
        $why = [];
        $add = static function (int $lvl, string $txt) use (&$st, &$why): void {
            $st = max($st, $lvl);
            $why[] = ['lvl' => $lvl, 'txt' => $txt];
        };
        $d = $s['disk'] ?? null;
        if ($d) {
            if ($d['pct'] >= 95 || ($d['full_days'] !== null && $d['full_days'] <= 7)) {
                $add(2, t('De disk is bijna vol ({p}%)', ['p' => round($d['pct'])]));
            } elseif ($d['pct'] >= 80 || ($d['full_days'] !== null && $d['full_days'] <= 30)) {
                $add(1, $d['full_days'] !== null ? t('De disk is voor {p}% vol (bij deze groei over ~{n} dagen vol)', ['p' => round($d['pct']), 'n' => $d['full_days']])
                    : t('De disk is voor {p}% vol', ['p' => round($d['pct'])]));
            }
        }
        $m = $s['mem'] ?? null;
        if ($m && $m['pct'] >= 95) {
            $add(2, t('Het geheugen is bijna vol ({p}%)', ['p' => round($m['pct'])]));
        } elseif ($m && $m['pct'] >= 90) {
            $add(1, t('Het geheugen is voor {p}% gebruikt', ['p' => round($m['pct'])]));
        }
        $c = $s['cpu'] ?? null;
        if ($c && $c['cores'] > 0 && ($c['load'][1] ?? 0) > $c['cores'] * 1.5) {
            $add(1, t('De server is zwaar belast (load {l} op {n} cores)', ['l' => number_format((float) $c['load'][1], 2, ',', ''), 'n' => $c['cores']]));
        }
        $cr = $s['cron'] ?? [];
        if (($cr['ago'] ?? null) !== null && $cr['ago'] > 300) {
            $ago = (int) $cr['ago'];
            $add(2, $ago >= 172800 ? t('De cron-ronde loopt niet meer (laatste {n} dagen geleden)', ['n' => intdiv($ago, 86400)])
                : ($ago >= 7200 ? t('De cron-ronde loopt niet meer (laatste {n} uur geleden)', ['n' => intdiv($ago, 3600)])
                : t('De cron-ronde loopt niet meer (laatste {n} min geleden)', ['n' => intdiv($ago, 60)])));
        } elseif (($cr['ago'] ?? null) === null) {
            $add(1, t('De cron-ronde heeft nog niet gelopen'));
        } elseif (($cr['over'] ?? 0) > 0) {
            $add(1, t('De cron-ronde liep {n}× uit (langer dan een minuut)', ['n' => $cr['over']]));
        }
        $e = $s['errors'] ?? ['n' => 0, 'fatal' => 0];
        if ($e['fatal'] > 0) {
            $add(2, t('{n} ernstige PHP-fout(en) in de laatste 24 uur', ['n' => $e['fatal']]));
        } elseif ($e['n'] > 0) {
            $add(1, t('{n} PHP-waarschuwing(en) in de laatste 24 uur', ['n' => $e['n']]));
        }
        usort($why, static fn($a, $b) => $b['lvl'] <=> $a['lvl']);
        return [$st, $why];
    }

    /** Een agent op dezelfde server: zelfde naam of een van onze adressen, en recent gezien */
    private static function agentHost(string $name): ?array
    {
        $ips = array_filter(array_merge(@gethostbynamel($name) ?: [], isset($_SERVER['SERVER_ADDR']) ? [(string) $_SERVER['SERVER_ADDR']] : []),
            static fn($ip) => !str_starts_with((string) $ip, '127.') && $ip !== '::1');
        $short = strtolower(explode('.', $name)[0]);
        foreach (q("SELECT id, name, address, agent_seen, agent_tick FROM hosts WHERE enabled = 1 AND mode = 'agent' AND agent_seen IS NOT NULL ORDER BY agent_seen DESC") as $h) {
            $hn = strtolower(explode('.', (string) $h['name'])[0]);
            if ($hn !== $short && !in_array((string) $h['address'], $ips, true)) {
                continue;
            }
            if (time() - (int) strtotime((string) $h['agent_seen']) > agent_stale_seconds(isset($h['agent_tick']) ? (int) $h['agent_tick'] : null)) {
                continue;
            }
            return $h;
        }
        return null;
    }

    private static function inContainer(): bool
    {
        if (is_file('/.dockerenv') || is_file('/run/.containerenv')) {
            return true;
        }
        $cg = (string) @file_get_contents('/proc/1/cgroup');
        return (bool) preg_match('/docker|lxc|kubepods|containerd/', $cg);
    }

    /** CPU-gebruik uit /proc/stat: verschil met de vorige meting (bewaard in een tijdelijk bestand), anders 0,25 s meten */
    private static function procCpu(): ?array
    {
        $read = static function (): ?array {
            $l = strtok((string) @file_get_contents('/proc/stat'), "\n");
            if (!$l || !str_starts_with($l, 'cpu ')) {
                return null;
            }
            $f = array_map('floatval', array_slice(preg_split('/\s+/', trim($l)) ?: [], 1));
            return [array_sum($f), ($f[3] ?? 0) + ($f[4] ?? 0)];
        };
        $now = $read();
        if (!$now) {
            return null;
        }
        $file = sys_get_temp_dir() . '/rzsense-cpu-' . substr(md5(SM_ROOT), 0, 8) . '.json';
        $prev = json_decode((string) @file_get_contents($file), true);
        if (!is_array($prev) || !isset($prev['t'], $prev['s']) || $now[0] - $prev['s'][0] <= 0 || time() - (int) $prev['t'] > 600) {
            usleep(250000);
            $prev = ['s' => $now];
            $now = $read() ?? $now;
        }
        @file_put_contents($file, (string) json_encode(['t' => time(), 's' => $now]), LOCK_EX);
        $dt = $now[0] - $prev['s'][0];
        $pct = $dt > 0 ? (1 - ($now[1] - $prev['s'][1]) / $dt) * 100 : 0;
        $cores = (int) preg_match_all('/^cpu\d+ /m', (string) @file_get_contents('/proc/stat'));
        return ['pct' => round(max(0, min(100, $pct)), 1), 'load' => array_map(static fn($x) => round((float) $x, 2), sys_getloadavg() ?: [0, 0, 0]), 'cores' => $cores];
    }

    private static function procMem(): ?array
    {
        $i = [];
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $i[$m[1]] = (float) $m[2] * 1024;
            }
        }
        if (empty($i['MemTotal']) || !isset($i['MemAvailable'])) {
            return null;
        }
        return ['total' => $i['MemTotal'], 'used' => $i['MemTotal'] - $i['MemAvailable'],
            'swap' => !empty($i['SwapTotal']) ? round(($i['SwapTotal'] - ($i['SwapFree'] ?? 0)) / $i['SwapTotal'] * 100) : null];
    }

    private static function osName(): string
    {
        $r = (string) @file_get_contents('/etc/os-release');
        return preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', $r, $m) ? mb_substr($m[1], 0, 60) : PHP_OS_FAMILY;
    }
}
