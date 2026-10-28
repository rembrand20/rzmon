<?php
declare(strict_types=1);

/**
 * Afwijkingen (1.111.0): RzMon leert per server wat normaal is (standaard 14 dagen, Instellingen → IDS → Afwijkingen
 * en ketens) en meldt daarna wat die server nog nooit deed: een nieuw programma, een webserver of database die iets
 * nieuws start, een nieuwe gebruiker, een programma dat ineens luistert of naar buiten belt, een nieuw land, een
 * nieuw container-image, ongewoon veel nieuwe processen, of iemand die actief is op een ongewoon tijdstip.
 * Wat er geleerd is staat in edr_seen (per server, soort en "kenmerk"); de aantallen per uur in edr_hourly.
 * Elke afwijking wordt een signaal (pagina Afwijkingen, MITRE, ketens); vanaf een score van edr_anom_min ook een
 * IDS-incident (warning). Wat al door een regel is gemeld, wordt alleen geleerd.
 */
final class Anomaly
{
    /** Diensten: als die iets nieuws doen, telt het zwaarder */
    private const SERVICES = '/^(nginx|apache2|httpd|lighttpd|caddy|php-fpm.*|php-cgi.*|uwsgi|gunicorn.*|mysqld|mariadbd|postgres|redis-server|mongod|sshd|named|dovecot|postfix|master|smbd|vsftpd|proftpd)$/';
    private const SHELLS = '/^(sh|bash|dash|ash|zsh|ksh|busybox|nc|ncat|netcat|socat|telnet|curl|wget|python[0-9.]*|perl|ruby|php[0-9.]*)$/';
    private const SYSTEM_DIRS = '#^/(usr/)?(s?bin|lib(64|exec)?|lib/[^/]+)/#';
    private const WEB_USERS = ['www-data', 'apache', 'nginx', 'http', 'wwwrun', 'lighttpd'];
    private const WEB_ROOTS = '#^/(var/www|srv/www|srv/http|usr/share/nginx|var/lib/nginx)/#';

    /** Aantallen per uur in dit rapport: [soort][uur] => n */
    private static array $hourly = [];
    /** Uren met interactieve activiteit in dit rapport */
    private static array $active = [];

    public static function on(): bool
    {
        return setting('edr_anom', '1') === '1';
    }

    public static function learnDays(): int
    {
        return max(1, min(90, (int) setting('edr_learn_days', '14')));
    }

    /** Leert deze server nog? (de eerste edr_learn_days dagen na de eerste gebeurtenis) */
    public static function learning(array $host): bool
    {
        $from = $host['edr_learn_from'] ?? null;
        return !$from || strtotime((string) $from) + self::learnDays() * 86400 > time();
    }

    /** Leerstatus voor de pagina */
    public static function learnInfo(array $host): array
    {
        $from = $host['edr_learn_from'] ?? null;
        $days = self::learnDays();
        $until = $from ? strtotime((string) $from) + $days * 86400 : null;
        $done = $from ? min($days, max(0, (time() - strtotime((string) $from)) / 86400)) : 0;
        return ['from' => $from, 'until' => $until ? date('Y-m-d H:i:s', $until) : null, 'days' => $days, 'day' => (int) floor($done) + ($until && $until > time() ? 1 : 0),
            'pct' => (int) round($done / $days * 100), 'learning' => self::learning($host)];
    }

    /** Begin van het leren vastleggen bij de eerste gebeurtenis */
    public static function start(array &$host): void
    {
        if (empty($host['edr_learn_from'])) {
            $host['edr_learn_from'] = now();
            q('UPDATE hosts SET edr_learn_from = ? WHERE id = ? AND edr_learn_from IS NULL', [$host['edr_learn_from'], $host['id']]);
        }
    }

