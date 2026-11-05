<?php
declare(strict_types=1);

/**
 * 1.186.0: netwerkverkeer per apparaat.
 *
 * Bron: UniFi (stat/sta, met een lokaal account). Elke 5 minuten de bytetellers van alle verbonden clients;
 * het verschil met de vorige keer gaat per uur (net_traffic, 30 dagen) en per dag (net_traffic_day, 1 jaar) in de database.
 * rx = wat het apparaat ontving (download), tx = wat het verstuurde (upload), gezien vanaf het apparaat.
 * Een teller die terugspringt (opnieuw verbonden) telt vanaf nul; een onmogelijk grote sprong wordt genegeerd.
 *
 * Elke nacht: is gisteren ongewoon veel verkeerd gebruikt (vergeleken met de 14 dagen ervoor), of 's nachts verkeer
 * bij een apparaat dat 's nachts normaal stil is, dan komt er een gebeurtenis in Netwerk → Ontdekken.
 */
final class NetTraffic
{
    public const EVERY = 300;              // seconden tussen twee metingen
    public const KEEP_HOURS_DAYS = 30;
    public const KEEP_DAYS = 400;
    private const MAX_BPS = 1_250_000_000; // 10 Gbit/s: meer kan een client niet; meer = teller-fout
    /** Gebeurtenis "ongewoon veel": zoveel keer het normale, en minstens zoveel bytes */
    public const SPIKE_FACTOR = 5;
    public const SPIKE_MIN = 300 * 1024 * 1024;
    public const NIGHT_MIN = 50 * 1024 * 1024;

    /** @var (callable(): array)|null alleen voor tests: geeft de clients zoals unifiClients()['clients'] */
    public static $fetch = null;

    public static function enabled(): bool
    {
        return setting('net_traffic', '1') === '1' && (self::$fetch !== null || (NetScan::enabled() && NetLinks::unifiOn()));
    }

    /** Uit de cron: elke 5 minuten de tellers ophalen, en één keer per dag de vergelijking */
    public static function cron(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $st = json_decode((string) setting('net_traffic_state', ''), true) ?: [];
        $msg = [];
        if ((int) ($st['at'] ?? 0) <= time() - self::EVERY + 20) {
            $msg[] = self::poll();
            $st = json_decode((string) setting('net_traffic_state', ''), true) ?: [];
        }
        if (($st['daily'] ?? '') !== date('Y-m-d') && (int) date('G') >= 1) {
            $st['daily'] = date('Y-m-d');
            save_setting('net_traffic_state', json_encode($st));
            $n = self::daily();
            self::purge();
            if ($n) {
                $msg[] = "Verkeer: $n ongewoon";
            }
        }
        return $msg ? implode(' · ', $msg) : null;
    }

    /** Tellers van alle verbonden clients ophalen en het verschil wegschrijven */
    public static function poll(?int $now = null): string
    {
        $now ??= time();
        $st = json_decode((string) setting('net_traffic_state', ''), true) ?: [];
        if (self::$fetch) {
            $clients = (self::$fetch)();
            $err = null;
        } else {
            $r = NetLinks::unifiTraffic();
            $clients = $r['clients'];
            $err = $r['error'];
        }
        if ($err !== null) {
            save_setting('net_traffic_state', json_encode(['at' => $now, 'error' => $err] + $st));
            return 'Verkeer: ' . $err;
        }
        $byMac = [];
        foreach (q('SELECT id, mac FROM net_devices WHERE mac IS NOT NULL')->fetchAll() as $r) {
            $byMac[$r['mac']] = (int) $r['id'];
        }
        $hour = date('Y-m-d H:00:00', $now);
        $day = date('Y-m-d', $now);
        $n = 0;
        $total = [0, 0];
        foreach ($clients as $mac => $c) {
            $mac = NetScan::normMac((string) $mac);
            if ($mac === null || !isset($byMac[$mac])) {
                continue;
            }
            $id = $byMac[$mac];
            $rx = max(0, (int) ($c['rx'] ?? 0));
            $tx = max(0, (int) ($c['tx'] ?? 0));
            $last = q('SELECT rx, tx, at FROM net_counters WHERE device_id = ?', [$id])->fetch();
            q('INSERT INTO net_counters (device_id, rx, tx, rate_rx, rate_tx, at) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE rx = VALUES(rx), tx = VALUES(tx), rate_rx = VALUES(rate_rx), rate_tx = VALUES(rate_tx), at = VALUES(at)',
                [$id, $rx, $tx, (int) ($c['rate_rx'] ?? 0), (int) ($c['rate_tx'] ?? 0), $now]);
            if (!$last) {
                continue;   // eerste keer: alleen de teller onthouden
            }
            $dt = max(1, $now - (int) $last['at']);
            if ($dt > 6 * 3600) {
                continue;   // te lang geleden (RzMon stond stil): niet weten waar het verkeer viel
            }
            $dRx = self::delta((int) $last['rx'], $rx, $dt);
            $dTx = self::delta((int) $last['tx'], $tx, $dt);
            if ($dRx + $dTx === 0) {
                continue;
            }
            self::add($id, $hour, $day, $dRx, $dTx);
            $total[0] += $dRx;
            $total[1] += $dTx;
            $n++;
        }
        save_setting('net_traffic_state', json_encode(['at' => $now, 'error' => null, 'n' => $n] + $st));
        return 'Verkeer: ' . $n . ' apparaten, ↓' . self::human($total[0]) . ' ↑' . self::human($total[1]);
    }

