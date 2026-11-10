<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: monitoring, servers en checks, meldingen, statistiek.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var array<string, mixed> $user
 * @var bool $isAdmin
 * @var callable(string): void $confirm
 */
defined('RZ_API') || exit;

switch ($action) {
    case 'logout':
        logout();
        out(['ok' => true]);

    case 'contacts':   // 1.99.0: laatste contact per agent, voor de live tijden (elke 5 seconden)
        $out = [];
        foreach (q("SELECT id, agent_seen, agent_tick, realtime, agent_beats FROM hosts WHERE mode = 'agent'")->fetchAll() as $h) {
            $out[(int) $h['id']] = agent_contact($h);
        }
        out(['hosts' => (object) $out, 'now_ts' => time()]);

    case 'state':
        $hosts = q('SELECT id, name, address, mode, parent_id, agent_token, agent_seen, agent_version, agent_update_req, agent_update_status, agent_update_via, agent_tick, realtime, info_agent, enc, enc_seen, watchdog, watchdog_min,
                    agent_key_seen, agent_key_at, agent_ip_new, pin_mode, ip_lock, agent_https, agent_conn, agent_fb_at, agent_url, agent_url_fb, agent_url_want, agent_url_status, agent_url_at, agent_url_pin, agent_url_fb_pin,
                    enabled, maintenance_until, maintenance_reason, snmp, kind, kind_auto, grp, agent_beats, edr_on, edr_info, iperf_ok, quiet, quiet_until FROM hosts ORDER BY name')->fetchAll();
        $checks = q('SELECT c.*, h.maintenance_until AS host_maintenance_until, h.maintenance_reason AS host_maintenance_reason
                     FROM checks c JOIN hosts h ON h.id = c.host_id ORDER BY c.name')->fetchAll();
        $global = Monitor::globalMaintenance();
        $agent = q("SELECT host_id, metric FROM agent_data")->fetchAll();
        $byHost = [];
        foreach ($checks as $c) {
            // Effectief onderhoud (globaal, server of check) voor de weergave
            $c['maintenance'] = Monitor::maintenanceFor($c);
            $c['quiet_eff'] = Quiet::summary(Quiet::forCheck($c));   // 1.189.0: welke stille uren gelden, en waarvandaan
            unset($c['host_maintenance_until'], $c['host_maintenance_reason']);
            if (!$isAdmin) {
                unset($c['hb_token']);   // geheime heartbeat-URL alleen voor beheerders
            }
            if (in_array($c['type'], ['mysql', 'mqtt', 'vpn'], true)) {
                $c['options'] = ExtraChecks::maskOptions($c['options']);   // 1.138.0: wachtwoord nooit naar de browser (1.170.0: ook MQTT en Gluetun)
            }
            $byHost[$c['host_id']][] = $c;
        }
        $metrics = [];
        foreach ($agent as $a) {
            $metrics[$a['host_id']][] = $a['metric'];
        }
        $discover = $isAdmin ? Discover::counts() : [];   // 1.95.0: nog niet bewaakt (alleen beheerders voegen checks toe)
        foreach ($hosts as &$h) {
            $h['discover_n'] = $discover[(int) $h['id']] ?? 0;
            $h['quiet_eff'] = Quiet::summary(Quiet::forHost($h));   // 1.189.0
            $h['checks'] = $byHost[$h['id']] ?? [];
            $h['metrics'] = $metrics[$h['id']] ?? [];
            $own = $h['maintenance_until'] && strtotime($h['maintenance_until']) > time()
                ? ['scope' => 'host', 'until' => $h['maintenance_until'], 'reason' => (string) $h['maintenance_reason']] : null;
            $h['maintenance'] = $global ?? $own;
            $h['snmp'] = SnmpMonitor::masked($h['snmp']);   // community en wachtwoorden gaan nooit naar de browser
            $h['contact'] = $h['mode'] === 'agent' ? agent_contact($h) : null;
            unset($h['agent_beats']);
        }
        unset($h);
        if (!$isAdmin) {
            // Agent-tokens zijn geheim: alleen beheerders installeren agents
            foreach ($hosts as &$h) {
                $h['agent_token'] = null;
            }
            unset($h);
        }
        $lastRun = q('SELECT MAX(last_checked) FROM checks')->fetchColumn();
        out(['hosts' => $hosts, 'badges' => tab_badges($user, $hosts, (string) ($_GET['seen'] ?? '')), 'last_run' => $lastRun, 'user' => $user['username'],
             'base_url' => config('base_url', ''), 'snmp_global' => ($sg = SnmpMonitor::global()) ? array_intersect_key($sg, array_flip(['v', 'port', 'user', 'level', 'auth_proto', 'priv_proto'])) : null, 'agent_https_only' => setting('agent_https_only', '0') === '1', 'lan_session' => is_lan_session(), 'maintenance' => $global,
             'version' => RZMON_VERSION, 'agent_latest' => AGENT_LATEST, 'edr_note_hide' => json_decode((string) setting('edr_note_hide', '{}'), true) ?: (object) [], 'role' => $user['role'], 'user_id' => $user['id'], 'ai' => Ai::stateInfo($user), 'isolated' => Ids::isolated(), 'health' => Health::current(), 'health_groups' => Health::groups(),
             'agent_tick' => agent_tick(null), 'now_ts' => time(), 'confirm' => Confirm::state($user),   // 1.206.0
             // Sjablonen (1.94.0): item → naam van het sjabloon, en welke sjablonen per server
             'templates' => q('SELECT id, name FROM check_templates ORDER BY name')->fetchAll(),
             'tpl_items' => (object) array_column(q('SELECT ti.id, t.name FROM template_items ti JOIN check_templates t ON t.id = ti.template_id')->fetchAll(), 'name', 'id'),
             'host_tpls' => (object) array_reduce(q('SELECT host_id, template_id FROM host_templates')->fetchAll(), static function ($a, $r) { $a[$r['host_id']][] = (int) $r['template_id']; return $a; }, []),
             'notify_fails' => max(1, (int) setting('notify_fails', '1')),
             'confirm_fails' => Monitor::confirmNeeded([], false), 'confirm_ok' => Monitor::confirmNeeded([], true),   // 1.185.0
             'confirm_types' => (object) Monitor::confirmTypes(),   // 1.193.0
             'quiet_profiles' => array_values(array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'icon' => $p['icon']], Quiet::profiles())), 'quiet_global' => (int) setting('quiet_global', '') ?: null,   // 1.189.0
             'thresh_default' => (string) setting('thresh_default', 'guard'), 'smart_sens' => (string) setting('smart_sens', 'normal'),
             // Open critical IDS-incidenten (rood bolletje op het tabblad 🛡️ IDS)
             'net_new' => NetScan::enabled() ? NetScan::newCount() : 0,
             'ids_open' => Ids::enabled() ? (int) q("SELECT COUNT(*) FROM ids_incidents WHERE status = 'open' AND severity = 2")->fetchColumn() : 0,
             // Voor beheerders: label "⬆ x.y.z" bovenin als er een nieuwere versie is
             'update_running' => $isAdmin && Updater::running(),
             // Voor het installatiecommando in de Agent Manager (--key): alleen beheerders, net als de tokens
             'enc_key' => $isAdmin && AgentCrypto::globalMode() !== 'off' ? (AgentCrypto::key() ?: null) : null,
             'dl_key' => $isAdmin ? Stealth::dlKey() : null,   // downloadsleutel voor de installatiecommando's (1.86.0)
             'conn_pin' => AgentCrypto::pinOn(), 'conn_ip_lock' => AgentCrypto::ipLockOn(), 'conn_https' => AgentCrypto::httpsOnly(),
             'url_min' => AgentCrypto::URL_MIN,
             'update' => $isAdmin && ($uc = json_decode((string) setting('update_cache', ''), true)) && !empty($uc['latest'])
                 && version_compare($uc['latest'], RZMON_VERSION, '>') ? $uc['latest'] : null]);

    // Meldingen van de bewaartermijn (Instellingen → Alarmgedrag), nieuwste eerst; de pagina groepeert per dag
    case 'alerts':
        // 1.119.0: standaard de laatste 7 dagen; ouder op verzoek (?days)
        $maxDays = Monitor::alertDays();
        $days = max(1, min($maxDays, (int) ($_GET['days'] ?? $maxDays)));
        $rows = q('SELECT a.*, c.name AS check_name, h.name AS host_name FROM alerts a
                   LEFT JOIN checks c ON c.id = a.check_id LEFT JOIN hosts h ON h.id = COALESCE(c.host_id, a.host_id)
                   WHERE a.created_at >= CURDATE() - INTERVAL ? DAY AND a.kind NOT IN (\'auth\', \'authfail\')
                   ORDER BY a.created_at DESC, a.id DESC LIMIT 10000', [$days - 1])->fetchAll();
        // Meldingen met een IP (geweigerde verbinding, login): staat dat IP al op de blokkadelijst?
        $nets = array_column(q('SELECT net FROM rz_blocks WHERE until IS NULL OR until > NOW()')->fetchAll(), 'net');
        // 1.212.0: melding van een vaste zoekactie: is die storing nog open?
        $lwOpen = array_flip(array_map('intval', array_column(q('SELECT alert_id FROM log_watch_state WHERE open = 1 AND alert_id IS NOT NULL')->fetchAll(), 'alert_id')));
        foreach ($rows as &$a) {
            $a['blocked'] = $a['ip'] !== null && $a['ip'] !== '' && $nets && ip_in_list((string) $a['ip'], $nets);
            if (!empty($a['log_watch'])) {
                $a['lw_open'] = isset($lwOpen[(int) $a['id']]);
            }
        }
        unset($a);
        out(['days' => $days, 'max_days' => $maxDays, 'alerts' => $rows, 'today' => date('Y-m-d'), 'truncated' => count($rows) >= 10000]);

    // Popup van een check: gegevens, beschikbaarheid en de laatste 5 statuswijzigingen met hun meldingen
    case 'check_info':
        $id = (int) ($_GET['id'] ?? 0);
        $c = q('SELECT c.*, h.name AS host_name, h.mode FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch();
        if (!$c) {
            out(['error' => t('Deze check bestaat niet meer')], 404);
        }
        $up = q('SELECT SUM(created_at >= NOW() - INTERVAL 1 DAY) AS n1, SUM(created_at >= NOW() - INTERVAL 1 DAY AND status <> 2) AS ok1,
                        COUNT(*) AS n7, SUM(status <> 2) AS ok7
                 FROM results WHERE check_id = ? AND created_at >= NOW() - INTERVAL 7 DAY', [$id])->fetch();
        $pct = static fn($ok, $n) => (int) $n > 0 ? round(100 * (int) $ok / (int) $n, 1) : null;
        $changes = q('SELECT * FROM status_changes WHERE check_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . (!empty($_GET['more']) ? 25 : 5), [$id])->fetchAll();
        $nChanges = (int) q('SELECT COUNT(*) FROM status_changes WHERE check_id = ?', [$id])->fetchColumn();
        $end = null;   // de wijziging erna (nieuwste eerst), of nu
        foreach ($changes as &$ch) {
            // Meldingen die bij deze wijziging horen: tussen deze en de volgende wijziging
            $ch['alerts'] = q("SELECT id, kind, status, title, created_at, sent_ok, email_ok, webhook_ok FROM alerts
                               WHERE check_id = ? AND created_at >= ?" . ($end ? ' AND created_at < ?' : '') . ' ORDER BY created_at, id LIMIT 5',
                array_merge([$id, $ch['created_at']], $end ? [$end] : []))->fetchAll();
            $ch['ended_at'] = $end;
            $end = $ch['created_at'];
        }
        unset($ch);
        if (in_array($c['type'], ['mysql', 'mqtt', 'vpn'], true)) {
            $c['options'] = ExtraChecks::maskOptions($c['options']);
        }
        if (!$isAdmin) {
            unset($c['hb_token']);   // 1.224.1: ook hier de geheime heartbeat-URL alleen voor beheerders (zoals bij 'state')
        }
        out(['check' => $c, 'flow' => Monitor::flow($c), 'uptime' => ['d1' => $pct($up['ok1'], $up['n1']), 'd7' => $pct($up['ok7'], $up['n7'])],
             'changes' => $changes, 'changes_total' => $nChanges, 'now' => now(), 'smart' => Smart::info($c), 'extra' => CheckInfo::extra($c)]);

    case 'history':
        $id = (int) ($_GET['check_id'] ?? 0);
        $hours = min(24 * Retention::hourlyDays(), max(1, (int) ($_GET['hours'] ?? 24)));
        // 1.136.0: langer dan 30 dagen (of verder terug dan de ruwe historie): uit de uurgemiddelden
        $rows = $hours > 24 * min(30, Retention::rawDays())
            ? q('SELECT UNIX_TIMESTAMP(hour) AS t, IF(n_crit > 0, 2, IF(n_warn > 0, 1, 0)) AS s, v_avg AS v, NULL AS x FROM results_hourly
                 WHERE check_id = ? AND hour >= NOW() - INTERVAL ? HOUR ORDER BY hour', [$id, $hours])->fetchAll()
            : q('SELECT UNIX_TIMESTAMP(created_at) AS t, status AS s, value AS v, vals AS x FROM results
                 WHERE check_id = ? AND created_at >= NOW() - INTERVAL ? HOUR ORDER BY created_at', [$id, $hours])->fetchAll();
        // Downsamplen naar max ~300 punten
        $step = max(1, (int) ceil(count($rows) / 300));
        $pts = [];
        for ($i = 0; $i < count($rows); $i += $step) {
            $slice = array_slice($rows, $i, $step);
            $vals = array_filter(array_column($slice, 'v'), fn($v) => $v !== null);
            // Container: per meting (CPU, RAM, disk) het gemiddelde
            $x = [];
            foreach ($slice as $r) {
                foreach ((array) json_decode((string) $r['x'], true) as $k => $xv) {
                    if (is_numeric($xv)) {
                        $x[$k][] = (float) $xv;
                    }
                }
            }
            $pts[] = [
                't' => (int) $slice[0]['t'],
                's' => max(array_map('intval', array_column($slice, 's'))),
                'v' => $vals ? array_sum($vals) / count($vals) : null,
            ] + ($x ? ['x' => array_map(fn($a) => round(array_sum($a) / count($a), 2), $x)] : []);
        }
        $up = $rows ? count(array_filter($rows, fn($r) => (int) $r['s'] !== STATUS_CRIT)) / count($rows) * 100 : null;
        $type = q('SELECT type, last_value, metric, options FROM checks WHERE id = ?', [$id])->fetch();
        // Container: welke metingen bewaakt worden, met eenheid en drempels (een lijn per meting)
        $series = null;
        if ($type && $type['type'] === 'container' && $type['metric'] === null) {
            $series = [];
            foreach ((array) ((json_decode((string) $type['options'], true) ?: [])['res'] ?? []) as $k => $t) {
                $series[] = ['key' => $k, 'label' => ['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => 'Disk'][$k] ?? $k,
                    'unit' => $k === 'disk' ? 'MB' : '%', 'warn' => $t[0] ?? null, 'crit' => $t[1] ?? null];
            }
        }
        $forecast = $type && $type['type'] === 'disk' && $type['last_value'] !== null ? Stats::diskForecast($id, (float) $type['last_value']) : null;
        $logLines = null;
        if ($type && $type['type'] === 'log') {
            $hid = q('SELECT host_id FROM checks WHERE id = ?', [$id])->fetchColumn();
            $lr = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = ?", [$hid, "logrun:$id"])->fetchColumn(), true);
            $logLines = array_map(fn($l) => ['time' => date('Y-m-d H:i:s', (int) $l[0]), 'line' => $l[1]], $lr['lines'] ?? []);
        }
        // 1.107.0: slimme drempels: de normale band (10e–95e percentiel) en de warning op het uur van elk punt
        $band = null;
        if ($type && ($m = Smart::model($id)) && isset($m['w']) && (int) ($m['days'] ?? 0) >= Smart::MIN_DAYS) {
            $band = array_map(static function ($p) use ($m) {
                $h = Smart::how($p['t']);
                return [$m['lo'][$h], $m['hi'][$h], $m['w'][$h], $m['c'][$h]];
            }, $pts);
        }
        out(['points' => $pts, 'uptime' => $up, 'forecast' => $forecast, 'log_lines' => $logLines, 'series' => $series, 'band' => $band]);

    // 1.171.0: AI-advies. Instellingen (beheerders): modellen ophalen, testen, diagnose en uitvoeren aan/uit (met wachtwoord)
    case 'ai_models':
    case 'ai_test':
        $in = input();
        session_write_close();
        $cfg = Ai::cfg($in);
        try {
            if ($action === 'ai_models') {
                $models = Ai::models($cfg);
                out(['models' => $models, 'suggest' => Ai::suggestModel($models)]);
            }
            // 1.223.1: hooguit 45 s, zodat de webserver of reverse proxy (vaak 60 s) de test niet afbreekt met een foutpagina
            $r = Ai::chat(['max_tokens' => 60, 'timeout' => min(45, $cfg['timeout'])] + $cfg, t('Antwoord in één korte zin.'), t('Zeg dat de verbinding met RzSense werkt.'));
            out(['ok' => true, 'text' => mb_substr($r['text'], 0, 300), 'ms' => $r['ms']]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($cfg['provider'] === 'ollama' && preg_match('/timed out|time-out|timeout/i', $msg)) {
                $msg = t('Ollama gaf binnen 45 seconden geen antwoord. De eerste vraag laadt het model in het geheugen en duurt op een gewone processor soms lang: probeer het over een minuut nog eens, of kies een kleiner model.') . ' (' . $msg . ')';
            }
            out(['error' => $e instanceof RuntimeException ? $msg : t('Fout bij het testen: {e}', ['e' => mb_substr($msg, 0, 200)])], 422);
        }

    case 'ai_info':
        out(['on' => Ai::on(), 'used' => Ai::usedToday(), 'diag' => setting('ai_diag', '0') === '1', 'exec' => setting('ai_exec', '0') === '1',
            'providers' => array_map(static fn($p) => ['label' => $p['label'], 'cloud' => $p['cloud']], Ai::PROVIDERS),
            'recent' => array_map([Ai::class, 'public'], q("SELECT * FROM ai_advice WHERE kind <> 'triage' ORDER BY id DESC LIMIT 8")->fetchAll()),
            'usage' => Ai::usage(), 'week_ref' => (int) date('oW'),   // 1.173.0: verbruik, tegoed, budget en het weekoverzicht
            'week_last' => (int) q("SELECT MAX(ref_id) FROM ai_advice WHERE kind = 'week'")->fetchColumn() ?: null,
            'triage_n' => (int) q("SELECT COUNT(*) FROM ai_advice WHERE kind = 'triage' AND created_at >= CURDATE()")->fetchColumn(), 'triage' => Triage::stats()]);

    case 'ai_cost_set':   // 1.173.0: prijzen per model en het bijgehouden tegoed
        $in = input();
        Ai::saveCost((array) ($in['prices'] ?? []), (array) ($in['credit'] ?? []));
        out(['ok' => true, 'usage' => Ai::usage()]);

    case 'ai_feedback':   // 1.173.0: hielp het advies? (geheugen voor de volgende keer)
        if (!Ai::on()) {
            out(['error' => t('AI-advies staat uit')], 404);
        }
        $in = input();
        try {
            $r = Ai::feedback((int) ($in['id'] ?? 0), array_key_exists('rating', $in) ? (int) $in['rating'] : null, array_key_exists('solved', $in) ? !empty($in['solved']) : null, $user['username']);
            out(['advice' => Ai::public($r)]);
        } catch (RuntimeException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'ai_guard_set':
        $in = input();
        $key = ($in['key'] ?? '') === 'exec' ? 'ai_exec' : 'ai_diag';
        $on = !empty($in['on']);
        if ($on && setting($key, '0') !== '1') {
            $confirm('TOESTAAN');   // ruimer: je wachtwoord (of thuis het woord)
        }
        save_setting($key, $on ? '1' : '0');
        AgentCommands::audit($user['username'], $key === 'ai_exec' ? 'AI: uitvoeren' : 'AI: diagnose', $on ? 'aan' : 'uit');
        if (!$on && $key === 'ai_diag') {
            q('UPDATE hosts SET ai_diag = NULL WHERE ai_diag IS NOT NULL');
        }
        out(['ok' => true, 'on' => $on]);

    // Advies opvragen (iedereen met leesrechten), vragen en afmaken (gebruikers), uitvoeren (beheerders)
    case 'ai_get':
    case 'ai_ask':
    case 'ai_run':
    case 'ai_exec':
        if (!Ai::on()) {
            out(['error' => t('AI-advies staat uit')], 404);
        }
        $in = $action === 'ai_get' ? $_GET : input();
        try {
            if ($action === 'ai_exec') {
                out(['ok' => true, 'message' => Ai::exec((int) ($in['id'] ?? 0), (string) ($in['action'] ?? ''), $user['username'])]);
            }
            if ($action === 'ai_get') {
                $kind = (string) ($in['kind'] ?? '');
                $r = !empty($in['id']) ? (q('SELECT * FROM ai_advice WHERE id = ?', [(int) $in['id']])->fetch() ?: null) : Ai::latest($kind, (int) ($in['ref'] ?? 0));
                out(['advice' => $r ? Ai::public($r) : null]);
            }
            $r = $action === 'ai_ask' ? Ai::ask((string) ($in['kind'] ?? ''), (int) ($in['ref'] ?? 0), $user['username'], !empty($in['force']))
                : (q('SELECT * FROM ai_advice WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch() ?: throw new RuntimeException(t('Niet gevonden')));
            if (in_array($r['status'], ['pending', 'diag'], true)) {
                // Het model kan even nadenken: de sessie vrijgeven en doorgaan, ook als de browser niet wacht
                session_write_close();
                ignore_user_abort(true);
                $r = Ai::work((int) $r['id']);
            }
            out(['advice' => Ai::public($r)]);
        } catch (RuntimeException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'availability':
        // 1.169.0: Monitoring → Beschikbaarheid: per server het percentage, per uur/dag, en de storingen in de periode
        out(Availability::report((int) ($_GET['days'] ?? 7)));

    case 'stats':
        // Beschikbaarheid per server en minigrafieken per check; 2 minuten bewaard
        $cache = json_decode((string) setting('stats_cache', ''), true);
        if (!$cache || ($cache['at'] ?? 0) < time() - 120) {
            $cache = ['at' => time(), 'uptime' => Stats::uptime(), 'sparks' => Stats::sparks()];
            save_setting('stats_cache', json_encode($cache));
        }
        out($cache);

    // Taal van de interface: bij je account (op elk apparaat), en op dit apparaat (ook zonder account)
    case 'lang_set':
        $lang = (string) (input()['lang'] ?? '');
        if (!in_array($lang, I18n::LANGS, true)) {
            out(['error' => t('Onbekende taal')], 422);
        }
        if ((int) ($user['id'] ?? 0) > 0) {
            q('UPDATE users SET lang = ? WHERE id = ?', [$lang, (int) $user['id']]);
        }
        I18n::remember($lang);
        out(['ok' => true, 'lang' => $lang]);

    case 'host_save':
        $in = input();
        $hostBefore = !empty($in['id']) ? ChangeLog::hostRow((int) $in['id']) : null;   // wijzigingslogboek (1.97.0)
        $mode = in_array($in['mode'] ?? '', ['local', 'agent', 'remote'], true) ? $in['mode'] : 'remote';
        $name = trim((string) ($in['name'] ?? ''));
        $addr = trim((string) ($in['address'] ?? ''));
        if ($name === '' || $addr === '' || !preg_match('/^[A-Za-z0-9.:\-\[\]]+$/', $addr)) {
            out(['error' => t('Naam en geldig adres (hostname/IP) zijn verplicht')], 422);
        }
        $enabled = !empty($in['enabled']) ? 1 : 0;
        $parent = !empty($in['parent_id']) ? (int) $in['parent_id'] : null;
        // SNMP: alleen als het formulier het meestuurt; gemaskeerde geheimen blijven zoals ze waren
        $snmp = false;
        if (array_key_exists('snmp', $in)) {
            $oldSnmp = !empty($in['id']) ? SnmpMonitor::config(q('SELECT snmp FROM hosts WHERE id = ?', [(int) $in['id']])->fetchColumn() ?: null) : null;
            $snmp = SnmpMonitor::clean($in['snmp'], $oldSnmp);
            if (is_string($snmp)) {
                out(['error' => $snmp], 422);
            }
        }
        if ($parent !== null) {
            if (!q('SELECT 1 FROM hosts WHERE id = ?', [$parent])->fetchColumn()) {
                out(['error' => t('Bovenliggende server bestaat niet')], 422);
            }
            // Geen kringen: de parent mag niet (indirect) onder deze server hangen
            for ($p = $parent, $i = 0; $p !== null && $i < 50; $i++) {
                if (!empty($in['id']) && $p === (int) $in['id']) {
                    out(['error' => t('Een server kan niet van zichzelf afhankelijk zijn (ook niet via een andere server)')], 422);
                }
                $next = q('SELECT parent_id FROM hosts WHERE id = ?', [$p])->fetchColumn();
                $p = $next ? (int) $next : null;
            }
        }
        if (!empty($in['id'])) {
            q('UPDATE hosts SET name = ?, address = ?, mode = ?, enabled = ?, parent_id = ? WHERE id = ?', [$name, $addr, $mode, $enabled, $parent, (int) $in['id']]);
            $id = (int) $in['id'];
        } else {
            q('INSERT INTO hosts (name, address, mode, enabled, parent_id) VALUES (?, ?, ?, ?, ?)', [$name, $addr, $mode, $enabled, $parent]);
            $id = (int) db()->lastInsertId();
            if (!empty($in['add_ping'])) {
                q("INSERT INTO checks (host_id, type, name, warn_threshold, crit_threshold) VALUES (?, 'ping', 'Ping', 100, 500)", [$id]);
            }
        }
        if ($mode === 'agent') {
            q('UPDATE hosts SET agent_token = COALESCE(agent_token, ?) WHERE id = ?', [bin2hex(random_bytes(20)), $id]);
        }
        if ($snmp !== false) {
            q('UPDATE hosts SET snmp = ? WHERE id = ?', [$snmp ? json_encode($snmp, JSON_UNESCAPED_UNICODE) : null, $id]);
        }
        // Soort apparaat: leeg = vanzelf (herkend), anders zelf gekozen
        if (array_key_exists('kind', $in)) {
            $kind = (string) $in['kind'];
            q('UPDATE hosts SET kind = ? WHERE id = ?', [isset(HostKind::KINDS[$kind]) ? $kind : null, $id]);
        }
        // 1.177.0: mag doorvoertest (iperf3) ontvangen voor de pad-check van een andere server
        if (array_key_exists('iperf_ok', $in)) {
            q('UPDATE hosts SET iperf_ok = ? WHERE id = ?', [!empty($in['iperf_ok']) ? 1 : 0, $id]);
        }
        // 1.189.0: stille uren van deze server (leeg = volgt de groep of algemeen)
        if (array_key_exists('quiet', $in)) {
            q('UPDATE hosts SET quiet = ? WHERE id = ?', [Quiet::cleanVal($in['quiet']), $id]);
        }
        // 1.169.0: eigen groep (vrije tekst, leeg = geen)
        if (array_key_exists('grp', $in)) {
            $grp = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $in['grp']) ?? ''), 0, 60);
            q('UPDATE hosts SET grp = ? WHERE id = ?', [$grp === '' ? null : $grp, $id]);
        }
        if (array_key_exists('kind_auto', $in) && isset(HostKind::KINDS[(string) $in['kind_auto']])) {
            HostKind::setAuto($id, (string) $in['kind_auto']);   // herkend bij 🔍 Test SNMP in het formulier
        }
        // IDS: extra bestanden bewaken op deze server
        if (array_key_exists('ids_fim', $in)) {
            $fimOwn = Ids::cleanFim($in['ids_fim']);
            q('UPDATE hosts SET ids_fim = ? WHERE id = ?', [$fimOwn ? json_encode($fimOwn, JSON_UNESCAPED_SLASHES) : null, $id]);
        }
        ChangeLog::record('host', $id, $hostBefore ? 'update' : 'create', $name, $hostBefore, ChangeLog::hostRow($id));
        out(['ok' => true, 'id' => $id]);

    // SNMP testen met de instellingen uit het formulier (nog niet opgeslagen; gemaskeerde geheimen van de server zelf)
    case 'snmp_test':
        $in = input();
        $old = !empty($in['id']) ? SnmpMonitor::config(q('SELECT snmp FROM hosts WHERE id = ?', [(int) $in['id']])->fetchColumn() ?: null) : null;
        $cfg = SnmpMonitor::clean(['on' => 1] + (array) ($in['snmp'] ?? []), $old);
        $addr = trim((string) ($in['address'] ?? ''));
        if (is_string($cfg) || $cfg === null) {
            out(['error' => $cfg ?: t('Vul de SNMP-instellingen in')], 422);
        }
        if ($addr === '' || !preg_match('/^[A-Za-z0-9.:\-\[\]]+$/', $addr)) {
            out(['error' => t('Vul eerst het adres van de server in')], 422);
        }
        if (isset($cfg['use'])) {
            $cfg = SnmpMonitor::global() ?? [];   // 1.178.0: de algemene instelling testen op dit apparaat
        }
        try {
            $sys = SnmpMonitor::test($cfg, $addr);
            if (!empty($in['id'])) {
                HostKind::setAuto((int) $in['id'], $sys['kind']);
            }
            out(['ok' => true, 'sys' => $sys]);
        } catch (Throwable $e) {
            out(['error' => $e->getMessage()], 422);
        }

    // 1.178.0: de algemene SNMP-instelling (Instellingen → Koppelingen → SNMP) en wie hem gebruikt
    case 'snmp_global':
        $rows = [];
        foreach (q("SELECT id, name, address, snmp FROM hosts WHERE snmp IS NOT NULL AND snmp <> '' ORDER BY name") as $r) {
            $c = SnmpMonitor::config($r['snmp']);
            $glob = SnmpMonitor::usesGlobal($r['snmp']);
            if ($c !== null || $glob) {
                $rows[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'address' => $r['address'], 'global' => $glob, 'v' => $c['v'] ?? null];
            }
        }
        out(['cfg' => SnmpMonitor::mask(SnmpMonitor::global()), 'hosts' => $rows, 'available' => SnmpMonitor::available()]);

    case 'snmp_global_save':
        $in = input();
        if (empty($in['on'])) {
            if ((int) q('SELECT COUNT(*) FROM hosts WHERE snmp = ?', [SnmpMonitor::USE_GLOBAL])->fetchColumn() > 0) {
                out(['error' => t('Er zijn nog apparaten die de algemene instelling gebruiken: geef die eerst eigen instellingen, of zet SNMP daar uit')], 422);
            }
            save_setting(SnmpMonitor::GLOBAL_KEY, '');
            out(['ok' => true]);
        }
        $cfg = SnmpMonitor::clean(['on' => 1] + array_diff_key((array) $in, ['use' => 1]), SnmpMonitor::global());
        if (is_string($cfg) || $cfg === null) {
            out(['error' => $cfg ?: t('Vul de SNMP-instellingen in')], 422);
        }
        save_setting(SnmpMonitor::GLOBAL_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE));
        AgentCommands::audit($user['username'], 'SNMP', t('algemene instelling opgeslagen (v{v})', ['v' => $cfg['v']]));
        out(['ok' => true]);

    case 'snmp_global_test':
        // Alle apparaten die de algemene instelling gebruiken (of één adres) met de algemene instelling testen
        $g = SnmpMonitor::global();
        if ($g === null) {
            out(['error' => t('Vul eerst de algemene SNMP-instelling in en sla hem op')], 422);
        }
        $res = [];
        foreach (q('SELECT id, name, address FROM hosts WHERE snmp = ? ORDER BY name LIMIT 40', [SnmpMonitor::USE_GLOBAL]) as $r) {
            $t0 = microtime(true);
            try {
                $sys = SnmpMonitor::test($g, (string) $r['address']);
                $res[] = ['id' => (int) $r['id'], 'ok' => true, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'name' => $sys['name']];
            } catch (Throwable $e) {
                $res[] = ['id' => (int) $r['id'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        out(['results' => $res]);

    // 1.178.0: SNMP van één server (Agent Manager): uit, de algemene instelling, of eigen instellingen
    case 'host_snmp':
        $in = input();
        $hid = (int) ($in['id'] ?? 0);
        $before = ChangeLog::hostRow($hid);
        if (!$before) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $snmp = SnmpMonitor::clean($in['snmp'] ?? null, SnmpMonitor::config(q('SELECT snmp FROM hosts WHERE id = ?', [$hid])->fetchColumn() ?: null));
        if (is_string($snmp)) {
            out(['error' => $snmp], 422);
        }
        q('UPDATE hosts SET snmp = ? WHERE id = ?', [$snmp ? json_encode($snmp, JSON_UNESCAPED_UNICODE) : null, $hid]);
        ChangeLog::record('host', $hid, 'update', $before['name'] . ' (📡 SNMP)', $before, ChangeLog::hostRow($hid));
        out(['ok' => true, 'snmp' => SnmpMonitor::masked(q('SELECT snmp FROM hosts WHERE id = ?', [$hid])->fetchColumn() ?: null)]);

    // 1.179.0: rugbaarheid van één CVE nu bijwerken (knop in de popup)
    case 'vuln_buzz_now':
        $cv = mb_substr(trim((string) (input()['cve'] ?? '')), 0, 40);
        try {
            CveBuzz::update($cv);
        } catch (Throwable $e) {
            out(['error' => t('Dit lukte niet: {e}', ['e' => $e->getMessage()])], 422);
        }
        out(['ok' => true, 'buzz' => CveBuzz::info($cv)]);

    // 1.178.0: 🧪 Test in de Agent Manager: starten, en daarna de uitslag ophalen (het agentdeel komt bij zijn volgende ronde)
    case 'agent_selftest':
        $hid = (int) (input()['id'] ?? 0);
        if (!q('SELECT 1 FROM hosts WHERE id = ?', [$hid])->fetchColumn()) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $tid = AgentTest::start($hid);
        out(['test' => $tid] + (AgentTest::result($hid, $tid) ?? []));

    case 'agent_selftest_get':
        $r = AgentTest::result((int) ($_GET['id'] ?? 0), (int) ($_GET['test'] ?? 0));
        $r === null ? out(['error' => t('Server niet gevonden')], 404) : out($r);

    // Wat er via SNMP op deze server te bewaken valt (＋ SNMP-checks)
    case 'snmp_discover':
        $h = q('SELECT address, snmp FROM hosts WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        $cfg = $h ? SnmpMonitor::config($h['snmp']) : null;
        if (!$cfg) {
            out(['error' => t('SNMP staat uit voor deze server (✎ Server bewerken → 📡 SNMP)')], 422);
        }
        try {
            $d = SnmpMonitor::discover($cfg, (string) $h['address']);
            HostKind::setAuto((int) ($_GET['id'] ?? 0), $d['sys']['kind']);
            out($d);
        } catch (Throwable $e) {
            out(['error' => $e->getMessage()], 422);
        }

    // Realtime-watcher voor deze server aan of uit (standaard uit)
    case 'host_realtime':
        $in = input();
        q('UPDATE hosts SET realtime = ? WHERE id = ?', [!empty($in['on']) ? 1 : 0, (int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], 'Realtime', (!empty($in['on']) ? 'aan' : 'uit') . ' voor server ' . (int) ($in['id'] ?? 0));
        out(['ok' => true]);

    // 🐕 Waakhond (1.107.0): deze agent bewaakt RzSense (Pushover als RzSense onbereikbaar is of de checks stilstaan)
    case 'host_watchdog':
        $in = input();
        $hid = (int) ($in['id'] ?? 0);
        $before = ChangeLog::hostRow($hid);
        q('UPDATE hosts SET watchdog = ?, watchdog_min = ? WHERE id = ? AND mode = ?', [!empty($in['on']) ? 1 : 0, max(2, min(120, (int) ($in['min'] ?? 5))), $hid, 'agent']);
        if ($before) {
            ChangeLog::record('host', $hid, 'update', $before['name'] . ' (🐕 ' . t('waakhond') . ')', $before, ChangeLog::hostRow($hid));
        }
        out(['ok' => true]);

    // Info-melding bij agent-updates van deze server (volgt verder de algemene info-regel)
}
