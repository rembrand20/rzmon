<?php
declare(strict_types=1);

/**
 * Incidenten (1.142.0): één lijst met wat er echt mis is, uit alle onderdelen, om af te handelen.
 * - Bronnen (elke minuut bijgewerkt door sync()):
 *   - check: een check die minstens 5 minuten critical is (één incident per storing, vanaf problem_since);
 *   - ids: een critical IDS-incident;
 *   - vuln: een actief misbruikte kwetsbaarheid (CISA KEV) die niet geaccepteerd is, per server.
 * - Status: open → in behandeling (met een eigenaar) → opgelost. Herstelt de bron zelf, dan gaat het incident vanzelf
 *   naar opgelost (met een notitie). Iemand kan het ook zelf sluiten; een IDS-incident wordt dan ook in IDS afgehandeld.
 * - Elke wijziging komt als regel in de tijdlijn (incident_notes), net als de notities van gebruikers.
 * - Samenvoegen (1.144.0): IDS-meldingen op dezelfde server binnen 6 uur, met hetzelfde IP (of een aanvalsketen), worden
 *   één incident ("brute force → login → proces"). Ook warnings (bv. brute force) komen dan als stap in de tijdlijn.
 *   De onderdelen staan in incident_refs; het incident is pas vanzelf opgelost als alle critical onderdelen afgehandeld zijn.
 * - Urgentie (1.144.0, zie Urgency): dezelfde score als bij "Nu doen" op het dashboard; daarop staat de lijst.
 */
final class Incidents
{
    public const STATUSES = ['open', 'ack', 'resolved'];
    /** Zo lang critical voordat een check een incident wordt (korte pieken niet) */
    public const CHECK_MIN = 5;
    /** Zo lang na de laatste stap hoort een nieuwe IDS-melding nog bij hetzelfde incident */
    public const GROUP_HOURS = 6;

