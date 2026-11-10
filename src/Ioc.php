<?php
declare(strict_types=1);

/**
 * Jagen op sporen (IOC's, 1.229.0): je plakt IP-adressen, domeinen, URL's, vingerafdrukken (sha256) of paden, bijvoorbeeld
 * uit een waarschuwing van het NCSC of een leverancier. RzSense zoekt ze in alles wat het al heeft:
 * - IP: IDS-incidenten, SSH-logins en -pogingen, blokkades, inlogpogingen op RzSense, aanvallen op je websites, EDR, logs;
 * - domein: IDS-incidenten, EDR, logs;
 * - sha256: programma's per server, EDR (bestanden), dreigingsinformatie;
 * - pad: programma's per server, EDR.
 * Met "ook op de servers" zoeken de agents (1.91.0) zelf naar de paden en vingerafdrukken (draaiende programma's en de
 * gebruikelijke plekken), één keer per opdracht.
 * Bewaakte sporen: elke 10 minuten vergelijkt RzSense wat er nieuw binnenkwam; een treffer = critical IDS-incident 'ioc'.
 * Aan/uit: ioc_on (Instellingen → IDS → Jagen of Overzicht → Functies).
 */
final class Ioc
{
    public const KINDS = ['ip', 'domain', 'sha', 'path'];
    private const MAX = 300;

    public static function enabled(): bool
    {
        return setting('ioc_on', '1') === '1';
    }

