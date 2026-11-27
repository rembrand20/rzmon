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
            'update_last' => mb_substr((string) ($in['update_last'] ?? ''), 0, 300),   // 1.343.0: de uitkomst van het laatste zelf bijwerken
            'ready' => AgentTakeover::cleanReady($in['ready'] ?? null),   // 1.344.0: staat alles klaar om over te nemen?
        ]);
        AgentData::put($id, 'goready', ['at' => now(), 'ready' => AgentTakeover::cleanReady($in['ready'] ?? null), 'update_last' => mb_substr((string) ($in['update_last'] ?? ''), 0, 300)]);
        // 1.343.0: heeft RzSense een nieuwere Go-agent voor deze processor? Dan werkt de agent zichzelf bij (hij controleert
        // zelf de handtekening van de ontwikkelaar; de SHA-256 is een extra controle op het downloaden)
        $update = self::updateFor((string) ($in['arch'] ?? ''), (string) ($in['agent_version'] ?? ''));
        // 1.344.0: overnemen, als de gebruiker dat voor deze server vroeg en het veilig kan (eerst bijwerken)
        $take = $update === null && AgentTakeover::shouldTake($id);
        return ['ok' => true, 'same' => $same, 'n' => count($items), 'update' => $update, 'takeover' => $take,
            'diffs' => array_values(array_map(static fn($i) => ['k' => $i['k'], 'bash' => $i['bash'], 'go' => $i['go']], array_filter($items, static fn($i) => !$i['ok'])))];
    }

    /**
     * Heeft RzSense een nieuwere Go-agent voor deze processor dan de agent nu heeft?
     * @return array{version: string, sha256: string}|null
     */
    public static function updateFor(string $arch, string $have): ?array
    {
        if (in_array($arch, self::ARCHS, true) && in_array($arch, self::available(), true) && preg_match('/^\d+\.\d+\.\d+$/', $have) && version_compare(AGENT_WIN_LATEST, $have, '>')) {
            return ['version' => AGENT_WIN_LATEST, 'sha256' => (string) hash_file('sha256', self::file($arch))];
        }
        return null;
    }

    /**
     * Wat de bash-agent het laatst meldde (alleen als het vers genoeg is)
     * @return array<string, mixed> cpu, mem, boot, disks (mount => [total, avail])
     */
    public static function bashNow(int $hostId): array
    {
        $out = ['disks' => []];
        $min = time() - self::FRESH;
        // 1.347.0: de softwarelijst die de bash-agent stuurde (Kwetsbaarheden of End-of-life aan)
        $inv = q('SELECT mgr, os_id, os_version, packages, exposed, kernel_running, kernel_latest FROM vuln_inventory WHERE host_id = ?', [$hostId])->fetch();
        if ($inv) {
            $out['inventory'] = ['list' => json_decode((string) $inv['packages'], true) ?: [], 'exposed' => json_decode((string) $inv['exposed'], true) ?: []] + $inv;
        }
        foreach (q("SELECT metric, value, updated_at FROM agent_data WHERE host_id = ? AND (metric IN ('cpu', 'mem', 'boot', 'ports', 'proclist', 'svclist', 'patch', 'time', 'smart', 'ups') OR metric LIKE 'path:%' OR metric LIKE 'disk:%' OR metric LIKE 'service:%' OR metric LIKE 'cert:%' OR metric LIKE 'proc:%'
                     OR metric LIKE 'svcinfo:%' OR metric LIKE 'svctest:%')", [$hostId]) as $r) {
            $m = (string) $r['metric'];
            if (str_starts_with($m, 'path:')) {   // 1.348.0: een pad-check meet om de paar minuten; de laatste meting telt
                $d = json_decode((string) $r['value'], true);
                if (is_array($d) && ctype_digit(substr($m, 5))) {
                    $out['path'][substr($m, 5)] = $d;
                }
                continue;
            }
            if ($m === 'patch') {   // 1.346.0: de bash-agent kijkt eens per uur; de Go-agent vlak daarna
                $d = json_decode((string) $r['value'], true);
                if (is_array($d) && (int) ($d['at'] ?? 0) >= time() - 1200) {
                    $out['patch'] = $d;
                }
                continue;
            }
            if (strtotime((string) $r['updated_at']) < $min) {
                continue;
            }
            if ($m === 'smart' || $m === 'ups') {   // 1.348.0
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out[$m] = $d;
                }
            } elseif ($m === 'time') {
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out['time'] = $d;
                }
            } elseif ($m === 'boot') {
                $out['boot'] = (int) $r['value'];
            } elseif (str_starts_with($m, 'service:')) {   // 1.340.0: de status van een dienst (systemctl is-active)
                $out['services'][substr($m, 8)] = (string) $r['value'];
            } elseif (str_starts_with($m, 'svcinfo:') || str_starts_with($m, 'svctest:')) {   // 1.342.0: dienstdetails en extra tests
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out[str_starts_with($m, 'svcinfo:') ? 'svc' : 'svc_tests'][substr($m, 8)] = $d;
                }
            } elseif (str_starts_with($m, 'cert:') || str_starts_with($m, 'proc:')) {   // 1.341.0: certificaten en proces-checks
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out[str_starts_with($m, 'cert:') ? 'certs' : 'procs'][substr($m, 5)] = $d;
                }
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
        // 1.341.0: certificaten (per bron), proces-checks (per check) en de lijst met processen om uit te kiezen
        if (is_array($bash['certs'] ?? null) && $bash['certs'] && is_array($go['certs'] ?? null)) {
            $gc = [];
            foreach ($go['certs'] as $c) {
                if (is_array($c) && isset($c['source'])) {
                    $gc[(string) $c['source']] = $c;
                }
            }
            $bc = $bash['certs'];
            $onlyB = array_keys(array_diff_key($bc, $gc));
            $onlyG = array_keys(array_diff_key($gc, $bc));
            $n = static fn(array $m): string => count($m) === 1 ? t('1 certificaat') : t('{n} certificaten', ['n' => count($m)]);
            // 1.345.2: RzSense bewaart hooguit 100 certificaten per server; meldt de bash-agent er zoveel, dan valt alleen te
            // vergelijken wat er van beide is
            if (count($bc) >= 100) {
                $onlyG = [];
            }
            $add('certificaten: lijst', $n($bc) . ($onlyB ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyB, 0, 5))]) : ''),
                $n($gc) . ($onlyG ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyG, 0, 5))]) : ''), !$onlyB && !$onlyG);
            // Gelijk: naam, uitgever, vervaldatum en de keten exact; "vertrouwd" en "fout" alleen ja of nee (de tekst komt
            // bij de bash-agent van openssl en verschilt dus altijd)
            $sig = static fn(array $c): array => [(string) ($c['cn'] ?? ''), (string) ($c['issuer'] ?? ''), is_numeric($c['not_after'] ?? null) ? (int) $c['not_after'] : null,
                (int) ($c['chain_n'] ?? 0), is_numeric($c['chain_min'] ?? null) ? (int) $c['chain_min'] : null, (string) ($c['chain_cn'] ?? ''),
                ($c['verify'] ?? null) === null ? null : ($c['verify'] === 'ok'), (string) ($c['error'] ?? '') !== ''];
            $txt = static fn(array $c): string => (string) ($c['error'] ?? '') !== '' ? t('fout: {e}', ['e' => mb_substr((string) $c['error'], 0, 80)])
                : implode(' · ', array_filter([(string) ($c['cn'] ?? ''), (string) ($c['issuer'] ?? ''), is_numeric($c['not_after'] ?? null) ? date('Y-m-d H:i', (int) $c['not_after']) : '',
                    t('keten {n}', ['n' => (int) ($c['chain_n'] ?? 0)]) . (($c['chain_cn'] ?? '') !== '' ? ' (' . $c['chain_cn'] . ')' : ''),
                    ($c['verify'] ?? null) === null ? '' : ($c['verify'] === 'ok' ? t('vertrouwd') : t('niet vertrouwd'))]));
            // 1.345.2: de bash-agent leest namen uit de tekst van openssl: een komma in een naam kapt hem af ("Starfield
            // Technologies\") en letters met accenten komen als \C5\91. De Go-agent leest het certificaat zelf en heeft de
            // hele naam. Dat is hetzelfde certificaat, dus geen verschil.
            $name = static function (string $b, string $g): bool {
                $b = (string) preg_replace_callback('/\\\\([0-9A-Fa-f]{2})/', static fn($m) => chr((int) hexdec($m[1])), $b);
                return $b === $g || (str_ends_with($b, '\\') && str_starts_with($g, substr($b, 0, -1)));
            };
            foreach (array_intersect_key($bc, $gc) as $src => $b) {
                [$sb, $sg] = [$sig($b), $sig($gc[$src])];
                $same = $name($sb[0], $sg[0]) && $name($sb[1], $sg[1]) && $name($sb[5], $sg[5]) && array_diff_key($sb, [0 => 1, 1 => 1, 5 => 1]) === array_diff_key($sg, [0 => 1, 1 => 1, 5 => 1]);
                $add('certificaat ' . mb_substr((string) $src, 0, 80), $txt($b), $txt($gc[$src]), $same);
            }
        }
        if (is_array($bash['procs'] ?? null) && $bash['procs'] && is_array($go['procs'] ?? null)) {
            $txt = static fn(array $p): string => (int) ($p['n'] ?? 0) === 0 ? t('draait niet') : t('{n}× · pid {p} · {c}% · {m}', ['n' => (int) $p['n'], 'p' => mb_substr((string) ($p['pids'] ?? ''), 0, 40),
                'c' => round((float) ($p['cpu'] ?? 0), 1), 'm' => Checks::bytes((float) ($p['rss'] ?? 0) * 1024)]);
            foreach ($bash['procs'] as $id => $b) {
                $g = $go['procs'][$id] ?? null;
                if (!is_array($g)) {
                    $add("proces-check #$id", $txt($b), t('niet gemeten'), false);
                    continue;
                }
                $ok = (int) ($b['n'] ?? 0) === (int) ($g['n'] ?? -1) && (string) ($b['pids'] ?? '') === (string) ($g['pids'] ?? '')
                    && $near($b['rss'] ?? 0, $g['rss'] ?? 0, max(8192, 0.1 * (float) ($b['rss'] ?? 0))) && $near($b['cpu'] ?? 0, $g['cpu'] ?? 0, 5)
                    && (($b['start'] ?? null) === null ? ($g['start'] ?? null) === null : $near($b['start'], $g['start'] ?? null, 3));
                $add("proces-check #$id", $txt($b), $txt($g), $ok);
            }
        }
        if (is_array($bash['proclist'] ?? null) && $bash['proclist'] && is_array($go['proc_list'] ?? null)) {
            $names = static fn(array $l): array => array_values(array_unique(array_filter(array_map(static fn($e) => is_array($e) ? (string) ($e[0] ?? '') : '', $l))));
            [$bn, $gn] = [$names($bash['proclist']), $names($go['proc_list'])];
            $onlyB = array_values(array_diff($bn, $gn));
            $onlyG = array_values(array_diff($gn, $bn));
            // Processen komen en gaan tussen de twee metingen: een klein verschil is geen verschil. Bij 200 of meer namen kapt
            // de bash-agent de lijst willekeurig af; dan valt er niets te vergelijken.
            $tol = max(3, (int) ceil(0.1 * max(count($bn), count($gn))));
            if (count($bn) < 200 && count($gn) < 200) {
                $add('processen: lijst', t('{n} namen', ['n' => count($bn)]) . ($onlyB ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyB, 0, 6))]) : ''),
                    t('{n} namen', ['n' => count($gn)]) . ($onlyG ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyG, 0, 6))]) : ''), count($onlyB) + count($onlyG) <= $tol);
            }
        }
        // 1.342.0: de details per dienst, de extra tests en de lijst met diensten om uit te kiezen
        if (is_array($bash['svc'] ?? null) && $bash['svc'] && is_array($go['svc'] ?? null)) {
            $set = static function (mixed $l): array {
                $l = array_map('strval', is_array($l) ? $l : []);
                sort($l);
                return array_values(array_unique($l));
            };
            $txt = static fn(array $d): string => !isset($d['type']) ? t('draait niet ({s})', ['s' => (string) ($d['sub'] ?? '?')])
                : implode(' · ', array_filter([(string) ($d['sub'] ?? ''), 'pid ' . (int) ($d['pid'] ?? 0), ($d['ports'] ?? []) ? t('poorten {p}', ['p' => implode(',', $d['ports'])]) : t('geen poorten'),
                    ($d['ports_fail'] ?? []) ? t('dicht: {p}', ['p' => implode(',', $d['ports_fail'])]) : '', ($d['probe'] ?? '') !== '' ? $d['probe'] . ' ' . ['goed', 'let op', 'fout'][(int) ($d['probe_rc'] ?? 0)] : '',
                    (int) ($d['restarts'] ?? 0) ? t('{n} herstarts', ['n' => (int) $d['restarts']]) : '', (int) ($d['log_errors'] ?? 0) ? t('{n} fouten in het log', ['n' => (int) $d['log_errors']]) : '']));
            foreach ($bash['svc'] as $name => $b) {
                $g = $go['svc'][$name] ?? null;
                if (!is_array($g)) {
                    $add("dienst $name: details", $txt($b), t('niet gemeten'), false);
                    continue;
                }
                // Exact: of hij draait, het hoofdproces, de poorten, wat dicht is, en de soort en uitkomst van de test. Het
                // eigen testscript telt niet mee (dat voert de proef niet uit), en het log schuift mee met de tijd.
                $ok = isset($b['type']) === isset($g['type']) && (string) ($b['sub'] ?? '') === (string) ($g['sub'] ?? '');
                $why = [];   // 1.345.2: welk onderdeel verschilt, met beide waarden (anders zie je twee gelijke regels met ≠)
                if ($ok && isset($b['type'])) {
                    $j = static fn(mixed $v): string => is_array($v) ? implode(',', array_map('strval', $v)) : (string) ($v ?? '–');
                    $parts = [
                        'type' => (string) $b['type'] === (string) ($g['type'] ?? ''), 'pid' => (int) ($b['pid'] ?? 0) === (int) ($g['pid'] ?? -1), 'restarts' => ($b['restarts'] ?? null) == ($g['restarts'] ?? null),
                        'ports' => $set($b['ports'] ?? []) === $set($g['ports'] ?? []), 'ports_fail' => $set($b['ports_fail'] ?? []) === $set($g['ports_fail'] ?? []),
                        'manual' => $set($b['manual'] ?? []) === $set($g['manual'] ?? []), 'sockets' => $set($b['sockets'] ?? []) === $set($g['sockets'] ?? []),
                        'sockets_fail' => count((array) ($b['sockets_fail'] ?? [])) === count((array) ($g['sockets_fail'] ?? [])),
                        'probe' => (string) ($b['probe'] ?? '') === (string) ($g['probe'] ?? ''), 'probe_rc' => ($b['probe_rc'] ?? null) === ($g['probe_rc'] ?? null),
                        'since' => ($b['since'] ?? null) === null ? ($g['since'] ?? null) === null : $near($b['since'], $g['since'] ?? null, 3),
                        'log_errors' => ((int) ($b['log_errors'] ?? 0) > 0) === ((int) ($g['log_errors'] ?? 0) > 0),
                    ];
                    foreach ($parts as $k => $same) {
                        if (!$same) {
                            $why[$k] = [mb_substr($j($b[$k] ?? null), 0, 120), mb_substr($j($g[$k] ?? null), 0, 120)];
                        }
                    }
                    $ok = $why === [];
                }
                $extra = static fn(int $i): string => implode('', array_map(static fn($k) => " · $k: " . $why[$k][$i], array_keys($why)));
                $add("dienst $name: details", $txt($b) . $extra(0), $txt($g) . $extra(1), $ok);
            }
        }
        if (is_array($bash['svc_tests'] ?? null) && $bash['svc_tests'] && is_array($go['svc_tests'] ?? null)) {
            foreach ($bash['svc_tests'] as $id => $b) {
                $g = $go['svc_tests'][$id] ?? null;
                $w = static fn(?array $x): string => $x === null ? t('niet gemeten') : (!empty($x['ok']) ? t('goed') : t('fout')) . ' · ' . mb_substr((string) ($x['msg'] ?? ''), 0, 80);
                $add("extra test #$id", $w($b), $w(is_array($g) ? $g : null), is_array($g) && !empty($b['ok']) === !empty($g['ok']));
            }
        }
        if (is_array($bash['svclist'] ?? null) && $bash['svclist'] && is_array($go['svc_list'] ?? null) && $go['svc_list']) {
            $map = static function (array $l): array {
                $m = [];
                foreach ($l as $e) {
                    if (is_array($e) && isset($e[0])) {
                        $m[(string) $e[0]] = (string) ($e[1] ?? '') . '/' . (int) ($e[2] ?? 0);
                    }
                }
                return $m;
            };
            [$bl, $gl] = [$map($bash['svclist']), $map($go['svc_list'])];
            $onlyB = array_keys(array_diff_key($bl, $gl));
            $onlyG = array_keys(array_diff_key($gl, $bl));
            $state = array_keys(array_filter(array_intersect_key($bl, $gl), static fn($v, $k) => $v !== $gl[$k], ARRAY_FILTER_USE_BOTH));
            // Een dienst die net start of stopt tussen de twee metingen is geen verschil: twee afwijkende statussen mag, en
            // (1.345.2) een paar namen ook: tijdelijke diensten met een nummer (dbus-…@11192) komen en gaan
            $add('diensten om uit te kiezen', t('{n} namen', ['n' => count($bl)]) . ($onlyB ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyB, 0, 6))]) : '')
                . ($state ? ' · ' . t('andere status: {l}', ['l' => implode(', ', array_slice($state, 0, 6))]) : ''),
                t('{n} namen', ['n' => count($gl)]) . ($onlyG ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($onlyG, 0, 6))]) : ''), count($onlyB) + count($onlyG) <= 4 && count($state) <= 2);
        }
        // 1.348.0: schijfgezondheid, UPS en pad-checks
        if (is_array($bash['smart'] ?? null) && $bash['smart'] && is_array($go['smart'] ?? null)) {
            $gd = array_column(array_filter($go['smart'], 'is_array'), null, 'dev');
            $sm = static fn(array $d): string => !empty($d['standby']) ? t('slaapstand') : (isset($d['error']) ? (string) $d['error']
                : implode(' · ', array_filter([(string) ($d['model'] ?? ''), ($d['passed'] ?? null) === null ? '' : ($d['passed'] ? t('zelftest goed') : t('zelftest FOUT')),
                    is_numeric($d['temp'] ?? null) ? $d['temp'] . ' °C' : '', is_numeric($d['wear'] ?? null) ? t('slijtage {n}%', ['n' => $d['wear']]) : '',
                    is_numeric($d['realloc'] ?? null) ? t('verplaatst {n}', ['n' => $d['realloc']]) : '', is_numeric($d['pending'] ?? null) ? t('wachtend {n}', ['n' => $d['pending']]) : '',
                    is_numeric($d['media_err'] ?? null) ? t('mediafouten {n}', ['n' => $d['media_err']]) : ''])));
            foreach ($bash['smart'] as $b) {
                if (!is_array($b) || !isset($b['dev'])) {
                    continue;
                }
                $g = $gd[$b['dev']] ?? null;
                if (!is_array($g)) {
                    $add('schijf ' . $b['dev'] . ': gezondheid', $sm($b), t('niet gemeten'), false);
                    continue;
                }
                // Slaapstand kan tussen de twee metingen wisselen: dan valt er niets te vergelijken
                $sleep = !empty($b['standby']) || !empty($g['standby']);
                $same = $sleep || ((string) ($b['model'] ?? '') === (string) ($g['model'] ?? '') && ($b['passed'] ?? null) === ($g['passed'] ?? null) && isset($b['error']) === isset($g['error'])
                    && (is_numeric($b['temp'] ?? null) ? $near($b['temp'], $g['temp'] ?? null, 4) : !is_numeric($g['temp'] ?? null))
                    && ($b['wear'] ?? null) == ($g['wear'] ?? null) && ($b['realloc'] ?? null) == ($g['realloc'] ?? null) && ($b['pending'] ?? null) == ($g['pending'] ?? null) && ($b['media_err'] ?? null) == ($g['media_err'] ?? null));
                $add('schijf ' . $b['dev'] . ': gezondheid', $sm($b), $sm($g), $same);
            }
        }
        if (is_array($bash['ups'] ?? null) && $bash['ups'] && is_array($go['ups'] ?? null)) {
            $gu = array_column(array_filter($go['ups'], 'is_array'), null, 'name');
            $up = static fn(array $u): string => implode(' · ', array_filter([(string) ($u['model'] ?? ''), (string) ($u['status'] ?? ''), is_numeric($u['charge'] ?? null) ? $u['charge'] . '%' : '']));
            foreach ($bash['ups'] as $b) {
                if (is_array($b) && isset($b['name'])) {
                    $g = $gu[$b['name']] ?? null;
                    $add('UPS ' . $b['name'], $up($b), is_array($g) ? $up($g) : t('niet gemeten'), is_array($g) && (string) ($b['status'] ?? '') === (string) ($g['status'] ?? '')
                        && (string) ($b['model'] ?? '') === (string) ($g['model'] ?? '') && (is_numeric($b['charge'] ?? null) ? $near($b['charge'], $g['charge'] ?? null, 3) : !is_numeric($g['charge'] ?? null)));
                }
            }
        }
        if (is_array($bash['path'] ?? null) && is_array($go['path'] ?? null)) {
            foreach ($go['path'] as $pid => $g) {
                $b = $bash['path'][$pid] ?? null;
                if (!is_array($g) || !is_array($b) || !empty($g['old']) || empty($g['at'])) {
                    continue;
                }
                // Het pad zelf hoort gelijk te zijn (kaart, adres, gateway, snelheid, hulpmiddel, bereikbaar of niet); de
                // vertraging per hop verschilt per meting, en een hop meer of minder kan (een router die soms niet antwoordt)
                $reach = static fn(array $p): bool => is_array($p['ping'] ?? null) && (float) ($p['ping']['loss'] ?? 100) < 100;
                $pt = static fn(array $p): string => implode(' · ', array_filter([(string) ($p['tool'] ?? ''), (string) ($p['dev'] ?? ''), (string) ($p['src'] ?? ''), ($p['gw'] ?? '') !== '' ? 'via ' . $p['gw'] : '',
                    is_numeric($p['speed'] ?? null) && $p['speed'] > 0 ? $p['speed'] . ' Mbit/s' : '', (string) ($p['virt'] ?? ''), t('{n} hops', ['n' => count((array) ($p['hops'] ?? []))]),
                    $reach($p) ? t('doel bereikbaar') : t('doel niet bereikbaar'), is_array($p['lldp'] ?? null) ? ($p['lldp']['sys'] ?? '') . ' ' . ($p['lldp']['port'] ?? '') : '']));
                $spd = static fn(array $p): int => is_numeric($p['speed'] ?? null) && $p['speed'] > 0 ? (int) $p['speed'] : 0;
                $add("pad-check #$pid", $pt($b), $pt($g), (string) ($b['tool'] ?? '') === (string) ($g['tool'] ?? '') && (string) ($b['dev'] ?? '') === (string) ($g['dev'] ?? '')
                    && (string) ($b['src'] ?? '') === (string) ($g['src'] ?? '') && (string) ($b['gw'] ?? '') === (string) ($g['gw'] ?? '') && $spd($b) === $spd($g)
                    && (string) ($b['virt'] ?? '') === (string) ($g['virt'] ?? '') && abs(count((array) ($b['hops'] ?? [])) - count((array) ($g['hops'] ?? []))) <= 1 && $reach($b) === $reach($g)
                    && (($b['lldp'] ?? null) ?: null) == ((is_array($g['lldp'] ?? null) ? $g['lldp'] : null) ?: null) && count((array) ($b['via'] ?? [])) === count((array) ($g['via'] ?? [])));
            }
        }
        // 1.347.0: de klok (bron, gesynchroniseerd, afwijking) en de softwarelijst voor Kwetsbaarheden
        if (is_array($bash['time'] ?? null) && is_array($go['time'] ?? null)) {
            $tt = static fn(array $x): string => (string) ($x['src'] ?? '?') . ' · ' . (!empty($x['sync']) ? t('gesynchroniseerd') : t('niet gesynchroniseerd'))
                . (is_numeric($x['offset_ms'] ?? null) ? ' · ' . round((float) $x['offset_ms'], 3) . ' ms' : '');
            $add('klok', $tt($bash['time']), $tt($go['time']), (string) ($bash['time']['src'] ?? '') === (string) ($go['time']['src'] ?? '') && !empty($bash['time']['sync']) === !empty($go['time']['sync'])
                && (is_numeric($bash['time']['offset_ms'] ?? null) ? $near($bash['time']['offset_ms'], $go['time']['offset_ms'] ?? null, 50) : !is_numeric($go['time']['offset_ms'] ?? null)));
        }
        if (is_array($bash['inventory'] ?? null) && is_array($go['packages'] ?? null)) {
            [$bi, $gp] = [$bash['inventory'], $go['packages']];
            $rows = static function (mixed $l): array {
                $m = [];
                foreach (is_array($l) ? $l : [] as $r) {
                    if (is_array($r) && isset($r[0], $r[1])) {
                        $m[] = $r[0] . ' ' . $r[1] . ' ' . ($r[2] ?? '');
                    }
                }
                sort($m);
                return array_values(array_unique($m));
            };
            [$bl, $gl] = [$rows($bi['list']), $rows($gp['list'] ?? [])];
            $onlyB = array_values(array_diff($bl, $gl));
            $onlyG = array_values(array_diff($gl, $bl));
            $st = static fn(array $l, array $only): string => t('{n} pakketten', ['n' => count($l)]) . ($only ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($only, 0, 5))]) : '');
            $add('software: pakketten', $st($bl, $onlyB), $st($gl, $onlyG), !$onlyB && !$onlyG);
            $sys = static fn(string $mgr, string $id, string $ver, string $run, string $latest): string => "$mgr · $id $ver · kernel $run" . ($latest !== '' && $latest !== $run ? " (→ $latest)" : '');
            $bs = $sys((string) $bi['mgr'], (string) $bi['os_id'], (string) $bi['os_version'], (string) $bi['kernel_running'], (string) $bi['kernel_latest']);
            $gs = $sys((string) ($gp['mgr'] ?? ''), strtolower((string) ($gp['os']['id'] ?? '')), (string) ($gp['os']['version'] ?? ''), (string) ($gp['kernel']['running'] ?? ''), (string) ($gp['kernel']['latest'] ?? ''));
            $add('software: systeem', $bs, $gs, $bs === $gs);
            $exp = static function (mixed $l): array {
                $m = [];
                foreach (is_array($l) ? $l : [] as $e) {
                    if (is_array($e) && isset($e[0])) {
                        $m[] = $e[0] . ':' . (int) ($e[1] ?? 0) . '/' . (($e[2] ?? '') === 'udp' ? 'udp' : 'tcp');
                    }
                }
                sort($m);
                return array_values(array_unique($m));
            };
            [$be, $ge] = [$exp($bi['exposed']), $exp($gp['exposed'] ?? [])];
            $add('software: bereikbaar via het netwerk', implode(', ', array_slice($be, 0, 12)) ?: '–', implode(', ', array_slice($ge, 0, 12)) ?: '–', $be === $ge);
        }
        // 1.346.0: welke updates er klaarstaan (pakket, versie, beveiliging ja of nee) en of een herstart nodig is
        if (is_array($bash['patch'] ?? null) && is_array($go['patch'] ?? null)) {
            $pl = static function (array $p): array {
                $m = [];
                foreach ((array) ($p['list'] ?? []) as $e) {
                    if (is_array($e) && isset($e[0])) {
                        $m[] = $e[0] . ' ' . ($e[1] ?? '') . (!empty($e[2]) ? ' 🛡' : '');
                    }
                }
                sort($m);
                return $m;
            };
            [$bl, $gl] = [$pl($bash['patch']), $pl($go['patch'])];
            $onlyB = array_values(array_diff($bl, $gl));
            $onlyG = array_values(array_diff($gl, $bl));
            $ptxt = static fn(array $p, array $only): string => t('{n} updates, {s} beveiliging', ['n' => (int) ($p['avail'] ?? 0), 's' => (int) ($p['sec'] ?? 0)])
                . (!empty($p['rb']) ? ' · ' . t('herstart nodig') : '') . ($only ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($only, 0, 5))]) : '');
            $add('updates', $ptxt($bash['patch'], $onlyB), $ptxt($go['patch'], $onlyG),
                !$onlyB && !$onlyG && (string) ($bash['patch']['mgr'] ?? '') === (string) ($go['patch']['mgr'] ?? '') && !empty($bash['patch']['rb']) === !empty($go['patch']['rb']));
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
        return ['shadow' => $s, 'archs' => self::available(), 'version' => AGENT_WIN_LATEST, 'pin' => $pin, 'take' => AgentTakeover::check($hostId)];
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
