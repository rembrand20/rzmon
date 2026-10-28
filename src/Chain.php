<?php
declare(strict_types=1);

/**
 * Signalen en ketens (1.111.0). Alles wat op een aanval kan wijzen, wordt een signaal (ids_signals) met de MITRE-
 * tactieken en -technieken erbij: treffers van de endpoint-regels, afwijkingen (Anomaly) en de IDS-incidenten van
 * vóór de endpoint-bewaking (logins, brute force, webaanvallen, nieuwe gebruikers …). Na elk rapport kijkt RzMon of
 * de signalen van die server samen een keten vormen (binnen edr_chain_min minuten, standaard 60):
 * - web:     een webaanval, daarna code op de webserver (webshell, shell van de webserver, nieuw bestand in de webmap);
 * - login:   inloggen (verdacht: nieuw IP, na brute force, ongewoon tijdstip) en daarna rechten of een achterdeur;
 * - dropper: een programma binnenhalen en daarna contact naar buiten;
 * - multi:   drie of meer verschillende stappen van een aanval;
 * - anoms:   veel afwijkingen tegelijk;
 * - lateral: een login vanaf een andere eigen server waar net een aanval was.
 * Een keten wordt een IDS-incident (type chain) met alle stappen als bewijs; in de modus Blokkeren (en met
 * edr_chain_act aan) blokkeert RzMon de aanvaller en isoleert hij de server bij web en dropper.
 */
final class Chain
{
    /** Nieuwe signalen in dit verzoek per server (alleen dan wordt er naar ketens gekeken) */
    private static array $new = [];

    /** Score van IDS-incidenten (anders: critical 60, warning 30) */
    private const SCORES = ['breakin' => 70, 'newip' => 40, 'rootlogin' => 30, 'webshell' => 70, 'revshell' => 70, 'out_bad' => 60, 'decoy_user' => 50, 'decoy_port' => 40, 'web_cve' => 70];
    private const LOGIN = ['ssh_login', 'newip', 'rootlogin', 'breakin'];
    private const WEB = ['web_payload', 'web_attack', 'web_cve'];
    private const NOISE = ['discovery', 'reconnaissance', 'resource-development'];

    public static function on(): bool
    {
        return setting('edr_chain', '1') === '1';
    }

    /**
     * Signaal vastleggen. $dedupe: niet opnieuw als hetzelfde (dkey) binnen zoveel minuten al was; $at: tijd van de
     * gebeurtenis zelf (anders nu), zodat de stappen van een keten in de goede volgorde staan. @return int|null id
     */
    public static function signal(array $host, string $src, string $feature, array $tactics, array $techs, string $label, int $score,
                                  ?string $ip, ?int $ref, string $dkey, ?array $data = null, int $dedupe = 0, ?string $at = null): ?int
    {
        $hid = (int) $host['id'];
        $dkey = mb_substr($dkey, 0, 255);
        if ($dedupe > 0 && q('SELECT 1 FROM ids_signals WHERE host_id = ? AND dkey = ? AND at >= NOW() - INTERVAL ? MINUTE LIMIT 1', [$hid, $dkey, $dedupe])->fetchColumn()) {
            return null;
        }
        $tactics = $tactics ?: Mitre::tacticsOf($techs);
        q('INSERT INTO ids_signals (host_id, at, src, feature, tactics, techs, label, score, ip, ref, dkey, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$hid, $at ?? now(), $src, mb_substr($feature, 0, 20), mb_substr(implode(',', array_unique($tactics)), 0, 160), mb_substr(implode(',', array_unique($techs)), 0, 160),
                mb_substr($label, 0, 250), max(0, min(100, $score)), $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null, $ref, $dkey,
                $data !== null ? mb_substr((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 4000) : null]);
        $id = (int) db()->lastInsertId();
        self::$new[$hid][] = $id;
        return $id;
    }

