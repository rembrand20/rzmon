'use strict';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const CSRF = $('meta[name=csrf-token]')?.content;
const STATUS = ['OK', 'WARNING', 'CRITICAL', 'ONBEKEND'];

const TYPES = {
  ping:    { label: 'Ping',          target: null,            unit: 'ms', warn: 100, crit: 500, help: 'Gemiddelde responstijd van 3 pings. Geen antwoord = CRITICAL.' },
  tcp:     { label: 'TCP poort',     target: 'Poort',         unit: 'ms', warn: 500, crit: 2000, ph: '22', help: 'Controleert of de poort open staat (bv. 22 SSH, 3306 MariaDB, 443 HTTPS).' },
  http:    { label: 'HTTP(S)',       target: 'URL of pad',    unit: 'ms', warn: 1000, crit: 3000, ph: 'https://example.com/ of /status', help: 'HTTP status ≥ 400 = CRITICAL. SSL-certificaat < 14 dagen = WARNING (voor eigen drempels: gebruik de check SSL-certificaat).' },
  ssl:     { label: 'SSL-certificaat', target: 'Host[:poort]', unit: 'dagen', warn: 21, crit: 7, low: true, ph: 'example.com of nas.lan:5001', help: 'Waarschuwt als het certificaat bijna verloopt. Leeg = adres van de server op poort 443. Werkt op elke TLS-poort (8443, 993, 8883…), ook op internet. Bij een agent-server kun je ook kiezen uit de certificaten die de agent meldt (bestanden en adressen gezien vanaf die server).' },
  disk:    { label: 'Diskruimte',    target: 'Mount / pad',   unit: '%',  warn: 80, crit: 90, ph: '/', help: 'Percentage gebruikt. Op agent-servers: mount zoals de agent meldt.' },
  cpu:     { label: 'CPU',           target: null,            unit: '%',  warn: 85, crit: 95, help: 'CPU-gebruik gemeten over 2 seconden, met load average in het bericht. Tip: zet "alarm na N fouten" op 3+ om korte pieken te negeren.' },
  ram:     { label: 'RAM',           target: null,            unit: '%',  warn: 85, crit: 95, help: 'Percentage geheugen in gebruik (op basis van MemAvailable, dus cache telt niet mee). Swap staat in het bericht.' },
  podman:  { label: 'Podman (algemeen)', target: null, unit: null, help: 'Algehele status van Podman op deze server: draait podman, hoeveel containers draaien er, en zijn er containers gestopt met een fout, ongezond (healthcheck) of gepauzeerd, of pods niet in orde? Vereist de agent.' },
  container: { label: 'Container (Podman)', target: 'Container', unit: null, ph: 'naam, of gebruiker/naam (rootless)', help: 'Eén check per container: de status (draait, healthcheck, herstarts) altijd, en daarnaast de CPU, RAM en disk die je hieronder aanvinkt, elk met eigen drempels. Kies de naam uit de lijst die de agent meldt.' },
  log:     { label: 'Logbestand (zoeken)', target: 'Bron', unit: 'treffers', warn: 5, crit: 20, ph: '/var/log/secure, journal, journal:sshd of kernel', help: 'De agent leest de nieuwe regels van een logbestand of het systemlog en telt hoeveel er op je zoekpatroon passen in de laatste periode (Instellingen → Alarmgedrag, standaard 15 min). Kies hieronder een voorinstelling of stel zelf in.' },
  ha:      { label: 'Home Assistant', target: null, unit: null, help: 'Via de Home Assistant-add-on: de laatste automatische back-up, integraties die niet geladen zijn, of de systeemstatus van de Supervisor. Kies hieronder wat je wilt bewaken.' },
  service: { label: 'Service',       target: 'Servicenaam',   unit: null, ph: 'nginx', help: 'De systemd-service moet "active" zijn. Op agent-servers kies je hem uit de lijst: de agent bewaakt hem dan vanzelf, en met Diepere controle wordt ook gekeken of hij echt werkt. Home Assistant-agent: homeassistant, supervisor, updates of addon:<slug>.' },
};
// Voorinstellingen voor logbestanden: bron, zoekpatroon (regex, hoofdletters maakt niet uit) en drempels
const LOG_PRESETS = {
  ssh:      { label: '🔐 Mislukte logins (SSH)', src: 'journal', warn: 5, crit: 20,
              pat: 'Failed password|Invalid user|authentication failure|Connection closed by authenticating user|maximum authentication attempts' },
  sudo:     { label: '🔐 Mislukte sudo / su', src: 'journal', warn: 1, crit: 5,
              pat: 'incorrect password attempt|sudo:.*authentication failure|FAILED SU|su:.*authentication failure' },
  disk:     { label: '💾 Diskfouten', src: 'kernel', warn: 1, crit: 5,
              pat: 'I/O error|blk_update_request|Buffer I/O error|medium error|ata[0-9.]+: (exception|error|failed command)|EXT4-fs error|XFS .*(error|corrupt)|BTRFS .*(error|corrupt)|nvme.*(timeout|reset|error)' },
  hardware: { label: '🔥 Hardwarefouten (CPU, geheugen, PCIe, temperatuur)', src: 'kernel', warn: 1, crit: 3,
              pat: 'Machine check|mce: |EDAC|Hardware Error|PCIe Bus Error|AER: |temperature above threshold|thermal.*throttl|Corrected error|Uncorrected error' },
  smart:    { label: '💽 SMART-waarschuwingen (smartd)', src: 'journal:smartd', warn: 1, crit: 3,
              pat: 'FAILURE|Prefailure|Currently unreadable|Offline uncorrectable|failed self-test|SMART Failure' },
  oom:      { label: '🧠 Geheugen vol (OOM)', src: 'kernel', warn: 1, crit: 3, pat: 'Out of memory|oom-kill|Killed process' },
  crash:    { label: '💥 Programma\'s gecrasht', src: 'kernel', warn: 1, crit: 5, pat: 'segfault|general protection|core dumped|traps: .* trap' },
  failed:   { label: '⚙️ Mislukte services (systemd)', src: 'journal', warn: 1, crit: 5, pat: 'Failed to start|failed with result|Main process exited, code=(exited|killed|dumped), status=[1-9]' },
  fw:       { label: '🧱 Firewall-blokkades', src: 'kernel', warn: 100, crit: 1000, pat: 'REJECT|DROP|filter_IN_.*(REJECT|DROP)|nft.*(drop|reject)' },
  f2b:      { label: '🚫 Fail2ban-bans', src: '/var/log/fail2ban.log', warn: 5, crit: 20, pat: ' Ban ' },
  web5xx:   { label: '🌐 Webserver: serverfouten (5xx)', src: '/var/log/nginx/access.log', warn: 10, crit: 50, pat: '" 5[0-9][0-9] ' },
  web404:   { label: '🌐 Webserver: niet gevonden (404, scans)', src: '/var/log/nginx/access.log', warn: 100, crit: 500, pat: '" 404 ' },
  weberr:   { label: '🌐 Webserver: foutlog', src: '/var/log/nginx/error.log', warn: 5, crit: 20, pat: '\\[(error|crit|alert|emerg)\\]' },
  mail:     { label: '✉️ Mail: mislukte logins', src: 'journal', warn: 5, crit: 20, pat: 'SASL [A-Z]+ authentication failed|auth failed|authentication failure.*(dovecot|postfix)' },
  db:       { label: '🗄️ Database: fouten', src: 'journal:mariadb', warn: 1, crit: 5, pat: '\\[ERROR\\]|Access denied for user|Aborted connection' },
  custom:   { label: '✏️ Zelf instellen', src: '', warn: 1, crit: 10, pat: '' },
};
const LOG_SOURCES = ['journal', 'journal:sshd', 'journal:ssh', 'kernel', '/var/log/secure', '/var/log/auth.log', '/var/log/messages',
  '/var/log/syslog', '/var/log/kern.log', '/var/log/fail2ban.log', '/var/log/nginx/access.log', '/var/log/nginx/error.log',
  '/var/log/httpd/access_log', '/var/log/httpd/error_log', '/var/log/maillog', '/var/log/mail.log', '/var/log/mariadb/mariadb.log'];

// Container-checks: wat er gemeten wordt (eigen eenheid en drempels)
const METRICS = {
  status: { label: 'Status (draait, healthcheck, herstarts)', unit: null, help: 'OK als de container draait. CRITICAL als hij gestopt of ongezond (healthcheck) is. WARNING als hij gepauzeerd is, of in het afgelopen kwartier automatisch herstart is (crash).' },
  cpu:    { label: 'CPU-gebruik', unit: '%', warn: 80, crit: 95, help: 'CPU-gebruik zoals podman stats het toont: 100% = één volledige core (kan dus boven 100% komen).' },
  mem:    { label: 'Geheugen (RAM)', unit: '%', warn: 85, crit: 95, help: 'Percentage van de geheugenlimiet van de container, of van het totale geheugen als er geen limiet is.' },
  disk:   { label: 'Diskgebruik', unit: 'MB', warn: 5000, crit: 10000, help: 'Schrijflaag van de container plus de grootte van zijn volumes (volumes worden elk uur gemeten).' },
};
// Home Assistant (add-on): wat er bewaakt kan worden, met standaarddrempels
const HA_METRICS = {
  backup:       { label: 'Laatste back-up', unit: 'dagen', warn: 2, crit: 7, help: 'Hoe oud de laatste geslaagde automatische back-up is (HA 2025.2 of nieuwer, met automatische back-ups). Ook WARNING als de laatste poging mislukt is.' },
  integrations: { label: 'Integraties', unit: 'stuks', warn: 1, crit: 3, help: 'Aantal integraties dat niet geladen is: fout bij instellen, opnieuw proberen of uitladen mislukt. Uitgeschakelde integraties tellen niet mee.' },
  repairs:      { label: 'Systeemstatus (reparaties)', unit: null, help: 'Reparaties van de Supervisor: "niet gezond" = CRITICAL; "niet ondersteund" of een openstaande melding (bv. weinig vrije ruimte) = WARNING.' },
};
/** Type-beschrijving van een check (container: één check met status + resources, geen eigen eenheid). */
function typeOf(c) {
  if (c.type === 'ha') return { ...TYPES.ha, ...(HA_METRICS[c.metric] || {}), label: 'Home Assistant' };
  return c.type === 'container' && c.metric ? { ...TYPES.container, ...METRICS[c.metric], label: TYPES.container.label } : TYPES[c.type];
}

const MODES = { local: 'Lokaal (deze server)', agent: 'Remote met agent', remote: 'Remote (alleen netwerk)' };

let state = { hosts: [], alerts: [] };
let stats = { uptime: {}, sparks: {} };

// ---------------------------------------------------------------- API
async function api(action, body, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const res = await fetch('api.php?' + qs, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({ error: 'Ongeldig antwoord van server' }));
  if (res.status === 401 && !action.startsWith('login')) { location.reload(); }
  if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  $('#toasts').append(el);
  setTimeout(() => el.remove(), 4000);
}

function ago(dt) {
  if (!dt) return 'nooit';
  const s = Math.round((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 1000);
  if (s < 60) return `${Math.max(0, s)}s geleden`;
  if (s < 3600) return `${Math.floor(s / 60)} min geleden`;
  if (s < 86400) return `${Math.floor(s / 3600)} uur geleden`;
  return `${Math.floor(s / 86400)} dagen geleden`;
}

const fmt = (v, unit) => (v === null || v === undefined ? '' : `${Math.round(v * 10) / 10}${unit ? ' ' + unit : ''}`);

// ---------------------------------------------------------------- Wachtwoord vergeten
const forgotForm = $('#forgot-form');
if (forgotForm) {
  const show = (forgot) => {
    $('#login-form').hidden = forgot;
    forgotForm.hidden = !forgot;
    (forgot ? forgotForm.ident : $('#login-form').username).focus();
  };
  $('#forgot-link').onclick = (e) => {
    e.preventDefault();
    forgotForm.ident.value = $('#login-form').username.value;
    show(true);
  };
  $('#forgot-back').onclick = (e) => { e.preventDefault(); show(false); };
  forgotForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('.form-error', forgotForm);
    const b = $('button', forgotForm);
    b.disabled = true;
    try {
      const r = await api('pw_forgot', { ident: forgotForm.ident.value });
      $('#forgot-fields').hidden = true;
      $('#forgot-done').hidden = false;
      $('#forgot-done').innerHTML = r.no_mail
        ? '⚠️ RzMon kan op dit moment geen e-mail versturen. <b>Vraag een beheerder</b> om je wachtwoord opnieuw in te stellen.'
        : '✉️ Als dit account bestaat en een e-mailadres heeft, is er een mail met een herstellink verstuurd. Kijk ook in je spammap. De link is 30 minuten geldig.';
      err.hidden = true;
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
    b.disabled = false;
  });
}

// Nieuw wachtwoord kiezen (link uit de mail)
const resetForm = $('#reset-form');
if (resetForm) {
  const token = resetForm.dataset.token;
  history.replaceState(null, '', location.pathname);   // de sleutel niet in de adresbalk of geschiedenis laten staan
  const err = $('.form-error', resetForm);
  api('pw_reset_check', { token }).then((r) => {
    $('#reset-user').textContent = r.username;
    resetForm.username.value = r.username;
    $('#reset-code-wrap').hidden = !r.totp;
    resetForm.code.required = r.totp;
    $('#reset-fields').hidden = false;
    $('#reset-status').hidden = true;
    resetForm.password.focus();
  }).catch((ex) => { $('#reset-status').textContent = ex.message; });
  resetForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (resetForm.password.value !== resetForm.password2.value) {
      err.textContent = 'De twee wachtwoorden zijn niet gelijk';
      err.hidden = false;
      return;
    }
    const b = $('#reset-fields button', resetForm);
    b.disabled = true;
    try {
      await api('pw_reset', { token, password: resetForm.password.value, code: resetForm.code.value });
      $('#reset-fields').hidden = true;
      err.hidden = true;
      $('#reset-status').hidden = false;
      $('#reset-status').innerHTML = '<b class="good-txt">✔ Je wachtwoord is gewijzigd.</b> Log nu in met je nieuwe wachtwoord.';
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      if (/verlopen|werkt niet meer/.test(ex.message)) $('#reset-fields').hidden = true;
    }
    b.disabled = false;
  });
}

// ---------------------------------------------------------------- Login
const loginForm = $('#login-form');
if (loginForm) {
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('.form-error', loginForm);
    const step2 = !$('#login-step2').hidden;
    try {
      if (step2) {
        await api('login_totp', { code: loginForm.code.value });
        return location.replace(location.pathname);   // zonder ?login=1, anders weer uitgelogd
      }
      const r = await api('login', { username: loginForm.username.value, password: loginForm.password.value, remember: loginForm.remember.checked });
      if (!r.totp_required) return location.replace(location.pathname);
      // Wachtwoord klopt; nu de code uit de authenticator-app
      $('#login-step1').hidden = true;
      $('#login-step2').hidden = false;
      $$('#login-step1 input').forEach((i) => (i.required = false));
      loginForm.code.required = true;
      err.hidden = true;
      loginForm.code.focus();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      if (step2 && /opnieuw in/.test(ex.message)) {
        $('#login-step1').hidden = false;
        $('#login-step2').hidden = true;
        $$('#login-step1 input').forEach((i) => (i.required = true));
        loginForm.code.required = false;
        loginForm.code.value = '';
        loginForm.password.value = '';
        loginForm.password.focus();
      } else if (step2) {
        loginForm.code.select();
      }
    }
  });
} else if (!resetForm) {
  init();
}

