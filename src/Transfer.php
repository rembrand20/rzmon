<?php
declare(strict_types=1);

/**
 * Exporteren en importeren (1.97.0): servers, checks, sjablonen en (optioneel) instellingen als één JSON-bestand,
 * om naar een andere RzMon te kopiëren of als leesbare reserve naast de back-up. Zonder geheimen: geen wachtwoorden,
 * tokens, sleutels of SNMP-community; agents moet je op de nieuwe RzMon opnieuw koppelen.
 * Importeren voegt alleen toe: een server of sjabloon met dezelfde naam blijft zoals hij is, een check die er al is
 * (zelfde type, doel en soort) wordt overgeslagen.
 */
final class Transfer
{
    private const HOST_COLS = ['name', 'address', 'mode', 'kind', 'enabled', 'ids_fim', 'agent_tick', 'realtime', 'info_agent'];
    private const SECRET = '/(pass|token|secret|_key$|^enc_key|community|api_key|hibp_key)/i';
    /** Instellingen die RzMon zelf bijhoudt, of die bij deze installatie horen */
    private const SKIP_SETTINGS = '/^(cron_|update_|.*_state$|.*_seen$|.*_at$|.*_day$|ipintel_|hibp_(wait|last|domain_error|rpm)|vuln_(daily|last|kev|cache)|selfcheck_|db_size_hist|task_watch|'
        . 'mqtt_last|mqtt_discovery|backup_last|backup_retry|backup_fail|templates_seeded|own_cert|proxy_|hsts_|cleanup_ignore|maintenance_|agent_fb|enc_|stealth_|https_|base_url|edr_self_mark|tests_rev|.*_notified$)/';

    private static function cleanSnmp(?string $json): ?string
    {
        $s = json_decode((string) $json, true);
        if (!is_array($s)) {
            return null;
        }
        foreach (array_keys($s) as $k) {
            if (preg_match('/(community|pass|key)/i', (string) $k)) {
                unset($s[$k]);
            }
        }
        return json_encode($s, JSON_UNESCAPED_SLASHES);
    }

    public static function export(bool $withSettings): array
    {
        $hosts = [];
        $names = [];
        foreach (q('SELECT * FROM hosts ORDER BY name')->fetchAll() as $h) {
            $names[(int) $h['id']] = $h['name'];
            $hosts[] = array_intersect_key($h, array_flip(self::HOST_COLS)) + ['snmp' => self::cleanSnmp($h['snmp'] ?? null), '_parent_id' => $h['parent_id']];
        }
        foreach ($hosts as &$h) {
            $h['parent'] = $h['_parent_id'] ? ($names[(int) $h['_parent_id']] ?? null) : null;
            unset($h['_parent_id']);
        }
        unset($h);
        $checks = [];
        foreach (q('SELECT * FROM checks ORDER BY host_id, id')->fetchAll() as $c) {
            $checks[] = ['host' => $names[(int) $c['host_id']] ?? null] + Templates::colsOf($c);
        }
        $tpls = [];
        foreach (Templates::list() as $t) {
            $tpls[] = ['name' => $t['name'], 'description' => $t['description'], 'items' => array_map(static fn($i) => array_diff_key($i, ['id' => 1]), $t['items']),
                'hosts' => array_column($t['hosts'], 'name')];
        }
        $out = ['rzmon_export' => 1, 'version' => RZMON_VERSION, 'exported_at' => date('c'), 'hosts' => $hosts, 'checks' => $checks, 'templates' => $tpls];
        if ($withSettings) {
            $out['settings'] = [];
            foreach (q('SELECT name, value FROM settings ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if (!preg_match(self::SECRET, (string) $k) && !preg_match(self::SKIP_SETTINGS, (string) $k)) {
                    $out['settings'][$k] = $v;
                }
            }
        }
        return $out;
    }

