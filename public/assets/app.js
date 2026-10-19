'use strict';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const CSRF = $('meta[name=csrf-token]')?.content;
const STATUS = ['OK', 'WARNING', 'CRITICAL', 'ONBEKEND'];

const TYPES = {
  ping:    { label: 'Ping',          target: null,            unit: 'ms', warn: 100, crit: 500, help: 'Gemiddelde responstijd van 3 pings. Geen antwoord = CRITICAL.' },
  tcp:     { label: 'TCP poort',     target: 'Poort',         unit: 'ms', warn: 500, crit: 2000, ph: '22', help: 'Controleert of de poort open staat (bv. 22 SSH, 3306 MariaDB, 443 HTTPS).' },
  http:    { label: 'HTTP(S)',       target: 'URL of pad',    unit: 'ms', warn: 1000, crit: 3000, ph: 'https://example.com/ of /status', help: 'HTTP status ≥ 400 = CRITICAL. SSL-certificaat < 14 dagen = WARNING.' },
  disk:    { label: 'Diskruimte',    target: 'Mount / pad',   unit: '%',  warn: 80, crit: 90, ph: '/', help: 'Percentage gebruikt. Op agent-servers: mount zoals de agent meldt.' },
  cpu:     { label: 'CPU',           target: null,            unit: '%',  warn: 85, crit: 95, help: 'CPU-gebruik gemeten over 2 seconden, met load average in het bericht. Tip: zet "alarm na N fouten" op 3+ om korte pieken te negeren.' },
  ram:     { label: 'RAM',           target: null,            unit: '%',  warn: 85, crit: 95, help: 'Percentage geheugen in gebruik (op basis van MemAvailable, dus cache telt niet mee). Swap staat in het bericht.' },
  service: { label: 'Service',       target: 'Servicenaam',   unit: null, ph: 'nginx', help: 'systemd service moet "active" zijn. Op agent-servers: voeg de service toe aan SERVICES in rzmon-agent.sh. Home Assistant-agent: homeassistant, supervisor, updates of addon:<slug>.' },
};
const MODES = { local: 'Lokaal (deze server)', agent: 'Remote met agent', remote: 'Remote (alleen netwerk)' };

let state = { hosts: [], alerts: [] };

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
  if (res.status === 401 && action !== 'login') { location.reload(); }
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
    try {
      await api('login', Object.fromEntries(new FormData(loginForm)));
      location.reload();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
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

  $('#settings-form').addEventListener('submit', saveSettings);
  $('#pushover-test').onclick = testPushover;
  $('#password-form').addEventListener('submit', changePassword);
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
}

function hostStatus(h) {
  const active = h.checks.filter((c) => +c.enabled);
  if (!active.length) return 3;
  const sts = active.map((c) => +c.status);
  if (sts.includes(2)) return 2;
  if (sts.includes(1)) return 1;
  if (sts.includes(3)) return 3;
  return 0;
}

function render() {
  const counts = [0, 0, 0, 0];
  state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && counts[+c.status]++));
  ['ok', 'warn', 'crit', 'unknown'].forEach((k, i) => ($('#sum-' + k).textContent = counts[i]));
  $('#last-run').textContent = state.last_run ? 'Laatste check: ' + ago(state.last_run) : '';
  document.title = (counts[2] ? `(${counts[2]}) ` : '') + 'RzMon';

  const f = $('#filter').value.trim().toLowerCase();
  const onlyProblems = $('#only-problems').checked;
  const hosts = state.hosts
    .map((h) => {
      let checks = h.checks;
      const hostMatch = !f || (h.name + ' ' + h.address).toLowerCase().includes(f);
      if (!hostMatch) checks = checks.filter((c) => (c.name + ' ' + c.target).toLowerCase().includes(f));
      if (onlyProblems) checks = checks.filter((c) => +c.status !== 0 && +c.enabled);
      return { ...h, shown: checks, match: hostMatch };
    })
    .filter((h) => (h.match && !onlyProblems) || h.shown.length)
    .sort((a, b) => (hostStatus(b) % 3) - (hostStatus(a) % 3) || a.name.localeCompare(b.name));

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
}

