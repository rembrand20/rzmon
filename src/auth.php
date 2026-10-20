<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('rzmonsid');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

/** Lijst met netwerken die als "lokaal" gelden voor inloggen zonder wachtwoord. */
function trusted_networks(): array
{
    return parse_list((string) setting('trusted_networks', ''));
}

/** Mag deze bezoeker zonder login binnen? */
function lan_login_allowed(): bool
{
    return setting('lan_no_login', '0') === '1' && ip_in_list(client_ip(), trusted_networks());
}

function current_user(): ?array
{
    start_session();
    $user = $_SESSION['user'] ?? null;

    // Automatische login vervalt zodra je niet meer vanaf het lokale netwerk komt
    // (bv. telefoon die van wifi naar 4G gaat) of de optie is uitgezet.
    if ($user && !empty($_SESSION['lan_auto']) && !lan_login_allowed()) {
        $_SESSION = ['csrf' => $_SESSION['csrf']];
        session_regenerate_id(true);
        $user = null;
    }

    // Gebruiker verwijderd in Instellingen → sessie ongeldig
    if ($user && !q('SELECT 1 FROM users WHERE id = ?', [$user['id']])->fetchColumn()) {
        $_SESSION = ['csrf' => $_SESSION['csrf']];
        $user = null;
    }

    if (!$user && lan_login_allowed()) {
        $u = q('SELECT id, username FROM users ORDER BY id LIMIT 1')->fetch();
        if ($u) {
            session_regenerate_id(true);
            $user = $_SESSION['user'] = ['id' => (int) $u['id'], 'username' => $u['username']];
            $_SESSION['lan_auto'] = true;
        }
    }
    return $user;
}

function is_lan_session(): bool
{
    return !empty($_SESSION['lan_auto']);
}

/**
 * Eerste stap van inloggen.
 * @return string|false 'ok' (ingelogd), 'totp' (nu nog de code uit de authenticator-app) of false
 */
function login(string $username, string $password): string|false
{
    start_session();
    $u = q('SELECT id, username, password_hash, totp_secret FROM users WHERE username = ?', [$username])->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        usleep(500000); // brute force afremmen
        return false;
    }
    if ($u['totp_secret']) {
        // Wachtwoord klopt; de sessie is pas geldig na de juiste code
        unset($_SESSION['user'], $_SESSION['lan_auto']);
        $_SESSION['pending_2fa'] = ['id' => (int) $u['id'], 'at' => time(), 'tries' => 0];
        return 'totp';
    }
    finish_login($u);
    return 'ok';
}

/**
 * Tweede stap: de 6-cijferige code. Na 5 foute codes of 5 minuten opnieuw beginnen.
 * @return true|string true, of een foutmelding
 */
function login_totp(string $code): true|string
{
    start_session();
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p || $p['at'] < time() - 300 || $p['tries'] >= 5) {
        unset($_SESSION['pending_2fa']);
        return 'Log opnieuw in met je wachtwoord';
    }
    $u = q('SELECT id, username, totp_secret, totp_last_step FROM users WHERE id = ?', [$p['id']])->fetch();
    if (!$u || !$u['totp_secret']) {
        unset($_SESSION['pending_2fa']);
        return 'Log opnieuw in met je wachtwoord';
    }
    $step = Totp::verify($u['totp_secret'], $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null);
    if ($step === null) {
        $_SESSION['pending_2fa']['tries']++;
        usleep(500000);
        return 'Onjuiste code' . ($_SESSION['pending_2fa']['tries'] >= 5 ? ' — log opnieuw in met je wachtwoord' : '');
    }
    q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
    finish_login($u);
    return true;
}

function finish_login(array $u): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa'], $_SESSION['lan_auto']);
    $_SESSION['user'] = ['id' => (int) $u['id'], 'username' => $u['username']];
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'];
}
