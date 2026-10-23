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
$v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="<?= I18n::lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
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
<?php elseif (!$user): ?>
  <main class="login-wrap">
    <form id="login-form" class="card login-card">
      <div class="brand big"><span class="logo">📡</span> RzMon <span class="version" title="<?= th('RzMon-versie') ?>">v<?= htmlspecialchars(RZMON_VERSION) ?></span></div>
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
  <header class="topbar">
    <div class="brand"><span class="logo">📡</span> RzMon <a class="version" href="https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md" target="_blank" rel="noopener" title="<?= th('RzMon-versie – bekijk wat er nieuw is') ?>">v<?= htmlspecialchars(RZMON_VERSION) ?></a>
      <button type="button" class="update-badge need-admin" id="update-badge" hidden title="<?= th('Er is een nieuwe versie van RzMon: klik om bij te werken') ?>"></button></div>
    <nav class="tabs" role="tablist">
      <button class="tab active" data-tab="dashboard"><?= th('Dashboard') ?></button>
      <button class="tab" data-tab="alerts"><?= th('Meldingen') ?></button>
      <button class="tab" data-tab="ids">🛡️ IDS <span class="tab-badge" id="ids-badge" hidden></span></button>
      <?php if (Vulns::enabled() && $role !== 'readonly'): ?><button class="tab" data-tab="vulns">🐞 <?= th('Kwetsbaarheden') ?> <span class="tab-badge" id="vuln-badge" hidden></span></button><?php endif; ?>
      <button class="tab" data-tab="settings"><?= th('Instellingen') ?></button>
    </nav>
    <div class="top-actions">
      <?php if (is_lan_session()): ?><span class="lan-badge" title="<?= th('Ingelogd zonder wachtwoord omdat je op het lokale netwerk zit') ?>">🏠 <?= th('Lokaal netwerk') ?></span><?php endif; ?>
      <?php if ($role !== 'admin'): ?><span class="role-badge" title="<?= th('Je rechten:') ?> <?= $role === 'user' ? th('storingen afhandelen, niets inrichten') : th('alleen kijken') ?>"><?= $role === 'user' ? '👤 ' . th('Gebruiker') : '👁 ' . th('Alleen lezen') ?></span><?php endif; ?>
      <?php if (is_lan_session()): ?><a class="btn small-btn" href="index.php?login=1" title="<?= th('Inloggen met je eigen account voor je eigen rechten') ?>"><?= th('Inloggen') ?></a><?php endif; ?>
      <button class="icon-btn" id="theme-toggle" title="<?= th('Thema wisselen') ?>">🌓</button>
      <?php if (!is_lan_session()): ?><button class="icon-btn" id="logout" title="<?= th('Uitloggen ({user})', ['user' => $user['username']]) ?>">⏻</button><?php endif; ?>
    </div>
  </header>

  <main class="container">
    <section id="tab-dashboard" class="tab-panel">
<?php if ($schemaError): ?>
      <div class="schema-banner">⚠ <?= htmlspecialchars($schemaError) ?> — <?= t('controleer de databaserechten of voer {cmd} uit.', ['cmd' => '<code>php install/install.php</code>']) ?></div>
