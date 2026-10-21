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
<html lang="nl">
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
      <h2 style="margin:0 0 .6rem">Nieuw wachtwoord</h2>
      <div id="reset-fields" hidden>
        <p class="muted small" style="margin-top:0">Kies een nieuw wachtwoord voor <b id="reset-user"></b>. Daarna worden alle apparaten waarop dit account ingelogd bleef, uitgelogd.</p>
        <input type="text" name="username" autocomplete="username" hidden>
        <label>Nieuw wachtwoord <span class="hint">(minstens 8 tekens)</span><input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
        <label>Nog een keer<input name="password2" type="password" autocomplete="new-password" minlength="8" required></label>
        <label id="reset-code-wrap" hidden>Code uit je authenticator-app<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="mono totp-input"></label>
        <button class="btn primary block">Wachtwoord opslaan</button>
      </div>
      <p class="muted small" id="reset-status">Link controleren…</p>
      <p class="form-error" hidden></p>
      <p class="small login-links"><a href="index.php?login=1">Naar inloggen</a></p>
    </form>
  </main>
<?php elseif (!$user): ?>
  <main class="login-wrap">
    <form id="login-form" class="card login-card">
      <div class="brand big"><span class="logo">📡</span> RzMon <span class="version" title="RzMon-versie">v<?= htmlspecialchars(RZMON_VERSION) ?></span></div>
      <div id="login-step1">
        <label>Gebruikersnaam<input name="username" autocomplete="username" required autofocus></label>
        <label>Wachtwoord<input name="password" type="password" autocomplete="current-password" required></label>
        <label class="switch small remember"><input type="checkbox" name="remember"><span></span> Onthoud mij op dit apparaat <small class="muted">(30 dagen)</small></label>
      </div>
      <div id="login-step2" hidden>
        <p class="muted small" style="margin-top:0">🔐 Voer de 6-cijferige code uit je authenticator-app in.</p>
        <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="mono totp-input"></label>
      </div>
      <p class="form-error" hidden></p>
      <button class="btn primary block">Inloggen</button>
      <p class="small login-links"><a href="#" id="forgot-link">Wachtwoord vergeten?</a></p>
      <?php if (lan_login_allowed()): ?>
        <p class="small" style="text-align:center;margin:.9rem 0 0"><a href="index.php?lan=1">Verder zonder inloggen (alleen lezen)</a></p>
      <?php endif; ?>
    </form>
    <form id="forgot-form" class="card login-card" hidden>
      <div class="brand big"><span class="logo">📡</span> RzMon</div>
      <h2 style="margin:0 0 .6rem">Wachtwoord vergeten</h2>
      <div id="forgot-fields">
        <p class="muted small" style="margin-top:0">Vul je gebruikersnaam of e-mailadres in. Je krijgt een mail met een link om een nieuw wachtwoord te kiezen (30 minuten geldig).</p>
        <label>Gebruikersnaam of e-mailadres<input name="ident" autocomplete="username" required maxlength="190"></label>
        <button class="btn primary block">Stuur herstellink</button>
      </div>
      <p class="small" id="forgot-done" hidden></p>
      <p class="form-error" hidden></p>
      <p class="small login-links"><a href="#" id="forgot-back">← Terug naar inloggen</a></p>
    </form>
  </main>
