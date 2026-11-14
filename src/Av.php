<?php

declare(strict_types=1);

/**
 * 1.257.0 (agent 1.99.0): uploads scannen met ClamAV op de reverse proxy. De agent op de server met de rol start ClamAV als
 * eigen container (als er genoeg geheugen is) en scant elke ronde de nieuwe bestanden in de gekozen uploadmappen, op verzoek
 * ook alles. Twee schakelaars in de beheerportal:
 *   - Melden: een vondst wordt een IDS-incident (malware) met melding;
 *   - Verplaatsen: de agent zet het bestand in een quarantaine (alleen root), terug te zetten of weg te halen vanuit RzSense.
 * Mappen kies je per site; de agent stelt ze voor uit de volumes van je apps. Alleen binnen het plafond op de server
 * (AV_DIRS_MAX), nooit systeemmappen: dat controleren RzSense én de agent.
 */
final class Av
{
    /** Systeemmappen die nooit mogen (ook als iemand ze in het plafond zet) */
    private const DENY = '#^/(etc|usr|bin|sbin|lib|lib64|boot|proc|sys|dev|run|root)(/|$)|^/var/lib/rzsense-#';

    public static function enabled(): bool
    {
        return setting('av_on', '0') === '1' && ProxyGuard::enabled();
    }

    public static function validDir(string $p): bool
    {
        return (bool) preg_match('#^/[A-Za-z0-9._@+/-]{1,250}$#', $p) && !str_contains($p, '..') && !str_contains($p, '//') && $p !== '/'
            && !preg_match(self::DENY, $p);
    }

    // ------------------------------------------------------------------ naar de agent

