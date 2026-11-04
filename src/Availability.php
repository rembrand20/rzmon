<?php
declare(strict_types=1);

/**
 * 1.169.0: Monitoring → Beschikbaarheid. Per server over een periode (24 uur, 7, 30 of 90 dagen):
 *  - het percentage metingen dat niet CRITICAL was (zelfde regel als Stats::uptime: ping, anders TCP en HTTP);
 *  - dat percentage per uur (24 uur) of per dag (de dagtotalen in stats_daily, vandaag uit de metingen);
 *  - de storingen: de tijd dat al die checks tegelijk CRITICAL waren (de server was onbereikbaar), uit de statuswissels.
 * Het groeperen (soort apparaat of eigen groep) doet de interface; die kent de soort van elke server al.
 */
final class Availability
{
    public const PERIODS = [1, 7, 30, 90];

    /**
     * @return array{days: int, from: int, to: int, span: int, hosts: array<int, array<string, mixed>>, outages: list<array<string, mixed>>}
     */
    public static function report(int $days, ?int $now = null): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 7;
        $now ??= time();
        $from = $days === 1 ? $now - 86400 : (int) strtotime(date('Y-m-d', $now)) - ($days - 1) * 86400;
        $buckets = $days === 1 ? 24 : $days;

        // Welke checks zeggen of een server bereikbaar is: ping, en zonder ping TCP en HTTP
        $byType = [];
        $status = [];
        foreach (q("SELECT id, host_id, type, status FROM checks WHERE enabled = 1 AND type IN ('ping', 'tcp', 'http') AND reverse_ok IS NULL") as $c) {
            $byType[(int) $c['host_id']][$c['type'] === 'ping' ? 1 : 0][] = (int) $c['id'];
            $status[(int) $c['id']] = (int) $c['status'];
        }
        $use = [];
        $owner = [];
        foreach ($byType as $hid => $k) {
            $use[$hid] = $k[1] ?? $k[0];
            foreach ($use[$hid] as $cid) {
                $owner[$cid] = $hid;
            }
        }

        // Metingen per uur of per dag: [host][ping?][bucket] = [n, ok]
        $raw = [];
        $add = static function (int $hid, int $p, int $b, float $n, float $ok) use (&$raw, $buckets): void {
            if ($b < 0 || $b >= $buckets) {
                return;
            }
            $raw[$hid][$p][$b][0] = ($raw[$hid][$p][$b][0] ?? 0) + $n;
            $raw[$hid][$p][$b][1] = ($raw[$hid][$p][$b][1] ?? 0) + $ok;
        };
        $live = "SELECT c.host_id, c.type = 'ping' AS p, %s AS b, COUNT(*) AS n, SUM(r.status <> 2) AS ok
                 FROM results r JOIN checks c ON c.id = r.check_id
                 WHERE c.enabled = 1 AND c.type IN ('ping', 'tcp', 'http') AND c.reverse_ok IS NULL AND r.created_at >= FROM_UNIXTIME(?)
                 GROUP BY c.host_id, p, b";
        if ($days === 1) {
            foreach (q(sprintf($live, 'FLOOR((UNIX_TIMESTAMP(r.created_at) - ?) / 3600)'), [$from, $from]) as $r) {
                $add((int) $r['host_id'], (int) $r['p'], (int) $r['b'], (float) $r['n'], (float) $r['ok']);
            }
        } else {
            $today = (int) strtotime(date('Y-m-d', $now));
            foreach (q(sprintf($live, '0'), [$today]) as $r) {
                $add((int) $r['host_id'], (int) $r['p'], $days - 1, (float) $r['n'], (float) $r['ok']);
            }
            // Eerdere dagen uit de dagtotalen (daar is de keuze ping of TCP/HTTP al gemaakt)
            $days0 = [];
            foreach (q("SELECT ref, day, metric, v FROM stats_daily WHERE metric IN ('h_n', 'h_ok') AND day >= ? AND day < ?",
                [date('Y-m-d', $from), date('Y-m-d', $today)]) as $r) {
                $days0[(int) $r['ref']][(string) $r['day']][$r['metric']] = (float) $r['v'];
            }
            foreach ($days0 as $hid => $list) {
                foreach ($list as $d => $m) {
                    $b = (int) round(((int) strtotime($d) - $from) / 86400);
                    $add($hid, 2, $b, $m['h_n'] ?? 0, $m['h_ok'] ?? 0);
                }
            }
        }

        $hosts = [];
        foreach ($raw as $hid => $k) {
            // Vandaag of per uur: ping heeft voorrang; dagtotalen (2) tellen altijd
            $pick = isset($k[1]) ? $k[1] : ($k[0] ?? []);
            $series = [];
            $n = 0.0;
            $ok = 0.0;
            for ($b = 0; $b < $buckets; $b++) {
                $bn = ($pick[$b][0] ?? 0) + ($k[2][$b][0] ?? 0);
                $bo = ($pick[$b][1] ?? 0) + ($k[2][$b][1] ?? 0);
                $series[] = $bn > 0 ? round($bo / $bn * 100, 2) : null;
                $n += $bn;
                $ok += $bo;
            }
            $hosts[$hid] = ['pct' => $n > 0 ? round($ok / $n * 100, 3) : null, 'series' => $series, 'n' => 0, 'down_s' => 0, 'longest_s' => 0, 'open' => false];
        }