    /** Verschil tussen twee tellerstanden; terug naar een lagere stand = opnieuw verbonden (telt vanaf nul) */
    public static function delta(int $old, int $new, int $dt): int
    {
        $d = $new >= $old ? $new - $old : $new;
        return $d > self::MAX_BPS * $dt ? 0 : $d;
    }

    public static function add(int $id, string $hour, string $day, int $rx, int $tx): void
    {
        q('INSERT INTO net_traffic (device_id, hour, rx, tx) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rx = rx + VALUES(rx), tx = tx + VALUES(tx)', [$id, $hour, $rx, $tx]);
        q('INSERT INTO net_traffic_day (device_id, day, rx, tx) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rx = rx + VALUES(rx), tx = tx + VALUES(tx)', [$id, $day, $rx, $tx]);
    }

    public static function human(int|float $b): string
    {
        $u = ['B', 'kB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1000 && $i < 4) {
            $b /= 1000;
            $i++;
        }
        return ($i ? number_format($b, $b < 10 ? 1 : 0, ',', '.') : (string) (int) $b) . ' ' . $u[$i];
    }

    // ------------------------------------------------------------------ ongewoon verkeer

    /**
     * Gisteren vergelijken met de 14 dagen ervoor (mediaan, minstens 7 dagen gegevens). Geeft het aantal gebeurtenissen.
     */
    public static function daily(?string $day = null): int
    {
        $day ??= date('Y-m-d', strtotime('yesterday'));
        $n = 0;
        $rows = q('SELECT device_id, rx, tx FROM net_traffic_day WHERE day = ?', [$day])->fetchAll();
        foreach ($rows as $r) {
            $id = (int) $r['device_id'];
            $hist = q('SELECT rx, tx FROM net_traffic_day WHERE device_id = ? AND day < ? AND day >= ? - INTERVAL 14 DAY', [$id, $day, $day])->fetchAll();
            if (count($hist) >= 7) {
                foreach (['tx' => 'up', 'rx' => 'down'] as $col => $dir) {
                    $med = self::median(array_map('intval', array_column($hist, $col)));
                    $v = (int) $r[$col];
                    if ($v >= self::SPIKE_MIN && $v >= max(1, $med) * self::SPIKE_FACTOR) {
                        NetDiscover::event($id, 'traffic', ['dir' => $dir, 'bytes' => $v, 'normal' => $med, 'day' => $day]);
                        $n++;
                    }
                }
            }
            // 's nachts (0–6 uur) verkeer bij een apparaat dat 's nachts normaal stil is
            $night = (int) q('SELECT COALESCE(SUM(rx + tx), 0) FROM net_traffic WHERE device_id = ? AND hour >= ? AND hour < ? + INTERVAL 6 HOUR', [$id, $day . ' 00:00:00', $day . ' 00:00:00'])->fetchColumn();
            if ($night >= self::NIGHT_MIN) {
                $nights = q("SELECT SUM(rx + tx) b FROM net_traffic WHERE device_id = ? AND hour < ? AND hour >= ? - INTERVAL 14 DAY AND HOUR(hour) < 6 GROUP BY DATE(hour)",
                    [$id, $day . ' 00:00:00', $day . ' 00:00:00'])->fetchAll();
                $quiet = count($nights) < 3 ? false : self::median(array_map('intval', array_column($nights, 'b'))) < self::NIGHT_MIN / 10;
                $daysKnown = (int) q('SELECT COUNT(*) FROM net_traffic_day WHERE device_id = ? AND day < ?', [$id, $day])->fetchColumn();
                if ($daysKnown >= 7 && ($quiet || count($nights) === 0)) {
                    NetDiscover::event($id, 'traffic_night', ['bytes' => $night, 'day' => $day]);
                    $n++;
                }
            }
        }
        return $n;
    }

    public static function median(array $v): int
    {
        if (!$v) {
            return 0;
        }
        sort($v);
        $m = intdiv(count($v), 2);
        return count($v) % 2 ? (int) $v[$m] : intdiv((int) $v[$m - 1] + (int) $v[$m], 2);
    }

    public static function purge(): void
    {
        q('DELETE FROM net_traffic WHERE hour < NOW() - INTERVAL ' . self::KEEP_HOURS_DAYS . ' DAY');
        q('DELETE FROM net_traffic_day WHERE day < CURDATE() - INTERVAL ' . self::KEEP_DAYS . ' DAY');
        q('DELETE t FROM net_traffic t LEFT JOIN net_devices d ON d.id = t.device_id WHERE d.id IS NULL');
        q('DELETE t FROM net_traffic_day t LEFT JOIN net_devices d ON d.id = t.device_id WHERE d.id IS NULL');
        q('DELETE t FROM net_counters t LEFT JOIN net_devices d ON d.id = t.device_id WHERE d.id IS NULL');
    }

