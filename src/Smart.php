<?php
declare(strict_types=1);

/**
 * Slimme drempels (1.107.0): RzMon leert per check wat normaal is, per uur van de week (168 vakjes: maandag 3:00 is
 * anders dan dinsdag 14:00), en zet daar de drempels op.
 *
 * Modus per check (checks.thresh_mode):
 * - manual: de vaste drempels (warn_threshold / crit_threshold), zoals altijd;
 * - smart:  geleerde drempels; tot er 7 dagen gegevens zijn gelden de vaste;
 * - guard:  slim met vangnet: geleerd, maar vanaf guard_threshold altijd CRITICAL;
 * - auto:   een nieuwe check die nog niet beslist is: bij de eerste run wordt dat de standaard (Instellingen,
 *           thresh_default) als de check zich ervoor leent, anders manual. Bestaande checks kregen bij de update
 *           "manual" (een update verandert geen instellingen).
 *
 * Leren (elke nacht, een paar checks per cronminuut): de meetwaarden van de laatste 28 dagen, robuust (mediaan,
 * percentielen, MAD); een vakje leent van het uur ervoor en erna, en bij te weinig metingen van alle dagen op dat uur.
 * Meetwaarden van een storing zonder waarde tellen niet mee. Een geleerde drempel verschuift hooguit 10% per nacht.
 * Een normaalwaarde die in twee weken flink stijgt (een sluipend lek), geeft een ℹ️-melding: anders zou RzMon het
 * gewoon gaan accepteren.
 *
 * Disks doen niet mee: een disk heeft geen "normaal per uur", en leren zou een vollopende disk volgen. Daar blijven de
 * vaste drempels met de voorspelling.
 */
final class Smart
{
    public const MODES = ['manual', 'smart', 'guard'];
    public const SENS = ['calm', 'normal', 'sharp'];
    public const MIN_DAYS = 7;
    private const WINDOW_DAYS = 28;
    private const MAX_STEP = 0.10;     // hooguit 10% per nacht
    private const DRIFT = 0.25;        // normaalwaarde 25% hoger dan twee weken geleden: melden

    /**
     * Hoe er geleerd wordt, per soort meting: [manier, warning minstens, critical minstens, stap warning, stap critical, vangnet].
     * add: warning = 95e percentiel + stap (punten); mul: warning = 95e percentiel × stap. Critical ligt daar weer boven.
     * De ondergrenzen voorkomen onzin als "CPU 15% = warning" bij een server die normaal op 3% draait.
     * Vangnet: standaardwaarde voor "altijd CRITICAL vanaf" (null = geen).
     */
    private const SPEC = [
        'ping' => ['mul', 20, 200, 2.0, 3.0, 1000],
        'tcp' => ['mul', 50, 500, 2.0, 3.0, 3000],
        'http' => ['mul', 300, 1500, 2.0, 2.5, 10000],
        'cpu' => ['add', 50, 80, 10, 10, null],
        'ram' => ['add', 50, 80, 10, 10, 98],
        'snmp:cpu' => ['add', 50, 80, 10, 10, null],
        'snmp:mem' => ['add', 50, 80, 10, 10, 98],
        'snmp:if' => ['add', 40, 70, 15, 15, 98],
        'snmp:load' => ['mul', 1, 2, 1.5, 1.5, null],
        'snmp:temp' => ['add', 50, 65, 8, 10, 85],
        'snmp:ups_load' => ['add', 60, 85, 10, 10, 98],
        'snmp:ups_temp' => ['add', 32, 40, 5, 5, 45],
        'snmp:syno_system' => ['add', 50, 60, 8, 8, 75],
        'snmp:syno_disk' => ['add', 42, 52, 6, 6, 62],
        'snmp:unifi_cu' => ['add', 40, 70, 15, 15, null],
        'snmp:unifi_quality' => ['add', 10, 20, 5, 10, null],
    ];

    /** Gevoeligheid: factor op de stappen en op de spreiding (rustig = ruimer) */
    private const SENS_F = ['calm' => 1.5, 'normal' => 1.0, 'sharp' => 0.7];

