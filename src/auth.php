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
    $lan = !empty($_SESSION['lan_auto']);

    // Automatische login vervalt zodra je niet meer vanaf het lokale netwerk komt
    // (bv. telefoon die van wifi naar 4G gaat) of de optie is uitgezet.
    if ($user && $lan && !lan_login_allowed()) {
        $_SESSION = ['csrf' => $_SESSION['csrf']];
        session_regenerate_id(true);
        $user = null;
        $lan = false;
    }

    if ($user && $lan) {
        // Rol op het LAN volgt direct de schakelaar "als beheerder inloggen"
        $user = $_SESSION['user'] = lan_user();
    } elseif ($user) {
        // Eigen account: bestaan en rol elke keer uit de database (verwijderd of rol gewijzigd → direct merkbaar)
        $row = q('SELECT id, username, role, pw_changed_at FROM users WHERE id = ?', [$user['id']])->fetch();
        // Wachtwoord gewijzigd ná het inloggen in deze sessie (bv. via "wachtwoord vergeten") → uitloggen
        if ($row && $row['pw_changed_at'] !== null && strtotime($row['pw_changed_at']) > (int) ($_SESSION['auth_at'] ?? 0)) {
            $row = false;
        }
        $user = $row ? ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']] : null;
        // Ingelogd via "onthoud mij" en dat apparaat is intussen vergeten (bv. telefoon kwijt) → uitloggen
        if ($user && !empty($_SESSION['remember_id']) && !remember_touch()) {
            $user = null;
        }
        if ($user) {
            $_SESSION['user'] = $user;
        } else {
            $_SESSION = ['csrf' => $_SESSION['csrf']];
        }
    }

    // Onthouden apparaat: inloggen met de sleutel uit de cookie (ook op het LAN: dan je eigen account)
    if (!$user) {
        $user = remember_login();
    }

    // "Inloggen" gekozen op het LAN (?login=1): dan niet automatisch binnenlaten
    if (!$user && lan_login_allowed() && empty($_SESSION['no_lan_auto'])) {
        session_regenerate_id(true);
        $_SESSION['lan_auto'] = true;
        $user = $_SESSION['user'] = lan_user();
    }
    return $user;
}

/**
 * Wie je bent bij inloggen zonder wachtwoord op het lokale netwerk: standaard alleen lezen
 * (geen echt account), of de eerste beheerder als "op het LAN als beheerder inloggen" aan staat.
 */
function lan_user(): array
{
    if (setting('lan_as_admin', '0') === '1') {
        $u = q("SELECT id, username FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
        if ($u) {
            return ['id' => (int) $u['id'], 'username' => $u['username'], 'role' => 'admin'];
        }
    }
    return ['id' => 0, 'username' => 'lokaal netwerk', 'role' => 'readonly'];
}

/** Heeft deze gebruiker minstens deze rol? */
function has_role(?array $user, string $min): bool
{
    return $user !== null && (ROLES[$user['role'] ?? ''] ?? 0) >= ROLES[$min];
}

function is_lan_session(): bool
{
    return !empty($_SESSION['lan_auto']);
}

/**
 * Eerste stap van inloggen.
 * @return string|false 'ok' (ingelogd), 'totp' (nu nog de code uit de authenticator-app) of false
 */
function login(string $username, string $password, bool $remember = false): string|false
{
    start_session();
    $u = q('SELECT id, username, password_hash, totp_secret, role FROM users WHERE username = ?', [$username])->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        Security::loginFailed($username, $u ? 'verkeerd wachtwoord' : 'onbekende gebruiker');
        usleep(500000); // brute force afremmen
        return false;
    }
    if ($u['totp_secret']) {
        // Wachtwoord klopt; de sessie is pas geldig na de juiste code
        unset($_SESSION['user'], $_SESSION['lan_auto']);
        $_SESSION['pending_2fa'] = ['id' => (int) $u['id'], 'at' => time(), 'tries' => 0, 'remember' => $remember];
        return 'totp';
    }
    finish_login($u, $remember);
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
        return t('Log opnieuw in met je wachtwoord');
    }
    $u = q('SELECT id, username, role, totp_secret, totp_last_step FROM users WHERE id = ?', [$p['id']])->fetch();
    if (!$u || !$u['totp_secret']) {
        unset($_SESSION['pending_2fa']);
        return t('Log opnieuw in met je wachtwoord');
    }
    $step = Totp::verify($u['totp_secret'], $code, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null);
    if ($step === null) {
        Security::loginFailed((string) $u['username'], 'verkeerde 2FA-code');
        $_SESSION['pending_2fa']['tries']++;
        usleep(500000);
        return t('Onjuiste code') . ($_SESSION['pending_2fa']['tries'] >= 5 ? ' — ' . t('log opnieuw in met je wachtwoord') : '');
    }
    q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
    finish_login($u, !empty($p['remember']), 'wachtwoord en 2FA');
    return true;
}

function finish_login(array $u, bool $remember = false, string $how = 'wachtwoord'): void
{
    Security::loginOk($u, $how);
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa'], $_SESSION['lan_auto'], $_SESSION['no_lan_auto'], $_SESSION['remember_id']);
    $_SESSION['user'] = ['id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'] ?? 'admin'];
    $_SESSION['auth_at'] = time();
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
    if ($remember) {
        remember_create((int) $u['id']);
    }
}

