<?php
declare(strict_types=1);

/**
 * Storingen bundelen over servers heen (1.107.0). Gaan er binnen het bundelvenster meerdere servers mis, dan komt er
 * één bericht, met de waarschijnlijke gemeenschappelijke oorzaak als RzMon die vindt:
 * - ze hangen (volgens Afhankelijkheden) achter dezelfde server;
 * - ze hangen (volgens UniFi) aan hetzelfde access point of dezelfde switch;
 * - alle agents zijn tegelijk stil: dan ligt het eerder aan RzMon of zijn netwerk;
 * - ze zitten in hetzelfde Proxmox-cluster.
 * De losse checks blijven gewoon zichtbaar in RzMon; Home Assistant krijgt nog steeds een bericht per check.
 * Aan/uit: Instellingen → Meldingen → Wanneer melden (notify_bundle_hosts).
 */
final class Bundle
{
    public static function on(): bool
    {
        return setting('notify_bundle_hosts', '1') === '1';
    }

    /**
     * Waarschijnlijke oorzaak voor deze meldingen (alleen de problemen tellen), of null.
     * @param array<int, array{item: array, check: array}> $items
     */
    public static function cause(array $items): ?string
    {
        $hosts = [];
        foreach ($items as $x) {
            if ((int) $x['item']['status'] !== STATUS_OK) {
                $hosts[(int) $x['check']['hid']] = $x['check'];
            }
        }
        $n = count($hosts);
        if ($n < 2) {
            return null;
        }
        $ids = implode(',', array_keys($hosts));
        $rows = q("SELECT h.id, h.name, h.address, h.parent_id, h.mode, h.agent_seen, h.pve_cluster, p.name AS parent_name
                   FROM hosts h LEFT JOIN hosts p ON p.id = h.parent_id WHERE h.id IN ($ids)")->fetchAll();

        // 1. Dezelfde server erboven (Afhankelijkheden)
        $parents = array_count_values(array_filter(array_map(static fn($r) => $r['parent_name'], $rows)));
        arsort($parents);
        if ($parents && reset($parents) >= max(2, (int) ceil($n * 0.6))) {
            return t('ze hangen allemaal achter {p}: kijk daar eerst', ['p' => array_key_first($parents)]);
        }

        // 2. Hetzelfde access point of dezelfde switch (UniFi, via de netwerkscan)
        $uplink = [];
        foreach ($rows as $r) {
            $info = json_decode((string) q('SELECT info FROM net_devices WHERE ip = ? ORDER BY last_seen DESC LIMIT 1', [$r['address']])->fetchColumn(), true) ?: [];
            $up = $info['unifi']['sw'] ?? $info['unifi']['ap'] ?? null;
            if ($up) {
                $uplink[$up] = ($uplink[$up] ?? 0) + 1;
            }
        }
        arsort($uplink);
        if ($uplink && reset($uplink) >= max(2, (int) ceil($n * 0.6))) {
            return t('ze hangen allemaal aan {d} (UniFi): ligt die eruit?', ['d' => array_key_first($uplink)]);
        }

        // 3. Alle agents tegelijk stil: dan eerder RzMon of zijn netwerk
        $stale = max(1, (int) setting('agent_stale_minutes', 5)) * 60;
        $agents = (int) q("SELECT COUNT(*) FROM hosts WHERE enabled = 1 AND mode = 'agent'")->fetchColumn();
        $silent = (int) q("SELECT COUNT(*) FROM hosts WHERE enabled = 1 AND mode = 'agent' AND (agent_seen IS NULL OR agent_seen < NOW() - INTERVAL ? SECOND)", [$stale])->fetchColumn();
        if ($agents >= 3 && $silent >= (int) ceil($agents * 0.8)) {
            return t('alle agents zijn tegelijk stil ({s} van {a}): ligt het aan RzMon zelf of aan zijn netwerkverbinding?', ['s' => $silent, 'a' => $agents]);
        }

        // 4. Hetzelfde Proxmox-cluster
        $cl = array_count_values(array_filter(array_map(static fn($r) => (string) $r['pve_cluster'], $rows)));
        arsort($cl);
        if ($cl && reset($cl) >= max(2, (int) ceil($n * 0.6))) {
            return t('ze zitten allemaal in Proxmox-cluster {c}', ['c' => array_key_first($cl)]);
        }
        return null;
    }

    /** Titel voor een bundel van meerdere servers */
    public static function title(array $items, ?string $cause): string
    {
        $rank = [STATUS_OK => 0, STATUS_UNKNOWN => 1, STATUS_WARN => 2, STATUS_CRIT => 3];
        $icon = [STATUS_OK => '✅', STATUS_WARN => '🟠', STATUS_CRIT => '🔴', STATUS_UNKNOWN => '⚪'];
        $worst = STATUS_OK;
        $hosts = [];
        foreach ($items as $x) {
            $st = (int) $x['item']['status'];
            $worst = $rank[$st] > $rank[$worst] ? $st : $worst;
            if ($st !== STATUS_OK) {
                $hosts[(int) $x['check']['hid']] = true;
            }
        }
        if ($worst === STATUS_OK) {
            return '✅ ' . t('HERSTELD') . ': ' . t('{n} servers', ['n' => count(array_unique(array_map(static fn($x) => $x['check']['hid'], $items)))]);
        }
        return $icon[$worst] . ' ' . STATUS_LABELS[$worst] . ': ' . t('{n} servers tegelijk', ['n' => count($hosts)]) . ($cause ? ' · ' . t('waarschijnlijk: {c}', ['c' => $cause]) : '');
    }
}
