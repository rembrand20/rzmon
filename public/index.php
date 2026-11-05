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
  <title>RzMon</title>
  <?= Brand::head() ?>
  <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
  <?php if ($userTheme === null): ?><script nonce="<?= csp_nonce() ?>">try{const t=localStorage.getItem('rzmon-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script><?php endif; ?>
</head>
<?php $sidePrefs = json_decode((string) ($prefsJson ?? ''), true) ?: []; $sideW = (int) ($sidePrefs['side_w'] ?? 0); ?>
<body<?= ($sidePrefs['side'] ?? '') === 'mini' ? ' class="side-mini"' : '' ?><?= $sideW >= 180 && $sideW <= 380 ? ' style="--side-w:' . $sideW . 'px"' : '' ?> data-role="<?= htmlspecialchars($role) ?>"<?php if ($user && !$mustChange && !$resetToken): ?> data-prefs="<?= htmlspecialchars($prefsJson ?? 'local') ?>"<?php endif; ?>>
<?php if ($resetToken): ?>
  <main class="login-wrap">
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
  <main class="login-wrap">
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
  <main class="login-wrap">
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
    <div class="brand"><?= Brand::logo() ?> <a class="version" href="https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md" target="_blank" rel="noopener" title="<?= th('RzMon-versie – bekijk wat er nieuw is') ?>">v<?= htmlspecialchars(RZMON_VERSION) ?></a>
      <button type="button" class="update-badge need-admin" id="update-badge" hidden title="<?= th('Er is een nieuwe versie van RzMon: klik om bij te werken') ?>"></button></div>
    <nav class="tabs main-tabs" role="tablist" id="main-tabs">
      <span class="tab-ind" aria-hidden="true"></span>
      <button class="tab active" data-tab="home" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 14l4-4"/><path d="M3.3 19a10 10 0 1 1 17.4 0"/><circle cx="12" cy="14" r="1.6"/></svg></span><span class="tab-lbl has-s"><?= th('Dashboard') ?></span><span class="tab-lbl-s"><?= th('Start') ?></span></button>
      <button class="tab" data-tab="dashboard" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span class="tab-badge" id="dash-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Monitoring') ?></span><span class="tab-lbl-s"><?= th('Checks') ?></span></button>
      <button class="tab" data-tab="alerts" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><span class="tab-badge" id="alerts-badge" hidden></span></span><span class="tab-lbl"><?= th('Meldingen') ?></span></button>
      <button class="tab" data-tab="ids" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg><span class="tab-badge" id="ids-badge" hidden></span></span><span class="tab-lbl">IDS</span></button>
      <?php if ($role !== 'readonly'): ?><button class="tab" data-tab="incidents" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.01"/></svg><span class="tab-badge" id="inc-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Incidenten') ?></span><span class="tab-lbl-s"><?= th('Incid.') ?></span></button><?php endif; ?>
      <?php if ($showVulns || $showLeaks): ?><button class="tab" data-tab="vulns" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 2 1.9 1.9M16 2l-1.9 1.9"/><path d="M9 7.1V6a3 3 0 1 1 6 0v1.1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v-9M6.5 13H3M21 13h-3.5M6 17l-2.5 2M18 17l2.5 2M6 9 3.5 7.5M18 9l2.5-1.5"/></svg><span class="tab-badge" id="vuln-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Kwetsbaarheden') ?></span><span class="tab-lbl-s"><?= th('CVE\'s') ?></span></button><?php endif; ?>
      <?php if ($showStats): ?><button class="tab" data-tab="analytics" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg></span><span class="tab-lbl"><?= th('Analyse') ?></span></button><?php endif; ?>
      <?php if ($showNet): ?><button class="tab" data-tab="net" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="2" width="6" height="5" rx="1"/><rect x="2" y="17" width="6" height="5" rx="1"/><rect x="16" y="17" width="6" height="5" rx="1"/><path d="M12 7v5M5 17v-2.5a1.5 1.5 0 0 1 1.5-1.5h11a1.5 1.5 0 0 1 1.5 1.5V17"/></svg><span class="tab-badge s1" id="net-badge" hidden></span></span><span class="tab-lbl"><?= th('Netwerk') ?></span></button><?php endif; ?>
      <?php if ($role === 'admin'): ?><button class="tab side-only" data-tab="settings" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg></span><span class="tab-lbl"><?= th('Instellingen') ?></span></button><?php endif; ?>
    </nav>
    <nav class="side-admin" id="side-admin" hidden aria-label="<?= th('Beheer') ?>">
      <button type="button" class="side-back" id="side-back">← <?= th('Terug naar RzMon') ?></button>
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
            <span class="role-tag r-<?= htmlspecialchars($role) ?>"><?= th(ROLE_LABELS[$role] ?? $role) ?></span>
            <div class="muted small"><?= $hasAccount ? th('Ingelogd vanaf {ip}', ['ip' => client_ip()]) : th('Ingelogd zonder wachtwoord omdat je op het lokale netwerk zit') ?></div></div>
          <?php if ($hasAccount): ?><button type="button" role="menuitem" data-acct="account">👤 <?= th('Mijn account') ?></button><?php endif; ?>
          <?php if ($role === 'admin'): ?><button type="button" role="menuitem" data-acct="settings">⚙️ <?= th('Instellingen') ?></button><?php endif; ?>
          <?php if (!$hasAccount): ?><a role="menuitem" class="acct-item" href="index.php?login=1">🔑 <?= th('Inloggen met je account') ?></a><?php endif; ?>
          <div class="acct-sep"></div>
          <div class="acct-row">🌓 <?= th('Thema') ?> <span class="seg" id="theme-seg"><button type="button" data-theme-set="auto">auto</button><button type="button" data-theme-set="light"><?= th('licht') ?></button><button type="button" data-theme-set="dark"><?= th('donker') ?></button></span></div>
          <div class="acct-row">◈ <?= th('Lay-out') ?> <span class="seg" id="dash-seg"><button type="button" data-view-layout="blox">Blox</button><button type="button" data-view-layout="l1nes">L1nes</button><button type="button" data-view-layout="compact">Compact</button><button type="button" data-view-layout="cc">CC</button></span></div>
          <div class="acct-row">🌐 <?= th('Taal') ?> <span class="seg" id="lang-seg"><button type="button" data-lang-set="nl" class="<?= I18n::lang() === 'nl' ? 'active' : '' ?>">NL</button><button type="button" data-lang-set="en" class="<?= I18n::lang() === 'en' ? 'active' : '' ?>">EN</button></span></div>
          <?php if ($hasAccount): ?><div class="acct-sep"></div><button type="button" role="menuitem" id="logout" class="danger-txt">⏻ <?= th('Uitloggen') ?></button><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <button type="button" class="cc-tvexit" id="cc-tvexit" hidden><?= th('✕ TV-modus uit') ?></button>
  <main class="container">
    <div class="iso-banner" id="iso-banner" role="alert" hidden></div><!-- 1.172.0: server in isolatie -->
    <section id="tab-home" class="tab-panel" aria-label="<?= th('Dashboard') ?>">
      <div class="hm-head">
        <div class="hm-live" id="hm-live"></div>
        <div><h1 class="hm-title"><?= th('Zo staat het ervoor') ?></h1><p class="muted small" id="hm-sub"></p></div>
        <div class="hm-right"><div class="seg" id="hm-period" role="group" aria-label="<?= th('Periode') ?>"></div><div id="hm-tools"></div></div>
      </div>
      <div id="home"><p class="muted"><?= th('Laden…') ?></p></div>
    </section>
    <section id="tab-dashboard" class="tab-panel" hidden>
<?php if ($schemaError): ?>
      <div class="schema-banner">⚠ <?= htmlspecialchars($schemaError) ?> — <?= t('controleer de databaserechten of voer {cmd} uit.', ['cmd' => '<code>php install/install.php</code>']) ?></div>
<?php endif; ?>
      <div class="maint-banner" id="maint-banner" hidden></div>
      <!-- 1.66.0: tellers onder de header (blijven staan), kerncijfers, "Nu aandacht nodig", werkbalk -->
      <div class="dash-counters" id="dash-counters">
        <div class="dash-in">
          <div class="health-counts" id="health-counts" role="group" aria-label="<?= th('Filter op status') ?>"></div>
          <span class="dash-last muted small"><span class="live-dot" id="live-dot" title="<?= th('Live: wijzigingen komen binnen een paar seconden binnen') ?>"></span> <span id="last-run"></span></span>
        </div>
      </div>
      <!-- 1.169.0: Checks (zoals altijd) of Beschikbaarheid per groep -->
      <div class="subtabs mon-tabs" role="tablist" aria-label="<?= th('Monitoring') ?>" id="mon-tabs">
        <button type="button" class="seg-btn active" data-msub="checks" role="tab" aria-selected="true">📋 <?= th('Checks') ?></button>
        <button type="button" class="seg-btn" data-msub="avail" role="tab" aria-selected="false">📶 <?= th('Beschikbaarheid') ?></button>
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
    </section>

    <section id="tab-alerts" class="tab-panel" hidden>
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

    <section id="tab-ids" class="tab-panel" hidden>
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
      <div class="pcard" data-pk="tabs" data-pw="12" data-pt="<?= th('Tabbladen') ?>"><div class="subtabs ids-tabs" role="tablist" aria-label="IDS" id="ids-tabs">
        <button type="button" class="seg-btn active" data-it="inc"><?= th('⚠️ Incidenten') ?> <span class="it-n" id="it-n-inc"></span></button>
        <button type="button" class="seg-btn" data-it="map"><?= th('🗺️ Aanvalskaart') ?></button>
        <button type="button" class="seg-btn" data-it="acc"><?= th('🔑 Toegang') ?> <span class="it-n" id="it-n-acc"></span></button>
        <button type="button" class="seg-btn" data-it="blk"><?= th('⛔ Blokkades') ?> <span class="it-n" id="it-n-blk"></span></button>
        <button type="button" class="seg-btn" data-it="prot"><?= th('🛡️ Bescherming') ?> <span class="it-n" id="it-n-prot"></span></button>
        <button type="button" class="seg-btn" data-it="live"><?= th('🛰️ Live') ?></button>
        <button type="button" class="seg-btn" data-it="rules"><?= th('🔏 Regels') ?></button>
        <button type="button" class="seg-btn" data-it="anom"><?= th('🧬 Afwijkingen') ?> <span class="it-n" id="it-n-anom"></span></button>
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
      <div class="ids-pane" data-pane="mitre" hidden>
        <div class="dash-bar"><select id="mitre-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <div class="seg" id="mitre-days"><button type="button" class="seg-btn" data-md="7"><?= th('7 dagen') ?></button><button type="button" class="seg-btn active" data-md="30"><?= th('30 dagen') ?></button><button type="button" class="seg-btn" data-md="90"><?= th('90 dagen') ?></button></div>
          <label class="switch small"><input type="checkbox" id="mitre-cover"><span></span> <?= th('Ook wat de regels kunnen zien') ?></label></div>
        <div class="card" id="mitre-matrix"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="live" hidden>
        <div class="dash-bar"><select id="edr-host" aria-label="<?= th('Server') ?>"><option value=""><?= th('Alle servers') ?></option></select>
          <label class="switch small"><input type="checkbox" id="edr-hits"><span></span> <?= th('Alleen treffers') ?></label>
          <label class="switch small"><input type="checkbox" id="edr-own" checked><span></span> <?= th('Toegestaan tonen (RzMon zelf en uitzonderingen)') ?></label>
          <span class="muted small" id="edr-info"></span></div>
        <div class="card" id="edr-feed"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="rules" hidden>
        <div class="dash-bar"><div class="seg" id="rule-src"><button type="button" class="seg-btn active" data-rs=""><?= th('Alle') ?></button><button type="button" class="seg-btn" data-rs="rzmon">RzMon</button><button type="button" class="seg-btn" data-rs="sigma">Sigma</button></div>
          <input id="rule-q" type="search" placeholder="<?= th('Zoeken: naam, techniek (T1059) …') ?>" autocomplete="off">
          <a class="btn small-btn need-admin" href="#settings/ids_rules" data-goto="settings/ids_rules">⚙️ <?= th('Regels en uitzonderingen beheren') ?></a></div>
        <div class="card rule-view" id="edr-rules"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div class="ids-pane" data-pane="inc">
        <div class="dash-bar">
          <input type="search" id="ids-search" placeholder="<?= th('🔍 Zoek incident, IP of server…') ?>">
          <div class="dash-chips" id="ids-filter" role="group" aria-label="Filter"></div>
          <span class="dash-sp"></span>
          <span class="muted small" id="ids-info"></span>
          <button type="button" class="btn need-admin" id="ids-block-add"><?= th('⛔ IP blokkeren') ?></button>
          <div class="dash-more">
            <button class="btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false" title="<?= th('Meer') ?>">⋯</button>
            <div class="dash-menu" role="menu" hidden><button type="button" id="ids-toggle" role="menuitem"><?= th('⊞ Alles openen') ?></button></div>
          </div>
        </div>
        <div id="ids-incidents" class="alert-list"></div>
      </div>
      <div class="ids-pane" data-pane="acc" hidden>
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
        <?= xp(t('Een IP-adres of netwerk blokkeren, voor heel RzMon.'), t('Een geblokkeerd IP-adres of netwerk krijgt nergens meer toegang tot RzMon: niet de webpagina, niet het inloggen, niet de API en geen agents. Jezelf en de RzMon-server kun je niet blokkeren. Toch buitengesloten? Op de server: <code>sudo php install/install.php --unblock-all</code>')) ?>
        <div class="block-add"><input id="block-net" class="mono" placeholder="<?= th('bv. 203.0.113.7 of 203.0.113.0/24') ?>" autocomplete="off">
          <input id="block-reason" placeholder="<?= th('reden (optioneel)') ?>" autocomplete="off">
          <button type="button" class="btn danger" id="block-add">⛔ <?= th('Blokkeren') ?></button></div>
        <?= xp(t('Alles wat nu geblokkeerd is, met deblokkeren.'), th('Hieronder alles wat nu geblokkeerd is: IP-adressen in RzMon, vergrendelde accounts en de IDS-blokkades op je servers. Deblokkeren werkt meteen; op de whitelist zetten heft de blokkade ook op en voorkomt een nieuwe.')) ?>
        <div class="bl-bar"><div class="seg" id="bl-tabs"><button type="button" class="seg-btn active" data-bl-tab="active"></button><button type="button" class="seg-btn" data-bl-tab="history"></button></div>
          <select id="bl-src"><option value=""><?= th('Alles') ?></option><option value="rz"><?= th('IP in RzMon') ?></option><option value="user"><?= th('Accounts') ?></option><option value="ids"><?= th('IDS (servers)') ?></option></select>
          <input id="bl-q" type="search" placeholder="<?= th('Zoeken: IP, naam, server, reden…') ?>" autocomplete="off"></div>
        <div id="blocks-list"></div>
        <p class="small wl-link"><span id="wl-count" class="muted"></span> <a class="btn small-btn" href="#settings/whitelist" data-goto="settings/whitelist">✅ <?= th('Whitelist beheren') ?></a></p>
      </div>

      </div>
      </div>
      </div>
    </section>

    <?php if ($showVulns || $showLeaks): ?>
    <section id="tab-vulns" class="tab-panel" hidden>
      <?php if ($showVulns && $showLeaks): ?>
      <div class="subtabs vuln-tabs" role="tablist" aria-label="<?= th('Kwetsbaarheden') ?>" id="vuln-tabs">
        <button type="button" class="seg-btn active" data-vt="cve">🐞 <?= th('Kwetsbaarheden') ?> <span class="it-n" id="vt-n-cve"></span></button>
        <button type="button" class="seg-btn" data-vt="leak">🔓 <?= th('Datalekken') ?> <span class="it-n" id="vt-n-leak"></span></button>
      </div>
      <?php endif; ?>
      <?php if ($showVulns): ?>
      <div class="vuln-pane" data-vpane="cve">
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
      <div class="pcard" data-pk="bar" data-pw="12" data-pt="<?= th('Zoeken en filters') ?>"><div class="dash-bar">
        <input type="search" id="vuln-search" placeholder="<?= th('🔍 Zoek pakket, CVE of server…') ?>" autocomplete="off">
        <div class="dash-chips" id="vuln-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="vuln-sev" aria-label="<?= th('Ernst') ?>">
          <option value="0"><?= th('Alle ernst') ?></option><option value="2"><?= th('Vanaf medium') ?></option>
          <option value="3"><?= th('Vanaf high') ?></option><option value="4"><?= th('Alleen critical') ?></option>
        </select>
        <button type="button" class="btn need-admin" id="vuln-scan">🔍 <?= th('Nu scannen') ?></button>
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
      <?php if ($showLeaks): ?>
      <div class="vuln-pane" data-vpane="leak" <?= $showVulns ? 'hidden' : '' ?>>
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
    <section id="tab-incidents" class="tab-panel" hidden>
      <!-- 1.142.0: één lijst uit monitoring, IDS en kwetsbaarheden -->
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

    <section id="tab-analytics" class="tab-panel" hidden>
      <!-- 1.106.0: trends door de tijd -->
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
    <section id="tab-net" class="tab-panel" hidden>
      <!-- 1.103.0: netwerkscan, zelfde opbouw als het dashboard -->
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
        <button type="button" class="btn need-admin" id="net-approve-all"><?= th('✔ Alle nieuwe bekend') ?></button>
        <button type="button" class="btn need-admin" id="net-scan">🔍 <?= th('Nu scannen') ?></button>
      </div>
      </div>
      <div class="pcard" data-pk="list" data-pw="12" data-pt="<?= th('Apparaten') ?>"><div class="card net-card"><div id="net-list"></div></div>
      </div>
      </div>
    </section>
    <?php endif; ?>

    <section id="tab-settings" class="tab-panel" hidden>
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
      <div class="set-toolbar">
        <span class="muted small"><?= th('Klik op een onderdeel om het te openen of te sluiten.') ?></span>
        <button type="button" class="btn" id="set-toggle-all"><?= th('⊞ Alles openen') ?></button>
      </div>
      <form id="settings-form" class="settings-grid need-admin" data-pgrid="set">
        <div data-sk="pushover" class="card">
          <h2>Pushover</h2>
          <?= xp(t('Pushmeldingen op je telefoon via de Pushover-app.'), t('<b>Wat het is:</b> Pushover is een app voor iPhone, Android en de computer die berichten van RzMon meteen op je scherm zet, met een eigen geluid per prioriteit en noodmeldingen die blijven herhalen tot je reageert. <b>Instellen:</b> maak op <a href="https://pushover.net/apps/build" target="_blank" rel="noopener">pushover.net</a> een applicatie aan; dat geeft het <i>API-token</i>. Je <i>user key</i> staat op je Pushover-dashboard. <b>Goed om te weten:</b> na een gratis proefperiode betaal je Pushover één keer per platform.')) ?>
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
          <?= xp(t('De prioriteit volgt Pushover; een storing bevestig je vanuit het bericht.'), t('ntfy krijgt dezelfde prioriteit als je bij Pushover (Geavanceerd) instelt: normaal = 3, hoog = 4, noodmelding = 5. Onder een storingsbericht staat een knop <b>✔ Bevestigen</b>: daarmee bevestig je de storing zonder RzMon te openen.')) ?>
          <button type="button" class="btn" id="ntfy-test"><?= th('📲 Testbericht sturen') ?></button>
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
          <input type="hidden" name="notify_rules"><input type="hidden" name="notify_channels"><input type="hidden" name="notify_flap"><input type="hidden" name="notify_quiet">
          <div class="quiet-box">
            <h3><?= th('🌙 Stille uren') ?></h3>
            <?= xp(t('Tijden zonder meldingen naar buiten; de checks lopen gewoon door.'), t('<b>Wat het doet:</b> in deze periodes stuurt RzMon niets via Pushover, ntfy, e-mail of Home Assistant. De checks draaien door en het dashboard en de meldingenpagina tonen alles. <b>Na afloop:</b> is er nog iets mis, dan krijg je <b>één bericht</b> met alle open problemen. Wat tussendoor vanzelf herstelde, wordt niet gemeld. <b>Goed om te weten:</b> per check kun je dit aanpassen (✎ → Stille uren).')) ?>
            <div id="quiet-editor"></div>
          </div>
          <div class="row2">
            <label><?= th('Meldingen per server bundelen binnen … seconden') ?> <span class="muted small"><?= th('(0 = niet bundelen)') ?></span><input type="number" name="notify_group_seconds" min="0" max="3600"></label>
            <label class="switch" style="align-self:end"><input type="checkbox" name="notify_recovery_acked"><span></span> <?= th('Ook herstel melden na een bevestigde storing') ?></label>
          </div>
          <?= xp(t('Meerdere storingen op één server tegelijk? Dan krijg je één bericht.'), t('Gaan op één server binnen deze tijd meerdere checks mis (of herstellen ze), dan stuurt RzMon één bericht met alles erin, in plaats van een rij losse berichten. De eerste melding wacht daardoor hooguit zo lang. <b>Goed om te weten:</b> Home Assistant krijgt wel een bericht per check, zodat je automatiseringen blijven werken. 0 = niet bundelen.')) ?>
          <h3 class="small" style="margin:1rem 0 .3rem">⏫ <?= th('Escaleren') ?></h3>
          <label class="switch"><input type="checkbox" name="esc_on"><span></span> <?= t('<b>Escaleren</b>: een storing die niemand bevestigt, na een tijd nog een keer melden, een stap hoger') ?></label>
          <div class="row3">
            <label><?= th('Warning: na … min') ?> <span class="muted small"><?= th('(0 = nooit)') ?></span><input type="number" name="esc_warn_min" min="0" max="1440"></label>
            <label><?= th('Critical: na … min') ?> <span class="muted small"><?= th('(0 = nooit)') ?></span><input type="number" name="esc_crit_min" min="0" max="1440"></label>
            <label><?= th('Critical wordt') ?><select name="esc_crit_prio"><option value="1"><?= th('hoge prioriteit') ?></option><option value="2"><?= th('nood (herhaalt tot je bevestigt)') ?></option></select></label>
          </div>
          <label class="switch"><input type="checkbox" name="esc_quiet"><span></span> <?= th('Niet escaleren tijdens de stille uren') ?></label>
          <?= xp(t('Een storing die niemand bevestigt, wordt na een tijd nog eens gemeld, een stap hoger.'), t('Is een storing na de ingestelde tijd nog niet bevestigd, dan meldt RzMon hem opnieuw met een hogere prioriteit: een warning gaat dan als critical (met de prioriteit van je critical-meldingen), een critical krijgt de prioriteit die je hierboven kiest. Dat gebeurt één keer per storing. <b>Bevestigen</b> stopt het escaleren.')) ?>
          <label class="switch"><input type="checkbox" name="notify_bundle_hosts"><span></span> <?= t('🔗 <b>Ook over servers heen bundelen</b>, met de waarschijnlijke oorzaak') ?></label>
          <?= xp(t('Vallen meerdere servers tegelijk uit, dan krijg je één bericht met de waarschijnlijke oorzaak.'), t('Gaan er binnen het bundelvenster meerdere servers mis, dan komt er één bericht, bijvoorbeeld <i>"🔴 CRITICAL: 5 servers tegelijk · waarschijnlijk: ze hangen allemaal aan Switch-zolder (UniFi)"</i>. <b>Hoe RzMon de oorzaak zoekt:</b> via je afhankelijkheden, via UniFi (welk access point of welke switch), via agents die allemaal tegelijk stil zijn en via je Proxmox-clusters.')) ?>
        </div>

        <div data-sk="sysinfo" class="card" id="sysinfo-card">
          <h2>ℹ️ <?= th('Systeeminfo') ?></h2>
          <?= xp(t('Meldingen over RzMon zelf (updates, certificaten, agents): per soort kies je waarheen.'), t('<b>Wat het is:</b> dit zijn meldingen die niet van een check komen, maar van RzMon zelf. Per soort kies je naar welke kanalen hij gaat. Bij Pushover kies je <i>stil</i> (zonder geluid) of <i>normaal</i>. Met 🧪 stuur je een voorbeeld naar de aangevinkte kanalen. <b>Goed om te weten:</b> tijdens de <b>stille uren</b> gaat er niets naar buiten, maar de melding staat wel op de meldingenpagina. Info-meldingen van checks stel je in bij de rij <i>Info (checks)</i>, of per check.')) ?>
          <div class="table-scroll"><table class="sysinfo-table" id="sysinfo"></table></div>
          <input type="hidden" name="sysinfo">
        </div>

        <div data-sk="alarm" class="card">
          <h2><?= th('Alarmgedrag') ?></h2>
          <label><?= th('Waarschuwen als een disk binnen … dagen vol raakt') ?> <span class="muted small"><?= th('(0 = uit)') ?></span><input type="number" name="disk_forecast_days" min="0" max="365"></label>
          <details class="adv"><summary><?= th('Geavanceerd') ?></summary>
          <label><?= th('Logbestanden: treffers tellen over de laatste … minuten') ?> <span class="muted small">(5–1440)</span><input type="number" name="log_window" min="5" max="1440"></label>
          <label class="switch"><input type="checkbox" name="auto_maint_enabled"><span></span> <?= th('Automatisch onderhoud na herhaalde meldingen') ?></label>
          <label><?= th('… na zoveel meldingen voor dezelfde storing') ?> <span class="muted small"><?= th('(alleen die check; stopt vanzelf als hij weer OK is)') ?></span><input type="number" name="auto_maint_after" min="1" max="100"></label>
          </details>
        </div>

        <div data-sk="webhook" class="card">
          <h2><?= th('Home Assistant-webhook') ?></h2>
          <?= xp(t('Elke melding ook naar Home Assistant, om er een automatisering mee te starten.'), t('<b>Wat het doet:</b> RzMon stuurt elke melding als JSON naar een webhook in Home Assistant. Daarmee start je een automatisering: een lamp die rood kleurt, een gesproken melding (TTS) of een bericht via de HA-app. <b>Instellen:</b> maak in Home Assistant een automatisering met als trigger <b>Webhook</b> en plak de URL hieronder, bijvoorbeeld <code>http://homeassistant.local:8123/api/webhook/rzmon-…</code>')) ?>
          <label class="switch"><input type="checkbox" name="ha_webhook_enabled"><span></span> <?= th('Webhook aan') ?></label>
          <label><?= th('Webhook-URL') ?><input name="ha_webhook_url" type="url" spellcheck="false" placeholder="http://homeassistant.local:8123/api/webhook/rzmon-geheim-id"></label>
          <p class="muted small"><?= t('Welke meldingen naar Home Assistant gaan, stel je in bij <b>🔔 Meldingen</b> (kolom Home Assistant).') ?></p>
          <label class="switch"><input type="checkbox" name="ha_webhook_verify_ssl"><span></span> <?= th('SSL-certificaat controleren (uit bij self-signed)') ?></label>
          <button type="button" class="btn" id="webhook-test"><?= th('🏠 Testbericht naar Home Assistant') ?></button>
        </div>

        <div data-sk="email" class="card">
          <h2>E-mail</h2>
          <?= xp(t('Meldingen per e-mail, altijd of alleen als reserve wanneer Pushover niet werkt.'), t('<b>Wat het doet:</b> RzMon stuurt meldingen ook als e-mail, via een mailserver (SMTP) die je hieronder invult. Kies <i>altijd</i> of <i>alleen als Pushover niet werkt</i>. <b>Goed om te weten:</b> bij Gmail, Outlook en iCloud heb je een <b>app-wachtwoord</b> nodig, niet je gewone wachtwoord. Met <i>Testmail sturen</i> controleer je meteen of het werkt.')) ?>
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

        <div data-sk="netlinks" class="card" id="netlinks-card">
          <h2>📡 <?= th('UniFi en AdGuard Home') ?></h2>
          <?= xp(t('Namen en extra gegevens uit UniFi en AdGuard Home voor de netwerkscan.'), t('<b>Wat het doet:</b> de netwerkscan (tabblad Netwerk) haalt uit UniFi de namen, het wifi-netwerk en het access point van je apparaten, en uit AdGuard Home de namen en de domeinen die elk apparaat opzoekt. Je kunt een apparaat ook <b>weren</b>: van het wifi en/of geen DNS meer. <b>Goed om te weten:</b> wachtwoorden en de API-sleutel gaan nooit terug naar de browser.')) ?>
          <h3 class="set-sub">UniFi</h3>
          <div class="row2">
            <label><?= th('Adres van de controller') ?> <span class="hint"><?= th('(bv. https://192.168.2.1 of https://unifi:8443)') ?></span><input name="unifi_url" class="mono" placeholder="https://192.168.2.1"></label>
            <label><?= th('Site') ?> <span class="hint"><?= th('(meestal default)') ?></span><input name="unifi_site" class="mono" placeholder="default"></label>
          </div>
          <div class="row2">
            <label><?= th('Gebruikersnaam (lokaal account)') ?><input name="unifi_user" autocomplete="off"></label>
            <label><?= th('Wachtwoord') ?><input name="unifi_pass" type="password" autocomplete="new-password"></label>
          </div>
          <label><?= th('of alleen een API-sleutel') ?> <span class="hint"><?= th('(Network 9 of nieuwer: alleen lezen, weren kan dan niet)') ?></span><input name="unifi_key" type="password" autocomplete="new-password" class="mono"></label>
          <label class="switch"><input type="checkbox" name="unifi_verify_ssl"><span></span> <?= th('Certificaat controleren') ?> <span class="hint"><?= th('(uit bij het eigen certificaat van UniFi)') ?></span></label>
          <?= xp(t('Gebruik een lokaal UniFi-account; met alleen een API-sleutel kan RzMon niet weren.'), t('<b>Instellen:</b> maak in UniFi een <b>lokaal account</b> voor RzMon (<i>Admins &amp; Users → Add admin → Restrict to local access only</i>), met beheerrechten voor Network en zonder 2FA. <b>Alleen een API-sleutel</b> (<i>Settings → Control Plane → Integrations</i>) kan ook: dan kan RzMon lezen, maar geen apparaten weren.')) ?>
          <h3 class="set-sub">AdGuard Home</h3>
          <label><?= th('Adressen') ?> <span class="hint"><?= th('(één per regel; gesynchroniseerde instanties allemaal invullen)') ?></span>
            <textarea name="adguard_urls" rows="2" class="mono" placeholder="http://192.168.2.203:3000&#10;http://192.168.2.204:3000"></textarea></label>
          <div class="row2">
            <label><?= th('Gebruikersnaam') ?><input name="adguard_user" autocomplete="off"></label>
            <label><?= th('Wachtwoord') ?><input name="adguard_pass" type="password" autocomplete="new-password"></label>
          </div>
          <label class="switch"><input type="checkbox" name="adguard_verify_ssl"><span></span> <?= th('Certificaat controleren') ?></label>
          <div class="btn-row"><button type="button" class="btn" id="netlinks-test">🧪 <?= th('Verbinding testen') ?></button></div>
          <div id="netlinks-result" class="small"></div>
        </div>

        <div data-sk="mqtt" class="card">
          <h2><?= th('MQTT (Home Assistant)') ?></h2>
          <?= xp(t('Elke server als apparaat met sensoren in Home Assistant, via MQTT.'), t('<b>Wat het doet:</b> RzMon meldt zich via <b>MQTT discovery</b> bij Home Assistant. Elke server wordt een apparaat, met sensoren voor de status en de meetwaarden van zijn checks. Zo gebruik je ze in dashboards en automatiseringen. <b>Nodig:</b> een MQTT-broker (bijvoorbeeld de add-on <i>Mosquitto broker</i>) en de MQTT-integratie in Home Assistant. Log bij Mosquitto in met een HA-gebruiker of een aparte gebruiker.')) ?>
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
          <?= xp(t('Een AI legt een storing of incident uit en stelt een oplossing voor.'), t('<b>Wat het doet:</b> bij een check, een gebundelde storing, een incident of een IDS-melding vraag je met 🤖 om advies. RzMon stuurt mee wat het weet (de check, de server, statuswissels, andere problemen, wijzigingen, herstelpogingen en eventueel een diagnose) en toont het antwoord: wat er aan de hand is, de oorzaak, de oplossing in stappen en hoe je controleert of het werkt.<br><b>Aanbieders:</b> Claude, ChatGPT, Gemini en Grok (in de cloud, met een API-sleutel) of <b>Ollama</b> (lokaal of op een eigen server, gratis, je gegevens blijven thuis).<br><b>Staat het uit,</b> dan zie je nergens iets van AI en gaat er niets de deur uit.')) ?>
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
            <p class="muted small" data-prov="ollama" hidden><?= t('Ollama op een andere machine? Laat het daar luisteren op het netwerk (<code>OLLAMA_HOST=0.0.0.0</code>) en zet de poort 11434 in de firewall alleen open voor de server van RzMon: Ollama heeft zelf geen wachtwoord.') ?></p>
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
            <?= xp(t('Wat het AI-advies kost: tokens en geschatte kosten, je tegoed en een maandbudget.'), t('<b>Waarom geschat:</b> de aanbieders laten met een gewone API-sleutel niet zien hoeveel tegoed je nog hebt. RzMon telt daarom zelf: elk antwoord zegt hoeveel tokens het kostte, en met de prijs per model die jij invult rekent RzMon de kosten uit. <b>Tegoed:</b> vul in hoeveel je hebt opgewaardeerd en vanaf wanneer; RzMon trekt de kosten eraf. Bij 80% krijg je een melding. <b>Maandbudget:</b> is het op, dan stopt AI-advies via de cloud tot volgende maand (of gaat het naar Ollama, zie Extra\'s). <b>Tegoed op:</b> meldt een aanbieder dat het tegoed op is, dan zie je dat hier en krijg je een melding met een link om op te waarderen.')) ?>
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
              <label class="switch"><input type="checkbox" name="ai_weekly"><span></span> <?= t('<b>📅 Weekoverzicht</b>: één keer per week in gewone taal wat er speelde, wat terugkwam en waar je eens naar moet kijken.') ?></label>
              <label class="ai-sub"><?= th('Op') ?><select name="ai_weekly_day">
                <option value="1"><?= th('maandag') ?></option><option value="2"><?= th('dinsdag') ?></option><option value="3"><?= th('woensdag') ?></option><option value="4"><?= th('donderdag') ?></option>
                <option value="5"><?= th('vrijdag') ?></option><option value="6"><?= th('zaterdag') ?></option><option value="7"><?= th('zondag') ?></option></select>
                <span class="muted small"><?= th('vanaf 8 uur') ?></span></label>
              <div class="btn-row ai-sub"><button type="button" class="btn small-btn" id="ai-week-open">📅 <?= th('Weekoverzicht bekijken') ?></button></div>
              <label class="switch"><input type="checkbox" name="ai_triage"><span></span> <?= t('<b>🏷️ Oordeel bij IDS-incidenten</b>: nieuwe IDS-incidenten krijgen een label <i>lijkt onschuldig</i>, <i>verdacht</i> of <i>lijkt echt</i> met de reden. Alleen een label: de AI handelt nooit iets af. Kost een vraag per incident.') ?></label>
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
            <label><?= th('Basismeting (uren)') ?> <span class="muted small"><?= th('eerst alleen vastleggen wat normaal is') ?></span><input type="number" name="ids_baseline_hours" min="0" max="720"></label>
            <label><?= th('Brute force bij … mislukte SSH-logins') ?><input type="number" name="ids_fail_count" min="2" max="1000"></label>
          </div>
          <div class="row3">
            <label><?= th('… binnen (minuten)') ?><input type="number" name="ids_fail_minutes" min="1" max="1440"></label>
            <label><?= th('Blokkeren (uren)') ?><input type="number" name="ids_block_hours" min="1" max="8760"></label>
            <label><?= th('Bij herhaling (dagen)') ?><input type="number" name="ids_repeat_days" min="1" max="365"></label>
          </div>
          <p class="small"><b><?= th('Nooit blokkeren') ?></b>: <?= t('je LAN en RzMon zelf worden nooit automatisch geblokkeerd. Andere IP-adressen en netwerken zet je op de <a href="#settings/whitelist" data-goto="settings/whitelist">✅ whitelist</a>; die geldt voor RzMon én het IDS op al je servers.') ?></p>
          <label class="switch"><input type="checkbox" name="ids_block_shared"><span></span> <?= th('Automatische blokkades op alle servers') ?></label>
          <?= xp(t('Wordt een aanvaller op één server geblokkeerd, dan meteen op al je servers.'), t('Een aanvaller die op één server automatisch geblokkeerd wordt (brute force, honeypot), wordt meteen ook geblokkeerd op al je andere servers met het IDS op <i>Blokkeren</i>. <b>Goed om te weten:</b> met de hand blokkeren geldt alleen voor de server die je kiest.')) ?>
          <h3 class="set-sub"><?= th('🍯 Honeypot') ?></h3>
          <?= xp(t('Lokaas: inlognamen en een poort die alleen aanvallers proberen.'), t('<b>Wat het is:</b> een honeypot is lokaas. Deze gebruikersnamen en deze poort gebruikt niemand behalve een aanvaller, dus één poging is genoeg. <b>Wat het doet:</b> komt de poging van buiten, dan blokkeert het IDS meteen (in de modus <i>Blokkeren</i>). Komt hij uit je eigen netwerk, dan krijg je een critical-melding: waarschijnlijk is daar een apparaat besmet. <b>Goed om te weten:</b> namen die op een server echt bestaan, worden daar overgeslagen.')) ?>
          <label><?= th('Honeypot-gebruikers') ?> <span class="muted small"><?= th('(gescheiden door spaties; vul gerust aan)') ?></span>
            <textarea name="ids_decoy_users" rows="2" class="mono"></textarea></label>
          <label><?= th('Honeypot-poort') ?> <span class="muted small"><?= th('(TCP; leeg = uit. Er luistert niets: de agent zet een regel in zijn eigen firewalltabel)') ?></span>
            <input type="number" name="ids_decoy_port" min="1" max="65535" placeholder="2323"></label>
          <h3 class="set-sub"><?= th('📁 Bestanden bewaken') ?></h3>
          <?= xp(t('Meldt nieuwe, gewijzigde en verwijderde bestanden in deze mappen.'), t('<b>Wat het doet:</b> de agent onthoudt van elk bestand in deze paden de vingerafdruk (SHA-256), grootte, rechten en eigenaar, en meldt nieuwe, gewijzigde en verwijderde bestanden. De inhoud gaat nooit naar RzMon. <b>Paden:</b> een map wordt helemaal doorzocht; met jokertekens kies je bestanden, bijvoorbeeld <code>/var/www/**/*.php</code>. Dit geldt voor alle servers; per server voeg je paden toe via <i>✎ Bewerken</i>. <b>Grenzen:</b> hooguit 20 paden en 10.000 bestanden per server.')) ?>
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
          <div class="row3">
            <label><?= th('Versturen via') ?><select name="ids_weekly"><option value="off"><?= th('uit') ?></option><option value="email"><?= th('e-mail') ?></option><option value="pushover">Pushover</option><option value="both"><?= th('e-mail en Pushover') ?></option></select></label>
            <label><?= th('Dag') ?><select name="ids_weekly_day"><?php foreach ([1 => 'maandag', 2 => 'dinsdag', 3 => 'woensdag', 4 => 'donderdag', 5 => 'vrijdag', 6 => 'zaterdag', 7 => 'zondag'] as $d => $n): ?><option value="<?= $d ?>"><?= th($n) ?></option><?php endforeach; ?></select></label>
            <label><?= th('Om') ?><input type="time" name="ids_weekly_time"></label>
          </div>
          <?= xp(t('Een overzicht van de afgelopen week, op de dag en tijd die je kiest.'), t('Het weekoverzicht bevat: geblokkeerde aanvallen, mislukte logins en de IP-adressen met de meeste pogingen, de honeypot, SSH-logins van buiten en de open incidenten van de afgelopen 7 dagen. Met <i>Nu versturen</i> krijg je er meteen een.')) ?>
          <p class="small"><button type="button" class="btn small-btn" id="ids-weekly-test"><?= th('Nu versturen') ?></button></p>
        </div>

        <div data-sk="ids_edr" class="card" id="ids-edr-card">
          <h2>🛡️ <?= th('Endpoint-bewaking') ?></h2>
          <?= xp(t('Kijkt live mee op je servers: processen, verbindingen, bestanden en containers.'), t('<b>Wat het is:</b> endpoint-bewaking (EDR). De agent (1.63.0 of nieuwer) ziet op het moment zelf nieuwe <b>processen</b> (auditd), <b>verbindingen</b> en luisterpoorten, nieuwe <b>bestanden</b> in tijdelijke mappen en webmappen (inotify) en <b>containers</b>. <b>Wat het doet:</b> RzMon toetst alles aan zijn eigen regels en aan de open <b>Sigma</b>-regels. Een treffer wordt een incident, met het bewijs erbij. <b>Waar je het ziet:</b> op <i>🛡️ IDS → 🛰️ Live</i>. Per server zet je het aan of uit in de Agent Manager.')) ?>
          <label class="switch"><input type="checkbox" name="edr_on"><span></span> <?= t('<b>Endpoint-bewaking aan</b> (op de servers waar het in de Agent Manager aan staat)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_sigma"><span></span> <?= t('<b>Sigma-regels</b> van SigmaHQ gebruiken (naast de eigen regels van RzMon)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_web"><span></span> <?= t('<b>Payload-inspectie</b> van verdachte webverzoeken (SWAG): SQL-injectie, XSS, Log4Shell, bekende exploits met CVE …') ?></label>
          <h3 class="set-sub"><?= th('Zelf ingrijpen (alleen in de modus ⛔ Blokkeren)') ?></h3>
          <label class="switch"><input type="checkbox" name="edr_kill"><span></span> <?= t('<b>Proces stoppen</b> bij een regel die dat zegt (webshell, reverse shell, cryptominer …)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_block"><span></span> <?= t('<b>IP blokkeren</b> bij een webaanval met een herkende payload') ?></label>
          <label class="switch"><input type="checkbox" name="edr_isolate"><span></span> <?= t('<b>Server isoleren</b> (24 uur) bij een reverse shell of cryptominer: alleen RzMon en je LAN kunnen er dan nog bij') ?></label>
          <?= xp(t('Wat RzMon bij een treffer doet, staat altijd in het incident.'), t('Wat RzMon zelf doet (bijvoorbeeld een proces stoppen of een IP blokkeren), staat altijd in het incident en gaat als critical ook naar Pushover. <b>Goed om te weten:</b> in de modus <i>🎓 Leren</i> grijpt RzMon nooit in, maar meldt het wel.')) ?>
          <h3 class="set-sub"><?= th('🧬 Afwijkingen en ketens') ?></h3>
          <label class="switch"><input type="checkbox" name="edr_anom"><span></span> <?= t('<b>Afwijkingen melden</b> (RzMon leert per server wat normaal is)') ?></label>
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
          <?= xp(t('Zoekt op of een IP-adres of bestand uit je incidenten al bekend is als gevaarlijk.'), t('<b>Wat het doet:</b> RzMon zoekt op wat er op internet bekend is over de IP-adressen en bestanden uit je incidenten: een bekende aanvaller, een onschuldige scanner, een Tor-uitgang, bekende malware? Dat staat dan bij het incident en bij het IP-adres, en RzMon handelt ernaar. <b>Privacy:</b> alleen het IP-adres of de vingerafdruk (hash) van een bestand gaat naar buiten, nooit namen van servers of gebruikers. <b>Goed om te weten:</b> werkt alleen als het IDS aan staat.')) ?>
          <label class="switch"><input type="checkbox" name="ti_on"><span></span> <?= t('<b>Dreigingsinformatie aan</b>') ?></label>
          <h3 class="set-sub">AbuseIPDB</h3>
          <label><?= th('API-sleutel') ?> <span class="hint"><?= t('(gratis: <a href="https://www.abuseipdb.com/account/api" target="_blank" rel="noopener">abuseipdb.com → Account → API</a>; 1.000 opzoekingen per dag)') ?></span>
            <input name="ti_abuseipdb_key" type="password" autocomplete="new-password" class="mono" placeholder="<?= th('plak hier je sleutel') ?>"></label>
          <p class="small"><button type="button" class="btn small-btn" data-ti-test="abuse"><?= th('Sleutel testen') ?></button> <span class="muted" data-ti-res="abuse"></span></p>
          <label class="switch"><input type="checkbox" name="ti_report"><span></span> <?= t('<b>Melden</b> bij AbuseIPDB wie RzMon automatisch blokkeert (brute force, webaanval, honeypot; hooguit één keer per dag per IP)') ?></label>
          <?= xp(t('Meldt aanvallers terug aan AbuseIPDB, zodat anderen ze ook kunnen weren.'), t('Het IP-adres, de soort aanval en de tekst "RzMon IDS" gaan naar AbuseIPDB, zodat anderen die aanvaller ook kunnen weren. <b>Goed om te weten:</b> adressen uit je eigen netwerk of van de whitelist worden nooit gemeld.')) ?>
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
          <h3 class="set-sub"><?= th('Wat RzMon ermee doet') ?></h3>
          <label class="switch"><input type="checkbox" name="ti_block"><span></span> <?= t('<b>Bekende aanvallers blokkeren</b> (modus ⛔ Blokkeren), vanaf deze score:') ?></label>
          <label><?= th('Score voor "bekende aanvaller" (0–100)') ?> <span class="hint"><?= th('(AbuseIPDB-percentage, of 80–90 bij GreyNoise/Shodan/abuse.ch)') ?></span><input name="ti_block_score" type="number" min="10" max="100"></label>
          <label class="switch"><input type="checkbox" name="ti_escalate"><span></span> <?= t('Een <b>login</b> vanaf een bekende aanvaller wordt critical (Pushover)') ?></label>
          <label class="switch"><input type="checkbox" name="ti_close_benign"><span></span> <?= t('Een webaanval van een <b>onschuldige scanner</b> (GreyNoise) vanzelf afhandelen') ?></label>
          <label class="switch"><input type="checkbox" name="ti_cve"><span></span> <?= t('Een webaanval op een <b>lek dat de server echt heeft</b> (tabblad Kwetsbaarheden) is critical') ?></label>
          <h3 class="set-sub"><?= th('🧭 Netwerk: AdGuard, Suricata en YARA') ?></h3>
          <label class="switch"><input type="checkbox" name="dns_on"><span></span> <?= t('<b>DNS-controle via AdGuard</b> (elk apparaat: domeinen van malware of phishing, DNS-tunnels, verzonnen domeinen)') ?></label>
          <p class="muted small" id="dns-status" style="margin-top:-.4rem"></p>
          <label class="switch"><input type="checkbox" name="suricata_on"><span></span> <?= t('<b>Suricata</b> (alerts van de agents gebruiken, als Suricata op een server draait; agent 1.64.0)') ?></label>
          <label class="switch"><input type="checkbox" name="suricata_block"><span></span> <?= t('<b>Blokkeren</b> bij een ernstig Suricata-alert (ernst 1, aanvaller van buiten, modus Blokkeren)') ?></label>
          <label class="switch"><input type="checkbox" name="edr_yara"><span></span> <?= t('<b>YARA</b> (regels van RzMon naar de agents sturen: nieuwe bestanden op webshells, miners, botnets en rootkits controleren; YARA moet op de server staan)') ?></label>
          <p class="muted small" id="ti-status"></p>
          <p class="small"><button type="button" class="btn small-btn" id="ti-lists"><?= th('Lijsten nu bijwerken') ?></button></p>
          <?= xp(t('Welke bronnen RzMon gebruikt en hoe vaak hij opzoekt.'), t('<b>Bronnen:</b> AbuseIPDB, GreyNoise Community, Shodan InternetDB, CIRCL hashlookup, abuse.ch (Feodo Tracker, URLhaus, ThreatFox, MalwareBazaar) en het Tor Project. <b>Hoe:</b> opzoeken gebeurt op de achtergrond (cron), met een cache van een dag en een limiet per dag, zodat je binnen de gratis grenzen blijft.')) ?>
        </div>

        <div data-sk="ids_attack" class="card" id="ids-attack-card">
          <h2>🌍 <?= th('IDS: aanvallers en verkeer') ?></h2>
          <p class="muted small"><?= t('Werkt alleen als het IDS aan staat (<a href="#settings/ids" data-goto="settings/ids">IDS: detectie</a>).') ?></p>
          <h3 class="set-sub"><?= th('☠️ Bekende aanvallers en land') ?></h3>
          <label class="switch"><input type="checkbox" name="ids_blocklists"><span></span> <?= th('Bekende aanvallers (Spamhaus DROP en FireHOL level 1, dagelijks bijgewerkt)') ?></label>
          <?= xp(t('Lijsten met bekende aanvallers, die de agents vooraf al tegenhouden.'), t('In de modus <i>Blokkeren</i> zetten de agents de adressen van deze lijsten vooraf in hun firewall, zodat een bekende aanvaller niet eens een poging kan doen. In de modus <i>Leren</i> krijgen logins en incidenten van zo\'n adres het label ☠️. <b>Goed om te weten:</b> je eigen netwerk staat er nooit in.')) ?>
          <label class="switch"><input type="checkbox" name="ids_geo"><span></span> <?= th('Land per IP-adres (DB-IP Lite, maandelijks bijgewerkt)') ?></label>
          <label><?= th('Normale landen') ?> <span class="muted small"><?= th('(landcodes, bv. NL BE DE; een SSH-login van buiten vanuit een ander land is critical)') ?></span>
            <input name="ids_geo_allowed" class="mono" placeholder="NL"></label>
          <p class="muted small" id="ipintel-status"></p>
          <?= xp(t('Uit welk land een IP-adres komt, opgezocht op de RzMon-server zelf.'), t('<b>Bron:</b> <a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a> (CC BY 4.0), elke maand bijgewerkt. <b>Privacy:</b> opzoeken gebeurt op de RzMon-server zelf; er gaat per login niets naar buiten. Met <i>Nu bijwerken</i> haal je de nieuwste lijst meteen op.')) ?>
          <p class="small"><button type="button" class="btn small-btn" id="ipintel-update"><?= th('Nu bijwerken') ?></button></p>
          <h3 class="set-sub"><?= th('🌐 Uitgaande verbindingen') ?> <span class="muted small"><?= th('(agent 1.56.0)') ?></span></h3>
          <label class="switch"><input type="checkbox" name="ids_outbound"><span></span> <span><?= t('Melden als een server verbinding maakt met een <b>bekende aanvaller</b> (critical)') ?></span></label>
          <label class="switch"><input type="checkbox" name="ids_outbound_country"><span></span> <span><?= t('…en met een land buiten je <b>normale landen</b> (warning, per programma aan te leren)') ?></span></label>
          <label class="switch"><input type="checkbox" name="ids_outbound_block"><span></span> <span><?= t('In de modus blokkeren uitgaand verkeer naar bekende aanvallers <b>blokkeren</b> (ook vanuit containers)') ?></span></label>
          <?= xp(t('Meldt verbindingen van je servers naar ongewone landen of bekende aanvallers.'), t('<b>Waarom:</b> zo valt een server op die al besmet is, bijvoorbeeld door een cryptominer of een verbinding met de aanvaller. <b>Hoe:</b> in de eerste uren (de basismeting) leert het IDS welke landen normaal zijn. <b>Vrijgeven:</b> klik bij het incident op <b>✅ Vrijgeven</b> om het IP op de whitelist te zetten (1, 7, 30 dagen of altijd), of op <b>Dit is normaal</b> voor dat programma en land.')) ?>
          <h3 class="set-sub"><?= th('📜 Logs van webdiensten') ?> <span class="muted small"><?= th('(agent 1.58.0)') ?></span></h3>
          <?= xp(t('Herkent aanvallen in de logs van je webdiensten, zoals SWAG en Home Assistant.'), t('<b>Wat het doet:</b> de agent leest elke minuut de nieuwe regels uit de logs en vat ze per IP samen; RzMon herkent daarin aanvallen. <b>Waar staan de logs?</b> Leeg of <code>auto</code> = zelf zoeken (de container van SWAG, gangbare mappen; in Home Assistant via de Supervisor). <b>Goed om te weten:</b> je eigen netwerk en de whitelist tellen nooit mee.')) ?>
          <div class="row3">
            <label class="switch"><input type="checkbox" name="weblog_swag"><span></span> <span><b>SWAG</b> (nginx)</span></label>
            <label class="switch"><input type="checkbox" name="weblog_ha"><span></span> <span><b>Home Assistant</b></span></label>
            <label class="switch"><input type="checkbox" name="weblog_vw"><span></span> <span><b>Vaultwarden</b></span></label>
          </div>
          <div class="row3">
            <label><?= th('SWAG: access-log') ?><input name="weblog_swag_path" class="mono" placeholder="auto"></label>
            <label><?= th('Home Assistant: home-assistant.log') ?><input name="weblog_ha_path" class="mono" placeholder="auto"></label>
            <label><?= th('Vaultwarden: logbestand of container') ?><input name="weblog_vw_path" class="mono" placeholder="auto"></label>
          </div>
          <div id="weblog-status" class="small"></div>
          <p class="small" style="margin:.6rem 0 .2rem"><b><?= th('Scenario\'s') ?></b> <span class="muted"><?= th('(elk apart aan of uit)') ?></span></p>
          <label class="switch"><input type="checkbox" name="wl_paths"><span></span> <span><?= t('SWAG: <b>bekend aanvalspad</b> met een foutcode (<code>/.env</code>, <code>/.git/</code>, <code>wp-login.php</code>, <code>phpmyadmin</code> …): meteen') ?></span></label>
          <label class="switch"><input type="checkbox" name="wl_ua"><span></span> <span><?= t('SWAG: <b>scanner</b> (sqlmap, nikto, nuclei, zgrab …): meteen') ?></span></label>
          <div class="lock-row"><label class="switch"><input type="checkbox" name="wl_404"><span></span> <span><?= t('SWAG: <b>veel 404</b>, vanaf') ?></span></label> <input type="number" name="wl_404_n" min="3" max="10000" class="num-in"></div>
          <div class="lock-row"><label class="switch"><input type="checkbox" name="wl_auth"><span></span> <span><?= t('SWAG: <b>veel 401/403</b> (wachtwoorden raden), vanaf') ?></span></label> <input type="number" name="wl_auth_n" min="3" max="10000" class="num-in"></div>
          <div class="lock-row"><label class="switch"><input type="checkbox" name="wl_nohost"><span></span> <span><?= t('SWAG: <b>zonder hostnaam</b> (444, scant je IP-adres), vanaf') ?></span></label> <input type="number" name="wl_nohost_n" min="1" max="10000" class="num-in"></div>
          <div class="lock-row"><?= t('<b>Home Assistant</b>: mislukte logins vanaf') ?> <input type="number" name="wl_ha_n" min="2" max="1000" class="num-in"> · <?= t('<b>Vaultwarden</b> vanaf') ?> <input type="number" name="wl_vw_n" min="2" max="1000" class="num-in"></div>
          <div class="lock-row"><?= th('…binnen (minuten)') ?> <input type="number" name="wl_minutes" min="1" max="1440" class="num-in"></div>
          <label class="switch"><input type="checkbox" name="weblog_block"><span></span> <span><?= t('In de modus blokkeren zo\'n IP ook <b>blokkeren</b> (op al je servers, via <i>Automatische blokkades op alle servers</i>)') ?></span></label>
          <?= xp(t('Deblokkeren kan altijd; ook verkeer naar Docker-containers wordt tegengehouden.'), t('Deblokkeren kan altijd bij <i>IDS → Blokkades</i>. <b>SWAG in Docker:</b> de agent blokkeert vóór Docker (prerouting), dus ook verkeer naar de container wordt tegengehouden.')) ?>
        </div>

        <div data-sk="ids_servers" class="card" id="ids-servers-card">
          <h2>🖥️ <?= th('IDS: servers') ?></h2>
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
          <?= xp(t('Zoekt bekende lekken (CVE\'s) in de software op je servers.'), t('<b>Wat het doet:</b> de agents (1.47.0 of nieuwer) sturen hun softwarelijst mee; voor de RzMon-server leest RzMon die zelf. RzMon zoekt daar de bekende kwetsbaarheden (CVE\'s) bij op <b>OSV.dev</b>, met de adviezen van de distributie zelf. Erbij staat of een lek <i>actief misbruikt</i> wordt (lijst van CISA) en hoe groot de kans op misbruik is (EPSS). <b>Privacy:</b> naar OSV.dev gaan alleen pakketnamen, versies en de distributie, geen servernamen of IP-adressen. <b>Ondersteund:</b> Debian, Ubuntu, Rocky Linux, AlmaLinux (en RHEL/CentOS via AlmaLinux) en Alpine.')) ?>
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

        <div data-sk="netscan" class="card" id="netscan-card">
          <h2>🔎 <?= th('Netwerkscan') ?></h2>
          <?= xp(t('Zoekt alle apparaten in je thuisnetwerk, met open poorten en fabrikant.'), t('<b>Wat het doet:</b> RzMon zoekt wat er op je <b>lokale netwerken</b> zit (<i>Inloggen → Lokale netwerken</i>): elk apparaat met IP-adres, MAC-adres, fabrikant, naam, open poorten en een gok wat voor apparaat het is. <b>Waar je het ziet:</b> op het tabblad <b>Netwerk</b>. Daar geef je een apparaat een naam, markeer je het als bekend, negeer je het of voeg je het met één klik toe aan RzMon. <b>Goed om te weten:</b> alleen privé-netwerken tot /22; nooit internet.')) ?>
          <label class="switch"><input type="checkbox" name="net_scan"><span></span> <?= t('<b>Netwerkscan aan</b> (tabblad Netwerk)') ?></label>
          <div class="row2">
            <label><?= th('Snelle ronde (ping en ARP) elke') ?>
              <select name="net_sweep_minutes"><option value="15">15 min</option><option value="30">30 min</option><option value="60">1 <?= th('uur') ?></option><option value="120">2 <?= th('uur') ?></option><option value="360">6 <?= th('uur') ?></option></select></label>
            <label><?= th('Diepere scan (poorten, namen, merk)') ?>
              <select name="net_deep_per_day"><option value="1"><?= th('1× per dag') ?></option><option value="2"><?= th('2× per dag') ?></option><option value="4"><?= th('4× per dag') ?></option><option value="6"><?= th('6× per dag') ?></option><option value="12"><?= th('12× per dag') ?></option></select></label>
          </div>
          <div id="netscan-nets" class="small"></div>
          <?= xp(t('Een nieuw apparaat geeft een melding; extra programma\'s maken de scan sneller.'), t('<b>Meldingen:</b> een nieuw apparaat geeft een ℹ️-melding <i>Nieuw apparaat op je netwerk</i>, standaard alleen op de meldingenpagina (kanalen bij <i>Meldingen → Systeemmeldingen</i>). De eerste scan is de basismeting en geeft geen meldingen. <b>Sneller:</b> met <code>fping</code> en <code>avahi-browse</code> (pakket <code>avahi-tools</code> of <code>avahi-utils</code>) op de RzMon-server gaat het sneller en herkent RzMon meer, maar nodig zijn ze niet. <b>Fabrikant:</b> uit de lijst van Wireshark, maandelijks bijgewerkt (uitgaand HTTPS naar <code>www.wireshark.org</code>).')) ?>
        </div>

        <div data-sk="internet" class="card" id="internet-card">
          <h2>🌍 <?= th('Internet') ?></h2>
          <?= xp(t('Bewaakt je internetlijn: ping, responstijd en snelheid.'), t('<b>Wat het doet:</b> RzMon bewaakt je internetlijn vanaf zijn eigen server. <b>Ping:</b> naar 1.1.1.1, 8.8.8.8 en je router, elke minuut. <b>Responstijd:</b> naar google.com en cloudflare.com, elke 5 minuten. <b>Snelheid:</b> download en upload via speed.cloudflare.com, op de tijden hieronder.')) ?>
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
          <?= xp(t('Een snelheidstest belast je lijn even; plan hem dus niet op drukke momenten.'), t('Elke test trekt je lijn een paar seconden vol; plan hem dus niet in de avond. Een test stopt na 10 seconden of na het aantal MB hierboven per richting (bij 1 Gbit/s ongeveer 2× 250 MB). <b>Goed om te weten:</b> ping en responstijd leren mee met de slimme drempels; de snelheid vergelijkt RzMon met je abonnement.')) ?>
        </div>

        <div data-sk="smart" class="card" id="smart-card">
          <h2>🧠 <?= th('Slimme drempels') ?></h2>
          <?= xp(t('RzMon leert wat normaal is per check en zet de drempels daar zelf op.'), t('<b>Wat het doet:</b> RzMon leert per check wat normaal is, per uur van de week (maandag 3:00 is anders dan dinsdag 14:00), en zet de drempels daarop. Dat begint na 7 dagen gegevens; daarna stelt RzMon ze elke nacht bij, hooguit 10% per nacht. <b>Terugdraaien:</b> elke aanpassing staat in het <i>Wijzigingslogboek</i>. <b>Werkt bij:</b> ping, TCP, HTTP, CPU, RAM, SNMP (CPU, geheugen, interface, load, temperatuur, UPS, UniFi), containers (CPU of RAM als losse check), DNS, NTP en MySQL. Disks houden vaste drempels, met de voorspelling wanneer ze vol raken.')) ?>
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
          <?= xp(t('Voor bestaande checks rekent RzMon uit hoeveel meldingen slim had gescheeld.'), t('Bestaande checks blijven handmatig tot je ze omzet. RzMon leert ook voor die checks en rekent na <b>op hoeveel van de afgelopen dagen er een warning was geweest</b>, met de drempels van nu en met slimme drempels. Een dag telt net als een echte melding: pas als er zoveel metingen <b>achter elkaar</b> te hoog waren als de check vraagt (<i>melden na N</i>).')) ?>
          <?= xp(t('Zo lees je de voorstellen: minder dagen, grijs en grillig.'), t('<b>Minder dagen met slim</b> = minder onnodige meldingen; die checks staan al aangevinkt. <b>Grijs</b> = slim levert daar niets op; de reden staat erachter. <b>Grillig</b> = de check piekt bijna elke dag even (bijvoorbeeld de webinterface van een camera); daar helpt <i>melden na 3</i> meer dan slimme drempels.')) ?>
          <div id="smart-proposals"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>

        <div data-sk="smartmore" class="card" id="smartmore-card">
          <h2>🧠 <?= th('Slim: overige instellingen') ?></h2>
          <h3 class="small" style="margin:.3rem 0">⏱️ <?= th('Slim interval') ?></h3>
          <label class="switch"><input type="checkbox" name="smart_interval"><span></span> <?= t('<b>Slim interval</b>: bij een probleem sneller checken, als alles al een uur goed is trager') ?></label>
          <div class="row2">
            <label><?= th('Bij een probleem elke … seconden') ?> <span class="muted small"><?= th('(ping, TCP, HTTP, SNMP)') ?></span><input type="number" name="smart_fast" min="10" max="300"></label>
            <label><?= th('Stabiel: hooguit … × het interval') ?> <span class="muted small"><?= th('(nooit trager dan een uur)') ?></span><input type="number" name="smart_slow" min="1" max="10"></label>
          </div>
          <?= xp(t('Bij een probleem vaker checken, en minder vaak als alles goed gaat.'), t('Sneller checken bij een probleem laat je eerder weten of een storing echt is, en wanneer hij voorbij is. Trager checken als alles stabiel is, ontlast je servers. <b>Goed om te weten:</b> checks van de agent volgen het rapport van de agent.')) ?>
          <?= xp(t('Melden na N blijft hetzelfde; sneller checken geeft geen extra meldingen.'), t('<i>Melden na N</i> blijft gelijk in tijd: bij een check op <i>melden na 3</i> met een interval van 1 minuut gaat de melding pas uit als het <b>2 minuten</b> mis is, net als zonder slim interval. Een korte hapering wordt dus niet alsnog een melding; wel weet RzMon sneller dat het weer goed is.')) ?>
          <h3 class="small" style="margin:1rem 0 .3rem">📈 <?= th('Afwijkingen en hints') ?></h3>
          <label class="switch"><input type="checkbox" name="anomaly_on"><span></span> <?= t('<b>Afwijkingen</b> bij checks zonder drempels (verkeer, clients, processen, logregels): ℹ️ als het flink anders is dan normaal op dat uur') ?></label>
          <label class="switch"><input type="checkbox" name="disk_jump_on"><span></span> <?= t('<b>Disk groeit hard</b>: ℹ️ als een disk in een uur zoveel procentpunt groeit:') ?> <input type="number" name="disk_jump_pct" min="1" max="50" style="width:4.5rem;display:inline-block"></label>
          <label class="switch"><input type="checkbox" name="context_on"><span></span> <?= t('<b>Oorzaak erbij</b>: in de melding wat er vlak ervoor gebeurde (update, herstart, wijziging)') ?></label>
          <label class="switch"><input type="checkbox" name="hb_learn"><span></span> <?= t('<b>Heartbeats leren hun ritme</b>: warning als hij later is dan normaal, of de meegestuurde waarde veel kleiner of groter') ?></label>
          <p class="muted small"><?= th('Afwijkingen en "disk groeit hard" staan bij Meldingen → Systeeminfo; standaard alleen op de meldingenpagina.') ?></p>
        </div>

        <div data-sk="update" class="card" id="update-card">
          <h2><?= th('⬆ RzMon bijwerken') ?></h2>
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
          <?= xp(t('RzMon kijkt dagelijks of er een nieuwe versie is en werkt zichzelf bij.'), t('<b>Wat het doet:</b> RzMon kijkt één keer per dag op GitHub of er een nieuwe versie is. Bijwerken doet de hulpdienst op de server: eventueel eerst een back-up, dan de nieuwe versie ophalen en het installatiescript uitvoeren, net als het commando met <code>install-rocky.sh</code>. <b>Goed om te weten:</b> je instellingen en gegevens blijven staan. De agents werk je apart bij (hieronder).')) ?>
        </div>

        <div data-sk="agents" class="card" id="agents-card">
          <h2>🖥️ <?= th('Agents') ?></h2>
          <div data-nodirty id="enc-hosts"></div>
          <h3 class="set-sub">⬆ <?= th('Bijwerken') ?></h3>
          <?= xp(t('Werk de agents vanaf hier bij, per server of allemaal tegelijk.'), t('Komt er een nieuwe agent, dan werk je die vanaf hier bij: per server in de <i>Agent Manager</i>, of allemaal tegelijk. De agent haalt de nieuwe versie bij RzMon op, controleert de handtekening en installeert hem zelf; zijn instellingen blijven staan. <b>Goed om te weten:</b> de eerste installatie gaat met het commando op de server.')) ?>
          <p class="small" id="agents-status">…</p>
          <button type="button" class="btn" id="agents-update-all" hidden><?= th('⬆ Alle verouderde agents bijwerken') ?></button>
        </div>

        <div data-sk="agentdef" class="card" id="agentdef-card">
          <h2><?= th('Standaardinstellingen') ?></h2>
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
            <?= xp(t('Laat agents een eigen commando uitvoeren en controleer de uitvoer.'), t('<b>Wat het doet:</b> met een <i>Commando</i>-check voert de agent een commando uit en vergelijkt RzMon de uitvoer met het antwoord dat je verwacht. <b>Veiligheid:</b> standaard staat dit uit. Aanzetten vraagt om je wachtwoord en komt in het logboek. Op een server zelf blokkeer je het altijd met <code>COMMANDS=0</code> (of <code>COMMANDS=nobody</code>) in de instellingen van de agent.')) ?>
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
          <label><?= th('Elke meting bewaren (dagen)') ?> <span class="hint"><?= th('(daarna per uur)') ?></span><input type="number" name="retention_days" min="1" max="3650"></label>
          <label><?= th('Uurgemiddelden bewaren (dagen)') ?> <span class="hint"><?= th('(grafieken tot een half jaar terug)') ?></span><input type="number" name="hourly_days" min="7" max="3650"></label>
          <label><?= th('Daggegevens bewaren (dagen)') ?> <span class="hint"><?= th('(Analyse en Dashboard)') ?></span><input type="number" name="daily_days" min="30" max="3650"></label>
          <p class="muted small" id="storage-growth"></p>
          <label><?= th('Meldingen bewaren (dagen)') ?> <span class="muted small"><?= th('(de pagina Meldingen toont ze per dag)') ?></span><input type="number" name="alerts_days" min="1" max="3650"></label>
        </div>

      </form>

      <div class="settings-grid" style="margin-top:1.25rem" data-pgrid="set">
        <div data-sk="ids_rules" class="card">
          <h2><?= th('Regels en uitzonderingen') ?></h2>
          <?= xp(t('Regels aan of uit zetten, en vastleggen wat op je servers normaal is.'), t('<b>Regels:</b> de eigen regels van RzMon en de Linux-regels van SigmaHQ. Een regel uitzetten geldt voor alle servers. <b>Uitzonderingen:</b> wat in een bepaalde situatie normaal is (een programma, gebruiker, pad, IP-adres …), per server, groep of overal. Dat geeft geen melding, RzMon grijpt niet in en het telt niet mee voor afwijkingen; in <i>IDS → Live</i> zie je het als groene regel. <b>Goed om te weten:</b> met één knop zet je een hele set aan (bijvoorbeeld systeemonderhoud of back-up), en RzMon stelt uitzonderingen voor op basis van je eigen meldingen. Op de IDS-pagina zie je de regels en hun treffers.')) ?>
          <div class="dash-bar"><div class="seg" id="rule-src-set"><button type="button" class="seg-btn active" data-rs=""><?= th('Alle') ?></button><button type="button" class="seg-btn" data-rs="rzmon">RzMon</button><button type="button" class="seg-btn" data-rs="sigma">Sigma</button><button type="button" class="seg-btn" data-rs="exc">🛡️ <?= th('Uitzonderingen') ?></button></div>
            <input id="rule-q-set" type="search" placeholder="<?= th('Zoeken: naam, techniek (T1059) …') ?>" autocomplete="off"></div>
          <div id="edr-rules-set"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>
        <div data-sk="whitelist" class="card">
          <h2>✅ <?= th('Whitelist (nooit blokkeren)') ?></h2>
          <?= xp(t('IP-adressen, netwerken en accounts die nooit geblokkeerd worden.'), t('<b>Wat het doet:</b> een IP-adres of netwerk op de whitelist wordt nergens geblokkeerd: niet in RzMon en niet door het IDS op welke server dan ook, ook niet via de lijsten met bekende aanvallers. Een account op de whitelist wordt nooit vergrendeld (het IP van de aanvaller wel). <b>Hoe lang:</b> je kiest 1, 7 of 30 dagen of altijd. <b>Goed om te weten:</b> je eigen netwerk en RzMon zelf worden al nooit automatisch geblokkeerd. Wat nu geblokkeerd is en deblokkeren staan op <i>IDS → Blokkades</i>; bij een blokkade of incident zet je een adres ook met één klik op de whitelist.')) ?>
          <div class="btn-row"><button type="button" class="btn" id="wl-add">✅ <?= th('Op de whitelist zetten…') ?></button></div>
          <div id="wl-list"><p class="muted"><?= th('Laden…') ?></p></div>
        </div>
      <form data-sk="access" class="card need-admin" id="access-form">
        <h2><?= th('Toegang') ?></h2>
        <div class="ip-status" id="ip-status">…</div>
        <label class="switch"><input type="checkbox" name="lan_no_login"><span></span> <?= th('Geen login nodig op het lokale netwerk') ?></label>
        <label class="switch"><input type="checkbox" name="lan_as_admin"><span></span> <?= t('Op het lokale netwerk als <b>beheerder</b> inloggen') ?></label>
        <?= xp(t('Aan: thuis ben je meteen beheerder. Uit: thuis eerst alleen lezen.'), t('<b>Aan:</b> op je lokale netwerk kom je zonder wachtwoord binnen als beheerder. <b>Uit:</b> op het lokale netwerk kom je binnen als <b>alleen lezen</b>; met <i>Inloggen</i> rechtsboven krijg je de rechten van je eigen account. <b>Goed om te weten:</b> van buitenaf is altijd een gebruikersnaam en wachtwoord nodig.')) ?>
        <label><?= th('Lokale netwerken') ?> <span class="hint"><?= th('(één per regel, bv. 192.168.1.0/24)') ?></span>
          <textarea name="trusted_networks" rows="5" spellcheck="false"></textarea></label>
        <details class="adv"><summary><?= th('Geavanceerd: reverse proxy') ?></summary>
        <label><?= th('Reverse proxy IP\'s') ?> <span class="hint"><?= th('(optioneel — alleen invullen als RzMon achter Nginx Proxy Manager, Traefik, Cloudflare tunnel e.d. staat)') ?></span>
          <textarea name="trusted_proxies" rows="2" spellcheck="false"></textarea></label>
        <p class="callout"><?= t('⚠️ Staat RzMon achter een reverse proxy of port-forward waarbij álle bezoekers het IP van je router/proxy krijgen? Vul dat proxy-IP dan in bij <i>Reverse proxy IP\'s</i> en zet het <b>niet</b> bij lokale netwerken — anders kan iedereen van buitenaf zonder wachtwoord binnen.') ?></p>
        </details>
        <h3 class="set-sub">⏱️ <?= th('Uitloggen') ?></h3>
        <div class="lock-row"><?= th('Uitloggen na') ?> <select name="session_idle"></select> <?= th('zonder activiteit') ?></div>
        <?= xp(t('Wanneer je automatisch wordt uitgelogd, en hoe Onthoud mij werkt.'), t('Zolang RzMon open en zichtbaar is, blijf je ingelogd. Ligt je telefoon in je zak of staat de pagina op de achtergrond, dan telt de tijd. <b>Onthoud mij op dit apparaat:</b> daarmee log je daarna vanzelf weer in (30 dagen). Van buiten je netwerk werkt dat alleen als het apparaat met 2FA is onthouden.')) ?>
        <h3 class="set-sub">🔐 <?= th('Inlogbeveiliging') ?></h3>
        <div class="lock-row"><?= th('Na') ?> <input type="number" name="login_lock_fails" min="1" max="50" class="num-in"> <?= th('mislukte pogingen binnen') ?>
          <input type="number" name="login_lock_window" min="1" max="1440" class="num-in"> <?= th('minuten:') ?></div>
        <label class="switch"><input type="checkbox" name="login_lock_account"><span></span> <span><?= t('het <b>account</b> blokkeren') ?></span></label>
        <label class="switch"><input type="checkbox" name="login_lock_ip"><span></span> <span><?= t('het <b>IP-adres</b> blokkeren (niet als het uit je lokale netwerken komt)') ?></span></label>
        <div class="lock-row"><?= th('Blokkade opheffen na') ?> <input type="number" name="login_lock_minutes" min="0" max="525600" class="num-in"> <?= th('minuten') ?>
          <span class="hint"><?= th('(0 = tot een beheerder deblokkeert)') ?></span></div>
        <?= xp(t('Blokkeert een account of IP-adres na te veel mislukte inlogpogingen.'), t('<b>Wat het doet:</b> na te veel mislukte pogingen blokkeert RzMon het account of het IP-adres. Een verkeerde 2FA-code telt ook. Pogingen tellen apart per account (vanaf welk IP dan ook) en per IP-adres. Je krijgt een ⚠️-melding via Pushover. <b>Goed om te weten:</b> een geblokkeerd account kan vanaf je <b>lokale netwerk</b> gewoon inloggen, zodat niemand van buiten je kan buitensluiten. <b>Deblokkeren:</b> bij <i>Gebruikers</i> (🔓) en bij <i>IDS → Blokkades</i>, of op de server met <code>sudo php install/install.php --unlock &lt;gebruiker&gt;</code>.')) ?>
        <p class="form-error" hidden></p>
      </form>

      <div data-sk="https" class="card need-admin" id="https-card">
        <h2>🔒 <?= th('Beheer via https') ?></h2>
        <?= xp(t('Beheer RzMon van buiten alleen via een veilige https-verbinding.'), t('<b>Waarom:</b> open je RzMon via http, dan kan iemand op het netwerk je wachtwoord, je sessie en de pagina zelf (bijvoorbeeld een installatiecommando) zien of veranderen. <b>Wat het doet:</b> met een <b>beheeradres</b> via je reverse proxy (bijvoorbeeld SWAG met Let\'s Encrypt) en <i>Beheer alleen via https</i> gaat de webinterface altijd via https. <b>Goed om te weten:</b> agents en de REST API blijven gewoon werken: hun berichten zijn al versleuteld of hebben een eigen sleutel.')) ?>
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
            <li><?= t('<b>Vertrouwde proxy</b>: zet het LAN-IP van SWAG bij Toegang → Geavanceerd → Reverse proxy IP\'s. Dan ziet RzMon het echte IP-adres van je bezoekers en dat ze via https komen.') ?></li>
            <li><?= t('<b>DNS-rewrite</b> (AdGuard Home → Filters → DNS-herschrijvingen): <code>rzmon.jouwdomein.nl</code> → het LAN-IP van SWAG. Thuis en onderweg gebruik je dan hetzelfde adres met hetzelfde geldige certificaat. Gebruikt de RzMon-server zelf ook AdGuard als DNS, dan kan hij het certificaat controleren.') ?></li>
            <li><?= t('Open RzMon via het beheeradres, vul het hierboven in en zet <i>Beheer alleen via https</i> aan.') ?></li>
          </ol>
          <pre class="mono small https-conf" id="https-conf"></pre>
          <?= xp(t('Nooddeur: via het IP-adres van RzMon kom je thuis altijd binnen.'), t('<b>Nooddeur:</b> https op het IP-adres van RzMon zelf (met een eigen certificaat; kies in je browser <i>toch doorgaan</i>) blijft vanaf je interne netwerken altijd werken, ook als het certificaat van het beheeradres verlopen is. <b>Buitengesloten?</b> Op de server: <code>sudo php install/install.php --https-off</code>.')) ?>
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
        <?= xp(t('Elke agent heeft een eigen sleutel: alles is versleuteld en ondertekend.'), t('<b>Wat het is:</b> elke agent (1.50.0 of nieuwer) heeft een <b>eigen sleutel</b>. Alles wat hij verstuurt is daarmee versleuteld en ondertekend (AES-256 met een handtekening), ook het antwoord van RzMon. Er gaat geen token meer over de lijn en een sleutel is nooit leesbaar. <b>Koppelen:</b> een nieuwe agent koppel je met een <b>koppelcode</b> (Agent Manager → 🔗 Koppelcode, eenmalig en 15 minuten geldig). Agents die nog de algemene sleutel gebruiken, krijgen hun eigen sleutel vanzelf. <b>Veiligheid:</b> een bericht met een verkeerde sleutel, een oude handtekening of van een onbekend IP-adres wordt geweigerd en geeft een ⚠️-melding.')) ?>
        <div class="btn-row"><button type="button" class="btn" id="conn-cert">🔐 <?= th('Certificaat bekijken') ?></button>
          <span class="muted small"><?= th('Welk certificaat de agents zien, downloaden en vernieuwen') ?></span></div>
        <div id="conn-ipwait"></div>
        <label><?= th('Versleutelen') ?> <select id="enc-mode"></select></label>
        <label><?= th('Sleutels van de agents vernieuwen') ?>
          <select id="conn-days"><option value="7"><?= th('elke 7 dagen') ?></option><option value="30"><?= th('elke 30 dagen') ?></option><option value="90"><?= th('elke 90 dagen') ?></option><option value="0"><?= th('alleen met de hand') ?></option></select></label>
        <label class="switch"><input type="checkbox" id="conn-pin"><span></span> <?= t('📌 <b>Certificaat van RzMon vastzetten</b> (bij een eigen, self-signed certificaat)') ?></label>
        <p class="muted small" id="conn-pin-info" style="margin-top:-.4rem"></p>
        <label class="switch"><input type="checkbox" id="conn-iplock"><span></span> <?= t('🌐 Agents alleen vanaf hun <b>bekende IP-adres</b>') ?></label>
        <?= xp(t('Een agent mag alleen vanaf het IP-adres waarmee hij zich de eerste keer meldde.'), t('Het eerste IP-adres van een agent wordt onthouden. Komt hij van een ander adres, dan weigert RzMon hem en krijg je een melding; hierboven kun je het nieuwe adres dan <b>toestaan</b>.')) ?>
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
        <?= xp(t('Van buiten lijkt RzMon niet te bestaan: een vreemde ziet alleen een 404.'), t('<b>Wat het doet:</b> van buiten je eigen netwerk ziet een vreemde niets dat naar RzMon wijst: alleen een gewone <b>404</b>, zoals een webserver die geeft. <b>Blijft werken:</b> agents, heartbeats, de links uit je meldingen en de REST API. Thuis (je lokale netwerken bij <i>Inloggen</i>) verandert er niets.')) ?>
        <div id="stealth-switches"></div>
        <div id="stealth-requests"></div>
        <h3 class="set-sub">📱 <?= th('Toegestane apparaten en passkeys') ?></h3>
        <?= xp(t('Alleen apparaten op deze lijst openen RzMon van buiten; hier zie je ze allemaal.'), t('Dit geldt per gebruiker. Een apparaat zet je thuis op de lijst bij <i>Mijn account → Van buiten en passkeys</i>. Hier zie je de apparaten van iedereen, en kun je ze hernoemen en intrekken.')) ?>
        <div id="stealth-devices"></div>
      </div>

      <div data-sk="api" class="card need-admin" id="api-card">
        <h2><?= th('API-sleutels') ?></h2>
        <?= xp(t('Sleutels waarmee andere systemen RzMon kunnen uitlezen en bedienen.'), t('<b>Wat het is:</b> met de REST API lees je statussen, meetwaarden en het IDS uit, bijvoorbeeld vanuit Home Assistant, en blokkeer je IP\'s of bevestig je storingen vanuit een ander systeem. <b>Per sleutel</b> kies je wat hij mag, eventueel vanaf welke adressen, en hoe lang hij geldig is. <b>Gebruik:</b> stuur de sleutel mee als <code>Authorization: Bearer &lt;sleutel&gt;</code>.')) ?>
        <p class="small"><?= th('Basis-URL:') ?> <code id="api-base"></code></p>
        <div id="api-keys" class="key-list"></div>
        <form id="api-key-form" class="api-key-form">
          <input name="name" placeholder="<?= th('Naam, bv. Home Assistant') ?>" required maxlength="100">
          <div id="api-key-fields"></div>
          <button class="btn"><?= th('＋ Sleutel maken') ?></button>
        </form>
        <details class="small api-docs">
          <summary><?= th('Endpoints') ?></summary>
          <ul>
            <li><?= t('<code>GET /status</code> – samenvatting, problemen en status per server') ?></li>
            <li><?= t('<code>GET /hosts</code> · <code>/hosts/{id}</code> – servers met checks') ?></li>
            <li><?= t('<code>GET /checks</code> – alle checks (<code>?status=2</code>, <code>?type=disk</code>, <code>?host_id=1</code>)') ?></li>
            <li><?= t('<code>GET /checks/{id}</code> · <code>/checks/{id}/history?hours=24</code>') ?></li>
            <li><?= t('<code>GET /alerts?limit=50</code> – laatste meldingen') ?></li>
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
        <?= xp(t('Elke nacht doet RzMon voorstellen om minder en betere meldingen te krijgen.'), t('<b>Wat het doet:</b> elke nacht kijkt RzMon naar de geschiedenis en stelt voor: <i>melden na</i> meer (of minder) mislukte checks, een vast onderhoudsvenster voor iets dat steeds op hetzelfde moment gebeurt, en afhankelijkheden (volgens UniFi, of omdat servers steeds samen uitvallen). <b>Jij beslist:</b> niets verandert tot je op <i>Toepassen</i> klikt, behalve <i>melden na N</i> als de schakelaar hieronder aan staat. Een genegeerd voorstel komt niet terug, en alles staat in het wijzigingslogboek.')) ?>
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

      <div data-sk="changelog" class="card need-admin" id="changelog-card">
        <h2>📜 <?= th('Wijzigingslogboek') ?> <button type="button" class="btn small-btn" id="cl-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Wie wat veranderde en wanneer, met terugdraaien.'), t('<b>Wat het is:</b> een logboek van wie wat veranderde en wanneer: instellingen, servers, checks en sjablonen, met de oude en de nieuwe waarde. <b>Terugdraaien:</b> een wijziging aan een instelling, server of check zet je terug met ↩; een verwijderde check of server komt terug (zonder meetgeschiedenis). <b>Goed om te weten:</b> wachtwoorden en sleutels staan er nooit leesbaar in.')) ?>
        <div class="ac-bar"><select id="cl-kind"><option value=""><?= th('Alles') ?></option><option value="setting"><?= th('Instellingen') ?></option><option value="host"><?= th('Servers') ?></option>
          <option value="check"><?= th('Checks') ?></option><option value="template"><?= th('Sjablonen') ?></option><option value="system"><?= th('Systeem') ?></option></select>
          <span class="muted small"><?= th('Bewaren') ?></span> <select id="cl-days" aria-label="<?= th('Bewaren') ?>"><option value="30"><?= th('30 dagen') ?></option><option value="90"><?= th('90 dagen') ?></option><option value="365"><?= th('1 jaar') ?></option><option value="1095"><?= th('3 jaar') ?></option></select></div>
        <div id="cl-list"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="transfer" class="card need-admin" id="transfer-card">
        <h2>📦 <?= th('Exporteren en importeren') ?></h2>
        <?= xp(t('Servers, checks en sjablonen als bestand, om over te zetten of als reserve.'), t('<b>Wat het is:</b> servers, checks en sjablonen (en eventueel instellingen) als één JSON-bestand, om naar een andere RzMon te kopiëren of als leesbare reserve naast de back-up. <b>Zonder geheimen:</b> wachtwoorden, tokens, sleutels en SNMP-community gaan niet mee; agents koppel je op de nieuwe RzMon opnieuw.')) ?>
        <label class="switch"><input type="checkbox" id="tr-settings"><span></span> <?= th('Ook de instellingen (zonder wachtwoorden)') ?></label>
        <div class="btn-row"><button type="button" class="btn" id="tr-export">⬇ <?= th('Exporteren') ?></button>
          <button type="button" class="btn" id="tr-import">⬆ <?= th('Importeren…') ?></button><input type="file" id="tr-file" accept=".json,application/json" hidden></div>
        <?= xp(t('Importeren voegt alleen toe en overschrijft niets.'), t('Importeren voegt alleen toe: een server of sjabloon met dezelfde naam blijft zoals hij is, en een check die er al is wordt overgeslagen. Je ziet eerst wat er gaat gebeuren.')) ?>
      </div>

      <div data-sk="conn" class="card need-admin" id="conn-card">
        <h2>📡 <?= th('Verbindingen en taken') ?> <button type="button" class="btn small-btn" id="conn-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <?= xp(t('Wat er binnenkomt en wat RzMon op de achtergrond doet.'), t('Wat er bij RzMon binnenkomt (agents, heartbeats, API) en wat RzMon zelf op de achtergrond doet. Lukt een taak te lang niet, dan krijg je een ⚠️-melding (<i>Meldingen → Systeemmeldingen → Achtergrondtaak lukt niet</i>).')) ?>
        <div id="conn-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="dbconn" class="card need-admin" id="dbconn-card">
        <h2>🔌 <?= th('Databaseverbinding') ?></h2>
        <?= xp(t('Waar RzMon zijn gegevens bewaart; alleen wijzigen als je de database verhuist.'), t('De database (MariaDB of MySQL) waarin RzMon alles bewaart. Pas dit alleen aan als je de database verhuist. Opslaan vraagt om je wachtwoord en maakt de tabellen op de nieuwe plek aan.')) ?>
        <div id="dbconn-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="server" class="card need-admin" id="server-card">
        <h2>🖧 <?= th('Server en PHP') ?></h2>
        <div id="server-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="syscheck" class="card need-admin" id="syscheck-card">
        <h2>🩺 <?= th('Systeemcheck') ?> <button type="button" class="btn small-btn" id="syscheck-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <div id="syscheck-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <!-- 1.151.0: eerst op de pagina Gezondheid -->
      <div data-sk="cron" class="card need-admin" id="cron-card">
        <h2>⏱️ Cron</h2>
        <div id="cron-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div data-sk="dbuse" class="card need-admin" id="dbuse-card">
        <h2>📊 <?= th('Gebruik van de database') ?></h2>
        <div id="dbuse-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div data-sk="perf" class="card need-admin" id="perf-card">
        <h2>🚀 <?= th('Prestaties') ?> <button type="button" class="btn small-btn" id="perf-reload" title="<?= th('Vernieuwen') ?>">🔄</button></h2>
        <div id="perf-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>
      <div data-sk="errors" class="card need-admin" id="errors-card">
        <h2>⚠️ <?= th('PHP-fouten (24 uur)') ?></h2>
        <div id="errors-body"><p class="muted"><?= th('Laden…') ?></p></div>
      </div>

      <div data-sk="db" class="card need-admin" id="db-card">
        <h2>🔑 <?= th('Databasewachtwoord') ?></h2>
        <?= xp(t('Maak met één klik een nieuw, sterk wachtwoord voor de database.'), t('RzMon gebruikt een eigen databasegebruiker. Met de knop maak je een nieuw, sterk wachtwoord en slaat RzMon het overal op. Alles blijft gewoon werken: dashboards, cron, API en agents.')) ?>
        <p class="small" id="db-status">…</p>
        <button type="button" class="btn" id="db-rotate"><?= th('🔑 Databasewachtwoord vernieuwen') ?></button>
      </div>

      </div>
      </div>
      </div>
    </section>

<?php if (!is_lan_session()): ?>
    <section id="tab-account" class="tab-panel" hidden>
      <div class="acct-page" data-pgrid="acct">
      <div class="pg-tools" data-pgtools="acct"></div>
      <h1 class="set-title">👤 <?= th('Mijn account') ?></h1>
      <div class="set-crumb muted small"><?= htmlspecialchars((string) $user['username']) ?> · <?= th(ROLE_LABELS[$role] ?? $role) ?></div>
      <div class="card" id="acct-data"><h2><?= th('Gegevens') ?></h2><div id="acct-data-body"><p class="muted"><?= th('Laden…') ?></p></div></div>
      <form id="password-form" class="card">
        <h2>🔑 <?= th('Wachtwoord wijzigen') ?></h2>
        <label><?= th('Huidig wachtwoord') ?><input type="password" name="current" autocomplete="current-password" required></label>
        <label><?= th('Nieuw wachtwoord') ?><input type="password" name="new" minlength="8" autocomplete="new-password" required></label>
        <button class="btn"><?= th('Wijzigen') ?></button>
      </form>
      <div class="card" id="acct-2fa"><h2>🔐 <?= th('Tweestapsverificatie') ?></h2><div id="acct-2fa-body"></div></div>
      <div class="card" id="acct-outside"><h2>🕶️ <?= th('Van buiten en passkeys') ?></h2><div id="acct-outside-body"></div></div>
      <div class="card" id="acct-devices"><h2>📱 <?= th('Ingelogde apparaten') ?></h2><div id="acct-devices-body"></div></div>
      <div class="card" id="acct-logins"><h2>🕘 <?= th('Mijn laatste logins') ?></h2><div id="acct-logins-body"></div></div>
      <div class="card view-card" data-pk="view" data-ids-modern="<?= setting('ids_modern', '1') === '1' ? 'on' : 'off' ?>" data-af-motion="<?= max(0, min(2, (int) setting('af_motion', '2'))) ?>">
        <h2>🖥️ <?= th('Weergave') ?></h2>
        <?= xp(t('Hoe RzMon eruitziet voor jou; wijzigingen zie je meteen.'), t('<b>Wat het is:</b> hoe de pagina\'s eruitzien, direct toegepast. Alles hier geldt voor <b>je account</b>, dus ook op je andere apparaten. <b>Grootte:</b> apart voor je telefoon en je computer, zodat je op een klein scherm iets anders kunt kiezen. <b>◈ CC:</b> alle pagina\'s in de stijl van het Command center, met kaarten die je kunt verslepen (✎ Indeling). <b>Goed om te weten:</b> ook je sortering en de gekozen periode op het Dashboard en bij Analyse worden voor je account onthouden.')) ?>
        <div class="view-row">
          <span class="view-label"><?= th('Bij openen') ?> <span class="hint"><?= th('(voor je account)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Bij openen') ?>">
            <button type="button" class="seg-btn" data-view-open="closed" title="<?= th('Alle servers en regels dicht, en alle dagen behalve vandaag; wat je openklapt blijft open tot je herlaadt') ?>"><?= th('Alles dicht') ?></button>
            <button type="button" class="seg-btn" data-view-open="smart" title="<?= th('Vandaag en ernstige dingen open; opengeklapte servers worden onthouden') ?>"><?= th('Slim openen') ?></button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Lay-out') ?></span>
          <div class="seg" role="group" aria-label="<?= th('Lay-out') ?>">
            <button type="button" class="seg-btn" data-view-layout="blox" title="<?= th('Een tegel per server') ?>">▦ Blox</button>
            <button type="button" class="seg-btn" data-view-layout="l1nes" title="<?= th('Servers onder elkaar, als een tabel') ?>">☰ L1nes</button>
            <button type="button" class="seg-btn" data-view-layout="compact" title="<?= th('Eén blokje per check: veel checks tegelijk, of voor een TV-scherm') ?>">▪ Compact</button>
            <button type="button" class="seg-btn" data-view-layout="cc" title="<?= th('Alles in de stijl van het Command center, met versleepbare kaarten') ?>">◈ CC</button>
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

  <dialog id="modal">
    <form method="dialog" class="modal-inner" id="modal-form">
      <header><h3 id="modal-title"></h3><button class="icon-btn" value="cancel" formnovalidate>✕</button></header>
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