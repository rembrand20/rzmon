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
        if ((int) ($st['wan_at'] ?? 0) <= time() - 3600) {   // 1.187.0: de internetlijn elk uur
            $st['wan_at'] = time();
            save_setting('net_traffic_state', json_encode($st));
            if ($m = self::wanUpdate()) {
                $msg[] = $m;
            }
            self::capCheck();
        }
        if (($st['daily'] ?? '') !== date('Y-m-d') && (int) date('G') >= 1) {
            $st['daily'] = date('Y-m-d');
            save_setting('net_traffic_state', json_encode($st));
            $n = self::daily();
            self::notifyDaily($st['daily']);
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

    /** 1.187.0: de gebeurtenissen van vannacht ook als ℹ️-melding (Systeemmeldingen → Ongewoon netwerkverkeer) */
    private static function notifyDaily(string $today): void
    {
        $rows = q("SELECT e.kind, e.detail, d.id FROM net_events e JOIN net_devices d ON d.id = e.device_id WHERE e.kind IN ('traffic', 'traffic_night') AND e.created_at >= ?",
            [$today . ' 00:00:00'])->fetchAll();
        $label = [];
        foreach (NetScan::list() as $x) {
            $label[(int) $x['id']] = (string) $x['label'];
        }
        foreach (array_slice($rows, 0, 10) as $r) {
            $x = json_decode((string) $r['detail'], true) ?: [];
            $name = $label[(int) $r['id']] ?? '?';
            Monitor::sysInfo('net_traffic', null, null, ($r['kind'] === 'traffic' ? '📈 ' . t('Ongewoon veel verkeer: {name}', ['name' => $name]) : '🌙 ' . t('Verkeer \'s nachts: {name}', ['name' => $name])),
                fn() => $r['kind'] === 'traffic'
                    ? t('{dir} {b} op {day}; normaal {n} per dag.', ['dir' => ($x['dir'] ?? '') === 'up' ? t('Upload') : t('Download'), 'b' => self::human((int) ($x['bytes'] ?? 0)),
                        'day' => (string) ($x['day'] ?? ''), 'n' => self::human((int) ($x['normal'] ?? 0))]) . "\n" . t('Zie Netwerk → Ontdekken.')
                    : t('{b} tussen 0 en 6 uur op {day}, terwijl het \'s nachts normaal stil is.', ['b' => self::human((int) ($x['bytes'] ?? 0)), 'day' => (string) ($x['day'] ?? '')]) . "\n" . t('Zie Netwerk → Ontdekken.'));
        }
    }

    // ------------------------------------------------------------------ 1.187.0: internetlijn per dag en datalimiet

    /** Internetverkeer per dag uit UniFi; lukt dat niet, dan de som van alle apparaten (bron 'devices') */
    public static function wanUpdate(): ?string
    {
        $r = self::$fetch ? ['days' => [], 'error' => 'test'] : NetLinks::unifiWanDaily(35);
        if ($r['days']) {
            foreach ($r['days'] as $day => [$rx, $tx]) {
                q("INSERT INTO net_wan_day (day, rx, tx, src) VALUES (?, ?, ?, 'unifi') ON DUPLICATE KEY UPDATE rx = VALUES(rx), tx = VALUES(tx), src = 'unifi'", [$day, $rx, $tx]);
            }
            return null;
        }
        foreach (q('SELECT day, SUM(rx) rx, SUM(tx) tx FROM net_traffic_day WHERE day >= CURDATE() - INTERVAL 34 DAY GROUP BY day')->fetchAll() as $d) {
            q("INSERT INTO net_wan_day (day, rx, tx, src) VALUES (?, ?, ?, 'devices') ON DUPLICATE KEY UPDATE rx = IF(src = 'unifi', rx, VALUES(rx)), tx = IF(src = 'unifi', tx, VALUES(tx))",
                [$d['day'], (int) $d['rx'], (int) $d['tx']]);
        }
        return null;
    }

    /** Deze maand: totaal, per dag, de datalimiet en de verwachting aan het eind van de maand */
    public static function wanMonth(?string $month = null): array
    {
        $month ??= date('Y-m');
        $rows = q("SELECT day, rx, tx, src FROM net_wan_day WHERE day LIKE ? ORDER BY day", [$month . '-%'])->fetchAll();
        $rx = array_sum(array_map('intval', array_column($rows, 'rx')));
        $tx = array_sum(array_map('intval', array_column($rows, 'tx')));
        $cap = (float) str_replace(',', '.', (string) setting('net_wan_cap_gb', ''));
        $daysIn = (int) date('t', (int) strtotime($month . '-01'));
        $today = $month === date('Y-m') ? (int) date('j') : $daysIn;
        $forecast = $today > 0 ? (int) round(($rx + $tx) / max(1, $today - 1 + (int) date('G') / 24) * $daysIn) : 0;
        return ['month' => $month, 'rx' => $rx, 'tx' => $tx, 'cap' => $cap > 0 ? (int) round($cap * 1e9) : null, 'forecast' => $month === date('Y-m') ? $forecast : null,
            'src' => $rows ? (in_array('unifi', array_column($rows, 'src'), true) ? 'unifi' : 'devices') : null,
            'days' => array_map(fn($r) => [(string) $r['day'], (int) $r['rx'], (int) $r['tx']], $rows)];
    }

    /** Melding bij 80% en 100% van de datalimiet (één keer per maand per stap) */
    public static function capCheck(): void
    {
        $m = self::wanMonth();
        if (!$m['cap']) {
            return;
        }
        $used = $m['rx'] + $m['tx'];
        $done = json_decode((string) setting('net_wan_cap_alert', ''), true) ?: [];
        foreach ([100, 80] as $pct) {
            if ($used >= $m['cap'] * $pct / 100 && ($done[$pct] ?? '') !== $m['month']) {
                $done[$pct] = $m['month'];
                if ($pct === 100) {
                    $done[80] = $m['month'];
                }
                save_setting('net_wan_cap_alert', json_encode($done));
                Monitor::sysInfo('net_cap', null, null, '📶 ' . t('Internet: {p}% van je datalimiet gebruikt', ['p' => $pct]),
                    fn() => t('Deze maand {u} van {c} (↓ {d} · ↑ {up}).', ['u' => self::human($used), 'c' => self::human((int) $m['cap']), 'd' => self::human($m['rx']), 'up' => self::human($m['tx'])]));
                break;
            }
        }
    }

    // ------------------------------------------------------------------ 1.187.0: check "Verkeer"

    /**
     * Check van het type traffic: target = MAC- of IP-adres (leeg = het adres van de server). metric:
     * rate (Mbit/s nu, down + up), up_rate (Mbit/s upload), day (GB vandaag), up_day (GB upload vandaag).
     */
    public static function check(array $check, array $host): array
    {
        $t = trim((string) ($check['target'] ?? '')) ?: (string) $host['address'];
        $mac = NetScan::normMac($t);
        $dev = $mac ? q('SELECT id, ip FROM net_devices WHERE mac = ?', [$mac])->fetch()
            : q('SELECT id, ip FROM net_devices WHERE ip = ? ORDER BY last_seen DESC LIMIT 1', [$t])->fetch();
        if (!$dev) {
            return Checks::result(STATUS_UNKNOWN, null, t('{t} staat niet in de netwerkscan (Netwerk → Apparaten)', ['t' => $t]));
        }
        if (!self::enabled()) {
            return Checks::result(STATUS_UNKNOWN, null, t('Verkeer per apparaat staat uit of UniFi is niet gekoppeld'));
        }
        $id = (int) $dev['id'];
        $metric = (string) ($check['metric'] ?: 'rate');
        if ($metric === 'rate' || $metric === 'up_rate') {
            $c = q('SELECT rate_rx, rate_tx, at FROM net_counters WHERE device_id = ?', [$id])->fetch();
            if (!$c || (int) $c['at'] < time() - 3 * self::EVERY) {
                return Checks::result(STATUS_UNKNOWN, null, t('Nu geen gegevens van UniFi (apparaat niet verbonden?)'));
            }
            $bps = $metric === 'up_rate' ? (int) $c['rate_tx'] : (int) $c['rate_rx'] + (int) $c['rate_tx'];
            $v = round($bps * 8 / 1e6, 2);
            $msg = t('{v} Mbit/s nu (↓ {d} · ↑ {u} Mbit/s)', ['v' => I18n::dec((string) $v), 'd' => I18n::dec((string) round((int) $c['rate_rx'] * 8 / 1e6, 2)), 'u' => I18n::dec((string) round((int) $c['rate_tx'] * 8 / 1e6, 2))]);
        } else {
            $d = q('SELECT rx, tx FROM net_traffic_day WHERE device_id = ? AND day = CURDATE()', [$id])->fetch() ?: ['rx' => 0, 'tx' => 0];
            $b = $metric === 'up_day' ? (int) $d['tx'] : (int) $d['rx'] + (int) $d['tx'];
            $v = round($b / 1e9, 2);
            $msg = t('{v} GB vandaag (↓ {d} · ↑ {u})', ['v' => I18n::dec((string) $v), 'd' => self::human((int) $d['rx']), 'u' => self::human((int) $d['tx'])]);
        }
        $w = $check['warn_threshold'] !== null ? (float) $check['warn_threshold'] : null;
        $c2 = $check['crit_threshold'] !== null ? (float) $check['crit_threshold'] : null;
        $st = $c2 !== null && $v >= $c2 ? STATUS_CRIT : ($w !== null && $v >= $w ? STATUS_WARN : STATUS_OK);
        return Checks::result($st, $v, $msg);
    }

    // ------------------------------------------------------------------ 1.187.0: rapport

    /** Top 10 en totaal over een periode */
    public static function report(string $from, string $to): array
    {
        $top = q('SELECT device_id, SUM(rx) rx, SUM(tx) tx FROM net_traffic_day WHERE day BETWEEN ? AND ? GROUP BY device_id ORDER BY SUM(rx + tx) DESC LIMIT 10', [$from, $to])->fetchAll();
        $label = [];
        foreach (NetScan::list() as $x) {
            $label[(int) $x['id']] = (string) $x['label'];
        }
        $wan = q('SELECT COALESCE(SUM(rx), 0) rx, COALESCE(SUM(tx), 0) tx FROM net_wan_day WHERE day BETWEEN ? AND ?', [$from, $to])->fetch();
        $len = max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1);
        $prevFrom = date('Y-m-d', (int) strtotime($from) - $len * 86400);
        $prevTo = date('Y-m-d', (int) strtotime($from) - 86400);
        $prev = [];
        foreach (q('SELECT device_id, SUM(rx + tx) b FROM net_traffic_day WHERE day BETWEEN ? AND ? GROUP BY device_id', [$prevFrom, $prevTo])->fetchAll() as $p) {
            $prev[(int) $p['device_id']] = (int) $p['b'];
        }
        return ['wan' => [(int) $wan['rx'], (int) $wan['tx']], 'top' => array_map(fn($r) => ['name' => $label[(int) $r['device_id']] ?? '?', 'rx' => (int) $r['rx'], 'tx' => (int) $r['tx'],
            'prev' => $prev[(int) $r['device_id']] ?? null], $top)];
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
        q('DELETE FROM net_wan_day WHERE day < CURDATE() - INTERVAL ' . self::KEEP_DAYS . ' DAY');
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
