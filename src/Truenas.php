<?php
declare(strict_types=1);

/**
 * TrueNAS SCALE (RzSense 1.174.0, agent 1.77.0).
 *
 * - Herkennen: de agent start via een cronjob van TrueNAS (cfg.sched = truenas), stuurt een truenas-blok mee,
 *   of de softwarelijst noemt TrueNAS als besturingssysteem.
 * - Kwetsbaarheden: TrueNAS is Debian eronder; welke Debian staat in het pakket base-files. Bijwerken gaat via een
 *   TrueNAS-update, nooit met apt (dat breekt TrueNAS).
 * - Uitlezen (agent 1.77.0): de agent vraagt TrueNAS zelf via midclt (zonder API-sleutel) om meldingen, taken
 *   (replicatie, snapshots, cloud sync, scrub, SMART-tests), apps, een beschikbare update en schijftemperaturen.
 * - IDS: blokkeren, isoleren en de honeypot-poort staan op TrueNAS standaard uit; per server aan te zetten (met je
 *   wachtwoord). De agent gebruikt dan zijn eigen firewalltabel en zet die elke minuut terug.
 */
final class Truenas
{
    /** @var array<int, bool> */
    private static array $cache = [];

    /** Is deze server een TrueNAS? */
    public static function is(int $hostId): bool
    {
        if (isset(self::$cache[$hostId])) {
            return self::$cache[$hostId];
        }
        $cfg = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'cfg'", [$hostId])->fetchColumn(), true) ?: [];
        $yes = ($cfg['sched'] ?? '') === 'truenas'
            || (bool) q("SELECT 1 FROM agent_data WHERE host_id = ? AND metric = 'truenas'", [$hostId])->fetchColumn()
            || (bool) q("SELECT 1 FROM vuln_inventory WHERE host_id = ? AND (os_id = 'truenas' OR os_name LIKE 'TrueNAS%')", [$hostId])->fetchColumn();
        return self::$cache[$hostId] = $yes;
    }

    /** Alle TrueNAS-servers (id's) */
    public static function ids(): array
    {
        return array_values(array_filter(array_map('intval', q("SELECT id FROM hosts WHERE mode = 'agent'")->fetchAll(PDO::FETCH_COLUMN)), [self::class, 'is']));
    }