    private static array $models = [];

    /** Sleutel in SPEC, of null als deze check niet kan leren */
    public static function key(array $check): ?string
    {
        $type = (string) ($check['type'] ?? '');
        $k = $type === 'snmp' ? 'snmp:' . ($check['metric'] ?? '') : $type;
        if (!isset(self::SPEC[$k]) || ($check['reverse_ok'] ?? null) !== null) {
            return null;   // omgekeerde checks gaan over werkt het wel/niet, niet over een waarde
        }
        return $k;
    }

    public static function eligible(array $check): bool
    {
        return self::key($check) !== null;
    }

    /** Standaard vangnet voor dit soort check */
    public static function guardDefault(array $check): ?float
    {
        $k = self::key($check);
        return $k !== null && self::SPEC[$k][5] !== null ? (float) self::SPEC[$k][5] : null;
    }

    public static function unit(array $check): string
    {
        $k = (string) self::key($check);
        return match (true) {
            in_array($k, ['ping', 'tcp', 'http'], true) => 'ms',
            in_array($k, ['snmp:temp', 'snmp:ups_temp', 'snmp:syno_system', 'snmp:syno_disk'], true) => '°C',
            $k === 'snmp:load' => '',
            default => '%',
        };
    }

    /** Modus zoals hij geldt (auto → de standaard bij de eerste run) */
    public static function mode(array $check): string
    {
        $m = (string) ($check['thresh_mode'] ?? 'manual');
        return in_array($m, self::MODES, true) ? $m : 'manual';
    }

    private static function sens(array $check): string
    {
        $s = (string) ($check['smart_sens'] ?? '');
        return in_array($s, self::SENS, true) ? $s : (in_array((string) setting('smart_sens', 'normal'), self::SENS, true) ? (string) setting('smart_sens', 'normal') : 'normal');
    }

    /** Een nieuwe check (auto) krijgt bij de eerste run de standaard uit Instellingen */
    private static function resolveAuto(array $row): array
    {
        $def = (string) setting('thresh_default', 'guard');
        $mode = self::eligible($row) && in_array($def, self::MODES, true) ? $def : 'manual';
        $guard = $row['guard_threshold'] ?? null;
        if ($mode === 'guard' && ($guard === null || $guard === '')) {
            $guard = self::guardDefault($row);
        }
        try {
            q("UPDATE checks SET thresh_mode = ?, guard_threshold = ? WHERE id = ? AND thresh_mode = 'auto'", [$mode, $guard, $row['id']]);
        } catch (Throwable) {
        }
        $row['thresh_mode'] = $mode;
        $row['guard_threshold'] = $guard;
        return $row;
    }

    public static function model(int $checkId): ?array
    {
        if (!array_key_exists($checkId, self::$models)) {
            $j = q('SELECT model FROM check_smart WHERE check_id = ?', [$checkId])->fetchColumn();
            self::$models[$checkId] = $j ? (json_decode((string) $j, true) ?: null) : null;
        }
        return self::$models[$checkId];
    }

    /** Uur van de week: maandag 0:00 = 0 … zondag 23:00 = 167 */
    public static function how(?int $ts = null): int
    {
        $ts ??= time();
        return ((int) date('N', $ts) - 1) * 24 + (int) date('G', $ts);
    }

    /**
     * De drempels die nu gelden (voor het uitvoeren van de check): bij smart/guard met genoeg gegevens de geleerde
     * van dit uur. Zet ook _smart (voor de uitleg in het bericht).
     */
    public static function apply(array $row): array
    {
        if (($row['thresh_mode'] ?? 'manual') === 'auto' && !empty($row['id'])) {
            $row = self::resolveAuto($row);
        }
        $mode = self::mode($row);
        if ($mode === 'manual' || !self::eligible($row) || empty($row['id'])) {
            return $row;
        }
        $m = self::model((int) $row['id']);
        if (!$m || (int) ($m['days'] ?? 0) < self::MIN_DAYS) {
            $row['_smart'] = ['learning' => (int) ($m['days'] ?? 0)];
            return $row;
        }
        $h = self::how();
        [$w, $c] = [(float) $m['w'][$h], (float) $m['c'][$h]];
        if ($mode === 'guard' && is_numeric($row['guard_threshold'] ?? null)) {
            $c = min($c, (float) $row['guard_threshold']);
            $w = min($w, $c);
        }
        $row['warn_threshold'] = $w;
        $row['crit_threshold'] = $c;
        $row['_smart'] = ['w' => $w, 'c' => $c, 'lo' => $m['lo'][$h] ?? null, 'hi' => $m['hi'][$h] ?? null];
        return $row;
    }