    /** Signaal bij een IDS-incident (nieuw of opgeteld; hetzelfde hooguit eens per 10 minuten) */
    public static function fromIncident(array $host, string $type, string $key, int $sev, string $title, ?string $ip, ?int $incidentId): void
    {
        if (in_array($type, ['edr', 'anomaly', 'chain', 'harden', 'ti_ip', 'ti_malware', 'suricata', 'dns_bad', 'dns_odd'], true)) {
            return;   // die maken hun eigen signaal, of horen niet bij een aanval
        }
        $score = self::SCORES[$type] ?? ($sev >= 2 ? 60 : 30);
        $techs = Mitre::forIncident($type, $key);
        self::signal($host, 'ids', $type, Mitre::tacticsOf($techs), $techs, $title, $score, $ip, $incidentId, "ids|$type|$key", null, 10);
    }

    /** Login vanaf een eigen server waar net (2 uur) een aanval of keten was: mogelijk verder het netwerk in */
    public static function lateral(array $host, string $ip, string $user): void
    {
        if ($ip === '' || !self::on()) {
            return;
        }
        foreach (q('SELECT id, name FROM hosts WHERE address = ? AND id <> ?', [$ip, $host['id']])->fetchAll() as $from) {
            $hot = q("SELECT 1 FROM ids_signals WHERE host_id = ? AND score >= 60 AND at >= NOW() - INTERVAL 2 HOUR LIMIT 1", [$from['id']])->fetchColumn()
                || q("SELECT 1 FROM ids_incidents WHERE host_id = ? AND type = 'chain' AND last_seen >= NOW() - INTERVAL 2 HOUR LIMIT 1", [$from['id']])->fetchColumn();
            if ($hot) {
                self::signal($host, 'ids', 'lateral', ['lateral-movement'], ['T1021.004'],
                    t('Login van {user} vanaf {from} ({ip}), waar net een aanval was', ['user' => $user, 'from' => $from['name'], 'ip' => $ip]), 60, null, null,
                    "lateral|{$from['id']}|$user", ['from' => (int) $from['id'], 'from_name' => $from['name'], 'ip' => $ip, 'user' => $user], 60);
            }
        }
    }

    /** De ketens (id => titel en uitleg) */
    public static function scenarios(): array
    {
        return [
            'web' => ['title' => 'Webaanval gevolgd door code op de webserver',
                'what' => 'Eerst een aanval op een website, daarna voert de webserver iets uit of komt er een bestand in de webmap. Waarschijnlijk is de aanval gelukt (webshell).'],
            'login' => ['title' => 'Inloggen gevolgd door rechten of een achterdeur',
                'what' => 'Iemand logde in en kort daarna veranderden er rechten, accounts of iets dat blijft na een herstart (cron, systemd, SSH-sleutels). Was jij dit niet, dan zit er iemand op de server.'],
            'dropper' => ['title' => 'Programma binnengehaald en daarna contact naar buiten',
                'what' => 'Er kwam een programma binnen en kort daarna maakte de server een nieuwe verbinding naar buiten. Zo werken malware en cryptominers.'],
            'multi' => ['title' => 'Meerdere stappen van een aanval',
                'what' => 'Binnen korte tijd gebeurden er dingen die bij verschillende stappen van een aanval horen (MITRE ATT&CK-tactieken). Los misschien onschuldig, samen verdacht.'],
            'anoms' => ['title' => 'Veel afwijkingen tegelijk',
                'what' => 'De server deed in korte tijd veel dingen die hij nooit eerder deed. Dat kan een grote update of nieuwe software zijn, maar ook iemand die rondkijkt.'],
            'lateral' => ['title' => 'Mogelijk verder het netwerk in',
                'what' => 'Er werd ingelogd vanaf een andere eigen server waar net een aanval of keten was. Een aanvaller gebruikt een gehackte server vaak om verder te komen.'],
        ];
    }

