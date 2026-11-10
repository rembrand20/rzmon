<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: onthouden apparaten, sessies, databasewachtwoord.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $method
 * @var array<string, mixed> $user
 * @var bool $isAdmin
 * @var callable(int): void $ownOnly
 * @var callable(string): void $confirm
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------- Onthouden apparaten ----------
    case 'devices':
        $uid = (int) ($_GET['user_id'] ?? $user['id']);
        $ownOnly($uid);
        $rows = q('SELECT id, device, ip, created_at, last_used FROM remember_tokens WHERE user_id = ? ORDER BY last_used DESC', [$uid])->fetchAll();
        foreach ($rows as &$r) {
            $r['current'] = (int) $r['id'] === (int) ($_SESSION['remember_id'] ?? 0);
        }
        unset($r);
        out($rows);

    case 'device_forget':
        $id = (int) (input()['id'] ?? 0);
        $owner = q('SELECT user_id FROM remember_tokens WHERE id = ?', [$id])->fetchColumn();
        if ($owner === false) {
            out(['error' => t('Apparaat niet gevonden')], 404);
        }
        $ownOnly((int) $owner);
        q('DELETE FROM remember_tokens WHERE id = ?', [$id]);
        q('DELETE FROM user_sessions WHERE remember_id = ? AND id <> ?', [$id, session_current()]);   // ook meteen uitloggen
        if ((int) $owner !== (int) $user['id']) {
            AgentCommands::audit($user['username'], 'Apparaat vergeten', (string) q('SELECT username FROM users WHERE id = ?', [$owner])->fetchColumn());
        }
        out(['ok' => true]);

    // Ingelogde apparaten (1.79.0): je eigen (of als beheerder die van een gebruiker), of van iedereen (alleen beheerders)
    case 'sessions':
        $uid = (int) ($_GET['user_id'] ?? $user['id']);
        $ownOnly($uid);
        out(session_list($uid));

    case 'sessions_all':
        out(['sessions' => session_list(null), 'ttl' => session_ttl()]);

    case 'session_logout':
        $id = (int) (input()['id'] ?? 0);
        $s = q('SELECT user_id, username, device FROM user_sessions WHERE id = ?', [$id])->fetch();
        if (!$s) {
            out(['error' => t('Deze sessie bestaat niet meer')], 404);
        }
        if ($s['user_id'] === null && !$isAdmin) {
            out(['error' => t('Daar heb je geen rechten voor')], 403);
        }
        if ($s['user_id'] !== null) {
            $ownOnly((int) $s['user_id']);
        }
        if ($id === session_current()) {
            out(['error' => t('Dit is je huidige sessie: gebruik Uitloggen in het accountmenu')], 409);
        }
        session_logout('session', $id);
        if ((int) $s['user_id'] !== (int) $user['id']) {
            AgentCommands::audit($user['username'], 'Sessie uitgelogd', "{$s['username']}: {$s['device']}");
        }
        out(['ok' => true]);

    // Alles van een gebruiker uitloggen (sessies en onthouden apparaten), behalve dit apparaat
    case 'device_forget_all':
    case 'sessions_logout_user':
        $uid = (int) (input()['user_id'] ?? $user['id']);
        $ownOnly($uid);
        $n = session_logout('user', $uid);
        if ($uid !== (int) $user['id']) {
            AgentCommands::audit($user['username'], 'Alle sessies uitgelogd', (string) q('SELECT username FROM users WHERE id = ?', [$uid])->fetchColumn());
        }
        out(['ok' => true, 'n' => $n]);

    // Noodknop: iedereen uitloggen behalve dit apparaat (met je wachtwoord)
    case 'sessions_logout_all':
        $confirm('UITLOGGEN');
        $n = session_logout('all');
        AgentCommands::audit($user['username'], 'Iedereen uitgelogd', "$n sessies en apparaten");
        out(['ok' => true, 'n' => $n]);

    case 'totp_reset':
        $tid = (int) (input()['id'] ?? 0);
        $ownOnly($tid);
        if ($tid === (int) $user['id'] && Https::mfaRequired()) {
            out(['error' => t('Van buiten je interne netwerken kun je je eigen 2FA niet uitzetten')], 403);
        }
        $confirm('ACCOUNT');   // 1.224.1: 2FA uitzetten alleen met je wachtwoord
        q('UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE id = ?', [$tid]);
        out(['ok' => true]);

    // ---------- Databasewachtwoord vernieuwen (alleen beheerders) ----------
    case 'setup_info':   // 1.92.0: de setup-pagina in de portal (Instellingen → Systeem)
        out(['checks' => array_map(static fn($c) => ['label' => $c[0], 'ok' => $c[1], 'detail' => $c[2]], Setup::checks(true, null)),
            'php_env' => Setup::phpInfo(), 'cron' => Setup::cronLine(), 'self' => SelfCheck::report(), 'perf' => Perf::report()] + Setup::config());

    case 'db_optimize':   // 1.119.0: de grootste tabellen opnieuw opbouwen (beheerders)
        if ($method !== 'POST') {
            out(['error' => 'POST'], 405);
        }
        session_write_close();
        $r = Perf::optimize();
        AgentCommands::audit($user['username'], 'Database geoptimaliseerd', implode(', ', array_column($r, 'name')));
        out(['ok' => true, 'tables' => $r]);

    case 'setup_db_test':
        $in = input();
        try {
            out(Setup::dbTest($in));
        } catch (PDOException $e) {
            out(['error' => 'Database: ' . $e->getMessage()], 422);
        }

    case 'setup_db_save':
        $confirm('DATABASE');
        $in = input();
        try {
            $r = Setup::dbSave($in);
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            out(['error' => 'Database: ' . $e->getMessage()], 422);
        }
        AgentCommands::audit($user['username'], 'Databaseverbinding', ($in['db_user'] ?? '') . '@' . ($in['db_host'] ?? '') . '/' . ($in['db_name'] ?? ''));
        out($r);

    case 'setup_schema':
        Installer::runSchema();
        out(['ok' => true, 'message' => t('Tabellen bijgewerkt')]);

    case 'setup_app_save':
        $in = input();
        try {
            out(Setup::appSave($in));
        } catch (InvalidArgumentException $e) {
            out(['error' => $e->getMessage()], 422);
        }

    case 'config_result':   // 1.168.0: is config.php door de hulpdienst opgeslagen?
        $nonce = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['nonce'] ?? input()['nonce'] ?? ''));
        $f = Installer::HELPER_DIR . '/config.result';
        $r = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($r) || $nonce === '' || !hash_equals((string) ($r['nonce'] ?? ''), $nonce)) {
            out(['done' => false, 'waiting' => is_file(Installer::HELPER_DIR . '/config.request')]);
        }
        @unlink($f);
        out(['done' => true, 'ok' => (bool) $r['ok'], 'error' => $r['ok'] ? null : t('De hulpdienst kon config.php niet opslaan: {e}', ['e' => (string) $r['error']])]);

    case 'db_info':
        $h = Installer::HELPER_DIR;
        $root = realpath(SM_ROOT);
        $db = config('db');
        out([
            'helper' => is_file("$h/helper.installed") && is_writable($h),
            'config_helper' => Installer::configHelper(),   // 1.168.0
            'direct' => Installer::configWritable() || is_writable(SM_CONFIG_FILE),
            'db' => ['host' => $db['host'], 'name' => $db['name'], 'user' => $db['user']],
            'cli_cmd' => "sudo php $root/install/install.php --rotate-db-password",
            'helper_cmd' => "sudo bash $root/install/install-helper.sh",
        ]);
}