    /** Bij een warning of critical door een geleerde drempel: wat is hier normaal (staat dan ook in de melding) */
    public static function annotate(array $row, array $res): array
    {
        $s = $row['_smart'] ?? null;
        if (!$s || !isset($s['w']) || !in_array($res['status'], [STATUS_WARN, STATUS_CRIT], true) || $res['value'] === null || ($res['value'] < $s['w'])) {
            return $res;
        }
        $u = self::unit($row);
        $res['message'] .= ' · 🧠 ' . t('normaal op dit uur {lo}–{hi}{u}', ['lo' => self::fmt($s['lo']), 'hi' => self::fmt($s['hi']), 'u' => $u]);
        return $res;
    }

    public static function fmt(mixed $v): string
    {
        if (!is_numeric($v)) {
            return '?';
        }
        $v = (float) $v;
        return I18n::dec((string) round($v, abs($v) >= 10 ? 0 : 1));
    }

    // ------------------------------------------------------------------ leren

    /**
     * Elke nacht (vanaf 4:00) alle checks die kunnen leren opnieuw doorrekenen, een paar per cronminuut,
     * zodat de checks zelf er niet op hoeven te wachten.
     */
    public static function cron(int $budgetSec = 15): void
    {
        if ((int) date('G') < 4) {
            return;
        }
        $t0 = microtime(true);
        $today = date('Y-m-d');
        $rows = q("SELECT c.*, s.model, s.learned_at FROM checks c LEFT JOIN check_smart s ON s.check_id = c.id
                   WHERE c.enabled = 1 AND c.type IN ('ping','tcp','http','cpu','ram','snmp') AND c.thresh_mode <> 'auto'
                   AND (s.learned_at IS NULL OR s.learned_at < ?) ORDER BY s.learned_at IS NOT NULL, s.learned_at, c.id LIMIT 200", [$today . ' 00:00:00'])->fetchAll();
        foreach ($rows as $r) {
            if (microtime(true) - $t0 > $budgetSec) {
                break;
            }
            if (!self::eligible($r)) {
                q('INSERT INTO check_smart (check_id, model, learned_at) VALUES (?, NULL, ?) ON DUPLICATE KEY UPDATE model = NULL, learned_at = VALUES(learned_at)', [$r['id'], now()]);
                continue;
            }
            try {
                self::learn($r);
            } catch (Throwable $e) {
                q('INSERT INTO check_smart (check_id, learned_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE learned_at = VALUES(learned_at)', [$r['id'], now()]);
                SelfCheck::log('warning', 'Smart::learn ' . $r['id'] . ': ' . $e->getMessage(), __FILE__, __LINE__);
            }
        }
    }

    /** Percentiel van een gesorteerde lijst (lineair tussen twee waarden) */
    private static function pct(array $s, float $p): float
    {
        $n = count($s);
        if ($n === 1) {
            return (float) $s[0];
        }
        $i = ($n - 1) * $p;
        $lo = (int) floor($i);
        $hi = min($n - 1, $lo + 1);
        return (float) $s[$lo] + ((float) $s[$hi] - (float) $s[$lo]) * ($i - $lo);
    }

    /** [mediaan, 10e, 95e percentiel, MAD] */
    private static function stats(array $v): array
    {
        sort($v);
        $med = self::pct($v, 0.5);
        $dev = array_map(static fn($x) => abs($x - $med), $v);
        sort($dev);
        return [$med, self::pct($v, 0.1), self::pct($v, 0.95), self::pct($dev, 0.5)];
    }

    /** Drempels uit de verdeling: [warning, critical] */
    private static function thresholds(string $key, string $sens, float $p95, float $mad): array
    {
        [$how, $wMin, $cMin, $wStep, $cStep] = self::SPEC[$key];
        $f = self::SENS_F[$sens];
        $s = 1.4826 * $mad * 3 * $f;   // ~3 standaardafwijkingen, robuust geschat
        if ($how === 'mul') {
            $w = max($wMin, $p95 * (1 + ($wStep - 1) * $f) + $s);
            $c = max($cMin, $w * (1 + ($cStep - 1) * $f));
        } else {
            $w = max($wMin, $p95 + max($wStep * $f, $s));
            $c = max($cMin, $w + max($cStep * $f, $s));
        }
        return [$w, $c];
    }

    /** Eén check doorrekenen en opslaan; bij smart/guard toepassen (hooguit 10% verschuiven) en vastleggen */
    public static function learn(array $check): ?array
    {
        $id = (int) $check['id'];
        $key = (string) self::key($check);
        $byHow = array_fill(0, 168, []);
        $byHour = array_fill(0, 24, []);
        $byDay = [];
        $all = $pts = [];
        $st = db()->prepare('SELECT WEEKDAY(created_at) * 24 + HOUR(created_at), value, DATE(created_at) FROM results
                             WHERE check_id = ? AND created_at >= NOW() - INTERVAL ' . self::WINDOW_DAYS . ' DAY AND value IS NOT NULL');
        $st->execute([$id]);
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $v = (float) $r[1];
            $byHow[(int) $r[0]][] = $v;
            $byHour[(int) $r[0] % 24][] = $v;
            $byDay[$r[2]][] = $v;
            $all[] = $v;
            $pts[] = [(int) $r[0], $v, $r[2]];
        }
        $st->closeCursor();
        $days = count($byDay);
        $old = self::storedModel($id);
        if (!$all) {
            q('INSERT INTO check_smart (check_id, model, learned_at) VALUES (?, NULL, ?) ON DUPLICATE KEY UPDATE model = NULL, learned_at = VALUES(learned_at)', [$id, now()]);
            unset(self::$models[$id]);
            return null;
        }
        $sens = self::sens($check);
        $gStats = self::stats($all);
        $m = ['v' => 1, 'days' => $days, 'n' => count($all), 'sens' => $sens, 'w' => [], 'c' => [], 'm' => [], 'lo' => [], 'hi' => []];
        $hourStats = [];
        // Stijgt de normaalwaarde sluipend (lek?), dan schuiven de drempels niet mee omhoog: daar gaat de ℹ️-melding over
        $drift = self::drift($byDay);
        $prevOk = $old && isset($old['w'][0]) && ($old['sens'] ?? $sens) === $sens && (int) ($old['days'] ?? 0) >= self::MIN_DAYS;
        for ($h = 0; $h < 168; $h++) {
            // Dit uur; te weinig metingen: plus het uur ervoor en erna (ook over de week heen); dan alle dagen op dit uur; dan alles
            $v = $byHow[$h];
            if (count($v) < 20) {
                $v = [...$byHow[($h + 167) % 168], ...$v, ...$byHow[($h + 1) % 168]];
            }
            if (count($v) < 20) {
                $hd = $h % 24;
                $hourStats[$hd] ??= count($pool = [...$byHour[($hd + 23) % 24], ...$byHour[$hd], ...$byHour[($hd + 1) % 24]]) >= 20 ? self::stats($pool) : $gStats;
                $s = $hourStats[$hd];
            } else {
                $s = self::stats($v);
            }
            [$med, $p10, $p95, $mad] = $s;
            [$w, $c] = self::thresholds($key, $sens, $p95, $mad);
            // Hooguit 10% per nacht verschuiven ten opzichte van de vorige keer (behalve als gevoeligheid veranderde)
            if ($prevOk) {
                $up = $drift ? 0.0 : self::MAX_STEP;
                $w = min(max($w, $old['w'][$h] * (1 - self::MAX_STEP)), $old['w'][$h] * (1 + $up));
                $c = min(max($c, $old['c'][$h] * (1 - self::MAX_STEP)), $old['c'][$h] * (1 + $up));
                $w = max($w, (float) self::SPEC[$key][1]);   // nooit onder de ondergrens
                $c = max($c, $w, (float) self::SPEC[$key][2]);
            }
            $m['w'][] = round($w, 2);
            $m['c'][] = round($c, 2);
            $m['m'][] = round($med, 2);
            $m['lo'][] = round($p10, 2);
            $m['hi'][] = round($p95, 2);
        }
        // Advies: op hoeveel dagen gaf de vaste drempel een warning, en hoeveel zou het slim zijn geweest
        $m['sim'] = self::simulate($check, $pts, $m);
        $m['drift'] = $drift;
        $m['at'] = now();
        q('INSERT INTO check_smart (check_id, model, learned_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE model = VALUES(model), learned_at = VALUES(learned_at)',
            [$id, json_encode($m), now()]);
        self::$models[$id] = $m;

        $mode = self::mode($check);
        if ($mode !== 'manual' && $days >= self::MIN_DAYS) {
            self::logChange($check, $old, $m);
        }
        if ($m['drift'] && $mode !== 'manual') {
            self::driftInfo($check, $m['drift']);
        }
        return $m;
    }

    private static function storedModel(int $id): ?array
    {
        $j = q('SELECT model FROM check_smart WHERE check_id = ?', [$id])->fetchColumn();
        return $j ? (json_decode((string) $j, true) ?: null) : null;
    }

    /** Dagen met een warning: met de vaste drempels en met de geleerde (op dezelfde meetwaarden) */
    private static function simulate(array $check, array $pts, array $m): array
    {
        $fixed = is_numeric($check['warn_threshold']) ? (float) $check['warn_threshold'] : null;
        $guard = self::mode($check) === 'guard' && is_numeric($check['guard_threshold'] ?? null) ? (float) $check['guard_threshold'] : null;
        $dF = $dS = [];
        foreach ($pts as [$h, $v, $d]) {
            if ($fixed !== null && $v >= $fixed) {
                $dF[$d] = true;
            }
            if ($v >= ($guard !== null ? min($m['w'][$h], $guard) : $m['w'][$h])) {
                $dS[$d] = true;
            }
        }
        return ['fixed' => $fixed === null ? null : count($dF), 'smart' => count($dS), 'days' => $m['days']];
    }

    /** Mediaan van de laatste 7 dagen tegen die van 14–28 dagen geleden: [oud, nieuw] als het flink steeg */
    private static function drift(array $byDay): ?array
    {
        $new = $old = [];
        $cut7 = date('Y-m-d', strtotime('-7 days'));
        $cut14 = date('Y-m-d', strtotime('-14 days'));
        $nNew = $nOld = 0;
        foreach ($byDay as $d => $vals) {
            if ($d >= $cut7) {
                array_push($new, ...$vals);
                $nNew++;
            } elseif ($d < $cut14) {
                array_push($old, ...$vals);
                $nOld++;
            }
        }
        if ($nNew < 3 || $nOld < 3) {
            return null;
        }
        sort($new);
        sort($old);
        $a = self::pct($old, 0.5);
        $b = self::pct($new, 0.5);
        if ($b > $a * (1 + self::DRIFT) && $b - $a >= max(5.0, abs($a) * 0.1)) {
            return [round($a, 1), round($b, 1)];
        }
        return null;
    }

    /** ℹ️ Normaalwaarde steeg flink (hooguit eens per week per check) */
    private static function driftInfo(array $check, array $d): void
    {
        $key = 'smart_drift_' . $check['id'];
        if (strtotime((string) setting($key, '')) > time() - 7 * 86400) {
            return;
        }
        save_setting($key, now());
        $host = q('SELECT id, name FROM hosts WHERE id = ?', [$check['host_id']])->fetch();
        $u = self::unit($check);
        Monitor::sysInfo('smart_drift', (int) $check['host_id'], (string) ($host['name'] ?? ''), 'ℹ️ INFO: ' . ($host['name'] ?? ''),
            fn() => t('🧠 {c}: de normaalwaarde stijgt, van {a}{u} naar {b}{u} in twee weken. Een lek of groeiende belasting? RzMon past de drempels hier niet zomaar op aan.',
                ['c' => $check['name'], 'a' => self::fmt($d[0]), 'b' => self::fmt($d[1]), 'u' => $u]));
    }

    /** Wijzigingslogboek: alleen als de drempels merkbaar verschoven (of het leren net klaar is) */
    private static function logChange(array $check, ?array $old, array $new): void
    {
        $range = static fn(array $a) => self::fmt(min($a)) . '–' . self::fmt(max($a));
        $first = !$old || (int) ($old['days'] ?? 0) < self::MIN_DAYS;
        if (!$first) {
            $diff = 0.0;
            foreach ($new['w'] as $h => $w) {
                $diff = max($diff, abs($w - ($old['w'][$h] ?? $w)) / max(abs((float) ($old['w'][$h] ?? 1)), 0.01));
            }
            if ($diff < 0.05) {
                return;
            }
        }
        $host = (string) q('SELECT name FROM hosts WHERE id = ?', [$check['host_id']])->fetchColumn();
        $u = self::unit($check);
        $sum = static fn(?array $m) => $m && (int) ($m['days'] ?? 0) >= self::MIN_DAYS ? ['warning' => $range($m['w']) . $u, 'critical' => $range($m['c']) . $u, '_model' => ['w' => $m['w'], 'c' => $m['c'], 'days' => $m['days'], 'sens' => $m['sens'] ?? 'normal']] : null;
        ChangeLog::record('check', (int) $check['id'], 'smart', ($first ? t('🧠 Slimme drempels actief: {c} op {h}', ['c' => $check['name'], 'h' => $host])
            : t('🧠 Slimme drempels bijgesteld: {c} op {h}', ['c' => $check['name'], 'h' => $host])), $sum($old), $sum($new));
    }

    /** Terugzetten vanuit het wijzigingslogboek: de geleerde drempels van daarvoor (RzMon leert daarna verder) */
    public static function restore(int $checkId, array $before): string
    {
        $m = self::storedModel($checkId);
        if (!$m || !isset($before['_model']['w'])) {
            throw new InvalidArgumentException(t('Dit kan niet (meer) worden teruggezet'));
        }
        $m['w'] = $before['_model']['w'];
        $m['c'] = $before['_model']['c'];
        q('UPDATE check_smart SET model = ? WHERE check_id = ?', [json_encode($m), $checkId]);
        return t('Vorige slimme drempels teruggezet; RzMon leert vannacht verder (zet de check op Handmatig om dat te stoppen)');
    }

    // ------------------------------------------------------------------ voor de interface

    /** Samenvatting voor de popup van een check: band en drempels per uur (168), wat nu geldt, advies */
    public static function info(array $check): ?array
    {
        if (!self::eligible($check)) {
            return null;
        }
        $m = self::model((int) $check['id']);
        $mode = (string) ($check['thresh_mode'] ?? 'manual');
        $out = ['mode' => $mode, 'eligible' => true, 'unit' => self::unit($check), 'min_days' => self::MIN_DAYS, 'days' => (int) ($m['days'] ?? 0),
            'guard_default' => self::guardDefault($check), 'sens' => $check['smart_sens'] ?? null, 'how' => self::how()];
        if ($m) {
            foreach (['w', 'c', 'lo', 'hi', 'm'] as $k) {
                $out[$k] = $m[$k];
            }
            $out['sim'] = $m['sim'] ?? null;
            $out['drift'] = $m['drift'] ?? null;
            $out['at'] = $m['at'] ?? null;
        }
        return $out;
    }

    /**
     * Voorstel (bestaande checks blijven handmatig tot je ze omzet): handmatige checks met genoeg gegevens,
     * waar slim minder dagen met een warning had gegeven. Meeste winst bovenaan.
     */
    public static function proposals(int $limit = 100): array
    {
        $out = [];
        foreach (q("SELECT c.id, c.name, c.type, c.metric, c.reverse_ok, c.host_id, h.name AS host_name, s.model FROM checks c JOIN hosts h ON h.id = c.host_id
                    JOIN check_smart s ON s.check_id = c.id WHERE c.enabled = 1 AND c.thresh_mode = 'manual' AND s.model IS NOT NULL") as $r) {
            if (!self::eligible($r)) {
                continue;
            }
            $m = json_decode((string) $r['model'], true) ?: [];
            if ((int) ($m['days'] ?? 0) < self::MIN_DAYS) {
                continue;
            }
            $sim = $m['sim'] ?? [];
            $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'host' => $r['host_name'], 'host_id' => (int) $r['host_id'],
                'fixed' => $sim['fixed'] ?? null, 'smart' => (int) ($sim['smart'] ?? 0), 'days' => (int) $m['days'],
                'gain' => (int) ($sim['fixed'] ?? 0) - (int) ($sim['smart'] ?? 0)];
        }
        usort($out, static fn($a, $b) => [$b['gain'], $a['host'], $a['name']] <=> [$a['gain'], $b['host'], $b['name']]);
        return array_slice($out, 0, $limit);
    }

    /**
     * Uit het checkformulier: modus, vangnet en gevoeligheid (alleen wat meegestuurd is).
     * Een check die niet kan leren, blijft handmatig.
     */
    public static function saveForm(int $id, array $in): void
    {
        if (!array_key_exists('thresh_mode', $in) && !array_key_exists('guard_threshold', $in) && !array_key_exists('smart_sens', $in)) {
            return;
        }
        $c = q('SELECT * FROM checks WHERE id = ?', [$id])->fetch();
        if (!$c) {
            return;
        }
        $mode = in_array($in['thresh_mode'] ?? '', self::MODES, true) ? $in['thresh_mode'] : (string) $c['thresh_mode'];
        if (!self::eligible($c)) {
            $mode = $mode === 'auto' ? 'auto' : 'manual';
        }
        $guard = array_key_exists('guard_threshold', $in)
            ? (is_numeric($in['guard_threshold']) ? (float) $in['guard_threshold'] : null)
            : $c['guard_threshold'];
        if ($mode === 'guard' && $guard === null && !array_key_exists('guard_threshold', $in)) {
            $guard = self::guardDefault($c);
        }
        $sens = array_key_exists('smart_sens', $in) ? (in_array($in['smart_sens'], self::SENS, true) ? $in['smart_sens'] : null) : $c['smart_sens'];
        q('UPDATE checks SET thresh_mode = ?, guard_threshold = ?, smart_sens = ? WHERE id = ?', [$mode, $guard, $sens, $id]);
        unset(self::$models[$id]);
    }

    /** Checks omzetten (voorstel of meerdere tegelijk): mode, met het standaard vangnet als dat nog leeg is */
    public static function setMode(array $ids, string $mode): int
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(t('Onbekende modus'));
        }
        $n = 0;
        foreach ($ids as $id) {
            $c = ChangeLog::checkRow((int) $id);
            if (!$c || ($mode !== 'manual' && !self::eligible($c))) {
                continue;
            }
            $guard = $c['guard_threshold'];
            if ($mode === 'guard' && ($guard === null || $guard === '')) {
                $guard = self::guardDefault($c);
            }
            q('UPDATE checks SET thresh_mode = ?, guard_threshold = ? WHERE id = ?', [$mode, $guard, $c['id']]);
            ChangeLog::record('check', (int) $c['id'], 'update', $c['_host'] . ' · ' . $c['name'] . ' (🧠 ' . t('drempelmodus') . ')', $c, ChangeLog::checkRow((int) $c['id']));
            $n++;
        }
        return $n;
    }
}