// ------------------------------------------------------------------ onthoud mij op dit apparaat

const REMEMBER_COOKIE = 'rzmon_remember';

/** Cookie zetten (of met null weghalen). Lax: ook geldig als je een link uit een melding opent. */
function remember_cookie(?string $value): void
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    setcookie(REMEMBER_COOKIE, $value ?? '', [
        'expires' => $value !== null ? time() + REMEMBER_DAYS * 86400 : time() - 3600,
        'path' => $path,
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    if ($value === null) {
        unset($_COOKIE[REMEMBER_COOKIE]);
    }
}

/** Nieuw onthouden apparaat: willekeurige sleutel; in de database alleen de hash. */
function remember_create(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    q('INSERT INTO remember_tokens (user_id, selector, token_hash, device, ip, last_used) VALUES (?, ?, ?, ?, ?, ?)', [
        $userId, $selector, hash('sha256', $validator), device_name(), mb_substr(client_ip(), 0, 45), now(),
    ]);
    $_SESSION['remember_id'] = (int) db()->lastInsertId();
    $_SESSION['remember_value'] = "$selector:$validator";
    $_SESSION['remember_touch'] = time();
    remember_cookie("$selector:$validator");
}

/**
 * Inloggen met de cookie. De sleutel wordt daarbij vervangen. Een oude sleutel die opnieuw
 * opduikt (gestolen cookie?) maakt het apparaat ongeldig. Geen 2FA nodig: dit apparaat is vertrouwd.
 */
function remember_login(): ?array
{
    $c = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $c, $m)) {
        if ($c !== '') {
            remember_cookie(null);
        }
        return null;
    }
    $t = q('SELECT r.*, u.username, u.role FROM remember_tokens r JOIN users u ON u.id = r.user_id WHERE r.selector = ?', [$m[1]])->fetch();
    if (!$t || strtotime($t['last_used']) < time() - REMEMBER_DAYS * 86400) {
        if ($t) {
            q('DELETE FROM remember_tokens WHERE id = ?', [$t['id']]);
        }
        remember_cookie(null);
        return null;
    }
    $hash = hash('sha256', $m[2]);
    $fresh = hash_equals($t['token_hash'], $hash);
    // Twee verzoeken tegelijk met dezelfde (net vervangen) sleutel: 60 seconden speling
    $grace = !$fresh && $t['prev_hash'] && hash_equals($t['prev_hash'], $hash) && strtotime((string) $t['rotated_at']) > time() - 60;
    if (!$fresh && !$grace) {
        q('DELETE FROM remember_tokens WHERE id = ?', [$t['id']]);
        remember_cookie(null);
        return null;
    }
    session_regenerate_id(true);
    unset($_SESSION['lan_auto'], $_SESSION['no_lan_auto']);
    $_SESSION['auth_at'] = time();
    $_SESSION['remember_id'] = (int) $t['id'];
    $_SESSION['remember_touch'] = time();
    if ($fresh) {
        $validator = bin2hex(random_bytes(32));
        q('UPDATE remember_tokens SET token_hash = ?, prev_hash = ?, rotated_at = ?, last_used = ?, ip = ? WHERE id = ?', [
            hash('sha256', $validator), $t['token_hash'], now(), now(), mb_substr(client_ip(), 0, 45), $t['id'],
        ]);
        $_SESSION['remember_value'] = "{$m[1]}:$validator";
        remember_cookie($_SESSION['remember_value']);
    }
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $t['user_id']]);
    Security::loginOk(['username' => $t['username']], 'onthouden apparaat');
    return $_SESSION['user'] = ['id' => (int) $t['user_id'], 'username' => $t['username'], 'role' => $t['role']];
}

/**
 * Tijdens een sessie: bestaat het apparaat nog (niet "vergeten")? Elke 10 minuten ook
 * "laatst gebruikt" en de vervaldatum van de cookie bijwerken (30 dagen vanaf nu).
 */
function remember_touch(): bool
{
    $id = (int) $_SESSION['remember_id'];
    if (!q('SELECT 1 FROM remember_tokens WHERE id = ?', [$id])->fetchColumn()) {
        unset($_SESSION['remember_id'], $_SESSION['remember_value']);
        remember_cookie(null);
        return false;
    }
    if (time() - (int) ($_SESSION['remember_touch'] ?? 0) > 600) {
        q('UPDATE remember_tokens SET last_used = ?, ip = ? WHERE id = ?', [now(), mb_substr(client_ip(), 0, 45), $id]);
        $_SESSION['remember_touch'] = time();
        if (!empty($_SESSION['remember_value'])) {
            remember_cookie($_SESSION['remember_value']);
        }
    }
    return true;
}

/** Leesbare naam voor het apparaat, uit de User-Agent: "Safari op iPhone". */
function device_name(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone',
        str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
        str_contains($ua, 'CrOS') => 'Chromebook',
        str_contains($ua, 'Linux') => 'Linux',
        default => t('onbekend systeem'),
    };
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Browser',
    };
    return t('{browser} op {os}', ['browser' => $browser, 'os' => $os]);
}

function logout(): void
{
    start_session();
    // Uitloggen = ook dit apparaat niet meer onthouden
    if (!empty($_SESSION['remember_id'])) {
        q('DELETE FROM remember_tokens WHERE id = ?', [(int) $_SESSION['remember_id']]);
    }
    remember_cookie(null);
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'];
}
