<?php
declare(strict_types=1);

/**
 * Trends (1.224.0): langzame veranderingen over de laatste 90 dagen, voor Analyse → Trends, een melding en het maandrapport.
 *
 * - Bronnen: de dagwaarden van Analyse (stats_daily: c_avg per check, storingen, wisselingen, IDS, kwetsbaarheden) en
 *   eigen dagwaarden die je achteraf niet meer kunt uitrekenen (tr_*, elk uur bijgewerkt): UPS-looptijd en -belasting,
 *   SSD-slijtage en vervangen sectoren, grootte van de database en de logs, duur van een controleronde, hardening-score.
 *   De internetsnelheid komt uit inet_speed.
 * - Lijn: Theil-Sen (de mediaan van alle hellingen tussen twee punten). Uitschieters, een dag- en weekritme (back-ups
 *   's nachts, rustige weekenden) trekken die lijn niet scheef.
 * - "Beweegt": de verandering per maand is groter dan de kleinste verandering die telt (per soort), én consequent
 *   (Kendall-tau ≥ 0,3: de meeste paren punten gaan dezelfde kant op). Anders: stabiel.
 * - Verwachting: waar de lijn de grens raakt (vol, versleten, te traag). Melding (sysinfo "trend") als dat binnen
 *   trend_alert_days dagen is; één keer per trend per 14 dagen.
 */
final class Trends
{
    public const DAYS = 90;
    private const MIN_DAYS = 14;
    private const WEEKS = 13;
    private const TAU = 0.3;
    /** Checktypen met een getal dat een trend heeft: [groep, pictogram, hoger is slechter, eenheid, decimalen, kleinste verandering per maand [%, absoluut]] */
    private const CHECKS = [
        'http' => ['slow', '🐢', true, ' ms', 0, [10, 10]], 'tcp' => ['slow', '🔌', true, ' ms', 1, [10, 1]], 'ping' => ['slow', '📶', true, ' ms', 1, [10, 1]],
        'dns' => ['slow', '🧭', true, ' ms', 0, [10, 2]], 'disk' => ['cap', '💽', true, '%', 0, [null, 1]], 'ram' => ['cap', '🧠', true, '%', 0, [null, 2]],
        'smart' => ['hw', '🌡️', true, ' °C', 0, [null, 1]], 'ups' => ['hw', '🔋', false, '%', 0, [null, 3]],
    ];

    // ------------------------------------------------------------------ dagwaarden verzamelen

    /** Vanuit cron: elk uur de dagwaarden van vandaag, en één keer per dag (na 05:00) de analyse en de meldingen */
    public static function cron(): ?string
    {
        if ((int) setting('trends_snap_at', '0') > time() - 3600) {
            return null;
        }
        save_setting('trends_snap_at', (string) time());
        self::snapshot(date('Y-m-d'));
        if ((int) date('G') >= 5 && setting('trends_day', '') !== date('Y-m-d')) {
            save_setting('trends_day', date('Y-m-d'));
            self::compute();   // in de taal van de pagina
            $n = (int) I18n::forNotify(fn() => self::notify(self::compute(false)));   // de melding in de taal van meldingen
            return $n ? "Trends: $n melding(en)" : null;
        }
        return null;
    }

