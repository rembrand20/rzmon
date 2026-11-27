<?php
/**
 * 1.344.0: overnemen per server. De Go-agent (rzsense) doet op een Linux-server het werk van de bash-agent, als jij dat
 * per server vraagt en het veilig kan:
 *   - de proef liep lang genoeg en was (bijna) altijd gelijk aan de bash-agent;
 *   - de server gebruikt niets wat de Go-agent nog niet meet (anders zou dat stilvallen);
 *   - op de server staat alles klaar om terug te vallen op de bash-agent.
 * RzSense vraagt het dan in het antwoord op een proefronde. De bash-agent wordt uitgezet, niet weggehaald; vraag je het
 * terug, valt een onderdeel later toch buiten wat de Go-agent kan, of lukt het rapporteren niet, dan gaat hij weer aan.
 */
final class AgentTakeover
{
    public const ROUNDS = 60;     // zoveel vergeleken proefrondes (een uur)
    public const PCT = 98.0;      // zoveel procent daarvan gelijk
    public const OS = 'linux-go'; // hosts.os zolang de Go-agent het werk doet

    /** Regels in de testlijst die de Go-agent uitvoert, of die alleen een losse vraag zijn (dan gebeurt er even niets) */
    private const LINES_OK = ['auth', 'cmd', 'db', 'heal', 'watchdog', 'log', 'logsrc', 'logship', 'logscan', 'logpause', 'logdirs', 'logbrowse', 'patch', 'path', 'pathtp', 'iperfs', 'tick', 'ival', 'svc', 'ports', 'url', 'port', 'cert', 'certdirs', 'certscan', 'proc', 'selftest', 'exprobe', 'diag',
        // 1.360.0 (Go-agent 0.29.0): het IDS, met blokkeren, de lokaas-poort, een proces stoppen, isoleren en bestanden bewaken
        'ids', 'block', 'knet', 'outblock', 'decoy', 'kill', 'isolate', 'userlock', 'fim', 'truenas_fw',
        // 1.361.0 (Go-agent 0.30.0): Realtime en de endpoint-sensoren (de eigen watcher van de Go-agent)
        'realtime', 'edr', 'edrself',
        // 1.362.0 (Go-agent 0.31.0): ransomware herkennen
        'ransom', 'ransompath'];
    /** Agent-checks die de Go-agent meet; andere soorten die van de agent komen, houden het overnemen tegen */
    private const CHECKS_BLOCK = ['ha'];

    /** Wat een regel in de testlijst voor de gebruiker is */
    private static function lineLabel(string $type): string
    {
        return match (true) {
            $type === 'realtime' => t('Realtime'),
            $type === 'auth' => t('Logins, sudo en su'),
            in_array($type, ['ids', 'block', 'unblock', 'isolate', 'userlock', 'decoy', 'fim', 'canary', 'knet', 'outblock', 'net'], true) => t('IDS'),
            str_starts_with($type, 'edr') => t('Endpoint-sensoren (EDR)'),
            in_array($type, ['weblog', 'web'], true) => t('Logs van webdiensten'),
            $type === 'watchdog' => t('De waakhond'),
            str_starts_with($type, 'log') => t('Logs verzamelen of doorzoeken'),
            $type === 'cmd' => t('Commando-checks'),
            in_array($type, ['apps', 'appctl', 'appblock'], true) => t('Programma\'s'),
            str_starts_with($type, 'ioc') => t('Jagen op sporen'),
            str_starts_with($type, 'ransom') => t('Ransomware herkennen'),
            $type === 'patch' => t('Updates'),
            in_array($type, ['relay', 'rchk'], true) => t('Relay'),
            $type === 'sudo' => t('Tijdelijk sudo'),
            str_starts_with($type, 'proxy') => t('Reverse proxy beschermen'),
            str_starts_with($type, 'waf') => t('WAF'),
            str_starts_with($type, 'av') => t('Antivirus (ClamAV)'),
            $type === 'nids' => t('Suricata'),
            in_array($type, ['path', 'pathtp', 'paths', 'iperf', 'iperfs', 'tp'], true) => t('Pad-checks en doorvoer'),
            $type === 'db' => t('Databasechecks'),
            $type === 'diag' => t('Diagnose voor AI-advies'),
            default => t('het onderdeel “{x}”', ['x' => $type]),
        };
    }

