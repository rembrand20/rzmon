<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: sjablonen, wijzigingslogboek, export en import, logs, instellingen.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var callable(string): void $confirm
 * @var callable(array<string, mixed>, bool=): array{array<string, mixed>, bool} $checkCols
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------------------------------------------------------------- Sjablonen en meerdere checks tegelijk (1.94.0)
    case 'templates':
        out(['templates' => Templates::list(), 'hosts' => q('SELECT id, name, address, mode FROM hosts ORDER BY name')->fetchAll()]);

    case 'template_save':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon opgeslagen') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        try {
            out(['ok' => true, 'id' => Templates::save((int) ($in['id'] ?? 0) ?: null, (string) ($in['name'] ?? ''), (string) ($in['description'] ?? ''))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'template_delete':
        $in = input();
        if (empty($in['keep'])) {
            $confirm('VERWIJDEREN');   // de checks op de servers gaan mee weg
        }
        $tplName = (string) q('SELECT name FROM check_templates WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn();
        Templates::delete((int) ($in['id'] ?? 0), !empty($in['keep']));
        ChangeLog::record('template', (int) ($in['id'] ?? 0) ?: null, 'info', t('Sjabloon verwijderd') . ": $tplName", null, ['keep' => !empty($in['keep'])]);
        out(['ok' => true]);

    case 'template_item_save':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Check in sjabloon opgeslagen') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        $tid = (int) ($in['template_id'] ?? 0);
        if (!q('SELECT 1 FROM check_templates WHERE id = ?', [$tid])->fetchColumn()) {
            out(['error' => t('Sjabloon bestaat niet')], 404);
        }
        [$cols] = $checkCols(['host_id' => 0, 'id' => 0] + $in);
        out(['ok' => true, 'id' => Templates::itemSave($tid, (int) ($in['item_id'] ?? 0) ?: null, $cols)]);

    case 'template_item_delete':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Check uit sjabloon gehaald') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        Templates::itemDelete((int) ($in['id'] ?? 0), !empty($in['keep']));
        out(['ok' => true]);

    case 'template_link':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon gekoppeld') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        try {
            out(['ok' => true] + Templates::link((int) ($in['host_id'] ?? 0), (int) ($in['template_id'] ?? 0)));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'template_unlink':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon losgekoppeld') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        Templates::unlink((int) ($in['host_id'] ?? 0), (int) ($in['template_id'] ?? 0), !empty($in['keep']));
        out(['ok' => true]);

    case 'template_from_host':
        $in = input();
        ChangeLog::record('template', (int) ($in['template_id'] ?? $in['id'] ?? 0) ?: null, 'info', t('Sjabloon gemaakt van een server') . (isset($in['name']) ? ': ' . mb_substr((string) $in['name'], 0, 100) : ''), null, array_intersect_key($in, array_flip(['id', 'template_id', 'item_id', 'host_id', 'name', 'keep', 'type', 'target'])));
        try {
            out(['ok' => true, 'id' => Templates::fromHost((int) ($in['host_id'] ?? 0), (string) ($in['name'] ?? ''), array_map('intval', (array) ($in['check_ids'] ?? [])))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'check_tpl_reset':
        try {
            Templates::reset((int) (input()['id'] ?? 0));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        out(['ok' => true]);

    case 'discover':   // 1.95.0: wat er op deze server nog niet bewaakt wordt
        $host = q('SELECT * FROM hosts WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$host) {
            out(['error' => t('Server bestaat niet')], 404);
        }
        $ign = (array) (json_decode((string) $host['discover_ignore'], true) ?: []);
        out(['items' => Discover::forHost($host), 'ignored' => count($ign)]);

    case 'discover_ignore':
        $in = input();
        Discover::ignore((int) ($in['host_id'] ?? 0), (array) ($in['keys'] ?? []), empty($in['undo']));
        out(['ok' => true]);

    // 1.98.0: certificaatbestanden en processen die de agent op de server vond (voor de keuzelijst in het checkformulier)
    case 'agent_certs':
    case 'agent_procs':
        $hid = (int) ($_GET['id'] ?? 0);
        $host = q('SELECT id, mode, agent_version FROM hosts WHERE id = ?', [$hid])->fetch();
        if (!$host) {
            out(['error' => t('Server bestaat niet')], 404);
        }
        $metric = $action === 'agent_certs' ? 'certfound' : 'proclist';
        $row = q('SELECT value, updated_at FROM agent_data WHERE host_id = ? AND metric = ?', [$hid, $metric])->fetch();
        $items = $row ? (array) (json_decode((string) $row['value'], true) ?: []) : [];
        if ($action === 'agent_certs') {
            $have = array_flip(q("SELECT target FROM checks WHERE host_id = ? AND type = 'ssl'", [$hid])->fetchAll(PDO::FETCH_COLUMN));
            foreach ($items as &$it) {
                $it['monitored'] = isset($have[$it['path'] ?? '']);
            }
            unset($it);
        } elseif ($host['mode'] === 'local') {
            $items = Checks::localProcList();
        }
        out(['items' => $items, 'at' => $row['updated_at'] ?? null, 'mode' => $host['mode'], 'agent_version' => $host['agent_version'],
            'pending' => $action === 'agent_certs' ? (int) q('SELECT certscan_req FROM hosts WHERE id = ?', [$hid])->fetchColumn() : 0]);

    case 'certscan':   // 1.98.0: de agent zoekt bij zijn volgende rapport opnieuw naar certificaatbestanden
        $hid = (int) (input()['host_id'] ?? 0);
        q('UPDATE hosts SET certscan_req = certscan_req + 1 WHERE id = ?', [$hid]);
        out(['ok' => true]);

    // ---------------------------------------------------------------- Wijzigingslogboek, export/import, meldketen (1.97.0)
    case 'changelog':
        out(['items' => ChangeLog::list(preg_replace('/[^a-z]/', '', (string) ($_GET['kind'] ?? ''))), 'days' => (int) setting('changelog_days', '90')]);

    case 'changelog_days':
        $d = (int) (input()['days'] ?? 90);
        save_setting('changelog_days', (string) (in_array($d, [30, 90, 365, 1095], true) ? $d : 90));
        out(['ok' => true]);

    case 'changelog_undo':
        try {
            out(['ok' => true, 'message' => ChangeLog::undo((int) (input()['id'] ?? 0))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'export':
        $data = Transfer::export(($_GET['settings'] ?? '') === '1');
        ChangeLog::record('system', null, 'info', t('Geëxporteerd'), null, ['servers' => count($data['hosts']), 'checks' => count($data['checks'])]);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rzmon-export-' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;

    case 'import':
        $in = input();
        $apply = !empty($in['apply']);
        if ($apply) {
            $confirm('IMPORTEREN');
        }
        try {
            $n = Transfer::import((array) ($in['data'] ?? []), !empty($in['settings']), $apply);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        if ($apply) {
            ChangeLog::record('system', null, 'info', t('Geïmporteerd'), null, $n);
        }
        out(['ok' => true, 'counts' => $n, 'applied' => $apply]);

    case 'chain_test':
        // Eén testmelding door alle kanalen die aan staan, met de opgeslagen instellingen
        session_write_close();
        $res = [];
        $title = '🧪 RzSense: ' . t('test van de meldketen');
        $body = t('Als je dit ziet, werkt dit kanaal. Verstuurd op {time}.', ['time' => date('d-m-Y H:i:s')]);
        $res['pushover'] = setting('pushover_enabled', '0') === '1' ? Pushover::send($title, $body, 0) : null;
        $res['ntfy'] = setting('ntfy_enabled', '0') === '1' ? Ntfy::send($title, $body, 0) : null;
        $res['email'] = setting('email_enabled', '0') === '1' ? Mailer::send($title, $body) : null;
        if (setting('ha_webhook_enabled', '0') === '1') {
            $fake = ['id' => 0, 'hid' => 0, 'host_name' => 'RzSense', 'name' => t('Testmelding'), 'type' => 'ping', 'target' => ''];
            $res['webhook'] = Webhook::send(Monitor::webhookPayload($fake, ['value' => null], STATUS_OK, $title, $body, 'test', STATUS_OK));
        } else {
            $res['webhook'] = null;
        }
        if (setting('mqtt_enabled', '0') === '1') {
            $r = Mqtt::publishAll(null, false);
            $res['mqtt'] = ['ok' => $r['ok'], 'error' => $r['error'] ?? null];
        } else {
            $res['mqtt'] = null;
        }
        q('INSERT INTO alerts (check_id, status, title, message, sent_ok, kind, created_at) VALUES (NULL, 0, ?, ?, 1, ?, ?)', [$title, $body, 'info', now()]);
        $res['page'] = ['ok' => true];
        out(['channels' => array_map(static fn($r) => $r === null ? null : ['ok' => (bool) ($r['ok'] ?? false), 'error' => (string) ($r['error'] ?? '')], $res)]);

    case 'cleanup':   // 1.96.0: opschoonadvies
        out(['items' => Cleanup::advice()]);

    case 'cleanup_ignore':
        $in = input();
        Cleanup::ignore((int) ($in['check_id'] ?? 0), preg_replace('/[^a-z]/', '', (string) ($in['kind'] ?? '')));
        out(['ok' => true]);

    case 'checks_all':
        out(['checks' => q('SELECT c.id, c.host_id, h.name AS host_name, c.type, c.target, c.name, c.metric, c.warn_threshold, c.crit_threshold, c.interval_sec, c.enabled,
                c.status, c.notify IS NOT NULL AS own_notify, c.notify_fails, c.info_only, c.maintenance_until, c.last_checked, c.template_item_id, c.tpl_override, t.name AS template_name
            FROM checks c JOIN hosts h ON h.id = c.host_id LEFT JOIN template_items ti ON ti.id = c.template_item_id LEFT JOIN check_templates t ON t.id = ti.template_id
            ORDER BY h.name, c.name')->fetchAll(), 'hosts' => q('SELECT id, name, address, mode FROM hosts ORDER BY name')->fetchAll()]);

    case 'checks_bulk':
        // Meerdere checks tegelijk: set (velden), maintenance, delete of copy (naar servers)
        $in = input();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
        if (!$ids || count($ids) > 2000) {
            out(['error' => t('Kies een of meer checks')], 422);
        }
        $list = implode(',', $ids);
        $act = (string) ($in['action'] ?? '');
        // Wijzigingslogboek: elke check apart, zodat je ook één ervan kunt terugzetten
        $bulkBefore = [];
        foreach ($ids as $bid) {
            if ($r = ChangeLog::checkRow($bid)) {
                $bulkBefore[$bid] = $r;
            }
        }
        $bulkLog = static function (string $what) use ($bulkBefore): void {
            foreach ($bulkBefore as $bid => $r) {
                $after = $what === 'delete' ? null : ChangeLog::checkRow($bid);
                ChangeLog::record('check', $bid, $what === 'delete' ? 'delete' : 'update', $r['_host'] . ' · ' . $r['name'] . ' (' . t('meerdere tegelijk') . ')', $r, $after);
            }
        };
        if ($act === 'delete') {
            $confirm('VERWIJDEREN');
            $bulkLog('delete');
            $n = q("DELETE FROM checks WHERE id IN ($list)")->rowCount();
            out(['ok' => true, 'n' => $n]);
        }
        if ($act === 'maintenance') {
            $minutes = max(0, min(60 * 24 * 90, (int) ($in['minutes'] ?? 0)));
            $until = $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
            $n = q("UPDATE checks SET maintenance_until = ?, maintenance_reason = ?, maintenance_auto = 0 WHERE id IN ($list)",
                [$until, mb_substr(trim((string) ($in['reason'] ?? '')), 0, 200) ?: null])->rowCount();
            $bulkLog('update');
            out(['ok' => true, 'n' => $n]);
        }
        if ($act === 'copy') {
            $n = 0;
            foreach ($ids as $id) {
                $n += Templates::copy($id, (array) ($in['host_ids'] ?? []));
            }
            out(['ok' => true, 'n' => $n]);
        }
        if ($act !== 'set') {
            out(['error' => t('Onbekende actie')], 422);
        }
        $v = (array) ($in['values'] ?? []);
        $set = [];
        if (isset($v['interval_sec'])) {
            $set['interval_sec'] = max(30, min(86400, (int) $v['interval_sec']));
        }
        foreach (['enabled', 'info_only'] as $b) {
            if (isset($v[$b])) {
                $set[$b] = $v[$b] ? 1 : 0;
            }
        }
        if (array_key_exists('notify_fails', $v)) {
            $set['notify_fails'] = $v['notify_fails'] === null || $v['notify_fails'] === '' ? null : max(1, min(100, (int) $v['notify_fails']));
        }
        if (!empty($v['notify_default'])) {
            $set['notify'] = null;   // terug naar de algemene meldregels
        }
        if (array_key_exists('warn_threshold', $v) || array_key_exists('crit_threshold', $v)) {
            // Drempels alleen voor checks van één soort (anders betekenen de getallen iets anders)
            $types = q("SELECT DISTINCT CONCAT(type, '|', COALESCE(metric, '')) FROM checks WHERE id IN ($list)")->fetchAll(PDO::FETCH_COLUMN);
            if (count($types) !== 1) {
                out(['error' => t('Drempels kun je alleen voor checks van hetzelfde type tegelijk aanpassen')], 422);
            }
            $w = num($v['warn_threshold'] ?? null);
            $c = num($v['crit_threshold'] ?? null);
            $low = str_starts_with($types[0], 'ssl|') || str_starts_with($types[0], 'domain|') || str_starts_with($types[0], 'ups|');
            if ($w !== null && $c !== null && ($low ? $w < $c : $w > $c)) {
                out(['error' => $low ? (str_starts_with($types[0], 'ssl|') ? t('Bij een certificaat moet de warning-drempel (dagen) hoger zijn dan de critical-drempel') : t('Hier is lager slechter: de warning-drempel moet hoger zijn dan de critical-drempel')) : t('Warning-drempel moet lager zijn dan critical-drempel')], 422);
            }
            $set['warn_threshold'] = $w;
            $set['crit_threshold'] = $c;
        }
        // 1.107.0: drempelmodus en gevoeligheid (checks die niet kunnen leren, blijven handmatig)
        $smartN = null;
        if (isset($v['thresh_mode']) || array_key_exists('smart_sens', $v)) {
            $smartN = 0;
            foreach ($ids as $bid) {
                $c = q('SELECT * FROM checks WHERE id = ?', [$bid])->fetch();
                if ($c && Smart::eligible($c)) {
                    Smart::saveForm($bid, array_intersect_key($v, array_flip(['thresh_mode', 'smart_sens'])));
                    $smartN++;
                }
            }
        }
        if (!$set && $smartN === null) {
            out(['error' => t('Niets om aan te passen')], 422);
        }
        if (!$set) {
            $bulkLog('update');
            out(['ok' => true, 'n' => $smartN]);
        }
        $cols = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($set)));
        // Met de hand aangepast: sjabloonchecks gaan daarna niet meer mee met het sjabloon
        q("UPDATE checks SET $cols, tpl_override = IF(template_item_id IS NULL, 0, 1) WHERE id IN ($list)", array_values($set));
        $bulkLog('update');
        out(['ok' => true, 'n' => count($ids)]);

    case 'check_try':   // 1.95.0: proberen vóór opslaan, er wordt niets bewaard
        $in = input();
        [$cols] = $checkCols(['id' => (int) ($in['check_id'] ?? 0)] + $in, true);
        try {
            $r = Monitor::tryCheck((int) ($in['host_id'] ?? 0), (int) ($in['check_id'] ?? 0), $cols);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        out(['status' => (int) $r['status'], 'value' => $r['value'], 'message' => (string) $r['message']]);

    case 'check_delete':
        $id = (int) (input()['id'] ?? 0);
        if ($row = ChangeLog::checkRow($id)) {
            ChangeLog::record('check', $id, 'delete', $row['_host'] . ' · ' . $row['name'], $row, null);
        }
        q('DELETE FROM checks WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'check_run':
        $res = Monitor::runCheckById((int) (input()['id'] ?? 0));
        out(['ok' => $res !== null, 'result' => $res]);

    case 'run_all':
        out(['ok' => true, 'count' => Monitor::runDue(true)]);

    case 'settings':
        $s = settings();
        // Wachtwoorden en tokens nooit terugsturen, alleen of ze ingevuld zijn
        foreach (SECRET_SETTINGS as $k) {
            $s[$k] = ($s[$k] ?? '') !== '' ? '••••••••' : '';
        }
        unset($s['ack_secret'], $s[SnmpMonitor::GLOBAL_KEY], $s['path_unifi_cache'], $s['mqtt_topics'], $s['mqtt_hash'], $s['stats_cache'], $s['update_cache']);
        $s['alerts_days'] = (string) Monitor::alertDays();
        $s['db_growth'] = Retention::growth();   // 1.136.0: grootte van de database en groei per dag (geen formulierveld)
        $s['sysinfo'] = json_encode(NotifyRules::sysinfo());
        out($s);

    case 'settings_save':
        $in = input();
        if (isset($in['sysinfo'])) {
            // Systeeminfo: alleen bekende meldingen en kanalen bewaren
            $in['sysinfo'] = json_encode(NotifyRules::cleanSysinfo((array) json_decode((string) $in['sysinfo'], true), NotifyRules::sysinfo()));
        }
        $allowed = ['sysinfo', 'pushover_enabled', 'pushover_user', 'pushover_token', 'pushover_priority_crit', 'pushover_priority_warn',
            'pushover_sound', 'ntfy_enabled', 'ntfy_server', 'ntfy_topic', 'ntfy_token', 'alert_after', 'repeat_minutes', 'notify_warn', 'notify_recovery', 'agent_stale_minutes', 'agent_tick', 'cert_dirs', 'retention_days', 'alerts_days', 'hourly_days', 'daily_days', 'auth_events', 'auth_ignore', 'ids_baseline_hours', 'ids_fail_count', 'ids_fail_minutes', 'ids_block_hours', 'ids_repeat_days', 'ids_fim', 'ids_fim_exclude', 'ids_decoy_users', 'ids_decoy_port', 'ids_block_shared', 'ids_weekly', 'ids_weekly_day', 'ids_weekly_time', 'ids_blocklists', 'ids_geo', 'ids_geo_allowed', 'ids_webshell', 'ids_webshell_kill', 'ids_persist', 'ids_outbound', 'ids_outbound_country', 'ids_outbound_block', 'ids_harden', 'weblog_swag', 'weblog_swag_path', 'weblog_ha', 'weblog_ha_path', 'weblog_vw', 'weblog_vw_path', 'weblog_block', 'wl_paths', 'wl_ua', 'wl_404', 'wl_404_n', 'wl_auth', 'wl_auth_n', 'wl_nohost', 'wl_nohost_n', 'wl_ha_n', 'wl_vw_n', 'wl_minutes',
            'ha_webhook_enabled', 'ha_webhook_url', 'ha_webhook_verify_ssl', 'ha_webhook_events', 'notify_recovery_acked',
            'email_enabled', 'email_mode', 'smtp_host', 'smtp_port', 'smtp_security', 'smtp_user', 'smtp_pass', 'smtp_verify_ssl', 'mail_from', 'mail_to',
            'mqtt_enabled', 'mqtt_host', 'mqtt_port', 'mqtt_tls', 'mqtt_verify_ssl', 'mqtt_user', 'mqtt_pass', 'mqtt_prefix', 'mqtt_base_topic',
            'disk_forecast_days', 'auto_maint_enabled', 'auto_maint_after', 'log_window', 'agent_auto_update',
            'backup_freq', 'backup_time', 'backup_weekday', 'backup_keep', 'backup_keep_manual', 'backup_history', 'backup_pass',
            'notify_rules', 'notify_channels', 'notify_unknown', 'notify_group_seconds', 'notify_bundle_hosts', 'notify_fails', 'notify_flap', 'notify_quiet', 'notify_lang',
            'vuln_time', 'vuln_min_severity', 'vuln_exclude', 'net_scan', 'net_sweep_minutes', 'net_deep_per_day', 'report_monthly', 'thresh_default', 'smart_sens', 'esc_on', 'esc_warn_min', 'esc_crit_min', 'esc_crit_prio', 'esc_quiet', 'smart_interval', 'smart_fast', 'smart_slow', 'anomaly_on', 'disk_jump_on', 'disk_jump_pct', 'context_on', 'hb_learn', 'edr_on', 'edr_kill', 'edr_block', 'edr_isolate', 'edr_sigma', 'edr_web', 'edr_anom', 'edr_learn_days', 'edr_anom_min', 'edr_chain', 'edr_chain_min', 'edr_chain_act', 'ti_on', 'ti_abuseipdb_key', 'ti_greynoise', 'ti_greynoise_key', 'ti_shodan', 'ti_circl', 'ti_tor', 'ti_tor_block', 'ti_urlhaus', 'ti_feodo', 'ti_abusech_key', 'ti_block', 'ti_block_score', 'ti_escalate', 'ti_close_benign', 'ti_report', 'ti_cve', 'dns_on', 'suricata_on', 'suricata_block', 'edr_yara', 'ids_modern', 'af_motion', 'smart_auto', 'alert_auto', 'inet_down', 'inet_up', 'inet_warn_pct', 'inet_crit_pct', 'inet_times', 'inet_speed_mb', 'perf_on', 'edr_note_hide', 'unifi_url', 'unifi_site', 'unifi_user', 'unifi_pass', 'unifi_key', 'unifi_verify_ssl', 'adguard_urls', 'adguard_user', 'adguard_pass', 'adguard_verify_ssl',
            'update_auto', 'update_time', 'update_backup', 'update_hold_min', 'confirm_skip_admin', 'confirm_skip_user', 'confirm_skip_readonly', 'confirm_grace_min',
            'ai_on', 'ai_provider', 'ai_auto', 'ai_daily', 'ai_mask', 'ai_timeout', 'ai_max_tokens', 'ai_ollama_url', 'ai_ollama_key',
            'cve_buzz_on', 'cve_buzz_alert', 'cve_github_token', 'cve_mastodon_url', 'cve_mastodon_token', 'cve_exploitdb', 'cve_nuclei',   // 1.179.0
            'ai_claude_key', 'ai_claude_model', 'ai_openai_key', 'ai_openai_model', 'ai_gemini_key', 'ai_gemini_model', 'ai_grok_key', 'ai_grok_model', 'ai_ollama_model',   // 1.171.0
            'ai_budget_month', 'ai_memory', 'ai_cache', 'ai_cache_hours', 'ai_fallback', 'ai_notify', 'ai_weekly', 'ai_weekly_day', 'ai_triage', 'ai_triage_max', 'ai_report',   // 1.173.0
            'confirm_fails', 'confirm_ok', 'confirm_types', 'confirm_fast',   // 1.185.0, 1.193.0
            'net_traffic', 'net_traffic_swap', 'net_wan_cap_gb',   // 1.186.0, 1.187.0
            'logs_on', 'logs_interval', 'logs_days', 'logs_max_gb', 'logs_day_mb', 'logs_mask', 'logs_mask_extra',
            'trend_alert_days', 'trend_ups_min', 'mailsec_on', 'eol_on', 'ransom_on', 'ransom_min', 'appctl_on', 'appctl_learn', 'ioc_on', 'patch_on', 'sudo_on', 'sudo_max', 'dnsbl_on', 'dnsbl_ti', 'dnsbl_ioc', 'dnsbl_own', 'dnsbl_allow', 'dns_nrd', 'dns_nrd_days', 'nis2_on', 'nis2_org', 'nis2_csirt'];   // 1.227.0, 1.228.0   // 1.224.0   // 1.210.0
        // 1.179.0: Mastodon alleen via https naar een gewone hostnaam
        if (isset($in['cve_mastodon_url']) && trim((string) $in['cve_mastodon_url']) !== '' && !CveBuzz::safeUrl(rtrim(trim((string) $in['cve_mastodon_url']), '/'))) {
            out(['error' => t('Mastodon: vul een https-adres in, bv. https://infosec.exchange')], 422);
        }
        // 1.171.0: AI-advies: een bekende aanbieder, een geldig adres voor Ollama, getallen binnen de grenzen
        if (isset($in['ai_provider']) && !isset(Ai::PROVIDERS[(string) $in['ai_provider']])) {
            out(['error' => t('Onbekende aanbieder')], 422);
        }
        if (isset($in['ai_ollama_url']) && (trim((string) $in['ai_ollama_url']) === '' || ExtraChecks::urlBlocked(trim((string) $in['ai_ollama_url'])) !== null)) {
            out(['error' => t('Vul het adres van Ollama in, bv. http://127.0.0.1:11434')], 422);
        }
        if (isset($in['ai_budget_month'])) {   // 1.173.0: maandbudget in euro (0 = geen)
            $in['ai_budget_month'] = (string) round(max(0, min(100000, (float) str_replace(',', '.', (string) $in['ai_budget_month']))), 2);
        }
        foreach (['trend_alert_days' => [0, 365], 'trend_ups_min' => [1, 240], 'ransom_min' => [20, 100000], 'appctl_learn' => [0, 90], 'sudo_max' => [5, 240], 'dns_nrd_days' => [1, 365]] as $k => [$lo, $hi]) {   // 1.224.0, 1.228.0
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        foreach (['ai_daily' => [0, 10000], 'ai_timeout' => [10, 600], 'ai_max_tokens' => [200, 8000], 'ai_cache_hours' => [1, 72], 'ai_weekly_day' => [1, 7]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        foreach (['claude', 'openai', 'gemini', 'grok', 'ollama'] as $p) {
            if (isset($in["ai_{$p}_model"]) && !preg_match('/^[A-Za-z0-9._:\/-]{0,80}$/', trim((string) $in["ai_{$p}_model"]))) {
                out(['error' => t('Ongeldige modelnaam')], 422);
            }
        }
        // 1.210.0: logs: getallen binnen de grenzen, eigen maskeerpatronen moeten geldige reguliere expressies zijn
        foreach (['logs_interval' => [1, 60], 'logs_days' => [1, 365], 'logs_day_mb' => [1, 100000]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        if (isset($in['logs_max_gb'])) {
            $in['logs_max_gb'] = (string) round(max(0.1, min(10000, (float) str_replace(',', '.', (string) $in['logs_max_gb']))), 1);
        }
        if (isset($in['logs_mask_extra'])) {
            foreach (preg_split('/\R/', (string) $in['logs_mask_extra']) ?: [] as $p) {
                if (trim($p) !== '' && @preg_match('~' . str_replace('~', '\~', trim($p)) . '~iu', '') === false) {
                    out(['error' => t('Ongeldig patroon om te maskeren: {p}', ['p' => mb_substr(trim($p), 0, 80)])], 422);
                }
            }
        }
        if (isset($in['confirm_grace_min'])) {
            $in['confirm_grace_min'] = (string) max(1, min(1440, (int) $in['confirm_grace_min']));   // 1.206.0
        }
        if (isset($in['update_hold_min'])) {
            $in['update_hold_min'] = (string) max(5, min(60, (int) $in['update_hold_min']));   // 1.205.0
        }
        if (isset($in['update_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['update_time'])) {
            out(['error' => t('Tijdstip voor automatisch bijwerken: gebruik uu:mm, bv. 04:00')], 422);
        }
        if (isset($in['notify_quiet'])) {
            $in['notify_quiet'] = json_encode(NotifyRules::cleanQuiet((array) (is_string($in['notify_quiet']) ? json_decode($in['notify_quiet'], true) : $in['notify_quiet'])));
        }
        if (isset($in['notify_flap'])) {
            $in['notify_flap'] = json_encode(NotifyRules::cleanFlap((array) (is_string($in['notify_flap']) ? json_decode($in['notify_flap'], true) : $in['notify_flap'])));
        }
        // Meldregels en kanalen per status: alleen geldige waarden opslaan
        if (isset($in['notify_rules'])) {
            $in['notify_rules'] = json_encode(array_replace(NotifyRules::defaults()['rules'],
                NotifyRules::cleanRules((array) (is_string($in['notify_rules']) ? json_decode($in['notify_rules'], true) : $in['notify_rules']))));
        }
        if (isset($in['notify_channels'])) {
            $in['notify_channels'] = json_encode(array_replace(NotifyRules::defaults()['channels'],
                NotifyRules::cleanChannels((array) (is_string($in['notify_channels']) ? json_decode($in['notify_channels'], true) : $in['notify_channels']))));
        }
        foreach (['esc_warn_min' => [0, 1440], 'esc_crit_min' => [0, 1440], 'esc_crit_prio' => [-1, 2], 'smart_fast' => [10, 300], 'smart_slow' => [1, 10], 'disk_jump_pct' => [1, 50], 'edr_learn_days' => [1, 90], 'edr_anom_min' => [1, 100], 'edr_chain_min' => [5, 1440], 'ti_block_score' => [10, 100],
            'inet_down' => [1, 100000], 'inet_up' => [1, 100000], 'inet_warn_pct' => [1, 100], 'inet_crit_pct' => [1, 100], 'inet_speed_mb' => [10, 2000]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        if (isset($in['inet_times'])) {
            $in['inet_times'] = Internet::cleanTimes((string) $in['inet_times']);   // 1.157.0: tijden van de snelheidstest
        }
        if (isset($in['thresh_default']) && !in_array($in['thresh_default'], Smart::MODES, true)) {
            $in['thresh_default'] = 'guard';
        }
        if (isset($in['smart_sens']) && !in_array($in['smart_sens'], Smart::SENS, true)) {
            $in['smart_sens'] = 'normal';
        }
        if (isset($in['auth_ignore'])) {
            $in['auth_ignore'] = implode(' ', array_slice(parse_list(mb_substr((string) $in['auth_ignore'], 0, 2000)), 0, 100));
        }
        foreach (['net_sweep_minutes' => [15, 1440], 'net_deep_per_day' => [1, 24], 'ids_baseline_hours' => [0, 720], 'ids_fail_count' => [2, 1000], 'ids_fail_minutes' => [1, 1440], 'ids_block_hours' => [1, 8760], 'ids_repeat_days' => [1, 365]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        if (isset($in['agent_tick'])) {
            $in['agent_tick'] = (string) (in_array((int) $in['agent_tick'], AGENT_TICKS, true) ? (int) $in['agent_tick'] : 60);
        }
        if (isset($in['ids_fim'])) {
            $in['ids_fim'] = json_encode(Ids::cleanFim(is_array($in['ids_fim']) ? $in['ids_fim'] : json_decode((string) $in['ids_fim'], true)), JSON_UNESCAPED_SLASHES);
        }
        if (isset($in['ids_fim_exclude'])) {
            $in['ids_fim_exclude'] = Ids::cleanFimExclude((string) $in['ids_fim_exclude']);
        }
        // Eigen mappen waarin de agent naar certificaatbestanden zoekt (1.98.0): absolute paden zonder spaties, hooguit 20
        if (isset($in['cert_dirs'])) {
            $in['cert_dirs'] = implode(' ', array_slice(array_values(array_unique(array_filter(preg_split('/[\s,]+/', mb_substr((string) $in['cert_dirs'], 0, 4000)),
                static fn($d) => (bool) preg_match('#^/[A-Za-z0-9._@+/-]{1,200}$#', $d) && !str_contains($d, '..')))), 0, 20));
        }
        // IDS (1.81.0): honeypot-gebruikers (namen), honeypot-poort (leeg = uit), wekelijks overzicht
        if (isset($in['ids_decoy_users'])) {
            $in['ids_decoy_users'] = implode(' ', array_slice(array_filter(parse_list(mb_strtolower(mb_substr((string) $in['ids_decoy_users'], 0, 2000))),
                fn($x) => (bool) preg_match('/^[a-z0-9._-]{1,32}$/', $x)), 0, 100));
        }
        if (isset($in['ids_decoy_port'])) {
            $dp = trim((string) $in['ids_decoy_port']);
            $in['ids_decoy_port'] = $dp === '' || (int) $dp < 1 || (int) $dp > 65535 ? '' : (string) (int) $dp;
        }
        if (isset($in['ids_geo_allowed'])) {
            $in['ids_geo_allowed'] = implode(' ', array_slice(array_filter(array_map('strtoupper', parse_list((string) $in['ids_geo_allowed'])),
                fn($c) => (bool) preg_match('/^[A-Z]{2}$/', $c)), 0, 60)) ?: 'NL';
        }
        if (isset($in['ids_weekly'])) {
            $in['ids_weekly'] = in_array($in['ids_weekly'], ['off', 'email', 'pushover', 'both'], true) ? $in['ids_weekly'] : 'email';
        }
        if (isset($in['ids_weekly_day'])) {
            $in['ids_weekly_day'] = (string) max(1, min(7, (int) $in['ids_weekly_day']));
        }
        if (isset($in['ids_weekly_time'])) {
            $in['ids_weekly_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['ids_weekly_time']) ? (string) $in['ids_weekly_time'] : '08:00';
        }
        // Logs van webdiensten (1.89.0): aantallen binnen grenzen, paden zonder spaties (leeg = zelf zoeken)
        foreach (['wl_404_n' => [3, 10000], 'wl_auth_n' => [3, 10000], 'wl_nohost_n' => [1, 10000], 'wl_ha_n' => [2, 1000], 'wl_vw_n' => [2, 1000], 'wl_minutes' => [1, 1440]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        foreach (['weblog_swag_path', 'weblog_ha_path', 'weblog_vw_path'] as $k) {
            if (isset($in[$k])) {
                $v = trim((string) $in[$k]);
                $in[$k] = $v === '' || preg_match('#^[A-Za-z0-9_./:@-]{1,250}$#', $v) ? ($v ?: 'auto') : 'auto';
            }
        }
        // 1.136.0: bewaartermijnen: ruwe historie minstens 1 dag, uurgemiddelden 7–3650, daggegevens 30–3650
        foreach (['retention_days' => [1, 3650], 'hourly_days' => [7, 3650], 'daily_days' => [30, 3650]] as $k => [$lo, $hi]) {
            if (isset($in[$k])) {
                $in[$k] = (string) max($lo, min($hi, (int) $in[$k]));
            }
        }
        if (isset($in['alerts_days'])) {
            $in['alerts_days'] = (string) max(1, min(3650, (int) $in['alerts_days']));
        }
        if (isset($in['notify_fails'])) {
            $in['notify_fails'] = (string) max(1, min(100, (int) $in['notify_fails']));
        }
        if (isset($in['confirm_types'])) {   // 1.193.0: per soort check
            $in['confirm_types'] = json_encode(Monitor::cleanConfirmTypes(json_decode((string) $in['confirm_types'], true)), JSON_FORCE_OBJECT);
        }
        if (isset($in['confirm_fast'])) {
            $in['confirm_fast'] = $in['confirm_fast'] === '0' ? '0' : '1';
        }
        foreach (['confirm_fails', 'confirm_ok'] as $k) {   // 1.185.0: leeg = meteen
            if (isset($in[$k])) {
                $in[$k] = trim((string) $in[$k]) === '' ? '' : (string) max(1, min(100, (int) $in[$k]));
            }
        }
        if (isset($in['vuln_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['vuln_time'])) {
            out(['error' => t('Scantijd voor kwetsbaarheden: gebruik uu:mm, bv. 03:30')], 422);
        }
        if (isset($in['vuln_min_severity'])) {
            $in['vuln_min_severity'] = (string) max(0, min(4, (int) $in['vuln_min_severity']));
        }
        if (isset($in['vuln_exclude'])) {
            $in['vuln_exclude'] = implode(',', array_unique(array_filter(array_map('intval', is_array($in['vuln_exclude']) ? $in['vuln_exclude'] : explode(',', (string) $in['vuln_exclude'])))));
        }
        if (isset($in['notify_lang'])) {
            $in['notify_lang'] = in_array($in['notify_lang'], I18n::LANGS, true) ? $in['notify_lang'] : 'nl';
        }
        if (isset($in['notify_group_seconds'])) {
            $in['notify_group_seconds'] = (string) max(0, min(3600, (int) $in['notify_group_seconds']));
        }
        if (($in['ha_webhook_url'] ?? '') !== '' && !preg_match('#^https?://\S+$#i', trim((string) $in['ha_webhook_url']))) {
            out(['error' => t('Webhook-URL moet met http:// of https:// beginnen')], 422);
        }
        // Velden die samen moeten kloppen: er komt (bij meteen opslaan) alleen mee wat veranderd is, dus voor de rest
        // de opgeslagen waarde gebruiken. Anders lukt bv. "e-mail aan" nooit, ook al staan server en ontvanger er al.
        $eff = static fn(string $k): string => trim((string) (array_key_exists($k, $in) ? $in[$k] : setting($k, '')));
        if ($eff('ha_webhook_enabled') === '1' && $eff('ha_webhook_url') === '') {
            out(['error' => t('Vul een webhook-URL in of zet de webhook uit')], 422);
        }
        if ($eff('email_enabled') === '1' && ($eff('smtp_host') === '' || $eff('mail_to') === '')) {
            out(['error' => t('Vul de SMTP-server en een ontvanger in, of zet e-mail uit')], 422);
        }
        if ($eff('mqtt_enabled') === '1' && $eff('mqtt_host') === '') {
            out(['error' => t('Vul de MQTT-server in, of zet MQTT uit')], 422);
        }
        foreach (['mqtt_prefix', 'mqtt_base_topic'] as $k) {
            if (isset($in[$k]) && !preg_match('#^[A-Za-z0-9_\-/]{1,64}$#', trim((string) $in[$k]))) {
                out(['error' => t('Een MQTT-topic mag alleen letters, cijfers, _ - en / bevatten')], 422);
            }
        }
        if (isset($in['backup_freq']) && !in_array($in['backup_freq'], ['off', 'daily', 'weekly', 'monthly'], true)) {
            out(['error' => t('Ongeldige back-upfrequentie')], 422);
        }
        if (isset($in['backup_time']) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in['backup_time'])) {
            out(['error' => t('Vul de back-uptijd in als uu:mm, bv. 03:15')], 422);
        }
        if (isset($in['backup_keep']) && ((int) $in['backup_keep'] < 1 || (int) $in['backup_keep'] > 365)) {
            out(['error' => t('Aantal back-ups bewaren: 1 tot 365')], 422);
        }
        if (isset($in['backup_keep_manual']) && (!preg_match('/^\d+$/', trim((string) $in['backup_keep_manual'])) || (int) $in['backup_keep_manual'] > 365)) {
            out(['error' => t('Handmatige back-ups bewaren: 0 (alles) tot 365')], 422);
        }
        if (isset($in['backup_weekday']) && ((int) $in['backup_weekday'] < 1 || (int) $in['backup_weekday'] > 7)) {
            out(['error' => t('Ongeldige dag voor de back-up')], 422);
        }
        if (($in['backup_pass'] ?? '') !== '' && $in['backup_pass'] !== '••••••••' && mb_strlen((string) $in['backup_pass']) < 8) {
            out(['error' => t('Het back-upwachtwoord moet minstens 8 tekens hebben')], 422);
        }
        // Schema gewijzigd: tellen vanaf nu (anders volgt meteen een "ingehaalde" back-up)
        foreach (['backup_freq', 'backup_time', 'backup_weekday'] as $k) {
            if (isset($in[$k]) && (string) $in[$k] !== (string) setting($k, '')) {
                save_setting('backup_last_auto', now());
                save_setting('backup_retry_at', '');
                break;
            }
        }
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = trim((string) $in[$k]);
            if (in_array($k, SECRET_SETTINGS, true) && $v === '••••••••') {
                continue;
            }
            save_setting($k, $v);
        }
        // 1.133.0: minder back-ups bewaren? Dan meteen opruimen
        $pruned = isset($in['backup_keep']) || isset($in['backup_keep_manual']) ? Backup::prune() : 0;
        if (isset($in['retention_days']) || isset($in['hourly_days']) || isset($in['daily_days'])) {
            Retention::rollup();   // 1.136.0: korter bewaren? Dan meteen opruimen (wat nog niet per uur samengevat is, blijft)
            Retention::purge();
        }
        out(['ok' => true] + ($pruned ? ['pruned' => $pruned] : []));

    // 🧪 bij een rij van ℹ️ Systeeminfo: een voorbeeld naar de aangevinkte kanalen (wat nu in het formulier staat), ook tijdens stille uren
    case 'sysinfo_test':
        $in = input();
        $event = (string) ($in['event'] ?? '');
        if (!in_array($event, NotifyRules::SYSINFO, true)) {
            out(['error' => t('Onbekende melding')], 422);
        }
        $cfg = NotifyRules::cleanSysinfo([$event => (array) ($in['cfg'] ?? [])], NotifyRules::sysinfo())[$event];
        $label = mb_substr(trim((string) ($in['label'] ?? $event)), 0, 80);
        Monitor::sysInfo($event, null, null, '🧪 ' . t('Test') . ": $label", fn() => t('Zo ziet een melding "{label}" eruit. Als je dit ziet, werkt dit kanaal.', ['label' => $label]),
            null, null, false, $cfg, true);
        $row = q("SELECT message, sent_ok, email_ok, webhook_ok FROM alerts WHERE kind = 'info' ORDER BY id DESC LIMIT 1")->fetch();
        out(['ok' => true, 'page' => $cfg[NotifyRules::PAGE], 'result' => $row ?: null]);

    case 'webhook_test':
        $in = input();
        $url = trim((string) ($in['ha_webhook_url'] ?? '')) ?: null;
        $verify = isset($in['ha_webhook_verify_ssl']) ? $in['ha_webhook_verify_ssl'] === '1' : null;
        $fake = ['id' => 0, 'hid' => 0, 'host_name' => 'RzSense', 'name' => t('Testmelding'), 'type' => 'ping', 'target' => ''];
        $res = Webhook::send(Monitor::webhookPayload($fake, ['value' => null], STATUS_OK, '🧪 RzSense test',
            t('Als je dit in Home Assistant ziet, werkt de webhook! 🎉'), 'test', STATUS_OK), $url, $verify);
        out($res, $res['ok'] ? 200 : 422);

    case 'email_test':
        // Test met de (nog niet opgeslagen) waarden uit het formulier
        $in = input();
        $cfg = Mailer::config();
        foreach (array_keys($cfg) as $k) {
            if (isset($in[$k]) && !($k === 'smtp_pass' && $in[$k] === '••••••••')) {
                $cfg[$k] = trim((string) $in[$k]);
            }
        }
        session_write_close();
        $res = Mailer::send('🧪 RzSense test', t('Als je dit leest, werken e-mailmeldingen van RzSense! 🎉') . "\n\n" . t('Verstuurd op {time}.', ['time' => date('d-m-Y H:i:s')]), $cfg);
        out($res, $res['ok'] ? 200 : 422);

    case 'mqtt_test':
        $in = input();
        $cfg = Mqtt::config();
        foreach (array_keys($cfg) as $k) {
            if (isset($in[$k]) && !($k === 'mqtt_pass' && $in[$k] === '••••••••')) {
                $cfg[$k] = trim((string) $in[$k]);
            }
        }
        session_write_close();
        $r = Mqtt::publishAll($cfg, true);
        if (!$r['ok']) {
            out(['error' => $r['error']], 422);
        }
        out(['ok' => true, 'message' => t('Verbonden ✔ — {n} sensoren voor {hosts} server(s) naar Home Assistant gestuurd', ['n' => $r['entities'], 'hosts' => $r['hosts']])]);

    case 'pushover_test':
        $in = input();
        $token = ($in['pushover_token'] ?? '') === '••••••••' || ($in['pushover_token'] ?? '') === ''
            ? (string) setting('pushover_token', '') : (string) $in['pushover_token'];
        $res = Pushover::send('🧪 RzSense test', t('Als je dit leest werkt Pushover! 🎉'), 0,
            trim((string) ($in['pushover_user'] ?? '')) ?: null, $token ?: null);
        out($res, $res['ok'] ? 200 : 422);

    case 'ntfy_test':
        // Test met de (nog niet opgeslagen) waarden uit het formulier; een gemaskeerd token = het opgeslagen token
        $in = input();
        $tok = (string) ($in['ntfy_token'] ?? '');
        session_write_close();
        $res = Ntfy::send('🧪 RzSense test', t('Als je dit leest werkt ntfy! 🎉'), 0, [], [
            'server' => (string) ($in['ntfy_server'] ?? ''), 'topic' => (string) ($in['ntfy_topic'] ?? ''),
            'token' => $tok === '••••••••' ? (string) setting('ntfy_token', '') : trim($tok)]);
        out($res, $res['ok'] ? 200 : 422);

    case 'access_info':
        out([
            'lan_no_login' => setting('lan_no_login', '0') === '1',
            'lan_as_admin' => setting('lan_as_admin', '0') === '1',
            'trusted_networks' => (string) setting('trusted_networks', ''),
            'trusted_proxies' => (string) setting('trusted_proxies', ''),
            'client_ip' => client_ip(),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
            'client_trusted' => Https::internal(),
            'unknown_proxy' => Https::unknownProxy(),
            'lan_session' => is_lan_session(),
            'lock' => Security::lockConfig(),
            'session_idle' => (int) (session_ttl() / 60), 'session_idle_choices' => SESSION_IDLE,
        ]);

    case 'access_save':
        $in = input();
        $networks = parse_list((string) ($in['trusted_networks'] ?? ''));
        $proxies = parse_list((string) ($in['trusted_proxies'] ?? ''));
        foreach (array_merge($networks, $proxies) as $cidr) {
            [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
            $max = str_contains($ip, ':') ? 128 : 32;
            if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $max))) {
                out(['error' => t('Ongeldig netwerk: {net} (gebruik bv. 192.168.1.0/24 of 10.0.0.5)', ['net' => $cidr])], 422);
            }
        }
        $enable = !empty($in['lan_no_login']);
        if ($enable && !$networks) {
            out(['error' => t('Geef minimaal één lokaal netwerk op')], 422);
        }
        foreach ($networks as $cidr) {
            if (str_ends_with($cidr, '/0')) {
                out(['error' => t('{net} zou iedereen op internet zonder wachtwoord binnenlaten', ['net' => $cidr])], 422);
            }
        }
        save_setting('lan_no_login', $enable ? '1' : '0');
        save_setting('lan_as_admin', !empty($in['lan_as_admin']) ? '1' : '0');
        save_setting('trusted_networks', implode("\n", $networks));
        save_setting('trusted_proxies', implode("\n", $proxies));
        $pu = json_decode((string) setting('proxy_unknown', ''), true) ?: [];
        if (!empty($pu['ip']) && ip_in_list((string) $pu['ip'], $proxies)) {
            save_setting('proxy_unknown', '');   // die proxy staat er nu bij: de ⚠️ in "Hoe staat het ervoor?" mag weg
        }
        // Inlogbeveiliging
        if (isset($in['login_lock_fails'])) {
            save_setting('login_lock_fails', (string) max(1, min(50, (int) $in['login_lock_fails'])));
            save_setting('login_lock_window', (string) max(1, min(1440, (int) ($in['login_lock_window'] ?? 10))));
            save_setting('login_lock_minutes', (string) max(0, min(525600, (int) ($in['login_lock_minutes'] ?? 60))));
            save_setting('login_lock_account', !empty($in['login_lock_account']) ? '1' : '0');
            save_setting('login_lock_ip', !empty($in['login_lock_ip']) ? '1' : '0');
        }
        if (isset($in['session_idle']) && in_array((int) $in['session_idle'], SESSION_IDLE, true)) {
            save_setting('session_idle_minutes', (string) (int) $in['session_idle']);
        }
        out(['ok' => true]);
}
