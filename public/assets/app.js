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
  container: { label: 'Container (Podman)', target: 'Container', unit: null, ph: 'naam, of gebruiker/naam (rootless)', help: 'Eén container: kies hieronder wat je wilt meten. Kies de naam uit de lijst die de agent meldt.' },
  service: { label: 'Service',       target: 'Servicenaam',   unit: null, ph: 'nginx', help: 'systemd service moet "active" zijn. Op agent-servers: voeg de service toe aan SERVICES in rzmon-agent.sh. Home Assistant-agent: homeassistant, supervisor, updates of addon:<slug>.' },
};
// Container-checks: wat er gemeten wordt (eigen eenheid en drempels)
const METRICS = {
  status: { label: 'Status (draait, healthcheck, herstarts)', unit: null, help: 'OK als de container draait. CRITICAL als hij gestopt of ongezond (healthcheck) is. WARNING als hij gepauzeerd is, of in het afgelopen kwartier automatisch herstart is (crash).' },
  cpu:    { label: 'CPU-gebruik', unit: '%', warn: 80, crit: 95, help: 'CPU-gebruik zoals podman stats het toont: 100% = één volledige core (kan dus boven 100% komen).' },
  mem:    { label: 'Geheugen (RAM)', unit: '%', warn: 85, crit: 95, help: 'Percentage van de geheugenlimiet van de container, of van het totale geheugen als er geen limiet is.' },
  disk:   { label: 'Diskgebruik', unit: 'MB', warn: 5000, crit: 10000, help: 'Schrijflaag van de container plus de grootte van zijn volumes (volumes worden elk uur gemeten).' },
};
/** Type-beschrijving van een check; bij containers met de eenheid van de gekozen meting. */
function typeOf(c) {
  return c.type === 'container' ? { ...TYPES.container, ...METRICS[c.metric || 'status'], label: TYPES.container.label } : TYPES[c.type];
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
        return location.reload();
      }
      const r = await api('login', { username: loginForm.username.value, password: loginForm.password.value });
      if (!r.totp_required) return location.reload();
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
} else {
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
  $('#only-problems').addEventListener('change', render);
  $('#hosts').addEventListener('click', onHostsClick);
  $('#hosts').addEventListener('keydown', (e) => {
    const head = e.target.closest('.host-head[data-act=toggle]');
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
  $('#pushover-test').onclick = testPushover;
  $('#webhook-test').onclick = testWebhook;
  $('#email-test').onclick = (e) => testChannel(e, 'email_test', 'Testmail verstuurd 📧', 'E-mail');
  $('#mqtt-test').onclick = (e) => testChannel(e, 'mqtt_test', null, 'MQTT');
  $('#maint-all').onclick = () => maintenanceDialog('all', 0, 'alle servers', state.maintenance);
  $('#maint-banner').addEventListener('click', (e) => e.target.closest('[data-maint-end]') && endMaintenance('all', 0));
  $('#password-form').addEventListener('submit', changePassword);
  $('#user-form').addEventListener('submit', createUser);
  $('#users').addEventListener('click', onUsersClick);
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
    render();
    renderAlerts();
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
  return `🔧 tot ${fmtUntil(m.until)}${m.reason ? ' – ' + m.reason : ''}`;
}

function render() {
  const counts = [0, 0, 0, 0, 0];
  state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && counts[c.maintenance ? 4 : +c.status]++));
  ['ok', 'warn', 'crit', 'unknown', 'maint'].forEach((k, i) => ($('#sum-' + k).textContent = counts[i]));
  const banner = $('#maint-banner');
  banner.hidden = !state.maintenance;
  if (state.maintenance) {
    banner.innerHTML = `<span><b>🔧 Onderhoud actief</b> tot ${esc(fmtUntil(state.maintenance.until))}${state.maintenance.reason ? ' – ' + esc(state.maintenance.reason) : ''}.
      Checks draaien door, maar er gaan geen meldingen uit.</span>
      <button class="btn" data-maint-end="all">Beëindigen</button>`;
  }
  $('#last-run').textContent = state.last_run ? 'Laatste check: ' + ago(state.last_run) : '';
  let open = 0;
  state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && !c.maintenance && +c.status === 2 && !c.acked_at && open++));
  document.title = (open ? `(${open}) ` : '') + 'RzMon';

  const f = $('#filter').value.trim().toLowerCase();
  const onlyProblems = $('#only-problems').checked;
  const hosts = state.hosts
    .map((h) => {
      let checks = h.checks;
      const hostMatch = !f || (h.name + ' ' + h.address).toLowerCase().includes(f);
      if (!hostMatch) checks = checks.filter((c) => (c.name + ' ' + c.target).toLowerCase().includes(f));
      if (onlyProblems) checks = checks.filter((c) => +c.status !== 0 && +c.enabled);
      return { ...h, shown: checks, match: hostMatch, autoOpen: !!f && !hostMatch && checks.length > 0 };
    })
    .filter((h) => (h.match && !onlyProblems) || h.shown.length)
    .sort((a, b) => STATUS_RANK[hostStatus(b)] - STATUS_RANK[hostStatus(a)] || a.name.localeCompare(b.name));

  const el = $('#hosts');
  if (!state.hosts.length) {
    el.innerHTML = `<div class="card empty">Nog geen servers. Klik op <b>＋ Server</b> om te beginnen.</div>`;
    return;
  }
  if (!hosts.length) {
    el.innerHTML = `<div class="card empty">${onlyProblems ? '🎉 Geen problemen gevonden!' : 'Niets gevonden.'}</div>`;
    return;
  }
  el.innerHTML = hosts.map(hostCard).join('');
  $('#toggle-all').textContent = state.hosts.length && state.hosts.every((h) => openHosts.has(+h.id)) ? '⊟ Alles sluiten' : '⊞ Alles openen';
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

