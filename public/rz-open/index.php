<?php
/**
 * 1.270.0: tijdelijk toegang van buiten (ProxyOpen). De reverse proxy laat dit ene pad door, ook als de site van RzSense
 * "alleen vanaf mijn netwerk" staat. Inloggen met wachtwoord én 2FA zet je huidige IP een paar uur open; daarna vanzelf dicht.
 * Op zichzelf staand (geen /assets): buiten je netwerk laat de proxy verder niets van RzSense door.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';
security_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$cookieLang = (string) ($_COOKIE[I18n::COOKIE] ?? '');
I18n::set(in_array($cookieLang, I18n::LANGS, true) ? $cookieLang : I18n::browser());
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);

if (!is_configured() || !ProxyOpen::enabled()) {
    http_response_code(404);
    exit;
}
$ip = client_ip();
$state = 'ask';
$msg = '';
$until = '';
$inside = Security::fromLan();
// Formulier-token tegen CSRF (in een cookie, de pagina heeft geen sessie)
$tok = (string) ($_COOKIE['rzopen'] ?? '');
if (!preg_match('/^[0-9a-f]{32}$/', $tok)) {
    $tok = bin2hex(random_bytes(16));
    setcookie('rzopen', $tok, ['expires' => time() + 900, 'path' => '/rz-open/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($tok, (string) ($_POST['t'] ?? ''))) {
        $state = 'error';
        $msg = t('De pagina was verlopen. Probeer het nog eens.');
    } else {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $dev = function_exists('device_name') ? device_name() : mb_substr($ua, 0, 120);
        $r = ProxyOpen::attempt(trim((string) ($_POST['u'] ?? '')), (string) ($_POST['p'] ?? ''), (string) ($_POST['c'] ?? ''), $ip, $dev);
        if ($r['ok']) {
            $state = 'done';
            $until = date('H:i', strtotime((string) $r['until']));
        } else {
            $state = 'error';
            $msg = (string) $r['error'];
        }
    }
}
$base = rtrim((string) setting('base_url', ''), '/') ?: '/';
?><!doctype html>
<html lang="<?= $e(I18n::lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= th('Toegang van buiten – RzSense') ?></title>
  <style>
    :root { --bg: #0d121b; --card: #151c28; --line: #283447; --fg: #e6ebf4; --muted: #94a0b6; --acc: #4cc3d4; --ok: #6fd69a; --bad: #ff8a80; color-scheme: dark; }
    @media (prefers-color-scheme: light) { :root { --bg: #f4f6fa; --card: #fff; --line: #d3dae6; --fg: #141a26; --muted: #5a6478; --acc: #0e7a8c; --ok: #1d7a46; --bad: #b42318; color-scheme: light; } }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding: 24px max(16px, env(safe-area-inset-left)); }
    main { width: 100%; max-width: 400px; background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 26px 22px; display: grid; gap: 14px; }
    h1 { margin: 0; font-size: 1.35rem; }
    .brand { font-weight: 800; letter-spacing: .02em; color: var(--acc); }
    p { margin: 0; color: var(--muted); font-size: .95rem; }
    label { display: grid; gap: 5px; font-size: .9rem; font-weight: 600; }
    input { font: inherit; padding: 11px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); color: var(--fg); }
    input:focus { outline: 2px solid var(--acc); outline-offset: 1px; }
    button, .btn { font: inherit; font-weight: 700; padding: 12px; border-radius: 12px; border: 0; background: var(--acc); color: #04161a; cursor: pointer; text-align: center; text-decoration: none; }
    .err { color: var(--bad); font-weight: 600; }
    .ok { color: var(--ok); font-weight: 700; font-size: 1.05rem; }
    .ip { font-family: ui-monospace, Menlo, monospace; color: var(--fg); }
  </style>
</head>
<body>
<main>
  <div class="brand">Rz//Sense</div>
  <?php if ($state === 'done'): ?>
    <h1>🔓 <?= th('Toegelaten') ?></h1>
    <p class="ok"><?= $e(t('Je adres {ip} mag tot {t} bij RzSense en je andere sites.', ['ip' => $ip, 't' => $until])) ?></p>
    <p><?= th('De reverse proxy neemt het binnen ongeveer een minuut over. Werkt het nog niet, wacht dan even en probeer opnieuw.') ?></p>
    <a class="btn" href="<?= $e($base) ?>"><?= th('Naar RzSense') ?></a>
    <p><?= th('Wissel je van netwerk (bijvoorbeeld van 5G naar wifi elders), dan heb je een nieuw adres en moet je dit opnieuw doen.') ?></p>
  <?php else: ?>
    <h1>🔒 <?= th('Toegang van buiten') ?></h1>
    <p><?= th('RzSense is alleen bereikbaar vanaf je eigen netwerk. Log in met je wachtwoord en de code uit je authenticator-app; dan mag dit adres een paar uur bij RzSense en je andere sites.') ?></p>
    <p><?= th('Je adres') ?>: <span class="ip"><?= $e($ip) ?></span><?= $inside ? ' · ' . th('al op je eigen netwerk') : '' ?></p>
    <?php if ($state === 'error'): ?><p class="err" role="alert"><?= $e($msg) ?></p><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="t" value="<?= $e($tok) ?>">
      <div style="display:grid;gap:12px">
        <label><?= th('Gebruikersnaam') ?><input name="u" autocomplete="username" required maxlength="64" autocapitalize="none"></label>
        <label><?= th('Wachtwoord') ?><input name="p" type="password" autocomplete="current-password" required maxlength="200"></label>
        <label><?= th('Code (2FA)') ?><input name="c" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required maxlength="7"></label>
        <button type="submit"><?= th('Laat dit adres toe') ?></button>
      </div>
    </form>
    <p><?= $e(t('Alleen met tweestapsverificatie. Na te veel foute pogingen wordt dit adres geblokkeerd. Je krijgt een melding als een adres wordt toegelaten.')) ?></p>
  <?php endif; ?>
</main>
</body>
</html>