    /**
     * Wat deze server gebruikt dat de Go-agent nog niet kan (leeg = niets houdt het tegen)
     * @param array<string, mixed> $host
     * @return list<string>
     */
    public static function blockers(array $host): array
    {
        $id = (int) $host['id'];
        $out = [];
        if (($host['mode'] ?? 'agent') !== 'agent') {
            return [t('deze server werkt niet met een agent')];
        }
        $tick = agent_tick((int) ($host['agent_tick'] ?? 0) ?: null);
        if ($tick < 60) {
            $out[] = t('rapporteert vaker dan elke minuut (elke {n} s)', ['n' => $tick]);
        }
        if (!function_exists('agent_tests_text')) {
            defined('RZ_API') || define('RZ_API', true);   // buiten de API (cron, tests)
            require_once __DIR__ . '/api/functies.php';
        }
        // 1.345.1: logs van webdiensten staan standaard voor elke server aan, maar tellen alleen waar de bash-agent zo'n log vond
        $web = q("SELECT src FROM weblog_state WHERE host_id = ? AND path IS NOT NULL AND path <> ''", [$id])->fetchAll(PDO::FETCH_COLUMN);
        foreach (explode("\n", agent_tests_text($id, false)) as $line) {
            $f = explode("\t", $line);
            if (count($f) >= 3 && $f[1] === 'weblog' && !in_array($f[2], $web, true)) {
                continue;
            }
            if (count($f) >= 3 && !in_array($f[1], self::LINES_OK, true) && !($f[1] === 'apps' && $f[2] === '0')) {   // apps 0: Programma's staat uit
                $out[] = self::lineLabel($f[1]);
            }
        }
        foreach (q('SELECT DISTINCT type FROM checks WHERE host_id = ? AND enabled = 1', [$id])->fetchAll(PDO::FETCH_COLUMN) as $type) {
            if (in_array($type, self::CHECKS_BLOCK, true)) {
                $out[] = t('een check van de soort “{x}”', ['x' => (string) $type]);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * De stand voor één server: mag het, wat houdt het tegen, en wie doet nu het werk
     * @return array<string, mixed>
     */
    public static function check(int $hostId): array
    {
        $host = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$host) {
            return ['can' => false, 'want' => false, 'active' => false, 'blockers' => [], 'proof' => [], 'ready' => [], 'note' => ''];
        }
        $active = ($host['os'] ?? '') === self::OS;
        $s = AgentData::get($hostId, 'shadow') ?? [];
        $live = AgentData::get($hostId, 'goready') ?? [];   // wat de Go-agent zelf het laatst meldde (ook als hij het werk doet)
        $ready = (array) ($live['ready'] ?? $s['ready'] ?? []);
        $rounds = (int) ($s['rounds'] ?? 0);
        $pct = $rounds > 0 ? round((int) ($s['rounds_ok'] ?? 0) / $rounds * 100, 1) : 0.0;
        $proof = [];
        if ($s === []) {
            $proof[] = t('de proef loopt nog niet op deze server');
        } else {
            if ($rounds < self::ROUNDS) {
                $proof[] = t('nog {n} proefrondes te gaan (er zijn er {need} nodig)', ['n' => self::ROUNDS - $rounds, 'need' => self::ROUNDS]);
            }
            if ($rounds > 0 && $pct < self::PCT) {
                $proof[] = t('{pct}% van de proefrondes was gelijk; er is {need}% nodig', ['pct' => $pct, 'need' => self::PCT]);
            }
            if (!$active && strtotime((string) ($s['at'] ?? '')) < time() - 300) {
                $proof[] = t('er komt geen proefronde meer binnen');
            }
            if (!$active && ($s['version'] ?? '') !== AGENT_WIN_LATEST) {
                $proof[] = t('de Go-agent op de server is nog niet bijgewerkt naar {v}', ['v' => AGENT_WIN_LATEST]);
            }
        }
        $need = [];
        if ($s !== [] && !$active) {
            foreach (['systemd' => t('de server gebruikt geen systemd'), 'bash_timer' => t('de bash-agent draait niet via zijn timer (rzmon-agent.timer)'),
                'fallback' => t('het bewakertje voor de terugval ontbreekt: voer het installatiecommando opnieuw uit'),
                'key_write' => t('de Go-agent mag niet in de sleutelmap schrijven: voer het installatiecommando opnieuw uit'),
                'cache_write' => t('de Go-agent mag niet in de map van de bash-agent schrijven (voor de stand van de logins): voer het installatiecommando opnieuw uit')] as $k => $why) {
                if (empty($ready[$k])) {
                    $need[] = $why;
                }
            }
        }
        $blockers = self::blockers($host);
        return [
            'can' => $blockers === [] && $proof === [] && $need === [],
            'want' => (int) ($host['go_want'] ?? 0) === 1,
            'active' => $active,
            'seen' => $host['agent_seen'] ?? null,
            'version' => $active ? ($host['agent_version'] ?? null) : null,
            'blockers' => $blockers, 'proof' => $proof, 'need' => $need,
            'note' => (string) ($ready['note'] ?? ''),
            'rounds' => self::ROUNDS, 'pct' => self::PCT,
        ];
    }

    /** Vraagt RzSense de Go-agent nu om over te nemen? (in het antwoord op een proefronde) */
    public static function shouldTake(int $hostId): bool
    {
        $c = self::check($hostId);
        return $c['want'] && !$c['active'] && $c['can'];
    }

    /**
     * De keuze van de gebruiker bewaren. Aanzetten kan alleen als niets het tegenhoudt.
     * @return array<string, mixed> de nieuwe stand, of ['error' => …]
     */
    public static function request(int $hostId, bool $on): array
    {
        $c = self::check($hostId);
        if ($on && !$c['can'] && !$c['active']) {
            return ['error' => t('Overnemen kan nog niet: {why}', ['why' => implode('; ', [...$c['blockers'], ...$c['proof'], ...$c['need']])])];
        }
        q('UPDATE hosts SET go_want = ? WHERE id = ?', [$on ? 1 : 0, $hostId]);
        ActionLog::add($hostId, 'rzsense', 'agent', $on ? t('Overnemen door de Go-agent gevraagd') : t('Terug naar de bash-agent gevraagd'), '', true);
        return self::check($hostId);
    }

    /**
     * Wat de Go-agent over zichzelf meldt (proefronde of echt rapport): staat alles klaar, en zijn laatste opmerking
     * @return array<string, mixed>
     */
    public static function cleanReady(mixed $in): array
    {
        $in = is_array($in) ? $in : [];
        $out = ['mode' => ($in['mode'] ?? '') === 'active' ? 'active' : 'shadow', 'note' => mb_substr((string) ($in['note'] ?? ''), 0, 300)];
        foreach (['systemd', 'bash_timer', 'fallback', 'key_write', 'cache_write'] as $k) {
            $out[$k] = !empty($in[$k]);
        }
        return $out;
    }

    /**
     * Een echt rapport van de Go-agent: zijn stand bewaren en bepalen wat er terug moet
     * @param array<string, mixed> $host
     * @param array<string, mixed> $in
     * @return array<string, mixed> velden voor het antwoord (go_back, go_update)
     */
    public static function fromAgent(array $host, array $in): array
    {
        $id = (int) $host['id'];
        AgentData::put($id, 'goready', ['at' => now(), 'ready' => self::cleanReady($in['ready'] ?? null), 'update_last' => mb_substr((string) ($in['update_last'] ?? ''), 0, 300)]);
        $host = q('SELECT * FROM hosts WHERE id = ?', [$id])->fetch() ?: $host;   // het rapport kent maar een deel van de kolommen
        $out = [];
        if ((int) ($host['go_want'] ?? 0) !== 1) {
            $out['go_back'] = true;   // de gebruiker vroeg het terug
        } elseif (($why = self::blockers($host)) !== []) {
            $out['go_back'] = true;   // er is iets aangezet wat de Go-agent nog niet kan: dat mag niet stilvallen
            Monitor::infoHost($host, static fn() => t('De bash-agent neemt het werk weer over: {why} kan de Go-agent nog niet', ['why' => implode(', ', $why)]));
        } elseif (($u = AgentShadow::updateFor((string) ($in['arch'] ?? ''), (string) ($in['agent_version'] ?? ''))) !== null) {
            $out['go_update'] = $u;
        }
        return $out;
    }

    /**
     * Wisselde de agent die het werk doet? Dan één regel in het logboek en een bericht.
     * @param array<string, mixed> $host
     */
    public static function noteSwitch(array $host, string $os, ?string $version): void
    {
        $was = (string) ($host['os'] ?? '');
        if ($was === $os || ($was !== self::OS && $os !== self::OS)) {
            return;
        }
        $txt = $os === self::OS
            ? static fn() => t('De Go-agent {v} doet nu het werk op deze server; de bash-agent staat uit', ['v' => (string) $version])
            : static fn() => t('De bash-agent {v} doet het werk weer op deze server', ['v' => (string) $version]);
        ActionLog::add((int) $host['id'], 'agent', 'agent', $txt(), '', true, '', null, 'agent');
        Monitor::infoHost($host, $txt);
    }
}
