<?php
declare(strict_types=1);

/**
 * Heartbeat-check (1.77.0, "passive check"): een script, cronjob of apparaat meldt zich zelf (ok, start, fail), via de
 * agent (rzmon-hb, versleuteld) of een geheime URL (hb.php). Komt er binnen het schema plus de speling niets binnen,
 * dan WARNING, na twee keer de speling CRITICAL. Een "fail" is altijd CRITICAL tot de volgende "ok". Met start en ok
 * meet RzMon de duur: CRITICAL als hij na de maximale duur nog loopt (zelf ingesteld, anders 2× het gemiddelde).
 *
 * Opties (checks.options): sched every|daily|weekly|cron, every (min), at (HH:MM), dow (0-6, 0 = zondag), cron,
 * grace (min), max (min, leeg = vanzelf), unit (eenheid van een meegestuurde waarde), ext (URL ook van buiten).
 * Toestand per check in hb_state: start, ok, fail, end, msg, fail_msg, value, dur (laatste duur), durs (laatste 10), first.
 */
final class Heartbeat
{
    public const KINDS = ['start', 'ok', 'fail'];
    public const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    // ------------------------------------------------------------------ opties

    /** Opties uit het formulier netjes maken; string = foutmelding */
    public static function clean(array $in): array|string
    {
        $sched = in_array($in['hb_sched'] ?? '', ['every', 'daily', 'weekly', 'cron'], true) ? $in['hb_sched'] : 'every';
        $o = ['sched' => $sched, 'grace' => max(1, min(10080, (int) ($in['hb_grace'] ?? 60)))];
        if ($sched === 'every') {
            $o['every'] = max(1, min(527040, (int) ($in['hb_every'] ?? 1440)));
        } else {
            if ($sched !== 'cron') {
                $at = (string) ($in['hb_at'] ?? '03:00');
                if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $at)) {
                    return t('Geef een tijd als 03:00');
                }
                $o['at'] = $at;
                if ($sched === 'weekly') {
                    $o['dow'] = max(0, min(6, (int) ($in['hb_dow'] ?? 0)));
                }
            } else {
                $cron = trim(preg_replace('/\s+/', ' ', (string) ($in['hb_cron'] ?? '')));
                if (self::parseCron($cron) === null) {
                    return t('Het cron-schema klopt niet. Gebruik 5 velden: minuut uur dag maand weekdag, bv. 30 2 * * 1-5');
                }
                $o['cron'] = $cron;
            }
        }
        $max = trim((string) ($in['hb_max'] ?? ''));
        if ($max !== '') {
            $o['max'] = max(1, min(10080, (int) $max));
        }
        $unit = mb_substr(trim((string) ($in['hb_unit'] ?? '')), 0, 12);
        if ($unit !== '') {
            $o['unit'] = $unit;
        }
        if (!empty($in['hb_ext'])) {
            $o['ext'] = 1;
        }
        return $o;
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Het schema als cron-regel (dagelijks en wekelijks ook), null bij "elke X minuten" */
    private static function cronOf(array $o): ?string
    {
        if (($o['sched'] ?? 'every') === 'every') {
            return null;
        }
        if ($o['sched'] === 'cron') {
            return (string) ($o['cron'] ?? '');
        }
        [$h, $m] = array_map('intval', explode(':', (string) ($o['at'] ?? '03:00')));
        return "$m $h * * " . ($o['sched'] === 'weekly' ? (int) ($o['dow'] ?? 0) : '*');
    }

    /** Schema in woorden, voor het bericht en de interface */
    public static function describe(array $o): string
    {
        $days = [t('zondag'), t('maandag'), t('dinsdag'), t('woensdag'), t('donderdag'), t('vrijdag'), t('zaterdag')];
        return match ($o['sched'] ?? 'every') {
            'daily' => t('elke dag om {t}', ['t' => $o['at']]),
            'weekly' => t('elke {d} om {t}', ['d' => $days[(int) ($o['dow'] ?? 0)], 't' => $o['at']]),
            'cron' => t('schema {c}', ['c' => $o['cron']]),
            default => t('elke {d}', ['d' => SnmpMonitor::duration((int) ($o['every'] ?? 1440) * 60)]),
        };
    }

    // ------------------------------------------------------------------ cron (5 velden, lokale tijd)

    /** @return array<int, array<int, true>>|null per veld de toegestane waarden; null = ongeldig */
    public static function parseCron(string $cron): ?array
    {
        $f = explode(' ', trim($cron));
        if (count($f) !== 5) {
            return null;
        }
        $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
        $out = [];
        foreach ($f as $i => $field) {
            [$lo, $hi] = $ranges[$i];
            $set = [];
            foreach (explode(',', $field) as $part) {
                if (!preg_match('#^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$#', $part, $m)) {
                    return null;
                }
                $a = $m[1] === '*' ? $lo : (int) $m[2];
                $b = $m[1] === '*' ? $hi : (isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $a);
                $step = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
                if ($m[1] !== '*' && (!isset($m[3]) || $m[3] === '') && $step > 1) {
                    $b = $hi;   // "5/15" = vanaf 5 elke 15
                }
                if ($a < $lo || $b > $hi || $a > $b || $step < 1) {
                    return null;
                }
                for ($v = $a; $v <= $b; $v += $step) {
                    $set[$i === 4 && $v === 7 ? 0 : $v] = true;
                }
            }
            $out[$i] = $set;
        }
        $out['dom_any'] = $f[2] === '*';
        $out['dow_any'] = $f[4] === '*';
        return $out;
    }

    private static function dayMatches(array $c, int $y, int $mo, int $d): bool
    {
        if (!isset($c[3][$mo])) {
            return false;
        }
        $dow = (int) date('w', mktime(12, 0, 0, $mo, $d, $y));
        $domOk = isset($c[2][$d]);
        $dowOk = isset($c[4][$dow]);
        // Zoals cron: zijn dag én weekdag beperkt, dan is één van beide genoeg
        return $c['dom_any'] && $c['dow_any'] ? true : ($c['dom_any'] ? $dowOk : ($c['dow_any'] ? $domOk : $domOk || $dowOk));
    }

    /** Laatste moment volgens het schema op of vóór $t (null = binnen ruim een jaar niets) */
    public static function prevRun(string $cron, int $t): ?int
    {
        $c = self::parseCron($cron);
        if ($c === null) {
            return null;
        }
        $hours = array_keys($c[1]);
        rsort($hours);
        $mins = array_keys($c[0]);
        rsort($mins);
        for ($i = 0; $i <= 400; $i++) {
            $day = strtotime("-$i day", $t);
            [$y, $mo, $d] = array_map('intval', explode('-', date('Y-n-j', $day)));
            if (!self::dayMatches($c, $y, $mo, $d)) {
                continue;
            }
            foreach ($hours as $h) {
                foreach ($mins as $m) {
                    $at = mktime($h, $m, 0, $mo, $d, $y);
                    if ($at <= $t) {
                        return $at;
                    }
                }
            }
        }
        return null;
    }

    /** Eerstvolgende moment volgens het schema na $t */
    public static function nextRun(string $cron, int $t): ?int
    {
        $c = self::parseCron($cron);
        if ($c === null) {
            return null;
        }
        $hours = array_keys($c[1]);
        sort($hours);
        $mins = array_keys($c[0]);
        sort($mins);
        for ($i = 0; $i <= 400; $i++) {
            $day = strtotime("+$i day", $t);
            [$y, $mo, $d] = array_map('intval', explode('-', date('Y-n-j', $day)));
            if (!self::dayMatches($c, $y, $mo, $d)) {
                continue;
            }
            foreach ($hours as $h) {
                foreach ($mins as $m) {
                    $at = mktime($h, $m, 0, $mo, $d, $y);
                    if ($at > $t) {
                        return $at;
                    }
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ toestand

    private static function state(int $checkId): array
    {
        $v = q('SELECT data FROM hb_state WHERE check_id = ?', [$checkId])->fetchColumn();
        return $v !== false ? (json_decode((string) $v, true) ?: []) : [];
    }

    private static function save(int $checkId, array $s): void
    {
        q('INSERT INTO hb_state (check_id, data, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()',
            [$checkId, json_encode($s, JSON_UNESCAPED_UNICODE)]);
    }

    /** Een melding van het script vastleggen (via de URL of de agent); $at = wanneer het echt gebeurde */
    public static function event(int $checkId, string $kind, int $at, ?float $value = null, string $msg = ''): void
    {
        $s = self::state($checkId);
        $s['first'] ??= $at;
        $msg = mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', $msg)), 0, 300);
        if ($kind === 'start') {
            $s['start'] = $at;
        } else {
            // Klaar (gelukt of mislukt): duur vanaf de laatste start, als die bij deze ronde hoort
            if (!empty($s['start']) && $s['start'] <= $at && $at - $s['start'] < 31 * 86400) {
                $s['dur'] = $at - (int) $s['start'];
                if ($kind === 'ok') {
                    $s['durs'] = array_slice([...($s['durs'] ?? []), $s['dur']], -10);
                }
            } else {
                unset($s['dur']);
            }
            $s['start'] = null;
            $s['end'] = $at;
            $s[$kind] = $at;
            $s['last'] = $kind;   // wat de laatste uitslag was (ok en fail kunnen in dezelfde seconde vallen)
            $s['msg'] = $msg;
            if ($kind === 'fail') {
                $s['fail_msg'] = $msg;
            }
            $s['value'] = $value;
            // 1.108.0: ritme leren: wanneer hij meestal binnenkomt, en de meegestuurde waarde (bv. de grootte van de back-up)
            if ($kind === 'ok') {
                $s['oks'] = array_slice([...($s['oks'] ?? []), $at], -30);
                if ($value !== null) {
                    $s['vals'] = array_slice([...($s['vals'] ?? []), $value], -30);
                }
            }
        }
        self::save($checkId, $s);
    }

    /**
     * Meldingen van de agent (rzmon-hb op de server, versleuteld meegestuurd): [{n: naam, s: start|ok|fail, t: tijd, v, m}].
     * @return string[] namen zonder check (de agent zet ze in zijn log)
     */
    public static function fromAgent(array $host, array $items): array
    {
        $checks = [];
        foreach (q("SELECT id, target FROM checks WHERE host_id = ? AND type = 'heartbeat' AND enabled = 1", [$host['id']])->fetchAll() as $c) {
            $checks[strtolower($c['target'])] = (int) $c['id'];
        }
        $now = time();
        $done = [];
        $unknown = [];
        foreach (array_slice($items, 0, 200) as $it) {
            $n = (string) ($it['n'] ?? '');
            $kind = (string) ($it['s'] ?? '');
            if (!preg_match(self::NAME, $n) || !in_array($kind, self::KINDS, true)) {
                continue;
            }
            $id = $checks[strtolower($n)] ?? null;
            if ($id === null) {
                $unknown[$n] = true;
                continue;
            }
            $t = (int) ($it['t'] ?? $now);
            $t = $t > $now + 300 || $t < $now - 7 * 86400 ? $now : min($t, $now);   // klok van de server niet blind vertrouwen
            $v = isset($it['v']) && is_numeric($it['v']) ? (float) $it['v'] : null;
            self::event($id, $kind, $t, $v, (string) ($it['m'] ?? ''));
            $done[$id] = true;
        }
        foreach (array_keys($done) as $id) {
            Monitor::runCheckById($id);
        }
        return array_keys($unknown);
    }

    // ------------------------------------------------------------------ beoordelen

    private static function when(?int $t): string
    {
        if (!$t) {
            return t('nog nooit');
        }
        $day = date('Y-m-d', $t);
        $rel = [date('Y-m-d') => t('vandaag'), date('Y-m-d', strtotime('-1 day')) => t('gisteren'), date('Y-m-d', strtotime('+1 day')) => t('morgen')];
        return ($rel[$day] ?? date('d-m', $t)) . ' ' . date('H:i', $t);
    }

    /**
     * Komt hij ongeveer elke dag (tussen 20 en 28 uur ertussen), dan weet RzMon hoe laat meestal. Is het daar ruim voorbij
     * (minstens 20 minuten, of 3× de normale spreiding) en is er vandaag nog niets binnen: een tekst voor de warning.
     */
    private static function lateByRhythm(array $s, int $now): ?string
    {
        $oks = $s['oks'] ?? [];
        if (count($oks) < 5) {
            return null;
        }
        $gaps = [];
        for ($i = 1; $i < count($oks); $i++) {
            $gaps[] = $oks[$i] - $oks[$i - 1];
        }
        sort($gaps);
        $gap = $gaps[intdiv(count($gaps), 2)];
        if ($gap < 20 * 3600 || $gap > 28 * 3600) {
            return null;
        }
        $mins = array_map(static fn($t) => (int) date('G', $t) * 60 + (int) date('i', $t), $oks);
        if (max($mins) - min($mins) > 360) {
            return null;   // te verspreid (of rond middernacht): geen vast tijdstip
        }
        sort($mins);
        $med = $mins[intdiv(count($mins), 2)];
        $dev = array_map(static fn($m) => abs($m - $med), $mins);
        sort($dev);
        $tol = max(20, 3 * $dev[intdiv(count($dev), 2)]);
        $expected = strtotime(date('Y-m-d', $now) . ' 00:00:00') + $med * 60;
        $lastOk = (int) end($oks);
        if ($now > $expected + $tol * 60 && $lastOk < $expected - 6 * 3600) {
            return t('vandaag nog niet binnen: meestal rond {t}', ['t' => sprintf('%02d:%02d', intdiv($med, 60), $med % 60)]);
        }
        return null;
    }

    /** De laatste meegestuurde waarde veel lager (minder dan de helft) of hoger (meer dan 3×) dan normaal */
    private static function oddValue(array $s, string $unit): ?string
    {
        $vals = $s['vals'] ?? [];
        if (count($vals) < 6) {
            return null;
        }
        $last = (float) end($vals);
        $prev = array_slice($vals, 0, -1);
        sort($prev);
        $med = (float) $prev[intdiv(count($prev), 2)];
        if ($med <= 0) {
            return null;
        }
        $f = static fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . ($unit !== '' ? " $unit" : '');
        if ($last < $med * 0.5) {
            return t('veel minder dan normaal ({v}, meestal {m})', ['v' => $f($last), 'm' => $f($med)]);
        }
        if ($last > $med * 3) {
            return t('veel meer dan normaal ({v}, meestal {m})', ['v' => $f($last), 'm' => $f($med)]);
        }
        return null;
    }

    /** Status van een heartbeat-check op dit moment */
    public static function check(array $check): array
    {
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: ['sched' => 'every', 'every' => 1440, 'grace' => 60];
        $id = (int) $check['id'];
        $s = self::state($id);
        $now = time();
        if (!isset($s['first'])) {
            $s['first'] = $now;   // vanaf nu wordt er iets verwacht
            self::save($id, $s);
        }
        $grace = max(1, (int) ($o['grace'] ?? 60)) * 60;
        $durs = $s['durs'] ?? [];
        $avg = count($durs) >= 3 ? array_sum($durs) / count($durs) : null;
        $value = !empty($o['unit']) ? (isset($s['value']) ? (float) $s['value'] : null)
            : (isset($s['dur']) ? round($s['dur'] / 60, 1) : null);
        $res = static fn(int $st, string $msg) => ['status' => $st, 'value' => $value, 'message' => $msg];
        $last = t('laatste melding: {w}', ['w' => self::when($s['end'] ?? null)]) . (isset($s['dur']) ? ' (' . t('duur {d}', ['d' => SnmpMonitor::duration((int) $s['dur'])]) . ')' : '');

        // Mislukt: CRITICAL tot de volgende geslaagde melding
        if (($s['last'] ?? '') === 'fail') {
            return $res(STATUS_CRIT, '✘ ' . t('Mislukt {w}', ['w' => self::when((int) $s['fail'])]) . (!empty($s['fail_msg']) ? ': ' . $s['fail_msg'] : ''));
        }

        // Bezig (start zonder einde): goed, tenzij langer dan de maximale duur
        if (!empty($s['start']) && $s['start'] > ($s['end'] ?? 0)) {
            $run = $now - (int) $s['start'];
            $max = isset($o['max']) ? $o['max'] * 60 : ($avg !== null ? (int) max(300, 2 * $avg) : null);
            if ($max !== null && $run > $max) {
                return $res(STATUS_CRIT, '⏳ ' . t('Loopt al {d} (maximaal {m}): hangt het?', ['d' => SnmpMonitor::duration($run), 'm' => SnmpMonitor::duration($max)]));
            }
            return $res(STATUS_OK, '⏳ ' . t('Bezig sinds {w} ({d})', ['w' => self::when((int) $s['start']), 'd' => SnmpMonitor::duration($run)]));
        }

        // Op tijd? Telt vanaf de laatste geslaagde melding (of vanaf het aanmaken van de check)
        $ok = isset($s['ok']) ? (int) $s['ok'] : null;
        $cron = self::cronOf($o);
        $status = STATUS_OK;
        $msg = '';
        if ($cron === null) {
            $due = ($ok ?? (int) $s['first']) + (int) ($o['every'] ?? 1440) * 60;
            $late = $now - $due;
            $msg = $late <= 0 ? t('volgende verwacht vóór {w}', ['w' => self::when($due + $grace)]) : '';
        } else {
            $slot = self::prevRun($cron, $now);
            $met = $slot === null || (int) $s['first'] > $slot || ($ok !== null && $ok >= $slot - $grace);
            $late = $met ? 0 : $now - $slot;
            if ($met) {
                $next = self::nextRun($cron, $now);
                $msg = $next ? t('volgende verwacht om {w}', ['w' => self::when($next)]) : '';
            }
            $due = $slot ?? $now;
        }
        if ($late > 2 * $grace) {
            $status = STATUS_CRIT;
        } elseif ($late > $grace) {
            $status = STATUS_WARN;
        } elseif ($late > 0) {
            $msg = t('verwacht vóór {w}', ['w' => self::when($due + $grace)]);
        }
        if ($status !== STATUS_OK) {
            return $res($status, '⏰ ' . t('Te laat: niets ontvangen sinds {w} (verwacht {s})', ['w' => self::when($ok ?? (int) $s['first']), 's' => self::describe($o)])
                . ($ok === null ? ' · ' . t('nog nooit een melding ontvangen') : ''));
        }

        // Duurde de laatste keer veel langer dan normaal?
        $prev = count($durs) >= 4 ? array_sum(array_slice($durs, 0, -1)) / (count($durs) - 1) : null;
        if (isset($s['dur']) && (isset($o['max']) ? $s['dur'] > $o['max'] * 60 : ($prev !== null && $s['dur'] > max(300, 2 * $prev)))) {
            $status = STATUS_WARN;
            $last .= ' · ' . t('veel langer dan normaal ({n})', ['n' => SnmpMonitor::duration((int) (isset($o['max']) ? $o['max'] * 60 : $prev))]);
        }
        // 1.108.0: geleerd ritme en geleerde waarde (Instellingen → Monitoring → Advies: hb_learn)
        if (setting('hb_learn', '1') === '1' && $status === STATUS_OK) {
            if (($late = self::lateByRhythm($s, $now)) !== null) {
                $status = STATUS_WARN;
                $last .= ' · ' . $late;
            }
            if (($odd = self::oddValue($s, (string) ($o['unit'] ?? ''))) !== null) {
                $status = STATUS_WARN;
                $last .= ' · ' . $odd;
            }
        }
        // Meegestuurde waarde met drempels (hoger = slechter)
        if (!empty($o['unit']) && $value !== null) {
            $w = $check['warn_threshold'];
            $c = $check['crit_threshold'];
            $vs = $c !== null && $value >= (float) $c ? STATUS_CRIT : ($w !== null && $value >= (float) $w ? STATUS_WARN : STATUS_OK);
            $status = max($status, $vs);
            $last .= ' · ' . rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $o['unit'];
        }
        return $res($status, [STATUS_OK => '✔ ', STATUS_WARN => '⚠️ ', STATUS_CRIT => '✘ '][$status] . $last . (!empty($s['msg']) ? ' · ' . $s['msg'] : '') . ($msg !== '' ? ' · ' . $msg : ''));
    }
}
