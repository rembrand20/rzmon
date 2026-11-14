<?php

declare(strict_types=1);

/**
 * 1.260.0 (agent 1.101.0): Suricata op het netwerk tussen de reverse proxy en de apps. Daar loopt het verkeer onversleuteld
 * over de netwerkbrug van Podman of Docker; de agent op de proxyserver start een eigen Suricata-container die daar meekijkt.
 * Instellingen (beheerportal → Beveiliging → Reverse proxy): aan/uit, het regelprofiel (web, balanced, full), de interface
 * (auto = de bruggen van de proxycontainer) en vanaf welke ernst een melding een IDS-incident wordt. De meldingen gaan door
 * dezelfde verwerking als die van een gewone Suricata (IDS-incidenten, blokkeren bij ernst 1 als dat aan staat).
 */
final class Nids
{
    public const PROFILES = ['web', 'balanced', 'full'];

    public static function enabled(): bool
    {
        return setting('nids_on', '0') === '1' && ProxyGuard::enabled();
    }

    /** auto, of een lijst interfacenamen (hooguit 4) */
    public static function cleanIface(string $v): ?string
    {
        $v = strtolower(trim($v));
        if ($v === '' || $v === 'auto') {
            return 'auto';
        }
        $out = [];
        foreach (preg_split('/[\s,]+/', $v) ?: [] as $i) {
            if ($i === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_.-]{1,15}$/', $i)) {
                return null;
            }
            $out[$i] = $i;
        }
        return $out ? implode(',', array_slice(array_values($out), 0, 4)) : 'auto';
    }

    /** @return list<string> */
    public static function agentLines(int $hostId): array
    {
        $h = q('SELECT proxy_role, agent_version FROM hosts WHERE id = ?', [$hostId])->fetch();
        if (!$h || !version_compare((string) $h['agent_version'], '1.101.0', '>=')) {
            return [];
        }
        $st = self::status($hostId);
        if (!(int) $h['proxy_role'] || !self::enabled()) {
            return $st && ($st['state'] ?? 'off') !== 'off' ? [implode("\t", [0, 'nids', 'off', 'balanced', 'auto'])] : [];
        }
        $p = (string) setting('nids_profile', 'balanced');
        return [implode("\t", [0, 'nids', 'on', in_array($p, self::PROFILES, true) ? $p : 'balanced', self::cleanIface((string) setting('nids_iface', 'auto')) ?? 'auto'])];
    }

    public static function fromAgent(array $host, array $in): void
    {
        if (!isset($in['nids']) || !is_array($in['nids'])) {
            return;
        }
        $n = $in['nids'];
        $hid = (int) $host['id'];
        $old = self::status($hid);
        $names = static fn($l) => array_values(array_filter(array_map('strval', array_slice((array) $l, 0, 8)), static fn($x) => (bool) preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $x)));
        $state = in_array($n['state'] ?? '', ['ok', 'starting', 'lowmem', 'error', 'off', 'unsupported'], true) ? $n['state'] : 'error';
        $alerts = array_values(array_filter((array) ($n['alerts'] ?? []), 'is_array'));
        $min = max(1, min(3, (int) setting('nids_min_sev', '2')));
        $keep = array_values(array_filter($alerts, static fn($a) => count($a) >= 10 && (int) $a[9] >= 1 && (int) $a[9] <= $min));
        $st = ['state' => $state, 'msg' => mb_substr((string) ($n['msg'] ?? ''), 0, 300), 'mem' => (int) ($n['mem'] ?? 0), 'image' => mb_substr((string) ($n['image'] ?? ''), 0, 120),
            'profile' => in_array($n['profile'] ?? '', self::PROFILES, true) ? $n['profile'] : '', 'rules' => (int) ($n['rules'] ?? 0),
            'ifaces' => $names($n['ifaces'] ?? []), 'found' => $names($n['found'] ?? []), 'seen' => count($alerts), 'kept' => count($keep), 'at' => time(),
            'total' => (int) ($old['total'] ?? 0) + count($keep)];
        q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'nids', ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)",
            [$hid, json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        if (!$old || $old['state'] !== $state) {
            $what = ['ok' => t('Suricata (proxynetwerk): actief'), 'starting' => t('Suricata (proxynetwerk): wordt gestart'), 'lowmem' => t('Suricata (proxynetwerk): te weinig geheugen'),
                'error' => t('Suricata (proxynetwerk): fout'), 'off' => t('Suricata (proxynetwerk): uit'), 'unsupported' => t('Suricata (proxynetwerk): niet ondersteund')][$state];
            ActionLog::add($hid, 'agent', 'proxy', $what, trim($st['msg'] . ($st['ifaces'] ? ' · ' . implode(', ', $st['ifaces']) : '')), $state === 'error' || $state === 'lowmem' ? false : null);
        }
        if ($keep) {
            Suricata::process($host, array_slice($keep, 0, 200));
        }
    }

    public static function status(int $hostId): ?array
    {
        $v = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = 'nids'", [$hostId])->fetchColumn(), true);
        return is_array($v) ? $v : null;
    }
}
