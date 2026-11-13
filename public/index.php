<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
security_headers();   // 1.143.0: CSP met nonce, nosniff …

// Niet ingesteld of database onbereikbaar → naar de setup
if (!is_configured()) {
    header('Location: setup.php');
    exit;
}
// Na een update (nieuwe VERSION) de database automatisch bijwerken
$schemaError = Installer::ensureSchema();
// Van buiten alleen op een toegestaan apparaat (1.86.0); anders een gewone 404
Stealth::gate();

try {
    // Database klaar maar nog geen beheerder (bv. na install-rocky.sh) → setup afmaken
    if (Installer::userCount() === 0) {
        header('Location: setup.php');
        exit;
    }
    // Op het LAN gekozen voor "Inloggen": niet automatisch (alleen lezen) binnenlaten
    if (isset($_GET['login'])) {
        start_session();
        $_SESSION = ['csrf' => $_SESSION['csrf'], 'no_lan_auto' => true];
    } elseif (isset($_GET['lan'])) {
        start_session();
        unset($_SESSION['no_lan_auto']);
    }
    $user = current_user();
    I18n::init($user);
} catch (PDOException $e) {
    header('Location: setup.php');
    exit;
}
$csrf = csrf_token();
$role = $user['role'] ?? 'readonly';
// Link uit de mail "wachtwoord opnieuw instellen"
$resetToken = preg_match('/^[0-9a-f]{64}$/', (string) ($_GET['reset'] ?? '')) ? (string) $_GET['reset'] : null;
// Wachtwoord staat in een datalek (1.69.0): eerst een nieuw kiezen
$mustChange = $user && !$resetToken && must_change_password($user);
// Tabblad Kwetsbaarheden: CVE's (scan aan) en/of Datalekken (controle aan, alleen beheerders)
$showVulns = Vulns::enabled() && $role !== 'readonly';
$showLeaks = $user && Hibp::enabled() && $role === 'admin';
$showEol = $user && Eol::enabled() && $role !== 'readonly';   // 1.227.0: Kwetsbaarheden → Verouderd
$showPatch = $user && $role !== 'readonly';   // 1.230.0: Kwetsbaarheden → Updates (ook als installeren uit staat: je ziet wat er klaarstaat)
$showStats = $role !== 'readonly';   // 1.106.0: 📊 Analyse, voor beheerders en gebruikers
$showNet = $role !== 'readonly' && (NetScan::enabled() || $role === 'admin');   // 1.103.0: netwerkscan
// Voorkeuren van de gebruiker; het thema (1.135.0) per gebruiker, zodat het op elk apparaat hetzelfde is
$prefsJson = $user && !$mustChange && !$resetToken && !empty($user['id']) && !is_lan_session() ? ((string) q('SELECT prefs FROM users WHERE id = ?', [$user['id']])->fetchColumn() ?: '{}') : null;
$userTheme = $prefsJson !== null ? ((json_decode($prefsJson, true) ?: [])['theme'] ?? null) : null;
$v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="<?= I18n::lang() ?>"<?= in_array($userTheme, ['light', 'dark'], true) ? ' data-theme="' . $userTheme . '"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <?php if ($resetToken): ?><meta name="referrer" content="no-referrer"><?php endif; ?>
  <title>Rz//Sense AI</title>
  <?= Brand::head() ?>
  <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
  <?php if ($userTheme === null): ?><script nonce="<?= csp_nonce() ?>">try{const t=localStorage.getItem('rzmon-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script><?php endif; ?>
</head>
<?php $sidePrefs = json_decode((string) ($prefsJson ?? ''), true) ?: []; $sideW = (int) ($sidePrefs['side_w'] ?? 0);
$skin = in_array($sidePrefs['skin'] ?? '', ['std', 's', 'glass', 'neo'], true) ? $sidePrefs['skin'] : (($sidePrefs['mon_glass'] ?? '') === 'on' ? 'glass' : 'std');   // 1.244.0: oude Liquid Glass-schakelaar = thema Liquid Glass
// 1.201.0: Liquid Glass en de laadanimatie, per account (meteen goed bij het laden, zonder flits)
$bodyCls = array_filter([($sidePrefs['side'] ?? '') === 'mini' ? 'side-mini' : '', $skin === 'glass' ? 'lg' : '', ($sidePrefs['mon_loader'] ?? '') === 'off' ? 'no-loader' : '', $skin === 'glass' && ($sidePrefs['mon_vivid'] ?? '') === 'on' ? 'lg-vivid' : '', $skin === 's' ? 'sk-s' : '', $skin === 'neo' ? 'sk-neo' : '']);   // 1.244.0: thema (Standaard, @S, Liquid Glass) ?>
<body<?= $bodyCls ? ' class="' . implode(' ', $bodyCls) . '"' : '' ?><?= $sideW >= 180 && $sideW <= 380 ? ' style="--side-w:' . $sideW . 'px"' : '' ?> data-role="<?= htmlspecialchars($role) ?>"<?php if ($user && !$mustChange && !$resetToken): ?> data-prefs="<?= htmlspecialchars($prefsJson ?? 'local') ?>"<?php endif; ?>>
<?php if ($resetToken): ?>
  <main class="login-wrap"><?= Brand::hudBg() ?>
    <form id="reset-form" class="card login-card" data-token="<?= htmlspecialchars($resetToken) ?>">
      <div class="brand big"><?= Brand::logo() ?></div>
      <?php if (!Https::isHttps()): ?><div class="http-bar<?= Https::loginBlocked() ? ' blocked' : '' ?>">🔓 <?= th('Deze verbinding is niet beveiligd: je wachtwoord en sessie zijn leesbaar op het netwerk.') ?></div><?php endif; ?>
      <h2 style="margin:0 0 .6rem"><?= th('Nieuw wachtwoord') ?></h2>
      <div id="reset-fields" hidden>
        <p class="muted small" style="margin-top:0"><?= t('Kies een nieuw wachtwoord voor {user}. Daarna worden alle apparaten waarop dit account ingelogd bleef, uitgelogd.', ['user' => '<b id="reset-user"></b>']) ?></p>
        <input type="text" name="username" autocomplete="username" hidden>
        <label><?= th('Nieuw wachtwoord') ?> <span class="hint"><?= th('(minstens 8 tekens)') ?></span><input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
        <label><?= th('Nog een keer') ?><input name="password2" type="password" autocomplete="new-password" minlength="8" required></label>
        <label id="reset-code-wrap" hidden><?= th('Code uit je authenticator-app') ?><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="mono totp-input"></label>
        <button class="btn primary block"><?= th('Wachtwoord opslaan') ?></button>
      </div>
      <p class="muted small" id="reset-status"><?= th('Link controleren…') ?></p>
      <p class="form-error" hidden></p>
      <p class="small login-links"><a href="index.php?login=1"><?= th('Naar inloggen') ?></a></p>
    </form>
  </main>
<?php elseif ($mustChange): ?>
  <main class="login-wrap"><?= Brand::hudBg() ?>
    <form id="pwforce-form" class="card login-card">
      <div class="brand big"><?= Brand::logo() ?></div>
      <h2 style="margin:0 0 .6rem">🔓 <?= th('Je wachtwoord staat in een datalek') ?></h2>
      <p class="muted small" style="margin-top:0"><?= t('Het wachtwoord van <b>{user}</b> komt voor in bekende datalekken (Have I Been Pwned). Aanvallers proberen zulke wachtwoorden als eerste. Kies een nieuw wachtwoord; daarna kun je verder.', ['user' => htmlspecialchars((string) $user['username'])]) ?></p>
      <input type="text" name="username" autocomplete="username" value="<?= htmlspecialchars((string) $user['username']) ?>" hidden>
      <label><?= th('Nieuw wachtwoord') ?> <span class="hint"><?= th('(minstens 8 tekens)') ?></span><input name="password" type="password" autocomplete="new-password" minlength="8" required autofocus></label>
      <label><?= th('Nog een keer') ?><input name="password2" type="password" autocomplete="new-password" minlength="8" required></label>
      <p class="muted small"><?= th('Tip: een zin van een paar woorden is sterk en makkelijk te onthouden. Ook dit wachtwoord wordt gecontroleerd; alleen een deel van de hash gaat naar HIBP, nooit het wachtwoord zelf.') ?></p>
      <p class="form-error" hidden></p>
      <button class="btn primary block"><?= th('Wachtwoord opslaan') ?></button>
      <p class="small login-links"><a href="#" id="pwforce-logout"><?= th('Uitloggen') ?></a></p>
    </form>
  </main>
<?php elseif (!$user): ?>
  <main class="login-wrap"><?= Brand::hudBg() ?>
    <form id="login-form" class="card login-card">
      <div class="brand big"><?= Brand::logo() ?></div>
      <?php if (!Https::isHttps()): $toHttps = Https::adminUrl(); ?>
        <div class="http-bar<?= Https::loginBlocked() ? ' blocked' : '' ?>">🔓 <?= Https::loginBlocked()
            ? th('Inloggen via http staat uit: je wachtwoord zou leesbaar over het netwerk gaan.')
            : th('Deze verbinding is niet beveiligd: je wachtwoord en sessie zijn leesbaar op het netwerk.') ?>
          <?php if ($toHttps !== ''): ?><a href="<?= htmlspecialchars($toHttps) ?>/"><?= th('Open {url}', ['url' => $toHttps]) ?></a><?php endif; ?></div>
      <?php endif; ?>
      <?php $refused = remember_refused(); if ($refused !== null): ?>
        <p class="callout small">🔑 <?= match ($refused) {
            'mfa' => th('Dit apparaat is onthouden, maar zonder 2FA. Van buiten je eigen netwerk moet je dan opnieuw inloggen. Log een keer in met 2FA en zet Onthoud mij aan; dan blijf je ook onderweg ingelogd.'),
            'local_only' => th('Dit account mag alleen vanaf je eigen netwerk inloggen.'),
            'locked' => th('Dit account is geblokkeerd na te veel mislukte pogingen.'),
            'expired' => th('Dit apparaat is langer dan 30 dagen niet gebruikt en daarom vergeten. Log opnieuw in.'),
            'reused' => th('Dit apparaat werd niet meer herkend (een oude sleutel) en is voor de zekerheid vergeten. Log opnieuw in en zet Onthoud mij aan.'),
            default => th('Dit apparaat wordt niet meer onthouden (vergeten bij Ingelogde apparaten). Log opnieuw in.'),
        } ?></p>
      <?php endif; ?>
      <div id="login-step1">
        <label><?= th('Gebruikersnaam') ?><input name="username" autocomplete="username" required autofocus></label>
        <label><?= th('Wachtwoord') ?><input name="password" type="password" autocomplete="current-password" required></label>
        <label class="switch small remember"><input type="checkbox" name="remember"><span></span> <?= th('Onthoud mij op dit apparaat') ?> <small class="muted"><?= th('(30 dagen)') ?></small></label>
      </div>
      <div id="login-step2" hidden>
        <p class="muted small" style="margin-top:0">🔐 <?= th('Voer de 6-cijferige code uit je authenticator-app in.') ?></p>
        <label><?= th('Code') ?><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="mono totp-input"></label>
      </div>
      <p class="form-error" hidden></p>
      <button class="btn primary block"><?= th('Inloggen') ?></button>
      <?php if (Passkey::possible()): ?>
        <button type="button" class="btn block" id="login-passkey" hidden>🔑 <?= th('Inloggen met een passkey') ?></button>
      <?php endif; ?>
      <p class="small login-links"><a href="#" id="forgot-link"><?= th('Wachtwoord vergeten?') ?></a></p>
      <?php if (lan_login_allowed()): ?>
        <p class="small" style="text-align:center;margin:.9rem 0 0"><a href="index.php?lan=1"><?= th('Verder zonder inloggen (alleen lezen)') ?></a></p>
      <?php endif; ?>
    </form>
    <form id="forgot-form" class="card login-card" hidden>
      <div class="brand big"><?= Brand::logo() ?></div>
      <h2 style="margin:0 0 .6rem"><?= th('Wachtwoord vergeten') ?></h2>
      <div id="forgot-fields">
        <p class="muted small" style="margin-top:0"><?= th('Vul je gebruikersnaam of e-mailadres in. Je krijgt een mail met een link om een nieuw wachtwoord te kiezen (30 minuten geldig).') ?></p>
        <label><?= th('Gebruikersnaam of e-mailadres') ?><input name="ident" autocomplete="username" required maxlength="190"></label>
        <button class="btn primary block"><?= th('Stuur herstellink') ?></button>
      </div>
      <p class="small" id="forgot-done" hidden></p>
      <p class="form-error" hidden></p>
      <p class="small login-links"><a href="#" id="forgot-back">← <?= th('Terug naar inloggen') ?></a></p>
    </form>
  </main>
<?php else: ?>
  <header class="topbar app-top">
    <div class="brand"><?= Brand::logo() ?> <a class="version" href="https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md" target="_blank" rel="noopener" title="<?= th('RzSense-versie – bekijk wat er nieuw is') ?>">v<?= htmlspecialchars(RZMON_VERSION) ?></a>
      <button type="button" class="update-badge need-admin" id="update-badge" hidden title="<?= th('Er is een nieuwe versie van RzSense: klik om bij te werken') ?>"></button>
      <!-- 1.216.0: levend statuslampje (wat er nu speelt), klik = Monitoring -->
      <button type="button" class="sb-status" id="sb-status" hidden><i aria-hidden="true"></i><span class="sb-t"></span><small><?= th('live') ?></small></button></div>
    <nav class="tabs main-tabs" role="tablist" id="main-tabs">
      <span class="tab-ind" aria-hidden="true"></span>
      <button class="tab mobile-only" data-tab="mds" role="tab" title="<?= th('Mobile device summary') ?>"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg><span class="tab-badge" id="mds-badge" hidden></span></span><span class="tab-lbl">mDS</span></button>
      <span class="tab-group side-only" role="presentation"><?= th('Overzicht') ?></span>
      <button class="tab active" data-tab="home" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14l4-4"/><path d="M3.3 19a10 10 0 1 1 17.4 0"/><circle cx="12" cy="14" r="1.6"/></svg></span><span class="tab-lbl has-s"><?= th('Dashboard') ?></span><span class="tab-lbl-s"><?= th('Start') ?></span></button>
      <button class="tab" data-tab="dashboard" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span class="tab-badge" id="dash-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Monitoring') ?></span><span class="tab-lbl-s"><?= th('Monitor') ?></span></button>
      <button class="tab" data-tab="alerts" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><span class="tab-badge" id="alerts-badge" hidden></span></span><span class="tab-lbl"><?= th('Meldingen') ?></span></button>
      <span class="tab-group side-only" role="presentation"><?= th('Beveiliging') ?></span>
      <button class="tab" data-tab="ids" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg><span class="tab-badge" id="ids-badge" hidden></span></span><span class="tab-lbl">IDS</span></button>
      <?php if ($role !== 'readonly'): ?><button class="tab" data-tab="incidents" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg><span class="tab-badge" id="inc-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Incidenten') ?></span><span class="tab-lbl-s"><?= th('Incid.') ?></span></button><?php endif; ?>
      <?php if ($showVulns || $showLeaks || $showEol): ?><button class="tab" data-tab="vulns" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 2 1.9 1.9M16 2l-1.9 1.9"/><path d="M9 7.1V6a3 3 0 1 1 6 0v1.1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v-9M6.5 13H3M21 13h-3.5M6 17l-2.5 2M18 17l2.5 2M6 9 3.5 7.5M18 9l2.5-1.5"/></svg><span class="tab-badge" id="vuln-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Kwetsbaarheden') ?></span><span class="tab-lbl-s"><?= th('CVE\'s') ?></span></button><?php endif; ?>
      <?php if ($showStats || $showNet): ?><span class="tab-group side-only" role="presentation"><?= th('Analyse en netwerk') ?></span><?php endif; ?>
      <?php if ($showStats): ?><button class="tab" data-tab="analytics" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg></span><span class="tab-lbl"><?= th('Analyse') ?></span></button><?php endif; ?>
      <?php if ($showStats): ?><button class="tab" data-tab="logs" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v13H5z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></span><span class="tab-lbl"><?= th('Logs') ?></span></button><?php endif; ?>
      <?php if ($showNet): ?><button class="tab" data-tab="net" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-2.5a1.5 1.5 0 0 1 1.5-1.5h11a1.5 1.5 0 0 1 1.5 1.5V17"/></svg><span class="tab-badge s1" id="net-badge" hidden></span></span><span class="tab-lbl"><?= th('Netwerk') ?></span></button><?php endif; ?>
      <!-- 1.196.0: op een telefoon de rest onder "Meer" (een blad van onderaf) -->
      <button class="tab mobile-only tab-more" type="button" id="tab-more" aria-haspopup="dialog" title="<?= th('Meer pagina\'s') ?>"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg><span class="tab-badge" id="more-badge" hidden></span></span><span class="tab-lbl"><?= th('Meer') ?></span></button>
      <?php if ($role === 'admin' || $role === 'user'): ?><button class="tab side-only" data-tab="settings" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></span><span class="tab-lbl"><?= th('Instellingen') ?></span></button><?php endif; ?>
    </nav>
    <nav class="side-admin" id="side-admin" hidden aria-label="<?= th('Beheer') ?>">
      <button type="button" class="side-back" id="side-back">← <?= th('Terug naar RzSense') ?></button>
      <div class="side-admin-t" id="side-admin-t"></div>
      <div id="side-admin-body"></div>
      <div class="side-acct" id="side-acct" hidden></div>
    </nav>
    <div class="side-resize" id="side-resize" role="separator" aria-orientation="vertical" tabindex="0" aria-label="<?= th('Breedte van de zijbalk') ?>" title="<?= th('Sleep om de zijbalk breder of smaller te maken; dubbelklik zet hem terug') ?>"></div>
    <div class="top-actions">
      <button type="button" class="side-toggle" id="side-toggle" aria-expanded="true" title="<?= th('Zijbalk inklappen tot iconen') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/><path d="M20 4v16"/></svg><span class="side-toggle-lbl"><?= th('Inklappen') ?></span></button>
      <button type="button" class="cmdk-btn" id="cmdk-btn" title="<?= th('Zoeken en doen (Ctrl+K)') ?>" aria-label="<?= th('Zoeken') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span class="cmdk-kbd">Ctrl K</span></button>
      <?php $hasAccount = !is_lan_session(); $uname = (string) ($user['username'] ?? ''); ?>
      <div class="acct" id="acct">
        <button type="button" class="acct-btn" id="acct-btn" aria-haspopup="menu" aria-expanded="false" title="<?= $hasAccount ? th('Je account, instellingen en uitloggen') : th('Ingelogd zonder wachtwoord omdat je op het lokale netwerk zit') ?>">
          <span class="avatar"><?= $hasAccount ? htmlspecialchars(mb_strtoupper(mb_substr($uname, 0, 1))) : '🏠' ?></span>
          <span class="acct-name"><?= $hasAccount ? htmlspecialchars($uname) : th('Lokaal netwerk') ?></span> <span class="acct-caret">▾</span></button>
        <div class="acct-menu" id="acct-menu" role="menu" hidden>
          <div class="acct-who"><b><?= $hasAccount ? htmlspecialchars($uname) : '🏠 ' . th('Lokaal netwerk') ?></b>
            <a class="acct-ver muted small" href="https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md" target="_blank" rel="noopener">RzSense v<?= htmlspecialchars(RZMON_VERSION) ?> · <?= th('wat is er nieuw?') ?></a>
            <span class="role-tag r-<?= htmlspecialchars($role) ?>"><?= th(ROLE_LABELS[$role] ?? $role) ?></span>
            <div class="muted small"><?= $hasAccount ? th('Ingelogd vanaf {ip}', ['ip' => client_ip()]) : th('Ingelogd zonder wachtwoord omdat je op het lokale netwerk zit') ?></div></div>
          <?php if ($hasAccount): ?><button type="button" role="menuitem" data-acct="account">👤 <?= th('Mijn account') ?></button><?php endif; ?>
          <?php if ($role === 'admin' || $role === 'user'): ?><button type="button" role="menuitem" data-acct="settings">⚙️ <?= th('Instellingen') ?></button><?php endif; ?>
          <?php if (!$hasAccount): ?><a role="menuitem" class="acct-item" href="index.php?login=1">🔑 <?= th('Inloggen met je account') ?></a><?php endif; ?>
          <div class="acct-sep"></div>
          <div class="acct-row">◈ <?= th('Lay-out') ?> <span class="seg" id="dash-seg"><button type="button" data-view-layout="blox">Blox</button><button type="button" data-view-layout="l1nes">L1nes</button><button type="button" data-view-layout="compact">Compact</button><button type="button" data-view-layout="cc">CC</button></span></div>
          <div class="acct-row">✦ <?= th('Thema') ?> <span class="seg" id="skin-seg"><button type="button" data-skin-set="std"><?= th('Standaard') ?></button><button type="button" data-skin-set="s">@S</button><button type="button" data-skin-set="glass"><?= th('Glas') ?></button><button type="button" data-skin-set="neo">Neo</button></span></div>
          <div class="acct-row">🌓 <?= th('Licht/donker') ?> <span class="seg" id="theme-seg"><button type="button" data-theme-set="auto">auto</button><button type="button" data-theme-set="light"><?= th('licht') ?></button><button type="button" data-theme-set="dark"><?= th('donker') ?></button></span></div>
          <div class="acct-row">🌐 <?= th('Taal') ?> <span class="seg" id="lang-seg"><button type="button" data-lang-set="nl" class="<?= I18n::lang() === 'nl' ? 'active' : '' ?>">NL</button><button type="button" data-lang-set="en" class="<?= I18n::lang() === 'en' ? 'active' : '' ?>">EN</button></span></div>
          <?php if ($hasAccount): ?><div class="acct-sep"></div><button type="button" role="menuitem" id="logout" class="danger-txt">⏻ <?= th('Uitloggen') ?></button><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <button type="button" class="cc-tvexit" id="cc-tvexit" hidden><?= th('✕ TV-modus uit') ?></button>
  <main class="container">
    <div class="iso-banner" id="iso-banner" role="alert" hidden></div><!-- 1.172.0: server in isolatie -->
    <header class="crumbs ph" id="crumbs" hidden></header><!-- 1.199.0 kruimelpad; 1.216.0: paginakop met titel, samenvatting en een altijd klikbaar pad -->
    <!-- 1.194.0: mDS, alleen op een telefoon -->
    <section id="tab-mds" class="tab-panel" role="tabpanel" aria-label="<?= th('Mobile device summary') ?>" hidden><div id="mds" class="mds"></div></section>
    <section id="tab-home" class="tab-panel" role="tabpanel" aria-label="<?= th('Dashboard') ?>">
      <div class="hm-head">
        <div class="hm-live" id="hm-live"></div>
        <div><h1 class="hm-title"><?= th('Zo staat het ervoor') ?></h1><p class="muted small" id="hm-sub"></p></div>
        <div class="hm-right"><div class="seg" id="hm-period" role="group" aria-label="<?= th('Periode') ?>"></div><div id="hm-tools"></div></div>
      </div>
      <div id="home"><p class="muted"><?= th('Laden…') ?></p></div>
    </section>
    <section id="tab-dashboard" class="tab-panel" role="tabpanel" hidden>
<?php if ($schemaError): ?>
      <div class="schema-banner">⚠ <?= htmlspecialchars($schemaError) ?> — <?= t('controleer de databaserechten of voer {cmd} uit.', ['cmd' => '<code>php install/install.php</code>']) ?></div>
<?php endif; ?>
      <?= xp(t('Al je servers en checks: wat goed gaat, wat aandacht nodig heeft en wat er speelt.'), t('<b>Wat het doet:</b> elke check (ping, poort, disk, dienst, certificaat …) controleert iets op een server en is OK, warning, critical of onbekend. <b>Zo gebruik je het:</b> begin bovenaan bij <i>Nu aandacht nodig</i>; klik op een server of check voor de details, de grafiek en wat je kunt doen. Met + voeg je een server of check toe. <b>Goed om te weten:</b> wanneer een check rood wordt en wanneer je een melding krijgt, stel je in bij Instellingen → Alarmgedrag en Meldingen.')) ?>
      <div class="maint-banner" id="maint-banner" hidden></div>
      <!-- 1.66.0: tellers onder de header (blijven staan), kerncijfers, "Nu aandacht nodig", werkbalk -->
      <div class="dash-counters" id="dash-counters">
        <div class="dash-in">
          <div class="health-counts" id="health-counts" role="group" aria-label="<?= th('Filter op status') ?>"></div>
          <div class="mres-row" id="mini-res" role="group" aria-label="<?= th('RzSense-server') ?>"></div><!-- 1.199.0; 1.219.0: de RzSense-kern -->
          <span class="dash-last muted small"><span class="live-dot" id="live-dot" title="<?= th('Live: wijzigingen komen binnen een paar seconden binnen') ?>"></span> <span id="last-run"></span></span>
        </div>
      </div>
      <div class="rzk-m" id="rz-core-m" role="group" aria-label="<?= th('RzSense-server') ?>"></div><!-- 1.219.0: telefoon -->
      <!-- 1.169.0: Checks (zoals altijd) of Beschikbaarheid per groep -->
      <div class="subtabs mon-tabs" role="tablist" aria-label="<?= th('Monitoring') ?>" id="mon-tabs">
        <button type="button" class="seg-btn active" data-msub="checks" role="tab" aria-selected="true"><svg class="st-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><?= th('Monitoring') ?></button>
        <button type="button" class="seg-btn" data-msub="cont" role="tab" aria-selected="false"><svg class="st-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></svg><?= th('Containers') ?></button>
        <button type="button" class="seg-btn" data-msub="paths" role="tab" aria-selected="false"><svg class="st-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="6" r="2"/><circle cx="19" cy="18" r="2"/><path d="M7 6h7a4 4 0 0 1 0 8h-4a4 4 0 0 0 0 8h7"/></svg><?= th('Netwerkpaden') ?></button>
        <button type="button" class="seg-btn" data-msub="avail" role="tab" aria-selected="false"><svg class="st-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V14M10 20V9M16 20V12M22 20V5"/></svg><?= th('Beschikbaarheid') ?></button>
      </div>
      <div class="pg-tools" data-pgtools="mon"></div>
      <div class="pgrid" data-pgrid="mon">
      <div class="pcard" data-pk="kpis" data-pw="12" data-pt="<?= th('Kerncijfers') ?>"><div class="kpis" id="health"></div></div>
      <div class="pcard" data-pk="attn" data-pw="12" data-pt="<?= th('Nu aandacht nodig') ?>"><section class="attn-sec" id="dash-attn"></section></div>
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Zoeken en filters') ?>"><div class="dash-bar">
        <input type="search" id="filter" placeholder="<?= th('🔍 Zoek server of check…') ?>">
        <div class="dash-chips" id="dash-chips" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="dash-sort" aria-label="<?= th('Sorteren') ?>">
          <option value="status"><?= th('Ergste eerst') ?></option>
          <option value="name"><?= th('Op naam') ?></option>
          <option value="cpu"><?= th('Hoogste CPU') ?></option>
          <option value="ram"><?= th('Hoogste RAM') ?></option>
          <option value="disk"><?= th('Volste disk') ?></option>
        </select>
        <div class="dash-more">
          <button class="btn" id="dash-more-btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
          <div class="dash-menu" id="dash-menu" role="menu" hidden>
            <button class="need-user" id="maint-all" type="button" role="menuitem">🔧 <?= th('Onderhoud') ?> <small><?= th('alle meldingen tijdelijk pauzeren') ?></small></button>
            <button class="need-user" id="run-all" type="button" role="menuitem">↻ <?= th('Alles nu checken') ?></button>
            <button id="toggle-all" type="button" role="menuitem">⊞ <?= th('Alles openen') ?></button>
          </div>
        </div>
        <button class="btn primary need-admin" id="add-host">＋ <?= th('Server') ?></button>
      </div></div>
      <div class="pcard" data-pk="hosts" data-pw="12" data-pt="<?= th('Servers') ?>"><div id="hosts" class="hosts"></div></div>
      </div>
      <div id="avail" class="avail" aria-live="polite"></div>
      <div id="containers" class="containers"></div>
      <div id="netpaths" class="netpaths"></div>
    </section>

    <section id="tab-alerts" class="tab-panel" role="tabpanel" hidden>
      <?= xp(t('Alles wat RzSense meldde, per dag: storingen, herstel en ℹ️-gebeurtenissen.'), t('<b>Wat het doet:</b> hier staat elke melding, ook die niet via Pushover of e-mail ging (bijvoorbeeld tijdens de stille uren). <b>Zo gebruik je het:</b> bevestig een storing, dan weet iedereen dat je ermee bezig bent en escaleert hij niet. Filter op status of server. <b>Goed om te weten:</b> hoe lang meldingen blijven staan, stel je in bij Instellingen → Opslag.')) ?>
      <!-- 1.67.0: zelfde opbouw als het dashboard -->
      <div class="dash-counters"><div class="dash-in">
        <span class="dash-lbl muted small"><?= th('Vandaag') ?></span>
        <div class="health-counts" id="alerts-counts" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-last muted small" id="alerts-info"></span>
      </div></div>
      <div class="pg-tools" data-pgtools="alerts"></div>
      <div class="pgrid" data-pgrid="alerts">
      <div class="pcard" data-pk="kpis" data-pw="12" data-pt="<?= th('Kerncijfers') ?>"><div class="kpis" id="alerts-kpis"></div></div>
      <div class="pcard cc-only" data-pk="hour" data-pw="8" data-pt="<?= th('Meldingen per uur') ?>"><div class="cc-card" id="alerts-ch-hour"></div></div>
      <div class="pcard cc-only" data-pk="chan" data-pw="4" data-pt="<?= th('Per kanaal') ?>"><div class="cc-card" id="alerts-ch-chan"></div></div>
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Zoeken en filters') ?>"><div class="dash-bar">
        <input type="search" id="alerts-search" placeholder="<?= th('🔍 Zoek in meldingen…') ?>">
        <div class="dash-chips" id="alerts-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="alerts-host" aria-label="<?= th('Server') ?>" hidden></select>
        <select id="alerts-sort" class="alerts-sort" aria-label="<?= th('Sorteren') ?>" title="<?= th('Sorteren (binnen elke dag)') ?>">
          <option value=""><?= th('Nieuwste eerst') ?></option>
          <option value="time:1"><?= th('Oudste eerst') ?></option>
          <option value="status:1"><?= th('Status: ernstigste eerst') ?></option>
          <option value="status:-1"><?= th('Status: OK eerst') ?></option>
          <option value="server:1"><?= th('Server A–Z') ?></option>
          <option value="server:-1"><?= th('Server Z–A') ?></option>
          <option value="check:1"><?= th('Check A–Z') ?></option>
          <option value="check:-1"><?= th('Check Z–A') ?></option>
          <option value="message:1"><?= th('Bericht A–Z') ?></option>
          <option value="channels:1"><?= th('Kanalen: mislukt eerst') ?></option>
        </select>
        <div class="dash-more">
          <button class="btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
          <div class="dash-menu" role="menu" hidden>
            <button type="button" id="alerts-toggle" role="menuitem">⊞ <?= th('Alles openen') ?></button>
          </div>
        </div>
      </div></div>
      <div class="pcard" data-pk="list" data-pw="12" data-pt="<?= th('Meldingen') ?>"><div id="alerts" class="alert-list"></div></div>
      </div>
    </section>

    <section id="tab-ids" class="tab-panel" role="tabpanel" hidden>
      <!-- 1.67.0: zelfde opbouw als het dashboard -->
      <div class="dash-counters"><div class="dash-in">
        <div class="health-counts" id="ids-counts"></div>
        <span class="dash-last muted small" id="ids-last"></span>
      </div></div>
      <div class="pg-tools" data-pgtools="ids"></div>
      <div class="pgrid" data-pgrid="ids">
      <div class="pcard" data-pk="kpis" data-pw="12" data-pt="<?= th('Kerncijfers') ?>"><div class="kpis" id="ids-kpis"></div>
      </div>
      <div class="pcard" data-pk="attn" data-pw="12" data-pt="<?= th('Nu aandacht nodig') ?>"><div class="attn-sec" id="ids-attn" hidden></div>
      </div>
      <div class="pcard" data-pk="hosts" data-pw="6" data-pt="<?= th('Servers met open incidenten') ?>"><div class="srv-list" id="ids-hosts"></div>
      </div>
      <div class="pcard" data-pk="chart" data-pw="6" data-pt="<?= th('Aanvallen per dag') ?>"><div id="ids-chart" hidden></div>
      </div>
      <div class="pcard" data-pk="sites" data-pw="12" data-pt="<?= th('Aanvallen per site') ?>" id="ids-sites-card" hidden><div class="card" id="ids-sites"></div>
      </div>
      <div class="pcard" data-pk="tabs" data-pw="12" data-pt="<?= th('Tabbladen') ?>"><div class="subtabs ids-tabs" role="tablist" aria-label="IDS" id="ids-tabs">
        <!-- 1.234.1: eerst waar je iets doet (belangrijkste eerst), dan wat je bekijkt -->
        <button type="button" class="seg-btn active" data-it="inc"><?= th('⚠️ Incidenten') ?> <span class="it-n" id="it-n-inc"></span></button>
        <button type="button" class="seg-btn" data-it="blk"><?= th('⛔ Blokkades') ?> <span class="it-n" id="it-n-blk"></span></button>
        <button type="button" class="seg-btn" data-it="apps"><?= th('🧩 Programma\'s') ?> <span class="it-n" id="it-n-apps"></span></button>
        <button type="button" class="seg-btn" data-it="hunt"><?= th('🔎 Jagen') ?></button>
        <span class="it-sep" role="separator" aria-hidden="true"></span>
        <button type="button" class="seg-btn" data-it="anom"><?= th('🧬 Afwijkingen') ?> <span class="it-n" id="it-n-anom"></span></button>
        <button type="button" class="seg-btn" data-it="prot"><?= th('🛡️ Bescherming') ?> <span class="it-n" id="it-n-prot"></span></button>
        <button type="button" class="seg-btn" data-it="acc"><?= th('🔑 Toegang') ?> <span class="it-n" id="it-n-acc"></span></button>
        <button type="button" class="seg-btn" data-it="live"><?= th('🛰️ Live') ?></button>
        <button type="button" class="seg-btn" data-it="map"><?= th('🗺️ Aanvalskaart') ?></button>
        <button type="button" class="seg-btn" data-it="mitre"><?= th('🧭 MITRE') ?></button>
      </div>
      <!-- 1.149.0: aanvalskaart -->
      <div class="ids-pane" data-pane="map" hidden>
        <div class="dash-bar am-bar">
          <div class="seg" id="am-win" role="group" aria-label="<?= th('Periode') ?>"><button type="button" class="seg-btn active" data-amw="15m"><?= th('Nu (15 min)') ?></button><button type="button" class="seg-btn" data-amw="1h"><?= th('1 uur') ?></button><button type="button" class="seg-btn" data-amw="24h"><?= th('24 uur') ?></button><button type="button" class="seg-btn" data-amw="7d"><?= th('7 dagen') ?></button><button type="button" class="seg-btn" data-amw="30d"><?= th('30 dagen') ?></button></div>
          <select id="am-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <select id="am-cc" aria-label="<?= th('Land') ?>"><option value=""><?= th('Alle landen') ?></option></select>
          <select id="am-cat" aria-label="<?= th('Soort aanval') ?>"><option value=""><?= th('Alle soorten') ?></option></select>
          <div class="seg" id="am-dir" role="group" aria-label="<?= th('Richting') ?>"><button type="button" class="seg-btn active" data-amdir=""><?= th('Beide') ?></button><button type="button" class="seg-btn" data-amdir="i"><?= th('Inkomend') ?></button><button type="button" class="seg-btn" data-amdir="o"><?= th('Uitgaand') ?></button></div>
          <div class="seg am-view" id="am-view" role="group" aria-label="<?= th('Weergave') ?>"><button type="button" class="seg-btn active" data-amv="flow">Attack Flow</button><button type="button" class="seg-btn" data-amv="world"><?= th('Wereldkaart') ?></button></div>
        </div>
        <div id="am-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="prot" hidden>
        <?= xp(t('Welke beschermingen aan staan, per onderdeel, met hun drempels.'), t('<b>Wat het doet:</b> één overzicht van alle onderdelen van het IDS (brute force, lokaas, webaanvallen, ransomware …) en hoe ze staan. <b>Zo gebruik je het:</b> zet een onderdeel aan of uit en stel met ⚙️ de drempels in. Of de agents echt blokkeren of alleen melden, bepaalt de modus bovenaan. <b>Goed om te weten:</b> je eigen netwerken en de whitelist worden nooit geblokkeerd, en RzSense zelf ook niet.')) ?>
        <div id="prot-mode"></div>
        <div class="card" id="prot-cards"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="anom" hidden>
        <div class="dash-bar"><select id="anom-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <div class="seg" id="anom-days"><button type="button" class="seg-btn" data-ad="1"><?= th('24 uur') ?></button><button type="button" class="seg-btn active" data-ad="7"><?= th('7 dagen') ?></button><button type="button" class="seg-btn" data-ad="30"><?= th('30 dagen') ?></button></div>
          <span class="muted small" id="anom-info"></span></div>
        <div class="card" id="anom-learn"><p class="muted"><?= th('Laden…') ?></p></div>
        <div class="card" id="anom-chains"></div>
        <div class="card" id="anom-list"></div>
      </div>
      <!-- 1.229.0: onbekende programma's en jagen op sporen -->
      <div class="ids-pane" data-pane="apps" hidden>
        <?= xp(t('Programma\'s op je servers die RzSense nog niet kende.'), t('<b>Wat het doet:</b> de agent leert eerst wat normaal is; daarna zie je hier elk nieuw programma met pad, pakket en vingerafdruk. <b>Zo gebruik je het:</b> keur een programma goed als het hoort; ken je het niet, onderzoek het dan of zoek het op met <i>Jagen</i>. <b>Goed om te weten:</b> een programma uit een pakket van je distributie is meestal in orde; iets in <code>/tmp</code> of een verborgen map niet.')) ?>
        <div class="dash-bar"><select id="apps-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <input id="apps-q" type="search" placeholder="<?= th('Zoeken: pad, pakket, vingerafdruk …') ?>" autocomplete="off">
          <a class="btn small-btn need-admin" href="#settings/ids_apps" data-goto="settings/ids_apps">⚙️ <?= th('Instellen') ?></a></div>
        <div class="card" id="apps-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="hunt" hidden>
        <?= xp(t('Zoek sporen van een bekende aanval op al je servers tegelijk.'), t('<b>Wat het doet:</b> plak IP-adressen, domeinen, vingerafdrukken of paden (bijvoorbeeld uit een waarschuwing van het NCSC); RzSense zoekt ze in alles wat het weet en desgewenst op de servers zelf. <b>Zo gebruik je het:</b> <i>Zoeken</i> doet het één keer; met <i>Bewaken</i> krijg je een melding als een spoor later opduikt. <b>Goed om te weten:</b> alleen een beheerder kan zoeken en bewaken.')) ?>
        <div class="callout small" id="hunt-off" hidden><?= th('Jagen op sporen staat uit (Instellingen → IDS → Jagen op sporen).') ?></div>
        <div class="hunt-grid">
          <div class="card">
            <h3 class="set-sub">🔎 <?= th('Sporen zoeken') ?></h3>
            <p class="muted small"><?= th('Plak IP-adressen, domeinen, URL\'s, vingerafdrukken (sha256) of paden, bijvoorbeeld uit een waarschuwing van het NCSC. Ook 1.2.3[.]4 en hxxp:// werken.') ?></p>
            <textarea id="hunt-text" rows="7" class="mono" placeholder="185.220.101.4&#10;evil-domain[.]com&#10;/tmp/.x/kworker&#10;e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"></textarea>
            <div class="btn-row"><label><?= th('Periode') ?> <select id="hunt-days"><option value="7">7 <?= th('dagen') ?></option><option value="30" selected>30 <?= th('dagen') ?></option><option value="90">90 <?= th('dagen') ?></option><option value="365">365 <?= th('dagen') ?></option></select></label>
              <label class="switch small need-admin"><input type="checkbox" id="hunt-agents" checked><span></span> <?= th('Ook op de servers zoeken (paden en vingerafdrukken)') ?></label></div>
            <div class="btn-row"><button type="button" class="btn primary need-admin" id="hunt-go">🔎 <?= th('Zoeken') ?></button>
              <button type="button" class="btn need-admin" id="hunt-watch">👁 <?= th('Bewaken') ?></button></div>
            <div id="hunt-result"></div>
          </div>
          <div class="card" id="hunt-side"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>
      </div>
      <div class="ids-pane" data-pane="mitre" hidden>
        <div class="dash-bar"><select id="mitre-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <div class="seg" id="mitre-days"><button type="button" class="seg-btn" data-md="7"><?= th('7 dagen') ?></button><button type="button" class="seg-btn active" data-md="30"><?= th('30 dagen') ?></button><button type="button" class="seg-btn" data-md="90"><?= th('90 dagen') ?></button></div>
          <label class="switch small"><input type="checkbox" id="mitre-cover"><span></span> <?= th('Ook wat de regels kunnen zien') ?></label></div>
        <div class="card" id="mitre-matrix"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="live" hidden>
        <!-- 1.219.0: Live begrijpelijk: oordeel, soorten, Aandacht/Alles/Toegestaan, gewone taal, uitleg en acties -->
        <div id="edr-hero"></div>
        <div class="lv-kinds" id="edr-kinds" role="group" aria-label="<?= th('Soorten') ?>"></div>
        <div class="lv-bar">
          <div class="lv-seg" id="edr-mode" role="tablist" aria-label="<?= th('Wat tonen') ?>">
            <button type="button" data-lv-mode="hits"><?= th('Aandacht') ?> <em id="edr-n-hits"></em></button>
            <button type="button" data-lv-mode="all"><?= th('Alles') ?> <em id="edr-n-all"></em></button>
            <button type="button" data-lv-mode="own"><?= th('Toegestaan') ?> <em id="edr-n-own"></em></button>
          </div>
          <select id="edr-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <span class="muted small lv-info" id="edr-info"></span>
        </div>
        <div id="edr-feed"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="inc">
        <?= xp(t('Mogelijke aanvallen die het IDS zag, met wat het deed en wat jij kunt doen.'), t('<b>Wat het doet:</b> elk incident vertelt waarom het IDS iets verdacht vond, wat het deed (bijvoorbeeld blokkeren) en op welke server. <b>Zo gebruik je het:</b> open een incident en kies: afhandelen, het IP-adres blokkeren, of vrijgeven als het normaal is (dan komt het niet terug). <b>Goed om te weten:</b> op de pagina Incidenten staan deze samen met storingen en kwetsbaarheden in één lijst.')) ?>
        <div class="dash-bar">
          <input type="search" id="ids-search" placeholder="<?= th('🔍 Zoek incident, IP of server…') ?>">
          <div class="dash-chips" id="ids-filter" role="group" aria-label="Filter"></div>
          <span class="dash-sp"></span>
          <span class="muted small" id="ids-info"></span>
          <a class="btn small-btn" href="#incidents" data-goto="incidents" title="<?= th('Eén lijst met de incidenten uit monitoring, het IDS en kwetsbaarheden') ?>">🧾 <?= th('Alle incidenten') ?> →</a>
          <button type="button" class="btn need-admin" id="ids-block-add"><?= th('⛔ IP blokkeren') ?></button>
          <div class="dash-more">
            <button class="btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
            <div class="dash-menu" role="menu" hidden><button type="button" id="ids-toggle" role="menuitem"><?= th('⊞ Alles openen') ?></button></div>
          </div>
        </div>
        <div id="ids-incidents" class="alert-list"></div>
      </div>
      <div class="ids-pane" data-pane="acc" hidden>
        <?= xp(t('Wie er op je servers inlogde: gelukt, mislukt en vanaf waar.'), t('<b>Wat het doet:</b> elke SSH-login en -poging die de agents zagen, met gebruiker, IP-adres, land en methode. <b>Zo gebruik je het:</b> zoek op gebruiker of IP-adres; een gelukte login vanaf een onbekend adres verdient aandacht. <b>Goed om te weten:</b> veel mislukte pogingen worden een brute-force-incident en kunnen worden geblokkeerd.')) ?>
        <div class="dash-bar">
          <input type="search" id="acc-search" placeholder="<?= th('🔍 Zoek gebruiker, IP of server…') ?>">
          <div class="dash-chips" id="acc-filter" role="group" aria-label="<?= th('Soort') ?>">
            <button type="button" class="dchip seg-btn on active" data-ac="all"><?= th('Alles') ?></button>
            <button type="button" class="dchip seg-btn" data-ac="ssh">SSH</button>
            <button type="button" class="dchip seg-btn" data-ac="console">Console</button>
            <button type="button" class="dchip seg-btn" data-ac="sudo">sudo</button>
            <button type="button" class="dchip seg-btn" data-ac="su">su</button>
            <button type="button" class="dchip seg-btn" data-ac="fail"><?= th('❌ Mislukt') ?></button>
          </div>
          <div class="dash-chips" id="acc-ssh" role="group" aria-label="<?= th('SSH') ?>" hidden>
            <button type="button" class="dchip seg-btn on active" data-acs="all"><?= th('Alles') ?></button>
            <button type="button" class="dchip seg-btn" data-acs="ok"><?= th('✔ Gelukt (sessies)') ?></button>
            <button type="button" class="dchip seg-btn" data-acs="fail"><?= th('❌ Mislukt') ?></button>
          </div>
          <span class="dash-sp"></span>
          <span class="muted small" id="acc-info"></span>
          <select id="acc-host" class="alerts-sort" aria-label="Server" hidden><option value=""><?= th('Alle servers') ?></option></select>
          <div class="dash-more">
            <button class="btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
            <div class="dash-menu" role="menu" hidden><button type="button" id="acc-toggle" role="menuitem"><?= th('⊞ Alles openen') ?></button></div>
          </div>
        </div>
        <div id="ids-access" class="alert-list"></div>
      </div>
      <div class="ids-pane" data-pane="blk" hidden>
        <div class="dash-bar"><span class="muted small" id="ids-block-info"></span></div>
        <div class="card" id="ids-blocks"></div>
      <div class="card need-admin" id="blocks-card">
        <h2>⛔ <?= th('Blokkades') ?></h2>
        <?= xp(t('Een IP-adres of netwerk blokkeren, voor heel RzSense.'), t('Een geblokkeerd IP-adres of netwerk krijgt nergens meer toegang tot RzSense: niet de webpagina, niet het inloggen, niet de API en geen agents. Jezelf en de RzSense-server kun je niet blokkeren. Toch buitengesloten? Op de server: <code>sudo php install/install.php --unblock-all</code>')) ?>
        <div class="block-add"><input id="block-net" class="mono" placeholder="<?= th('bv. 203.0.113.7 of 203.0.113.0/24') ?>" autocomplete="off">
          <input id="block-reason" placeholder="<?= th('reden (optioneel)') ?>" autocomplete="off">
          <button type="button" class="btn danger" id="block-add">⛔ <?= th('Blokkeren') ?></button></div>
        <?= xp(t('Alles wat nu geblokkeerd is, met deblokkeren.'), th('Hieronder alles wat nu geblokkeerd is: IP-adressen in RzSense, vergrendelde accounts en de IDS-blokkades op je servers. Deblokkeren werkt meteen; op de whitelist zetten heft de blokkade ook op en voorkomt een nieuwe.')) ?>
        <div class="bl-bar"><div class="seg" id="bl-tabs"><button type="button" class="seg-btn active" data-bl-tab="active"></button><button type="button" class="seg-btn" data-bl-tab="history"></button></div>
          <select id="bl-src"><option value=""><?= th('Alles') ?></option><option value="rz"><?= th('IP in RzSense') ?></option><option value="user"><?= th('Accounts') ?></option><option value="ids"><?= th('IDS (servers)') ?></option></select>
          <input id="bl-q" type="search" placeholder="<?= th('Zoeken: IP, naam, server, reden…') ?>" autocomplete="off"></div>
        <div id="blocks-list"></div>
        <p class="small wl-link"><span id="wl-count" class="muted"></span></p>
      </div>
      <div class="card need-admin" id="wl-card">   <!-- 1.236.0: de whitelist staat bij de blokkades -->
          <h2>✅ <?= th('Whitelist (nooit blokkeren)') ?></h2>
          <?= xp(t('IP-adressen, netwerken en accounts die nooit geblokkeerd worden.'), t('<b>Wat het doet:</b> een IP-adres of netwerk op de whitelist wordt nergens geblokkeerd: niet in RzSense en niet door het IDS op welke server dan ook, ook niet via de lijsten met bekende aanvallers. Een account op de whitelist wordt nooit vergrendeld (het IP van de aanvaller wel). <b>Hoe lang:</b> je kiest 1, 7 of 30 dagen of altijd. <b>Goed om te weten:</b> je eigen netwerk en RzSense zelf worden al nooit automatisch geblokkeerd. Bij een blokkade of incident zet je een adres ook met één klik op de whitelist.')) ?>
          <div class="btn-row"><button type="button" class="btn" id="wl-add">✅ <?= th('Op de whitelist zetten…') ?></button></div>
          <div id="wl-list"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

      </div>
      </div>
      </div>
    </section>

    <?php if ($showVulns || $showLeaks || $showEol || $showPatch): ?>
    <section id="tab-vulns" class="tab-panel" role="tabpanel" hidden>
      <?php if ((int) $showVulns * 2 + (int) $showLeaks + (int) $showEol + (int) $showPatch > 1): ?>
      <div class="subtabs vuln-tabs" role="tablist" aria-label="<?= th('Kwetsbaarheden') ?>" id="vuln-tabs">
        <?php if ($showVulns): ?><button type="button" class="seg-btn active" data-vt="cve">🐞 <?= th('Kwetsbaarheden') ?> <span class="it-n" id="vt-n-cve"></span></button><?php endif; ?>
        <?php if ($showVulns): ?><button type="button" class="seg-btn" data-vt="sw">📦 <?= th('Software') ?> <span class="it-n" id="vt-n-sw"></span></button><?php endif; ?>
        <?php if ($showEol): ?><button type="button" class="seg-btn" data-vt="eol">⏳ <?= th('Verouderd') ?> <span class="it-n" id="vt-n-eol"></span></button><?php endif; ?>
        <?php if ($showPatch): ?><button type="button" class="seg-btn" data-vt="patch">🩹 <?= th('Updates') ?> <span class="it-n" id="vt-n-patch"></span></button><?php endif; ?>
        <?php if ($showLeaks): ?><button type="button" class="seg-btn" data-vt="leak">🔓 <?= th('Datalekken') ?> <span class="it-n" id="vt-n-leak"></span></button><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($showVulns): ?>
      <!-- 1.248.0: één zoekvak voor servers, CVE's en software -->
      <div class="vs-bar" id="vs-bar">
        <input type="search" id="vs-q" placeholder="<?= th('🔎 Zoek een server, een CVE (bv. CVE-2024-6387) of een programma (bv. openssl)…') ?>" autocomplete="off" aria-label="<?= th('Zoek een server, CVE of programma') ?>" aria-controls="vs-sug" aria-expanded="false">
        <div class="vs-sug" id="vs-sug" role="listbox" hidden></div>
      </div>
      <div class="vuln-pane" data-vpane="cve">
      <?= xp(t('Bekende kwetsbaarheden in de software op je servers, de belangrijkste eerst.'), t('<b>Wat het doet:</b> de agents sturen de lijst met geïnstalleerde pakketten; RzSense vergelijkt die met bekende kwetsbaarheden en weegt mee of een lek actief wordt misbruikt (KEV) en hoe waarschijnlijk dat is (EPSS). <b>Zo gebruik je het:</b> begin bij actief misbruikt en critical; vaak lost één update er veel tegelijk op. Geldt iets niet voor jou, zet het dan op niet van toepassing. <b>Goed om te weten:</b> bijwerken doe je via het tabblad Updates of je eigen pakketbeheer.')) ?>
      <!-- 1.67.0: zelfde opbouw als het dashboard -->
      <div class="dash-counters"><div class="dash-in">
        <div class="health-counts" id="vuln-counts"></div>
        <span class="dash-last muted small" id="vuln-info"></span>
      </div></div>
      <div class="pg-tools" data-pgtools="vuln"></div>
      <div class="pgrid" data-pgrid="vuln">
      <div class="pcard" data-pk="banner" data-pw="12" data-pt="<?= th('Melding') ?>"><div id="vuln-banner"></div>
      </div>
      <div class="pcard" data-pk="kpis" data-pw="12" data-pt="<?= th('Kerncijfers') ?>"><div class="kpis" id="vuln-kpis"></div>
      </div>
      <div class="pcard cc-only" data-pk="cc_sev" data-pw="4" data-pt="<?= th('Per ernst') ?>"><div class="cc-card" id="vuln-cc-sev"></div>
      </div>
      <div class="pcard cc-only" data-pk="cc_hosts" data-pw="8" data-pt="<?= th('Servers met de meeste kwetsbaarheden') ?>"><div class="cc-card" id="vuln-cc-hosts"></div>
      </div>
      <div class="pcard" data-pk="oneup" data-pw="12" data-pt="<?= th('Eén update, veel opgelost') ?>"><div id="vuln-oneup"></div>
      </div>
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Zoeken en filters') ?>"><div class="dash-bar">
        <input type="search" id="vuln-search" placeholder="<?= th('🔍 Zoek pakket, CVE of server…') ?>" autocomplete="off">
        <div class="dash-chips" id="vuln-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="vuln-sev" aria-label="<?= th('Ernst') ?>">
          <option value="0"><?= th('Alle ernst') ?></option><option value="2"><?= th('Vanaf medium') ?></option>
          <option value="3"><?= th('Vanaf high') ?></option><option value="4"><?= th('Alleen critical') ?></option>
        </select>
        <button type="button" class="btn need-admin" id="vuln-scan">🔍 <?= th('Nu scannen') ?></button>
        <button type="button" class="btn primary need-admin" id="vuln-up-all" title="<?= th('Alle servers bijwerken waarvoor een oplossing is, met één bevestiging') ?>">⬆ <?= th('Alles bijwerken') ?></button>
        <a class="btn" id="vuln-csv" href="api.php?action=vuln_csv&amp;kind=findings" title="<?= th('Alle open kwetsbaarheden als CSV-bestand (opent in Excel)') ?>">⤓ CSV</a>
        <div class="dash-more">
          <button class="btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
          <div class="dash-menu" role="menu" hidden><button type="button" id="vuln-toggle" role="menuitem"><?= th('⊞ Alles openen / sluiten') ?></button></div>
        </div>
      </div>
      </div>
      <div class="pcard" data-pk="list" data-pw="12" data-pt="<?= th('Servers') ?>"><div id="vuln-hosts"></div>
      </div>
      </div>
      </div>
      <?php endif; ?>
      <?php if ($showVulns): ?>
      <!-- 1.248.0: software over al je servers: versies per besturingssysteem, open CVE's en vanaf welke versie het veilig is -->
      <div class="vuln-pane" data-vpane="sw" hidden>
      <?= xp(t('Elk programma over al je servers: welke versies waar staan, welke CVE\'s nog open staan en vanaf welke versie het veilig is.'), t('<b>Wat het doet:</b> RzSense zet de softwarelijsten van al je servers naast elkaar. Per programma zie je per besturingssysteem de versies, op welke servers ze staan, de open CVE\'s en <i>veilig vanaf</i>: de versie waarin alle open CVE\'s van dat programma opgelost zijn. <b>Zo gebruik je het:</b> standaard staan alleen programma\'s met open CVE\'s erin; met <i>Alle software</i> zie je alles wat er geïnstalleerd is. Klik op een programma voor de servers, en werk ze bij met ⬆. <b>Goed om te weten:</b> versies worden per besturingssysteem vergeleken, omdat Debian, Ubuntu en Rocky reparaties elk in hun eigen versienummer zetten. Een server die nog niet gescand is of waarvan de scan mislukte, staat onder <i>onbekend</i> en telt niet mee als veilig.')) ?>
      <div class="dash-bar vsw-bar">
        <input type="search" id="vsw-q" placeholder="<?= th('🔍 Zoek een programma of server…') ?>" autocomplete="off">
        <div class="dash-chips" id="vsw-scope" role="group" aria-label="<?= th('Welke software') ?>"></div>
        <div class="dash-chips" id="vsw-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <button type="button" class="btn primary need-admin" id="vsw-up-all" title="<?= th('Alle servers bijwerken waarvoor een oplossing is, met één bevestiging') ?>">⬆ <?= th('Alles bijwerken') ?></button>
        <a class="btn" id="vsw-csv" href="api.php?action=vuln_csv&amp;kind=software" title="<?= th('Dit overzicht als CSV-bestand (opent in Excel)') ?>">⤓ CSV</a>
      </div>
      <div id="vsw-info" class="muted small"></div>
      <div id="vsw-list" class="vsw-list"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <?php endif; ?>
      <?php if ($showEol): ?>
      <div class="vuln-pane" data-vpane="eol" <?= $showVulns ? 'hidden' : '' ?>>
        <div class="dash-counters"><div class="dash-in">
          <div class="health-counts" id="eol-counts"></div>
          <span class="dash-last muted small" id="eol-info"></span>
        </div></div>
        <?= xp(t('Welk besturingssysteem en welke software geen updates meer krijgt, of binnenkort niet.'), t('<b>Wat het is:</b> uit de softwarelijst die de agent stuurt, kijkt RzSense bij <a href="https://endoflife.date" target="_blank" rel="noopener">endoflife.date</a> tot wanneer een versie updates krijgt (alleen productnamen gaan de deur uit). <b>Oordeel:</b> ⛔ besturingssysteem buiten ondersteuning = critical; ⏳ binnen 90 dagen = warning; software van de leverancier zelf (zoals docker-ce) buiten ondersteuning = warning. Software uit de pakketbron van je distributie krijgt vaak nog beveiligingsupdates van de distributie, ook als de maker zelf stopt: dan alleen ℹ️ ter info. <b>Meldingen:</b> 90 en 30 dagen vooraf en op de dag zelf (Systeemmeldingen → End-of-life). <b>Uitzetten:</b> Instellingen → Overzicht → Functies.')) ?>
        <div class="dash-bar"><input type="search" id="eol-search" placeholder="<?= th('🔍 Zoek server of software…') ?>" autocomplete="off">
          <div class="dash-chips" id="eol-filter" role="group" aria-label="<?= th('Filter') ?>"></div><span class="dash-sp"></span>
          <button type="button" class="btn need-admin" id="eol-refresh">🔄 <?= th('Nu opzoeken') ?></button></div>
        <div id="eol-list"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <?php endif; ?>
      <?php if ($showPatch): ?>
      <div class="vuln-pane" data-vpane="patch" <?= $showVulns || $showEol ? 'hidden' : '' ?>>
        <?= xp(t('Welke updates er per server klaarstaan, en ze installeren: nu of in een onderhoudsvenster.'), t('<b>Wat het doet:</b> de agent meldt welke updates er zijn en of een herstart nodig is. <b>Zo gebruik je het:</b> installeer met de hand of laat het in een vast venster gebeuren (Agent Manager → Updates; voor meerdere servers tegelijk, gespreid). <b>Goed om te weten:</b> na de installatie zie je hier en in het Logboek wat er is bijgewerkt.')) ?>
        <div class="dash-bar"><span class="muted small"><?= th('Welke updates er per server klaarstaan, en ze installeren. Per server stel je in wanneer (Agent Manager → Updates).') ?></span>
          <a class="btn small-btn need-admin" href="#settings/patch" data-goto="settings/patch">⚙️ <?= th('Instellen') ?></a></div>
        <div class="card" id="patch-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <?php endif; ?>
      <?php if ($showLeaks): ?>
      <div class="vuln-pane" data-vpane="leak" <?= $showVulns || $showEol || $showPatch ? 'hidden' : '' ?>>
        <?= xp(t('Of de e-mailadressen van je gebruikers en je domein in bekende datalekken staan.'), t('<b>Wat het doet:</b> RzSense vraagt bij Have I Been Pwned na in welke lekken een adres voorkomt, en meldt nieuwe lekken. <b>Zo gebruik je het:</b> wijzig het wachtwoord van een gelekt account, overal waar het gebruikt werd, en zet tweestapsverificatie aan. <b>Goed om te weten:</b> welke adressen en welk domein worden gecontroleerd, stel je in bij Instellingen → Datalekken.')) ?>
        <div class="dash-counters"><div class="dash-in">
          <div class="health-counts" id="leak-counts"></div>
          <span class="dash-last muted small" id="leak-info"></span>
        </div></div>
        <div id="leak-banner"></div>
        <div class="kpis" id="leak-kpis"></div>
        <div class="dash-bar">
          <input type="search" id="leak-search" placeholder="<?= th('🔍 Zoek adres of lek…') ?>" autocomplete="off">
          <div class="dash-chips" id="leak-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
          <span class="dash-sp"></span>
          <button type="button" class="btn" id="leak-run">🔍 <?= th('Nu controleren') ?></button>
          <div class="dash-more">
            <button class="btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
            <div class="dash-menu" role="menu" hidden><button type="button" id="leak-toggle" role="menuitem"><?= th('⊞ Alles openen / sluiten') ?></button></div>
          </div>
        </div>
        <div id="leak-users"></div>
        <div id="leak-list"></div>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($showStats): ?>
    <?php if ($role !== 'readonly'): ?>
    <section id="tab-incidents" class="tab-panel" role="tabpanel" hidden>
      <!-- 1.142.0: één lijst uit monitoring, IDS en kwetsbaarheden -->
      <?= xp(t('Eén lijst met alles wat aandacht vraagt: storingen, aanvallen en kwetsbaarheden.'), t('<b>Wat het doet:</b> incidenten uit monitoring, het IDS en kwetsbaarheden staan hier samen, met status, eigenaar en tijdlijn. <b>Zo gebruik je het:</b> neem een incident op je naam, houd notities bij en sluit het als het is opgelost. <b>Goed om te weten:</b> voor NIS2 kun je een incident als significant markeren; het rapport vind je op de NIS2-pagina.')) ?>
      <div class="pg-tools" data-pgtools="inc"></div>
      <div class="pgrid" data-pgrid="inc">
      <div class="pcard" data-pk="kpis" data-pw="12" data-pt="<?= th('Kerncijfers') ?>"><div class="kpis" id="inc-kpis"></div>
      </div>
      <div class="pcard cc-only" data-pk="cc_chart" data-pw="8" data-pt="<?= th('Geopend en opgelost') ?>"><div class="cc-card" id="inc-cc-chart"></div>
      </div>
      <div class="pcard cc-only" data-pk="cc_src" data-pw="4" data-pt="<?= th('Per bron') ?>"><div class="cc-card" id="inc-cc-src"></div>
      </div>
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Zoeken en filters') ?>"><div class="dash-bar">
        <input type="search" id="inc-search" placeholder="<?= th('Zoek incident, server of eigenaar…') ?>" aria-label="<?= th('Zoeken') ?>">
        <div class="dash-chips" id="inc-filter" role="group"></div>
        <span class="dash-sp"></span>
        <span class="muted small"><?= th('Elke minuut bijgewerkt uit monitoring, IDS en kwetsbaarheden') ?></span>
      </div>
      </div>
      <div class="pcard" data-pk="list" data-pw="12" data-pt="<?= th('Incidenten') ?>"><div id="inc-list"><p class="muted">⏳ <?= th('Laden…') ?></p></div>
      </div>
      </div>
    </section>
    <?php endif; ?>

    <section id="tab-logs" class="tab-panel" role="tabpanel" hidden>
      <!-- 1.211.0: zoeken in de logs van je servers (beheerder en gebruiker) -->
      <?= xp(t('Zoeken in de logs van al je servers, zien wat binnenkomt, en vaste zoekacties als melding.'), t('<b>Wat het doet:</b> de agents sturen hun logbestanden versleuteld naar RzSense; hier zoek je erin over alle servers tegelijk. <b>Zo gebruik je het:</b> zoek op een woord, server en periode. Met <i>Vaste zoekacties</i> krijg je een melding zodra een zoekopdracht iets vindt; <i>Binnenkomst</i> laat zien wat elke server stuurt. <b>Goed om te weten:</b> welke logs een server stuurt, kies je in de Agent Manager; hoe lang ze blijven bij Instellingen → Opslag.')) ?>
      <div id="logs-page" class="logs-page"><p class="muted"><?= th('Laden…') ?></p></div>
    </section>
    <section id="tab-analytics" class="tab-panel" role="tabpanel" hidden>
      <!-- 1.106.0: trends door de tijd -->
      <?= xp(t('Hoe het gaat over een langere periode: betrouwbaarheid, capaciteit, beveiliging en advies.'), t('<b>Wat het doet:</b> RzSense vat de metingen per periode samen, geeft een score en adviezen, en laat met trends zien waar het naartoe gaat. <b>Zo gebruik je het:</b> kies een periode en een tabblad. Een advies handel je af, stel je uit of zet je op niet van toepassing. <b>Goed om te weten:</b> hoe ver je terug kunt kijken, hangt af van de bewaartermijnen (Instellingen → Opslag).')) ?>
      <div class="pg-tools" data-pgtools="an"></div>
      <div class="pgrid" data-pgrid="an">
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Periode en tabbladen') ?>"><div class="dash-bar an-toolbar">
        <div class="dash-chips" id="an-period" role="group" aria-label="<?= th('Periode') ?>"></div>
        <span class="an-custom"><input type="date" id="an-from" aria-label="<?= th('Van') ?>"> – <input type="date" id="an-to" aria-label="<?= th('Tot en met') ?>"> <button type="button" class="btn small-btn" id="an-go"><?= th('Toon') ?></button></span>
        <span class="dash-sp"></span>
        <span class="muted small" id="an-info"></span>
        <button type="button" class="btn small-btn" id="an-pdf" title="<?= th('Afdrukbaar rapport over deze periode; bewaar het als PDF') ?>">📄 <?= th('Rapport (PDF)') ?></button>
        <div class="an-report need-admin" id="an-report"></div>
      </div>
      </div>
      <div class="pcard" data-pk="body" data-pw="12" data-pt="<?= th('Analyse') ?>"><div id="an-body"><p class="muted">⏳ <?= th('Analyse laden…') ?></p></div>
      </div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($showNet): ?>
    <section id="tab-net" class="tab-panel" role="tabpanel" hidden>
      <!-- 1.183.0: Apparaten (de lijst) en Ontdekken (wat er verandert, voorstellen, aanwezigheid) -->
      <div class="subtabs mon-tabs" role="tablist" aria-label="<?= th('Netwerk') ?>" id="net-tabs">
        <button type="button" class="seg-btn" data-nsub="map" role="tab" aria-selected="false">🗺️ <?= th('Overzicht') ?></button>
        <button type="button" class="seg-btn active" data-nsub="list" role="tab" aria-selected="true">📋 <?= th('Apparaten') ?></button>
        <button type="button" class="seg-btn" data-nsub="disc" role="tab" aria-selected="false">🔍 <?= th('Ontdekken') ?> <span class="tab-badge" id="net-disc-badge" hidden></span></button>
      </div>
      <!-- 1.103.0: netwerkscan, zelfde opbouw als het dashboard -->
      <?= xp(t('Alle apparaten in je netwerk, met naam, soort, IP-adres en fabrikant.'), t('<b>Wat het doet:</b> RzSense scant je netwerk (en leest UniFi of SNMP uit als je die hebt) en houdt bij welke apparaten er zijn en wanneer ze online waren. <b>Zo gebruik je het:</b> geef een nieuw apparaat een naam en zet het op bekend; een apparaat dat je niet kent, onderzoek je. <b>Goed om te weten:</b> deze apparaten kun je ook kiezen bij het adres van een server of het doel van een check.'), 'xp-net-list') ?>
      <div class="dash-counters"><div class="dash-in">
        <div class="health-counts" id="net-counts"></div>
        <span class="dash-last muted small" id="net-last"></span>
      </div></div>
      <div class="pg-tools" data-pgtools="net"></div>
      <div class="pgrid" data-pgrid="net">
      <div class="pcard" data-pk="banner" data-pw="12" data-pt="<?= th('Melding') ?>"><div id="net-banner"></div>
      </div>
      <div class="pcard" data-pk="kpis" data-pw="12" data-pt="<?= th('Kerncijfers') ?>"><div class="kpis" id="net-kpis"></div>
      </div>
      <div class="pcard cc-only" data-pk="cc_chart" data-pw="12" data-pt="<?= th('Apparaten') ?>"><div class="cc-card" id="net-cc-chart"></div>
      </div>
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Zoeken en filters') ?>"><div class="dash-bar">
        <input type="search" id="net-search" placeholder="<?= th('🔍 Zoek naam, IP, MAC of fabrikant…') ?>" autocomplete="off">
        <div class="dash-chips" id="net-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="net-group" aria-label="<?= th('Groeperen') ?>">
          <option value=""><?= th('Niet groeperen') ?></option><option value="kind"><?= th('Per soort') ?></option>
          <option value="vendor"><?= th('Per fabrikant') ?></option><option value="conn"><?= th('Per verbinding (wifi of bedraad)') ?></option>
        </select>
        <button type="button" class="btn need-admin" id="net-approve-all"><?= th('✔ Alle nieuwe bekend') ?></button>
        <button type="button" class="btn need-admin" id="net-scan">🔍 <?= th('Nu scannen') ?></button>
      </div>
      <div id="net-scan-steps" class="rz-job-details" hidden></div>
      </div>
      <div class="pcard" data-pk="list" data-pw="12" data-pt="<?= th('Apparaten') ?>"><div class="card net-card"><div id="net-list"></div></div>
      </div>
      </div>
      <?= xp(t('Wat er in je netwerk verandert: nieuwe apparaten, veranderingen en voorstellen.'), t('<b>Wat het doet:</b> je ziet nieuwe apparaten, een ander IP-adres of andere open poorten, en wie wanneer thuis is (aanwezigheid). <b>Zo gebruik je het:</b> loop de nieuwe apparaten na en neem een voorstel over, bijvoorbeeld een check toevoegen. <b>Goed om te weten:</b> de scan zelf stel je in bij Instellingen → Netwerkscan.'), 'xp-net-disc') ?>
      <div id="net-disc" class="net-disc" aria-live="polite"></div>
      <?= xp(t('Hoe je netwerk in elkaar zit: router, switches en accesspoints, met de apparaten erachter.'), t('<b>Wat het doet:</b> RzSense tekent de kaart uit de gegevens van UniFi, SNMP en de netwerkscan. <b>Zo gebruik je het:</b> klik op een apparaat voor de details. <b>Goed om te weten:</b> zonder UniFi of SNMP weet RzSense niet achter welke switch een apparaat zit; het staat dan direct onder de router.'), 'xp-net-map') ?>
      <div id="net-map" class="net-map"></div>
    </section>
    <?php endif; ?>

    <section id="tab-settings" class="tab-panel" role="tabpanel" hidden>
      <div class="set-layout">
      <nav class="set-side" id="set-side" aria-label="<?= th('Instellingen') ?>">
        <div class="set-search"><input type="search" id="set-search" placeholder="<?= th('🔍 Zoek een instelling…') ?>" autocomplete="off" spellcheck="false" aria-label="<?= th('Zoek een instelling') ?>"></div>
        <div id="set-navlist"></div>
      </nav>
      <div class="set-main">
      <div class="set-mobile"><select id="set-mselect" aria-label="<?= th('Onderdeel') ?>"></select></div>
      <div class="set-crumb muted small"><span id="set-crumb"></span> <span class="autosave-state" id="save-state" aria-live="polite"></span></div>
      <h1 class="set-title" id="set-title"></h1>
      <p class="set-lead" id="set-lead"></p>
      <div class="set-attn" id="set-attn" hidden></div>
      <div class="seg set-tabs" id="set-tabs" role="tablist" hidden></div>
      <div class="pg-tools" data-pgtools="set"></div>
      <div data-sk="overview" class="card set-overview" id="set-overview"></div>
      <div data-sk="features" class="card" id="features-card" data-nodirty>
        <h2>📋 <?= th('Functies') ?></h2>
        <?= xp(t('Alle functies van RzSense op één plek.'), t('<b>Wat je ziet:</b> elke functie met het onderdeel, de versie waarin hij kwam en de status van nu: <b>aan</b>, <b>uit</b>, <b>niet ingesteld</b> (er ontbreekt nog iets, zoals een sleutel of server) of <b>altijd</b> (geen schakelaar). Met de knop ga je naar de plek waar je hem instelt. Klik op een teller om te filteren.')) ?>
        <div id="features-box"><p class="muted small"><?= th('laden…') ?></p></div>
      </div>
      <div class="set-toolbar">
        <span class="muted small"><?= th('Klik op een onderdeel om het te openen of te sluiten.') ?></span>
        <button type="button" class="btn" id="set-toggle-all"><?= th('⊞ Alles openen') ?></button>
      </div>
      <form id="settings-form" class="settings-grid" data-pgrid="set">
        <div data-sk="nis2" class="card" id="nis2-card">
          <h2>🇪🇺 <?= th('NIS2') ?></h2>
          <?= xp(t('Een hulpmiddel bij de zorgplicht uit de NIS2-richtlijn (in Nederland de Cyberbeveiligingswet).'), t('<b>Wat het is:</b> per maatregel uit <b>artikel 21</b> (a tot en met j) zie je wat RzSense er zelf van ziet, en schrijf je erbij wat je verder geregeld hebt (beleid, afspraken met leveranciers, training). Een <b>significant incident</b> markeer je hieronder; RzSense telt af naar de <b>vroegtijdige waarschuwing (24 uur)</b>, de <b>incidentmelding (72 uur)</b> en het <b>eindverslag (een maand)</b> aan je CSIRT, en waarschuwt als een termijn dichtbij komt. Het <b>rapport</b> kun je afdrukken als PDF. <b>Let op:</b> dit is geen juridisch advies en geen certificering; het laat zien wat RzSense technisch ziet.')) ?>
          <label class="switch"><input type="checkbox" name="nis2_on"><span></span> <?= t('<b>NIS2-overzicht</b> en meldtermijnen') ?></label>
          <label><?= th('Organisatie') ?> <span class="hint"><?= th('(voor het rapport)') ?></span><input name="nis2_org" maxlength="120"></label>
          <label><?= th('CSIRT') ?> <span class="hint"><?= th('(waar je meldt, bv. het NCSC of het CSIRT van je sector)') ?></span><input name="nis2_csirt" maxlength="200"></label>
          <p class="small muted"><?= th('Het rapport automatisch versturen (bijvoorbeeld elk kwartaal naar je directie of adviseur) stel je in bij Rapporten.') ?> <a href="#settings/reports" data-goto="settings/reports"><?= th('Naar Rapporten') ?></a></p>
        <div id="nis2-box"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="pushover" class="card">
          <h2>Pushover</h2>
          <?= xp(t('Pushmeldingen op je telefoon via de Pushover-app.'), t('<b>Wat het is:</b> Pushover is een app voor iPhone, Android en de computer die berichten van RzSense meteen op je scherm zet, met een eigen geluid per prioriteit en noodmeldingen die blijven herhalen tot je reageert. <b>Instellen:</b> maak op <a href="https://pushover.net/apps/build" target="_blank" rel="noopener">pushover.net</a> een applicatie aan; dat geeft het <i>API-token</i>. Je <i>user key</i> staat op je Pushover-dashboard. <b>Goed om te weten:</b> na een gratis proefperiode betaal je Pushover één keer per platform.')) ?>
          <label class="switch"><input type="checkbox" name="pushover_enabled"><span></span> <?= th('Pushover meldingen aan') ?></label>
          <label>User key<input name="pushover_user" autocomplete="off" spellcheck="false"></label>
          <label>API token<input name="pushover_token" autocomplete="off" spellcheck="false"></label>
          <details class="adv"><summary><?= th('Geavanceerd') ?></summary>
          <div class="row2">
            <label><?= th('Prioriteit critical') ?>
              <select name="pushover_priority_crit">
                <option value="-1"><?= th('Laag (-1)') ?></option><option value="0"><?= th('Normaal (0)') ?></option>
                <option value="1"><?= th('Hoog (1)') ?></option><option value="2"><?= th('Emergency (2)') ?></option>
              </select>
            </label>
            <label><?= th('Prioriteit warning') ?>
              <select name="pushover_priority_warn">
                <option value="-1"><?= th('Laag (-1)') ?></option><option value="0"><?= th('Normaal (0)') ?></option>
                <option value="1"><?= th('Hoog (1)') ?></option>
              </select>
            </label>
          </div>
          <label><?= th('Geluid') ?> <span class="muted small"><?= th('(leeg = standaard, bv. siren, alien, pushover)') ?></span><input name="pushover_sound"></label>
          </details>
          <button type="button" class="btn" id="pushover-test"><?= th('📲 Testbericht sturen') ?></button>
        </div>

        <div data-sk="ntfy" class="card">
          <h2>ntfy</h2>
          <?= xp(t('Gratis pushmeldingen via de ntfy-app, naast of in plaats van Pushover.'), t('<b>Wat het is:</b> ntfy is een gratis app en dienst voor pushberichten. Je gebruikt <a href="https://ntfy.sh" target="_blank" rel="noopener">ntfy.sh</a> of je eigen ntfy-server. <b>Instellen:</b> installeer de ntfy-app, abonneer je op het onderwerp (topic) dat je hieronder invult en sla op. <b>Goed om te weten:</b> op ntfy.sh kan iedereen die de naam van het onderwerp kent meelezen. Kies dus een lange, moeilijk te raden naam, of gebruik een eigen server met een toegangstoken.')) ?>
          <label class="switch"><input type="checkbox" name="ntfy_enabled"><span></span> <?= th('ntfy meldingen aan') ?></label>
          <label><?= th('Server') ?><input name="ntfy_server" placeholder="https://ntfy.sh" autocomplete="off" spellcheck="false"></label>
          <label><?= th('Onderwerp (topic)') ?><input name="ntfy_topic" placeholder="rzmon-…" autocomplete="off" spellcheck="false" pattern="[A-Za-z0-9_\-]{1,64}"></label>
          <label><?= th('Toegangstoken of gebruiker:wachtwoord') ?> <span class="muted small"><?= th('(leeg = zonder inloggen)') ?></span><input name="ntfy_token" autocomplete="off" spellcheck="false"></label>
          <?= xp(t('De prioriteit volgt Pushover; een storing bevestig je vanuit het bericht.'), t('ntfy krijgt dezelfde prioriteit als je bij Pushover (Geavanceerd) instelt: normaal = 3, hoog = 4, noodmelding = 5. Onder een storingsbericht staat een knop <b>✔ Bevestigen</b>: daarmee bevestig je de storing zonder RzSense te openen.')) ?>
          <button type="button" class="btn" id="ntfy-test"><?= th('📲 Testbericht sturen') ?></button>
        </div>

        <div data-sk="quiet" class="card" id="quiet-card" data-nodirty>
          <h2>🌙 <?= th('Stille uren') ?></h2>
          <?= xp(t('Profielen met een naam, te koppelen aan groepen, servers en checks.'), t('<b>Wat het doet:</b> in de stille uren stuurt RzSense niets via Pushover, ntfy, e-mail of Home Assistant; de checks lopen door en na afloop komt één samenvatting van wat nog speelt. <b>Profielen:</b> geef een profiel een naam en vensters (dagen en tijden, ook over middernacht), of een periode (bv. een vakantie). Met <i>Critical komt wel door</i> wordt je bij een critical toch gewekt. <b>Koppelen:</b> ★ Algemeen geldt overal; een groep, server of check kan een eigen profiel of <i>nooit stil</i> krijgen. Het fijnste wint: check → server → eigen groep → soort apparaat → algemeen. <b>Nu stil</b> (🌙 bij een server of check) gaat voor alles.')) ?>
          <div id="quiet-box"><p class="muted small"><?= th('laden…') ?></p></div>
        </div>

        <div data-sk="confirm" class="card">
          <h2>⏳ <?= th('Status bevestigen') ?></h2>
          <?= xp(t('Een check wordt pas warning of critical (of weer OK) als het een paar keer achter elkaar zo is.'), t('<b>Wat het doet:</b> één mislukte controle maakt een check nog niet rood. Pas na het aantal controles hieronder <b>achter elkaar</b> wisselt de status. Tot die tijd blijft de check zoals hij was, met het label <b>⏳ 1/3</b>. Warning, critical en onbekend tellen samen; is de check tussendoor één keer goed, dan begint de telling opnieuw. <b>Leeg</b> = meteen, zoals altijd. Per check kun je het anders instellen (✎ → Status bevestigen). <b>Goed om te weten:</b> <i>Melden na … mislukte checks</i> telt pas vanaf het moment dat de status bevestigd is. Met 3 hier en 2 bij melden krijg je dus pas na 4 mislukte controles een melding. Grafieken en de geschiedenis tonen gewoon elke meting.')) ?>
          <div class="conf-row">
            <label><?= th('Warning of critical pas na … mislukte controles') ?> <span class="muted small"><?= th('(leeg = meteen)') ?></span>
              <input type="number" name="confirm_fails" min="1" max="100" placeholder="<?= th('meteen') ?>" style="max-width:8rem"></label>
            <label><?= th('Weer OK pas na … goede controles') ?> <span class="muted small"><?= th('(leeg = meteen)') ?></span>
              <input type="number" name="confirm_ok" min="1" max="100" placeholder="<?= th('meteen') ?>" style="max-width:8rem"></label>
          </div>
          <!-- 1.193.0: sneller bevestigen, per soort check, en het advies in één keer -->
          <label class="switch conf-fast"><input type="checkbox" name="confirm_fast"><span></span> <?= t('<b>Sneller bevestigen</b>: twijfelt een ping, TCP, HTTP of SNMP nog, dan controleert RzSense hem elke 20 s opnieuw') ?></label>
          <?= xp(t('Zo is een echte storing binnen een minuut bevestigd; een hapering van een paar seconden blijft stil.'), t('<b>Wat het doet:</b> zolang een snelle check nog bevestigt (⏳), controleert RzSense hem elke 20 seconden in plaats van na zijn gewone interval. Met 3 hier is een echte storing dan na ±40 seconden bevestigd in plaats van na 2 tot 3 minuten. <b>Goed om te weten:</b> een hapering van minder dan ±40 seconden blijft stil; korter dan dat wordt niet meer gefilterd. Andere checks (agent, disk, back-up …) volgen hun eigen interval.')) ?>
          <h3 class="set-sub"><?= th('Per soort check') ?> <span class="muted small"><?= th('(leeg = de waarde hierboven; een check met een eigen waarde gaat voor)') ?></span></h3>
          <input type="hidden" name="confirm_types">
          <div id="conf-types" class="conf-types"></div>
          <div class="conf-adv">
            <button type="button" class="btn need-admin" id="conf-advice">✨ <?= th('Advies toepassen') ?></button>
            <span class="muted small"><?= th('Critical direct, warning na 10 minuten, onbekend na 15 minuten, herstel direct; melden na 1; langzame checks meteen geldig. Je ziet eerst wat er verandert.') ?></span>
          </div>
        </div>

        <div data-sk="notify" class="card notify-card">
          <h2><?= th('🔔 Meldingen') ?></h2>
          <?= xp(t('Per status kies je: nooit melden, direct, of pas als het een tijdje zo blijft.'), t('<b>Wat het doet:</b> voor elke status (warning, critical, onbekend, hersteld) bepaal je wanneer er een melding uitgaat: <b>nooit</b>, <b>direct</b>, of pas <b>na X minuten</b> onafgebroken in die status. Herstelt het eerder, dan krijg je niets. Bij <i>Hersteld</i> betekent X: pas melden als het X minuten goed blijft. <b>Goed om te weten:</b> per check kun je dit anders instellen (✎ → Meldingen).')) ?>
          <label><?= th('Melden na … mislukte checks achter elkaar') ?> <span class="muted small"><?= th('(1 = bij de eerste; per check aan te passen)') ?></span>
            <input type="number" name="notify_fails" min="1" max="100" style="max-width:8rem"></label>
          <?= xp(t('Hoe het aantal mislukte checks en de regels hieronder samenwerken.'), t('De telling loopt per status: wordt een warning een critical, dan begint de telling opnieuw. <i>Onbekend</i> telt ook mee. Er gaat pas een melding uit als het aantal <b>én</b> de regel hieronder allebei kloppen. Wil je alleen op het aantal letten, zet de regel dan op <i>direct</i>. Herstel wordt gemeld zoals hieronder ingesteld.')) ?>
          <label class="switch"><input type="checkbox" name="notify_unknown"><span></span> <?= th('Melden bij ONBEKEND') ?></label>
          <label><?= th('Taal van meldingen en checkberichten') ?> <span class="muted small">(<?= th('Pushover, e-mail, Home Assistant en de berichten van checks; voor iedereen hetzelfde') ?>)</span>
            <select name="notify_lang" style="max-width:12rem"><option value="nl">Nederlands</option><option value="en">English</option></select></label>
          <div id="notify-editor"></div>
          <input type="hidden" name="notify_rules"><input type="hidden" name="notify_channels"><input type="hidden" name="notify_flap">
          <div class="row2">
            <label><?= th('Meldingen per server bundelen binnen … seconden') ?> <span class="muted small"><?= th('(0 = niet bundelen)') ?></span><input type="number" name="notify_group_seconds" min="0" max="3600"></label>
            <label class="switch" style="align-self:end"><input type="checkbox" name="notify_recovery_acked"><span></span> <?= th('Ook herstel melden na een bevestigde storing') ?></label>
          </div>
          <?= xp(t('Meerdere storingen op één server tegelijk? Dan krijg je één bericht.'), t('Gaan op één server binnen deze tijd meerdere checks mis (of herstellen ze), dan stuurt RzSense één bericht met alles erin, in plaats van een rij losse berichten. De eerste melding wacht daardoor hooguit zo lang. <b>Goed om te weten:</b> Home Assistant krijgt wel een bericht per check, zodat je automatiseringen blijven werken. 0 = niet bundelen.')) ?>
          <h3 class="small" style="margin:1rem 0 .3rem">⏫ <?= th('Escaleren') ?></h3>
          <label class="switch"><input type="checkbox" name="esc_on"><span></span> <?= t('<b>Escaleren</b>: een storing die niemand bevestigt, na een tijd nog een keer melden, een stap hoger') ?></label>
          <div class="row3">
            <label><?= th('Warning: na … min') ?> <span class="muted small"><?= th('(0 = nooit)') ?></span><input type="number" name="esc_warn_min" min="0" max="1440"></label>
            <label><?= th('Critical: na … min') ?> <span class="muted small"><?= th('(0 = nooit)') ?></span><input type="number" name="esc_crit_min" min="0" max="1440"></label>
            <label><?= th('Critical wordt') ?><select name="esc_crit_prio"><option value="1"><?= th('hoge prioriteit') ?></option><option value="2"><?= th('nood (herhaalt tot je bevestigt)') ?></option></select></label>
          </div>
          <label class="switch"><input type="checkbox" name="esc_quiet"><span></span> <?= th('Niet escaleren tijdens de stille uren') ?></label>
          <?= xp(t('Een storing die niemand bevestigt, wordt na een tijd nog eens gemeld, een stap hoger.'), t('Is een storing na de ingestelde tijd nog niet bevestigd, dan meldt RzSense hem opnieuw met een hogere prioriteit: een warning gaat dan als critical (met de prioriteit van je critical-meldingen), een critical krijgt de prioriteit die je hierboven kiest. Dat gebeurt één keer per storing. <b>Bevestigen</b> stopt het escaleren.')) ?>
          <label class="switch"><input type="checkbox" name="notify_bundle_hosts"><span></span> <?= t('🔗 <b>Ook over servers heen bundelen</b>, met de waarschijnlijke oorzaak') ?></label>
          <?= xp(t('Vallen meerdere servers tegelijk uit, dan krijg je één bericht met de waarschijnlijke oorzaak.'), t('Gaan er binnen het bundelvenster meerdere servers mis, dan komt er één bericht, bijvoorbeeld <i>"🔴 CRITICAL: 5 servers tegelijk · waarschijnlijk: ze hangen allemaal aan Switch-zolder (UniFi)"</i>. <b>Hoe RzSense de oorzaak zoekt:</b> via je afhankelijkheden, via UniFi (welk access point of welke switch), via agents die allemaal tegelijk stil zijn en via je Proxmox-clusters.')) ?>
        </div>

        <div data-sk="sysinfo" class="card" id="sysinfo-card">
          <h2>ℹ️ <?= th('Systeeminfo') ?></h2>
          <?= xp(t('Meldingen over RzSense zelf (updates, certificaten, agents): per soort kies je waarheen.'), t('<b>Wat het is:</b> dit zijn meldingen die niet van een check komen, maar van RzSense zelf. Per soort kies je naar welke kanalen hij gaat. Bij Pushover kies je <i>stil</i> (zonder geluid) of <i>normaal</i>. Met 🧪 stuur je een voorbeeld naar de aangevinkte kanalen. <b>Goed om te weten:</b> tijdens de <b>stille uren</b> gaat er niets naar buiten, maar de melding staat wel op de meldingenpagina. Info-meldingen van checks stel je in bij de rij <i>Info (checks)</i>, of per check.')) ?>
          <div class="table-scroll"><table class="sysinfo-table" id="sysinfo"></table></div>
          <input type="hidden" name="sysinfo">
        </div>

        <div data-sk="alarm" class="card">
          <h2><?= th('Alarmgedrag') ?></h2>
          <?= xp(t('Vooruitkijken: waarschuwen voordat een disk vol is of een trend een grens haalt.'), t('<b>Wat het doet:</b> RzSense rekent uit hoe snel een disk volloopt en hoe een trend (Analyse → Trends) zich ontwikkelt, en meldt het als de grens binnen het aantal dagen hieronder valt. <b>Zo stel je het in:</b> 7 dagen voor disks geeft tijd om op te ruimen; 30 dagen voor trends is een rustige vroege waarschuwing. 0 zet het uit. Onder <i>Geavanceerd</i> staan de logchecks en automatisch onderhoud. <b>Goed om te weten:</b> dit zijn ℹ️-meldingen: geen storing, wel iets om op tijd naar te kijken.')) ?>
          <label><?= th('Waarschuwen als een disk binnen … dagen vol raakt') ?> <span class="muted small"><?= th('(0 = uit)') ?></span><input type="number" name="disk_forecast_days" min="0" max="365"></label>
          <label><?= th('Melden als een trend binnen … dagen een grens bereikt') ?> <span class="muted small"><?= th('(Analyse → Trends; 0 = uit)') ?></span><input type="number" name="trend_alert_days" min="0" max="365"></label>
          <label><?= th('UPS: kortste looptijd die nog goed is (minuten)') ?> <span class="muted small"><?= th('(voor de trend van de accu)') ?></span><input type="number" name="trend_ups_min" min="1" max="240"></label>
          <details class="adv"><summary><?= th('Geavanceerd') ?></summary>
          <label><?= th('Logbestanden: treffers tellen over de laatste … minuten') ?> <span class="muted small">(5–1440)</span><input type="number" name="log_window" min="5" max="1440"></label>
          <label class="switch"><input type="checkbox" name="auto_maint_enabled"><span></span> <?= th('Automatisch onderhoud na herhaalde meldingen') ?></label>
          <label><?= th('… na zoveel meldingen voor dezelfde storing') ?> <span class="muted small"><?= th('(alleen die check; stopt vanzelf als hij weer OK is)') ?></span><input type="number" name="auto_maint_after" min="1" max="100"></label>
          </details>
        </div>

        <div data-sk="webhook" class="card">
          <h2><?= th('Home Assistant-webhook') ?></h2>
          <?= xp(t('Elke melding ook naar Home Assistant, om er een automatisering mee te starten.'), t('<b>Wat het doet:</b> RzSense stuurt elke melding als JSON naar een webhook in Home Assistant. Daarmee start je een automatisering: een lamp die rood kleurt, een gesproken melding (TTS) of een bericht via de HA-app. <b>Instellen:</b> maak in Home Assistant een automatisering met als trigger <b>Webhook</b> en plak de URL hieronder, bijvoorbeeld <code>http://homeassistant.local:8123/api/webhook/rzmon-…</code>')) ?>
          <label class="switch"><input type="checkbox" name="ha_webhook_enabled"><span></span> <?= th('Webhook aan') ?></label>
          <label><?= th('Webhook-URL') ?><input name="ha_webhook_url" type="url" spellcheck="false" placeholder="http://homeassistant.local:8123/api/webhook/rzmon-geheim-id"></label>
          <p class="muted small"><?= t('Welke meldingen naar Home Assistant gaan, stel je in bij <b>🔔 Meldingen</b> (kolom Home Assistant).') ?></p>
          <label class="switch"><input type="checkbox" name="ha_webhook_verify_ssl"><span></span> <?= th('SSL-certificaat controleren (uit bij self-signed)') ?></label>
          <button type="button" class="btn" id="webhook-test"><?= th('🏠 Testbericht naar Home Assistant') ?></button>
        </div>

        <div data-sk="email" class="card">
          <h2>E-mail</h2>
          <?= xp(t('Meldingen per e-mail, altijd of alleen als reserve wanneer Pushover niet werkt.'), t('<b>Wat het doet:</b> RzSense stuurt meldingen ook als e-mail, via een mailserver (SMTP) die je hieronder invult. Kies <i>altijd</i> of <i>alleen als Pushover niet werkt</i>. <b>Goed om te weten:</b> bij Gmail, Outlook en iCloud heb je een <b>app-wachtwoord</b> nodig, niet je gewone wachtwoord. Met <i>Testmail sturen</i> controleer je meteen of het werkt.')) ?>
          <label class="switch"><input type="checkbox" name="email_enabled"><span></span> <?= th('E-mailmeldingen aan') ?></label>
          <label><?= th('Versturen') ?>
            <select name="email_mode">
              <option value="always"><?= th('Altijd (naast Pushover)') ?></option>
              <option value="fallback"><?= th('Alleen als Pushover uit staat of mislukt') ?></option>
            </select>
          </label>
          <div class="row3 smtp-row">
            <label><?= th('SMTP-server') ?><input name="smtp_host" spellcheck="false" placeholder="smtp.gmail.com"></label>
            <label><?= th('Poort') ?><input name="smtp_port" type="number" min="1" max="65535" placeholder="587" title="<?= th('587 = STARTTLS, 465 = SSL/TLS, 25 = geen') ?>"></label>
            <label><?= th('Beveiliging') ?>
              <select name="smtp_security">
                <option value="starttls">STARTTLS</option>
                <option value="ssl"><?= th('SSL/TLS') ?></option>
                <option value="none"><?= th('Geen') ?></option>
              </select>
            </label>
          </div>
          <div class="row2">
            <label><?= th('Gebruikersnaam') ?><input name="smtp_user" autocomplete="off" spellcheck="false" placeholder="<?= th('leeg = zonder inloggen') ?>"></label>
            <label><?= th('Wachtwoord') ?><input name="smtp_pass" type="password" autocomplete="new-password"></label>
          </div>
          <div class="row2">
            <label><?= th('Afzender') ?> <span class="hint"><?= th('(leeg = gebruikersnaam)') ?></span><input name="mail_from" type="email" spellcheck="false" placeholder="rzmon@example.com"></label>
            <label><?= th('Ontvanger(s)') ?> <span class="hint"><?= th('(komma tussen meerdere)') ?></span><input name="mail_to" spellcheck="false" placeholder="jij@example.com"></label>
          </div>
          <details class="adv"><summary><?= th('Geavanceerd') ?></summary>
          <label class="switch"><input type="checkbox" name="smtp_verify_ssl"><span></span> <?= th('SSL-certificaat controleren (uit bij self-signed)') ?></label>
          </details>
          <button type="button" class="btn" id="email-test"><?= th('📧 Testmail sturen') ?></button>
        </div>

        <div data-sk="looks" class="card" id="looks-card">
          <h2>🎨 <?= th('Weergave voor nieuwe accounts') ?></h2>
          <?= xp(t('De standaard voor accounts die zelf nog niets kozen.'), t('<b>Wat het doet:</b> elk account kiest zijn eigen weergave bij <i>Mijn account → Weergave</i>. Wat je hier instelt, geldt voor accounts die daar nog niets kozen, en voor het lokale netwerk zonder account.')) ?>
          <label class="switch"><input type="checkbox" name="ids_modern"><span></span> <?= t('<b>Moderne IDS-weergave</b> (anders compact)') ?></label>
          <label><?= th('Beweging in de Attack Flow en het Command center') ?>
            <select name="af_motion"><option value="0"><?= th('stil') ?></option><option value="1"><?= th('rustig') ?></option><option value="2"><?= th('vol') ?></option></select></label>
        </div>

        <div data-sk="reports" class="card" id="reports-card" data-nodirty>
          <h2>📬 <?= th('Rapporten') ?></h2>
          <?= xp(t('Alle overzichten die RzSense verstuurt, op één plek.'), t('<b>Wat het doet:</b> per rapport kies je of het aan staat, hoe vaak (elke week of elke maand), de dag, de tijd en de kanalen: e-mail (als HTML, met een platte versie erbij) en/of een korte push. Het <b>Maandoverzicht</b> zet de onderdelen die je aanvinkt samen in één bericht. <b>Nu versturen</b> stuurt een rapport meteen via zijn kanalen, <b>Voorbeeld</b> laat zien hoe hij eruitziet, en <b>Naar mij</b> stuurt hem naar het e-mailadres van je eigen account (Mijn account). <b>Nodig:</b> e-mail bij <i>Meldingen → Kanalen</i>; voor push Pushover of ntfy.')) ?>
          <div id="reports-box"><p class="muted small"><?= th('laden…') ?></p></div>
        </div>

        <div data-sk="unifi" class="card" id="unifi-card">
          <h2>📡 <?= th('UniFi') ?></h2>
          <?= xp(t('Namen, wifi, verkeer en weren van je apparaten, rechtstreeks uit je UniFi-controller.'), t('<b>Wat het doet:</b> RzSense leest elke 5 minuten de lijst met apparaten uit je UniFi Network-app (op een UniFi OS-console zoals een UDM of Cloud Gateway, of zelf gehost). <b>Waar je het ziet:</b> <i>Netwerk → Apparaten en Ontdekken</i>: de naam, het wifi-netwerk, het access point of de switchpoort van elk apparaat. <i>Verkeer per apparaat</i>: download en upload per uur (30 dagen) en per dag (een jaar), met een melding bij ongewoon veel of nachtelijk verkeer. <i>Diensten (DPI)</i>: welke soort verkeer een apparaat doet, zoals streaming of updates. <i>Internetlijn per maand</i>: het verkeer over je gateway, met een datalimiet. <i>Netwerkpaden</i>: de switchpoort en de snelheid van de verbinding. <b>Weren:</b> bij een apparaat kun je het van het wifi weren; dat gebeurt alleen als jij erop klikt. <b>Veilig:</b> het wachtwoord en de API-sleutel blijven op de RzSense-server en gaan nooit naar de browser. RzSense leest alleen, behalve als je zelf iets weert.')) ?>
          <h3 class="set-sub"><?= th('Verbinding') ?></h3>
          <div class="row2">
            <label><?= th('Adres van de controller') ?> <span class="hint"><?= th('(bv. https://192.168.2.1 of https://unifi:8443)') ?></span><input name="unifi_url" class="mono" placeholder="https://192.168.2.1"></label>
            <label><?= th('Site') ?> <span class="hint"><?= th('(meestal default)') ?></span><input name="unifi_site" class="mono" placeholder="default"></label>
          </div>
          <?= xp(t('Het adres van je console of controller, en de site waar je apparaten in staan.'), t('<b>Adres:</b> bij een UniFi OS-console (UDM, UDR, Cloud Gateway, Cloud Key Gen2+) het IP-adres van de console met https, zonder poort. Bij een zelf gehoste controller (Docker, Linux) meestal met poort 8443. <b>Site:</b> staat in het adres van de Network-app achter <code>/site/</code>; bij één locatie is dat bijna altijd <code>default</code>.')) ?>
          <h3 class="set-sub"><?= th('Inloggen') ?></h3>
          <div class="row2">
            <label><?= th('Gebruikersnaam (lokaal account)') ?><input name="unifi_user" autocomplete="off"></label>
            <label><?= th('Wachtwoord') ?><input name="unifi_pass" type="password" autocomplete="new-password"></label>
          </div>
          <label><?= th('of alleen een API-sleutel') ?> <span class="hint"><?= th('(Network 9 of nieuwer: alleen lezen, weren kan dan niet)') ?></span><input name="unifi_key" type="password" autocomplete="new-password" class="mono"></label>
          <?= xp(t('Gebruik een lokaal UniFi-account; met alleen een API-sleutel kan RzSense niet weren.'), t('<b>Instellen:</b> maak in UniFi een <b>lokaal account</b> voor RzSense (<i>Admins &amp; Users → Add admin → Restrict to local access only</i>), met beheerrechten voor Network en zonder 2FA. <b>Alleen een API-sleutel</b> (<i>Settings → Control Plane → Integrations</i>) kan ook: dan kan RzSense lezen, maar geen apparaten weren.')) ?>
          <label class="switch"><input type="checkbox" name="unifi_verify_ssl"><span></span> <?= th('Certificaat controleren') ?> <span class="hint"><?= th('(uit bij het eigen certificaat van UniFi)') ?></span></label>
          <?= xp(t('Laat dit uit zolang je console zijn eigen certificaat gebruikt.'), t('<b>Waarom:</b> een UniFi-console gebruikt standaard een certificaat dat hij zelf heeft gemaakt. Dat wordt niet vertrouwd, dus de controle zou mislukken. Heb je de console een geldig certificaat gegeven (bv. via een eigen domeinnaam), zet het dan aan.')) ?>
          <div class="btn-row"><button type="button" class="btn" data-netlinks-test="unifi">🧪 <?= th('Verbinding testen') ?></button></div>
          <div class="small" data-netlinks-result="unifi"></div>
        </div>

        <div data-sk="adguard" class="card" id="adguard-card">
          <h2>🛡️ <?= th('AdGuard Home') ?></h2>
          <?= xp(t('Namen, DNS-verzoeken en DNS-blokkeren uit AdGuard Home, en de DNS-controle van het IDS.'), t('<b>Wat het doet:</b> RzSense leest uit AdGuard Home welke apparaten er zijn en welke domeinen ze opzoeken. <b>Waar je het ziet:</b> <i>Netwerk</i>: de namen van apparaten (zoals je ze in AdGuard Home noemde) en in het venster van een apparaat met welke domeinen het praat. <i>Analyse</i>: het aantal DNS-verzoeken, hoeveel AdGuard tegenhield, en de top van apparaten en geblokkeerde domeinen. <i>IDS → DNS-controle</i>: elke minuut kijkt RzSense in de query-log of een apparaat een bekend malware- of botnetdomein opzoekt, of iets wat op een DNS-tunnel lijkt; zo zie je ook camera\'s, tv\'s en andere apparaten zonder agent (aan of uit bij <i>IDS → Dreigingsinformatie</i>). <b>Weren:</b> bij een apparaat kun je het geen DNS meer geven (de lijst <i>Niet toegestane clients</i> in AdGuard Home); dat gebeurt alleen als jij erop klikt. <b>Veilig:</b> het wachtwoord blijft op de RzSense-server en gaat nooit naar de browser. Er gaat niets naar buiten: domeinen worden vergeleken met de lijsten op de RzSense-server.')) ?>
          <h3 class="set-sub"><?= th('Verbinding') ?></h3>
          <label><?= th('Adressen') ?> <span class="hint"><?= th('(één per regel; gesynchroniseerde instanties allemaal invullen)') ?></span>
            <textarea name="adguard_urls" rows="2" class="mono" placeholder="http://192.168.2.203:3000&#10;http://192.168.2.204:3000"></textarea></label>
          <?= xp(t('Heb je twee AdGuards die elkaar bijwerken? Vul ze allebei in.'), t('<b>Adres:</b> het adres van de webinterface van AdGuard Home, met de poort (vaak 3000 of 80; bij de Home Assistant-add-on de poort van de add-on). <b>Meerdere instanties</b> (bv. met adguardhome-sync, of een tweede DNS-server): RzSense telt hun verzoeken en statistiek bij elkaar op, en weren gebeurt op allemaal tegelijk, zodat een apparaat niet via de andere DNS-server alsnog binnenkomt.')) ?>
          <h3 class="set-sub"><?= th('Inloggen') ?></h3>
          <div class="row2">
            <label><?= th('Gebruikersnaam') ?><input name="adguard_user" autocomplete="off"></label>
            <label><?= th('Wachtwoord') ?><input name="adguard_pass" type="password" autocomplete="new-password"></label>
          </div>
          <?= xp(t('Hetzelfde account als waarmee je inlogt op de webinterface van AdGuard Home.'), t('<b>Account:</b> AdGuard Home heeft geen aparte API-sleutel; RzSense gebruikt de gebruikersnaam en het wachtwoord van de webinterface. Bij meerdere instanties moeten ze op alle instanties hetzelfde zijn. Tip: maak in AdGuard Home een eigen gebruiker voor RzSense, dan zie je in de log wat RzSense deed.')) ?>
          <label class="switch"><input type="checkbox" name="adguard_verify_ssl"><span></span> <?= th('Certificaat controleren') ?> <span class="hint"><?= th('(alleen bij https met een geldig certificaat)') ?></span></label>
          <div class="btn-row"><button type="button" class="btn" data-netlinks-test="adguard">🧪 <?= th('Verbinding testen') ?></button></div>
          <div class="small" data-netlinks-result="adguard"></div>
        </div>

        <div data-sk="snmp" class="card" id="snmp-card" data-nodirty>
          <h2>📡 <?= th('SNMP – algemene instelling') ?></h2>
          <?= xp(t('Eén SNMP-instelling voor al je apparaten, met per apparaat een uitzondering.'), t('<b>Wat het doet:</b> elk apparaat met SNMP aan en zonder <i>Eigen instellingen</i> gebruikt deze versie, poort, community of v3-gebruiker. Verander je hier iets, dan geldt dat meteen voor al die apparaten. <b>Per apparaat afwijken:</b> zet bij het apparaat (<i>Server bewerken</i> of de Agent Manager) <i>Eigen instellingen</i> aan. <b>Goed om te weten:</b> de community en de wachtwoorden gaan nooit terug naar de browser.')) ?>
          <div id="snmp-global"><p class="muted small"><?= th('laden…') ?></p></div>
        </div>

        <div data-sk="mqtt" class="card">
          <h2><?= th('MQTT (Home Assistant)') ?></h2>
          <?= xp(t('Elke server als apparaat met sensoren in Home Assistant, via MQTT.'), t('<b>Wat het doet:</b> RzSense meldt zich via <b>MQTT discovery</b> bij Home Assistant. Elke server wordt een apparaat, met sensoren voor de status en de meetwaarden van zijn checks. Zo gebruik je ze in dashboards en automatiseringen. <b>Nodig:</b> een MQTT-broker (bijvoorbeeld de add-on <i>Mosquitto broker</i>) en de MQTT-integratie in Home Assistant. Log bij Mosquitto in met een HA-gebruiker of een aparte gebruiker.')) ?>
          <label class="switch"><input type="checkbox" name="mqtt_enabled"><span></span> <?= th('MQTT aan') ?></label>
          <div class="row3 smtp-row">
            <label><?= th('MQTT-server') ?><input name="mqtt_host" spellcheck="false" placeholder="homeassistant.local"></label>
            <label><?= th('Poort') ?><input name="mqtt_port" type="number" min="1" max="65535" placeholder="1883" title="<?= th('1883 = geen versleuteling, 8883 = TLS') ?>"></label>
            <label><?= th('Versleuteling') ?>
              <select name="mqtt_tls"><option value="0"><?= th('Geen') ?></option><option value="1">TLS</option></select>
            </label>
          </div>
          <div class="row2">
            <label><?= th('Gebruikersnaam') ?><input name="mqtt_user" autocomplete="off" spellcheck="false"></label>
            <label><?= th('Wachtwoord') ?><input name="mqtt_pass" type="password" autocomplete="new-password"></label>
          </div>
          <details class="adv">
            <summary><?= th('Geavanceerd') ?></summary>
            <div class="row2">
              <label><?= th('Discovery-prefix') ?><input name="mqtt_prefix" spellcheck="false" placeholder="homeassistant"></label>
              <label><?= th('Basis-topic') ?><input name="mqtt_base_topic" spellcheck="false" placeholder="rzmon"></label>
            </div>
            <label class="switch"><input type="checkbox" name="mqtt_verify_ssl"><span></span> <?= th('TLS-certificaat controleren') ?></label>
          </details>
          <p class="small" id="mqtt-status"></p>
          <button type="button" class="btn" id="mqtt-test"><?= t('📡 Verbinding testen &amp; publiceren') ?></button>
        </div>


        <div data-sk="ai" class="card" id="ai-card">
          <h2>🤖 <?= th('AI-advies') ?></h2>
          <?= xp(t('Een AI legt een storing of incident uit en stelt een oplossing voor.'), t('<b>Wat het doet:</b> bij een check, een gebundelde storing, een incident of een IDS-melding vraag je met 🤖 om advies. RzSense stuurt mee wat het weet (de check, de server, statuswissels, andere problemen, wijzigingen, herstelpogingen en eventueel een diagnose) en toont het antwoord: wat er aan de hand is, de oorzaak, de oplossing in stappen en hoe je controleert of het werkt.<br><b>Aanbieders:</b> Claude, ChatGPT, Gemini en Grok (in de cloud, met een API-sleutel) of <b>Ollama</b> (lokaal of op een eigen server, gratis, je gegevens blijven thuis).<br><b>Staat het uit,</b> dan zie je nergens iets van AI en gaat er niets de deur uit.')) ?>
          <label class="switch"><input type="checkbox" name="ai_on"><span></span> <?= t('<b>AI-advies aan</b>') ?></label>
          <div id="ai-body">
            <label><?= th('Aanbieder') ?>
              <select name="ai_provider">
                <option value="ollama"><?= th('Ollama (lokaal of eigen server)') ?></option>
                <option value="claude">Claude (Anthropic)</option>
                <option value="openai">ChatGPT (OpenAI)</option>
                <option value="gemini">Gemini (Google)</option>
                <option value="grok">Grok (xAI)</option>
              </select>
            </label>
            <p class="muted small" data-fb-note hidden>🏠 <?= th('Ollama als reserve (Extra\'s → Terugvallen op Ollama):') ?></p>
            <div class="row2" data-prov="ollama" hidden>
              <label><?= th('Adres van Ollama') ?><input name="ai_ollama_url" spellcheck="false" placeholder="http://127.0.0.1:11434"></label>
              <label><?= th('Model') ?><input name="ai_ollama_model" list="ai-ml-ollama" spellcheck="false" placeholder="llama3.1:8b"><datalist id="ai-ml-ollama"></datalist></label>
            </div>
            <p class="muted small" data-prov="ollama" hidden><?= t('Ollama op een andere machine? Laat het daar luisteren op het netwerk (<code>OLLAMA_HOST=0.0.0.0</code>) en zet de poort 11434 in de firewall alleen open voor de server van RzSense: Ollama heeft zelf geen wachtwoord.') ?></p>
            <div class="row2" data-prov="claude" hidden>
              <label><?= th('API-sleutel') ?><input name="ai_claude_key" type="password" autocomplete="new-password" spellcheck="false"></label>
              <label><?= th('Model') ?><input name="ai_claude_model" list="ai-ml-claude" spellcheck="false" placeholder="claude-sonnet-5-5"><datalist id="ai-ml-claude"></datalist></label>
            </div>
            <div class="row2" data-prov="openai" hidden>
              <label><?= th('API-sleutel') ?><input name="ai_openai_key" type="password" autocomplete="new-password" spellcheck="false"></label>
              <label><?= th('Model') ?><input name="ai_openai_model" list="ai-ml-openai" spellcheck="false" placeholder="<?= th('klik op ⟳ Modellen ophalen') ?>"><datalist id="ai-ml-openai"></datalist></label>
            </div>
            <div class="row2" data-prov="gemini" hidden>
              <label><?= th('API-sleutel') ?><input name="ai_gemini_key" type="password" autocomplete="new-password" spellcheck="false"></label>
              <label><?= th('Model') ?><input name="ai_gemini_model" list="ai-ml-gemini" spellcheck="false" placeholder="<?= th('klik op ⟳ Modellen ophalen') ?>"><datalist id="ai-ml-gemini"></datalist></label>
            </div>
            <div class="row2" data-prov="grok" hidden>
              <label><?= th('API-sleutel') ?><input name="ai_grok_key" type="password" autocomplete="new-password" spellcheck="false"></label>
              <label><?= th('Model') ?><input name="ai_grok_model" list="ai-ml-grok" spellcheck="false" placeholder="<?= th('klik op ⟳ Modellen ophalen') ?>"><datalist id="ai-ml-grok"></datalist></label>
            </div>
            <div class="btn-row"><button type="button" class="btn" id="ai-models">⟳ <?= th('Modellen ophalen') ?></button>
              <button type="button" class="btn" id="ai-test">🧪 <?= th('Testen') ?></button></div>
            <p class="small" id="ai-test-res"></p>
            <h3 class="set-sub"><?= th('Wanneer') ?></h3>
            <div class="mode-switch"><span><?= th('🖐️ Op verzoek') ?></span>
              <label class="switch"><input type="checkbox" name="ai_auto"><span></span></label>
              <span><?= th('⚡ Automatisch') ?></span></div>
            <p class="muted small"><?= th('Automatisch: één advies per storing zodra die CRITICAL wordt (bij 3 of meer problemen op een server één advies voor die server). Op verzoek: alleen als je op 🤖 klikt.') ?></p>
            <div class="row3">
              <label id="ai-daily-wrap"><?= th('Daglimiet (vragen per dag)') ?><input type="number" name="ai_daily" min="0" max="10000" title="<?= th('0 = geen limiet') ?>"></label>
              <label><?= th('Time-out (seconden)') ?><input type="number" name="ai_timeout" min="10" max="600"></label>
              <label><?= th('Langste antwoord (tokens)') ?><input type="number" name="ai_max_tokens" min="200" max="8000"></label>
            </div>
            <label class="switch" id="ai-mask-wrap"><input type="checkbox" name="ai_mask"><span></span> <?= t('<b>Maskeren</b>: servernamen, IP- en e-mailadressen als labels naar de cloud (server-1, ip-1); in het antwoord weer de echte namen') ?></label>
            <h3 class="set-sub"><?= th('Diep zoeken en uitvoeren') ?></h3>
            <div data-nodirty>
              <label class="switch"><input type="checkbox" id="ai-diag"><span></span> <?= t('<b>Diep zoeken via de agent</b> bij een CRITICAL (vaste commando\'s die alleen lezen: status, logs, schijf, processen, poorten)') ?></label>
              <div class="mode-switch"><span><?= th('📝 Alleen tekst') ?></span>
                <label class="switch"><input type="checkbox" id="ai-exec"><span></span></label>
                <span><?= th('▶ Uitvoeren') ?></span></div>
              <p class="muted small"><?= th('Uitvoeren: een advies mag een bestaande herstelactie voorstellen (herstarten of een herstelscript uit heal.d); die gaat pas na jouw klik en bevestiging. Aanzetten vraagt je wachtwoord; de AI bedenkt nooit zelf commando\'s die worden uitgevoerd.') ?></p>
            </div>
            <p class="small" id="ai-usage"></p>
            <!-- 1.173.0: verbruik, tegoed en budget -->
            <h3 class="set-sub">💶 <?= th('Verbruik en tegoed') ?></h3>
            <?= xp(t('Wat het AI-advies kost: tokens en geschatte kosten, je tegoed en een maandbudget.'), t('<b>Waarom geschat:</b> de aanbieders laten met een gewone API-sleutel niet zien hoeveel tegoed je nog hebt. RzSense telt daarom zelf: elk antwoord zegt hoeveel tokens het kostte, en met de prijs per model die jij invult rekent RzSense de kosten uit. <b>Tegoed:</b> vul in hoeveel je hebt opgewaardeerd en vanaf wanneer; RzSense trekt de kosten eraf. Bij 80% krijg je een melding. <b>Maandbudget:</b> is het op, dan stopt AI-advies via de cloud tot volgende maand (of gaat het naar Ollama, zie Extra\'s). <b>Tegoed op:</b> meldt een aanbieder dat het tegoed op is, dan zie je dat hier en krijg je een melding met een link om op te waarderen.')) ?>
            <div id="ai-credit-out"></div>
            <div id="ai-cost-kpi" class="ai-kpis"></div>
            <div id="ai-cost-chart"></div>
            <label class="ai-budget"><?= th('Maandbudget (€)') ?> <span class="muted small"><?= th('0 = geen budget') ?></span><input type="number" name="ai_budget_month" min="0" max="100000" step="0.01" inputmode="decimal"></label>
            <div data-nodirty id="ai-cost-edit"></div>
            <!-- 1.173.0: extra's, elk apart uit te zetten -->
            <h3 class="set-sub">✨ <?= th('Extra\'s') ?></h3>
            <div class="ai-extras">
              <label class="switch"><input type="checkbox" name="ai_memory"><span></span> <?= t('<b>🧠 Geheugen</b>: geef bij een advies aan of het hielp of het probleem oploste. Bij dezelfde storing gaat dat mee naar de AI, zodat die begint met wat werkte.') ?></label>
              <label class="switch"><input type="checkbox" name="ai_cache"><span></span> <?= t('<b>♻️ Niet twee keer betalen</b>: dezelfde storing binnen de ingestelde tijd krijgt het bestaande advies; <i>Opnieuw vragen</i> vraagt altijd een nieuw.') ?></label>
              <label class="ai-sub"><?= th('Bewaren (uren)') ?><input type="number" name="ai_cache_hours" min="1" max="72"></label>
              <label class="switch"><input type="checkbox" name="ai_fallback"><span></span> <?= t('<b>🏠 Terugvallen op Ollama</b>: is de cloud onbereikbaar, het tegoed op of de daglimiet of het budget bereikt, dan gaat de vraag naar Ollama (vul daarvoor het adres en een model van Ollama in).') ?></label>
              <label class="switch"><input type="checkbox" name="ai_notify"><span></span> <?= t('<b>🔔 Samenvatting in de melding</b>: bij automatisch advies een korte melding met de waarschijnlijke oorzaak (kanalen: Meldingen → ℹ️ Systeeminfo).') ?></label>
              <p class="small muted"><?= t('<b>📅 Weekoverzicht</b>: één keer per week in gewone taal wat er speelde, wat terugkwam en waar je eens naar moet kijken.') ?> <?= th('Aan of uit, de dag en de kanalen stel je in bij Rapporten.') ?> <a href="#settings/reports" data-goto="settings/reports"><?= th('Naar Rapporten') ?></a></p>
              <div class="btn-row ai-sub"><button type="button" class="btn small-btn" id="ai-week-open">📅 <?= th('Weekoverzicht bekijken') ?></button></div>
              <label class="switch"><input type="checkbox" name="ai_triage"><span></span> <?= t('<b>🏷️ Oordeel bij IDS-incidenten</b>: nieuwe IDS-incidenten krijgen een label <i>lijkt onschuldig</i>, <i>verdacht</i> of <i>lijkt echt</i> met de reden. Alleen een label: de AI handelt nooit iets af. Slim: eerst jouw eigen keuzes (👤), dan regels (⚙️) en eerdere oordelen over dezelfde soort (♻️); alleen wat dan nog onzeker is, gaat naar de AI (🤖), één vraag per soort.') ?></label>
              <div class="lock-row ai-sub"><?= th('Hooguit') ?> <input type="number" name="ai_triage_max" min="0" max="500" class="num-in"> <?= th('AI-vragen voor oordelen per dag (0 = alleen regels en eerdere oordelen)') ?></div>
              <p class="small muted" id="ai-triage-stats"></p>
              <label class="switch"><input type="checkbox" name="ai_report"><span></span> <?= t('<b>📝 Verslag na een incident</b>: bij een opgelost incident een knop voor een kort verslag: wat er gebeurde, hoe lang, wat hielp en hoe je het voorkomt.') ?></label>
            </div>
            <h3 class="set-sub"><?= th('Laatste adviezen') ?></h3>
            <div id="ai-recent"></div>
          </div>
        </div>

        <div data-sk="ids" class="card" id="ids-card">
          <h2>🛡️ <?= th('IDS: detectie') ?></h2>
          <?= xp(t('Inbraakdetectie op je servers via de agents.'), t('<b>Wat het is:</b> het IDS (intrusion detection system) let via de agents op tekenen van een inbraak: gewijzigde gevoelige bestanden, nieuwe gebruikers en poorten, verdachte processen en verbindingen, brute force en een inlog na brute force. <b>Waar je het ziet:</b> op de pagina <i>🛡️ IDS</i>. Critical gaat ook via Pushover, ook tijdens de stille uren. <b>Leren of blokkeren:</b> in <i>🎓 Leren</i> meldt het IDS alleen; in <i>⛔ Blokkeren</i> grijpt het ook in.')) ?>
          <div data-nodirty>
            <label class="switch"><input type="checkbox" id="ids-enabled"><span></span> <?= t('<b>IDS aan</b>') ?></label>
            <div class="mode-switch"><span id="ids-l-learn"><?= th('🎓 Leren') ?></span>
              <label class="switch"><input type="checkbox" id="ids-mode"><span></span></label>
              <span id="ids-l-block"><?= th('⛔ Blokkeren') ?></span></div>
            <p class="muted small" id="ids-mode-info"></p>
          </div>
          <div class="row2">
            <label><?= th('Basismeting (dagen)') ?> <span class="muted small"><?= th('eerst alleen vastleggen wat normaal is') ?></span><input type="number" name="ids_baseline_hours" min="0" max="720"></label>
            <label><?= th('Brute force bij … mislukte SSH-logins') ?><input type="number" name="ids_fail_count" min="2" max="1000"></label>
          </div>
          <div class="row3">
            <label><?= th('… binnen (minuten)') ?><input type="number" name="ids_fail_minutes" min="1" max="1440"></label>
            <label><?= th('Blokkeren (uren)') ?><input type="number" name="ids_block_hours" min="1" max="8760"></label>
            <label><?= th('Bij herhaling (dagen)') ?><input type="number" name="ids_repeat_days" min="1" max="365"></label>
          </div>
          <p class="small"><b><?= th('Nooit blokkeren') ?></b>: <?= t('je LAN en RzSense zelf worden nooit automatisch geblokkeerd. Andere IP-adressen en netwerken zet je op de <a href="#settings/whitelist" data-goto="settings/whitelist">✅ whitelist</a>; die geldt voor RzSense én het IDS op al je servers.') ?></p>
          <label class="switch"><input type="checkbox" name="ids_block_shared"><span></span> <?= th('Automatische blokkades op alle servers') ?></label>
          <?= xp(t('Wordt een aanvaller op één server geblokkeerd, dan meteen op al je servers.'), t('Een aanvaller die op één server automatisch geblokkeerd wordt (brute force, honeypot), wordt meteen ook geblokkeerd op al je andere servers met het IDS op <i>Blokkeren</i>. <b>Goed om te weten:</b> met de hand blokkeren geldt alleen voor de server die je kiest.')) ?>
          <h3 class="set-sub"><?= th('🍯 Honeypot') ?></h3>
          <?= xp(t('Lokaas: inlognamen en een poort die alleen aanvallers proberen.'), t('<b>Wat het is:</b> een honeypot is lokaas. Deze gebruikersnamen en deze poort gebruikt niemand behalve een aanvaller, dus één poging is genoeg. <b>Wat het doet:</b> komt de poging van buiten, dan blokkeert het IDS meteen (in de modus <i>Blokkeren</i>). Komt hij uit je eigen netwerk, dan krijg je een critical-melding: waarschijnlijk is daar een apparaat besmet. <b>Goed om te weten:</b> namen die op een server echt bestaan, worden daar overgeslagen.')) ?>
          <label><?= th('Honeypot-gebruikers') ?> <span class="muted small"><?= th('(gescheiden door spaties; vul gerust aan)') ?></span>
            <textarea name="ids_decoy_users" rows="2" class="mono"></textarea></label>
          <label><?= th('Honeypot-poort') ?> <span class="muted small"><?= th('(TCP; leeg = uit. Er luistert niets: de agent zet een regel in zijn eigen firewalltabel)') ?></span>
            <input type="number" name="ids_decoy_port" min="1" max="65535" placeholder="2323"></label>
          <h3 class="set-sub"><?= th('📁 Bestanden bewaken') ?></h3>
          <?= xp(t('Meldt nieuwe, gewijzigde en verwijderde bestanden in deze mappen.'), t('<b>Wat het doet:</b> de agent onthoudt van elk bestand in deze paden de vingerafdruk (SHA-256), grootte, rechten en eigenaar, en meldt nieuwe, gewijzigde en verwijderde bestanden. De inhoud gaat nooit naar RzSense. <b>Paden:</b> een map wordt helemaal doorzocht; met jokertekens kies je bestanden, bijvoorbeeld <code>/var/www/**/*.php</code>. Dit geldt voor alle servers; per server voeg je paden toe via <i>✎ Bewerken</i>. <b>Grenzen:</b> hooguit 20 paden en 10.000 bestanden per server.')) ?>
          <div id="fim-editor"></div>
          <input type="hidden" name="ids_fim">
          <label><?= th('Uitsluiten') ?> <span class="muted small"><?= t('(namen als <code>*.log</code>, mappen als <code>cache/</code>, of paden; gescheiden door spaties)') ?></span>
            <input name="ids_fim_exclude" class="mono" placeholder="*.log *.tmp cache/"></label>
          <h3 class="set-sub"><?= th('🔑 Toegang') ?></h3>
          <label class="switch"><input type="checkbox" name="auth_events"><span></span> <?= t('Toegang bijhouden: logins, <code>sudo</code> en <code>su</code>') ?></label>
          <?= xp(t('Houdt bij wie er inlogt (SSH, console, sudo, su), ook mislukte pogingen.'), t('<b>Wat het doet:</b> de agent houdt bij wie er inlogt via SSH en de console, en wie <code>sudo</code> en <code>su</code> gebruikt, ook mislukte pogingen (per IP-adres per uur opgeteld). <b>Waar je het ziet:</b> op <i>🛡️ IDS → Toegang</i>; er gaat geen melding uit. <b>Goed om te weten:</b> dit werkt ook als het IDS uit staat. Op een server zelf zet je het uit met <code>AUTH_EVENTS=0</code>.')) ?>
          <label><?= th('Negeren') ?> <span class="muted small"><?= th('(gebruikers en/of IP-adressen of netwerken, gescheiden door spaties)') ?></span>
            <textarea name="auth_ignore" rows="2" placeholder="<?= th('bv. ansible backup 192.168.2.5 10.0.0.0/8') ?>"></textarea></label>
          <h3 class="set-sub"><?= th('📬 Wekelijks overzicht') ?></h3>
          <p class="small muted"><?= th('Het IDS-weekoverzicht stel je in bij Rapporten: aan of uit, de dag, de tijd en de kanalen.') ?> <a href="#settings/reports" data-goto="settings/reports"><?= th('Naar Rapporten') ?></a></p>
        </div>

        <div data-sk="ids_edr" class="card" id="ids-edr-card">
          <h2>🛡️ <?= th('Endpoint-bewaking') ?></h2>
          <?= xp(t('Kijkt live mee op je servers: processen, verbindingen, bestanden en containers.'), t('<b>Wat het is:</b> endpoint-bewaking (EDR). De agent (1.63.0 of nieuwer) ziet op het moment zelf nieuwe <b>processen</b> (auditd), <b>verbindingen</b> en luisterpoorten, nieuwe <b>bestanden</b> in tijdelijke mappen en webmappen (inotify) en <b>containers</b>. <b>Wat het doet:</b> RzSense toetst alles aan zijn eigen regels en aan de open <b>Sigma</b>-regels. Een treffer wordt een incident, met het bewijs erbij. <b>Waar je het ziet:</b> op <i>🛡️ IDS → 🛰️ Live</i>. Per server zet je het aan of uit in de Agent Manager.')) ?>
          <label class="switch"><input type="checkbox" name="edr_on"><span></span> <?= t('<b>Endpoint-bewaking aan</b> (op de servers waar het in de Agent Manager aan staat)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_sigma"><span></span> <?= t('<b>Sigma-regels</b> van SigmaHQ gebruiken (naast de eigen regels van RzSense)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_web"><span></span> <?= t('<b>Payload-inspectie</b> van verdachte webverzoeken (via je reverse proxy): SQL-injectie, XSS, Log4Shell, bekende exploits met CVE …') ?></label>
          <h3 class="set-sub"><?= th('Zelf ingrijpen (alleen in de modus ⛔ Blokkeren)') ?></h3>
          <label class="switch"><input type="checkbox" name="edr_kill"><span></span> <?= t('<b>Proces stoppen</b> bij een regel die dat zegt (webshell, reverse shell, cryptominer …)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_block"><span></span> <?= t('<b>IP blokkeren</b> bij een webaanval met een herkende payload') ?></label>
          <label class="switch"><input type="checkbox" name="edr_isolate"><span></span> <?= t('<b>Server isoleren</b> (24 uur) bij een reverse shell of cryptominer: alleen RzSense en je LAN kunnen er dan nog bij') ?></label>
          <?= xp(t('Wat RzSense bij een treffer doet, staat altijd in het incident.'), t('Wat RzSense zelf doet (bijvoorbeeld een proces stoppen of een IP blokkeren), staat altijd in het incident en gaat als critical ook naar Pushover. <b>Goed om te weten:</b> in de modus <i>🎓 Leren</i> grijpt RzSense nooit in, maar meldt het wel.')) ?>
          <h3 class="set-sub"><?= th('🧬 Afwijkingen en ketens') ?></h3>
          <label class="switch"><input type="checkbox" name="edr_anom"><span></span> <?= t('<b>Afwijkingen melden</b> (RzSense leert per server wat normaal is)') ?></label>
          <div class="row">
            <label><?= th('Leerperiode (dagen)') ?> <input name="edr_learn_days" type="number" min="1" max="90"></label>
            <label><?= th('Incident vanaf score') ?> <span class="muted small"><?= th('(lager: alleen op de pagina)') ?></span> <input name="edr_anom_min" type="number" min="1" max="100"></label>
          </div>
          <label class="switch"><input type="checkbox" name="edr_chain"><span></span> <?= t('<b>Ketens melden</b> (combinaties die samen op een aanval wijzen)') ?></label>
          <label><?= th('Venster voor een keten (minuten)') ?> <input name="edr_chain_min" type="number" min="5" max="1440"></label>
          <label class="switch"><input type="checkbox" name="edr_chain_act"><span></span> <?= t('<b>Ingrijpen bij een keten</b> (aanvaller blokkeren; isoleren bij webshell of malware)') ?></label>
          <?= xp(t('Afwijkingen (iets nieuws) en ketens (een combinatie die op een aanval lijkt).'), t('<b>Afwijking:</b> iets wat de server in de leerperiode nooit deed. Boven de ingestelde score wordt het een incident (warning). <b>Keten:</b> een combinatie die op een aanval lijkt, zoals webaanval → webshell, inloggen → achterdeur, of iets binnenhalen → contact naar buiten. Een keten is critical en gaat naar Pushover. <b>Goed om te weten:</b> ingrijpen gebeurt alleen in de modus <i>⛔ Blokkeren</i>. Alles staat op <i>🛡️ IDS → 🧬 Afwijkingen</i> en <i>🧭 MITRE</i>.')) ?>
        </div>

        <div data-sk="ids_ti" class="card" id="ids-ti-card">
          <h2>🌐 <?= th('Dreigingsinformatie') ?></h2>
          <?= xp(t('Zoekt op of een IP-adres of bestand uit je incidenten al bekend is als gevaarlijk.'), t('<b>Wat het doet:</b> RzSense zoekt op wat er op internet bekend is over de IP-adressen en bestanden uit je incidenten: een bekende aanvaller, een onschuldige scanner, een Tor-uitgang, bekende malware? Dat staat dan bij het incident en bij het IP-adres, en RzSense handelt ernaar. <b>Privacy:</b> alleen het IP-adres of de vingerafdruk (hash) van een bestand gaat naar buiten, nooit namen van servers of gebruikers. <b>Goed om te weten:</b> werkt alleen als het IDS aan staat.')) ?>
          <label class="switch"><input type="checkbox" name="ti_on"><span></span> <?= t('<b>Dreigingsinformatie aan</b>') ?></label>
          <h3 class="set-sub">AbuseIPDB</h3>
          <label><?= th('API-sleutel') ?> <span class="hint"><?= t('(gratis: <a href="https://www.abuseipdb.com/account/api" target="_blank" rel="noopener">abuseipdb.com → Account → API</a>; 1.000 opzoekingen per dag)') ?></span>
            <input name="ti_abuseipdb_key" type="password" autocomplete="new-password" class="mono" placeholder="<?= th('plak hier je sleutel') ?>"></label>
          <p class="small"><button type="button" class="btn small-btn" data-ti-test="abuse"><?= th('Sleutel testen') ?></button> <span class="muted" data-ti-res="abuse"></span></p>
          <label class="switch"><input type="checkbox" name="ti_report"><span></span> <?= t('<b>Melden</b> bij AbuseIPDB wie RzSense automatisch blokkeert (brute force, webaanval, honeypot; hooguit één keer per dag per IP)') ?></label>
          <?= xp(t('Meldt aanvallers terug aan AbuseIPDB, zodat anderen ze ook kunnen weren.'), t('Het IP-adres, de soort aanval en de tekst "RzSense IDS" gaan naar AbuseIPDB, zodat anderen die aanvaller ook kunnen weren. <b>Goed om te weten:</b> adressen uit je eigen netwerk of van de whitelist worden nooit gemeld.')) ?>
          <h3 class="set-sub"><?= th('Andere bronnen (gratis)') ?></h3>
          <label class="switch"><input type="checkbox" name="ti_greynoise"><span></span> <?= t('<b>GreyNoise</b> (scant dit IP het hele internet, is het een bekende dienst zoals Google of Shodan, of kwaadaardig)') ?></label>
          <label><?= th('GreyNoise-sleutel') ?> <span class="hint"><?= th('(niet nodig; zonder sleutel ongeveer 50 per dag)') ?></span><input name="ti_greynoise_key" type="password" autocomplete="new-password" class="mono"></label>
          <label class="switch"><input type="checkbox" name="ti_shodan"><span></span> <?= t('<b>Shodan InternetDB</b> (open poorten, lekken en labels zoals c2 of malware van het IP zelf)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_circl"><span></span> <?= t('<b>CIRCL hashlookup</b> (is een nieuw programma een bekend, goed bestand)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_tor"><span></span> <?= t('<b>Tor-uitgangen</b> herkennen (dagelijkse lijst)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_tor_block"><span></span> <?= t('Tor-uitgangen ook <b>vooraf blokkeren</b> op de servers (modus Blokkeren, bij de bekende aanvallers)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_feodo"><span></span> <?= t('<b>abuse.ch Feodo Tracker</b> (botnet-servers bij de bekende aanvallers; een verbinding ernaartoe is critical)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_urlhaus"><span></span> <?= t('<b>abuse.ch URLhaus</b> (domeinen met malware, voor de DNS-controle via AdGuard)') ?></label>
          <h3 class="set-sub">abuse.ch <span class="muted small"><?= th('(optioneel)') ?></span></h3>
          <label><?= th('abuse.ch Auth-Key') ?> <span class="hint"><?= t('(gratis: <a href="https://auth.abuse.ch/" target="_blank" rel="noopener">auth.abuse.ch</a>; voor MalwareBazaar en ThreatFox)') ?></span>
            <input name="ti_abusech_key" type="password" autocomplete="new-password" class="mono"></label>
          <p class="small"><button type="button" class="btn small-btn" data-ti-test="abusech"><?= th('Sleutel testen') ?></button> <span class="muted" data-ti-res="abusech"></span></p>
          <h3 class="set-sub"><?= th('Wat RzSense ermee doet') ?></h3>
          <label class="switch"><input type="checkbox" name="ti_block"><span></span> <?= t('<b>Bekende aanvallers blokkeren</b> (modus ⛔ Blokkeren), vanaf deze score:') ?></label>
          <label><?= th('Score voor "bekende aanvaller" (0–100)') ?> <span class="hint"><?= th('(AbuseIPDB-percentage, of 80–90 bij GreyNoise/Shodan/abuse.ch)') ?></span><input name="ti_block_score" type="number" min="10" max="100"></label>
          <label class="switch"><input type="checkbox" name="ti_escalate"><span></span> <?= t('Een <b>login</b> vanaf een bekende aanvaller wordt critical (Pushover)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_close_benign"><span></span> <?= t('Een webaanval van een <b>onschuldige scanner</b> (GreyNoise) vanzelf afhandelen') ?></label>
          <label class="switch"><input type="checkbox" name="ti_cve"><span></span> <?= t('Een webaanval op een <b>lek dat de server echt heeft</b> (tabblad Kwetsbaarheden) is critical') ?></label>
          <h3 class="set-sub"><?= th('🧭 Netwerk: AdGuard, Suricata en YARA') ?></h3>
          <label class="switch"><input type="checkbox" name="dns_on"><span></span> <?= t('<b>DNS-controle via AdGuard</b> (elk apparaat: domeinen van malware of phishing, DNS-tunnels, verzonnen domeinen)') ?></label>
          <p class="muted small" id="dns-status" style="margin-top:-.4rem"></p>
          <label class="switch"><input type="checkbox" name="dns_nrd"><span></span> <?= t('<b>Nieuw geregistreerde domeinen</b> herkennen (jonger dan <i>N</i> dagen; de domeinnaam gaat dan naar de RDAP-server van de registry)') ?></label>
          <label><?= th('Nieuw = jonger dan') ?> <span class="hint"><?= th('(dagen)') ?></span> <input type="number" name="dns_nrd_days" min="1" max="365" class="num-in"></label>
          <label class="switch"><input type="checkbox" name="suricata_on"><span></span> <?= t('<b>Suricata</b> (alerts van de agents gebruiken, als Suricata op een server draait; agent 1.64.0)') ?></label>
          <label class="switch"><input type="checkbox" name="suricata_block"><span></span> <?= t('<b>Blokkeren</b> bij een ernstig Suricata-alert (ernst 1, aanvaller van buiten, modus Blokkeren)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_yara"><span></span> <?= t('<b>YARA</b> (regels van RzSense naar de agents sturen: nieuwe bestanden op webshells, miners, botnets en rootkits controleren; YARA moet op de server staan)') ?></label>
          <p class="muted small" id="ti-status"></p>
          <p class="small"><button type="button" class="btn small-btn" id="ti-lists"><?= th('Lijsten nu bijwerken') ?></button></p>
          <?= xp(t('Welke bronnen RzSense gebruikt en hoe vaak hij opzoekt.'), t('<b>Bronnen:</b> AbuseIPDB, GreyNoise Community, Shodan InternetDB, CIRCL hashlookup, abuse.ch (Feodo Tracker, URLhaus, ThreatFox, MalwareBazaar) en het Tor Project. <b>Hoe:</b> opzoeken gebeurt op de achtergrond (cron), met een cache van een dag en een limiet per dag, zodat je binnen de gratis grenzen blijft.')) ?>
        </div>

        <div data-sk="dnsbl" class="card" id="dnsbl-card">
          <h2>🚫 <?= th('AdGuard-blokkadelijst') ?></h2>
          <?= xp(t('Een lijst voor AdGuard Home, zodat bekende malwaredomeinen in je hele netwerk geblokkeerd worden.'), t('<b>Hoe het werkt:</b> RzSense maakt een filterlijst met de <b>malwaredomeinen</b> uit de dreigingsinformatie (URLhaus, ThreatFox, dagelijks bijgewerkt), je <b>bewaakte domeinen</b> (IDS → Jagen) en domeinen die je hieronder zelf invult. AdGuard haalt de lijst op via een <b>geheime URL</b>, alleen vanaf je lokale netwerk. Met <b>Toevoegen aan AdGuard</b> zet RzSense hem er zelf in (Koppelingen → AdGuard); anders plak je de URL bij <i>Filters → DNS-blokkadelijsten → Blokkadelijst toevoegen → Eigen lijst</i>. Domeinen bij <i>Nooit blokkeren</i> en het adres van RzSense komen er nooit in.')) ?>
          <label class="switch"><input type="checkbox" name="dnsbl_on"><span></span> <?= t('<b>AdGuard-blokkadelijst</b> aanbieden') ?></label>
          <label class="switch"><input type="checkbox" name="dnsbl_ti"><span></span> <?= th('Malwaredomeinen uit de dreigingsinformatie (URLhaus, ThreatFox)') ?></label>
          <label class="switch"><input type="checkbox" name="dnsbl_ioc"><span></span> <?= th('Bewaakte domeinen (IDS → Jagen)') ?></label>
          <label><?= th('Eigen domeinen') ?> <span class="hint"><?= th('(één per regel)') ?></span><textarea name="dnsbl_own" rows="3" class="mono" placeholder="slecht-voorbeeld.nl"></textarea></label>
          <label><?= th('Nooit blokkeren') ?> <span class="hint"><?= th('(één per regel)') ?></span><textarea name="dnsbl_allow" rows="2" class="mono"></textarea></label>
          <div id="dnsbl-box"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="ids_attack" class="card" id="ids-attack-card">
          <h2>🌍 <?= th('IDS: aanvallers en verkeer') ?></h2>
          <?= xp(t('Bekende aanvallers, landen, dreigingsinformatie en verdachte DNS: wat het IDS van buiten weet.'), t('<b>Wat het doet:</b> het IDS gebruikt lijsten met bekende aanvallers, het land van een adres en informatie van diensten als AbuseIPDB om een aanval eerder en zekerder te herkennen. <b>Zo stel je het in:</b> zet de lijsten aan die je wilt; een sleutel van een dienst vul je in bij die dienst. De score bepaalt vanaf wanneer een adres als bekende aanvaller geldt. <b>Goed om te weten:</b> zonder het IDS (IDS: detectie) doet deze kaart niets. Je eigen netwerken en de whitelist worden nooit geblokkeerd.')) ?>
          <p class="muted small"><?= t('Werkt alleen als het IDS aan staat (<a href="#settings/ids" data-goto="settings/ids">IDS: detectie</a>).') ?></p>
          <h3 class="set-sub"><?= th('☠️ Bekende aanvallers en land') ?></h3>
          <label class="switch"><input type="checkbox" name="ids_blocklists"><span></span> <?= th('Bekende aanvallers (Spamhaus DROP en FireHOL level 1, dagelijks bijgewerkt)') ?></label>
          <?= xp(t('Lijsten met bekende aanvallers, die de agents vooraf al tegenhouden.'), t('In de modus <i>Blokkeren</i> zetten de agents de adressen van deze lijsten vooraf in hun firewall, zodat een bekende aanvaller niet eens een poging kan doen. In de modus <i>Leren</i> krijgen logins en incidenten van zo\'n adres het label ☠️. <b>Goed om te weten:</b> je eigen netwerk staat er nooit in.')) ?>
          <label class="switch"><input type="checkbox" name="ids_geo"><span></span> <?= th('Land per IP-adres (DB-IP Lite, maandelijks bijgewerkt)') ?></label>
          <label><?= th('Normale landen') ?> <span class="muted small"><?= th('(landcodes, bv. NL BE DE; een SSH-login van buiten vanuit een ander land is critical)') ?></span>
            <input name="ids_geo_allowed" class="mono" placeholder="NL"></label>
          <p class="muted small" id="ipintel-status"></p>
          <?= xp(t('Uit welk land een IP-adres komt, opgezocht op de RzSense-server zelf.'), t('<b>Bron:</b> <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a> (CC BY 4.0), elke maand bijgewerkt. <b>Privacy:</b> opzoeken gebeurt op de RzSense-server zelf; er gaat per login niets naar buiten. Met <i>Nu bijwerken</i> haal je de nieuwste lijst meteen op.')) ?>
          <p class="small"><button type="button" class="btn small-btn" id="ipintel-update"><?= th('Nu bijwerken') ?></button></p>
          <h3 class="set-sub"><?= th('🌐 Uitgaande verbindingen') ?> <span class="muted small"><?= th('(agent 1.56.0)') ?></span></h3>
          <label class="switch"><input type="checkbox" name="ids_outbound"><span></span> <span><?= t('Melden als een server verbinding maakt met een <b>bekende aanvaller</b> (critical)') ?></span></label>
          <label class="switch"><input type="checkbox" name="ids_outbound_country"><span></span> <span><?= t('…en met een land buiten je <b>normale landen</b> (warning, per programma aan te leren)') ?></span></label>
          <label class="switch"><input type="checkbox" name="ids_outbound_block"><span></span> <span><?= t('In de modus blokkeren uitgaand verkeer naar bekende aanvallers <b>blokkeren</b> (ook vanuit containers)') ?></span></label>
          <?= xp(t('Meldt verbindingen van je servers naar ongewone landen of bekende aanvallers.'), t('<b>Waarom:</b> zo valt een server op die al besmet is, bijvoorbeeld door een cryptominer of een verbinding met de aanvaller. <b>Hoe:</b> in de eerste uren (de basismeting) leert het IDS welke landen normaal zijn. <b>Vrijgeven:</b> klik bij het incident op <b>✅ Vrijgeven</b> om het IP op de whitelist te zetten (1, 7, 30 dagen of altijd), of op <b>Dit is normaal</b> voor dat programma en land.')) ?>
          <h3 class="set-sub"><?= th('📜 Logs van webdiensten') ?> <span class="muted small"><?= th('(agent 1.58.0)') ?></span></h3>
          <?= xp(t('Herkent aanvallen in de logs van je webdiensten, zoals je reverse proxy (bv. SWAG) en Home Assistant.'), t('<b>Wat het doet:</b> de agent leest elke minuut de nieuwe regels uit de logs en vat ze per IP samen; RzSense herkent daarin aanvallen. <b>Waar staan de logs?</b> Leeg of <code>auto</code> = zelf zoeken (de container van SWAG, gangbare mappen; in Home Assistant via de Supervisor). <b>Goed om te weten:</b> je eigen netwerk en de whitelist tellen nooit mee.')) ?>
          <div class="row3">
            <label class="switch"><input type="checkbox" name="weblog_swag"><span></span> <span><?= t('<b>Reverse proxy / webserver</b> (nginx-log)') ?></span></label>
            <label class="switch"><input type="checkbox" name="weblog_ha"><span></span> <span><b>Home Assistant</b></span></label>
            <label class="switch"><input type="checkbox" name="weblog_vw"><span></span> <span><b>Vaultwarden</b></span></label>
          </div>
          <div class="row3">
            <label><?= th('nginx access-log (reverse proxy)') ?><input name="weblog_swag_path" class="mono" placeholder="auto"></label>
            <label><?= th('Home Assistant: home-assistant.log') ?><input name="weblog_ha_path" class="mono" placeholder="auto"></label>
            <label><?= th('Vaultwarden: logbestand of container') ?><input name="weblog_vw_path" class="mono" placeholder="auto"></label>
          </div>
          <div id="weblog-status" class="small"></div>
          <p class="small" style="margin:.6rem 0 .2rem"><b><?= th('Scenario\'s') ?></b> <span class="muted"><?= th('(elk apart aan of uit)') ?></span></p>
          <label class="switch"><input type="checkbox" name="wl_paths"><span></span> <span><?= t('Web: <b>bekend aanvalspad</b> met een foutcode (<code>/.env</code>, <code>/.git/</code>, <code>wp-login.php</code>, <code>phpmyadmin</code> …): meteen') ?></span></label>
          <label class="switch"><input type="checkbox" name="wl_ua"><span></span> <span><?= t('Web: <b>scanner</b> (sqlmap, nikto, nuclei, zgrab …): meteen') ?></span></label>
          <div class="lock-row"><label class="switch"><input type="checkbox" name="wl_404"><span></span> <span><?= t('Web: <b>veel 404</b>, vanaf') ?></span></label> <input type="number" name="wl_404_n" min="3" max="10000" class="num-in"></div>
          <div class="lock-row"><label class="switch"><input type="checkbox" name="wl_auth"><span></span> <span><?= t('Web: <b>veel 401/403</b> (wachtwoorden raden), vanaf') ?></span></label> <input type="number" name="wl_auth_n" min="3" max="10000" class="num-in"></div>
          <div class="lock-row"><label class="switch"><input type="checkbox" name="wl_nohost"><span></span> <span><?= t('Web: <b>zonder hostnaam</b> (444, scant je IP-adres), vanaf') ?></span></label> <input type="number" name="wl_nohost_n" min="1" max="10000" class="num-in"></div>
          <div class="lock-row"><?= t('<b>Home Assistant</b>: mislukte logins vanaf') ?> <input type="number" name="wl_ha_n" min="2" max="1000" class="num-in"> · <?= t('<b>Vaultwarden</b> vanaf') ?> <input type="number" name="wl_vw_n" min="2" max="1000" class="num-in"></div>
          <div class="lock-row"><?= th('…binnen (minuten)') ?> <input type="number" name="wl_minutes" min="1" max="1440" class="num-in"></div>
          <label class="switch"><input type="checkbox" name="weblog_block"><span></span> <span><?= t('In de modus blokkeren zo\'n IP ook <b>blokkeren</b> (op al je servers, via <i>Automatische blokkades op alle servers</i>)') ?></span></label>
          <?= xp(t('Deblokkeren kan altijd; ook verkeer naar Docker-containers wordt tegengehouden.'), t('Deblokkeren kan altijd bij <i>IDS → Blokkades</i>. <b>SWAG in Docker:</b> de agent blokkeert vóór Docker (prerouting), dus ook verkeer naar de container wordt tegengehouden.')) ?>
        </div>

        <div data-sk="ids_servers" class="card" id="ids-servers-card">
          <h2>🖥️ <?= th('IDS: servers') ?></h2>
          <?= xp(t('Wat het IDS op je servers zelf bewaakt: webshells, persistentie, ransomware, onbekende programma\'s en tijdelijk sudo.'), t('<b>Wat het doet:</b> de agent kijkt op de server naar wat een aanvaller doet nadat hij binnen is: een webserver die opdrachten start, nieuwe opstartregels, massaal versleutelde bestanden en programma\'s die er niet thuishoren. <b>Zo stel je het in:</b> zet per onderdeel aan wat je wilt. In de modus <i>Blokkeren</i> kan de agent een proces meteen stoppen; per server stel je het in de Agent Manager anders in. <b>Goed om te weten:</b> sommige onderdelen hebben een nieuwere agent nodig (staat erbij). Een vals alarm geef je vrij bij het incident.')) ?>
          <p class="muted small"><?= t('Werkt alleen als het IDS aan staat (<a href="#settings/ids" data-goto="settings/ids">IDS: detectie</a>).') ?></p>
          <h3 class="set-sub"><?= th('🕸️ Webshell en persistentie') ?> <span class="muted small"><?= th('(agent 1.56.0)') ?></span></h3>
          <label class="switch"><input type="checkbox" name="ids_webshell"><span></span> <span><?= t('<b>Webshell</b>: een webserver (nginx, apache, php-fpm …) start een shell of een programma als <code>curl</code>, <code>wget</code> of <code>python</code>') ?></span></label>
          <label class="switch"><input type="checkbox" name="ids_webshell_kill"><span></span> <span><?= t('In de modus blokkeren dat <b>proces meteen stoppen</b>') ?></span></label>
          <?= xp(t('Een webserver die opdrachten uitvoert is critical; vrijgeven kan bij het incident.'), t('Dit is altijd critical en gaat ook naar Pushover. <b>Toch normaal?</b> Bijvoorbeeld een beheerpaneel dat scripts start: klik bij het incident op <b>✅ Vrijgeven</b>, dan wordt het voortaan niet meer gemeld of gestopt. <b>Goed om te weten:</b> PHP-mail via <code>sendmail</code> telt niet.')) ?>
          <label class="switch"><input type="checkbox" name="ids_persist"><span></span> <span><?= t('<b>Nieuwe automatisch startende dienst of timer</b> (systemd)') ?></span></label>
          <?= xp(t('Meldt nieuwe manieren waarop iets na een herstart vanzelf weer start.'), t('<b>Waarom:</b> zo blijft een aanvaller na een herstart aanwezig (persistentie), bijvoorbeeld met een nieuwe dienst, cronjob of opstartregel. <b>Goed om te weten:</b> tijdens een pakketupdate wordt dit niet gemeld. Nieuwe cronjobs en unit-bestanden ziet het IDS al bij de bestanden.')) ?>
          <h3 class="set-sub"><?= th('🧰 Hardening') ?> <span class="muted small"><?= th('(agent 1.57.0)') ?></span></h3>
          <label class="switch"><input type="checkbox" name="ids_harden"><span></span> <span><?= t('<b>Hardening-score</b> per server, met een incident als iets slechter wordt') ?></span></label>
          <?= xp(t('Elk uur een veiligheidsscore per server, met uitleg hoe je het dichtzet.'), t('<b>Wat het doet:</b> de agent controleert elk uur de basisbeveiliging: SSH met wachtwoord of als root, de firewall, automatische updates, open databases, de Docker-socket, accounts zonder wachtwoord, sudo zonder wachtwoord, /tmp en SELinux. <b>Waar je het ziet:</b> klik op de score bij een server (IDS) voor uitleg en hoe je het dichtzet; daar kun je een server ook isoleren of een gebruiker vergrendelen.')) ?>
        </div>

        <div data-sk="vulns" class="card" id="vulns-card">
          <h2>🐞 <?= th('Kwetsbaarheden') ?></h2>
          <?= xp(t('Zoekt bekende lekken (CVE\'s) in de software op je servers.'), t('<b>Wat het doet:</b> de agents (1.47.0 of nieuwer) sturen hun softwarelijst mee; voor de RzSense-server leest RzSense die zelf. RzSense zoekt daar de bekende kwetsbaarheden (CVE\'s) bij op <b>OSV.dev</b>, met de adviezen van de distributie zelf. Erbij staat of een lek <i>actief misbruikt</i> wordt (lijst van CISA) en hoe groot de kans op misbruik is (EPSS). <b>Privacy:</b> naar OSV.dev gaan alleen pakketnamen, versies en de distributie, geen servernamen of IP-adressen. <b>Ondersteund:</b> Debian, Ubuntu, Rocky Linux, AlmaLinux (en RHEL/CentOS via AlmaLinux) en Alpine.')) ?>
          <div data-nodirty><label class="switch"><input type="checkbox" id="vuln-enabled"><span></span> <?= t('<b>Kwetsbaarheden aan</b> (tabblad 🐞 Kwetsbaarheden)') ?></label></div>
          <div class="row2">
            <label><?= th('Dagelijks scannen om') ?><input type="time" name="vuln_time"></label>
            <label><?= th('Tonen vanaf') ?>
              <select name="vuln_min_severity"><option value="0"><?= th('alles') ?></option><option value="1">low</option><option value="2">medium</option><option value="3">high</option><option value="4">critical</option></select></label>
          </div>
          <input type="hidden" name="vuln_exclude">
          <div class="vuln-exclude"><span class="muted small"><?= th('Niet scannen:') ?></span> <span id="vuln-exclude-list"></span></div>
          <?= xp(t('Wat er nodig is, wanneer je een melding krijgt en hoe bijwerken werkt.'), t('<b>Nodig:</b> uitgaand HTTPS naar <code>api.osv.dev</code>, <code>www.cisa.gov</code>, <code>api.first.org</code> en <code>services.nvd.nist.gov</code>. <b>Meldingen:</b> een ℹ️ info-melding bij een nieuwe critical of actief misbruikte kwetsbaarheid volgt de rij <i>Info</i> bij Meldingen. <b>Bijwerken:</b> via de agent kan dat als commando\'s als root aan staan voor die server; anders krijg je het commando om zelf uit te voeren.')) ?>
          <div class="btn-row"><button type="button" class="btn" id="vuln-scan-now">🔍 <?= th('Nu scannen') ?></button> <span class="muted small" id="vuln-scan-info"></span></div>
        </div>

        <div data-sk="sudo" class="card" id="sudo-card">
          <h2>🔑 <?= th('Tijdelijk sudo') ?></h2>
          <?= xp(t('Geef een gebruiker op één server voor korte tijd sudo, in plaats van altijd.'), t('<b>Hoe het werkt:</b> in de <b>Agent Manager</b> (kaart <i>Tijdelijk sudo</i>) kies je een gebruiker, hoe lang (standaard 30 minuten) en waarom; je bevestigt met je <b>wachtwoord</b> en, als je die hebt, je <b>2FA-code</b>. De agent (1.93.0) zet een eigen bestand in <i>/etc/sudoers.d</i>, gecontroleerd met visudo, en haalt het op tijd weer weg; een systemd-timer is het vangnet als de agent stopt. <b>Intrekken</b> kan altijd. Root kan niet, en andere sudo-regels raakt de agent nooit aan. Alles staat in het logboek van de server.')) ?>
          <label class="switch"><input type="checkbox" name="sudo_on"><span></span> <?= t('<b>Tijdelijk sudo</b> via de agent') ?></label>
          <label><?= th('Hooguit') ?> <span class="hint"><?= th('(minuten, 5 tot 240)') ?></span> <input type="number" name="sudo_max" min="5" max="240" class="num-in"></label>
          <h3 class="set-sub"><?= th('Laatste aanvragen') ?></h3>
          <div id="sudo-list"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="patch" class="card" id="patch-card">
          <h2>🩹 <?= th('Updates installeren') ?></h2>
          <?= xp(t('Laat de agent updates installeren: met de hand, in het onderhoudsvenster van een server, of elke dag.'), t('<b>Hoe het werkt:</b> de agent (1.92.0) kijkt eens per uur welke updates er klaarstaan en welke daarvan <b>beveiligingsupdates</b> zijn. In <b>Kwetsbaarheden → Updates</b> zie je dat per server en installeer je ze met één klik. Per server kies je in de <b>Agent Manager</b>: <b>met de hand</b> (standaard), <b>in het vaste onderhoudsvenster</b> van die server, of <b>elke dag om een tijd</b>; <b>alleen beveiligingsupdates</b> (standaard) of alles; en <b>eerst een snapshot</b> (ZFS, snapper of btrfs). <b>Home Assistant</b> gaat via de Supervisor (Core en add-ons, altijd met een back-up). <b>Nooit:</b> TrueNAS (dat gaat via TrueNAS zelf), automatisch op de RzSense-server zelf, of een herstart. Alles staat in het logboek.')) ?>
          <label class="switch"><input type="checkbox" name="patch_on"><span></span> <?= t('<b>Updates installeren</b> via de agent (met de hand of automatisch, per server)') ?></label>
          <h3 class="set-sub"><?= th('Per server') ?></h3>
          <div id="patch-hosts"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="ransom" class="card" id="ransom-card">
          <h2>🧬 <?= th('Ransomware herkennen') ?></h2>
          <?= xp(t('Ziet het als iemand je bestanden aan het versleutelen is, en grijpt in als je dat wilt.'), t('<b>Hoe het werkt:</b> per server kies je in de <b>Agent Manager</b> welke mappen de agent bewaakt (hij stelt je Samba-shares, NFS-exports, de shares van TrueNAS en /home voor). Elke ronde kijkt de agent naar twee <b>lokaasbestanden</b> per map (gewijzigd, hernoemd of weg = meteen alarm), naar hoeveel bestanden er tegelijk veranderen, naar bekende <b>ransomware-extensies</b> en <b>losgeldbriefjes</b>, en naar tekstbestanden die ineens <b>versleuteld</b> zijn. RzSense leert per map wat normaal is (7 dagen). <b>Bij alarm:</b> een critical incident met een pushbericht, en (per server in te stellen) meteen een <b>snapshot</b> (ZFS of btrfs) en eventueel de schrijvende processen <b>stoppen</b>. Alles staat in het logboek van de server. <b>Agent 1.90.0</b> of nieuwer, als root.')) ?>
          <label class="switch"><input type="checkbox" name="ransom_on"><span></span> <?= t('<b>Ransomware herkennen</b> (op de servers waar je mappen kiest)') ?></label>
          <label><?= th('Heel veel tegelijk vanaf') ?> <span class="hint"><?= th('(bestanden per ronde; RzSense gebruikt het hoogste van dit getal en 4× wat normaal is)') ?></span>
            <input type="number" name="ransom_min" min="20" max="100000" class="num-in"></label>
          <h3 class="set-sub"><?= th('Per server') ?></h3>
          <div id="ransom-hosts"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="apps" class="card" id="apps-card">
          <h2>🧩 <?= th('Onbekende programma\'s') ?></h2>
          <?= xp(t('Ziet welke programma\'s er op je servers draaien, en laat je weten als er een nieuw, onbekend programma bij komt.'), t('<b>Hoe het werkt:</b> de agent meldt elk programma dat draait één keer, met een <b>vingerafdruk</b> (sha256) en het <b>pakket</b> waar het bij hoort. Wat uit een pakket komt is goed. In de <b>leerperiode</b> (na het eerste rapport) geldt wat er draait als normaal; daarna geeft een programma zonder pakket dat je niet toestond een <b>incident</b>, en de vingerafdruk gaat naar de dreigingsinformatie (bekende malware = critical). In <b>IDS → Programma\'s</b> sta je toe of blokkeer je, op vingerafdruk, pad of map, voor één of alle servers. Een geblokkeerd programma dat draait = critical incident; per server kun je de agent het laten <b>stoppen</b> (Agent Manager, met je wachtwoord). Alles staat in het logboek. <b>Agent 1.91.0</b> of nieuwer, als root; containers tellen niet mee.')) ?>
          <label class="switch"><input type="checkbox" name="appctl_on"><span></span> <?= t('<b>Onbekende programma\'s</b> bijhouden en melden') ?></label>
          <label><?= th('Leerperiode') ?> <span class="hint"><?= th('(dagen na het eerste rapport van een server; 0 = meteen melden)') ?></span>
            <input type="number" name="appctl_learn" min="0" max="90" class="num-in"></label>
          <h3 class="set-sub"><?= th('Per server') ?></h3>
          <div id="apps-hosts"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="hunt" class="card" id="hunt-card">
          <h2>🔎 <?= th('Jagen op sporen') ?></h2>
          <?= xp(t('Zoek in één keer of sporen uit een waarschuwing (IP, domein, vingerafdruk, pad) bij jou voorkomen.'), t('<b>Hoe het werkt:</b> in <b>IDS → Jagen</b> plak je sporen. RzSense zoekt ze in IDS-incidenten, SSH-logins, blokkades, inlogpogingen, aanvallen op je websites, de endpoint-bewaking, de programma\'s per server en de logs. Met <b>ook op de servers</b> zoekt elke agent (1.91.0) zelf naar de paden en vingerafdrukken (draaiende programma\'s, /tmp, /opt, /home …), hooguit 2 minuten en met lage voorrang. <b>Bewaken</b> zet sporen op een lijst: elke 10 minuten vergelijkt RzSense wat er nieuw binnenkwam, en een treffer is een critical incident. Er gaat niets naar buiten.')) ?>
          <label class="switch"><input type="checkbox" name="ioc_on"><span></span> <?= t('<b>Jagen op sporen</b> en bewaakte sporen') ?></label>
          <p class="small"><a href="#ids/hunt" data-goto="ids/hunt"><?= th('Naar IDS → Jagen') ?> →</a></p>
        </div>

        <div data-sk="eol" class="card" id="eol-card">
          <h2>⏳ <?= th('End-of-life') ?></h2>
          <?= xp(t('Besturingssysteem en software die geen updates meer krijgen, of binnenkort niet.'), t('<b>Wat het doet:</b> uit dezelfde softwarelijst als de kwetsbaarheden (dit werkt ook als Kwetsbaarheden uit staat) kijkt RzSense eens per dag bij <b>endoflife.date</b> tot wanneer een versie updates krijgt. Het overzicht staat bij 🐞 Kwetsbaarheden → ⏳ Verouderd. <b>Privacy:</b> naar endoflife.date gaan alleen productnamen (zoals debian of php), geen versies, servernamen of IP-adressen. <b>Meldingen:</b> 90 en 30 dagen vooraf en op de dag zelf, bij Systeemmeldingen → End-of-life.')) ?>
          <label class="switch"><input type="checkbox" name="eol_on"><span></span> <?= t('<b>End-of-life bijhouden</b>') ?></label>
        </div>

        <div data-sk="mailsec" class="card" id="mailsec-card">
          <h2>✉️ <?= th('E-mailbeveiliging van je domein') ?></h2>
          <?= xp(t('Kan iemand mail versturen uit naam van jouw domein? Controle van SPF, DKIM en DMARC.'), t('<b>Wat het doet:</b> bij een <b>Domein-check</b> kies je <i>Wat controleren → E-mailbeveiliging</i>. RzSense kijkt dan eens per 3 uur in DNS naar SPF, DKIM, DMARC, MTA-STS en TLS-rapporten, en geeft per punt het oordeel en het DNS-record om te plakken. <b>Toevoegen:</b> bij een server (bv. de server van RzSense) → ＋ Check → Domein. <b>Domein zonder mail?</b> Vink dat aan bij de check: dan hoort er <code>v=spf1 -all</code> en DMARC <code>p=reject</code> te staan. <b>Uit:</b> de checks gaan dan op onbekend.')) ?>
          <label class="switch"><input type="checkbox" name="mailsec_on"><span></span> <?= t('<b>E-mailbeveiliging controleren</b>') ?></label>
        </div>

        <div data-sk="cvebuzz" class="card" id="cvebuzz-card">
          <h2>🔥 <?= th('Rugbaarheid en exploits') ?></h2>
          <?= xp(t('Hoeveel aandacht krijgt een kwetsbaarheid, en wordt hij al misbruikt?'), t('<b>Wat het doet:</b> voor elke CVE op je servers telt RzSense één keer per dag (actief misbruikt of high/critical: twee keer) hoe vaak hij besproken wordt (Hacker News, Mastodon) en of er een exploit is (GitHub, Exploit-DB, een Nuclei-template, het oordeel van CISA). Dat wordt een score van 0 tot 100: de <b>rugbaarheid</b>. Passeert een CVE de grens of stijgt hij snel, dan krijg je een ℹ️-melding <i>veel aandacht</i>. <b>Naar buiten gaat alleen het CVE-nummer</b>, nooit de naam van je server of pakket. <b>Sleutels:</b> zonder GitHub-token zoekt RzSense er minder per minuut; het token heeft geen rechten nodig (een fine-grained token met alleen publieke toegang). Een Mastodon-token telt ook berichten zonder hashtag; zonder token telt RzSense de hashtag (bv. #CVE20243094).')) ?>
          <label class="switch"><input type="checkbox" name="cve_buzz_on"><span></span> <?= t('<b>Rugbaarheid bijhouden</b>') ?></label>
          <div class="row2">
            <label><?= th('Melden vanaf') ?> <span class="hint"><?= th('(rugbaarheid 0–100)') ?></span><input name="cve_buzz_alert" type="number" min="20" max="95" placeholder="60"></label>
            <label><?= th('GitHub-token') ?> <span class="hint"><?= th('(optioneel; zonder rechten)') ?></span><input name="cve_github_token" type="password" autocomplete="new-password" spellcheck="false" placeholder="github_pat_…"></label>
          </div>
          <div class="row2">
            <label><?= th('Mastodon-instantie') ?><input name="cve_mastodon_url" class="mono" spellcheck="false" placeholder="https://infosec.exchange"></label>
            <label><?= th('Mastodon-token') ?> <span class="hint"><?= th('(optioneel; alleen lezen)') ?></span><input name="cve_mastodon_token" type="password" autocomplete="new-password" spellcheck="false"></label>
          </div>
          <label class="switch"><input type="checkbox" name="cve_exploitdb"><span></span> <?= th('Exploit-DB (lijst van openbare exploits, één keer per dag)') ?></label>
          <label class="switch"><input type="checkbox" name="cve_nuclei"><span></span> <?= th('Nuclei-templates (waar scanners naar zoeken, één keer per dag)') ?></label>
          <?= xp(t('Welke adressen RzSense hiervoor gebruikt.'), t('<b>Nodig:</b> uitgaand HTTPS naar <code>api.github.com</code>, <code>hn.algolia.com</code>, <code>raw.githubusercontent.com</code> (CISA Vulnrichment en Nuclei), <code>gitlab.com</code> (Exploit-DB) en je Mastodon-instantie. <b>AI:</b> met AI-advies aan staat in de popup van een kwetsbaarheid de knop <i>Raakt dit jou echt?</i>: de AI kijkt naar je servers (staat het pakket open naar het netwerk, is er een oplossing) en zegt <i>eerst doen</i>, <i>kan wachten</i> of <i>niet van toepassing</i>.')) ?>
        </div>

        <div data-sk="netscan" class="card" id="netscan-card">
          <h2>🔎 <?= th('Netwerkscan') ?></h2>
          <?= xp(t('Zoekt alle apparaten in je thuisnetwerk, met open poorten en fabrikant.'), t('<b>Wat het doet:</b> RzSense zoekt wat er op je <b>lokale netwerken</b> zit (<i>Inloggen → Lokale netwerken</i>): elk apparaat met IP-adres, MAC-adres, fabrikant, naam, open poorten en een gok wat voor apparaat het is. <b>Waar je het ziet:</b> op het tabblad <b>Netwerk</b>. Daar geef je een apparaat een naam, markeer je het als bekend, negeer je het of voeg je het met één klik toe aan RzSense. <b>Goed om te weten:</b> alleen privé-netwerken tot /22; nooit internet.')) ?>
          <label class="switch"><input type="checkbox" name="net_scan"><span></span> <?= t('<b>Netwerkscan aan</b> (tabblad Netwerk)') ?></label>
          <div class="row2">
            <label><?= th('Snelle ronde (ping en ARP) elke') ?>
              <select name="net_sweep_minutes"><option value="15">15 min</option><option value="30">30 min</option><option value="60">1 <?= th('uur') ?></option><option value="120">2 <?= th('uur') ?></option><option value="360">6 <?= th('uur') ?></option></select></label>
            <label><?= th('Diepere scan (poorten, namen, merk)') ?>
              <select name="net_deep_per_day"><option value="1"><?= th('1× per dag') ?></option><option value="2"><?= th('2× per dag') ?></option><option value="4"><?= th('4× per dag') ?></option><option value="6"><?= th('6× per dag') ?></option><option value="12"><?= th('12× per dag') ?></option></select></label>
          </div>
          <div id="netscan-nets" class="small"></div>
          <!-- 1.186.0: verkeer per apparaat -->
          <label class="switch"><input type="checkbox" name="net_traffic"><span></span> <?= t('<b>📊 Verkeer per apparaat</b> bijhouden (UniFi, SNMP of de agent; elke 5 minuten)') ?></label>
          <?= xp(t('Hoeveel elk apparaat downloadt en uploadt, met een melding bij ongewoon veel verkeer.'), t('<b>Wat het doet:</b> RzSense leest elke 5 minuten de tellers van UniFi en houdt per apparaat bij hoeveel het downloadt en uploadt: per uur (30 dagen) en per dag (een jaar). Je ziet het op <i>Netwerk</i> (Overzicht, Apparaten en Ontdekken) en in het venster van elk apparaat. <b>Ongewoon veel:</b> na 7 dagen leren komt er in <i>Ontdekken</i> een melding als een apparaat op een dag 5× zoveel uploadt of downloadt als normaal (minstens 300 MB), of als het \'s nachts ineens verkeer heeft. <b>Bronnen:</b> het beste is UniFi met een lokaal account (<i>Koppelingen → UniFi</i>); dat ziet elk apparaat. Zonder UniFi telt RzSense via SNMP de poorten van je switches (een poort met één apparaat erop), en de netwerkkaarten van servers met agent 1.80.0 of nieuwer. Ziet UniFi een apparaat, dan gaat UniFi voor. Het verkeer tussen apparaten onderling wordt maar voor een deel gezien.')) ?>
          <label class="switch small"><input type="checkbox" name="net_traffic_swap"><span></span> <?= th('Download en upload omdraaien (als je controller het andersom telt)') ?></label>
          <label><?= th('Datalimiet van je internetabonnement') ?> <span class="muted small"><?= th('(GB per maand, download + upload; leeg = geen limiet)') ?></span>
            <input type="number" name="net_wan_cap_gb" min="1" step="1" placeholder="<?= th('geen') ?>" style="max-width:10rem"></label>
          <div id="netscan-traffic" class="small muted"></div>
          <?= xp(t('Een nieuw apparaat geeft een melding; extra programma\'s maken de scan sneller.'), t('<b>Meldingen:</b> een nieuw apparaat geeft een ℹ️-melding <i>Nieuw apparaat op je netwerk</i>, standaard alleen op de meldingenpagina (kanalen bij <i>Meldingen → Systeemmeldingen</i>). De eerste scan is de basismeting en geeft geen meldingen. <b>Sneller:</b> met <code>fping</code> en <code>avahi-browse</code> (pakket <code>avahi-tools</code> of <code>avahi-utils</code>) op de RzSense-server gaat het sneller en herkent RzSense meer, maar nodig zijn ze niet. <b>Fabrikant:</b> uit de lijst van Wireshark, maandelijks bijgewerkt (uitgaand HTTPS naar <code>www.wireshark.org</code>).')) ?>
        </div>

        <div data-sk="logs" class="card" id="logs-card">
          <h2>📜 <?= th('Logs') ?></h2>
          <?= xp(t('De agent verzamelt de logs die je per server kiest en stuurt ze gecomprimeerd en versleuteld naar RzSense.'), t('<b>Bronnen</b> kies je per server in de <b>Agent Manager</b> (kaart <i>Logs</i>): bestanden of mappen (met *), de journal, één service, een container, of de logs van Home Assistant. <b>Hoe:</b> elke run leest de agent alleen de nieuwe regels; elke paar minuten gaan ze als één pakketje (gzip, versleuteld met de sleutel van de agent) naar RzSense, los van het gewone rapport. Pas als RzSense het heeft opgeslagen, gooit de agent het pakketje weg; zonder verbinding bewaart hij ze (tot 50 MB). De logbestanden op de server zelf blijven staan. <b>Opslag:</b> per dag een tabel met gecomprimeerde blokken (ongeveer 7 à 8 keer kleiner); een dag ouder dan de bewaartermijn gaat in één keer weg, en boven het maximum de oudste dag eerst. <b>Zoeken</b> mag voor beheerders en gebruikers, niet voor alleen lezen.')) ?>
          <label class="switch"><input type="checkbox" name="logs_on"><span></span> <?= t('<b>Logs verzamelen</b> (hoofdschakelaar; de bronnen stel je per server in)') ?></label>
          <div class="row2">
            <label><?= th('Versturen elke (minuten)') ?> <span class="muted small"><?= th('(1–60; per server aan te passen)') ?></span><input type="number" name="logs_interval" min="1" max="60" step="1"></label>
            <p class="small muted"><?= th('Hoe lang logs bewaard blijven, stel je in bij Database → Opslag.') ?> <a href="#settings/db" data-goto="settings/db"><?= th('Naar Opslag') ?></a></p>
          </div>
          <div class="row2">
            <label><?= th('Maximaal in totaal (GB)') ?> <span class="muted small"><?= th('(daarboven gaat de oudste dag weg)') ?></span><input type="number" name="logs_max_gb" min="0.1" max="10000" step="0.1"></label>
            <label><?= th('Maximaal per server per dag (MB)') ?> <span class="muted small"><?= th('(ongecomprimeerd; daarna tot middernacht niets meer)') ?></span><input type="number" name="logs_day_mb" min="1" max="100000" step="1"></label>
          </div>
          <label class="switch"><input type="checkbox" name="logs_mask"><span></span> <?= t('<b>Gevoelige gegevens maskeren</b> vóór het opslaan: wachtwoorden, tokens, API-sleutels en e-mailadressen worden ••••') ?></label>
          <label><?= th('Eigen patronen om te maskeren') ?> <span class="muted small"><?= th('(één reguliere expressie per regel, bv. klantnummer=\d+)') ?></span>
            <textarea name="logs_mask_extra" rows="3" spellcheck="false" style="font-family:ui-monospace,monospace"></textarea></label>
          <h3 class="small" style="margin:1rem 0 .3rem"><?= th('Opslag en binnenkomst') ?></h3>
          <div id="logs-ov"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="internet" class="card" id="internet-card">
          <h2>🌍 <?= th('Internet') ?></h2>
          <?= xp(t('Bewaakt je internetlijn: ping, responstijd en snelheid.'), t('<b>Wat het doet:</b> RzSense bewaakt je internetlijn vanaf zijn eigen server. <b>Ping:</b> naar 1.1.1.1, 8.8.8.8 en je router, elke minuut. <b>Responstijd:</b> naar google.com en cloudflare.com, elke 5 minuten. <b>Snelheid:</b> download en upload via speed.cloudflare.com, op de tijden hieronder.')) ?>
          <div id="inet-state"><p class="muted"><?= th('Laden…') ?></p></div>
          <h3 class="small" style="margin:1rem 0 .3rem"><?= th('Abonnement en drempels') ?></h3>
          <div class="row2">
            <label><?= th('Download (Mbit/s)') ?><input type="number" name="inet_down" min="1" max="100000" step="1"></label>
            <label><?= th('Upload (Mbit/s)') ?><input type="number" name="inet_up" min="1" max="100000" step="1"></label>
          </div>
          <div class="row2">
            <label><?= th('Warning onder (% van je abonnement)') ?><input type="number" name="inet_warn_pct" min="1" max="100" step="1"></label>
            <label><?= th('Critical onder (% van je abonnement)') ?><input type="number" name="inet_crit_pct" min="1" max="100" step="1"></label>
          </div>
          <h3 class="small" style="margin:1rem 0 .3rem"><?= th('Snelheidstest') ?></h3>
          <div class="row2">
            <label><?= th('Tijden') ?> <span class="hint"><?= th("(UU:MM, gescheiden door komma's)") ?></span><input name="inet_times" placeholder="03:30,09:30,13:30,17:30"></label>
            <label><?= th('Hooguit per richting (MB)') ?><input type="number" name="inet_speed_mb" min="10" max="2000" step="10"></label>
          </div>
          <?= xp(t('Een snelheidstest belast je lijn even; plan hem dus niet op drukke momenten.'), t('Elke test trekt je lijn een paar seconden vol; plan hem dus niet in de avond. Een test stopt na 10 seconden of na het aantal MB hierboven per richting (bij 1 Gbit/s ongeveer 2× 250 MB). <b>Goed om te weten:</b> ping en responstijd leren mee met de slimme drempels; de snelheid vergelijkt RzSense met je abonnement.')) ?>
        </div>

        <div data-sk="smart" class="card" id="smart-card">
          <h2>🧠 <?= th('Slimme drempels') ?></h2>
          <?= xp(t('RzSense leert wat normaal is per check en zet de drempels daar zelf op.'), t('<b>Wat het doet:</b> RzSense leert per check wat normaal is, per uur van de week (maandag 3:00 is anders dan dinsdag 14:00), en zet de drempels daarop. Dat begint na 7 dagen gegevens; daarna stelt RzSense ze elke nacht bij, hooguit 10% per nacht. <b>Terugdraaien:</b> elke aanpassing staat in het <i>Wijzigingslogboek</i>. <b>Werkt bij:</b> ping, TCP, HTTP, CPU, RAM, SNMP (CPU, geheugen, interface, load, temperatuur, UPS, UniFi), containers (CPU of RAM als losse check), DNS, NTP en MySQL. Disks houden vaste drempels, met de voorspelling wanneer ze vol raken.')) ?>
          <div class="row2">
            <label><?= th('Nieuwe checks') ?>
              <select name="thresh_default"><option value="guard">🧠🛡️ <?= th('Slim met vangnet') ?></option><option value="smart">🧠 <?= th('Slim') ?></option><option value="manual">✋ <?= th('Handmatig') ?></option></select></label>
            <label><?= th('Gevoeligheid') ?>
              <select name="smart_sens"><option value="calm"><?= th('rustig') ?></option><option value="normal"><?= th('normaal') ?></option><option value="sharp"><?= th('scherp') ?></option></select></label>
          </div>
          <?= xp(t('Slim met vangnet, rustig of scherp: hoe gevoelig de slimme drempels zijn.'), t('<b>Slim met vangnet:</b> geleerd, maar vanaf een harde grens altijd CRITICAL (bijvoorbeeld RAM 98%). <b>Rustig</b> geeft minder meldingen, <b>scherp</b> ziet een afwijking eerder. Per check aan te passen (✎). <b>Sluipende stijging:</b> stijgt de normaalwaarde van een check langzaam (een lek?), dan krijg je een ℹ️-melding en schuiven de drempels niet mee omhoog.')) ?>
          <label class="switch"><input type="checkbox" name="smart_auto"><span></span> <span><?= t('<b>Zelf omzetten</b>: een handmatige check gaat na 7 dagen leren vanzelf naar <i>slim met vangnet</i> als dat minstens 2 dagen minder warnings had gegeven') ?></span></label>
          <?= xp(t('Gebeurt één keer per check, met een melding en terug te draaien.'), t('Eén keer per check, met een ℹ️-melding (<i>Meldingen → Systeemmeldingen → Drempels zelf aangepast</i>) en een regel in het <i>Wijzigingslogboek</i>. Zet je hem daar terug, dan blijft de check handmatig.')) ?>
          <h3 class="small" style="margin:1rem 0 .3rem"><?= th('Voorstel voor bestaande checks') ?></h3>
          <?= xp(t('Voor bestaande checks rekent RzSense uit hoeveel meldingen slim had gescheeld.'), t('Bestaande checks blijven handmatig tot je ze omzet. RzSense leert ook voor die checks en rekent na <b>op hoeveel van de afgelopen dagen er een warning was geweest</b>, met de drempels van nu en met slimme drempels. Een dag telt net als een echte melding: pas als er zoveel metingen <b>achter elkaar</b> te hoog waren als de check vraagt (<i>melden na N</i>).')) ?>
          <?= xp(t('Zo lees je de voorstellen: minder dagen, grijs en grillig.'), t('<b>Minder dagen met slim</b> = minder onnodige meldingen; die checks staan al aangevinkt. <b>Grijs</b> = slim levert daar niets op; de reden staat erachter. <b>Grillig</b> = de check piekt bijna elke dag even (bijvoorbeeld de webinterface van een camera); daar helpt <i>melden na 3</i> meer dan slimme drempels.')) ?>
          <div id="smart-proposals"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="smartmore" class="card" id="smartmore-card">
          <h2>🧠 <?= th('Slim: overige instellingen') ?></h2>
          <?= xp(t('Sneller checken bij een probleem, trager als alles goed is, en een melding bij een disk die hard groeit.'), t('<b>Wat het doet:</b> met <i>slim interval</i> controleert RzSense een check die twijfelt veel vaker, en een check die al lang goed is minder vaak. Zo zie je een storing sneller en is RzSense zuiniger. <b>Zo stel je het in:</b> 20 seconden bij een probleem en hooguit 2× het interval bij rust is een goede start. <b>Goed om te weten:</b> een check wordt nooit trager dan eens per uur, en een check met een eigen interval houdt zijn ritme.')) ?>
          <h3 class="small" style="margin:.3rem 0">⏱️ <?= th('Slim interval') ?></h3>
          <label class="switch"><input type="checkbox" name="smart_interval"><span></span> <?= t('<b>Slim interval</b>: bij een probleem sneller checken, als alles al een uur goed is trager') ?></label>
          <div class="row2">
            <label><?= th('Bij een probleem elke … seconden') ?> <span class="muted small"><?= th('(ping, TCP, HTTP, SNMP)') ?></span><input type="number" name="smart_fast" min="10" max="300"></label>
            <label><?= th('Stabiel: hooguit … × het interval') ?> <span class="muted small"><?= th('(nooit trager dan een uur)') ?></span><input type="number" name="smart_slow" min="1" max="10"></label>
          </div>
          <?= xp(t('Bij een probleem vaker checken, en minder vaak als alles goed gaat.'), t('Sneller checken bij een probleem laat je eerder weten of een storing echt is, en wanneer hij voorbij is. Trager checken als alles stabiel is, ontlast je servers. <b>Goed om te weten:</b> checks van de agent volgen het rapport van de agent.')) ?>
          <?= xp(t('Melden na N blijft hetzelfde; sneller checken geeft geen extra meldingen.'), t('<i>Melden na N</i> blijft gelijk in tijd: bij een check op <i>melden na 3</i> met een interval van 1 minuut gaat de melding pas uit als het <b>2 minuten</b> mis is, net als zonder slim interval. Een korte hapering wordt dus niet alsnog een melding; wel weet RzSense sneller dat het weer goed is.')) ?>
          <h3 class="small" style="margin:1rem 0 .3rem">📈 <?= th('Afwijkingen en hints') ?></h3>
          <label class="switch"><input type="checkbox" name="anomaly_on"><span></span> <?= t('<b>Afwijkingen</b> bij checks zonder drempels (verkeer, clients, processen, logregels): ℹ️ als het flink anders is dan normaal op dat uur') ?></label>
          <label class="switch"><input type="checkbox" name="disk_jump_on"><span></span> <?= t('<b>Disk groeit hard</b>: ℹ️ als een disk in een uur zoveel procentpunt groeit:') ?> <input type="number" name="disk_jump_pct" min="1" max="50" style="width:4.5rem;display:inline-block"></label>
          <label class="switch"><input type="checkbox" name="context_on"><span></span> <?= t('<b>Oorzaak erbij</b>: in de melding wat er vlak ervoor gebeurde (update, herstart, wijziging)') ?></label>
          <label class="switch"><input type="checkbox" name="hb_learn"><span></span> <?= t('<b>Heartbeats leren hun ritme</b>: warning als hij later is dan normaal, of de meegestuurde waarde veel kleiner of groter') ?></label>
          <p class="muted small"><?= th('Afwijkingen en "disk groeit hard" staan bij Meldingen → Systeeminfo; standaard alleen op de meldingenpagina.') ?></p>
        </div>

        <div data-sk="confirmpw" class="card" id="confirmpw-card">
          <h2>🔐 <?= th('Bevestigen bij acties') ?></h2>
          <?= xp(t('Per rol: bij acties een vinkje in plaats van je wachtwoord.'), t('Bij acties zoals herstellen, installeren, isoleren, blokkeren, bijwerken, terugzetten en verwijderen vraagt RzSense om je wachtwoord. Staat de schakelaar voor een rol aan, dan is een vinkje <i>Ik weet het zeker</i> genoeg, <b>zolang je je wachtwoord de laatste minuten hieronder hebt ingevuld</b> (bij het inloggen of bij een eerdere bevestiging). Daarna vraagt RzSense het één keer opnieuw. Op het lokale netwerk (automatisch ingelogd) vervangt het vinkje het woord dat je typt. <b>Altijd met je wachtwoord:</b> passkeys, sleutels, back-ups en certificaten downloaden, het databasewachtwoord en iedereen uitloggen.')) ?>
          <label class="switch"><input type="checkbox" name="confirm_skip_admin"><span></span> <?= th('🔑 Beheerder: vinkje in plaats van wachtwoord') ?></label>
          <label class="switch"><input type="checkbox" name="confirm_skip_user"><span></span> <?= th('👤 Gebruiker: vinkje in plaats van wachtwoord') ?></label>
          <label class="switch"><input type="checkbox" name="confirm_skip_readonly"><span></span> <?= th('👁 Alleen lezen: vinkje in plaats van wachtwoord') ?></label>
          <p class="muted small"><?= th('Alleen lezen heeft geen knoppen voor acties; de schakelaar telt pas als dat ooit verandert.') ?></p>
          <label class="update-hold"><?= th('Vinkje geldig tot') ?> <input type="number" name="confirm_grace_min" min="1" max="1440" step="1" style="width:5.5rem"> <?= th('minuten na het invullen van je wachtwoord') ?></label>
        </div>

        <div data-sk="update" class="card" id="update-card">
          <h2><?= th('⬆ RzSense bijwerken') ?></h2>
          <?= xp(t('Een nieuwe versie van RzSense installeren, met een back-up vooraf.'), t('<b>Wat het doet:</b> RzSense zoekt zelf naar een nieuwe versie en laat zien wat er verandert. Met <i>Nu bijwerken</i> maakt het eerst een back-up en installeert het dan de nieuwe versie; de database wordt vanzelf bijgewerkt. <b>Zo stel je het in:</b> kies of je zelf bijwerkt of automatisch, en hoe lang agents hooguit wachten tijdens de update. <b>Goed om te weten:</b> je instellingen en <code>config.php</code> blijven gelijk. Agents krijgen hun nieuwe versie via RzSense.')) ?>
          <p class="small" id="update-status">…</p>
          <div id="update-notes"></div>
          <div id="update-progress" hidden></div>
          <div class="update-actions">
            <button type="button" class="btn primary" id="update-start" hidden><?= th('⬆ Nu bijwerken') ?></button>
            <button type="button" class="btn" id="update-check"><?= th('🔍 Nu controleren') ?></button>
            <button type="button" class="btn" id="update-pick" hidden><?= th('🔁 Andere versie…') ?></button>
          </div>
          <label class="switch" style="margin-top:.9rem"><input type="checkbox" name="update_backup"><span></span> <?= th('Eerst een back-up maken') ?></label>
          <?= xp(t('Maakt eerst een back-up, voor de knop en voor automatisch bijwerken.'), t('Een back-up van de database en de instellingen, die je terugvindt in de lijst bij <i>Back-up</i>. Dit geldt voor de knop en voor automatisch bijwerken.')) ?>
          <label class="switch"><input type="checkbox" name="update_auto"><span></span> <?= th('Automatisch bijwerken') ?></label>
          <label class="update-time"><?= th('Elke dag om') ?> <input type="time" name="update_time"> <?= th('als er een nieuwe versie is') ?></label>
          <?= xp(t('RzSense kijkt dagelijks of er een nieuwe versie is en werkt zichzelf bij.'), t('<b>Wat het doet:</b> RzSense kijkt één keer per dag op GitHub of er een nieuwe versie is. Bijwerken doet de hulpdienst op de server: eventueel eerst een back-up, dan de nieuwe versie ophalen en het installatiescript uitvoeren, net als het commando met <code>install-rocky.sh</code>. <b>Goed om te weten:</b> je instellingen en gegevens blijven staan. De agents werk je apart bij (hieronder).')) ?>
          <label class="update-hold"><?= th('Agents wachten tijdens een update hooguit') ?> <input type="number" name="update_hold_min" min="5" max="60" step="1" style="width:5rem"> <?= th('minuten') ?></label>
          <?= xp(t('Tijdens een update houden de agents hun metingen vast en geven ze geen alarm, tot RzSense terug is.'), t('Bij <b>Bijwerken</b> waarschuwt RzSense eerst de agents (vanaf agent 1.82.0, versleuteld). Lukt een rapport dan niet, dan zet de agent zijn meting in de wachtrij en wacht hij op de vrijgave; daarna stuurt hij alles na. Is RzSense na deze tijd nog weg, dan geeft de waakhond alsnog alarm (<i>RzSense onbereikbaar tijdens update</i>). Na afloop staat er een samenvatting bij Meldingen (ℹ️ Systeeminfo). Standaard 5 minuten, 5–60.')) ?>
        </div>

        <div data-sk="agents" class="card" id="agents-card">
          <h2>🖥️ <?= th('Agents') ?></h2>
          <div data-nodirty id="enc-hosts"></div>
          <h3 class="set-sub">⬆ <?= th('Bijwerken') ?></h3>
          <?= xp(t('Werk de agents vanaf hier bij, per server of allemaal tegelijk.'), t('Komt er een nieuwe agent, dan werk je die vanaf hier bij: per server in de <i>Agent Manager</i>, of allemaal tegelijk. De agent haalt de nieuwe versie bij RzSense op, controleert de handtekening en installeert hem zelf; zijn instellingen blijven staan. <b>Goed om te weten:</b> de eerste installatie gaat met het commando op de server.')) ?>
          <p class="small" id="agents-status">…</p>
          <button type="button" class="btn" id="agents-update-all" hidden><?= th('⬆ Alle verouderde agents bijwerken') ?></button>
        </div>

        <div data-sk="agentdef" class="card" id="agentdef-card">
          <h2><?= th('Standaardinstellingen') ?></h2>
          <?= xp(t('Hoe agents standaard werken: bijwerken, hoe vaak ze rapporteren en wanneer ze offline zijn.'), t('<b>Wat het doet:</b> deze waarden gelden voor elke agent, tenzij je per server iets anders kiest in de Agent Manager. <b>Zo stel je het in:</b> laat agents automatisch bijwerken en rapporteren elke 60 seconden. Offline na 5 minuten zonder rapport is gebruikelijk. <b>Goed om te weten:</b> 30 seconden werkt alleen met de systemd-timer; via cron of op TrueNAS minimaal elke minuut.')) ?>
          <label class="switch"><input type="checkbox" name="agent_auto_update"><span></span> <?= th('Agents automatisch bijwerken') ?></label>
          <label style="margin-top:.9rem"><?= th('⏱ Agents rapporteren standaard elke') ?>
            <select name="agent_tick"><option value="30"><?= th('30 seconden') ?></option><option value="60"><?= th('60 seconden') ?></option><option value="120"><?= th('2 minuten') ?></option><option value="300"><?= th('5 minuten') ?></option></select>
            <span class="muted small"><?= t('Per server anders in te stellen in de <i>Agent Manager</i>. 30 s werkt met de systemd-timer (agent 1.41.0+); via cron of op TrueNAS minimaal elke minuut.') ?></span></label>
          <label><?= th('Agent offline na … minuten zonder rapport') ?><input type="number" name="agent_stale_minutes" min="1"></label>
          <label><?= th('🔒 Ook zoeken naar certificaten in') ?> <span class="muted small"><?= th('(optioneel)') ?></span>
            <textarea name="cert_dirs" rows="2" class="mono" placeholder="/data/certs /home/app/ssl"></textarea>
            <span class="muted small"><?= t('De agent (1.59.0+) zoekt eens per dag naar certificaatbestanden (.crt, .pem, .cer) in de bekende mappen (/etc/ssl, /etc/pki, /etc/letsencrypt, /etc/nginx, /opt, /srv …). Zet hier extra mappen, gescheiden door spaties; ze gelden voor alle agents. Per server kan ook <code>CERT_DIRS</code> in <code>/etc/default/rzmon-agent</code>.') ?></span></label>
          <?= xp(t('Verouderde agents werken zichzelf bij; een server uitzonderen kan.'), t('Verouderde agents werken zichzelf bij zonder dat je op de knop drukt (hooguit één poging per 6 uur). <b>Home Assistant:</b> de add-on werk je bij via de add-on winkel. <b>Een server uitzonderen:</b> zet <code>REMOTE_UPDATE=0</code> in <code>/etc/default/rzmon-agent</code>.')) ?>
        </div>

        <div data-sk="commands" class="card" id="commands-card">
          <h2><?= th('🖥️ Commando-checks') ?></h2>
          <div class="cmd-box" data-nodirty>
            <?= xp(t('Laat agents een eigen commando uitvoeren en controleer de uitvoer.'), t('<b>Wat het doet:</b> met een <i>Commando</i>-check voert de agent een commando uit en vergelijkt RzSense de uitvoer met het antwoord dat je verwacht. <b>Veiligheid:</b> standaard staat dit uit. Aanzetten vraagt om je wachtwoord en komt in het logboek. Op een server zelf blokkeer je het altijd met <code>COMMANDS=0</code> (of <code>COMMANDS=nobody</code>) in de instellingen van de agent.')) ?>
            <label><?= th('Commando\'s op agents') ?>
              <select id="agent-commands"><option value="off"><?= th('Uit') ?></option><option value="nobody"><?= th('Toestaan, als gebruiker nobody') ?></option><option value="root"><?= th('Toestaan, als root') ?></option></select></label>
            <p class="small" id="agent-commands-hosts"></p>
            <details class="small api-docs"><summary><?= th('Logboek') ?></summary><div id="audit-log"></div></details>
          </div>
        </div>

        <div data-sk="backup" class="card" id="backup-card">
          <h2><?= th('💾 Back-up') ?></h2>
          <?= xp(t('Automatische back-ups van de hele database en config.php.'), t('<b>Wat het is:</b> een zip met de hele database (servers, checks, instellingen, gebruikers) en <code>config.php</code>. <b>Waar:</b> de back-ups staan op deze server, buiten de webmap. <b>Goed om te weten:</b> download er af en toe een, zodat je ook een kopie hebt als de server zelf stuk gaat.')) ?>
          <div class="row3">
            <label><?= th('Automatisch') ?>
              <select name="backup_freq">
                <option value="off"><?= th('Uit') ?></option><option value="daily"><?= th('Elke dag') ?></option>
                <option value="weekly"><?= th('Elke week') ?></option><option value="monthly"><?= th('Elke maand (de 1e)') ?></option>
              </select>
            </label>
            <label><?= th('Om') ?><input type="time" name="backup_time"></label>
            <label><?= th('Bewaren') ?> <span class="hint"><?= th('(aantal)') ?></span><input type="number" name="backup_keep" min="1" max="365"></label>
            <label><?= th('Handmatige bewaren') ?> <span class="hint"><?= th('(aantal, 0 = alles)') ?></span><input type="number" name="backup_keep_manual" min="0" max="365"></label>
          </div>
          <label class="backup-weekday"><?= th('Op') ?>
            <select name="backup_weekday">
              <option value="1"><?= th('maandag') ?></option><option value="2"><?= th('dinsdag') ?></option><option value="3"><?= th('woensdag') ?></option><option value="4"><?= th('donderdag') ?></option>
              <option value="5"><?= th('vrijdag') ?></option><option value="6"><?= th('zaterdag') ?></option><option value="7"><?= th('zondag') ?></option>
            </select>
          </label>
          <label class="switch"><input type="checkbox" name="backup_history"><span></span> <?= th('Meethistorie meenemen') ?></label>
          <?= xp(t('Ook de meetgeschiedenis meenemen; uit geeft een veel kleinere zip.'), t('De meetresultaten achter de grafieken. Uit = een veel kleinere zip; bij terugzetten blijft de huidige geschiedenis dan staan.')) ?>
          <label><?= th('Zip-wachtwoord') ?> <span class="hint"><?= th('(minstens 8 tekens; versleutelt met AES-256)') ?></span><input name="backup_pass" type="password" autocomplete="new-password"></label>
          <?= xp(t('Bewaar dit wachtwoord goed: zonder is de back-up onbruikbaar.'), t('Het wachtwoord is nodig om een zip te openen (met 7-Zip) of terug te zetten op een andere server. <b>Bewaar het goed:</b> zonder wachtwoord is de back-up onbruikbaar. Wijzigen geldt voor nieuwe back-ups.')) ?>
          <p class="small" id="backup-status">…</p>
          <div class="btn-row">
            <button type="button" class="btn" id="backup-now"><?= th('💾 Nu back-up maken') ?></button>
            <button type="button" class="btn" id="backup-upload"><?= th('⬆ Zip uploaden') ?></button>
            <input type="file" id="backup-file" accept=".zip,application/zip" hidden>
          </div>
          <div id="backup-list" class="key-list"></div>
        </div>

        <div data-sk="storage" class="card" id="storage-card">
          <h2>🗄️ <?= th('Opslag') ?></h2>
          <?= xp(t('Hoe lang RzSense metingen, meldingen en logs bewaart, en hoe groot de database wordt.'), t('<b>Wat het doet:</b> elke meting wordt een tijd los bewaard, daarna als uurgemiddelde en tenslotte per dag. Zo blijven grafieken over een lange periode mogelijk zonder dat de database blijft groeien. <b>Zo stel je het in:</b> 30 dagen per meting, 180 dagen per uur en 2 jaar per dag past bij de meeste installaties. Onder de velden zie je hoe hard de database groeit. <b>Goed om te weten:</b> korter bewaren maakt de database kleiner en sneller; oude gegevens zijn daarna echt weg.')) ?>
          <label><?= th('Elke meting bewaren (dagen)') ?> <span class="hint"><?= th('(daarna per uur)') ?></span><input type="number" name="retention_days" min="1" max="3650"></label>
          <label><?= th('Uurgemiddelden bewaren (dagen)') ?> <span class="hint"><?= th('(grafieken tot een half jaar terug)') ?></span><input type="number" name="hourly_days" min="7" max="3650"></label>
          <label><?= th('Daggegevens bewaren (dagen)') ?> <span class="hint"><?= th('(Analyse en Dashboard)') ?></span><input type="number" name="daily_days" min="30" max="3650"></label>
          <p class="muted small" id="storage-growth"></p>
          <label><?= th('Meldingen bewaren (dagen)') ?> <span class="muted small"><?= th('(de pagina Meldingen toont ze per dag)') ?></span><input type="number" name="alerts_days" min="1" max="3650"></label>
          <label><?= th('Logs van je servers bewaren (dagen)') ?> <span class="muted small"><?= th('(Logs)') ?></span><input type="number" name="logs_days" min="1" max="365" step="1"></label>
          <p class="small muted"><?= th('Het Logboek (acties en wijzigingen) heeft een eigen termijn, omdat het je bewijs is van wat er gebeurde:') ?> <a href="#settings/actlog" data-goto="settings/actlog"><?= th('Logboek') ?> →</a></p>
        </div>

      </form>

      <div class="settings-grid" style="margin-top:1.25rem" data-pgrid="set">
        <div data-sk="ids_rules" class="card">
          <h2><?= th('Regels en uitzonderingen') ?></h2>
          <?= xp(t('Regels aan of uit zetten, en vastleggen wat op je servers normaal is.'), t('<b>Regels:</b> de eigen regels van RzSense en de Linux-regels van SigmaHQ. Een regel uitzetten geldt voor alle servers. <b>Uitzonderingen:</b> wat in een bepaalde situatie normaal is (een programma, gebruiker, pad, IP-adres …), per server, groep of overal. Dat geeft geen melding, RzSense grijpt niet in en het telt niet mee voor afwijkingen; in <i>IDS → Live</i> zie je het als groene regel. <b>Goed om te weten:</b> met één knop zet je een hele set aan (bijvoorbeeld systeemonderhoud of back-up), en RzSense stelt uitzonderingen voor op basis van je eigen meldingen. Op de IDS-pagina zie je de regels en hun treffers.')) ?>
          <div class="dash-bar"><div class="seg" id="rule-src-set"><button type="button" class="seg-btn active" data-rs=""><?= th('Alle') ?></button><button type="button" class="seg-btn" data-rs="rzmon">RzSense</button><button type="button" class="seg-btn" data-rs="sigma">Sigma</button><button type="button" class="seg-btn" data-rs="exc">🛡️ <?= th('Uitzonderingen') ?></button></div>
            <input id="rule-q-set" type="search" placeholder="<?= th('Zoeken: naam, techniek (T1059) …') ?>" autocomplete="off"></div>
          <div id="edr-rules-set"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>
      <form data-sk="access" class="card need-admin" id="access-form">
        <h2><?= th('Toegang') ?></h2>
        <?= xp(t('Wie er binnenkomt: je lokale netwerken, de proxy\'s die je vertrouwt en de blokkade na mislukte logins.'), t('<b>Wat het doet:</b> hier bepaal je welk netwerk als thuis telt (geen of een lichtere login), welke reverse proxy het echte IP-adres mag doorgeven, en wanneer een account of IP-adres na mislukte pogingen wordt geblokkeerd. <b>Zo stel je het in:</b> vul je thuisnetwerk(en) in, bv. <code>192.168.1.0/24</code>, en het adres van je proxy (SWAG, NPM). Laat de inlogbeveiliging aan. <b>Goed om te weten:</b> <i>Lokale netwerken</i> worden ook elders gebruikt (bv. REST API → Alleen vanaf, IDS) en veranderen daar vanzelf mee.')) ?>
        <div class="ip-status" id="ip-status">…</div>
        <label class="switch"><input type="checkbox" name="lan_no_login"><span></span> <?= th('Geen login nodig op het lokale netwerk') ?></label>
        <label class="switch"><input type="checkbox" name="lan_as_admin"><span></span> <?= t('Op het lokale netwerk als <b>beheerder</b> inloggen') ?></label>
        <?= xp(t('Aan: thuis ben je meteen beheerder. Uit: thuis eerst alleen lezen.'), t('<b>Aan:</b> op je lokale netwerk kom je zonder wachtwoord binnen als beheerder. <b>Uit:</b> op het lokale netwerk kom je binnen als <b>alleen lezen</b>; met <i>Inloggen</i> rechtsboven krijg je de rechten van je eigen account. <b>Goed om te weten:</b> van buitenaf is altijd een gebruikersnaam en wachtwoord nodig.')) ?>
        <label><?= th('Lokale netwerken') ?> <span class="hint"><?= th('(één per regel, bv. 192.168.1.0/24)') ?></span>
          <textarea name="trusted_networks" rows="5" spellcheck="false"></textarea></label>
        <details class="adv"><summary><?= th('Geavanceerd: reverse proxy') ?></summary>
        <label><?= th('Reverse proxy IP\'s') ?> <span class="hint"><?= th('(optioneel — alleen invullen als RzSense achter Nginx Proxy Manager, Traefik, Cloudflare tunnel e.d. staat)') ?></span>
          <textarea name="trusted_proxies" rows="2" spellcheck="false"></textarea></label>
        <p class="callout"><?= t('⚠️ Staat RzSense achter een reverse proxy of port-forward waarbij álle bezoekers het IP van je router/proxy krijgen? Vul dat proxy-IP dan in bij <i>Reverse proxy IP\'s</i> en zet het <b>niet</b> bij lokale netwerken — anders kan iedereen van buitenaf zonder wachtwoord binnen.') ?></p>
        </details>
        <h3 class="set-sub">⏱️ <?= th('Uitloggen') ?></h3>
        <div class="lock-row"><?= th('Uitloggen na') ?> <select name="session_idle"></select> <?= th('zonder activiteit') ?></div>
        <?= xp(t('Wanneer je automatisch wordt uitgelogd, en hoe Onthoud mij werkt.'), t('Zolang RzSense open en zichtbaar is, blijf je ingelogd. Ligt je telefoon in je zak of staat de pagina op de achtergrond, dan telt de tijd. <b>Onthoud mij op dit apparaat:</b> daarmee log je daarna vanzelf weer in (30 dagen). Van buiten je netwerk werkt dat alleen als het apparaat met 2FA is onthouden.')) ?>
        <h3 class="set-sub">🔐 <?= th('Inlogbeveiliging') ?></h3>
        <div class="lock-row"><?= th('Na') ?> <input type="number" name="login_lock_fails" min="1" max="50" class="num-in"> <?= th('mislukte pogingen binnen') ?>
          <input type="number" name="login_lock_window" min="1" max="1440" class="num-in"> <?= th('minuten:') ?></div>
        <label class="switch"><input type="checkbox" name="login_lock_account"><span></span> <span><?= t('het <b>account</b> blokkeren') ?></span></label>
        <label class="switch"><input type="checkbox" name="login_lock_ip"><span></span> <span><?= t('het <b>IP-adres</b> blokkeren (niet als het uit je lokale netwerken komt)') ?></span></label>
        <div class="lock-row"><?= th('Blokkade opheffen na') ?> <input type="number" name="login_lock_minutes" min="0" max="525600" class="num-in"> <?= th('minuten') ?>
          <span class="hint"><?= th('(0 = tot een beheerder deblokkeert)') ?></span></div>
        <?= xp(t('Blokkeert een account of IP-adres na te veel mislukte inlogpogingen.'), t('<b>Wat het doet:</b> na te veel mislukte pogingen blokkeert RzSense het account of het IP-adres. Een verkeerde 2FA-code telt ook. Pogingen tellen apart per account (vanaf welk IP dan ook) en per IP-adres. Je krijgt een ⚠️-melding via Pushover. <b>Goed om te weten:</b> een geblokkeerd account kan vanaf je <b>lokale netwerk</b> gewoon inloggen, zodat niemand van buiten je kan buitensluiten. <b>Deblokkeren:</b> bij <i>Gebruikers</i> (🔓) en bij <i>IDS → Blokkades</i>, of op de server met <code>sudo php install/install.php --unlock &lt;gebruiker&gt;</code>.')) ?>
        <p class="form-error" hidden></p>
      </form>

      <div data-sk="https" class="card need-admin" id="https-card">
        <h2>🔒 <?= th('Beheer via https') ?></h2>
        <?= xp(t('Beheer RzSense van buiten alleen via een veilige https-verbinding.'), t('<b>Waarom:</b> open je RzSense via http, dan kan iemand op het netwerk je wachtwoord, je sessie en de pagina zelf (bijvoorbeeld een installatiecommando) zien of veranderen. <b>Wat het doet:</b> met een <b>beheeradres</b> via je reverse proxy (bijvoorbeeld SWAG met Let\'s Encrypt) en <i>Beheer alleen via https</i> gaat de webinterface altijd via https. <b>Goed om te weten:</b> agents en de REST API blijven gewoon werken: hun berichten zijn al versleuteld of hebben een eigen sleutel.')) ?>
        <div id="https-now" class="https-now"></div>
        <label><?= th('Beheeradres') ?> <span class="hint"><?= th('(https://… via je reverse proxy, bv. https://rzmon.jouwdomein.nl)') ?></span>
          <span class="inline-row"><input id="https-url" type="url" placeholder="https://rzmon.jouwdomein.nl" spellcheck="false"><button type="button" class="btn" id="https-check">🔍 <?= th('Nu controleren') ?></button></span></label>
        <div id="https-cert"></div>
        <h3 class="set-sub">🔐 <?= th('Afdwingen') ?></h3>
        <label class="switch"><input type="checkbox" id="https-only"><span></span> <span><?= t('<b>Beheer alleen via https</b>: http gaat naar het beheeradres, met HSTS') ?></span></label>
        <p class="muted small" id="https-hsts" style="margin-top:-.3rem"></p>
        <label class="switch"><input type="checkbox" id="https-block"><span></span> <span><?= t('<b>Inloggen via http weigeren</b> (op de inlogpagina via http staat dan een rode balk met de link naar https)') ?></span></label>
        <label class="switch"><input type="checkbox" id="https-mfa"><span></span> <span><?= t('<b>2FA verplicht</b> van buiten je interne netwerken (Toegang → Lokale netwerken)') ?></span></label>
        <p class="muted small" id="https-mfa-info" style="margin-top:-.3rem"></p>
        <details class="adv"><summary><?= th('Zo zet je het op: SWAG en een DNS-rewrite') ?></summary>
          <ol class="https-steps small">
            <li><?= t('<b>SWAG</b>: maak <code>nginx/proxy-confs/rzmon.subdomain.conf</code> (voorbeeld hieronder) en herstart SWAG. SWAG vraagt het Let\'s Encrypt-certificaat zelf aan en vernieuwt het.') ?></li>
            <li><?= t('<b>Vertrouwde proxy</b>: zet het LAN-IP van SWAG bij Toegang → Geavanceerd → Reverse proxy IP\'s. Dan ziet RzSense het echte IP-adres van je bezoekers en dat ze via https komen.') ?></li>
            <li><?= t('<b>DNS-rewrite</b> (AdGuard Home → Filters → DNS-herschrijvingen): <code>rzmon.jouwdomein.nl</code> → het LAN-IP van SWAG. Thuis en onderweg gebruik je dan hetzelfde adres met hetzelfde geldige certificaat. Gebruikt de RzSense-server zelf ook AdGuard als DNS, dan kan hij het certificaat controleren.') ?></li>
            <li><?= t('Open RzSense via het beheeradres, vul het hierboven in en zet <i>Beheer alleen via https</i> aan.') ?></li>
          </ol>
          <pre class="mono small https-conf" id="https-conf"></pre>
          <?= xp(t('Nooddeur: via het IP-adres van RzSense kom je thuis altijd binnen.'), t('<b>Nooddeur:</b> https op het IP-adres van RzSense zelf (met een eigen certificaat; kies in je browser <i>toch doorgaan</i>) blijft vanaf je interne netwerken altijd werken, ook als het certificaat van het beheeradres verlopen is. <b>Buitengesloten?</b> Op de server: <code>sudo php install/install.php --https-off</code>.')) ?>
        </details>
        <p class="form-error" hidden></p>
      </div>

      <div data-sk="hibp" class="card need-admin" id="hibp-card">
        <h2>🔓 <?= th('Datalekken (Have I Been Pwned)') ?></h2>
        <?= xp(t('Controleert of wachtwoorden en e-mailadressen in bekende datalekken staan.'), t('<b>Wachtwoorden:</b> gratis, en je wachtwoord gaat nooit de deur uit: alleen de eerste 5 tekens van de SHA-1-vingerafdruk, met opvulling (dienst: Have I Been Pwned). <b>E-mailadressen:</b> met een API-sleutel van <a href="https://haveibeenpwned.com/API/Key" target="_blank" rel="noopener">haveibeenpwned.com</a>, elke dag. <b>Goed om te weten:</b> per account uit te zetten bij <i>Gebruikers</i> (🔓).')) ?>
        <label class="switch"><input type="checkbox" id="hibp-on"><span></span> <b><?= th('Controle op datalekken') ?></b></label>
        <div id="hibp-body">
          <h3 class="set-sub">🔑 <?= th('Wachtwoorden') ?></h3>
          <div class="lock-row"><?= th('Gelekt vanaf') ?> <input type="number" id="hibp-threshold" min="1" max="1000000" class="num-in"> <?= th('keer in datalekken') ?>
            <span class="hint"><?= th('(1 = elke treffer)') ?></span></div>
          <?= xp(t('Een gelekt wachtwoord wordt geweigerd; wie het al gebruikt, moet een nieuw kiezen.'), t('Kies je een wachtwoord dat in een datalek staat, dan krijg je een waarschuwing. Wie inlogt met een gelekt wachtwoord, moet eerst een nieuw kiezen; een gelekt wachtwoord wordt daar geweigerd.')) ?>
          <h3 class="set-sub">✉️ <?= th('E-mailadressen') ?></h3>
          <label><?= th('API-sleutel') ?> <span class="hint" id="hibp-key-state"></span>
            <span class="inline-row"><input type="password" id="hibp-key" autocomplete="off" spellcheck="false" placeholder="<?= th('niet gewijzigd') ?>"><button type="button" class="btn" id="hibp-test">🧪 <?= th('Testen') ?></button></span></label>
          <p class="small" id="hibp-test-out"></p>
          <label><?= th('Extra e-mailadressen') ?> <span class="hint"><?= th('(één per regel; de adressen van gebruikers met de controle aan gaan vanzelf mee)') ?></span>
            <textarea id="hibp-emails" rows="3" spellcheck="false" placeholder="jij@voorbeeld.nl"></textarea></label>
          <label><?= th('Domein') ?> <span class="hint"><?= th('(optioneel; eerst verifiëren op haveibeenpwned.com → Domain search)') ?></span>
            <input id="hibp-domain" spellcheck="false" placeholder="jouwdomein.nl"></label>
          <p class="muted small" id="hibp-status"></p>
        </div>
        <p class="form-error" hidden></p>
      </div>

      <div data-sk="enc" class="card need-admin" id="enc-card">
        <h2>🔐 <?= th('Beveiligde verbinding met de agents') ?></h2>
        <?= xp(t('Elke agent heeft een eigen sleutel: alles is versleuteld en ondertekend.'), t('<b>Wat het is:</b> elke agent (1.50.0 of nieuwer) heeft een <b>eigen sleutel</b>. Alles wat hij verstuurt is daarmee versleuteld en ondertekend (AES-256 met een handtekening), ook het antwoord van RzSense. Er gaat geen token meer over de lijn en een sleutel is nooit leesbaar. <b>Koppelen:</b> een nieuwe agent koppel je met een <b>koppelcode</b> (Agent Manager → 🔗 Koppelcode, eenmalig en 15 minuten geldig). Agents die nog de algemene sleutel gebruiken, krijgen hun eigen sleutel vanzelf. <b>Veiligheid:</b> een bericht met een verkeerde sleutel, een oude handtekening of van een onbekend IP-adres wordt geweigerd en geeft een ⚠️-melding.')) ?>
        <div class="btn-row"><button type="button" class="btn" id="conn-cert">🔐 <?= th('Certificaat bekijken') ?></button>
          <span class="muted small"><?= th('Welk certificaat de agents zien, downloaden en vernieuwen') ?></span></div>
        <div id="conn-ipwait"></div>
        <label><?= th('Versleutelen') ?> <select id="enc-mode"></select></label>
        <label><?= th('Sleutels van de agents vernieuwen') ?>
          <select id="conn-days"><option value="7"><?= th('elke 7 dagen') ?></option><option value="30"><?= th('elke 30 dagen') ?></option><option value="90"><?= th('elke 90 dagen') ?></option><option value="0"><?= th('alleen met de hand') ?></option></select></label>
        <label class="switch"><input type="checkbox" id="conn-pin"><span></span> <?= t('📌 <b>Certificaat van RzSense vastzetten</b> (bij een eigen, self-signed certificaat)') ?></label>
        <p class="muted small" id="conn-pin-info" style="margin-top:-.4rem"></p>
        <label class="switch"><input type="checkbox" id="conn-iplock"><span></span> <?= t('🌐 Agents alleen vanaf hun <b>bekende IP-adres</b>') ?></label>
        <?= xp(t('Een agent mag alleen vanaf het IP-adres waarmee hij zich de eerste keer meldde.'), t('Het eerste IP-adres van een agent wordt onthouden. Komt hij van een ander adres, dan weigert RzSense hem en krijg je een melding; hierboven kun je het nieuwe adres dan <b>toestaan</b>.')) ?>
        <label class="switch"><input type="checkbox" id="conn-https"><span></span> <?= t('🔒 Agents alleen via <b>https</b> (via het reserve-adres mag http, met een ⚠️)') ?></label>
        <label class="switch"><input type="checkbox" id="conn-ownkey"><span></span> <?= t('🔑 Alleen agents met een <b>eigen sleutel</b> (een agent met alleen een token wordt geweigerd; koppelen met een koppelcode blijft altijd kunnen)') ?></label>
        <div class="btn-row"><button type="button" class="btn" id="conn-cleanup">🧹 <?= th('Alle agents opruimen…') ?></button>
          <span class="muted small" id="conn-cleanup-info"><?= th('oude tokens en de algemene sleutel wissen, en de agents opnieuw laten installeren met een reservekopie') ?></span></div>
        <div class="btn-row"><button type="button" class="btn" id="conn-url-all">🌐 <?= th('Alle agents naar een ander adres…') ?></button>
          <span class="muted small"><?= th('bv. https via je reverse proxy; elke agent test het eerst (agent 1.51.0)') ?></span></div>
        <p class="muted small"><?= t('Per server (sleutel, IP-adres, verbinding): zie de tabel bij <a href="#settings/agents" data-goto="settings/agents">Agents</a>.') ?></p>
        <details class="adv"><summary><?= th('Algemene sleutel (agents 1.49.x)') ?></summary>
          <?= xp(t('Alleen voor oude agents (1.49.x) die nog één gedeelde sleutel gebruiken.'), t('Agents van versie 1.49.x versleutelen met één algemene sleutel. Nieuwere agents gebruiken die alleen nog om over te stappen op hun eigen sleutel. Heb je geen oude agents meer, dan kun je de algemene sleutel bij <i>Opruimen</i> wissen.')) ?>
          <label><?= th('Sleutel') ?> <span class="hint"><?= th('(64 tekens)') ?></span>
            <span class="enc-key-row"><input id="enc-key" type="password" readonly class="mono" placeholder="<?= th('nog geen sleutel') ?>">
              <button type="button" class="btn" id="enc-show" title="<?= th('Tonen') ?>">👁</button>
              <button type="button" class="btn" id="enc-copy" title="<?= th('Kopiëren') ?>">📋</button></span></label>
          <div class="btn-row"><button type="button" class="btn" id="enc-generate">🎲 <?= th('Sleutel genereren') ?></button> <span class="muted small" id="enc-info"></span></div>
        </details>
      </div>

      <div data-sk="outside" class="card need-admin" id="outside-card">
        <h2>🕶️ <?= th('Onzichtbaar van buiten') ?></h2>
        <?= xp(t('Van buiten lijkt RzSense niet te bestaan: een vreemde ziet alleen een 404.'), t('<b>Wat het doet:</b> van buiten je eigen netwerk ziet een vreemde niets dat naar RzSense wijst: alleen een gewone <b>404</b>, zoals een webserver die geeft. <b>Blijft werken:</b> agents, heartbeats, de links uit je meldingen en de REST API. Thuis (je lokale netwerken bij <i>Inloggen</i>) verandert er niets.')) ?>
        <div id="stealth-switches"></div>
        <div id="stealth-requests"></div>
        <h3 class="set-sub">📱 <?= th('Toegestane apparaten en passkeys') ?></h3>
        <?= xp(t('Alleen apparaten op deze lijst openen RzSense van buiten; hier zie je ze allemaal.'), t('Dit geldt per gebruiker. Een apparaat zet je thuis op de lijst bij <i>Mijn account → Van buiten en passkeys</i>. Hier zie je de apparaten van iedereen, en kun je ze hernoemen en intrekken.')) ?>
        <div id="stealth-devices"></div>
      </div>

      <div data-sk="api" class="card need-admin" id="api-card">
        <h2><?= th('API-sleutels') ?></h2>
        <?= xp(t('Sleutels waarmee andere systemen RzSense kunnen uitlezen en bedienen.'), t('<b>Wat het is:</b> met de REST API lees je statussen, meetwaarden en het IDS uit, bijvoorbeeld vanuit Home Assistant, en blokkeer je IP\'s of bevestig je storingen vanuit een ander systeem. <b>Per sleutel</b> kies je wat hij mag, eventueel vanaf welke adressen, en hoe lang hij geldig is. <b>Gebruik:</b> stuur de sleutel mee als <code>Authorization: Bearer &lt;sleutel&gt;</code>.')) ?>
        <p class="small"><?= th('Basis-URL:') ?> <code id="api-base"></code></p>
        <div id="api-keys" class="key-list"></div>
        <form id="api-key-form" class="api-key-form">
          <input name="name" placeholder="<?= th('Naam, bv. Home Assistant') ?>" required maxlength="100">
          <div id="api-key-fields"></div>
          <button class="btn"><?= th('＋ Sleutel maken') ?></button>
        </form>
        <!-- 1.197.0: de netwerkkaart voor Home Assistant -->
        <h3 class="set-sub">🏠 <?= th('Home Assistant-kaart') ?></h3>
        <?= xp(t('Een kaart voor je Home Assistant-dashboard met het netwerk in het kort, zoals mDS.'), t('<b>Wat je ziet:</b> één oordeel (met de redenen), de internetlijn, het verbruik deze maand, de apparaten, de netwerkpaden, het IDS en de top 3 verkeer vandaag. Tik op een tegel en RzSense opent op die plek. <b>Zo zet je hem erin:</b> 1. maak hierboven een sleutel met alleen <i>Status lezen</i> (en <i>IDS lezen</i> voor de IDS-tegel); 2. in Home Assistant: <i>Instellingen → Dashboards → ⋮ → Bronnen → Bron toevoegen</i>, met de URL hieronder als <i>JavaScript-module</i>; 3. ververs de pagina en voeg de kaart <i>RzSense netwerk</i> toe. <b>Let op:</b> de browser of de app haalt de gegevens zelf bij RzSense op. Gebruikt Home Assistant https, dan moet RzSense ook via https bereikbaar zijn, en zet je <i>alleen vanaf</i> bij de sleutel, dan moeten de adressen van je telefoon en computer erin staan. Iedereen die het dashboard mag bewerken ziet de sleutel; daarom alleen leesrechten.')) ?>
        <p class="small"><?= th('Bron (JavaScript-module):') ?> <code id="ha-card-url"></code> <button type="button" class="link-btn" id="ha-card-copy"><?= th('Kopieer') ?></button></p>
        <pre class="code" id="ha-card-yaml"></pre>
        <details class="small api-docs">
          <summary><?= th('Endpoints') ?></summary>
          <ul>
            <li><?= t('<code>GET /status</code> – samenvatting, problemen en status per server') ?></li>
            <li><?= t('<code>GET /hosts</code> · <code>/hosts/{id}</code> – servers met checks') ?></li>
            <li><?= t('<code>GET /checks</code> – alle checks (<code>?status=2</code>, <code>?type=disk</code>, <code>?host_id=1</code>)') ?></li>
            <li><?= t('<code>GET /checks/{id}</code> · <code>/checks/{id}/history?hours=24</code>') ?></li>
            <li><?= t('<code>GET /alerts?limit=50</code> – laatste meldingen') ?></li>
            <li><?= t('<code>GET /network</code> – netwerk in het kort: oordeel met redenen, internetlijn, verbruik deze maand, apparaten, netwerkpaden (en IDS met <i>IDS lezen</i>)') ?></li>
          </ul>
          <p class="muted"><?= th('🛡️ IDS lezen:') ?></p>
          <ul>
            <li><?= t('<code>GET /ids</code> – overzicht: open incidenten, actieve blokkades, servers') ?></li>
            <li><?= t('<code>GET /ids/incidents</code> (<code>?status=open</code>, <code>?severity=2</code>, <code>?host_id=1</code>, <code>?limit=100</code>)') ?></li>
            <li><?= t('<code>GET /ids/blocks</code> – actieve blokkades (<code>?all=1</code>: ook opgeheven en verlopen)') ?></li>
            <li><?= t('<code>GET /ids/access</code> – logins, sudo en su (<code>?host_id=1</code>, <code>?type=ssh</code>, <code>?failed=1</code>)') ?></li>
          </ul>
          <p class="muted"><?= th('🐞 Kwetsbaarheden lezen:') ?></p>
          <ul>
            <li><?= t('<code>GET /vulnerabilities</code> – per server: aantal critical, high, medium, low, actief misbruikt, met oplossing') ?></li>
            <li><?= t('<code>GET /vulnerabilities/{id}</code> – de kwetsbaarheden van één server (<code>?all=1</code>: ook geaccepteerde)') ?></li>
          </ul>
          <p class="muted"><?= th('⛔ IDS blokkeren:') ?></p>
          <ul>
            <li><?= t('<code>POST /ids/block</code> – <code>{"host": "web01", "ip": "203.0.113.7", "hours": 24, "reason": "…"}</code> (<code>hours</code> 0 = permanent)') ?></li>
            <li><?= t('<code>POST /ids/unblock</code> – <code>{"id": 12}</code> of <code>{"host": "web01", "ip": "203.0.113.7"}</code>') ?></li>
          </ul>
          <p class="muted"><?= th('✔ Checks bedienen:') ?></p>
          <ul>
            <li><?= t('<code>POST /checks/{id}/ack</code> – <code>{"comment": "…"}</code> · <code>POST /checks/{id}/unack</code>') ?></li>
            <li><?= t('<code>POST /checks/{id}/maintenance</code> · <code>/hosts/{id}/maintenance</code> – <code>{"minutes": 60, "reason": "…"}</code> (0 = onderhoud beëindigen)') ?></li>
            <li><?= t('<code>POST /checks/{id}/run</code> – nu checken') ?></li>
          </ul>
        </details>
      </div>

      <div data-sk="users" class="card" id="users-card" <?= ($user['id'] ?? 0) === 0 ? 'hidden' : '' ?>>
        <h2><?= th('Gebruikers') ?></h2>
        <?= xp(t('Accounts met hun rol (beheerder, gebruiker, alleen lezen) en tweestapsverificatie.'), t('<b>Beheerder:</b> mag alles. <b>Gebruiker:</b> handelt storingen af (bevestigen, nu checken, onderhoud, poortscan), maar richt niets in. <b>Alleen lezen:</b> kijkt alleen. <b>Tweestapsverificatie (2FA):</b> naast het wachtwoord is een code uit een authenticator-app nodig, zoals Google of Microsoft Authenticator, Authy, Bitwarden of 1Password.'), 'need-admin') ?>
        <p class="muted small" data-role-only="nonadmin"><?= th('Je account. Hier stel je je tweestapsverificatie in; je wachtwoord wijzig je hieronder.') ?></p>
        <div id="users" class="key-list"></div>
        <p class="small need-admin" id="pw-reset-info"></p>
        <form id="user-form" class="user-form need-admin">
          <div class="row2">
            <input name="username" placeholder="<?= th('Gebruikersnaam') ?>" required maxlength="64" autocomplete="off">
            <input name="password" type="password" placeholder="<?= th('Wachtwoord (min. 8 tekens)') ?>" required minlength="8" autocomplete="new-password">
          </div>
          <input name="email" type="email" placeholder="<?= th('E-mailadres (optioneel, voor wachtwoord vergeten)') ?>" maxlength="190" autocomplete="off">
          <label><?= th('Rol') ?>
            <select name="role">
              <option value="user"><?= th('👤 Gebruiker – storingen afhandelen') ?></option>
              <option value="readonly"><?= th('👁 Alleen lezen – kijken') ?></option>
              <option value="admin"><?= th('🔑 Beheerder – alles') ?></option>
            </select>
          </label>
          <label class="switch small"><input type="checkbox" name="totp"><span></span> <?= th('Direct tweestapsverificatie instellen') ?></label>
          <button class="btn"><?= th('＋ Gebruiker toevoegen') ?></button>
        </form>
        <div class="need-admin" id="sess-admin">
          <h3 class="set-sub">📱 <?= th('Ingelogde apparaten') ?></h3>
          <?= xp(t('Wie waar is ingelogd, met uitloggen op afstand.'), t('Alle sessies van alle gebruikers, en de onthouden apparaten (die loggen bij hun volgende bezoek vanzelf in). <b>Uitloggen</b> werkt bij de volgende klik op dat apparaat; een onthouden apparaat wordt dan ook vergeten.')) ?>
          <div class="sess-tools"><select id="sess-user" aria-label="<?= th('Gebruiker') ?>"></select>
            <button type="button" class="btn small-btn" id="sess-user-out" hidden><?= th('Alles van deze gebruiker uitloggen') ?></button>
            <button type="button" class="btn small-btn danger" id="sess-all-out"><?= th('Iedereen uitloggen behalve mij') ?></button></div>
          <div id="sess-all"></div>
        </div>
      </div>

      <?php if (($user['id'] ?? 0) === 0): ?>
      <?php endif; ?>
      <div data-sk="templates" class="card need-admin" id="templates-card">
        <h2>📋 <?= th('Sjablonen') ?></h2>
        <?= xp(t('Vaste sets checks voor een soort server; wijzig het sjabloon en alle servers volgen.'), t('<b>Wat het is:</b> een sjabloon is een set checks (met drempels, interval en meldregels) die je aan servers koppelt, bijvoorbeeld <i>Linux-server</i> of <i>Website</i>. <b>Wat het doet:</b> pas je het sjabloon aan, dan veranderen de checks op alle gekoppelde servers mee, behalve checks die je op een server zelf hebt aangepast. <b>Koppelen:</b> hier of bij <i>✎ Server bewerken</i>.')) ?>
        <div class="btn-row"><button type="button" class="btn primary" id="tpl-new">＋ <?= th('Nieuw sjabloon') ?></button>
          <button type="button" class="btn" id="tpl-from-host">📋 <?= th('Sjabloon maken van een server…') ?></button></div>
        <div id="tpl-list"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="allchecks" class="card need-admin" id="allchecks-card">
        <h2>☑️ <?= th('Alle checks') ?></h2>
        <?= xp(t('Alle checks in één lijst; vink er meer aan om ze samen te bewerken.'), t('Vink checks aan om ze tegelijk aan te passen: interval, drempels, melden, onderhoud, aan of uit, kopiëren naar andere servers of verwijderen. Klik op een naam om die ene check te bewerken.')) ?>
        <div class="ac-bar"><input type="search" id="ac-q" placeholder="<?= th('🔍 Zoek check, doel of server…') ?>" autocomplete="off">
          <select id="ac-host"><option value=""><?= th('Alle servers') ?></option></select>
          <select id="ac-type"><option value=""><?= th('Alle types') ?></option></select></div>
        <div id="ac-bulk" class="ac-bulk" hidden></div>
        <div id="ac-list"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="cleanup" class="card need-admin" id="cleanup-card">
        <h2>🧹 <?= th('Opschoonadvies') ?> <button type="button" class="btn small-btn" id="cleanup-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Checks die opgeruimd of aangepast kunnen worden, met een voorstel.'), t('Checks die je aandacht verdienen: al dagen onbekend, de meeste meldingen (ruismakers), steeds wisselend, al lang in warning, net onder de drempel, of al lang uit. Bij elke check staat een voorstel. Wat normaal is, zet je op <i>niet meer tonen</i>.')) ?>
        <div id="cleanup-list"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="advice" class="card need-admin" id="advice-card">
        <h2>💡 <?= th('Slimme voorstellen') ?> <button type="button" class="btn small-btn" id="advice-run" title="<?= th('Nu opnieuw bepalen (anders elke nacht)') ?>">🔄</button></h2>
        <?= xp(t('Elke nacht doet RzSense voorstellen om minder en betere meldingen te krijgen.'), t('<b>Wat het doet:</b> elke nacht kijkt RzSense naar de geschiedenis en stelt voor: <i>melden na</i> meer (of minder) mislukte checks, een vast onderhoudsvenster voor iets dat steeds op hetzelfde moment gebeurt, en afhankelijkheden (volgens UniFi, of omdat servers steeds samen uitvallen). <b>Jij beslist:</b> niets verandert tot je op <i>Toepassen</i> klikt, behalve <i>melden na N</i> als de schakelaar hieronder aan staat. Een genegeerd voorstel komt niet terug, en alles staat in het wijzigingslogboek.')) ?>
        <label class="switch"><input type="checkbox" id="alert-auto"><span></span> <span><?= t('<b>Melden na N zelf instellen</b>: een voorstel voor <i>melden na N</i> meteen toepassen') ?></span></label>
        <?= xp(t('Wordt gemeld en is terug te draaien.'), t('Je krijgt een ℹ️-melding (<i>Systeemmeldingen → Melden na N zelf aangepast</i>) en er komt een regel in het <i>Wijzigingslogboek</i> om terug te gaan naar de vorige waarde. Een teruggezet voorstel komt niet terug.')) ?>
        <div id="advice-list"><p class="muted"><?= th('Laden…') ?></p></div>
        <h3 class="small" style="margin:1.2rem 0 .3rem">🗓️ <?= th('Vaste onderhoudsvensters') ?></h3>
        <p class="muted small" style="margin-top:0"><?= th('Elke dag of op één weekdag een tijd zonder meldingen, voor een server of één check (bv. de back-up om 2:00 of de herstart op zondag).') ?></p>
        <div id="window-list"></div>
      </div>

      <div data-sk="statuspage" class="card need-admin" id="statuspage-card">
        <h2>🟢 <?= th('Statuspagina') ?></h2>
        <?= xp(t('Een openbare statuspagina voor huisgenoten of klanten, via een geheime link.'), t('<b>Wat het is:</b> een eenvoudige pagina die laat zien of de onderdelen die jij kiest werken, met een balk per dag over de laatste 90 dagen. <b>Privacy:</b> geen IP-adressen, servernamen of foutteksten. <b>Openen:</b> via een <b>geheime link</b>, ook van buiten. Met <i>Onzichtbaar van buiten</i> aan geeft een verkeerde link gewoon een 404.')) ?>
        <div id="statuspage-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="chain" class="card need-admin" id="chain-card">
        <h2>🧪 <?= th('Meldketen testen') ?></h2>
        <?= xp(t('Test in één keer of alle meldkanalen werken.'), t('Stuurt één testmelding door alle kanalen die aan staan (Pushover, ntfy, e-mail, Home Assistant-webhook, MQTT en de meldingenpagina), met de opgeslagen instellingen. Per kanaal zie je of het lukte.')) ?>
        <button type="button" class="btn primary" id="chain-test">🧪 <?= th('Nu testen') ?></button>
        <div id="chain-out"></div>
      </div>

      <div data-sk="actlog" class="card" id="actlog-card">
        <h2>📒 <?= th('Logboek') ?> <button type="button" class="btn small-btn" id="al-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Wat er op je servers gebeurde, wie het deed en wat er veranderde.'), t('<b>Wat het is:</b> één logboek voor alles wat er gebeurde: wat de agent zelf veranderde (firewall, EDR-regels, de beschermingen), wat RzSense deed (isoleren, processen stoppen, zelf herstellen, bijwerken, rapporten), wat een beheerder deed (blokkeren, API-sleutels, apparaten …) en elke <b>wijziging</b> aan instellingen, servers, checks en sjablonen. <b>Terugzetten:</b> bij een wijziging zet ↩ hem terug; een verwijderde check of server komt terug (zonder meetgeschiedenis). <b>Bron:</b> 🧑 een beheerder, 🤖 RzSense zelf, 🖥️ de agent. <b>Per server:</b> ook in de Agent Manager (kaart Logboek). <b>Goed om te weten:</b> wachtwoorden, tokens en sleutels staan er nooit in.')) ?>
        <div class="ac-bar al-bar">
          <select id="al-host" aria-label="<?= th('Server') ?>"><option value="0"><?= th('Alle servers') ?></option></select>
          <select id="al-module" aria-label="<?= th('Onderdeel') ?>"><option value=""><?= th('Alle onderdelen') ?></option></select>
          <select id="al-source" aria-label="<?= th('Bron') ?>"><option value=""><?= th('Iedereen') ?></option><option value="user">🧑 <?= th('Beheerder') ?></option><option value="rzsense">🤖 RzSense</option><option value="agent">🖥️ <?= th('Agent') ?></option></select>
          <select id="al-days" aria-label="<?= th('Periode') ?>"><option value="1"><?= th('24 uur') ?></option><option value="7"><?= th('7 dagen') ?></option><option value="30" selected><?= th('30 dagen') ?></option><option value="90"><?= th('90 dagen') ?></option><option value="365"><?= th('1 jaar') ?></option></select>
          <label class="switch small"><input type="checkbox" id="al-fail"><span></span> <?= th('Alleen mislukt') ?></label>
          <input type="search" id="al-q" placeholder="<?= th('Zoeken…') ?>" aria-label="<?= th('Zoeken') ?>">
          <a class="btn small-btn" id="al-csv" href="#" download>⬇ CSV</a>
        </div>
        <div id="al-list" class="al-list"><p class="muted"><?= th('Laden…') ?></p></div>
        <p class="muted small need-admin"><?= th('Bewaren') ?> <select id="al-keep" aria-label="<?= th('Bewaren') ?>"><option value="30"><?= th('30 dagen') ?></option><option value="90"><?= th('90 dagen') ?></option><option value="180"><?= th('180 dagen') ?></option><option value="365"><?= th('1 jaar') ?></option><option value="730"><?= th('2 jaar') ?></option><option value="1095"><?= th('3 jaar') ?></option><option value="3650"><?= th('10 jaar') ?></option></select>
          · <?= th('geldt voor het hele logboek, ook voor wijzigingen') ?></p>
      </div>

      <div data-sk="transfer" class="card need-admin" id="transfer-card">
        <h2>📦 <?= th('Exporteren en importeren') ?></h2>
        <?= xp(t('Servers, checks en sjablonen als bestand, om over te zetten of als reserve.'), t('<b>Wat het is:</b> servers, checks en sjablonen (en eventueel instellingen) als één JSON-bestand, om naar een andere RzSense te kopiëren of als leesbare reserve naast de back-up. <b>Zonder geheimen:</b> wachtwoorden, tokens, sleutels en SNMP-community gaan niet mee; agents koppel je op de nieuwe RzSense opnieuw.')) ?>
        <label class="switch"><input type="checkbox" id="tr-settings"><span></span> <?= th('Ook de instellingen (zonder wachtwoorden)') ?></label>
        <div class="btn-row"><button type="button" class="btn" id="tr-export">⬇ <?= th('Exporteren') ?></button>
          <button type="button" class="btn" id="tr-import">⬆ <?= th('Importeren…') ?></button><input type="file" id="tr-file" accept=".json,application/json" hidden></div>
        <?= xp(t('Importeren voegt alleen toe en overschrijft niets.'), t('Importeren voegt alleen toe: een server of sjabloon met dezelfde naam blijft zoals hij is, en een check die er al is wordt overgeslagen. Je ziet eerst wat er gaat gebeuren.')) ?>
      </div>

      <div data-sk="conn" class="card need-admin" id="conn-card">
        <h2>📡 <?= th('Verbindingen en taken') ?> <button type="button" class="btn small-btn" id="conn-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Wat er binnenkomt en wat RzSense op de achtergrond doet.'), t('Wat er bij RzSense binnenkomt (agents, heartbeats, API) en wat RzSense zelf op de achtergrond doet. Lukt een taak te lang niet, dan krijg je een ⚠️-melding (<i>Meldingen → Systeemmeldingen → Achtergrondtaak lukt niet</i>).')) ?>
        <div id="conn-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="dbconn" class="card need-admin" id="dbconn-card">
        <h2>🔌 <?= th('Databaseverbinding') ?></h2>
        <?= xp(t('Waar RzSense zijn gegevens bewaart; alleen wijzigen als je de database verhuist.'), t('De database (MariaDB of MySQL) waarin RzSense alles bewaart. Pas dit alleen aan als je de database verhuist. Opslaan vraagt om je wachtwoord en maakt de tabellen op de nieuwe plek aan.')) ?>
        <div id="dbconn-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="server" class="card need-admin" id="server-card">
        <h2>🖧 <?= th('Server en PHP') ?></h2>
        <?= xp(t('De basisinstellingen van RzSense: tijdzone, adressen, het pad naar ping en een paar PHP-waarden.'), t('<b>Wat het doet:</b> de tijdzone bepaalt alle tijden; de basis-URL gebruiken de agents, de externe URL komt in rapporten, meldingen en de statuspagina. <b>Zo stel je het in:</b> kies je tijdzone en vul de adressen in waarop RzSense bereikbaar is. Laat de PHP-waarden leeg om die uit php.ini te gebruiken. <b>Goed om te weten:</b> dit wordt in <code>config.php</code> opgeslagen (eventueel via de hulpdienst). Zet display_errors alleen aan om te debuggen.')) ?>
        <div id="server-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="syscheck" class="card need-admin" id="syscheck-card">
        <h2>🩺 <?= th('Systeemcheck') ?> <button type="button" class="btn small-btn" id="syscheck-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Klopt alles wat RzSense nodig heeft? PHP-versie, extensies, rechten, cron en de database.'), t('<b>Wat het doet:</b> een lijst met wat RzSense nodig heeft, met ✓ (goed), ! (werkt, maar kan beter) of ✕ (moet je oplossen) en wat je eraan doet. <b>Zo stel je het in:</b> los eerst de ✕ op, van boven naar beneden; met 🔄 kijk je opnieuw. <b>Goed om te weten:</b> onder <i>PHP-omgeving</i> zie je de precieze waarden, handig als je om hulp vraagt.')) ?>
        <div id="syscheck-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <!-- 1.151.0: eerst op de pagina Gezondheid -->
      <div data-sk="cron" class="card need-admin" id="cron-card">
        <h2>⏱️ Cron</h2>
        <?= xp(t('De regel waarmee de checks elke minuut draaien.'), t('<b>Wat het doet:</b> RzSense voert de checks, meldingen, rapporten en het opruimen uit in een ronde die cron elke minuut start. <b>Zo stel je het in:</b> zet de regel hieronder in de crontab van root (<code>crontab -e</code>) of in <code>/etc/cron.d</code>. <b>Goed om te weten:</b> staat cron stil, dan ziet de waakhond van de agents dat en meldt het via Pushover.')) ?>
        <div id="cron-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div data-sk="dbuse" class="card need-admin" id="dbuse-card">
        <h2>📊 <?= th('Gebruik van de database') ?></h2>
        <?= xp(t('Hoe groot de database is, welke tabellen het meeste ruimte gebruiken en hoe druk de verbindingen zijn.'), t('<b>Wat het doet:</b> per tabel de grootte en het aantal rijen, en hoeveel databaseverbindingen RzSense gebruikt. <b>Zo stel je het in:</b> is een tabel opvallend groot, verkort dan de bewaartermijn bij <i>Opslag</i>. <b>Goed om te weten:</b> na het opruimen geeft de database ruimte vaak pas vrij na optimaliseren: de knop <i>🧹 Database optimaliseren</i> hieronder.')) ?>
        <div id="dbuse-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div data-sk="perf" class="card need-admin" id="perf-card">
        <h2>🚀 <?= th('Prestaties') ?> <button type="button" class="btn small-btn" id="perf-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Hoe snel RzSense is: OPcache, compressie, de traagste verzoeken en onderdelen van de cron-ronde.'), t('<b>Wat het doet:</b> RzSense meet hoe lang verzoeken en de onderdelen van de cron-ronde duren, en ziet of PHP-OPcache en compressie aan staan. <b>Zo stel je het in:</b> rood is traag: volg het advies dat erbij staat (vaak OPcache aanzetten of compressie in je webserver). <b>Goed om te weten:</b> de cijfers gaan over de laatste 24 uur; met 🔄 haal je ze opnieuw op.')) ?>
        <div id="perf-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div data-sk="errors" class="card need-admin" id="errors-card">
        <h2>⚠️ <?= th('PHP-fouten (24 uur)') ?></h2>
        <?= xp(t('Fouten die PHP de afgelopen 24 uur in RzSense tegenkwam.'), t('<b>Wat het doet:</b> een lijst van foutmeldingen uit RzSense zelf, met hoe vaak en wanneer het laatst. <b>Zo stel je het in:</b> niets te doen als de lijst leeg is. Staat er een fout die blijft terugkomen, meld hem dan met de tekst erbij. <b>Goed om te weten:</b> wachtwoorden en sleutels staan nooit in deze lijst.')) ?>
        <div id="errors-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="db" class="card need-admin" id="db-card">
        <h2>🔑 <?= th('Databasewachtwoord') ?></h2>
        <?= xp(t('Maak met één klik een nieuw, sterk wachtwoord voor de database.'), t('RzSense gebruikt een eigen databasegebruiker. Met de knop maak je een nieuw, sterk wachtwoord en slaat RzSense het overal op. Alles blijft gewoon werken: dashboards, cron, API en agents.')) ?>
        <p class="small" id="db-status">…</p>
        <button type="button" class="btn" id="db-rotate"><?= th('🔑 Databasewachtwoord vernieuwen') ?></button>
      </div>

      </div>
      </div>
      </div>
    </section>

<?php if (!is_lan_session()): ?>
    <section id="tab-account" class="tab-panel" role="tabpanel" hidden>
      <div class="acct-page" data-pgrid="acct">
      <div class="pg-tools" data-pgtools="acct"></div>
      <h1 class="set-title">👤 <?= th('Mijn account') ?></h1>
      <div class="set-crumb muted small"><?= htmlspecialchars((string) $user['username']) ?> · <?= th(ROLE_LABELS[$role] ?? $role) ?></div>
      <div class="card" id="acct-data"><h2><?= th('Gegevens') ?></h2><?= xp(t('Je naam, e-mailadres en rol.'), t('<b>Wat het doet:</b> je e-mailadres gebruikt RzSense voor rapporten die je zelf verstuurt, voor wachtwoord vergeten en voor de controle op datalekken. <b>Zo stel je het in:</b> houd je e-mailadres actueel. <b>Goed om te weten:</b> je rol (beheerder of gebruiker) wijzigt alleen een beheerder.')) ?><div id="acct-data-body"><p class="muted"><?= th('Laden…') ?></p></div></div>
      <form id="password-form" class="card">
        <h2>🔑 <?= th('Wachtwoord wijzigen') ?></h2>
        <?= xp(t('Een nieuw wachtwoord voor je account.'), t('<b>Wat het doet:</b> vervangt je wachtwoord; andere apparaten blijven ingelogd tot je ze uitlogt bij <i>Ingelogde apparaten</i>. <b>Zo stel je het in:</b> kies minstens 8 tekens, het liefst een wachtzin uit een wachtwoordmanager. <b>Goed om te weten:</b> RzSense controleert of een wachtwoord in een bekend datalek staat (zonder het wachtwoord zelf te versturen).')) ?>
        <label><?= th('Huidig wachtwoord') ?><input type="password" name="current" autocomplete="current-password" required></label>
        <label><?= th('Nieuw wachtwoord') ?><input type="password" name="new" minlength="8" autocomplete="new-password" required></label>
        <button class="btn"><?= th('Wijzigen') ?></button>
      </form>
      <div class="card" id="acct-2fa"><h2>🔐 <?= th('Tweestapsverificatie') ?></h2><?= xp(t('Naast je wachtwoord een code uit een app op je telefoon.'), t('<b>Wat het doet:</b> bij het inloggen vraagt RzSense ook een code van 6 cijfers uit een app als Google Authenticator, Aegis of je wachtwoordmanager. <b>Zo stel je het in:</b> scan de QR-code met de app en vul de code ter controle in. Kwijt je je telefoon, dan zet een beheerder 2FA voor je uit. <b>Goed om te weten:</b> van buiten (via internet) kan 2FA verplicht zijn; op je lokale netwerk kan het lichter.')) ?><div id="acct-2fa-body"></div></div>
      <div class="card" id="acct-outside"><h2>🕶️ <?= th('Van buiten en passkeys') ?></h2><?= xp(t('Inloggen van buiten je netwerk, en met een passkey in plaats van een wachtwoord.'), t('<b>Wat het doet:</b> een passkey is een sleutel op je telefoon of laptop (vingerafdruk, gezicht of pincode); niemand kan hem raden of afluisteren. <b>Zo stel je het in:</b> voeg een passkey toe op elk apparaat waarmee je inlogt. <b>Goed om te weten:</b> een passkey werkt alleen op het adres waarop je hem maakte (bv. je externe URL).')) ?><div id="acct-outside-body"></div></div>
      <div class="card" id="acct-devices"><h2>📱 <?= th('Ingelogde apparaten') ?></h2><?= xp(t('Waar je account nu ingelogd is, en die apparaten uitloggen.'), t('<b>Wat het doet:</b> elk apparaat en elke browser die met je account is ingelogd, met het IP-adres en wanneer het laatst actief was. <b>Zo stel je het in:</b> ken je een apparaat niet, log het dan uit en wijzig je wachtwoord. <b>Goed om te weten:</b> <i>Onthoud mij</i> houdt een apparaat langer ingelogd; uitloggen hier maakt dat ongedaan.')) ?><div id="acct-devices-body"></div></div>
      <div class="card" id="acct-logins"><h2>🕘 <?= th('Mijn laatste logins') ?></h2><?= xp(t('Je laatste inlogpogingen, gelukt en mislukt.'), t('<b>Wat het doet:</b> wanneer, vanaf welk IP-adres en hoe (wachtwoord, passkey, lokaal netwerk) er met je account is ingelogd. <b>Zo stel je het in:</b> een mislukte poging die je niet zelf deed? Wijzig je wachtwoord en zet tweestapsverificatie aan. <b>Goed om te weten:</b> na te veel mislukte pogingen blokkeert RzSense het account of het IP-adres (Instellingen → Toegang).')) ?><div id="acct-logins-body"></div></div>
      <div class="card view-card" data-pk="view" data-ids-modern="<?= setting('ids_modern', '1') === '1' ? 'on' : 'off' ?>" data-af-motion="<?= max(0, min(2, (int) setting('af_motion', '2'))) ?>">
        <h2>🖥️ <?= th('Weergave') ?></h2>
        <?= xp(t('Hoe RzSense eruitziet voor jou; wijzigingen zie je meteen.'), t('<b>Wat het is:</b> hoe de pagina\'s eruitzien, direct toegepast. Alles hier geldt voor <b>je account</b>, dus ook op je andere apparaten. <b>Grootte:</b> apart voor je telefoon en je computer, zodat je op een klein scherm iets anders kunt kiezen. <b>◈ CC:</b> alle pagina\'s in de stijl van het Command center, met kaarten die je kunt verslepen (✎ Indeling). <b>Goed om te weten:</b> ook je sortering en de gekozen periode op het Dashboard en bij Analyse worden voor je account onthouden.')) ?>
        <div class="view-row">
          <span class="view-label"><?= th('Bij openen') ?> <span class="hint"><?= th('(voor je account)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Bij openen') ?>">
            <button type="button" class="seg-btn" data-view-open="closed" title="<?= th('Alle servers en regels dicht, en alle dagen behalve vandaag; wat je openklapt blijft open tot je herlaadt') ?>"><?= th('Alles dicht') ?></button>
            <button type="button" class="seg-btn" data-view-open="smart" title="<?= th('Vandaag en ernstige dingen open; opengeklapte servers worden onthouden') ?>"><?= th('Slim openen') ?></button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Lay-out') ?> <span class="hint"><?= th('(hoe de pagina\'s zijn opgebouwd)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Lay-out') ?>">
            <button type="button" class="seg-btn" data-view-layout="blox" title="<?= th('Een tegel per server') ?>">▦ Blox</button>
            <button type="button" class="seg-btn" data-view-layout="l1nes" title="<?= th('Servers onder elkaar, als een tabel') ?>">☰ L1nes</button>
            <button type="button" class="seg-btn" data-view-layout="compact" title="<?= th('Eén blokje per check: veel checks tegelijk, of voor een TV-scherm') ?>">▪ Compact</button>
            <button type="button" class="seg-btn" data-view-layout="cc" title="<?= th('Alles in de stijl van het Command center, met versleepbare kaarten') ?>">◈ CC</button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Thema') ?> <span class="hint"><?= th('(hoe het eruitziet)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Thema') ?>">
            <button type="button" class="seg-btn" data-skin-set="std" title="<?= th('De gewone stijl van RzSense') ?>"><?= th('Standaard') ?></button>
            <button type="button" class="seg-btn" data-skin-set="s" title="<?= th('Rustig en licht, in de stijl van Apple: witte kaarten, zachte schaduw, één accentkleur') ?>">@S</button>
            <button type="button" class="seg-btn" data-skin-set="glass" title="<?= th('Kaarten, menu\'s en vensters als glas, met licht dat er zacht achter beweegt') ?>">Liquid Glass</button>
            <button type="button" class="seg-btn" data-skin-set="neo" title="<?= th('Command-center-stijl: diep blauw, cyaan accent en gloeiende randen') ?>">Neo</button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Licht/donker') ?></span>
          <div class="seg" role="group" aria-label="<?= th('Licht/donker') ?>">
            <button type="button" class="seg-btn" data-theme-set="auto" title="<?= th('Volgt je computer of telefoon') ?>"><?= th('Auto') ?></button>
            <button type="button" class="seg-btn" data-theme-set="light"><?= th('Licht') ?></button>
            <button type="button" class="seg-btn" data-theme-set="dark"><?= th('Donker') ?></button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Grootte') ?> <span class="hint"><?= th('(per lay-out; telefoon en computer apart)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Grootte') ?>">
            <button type="button" class="seg-btn" data-view-density="small" title="<?= th('Compact: meer servers tegelijk in beeld') ?>">Small</button>
            <button type="button" class="seg-btn" data-view-density="large" title="<?= th('Meer informatie: CPU, RAM, disk, bereikbaarheid') ?>">Large</button>
          </div>
        </div>
        <label class="switch"><input type="checkbox" data-mon-pref="glow"><span></span> <span><?= t('<b>Rode gloed bij critical</b>: een server met een critical check pulseert zacht') ?> <span class="hint"><?= th('(voor je account)') ?></span></span></label>
        <label class="switch" id="vivid-row"><input type="checkbox" data-mon-pref="vivid"><span></span> <span><?= t('<b>Levendige kleuren</b> bij Liquid Glass: blauw, turquoise en paars licht en sterkere glans (uit = rustig, alleen blauw)') ?> <span class="hint"><?= th('(voor je account)') ?></span></span></label>
        <label class="switch"><input type="checkbox" data-mon-pref="loader"><span></span> <span><?= t('<b>Laadanimatie</b>: bij het openen lichten je servers een voor een op, en overal waar je moet wachten loopt een hartslag') ?> <span class="hint"><?= th('(voor je account; met "minder beweging" stilstaand)') ?></span></span></label>
        <label class="switch"><input type="checkbox" data-mon-pref="anim"><span></span> <span><?= t('<b>Live animaties</b>: getallen lopen naar de nieuwe waarde en een statuswissel licht even op') ?> <span class="hint"><?= th('(voor je account; staat je systeem op "minder beweging", dan altijd uit)') ?></span></span></label>
        <h3 class="set-sub">🛡️ <?= th('IDS') ?></h3>
        <label class="switch"><input type="checkbox" data-acct-ids-modern><span></span> <span><?= t('<b>Moderne weergave</b> van de pagina IDS (score, Nu aandacht nodig, serverkaarten, grafiek)') ?> <span class="hint"><?= th('(voor je account)') ?></span></span></label>
        <?= xp(t('Uit: de oudere, compacte weergave van de IDS-pagina.'), t('Aan: de IDS-pagina met een score, <i>Nu aandacht nodig</i>, serverkaarten en een grafiek. Uit: de compacte weergave van vóór 1.117.0, met de modus als tegel en de servers als regels.')) ?>
        <label class="af-motion-set"><span><?= t('<b>Beweging in de live Attack Flow</b> (IDS → Aanvalskaart)') ?> <span class="hint"><?= th('(voor je account)') ?></span></span>
          <input type="range" data-acct-motion min="0" max="2" step="1" list="af-motion-steps">
          <span class="af-motion-lbls small muted" aria-hidden="true"><span><?= th('Stil') ?></span><span><?= th('Rustig') ?></span><span><?= th('Vol') ?></span></span></label>
        <datalist id="af-motion-steps"><option value="0"></option><option value="1"></option><option value="2"></option></datalist>
        <?= xp(t('Hoeveel er beweegt in de live Attack Flow: stil, rustig of vol.'), t('<b>Vol:</b> bewegende deeltjes, flitsende schilden en een lopende band. <b>Rustig:</b> alles trager, zonder flitsen. <b>Stil:</b> geen beweging, handig voor een scherm dat de hele dag aanstaat. <b>Goed om te weten:</b> staat <i>minder beweging</i> aan op je apparaat, dan is het altijd stil.')) ?>
      </div>

      </div>
    </section>
<?php endif; ?>
  </main>

  <dialog id="modal" aria-labelledby="modal-title">
    <form method="dialog" class="modal-inner" id="modal-form">
      <header><h3 id="modal-title"></h3><button class="icon-btn" value="cancel" formnovalidate aria-label="<?= th('Sluiten') ?>" title="<?= th('Sluiten') ?>">✕</button></header>
      <div id="modal-body"></div>
      <p class="form-error" hidden></p>
      <footer id="modal-footer"></footer>
    </form>
  </dialog>

  <div id="toasts"></div>
<?php endif; ?>
<?php if ($user): ?>
  <script src="assets/vendor/qrcode.js?v=1.4.4"></script>
<?php endif; ?>
<?php if ($user && !$mustChange && !$resetToken): ?>
  <div class="lg-aurora" aria-hidden="true"><i></i><i></i><i></i></div><!-- 1.201.0: licht achter het glas (alleen met Liquid Glass) -->
  <?php if (($sidePrefs['mon_loader'] ?? '') !== 'off'): ?>
  <div class="rz-boot" id="rz-boot" aria-hidden="true"><canvas></canvas><div class="rz-boot-t"><b data-boot-head><?= th('Verbinden met RzSense') ?></b><span data-boot-sub></span></div></div>
  <?php endif; ?>
  <script src="assets/pulse.js?v=<?= filemtime(__DIR__ . '/assets/pulse.js') ?>"></script>
<?php endif; ?>
  <script nonce="<?= csp_nonce() ?>">window.LANG = <?= json_encode(I18n::lang()) ?>; window.I18N = <?= I18n::lang() === 'en' ? json_encode(I18n::dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : '{}' ?>;</script>
<div class="cmdk" id="cmdk" hidden>
  <div class="cmdk-box" role="dialog" aria-modal="true" aria-label="<?= th('Zoeken en doen') ?>">
    <input id="cmdk-in" type="search" autocomplete="off" spellcheck="false" placeholder="<?= th('Server, check, IP-adres of instelling… (bv. "rl02 disk onderhoud")') ?>">
    <div class="cmdk-list" id="cmdk-list" role="listbox"></div>
    <div class="cmdk-foot muted small"><?= th('↑↓ kiezen · Enter doen · Esc sluiten · woorden als "onderhoud", "nu", "bevestig", "grafiek", "blokkeer" geven acties') ?></div>
  </div>
</div>
  <script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>