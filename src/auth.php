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

function login(string $username, string $password): bool
{
    start_session();
    $u = q('SELECT id, username, password_hash FROM users WHERE username = ?', [$username])->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        usleep(500000); // brute force afremmen
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int) $u['id'], 'username' => $u['username']];
    unset($_SESSION['lan_auto']);
    return true;
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