    /** @return list<string> */
    public static function agentLines(int $hostId): array
    {
        $role = (int) q('SELECT proxy_role FROM hosts WHERE id = ?', [$hostId])->fetchColumn() === 1;
        $st = self::status($hostId);
        if (!$role || !self::enabled()) {
            return $st && ($st['state'] ?? 'off') !== 'off' ? [implode("\t", [0, 'av', 'off', 0, 0])] : [];
        }
        $out = [implode("\t", [0, 'av', 'on', setting('av_notify', '1') === '1' ? 1 : 0, setting('av_move', '1') === '1' ? 1 : 0])];
        foreach (q('SELECT path FROM av_dirs WHERE host_id = ? ORDER BY path LIMIT 30', [$hostId])->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (self::validDir((string) $p)) {
                $out[] = implode("\t", [0, 'avdir', $p]);
            }
        }
        $full = (string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'av_full'", [$hostId])->fetchColumn();
        if ($full !== '') {
            $out[] = implode("\t", [0, 'avfull', $full]);
        }
        foreach (q("SELECT qid, op_pending FROM av_events WHERE host_id = ? AND op_pending IN ('restore', 'delete') AND qid <> '' LIMIT 50", [$hostId]) as $r) {
            $out[] = implode("\t", [0, $r['op_pending'] === 'restore' ? 'avrestore' : 'avdelete', $r['qid']]);
        }
        return $out;
    }

    // ------------------------------------------------------------------ van de agent

    public static function fromAgent(array $host, array $in): void
    {
        if (!isset($in['av']) || !is_array($in['av'])) {
            return;
        }
        $a = $in['av'];
        $hid = (int) $host['id'];
        $old = self::status($hid);
        $str = static fn($v, int $n) => mb_substr(is_scalar($v) ? (string) $v : '', 0, $n);
        $dirs = [];
        foreach (array_slice((array) ($a['dirs'] ?? []), 0, 30) as $d) {
            if (is_array($d)) {
                $dirs[] = ['p' => $str($d['p'] ?? '', 250), 'ok' => !empty($d['ok']), 'why' => $str($d['why'] ?? '', 100)];
            }
        }
        $sugg = [];
        foreach (array_slice((array) ($a['suggest'] ?? []), 0, 100) as $s) {
            if (is_array($s) && self::validDir((string) ($s['p'] ?? ''))) {
                $sugg[] = ['c' => $str($s['c'] ?? '', 80), 'p' => (string) $s['p']];
            }
        }
        $quar = [];
        foreach (array_slice((array) ($a['quarantine'] ?? []), 0, 500) as $q) {
            if (is_array($q) && preg_match('/^\d{14}-[0-9a-f]{8}$/', (string) ($q['qid'] ?? ''))) {
                $quar[] = ['qid' => (string) $q['qid'], 'p' => $str($q['p'] ?? '', 500), 'sig' => $str($q['sig'] ?? '', 120), 'size' => (int) ($q['size'] ?? 0), 'at' => (int) ($q['at'] ?? 0)];
            }
        }
        $state = in_array($a['state'] ?? '', ['ok', 'starting', 'lowmem', 'error', 'off', 'unsupported'], true) ? $a['state'] : 'error';
        $st = ['state' => $state, 'msg' => $str($a['msg'] ?? '', 300), 'mem' => (int) ($a['mem'] ?? 0), 'image' => $str($a['image'] ?? '', 120), 'sigs' => $str($a['sigs'] ?? '', 80),
            'new' => (int) ($a['new'] ?? 0), 'full' => in_array($a['full'] ?? '', ['none', 'running', 'done'], true) ? $a['full'] : 'none', 'full_id' => $str($a['full_id'] ?? '', 40),
            'dirs' => $dirs, 'suggest' => $sugg, 'quarantine' => $quar, 'at' => time()];
        q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'av', ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
            [$hid, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        if (!$old || $old['state'] !== $st['state']) {
            $what = ['ok' => t('ClamAV: actief'), 'starting' => t('ClamAV: wordt gestart'), 'lowmem' => t('ClamAV: te weinig geheugen'), 'error' => t('ClamAV: fout'),
                'off' => t('ClamAV: uit'), 'unsupported' => t('ClamAV: niet ondersteund')][$state];
            ActionLog::add($hid, 'agent', 'proxy', $what, $st['msg'], $state === 'error' || $state === 'lowmem' ? false : null);
        }
        // Volledige scan klaar: het verzoek weghalen
        if ($st['full'] === 'done' && $st['full_id'] !== '' && $st['full_id'] === (string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'av_full'", [$hid])->fetchColumn()) {
            q("DELETE FROM agent_data WHERE host_id = ? AND metric = 'av_full'", [$hid]);
        }
        foreach (array_slice((array) ($a['found'] ?? []), 0, 200) as $f) {
            if (is_array($f)) {
                self::found($host, $f);
            }
        }
        foreach (array_slice((array) ($a['ops'] ?? []), 0, 50) as $o) {
            if (!is_array($o) || !preg_match('/^\d{14}-[0-9a-f]{8}$/', (string) ($o['qid'] ?? ''))) {
                continue;
            }
            $ok = !empty($o['ok']);
            $op = ($o['op'] ?? '') === 'delete' ? 'delete' : 'restore';
            q("UPDATE av_events SET op_pending = '', op_msg = ?, status = ? WHERE host_id = ? AND qid = ?",
                [$str($o['msg'] ?? '', 200), $ok ? ($op === 'delete' ? 'deleted' : 'restored') : 'quarantined', $hid, (string) $o['qid']]);
        }
    }

    /** Eén vondst: opslaan (één keer), en als Melden aan staat een IDS-incident */
    private static function found(array $host, array $f): void
    {
        $hid = (int) $host['id'];
        $path = mb_substr((string) ($f['p'] ?? ''), 0, 500);
        $sig = mb_substr(preg_replace('/[^\w.:\/-]/u', '', (string) ($f['sig'] ?? '')) ?? '', 0, 120);
        $sha = preg_match('/^[0-9a-f]{64}$/', (string) ($f['sha'] ?? '')) ? (string) $f['sha'] : '';
        $qid = preg_match('/^\d{14}-[0-9a-f]{8}$/', (string) ($f['qid'] ?? '')) ? (string) $f['qid'] : '';
        if ($path === '' || $sig === '') {
            return;
        }
        $moved = !empty($f['moved']) && $qid !== '';
        $key = $qid !== '' ? $qid : substr(sha1($path . '|' . $sha), 0, 23);
        $ins = q('INSERT IGNORE INTO av_events (host_id, fkey, at, path, sig, sha256, size, qid, status) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)',
            [$hid, $key, $path, $sig, $sha, max(0, (int) ($f['size'] ?? 0)), $qid, $moved ? 'quarantined' : 'found']);
        if ($ins->rowCount() === 0 || setting('av_notify', '1') !== '1') {
            return;
        }
        Ids::raise($host, 2, 'malware', $sha !== '' ? substr($sha, 0, 16) : substr(sha1($path), 0, 16), t('Malware gevonden: {sig}', ['sig' => $sig]),
            $path . ' · ' . ($moved ? t('naar de quarantaine verplaatst') : t('alleen gemeld (niet verplaatst)')));
    }

    // ------------------------------------------------------------------ vanuit RzSense

    public static function addDir(int $hostId, string $path, string $site, string $by): ?string
    {
        $path = rtrim(trim($path), '/');
        if (!self::validDir($path)) {
            return t('Ongeldige map: een volledig pad zoals /srv/nextcloud/data, geen systeemmap');
        }
        if (!q('SELECT proxy_role FROM hosts WHERE id = ?', [$hostId])->fetchColumn()) {
            return t('Deze server is niet de reverse proxy');
        }
        if ((int) q('SELECT COUNT(*) FROM av_dirs WHERE host_id = ?', [$hostId])->fetchColumn() >= 30) {
            return t('Hooguit 30 mappen per server');
        }
        q('INSERT INTO av_dirs (host_id, path, site, added_by, added_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE site = VALUES(site)',
            [$hostId, $path, mb_substr($site, 0, 190), mb_substr($by, 0, 64)]);
        ActionLog::by($by, $hostId, 'proxy', t('ClamAV: map toegevoegd'), $path);
        return null;
    }

    public static function removeDir(int $hostId, string $path, string $by): void
    {
        if (q('DELETE FROM av_dirs WHERE host_id = ? AND path = ?', [$hostId, $path])->rowCount()) {
            ActionLog::by($by, $hostId, 'proxy', t('ClamAV: map weggehaald'), $path);
        }
    }

    public static function fullScan(int $hostId, string $by): void
    {
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(2));
        q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'av_full', ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", [$hostId, $id]);
        ActionLog::by($by, $hostId, 'proxy', t('ClamAV: volledige scan aangevraagd'), '');
    }

    /** Terugzetten of definitief weghalen: de agent doet het bij zijn volgende ronde. @return string|null fout */
    public static function op(int $eventId, string $op, string $by): ?string
    {
        $e = q("SELECT * FROM av_events WHERE id = ? AND status = 'quarantined' AND qid <> ''", [$eventId])->fetch();
        if (!$e || !in_array($op, ['restore', 'delete'], true)) {
            return t('Niet (meer) in de quarantaine');
        }
        q('UPDATE av_events SET op_pending = ?, op_by = ?, op_at = NOW(), op_msg = NULL WHERE id = ?', [$op, mb_substr($by, 0, 64), $eventId]);
        ActionLog::by($by, (int) $e['host_id'], 'proxy', $op === 'restore' ? t('ClamAV: terugzetten aangevraagd') : t('ClamAV: definitief weghalen aangevraagd'), (string) $e['path']);
        return null;
    }

    // ------------------------------------------------------------------ voor de pagina's

    public static function status(int $hostId): ?array
    {
        $v = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'av'", [$hostId])->fetchColumn(), true);
        return is_array($v) ? $v : null;
    }

    /** Per server: stand, mappen (met site en of de agent ze accepteert), voorstellen per site, en de vondsten */
    public static function overview(int $hostId, array $sites): array
    {
        $st = self::status($hostId) ?? [];
        $agentDirs = [];
        foreach ((array) ($st['dirs'] ?? []) as $d) {
            $agentDirs[$d['p']] = $d;
        }
        $dirs = [];
        foreach (q('SELECT path, site FROM av_dirs WHERE host_id = ? ORDER BY site, path', [$hostId]) as $r) {
            $a = $agentDirs[$r['path']] ?? null;
            $dirs[] = ['path' => $r['path'], 'site' => $r['site'], 'ok' => $a ? (bool) $a['ok'] : null, 'why' => $a['why'] ?? ''];
        }
        // Voorstellen: de mappen van de container achter een site (de app erachter heet meestal zoals de container)
        $have = array_column($dirs, 'path');
        $byCtr = [];
        foreach ($sites as $s) {
            $byCtr[ProxyAdvice::hint((string) ($s['up'] ?? ''))][] = $s['site'];
        }
        $sugg = [];
        foreach ((array) ($st['suggest'] ?? []) as $s) {
            if (!in_array($s['p'], $have, true)) {
                $sugg[] = ['ctr' => $s['c'], 'path' => $s['p'], 'site' => ($byCtr[strtolower((string) $s['c'])] ?? [''])[0]];
            }
        }
        $events = array_map(static fn($r) => ['id' => (int) $r['id'], 'at' => $r['at'], 'path' => $r['path'], 'sig' => $r['sig'], 'size' => (int) $r['size'],
            'status' => $r['status'], 'pending' => $r['op_pending'], 'msg' => $r['op_msg'], 'qid' => $r['qid']],
            q('SELECT * FROM av_events WHERE host_id = ? ORDER BY at DESC, id DESC LIMIT 50', [$hostId])->fetchAll());
        return ['state' => $st['state'] ?? null, 'msg' => $st['msg'] ?? '', 'mem' => $st['mem'] ?? null, 'sigs' => $st['sigs'] ?? '', 'new' => $st['new'] ?? 0,
            'full' => q("SELECT 1 FROM agent_data WHERE host_id = ? AND metric = 'av_full'", [$hostId])->fetchColumn() ? (($st['full'] ?? '') === 'running' ? 'running' : 'requested') : ($st['full'] ?? 'none'),
            'at' => $st['at'] ?? null, 'dirs' => $dirs, 'suggest' => array_slice($sugg, 0, 40), 'events' => $events,
            'quarantine' => count((array) ($st['quarantine'] ?? []))];
    }

    public static function purge(): void
    {
        q("DELETE FROM av_events WHERE status IN ('restored', 'deleted', 'found') AND at < NOW() - INTERVAL 180 DAY");
    }
}
