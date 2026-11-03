<?php
declare(strict_types=1);

/**
 * Urgentie (1.144.0): één manier om te bepalen wat het eerst moet, voor Incidenten en "Nu doen" op het dashboard.
 * Score 0–100 = ernst + hoe lang al + bereikbaar van buiten + wat ervan afhangt + beveiliging:
 *   ernst:        critical 50, warning 25, tip 5
 *   duur:         tot 15 (5 min ≈ 3, 1 uur ≈ 13, een dag of langer 15)
 *   bereikbaar:   +10 (een open poort of een aanvaller van buiten)
 *   afhankelijk:  +3 per server die ervan afhangt (parent), tot 10
 *   beveiliging:  +15 (IDS of een actief misbruikte kwetsbaarheid)
 * Elk onderdeel komt ook als korte reden mee, zodat je ziet waarom iets bovenaan staat.
 */
final class Urgency
{
    /** @return array{score: int, why: list<string>} */
    public static function score(int $sev, int $minutes, bool $exposed, int $dependents, bool $security): array
    {
        $why = [];
        $s = [2 => 50, 1 => 25][$sev] ?? 5;
        $why[] = [2 => t('critical'), 1 => t('warning')][$sev] ?? t('tip');
        if ($minutes > 0) {
            $d = (int) min(15, round(log(1 + $minutes / 5) * 4.5));
            $s += $d;
            if ($minutes >= 60) {
                $why[] = $minutes >= 1440 ? t('al {n} dagen', ['n' => intdiv($minutes, 1440)]) : t('al {n} uur', ['n' => intdiv($minutes, 60)]);
            }
        }
        if ($exposed) {
            $s += 10;
            $why[] = t('bereikbaar van buiten');
        }
        if ($dependents > 0) {
            $s += min(10, 3 * $dependents);
            $why[] = t('{n} server(s) hangen ervan af', ['n' => $dependents]);
        }
        if ($security) {
            $s += 15;
            $why[] = t('beveiliging');
        }
        return ['score' => (int) min(100, $s), 'why' => $why];
    }

    /** Per server: hoeveel servers er (direct of verder) van afhangen, via hosts.parent_id */
    public static function dependents(bool $fresh = false): array
    {
        static $out = null;
        if ($out !== null && !$fresh) {
            return $out;
        }
        $children = [];
        foreach (q('SELECT id, parent_id FROM hosts WHERE enabled = 1 AND parent_id IS NOT NULL') as $h) {
            $children[(int) $h['parent_id']][] = (int) $h['id'];
        }
        $out = [];
        foreach (array_keys($children) as $p) {
            $seen = [];
            $todo = $children[$p];
            while ($todo) {
                $c = array_pop($todo);
                if (isset($seen[$c]) || $c === $p) {
                    continue;
                }
                $seen[$c] = true;
                array_push($todo, ...($children[$c] ?? []));
            }
            $out[$p] = count($seen);
        }
        return $out;
    }

    /** Wie hangt er van deze server af (namen, voor de uitleg bij een incident) */
    public static function dependentNames(int $hostId, int $max = 6): array
    {
        $names = [];
        $todo = [$hostId];
        $seen = [$hostId => true];
        while ($todo && count($names) < 50) {
            $p = array_pop($todo);
            foreach (q('SELECT id, name FROM hosts WHERE enabled = 1 AND parent_id = ?', [$p]) as $h) {
                if (!isset($seen[(int) $h['id']])) {
                    $seen[(int) $h['id']] = true;
                    $names[] = (string) $h['name'];
                    $todo[] = (int) $h['id'];
                }
            }
        }
        return array_slice($names, 0, $max);
    }
}
