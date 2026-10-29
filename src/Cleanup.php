<?php
declare(strict_types=1);

/**
 * Opschoonadvies (1.96.0), Instellingen → Monitoring → Advies: checks die al dagen onbekend zijn, ruismakers (veel
 * meldingen), flappers (wisselen steeds), drempels die rakelings goed of fout zijn, al lang in warning, en checks die al
 * lang uit staan. Per punt een voorstel; "niet meer tonen" bewaart RzMon per check en soort (cleanup_ignore).
 */
final class Cleanup
{
    private static function ignored(): array
    {
        return (array) (json_decode((string) setting('cleanup_ignore', ''), true) ?: []);
    }

    public static function ignore(int $checkId, string $kind): void
    {
        $ign = self::ignored();
        $ign["$checkId:$kind"] = date('Y-m-d');
        save_setting('cleanup_ignore', json_encode(array_slice($ign, -2000, null, true)));
    }

    /** @return list<array{kind: string, check_id: int, name: string, host: string, type: string, detail: string, advice: string, fix: ?string}> */
    public static function advice(): array
    {
        $ign = self::ignored();
        $checks = [];
        foreach (q('SELECT c.*, h.name AS host_name FROM checks c JOIN hosts h ON h.id = c.host_id')->fetchAll() as $c) {
            $checks[(int) $c['id']] = $c;
        }
        $out = [];
        $add = static function (string $kind, int $id, string $detail, string $advice, ?string $fix = null) use (&$out, $ign, $checks): void {
            if (!isset($checks[$id]) || isset($ign["$id:$kind"])) {
                return;
            }
            $c = $checks[$id];
            $out[] = ['kind' => $kind, 'check_id' => $id, 'name' => $c['name'], 'host' => $c['host_name'], 'type' => $c['type'], 'detail' => $detail, 'advice' => $advice, 'fix' => $fix];
        };
        $since = static fn(?string $dt) => $dt ? max(0, (int) floor((time() - strtotime($dt)) / 86400)) : 0;

        foreach ($checks as $id => $c) {
            if (!(int) $c['enabled']) {
                // Al lang uit: verwijderen of weer aanzetten
                if ($c['last_checked'] === null || strtotime((string) $c['last_checked']) < time() - 30 * 86400) {
                    $add('off', $id, t('Staat uit, al {n} dagen niet gecontroleerd', ['n' => $c['last_checked'] ? $since($c['last_checked']) : '30+']),
                        t('Nodig? Zet hem weer aan, of verwijder hem.'), 'delete');
                }
                continue;
            }
            // Al dagen onbekend: het doel bestaat waarschijnlijk niet meer
            if ((int) $c['status'] === STATUS_UNKNOWN && $c['status_since'] && strtotime((string) $c['status_since']) < time() - 86400) {
                $add('unknown', $id, t('Al {n} dagen ONBEKEND: {msg}', ['n' => $since($c['status_since']), 'msg' => mb_substr((string) $c['last_message'], 0, 120)]),
                    t('Bestaat het doel nog (service, container, disk, agent)? Pas de check aan of verwijder hem.'), 'disable');
            }
            // Al lang in warning: de drempel is waarschijnlijk te krap (of je accepteert het)
            if ((int) $c['status'] === STATUS_WARN && $c['status_since'] && strtotime((string) $c['status_since']) < time() - 3 * 86400) {
                $add('warn', $id, t('Al {n} dagen WARNING ({value})', ['n' => $since($c['status_since']), 'value' => $c['last_value'] !== null ? round((float) $c['last_value'], 1) : '–']),
                    t('Is dit normaal voor deze server? Verhoog dan de warning-drempel; anders oplossen.'), 'edit');
            }
        }
        // Ruismakers: de meeste meldingen in 7 dagen
        foreach (q("SELECT check_id, COUNT(*) AS n FROM alerts WHERE kind = 'notify' AND check_id IS NOT NULL AND created_at >= NOW() - INTERVAL 7 DAY
                    GROUP BY check_id HAVING n >= 10 ORDER BY n DESC LIMIT 15")->fetchAll() as $r) {
            $add('noisy', (int) $r['check_id'], t('{n} meldingen in 7 dagen', ['n' => $r['n']]),
                t('Melden pas na 3 mislukte checks achter elkaar haalt korte haperingen weg; of verhoog de drempels.'), 'fails3');
        }
        // Flappers: wisselen steeds van status
        foreach (q('SELECT check_id, COUNT(*) AS n FROM status_changes WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY check_id HAVING n >= 20 ORDER BY n DESC LIMIT 15')->fetchAll() as $r) {
            $add('flap', (int) $r['check_id'], t('{n} keer van status gewisseld in 7 dagen', ['n' => $r['n']]),
                t('Een langer interval of melden na een paar mislukte checks maakt hem rustiger; kijk ook of het doel echt hapert.'), 'fails3');
        }
        // Rakelings: de waarde ligt meestal net onder de warning-drempel (binnen 10%), of gaat er vaak net overheen
        foreach (q('SELECT r.check_id, AVG(r.value) AS avg, MAX(r.value) AS max, COUNT(*) AS n FROM results r
                    WHERE r.created_at >= NOW() - INTERVAL 7 DAY AND r.value IS NOT NULL GROUP BY r.check_id HAVING n >= 50')->fetchAll() as $r) {
            $c = $checks[(int) $r['check_id']] ?? null;
            if (!$c || $c['warn_threshold'] === null || in_array($c['type'], ['ssl'], true) || (float) $c['warn_threshold'] <= 0) {
                continue;
            }
            $w = (float) $c['warn_threshold'];
            if ((float) $r['avg'] >= 0.9 * $w && (float) $r['avg'] < $w) {
                $add('tight', (int) $r['check_id'], t('Gemiddeld {avg}, de warning-drempel is {w}', ['avg' => round((float) $r['avg'], 1), 'w' => $w]),
                    t('De waarde zit bijna altijd net onder de drempel: een kleine piek geeft al een melding. Verhoog de drempel iets, of los het op.'), 'edit');
            }
        }
        $order = ['unknown' => 0, 'noisy' => 1, 'flap' => 2, 'warn' => 3, 'tight' => 4, 'off' => 5];
        usort($out, static fn($a, $b) => [$order[$a['kind']], $a['host'], $a['name']] <=> [$order[$b['kind']], $b['host'], $b['name']]);
        return $out;
    }
}
