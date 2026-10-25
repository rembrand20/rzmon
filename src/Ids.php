<?php
declare(strict_types=1);

/**
 * Intrusion detection (pagina 🛡️ IDS). De agent verzamelt; hier wordt vergeleken met wat normaal is.
 *
 * - Aan/uit (ids_enabled, standaard uit) en Leren/Blokkeren (ids_mode): leren = alleen melden,
 *   blokkeren = brute force ook echt blokkeren (lokaal op die server, nftables-tabel "rzmon" van de agent).
 * - Basismeting: de eerste ids_baseline_hours van een server wordt alleen vastgelegd wat er is (bestanden,
 *   gebruikers, poorten, SUID, modules, login-IP's); daarna is elke afwijking een incident.
 * - Incidenten: warning (1) of critical (2). Critical gaat ook naar Pushover, ook tijdens stille uren.
 *   Dezelfde gebeurtenis binnen 24 uur wordt opgeteld bij het bestaande incident.
 * - Blokkeren: per server, eerst ids_block_hours, bij herhaling ids_repeat_days. Nooit automatisch: privé-
 *   adressen (LAN), RzMon zelf en ids_allow. Met de hand kan ook een LAN-adres (behalve het eigen IP).
 * - Bestanden bewaken (FIM): paden uit ids_fim (algemeen) en hosts.ids_fim (per server). De agent stuurt alleen
 *   wat veranderd is; hier wordt altijd vergeleken met de eigen basismeting (kind 'fim': hash|grootte|rechten|eigenaar),
 *   dus een rapport dat twee keer binnenkomt geeft geen dubbele incidenten.
 */
final class Ids
{
    public const SEVERITY = [1 => 'warning', 2 => 'critical'];
    // Bestanden waarvan een wijziging critical is (andere gevoelige bestanden: warning)
    private const CRIT_FILES = ['#^/root/\.ssh/authorized_keys$#', '#^/etc/sudoers(\.d/.*)?$#', '#^/etc/ld\.so\.preload$#', '#^/etc/pam\.d/#'];
    // Sinds agent 1.54.0 ("fv":2) bewaakt: de eerste keer stil vastleggen (anders na de update een incident per bestand)
    private const FILES_V2 = '#^(/etc/pam\.d/|/etc/(hosts|resolv\.conf|environment)$|/var/spool/(at|cron/atjobs)/|/root/\.(bashrc|bash_profile|profile)$|/etc/systemd/user/|/root/\.config/systemd/user/|/home/[^/]+/\.config/systemd/user/)#';
    /** Lokaas-gebruikers (1.81.0): standaard, aan te vullen bij Instellingen → IDS */
    public const DECOY_USERS = 'admin test guest user oracle ubuntu pi ftp support postgres';
    // Diensten die bij elke start een willekeurige poort kiezen: alleen de naam telt
    private const RANDOM_PORT_PROCS = ['rpc.statd', 'rpc.mountd', 'rpcbind', 'rpc.rquotad', 'avahi-daemon', 'chronyd', 'dhclient', 'NetworkManager'];
    private const BAD_PORTS = [1337, 4444, 4445, 5555, 6666, 6667, 6697, 12345, 31337];
    public const FIM_MAX_PATHS = 20;
    // Meer dan zoveel wijzigingen onder één pad in één rapport: één incident met de lijst
    private const FIM_BULK = 10;

    public static function enabled(): bool
    {
        return setting('ids_enabled', '0') === '1';
    }

    public static function blocking(): bool
    {
        return self::enabled() && setting('ids_mode', 'learn') === 'block';
    }