<?php endif; ?>
      <div class="maint-banner" id="maint-banner" hidden></div>
      <div class="summary health" id="health">
        <div class="health-main">
          <button class="health-state" id="health-state" type="button" title="<?= th('Klik: alleen problemen tonen') ?>">
            <b id="health-label"><?= th('Laden…') ?></b>
          </button>
          <div class="health-score" title="<?= th('Gezondheidsscore: 100% = alles OK. Een critical telt zwaarder dan een warning, een onbereikbare server het zwaarst.') ?>">
            <b id="health-pct">–</b><span class="meter-bar"><i id="health-bar"></i></span>
          </div>
          <div class="health-counts" id="health-counts" role="group" aria-label="<?= th('Filter op status') ?>"></div>
        </div>
        <div class="health-info" id="health-info"></div>
        <div class="summary-actions">
          <span class="muted small" id="last-run"></span>
          <button class="btn need-user" id="maint-all" title="<?= th('Alle meldingen tijdelijk pauzeren') ?>">🔧 <?= th('Onderhoud') ?></button>
          <button class="btn need-user" id="run-all">↻ <?= th('Alles nu checken') ?></button>
          <button class="btn primary need-admin" id="add-host">＋ <?= th('Server') ?></button>
        </div>
      </div>
      <div class="filter-row">
        <input type="search" id="filter" placeholder="<?= th('Zoek server of check…') ?>">
        <label class="switch small"><input type="checkbox" id="only-problems"><span></span> <?= th('Alleen problemen') ?></label>
        <button class="btn" id="toggle-all" type="button">⊞ <?= th('Alles openen') ?></button>
      </div>
      <div id="hosts" class="hosts"></div>
    </section>

    <section id="tab-alerts" class="tab-panel" hidden>
      <div class="card">
        <div class="alerts-head">
          <h2><?= th('Meldingen') ?></h2>
          <span class="muted small" id="alerts-info"></span>
          <div class="seg" role="group" aria-label="<?= th('Filter') ?>" id="alerts-filter">
            <button type="button" class="seg-btn active" data-af="all"><?= th('Alles') ?></button>
            <button type="button" class="seg-btn" data-af="noinfo"><?= th('Zonder info') ?></button>
            <button type="button" class="seg-btn" data-af="info">ℹ️ Info</button>
          </div>
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
          <button type="button" class="btn" id="alerts-toggle">⊞ <?= th('Alles openen') ?></button>
        </div>
        <div id="alerts" class="alert-list"></div>
      </div>
    </section>

    <section id="tab-ids" class="tab-panel" hidden>
      <div class="card">
        <div class="alerts-head"><h2>🛡️ IDS</h2><span class="muted small" id="ids-status"></span>
          <button type="button" class="btn need-admin" id="ids-block-add" style="margin-left:auto"><?= th('⛔ IP blokkeren') ?></button></div>
        <div class="ids-hosts" id="ids-hosts"></div>
      </div>
      <div class="seg ids-tabs" role="tablist" aria-label="IDS" id="ids-tabs">
        <button type="button" class="seg-btn active" data-it="inc"><?= th('⚠️ Incidenten') ?> <span class="it-n" id="it-n-inc"></span></button>
        <button type="button" class="seg-btn" data-it="acc"><?= th('🔑 Toegang') ?> <span class="it-n" id="it-n-acc"></span></button>
        <button type="button" class="seg-btn" data-it="blk"><?= th('⛔ Blokkades') ?> <span class="it-n" id="it-n-blk"></span></button>
      </div>
      <div class="card ids-pane" data-pane="inc">
        <div class="alerts-head">
          <h2><?= th('⚠️ Incidenten') ?></h2>
          <span class="muted small" id="ids-info"></span>
          <div class="seg" role="group" aria-label="Filter" id="ids-filter">
            <button type="button" class="seg-btn active" data-if="open">Open</button>
            <button type="button" class="seg-btn" data-if="all"><?= th('Alles') ?></button>
          </div>
          <button type="button" class="btn" id="ids-toggle"><?= th('⊞ Alles openen') ?></button>
        </div>
        <div id="ids-incidents" class="alert-list"></div>
      </div>
      <div class="card ids-pane" data-pane="acc" hidden>
        <div class="alerts-head">
          <h2><?= th('🔑 Toegang') ?></h2>
          <span class="muted small" id="acc-info"></span>
          <div class="seg" role="group" aria-label="<?= th('Soort') ?>" id="acc-filter">
            <button type="button" class="seg-btn active" data-ac="all"><?= th('Alles') ?></button>
            <button type="button" class="seg-btn" data-ac="ssh">SSH</button>
            <button type="button" class="seg-btn" data-ac="console">Console</button>
            <button type="button" class="seg-btn" data-ac="sudo">sudo</button>
            <button type="button" class="seg-btn" data-ac="su">su</button>
            <button type="button" class="seg-btn" data-ac="fail"><?= th('❌ Mislukt') ?></button>
          </div>
          <select id="acc-host" class="alerts-sort" aria-label="Server"><option value=""><?= th('Alle servers') ?></option></select>
          <button type="button" class="btn" id="acc-toggle"><?= th('⊞ Alles openen') ?></button>
        </div>
        <div id="ids-access" class="alert-list"></div>
      </div>
      <div class="card ids-pane" data-pane="blk" hidden>
        <div class="alerts-head">
          <h2><?= th('⛔ Blokkades') ?></h2>
          <span class="muted small" id="ids-block-info"></span>
        </div>
        <div id="ids-blocks"></div>
      </div>
    </section>

    <?php if (Vulns::enabled() && $role !== 'readonly'): ?>
    <section id="tab-vulns" class="tab-panel" hidden>
      <div class="card">
        <div class="alerts-head">
          <h2>🐞 <?= th('Kwetsbaarheden') ?></h2>
          <span class="muted small" id="vuln-info"></span>
          <button type="button" class="btn need-admin" id="vuln-scan" style="margin-left:auto">🔍 <?= th('Nu scannen') ?></button>
        </div>
        <div class="vuln-counts" id="vuln-counts"></div>
        <div class="vuln-tools">
          <input type="search" id="vuln-search" placeholder="<?= th('Zoek pakket, CVE of server…') ?>" autocomplete="off">
          <div class="seg" role="group" aria-label="<?= th('Filter') ?>" id="vuln-filter">
            <button type="button" class="seg-btn active" data-vf="all"><?= th('Alles') ?></button>
            <button type="button" class="seg-btn" data-vf="exposed">🌐 <?= th('Bereikbaar') ?></button>
            <button type="button" class="seg-btn" data-vf="fix"><?= th('Met oplossing') ?></button>
            <button type="button" class="seg-btn" data-vf="kev">⚠️ <?= th('Actief misbruikt') ?></button>
            <button type="button" class="seg-btn" data-vf="ignored"><?= th('Geaccepteerd') ?></button>
          </div>
          <select id="vuln-sev" aria-label="<?= th('Ernst') ?>">
            <option value="0"><?= th('Alle ernst') ?></option><option value="2"><?= th('Vanaf medium') ?></option>
            <option value="3"><?= th('Vanaf high') ?></option><option value="4"><?= th('Alleen critical') ?></option>
          </select>
        </div>
      </div>
      <div id="vuln-hosts"></div>
    </section>
    <?php endif; ?>

    <section id="tab-settings" class="tab-panel" hidden>
      <div class="settings-top">
        <div class="lang-switch" role="group" aria-label="Language / Taal" title="Language / Taal">
          <span>🌐 EN</span><label class="switch"><input type="checkbox" id="lang-toggle" <?= I18n::lang() === 'nl' ? 'checked' : '' ?>><span></span></label><span>NL</span>
        </div>
        <?php if ($role === 'admin'): ?><a class="btn" href="setup.php" title="<?= th('Database, configuratie en tabellen') ?>">⚙️ Setup</a><?php endif; ?>
      </div>
      <div class="card view-card">
        <h2><?= th('Weergave') ?></h2>
        <p class="muted small"><?= t('Hoe het dashboard, de meldingen en deze instellingen eruitzien. Geldt voor <b>dit apparaat</b> (zo kun je op je telefoon iets anders kiezen dan op je pc) en wordt direct toegepast.') ?></p>
        <div class="view-row">
          <span class="view-label"><?= th('Lay-out') ?></span>
          <div class="seg" role="group" aria-label="<?= th('Lay-out') ?>">
            <button type="button" class="seg-btn" data-view-layout="blox" title="<?= th('Een tegel per server') ?>">▦ Blox</button>
            <button type="button" class="seg-btn" data-view-layout="l1nes" title="<?= th('Servers onder elkaar, als een tabel') ?>">☰ L1nes</button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label"><?= th('Grootte') ?> <span class="hint"><?= th('(bij L1nes)') ?></span></span>
          <div class="seg" role="group" aria-label="<?= th('Grootte') ?>">
            <button type="button" class="seg-btn" data-view-density="small" title="<?= th('Eén compacte regel per server') ?>">Small</button>
            <button type="button" class="seg-btn" data-view-density="large" title="<?= th('Meer informatie: CPU, RAM, disk, bereikbaarheid') ?>">Large</button>
          </div>
        </div>
      </div>

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

        <div data-sk="alarm" class="card">
          <h2><?= th('Alarmgedrag') ?></h2>
          <label><?= th('Agent offline na … minuten zonder rapport') ?><input type="number" name="agent_stale_minutes" min="1"></label>
          <label><?= th('Historie bewaren (dagen)') ?><input type="number" name="retention_days" min="1"></label>
          <label><?= th('Meldingen bewaren (dagen)') ?> <span class="muted small"><?= th('(de pagina Meldingen toont ze per dag)') ?></span><input type="number" name="alerts_days" min="1" max="3650"></label>
          <label><?= th('Waarschuwen als een disk binnen … dagen vol raakt') ?> <span class="muted small"><?= th('(0 = uit)') ?></span><input type="number" name="disk_forecast_days" min="0" max="365"></label>
          <label><?= th('Logbestanden: treffers tellen over de laatste … minuten') ?> <span class="muted small">(5–1440)</span><input type="number" name="log_window" min="5" max="1440"></label>
          <label class="switch"><input type="checkbox" name="auto_maint_enabled"><span></span> <?= th('Automatisch onderhoud na herhaalde meldingen') ?></label>
          <label><?= th('… na zoveel meldingen voor dezelfde storing') ?> <span class="muted small"><?= th('(alleen die check; stopt vanzelf als hij weer OK is)') ?></span><input type="number" name="auto_maint_after" min="1" max="100"></label>
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
          <label class="switch"><input type="checkbox" name="smtp_verify_ssl"><span></span> <?= th('SSL-certificaat controleren (uit bij self-signed)') ?></label>
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
          <details class="small api-docs">
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
          <label class="switch"><input type="checkbox" name="vuln_enabled"><span></span> <?= t('<b>Kwetsbaarheden aan</b> (tabblad 🐞 Kwetsbaarheden)') ?></label>
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

        <div class="settings-save"><button class="btn primary"><?= th('💾 Instellingen opslaan') ?></button> <span class="save-state" id="save-state"></span></div>
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
        <label><?= th('Reverse proxy IP\'s') ?> <span class="hint"><?= th('(optioneel — alleen invullen als RzMon achter Nginx Proxy Manager, Traefik, Cloudflare tunnel e.d. staat)') ?></span>
          <textarea name="trusted_proxies" rows="2" spellcheck="false"></textarea></label>
        <p class="callout"><?= t('⚠️ Staat RzMon achter een reverse proxy of port-forward waarbij álle bezoekers het IP van je router/proxy krijgen? Vul dat proxy-IP dan in bij <i>Reverse proxy IP\'s</i> en zet het <b>niet</b> bij lokale netwerken — anders kan iedereen van buitenaf zonder wachtwoord binnen.') ?></p>
        <p class="form-error" hidden></p>
        <button class="btn primary"><?= th('Toegang opslaan') ?></button>
      </form>

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
      <div class="card" data-sk="account"><h2><?= th('Account') ?></h2><p class="muted small"><?= t('Je bent automatisch ingelogd via het lokale netwerk, met alleen-lezen-rechten. Klik rechtsboven op <b>Inloggen</b> om met je eigen account in te loggen.') ?></p></div>
      <?php endif; ?>
      <div data-sk="db" class="card need-admin" id="db-card">
        <h2><?= th('Database') ?></h2>
        <p class="muted small"><?= th('RzMon gebruikt een eigen databasegebruiker. Met de knop maak je een nieuw, sterk wachtwoord en slaat RzMon het overal op. Alles blijft gewoon werken: dashboards, cron, API en agents.') ?></p>
        <p class="small" id="db-status">…</p>
        <button type="button" class="btn" id="db-rotate"><?= th('🔑 Databasewachtwoord vernieuwen') ?></button>
      </div>

      <form id="password-form" data-sk="password" class="card" <?= ($user['id'] ?? 0) === 0 ? 'hidden' : '' ?>>
        <h2><?= th('Wachtwoord wijzigen') ?></h2>
        <label><?= th('Huidig wachtwoord') ?><input type="password" name="current" autocomplete="current-password" required></label>
        <label><?= th('Nieuw wachtwoord') ?><input type="password" name="new" minlength="8" autocomplete="new-password" required></label>
        <button class="btn"><?= th('Wijzigen') ?></button>
      </form>
      </div>
    </section>
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