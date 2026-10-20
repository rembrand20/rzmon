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
$v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <title>RzMon</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📡</text></svg>">
  <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
  <script>try{const t=localStorage.getItem('rzmon-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body data-role="<?= htmlspecialchars($role) ?>">
<?php if (!$user): ?>
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
      <?php if (lan_login_allowed()): ?>
        <p class="small" style="text-align:center;margin:.9rem 0 0"><a href="index.php?lan=1">Verder zonder inloggen (alleen lezen)</a></p>
      <?php endif; ?>
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
      <div class="summary">
        <div class="stat ok"><span class="num" id="sum-ok">–</span><span>OK</span></div>
        <div class="stat warn"><span class="num" id="sum-warn">–</span><span>Warning</span></div>
        <div class="stat crit"><span class="num" id="sum-crit">–</span><span>Critical</span></div>
        <div class="stat unknown"><span class="num" id="sum-unknown">–</span><span>Onbekend</span></div>
        <div class="stat maint" title="Checks in onderhoud: geen meldingen"><span class="num" id="sum-maint">–</span><span>Onderhoud</span></div>
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

        <div class="card">
          <h2>Alarmgedrag</h2>
          <label>Alarm na aantal opeenvolgende fouten<input type="number" name="alert_after" min="1" max="20"></label>
          <label>Herhaal melding elke … minuten <span class="muted small">(0 = nooit)</span><input type="number" name="repeat_minutes" min="0"></label>
          <label class="switch"><input type="checkbox" name="notify_warn"><span></span> Ook melden bij WARNING</label>
          <label class="switch"><input type="checkbox" name="notify_recovery"><span></span> Melden bij herstel</label>
          <label class="switch"><input type="checkbox" name="notify_recovery_acked"><span></span> Ook herstel melden na een bevestigde storing</label>
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
          <label>Versturen bij
            <select name="ha_webhook_events">
              <option value="all">Alle meldingen (warning, critical, herhaling, herstel)</option>
              <option value="crit">Alleen critical en herstel</option>
            </select>
          </label>
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
        <form id="user-form" class="user-form need-admin">
          <div class="row2">
            <input name="username" placeholder="Gebruikersnaam" required maxlength="64" autocomplete="off">
            <input name="password" type="password" placeholder="Wachtwoord (min. 8 tekens)" required minlength="8" autocomplete="new-password">
          </div>
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