function hostCard(h) {
  const st = hostStatus(h);
  const sum = hostSummary(h);
  const isOpen = openHosts.has(+h.id) || h.autoOpen;
  const parent = h.parent_id ? findHost(h.parent_id) : null;
  const problems = h.checks
    .filter((c) => +c.enabled && +c.status !== 0)
    .sort((a, b) => STATUS_RANK[b.maintenance ? 4 : +b.status] - STATUS_RANK[a.maintenance ? 4 : +a.status]);
  const chips = problems.slice(0, 4).map((c) =>
    `<span class="pchip s${c.maintenance ? 4 : c.status} ${c.acked_at ? 'acked' : ''}" title="${esc(c.last_message || '')}">${c.acked_at ? '✔ ' : ''}${esc(c.name)}</span>`).join('')
    + (problems.length > 4 ? `<span class="pchip more">+${problems.length - 4}</span>` : '');
  const counts = [0, 1, 2, 3].map((s) => [s, h.checks.filter((c) => +c.enabled && !c.maintenance && +c.status === s).length])
    .filter(([, n]) => n).map(([s, n]) => `<span class="cnt s${s}" title="${STATUS[s]}">${n}</span>`).join('');
  const agentInfo = h.mode === 'agent'
    ? `<span class="muted small">Agent: ${h.agent_seen ? ago(h.agent_seen) : 'nog niet gezien'}</span>
       <button class="btn" data-act="agent" data-id="${h.id}">Agent installeren</button>` : '';
  const lastChecked = h.checks.reduce((m, c) => (c.last_checked && (!m || c.last_checked > m) ? c.last_checked : m), null);

  return `
  <article class="card host tile s${st} ${+h.enabled ? '' : 'disabled'} ${h.maintenance ? 'in-maint' : ''} ${isOpen ? 'open' : ''}">
    <div class="host-head" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${isOpen}"
         title="${isOpen ? 'Inklappen' : 'Klik voor alle checks'}">
      <span class="dot"></span>
      <div class="host-title">
        <strong>${esc(h.name)} <span class="badge">${esc(h.mode)}</span>${+h.enabled ? '' : '<span class="badge">uit</span>'}</strong>
        <span class="muted mono">${esc(h.address)}</span>${parent ? ` <span class="via" title="Afhankelijk van ${esc(parent.name)}: geen aparte meldingen als die onbereikbaar is">↳ via ${esc(parent.name)}</span>` : ''}
        ${h.maintenance ? `<span class="maint-tag">${esc(maintLabel(h.maintenance))}</span>` : ''}
      </div>
      <span class="counts">${counts}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    <div class="host-sum" data-act="toggle" data-id="${h.id}">
      <div class="sum-line ${sum.cls}">${esc(sum.text)}</div>
      ${chips ? `<div class="chips">${chips}</div>` : ''}
      ${hostMeters(h) ? `<div class="meters">${hostMeters(h)}</div>` : ''}
      <div class="muted small sum-foot">${lastChecked ? 'Laatste check ' + ago(lastChecked) : ''}${h.mode === 'agent' ? ` · agent ${h.agent_seen ? ago(h.agent_seen) : 'nog niet gezien'}${agentVer(h)}` : ''}${uptimeLine(h, true)}</div>
    </div>
    ${isOpen ? `
    <div class="host-detail">
      <div class="detail-bar">
        ${h.checks.some((c) => +c.enabled && +c.status !== 0 && !c.acked_at) ? `<button class="btn ack-btn" data-act="ack-host" data-id="${h.id}">✔ Alles bevestigen</button>` : ''}
        <button class="btn" data-act="maint-host" data-id="${h.id}">🔧 Onderhoud</button>
        <button class="btn" data-act="add-check" data-id="${h.id}">＋ Check</button>
        <button class="btn" data-act="scan" data-id="${h.id}">🔍 Poorten</button>
        ${h.metrics.includes('podman') ? `<button class="btn" data-act="podman" data-id="${h.id}">📦 Containers</button>` : ''}
        <button class="btn" data-act="edit-host" data-id="${h.id}">✎ Bewerken</button>
        ${agentInfo ? `<span class="spacer"></span>${agentInfo}` : ''}
      </div>
      ${uptimeLine(h, false)}
      <ul class="checks">
        ${h.shown.length ? h.shown.map(checkRow).join('') : '<li class="empty small">Nog geen checks — klik op ＋ Check</li>'}
      </ul>
    </div>` : ''}
  </article>`;
}