    /** Regels voor de testlijst van de agent: IDS aan + modus, en de te blokkeren IP's (ip <tab> verloopt, 0 = nooit). */
    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $out = [implode("\t", [0, 'ids', self::blocking() ? 'block' : 'learn'])];
        // Bestanden bewaken: e <tab> epoch, p <tab> ernst <tab> pad, x <tab> uitsluiten (base64, één regel)
        $paths = self::fimPaths($hostId);
        if ($paths) {
            $cfg = "e\t" . (int) q('SELECT fim_epoch FROM ids_state WHERE host_id = ?', [$hostId])->fetchColumn() . "\n";
            foreach ($paths as $p) {
                $cfg .= "p\t{$p['s']}\t{$p['p']}\n";
            }
            foreach (parse_list((string) setting('ids_fim_exclude', '')) as $x) {
                $cfg .= "x\t$x\n";
            }
            $out[] = implode("\t", [0, 'fim', base64_encode($cfg)]);
        }
        if (self::blocking()) {
            foreach (self::blocksFor($hostId) as $b) {
                $out[] = implode("\t", [0, 'block', $b['ip'], $b['until'] ? strtotime($b['until']) : 0]);
            }
        }
        if (($port = self::decoyPort()) !== null) {
            $out[] = implode("\t", [0, 'decoy', $port]);
        }
        return $out;
    }

    /** Lokaas-poort (1.81.0), of null (uit) */
    public static function decoyPort(): ?int
    {
        $p = (int) setting('ids_decoy_port', '2323');
        return $p >= 1 && $p <= 65535 ? $p : null;
    }

    /** Lokaas-gebruikers (kleine letters) */
    public static function decoyUsers(): array
    {
        return array_values(array_unique(array_map('strtolower', parse_list((string) setting('ids_decoy_users', self::DECOY_USERS)))));
    }

    /**
     * Te blokkeren IP's voor een server: zijn eigen blokkades, en (1.81.0, "automatische blokkades op alle servers")
     * de automatische van de andere servers. Per IP één regel, met de langste duur.
     */
    public static function blocksFor(int $hostId): array
    {
        $shared = setting('ids_block_shared', '1') === '1';
        $out = [];
        foreach (q('SELECT ip, until FROM ids_blocks WHERE removed_at IS NULL AND (until IS NULL OR until > NOW()) AND (host_id = ?' . ($shared ? ' OR auto = 1' : '') . ')',
            [$hostId])->fetchAll() as $b) {
            $cur = $out[$b['ip']] ?? null;
            if (!$cur || ($cur['until'] !== null && ($b['until'] === null || $b['until'] > $cur['until']))) {
                $out[$b['ip']] = $b;
            }
        }
        return array_values($out);
    }

    public static function activeBlocks(?int $hostId = null): array
    {
        return q('SELECT b.*, h.name AS host_name FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                  WHERE b.removed_at IS NULL AND (b.until IS NULL OR b.until > NOW())' . ($hostId ? ' AND b.host_id = ?' : '') . ' ORDER BY b.created_at DESC',
            $hostId ? [$hostId] : [])->fetchAll();
    }

    // ------------------------------------------------------------------ rapport verwerken

    /** Rapport van de agent: $ids = IDS-gegevens, $auth = logins, $ports = luisterende poorten. */
    public static function process(array $host, array $ids, array $auth, array $ports): void
    {
        if (!self::enabled()) {
            return;
        }
        $hid = (int) $host['id'];
        $st = q('SELECT * FROM ids_state WHERE host_id = ?', [$hid])->fetch();
        if (!$st) {
            $until = date('Y-m-d H:i:s', time() + 3600 * max(0, (int) setting('ids_baseline_hours', 24)));
            q('INSERT INTO ids_state (host_id, baseline_until, fim_epoch) VALUES (?, ?, ?)', [$hid, $until, time()]);
            $st = ['baseline_until' => $until];
        }
        $fw = (array) ($ids['fw'] ?? []);
        q('UPDATE ids_state SET last_report = NOW(), fw_state = ?, fw_error = ? WHERE host_id = ?',
            [mb_substr((string) ($fw['state'] ?? ''), 0, 20), mb_substr((string) ($fw['err'] ?? ''), 0, 255), $hid]);
        foreach ((array) ($fw['hits'] ?? []) as $ip => $n) {
            q('UPDATE ids_blocks SET hits = GREATEST(hits, ?) WHERE host_id = ? AND ip = ? AND removed_at IS NULL', [(int) $n, $hid, (string) $ip]);
        }

        $learning = strtotime($st['baseline_until']) > time();
        // Pakketupdate in het afgelopen kwartier: gewijzigde bestanden zijn dan waarschijnlijk de update
        $updating = (int) ($ids['pkg'] ?? 0) > time() - 900;
        $ctx = ['host' => $host, 'learning' => $learning,
            'users' => array_map(static fn($u) => strtolower((string) ($u[0] ?? '')), array_slice((array) ($ids['users'] ?? []), 0, 1000))];
        q('UPDATE ids_state SET decoy_state = ? WHERE host_id = ?', [isset($ids['decoy_err']) ? mb_substr((string) $ids['decoy_err'], 0, 100) : null, $hid]);

        // Gebruikers en groepen eerst: die veranderen ook passwd/shadow/group, dat hoeft niet apart
        $userChange = self::users($ctx, (array) ($ids['users'] ?? []));
        $userChange = self::groups($ctx, $ids['groups'] ?? null) || $userChange;
        self::files($ctx, (array) ($ids['files'] ?? []), $updating, $userChange, (int) ($ids['fv'] ?? 1));
        self::decoyHits($ctx, (array) ($ids['decoy'] ?? []));
        self::ports($ctx, $ports);
        self::simpleSet($ctx, 'suid', (array) ($ids['suid'] ?? []), 'suid_new', t('Nieuw SUID-bestand'), t('Kan gebruikt worden om root te worden.'), isset($ids['suid']));
        self::simpleSet($ctx, 'module', (array) ($ids['modules'] ?? []), 'module_new', t('Nieuwe kernelmodule'), t('Geladen sinds de vorige controle.'), isset($ids['modules']));
        self::simpleSet($ctx, 'pkg', array_slice((array) ($ids['pkgdiff'] ?? []), 0, 100), 'pkg_changed', t('Systeembestand wijkt af van het pakket'),
            t('rpm -Va / debsums: de inhoud komt niet overeen met het geïnstalleerde pakket.'), isset($ids['pkgdiff']));
        foreach (array_slice((array) ($ids['procs'] ?? []), 0, 20) as $p) {
            $exe = (string) ($p['exe'] ?? '');
            $memfd = str_starts_with($exe, '/memfd:');
            self::incident($ctx, $memfd ? 1 : 2, 'proc_suspicious', $exe,
                ($memfd ? t('Programma zonder bestand (memfd)') : t('Verdacht proces')) . ': ' . $exe,
                'pid ' . (int) ($p['pid'] ?? 0) . ' · ' . t('gebruiker') . ' ' . ($p['user'] ?? '?') . ' · ' . mb_substr((string) ($p['cmd'] ?? ''), 0, 300));
        }
        foreach (array_slice((array) ($ids['conns'] ?? []), 0, 20) as $c) {
            $peer = (string) ($c['peer'] ?? '');
            $ip = self::hostOf($peer);
            if (($c['kind'] ?? '') === 'shell') {
                self::incident($ctx, 2, 'revshell', ($c['proc'] ?? '') . '|' . $ip, t('Mogelijke reverse shell: {proc} verbonden met {peer}', ['proc' => $c['proc'] ?? '?', 'peer' => $peer]),
                    'pid ' . (int) ($c['pid'] ?? 0) . ' · ' . t('lokaal') . ' ' . ($c['local'] ?? ''), $ip);
            } else {
                self::incident($ctx, 1, 'outbound', $peer, t('Verbinding naar verdachte poort') . ': ' . $peer . ' (' . ($c['proc'] ?? '?') . ')',
                    t('Poorten als {ports} worden vaak door malware gebruikt.', ['ports' => implode(', ', self::BAD_PORTS)]), $ip);
            }
        }
        self::logins($ctx, $auth);
        if (is_array($ids['fim'] ?? null)) {
            q('UPDATE ids_state SET fim_count = ? WHERE host_id = ?', [max(0, (int) ($ids['fim']['n'] ?? 0)), $hid]);
            self::fim($ctx, $ids['fim']);
        } elseif (!self::fimPaths($hid)) {
            q("DELETE FROM ids_baseline WHERE host_id = ? AND kind = 'fim'", [$hid]);   // niets meer bewaakt
            q('UPDATE ids_state SET fim_count = NULL WHERE host_id = ?', [$hid]);
        }
        if (is_array($ids['fimc'] ?? null)) {
            $cand = array_filter(array_map('strval', array_slice($ids['fimc'], 0, 30)), fn($p) => preg_match('#^/[A-Za-z0-9._/-]{1,100}$#', $p));
            q('UPDATE ids_state SET fim_cand = ? WHERE host_id = ?', [mb_substr(implode(' ', $cand), 0, 1000), $hid]);
        }
    }

    // ------------------------------------------------------------------ bestanden bewaken (FIM)

    /** Lijst met paden opschonen: [['p' => '/pad', 's' => 1|2], …] (absoluut, niet /, /proc, /sys, /dev of /run). */
    public static function cleanFim(mixed $list): array
    {
        $out = [];
        foreach (array_slice(is_array($list) ? $list : [], 0, self::FIM_MAX_PATHS) as $e) {
            $p = preg_replace('#/+#', '/', trim((string) (is_array($e) ? ($e['p'] ?? '') : $e)));
            if ($p !== '/' && strlen($p) > 1) {
                $p = rtrim($p, '/');
            }
            if ($p === '' || $p[0] !== '/' || $p === '/' || strlen($p) > 300 || preg_match('/[\x00-\x1f]/', $p)
                || preg_match('#^/(proc|sys|dev|run)(/|$)#', $p) || isset($out[$p])) {
                continue;
            }
            $out[$p] = ['p' => $p, 's' => (int) (is_array($e) ? ($e['s'] ?? 1) : 1) === 2 ? 2 : 1];
        }
        return array_values($out);
    }

    /** Uitsluiten: patronen gescheiden door spaties, bv. *.log cache/ */
    public static function cleanFimExclude(string $v): string
    {
        return implode(' ', array_slice(array_filter(parse_list(mb_substr($v, 0, 2000)), fn($x) => strlen($x) <= 100 && !preg_match('/[\x00-\x1f]/', $x)), 0, 30));
    }

    /** Paden voor één server: de algemene lijst plus die van de server (de server wint bij hetzelfde pad). */
    public static function fimPaths(int $hostId): array
    {
        $all = [];
        foreach (self::cleanFim(json_decode((string) setting('ids_fim', '[]'), true)) as $p) {
            $all[$p['p']] = $p;
        }
        $own = q('SELECT ids_fim FROM hosts WHERE id = ?', [$hostId])->fetchColumn();
        foreach (self::cleanFim(json_decode((string) $own, true)) as $p) {
            $all[$p['p']] = $p;
        }
        return array_slice(array_values($all), 0, 2 * self::FIM_MAX_PATHS);
    }

    /**
     * Rapport van de agent: c = nieuw of veranderd [pad, grootte, rechten, eigenaar, hash, ernst, bewaakt pad],
     * d = verdwenen [pad, ernst, bewaakt pad], b = nieuw bewaakt pad (stil vastleggen), u = niet meer bewaakt,
     * full = de hele lijst (eerste keer of na een nieuwe basismeting).
     */
    private static function fim(array $ctx, array $fim): void
    {
        $hid = (int) $ctx['host']['id'];
        $full = !empty($fim['full']);
        $known = q("SELECT item, value FROM ids_baseline WHERE host_id = ? AND kind = 'fim'", [$hid])->fetchAll(PDO::FETCH_KEY_PAIR);
        $quiet = $ctx['learning'] || ($full && !$known);   // de eerste lijst is de basismeting
        $up = db()->prepare("INSERT INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'fim', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $del = db()->prepare("DELETE FROM ids_baseline WHERE host_id = ? AND kind = 'fim' AND item = ?");
        $entry = static function (mixed $e): ?array {
            $e = (array) $e;
            $path = mb_substr((string) ($e[0] ?? ''), 0, 255);
            if ($path === '' || $path[0] !== '/') {
                return null;
            }
            return ['path' => $path, 'size' => max(0, (int) ($e[1] ?? 0)), 'mode' => preg_replace('/[^0-7]/', '', (string) ($e[2] ?? '')),
                'owner' => mb_substr(preg_replace('/[^A-Za-z0-9._:-]/', '', (string) ($e[3] ?? '')), 0, 64),
                'hash' => preg_replace('/[^0-9a-fm.]/', '', (string) ($e[4] ?? '')), 'sev' => (int) ($e[5] ?? 1) === 2 ? 2 : 1,
                'root' => mb_substr((string) ($e[6] ?? ''), 0, 255)];
        };
        $val = static fn(array $x) => mb_substr("{$x['hash']}|{$x['size']}|{$x['mode']}|{$x['owner']}", 0, 255);

        foreach (array_slice((array) ($fim['b'] ?? []), 0, 20000) as $e) {
            if ($x = $entry($e)) {
                $up->execute([$hid, $x['path'], $val($x)]);
            }
        }
        foreach (array_slice((array) ($fim['u'] ?? []), 0, 20000) as $p) {
            $del->execute([$hid, mb_substr((string) $p, 0, 255)]);
        }
        $events = [];
        $seen = [];
        foreach (array_slice((array) ($fim['c'] ?? []), 0, 20000) as $e) {
            if (!($x = $entry($e))) {
                continue;
            }
            $seen[$x['path']] = true;
            $old = $known[$x['path']] ?? null;
            if ($old === $val($x)) {
                continue;
            }
            $up->execute([$hid, $x['path'], $val($x)]);
            if (!$quiet) {
                $events[$x['root']][] = ['op' => $old === null ? '+' : '~', 'x' => $x, 'old' => $old];
            }
        }
        foreach (array_slice((array) ($fim['d'] ?? []), 0, 20000) as $e) {
            $e = (array) $e;
            $path = mb_substr((string) ($e[0] ?? ''), 0, 255);
            if (!isset($known[$path])) {
                continue;
            }
            $del->execute([$hid, $path]);
            if (!$quiet) {
                $x = ['path' => $path, 'sev' => (int) ($e[1] ?? 1) === 2 ? 2 : 1, 'root' => mb_substr((string) ($e[2] ?? ''), 0, 255)];
                $events[$x['root']][] = ['op' => '-', 'x' => $x, 'old' => $known[$path]];
            }
        }
        if ($full) {
            // Volledige lijst: wat er niet in staat, wordt niet (meer) bewaakt
            foreach (array_keys($known) as $p) {
                if (!isset($seen[$p])) {
                    $del->execute([$hid, $p]);
                }
            }
        }
        if (!$events) {
            return;
        }
        $ignored = array_flip(q("SELECT item FROM ids_baseline WHERE host_id = ? AND kind = 'ignore' AND (item LIKE 'fim|%' OR item LIKE 'fim\_bulk|%')",
            [$hid])->fetchAll(PDO::FETCH_COLUMN));
        foreach ($events as $root => $list) {
            if (isset($ignored["fim_bulk|$root"])) {
                continue;   // "niet meer bewaken" bij een bundel: dit hele pad
            }
            $list = array_values(array_filter($list, fn($ev) => !isset($ignored['fim|' . $ev['x']['path']])));
            if (!$list) {
                continue;
            }
            if (count($list) > self::FIM_BULK) {
                $n = ['+' => 0, '~' => 0, '-' => 0];
                foreach ($list as $ev) {
                    $n[$ev['op']]++;
                }
                $sum = implode(', ', array_filter([$n['+'] ? t('{n} nieuw', ['n' => $n['+']]) : '', $n['~'] ? t('{n} gewijzigd', ['n' => $n['~']]) : '', $n['-'] ? t('{n} verwijderd', ['n' => $n['-']]) : '']));
                $lines = array_map(fn($ev) => ['+' => '＋ ', '~' => '✎ ', '-' => '✕ '][$ev['op']] . $ev['x']['path'], array_slice($list, 0, 25));
                self::incident($ctx, max(array_map([self::class, 'fimSev'], $list)), 'fim_bulk', $root, t('{n} bestanden gewijzigd in {root}', ['n' => count($list), 'root' => $root]),
                    "$sum:\n" . implode("\n", $lines) . (count($list) > 25 ? "\n… " . t('en nog {n}', ['n' => count($list) - 25]) : ''), null, false, true);
            } else {
                foreach ($list as $ev) {
                    [$title, $detail] = self::fimText($ev);
                    self::incident($ctx, self::fimSev($ev), 'fim', $ev['x']['path'], $title, $detail, null, false, true);
                }
            }
        }
    }

    /** Ernst van een wijziging: die van het pad, of critical als er SUID/SGID bij kwam. */
    private static function fimSev(array $ev): int
    {
        $sev = $ev['x']['sev'];
        if ($ev['op'] !== '-') {
            $oldMode = $ev['old'] !== null ? octdec((string) (explode('|', $ev['old'])[2] ?? '0') ?: '0') : 0;
            if ((octdec($ev['x']['mode'] ?: '0') & 06000) & ~$oldMode) {
                $sev = 2;
            }
        }
        return $sev;
    }

    /** Titel en uitleg van één wijziging (nooit de inhoud, alleen hash, grootte, rechten en eigenaar). */
    private static function fimText(array $ev): array
    {
        $x = $ev['x'];
        $path = $x['path'];
        $hash = static fn(string $h) => $h === '' ? '?' : (str_starts_with($h, 'm') ? t('te groot voor een hash') : substr($h, 0, 12) . '…');
        $size = static fn($b) => Checks::bytes((float) $b);
        if ($ev['op'] === '-') {
            [, $s, $m, $o] = array_pad(explode('|', (string) $ev['old']), 4, '');
            return [t('Bestand verwijderd') . ": $path", t('Was {size} · rechten {mode} · eigenaar {owner}', ['size' => $size($s), 'mode' => $m, 'owner' => $o])];
        }
        if ($ev['op'] === '+') {
            return [t('Nieuw bestand') . ": $path", t('Bestond nog niet.') . ' ' . t('{size} · rechten {mode} · eigenaar {owner}', ['size' => $size($x['size']), 'mode' => $x['mode'], 'owner' => $x['owner']]) . ' · SHA-256 ' . $hash($x['hash'])];
        }
        [$h, $s, $m, $o] = array_pad(explode('|', (string) $ev['old']), 4, '');
        $parts = [];
        if ($h !== $x['hash']) {
            $parts[] = str_starts_with($x['hash'], 'm') ? t('Groot bestand: tijd of grootte gewijzigd') : t('Inhoud gewijzigd') . ': SHA-256 ' . $hash($h) . ' → ' . $hash($x['hash']);
        }
        if ((string) $s !== (string) $x['size']) {
            $parts[] = $size($s) . ' → ' . $size($x['size']);
        }
        if ($m !== $x['mode']) {
            $parts[] = t('rechten') . " $m → {$x['mode']}";
        }
        if ($o !== $x['owner']) {
            $parts[] = t('eigenaar') . " $o → {$x['owner']}";
        }
        $rights = $h === $x['hash'];
        return [($rights ? t('Rechten gewijzigd') : t('Bestand gewijzigd')) . ': ' . $path, ucfirst(implode(' · ', $parts)) . '.'];
    }

    private static function files(array $ctx, array $files, bool $updating, bool $userChange, int $fv = 1): void
    {
        if (!$files) {
            return;
        }
        $hid = (int) $ctx['host']['id'];
        $known = q("SELECT item, value FROM ids_baseline WHERE host_id = ? AND kind = 'file'", [$hid])->fetchAll(PDO::FETCH_KEY_PAIR);
        // Eerste rapport met de extra bestanden van agent 1.54.0: die stil vastleggen
        $v2quiet = false;
        if ($fv >= 2 && !q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'seen' AND item = 'files_v2'", [$hid])->fetchColumn()) {
            q("INSERT IGNORE INTO ids_baseline (host_id, kind, item) VALUES (?, 'seen', 'files_v2')", [$hid]);
            $v2quiet = true;
        }
        $up = db()->prepare("INSERT INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'file', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        foreach (array_slice($files, 0, 1000, true) as $path => $hash) {
            $path = mb_substr((string) $path, 0, 255);
            $hash = preg_replace('/[^0-9a-f]/', '', (string) $hash);
            if ($hash === '' || ($known[$path] ?? null) === $hash) {
                continue;
            }
            $up->execute([$hid, $path, $hash]);
            if ($ctx['learning'] || $updating || ($userChange && preg_match('#^/etc/(passwd|shadow|group|gshadow)$#', $path))
                || ($v2quiet && !isset($known[$path]) && preg_match(self::FILES_V2, $path))) {
                continue;
            }
            $crit = (bool) array_filter(self::CRIT_FILES, fn($re) => preg_match($re, $path));
            $new = !isset($known[$path]);
            self::incident($ctx, $crit ? 2 : 1, 'file', $path, ($new ? t('Nieuw bestand') : t('Bestand gewijzigd')) . ': ' . $path,
                $new ? t('Dit bestand bestond nog niet.') : t('De inhoud is veranderd (SHA-256), buiten een pakketupdate om.'));
        }
    }

    /** @return bool true als er een gebruiker bij kwam of veranderde */
    private static function users(array $ctx, array $users): bool
    {
        if (!$users) {
            return false;
        }
        $changed = false;
        $hid = (int) $ctx['host']['id'];
        $known = q("SELECT item, value FROM ids_baseline WHERE host_id = ? AND kind = 'user'", [$hid])->fetchAll(PDO::FETCH_KEY_PAIR);
        $up = db()->prepare("INSERT INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'user', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        foreach (array_slice($users, 0, 1000) as $u) {
            [$name, $uid] = [mb_substr((string) ($u[0] ?? ''), 0, 64), (string) (int) ($u[1] ?? -1)];
            if ($name === '') {
                continue;
            }
            // Tweede account met UID 0: altijd critical, ook tijdens de basismeting
            if ($uid === '0' && $name !== 'root') {
                self::incident($ctx, 2, 'uid0', $name, t('Tweede root-account: {name} (UID 0)', ['name' => $name]), t('Een account met UID 0 heeft volledige rechten; een klassieke achterdeur.'), null, true);
            }
            if (($known[$name] ?? null) === $uid) {
                continue;
            }
            $up->execute([$hid, $name, $uid]);
            $changed = true;
            if (!$ctx['learning'] && !isset($known[$name])) {
                self::incident($ctx, 1, 'user_new', $name, t('Nieuwe gebruiker: {name} (UID {uid})', ['name' => $name, 'uid' => $uid]), t('Toegevoegd aan /etc/passwd.'));
            }
        }
        return $changed;
    }

    /**
     * Leden van groepen die (bijna) root zijn: wheel, sudo, docker, root, adm, lxd (agent 1.54.0). Er komt iemand bij:
     * critical. Het eerste rapport is de basismeting. @return bool true als er iets veranderde
     */
    private static function groups(array $ctx, mixed $groups): bool
    {
        if (!is_array($groups)) {
            return false;
        }
        $hid = (int) $ctx['host']['id'];
        $known = array_flip(q("SELECT item FROM ids_baseline WHERE host_id = ? AND kind = 'group'", [$hid])->fetchAll(PDO::FETCH_COLUMN));
        $first = !$known && !q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'seen' AND item = 'groups'", [$hid])->fetchColumn();
        q("INSERT IGNORE INTO ids_baseline (host_id, kind, item) VALUES (?, 'seen', 'groups')", [$hid]);
        $now = [];
        foreach (array_slice($groups, 0, 20, true) as $g => $members) {
            if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', (string) $g)) {
                continue;
            }
            foreach (array_slice((array) $members, 0, 200) as $m) {
                if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $m)) {
                    $now["$g|$m"] = true;
                }
            }
        }
        $changed = false;
        foreach (array_keys($now) as $item) {
            if (isset($known[$item])) {
                continue;
            }
            q("INSERT IGNORE INTO ids_baseline (host_id, kind, item) VALUES (?, 'group', ?)", [$hid, $item]);
            $changed = true;
            if (!$first && !$ctx['learning']) {
                [$g, $m] = explode('|', $item, 2);
                self::incident($ctx, 2, 'group_add', $item, t('{user} toegevoegd aan de groep {group}', ['user' => $m, 'group' => $g]),
                    t('Lid van {group} is (bijna) hetzelfde als root. Was jij dit niet, verwijder het dan met: gpasswd -d {user} {group}', ['group' => $g, 'user' => $m]));
            }
        }
        foreach (array_keys($known) as $item) {
            if (!isset($now[$item])) {
                q("DELETE FROM ids_baseline WHERE host_id = ? AND kind = 'group' AND item = ?", [$hid, $item]);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Verbindingen met de lokaas-poort (1.81.0). Van buiten: incident en meteen blokkeren (in de modus blokkeren);
     * vanaf je eigen netwerk: critical (waarschijnlijk een besmet apparaat; LAN-adressen worden nooit automatisch geblokkeerd).
     */
    private static function decoyHits(array $ctx, array $ips): void
    {
        $port = self::decoyPort();
        $own = OwnCert::serverIps();
        foreach (array_slice($ips, 0, 50) as $ip) {
            $ip = (string) $ip;
            if (@inet_pton($ip) === false || ip_in_list($ip, ['127.0.0.0/8', '::1/128']) || in_array($ip, $own, true)) {
                continue;   // RzMon zelf (bv. een poortscan) telt niet
            }
            if (self::internalIp($ip)) {
                self::incident($ctx, 2, 'decoy_port', $ip, t('Lokaas-poort {port} benaderd vanaf {ip} (je eigen netwerk)', ['port' => $port, 'ip' => $ip]),
                    t('Niemand gebruikt deze poort; een apparaat in je eigen netwerk dat hem probeert, scant je netwerk. Waarschijnlijk is het besmet: zoek uit welk apparaat dit is.'), $ip);
            } else {
                $blocked = self::autoBlock($ctx['host'], $ip, t('Lokaas-poort {port}', ['port' => $port]));
                self::incident($ctx, 1, 'decoy_port', $ip, t('Lokaas-poort {port} benaderd vanaf {ip}', ['port' => $port, 'ip' => $ip]),
                    t('Niemand gebruikt deze poort, dus dit is een scanner.') . ($blocked ? " · ⛔ $blocked" : ''), $ip);
            }
        }
    }

    private static function ports(array $ctx, array $ports): void
    {
        if (!$ports) {
            return;
        }
        $hid = (int) $ctx['host']['id'];
        $known = q("SELECT item FROM ids_baseline WHERE host_id = ? AND kind = 'port'", [$hid])->fetchAll(PDO::FETCH_COLUMN);
        $known = array_flip($known);
        $ins = db()->prepare("INSERT IGNORE INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'port', ?, ?)");
        foreach (array_slice($ports, 0, 500) as $p) {
            $proc = (string) ($p['proc'] ?? '');
            $port = (int) ($p['port'] ?? 0);
            if ($port <= 0 || $proc === '') {
                continue;   // zonder proces (kernel, bv. NFS): niet te beoordelen
            }
            $key = in_array($proc, self::RANDOM_PORT_PROCS, true) ? "proc:$proc" : "$port";
            if (isset($known[$key])) {
                continue;
            }
            $known[$key] = true;
            $ins->execute([$hid, $key, $proc]);
            if (!$ctx['learning']) {
                self::incident($ctx, 1, 'port_new', $key, t('Nieuwe luisterende poort: {port} ({proc})', ['port' => $port, 'proc' => $proc]), t('Luistert op {addr}.', ['addr' => $p['addr'] ?? '?']));
            }
        }
    }

    /** Lijst die alleen kan groeien (SUID-bestanden, kernelmodules): nieuw = incident. */
    private static function simpleSet(array $ctx, string $kind, array $items, string $type, string $label, string $detail, bool $reported): void
    {
        if (!$reported) {
            return;
        }
        $hid = (int) $ctx['host']['id'];
        $known = array_flip(q('SELECT item FROM ids_baseline WHERE host_id = ? AND kind = ?', [$hid, $kind])->fetchAll(PDO::FETCH_COLUMN));
        // De eerste lijst (bv. de eerste SUID-scan, die op de achtergrond loopt) is altijd de basismeting
        $first = !$known && !q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'seen' AND item = ?", [$hid, $kind])->fetchColumn();
        q("INSERT IGNORE INTO ids_baseline (host_id, kind, item) VALUES (?, 'seen', ?)", [$hid, $kind]);
        $ins = db()->prepare('INSERT IGNORE INTO ids_baseline (host_id, kind, item) VALUES (?, ?, ?)');
        foreach (array_slice($items, 0, 2000) as $item) {
            $item = mb_substr((string) $item, 0, 255);
            if ($item === '' || isset($known[$item])) {
                continue;
            }
            $ins->execute([$hid, $kind, $item]);
            if (!$ctx['learning'] && !$first) {
                self::incident($ctx, 1, $type, $item, "$label: $item", $detail);
            }
        }
    }

    /** Logins: brute force (en blokkeren), inlog na brute force, root via SSH, nieuw IP. */
    private static function logins(array $ctx, array $auth): void
    {
        $hid = (int) $ctx['host']['id'];
        $win = max(1, (int) setting('ids_fail_minutes', 10));
        $limit = max(2, (int) setting('ids_fail_count', 10));
        foreach (array_slice((array) ($auth['fails'] ?? []), 0, 100) as $f) {
            $ip = (string) ($f['ip'] ?? '');
            if (($f['k'] ?? '') !== 'ssh' || @inet_pton($ip) === false) {
                continue;
            }
            q('INSERT INTO ids_attempts (host_id, ip, n) VALUES (?, ?, ?)', [$hid, $ip, max(1, (int) ($f['n'] ?? 1))]);
            // Lokaas-gebruiker (1.81.0): een naam die hier niet bestaat en die alleen aanvallers proberen; meteen blokkeren
            $decoy = array_values(array_diff(array_intersect(array_map(static fn($u) => strtolower((string) $u), (array) ($f['users'] ?? [])), self::decoyUsers()), $ctx['users'] ?? []));
            if ($decoy) {
                $internal = self::internalIp($ip);
                $blocked = $internal ? null : self::autoBlock($ctx['host'], $ip, t('Lokaas-gebruiker: {user}', ['user' => $decoy[0]]));
                self::incident($ctx, $internal ? 2 : 1, 'decoy_user', $ip, t('Inlogpoging met lokaas-gebruiker {user} vanaf {ip}', ['user' => implode(', ', $decoy), 'ip' => $ip]),
                    ($internal ? t('Een apparaat in je eigen netwerk probeert in te loggen met een naam die hier niet bestaat. Waarschijnlijk is het besmet: zoek uit welk apparaat dit is.')
                        : t('Een naam die op deze server niet bestaat en die alleen aanvallers proberen.')) . ($blocked ? " · ⛔ $blocked" : ''), $ip);
            }
            $n = (int) q('SELECT SUM(n) FROM ids_attempts WHERE host_id = ? AND ip = ? AND at >= NOW() - INTERVAL ? MINUTE', [$hid, $ip, $win])->fetchColumn();
            if ($n >= $limit) {
                $users = implode(', ', array_slice((array) ($f['users'] ?? []), 0, 5));
                $blocked = self::autoBlock($ctx['host'], $ip, t('Brute force: {n} mislukte SSH-logins in {win} min', ['n' => $n, 'win' => $win]));
                self::incident($ctx, 1, 'bruteforce', $ip, t('Brute force op SSH vanaf {ip} ({n} pogingen in {win} min)', ['ip' => $ip, 'n' => $n, 'win' => $win]),
                    t('Gebruikers') . ': ' . ($users ?: '?') . ($blocked ? " · ⛔ $blocked" : ''), $ip);
            }
        }
        foreach (array_slice((array) ($auth['events'] ?? []), 0, 300) as $e) {
            $k = (string) ($e['k'] ?? '');
            if ($k !== 'ssh' && $k !== 'console') {
                continue;
            }
            $ip = (string) ($e['ip'] ?? '');
            $user = mb_substr((string) ($e['u'] ?? '?'), 0, 64);
            $special = false;   // al een incident voor deze login (dan geen 🔑-incident of info erbij)
            if ($k === 'ssh') {
                // Gelukt na veel mislukte pogingen vanaf hetzelfde IP (afgelopen uur): mogelijk ingebroken
                $fails = (int) q('SELECT COALESCE(SUM(n), 0) FROM ids_attempts WHERE host_id = ? AND ip = ? AND at >= NOW() - INTERVAL 1 HOUR', [$hid, $ip])->fetchColumn();
                if ($fails >= max(3, intdiv($limit, 2))) {
                    $special = true;
                    self::incident($ctx, 2, 'breakin', "$user|$ip", t('Inlog na brute force: {user} vanaf {ip}', ['user' => $user, 'ip' => $ip]),
                        t('Eerst {n} mislukte pogingen vanaf dit IP in het afgelopen uur, daarna een geslaagde login. Controleer of jij dit was.', ['n' => $fails]), $ip, true);
                }
                if ($user === 'root') {
                    $special = true;
                    self::incident($ctx, 1, 'rootlogin', $ip, t('Root-login via SSH vanaf {ip}', ['ip' => $ip]), t('Inloggen als root via SSH; liever als gewone gebruiker en daarna sudo.'), $ip);
                }
                $known = q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'loginip' AND item = ?", [$hid, $ip])->fetchColumn();
                if (!$known && $ip !== '') {
                    q("INSERT IGNORE INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'loginip', ?, ?)", [$hid, $ip, $user]);
                    if (!$ctx['learning']) {
                        $special = true;
                        self::incident($ctx, 1, 'newip', $ip, t('Login vanaf een nieuw IP: {user} vanaf {ip}', ['user' => $user, 'ip' => $ip]), t('Vanaf dit adres is op deze server nog niet eerder ingelogd.'), $ip);
                    }
                }
            }
            if ($special || AuthEvents::ignored([$user], $ip)) {
                continue;
            }
            self::loginSeen($ctx, $k, $user, $ip, (string) ($e['m'] ?? ''));
        }
    }

    /** Je eigen netwerken (Instellingen → Toegang → Lokale netwerken); console-logins (zonder IP) gelden als binnen */
    private static function internalIp(string $ip): bool
    {
        $nets = parse_list((string) setting('trusted_networks', '')) ?: PRIVATE_NETWORKS;
        return $ip === '' || ip_in_list($ip, $nets);
    }

    /**
     * Gewone geslaagde login (1.80.0). Van buiten je netwerk: 🔑 incident (warning, met melding), dat open blijft tot
     * "Gezien" of "Dit is normaal"; zelfde gebruiker en IP binnen 24 uur telt op. Van binnen (en aan de console): een
     * ℹ️ systeeminfo-bericht, per kanaal aan of uit (Instellingen → Meldingen → Systeeminfo).
     */
    private static function loginSeen(array $ctx, string $kind, string $user, string $ip, string $method): void
    {
        $host = (string) $ctx['host']['name'];
        $how = ['publickey' => t('sleutel'), 'password' => t('wachtwoord'), 'keyboard-interactive/pam' => t('wachtwoord')][$method] ?? '';
        if (!self::internalIp($ip)) {
            $new = self::incident($ctx, 1, 'ssh_login', "$user|$ip", t('SSH-login van buiten: {user} vanaf {ip}', ['user' => $user, 'ip' => $ip]),
                t('Geslaagde login van buiten je netwerk{how}. Was jij dit? Klik dan Gezien, of Dit is normaal voor deze gebruiker en dit IP-adres.',
                    ['how' => $how !== '' ? ' (' . $how . ')' : '']), $ip);
            if ($new) {
                self::notify($host . ': ' . t('SSH-login van buiten: {user} vanaf {ip}', ['user' => $user, 'ip' => $ip]),
                    t('Geslaagde login van buiten je netwerk{how}.', ['how' => $how !== '' ? ' (' . $how . ')' : '']), STATUS_WARN);
            }
            return;
        }
        $title = $kind === 'console' ? '🖥️ ' . t('Console-login: {u} op {host}', ['u' => $user, 'host' => $host])
            : '🔑 ' . t('SSH-login: {u} op {host}', ['u' => $user, 'host' => $host]);
        Monitor::sysInfo('server_login', (int) $ctx['host']['id'], $host, $title,
            fn() => $kind === 'console' ? t('aan de console') : t('vanaf {ip}', ['ip' => $ip]) . ($how !== '' ? " ($how)" : ''), $ip !== '' ? $ip : null);
    }

    private static function hostOf(string $peer): string
    {
        return preg_match('/^\[?([0-9a-fA-F:.]+?)\]?:\d+$/', $peer, $m) ? $m[1] : $peer;
    }

    // ------------------------------------------------------------------ incidenten

    /**
     * Incident vastleggen. Hetzelfde (server, soort, sleutel) binnen 24 uur: optellen bij het bestaande.
     * Uitzonderingen ("Dit is normaal") worden overgeslagen. Critical gaat ook naar Pushover.
     */
    /** @param bool $openOnly alleen optellen bij een open incident (bestanden: na goedkeuren is een nieuwe wijziging een nieuw incident) */
    private static function incident(array $ctx, int $sev, string $type, string $key, string $title, string $detail, ?string $ip = null, bool $always = false, bool $openOnly = false): bool
    {
        $hid = (int) $ctx['host']['id'];
        $key = mb_substr($key, 0, 255);
        if (q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'ignore' AND item = ?", [$hid, mb_substr("$type|$key", 0, 255)])->fetchColumn()) {
            return false;
        }
        $prev = q('SELECT id FROM ids_incidents WHERE host_id = ? AND type = ? AND dkey = ? AND created_at >= NOW() - INTERVAL 1 DAY'
                  . ($openOnly ? " AND status = 'open'" : '') . ' ORDER BY id DESC LIMIT 1',
            [$hid, $type, $key])->fetchColumn();
        if ($prev) {
            q('UPDATE ids_incidents SET hits = hits + 1, last_seen = NOW(), title = ?, detail = ? WHERE id = ?',
                [mb_substr($title, 0, 250), mb_substr($detail, 0, 1000), $prev]);
            return false;
        }
        q('INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$hid, $sev, $type, $key, mb_substr($title, 0, 250), mb_substr($detail, 0, 1000), $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null]);
        if ($sev === 2) {
            self::notify($ctx['host']['name'] . ': ' . $title, $detail);
        }
        return true;
    }

    /** Naar Pushover (ook tijdens stille uren), en in Meldingen. Critical, of (SSH-login van buiten) warning. */
    private static function notify(string $title, string $detail, int $status = STATUS_CRIT): void
    {
        $sent = null;
        $err = '';
        if (setting('pushover_enabled', '0') === '1') {
            $base = rtrim((string) config('base_url', ''), '/');
            $prio = $status === STATUS_CRIT ? min(1, (int) setting('pushover_priority_crit', 1)) : min(1, (int) setting('pushover_priority_warn', 0));
            $r = Pushover::send("🛡️ IDS: $title", $detail . "\n" . date('d-m-Y H:i:s'), $prio, null, null,
                $base !== '' ? ['url' => "$base/#ids", 'url_title' => 'Open IDS'] : []);
            $sent = $r['ok'];
            $err = $r['ok'] ? '' : ' [' . t('niet verzonden') . ': Pushover: ' . $r['error'] . ']';
        }
        q("INSERT INTO alerts (check_id, status, title, message, sent_ok, kind) VALUES (NULL, ?, ?, ?, ?, 'ids')",
            [$status, mb_substr("🛡️ IDS: $title", 0, 250), mb_substr($detail . $err, 0, 1000), $sent ? 1 : 0]);
    }

    // ------------------------------------------------------------------ blokkeren

    /** IP's die nooit automatisch geblokkeerd worden: LAN, RzMon zelf en de eigen lijst. */
    public static function protectedIp(string $ip): bool
    {
        if (ip_in_list($ip, PRIVATE_NETWORKS) || ip_in_list($ip, parse_list((string) setting('ids_allow', '')))) {
            return true;
        }
        $own = [];
        $base = (string) parse_url((string) config('base_url', ''), PHP_URL_HOST);
        if ($base !== '') {
            $own = @inet_pton($base) !== false ? [$base] : (gethostbynamel($base) ?: []);
        }
        return in_array($ip, $own, true);
    }

    /** @return string|null beschrijving van de blokkade, of null als er niet geblokkeerd is */
    private static function autoBlock(array $host, string $ip, string $reason): ?string
    {
        if (!self::blocking() || self::protectedIp($ip)) {
            return null;
        }
        $hid = (int) $host['id'];
        $fw = (string) q('SELECT fw_state FROM ids_state WHERE host_id = ?', [$hid])->fetchColumn();
        if ($fw === 'unsupported') {
            return null;   // bv. TrueNAS: alleen melden
        }
        if (q('SELECT 1 FROM ids_blocks WHERE host_id = ? AND ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW())', [$hid, $ip])->fetchColumn()) {
            return t('al geblokkeerd');
        }
        // Eerder geblokkeerd (laatste 30 dagen): langer
        $repeat = (bool) q('SELECT 1 FROM ids_blocks WHERE host_id = ? AND ip = ? AND created_at >= NOW() - INTERVAL 30 DAY', [$hid, $ip])->fetchColumn();
        $hours = $repeat ? 24 * max(1, (int) setting('ids_repeat_days', 7)) : max(1, (int) setting('ids_block_hours', 24));
        q('INSERT INTO ids_blocks (host_id, ip, reason, auto, created_by, until) VALUES (?, ?, ?, 1, ?, ?)',
            [$hid, $ip, mb_substr($reason . ($repeat ? ' (' . t('herhaling') . ')' : ''), 0, 255), t('automatisch'), date('Y-m-d H:i:s', time() + $hours * 3600)]);
        return $hours >= 48 ? t('geblokkeerd voor {n} dagen', ['n' => intdiv($hours, 24)]) : t('geblokkeerd voor {n} uur', ['n' => $hours]);
    }

    /** Blokkade opheffen; ook de pogingen van dat IP vergeten (anders volgt meteen een nieuwe automatische). @return array|null de blokkade */
    public static function unblock(int $id, string $by): ?array
    {
        $b = q('SELECT * FROM ids_blocks WHERE id = ?', [$id])->fetch();
        if (!$b) {
            return null;
        }
        q('UPDATE ids_blocks SET removed_at = NOW(), removed_by = ? WHERE id = ? AND removed_at IS NULL', [mb_substr($by, 0, 100), $b['id']]);
        q('DELETE FROM ids_attempts WHERE host_id = ? AND ip = ?', [$b['host_id'], $b['ip']]);
        return $b;
    }

    /** Met de hand blokkeren. @return true|string */
    public static function block(int $hostId, string $ip, ?int $hours, string $reason, string $by, string $byIp): true|string
    {
        $ip = trim($ip);
        if (@inet_pton($ip) === false) {
            return t('Ongeldig IP-adres');
        }
        if ($ip === $byIp) {
            return t('Dit is het IP-adres waarmee je nu in RzMon zit; dat blokkeren we niet');
        }
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return t('Localhost blokkeren kan niet');
        }
        if (!q("SELECT 1 FROM hosts WHERE id = ? AND mode = 'agent'", [$hostId])->fetchColumn()) {
            return t('Kies een server met agent');
        }
        if (q('SELECT 1 FROM ids_blocks WHERE host_id = ? AND ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW())', [$hostId, $ip])->fetchColumn()) {
            return t('Dit IP is op die server al geblokkeerd');
        }
        q('INSERT INTO ids_blocks (host_id, ip, reason, auto, created_by, until) VALUES (?, ?, ?, 0, ?, ?)',
            [$hostId, $ip, mb_substr($reason ?: t('met de hand'), 0, 255), $by, $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null]);
        return true;
    }

    // ------------------------------------------------------------------ wekelijks overzicht (1.81.0)

    /** Elke minuut (cron): op de gekozen dag en tijd het overzicht van de afgelopen 7 dagen versturen */
    public static function weeklyCron(): ?string
    {
        $via = (string) setting('ids_weekly', 'email');
        if (!self::enabled() || !in_array($via, ['email', 'pushover', 'both'], true)) {
            return null;
        }
        $day = max(1, min(7, (int) setting('ids_weekly_day', '1')));
        $at = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) setting('ids_weekly_time', '08:00')) ? (string) setting('ids_weekly_time', '08:00') : '08:00';
        if ((int) date('N') !== $day || date('H:i') < $at || setting('ids_weekly_last', '') === date('Y-m-d')) {
            return null;
        }
        save_setting('ids_weekly_last', date('Y-m-d'));
        return self::sendWeekly($via);
    }

    /** Overzicht maken en versturen. @return string wat er gebeurde (voor het cron-log) */
    public static function sendWeekly(string $via): string
    {
        [$subject, $body, $short] = self::weeklyText();
        $out = [];
        if (in_array($via, ['email', 'both'], true) && setting('email_enabled', '0') === '1') {
            $r = Mailer::send($subject, $body);
            $out[] = 'e-mail ' . ($r['ok'] ? 'ok' : $r['error']);
        }
        if (in_array($via, ['pushover', 'both'], true) && setting('pushover_enabled', '0') === '1') {
            $r = Pushover::send($subject, $short, -1);
            $out[] = 'Pushover ' . ($r['ok'] ? 'ok' : $r['error']);
        }
        return 'IDS-weekoverzicht: ' . (implode(', ', $out) ?: 'geen kanaal aan');
    }

    /** @return array{0:string,1:string,2:string} onderwerp, tekst (e-mail) en korte tekst (Pushover) */
    public static function weeklyText(): array
    {
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $inc = q('SELECT severity, COUNT(*) n FROM ids_incidents WHERE created_at >= ? GROUP BY severity', [$since])->fetchAll(PDO::FETCH_KEY_PAIR);
        $open = q("SELECT severity, COUNT(*) n FROM ids_incidents WHERE status = 'open' GROUP BY severity")->fetchAll(PDO::FETCH_KEY_PAIR);
        $blocks = q('SELECT auto, COUNT(*) n FROM ids_blocks WHERE created_at >= ? GROUP BY auto', [$since])->fetchAll(PDO::FETCH_KEY_PAIR);
        $decoy = (int) q("SELECT COALESCE(SUM(hits), 0) FROM ids_incidents WHERE created_at >= ? AND type IN ('decoy_user', 'decoy_port')", [$since])->fetchColumn();
        $fails = 0;
        $top = [];
        foreach (q("SELECT detail FROM access_log WHERE kind = 'authfail' AND type = 'ssh' AND created_at >= ?", [$since])->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $d = json_decode((string) $d, true) ?: [];
            $n = max(1, (int) ($d['n'] ?? 1));
            $fails += $n;
            if (($d['ip'] ?? '') !== '') {
                $top[$d['ip']] = ($top[$d['ip']] ?? 0) + $n;
            }
        }
        arsort($top);
        $outside = q("SELECT i.title, i.hits, h.name FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.type = 'ssh_login' AND i.created_at >= ? ORDER BY i.created_at DESC LIMIT 10",
            [$since])->fetchAll();
        $openList = q("SELECT i.title, i.severity, h.name FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.status = 'open' ORDER BY i.severity DESC, i.created_at DESC LIMIT 10")->fetchAll();
        $nOpen = (int) ($open[2] ?? 0) + (int) ($open[1] ?? 0);

        $lines = [t('IDS-overzicht van {from} tot {to}', ['from' => date('d-m', time() - 7 * 86400), 'to' => date('d-m-Y')]), ''];
        $lines[] = '⛔ ' . t('Geblokkeerd: {n} (automatisch {a}, met de hand {m})', ['n' => array_sum($blocks), 'a' => (int) ($blocks[1] ?? 0), 'm' => (int) ($blocks[0] ?? 0)]);
        $lines[] = '❌ ' . t('Mislukte SSH-logins: {n}', ['n' => $fails]);
        $lines[] = '🪤 ' . t('Lokaas aangetikt: {n}×', ['n' => $decoy]);
        $lines[] = '⚠️ ' . t('Nieuwe incidenten: {c} critical, {w} warning', ['c' => (int) ($inc[2] ?? 0), 'w' => (int) ($inc[1] ?? 0)]);
        $lines[] = '📌 ' . t('Nu nog open: {c} critical, {w} warning', ['c' => (int) ($open[2] ?? 0), 'w' => (int) ($open[1] ?? 0)]);
        if ($top) {
            $lines[] = '';
            $lines[] = t('Meeste mislukte logins:');
            foreach (array_slice($top, 0, 5, true) as $ip => $n) {
                $lines[] = "  • $ip: {$n}×";
            }
        }
        if ($outside) {
            $lines[] = '';
            $lines[] = t('SSH-logins van buiten:');
            foreach ($outside as $o) {
                $lines[] = "  • {$o['name']}: {$o['title']}" . ((int) $o['hits'] > 1 ? " ({$o['hits']}×)" : '');
            }
        }
        if ($openList) {
            $lines[] = '';
            $lines[] = t('Open incidenten:');
            foreach ($openList as $o) {
                $lines[] = '  • ' . ((int) $o['severity'] === 2 ? '🔴' : '🟠') . " {$o['name']}: {$o['title']}";
            }
        }
        $base = rtrim((string) config('base_url', ''), '/');
        if ($base !== '') {
            $lines[] = '';
            $lines[] = "RzMon: $base/#ids";
        }
        $short = t('{b} geblokkeerd · {f} mislukte logins · {d}× lokaas · {o} open', ['b' => array_sum($blocks), 'f' => $fails, 'd' => $decoy, 'o' => $nOpen]);
        return ['🛡️ ' . t('IDS-weekoverzicht'), implode("\n", $lines), $short];
    }

    /**
     * Login-incidenten vanaf je eigen netwerk (nieuw IP, root-login) sluiten na 24 uur vanzelf (1.80.0); die van buiten
     * blijven open tot "Gezien" of "Dit is normaal". Inlog na brute force (critical) blijft altijd open.
     */
    public static function autoClose(): int
    {
        $n = 0;
        foreach (q("SELECT id, ip FROM ids_incidents WHERE status = 'open' AND type IN ('newip', 'rootlogin', 'ssh_login') AND last_seen < NOW() - INTERVAL 1 DAY")->fetchAll() as $i) {
            if (self::internalIp((string) $i['ip'])) {
                $n += q("UPDATE ids_incidents SET status = 'done', handled_by = 'RzMon', handled_at = NOW() WHERE id = ? AND status = 'open'", [$i['id']])->rowCount();
            }
        }
        return $n;
    }

    public static function purge(): void
    {
        self::autoClose();
        q('DELETE FROM ids_attempts WHERE at < NOW() - INTERVAL 1 DAY');
        q('DELETE FROM ids_incidents WHERE created_at < NOW() - INTERVAL ? DAY', [Monitor::alertDays()]);
        q('DELETE FROM ids_blocks WHERE (removed_at IS NOT NULL AND removed_at < NOW() - INTERVAL 90 DAY) OR (until IS NOT NULL AND until < NOW() - INTERVAL 90 DAY)');
    }
}