// ---------------------------------------------------------------- App
function init() {
  $$('.tab').forEach((t) => t.addEventListener('click', () => showTab(t.dataset.tab)));
  showTab(location.hash.slice(1) || 'dashboard');

  $('#theme-toggle').onclick = () => {
    const cur = document.documentElement.dataset.theme
      || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    const next = cur === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('rzmon-theme', next); } catch (e) {}
  };
  if ($('#logout')) $('#logout').onclick = async () => { await api('logout', {}); location.reload(); };
  $('#add-host').onclick = () => hostForm();
  $('#run-all').onclick = async (e) => {
    e.target.disabled = true;
    try { const r = await api('run_all', {}); toast(`${r.count} checks uitgevoerd`); await refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#filter').addEventListener('input', render);
  $('#only-problems').addEventListener('change', () => { statusFilter = null; render(); });
  $('#health-state').onclick = () => {
    const op = $('#only-problems');
    op.checked = statusFilter !== null || !op.checked;
    statusFilter = null;
    render();
  };
  $('#health-counts').addEventListener('click', (e) => {
    const b = e.target.closest('[data-st]');
    if (!b) return;
    const st = +b.dataset.st;
    statusFilter = statusFilter === st ? null : st;
    $('#only-problems').checked = false;
    render();
  });
  $('#health-info').addEventListener('click', (e) => {
    const b = e.target.closest('[data-host]');
    if (b) jumpToHost(b.dataset.host);
  });
  $('#hosts').addEventListener('click', onHostsClick);
  $('#hosts').addEventListener('keydown', (e) => {
    const head = e.target.closest('.host-head[data-act=toggle], .line-row[data-act=toggle]');
    if (head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleHost(head.dataset.id); }
  });
  $('#toggle-all').onclick = () => {
    const all = state.hosts.every((h) => openHosts.has(+h.id));
    openHosts.clear();
    if (!all) state.hosts.forEach((h) => openHosts.add(+h.id));
    saveOpen();
    render();
  };

  $('#settings-form').addEventListener('submit', saveSettings);
  $('#backup-now').onclick = backupNow;
  $('#update-start').onclick = startUpdate;
  $('#settings-form').update_auto.addEventListener('change', backupFormToggle);
  $('#update-check').onclick = async (e) => { const btn = e.currentTarget; btn.disabled = true; await loadUpdate(true); btn.disabled = false; };
  $('#update-badge').onclick = () => { showTab('settings'); setTimeout(() => $('#update-card').scrollIntoView({ behavior: 'smooth', block: 'start' }), 300); };
  $('#agents-update-all').onclick = updateAllAgents;
  $('#backup-upload').onclick = () => $('#backup-file').click();
  $('#backup-file').onchange = (e) => backupUpload(e.target.files[0]);
  $('#backup-list').addEventListener('click', onBackupListClick);
  $('#settings-form').backup_freq.addEventListener('change', backupFormToggle);
  $('#pushover-test').onclick = testPushover;
  $('#webhook-test').onclick = testWebhook;
  $('#email-test').onclick = (e) => testChannel(e, 'email_test', 'Testmail verstuurd 📧', 'E-mail');
  $('#mqtt-test').onclick = (e) => testChannel(e, 'mqtt_test', null, 'MQTT');
  $('#maint-all').onclick = () => maintenanceDialog('all', 0, 'alle servers', state.maintenance);
  $('#maint-banner').addEventListener('click', (e) => e.target.closest('[data-maint-end]') && endMaintenance('all', 0));
  $('#password-form').addEventListener('submit', changePassword);
  $('#user-form').addEventListener('submit', createUser);
  $('#db-rotate').onclick = dbRotateDialog;
  $$('[data-view-layout]').forEach((b) => (b.onclick = () => setViewPref('layout', b.dataset.viewLayout)));
  $$('[data-view-density]').forEach((b) => (b.onclick = () => setViewPref('density', b.dataset.viewDensity)));
  showViewPrefs();
  $('#users').addEventListener('click', onUsersClick);
  $('#users').addEventListener('change', async (e) => {
    const sel = e.target.closest('select[data-uact=role]');
    if (!sel) return;
    try { await api('user_role', { id: sel.dataset.id, role: sel.value }); toast('Rol gewijzigd'); }
    catch (ex) { toast(ex.message, 'err'); }
    loadUsers();
  });
  $('#api-key-form').addEventListener('submit', createApiKey);
  $('#api-keys').addEventListener('click', deleteApiKey);
  $('#access-form').addEventListener('submit', saveAccess);

  refresh();
  setInterval(() => { if (!document.hidden && !$('#modal').open) refresh(); }, 20000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

function showTab(name) {
  if (!$('#tab-' + name)) name = 'dashboard';
  $$('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  $$('.tab-panel').forEach((p) => (p.hidden = p.id !== 'tab-' + name));
  history.replaceState(null, '', '#' + name);
  if (name === 'settings') loadSettings();
}

async function refresh() {
  try {
    state = await api('state');
    if (state.role && document.body.dataset.role !== state.role) location.reload();   // rol gewijzigd
    render();
    renderAlerts();
    if (isAdmin()) renderAgentUpdates();
    const ub = $('#update-badge');
    ub.hidden = !state.update;
    if (state.update) ub.textContent = '⬆ ' + state.update;
  } catch (ex) {
    toast(ex.message, 'err');
  }
  // Beschikbaarheid en minigrafieken apart: mag iets langer duren
  try { stats = await api('stats'); render(); } catch (ex) { /* niet kritiek */ }
}

const pct = (v) => (v === null || v === undefined ? '–' : (v >= 99.995 ? '100' : v.toFixed(v >= 99 ? 2 : 1)).replace('.', ',') + '%');

/** Beschikbaarheid van een server (24 u / 7 d / 30 d), of '' zonder netwerkchecks. */
function uptimeLine(h, short) {
  const u = stats.uptime[h.id];
  if (!u) return '';
  if (short) return ` · <span title="Bereikbaar afgelopen 7 dagen">⏱ ${pct(u.d7)}</span>`;
  return `<span class="uptime" title="Percentage van de metingen (ping, of anders poort/HTTP) dat de server bereikbaar was">
    Bereikbaar: 24 u <b>${pct(u.h24)}</b> · 7 d <b>${pct(u.d7)}</b> · 30 d <b>${pct(u.d30)}</b></span>`;
}

/** Minigrafiek van de laatste 24 uur: lijn voor de waarde, gekleurde streepjes voor de status. */
function sparkSvg(c) {
  const data = stats.sparks[c.id];
  if (!data) return '';
  const W = 240, H = 26;
  const t = typeOf(c);
  const vals = data.filter((d) => d && d[0] !== null).map((d) => d[0]);
  const max = t.unit === '%' ? 100 : Math.max(...vals, +c.warn_threshold || 0, 1) * 1.1;
  const x = (i) => (i / 47) * W;
  const y = (v) => H - 6 - (v / max) * (H - 8);
  // Lege blokjes overslaan: de lijn loopt door naar het volgende meetpunt
  let line = '';
  data.forEach((d, i) => {
    if (d && d[0] !== null) line += `${line ? 'L' : 'M'}${x(i).toFixed(1)},${y(d[0]).toFixed(1)}`;
  });
  const strip = data.map((d, i) => (d ? `<rect class="s${d[1]}" x="${x(i) - W / 96}" y="${H - 3}" width="${W / 48 + .3}" height="3"/>` : '')).join('');
  return `<svg class="spark" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" data-act="history" data-id="${c.id}" role="img">
    <title>Laatste 24 uur – klik voor de grafiek</title>${t.unit && vals.length ? `<path d="${line}"/>` : ''}<g>${strip}</g></svg>`;
}

// 4 = alles in onderhoud
function hostStatus(h) {
  const enabled = h.checks.filter((c) => +c.enabled);
  const active = enabled.filter((c) => !c.maintenance);
  if (!active.length) return enabled.length || h.maintenance ? 4 : 3;
  const sts = active.map((c) => +c.status);
  if (sts.includes(2)) return 2;
  if (sts.includes(1)) return 1;
  if (sts.includes(3)) return 3;
  return 0;
}

const STATUS_RANK = { 2: 4, 1: 3, 3: 2, 0: 1, 4: 0 };

/** "14:30" voor vandaag, anders "di 21 okt 14:30" */
function fmtUntil(dt) {
  const d = new Date(dt.replace(' ', 'T'));
  const time = d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === new Date().toDateString()
    ? time : d.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' }) + ' ' + time;
}

function maintLabel(m) {
  // Automatisch onderhoud loopt "tot opgelost" (de datum is een placeholder ver in de toekomst)
  if (m.auto) return `🔧 automatisch, tot opgelost${m.reason ? ' – ' + m.reason : ''}`;
  return `🔧 tot ${fmtUntil(m.until)}${m.reason ? ' – ' + m.reason : ''}`;
}

// ---------------------------------------------------------------- Algehele gezondheid
// De toestand en score komen van de server (Health.php); hier alleen weergave en filters.
let statusFilter = null;   // null, of 0-3 (status) / 4 (onderhoud): alleen die checks tonen
const HEALTH_COUNTS = [[0, 'ok', 'OK'], [1, 'warning', 'W'], [2, 'critical', 'C'], [3, 'unknown', '?'], [4, 'maintenance', '🔧']];
const HEALTH_TITLES = { 0: 'OK', 1: 'warning', 2: 'critical', 3: 'onbekend', 4: 'in onderhoud' };

/** "12 min", "2 u 14 min", "3 d 4 u" */
function dur(dt) {
  const m = Math.max(0, Math.round((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 60000));
  if (m < 60) return `${m} min`;
  if (m < 1440) return `${Math.floor(m / 60)} u${m % 60 ? ` ${m % 60} min` : ''}`;
  return `${Math.floor(m / 1440)} d${Math.floor(m % 1440 / 60) ? ` ${Math.floor(m % 1440 / 60)} u` : ''}`;
}

function renderHealth() {
  const hl = state.health;
  if (!hl) return;
  const box = $('#health');
  box.className = 'summary health h-' + hl.state;
  $('#health-label').textContent = { ok: '🟢', attention: '🟠', outage: '🔴' }[hl.state] + ' ' + hl.label;
  $('#health-state').title = $('#only-problems').checked ? 'Klik: alles weer tonen' : 'Klik: alleen problemen tonen';
  $('#health-pct').textContent = hl.score + '%';
  $('#health-bar').style.width = hl.score + '%';
  $('#health-counts').innerHTML = HEALTH_COUNTS.map(([st, key, short]) => {
    const n = hl.counts[key];
    if (st === 4 && !n) return '';
    return `<button type="button" class="hc s${st} ${statusFilter === st ? 'on' : ''} ${n ? '' : 'zero'}" data-st="${st}"
      title="${n} check${n === 1 ? '' : 's'} ${HEALTH_TITLES[st]}${statusFilter === st ? ' – klik om het filter op te heffen' : ' – klik om alleen deze te tonen'}">
      ${st === 4 ? '' : '<span class="hc-dot"></span>'}<b>${n}</b> ${short}</button>`;
  }).join('');

  const parts = [];
  if (hl.quiet_until) parts.push(`<span class="quiet-now" title="Stille uren: er gaan geen meldingen uit. Daarna volgt één bericht met wat dan nog open is.">🌙 <b>Stil tot ${esc(fmtUntil(hl.quiet_until))}</b></span>`);
  const hostLink = (id, text) => `<button type="button" class="hl-link" data-host="${id}">${esc(text)}</button>`;
  const why = { agent: 'agent stil', parent: 'achter uitgevallen server' };
  const downs = hl.down.map((d) => hostLink(d.id, d.name) + (why[d.reason] ? ` (${why[d.reason]})` : '') + (d.acknowledged ? ' ✔' : '')).join(', ');
  parts.push(`<span class="${hl.down.length ? 'bad' : ''}" title="Servers die reageren (ping, of de agent rapporteert)">🖥 Servers bereikbaar <b>${hl.hosts_up}/${hl.hosts_total}</b>${downs ? ` – onbereikbaar: ${downs}` : ''}</span>`);
  parts.push(`<span title="Actieve checks met status OK (checks in onderhoud tellen niet mee)">✔ Checks OK <b>${hl.checks_ok}/${hl.checks_total}</b></span>`);
  if (hl.open_problems) {
    const o = hl.oldest_problem;
    parts.push(`<span title="Problemen die nog niet bevestigd zijn">⏳ <b>${hl.open_problems}</b> open ${hl.open_problems === 1 ? 'probleem' : 'problemen'}${o
      ? ` · langst: ${hostLink(o.host_id, o.host + ' – ' + o.check)} (${dur(o.since)})` : ''}</span>`);
  } else if (hl.state === 'ok') {
    parts.push('<span>Geen open problemen</span>');
  }
  const u = Object.values(stats.uptime).map((x) => x.h24).filter((v) => v !== null && v !== undefined);
  if (u.length) parts.push(`<span title="Gemiddelde bereikbaarheid van alle servers over de afgelopen 24 uur">📈 24 u beschikbaar <b>${pct(u.reduce((a, b) => a + b, 0) / u.length)}</b></span>`);
  if (hl.flapping) parts.push(`<span title="Checks die steeds van status wisselen">🔀 <b>${hl.flapping}</b> flapt</span>`);
  if (hl.counts.maintenance) parts.push(`<span title="Checks in onderhoud: geen meldingen">🔧 <b>${hl.counts.maintenance}</b> in onderhoud</span>`);
  $('#health-info').innerHTML = parts.join('<span class="hl-sep">·</span>');
}

/** Naar een server springen en hem openklappen (vanuit de gezondheidsbalk). */
function jumpToHost(id) {
  statusFilter = null;
  $('#filter').value = '';
  openHosts.add(+id);
  saveOpen();
  render();
  const head = $(`#hosts [data-act=toggle][data-id="${id}"]`);
  if (head) {
    const art = head.closest('article');
    art.scrollIntoView({ behavior: 'smooth', block: 'start' });
    art.classList.add('flash');
    setTimeout(() => art.classList.remove('flash'), 1600);
  }
}

function render() {
  renderHealth();
  const banner = $('#maint-banner');
  banner.hidden = !state.maintenance;
  if (state.maintenance) {
    banner.innerHTML = `<span><b>🔧 Onderhoud actief</b> tot ${esc(fmtUntil(state.maintenance.until))}${state.maintenance.reason ? ' – ' + esc(state.maintenance.reason) : ''}.
      Checks draaien door, maar er gaan geen meldingen uit.</span>
      <button class="btn need-user" data-maint-end="all">Beëindigen</button>`;
  }
  $('#last-run').textContent = state.last_run ? 'Laatste check: ' + ago(state.last_run) : '';
  let open = 0;
  state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && !c.maintenance && +c.status === 2 && !c.acked_at && open++));
  document.title = (open ? `(${open}) ` : '') + 'RzMon';

  const f = $('#filter').value.trim().toLowerCase();
  const onlyProblems = $('#only-problems').checked;
  const narrowed = onlyProblems || statusFilter !== null;
  const hosts = state.hosts
    .map((h) => {
      let checks = h.checks;
      const hostMatch = !f || (h.name + ' ' + h.address).toLowerCase().includes(f);
      if (!hostMatch) checks = checks.filter((c) => (c.name + ' ' + c.target).toLowerCase().includes(f));
      if (onlyProblems) checks = checks.filter((c) => +c.status !== 0 && +c.enabled);
      if (statusFilter !== null) checks = checks.filter((c) => +h.enabled && +c.enabled && (statusFilter === 4 ? !!c.maintenance : !c.maintenance && +c.status === statusFilter));
      return { ...h, shown: checks, match: hostMatch, autoOpen: !!f && !hostMatch && checks.length > 0 };
    })
    .filter((h) => (h.match && !narrowed) || h.shown.length)
    .sort((a, b) => STATUS_RANK[hostStatus(b)] - STATUS_RANK[hostStatus(a)] || a.name.localeCompare(b.name));

  const el = $('#hosts');
  if (!state.hosts.length) {
    el.innerHTML = `<div class="card empty">Nog geen servers. Klik op <b>＋ Server</b> om te beginnen.</div>`;
    return;
  }
  if (!hosts.length) {
    el.innerHTML = `<div class="card empty">${statusFilter !== null ? `Geen checks met status ${HEALTH_COUNTS.find((k) => k[0] === statusFilter)[2]}.`
      : onlyProblems ? '🎉 Geen problemen gevonden!' : 'Niets gevonden.'}</div>`;
    return;
  }
  const { layout, density } = viewPrefs();
  el.className = 'hosts layout-' + layout + (layout === 'l1nes' ? ' density-' + density : '');
  el.innerHTML = layout === 'l1nes'
    ? (density === 'large' ? lineHeader() : '') + hosts.map((h) => hostLine(h, density === 'large')).join('')
    : hosts.map(hostCard).join('');
  $('#toggle-all').textContent = state.hosts.length && state.hosts.every((h) => openHosts.has(+h.id)) ? '⊟ Alles sluiten' : '⊞ Alles openen';
}

// ---------------------------------------------------------------- Weergave (per apparaat)
// Blox = tegels (standaard), L1nes = balken onder elkaar; L1nes in Small of Large.
function viewPrefs() {
  let layout = 'blox', density = 'small';
  try {
    layout = localStorage.getItem('rzmon-layout') === 'l1nes' ? 'l1nes' : 'blox';
    density = localStorage.getItem('rzmon-density') === 'large' ? 'large' : 'small';
  } catch (e) { /* geen opslag: standaard */ }
  return { layout, density };
}
function setViewPref(key, value) {
  try { localStorage.setItem('rzmon-' + key, value); } catch (e) {}
  showViewPrefs();
  render();
}
function showViewPrefs() {
  const { layout, density } = viewPrefs();
  $$('[data-view-layout]').forEach((b) => b.classList.toggle('active', b.dataset.viewLayout === layout));
  $$('[data-view-density]').forEach((b) => { b.classList.toggle('active', b.dataset.viewDensity === density); b.disabled = layout !== 'l1nes'; });
}

// ---------------------------------------------------------------- Server-tegels
// Ingeklapt: alleen de algehele status. Klik = uitklappen met alle checks.
const openHosts = new Set((() => { try { return JSON.parse(localStorage.getItem('rzmon-open') || '[]'); } catch (e) { return []; } })());
function saveOpen() { try { localStorage.setItem('rzmon-open', JSON.stringify([...openHosts])); } catch (e) {} }
function toggleHost(id) {
  openHosts.has(+id) ? openHosts.delete(+id) : openHosts.add(+id);
  saveOpen();
  render();
}

function hostSummary(h) {
  const enabled = h.checks.filter((c) => +c.enabled);
  if (!enabled.length) return { text: 'Nog geen checks', cls: 's3' };
  const active = enabled.filter((c) => !c.maintenance);
  if (!active.length) return { text: '🔧 In onderhoud', cls: 's4' };
  const n = [0, 0, 0, 0];
  active.forEach((c) => n[+c.status]++);
  const acked = active.filter((c) => +c.status !== 0 && c.acked_at).length;
  const parts = [];
  if (n[2]) parts.push(`${n[2]} kritiek`);
  if (n[1]) parts.push(`${n[1]} waarschuwing`);
  if (n[3]) parts.push(`${n[3]} onbekend`);
  if (!parts.length) return { text: `Alles OK · ${active.length} check${active.length === 1 ? '' : 's'}`, cls: 's0' };
  return { text: parts.join(' · ') + (acked ? ` (${acked} bevestigd)` : ''), cls: 's' + hostStatus(h) };
}

/** Mini-meters voor CPU, RAM en de volste disk */
function hostMeters(h) {
  const pick = (type) => h.checks.filter((c) => c.type === type && +c.enabled && c.last_value !== null)
    .sort((a, b) => b.last_value - a.last_value)[0];
  return [['cpu', 'CPU'], ['ram', 'RAM'], ['disk', 'Disk']].map(([t, l]) => {
    const c = pick(t);
    if (!c) return '';
    const v = Math.min(100, Math.round(+c.last_value));
    return `<span class="meter s${c.maintenance ? 4 : c.status}" title="${esc(c.name)}: ${esc(c.last_message || '')}">
      <span>${l}</span><span class="meter-bar"><i style="width:${v}%"></i></span><b>${v}%</b></span>`;
  }).join('');
}

/** Onderdelen die Blox en L1nes allebei gebruiken. */
function hostParts(h) {
  const problems = h.checks
    .filter((c) => +c.enabled && +c.status !== 0)
    .sort((a, b) => STATUS_RANK[b.maintenance ? 4 : +b.status] - STATUS_RANK[a.maintenance ? 4 : +a.status]);
  const chips = (max) => problems.slice(0, max).map((c) =>
    `<span class="pchip s${c.maintenance ? 4 : c.status} ${c.acked_at ? 'acked' : ''}" title="${esc(c.last_message || '')}">${c.acked_at ? '✔ ' : ''}${esc(c.name)}</span>`).join('')
    + (problems.length > max ? `<span class="pchip more">+${problems.length - max}</span>` : '');
  const counts = [0, 1, 2, 3].map((s) => [s, h.checks.filter((c) => +c.enabled && !c.maintenance && +c.status === s).length])
    .filter(([, n]) => n).map(([s, n]) => `<span class="cnt s${s}" title="${STATUS[s]}">${n}</span>`).join('');
  const parent = h.parent_id ? findHost(h.parent_id) : null;
  return {
    st: hostStatus(h),
    sum: hostSummary(h),
    isOpen: openHosts.has(+h.id) || h.autoOpen,
    chips,
    counts,
    lastChecked: h.checks.reduce((m, c) => (c.last_checked && (!m || c.last_checked > m) ? c.last_checked : m), null),
    title: `${esc(h.name)} <span class="badge">${esc(h.mode)}</span>${+h.enabled ? '' : '<span class="badge">uit</span>'}`,
    via: parent ? ` <span class="via" title="Afhankelijk van ${esc(parent.name)}: geen aparte meldingen als die onbereikbaar is">↳ via ${esc(parent.name)}</span>` : '',
    maint: h.maintenance ? `<span class="maint-tag">${esc(maintLabel(h.maintenance))}</span>` : '',
    cls: `card host s${hostStatus(h)} ${+h.enabled ? '' : 'disabled'} ${h.maintenance ? 'in-maint' : ''} ${openHosts.has(+h.id) || h.autoOpen ? 'open' : ''}`,
  };
}

/**
 * Uitgeklapt deel: knoppen en alle checks. Blox: checks als tegels. L1nes (lines = 'small' of 'large'):
 * checks als rijen, net als de servers, met problemen bovenaan.
 */
function hostDetail(h, lines = null) {
  const agentInfo = h.mode === 'agent'
    ? `<span class="muted small">Agent: ${h.agent_seen ? ago(h.agent_seen) : 'nog niet gezien'}</span>
       <button class="btn need-admin" data-act="agent" data-id="${h.id}">Agent Manager</button>` : '';
  return `
    <div class="host-detail">
      <div class="detail-bar">
        ${h.checks.some((c) => +c.enabled && +c.status !== 0 && !c.acked_at) ? `<button class="btn ack-btn need-user" data-act="ack-host" data-id="${h.id}">✔ Alles bevestigen</button>` : ''}
        <button class="btn need-user" data-act="maint-host" data-id="${h.id}">🔧 Onderhoud</button>
        <button class="btn need-admin" data-act="add-check" data-id="${h.id}">＋ Check</button>
        <button class="btn need-user" data-act="scan" data-id="${h.id}">🔍 Poorten</button>
        ${h.metrics.includes('podman') ? `<button class="btn" data-act="podman" data-id="${h.id}">📦 Containers</button>` : ''}
        <button class="btn need-admin" data-act="edit-host" data-id="${h.id}">✎ Bewerken</button>
        ${agentInfo ? `<span class="spacer"></span>${agentInfo}` : ''}
      </div>
      ${uptimeLine(h, false)}
      ${lines ? checkLines(h, lines === 'large') : `<ul class="checks">
        ${h.shown.length ? h.shown.map(checkRow).join('') : '<li class="empty small">Nog geen checks — klik op ＋ Check</li>'}
      </ul>`}
    </div>`;
}

// Blox: een tegel per server
function hostCard(h) {
  const p = hostParts(h);
  return `
  <article class="${p.cls} tile">
    <div class="host-head" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? 'Inklappen' : 'Klik voor alle checks'}">
      <span class="dot"></span>
      <div class="host-title">
        <strong>${p.title}</strong>
        <span class="muted mono">${esc(h.address)}</span>${p.via}
        ${p.maint}
      </div>
      <span class="counts">${p.counts}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    <div class="host-sum" data-act="toggle" data-id="${h.id}">
      <div class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</div>
      ${p.chips(4) ? `<div class="chips">${p.chips(4)}</div>` : ''}
      ${hostMeters(h) ? `<div class="meters">${hostMeters(h)}</div>` : ''}
      <div class="muted small sum-foot">${p.lastChecked ? 'Laatste check ' + ago(p.lastChecked) : ''}${h.mode === 'agent' ? ` · agent ${h.agent_seen ? ago(h.agent_seen) : 'nog niet gezien'}${agentVer(h)}` : ''}${uptimeLine(h, true)}</div>
    </div>
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
}

// L1nes: een balk over de volle breedte per server, als een tabel (Small of Large)
/** Eén meter (CPU, RAM of de volste disk) als tabelcel, of een streepje. */
function meterCell(h, type) {
  const c = h.checks.filter((x) => x.type === type && +x.enabled && x.last_value !== null).sort((a, b) => b.last_value - a.last_value)[0];
  if (!c) return '<span class="muted">–</span>';
  const v = Math.min(100, Math.round(+c.last_value));
  return `<span class="meter s${c.maintenance ? 4 : c.status}" title="${esc(c.name)}: ${esc(c.last_message || '')}">
    <span class="meter-bar"><i style="width:${v}%"></i></span><b>${v}%</b></span>`;
}

function hostLine(h, large) {
  const p = hostParts(h);
  const u = stats.uptime[h.id];
  const agent = h.mode === 'agent' ? `agent ${h.agent_seen ? ago(h.agent_seen) : 'nog niet gezien'}${agentVer(h)}` : '';
  const cells = large
    ? `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.chips(5) ? `<span class="chips">${p.chips(5)}</span>` : ''}</span>
       <span class="lc-m">${meterCell(h, 'cpu')}</span>
       <span class="lc-m">${meterCell(h, 'ram')}</span>
       <span class="lc-m">${meterCell(h, 'disk')}</span>
       <span class="lc-up" title="Bereikbaar afgelopen 7 dagen">${u ? pct(u.d7) : '<span class="muted">–</span>'}</span>
       <span class="lc-last muted small">${p.lastChecked ? ago(p.lastChecked) : ''}${agent ? `<br>${agent}` : ''}</span>`
    : `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span></span>
       <span class="counts">${p.counts}</span>`;
  return `
  <article class="${p.cls} line ${large ? 'large' : 'small'}">
    <div class="line-row" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? 'Inklappen' : 'Klik voor alle checks'}">
      <span class="dot"></span>
      <span class="lc-name"><strong>${p.title}</strong>${large ? `<span class="muted mono small">${esc(h.address)}</span>${p.via}${p.maint}` : ''}</span>
      ${large ? '' : `<span class="lc-addr muted mono small">${esc(h.address)}</span>`}
      ${cells}
      <span class="chev" aria-hidden="true">›</span>
    </div>
    ${p.isOpen ? hostDetail(h, large ? 'large' : 'small') : ''}
  </article>`;
}

/** Koppen boven de kolommen van L1nes Large. */
function lineHeader() {
  return `<div class="line-head" aria-hidden="true"><span></span><span>Server</span><span>Status</span>
    <span class="lc-m">CPU</span><span class="lc-m">RAM</span><span class="lc-m">Disk</span><span class="lc-up">Bereikbaar</span><span class="lc-last">Laatste check</span><span></span></div>`;
}

/** Wat een check-tegel (Blox) en een check-rij (L1nes) allebei tonen. */
function checkBits(c) {
  const t = typeOf(c);
  const s = +c.status;
  const pctVal = t.unit === '%' && c.last_value !== null ? Math.min(100, +c.last_value) : null;
  const ticks = (pctVal !== null ? (c.warn_threshold !== null ? `<b style="left:${+c.warn_threshold}%"></b>` : '')
    + (c.crit_threshold !== null ? `<b style="left:${+c.crit_threshold}%"></b>` : '') : '');
  const num = ['ms', 'dagen', 'MB', 'treffers', 'stuks'].includes(t.unit) && c.last_value !== null
    ? (t.unit === 'dagen' && c.type !== 'ha' ? Math.floor(c.last_value) + ' dagen' : fmt(c.last_value, t.unit).replace('.', ',')) : '';
  const own = c.maintenance && c.maintenance.scope === 'check';
  const problem = s !== 0 && +c.enabled;
  const ackTitle = c.acked_at ? `Bevestigd door ${c.acked_by || '?'} – ${ago(c.acked_at)}${c.ack_comment ? ': ' + c.ack_comment : ''}. Klik om in te trekken.` : '';
  const flags = (problem && c.acked_at ? ` <button class="ack-tag need-user-act" data-act="unack" data-id="${c.id}" title="${esc(ackTitle)}">✔ bevestigd</button>` : '')
    + (own ? ` <span class="maint-tag" title="${esc(maintLabel(c.maintenance))}">${c.maintenance.auto ? '🔧 auto, tot opgelost' : `🔧 tot ${esc(fmtUntil(c.maintenance.until))}`}</span>` : '')
    + (c.suppressed_by ? ' <span class="supp-tag" title="Geen melding: een server erboven is onbereikbaar">🔕 onderdrukt</span>' : '')
    + (c.notify ? ' <span class="own-notify" title="Deze check heeft eigen meldregels">🔔</span>' : '')
    + ((q) => (q && q.mode === 'never' ? ' <span class="quiet-tag" title="Nooit stil: meldt ook tijdens de stille uren">🌙✕</span>'
      : q && q.mode === 'own' ? ` <span class="quiet-tag" title="Eigen stille uren: ${esc(quietText(q.windows))}">🌙</span>` : ''))(quietOf(c))
    + (c.flapping_since ? ` <span class="flap-tag" title="Wisselt steeds van status sinds ${esc(fmtUntil(c.flapping_since))}: per status hooguit één melding tot hij stabiel is">🔀 flapt</span>` : '');
  const actions = `
      ${problem && !c.acked_at ? `<button class="icon-btn ack-btn need-user" title="Storing bevestigen: geen meldingen meer" data-act="ack" data-id="${c.id}">✔</button>` : ''}
      <button class="icon-btn need-user" title="Onderhoud" data-act="maint-check" data-id="${c.id}">🔧</button>
      <button class="icon-btn need-user" title="Nu checken" data-act="run" data-id="${c.id}">↻</button>
      <button class="icon-btn" title="Historie" data-act="history" data-id="${c.id}">📈</button>
      <button class="icon-btn need-admin" title="Bewerken" data-act="edit-check" data-id="${c.id}">✎</button>`;
  return {
    t, s, pctVal, ticks, num, problem, flags, actions,
    cls: `s${s} ${+c.enabled ? '' : 'disabled'} ${c.maintenance ? 'in-maint' : ''} ${problem && c.acked_at ? 'acked' : ''}`,
    title: `${STATUS[s]} – laatst ${ago(c.last_checked)}`,
    msg: esc(c.last_message || 'Nog niet gecontroleerd'),
  };
}

// Blox: een check als tegel
function checkRow(c) {
  const b = checkBits(c);
  const bar = b.pctVal !== null ? `<div class="bar" title="${fmt(b.pctVal, '%')}"><i style="width:${b.pctVal}%"></i>${b.ticks}</div>` : '';
  return `
  <li class="chk ${b.cls}" title="${b.title}">
    <span class="dot"></span>
    <span class="chk-name">${esc(c.name)}<span class="type">${b.t.label}${b.num ? ` · ${b.num}` : ''}</span>${b.flags}</span>
    <span class="chk-actions">${b.actions}
    </span>
    ${bar}
    ${sparkSvg(c)}
    <span class="chk-msg">${b.msg} · ${ago(c.last_checked)}</span>
  </li>`;
}

// L1nes: checks als rijen onder de server, problemen bovenaan (kritiek → waarschuwing → onbekend → OK →
// onderhoud → uitgeschakeld); binnen dezelfde status de volgorde waarin ze zijn toegevoegd.
function checkLines(h, large) {
  if (!h.shown.length) return '<p class="empty small check-lines-empty">Nog geen checks — klik op ＋ Check</p>';
  const rank = (c) => (+c.enabled ? STATUS_RANK[c.maintenance ? 4 : +c.status] : -1);
  const sorted = h.shown.map((c, i) => [c, i]).sort((a, b) => rank(b[0]) - rank(a[0]) || a[1] - b[1]).map(([c]) => c);
  const head = large ? `<div class="cl-head" aria-hidden="true"><span></span><span>Check</span><span>Waarde</span><span class="cl-spark">24 uur</span>
    <span>Bericht</span><span class="cl-last">Laatst</span><span></span></div>` : '';
  return `<div class="check-lines ${large ? 'large' : 'small'}">${head}${sorted.map((c) => checkLine(c, large)).join('')}</div>`;
}

function checkLine(c, large) {
  const b = checkBits(c);
  const value = b.pctVal !== null
    ? `<span class="cl-meter" title="${fmt(b.pctVal, '%')}"><span class="bar"><i style="width:${b.pctVal}%"></i>${b.ticks}</span><b>${Math.round(b.pctVal)}%</b></span>`
    : b.num ? `<span class="cl-num">${b.num}</span>` : '<span class="muted">–</span>';
  return `
  <div class="cl chk ${b.cls}" title="${b.title}">
    <span class="dot"></span>
    <span class="cl-name"><span class="cl-title">${esc(c.name)}</span>${large ? `<span class="type">${b.t.label}</span>` : ` <span class="type">${b.t.label}</span>`}${b.flags ? `<span class="cl-flags">${b.flags}</span>` : ''}</span>
    <span class="cl-value">${value}</span>
    ${large ? `<span class="cl-spark">${sparkSvg(c) || '<span class="muted small">–</span>'}</span>` : ''}
    <span class="cl-msg">${b.msg}</span>
    <span class="cl-last muted small">${ago(c.last_checked)}</span>
    <span class="chk-actions">${b.actions}
    </span>
  </div>`;
}

function findHost(id) { return state.hosts.find((h) => +h.id === +id); }
function findCheck(id) {
  for (const h of state.hosts) { const c = h.checks.find((c) => +c.id === +id); if (c) return [c, h]; }
  return [null, null];
}

async function onHostsClick(e) {
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const id = b.dataset.id;
  switch (b.dataset.act) {
    case 'add-check': return checkForm(null, findHost(id));
    case 'edit-host': return hostForm(findHost(id));
    case 'edit-check': { const [c, h] = findCheck(id); return checkForm(c, h); }
    case 'history': { const [c, h] = findCheck(id); return showHistory(c, h); }
    case 'agent': return agentInstructions(findHost(id));
    case 'scan': return portScan(findHost(id));
    case 'podman': return podmanOverview(findHost(id));
    case 'toggle': return toggleHost(id);
    case 'ack': { const [c, h] = findCheck(id); return ackDialog('check', id, `${h.name} – ${c.name}`, c.last_message); }
    case 'ack-host': { const h = findHost(id); const n = h.checks.filter((c) => +c.enabled && +c.status !== 0 && !c.acked_at).length; return ackDialog('host', id, h.name, `${n} onbevestigde storing(en)`); }
    case 'unack':
      if (!confirm('Bevestiging intrekken? Er worden dan weer meldingen verstuurd voor deze storing.')) return;
      try { await api('unack', { id }); toast('Bevestiging ingetrokken'); refresh(); } catch (ex) { toast(ex.message, 'err'); }
      return;
    case 'maint-host': { const h = findHost(id); return maintenanceDialog('host', id, h.name, h.maintenance && h.maintenance.scope === 'host' ? h.maintenance : null); }
    case 'maint-check': { const [c, h] = findCheck(id); return maintenanceDialog('check', id, `${h.name} – ${c.name}`, c.maintenance && c.maintenance.scope === 'check' ? c.maintenance : null); }
    case 'run':
      b.disabled = true;
      try {
        const r = await api('check_run', { id });
        toast(`${STATUS[r.result.status]}: ${r.result.message}`, r.result.status === 0 ? 'ok' : 'err');
        await refresh();
      } catch (ex) { toast(ex.message, 'err'); }
      b.disabled = false;
  }
}

// ---------------------------------------------------------------- Modal helper
function modal({ title, body, buttons = [], wide = false, onSubmit }) {
  const dlg = $('#modal');
  const form = $('#modal-form');
  dlg.classList.toggle('wide', wide);
  $('#modal-title').textContent = title;
  $('#modal-body').innerHTML = body;
  const err = $('.form-error', form);
  err.hidden = true;
  const footer = $('#modal-footer');
  footer.innerHTML = '';
  buttons.forEach((b) => {
    const btn = document.createElement('button');
    btn.className = 'btn ' + (b.cls || '');
    btn.textContent = b.label;
    btn.type = b.submit ? 'submit' : 'button';
    if (b.style) btn.style.cssText = b.style;
    if (b.onClick) btn.onclick = () => b.onClick(btn);
    footer.append(btn);
  });
  form.onsubmit = async (e) => {
    if (e.submitter?.value === 'cancel') return;
    e.preventDefault();
    if (!onSubmit) return dlg.close();
    const sb = $('button[type=submit]', footer);
    if (sb) sb.disabled = true;
    try {
      await onSubmit(Object.fromEntries(new FormData(form)), form);
      dlg.close();
      await refresh();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
    }
    if (sb) sb.disabled = false;
  };
  if (!dlg.open) dlg.showModal();
  return { dlg, form, err };
}

function modalError(msg) {
  const err = $('#modal-form .form-error');
  err.textContent = msg;
  err.hidden = false;
}

// ---------------------------------------------------------------- Host form
function hostForm(h) {
  const isNew = !h;
  h = h || { name: '', address: '', mode: 'remote', enabled: 1 };
  modal({
    title: isNew ? 'Server toevoegen' : `Server bewerken – ${h.name}`,
    body: `
      <label>Naam<input name="name" value="${esc(h.name)}" required placeholder="bv. Webserver 01"></label>
      <label>Hostname / IP-adres<input name="address" value="${esc(h.address)}" required placeholder="192.168.1.10" class="mono"></label>
      <label>Type
        <select name="mode">${Object.entries(MODES).map(([k, v]) => `<option value="${k}" ${h.mode === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
        <span class="hint">Lokaal = disk/services van deze monitor-server. Agent = remote server die disk/services rapporteert via rzmon-agent.sh. Remote = alleen ping/poort/http.</span>
      </label>
      <label>Afhankelijk van <span class="hint">(optioneel)</span>
        <select name="parent_id">
          <option value="">— geen —</option>
          ${parentOptions(h)}
        </select>
        <span class="hint">Bereikbaar via deze server, bv. een router, switch, VPN of hypervisor. Is die onbereikbaar, dan krijg je één melding voor die server en niet ook voor deze.</span>
      </label>
      <label class="switch"><input type="checkbox" name="enabled" ${+h.enabled ? 'checked' : ''}><span></span> Actief</label>
      ${isNew ? '<label class="switch"><input type="checkbox" name="add_ping" checked><span></span> Direct een ping-check toevoegen</label>' : ''}`,
    buttons: [
      ...(isNew ? [] : [{ label: '🗑 Verwijderen', cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(`Server "${h.name}" en al zijn checks verwijderen?`)) return;
        await api('host_delete', { id: h.id });
        $('#modal').close(); toast('Server verwijderd'); refresh();
      } }]),
      { label: 'Opslaan', cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      const r = await api('host_save', { ...d, id: h.id, enabled: !!d.enabled, add_ping: !!d.add_ping });
      toast('Server opgeslagen');
      if (isNew && d.mode === 'agent') {
        await refresh();
        setTimeout(() => agentInstructions(findHost(r.id)), 50);
      }
    },
  });
}