    /** Wat er bij importeren gebeurt (zonder iets te veranderen), of het echt doen. @return array tellers */
    public static function import(array $d, bool $withSettings, bool $apply): array
    {
        if ((int) ($d['rzmon_export'] ?? 0) !== 1) {
            throw new InvalidArgumentException(t('Dit is geen export van RzMon'));
        }
        $n = ['hosts_new' => 0, 'hosts_existing' => 0, 'templates_new' => 0, 'templates_existing' => 0, 'checks_new' => 0, 'checks_existing' => 0, 'settings' => 0];
        $hostIds = array_column(q('SELECT id, name FROM hosts')->fetchAll(), 'id', 'name');
        $tplIds = array_column(q('SELECT id, name FROM check_templates')->fetchAll(), 'id', 'name');
        // Sjablonen (nieuwe; een bestaand sjabloon met dezelfde naam blijft zoals het is)
        foreach ((array) ($d['templates'] ?? []) as $t) {
            $name = mb_substr(trim((string) ($t['name'] ?? '')), 0, 100);
            if ($name === '') {
                continue;
            }
            if (isset($tplIds[$name])) {
                $n['templates_existing']++;
                continue;
            }
            $n['templates_new']++;
            if ($apply) {
                $tid = Templates::save(null, $name, (string) ($t['description'] ?? ''));
                $tplIds[$name] = $tid;
                foreach ((array) ($t['items'] ?? []) as $it) {
                    if (is_array($it) && isset($it['type'])) {
                        Templates::itemSave($tid, null, $it);
                    }
                }
            }
        }
        // Servers
        $newHosts = [];
        foreach ((array) ($d['hosts'] ?? []) as $h) {
            $name = mb_substr(trim((string) ($h['name'] ?? '')), 0, 100);
            if ($name === '' || !in_array($h['mode'] ?? '', ['local', 'agent', 'remote'], true)) {
                continue;
            }
            if (isset($hostIds[$name])) {
                $n['hosts_existing']++;
                continue;
            }
            $n['hosts_new']++;
            if ($apply) {
                q('INSERT INTO hosts (name, address, mode, kind, enabled, ids_fim, agent_tick, realtime, info_agent, snmp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                    $name, mb_substr((string) ($h['address'] ?? ''), 0, 255), $h['mode'], isset(HostKind::KINDS[$h['kind'] ?? '']) ? $h['kind'] : null, (int) ($h['enabled'] ?? 1) ? 1 : 0,
                    $h['ids_fim'] ?? null, isset($h['agent_tick']) ? (int) $h['agent_tick'] : null, (int) ($h['realtime'] ?? 0) ? 1 : 0, (int) ($h['info_agent'] ?? 1) ? 1 : 0, self::cleanSnmp($h['snmp'] ?? null)]);
                $hostIds[$name] = (int) db()->lastInsertId();
                $newHosts[$name] = $h['parent'] ?? null;
            }
        }
        if ($apply) {
            foreach ($newHosts as $name => $parent) {
                if ($parent && isset($hostIds[$parent])) {
                    q('UPDATE hosts SET parent_id = ? WHERE id = ?', [$hostIds[$parent], $hostIds[$name]]);
                }
            }
            // Sjablonen koppelen aan de servers (maakt de sjablooncheck of neemt een bestaande over)
            foreach ((array) ($d['templates'] ?? []) as $t) {
                foreach ((array) ($t['hosts'] ?? []) as $hn) {
                    if (isset($tplIds[$t['name'] ?? ''], $hostIds[$hn])) {
                        Templates::link((int) $hostIds[$hn], (int) $tplIds[$t['name']]);
                    }
                }
            }
        }
        // Checks die er nog niet zijn
        $have = [];
        foreach (q('SELECT host_id, type, target, COALESCE(metric, \'\') AS metric FROM checks')->fetchAll() as $c) {
            $have["{$c['host_id']}|{$c['type']}|{$c['target']}|{$c['metric']}"] = true;
        }
        $inFile = array_column((array) ($d['hosts'] ?? []), 'name');
        foreach ((array) ($d['checks'] ?? []) as $c) {
            // De server bestaat al, of komt uit dit bestand (bij een voorbeeld nog zonder id)
            $hid = is_array($c) ? ($hostIds[(string) ($c['host'] ?? '')] ?? null) : null;
            if (!is_array($c) || !isset($c['type']) || (!$hid && !in_array((string) ($c['host'] ?? ''), $inFile, true))) {
                continue;
            }
            $key = "$hid|{$c['type']}|" . ($c['target'] ?? '') . '|' . ($c['metric'] ?? '');
            if ($hid && isset($have[$key])) {
                $n['checks_existing']++;
                continue;
            }
            $n['checks_new']++;
            if ($apply && $hid) {
                Templates::insertCheck((int) $hid, $c);
                $have[$key] = true;
            }
        }
        if ($withSettings) {
            foreach ((array) ($d['settings'] ?? []) as $k => $v) {
                if (!preg_match('/^[a-z0-9_]{1,64}$/', (string) $k) || preg_match(self::SECRET, (string) $k) || preg_match(self::SKIP_SETTINGS, (string) $k) || !is_scalar($v)) {
                    continue;
                }
                $n['settings']++;
                if ($apply) {
                    save_setting((string) $k, (string) $v);
                }
            }
        }
        return $n;
    }
}