    private static function put(string $day, string $metric, int $ref, float $v): void
    {
        static $st = null;
        $st ??= db()->prepare('INSERT INTO stats_daily (day, metric, ref, v) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
        $st->execute([$day, $metric, $ref, $v]);
    }

    /** De stand van vandaag (overschrijft de vorige keer van vandaag) */
    public static function snapshot(string $day): void
    {
        foreach (q("SELECT c.id, c.type, c.target, c.host_id, h.mode FROM checks c JOIN hosts h ON h.id = c.host_id
                    WHERE c.enabled = 1 AND h.enabled = 1 AND c.type IN ('ups', 'smart') AND h.mode = 'agent'")->fetchAll() as $c) {
            $list = json_decode((string) Checks::agentMetric(['id' => (int) $c['host_id']] + $c, $c['type']), true);
            if (!is_array($list)) {
                continue;
            }
            $tg = trim((string) $c['target']);
            if ($c['type'] === 'ups') {
                foreach ($list as $u) {
                    if (is_array($u) && ($tg === '' || ($u['name'] ?? '') === $tg)) {
                        if (isset($u['runtime']) && is_numeric($u['runtime'])) {
                            self::put($day, 'tr_rt', (int) $c['id'], round((float) $u['runtime'] / 60, 2));
                        }
                        if (isset($u['load']) && is_numeric($u['load'])) {
                            self::put($day, 'tr_load', (int) $c['id'], (float) $u['load']);
                        }
                        break;
                    }
                }
                continue;
            }
            $wear = $bad = null;
            foreach ($list as $d) {
                if (!is_array($d) || ($tg !== '' && ($d['dev'] ?? '') !== $tg) || !empty($d['error'])) {
                    continue;
                }
                if (isset($d['wear']) && is_numeric($d['wear'])) {
                    $wear = max($wear ?? 0, (float) $d['wear']);
                }
                $bad = ($bad ?? 0) + (int) ($d['realloc'] ?? 0) + (int) ($d['pending'] ?? 0);
            }
            if ($wear !== null) {
                self::put($day, 'tr_wear', (int) $c['id'], $wear);
            }
            if ($bad !== null) {
                self::put($day, 'tr_bad', (int) $c['id'], (float) $bad);
            }
        }
        try {
            self::put($day, 'tr_db', 0, (float) Retention::dbSize());
            self::put($day, 'tr_logs', 0, (float) q("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES
                WHERE table_schema = DATABASE() AND table_name LIKE 'logd\\_%'")->fetchColumn());
        } catch (Throwable) {
        }
        $dur = array_map('floatval', (array) (json_decode((string) setting('cron_durations', ''), true) ?: []));
        if ($dur) {
            sort($dur);
            self::put($day, 'tr_cron', 0, round($dur[intdiv(count($dur), 2)], 2));   // mediaan: één trage ronde telt niet
        }
        if (Hardening::enabled()) {
            $sc = array_filter(array_map(static fn($x) => $x['score'], Hardening::scores()), static fn($v) => $v !== null);
            if ($sc) {
                self::put($day, 'tr_hard', 0, round(array_sum($sc) / count($sc), 1));
            }
        }
    }

    // ------------------------------------------------------------------ rekenen

    /**
     * Theil-Sen-lijn door [x => y]: [helling, snijpunt bij x = 0, Kendall-tau]
     * @param array<int, float> $pts
     * @return array{0: float, 1: float, 2: float}
     */
    public static function fit(array $pts): array
    {
        $xs = array_keys($pts);
        $ys = array_values($pts);
        $n = count($xs);
        $sl = [];
        $con = $dis = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $dx = $xs[$j] - $xs[$i];
                if ($dx == 0) {
                    continue;
                }
                $dy = $ys[$j] - $ys[$i];
                $sl[] = $dy / $dx;
                if ($dy > 0) {
                    $con++;
                } elseif ($dy < 0) {
                    $dis++;
                }
            }
        }
        if (!$sl) {
            return [0.0, $ys[0] ?? 0.0, 0.0];
        }
        $b = self::median($sl);
        $a = self::median(array_map(static fn($x, $y) => $y - $b * $x, $xs, $ys));
        return [$b, $a, ($con - $dis) / count($sl)];
    }

    /** @param array<int|float> $v */
    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ((float) $v[$n / 2 - 1] + (float) $v[$n / 2]) / 2;
    }

    /** Dagreeks over de laatste 90 dagen (vandaag telt mee): [dag => waarde of null], vanaf de eerste dag met een waarde */
    private static function daySeries(array $byDay): array
    {
        $out = [];
        $seen = false;
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $v = $byDay[$d] ?? null;
            if ($v === null && !$seen) {
                continue;
            }
            $seen = true;
            $out[$d] = $v === null ? null : (float) $v;
        }
        return $out;
    }

