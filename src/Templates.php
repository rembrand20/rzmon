<?php
declare(strict_types=1);

/**
 * Check-sjablonen (1.94.0). Een sjabloon is een lijst checks (dezelfde kolommen als de tabel checks, als JSON).
 * Koppel je een sjabloon aan een server, dan krijgt die server de checks; een bestaande check van dezelfde soort
 * (type, doel, wat) wordt overgenomen. Pas je het sjabloon aan, dan gaan de wijzigingen mee naar alle gekoppelde
 * servers, behalve checks die je op een server zelf hebt aangepast (tpl_override); die kun je terugzetten.
 * In het doel en de naam: {adres} en {naam} van de server (ook {address} en {name}).
 */
final class Templates
{
    public const COLS = ['type', 'target', 'name', 'warn_threshold', 'crit_threshold', 'interval_sec', 'enabled', 'verify_ssl', 'metric', 'options',
        'notify', 'quiet', 'command', 'reverse_ok', 'reverse_fail', 'notify_fails', 'info_only'];

    /** Alleen de bekende kolommen, in vaste volgorde */
    private static function clean(array $cols): array
    {
        $out = [];
        foreach (self::COLS as $c) {
            $out[$c] = $cols[$c] ?? null;
        }
        $out['interval_sec'] = max(30, (int) ($out['interval_sec'] ?? 60));
        foreach (['enabled', 'verify_ssl'] as $b) {
            $out[$b] = (int) ($cols[$b] ?? 1) ? 1 : 0;
        }
        $out['info_only'] = (int) ($out['info_only'] ?? 0) ? 1 : 0;
        $out['target'] = (string) ($out['target'] ?? '');
        $out['name'] = mb_substr((string) ($out['name'] ?? ''), 0, 100);
        return $out;
    }

    /** {adres} en {naam} invullen voor deze server */
    private static function forHost(array $cols, array $host): array
    {
        $map = ['{adres}' => (string) $host['address'], '{address}' => (string) $host['address'], '{naam}' => (string) $host['name'], '{name}' => (string) $host['name']];
        $cols['target'] = strtr((string) $cols['target'], $map);
        $cols['name'] = mb_substr(strtr((string) $cols['name'], $map), 0, 100);
        return $cols;
    }

    public static function insertCheck(int $hostId, array $cols, ?int $itemId = null): int
    {
        $c = self::clean($cols);
        q('INSERT INTO checks (' . implode(', ', self::COLS) . ', host_id, template_item_id) VALUES (' . implode(', ', array_fill(0, count(self::COLS) + 2, '?')) . ')',
            [...array_values($c), $hostId, $itemId]);
        $id = (int) db()->lastInsertId();
        if ($c['type'] === 'heartbeat') {
            q('UPDATE checks SET hb_token = ? WHERE id = ? AND hb_token IS NULL', [Heartbeat::newToken(), $id]);
        }
        return $id;
    }