        // Storingen uit de statuswissels
        $outages = [];
        if ($owner) {
            $ids = array_keys($owner);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $state = [];
            foreach (q("SELECT s.check_id, s.to_status FROM status_changes s JOIN (SELECT check_id, MAX(id) AS id FROM status_changes
                        WHERE check_id IN ($in) AND created_at < FROM_UNIXTIME(?) GROUP BY check_id) l ON l.id = s.id", array_merge($ids, [$from])) as $r) {
                $state[(int) $r['check_id']] = (int) $r['to_status'];
            }
            $changes = q("SELECT check_id, from_status, to_status, UNIX_TIMESTAMP(created_at) AS t FROM status_changes
                          WHERE check_id IN ($in) AND created_at >= FROM_UNIXTIME(?) ORDER BY check_id, id LIMIT 200000", array_merge($ids, [$from]))->fetchAll();
            $first = [];
            foreach ($changes as $r) {
                $first[(int) $r['check_id']] ??= (int) $r['from_status'];
            }
            $iv = [];   // [host][] = [start, eind, nog bezig]
            $open = [];
            $firstAt = [];
            foreach ($changes as $r) {
                $firstAt[(int) $r['check_id']] ??= (int) $r['t'];
            }
            foreach ($ids as $cid) {
                $st = $state[$cid] ?? $first[$cid] ?? $status[$cid];
                if ($st !== 2) {
                    continue;
                }
                if (isset($state[$cid])) {
                    $open[$cid] = $from;   // al CRITICAL vóór de periode (bekend uit de wissels)
                } elseif (($t = self::critStart($cid, $from, $firstAt[$cid] ?? $now)) !== null) {
                    $open[$cid] = $t;      // geen eerdere wissel bekend: het begin uit de metingen
                }
            }
            foreach ($changes as $r) {
                $cid = (int) $r['check_id'];
                $t = (int) $r['t'];
                if ((int) $r['to_status'] === 2) {
                    $open[$cid] ??= $t;
                } elseif (isset($open[$cid])) {
                    $iv[$owner[$cid]][] = [$open[$cid], $t, false];
                    unset($open[$cid]);
                }
            }
            foreach ($open as $cid => $t) {
                $iv[$owner[$cid]][] = [$t, $now, true];
            }
            foreach ($iv as $hid => $list) {
                foreach (self::allDown($list, count($use[$hid])) as [$a, $b, $busy]) {
                    $d = max(0, $b - $a);
                    if ($d === 0 && !$busy) {
                        continue;
                    }
                    $hosts[$hid] ??= ['pct' => null, 'series' => array_fill(0, $buckets, null), 'n' => 0, 'down_s' => 0, 'longest_s' => 0, 'open' => false];
                    $hosts[$hid]['n']++;
                    $hosts[$hid]['down_s'] += $d;
                    $hosts[$hid]['longest_s'] = max($hosts[$hid]['longest_s'], $d);
                    $hosts[$hid]['open'] = $hosts[$hid]['open'] || $busy;
                    $outages[] = ['host_id' => $hid, 'start' => $a, 'end' => $busy ? null : $b, 'dur' => $d, 'from_start' => $a <= $from];
                }
            }
            usort($outages, static fn($x, $y) => $y['start'] <=> $x['start']);
        }
        return ['days' => $days, 'from' => $from, 'to' => $now, 'span' => $now - $from, 'hosts' => $hosts, 'outages' => array_slice($outages, 0, 50)];
    }

    /**
     * Wanneer een check CRITICAL werd als er geen statuswissel van vóór de periode is (de geschiedenis begint later):
     * de eerste CRITICAL meting na de laatste goede meting vóór $until. Geen CRITICAL meting: geen storing.
     */
    private static function critStart(int $cid, int $from, int $until): ?int
    {
        $ok = q('SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM results WHERE check_id = ? AND created_at >= FROM_UNIXTIME(?) AND created_at < FROM_UNIXTIME(?) AND status <> 2',
            [$cid, $from, $until])->fetchColumn();
        $t = q('SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM results WHERE check_id = ? AND created_at >= FROM_UNIXTIME(?) AND created_at < FROM_UNIXTIME(?) AND status = 2',
            [$cid, $ok ? max($from, (int) $ok) : $from, $until])->fetchColumn();
        return $t ? (int) $t : null;
    }

    /**
     * De tijden dat alle $k checks van één server tegelijk CRITICAL waren. Per check overlappen de stukken niet,
     * dus tellen we hoeveel checks er op elk moment CRITICAL zijn. Nog bezig = het stuk loopt tot nu.
     * @param list<array{0: int, 1: int, 2: bool}> $list [begin, eind, nog bezig] per check
     * @return list<array{0: int, 1: int, 2: bool}>
     */
    public static function allDown(array $list, int $k): array
    {
        $ev = [];
        foreach ($list as [$a, $b, $busy]) {
            $ev[] = [$a, 1, false];
            $ev[] = [$b, -1, $busy];
        }
        // Bij gelijke tijd eerst het einde: aansluitende stukken van verschillende checks overlappen niet
        usort($ev, static fn($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $out = [];
        $n = 0;
        $start = null;
        foreach ($ev as [$t, $d, $busy]) {
            $n += $d;
            if ($d === 1 && $n >= $k && $start === null) {
                $start = $t;
            } elseif ($d === -1 && $start !== null && $n < $k) {
                $out[] = [$start, $t, $busy];
                $start = null;
            }
        }
        return $out;
    }
}