    /** Weekreeks (13 blokken van 7 dagen tot en met gisteren, opgeteld): [begindag => som] */
    private static function weekSeries(array $byDay): array
    {
        $out = [];
        $first = null;
        for ($w = self::WEEKS - 1; $w >= 0; $w--) {
            $start = date('Y-m-d', strtotime('-' . ($w * 7 + 7) . ' day'));
            $sum = 0.0;
            $any = false;
            for ($k = 0; $k < 7; $k++) {
                $d = date('Y-m-d', strtotime("$start +$k day"));
                if (isset($byDay[$d])) {
                    $sum += (float) $byDay[$d];
                    $any = true;
                }
            }
            if (!$any && $first === null) {
                continue;
            }
            $first ??= $start;
            $out[$start] = $sum;
        }
        return $out;
    }

    /**
     * Eén trend beoordelen. $o: id, grp, icon, title, sub, unit, d, bytes, bars, hb (hoger is slechter), eff [pct, abs],
     * limit, limit_lbl, advice, go, host_id. Null bij te weinig gegevens.
     */
    public static function judge(array $series, array $o): ?array
    {
        $bars = !empty($o['bars']);
        $pts = [];
        $i = 0;
        foreach ($series as $v) {
            if ($v !== null) {
                $pts[$i] = (float) $v;
            }
            $i++;
        }
        if (count($pts) < ($bars ? 6 : self::MIN_DAYS) || array_key_last($pts) - array_key_first($pts) < ($bars ? 5 : self::MIN_DAYS - 1)) {
            return null;
        }
        [$b, $a, $tau] = self::fit($pts);
        $n = count($series);
        $last = $n - 1;
        $fitNow = $a + $b * $last;
        $perMonth = $b * ($bars ? 30 / 7 : 30);
        $base = max(abs($fitNow), abs($a), 1e-9);
        $pct = $perMonth / $base * 100;
        [$effPct, $effAbs] = $o['eff'] + [null, null];
        $sig = abs($tau) >= ($bars ? 0.35 : self::TAU) && $perMonth != 0
            && ($effPct === null || abs($pct) >= $effPct) && ($effAbs === null || abs($perMonth) >= $effAbs);
        $hb = $o['hb'] ?? true;
        $bad = $sig && ($hb ? $b > 0 : $b < 0);
        $vals = array_values($pts);
        $now = self::median(array_slice($vals, -3));
        $was = self::median(array_slice($vals, 0, 3));
        $eta = $etaDays = null;
        $limit = $o['limit'] ?? null;
        if ($bad && $limit !== null && $b != 0) {
            $steps = ($limit - $fitNow) / $b;
            $days = (int) round($steps * ($bars ? 7 : 1));
            if ($steps > 0 && $days <= 730) {
                $etaDays = max(1, $days);
                $eta = date('Y-m-d', strtotime("+$etaDays day"));
            }
        }
        $fmt = static fn($v) => self::fmt((float) $v, $o);
        $first = (string) array_key_first($series);
        $span = (int) round((strtotime(date('Y-m-d')) - strtotime($first)) / 86400) + ($bars ? 7 : 0);
        if ($sig) {
            $chg = $effPct !== null ? I18n::dec((string) round(abs($pct))) . '%'
                : (($o['unit'] ?? '') === '%' ? t('{n} procentpunt', ['n' => I18n::dec((string) round(abs($perMonth), 1))]) : self::fmt(abs($perMonth), $o));
            $short = t('{dir} {c} per maand: van {a} naar {b} in {n} dagen.', ['dir' => $b > 0 ? t('Stijgt') : t('Daalt'), 'c' => $chg, 'a' => $fmt($was), 'b' => $fmt($now), 'n' => $span]);
            $text = $short . ($bad ? ' ' . ($o['advice'] ?? '') : ' ' . ($o['good'] ?? t('Dat is goed nieuws.')));
            if ($eta) {
                $text .= ' ' . t('Bij dit tempo {lbl} rond {d}.', ['lbl' => $o['limit_lbl'] ?? t('de grens bereikt'), 'd' => self::longDate($eta)]);
            }
        } else {
            $short = $text = t('Stabiel rond {v}.', ['v' => $fmt($now)]);
        }
        $round = static fn($v) => $v === null ? null : round((float) $v, ($o['bytes'] ?? false) ? 0 : max(2, (int) ($o['d'] ?? 0) + 1));
        return [
            'id' => $o['id'], 'grp' => $o['grp'], 'icon' => $o['icon'], 'title' => $o['title'], 'sub' => $o['sub'] ?? '', 'unit' => $o['unit'] ?? '',
            'd' => (int) ($o['d'] ?? 0), 'bytes' => !empty($o['bytes']), 'bars' => $bars, 'per' => 30,
            'series' => array_map($round, $series), 'fit' => [round($b, 6), round($a, 4)], 'tau' => round($tau, 2),
            'now' => $round($now), 'was' => $round($was), 'chg' => (int) round($pct), 'chg_abs' => $effPct === null ? round($perMonth, 3) : null,
            'sig' => $sig, 'bad' => $bad, 'limit' => $limit, 'limit_lbl' => $o['limit_lbl'] ?? null, 'eta' => $eta, 'eta_days' => $etaDays,
            'eta_lbl' => $o['eta_lbl'] ?? null, 'ahead' => $sig && !$bars ? min(60, $etaDays ?? 30) : 0,
            'text' => trim($text), 'short' => $short, 'go' => $o['go'] ?? null, 'host_id' => $o['host_id'] ?? null,
        ];
    }