    /** Check bijwerken; $manual: met de hand (formulier, meerdere tegelijk) → een sjablooncheck gaat niet meer mee met het sjabloon */
    public static function updateCheck(int $id, array $cols, bool $manual, ?array $only = null): void
    {
        $c = self::clean($cols);
        if ($only !== null) {
            $c = array_intersect_key($c, array_flip($only));
        }
        $set = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($c)));
        q("UPDATE checks SET $set" . ($manual ? ', tpl_override = IF(template_item_id IS NULL, 0, 1)' : '') . ' WHERE id = ?', [...array_values($c), $id]);
    }

    /** Huidige kolommen van een check (om te kopiëren of in een sjabloon te zetten) */
    public static function colsOf(array $check): array
    {
        return self::clean(array_intersect_key($check, array_flip(self::COLS)));
    }

    // ---------------------------------------------------------------- sjablonen

    public static function list(): array
    {
        self::seed();
        $tpls = q('SELECT id, name, description, updated_at FROM check_templates ORDER BY name')->fetchAll();
        $items = [];
        foreach (q('SELECT id, template_id, data FROM template_items ORDER BY id')->fetchAll() as $r) {
            $items[(int) $r['template_id']][] = ['id' => (int) $r['id']] + (json_decode((string) $r['data'], true) ?: []);
        }
        $hosts = [];
        foreach (q('SELECT ht.template_id, h.id, h.name FROM host_templates ht JOIN hosts h ON h.id = ht.host_id ORDER BY h.name')->fetchAll() as $r) {
            $hosts[(int) $r['template_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $override = [];
        foreach (q('SELECT ti.template_id, COUNT(*) AS n FROM checks c JOIN template_items ti ON ti.id = c.template_item_id WHERE c.tpl_override = 1 GROUP BY ti.template_id')->fetchAll() as $r) {
            $override[(int) $r['template_id']] = (int) $r['n'];
        }
        return array_map(static fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'description' => $t['description'], 'updated_at' => $t['updated_at'],
            'items' => $items[(int) $t['id']] ?? [], 'hosts' => $hosts[(int) $t['id']] ?? [], 'overridden' => $override[(int) $t['id']] ?? 0], $tpls);
    }

    public static function save(?int $id, string $name, string $description): int
    {
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') {
            throw new InvalidArgumentException(t('Geef het sjabloon een naam'));
        }
        if ($id) {
            q('UPDATE check_templates SET name = ?, description = ?, updated_at = ? WHERE id = ?', [$name, mb_substr(trim($description), 0, 300), now(), $id]);
            return $id;
        }
        q('INSERT INTO check_templates (name, description, created_at) VALUES (?, ?, ?)', [$name, mb_substr(trim($description), 0, 300), now()]);
        return (int) db()->lastInsertId();
    }

    /** Sjabloon weg; de checks op de servers blijven (los), of gaan mee weg */
    public static function delete(int $id, bool $keepChecks): void
    {
        self::dropChecks('ti.template_id = ?', [$id], $keepChecks);
        q('DELETE FROM check_templates WHERE id = ?', [$id]);
    }

    private static function dropChecks(string $where, array $args, bool $keep): void
    {
        $ids = q("SELECT c.id FROM checks c JOIN template_items ti ON ti.id = c.template_item_id WHERE $where", $args)->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        q($keep ? "UPDATE checks SET template_item_id = NULL, tpl_override = 0 WHERE id IN ($in)" : "DELETE FROM checks WHERE id IN ($in)");
    }

    /** Check in een sjabloon opslaan, en meteen naar de gekoppelde servers (behalve daar aangepaste checks) */
    public static function itemSave(int $templateId, ?int $itemId, array $cols): int
    {
        $c = self::clean($cols);
        $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($itemId) {
            q('UPDATE template_items SET data = ? WHERE id = ? AND template_id = ?', [$json, $itemId, $templateId]);
        } else {
            q('INSERT INTO template_items (template_id, data) VALUES (?, ?)', [$templateId, $json]);
            $itemId = (int) db()->lastInsertId();
        }
        q('UPDATE check_templates SET updated_at = ? WHERE id = ?', [now(), $templateId]);
        foreach (q('SELECT h.* FROM host_templates ht JOIN hosts h ON h.id = ht.host_id WHERE ht.template_id = ?', [$templateId])->fetchAll() as $h) {
            self::applyItem($h, $itemId, $c);
        }
        return $itemId;
    }

    public static function itemDelete(int $itemId, bool $keepChecks): void
    {
        self::dropChecks('ti.id = ?', [$itemId], $keepChecks);
        q('DELETE FROM template_items WHERE id = ?', [$itemId]);
    }

    /** Eén sjablooncheck op één server: bijwerken, een bestaande van dezelfde soort overnemen, of aanmaken */
    private static function applyItem(array $host, int $itemId, array $cols): string
    {
        $c = self::forHost($cols, $host);
        $cur = q('SELECT id, tpl_override FROM checks WHERE host_id = ? AND template_item_id = ?', [$host['id'], $itemId])->fetch();
        if ($cur) {
            if ((int) $cur['tpl_override']) {
                return 'kept';
            }
            self::updateCheck((int) $cur['id'], $c, false);
            return 'updated';
        }
        $same = q('SELECT id FROM checks WHERE host_id = ? AND template_item_id IS NULL AND type = ? AND target = ? AND COALESCE(metric, \'\') = ? LIMIT 1',
            [$host['id'], $c['type'], $c['target'], (string) $c['metric']])->fetchColumn();
        if ($same) {
            q('UPDATE checks SET template_item_id = ?, tpl_override = 0 WHERE id = ?', [$itemId, $same]);
            self::updateCheck((int) $same, $c, false);
            return 'adopted';
        }
        self::insertCheck((int) $host['id'], $c, $itemId);
        return 'created';
    }

    /** Sjabloon aan een server koppelen. @return array{created: int, adopted: int} */
    public static function link(int $hostId, int $templateId): array
    {
        $host = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$host || !q('SELECT 1 FROM check_templates WHERE id = ?', [$templateId])->fetchColumn()) {
            throw new InvalidArgumentException(t('Server of sjabloon bestaat niet'));
        }
        q('INSERT IGNORE INTO host_templates (host_id, template_id) VALUES (?, ?)', [$hostId, $templateId]);
        $n = ['created' => 0, 'adopted' => 0];
        foreach (q('SELECT id, data FROM template_items WHERE template_id = ?', [$templateId])->fetchAll() as $it) {
            $r = self::applyItem($host, (int) $it['id'], json_decode((string) $it['data'], true) ?: []);
            if (isset($n[$r])) {
                $n[$r]++;
            }
        }
        return $n;
    }

    public static function unlink(int $hostId, int $templateId, bool $keepChecks): void
    {
        self::dropChecks('c.host_id = ? AND ti.template_id = ?', [$hostId, $templateId], $keepChecks);
        q('DELETE FROM host_templates WHERE host_id = ? AND template_id = ?', [$hostId, $templateId]);
    }

    /** Nieuw sjabloon met de checks van deze server; de server wordt meteen gekoppeld */
    public static function fromHost(int $hostId, string $name, array $checkIds = []): int
    {
        $host = q('SELECT * FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$host) {
            throw new InvalidArgumentException(t('Server bestaat niet'));
        }
        $tid = self::save(null, $name, t('Gemaakt van {name}', ['name' => $host['name']]));
        $rows = q('SELECT * FROM checks WHERE host_id = ? AND template_item_id IS NULL ORDER BY id', [$hostId])->fetchAll();
        foreach ($rows as $r) {
            if ($checkIds && !in_array((int) $r['id'], $checkIds, true)) {
                continue;
            }
            $c = self::colsOf($r);
            // Het adres van deze server wordt {adres}, zodat het op elke server werkt
            if ((string) $host['address'] !== '' && str_contains($c['target'], (string) $host['address'])) {
                $c['target'] = str_replace((string) $host['address'], '{adres}', $c['target']);
            }
            q('INSERT INTO template_items (template_id, data) VALUES (?, ?)', [$tid, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            q('UPDATE checks SET template_item_id = ?, tpl_override = 0 WHERE id = ?', [(int) db()->lastInsertId(), $r['id']]);
        }
        q('INSERT IGNORE INTO host_templates (host_id, template_id) VALUES (?, ?)', [$hostId, $tid]);
        return $tid;
    }

    /** Aangepaste check terug naar het sjabloon */
    public static function reset(int $checkId): void
    {
        $r = q('SELECT c.host_id, ti.id AS item_id, ti.data FROM checks c JOIN template_items ti ON ti.id = c.template_item_id WHERE c.id = ?', [$checkId])->fetch();
        if (!$r) {
            throw new InvalidArgumentException(t('Deze check komt niet uit een sjabloon'));
        }
        $host = q('SELECT * FROM hosts WHERE id = ?', [$r['host_id']])->fetch();
        self::updateCheck($checkId, self::forHost(json_decode((string) $r['data'], true) ?: [], $host), false);
        q('UPDATE checks SET tpl_override = 0 WHERE id = ?', [$checkId]);
    }

    /** Check kopiëren naar andere servers (los, niet uit een sjabloon). @return int aantal kopieën */
    public static function copy(int $checkId, array $hostIds): int
    {
        $src = q('SELECT c.*, h.address FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$checkId])->fetch();
        if (!$src) {
            return 0;
        }
        $n = 0;
        foreach (array_unique(array_map('intval', $hostIds)) as $hid) {
            $host = q('SELECT * FROM hosts WHERE id = ?', [$hid])->fetch();
            if (!$host || $hid === (int) $src['host_id']) {
                continue;
            }
            $c = self::colsOf($src);
            if ((string) $src['address'] !== '' && (string) $host['address'] !== '') {
                $c['target'] = str_replace((string) $src['address'], (string) $host['address'], $c['target']);
            }
            self::insertCheck($hid, $c);
            $n++;
        }
        return $n;
    }

    /** Eerste keer: een paar startsjablonen, om zelf aan te passen */
    private static function seed(): void
    {
        if (q("SELECT 1 FROM settings WHERE name = 'templates_seeded'")->fetchColumn()) {
            return;
        }
        save_setting('templates_seeded', '1');
        if (q('SELECT COUNT(*) FROM check_templates')->fetchColumn()) {
            return;
        }
        $base = ['interval_sec' => 60, 'enabled' => 1, 'verify_ssl' => 1];
        $sets = [
            [t('Linux-server (agent)'), t('CPU, RAM, disk / en uptime. Voeg zelf services toe, bv. sshd of nginx.'), [
                ['type' => 'cpu', 'name' => 'CPU', 'warn_threshold' => 85, 'crit_threshold' => 95],
                ['type' => 'ram', 'name' => 'RAM', 'warn_threshold' => 85, 'crit_threshold' => 95],
                ['type' => 'disk', 'target' => '/', 'name' => 'Disk /', 'warn_threshold' => 80, 'crit_threshold' => 90],
                ['type' => 'reboot', 'name' => 'Uptime', 'interval_sec' => 300],
            ]],
            [t('Website'), t('Bereikbaarheid en certificaat van https://{adres}/.'), [
                ['type' => 'http', 'target' => 'https://{adres}/', 'name' => 'HTTPS {naam}', 'warn_threshold' => 1000, 'crit_threshold' => 3000],
                ['type' => 'ssl', 'target' => '', 'name' => t('Certificaat {naam}'), 'warn_threshold' => 21, 'crit_threshold' => 7, 'interval_sec' => 3600],
            ]],
            [t('Netwerkapparaat'), t('Ping naar het apparaat.'), [
                ['type' => 'ping', 'name' => 'Ping', 'warn_threshold' => 100, 'crit_threshold' => 500],
            ]],
        ];
        foreach ($sets as [$name, $desc, $items]) {
            $tid = self::save(null, $name, $desc);
            foreach ($items as $it) {
                q('INSERT INTO template_items (template_id, data) VALUES (?, ?)', [$tid, json_encode(self::clean($it + $base), JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
}
