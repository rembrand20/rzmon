<?php
declare(strict_types=1);

/**
 * Onbekende programma's (1.229.0, agent 1.91.0): een lichte vorm van "application control".
 * - De agent meldt elk programma dat draait één keer (en opnieuw als het verandert): pad, vingerafdruk (sha256), het pakket
 *   waar het bij hoort, de gebruiker en de opdrachtregel. Elke ronde stuurt hij de vingerafdrukken van wat er nu draait
 *   zonder pakket.
 * - RzSense beslist: uit een pakket = goed; wat je toestond = goed; in de leerperiode (standaard 7 dagen na het eerste
 *   rapport) = geleerd; daarna = onbekend: een warning-incident 'app_new' en de vingerafdruk gaat naar de
 *   dreigingsinformatie (CIRCL, MalwareBazaar). Bekende malware wordt daar critical.
 * - Je kunt toestaan of blokkeren, op vingerafdruk of op pad (een map: alles erin), voor één server of voor alle.
 *   Geblokkeerd en het draait = critical incident 'app_block'; staat "stoppen" aan bij de server (Agent Manager, met je
 *   wachtwoord), dan stopt de agent het.
 * Aan/uit: appctl_on (Instellingen → IDS → Programma's of Overzicht → Functies), en per server (Agent Manager).
 */
final class AppControl
{
    public const AGENT = '1.91.0';
    private const STATUSES = ['pkg', 'learn', 'ok', 'new', 'block'];

    public static function enabled(): bool
    {
        return setting('appctl_on', '1') === '1';
    }

    public static function learnDays(): int
    {
        return max(0, min(90, (int) setting('appctl_learn', '7')));
    }

    public static function agentOk(array $host): bool
    {
        return !empty($host['agent_version']) && version_compare((string) $host['agent_version'], self::AGENT, '>=');
    }

    /** Nog in de leerperiode? */
    public static function learning(array $host): bool
    {
        $since = (string) ($host['appctl_since'] ?? '');
        return $since === '' || strtotime($since) > time() - self::learnDays() * 86400;
    }

