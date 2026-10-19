<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

// Niet ingesteld of database onbereikbaar → naar de setup
if (!is_configured()) {
    header('Location: setup.php');
    exit;
}
try {
    $user = current_user();
} catch (PDOException $e) {
    header('Location: setup.php');
    exit;
}
$csrf = csrf_token();
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
<body>
<?php if (!$user): ?>
  <main class="login-wrap">
    <form id="login-form" class="card login-card">
      <div class="brand big"><span class="logo">📡</span> RzMon</div>
      <label>Gebruikersnaam<input name="username" autocomplete="username" required autofocus></label>
      <label>Wachtwoord<input name="password" type="password" autocomplete="current-password" required></label>
      <p class="form-error" hidden></p>
      <button class="btn primary block">Inloggen</button>
    </form>
  </main>
<?php else: ?>
  <header class="topbar">
    <div class="brand"><span class="logo">📡</span> RzMon</div>
    <nav class="tabs" role="tablist">
      <button class="tab active" data-tab="dashboard">Dashboard</button>
      <button class="tab" data-tab="alerts">Meldingen</button>
      <button class="tab" data-tab="settings">Instellingen</button>
      <a class="tab" href="setup.php">Setup</a>
    </nav>
    <div class="top-actions">
      <?php if (is_lan_session()): ?><span class="lan-badge" title="Ingelogd zonder wachtwoord omdat je op het lokale netwerk zit">🏠 Lokaal netwerk</span><?php endif; ?>
      <button class="icon-btn" id="theme-toggle" title="Thema wisselen">🌓</button>
      <?php if (!is_lan_session()): ?><button class="icon-btn" id="logout" title="Uitloggen">⏻</button><?php endif; ?>
    </div>
  </header>

  <main class="container">
    <section id="tab-dashboard" class="tab-panel">
      <div class="summary">
        <div class="stat ok"><span class="num" id="sum-ok">–</span><span>OK</span></div>
        <div class="stat warn"><span class="num" id="sum-warn">–</span><span>Warning</span></div>
        <div class="stat crit"><span class="num" id="sum-crit">–</span><span>Critical</span></div>
        <div class="stat unknown"><span class="num" id="sum-unknown">–</span><span>Onbekend</span></div>
        <div class="summary-actions">
          <span class="muted small" id="last-run"></span>
          <button class="btn" id="run-all">↻ Alles nu checken</button>
          <button class="btn primary" id="add-host">＋ Server</button>
        </div>
      </div>
      <div class="filter-row">
        <input type="search" id="filter" placeholder="Zoek server of check…">
        <label class="switch small"><input type="checkbox" id="only-problems"><span></span> Alleen problemen</label>
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
      <form id="settings-form" class="settings-grid">
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
          <label>Agent offline na … minuten zonder rapport<input type="number" name="agent_stale_minutes" min="1"></label>
          <label>Historie bewaren (dagen)<input type="number" name="retention_days" min="1"></label>
        </div>

        <div class="settings-save"><button class="btn primary">Instellingen opslaan</button></div>
      </form>

      <div class="settings-grid" style="margin-top:1.25rem">
      <form class="card" id="access-form">
        <h2>Toegang</h2>
        <div class="ip-status" id="ip-status">…</div>
        <label class="switch"><input type="checkbox" name="lan_no_login"><span></span> Geen login nodig op het lokale netwerk</label>
        <label>Lokale netwerken <span class="hint">(één per regel, bv. 192.168.1.0/24)</span>
          <textarea name="trusted_networks" rows="5" spellcheck="false"></textarea></label>
        <label>Reverse proxy IP's <span class="hint">(optioneel — alleen invullen als RzMon achter Nginx Proxy Manager, Traefik, Cloudflare tunnel e.d. staat)</span>
          <textarea name="trusted_proxies" rows="2" spellcheck="false"></textarea></label>
        <p class="callout">⚠️ Staat RzMon achter een reverse proxy of port-forward waarbij álle bezoekers het IP van je router/proxy krijgen? Vul dat proxy-IP dan in bij <i>Reverse proxy IP's</i> en zet het <b>niet</b> bij lokale netwerken — anders kan iedereen van buitenaf zonder wachtwoord binnen.</p>
        <p class="form-error" hidden></p>
        <button class="btn primary">Toegang opslaan</button>
      </form>

      <div class="card" id="api-card">
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

      <form id="password-form" class="card">
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
  <script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