function hostCard(h) {
  const st = hostStatus(h);
  const agentInfo = h.mode === 'agent'
    ? `<span class="muted">Agent: ${h.agent_seen ? ago(h.agent_seen) : 'nog niet gezien'}</span>
       <button class="btn" data-act="agent" data-id="${h.id}">Agent installeren</button>` : '';
  return `
  <article class="card host s${st} ${+h.enabled ? '' : 'disabled'}">
    <div class="host-head">
      <span class="dot"></span>
      <div class="host-title">
        <strong>${esc(h.name)} <span class="badge">${esc(h.mode)}</span>${+h.enabled ? '' : '<span class="badge">uit</span>'}</strong>
        <span class="muted mono">${esc(h.address)}</span>
      </div>
      <button class="icon-btn" title="Check toevoegen" data-act="add-check" data-id="${h.id}">＋</button>
      <button class="icon-btn" title="Poorten scannen" data-act="scan" data-id="${h.id}">🔍</button>
      <button class="icon-btn" title="Server bewerken" data-act="edit-host" data-id="${h.id}">✎</button>
    </div>
    <ul class="checks">
      ${h.shown.length ? h.shown.map(checkRow).join('') : '<li class="empty small">Nog geen checks — klik op ＋</li>'}
    </ul>
    ${agentInfo ? `<div class="host-foot">${agentInfo}</div>` : ''}
  </article>`;
}

function checkRow(c) {
  const t = TYPES[c.type];
  const s = +c.status;
  let bar = '';
  if (t.unit === '%' && c.last_value !== null) {
    const v = Math.min(100, +c.last_value);
    bar = `<div class="bar" title="${fmt(v, '%')}"><i style="width:${v}%"></i>
      ${c.warn_threshold !== null ? `<b style="left:${+c.warn_threshold}%"></b>` : ''}
      ${c.crit_threshold !== null ? `<b style="left:${+c.crit_threshold}%"></b>` : ''}</div>`;
  }
  const value = t.unit === 'ms' && c.last_value !== null ? ` · ${fmt(c.last_value, t.unit)}` : '';
  return `
  <li class="chk s${s} ${+c.enabled ? '' : 'disabled'}" title="${STATUS[s]} – laatst ${ago(c.last_checked)}">
    <span class="dot"></span>
    <span class="chk-name">${esc(c.name)}<span class="type">${t.label}${value}</span></span>
    <span class="chk-actions">
      <button class="icon-btn" title="Nu checken" data-act="run" data-id="${c.id}">↻</button>
      <button class="icon-btn" title="Historie" data-act="history" data-id="${c.id}">📈</button>
      <button class="icon-btn" title="Bewerken" data-act="edit-check" data-id="${c.id}">✎</button>
    </span>
    ${bar}
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

// ---------------------------------------------------------------- Check form
function checkForm(c, h) {
  const isNew = !c;
  c = c || { type: 'ping', target: '', name: '', warn_threshold: TYPES.ping.warn, crit_threshold: TYPES.ping.crit, interval_sec: 60, enabled: 1 };
  const allowed = h.mode === 'remote' ? ['ping', 'tcp', 'http'] : Object.keys(TYPES);
  const disks = h.metrics.filter((m) => m.startsWith('disk:')).map((m) => m.slice(5));
  const svcs = h.metrics.filter((m) => m.startsWith('service:')).map((m) => m.slice(8));

  const { form } = modal({
    title: isNew ? `Check toevoegen – ${h.name}` : `Check bewerken – ${c.name}`,
    body: `
      <label>Type
        <select name="type">${allowed.map((k) => `<option value="${k}" ${c.type === k ? 'selected' : ''}>${TYPES[k].label}</option>`).join('')}</select>
        <span class="hint" id="type-help"></span>
      </label>
      <label id="target-wrap"><span id="target-label"></span><input name="target" value="${esc(c.target)}" list="target-list" class="mono"></label>
      <datalist id="target-list"></datalist>
      <label>Naam <span class="hint">(optioneel)</span><input name="name" value="${esc(c.name)}"></label>
      <div class="row3" id="thr-wrap">
        <label>Warning vanaf <span class="hint unit"></span><input name="warn_threshold" type="number" step="any" value="${c.warn_threshold ?? ''}"></label>
        <label>Critical vanaf <span class="hint unit"></span><input name="crit_threshold" type="number" step="any" value="${c.crit_threshold ?? ''}"></label>
        <label>Interval <span class="hint">sec</span><input name="interval_sec" type="number" min="30" step="30" value="${c.interval_sec}"></label>
      </div>
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
      await api('check_save', { ...d, id: c.id, host_id: h.id, enabled: !!d.enabled });
      toast('Check opgeslagen');
    },
  });

  const typeSel = $('[name=type]', form);
  const update = (setDefaults) => {
    const t = TYPES[typeSel.value];
    $('#type-help').textContent = t.help;
    $('#target-wrap').hidden = !t.target;
    $('#target-label').textContent = t.target || '';
    $('[name=target]', form).placeholder = t.ph || '';
    $('[name=target]', form).required = !!t.target && typeSel.value !== 'http';
    $$('.unit', form).forEach((u) => (u.textContent = t.unit || ''));
    $$('#thr-wrap label', form).slice(0, 2).forEach((l) => (l.hidden = !t.unit));
    const list = typeSel.value === 'disk' ? disks : typeSel.value === 'service' ? svcs : [];
    $('#target-list').innerHTML = list.map((v) => `<option value="${esc(v)}">`).join('');
    if (setDefaults) {
      $('[name=warn_threshold]', form).value = t.warn ?? '';
      $('[name=crit_threshold]', form).value = t.crit ?? '';
      $('[name=target]', form).value = typeSel.value === 'disk' ? '/' : '';
    }
  };
  typeSel.onchange = () => update(true);
  update(false);
}

