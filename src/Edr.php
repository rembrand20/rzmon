<?php
declare(strict_types=1);

/**
 * Endpoint-bewaking (EDR-light, 1.110.0). De realtime-watcher van de agent (1.63.0) ziet op het moment zelf:
 * - x: nieuwe processen (auditd, execve): programma, opdrachtregel, ouder, gebruiker;
 * - a: wijzigingen aan bewaakte paden en kernelmodules (auditd, sleutels rzmon_*);
 * - n: nieuwe uitgaande verbindingen en luisterpoorten;
 * - f: nieuwe uitvoerbare bestanden en scripts in tijdelijke mappen en webmappen (inotify);
 * - c: containers (exec, start met volledige rechten).
 * Hier wordt elke gebeurtenis bewaard (Live, 3 dagen), geleerd wat een server normaal doet (Anomaly, 1.111.0) en getoetst aan de eigen regels (EdrRules) en de Sigma-regels (Sigma). Een treffer wordt
 * een IDS-incident met het bewijs erbij; in de modus Blokkeren stopt RzMon het proces, blokkeert het IP of isoleert de
 * server (per soort aan/uit, Instellingen → IDS). Alles wat RzMon zelf doet, wordt ook gemeld.
 * Aan/uit: algemeen (edr_on) en per server (hosts.edr_on); het IDS moet aan staan.
 */
final class Edr
{
    public const KINDS = ['x' => 'exec', 'a' => 'audit', 'n' => 'net', 'f' => 'file', 'c' => 'ctr'];
    /** In Live ook: samenvatting van wat RzMon zelf deed (o) en toegestaan verkeer van RzMon (s = Suricata, d = DNS, w = web) */
    private const FEED_KINDS = self::KINDS + ['o' => 'own', 's' => 'sur', 'd' => 'dns', 'w' => 'web'];
    /** Van RzMon zelf (agent 1.65.0): agent = de agent en wat hij start, rzmon = RzMon op zijn eigen server, net = verkeer met RzMon */
    public const OWN = ['agent', 'rzmon', 'net'];
    /** Per rapport: wat RzMon zelf deed, opgeteld [soort => [aantallen, voorbeelden]] */
    private static array $ownAgg = [];
    private const MAX_EVENTS = 500;
    private static ?array $rules = null;
    /** Processen die RzMon in dit rapport meteen laat stoppen (gaan mee in het antwoord aan de agent) */
    private static array $kills = [];

    public static function onFor(array $host): bool
    {
        return Ids::enabled() && setting('edr_on', '1') === '1' && (int) ($host['edr_on'] ?? 1) === 1 && ($host['mode'] ?? '') === 'agent';
    }

    /** Regel voor de agent: sensoren aan */
    public static function agentLines(array $host): array
    {
        if (!self::onFor($host)) {
            return [];
        }
        // 1.114.0: zo herkent de agent RzMon zelf op zijn eigen server (de map, en de gebruiker van de webserver)
        $user = function_exists('posix_geteuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : get_current_user();
        return [implode("\t", [0, 'edr', 1]), implode("\t", [0, 'edrself', SM_ROOT, preg_replace('/[^A-Za-z0-9._-]/', '', $user)])];
    }

    /** Alle regels die aan staan: eigen regels en (als dat aan staat) Sigma */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $off = array_flip((array) json_decode((string) setting('edr_rules_off', '[]'), true));
        $out = [];
        foreach (EdrRules::all() as $r) {
            if (!isset($off[$r['id']])) {
                $out[] = $r + ['src' => 'rzmon'];
            }
        }
        if (setting('edr_sigma', '1') === '1') {
            foreach (Sigma::rules() as $r) {
                if (!isset($off[$r['id']])) {
                    $out[] = $r + ['sigma' => true];
                }
            }
        }
        return self::$rules = $out;
    }

    /** Na een wijziging van de regels of instellingen (en in de tests) */
    public static function reset(): void
    {
        self::$rules = null;
        self::$kills = [];
    }

