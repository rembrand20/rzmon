<?php
declare(strict_types=1);

/**
 * Slimme voorstellen (1.108.0). RzMon kijkt elke nacht naar de geschiedenis en stelt voor; jij past toe of negeert
 * (Instellingen → Monitoring → Advies). Een genegeerd voorstel komt niet terug (zelfde vingerafdruk).
 * - fails:  "melden na N mislukte checks" per check: veel storingen die binnen een paar minuten vanzelf herstellen →
 *           hoger; storingen die altijd lang duren terwijl N hoog staat → lager (sneller weten);
 * - maint:  een terugkerend patroon (elke zondag 4:00, elke nacht 2:00) → een vast onderhoudsvenster;
 * - dep:    afhankelijkheden: volgens UniFi (switch of access point) of omdat twee servers steeds samen uitvallen.
 * Vaste onderhoudsvensters (maint_windows) gelden voor een server of één check: per dag of op één weekdag.
 */
final class Advice
{
    public const KINDS = ['fails', 'maint', 'dep'];
    private static ?array $windows = null;

    // ------------------------------------------------------------------ vaste onderhoudsvensters

    /** Actief vast venster voor deze check (of zijn server), of null: ['until' => …, 'reason' => …] */
    public static function windowFor(int $checkId, int $hostId, ?int $ts = null): ?array
    {
        self::$windows ??= q('SELECT * FROM maint_windows')->fetchAll();
        $ts ??= time();
        foreach (self::$windows as $w) {
            if (!(($w['scope'] === 'host' && (int) $w['ref_id'] === $hostId) || ($w['scope'] === 'check' && (int) $w['ref_id'] === $checkId))) {
                continue;
            }
            // Vandaag of gisteren begonnen (een venster kan over middernacht lopen)
            foreach ([0, 1] as $back) {
                $day = $ts - $back * 86400;
                if ((int) $w['dow'] !== 0 && (int) date('N', $day) !== (int) $w['dow']) {
                    continue;
                }
                $start = strtotime(date('Y-m-d', $day) . ' ' . $w['start'] . ':00');
                $end = $start + (int) $w['minutes'] * 60;
                if ($ts >= $start && $ts < $end) {
                    return ['until' => date('Y-m-d H:i:s', $end), 'reason' => '🗓️ ' . ($w['reason'] ?: t('vast onderhoudsvenster')), 'window' => (int) $w['id']];
                }
            }
        }
        return null;
    }

