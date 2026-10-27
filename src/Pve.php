<?php
declare(strict_types=1);

/**
 * Proxmox VE (1.105.0, agent 1.61.0). De agent op een Proxmox-host stuurt de guests (VM's en containers), de opslag,
 * ZFS-pools, de cluster, de nieuwste back-up per guest, de back-uptaken en de laatste vzdump-taken.
 * - agent_data 'pve': de node (versie, opslag, ZFS, taken, cluster); 'pveg:<vmid>': één guest; 'pves:<naam>': één opslag.
 * - Checks van het type 'pve', metric: overview, guest (target = VMID), backups (target leeg = alle guests, of een VMID),
 *   storage (target leeg = alle, of een naam) en zfs.
 * - Losse nodes kun je samen als "cluster" aanmerken (hosts.pve_cluster): het overzicht toont ze dan samen.
 */
final class Pve
{
    public const METRICS = ['overview', 'guest', 'backups', 'storage', 'zfs'];

    // ------------------------------------------------------------------ van de agent

    public static function fromAgent(array $host, array $p, PDOStatement $st, string $now): void
    {
        $hid = (int) $host['id'];
        $conf = [];
        foreach ((array) ($p['conf'] ?? []) as $c) {
            if (is_array($c) && count($c) >= 5) {
                $conf[(int) $c[1]] = ['onboot' => (int) $c[2], 'agent' => (int) $c[3], 'template' => (int) $c[4]];
            }
        }
        $bk = (array) ($p['backups'] ?? []);
        $ga = (array) ($p['gagent'] ?? []);
        $guests = [];
        foreach (['qemu' => 'vm', 'lxc' => 'ct'] as $src => $kind) {
            foreach (array_slice((array) ($p[$src] ?? []), 0, 500) as $g) {
                $id = (int) ($g['vmid'] ?? 0);
                if ($id < 1) {
                    continue;
                }
                $c = $conf[$id] ?? [];
                $b = $bk[(string) $id] ?? null;
                $guests[$id] = [
                    'vmid' => $id, 'kind' => $kind, 'name' => mb_substr((string) ($g['name'] ?? ''), 0, 80), 'status' => preg_replace('/[^a-z-]/', '', (string) ($g['status'] ?? '')),
                    'qmp' => preg_replace('/[^a-z-]/', '', (string) ($g['qmpstatus'] ?? '')), 'lock' => preg_replace('/[^a-z-]/', '', (string) ($g['lock'] ?? '')),
                    'cpu' => isset($g['cpu']) ? round((float) $g['cpu'] * 100, 1) : null, 'cpus' => (int) ($g['cpus'] ?? $g['maxcpu'] ?? 0),
                    'mem' => (int) ($g['mem'] ?? 0), 'maxmem' => (int) ($g['maxmem'] ?? 0), 'disk' => (int) ($g['disk'] ?? 0), 'maxdisk' => (int) ($g['maxdisk'] ?? 0),
                    'uptime' => (int) ($g['uptime'] ?? 0), 'template' => (int) (!empty($g['template']) || !empty($c['template'])),
                    'onboot' => (int) ($c['onboot'] ?? 0), 'agent' => (int) ($c['agent'] ?? 0), 'ga' => isset($ga[(string) $id]) ? (int) $ga[(string) $id] : null,
                    'bk_last' => is_array($b) ? self::bkTime((string) ($b[0] ?? '')) : null, 'bk_n' => is_array($b) ? (int) ($b[1] ?? 0) : 0,
                    'bk_store' => is_array($b) ? mb_substr((string) ($b[2] ?? ''), 0, 60) : null,
                ];
            }
        }
        // In welke back-uptaak zit een guest? (alle guests, een lijst, of een pool: dat laatste weten we niet zeker)
        $jobs = [];
        $covered = [];
        $poolJob = false;
        foreach ((array) ($p['jobs'] ?? []) as $j) {
            if (!is_array($j)) {
                continue;
            }
            $on = !isset($j['enabled']) || (int) $j['enabled'] === 1;
            $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($j['vmid'] ?? '')))));
            $ex = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($j['exclude'] ?? '')))));
            $all = !empty($j['all']);
            $jobs[] = ['id' => mb_substr((string) ($j['id'] ?? ''), 0, 60), 'on' => $on, 'schedule' => mb_substr((string) ($j['schedule'] ?? $j['starttime'] ?? ''), 0, 60),
                'storage' => mb_substr((string) ($j['storage'] ?? ''), 0, 60), 'all' => $all, 'vmids' => $ids, 'pool' => mb_substr((string) ($j['pool'] ?? ''), 0, 60)];
            if (!$on) {
                continue;
            }
            if ($all) {
                foreach (array_keys($guests) as $id) {
                    if (!in_array($id, $ex, true)) {
                        $covered[$id] = true;
                    }
                }
            } elseif (!empty($j['pool'])) {
                $poolJob = true;
            } else {
                foreach ($ids as $id) {
                    $covered[$id] = true;
                }
            }
        }
        foreach ($guests as $id => &$g) {
            $g['job'] = isset($covered[$id]) ? 1 : ($poolJob ? null : 0);
        }
        unset($g);
        // Laatste vzdump-taken: de nieuwste, en de nieuwste mislukte
        $tasks = array_values(array_filter((array) ($p['tasks'] ?? []), fn($t) => is_array($t) && ($t['type'] ?? 'vzdump') === 'vzdump' && !empty($t['endtime'])));
        usort($tasks, fn($a, $b) => (int) $b['starttime'] <=> (int) $a['starttime']);
        $last = $tasks[0] ?? null;
        $lastFail = null;
        foreach ($tasks as $t) {
            if (($t['status'] ?? '') !== 'OK') {
                $lastFail = $t;
                break;
            }
        }
        $task = static fn(?array $t) => $t ? ['start' => (int) $t['starttime'], 'end' => (int) $t['endtime'], 'status' => mb_substr((string) ($t['status'] ?? ''), 0, 120),
            'id' => mb_substr((string) ($t['id'] ?? ''), 0, 20)] : null;
        $storage = [];
        foreach (array_slice((array) ($p['storage'] ?? []), 0, 100) as $s) {
            if (!is_array($s) || !preg_match('/^[A-Za-z0-9._-]{1,60}$/', (string) ($s['storage'] ?? ''))) {
                continue;
            }
            $storage[$s['storage']] = ['name' => $s['storage'], 'type' => preg_replace('/[^a-z0-9]/', '', (string) ($s['type'] ?? '')), 'active' => (int) ($s['active'] ?? 0),
                'enabled' => (int) ($s['enabled'] ?? 1), 'used' => (int) ($s['used'] ?? 0), 'total' => (int) ($s['total'] ?? 0), 'shared' => (int) ($s['shared'] ?? 0),
                'content' => mb_substr((string) ($s['content'] ?? ''), 0, 120)];
        }
        $zfs = [];
        foreach (array_slice((array) ($p['zfs'] ?? []), 0, 30) as $z) {
            if (is_array($z) && ($z['name'] ?? '') !== '') {
                $zfs[] = ['name' => mb_substr((string) $z['name'], 0, 60), 'health' => preg_replace('/[^A-Z]/', '', (string) ($z['health'] ?? '')), 'size' => (int) ($z['size'] ?? 0),
                    'alloc' => (int) ($z['alloc'] ?? 0), 'cap' => (int) ($z['cap'] ?? 0), 'scan' => mb_substr((string) ($z['scan'] ?? ''), 0, 200),
                    'scrub' => self::scrubTime((string) ($z['scan'] ?? ''))];
            }
        }
        $cluster = ['name' => null, 'quorate' => null, 'nodes' => []];
        foreach ((array) ($p['cluster'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'cluster') {
                $cluster['name'] = mb_substr((string) ($c['name'] ?? ''), 0, 60);
                $cluster['quorate'] = (int) ($c['quorate'] ?? 0);
            } elseif (($c['type'] ?? '') === 'node') {
                $cluster['nodes'][] = ['name' => mb_substr((string) ($c['name'] ?? ''), 0, 60), 'online' => (int) ($c['online'] ?? 0), 'local' => (int) ($c['local'] ?? 0)];
            }
        }
        $node = ['node' => mb_substr((string) ($p['node'] ?? ''), 0, 60), 'version' => mb_substr((string) ($p['version'] ?? ''), 0, 120), 'at' => (int) ($p['at'] ?? time()),
            'storage' => array_values($storage), 'zfs' => $zfs, 'jobs' => array_slice($jobs, 0, 30), 'pool_job' => $poolJob, 'last_task' => $task($last), 'last_fail' => $task($lastFail),
            'cluster' => $cluster, 'n' => ['vm' => count(array_filter($guests, fn($g) => $g['kind'] === 'vm' && !$g['template'])),
                'ct' => count(array_filter($guests, fn($g) => $g['kind'] === 'ct' && !$g['template'])),
                'running' => count(array_filter($guests, fn($g) => $g['status'] === 'running'))]];
        $st->execute([$hid, 'pve', json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        foreach ($guests as $id => $g) {
            $st->execute([$hid, "pveg:$id", json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now]);
        }
        foreach ($storage as $name => $s) {
            $st->execute([$hid, "pves:$name", json_encode($s), $now]);
        }
        // Guests en opslag die er niet meer zijn
        $keep = array_merge(array_map(fn($id) => "pveg:$id", array_keys($guests)), array_map(fn($n) => "pves:$n", array_keys($storage)));
        $old = q("SELECT metric FROM agent_data WHERE host_id = ? AND (metric LIKE 'pveg:%' OR metric LIKE 'pves:%')", [$hid])->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_diff($old, $keep) as $m) {
            q('DELETE FROM agent_data WHERE host_id = ? AND metric = ?', [$hid, $m]);
        }
    }

    /** "2026-10-27 01:00:02" (lokale tijd van de node) of "…Z" (UTC, Proxmox Backup Server) → unix-tijd */
    private static function bkTime(string $s): ?int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}Z?$/', $s)) {
            return null;
        }
        $t = str_ends_with($s, 'Z') ? strtotime(rtrim($s, 'Z') . ' UTC') : strtotime($s);
        return $t ?: null;
    }

    /** "scrub repaired 0B in 00:10:02 with 0 errors on Sun Aug 10 00:34:03 2026" → unix-tijd */
    private static function scrubTime(string $scan): ?int
    {
        return preg_match('/on \w{3} (\w{3} +\d+ [\d:]+ \d{4})$/', trim($scan), $m) ? (strtotime($m[1]) ?: null) : null;
    }

    // ------------------------------------------------------------------ checks

    private static function data(int $hostId, string $metric): ?array
    {
        $v = q('SELECT value FROM agent_data WHERE host_id = ? AND metric = ?', [$hostId, $metric])->fetchColumn();
        return $v !== false ? (json_decode((string) $v, true) ?: null) : null;
    }

    public static function guests(int $hostId): array
    {
        $out = [];
        foreach (q("SELECT value FROM agent_data WHERE host_id = ? AND metric LIKE 'pveg:%'", [$hostId])->fetchAll(PDO::FETCH_COLUMN) as $v) {
            if ($g = json_decode((string) $v, true)) {
                $out[(int) $g['vmid']] = $g;
            }
        }
        ksort($out);
        return $out;
    }

    public static function guestLabel(array $g): string
    {
        return ($g['kind'] === 'ct' ? 'CT' : 'VM') . " {$g['vmid']}" . ($g['name'] !== '' ? " ({$g['name']})" : '');
    }

    private static function gb(int $b): string
    {
        return number_format($b / 1073741824, $b >= 10 * 1073741824 ? 0 : 1, I18n::lang() === 'en' ? '.' : ',', '') . ' GB';
    }

    private static function age(int $s): string
    {
        return $s >= 172800 ? t('{n} dagen', ['n' => intdiv($s, 86400)]) : ($s >= 7200 ? t('{n} uur', ['n' => intdiv($s, 3600)]) : t('{n} min', ['n' => max(1, intdiv($s, 60))]));
    }

    /** @return array{0: int, 1: float|null, 2: string} [status, waarde, bericht] */
    public static function check(array $check, array $host): array
    {
        $hid = (int) $host['id'];
        $node = self::data($hid, 'pve');
        if (!$node) {
            return [STATUS_UNKNOWN, null, t('Agent meldt geen Proxmox VE (agent {v} of nieuwer, als root op de Proxmox-host)', ['v' => '1.61.0'])];
        }
        $o = json_decode((string) ($check['options'] ?? ''), true) ?: [];
        $w = $check['warn_threshold'] !== null && $check['warn_threshold'] !== '' ? (float) $check['warn_threshold'] : null;
        $c = $check['crit_threshold'] !== null && $check['crit_threshold'] !== '' ? (float) $check['crit_threshold'] : null;
        $target = trim((string) ($check['target'] ?? ''));
        return match ((string) $check['metric']) {
            'guest' => self::checkGuest($hid, $target, $w ?? 90, $c ?? 95),
            'backups' => self::checkBackups($hid, $node, $target, $w ?? 2, $c ?? 7, $o),
            'storage' => self::checkStorage($node, $target, $w ?? 85, $c ?? 95),
            'zfs' => self::checkZfs($node, $w ?? 80, $c ?? 90),
            default => self::checkOverview($hid, $node),
        };
    }

    private static function checkOverview(int $hid, array $node): array
    {
        $guests = self::guests($hid);
        $crit = [];
        $warn = [];
        foreach ($guests as $g) {
            if ($g['template']) {
                continue;
            }
            if ($g['onboot'] && $g['status'] !== 'running') {
                $crit[] = t('{g} staat uit (start automatisch)', ['g' => self::guestLabel($g)]);
            } elseif ($g['status'] === 'running' && in_array($g['qmp'], ['paused', 'suspended', 'prelaunch'], true)) {
                $warn[] = t('{g} is {s}', ['g' => self::guestLabel($g), 's' => $g['qmp']]);
            }
            if ($g['lock'] !== '' && $g['lock'] !== 'backup') {
                $warn[] = t('{g} is vergrendeld ({l})', ['g' => self::guestLabel($g), 'l' => $g['lock']]);
            }
        }
        foreach ($node['storage'] as $s) {
            if ($s['enabled'] && !$s['active']) {
                $crit[] = t('opslag {s} is niet beschikbaar', ['s' => $s['name']]);
            }
        }
        foreach ($node['zfs'] as $z) {
            if ($z['health'] !== 'ONLINE') {
                $crit[] = "ZFS {$z['name']}: {$z['health']}";
            }
        }
        if (($node['cluster']['quorate'] ?? null) === 0) {
            $crit[] = t('cluster heeft geen quorum');
        }
        $lf = $node['last_fail'];
        if ($lf && (!$node['last_task'] || $node['last_task']['start'] <= $lf['start'])) {
            $warn[] = t('laatste back-uptaak mislukt: {s}', ['s' => $lf['status']]);
        }
        $n = $node['n'];
        $msg = preg_replace('#^pve-manager/([\d.]+).*$#', 'PVE $1', $node['version'] ?: 'Proxmox VE') . ' · '
            . t('{r} van {t} guests draaien ({vm} VM\'s, {ct} containers)', ['r' => $n['running'], 't' => $n['vm'] + $n['ct'], 'vm' => $n['vm'], 'ct' => $n['ct']]);
        $st = $crit ? STATUS_CRIT : ($warn ? STATUS_WARN : STATUS_OK);
        return [$st, (float) $n['running'], ($crit || $warn ? implode(' · ', array_merge($crit, $warn)) . ' — ' : '') . $msg];
    }

    private static function checkGuest(int $hid, string $target, float $w, float $c): array
    {
        $g = self::data($hid, 'pveg:' . (int) $target);
        if (!$g) {
            return [STATUS_UNKNOWN, null, t('Guest {id} bestaat niet (meer) op deze node; verplaatst of verwijderd?', ['id' => (int) $target])];
        }
        $label = self::guestLabel($g);
        if ($g['template']) {
            return [STATUS_OK, null, t('{g} is een sjabloon', ['g' => $label])];
        }
        if ($g['status'] !== 'running') {
            return [STATUS_CRIT, null, t('{g} staat uit ({s})', ['g' => $label, 's' => $g['status'] ?: '?'])];
        }
        $memPct = $g['maxmem'] > 0 ? round($g['mem'] / $g['maxmem'] * 100, 1) : null;
        $parts = [t('{g} draait', ['g' => $label]), 'CPU ' . ($g['cpu'] !== null ? str_replace('.', I18n::lang() === 'en' ? '.' : ',', (string) $g['cpu']) . '%' : '?')];
        if ($memPct !== null) {
            $parts[] = 'RAM ' . self::gb($g['mem']) . ' / ' . self::gb($g['maxmem']) . ' (' . (I18n::lang() === 'en' ? $memPct : str_replace('.', ',', (string) $memPct)) . '%)';
        }
        if ($g['kind'] === 'ct' && $g['maxdisk'] > 0) {
            $parts[] = 'disk ' . round($g['disk'] / $g['maxdisk'] * 100) . '%';
        }
        $parts[] = t('aan sinds {t}', ['t' => self::age($g['uptime'])]);
        $st = STATUS_OK;
        $bad = [];
        if ($memPct !== null && $memPct >= $c) {
            $st = STATUS_CRIT;
        } elseif ($memPct !== null && $memPct >= $w) {
            $st = STATUS_WARN;
        }
        if ($g['kind'] === 'vm' && $g['agent'] && $g['ga'] === 0) {
            $st = max($st, STATUS_WARN);
            $bad[] = t('guest agent antwoordt niet');
        }
        if ($g['lock'] !== '' && $g['lock'] !== 'backup') {
            $st = max($st, STATUS_WARN);
            $bad[] = t('vergrendeld ({l})', ['l' => $g['lock']]);
        }
        if (in_array($g['qmp'], ['paused', 'suspended'], true)) {
            $st = max($st, STATUS_WARN);
            $bad[] = $g['qmp'];
        }
        return [$st, $memPct, ($bad ? implode(' · ', $bad) . ' — ' : '') . implode(' · ', $parts)];
    }

    /** Afwijkende drempels per guest uit "101=7/14 105=uit" */
    public static function parsePer(string $s): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($s)) as $p) {
            if (preg_match('#^(\d+)=(uit|off)$#i', $p, $m)) {
                $out[(int) $m[1]] = null;
            } elseif (preg_match('#^(\d+)=(\d+)(?:/(\d+))?$#', $p, $m)) {
                $out[(int) $m[1]] = [(int) $m[2], isset($m[3]) ? (int) $m[3] : max((int) $m[2] * 2, (int) $m[2] + 1)];
            }
        }
        return $out;
    }

    private static function checkBackups(int $hid, array $node, string $target, float $w, float $c, array $o): array
    {
        $guests = self::guests($hid);
        if ($target !== '') {
            $guests = array_intersect_key($guests, [(int) $target => 1]);
            if (!$guests) {
                return [STATUS_UNKNOWN, null, t('Guest {id} bestaat niet (meer) op deze node', ['id' => (int) $target])];
            }
        }
        $per = self::parsePer((string) ($o['per'] ?? ''));
        $crit = [];
        $warn = [];
        $oldest = 0.0;
        $ok = 0;
        foreach ($guests as $id => $g) {
            if ($g['template'] || (array_key_exists($id, $per) && $per[$id] === null)) {
                continue;
            }
            [$gw, $gc] = $per[$id] ?? [$w, $c];
            $label = self::guestLabel($g);
            if (!$g['bk_last']) {
                $crit[] = t('{g}: nog nooit een back-up', ['g' => $label]) . ($g['job'] === 0 ? ' (' . t('zit in geen enkele back-uptaak') . ')' : '');
                continue;
            }
            $days = (time() - $g['bk_last']) / 86400;
            $oldest = max($oldest, $days);
            if ($days >= $gc) {
                $crit[] = t('{g}: laatste back-up {t} geleden', ['g' => $label, 't' => self::age((int) ($days * 86400))]);
            } elseif ($days >= $gw) {
                $warn[] = t('{g}: laatste back-up {t} geleden', ['g' => $label, 't' => self::age((int) ($days * 86400))]);
            } else {
                $ok++;
            }
            if ($g['job'] === 0) {
                $warn[] = t('{g} zit in geen enkele back-uptaak', ['g' => $label]);
            }
        }
        $lf = $node['last_fail'];
        if ($target === '' && $lf && (!$node['last_task'] || $node['last_task']['start'] <= $lf['start'])) {
            $warn[] = t('laatste back-uptaak mislukt ({t}): {s}', ['t' => date('d-m H:i', $lf['start']), 's' => $lf['status']]);
        }
        $st = $crit ? STATUS_CRIT : ($warn ? STATUS_WARN : STATUS_OK);
        $sum = t('{n} guest(s) met een recente back-up', ['n' => $ok]) . ($node['last_task'] ? ' · ' . t('laatste taak {t}', ['t' => date('d-m H:i', $node['last_task']['start'])]) : '');
        return [$st, round($oldest, 1), ($crit || $warn ? implode(' · ', array_merge($crit, $warn)) . ' — ' : '') . $sum];
    }

    private static function checkStorage(array $node, string $target, float $w, float $c): array
    {
        $list = array_values(array_filter($node['storage'], fn($s) => $s['enabled'] && ($target === '' || $s['name'] === $target)));
        if (!$list) {
            return [STATUS_UNKNOWN, null, $target !== '' ? t('Opslag {s} bestaat niet (of staat uit)', ['s' => $target]) : t('Geen opslag gemeld')];
        }
        $st = STATUS_OK;
        $bad = [];
        $parts = [];
        $max = 0.0;
        foreach ($list as $s) {
            if (!$s['active']) {
                $st = STATUS_CRIT;
                $bad[] = t('{s} niet beschikbaar', ['s' => $s['name']]);
                continue;
            }
            $pct = $s['total'] > 0 ? round($s['used'] / $s['total'] * 100, 1) : 0.0;
            $max = max($max, $pct);
            if ($pct >= $c) {
                $st = STATUS_CRIT;
                $bad[] = "{$s['name']} $pct%";
            } elseif ($pct >= $w) {
                $st = max($st, STATUS_WARN);
                $bad[] = "{$s['name']} $pct%";
            }
            $parts[] = "{$s['name']} " . round($pct) . '%';
        }
        return [$st, $max, ($bad ? implode(' · ', $bad) . ' — ' : '') . implode(' · ', $parts)];
    }

    private static function checkZfs(array $node, float $w, float $c): array
    {
        if (!$node['zfs']) {
            return [STATUS_UNKNOWN, null, t('Geen ZFS-pools op deze node')];
        }
        $st = STATUS_OK;
        $bad = [];
        $parts = [];
        $max = 0.0;
        foreach ($node['zfs'] as $z) {
            $max = max($max, (float) $z['cap']);
            if ($z['health'] !== 'ONLINE') {
                $st = STATUS_CRIT;
                $bad[] = "{$z['name']}: {$z['health']}";
            }
            if ($z['cap'] >= $c) {
                $st = STATUS_CRIT;
                $bad[] = t('{p} {n}% vol', ['p' => $z['name'], 'n' => $z['cap']]);
            } elseif ($z['cap'] >= $w) {
                $st = max($st, STATUS_WARN);
                $bad[] = t('{p} {n}% vol', ['p' => $z['name'], 'n' => $z['cap']]);
            }
            if ($z['scrub'] && $z['scrub'] < time() - 35 * 86400) {
                $st = max($st, STATUS_WARN);
                $bad[] = t('{p}: laatste scrub {t} geleden', ['p' => $z['name'], 't' => self::age(time() - $z['scrub'])]);
            }
            $parts[] = "{$z['name']} {$z['health']} {$z['cap']}%";
        }
        return [$st, $max, ($bad ? implode(' · ', $bad) . ' — ' : '') . implode(' · ', $parts)];
    }

    // ------------------------------------------------------------------ overzicht (venster)

    /** De node van deze server, en de andere nodes die als dezelfde cluster zijn aangemerkt */
    public static function overview(int $hostId): ?array
    {
        $h = q('SELECT id, name, pve_cluster FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h) {
            return null;
        }
        $cl = trim((string) ($h['pve_cluster'] ?? ''));
        $hosts = $cl !== '' ? q("SELECT h.id, h.name, h.pve_cluster FROM hosts h JOIN agent_data d ON d.host_id = h.id AND d.metric = 'pve' WHERE h.pve_cluster = ? ORDER BY h.name", [$cl])->fetchAll() : [$h];
        $nodes = [];
        foreach ($hosts as $x) {
            $node = self::data((int) $x['id'], 'pve');
            if (!$node) {
                continue;
            }
            $checks = q("SELECT id, metric, target, status FROM checks WHERE host_id = ? AND type = 'pve'", [$x['id']])->fetchAll();
            $nodes[] = ['host_id' => (int) $x['id'], 'host' => $x['name'], 'node' => $node, 'guests' => array_values(self::guests((int) $x['id'])), 'checks' => $checks];
        }
        $known = q("SELECT DISTINCT pve_cluster FROM hosts WHERE pve_cluster IS NOT NULL AND pve_cluster <> '' ORDER BY pve_cluster")->fetchAll(PDO::FETCH_COLUMN);
        return ['cluster' => $cl, 'clusters' => $known, 'nodes' => $nodes, 'now' => time()];
    }

    public static function setCluster(int $hostId, string $name, string $by): void
    {
        $name = mb_substr(trim(preg_replace('/[\x00-\x1f]/', '', $name)), 0, 64);
        q('UPDATE hosts SET pve_cluster = ? WHERE id = ?', [$name !== '' ? $name : null, $hostId]);
        AgentCommands::audit($by, 'Proxmox-cluster', "server $hostId: " . ($name !== '' ? $name : '(geen)'));
    }
}
