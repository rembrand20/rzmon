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
 */
final class Incidents
{
    public const STATUSES = ['open', 'ack', 'resolved'];
    /** Zo lang critical voordat een check een incident wordt (korte pieken niet) */
    public const CHECK_MIN = 5;

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
        // IDS: critical incidenten van de laatste week
        foreach (q("SELECT i.id, i.title, i.detail, i.status, i.created_at, h.id AS host_id, h.name AS host FROM ids_incidents i JOIN hosts h ON h.id = i.host_id
                    WHERE i.severity >= 2 AND i.created_at >= NOW() - INTERVAL 7 DAY") as $x) {
            if ($x['status'] === 'open') {
                $n += self::open('ids', "ids:{$x['id']}", (int) $x['id'], (int) $x['host_id'], 2, "🛡️ {$x['host']}: {$x['title']}", (string) $x['detail'], (string) $x['created_at']);
            }
        }
        foreach (q("SELECT i.id FROM incidents i LEFT JOIN ids_incidents x ON x.id = i.ref_id WHERE i.source = 'ids' AND i.status <> 'resolved' AND (x.id IS NULL OR x.status <> 'open')") as $i) {
            $n += self::autoResolve((int) $i['id'], t('afgehandeld in IDS'));
        }
        // Kwetsbaarheden: actief misbruikt (KEV), niet geaccepteerd
        if (Vulns::enabled()) {
            $kev = [];
            foreach (q("SELECT f.host_id, f.cve, MIN(f.first_seen) AS seen, GROUP_CONCAT(DISTINCT f.package) AS pkgs, MAX(f.fixed_version) AS fix, h.name AS host
                        FROM vuln_findings f JOIN vuln_cve c ON c.cve = f.cve AND c.kev = 1 JOIN hosts h ON h.id = f.host_id
                        WHERE NOT EXISTS (SELECT 1 FROM vuln_ignore i WHERE i.cve = f.cve AND (i.package IS NULL OR i.package = f.package)
                              AND (i.host_id IS NULL OR i.host_id = f.host_id) AND (i.until IS NULL OR i.until >= CURDATE()))
                        GROUP BY f.host_id, f.cve, h.name") as $v) {
                $key = "vuln:{$v['host_id']}:{$v['cve']}";
                $kev[$key] = true;
                $n += self::open('vuln', $key, null, (int) $v['host_id'], 2, "🐞 {$v['host']}: {$v['cve']} ({$v['pkgs']})",
                    t('Actief misbruikt (CISA KEV).') . ($v['fix'] ? ' ' . t('Opgelost in {v}.', ['v' => $v['fix']]) : ' ' . t('Nog geen oplossing.')), (string) $v['seen']);
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
    private static function open(string $src, string $key, ?int $ref, int $hostId, int $sev, string $title, string $detail, string $since): int
    {
        if (q('SELECT 1 FROM incidents WHERE ref_key = ?', [$key])->fetchColumn()) {
            return 0;
        }
        q('INSERT INTO incidents (source, ref_key, ref_id, host_id, severity, title, detail, status, opened_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$src, $key, $ref, $hostId, $sev, mb_substr($title, 0, 250), mb_substr($detail, 0, 1000), 'open', $since ?: now(), now()]);
        self::note((int) db()->lastInsertId(), 'RzMon', 'system', t('Incident geopend'));
        return 1;
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
                q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'open'", [$by, $i['ref_id']]);
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
        $rows = q("SELECT i.*, h.name AS host, (SELECT COUNT(*) FROM incident_notes n WHERE n.incident_id = i.id AND n.kind = 'note') AS notes
                   FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id
                   WHERE i.status <> 'resolved' OR i.resolved_at >= NOW() - INTERVAL ? DAY
                   ORDER BY i.status = 'resolved', i.severity DESC, i.opened_at DESC LIMIT 500", [max(1, min(365, $days))])->fetchAll();
        $out = array_map(static fn($r) => [
            'id' => (int) $r['id'], 'source' => $r['source'], 'ref_id' => $r['ref_id'] !== null ? (int) $r['ref_id'] : null, 'ref_key' => $r['ref_key'],
            'host_id' => $r['host_id'] !== null ? (int) $r['host_id'] : null, 'host' => $r['host'], 'severity' => (int) $r['severity'],
            'title' => $r['title'], 'detail' => $r['detail'], 'status' => $r['status'], 'owner' => $r['owner'],
            'opened_at' => $r['opened_at'], 'resolved_at' => $r['resolved_at'], 'updated_at' => $r['updated_at'], 'notes' => (int) $r['notes'],
        ], $rows);
        $mttr = q("SELECT AVG(TIMESTAMPDIFF(SECOND, opened_at, resolved_at)) FROM incidents WHERE status = 'resolved' AND resolved_at >= NOW() - INTERVAL 30 DAY")->fetchColumn();
        return ['incidents' => $out, 'users' => q('SELECT username FROM users ORDER BY username')->fetchAll(PDO::FETCH_COLUMN),
            'counts' => [
                'open' => count(array_filter($out, fn($i) => $i['status'] === 'open')),
                'ack' => count(array_filter($out, fn($i) => $i['status'] === 'ack')),
                'resolved' => count(array_filter($out, fn($i) => $i['status'] === 'resolved')),
                'mttr' => $mttr !== null && $mttr !== false ? (int) $mttr : null,
            ]];
    }

    public static function info(int $id): ?array
    {
        $i = q('SELECT i.*, h.name AS host FROM incidents i LEFT JOIN hosts h ON h.id = i.host_id WHERE i.id = ?', [$id])->fetch();
        if (!$i) {
            return null;
        }
        $i['notes'] = q('SELECT by_user, kind, text, created_at FROM incident_notes WHERE incident_id = ? ORDER BY id', [$id])->fetchAll();
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