    /**
     * Tekst uit elkaar halen in sporen. Haalt "onschadelijk gemaakte" schrijfwijzen weg (1.2.3[.]4, hxxp://, example[.]com).
     * @return array{iocs: list<array{kind: string, value: string}>, skipped: list<string>}
     */
    public static function parse(string $text): array
    {
        $text = str_ireplace(['[.]', '(.)', '{.}', '[dot]', '(dot)', '[:]', 'hxxps://', 'hxxp://', 'fxp://'], ['.', '.', '.', '.', '.', ':', 'https://', 'http://', 'ftp://'], $text);
        $out = [];
        $skipped = [];
        foreach (preg_split('/[\s,;|"\'<>]+/u', $text) ?: [] as $w) {
            $w = trim($w, " \t\r\n()[]{}");
            if ($w === '' || str_starts_with($w, '#')) {
                continue;
            }
            $kind = null;
            $v = $w;
            if (filter_var($w, FILTER_VALIDATE_IP)) {
                $kind = 'ip';
            } elseif (preg_match('/^[0-9a-f]{64}$/i', $w)) {
                $kind = 'sha';
                $v = strtolower($w);
            } elseif (preg_match('/^[0-9a-f]{32}$|^[0-9a-f]{40}$/i', $w)) {
                $skipped[] = $w . ' (' . t('alleen sha256 wordt ondersteund') . ')';
                continue;
            } elseif (preg_match('~^[a-z][a-z0-9+.-]*://([^/:?#]+)~i', $w, $m)) {
                $v = strtolower($m[1]);
                $kind = filter_var($v, FILTER_VALIDATE_IP) ? 'ip' : 'domain';
            } elseif ($w[0] === '/' && !str_contains($w, '..') && mb_strlen($w) <= 300 && !preg_match('/[\x00-\x1F]/', $w)) {
                $kind = 'path';
            } elseif (preg_match('/^(?=.{4,253}$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i', $w)) {
                $v = rtrim(strtolower($w), '.');
                $kind = 'domain';
            }
            if ($kind === null) {
                $skipped[] = mb_substr($w, 0, 80);
                continue;
            }
            $out["$kind|$v"] = ['kind' => $kind, 'value' => $v];
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return ['iocs' => array_values($out), 'skipped' => array_slice($skipped, 0, 50)];
    }

    /** Alle sporen tegelijk zoeken in wat RzSense heeft (de laatste $days dagen) */
    public static function search(array $iocs, int $days, ?int $fromTs = null): array
    {
        $days = max(1, min(365, $days));
        $fromTs ??= time() - $days * 86400;
        $since = date('Y-m-d H:i:s', $fromTs);
        $by = array_fill_keys(self::KINDS, []);
        foreach ($iocs as $i) {
            if (isset($by[$i['kind']])) {
                $by[$i['kind']][] = (string) $i['value'];
            }
        }
        $hosts = array_column(q('SELECT id, name FROM hosts')->fetchAll(), 'name', 'id');
        $hits = [];
        $add = static function (string $kind, string $value, string $src, ?int $hostId, ?string $at, string $text) use (&$hits, $hosts): void {
            $k = "$kind|$value";
            if (count($hits[$k] ?? []) >= 50) {
                return;
            }
            $hits[$k][] = ['src' => $src, 'host_id' => $hostId, 'host' => $hostId ? (string) ($hosts[$hostId] ?? '?') : 'RzSense', 'at' => $at, 'text' => mb_substr($text, 0, 300)];
        };
        $in = static fn(array $v) => implode(',', array_fill(0, count($v), '?'));
        foreach (array_chunk($by['ip'], 100) as $ips) {
            foreach (q("SELECT host_id, ip, created_at, title FROM ids_incidents WHERE ip IN ({$in($ips)}) AND type <> 'ioc' AND last_seen >= ? ORDER BY id DESC LIMIT 500", [...$ips, $since])->fetchAll() as $r) {
                $add('ip', $r['ip'], 'ids', (int) $r['host_id'], $r['created_at'], $r['title']);
            }
            foreach (q("SELECT host_id, ip, at, ok, username FROM ssh_events WHERE ip IN ({$in($ips)}) AND at >= ? ORDER BY id DESC LIMIT 500", [...$ips, $since])->fetchAll() as $r) {
                $add('ip', $r['ip'], 'ssh', (int) $r['host_id'], $r['at'], ($r['ok'] ? t('SSH-login') : t('SSH-poging')) . ' · ' . $r['username']);
            }
            foreach (q("SELECT host_id, ip, created_at, reason FROM ids_blocks WHERE ip IN ({$in($ips)}) AND created_at >= ? ORDER BY id DESC LIMIT 200", [...$ips, $since])->fetchAll() as $r) {
                $add('ip', $r['ip'], 'block', (int) $r['host_id'], $r['created_at'], t('Geblokkeerd') . ' · ' . $r['reason']);
            }
            foreach (q("SELECT ip, created_at, ok, how FROM login_log WHERE ip IN ({$in($ips)}) AND created_at >= ? ORDER BY id DESC LIMIT 200", [...$ips, $since])->fetchAll() as $r) {
                $add('ip', $r['ip'], 'login', null, $r['created_at'], ($r['ok'] ? t('Ingelogd op RzSense') : t('Inlogpoging op RzSense')) . ' · ' . $r['how']);
            }
            foreach (q("SELECT host_id, ip, at, src, kind, n FROM weblog_attempts WHERE ip IN ({$in($ips)}) AND at >= ? ORDER BY id DESC LIMIT 200", [...$ips, $since])->fetchAll() as $r) {
                $add('ip', $r['ip'], 'web', (int) $r['host_id'], $r['at'], t('Webaanval') . " · {$r['src']} {$r['kind']} × {$r['n']}");
            }
        }
        foreach (array_chunk($by['sha'], 100) as $shas) {
            foreach (q("SELECT host_id, sha, path, last_seen, status FROM app_seen WHERE sha IN ({$in($shas)}) LIMIT 500", $shas)->fetchAll() as $r) {
                $add('sha', $r['sha'], 'apps', (int) $r['host_id'], $r['last_seen'], $r['path'] . ' · ' . self::status((string) $r['status']));
            }
            foreach (q("SELECT sha, verdict, checked_at FROM ti_hash WHERE sha IN ({$in($shas)})", $shas)->fetchAll() as $r) {
                if ($r['verdict'] === 'malware' || $r['verdict'] === 'known_good') {
                    $add('sha', $r['sha'], 'ti', null, $r['checked_at'], t('Dreigingsinformatie: {v}', ['v' => $r['verdict']]));
                }
            }
        }
        foreach (array_chunk($by['path'], 100) as $paths) {
            foreach (q("SELECT host_id, sha, path, last_seen, status FROM app_seen WHERE path IN ({$in($paths)}) LIMIT 500", $paths)->fetchAll() as $r) {
                $add('path', $r['path'], 'apps', (int) $r['host_id'], $r['last_seen'], 'sha256 ' . substr($r['sha'], 0, 16) . '… · ' . self::status((string) $r['status']));
            }
        }
        // Teksten: IDS-details (domeinen) en EDR-gebeurtenissen (alles), in één keer door de nieuwste rijen
        $words = array_values(array_unique([...$by['ip'], ...$by['domain'], ...$by['sha'], ...$by['path']]));
        $kindOf = [];
        foreach ($iocs as $i) {
            $kindOf[(string) $i['value']] = (string) $i['kind'];
        }
        if ($by['domain']) {
            $st = q('SELECT host_id, created_at, title, detail FROM ids_incidents WHERE last_seen >= ? AND type <> \'ioc\' ORDER BY id DESC LIMIT 20000', [$since]);
            while ($r = $st->fetch()) {
                $txt = mb_strtolower($r['title'] . ' ' . $r['detail']);
                foreach ($by['domain'] as $d) {
                    if (str_contains($txt, $d)) {
                        $add('domain', $d, 'ids', (int) $r['host_id'], $r['created_at'], $r['title']);
                    }
                }
            }
        }
        $t0 = microtime(true);
        $partial = false;
        if ($words) {
            $st = q('SELECT host_id, at, kind, sig, data FROM edr_events WHERE at >= ? ORDER BY id DESC LIMIT 50000', [$since]);
            while ($r = $st->fetch()) {
                if (microtime(true) - $t0 > 6) {
                    $partial = true;
                    break;
                }
                $txt = mb_strtolower($r['sig'] . ' ' . $r['data']);
                foreach ($words as $w) {
                    if (str_contains($txt, mb_strtolower($w))) {
                        $add($kindOf[$w] ?? 'domain', $w, 'edr', (int) $r['host_id'], $r['at'], $r['sig']);
                    }
                }
            }
        }
        // Logs: één zoekopdracht met alle IP's en domeinen (hooguit 8 s)
        $logWords = array_values(array_filter([...$by['ip'], ...$by['domain']], static fn($w) => mb_strlen($w) >= 4));
        $logPartial = false;
        if ($logWords && LogStore::enabled()) {
            $re = '/' . implode('|', array_map(static fn($w) => str_replace('/', '\/', preg_quote($w, '~')), array_slice($logWords, 0, 200))) . '/';
            $r = LogStore::search(['q' => $re, 'from' => $fromTs, 'limit' => 500]);
            $logPartial = !empty($r['partial']);
            foreach ((array) ($r['hits'] ?? []) as $h) {
                $low = mb_strtolower((string) $h['msg']);
                foreach ($logWords as $w) {
                    if (str_contains($low, mb_strtolower($w))) {
                        $add($kindOf[$w] ?? 'domain', $w, 'logs', (int) $h['host_id'], (string) $h['time'], $h['source'] . ': ' . $h['msg']);
                    }
                }
            }
        }
        $res = [];
        foreach ($iocs as $i) {
            $k = $i['kind'] . '|' . $i['value'];
            $list = $hits[$k] ?? [];
            usort($list, static fn($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
            $res[] = $i + ['hits' => $list, 'n' => count($list), 'ti' => $i['kind'] === 'ip' ? ThreatIntel::listed('i', $i['value']) : ($i['kind'] === 'domain' ? ThreatIntel::listed('d', $i['value']) : null)];
        }
        usort($res, static fn($a, $b) => $b['n'] <=> $a['n']);
        return ['iocs' => $res, 'hits' => array_sum(array_column($res, 'n')), 'partial' => $partial || $logPartial, 'days' => $days];
    }

    /** Een jacht: zoeken, opslaan, en eventueel de agents laten zoeken */
    public static function hunt(string $text, int $days, bool $agents, string $by): array
    {
        $p = self::parse($text);
        if (!$p['iocs']) {
            throw new InvalidArgumentException(t('Geen sporen gevonden in de tekst (IP, domein, URL, sha256 of pad).'));
        }
        $r = self::search($p['iocs'], $days);
        q('INSERT INTO ioc_hunts (created_at, username, iocs, days, hits, result) VALUES (?, ?, ?, ?, ?, ?)',
            [now(), $by, json_encode($p['iocs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $r['days'], $r['hits'],
                json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $id = (int) db()->lastInsertId();
        $asked = 0;
        $sha = array_values(array_column(array_filter($p['iocs'], static fn($i) => $i['kind'] === 'sha'), 'value'));
        $path = array_values(array_column(array_filter($p['iocs'], static fn($i) => $i['kind'] === 'path'), 'value'));
        if ($agents && ($sha || $path)) {
            $req = json_encode(['id' => $id, 'at' => time(), 'sha' => array_slice($sha, 0, 500), 'path' => array_slice($path, 0, 200)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach (q("SELECT id, agent_version FROM hosts WHERE mode = 'agent' AND enabled = 1")->fetchAll() as $h) {
                if (!empty($h['agent_version']) && version_compare((string) $h['agent_version'], AppControl::AGENT, '>=')) {
                    q('UPDATE hosts SET ioc_req = ? WHERE id = ?', [$req, $h['id']]);
                    ActionLog::by($by, (int) $h['id'], 'ioc', t('Gevraagd om naar sporen te zoeken'), t('{n} vingerafdrukken, {m} paden', ['n' => count($sha), 'm' => count($path)]));
                    $asked++;
                }
            }
            q('UPDATE ioc_hunts SET agents = ? WHERE id = ?', [json_encode(['asked' => $asked, 'done' => []]), $id]);
        }
        ActionLog::by($by, null, 'ioc', t('Gejaagd op sporen'), t('{n} sporen, {h} treffers, {d} dagen', ['n' => count($p['iocs']), 'h' => $r['hits'], 'd' => $r['days']])
            . ($asked ? ' · ' . t('{n} servers zoeken mee', ['n' => $asked]) : ''));
        return self::get($id) + ['skipped' => $p['skipped']];
    }

    public static function get(int $id): array
    {
        $h = q('SELECT * FROM ioc_hunts WHERE id = ?', [$id])->fetch();
        if (!$h) {
            throw new InvalidArgumentException(t('Jacht niet gevonden'));
        }
        $ag = json_decode((string) ($h['agents'] ?? ''), true) ?: null;
        if ($ag) {
            $names = array_column(q('SELECT id, name FROM hosts')->fetchAll(), 'name', 'id');
            foreach ($ag['done'] as $hid => &$d) {
                $d['host'] = (string) ($names[(int) $hid] ?? '?');
            }
            unset($d);
            $ag['waiting'] = (int) q('SELECT COUNT(*) FROM hosts WHERE ioc_req LIKE ?', ['{"id":' . $id . ',%'])->fetchColumn();
        }
        return ['id' => (int) $h['id'], 'at' => $h['created_at'], 'by' => $h['username'], 'days' => (int) $h['days'], 'hits' => (int) $h['hits'],
            'result' => json_decode((string) $h['result'], true) ?: [], 'agents' => $ag];
    }

    public static function hunts(): array
    {
        return array_map(static fn($h) => ['id' => (int) $h['id'], 'at' => $h['created_at'], 'by' => $h['username'], 'days' => (int) $h['days'], 'hits' => (int) $h['hits'],
            'n' => count((array) json_decode((string) $h['iocs'], true)), 'iocs' => array_slice((array) json_decode((string) $h['iocs'], true), 0, 5)],
            q('SELECT id, created_at, username, days, hits, iocs FROM ioc_hunts ORDER BY id DESC LIMIT 30')->fetchAll());
    }

    /** Regels voor de agent: een openstaande jacht (vervalt na een dag) */
    public static function agentLines(int $hostId): array
    {
        if (!self::enabled()) {
            return [];
        }
        $req = json_decode((string) q('SELECT ioc_req FROM hosts WHERE id = ?', [$hostId])->fetchColumn(), true);
        if (!is_array($req) || !isset($req['id'])) {
            return [];
        }
        if ((int) ($req['at'] ?? 0) < time() - 86400) {
            q('UPDATE hosts SET ioc_req = NULL WHERE id = ?', [$hostId]);
            return [];
        }
        $out = ["0\tiochunt\t" . (int) $req['id']];
        foreach ((array) ($req['sha'] ?? []) as $s) {
            if (preg_match('/^[0-9a-f]{64}$/', (string) $s)) {
                $out[] = "0\tiocsha\t$s";
            }
        }
        foreach ((array) ($req['path'] ?? []) as $p) {
            $out[] = "0\tiocpath\t" . base64_encode((string) $p);
        }
        return $out;
    }

    /** Uitkomst van de agent */
    public static function fromAgent(array $host, array $in): void
    {
        $r = $in['ioc'] ?? null;
        if (!is_array($r) || !isset($r['id'])) {
            return;
        }
        $id = (int) $r['id'];
        $hid = (int) $host['id'];
        $req = json_decode((string) q('SELECT ioc_req FROM hosts WHERE id = ?', [$hid])->fetchColumn(), true);
        if (is_array($req) && (int) ($req['id'] ?? 0) === $id) {
            q('UPDATE hosts SET ioc_req = NULL WHERE id = ?', [$hid]);
        }
        $hits = [];
        foreach (array_slice((array) ($r['hits'] ?? []), 0, 100) as $x) {
            if (is_array($x) && in_array($x['t'] ?? '', ['sha', 'path'], true)) {
                $hits[] = ['t' => (string) $x['t'], 'v' => mb_substr((string) ($x['v'] ?? ''), 0, 300), 'p' => mb_substr((string) ($x['p'] ?? ''), 0, 300)];
            }
        }
        $h = q('SELECT agents FROM ioc_hunts WHERE id = ?', [$id])->fetch();
        if ($h) {
            $ag = json_decode((string) $h['agents'], true) ?: ['asked' => 0, 'done' => []];
            $ag['done'][$hid] = ['files' => (int) ($r['files'] ?? 0), 'secs' => (int) ($r['secs'] ?? 0), 'hits' => $hits, 'at' => now()];
            q('UPDATE ioc_hunts SET agents = ?, hits = hits + ? WHERE id = ?', [json_encode($ag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), count($hits), $id]);
        }
        foreach ($hits as $x) {
            Ids::raise($host, 2, 'ioc', substr(sha1($x['t'] . '|' . $x['v']), 0, 16), t('Spoor gevonden op de server: {v}', ['v' => $x['t'] === 'sha' ? substr($x['v'], 0, 16) . '…' : $x['v']]),
                t('Gevonden bij het jagen op sporen: {p}', ['p' => $x['p']]) . ' · ' . t('Kijk wat dit is, hoe het hier kwam, en haal de server van het netwerk als je het niet vertrouwt.'));
        }
    }

    // ------------------------------------------------------------------ bewaakte sporen

    public static function watchList(): array
    {
        return array_map(static fn($w) => ['id' => (int) $w['id'], 'kind' => $w['kind'], 'value' => $w['value'], 'note' => $w['note'], 'by' => $w['username'],
            'at' => $w['created_at'], 'last_hit' => $w['last_hit'], 'hits' => (int) $w['hits']], q('SELECT * FROM ioc_watch ORDER BY created_at DESC LIMIT 2000')->fetchAll());
    }

    public static function watchAdd(string $text, string $note, string $by): array
    {
        $p = self::parse($text);
        $n = 0;
        foreach ($p['iocs'] as $i) {
            $n += q('INSERT IGNORE INTO ioc_watch (kind, value, note, username, created_at) VALUES (?, ?, ?, ?, ?)', [$i['kind'], $i['value'], mb_substr($note, 0, 200), $by, now()])->rowCount();
        }
        if ($n) {
            ActionLog::by($by, null, 'ioc', t('Sporen bewaken'), t('{n} sporen toegevoegd', ['n' => $n]) . ($note !== '' ? " · $note" : ''));
        }
        return ['added' => $n, 'skipped' => $p['skipped']];
    }

    public static function watchDelete(array $ids, string $by): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $vals = q('SELECT value FROM ioc_watch WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN);
        q('DELETE FROM ioc_watch WHERE id IN (' . implode(',', $ids) . ')');
        ActionLog::by($by, null, 'ioc', t('Bewaakte sporen weggehaald'), implode(', ', array_slice($vals, 0, 10)) . (count($vals) > 10 ? ' …' : ''));
        return count($vals);
    }

    /** Elke 10 minuten: wat er sinds de vorige keer binnenkwam vergelijken met de bewaakte sporen */
    public static function cron(): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $st = json_decode((string) setting('ioc_state', ''), true) ?: [];
        if ((int) ($st['at'] ?? 0) > time() - 600) {
            return null;
        }
        $from = (int) ($st['at'] ?? 0) ?: time() - 600;
        save_setting('ioc_state', json_encode(['at' => time()]));
        $watch = q('SELECT id, kind, value FROM ioc_watch')->fetchAll();
        if (!$watch) {
            return null;
        }
        $r = self::search(array_map(static fn($w) => ['kind' => $w['kind'], 'value' => $w['value']], $watch), 1, $from);
        $since = date('Y-m-d H:i:s', $from);
        $ids = array_column($watch, 'id', 'value');
        $n = 0;
        foreach ($r['iocs'] as $i) {
            $fresh = array_filter($i['hits'], static fn($h) => (string) $h['at'] >= $since && $h['src'] !== 'ti');
            if (!$fresh) {
                continue;
            }
            q('UPDATE ioc_watch SET last_hit = NOW(), hits = hits + ? WHERE id = ?', [count($fresh), $ids[$i['value']] ?? 0]);
            foreach ($fresh as $h) {
                $host = $h['host_id'] ? q('SELECT * FROM hosts WHERE id = ?', [$h['host_id']])->fetch() : null;
                if ($host) {
                    Ids::raise($host, 2, 'ioc', substr(sha1($i['kind'] . '|' . $i['value']), 0, 16), t('Bewaakt spoor gezien: {v}', ['v' => $i['value']]),
                        t('Waar: {src} · {t}', ['src' => $h['src'], 't' => $h['text']]) . ' · ' . t('Dit spoor staat op je lijst (IDS → Jagen).'), $i['kind'] === 'ip' ? $i['value'] : null);
                    $n++;
                }
            }
        }
        return $n ? "Bewaakte sporen: $n treffers" : null;
    }

    private static function status(string $s): string
    {
        return ['pkg' => t('uit een pakket'), 'learn' => t('geleerd'), 'ok' => t('toegestaan'), 'new' => t('onbekend'), 'block' => t('geblokkeerd')][$s] ?? $s;
    }

    public static function purge(): void
    {
        q('DELETE FROM ioc_hunts WHERE created_at < NOW() - INTERVAL 180 DAY');
    }
}