    public static function sync(): int
    {
        $n = 0;
        // Checks: critical sinds minstens 5 minuten
        foreach (q("SELECT c.id, c.name, c.problem_since, c.last_message, h.id AS host_id, h.name AS host FROM checks c JOIN hosts h ON h.id = c.host_id
                    WHERE c.enabled = 1 AND c.info_only = 0 AND c.status = 2 AND c.problem_since IS NOT NULL AND c.problem_since <= NOW() - INTERVAL ? MINUTE
                      AND (c.maintenance_until IS NULL OR c.maintenance_until < NOW()) AND (h.maintenance_until IS NULL OR h.maintenance_until < NOW())   -- onderhoud: geen incident",
            [self::CHECK_MIN]) as $c) {
            $n += self::open('check', "check:{$c['id']}:" . strtotime((string) $c['problem_since']), (int) $c['id'], (int) $c['host_id'], 2,
                "{$c['host']} – {$c['name']}", (string) $c['last_message'], (string) $c['problem_since']);
        }
        // Weer goed (of weg): opgelost
        foreach (q("SELECT i.id, i.ref_id, i.ref_key, c.status, c.enabled, c.problem_since FROM incidents i LEFT JOIN checks c ON c.id = i.ref_id
                    WHERE i.source = 'check' AND i.status <> 'resolved'") as $i) {
            // Ook een nieuwe storing van dezelfde check (andere problem_since): de vorige is dan voorbij
            $same = $i['problem_since'] !== null && $i['ref_key'] === "check:{$i['ref_id']}:" . strtotime((string) $i['problem_since']);
            if ($i['status'] === null || (int) $i['enabled'] === 0 || (int) $i['status'] === STATUS_OK || !$same) {
                $n += self::autoResolve((int) $i['id'], $i['status'] === null || (int) $i['enabled'] === 0 ? t('check verwijderd of uitgezet') : t('check is weer OK'));
            }
        }
        // IDS van de laatste week, oudste eerst: hoort hij bij een open incident (zelfde server en IP, of een keten), dan wordt hij
        // daar een stap van (ook een warning); anders wordt een critical een nieuw incident
        foreach (q("SELECT i.id, i.type, i.ip, i.severity, i.title, i.detail, i.created_at, h.id AS host_id, h.name AS host FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                    WHERE i.severity >= 1 AND i.status = 'open' AND i.created_at >= NOW() - INTERVAL 7 DAY ORDER BY i.created_at, i.id") as $x) {
            $key = "ids:{$x['id']}";
            if (self::known($key)) {
                continue;
            }
            if ($grp = self::group($x)) {
                $n += self::attach($grp, $x, $key);
            } elseif ((int) $x['severity'] >= 2) {
                $n += self::open('ids', $key, (int) $x['id'], (int) $x['host_id'], 2, "🛡️ {$x['host']}: {$x['title']}", (string) $x['detail'], (string) $x['created_at'],
                    $x['ip'] ?: null, (string) $x['type']);
            }
        }
        // Vanzelf opgelost: het eerste IDS-incident en alle critical stappen zijn in IDS afgehandeld
        foreach (q("SELECT i.id FROM incidents i LEFT JOIN ids_incidents x ON x.id = i.ref_id WHERE i.source = 'ids' AND i.status <> 'resolved' AND (x.id IS NULL OR x.status <> 'open')
                    AND NOT EXISTS (SELECT 1 FROM incident_refs r JOIN ids_incidents y ON y.id = r.ref_id WHERE r.incident_id = i.id AND y.status = 'open' AND y.severity >= 2)") as $i) {
            $n += self::autoResolve((int) $i['id'], t('afgehandeld in IDS'));
        }
        // Kwetsbaarheden: actief misbruikt (KEV), niet geaccepteerd
        if (Vulns::enabled()) {
            $kev = [];
            foreach (q("SELECT f.host_id, f.cve, MIN(f.first_seen) AS seen, GROUP_CONCAT(DISTINCT f.package) AS pkgs, GROUP_CONCAT(DISTINCT f.source) AS bins, MAX(f.fixed_version) AS fix, h.name AS host
                        FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve AND c.kev = 1 JOIN hosts h ON h.id = f.host_id
                        WHERE NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.package IS NULL OR i.package = f.package)
                              AND (i.host_id IS NULL OR i.host_id = f.host_id) AND (i.until IS NULL OR i.until >= CURDATE()))
                        GROUP BY f.host_id, f.cve, h.name") as $v) {
                $key = "vuln:{$v['host_id']}:{$v['cve']}";
                $kev[$key] = true;
                // 1.144.0: luistert het pakket op het netwerk? Dan staat dat in de reden (dat maakt hem echt dringend)
                $ports = self::ports((int) $v['host_id'], array_merge(explode(',', (string) $v['pkgs']), array_map('trim', explode(',', (string) $v['bins']))));
                $n += self::open('vuln', $key, null, (int) $v['host_id'], 2, "🐞 {$v['host']}: {$v['cve']} ({$v['pkgs']})",
                    t('Actief misbruikt (CISA KEV).') . ($ports ? ' ' . t('Luistert op {p}: bereikbaar via het netwerk.', ['p' => implode(', ', $ports)]) : '')
                    . ($v['fix'] ? ' ' . t('Opgelost in {v}.', ['v' => $v['fix']]) : ' ' . t('Nog geen oplossing.')), (string) $v['seen']);
            }
            foreach (q("SELECT id, ref_key FROM incidents WHERE source = 'vuln' AND status <> 'resolved'") as $i) {
                if (!isset($kev[$i['ref_key']])) {
                    $n += self::autoResolve((int) $i['id'], t('niet meer gevonden (bijgewerkt of geaccepteerd)'));
                }
            }
        }
        return $n;
    }

    /** Nieuw incident (1) of bestaat al (0) */
    private static function open(string $src, string $key, ?int $ref, int $hostId, int $sev, string $title, string $detail, string $since, ?string $ip = null, ?string $kind = null): int
    {
        if (self::known($key)) {
            return 0;
        }
        q('INSERT INTO incidents (source, ref_key, ref_id, host_id, severity, title, detail, status, opened_at, updated_at, ip, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$src, $key, $ref, $hostId, $sev, mb_substr($title, 0, 250), mb_substr($detail, 0, 1000), 'open', $since ?: now(), now(), $ip, $kind]);
        self::note((int) db()->lastInsertId(), 'RzMon', 'system', t('Incident geopend'));
        return 1;
    }

    /** Bestaat al: als incident of als stap van een incident */
    private static function known(string $key): bool
    {
        return (bool) q('SELECT 1 FROM incidents WHERE ref_key = ? UNION SELECT 1 FROM incident_refs WHERE ref_key = ? LIMIT 1', [$key, $key])->fetchColumn();
    }

    /**
     * Bij welk open IDS-incident hoort deze melding? Zelfde server, laatste stap hooguit 6 uur eerder, en hetzelfde IP
     * of één van beide is een aanvalsketen. Streng gehouden: liever twee incidenten dan iets wegstoppen.
     */
    private static function group(array $x): ?int
    {
        $isChain = $x['type'] === 'chain';
        $id = q("SELECT i.id FROM incidents i WHERE i.source = 'ids' AND i.status <> 'resolved' AND i.host_id = ?
                 AND GREATEST(i.opened_at, COALESCE((SELECT MAX(r.at) FROM incident_refs r WHERE r.incident_id = i.id), i.opened_at)) >= ? - INTERVAL ? HOUR
                 AND i.opened_at <= ? + INTERVAL ? HOUR
                 AND (" . ($isChain ? '1' : '0') . " = 1 OR i.kind = 'chain' OR EXISTS (SELECT 1 FROM incident_refs r WHERE r.incident_id = i.id AND r.kind = 'chain')
                      OR (? <> '' AND (i.ip = ? OR EXISTS (SELECT 1 FROM incident_refs r WHERE r.incident_id = i.id AND r.ip = ?))))
                 ORDER BY i.opened_at LIMIT 1",
            [$x['host_id'], $x['created_at'], self::GROUP_HOURS, $x['created_at'], self::GROUP_HOURS, (string) $x['ip'], (string) $x['ip'], (string) $x['ip']])->fetchColumn();
        return $id ? (int) $id : null;
    }

    /** Een IDS-melding als stap aan een incident toevoegen; de titel wordt de keten van stappen */
    private static function attach(int $id, array $x, string $key): int
    {
        q('INSERT IGNORE INTO incident_refs (incident_id, ref_key, ref_id, kind, severity, title, ip, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $key, (int) $x['id'], (string) $x['type'], (int) $x['severity'], mb_substr((string) $x['title'], 0, 250), $x['ip'] ?: null, $x['created_at']]);
        q('UPDATE incidents SET severity = GREATEST(severity, ?), updated_at = NOW() WHERE id = ?', [min(2, (int) $x['severity']), $id]);
        self::note($id, 'RzMon', 'system', t('🔗 Hoort erbij: {t}', ['t' => $x['title']]) . ($x['ip'] ? " ({$x['ip']})" : ''));
        // Een aanvalsketen verbindt alles op die server: andere open IDS-incidenten van dezelfde periode gaan erin op
        if ($x['type'] === 'chain') {
            foreach (q("SELECT * FROM incidents WHERE source = 'ids' AND status <> 'resolved' AND host_id = ? AND id <> ?
                        AND opened_at BETWEEN ? - INTERVAL ? HOUR AND ? + INTERVAL ? HOUR", [$x['host_id'], $id, $x['created_at'], self::GROUP_HOURS, $x['created_at'], self::GROUP_HOURS]) as $o) {
                self::absorb($id, $o);
            }
        }
        // Titel: de stappen op volgorde, bv. "⛓ NAS: Brute force → SSH-login van buiten → Verdacht proces"
        $i = q('SELECT i.ref_id, i.opened_at, h.name AS host, x.title FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id LEFT JOIN ids_incidents x ON x.id = i.ref_id WHERE i.id = ?', [$id])->fetch();
        $steps = [[(string) $i['opened_at'], (string) ($i['title'] ?? '')]];
        foreach (q('SELECT at, title FROM incident_refs WHERE incident_id = ?', [$id]) as $r) {
            $steps[] = [(string) $r['at'], (string) $r['title']];
        }
        usort($steps, static fn($a, $b) => $a[0] <=> $b[0]);
        $names = array_map(static fn($s) => mb_substr(preg_replace('/^(Keten|Chain): /u', '', $s[1]), 0, 40), $steps);
        $line = implode(' → ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' → …' : '');
        q('UPDATE incidents SET title = ? WHERE id = ?', [mb_substr("⛓ {$i['host']}: $line", 0, 250), $id]);
        return 1;
    }

    /** Incident $o opnemen in incident $id: zijn eerste melding en stappen worden stappen, notities en eigenaar gaan mee */
    private static function absorb(int $id, array $o): void
    {
        $x = $o['ref_id'] ? q('SELECT id, type, severity, title, ip, created_at FROM ids_incidents WHERE id = ?', [$o['ref_id']])->fetch() : null;
        q('UPDATE incident_refs SET incident_id = ? WHERE incident_id = ?', [$id, $o['id']]);
        q('INSERT IGNORE INTO incident_refs (incident_id, ref_key, ref_id, kind, severity, title, ip, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $o['ref_key'], $o['ref_id'], (string) ($x['type'] ?? $o['kind'] ?? ''), (int) $o['severity'], mb_substr((string) ($x['title'] ?? $o['title']), 0, 250),
                $x['ip'] ?? $o['ip'], $x['created_at'] ?? $o['opened_at']]);
        q('UPDATE incident_notes SET incident_id = ? WHERE incident_id = ?', [$id, $o['id']]);
        if ($o['owner']) {
            q('UPDATE incidents SET owner = COALESCE(owner, ?) WHERE id = ?', [$o['owner'], $id]);
        }
        q('UPDATE incidents SET severity = GREATEST(severity, ?), opened_at = LEAST(opened_at, ?) WHERE id = ?', [(int) $o['severity'], $o['opened_at'], $id]);
        q('DELETE FROM incidents WHERE id = ?', [$o['id']]);
        self::note($id, 'RzMon', 'system', t('🔗 Samengevoegd: {t}', ['t' => $o['title']]));
    }

    /** Op welke poorten luisteren deze pakketten op deze server (uit de softwarelijst van de agent) */
    public static function ports(int $hostId, array $pkgs): array
    {
        $exp = json_decode((string) q('SELECT exposed FROM vuln_inventory WHERE host_id = ?', [$hostId])->fetchColumn(), true) ?: [];
        $want = array_flip(array_filter(array_map('trim', $pkgs)));
        $out = [];
        foreach ($exp as $e) {
            if (isset($e[0], $want[$e[0]])) {
                $out[] = ($e[1] ?? '?') . '/' . ($e[2] ?? 'tcp');
            }
        }
        return array_values(array_unique($out));
    }

    private static function autoResolve(int $id, string $why): int
    {
        $n = q("UPDATE incidents SET status = 'resolved', resolved_at = NOW(), updated_at = NOW() WHERE id = ? AND status <> 'resolved'", [$id])->rowCount();
        if ($n) {
            self::note($id, 'RzMon', 'status', t('Opgelost: {why}', ['why' => $why]));
        }
        return $n;
    }

    public static function note(int $id, string $by, string $kind, string $text): void
    {
        q('INSERT INTO incident_notes (incident_id, by_user, kind, text, created_at) VALUES (?, ?, ?, ?, ?)', [$id, mb_substr($by, 0, 100), $kind, mb_substr($text, 0, 2000), now()]);
    }

    /**
     * Status, eigenaar en/of een notitie wijzigen. @return string|null fout
     * Zelf op opgelost gezet: een IDS-incident wordt ook in IDS afgehandeld.
     */
    public static function update(int $id, string $by, ?string $status, ?string $owner, ?string $note): ?string
    {
        $i = q('SELECT * FROM incidents WHERE id = ?', [$id])->fetch();
        if (!$i) {
            return t('Incident niet gevonden');
        }
        if ($status !== null && $status !== $i['status']) {
            if (!in_array($status, self::STATUSES, true)) {
                return t('Onbekende status');
            }
            q('UPDATE incidents SET status = ?, resolved_at = ?, updated_at = NOW() WHERE id = ?', [$status, $status === 'resolved' ? now() : null, $id]);
            self::note($id, $by, 'status', t('Status: {s}', ['s' => self::label($status)]));
            if ($status === 'resolved' && $i['source'] === 'ids' && $i['ref_id']) {
                q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE status = 'open'
                   AND (id = ? OR id IN (SELECT ref_id FROM incident_refs WHERE incident_id = ?))", [$by, $i['ref_id'], $id]);
            }
        }
        if ($owner !== null && $owner !== (string) $i['owner']) {
            if ($owner !== '' && !q('SELECT 1 FROM users WHERE username = ?', [$owner])->fetchColumn()) {
                return t('Onbekende gebruiker');
            }
            q('UPDATE incidents SET owner = ?, updated_at = NOW() WHERE id = ?', [$owner !== '' ? $owner : null, $id]);
            self::note($id, $by, 'owner', $owner !== '' ? t('Eigenaar: {u}', ['u' => $owner]) : t('Geen eigenaar meer'));
            // Iemand pakt het op: dan is het in behandeling
            if ($owner !== '' && ($status ?? $i['status']) === 'open') {
                q("UPDATE incidents SET status = 'ack' WHERE id = ?", [$id]);
                self::note($id, $by, 'status', t('Status: {s}', ['s' => self::label('ack')]));
            }
        }
        if ($note !== null && trim($note) !== '') {
            self::note($id, $by, 'note', trim($note));
            q('UPDATE incidents SET updated_at = NOW() WHERE id = ?', [$id]);
        }
        return null;
    }

    public static function label(string $status): string
    {
        return ['open' => t('open'), 'ack' => t('in behandeling'), 'resolved' => t('opgelost')][$status] ?? $status;
    }

    /** Lijst: alles wat open is plus wat de laatste $days dagen is opgelost */
    public static function list(int $days = 30): array
    {
        $rows = q("SELECT i.*, h.name AS host, (SELECT COUNT(*) FROM incident_notes n WHERE n.incident_id = i.id AND n.kind = 'note') AS notes,
                          (SELECT COUNT(*) FROM incident_refs r WHERE r.incident_id = i.id) AS parts
                   FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id
                   WHERE i.status <> 'resolved' OR i.resolved_at >= NOW() - INTERVAL ? DAY
                   ORDER BY i.status = 'resolved', i.severity DESC, i.opened_at DESC LIMIT 500", [max(1, min(365, $days))])->fetchAll();
        $out = array_map(static fn($r) => [
            'id' => (int) $r['id'], 'source' => $r['source'], 'ref_id' => $r['ref_id'] !== null ? (int) $r['ref_id'] : null, 'ref_key' => $r['ref_key'],
            'host_id' => $r['host_id'] !== null ? (int) $r['host_id'] : null, 'host' => $r['host'], 'severity' => (int) $r['severity'],
            'title' => $r['title'], 'detail' => $r['detail'], 'status' => $r['status'], 'owner' => $r['owner'],
            'opened_at' => $r['opened_at'], 'resolved_at' => $r['resolved_at'], 'updated_at' => $r['updated_at'], 'notes' => (int) $r['notes'],
            'parts' => (int) $r['parts'] + 1,
        ] + self::urgency($r), $rows);
        // 1.144.0: wat nog loopt op urgentie (dezelfde score als "Nu doen"), daarna wat opgelost is
        usort($out, static fn($a, $b) => [$a['status'] === 'resolved', -$a['urgency']] <=> [$b['status'] === 'resolved', -$b['urgency']]);
        $mttr = q("SELECT AVG(TIMESTAMPDIFF(SECOND, opened_at, resolved_at)) FROM incidents WHERE status = 'resolved' AND resolved_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
        return ['incidents' => $out, 'users' => q('SELECT username FROM users ORDER BY username')->fetchAll(PDO::FETCH_COLUMN),
            'counts' => [
                'open' => count(array_filter($out, fn($i) => $i['status'] === 'open')),
                'ack' => count(array_filter($out, fn($i) => $i['status'] === 'ack')),
                'resolved' => count(array_filter($out, fn($i) => $i['status'] === 'resolved')),
                'mttr' => $mttr !== null && $mttr !== false ? (int) $mttr : null,
            ]];
    }

    /**
     * Urgentie van één incident: ernst, hoe lang al, bereikbaar (pakket luistert op het netwerk, of een IP van buiten),
     * hoeveel servers ervan afhangen, en of het om beveiliging gaat. @return array{urgency: int, why: list<string>, exposed: bool}
     */
    public static function urgency(array $r): array
    {
        $exposed = false;
        if ($r['source'] === 'vuln') {
            $exposed = str_contains((string) $r['detail'], '/tcp') || str_contains((string) $r['detail'], '/udp');
        } elseif ($r['source'] === 'ids') {
            $exposed = !empty($r['ip']) && !Ids::internalIp((string) $r['ip']);
        }
        $end = $r['resolved_at'] ? strtotime((string) $r['resolved_at']) : time();
        $u = Urgency::score((int) $r['severity'], max(0, intdiv($end - strtotime((string) $r['opened_at']), 60)), $exposed,
            $r['host_id'] !== null ? (Urgency::dependents()[(int) $r['host_id']] ?? 0) : 0, in_array($r['source'], ['ids', 'vuln'], true));
        return ['urgency' => $u['score'], 'why' => $u['why'], 'exposed' => $exposed];
    }

    public static function info(int $id): ?array
    {
        $i = q('SELECT i.*, h.name AS host FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id WHERE i.id = ?', [$id])->fetch();
        if (!$i) {
            return null;
        }
        $i['notes'] = q('SELECT by_user, kind, text, created_at FROM incident_notes WHERE incident_id = ? ORDER BY id', [$id])->fetchAll();
        // 1.144.0: de stappen (samengevoegde IDS-meldingen), de urgentie en het pad: van waar, via welke poort, en wat ervan afhangt
        $first = $i['source'] === 'ids' && $i['ref_id'] ? q('SELECT title, severity, ip, created_at FROM ids_incidents WHERE id = ?', [$i['ref_id']])->fetch() : null;
        $steps = $first ? [['title' => $first['title'], 'severity' => (int) $first['severity'], 'ip' => $first['ip'], 'at' => $first['created_at']]] : [];
        foreach (q('SELECT title, severity, ip, at FROM incident_refs WHERE incident_id = ? ORDER BY at', [$id]) as $r) {
            $steps[] = ['title' => $r['title'], 'severity' => (int) $r['severity'], 'ip' => $r['ip'], 'at' => $r['at']];
        }
        usort($steps, static fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $i['steps'] = count($steps) > 1 ? $steps : [];
        $i += self::urgency($i);
        $path = [];
        $ip = $i['ip'] ?: ($steps[0]['ip'] ?? null);
        if ($i['source'] === 'ids' && $ip && !Ids::internalIp((string) $ip)) {
            $path[] = t('Internet ({ip})', ['ip' => $ip]);
        } elseif ($i['source'] === 'vuln' && $i['exposed']) {
            $path[] = t('Internet / netwerk');
            if (preg_match('/(\d+\/(tcp|udp)(, \d+\/(tcp|udp))*)/', (string) $i['detail'], $m)) {
                $path[] = t('poort {p}', ['p' => $m[1]]);
            }
        }
        if ($i['host']) {
            $path[] = (string) $i['host'];
        }
        $deps = $i['host_id'] ? Urgency::dependentNames((int) $i['host_id']) : [];
        if ($deps) {
            $n = Urgency::dependents()[(int) $i['host_id']] ?? count($deps);
            $path[] = t('{n} afhankelijke server(s): {list}', ['n' => $n, 'list' => implode(', ', $deps) . ($n > count($deps) ? ', …' : '')]);
        }
        $i['path'] = count($path) > 1 ? $path : [];
        return $i;
    }

    /** Voor het rapport: incidenten in een periode (geopend), per bron, en de gemiddelde tijd tot opgelost */
    public static function period(string $from, string $to): array
    {
        $rows = q("SELECT source, severity, status, title, opened_at, resolved_at, owner, TIMESTAMPDIFF(SECOND, opened_at, COALESCE(resolved_at, NOW())) AS dur
                   FROM incidents WHERE opened_at >= ? AND opened_at < ? + INTERVAL 1 DAY ORDER BY opened_at", [$from, $to])->fetchAll();
        $by = ['check' => 0, 'ids' => 0, 'vuln' => 0];
        $res = [];
        foreach ($rows as $r) {
            $by[$r['source']] = ($by[$r['source']] ?? 0) + 1;
            if ($r['status'] === 'resolved') {
                $res[] = (int) $r['dur'];
            }
        }
        return ['total' => count($rows), 'by_source' => $by, 'open' => count(array_filter($rows, fn($r) => $r['status'] !== 'resolved')),
            'mttr' => $res ? (int) round(array_sum($res) / count($res)) : null, 'rows' => array_slice(array_reverse($rows), 0, 25)];
    }

    public static function purge(): void
    {
        q("DELETE FROM incidents WHERE status = 'resolved' AND resolved_at < NOW() - INTERVAL 400 DAY");
    }
}