    private static function has(array $s, array $tactics): bool
    {
        return (bool) array_intersect($s['tactics'], $tactics);
    }

    /** Na een rapport: vormen de signalen van deze server samen een keten? */
    public static function check(array $host): void
    {
        $hid = (int) $host['id'];
        $new = array_flip(self::$new[$hid] ?? []);
        unset(self::$new[$hid]);
        if (!$new || !self::on()) {
            return;
        }
        $win = max(5, min(1440, (int) setting('edr_chain_min', '60')));
        $sig = array_map(static function ($r) {
            $r['tactics'] = array_values(array_filter(explode(',', $r['tactics'])));
            $r['techs'] = array_values(array_filter(explode(',', $r['techs'])));
            $r['data'] = json_decode((string) $r['data'], true) ?: [];
            $r['t'] = strtotime($r['at']);
            return $r;
        }, q('SELECT * FROM ids_signals WHERE host_id = ? AND at >= NOW() - INTERVAL ? MINUTE ORDER BY at, id', [$hid, $win])->fetchAll());
        if (!$sig) {
            return;
        }
        // "daarna", met 10 minuten speling: logs (SWAG) en sensoren komen niet altijd in volgorde binnen
        $after = static fn(array $a, array $b) => $b['t'] >= $a['t'] - 600 && $b['id'] !== $a['id'];
        $found = [];
        // web: webaanval, daarna iets van de webserver
        $web = array_values(array_filter($sig, static fn($s) => $s['src'] === 'ids' && in_array($s['feature'], self::WEB, true)));
        if ($web) {
            $code = array_values(array_filter($sig, static fn($s) => $after($web[0], $s) && ($s['src'] !== 'ids' || in_array($s['feature'], ['webshell', 'revshell'], true))
                && (!empty($s['data']['_web']) || in_array('T1505.003', $s['techs'], true) || in_array($s['feature'], ['webshell', 'revshell'], true))));
            if ($code) {
                $ips = array_values(array_unique(array_filter(array_column($web, 'ip'))));
                $found['web'] = ['steps' => array_merge($web, $code), 'sev' => 2, 'ips' => $ips, 'isolate' => max(array_column($code, 'score')) >= 60, 'key' => 'web|' . ($ips[0] ?? '')];
            }
        }
        // login: inloggen, daarna rechten, accounts of iets dat blijft
        $login = array_values(array_filter($sig, static fn($s) => ($s['src'] === 'ids' && in_array($s['feature'], self::LOGIN, true)) || ($s['src'] === 'anom' && in_array($s['feature'], ['I', 'hour'], true))));
        if ($login) {
            $esc = array_values(array_filter($sig, static fn($s) => $after($login[0], $s) && (int) $s['score'] >= 30 && !in_array($s, $login, true)
                && self::has($s, ['privilege-escalation', 'persistence', 'defense-impairment', 'stealth', 'credential-access'])));
            if ($esc) {
                $found['login'] = ['steps' => array_merge($login, $esc), 'sev' => max(array_column($login, 'score')) >= 40 ? 2 : 1, 'ips' => [], 'isolate' => false, 'key' => 'login'];
            }
        }
        // dropper: binnenhalen (T1105), daarna contact naar buiten
        $in = array_values(array_filter($sig, static fn($s) => in_array('T1105', $s['techs'], true)));
        if ($in) {
            $c2 = array_values(array_filter($sig, static fn($s) => $after($in[0], $s) && !in_array('T1105', $s['techs'], true)
                && ((self::has($s, ['command-and-control']) && ($s['src'] !== 'anom' || in_array($s['feature'], ['O', 'C'], true) || (int) $s['score'] >= 40))
                    || (($s['data']['k'] ?? '') === 'net' && !empty($s['ip'])))));
            if ($c2) {
                $ips = array_values(array_unique(array_filter(array_column($c2, 'ip'))));
                $found['dropper'] = ['steps' => array_merge($in, $c2), 'sev' => 2, 'ips' => $ips, 'isolate' => true, 'key' => 'dropper'];
            }
        }
        // multi: drie of meer tactieken (zonder rondkijken), samen genoeg
        $strong = array_values(array_filter($sig, static fn($s) => (int) $s['score'] >= 30));
        $tac = array_diff(array_unique(array_merge([], ...array_column($strong, 'tactics'))), self::NOISE);
        if (count($tac) >= 3 && array_sum(array_column($strong, 'score')) >= 120 && !isset($found['web']) && !isset($found['dropper'])) {
            $found['multi'] = ['steps' => $strong, 'sev' => 2, 'ips' => [], 'isolate' => false, 'key' => 'multi'];
        }
        // anoms: veel afwijkingen tegelijk
        $an = array_values(array_filter($sig, static fn($s) => $s['src'] === 'anom'));
        if (count($an) >= 3 && array_sum(array_column($an, 'score')) >= 100 && !isset($found['multi'])) {
            $found['anoms'] = ['steps' => $an, 'sev' => 1, 'ips' => [], 'isolate' => false, 'key' => 'anoms'];
        }
        // lateral: login vanaf een eigen server met een aanval
        foreach (array_filter($sig, static fn($s) => $s['feature'] === 'lateral') as $s) {
            $found['lateral'] = ['steps' => [$s], 'sev' => 2, 'ips' => [], 'isolate' => false, 'key' => 'lateral|' . ($s['data']['from'] ?? '')];
        }
        $all = self::scenarios();
        foreach ($found as $id => $c) {
            if (!array_filter($c['steps'], static fn($s) => isset($new[$s['id']]))) {
                continue;   // niets nieuws in deze keten
            }
            self::raise($host, $id, $all[$id], $c);
        }
    }