<?php else: ?>
  <header class="topbar">
    <div class="brand"><span class="logo">📡</span> RzMon <a class="version" href="https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md" target="_blank" rel="noopener" title="RzMon-versie – bekijk wat er nieuw is">v<?= htmlspecialchars(RZMON_VERSION) ?></a></div>
    <nav class="tabs" role="tablist">
      <button class="tab active" data-tab="dashboard">Dashboard</button>
      <button class="tab" data-tab="alerts">Meldingen</button>
      <button class="tab" data-tab="settings">Instellingen</button>
      <?php if ($role === 'admin'): ?><a class="tab" href="setup.php">Setup</a><?php endif; ?>
    </nav>
    <div class="top-actions">
      <?php if (is_lan_session()): ?><span class="lan-badge" title="Ingelogd zonder wachtwoord omdat je op het lokale netwerk zit">🏠 Lokaal netwerk</span><?php endif; ?>
      <?php if ($role !== 'admin'): ?><span class="role-badge" title="Je rechten: <?= $role === 'user' ? 'storingen afhandelen, niets inrichten' : 'alleen kijken' ?>"><?= $role === 'user' ? '👤 Gebruiker' : '👁 Alleen lezen' ?></span><?php endif; ?>
      <?php if (is_lan_session()): ?><a class="btn small-btn" href="index.php?login=1" title="Inloggen met je eigen account voor je eigen rechten">Inloggen</a><?php endif; ?>
      <button class="icon-btn" id="theme-toggle" title="Thema wisselen">🌓</button>
      <?php if (!is_lan_session()): ?><button class="icon-btn" id="logout" title="Uitloggen (<?= htmlspecialchars($user['username']) ?>)">⏻</button><?php endif; ?>
    </div>
  </header>

  <main class="container">
    <section id="tab-dashboard" class="tab-panel">
<?php if ($schemaError): ?>
      <div class="schema-banner">⚠ <?= htmlspecialchars($schemaError) ?> — controleer de databaserechten of voer <code>php install/install.php</code> uit.</div>
