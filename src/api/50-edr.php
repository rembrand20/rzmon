<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: endpoint-bewaking, bescherming, dreigingsinformatie, afwijkingen, uitzonderingen.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var array<string, mixed> $user
 * @var bool $isAdmin
 * @var callable(int): void $ownOnly
 * @var callable(string): void $confirm
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------- 1.110.0: endpoint-bewaking ----------
    case 'edr_host':   // per server: aan/uit, sensoren, wat ontbreekt
        $h = q('SELECT * FROM hosts WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        out(Edr::status($h) + ['ids' => Ids::enabled(), 'global' => setting('edr_on', '1') === '1', 'blocking' => Ids::blocking(),
            'install' => json_decode((string) ($h['vuln_upgrade_status'] ?? ''), true)]);

    case 'edr_host_set':
        $in = input();
        $h = q('SELECT id, name FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        q('UPDATE hosts SET edr_on = ? WHERE id = ?', [!empty($in['on']) ? 1 : 0, $h['id']]);
        AgentCommands::audit($user['username'], !empty($in['on']) ? 'Endpoint-bewaking aan' : 'Endpoint-bewaking uit', (string) $h['name']);
        out(['ok' => true]);

    case 'edr_install':   // ontbrekend onderdeel via de agent installeren (bevestigen, net als bijwerken)
        $in = input();
        $h = q('SELECT * FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $confirm('INSTALLEREN');
        $own = mb_substr((string) ($in['pkgs'] ?? ''), 0, 1000);
        $r = Edr::install($h, (string) ($in['comp'] ?? ''), $user['username'], $own);
        if ($r !== true) {
            out(['error' => $r], 422);
        }
        AgentCommands::audit($user['username'], 'Endpoint-bewaking: installeren', $h['name'] . ': ' . ((string) ($in['comp'] ?? '')) . (trim($own) !== '' ? ' (' . trim($own) . ')' : ''));
        out(['ok' => true]);

    case 'self_status':   // 1.219.0: de RzSense-kern (de server waar RzSense op draait)
        out(SelfStatus::get());

    case 'edr_feed':   // Live: de laatste gebeurtenissen
        $hid = (int) ($_GET['host'] ?? 0) ?: null;
        out(['events' => Edr::feed((int) ($_GET['limit'] ?? 200), $hid, ($_GET['hits'] ?? '') === '1', (int) ($_GET['after'] ?? 0), ($_GET['own'] ?? '1') !== '0', ($_GET['only_own'] ?? '') === '1'),
            'sum' => ($_GET['sum'] ?? '1') === '1' ? Edr::liveSummary($hid) : null,   // 1.219.0: oordeel, soorten en per uur
            'on' => Ids::enabled() && setting('edr_on', '1') === '1']);

    case 'edr_kill':   // 1.219.0: proces stoppen vanuit Live (beheerder; alleen wat de agent zelf meldde)
        $r = Edr::killFromEvent((int) ($in['id'] ?? 0), $user['username']);
        if ($r !== true) {
            out(['error' => $r], 400);
        }
        out(['ok' => true]);

    // ---------- 1.115.0: bescherming ----------
    case 'ids_protect':
        out(Protect::overview());

    // ---------- 1.112.0: dreigingsinformatie ----------
    case 'ti_status':
        out(ThreatIntel::status() + ['on' => ThreatIntel::on(), 'dns' => DnsWatch::status()]);

    case 'ti_test':   // werkt de sleutel? (eerst opslaan, dan testen)
        out(ThreatIntel::test((string) (input()['p'] ?? '')));

    case 'ti_lists':   // lijsten nu bijwerken
        out(['msg' => ThreatIntel::updateLists()]);

    // ---------- 1.111.0: afwijkingen, ketens en MITRE ----------
    case 'ids_anom':   // Afwijkingen: leerstatus per server, ketens en afwijkingen
        $hid = (int) ($_GET['host'] ?? 0) ?: null;
        $days = (int) ($_GET['days'] ?? 7) ?: 7;
        out(['hosts' => Anomaly::hosts(), 'chains' => Chain::list(max(30, $days), $hid), 'anomalies' => Anomaly::list($days, $hid),
            'on' => Ids::enabled() && setting('edr_on', '1') === '1', 'anom' => Anomaly::on(), 'chain' => Chain::on(), 'learn_days' => Anomaly::learnDays(),
            'min' => (int) setting('edr_anom_min', '40'), 'scenarios' => array_map(static fn($s) => ['title' => t($s['title']), 'what' => t($s['what'])], Chain::scenarios())]);

    case 'ids_mitre':   // MITRE ATT&CK: wat er gezien is per tactiek en techniek, en de dekking van de regels
        $hid = (int) ($_GET['host'] ?? 0) ?: null;
        $days = (int) ($_GET['days'] ?? 30) ?: 30;
        if (isset($_GET['tech']) || isset($_GET['tactic'])) {
            out(['signals' => Chain::signals($days, $hid, ($_GET['tactic'] ?? '') ?: null, isset($_GET['tech']) ? (string) $_GET['tech'] : null)]);
        }
        out(Chain::overview($days, $hid));

    case 'edr_learn':   // leren afronden of opnieuw beginnen
        $in = input();
        $h = q('SELECT id, name FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $finish = ($in['action'] ?? '') === 'finish';
        Anomaly::relearn((int) $h['id'], $finish);
        AgentCommands::audit($user['username'], $finish ? 'Leren afgerond (afwijkingen)' : 'Opnieuw leren (afwijkingen)', (string) $h['name']);
        out(['ok' => true]);

    case 'edr_rules':   // Regels: eigen en Sigma, met aan/uit
        $off = array_flip((array) json_decode((string) setting('edr_rules_off', '[]'), true));
        $hits = array_column(q("SELECT rule, COUNT(*) AS n FROM edr_events WHERE rule IS NOT NULL GROUP BY rule")->fetchAll(), 'n', 'rule');
        $list = [];
        foreach (EdrRules::all() as $r) {
            $list[] = ['id' => $r['id'], 'src' => 'rzmon', 'title' => t($r['title']), 'what' => t($r['what']), 'sev' => $r['sev'], 'act' => $r['act'], 'tags' => $r['tags'],
                'kinds' => $r['kinds'], 'on' => !isset($off[$r['id']]), 'hits' => (int) ($hits[$r['id']] ?? 0)];
        }
        foreach (Sigma::rules() as $r) {
            $list[] = ['id' => $r['id'], 'src' => 'sigma', 'title' => $r['title'], 'what' => $r['desc'], 'sev' => $r['level'] >= 2 ? 2 : 1, 'act' => $r['level'] >= 3 ? ['kill'] : [],
                'tags' => $r['tags'], 'kinds' => $r['kinds'], 'on' => !isset($off[$r['id']]), 'hits' => (int) ($hits[$r['id']] ?? 0), 'author' => $r['author'], 'url' => $r['src'], 'level' => $r['level']];
        }
        out(['rules' => $list, 'sigma_on' => setting('edr_sigma', '1') === '1']);

    // ---------------------------------------------------------- Uitzonderingen IDS/EDR (1.132.0)
    case 'exc_list':
        $rules = [];
        foreach (EdrRules::all() as $r) {
            $rules[] = ['id' => $r['id'], 'title' => t($r['title']), 'target' => 'edr'];
        }
        foreach (Sigma::rules() as $r) {
            $rules[] = ['id' => $r['id'], 'title' => 'Sigma: ' . $r['title'], 'target' => 'edr'];
        }
        foreach (['out_country' => 'Uitgaande verbinding naar een ander land', 'outbound' => 'Uitgaande verbinding', 'webshell' => 'Mogelijke webshell',
            'rootlogin' => 'Root-login via SSH', 'newip' => 'Login vanaf een nieuw IP-adres', 'fim' => 'Bestand gewijzigd (bewaakte paden)', 'port_new' => 'Nieuwe luisterende poort',
            'user_new' => 'Nieuwe gebruiker', 'group_add' => 'Gebruiker aan een groep toegevoegd', 'revshell' => 'Reverse shell', 'kev_port' => 'Bekende kwetsbaarheid op een open poort'] as $k => $v) {
            $rules[] = ['id' => $k, 'title' => t($v), 'target' => 'ids'];
        }
        out(Exceptions::overview() + ['rules' => array_values(array_filter($rules, static fn($r) => !in_array($r['id'], Exceptions::NEVER_RULES, true)))]);

    case 'exc_proposals':
        out(['proposals' => Exceptions::proposals()]);

    case 'exc_export':
        out(Exceptions::export());

    case 'exc_save':
    case 'exc_set':
    case 'exc_add':
    case 'exc_import':
        $confirm('UITZONDEREN');   // uitzonderingen maken minder zichtbaar: altijd met je wachtwoord
        $in = input();
        try {
            out(match ($action) {
                'exc_save' => ['ok' => true, 'id' => Exceptions::save($in, $user['username'])],
                'exc_set' => ['ok' => true, 'added' => Exceptions::addSet((string) ($in['key'] ?? ''), $user['username'])],
                'exc_add' => ['ok' => true, 'added' => Exceptions::addProposals((array) ($in['ids'] ?? []), $user['username'])],
                default => ['ok' => true] + Exceptions::import(is_array($in['data'] ?? null) ? $in['data'] : (json_decode((string) ($in['data'] ?? ''), true) ?: []), $user['username']),
            });
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'exc_toggle':   // aanzetten met je wachtwoord; uitzetten mag zo (dan wordt er juist meer gemeld)
        $in = input();
        $on = !empty($in['on']);
        if ($on) {
            $confirm('UITZONDEREN');
        }
        $name = (string) q('SELECT name FROM ids_exceptions WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn();
        q('UPDATE ids_exceptions SET enabled = ? WHERE id = ?', [$on ? 1 : 0, (int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], $on ? 'Uitzondering aan' : 'Uitzondering uit', $name);
        out(['ok' => true]);

    case 'exc_delete':   // weghalen (ook een hele set): er wordt dan weer gemeld
        $in = input();
        if (!empty($in['set'])) {
            q('DELETE FROM ids_exceptions WHERE set_key = ?', [(string) $in['set']]);
            AgentCommands::audit($user['username'], 'Uitzonderingen: set weggehaald', (string) $in['set']);
        } else {
            $name = (string) q('SELECT name FROM ids_exceptions WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetchColumn();
            q('DELETE FROM ids_exceptions WHERE id = ?', [(int) ($in['id'] ?? 0)]);
            AgentCommands::audit($user['username'], 'Uitzondering weggehaald', $name);
        }
        out(['ok' => true]);

    case 'edr_rule_set':
        $in = input();
        $id = (string) ($in['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{3,64}$/', $id)) {
            out(['error' => t('Onbekende regel')], 422);
        }
        $off = (array) json_decode((string) setting('edr_rules_off', '[]'), true);
        $off = array_values(array_unique(!empty($in['on']) ? array_diff($off, [$id]) : [...$off, $id]));
        save_setting('edr_rules_off', json_encode($off));
        Edr::reset();
        out(['ok' => true]);

    case 'status_get':   // 1.107.0: statuspagina (instellingen)
        $base = rtrim((string) config('base_url', ''), '/');
        $ext = rtrim((string) config('external_url', ''), '/');
        $path = '/status.php?k=' . StatusPage::token();
        out(['on' => setting('status_on', '0') === '1', 'title' => (string) setting('status_title', ''), 'note' => (string) setting('status_note', ''),
            'items' => StatusPage::items(), 'url' => $base . $path, 'has_base' => $base !== '',
            'url_ext' => $ext !== '' ? $ext . $path : null, 'link' => setting('status_link', 'local') === 'external' && $ext !== '' ? 'external' : 'local']);

    case 'status_save':
        StatusPage::save(input());
        out(['ok' => true]);

    case 'status_link':   // 1.128.0: lokaal of extern adres in de geheime link
        $in = input();
        if (($in['link'] ?? '') === 'external' && trim((string) config('external_url', '')) === '') {
            out(['error' => t('Vul eerst de externe URL in (Instellingen → Server en PHP)')], 422);
        }
        save_setting('status_link', ($in['link'] ?? '') === 'external' ? 'external' : 'local');
        out(['ok' => true]);

    case 'status_token_new':
        StatusPage::newToken();
        out(['ok' => true]);

    case 'rev':   // 1.107.0: live bijwerken: een klein kenmerk; verandert het, dan haalt de browser de stand opnieuw op
        // 1.109.0: ook nieuwe meetwaarden (MAX(id) van results is snel: primaire sleutel), zodat waarden live meelopen
        // 1.119.0: per onderdeel een kenmerk, zodat de browser alleen herlaadt wat veranderde (de IDS meldt bijna elke
        // minuut nieuwe gebeurtenissen; daarvoor hoeven het dashboard en de meldingen niet opnieuw)
        $r = q("SELECT (SELECT MAX(id) FROM status_changes), (SELECT MAX(id) FROM change_log), (SELECT MAX(id) FROM results),
                       (SELECT COUNT(*) FROM checks WHERE acked_at IS NOT NULL), (SELECT COUNT(*) FROM checks WHERE maintenance_until > NOW()),
                       (SELECT COUNT(*) FROM checks WHERE enabled = 1), (SELECT value FROM settings WHERE name = 'cron_last_run'),
                       (SELECT MAX(id) FROM alerts), (SELECT CONCAT(COUNT(*), '.', SUM(status = 'open'), '.', COALESCE(MAX(id), 0), '.', COALESCE(MAX(last_seen), ''), '.', COALESCE(MAX(handled_at), '')) FROM ids_incidents),
                       (SELECT CONCAT(COUNT(*), '.', COALESCE(MAX(id), 0)) FROM ids_blocks WHERE removed_at IS NULL), (SELECT MAX(id) FROM edr_events)")->fetch(PDO::FETCH_NUM);
        $h = static fn(array $x) => substr(md5(implode('|', array_map('strval', $x))), 0, 10);
        out(['rev' => $h(array_slice($r, 0, 7)), 'parts' => ['a' => (string) $r[7], 'i' => $h([$r[8], $r[9]]), 'e' => (string) $r[10]]]);

    case 'smart_proposals':   // 1.107.0: handmatige checks die op slim kunnen (bestaande checks blijven handmatig tot je ze omzet)
        out(['list' => Smart::proposals(), 'default' => (string) setting('thresh_default', 'guard'), 'sens' => (string) setting('smart_sens', 'normal')]);

    case 'smart_set':
        $in = input();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
        try {
            $n = Smart::setMode($ids, (string) ($in['mode'] ?? ''));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        out(['ok' => true, 'n' => $n]);

    case 'host_exposure':
        // 1.146.0: aanvalspad en impact van één server (poorten, kwetsbaarheden, afhankelijke servers)
        $ex = HostRisk::exposure((int) ($_GET['id'] ?? 0));
        $ex ? out($ex) : out(['error' => t('Server niet gevonden')], 404);

    case 'incidents':
        // 1.142.0: één lijst met incidenten (open, in behandeling en de laatste 30 dagen opgelost)
        out(Incidents::list((int) ($_GET['days'] ?? 30)));

    case 'incident_info':
        $inc = Incidents::info((int) ($_GET['id'] ?? 0));
        $inc ? out($inc) : out(['error' => t('Incident niet gevonden')], 404);

    // ---------- 1.232.0: NIS2 ----------
    case 'nis2':
        out(Nis2::overview());

    case 'nis2_note':
        $in = input();
        try {
            Nis2::saveNote((string) ($in['code'] ?? ''), (string) ($in['note'] ?? ''), !empty($in['manual']), (string) $user['username']);
            out(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'nis2_mark':
        $in = input();
        try {
            Nis2::mark((int) ($in['id'] ?? 0), !empty($in['on']), (string) $user['username']);
            out(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'nis2_sent':
        $in = input();
        try {
            Nis2::sent((int) ($in['id'] ?? 0), (string) ($in['which'] ?? ''), !empty($in['on']), (string) $user['username']);
            out(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'nis2_report':
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        echo Nis2::html();
        exit;

    case 'incident_set':
        $in = input();
        $err = Incidents::update((int) ($in['id'] ?? 0), $user['username'],
            isset($in['status']) ? (string) $in['status'] : null, isset($in['owner']) ? trim((string) $in['owner']) : null,
            isset($in['note']) ? mb_substr((string) $in['note'], 0, 2000) : null);
        $err ? out(['error' => $err], 422) : out(['ok' => true]);

    case 'report':
        // 1.142.0: afdrukbaar rapport (als PDF te bewaren), losse HTML zonder scripts
        $p = (string) ($_GET['period'] ?? '30d');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        echo Report::html($p);
        exit;

    case 'analytics':
        $period = (string) ($_GET['period'] ?? '30d');
        if (!preg_match('/^(1d|7d|30d|90d|365d|\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2})$/', $period)) {
            $period = '30d';
        }
        out(Analytics::cached($period, !empty($_GET['fresh'])) + ['report_monthly' => Analytics::reportOn(), 'mail' => setting('email_enabled', '0') === '1' && setting('mail_to', '') !== '']);

    case 'insights':   // 1.155.0: Analyse en advies
        $r = Insights::page();
        if (!$isAdmin) {
            foreach ($r['advices'] as &$a) {
                $a['acts'] = array_values(array_filter($a['acts'], static fn($x) => in_array($x['k'], ['go', 'cve', 'ip'], true)));
            }
            unset($a);
        }
        out($r + ['admin' => $isAdmin]);

    case 'insight_analyses':
        out(Insights::analyses((int) ($_GET['days'] ?? 30)));

    case 'trends':   // 1.224.0: Analyse → Trends
        out(Trends::page(null, $isAdmin && !empty($_GET['fresh'])));

    case 'insight_set':
        $in = input();
        Insights::setStatus((string) ($in['key'] ?? ''), (string) ($in['status'] ?? ''), (string) $user['username'])
            ? out(['ok' => true]) : out(['error' => t('Ongeldig advies of status')], 422);

    case 'mds':   // 1.194.0: Mobile device summary
        out(Mds::data());

    case 'home':   // 1.134.0: het Dashboard: alles samen (monitoring, IDS, CVE's, netwerk)
        $hd = Home::data(has_role($user, 'user'), (string) ($_GET['period'] ?? '7d'), !empty($_GET['fresh']));
        if (!empty($_GET['cc'])) {   // 1.154.0: het Command center (tweede dashboard)
            $hd['cc'] = CommandCenter::data((string) ($_GET['ccp'] ?? 'today'), mb_substr((string) ($_GET['seen'] ?? ''), 0, 30));
        }
        out($hd);

    // 1.180.0: Instellingen → Rapporten. Lezen, voorbeeld en "naar mij": gebruikers; instellen en versturen: beheerders
    case 'features':   // 1.182.0
        out(['rows' => Features::rows(), 'groups' => Features::groups()]);

    case 'feature_toggle':   // 1.226.0: een functie aan of uit vanuit het overzicht (in het wijzigingslogboek via de vergelijking in api.php)
        $in = input();
        $k = (string) ($in['key'] ?? '');
        if (!in_array($k, Features::TOGGLE, true)) {
            out(['error' => t('Deze functie zet je aan of uit op zijn eigen pagina')], 422);
        }
        save_setting($k, !empty($in['on']) ? '1' : '0');
        out(['ok' => true, 'rows' => Features::rows()]);

    case 'reports_get':
        $rl = json_decode((string) setting('reports_last', ''), true) ?: [];
        $meta = [];
        foreach (Reports::LIST as $k => [$icon, $fixed]) {
            $meta[] = ['key' => $k, 'label' => Reports::label($k), 'icon' => $icon, 'fixed' => $fixed, 'last' => $rl[$k] ?? null];
        }
        $me = (string) q('SELECT email FROM users WHERE id = ?', [(int) ($user['id'] ?? 0)])->fetchColumn();
        $rcfg = Reports::cfg();
        if (!$isAdmin) {
            // 1.233.0: de ontvangers van een rapport zijn alleen voor beheerders (adressen van anderen)
            $rcfg = array_map(static fn($c) => ['to' => '', 'also' => false, 'own' => $c['to'] !== ''] + $c, $rcfg);
        }
        out(['cfg' => $rcfg, 'meta' => $meta, 'parts' => Reports::PARTS, 'admin' => $isAdmin, 'smtp' => trim((string) setting('smtp_host', '')) !== '',
            'mail' => trim((string) setting('smtp_host', '')) !== '' && trim((string) setting('mail_to', '')) !== '', 'push' => Push::enabled(), 'max_to' => Reports::MAX_TO,
            'ai' => Ai::on(), 'ids' => Ids::enabled(), 'vulns' => Vulns::enabled(), 'hibp' => Hibp::enabled(), 'nis2' => Nis2::enabled(), 'me' => $me]);

    case 'reports_save':
        try {
            out(['ok' => true, 'cfg' => Reports::save((array) (input()['cfg'] ?? []))]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'report_send':
        $rk = (string) (input()['key'] ?? '');
        $r = Reports::send($rk);
        Reports::log($rk, $r, (string) $user['username']);
        $r['ok'] ? out(['ok' => true, 'sent' => $r['sent'], 'warn' => $r['error'] ?? null]) : out(['error' => $r['error'] ?? t('Versturen mislukt')], 422);

    case 'report_me':
        $to = (string) q('SELECT email FROM users WHERE id = ?', [(int) ($user['id'] ?? 0)])->fetchColumn();
        if ($to === '') {
            out(['error' => t('Vul eerst je e-mailadres in bij Mijn account')], 422);
        }
        // 1.233.0: hooguit 10 keer per uur per account (geen mailbom vanuit een gebruikersaccount)
        $rl = array_filter(json_decode((string) setting('report_me_state', ''), true) ?: [], static fn($ts) => is_array($ts));
        $uid = (string) (int) $user['id'];
        $rl[$uid] = array_values(array_filter($rl[$uid] ?? [], static fn($ts) => (int) $ts > time() - 3600));
        if (count($rl[$uid]) >= 10) {
            out(['error' => t('Je hebt dit uur al 10 rapporten naar jezelf gestuurd. Probeer het later opnieuw.')], 429);
        }
        $rk = (string) (input()['key'] ?? '');
        $r = Reports::send($rk, $to);
        if ($r['ok']) {
            $rl[$uid][] = time();
            save_setting('report_me_state', json_encode($rl));
        }
        Reports::log($rk, $r, (string) $user['username'], $to);
        $r['ok'] ? out(['ok' => true, 'to' => $to]) : out(['error' => $r['error'] ?? t('Versturen mislukt')], 422);

    case 'report_preview':
        $k = (string) ($_GET['key'] ?? '');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        echo isset(Reports::LIST[$k]) ? I18n::forNotify(fn() => Reports::build($k))['html'] : htmlspecialchars(t('Onbekend rapport'));
        exit;

    case 'analytics_report_send':
        $m = (string) (input()['month'] ?? date('Y-m', strtotime('first day of last month')));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            out(['error' => t('Ongeldige maand')], 422);
        }
        $r = Analytics::sendMonthly($m);
        $r['ok'] ? out(['ok' => true]) : out(['error' => $r['error'] ?? t('Versturen mislukt')], 422);

    // 1.105.0: Proxmox VE – overzicht van een node (of de cluster) en losse nodes als cluster aanmerken
    case 'pve_overview':
        $o = Pve::overview((int) ($_GET['host_id'] ?? 0));
        $o ? out($o) : out(['error' => t('Niet gevonden')], 404);

    case 'pve_cluster_set':
        $in = input();
        Pve::setCluster((int) ($in['host_id'] ?? 0), (string) ($in['name'] ?? ''), $user['username']);
        out(['ok' => true]);

    // 1.104.0: UniFi en AdGuard Home testen, een apparaat weren of weer toelaten, en de domeinen van een apparaat
    case 'net_discover':   // 1.183.0: Netwerk → Ontdekken
        $devs = NetScan::list();
        out(['enabled' => NetScan::enabled(), 'events' => NetDiscover::events(30), 'presence' => NetDiscover::presenceGrid(7), 'new_per_day' => NetDiscover::newPerDay(),
            'suggestions' => NetDiscover::suggestions($devs), 'traffic' => NetTraffic::hourGrid(7) ?: (object) [], 'learn_days' => NetDiscover::LEARN_DAYS, 'gone_days' => NetDiscover::GONE_DAYS, 'adguard' => NetLinks::adguardOn()]);

    case 'net_traffic_device':   // 1.186.0: verkeer van één apparaat
        out(NetTraffic::device((int) ($_GET['id'] ?? 0)));

    case 'net_dpi':   // 1.187.0: diensten (UniFi DPI) van één apparaat
        $mac = (string) q('SELECT mac FROM net_devices WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetchColumn();
        out($mac !== '' ? NetLinks::unifiDpi($mac) : ['cats' => [], 'error' => t('Geen MAC-adres bekend')]);

    case 'net_adopt':   // 1.183.0: apparaat met één klik in RzSense, met de gekozen checks
        $in = input();
        try {
            $hid = NetDiscover::adopt((int) ($in['id'] ?? 0), (array) ($in['checks'] ?? []), (string) ($in['name'] ?? ''), (string) $user['username']);
            out(['ok' => true, 'host_id' => $hid]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    // 1.189.0: stille-urenprofielen
    case 'quiet_get':
        out(Quiet::overview() + ['kinds' => HostKind::GROUPS]);

    case 'quiet_profile_save':
        $in = input();
        try {
            out(['ok' => true, 'id' => Quiet::saveProfile(!empty($in['id']) ? (int) $in['id'] : null, $in)]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'quiet_profile_delete':
        Quiet::deleteProfile((int) (input()['id'] ?? 0));
        out(['ok' => true]);

    case 'quiet_global_save':
        $gid = (int) (input()['id'] ?? 0);
        save_setting('quiet_global', $gid && isset(Quiet::profiles()[$gid]) ? (string) $gid : '');
        Quiet::reset();
        out(['ok' => true]);

    case 'quiet_group_save':
        $in = input();
        try {
            Quiet::setGroup((string) ($in['key'] ?? ''), $in['val'] ?? null);
            out(['ok' => true]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'quiet_now':   // Nu stil: minuten vanaf nu, of tot een tijdstip; 0 = opheffen
        $in = input();
        $what = ($in['what'] ?? '') === 'host' ? 'host' : 'check';
        $until = !empty($in['until']) ? (int) strtotime((string) $in['until']) : (!empty($in['minutes']) ? time() + 60 * max(1, min(44640, (int) $in['minutes'])) : null);
        Quiet::setNow($what, (int) ($in['id'] ?? 0), $until ?: null);
        out(['ok' => true]);

    // 1.193.0: het advies voor bevestigen en melden in één keer (komt in het wijzigingslogboek, terug te draaien)
    case 'notify_advice_apply':
        $g = NotifyRules::global()['rules'];
        $g['crit'] = ['mode' => 'now', 'min' => 0, 'repeat' => (int) ($g['crit']['repeat'] ?? 0)];
        $g['warn'] = ['mode' => 'after', 'min' => 10, 'repeat' => (int) ($g['warn']['repeat'] ?? 0)];
        $g['unknown'] = ['mode' => 'after', 'min' => 15, 'repeat' => (int) ($g['unknown']['repeat'] ?? 0)];
        $g['recovery'] = ['mode' => 'now', 'min' => 0, 'repeat' => 0];
        save_setting('notify_rules', json_encode(NotifyRules::cleanRules($g)));
        save_setting('notify_fails', '1');
        $ct = Monitor::confirmTypes();
        foreach (Monitor::SLOW_CONFIRM_TYPES as $t) {
            $ct[$t] = ['f' => '1', 'o' => $ct[$t]['o'] ?? ''];
        }
        save_setting('confirm_types', json_encode(Monitor::cleanConfirmTypes($ct), JSON_FORCE_OBJECT));
        out(['ok' => true]);

    case 'net_links_test':
        out(NetLinks::test());

    case 'net_block':
        $in = input();
        $confirm('WEREN');
        $r = NetScan::block((int) ($in['id'] ?? 0), ['unifi' => !empty($in['unifi']), 'adguard' => !empty($in['adguard']), 'rzmon' => !empty($in['rzmon'])],
            max(0, min(3650, (int) ($in['days'] ?? 1))), (string) ($in['reason'] ?? ''), $user['username'], client_ip());
        $r['ok'] ? out($r) : out(['error' => implode(' · ', $r['errors']) ?: t('Niets gekozen om mee te weren')], 422);

    case 'net_unblock':
        $r = NetScan::unblock((int) (input()['id'] ?? 0), $user['username']);
        out($r);

    case 'net_domains':
        $d = q('SELECT ip FROM net_devices WHERE id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$d) {
            out(['error' => t('Niet gevonden')], 404);
        }
        if (!NetLinks::adguardOn()) {
            out(['error' => t('AdGuard Home is niet ingesteld (Instellingen → Koppelingen → AdGuard Home)')], 422);
        }
        out(NetLinks::adguardDomains((string) $d['ip']));

    case 'net_device_save':
        $in = input();
        NetScan::save((int) ($in['id'] ?? 0), $in, $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'net_approve_all':
        out(['ok' => true, 'n' => NetScan::approveAll($user['username'])]);

    case 'net_forget':
        NetScan::forget((int) (input()['id'] ?? 0), $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'net_scan_now':
        if (!NetScan::networks()['scan']) {
            out(['error' => t('Geen netwerk om te scannen: zet je netwerk (bv. 192.168.1.0/24) bij Toegang → Lokale netwerken')], 422);
        }
        @set_time_limit(120);
        $msg = NetScan::sweep();
        if (!empty(input()['deep'])) {
            $msg .= ' · ' . NetScan::deep();
        }
        out(['ok' => true, 'message' => $msg]);

    // 1.102.0: apparaten die van buiten geweigerd werden (wachtende apparaten)
    case 'device_request_approve':
        $in = input();
        $confirm('TOESTAAN');
        ($err = Stealth::approveRequest((int) ($in['id'] ?? 0), (int) ($in['user_id'] ?? 0), (string) ($in['name'] ?? ''), $user['username'])) === null
            ? out(['ok' => true]) : out(['error' => $err], 404);

    case 'device_request_ignore':
        Stealth::ignoreRequest((int) (input()['id'] ?? 0), $user['username']) ? out(['ok' => true]) : out(['error' => t('Niet gevonden')], 404);

    case 'stealth_save':
        $in = input();
        $map = ['hide' => 'stealth_hide', 'ui' => 'stealth_ui', 'countries' => 'stealth_countries', 'door' => 'stealth_door', 'passkeys' => 'passkeys_enabled', 'requests' => 'stealth_requests'];
        // Minder veilig maken (niet herkenbaar of de webinterface op slot uitzetten): eerst bevestigen
        foreach (['hide', 'ui'] as $k) {
            if (array_key_exists($k, $in) && empty($in[$k]) && setting($map[$k], $k === 'hide' ? '1' : '0') === '1') {
                $confirm('UITZETTEN');
            }
        }
        foreach ($map as $k => $name) {
            if (array_key_exists($k, $in)) {
                save_setting($name, !empty($in[$k]) ? '1' : '0');
                AgentCommands::audit($user['username'], 'Onzichtbaar van buiten', "$name = " . (!empty($in[$k]) ? 'aan' : 'uit'));
            }
        }
        out(['ok' => true]);

    case 'my_logins':
        out(!empty($user['id']) && !is_lan_session() ? Security::loginHistory((int) $user['id']) : []);

    case 'user_unlock':
        Security::unlockUser((int) (input()['id'] ?? 0), $user['username']);
        out(['ok' => true]);

    case 'user_create':
        $in = input();
        $email = trim((string) ($in['email'] ?? ''));
        try {
            if (q('SELECT 1 FROM users WHERE username = ?', [trim((string) ($in['username'] ?? ''))])->fetchColumn()) {
                throw new InvalidArgumentException(t('Deze gebruikersnaam bestaat al'));
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(t('Ongeldig e-mailadres'));
            }
            if ($email !== '' && q('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)', [$email])->fetchColumn()) {
                throw new InvalidArgumentException(t('Dit e-mailadres hoort al bij een ander account'));
            }
            Installer::saveUser(trim((string) ($in['username'] ?? '')), (string) ($in['password'] ?? ''),
                in_array($in['role'] ?? '', array_keys(ROLES), true) ? $in['role'] : 'user');
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }
        $id = (int) q('SELECT id FROM users WHERE username = ?', [trim((string) ($in['username'] ?? ''))])->fetchColumn();
        if ($email !== '') {
            q('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
        }
        out(['ok' => true, 'id' => $id, 'pwned' => Hibp::checkUser($id, (string) ($in['password'] ?? '')) ?? 0]);

    case 'user_password':
        $in = input();
        if (strlen((string) ($in['password'] ?? '')) < 8) {
            out(['error' => t('Wachtwoord moet minimaal 8 tekens zijn')], 422);
        }
        q('UPDATE users SET password_hash = ?, pw_changed_at = ?, pw_must_change = 0 WHERE id = ?', [hash_password((string) $in['password']), now(), (int) ($in['id'] ?? 0)]);
        if ((int) ($in['id'] ?? 0) === $user['id']) {
            $_SESSION['auth_at'] = time();   // eigen account: deze sessie blijft
        }
        // Nieuw wachtwoord gezet door een beheerder: sessies en onthouden apparaten van die gebruiker weg (je eigen sessie blijft)
        session_logout('user', (int) ($in['id'] ?? 0));
        out(['ok' => true, 'pwned' => Hibp::checkUser((int) ($in['id'] ?? 0), (string) $in['password']) ?? 0]);

    case 'user_email':
        // E-mailadres voor "wachtwoord vergeten" (beheerder: iedereen; anderen: alleen zichzelf)
        $id = (int) (input()['id'] ?? 0);
        $ownOnly($id);
        $email = trim((string) (input()['email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            out(['error' => t('Ongeldig e-mailadres')], 422);
        }
        if ($email !== '' && q('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?) AND id <> ?', [$email, $id])->fetchColumn()) {
            out(['error' => t('Dit e-mailadres hoort al bij een ander account')], 422);
        }
        // 1.224.1: met je wachtwoord, want via dit adres kun je een nieuw wachtwoord krijgen. Het oude adres krijgt bericht.
        $confirm('ACCOUNT');
        $old = q('SELECT username, email FROM users WHERE id = ?', [$id])->fetch();
        q('UPDATE users SET email = ? WHERE id = ?', [$email !== '' ? $email : null, $id]);
        if ($old && (string) $old['email'] !== '' && strcasecmp((string) $old['email'], $email) !== 0 && setting('email_enabled', '0') === '1') {
            try {
                Mailer::send(I18n::forNotify(fn() => t('RzSense: e-mailadres gewijzigd')), I18n::forNotify(fn() => t('Het e-mailadres van {user} in RzSense is gewijzigd door {by}. Was dit niet de bedoeling? Kijk dan meteen bij Instellingen → Gebruikers.',
                    ['user' => $old['username'], 'by' => $user['username']])), null, (string) $old['email']);
            } catch (Throwable) {
            }
        }
        out(['ok' => true]);

    case 'pw_reset_info':
        out(['unavailable' => PasswordReset::unavailable(),
             'log' => q('SELECT l.created_at, l.ident, l.ip, l.event, u.username FROM password_reset_log l
                         LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 50')->fetchAll()]);

    case 'user_role':
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $role = (string) ($in['role'] ?? '');
        if (!isset(ROLES[$role])) {
            out(['error' => t('Onbekende rol')], 422);
        }
        if ($id === $user['id']) {
            out(['error' => t('Je kunt je eigen rol niet wijzigen (laat een andere beheerder dat doen)')], 422);
        }
        if ($role !== 'admin' && q("SELECT role FROM users WHERE id = ?", [$id])->fetchColumn() === 'admin'
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            out(['error' => t('Er moet minimaal één beheerder overblijven')], 422);
        }
        if ($role !== 'admin' && !(int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0 AND id <> ?", [$id])->fetchColumn()
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0")->fetchColumn()) {
            out(['error' => t('Er moet minstens één beheerder overblijven die ook van buiten mag inloggen')], 422);
        }
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        out(['ok' => true]);

    case 'user_delete':
        $id = (int) (input()['id'] ?? 0);
        if ($id === $user['id']) {
            out(['error' => t('Je kunt jezelf niet verwijderen')], 422);
        }
        if ((int) q('SELECT COUNT(*) FROM users')->fetchColumn() <= 1) {
            out(['error' => t('Er moet minimaal één gebruiker overblijven')], 422);
        }
        if (q("SELECT role FROM users WHERE id = ?", [$id])->fetchColumn() === 'admin'
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
            out(['error' => t('Er moet minimaal één beheerder overblijven')], 422);
        }
        if (!(int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0 AND id <> ?", [$id])->fetchColumn()
            && (int) q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND local_only = 0")->fetchColumn()) {
            out(['error' => t('Er moet minstens één beheerder overblijven die ook van buiten mag inloggen')], 422);
        }
        q('DELETE FROM users WHERE id = ?', [$id]);
        out(['ok' => true]);

    case 'totp_setup':
        // Stap 1: nieuw geheim (nog niet actief) → QR-code scannen
        $ownOnly((int) (input()['id'] ?? 0));
        $u = q('SELECT id, username FROM users WHERE id = ?', [(int) (input()['id'] ?? 0)])->fetch();
        if (!$u) {
            out(['error' => t('Gebruiker niet gevonden')], 404);
        }
        $secret = Totp::newSecret();
        $_SESSION['totp_setup'][(int) $u['id']] = $secret;
        out(['ok' => true, 'secret' => $secret, 'uri' => Totp::uri($secret, $u['username'])]);

    case 'totp_confirm':
        // Stap 2: pas actief na een geldige code, zodat je jezelf niet buitensluit
        $in = input();
        $id = (int) ($in['id'] ?? 0);
        $ownOnly($id);
        $confirm('ACCOUNT');   // 1.224.1: 2FA vervangen alleen met je wachtwoord
        $secret = $_SESSION['totp_setup'][$id] ?? null;
        if (!$secret) {
            out(['error' => t('Begin opnieuw met instellen')], 422);
        }
        $step = Totp::verify($secret, (string) ($in['code'] ?? ''));
        if ($step === null) {
            out(['error' => t('Code klopt niet. Staat de klok van je telefoon goed?')], 422);
        }
        q('UPDATE users SET totp_secret = ?, totp_last_step = ? WHERE id = ?', [$secret, $step, $id]);
        unset($_SESSION['totp_setup'][$id]);
        out(['ok' => true]);
}