    // ------------------------------------------------------------------ voor de pagina's

    /** Per apparaat de laatste 24 uur: [id => ['rx', 'tx', 'spark' => [24 × bytes], 'rate_rx', 'rate_tx']] */
    public static function summary(): array
    {
        $out = [];
        $from = strtotime(date('Y-m-d H:00:00')) - 23 * 3600;
        foreach (q('SELECT device_id, hour, rx, tx FROM net_traffic WHERE hour >= ?', [date('Y-m-d H:i:s', $from)])->fetchAll() as $r) {
            $id = (int) $r['device_id'];
            $out[$id] ??= ['rx' => 0, 'tx' => 0, 'spark' => array_fill(0, 24, 0)];
            $out[$id]['rx'] += (int) $r['rx'];
            $out[$id]['tx'] += (int) $r['tx'];
            $i = (int) floor((strtotime((string) $r['hour']) - $from) / 3600);
            if ($i >= 0 && $i < 24) {
                $out[$id]['spark'][$i] += (int) $r['rx'] + (int) $r['tx'];
            }
        }
        foreach (q('SELECT device_id, rate_rx, rate_tx, at FROM net_counters WHERE at >= ?', [time() - 3 * self::EVERY])->fetchAll() as $r) {
            $id = (int) $r['device_id'];
            $out[$id] ??= ['rx' => 0, 'tx' => 0, 'spark' => array_fill(0, 24, 0)];
            $out[$id]['rate_rx'] = (int) $r['rate_rx'];
            $out[$id]['rate_tx'] = (int) $r['rate_tx'];
        }
        return $out;
    }

    /** Totaal van vandaag en gisteren tot hetzelfde uur: ['today' => [rx, tx], 'yesterday' => [rx, tx]] */
    public static function totals(): array
    {
        $t = q('SELECT COALESCE(SUM(rx), 0) rx, COALESCE(SUM(tx), 0) tx FROM net_traffic WHERE hour >= CURDATE()')->fetch();
        $y = q('SELECT COALESCE(SUM(rx), 0) rx, COALESCE(SUM(tx), 0) tx FROM net_traffic WHERE hour >= CURDATE() - INTERVAL 1 DAY AND hour < NOW() - INTERVAL 1 DAY')->fetch();
        return ['today' => [(int) $t['rx'], (int) $t['tx']], 'yesterday' => [(int) $y['rx'], (int) $y['tx']]];
    }

    /** Per apparaat per uur (laatste 7 dagen): [id => ['Y-m-d H' => bytes]], voor de heatmap in Ontdekken */
    public static function hourGrid(int $days = 7): array
    {
        $out = [];
        foreach (q('SELECT device_id, hour, rx + tx b FROM net_traffic WHERE hour >= CURDATE() - INTERVAL ' . max(0, min(29, $days - 1)) . ' DAY')->fetchAll() as $r) {
            $out[(int) $r['device_id']][substr((string) $r['hour'], 0, 13)] = (int) $r['b'];
        }
        return $out;
    }

    /** Alles voor het venster van één apparaat: per uur (7 dagen), per dag (30 dagen) en wat normaal is */
    public static function device(int $id): array
    {
        $hours = q('SELECT hour, rx, tx FROM net_traffic WHERE device_id = ? AND hour >= CURDATE() - INTERVAL 6 DAY ORDER BY hour', [$id])->fetchAll();
        $days = q('SELECT day, rx, tx FROM net_traffic_day WHERE device_id = ? AND day >= CURDATE() - INTERVAL 29 DAY ORDER BY day', [$id])->fetchAll();
        $hist = array_values(array_filter($days, fn($d) => $d['day'] < date('Y-m-d')));
        $hist = array_slice($hist, -14);
        $last = q('SELECT rate_rx, rate_tx, at FROM net_counters WHERE device_id = ?', [$id])->fetch() ?: null;
        return [
            'hours' => array_map(fn($r) => [substr((string) $r['hour'], 0, 13), (int) $r['rx'], (int) $r['tx']], $hours),
            'days' => array_map(fn($r) => [(string) $r['day'], (int) $r['rx'], (int) $r['tx']], $days),
            'normal' => count($hist) >= 7 ? ['rx' => self::median(array_map('intval', array_column($hist, 'rx'))), 'tx' => self::median(array_map('intval', array_column($hist, 'tx')))] : null,
            'rate' => $last && (int) $last['at'] >= time() - 3 * self::EVERY ? ['rx' => (int) $last['rate_rx'], 'tx' => (int) $last['rate_tx']] : null,
        ];
    }

    public static function state(): array
    {
        $st = json_decode((string) setting('net_traffic_state', ''), true) ?: [];
        return ['on' => self::enabled(), 'setting' => setting('net_traffic', '1') === '1', 'unifi' => NetLinks::unifiOn(), 'account' => NetLinks::unifiAccountMode(),
            'at' => $st['at'] ?? null, 'error' => $st['error'] ?? null];
    }
}