    /**
     * Debian-versie onder een afgeleide distributie (TrueNAS, of iets dat "like debian" is) uit het pakket base-files.
     * @param array<mixed> $list de softwarelijst [naam, versie, …] (van buitenaf: velden kunnen ontbreken)
     */
    public static function debianMajor(array $list): ?int
    {
        foreach ($list as $r) {
            if (($r[0] ?? '') === 'base-files' && preg_match('/^(\d{1,2})(?:[.+~]|$)/', (string) ($r[1] ?? ''), $m)) {
                $n = (int) $m[1];
                return $n >= 9 && $n <= 20 ? $n : null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ uitlezen via midclt (agent 1.77.0)

    /** Wat een truenas-check bewaakt */
    public const METRICS = ['alerts', 'tasks', 'apps', 'update', 'temp'];
    /** Zo oud mag het blok van de agent zijn (meldingen en taken komen elke 5 minuten) */
    private const STALE = 1200;

    /** Tijd uit midclt: {"$date": ms} of een getal (seconden of ms) */
    private static function ts(mixed $v): ?int
    {
        if (is_array($v) && isset($v['$date'])) {
            $v = $v['$date'];
        }
        if (!is_numeric($v)) {
            return null;
        }
        $n = (int) $v;
        return $n > 100000000000 ? intdiv($n, 1000) : ($n > 0 ? $n : null);
    }

    private static function txt(mixed $s, int $max = 300): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5))) ?? '');
        return mb_substr($s, 0, $max);
    }

    /** Het blok van de agent opslaan (agent_data 'truenas'), in een vaste, kleine vorm */
    public static function fromAgent(array $host, array $t, PDOStatement $st, string $now): void
    {
        $alerts = [];
        foreach (is_array($t['alerts'] ?? null) ? $t['alerts'] : [] as $a) {
            if (!is_array($a) || !empty($a['dismissed'])) {
                continue;
            }
            $alerts[] = ['l' => strtoupper(self::txt($a['level'] ?? 'INFO', 12)), 't' => self::txt($a['formatted'] ?? $a['text'] ?? $a['klass'] ?? '?'),
                'k' => self::txt($a['klass'] ?? '', 60), 'at' => self::ts($a['datetime'] ?? null)];
        }
        $tasks = [];
        $state = static function (array $x, string $key): array {
            $s = is_array($x[$key] ?? null) ? $x[$key] : [];
            return [strtoupper(self::txt($s['state'] ?? '', 20)), self::ts($s['datetime'] ?? $s['time_finished'] ?? $s['time_started'] ?? null), self::txt($s['error'] ?? '', 300)];
        };
        foreach (['repl' => ['name', 'state'], 'snap' => ['dataset', 'state'], 'cloud' => ['description', 'job'], 'rsync' => ['desc', 'job']] as $k => [$nameKey, $stKey]) {
            foreach (is_array($t[$k] ?? null) ? $t[$k] : [] as $x) {
                if (!is_array($x)) {
                    continue;
                }
                [$s, $at, $err] = $state($x, $stKey);
                $name = self::txt($x[$nameKey] ?? '', 120) ?: ($k === 'rsync' ? self::txt($x['path'] ?? '', 120) : '') ?: '#' . (int) ($x['id'] ?? 0);
                $tasks[] = ['k' => $k, 'n' => $name, 'on' => !array_key_exists('enabled', $x) || !empty($x['enabled']), 'st' => $s, 'at' => $at, 'err' => $err];
            }
        }
        $apps = [];
        foreach (is_array($t['apps'] ?? null) ? $t['apps'] : [] as $x) {
            if (is_array($x) && ($n = self::txt($x['name'] ?? '', 80)) !== '') {
                $apps[] = ['n' => $n, 'st' => strtoupper(self::txt($x['state'] ?? '', 20)), 'up' => !empty($x['upgrade_available']), 'v' => self::txt($x['version'] ?? '', 40)];
            }
        }
        $temps = [];
        foreach (is_array($t['temps'] ?? null) ? $t['temps'] : [] as $d => $v) {
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) $d) && is_numeric($v)) {
                $temps[(string) $d] = round((float) $v, 1);
            }
        }
        $upd = self::update($t['update'] ?? null);
        $data = ['v' => self::txt($t['version'] ?? '', 60) ?: (string) ($upd['cur'] ?? ''), 'at' => (int) ($t['at'] ?? time()), 'alerts' => array_slice($alerts, 0, 100), 'tasks' => array_slice($tasks, 0, 200),
            'apps' => array_slice($apps, 0, 200), 'update' => $upd, 'temps' => $temps,
            'have' => ['alerts' => is_array($t['alerts'] ?? null), 'tasks' => is_array($t['repl'] ?? null) || is_array($t['snap'] ?? null) || is_array($t['cloud'] ?? null),
                'apps' => is_array($t['apps'] ?? null), 'update' => is_array($t['update'] ?? null), 'temps' => is_array($t['temps'] ?? null) && $temps]];
        $st->execute([(int) $host['id'], 'truenas', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        self::$cache[(int) $host['id']] = true;
    }

    /** Is er een TrueNAS-update? (update.status op nieuwere versies, update.check_available op oudere) */
    private static function update(mixed $u): ?array
    {
        if (!is_array($u)) {
            return null;
        }
        if (isset($u['status']) && is_string($u['status'])) {   // check_available: {status: AVAILABLE|UNAVAILABLE|REBOOT_REQUIRED, version}
            return ['av' => $u['status'] === 'AVAILABLE', 'v' => self::txt($u['version'] ?? '', 40), 'reboot' => $u['status'] === 'REBOOT_REQUIRED'];
        }
        $s = is_array($u['status'] ?? null) ? $u['status'] : $u;   // status: {new_version: {version …} | null, current_version …}
        $nv = $s['new_version'] ?? null;
        $cur = $s['current_version'] ?? null;
        return ['av' => is_array($nv) || (is_string($nv) && $nv !== ''), 'v' => self::txt(is_array($nv) ? ($nv['version'] ?? '') : (string) $nv, 40), 'reboot' => false,
            'cur' => self::txt(is_array($cur) ? ($cur['version'] ?? '') : (string) $cur, 40)];
    }

    public static function data(int $hostId): ?array
    {
        $v = q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'truenas'", [$hostId])->fetchColumn();
        $d = $v ? (json_decode((string) $v, true) ?: null) : null;
        if ($d && !$d['temps']) {
            // 1.175.0: nieuwere TrueNAS (bv. 27) geeft via midclt geen temperaturen: dan die uit SMART (smartctl, al bij de agent)
            $d['temps'] = self::smartTemps($hostId);
            $d['have']['temps'] = (bool) $d['temps'];
            $d['temps_from'] = $d['temps'] ? 'smart' : null;
        }
        return $d;
    }

    /** Schijftemperaturen uit de SMART-gegevens die de agent zelf meestuurt (agent_data 'smart': lijst met dev/temp) */
    public static function smartTemps(int $hostId): array
    {
        $out = [];
        $list = json_decode((string) Checks::agentMetric(['id' => $hostId], 'smart'), true);
        foreach (is_array($list) ? $list : [] as $d) {
            if (!is_array($d) || !isset($d['temp']) || !is_numeric($d['temp']) || !empty($d['standby'])) {
                continue;
            }
            $dev = basename((string) ($d['dev'] ?? ''));
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $dev)) {
                $out[$dev] = round((float) $d['temp'], 1);
            }
        }
        ksort($out);
        return $out;
    }

    private const LEVEL = ['EMERGENCY' => 2, 'ALERT' => 2, 'CRITICAL' => 2, 'ERROR' => 2, 'WARNING' => 1, 'NOTICE' => 0, 'INFO' => 0];
    private const TASK = ['repl' => 'replicatie', 'snap' => 'snapshot', 'cloud' => 'cloud sync', 'rsync' => 'rsync'];

    /** Een truenas-check: [status, waarde, melding] */
    public static function check(array $check, array $host): array
    {
        $d = self::data((int) $host['id']);
        if (!$d) {
            return [STATUS_UNKNOWN, null, t('Nog geen gegevens van TrueNAS: agent 1.77.0 of nieuwer nodig, als root')];
        }
        if ($d['at'] < time() - self::STALE) {
            return [STATUS_UNKNOWN, null, t('Geen verse gegevens van TrueNAS sinds {t}', ['t' => date('d-m H:i', (int) $d['at'])])];
        }
        $w = $check['warn_threshold'] !== null ? (float) $check['warn_threshold'] : null;
        $c = $check['crit_threshold'] !== null ? (float) $check['crit_threshold'] : null;
        $target = trim((string) $check['target']);
        $metric = (string) ($check['metric'] ?: 'alerts');
        if (empty($d['have'][$metric === 'temp' ? 'temps' : $metric])) {
            return [STATUS_UNKNOWN, null, t('TrueNAS gaf hier geen antwoord op (midclt)')];
        }
        switch ($metric) {
            case 'alerts':
                $bad = array_values(array_filter($d['alerts'], static fn($a) => (self::LEVEL[$a['l']] ?? 0) > 0));
                usort($bad, static fn($a, $b) => (self::LEVEL[$b['l']] ?? 0) <=> (self::LEVEL[$a['l']] ?? 0));
                if (!$bad) {
                    return [STATUS_OK, 0.0, t('Geen TrueNAS-meldingen') . (count($d['alerts']) ? ' (' . t('{n} ter info', ['n' => count($d['alerts'])]) . ')' : '')];
                }
                $crit = (self::LEVEL[$bad[0]['l']] ?? 0) === 2;
                return [$crit ? STATUS_CRIT : STATUS_WARN, (float) count($bad),
                    t('{n} TrueNAS-melding(en)', ['n' => count($bad)]) . ': ' . implode(' · ', array_map(static fn($a) => "{$a['l']}: " . mb_substr($a['t'], 0, 140), array_slice($bad, 0, 3)))];
            case 'tasks':
                $list = array_values(array_filter($d['tasks'], static fn($x) => $x['on'] && ($target === '' || mb_stripos($x['n'], $target) !== false)));
                if (!$list) {
                    return [$target !== '' ? STATUS_UNKNOWN : STATUS_OK, 0.0, $target !== '' ? t('Geen taak gevonden met "{t}"', ['t' => $target]) : t('Geen taken ingesteld')];
                }
                $failed = array_values(array_filter($list, static fn($x) => in_array($x['st'], ['ERROR', 'FAILED', 'ABORTED'], true)));
                if ($failed) {
                    return [STATUS_CRIT, (float) count($failed), t('{n} taak/taken mislukt', ['n' => count($failed)]) . ': '
                        . implode(' · ', array_map(static fn($x) => t(self::TASK[$x['k']]) . " {$x['n']}" . ($x['err'] !== '' ? ': ' . mb_substr($x['err'], 0, 120) : ''), array_slice($failed, 0, 3)))];
                }
                $run = count(array_filter($list, static fn($x) => $x['st'] === 'RUNNING'));
                return [STATUS_OK, 0.0, t('{n} taken in orde', ['n' => count($list)]) . ($run ? ' · ' . t('{n} bezig', ['n' => $run]) : '')];
            case 'apps':
                $list = array_values(array_filter($d['apps'], static fn($x) => $target === '' || strcasecmp($x['n'], $target) === 0));
                if (!$list) {
                    return [$target !== '' ? STATUS_CRIT : STATUS_OK, 0.0, $target !== '' ? t('App {n} niet gevonden', ['n' => $target]) : t('Geen apps')];
                }
                $crashed = array_filter($list, static fn($x) => $x['st'] === 'CRASHED');
                $stopped = array_filter($list, static fn($x) => $x['st'] === 'STOPPED');
                $ups = array_filter($list, static fn($x) => $x['up']);
                $upTxt = $ups ? ' · ' . t('update beschikbaar: {a}', ['a' => implode(', ', array_slice(array_column($ups, 'n'), 0, 5))]) : '';
                if ($crashed || ($target !== '' && $stopped)) {
                    $bad = array_merge($crashed, $target !== '' ? $stopped : []);
                    return [STATUS_CRIT, (float) count($bad), t('App(s) draaien niet: {a}', ['a' => implode(', ', array_map(static fn($x) => "{$x['n']} ({$x['st']})", array_slice($bad, 0, 5)))]) . $upTxt];
                }
                $run = count(array_filter($list, static fn($x) => $x['st'] === 'RUNNING'));
                return [STATUS_OK, (float) count($crashed), t('{r} van {n} apps draaien', ['r' => $run, 'n' => count($list)]) . ($stopped ? ' · ' . t('gestopt: {a}', ['a' => implode(', ', array_slice(array_column($stopped, 'n'), 0, 5))]) : '') . $upTxt];
            case 'update':
                $u = $d['update'];
                if ($u && $u['reboot']) {
                    return [STATUS_WARN, 1.0, t('TrueNAS is bijgewerkt en wacht op een herstart')];
                }
                return $u && $u['av'] ? [STATUS_WARN, 1.0, t('TrueNAS-update beschikbaar: {v}', ['v' => $u['v'] ?: '?']) . ' (' . t('nu {c}', ['c' => $d['v'] ?: '?']) . ')']
                    : [STATUS_OK, 0.0, t('TrueNAS is bijgewerkt ({v})', ['v' => $d['v'] ?: '?'])];
            case 'temp':
                $temps = $target !== '' ? array_intersect_key($d['temps'], [$target => 1]) : $d['temps'];
                if (!$temps) {
                    return [STATUS_UNKNOWN, null, $target !== '' ? t('Schijf {d} niet gevonden', ['d' => $target]) : t('Geen schijftemperaturen')];
                }
                arsort($temps);
                $max = (float) reset($temps);
                $hot = (string) key($temps);
                $st = $c !== null && $max >= $c ? STATUS_CRIT : ($w !== null && $max >= $w ? STATUS_WARN : STATUS_OK);
                return [$st, $max, t('Warmste schijf {d}: {t} °C', ['d' => $hot, 't' => number_format($max, $max == floor($max) ? 0 : 1, I18n::lang() === 'en' ? '.' : ',', '')]) . (count($temps) > 1 ? ' · ' . t('{n} schijven', ['n' => count($temps)]) : '')];
        }
        return [STATUS_UNKNOWN, null, t('Onbekend onderdeel')];
    }

    /** Voor het TrueNAS-venster: alles wat de agent laatst stuurde, plus welke checks er al zijn */
    public static function overview(int $hostId): ?array
    {
        $d = self::data($hostId);
        if (!$d) {
            return null;
        }
        $checks = q("SELECT id, metric, target, status FROM checks WHERE host_id = ? AND type = 'truenas'", [$hostId])->fetchAll();
        return $d + ['checks' => $checks, 'fresh' => $d['at'] >= time() - self::STALE];
    }
}
