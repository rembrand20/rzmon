<?php
declare(strict_types=1);

/**
 * 1.339.0: proef met de Go-agent op Linux. De Go-agent draait náást de bash-agent en stuurt elke minuut zijn eigen
 * metingen (hostnaam, opstarttijd, CPU, geheugen, schijven) naar de actie agent_shadow. RzSense legt die naast wat de
 * bash-agent net meldde en houdt bij hoe vaak ze gelijk zijn. Er wordt niets overgenomen: de gegevens van de bash-agent
 * blijven zoals ze zijn, en dit staat apart (agent_data, onderwerp "shadow").
 *
 * Gelijk betekent: exact waar het exact hoort (grootte van geheugen en schijven, aantal kernen, koppelpunten), en binnen
 * een marge waar de twee op een ander moment meten (CPU-gebruik, vrije ruimte).
 */
final class AgentShadow
{
    /** Zo oud mag de meting van de bash-agent hooguit zijn om mee te vergelijken (seconden) */
    public const FRESH = 180;
    /** Bestanden van de Go-agent voor Linux, per processor (gebouwd en ondertekend door GitHub Actions) */
    public const ARCHS = ['amd64', 'arm64', 'armv7'];

    /**
     * Een proefronde van de Go-agent aannemen en vergelijken.
     * @param array<string, mixed> $host
     * @param array<string, mixed> $in
     * @return array<string, mixed> het antwoord voor de agent
     */
    public static function receive(array $host, array $in): array
    {
        $core = is_array($in['core'] ?? null) ? $in['core'] : [];
        if (!is_array($core['mem'] ?? null) || !is_array($core['cpu'] ?? null)) {
            return ['ok' => false, 'error' => t('geen metingen in de proefronde')];
        }
        $id = (int) $host['id'];
        $items = self::compare($core, self::bashNow($id));
        $same = count(array_filter($items, static fn($i) => $i['ok']));
        $prev = AgentData::get($id, 'shadow') ?? [];
        $diffN = (array) ($prev['diff_n'] ?? []);
        foreach ($items as $i) {
            if (!$i['ok']) {
                $diffN[$i['k']] = (int) ($diffN[$i['k']] ?? 0) + 1;
            }
        }
        arsort($diffN);
        $counted = $items !== [];   // zonder verse meting van de bash-agent telt de ronde niet mee
        $allOk = $counted && $same === count($items);
        AgentData::put($id, 'shadow', [
            'at' => now(), 'since' => (string) ($prev['since'] ?? now()),
            'version' => mb_substr((string) ($in['agent_version'] ?? ''), 0, 20), 'bash_version' => mb_substr((string) ($in['bash_version'] ?? ''), 0, 20),
            'took_ms' => max(0, min(600000, (int) ($in['took_ms'] ?? 0))),
            'same' => $same, 'n' => count($items), 'items' => $items,
            'rounds' => (int) ($prev['rounds'] ?? 0) + ($counted ? 1 : 0), 'rounds_ok' => (int) ($prev['rounds_ok'] ?? 0) + ($allOk ? 1 : 0),
            'skipped' => (int) ($prev['skipped'] ?? 0) + ($counted ? 0 : 1),
            'streak' => $allOk ? (int) ($prev['streak'] ?? 0) + 1 : ($counted ? 0 : (int) ($prev['streak'] ?? 0)),
            'last_diff_at' => $counted && !$allOk ? now() : ($prev['last_diff_at'] ?? null),
            'diff_n' => array_slice($diffN, 0, 20, true),
            'doctor' => self::cleanDoctor($in['doctor'] ?? []),
        ]);
        return ['ok' => true, 'same' => $same, 'n' => count($items),
            'diffs' => array_values(array_map(static fn($i) => ['k' => $i['k'], 'bash' => $i['bash'], 'go' => $i['go']], array_filter($items, static fn($i) => !$i['ok'])))];
    }