    /** Regels voor de agent: aan (met stoppen ja/nee) en de geblokkeerde vingerafdrukken */
    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $h = q('SELECT id, appctl, appctl_kill FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || !(int) $h['appctl']) {
            return [];
        }
        $out = [implode("\t", [0, 'apps', (int) $h['appctl_kill']])];
        foreach (array_slice(self::blockedShas($hostId), 0, 200) as $sha) {
            $out[] = "0\tappblock\t$sha";
        }
        return $out;
    }

    /** Geblokkeerde vingerafdrukken voor een server: rechtstreeks, en wat er gezien werd op een geblokkeerd pad */
    public static function blockedShas(int $hostId): array
    {
        $shas = [];
        foreach (q("SELECT sha, path FROM app_rules WHERE action = 'block' AND host_id IN (0, ?)", [$hostId])->fetchAll() as $r) {
            if ($r['sha'] !== '') {
                $shas[$r['sha']] = true;
            }
        }
        foreach (q("SELECT DISTINCT sha FROM app_seen WHERE host_id = ? AND status = 'block'", [$hostId])->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $shas[(string) $s] = true;
        }
        return array_keys($shas);
    }

    /** Wat er voor dit programma besloten is: 'allow', 'block' of null. Blokkeren gaat voor; een server gaat voor alle servers. */
    public static function rule(int $hostId, string $sha, string $path, ?array $rules = null): ?string
    {
        $rules ??= q('SELECT host_id, sha, path, action FROM app_rules WHERE host_id IN (0, ?)', [$hostId])->fetchAll();
        $best = null;
        $bestScore = -1;
        foreach ($rules as $r) {
            if ((int) $r['host_id'] !== 0 && (int) $r['host_id'] !== $hostId) {
                continue;
            }
            $rp = (string) $r['path'];
            $match = ($r['sha'] !== '' && $r['sha'] === $sha && ($rp === '' || $rp === $path))
                || ($r['sha'] === '' && $rp !== '' && ($rp === $path || (str_ends_with($rp, '/') && str_starts_with($path, $rp))));
            if (!$match) {
                continue;
            }
            $score = ($r['action'] === 'block' ? 2 : 0) + ((int) $r['host_id'] ? 1 : 0);
            if ($score > $bestScore) {
                $best = (string) $r['action'];
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** Het rapport van de agent verwerken */
    public static function fromAgent(array $host, array $in, PDOStatement $st, string $now): void
    {
        $a = $in['apps'] ?? null;
        if (!is_array($a)) {
            return;
        }
        $hid = (int) $host['id'];
        if (empty($host['appctl_since'])) {
            q('UPDATE hosts SET appctl_since = ? WHERE id = ? AND appctl_since IS NULL', [$now, $hid]);
            $host['appctl_since'] = $now;
        }
        $new = array_slice(array_values(array_filter((array) ($a['new'] ?? []), 'is_array')), 0, 150);
        $blk = array_slice(array_values(array_filter((array) ($a['blk'] ?? []), 'is_array')), 0, 50);
        $st->execute([$hid, 'apps', json_encode(['at' => time(), 'on' => !empty($a['on']), 'n' => (int) ($a['n'] ?? 0), 'kill' => !empty($a['kill']),
            'blk' => count($blk)], JSON_UNESCAPED_SLASHES), $now]);
        if (!self::enabled()) {
            return;
        }
        $learning = self::learning($host);
        $rules = q('SELECT host_id, sha, path, action FROM app_rules WHERE host_id IN (0, ?)', [$hid])->fetchAll();
        foreach ($new as $x) {
            $sha = strtolower((string) ($x['sha'] ?? ''));
            $path = (string) ($x['p'] ?? '');
            if (!preg_match('/^[0-9a-f]{64}$/', $sha) || $path === '' || $path[0] !== '/' || mb_strlen($path) > 300 || preg_match('/[\x00-\x1F]/', $path)) {
                continue;
            }
            $pkg = mb_substr(preg_replace('/[^\w.+:@-]/u', '', (string) ($x['pkg'] ?? '')) ?? '', 0, 80);
            $user = mb_substr(preg_replace('/[^\w.@-]/u', '', (string) ($x['u'] ?? '')) ?? '', 0, 64);
            $cmd = mb_substr(Ai::scrub((string) ($x['cmd'] ?? '')), 0, 300);
            $rule = self::rule($hid, $sha, $path, $rules);
            $status = match (true) {
                $rule === 'block' => 'block',
                $pkg !== '' => 'pkg',
                $rule === 'allow' => 'ok',
                $learning => 'learn',
                default => 'new',
            };
            $had = q('SELECT status FROM app_seen WHERE host_id = ? AND sha = ? AND path = ?', [$hid, $sha, $path])->fetchColumn();
            if ($had !== false) {
                q('UPDATE app_seen SET last_seen = ?, pkg = ?, username = ?, cmd = ? WHERE host_id = ? AND sha = ? AND path = ?', [$now, $pkg, $user, $cmd, $hid, $sha, $path]);
                continue;
            }
            q('INSERT INTO app_seen (host_id, sha, path, pkg, username, cmd, status, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$hid, $sha, $path, $pkg, $user, $cmd, $status, $now, $now]);
            if ($pkg !== '') {
                continue;
            }
            $inc = null;
            if ($status === 'block') {
                ActionLog::add($hid, 'rzsense', 'appctl', t('Geblokkeerd programma gezien'), "$path · sha256 " . substr($sha, 0, 16) . '…', false);
            } elseif ($status === 'new') {
                $inc = Ids::raise($host, 1, 'app_new', $sha, t('Onbekend programma: {p}', ['p' => $path]),
                    t('Draait als {u}: {cmd}', ['u' => $user ?: '?', 'cmd' => $cmd]) . ' · ' . t('niet uit een pakket') . ' · sha256 ' . substr($sha, 0, 16) . '… · '
                    . t('Vertrouw je het? Sta het toe in IDS → Programma\'s; anders blokkeren.'));
                ActionLog::add($hid, 'rzsense', 'appctl', t('Onbekend programma gezien'), "$path · sha256 " . substr($sha, 0, 16) . '…');
            } elseif (!empty($x['ch']) && $status !== 'block') {
                $inc = Ids::raise($host, 1, 'app_changed', substr(sha1($path), 0, 16), t('Programma veranderd: {p}', ['p' => $path]),
                    t('Dit programma (niet uit een pakket) heeft een andere vingerafdruk dan eerst. Een eigen update, of is het vervangen?') . ' · sha256 ' . substr($sha, 0, 16) . '…');
                ActionLog::add($hid, 'rzsense', 'appctl', t('Programma veranderd'), "$path · sha256 " . substr($sha, 0, 16) . '…');
            }
            ThreatIntel::queue('h', $sha, $hid, $inc, $path);   // ook in de leerperiode: bekende malware wil je meteen weten
        }
        // Wat er nu draait (zonder pakket): laatst gezien bijwerken
        $run = array_values(array_filter(array_map(static fn($s) => strtolower((string) $s), array_slice((array) ($a['run'] ?? []), 0, 300)),
            static fn($s) => preg_match('/^[0-9a-f]{64}$/', $s) === 1));
        foreach (array_chunk($run, 100) as $chunk) {
            q('UPDATE app_seen SET last_seen = ? WHERE host_id = ? AND sha IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', [$now, $hid, ...$chunk]);
        }
        foreach ($blk as $b) {
            $sha = strtolower((string) ($b['sha'] ?? ''));
            if (!preg_match('/^[0-9a-f]{64}$/', $sha)) {
                continue;
            }
            $path = mb_substr((string) ($b['p'] ?? ''), 0, 300);
            $killed = (int) ($b['killed'] ?? 0);
            Ids::raise($host, 2, 'app_block', $sha, t('Geblokkeerd programma draaide: {p}', ['p' => $path]),
                ($killed ? '⛔ ' . t('gestopt ({n}×)', ['n' => $killed]) : t('niet gestopt: zet "Geblokkeerde programma\'s stoppen" aan in de Agent Manager'))
                . ' · ' . t('gebruiker {u}', ['u' => (string) ($b['u'] ?? '?')]) . ' · sha256 ' . substr($sha, 0, 16) . '…');
        }
    }

    /** Beslissen: toestaan, blokkeren of de beslissing weghalen ('none'). Op vingerafdruk, of op pad als $sha leeg is. */
    public static function decide(string $action, string $sha, string $path, int $hostId, string $note, string $by): int
    {
        $sha = strtolower(trim($sha));
        $path = trim($path);
        if (!in_array($action, ['allow', 'block', 'none'], true)) {
            throw new InvalidArgumentException(t('Onbekende keuze'));
        }
        if ($sha !== '' && !preg_match('/^[0-9a-f]{64}$/', $sha)) {
            throw new InvalidArgumentException(t('Ongeldige vingerafdruk (sha256)'));
        }
        if ($path !== '' && ($path[0] !== '/' || str_contains($path, '..') || mb_strlen($path) > 300 || preg_match('/[\x00-\x1F]/', $path))) {
            throw new InvalidArgumentException(t('Ongeldig pad'));
        }
        if ($sha === '' && $path === '') {
            throw new InvalidArgumentException(t('Geef een vingerafdruk of een pad'));
        }
        if ($action === 'block' && $sha === '' && ($path === '/' || preg_match('#^/(usr|bin|sbin|lib|lib64|etc)/?$#', $path))) {
            throw new InvalidArgumentException(t('Zo\'n brede map blokkeren kan niet: kies een programma of een kleinere map.'));
        }
        q('DELETE FROM app_rules WHERE host_id = ? AND sha = ? AND path = ?', [$hostId, $sha, $sha !== '' ? '' : $path]);
        if ($action !== 'none') {
            q('INSERT INTO app_rules (host_id, sha, path, action, note, username, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$hostId, $sha, $sha !== '' ? '' : $path, $action, mb_substr($note, 0, 200), $by, now()]);
        }
        // De status van wat al gezien is bijwerken
        $where = $sha !== '' ? 'sha = ?' : (str_ends_with($path, '/') ? 'path LIKE ?' : 'path = ?');
        $arg = $sha !== '' ? $sha : (str_ends_with($path, '/') ? addcslashes($path, '%_\\') . '%' : $path);
        $rows = q("SELECT host_id, sha, path, pkg, status FROM app_seen WHERE $where" . ($hostId ? ' AND host_id = ' . $hostId : ''), [$arg])->fetchAll();
        $hosts = [];
        $hostRows = [];
        foreach ($rows as $r) {
            $hid = (int) $r['host_id'];
            $hostRows[$hid] ??= q('SELECT * FROM hosts WHERE id = ?', [$hid])->fetch() ?: [];
            $rule = self::rule($hid, (string) $r['sha'], (string) $r['path']);
            $status = match (true) {
                $rule === 'block' => 'block',
                $r['pkg'] !== '' => 'pkg',
                $rule === 'allow' => 'ok',
                in_array($r['status'], ['ok', 'block'], true) => self::learning($hostRows[$hid]) ? 'learn' : 'new',
                default => (string) $r['status'],
            };
            if ($status !== $r['status']) {
                q('UPDATE app_seen SET status = ? WHERE host_id = ? AND sha = ? AND path = ?', [$status, $hid, $r['sha'], $r['path']]);
            }
            if ($status === 'ok') {
                q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE host_id = ? AND type IN ('app_new', 'app_changed') AND status = 'open' AND dkey IN (?, ?)",
                    [$by . ' (' . t('toegestaan') . ')', $hid, $r['sha'], substr(sha1((string) $r['path']), 0, 16)]);
            }
            $hosts[$hid] = true;
        }
        $label = ['allow' => t('Programma toegestaan'), 'block' => t('Programma geblokkeerd'), 'none' => t('Keuze voor programma weggehaald')][$action];
        $what = ($sha !== '' ? 'sha256 ' . substr($sha, 0, 16) . '…' : $path) . ($note !== '' ? " · $note" : '') . ($hostId ? '' : ' · ' . t('alle servers'));
        if ($hostId) {
            $hosts[$hostId] = true;
        }
        if (!$hosts) {
            ActionLog::by($by, null, 'appctl', $label, $what);
        }
        foreach (array_keys($hosts) as $hid) {
            ActionLog::by($by, $hid, 'appctl', $label, $what);
        }
        return count($rows);
    }

    /** Lijst voor IDS → Programma's: wat er gezien is (standaard zonder pakket), met filters */
    public static function list(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        $status = (string) ($f['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'a.status = ?';
            $args[] = $status;
        } elseif ($status !== 'all') {
            $where[] = "a.status <> 'pkg'";
        }
        if ((int) ($f['host'] ?? 0)) {
            $where[] = 'a.host_id = ?';
            $args[] = (int) $f['host'];
        }
        if (($s = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = '(a.path LIKE ? OR a.sha LIKE ? OR a.pkg LIKE ? OR a.cmd LIKE ?)';
            $like = '%' . addcslashes($s, '%_\\') . '%';
            array_push($args, $like, strtolower($like), $like, $like);
        }
        $rows = q('SELECT a.*, h.name AS host, t.verdict FROM app_seen a JOIN hosts h ON h.id = a.host_id LEFT JOIN ti_hash t ON t.sha = a.sha WHERE '
            . implode(' AND ', $where) . " ORDER BY FIELD(a.status, 'block', 'new', 'ok', 'learn', 'pkg'), a.last_seen DESC LIMIT 500", $args)->fetchAll();
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (q('SELECT status, COUNT(*) AS n FROM app_seen GROUP BY status')->fetchAll() as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $hostsOn = [];
        foreach (q("SELECT id, name, appctl, appctl_kill, appctl_since, agent_version FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll() as $h) {
            $hostsOn[] = ['id' => (int) $h['id'], 'name' => (string) $h['name'], 'on' => (bool) $h['appctl'], 'kill' => (bool) $h['appctl_kill'],
                'learning' => self::learning($h), 'until' => $h['appctl_since'] ? date('Y-m-d H:i:s', strtotime((string) $h['appctl_since']) + self::learnDays() * 86400) : null,
                'agent_ok' => self::agentOk($h)];
        }
        return ['on' => self::enabled(), 'learn' => self::learnDays(), 'rows' => array_map(static fn($r) => ['host_id' => (int) $r['host_id'], 'host' => $r['host'],
            'sha' => $r['sha'], 'path' => $r['path'], 'pkg' => $r['pkg'], 'user' => $r['username'], 'cmd' => $r['cmd'], 'status' => $r['status'],
            'first' => $r['first_seen'], 'last' => $r['last_seen'], 'verdict' => (string) ($r['verdict'] ?? '')], $rows),
            'counts' => $counts, 'hosts' => $hostsOn, 'rules' => self::rules()];
    }

    public static function rules(): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'host_id' => (int) $r['host_id'], 'host' => $r['host'] ?? null, 'sha' => $r['sha'], 'path' => $r['path'],
            'action' => $r['action'], 'note' => $r['note'], 'by' => $r['username'], 'at' => $r['created_at']],
            q('SELECT r.*, h.name AS host FROM app_rules r LEFT JOIN hosts h ON h.id = r.host_id ORDER BY r.created_at DESC LIMIT 500')->fetchAll());
    }

    public static function deleteRule(int $id, string $by): void
    {
        $r = q('SELECT * FROM app_rules WHERE id = ?', [$id])->fetch();
        if ($r) {
            self::decide('none', (string) $r['sha'], (string) $r['path'], (int) $r['host_id'], '', $by);
        }
    }

    /** Voor de Agent Manager */
    public static function info(int $hostId): array
    {
        $h = q('SELECT id, appctl, appctl_kill, appctl_since, agent_version FROM hosts WHERE id = ?', [$hostId])->fetch() ?: [];
        $last = (array) json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'apps'", [$hostId])->fetchColumn(), true);
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (q('SELECT status, COUNT(*) AS n FROM app_seen WHERE host_id = ? GROUP BY status', [$hostId])->fetchAll() as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        return ['on' => self::enabled(), 'host_on' => (bool) ($h['appctl'] ?? 1), 'kill' => (bool) ($h['appctl_kill'] ?? 0), 'since' => $h['appctl_since'] ?? null,
            'learning' => $h ? self::learning($h) : true, 'learn' => self::learnDays(), 'counts' => $counts, 'last' => $last ?: null, 'agent_ok' => $h && self::agentOk($h),
            'rows' => array_map(static fn($r) => ['sha' => $r['sha'], 'path' => $r['path'], 'user' => $r['username'], 'status' => $r['status'], 'last' => $r['last_seen']],
                q("SELECT * FROM app_seen WHERE host_id = ? AND status <> 'pkg' ORDER BY FIELD(status, 'block', 'new', 'ok', 'learn'), last_seen DESC LIMIT 100", [$hostId])->fetchAll())];
    }

    /** Per server aan/uit en stoppen (Agent Manager) */
    public static function saveHost(int $hostId, bool $on, bool $kill, string $by): array
    {
        $before = q('SELECT appctl, appctl_kill FROM hosts WHERE id = ?', [$hostId])->fetch() ?: [];
        $new = ['appctl' => $on ? 1 : 0, 'appctl_kill' => $kill ? 1 : 0];
        q('UPDATE hosts SET appctl = ?, appctl_kill = ? WHERE id = ?', [$new['appctl'], $new['appctl_kill'], $hostId]);
        $parts = [];
        if ((int) ($before['appctl'] ?? -1) !== $new['appctl']) {
            $parts[] = t('programma\'s bijhouden') . ' ' . ($on ? t('aan') : t('uit'));
        }
        if ((int) ($before['appctl_kill'] ?? -1) !== $new['appctl_kill']) {
            $parts[] = t('geblokkeerde programma\'s stoppen') . ' ' . ($kill ? t('aan') : t('uit'));
        }
        if ($parts) {
            ActionLog::by($by, $hostId, 'appctl', t('Programma\'s ingesteld'), implode(' · ', $parts));
            ChangeLog::record('host', $hostId, 'update', (string) q('SELECT name FROM hosts WHERE id = ?', [$hostId])->fetchColumn() . ' (🧩 ' . t('Programma\'s') . ')', $before, $new);
        }
        return self::info($hostId);
    }

    /** Opruimen: wat 90 dagen niet meer draaide en geen beslissing heeft */
    public static function purge(): void
    {
        q("DELETE FROM app_seen WHERE last_seen < NOW() - INTERVAL 90 DAY AND status IN ('pkg', 'learn', 'new')");
    }
}