    private static function raise(array $host, string $id, array $sc, array $c): void
    {
        $hid = (int) $host['id'];
        usort($c['steps'], static fn($a, $b) => [$a['t'], $a['id']] <=> [$b['t'], $b['id']]);
        $steps = array_slice($c['steps'], -15);
        $tactics = array_values(array_unique(array_merge([], ...array_column($steps, 'tactics'))));
        $techs = array_values(array_unique(array_merge([], ...array_column($steps, 'techs'))));
        $existed = q("SELECT 1 FROM ids_incidents WHERE host_id = ? AND type = 'chain' AND dkey = ? AND created_at >= NOW() - INTERVAL 1 DAY", [$hid, mb_substr($c['key'], 0, 255)])->fetchColumn();
        $done = [];
        if (!$existed && ($c['ips'] || $c['isolate']) && setting('edr_chain_act', '1') === '1') {
            if (Ids::blocking()) {
                foreach (array_slice($c['ips'], 0, 5) as $ip) {
                    if (!Ids::internalIp($ip) && ($b = Ids::autoBlock($host, $ip, t('Keten: {what}', ['what' => t($sc['title'])])))) {
                        $done[] = "$ip $b";
                    }
                }
                if ($c['isolate'] && setting('edr_isolate', '1') === '1' && !q('SELECT 1 FROM hosts WHERE id = ? AND isolate_until > NOW()', [$hid])->fetchColumn()) {
                    Ids::isolate($hid, 24, 'RzMon (' . t('automatisch') . ')', '');
                    $done[] = t('server geïsoleerd (24 uur)');
                }
            } else {
                $done[] = t('modus Leren: RzMon greep niet in');
            }
        }
        $line = implode(' → ', array_map(static fn($s) => t(Mitre::TACTIC_NL[$s['tactics'][0] ?? ''] ?? 'Signaal') . ': ' . mb_substr($s['label'], 0, 60),
            array_slice($steps, 0, 4))) . (count($steps) > 4 ? ' → …' : '');
        $detail = $line . ($done ? ' · ⛔ ' . implode(', ', $done) : '');
        $ip = $c['ips'][0] ?? (array_values(array_filter(array_column($steps, 'ip')))[0] ?? null);
        $inc = Ids::raise($host, $c['sev'], 'chain', $c['key'], t('Keten: {what}', ['what' => t($sc['title'])]), $detail, $ip);
        if ($inc) {
            q("DELETE FROM ids_evidence WHERE incident_id = ? AND kind = 'chain'", [$inc]);
            Edr::evidence($inc, 'chain', ['scenario' => $id, '_done' => $done, 'steps' => array_map(static fn($s) => ['id' => (int) $s['id'], 'at' => $s['at'], 'src' => $s['src'],
                'feature' => $s['feature'], 'label' => $s['label'], 'score' => (int) $s['score'], 'tactics' => $s['tactics'], 'techs' => $s['techs'], 'ip' => $s['ip'],
                'ref' => $s['ref'] ? (int) $s['ref'] : null], $steps)], 'chain:' . $id,
                ['title' => t($sc['title']), 'src' => 'chain', 'tags' => $techs, 'author' => '', 'url' => '', 'what' => t($sc['what']), 'fp' => [], 'tactics' => $tactics]);
        }
    }

