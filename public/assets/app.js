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
  command: { label: 'Commando', target: null, unit: null, help: 'De agent voert een commando uit op de server, en RzMon vergelijkt de uitvoer met het antwoord dat je verwacht. Alleen als commando\'s voor deze server aan staan (Instellingen → ⬆ Agents bijwerken).' },
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
  ha_power: { label: '🏠 HA OS: te lage voeding (Raspberry Pi)', src: 'ha:host', warn: 1, crit: 5, pat: 'under-voltage|Undervoltage detected|voltage normalised' },
  ha_disk:  { label: '🏠 HA OS: diskfouten (SD-kaart/SSD)', src: 'ha:host', warn: 1, crit: 5, pat: 'I/O error|mmc[0-9].*(error|timeout)|EXT4-fs error|blk_update_request' },
  ha_core:  { label: '🏠 Home Assistant Core: fouten', src: 'ha:core', warn: 5, crit: 20, pat: ' ERROR | CRITICAL ' },
  ha_sup:   { label: '🏠 Supervisor: fouten', src: 'ha:supervisor', warn: 1, crit: 5, pat: ' ERROR | CRITICAL ' },
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
  reboot:       { label: 'Onverwachte herstart (HA OS)', unit: null, help: 'CRITICAL (een uur lang) als Home Assistant OS opnieuw is opgestart zonder OS-update: stroomuitval, crash of een handmatige herstart. Een herstart door een OS-update wordt alleen vermeld.' },
  disklife:     { label: 'Levensduur SD-kaart / eMMC', unit: '%', warn: 75, crit: 90, help: 'Hoeveel procent van de levensduur van de opslag verbruikt is. Alleen als de hardware dat meldt (eMMC, sommige SD-kaarten en SSD\'s).' },
  osupdate:     { label: 'OS-update mislukt (A/B-terugval)', unit: null, help: 'CRITICAL als een OS-update niet wilde opstarten en Home Assistant OS is teruggevallen op de vorige versie.' },
  usb:          { label: 'USB-apparaat aanwezig', unit: null, target: 'USB-apparaat', ph: 'kies uit de lijst', help: 'CRITICAL als het apparaat (Zigbee- of Z-Wave-stick, Bluetooth) niet meer gezien wordt door Home Assistant OS.' },
};
/** Home Assistant-add-on? (meldt ha:-gegevens of de Supervisor) */
function isHaHost(h) { return h.agent_update_via === 'ha' || h.metrics.some((m) => m.startsWith('ha:') || m === 'service:supervisor'); }
/** Type-beschrijving van een check (container: één check met status + resources, geen eigen eenheid). */
function typeOf(c) {
  if (c.type === 'ha') return { ...TYPES.ha, ...(HA_METRICS[c.metric] || {}), label: 'Home Assistant' };
  return c.type === 'container' && c.metric ? { ...TYPES.container, ...METRICS[c.metric], label: TYPES.container.label } : TYPES[c.type];
}

const MODES = { local: 'Lokaal (deze server)', agent: 'Remote met agent', remote: 'Remote (alleen netwerk)' };

let state = { hosts: [], alerts: [] };
// Omgekeerde status (zie Checks::REVERSIBLE)
const REVERSIBLE = ['ping', 'tcp', 'http', 'service', 'container', 'podman', 'command', 'ha'];
const REV_OK = [[0, 'OK (normaal)'], [1, '🟠 WARNING (omgekeerd)'], [2, '🔴 CRITICAL (omgekeerd)']];
const REV_FAIL = [[0, '✅ OK'], [1, '🟠 WARNING'], [2, '🔴 CRITICAL']];
const STATUS_WORD = ['OK', 'WARNING', 'CRITICAL', 'onbekend'];

let stats = { uptime: {}, sparks: {} };
// Sorteren op de pagina Meldingen (per apparaat onthouden): { key: 'time'|'status'|'server'|'check'|'message'|'channels', dir: 1|-1 }
const ALERT_SORTS = { time: 'Tijd', status: 'Status', server: 'Server', check: 'Check', message: 'Bericht', channels: 'Kanalen' };
// IDS: tabblad, en Toegang (gegevens, filters, sorteren, open dagen)
let idsTab = (() => { try { return localStorage.getItem('rzmon-ids-tab') || 'inc'; } catch (e) { return 'inc'; } })();
let accData = null, accFilter = 'all', accHost = '', accDayOpen = {};
let accSort = (() => { try { return JSON.parse(localStorage.getItem('rzmon-acc-sort') || 'null') || { key: '', dir: 1 }; } catch (e) { return { key: '', dir: 1 }; } })();
// Checks onder een server in groepen (vaste volgorde); dichtgeklapte groepen per server per apparaat onthouden
const CHECK_GROUPS = [
  ['sys', '📊 Systeem', ['cpu', 'ram', 'disk']],
  ['net', '🌐 Bereikbaarheid', ['ping', 'tcp', 'http']],
  ['svc', '⚙️ Services', ['service']],
  ['cnt', '📦 Containers', ['podman', 'container']],
  ['cmd', "🖥️ Commando's", ['command']],
  ['log', '📜 Logbestanden', ['log']],
  ['ssl', '🔒 Certificaten', ['ssl']],
  ['ha', '🏠 Home Assistant', ['ha']],
];
const GROUP_MIN = 5;   // pas kopjes vanaf zoveel checks; daaronder alleen de volgorde
let grpClosed = (() => { try { return JSON.parse(localStorage.getItem('rzmon-grp-closed') || '{}') || {}; } catch (e) { return {}; } })();
let alertSort = (() => { try { return JSON.parse(localStorage.getItem('rzmon-alert-sort') || 'null') || { key: '', dir: 1 }; } catch (e) { return { key: '', dir: 1 }; } })();
// Instellingen in L1nes (zie applySettingsLayout); hier bovenaan omdat de weergave al bij het opstarten wordt toegepast
const SET_GROUPS = { pushover: 'meldingen', notify: 'meldingen', email: 'meldingen', webhook: 'meldingen', mqtt: 'meldingen', alarm: 'monitoring',
  ids: 'monitoring', update: 'systeem', agents: 'systeem', backup: 'systeem', db: 'systeem', access: 'toegang', users: 'toegang', api: 'toegang', account: 'toegang', password: 'toegang' };
let setOpen = (() => { try { return JSON.parse(localStorage.getItem('rzmon-set-open') || '{}') || {}; } catch (e) { return {}; } })();
function saveSetOpen() { try { localStorage.setItem('rzmon-set-open', JSON.stringify(setOpen)); } catch (e) {} }
const setCards = () => $$('#tab-settings [data-sk]');
/** Zichtbaar binnen de instellingen (niet verborgen door rechten of hidden), ook als het tabblad zelf dicht is. */
function setVisible(el) {
  for (let n = el; n && n.id !== 'tab-settings'; n = n.parentElement) {
    if (n.hidden || getComputedStyle(n).display === 'none') return false;
  }
  return true;
}

