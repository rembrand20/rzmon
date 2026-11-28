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
        $bashNow = self::bashNow($id);
        // 1.359.0: bestanden bewaken: de waarden die RzSense van de bash-agent bewaart, bij de steekproef van de Go-agent
        $sample = $core['ids_obs']['fim']['sample'] ?? null;
        if (is_array($sample) && $sample !== [] && isset($bashNow['ids_obs'])) {
            $paths = array_slice(array_map('strval', array_keys($sample)), 0, 300);
            $bashNow['ids_obs']['fim_base'] = q("SELECT item, value FROM ids_baseline WHERE host_id = ? AND kind = 'fim' AND item IN (" . implode(',', array_fill(0, count($paths), '?')) . ')',
                array_merge([$id], $paths))->fetchAll(PDO::FETCH_KEY_PAIR);
            $bashNow['ids_obs']['fim_count'] = (int) q('SELECT fim_count FROM ids_state WHERE host_id = ?', [$id])->fetchColumn();
        }
        $items = self::compare($core, $bashNow);
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
     * 1.357.0: IDS als proef. Wat de bash-agent voor het IDS waarnam, bewaren om naast de Go-agent te leggen. Alleen als
     * de proef op deze server loopt, en alleen wat vergeleken wordt: van bestanden de SHA-256 (nooit inhoud), gebruikers,
     * groepen, kernelmodules, verdachte processen en de diensten die automatisch starten.
     * @param array<string, mixed> $ids
     */
    public static function noteBashIds(int $hostId, array $ids): void
    {
        $s = AgentData::get($hostId, 'shadow');
        if ($s === null || strtotime((string) ($s['at'] ?? '')) < time() - 600) {
            return;
        }
        $prev = AgentData::get($hostId, 'ids_obs_bash') ?? [];
        AgentData::put($hostId, 'ids_obs_bash', [
            'at' => time(),
            'files' => array_slice(array_filter((array) ($ids['files'] ?? []), static fn($h) => is_string($h) && preg_match('/^[0-9a-f]{64}$/', $h)), 0, 1000, true),
            'users' => array_slice((array) ($ids['users'] ?? []), 0, 2000), 'groups' => (array) ($ids['groups'] ?? []),
            'modules' => array_slice((array) ($ids['modules'] ?? []), 0, 2000),
            'procs' => array_map(static fn($p) => ['pid' => (int) ($p['pid'] ?? 0), 'exe' => mb_substr((string) ($p['exe'] ?? ''), 0, 300)], array_slice(array_filter((array) ($ids['procs'] ?? []), 'is_array'), 0, 20)),
            // de bash-agent stuurt de lijst met diensten maar eens per 10 minuten: de vorige bewaren
            'units' => isset($ids['units']) && is_array($ids['units']) ? array_slice($ids['units'], 0, 500) : ($prev['units'] ?? null),
            // 1.359.0: de rest van het waarnemen. Hardening komt eens per uur mee, SUID en de pakketcontrole als ze er zijn.
            'web' => array_map(static fn($w) => [(string) ($w['tool'] ?? ''), (string) ($w['web'] ?? ''), (string) ($w['user'] ?? '')], array_slice(array_filter((array) ($ids['web'] ?? []), 'is_array'), 0, 20)),
            'conns' => array_map(static fn($c) => [(string) ($c['kind'] ?? ''), (string) ($c['proc'] ?? ''), (string) ($c['peer'] ?? '')], array_slice(array_filter((array) ($ids['conns'] ?? []), 'is_array'), 0, 200)),
            'out' => array_slice(array_filter((array) ($ids['out'] ?? []), 'is_array'), 0, 100),
            'hard' => is_array($ids['hard'] ?? null) ? array_slice(array_map('strval', $ids['hard']), 0, 80, true) : ($prev['hard'] ?? null),
            'suid' => is_array($ids['suid'] ?? null) ? array_slice($ids['suid'], 0, 2000) : null,
            'pkgdiff' => is_array($ids['pkgdiff'] ?? null) ? array_slice($ids['pkgdiff'], 0, 100) : null,
            'fimc' => array_slice((array) ($ids['fimc'] ?? []), 0, 20),
            'has2' => array_key_exists('fimc', $ids),   // stuurt deze bash-agent het tweede deel mee?
        ]);
    }

    /**
     * 1.361.0: endpoint-sensoren als proef: de stand van de sensoren die de bash-agent meldde, om naast de Go-agent te leggen
     * @param array<string, mixed> $info
     */
    public static function noteBashEdr(int $hostId, array $info): void
    {
        $s = AgentData::get($hostId, 'shadow');
        if ($s === null || strtotime((string) ($s['at'] ?? '')) < time() - 600) {
            return;
        }
        $keep = [];
        foreach (['audit', 'file', 'ctr', 'fam', 'epel', 'systemd', 'yara'] as $k) {
            if (isset($info[$k]) && is_scalar($info[$k])) {
                $keep[$k] = mb_substr((string) $info[$k], 0, 20);
            }
        }
        AgentData::put($hostId, 'edr_obs_bash', ['at' => time()] + $keep);
    }

    /** 1.363.0: Programma's als proef: hoeveel programma's de bash-agent zag draaien */
    public static function noteBashApps(int $hostId, int $n): void
    {
        $s = AgentData::get($hostId, 'shadow');
        if ($s !== null && strtotime((string) ($s['at'] ?? '')) >= time() - 600) {
            AgentData::put($hostId, 'apps_obs_bash', ['at' => time(), 'n' => max(0, $n)]);
        }
    }

    /**
     * 1.362.0: ransomware als proef: welke mappen de bash-agent bewaakt en welke hij te kiezen geeft
     * @param array<string, mixed> $ransom
     */
    public static function noteBashRansom(int $hostId, array $ransom): void
    {
        $s = AgentData::get($hostId, 'shadow');
        if ($s === null || strtotime((string) ($s['at'] ?? '')) < time() - 600) {
            return;
        }
        AgentData::put($hostId, 'ransom_obs_bash', ['at' => time(), 'on' => !empty($ransom['on']),
            'paths' => array_values(array_map(static fn($p) => mb_substr((string) ($p['p'] ?? ''), 0, 255), array_slice(array_filter((array) ($ransom['paths'] ?? []), 'is_array'), 0, 20))),
            'cand' => array_values(array_map(static fn($c) => mb_substr(implode(' ', array_map('strval', (array) $c)), 0, 300), array_slice((array) ($ransom['cand'] ?? []), 0, 50)))]);
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
        $apB = AgentData::get($hostId, 'apps_obs_bash');
        if (is_array($apB) && (int) ($apB['at'] ?? 0) >= $min) {
            $out['apps_obs'] = $apB;
        }
        $rsB = AgentData::get($hostId, 'ransom_obs_bash');
        if (is_array($rsB) && (int) ($rsB['at'] ?? 0) >= $min) {
            $out['ransom_obs'] = $rsB;
        }
        $edrB = AgentData::get($hostId, 'edr_obs_bash');
        if (is_array($edrB) && (int) ($edrB['at'] ?? 0) >= $min) {
            $out['edr_obs'] = $edrB;
        }
        $idsB = AgentData::get($hostId, 'ids_obs_bash');
        if (is_array($idsB) && (int) ($idsB['at'] ?? 0) >= $min) {
            $out['ids_obs'] = $idsB;
        }
        // 1.347.0: de softwarelijst die de bash-agent stuurde (Kwetsbaarheden of End-of-life aan)
        $inv = q('SELECT mgr, os_id, os_version, packages, exposed, kernel_running, kernel_latest FROM vuln_inventory WHERE host_id = ?', [$hostId])->fetch();
        if ($inv) {
            $out['inventory'] = ['list' => json_decode((string) $inv['packages'], true) ?: [], 'exposed' => json_decode((string) $inv['exposed'], true) ?: []] + $inv;
        }
        foreach (q("SELECT metric, value, updated_at FROM agent_data WHERE host_id = ? AND (metric IN ('cpu', 'mem', 'boot', 'ports', 'proclist', 'svclist', 'patch', 'time', 'smart', 'ups', 'wg') OR metric LIKE 'path:%' OR metric LIKE 'container:%' OR metric LIKE 'pveg:%' OR metric LIKE 'pves:%' OR metric = 'truenas' OR metric LIKE 'disk:%' OR metric LIKE 'service:%' OR metric LIKE 'cert:%' OR metric LIKE 'proc:%'
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
            if (str_starts_with($m, 'pveg:') || str_starts_with($m, 'pves:') || $m === 'truenas') {   // 1.354.0: Proxmox-guests en -opslag, TrueNAS
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $m === 'truenas' ? $out['truenas'] = $d : $out[str_starts_with($m, 'pveg:') ? 'pveg' : 'pves'][substr($m, 5)] = $d;
                }
            } elseif (str_starts_with($m, 'container:')) {   // 1.351.0: per container (sleutel: naam, of gebruiker/naam bij rootless)
                $d = json_decode((string) $r['value'], true);
                if (is_array($d)) {
                    $out['containers'][substr($m, 10)] = $d;
                }
            } elseif ($m === 'smart' || $m === 'ups' || $m === 'wg') {   // 1.348.0; 1.355.0: WireGuard
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
            // 1.367.3: meldt de Go-agent dat de bash-agent (of een eigen trage controle) bezig was tijdens zijn meting, dan
            // telt het verschil niet: op een server met één kern meet hij dan het werk van de ander mee
            $busy = !empty($go['cpu_disturbed']);
            $add('cpu: gebruik (%)', round((float) ($bash['cpu']['pct'] ?? 0), 1), round((float) ($go['cpu']['pct'] ?? 0), 1) . ($busy ? ' · ' . t('de bash-agent was bezig tijdens de meting') : ''),
                $busy || $near($bash['cpu']['pct'] ?? null, $go['cpu']['pct'] ?? null, 25));
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
                        // 1.356.2: de bash-agent bewaart de details een tijd; is de klok na het opstarten gelijkgezet, dan scheelt
                        // "sinds" een paar seconden: 15 seconden marge
                        'since' => ($b['since'] ?? null) === null ? ($g['since'] ?? null) === null : $near($b['since'], $g['since'] ?? null, 15),
                        'log_errors' => ((int) ($b['log_errors'] ?? 0) > 0) === ((int) ($g['log_errors'] ?? 0) > 0),
                    ];
                    // 1.356.2: is de dienst opnieuw gestart sinds de (bewaarde) meting van de bash-agent, dan zijn een ander
                    // hoofdproces en een later begin geen verschil
                    if (!$parts['pid'] && is_numeric($b['since'] ?? null) && is_numeric($g['since'] ?? null) && (int) $g['since'] > (int) $b['since'] + 15) {
                        $parts['pid'] = $parts['since'] = $parts['restarts'] = true;
                    }
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
        // 1.365.0: de reverse proxy als proef: zou de Go-agent precies het beleid schrijven dat de bash-agent schreef? De
        // Go-agent schrijft, test en herlaadt in de proef niets; hij legt zijn beleid naast de bestanden die er staan.
        if (is_array($go['proxy_obs'] ?? null) && array_key_exists('same_http', $go['proxy_obs'])) {
            $po = $go['proxy_obs'];
            $same = !empty($po['same_http']) && !empty($po['same_srv']);
            $ptx = t('{k}, {n} sites', ['k' => (string) ($po['kind'] ?? '?'), 'n' => count((array) ($po['sites'] ?? []))]);
            // Is het beleid net veranderd en heeft de bash-agent het nog niet doorgevoerd, dan zegt een verschil niets
            if ((string) ($po['applied'] ?? '') === (string) ($po['hash'] ?? '')) {
                $add('Reverse proxy (proef): het beleid dat de Go-agent zou schrijven', $ptx, $same ? $ptx : t('een ander beleid'), $same);
            }
        }
        // 1.363.0: Programma's als proef: hoeveel programma's er draaien. Er start en stopt altijd wel iets tussen de twee
        // metingen: 10% mag verschillen (minstens 4).
        if (is_array($bash['apps_obs'] ?? null) && is_array($go['apps_obs'] ?? null)) {
            [$an, $gn] = [(int) ($bash['apps_obs']['n'] ?? 0), (int) ($go['apps_obs']['n'] ?? 0)];
            $add("Programma's (proef): draaiende programma's", (string) $an, (string) $gn, abs($an - $gn) <= max(4, intdiv(max($an, $gn), 10)));
        }
        // 1.362.0: ransomware als proef: welke mappen bewaakt worden en welke er te kiezen zijn. De Go-agent plaatst in de
        // proef geen lokaasbestanden.
        if (is_array($bash['ransom_obs'] ?? null) && !empty($bash['ransom_obs']['on']) && is_array($go['ransom_obs'] ?? null)) {
            foreach (['paths' => 'Ransomware (proef): bewaakte mappen', 'cand' => 'Ransomware (proef): mappen om uit te kiezen'] as $rk => $rname) {
                $rb = array_map('strval', (array) ($bash['ransom_obs'][$rk] ?? []));
                $rg = array_map('strval', (array) ($go['ransom_obs'][$rk] ?? []));
                sort($rb);
                sort($rg);
                $rt = static fn(array $m, array $o) => t('{n} mappen', ['n' => count($m)]) . (array_diff($m, $o) ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice(array_values(array_diff($m, $o)), 0, 4))]) : '');
                $add($rname, $rt($rb, $rg), $rt($rg, $rb), $rb === $rg);
            }
        }
        // 1.361.0: endpoint-sensoren als proef: wat er op deze server is om mee te werken (auditd met de regels, inotify,
        // containers, YARA). De Go-agent zet in de proef zelf geen regels en start geen sensoren.
        if (is_array($bash['edr_obs'] ?? null) && is_array($go['edr_obs'] ?? null)) {
            $diff = [];
            foreach (['audit', 'file', 'ctr', 'fam', 'epel', 'systemd', 'yara'] as $k) {
                if (isset($bash['edr_obs'][$k], $go['edr_obs'][$k]) && (string) $bash['edr_obs'][$k] !== (string) $go['edr_obs'][$k]) {
                    $diff[] = $k;
                }
            }
            $et = static fn(array $i) => $diff ? implode(', ', array_map(static fn($k) => $k . '=' . (string) $i[$k], $diff)) : t('auditd {a}, bestanden {f}, containers {c}', ['a' => (string) ($i['audit'] ?? '?'), 'f' => (string) ($i['file'] ?? '?'), 'c' => (string) ($i['ctr'] ?? '?')]);
            $add('EDR (proef): sensoren', $et($bash['edr_obs']), $et($go['edr_obs']), !$diff);
        }
        // 1.357.0: IDS als proef: wat de twee agents waarnemen (er wordt niets geblokkeerd of gestopt)
        if (is_array($bash['ids_obs'] ?? null) && is_array($go['ids_obs'] ?? null)) {
            [$bi, $gi] = [$bash['ids_obs'], $go['ids_obs']];
            $bf = (array) ($bi['files'] ?? []);
            $gf = (array) ($gi['files'] ?? []);
            $onlyB = array_keys(array_diff_key($bf, $gf));
            $onlyG = array_keys(array_diff_key($gf, $bf));
            $other = array_keys(array_filter(array_intersect_key($bf, $gf), static fn($h, $p) => $h !== $gf[$p], ARRAY_FILTER_USE_BOTH));
            $ft = static fn(array $m, array $only) => t('{n} bestanden', ['n' => count($m)]) . ($only ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($only, 0, 5))]) : '')
                . ($other ? ' · ' . t('andere inhoud: {l}', ['l' => implode(', ', array_slice($other, 0, 5))]) : '');
            // Een bestand dat net tussen de twee metingen verandert (bv. resolv.conf) is geen verschil van de agent: één mag
            $add('IDS (proef): gevoelige bestanden', $ft($bf, $onlyB), $ft($gf, $onlyG), !$onlyB && !$onlyG && count($other) <= 1);
            $lst = static function (mixed $l): array {
                $m = array_map(static fn($x) => is_array($x) ? implode(':', array_map('strval', $x)) : (string) $x, is_array($l) ? $l : []);
                sort($m);
                return $m;
            };
            $cmp = static function (string $name, string $unit, array $b, array $g, int $tol = 0) use ($add): void {
                $ob = array_values(array_diff($b, $g));
                $og = array_values(array_diff($g, $b));
                $tx = static fn(array $m, array $only) => count($m) . ' ' . $unit . ($only ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($only, 0, 6))]) : '');
                $add($name, $tx($b, $ob), $tx($g, $og), count($ob) + count($og) <= $tol);
            };
            $cmp('IDS (proef): gebruikers', t('gebruikers'), $lst($bi['users'] ?? []), $lst($gi['users'] ?? []));
            $grp = static function (mixed $g): array {
                $m = [];
                foreach (is_array($g) ? $g : [] as $name => $members) {
                    $mm = array_map('strval', (array) $members);
                    sort($mm);
                    $m[] = $name . '=' . implode(',', $mm);
                }
                sort($m);
                return $m;
            };
            $cmp('IDS (proef): groepen met rechten', t('groepen'), $grp($bi['groups'] ?? []), $grp($gi['groups'] ?? []));
            $cmp('IDS (proef): kernelmodules', t('modules'), $lst($bi['modules'] ?? []), $lst($gi['modules'] ?? []), 1);
            $pr = static fn(mixed $l): array => array_values(array_unique(array_map(static fn($p) => (string) ($p['exe'] ?? ''), array_filter(is_array($l) ? $l : [], 'is_array'))));
            $cmp('IDS (proef): verdachte processen', t('processen'), $pr($bi['procs'] ?? []), $pr($gi['procs'] ?? []), 1);
            if (is_array($bi['units'] ?? null) && is_array($gi['units'] ?? null)) {
                $cmp('IDS (proef): diensten die automatisch starten', t('diensten'), $lst($bi['units']), $lst($gi['units']));
            }
            // 1.359.0: de rest van het waarnemen (Go-agent 0.28.0)
            if (!empty($bi['has2']) && array_key_exists('fimc', $gi)) {
                // Processen en verbindingen komen en gaan tussen de twee metingen: één verschil mag
                $web = static fn(mixed $l): array => array_values(array_unique(array_map(static fn($w) => is_array($w) ? (isset($w['tool']) ? "{$w['tool']}|{$w['web']}|{$w['user']}" : implode('|', array_map('strval', $w))) : '', is_array($l) ? $l : [])));
                $cmp('IDS (proef): webshells', t('processen'), $web($bi['web'] ?? []), $web($gi['web'] ?? []), 1);
                $con = static fn(mixed $l): array => array_values(array_unique(array_map(static fn($c) => is_array($c) ? (isset($c['kind']) ? "{$c['kind']}|{$c['proc']}|{$c['peer']}" : implode('|', array_map('strval', $c))) : '', is_array($l) ? $l : [])));
                $cmp('IDS (proef): verdachte verbindingen', t('verbindingen'), $con($bi['conns'] ?? []), $con($gi['conns'] ?? []), 1);
                // Waarheen de server verbindt, verandert elke seconde: de helft mag anders zijn (minstens 4)
                [$ob, $og] = [$lst($bi['out'] ?? []), $lst($gi['out'] ?? [])];
                $cmp('IDS (proef): uitgaande verbindingen', t('adressen'), $ob, $og, max(4, intdiv(count(array_unique(array_merge($ob, $og))), 2)));
                if (is_array($bi['hard'] ?? null) && $bi['hard'] !== [] && is_array($gi['hard'] ?? null)) {
                    $norm = static function (mixed $v): string {
                        $p = explode(',', (string) $v);
                        sort($p);
                        return implode(',', $p);
                    };
                    $diff = [];
                    foreach (array_intersect_key($bi['hard'], $gi['hard']) as $k => $v) {
                        // 1.360.0: van bestanden die iedereen mag wijzigen gaan er hooguit vijf mee, en welke vijf hangt af van
                        // de volgorde waarin de map gelezen wordt: het gaat erom óf ze er zijn
                        if ($k === 'ww_files' ? ((string) $v === '') !== ((string) $gi['hard'][$k] === '') : $norm($v) !== $norm($gi['hard'][$k])) {
                            $diff[] = (string) $k;
                        }
                    }
                    $ht = static fn(array $h) => t('{n} instellingen', ['n' => count($h)]) . ($diff ? ' · ' . implode(', ', array_map(static fn($k) => $k . '=' . mb_substr((string) $h[$k], 0, 40), array_slice($diff, 0, 5))) : '');
                    $add('IDS (proef): hardening', $ht($bi['hard']), $ht($gi['hard']), !$diff);
                }
                if (is_array($bi['suid'] ?? null) && is_array($gi['suid'] ?? null)) {
                    $cmp('IDS (proef): SUID-bestanden', t('bestanden'), $lst($bi['suid']), $lst($gi['suid']), 1);
                }
                if (is_array($bi['pkgdiff'] ?? null) && is_array($gi['pkgdiff'] ?? null)) {
                    $cmp('IDS (proef): pakketcontrole', t('bestanden'), $lst($bi['pkgdiff']), $lst($gi['pkgdiff']), 1);
                }
                $cmp('IDS (proef): mappen om te bewaken', t('mappen'), $lst($bi['fimc'] ?? []), $lst($gi['fimc'] ?? []));
                // 1.360.0: wat de Go-agent in de firewall zou zetten, naast wat de bash-agent zette (de vingerafdruk van de
                // lijst: blokkades, bekende aanvallers, lokaas-poort). De Go-agent zet in de proef zelf niets.
                $fw = is_array($gi['fw'] ?? null) ? $gi['fw'] : null;
                if ($fw !== null && !empty($fw['build']) && (string) ($fw['bash_sig'] ?? '') !== '') {
                    $ftx = t('{n} blokkades, {k} netten', ['n' => (int) ($fw['n'] ?? 0), 'k' => (int) ($fw['kn'] ?? 0)]) . (($fw['decoy'] ?? '') !== '' ? ' · ' . t('lokaas-poort {p}', ['p' => (string) $fw['decoy']]) : '');
                    $same = (string) $fw['sig'] === (string) $fw['bash_sig'];
                    $add('IDS (proef): firewall (wat de Go-agent zou zetten)', $same ? $ftx : t('een andere lijst'), $ftx, $same);
                }
                $iso = is_array($gi['iso'] ?? null) ? $gi['iso'] : null;
                if ($iso !== null && (string) ($iso['bash_sig'] ?? '') !== '') {
                    $itx = t('{n} adressen blijven open', ['n' => (int) ($iso['n'] ?? 0)]);
                    $same = (string) ($iso['sig'] ?? '') === (string) $iso['bash_sig'];
                    $add('IDS (proef): isoleren (wat de Go-agent open zou laten)', $same ? $itx : t('een andere lijst'), $itx, $same);
                }
                if (is_array($gi['fim'] ?? null) && is_array($bi['fim_base'] ?? null)) {
                    $gs = (array) ($gi['fim']['sample'] ?? []);
                    $other = array_keys(array_filter($gs, static fn($v, $p) => ($bi['fim_base'][$p] ?? null) !== mb_substr((string) $v, 0, 255), ARRAY_FILTER_USE_BOTH));
                    $gn = (int) ($gi['fim']['n'] ?? 0);
                    $bn = (int) ($bi['fim_count'] ?? 0);
                    // Een bestand dat net verandert of erbij komt tussen de twee metingen: twee mag
                    $add('IDS (proef): bestanden bewaken', t('{n} bestanden', ['n' => $bn]),
                        t('{n} bestanden', ['n' => $gn]) . ($other ? ' · ' . t('andere waarde: {l}', ['l' => implode(', ', array_slice($other, 0, 4))]) : ''), abs($gn - $bn) <= 2 && count($other) <= 2);
                }
            }
        }
        // 1.355.0: WireGuard: welke peers er zijn en hun endpoint (de handshake en het verkeer lopen door en tellen niet)
        if (is_array($bash['wg'] ?? null) && is_array($go['wg'] ?? null)) {
            $wp = static function (array $l): array {
                $m = [];
                foreach ($l as $p) {
                    if (is_array($p) && isset($p['id'])) {
                        $m[] = $p['id'] . (($p['endpoint'] ?? '') !== '' ? ' → ' . $p['endpoint'] : '') . ((int) ($p['hs'] ?? -1) < 0 ? ' (' . t('nog geen handshake') . ')' : '');
                    }
                }
                sort($m);
                return $m;
            };
            [$bw, $gw] = [$wp($bash['wg']), $wp($go['wg'])];
            $add('WireGuard: peers', implode(', ', array_slice($bw, 0, 10)) ?: '–', implode(', ', array_slice($gw, 0, 10)) ?: '–', $bw === $gw);
        }
        // 1.354.0: Proxmox (guests en opslag) en TrueNAS (versie en apps)
        if (is_array($bash['pveg'] ?? null) && $bash['pveg'] && is_array($go['pve'] ?? null)) {
            $gconf = [];
            foreach ((array) ($go['pve']['conf'] ?? []) as $c) {
                if (is_array($c) && isset($c[1])) {
                    $gconf[(int) $c[1]] = $c;
                }
            }
            $gg = [];
            foreach (['qemu' => 'vm', 'lxc' => 'ct'] as $src => $kind) {
                foreach ((array) ($go['pve'][$src] ?? []) as $g) {
                    if (is_array($g) && (int) ($g['vmid'] ?? 0) > 0) {
                        $id = (int) $g['vmid'];
                        $gg[$id] = "$kind · " . mb_substr((string) ($g['name'] ?? ''), 0, 80) . ' · ' . preg_replace('/[^a-z-]/', '', (string) ($g['status'] ?? ''))
                            . ((int) ($gconf[$id][2] ?? 0) ? ' · onboot' : '') . ((int) ($gconf[$id][3] ?? 0) ? ' · agent' : '');
                    }
                }
            }
            $bg = [];
            foreach ($bash['pveg'] as $id => $g) {
                $bg[(int) $id] = ($g['kind'] ?? '') . ' · ' . ($g['name'] ?? '') . ' · ' . ($g['status'] ?? '') . (!empty($g['onboot']) ? ' · onboot' : '') . (!empty($g['agent']) ? ' · agent' : '');
            }
            $onlyB = array_keys(array_diff_key($bg, $gg));
            $onlyG = array_keys(array_diff_key($gg, $bg));
            $gn = static fn(array $m, array $only): string => t('{n} guests', ['n' => count($m)]) . ($only ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($only, 0, 8))]) : '');
            $add('Proxmox: guests', $gn($bg, $onlyB), $gn($gg, $onlyG), !$onlyB && !$onlyG);
            foreach (array_intersect_key($bg, $gg) as $id => $txt) {
                $add("Proxmox-guest $id", $txt, $gg[$id], $txt === $gg[$id]);
            }
            $bsn = array_keys((array) ($bash['pves'] ?? []));
            $gsn = array_values(array_filter(array_map(static fn($s) => is_array($s) ? (string) ($s['storage'] ?? '') : '', (array) ($go['pve']['storage'] ?? []))));
            sort($bsn);
            sort($gsn);
            $add('Proxmox: opslag', implode(', ', $bsn) ?: '–', implode(', ', $gsn) ?: '–', $bsn === $gsn);
        }
        if (is_array($bash['truenas'] ?? null) && is_array($go['truenas'] ?? null)) {
            $ba = [];
            foreach ((array) ($bash['truenas']['apps'] ?? []) as $x) {
                $ba[] = ($x['n'] ?? '') . ' ' . ($x['st'] ?? '');
            }
            $ga = [];
            foreach ((array) ($go['truenas']['apps'] ?? []) as $x) {
                if (is_array($x) && ($x['name'] ?? '') !== '') {
                    $ga[] = $x['name'] . ' ' . strtoupper((string) ($x['state'] ?? ''));
                }
            }
            sort($ba);
            sort($ga);
            $tv = static fn(string $v, array $apps): string => $v . ' · ' . t('{n} apps', ['n' => count($apps)]) . ($apps ? ': ' . implode(', ', array_slice($apps, 0, 8)) : '');
            $add('TrueNAS', $tv((string) ($bash['truenas']['v'] ?? ''), $ba), $tv(trim((string) ($go['truenas']['version'] ?? '')), $ga), (string) ($bash['truenas']['v'] ?? '') === trim((string) ($go['truenas']['version'] ?? '')) && $ba === $ga);
        }
        // 1.351.0: containers (Podman en Docker): welke er zijn, en per container de status, health, image en pod
        if (is_array($bash['containers'] ?? null) && $bash['containers'] && is_array($go['podman']['containers'] ?? null)) {
            $gcx = [];
            foreach ($go['podman']['containers'] as $c) {
                if (is_array($c) && ($c['name'] ?? '') !== '') {
                    $gcx[mb_substr((($c['user'] ?? 'root') !== 'root' ? $c['user'] . '/' : '') . $c['name'], 0, 150)] = $c;
                }
            }
            $bcx = $bash['containers'];
            $onlyB = array_keys(array_diff_key($bcx, $gcx));
            $onlyG = array_keys(array_diff_key($gcx, $bcx));
            $cn = static fn(array $m, array $only): string => t('{n} containers', ['n' => count($m)]) . ($only ? ' · ' . t('alleen hier: {l}', ['l' => implode(', ', array_slice($only, 0, 6))]) : '');
            $add('containers: lijst', $cn($bcx, $onlyB), $cn($gcx, $onlyG), !$onlyB && !$onlyG);
            $ct = static fn(array $c): string => implode(' · ', array_filter([(string) ($c['state'] ?? ''), (string) ($c['health'] ?? ''), mb_substr((string) ($c['image'] ?? ''), 0, 60),
                ($c['pod'] ?? '') !== '' ? 'pod ' . $c['pod'] : '', t('{n} herstarts', ['n' => (int) ($c['restarts'] ?? 0)])]));
            foreach (array_intersect_key($bcx, $gcx) as $key => $b) {
                $g = $gcx[$key];
                $add('container ' . mb_substr((string) $key, 0, 80), $ct($b), $ct($g), (string) ($b['state'] ?? '') === (string) ($g['state'] ?? '') && (string) ($b['health'] ?? '') === (string) ($g['health'] ?? '')
                    && (string) ($b['image'] ?? '') === (string) ($g['image'] ?? '') && (string) ($b['pod'] ?? '') === (string) ($g['pod'] ?? '') && (int) ($b['restarts'] ?? 0) === (int) ($g['restarts'] ?? 0)
                    && (int) ($b['exit_code'] ?? 0) === (int) ($g['exit_code'] ?? 0));
            }
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