    /** Rapport van de agent: "edr": {"ev": [...], "info": {...}}. @return array velden voor het antwoord (edr_kill) */
    public static function process(array $host, array $edr): array
    {
        $hid = (int) $host['id'];
        if (is_array($edr['info'] ?? null)) {
            $info = array_map(static fn($v) => is_scalar($v) ? mb_substr((string) $v, 0, 64) : null, array_slice($edr['info'], 0, 20, true));
            q('UPDATE hosts SET edr_info = ?, edr_at = ? WHERE id = ?', [json_encode($info), now(), $hid]);
        }
        self::$kills = [];
        if (!self::onFor($host)) {
            return [];
        }
        $evs = array_slice(array_values(array_filter((array) ($edr['ev'] ?? []), 'is_array')), 0, self::MAX_EVENTS);
        // 1.131.0: alle gebeurtenissen van dit rapport in één transactie (was: twee schrijfacties per gebeurtenis, elk
        // apart bevestigd; bij honderden gebeurtenissen duurde een rapport zo tientallen seconden)
        in_tx(static function () use ($host, $hid, $evs): void {
            if ($evs) {
                Anomaly::start($host);   // het leren (afwijkingen, 1.111.0) begint bij de eerste gebeurtenis
            }
            foreach ($evs as $ev) {
                $kind = self::KINDS[$ev['k'] ?? ''] ?? null;
                if ($kind === null) {
                    continue;
                }
                $ev = self::clean($ev);
                $f = self::fields($kind, $ev);
                $sig = self::sig($kind, $ev);
                $at = self::when($ev);
                if (in_array($ev['own'] ?? '', self::OWN, true)) {
                    self::own($host, (string) $ev['own'], $kind, $ev, $f, $sig, $at);   // RzMon zelf: loggen, nooit ingrijpen
                    continue;
                }
                $hit = null;
                foreach (self::rules() as $r) {
                    if (in_array($kind, (array) $r['kinds'], true) && Sigma::match($r, $f)) {
                        $hit = self::handle($host, $kind, $ev, $f, $r);
                        break;   // één incident per gebeurtenis: de eerste (eigen regels gaan voor)
                    }
                }
                Anomaly::observe($host, $kind, $ev, $sig, $hit !== null, $at);
                // 1.112.0: dreigingsinformatie: nieuw programma of script opzoeken; verbinding met een bekende botnet-server
                if ($kind === 'file' && !empty($ev['sha']) && ((int) ($ev['elf'] ?? 0) === 1 || (int) ($ev['x'] ?? 0) === 1 || !empty($ev['susp']))) {
                    ThreatIntel::queue('h', (string) $ev['sha'], $hid, $hit['inc'] ?? null, (string) ($ev['path'] ?? ''));
                }
                if ($kind === 'net' && ($ev['dir'] ?? '') === 'out' && $hit === null) {
                    self::knownBad($host, $ev);
                }
                q('INSERT INTO edr_events (host_id, at, kind, sig, data, rule, sev) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$hid, $at, $ev['k'], $sig, json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                        $hit['rule'] ?? null, $hit['sev'] ?? 0]);
            }
            if ($evs) {
                Anomaly::flush($host);
            }
            self::ownFlush($host);
        });
        // 1.113.0: Suricata-alerts, en de YARA-regels naar de agent als die nog niet de nieuwste heeft
        if (is_array($edr['sur'] ?? null)) {
            Suricata::process($host, $edr['sur']);
        }
        if (($edr['info']['yara'] ?? '') === 'ok' && setting('edr_yara', '1') === '1' && ($edr['info']['ysha'] ?? '') !== hash('sha256', self::yaraRules())) {
            return (self::$kills ? ['edr_kill' => array_values(self::$kills)] : []) + ['edr_yara' => base64_encode(self::yaraRules())];
        }
        return self::$kills ? ['edr_kill' => array_values(self::$kills)] : [];
    }

    /**
     * Iets van RzMon zelf (agent 1.65.0): nooit een incident, nooit ingrijpen, niet meetellen voor afwijkingen, ketens of
     * dreigingsinformatie. Wel loggen als "toegestaan": opgeteld per rapport, en apart als een regel raak zou zijn
     * geweest, of bij iets bijzonders (pakketten installeren, zelf herstellen, commando's van RzMon).
     */
    private static function own(array $host, string $own, string $kind, array $ev, array $f, string $sig, string $at): void
    {
        $would = null;
        foreach (self::rules() as $r) {
            if (in_array($kind, (array) $r['kinds'], true) && Sigma::match($r, $f)) {
                $would = $r;
                break;
            }
        }
        $cmd = (string) ($ev['cmd'] ?? '');
        $special = $kind === 'exec' && (preg_match('#/(apt-get|apt|dpkg|dnf|yum|rpm|apk)$#', (string) ($ev['exe'] ?? '')) || str_contains($cmd, '/heal.d/') || str_contains($cmd, 'rzmon-agent-setup'));
        if ($would || $special) {
            $ev['_would'] = $would ? (!empty($would['sigma']) ? 'Sigma: ' . $would['title'] : t($would['title'])) : null;
            q('INSERT INTO edr_events (host_id, at, kind, sig, data, rule, sev, own) VALUES (?, ?, ?, ?, ?, NULL, 0, ?)',
                [$host['id'], $at, array_search($kind, self::KINDS, true) ?: 'x', $sig, json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $own]);
            return;
        }
        $a = &self::$ownAgg[(int) $host['id']][$own];
        $a['n'][$kind] = ($a['n'][$kind] ?? 0) + 1;
        $a['from'] = min($a['from'] ?? $at, $at);
        $a['to'] = max($a['to'] ?? $at, $at);
        $text = match ($kind) {
            'exec' => basename((string) ($ev['exe'] ?? ($ev['comm'] ?? '?'))) . ($cmd !== '' ? ': ' . mb_substr($cmd, 0, 120) : ''),
            'net' => basename((string) ($ev['exe'] ?? ($ev['comm'] ?? '?'))) . (($ev['dir'] ?? '') === 'out' ? ' → ' . ($ev['rip'] ?? '') . ':' . ($ev['rp'] ?? '') : ' ' . t('luistert op poort {p}', ['p' => $ev['lp'] ?? '?'])),
            'audit' => basename((string) ($ev['exe'] ?? '?')) . ' → ' . ($ev['name'] ?? ''),
            default => $sig,
        };
        if (count($a['s'] ?? []) < 25 && !in_array($text, $a['s'] ?? [], true)) {
            $a['s'][] = $text;
        }
        unset($a);
    }

    /** Eén groene regel per soort (agent, RzMon, netwerk) voor dit rapport */
    private static function ownFlush(array $host): void
    {
        foreach (self::$ownAgg[(int) $host['id']] ?? [] as $own => $a) {
            q('INSERT INTO edr_events (host_id, at, kind, sig, data, rule, sev, own) VALUES (?, ?, ?, ?, ?, NULL, 0, ?)',
                [$host['id'], $a['to'], 'o', "own $own", json_encode(['own' => $own, 'n' => $a['n'], 's' => $a['s'] ?? [], 'from' => $a['from']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $own]);
        }
        unset(self::$ownAgg[(int) $host['id']]);
    }

    /** Toegestaan verkeer van RzMon dat anders gemeld zou zijn (Suricata, DNS, web): een groene regel, hooguit eens per uur */
    public static function allowed(array $host, string $kind, string $sig, array $data): void
    {
        $sig = mb_substr($sig, 0, 255);
        if (q('SELECT 1 FROM edr_events WHERE host_id = ? AND kind = ? AND sig = ? AND at >= NOW() - INTERVAL 1 HOUR LIMIT 1', [$host['id'], $kind, $sig])->fetchColumn()) {
            return;
        }
        q('INSERT INTO edr_events (host_id, at, kind, sig, data, rule, sev, own) VALUES (?, ?, ?, ?, ?, NULL, 0, ?)',
            [$host['id'], now(), $kind, $sig, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 'net']);
    }

    /** Alleen bekende velden, begrensd */
    private static function clean(array $ev): array
    {
        $out = [];
        foreach ($ev as $k => $v) {
            if (!is_string($k) || !preg_match('/^[a-z]{1,6}$/', $k)) {
                continue;
            }
            if (is_array($v)) {
                $out[$k] = array_map(static fn($x) => mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) (is_scalar($x) ? $x : '')), 0, 300), array_slice($v, 0, 16));
            } elseif (is_scalar($v)) {
                $out[$k] = mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) $v), 0, $k === 'cmd' || $k === 'pcmd' ? 1000 : 300);
            }
        }
        if (isset($out['cmd'])) {
            $out['cmd'] = Inspect::redact($out['cmd']);
        }
        return $out;
    }

    public static function when(array $ev): string
    {
        $t = (int) ($ev['t'] ?? 0);
        return date('Y-m-d H:i:s', $t > time() - 86400 && $t <= time() + 300 ? $t : time());
    }

    /** De velden zoals Sigma ze kent (process_creation, network_connection, file_event, auditd) */
    public static function fields(string $kind, array $ev): array
    {
        $f = [];
        $exe = (string) ($ev['exe'] ?? '');
        $comm = (string) ($ev['comm'] ?? '');
        $image = $exe !== '' ? $exe : ($comm !== '' ? '/' . $comm : '');
        switch ($kind) {
            case 'exec':
                $argv = array_values((array) ($ev['argv'] ?? []));
                $cmd = (string) ($ev['cmd'] ?? implode(' ', $argv));
                $f = ['Image' => $image, 'CommandLine' => $cmd, 'ParentImage' => (string) ($ev['pexe'] ?? '') ?: (isset($ev['pc']) ? '/' . $ev['pc'] : ''),
                    'ParentCommandLine' => (string) ($ev['pcmd'] ?? ''), 'User' => (string) ($ev['u'] ?? ''), 'CurrentDirectory' => (string) ($ev['cwd'] ?? ''),
                    'type' => ['EXECVE', 'SYSCALL'], 'SYSCALL' => (string) ($ev['sc'] ?? 'execve'), 'exe' => $exe, 'comm' => $comm,
                    'uid' => (string) ($ev['uid'] ?? ''), 'euid' => (string) ($ev['euid'] ?? ($ev['uid'] ?? '')), 'auid' => (string) ($ev['auid'] ?? '')];
                foreach (array_slice($argv, 0, 10) as $i => $a) {
                    $f["a$i"] = (string) $a;
                }
                break;
            case 'audit':
                $f = ['type' => ['PATH', 'SYSCALL'], 'name' => (string) ($ev['name'] ?? ''), 'exe' => $exe, 'comm' => $comm, 'SYSCALL' => (string) ($ev['sc'] ?? ''),
                    'uid' => (string) ($ev['uid'] ?? ''), 'euid' => (string) ($ev['uid'] ?? ''), 'auid' => (string) ($ev['auid'] ?? ''), 'key' => (string) ($ev['key'] ?? ''),
                    'Image' => $image, 'CommandLine' => (string) ($ev['cmd'] ?? '')];
                break;
            case 'net':
                $f = ['Image' => $image, 'DestinationIp' => (string) ($ev['rip'] ?? ''), 'DestinationPort' => (string) ($ev['rp'] ?? ''),
                    'Initiated' => ($ev['dir'] ?? '') === 'out' ? 'true' : 'false', 'SourcePort' => (string) ($ev['lp'] ?? ''), 'User' => (string) ($ev['u'] ?? '')];
                break;
            case 'file':
                $f = ['TargetFilename' => (string) ($ev['path'] ?? ''), 'elf' => (string) ($ev['elf'] ?? '0'), 'x' => (string) ($ev['x'] ?? '0'), 'susp' => (string) ($ev['susp'] ?? ''),
                    'yara' => (string) ($ev['yara'] ?? '')];
                break;
            case 'ctr':
                $f = ['act' => (string) ($ev['act'] ?? ''), 'priv' => (string) ($ev['priv'] ?? '0'), 'name' => (string) ($ev['name'] ?? ''), 'image' => (string) ($ev['img'] ?? ''),
                    'CommandLine' => (string) ($ev['cmd'] ?? '')];
                break;
        }
        $f['_raw'] = implode(' ', array_filter(array_map(static fn($v) => is_array($v) ? implode(' ', $v) : (string) $v, $f), 'strlen'));
        return $f;
    }

    /** Wat iets "is" voor deze server (zonder getallen en wisselende delen): voor edr_seen en "Dit is normaal" */
    public static function sig(string $kind, array $ev): string
    {
        $n = static fn(string $s) => (string) preg_replace(['/\d+/', '/[0-9a-f]{8,}/i'], ['#', '…'], $s);
        $s = match ($kind) {
            'exec' => ($ev['exe'] ?? ('/' . ($ev['comm'] ?? '?'))) . ' < ' . ($ev['pc'] ?? '?'),
            'audit' => ($ev['key'] ?? '?') . ' ' . ($ev['name'] ?? '') . ' ' . basename((string) ($ev['exe'] ?? '')),
            'net' => ($ev['dir'] ?? '?') . ' ' . basename((string) ($ev['exe'] ?? ($ev['comm'] ?? '?'))) . ' ' . (($ev['dir'] ?? '') === 'out' ? ($ev['rip'] ?? '') . ':' : ':') . ($ev['rp'] ?? $ev['lp'] ?? ''),
            'file' => dirname((string) ($ev['path'] ?? '')) . '/' . preg_replace('/^.*(\.[a-z0-9]{1,5})$/i', '*$1', basename((string) ($ev['path'] ?? ''))),
            'ctr' => ($ev['act'] ?? '?') . ' ' . ($ev['name'] ?? '?'),
            default => '?',
        };
        return mb_substr($n($s), 0, 255);
    }

    /** Een regel is raak: incident, bewijs, reactie, signaal. @return array{rule: string, sev: int}|null null = "Dit is normaal" */
    private static function handle(array $host, string $kind, array $ev, array $f, array $r): ?array
    {
        $key = $r['id'] . '|' . ($kind === 'exec' ? ($f['Image'] . '<' . ($ev['pc'] ?? '')) : self::sig($kind, $ev));
        if (Ids::ignored((int) $host['id'], 'edr', $key)) {
            return null;   // "Dit is normaal" voor deze server: niet melden en niet ingrijpen
        }
        $sigma = !empty($r['sigma']);
        $level = (int) ($r['level'] ?? 0);
        $sev = $sigma ? ($level >= 2 ? 2 : 1) : (int) $r['sev'];
        $acts = $sigma ? ($level >= 3 ? ['kill'] : []) : (array) $r['act'];
        $what = match ($kind) {
            'exec', 'audit' => basename((string) ($f['Image'] ?: ($ev['name'] ?? '?'))),
            'net' => basename((string) $f['Image']) . ' → ' . ($ev['rip'] ?? '') . ':' . ($ev['rp'] ?? $ev['lp'] ?? ''),
            'file' => (string) ($ev['path'] ?? ''),
            'ctr' => (string) ($ev['name'] ?? ''),
            default => '',
        };
        $title = ($sigma ? 'Sigma: ' . $r['title'] : t($r['title'])) . ($what !== '' ? ' · ' . $what : '');
        $facts = self::facts($kind, $ev);
        // Reageren (alleen in de modus Blokkeren, per soort aan/uit)
        $done = [];
        if ($acts && Ids::blocking()) {
            $pid = (int) ($ev['pid'] ?? 0);
            $st = (int) ($ev['st'] ?? 0);
            if (in_array('kill', $acts, true) && setting('edr_kill', '1') === '1' && $pid > 1 && $st > 0) {
                q('INSERT IGNORE INTO ids_kills (host_id, pid, start, dkey, created_at) VALUES (?, ?, ?, ?, ?)',
                    [$host['id'], $pid, $st, mb_substr($r['id'] . '|' . self::sig($kind, $ev), 0, 255), now()]);
                self::$kills["$pid:$st"] = [$pid, $st];
                $done[] = t('proces {pid} wordt gestopt', ['pid' => $pid]);
            }
            $ip = (string) ($ev['rip'] ?? '');
            if (in_array('block', $acts, true) && setting('edr_block', '1') === '1' && $ip !== '' && !Ids::internalIp($ip)
                && ($b = Ids::autoBlock($host, $ip, t('Endpoint: {r}', ['r' => $r['id']])))) {
                $done[] = "$ip $b";
            }
            if (in_array('isolate', $acts, true) && setting('edr_isolate', '1') === '1'
                && !q('SELECT 1 FROM hosts WHERE id = ? AND isolate_until > NOW()', [$host['id']])->fetchColumn()) {
                Ids::isolate((int) $host['id'], 24, 'RzMon (' . t('automatisch') . ')', '');
                $done[] = t('server geïsoleerd (24 uur)');
            }
        } elseif ($acts) {
            $done[] = t('modus Leren: RzMon greep niet in');
        }
        $detail = implode(' · ', array_filter([$facts, $sigma ? t('Sigma-regel van {a} (SigmaHQ, DRL 1.1)', ['a' => $r['author'] ?: '?']) : t($r['what']),
            $done ? '⛔ ' . implode(', ', $done) : '']));
        $ip = $kind === 'net' && !empty($ev['rip']) && !Ids::internalIp((string) $ev['rip']) ? (string) $ev['rip'] : null;
        $id = Ids::raise($host, $sev, 'edr', $key, $title, $detail, $ip);
        self::evidence($id, $kind, $ev + ['_done' => $done], (string) $r['id'], [
            'title' => $sigma ? $r['title'] : t($r['title']), 'src' => $sigma ? 'sigma' : 'rzmon', 'tags' => $r['tags'] ?? [],
            'author' => $r['author'] ?? '', 'url' => $r['src'] ?? '', 'what' => $sigma ? ($r['desc'] ?? '') : t($r['what']), 'fp' => $r['fp'] ?? [],
        ]);
        $techs = (array) ($r['tags'] ?? []);
        Chain::signal($host, 'rule', mb_substr((string) $r['id'], 0, 20), ($sigma ? (array) ($r['tactics'] ?? []) : []) ?: Mitre::tacticsOf($techs), $techs,
            $title, $sev >= 2 ? 60 : 35, $ip, $id, "rule|$key", ['_web' => Anomaly::isWeb($kind, $ev) ? 1 : 0, 'k' => $kind], 10, self::when($ev));
        return ['rule' => mb_substr((string) $r['id'], 0, 80), 'sev' => $sev, 'inc' => $id];
    }

    /**
     * Uitgaande verbinding naar een adres op een lijst (Feodo/Spamhaus/FireHOL, ThreatFox): zonder iets op te zoeken
     * meteen critical (meestal malware die naar huis belt); in de modus Blokkeren het proces stoppen.
     */
    private static function knownBad(array $host, array $ev): void
    {
        $ip = (string) ($ev['rip'] ?? '');
        if ($ip === '' || Ids::internalIp($ip) || !ThreatIntel::on()) {
            return;
        }
        $tf = ThreatIntel::listed('i', $ip);
        $list = IpIntel::bad($ip) ?? ($tf && $tf['src'] === 'threatfox' ? 'ThreatFox (' . $tf['info'] . ')' : null);
        if ($list === null) {
            return;
        }
        $exe = basename((string) ($ev['exe'] ?? ($ev['comm'] ?? '?')));
        $done = [];
        if (Ids::blocking() && setting('ti_block', '1') === '1') {
            $pid = (int) ($ev['pid'] ?? 0);
            $st = (int) ($ev['st'] ?? 0);
            if ($pid > 1 && $st > 0 && setting('edr_kill', '1') === '1') {
                q('INSERT IGNORE INTO ids_kills (host_id, pid, start, dkey, created_at) VALUES (?, ?, ?, ?, ?)', [$host['id'], $pid, $st, mb_substr("ti|$ip", 0, 255), now()]);
                self::$kills["$pid:$st"] = [$pid, $st];
                $done[] = t('proces {pid} wordt gestopt', ['pid' => $pid]);
            }
        }
        $id = Ids::raise($host, 2, 'ti_ip', $ip, t('Verbinding met een bekende aanvaller: {exe} → {ip}', ['exe' => $exe, 'ip' => $ip . ':' . ($ev['rp'] ?? '')]),
            '☠️ ' . $list . ' · ' . self::facts('net', $ev) . ($done ? ' · ⛔ ' . implode(', ', $done) : ''), $ip);
        self::evidence($id, 'net', $ev + ['_done' => $done], 'ti-c2', ['title' => t('Verbinding met een bekende aanvaller'), 'src' => 'ti', 'tags' => ['T1071'], 'author' => '', 'url' => '',
            'what' => t('Dit adres staat op een lijst met botnet- en malwareservers ({list}). Een programma op de server praat ermee: meestal malware die opdrachten ophaalt.', ['list' => $list]), 'fp' => []]);
        Chain::signal($host, 'ids', 'ti_c2', ['command-and-control'], ['T1071'], t('Verbinding met een bekende aanvaller: {exe} → {ip}', ['exe' => $exe, 'ip' => $ip]), 80, $ip, $id,
            "ti|c2|$ip", ['k' => 'net'], 10, self::when($ev));
    }

    /** De belangrijkste gegevens in één regel */
    private static function facts(string $kind, array $ev): string
    {
        $cmd = mb_substr((string) ($ev['cmd'] ?? ''), 0, 200);
        return match ($kind) {
            'exec' => trim($cmd . ' · ' . t('ouder') . ' ' . ($ev['pc'] ?? '?') . ' · ' . t('gebruiker') . ' ' . ($ev['u'] ?? ($ev['uid'] ?? '?')) . ' · pid ' . ($ev['pid'] ?? '?')),
            'audit' => trim(($ev['name'] ?? '') . ' · ' . ($ev['exe'] ?? '?') . ' · ' . t('gebruiker') . ' ' . ($ev['u'] ?? ($ev['uid'] ?? '?'))),
            'net' => (($ev['dir'] ?? '') === 'out' ? t('naar') . ' ' . ($ev['rip'] ?? '') . ':' . ($ev['rp'] ?? '') : t('luistert op poort {p}', ['p' => $ev['lp'] ?? '?']))
                . ' · ' . ($ev['exe'] ?? ($ev['comm'] ?? '?')) . ' · pid ' . ($ev['pid'] ?? '?'),
            'file' => ($ev['path'] ?? '') . ' · ' . number_format((int) ($ev['size'] ?? 0), 0, ',', '.') . ' bytes' . (!empty($ev['sha']) ? ' · sha256 ' . substr((string) $ev['sha'], 0, 16) . '…' : '')
                . (!empty($ev['susp']) ? ' · ' . t('bevat {w}', ['w' => $ev['susp']]) : ''),
            'ctr' => ($ev['rt'] ?? '') . ' ' . ($ev['act'] ?? '') . ' ' . ($ev['name'] ?? '') . (!empty($ev['img']) ? ' (' . $ev['img'] . ')' : '') . ($cmd !== '' ? ' · ' . $cmd : ''),
            default => '',
        };
    }

    /** Bewijs bij een incident (hooguit 20 per incident, de nieuwste) */
    public static function evidence(?int $incidentId, string $kind, array $data, string $rule, array $ruleInfo = []): void
    {
        if (!$incidentId) {
            return;
        }
        $json = json_encode(['ev' => $data, 'rule' => $ruleInfo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        q('INSERT INTO ids_evidence (incident_id, at, kind, rule, data) VALUES (?, ?, ?, ?, ?)', [$incidentId, now(), $kind, mb_substr($rule, 0, 80), mb_substr((string) $json, 0, 8000)]);
        q('DELETE FROM ids_evidence WHERE incident_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM ids_evidence WHERE incident_id = ? ORDER BY id DESC LIMIT 20) x)',
            [$incidentId, $incidentId]);
    }

    /** Voor het incidentvenster: het bewijs */
    public static function evidenceFor(int $incidentId): array
    {
        return array_map(static function ($r) {
            $d = json_decode((string) $r['data'], true) ?: [];
            $ev = $d['ev'] ?? [];
            // 1.112.0: wat er over het bestand bekend is (CIRCL, MalwareBazaar)
            $ti = $r['kind'] === 'file' && !empty($ev['sha']) && is_string($ev['sha']) ? ThreatIntel::hash($ev['sha']) : null;
            return ['at' => $r['at'], 'kind' => $r['kind'], 'rule' => $r['rule'], 'ev' => $ev, 'info' => $d['rule'] ?? [], 'ti' => $ti];
        }, q('SELECT * FROM ids_evidence WHERE incident_id = ? ORDER BY id DESC', [$incidentId])->fetchAll());
    }

    /** Titels van alle regels (voor Live) */
    public static function titles(): array
    {
        $t = [];
        foreach (EdrRules::all() as $r) {
            $t[$r['id']] = t($r['title']);
        }
        foreach (Sigma::rules() as $r) {
            $t[$r['id']] = 'Sigma: ' . $r['title'];
        }
        return $t;
    }

    /** Live: de laatste gebeurtenissen (alle servers of één), nieuwste eerst */
    public static function feed(int $limit = 200, ?int $hostId = null, bool $hitsOnly = false, int $after = 0, bool $own = true): array
    {
        $w = ['e.id > ?'];
        $p = [$after];
        if (!$own) {
            $w[] = 'e.own IS NULL';
        }
        if ($hostId) {
            $w[] = 'e.host_id = ?';
            $p[] = $hostId;
        }
        if ($hitsOnly) {
            $w[] = 'e.rule IS NOT NULL';
        }
        $titles = self::titles();
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'host' => $r['host'], 'at' => $r['at'], 'kind' => self::FEED_KINDS[$r['kind']] ?? $r['kind'],
            'own' => $r['own'], 'sig' => $r['sig'], 'rule' => $r['rule'], 'rule_title' => $r['rule'] ? ($titles[$r['rule']] ?? $r['rule']) : null, 'sev' => (int) $r['sev'], 'ev' => json_decode((string) $r['data'], true) ?: []],
            q('SELECT e.*, h.name AS host FROM edr_events e JOIN hosts h ON h.id = e.host_id WHERE ' . implode(' AND ', $w) . ' ORDER BY e.id DESC LIMIT ' . max(1, min(500, $limit)), $p)->fetchAll());
    }

    // ------------------------------------------------------------------ sensoren en onderdelen per server

    /** Onderdelen die de agent kan installeren: naam => [rpm-pakket(ten), deb-pakket(ten), waarvoor] */
    public const COMPONENTS = [
        'auditd' => [['audit'], ['auditd'], 'nieuwe processen, kernelmodules en bewaakte paden'],
        'inotify' => [['epel-release', 'inotify-tools'], ['inotify-tools'], 'nieuwe bestanden in tijdelijke mappen en webmappen'],
        'yara' => [['epel-release', 'yara'], ['yara'], 'nieuwe bestanden op malware controleren (YARA, optioneel)'],
    ];

    /** Per server: staat het aan, welke sensoren werken, wat ontbreekt */
    public static function status(array $host): array
    {
        $i = json_decode((string) ($host['edr_info'] ?? ''), true) ?: [];
        $missing = [];
        if (($i['audit'] ?? '') === 'missing') {
            $missing[] = 'auditd';
        }
        if (($i['file'] ?? '') === 'missing') {
            $missing[] = 'inotify';
        }
        $optional = ($i['yara'] ?? '') === 'missing' ? ['yara'] : [];   // 1.113.0: YARA hoeft niet, maar kan
        return ['on' => (int) ($host['edr_on'] ?? 1) === 1, 'active' => self::onFor($host), 'info' => $i, 'at' => $host['edr_at'] ?? null, 'missing' => $missing, 'optional' => $optional,
            'install_blocked' => Vulns::upgradeBlocked($host), 'fam' => $i['fam'] ?? ''];
    }

    /** Ontbrekend onderdeel laten installeren door de agent (pakketbeheerder van de server, met voortgang) */
    /** Pakketnamen die je zelf opgeeft (1.125.0): gewone namen van dnf/apt, gescheiden door spaties of komma's */
    public static function parsePkgs(string $text): array|string
    {
        $names = array_values(array_unique(array_filter(preg_split('/[\s,]+/', trim($text)) ?: [])));
        if (!$names) {
            return t('Geen pakketnamen opgegeven');
        }
        if (count($names) > 10) {
            return t('Hooguit {n} pakketten tegelijk', ['n' => 10]);
        }
        foreach ($names as $n) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_:~-]{0,99}$/', $n)) {
                return t('Ongeldige pakketnaam: {n}', ['n' => mb_substr($n, 0, 40)]);
            }
        }
        return $names;
    }

    public static function install(array $host, string $comp, string $by, string $own = ''): true|string
    {
        if (!isset(self::COMPONENTS[$comp])) {
            return t('Onbekend onderdeel');
        }
        if (trim($own) !== '') {
            $pkgs = self::parsePkgs($own);
            return is_array($pkgs) ? Vulns::requestInstall($host, $pkgs, $by, true) : $pkgs;
        }
        $i = json_decode((string) ($host['edr_info'] ?? ''), true) ?: [];
        $rpm = ($i['fam'] ?? '') === 'rhel';
        $pkgs = self::COMPONENTS[$comp][$rpm ? 0 : 1];
        if ($rpm && ($comp === 'inotify' || $comp === 'yara') && (int) ($i['epel'] ?? 0) === 1) {
            $pkgs = [$comp === 'yara' ? 'yara' : 'inotify-tools'];
        }
        return Vulns::requestInstall($host, $pkgs, $by);
    }

    /** De YARA-regels van RzMon (rules/yara/rzmon.yar) */
    public static function yaraRules(): string
    {
        static $r = null;
        return $r ??= (string) @file_get_contents(SM_ROOT . '/rules/yara/rzmon.yar');
    }

    public static function purge(): void
    {
        q('DELETE FROM edr_events WHERE at < NOW() - INTERVAL 3 DAY');
        q('DELETE FROM edr_seen WHERE last_seen < NOW() - INTERVAL 60 DAY');
        Anomaly::purge();
        Chain::purge();
        ThreatIntel::purge();
    }
}
