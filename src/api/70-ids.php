<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: IDS, kwetsbaarheden, hardening, tijdlijn per IP.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var array<string, mixed> $user
 * @var bool $isAdmin
 * @var callable(string): void $confirm
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------------------------------------------------------- IDS
    case 'ids':
        // 1.119.0: standaard de laatste 14 dagen (plus alles wat nog open staat); ouder op verzoek
        $maxDays = Monitor::alertDays();
        $days = max(1, min($maxDays, (int) ($_GET['days'] ?? $maxDays)));
        $hosts = q("SELECT h.id, h.name, h.agent_seen, h.agent_update_via = 'ha' AS ha, h.ids_fim, h.realtime,
                           (SELECT value FROM agent_data ad WHERE ad.host_id = h.id AND ad.metric = 'realtime') AS rt,
                           (SELECT value FROM agent_data ad WHERE ad.host_id = h.id AND ad.metric = 'tools') AS tools, s.fim_count, s.fim_cand, s.baseline_until, s.fw_state, s.fw_error, s.decoy_state, s.last_report
                    FROM hosts h LEFT JOIN ids_state s ON s.host_id = h.id WHERE h.mode = 'agent' AND h.enabled = 1 ORDER BY h.name")->fetchAll();
        Perf::part('ids:servers');   // 1.220.0: per onderdeel meten (Instellingen → Prestaties en fouten)
        $open = q("SELECT host_id, severity, COUNT(*) AS n FROM ids_incidents WHERE status = 'open' GROUP BY host_id, severity")->fetchAll();
        $blocks = Ids::activeBlocks();
        Perf::part('ids:blokkades');
        // 1.220.0: hardening verandert niet per seconde: één minuut bewaren
        $hard = Hardening::enabled() ? memo('ids-hardening', 60, static fn() => Hardening::scores()) : [];
        $hard = array_combine(array_map('intval', array_keys($hard)), array_values($hard)) ?: [];
        Perf::part('ids:hardening');
        $iso = q('SELECT id, isolate_until, isolate_by, isolate_state FROM hosts')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($hosts as &$h) {
            $h['hard'] = $hard[(int) $h['id']] ?? null;
            $h['iso'] = ($iso[$h['id']]['isolate_until'] ?? null) ? $iso[$h['id']] : (str_starts_with((string) ($iso[$h['id']]['isolate_state'] ?? ''), 'fout') ? $iso[$h['id']] : null);
            $h['open'] = [1 => 0, 2 => 0];
            foreach ($open as $o) {
                if ((int) $o['host_id'] === (int) $h['id']) {
                    $h['open'][(int) $o['severity']] = (int) $o['n'];
                }
            }
            $h['blocks'] = count(array_filter($blocks, fn($b) => (int) $b['host_id'] === (int) $h['id']));
            $h['ha'] = (bool) $h['ha'];   // Home Assistant-add-on: geen IDS mogelijk (container)
            $h['learning'] = $h['baseline_until'] !== null && strtotime($h['baseline_until']) > time();
        }
        unset($h);
        out([
            'enabled' => Ids::enabled(), 'mode' => (string) setting('ids_mode', 'learn'), 'today' => date('Y-m-d'), 'days' => $days, 'max_days' => $maxDays,
            'modern' => setting('ids_modern', '1') === '1',   // 1.117.0: moderne weergave (anders compact)
            'fim' => Ids::cleanFim(json_decode((string) setting('ids_fim', '[]'), true)),
            'hosts' => $hosts,
            // 1.217.0: open en de periode apart (dan gebruikt de database zijn indexen); van afgehandelde incidenten een kortere
            // omschrijving (het hele verhaal staat in het venster) en "wat RzSense deed" alleen voor open en recente incidenten
            // 1.220.0: van afgehandelde incidenten ouder dan 2 dagen alleen het begin van de omschrijving (het venster haalt de rest op)
            'incidents' => $incs = (static function () use ($days): array {
                $cols = "i.id, i.host_id, i.severity, i.type, i.dkey, i.title, IF(i.status = 'open' OR i.created_at >= NOW() - INTERVAL 2 DAY, i.detail, LEFT(i.detail, 240)) AS detail,
                         i.ip, i.hits, i.status, i.handled_by, i.handled_at, i.created_at, i.last_seen, i.cc, i.mode, i.dport, i.proto, i.service, i.dir, h.name AS host_name";
                $r = q("SELECT * FROM (
                    SELECT $cols FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.status = 'open'
                    UNION SELECT $cols FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.created_at >= CURDATE() - INTERVAL ? DAY
                ) x ORDER BY x.created_at DESC, x.id DESC LIMIT 5000", [$days - 1])->fetchAll();
                Perf::part('ids:incidenten');
                return $r;
            })(),
            // 1.217.0: de grafiek en de staafjes per server (14 dagen) uit tellingen, zodat de lijst zelf kort kan blijven
            'perday' => (static function (): array {
                $r = memo('ids-perday', 60, static fn() => q("SELECT DATE(created_at) AS d, host_id, type, severity, COUNT(*) AS n FROM ids_incidents
                           WHERE created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at), host_id, type, severity")->fetchAll());
                Perf::part('ids:per-dag');
                return $r;
            })(),
            'prot' => (static function (array $l): array { $r = Protect::describeAll($l); Perf::part('ids:wat-rzsense-deed'); return $r; })(array_values(array_filter($incs, static fn($i) => $i['status'] === 'open' || strtotime((string) $i['created_at']) >= time() - 2 * 86400))),
            'blocks' => Ids::readable($blocks),
            'removed' => Ids::readable(q('SELECT b.*, h.name AS host_name FROM ids_blocks b JOIN hosts h ON h.id = b.host_id
                            WHERE b.removed_at IS NOT NULL OR (b.until IS NOT NULL AND b.until <= NOW()) ORDER BY COALESCE(b.removed_at, b.until) DESC LIMIT 30')->fetchAll()),
            'my_ip' => client_ip(),
            // 1.166.0: wat het IDS de laatste 24 uur deed
            'blocked24' => (int) q('SELECT COUNT(*) FROM ids_blocks WHERE created_at >= NOW() - INTERVAL 1 DAY')->fetchColumn(),
            'top24' => (static function () use ($hosts): array {
                $r = memo('ids-top24', 60, static fn() => ids_top_attackers($hosts));
                Perf::part('ids:aanvallers');
                Perf::timingHeader();
                return $r;
            })(),
        ]);

    // Toegang: logins, sudo en su (zonder wat inmiddels op de lijst Negeren staat)
    // ---------- Kwetsbaarheden (beheerders en gebruikers; bijwerken en scannen alleen beheerders) ----------
    case 'ransom_info':   // 1.228.0: ransomware per server (Agent Manager)
        out(Ransom::info((int) ($_GET['host_id'] ?? 0)));

    case 'ransom_overview':
        out(['on' => Ransom::enabled(), 'min' => (int) setting('ransom_min', '300'), 'hosts' => Ransom::overview()]);

    case 'ransom_save':
        $in = input();
        $hid = (int) ($in['host_id'] ?? 0);
        $hr = q("SELECT id, ransom_kill FROM hosts WHERE id = ? AND mode = 'agent'", [$hid])->fetch();
        if (!$hr) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        if (!empty($in['kill']) && !(int) $hr['ransom_kill']) {
            $confirm('STOPPEN');   // processen laten stoppen door de agent: met je wachtwoord
        }
        try {
            out(Ransom::save($hid, $in, (string) $user['username']));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    // ---------- 1.231.0: tijdelijk sudo (agent 1.93.0) ----------
    case 'sudo_list':
        $hid = (int) ($_GET['host_id'] ?? 0);
        if ($hid) {
            $h = q('SELECT * FROM hosts WHERE id = ?', [$hid])->fetch();
            out($h ? Sudo::info($h) : ['error' => t('Server niet gevonden')], $h ? 200 : 404);
        }
        out(['on' => Sudo::enabled(), 'max' => Sudo::maxMinutes(), 'grants' => Sudo::list()]);

    case 'sudo_grant':
        $in = input();
        $h = q("SELECT * FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $confirm('SUDO');   // altijd je wachtwoord (of thuis het woord), en daarna je 2FA-code als je die hebt
        $me = q('SELECT totp_secret, totp_last_step FROM users WHERE id = ?', [$user['id']])->fetch();
        if ($me && $me['totp_secret']) {
            $step = Totp::verify((string) $me['totp_secret'], preg_replace('/\D/', '', (string) ($in['totp'] ?? '')) ?? '', $me['totp_last_step'] !== null ? (int) $me['totp_last_step'] : null);
            if ($step === null) {
                usleep(500000);
                out(['error' => t('De 2FA-code klopt niet'), 'need_totp' => true], 422);
            }
            q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
        }
        try {
            out(['ok' => true, 'id' => Sudo::grant($h, (string) ($in['user'] ?? ''), (int) ($in['minutes'] ?? 30), (string) ($in['reason'] ?? ''), (string) $user['username'])]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'sudo_revoke':
        Sudo::revoke((int) (input()['id'] ?? 0), (string) $user['username']);
        out(['ok' => true]);

    // ---------- 1.231.0: AdGuard-blokkadelijst ----------
    case 'dnsbl_status':
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = rtrim((string) setting('base_url', ''), '/') ?: $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php')), '/');
        out(DnsList::status() + ['url' => $base . '/dnsbl.php?t=' . DnsList::token()]);

    case 'dnsbl_token_new':
        $confirm('VERNIEUWEN');
        DnsList::newToken((string) $user['username']);
        out(['ok' => true]);

    case 'dnsbl_adguard_add':
        $in = input();
        $url = (string) ($in['url'] ?? '');
        if (!preg_match('#^https?://[^\s]+/dnsbl\.php\?t=[0-9a-f]{40}$#', $url) || !str_ends_with($url, DnsList::token())) {
            out(['error' => t('Ongeldige URL')], 422);
        }
        out(['results' => DnsList::addToAdguard($url, (string) $user['username'])]);

    // ---------- 1.230.0: updates installeren (patchbeheer, agent 1.92.0) ----------
    case 'patch':
        out(Patch::overview());

    case 'patch_save':
        $in = input();
        try {
            out(Patch::save((int) ($in['host_id'] ?? 0), $in, (string) $user['username']));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'patch_now':
        $in = input();
        $h = q("SELECT * FROM hosts WHERE id = ? AND mode = 'agent'", [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $confirm('BIJWERKEN');
        $r = Patch::request($h, (string) ($in['scope'] ?? ''), (string) $user['username']);
        if ($r !== true) {
            out(['error' => $r], 422);
        }
        out(['ok' => true]);

    // ---------- 1.229.0: onbekende programma's (agent 1.91.0) ----------
    case 'apps':
        out(AppControl::list($_GET));

    case 'apps_info':
        out(AppControl::info((int) ($_GET['host_id'] ?? 0)));

    case 'apps_decide':
        $in = input();
        try {
            $n = AppControl::decide((string) ($in['action'] ?? ''), (string) ($in['sha'] ?? ''), (string) ($in['path'] ?? ''), (int) ($in['host_id'] ?? 0),
                trim((string) ($in['note'] ?? '')), (string) $user['username']);
            out(['ok' => true, 'n' => $n]);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'apps_rule_delete':
        AppControl::deleteRule((int) (input()['id'] ?? 0), (string) $user['username']);
        out(['ok' => true]);

    case 'apps_host_save':
        $in = input();
        $hid = (int) ($in['host_id'] ?? 0);
        $hr = q("SELECT id, appctl_kill FROM hosts WHERE id = ? AND mode = 'agent'", [$hid])->fetch();
        if (!$hr) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        if (!empty($in['kill']) && !(int) $hr['appctl_kill']) {
            $confirm('STOPPEN');   // geblokkeerde programma's laten stoppen door de agent: met je wachtwoord
        }
        out(AppControl::saveHost($hid, !empty($in['on']), !empty($in['kill']), (string) $user['username']));

    // ---------- 1.229.0: jagen op sporen (IOC) ----------
    case 'ioc_hunt':
        $in = input();
        try {
            out(Ioc::hunt((string) ($in['text'] ?? ''), (int) ($in['days'] ?? 30), !empty($in['agents']), (string) $user['username']));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'ioc_get':
        try {
            out(Ioc::get((int) ($_GET['id'] ?? 0)));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 404);
        }

    case 'ioc_hunts':
        out(['on' => Ioc::enabled(), 'hunts' => Ioc::hunts(), 'watch' => Ioc::watchList()]);

    case 'ioc_watch_add':
        $in = input();
        out(Ioc::watchAdd((string) ($in['text'] ?? ''), trim((string) ($in['note'] ?? '')), (string) $user['username']));

    case 'ioc_watch_delete':
        out(['n' => Ioc::watchDelete((array) (input()['ids'] ?? []), (string) $user['username'])]);

    case 'eol':   // 1.227.0: end-of-life per server
        out(Eol::overview());

    case 'eol_refresh':
        save_setting('eol_last', '0');
        Eol::daily();
        out(Eol::overview());

    case 'vulns':
        out(Vulns::enabled() ? Vulns::overview() : ['enabled' => false]);

    case 'vuln_packages':
        out(['packages' => Vulns::packages((int) ($_GET['host_id'] ?? 0))]);

    // Voortgang van "bijwerken" op één server (het venster vraagt dit elke paar seconden)
    case 'vuln_upgrade_status':
        $h = q('SELECT h.agent_version, h.vuln_upgrade_status, i.scanned_at, i.updated_at FROM hosts h LEFT JOIN vuln_inventory i ON i.host_id = h.id WHERE h.id = ?',
            [(int) ($_GET['host_id'] ?? 0)])->fetch();
        $h ? out(['status' => json_decode((string) $h['vuln_upgrade_status'], true), 'scanned_at' => $h['scanned_at'], 'updated_at' => $h['updated_at'],
                  'live' => $h['agent_version'] && version_compare((string) $h['agent_version'], Vulns::PROGRESS_MIN, '>=')])
           : out(['error' => t('Server niet gevonden')], 404);

    case 'vuln_detail':
        $d = Vulns::detail(mb_substr(trim((string) ($_GET['cve'] ?? '')), 0, 80));
        $d ? out($d + ['buzz' => CveBuzz::info($d['cve'])]) : out(['error' => t('Kwetsbaarheid niet gevonden')], 404);   // 1.179.0: rugbaarheid erbij

    case 'vuln_ignore':
        $in = input();
        $cve = mb_substr(trim((string) ($in['cve'] ?? '')), 0, 40);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]+$/', $cve)) {
            out(['error' => t('Ongeldige kwetsbaarheid')], 422);
        }
        $pkg = trim((string) ($in['package'] ?? '')) ?: null;
        $hid = (int) ($in['host_id'] ?? 0) ?: null;
        $reason = mb_substr(trim((string) ($in['reason'] ?? '')), 0, 300);
        if ($reason === '') {
            out(['error' => t('Vul een reden in: waarom is dit risico acceptabel?')], 422);
        }
        // 1.141.0: tot een datum (daarna telt hij weer mee), of voor altijd
        $until = trim((string) ($in['until'] ?? ''));
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || !strtotime($until) || $until < date('Y-m-d'))) {
            out(['error' => t('Kies een datum vanaf vandaag, of laat hem leeg (voor altijd)')], 422);
        }
        q('INSERT INTO vuln_ignore (cve, package, host_id, reason, until, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$cve, $pkg !== null ? mb_substr($pkg, 0, 150) : null, $hid, $reason, $until ?: null, $user['username'], now()]);
        AgentCommands::audit($user['username'], 'Kwetsbaarheid: risico geaccepteerd', $cve . ($pkg ? " · $pkg" : '') . ($hid ? " · server $hid" : ' · alle servers')
            . ($until ? " · tot $until" : ''));
        out(['ok' => true]);

    case 'vuln_sbom':
        // 1.141.0: softwarelijst + kwetsbaarheden van één server als CycloneDX (JSON-bestand)
        $sb = Vulns::sbom((int) ($_GET['host_id'] ?? 0));
        if (!$sb) {
            out(['error' => t('Geen softwarelijst voor deze server')], 404);
        }
        $fn = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $sb['metadata']['component']['name']) ?: 'server';
        header('Content-Type: application/vnd.cyclonedx+json');
        header('Content-Disposition: attachment; filename="sbom-' . $fn . '-' . date('Y-m-d') . '.cdx.json"');
        echo json_encode($sb, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    case 'vuln_unignore':
        $in = input();
        q('DELETE FROM vuln_ignore WHERE id = ?', [(int) ($in['id'] ?? 0)]);
        AgentCommands::audit($user['username'], 'Kwetsbaarheid: niet meer genegeerd', '#' . (int) ($in['id'] ?? 0));
        out(['ok' => true]);

    // Kwetsbaarheden aan of uit (Instellingen → Beveiliging → Kwetsbaarheden); aan = meteen de softwarelijsten laten (her)sturen en scannen
    case 'vuln_set':
        $on = !empty(input()['enabled']);
        save_setting('vuln_enabled', $on ? '1' : '0');
        AgentCommands::audit($user['username'], 'Kwetsbaarheden', $on ? 'aan' : 'uit');
        out(['ok' => true, 'enabled' => $on]);

    case 'vuln_scan':
        if (!Vulns::enabled()) {
            out(['error' => t('Kwetsbaarheden staat uit (Instellingen → Beveiliging → Kwetsbaarheden)')], 422);
        }
        session_write_close();
        q('UPDATE vuln_inventory SET scanned_at = NULL');
        $msg = Vulns::cron(25);
        out(['ok' => true, 'message' => $msg, 'pending' => (int) q('SELECT COUNT(*) FROM vuln_inventory WHERE scanned_at IS NULL')->fetchColumn()]);

    case 'vuln_upgrade':
        $in = input();
        $h = q('SELECT id, name, mode, agent_version, agent_update_via, commands FROM hosts WHERE id = ?', [(int) ($in['host_id'] ?? 0)])->fetch();
        if (!$h) {
            out(['error' => t('Server niet gevonden')], 404);
        }
        $confirm('BIJWERKEN');
        $inv = q('SELECT mgr, packages FROM vuln_inventory WHERE host_id = ?', [$h['id']])->fetch();
        // Welke pakketten: de meegegeven bevindingen, of alles waarvoor een oplossing is
        $names = !empty($in['all'])
            ? q('SELECT DISTINCT package FROM vuln_findings WHERE host_id = ? AND fixed_version IS NOT NULL', [$h['id']])->fetchAll(PDO::FETCH_COLUMN)
            : array_map('strval', array_slice((array) ($in['packages'] ?? []), 0, 200));
        $bins = $inv ? Vulns::binaries((array) json_decode((string) $inv['packages'], true), (string) $inv['mgr'], $names) : [];
        $r = Vulns::requestUpgrade($h, $bins, $user['username']);
        if ($r !== true) {
            out(['error' => $r, 'command' => $inv && $bins ? Vulns::upgradeCommand((string) $inv['mgr'], $bins) : null], 422);
        }
        AgentCommands::audit($user['username'], 'Pakketten bijwerken gevraagd', "{$h['name']}: " . implode(' ', $bins));
        out(['ok' => true, 'packages' => $bins]);

    case 'ids_access':
        $days = Monitor::alertDays();
        $rows = q('SELECT a.id, a.host_id, a.kind, a.type, a.title, a.message, a.detail, a.created_at, h.name AS host_name
                   FROM access_log a JOIN hosts h ON h.id = a.host_id
                   WHERE a.created_at >= CURDATE() - INTERVAL ? DAY ORDER BY a.created_at DESC, a.id DESC LIMIT 10000', [$days - 1])->fetchAll();
        out(['enabled' => AuthEvents::enabled(), 'days' => $days, 'today' => date('Y-m-d'), 'truncated' => count($rows) >= 10000,
             'rows' => array_values(array_filter($rows, fn($r) => !AuthEvents::rowIgnored($r)))]);

    // Toegang: gebruiker of IP-adres voortaan niet meer tonen
    case 'ids_ignore':
        $add = array_filter(parse_list(mb_substr((string) (input()['add'] ?? ''), 0, 300)),
            fn($x) => preg_match('/^[A-Za-z0-9._@\\$-]{1,64}$/', $x) || @inet_pton(explode('/', $x)[0]) !== false);
        if (!$add) {
            out(['error' => t('Niets om te negeren')], 422);
        }
        $list = array_values(array_unique(array_merge(parse_list((string) setting('auth_ignore', '')), $add)));
        save_setting('auth_ignore', implode(' ', array_slice($list, 0, 100)));
        AgentCommands::audit($user['username'], 'IDS: toegang negeren', implode(' ', $add));
        out(['ok' => true, 'ignore' => setting('auth_ignore', '')]);

    case 'ids_set':
        $in = input();
        if (array_key_exists('enabled', $in)) {
            $on = !empty($in['enabled']);
            save_setting('ids_enabled', $on ? '1' : '0');
            AgentCommands::audit($user['username'], 'IDS', $on ? 'aangezet' : 'uitgezet');
        }
        if (isset($in['mode'])) {
            $mode = $in['mode'] === 'block' ? 'block' : 'learn';
            if ($mode === 'block' && setting('ids_mode', 'learn') !== 'block') {
                $confirm('BLOKKEREN');
            }
            save_setting('ids_mode', $mode);
            AgentCommands::audit($user['username'], 'IDS', $mode === 'block' ? 'modus blokkeren' : 'modus leren (alleen melden)');
        }
        out(['ok' => true]);

    // Bekende aanvallers en land per IP (1.82.0): stand, en nu bijwerken
    case 'ipintel_status':
        out(IpIntel::status());

    case 'ipintel_update':
        @set_time_limit(300);
        session_write_close();
        $msg = [IpIntel::updateBlocklists()];
        if (!empty(input()['geo'])) {
            $msg[] = IpIntel::updateGeo();
        }
        out(['ok' => true, 'result' => implode(' · ', $msg)] + IpIntel::status());

    // Wekelijks IDS-overzicht nu versturen (1.81.0), om te zien hoe het eruitziet
    case 'ids_weekly_test':
        $via = (string) setting('ids_weekly', 'email');
        out(['ok' => true, 'result' => Ids::sendWeekly($via === 'off' ? 'both' : $via), 'text' => Ids::weeklyText()[1]]);

    case 'ids_incident':
        $in = input();
        $act = (string) ($in['action'] ?? '');
        // 1.217.0: een bundel of een keuze in één keer (ids), in plaats van een aanvraag per incident
        if (isset($in['ids']) && is_array($in['ids'])) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $in['ids'])))), 0, 2000);
            if (!$ids) {
                out(['error' => t('Incident niet gevonden')], 404);
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($act === 'reopen') {
                $n = q("UPDATE ids_incidents SET status = 'open', handled_by = NULL, handled_at = NULL WHERE id IN ($ph)", $ids)->rowCount();
            } else {
                $n = q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE id IN ($ph) AND status = 'open'", array_merge([$user['username']], $ids))->rowCount();
                if ($act === 'normal') {
                    $incs = q("SELECT id, host_id, type, dkey, title FROM ids_incidents WHERE id IN ($ph)", $ids)->fetchAll();
                    foreach ($incs as $inc) {
                        Triage::fromUser($inc, 'benign', (string) $user['username']);   // 1.223.0
                        q("INSERT IGNORE INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'ignore', ?, ?)",
                            [$inc['host_id'], mb_substr($inc['type'] . '|' . $inc['dkey'], 0, 255), mb_substr($user['username'], 0, 255)]);
                        q("UPDATE ids_kills SET done_at = NOW(), result = ? WHERE host_id = ? AND dkey = ? AND done_at IS NULL", [t('vrijgegeven'), $inc['host_id'], $inc['dkey']]);
                    }
                    if ($incs) {
                        AgentCommands::audit($user['username'], 'IDS: dit is normaal', count($incs) . ' incidenten, o.a. ' . $incs[0]['title']);
                    }
                }
            }
            out(['ok' => true, 'n' => $n]);
        }
        $inc = q('SELECT * FROM ids_incidents WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
        if (!$inc) {
            out(['error' => t('Incident niet gevonden')], 404);
        }
        if ($act === 'triage' || $act === 'notme') {   // 1.223.0: jouw oordeel (of "Nee, ik ken dit niet" = echt); verandert de status niet
            $v = $act === 'notme' ? 'real' : (string) ($in['verdict'] ?? '');
            if (!in_array($v, Triage::VERDICTS, true)) {
                out(['error' => t('Onbekend oordeel')], 422);
            }
            Triage::fromUser($inc, $v, (string) $user['username']);
            out(['ok' => true, 'triage' => Triage::map()]);
        }
        if ($act === 'reopen') {
            q("UPDATE ids_incidents SET status = 'open', handled_by = NULL, handled_at = NULL WHERE id = ?", [$inc['id']]);
        } else {
            q("UPDATE ids_incidents SET status = 'done', handled_by = ?, handled_at = NOW() WHERE id = ?", [$user['username'], $inc['id']]);
            if ($act === 'normal') {
                Triage::fromUser($inc, 'benign', (string) $user['username']);   // 1.223.0: voortaan "onschuldig" voor deze soort
                // Voortaan niet meer melden (deze soort met deze sleutel, op deze server)
                q("INSERT IGNORE INTO ids_baseline (host_id, kind, item, value) VALUES (?, 'ignore', ?, ?)",
                    [$inc['host_id'], mb_substr($inc['type'] . '|' . $inc['dkey'], 0, 255), mb_substr($user['username'], 0, 255)]);
                AgentCommands::audit($user['username'], 'IDS: dit is normaal', "server {$inc['host_id']}: {$inc['title']}");
                // Webshell vrijgegeven: een proces dat nog gestopt zou worden, met rust laten
                q("UPDATE ids_kills SET done_at = NOW(), result = ? WHERE host_id = ? AND dkey = ? AND done_at IS NULL", [t('vrijgegeven'), $inc['host_id'], $inc['dkey']]);
            }
        }
        out(['ok' => true]);

    // Incidentvenster (1.101.0): het incident met advies; de gegevens over het IP-adres apart (ip_info), met time-outs
    case 'ids_incident_info':
        $inc = q('SELECT i.*, h.name AS host_name, h.mode AS host_mode FROM ids_incidents i JOIN hosts h ON h.id = i.host_id WHERE i.id = ?', [(int) ($_GET['id'] ?? 0)])->fetch();
        if (!$inc) {
            out(['error' => t('Incident niet gevonden')], 404);
        }
        out(['incident' => $inc, 'advice' => IdsAdvice::forIncident($inc), 'my_ip' => client_ip(), 'evidence' => Edr::evidenceFor((int) $inc['id']), 'explain' => Protect::describe($inc),
            'wpath' => WebLog::path($inc),   // 1.222.0: pad van een webaanval
            'ignored' => (bool) q("SELECT 1 FROM ids_baseline WHERE host_id = ? AND kind = 'ignore' AND item = ?", [$inc['host_id'], mb_substr($inc['type'] . '|' . $inc['dkey'], 0, 255)])->fetchColumn()]);

    case 'ip_info':
        $ip = trim((string) ($_GET['ip'] ?? ''));
        if (@inet_pton($ip) === false) {
            out(['error' => t('Ongeldig IP-adres')], 422);
        }
        $fresh = ($_GET['fresh'] ?? '') === '1' && $isAdmin;
        // 1.112.0: dreigingsinformatie (uit de cache; nu opzoeken als het er nog niet is, of bij "opnieuw opzoeken")
        out(IdsAdvice::ipInfo($ip, $fresh) + ['ti' => ThreatIntel::on() && !Ids::internalIp($ip) ? ($fresh ? ThreatIntel::lookupIp($ip) : ThreatIntel::ip($ip, true)) : null,
            'ti_on' => ThreatIntel::on()]);

    case 'ids_block':
        $in = input();
        $hours = (int) ($in['hours'] ?? 24);
        $r = Ids::block((int) ($in['host_id'] ?? 0), (string) ($in['ip'] ?? ''), $hours > 0 ? $hours : null, trim((string) ($in['reason'] ?? '')), $user['username'], client_ip());
        if ($r !== true) {
            out(['error' => $r], 422);
        }
        AgentCommands::audit($user['username'], 'IDS: geblokkeerd', ($in['ip'] ?? '') . ' op server ' . (int) $in['host_id'] . ($hours > 0 ? " voor $hours uur" : ' permanent'));
        out(['ok' => true, 'active' => Ids::blocking()]);

    case 'ids_unblock':
        $b = Ids::unblock((int) (input()['id'] ?? 0), $user['username']);
        if (!$b) {
            out(['error' => t('Blokkade niet gevonden')], 404);
        }
        AgentCommands::audit($user['username'], 'IDS: gedeblokkeerd', "{$b['ip']} op server {$b['host_id']}");
        out(['ok' => true]);

    // ---------- Hardening, isoleren, gebruiker vergrendelen en de tijdlijn per IP (1.88.0) ----------
    case 'truenas_overview':   // 1.174.0: wat TrueNAS laatst meldde (meldingen, taken, apps, update, temperaturen)
        $o = Truenas::overview((int) ($_GET['host_id'] ?? 0));
        $o ? out($o) : out(['error' => t('Nog geen gegevens van TrueNAS: agent 1.77.0 of nieuwer nodig, als root')], 404);

    case 'harden_detail':
        $hid = (int) ($_GET['host_id'] ?? 0);
        out(Hardening::detail($hid) + ['iso' => q('SELECT isolate_until, isolate_by, isolate_state FROM hosts WHERE id = ?', [$hid])->fetch() ?: null,
            'userlocks' => Ids::userLocks($hid), 'my_ip' => client_ip(),
            'truenas' => Truenas::is($hid) ? ['fw' => (int) q('SELECT truenas_fw FROM hosts WHERE id = ?', [$hid])->fetchColumn() === 1,
                'state' => (string) q('SELECT fw_state FROM ids_state WHERE host_id = ?', [$hid])->fetchColumn()] : null]);

    case 'host_truenas_fw':   // 1.174.0: IDS mag op deze TrueNAS blokkeren (aanzetten met je wachtwoord)
        $in = input();
        $hid = (int) ($in['host_id'] ?? 0);
        $on = !empty($in['on']);
        if (!Truenas::is($hid)) {
            out(['error' => t('Dit is geen TrueNAS-server')], 422);
        }
        if ($on) {
            $confirm('TOESTAAN');
        }
        q('UPDATE hosts SET truenas_fw = ? WHERE id = ?', [$on ? 1 : 0, $hid]);   // de lijst voor de agent verandert: hij haalt hem bij zijn volgende ronde op
        AgentCommands::audit($user['username'], 'TrueNAS: blokkeren door het IDS', ($on ? 'aan' : 'uit') . ' op ' . (string) q('SELECT name FROM hosts WHERE id = ?', [$hid])->fetchColumn());
        out(['ok' => true, 'on' => $on]);

    case 'harden_ignore':
        $in = input();
        Hardening::ignore((int) ($in['host_id'] ?? 0), (string) ($in['item'] ?? ''), !empty($in['ignored']));
        AgentCommands::audit($user['username'], 'Hardening negeren', ($in['item'] ?? '') . ' op server ' . (int) ($in['host_id'] ?? 0) . (!empty($in['ignored']) ? ' aan' : ' uit'));
        out(['ok' => true]);

    case 'host_isolate':
        $in = input();
        $confirm('ISOLEREN');
        $hid = (int) ($in['host_id'] ?? 0);
        if (!($ih = q("SELECT address, agent_ips FROM hosts WHERE id = ? AND mode = 'agent'", [$hid])->fetch())) {
            out(['error' => t('Kies een server met agent')], 422);
        }
        // 1.105.2: de RzSense-server zelf isoleren legt alle checks stil (ping, SNMP, poorten naar andere netwerken)
        foreach (array_merge([(string) $ih['address']], parse_list((string) $ih['agent_ips'])) as $a) {
            if ($a !== '' && NetScan::isOwnIp(filter_var($a, FILTER_VALIDATE_IP) ? $a : (string) gethostbyname($a))) {
                out(['error' => t('Dit is de RzSense-server zelf: isoleren zou al je checks stilleggen')], 422);
            }
        }
        $why = '';
        if (($iid = (int) ($in['incident_id'] ?? 0)) > 0) {   // 1.172.0: vanuit een incident: dat is de reden
            $why = (string) q('SELECT title FROM ids_incidents WHERE id = ? AND host_id = ?', [$iid, $hid])->fetchColumn();
        }
        Ids::isolate($hid, (int) ($in['hours'] ?? 24), $user['username'], client_ip(), $why !== '' ? $why : t('Handmatig'));
        out(['ok' => true]);

    case 'host_unisolate':
        $confirm('OPHEFFEN');   // 1.172.0: opheffen verlaagt de bescherming, dus ook met je wachtwoord
        Ids::unisolate((int) (input()['host_id'] ?? 0), $user['username']);
        out(['ok' => true]);

    case 'host_userlock':
        $in = input();
        $lock = !empty($in['lock']);
        if ($lock) {
            $confirm('VERGRENDELEN');
        }
        $r = Ids::userLock((int) ($in['host_id'] ?? 0), strtolower(trim((string) ($in['user'] ?? ''))), $lock, $user['username']);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 422);

    case 'weblog_status':
        out(WebLog::status());

    case 'web_sites':   // 1.222.0: IDS → Overzicht: aanvallen per site
        out(memo('web_sites_' . (int) ($_GET['hours'] ?? 24), 60, static fn() => WebLog::sites((int) ($_GET['hours'] ?? 24))));

    case 'ids_attack_flow':   // 1.152.0: Attack Flow (aanvaller → doelwit → dienst → afloop), met tijdlijn
        out(AttackFlow::build(['days' => (int) ($_GET['days'] ?? 1), 'mins' => (int) ($_GET['mins'] ?? 0), 'host' => (int) ($_GET['host'] ?? 0), 'cc' => strtoupper(mb_substr((string) ($_GET['cc'] ?? ''), 0, 3)),
            'cat' => preg_replace('/[^a-z]/', '', (string) ($_GET['cat'] ?? '')), 'dir' => in_array($_GET['dir'] ?? '', ['i', 'o'], true) ? $_GET['dir'] : '',
            'top' => (int) ($_GET['top'] ?? 12)]));

    case 'ids_ip_events':   // 1.152.0: alles over één IP voor de popup
        $ip = trim((string) ($_GET['ip'] ?? ''));
        @inet_pton($ip) !== false ? out(AttackFlow::ipEvents($ip, (int) ($_GET['days'] ?? 7))) : out(['error' => t('Ongeldig IP-adres')], 422);

    case 'ssh_sessions':   // 1.152.0: IDS → Toegang → SSH → Gelukt
        out(['days' => max(1, min(SshEvents::KEEP_DAYS, (int) ($_GET['days'] ?? 7))), 'sessions' => SshEvents::sessions((int) ($_GET['days'] ?? 7))]);

    case 'ids_flows':   // 1.149.0: aanvalskaart
        out(Ids::flows(['days' => (int) ($_GET['days'] ?? 1), 'host' => (int) ($_GET['host'] ?? 0), 'cc' => strtoupper(mb_substr((string) ($_GET['cc'] ?? ''), 0, 3)),
            'cat' => preg_replace('/[^a-z]/', '', (string) ($_GET['cat'] ?? '')), 'dir' => in_array($_GET['dir'] ?? '', ['i', 'o'], true) ? $_GET['dir'] : '',
            'top' => (int) ($_GET['top'] ?? 10)]));

    case 'ip_timeline':
        $ip = trim((string) ($_GET['ip'] ?? ''));
        @inet_pton($ip) !== false ? out(Ids::timeline($ip)) : out(['error' => t('Ongeldig IP-adres')], 422);

    case 'ids_rebaseline':
        $hid = (int) (input()['host_id'] ?? 0);
        q("DELETE FROM ids_baseline WHERE host_id = ? AND kind <> 'ignore'", [$hid]);
        q('DELETE FROM ids_state WHERE host_id = ?', [$hid]);
        AgentCommands::audit($user['username'], 'IDS: basismeting opnieuw', "server $hid");
        out(['ok' => true]);
}
