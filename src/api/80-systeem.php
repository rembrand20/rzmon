<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): acties: commando's op agents, bijwerken, back-up, wachtwoord.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var array<string, mixed> $user
 * @var callable(string): void $confirm
 */
defined('RZ_API') || exit;

switch ($action) {
    // ---------------------------------------------------------- Commando's op agents
    case 'agent_commands':
        $hosts = q("SELECT id, name, commands FROM hosts WHERE mode = 'agent' ORDER BY name")->fetchAll();
        out([
            'global' => (string) setting('agent_commands', 'off'),
            'hosts' => array_map(fn($h) => ['id' => (int) $h['id'], 'name' => $h['name'], 'own' => $h['commands'], 'mode' => AgentCommands::modeFor($h)], $hosts),
            'log' => q('SELECT username, action, detail, ip, created_at FROM audit_log ORDER BY id DESC LIMIT 20')->fetchAll(),
        ]);

    case 'agent_commands_set':
        $in = input();
        $mode = (string) ($in['mode'] ?? '');
        $hostId = (int) ($in['host_id'] ?? 0);
        if ($hostId > 0) {
            $h = q("SELECT id, name, commands FROM hosts WHERE id = ? AND mode = 'agent'", [$hostId])->fetch();
            if (!$h) {
                out(['error' => t('Server niet gevonden')], 404);
            }
            if ($mode !== 'global' && !isset(AgentCommands::MODES[$mode])) {
                out(['error' => t('Ongeldige keuze')], 422);
            }
            $from = AgentCommands::modeFor($h);
            $to = AgentCommands::modeFor(['commands' => $mode === 'global' ? null : $mode]);
            if (AgentCommands::widens($from, $to)) {
                $confirm('TOESTAAN');
            }
            q('UPDATE hosts SET commands = ? WHERE id = ?', [$mode === 'global' ? null : $mode, $hostId]);
            AgentCommands::audit($user['username'], "commando's per server", "{$h['name']}: " . ($mode === 'global' ? 'algemene instelling' : AgentCommands::MODES[$mode]) . " (was: " . AgentCommands::MODES[$from] . ')');
        } else {
            if (!isset(AgentCommands::MODES[$mode])) {
                out(['error' => t('Ongeldige keuze')], 422);
            }
            $from = (string) setting('agent_commands', 'off');
            if (AgentCommands::widens($from, $mode)) {
                $confirm('TOESTAAN');
            }
            save_setting('agent_commands', $mode);
            AgentCommands::audit($user['username'], "commando's algemeen", AgentCommands::MODES[$mode] . ' (was: ' . (AgentCommands::MODES[$from] ?? $from) . ')');
        }
        out(['ok' => true]);

    // ---------------------------------------------------------- RzSense bijwerken
    case 'update_info':
        $cache = Updater::check(!empty($_GET['force']));
        out([
            'current' => RZMON_VERSION,
            'latest' => $cache['latest'] ?? null,
            'available' => !empty($cache['latest']) && version_compare($cache['latest'], RZMON_VERSION, '>'),
            'checked_at' => isset($cache['checked_at']) ? date('Y-m-d H:i:s', (int) $cache['checked_at']) : null,
            'error' => $cache['error'] ?? null,
            'notes' => $cache['notes'] ?? [],
            'versions' => $cache['versions'] ?? [],
            'pinned' => (string) setting('update_pinned', ''),
            'unavailable' => Updater::unavailable(),
            'status' => Updater::status(),
            'hold_old' => UpdateHold::oldAgents(),   // 1.205.0: deze agents kunnen niet wachten tijdens de update
            'hold_min' => UpdateHold::minutes(),
            'backup' => setting('update_backup', '1') === '1',
            'source' => implode('@', Updater::source()),
            'install_dir' => SM_ROOT,
        ]);

    case 'update_start':
        $backup = !empty(input()['backup']);
        save_setting('update_backup', $backup ? '1' : '0');   // de schakelaar onthouden
        $ver = trim((string) (input()['version'] ?? ''));
        if ($ver !== '' && version_compare($ver, RZMON_VERSION, '<')) {
            $confirm('TERUGZETTEN');   // naar een oudere versie: eerst bevestigen
        }
        $r = Updater::request($backup, $user['username'], $ver !== '' ? $ver : null);
        $r === true ? out(['ok' => true]) : out(['error' => $r], 409);

    case 'update_status':
        UpdateHold::tick();   // 1.205.0: de agents weten het (of de tijd is om): de update kan beginnen
        out(['status' => Updater::status(), 'version' => RZMON_VERSION,
            'log' => is_readable(Installer::HELPER_DIR . '/update.log') ? mb_substr((string) file_get_contents(Installer::HELPER_DIR . '/update.log'), -6000) : '']);

    case 'db_rotate':
        $confirm('VERNIEUWEN');
        $h = Installer::HELPER_DIR;
        if (is_file("$h/helper.installed") && is_writable($h)) {
            // Via de hulpdienst (als root): verzoek neerzetten, het resultaat komt binnen een paar seconden
            @unlink("$h/rotate.result");
            $nonce = bin2hex(random_bytes(16));
            if (file_put_contents("$h/rotate.request", $nonce) === false) {
                out(['error' => t('Kan geen verzoek neerzetten in {dir}', ['dir' => $h])], 500);
            }
            $_SESSION['db_rotate'] = ['nonce' => $nonce, 'at' => time()];
            out(['ok' => true, 'pending' => true]);
        }
        if (Installer::configWritable() || is_writable(SM_CONFIG_FILE)) {
            session_write_close();
            $r = Installer::rotateDbPassword();
            out($r, $r['ok'] ? 200 : 422);
        }
        out(['error' => t('De RzSense-map is vergrendeld en de hulpdienst is niet geïnstalleerd'), 'need_helper' => true], 409);

    case 'db_rotate_status':
        $p = $_SESSION['db_rotate'] ?? null;
        $h = Installer::HELPER_DIR;
        if (!$p) {
            out(['error' => t('Geen wachtwoordwissel bezig')], 404);
        }
        $res = json_decode((string) @file_get_contents("$h/rotate.result"), true);
        if ($res && hash_equals($p['nonce'], (string) ($res['nonce'] ?? ''))) {
            // Eén keer tonen: resultaat meteen weg
            @unlink("$h/rotate.result");
            unset($_SESSION['db_rotate'], $res['nonce'], $res['at']);
            out($res, $res['ok'] ? 200 : 422);
        }
        if (time() - $p['at'] > 30) {
            unset($_SESSION['db_rotate']);
            @unlink("$h/rotate.request");
            out(['error' => t('De hulpdienst reageert niet. Controleer op de server: systemctl status rzmon-helper.path')], 504);
        }
        out(['pending' => true]);

    // ---------- Back-up en herstel (alleen beheerders) ----------
    case 'backup_info':
        $root = realpath(SM_ROOT);
        $unlock = (int) ($_SESSION['backup_unlock'] ?? 0) + 600 - time();
        $next = Backup::nextSlot();
        out([
            'problem' => Backup::problem(),
            'fix_cmd' => Backup::fixCommand(),
            'dir' => Backup::dir(),
            'encryption' => Backup::encryptionSupported(),
            'has_pass' => (string) setting('backup_pass', '') !== '',
            'next' => $next ? date('Y-m-d H:i:s', $next) : null,
            'last_auto' => setting('backup_last_auto'),
            'last_error' => (string) setting('backup_last_error', ''),
            'unlocked' => max(0, $unlock),
            'max_upload' => Backup::MAX_UPLOAD,
            'list' => Backup::problem() === null ? Backup::list() : [],
            'cli_backup' => "sudo php $root/install/install.php --backup",
            'cli_restore' => "sudo php $root/install/install.php --restore /" . t('pad/naar') . '/rzmon-backup-….zip',
        ]);

    case 'backup_create':
        session_write_close();   // andere verzoeken van deze gebruiker niet blokkeren
        $r = Backup::create('manual', isset(input()['history']) ? (bool) input()['history'] : null);
        out($r, $r['ok'] ? 200 : 422);

    case 'backup_delete':
        if (!Backup::delete((string) (input()['file'] ?? ''))) {
            out(['error' => t('Back-up niet gevonden')], 404);
        }
        out(['ok' => true]);

    case 'backup_unlock':
        // Downloaden kan 10 minuten na het bevestigen (de zip bevat config.php met het databasewachtwoord)
        $confirm('DOWNLOADEN');
        $_SESSION['backup_unlock'] = time();
        out(['ok' => true, 'unlocked' => 600]);

    case 'backup_download':
        $path = Backup::path((string) ($_GET['file'] ?? ''));
        if ((int) ($_SESSION['backup_unlock'] ?? 0) < time() - 600) {
            out(['error' => t('Bevestig eerst met je wachtwoord')], 403);
        }
        if ($path === null) {
            out(['error' => t('Back-up niet gevonden')], 404);
        }
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;

    case 'backup_upload':
        // De zip komt in stukken van 1 MB (ruwe body), zie app.js
        session_write_close();
        $r = Backup::uploadChunk((string) ($_GET['id'] ?? ''), (int) ($_GET['offset'] ?? -1),
            (string) file_get_contents('php://input'), ($_GET['final'] ?? '') === '1');
        out($r, $r['ok'] ? 200 : 422);

    case 'backup_restore':
        $confirm('HERSTELLEN');
        $path = Backup::path((string) (input()['file'] ?? ''));
        if ($path === null) {
            out(['error' => t('Back-up niet gevonden')], 404);
        }
        session_write_close();
        $r = Backup::restore($path, trim((string) (input()['zip_pass'] ?? '')) ?: null);
        out($r, $r['ok'] ? 200 : 422);

    case 'password':
        if ($user['id'] === 0) {
            out(['error' => t('Je bent automatisch ingelogd (lokaal netwerk). Log in met je eigen account om je wachtwoord te wijzigen.')], 422);
        }
        $in = input();
        $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
        if (!$row || !password_verify((string) ($in['current'] ?? ''), $row['password_hash'])) {
            out(['error' => t('Huidig wachtwoord onjuist')], 422);
        }
        if (strlen((string) ($in['new'] ?? '')) < 8) {
            out(['error' => t('Nieuw wachtwoord moet minimaal 8 tekens zijn')], 422);
        }
        // Andere sessies van dit account worden uitgelogd (pw_changed_at); deze blijft
        q('UPDATE users SET password_hash = ?, pw_changed_at = ?, pw_must_change = 0 WHERE id = ?', [hash_password((string) $in['new']), now(), $user['id']]);
        $_SESSION['auth_at'] = time();
        // Eigen wachtwoord gewijzigd: alle andere sessies en onthouden apparaten weg (dit apparaat blijft)
        session_logout('user', (int) $user['id']);
        out(['ok' => true, 'pwned' => Hibp::checkUser((int) $user['id'], (string) $in['new']) ?? 0]);
}