    /**
     * Wat de bash-agent het laatst meldde (alleen als het vers genoeg is)
     * @return array<string, mixed> cpu, mem, boot, disks (mount => [total, avail])
     */
    public static function bashNow(int $hostId): array
    {
        $out = ['disks' => []];
        $min = time() - self::FRESH;
        foreach (q("SELECT metric, value, updated_at FROM agent_data WHERE host_id = ? AND (metric IN ('cpu', 'mem', 'boot', 'ports') OR metric LIKE 'disk:%' OR metric LIKE 'service:%')", [$hostId]) as $r) {
            if (strtotime((string) $r['updated_at']) < $min) {
                continue;
            }
            $m = (string) $r['metric'];
            if ($m === 'boot') {
                $out['boot'] = (int) $r['value'];
            } elseif (str_starts_with($m, 'service:')) {   // 1.340.0: de status van een dienst (systemctl is-active)
                $out['services'][substr($m, 8)] = (string) $r['value'];
            } elseif (str_starts_with($m, 'disk:')) {
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out['disks'][substr($m, 5)] = ['total' => (float) ($d['total'] ?? 0), 'avail' => (float) ($d['avail'] ?? 0)];
                }
            } else {
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out[$m] = $d;
                }
            }
        }
        return $out;
    }

    /**
     * De metingen van de Go-agent naast die van de bash-agent leggen.
     * @param array<string, mixed> $go het blok "core" van de Go-agent
     * @param array<string, mixed> $bash uit bashNow()
     * @return list<array{k: string, bash: string, go: string, ok: bool}>
     */
    public static function compare(array $go, array $bash): array
    {
        $out = [];
        $add = static function (string $k, mixed $b, mixed $g, bool $ok) use (&$out): void {
            $out[] = ['k' => $k, 'bash' => self::show($b), 'go' => self::show($g), 'ok' => $ok];
        };
        $near = static fn($a, $b, float $tol): bool => is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) <= $tol;
        if (isset($bash['boot'], $go['boot'])) {
            $add('opstarttijd', (int) $bash['boot'], (int) $go['boot'], $near($bash['boot'], $go['boot'], 2));
        }
        if (is_array($bash['cpu'] ?? null) && is_array($go['cpu'] ?? null)) {
            $add('cpu: kernen', (int) ($bash['cpu']['cores'] ?? 0), (int) ($go['cpu']['cores'] ?? 0), (int) ($bash['cpu']['cores'] ?? 0) === (int) ($go['cpu']['cores'] ?? -1));
            // Gemeten op een ander moment (elk over 2 seconden): ruime marge
            $add('cpu: gebruik (%)', round((float) ($bash['cpu']['pct'] ?? 0), 1), round((float) ($go['cpu']['pct'] ?? 0), 1), $near($bash['cpu']['pct'] ?? null, $go['cpu']['pct'] ?? null, 25));
            $add('cpu: belasting (1 min)', round((float) ($bash['cpu']['load'][0] ?? 0), 2), round((float) ($go['cpu']['load'][0] ?? 0), 2),
                $near($bash['cpu']['load'][0] ?? null, $go['cpu']['load'][0] ?? null, max(1.0, 0.5 * (float) ($bash['cpu']['load'][0] ?? 0))));
        }
        if (is_array($bash['mem'] ?? null) && is_array($go['mem'] ?? null)) {
            $bt = (float) ($bash['mem']['total'] ?? 0);
            $add('geheugen: totaal', $bt, (float) ($go['mem']['total'] ?? 0), $bt > 0 && $bt === (float) ($go['mem']['total'] ?? -1));
            $add('geheugen: beschikbaar', (float) ($bash['mem']['avail'] ?? 0), (float) ($go['mem']['avail'] ?? 0), $near($bash['mem']['avail'] ?? null, $go['mem']['avail'] ?? null, max(64 * 1048576, $bt * 0.05)));
            $bs = (float) ($bash['mem']['swap_total'] ?? 0);
            $add('wisselgeheugen: totaal', $bs, (float) ($go['mem']['swap_total'] ?? 0), $bs === (float) ($go['mem']['swap_total'] ?? -1));
            if ($bs > 0) {
                $add('wisselgeheugen: vrij', (float) ($bash['mem']['swap_free'] ?? 0), (float) ($go['mem']['swap_free'] ?? 0), $near($bash['mem']['swap_free'] ?? null, $go['mem']['swap_free'] ?? null, max(64 * 1048576, $bs * 0.05)));
            }
        }
        $gd = [];
        foreach ((array) ($go['disks'] ?? []) as $d) {
            if (is_array($d) && isset($d['mount'])) {
                $gd[(string) $d['mount']] = ['total' => (float) ($d['total'] ?? 0), 'avail' => (float) ($d['avail'] ?? 0)];
            }
        }
        $bd = (array) ($bash['disks'] ?? []);
        if ($bd) {
            $bm = array_keys($bd);
            $gm = array_keys($gd);
            sort($bm);
            sort($gm);
            $add('schijven: koppelpunten', implode(' ', $bm), implode(' ', $gm), $bm === $gm);
            foreach ($bd as $mount => $b) {
                if (!isset($gd[$mount])) {
                    continue;
                }
                $add("schijf $mount: grootte", $b['total'], $gd[$mount]['total'], $b['total'] === $gd[$mount]['total']);
                $add("schijf $mount: vrij", $b['avail'], $gd[$mount]['avail'], $near($b['avail'], $gd[$mount]['avail'], max(64 * 1048576, $b['total'] * 0.01)));
            }
        }
        // 1.340.0: luisterende poorten (poort, adres, proces) en diensten
        if (is_array($bash['ports'] ?? null) && is_array($go['ports'] ?? null)) {
            $map = static function (array $list): array {
                $m = [];
                foreach ($list as $p) {
                    if (is_array($p) && (int) ($p['port'] ?? 0) > 0) {
                        $m[(int) $p['port'] . ' ' . (string) ($p['addr'] ?? '')] = (string) ($p['proc'] ?? '');
                    }
                }
                ksort($m, SORT_NATURAL);
                return $m;
            };
            [$bp, $gp] = [$map($bash['ports']), $map($go['ports'])];
            $onlyB = array_keys(array_diff_key($bp, $gp));
            $onlyG = array_keys(array_diff_key($gp, $bp));
            $n = static fn(array $m): string => count($m) === 1 ? t('1 poort') : t('{n} poorten', ['n' => count($m)]);
            $add('poorten: lijst', $n($bp) . ($onlyB ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyB, 0, 8))]) : ''),
                $n($gp) . ($onlyG ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyG, 0, 8))]) : ''), !$onlyB && !$onlyG);
            $pb = $pg = [];
            foreach (array_intersect_key($bp, $gp) as $k => $proc) {
                if ($proc !== $gp[$k]) {
                    $pb[] = "$k = " . ($proc !== '' ? $proc : '–');
                    $pg[] = "$k = " . ($gp[$k] !== '' ? $gp[$k] : '–');
                }
            }
            $add('poorten: processen', $pb ? implode(', ', array_slice($pb, 0, 8)) : t('gelijk'), $pg ? implode(', ', array_slice($pg, 0, 8)) : t('gelijk'), !$pb);
        }
        if (is_array($bash['services'] ?? null) && $bash['services'] && is_array($go['services'] ?? null)) {
            // De bash-agent meldt ook diensten die RzSense zelf afleidt; alleen wat de Go-agent hoort te meten telt: de namen
            // die de Go-agent meldt, plus wat hij mist van de gewone systemd-diensten van de bash-agent
            $bs = array_filter($bash['services'], static fn($k) => !str_contains((string) $k, ':') && !in_array($k, ['homeassistant', 'supervisor', 'updates'], true), ARRAY_FILTER_USE_KEY);
            $gs = array_map('strval', $go['services']);
            $names = array_unique([...array_keys($bs), ...array_keys($gs)]);
            sort($names);
            $miss = array_values(array_diff(array_keys($bs), array_keys($gs)));
            $extra = array_values(array_diff(array_keys($gs), array_keys($bs)));
            $n = static fn(array $m): string => count($m) === 1 ? t('1 dienst') : t('{n} diensten', ['n' => count($m)]);
            $add('diensten: lijst', $n($bs) . ($miss ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($miss, 0, 8))]) : ''),
                $n($gs) . ($extra ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($extra, 0, 8))]) : ''), !$miss && !$extra);
            foreach ($names as $name) {
                if (isset($bs[$name], $gs[$name])) {
                    $add("dienst $name", $bs[$name], $gs[$name], $bs[$name] === $gs[$name]);
                }
            }
        }
        return $out;
    }

    /** Een waarde om te tonen: grote getallen als bytes */
    private static function show(mixed $v): string
    {
        if (is_float($v) && $v >= 1048576) {
            return Checks::bytes($v);
        }
        return is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : mb_substr((string) $v, 0, 300);
    }

    /**
     * @return list<array{k: string, st: string, t: string, d: string, fix: string}>
     */
    private static function cleanDoctor(mixed $in): array
    {
        $out = [];
        foreach (array_slice(is_array($in) ? $in : [], 0, 12) as $f) {
            if (is_array($f) && isset($f['k'], $f['t'])) {
                $out[] = ['k' => mb_substr((string) $f['k'], 0, 30), 'st' => in_array($f['st'] ?? '', ['ok', 'warn', 'bad'], true) ? (string) $f['st'] : 'warn',
                    't' => mb_substr((string) $f['t'], 0, 100), 'd' => mb_substr((string) ($f['d'] ?? ''), 0, 300), 'fix' => mb_substr((string) ($f['fix'] ?? ''), 0, 300)];
            }
        }
        return $out;
    }

    /**
     * Voor de Agent Manager: de stand van de proef op deze server, en welke bestanden RzSense kan leveren
     * @return array<string, mixed>
     */
    public static function get(int $hostId): array
    {
        $s = AgentData::get($hostId, 'shadow');
        if ($s !== null) {
            $s['live'] = strtotime((string) $s['at']) > time() - 300;
            $s['pct'] = $s['rounds'] > 0 ? round($s['rounds_ok'] / $s['rounds'] * 100, 1) : null;
        }
        // De vingerafdruk van het certificaat van RzSense, voor het installatiecommando (zoals bij koppelen)
        $host = q('SELECT id, pin_mode FROM hosts WHERE id = ?', [$hostId])->fetch() ?: null;
        $pin = null;
        try {
            $pin = $host && AgentCrypto::pinOn($host) ? AgentCrypto::pin(false, true, $host) : null;
        } catch (Throwable) {
            $pin = null;
        }
        return ['shadow' => $s, 'archs' => self::available(), 'version' => AGENT_WIN_LATEST, 'pin' => $pin];
    }

    /** De proef opnieuw beginnen te tellen (de Go-agent zelf blijft draaien) */
    public static function reset(int $hostId): void
    {
        AgentData::put($hostId, 'shadow', null);
    }

    /** @return list<string> de processors waarvoor een ondertekend bestand klaarstaat */
    public static function available(): array
    {
        return array_values(array_filter(self::ARCHS, static fn($a) => is_file(self::file($a)) && is_file(self::file($a) . '.sig')));
    }

    public static function file(string $arch): string
    {
        return SM_ROOT . '/agent-win/dist/rzsense-linux-' . (in_array($arch, self::ARCHS, true) ? $arch : 'amd64');
    }
}