/** Servers die als parent kunnen dienen: niet de server zelf en niets wat (indirect) eronder hangt. */
function parentOptions(h) {
  const below = new Set(h.id ? [+h.id] : []);
  let grew = true;
  while (grew) {
    grew = false;
    state.hosts.forEach((x) => {
      if (x.parent_id && below.has(+x.parent_id) && !below.has(+x.id)) { below.add(+x.id); grew = true; }
    });
  }
  return state.hosts
    .filter((x) => !below.has(+x.id))
    .map((x) => `<option value="${x.id}" ${+h.parent_id === +x.id ? 'selected' : ''}>${esc(x.name)} (${esc(x.address)})</option>`)
    .join('');
}

// ---------------------------------------------------------------- Acknowledge
function ackDialog(scope, id, label, detail) {
  modal({
    title: `✔ Bevestigen – ${label}`,
    body: `
      <p class="muted small" style="margin-top:0">${esc(detail || '')}</p>
      <p style="margin-top:0">Na bevestigen krijg je voor deze storing <b>geen meldingen meer</b>: geen herhalingen, en ook niet als hij erger wordt.
        Zodra de check weer OK is vervalt de bevestiging; gaat het later opnieuw mis, dan krijg je gewoon weer een melding.</p>
      <label>Opmerking <span class="hint">(optioneel)</span><input name="comment" maxlength="200" placeholder="bv. schijf wordt morgen vervangen" autofocus></label>`,
    buttons: [{ label: '✔ Bevestigen', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api(scope === 'host' ? 'ack_host' : 'ack', { id, comment: d.comment });
      toast(scope === 'host' ? `${r.count} storing(en) bevestigd` : 'Storing bevestigd');
    },
  });
}

// ---------------------------------------------------------------- Maintenance
function maintenanceDialog(scope, id, label, current) {
  const presets = [[15, '15 min'], [30, '30 min'], [60, '1 uur'], [120, '2 uur'], [240, '4 uur'], [480, '8 uur'], [1440, '24 uur'], [10080, '1 week']];
  modal({
    title: `🔧 Onderhoud – ${label}`,
    body: `
      <p class="muted small" style="margin-top:0">Checks blijven draaien en je ziet de status gewoon, maar er gaan <b>geen meldingen</b> uit (Pushover en webhook).
        Is er na afloop nog iets mis, dan krijg je alsnog een melding.</p>
      ${current ? `<p class="maint-now">Nu actief: ${esc(maintLabel(current))}</p>` : ''}
      <div class="presets">${presets.map(([m, l]) => `<button type="button" class="btn" data-min="${m}">${l}</button>`).join('')}</div>
      <label>Of tot een vast tijdstip<input type="datetime-local" name="until"></label>
      <label>Reden <span class="hint">(optioneel)</span><input name="reason" maxlength="200" placeholder="bv. updates installeren" value="${esc(current?.reason || '')}"></label>`,
    buttons: [
      ...(current ? [{ label: 'Onderhoud beëindigen', cls: 'danger', style: 'margin-right:auto', onClick: () => endMaintenance(scope, id) }] : []),
      { label: 'Tot dit tijdstip', cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      if (!d.until) throw new Error('Kies een duur of een tijdstip');
      await api('maintenance_set', { scope, id, until: d.until, reason: d.reason });
      toast('Onderhoud ingesteld');
    },
  });
  $$('[data-min]', $('#modal-body')).forEach((b) => (b.onclick = async () => {
    try {
      await api('maintenance_set', { scope, id, minutes: +b.dataset.min, reason: $('#modal-form').reason.value });
      $('#modal').close();
      toast(`Onderhoud: ${b.textContent}`);
      refresh();
    } catch (ex) { modalError(ex.message); }
  }));
}

async function endMaintenance(scope, id) {
  try {
    await api('maintenance_set', { scope, id, minutes: 0 });
    if ($('#modal').open) $('#modal').close();
    toast('Onderhoud beëindigd');
    refresh();
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Check form
function checkForm(c, h, preset = {}) {
  const isNew = !c;
  c = c || { type: 'ping', target: '', name: '', warn_threshold: TYPES.ping.warn, crit_threshold: TYPES.ping.crit, interval_sec: 60, enabled: 1, ...preset };
  const quietOwn = quietOf(c);
  const allowed = h.mode === 'remote' ? ['ping', 'tcp', 'http', 'ssl']
    : Object.keys(TYPES).filter((k) => h.mode === 'agent' || !['podman', 'container', 'log', 'ha'].includes(k))
      .filter((k) => k !== 'ha' || h.metrics.some((m) => m.startsWith('ha:') || m === 'service:supervisor') || c?.type === 'ha');
  const disks = h.metrics.filter((m) => m.startsWith('disk:')).map((m) => m.slice(5));
  const svcs = h.metrics.filter((m) => m.startsWith('service:')).map((m) => m.slice(8));
  let opts = {};
  try { opts = JSON.parse(c.options || '{}') || {}; } catch (e) { opts = {}; }
  const certs = h.metrics.filter((m) => m.startsWith('cert:')).map((m) => m.slice(5));
  const containers = h.metrics.filter((m) => m.startsWith('container:')).map((m) => m.slice(10));

  const { form } = modal({
    title: isNew ? `Check toevoegen – ${h.name}` : `Check bewerken – ${c.name}`,
    body: `
      <label>Type
        <select name="type">${allowed.map((k) => `<option value="${k}" ${c.type === k ? 'selected' : ''}>${TYPES[k].label}</option>`).join('')}</select>
        <span class="hint" id="type-help"></span>
      </label>
      <div id="log-wrap" hidden>
        <label>Voorinstelling
          <select name="preset">${Object.entries(LOG_PRESETS).map(([k, p]) => `<option value="${k}" ${(opts.preset || 'custom') === k ? 'selected' : ''}>${p.label}</option>`).join('')}</select>
        </label>
        <label>Zoek naar <span class="hint">(regex; hoofdletters maakt niet uit; | = of)</span>
          <input name="pattern" class="mono" value="${esc(opts.pattern || '')}" placeholder="bv. Failed password|Invalid user"></label>
        <span class="hint">Bron: een logbestand (alleen in mappen die op de server zijn toegestaan, standaard /var/log), <code>journal</code> (systemlog), <code>journal:sshd</code> (log van één service) of <code>kernel</code>. Drempels: aantal treffers in de periode.</span>
      </div>
      <label id="target-wrap"><span id="target-label"></span><input name="target" value="${esc(c.target)}" list="target-list" class="mono"></label>
      <label id="ha-wrap" hidden>Bewaken
        <select name="metric">${Object.entries(HA_METRICS).map(([k, m]) => `<option value="${k}" ${(c.metric || 'backup') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="ha-help"></span>
      </label>
      <div id="res-wrap" hidden>
        <p class="hint" style="margin-top:0">✓ De <b>status</b> wordt altijd bewaakt: gestopt of ongezond = CRITICAL, gepauzeerd of net automatisch herstart = WARNING. Vink aan wat je daarnaast wilt bewaken:</p>
        ${['cpu', 'mem', 'disk'].map((k) => {
          const r = (opts.res || {})[k];
          const u = k === 'disk' ? 'MB' : '%';
          return `<div class="res-row">
            <label class="switch small" title="${esc(METRICS[k].help)}"><input type="checkbox" name="res_${k}" ${r ? 'checked' : ''}><span></span> ${METRICS[k].label}</label>
            <label>Warning vanaf <span class="hint">${u}</span><input type="number" step="any" min="0" name="res_${k}_warn" value="${r ? (r[0] ?? '') : METRICS[k].warn}"></label>
            <label>Critical vanaf <span class="hint">${u}</span><input type="number" step="any" min="0" name="res_${k}_crit" value="${r ? (r[1] ?? '') : METRICS[k].crit}"></label>
          </div>`;
        }).join('')}
        <p class="hint">CPU: 100% = één volledige core. RAM: percentage van de geheugenlimiet van de container. Disk: schrijflaag plus volumes.</p>
      </div>
      <datalist id="target-list"></datalist>
      <div id="svc-pick-wrap" hidden>
        <div class="svc-pick-head"><span class="hint" id="svc-pick-info">Services op deze server ophalen…</span></div>
        <div class="svc-pick" id="svc-pick" role="listbox"></div>
      </div>
      <label>Naam <span class="hint">(optioneel)</span><input name="name" value="${esc(c.name)}"></label>
      <details class="notify-own" ${c.notify ? 'open' : ''}>
        <summary>🔔 Meldingen: <span id="notify-mode-label">${c.notify ? 'eigen regels' : 'standaard (algemene instellingen)'}</span></summary>
        <label class="switch small"><input type="checkbox" id="notify-own" ${c.notify ? 'checked' : ''}><span></span> Eigen meldregels voor deze check</label>
        <div id="notify-own-editor" ${c.notify ? '' : 'hidden'}></div>
      </details>
      <details class="notify-own" ${quietOwn ? 'open' : ''}>
        <summary>🌙 Stille uren: <span id="quiet-mode-label">${QUIET_MODES[quietOwn ? quietOwn.mode : 'global']}</span></summary>
        <select id="quiet-mode">${Object.entries(QUIET_MODES).map(([k, l]) => `<option value="${k}" ${(quietOwn ? quietOwn.mode : 'global') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <div id="quiet-own-editor" hidden></div>
      </details>
      <div class="row3" id="thr-wrap">
        <label><span class="thr-word">Warning vanaf</span> <span class="hint unit"></span><input name="warn_threshold" type="number" step="any" value="${c.warn_threshold ?? ''}"></label>
        <label><span class="thr-word">Critical vanaf</span> <span class="hint unit"></span><input name="crit_threshold" type="number" step="any" value="${c.crit_threshold ?? ''}"></label>
        <label>Interval <span class="hint">sec</span><input name="interval_sec" type="number" min="30" step="30" value="${c.interval_sec}"></label>
      </div>
      <div id="deep-wrap" hidden>
        <label class="switch"><input type="checkbox" name="deep" ${+(opts.deep ?? 1) ? 'checked' : ''}><span></span> Diepere controle</label>
        <span class="hint">Niet alleen "aan", maar ook: luistert hij op zijn poorten, antwoordt hij (HTTP, database, SSH, mail, DNS…), hoe vaak is hij herstart en staan er fouten in het log.</span>
        <label style="margin-top:.6rem">Eigen poort(en) of socket <span class="hint">(optioneel)</span>
          <input name="ports" class="mono" value="${esc(opts.ports || '')}" placeholder="bv. 8080, 127.0.0.1:9000 of /run/php-fpm/www.sock">
          <span class="hint">Voor als de agent de poort zelf niet vindt (container, socket, andere procesnaam). Wordt altijd getest, en de test van de dienst (HTTP, SSH…) gebruikt deze poort.</span>
        </label>
        <div class="row2" style="margin-top:.6rem">
          <label>Extra test <span class="hint">(optioneel)</span>
            <select name="test_type">
              <option value="">Geen</option>
              <option value="url" ${opts.test_type === 'url' ? 'selected' : ''}>URL moet antwoorden</option>
              <option value="port" ${opts.test_type === 'port' ? 'selected' : ''}>Poort moet antwoorden</option>
            </select>
          </label>
          <label id="test-target-wrap">Adres<input name="test_target" class="mono" value="${esc(opts.test_target || '')}"></label>
        </div>
        <label id="test-expect-wrap">Moet deze tekst bevatten <span class="hint">(optioneel)</span><input name="test_expect" value="${esc(opts.test_expect || '')}" placeholder="bv. OK of &quot;status&quot;:&quot;up&quot;"></label>
        <span class="hint">De agent voert de test uit op de server zelf, dus <code>localhost</code> werkt ook. Nieuwe tests worden binnen 10 minuten opgepikt.</span>
      </div>
      <label class="switch" id="verify-wrap"><input type="checkbox" name="verify_ssl" ${+(c.verify_ssl ?? 1) ? 'checked' : ''}><span></span> Certificaat controleren <span class="hint">(uit bij self-signed, bv. Proxmox of een NAS)</span></label>
      <label class="switch"><input type="checkbox" name="enabled" ${+c.enabled ? 'checked' : ''}><span></span> Actief</label>`,
    buttons: [
      ...(isNew ? [] : [{ label: '🗑 Verwijderen', cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(`Check "${c.name}" verwijderen?`)) return;
        await api('check_delete', { id: c.id });
        $('#modal').close(); toast('Check verwijderd'); refresh();
      } }]),
      { label: isNew ? 'Toevoegen & checken' : 'Opslaan & checken', cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      const notify = $('#notify-own').checked && ownEditor ? ownEditor.get() : null;
      const qm = $('#quiet-mode').value;
      const quiet = qm === 'global' ? null : qm === 'never' ? { mode: 'never' } : { mode: 'own', windows: quietOwnEditor ? quietOwnEditor.get().windows : [] };
      if (qm === 'own' && !quiet.windows.length) throw new Error('Stille uren: vul minstens één venster in met een dag');
      const res = {};
      ['cpu', 'mem', 'disk'].forEach((k) => { res[k] = { on: !!d[`res_${k}`], warn: d[`res_${k}_warn`], crit: d[`res_${k}_crit`] }; });
      await api('check_save', { ...d, res, notify, quiet, id: c.id, host_id: h.id, enabled: !!d.enabled, verify_ssl: !!d.verify_ssl, deep: !!d.deep });
      toast('Check opgeslagen');
    },
  });

  // Eigen meldregels: start met wat er al was, of met de algemene instellingen
  let ownEditor = null;
  const showOwn = async () => {
    const on = $('#notify-own').checked;
    $('#notify-own-editor').hidden = !on;
    $('#notify-mode-label').textContent = on ? 'eigen regels' : 'standaard (algemene instellingen)';
    if (on) $('#modal').classList.add('wide');   // de tabel heeft ruimte nodig
    if (on && !ownEditor) {
      // Basis: de algemene instellingen; daaroverheen wat deze check al zelf had
      let own = null;
      try { own = JSON.parse(c.notify || 'null'); } catch (e) { own = null; }
      const g = await api('settings');
      const parse = (v) => { try { return JSON.parse(v || '{}') || {}; } catch (e) { return {}; } };
      const cfg = { rules: { ...parse(g.notify_rules), ...((own && own.rules) || {}) }, channels: { ...parse(g.notify_channels), ...((own && own.channels) || {}) },
        flap: (own && own.flap) || parse(g.notify_flap) };
      ownEditor = notifyEditor($('#notify-own-editor'), cfg,
        { pushover_enabled: g.pushover_enabled === '1', email_enabled: g.email_enabled === '1', ha_webhook_enabled: g.ha_webhook_enabled === '1' });
    }
  };
  $('#notify-own').onchange = showOwn;
  if (c.notify) showOwn();
  // Stille uren: algemeen, eigen vensters (start met de algemene) of nooit stil
  let quietOwnEditor = null;
  const showQuiet = async () => {
    const mode = $('#quiet-mode').value;
    $('#quiet-mode-label').textContent = QUIET_MODES[mode];
    $('#quiet-own-editor').hidden = mode !== 'own';
    if (mode === 'own' && !quietOwnEditor) {
      let windows = quietOwn && quietOwn.windows;
      if (!windows || !windows.length) {
        try { windows = JSON.parse((await api('settings')).notify_quiet || '{}').windows; } catch (e) { windows = null; }
      }
      quietOwnEditor = quietEditor($('#quiet-own-editor'), { windows: windows || [] }, false);
    }
  };
  $('#quiet-mode').onchange = showQuiet;
  showQuiet();
  const typeSel = $('[name=type]', form);
  const haSel = $('[name=metric]', form);
  const update = (setDefaults) => {
    const isContainer = typeSel.value === 'container';
    const isHa = typeSel.value === 'ha';
    const t = isHa ? { ...TYPES.ha, ...HA_METRICS[haSel.value] } : TYPES[typeSel.value];
    $('#ha-wrap').hidden = !isHa;
    haSel.disabled = !isHa;   // niet meesturen bij andere types
    $('#ha-help').textContent = isHa ? HA_METRICS[haSel.value].help : '';
    $('#type-help').textContent = TYPES[typeSel.value].help;
    $('#res-wrap').hidden = !isContainer;
    $$('#res-wrap input[type=number]', form).forEach((i) => { i.disabled = !$(`[name=${i.name.replace(/_(warn|crit)$/, '')}]`, form).checked; });
    $('#target-wrap').hidden = !t.target;
    $('#target-label').textContent = t.target || '';
    $('[name=target]', form).placeholder = t.ph || '';
    $$('.unit', form).forEach((u) => (u.textContent = t.unit || ''));
    $$('#thr-wrap label', form).slice(0, 2).forEach((l) => (l.hidden = !t.unit));
    const words = $$('.thr-word', form);
    words[0].textContent = t.low ? 'Warning onder' : 'Warning vanaf';
    words[1].textContent = t.low ? 'Critical onder' : 'Critical vanaf';
    $('#verify-wrap').hidden = !['http', 'ssl'].includes(typeSel.value);
    // Diepere controle en extra test: alleen services op agent-servers
    const deep = typeSel.value === 'service' && h.mode === 'agent';
    $('#deep-wrap').hidden = !deep;
    const tt = $('[name=test_type]', form).value;
    $('#test-target-wrap').hidden = !tt;
    $('#test-expect-wrap').hidden = tt !== 'url';
    $('[name=test_target]', form).placeholder = tt === 'port' ? '8080 of localhost:8080' : 'http://localhost:8080/health';
    $('[name=test_target]', form).required = deep && !!tt;
    $('[name=target]', form).required = !!t.target && !['http', 'ssl'].includes(typeSel.value);
    const list = { disk: disks, service: svcs, ssl: certs, container: containers, log: LOG_SOURCES }[typeSel.value] || [];
    $('#log-wrap').hidden = typeSel.value !== 'log';
    $('[name=pattern]', form).required = typeSel.value === 'log';
    $('#target-list').innerHTML = list.map((v) => `<option value="${esc(v)}">`).join('');
    $('#svc-pick-wrap').hidden = !(typeSel.value === 'service' && h.mode === 'agent' && svcList);
    if (typeSel.value === 'service' && h.mode === 'agent') renderSvcPick();
    if (setDefaults) {
      $('[name=warn_threshold]', form).value = t.warn ?? '';
      $('[name=crit_threshold]', form).value = t.crit ?? '';
      if (setDefaults !== 'metric') $('[name=target]', form).value = typeSel.value === 'disk' ? '/' : '';
    }
  };
  // Servicekiezer (agent-servers): alle services op de server, met status; filtert op wat je typt
  let svcList = null;
  const watched = new Set((h.checks || []).filter((x) => x.type === 'service' && (!c.id || +x.id !== +c.id)).map((x) => x.target));
  const renderSvcPick = () => {
    if (!svcList) return;
    const q = $('[name=target]', form).value.trim().toLowerCase();
    const rows = svcList.filter(([n]) => !q || n.toLowerCase().includes(q));
    const label = { active: 'actief', failed: 'mislukt', inactive: 'uit', activating: 'start op', deactivating: 'stopt', stopped: 'gestopt', error: 'fout', update: 'update beschikbaar' };
    $('#svc-pick').innerHTML = rows.length ? rows.map(([n, st, en]) => `
      <button type="button" class="svc-item${$('[name=target]', form).value === n ? ' sel' : ''}" data-svc="${esc(n)}" ${watched.has(n) ? 'disabled' : ''}>
        <span class="svc-dot ${st === 'active' ? 'ok' : st === 'failed' || st === 'error' ? 'crit' : st === 'update' ? 'warn' : 'off'}"></span>
        <span class="mono">${esc(n)}</span>
        <span class="hint">${esc(label[st] || st)}${en ? '' : ' · start niet automatisch'}${watched.has(n) ? ' · wordt al bewaakt' : ''}</span>
      </button>`).join('') : `<p class="hint">Geen service gevonden${q ? ` met "${esc(q)}"` : ''}. Je kunt de naam ook zelf typen.</p>`;
    $('#svc-pick-info').textContent = `${svcList.length} services op ${h.name} (die aan staan of draaien). Klik om te kiezen; de agent bewaakt hem dan vanzelf. Staat hij er niet bij, typ dan de naam.`;
  };
  if (h.mode === 'agent') {
    api('agent_services', null, { id: h.id }).then((r) => {
      if (!r.list) { $('#svc-pick-info').textContent = 'Deze agent stuurt nog geen lijst met services (bijwerken naar de nieuwste versie). Typ de naam zelf.'; }
      svcList = r.list || [];
      update(false);
      $('#svc-pick-wrap').hidden = !(typeSel.value === 'service');
      if (!r.list) $('#svc-pick').innerHTML = '';
    }).catch(() => {});
    $('#svc-pick').onclick = (e) => {
      const b = e.target.closest('[data-svc]');
      if (!b) return;
      const name = $('[name=name]', form);
      if (!name.value || name.value === $('[name=target]', form).value) name.value = b.dataset.svc;
      $('[name=target]', form).value = b.dataset.svc;
      renderSvcPick();
    };
    $('[name=target]', form).addEventListener('input', () => { if (typeSel.value === 'service') renderSvcPick(); });
  }
  typeSel.onchange = () => update(true);
  haSel.onchange = () => update('metric');
  $$('#res-wrap input[type=checkbox]', form).forEach((cb) => (cb.onchange = () => update(false)));
  $('[name=test_type]', form).onchange = () => update(false);
  // Voorinstelling kiezen: bron, patroon, drempels en naam invullen
  $('[name=preset]', form).onchange = (e) => {
    const p = LOG_PRESETS[e.target.value];
    if (!p || e.target.value === 'custom') return;
    const f = (n) => $(`[name=${n}]`, form);
    f('target').value = p.src;
    f('pattern').value = p.pat;
    f('warn_threshold').value = p.warn;
    f('crit_threshold').value = p.crit;
    // Naam invullen, tenzij je zelf al iets anders hebt getypt
    const plain = (x) => x.label.replace(/^\S+\s/, '');
    if (!f('name').value || Object.values(LOG_PRESETS).some((x) => f('name').value === plain(x))) f('name').value = plain(p);
  };
  update(false);
}

// ---------------------------------------------------------------- History
async function showHistory(c, h) {
  const t = typeOf(c);
  modal({
    title: `${h.name} – ${c.name}`,
    wide: true,
    body: `<div class="chart">
      <div class="chart-head">
        <span id="uptime" class="muted"></span>
        <select id="range"><option value="6">6 uur</option><option value="24" selected>24 uur</option><option value="168">7 dagen</option><option value="720">30 dagen</option></select>
      </div>
      <div id="chart-svg"><p class="muted">Laden…</p></div></div>
      <div id="log-lines"></div>`,
  });
  const load = async () => {
    const hours = $('#range').value;
    const d = await api('history', null, { check_id: c.id, hours });
    const vals = d.points.map((p) => p.v).filter((v) => v !== null);
    const unit = t.unit === 'dagen' ? ' d' : t.unit ? ' ' + t.unit : '';
    const mma = t.unit && vals.length
      ? ` · min <b>${fmt(Math.min(...vals))}</b> · gem <b>${fmt(vals.reduce((a, b) => a + b, 0) / vals.length)}</b> · max <b>${fmt(Math.max(...vals))}</b>${unit}` : '';
    const fc = d.forecast
      ? (d.forecast.days_to_full !== null
        ? ` · <span class="${d.forecast.days_to_full <= 14 ? 'bad' : ''}">trend +${String(Math.round(d.forecast.per_day * 100) / 100).replace('.', ',')}%/dag: vol over ~${Math.round(d.forecast.days_to_full)} dagen</span>`
        : ' · trend: stabiel')
      : '';
    $('#uptime').innerHTML = d.uptime === null ? 'Geen data'
      : c.type === 'log' ? `Treffers per controle${mma}`   // "beschikbaarheid" zegt bij een logbestand niets
      : `Beschikbaarheid: <b>${pct(d.uptime)}</b>${mma}${fc}`;
    // Logbestand: de laatst gevonden regels
    const ll = $('#log-lines');
    if (ll) ll.innerHTML = d.log_lines && d.log_lines.length
      ? `<h4>Laatst gevonden regels</h4><pre class="code log-lines">${d.log_lines.map((l) => `<span class="muted">${esc(fmtUntil(l.time))}</span>  ${esc(l.line)}`).join('\n')}</pre>`
      : (d.log_lines ? '<p class="muted small">Nog geen regels gevonden.</p>' : '');
    // Container: een grafiek per bewaakte meting (CPU, RAM, disk), elk met eigen schaal en drempels
    if (d.series && d.series.length) {
      $('#chart-svg').innerHTML = d.series.map((sr) => {
        const pts = d.points.map((p) => ({ t: p.t, s: p.s, v: p.x && p.x[sr.key] !== undefined ? p.x[sr.key] : null }));
        const sv = pts.map((p) => p.v).filter((v) => v !== null);
        const stats = sv.length ? ` <span class="muted small">min ${fmt(Math.min(...sv))} · gem ${fmt(sv.reduce((a, b) => a + b, 0) / sv.length)} · max ${fmt(Math.max(...sv))} ${sr.unit}</span>` : ' <span class="muted small">nog geen metingen</span>';
        return `<h4 class="chart-sub">${esc(sr.label)}${stats}</h4>`
          + chartSvg(pts, { warn_threshold: sr.warn, crit_threshold: sr.crit }, { unit: sr.unit }, +hours, 170);
      }).join('');
      return;
    }
    $('#chart-svg').innerHTML = chartSvg(d.points, c, t, +hours);
  };
  $('#range').onchange = load;
  load();
}

function chartSvg(pts, c, t, hours, H = 260) {
  if (!pts.length) return '<p class="muted">Nog geen meetgegevens.</p>';
  const W = 860, L = 48, R = 12, T = 12, B = 40;
  const t1 = Math.floor(Date.now() / 1000), t0 = t1 - hours * 3600;
  const x = (ts) => L + ((ts - t0) / (t1 - t0)) * (W - L - R);
  const vals = pts.map((p) => p.v).filter((v) => v !== null);
  const hasVals = t.unit && vals.length;
  let max = hasVals ? Math.max(...vals, +c.crit_threshold || 0, +c.warn_threshold || 0) * 1.1 : 1;
  if (t.unit === '%') max = Math.max(100, ...vals);   // CPU van een container kan boven 100% (meer cores)
  const y = (v) => T + (1 - v / max) * (H - T - B - 14);
  let svg = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" style="height:${H}px">`;

  if (hasVals) {
    svg += '<g class="grid">';
    for (let i = 0; i <= 4; i++) {
      const v = (max / 4) * i;
      svg += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}"/><text x="${L - 6}" y="${y(v) + 4}" text-anchor="end">${Math.round(v)}</text>`;
    }
    svg += '</g>';
    if (c.warn_threshold !== null) svg += `<line class="thr-w" x1="${L}" x2="${W - R}" y1="${y(+c.warn_threshold)}" y2="${y(+c.warn_threshold)}"/>`;
    if (c.crit_threshold !== null) svg += `<line class="thr-c" x1="${L}" x2="${W - R}" y1="${y(+c.crit_threshold)}" y2="${y(+c.crit_threshold)}"/>`;
    const segs = [];
    let cur = [];
    pts.forEach((p) => { if (p.v === null) { if (cur.length) segs.push(cur); cur = []; } else cur.push(p); });
    if (cur.length) segs.push(cur);
    segs.forEach((s) => {
      const line = s.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(p.v).toFixed(1)}`).join('');
      svg += `<path class="area" d="${line}L${x(s[s.length - 1].t)},${y(0)}L${x(s[0].t)},${y(0)}Z"/><path class="line" d="${line}"/>`;
    });
  }
  // Statusstrook onderaan
  const sy = H - B + 4;
  svg += '<g class="strip">';
  pts.forEach((p, i) => {
    const x1 = x(p.t), x2 = i < pts.length - 1 ? x(pts[i + 1].t) : x(t1);
    svg += `<rect class="s${p.s}" x="${x1}" y="${sy}" width="${Math.max(1, x2 - x1)}" height="10"><title>${STATUS[p.s]} – ${new Date(p.t * 1000).toLocaleString('nl-NL')}${p.v !== null ? ' – ' + fmt(p.v, t.unit) : ''}</title></rect>`;
  });
  svg += '</g>';
  for (let i = 0; i <= 4; i++) {
    const ts = t0 + ((t1 - t0) / 4) * i;
    const d = new Date(ts * 1000);
    const lbl = hours > 24 ? d.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' }) : d.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
    svg += `<text x="${x(ts)}" y="${H - 8}" text-anchor="${i === 0 ? 'start' : i === 4 ? 'end' : 'middle'}">${lbl}</text>`;
  }
  return svg + '</svg>';
}

// ---------------------------------------------------------------- Port scan
function portScan(h) {
  modal({
    title: `Poortscan – ${h.name}`,
    wide: true,
    body: `
      <p class="muted small" style="margin-top:0">Scant vanaf de RzMon-server welke TCP-poorten op <code>${esc(h.address)}</code> open staan.
        Open poorten kun je direct als TCP-check toevoegen, zodat je een melding krijgt als de dienst wegvalt.</p>
      <div class="scan-form">
        <label>Bereik
          <select id="scan-preset">
            <option value="common">Bekende poorten (SSH, web, databases, HA, NAS…)</option>
            <option value="top1024">Poort 1-1024</option>
            <option value="custom">Eigen lijst / range</option>
          </select></label>
        <label id="scan-custom-wrap" hidden>Poorten <span class="hint">bv. 22,80,443,8000-8100</span><input id="scan-ports" class="mono" placeholder="22,80,443,8000-8100"></label>
        <button type="button" class="btn primary" id="scan-go">🔍 Scannen</button>
      </div>
      <div id="scan-result"></div>`,
  });
  $('#scan-preset').onchange = (e) => ($('#scan-custom-wrap').hidden = e.target.value !== 'custom');
  $('#scan-go').onclick = () => runScan(h);
  $('#scan-result').addEventListener('click', (e) => addPortCheck(e, h));
  runScan(h);
}

async function runScan(h) {
  const btn = $('#scan-go');
  const out = $('#scan-result');
  btn.disabled = true;
  out.innerHTML = '<p class="scan-summary">⏳ Bezig met scannen…</p>';
  try {
    const r = await api('port_scan', { host_id: h.id, preset: $('#scan-preset').value, ports: $('#scan-ports').value });
    const certMon = new Set(h.checks.filter((c) => c.type === 'ssl').map((c) => +((c.target || '').match(/:(\d+)\/?$/) || [0, 443])[1]));
    const row = (p, extra = '') => {
      const mon = r.monitored.includes(+p.port);
      const tls = TLS_PORTS.includes(+p.port);
      return `<tr><td class="num">${p.port}</td><td>${esc(p.service || '–')}</td>${extra}
        <td style="text-align:right;white-space:nowrap">${mon ? '<span class="muted small">✓ bewaakt</span>'
          : `<button type="button" class="btn need-admin" data-port="${p.port}" data-service="${esc(p.service || '')}">＋ Check</button>`}
          ${tls ? (certMon.has(+p.port) ? ' <span class="muted small">✓ cert</span>'
            : ` <button type="button" class="btn need-admin" data-cert="${p.port}" title="Certificaat bewaken (vervaldatum)">＋ Cert</button>`) : ''}</td></tr>`;
    };
    let html = `<p class="scan-summary">${r.open.length} open van ${r.scanned} gescande poorten · ${r.seconds}s</p>`;
    html += r.open.length
      ? `<table class="port-table"><thead><tr><th>Poort</th><th>Dienst</th><th>Reactie</th><th></th></tr></thead><tbody>
          ${r.open.map((p) => row(p, `<td class="muted">${p.ms} ms</td>`)).join('')}</tbody></table>`
      : '<p class="muted">Geen open poorten gevonden (of een firewall blokkeert de scan).</p>';
    if (r.listening) {
      html += `<h4 style="margin:1.4rem 0 .2rem">Luisterende poorten volgens de agent</h4>
        <p class="muted small" style="margin:0">Ook poorten die alleen lokaal luisteren of door een firewall worden afgeschermd.</p>`;
      html += r.listening.length
        ? `<table class="port-table"><thead><tr><th>Poort</th><th>Dienst</th><th>Adres</th><th>Proces</th><th></th></tr></thead><tbody>
            ${r.listening.map((p) => row(p, `<td class="mono small">${esc(p.addr)}</td><td>${esc(p.proc || '–')}</td>`)).join('')}</tbody></table>`
        : '<p class="muted small">Nog geen gegevens — werk de agent bij naar de nieuwste versie (vereist <code>ss</code>).</p>';
    }
    out.innerHTML = html;
  } catch (ex) {
    out.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`;
  }
  btn.disabled = false;
}

// Poorten waarop meestal TLS draait: daar kan direct een certificaatcheck bij
const TLS_PORTS = [443, 465, 636, 853, 990, 993, 995, 1443, 2083, 2087, 3269, 4443, 5001, 5986, 6443, 7443, 8006, 8443, 8883, 8920, 9443, 10443];

/** Adres in het eigen netwerk? Daar zijn self-signed certificaten gewoon, dus niet streng controleren. */
function isLocalAddress(a) {
  return /^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|\[?f[cd]|\[?::1)/i.test(a)
    || !a.includes('.') || /\.(lan|local|home|box|internal|localdomain|home\.arpa)$/i.test(a);
}

async function addCertCheck(b, h) {
  b.disabled = true;
  const port = +b.dataset.cert;
  try {
    await api('check_save', { host_id: h.id, type: 'ssl', target: port === 443 ? '' : `${h.address}:${port}`,
      name: port === 443 ? 'Certificaat' : `Certificaat ${port}`, warn_threshold: TYPES.ssl.warn, crit_threshold: TYPES.ssl.crit,
      interval_sec: 3600, enabled: true, verify_ssl: !isLocalAddress(h.address) });
    b.outerHTML = '<span class="muted small">✓ cert</span>';
    toast(`Certificaatcheck op poort ${port} toegevoegd${isLocalAddress(h.address) ? ' (lokaal adres: certificaatcontrole uit, i.v.m. self-signed)' : ''}`);
    refresh();
  } catch (ex) {
    toast(ex.message, 'err');
    b.disabled = false;
  }
}

async function addPortCheck(e, h) {
  const cb = e.target.closest('[data-cert]');
  if (cb) return addCertCheck(cb, h);
  const b = e.target.closest('[data-port]');
  if (!b) return;
  b.disabled = true;
  const name = b.dataset.service ? `${b.dataset.service} (${b.dataset.port})` : `Poort ${b.dataset.port}`;
  try {
    await api('check_save', { host_id: h.id, type: 'tcp', target: b.dataset.port, name,
      warn_threshold: TYPES.tcp.warn, crit_threshold: TYPES.tcp.crit, interval_sec: 60, enabled: true });
    $$(`[data-port="${b.dataset.port}"]`, $('#scan-result')).forEach((x) => x.outerHTML = '<span class="muted small">✓ bewaakt</span>');
    toast(`Check "${name}" toegevoegd`);
    refresh();
  } catch (ex) {
    toast(ex.message, 'err');
    b.disabled = false;
  }
}

// ---------------------------------------------------------------- Podman
const fmtBytes = (b) => {
  if (b === null || b === undefined) return '–';
  const u = ['B', 'kB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (b >= 1000 && i < u.length - 1) { b /= 1000; i++; }
  return `${b >= 100 || i === 0 ? Math.round(b) : b.toFixed(1)} ${u[i]}`.replace('.', ',');
};

async function podmanOverview(h) {
  modal({ title: `📦 Containers – ${h.name}`, wide: true, body: '<div id="podman-body"><p class="muted">Laden…</p></div>' });
  const body = $('#podman-body');
  let d;
  try { d = await api('podman', null, { id: h.id }); } catch (ex) { body.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sm = d.summary;
  const checkOf = (key) => d.checks.find((c) => c.type === 'container' && c.target === key);
  const resLabel = (c) => ['status', ...Object.keys((JSON.parse(c.options || '{}') || {}).res || {}).map((k) => ({ cpu: 'CPU', mem: 'RAM', disk: 'disk' }[k]))].join(', ');
  const stateCls = (c) => (c.state === 'running' ? (c.health === 'unhealthy' ? 's2' : 's0') : c.state === 'paused' ? 's1' : +c.exit_code ? 's2' : 's3');
  const stateText = (c) => (c.state === 'running' ? 'draait' + (c.health ? ` (${c.health === 'healthy' ? 'gezond' : c.health === 'unhealthy' ? 'ONGEZOND' : 'start op'})` : '')
    : c.state === 'paused' ? 'gepauzeerd' : c.state === 'exited' ? `gestopt (exit ${c.exit_code})` : c.state);
  const rows = d.containers.map((c) => {
    const memPct = c.mem !== null && c.mem_limit ? (c.mem / c.mem_limit) * 100 : null;
    const disk = (c.size_rw || 0) + (c.volumes || 0);
    const chk = checkOf(c.key);
    const watch = chk
      ? `<button type="button" class="btn small-btn need-admin pc-watched" data-pc-edit="${chk.id}" title="Aanpassen wat er bewaakt wordt">✓ ${esc(resLabel(chk))} ✎</button>`
      : `<button type="button" class="btn small-btn need-admin" data-pc="${esc(c.key)}" title="Status en (naar keuze) CPU, RAM en disk bewaken">＋ Bewaken</button>`;
    return `<tr>
      <td><span class="dot-inline ${stateCls(c)}"></span><b>${esc(c.key)}</b>${c.pod ? `<br><span class="muted small">pod ${esc(c.pod)}</span>` : ''}
        <br><span class="muted small mono">${esc(c.image)}</span></td>
      <td>${esc(stateText(c))}${+c.restarts ? `<br><span class="muted small">${c.restarts}× herstart</span>` : ''}</td>
      <td class="num">${c.cpu !== null ? (Math.round(c.cpu * 10) / 10).toString().replace('.', ',') + '%' : '–'}</td>
      <td class="num">${c.mem !== null ? fmtBytes(c.mem) : '–'}${memPct !== null ? `<br><span class="muted small">${memPct.toFixed(1).replace('.', ',')}% van ${fmtBytes(c.mem_limit)}</span>` : ''}</td>
      <td class="num">${fmtBytes(disk)}${c.volumes ? `<br><span class="muted small">volumes ${fmtBytes(c.volumes)}</span>` : ''}</td>
      <td class="pc-actions">${watch}</td>
    </tr>`;
  }).join('');
  const missing = d.containers.filter((c) => !checkOf(c.key));
  body.innerHTML = `
    <p class="scan-summary">Podman ${esc(sm.version || '?')} · ${d.containers.filter((c) => c.state === 'running').length} van ${d.containers.length} containers draaien
      ${(sm.pods || []).length ? ' · pods: ' + sm.pods.map((p) => `${esc(p.name)} (${esc(p.status)})`).join(', ') : ''}
      <br><span class="muted small">Images ${fmtBytes(sm.images_size)} · containers ${fmtBytes(sm.containers_size)} · volumes ${fmtBytes(sm.volumes_size)}
      ${sm.reclaimable ? ` · ${fmtBytes(sm.reclaimable)} op te ruimen (<code>podman system prune</code>)` : ''} · gemeld ${ago(d.updated_at)}
      ${(sm.users || []).length > 1 ? ' · gebruikers: ' + sm.users.map(esc).join(', ') : ''}</span></p>
    ${sm.error ? `<p class="form-error">Podman-fout: ${esc(sm.error)}</p>` : ''}
    <div class="podman-actions">
      ${d.checks.some((c) => c.type === 'podman') ? '<span class="muted small">✓ Podman-check aanwezig</span>'
        : '<button type="button" class="btn need-admin" id="pc-overall">＋ Podman-check (algehele status)</button>'}
      ${missing.length ? `<button type="button" class="btn need-admin" id="pc-all" title="Alleen de status; CPU, RAM en disk kun je daarna per container aanvinken">＋ ${missing.length === d.containers.length ? 'Alle' : 'De overige'} ${missing.length} container(s) bewaken</button>` : ''}
    </div>
    <div class="table-scroll"><table class="port-table podman-table">
      <thead><tr><th>Container</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>Bewaken</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="6" class="muted">Geen containers.</td></tr>'}</tbody></table></div>
    <p class="muted small">CPU zoals <code>podman stats</code>: 100% = één core. Disk = schrijflaag van de container + zijn volumes.
      Containers van gewone gebruikers (rootless) staan als <code>gebruiker/naam</code>.</p>`;

  const add = async (payload) => api('check_save', { host_id: h.id, interval_sec: 60, enabled: true, ...payload });
  const addContainer = (key) => add({ type: 'container', target: key, name: '', res: {} });
  body.onclick = async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    b.disabled = true;
    // Eén container bewaken of aanpassen: het checkformulier met status + vinkjes voor CPU, RAM en disk
    if (b.dataset.pc) return checkForm(null, findHost(h.id), { type: 'container', target: b.dataset.pc, name: '', options: '{"res":{}}' });
    if (b.dataset.pcEdit) { const [c] = findCheck(b.dataset.pcEdit); if (c) return checkForm(c, findHost(h.id)); }
    try {
      if (b.id === 'pc-overall') await add({ type: 'podman', name: 'Podman' });
      else if (b.id === 'pc-all') for (const c of missing) await addContainer(c.key);
      toast('Check(s) toegevoegd');
      await refresh();
      podmanOverview(findHost(h.id));
    } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
  };
}

// ---------------------------------------------------------------- Agent
function agentInstructions(h) {
  const base = state.base_url || location.origin + location.pathname.replace(/\/[^/]*$/, '');
  const scriptUrl = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api.php?action=agent_script';
  const installCmd = `curl -fsSL '${base}/api.php?action=agent_install' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}'`;
  modal({
    title: `Agent Manager – ${h.name}`,
    wide: true,
    body: `
      <div class="agent-live" id="agent-live"><span class="muted">Status ophalen…</span></div>
      <div class="seg" role="tablist">
        <button type="button" class="seg-btn active" data-seg="linux">🐧 Linux-server</button>
        <button type="button" class="seg-btn" data-seg="ha">🏠 Home Assistant OS</button>
      </div>
      <div data-pane="ha" hidden>
        <p>Op Home Assistant OS / Supervised draait de agent als <b>add-on</b>. Hij meldt CPU, RAM, de data-partitie, of Home Assistant Core en elke add-on draait, en welke updates er klaarstaan.</p>
        <ol class="steps">
          <li>In Home Assistant: <i>Instellingen → Add-ons → Add-on winkel</i> → ⋮ → <b>Repositories</b> → voeg <code>https://github.com/rembrand20/rzmon</code> toe.
            <span class="muted small">(Of kopieer de map <code>ha-addon/rzmon_agent</code> naar de share <code>addons</code> met de <i>Samba share</i>-add-on.)</span></li>
          <li>⋮ → <b>Controleren op updates</b> en installeer <b>RzMon Agent</b>.</li>
          <li>Tabblad <b>Configuratie</b>:</li>
        </ol>
        <pre class="code">monitor_url: ${esc(base)}
token: ${esc(h.agent_token || '')}
interval: 60
verify_ssl: true</pre>
        <ol class="steps" start="4">
          <li><b>Starten</b>. In het logboek moet <code>Verbonden met RzMon ✔</code> staan.</li>
          <li>Voeg hier checks toe met <b>＋</b>: <i>Service</i> <code>homeassistant</code>, <code>supervisor</code>, <code>updates</code> of <code>addon:&lt;slug&gt;</code>, en <i>Diskruimte</i> <code>/data</code>.</li>
        </ol>
        <p class="muted small">Tip: zet er ook een ping-check en een TCP-check op poort 8123 bij. Die werken los van de agent.</p>
      </div>
      <div data-pane="linux">
      <p>Voer dit één commando uit op <b>${esc(h.name)}</b> (Rocky/RHEL/Alma, Debian/Ubuntu, Raspberry Pi OS, Proxmox).
        Het installeert de agent, stelt de services voor die er draaien, zet een systemd-timer aan en stuurt een testrapport.</p>
      <div class="copy-line"><pre class="code" id="agent-oneliner">${esc(installCmd)}</pre>
        <button type="button" class="btn" id="copy-oneliner">Kopieer</button></div>
      ${base.startsWith('https') ? '<p class="muted small">Self-signed certificaat op RzMon? Voeg <code>-k</code> toe aan curl en <code>--insecure</code> aan het eind.</p>' : ''}
      <p class="muted small">Daarna: klik hier op <b>＋</b> en voeg checks toe (CPU, RAM, diskruimte, services). Bijwerken gaat daarna met de knop hierboven (of <code>sudo rzmon-agent-setup --update</code>) · verwijderen: <code>sudo rzmon-agent-setup --uninstall</code>.</p>
      <p class="muted small">🩺 <b>Komt er niets binnen?</b> Voer op de server <code>sudo rzmon-agent-setup --test</code> uit. Die controleert stap voor stap planning, netwerk, adres, token en een proefrapport, en zegt wat er mis is.</p>
      <p class="muted small">⚠️ De server moet RzMon kunnen bereiken op <code>${esc(base)}</code>. Klopt dat adres niet, pas dan de <i>Basis-URL</i> aan in Setup.</p>
      <details class="small api-docs">
        <summary>Liever handmatig installeren?</summary>
      <p class="muted small">1. Download <a href="${esc(scriptUrl)}" target="_blank">rzmon-agent.sh</a> en kopieer het naar de server.</p>
      <p class="muted small">2. Installeer en configureer:</p>
      <pre class="code">sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
sudo tee /etc/default/rzmon-agent &gt;/dev/null &lt;&lt;'EOF'
MONITOR_URL="${esc(base)}"
TOKEN="${esc(h.agent_token || '')}"
CERTS="/etc/letsencrypt/live/*/cert.pem localhost:8443"
EOF
sudo chmod 600 /etc/default/rzmon-agent
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh &gt;/dev/null 2&gt;&amp;1' | sudo tee /etc/cron.d/rzmon-agent
# Testen:
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'</pre>
      <p class="muted small"><code>CERTS</code>: certificaatbestanden en/of <code>host:poort</code> (ook op internet) waarvan de agent de vervaldatum meldt. Services kies je na de eerste rapportage hier bij "Check toevoegen" uit een lijst van de server; de agent bewaakt ze dan vanzelf.</p>
      </details>
      </div>`,
    buttons: [
      { label: '↻ Nieuw token', onClick: async () => {
        if (!confirm('Nieuw token genereren? De oude agent werkt dan niet meer.')) return;
        await api('host_token', { id: h.id }); await refresh(); agentInstructions(findHost(h.id));
      } },
      { label: 'Sluiten', cls: 'primary', submit: true },
    ],
  });
  const body = $('#modal-body');
  const show = (seg) => {
    $$('.seg-btn', body).forEach((b) => b.classList.toggle('active', b.dataset.seg === seg));
    $$('[data-pane]', body).forEach((p) => (p.hidden = p.dataset.pane !== seg));
  };
  $$('.seg-btn', body).forEach((b) => (b.onclick = () => show(b.dataset.seg)));
  pollAgentStatus(h.id);
  $('#agent-live').onclick = async (e) => {
    const b = e.target.closest('[data-agent-upd]');
    if (!b) return;
    b.disabled = true;
    try {
      await api(b.dataset.agentUpd === 'go' ? 'agent_update' : 'agent_update_cancel', { id: h.id });
      toast(b.dataset.agentUpd === 'go' ? 'Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport' : 'Geannuleerd');
    } catch (ex) { toast(ex.message, 'err'); }
    pollAgentStatus(h.id);
  };
  $('#copy-oneliner').onclick = async () => {
    try { await navigator.clipboard.writeText(installCmd); toast('Commando gekopieerd'); }
    catch (ex) { getSelection().selectAllChildren($('#agent-oneliner')); toast('Geselecteerd — druk Ctrl+C', 'err'); }
  };
  // Raad Home Assistant bij een naam of agent-gegevens die erop wijzen
  if (/home.?assistant|hass|haos/i.test(h.name) || h.metrics.includes('service:homeassistant')) show('ha');
}

/** Versies vergelijken ("1.10.0" > "1.9.2"); negatief als a ouder is dan b. */
function verCmp(a, b) {
  const pa = String(a).split('.').map(Number), pb = String(b).split('.').map(Number);
  for (let i = 0; i < 3; i++) if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) - (pb[i] || 0);
  return 0;
}
/** Agent-versie als tekst, met ⬆ als de agent ouder is dan RzMon zelf. */
function agentVer(h) {
  const busy = h.agent_update_req ? ' <span title="Wordt bijgewerkt">⏳</span>' : '';
  if (!h.agent_version) return h.agent_seen ? ` · versie onbekend ⬆${busy}` : '';
  return ` · v${esc(h.agent_version)}${state.version && verCmp(h.agent_version, state.version) < 0 ? ' ⬆' : ''}${busy}`;
}

/** Live-status van de agent in het venster, elke 5 s zolang het open is. */
let agentPollGen = 0;
async function pollAgentStatus(hostId, gen = ++agentPollGen) {
  const box = $('#agent-live');
  // Stoppen als het venster dicht is of opnieuw geopend (dan loopt er al een nieuwe lus)
  if (!box || !$('#modal').open || gen !== agentPollGen) return;
  try {
    const s = await api('agent_status', null, { id: hostId });
    const age = s.seen ? Math.round((new Date(s.now.replace(' ', 'T')) - new Date(s.seen.replace(' ', 'T'))) / 1000) : null;
    const fresh = age !== null && age < 180;
    let html = s.mode !== 'agent'
      ? `<b class="bad">✘ Deze server staat op type "${esc(s.mode)}".</b> Een agent werkt alleen bij type <b>Remote met agent</b> (✎ Bewerken).`
      : s.seen
        ? `<b class="${fresh ? 'good' : 'bad'}">${fresh ? '✔' : '⚠'} Laatste rapport ${ago(s.seen)}</b>${s.ip ? ` vanaf <code>${esc(s.ip)}</code>` : ''}${fresh ? '' : ' — de agent rapporteert niet meer'}`
        : '<b class="wait">⏳ Nog geen rapport ontvangen</b> <span class="muted small">(wordt elke 5 s bijgewerkt)</span>';
    if (s.mode === 'agent' && s.seen) {
      const old = !s.agent_version || verCmp(s.agent_version, s.version) < 0;
      const u = s.update || {};
      const canRemote = u.via === 'systemd' || u.via === 'direct';
      html += `<div class="small">Agent-versie <b>${s.agent_version ? esc(s.agent_version) : 'onbekend (ouder dan 1.6.0)'}</b>, RzMon ${esc(s.version)}${old ? '' : ' ✔'}</div>`;
      if (u.status) html += `<div class="small agent-upd-status">${esc(u.status)}</div>`;
      if (isAdmin() && u.pending) {
        html += '<div class="btn-row agent-upd"><button type="button" class="btn" data-agent-upd="cancel">Bijwerken annuleren</button></div>';
      } else if (isAdmin() && (old || canRemote)) {
        html += `<div class="btn-row agent-upd"><button type="button" class="btn ${old ? 'primary' : ''}" data-agent-upd="go">⬆ ${old ? `Bijwerken naar ${esc(s.version)}` : 'Opnieuw installeren'}</button>
          <span class="muted small">${canRemote ? 'De agent werkt zichzelf bij binnen een minuut.'
            : u.via === 'ha' ? 'Home Assistant-add-on: bijwerken via de add-on winkel.'
            : u.via === 'off' ? 'Op afstand bijwerken staat uit op deze server (REMOTE_UPDATE=0).'
            : 'Deze agent kan dat nog niet: werk hem één keer bij op de server met <code>sudo rzmon-agent-setup --update</code>.'}</span></div>`;
      }
    }
    if (s.failures.length) {
      html += `<div class="fails"><span class="muted small">Geweigerde pogingen (24 uur):</span>
        ${s.failures.map((f) => `<div class="small"><code>${esc(f.ip)}</code> · ${ago(f.created_at)}${+f.n > 1 ? ` (${f.n}×)` : ''} · ${esc(f.reason)}</div>`).join('')}</div>`;
    }
    if (gen === agentPollGen) box.innerHTML = html;
  } catch (ex) {
    if (gen === agentPollGen) box.innerHTML = `<span class="muted small">${esc(ex.message)}</span>`;
  }
  setTimeout(() => pollAgentStatus(hostId, gen), 5000);
}

// ---------------------------------------------------------------- Alerts
function channelIcons(a) {
  if (a.kind === 'ack') return '<span title="Bevestiging">✔</span>';
  const out = [];
  if (+a.sent_ok) out.push('<span title="Verstuurd via Pushover">📲</span>');
  if (a.email_ok !== null && a.email_ok !== undefined) out.push(+a.email_ok ? '<span title="Verstuurd per e-mail">📧</span>' : '<span title="E-mail mislukt">📧✕</span>');
  if (a.webhook_ok !== null) out.push(+a.webhook_ok ? '<span title="Verstuurd naar Home Assistant">🏠</span>' : '<span title="Webhook mislukt">🏠✕</span>');
  if (!out.length) out.push('<span title="Niet verzonden">✉️✕</span>');
  return out.join(' ');
}

function renderAlerts() {
  const el = $('#alerts');
  if (!state.alerts.length) { el.innerHTML = '<p class="muted">Nog geen meldingen. 👌</p>'; return; }
  el.innerHTML = state.alerts.map((a) => `
    <div class="alert-item ${a.kind === 'ack' ? 'ack' : 's' + a.status}">
      <span class="dot"></span>
      <strong>${esc(a.title)}</strong>
      <span class="muted small" title="${esc(a.created_at)}">${ago(a.created_at)} ${channelIcons(a)}</span>
      <span class="msg">${esc(a.message)}</span>
    </div>`).join('');
}

// ---------------------------------------------------------------- Settings
// ---------------------------------------------------------------- Access
async function loadAccess() {
  const a = await api('access_info');
  const f = $('#access-form');
  f.lan_no_login.checked = a.lan_no_login;
  f.lan_as_admin.checked = a.lan_as_admin;
  f.trusted_networks.value = a.trusted_networks;
  f.trusted_proxies.value = a.trusted_proxies;
  $('#ip-status').innerHTML = `Jouw IP-adres: <code>${esc(a.client_ip)}</code>${a.client_ip !== a.remote_addr ? ` <span class="muted small">(via proxy ${esc(a.remote_addr)})</span>` : ''}
    — ${a.client_trusted ? '<b class="yes">valt binnen je lokale netwerken</b>' : '<b class="no">valt buiten je lokale netwerken</b>'}`;
}

async function saveAccess(e) {
  e.preventDefault();
  const f = e.target;
  const err = $('.form-error', f);
  err.hidden = true;
  try {
    await api('access_save', { lan_no_login: f.lan_no_login.checked, lan_as_admin: f.lan_as_admin.checked, trusted_networks: f.trusted_networks.value, trusted_proxies: f.trusted_proxies.value });
    toast('Toegang opgeslagen');
    loadAccess();
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
  }
}

// ---------------------------------------------------------------- API keys
function apiBase() {
  return location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/v1.php';
}

async function loadApiKeys() {
  $('#api-base').textContent = apiBase();
  const keys = await api('api_keys');
  $('#api-keys').innerHTML = keys.length ? keys.map((k) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(k.name)}</strong> <code class="muted">${esc(k.key_hint)}</code><br>
        <span class="muted small">Laatst gebruikt: ${k.last_used ? ago(k.last_used) : 'nooit'}</span></span>
      <button class="icon-btn" title="Intrekken" data-del="${k.id}" data-name="${esc(k.name)}">🗑</button>
    </div>`).join('') : '<p class="muted small">Nog geen sleutels.</p>';
}

async function createApiKey(e) {
  e.preventDefault();
  try {
    const r = await api('api_key_create', Object.fromEntries(new FormData(e.target)));
    e.target.reset();
    loadApiKeys();
    modal({
      title: 'Nieuwe API-sleutel',
      body: `<p>Kopieer deze sleutel nu — hij wordt <b>niet</b> opnieuw getoond.</p>
        <div class="new-key"><input id="new-key" readonly value="${esc(r.key)}"><button type="button" class="btn" id="copy-key">Kopieer</button></div>
        <p class="muted small" style="margin-top:1rem">Test:</p>
        <pre class="code">curl -H "Authorization: Bearer ${esc(r.key)}" ${esc(apiBase())}/status</pre>`,
      buttons: [{ label: 'Klaar', cls: 'primary', submit: true }],
    });
    $('#copy-key').onclick = async () => {
      $('#new-key').select();
      try { await navigator.clipboard.writeText(r.key); } catch (ex) { document.execCommand('copy'); }
      toast('Gekopieerd');
    };
  } catch (ex) { toast(ex.message, 'err'); }
}

async function deleteApiKey(e) {
  const b = e.target.closest('[data-del]');
  if (!b || !confirm(`API-sleutel "${b.dataset.name}" intrekken? Toepassingen die hem gebruiken verliezen direct toegang.`)) return;
  try { await api('api_key_delete', { id: b.dataset.del }); toast('Sleutel ingetrokken'); loadApiKeys(); }
  catch (ex) { toast(ex.message, 'err'); }
}

function isAdmin() { return document.body.dataset.role === 'admin'; }

async function loadSettings() {
  loadUsers();
  if (!isAdmin()) return;   // de rest van de instellingen is alleen voor beheerders
  loadApiKeys();
  loadAccess();
  loadDbInfo();
  const s = await api('settings');
  const f = $('#settings-form');
  $('#mqtt-status').innerHTML = s.mqtt_enabled !== '1' ? ''
    : s.mqtt_last_error ? `<b class="bad">✘ ${esc(s.mqtt_last_error)}</b>`
    : s.mqtt_last_ok ? `<b class="good">✔ Laatst gepubliceerd ${ago(s.mqtt_last_ok)}</b>` : '<span class="muted">Nog niet gepubliceerd</span>';
  Object.entries(s).forEach(([k, v]) => {
    const el = f.elements[k];
    if (!el) return;
    if (el.type === 'checkbox') el.checked = v === '1';
    else el.value = v ?? '';
  });
  const parse = (v) => { try { return JSON.parse(v || '{}') || {}; } catch (e) { return {}; } };
  notifyGlobal = notifyEditor($('#notify-editor'), { rules: parse(s.notify_rules), channels: parse(s.notify_channels), flap: parse(s.notify_flap) },
    { pushover_enabled: s.pushover_enabled === '1', email_enabled: s.email_enabled === '1', ha_webhook_enabled: s.ha_webhook_enabled === '1' });
  quietGlobal = quietEditor($('#quiet-editor'), parse(s.notify_quiet), true);
  backupFormToggle();
  loadBackups();
  loadUpdate(false);
  refresh();   // verse agentversies voor de kaart "Agents bijwerken"
}

/** Kaart "Agents": hoeveel agents verouderd zijn, en de knop om ze allemaal bij te werken. */
function renderAgentUpdates() {
  const agents = state.hosts.filter((h) => h.mode === 'agent' && +h.enabled && h.agent_seen);
  const old = agents.filter((h) => !h.agent_version || verCmp(h.agent_version, state.version) < 0);
  const busy = agents.filter((h) => h.agent_update_req);
  $('#agents-status').innerHTML = !agents.length ? 'Nog geen agents.'
    : (old.length ? `<b class="warn-txt">${old.length} van ${agents.length} agent(s) verouderd</b>: ${old.map((h) => esc(h.name) + (h.agent_version ? ` (${esc(h.agent_version)})` : '')).join(', ')}`
      : `<b class="good-txt">✔ Alle ${agents.length} agent(s) zijn up-to-date (${esc(state.version)})</b>`)
      + (busy.length ? ` · ⏳ ${busy.length} wordt bijgewerkt` : '');
  $('#agents-update-all').hidden = !old.length;
}

async function updateAllAgents(e) {
  const b = e.currentTarget;
  b.disabled = true;
  try {
    const r = await api('agent_update', { all: true });
    toast(`${r.count} agent(s) worden bijgewerkt bij hun volgende rapport`);
    await refresh();
    renderAgentUpdates();
  } catch (ex) { toast(ex.message, 'err'); }
  b.disabled = false;
}

// ---------------------------------------------------------------- Meldregels (algemeen en per check)
const NOTIFY_ROWS = [
  ['warn', '🟠 Warning'], ['crit', '🔴 Critical'], ['unknown', '⚪ Onbekend'], ['recovery', '✅ Hersteld'],
];
const NOTIFY_CHANNELS = [['pushover', 'Pushover', 'pushover_enabled'], ['email', 'E-mail', 'email_enabled'], ['webhook', 'Home Assistant', 'ha_webhook_enabled']];

/** Tabel met per status: melden (nooit / direct / na X min), herhalen en kanalen. Geeft {get()} terug. */
function notifyEditor(el, cfg, enabled = {}) {
  const r = cfg.rules || {}, ch = cfg.channels || {}, fl = cfg.flap || {};
  el.innerHTML = `<div class="table-scroll"><table class="notify-table">
    <thead><tr><th>Status</th><th>Melden</th><th>Herhalen</th>
      ${NOTIFY_CHANNELS.map(([k, l, set]) => `<th class="nt-ch" title="${enabled[set] === false ? 'Dit kanaal staat uit (zie hieronder)' : ''}">${l}${enabled[set] === false ? ' <span class="muted">(uit)</span>' : ''}</th>`).join('')}</tr></thead>
    <tbody>${NOTIFY_ROWS.map(([k, label]) => {
      const x = r[k] || { mode: 'now', min: 0, repeat: 0 };
      return `<tr data-k="${k}">
        <td class="nt-label">${label}</td>
        <td><div class="nt-when">
          <select data-f="mode">
            <option value="never" ${x.mode === 'never' ? 'selected' : ''}>Nooit</option>
            <option value="now" ${x.mode === 'now' ? 'selected' : ''}>Direct</option>
            <option value="after" ${x.mode === 'after' ? 'selected' : ''}>${k === 'recovery' ? 'Na … min OK' : 'Na … minuten'}</option>
          </select>
          <input type="number" data-f="min" min="1" max="10080" value="${x.mode === 'after' ? x.min : 5}" ${x.mode === 'after' ? '' : 'hidden'} title="minuten">
        </div></td>
        <td>${k === 'recovery' ? '<span class="muted small">–</span>'
          : `<span class="nt-rep">elke <input type="number" data-f="repeat" min="0" max="10080" value="${x.repeat || 0}"> min</span>`}</td>
        ${NOTIFY_CHANNELS.map(([c]) => `<td class="nt-ch"><input type="checkbox" data-c="${c}" ${(ch[k] || {})[c] !== false ? 'checked' : ''}></td>`).join('')}
      </tr>`;
    }).join('')}</tbody></table></div>
    <p class="hint">Herhalen: elke X minuten opnieuw zolang het probleem blijft (0 = nooit).</p>
    <div class="flap-box">
      <label class="switch small"><input type="checkbox" data-flap="on" ${fl.on !== false ? 'checked' : ''}><span></span> <b>Anti-flapping</b></label>
      <div class="flap-fields">
        Een check flapt bij <input type="number" data-flap="changes" min="2" max="100" value="${fl.changes ?? 4}"> statuswisselingen binnen
        <input type="number" data-flap="window" min="1" max="1440" value="${fl.window ?? 30}"> minuten; weer stabiel na
        <input type="number" data-flap="stable" min="1" max="1440" value="${fl.stable ?? 15}"> minuten zonder wisseling.
      </div>
      <p class="hint">Flapt een check, dan krijg je één melding "staat te flappen", daarna per status (warning, critical, onbekend, hersteld) hooguit één melding, en een melding als hij weer stabiel is.</p>
    </div>`;
  const sync = () => { $('.flap-fields', el).classList.toggle('off', !$('[data-flap=on]', el).checked); $$('tr[data-k]', el).forEach((tr) => {
    const mode = $('[data-f=mode]', tr).value;
    $('[data-f=min]', tr).hidden = mode !== 'after';
    $$('input', tr).forEach((i) => { if (i.dataset.f !== 'min') i.disabled = mode === 'never' && i.dataset.f === 'repeat'; });
  }); };
  el.onchange = sync;
  sync();
  return {
    get() {
      const out = { rules: {}, channels: {} };
      $$('tr[data-k]', el).forEach((tr) => {
        const k = tr.dataset.k;
        out.rules[k] = { mode: $('[data-f=mode]', tr).value, min: +$('[data-f=min]', tr).value || 1, repeat: +($('[data-f=repeat]', tr)?.value || 0) };
        out.channels[k] = Object.fromEntries($$('[data-c]', tr).map((i) => [i.dataset.c, i.checked]));
      });
      out.flap = { on: $('[data-flap=on]', el).checked, changes: +$('[data-flap=changes]', el).value, window: +$('[data-flap=window]', el).value, stable: +$('[data-flap=stable]', el).value };
      return out;
    },
  };
}
let notifyGlobal = null;   // editor van de algemene meldregels
let quietGlobal = null;    // editor van de algemene stille uren

// ---------------------------------------------------------------- Stille uren
const QUIET_DAYS = [[1, 'ma'], [2, 'di'], [3, 'wo'], [4, 'do'], [5, 'vr'], [6, 'za'], [7, 'zo']];
const QUIET_DEFAULT = () => ({ from: '23:00', to: '07:00', days: [1, 2, 3, 4, 5, 6, 7] });
const QUIET_MODES = { global: 'algemeen (Instellingen)', own: 'eigen vensters', never: 'nooit stil: altijd melden' };
/** Eigen stille uren van een check ({mode, windows}) of null (= algemeen). */
function quietOf(c) { try { return JSON.parse(c.quiet || 'null'); } catch (e) { return null; } }
/** "ma–vr 23:00–07:00 · za, zo 00:00–10:00" */
function quietText(windows) {
  return (windows || []).map((w) => {
    const d = w.days.length === 7 ? 'elke dag' : w.days.join(',') === '1,2,3,4,5' ? 'ma–vr' : w.days.join(',') === '6,7' ? 'za–zo'
      : w.days.map((x) => QUIET_DAYS[x - 1][1]).join(', ');
    return `${d} ${w.from}–${w.to}`;
  }).join(' · ');
}

/** Vensters (van–tot, dagen) met optioneel een aan/uit-schakelaar. Geeft {get()} terug: { on, windows }. */
function quietEditor(el, cfg, withSwitch) {
  const row = (w) => `<div class="quiet-row">
      <span class="quiet-time">van <input type="time" data-q="from" value="${esc(w.from)}" required> tot <input type="time" data-q="to" value="${esc(w.to)}" required></span>
      <span class="quiet-days">${QUIET_DAYS.map(([d, l]) => `<label class="day-chip"><input type="checkbox" data-day="${d}" ${w.days.includes(d) ? 'checked' : ''}><span>${l}</span></label>`).join('')}</span>
      <button type="button" class="icon-btn" data-q="del" title="Venster verwijderen">🗑</button>
    </div>`;
  const wins = cfg.windows && cfg.windows.length ? cfg.windows : [QUIET_DEFAULT()];
  el.innerHTML = `${withSwitch ? `<label class="switch small"><input type="checkbox" data-q="on" ${cfg.on ? 'checked' : ''}><span></span> <b>Stille uren aan</b></label>` : ''}
    <div class="quiet-fields"><div class="quiet-list">${wins.map(row).join('')}</div>
      <button type="button" class="btn small-btn" data-q="add">＋ Venster</button>
      <p class="hint">Over middernacht mag (23:00 tot 07:00 hoort bij de dag waarop het begint). Van gelijk aan tot = 24 uur. Meerdere vensters, bv. door de week 23:00–07:00 en in het weekend 00:00–10:00.</p></div>`;
  const sync = () => {
    if (withSwitch) $('.quiet-fields', el).classList.toggle('off', !$('[data-q=on]', el).checked);
    $$('[data-q=del]', el).forEach((b) => (b.disabled = $$('.quiet-row', el).length < 2));
  };
  el.onclick = (e) => {
    if (e.target.closest('[data-q=add]')) $('.quiet-list', el).insertAdjacentHTML('beforeend', row({ from: '00:00', to: '08:00', days: [6, 7] }));
    const del = e.target.closest('[data-q=del]');
    if (del) del.closest('.quiet-row').remove();
    sync();
  };
  el.onchange = sync;
  sync();
  return {
    get() {
      return {
        on: withSwitch ? $('[data-q=on]', el).checked : true,
        windows: $$('.quiet-row', el).map((r) => ({ from: $('[data-q=from]', r).value, to: $('[data-q=to]', r).value,
          days: $$('[data-day]', r).filter((i) => i.checked).map((i) => +i.dataset.day) })).filter((w) => w.from && w.to && w.days.length),
      };
    },
  };
}

function settingsData() {
  const f = $('#settings-form');
  if (notifyGlobal) {
    const n = notifyGlobal.get();
    f.notify_rules.value = JSON.stringify(n.rules);
    f.notify_channels.value = JSON.stringify(n.channels);
    f.notify_flap.value = JSON.stringify(n.flap);
  }
  if (quietGlobal) f.notify_quiet.value = JSON.stringify(quietGlobal.get());
  const d = {};
  [...f.elements].forEach((el) => {
    if (!el.name) return;
    d[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
  });
  return d;
}

async function saveSettings(e) {
  e.preventDefault();
  try { await api('settings_save', settingsData()); toast('Instellingen opgeslagen'); loadSettings(); }
  catch (ex) { toast(ex.message, 'err'); }
}

async function testPushover(e) {
  const b = e.target;
  b.disabled = true;
  try { await api('pushover_test', settingsData()); toast('Testbericht verstuurd! Check je telefoon 📱'); }
  catch (ex) { toast('Pushover: ' + ex.message, 'err'); }
  b.disabled = false;
}

async function testWebhook(e) {
  const b = e.target;
  b.disabled = true;
  try { await api('webhook_test', settingsData()); toast('Testbericht naar Home Assistant verstuurd 🏠'); }
  catch (ex) { toast('Webhook: ' + ex.message, 'err'); }
  b.disabled = false;
}

/** Testknop voor een meldkanaal, met de (nog niet opgeslagen) waarden uit het formulier. */
async function testChannel(e, action, okMsg, label) {
  const b = e.target;
  b.disabled = true;
  try {
    const r = await api(action, settingsData());
    toast(okMsg || r.message);
    if (action === 'mqtt_test') loadSettings();
  } catch (ex) { toast(`${label}: ${ex.message}`, 'err'); }
  b.disabled = false;
}

async function changePassword(e) {
  e.preventDefault();
  try {
    await api('password', Object.fromEntries(new FormData(e.target)));
    e.target.reset();
    toast('Wachtwoord gewijzigd');
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Gebruikers & tweestapsverificatie
let users = [];
const ROLE_LABEL = { admin: '🔑 Beheerder', user: '👤 Gebruiker', readonly: '👁 Alleen lezen' };
async function loadUsers() {
  if ($('#users-card').hidden) return;
  users = await api('users');
  if (isAdmin()) {
    api('pw_reset_info').then((r) => {
      $('#pw-reset-info').innerHTML = (r.unavailable
        ? `<span class="warn-txt">⚠️ Wachtwoord vergeten werkt nu niet: ${esc(r.unavailable)}.</span> `
        : '🔑 Wachtwoord vergeten werkt voor accounts met een e-mailadres. ')
        + `<a href="#" id="pw-reset-log">Logboek bekijken (${r.log.length})</a>`;
      $('#pw-reset-log').onclick = (e) => { e.preventDefault(); pwResetLog(r.log); };
    }).catch(() => {});
  }
  $('#users').innerHTML = users.map((u) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(u.username)}</strong>${u.me ? ' <span class="badge">jij</span>' : ''}
        <span class="role-tag r-${esc(u.role)}">${ROLE_LABEL[u.role] || esc(u.role)}</span>
        ${u.totp ? '<span class="tfa on" title="Tweestapsverificatie staat aan">🔐 2FA aan</span>' : '<span class="tfa" title="Alleen wachtwoord">2FA uit</span>'}<br>
        <span class="muted small">${u.email ? `✉ ${esc(u.email)}` : '<span title="Nodig voor wachtwoord vergeten">✉ geen e-mailadres</span>'} · Laatst ingelogd: ${u.last_login ? ago(u.last_login) : 'nooit'}</span></span>
      <span class="row-actions">
        ${u.totp
          ? `<button class="btn" data-uact="totp-reset" data-id="${u.id}" title="Tweestapsverificatie uitzetten, bv. bij een verloren telefoon">2FA resetten</button>`
          : `<button class="btn" data-uact="totp-setup" data-id="${u.id}">🔐 2FA instellen</button>`}
        ${u.me ? '' : `<select class="role-select need-admin" data-uact="role" data-id="${u.id}" title="Rol wijzigen">
          ${Object.entries(ROLE_LABEL).map(([k, l]) => `<option value="${k}" ${u.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select>`}
        <button class="btn small-btn" data-uact="devices" data-id="${u.id}" title="Apparaten waarop ${esc(u.username)} ingelogd blijft">📱 ${+u.devices}</button>
        <button class="icon-btn" data-uact="email" data-id="${u.id}" title="E-mailadres (voor wachtwoord vergeten)">✉</button>
        <button class="icon-btn need-admin" data-uact="password" data-id="${u.id}" title="Nieuw wachtwoord instellen">🔑</button>
        ${u.me ? '' : `<button class="icon-btn need-admin" data-uact="delete" data-id="${u.id}" title="Verwijderen">🗑</button>`}
      </span>
    </div>`).join('');
}

/** Logboek van "wachtwoord vergeten": aanvragen, verstuurde links en herstelde wachtwoorden. */
function pwResetLog(log) {
  modal({
    title: '🔑 Wachtwoord vergeten – logboek',
    wide: true,
    body: log.length ? `<div class="table-scroll"><table class="port-table"><thead><tr><th>Wanneer</th><th>Account</th><th>Ingevuld</th><th>IP-adres</th><th>Wat</th></tr></thead><tbody>
      ${log.map((l) => `<tr><td title="${esc(l.created_at)}">${ago(l.created_at)}</td><td>${l.username ? esc(l.username) : '<span class="muted">–</span>'}</td>
        <td class="mono small">${esc(l.ident)}</td><td class="mono small">${esc(l.ip)}</td><td>${esc(l.event)}</td></tr>`).join('')}</tbody></table></div>
      <p class="muted small">De laatste 50 regels; ouder dan 90 dagen wordt opgeruimd.</p>`
      : '<p class="muted">Nog geen aanvragen.</p>',
    buttons: [{ label: 'Sluiten', cls: 'primary', submit: true }],
  });
}

async function createUser(e) {
  e.preventDefault();
  const f = e.target;
  try {
    const r = await api('user_create', { username: f.username.value, password: f.password.value, email: f.email.value, role: f.role.value });
    const name = f.username.value;
    const withTotp = f.totp.checked;
    f.reset();
    toast(`Gebruiker ${name} aangemaakt`);
    await loadUsers();
    if (withTotp) totpSetup(r.id, name);
  } catch (ex) { toast(ex.message, 'err'); }
}

async function onUsersClick(e) {
  const b = e.target.closest('button[data-uact]');
  if (!b) return;
  const u = users.find((x) => +x.id === +b.dataset.id);
  try {
    switch (b.dataset.uact) {
      case 'totp-setup': return totpSetup(u.id, u.username);
      case 'devices': return devicesDialog(u);
      case 'totp-reset':
        if (!confirm(`Tweestapsverificatie van ${u.username} resetten?\nInloggen kan daarna weer met alleen het wachtwoord, tot 2FA opnieuw is ingesteld.`)) return;
        await api('totp_reset', { id: u.id });
        toast(`2FA van ${u.username} gereset`);
        return loadUsers();
      case 'delete':
        if (!confirm(`Gebruiker ${u.username} verwijderen?`)) return;
        await api('user_delete', { id: u.id });
        toast(`${u.username} verwijderd`);
        return loadUsers();
      case 'email':
        return modal({
          title: `E-mailadres – ${u.username}`,
          body: `<p class="muted small" style="margin-top:0">Hierheen gaat de link als ${u.me ? 'je' : esc(u.username) + ' zijn of haar'} wachtwoord vergeten is. Leeg = geen herstel per e-mail.</p>
            <label>E-mailadres<input type="email" name="email" value="${esc(u.email || '')}" maxlength="190" autocomplete="email" autofocus></label>`,
          buttons: [{ label: 'Opslaan', cls: 'primary', submit: true }],
          onSubmit: async (d) => { await api('user_email', { id: u.id, email: d.email }); toast('E-mailadres opgeslagen'); loadUsers(); },
        });
      case 'password':
        return modal({
          title: `Nieuw wachtwoord – ${u.username}`,
          body: `<label>Nieuw wachtwoord<input type="password" name="password" minlength="8" required autocomplete="new-password" autofocus></label>`,
          buttons: [{ label: 'Opslaan', cls: 'primary', submit: true }],
          onSubmit: async (d) => { await api('user_password', { id: u.id, password: d.password }); toast('Wachtwoord gewijzigd'); },
        });
    }
  } catch (ex) { toast(ex.message, 'err'); }
}

/** Onthouden apparaten van een gebruiker: bekijken en vergeten. */
async function devicesDialog(u) {
  let list;
  try { list = await api('devices', null, { user_id: u.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const icon = (d) => (/iPhone|Android/.test(d) ? '📱' : /iPad/.test(d) ? '📲' : '💻');
  modal({
    title: `📱 Onthouden apparaten – ${u.username}`,
    body: list.length ? `
      <p class="muted small" style="margin-top:0">Op deze apparaten blijft ${esc(u.username)} ingelogd (zonder wachtwoord en 2FA). Een apparaat wordt vanzelf vergeten na 30 dagen niet gebruiken, en bij een wachtwoordwijziging.</p>
      <div class="key-list" id="device-list">${list.map((d) => `
        <div class="key-row">
          <span class="grow"><strong>${icon(d.device)} ${esc(d.device || 'Onbekend apparaat')}</strong>${d.current ? ' <span class="badge">dit apparaat</span>' : ''}<br>
            <span class="muted small">Laatst gebruikt ${ago(d.last_used)} · ${esc(d.ip)} · sinds ${esc(fmtUntil(d.created_at))}</span></span>
          <button type="button" class="btn small-btn" data-forget="${d.id}">Vergeten</button>
        </div>`).join('')}</div>`
      : '<p class="muted">Geen onthouden apparaten. Zet bij het inloggen <b>Onthoud mij op dit apparaat</b> aan.</p>',
    buttons: [
      ...(list.length ? [{ label: 'Alle apparaten vergeten', cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(`Alle onthouden apparaten van ${u.username} vergeten? Daar moet dan opnieuw worden ingelogd.`)) return;
        try { await api('device_forget_all', { user_id: u.id }); toast('Alle apparaten vergeten'); $('#modal').close(); loadUsers(); }
        catch (ex) { toast(ex.message, 'err'); }
      } }] : []),
      { label: 'Sluiten', cls: 'primary', submit: true },
    ],
  });
  $('#device-list')?.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-forget]');
    if (!b) return;
    try {
      await api('device_forget', { id: b.dataset.forget });
      toast('Apparaat vergeten');
      loadUsers();
      devicesDialog(u);
    } catch (ex) { toast(ex.message, 'err'); }
  });
}

async function totpSetup(id, username) {
  let r;
  try { r = await api('totp_setup', { id }); } catch (ex) { return toast(ex.message, 'err'); }
  let qr = '';
  try {
    const q = qrcode(0, 'M');
    q.addData(r.uri);
    q.make();
    qr = q.createSvgTag(5, 4);
  } catch (ex) { qr = '<p class="muted small">QR-code maken lukte niet; gebruik de code hieronder.</p>'; }
  modal({
    title: `🔐 Tweestapsverificatie – ${username}`,
    body: `
      <ol class="steps">
        <li>Open een authenticator-app op de telefoon van <b>${esc(username)}</b> en scan deze QR-code:</li>
      </ol>
      <div class="qr">${qr}</div>
      <p class="muted small" style="text-align:center">Scannen lukt niet? Voer deze sleutel handmatig in:<br><code class="secret">${esc(r.secret.replace(/(.{4})/g, '$1 ').trim())}</code></p>
      <ol class="steps" start="2">
        <li>Vul de 6-cijferige code in die de app nu toont. Pas daarna staat 2FA aan.</li>
      </ol>
      <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required class="mono totp-input" autofocus></label>`,
    buttons: [{ label: 'Activeren', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('totp_confirm', { id, code: d.code });
      toast(`2FA staat aan voor ${username} 🔐`);
      loadUsers();
    },
  });
}

// ---------------------------------------------------------------- Databasewachtwoord vernieuwen
let dbInfo = null;
async function loadDbInfo() {
  try { dbInfo = await api('db_info'); } catch (ex) { return; }
  $('#db-status').innerHTML = `Gebruiker <code>${esc(dbInfo.db.user)}</code> op <code>${esc(dbInfo.db.host)}</code>, database <code>${esc(dbInfo.db.name)}</code> · `
    + (dbInfo.helper ? '<b class="good-txt">hulpdienst actief</b>' : dbInfo.direct ? 'RzMon mag config.php zelf opslaan' : '<b class="warn-txt">hulpdienst niet geïnstalleerd</b>');
}

/** Een commando met kopieerknop. */
function copyBox(id, cmd) {
  return `<div class="copy-line"><pre class="code" id="${id}">${esc(cmd)}</pre><button type="button" class="btn" data-copy="${id}">Kopieer</button></div>`;
}
function wireCopy(root) {
  $$('[data-copy]', root).forEach((b) => (b.onclick = async () => {
    const el = $('#' + b.dataset.copy);
    try { await navigator.clipboard.writeText(el.textContent); toast('Gekopieerd'); }
    catch (ex) { getSelection().selectAllChildren(el); toast('Geselecteerd — druk Ctrl+C', 'err'); }
  }));
}

/** Bevestigen met je eigen wachtwoord, of een woord typen als je automatisch beheerder bent op het LAN. */
function confirmField(word) {
  const lan = !!document.querySelector('.lan-badge');
  return `<label>${lan ? `Typ <b>${word}</b> om te bevestigen` : 'Bevestig met je eigen wachtwoord'}
    <input name="confirm" type="${lan ? 'text' : 'password'}" required autocomplete="${lan ? 'off' : 'current-password'}" autofocus></label>`;
}

function dbRotateDialog() {
  const info = dbInfo || {};
  const canClick = info.helper || info.direct;
  const terminal = `
    <details class="small api-docs" ${canClick ? '' : 'open'}>
      <summary>Via de terminal (als root)</summary>
      <p class="muted small">Dit doet hetzelfde als de knop, en werkt altijd:</p>
      ${copyBox('cmd-rotate', info.cli_cmd || '')}
      ${info.helper ? '' : `<p class="muted small" style="margin-top:.8rem">Hulpdienst installeren, zodat de knop ook werkt als de RzMon-map vergrendeld is:</p>${copyBox('cmd-helper', info.helper_cmd || '')}`}
    </details>`;
  modal({
    title: '🔑 Databasewachtwoord vernieuwen',
    body: canClick ? `
      <p style="margin-top:0">RzMon maakt een nieuw wachtwoord van 32 tekens en slaat het op in de database en in <code>config.php</code>${info.helper ? ' (en in /root/rzmon-install.txt)' : ''}.
        Daarna test RzMon de verbinding; lukt dat niet, dan wordt het oude wachtwoord automatisch teruggezet.</p>
      <p class="muted small">Je ziet het nieuwe wachtwoord <b>één keer</b>. RzMon zelf heeft het niet nodig van je; bewaar het alleen als je zelf met de database wilt werken.</p>
      ${confirmField('VERNIEUWEN')}
      ${terminal}`
      : `<p style="margin-top:0">De RzMon-map is vergrendeld (veilig), en de <b>hulpdienst</b> die de wissel als root uitvoert is nog niet geïnstalleerd.
          Voer op de server één van deze commando's uit:</p>
        <p class="small"><b>1. Hulpdienst installeren</b> (eenmalig; daarna werkt deze knop):</p>${copyBox('cmd-helper', info.helper_cmd || '')}
        <p class="small" style="margin-top:1rem"><b>2. Of nu meteen vernieuwen</b> in de terminal:</p>${copyBox('cmd-rotate', info.cli_cmd || '')}`,
    buttons: canClick ? [{ label: '🔑 Vernieuwen', cls: 'primary', submit: true }] : [{ label: 'Sluiten', cls: 'primary', submit: true }],
    onSubmit: canClick ? async (d) => {
      let r = await api('db_rotate', { confirm: d.confirm });
      for (let i = 0; r.pending && i < 40; i++) {   // hulpdienst: even wachten op het resultaat
        await new Promise((res) => setTimeout(res, 750));
        try { r = await api('db_rotate_status'); }
        catch (ex) { if (/hulpdienst reageert niet|Geen wachtwoordwissel/.test(ex.message)) throw ex; }   // tijdens de wissel kan één verzoek mislukken
      }
      if (r.pending) throw new Error('Geen antwoord van de hulpdienst');
      setTimeout(() => showNewDbPassword(r), 50);
    } : null,
  });
  wireCopy($('#modal-body'));
}

function showNewDbPassword(r) {
  modal({
    title: '✔ Nieuw databasewachtwoord',
    body: `<p style="margin-top:0">Opgeslagen en getest; RzMon werkt gewoon door. Dit wachtwoord wordt <b>niet opnieuw getoond</b>.</p>
      ${copyBox('new-db-pass', r.password)}
      ${(r.warnings || []).map((w) => `<p class="callout small">⚠️ ${esc(w)}</p>`).join('')}`,
    buttons: [{ label: 'Klaar', cls: 'primary', submit: true }],
  });
  wireCopy($('#modal-body'));
  loadDbInfo();
}

// ---------------------------------------------------------------- RzMon bijwerken
let updatePoll = null;

/** Changelog-tekst (Markdown) als eenvoudige HTML: kopjes, opsommingen, vet, cursief en code. */
function mdLite(text) {
  const inline = (t) => esc(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\*(.+?)\*/g, '<i>$1</i>').replace(/`([^`]+)`/g, '<code>$1</code>');
  let html = '', depth = 0;
  for (const line of text.split('\n')) {
    const m = line.match(/^(\s*)- (.*)$/);
    const d = m ? Math.floor(m[1].length / 2) + 1 : 0;
    while (depth < d) { html += '<ul>'; depth++; }
    while (depth > d) { html += '</ul>'; depth--; }
    if (m) html += `<li>${inline(m[2])}</li>`;
    else if (/^### /.test(line)) html += `<h4>${inline(line.slice(4))}</h4>`;
    else if (line.trim()) html += `<p>${inline(line)}</p>`;
  }
  while (depth-- > 0) html += '</ul>';
  return html;
}

async function loadUpdate(force) {
  const st = $('#update-status');
  let u;
  try { u = await api('update_info', null, force ? { force: 1 } : {}); } catch (ex) { st.textContent = ex.message; return; }
  const parts = [`Je gebruikt <b>${esc(u.current)}</b>.`];
  if (u.error) parts.push(`<b class="warn-txt">⚠️ ${esc(u.error)}</b>`);
  else if (u.available) parts.push(`<b class="good-txt">Nieuwe versie beschikbaar: ${esc(u.latest)}</b>`);
  else if (u.latest) parts.push('<span class="good-txt">✔ Dit is de nieuwste versie.</span>');
  if (u.checked_at) parts.push(`<span class="muted">Gecontroleerd ${ago(u.checked_at)}.</span>`);
  if (u.available && u.unavailable) {
    parts.push(`<br><b class="warn-txt">Bijwerken vanuit RzMon kan nog niet:</b> ${esc(u.unavailable)}. Voer één keer uit op de server:`
      + copyBox('cmd-update-setup', 'curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh && sudo bash install-rocky.sh'));
  }
  st.innerHTML = parts.join(' ');
  $('#update-notes').innerHTML = u.available && u.notes.length ? `<details class="update-notes" open><summary>Wat is er nieuw (${u.notes.length} versie${u.notes.length === 1 ? '' : 's'})</summary>
    ${u.notes.map((n) => `<div class="un-ver"><h3>${esc(n.version)} <span class="muted small">${esc(n.date)}</span></h3>${mdLite(n.text)}</div>`).join('')}</details>` : '';
  const b = $('#update-start');
  b.hidden = !u.available || !!u.unavailable;
  b.textContent = `⬆ Nu bijwerken naar ${u.latest}`;
  showUpdateProgress(u.status, '');
  if (u.status && ['queued', 'running'].includes(u.status.state)) pollUpdate();
}

const UPDATE_STEPS = [['check', 'Controleren'], ['backup', 'Back-up maken'], ['download', 'Nieuwste versie ophalen'], ['install', 'Installeren'], ['klaar', 'Klaar']];

function showUpdateProgress(s, log) {
  const el = $('#update-progress');
  // Een afgeronde update van langer dan een dag geleden niet meer tonen
  if (!s || (['ok', 'error'].includes(s.state) && s.at < Date.now() / 1000 - 86400)) { el.hidden = true; return; }
  el.hidden = false;
  const cur = UPDATE_STEPS.findIndex(([k]) => k === s.step);
  const icon = (i) => s.state === 'ok' || i < cur ? '✔' : i === cur ? (s.state === 'error' ? '✘' : '⏳') : '·';
  el.innerHTML = `<div class="update-box ${s.state}">
    <b>${s.state === 'ok' ? '✔ ' : s.state === 'error' ? '✘ ' : '⏳ '}${esc(s.message || '')}</b>
    ${s.state === 'queued' && s.stuck ? '<p class="warn-txt small">De hulpdienst reageert niet. Controleer op de server: <code>systemctl status rzmon-update.path</code></p>' : ''}
    ${s.state !== 'queued' ? `<ol class="update-steps">${UPDATE_STEPS.map(([k, l], i) => `<li class="${i === cur ? 'cur' : ''}">${icon(i)} ${l}</li>`).join('')}</ol>` : ''}
    ${log ? `<details ${s.state === 'error' ? 'open' : ''}><summary>Logboek</summary><pre class="update-log">${esc(log)}</pre></details>` : ''}
  </div>`;
}

function pollUpdate() {
  clearTimeout(updatePoll);
  updatePoll = setTimeout(async () => {
    let r;
    try { r = await api('update_status'); } catch (ex) { pollUpdate(); return; }   // even onbereikbaar tijdens het installeren
    showUpdateProgress(r.status, r.log);
    if (r.status && ['queued', 'running'].includes(r.status.state)) return pollUpdate();
    if (r.status && r.status.state === 'ok') {
      toast(r.status.message);
      setTimeout(() => location.reload(), 2500);   // nieuwe versie laden
    }
  }, 2000);
}

async function startUpdate(e) {
  const backup = $('#settings-form').update_backup.checked;
  const btn = e.currentTarget;
  if (!confirm(`RzMon bijwerken naar de nieuwste versie?${backup ? '\nEerst wordt er een back-up gemaakt.' : '\nZonder back-up vooraf.'}\n\nRzMon is daarna even niet bereikbaar.`)) return;
  btn.disabled = true;
  try {
    await api('update_start', { backup });
    showUpdateProgress({ state: 'queued', step: 'queued', message: 'Wachten op de hulpdienst…', at: Date.now() / 1000 }, '');
    pollUpdate();
  } catch (ex) { toast(ex.message, 'err'); }
  btn.disabled = false;
}

// ---------------------------------------------------------------- Back-up
let backupInfo = null;
let backupUnlockedUntil = 0;   // downloaden zonder opnieuw te bevestigen tot dit moment
const fmtSize = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(b / 1024))} kB`);
const fmtDateTime = (dt) => new Date(dt.replace(' ', 'T')).toLocaleString('nl-NL',
  { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

function backupFormToggle() {
  const f = $('#settings-form');
  $('.backup-weekday', f).hidden = f.backup_freq.value !== 'weekly';
  $('.update-time', f).hidden = !f.update_auto.checked;   // tijdstip alleen bij automatisch bijwerken
}

async function loadBackups() {
  const st = $('#backup-status');
  try { backupInfo = await api('backup_info'); } catch (ex) { st.textContent = ex.message; return; }
  const b = backupInfo;
  backupUnlockedUntil = Date.now() + b.unlocked * 1000 - 5000;
  ['#backup-now', '#backup-upload'].forEach((id) => ($(id).disabled = !!b.problem));
  if (b.problem) {
    st.innerHTML = `<b class="warn-txt">⚠️ ${esc(b.problem)}.</b> Voer dit één keer uit op de server:${copyBox('cmd-backup-fix', b.fix_cmd)}`;
    wireCopy(st);
    $('#backup-list').innerHTML = '';
    return;
  }
  const parts = [];
  if (b.last_error) parts.push(`<b class="bad">✘ Automatische back-up mislukt: ${esc(b.last_error)}</b> (RzMon probeert het elk uur opnieuw)`);
  const lastAuto = b.list.find((x) => x.type === 'auto');
  if (lastAuto) parts.push(`Laatste automatische: <b>${ago(lastAuto.at)}</b>`);
  parts.push(b.next ? `Volgende: ${fmtDateTime(b.next)}` : 'Automatische back-up staat uit');
  if (!b.has_pass) parts.push('<b class="warn-txt">⚠️ Geen zip-wachtwoord: back-ups worden niet versleuteld</b>');
  if (!b.encryption) parts.push('<b class="warn-txt">⚠️ Deze PHP kan geen versleutelde zip maken (libzip te oud)</b>');
  st.innerHTML = parts.join(' · ');
  $('#backup-list').innerHTML = b.list.length ? b.list.map((x) => `
    <div class="key-row">
      <span class="grow"><strong>${fmtDateTime(x.at)}</strong> <span class="muted small">· ${esc(x.type_label)}</span><br>
        <span class="muted small">${fmtSize(x.size)} · ${x.encrypted ? '🔒 versleuteld' : '🔓 niet versleuteld'}${x.history ? '' : ' · zonder meethistorie'}${x.version ? ` · v${esc(x.version)}` : ''}${x.server ? ` · ${esc(x.server)}` : ''}${x.valid ? '' : ' · <b class="bad">beschadigd</b>'}</span></span>
      <button type="button" class="icon-btn" title="Downloaden als zip" data-bk="dl" data-file="${esc(x.file)}">⬇</button>
      <button type="button" class="icon-btn" title="Terugzetten" data-bk="restore" data-file="${esc(x.file)}" ${x.valid ? '' : 'disabled'}>↺</button>
      <button type="button" class="icon-btn" title="Verwijderen" data-bk="del" data-file="${esc(x.file)}">🗑</button>
    </div>`).join('') : '<p class="muted small">Nog geen back-ups.</p>';
}

async function backupNow(e) {
  const b = e.currentTarget;
  b.disabled = true;
  b.textContent = '⏳ Bezig…';
  try {
    const r = await api('backup_create', {});
    toast(`Back-up gemaakt (${fmtSize(r.size)})`);
  } catch (ex) { toast('Back-up: ' + ex.message, 'err'); }
  b.disabled = false;
  b.textContent = '💾 Nu back-up maken';
  loadBackups();
}

async function onBackupListClick(e) {
  const b = e.target.closest('[data-bk]');
  if (!b) return;
  const file = b.dataset.file;
  if (b.dataset.bk === 'dl') backupDownload(file);
  else if (b.dataset.bk === 'restore') backupRestore(file);
  else if (b.dataset.bk === 'del') {
    if (!confirm('Deze back-up verwijderen?')) return;
    try { await api('backup_delete', { file }); toast('Back-up verwijderd'); }
    catch (ex) { toast(ex.message, 'err'); }
    loadBackups();
  }
}

function backupDownload(file) {
  const go = () => {
    const a = document.createElement('a');
    a.href = 'api.php?' + new URLSearchParams({ action: 'backup_download', file });
    a.download = file;
    document.body.append(a);
    a.click();
    a.remove();
  };
  if (Date.now() < backupUnlockedUntil) return go();
  modal({
    title: '⬇ Back-up downloaden',
    body: `<p style="margin-top:0">De zip bevat ook <code>config.php</code> met het databasewachtwoord. Bevestig daarom eerst; daarna kun je 10 minuten downloaden zonder opnieuw te bevestigen.</p>
      ${backupInfo?.has_pass ? '' : '<p class="callout small">⚠️ Deze back-up is niet versleuteld. Stel een zip-wachtwoord in voor de volgende back-ups.</p>'}
      ${confirmField('DOWNLOADEN')}`,
    buttons: [{ label: '⬇ Downloaden', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('backup_unlock', { confirm: d.confirm });
      backupUnlockedUntil = Date.now() + r.unlocked * 1000 - 5000;
      setTimeout(go, 50);
    },
  });
}

function backupRestore(file) {
  const x = (backupInfo?.list || []).find((b) => b.file === file);
  if (!x) return;
  modal({
    title: '↺ Back-up terugzetten',
    body: `<p style="margin-top:0">De database gaat terug naar <b>${fmtDateTime(x.at)}</b>${x.server ? ` (server ${esc(x.server)})` : ''}.
        Wat daarna is veranderd aan servers, checks, instellingen en gebruikers gaat verloren.</p>
      <ul class="small">
        <li>RzMon maakt eerst een <b>veiligheidsback-up</b> van nu. Daarmee kun je altijd terug.</li>
        <li>Het back-upschema en het zip-wachtwoord blijven zoals ze nu zijn.</li>
        ${x.history ? '' : '<li>Deze back-up is zonder meethistorie: de huidige grafieken blijven staan.</li>'}
        <li>Gebruikers en wachtwoorden worden die uit de back-up; misschien moet je opnieuw inloggen.</li>
        <li><code>config.php</code> wordt hier niet teruggezet. Voor een nieuwe server: zie de handleiding.</li>
      </ul>
      ${x.encrypted ? '<label>Wachtwoord van deze zip <span class="hint">(leeg = het ingestelde zip-wachtwoord)</span><input name="zip_pass" type="password" autocomplete="off"></label>' : ''}
      ${confirmField('HERSTELLEN')}`,
    buttons: [{ label: '↺ Terugzetten', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('backup_restore', { ...d, file });
      setTimeout(() => modal({
        title: '✔ Back-up teruggezet',
        body: `<p style="margin-top:0">De database is teruggezet.${r.safety ? ` De toestand van daarvoor staat in de veiligheidsback-up <code>${esc(r.safety)}</code>.` : ''}</p>
          <p class="muted small">De pagina wordt opnieuw geladen.</p>`,
        buttons: [{ label: 'OK', cls: 'primary', submit: true }],
        onSubmit: async () => location.reload(),
      }), 50);
    },
  });
}

/** Zip uploaden in stukken van 1 MB (dan maakt de upload-limiet van PHP niet uit). */
async function backupUpload(file) {
  if (!file) return;
  const btn = $('#backup-upload');
  const CHUNK = 1024 * 1024;
  if (file.size > (backupInfo?.max_upload || Infinity)) { toast('Bestand is te groot (maximaal 2 GB)', 'err'); return; }
  const id = [...crypto.getRandomValues(new Uint8Array(8))].map((v) => v.toString(16).padStart(2, '0')).join('');
  btn.disabled = true;
  try {
    let r = null;
    for (let off = 0; ; off += CHUNK) {
      const final = off + CHUNK >= file.size;
      btn.textContent = `⬆ ${Math.round((Math.min(off + CHUNK, file.size) / (file.size || 1)) * 100)}%`;
      const res = await fetch('api.php?' + new URLSearchParams({ action: 'backup_upload', id, offset: off, final: final ? '1' : '0' }), {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': CSRF },
        body: file.slice(off, off + CHUNK),
        credentials: 'same-origin',
      });
      r = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
      if (!res.ok) throw new Error(r.error || `HTTP ${res.status}`);
      if (final) break;
    }
    toast('Zip geüpload');
    await loadBackups();
    backupRestore(r.file);
  } catch (ex) { toast('Upload: ' + ex.message, 'err'); }
  btn.disabled = false;
  btn.textContent = '⬆ Zip uploaden';
  $('#backup-file').value = '';
}
