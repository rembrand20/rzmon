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
        'secure' => Https::isHttps(),
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
    return setting('lan_no_login', '0') === '1' && Https::internal();
}

function current_user(): ?array
{
    start_session();
    $user = $_SESSION['user'] ?? null;
    $lan = !empty($_SESSION['lan_auto']);

    // Automatische login vervalt zodra je niet meer vanaf het lokale netwerk komt
    // (bv. telefoon die van wifi naar 4G gaat) of de optie is uitgezet.
    if ($user && $lan && !lan_login_allowed()) {
        session_end();
        $_SESSION = ['csrf' => $_SESSION['csrf']];
        session_regenerate_id(true);
        $user = null;
        $lan = false;
    }

    if ($user && $lan) {
        // Rol op het LAN volgt direct de schakelaar "als beheerder inloggen"
        $user = $_SESSION['user'] = lan_user();
        // Uitgelogd vanuit "Ingelogde apparaten": opnieuw beginnen (zolang LAN-inloggen aan staat, volgt een nieuwe sessie)
        if (!session_alive($user)) {
            $_SESSION = ['csrf' => $_SESSION['csrf']];
            $user = null;
        }
    } elseif ($user) {
        // Eigen account: bestaan en rol elke keer uit de database (verwijderd of rol gewijzigd → direct merkbaar)
        $row = q('SELECT id, username, role, pw_changed_at, local_only FROM users WHERE id = ?', [$user['id']])->fetch();
        // Account dat alleen vanaf de interne netwerken mag (noodaccount): van buiten vervalt de sessie
        if ($row && (int) $row['local_only'] && !Https::internal()) {
            $row = false;
        }
        // Wachtwoord gewijzigd ná het inloggen in deze sessie (bv. via "wachtwoord vergeten") → uitloggen
        if ($row && $row['pw_changed_at'] !== null && strtotime($row['pw_changed_at']) > (int) ($_SESSION['auth_at'] ?? 0)) {
            $row = false;
        }
        $user = $row ? ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']] : null;
        // Van buiten je interne netwerken is 2FA verplicht (als dat aan staat): een sessie zonder 2FA vervalt dan
        if ($user && empty($_SESSION['mfa']) && Https::mfaRequired()) {
            $user = null;
        }
        // Ingelogd via "onthoud mij" en dat apparaat is intussen vergeten (bv. telefoon kwijt) → uitloggen
        if ($user && !empty($_SESSION['remember_id']) && !remember_touch()) {
            $user = null;
        }
        // Uitgelogd vanuit "Ingelogde apparaten" (door jezelf of een beheerder, 1.79.0)
        if ($user && !session_alive($user)) {
            $user = null;
        }
        if ($user) {
            $_SESSION['user'] = $user;
        } else {
            session_end();
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
        session_track($user, 'lan');
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
 * @return string|false 'ok' (ingelogd), 'totp' (nu nog de code uit de authenticator-app), 'locked', 'need_2fa' (van buiten zonder 2FA),
 *                      'local_only' (account alleen voor de interne netwerken) of false
 */
function login(string $username, string $password, bool $remember = false): string|false
{
    start_session();
    $u = q('SELECT id, username, password_hash, totp_secret, role, locked_at, locked_until, local_only, hibp, pw_pwned, pw_pwned_at FROM users WHERE username = ?', [$username])->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        Security::loginFailed($username, $u ? 'verkeerd wachtwoord' : 'onbekende gebruiker', (bool) $u);
        usleep(500000); // brute force afremmen
        return false;
    }
    // Geblokkeerd na te veel mislukte pogingen: alleen nog vanaf het lokale netwerk (zo kan niemand van buiten je buitensluiten).
    // Pas bij een juist wachtwoord zeggen we dat, zodat een aanvaller er niets aan heeft.
    if (Security::accountLocked($u) && !Security::fromLan()) {
        return 'locked';
    }
    // Account alleen voor de interne netwerken (bv. een noodaccount zonder 2FA): van buiten weigeren, met een ℹ️ (Systeeminfo)
    if ((int) $u['local_only'] && !Https::internal()) {
        Security::loginFailed($username, 'alleen lokaal: inlogpoging van buiten', true);
        $ip = client_ip();
        Monitor::sysInfo('local_only', null, null, '🏠 ' . t('Inlogpoging van buiten op lokaal account: {user}', ['user' => $u['username']]),
            fn() => t('{user} mag alleen vanaf het interne netwerk inloggen. Vanaf {ip} werd van buiten geprobeerd in te loggen, met het juiste wachtwoord: wijzig het wachtwoord van dit account.',
                ['user' => $u['username'], 'ip' => $ip]), $ip);
        return 'local_only';
    }
    // Datalekken (Have I Been Pwned): staat dit wachtwoord in een lek? Dan na het inloggen eerst een nieuw kiezen.
    if (Hibp::forUser($u)) {
        if ($u['pw_pwned_at'] === null || strtotime((string) $u['pw_pwned_at']) < time() - 86400) {
            Hibp::checkUser((int) $u['id'], $password);
        }
        if ((int) q('SELECT pw_pwned FROM users WHERE id = ?', [$u['id']])->fetchColumn() > 0) {
            q('UPDATE users SET pw_must_change = 1 WHERE id = ?', [$u['id']]);
        }
    }
    // Van buiten je interne netwerken 2FA verplicht, maar dit account heeft geen 2FA: weigeren (ook hier pas na een juist wachtwoord)
    if (!$u['totp_secret'] && Https::mfaRequired()) {
        Security::loginFailed($username, '2FA verplicht van buiten het interne netwerk', true);
        return 'need_2fa';
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
        Security::loginFailed((string) $u['username'], 'verkeerde 2FA-code', true);
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
    $_SESSION['mfa'] = $how !== 'wachtwoord';   // ingelogd met 2FA (telt van buiten je interne netwerken)
    q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
    if ($remember) {
        remember_create((int) $u['id']);
    }
    session_track($_SESSION['user'], $how === 'wachtwoord' ? 'password' : '2fa');
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
        'secure' => Https::isHttps(),
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
    q('INSERT INTO remember_tokens (user_id, selector, token_hash, device, ip, last_used, mfa) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $userId, $selector, hash('sha256', $validator), device_name(), mb_substr(client_ip(), 0, 45), now(), !empty($_SESSION['mfa']) ? 1 : 0,
    ]);
    $_SESSION['remember_id'] = (int) db()->lastInsertId();
    $_SESSION['remember_value'] = "$selector:$validator";
    $_SESSION['remember_touch'] = time();
    remember_cookie("$selector:$validator");
}

/**
 * Inloggen met de cookie. De sleutel wordt daarbij vervangen. Een oude sleutel die opnieuw
 * opduikt (gestolen cookie?) maakt het apparaat ongeldig. Geen 2FA nodig: dit apparaat is vertrouwd. Van buiten je interne
 * netwerken (als 2FA daar verplicht is) alleen een apparaat dat destijds met 2FA is onthouden.
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
    $t = q('SELECT r.*, u.username, u.role, u.locked_at, u.locked_until, u.local_only FROM remember_tokens r JOIN users u ON u.id = r.user_id WHERE r.selector = ?', [$m[1]])->fetch();
    if ($t && (int) $t['local_only'] && !Https::internal()) {
        return null;   // account alleen voor de interne netwerken: van buiten niet automatisch inloggen (het apparaat blijft bewaard)
    }
    if ($t && Security::accountLocked($t) && !Security::fromLan()) {
        return null;   // account geblokkeerd: niet automatisch inloggen (het apparaat blijft bewaard voor na het deblokkeren)
    }
    if ($t && !(int) $t['mfa'] && Https::mfaRequired()) {
        return null;   // van buiten alleen een apparaat dat met 2FA is onthouden; het apparaat blijft bewaard voor thuis
    }
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
    $_SESSION['mfa'] = (int) $t['mfa'] === 1;
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
    $_SESSION['user'] = ['id' => (int) $t['user_id'], 'username' => $t['username'], 'role' => $t['role']];
    session_track($_SESSION['user'], 'remember');
    return $_SESSION['user'];
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

// ------------------------------------------------------------------ ingelogde sessies (1.79.0)
// Elke sessie staat in user_sessions, zodat je (en een beheerder) ze kunt zien en uitloggen. Uitloggen werkt bij het
// volgende verzoek van dat apparaat: de regel is dan weg. Een onthouden apparaat wordt bij uitloggen ook vergeten.

/** Hoe lang een sessie zonder verzoek actief telt (zo lang bewaart PHP de sessie ook) */
function session_ttl(): int
{
    return max(600, (int) ini_get('session.gc_maxlifetime'));
}

/** Deze sessie vastleggen. how: password, 2fa, remember, lan, of existing (al ingelogd vóór 1.79.0) */
function session_track(array $user, string $how): void
{
    session_end();   // een vorige sessie in dezelfde browser (bv. LAN, daarna met je account)
    $key = bin2hex(random_bytes(32));
    q('INSERT INTO user_sessions (sid_hash, user_id, username, how, remember_id, device, ip, created_at, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        hash('sha256', $key), !empty($user['id']) ? (int) $user['id'] : null, mb_substr((string) $user['username'], 0, 64), $how,
        !empty($_SESSION['remember_id']) ? (int) $_SESSION['remember_id'] : null, device_name(), mb_substr(client_ip(), 0, 45), now(), now(),
    ]);
    $_SESSION['sess_row'] = (int) db()->lastInsertId();
    $_SESSION['sess_key'] = $key;
    $_SESSION['sess_touch'] = time();
}

/** Bestaat deze sessie nog (niet uitgelogd)? Hooguit eens per minuut "laatst actief" en het IP bijwerken. */
function session_alive(array $user): bool
{
    if (empty($_SESSION['sess_row'])) {
        session_track($user, 'existing');   // al ingelogd vóór de update: nu ook in de lijst
        return true;
    }
    $r = q('SELECT sid_hash, last_seen FROM user_sessions WHERE id = ?', [(int) $_SESSION['sess_row']])->fetch();
    if (!$r || !hash_equals((string) $r['sid_hash'], hash('sha256', (string) ($_SESSION['sess_key'] ?? '')))) {
        unset($_SESSION['sess_row'], $_SESSION['sess_key']);
        return false;
    }
    // Te lang stil (ook als PHP de sessie nog niet heeft opgeruimd): verlopen, zoals de lijst het ook toont
    if (strtotime((string) $r['last_seen']) < time() - session_ttl()) {
        session_end();
        return false;
    }
    if (time() - (int) ($_SESSION['sess_touch'] ?? 0) > 60) {
        q('UPDATE user_sessions SET last_seen = ?, ip = ?, remember_id = COALESCE(?, remember_id) WHERE id = ?',
            [now(), mb_substr(client_ip(), 0, 45), !empty($_SESSION['remember_id']) ? (int) $_SESSION['remember_id'] : null, (int) $_SESSION['sess_row']]);
        $_SESSION['sess_touch'] = time();
    }
    return true;
}

/** Deze sessie uit de lijst halen (uitloggen, of de sessie vervalt) */
function session_end(): void
{
    if (!empty($_SESSION['sess_row'])) {
        q('DELETE FROM user_sessions WHERE id = ? AND sid_hash = ?', [(int) $_SESSION['sess_row'], hash('sha256', (string) ($_SESSION['sess_key'] ?? ''))]);
    }
    unset($_SESSION['sess_row'], $_SESSION['sess_key'], $_SESSION['sess_touch']);
}

/** Id van de sessie van dit verzoek (0 = geen) */
function session_current(): int
{
    return (int) ($_SESSION['sess_row'] ?? 0);
}

/**
 * Ingelogde apparaten: actieve sessies, en onthouden apparaten die nu geen actieve sessie hebben (die loggen bij
 * het volgende bezoek vanzelf in). $userId null = van iedereen.
 * @return list<array> kind (session|device), id, user_id, username, how, device, ip, internal, since, last, remembered, current
 */
function session_list(?int $userId): array
{
    $nets = trusted_networks();
    $out = [];
    $active = [];
    $rows = q('SELECT id, user_id, username, how, remember_id, device, ip, created_at, last_seen FROM user_sessions WHERE last_seen > ?'
        . ($userId !== null ? ' AND user_id = ?' : '') . ' ORDER BY last_seen DESC',
        array_merge([date('Y-m-d H:i:s', time() - session_ttl())], $userId !== null ? [$userId] : []))->fetchAll();
    $tokens = [];
    foreach (q('SELECT r.id, r.user_id, u.username, r.device, r.ip, r.created_at, r.last_used, r.mfa FROM remember_tokens r JOIN users u ON u.id = r.user_id'
        . ($userId !== null ? ' WHERE r.user_id = ?' : '') . ' ORDER BY r.last_used DESC', $userId !== null ? [$userId] : [])->fetchAll() as $t) {
        $tokens[(int) $t['id']] = $t;
    }
    foreach ($rows as $r) {
        $rid = (int) $r['remember_id'];
        $remembered = $rid && isset($tokens[$rid]);
        if ($remembered) {
            $active[$rid] = true;
        }
        $out[] = ['kind' => 'session', 'id' => (int) $r['id'], 'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null, 'username' => $r['username'],
            'how' => $r['how'], 'device' => $r['device'], 'ip' => $r['ip'], 'internal' => $r['ip'] !== '' && ip_in_list($r['ip'], $nets),
            'since' => $r['created_at'], 'last' => $r['last_seen'], 'remembered' => $remembered, 'current' => (int) $r['id'] === session_current()];
    }
    foreach ($tokens as $id => $t) {
        if (!isset($active[$id])) {
            $out[] = ['kind' => 'device', 'id' => $id, 'user_id' => (int) $t['user_id'], 'username' => $t['username'], 'how' => 'remember',
                'device' => $t['device'], 'ip' => $t['ip'], 'internal' => $t['ip'] !== '' && ip_in_list($t['ip'], $nets),
                'since' => $t['created_at'], 'last' => $t['last_used'], 'remembered' => true, 'current' => false];
        }
    }
    return $out;
}

/**
 * Uitloggen: sessies en onthouden apparaten weghalen, behalve de sessie (en het onthouden apparaat) van dit verzoek.
 * $where: 'user' (alles van $id), 'session' (één sessie, $id) of 'all' (iedereen). @return aantal
 */
function session_logout(string $where, int $id = 0): int
{
    $me = session_current();
    $myToken = (int) ($_SESSION['remember_id'] ?? 0);
    if ($where === 'session') {
        $tok = (int) q('SELECT remember_id FROM user_sessions WHERE id = ?', [$id])->fetchColumn();
        $n = q('DELETE FROM user_sessions WHERE id = ? AND id <> ?', [$id, $me])->rowCount();
        if ($n && $tok && $tok !== $myToken) {
            q('DELETE FROM remember_tokens WHERE id = ?', [$tok]);
        }
        return $n;
    }
    $cond = $where === 'user' ? 'user_id = ?' : '1 = 1';
    $args = $where === 'user' ? [$id] : [];
    $n = q("DELETE FROM user_sessions WHERE $cond AND id <> ?", array_merge($args, [$me]))->rowCount();
    $n += q("DELETE FROM remember_tokens WHERE $cond AND id <> ?", array_merge($args, [$myToken]))->rowCount();
    return $n;
}

function logout(): void
{
    start_session();
    session_end();
    // Uitloggen = ook dit apparaat niet meer onthouden
    if (!empty($_SESSION['remember_id'])) {
        q('DELETE FROM remember_tokens WHERE id = ?', [(int) $_SESSION['remember_id']]);
    }
    remember_cookie(null);
    $_SESSION = [];
    session_destroy();
}

/** Moet deze gebruiker eerst een nieuw wachtwoord kiezen (het huidige staat in een datalek)? */
function must_change_password(?array $user): bool
{
    if (!$user || empty($user['id']) || is_lan_session()) {
        return false;
    }
    return (bool) q('SELECT pw_must_change FROM users WHERE id = ?', [$user['id']])->fetchColumn();
}

function csrf_token(): string
{
    start_session();
    return $_SESSION['csrf'];
}