// ---------------------------------------------------------------- History
async function showHistory(c, h) {
  const t = TYPES[c.type];
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
    $('#uptime').innerHTML = d.uptime === null ? 'Geen data' : `Beschikbaarheid: <b>${d.uptime.toFixed(2)}%</b> · ${d.points.length} meetpunten`;
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
    const row = (p, extra = '') => {
      const mon = r.monitored.includes(+p.port);
      return `<tr><td class="num">${p.port}</td><td>${esc(p.service || '–')}</td>${extra}
        <td style="text-align:right">${mon ? '<span class="muted small">✓ bewaakt</span>'
          : `<button type="button" class="btn" data-port="${p.port}" data-service="${esc(p.service || '')}">＋ Check</button>`}</td></tr>`;
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

async function addPortCheck(e, h) {
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

// ---------------------------------------------------------------- Agent
function agentInstructions(h) {
  const base = state.base_url || location.origin + location.pathname.replace(/\/[^/]*$/, '');
  const scriptUrl = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api.php?action=agent_script';
  modal({
    title: `Agent installeren – ${h.name}`,
    wide: true,
    body: `
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
      <p>Voer dit uit op <b>${esc(h.name)}</b>. De agent stuurt elke minuut diskgebruik en service-status naar de monitor.</p>
      <p class="muted small">1. Download <code>agent/rzmon-agent.sh</code> uit de projectmap (of via je browser: <a href="${esc(scriptUrl)}" target="_blank">rzmon-agent.sh</a>) en kopieer het naar de server.</p>
      <p class="muted small">2. Installeer en configureer:</p>
      <pre class="code">sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
sudo tee /etc/default/rzmon-agent &gt;/dev/null &lt;&lt;'EOF'
MONITOR_URL="${esc(base)}"
TOKEN="${esc(h.agent_token || '')}"
SERVICES="ssh cron nginx mariadb"
EOF
sudo chmod 600 /etc/default/rzmon-agent
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh &gt;/dev/null 2&gt;&amp;1' | sudo tee /etc/cron.d/rzmon-agent
# Testen:
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'</pre>
      <p class="muted small">Pas <code>SERVICES</code> aan naar de services die je wilt bewaken. Na de eerste rapportage kun je bij "Check toevoegen" de mounts en services uit een lijst kiezen.</p>
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
  // Raad Home Assistant bij een naam of agent-gegevens die erop wijzen
  if (/home.?assistant|hass|haos/i.test(h.name) || h.metrics.includes('service:homeassistant')) show('ha');
}

// ---------------------------------------------------------------- Alerts
function renderAlerts() {
  const el = $('#alerts');
  if (!state.alerts.length) { el.innerHTML = '<p class="muted">Nog geen meldingen. 👌</p>'; return; }
  el.innerHTML = state.alerts.map((a) => `
    <div class="alert-item s${a.status}">
      <span class="dot"></span>
      <strong>${esc(a.title)}</strong>
      <span class="muted small" title="${esc(a.created_at)}">${ago(a.created_at)} ${+a.sent_ok ? '📲' : '<span title="Niet via Pushover verzonden">✉️✕</span>'}</span>
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
  loadAccess();
  const s = await api('settings');
  const f = $('#settings-form');
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

async function changePassword(e) {
  e.preventDefault();
  try {
    await api('password', Object.fromEntries(new FormData(e.target)));
    e.target.reset();
    toast('Wachtwoord gewijzigd');
  } catch (ex) { toast(ex.message, 'err'); }
}
