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
 */
final class Ids
{
    public const SEVERITY = [1 => 'warning', 2 => 'critical'];
    // Bestanden waarvan een wijziging critical is (andere gevoelige bestanden: warning)
    private const CRIT_FILES = ['#^/root/\.ssh/authorized_keys$#', '#^/etc/sudoers(\.d/.*)?$#', '#^/etc/ld\.so\.preload$#'];
    // Diensten die bij elke start een willekeurige poort kiezen: alleen de naam telt
    private const RANDOM_PORT_PROCS = ['rpc.statd', 'rpc.mountd', 'rpcbind', 'rpc.rquotad', 'avahi-daemon', 'chronyd', 'dhclient', 'NetworkManager'];
    private const BAD_PORTS = [1337, 4444, 4445, 5555, 6666, 6667, 6697, 12345, 31337];

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
        if (self::blocking()) {
            foreach (self::activeBlocks($hostId) as $b) {
                $out[] = implode("\t", [0, 'block', $b['ip'], $b['until'] ? strtotime($b['until']) : 0]);
            }
        }
        return $out;
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
            q('INSERT INTO ids_state (host_id, baseline_until) VALUES (?, ?)', [$hid, $until]);
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
        $ctx = ['host' => $host, 'learning' => $learning];

        // Gebruikers eerst: een nieuwe gebruiker verandert ook passwd/shadow/group, dat hoeft niet apart
        $userChange = self::users($ctx, (array) ($ids['users'] ?? []));
        self::files($ctx, (array) ($ids['files'] ?? []), $updating, $userChange);
        self::ports($ctx, $ports);
        self::simpleSet($ctx, 'suid', (array) ($ids['suid'] ?? []), 'suid_new', 'Nieuw SUID-bestand', 'Kan gebruikt worden om root te worden.', isset($ids['suid']));
        self::simpleSet($ctx, 'module', (array) ($ids['modules'] ?? []), 'module_new', 'Nieuwe kernelmodule', 'Geladen sinds de vorige controle.', isset($ids['modules']));
        self::simpleSet($ctx, 'pkg', array_slice((array) ($ids['pkgdiff'] ?? []), 0, 100), 'pkg_changed', 'Systeembestand wijkt af van het pakket',
            'rpm -Va / debsums: de inhoud komt niet overeen met het geïnstalleerde pakket.', isset($ids['pkgdiff']));
        foreach (array_slice((array) ($ids['procs'] ?? []), 0, 20) as $p) {
            $exe = (string) ($p['exe'] ?? '');
            $memfd = str_starts_with($exe, '/memfd:');
            self::incident($ctx, $memfd ? 1 : 2, 'proc_suspicious', $exe,
                ($memfd ? 'Programma zonder bestand (memfd)' : 'Verdacht proces') . ': ' . $exe,
                'pid ' . (int) ($p['pid'] ?? 0) . ' · gebruiker ' . ($p['user'] ?? '?') . ' · ' . mb_substr((string) ($p['cmd'] ?? ''), 0, 300));
        }
        foreach (array_slice((array) ($ids['conns'] ?? []), 0, 20) as $c) {
            $peer = (string) ($c['peer'] ?? '');
            $ip = self::hostOf($peer);
            if (($c['kind'] ?? '') === 'shell') {
                self::incident($ctx, 2, 'revshell', ($c['proc'] ?? '') . '|' . $ip, 'Mogelijke reverse shell: ' . ($c['proc'] ?? '?') . ' verbonden met ' . $peer,
                    'pid ' . (int) ($c['pid'] ?? 0) . ' · lokaal ' . ($c['local'] ?? ''), $ip);
            } else {
                self::incident($ctx, 1, 'outbound', $peer, 'Verbinding naar verdachte poort: ' . $peer . ' (' . ($c['proc'] ?? '?') . ')',
                    'Poorten als ' . implode(', ', self::BAD_PORTS) . ' worden vaak door malware gebruikt.', $ip);
            }
        }
        self::logins($ctx, $auth);
    }

    private static function files(array $ctx, array $files, bool $updating, bool $userChange): void
    {
        if (!$files) {
            return;
        }
        $hid = (int) $ctx['host']['id'];
        $known = q("SELECT item, value FROM ids_baseline WHERE host_id = ? AND kind = 'file'", [$hid])->fetchAll(PDO::FETCH_KEY_PAIR);
        $up = db()->prepare("INSERT INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'file', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        foreach (array_slice($files, 0, 1000, true) as $path => $hash) {
            $path = mb_substr((string) $path, 0, 255);
            $hash = preg_replace('/[^0-9a-f]/', '', (string) $hash);
            if ($hash === '' || ($known[$path] ?? null) === $hash) {
                continue;
            }
            $up->execute([$hid, $path, $hash]);
            if ($ctx['learning'] || $updating || ($userChange && preg_match('#^/etc/(passwd|shadow|group|gshadow)$#', $path))) {
                continue;
            }
            $crit = (bool) array_filter(self::CRIT_FILES, fn($re) => preg_match($re, $path));
            $new = !isset($known[$path]);
            self::incident($ctx, $crit ? 2 : 1, 'file', $path, ($new ? 'Nieuw bestand: ' : 'Bestand gewijzigd: ') . $path,
                $new ? 'Dit bestand bestond nog niet.' : 'De inhoud is veranderd (SHA-256), buiten een pakketupdate om.');
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
                self::incident($ctx, 2, 'uid0', $name, "Tweede root-account: $name (UID 0)", 'Een account met UID 0 heeft volledige rechten; een klassieke achterdeur.', null, true);
            }
            if (($known[$name] ?? null) === $uid) {
                continue;
            }
            $up->execute([$hid, $name, $uid]);
            $changed = true;
            if (!$ctx['learning'] && !isset($known[$name])) {
                self::incident($ctx, 1, 'user_new', $name, "Nieuwe gebruiker: $name (UID $uid)", 'Toegevoegd aan /etc/passwd.');
            }
        }
        return $changed;
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
                self::incident($ctx, 1, 'port_new', $key, "Nieuwe luisterende poort: $port ($proc)", 'Luistert op ' . ($p['addr'] ?? '?') . '.');
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
            $n = (int) q('SELECT SUM(n) FROM ids_attempts WHERE host_id = ? AND ip = ? AND at >= NOW() - INTERVAL ? MINUTE', [$hid, $ip, $win])->fetchColumn();
            if ($n >= $limit) {
                $users = implode(', ', array_slice((array) ($f['users'] ?? []), 0, 5));
                $blocked = self::autoBlock($ctx['host'], $ip, "Brute force: $n mislukte SSH-logins in $win min");
                self::incident($ctx, 1, 'bruteforce', $ip, "Brute force op SSH vanaf $ip ($n pogingen in $win min)",
                    'Gebruikers: ' . ($users ?: '?') . ($blocked ? " · ⛔ $blocked" : ''), $ip);
            }
        }
        foreach (array_slice((array) ($auth['events'] ?? []), 0, 300) as $e) {
            if (($e['k'] ?? '') !== 'ssh') {
                continue;
            }
            $ip = (string) ($e['ip'] ?? '');
            $user = mb_substr((string) ($e['u'] ?? '?'), 0, 64);
            // Gelukt na veel mislukte pogingen vanaf hetzelfde IP (afgelopen uur): mogelijk ingebroken
            $fails = (int) q('SELECT COALESCE(SUM(n), 0) FROM ids_attempts WHERE host_id = ? AND ip = ? AND at >= NOW() - INTERVAL 1 HOUR', [$hid, $ip])->fetchColumn();
            if ($fails >= max(3, intdiv($limit, 2))) {
                self::incident($ctx, 2, 'breakin', "$user|$ip", "Inlog na brute force: $user vanaf $ip",
                    "Eerst $fails mislukte pogingen vanaf dit IP in het afgelopen uur, daarna een geslaagde login. Controleer of jij dit was.", $ip, true);
            }
            if ($user === 'root') {
                self::incident($ctx, 1, 'rootlogin', $ip, "Root-login via SSH vanaf $ip", 'Inloggen als root via SSH; liever als gewone gebruiker en daarna sudo.', $ip);
            }
            $known = q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'loginip' AND item = ?", [$hid, $ip])->fetchColumn();
            if (!$known && $ip !== '') {
                q("INSERT IGNORE INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'loginip', ?, ?)", [$hid, $ip, $user]);
                if (!$ctx['learning']) {
                    self::incident($ctx, 1, 'newip', $ip, "Login vanaf een nieuw IP: $user vanaf $ip", 'Vanaf dit adres is op deze server nog niet eerder ingelogd.', $ip);
                }
            }
        }
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
    private static function incident(array $ctx, int $sev, string $type, string $key, string $title, string $detail, ?string $ip = null, bool $always = false): void
    {
        $hid = (int) $ctx['host']['id'];
        $key = mb_substr($key, 0, 255);
        if (q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'ignore' AND item = ?", [$hid, mb_substr("$type|$key", 0, 255)])->fetchColumn()) {
            return;
        }
        $prev = q('SELECT id FROM ids_incidents WHERE host_id = ? AND type = ? AND dkey = ? AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1',
            [$hid, $type, $key])->fetchColumn();
        if ($prev) {
            q('UPDATE ids_incidents SET hits = hits + 1, last_seen = NOW(), title = ?, detail = ? WHERE id = ?',
                [mb_substr($title, 0, 250), mb_substr($detail, 0, 1000), $prev]);
            return;
        }
        q('INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$hid, $sev, $type, $key, mb_substr($title, 0, 250), mb_substr($detail, 0, 1000), $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null]);
        if ($sev === 2) {
            self::notify($ctx['host']['name'] . ': ' . $title, $detail);
        }
    }

    /** Critical naar Pushover (ook tijdens stille uren), en in Meldingen. */
    private static function notify(string $title, string $detail): void
    {
        $sent = null;
        $err = '';
        if (setting('pushover_enabled', '0') === '1') {
            $base = rtrim((string) config('base_url', ''), '/');
            $r = Pushover::send("🛡️ IDS: $title", $detail . "\n" . date('d-m-Y H:i:s'), min(1, (int) setting('pushover_priority_crit', 1)), null, null,
                $base !== '' ? ['url' => "$base/#ids", 'url_title' => 'Open IDS'] : []);
            $sent = $r['ok'];
            $err = $r['ok'] ? '' : ' [niet verzonden: Pushover: ' . $r['error'] . ']';
        }
        q("INSERT INTO alerts (check_id, status, title, message, sent_ok, kind) VALUES (NULL, ?, ?, ?, ?, 'ids')",
            [STATUS_CRIT, mb_substr("🛡️ IDS: $title", 0, 250), mb_substr($detail . $err, 0, 1000), $sent ? 1 : 0]);
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
            return 'al geblokkeerd';
        }
        // Eerder geblokkeerd (laatste 30 dagen): langer
        $repeat = (bool) q('SELECT 1 FROM ids_blocks WHERE host_id = ? AND ip = ? AND created_at >= NOW() - INTERVAL 30 DAY', [$hid, $ip])->fetchColumn();
        $hours = $repeat ? 24 * max(1, (int) setting('ids_repeat_days', 7)) : max(1, (int) setting('ids_block_hours', 24));
        q('INSERT INTO ids_blocks (host_id, ip, reason, auto, created_by, until) VALUES (?, ?, ?, 1, ?, ?)',
            [$hid, $ip, mb_substr($reason . ($repeat ? ' (herhaling)' : ''), 0, 255), 'automatisch', date('Y-m-d H:i:s', time() + $hours * 3600)]);
        return 'geblokkeerd voor ' . ($hours >= 48 ? intdiv($hours, 24) . ' dagen' : "$hours uur");
    }

    /** Met de hand blokkeren. @return true|string */
    public static function block(int $hostId, string $ip, ?int $hours, string $reason, string $by, string $byIp): true|string
    {
        $ip = trim($ip);
        if (@inet_pton($ip) === false) {
            return 'Ongeldig IP-adres';
        }
        if ($ip === $byIp) {
            return 'Dit is het IP-adres waarmee je nu in RzMon zit; dat blokkeren we niet';
        }
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return 'Localhost blokkeren kan niet';
        }
        if (!q("SELECT 1 FROM hosts WHERE id = ? AND mode = 'agent'", [$hostId])->fetchColumn()) {
            return 'Kies een server met agent';
        }
        if (q('SELECT 1 FROM ids_blocks WHERE host_id = ? AND ip = ? AND removed_at IS NULL AND (until IS NULL OR until > NOW())', [$hostId, $ip])->fetchColumn()) {
            return 'Dit IP is op die server al geblokkeerd';
        }
        q('INSERT INTO ids_blocks (host_id, ip, reason, auto, created_by, until) VALUES (?, ?, ?, 0, ?, ?)',
            [$hostId, $ip, mb_substr($reason ?: 'met de hand', 0, 255), $by, $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : null]);
        return true;
    }

    public static function purge(): void
    {
        q('DELETE FROM ids_attempts WHERE at < NOW() - INTERVAL 1 DAY');
        q('DELETE FROM ids_incidents WHERE created_at < NOW() - INTERVAL ? DAY', [Monitor::alertDays()]);
        q('DELETE FROM ids_blocks WHERE (removed_at IS NOT NULL AND removed_at < NOW() - INTERVAL 90 DAY) OR (until IS NOT NULL AND until < NOW() - INTERVAL 90 DAY)');
    }
}