let settingsLoaded = false;   // samenvattingen pas tonen als de instellingen geladen zijn
let updateInfo = null;        // laatste update_info (voor de samenvatting in L1nes)
let alertData = null;         // meldingen van de bewaartermijn (pagina Meldingen)
const alertDayOpen = {};      // datum → open/dicht (anders: alleen vandaag open)
let idsData = null;           // pagina 🛡️ IDS
let idsFilter = 'open';       // open | all
const idsDayOpen = {};

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
  $('#ids-enabled').onchange = async (e) => {
    try { await api('ids_set', { enabled: e.target.checked }); toast(e.target.checked ? 'IDS aangezet' : 'IDS uitgezet'); }
    catch (ex) { toast(ex.message, 'err'); e.target.checked = !e.target.checked; }
    idsModeInfo();
  };
  $('#ids-mode').onchange = (e) => {
    const block = e.target.checked;
    e.target.checked = !block;   // pas omzetten als het gelukt is
    if (!block) {
      api('ids_set', { mode: 'learn' }).then(() => { $('#ids-mode').checked = false; idsModeInfo(); toast('IDS: leren (alleen melden)'); }).catch((ex) => toast(ex.message, 'err'));
      return;
    }
    modal({
      title: '⛔ IDS: blokkeren aanzetten',
      body: `<p style="margin-top:0">RzMon gaat dan echt IP-adressen blokkeren op je servers (eigen nftables-tabel van de agent), bij brute force en als jij op ⛔ klikt.
          Je LAN, RzMon zelf en de lijst <i>Nooit blokkeren</i> worden nooit automatisch geblokkeerd; op TrueNAS wordt alleen gedetecteerd.</p>
        <p class="muted small">Op een server zelf zet je blokkeren altijd uit met <code>IDS_BLOCK=0</code> in de instellingen van de agent. Doe het eerste gebruik bij voorkeur terwijl je ook via de console kunt inloggen. Deze wijziging komt in het logboek.</p>
        ${confirmField('BLOKKEREN')}`,
      buttons: [{ label: 'Blokkeren aanzetten', cls: 'primary', submit: true }],
      onSubmit: async (d) => {
        await api('ids_set', { mode: 'block', confirm: d.confirm });
        toast('IDS: blokkeren staat aan');
        setTimeout(() => { showTab('settings'); }, 50);
      },
    });
  };
  $('#agent-commands').onchange = (e) => {
    const sel = e.target;
    setAgentCommands(null, sel.value, sel.dataset.current || 'off', 'alle agents', () => { showTab('settings'); loadAgentCommands(); });
  };
  $('#ids-filter').onclick = (e) => {
    const b = e.target.closest('[data-if]');
    if (!b) return;
    idsFilter = b.dataset.if;
    $$('#ids-filter .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    renderIds();
  };
  $('#ids-toggle').onclick = () => {
    const days = $$('#ids-incidents .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-block-add').onclick = () => idsBlockDialog();
  $('#ids-tabs').onclick = (e) => {
    const b = e.target.closest('[data-it]');
    if (!b) return;
    idsTab = b.dataset.it;
    try { localStorage.setItem('rzmon-ids-tab', idsTab); } catch (ex) { /* alleen voor nu */ }
    showIdsTab();
  };
  $('#acc-filter').onclick = (e) => {
    const b = e.target.closest('[data-ac]');
    if (!b) return;
    accFilter = b.dataset.ac;
    $$('#acc-filter .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    renderAccess();
  };
  $('#acc-host').onchange = (e) => { accHost = e.target.value; renderAccess(); };
  $('#acc-toggle').onclick = () => {
    const days = $$('#ids-access .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-access').addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sort]');
    if (th) {
      const k = th.dataset.sort;
      accSort = accSort.key !== k ? { key: k, dir: 1 } : accSort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 };
      try { localStorage.setItem('rzmon-acc-sort', JSON.stringify(accSort)); } catch (ex) { /* alleen voor nu */ }
      return renderAccess();
    }
    const b = e.target.closest('[data-acc]');
    if (!b) return;
    const r = accData.rows.find((x) => +x.id === +b.dataset.id);
    if (!r) return;
    if (b.dataset.acc === 'block') return idsBlockDialog(accDet(r).ip, r.host_id, `${r.type === 'ssh' ? 'SSH' : r.type}: ${r.kind === 'authfail' ? 'mislukte pogingen' : 'login'}`);
    if (b.dataset.acc === 'ignore') accIgnoreDialog(r);
  });
  $('#tab-ids').addEventListener('click', async (e) => {
    const go = e.target.closest('[data-goto]');
    if (go) { e.preventDefault(); showTab(go.dataset.goto); setTimeout(() => $('#ids-card').scrollIntoView({ behavior: 'smooth' }), 400); return; }
    const b = e.target.closest('[data-ids], [data-unblock], [data-rebase]');
    if (!b) return;
    try {
      if (b.dataset.ids === 'block') return idsBlockDialog(b.dataset.ip, b.dataset.host);
      if (b.dataset.ids) await api('ids_incident', { id: +b.dataset.id, action: b.dataset.ids });
      else if (b.dataset.unblock) {
        if (!confirm('Deze blokkade opheffen?')) return;
        await api('ids_unblock', { id: +b.dataset.unblock });
        toast('Gedeblokkeerd; de agent past het binnen een minuut aan');
      } else if (b.dataset.rebase) {
        if (!confirm('Basismeting opnieuw doen? Wat er nu op de server staat wordt als normaal vastgelegd; tot het einde van de basismeting worden afwijkingen niet gemeld.')) return;
        await api('ids_rebaseline', { host_id: +b.dataset.rebase });
      }
      loadIds();
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#alerts-toggle').onclick = () => {
    const days = $$('#alerts .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });   // het toggle-event bouwt de inhoud op en onthoudt de stand
  };
  $('#alerts').addEventListener('click', (e) => {
    // Kolomkop: sorteren (oplopend → aflopend → standaard)
    const th = e.target.closest('th[data-sort]');
    if (th) {
      const k = th.dataset.sort;
      alertSort = alertSort.key !== k ? { key: k, dir: 1 } : alertSort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 };
      return saveAlertSort();
    }
    // Small: klik op het bericht klapt het hele bericht uit
    const td = e.target.closest('.alert-table.small .at-msg');
    if (td) return td.parentElement.classList.toggle('at-open');
    // Anders: popup van de check
    const row = e.target.closest('[data-cid]');
    if (row && !e.target.closest('button, a')) checkInfo(row.dataset.cid);
  });
  $('#alerts-sort').onchange = (e) => {
    const [key, dir] = e.target.value.split(':');
    alertSort = { key, dir: +dir || 1 };
    saveAlertSort();
  };
  $('#tab-settings').addEventListener('click', (e) => {
    const h = e.target.closest('.set-l1nes [data-sk] > h2');
    if (h && !e.target.closest('a, button, input, label')) toggleSetting(h.parentElement);
  });
  $('#tab-settings').addEventListener('keydown', (e) => {
    const h = e.target.closest('.set-l1nes [data-sk] > h2');
    if (h && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleSetting(h.parentElement); }
  });
  $('#set-toggle-all').onclick = () => {
    const shown = setCards().filter(setVisible);
    const all = shown.every((c) => c.classList.contains('set-open'));
    shown.forEach((c) => (setOpen[c.dataset.sk] = !all));
    saveSetOpen();
    applySettingsLayout();
  };
  $('#settings-form').addEventListener('input', (e) => { if (!e.target.closest('[data-nodirty]')) markSettingsDirty(true); settingsSummaries(); });
  $('#settings-form').addEventListener('change', (e) => { if (!e.target.closest('[data-nodirty]')) markSettingsDirty(true); settingsSummaries(); });
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
  $('#api-keys').addEventListener('click', apiKeyClick);
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
  if (name === 'alerts') loadAlerts();
  if (name === 'ids') loadIds();
}

async function refresh() {
  try {
    state = await api('state');
    if (state.role && document.body.dataset.role !== state.role) location.reload();   // rol gewijzigd
    render();
    if (!$('#tab-alerts').hidden) loadAlerts();
    if (!$('#tab-ids').hidden) loadIds();
    $('#ids-badge').hidden = !state.ids_open;
    $('#ids-badge').textContent = state.ids_open || '';
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
  applySettingsLayout();
  if (alertData) renderAlerts();
}

// ---------------------------------------------------------------- Instellingen in L1nes
// Blox: kaarten naast elkaar (zoals altijd). L1nes: rijen over de volle breedte in groepen, open te klappen;
// Small = standaard dicht, Large = standaard open. Wat je open- of dichtklapt, wordt per apparaat onthouden.
function applySettingsLayout() {
  const { layout, density } = viewPrefs();
  const lines = layout === 'l1nes';
  const sec = $('#tab-settings');
  if (!sec) return;
  sec.classList.toggle('set-l1nes', lines);
  setCards().forEach((card) => {
    const h = $('h2', card);
    if (!$('.set-sum', h)) h.insertAdjacentHTML('beforeend', '<span class="set-sum"></span>');
    const open = !lines || (setOpen[card.dataset.sk] ?? density === 'large');
    card.classList.toggle('set-open', open);
    if (lines) { h.setAttribute('role', 'button'); h.tabIndex = 0; h.setAttribute('aria-expanded', open); }
    else { h.removeAttribute('role'); h.removeAttribute('tabindex'); h.removeAttribute('aria-expanded'); }
  });
  // Een groep zonder zichtbare onderdelen (bv. voor een gebruiker zonder beheerrechten) niet tonen
  $$('.set-group', sec).forEach((g) => {
    g.hidden = !setCards().some((c) => SET_GROUPS[c.dataset.sk] === g.dataset.group && setVisible(c));
  });
  const shown = setCards().filter(setVisible);
  $('#set-toggle-all').textContent = shown.length && shown.every((c) => c.classList.contains('set-open')) ? '⊟ Alles sluiten' : '⊞ Alles openen';
  settingsSummaries();
}

function toggleSetting(card) {
  setOpen[card.dataset.sk] = !card.classList.contains('set-open');
  saveSetOpen();
  applySettingsLayout();
}

/** Korte samenvatting achter elke titel in L1nes, uit wat er nu in het formulier staat. */
function settingsSummaries() {
  const f = $('#settings-form');
  if (!settingsLoaded || !f || !$('#tab-settings').classList.contains('set-l1nes')) return;
  const on = (x) => `<span class="good-txt">✔ aan</span>${x ? ' · ' + x : ''}`;
  const off = '<span class="muted">✘ uit</span>';
  const opt = (sel) => (sel && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent.trim() : '');
  const when = (r) => (!r ? '?' : r.mode === 'never' ? 'nooit' : r.mode === 'now' ? 'direct' : `na ${r.min} min`);
  const txt = (sel) => ($(sel) ? $(sel).textContent.replace(/\s+/g, ' ').trim() : '');
  const sum = {};
  if (isAdmin()) {
    sum.pushover = f.pushover_enabled.checked ? on(`critical: ${opt(f.pushover_priority_crit)} · warning: ${opt(f.pushover_priority_warn)}`) : off;
    if (notifyGlobal) {
      const n = notifyGlobal.get(), q = quietGlobal ? quietGlobal.get() : null;
      sum.notify = esc([`warning: ${when(n.rules.warn)}`, `critical: ${when(n.rules.crit)}`, n.rules.crit && n.rules.crit.repeat ? `herhalen elke ${n.rules.crit.repeat} min` : '',
        q && q.on && q.windows.length ? `🌙 ${quietText(q.windows)}` : '', n.flap.on ? 'anti-flapping' : ''].filter(Boolean).join(' · '));
    }
    sum.email = f.email_enabled.checked ? on(esc(f.mail_to.value)) : off;
    sum.webhook = f.ha_webhook_enabled.checked ? on('') : off;
    sum.mqtt = f.mqtt_enabled.checked ? on(esc(txt('#mqtt-status').replace(/^✔ /, ''))) : off;
    sum.ids = !$('#ids-enabled').checked ? '<span class="muted">✘ uit</span>'
      : $('#ids-mode').checked ? '<span class="warn-txt">⛔ blokkeren</span>' : '<span class="good-txt">✔ aan</span> · leren (alleen melden)';
    sum.ids += f.auth_events.checked ? ' · 🔑 toegang' : '';
    if (fimGlobal && fimGlobal.get().length) sum.ids += ` · 📁 ${fimGlobal.get().length} pad${fimGlobal.get().length === 1 ? '' : 'en'}`;
    sum.alarm = esc(`agent offline na ${f.agent_stale_minutes.value} min · historie ${f.retention_days.value} dagen · meldingen ${f.alerts_days.value} dagen`);
    if (updateInfo) {
      sum.update = (updateInfo.available ? `<span class="warn-txt">⬆ ${esc(updateInfo.latest)} beschikbaar</span>` : `✔ ${esc(updateInfo.current)}${updateInfo.latest ? ' · nieuwste versie' : ''}`)
        + (f.update_auto.checked ? ` · automatisch om ${esc(f.update_time.value)}` : '');
    }
    sum.agents = esc(txt('#agents-status')) + ($('#agent-commands').dataset.current && $('#agent-commands').dataset.current !== 'off'
      ? ` · commando's ${esc(CMD_MODES[$('#agent-commands').dataset.current])}` : '');
    if (backupInfo && !backupInfo.problem) {
      const last = backupInfo.list[0];
      sum.backup = esc(f.backup_freq.value === 'off' ? 'automatisch uit' : `${opt(f.backup_freq).toLowerCase()} om ${f.backup_time.value}`)
        + (last ? ` · laatste: ${ago(last.at)}` : '') + (backupInfo.last_error ? ' · <span class="warn-txt">✘ mislukt</span>' : '');
    } else if (backupInfo) sum.backup = '<span class="warn-txt">⚠️ niet ingesteld</span>';
    sum.db = esc(txt('#db-status').split(' · ')[0]);
    const af = $('#access-form');
    sum.access = af.lan_no_login.checked ? `LAN zonder inloggen${af.lan_as_admin.checked ? ' (als beheerder)' : ''}` : 'altijd inloggen';
    const nKeys = $$('#api-keys .key-row').length;
    sum.api = `${nKeys} sleutel${nKeys === 1 ? '' : 's'}`;
  }
  if (Array.isArray(users)) sum.users = `${users.length} gebruiker${users.length === 1 ? '' : 's'}`;
  setCards().forEach((card) => { const el = $('.set-sum', card); if (el) el.innerHTML = sum[card.dataset.sk] || ''; });
}

function idsModeInfo() {
  const on = $('#ids-enabled').checked, block = $('#ids-mode').checked;
  $('#ids-mode').disabled = !on;
  $('#ids-l-learn').classList.toggle('active', !block);
  $('#ids-l-block').classList.toggle('active', block);
  $('#ids-mode-info').innerHTML = !on ? 'IDS staat uit: de agents verzamelen niets en er wordt niets geblokkeerd.'
    : block ? '<b class="warn-txt">Blokkeren:</b> brute force wordt gemeld <b>en</b> geblokkeerd; met ⛔ blokkeer je zelf een IP.'
    : '<b>Leren:</b> alles wordt gemeld, er wordt niets geblokkeerd.';
}

let settingsDirty = false;
function markSettingsDirty(dirty) {
  settingsDirty = dirty;
  $('#save-state').textContent = dirty ? '● niet opgeslagen wijzigingen' : '';
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
 * Uitgeklapt deel: knoppen en alle checks, in groepen (Systeem, Bereikbaarheid, Services…). Blox: checks als
 * tegels. L1nes (lines = 'small' of 'large'): checks als rijen, net als de servers.
 */
/**
 * Checks van een server in groepen: [[sleutel, titel, checks], …]. Binnen een groep problemen eerst
 * (kritiek → waarschuwing → onbekend → OK → onderhoud → uitgeschakeld), daarna (bij Systeem: CPU, RAM, disks) op naam.
 */
function groupChecks(checks) {
  const rank = (c) => (+c.enabled ? STATUS_RANK[c.maintenance ? 4 : +c.status] : -1);
  const name = (c) => (c.type === 'disk' ? `disk ${c.target}` : c.name);
  const out = CHECK_GROUPS.map(([k, title, types]) => [k, title, checks.filter((c) => types.includes(c.type))
    .sort((a, b) => rank(b) - rank(a) || types.indexOf(a.type) - types.indexOf(b.type)
      || name(a).localeCompare(name(b), 'nl', { numeric: true, sensitivity: 'base' }))]);
  const known = CHECK_GROUPS.flatMap((g) => g[2]);
  const other = checks.filter((c) => !known.includes(c.type));
  if (other.length) out.push(['other', 'Overig', other]);
  return out.filter((g) => g[2].length);
}

/** Kop van een groep: titel, aantal en de statussen die aandacht vragen */
function groupHead(h, k, title, list, tag) {
  const n = { 2: 0, 1: 0, 3: 0 };
  list.forEach((c) => { if (+c.enabled && !c.maintenance && n[+c.status] !== undefined) n[+c.status]++; });
  const sum = [[2, '🔴'], [1, '🟠'], [3, '⚪']].filter(([s]) => n[s]).map(([s, i]) => `<span class="ad-count">${i} ${n[s]}</span>`).join('') || '<span class="ad-count">✅</span>';
  const closed = !!grpClosed[`${h.id}:${k}`];
  return `<${tag} class="grp-head" data-act="grp" data-id="${h.id}" data-g="${k}" role="button" tabindex="0" aria-expanded="${!closed}">
    <span class="grp-chev">${closed ? '▸' : '▾'}</span><span class="grp-title">${title}</span>
    <span class="grp-sum"><span class="muted small">${list.length}</span>${sum}</span></${tag}>`;
}

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
      ${lines ? checkLines(h, lines === 'large') : checkTiles(h)}
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
    + (c.reverse_ok != null && REVERSIBLE.includes(c.type) ? ` <span class="rev-tag" title="Omgekeerd: reageert het, dan ${STATUS_WORD[c.reverse_ok]}; reageert het niet, dan ${STATUS_WORD[c.reverse_fail ?? 0]}">⇄</span>` : '')
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
  <li class="chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
    <span class="dot"></span>
    <span class="chk-name">${esc(c.name)}<span class="type">${b.t.label}${b.num ? ` · ${b.num}` : ''}</span>${b.flags}</span>
    <span class="chk-actions">${b.actions}
    </span>
    ${bar}
    ${sparkSvg(c)}
    <span class="chk-msg">${b.msg} · ${ago(c.last_checked)}</span>
  </li>`;
}

// Blox: checks als tegels, in groepen (zie groupChecks)
function checkTiles(h) {
  if (!h.shown.length) return '<ul class="checks"><li class="empty small">Nog geen checks — klik op ＋ Check</li></ul>';
  const groups = groupChecks(h.shown);
  if (h.shown.length < GROUP_MIN) return `<ul class="checks">${groups.flatMap((g) => g[2]).map(checkRow).join('')}</ul>`;
  return `<div class="chk-groups">${groups.map(([k, title, list]) => `
    <section class="chk-group${grpClosed[`${h.id}:${k}`] ? ' closed' : ''}">
      ${groupHead(h, k, title, list, 'div')}
      <ul class="checks">${list.map(checkRow).join('')}</ul>
    </section>`).join('')}</div>`;
}

// L1nes: checks als rijen onder de server, in dezelfde groepen
function checkLines(h, large) {
  if (!h.shown.length) return '<p class="empty small check-lines-empty">Nog geen checks — klik op ＋ Check</p>';
  const groups = groupChecks(h.shown);
  const head = large ? `<div class="cl-head" aria-hidden="true"><span></span><span>Check</span><span>Waarde</span><span class="cl-spark">24 uur</span>
    <span>Bericht</span><span class="cl-last">Laatst</span><span></span></div>` : '';
  const body = h.shown.length < GROUP_MIN ? groups.flatMap((g) => g[2]).map((c) => checkLine(c, large)).join('')
    : groups.map(([k, title, list]) => `<div class="cl-group${grpClosed[`${h.id}:${k}`] ? ' closed' : ''}">
        ${groupHead(h, k, title, list, 'div')}
        <div class="cl-group-body">${list.map((c) => checkLine(c, large)).join('')}</div></div>`).join('');
  return `<div class="check-lines ${large ? 'large' : 'small'}">${head}${body}</div>`;
}

function checkLine(c, large) {
  const b = checkBits(c);
  const value = b.pctVal !== null
    ? `<span class="cl-meter" title="${fmt(b.pctVal, '%')}"><span class="bar"><i style="width:${b.pctVal}%"></i>${b.ticks}</span><b>${Math.round(b.pctVal)}%</b></span>`
    : b.num ? `<span class="cl-num">${b.num}</span>` : '<span class="muted">–</span>';
  return `
  <div class="cl chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
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
  if (!b) {
    // Klik op een check (niet op een knop, link of de minigrafiek): popup met meer informatie
    const chk = e.target.closest('[data-cid]');
    if (chk && !e.target.closest('button, a, input, select, label')) checkInfo(chk.dataset.cid);
    return;
  }
  const id = b.dataset.id;
  switch (b.dataset.act) {
    case 'grp': {
      // Groep in- of uitklappen (zonder alles opnieuw op te bouwen)
      const key = `${id}:${b.dataset.g}`;
      if (grpClosed[key]) delete grpClosed[key]; else grpClosed[key] = true;
      try { localStorage.setItem('rzmon-grp-closed', JSON.stringify(grpClosed)); } catch (ex) { /* alleen voor nu */ }
      b.parentElement.classList.toggle('closed', !!grpClosed[key]);
      b.setAttribute('aria-expanded', String(!grpClosed[key]));
      $('.grp-chev', b).textContent = grpClosed[key] ? '▸' : '▾';
      return;
    }
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

// ---------------------------------------------------------------- Popup van een check
const ST_ICON = ['✅', '🟠', '🔴', '⚪'];
/** "37 min", "4 u 47 min", "3 d 2 u" */
function durText(sec) {
  sec = Math.max(0, Math.round(sec));
  if (sec < 60) return `${sec} s`;
  const m = Math.floor(sec / 60), h = Math.floor(m / 60), d = Math.floor(h / 24);
  return d ? `${d} d${h % 24 ? ` ${h % 24} u` : ''}` : h ? `${h} u${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`;
}
const fmtPct = (v) => `${(+v).toLocaleString('nl-NL', { maximumFractionDigits: 1 })}%`;
const toTime = (dt) => new Date(dt.replace(' ', 'T')).getTime();
/** Datum en tijd kort: "14:02" vandaag, anders "di 14 okt 14:02" */
function whenText(dt) {
  const d = new Date(dt.replace(' ', 'T'));
  const today = new Date().toDateString() === d.toDateString();
  return (today ? '' : d.toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' }) + ' ') + dt.slice(11, 16);
}

async function checkInfo(id) {
  let r;
  try { r = await api('check_info', null, { id }); } catch (ex) { return toast(ex.message, 'err'); }
  const c = r.check;
  const [sc, h] = findCheck(c.id);   // dashboardgegevens (onderhoud, bevestigd) als die er zijn
  const now = toTime(r.now);
  const t = TYPES[c.type] || { label: c.type };
  const st = +c.status;
  const problem = st !== 0 && +c.enabled;
  const unit = t.unit || '';
  const rows = [
    ['Type', `${esc(t.label)}${c.target ? ` · <code>${esc(c.target)}</code>` : ''}`],
    ['Interval', `${+c.interval_sec} s`],
    ...(unit && (c.warn_threshold !== null || c.crit_threshold !== null) ? [['Drempels', `${t.low ? 'onder ' : ''}${c.warn_threshold ?? '–'} / ${c.crit_threshold ?? '–'} ${esc(unit)}`]] : []),
    ['Laatste check', c.last_checked ? ago(c.last_checked) : 'nog niet'],
    ['Beschikbaar', r.uptime.d1 === null ? '<span class="muted">nog geen metingen</span>' : `24 u <b>${fmtPct(r.uptime.d1)}</b> · 7 d <b>${fmtPct(r.uptime.d7)}</b>`],
  ];
  const tags = [
    c.notify ? '🔔 eigen meldregels' : '🔔 algemene meldregels',
    (() => { const q = quietOf(c); return q ? (q.mode === 'never' ? '🌙✕ nooit stil' : '🌙 eigen stille uren') : '🌙 algemene stille uren'; })(),
    c.reverse_ok != null && REVERSIBLE.includes(c.type) ? `⇄ omgekeerd: slaagt = ${STATUS_WORD[c.reverse_ok]}, mislukt = ${STATUS_WORD[c.reverse_fail ?? 0]}` : '',
    sc && sc.maintenance ? `🔧 ${esc(maintLabel(sc.maintenance))}` : '',
    c.acked_at ? `✔ bevestigd door ${esc(c.acked_by || '?')}` : '',
    !+c.enabled ? '⏸ uitgeschakeld' : '',
  ].filter(Boolean);
  const changes = r.changes.map((ch, i) => {
    const sent = ch.alerts.filter((a) => a.kind !== 'ack');
    const acks = ch.alerts.filter((a) => a.kind === 'ack');
    const dur = ((ch.ended_at ? toTime(ch.ended_at) : now) - toTime(ch.created_at)) / 1000;
    const why = sent.length ? sent.map((a) => `📨 gemeld ${esc(whenText(a.created_at))} · ${channelIcons(a)}`).join('<br>')
      : ch.note ? `<span class="muted">niet gemeld: ${esc(ch.note)}</span>`
      : i === 0 && +ch.to_status !== 0 ? '<span class="muted">nog niet gemeld (wacht op de meldregel, of stille uren)</span>'
      : `<span class="muted">niet gemeld${+ch.to_status === 0 ? '' : ' (te kort, flapt, stille uren of de meldregel staat uit)'}</span>`;
    return `<li class="ci-change s${+ch.to_status}">
      <div class="ci-ch-head"><span class="ci-when" title="${esc(ch.created_at)}">${esc(whenText(ch.created_at))}</span>
        <b>${ST_ICON[+ch.from_status]} ${STATUS[+ch.from_status]} → ${ST_ICON[+ch.to_status]} ${STATUS[+ch.to_status]}</b>
        <span class="muted small">${ch.ended_at ? 'duurde' : 'duurt nu'} ${durText(dur)}</span></div>
      <div class="ci-msg">${esc(ch.message || '–')}</div>
      <div class="small">${why}${acks.length ? `<br>✔ bevestigd ${esc(whenText(acks[0].created_at))}` : ''}</div></li>`;
  }).join('');
  const since = c.status_since ? ` sinds ${esc(whenText(c.status_since))} (${durText((now - toTime(c.status_since)) / 1000)})` : '';
  const close = () => $('#modal').close();
  const btns = [
    ...(problem && !c.acked_at ? [{ label: '✔ Bevestigen', cls: 'need-user', onClick: () => ackDialog('check', c.id, `${c.host_name} – ${c.name}`, c.last_message) }] : []),
    { label: '🔧 Onderhoud', cls: 'need-user', onClick: () => maintenanceDialog('check', c.id, `${c.host_name} – ${c.name}`, sc && sc.maintenance && sc.maintenance.scope === 'check' ? sc.maintenance : null) },
    { label: '↻ Nu checken', cls: 'need-user', onClick: async (btn) => {
      btn.disabled = true;
      try { await api('check_run', { id: c.id }); await refresh(); checkInfo(c.id); } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
    } },
    ...(sc ? [{ label: '📈 Grafiek', onClick: () => { close(); showHistory(sc, h); } }] : []),
    ...(sc ? [{ label: '✎ Bewerken', cls: 'need-admin', onClick: () => checkForm(sc, h) }] : []),
  ];
  modal({
    title: `${ST_ICON[st]} ${c.name} – ${c.host_name}`,
    wide: true,
    body: `<div class="ci">
      <p class="ci-status s${st}"><b>${STATUS[st]}</b>${since}<br><span class="ci-msg">${esc(c.last_message || '–')}</span></p>
      <dl class="ci-grid">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
      <p class="small muted ci-tags">${tags.join(' · ')}</p>
      <h3 class="ci-h">Laatste 5 statuswijzigingen</h3>
      ${changes ? `<ol class="ci-changes">${changes}</ol>` : '<p class="muted small">Nog geen statuswijzigingen bewaard. Ze worden bijgehouden vanaf versie 1.37.0.</p>'}
    </div>`,
    buttons: btns,
  });
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
      <details class="notify-own" id="fim-host-wrap" ${h.mode === 'agent' ? '' : 'hidden'}>
        <summary>📁 Bestanden bewaken (IDS): <span id="fim-host-label">laden…</span></summary>
        <p class="hint" style="margin-top:0">Extra paden voor deze server, bovenop de algemene lijst (<i>Instellingen → 🛡️ IDS</i>). Hetzelfde pad hier gaat voor, bv. om de ernst te veranderen.</p>
        <div id="fim-host"></div>
      </details>
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
      const r = await api('host_save', { ...d, id: h.id, enabled: !!d.enabled, add_ping: !!d.add_ping, ...(fimHost ? { ids_fim: fimHost.get() } : {}) });
      toast('Server opgeslagen');
      if (isNew && d.mode === 'agent') {
        await refresh();
        setTimeout(() => agentInstructions(findHost(r.id)), 50);
      }
    },
  });
  hostFimInit(h);
}

/** Serverformulier: lijst "Bestanden bewaken" van deze server (alleen agent-servers, niet de HA-add-on) */
let fimHost = null;
async function hostFimInit(h) {
  fimHost = null;
  const wrap = $('#fim-host-wrap');
  const sel = $('#modal [name=mode]');
  const show = () => { wrap.hidden = sel.value !== 'agent'; };
  sel.addEventListener('change', show);
  show();
  let own = [], ha = false;
  if (h.id && isAdmin()) {
    try { const x = (await api('ids')).hosts.find((y) => +y.id === +h.id); if (x) { own = JSON.parse(x.ids_fim || '[]') || []; ha = !!x.ha; } } catch (e) { /* leeg beginnen */ }
  }
  if (!$('#fim-host')) return;   // formulier al dicht
  if (ha) { wrap.hidden = true; return; }
  const label = () => { const n = fimHost.get().length; $('#fim-host-label').textContent = n ? `${n} extra pad${n === 1 ? '' : 'en'}` : 'alleen de algemene lijst'; };
  fimHost = fimEditor($('#fim-host'), own, h.id || null, label);
  label();
  if (own.length) wrap.open = true;
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
  const cmdCfg = (() => { try { return JSON.parse(c.command || '{}') || {}; } catch (e) { return {}; } })();
  const allowed = h.mode === 'remote' ? ['ping', 'tcp', 'http', 'ssl']
    : Object.keys(TYPES).filter((k) => h.mode === 'agent' || !['podman', 'container', 'log', 'ha', 'command'].includes(k))
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
      <div id="cmd-wrap" hidden>
        <p class="hint" id="cmd-mode-info"></p>
        <label>Voorinstelling <select id="cmd-preset">${presetOptions('linux')}</select></label>
        <p class="hint" id="cmd-preset-info"></p>
        <label>Commando <textarea name="cmd" class="mono" rows="2" maxlength="1000" placeholder="bv. zpool status -x">${esc(cmdCfg.cmd || '')}</textarea></label>
        <div class="row2">
          <label>Goed als de uitvoer
            <select name="cmd_match">${Object.entries(CMD_MATCHES).map(([k, l]) => `<option value="${k}" ${(cmdCfg.match || 'equals') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
          </label>
          <label id="cmd-expect-wrap">Verwacht antwoord <textarea name="cmd_expect" class="mono" rows="1" maxlength="1000" placeholder="bv. all pools are healthy">${esc(cmdCfg.expect || '')}</textarea></label>
        </div>
        <div class="row2">
          <label>Klopt het niet, dan
            <select name="cmd_fail"><option value="crit" ${cmdCfg.fail !== 'warn' ? 'selected' : ''}>🔴 Critical</option><option value="warn" ${cmdCfg.fail === 'warn' ? 'selected' : ''}>🟠 Warning</option></select>
          </label>
          <label>Time-out <span class="hint">seconden (1–60)</span><input name="cmd_timeout" type="number" min="1" max="60" value="${cmdCfg.timeout || 10}"></label>
        </div>
        <p class="hint">Spaties en regeleindes aan begin en eind tellen niet mee. Regex zonder scheidingstekens, bv. <code>^ok\\b</code> of <code>temp=[0-5][0-9]</code>. Het commando draait als <code>nobody</code>, of als root als dat voor deze server is toegestaan, in <code>bash</code>, zonder invoer.</p>
      </div>
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
      <div id="rev-wrap" class="row2">
        <label>Status als de check slaagt
          <select name="reverse_ok">${REV_OK.map(([v, l]) => `<option value="${v}" ${+(c.reverse_ok ?? 0) === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        </label>
        <label id="rev-fail-wrap">Status als de check mislukt
          <select name="reverse_fail">${REV_FAIL.map(([v, l]) => `<option value="${v}" ${+(c.reverse_fail ?? 0) === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        </label>
        <span class="hint" id="rev-hint" style="grid-column:1/-1"></span>
      </div>
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
      const command = d.type === 'command' ? { cmd: d.cmd, expect: d.cmd_expect, match: d.cmd_match, timeout: d.cmd_timeout, fail: d.cmd_fail } : undefined;
      const res = {};
      ['cpu', 'mem', 'disk'].forEach((k) => { res[k] = { on: !!d[`res_${k}`], warn: d[`res_${k}_warn`], crit: d[`res_${k}_crit`] }; });
      await api('check_save', { ...d, res, notify, quiet, command, id: c.id, host_id: h.id, enabled: !!d.enabled, verify_ssl: !!d.verify_ssl, deep: !!d.deep });
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
  $('[name=reverse_ok]', form).onchange = () => update(false);
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
    // Omgekeerde status: alleen bij werkt-het-wel-of-niet-checks
    const rev = REVERSIBLE.includes(typeSel.value);
    $('#rev-wrap').hidden = !rev;
    $('[name=reverse_ok]', form).disabled = !rev;
    const revOn = rev && +$('[name=reverse_ok]', form).value > 0;
    $('#rev-fail-wrap').hidden = !revOn;
    $('[name=reverse_fail]', form).disabled = !revOn;
    $('#rev-hint').innerHTML = revOn ? '⇄ <b>Omgekeerd:</b> reageert het (ook met een waarschuwing), dan krijgt de check de eerste status; reageert het niet, de tweede. Handig voor iets dat juist níet mag draaien of bereikbaar mag zijn. Onbekend blijft onbekend.' : '';
    // Diepere controle en extra test: alleen services op agent-servers
    const deep = typeSel.value === 'service' && h.mode === 'agent';
    $('#deep-wrap').hidden = !deep;
    const tt = $('[name=test_type]', form).value;
    $('#test-target-wrap').hidden = !tt;
    $('#test-expect-wrap').hidden = tt !== 'url';
    $('[name=test_target]', form).placeholder = tt === 'port' ? '8080 of localhost:8080' : 'http://localhost:8080/health';
    $('[name=test_target]', form).required = deep && !!tt;
    $('[name=target]', form).required = !!t.target && !['http', 'ssl'].includes(typeSel.value);
    $('[name=target]', form).disabled = !t.target;   // niet meesturen als er geen doel is (bv. HA-checks zonder doel)
    const haLogs = ['ha:host', 'ha:core', 'ha:supervisor', ...svcs.filter((x) => x.startsWith('addon:')).map((x) => `ha:${x}`)];
    const usbs = h.metrics.filter((m) => m.startsWith('usb:')).map((m) => m.slice(4));
    const list = isHa && haSel.value === 'usb' ? usbs
      : { disk: disks, service: svcs, ssl: certs, container: containers, log: isHaHost(h) ? haLogs : LOG_SOURCES }[typeSel.value] || [];
    $('#log-wrap').hidden = typeSel.value !== 'log';
    const isCmd = typeSel.value === 'command';
    $('#cmd-wrap').hidden = !isCmd;
    $('[name=cmd]', form).required = isCmd;
    $('#cmd-expect-wrap').hidden = $('[name=cmd_match]', form).value === 'exit0';
    if (isCmd && !cmdInfoLoaded) {
      cmdInfoLoaded = true;
      api('agent_services', null, { id: h.id }).then((r) => {
        const { platform } = detectPlatform(r.list);
        const sel = $('#cmd-preset');
        if (sel) sel.innerHTML = presetOptions(platform, sel.value);
      }).catch(() => {});
      api('agent_commands').then((r) => {
        const m = (r.hosts.find((x) => x.id === +h.id) || {}).mode || 'off';
        cmdMode = m;
        $('#cmd-mode-info').innerHTML = m === 'off'
          ? `<b class="warn-txt">⚠️ Commando's staan voor ${esc(h.name)} uit.</b> De check blijft onbekend tot je ze aanzet: Instellingen → ⬆ Agents bijwerken, of per server in de Agent Manager.`
          : `Commando's op ${esc(h.name)}: <b>${esc(CMD_MODES[m])}</b>.`;
      }).catch(() => {});
    }
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
  let cmdInfoLoaded = false;
  let cmdMode = 'off';
  const presetInfo = () => {
    const p = CMD_PRESETS[$('#cmd-preset').value];
    $('#cmd-preset-info').innerHTML = !p ? '' : [
      p.root ? (cmdMode === 'root' ? 'Draait als root.' : `<b class="warn-txt">⚠️ Heeft root nodig</b>, en commando's op ${esc(h.name)} staan ${esc(CMD_MODES[cmdMode])}: zet ze op <i>als root</i> (Agent Manager), anders mislukt hij.`) : 'Werkt als nobody.',
      p.tested ? '' : '<span class="muted">Nog niet op een echt systeem getest; laat weten als hij niet goed uitkomt.</span>',
    ].filter(Boolean).join(' ');
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
  $('[name=cmd_match]', form).onchange = () => update(false);
  $('#cmd-preset').onchange = (e) => {
    const p = CMD_PRESETS[e.target.value];
    if (p) {
      const f = (n) => $(`[name=${n}]`, form);
      f('cmd').value = p.cmd; f('cmd_match').value = p.match; f('cmd_expect').value = p.expect; f('cmd_fail').value = p.fail;
      f('cmd_timeout').value = p.timeout; f('interval_sec').value = p.interval;
      if (!f('name').value || Object.values(CMD_PRESETS).some((x) => x.name === f('name').value)) f('name').value = p.name;
      update(false);
    }
    presetInfo();
  };
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
      <td class="num">${c.cpu !== null && c.cpu !== undefined ? (Math.round(c.cpu * 10) / 10).toString().replace('.', ',') + '%' : '–'}</td>
      <td class="num">${c.mem !== null ? fmtBytes(c.mem) : '–'}${memPct !== null ? `<br><span class="muted small">${memPct.toFixed(1).replace('.', ',')}% van ${fmtBytes(c.mem_limit)}</span>` : ''}</td>
      <td class="num">${fmtBytes(disk)}${c.volumes ? `<br><span class="muted small">volumes ${fmtBytes(c.volumes)}</span>` : ''}</td>
      <td class="pc-actions">${watch}</td>
    </tr>`;
  }).join('');
  const missing = d.containers.filter((c) => !checkOf(c.key));
  const ha = sm.version === 'Home Assistant';
  body.innerHTML = `
    <p class="scan-summary">${ha ? 'Home Assistant: Core, Supervisor, plugins en add-ons' : `Podman ${esc(sm.version || '?')}`} · ${d.containers.filter((c) => c.state === 'running').length} van ${d.containers.length} containers draaien
      ${(sm.pods || []).length ? ' · pods: ' + sm.pods.map((p) => `${esc(p.name)} (${esc(p.status)})`).join(', ') : ''}
      <br><span class="muted small">${ha ? 'Gestopte add-ons met exitcode 0 zijn bewust uitgezet; een add-on met een fout staat als gestopt (exit 1).'
        : `Images ${fmtBytes(sm.images_size)} · containers ${fmtBytes(sm.containers_size)} · volumes ${fmtBytes(sm.volumes_size)}
      ${sm.reclaimable ? ` · ${fmtBytes(sm.reclaimable)} op te ruimen (<code>podman system prune</code>)` : ''}`} · gemeld ${ago(d.updated_at)}
      ${(sm.users || []).length > 1 ? ' · gebruikers: ' + sm.users.map(esc).join(', ') : ''}</span></p>
    ${sm.error ? `<p class="form-error">Podman-fout: ${esc(sm.error)}</p>` : ''}
    <div class="podman-actions">
      ${d.checks.some((c) => c.type === 'podman') ? `<span class="muted small">✓ ${ha ? 'Containers' : 'Podman'}-check aanwezig</span>`
        : `<button type="button" class="btn need-admin" id="pc-overall">＋ ${ha ? 'Containers' : 'Podman'}-check (algehele status)</button>`}
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
/** Status van de realtime-watcher, voor de Agent Manager en de IDS-pagina */
function realtimeText(rt) {
  if (!rt || !rt.on) return '<span class="muted">⚡ Realtime: uit</span>';
  const s = rt.status;
  if (!s || s.state !== 'active') {
    return '<span class="warn-txt">⚡ Realtime: aan, maar de watcher is (nog) niet actief</span> <span class="muted">— agent 1.43.0 met systemd nodig; bijwerken met ⬆, of REALTIME=0 op de server</span>';
  }
  const parts = [s.journal === 'ok' ? 'systemlog ✔' : s.journal === 'supervisor' ? 'Supervisor ✔' : 'systemlog –',
    s.files === 'ok' ? 'bestanden ✔' : s.files === 'missing' ? 'bestanden: inotify-tools ontbreekt' : null].filter(Boolean);
  return `<span class="good">⚡ Realtime actief</span> <span class="muted">sinds ${new Date(s.since * 1000).toLocaleString('nl-NL', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })} · ${parts.join(' · ')}</span>`;
}

/**
 * Ontbreekt er iets voor bestanden in realtime (inotify-tools, op Rocky/Alma/RHEL via EPEL)? Melding met de oplossing,
 * of '' als alles er is. via: hoe de agent zichzelf bijwerkt (alleen dan helpt "Opnieuw installeren").
 */
function toolsWarning(t, via) {
  if (!t || t.inotify) return '';
  const fix = via === 'systemd' || via === 'direct' ? 'klik hierboven op <b>⬆ Opnieuw installeren</b> (de agent installeert het dan zelf, als de server internet heeft), of op de server:' : 'op de server:';
  if (t.family === 'rhel' && !t.epel) {
    return t.no_epel ? '<span class="muted">⚠️ EPEL staat uit (EPEL=0): bestanden worden niet in realtime gevolgd, alleen bij de gewone runs.</span>'
      : `<span class="warn-txt">⚠️ EPEL is niet geïnstalleerd:</span> bestanden worden niet in realtime gevolgd (logins en services wel). Oplossen: ${fix}
        <code>sudo dnf install epel-release &amp;&amp; sudo dnf install inotify-tools</code>`;
  }
  return `<span class="warn-txt">⚠️ inotify-tools ontbreekt:</span> bestanden worden niet in realtime gevolgd (logins en services wel). Oplossen: ${fix}
    <code>${t.family === 'rhel' ? 'sudo dnf install inotify-tools' : 'sudo apt install inotify-tools'}</code>`;
}

/** "30 s", "2 min" */
function tickText(t) { return +t >= 120 ? `${+t / 60} min` : `${+t} s`; }

function agentInstructions(h) {
  const base = state.base_url || location.origin + location.pathname.replace(/\/[^/]*$/, '');
  const scriptUrl = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api.php?action=agent_script';
  const installCmd = `curl -fsSL '${base}/api.php?action=agent_install' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}'`;
  const truenasCmd = `curl -fsSL '${base}/api.php?action=agent_install' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}' --truenas`;
  modal({
    title: `Agent Manager – ${h.name}`,
    wide: true,
    body: `
      <div class="agent-live" id="agent-live"><span class="muted">Status ophalen…</span></div>
      <div class="cmd-host-row need-admin">⏱ Rapporteren elke:
        <select id="host-tick"><option value="0">standaard (${tickText(state.agent_tick)})</option>${[30, 60, 120, 300].map((t) => `<option value="${t}" ${+h.agent_tick === t ? 'selected' : ''}>${tickText(t)}</option>`).join('')}</select>
        <span class="muted small" id="host-tick-info">${h.agent_update_via === 'direct' ? 'via cron/TrueNAS: minimaal elke minuut' : ''}</span></div>
      <div class="cmd-host-row need-admin">⚡ Realtime:
        <select id="host-realtime"><option value="0" ${+h.realtime ? '' : 'selected'}>uit</option><option value="1" ${+h.realtime ? 'selected' : ''}>aan</option></select>
        <span class="muted small" id="host-realtime-info">${h.agent_update_via === 'direct' ? 'werkt alleen met systemd (niet via cron of op TrueNAS)' : 'logins, sudo, services en 🔴-bestanden meteen'}</span></div>
      <div class="cmd-host-row need-admin">🖥️ Commando-checks op deze server:
        <select id="host-commands"><option value="global">algemene instelling</option>${Object.entries(CMD_MODES).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
        <span class="muted small" id="host-commands-info"></span></div>
      <div class="seg" role="tablist">
        <button type="button" class="seg-btn active" data-seg="linux">🐧 Linux-server</button>
        <button type="button" class="seg-btn" data-seg="ha">🏠 Home Assistant OS</button>
        <button type="button" class="seg-btn" data-seg="truenas">🗄️ TrueNAS SCALE</button>
      </div>
      <div data-pane="truenas" hidden>
        <p>Op TrueNAS SCALE horen <code>/usr</code> en <code>/etc</code> bij TrueNAS zelf. De agent komt daarom op je <b>pool</b>
          (<code>/mnt/&lt;pool&gt;/rzmon-agent</code>), en draait via een <b>cronjob van TrueNAS</b> die ook na een TrueNAS-update blijft staan.</p>
        <ol class="steps">
          <li>Open in TrueNAS <i>System → Shell</i> (of log in met SSH als beheerder).</li>
          <li>Voer dit uit:
            <div class="copy-line"><pre class="code" id="truenas-oneliner">${esc(truenasCmd)}</pre>
              <button type="button" class="btn" id="copy-truenas">Kopieer</button></div></li>
        </ol>
        <p class="muted small">De eerste pool wordt gekozen (niet <code>boot-pool</code>); een andere kies je met <code>--pool naam</code>. Het script zet de agent en de instellingen op de pool,
          maakt de cronjob <i>RzMon agent</i> aan (elke minuut, uitvoer verborgen) en stuurt een testrapport. Had je de agent al met de hand geïnstalleerd? Dan worden je instellingen en cronjob overgenomen.</p>
        <p class="muted small">Daarna: bijwerken met de knop hierboven · testen: <code>bash /mnt/&lt;pool&gt;/rzmon-agent/rzmon-agent-setup --test</code> · verwijderen: <code>… --uninstall</code>.
          Tip: een <b>Commando</b>-check met <code>zpool status -x</code> en verwacht antwoord <code>all pools are healthy</code> bewaakt je pools.</p>
      </div>
      <div data-pane="ha" hidden>
        <p>Op Home Assistant OS / Supervised draait de agent als <b>add-on</b>. Hij meldt CPU, RAM, de data-partitie, Core, Supervisor, plugins en add-ons (ook als containers met CPU/RAM), updates, back-ups, onverwachte herstarts, de SD-kaart, USB-sticks en logs. Daarna: <b>＋ Aanbevolen checks</b>.</p>
        <ol class="steps">
          <li>In Home Assistant: <i>Instellingen → Add-ons → Add-on winkel</i> → ⋮ → <b>Repositories</b> → voeg <code>https://github.com/rembrand20/rzmon</code> toe.
            <span class="muted small">(Of kopieer de map <code>ha-addon/rzmon_agent</code> naar de share <code>addons</code> met de <i>Samba share</i>-add-on.)</span></li>
          <li>⋮ → <b>Controleren op updates</b> en installeer <b>RzMon Agent</b>.</li>
          <li>Tabblad <b>Configuratie</b>:</li>
        </ol>
        <pre class="code">monitor_url: ${esc(base)}
token: ${esc(h.agent_token || '')}
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
      ...(isAdmin() ? [{ label: '＋ Aanbevolen checks', style: 'margin-right:auto', onClick: () => recommendedChecks(findHost(h.id)) }] : []),
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
  $('#host-realtime').onchange = async (e) => {
    try {
      await api('host_realtime', { id: h.id, on: e.target.value === '1' });
      toast(`Realtime ${e.target.value === '1' ? 'aan' : 'uit'} voor ${h.name}; de agent pakt het binnen een minuut op`);
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#host-tick').onchange = async (e) => {
    try {
      const r = await api('host_tick', { id: h.id, tick: +e.target.value });
      toast(`${h.name} rapporteert elke ${tickText(r.tick)}; de agent pakt het binnen een minuut op`);
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  if (isAdmin()) {
    api('agent_commands').then((r) => {
      const me = r.hosts.find((x) => x.id === +h.id);
      if (!me) return;
      const sel = $('#host-commands');
      sel.value = me.own || 'global';
      $('#host-commands-info').textContent = me.own ? '' : `(nu: ${CMD_MODES[me.mode]})`;
      sel.onchange = () => setAgentCommands(h.id, sel.value, me.mode, h.name, () => agentInstructions(findHost(h.id)));
    }).catch(() => {});
  }
  $('#copy-truenas').onclick = async () => {
    try { await navigator.clipboard.writeText(truenasCmd); toast('Commando gekopieerd'); }
    catch (ex) { getSelection().selectAllChildren($('#truenas-oneliner')); toast('Geselecteerd — druk Ctrl+C', 'err'); }
  };
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
  else if (/truenas|freenas/i.test(h.name) || h.metrics.some((m) => m.startsWith('service:middlewared'))) show('truenas');
}

/** Aanbevolen commando-checks voor deze server (TrueNAS, Proxmox of Linux), met vinkjes; bestaande slaan we over. */
/** Aanbevolen checks voor de Home Assistant-add-on: OS, containers, USB-sticks en logs */
function haRecommended(h) {
  const has = (type, metric, target = '') => h.checks.some((c) => c.type === type && (c.metric || '') === metric && (target === '' || c.target === target));
  const items = [
    ...['backup', 'integrations', 'repairs', 'reboot', 'disklife', 'osupdate'].map((m) => ({ key: `ha_${m}`, label: HA_METRICS[m].label, exists: has('ha', m),
      data: { type: 'ha', metric: m, warn_threshold: HA_METRICS[m].warn ?? '', crit_threshold: HA_METRICS[m].crit ?? '' } })),
    ...h.metrics.filter((m) => m.startsWith('usb:')).map((m) => ({ key: `usb_${m}`, label: `USB-apparaat ${m.slice(4)}`, exists: has('ha', 'usb', m.slice(4)),
      data: { type: 'ha', metric: 'usb', target: m.slice(4) } })),
    ...['homeassistant', 'hassio_supervisor'].filter((n) => h.metrics.includes(`container:${n}`)).map((n) => ({ key: `ct_${n}`,
      label: `Container ${n === 'homeassistant' ? 'Home Assistant Core' : 'Supervisor'}: status, CPU en RAM`, exists: h.checks.some((c) => c.type === 'container' && c.target === n),
      data: { type: 'container', target: n, name: n === 'homeassistant' ? '📦 Home Assistant Core' : '📦 Supervisor', res: { cpu: { on: true, warn: 80, crit: 95 }, mem: { on: true, warn: 85, crit: 95 }, disk: { on: false } } } })),
    ...[['ha_power', 'ha:host'], ['ha_core', 'ha:core']].map(([p, src]) => ({ key: `log_${p}`, label: LOG_PRESETS[p].label, exists: h.checks.some((c) => c.type === 'log' && c.target === src),
      data: { type: 'log', target: src, name: LOG_PRESETS[p].label.replace(/^\S+\s/, ''), preset: p, pattern: LOG_PRESETS[p].pat, warn_threshold: LOG_PRESETS[p].warn, crit_threshold: LOG_PRESETS[p].crit } })),
  ];
  modal({
    title: `＋ Aanbevolen checks – ${h.name}`,
    wide: true,
    body: `<p style="margin-top:0">Herkend als <b>Home Assistant</b>. Vink aan wat je wilt bewaken; je kunt elke check later nog aanpassen.</p>
      <div class="rec-list">${items.map((x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${esc(x.key)}" ${x.exists ? 'disabled' : 'checked'}>
        <span><b>${esc(x.label)}</b>${x.exists ? ' · al aanwezig' : ''}</span></label>`).join('')}</div>
      <p class="muted small">Containers, USB-apparaten en logs vragen add-on 1.42.0 of nieuwer met de rol <i>manager</i>. Nog niet op een echte Home Assistant getest: laat weten als iets niet goed uitkomt.</p>`,
    buttons: [{ label: 'Toevoegen', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error('Vink minstens één check aan');
      for (const x of chosen) await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, verify_ssl: true, ...x.data });
      toast(`${chosen.length} check(s) toegevoegd`);
      refresh();
    },
  });
}

async function recommendedChecks(h) {
  if (isHaHost(h)) return haRecommended(h);
  let list = null, mode = 'off';
  try { list = (await api('agent_services', null, { id: h.id })).list; } catch (e) { /* zonder lijst: Linux */ }
  try { mode = ((await api('agent_commands')).hosts.find((x) => x.id === +h.id) || {}).mode || 'off'; } catch (e) { /* leeg */ }
  const { platform, os } = detectPlatform(list);
  const have = new Set(h.checks.filter((c) => c.type === 'command').map((c) => c.name));
  const names = new Set((list || []).map((x) => x[0]));
  // ZFS en Docker ook voorstellen als die diensten op de server draaien
  const offered = (k, p) => platform in p.rec || (k === 'zfs_health' && names.has('zfs-zed')) || (k === 'docker' && names.has('docker'));
  const items = Object.entries(CMD_PRESETS).filter(([k, p]) => offered(k, p) && (!p.os || !os || p.os === os));
  // Standaard aangevinkt: aanbevolen voor dit platform (bij onbekende Linux-variant niet de distro-specifieke)
  const checked = (k, p) => (p.rec[platform] && (!p.os || p.os === os)) || (k === 'zfs_health' && names.has('zfs-zed')) || (k === 'docker' && names.has('docker'));
  const row = ([k, p]) => {
    const exists = have.has(p.name);
    return `<label class="rec-row ${exists ? 'muted' : ''}"><input type="checkbox" name="rec_${k}" ${!exists && checked(k, p) ? 'checked' : ''} ${exists ? 'disabled' : ''}>
      <span><b>${esc(p.label)}</b>${p.root ? ' <span class="badge-root">root</span>' : ''}${exists ? ' · al aanwezig' : ''}<br>
      <code class="small">${esc(p.cmd.length > 90 ? p.cmd.slice(0, 87) + '…' : p.cmd)}</code></span></label>`;
  };
  const needRoot = items.some(([, p]) => p.root);
  modal({
    title: `＋ Aanbevolen checks – ${h.name}`,
    wide: true,
    body: `<p style="margin-top:0">Herkend als <b>${CMD_GROUPS[platform]}</b>${os ? ` (${os === 'rhel' ? 'Rocky/RHEL' : 'Debian-familie'})` : ''}. Vink aan wat je wilt bewaken; elke regel wordt een <i>Commando</i>-check die je later nog kunt aanpassen.</p>
      ${mode === 'off' ? `<p class="warn-txt small"><b>⚠️ Commando's staan voor ${esc(h.name)} uit.</b> De checks blijven onbekend tot je ze aanzet (Agent Manager → Commando-checks op deze server).</p>`
        : mode === 'nobody' && needRoot ? '<p class="warn-txt small">⚠️ Checks met <b>root</b> werken alleen als commando\'s op deze server <i>als root</i> mogen.</p>' : ''}
      <div class="rec-list">${items.map(row).join('')}</div>
      <p class="muted small">Voorinstellingen voor TrueNAS en Proxmox zijn nog niet op een echt systeem getest; laat weten als er een niet goed uitkomt.</p>`,
    buttons: [{ label: 'Toevoegen', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter(([k]) => d['rec_' + k]);
      if (!chosen.length) throw new Error('Vink minstens één check aan');
      for (const [, p] of chosen) {
        await api('check_save', { host_id: h.id, type: 'command', name: p.name, interval_sec: p.interval, enabled: true,
          command: { cmd: p.cmd, expect: p.expect, match: p.match, timeout: p.timeout, fail: p.fail } });
      }
      toast(`${chosen.length} check(s) toegevoegd`);
      refresh();
    },
  });
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
      html += `<div class="small">${realtimeText(s.realtime)}</div>`;
      const tw = toolsWarning(s.realtime && s.realtime.tools, s.update && s.update.via);
      if (tw) html += `<div class="small tools-warn">${tw}</div>`;
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
  if (a.kind === 'auth' || a.kind === 'authfail') return '<span class="muted" title="Alleen op deze pagina, niet verstuurd">–</span>';
  if (a.kind === 'ack') return '<span title="Bevestiging">✔</span>';
  const out = [];
  if (+a.sent_ok) out.push('<span title="Verstuurd via Pushover">📲</span>');
  if (a.email_ok !== null && a.email_ok !== undefined) out.push(+a.email_ok ? '<span title="Verstuurd per e-mail">📧</span>' : '<span title="E-mail mislukt">📧✕</span>');
  if (a.webhook_ok !== null) out.push(+a.webhook_ok ? '<span title="Verstuurd naar Home Assistant">🏠</span>' : '<span title="Webhook mislukt">🏠✕</span>');
  if (!out.length) out.push('<span title="Niet verzonden">✉️✕</span>');
  return out.join(' ');
}

/** "🔴 Critical", "✅ Hersteld", "✔ Bevestigd"… voor de meldingentabel */
function alertStatusText(a) {
  if (a.kind === 'ids') return '🛡️ IDS';
  if (a.kind === 'auth') return '🔑 Toegang';
  if (a.kind === 'authfail') return '❌ Mislukt';
  if (a.kind === 'ack') return '✔ Bevestigd';
  if (a.kind === 'maint') return '🔧 Onderhoud';
  const ev = (a.title.match(/^\S+ (HERSTELD|STABIEL|FLAPPEND)/) || [])[1];
  if (ev) return { HERSTELD: '✅ Hersteld', STABIEL: '⏸ Stabiel', FLAPPEND: '🔀 Flapt' }[ev];
  return ['✅ OK', '🟠 Warning', '🔴 Critical', '⚪ Onbekend'][+a.status] || '';
}

// ---------------------------------------------------------------- Meldingen, per dag
// Alle meldingen van de bewaartermijn, per dag in een uitklapblok. Vandaag staat open; wat je open- of
// dichtklapt blijft zo tot je de pagina herlaadt.

async function loadAlerts() {
  try { alertData = await api('alerts'); } catch (ex) { toast(ex.message, 'err'); return; }
  renderAlerts();
}

// ---------------------------------------------------------------- IDS
const IDS_FW = {
  active: (h) => `⛔ blokkeren actief${h.blocks ? ` · ${h.blocks} geblokkeerd` : ''}`,
  off: () => 'alleen melden',
  unsupported: () => 'TrueNAS: alleen detecteren',
  disabled: () => 'blokkeren uit op de server (IDS_BLOCK=0)',
  error: (h) => `<span class="bad">✘ firewall: ${esc(h.fw_error || 'fout')}</span>`,
};

async function loadIds() {
  try { [idsData, accData] = await Promise.all([api('ids'), api('ids_access')]); } catch (ex) { toast(ex.message, 'err'); return; }
  renderIds();
  renderAccess();
  showIdsTab();
}

function showIdsTab() {
  if (!['inc', 'acc', 'blk'].includes(idsTab)) idsTab = 'inc';
  $$('#ids-tabs .seg-btn').forEach((x) => x.classList.toggle('active', x.dataset.it === idsTab));
  $$('#tab-ids .ids-pane').forEach((x) => { x.hidden = x.dataset.pane !== idsTab; });
}

// ---------------------------------------------------------------- IDS: bestanden bewaken
const FIM_SEV = [[1, '🟠 warning'], [2, '🔴 critical']];
/** Voorstel per gangbare map (die de agent op de server heeft gezien): pad en ernst */
function fimSuggest(dir) {
  if (/^\/(var|srv)\/(www|http)$/.test(dir)) return { p: `${dir}/**/*.php`, s: 2, why: 'nieuwe of gewijzigde PHP-bestanden (webshells)' };
  if (/\/s?bin$/.test(dir)) return { p: dir, s: 2, why: 'eigen programma\'s en scripts' };
  return { p: dir, s: 1, why: dir === '/opt' ? 'eigen software' : 'configuratie' };
}

/**
 * Lijst met paden en ernst. hostId: alleen voorstellen van die server (anders van alle servers).
 * onChange na elke wijziging. @return {{ get: () => {p: string, s: number}[] }}
 */
function fimEditor(el, list, hostId, onChange) {
  let rows = (Array.isArray(list) ? list : []).map((r) => ({ p: String(r.p || ''), s: +r.s === 2 ? 2 : 1 }));
  const changed = () => { if (onChange) onChange(); };
  const draw = () => {
    el.innerHTML = `<div class="fim-rows">${rows.map((r, i) => `<div class="fim-row" data-i="${i}">
        <input class="mono" data-f="p" value="${esc(r.p)}" placeholder="/usr/local/bin" maxlength="300" aria-label="Pad">
        <select data-f="s" aria-label="Ernst">${FIM_SEV.map(([v, l]) => `<option value="${v}" ${r.s === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <button type="button" class="icon-btn" data-fim="del" title="Weghalen">✕</button></div>`).join('')
      || '<p class="muted small fim-empty">Nog geen paden.</p>'}</div>
      <div class="fim-tools"><button type="button" class="btn small-btn" data-fim="add" ${rows.length >= 20 ? 'disabled' : ''}>＋ Pad</button>
        <button type="button" class="btn small-btn" data-fim="suggest">＋ Aanbevolen</button></div>`;
  };
  el.oninput = el.onchange = (e) => {
    const row = e.target.closest('.fim-row');
    if (!row || !e.target.dataset.f) return;
    const r = rows[+row.dataset.i];
    if (!r || !el.contains(row)) return;   // lijst is intussen opnieuw opgebouwd
    if (e.target.dataset.f === 'p') { r.p = e.target.value.trim(); fimCheck(e.target); } else r.s = +e.target.value;
    changed();
  };
  el.onclick = async (e) => {
    const b = e.target.closest('[data-fim]');
    if (!b) return;
    if (b.dataset.fim === 'add') { rows.push({ p: '', s: 1 }); draw(); $$('.fim-row input', el).pop().focus(); }
    else if (b.dataset.fim === 'del') { rows.splice(+b.closest('.fim-row').dataset.i, 1); draw(); changed(); }
    else if (b.dataset.fim === 'suggest') {
      let ids;
      try { ids = await api('ids'); } catch (ex) { return toast(ex.message, 'err'); }
      // Gangbare mappen die de agent(s) gezien hebben, met op welke servers
      const where = new Map();
      ids.hosts.filter((x) => !hostId || +x.id === +hostId).forEach((x) => (x.fim_cand || '').split(' ').filter(Boolean)
        .forEach((d) => { if (!where.has(d)) where.set(d, []); where.get(d).push(x.name); }));
      const have = new Set(rows.map((r) => r.p));
      const sugg = [...where].map(([d, hosts]) => ({ ...fimSuggest(d), hosts })).sort((a, b) => b.s - a.s || a.p.localeCompare(b.p));
      if (!sugg.length) return toast(ids.enabled ? 'Nog geen voorstellen: de agents (1.40.0+) melden binnen een paar minuten welke mappen er zijn' : 'Zet eerst het IDS aan: dan melden de agents welke mappen er zijn', 'err');
      // Eigen venster bovenop het formulier (het formulier zelf blijft open)
      const dlg = document.createElement('dialog');
      dlg.className = 'fim-dlg';
      dlg.innerHTML = `<form method="dialog" class="modal-inner"><header><h3>＋ Aanbevolen paden</h3></header>
        <div class="fim-dlg-body"><p class="muted small" style="margin-top:0">Mappen die ${hostId ? 'de agent op deze server' : 'de agents'} gevonden ${hostId ? 'heeft' : 'hebben'}. Vink aan wat je wilt bewaken.</p>
        ${sugg.map((x, i) => `<label class="switch small fim-sug"><input type="checkbox" name="s${i}" ${have.has(x.p) ? 'disabled' : 'checked'}><span></span>
          <span class="fim-sug-t"><code>${esc(x.p)}</code> ${x.s === 2 ? '🔴' : '🟠'}<br><span class="muted small">${esc(x.why)}${hostId ? '' : ` · ${esc(x.hosts.join(', '))}`}${have.has(x.p) ? ' · staat er al' : ''}</span></span></label>`).join('')}</div>
        <footer><button class="btn" value="cancel">Annuleren</button><button class="btn primary" value="ok">Toevoegen</button></footer></form>`;
      document.body.append(dlg);
      dlg.addEventListener('close', () => {
        if (dlg.returnValue === 'ok') {
          sugg.forEach((x, i) => { if (!have.has(x.p) && dlg.querySelector(`[name=s${i}]`).checked && rows.length < 20) rows.push({ p: x.p, s: x.s }); });
          draw(); changed();
        }
        dlg.remove();
      });
      dlg.showModal();
    }
  };
  draw();
  $$('.fim-row input', el).forEach(fimCheck);
  return { get: () => rows.filter((r) => r.p).map((r) => ({ p: r.p, s: r.s })) };
}

/** Pad controleren zoals RzMon dat doet (Ids::cleanFim), zodat je het meteen ziet */
function fimCheck(input) {
  const p = input.value.trim();
  input.setCustomValidity(!p || (p.startsWith('/') && p.replace(/\/+$/, '') !== '' && !/^\/(proc|sys|dev|run)(\/|$)/.test(p)) ? ''
    : 'Gebruik een volledig pad (begint met /), niet / zelf en niet onder /proc, /sys, /dev of /run');
  input.title = input.validationMessage;
}

// ---------------------------------------------------------------- IDS → Toegang
const ACC_TYPES = { ssh: 'SSH', console: 'Console', sudo: 'sudo', su: 'su' };
const ACC_COLS = { time: 'Tijd', type: 'Soort', server: 'Server', who: 'Wie', what: 'Wat / vanaf' };
function accDet(r) { try { return JSON.parse(r.detail || '{}') || {}; } catch (e) { return {}; } }
function accWho(r, d) {
  const who = (d.users ? d.users.join(', ') : d.user) || '?';
  return who + (d.as && (r.type === 'su' || r.type === 'sudo') ? ` → ${d.as}` : '');
}

function renderAccess() {
  const d = accData;
  const el = $('#ids-access');
  if (!d) { el.innerHTML = '<p class="muted">Laden…</p>'; return; }
  // Serverkeuze: alleen servers die in de lijst voorkomen
  const hosts = [...new Map(d.rows.map((r) => [r.host_id, r.host_name])).entries()].sort((a, b) => a[1].localeCompare(b[1], 'nl'));
  $('#acc-host').innerHTML = '<option value="">Alle servers</option>' + hosts.map(([id, n]) => `<option value="${id}" ${+accHost === +id ? 'selected' : ''}>${esc(n)}</option>`).join('');
  const fails = d.rows.filter((r) => r.kind === 'authfail').length;
  $('#it-n-acc').textContent = d.rows.length ? `(${d.rows.length})` : '';
  $('#acc-info').textContent = `${d.rows.length - fails} gelukt · ${fails} mislukt · laatste ${d.days} dagen` + (d.truncated ? ' (alleen de nieuwste 10.000)' : '');
  const off = d.enabled ? '' : '<p class="warn-txt small">Toegang bijhouden staat uit: er komt niets nieuws bij. Aanzetten onder <a href="#settings" data-goto="settings">Instellingen → 🛡️ IDS</a>.</p>';
  const list = d.rows.filter((r) => (accFilter === 'all' || (accFilter === 'fail' ? r.kind === 'authfail' : r.type === accFilter))
    && (!accHost || +r.host_id === +accHost));
  if (!list.length) {
    el.innerHTML = off + `<p class="muted">${d.rows.length ? 'Niets gevonden met dit filter.' : 'Nog geen logins, sudo of su.'}</p>`;
    $('#acc-toggle').hidden = true;
    return;
  }
  const byDay = new Map();
  list.forEach((r) => { const day = r.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(r); });
  const isOpen = (day) => accDayOpen[day] ?? day === d.today;
  el.innerHTML = off + [...byDay].map(([day, rows]) => {
    const nf = rows.filter((r) => r.kind === 'authfail').length;
    return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${rows.length - nf ? `<span class="ad-count" title="gelukt">🔑 ${rows.length - nf}</span>` : ''}${nf ? `<span class="ad-count" title="mislukt">❌ ${nf}</span>` : ''}</span>
        <span class="muted small ad-total">${rows.length} regel${rows.length === 1 ? '' : 's'}</span></summary>
      <div class="ad-body">${isOpen(day) ? accTable(rows) : ''}</div></details>`;
  }).join('');
  $$('.alert-day', el).forEach((det) => det.addEventListener('toggle', () => {
    accDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = accTable(byDay.get(det.dataset.day));
    accToggleLabel();
  }));
  accToggleLabel();
}

function accToggleLabel() {
  const days = $$('#ids-access .alert-day');
  $('#acc-toggle').hidden = days.length < 2;
  $('#acc-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ Alles sluiten' : '⊞ Alles openen';
}

function accTable(rows) {
  const val = {
    time: (r) => r.created_at, type: (r) => (r.kind === 'authfail' ? '1' : '0') + r.type, server: (r) => r.host_name,
    who: (r) => accWho(r, accDet(r)), what: (r) => r.message,
  }[accSort.key];
  if (val) {
    rows = rows.map((r) => [r, val(r)]).sort((p, q) => accSort.dir * String(p[1]).localeCompare(String(q[1]), 'nl', { numeric: true, sensitivity: 'base' })
      || (q[0].created_at > p[0].created_at ? 1 : -1)).map(([r]) => r);
  }
  const th = (k) => `<th class="sortable${accSort.key === k ? ' sorted' : ''}" data-sort="${k}" title="Sorteren op ${ACC_COLS[k].toLowerCase()}">${ACC_COLS[k]}${accSort.key === k ? (accSort.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`;
  return `<div class="table-scroll"><table class="alert-table large acc-table"><thead><tr>${Object.keys(ACC_COLS).map(th).join('')}<th></th></tr></thead><tbody>
    ${rows.map((r) => {
      const det = accDet(r);
      const fail = r.kind === 'authfail';
      return `<tr class="${fail ? 's1' : 'auth'}">
        <td class="at-time" title="${esc(r.created_at)}">${esc(r.created_at.slice(11, 16))}</td>
        <td class="at-st">${fail ? '❌ ' : '🔑 '}${esc(ACC_TYPES[r.type] || r.type)}${fail && +det.n > 1 ? ` <span class="muted small">${+det.n}×</span>` : ''}</td>
        <td>${esc(r.host_name)}</td>
        <td>${esc(accWho(r, det))}</td>
        <td class="at-msg">${esc(r.message)}</td>
        <td class="acc-actions need-admin">
          <button type="button" class="linkbtn" data-acc="ignore" data-id="${r.id}" title="Deze gebruiker of dit IP-adres voortaan niet meer tonen">🙈 Negeer</button>
          ${det.ip ? `<button type="button" class="linkbtn" data-acc="block" data-id="${r.id}" title="IP-adres ${esc(det.ip)} blokkeren">⛔ Blokkeer</button>` : ''}
        </td></tr>`;
    }).join('')}</tbody></table></div>`;
}

/** 🙈 Negeer: kies de gebruiker(s) en/of het IP-adres die voortaan niet meer getoond worden */
function accIgnoreDialog(r) {
  const det = accDet(r);
  const users = [...new Set((det.users || [det.user]).filter((u) => u && u !== '?'))];
  const opts = [...users.map((u) => ['user', u, `gebruiker <b>${esc(u)}</b>`]), ...(det.ip ? [['ip', det.ip, `IP-adres <code>${esc(det.ip)}</code>`]] : [])];
  if (!opts.length) return toast('Geen gebruiker of IP-adres om te negeren', 'err');
  modal({
    title: '🙈 Negeren',
    body: `<p style="margin-top:0">Wat wil je voortaan niet meer zien in <i>Toegang</i>? Ook de regels die er al staan, verdwijnen.</p>
      ${opts.map(([k, v, l], i) => `<label class="switch small"><input type="checkbox" name="ign${i}" value="${esc(v)}" ${opts.length === 1 || k === 'ip' ? 'checked' : ''}><span></span> ${l}</label>`).join('')}
      <p class="muted small">Een mislukte poging met meerdere gebruikers wordt alleen verborgen als <i>alle</i> gebruikers genegeerd worden, of het IP-adres. De lijst pas je aan onder <i>Instellingen → 🛡️ IDS → Negeren</i>. Brute-forcedetectie van het IDS blijft gewoon werken.</p>`,
    buttons: [{ label: '🙈 Negeren', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const add = Object.keys(d).filter((k) => k.startsWith('ign')).map((k) => d[k]);
      if (!add.length) throw new Error('Kies minstens één gebruiker of IP-adres');
      await api('ids_ignore', { add: add.join(' ') });
      toast(`Genegeerd: ${add.join(', ')}`);
      setTimeout(loadIds, 50);
    },
  });
}

function renderIds() {
  const d = idsData;
  if (!d) return;
  $('#ids-status').innerHTML = !d.enabled ? '<b class="warn-txt">IDS staat uit.</b> Zet het aan onder <a href="#settings" data-goto="settings">Instellingen → 🛡️ IDS</a>.'
    : `Modus: <b>${d.mode === 'block' ? '⛔ blokkeren' : '🎓 leren (alleen melden)'}</b> · <a href="#settings" data-goto="settings">instellingen</a>`;
  $('#ids-hosts').innerHTML = d.hosts.length ? d.hosts.map((h) => h.ha ? `
    <div class="ids-host"><div><b>${esc(h.name)}</b></div>
      <div class="small muted">niet beschikbaar in de Home Assistant-add-on: die draait in een container en ziet het systeem zelf niet</div></div>` : `
    <div class="ids-host">
      <div><b>${esc(h.name)}</b> <span class="muted small">${h.last_report ? 'IDS-rapport ' + ago(h.last_report) : d.enabled ? 'nog geen IDS-rapport (agent 1.35.0+)' : ''}</span></div>
      <div class="small">${!h.baseline_until ? '' : h.learning ? `📏 basismeting tot ${esc(fmtUntil(h.baseline_until))}` : '✔ bewaakt'}
        ${h.fw_state ? ' · ' + (IDS_FW[h.fw_state] || (() => esc(h.fw_state)))(h) : ''}${h.fim_count != null ? ` · 📁 ${(+h.fim_count).toLocaleString('nl-NL')} bestand${+h.fim_count === 1 ? '' : 'en'} bewaakt` : ''}${+h.realtime ? ((x) => (x && x.state === 'active' ? ' · <span class="good" title="Realtime-watcher actief">⚡ realtime</span>' : ' · <span class="warn-txt" title="Realtime staat aan, maar de watcher is niet actief">⚡ niet actief</span>'))(JSON.parse(h.rt || 'null')) : ''}${+h.realtime ? ((t) => (t && !t.inotify ? ` · <span class="warn-txt" title="Bestanden worden niet in realtime gevolgd; zie de Agent Manager">${t.family === 'rhel' && !t.epel ? 'EPEL ontbreekt' : 'inotify-tools ontbreekt'}</span>` : ''))(JSON.parse(h.tools || 'null')) : ''}</div>
      <div class="small">${h.open[2] ? `<span class="ad-count">🔴 ${h.open[2]}</span>` : ''} ${h.open[1] ? `<span class="ad-count">🟠 ${h.open[1]}</span>` : ''}
        ${!h.open[1] && !h.open[2] ? '<span class="muted">geen open incidenten</span>' : ''}
        ${h.baseline_until ? `<button type="button" class="linkbtn need-admin" data-rebase="${h.id}" title="Alles wat er nu is als normaal vastleggen">↺ basismeting opnieuw</button>` : ''}</div>
    </div>`).join('') : '<p class="muted">Nog geen servers met agent.</p>';

  // Incidenten per dag
  const list = d.incidents.filter((i) => idsFilter === 'all' || i.status === 'open');
  const open = d.incidents.filter((i) => i.status === 'open');
  $('#ids-info').textContent = `${open.length} open · ${d.incidents.length} in de laatste ${d.days} dagen`;
  $('#it-n-inc').textContent = open.length ? `(${open.length})` : '';
  const el = $('#ids-incidents');
  if (!list.length) {
    el.innerHTML = `<p class="muted">${idsFilter === 'open' ? 'Geen open incidenten. 👌' : 'Nog geen incidenten.'}</p>`;
  } else {
    const byDay = new Map();
    list.forEach((i) => { const day = i.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(i); });
    const isOpen = (day) => idsDayOpen[day] ?? (day === d.today || idsFilter === 'open');
    el.innerHTML = [...byDay].map(([day, items]) => {
      const n2 = items.filter((i) => +i.severity === 2).length, n1 = items.length - n2;
      return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''}>
        <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
          <span class="ad-counts">${n2 ? `<span class="ad-count">🔴 ${n2}</span>` : ''}${n1 ? `<span class="ad-count">🟠 ${n1}</span>` : ''}</span>
          <span class="muted small ad-total">${items.length} incident${items.length === 1 ? '' : 'en'}</span></summary>
        <div class="ad-body">${items.map(idsRow).join('')}</div></details>`;
    }).join('');
    $$('.alert-day', el).forEach((det) => det.addEventListener('toggle', () => { idsDayOpen[det.dataset.day] = det.open; idsToggleLabel(); }));
  }
  idsToggleLabel();

  // Blokkades
  $('#ids-block-info').textContent = `${d.blocks.length} actief`;
  $('#it-n-blk').textContent = d.blocks.length ? `(${d.blocks.length})` : '';
  const row = (b, active) => `<tr>
      <td class="mono">${esc(b.ip)}</td><td>${esc(b.host_name)}</td><td>${esc(b.reason)}</td>
      <td class="at-time" title="${esc(b.created_at)}">${esc(fmtUntil(b.created_at))}</td>
      <td class="at-time">${active ? (b.until ? esc(fmtUntil(b.until)) : '<b>permanent</b>') : b.removed_at ? 'gedeblokkeerd ' + esc(fmtUntil(b.removed_at)) + (b.removed_by ? ' door ' + esc(b.removed_by) : '') : 'verlopen'}</td>
      <td>${+b.auto ? 'automatisch' : esc(b.created_by)}</td>
      <td title="Tegengehouden pakketten sinds de laatste wijziging van de lijst">${+b.hits || 0}</td>
      <td>${active ? `<button type="button" class="btn need-admin small-btn" data-unblock="${b.id}">🔓 Deblokkeren</button>` : ''}</td></tr>`;
  const head = '<thead><tr><th>IP</th><th>Server</th><th>Reden</th><th>Sinds</th><th>Tot</th><th>Door</th><th>Pakketten</th><th></th></tr></thead>';
  $('#ids-blocks').innerHTML = (d.blocks.length ? `<div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${d.blocks.map((b) => row(b, true)).join('')}</tbody></table></div>`
      : `<p class="muted">Niets geblokkeerd.${d.mode !== 'block' ? ' (Modus <i>leren</i>: er wordt niets geblokkeerd.)' : ''}</p>`)
    + (d.removed.length ? `<details class="small api-docs"><summary>Eerder geblokkeerd (${d.removed.length})</summary>
        <div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${d.removed.map((b) => row(b, false)).join('')}</tbody></table></div></details>` : '');
}

function idsRow(i) {
  const sev = +i.severity === 2 ? 2 : 1;
  const done = i.status !== 'open';
  const fim = i.type === 'fim' || i.type === 'fim_bulk';
  const okLabel = fim ? (i.type === 'fim_bulk' ? '✔ Alles goedkeuren' : '✔ Wijziging goedkeuren') : '✔ Afhandelen';
  const normal = fim ? ['🚫 Niet meer bewaken', i.type === 'fim_bulk' ? 'Goedkeuren en wijzigingen onder dit pad voortaan niet meer melden' : 'Goedkeuren en dit bestand voortaan niet meer bewaken']
    : ['Dit is normaal', 'Afhandelen en dit voortaan niet meer melden'];
  return `<div class="alert-item ids-item s${sev} ${done ? 'ids-done' : ''}">
    <span class="dot"></span>
    <strong>${sev === 2 ? '🔴' : '🟠'} ${esc(i.title)}${+i.hits > 1 ? ` <span class="muted small">(${i.hits}×)</span>` : ''}</strong>
    <span class="muted small" title="${esc(i.created_at)}">${esc(i.created_at.slice(11, 16))} · ${esc(i.host_name)}</span>
    <span class="msg">${esc(i.detail)}${i.ip ? ` · IP <code>${esc(i.ip)}</code>` : ''}${done ? ` · ✔ afgehandeld door ${esc(i.handled_by || '?')}` : ''}</span>
    <span class="ids-actions need-admin">${done ? `<button type="button" class="linkbtn" data-ids="reopen" data-id="${i.id}">↺ heropenen</button>`
      : `<button type="button" class="btn small-btn" data-ids="handle" data-id="${i.id}">${okLabel}</button>
         <button type="button" class="btn small-btn" data-ids="normal" data-id="${i.id}" title="${normal[1]}">${normal[0]}</button>`}
      ${i.ip ? `<button type="button" class="btn small-btn" data-ids="block" data-ip="${esc(i.ip)}" data-host="${i.host_id}">⛔ Blokkeer IP</button>` : ''}</span>
  </div>`;
}

function idsToggleLabel() {
  const days = $$('#ids-incidents .alert-day');
  $('#ids-toggle').hidden = days.length < 2;
  $('#ids-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ Alles sluiten' : '⊞ Alles openen';
}

function idsBlockDialog(ip = '', hostId = '', reason = '') {
  const hosts = ((idsData && idsData.hosts) || []).filter((h) => !h.ha);
  modal({
    title: '⛔ IP blokkeren',
    body: `<label>IP-adres<input name="ip" class="mono" value="${esc(ip)}" required placeholder="bv. 203.0.113.9"></label>
      <div class="row2">
        <label>Op server<select name="host_id">${hosts.map((h) => `<option value="${h.id}" ${+h.id === +hostId ? 'selected' : ''}>${esc(h.name)}${h.fw_state === 'unsupported' ? ' (alleen detecteren)' : ''}</option>`).join('')}</select></label>
        <label>Hoe lang<select name="hours"><option value="24">24 uur</option><option value="168">7 dagen</option><option value="720">30 dagen</option><option value="0">Permanent</option></select></label>
      </div>
      <label>Reden <span class="hint">(optioneel)</span><input name="reason" maxlength="200" value="${esc(reason)}"></label>
      ${idsData && idsData.mode !== 'block' ? '<p class="warn-txt small">IDS staat op <i>leren</i>: de blokkade wordt vastgelegd, maar pas echt actief als je overschakelt naar <i>blokkeren</i>.</p>' : ''}
      <p class="muted small">Je eigen IP-adres (${esc((idsData && idsData.my_ip) || '?')}) kan niet geblokkeerd worden. Deblokkeren kan altijd hier; de agent past het binnen een minuut aan.</p>`,
    buttons: [{ label: '⛔ Blokkeren', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('ids_block', { ip: d.ip, host_id: +d.host_id, hours: +d.hours, reason: d.reason });
      toast(`${d.ip} geblokkeerd`);
      setTimeout(loadIds, 50);
    },
  });
}

/** "Vandaag", "Gisteren" of "di 14 okt", met het jaar erbij als het niet dit jaar is */
function dayLabel(day, today) {
  const d = new Date(day + 'T12:00:00'), t = new Date(today + 'T12:00:00');
  const diff = Math.round((t - d) / 86400000);
  const opts = { weekday: 'long', day: 'numeric', month: 'long', ...(d.getFullYear() !== t.getFullYear() ? { year: 'numeric' } : {}) };
  const date = d.toLocaleDateString('nl-NL', opts);
  return diff === 0 ? `Vandaag · ${date}` : diff === 1 ? `Gisteren · ${date}` : date.charAt(0).toUpperCase() + date.slice(1);
}

const AUTH_TYPES = { ssh: 'SSH', console: 'Console', sudo: 'sudo', su: 'su' };
/** Extra gegevens van een login/sudo-regel (wie, wat, IP), of null */
function alertDetail(a) {
  if (a.kind !== 'auth' && a.kind !== 'authfail') return null;
  try { return JSON.parse(a.detail || '{}'); } catch (e) { return {}; }
}

/** Tellers in de kop van een dag: hoeveel critical, warning, onbekend, hersteld en bevestigd */
function alertDayCounts(list) {
  const n = { crit: 0, warn: 0, unknown: 0, ok: 0, ack: 0, auth: 0, authfail: 0 };
  list.forEach((a) => {
    if (a.kind === 'auth' || a.kind === 'authfail') n[a.kind]++;
    else if (a.kind === 'ack') n.ack++;
    else if (+a.status === 2) n.crit++;
    else if (+a.status === 1) n.warn++;
    else if (+a.status === 3) n.unknown++;
    else n.ok++;
  });
  return [['crit', '🔴', 'critical'], ['warn', '🟠', 'warning'], ['unknown', '⚪', 'onbekend'], ['ok', '✅', 'hersteld/OK'], ['ack', '✔', 'bevestigd'], ['auth', '🔑', 'logins en sudo'], ['authfail', '❌', 'mislukte logins/sudo']]
    .filter(([k]) => n[k]).map(([k, i, t]) => `<span class="ad-count" title="${n[k]} ${t}">${i} ${n[k]}</span>`).join('');
}

/** Server en check van een melding; van een verwijderde check uit de titel ("🔴 CRITICAL: server – check") */
function alertCols(a) {
  const t = a.title.match(/:\s*(.+?) – (.+)$/);
  const det = alertDetail(a);
  const who = det ? (det.users ? det.users.join(', ') : det.user) || '?' : '';
  return {
    host: a.host_name || (t ? t[1] : 'RzMon'),
    check: det ? `${AUTH_TYPES[det.type] || det.type} · ${who}${det.as && det.type === 'su' ? ' → ' + det.as : ''}` : a.check_name || (t ? t[2] : '–'),
  };
}

/** Volgorde van de status: critical, warning, onbekend, OK/hersteld, bevestigd, logins */
function alertRank(a) {
  if (a.kind === 'authfail') return 5;
  if (a.kind === 'auth') return 6;
  if (a.kind === 'ack') return 4;
  return [3, 1, 0, 2][+a.status] ?? 3;
}
/** Mislukte verzendingen tellen zwaar, daarna hoe weinig er verstuurd is */
function alertChannelScore(a) {
  if (a.kind === 'auth' || a.kind === 'authfail' || a.kind === 'ack') return 100;
  const sent = +a.sent_ok + (+a.email_ok || 0) + (+a.webhook_ok || 0);
  const failed = (a.email_ok !== null && a.email_ok !== undefined && !+a.email_ok ? 1 : 0) + (a.webhook_ok !== null && a.webhook_ok !== undefined && !+a.webhook_ok ? 1 : 0) + (sent ? 0 : 1);
  return -failed * 10 + sent;
}
function sortAlerts(list) {
  const { key, dir } = alertSort;
  if (!ALERT_SORTS[key]) return list;
  const val = {
    time: (a) => a.created_at, status: alertRank, server: (a) => alertCols(a).host, check: (a) => alertCols(a).check,
    message: (a) => a.message || '', channels: alertChannelScore,
  }[key];
  const cmp = (x, y) => (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'nl', { numeric: true, sensitivity: 'base' }));
  return list.map((a) => [a, val(a)]).sort((p, q) => dir * cmp(p[1], q[1]) || (q[0].created_at > p[0].created_at ? 1 : -1)).map(([a]) => a);
}

function alertRows(list, layout, density) {
  const time = (a) => esc(a.created_at.slice(11, 16));
  list = sortAlerts(list);
  const cid = (a) => (a.check_id ? ` data-cid="${a.check_id}"` : '');
  if (layout === 'l1nes') {
    const th = (k) => `<th class="sortable${alertSort.key === k ? ' sorted' : ''}" data-sort="${k}" title="Sorteren op ${ALERT_SORTS[k].toLowerCase()}">${ALERT_SORTS[k]}${alertSort.key === k ? (alertSort.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`;
    return `<table class="alert-table ${density}"><thead><tr>${Object.keys(ALERT_SORTS).map(th).join('')}</tr></thead><tbody>
      ${list.map((a) => {
        const { host, check } = alertCols(a);
        return `<tr class="${a.kind === 'ack' ? 'ack' : a.kind === 'auth' ? 'auth' : 's' + a.status}${a.check_id ? ' clickable' : ''}"${cid(a)}>
          <td class="at-time" title="${esc(a.created_at)}">${time(a)}</td>
          <td class="at-st">${alertStatusText(a)}</td>
          <td>${esc(host)}</td>
          <td>${esc(check)}</td>
          <td class="at-msg" title="${density === 'small' ? 'Klik voor het hele bericht' : ''}">${esc(a.message)}</td>
          <td class="at-ch">${channelIcons(a)}</td></tr>`;
      }).join('')}</tbody></table>`;
  }
  return list.map((a) => `
    <div class="alert-item ${a.kind === 'ack' ? 'ack' : a.kind === 'auth' ? 'auth' : 's' + a.status}${a.check_id ? ' clickable' : ''}"${cid(a)}>
      <span class="dot"></span>
      <strong>${esc(a.title)}</strong>
      <span class="muted small" title="${esc(a.created_at)}">${time(a)} ${channelIcons(a)}</span>
      <span class="msg">${esc(a.message)}</span>
    </div>`).join('');
}

function renderAlerts() {
  const el = $('#alerts');
  if (!alertData) { el.innerHTML = '<p class="muted">Laden…</p>'; return; }
  const { layout, density } = viewPrefs();
  const d = alertData;
  // Blox heeft geen kolomkoppen: daar een keuzelijst om te sorteren
  const sortSel = $('#alerts-sort');
  sortSel.hidden = layout === 'l1nes';
  sortSel.value = ALERT_SORTS[alertSort.key] ? `${alertSort.key}:${alertSort.dir}` : '';
  if (sortSel.selectedIndex < 0) sortSel.value = '';
  $('#alerts-info').textContent = `${d.alerts.length} melding${d.alerts.length === 1 ? '' : 'en'} van de laatste ${d.days} dag${d.days === 1 ? '' : 'en'}`
    + (d.truncated ? ' (alleen de nieuwste 10.000)' : '');
  if (!d.alerts.length) { el.innerHTML = '<p class="muted">Nog geen meldingen. 👌</p>'; $('#alerts-toggle').hidden = true; return; }
  const shown = d.alerts;
  const byDay = new Map();
  shown.forEach((a) => {
    const day = a.created_at.slice(0, 10);
    if (!byDay.has(day)) byDay.set(day, []);
    byDay.get(day).push(a);
  });
  const isOpen = (day) => alertDayOpen[day] ?? day === d.today;
  el.innerHTML = [...byDay].map(([day, list]) => `
    <details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${alertDayCounts(list)}</span>
        <span class="muted small ad-total">${list.length} melding${list.length === 1 ? '' : 'en'}</span></summary>
      <div class="ad-body">${isOpen(day) ? alertRows(list, layout, density) : ''}</div>
    </details>`).join('');
  // Pas bij openen opbouwen: snel, ook met duizenden meldingen
  $$('.alert-day', el).forEach((det) => det.addEventListener('toggle', () => {
    alertDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = alertRows(byDay.get(det.dataset.day), layout, density);
    alertToggleLabel();
  }));
  $('#alerts-toggle').hidden = byDay.size < 2;
  alertToggleLabel();
}

function saveAlertSort() {
  try { localStorage.setItem('rzmon-alert-sort', JSON.stringify(alertSort)); } catch (e) { /* alleen voor deze sessie */ }
  renderAlerts();
}

function alertToggleLabel() {
  const days = $$('#alerts .alert-day');
  $('#alerts-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ Alles sluiten' : '⊞ Alles openen';
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
  settingsSummaries();
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

const API_RIGHTS = {
  read: ['📖 Status lezen', 'servers, checks, meetwaarden, meldingen'],
  ids_read: ['🛡️ IDS lezen', 'incidenten, blokkades, logins'],
  ids_block: ['⛔ IDS blokkeren', "IP's blokkeren en deblokkeren"],
  checks: ['✔ Checks bedienen', 'bevestigen, onderhoud, nu checken'],
};

/** Rechten, alleen vanaf en geldigheid; voor een nieuwe sleutel en voor ✎ (k = bestaande sleutel) */
function apiKeyFields(k) {
  const has = k ? String(k.rights || 'read').split(',') : ['read'];
  return `<div class="api-rights">${Object.entries(API_RIGHTS).map(([r, [label, hint]]) => `
      <label><input type="checkbox" name="rights" value="${r}" ${has.includes(r) ? 'checked' : ''}><span>${label}<small>${hint}</small></span></label>`).join('')}</div>
    <div class="row2">
      <label>Alleen vanaf <span class="hint">(optioneel; IP's of netwerken, bv. 192.168.1.10 10.0.0.0/24)</span>
        <input name="allow_from" maxlength="500" spellcheck="false" value="${esc(k?.allow_from || '')}" placeholder="overal vandaan"></label>
      <label>${k ? 'Verlengen' : 'Geldig'}
        <select name="months">${k ? '<option value="0">niet verlengen</option>' : ''}
          ${[6, 12, 24].map((m) => `<option value="${m}" ${!k && m === 12 ? 'selected' : ''}>${k ? 'met ' : ''}${m} maanden${k ? ' vanaf nu' : ''}</option>`).join('')}</select></label>
    </div>`;
}

/** Wat het formulier stuurt: rights als lijst */
function apiKeyData(form) {
  const fd = new FormData(form);
  return { ...Object.fromEntries(fd), rights: fd.getAll('rights') };
}

const fmtDay = (dt) => new Date(dt.replace(' ', 'T')).toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' });

function apiKeyExpiry(k) {
  if (!k.expires_at) return '<span class="muted">geen vervaldatum</span>';
  const days = Math.floor((new Date(k.expires_at.replace(' ', 'T')) - Date.now()) / 86400000);
  if (days < 0) return `<span class="crit-txt">verlopen op ${esc(fmtDay(k.expires_at))}</span>`;
  return `<span class="${days <= 30 ? 'warn-txt' : 'muted'}">geldig tot ${esc(fmtDay(k.expires_at))}${days <= 30 ? ` (nog ${days} dag${days === 1 ? '' : 'en'})` : ''}</span>`;
}

let apiKeys = [];
async function loadApiKeys() {
  $('#api-base').textContent = apiBase();
  if (!$('#api-key-fields').innerHTML) $('#api-key-fields').innerHTML = apiKeyFields();
  apiKeys = await api('api_keys');
  $('#api-keys').innerHTML = apiKeys.length ? apiKeys.map((k) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(k.name)}</strong> <code class="muted">${esc(k.key_hint)}</code><br>
        ${String(k.rights || 'read').split(',').map((r) => `<span class="api-right ${r}">${esc(API_RIGHTS[r]?.[0] || r)}</span>`).join('')}<br>
        <span class="muted small">${k.allow_from ? `Alleen vanaf ${esc(k.allow_from)} · ` : ''}${apiKeyExpiry(k)} · laatst gebruikt: ${k.last_used ? ago(k.last_used) : 'nooit'}</span></span>
      <button class="icon-btn" title="Rechten, adressen en geldigheid" data-edit="${k.id}">✎</button>
      <button class="icon-btn" title="Intrekken" data-del="${k.id}" data-name="${esc(k.name)}">🗑</button>
    </div>`).join('') : '<p class="muted small">Nog geen sleutels.</p>';
  settingsSummaries();
}

/** ⛔ blokkeren via de API vraagt om bevestiging; daarna (of meteen) opslaan */
function apiKeyConfirm(title, data, save) {
  modal({
    title,
    body: `<p style="margin-top:0">Met het recht <b>⛔ IDS blokkeren</b> kan wie deze sleutel heeft IP-adressen blokkeren op je servers (als het IDS op <i>blokkeren</i> staat; in de leerstand wordt het alleen vastgelegd).
        Je LAN, RzMon zelf en de lijst <i>Nooit blokkeren</i> weigert de API altijd. Overweeg <i>Alleen vanaf</i> in te vullen.</p>
      ${confirmField('BLOKKEREN')}`,
    buttons: [{ label: 'Opslaan', cls: 'primary', submit: true }],
    onSubmit: (d) => save({ ...data, confirm: d.confirm }),
  });
}

async function createApiKey(e) {
  e.preventDefault();
  const form = e.target;
  const data = apiKeyData(form);
  if (!data.rights.length) return toast('Kies minstens één recht', 'err');
  const save = async (d) => {
    const r = await api('api_key_create', d);
    form.reset();
    $('#api-key-fields').innerHTML = apiKeyFields();
    loadApiKeys();
    setTimeout(() => newApiKeyDialog(r.key, d.rights), 0);
  };
  if (data.rights.includes('ids_block')) return apiKeyConfirm('⛔ Sleutel met blokkeerrecht', data, save);
  try { await save(data); } catch (ex) { toast(ex.message, 'err'); }
}

function apiKeyEdit(k) {
  const before = String(k.rights || '').split(',');
  modal({
    title: `✎ API-sleutel ${k.name}`,
    body: `<div class="api-key-form">${apiKeyFields(k)}<p class="muted small" style="margin:0">${apiKeyExpiry(k)}</p></div>`,
    buttons: [{ label: 'Opslaan', cls: 'primary', submit: true }],
    onSubmit: async (d, form) => {
      const data = { ...apiKeyData(form), id: k.id };
      if (!data.rights.length) throw new Error('Kies minstens één recht');
      const save = async (x) => { await api('api_key_update', x); toast('Sleutel aangepast'); loadApiKeys(); };
      if (data.rights.includes('ids_block') && !before.includes('ids_block')) {
        setTimeout(() => apiKeyConfirm('⛔ Blokkeerrecht toevoegen', data, save), 0);
        return;
      }
      await save(data);
    },
  });
}

function newApiKeyDialog(key, rights) {
  const auth = `-H "Authorization: Bearer ${esc(key)}"`;
  const ex = [];
  if (rights.includes('read')) ex.push(`curl ${auth} ${esc(apiBase())}/status`);
  if (rights.includes('ids_read')) ex.push(`curl ${auth} ${esc(apiBase())}/ids/incidents?status=open`);
  if (rights.includes('ids_block')) ex.push(`curl ${auth} -H "Content-Type: application/json" \\
  -d '{"host":"web01","ip":"203.0.113.7","hours":24,"reason":"fail2ban"}' ${esc(apiBase())}/ids/block`);
  if (rights.includes('checks')) ex.push(`curl ${auth} -X POST -d '{"comment":"bezig"}' ${esc(apiBase())}/checks/1/ack`);
  modal({
    title: 'Nieuwe API-sleutel',
    body: `<p>Kopieer deze sleutel nu — hij wordt <b>niet</b> opnieuw getoond.</p>
      <div class="new-key"><input id="new-key" readonly value="${esc(key)}"><button type="button" class="btn" id="copy-key">Kopieer</button></div>
      <p class="muted small" style="margin-top:1rem">Test:</p>
      <pre class="code">${ex.join('\n\n')}</pre>`,
    buttons: [{ label: 'Klaar', cls: 'primary', submit: true }],
  });
  $('#copy-key').onclick = async () => {
    $('#new-key').select();
    try { await navigator.clipboard.writeText(key); } catch (e) { document.execCommand('copy'); }
    toast('Gekopieerd');
  };
}

async function apiKeyClick(e) {
  const ed = e.target.closest('[data-edit]');
  if (ed) return apiKeyEdit(apiKeys.find((k) => String(k.id) === ed.dataset.edit));
  const b = e.target.closest('[data-del]');
  if (!b || !confirm(`API-sleutel "${b.dataset.name}" intrekken? Toepassingen die hem gebruiken verliezen direct toegang.`)) return;
  try { await api('api_key_delete', { id: b.dataset.del }); toast('Sleutel ingetrokken'); loadApiKeys(); }
  catch (ex) { toast(ex.message, 'err'); }
}

function isAdmin() { return document.body.dataset.role === 'admin'; }

async function loadSettings() {
  settingsLoaded = true;
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
  $('#ids-enabled').checked = s.ids_enabled === '1';
  $('#ids-mode').checked = s.ids_mode === 'block';
  fimGlobal = fimEditor($('#fim-editor'), parse(s.ids_fim || '[]'), null, () => { markSettingsDirty(true); settingsSummaries(); });
  idsModeInfo();
  backupFormToggle();
  markSettingsDirty(false);
  applySettingsLayout();
  loadBackups();
  loadUpdate(false);
  loadAgentCommands();
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
  settingsSummaries();
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
let fimGlobal = null;      // editor van de algemene lijst 'Bestanden bewaken' (IDS)
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
  if (fimGlobal) f.ids_fim.value = JSON.stringify(fimGlobal.get());
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
  settingsSummaries();
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
  settingsSummaries();
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
// ---------------------------------------------------------------- Commando-checks: aan/uit
const CMD_MODES = { off: 'uit', nobody: 'als nobody', root: 'als root' };
const CMD_MATCHES = { equals: 'is gelijk aan', contains: 'bevat', regex: 'komt overeen met (regex)', exit0: 'exitcode is 0' };
const CMD_RANK = { off: 0, nobody: 1, root: 2 };
const CMD_GROUPS = { truenas: '🗄️ TrueNAS SCALE / ZFS', proxmox: '🖥️ Proxmox VE', linux: '🐧 Linux' };
// Kant-en-klare commando-checks. rec: in "Aanbevolen checks" voor dat platform (true = standaard aangevinkt);
// os: alleen voor Debian/Ubuntu of Rocky/RHEL; root: werkt niet als nobody; tested: op een echt systeem getest.
const CMD_PRESETS = {
  zfs_health: {"group": "truenas", "label": "ZFS-pools gezond", "name": "ZFS-pools", "cmd": "zpool status -x", "match": "equals", "expect": "all pools are healthy", "fail": "crit", "root": false, "interval": 300, "rec": {"truenas": true, "proxmox": false}, "timeout": 10, "tested": false},
  zfs_cap: {"group": "truenas", "label": "ZFS-pools onder 80% vol", "name": "ZFS-capaciteit", "cmd": "zpool list -H -o capacity | tr -d '%' | sort -n | tail -1", "match": "regex", "expect": "^[0-7]?[0-9]$", "fail": "warn", "root": false, "interval": 300, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  zfs_scrub: {"group": "truenas", "label": "Scrub zonder fouten", "name": "ZFS-scrub", "cmd": "out=$(zpool status) || exit 2; ! grep -E 'repaired [1-9]|with [1-9][0-9]* errors|errors: [0-9]+ data errors' <<<\"$out\"", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 900, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  truenas_alerts: {"group": "truenas", "label": "Geen TrueNAS-meldingen (ERROR/CRITICAL)", "name": "TrueNAS-meldingen", "cmd": "midclt call alert.list | python3 -c 'import json,sys; a=[x for x in json.load(sys.stdin) if not x.get(\"dismissed\") and x.get(\"level\") in (\"ERROR\",\"CRITICAL\",\"ALERT\",\"EMERGENCY\")]; print(len(a)); [print((x.get(\"formatted\") or x.get(\"klass\") or \"\")[:120]) for x in a[:3]]'", "match": "equals", "expect": "0", "fail": "warn", "root": true, "interval": 300, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  truenas_apps: {"group": "truenas", "label": "Apps (Docker) draaien", "name": "TrueNAS-apps", "cmd": "s=$(docker ps -a --filter status=exited --filter status=dead --format '{{.Names}}') || exit 2; [ -z \"$s\" ] || { echo \"gestopt: $(echo $s | head -c 200)\"; exit 1; }", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"truenas": false}, "timeout": 10, "tested": false},
  pve_quorum: {"group": "proxmox", "label": "Cluster heeft quorum (alleen bij een cluster)", "name": "Proxmox-quorum", "cmd": "pvecm status | grep -q 'Quorate:.*Yes' && echo ok", "match": "equals", "expect": "ok", "fail": "crit", "root": true, "interval": 120, "rec": {"proxmox": false}, "timeout": 10, "tested": false},
  pve_storage: {"group": "proxmox", "label": "Opslag onder 85% vol", "name": "Proxmox-opslag", "cmd": "pvesm status | awk 'NR>1 { p=$7; gsub(\"%\",\"\",p); if (p+0 > 85) { print $1 \" \" $7; f=1 } } END { exit f }'", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  pve_backup: {"group": "proxmox", "label": "Laatste back-uptaak (vzdump) geslaagd", "name": "Proxmox-back-up", "cmd": "awk -F: '$6==\"vzdump\" {l=$0} END {n=split(l,a,\" \"); s=\"\"; for(i=3;i<=n;i++) s=s (i>3?\" \":\"\") a[i]; print (s==\"\"?\"geen back-uptaak gevonden\":s)}' /var/log/pve/tasks/index", "match": "equals", "expect": "OK", "fail": "crit", "root": true, "interval": 900, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  pve_ceph: {"group": "proxmox", "label": "Ceph gezond (alleen met Ceph)", "name": "Ceph", "cmd": "ceph health", "match": "equals", "expect": "HEALTH_OK", "fail": "crit", "root": true, "interval": 120, "rec": {"proxmox": false}, "timeout": 10, "tested": false},
  pve_updates: {"group": "proxmox", "label": "Geen Proxmox-updates", "name": "Proxmox-updates", "cmd": "apt list --upgradable 2>/dev/null | grep -c -E '^(pve|proxmox)'", "match": "equals", "expect": "0", "fail": "warn", "root": false, "interval": 3600, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  failed_units: {"group": "linux", "label": "Geen mislukte services", "name": "Mislukte services", "cmd": "! systemctl --failed --no-legend --plain | awk '{print $1}' | grep .", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 300, "rec": {"linux": true, "proxmox": true}, "timeout": 10, "tested": false},
  reboot_deb: {"group": "linux", "os": "debian", "label": "Geen herstart nodig (Debian/Ubuntu/Proxmox)", "name": "Herstart nodig", "cmd": "if [ -f /var/run/reboot-required ]; then echo \"herstart nodig\"; head -3 /var/run/reboot-required.pkgs 2>/dev/null; exit 1; fi", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true, "proxmox": true}, "timeout": 10, "tested": true},
  reboot_rhel: {"group": "linux", "os": "rhel", "label": "Geen herstart nodig (Rocky/RHEL)", "name": "Herstart nodig", "cmd": "needs-restarting -r", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 900, "rec": {"linux": true}, "timeout": 10, "tested": false},
  updates_deb: {"group": "linux", "os": "debian", "label": "Geen updates (Debian/Ubuntu)", "name": "Updates", "cmd": "apt list --upgradable 2>/dev/null | grep -c upgradable", "match": "equals", "expect": "0", "fail": "warn", "root": false, "interval": 3600, "rec": {"linux": true}, "timeout": 10, "tested": true},
  security_rhel: {"group": "linux", "os": "rhel", "label": "Geen beveiligingsupdates (Rocky/RHEL)", "name": "Beveiligingsupdates", "cmd": "dnf -q updateinfo list --security 2>/dev/null | wc -l", "match": "equals", "expect": "0", "fail": "warn", "root": true, "interval": 3600, "timeout": 60, "rec": {"linux": true}, "tested": false},
  ntp: {"group": "linux", "label": "Klok gesynchroniseerd (NTP)", "name": "Tijd (NTP)", "cmd": "timedatectl show -p NTPSynchronized --value", "match": "equals", "expect": "yes", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true, "proxmox": true, "truenas": true}, "timeout": 10, "tested": false},
  inodes: {"group": "linux", "label": "Inodes onder 90% (alle disks)", "name": "Inodes", "cmd": "df --output=ipcent -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null | tail -n +2 | tr -d ' %' | grep -v -- '-' | sort -n | tail -1", "match": "regex", "expect": "^[0-8]?[0-9]$", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true}, "timeout": 10, "tested": true},
  temp: {"group": "linux", "label": "CPU-temperatuur onder 70 °C", "name": "Temperatuur", "cmd": "cat /sys/class/thermal/thermal_zone0/temp", "match": "regex", "expect": "^([0-9]{1,4}|[1-6][0-9]{4})$", "fail": "warn", "root": false, "interval": 300, "rec": {}, "timeout": 10, "tested": false},
  mdadm: {"group": "linux", "label": "Software-RAID (mdadm) heel", "name": "RAID (mdadm)", "cmd": "if grep -q '\\[[U_]*_[U_]*\\]' /proc/mdstat 2>/dev/null; then grep -B1 '_' /proc/mdstat | head -4; exit 1; fi", "match": "exit0", "expect": "", "fail": "crit", "root": false, "interval": 300, "rec": {"linux": false}, "timeout": 10, "tested": true},
  smart: {"group": "linux", "label": "SMART: alle fysieke schijven gezond", "name": "SMART", "cmd": "fail=; for d in $(lsblk -dno NAME -e 7,11); do o=$(smartctl -H /dev/$d 2>/dev/null); if grep -qE \"overall-health|Health Status\" <<<\"$o\" && ! grep -qE \"PASSED|: OK\" <<<\"$o\"; then fail=\"$fail $d\"; fi; done; [ -z \"$fail\" ] || { echo \"geen OK van:$fail\"; exit 1; }", "match": "exit0", "expect": "", "fail": "crit", "root": true, "interval": 3600, "timeout": 30, "rec": {"proxmox": true, "linux": false}, "tested": false},
  docker: {"group": "linux", "label": "Docker-containers draaien", "name": "Docker-containers", "cmd": "s=$(docker ps -a --filter status=exited --filter status=dead --format '{{.Names}}') || exit 2; [ -z \"$s\" ] || { echo \"gestopt: $(echo $s | head -c 200)\"; exit 1; }", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"linux": false}, "timeout": 10, "tested": false},
};

/** Platform van een agent-server uit zijn servicelijst: TrueNAS, Proxmox of Linux, en Debian- of RHEL-familie. */
function detectPlatform(list) {
  const names = new Set((list || []).map((s) => s[0]));
  const platform = names.has('middlewared') ? 'truenas' : (names.has('pvedaemon') || names.has('pve-cluster')) ? 'proxmox' : 'linux';
  const os = names.has('apt-daily') || platform === 'proxmox' || platform === 'truenas' ? 'debian' : names.has('dnf-makecache') ? 'rhel' : null;
  return { platform, os };
}

/** Keuzelijst met voorinstellingen; de groep van dit platform bovenaan. */
function presetOptions(platform, selected = '') {
  const order = [platform, ...Object.keys(CMD_GROUPS).filter((g) => g !== platform)];
  return '<option value="">— Zelf invullen —</option>' + order.map((g) => `<optgroup label="${CMD_GROUPS[g]}">${Object.entries(CMD_PRESETS)
    .filter(([, p]) => p.group === g).map(([k, p]) => `<option value="${k}" ${k === selected ? 'selected' : ''}>${esc(p.label)}${p.root ? ' (root)' : ''}</option>`).join('')}</optgroup>`).join('');
}

/**
 * Commando's algemeen (hostId null) of per server zetten. Ruimer maken (uit → nobody → root) vraagt om je
 * wachtwoord; daarna terug naar wat erna moet gebeuren (then).
 */
async function setAgentCommands(hostId, mode, current, label, then) {
  const target = mode === 'global' ? null : mode;
  const widens = target !== null && CMD_RANK[target] > CMD_RANK[current];
  if (!widens) {
    try { await api('agent_commands_set', { host_id: hostId, mode }); toast('Opgeslagen'); } catch (ex) { toast(ex.message, 'err'); }
    return then();
  }
  modal({
    title: `🖥️ Commando's toestaan – ${label}`,
    body: `<p style="margin-top:0">Hiermee mag RzMon commando's laten uitvoeren op ${esc(label)}, <b>${esc(CMD_MODES[target])}</b>.
        Iedereen die in RzMon beheerder is, kan dan commando's op ${hostId ? 'deze server' : 'je agents'} draaien${target === 'root' ? ', <b>met volledige rechten</b>' : ''}.</p>
      <p class="muted small">Op een server zelf kun je het altijd blokkeren met <code>COMMANDS=0</code> (of <code>COMMANDS=nobody</code>) in de instellingen van de agent. Deze wijziging komt in het logboek.</p>
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: 'Toestaan', cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('agent_commands_set', { host_id: hostId, mode, confirm: d.confirm });
      toast('Opgeslagen');
      setTimeout(then, 50);
    },
  });
}

async function loadAgentCommands() {
  let r;
  try { r = await api('agent_commands'); } catch (ex) { return; }
  const sel = $('#agent-commands');
  sel.value = r.global;
  sel.dataset.current = r.global;
  const own = r.hosts.filter((x) => x.own);
  $('#agent-commands-hosts').innerHTML = own.length ? 'Per server anders: ' + own.map((x) => `<b>${esc(x.name)}</b> ${esc(CMD_MODES[x.own])}`).join(' · ') : '';
  $('#audit-log').innerHTML = r.log.length ? r.log.map((l) => `<div class="key-row"><span class="grow small"><b>${esc(l.action)}</b>: ${esc(l.detail)}<br>
      <span class="muted">${esc(fmtDateTime(l.created_at))} · ${esc(l.username)} · ${esc(l.ip)}</span></span></div>`).join('') : '<p class="muted small">Nog niets.</p>';
  settingsSummaries();
}

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
  updateInfo = u;
  settingsSummaries();
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
  settingsSummaries();
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