    public static function windows(): array
    {
        $out = [];
        foreach (q("SELECT w.*, CASE WHEN w.scope = 'host' THEN h.name ELSE CONCAT(h2.name, ' – ', c.name) END AS label
                    FROM maint_windows w LEFT JOIN hosts h ON w.scope = 'host' AND h.id = w.ref_id
                    LEFT JOIN checks c ON w.scope = 'check' AND c.id = w.ref_id LEFT JOIN hosts h2 ON h2.id = c.host_id ORDER BY w.dow, w.start") as $w) {
            $out[] = ['id' => (int) $w['id'], 'scope' => $w['scope'], 'ref_id' => (int) $w['ref_id'], 'dow' => (int) $w['dow'], 'start' => $w['start'],
                'minutes' => (int) $w['minutes'], 'reason' => (string) $w['reason'], 'label' => (string) ($w['label'] ?? '?')];
        }
        return $out;
    }

    public static function windowSave(array $in): int
    {
        $scope = ($in['scope'] ?? '') === 'check' ? 'check' : 'host';
        $ref = (int) ($in['ref_id'] ?? 0);
        $exists = $scope === 'host' ? q('SELECT 1 FROM hosts WHERE id = ?', [$ref])->fetchColumn() : q('SELECT 1 FROM checks WHERE id = ?', [$ref])->fetchColumn();
        $start = (string) ($in['start'] ?? '');
        if (!$exists || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start)) {
            throw new InvalidArgumentException(t('Kies een server of check en een begintijd (uu:mm)'));
        }
        q('INSERT INTO maint_windows (scope, ref_id, dow, start, minutes, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$scope, $ref,
            max(0, min(7, (int) ($in['dow'] ?? 0))), $start, max(5, min(1440, (int) ($in['minutes'] ?? 30))), mb_substr(trim((string) ($in['reason'] ?? '')), 0, 200) ?: null, now()]);
        $id = (int) db()->lastInsertId();
        ChangeLog::record('window', $id, 'create', t('Vast onderhoudsvenster: {w}', ['w' => self::describe(q('SELECT * FROM maint_windows WHERE id = ?', [$id])->fetch())]), null, null);
        self::$windows = null;
        return $id;
    }

    public static function windowDelete(int $id): void
    {
        $w = q('SELECT * FROM maint_windows WHERE id = ?', [$id])->fetch();
        if ($w) {
            q('DELETE FROM maint_windows WHERE id = ?', [$id]);
            ChangeLog::record('window', $id, 'delete', t('Vast onderhoudsvenster weg: {w}', ['w' => self::describe($w)]), null, null);
        }
        self::$windows = null;
    }

    private static function dayName(int $dow): string
    {
        return $dow === 0 ? t('elke dag') : t(['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'][$dow - 1]);
    }

    public static function describe(array $w): string
    {
        $label = $w['scope'] === 'host' ? (string) q('SELECT name FROM hosts WHERE id = ?', [$w['ref_id']])->fetchColumn()
            : (string) q("SELECT CONCAT(h.name, ' – ', c.name) FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?", [$w['ref_id']])->fetchColumn();
        return t('{l}: {d} {s} ({m} min)', ['l' => $label, 'd' => self::dayName((int) $w['dow']), 's' => $w['start'], 'm' => (int) $w['minutes']]);
    }

    // ------------------------------------------------------------------ voorstellen maken

    /** Eén keer per nacht (vanaf 4:30): alle voorstellen opnieuw bepalen */
    public static function cron(bool $force = false): int
    {
        if (!$force && ((int) date('G') < 4 || (int) date('G') === 4 && (int) date('i') < 30 || setting('advice_day', '') === date('Y-m-d'))) {
            return 0;
        }
        save_setting('advice_day', date('Y-m-d'));
        $n = 0;
        foreach ([self::fails(), self::maint(), self::deps()] as $list) {
            foreach ($list as [$kind, $ref, $fp, $data]) {
                $n += self::propose($kind, $ref, $fp, $data);
            }
        }
        // Wat niet meer klopt (de check is weg, of het voorstel is achterhaald) opruimen
        q("DELETE FROM advice WHERE state = 'open' AND created_at < NOW() - INTERVAL 14 DAY");
        return $n;
    }

    /** Nieuw voorstel (of bijwerken als het al openstaat); genegeerd of toegepast: niet opnieuw */
    private static function propose(string $kind, int $ref, string $fp, array $data): int
    {
        $prev = q('SELECT id, state FROM advice WHERE kind = ? AND ref_id = ? AND fp = ?', [$kind, $ref, $fp])->fetch();
        if ($prev && $prev['state'] !== 'open') {
            return 0;
        }
        if ($prev) {
            q('UPDATE advice SET data = ?, created_at = ? WHERE id = ?', [json_encode($data, JSON_UNESCAPED_UNICODE), now(), $prev['id']]);
            return 0;
        }
        // Eén open voorstel per soort en check/server: een ouder (andere N, ander venster) vervalt
        q("DELETE FROM advice WHERE kind = ? AND ref_id = ? AND state = 'open'", [$kind, $ref]);
        q('INSERT INTO advice (kind, ref_id, fp, data, created_at) VALUES (?, ?, ?, ?, ?)', [$kind, $ref, $fp, json_encode($data, JSON_UNESCAPED_UNICODE), now()]);
        return 1;
    }

    /** Storingen per check uit status_changes: [check_id => [[begin, duur s], …]] (alleen afgeronde) */
    private static function episodes(int $days): array
    {
        $out = [];
        $open = [];
        foreach (q('SELECT check_id, from_status, to_status, created_at AS t FROM status_changes
                    WHERE created_at >= NOW() - INTERVAL ? DAY ORDER BY check_id, id', [$days]) as $r) {
            $id = (int) $r['check_id'];
            $t = (int) strtotime((string) $r['t']);   // lokale tijd; UNIX_TIMESTAMP zou rond zomer-/wintertijd een uur verschuiven
            if ((int) $r['from_status'] === STATUS_OK && (int) $r['to_status'] !== STATUS_OK) {
                $open[$id] = $t;
            } elseif ((int) $r['to_status'] === STATUS_OK && isset($open[$id])) {
                $out[$id][] = [$open[$id], $t - $open[$id]];
                unset($open[$id]);
            }
        }
        return $out;
    }

    /** Melden na N: per check, uit de storingen van de laatste 30 dagen */
    private static function fails(): array
    {
        $out = [];
        $eps = self::episodes(30);
        $checks = q('SELECT c.id, c.interval_sec, c.notify_fails, c.info_only, c.enabled FROM checks c WHERE c.enabled = 1 AND c.info_only = 0')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($eps as $id => $list) {
            $c = $checks[$id] ?? null;
            if (!$c || count($list) < 5) {
                continue;
            }
            $cur = Monitor::failsNeeded($c + ['notify_fails' => $c['notify_fails']]);
            $iv = max(30, (int) $c['interval_sec']);
            $short = array_values(array_filter($list, static fn($e) => $e[1] <= 300));
            if (count($short) / count($list) >= 0.7) {
                // Lang genoeg om de korte storingen over te slaan: 80% daarvan is binnen zoveel metingen weer goed
                $d = array_map(static fn($e) => $e[1], $short);
                sort($d);
                $p80 = $d[(int) floor((count($d) - 1) * 0.8)];
                $n = max(2, min(6, (int) ceil($p80 / $iv) + 1));
                if ($n > $cur) {
                    $out[] = ['fails', (int) $id, "n$n", ['n' => $n, 'cur' => $cur, 'episodes' => count($list), 'short' => count($short), 'p80' => $p80]];
                }
            } elseif ($cur >= 3 && count(array_filter($list, static fn($e) => $e[1] >= 900)) / count($list) >= 0.8) {
                // Storingen duren bijna altijd lang: dan wil je het eerder weten
                $out[] = ['fails', (int) $id, 'n1', ['n' => 1, 'cur' => $cur, 'episodes' => count($list), 'short' => count($short), 'p80' => null]];
            }
        }
        return $out;
    }

    /** Terugkerende storingen op hetzelfde moment: een vast onderhoudsvenster */
    private static function maint(): array
    {
        $eps = self::episodes(56);
        $hostOf = q('SELECT id, host_id FROM checks')->fetchAll(PDO::FETCH_KEY_PAIR);
        $buckets = [];   // "w|dow|hh:mm" of "d|0|hh:mm" → [check => [dagen…], duren…]
        foreach ($eps as $id => $list) {
            foreach ($list as [$t, $dur]) {
                if ($dur > 5400) {
                    continue;   // langer dan anderhalf uur: geen gepland onderhoud
                }
                $slot = sprintf('%02d:%02d', (int) date('G', $t), intdiv((int) date('i', $t), 15) * 15);
                foreach (['w|' . date('N', $t) . "|$slot", "d|0|$slot"] as $k) {
                    $buckets[$k]['checks'][$id][date('Y-m-d', $t)] = true;
                    $buckets[$k]['end'] = max($buckets[$k]['end'] ?? 0, (int) date('i', $t) % 15 * 60 + $dur);
                }
            }
        }
        $out = [];
        $done = [];
        foreach ($buckets as $k => $b) {
            [$type, $dow, $slot] = explode('|', $k);
            foreach ($b['checks'] as $id => $days) {
                $need = $type === 'w' ? 3 : 5;
                $recent = array_filter(array_keys($days), static fn($d) => $type === 'w' || $d >= date('Y-m-d', strtotime('-14 days')));
                if (count($recent) < $need) {
                    unset($b['checks'][$id]);
                }
            }
            if (!$b['checks']) {
                continue;
            }
            // Meer checks van dezelfde server met hetzelfde patroon: het venster voor de hele server
            $perHost = [];
            foreach (array_keys($b['checks']) as $id) {
                $perHost[(int) ($hostOf[$id] ?? 0)][] = (int) $id;
            }
            [$hh, $mm] = array_map('intval', explode(':', $slot));
            $start = max(0, $hh * 60 + $mm - 5);
            $minutes = (int) (ceil(($b['end'] + 300) / 300) * 5) + 10;
            foreach ($perHost as $hid => $ids) {
                [$scope, $ref] = count($ids) >= 2 ? ['host', $hid] : ['check', $ids[0]];
                $key = "$scope:$ref";
                if (isset($done[$key]) || $type === 'd' && isset($done["w$key"])) {
                    continue;   // dagelijks gaat voor wekelijks (dat is ruimer); per server/check één voorstel
                }
                $st = sprintf('%02d:%02d', intdiv($start, 60), $start % 60);
                if (self::windowCovers($scope, (int) $ref, (int) $dow, $st)) {
                    continue;
                }
                $done[$key] = true;
                $out[] = ['maint', (int) $ref, "$scope|$dow|$st", ['scope' => $scope, 'dow' => (int) $dow, 'start' => $st, 'minutes' => min(120, $minutes),
                    'times' => max(array_map('count', $b['checks'])), 'checks' => array_slice($ids, 0, 10)]];
            }
        }
        return $out;
    }

    private static function windowCovers(string $scope, int $ref, int $dow, string $start): bool
    {
        return (bool) q('SELECT 1 FROM maint_windows WHERE scope = ? AND ref_id = ? AND (dow = 0 OR dow = ?) AND start <= ? AND ADDTIME(start, SEC_TO_TIME(minutes * 60)) > ?',
            [$scope, $ref, $dow, $start, $start])->fetchColumn();
    }

    /** Afhankelijkheden: uit UniFi (switch of access point) en uit servers die steeds samen uitvallen */
    private static function deps(): array
    {
        $hosts = q('SELECT id, name, address, parent_id, kind, kind_auto FROM hosts WHERE enabled = 1')->fetchAll(PDO::FETCH_UNIQUE);
        $byName = [];
        foreach ($hosts as $id => $h) {
            $byName[mb_strtolower(trim((string) $h['name']))] = (int) $id;
        }
        $out = [];
        // 1. UniFi: de switch of het access point waar de server aan hangt, is zelf een server in RzMon
        foreach ($hosts as $id => $h) {
            if ($h['parent_id'] !== null) {
                continue;
            }
            $info = json_decode((string) q('SELECT info FROM net_devices WHERE ip = ? ORDER BY last_seen DESC LIMIT 1', [$h['address']])->fetchColumn(), true) ?: [];
            $up = mb_strtolower(trim((string) ($info['unifi']['sw'] ?? $info['unifi']['ap'] ?? '')));
            $p = $up !== '' ? ($byName[$up] ?? null) : null;
            if ($p && $p !== (int) $id && !self::isAncestor((int) $id, $p, $hosts)) {
                $out[] = ['dep', (int) $id, "p$p", ['parent' => $p, 'why' => 'unifi', 'via' => $info['unifi']['sw'] ?? $info['unifi']['ap']]];
            }
        }
        // 2. Samen uitvallen: storingen (naar critical) per server, de laatste 60 dagen. Van de ping als de server die heeft
        //    (een losse dienst die eruit ligt is geen uitval van de server), anders van TCP/HTTP.
        $byType = ['ping' => [], 'other' => []];
        foreach (q("SELECT c.host_id, c.type, s.created_at AS t FROM status_changes s JOIN checks c ON c.id = s.check_id
                    WHERE s.to_status = 2 AND s.from_status <> 2 AND c.type IN ('ping', 'tcp', 'http') AND s.created_at >= NOW() - INTERVAL 60 DAY ORDER BY s.created_at, s.id") as $r) {
            $hid = (int) $r['host_id'];
            $g = $r['type'] === 'ping' ? 'ping' : 'other';
            $t = (int) strtotime((string) $r['t']);
            if (!isset($byType[$g][$hid]) || $t - end($byType[$g][$hid]) > 600) {
                $byType[$g][$hid][] = $t;   // eén storing per server per 10 minuten
            }
        }
        $hasPing = array_flip(array_map('intval', q("SELECT DISTINCT host_id FROM checks WHERE type = 'ping' AND enabled = 1")->fetchAll(PDO::FETCH_COLUMN)));
        $starts = $byType['ping'];
        foreach ($byType['other'] as $hid => $ts) {
            if (!isset($hasPing[$hid])) {
                $starts[$hid] = $ts;
            }
        }
        $proposed = array_column(array_map(static fn($o) => [$o[1], true], $out), 1, 0);
        foreach ($starts as $child => $cs) {
            if (count($cs) < 3 || !isset($hosts[$child]) || $hosts[$child]['parent_id'] !== null || isset($proposed[$child])) {
                continue;
            }
            $best = null;
            foreach ($starts as $parent => $ps) {
                if ($parent === $child || !isset($hosts[$parent]) || self::isAncestor($child, $parent, $hosts)) {
                    continue;
                }
                $hit = 0;
                foreach ($cs as $t) {
                    foreach ($ps as $pt) {
                        if (abs($t - $pt) <= 180) {
                            $hit++;
                            break;
                        }
                    }
                }
                if ($hit / count($cs) < 0.8) {
                    continue;
                }
                // Andersom ook steeds samen? Dan is niet te zeggen wie van wie afhangt, behalve bij een netwerkapparaat
                $net = in_array($hosts[$parent]['kind'] ?? $hosts[$parent]['kind_auto'], ['router', 'switch', 'ap', 'firewall', 'network'], true);
                $netC = in_array($hosts[$child]['kind'] ?? $hosts[$child]['kind_auto'], ['router', 'switch', 'ap', 'firewall', 'network'], true);
                if (!$net && ($netC || count($ps) <= count($cs))) {
                    continue;
                }
                $score = $hit + ($net ? 100 : 0);
                if ($best === null || $score > $best[1]) {
                    $best = [$parent, $score, $hit];
                }
            }
            if ($best) {
                $out[] = ['dep', $child, 'p' . $best[0], ['parent' => $best[0], 'why' => 'together', 'times' => $best[2], 'of' => count($cs)]];
            }
        }
        return $out;
    }

    /** Is $a een voorouder van $b (dan zou $a → $b een kring maken)? */
    private static function isAncestor(int $a, int $b, array $hosts): bool
    {
        for ($i = 0, $p = $hosts[$b]['parent_id'] ?? null; $p !== null && $i < 20; $i++, $p = $hosts[(int) $p]['parent_id'] ?? null) {
            if ((int) $p === $a) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ lijst, toepassen, negeren

    public static function list(): array
    {
        $out = [];
        foreach (q("SELECT * FROM advice WHERE state = 'open' ORDER BY kind, created_at DESC") as $a) {
            $d = json_decode((string) $a['data'], true) ?: [];
            $item = ['id' => (int) $a['id'], 'kind' => $a['kind'], 'ref_id' => (int) $a['ref_id'], 'data' => $d, 'created_at' => $a['created_at']];
            if ($a['kind'] === 'fails' || ($a['kind'] === 'maint' && ($d['scope'] ?? '') === 'check')) {
                $c = q('SELECT c.name, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$a['ref_id']])->fetch();
                if (!$c) {
                    continue;
                }
                $item['label'] = "{$c['host']} – {$c['name']}";
            } else {
                $h = q('SELECT name FROM hosts WHERE id = ?', [$a['ref_id']])->fetchColumn();
                if ($h === false) {
                    continue;
                }
                $item['label'] = (string) $h;
                if ($a['kind'] === 'dep') {
                    $item['parent'] = (string) q('SELECT name FROM hosts WHERE id = ?', [$d['parent'] ?? 0])->fetchColumn();
                }
            }
            $item['text'] = self::text($item);
            $out[] = $item;
        }
        return $out;
    }

    private static function text(array $a): string
    {
        $d = $a['data'];
        return match ($a['kind']) {
            'fails' => (int) $d['n'] > (int) $d['cur']
                ? t('Melden na {n} mislukte checks (nu {c}): {s} van de {e} storingen waren binnen 5 minuten vanzelf weer goed', ['n' => $d['n'], 'c' => $d['cur'], 's' => $d['short'], 'e' => $d['episodes']])
                : t('Meteen melden (nu na {c}): de laatste {e} storingen duurden allemaal lang', ['c' => $d['cur'], 'e' => $d['episodes']]),
            'maint' => t('Vast onderhoudsvenster {d} {s} ({m} min): {n}× op dat moment een storing die vanzelf herstelde', ['d' => self::dayName((int) $d['dow']), 's' => $d['start'], 'm' => $d['minutes'], 'n' => $d['times']]),
            'dep' => ($d['why'] ?? '') === 'unifi'
                ? t('Hangt af van {p}: hangt er volgens UniFi aan', ['p' => $a['parent'] ?? '?'])
                : t('Hangt af van {p}: viel {n} van de {o} keer tegelijk uit', ['p' => $a['parent'] ?? '?', 'n' => $d['times'], 'o' => $d['of']]),
            default => '',
        };
    }

    public static function decide(int $id, bool $apply): string
    {
        $a = q("SELECT * FROM advice WHERE id = ? AND state = 'open'", [$id])->fetch();
        if (!$a) {
            throw new InvalidArgumentException(t('Dit voorstel bestaat niet (meer)'));
        }
        $d = json_decode((string) $a['data'], true) ?: [];
        $msg = t('Genegeerd; dit voorstel komt niet terug');
        if ($apply) {
            switch ($a['kind']) {
                case 'fails':
                    $before = ChangeLog::checkRow((int) $a['ref_id']);
                    q('UPDATE checks SET notify_fails = ? WHERE id = ?', [max(1, min(100, (int) $d['n'])), $a['ref_id']]);
                    if ($before) {
                        ChangeLog::record('check', (int) $a['ref_id'], 'update', $before['_host'] . ' · ' . $before['name'] . ' (💡 ' . t('voorstel') . ')', $before, ChangeLog::checkRow((int) $a['ref_id']));
                    }
                    $msg = t('Melden na {n} mislukte checks', ['n' => (int) $d['n']]);
                    break;
                case 'maint':
                    self::windowSave(['scope' => $d['scope'], 'ref_id' => (int) $a['ref_id'], 'dow' => (int) $d['dow'], 'start' => $d['start'], 'minutes' => (int) $d['minutes'],
                        'reason' => t('patroon herkend door RzMon')]);
                    $msg = t('Vast onderhoudsvenster toegevoegd');
                    break;
                case 'dep':
                    $before = ChangeLog::hostRow((int) $a['ref_id']);
                    q('UPDATE hosts SET parent_id = ? WHERE id = ?', [(int) $d['parent'], $a['ref_id']]);
                    if ($before) {
                        ChangeLog::record('host', (int) $a['ref_id'], 'update', $before['name'] . ' (💡 ' . t('voorstel') . ')', $before, ChangeLog::hostRow((int) $a['ref_id']));
                    }
                    $msg = t('Afhankelijkheid ingesteld');
                    break;
            }
        }
        q('UPDATE advice SET state = ?, decided_at = ? WHERE id = ?', [$apply ? 'applied' : 'dismissed', now(), $id]);
        return $msg;
    }

    public static function openCount(): int
    {
        return (int) q("SELECT COUNT(*) FROM advice WHERE state = 'open'")->fetchColumn();
    }
}
