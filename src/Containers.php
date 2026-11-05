<?php
declare(strict_types=1);

/**
 * 1.176.0: Monitoring → Containers. Eén overzicht van alle containers (Podman en Docker) die de agents melden:
 * status (running, failed, stopped, paused), image, hoe lang hij draait, CPU, geheugen, schijf, herstarts,
 * en of er een check op staat. Alles komt uit de gegevens die de agent al meestuurt (agent_data).
 */
final class Containers
{
    /** @return array<string, mixed> */
    public static function overview(): array
    {
        $hosts = [];
        $tot = ['n' => 0, 'running' => 0, 'failed' => 0, 'stopped' => 0, 'paused' => 0, 'podman' => 0, 'docker' => 0, 'pods' => 0,
            'cpu' => 0.0, 'mem' => 0.0, 'images' => 0.0, 'volumes' => 0.0, 'rw' => 0.0, 'reclaim' => 0.0, 'hosts' => 0];
        $rows = q("SELECT h.id, h.name, h.address, h.enabled, h.agent_seen, h.agent_tick, d.value, d.updated_at
            FROM agent_data d JOIN hosts h ON h.id = d.host_id WHERE d.metric = 'podman' ORDER BY h.name")->fetchAll();
        foreach ($rows as $h) {
            $sum = json_decode((string) $h['value'], true) ?: [];
            $hid = (int) $h['id'];
            $checks = [];
            foreach (q("SELECT id, target, metric, status, enabled, cstate FROM checks WHERE host_id = ? AND type = 'container'", [$hid]) as $c) {
                $t = (string) $c['target'];
                // Eén check per container is genoeg om "bewaakt" te tonen; de status-check (of gecombineerde) gaat voor
                if (!isset($checks[$t]) || in_array((string) ($c['metric'] ?? ''), ['', 'status'], true)) {
                    $checks[$t] = ['id' => (int) $c['id'], 'status' => (int) $c['status'], 'enabled' => (int) $c['enabled']];
                }
            }
            $list = [];
            // Alleen containers uit dezelfde ronde als het overzicht: verwijderde containers vallen zo weg
            foreach (q("SELECT metric, value FROM agent_data WHERE host_id = ? AND metric LIKE 'container:%' AND updated_at >= ? - INTERVAL 5 MINUTE ORDER BY metric",
                [$hid, $h['updated_at']]) as $r) {
                $c = json_decode((string) $r['value'], true);
                if (!is_array($c)) {
                    continue;
                }
                $key = substr((string) $r['metric'], 10);
                $cs = Checks::containerState($c);
                $rt = ($c['user'] ?? '') === 'docker' ? 'docker' : 'podman';
                $memPct = isset($c['mem'], $c['mem_limit']) && (float) $c['mem_limit'] > 0 ? round((float) $c['mem'] / (float) $c['mem_limit'] * 100, 1) : null;
                $list[] = [
                    'key' => $key, 'name' => (string) ($c['name'] ?? $key), 'user' => (string) ($c['user'] ?? ''), 'pod' => (string) ($c['pod'] ?? ''),
                    'runtime' => $rt, 'image' => (string) ($c['image'] ?? ''), 'state' => $cs, 'raw_state' => (string) ($c['state'] ?? ''),
                    'status_text' => (string) ($c['status'] ?? ''), 'health' => (string) ($c['health'] ?? ''), 'exit_code' => (int) ($c['exit_code'] ?? 0),
                    'restarts' => (int) ($c['restarts'] ?? 0), 'started' => !empty($c['started']) ? (int) $c['started'] : null,
                    'cpu' => isset($c['cpu']) && $cs === 'running' ? round((float) $c['cpu'], 1) : null,
                    'mem' => isset($c['mem']) && $cs === 'running' ? (float) $c['mem'] : null, 'mem_limit' => isset($c['mem_limit']) ? (float) $c['mem_limit'] : null,
                    'mem_pct' => $cs === 'running' ? $memPct : null,
                    'size_rw' => (float) ($c['size_rw'] ?? 0), 'volumes' => (float) ($c['volumes'] ?? 0),
                    'net_in' => isset($c['net_in']) ? (float) $c['net_in'] : null, 'net_out' => isset($c['net_out']) ? (float) $c['net_out'] : null,
                    'pids' => isset($c['pids']) ? (int) $c['pids'] : null,
                    'check' => $checks[$key] ?? null,
                ];
                $tot['n']++;
                $tot[$cs]++;
                $tot[$rt]++;
                if ($cs === 'running') {
                    $tot['cpu'] += (float) ($c['cpu'] ?? 0);
                    $tot['mem'] += (float) ($c['mem'] ?? 0);
                }
                $tot['rw'] += (float) ($c['size_rw'] ?? 0);
            }
            // Ergste eerst: failed, dan stopped/paused, dan running; binnen een groep op naam
            $rank = ['failed' => 0, 'paused' => 1, 'stopped' => 2, 'running' => 3];
            usort($list, static fn($a, $b) => $rank[$a['state']] <=> $rank[$b['state']] ?: strcasecmp($a['name'], $b['name']));
            $stale = $h['agent_seen'] === null || strtotime((string) $h['agent_seen']) < time() - agent_stale_seconds($h['agent_tick'] !== null ? (int) $h['agent_tick'] : null);
            $tot['images'] += (float) ($sum['images_size'] ?? 0);
            $tot['volumes'] += (float) ($sum['volumes_size'] ?? 0);
            $tot['reclaim'] += (float) ($sum['reclaimable'] ?? 0);
            $tot['pods'] += is_array($sum['pods'] ?? null) ? count($sum['pods']) : 0;
            $tot['hosts']++;
            $hosts[] = [
                'id' => $hid, 'name' => (string) $h['name'], 'address' => (string) $h['address'], 'enabled' => (int) $h['enabled'],
                'version' => (string) ($sum['version'] ?? ''), 'error' => (string) ($sum['error'] ?? ''), 'updated_at' => (string) $h['updated_at'], 'stale' => $stale,
                'images' => (int) ($sum['images'] ?? 0), 'images_size' => (float) ($sum['images_size'] ?? 0), 'volumes_size' => (float) ($sum['volumes_size'] ?? 0),
                'containers_size' => (float) ($sum['containers_size'] ?? 0), 'reclaimable' => (float) ($sum['reclaimable'] ?? 0),
                'containers' => $list,
            ];
        }
        $tot['cpu'] = round($tot['cpu'], 1);
        return ['hosts' => $hosts, 'total' => $tot, 'at' => now()];
    }
}