    // ------------------------------------------------------------------ MITRE-overzicht

    /** Tactieken met de technieken die de laatste $days dagen gezien zijn, en wat de regels kunnen zien (dekking) */
    public static function overview(int $days = 30, ?int $hostId = null): array
    {
        $w = ['at >= NOW() - INTERVAL ? DAY'];
        $p = [max(1, min(90, $days))];
        if ($hostId) {
            $w[] = 'host_id = ?';
            $p[] = $hostId;
        }
        $seen = [];   // tactiek => techniek ('' = zonder) => [n, last, hosts]
        foreach (q('SELECT tactics, techs, COUNT(*) AS n, MAX(at) AS last, GROUP_CONCAT(DISTINCT host_id) AS hosts, MAX(score) AS score
                    FROM ids_signals WHERE ' . implode(' AND ', $w) . ' GROUP BY tactics, techs', $p)->fetchAll() as $r) {
            $tac = array_filter(explode(',', $r['tactics']));
            $techs = array_filter(explode(',', $r['techs'])) ?: [''];
            foreach ($techs as $tech) {
                $own = $tech !== '' ? array_intersect($tac, Mitre::tech($tech)['tactics'] ?? []) : [];
                foreach (($own ?: $tac) as $ta) {
                    $x = &$seen[$ta][$tech];
                    $x = ['n' => ($x['n'] ?? 0) + (int) $r['n'], 'last' => max($x['last'] ?? '', $r['last']), 'score' => max($x['score'] ?? 0, (int) $r['score']),
                        'hosts' => array_values(array_unique(array_merge($x['hosts'] ?? [], array_map('intval', explode(',', (string) $r['hosts'])))))];
                    unset($x);
                }
            }
        }
        $cover = [];
        foreach (Edr::rules() as $r) {
            foreach ((array) ($r['tags'] ?? []) as $tech) {
                foreach (Mitre::tech($tech)['tactics'] ?? [] as $ta) {
                    $cover[$ta][$tech][] = !empty($r['sigma']) ? 'Sigma: ' . $r['title'] : t($r['title']);
                }
            }
        }
        $out = [];
        foreach (Mitre::tactics() as $ta) {
            $s = $ta['slug'];
            $techs = [];
            foreach (array_unique(array_merge(array_keys($seen[$s] ?? []), array_keys($cover[$s] ?? []))) as $tech) {
                $tech = (string) $tech;
                $info = $tech !== '' ? Mitre::tech($tech) : null;
                $techs[] = ['id' => $tech, 'name' => $info['name'] ?? ($tech === '' ? t('overig') : $tech), 'n' => $seen[$s][$tech]['n'] ?? 0,
                    'last' => $seen[$s][$tech]['last'] ?? null, 'score' => $seen[$s][$tech]['score'] ?? 0, 'hosts' => $seen[$s][$tech]['hosts'] ?? [],
                    'rules' => array_slice(array_values(array_unique($cover[$s][$tech] ?? [])), 0, 8), 'nrules' => count(array_unique($cover[$s][$tech] ?? []))];
            }
            usort($techs, static fn($a, $b) => [$b['n'], $b['nrules'], $a['id']] <=> [$a['n'], $a['nrules'], $b['id']]);
            $out[] = $ta + ['n' => array_sum(array_column($techs, 'n')), 'techs' => $techs];
        }
        return ['days' => $p[0], 'tactics' => $out, 'source' => Mitre::data()['source'] ?? '', 'license' => Mitre::data()['license'] ?? ''];
    }

    /** Signalen bij een techniek of tactiek (voor het MITRE-overzicht) */
    public static function signals(int $days, ?int $hostId, ?string $tactic, ?string $tech, int $limit = 100): array
    {
        $w = ['s.at >= NOW() - INTERVAL ? DAY'];
        $p = [max(1, min(90, $days))];
        if ($hostId) {
            $w[] = 's.host_id = ?';
            $p[] = $hostId;
        }
        if ($tactic) {
            $w[] = 'FIND_IN_SET(?, s.tactics)';
            $p[] = $tactic;
        }
        if ($tech !== null) {
            if ($tech === '') {
                $w[] = "s.techs = ''";
            } else {
                $w[] = '(FIND_IN_SET(?, s.techs) OR s.techs LIKE ?)';
                $p[] = $tech;
                $p[] = "%$tech.%";
            }
        }
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'host' => $r['host'], 'at' => $r['at'], 'src' => $r['src'],
            'feature' => $r['feature'], 'label' => $r['label'], 'score' => (int) $r['score'], 'tactics' => array_values(array_filter(explode(',', $r['tactics']))),
            'techs' => array_values(array_filter(explode(',', $r['techs']))), 'ip' => $r['ip'], 'ref' => $r['ref'] ? (int) $r['ref'] : null],
            q('SELECT s.*, h.name AS host FROM ids_signals s JOIN hosts h ON h.id = s.host_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.id DESC LIMIT ' . max(1, min(500, $limit)), $p)->fetchAll());
    }

    /** Ketens (incidenten) voor de pagina */
    public static function list(int $days = 30, ?int $hostId = null): array
    {
        $w = ["i.type = 'chain'", 'i.last_seen >= NOW() - INTERVAL ? DAY'];
        $p = [max(1, min(365, $days))];
        if ($hostId) {
            $w[] = 'i.host_id = ?';
            $p[] = $hostId;
        }
        $out = [];
        foreach (q('SELECT i.*, h.name AS host FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE ' . implode(' AND ', $w) . ' ORDER BY i.last_seen DESC LIMIT 50', $p)->fetchAll() as $i) {
            $ev = q("SELECT data FROM ids_evidence WHERE incident_id = ? AND kind = 'chain' ORDER BY id DESC LIMIT 1", [$i['id']])->fetchColumn();
            $d = json_decode((string) $ev, true) ?: [];
            $out[] = ['id' => (int) $i['id'], 'host_id' => (int) $i['host_id'], 'host' => $i['host'], 'title' => $i['title'], 'severity' => (int) $i['severity'],
                'status' => $i['status'], 'created_at' => $i['created_at'], 'last_seen' => $i['last_seen'], 'hits' => (int) $i['hits'],
                'steps' => $d['ev']['steps'] ?? [], 'done' => $d['ev']['_done'] ?? [], 'what' => $d['rule']['what'] ?? ''];
        }
        return $out;
    }

    /** In de tests */
    public static function reset(): void
    {
        self::$new = [];
    }

    public static function purge(): void
    {
        q('DELETE FROM ids_signals WHERE at < NOW() - INTERVAL 90 DAY');
    }
}