    private static function fmt(float $v, array $o): string
    {
        if (!empty($o['bytes'])) {
            foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6, 'kB' => 1e3] as $u => $f) {
                if (abs($v) >= $f) {
                    return I18n::dec((string) round($v / $f, 1)) . ' ' . $u;
                }
            }
            return (int) $v . ' B';
        }
        return I18n::dec((string) round($v, (int) ($o['d'] ?? 0))) . ($o['unit'] ?? '');
    }

    /** "3 april 2027" in de taal van de gebruiker (of van meldingen) */
    public static function longDate(string $ymd): string
    {
        class_exists('Analytics');   // daar staat strftime_month()
        return (int) substr($ymd, 8, 2) . ' ' . strftime_month(substr($ymd, 0, 7));
    }

    /** [ref => [dag => waarde]] voor deze metrics vanaf 91 dagen geleden */
    private static function load(array $metrics, bool $sumRefs = false): array
    {
        $in = implode(',', array_fill(0, count($metrics), '?'));
        $from = date('Y-m-d', strtotime('-' . (self::DAYS + 7) . ' day'));
        $out = [];
        $sql = $sumRefs
            ? "SELECT 0 AS ref, day, SUM(v) AS v FROM stats_daily WHERE metric IN ($in) AND day >= ? GROUP BY day"
            : "SELECT ref, metric, day, v FROM stats_daily WHERE metric IN ($in) AND day >= ?";
        foreach (q($sql, [...$metrics, $from]) as $r) {
            $key = $sumRefs ? 0 : (count($metrics) > 1 ? $r['metric'] . ':' . $r['ref'] : (int) $r['ref']);
            $out[$key][(string) $r['day']] = (float) $r['v'];
        }
        return $out;
    }

    /** De bewaarde berekening, per taal (de zinnen zijn al vertaald) */
    private static function cacheKey(): string
    {
        return 'trends_cache_' . (I18n::lang() === 'en' ? 'en' : 'nl');
    }

    /** Alle trends berekenen en (standaard) bewaren */
    public static function compute(bool $save = true): array
    {
        $items = [];
        $add = static function (?array $x) use (&$items) {
            if ($x) {
                $items[] = $x;
            }
        };
        // Checks: het daggemiddelde van de waarde
        $checks = q("SELECT c.id, c.type, c.name, c.target, c.warn_threshold, c.crit_threshold, h.id AS host_id, h.name AS host
                     FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.enabled = 1 AND h.enabled = 1 AND c.type IN ('" . implode("','", array_keys(self::CHECKS)) . "')")->fetchAll();
        $avg = $checks ? self::load(['c_avg']) : [];
        $snap = $checks ? self::load(['tr_rt', 'tr_load', 'tr_wear', 'tr_bad']) : [];
        foreach ($checks as $c) {
            $id = (int) $c['id'];
            [$grp, $icon, $hb, $unit, $d, $eff] = self::CHECKS[$c['type']];
            $warn = $c['warn_threshold'] !== null ? (float) $c['warn_threshold'] : null;
            $crit = $c['crit_threshold'] !== null ? (float) $c['crit_threshold'] : null;
            $base = ['grp' => $grp, 'icon' => $icon, 'hb' => $hb, 'unit' => $unit, 'd' => $d, 'eff' => $eff, 'sub' => $c['name'] . ' · ' . $c['host'],
                'go' => "check:$id", 'host_id' => (int) $c['host_id']];
            $o = match ($c['type']) {
                'disk' => ['title' => t('Disk {m}', ['m' => $c['target'] ?: '/']), 'limit' => $crit ?? 95.0, 'limit_lbl' => t('vol'), 'eta_lbl' => t('vol'),
                    'advice' => t('Ruim op of maak hem groter; kijk welke map groeit.')],
                'ram' => ['title' => t('Geheugen (RAM)'), 'limit' => $crit ?? 95.0, 'limit_lbl' => t('vol'), 'eta_lbl' => t('vol'),
                    'advice' => t('Loopt langzaam op: dat kan een geheugenlek zijn. Kijk welk proces of welke container groeit, of herstart hem.')],
                'smart' => ['title' => t('Schijftemperatuur'), 'limit' => $crit ?? $warn ?? 55.0, 'limit_lbl' => t('te warm'), 'eta_lbl' => t('te warm'),
                    'advice' => t('Kijk naar stof, de ventilator of een warmere plek.')],
                'ups' => ['title' => t('UPS-accu: lading'), 'limit' => $warn, 'limit_lbl' => t('onder de waarschuwingsgrens'), 'eta_lbl' => t('te laag'),
                    'advice' => t('De accu laadt minder ver op: een teken van slijtage.')],
                default => ['title' => t('Responstijd {n}', ['n' => $c['name']]), 'sub' => strtoupper((string) $c['type']) . ' · ' . $c['host'],
                    'limit' => $warn, 'limit_lbl' => t('boven de waarschuwingsgrens'), 'eta_lbl' => t('te traag'),
                    'advice' => t('Vaak een volle database, te weinig geheugen, een trage schijf of een app die bijgewerkt moet worden.')],
            };
            $add(self::judge(self::daySeries($avg[$id] ?? []), ['id' => "c$id"] + $o + $base));
            if ($c['type'] === 'ups' && isset($snap["tr_rt:$id"])) {
                // Looptijd bij dezelfde belasting: looptijd × belasting / de gewone belasting
                $loads = array_values(array_filter($snap["tr_load:$id"] ?? [], static fn($v) => $v > 0));
                $ref = $loads ? self::median($loads) : 0;
                $rt = [];
                foreach ($snap["tr_rt:$id"] as $day => $v) {
                    $l = $snap["tr_load:$id"][$day] ?? 0;
                    $rt[$day] = $ref > 0 && $l > 0 ? $v * $l / $ref : $v;
                }
                $min = (float) setting('trend_ups_min', '10');
                $add(self::judge(self::daySeries($rt), ['id' => "ur$id", 'title' => t('UPS-accu: looptijd'), 'unit' => ' min', 'd' => 0, 'hb' => false, 'eff' => [5, 0.5],
                    'limit' => $min, 'limit_lbl' => t('onder {n} min', ['n' => (int) $min]), 'eta_lbl' => t('onder {n} min', ['n' => (int) $min]),
                    'advice' => $ref > 0 ? t('Bij dezelfde belasting ({l}%) houdt de accu het korter vol: plan een nieuwe accu.', ['l' => (int) round($ref)])
                        : t('De accu houdt het korter vol: plan een nieuwe accu.')] + $base));
            }
            if ($c['type'] === 'smart') {
                if (isset($snap["tr_wear:$id"])) {
                    $add(self::judge(self::daySeries($snap["tr_wear:$id"]), ['id' => "sw$id", 'title' => t('SSD-slijtage'), 'icon' => '💾', 'unit' => '%', 'd' => 0, 'eff' => [null, 0.5],
                        'limit' => 90.0, 'limit_lbl' => t('90% versleten'), 'eta_lbl' => t('90% versleten'),
                        'advice' => t('Plan een vervanging voordat hij op is, en kijk wat er zoveel schrijft (logs, databases, swap).')] + $base));
                }
                if (isset($snap["tr_bad:$id"])) {
                    $add(self::judge(self::daySeries($snap["tr_bad:$id"]), ['id' => "sb$id", 'title' => t('Vervangen sectoren'), 'icon' => '🧲', 'unit' => '', 'd' => 0, 'eff' => [null, 0.5],
                        'advice' => t('Er komen beschadigde plekken bij: maak een back-up en vervang de schijf.')] + $base));
                }
            }
        }
        // Internetsnelheid (de snelheidstest van RzSense)
        $sp = [];
        foreach (q('SELECT DATE(at) AS d, AVG(down) AS dn, AVG(up) AS up, AVG(lat) AS lat FROM inet_speed WHERE err IS NULL AND at >= ? GROUP BY d',
            [date('Y-m-d', strtotime('-' . self::DAYS . ' day'))]) as $r) {
            foreach (['dn', 'up', 'lat'] as $k) {
                if ($r[$k] !== null) {
                    $sp[$k][(string) $r['d']] = (float) $r[$k];
                }
            }
        }
        $wp = (float) setting('inet_warn_pct', '70') / 100;
        foreach ([['dn', t('Internetsnelheid (download)'), (float) setting('inet_down', '1000') * $wp], ['up', t('Internetsnelheid (upload)'), (float) setting('inet_up', '1000') * $wp]] as [$k, $title, $lim]) {
            if (isset($sp[$k])) {
                $add(self::judge(self::daySeries($sp[$k]), ['id' => "in_$k", 'grp' => 'slow', 'icon' => '🌐', 'title' => $title, 'sub' => t('snelheidstest van RzSense'), 'unit' => ' Mbit/s',
                    'd' => 0, 'hb' => false, 'eff' => [5, 5], 'limit' => $lim > 0 ? $lim : null, 'limit_lbl' => t('onder de waarschuwingsgrens'), 'eta_lbl' => t('te traag'),
                    'advice' => t('Klopt dat met je abonnement? Meld het bij je provider, met deze grafiek erbij.'), 'go' => 'dashboard']));
            }
        }
        if (isset($sp['lat'])) {
            $add(self::judge(self::daySeries($sp['lat']), ['id' => 'in_lat', 'grp' => 'slow', 'icon' => '⏱️', 'title' => t('Vertraging van de internetlijn'), 'sub' => t('snelheidstest van RzSense'),
                'unit' => ' ms', 'd' => 0, 'eff' => [15, 2], 'advice' => t('De internetlijn reageert trager. Kijk of het op bepaalde uren is (Analyse → Netwerk) en meld het anders bij je provider.'), 'go' => 'dashboard']));
        }
        // Capaciteit van RzSense zelf
        $own = self::load(['tr_db', 'tr_logs', 'tr_cron', 'tr_hard', 'vuln_open']);
        $free = null;
        try {
            $dir = (string) (q('SELECT @@datadir')->fetchColumn() ?: '');
            $free = @disk_free_space(is_dir($dir) ? $dir : SM_ROOT) ?: null;
        } catch (Throwable) {
        }
        foreach ([['tr_db:0', 'own_db', '🗄️', t('Database van RzSense'), t('Kijk bij Instellingen → Database welke gegevens je korter kunt bewaren.')],
            ['tr_logs:0', 'own_logs', '📜', t('Logs in RzSense'), t('Zet bij Instellingen → Logs de bewaartijd korter of verzamel minder bronnen.')]] as [$k, $id, $icon, $title, $adv]) {
            if (isset($own[$k])) {
                $last = end($own[$k]);
                $add(self::judge(self::daySeries($own[$k]), ['id' => $id, 'grp' => 'cap', 'icon' => $icon, 'title' => $title, 'sub' => 'RzSense', 'bytes' => true, 'eff' => [10, 1e7],
                    'limit' => $free ? $last + $free : null, 'limit_lbl' => t('schijf vol'), 'eta_lbl' => t('schijf vol'), 'advice' => $adv, 'go' => 'settings/database']));
            }
        }
        // Betrouwbaarheid: storingen en wisselingen per week, de duur van een controleronde
        foreach ([['c_tocrit', 'rel_fail', '⚡', t('Storingen per week'), t('Kijk bij Analyse → Betrouwbaarheid welke checks het vaakst storen.')],
            ['c_chg', 'rel_flap', '🔀', t('Wisselingen per week'), t('Checks wisselen steeds vaker tussen goed en fout: zoek de oorzaak of zet "melden na N" hoger.')]] as [$m, $id, $icon, $title, $adv]) {
            $s = self::load([$m], true)[0] ?? [];
            if ($s) {
                $add(self::judge(self::weekSeries($s), ['id' => $id, 'grp' => 'rel', 'icon' => $icon, 'title' => $title, 'sub' => t('alle checks'), 'bars' => true, 'eff' => [20, 2],
                    'advice' => $adv]));
            }
        }
        if (isset($own['tr_cron:0'])) {
            $add(self::judge(self::daySeries($own['tr_cron:0']), ['id' => 'rel_cron', 'grp' => 'rel', 'icon' => '⏱️', 'title' => t('Duur van een controleronde'), 'sub' => 'RzSense',
                'unit' => ' s', 'd' => 1, 'eff' => [15, 1], 'limit' => 55.0, 'limit_lbl' => t('langer dan een minuut'), 'eta_lbl' => t('te lang'),
                'advice' => t('Meer checks of een tragere server. Kijk bij Instellingen → Prestaties en fouten welke checks het langst duren.'), 'go' => 'settings/perf']));
        }
        // Beveiliging en onderhoud
        $ids = self::load(['ids_0', 'ids_1', 'ids_2'], true)[0] ?? [];
        if ($ids) {
            $add(self::judge(self::weekSeries($ids), ['id' => 'sec_ids', 'grp' => 'sec', 'icon' => '⚔️', 'title' => t('Aanvallen per week'), 'sub' => t('IDS-incidenten'), 'bars' => true,
                'eff' => [20, 3], 'advice' => t('Er wordt vaker aangevallen. Kijk bij IDS welke diensten het doelwit zijn, en of alles wat open staat ook open moet.'), 'go' => 'ids']));
        }
        $auth = self::load(['auth_fail'], true)[0] ?? [];
        if ($auth) {
            $add(self::judge(self::weekSeries($auth), ['id' => 'sec_auth', 'grp' => 'sec', 'icon' => '🔑', 'title' => t('Mislukte logins per week'), 'sub' => 'RzSense', 'bars' => true,
                'eff' => [25, 3], 'advice' => t('Vaker een fout wachtwoord op RzSense: kijk bij IDS → Toegang van welke adressen.'), 'go' => 'ids']));
        }
        if (isset($own['vuln_open:0'])) {
            $add(self::judge(self::daySeries($own['vuln_open:0']), ['id' => 'sec_vuln', 'grp' => 'sec', 'icon' => '🐞', 'title' => t('Openstaande kwetsbaarheden'), 'sub' => t('alle servers'),
                'unit' => '', 'eff' => [15, 2], 'advice' => t('De achterstand groeit: werk de servers bij (Kwetsbaarheden → bijwerken).'),
                'good' => t('De achterstand loopt terug.'), 'go' => 'vulns']));
        }
        if (isset($own['tr_hard:0'])) {
            $add(self::judge(self::daySeries($own['tr_hard:0']), ['id' => 'sec_hard', 'grp' => 'sec', 'icon' => '🧱', 'title' => t('Hardening-score'), 'sub' => t('gemiddeld over je servers'),
                'unit' => '/100', 'hb' => false, 'eff' => [null, 3], 'advice' => t('De score daalt: kijk bij IDS → Hardening wat er veranderd is.'), 'go' => 'ids']));
        }
        // Wat beweegt er: eerst wat binnenkort een grens raakt, dan de grootste consequente verandering
        $rank = static fn($x) => ($x['eta_days'] !== null ? max(0, 400 - $x['eta_days']) : 0) + abs($x['tau']) * min(abs($x['chg']), 100);
        $mov = array_values(array_filter($items, static fn($x) => $x['bad']));
        usort($mov, static fn($a, $b) => $rank($b) <=> $rank($a));
        $r = ['at' => date('Y-m-d H:i:s'), 'days' => self::DAYS, 'moving' => array_slice(array_map(static fn($x) => $x['id'], $mov), 0, 3), 'items' => $items];
        if ($save) {
            save_setting(self::cacheKey(), json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $r;
    }

    /** Voor de pagina: de laatste berekening (hooguit 6 uur oud), voor gebruikers zonder de servers die ze niet zien */
    public static function page(?array $hostIds = null, bool $fresh = false): array
    {
        $r = json_decode((string) setting(self::cacheKey(), ''), true);
        if ($fresh || !is_array($r) || strtotime((string) ($r['at'] ?? '')) < time() - 6 * 3600) {
            $r = self::compute();
        }
        if ($hostIds !== null) {
            $r['items'] = array_values(array_filter($r['items'], static fn($x) => $x['host_id'] === null || in_array((int) $x['host_id'], $hostIds, true)));
            $r['moving'] = array_values(array_filter($r['moving'], static fn($id) => in_array($id, array_column($r['items'], 'id'), true)));
        }
        $r['alert_days'] = (int) setting('trend_alert_days', '30');
        return $r;
    }

    /** De bovenste trends (voor het maandrapport) */
    public static function top(int $n = 3): array
    {
        $r = self::page();
        $out = [];
        foreach ($r['moving'] as $id) {
            foreach ($r['items'] as $x) {
                if ($x['id'] === $id) {
                    $out[] = $x;
                }
            }
        }
        return array_slice($out, 0, $n);
    }

    /** Melding als een trend binnen trend_alert_days de grens raakt; per trend hooguit één keer per 14 dagen */
    private static function notify(array $r): int
    {
        $days = (int) setting('trend_alert_days', '30');
        if ($days <= 0) {
            return 0;
        }
        $n = 0;
        foreach ($r['items'] as $x) {   // 1.237.0: "al gemeld" via Once (14 dagen per trend)
            if (!$x['bad'] || $x['eta_days'] === null || $x['eta_days'] > $days || !Once::claimFor('trends_sent', (string) $x['id'], 14 * 86400)) {
                continue;
            }
            $n++;
            $host = $x['host_id'] ? (string) (q('SELECT name FROM hosts WHERE id = ?', [$x['host_id']])->fetchColumn() ?: '') : null;
            Monitor::sysInfo('trend', $x['host_id'], $host, '📉 ' . t('TREND') . ': ' . $x['title'] . ($x['sub'] ? ' (' . $x['sub'] . ')' : ''),
                static fn() => $x['text'] . "\n" . t('Meer in RzSense: Analyse → Trends.'));
        }
        return $n;
    }
}