function checkRow(c) {
  const t = typeOf(c);
  const s = +c.status;
  let bar = '';
  if (t.unit === '%' && c.last_value !== null) {
    const v = Math.min(100, +c.last_value);
    bar = `<div class="bar" title="${fmt(v, '%')}"><i style="width:${v}%"></i>
      ${c.warn_threshold !== null ? `<b style="left:${+c.warn_threshold}%"></b>` : ''}
      ${c.crit_threshold !== null ? `<b style="left:${+c.crit_threshold}%"></b>` : ''}</div>`;
  }
  const value = ['ms', 'dagen', 'MB'].includes(t.unit) && c.last_value !== null ? ` · ${t.unit === 'dagen' ? Math.floor(c.last_value) + ' dagen' : fmt(c.last_value, t.unit)}` : '';
  const own = c.maintenance && c.maintenance.scope === 'check';
  const problem = s !== 0 && +c.enabled;
  const ackTitle = c.acked_at ? `Bevestigd door ${c.acked_by || '?'} – ${ago(c.acked_at)}${c.ack_comment ? ': ' + c.ack_comment : ''}. Klik om in te trekken.` : '';
  const flags = (problem && c.acked_at ? ` <button class="ack-tag" data-act="unack" data-id="${c.id}" title="${esc(ackTitle)}">✔ bevestigd</button>` : '')
    + (own ? ` <span class="maint-tag" title="${esc(maintLabel(c.maintenance))}">🔧 tot ${esc(fmtUntil(c.maintenance.until))}</span>` : '')
    + (c.suppressed_by ? ' <span class="supp-tag" title="Geen melding: een server erboven is onbereikbaar">🔕 onderdrukt</span>' : '');
  return `
  <li class="chk s${s} ${+c.enabled ? '' : 'disabled'} ${c.maintenance ? 'in-maint' : ''} ${problem && c.acked_at ? 'acked' : ''}" title="${STATUS[s]} – laatst ${ago(c.last_checked)}">
    <span class="dot"></span>
    <span class="chk-name">${esc(c.name)}<span class="type">${t.label}${value}</span>${flags}</span>
    <span class="chk-actions">
      ${problem && !c.acked_at ? `<button class="icon-btn ack-btn" title="Storing bevestigen: geen meldingen meer" data-act="ack" data-id="${c.id}">✔</button>` : ''}
      <button class="icon-btn" title="Onderhoud" data-act="maint-check" data-id="${c.id}">🔧</button>
      <button class="icon-btn" title="Nu checken" data-act="run" data-id="${c.id}">↻</button>
      <button class="icon-btn" title="Historie" data-act="history" data-id="${c.id}">📈</button>
      <button class="icon-btn" title="Bewerken" data-act="edit-check" data-id="${c.id}">✎</button>
    </span>
    ${bar}
    ${sparkSvg(c)}
    <span class="chk-msg">${esc(c.last_message || 'Nog niet gecontroleerd')} · ${ago(c.last_checked)}</span>
  </li>`;
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
  const allowed = h.mode === 'remote' ? ['ping', 'tcp', 'http', 'ssl']
    : Object.keys(TYPES).filter((k) => h.mode === 'agent' || !['podman', 'container'].includes(k));
  const disks = h.metrics.filter((m) => m.startsWith('disk:')).map((m) => m.slice(5));
  const svcs = h.metrics.filter((m) => m.startsWith('service:')).map((m) => m.slice(8));
  const certs = h.metrics.filter((m) => m.startsWith('cert:')).map((m) => m.slice(5));
  const containers = h.metrics.filter((m) => m.startsWith('container:')).map((m) => m.slice(10));

  const { form } = modal({
    title: isNew ? `Check toevoegen – ${h.name}` : `Check bewerken – ${c.name}`,
    body: `
      <label>Type
        <select name="type">${allowed.map((k) => `<option value="${k}" ${c.type === k ? 'selected' : ''}>${TYPES[k].label}</option>`).join('')}</select>
        <span class="hint" id="type-help"></span>
      </label>
      <label id="target-wrap"><span id="target-label"></span><input name="target" value="${esc(c.target)}" list="target-list" class="mono"></label>
      <label id="metric-wrap" hidden>Meten
        <select name="metric">${Object.entries(METRICS).map(([k, m]) => `<option value="${k}" ${(c.metric || 'status') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="metric-help"></span>
      </label>
      <datalist id="target-list"></datalist>
      <label>Naam <span class="hint">(optioneel)</span><input name="name" value="${esc(c.name)}"></label>
      <div class="row3" id="thr-wrap">
        <label><span class="thr-word">Warning vanaf</span> <span class="hint unit"></span><input name="warn_threshold" type="number" step="any" value="${c.warn_threshold ?? ''}"></label>
        <label><span class="thr-word">Critical vanaf</span> <span class="hint unit"></span><input name="crit_threshold" type="number" step="any" value="${c.crit_threshold ?? ''}"></label>
        <label>Interval <span class="hint">sec</span><input name="interval_sec" type="number" min="30" step="30" value="${c.interval_sec}"></label>
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
      await api('check_save', { ...d, id: c.id, host_id: h.id, enabled: !!d.enabled, verify_ssl: !!d.verify_ssl });
      toast('Check opgeslagen');
    },
  });

  const typeSel = $('[name=type]', form);
  const metricSel = $('[name=metric]', form);
  const update = (setDefaults) => {
    const isContainer = typeSel.value === 'container';
    const t = isContainer ? { ...TYPES.container, ...METRICS[metricSel.value] } : TYPES[typeSel.value];
    $('#type-help').textContent = TYPES[typeSel.value].help;
    $('#metric-wrap').hidden = !isContainer;
    $('#metric-help').textContent = isContainer ? METRICS[metricSel.value].help : '';
    $('#target-wrap').hidden = !t.target;
    $('#target-label').textContent = t.target || '';
    $('[name=target]', form).placeholder = t.ph || '';
    $$('.unit', form).forEach((u) => (u.textContent = t.unit || ''));
    $$('#thr-wrap label', form).slice(0, 2).forEach((l) => (l.hidden = !t.unit));
    const words = $$('.thr-word', form);
    words[0].textContent = t.low ? 'Warning onder' : 'Warning vanaf';
    words[1].textContent = t.low ? 'Critical onder' : 'Critical vanaf';
    $('#verify-wrap').hidden = !['http', 'ssl'].includes(typeSel.value);
    $('[name=target]', form).required = !!t.target && !['http', 'ssl'].includes(typeSel.value);
    const list = { disk: disks, service: svcs, ssl: certs, container: containers }[typeSel.value] || [];
    $('#target-list').innerHTML = list.map((v) => `<option value="${esc(v)}">`).join('');
    if (setDefaults) {
      $('[name=warn_threshold]', form).value = t.warn ?? '';
      $('[name=crit_threshold]', form).value = t.crit ?? '';
      if (setDefaults !== 'metric') $('[name=target]', form).value = typeSel.value === 'disk' ? '/' : '';
    }
  };
  typeSel.onchange = () => update(true);
  metricSel.onchange = () => update('metric');
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
      <div id="chart-svg"><p class="muted">Laden…</p></div></div>`,
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
    $('#uptime').innerHTML = d.uptime === null ? 'Geen data' : `Beschikbaarheid: <b>${pct(d.uptime)}</b>${mma}${fc}`;
    $('#chart-svg').innerHTML = chartSvg(d.points, c, t, +hours);
  };
  $('#range').onchange = load;
  load();
}

function chartSvg(pts, c, t, hours) {
  if (!pts.length) return '<p class="muted">Nog geen meetgegevens.</p>';
  const W = 860, H = 260, L = 48, R = 12, T = 12, B = 40;
  const t1 = Math.floor(Date.now() / 1000), t0 = t1 - hours * 3600;
  const x = (ts) => L + ((ts - t0) / (t1 - t0)) * (W - L - R);
  const vals = pts.map((p) => p.v).filter((v) => v !== null);
  const hasVals = t.unit && vals.length;
  let max = hasVals ? Math.max(...vals, +c.crit_threshold || 0, +c.warn_threshold || 0) * 1.1 : 1;
  if (t.unit === '%') max = 100;
  const y = (v) => T + (1 - v / max) * (H - T - B - 14);
  let svg = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img">`;

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
          : `<button type="button" class="btn" data-port="${p.port}" data-service="${esc(p.service || '')}">＋ Check</button>`}
          ${tls ? (certMon.has(+p.port) ? ' <span class="muted small">✓ cert</span>'
            : ` <button type="button" class="btn" data-cert="${p.port}" title="Certificaat bewaken (vervaldatum)">＋ Cert</button>`) : ''}</td></tr>`;
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
  const has = (key, metric) => d.checks.some((c) => c.type === 'container' && c.target === key && (c.metric || 'status') === metric);
  const stateCls = (c) => (c.state === 'running' ? (c.health === 'unhealthy' ? 's2' : 's0') : c.state === 'paused' ? 's1' : +c.exit_code ? 's2' : 's3');
  const stateText = (c) => (c.state === 'running' ? 'draait' + (c.health ? ` (${c.health === 'healthy' ? 'gezond' : c.health === 'unhealthy' ? 'ONGEZOND' : 'start op'})` : '')
    : c.state === 'paused' ? 'gepauzeerd' : c.state === 'exited' ? `gestopt (exit ${c.exit_code})` : c.state);
  const rows = d.containers.map((c) => {
    const memPct = c.mem !== null && c.mem_limit ? (c.mem / c.mem_limit) * 100 : null;
    const disk = (c.size_rw || 0) + (c.volumes || 0);
    const btn = (metric, label, title) => (has(c.key, metric) ? `<span class="muted small" title="Wordt bewaakt">✓ ${label}</span>`
      : `<button type="button" class="btn small-btn" data-pc="${esc(c.key)}" data-metric="${metric}" title="${title}">＋ ${label}</button>`);
    return `<tr>
      <td><span class="dot-inline ${stateCls(c)}"></span><b>${esc(c.key)}</b>${c.pod ? `<br><span class="muted small">pod ${esc(c.pod)}</span>` : ''}
        <br><span class="muted small mono">${esc(c.image)}</span></td>
      <td>${esc(stateText(c))}${+c.restarts ? `<br><span class="muted small">${c.restarts}× herstart</span>` : ''}</td>
      <td class="num">${c.cpu !== null ? (Math.round(c.cpu * 10) / 10).toString().replace('.', ',') + '%' : '–'}</td>
      <td class="num">${c.mem !== null ? fmtBytes(c.mem) : '–'}${memPct !== null ? `<br><span class="muted small">${memPct.toFixed(1).replace('.', ',')}% van ${fmtBytes(c.mem_limit)}</span>` : ''}</td>
      <td class="num">${fmtBytes(disk)}${c.volumes ? `<br><span class="muted small">volumes ${fmtBytes(c.volumes)}</span>` : ''}</td>
      <td class="pc-actions">${btn('status', 'Status', 'Melding als de container stopt, ongezond wordt of herstart')}
        ${btn('cpu', 'CPU', 'CPU-gebruik bewaken')} ${btn('mem', 'RAM', 'Geheugengebruik bewaken')} ${btn('disk', 'Disk', 'Diskgebruik bewaken')}</td>
    </tr>`;
  }).join('');
  const missing = d.containers.filter((c) => !has(c.key, 'status'));
  body.innerHTML = `
    <p class="scan-summary">Podman ${esc(sm.version || '?')} · ${d.containers.filter((c) => c.state === 'running').length} van ${d.containers.length} containers draaien
      ${(sm.pods || []).length ? ' · pods: ' + sm.pods.map((p) => `${esc(p.name)} (${esc(p.status)})`).join(', ') : ''}
      <br><span class="muted small">Images ${fmtBytes(sm.images_size)} · containers ${fmtBytes(sm.containers_size)} · volumes ${fmtBytes(sm.volumes_size)}
      ${sm.reclaimable ? ` · ${fmtBytes(sm.reclaimable)} op te ruimen (<code>podman system prune</code>)` : ''} · gemeld ${ago(d.updated_at)}
      ${(sm.users || []).length > 1 ? ' · gebruikers: ' + sm.users.map(esc).join(', ') : ''}</span></p>
    ${sm.error ? `<p class="form-error">Podman-fout: ${esc(sm.error)}</p>` : ''}
    <div class="podman-actions">
      ${d.checks.some((c) => c.type === 'podman') ? '<span class="muted small">✓ Podman-check aanwezig</span>'
        : '<button type="button" class="btn" id="pc-overall">＋ Podman-check (algehele status)</button>'}
      ${missing.length ? `<button type="button" class="btn" id="pc-all">＋ Status-check voor ${missing.length === d.containers.length ? 'alle' : 'de overige'} ${missing.length} container(s)</button>` : ''}
    </div>
    <div class="table-scroll"><table class="port-table podman-table">
      <thead><tr><th>Container</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>Bewaken</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="6" class="muted">Geen containers.</td></tr>'}</tbody></table></div>
    <p class="muted small">CPU zoals <code>podman stats</code>: 100% = één core. Disk = schrijflaag van de container + zijn volumes.
      Containers van gewone gebruikers (rootless) staan als <code>gebruiker/naam</code>.</p>`;

  const add = async (payload) => api('check_save', { host_id: h.id, interval_sec: 60, enabled: true, ...payload });
  const addContainer = (key, metric) => add({ type: 'container', target: key, metric, name: '',
    warn_threshold: METRICS[metric].warn ?? '', crit_threshold: METRICS[metric].crit ?? '' });
  body.onclick = async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    b.disabled = true;
    try {
      if (b.id === 'pc-overall') await add({ type: 'podman', name: 'Podman' });
      else if (b.id === 'pc-all') for (const c of missing) await addContainer(c.key, 'status');
      else if (b.dataset.pc) await addContainer(b.dataset.pc, b.dataset.metric);
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
    title: `Agent installeren – ${h.name}`,
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
      <p class="muted small">Daarna: klik hier op <b>＋</b> en voeg checks toe (CPU, RAM, diskruimte, services). Later bijwerken: <code>sudo rzmon-agent-setup --update</code> · verwijderen: <code>sudo rzmon-agent-setup --uninstall</code>.</p>
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
SERVICES="ssh cron nginx mariadb"
CERTS="/etc/letsencrypt/live/*/cert.pem localhost:8443"
EOF
sudo chmod 600 /etc/default/rzmon-agent
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh &gt;/dev/null 2&gt;&amp;1' | sudo tee /etc/cron.d/rzmon-agent
# Testen:
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'</pre>
      <p class="muted small"><code>CERTS</code>: certificaatbestanden en/of <code>host:poort</code> (ook op internet) waarvan de agent de vervaldatum meldt. Pas <code>SERVICES</code> aan naar de services die je wilt bewaken. Na de eerste rapportage kun je bij "Check toevoegen" de mounts en services uit een lijst kiezen.</p>
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
  if (!h.agent_version) return h.agent_seen ? ' · versie onbekend ⬆' : '';
  return ` · v${esc(h.agent_version)}${state.version && verCmp(h.agent_version, state.version) < 0 ? ' ⬆' : ''}`;
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
      html += `<div class="small">Agent-versie <b>${s.agent_version ? esc(s.agent_version) : 'onbekend (ouder dan 1.6.0)'}</b>, RzMon ${esc(s.version)}${old
        ? ` — <span class="bad">bijwerken met <code>sudo rzmon-agent-setup --update</code></span>` : ' ✔'}</div>`;
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
    await api('access_save', { lan_no_login: f.lan_no_login.checked, trusted_networks: f.trusted_networks.value, trusted_proxies: f.trusted_proxies.value });
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

async function loadSettings() {
  loadApiKeys();
  loadUsers();
  loadAccess();
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
}

function settingsData() {
  const f = $('#settings-form');
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
async function loadUsers() {
  users = await api('users');
  $('#users').innerHTML = users.map((u) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(u.username)}</strong>${u.me ? ' <span class="badge">jij</span>' : ''}
        ${u.totp ? '<span class="tfa on" title="Tweestapsverificatie staat aan">🔐 2FA aan</span>' : '<span class="tfa" title="Alleen wachtwoord">2FA uit</span>'}<br>
        <span class="muted small">Laatst ingelogd: ${u.last_login ? ago(u.last_login) : 'nooit'}</span></span>
      <span class="row-actions">
        ${u.totp
          ? `<button class="btn" data-uact="totp-reset" data-id="${u.id}" title="Tweestapsverificatie uitzetten, bv. bij een verloren telefoon">2FA resetten</button>`
          : `<button class="btn" data-uact="totp-setup" data-id="${u.id}">🔐 2FA instellen</button>`}
        <button class="icon-btn" data-uact="password" data-id="${u.id}" title="Nieuw wachtwoord instellen">🔑</button>
        ${u.me ? '' : `<button class="icon-btn" data-uact="delete" data-id="${u.id}" title="Verwijderen">🗑</button>`}
      </span>
    </div>`).join('');
}

async function createUser(e) {
  e.preventDefault();
  const f = e.target;
  try {
    const r = await api('user_create', { username: f.username.value, password: f.password.value });
    const name = f.username.value;
    const withTotp = f.totp.checked;
    f.reset();
    toast(`Gebruiker ${name} aangemaakt`);
    await loadUsers();
    if (withTotp) totpSetup(r.id, name);
  } catch (ex) { toast(ex.message, 'err'); }
}

async function onUsersClick(e) {
  const b = e.target.closest('[data-uact]');
  if (!b) return;
  const u = users.find((x) => +x.id === +b.dataset.id);
  try {
    switch (b.dataset.uact) {
      case 'totp-setup': return totpSetup(u.id, u.username);
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
