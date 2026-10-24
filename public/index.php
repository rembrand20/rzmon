<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

// Niet ingesteld of database onbereikbaar → naar de setup
if (!is_configured()) {
    header('Location: setup.php');
    exit;
}
// Na een update (nieuwe VERSION) de database automatisch bijwerken
$schemaError = Installer::ensureSchema();

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
$v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="<?= I18n::lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <?php if ($resetToken): ?><meta name="referrer" content="no-referrer"><?php endif; ?>
  <title>RzMon</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📡</text></svg>">
  <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
  <script>try{const t=localStorage.getItem('rzmon-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body data-role="<?= htmlspecialchars($role) ?>">
<?php if ($resetToken): ?>
  <main class="login-wrap">
    <form id="reset-form" class="card login-card" data-token="<?= htmlspecialchars($resetToken) ?>">
      <div class="brand big"><span class="logo">📡</span> RzMon</div>
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
      <div class="brand big"><span class="logo">📡</span> RzMon</div>
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
      <div class="brand big"><span class="logo">📡</span> RzMon <span class="version" title="<?= th('RzMon-versie') ?>">v<?= htmlspecialchars(RZMON_VERSION) ?></span></div>
      <?php if (!Https::isHttps()): $toHttps = Https::adminUrl(); ?>
        <div class="http-bar<?= Https::loginBlocked() ? ' blocked' : '' ?>">🔓 <?= Https::loginBlocked()
            ? th('Inloggen via http staat uit: je wachtwoord zou leesbaar over het netwerk gaan.')
            : th('Deze verbinding is niet beveiligd: je wachtwoord en sessie zijn leesbaar op het netwerk.') ?>
          <?php if ($toHttps !== ''): ?><a href="<?= htmlspecialchars($toHttps) ?>/"><?= th('Open {url}', ['url' => $toHttps]) ?></a><?php endif; ?></div>
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
      <p class="small login-links"><a href="#" id="forgot-link"><?= th('Wachtwoord vergeten?') ?></a></p>
      <?php if (lan_login_allowed()): ?>
        <p class="small" style="text-align:center;margin:.9rem 0 0"><a href="index.php?lan=1"><?= th('Verder zonder inloggen (alleen lezen)') ?></a></p>
      <?php endif; ?>
    </form>
    <form id="forgot-form" class="card login-card" hidden>
      <div class="brand big"><span class="logo">📡</span> RzMon</div>
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
    <div class="brand"><span class="logo">📡</span> RzMon <a class="version" href="https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md" target="_blank" rel="noopener" title="<?= th('RzMon-versie – bekijk wat er nieuw is') ?>">v<?= htmlspecialchars(RZMON_VERSION) ?></a>
      <button type="button" class="update-badge need-admin" id="update-badge" hidden title="<?= th('Er is een nieuwe versie van RzMon: klik om bij te werken') ?>"></button></div>
    <nav class="tabs main-tabs" role="tablist" id="main-tabs">
      <span class="tab-ind" aria-hidden="true"></span>
      <button class="tab active" data-tab="dashboard" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg><span class="tab-badge" id="dash-badge" hidden></span></span><span class="tab-lbl"><?= th('Dashboard') ?></span></button>
      <button class="tab" data-tab="alerts" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><span class="tab-badge" id="alerts-badge" hidden></span></span><span class="tab-lbl"><?= th('Meldingen') ?></span></button>
      <button class="tab" data-tab="ids" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg><span class="tab-badge" id="ids-badge" hidden></span></span><span class="tab-lbl">IDS</span></button>
      <?php if ($showVulns || $showLeaks): ?><button class="tab" data-tab="vulns" role="tab"><span class="tab-icw"><svg class="tab-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 2 1.9 1.9M16 2l-1.9 1.9"/><path d="M9 7.1V6a3 3 0 1 1 6 0v1.1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v-9M6.5 13H3M21 13h-3.5M6 17l-2.5 2M18 17l2.5 2M6 9 3.5 7.5M18 9l2.5-1.5"/></svg><span class="tab-badge" id="vuln-badge" hidden></span></span><span class="tab-lbl has-s"><?= th('Kwetsbaarheden') ?></span><span class="tab-lbl-s"><?= th('CVE\'s') ?></span></button><?php endif; ?>
    </nav>
    <div class="top-actions">
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
          <div class="acct-row">🌐 <?= th('Taal') ?> <span class="seg" id="lang-seg"><button type="button" data-lang-set="nl" class="<?= I18n::lang() === 'nl' ? 'active' : '' ?>">NL</button><button type="button" data-lang-set="en" class="<?= I18n::lang() === 'en' ? 'active' : '' ?>">EN</button></span></div>
          <?php if ($hasAccount): ?><div class="acct-sep"></div><button type="button" role="menuitem" id="logout" class="danger-txt">⏻ <?= th('Uitloggen') ?></button><?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <main class="container">
    <section id="tab-dashboard" class="tab-panel">
<?php if ($schemaError): ?>
      <div class="schema-banner">⚠ <?= htmlspecialchars($schemaError) ?> — <?= t('controleer de databaserechten of voer {cmd} uit.', ['cmd' => '<code>php install/install.php</code>']) ?></div>
<?php endif; ?>
      <div class="maint-banner" id="maint-banner" hidden></div>
      <!-- 1.66.0: tellers onder de header (blijven staan), kerncijfers, "Nu aandacht nodig", werkbalk -->
      <div class="dash-counters" id="dash-counters">
        <div class="dash-in">
          <div class="health-counts" id="health-counts" role="group" aria-label="<?= th('Filter op status') ?>"></div>
          <span class="dash-last muted small" id="last-run"></span>
        </div>
      </div>
      <div class="kpis" id="health"></div>
      <section class="attn-sec" id="dash-attn"></section>
      <div class="dash-bar">
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
      </div>
      <div id="hosts" class="hosts"></div>
    </section>

    <section id="tab-alerts" class="tab-panel" hidden>
      <!-- 1.67.0: zelfde opbouw als het dashboard -->
      <div class="dash-counters"><div class="dash-in">
        <span class="dash-lbl muted small"><?= th('Vandaag') ?></span>
        <div class="health-counts" id="alerts-counts" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-last muted small" id="alerts-info"></span>
      </div></div>
      <div class="kpis" id="alerts-kpis"></div>
      <div class="dash-bar">
        <input type="search" id="alerts-search" placeholder="<?= th('🔍 Zoek in meldingen…') ?>">
        <div class="dash-chips" id="alerts-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="alerts-host" aria-label="<?= th('Server') ?>"></select>
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
      </div>
      <div id="alerts" class="alert-list"></div>
    </section>

    <section id="tab-ids" class="tab-panel" hidden>
      <!-- 1.67.0: zelfde opbouw als het dashboard -->
      <div class="dash-counters"><div class="dash-in">
        <div class="health-counts" id="ids-counts"></div>
        <span class="dash-last muted small" id="ids-last"></span>
      </div></div>
      <div class="kpis" id="ids-kpis"></div>
      <div class="srv-list" id="ids-hosts"></div>
      <div class="subtabs ids-tabs" role="tablist" aria-label="IDS" id="ids-tabs">
        <button type="button" class="seg-btn active" data-it="inc"><?= th('⚠️ Incidenten') ?> <span class="it-n" id="it-n-inc"></span></button>
        <button type="button" class="seg-btn" data-it="acc"><?= th('🔑 Toegang') ?> <span class="it-n" id="it-n-acc"></span></button>
        <button type="button" class="seg-btn" data-it="blk"><?= th('⛔ Blokkades') ?> <span class="it-n" id="it-n-blk"></span></button>
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
          <span class="dash-sp"></span>
          <span class="muted small" id="acc-info"></span>
          <select id="acc-host" class="alerts-sort" aria-label="Server"><option value=""><?= th('Alle servers') ?></option></select>
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
      <div id="vuln-banner"></div>
      <div class="kpis" id="vuln-kpis"></div>
      <div class="dash-bar">
        <input type="search" id="vuln-search" placeholder="<?= th('🔍 Zoek pakket, CVE of server…') ?>" autocomplete="off">
        <div class="dash-chips" id="vuln-filter" role="group" aria-label="<?= th('Filter') ?>"></div>
        <span class="dash-sp"></span>
        <select id="vuln-sev" aria-label="<?= th('Ernst') ?>">
          <option value="0"><?= th('Alle ernst') ?></option><option value="2"><?= th('Vanaf medium') ?></option>
          <option value="3"><?= th('Vanaf high') ?></option><option value="4"><?= th('Alleen critical') ?></option>
        </select>
        <button type="button" class="btn need-admin" id="vuln-scan">🔍 <?= th('Nu scannen') ?></button>
      </div>
      <div id="vuln-hosts"></div>
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
        </div>
        <div id="leak-users"></div>
        <div id="leak-list"></div>
      </div>
      <?php endif; ?>
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
      <h1 class="set-title" id="set-title"></h1>
      <div class="set-crumb muted small"><span id="set-crumb"></span> <span class="autosave-state" id="save-state" aria-live="polite"></span></div>
      <div data-sk="overview" class="card set-overview" id="set-overview"></div>
      <div class="set-toolbar">
        <span class="muted small"><?= th('Klik op een onderdeel om het te openen of te sluiten.') ?></span>
        <button type="button" class="btn" id="set-toggle-all"><?= th('⊞ Alles openen') ?></button>
      </div>
      <h3 class="set-group" data-group="meldingen"><?= th('🔔 Meldingen') ?></h3>
      <h3 class="set-group" data-group="monitoring"><?= th('📈 Monitoring') ?></h3>
      <h3 class="set-group" data-group="systeem"><?= th('⚙️ Systeem') ?></h3>
      <h3 class="set-group" data-group="toegang"><?= th('🔐 Toegang') ?></h3>
      <form id="settings-form" class="settings-grid need-admin">
        <div data-sk="pushover" class="card">
          <h2>Pushover</h2>
          <p class="muted small"><?= t('Maak een applicatie aan op <a href="https://pushover.net/apps/build" target="_blank" rel="noopener">pushover.net</a> voor het API-token. Je user key staat op je Pushover dashboard.') ?></p>
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

        <div data-sk="notify" class="card notify-card">
          <h2><?= th('🔔 Meldingen') ?></h2>
          <p class="muted small"><?= t('Per status: <b>nooit</b>, <b>direct</b>, of pas <b>na X minuten</b> onafgebroken in die status. Herstelt het eerder, dan komt er geen melding. Hersteld: pas melden als het X minuten goed blijft. Per check kun je dit aanpassen (✎ → Meldingen).') ?></p>
          <label><?= th('Melden na … mislukte checks achter elkaar') ?> <span class="muted small"><?= th('(1 = bij de eerste; per check aan te passen)') ?></span>
            <input type="number" name="notify_fails" min="1" max="100" style="max-width:8rem"></label>
          <p class="muted small" style="margin-top:-.4rem"><?= t('Telt per status: wordt een warning critical, dan begint de telling opnieuw. Onbekend telt ook. Het aantal én de regel hieronder moeten allebei kloppen; wil je alleen het aantal, zet de regel dan op <i>direct</i>. Herstel wordt gemeld zoals hieronder ingesteld.') ?></p>
          <label class="switch"><input type="checkbox" name="notify_unknown"><span></span> <?= th('Melden bij ONBEKEND') ?></label>
          <label><?= th('Taal van meldingen en checkberichten') ?> <span class="muted small">(<?= th('Pushover, e-mail, Home Assistant en de berichten van checks; voor iedereen hetzelfde') ?>)</span>
            <select name="notify_lang" style="max-width:12rem"><option value="nl">Nederlands</option><option value="en">English</option></select></label>
          <div id="notify-editor"></div>
          <input type="hidden" name="notify_rules"><input type="hidden" name="notify_channels"><input type="hidden" name="notify_flap"><input type="hidden" name="notify_quiet">
          <div class="quiet-box">
            <h3><?= th('🌙 Stille uren') ?></h3>
            <p class="muted small"><?= t('In deze periodes gaan er geen meldingen uit (Pushover, e-mail en Home Assistant). Checks draaien gewoon door en het dashboard blijft alles tonen. Is er na afloop nog iets mis, dan krijg je <b>één bericht</b> met alle open problemen. Wat in de tussentijd vanzelf herstelde, wordt niet gemeld. Per check aan te passen (✎ → Stille uren).') ?></p>
            <div id="quiet-editor"></div>
          </div>
          <div class="row2">
            <label><?= th('Meldingen per server bundelen binnen … seconden') ?> <span class="muted small"><?= th('(0 = niet bundelen)') ?></span><input type="number" name="notify_group_seconds" min="0" max="3600"></label>
            <label class="switch" style="align-self:end"><input type="checkbox" name="notify_recovery_acked"><span></span> <?= th('Ook herstel melden na een bevestigde storing') ?></label>
          </div>
          <p class="muted small" style="margin-top:-.3rem"><?= th('Bundelen: gaan op één server binnen deze tijd meerdere checks mis (of herstellen ze), dan komt er één bericht met alles erin. De eerste melding wacht daardoor hooguit zo lang. Home Assistant krijgt wel een bericht per check, voor je automatiseringen.') ?></p>
        </div>

        <div data-sk="sysinfo" class="card" id="sysinfo-card">
          <h2>ℹ️ <?= th('Systeeminfo') ?></h2>
          <p class="muted small"><?= t('Meldingen van RzMon zelf (geen checks): per melding waar hij heen gaat. Tijdens <b>stille uren</b> gaat er niets naar buiten, maar komt hij wel op de meldingenpagina. Pushover: <i>stil</i> (zonder geluid) of <i>normaal</i>. Met 🧪 stuur je een voorbeeld naar de aangevinkte kanalen. Infomeldingen van checks stel je in bij de rij <i>Info (checks)</i> hierboven, of per check.') ?></p>
          <div class="table-scroll"><table class="sysinfo-table" id="sysinfo"></table></div>
          <input type="hidden" name="sysinfo">
        </div>

        <div data-sk="alarm" class="card">
          <h2><?= th('Alarmgedrag') ?></h2>
          <label><?= th('Agent offline na … minuten zonder rapport') ?><input type="number" name="agent_stale_minutes" min="1"></label>
          <label><?= th('Historie bewaren (dagen)') ?><input type="number" name="retention_days" min="1"></label>
          <label><?= th('Meldingen bewaren (dagen)') ?> <span class="muted small"><?= th('(de pagina Meldingen toont ze per dag)') ?></span><input type="number" name="alerts_days" min="1" max="3650"></label>
          <label><?= th('Waarschuwen als een disk binnen … dagen vol raakt') ?> <span class="muted small"><?= th('(0 = uit)') ?></span><input type="number" name="disk_forecast_days" min="0" max="365"></label>
          <details class="adv"><summary><?= th('Geavanceerd') ?></summary>
          <label><?= th('Logbestanden: treffers tellen over de laatste … minuten') ?> <span class="muted small">(5–1440)</span><input type="number" name="log_window" min="5" max="1440"></label>
          <label class="switch"><input type="checkbox" name="auto_maint_enabled"><span></span> <?= th('Automatisch onderhoud na herhaalde meldingen') ?></label>
          <label><?= th('… na zoveel meldingen voor dezelfde storing') ?> <span class="muted small"><?= th('(alleen die check; stopt vanzelf als hij weer OK is)') ?></span><input type="number" name="auto_maint_after" min="1" max="100"></label>
          </details>
        </div>

        <div data-sk="webhook" class="card">
          <h2><?= th('Home Assistant-webhook') ?></h2>
          <p class="muted small"><?= t('Stuurt elke melding ook als JSON naar Home Assistant, zodat je er een automatisering op kunt starten: een lamp die rood kleurt, een TTS-melding, of een notificatie via de HA-app. Maak in HA een automatisering met trigger <b>Webhook</b> en plak de URL hieronder, bv. <code>http://homeassistant.local:8123/api/webhook/rzmon-…</code>') ?></p>
          <label class="switch"><input type="checkbox" name="ha_webhook_enabled"><span></span> <?= th('Webhook aan') ?></label>
          <label><?= th('Webhook-URL') ?><input name="ha_webhook_url" type="url" spellcheck="false" placeholder="http://homeassistant.local:8123/api/webhook/rzmon-geheim-id"></label>
          <p class="muted small"><?= t('Welke meldingen naar Home Assistant gaan, stel je in bij <b>🔔 Meldingen</b> (kolom Home Assistant).') ?></p>
          <label class="switch"><input type="checkbox" name="ha_webhook_verify_ssl"><span></span> <?= th('SSL-certificaat controleren (uit bij self-signed)') ?></label>
          <button type="button" class="btn" id="webhook-test"><?= th('🏠 Testbericht naar Home Assistant') ?></button>
        </div>

        <div data-sk="email" class="card">
          <h2>E-mail</h2>
          <p class="muted small"><?= t('Meldingen ook per e-mail, of alleen als reserve wanneer Pushover niet werkt. Bij Gmail, Outlook en iCloud heb je een <b>app-wachtwoord</b> nodig (niet je gewone wachtwoord).') ?></p>
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

        <div data-sk="mqtt" class="card">
          <h2><?= th('MQTT (Home Assistant)') ?></h2>
          <p class="muted small"><?= t('RzMon meldt zich via <b>MQTT discovery</b> bij Home Assistant: elke server wordt een apparaat met sensoren voor de status en meetwaarden van zijn checks. Je hebt een MQTT-broker nodig, bv. de <i>Mosquitto broker</i>-add-on, en de MQTT-integratie in HA. Gebruik bij Mosquitto een HA-gebruiker (of een aparte gebruiker) als login.') ?></p>
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

        <div data-sk="ids" class="card" id="ids-card">
          <h2>🛡️ IDS</h2>
          <p class="muted small"><?= t('Intrusion detection via de agents: gewijzigde gevoelige bestanden, nieuwe gebruikers en poorten, verdachte processen en verbindingen, brute force en inbraak na brute force. Resultaten op de pagina <i>🛡️ IDS</i>; critical ook via Pushover (ook tijdens stille uren).') ?></p>
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
          <label><?= th('Nooit blokkeren') ?> <span class="muted small"><?= th('(IP-adressen of netwerken; je LAN en RzMon zelf worden nooit automatisch geblokkeerd)') ?></span>
            <textarea name="ids_allow" rows="2" placeholder="<?= th('bv. 203.0.113.10 198.51.100.0/24') ?>"></textarea></label>
          <h3 class="set-sub"><?= th('📁 Bestanden bewaken') ?></h3>
          <p class="muted small" style="margin-top:-.2rem"><?= t('De agent houdt van deze paden de SHA-256-hash, grootte, rechten en eigenaar bij en meldt nieuwe, gewijzigde en verwijderde bestanden (nooit de inhoud). Mappen worden helemaal doorzocht; met jokertekens kies je bestanden, bv. <code>/var/www/**/*.php</code>. Geldt voor alle servers; per server voeg je er paden aan toe via <i>✎ Bewerken</i>. Maximaal 20 paden en 10.000 bestanden per server.') ?></p>
          <div id="fim-editor"></div>
          <input type="hidden" name="ids_fim">
          <label><?= th('Uitsluiten') ?> <span class="muted small"><?= t('(namen als <code>*.log</code>, mappen als <code>cache/</code>, of paden; gescheiden door spaties)') ?></span>
            <input name="ids_fim_exclude" class="mono" placeholder="*.log *.tmp cache/"></label>
          <h3 class="set-sub"><?= th('🔑 Toegang') ?></h3>
          <label class="switch"><input type="checkbox" name="auth_events"><span></span> <?= t('Toegang bijhouden: logins, <code>sudo</code> en <code>su</code>') ?></label>
          <p class="muted small" style="margin-top:-.4rem"><?= t('Via de agent: inloggen via SSH en de console, <code>sudo</code> en <code>su</code>, ook mislukte pogingen (per IP-adres per uur opgeteld). Op de pagina <i>🛡️ IDS → Toegang</i>; er gaat geen melding uit. Werkt ook als het IDS uit staat. Op een server zelf uit te zetten met <code>AUTH_EVENTS=0</code>.') ?></p>
          <label><?= th('Negeren') ?> <span class="muted small"><?= th('(gebruikers en/of IP-adressen of netwerken, gescheiden door spaties)') ?></span>
            <textarea name="auth_ignore" rows="2" placeholder="<?= th('bv. ansible backup 192.168.2.5 10.0.0.0/8') ?>"></textarea></label>
        </div>

        <div data-sk="vulns" class="card" id="vulns-card">
          <h2>🐞 <?= th('Kwetsbaarheden') ?></h2>
          <p class="muted small"><?= t('De agents (1.47.0 of nieuwer) sturen hun softwarelijst mee; voor de RzMon-server leest RzMon hem zelf. RzMon zoekt daar de bekende kwetsbaarheden (CVE\'s) bij op <b>OSV.dev</b>, met de adviezen van de distributie zelf, plus <i>actief misbruikt</i> (CISA) en de kans op misbruik (EPSS). Naar OSV.dev gaan alleen pakketnamen, versies en de distributie, geen servernamen of IP-adressen. Ondersteund: Debian, Ubuntu, Rocky Linux, AlmaLinux (en RHEL/CentOS via AlmaLinux) en Alpine.') ?></p>
          <div data-nodirty><label class="switch"><input type="checkbox" id="vuln-enabled"><span></span> <?= t('<b>Kwetsbaarheden aan</b> (tabblad 🐞 Kwetsbaarheden)') ?></label></div>
          <div class="row2">
            <label><?= th('Dagelijks scannen om') ?><input type="time" name="vuln_time"></label>
            <label><?= th('Tonen vanaf') ?>
              <select name="vuln_min_severity"><option value="0"><?= th('alles') ?></option><option value="1">low</option><option value="2">medium</option><option value="3">high</option><option value="4">critical</option></select></label>
          </div>
          <input type="hidden" name="vuln_exclude">
          <div class="vuln-exclude"><span class="muted small"><?= th('Niet scannen:') ?></span> <span id="vuln-exclude-list"></span></div>
          <p class="muted small"><?= t('Nodig: uitgaand HTTPS naar <code>api.osv.dev</code>, <code>www.cisa.gov</code>, <code>api.first.org</code> en <code>services.nvd.nist.gov</code>. Een ℹ️ info-melding bij een nieuwe critical of actief misbruikte kwetsbaarheid volgt de rij Info bij 🔔 Meldingen. Bijwerken via de agent kan als commando\'s als root aan staan voor die server; anders krijg je het commando om zelf uit te voeren.') ?></p>
          <div class="btn-row"><button type="button" class="btn" id="vuln-scan-now">🔍 <?= th('Nu scannen') ?></button> <span class="muted small" id="vuln-scan-info"></span></div>
        </div>

        <div data-sk="update" class="card" id="update-card">
          <h2><?= th('⬆ RzMon bijwerken') ?></h2>
          <p class="small" id="update-status">…</p>
          <div id="update-notes"></div>
          <div id="update-progress" hidden></div>
          <div class="update-actions">
            <button type="button" class="btn primary" id="update-start" hidden><?= th('⬆ Nu bijwerken') ?></button>
            <button type="button" class="btn" id="update-check"><?= th('🔍 Nu controleren') ?></button>
          </div>
          <label class="switch" style="margin-top:.9rem"><input type="checkbox" name="update_backup"><span></span> <?= th('Eerst een back-up maken') ?></label>
          <p class="muted small" style="margin-top:-.4rem"><?= t('Database en instellingen, in de lijst onder <i>Back-up</i>. Geldt voor de knop en voor automatisch bijwerken.') ?></p>
          <label class="switch"><input type="checkbox" name="update_auto"><span></span> <?= th('Automatisch bijwerken') ?></label>
          <label class="update-time"><?= th('Elke dag om') ?> <input type="time" name="update_time"> <?= th('als er een nieuwe versie is') ?></label>
          <p class="muted small" style="margin-top:-.2rem"><?= t('RzMon kijkt één keer per dag op GitHub of er een nieuwe versie is. Bijwerken doet de hulpdienst op de server: eerst eventueel een back-up, dan de nieuwe versie ophalen en het installatiescript uitvoeren, net als het commando met <code>install-rocky.sh</code>. Je instellingen en gegevens blijven staan. De agents werk je apart bij (hieronder).') ?></p>
        </div>

        <div data-sk="agents" class="card" id="agents-card">
          <h2><?= th('⬆ Agents bijwerken') ?></h2>
          <p class="muted small"><?= t('Bij een nieuwe versie van RzMon kun je de agents vanaf hier bijwerken: per server in het venster <i>Agent Manager</i>, of allemaal tegelijk. De agent haalt de nieuwe versie bij RzMon op, controleert hem en installeert hem zelf; zijn instellingen blijven staan. De eerste installatie gaat met het commando op de server.') ?></p>
          <p class="small" id="agents-status">…</p>
          <button type="button" class="btn" id="agents-update-all" hidden><?= th('⬆ Alle verouderde agents bijwerken') ?></button>
          <label class="switch" style="margin-top:.9rem"><input type="checkbox" name="agent_auto_update"><span></span> <?= th('Agents automatisch bijwerken') ?></label>
          <label style="margin-top:.9rem"><?= th('⏱ Agents rapporteren standaard elke') ?>
            <select name="agent_tick"><option value="30"><?= th('30 seconden') ?></option><option value="60"><?= th('60 seconden') ?></option><option value="120"><?= th('2 minuten') ?></option><option value="300"><?= th('5 minuten') ?></option></select>
            <span class="muted small"><?= t('Per server anders in te stellen in de <i>Agent Manager</i>. 30 s werkt met de systemd-timer (agent 1.41.0+); via cron of op TrueNAS minimaal elke minuut.') ?></span></label>
          <div class="cmd-box" data-nodirty>
            <h3><?= th('🖥️ Commando-checks') ?></h3>
            <p class="muted small"><?= t('Met een <i>Commando</i>-check voert de agent een commando uit en vergelijkt RzMon de uitvoer met het antwoord dat je verwacht. Standaard staat dit uit. Aanzetten vraagt om je wachtwoord en komt in het logboek. Op een server zelf blokkeer je het altijd met <code>COMMANDS=0</code> (of <code>COMMANDS=nobody</code>) in de instellingen van de agent.') ?></p>
            <label><?= th('Commando\'s op agents') ?>
              <select id="agent-commands"><option value="off"><?= th('Uit') ?></option><option value="nobody"><?= th('Toestaan, als gebruiker nobody') ?></option><option value="root"><?= th('Toestaan, als root') ?></option></select></label>
            <p class="small" id="agent-commands-hosts"></p>
            <details class="small api-docs"><summary><?= th('Logboek') ?></summary><div id="audit-log"></div></details>
          </div>
          <p class="muted small" style="margin-top:-.4rem"><?= t('Verouderde agents werken zichzelf bij zonder dat je op de knop drukt (hooguit één poging per 6 uur). Home Assistant-add-ons werk je bij via de add-on winkel. Een server uitzonderen: <code>REMOTE_UPDATE=0</code> in <code>/etc/default/rzmon-agent</code>.') ?></p>
        </div>

        <div data-sk="backup" class="card" id="backup-card">
          <h2><?= th('💾 Back-up') ?></h2>
          <p class="muted small"><?= t('Een zip met de hele database (servers, checks, instellingen, gebruikers) en <code>config.php</code>. De back-ups staan op deze server, buiten de webmap. Download er af en toe een, zodat je ook een kopie hebt als de server zelf stuk gaat.') ?></p>
          <div class="row3">
            <label><?= th('Automatisch') ?>
              <select name="backup_freq">
                <option value="off"><?= th('Uit') ?></option><option value="daily"><?= th('Elke dag') ?></option>
                <option value="weekly"><?= th('Elke week') ?></option><option value="monthly"><?= th('Elke maand (de 1e)') ?></option>
              </select>
            </label>
            <label><?= th('Om') ?><input type="time" name="backup_time"></label>
            <label><?= th('Bewaren') ?> <span class="hint"><?= th('(aantal)') ?></span><input type="number" name="backup_keep" min="1" max="365"></label>
          </div>
          <label class="backup-weekday"><?= th('Op') ?>
            <select name="backup_weekday">
              <option value="1"><?= th('maandag') ?></option><option value="2"><?= th('dinsdag') ?></option><option value="3"><?= th('woensdag') ?></option><option value="4"><?= th('donderdag') ?></option>
              <option value="5"><?= th('vrijdag') ?></option><option value="6"><?= th('zaterdag') ?></option><option value="7"><?= th('zondag') ?></option>
            </select>
          </label>
          <label class="switch"><input type="checkbox" name="backup_history"><span></span> <?= th('Meethistorie meenemen') ?></label>
          <p class="muted small" style="margin-top:-.4rem"><?= th('De meetresultaten achter de grafieken. Uit = een veel kleinere zip; bij terugzetten blijft de huidige historie dan staan.') ?></p>
          <label><?= th('Zip-wachtwoord') ?> <span class="hint"><?= th('(minstens 8 tekens; versleutelt met AES-256)') ?></span><input name="backup_pass" type="password" autocomplete="new-password"></label>
          <p class="muted small" style="margin-top:-.4rem"><?= t('Nodig om een zip te openen (met 7-Zip) of terug te zetten op een andere server. <b>Bewaar het goed</b>: zonder wachtwoord is de back-up onbruikbaar. Wijzigen geldt voor nieuwe back-ups.') ?></p>
          <p class="small" id="backup-status">…</p>
          <div class="btn-row">
            <button type="button" class="btn" id="backup-now"><?= th('💾 Nu back-up maken') ?></button>
            <button type="button" class="btn" id="backup-upload"><?= th('⬆ Zip uploaden') ?></button>
            <input type="file" id="backup-file" accept=".zip,application/zip" hidden>
          </div>
          <div id="backup-list" class="key-list"></div>
        </div>

      </form>

      <div class="settings-grid" style="margin-top:1.25rem">
      <form data-sk="access" class="card need-admin" id="access-form">
        <h2><?= th('Toegang') ?></h2>
        <div class="ip-status" id="ip-status">…</div>
        <label class="switch"><input type="checkbox" name="lan_no_login"><span></span> <?= th('Geen login nodig op het lokale netwerk') ?></label>
        <label class="switch"><input type="checkbox" name="lan_as_admin"><span></span> <?= t('Op het lokale netwerk als <b>beheerder</b> inloggen') ?></label>
        <p class="muted small" style="margin-top:-.3rem"><?= t('Uit: op het lokale netwerk kom je binnen als <b>alleen lezen</b>, en met <i>Inloggen</i> rechtsboven krijg je de rechten van je eigen account. Van buitenaf is altijd een gebruikersnaam en wachtwoord nodig.') ?></p>
        <label><?= th('Lokale netwerken') ?> <span class="hint"><?= th('(één per regel, bv. 192.168.1.0/24)') ?></span>
          <textarea name="trusted_networks" rows="5" spellcheck="false"></textarea></label>
        <details class="adv"><summary><?= th('Geavanceerd: reverse proxy') ?></summary>
        <label><?= th('Reverse proxy IP\'s') ?> <span class="hint"><?= th('(optioneel — alleen invullen als RzMon achter Nginx Proxy Manager, Traefik, Cloudflare tunnel e.d. staat)') ?></span>
          <textarea name="trusted_proxies" rows="2" spellcheck="false"></textarea></label>
        <p class="callout"><?= t('⚠️ Staat RzMon achter een reverse proxy of port-forward waarbij álle bezoekers het IP van je router/proxy krijgen? Vul dat proxy-IP dan in bij <i>Reverse proxy IP\'s</i> en zet het <b>niet</b> bij lokale netwerken — anders kan iedereen van buitenaf zonder wachtwoord binnen.') ?></p>
        </details>
        <h3 class="set-sub">🔐 <?= th('Inlogbeveiliging') ?></h3>
        <div class="lock-row"><?= th('Na') ?> <input type="number" name="login_lock_fails" min="1" max="50" class="num-in"> <?= th('mislukte pogingen binnen') ?>
          <input type="number" name="login_lock_window" min="1" max="1440" class="num-in"> <?= th('minuten:') ?></div>
        <label class="switch"><input type="checkbox" name="login_lock_account"><span></span> <span><?= t('het <b>account</b> blokkeren') ?></span></label>
        <label class="switch"><input type="checkbox" name="login_lock_ip"><span></span> <span><?= t('het <b>IP-adres</b> blokkeren (niet als het uit je lokale netwerken komt)') ?></span></label>
        <div class="lock-row"><?= th('Blokkade opheffen na') ?> <input type="number" name="login_lock_minutes" min="0" max="525600" class="num-in"> <?= th('minuten') ?>
          <span class="hint"><?= th('(0 = tot een beheerder deblokkeert)') ?></span></div>
        <p class="muted small"><?= t('Een verkeerde 2FA-code telt ook. Pogingen tellen apart per account (vanaf welk IP dan ook) en per IP-adres. Een geblokkeerd account kan vanaf je <b>lokale netwerk</b> gewoon inloggen, zodat niemand van buiten je kan buitensluiten. Je krijgt een ⚠️-melding met Pushover. Deblokkeren: bij <i>Gebruikers</i> (🔓) en bij <i>Blokkades</i>; of op de server <code>sudo php install/install.php --unlock &lt;gebruiker&gt;</code>.') ?></p>
        <p class="form-error" hidden></p>
      </form>

      <div data-sk="https" class="card need-admin" id="https-card">
        <h2>🔒 <?= th('Beheer via https') ?></h2>
        <p class="muted small"><?= t('Open je RzMon via http, dan kan iemand op het netwerk je wachtwoord, je sessie en de pagina zelf (bv. een installatiecommando) zien of veranderen. Met een <b>beheeradres</b> via je reverse proxy (bv. SWAG met Let\'s Encrypt) en <i>Beheer alleen via https</i> gaat de webinterface altijd via https. Agents en de REST API blijven gewoon werken: hun berichten zijn al versleuteld of hebben een eigen sleutel.') ?></p>
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
          <p class="muted small"><?= t('<b>Nooddeur</b>: https op het IP-adres van RzMon zelf (self-signed, kies "toch doorgaan") blijft vanaf je interne netwerken altijd werken, ook als het certificaat van het beheeradres verlopen is. Buitengesloten? Op de server: <code>sudo php install/install.php --https-off</code>.') ?></p>
        </details>
        <p class="form-error" hidden></p>
      </div>

      <div data-sk="hibp" class="card need-admin" id="hibp-card">
        <h2>🔓 <?= th('Datalekken (Have I Been Pwned)') ?></h2>
        <p class="muted small"><?= t('Controleert of <b>wachtwoorden</b> van RzMon-accounts en <b>e-mailadressen</b> in bekende datalekken staan. Wachtwoorden: gratis, en het wachtwoord gaat nooit de deur uit (alleen de eerste 5 tekens van de SHA-1-hash, met opvulling). E-mailadressen: met een API-sleutel van <a href="https://haveibeenpwned.com/API/Key" target="_blank" rel="noopener">haveibeenpwned.com</a>, dagelijks. Per account uit te zetten bij <i>Gebruikers</i> (🔓).') ?></p>
        <label class="switch"><input type="checkbox" id="hibp-on"><span></span> <b><?= th('Controle op datalekken') ?></b></label>
        <div id="hibp-body">
          <h3 class="set-sub">🔑 <?= th('Wachtwoorden') ?></h3>
          <div class="lock-row"><?= th('Gelekt vanaf') ?> <input type="number" id="hibp-threshold" min="1" max="1000000" class="num-in"> <?= th('keer in datalekken') ?>
            <span class="hint"><?= th('(1 = elke treffer)') ?></span></div>
          <p class="muted small" style="margin-top:-.2rem"><?= th('Bij het instellen van een gelekt wachtwoord krijg je een waarschuwing. Wie inlogt met een gelekt wachtwoord, moet eerst een nieuw kiezen; een gelekt wachtwoord wordt daar geweigerd.') ?></p>
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
        <p class="muted small"><?= t('Elke agent (1.50.0 of nieuwer) heeft een <b>eigen sleutel</b>. Daarmee is alles wat hij verstuurt versleuteld en ondertekend (AES-256 met een handtekening), ook het antwoord van RzMon. Er gaat geen token meer over de lijn, en een sleutel nooit leesbaar: een nieuwe agent koppel je met een <b>koppelcode</b> (Agent Manager → 🔗 Koppelcode, eenmalig en 15 minuten geldig). Agents die al met de algemene sleutel werkten, krijgen hun eigen sleutel vanzelf. Een bericht met een verkeerde sleutel, een oude handtekening of van een onbekend IP-adres wordt geweigerd en geeft een ⚠️-melding.') ?></p>
        <div id="conn-ipwait"></div>
        <label><?= th('Versleutelen') ?> <select id="enc-mode"></select></label>
        <label><?= th('Sleutels van de agents vernieuwen') ?>
          <select id="conn-days"><option value="7"><?= th('elke 7 dagen') ?></option><option value="30"><?= th('elke 30 dagen') ?></option><option value="90"><?= th('elke 90 dagen') ?></option><option value="0"><?= th('alleen met de hand') ?></option></select></label>
        <label class="switch"><input type="checkbox" id="conn-pin"><span></span> <?= t('📌 <b>Certificaat van RzMon vastzetten</b> (bij een eigen, self-signed certificaat)') ?></label>
        <p class="muted small" id="conn-pin-info" style="margin-top:-.4rem"></p>
        <label class="switch"><input type="checkbox" id="conn-iplock"><span></span> <?= t('🌐 Agents alleen vanaf hun <b>bekende IP-adres</b>') ?></label>
        <p class="muted small" style="margin-top:-.4rem"><?= t('Het eerste IP-adres van een agent wordt onthouden. Komt hij van een ander adres, dan weigert RzMon hem en krijg je een melding; hierboven kun je het nieuwe adres dan <b>toestaan</b>.') ?></p>
        <div id="enc-hosts"></div>
        <details class="adv"><summary><?= th('Algemene sleutel (agents 1.49.x)') ?></summary>
          <p class="muted small"><?= t('Agents van versie 1.49.x versleutelen met één algemene sleutel. Nieuwere agents gebruiken die alleen nog om over te stappen op hun eigen sleutel.') ?></p>
          <label><?= th('Sleutel') ?> <span class="hint"><?= th('(64 tekens)') ?></span>
            <span class="enc-key-row"><input id="enc-key" type="password" readonly class="mono" placeholder="<?= th('nog geen sleutel') ?>">
              <button type="button" class="btn" id="enc-show" title="<?= th('Tonen') ?>">👁</button>
              <button type="button" class="btn" id="enc-copy" title="<?= th('Kopiëren') ?>">📋</button></span></label>
          <div class="btn-row"><button type="button" class="btn" id="enc-generate">🎲 <?= th('Sleutel genereren') ?></button> <span class="muted small" id="enc-info"></span></div>
        </details>
      </div>

      <div data-sk="blocks" class="card need-admin" id="blocks-card">
        <h2>⛔ <?= th('Blokkades') ?></h2>
        <p class="muted small"><?= t('Een geblokkeerd IP-adres of netwerk krijgt nergens meer toegang tot RzMon: niet de webpagina, niet het inloggen, niet de API en geen agents. Jezelf en de RzMon-server kun je niet blokkeren. Toch buitengesloten? Op de server: <code>sudo php install/install.php --unblock-all</code>') ?></p>
        <div class="block-add"><input id="block-net" class="mono" placeholder="<?= th('bv. 203.0.113.7 of 203.0.113.0/24') ?>" autocomplete="off">
          <input id="block-reason" placeholder="<?= th('reden (optioneel)') ?>" autocomplete="off">
          <button type="button" class="btn danger" id="block-add">⛔ <?= th('Blokkeren') ?></button></div>
        <div id="blocks-list"></div>
      </div>

      <div data-sk="api" class="card need-admin" id="api-card">
        <h2><?= th('API-sleutels') ?></h2>
        <p class="muted small"><?= t('Lees statussen, meetwaarden en het IDS uit via de REST API, bijvoorbeeld vanuit Home Assistant, en blokkeer IP\'s of bevestig storingen vanuit een ander systeem. Per sleutel kies je wat hij mag, eventueel vanaf welke adressen, en hoe lang hij geldig is. Stuur de sleutel mee als <code>Authorization: Bearer &lt;sleutel&gt;</code>.') ?></p>
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
        <p class="muted small need-admin"><?= t('<b>Beheerder</b>: alles. <b>Gebruiker</b>: storingen afhandelen (bevestigen, nu checken, onderhoud, poortscan), maar niets inrichten. <b>Alleen lezen</b>: kijken. Met <b>tweestapsverificatie</b> (TOTP) is naast het wachtwoord een code uit een authenticator-app nodig, zoals Google/Microsoft Authenticator, Authy, Bitwarden of 1Password.') ?></p>
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
      </div>

      <?php if (($user['id'] ?? 0) === 0): ?>
      <?php endif; ?>
      <div data-sk="setup" class="card need-admin" id="setup-card">
        <h2>🛠️ <?= th('Setup') ?></h2>
        <p class="muted small"><?= th('Systeemcheck, database en configuratie, tabellen, tijdzone, basis-URL en PHP-instellingen.') ?></p>
        <a class="btn" href="setup.php">⚙️ <?= th('Setup openen') ?></a>
      </div>

      <div data-sk="db" class="card need-admin" id="db-card">
        <h2><?= th('Database') ?></h2>
        <p class="muted small"><?= th('RzMon gebruikt een eigen databasegebruiker. Met de knop maak je een nieuw, sterk wachtwoord en slaat RzMon het overal op. Alles blijft gewoon werken: dashboards, cron, API en agents.') ?></p>
        <p class="small" id="db-status">…</p>
        <button type="button" class="btn" id="db-rotate"><?= th('🔑 Databasewachtwoord vernieuwen') ?></button>
      </div>

      </div>
      </div>
      </div>
    </section>

<?php if (!is_lan_session()): ?>
    <section id="tab-account" class="tab-panel" hidden>
      <div class="acct-page">
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
      <div class="card" id="acct-devices"><h2>📱 <?= th('Onthouden apparaten') ?></h2><div id="acct-devices-body"></div></div>
      <div class="card" id="acct-logins"><h2>🕘 <?= th('Mijn laatste logins') ?></h2><div id="acct-logins-body"></div></div>
      <div class="card view-card">
        <h2>🖥️ <?= th('Weergave op dit apparaat') ?></h2>
        <p class="muted small"><?= t('Hoe het dashboard en de meldingen eruitzien. Geldt voor <b>dit apparaat</b> (zo kun je op je telefoon iets anders kiezen dan op je pc) en wordt direct toegepast.') ?></p>
        <div class="view-row">
          <span class="view-label"><?= th('Lay-out') ?></span>
          <div class="seg" role="group" aria-label="<?= th('Lay-out') ?>">
            <button type="button" class="seg-btn" data-view-layout="blox" title="<?= th('Een tegel per server') ?>">▦ Blox</button>
            <button type="button" class="seg-btn" data-view-layout="l1nes" title="<?= th('Servers onder elkaar, als een tabel') ?>">☰ L1nes</button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Grootte') ?> <span class="hint"><?= th('(per lay-out)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Grootte') ?>">
            <button type="button" class="seg-btn" data-view-density="small" title="<?= th('Compact: meer servers tegelijk in beeld') ?>">Small</button>
            <button type="button" class="seg-btn" data-view-density="large" title="<?= th('Meer informatie: CPU, RAM, disk, bereikbaarheid') ?>">Large</button>
          </div>
        </div>
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
  <script>window.LANG = <?= json_encode(I18n::lang()) ?>; window.I18N = <?= I18n::lang() === 'en' ? json_encode(I18n::dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : '{}' ?>;</script>
  <script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>