    /** Leren nu afronden, of opnieuw beginnen (alles wat geleerd is weg) */
    public static function relearn(int $hostId, bool $finish): void
    {
        if ($finish) {
            q('UPDATE hosts SET edr_learn_from = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - self::learnDays() * 86400 - 60), $hostId]);
            return;
        }
        q('DELETE FROM edr_seen WHERE host_id = ?', [$hostId]);
        q('DELETE FROM edr_hourly WHERE host_id = ?', [$hostId]);
        q('UPDATE hosts SET edr_learn_from = ? WHERE id = ?', [now(), $hostId]);
    }

    public static function isWeb(string $kind, array $ev): bool
    {
        $pc = (string) ($ev['pc'] ?? '');
        return match ($kind) {
            'exec' => (bool) preg_match('/^(nginx|apache2|httpd|lighttpd|caddy|php-fpm.*|php-cgi.*|uwsgi|gunicorn.*)$/', $pc) || in_array((string) ($ev['u'] ?? ''), self::WEB_USERS, true),
            'net' => in_array((string) ($ev['u'] ?? ''), self::WEB_USERS, true),
            'file' => (bool) preg_match(self::WEB_ROOTS, (string) ($ev['path'] ?? '')),
            default => false,
        };
    }

    /** Ingelogde gebruiker (auditd: auid), als het geen dienst is */
    private static function auid(array $ev): ?string
    {
        $a = (string) ($ev['auid'] ?? '');
        return $a !== '' && $a !== '-1' && $a !== '4294967295' && ctype_digit($a) ? $a : null;
    }

    /**
     * Eén gebeurtenis: leren (edr_seen) en, na de leerperiode, de opvallendste nieuwe eigenschap als signaal.
     * @param bool $hit al gemeld door een regel (dan alleen leren)
     */
    public static function observe(array $host, string $kind, array $ev, string $sig, bool $hit, string $at): void
    {
        $hid = (int) $host['id'];
        $hr = substr($at, 0, 13) . ':00:00';
        $letter = array_search($kind, Edr::KINDS, true) ?: '?';
        self::$hourly[$hid][$letter][$hr] = (self::$hourly[$hid][$letter][$hr] ?? 0) + 1;
        if ($kind === 'exec' && self::auid($ev) !== null) {
            self::$hourly[$hid]['i'][$hr] = (self::$hourly[$hid]['i'][$hr] ?? 0) + 1;
            self::$active[$hid][$hr] = $ev['u'] ?? self::auid($ev);
        }
        $best = null;
        foreach (self::features($kind, $ev, $sig, $letter) as $ft) {
            $new = q('INSERT INTO edr_seen (host_id, kind, sig, first_seen, last_seen, n) VALUES (?, ?, ?, ?, ?, 1)
                      ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), n = n + 1', [$hid, $ft['k'], mb_substr($ft['key'], 0, 255), $at, $at])->rowCount() === 1;
            if ($new && ($best === null || $ft['score'] > $best['score'])) {
                $best = $ft;
            }
        }
        if ($best === null || $hit || !self::on() || self::learning($host)) {
            return;
        }
        self::report($host, $kind, $ev, $best, $at);
    }

    /** Kenmerken van een gebeurtenis: [k (soort in edr_seen), key, score, tactics, techs, label] */
    private static function features(string $kind, array $ev, string $sig, string $letter): array
    {
        $out = [];
        $exe = (string) ($ev['exe'] ?? '');
        $comm = (string) ($ev['comm'] ?? '');
        $name = basename($exe !== '' ? $exe : $comm);
        $norm = static fn(string $s) => (string) preg_replace(['/\d+/', '/[0-9a-f]{8,}/i'], ['#', '…'], $s);
        $f = static fn(string $k, string $key, int $score, array $tactics, array $techs, string $label) =>
            ['k' => $k, 'key' => $key, 'score' => $score, 'tactics' => $tactics, 'techs' => $techs, 'label' => $label];
        switch ($kind) {
            case 'exec':
                $pc = (string) ($ev['pc'] ?? '?');
                $path = $exe !== '' ? $exe : "/$comm";
                $out[] = $f('E', $norm($path), preg_match(self::SYSTEM_DIRS, $path) ? 20 : 35, ['execution'], [],
                    t('Nieuw programma: {exe}', ['exe' => $path]));
                $svc = (bool) preg_match(self::SERVICES, $pc);
                $out[] = $f($letter, $sig, $svc ? 40 : 10, ['execution'], preg_match(self::SHELLS, $name) ? ['T1059.004'] : [],
                    t('Nieuw: {parent} start {child}', ['parent' => $pc, 'child' => $name]));
                if (($u = (string) ($ev['u'] ?? ($ev['uid'] ?? ''))) !== '') {
                    $out[] = $f('U', $u, 35, ['privilege-escalation'], ['T1078.003'], t('Nieuwe gebruiker voert programma\'s uit: {u}', ['u' => $u]));
                }
                if (($auid = self::auid($ev)) !== null) {
                    $out[] = $f('I', $auid, 40, ['initial-access'], ['T1078'],
                        t('Voor het eerst actief na inloggen: {u} (uid {uid})', ['u' => $ev['u'] ?? '?', 'uid' => $auid]));
                }
                break;
            case 'net':
                $port = (string) ($ev['rp'] ?? $ev['lp'] ?? '');
                if (($ev['dir'] ?? '') === 'out') {
                    $ip = (string) ($ev['rip'] ?? '');
                    $internal = $ip === '' || Ids::internalIp($ip);
                    $svc = (bool) preg_match(self::SERVICES, $name);
                    $out[] = $f('O', "$name:$port", $internal ? 10 : ($svc ? 40 : 15), ['command-and-control'], ['T1071'],
                        $svc && !$internal ? t('Dienst maakt een nieuwe verbinding naar buiten: {exe} → {ip}:{p}', ['exe' => $name, 'ip' => $ip, 'p' => $port])
                            : t('Nieuwe uitgaande verbinding: {exe} → poort {p}', ['exe' => $name, 'p' => $port]));
                    if (!$internal && ($cc = IpIntel::country($ip)) !== null) {
                        $out[] = $f('C', $cc, 30, ['command-and-control'], ['T1071'],
                            t('Eerste verbinding naar {cc}: {exe} → {ip}', ['cc' => IpIntel::flag($cc), 'exe' => $name, 'ip' => $ip]));
                    }
                } else {
                    $lp = (int) ($ev['lp'] ?? 0);
                    $out[] = $f($letter, $sig, 45, ['persistence', 'command-and-control'], ['T1571'],
                        t('Nieuw programma luistert op het netwerk: {exe} (poort {p})', ['exe' => $name, 'p' => $lp]));
                    if ($lp > 0 && $lp < 32768) {
                        $out[] = $f('P', "$name:$lp", 20, ['command-and-control'], ['T1571'], t('Luistert op een nieuwe poort: {exe} (poort {p})', ['exe' => $name, 'p' => $lp]));
                    }
                }
                break;
            case 'file':
                $elf = (int) ($ev['elf'] ?? 0) === 1;
                $out[] = $f($letter, $sig, $elf ? 35 : 15, $elf ? ['command-and-control'] : ['execution'], $elf ? ['T1105'] : [],
                    t('Nieuw soort bestand: {path}', ['path' => (string) ($ev['path'] ?? '')]));
                break;
            case 'audit':
                $key = (string) ($ev['key'] ?? '');
                $techs = $key === 'rzmon_mod' ? ['T1547.006'] : Mitre::forIncident('file', (string) ($ev['name'] ?? ''));
                $out[] = $f($letter, $sig, 30, Mitre::tacticsOf($techs) ?: ['persistence'], $techs,
                    t('Nieuw: {exe} wijzigt {name}', ['exe' => $name ?: '?', 'name' => (string) ($ev['name'] ?? $key)]));
                break;
            case 'ctr':
                if (($img = (string) ($ev['img'] ?? '')) !== '') {
                    $out[] = $f('K', $norm($img), 25, ['execution'], ['T1610'], t('Nieuw container-image: {img}', ['img' => $img]));
                }
                $out[] = $f($letter, $sig, 10, ['execution'], ['T1609'], t('Nieuw in container {name}: {act}', ['name' => $ev['name'] ?? '?', 'act' => $ev['act'] ?? '?']));
                break;
        }
        return $out;
    }

    /** Een afwijking: signaal en (vanaf de drempel) een incident met bewijs */
    private static function report(array $host, string $kind, array $ev, array $ft, string $at): void
    {
        $ip = (string) ($ev['rip'] ?? '');
        $ip = $ip !== '' && !Ids::internalIp($ip) ? $ip : null;
        $dkey = $ft['k'] . '|' . mb_substr($ft['key'], 0, 200);
        $ref = null;
        if ($ft['score'] >= max(1, (int) setting('edr_anom_min', '40'))) {
            $ref = Ids::raise($host, 1, 'anomaly', $dkey, t('Afwijking: {what}', ['what' => $ft['label']]),
                t('Nooit eerder gezien op deze server (geleerd sinds {d}).', ['d' => date('d-m-Y', strtotime((string) $host['edr_learn_from']))]), $ip);
            Edr::evidence($ref, $kind, $ev, 'anomaly', ['title' => $ft['label'], 'src' => 'anomaly', 'tags' => $ft['techs'], 'author' => '', 'url' => '',
                'what' => t('Deze server deed dit in de leerperiode nooit. Dat hoeft geen aanval te zijn (een nieuw programma, een update), maar past het niet bij wat je deed, kijk dan verder.'), 'fp' => []]);
        }
        if ($ip !== null) {
            ThreatIntel::queue('i', $ip, (int) $host['id'], $ref, $ft['label']);   // 1.112.0: een bekende aanvaller?
        }
        Chain::signal($host, 'anom', $ft['k'], $ft['tactics'], $ft['techs'], $ft['label'], $ft['score'], $ip, $ref, "anom|$dkey",
            $ev + ['_web' => self::isWeb($kind, $ev) ? 1 : 0, 'k' => $kind], 0, $at);
    }

    /** Na alle gebeurtenissen van een rapport: aantallen per uur bijwerken, ongewoon veel en ongewoon tijdstip */
    public static function flush(array $host): void
    {
        $hid = (int) $host['id'];
        foreach (self::$hourly[$hid] ?? [] as $k => $hours) {
            foreach ($hours as $hr => $n) {
                q('INSERT INTO edr_hourly (host_id, hr, kind, n) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)', [$hid, $hr, $k, $n]);
            }
        }
        $active = self::$active[$hid] ?? [];
        $kinds = array_intersect(array_keys(self::$hourly[$hid] ?? []), ['x', 'n']);
        unset(self::$hourly[$hid], self::$active[$hid]);
        if (!self::on() || self::learning($host)) {
            return;
        }
        $days = (int) q('SELECT COUNT(DISTINCT DATE(hr)) FROM edr_hourly WHERE host_id = ? AND hr < CURDATE()', [$hid])->fetchColumn();
        if ($days < 7) {
            return;
        }
        $nowHr = date('Y-m-d H:00:00');
        foreach ($kinds as $k) {
            $n = (int) q('SELECT n FROM edr_hourly WHERE host_id = ? AND hr = ? AND kind = ?', [$hid, $nowHr, $k])->fetchColumn();
            if ($n < 50) {
                continue;
            }
            $vals = array_map('intval', q('SELECT n FROM edr_hourly WHERE host_id = ? AND kind = ? AND HOUR(hr) = ? AND hr < CURDATE() AND hr >= CURDATE() - INTERVAL 30 DAY',
                [$hid, $k, (int) date('G')])->fetchAll(PDO::FETCH_COLUMN));
            $vals = array_merge($vals, array_fill(0, max(0, min(30, $days) - count($vals)), 0));
            $mean = array_sum($vals) / count($vals);
            $sd = sqrt(array_sum(array_map(static fn($v) => ($v - $mean) ** 2, $vals)) / count($vals));
            if ($n > max(50, $mean * 3, $mean + 4 * $sd)) {
                $label = $k === 'x' ? t('Ongewoon veel nieuwe processen: {n} dit uur (normaal ~{avg})', ['n' => $n, 'avg' => (int) round($mean)])
                    : t('Ongewoon veel nieuwe verbindingen: {n} dit uur (normaal ~{avg})', ['n' => $n, 'avg' => (int) round($mean)]);
                Chain::signal($host, 'anom', 'vol', $k === 'x' ? ['execution'] : ['command-and-control'], [], $label, 30, null, null,
                    "anom|vol|$k|" . date('YmdH'), ['n' => $n, 'avg' => round($mean, 1)], 60);
            }
        }
        // Actief op een uur waarop hier in de leerperiode nooit iemand ingelogd actief was
        foreach ($active as $hr => $user) {
            $h = (int) substr($hr, 11, 2);
            $seen = (int) q("SELECT COUNT(*) FROM edr_hourly WHERE host_id = ? AND kind = 'i' AND hr < CURDATE()", [$hid])->fetchColumn();
            $atHour = (int) q("SELECT COUNT(*) FROM edr_hourly WHERE host_id = ? AND kind = 'i' AND HOUR(hr) = ? AND hr < CURDATE()", [$hid, $h])->fetchColumn();
            if ($seen >= 7 && $atHour === 0) {
                Chain::signal($host, 'anom', 'hour', ['initial-access'], ['T1078'],
                    t('Actief op een ongewoon tijdstip: {u} om {h}:00', ['u' => $user, 'h' => sprintf('%02d', $h)]), 30, null, null,
                    'anom|hour|' . substr($hr, 0, 13), ['u' => $user, 'h' => $h], 60);
            }
        }
    }

    /** Afwijkingen voor de pagina (nieuwste eerst) */
    public static function list(int $days = 7, ?int $hostId = null, int $limit = 300): array
    {
        $w = ["s.src = 'anom'", 's.at >= NOW() - INTERVAL ? DAY'];
        $p = [max(1, min(90, $days))];
        if ($hostId) {
            $w[] = 's.host_id = ?';
            $p[] = $hostId;
        }
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'host' => $r['host'], 'at' => $r['at'], 'feature' => $r['feature'],
            'label' => $r['label'], 'score' => (int) $r['score'], 'tactics' => array_values(array_filter(explode(',', $r['tactics']))),
            'techs' => array_values(array_filter(explode(',', $r['techs']))), 'ip' => $r['ip'], 'ref' => $r['ref'] ? (int) $r['ref'] : null],
            q('SELECT s.*, h.name AS host FROM ids_signals s JOIN hosts h ON h.id = s.host_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.id DESC LIMIT ' . max(1, min(1000, $limit)), $p)->fetchAll());
    }

    /** Leerstatus van alle servers met endpoint-bewaking */
    public static function hosts(): array
    {
        $out = [];
        foreach (q("SELECT h.id, h.name, h.edr_on, h.edr_learn_from, h.edr_at, h.mode,
                     (SELECT COUNT(*) FROM edr_seen s WHERE s.host_id = h.id) AS learned,
                     (SELECT COUNT(*) FROM ids_signals g WHERE g.host_id = h.id AND g.src = 'anom' AND g.at >= NOW() - INTERVAL 7 DAY) AS anoms
                   FROM hosts h WHERE h.mode = 'agent' AND h.edr_at IS NOT NULL ORDER BY h.name")->fetchAll() as $h) {
            $out[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'on' => (int) $h['edr_on'] === 1, 'learned' => (int) $h['learned'], 'anoms' => (int) $h['anoms']] + self::learnInfo($h);
        }
        return $out;
    }

    /** In de tests */
    public static function reset(): void
    {
        self::$hourly = [];
        self::$active = [];
    }

    public static function purge(): void
    {
        q('DELETE FROM edr_hourly WHERE hr < NOW() - INTERVAL 31 DAY');
    }
}
