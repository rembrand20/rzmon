<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.225.0): inloggen, passkeys en wachtwoord vergeten; zonder sessie.
// Alleen via api.php: de variabelen hieronder (gebruiker, rechten, bevestigen …) komen daarvandaan.
/**
 * @var string $action
 * @var string $method
 */
defined('RZ_API') || exit;

// ---------- Webinterface van buiten: alleen op een toegestaan apparaat (1.86.0); anders een 404 ----------
Stealth::gate();

// ---------- Login ----------
// Inloggen via http uitgezet (Instellingen → Beveiliging → Certificaat en https): wachtwoorden alleen via https
if (in_array($action, ['login', 'login_totp', 'pw_reset'], true) && Https::loginBlocked()) {
    $to = Https::adminUrl();
    out(['error' => t('Inloggen via http staat uit: je wachtwoord zou leesbaar over het netwerk gaan.') . ($to !== '' ? ' ' . t('Open {url}', ['url' => $to]) : ''), 'https' => $to ?: null], 403);
}
if ($action === 'login' && $method === 'POST') {
    $in = input();
    $r = login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''), !empty($in['remember']));
    if ($r === 'totp') {
        out(['ok' => true, 'totp_required' => true]);
    }
    if ($r === 'ok') {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    if ($r === 'locked') {
        out(['error' => t('Dit account is geblokkeerd na te veel mislukte pogingen. Vraag een beheerder om het te deblokkeren.'), 'locked' => true], 403);
    }
    if ($r === 'local_only') {
        out(['error' => t('Dit account mag alleen vanaf het interne netwerk inloggen.'), 'local_only' => true], 403);
    }
    if ($r === 'device') {
        out(['error' => t('Dit apparaat is van buiten niet toegestaan voor dit account')], 403);
    }
    if ($r === 'need_2fa') {
        out(['error' => t('Van buiten je interne netwerken is tweestapsverificatie (2FA) verplicht. Stel eerst 2FA in vanaf je thuisnetwerk (Mijn account).'), 'need_2fa' => true], 403);
    }
    out(['error' => t('Onjuiste gebruikersnaam of wachtwoord')], 401);
}
if ($action === 'login_totp' && $method === 'POST') {
    $r = login_totp((string) (input()['code'] ?? ''));
    if ($r === true) {
        out(['ok' => true, 'csrf' => csrf_token()]);
    }
    // restart: opnieuw beginnen met het wachtwoord (de browser kijkt hiernaar, niet naar de tekst)
    $p = $_SESSION['pending_2fa'] ?? null;
    out(['error' => $r, 'restart' => !$p || $p['tries'] >= 5], 401);
}

// ---------- Inloggen met een passkey (1.86.0): in plaats van wachtwoord en 2FA ----------
if ($action === 'passkey_login_options' && $method === 'POST') {
    Passkey::possible() ? out(Passkey::loginOptions()) : out(['error' => t('Passkeys zijn hier niet beschikbaar')], 422);
}
if ($action === 'passkey_login' && $method === 'POST') {
    if (!Passkey::possible()) {
        out(['error' => t('Passkeys zijn hier niet beschikbaar')], 422);
    }
    $in = input();
    $u = Passkey::loginFinish($in);
    if (is_string($u)) {
        out(['error' => $u], 401);
    }
    if (!Stealth::userAllowed($u['id'])) {
        out(['error' => t('Dit apparaat is van buiten niet toegestaan voor dit account')], 403);
    }
    if (Security::accountLocked($u) && !Security::fromLan()) {
        out(['error' => t('Dit account is geblokkeerd na te veel mislukte pogingen. Vraag een beheerder om het te deblokkeren.'), 'locked' => true], 403);
    }
    if ((int) $u['local_only'] && !Https::internal()) {
        out(['error' => t('Dit account mag alleen vanaf het interne netwerk inloggen.'), 'local_only' => true], 403);
    }
    start_session();
    finish_login($u, !empty($in['remember']), 'passkey');
    out(['ok' => true, 'csrf' => csrf_token()]);
}

// ---------- Wachtwoord vergeten (zonder login) ----------
if ($action === 'pw_forgot' && $method === 'POST') {
    // Altijd hetzelfde antwoord: niemand kan zo uitzoeken welke accounts er bestaan
    $why = PasswordReset::unavailable();
    $send = $why === null ? PasswordReset::request((string) (input()['ident'] ?? '')) : null;
    if ($why !== null) {
        out(['ok' => true, 'no_mail' => true]);   // geen mail mogelijk: "vraag een beheerder"
    }
    http_response_code(200);
    echo json_encode(['ok' => true]);
    // De mail pas na het antwoord versturen: zo duurt het antwoord even lang, of het account nu bestaat of niet
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if ($send) {
        $send();
    }
    exit;
}
if ($action === 'pw_reset_check' && $method === 'POST') {
    $r = PasswordReset::check((string) (input()['token'] ?? ''));
    $r ? out(['ok' => true] + $r) : out(['error' => t('Deze link is verlopen of al gebruikt. Vraag een nieuwe aan via "Wachtwoord vergeten?".')], 410);
}
if ($action === 'pw_reset' && $method === 'POST') {
    $in = input();
    $who = PasswordReset::check((string) ($in['token'] ?? ''));
    $r = PasswordReset::reset((string) ($in['token'] ?? ''), (string) ($in['password'] ?? ''), (string) ($in['code'] ?? ''));
    // dead: de link werkt niet meer (verlopen, gebruikt of te veel pogingen); pwned: het nieuwe wachtwoord staat in een datalek
    $r === true ? out(['ok' => true, 'pwned' => $who ? (int) q('SELECT COALESCE(pw_pwned, 0) FROM users WHERE username = ? AND hibp = 1', [$who['username']])->fetchColumn() : 0])
        : out(['error' => $r, 'dead' => PasswordReset::check((string) ($in['token'] ?? '')) === null], 422);
}