<?php endif; ?>
      <div class="maint-banner" id="maint-banner" hidden></div>
      <div class="summary health" id="health">
        <div class="health-main">
          <button class="health-state" id="health-state" type="button" title="Klik: alleen problemen tonen">
            <b id="health-label">Laden…</b>
          </button>
          <div class="health-score" title="Gezondheidsscore: 100% = alles OK. Een critical telt zwaarder dan een warning, een onbereikbare server het zwaarst.">
            <b id="health-pct">–</b><span class="meter-bar"><i id="health-bar"></i></span>
          </div>
          <div class="health-counts" id="health-counts" role="group" aria-label="Filter op status"></div>
        </div>
        <div class="health-info" id="health-info"></div>
        <div class="summary-actions">
          <span class="muted small" id="last-run"></span>
          <button class="btn need-user" id="maint-all" title="Alle meldingen tijdelijk pauzeren">🔧 Onderhoud</button>
          <button class="btn need-user" id="run-all">↻ Alles nu checken</button>
          <button class="btn primary need-admin" id="add-host">＋ Server</button>
        </div>
      </div>
      <div class="filter-row">
        <input type="search" id="filter" placeholder="Zoek server of check…">
        <label class="switch small"><input type="checkbox" id="only-problems"><span></span> Alleen problemen</label>
        <button class="btn" id="toggle-all" type="button">⊞ Alles openen</button>
      </div>
      <div id="hosts" class="hosts"></div>
    </section>

    <section id="tab-alerts" class="tab-panel" hidden>
      <div class="card">
        <h2>Laatste meldingen</h2>
        <div id="alerts" class="alert-list"></div>
      </div>
    </section>

    <section id="tab-settings" class="tab-panel" hidden>
      <div class="card view-card">
        <h2>Weergave</h2>
        <p class="muted small">Hoe de servers op het dashboard staan. Geldt voor <b>dit apparaat</b> (zo kun je op je telefoon iets anders kiezen dan op je pc) en wordt direct toegepast.</p>
        <div class="view-row">
          <span class="view-label">Lay-out</span>
          <div class="seg" role="group" aria-label="Lay-out">
            <button type="button" class="seg-btn" data-view-layout="blox" title="Een tegel per server">▦ Blox</button>
            <button type="button" class="seg-btn" data-view-layout="l1nes" title="Servers onder elkaar, als een tabel">☰ L1nes</button>
          </div>
        </div>
        <div class="view-row">
          <span class="view-label">Grootte <span class="hint">(bij L1nes)</span></span>
          <div class="seg" role="group" aria-label="Grootte">
            <button type="button" class="seg-btn" data-view-density="small" title="Eén compacte regel per server">Small</button>
            <button type="button" class="seg-btn" data-view-density="large" title="Meer informatie: CPU, RAM, disk, bereikbaarheid">Large</button>
          </div>
        </div>
      </div>

      <form id="settings-form" class="settings-grid need-admin">
        <div class="card">
          <h2>Pushover</h2>
          <p class="muted small">Maak een applicatie aan op <a href="https://pushover.net/apps/build" target="_blank" rel="noopener">pushover.net</a> voor het API-token. Je user key staat op je Pushover dashboard.</p>
          <label class="switch"><input type="checkbox" name="pushover_enabled"><span></span> Pushover meldingen aan</label>
          <label>User key<input name="pushover_user" autocomplete="off" spellcheck="false"></label>
          <label>API token<input name="pushover_token" autocomplete="off" spellcheck="false"></label>
          <div class="row2">
            <label>Prioriteit critical
              <select name="pushover_priority_crit">
                <option value="-1">Laag (-1)</option><option value="0">Normaal (0)</option>
                <option value="1">Hoog (1)</option><option value="2">Emergency (2)</option>
              </select>
            </label>
            <label>Prioriteit warning
              <select name="pushover_priority_warn">
                <option value="-1">Laag (-1)</option><option value="0">Normaal (0)</option>
                <option value="1">Hoog (1)</option>
              </select>
            </label>
          </div>
          <label>Geluid <span class="muted small">(leeg = standaard, bv. siren, alien, pushover)</span><input name="pushover_sound"></label>
          <button type="button" class="btn" id="pushover-test">📲 Testbericht sturen</button>
        </div>

        <div class="card notify-card">
          <h2>🔔 Meldingen</h2>
          <p class="muted small">Per status: <b>nooit</b>, <b>direct</b>, of pas <b>na X minuten</b> onafgebroken in die status. Herstelt het eerder, dan komt er geen melding. Hersteld: pas melden als het X minuten goed blijft. Per check kun je dit aanpassen (✎ → Meldingen).</p>
          <label class="switch"><input type="checkbox" name="notify_unknown"><span></span> Melden bij ONBEKEND</label>
          <div id="notify-editor"></div>
          <input type="hidden" name="notify_rules"><input type="hidden" name="notify_channels"><input type="hidden" name="notify_flap"><input type="hidden" name="notify_quiet">
          <div class="quiet-box">
            <h3>🌙 Stille uren</h3>
            <p class="muted small">In deze periodes gaan er geen meldingen uit (Pushover, e-mail en Home Assistant). Checks draaien gewoon door en het dashboard blijft alles tonen.
              Is er na afloop nog iets mis, dan krijg je <b>één bericht</b> met alle open problemen. Wat in de tussentijd vanzelf herstelde, wordt niet gemeld. Per check aan te passen (✎ → Stille uren).</p>
            <div id="quiet-editor"></div>
          </div>
          <div class="row2">
            <label>Meldingen per server bundelen binnen … seconden <span class="muted small">(0 = niet bundelen)</span><input type="number" name="notify_group_seconds" min="0" max="3600"></label>
            <label class="switch" style="align-self:end"><input type="checkbox" name="notify_recovery_acked"><span></span> Ook herstel melden na een bevestigde storing</label>
          </div>
          <p class="muted small" style="margin-top:-.3rem">Bundelen: gaan op één server binnen deze tijd meerdere checks mis (of herstellen ze), dan komt er één bericht met alles erin. De eerste melding wacht daardoor hooguit zo lang. Home Assistant krijgt wel een bericht per check, voor je automatiseringen.</p>
        </div>

        <div class="card">
          <h2>Alarmgedrag</h2>
          <label>Agent offline na … minuten zonder rapport<input type="number" name="agent_stale_minutes" min="1"></label>
          <label>Historie bewaren (dagen)<input type="number" name="retention_days" min="1"></label>
          <label>Waarschuwen als een disk binnen … dagen vol raakt <span class="muted small">(0 = uit)</span><input type="number" name="disk_forecast_days" min="0" max="365"></label>
          <label>Logbestanden: treffers tellen over de laatste … minuten <span class="muted small">(5–1440)</span><input type="number" name="log_window" min="5" max="1440"></label>
          <label class="switch"><input type="checkbox" name="auto_maint_enabled"><span></span> Automatisch onderhoud na herhaalde meldingen</label>
          <label>… na zoveel meldingen voor dezelfde storing <span class="muted small">(alleen die check; stopt vanzelf als hij weer OK is)</span><input type="number" name="auto_maint_after" min="1" max="100"></label>
        </div>

        <div class="card">
          <h2>Home Assistant-webhook</h2>
          <p class="muted small">Stuurt elke melding ook als JSON naar Home Assistant, zodat je er een automatisering op kunt starten: een lamp die rood kleurt, een TTS-melding, of een notificatie via de HA-app.
            Maak in HA een automatisering met trigger <b>Webhook</b> en plak de URL hieronder, bv. <code>http://homeassistant.local:8123/api/webhook/rzmon-…</code></p>
          <label class="switch"><input type="checkbox" name="ha_webhook_enabled"><span></span> Webhook aan</label>
          <label>Webhook-URL<input name="ha_webhook_url" type="url" spellcheck="false" placeholder="http://homeassistant.local:8123/api/webhook/rzmon-geheim-id"></label>
          <p class="muted small">Welke meldingen naar Home Assistant gaan, stel je in bij <b>🔔 Meldingen</b> (kolom Home Assistant).</p>
          <label class="switch"><input type="checkbox" name="ha_webhook_verify_ssl"><span></span> SSL-certificaat controleren (uit bij self-signed)</label>
          <button type="button" class="btn" id="webhook-test">🏠 Testbericht naar Home Assistant</button>
        </div>

        <div class="card">
          <h2>E-mail</h2>
          <p class="muted small">Meldingen ook per e-mail, of alleen als reserve wanneer Pushover niet werkt. Bij Gmail, Outlook en iCloud heb je een <b>app-wachtwoord</b> nodig (niet je gewone wachtwoord).</p>
          <label class="switch"><input type="checkbox" name="email_enabled"><span></span> E-mailmeldingen aan</label>
          <label>Versturen
            <select name="email_mode">
              <option value="always">Altijd (naast Pushover)</option>
              <option value="fallback">Alleen als Pushover uit staat of mislukt</option>
            </select>
          </label>
          <div class="row3 smtp-row">
            <label>SMTP-server<input name="smtp_host" spellcheck="false" placeholder="smtp.gmail.com"></label>
            <label>Poort<input name="smtp_port" type="number" min="1" max="65535" placeholder="587" title="587 = STARTTLS, 465 = SSL/TLS, 25 = geen"></label>
            <label>Beveiliging
              <select name="smtp_security">
                <option value="starttls">STARTTLS</option>
                <option value="ssl">SSL/TLS</option>
                <option value="none">Geen</option>
              </select>
            </label>
          </div>
          <div class="row2">
            <label>Gebruikersnaam<input name="smtp_user" autocomplete="off" spellcheck="false" placeholder="leeg = zonder inloggen"></label>
            <label>Wachtwoord<input name="smtp_pass" type="password" autocomplete="new-password"></label>
          </div>
          <div class="row2">
            <label>Afzender <span class="hint">(leeg = gebruikersnaam)</span><input name="mail_from" type="email" spellcheck="false" placeholder="rzmon@example.com"></label>
            <label>Ontvanger(s) <span class="hint">(komma tussen meerdere)</span><input name="mail_to" spellcheck="false" placeholder="jij@example.com"></label>
          </div>
          <label class="switch"><input type="checkbox" name="smtp_verify_ssl"><span></span> SSL-certificaat controleren (uit bij self-signed)</label>
          <button type="button" class="btn" id="email-test">📧 Testmail sturen</button>
        </div>

        <div class="card">
          <h2>MQTT (Home Assistant)</h2>
          <p class="muted small">RzMon meldt zich via <b>MQTT discovery</b> bij Home Assistant: elke server wordt een apparaat met sensoren voor de status en meetwaarden van zijn checks. Je hebt een MQTT-broker nodig, bv. de <i>Mosquitto broker</i>-add-on, en de MQTT-integratie in HA.
            Gebruik bij Mosquitto een HA-gebruiker (of een aparte gebruiker) als login.</p>
          <label class="switch"><input type="checkbox" name="mqtt_enabled"><span></span> MQTT aan</label>
          <div class="row3 smtp-row">
            <label>MQTT-server<input name="mqtt_host" spellcheck="false" placeholder="homeassistant.local"></label>
            <label>Poort<input name="mqtt_port" type="number" min="1" max="65535" placeholder="1883" title="1883 = geen versleuteling, 8883 = TLS"></label>
            <label>Versleuteling
              <select name="mqtt_tls"><option value="0">Geen</option><option value="1">TLS</option></select>
            </label>
          </div>
          <div class="row2">
            <label>Gebruikersnaam<input name="mqtt_user" autocomplete="off" spellcheck="false"></label>
            <label>Wachtwoord<input name="mqtt_pass" type="password" autocomplete="new-password"></label>
          </div>
          <details class="small api-docs">
            <summary>Geavanceerd</summary>
            <div class="row2">
              <label>Discovery-prefix<input name="mqtt_prefix" spellcheck="false" placeholder="homeassistant"></label>
              <label>Basis-topic<input name="mqtt_base_topic" spellcheck="false" placeholder="rzmon"></label>
            </div>
            <label class="switch"><input type="checkbox" name="mqtt_verify_ssl"><span></span> TLS-certificaat controleren</label>
          </details>
          <p class="small" id="mqtt-status"></p>
          <button type="button" class="btn" id="mqtt-test">📡 Verbinding testen &amp; publiceren</button>
        </div>

        <div class="card" id="agents-card">
          <h2>⬆ Agents bijwerken</h2>
          <p class="muted small">Bij een nieuwe versie van RzMon kun je de agents vanaf hier bijwerken: per server in het venster <i>Agent Manager</i>, of allemaal tegelijk. De agent haalt de nieuwe versie bij RzMon op, controleert hem en installeert hem zelf; zijn instellingen blijven staan. De eerste installatie gaat met het commando op de server.</p>
          <p class="small" id="agents-status">…</p>
          <button type="button" class="btn" id="agents-update-all" hidden>⬆ Alle verouderde agents bijwerken</button>
          <label class="switch" style="margin-top:.9rem"><input type="checkbox" name="agent_auto_update"><span></span> Agents automatisch bijwerken</label>
          <p class="muted small" style="margin-top:-.4rem">Verouderde agents werken zichzelf bij zonder dat je op de knop drukt (hooguit één poging per 6 uur). Home Assistant-add-ons werk je bij via de add-on winkel. Een server uitzonderen: <code>REMOTE_UPDATE=0</code> in <code>/etc/default/rzmon-agent</code>.</p>
        </div>

        <div class="card" id="backup-card">
          <h2>💾 Back-up</h2>
          <p class="muted small">Een zip met de hele database (servers, checks, instellingen, gebruikers) en <code>config.php</code>. De back-ups staan op deze server, buiten de webmap. Download er af en toe een, zodat je ook een kopie hebt als de server zelf stuk gaat.</p>
          <div class="row3">
            <label>Automatisch
              <select name="backup_freq">
                <option value="off">Uit</option><option value="daily">Elke dag</option>
                <option value="weekly">Elke week</option><option value="monthly">Elke maand (de 1e)</option>
              </select>
            </label>
            <label>Om<input type="time" name="backup_time"></label>
            <label>Bewaren <span class="hint">(aantal)</span><input type="number" name="backup_keep" min="1" max="365"></label>
          </div>
          <label class="backup-weekday">Op
            <select name="backup_weekday">
              <option value="1">maandag</option><option value="2">dinsdag</option><option value="3">woensdag</option><option value="4">donderdag</option>
              <option value="5">vrijdag</option><option value="6">zaterdag</option><option value="7">zondag</option>
            </select>
          </label>
          <label class="switch"><input type="checkbox" name="backup_history"><span></span> Meethistorie meenemen</label>
          <p class="muted small" style="margin-top:-.4rem">De meetresultaten achter de grafieken. Uit = een veel kleinere zip; bij terugzetten blijft de huidige historie dan staan.</p>
          <label>Zip-wachtwoord <span class="hint">(minstens 8 tekens; versleutelt met AES-256)</span><input name="backup_pass" type="password" autocomplete="new-password"></label>
          <p class="muted small" style="margin-top:-.4rem">Nodig om een zip te openen (met 7-Zip) of terug te zetten op een andere server. <b>Bewaar het goed</b>: zonder wachtwoord is de back-up onbruikbaar. Wijzigen geldt voor nieuwe back-ups.</p>
          <p class="small" id="backup-status">…</p>
          <div class="btn-row">
            <button type="button" class="btn" id="backup-now">💾 Nu back-up maken</button>
            <button type="button" class="btn" id="backup-upload">⬆ Zip uploaden</button>
            <input type="file" id="backup-file" accept=".zip,application/zip" hidden>
          </div>
          <div id="backup-list" class="key-list"></div>
        </div>

        <div class="settings-save"><button class="btn primary">Instellingen opslaan</button></div>
      </form>

      <div class="settings-grid" style="margin-top:1.25rem">
      <form class="card need-admin" id="access-form">
        <h2>Toegang</h2>
        <div class="ip-status" id="ip-status">…</div>
        <label class="switch"><input type="checkbox" name="lan_no_login"><span></span> Geen login nodig op het lokale netwerk</label>
        <label class="switch"><input type="checkbox" name="lan_as_admin"><span></span> Op het lokale netwerk als <b>beheerder</b> inloggen</label>
        <p class="muted small" style="margin-top:-.3rem">Uit: op het lokale netwerk kom je binnen als <b>alleen lezen</b>, en met <i>Inloggen</i> rechtsboven krijg je de rechten van je eigen account. Van buitenaf is altijd een gebruikersnaam en wachtwoord nodig.</p>
        <label>Lokale netwerken <span class="hint">(één per regel, bv. 192.168.1.0/24)</span>
          <textarea name="trusted_networks" rows="5" spellcheck="false"></textarea></label>
        <label>Reverse proxy IP's <span class="hint">(optioneel — alleen invullen als RzMon achter Nginx Proxy Manager, Traefik, Cloudflare tunnel e.d. staat)</span>
          <textarea name="trusted_proxies" rows="2" spellcheck="false"></textarea></label>
        <p class="callout">⚠️ Staat RzMon achter een reverse proxy of port-forward waarbij álle bezoekers het IP van je router/proxy krijgen? Vul dat proxy-IP dan in bij <i>Reverse proxy IP's</i> en zet het <b>niet</b> bij lokale netwerken — anders kan iedereen van buitenaf zonder wachtwoord binnen.</p>
        <p class="form-error" hidden></p>
        <button class="btn primary">Toegang opslaan</button>
      </form>

      <div class="card need-admin" id="api-card">
        <h2>API-sleutels</h2>
        <p class="muted small">Lees statussen en meetwaarden uit via de REST API, bijvoorbeeld vanuit Home Assistant. Stuur de sleutel mee als <code>Authorization: Bearer &lt;sleutel&gt;</code>.</p>
        <p class="small">Basis-URL: <code id="api-base"></code></p>
        <div id="api-keys" class="key-list"></div>
        <form id="api-key-form" class="inline-form">
          <input name="name" placeholder="Naam, bv. Home Assistant" required maxlength="100">
          <button class="btn">＋ Sleutel maken</button>
        </form>
        <details class="small api-docs">
          <summary>Endpoints</summary>
          <ul>
            <li><code>GET /status</code> – samenvatting, problemen en status per server</li>
            <li><code>GET /hosts</code> · <code>/hosts/{id}</code> – servers met checks</li>
            <li><code>GET /checks</code> – alle checks (<code>?status=2</code>, <code>?type=disk</code>, <code>?host_id=1</code>)</li>
            <li><code>GET /checks/{id}</code> · <code>/checks/{id}/history?hours=24</code></li>
            <li><code>GET /alerts?limit=50</code> – laatste meldingen</li>
          </ul>
        </details>
      </div>

      <div class="card" id="users-card" <?= ($user['id'] ?? 0) === 0 ? 'hidden' : '' ?>>
        <h2>Gebruikers</h2>
        <p class="muted small need-admin"><b>Beheerder</b>: alles. <b>Gebruiker</b>: storingen afhandelen (bevestigen, nu checken, onderhoud, poortscan), maar niets inrichten. <b>Alleen lezen</b>: kijken.
          Met <b>tweestapsverificatie</b> (TOTP) is naast het wachtwoord een code uit een authenticator-app nodig, zoals Google/Microsoft Authenticator, Authy, Bitwarden of 1Password.</p>
        <p class="muted small" data-role-only="nonadmin">Je account. Hier stel je je tweestapsverificatie in; je wachtwoord wijzig je hieronder.</p>
        <div id="users" class="key-list"></div>
        <p class="small need-admin" id="pw-reset-info"></p>
        <form id="user-form" class="user-form need-admin">
          <div class="row2">
            <input name="username" placeholder="Gebruikersnaam" required maxlength="64" autocomplete="off">
            <input name="password" type="password" placeholder="Wachtwoord (min. 8 tekens)" required minlength="8" autocomplete="new-password">
          </div>
          <input name="email" type="email" placeholder="E-mailadres (optioneel, voor wachtwoord vergeten)" maxlength="190" autocomplete="off">
          <label>Rol
            <select name="role">
              <option value="user">👤 Gebruiker – storingen afhandelen</option>
              <option value="readonly">👁 Alleen lezen – kijken</option>
              <option value="admin">🔑 Beheerder – alles</option>
            </select>
          </label>
          <label class="switch small"><input type="checkbox" name="totp"><span></span> Direct tweestapsverificatie instellen</label>
          <button class="btn">＋ Gebruiker toevoegen</button>
        </form>
      </div>

      <?php if (($user['id'] ?? 0) === 0): ?>
      <div class="card"><h2>Account</h2><p class="muted small">Je bent automatisch ingelogd via het lokale netwerk, met alleen-lezen-rechten. Klik rechtsboven op <b>Inloggen</b> om met je eigen account in te loggen.</p></div>
      <?php endif; ?>
      <div class="card need-admin" id="db-card">
        <h2>Database</h2>
        <p class="muted small">RzMon gebruikt een eigen databasegebruiker. Met de knop maak je een nieuw, sterk wachtwoord en slaat RzMon het overal op. Alles blijft gewoon werken: dashboards, cron, API en agents.</p>
        <p class="small" id="db-status">…</p>
        <button type="button" class="btn" id="db-rotate">🔑 Databasewachtwoord vernieuwen</button>
      </div>

      <form id="password-form" class="card" <?= ($user['id'] ?? 0) === 0 ? 'hidden' : '' ?>>
        <h2>Wachtwoord wijzigen</h2>
        <label>Huidig wachtwoord<input type="password" name="current" autocomplete="current-password" required></label>
        <label>Nieuw wachtwoord<input type="password" name="new" minlength="8" autocomplete="new-password" required></label>
        <button class="btn">Wijzigen</button>
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
  <script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
