// ---------------------------------------------------------------- Kwetsbaarheden
const VULN_SEV = [['⚪', L('onbekend')], ['🔵', 'low'], ['🟡', 'medium'], ['🟠', 'high'], ['🔴', 'critical']];
let vulnData = null;
let vulnFilter = 'all';
let vulnSharedErr = new Set();   // scanfouten die als één melding bovenaan staan
// Open of dicht (1.93.0): standaard dicht; wat je open- of dichtklapt, onthoudt RzSense per account (h: servers, e: e-mailadressen).
// Met zoeken of een filter gaan de blokken met treffers tijdelijk open (vulnTmp); daarna weer jouw stand.
const vulnTmp = { h: new Map(), e: new Map() };
const vulnSaved = (kind, id) => ((PREFS.vuln_open || {})[kind] || []).map(String).includes(String(id));
function vulnOpenSave(kind, list) {   // list: [[id, open], …]
  const v = PREFS.vuln_open || {};
  const s = new Set((v[kind] || []).map(String));
  list.forEach(([id, open]) => (open ? s.add(String(id)) : s.delete(String(id))));
  savePref('vuln_open', { h: v.h || [], e: v.e || [], [kind]: kind === 'h' ? [...s].map(Number) : [...s] });
}

function initVulns() {
  $('#vuln-search').oninput = () => renderVulns();
  $('#vuln-sev').onchange = () => renderVulns();
  $('#vuln-filter').onclick = (e) => {
    const b = e.target.closest('[data-vf]');
    if (b) { vulnFilter = b.dataset.vf; renderVulns(); }
  };
  // Tellers: critical/high/medium = ernst vanaf, actief misbruikt = dat filter
  $('#vuln-counts').onclick = (e) => {
    const b = e.target.closest('[data-vsev], [data-vf]');
    if (!b) return;
    if (b.dataset.vf) vulnFilter = vulnFilter === 'kev' ? 'all' : 'kev';
    else { const v = b.dataset.vsev; $('#vuln-sev').value = $('#vuln-sev').value === v ? '0' : v; }
    renderVulns();
  };
  $('#vuln-banner').onclick = (e) => { if (e.target.closest('[data-vscan]')) vulnScan(e.target.closest('[data-vscan]')); };
  $('#vuln-scan').onclick = (e) => vulnScan(e.currentTarget);
  $('#vuln-toggle').onclick = () => {
    const cards = $$('#vuln-hosts details.vuln-host');
    const all = cards.length && cards.every((c) => c.open);
    cards.forEach((c) => { c.open = !all; vulnTmp.h.set(+c.dataset.host, !all); });
    vulnOpenSave('h', cards.map((c) => [+c.dataset.host, !all]));
  };
  $('#vuln-hosts').addEventListener('click', vulnClick);
  initVulnSearch();   // 1.248.0
}

async function loadVulns() {
  try {
    vulnData = await api('vulns');
    keepOpen($('#vuln-hosts'), renderVulns);
  } catch (ex) { $('#vuln-hosts').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

// ---------------------------------------------------------------- Datalekken (Have I Been Pwned, 1.69.0)
let leakData = null;
let leakFilter = 'open';
let vulnTab = (() => { try { return localStorage.getItem('rzmon-vuln-tab') || 'cve'; } catch (e) { return 'cve'; } })();
const LEAK_SRC = { user: () => '👤 ' + L('gebruiker'), list: () => '📋 ' + L('eigen lijst'), domain: () => '🌐 ' + L('domein') };

function showVulnTab() {
  if (!$('#vuln-tabs')) return;
  const have = $$('#vuln-tabs .seg-btn').map((x) => x.dataset.vt);
  if (!have.includes(vulnTab)) vulnTab = have[0] || 'cve';
  if (vulnTab === 'eol') loadEol();   // 1.227.0
  if (vulnTab === 'patch') loadPatch();   // 1.230.0
  if (vulnTab === 'sw') loadVulnSoftware();   // 1.248.0
  if ($('#vs-bar')) $('#vs-bar').hidden = !['cve', 'sw'].includes(vulnTab);
  $$('#vuln-tabs .seg-btn').forEach((x) => x.classList.toggle('active', x.dataset.vt === vulnTab));
  $$('#tab-vulns .vuln-pane').forEach((x) => { x.hidden = x.dataset.vpane !== vulnTab; });
  crumbs();   // 1.199.0
}

/** De tabbladen Kwetsbaarheden / Verouderd / Datalekken (1.227.0: los, want Datalekken kan uit staan) */
function initVulnTabs() {
  if ($('#vuln-tabs') && !$('#vuln-tabs').dataset.init) {
    $('#vuln-tabs').dataset.init = '1';
    $('#vuln-tabs').onclick = (e) => {
      const b = e.target.closest('[data-vt]');
      if (!b) return;
      vulnTab = b.dataset.vt;
      try { localStorage.setItem('rzmon-vuln-tab', vulnTab); } catch (ex) { /* alleen voor nu */ }
      showVulnTab();
      fxSyncHash();
    };
    showVulnTab();
  }
}
function initLeaks() {
  initVulnTabs();
  $('#leak-search').oninput = () => renderLeaks();
  $('#leak-toggle').onclick = () => {
    const cards = $$('#leak-list details.leak-card');
    const all = cards.length && cards.every((c) => c.open);
    cards.forEach((c) => { c.open = !all; vulnTmp.e.set(c.dataset.email, !all); });
    vulnOpenSave('e', cards.map((c) => [c.dataset.email, !all]));
  };
  $('#leak-filter').onclick = (e) => { const b = e.target.closest('[data-lf]'); if (b) { leakFilter = b.dataset.lf; renderLeaks(); } };
  $('#leak-counts').onclick = (e) => { const b = e.target.closest('[data-lf]'); if (b) { leakFilter = leakFilter === b.dataset.lf ? 'all' : b.dataset.lf; renderLeaks(); } };
  $('#leak-run').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      const r = await api('hibp_run', {});
      toast(r.left > 0 ? L('Controle gestart: nog {n} adres(sen) te gaan, de rest volgt binnen een paar minuten', { n: r.left }) : L('Controle klaar'));
      loadLeaks();
    } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  };
  $('#leak-list').addEventListener('click', async (e) => {
    const det = e.target.closest('details.leak-card');
    if (e.target.closest('summary') && det && $(':scope > summary', det).contains(e.target)) {   // open/dicht onthouden (per account)
      setTimeout(() => { vulnTmp.e.set(det.dataset.email, det.open); vulnOpenSave('e', [[det.dataset.email, det.open]]); }, 0);
      return;
    }
    const b = e.target.closest('[data-lhandle]');
    if (!b) return;
    try { await api('hibp_handle', { email: b.dataset.email, name: b.dataset.lhandle, handled: b.dataset.done === '1' }); loadLeaks(); }
    catch (ex) { toast(ex.message, 'err'); }
  });
}

async function loadLeaks() {
  try {
    leakData = await api('hibp_overview');
    keepOpen($('#leak-list'), renderLeaks);
  } catch (ex) { $('#leak-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

function leakVisible(e) {
  const q = $('#leak-search').value.trim().toLowerCase();
  if (!e.breaches.length && !fxPass('leaks', { email: e.email, source: e.source, none: 1 })) return null;
  const bs = e.breaches.filter((b) => fxPass('leaks', { ...b, email: e.email, source: e.source })
    && (leakFilter === 'handled' ? b.handled_at : leakFilter === 'all' || leakFilter === 'none' ? true : !b.handled_at)
    && (leakFilter !== 'pw' || b.passwords)
    && (!q || e.email.includes(q) || (b.title || b.name).toLowerCase().includes(q) || (b.domain || '').toLowerCase().includes(q)));
  if (leakFilter === 'none') return e.breaches.length ? null : bs;
  if ((leakFilter !== 'all' || fxActive('leaks')) && !bs.length && e.breaches.length) return null;
  if (leakFilter === 'all' && q && !bs.length && !e.email.includes(q)) return null;
  return bs;
}

function renderLeaks() {
  if (!leakData) return;
  const d = leakData;
  const all = d.emails.flatMap((e) => e.breaches.map((b) => ({ ...b, email: e.email })));
  const open = all.filter((b) => !b.handled_at);
  const pw = open.filter((b) => b.passwords);
  const hit = new Set(open.map((b) => b.email));
  const pwUsers = d.users.filter((u) => +u.hibp && +u.pw_pwned > 0);
  const handled = all.length - open.length;
  // Tellers
  $('#leak-counts').innerHTML = [
    ['open', open.length, L('Open lekken'), 's2'], ['pw', pw.length, L('Met wachtwoorden'), 's2'], ['', hit.size, L('Adressen getroffen'), 's1'],
    ['', pwUsers.length, L('Gelekte wachtwoorden'), 's2'], ['handled', handled, L('Afgehandeld'), 's0'],
  ].map(([k, n, w, c]) => `<button type="button" class="hc ${c} ${n ? '' : 'zero'} ${k && leakFilter === k ? 'on' : ''}" ${k ? `data-lf="${k}"` : ''}><span class="hc-dot"></span><b data-flash>${n}</b> <span class="hc-w">${w}</span></button>`).join('');
  $('#leak-info').textContent = d.check_at ? L('laatste controle {t}', { t: ago(d.check_at) }) + ' · ' + L('dagelijks') : '';
  if ($('#vt-n-leak')) $('#vt-n-leak').textContent = open.length || '';
  // Meldingen bovenaan: geen sleutel, fouten
  $('#leak-banner').innerHTML = [
    !d.has_key ? `<div class="v-banner info">ℹ️ <span>${L('Geen API-sleutel: alleen de wachtwoorden van RzSense-accounts worden gecontroleerd. Een sleutel vul je in bij Instellingen → Beveiliging → Datalekken.')}</span></div>` : '',
    d.last_error ? `<div class="v-banner">⚠️ <span><b>HIBP:</b> ${esc(d.last_error)}</span></div>` : '',
    d.domain_error ? `<div class="v-banner">⚠️ <span><b>${L('Domein')}:</b> ${esc(d.domain_error)}</span></div>` : '',
  ].join('');
  const newest = open.slice().sort((a, b) => String(b.added_date || '').localeCompare(String(a.added_date || '')))[0];
  const checked = d.emails.filter((e) => e.checked_at).length;
  $('#leak-kpis').innerHTML = `
    <div class="kpi kpi-main ${d.emails.some((e) => e.error) ? 'warn' : ''}"><div class="kpi-txt"><div class="kpi-lbl">${L('Adressen gecontroleerd')}</div>
      <div class="kpi-val">${checked}<small>/${d.emails.length}</small></div>
      <div class="kpi-sub">${d.domain ? L('incl. domein {d}', { d: esc(d.domain) }) : L('gebruikers en eigen lijst')}</div></div></div>
    <div class="kpi ${open.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Open lekken')}</div><div class="kpi-val">${open.length}</div>
      <div class="kpi-sub">${L('op {n} adressen', { n: hit.size })}</div></div>
    <div class="kpi ${pw.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Met wachtwoorden')}</div><div class="kpi-val">${pw.length}</div>
      <div class="kpi-sub">${pw.length ? L('wijzig die wachtwoorden') : L('geen')}</div></div>
    <div class="kpi ${pwUsers.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Gelekte wachtwoorden in RzSense')}</div><div class="kpi-val">${pwUsers.length}</div>
      <div class="kpi-sub">${pwUsers.length ? esc(pwUsers.map((u) => u.username).join(', ')) : L('geen bekend')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Nieuwste lek')}</div><div class="kpi-val kpi-val-txt">${newest ? esc(newest.title || newest.name) : '–'}</div>
      <div class="kpi-sub">${newest && newest.breach_date ? esc(fmtDay(newest.breach_date)) : ''}</div></div>`;
  const cnt = { all: all.length, open: open.length, pw: pw.length, handled, none: d.emails.filter((e) => !e.breaches.length).length };
  $('#leak-filter').innerHTML = [['open', L('Open')], ['pw', '🔑 ' + L('Met wachtwoorden')], ['handled', L('Afgehandeld')], ['none', L('Zonder lekken')], ['all', L('Alles')]]
    .map(([k, l]) => `<button type="button" class="dchip ${leakFilter === k ? 'on' : ''}" data-lf="${k}">${l} <b>${cnt[k]}</b></button>`).join('');
  // Accounts met een gelekt wachtwoord
  $('#leak-users').innerHTML = pwUsers.length ? `<div class="v-banner">🔓 <span><b>${L('Wachtwoord in een datalek')}:</b> ${pwUsers.map((u) => esc(u.username)).join(', ')}.
    ${L('Bij de volgende keer inloggen moet eerst een nieuw wachtwoord worden gekozen.')}</span></div>` : '';
  fxRender('leaks');
  // Per adres
  const cards = d.emails.map((e) => [e, leakVisible(e)]).filter(([, bs]) => bs !== null)
    .sort(([a, x], [b, y]) => y.filter((z) => z.passwords && !z.handled_at).length - x.filter((z) => z.passwords && !z.handled_at).length || y.length - x.length || a.email.localeCompare(b.email));
  $('#leak-list').innerHTML = cards.length ? cards.map(([e, bs]) => leakCard(e, bs)).join('')
    : `<div class="card"><p class="muted" style="margin:0">${d.emails.length ? (leakFilter === 'open' ? '✔ ' + L('Geen open lekken.') : L('Niets gevonden.'))
      : L('Nog geen e-mailadressen: vul een API-sleutel in, en e-mailadressen bij de gebruikers of in de eigen lijst.')}</p></div>`;
}

function leakCard(e, bs) {
  const openN = e.breaches.filter((b) => !b.handled_at).length;
  const pwN = e.breaches.filter((b) => !b.handled_at && b.passwords).length;
  const narrowed = !!$('#leak-search').value.trim() || fxActive('leaks');
  if (!narrowed) vulnTmp.e.clear();
  const isOpen = narrowed ? vulnTmp.e.get(e.email) ?? bs.length > 0 : vulnSaved('e', e.email);
  return `<details class="card vuln-host leak-card ${pwN ? 'vh-w4' : openN ? 'vh-w2' : 'vh-w-1'}" data-email="${esc(e.email)}" ${isOpen ? 'open' : ''}>
    <summary><span class="host-ic" aria-hidden="true">✉️</span>
      <span class="vh-name"><b>${esc(e.email)}</b><span class="muted small">${(LEAK_SRC[e.source] || LEAK_SRC.list)()}${e.checked_at ? ' · ' + L('gecontroleerd {t}', { t: ago(e.checked_at) }) : ' · ' + L('nog niet gecontroleerd')}</span></span>
      <span class="vh-counts">${e.error ? `<span class="warn-txt small" title="${esc(e.error)}">⚠️ ${esc(e.error)}</span>` : ''}
        ${pwN ? `<span class="vpill sev4" title="${L('met wachtwoorden')}">🔑 ${pwN}</span>` : ''}${openN - pwN ? `<span class="vpill sev2" title="${L('open lekken')}">${openN - pwN}</span>` : ''}
        ${!e.breaches.length && e.checked_at ? `<span class="good-txt">✔ ${L('in geen enkel bekend lek')}</span>` : ''}${e.breaches.length && !openN ? `<span class="muted small">✔ ${L('alles afgehandeld')}</span>` : ''}</span></summary>
    ${bs.length ? `<div class="leak-rows">${bs.map((b) => `
      <div class="leak-row ${b.handled_at ? 'done' : ''} ${b.passwords ? 'pw' : ''}">
        <div class="leak-main"><div><b>${esc(b.title || b.name)}</b> <span class="muted small">${b.domain ? esc(b.domain) + ' · ' : ''}${b.breach_date ? esc(fmtDay(b.breach_date)) : '?'}${+b.pwn_count ? ' · ' + L('{n} accounts', { n: (+b.pwn_count).toLocaleString(LOCALE) }) : ''}${+b.verified ? '' : ' · ' + L('niet geverifieerd')}</span></div>
          <div class="leak-classes">${(b.data_classes_l || []).map((c, i) => `<span class="lc ${b.data_classes[i] === 'Passwords' ? 'pw' : ''}">${esc(c)}</span>`).join('')}</div>
          ${b.description ? `<details class="small leak-desc"><summary>${L('Wat is er gebeurd?')}</summary><p>${esc(b.description)}</p></details>` : ''}
          ${b.handled_at ? `<div class="muted small">✔ ${L('Afgehandeld {t} door {by}', { t: ago(b.handled_at), by: esc(b.handled_by || '?') })}</div>` : ''}</div>
        <button type="button" class="btn small-btn" data-lhandle="${esc(b.name)}" data-email="${esc(e.email)}" data-done="${b.handled_at ? '0' : '1'}">${b.handled_at ? '↺ ' + L('Heropenen') : '✔ ' + L('Afgehandeld')}</button>
      </div>`).join('')}</div>` : (e.breaches.length ? `<p class="muted small" style="margin:.4rem 0 0">${L('Geen lekken in deze selectie.')}</p>` : '')}
  </details>`;
}

async function vulnScan(btn) {
  btn.disabled = true;
  try {
    const r = await api('vuln_scan', {});
    toast(r.message || (r.pending ? L('Scan gestart: nog {n} server(s) te gaan, de rest volgt binnen een paar minuten', { n: r.pending }) : L('Scan klaar')));
    loadVulns();
  } catch (ex) { toast(ex.message, 'err'); }
  btn.disabled = false;
}

/** Bevindingen die nu zichtbaar zijn (zoeken, filter, ernst; minimum uit de instellingen) */
function vulnVisible() {
  const q = $('#vuln-search').value.trim().toLowerCase();
  const sev = Math.max(+$('#vuln-sev').value || 0, vulnData.min_severity || 0);
  const hostName = Object.fromEntries(vulnData.hosts.map((h) => [h.id, h.name.toLowerCase()]));
  const exposed = Object.fromEntries(vulnData.hosts.map((h) => [h.id, h.exposed || {}]));
  return vulnData.findings.filter((f) => {
    if (!fxPass('vulns', f)) return false;
    if (vulnFilter === 'ignored' ? !f.ignored : f.ignored && !fxHas('vulns', 'ign')) return false;
    if (f.severity < sev && !(sev && f.kev)) return false;
    if (vulnFilter === 'exposed' && !exposed[f.host_id][f.package]) return false;
    if (vulnFilter === 'fix' && !f.fixed) return false;
    if (vulnFilter === 'kev' && !f.kev) return false;
    if (vulnFilter === 'risk' && f.risk < 70) return false;
    if (vulnFilter === 'buzz') { const b = vulnBuzz(f.cve); if (!b || b.score === null || b.score < (vulnData.buzz_min || 60)) return false; }   // 1.179.0
    return !q || f.package.toLowerCase().includes(q) || f.cve.toLowerCase().includes(q) || f.vuln_id.toLowerCase().includes(q)
      || (f.binaries || '').toLowerCase().includes(q) || hostName[f.host_id].includes(q) || (f.summary || '').toLowerCase().includes(q);
  });
}

/** 1.179.0: rugbaarheid van een CVE (of null), en het oordeel van de AI */
const vulnBuzz = (cve) => (vulnData && vulnData.buzz ? vulnData.buzz[cve] || null : null);
const VULN_AI = { now: ['bad', () => L('eerst doen')], later: ['warn', () => L('kan wachten')], na: ['ok', () => L('niet van toepassing')] };
function vulnChip(f) {
  const [icon] = VULN_SEV[f.severity] || VULN_SEV[0];
  const b = vulnBuzz(f.cve);
  const hot = b && b.score !== null && b.score >= (vulnData.buzz_min || 60);
  const ai = b && VULN_AI[b.ai];
  return `<button type="button" class="cve-chip sev${f.severity}${f.kev ? ' kev' : ''}${hot ? ' hot' : ''}" data-cve="${esc(f.cve)}"
    title="${esc((f.summary || f.vuln_id) + ` · ${L('risico')} ${f.risk}` + (f.cvss ? ` · CVSS ${f.cvss}` : '') + (f.kev ? ' · ' + L('actief misbruikt') : '')
      + (b && b.score !== null ? ' · ' + L('rugbaarheid {s}', { s: b.score }) : '') + (ai ? ' · AI: ' + ai[1]() : ''))}">${icon} ${esc(f.cve)}${f.kev ? ' ⚠️' : ''}${hot ? ` <span class="cve-hot">🔥${b.score}</span>` : ''}${ai ? ` <span class="cve-ai ${ai[0]}" aria-hidden="true">🤖</span>` : ''}</button>`;
}

/** 1.141.0: risicoscore 0–100 als pil (hoog ≥ 70, midden ≥ 40) */
function riskPill(r) {
  const lvl = r >= 70 ? 'high' : r >= 40 ? 'mid' : 'low';
  return `<span class="risk-pill risk-${lvl}" title="${esc(L('Risico {r} van 100: ernst (CVSS), actief misbruikt, kans op misbruik (EPSS) en of het pakket op het netwerk luistert', { r }))}">${r}</span>`;
}

function renderVulns() {
  if (!vulnData) return;
  try { ccVulnCharts(); } catch (e) { /* idem */ }
  const d = vulnData;
  const list = vulnVisible();
  // Tellers per ernst (unieke CVE's per server), zonder geaccepteerde
  const live = d.findings.filter((f) => !f.ignored);
  const uniq = (fs) => new Set(fs.map((f) => f.host_id + '|' + f.cve));
  const n = [0, 1, 2, 3, 4].map((s) => uniq(live.filter((f) => f.severity === s)).size);
  const kev = uniq(live.filter((f) => f.kev)).size;
  const sevSel = $('#vuln-sev').value;
  const WORD = { 4: 'Critical', 3: 'High', 2: 'Medium', 1: 'Low', 0: L('Onbekend') };
  $('#vuln-counts').innerHTML = [4, 3, 2, 1, 0].filter((sv) => n[sv] || sv >= 1)
    .map((sv) => `<button type="button" class="hc vsev${sv} ${n[sv] ? '' : 'zero'} ${sv >= 2 && sevSel === String(sv) ? 'on' : ''}" ${sv >= 2 ? `data-vsev="${sv}" title="${esc(L('Klik: vanaf deze ernst'))}"` : ''}><span class="hc-dot"></span><b>${n[sv]}</b> <span class="hc-w">${WORD[sv]}</span></button>`).join('')
    + `<button type="button" class="hc vkev ${kev ? '' : 'zero'} ${vulnFilter === 'kev' ? 'on' : ''}" data-vf="kev" title="${esc(L('Actief misbruikt (CISA KEV)'))}"><span class="hc-dot"></span><b>${kev}</b> <span class="hc-w">⚠️ ${L('Actief misbruikt')}</span></button>`;
  const scanned = d.hosts.filter((h) => h.scanned_at && !h.error);
  const failedH = d.hosts.filter((h) => h.error);
  $('#vuln-info').textContent = d.hosts.length ? (d.last_scan ? L('laatste scan {t}', { t: ago(d.last_scan) }) + ' · ' : '') + L('dagelijks om {t}', { t: d.scan_time }) : '';
  // Dezelfde scanfout op meerdere servers: één melding bovenaan
  const errs = {};
  failedH.forEach((h) => (errs[h.error] ||= []).push(h.name));
  const shared = Object.entries(errs).filter(([, hs]) => hs.length > 1);
  vulnSharedErr = new Set(shared.map(([e]) => e));
  $('#vuln-banner').innerHTML = shared.map(([e, hs]) => `<div class="v-banner">⚠️ <span><b>${L('Scan mislukt op {n} servers', { n: hs.length })}</b> (${esc(hs.join(', '))}): ${esc(e)}</span>
    <button type="button" class="btn need-admin" data-vscan>🔍 ${L('Opnieuw scannen')}</button></div>`).join('');
  // Kerncijfers
  const pk = new Set(live.map((f) => f.host_id + '|' + f.package));
  const fixPk = new Set(live.filter((f) => f.fixed).map((f) => f.host_id + '|' + f.package));
  const kevF = live.find((f) => f.kev);
  const noList = d.hosts.filter((h) => !h.packages).length;
  const [hh, mm] = String(d.scan_time || '03:30').split(':').map(Number);
  const next = new Date(); next.setHours(hh, mm, 0, 0); if (next <= new Date()) next.setDate(next.getDate() + 1);
  const left = Math.round((next - new Date()) / 60000);
  $('#vuln-kpis').innerHTML = `
    <div class="kpi kpi-main ${failedH.length ? 'warn' : ''}"><div class="kpi-txt"><div class="kpi-lbl">${L('Servers gescand')}</div>
      <div class="kpi-val">${scanned.length}<small>/${d.hosts.length}</small></div>
      <div class="kpi-sub">${[failedH.length ? L('{n} met een fout', { n: failedH.length }) : '', noList ? L('{n} zonder softwarelijst', { n: noList }) : ''].filter(Boolean).join(' · ') || L('allemaal gelukt')}</div></div></div>
    <div class="kpi ${pk.size ? 'bad' : ''}"><div class="kpi-lbl">${L('Kwetsbare pakketten')}</div><div class="kpi-val">${pk.size}</div>
      <div class="kpi-sub">${L('{n} kwetsbaarheden', { n: uniq(live).size })}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Met oplossing')}</div><div class="kpi-val ${fixPk.size ? 'good-txt' : ''}">${fixPk.size}</div>
      <div class="kpi-sub">${fixPk.size ? L('bij te werken met ⬆') : L('niets bij te werken')}</div></div>
    <div class="kpi ${kev ? 'bad' : ''}"><div class="kpi-lbl">${L('Actief misbruikt')}</div><div class="kpi-val">${kev}</div>
      <div class="kpi-sub">${kevF ? esc(kevF.cve + ' · ' + kevF.package) : L('geen (CISA KEV)')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Opgelost (30 dagen)')}</div><div class="kpi-val ${d.resolved ? 'good-txt' : ''}">${d.resolved || 0}</div>
      <div class="kpi-sub">${L('volgende scan om {t} (over {l})', { t: esc(d.scan_time), l: left >= 60 ? `${Math.floor(left / 60)} ${L('u')} ${left % 60} min` : `${left} min` })}</div></div>`;
  // Chips met aantallen
  const cntF = (k) => new Set(d.findings.filter((f) => (k === 'ignored' ? f.ignored : !f.ignored)
    && (k !== 'exposed' || (d.hosts.find((h) => +h.id === +f.host_id)?.exposed || {})[f.package]) && (k !== 'fix' || f.fixed) && (k !== 'kev' || f.kev) && (k !== 'risk' || f.risk >= 70) && (k !== 'buzz' || ((vulnBuzz(f.cve) || {}).score ?? -1) >= (d.buzz_min || 60))).map((f) => f.host_id + '|' + f.cve)).size;
  $('#vuln-filter').innerHTML = [['all', L('Alles')], ['risk', '⚡ ' + L('Hoog risico')], ...(d.buzz ? [['buzz', '🔥 ' + L('Veel aandacht')]] : []), ['exposed', '🌐 ' + L('Bereikbaar')], ['fix', L('Met oplossing')], ['kev', '⚠️ ' + L('Actief misbruikt')], ['ignored', L('Geaccepteerd')]]
    .map(([k, l]) => `<button type="button" class="dchip ${vulnFilter === k ? 'on' : ''}" data-vf="${k}">${l} <b>${cntF(k)}</b></button>`).join('');
  $('#vuln-oneup').innerHTML = vulnOneUp(live);
  $('#vuln-badge').hidden = !(n[4] + kev);
  $('#vuln-badge').textContent = n[4] + kev || '';
  if (!d.hosts.length) {
    $('#vuln-hosts').innerHTML = `<div class="card"><p class="muted">${L('Nog geen softwarelijsten. Werk de agents bij naar {v} of nieuwer: dan sturen ze hun pakketten mee.', { v: '1.47.0' })}</p></div>`;
    return;
  }
  const byHost = {};
  list.forEach((f) => (byHost[f.host_id] ||= []).push(f));
  fxRender('vulns');
  // Met een filter alleen de servers met treffers
  const hostsShown = fxActive('vulns') ? d.hosts.filter((h) => (byHost[h.id] || []).length) : d.hosts;
  $('#vuln-hosts').innerHTML = hostsShown.length ? hostsShown.map((h) => vulnHostCard(h, byHost[h.id] || [])).join('')
    : `<div class="card"><p class="muted" style="margin:0">${L('Niets gevonden.')}</p></div>`;
}

/**
 * 1.179.0: "Eén update, veel opgelost": per pakket hoeveel kwetsbaarheden een update oplost, op hoeveel servers,
 * gesorteerd op het risico dat verdwijnt (som van het risico per server en CVE). Alleen wat een oplossing heeft.
 */
function vulnOneUp(live) {
  const by = {};
  live.filter((f) => f.fixed).forEach((f) => {
    const p = (by[f.package] ||= { name: f.package, hosts: {}, cves: new Set(), kev: new Set(), hot: new Set(), sum: 0, seen: new Set(), max: 0 });
    const k = f.host_id + '|' + f.cve;
    if (!p.seen.has(k)) { p.seen.add(k); p.sum += f.risk || 0; }
    p.cves.add(f.cve);
    if (f.kev) p.kev.add(f.cve);
    const b = vulnBuzz(f.cve);
    if (b && b.score !== null && b.score >= (vulnData.buzz_min || 60)) p.hot.add(f.cve);
    p.max = Math.max(p.max, f.risk || 0);
    const hh = (p.hosts[f.host_id] ||= { id: f.host_id, fixed: f.fixed, n: 0 });
    hh.n++;
    if (String(f.fixed) > String(hh.fixed)) hh.fixed = f.fixed;
  });
  const list = Object.values(by).filter((p) => p.cves.size >= 2 || p.kev.size || p.max >= 70).sort((a, b) => b.sum - a.sum).slice(0, 6);
  if (!list.length) return '';
  const name = (id) => (vulnData.hosts.find((h) => h.id === +id) || {}).name || '#' + id;
  return `<div class="card vou"><div class="vou-h"><h3>🎯 ${L('Eén update, veel opgelost')}</h3><span class="muted small">${L('de updates die het meeste risico wegnemen')}</span></div>
    <div class="vou-list">${list.map((p, i) => {
      const hs = Object.values(p.hosts);
      const fixed = [...new Set(hs.map((x) => x.fixed))];
      return `<div class="vou-row"><span class="vou-rank" aria-hidden="true">${i + 1}</span>
        <span class="vou-main"><b>${esc(p.name)}</b> <span class="muted small mono">→ ${esc(fixed.length === 1 ? fixed[0] : L('{n} versies', { n: fixed.length }))}</span>
          <small>${p.cves.size === 1 ? L('1 kwetsbaarheid opgelost') : L('{n} kwetsbaarheden opgelost', { n: p.cves.size })} · ${hs.length === 1 ? esc(name(hs[0].id)) : L('op {n} servers', { n: hs.length })}${p.kev.size ? ` · <b class="bad">⚠️ ${L('{n} actief misbruikt', { n: p.kev.size })}</b>` : ''}${p.hot.size ? ` · 🔥 ${L('{n} met veel aandacht', { n: p.hot.size })}` : ''}</small></span>
        <span class="vou-bar" title="${esc(L('Risico dat verdwijnt: {n}', { n: p.sum }))}"><i style="width:${Math.min(100, Math.round(p.sum / list[0].sum * 100))}%"></i></span>
        <button type="button" class="btn small-btn need-admin" data-vou="${esc(p.name)}">⬆ ${L('Bijwerken')}</button></div>`;
    }).join('')}</div></div>`;
}
/** Bijwerken vanuit "Eén update": één server meteen, meer servers eerst kiezen */
function vulnOneUpDo(pkg) {
  const hosts = [...new Set(vulnData.findings.filter((f) => f.package === pkg && f.fixed && !f.ignored).map((f) => f.host_id))]
    .map((id) => vulnData.hosts.find((h) => h.id === id)).filter(Boolean);
  if (hosts.length === 1) return vulnUpgrade(hosts[0], [pkg]);
  modal({
    title: L('{p} bijwerken', { p: pkg }),
    body: `<div style="padding:1rem 1.2rem"><p class="muted small">${L('Kies de server; bijwerken gaat per server via de agent (of met het commando).')}</p>
      ${hosts.map((h) => `<div class="am-row"><div class="am-rt"><b>${esc(h.name)}</b><small>${esc(h.os || '')}</small></div><div class="am-rr"><button type="button" class="btn small-btn" data-vou-h="${h.id}">⬆ ${L('Bijwerken')}</button></div></div>`).join('')}</div>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  $$('[data-vou-h]', $('#modal')).forEach((b) => (b.onclick = () => vulnUpgrade(hosts.find((h) => h.id === +b.dataset.vouH), [pkg])));
}

function vulnHostCard(h, fs) {
  const sharedErr = h.error && vulnSharedErr.has(h.error);   // staat al bovenaan als één melding
  // Per pakket: alle CVE's, ernstigste eerst; pakketten met de ergste en bereikbare bovenaan
  const pk = {};
  fs.forEach((f) => (pk[f.package] ||= { name: f.package, version: f.version, fixed: f.fixed, binaries: f.binaries, list: [] }).list.push(f));
  const rank = (p) => Math.max(...p.list.map((f) => f.risk || 0));   // 1.141.0: hoogste risicoscore bovenaan
  const pkgs = Object.values(pk).sort((a, b) => rank(b) - rank(a) || a.name.localeCompare(b.name));
  pkgs.forEach((p) => {
    p.risk = rank(p);
    p.list.sort((a, b) => (b.risk - a.risk) || (b.kev - a.kev) || (b.severity - a.severity));
    p.fixed = p.list.map((f) => f.fixed).filter(Boolean).sort().pop() || null;
  });
  const cnt = [4, 3, 2, 1, 0].map((s) => [s, new Set(fs.filter((f) => f.severity === s).map((f) => f.cve)).size]).filter(([, c]) => c);
  // Dicht, tenzij je hem open had (per account); met zoeken of een filter tijdelijk open als er treffers zijn
  const narrowed = !!$('#vuln-search').value.trim() || fxActive('vulns');
  if (!narrowed) vulnTmp.h.clear();
  const open = narrowed ? vulnTmp.h.get(+h.id) ?? fs.length > 0 : vulnSaved('h', h.id);
  const up = h.upgrade;
  const upTxt = !up ? '' : up.state === 'queued' || up.state === 'running'
    ? `<a href="#" data-vact="progress" data-host="${h.id}" title="${L('Voortgang tonen')}">${agentSteps(up)}</a>`   // 1.252.0: dezelfde stappen als overal
    : up.state === 'ok' ? `<span class="good-txt">✔ ${L('Bijgewerkt {t}', { t: ago(up.at) })}: ${esc((up.pkgs || []).join(' '))}</span>`
      + (!up.verified ? ` · <span class="muted">${L('controle volgt na de volgende scan')}</span>`
        : ` · <span class="${Object.keys(up.verified.still || {}).length ? 'warn-txt' : 'good-txt'}">🔎 ${L('gecontroleerd: {n} kwetsbaarheden opgelost', { n: up.verified.fixed })}${Object.keys(up.verified.still || {}).length
          ? ', ' + L('nog open in {p}', { p: esc(Object.keys(up.verified.still).join(', ')) }) : ''}</span>`)
    : `<span class="bad">✘ ${L('Bijwerken mislukt {t}', { t: ago(up.at) })}</span>`;
  const notes = [
    h.error && !sharedErr ? `<span class="warn-txt">⚠️ ${esc(h.error)}</span>` : '',   // ook in de kop; hier volledig
    h.agent_old ? `<span class="warn-txt">⬆ ${L('Agent {v} of nieuwer nodig voor de softwarelijst', { v: '1.47.0' })}</span>` : '',
    h.reboot ? `<span class="warn-txt" title="${esc(L('Draait: {a} · geïnstalleerd: {b}', { a: h.kernel, b: h.reboot }))}">↻ ${L('Herstart nodig voor de nieuwe kernel')}</span>` : '',
    h.restart && h.restart.length ? `<span class="warn-txt" title="${esc(h.restart.join(', '))}">↻ ${L('{n} service(s) draaien nog met oude bestanden', { n: h.restart.length })}</span>` : '',
    upTxt,
    h.resolved ? `<span class="good-txt" title="${esc(L('Verdwenen bij een nieuwe scan (bijgewerkt of het advies is aangepast); laatste {t}', { t: fmtDateTime(h.resolved.last) }))}">✔ ${L('{n} opgelost in 30 dagen', { n: h.resolved.n })}</span>` : '',
  ].filter(Boolean).join(' · ');
  const fixable = pkgs.filter((p) => p.fixed).map((p) => p.name);
  const total = cnt.reduce((a, [, c]) => a + c, 0);
  const worst = cnt.length ? cnt[0][0] : -1;
  const hh = state && state.hosts ? findHost(h.id) : null;
  return `<details class="card vuln-host vh-w${worst} ${h.error ? 'vh-err' : ''}" data-host="${h.id}" ${open ? 'open' : ''}>
    <summary><span class="host-ic" aria-hidden="true">${hh ? hostIcon(hh) : '🖥️'}</span>
      <span class="vh-name"><b>${esc(h.name)}</b><span class="muted small">${esc(h.os || '')}${h.packages ? ' · ' + L('{n} pakketten', { n: h.packages }) : ''}${h.scanned_at ? ' · ' + L('gescand {t}', { t: ago(h.scanned_at) }) : ''}</span></span>
      <span class="vh-counts">${h.error ? `<span class="warn-txt small" title="${esc(h.error)}">⚠️ ${sharedErr ? L('scan mislukt (zie boven)') : esc(h.error.length > 70 ? h.error.slice(0, 70) + '…' : h.error)}</span>` : ''}${cnt.length ? cnt.map(([s, c]) => `<span class="vpill sev${s}" title="${c} ${VULN_SEV[s][1]}">${c}</span>`).join('') : (h.scanned_at && !h.error ? `<span class="good-txt">✔ ${L('geen bekende kwetsbaarheden')}</span>` : '')}</span>
      ${total ? `<span class="sevbar">${cnt.map(([s, c]) => `<i class="sev${s}" style="width:${(c / total * 100).toFixed(1)}%"></i>`).join('')}</span>` : ''}</summary>
    ${notes ? `<p class="small vh-notes">${notes}${up && up.log ? ` <details class="small"><summary>${L('Uitvoer')}</summary><pre class="code">${esc(up.log)}</pre></details>` : ''}</p>` : ''}
    <div class="vh-actions">
      ${fixable.length ? `<button type="button" class="btn need-admin" data-vact="upgrade-all" data-host="${h.id}">⬆ ${L('Alle beveiligingsupdates installeren ({n})', { n: fixable.length })}</button>` : ''}
      ${h.packages ? `<button type="button" class="btn" data-vact="software" data-host="${h.id}">📦 ${L('Alle software')}</button>
        <a class="btn" href="api.php?${new URLSearchParams({ action: 'vuln_sbom', host_id: h.id })}" title="${esc(L('Softwarelijst met kwetsbaarheden als CycloneDX-bestand (SBOM), voor andere scanners of Dependency-Track'))}">⤓ SBOM</a>` : ''}
    </div>
    ${pkgs.length ? `<div class="table-scroll"><table class="vuln-table">
      <thead><tr><th>${L('Risico')}</th><th>${L('Pakket')}</th><th>${L('Geïnstalleerd')}</th><th>${L('Opgelost in')}</th><th>CVE's</th><th></th></tr></thead>
      <tbody>${pkgs.map((p) => `<tr>
        <td>${riskPill(p.risk)}</td>
        <td><b>${esc(p.name)}</b>${h.exposed[p.name] ? ` <span class="exp-tag" title="${esc(L('Luistert op het netwerk: {p}', { p: h.exposed[p.name].join(', ') }))}">🌐 ${esc(h.exposed[p.name].join(', '))}</span>` : ''}
          ${p.binaries && p.binaries !== p.name ? `<div class="muted small" title="${esc(p.binaries)}">${esc(p.binaries.length > 60 ? p.binaries.slice(0, 60) + '…' : p.binaries)}</div>` : ''}</td>
        <td class="mono small">${esc(p.version)}</td>
        <td class="mono small">${p.fixed ? esc(p.fixed) : `<span class="muted">${L('nog geen oplossing')}</span>`}</td>
        <td class="cve-cell">${p.list.slice(0, 6).map(vulnChip).join(' ')}${p.list.length > 6 ? ` <button type="button" class="cve-more" data-vact="more" data-host="${h.id}" data-pkg="${esc(p.name)}">+${p.list.length - 6}</button>` : ''}</td>
        <td>${p.fixed ? `<button type="button" class="icon-btn need-admin" title="${esc(L('Bijwerken'))}" data-vact="upgrade" data-host="${h.id}" data-pkg="${esc(p.name)}">⬆</button>` : ''}</td>
      </tr>`).join('')}</tbody></table></div>` : (h.scanned_at ? `<p class="muted small">${vulnFilter === 'all' && !$('#vuln-search').value ? L('Geen kwetsbaarheden gevonden.') : L('Niets met dit filter.')}</p>` : `<p class="muted small">${L('Nog niet gescand.')}</p>`)}
  </details>`;
}

function vulnClick(e) {
  const vou = e.target.closest('[data-vou]');
  if (vou) return vulnOneUpDo(vou.dataset.vou);
  const det = e.target.closest('details.vuln-host');
  if (det && $(':scope > summary', det).contains(e.target)) {   // open/dicht onthouden (per account)
    setTimeout(() => { vulnTmp.h.set(+det.dataset.host, det.open); vulnOpenSave('h', [[+det.dataset.host, det.open]]); }, 0);
    return;
  }
  const chip = e.target.closest('[data-cve]');
  if (chip) return vulnDetail(chip.dataset.cve);
  const b = e.target.closest('[data-vact]');
  if (!b) return;
  const h = vulnData.hosts.find((x) => x.id === +b.dataset.host);
  if (b.dataset.vact === 'upgrade') return vulnUpgrade(h, [b.dataset.pkg]);
  if (b.dataset.vact === 'upgrade-all') return vulnUpgrade(h, null);
  if (b.dataset.vact === 'software') return vulnSoftware(h);
  if (b.dataset.vact === 'progress') return vulnUpgradeDialog(h, true);
  if (b.dataset.vact === 'more') {
    const fs = vulnVisible().filter((f) => f.host_id === h.id && f.package === b.dataset.pkg);
    b.parentElement.innerHTML = fs.map(vulnChip).join(' ');
  }
}

/** 1.179.0: blok Rugbaarheid (meter, bronnen, verloop) en Raakt dit jou echt? (AI) in de popup van een kwetsbaarheid */
function vulnBuzzHtml(d) {
  const b = d.buzz;
  if (!b) return '';
  const x = b.data || {};
  const lvl = b.score >= b.threshold ? 'hot' : b.score >= 30 ? 'mid' : 'low';
  const ssvc = x.ssvc ? ({ active: L('actief misbruikt'), poc: L('er is een proof-of-concept'), none: L('geen misbruik bekend') }[x.ssvc.e] || '?')
    + (x.ssvc.a === 'yes' ? ' · ' + L('automatiseerbaar') : '') + (x.ssvc.t === 'total' ? ' · ' + L('volledige overname') : '') : L('nog geen oordeel');
  const yes = (v) => (v ? `<b class="bad">${L('ja')}</b>` : L('nee'));
  const cnt = (n) => (+n ? `<b>${(+n).toLocaleString(LOCALE)}</b>` : '0');
  const hist = (b.history || []).slice(-14);
  const max = Math.max(10, ...hist.map((h) => +h[1]));
  const spark = hist.length > 1 ? `<svg class="vbz-spark" viewBox="0 0 ${(hist.length - 1) * 10} 30" preserveAspectRatio="none" aria-hidden="true"><polyline points="${hist.map((h, i) => `${i * 10},${(30 - (h[1] / max) * 28).toFixed(1)}`).join(' ')}"/></svg>` : '';
  const ai = VULN_AI[b.ai];
  const tag = (u) => `${esc(b.mastodon)}/tags/${u}`;
  const m = /^CVE-(\d{4})-(\d+)$/.exec(d.cve);
  return `<section class="vbz vbz-${lvl}" id="vbz">
    <div class="vbz-top">
      <div class="vbz-ring" style="--p:${b.score}"><div><b>${b.checked_at ? b.score : '–'}</b><small>${L('rugbaarheid')}</small></div></div>
      <div class="vbz-txt"><h3>🔥 ${L('Rugbaarheid')} ${b.checked_at && b.trend ? `<span class="vbz-trend ${b.trend > 0 ? 'up' : 'down'}">${b.trend > 0 ? '↑ +' : '↓ '}${b.trend} ${L('in 7 dagen')}</span>` : ''}</h3>
        <p class="muted small">${b.checked_at ? L('Exploits {e}/60 · praat {t}/40 · bijgewerkt {w}', { e: b.exploit, t: b.talk, w: ago(b.checked_at) }) : b.on ? L('Nog niet bijgewerkt; dat gebeurt bij de volgende scan.') : L('Staat uit (Instellingen → Kwetsbaarheden → Rugbaarheid).')}</p>
        ${spark}
        ${b.on ? `<button type="button" class="btn small-btn" data-vbuzz-now>↻ ${L('Nu bijwerken')}</button>` : ''}</div>
    </div>
    ${b.checked_at ? `<div class="vbz-src">
      <span>⚠️ ${L('Actief misbruikt (CISA KEV)')}</span><span>${yes(d.kev)}</span>
      <span>🏛️ ${L('Oordeel van CISA (SSVC)')}</span><span>${esc(ssvc)}</span>
      <span>💥 <a href="https://github.com/search?type=repositories&q=${encodeURIComponent('"' + d.cve + '"')}" target="_blank" rel="noopener noreferrer">${L("Openbare PoC's op GitHub")}</a></span><span>${cnt(x.gh)}</span>
      <span>🗡️ ${L('Exploit op Exploit-DB')}</span><span>${yes(x.edb)}</span>
      <span>🎯 ${L('Nuclei-template (scanners zoeken ernaar)')}</span><span>${yes(x.nuclei)}</span>
      <span>🟠 <a href="https://hn.algolia.com/?query=${encodeURIComponent('"' + d.cve + '"')}" target="_blank" rel="noopener noreferrer">Hacker News</a> <span class="muted small">${L('7 dagen · totaal')}</span></span><span>${cnt(x.hn7)} · ${cnt(x.hn)}</span>
      <span>🐘 ${m ? `<a href="${tag('CVE' + m[1] + m[2])}" target="_blank" rel="noopener noreferrer">Mastodon</a>` : 'Mastodon'} <span class="muted small">${L('7 dagen')}</span></span><span>${cnt(x.md7)}</span>
    </div>` : ''}
    ${aiOn() ? `<div class="vbz-ai"><div><b>🤖 ${L('Raakt dit jou echt?')}</b>${ai ? ` <span class="ai-verdict ${ai[0]}">${esc(ai[1]())}</span>` : ''}
        <p class="muted small">${L('De AI kijkt naar je servers: staat het pakket open naar het netwerk, is er al misbruik, is er een oplossing. Je krijgt een oordeel en wat je moet doen.')}</p></div>
      ${aiBtn('cve', b.id, true)}</div>` : ''}
  </section>`;
}

/** CVSS-vector in gewone woorden: hoe is hij te misbruiken? */
function cvssWords(v) {
  if (!v) return '';
  const m = Object.fromEntries(v.split('/').map((p) => p.split(':')));
  return [
    { N: L('via het netwerk'), A: L('vanaf het lokale netwerk'), L: L('alleen lokaal op de server'), P: L('alleen met fysieke toegang') }[m.AV],
    { N: L('zonder in te loggen'), L: L('met een gewoon account'), H: L('met beheerdersrechten') }[m.PR],
    { N: L('zonder dat een gebruiker iets doet'), R: L('een gebruiker moet iets doen (bv. een link openen)'), P: L('een gebruiker moet iets doen') }[m.UI],
    m.AC === 'H' ? L('moeilijk uit te voeren') : null,
  ].filter(Boolean).join(' · ');
}

async function vulnDetail(cve) {
  let d;
  try { d = await api('vuln_detail', null, { cve }); } catch (ex) {
    // 1.248.0: een CVE die RzSense niet op je servers kent: toch laten zien waar hij (niet) staat
    if (ex.status !== 404 || !CVE_RE.test(cve)) return toast(ex.message, 'err');
    const { form } = modal({
      title: `🔎 ${cve}`, wide: true,
      body: `<div style="padding:1rem 1.2rem"><p>${L('Deze kwetsbaarheid staat op geen enkele server open, en RzSense heeft hem ook niet eerder gevonden.')}</p>
        <p class="muted small">${L('Let op de servers onder <i>Onbekend</i>: daar kan RzSense niets over zeggen.')}
        <a href="https://nvd.nist.gov/vuln/detail/${encodeURIComponent(cve)}" target="_blank" rel="noopener noreferrer">NVD</a> ·
        <a href="https://osv.dev/vulnerability/${encodeURIComponent(cve)}" target="_blank" rel="noopener noreferrer">OSV</a></p>
        <div class="vd-where" id="vd-where"><p class="muted">${L('Laden…')}</p></div></div>`,
      buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
    });
    vulnWhere(cve, form);
    return;
  }
  const [icon, word] = VULN_SEV[d.severity] || VULN_SEV[0];
  const rows = [
    [L('Ernst'), `${icon} <b>${esc(word)}</b>${d.cvss !== null ? ` · CVSS <b>${dec(d.cvss)}</b>` : ''}${d.vector ? `<div class="muted small">${esc(cvssWords(d.vector))}</div><div class="mono small muted">${esc(d.vector)}</div>` : ''}`],
    [L('Actief misbruikt'), d.kev ? `<b class="bad">⚠️ ${L('ja')}</b>${d.kev_added ? ' · ' + L('op de lijst van CISA sinds {t}', { t: esc(d.kev_added) }) : ''}` : L('niet bekend')],
    [L('Kans op misbruik'), d.epss !== null ? L('{p} in de komende 30 dagen (EPSS)', { p: dec((d.epss * 100).toFixed(d.epss < 0.01 ? 2 : 1)) + '%' }) : '–'],
    ...(d.accepted.length ? [[L('Geaccepteerd'), d.accepted.map((a) => `<div class="vd-acc">${a.expired ? `<span class="warn-txt">${L('verlopen')}</span>` : '✔'}
      ${esc(a.host ? `${a.host}${a.package ? ' · ' + a.package : ''}` : L('alle servers'))} · ${a.until ? (a.expired ? L('was tot {d}', { d: esc(a.until) }) : L('tot {d}', { d: esc(a.until) })) : L('voor altijd')}
      <div class="muted small">${esc(a.reason || '–')} · ${esc(a.by)}, ${esc(fmtDateTime(a.at))}</div>
      <button type="button" class="btn small-btn need-user" data-vd-unign="${a.id}">${L('Opheffen')}</button></div>`).join('')]] : []),
    [L('Gepubliceerd'), d.published ? esc(fmtDateTime(d.published.slice(0, 19).replace('T', ' '))) : '–'],
    ...(d.aliases.length ? [[L('Ook bekend als'), d.aliases.map(esc).join(', ')]] : []),
  ];
  const hostRows = d.hosts.map((x, i) => `<div class="vd-host">
      <div><b>${esc(x.host)}</b> · <span class="mono small">${esc(x.package)} ${esc(x.version)}</span> → ${x.fixed ? `<span class="mono small good-txt">${esc(x.fixed)}</span>` : `<span class="muted">${L('nog geen oplossing')}</span>`}
        ${x.ignored ? ` · <span class="muted">${L('geaccepteerd')}</span>` : ''}</div>
      ${x.command ? copyBox('vd-cmd-' + i, x.command) : ''}
      ${x.fixed ? (x.upgrade_blocked ? `<p class="muted small">${L('Bijwerken via de agent kan hier niet: {why}. Voer het commando hierboven zelf uit op de server.', { why: esc(x.upgrade_blocked) })}</p>`
        : `<button type="button" class="btn need-admin" data-vd-up="${i}">⬆ ${L('Bijwerken via de agent')}</button>`) : ''}
    </div>`).join('');
  const { form } = modal({
    title: `${icon} ${cve}`, wide: true,
    body: `<div style="padding:1rem 1.2rem">
      ${d.summary ? `<p><b>${esc(d.summary)}</b></p>` : ''}
      ${d.details ? `<details class="vd-details" ${d.details.length < 600 ? 'open' : ''}><summary>${L('Omschrijving')}</summary><p class="small" style="white-space:pre-wrap">${esc(d.details)}</p></details>` : ''}
      <table class="kv">${rows.map(([k, v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('')}</table>
      ${vulnBuzzHtml(d)}
      <div class="vd-where" id="vd-where"><p class="muted">${L('Laden…')}</p></div>
      <h3>${L('Zo los je het op')}</h3>
      ${xpHtml(L('Installeer de oplossing uit de pakketbron van je distributie.'), L('Installeer de nieuwe versie uit de eigen pakketbron van de distributie (zoals hieronder); een download bij de maker is niet nodig. <b>Goed om te weten:</b> bij de kernel of een bibliotheek is daarna een herstart van de server of van de services nodig.'))}
      ${hostRows}
      <h3>${L('Meer informatie en adviezen')}</h3>
      <ul class="vd-links">${d.links.map((l) => `<li><a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${esc(l.label)}</a> <span class="muted small">${esc(l.url.replace(/^https?:\/\//, '').slice(0, 70))}</span></li>`).join('')}</ul>
    </div>`,
    buttons: [
      { label: '✔ ' + L('Risico accepteren…'), cls: 'need-user', style: 'margin-right:auto', onClick: () => vulnIgnore(d) },
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  wireCopy(form);
  vulnWhere(cve, form);   // 1.248.0: waar staat hij wel en niet
  const bn = $('[data-vbuzz-now]', form);
  if (bn) bn.onclick = async () => {
    bn.disabled = true;
    bn.textContent = L('Bezig…');
    try { const r = await api('vuln_buzz_now', { cve }); d.buzz = r.buzz; $('#vbz', form).outerHTML = vulnBuzzHtml(d); if (vulnData && vulnData.buzz) vulnData.buzz[cve] = { ...(vulnData.buzz[cve] || {}), id: r.buzz.id, score: r.buzz.score, trend: r.buzz.trend }; }
    catch (ex) { toast(ex.message, 'err'); bn.disabled = false; }
  };
  $$('[data-vd-unign]', form).forEach((b) => (b.onclick = async () => {
    try { await api('vuln_unignore', { id: +b.dataset.vdUnign }); toast(L('Telt weer mee')); $('#modal').close(); loadVulns(); vulnDetail(cve); }
    catch (ex) { toast(ex.message, 'err'); }
  }));
  $$('[data-vd-up]', form).forEach((b) => (b.onclick = () => {
    const x = d.hosts[+b.dataset.vdUp];
    vulnUpgrade(vulnData.hosts.find((h) => h.id === x.host_id) || { id: x.host_id, name: x.host }, [x.package]);
  }));
}

function vulnIgnore(d) {
  modal({
    title: L('Risico accepteren: {cve}', { cve: d.cve }),
    body: `<div style="padding:1rem 1.2rem">
      <p class="muted small">${L('Een geaccepteerde kwetsbaarheid telt niet meer mee en geeft geen melding. Je ziet hem terug onder het filter Geaccepteerd.')}</p>
      <label>${L('Waarvoor')}<select name="scope">
        <option value="all">${L('Alle servers')}</option>
        ${d.hosts.map((x, i) => `<option value="${i}">${esc(x.host)} · ${esc(x.package)}</option>`).join('')}
      </select></label>
      <label>${L('Reden')} <span class="hint">${L('(bv. niet bereikbaar van buitenaf)')}</span><input name="reason" maxlength="300" required></label>
      <label>${L('Hoe lang')}<select name="until">
        <option value="30">${L('30 dagen')}</option><option value="90" selected>${L('90 dagen')}</option><option value="180">${L('180 dagen')}</option>
        <option value="365">${L('1 jaar')}</option><option value="">${L('voor altijd')}</option>
      </select></label>
      <p class="hint">${L('Daarna telt hij weer mee, zodat je er opnieuw naar kijkt. Is er dan nog niets veranderd, accepteer hem dan opnieuw.')}</p>
    </div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✔ ' + L('Accepteren'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      const x = v.scope === 'all' ? null : d.hosts[+v.scope];
      const until = v.until ? new Date(Date.now() + +v.until * 86400000).toISOString().slice(0, 10) : '';
      await api('vuln_ignore', { cve: d.cve, reason: v.reason, until, host_id: x ? x.host_id : null, package: x ? x.package : null });
      toast(L('Geaccepteerd'));
      loadVulns();
    },
  });
}

/** Bijwerken via de agent (beheerders); kan dat niet, dan het commando om zelf uit te voeren */
function vulnUpgrade(h, pkgs) {
  const all = pkgs === null;
  modal({
    title: all ? L('Alle beveiligingsupdates installeren op {name}', { name: h.name }) : L('Bijwerken op {name}', { name: h.name }),
    body: `<div style="padding:1rem 1.2rem">
      <p>${all ? L('De agent installeert de nieuwe versie van alle pakketten waarvoor een oplossing is.') : L('De agent installeert de nieuwe versie van: {p}', { p: `<b>${esc(pkgs.join(' '))}</b>` })}</p>
      ${xpHtml(L('Bijwerken gebeurt bij het volgende rapport van de agent.'), L('Dit gebeurt bij het volgende rapport van de agent, met de pakketbeheerder van de server (dnf of apt), en komt in het logboek. Er wordt niets herstart; is dat nodig, dan zie je ↻ bij de server.'))}
      ${h.upgrade_blocked ? `<p class="warn-txt">${L('Bijwerken via de agent kan hier niet: {why}.', { why: esc(h.upgrade_blocked) })}</p>` : confirmField('BIJWERKEN')}
      <div id="vu-cmd"></div>
    </div>`,
    buttons: h.upgrade_blocked ? [{ label: L('Commando tonen'), cls: 'primary', submit: true }] : [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⬆ ' + L('Bijwerken'), cls: 'primary', submit: true }],
    onSubmit: async (v, form) => {
      try {
        const res = await api('vuln_upgrade', { host_id: h.id, all, packages: pkgs || [], confirm: v.confirm });
        // In hetzelfde venster meteen de voortgang tonen (eerst "wachten op de agent"), niet eerst sluiten
        vulnUpgradeDialog(h, true, { state: 'queued', pkgs: res.packages || pkgs || [] });
        loadVulns();
        return 'keep';
      } catch (ex) {
        // Kan het niet via de agent? Dan het commando tonen om zelf uit te voeren
        if (ex.data && ex.data.command) {
          $('#vu-cmd', form).innerHTML = `<p class="muted small">${L('Voer dit zelf uit op de server:')}</p>${copyBox('vu-cmd-line', ex.data.command)}`;
          wireCopy(form);
          throw new Error(ex.message);
        }
        throw ex;
      }
    },
  });
}

// ---------------------------------------------------------------- Bijwerken via de agent: voortgang
// Agent 1.48.0+ meldt elke paar seconden de stap, het percentage en het pakket; oudere agents alleen het begin en de uitkomst.
const VULN_UP_STEPS = [['queued', L('Wachten op de agent')], ['update', L('Pakketlijst ophalen')], ['download', L('Downloaden')], ['install', L('Installeren')], ['done', L('Klaar')]];
let vulnUpPoll = null;
const vulnUpShown = () => $('#modal').open && !!$('#modal-body .vu-dlg');

/** Venster met de voortgang van het bijwerken op server h; force = openen, ook als er een ander venster openstaat. */
async function vulnUpgradeDialog(h, force, first) {
  clearTimeout(vulnUpPoll);
  let r;
  if (first) r = { status: first, live: true };   // meteen iets tonen; de echte stand volgt hieronder
  else {
    try { r = await api('vuln_upgrade_status', null, { host_id: h.id }); }
    catch (ex) {
      // Even geen antwoord: het venster blijft staan en vraagt het zo opnieuw
      if (force && !vulnUpShown()) r = { status: { state: 'queued', pkgs: [] }, live: true };
      else { if (vulnUpShown()) vulnUpPoll = setTimeout(() => vulnUpgradeDialog(h), 3000); return; }
    }
  }
  if (!force && !vulnUpShown()) return;   // venster gesloten: stoppen
  const st = r.status || { state: 'queued', pkgs: [] };
  const busy = ['queued', 'running'].includes(st.state);
  const step = st.state === 'queued' ? 'queued' : st.state === 'ok' || st.state === 'error' ? 'done'
    : st.stage === 'verify' ? 'install' : st.stage || 'update';
  const cur = VULN_UP_STEPS.findIndex(([k]) => k === step);
  // Zonder tussenstand (oude agent, of nog niets gehoord): een bewegende balk zonder percentage
  const known = st.state === 'ok' || st.state === 'error' || typeof st.pct === 'number';
  const pct = st.state === 'ok' ? 100 : st.state === 'queued' ? 2 : known ? st.pct : 100;
  const since = st.sent ? ' · ' + L('gestart {t}', { t: ago(st.sent) }) : '';
  const rescanned = st.state === 'ok' && r.scanned_at && r.scanned_at >= st.at;
  const label = VULN_UP_STEPS[cur][1];
  const now = st.state === 'queued' ? '⏳ ' + L('Wachten op de agent: hij pakt de opdracht op bij zijn volgende rapport.')
    : st.state === 'ok' ? '✔ ' + L('Bijgewerkt: {p}', { p: esc((st.pkgs || []).join(' ')) })
    : st.state === 'error' ? '✘ ' + L('Bijwerken mislukt')
    : known ? `⏳ ${st.stage === 'verify' ? L('Controleren') : label}${st.detail ? `: <b>${esc(st.detail)}</b>` : ''}${st.count ? ` <span class="muted">(${esc(st.count)})</span>` : ''}`
    : '⏳ ' + L('Bezig met bijwerken') + since;
  const after = st.state !== 'ok' ? ''
    : rescanned ? `<p class="good-txt small">🔍 ${L('Opnieuw gescand: de lijst met kwetsbaarheden is bijgewerkt.')}</p>`
    : `<p class="muted small">🔍 ${L('RzSense scant opnieuw zodra de agent de nieuwe softwarelijst stuurt (meestal binnen een paar minuten).')}</p>`;
  const pk = st.pkgs || [];
  let nowTxt = now.replace(/^[⏳✔✘] /, '');
  if (nowTxt.startsWith(label + ': ')) nowTxt = nowTxt.slice(label.length + 2);   // de stap staat er al boven
  if (vulnUpShown()) updKeepScroll(); else { updKept.log = null; updKept.scroll = null; }
  modal({
    title: L('Bijwerken op {name}', { name: h.name }),
    body: `<div class="upd-dlg vu-dlg ${st.state === 'ok' ? 'ok' : st.state === 'error' ? 'error' : ''}">
      ${updHead(L('Bijwerken op {name}', { name: esc(h.name) }), [updChip(pk.length === 1 ? pk[0] : L('{n} pakketten', { n: pk.length }), true)], UPD_SRV)}
      <div class="upd-body">
        <div class="upd-prog">${updRing(known || st.state === 'queued' ? pct : null, st.state === 'ok' ? L('klaar') : st.state === 'error' ? L('gestopt') : since.replace(' · ', ''))}${updSteps(VULN_UP_STEPS, cur, st.state === 'queued' ? 'running' : st.state, nowTxt)}</div>
        ${busy && st.state === 'running' && !r.live ? `<p class="muted small">${L('Deze agent meldt geen tussenstand. Werk hem bij naar {v} voor een voortgangsbalk.', { v: '1.48.0' })}</p>` : ''}
        ${after}
        <p class="muted small">${L('Pakketten')}: <span class="mono">${esc(pk.join(' '))}</span></p>
        ${updLog(st.log, st.state === 'error', 8)}
      </div></div>`,
    buttons: busy ? [{ label: L('Op de achtergrond'), onClick: () => { $('#modal').close(); loadVulns(); } }]
      : [{ label: L('Sluiten'), cls: 'primary', onClick: () => { $('#modal').close(); loadVulns(); } }],
  });
  $('#modal').classList.add('upd-modal');
  updLogScroll();
  // Doorvragen tot klaar, en daarna tot de nieuwe scan er is (hooguit 15 minuten)
  if (busy || (st.state === 'ok' && !rescanned && Date.now() - new Date(st.at.replace(' ', 'T')) < 900000)) {
    vulnUpPoll = setTimeout(() => vulnUpgradeDialog(h), busy ? 2000 : 5000);
  } else if (!$('#tab-vulns').hidden) loadVulns();
}

async function vulnSoftware(h) {
  let r;
  try { r = await api('vuln_packages', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const { form } = modal({
    title: L('Alle software op {name}', { name: h.name }), wide: true,
    body: `<div style="padding:1rem 1.2rem">
      <p class="muted small">${esc(h.os || '')} · ${L('{n} pakketten', { n: r.packages.length })}</p>
      <input type="search" id="vs-search" placeholder="${L('Zoek pakket…')}" autocomplete="off">
      <div class="table-scroll" style="max-height:55vh"><table class="vuln-table" id="vs-table"></table></div>
    </div>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  const draw = () => {
    const q = $('#vs-search', form).value.trim().toLowerCase();
    const list = r.packages.filter((p) => !q || p.name.toLowerCase().includes(q) || p.source.toLowerCase().includes(q)).slice(0, 2000);
    $('#vs-table', form).innerHTML = `<thead><tr><th>${L('Pakket')}</th><th>${L('Versie')}</th><th>${L('Architectuur')}</th><th>${L('Bron')}</th><th></th></tr></thead><tbody>
      ${list.map((p) => `<tr><td>${esc(p.name)}</td><td class="mono small">${esc(p.version)}</td><td class="small">${esc(p.arch)}</td><td class="small muted">${p.source !== p.name ? esc(p.source) : ''}</td>
        <td>${p.vulns ? `<span class="vc sev3">🐞 ${p.vulns}</span>` : ''}</td></tr>`).join('')}</tbody>`;
  };
  $('#vs-search', form).oninput = draw;
  draw();
}

/** Instellingen: welke servers niet gescand worden (vinkjes → verborgen veld vuln_exclude) */
function vulnExcludeEditor() {
  const f = $('#settings-form');
  const el = $('#vuln-exclude-list');
  if (!f || !el || !f.vuln_exclude) return;
  const ex = new Set(String(f.vuln_exclude.value || '').split(',').filter(Boolean).map(Number));
  const hosts = state.hosts.filter((h) => h.mode !== 'remote');
  el.innerHTML = hosts.length ? hosts.map((h) => `<label class="chk-inline"><input type="checkbox" data-vex="${h.id}" ${ex.has(+h.id) ? 'checked' : ''}> ${esc(h.name)}</label>`).join(' ')
    : `<span class="muted small">${L('geen servers met een agent')}</span>`;
  $$('[data-vex]', el).forEach((c) => (c.onchange = () => {
    f.vuln_exclude.value = $$('[data-vex]', el).filter((x) => x.checked).map((x) => x.dataset.vex).join(',');
    markSettingsDirty(true, c);
  }));
}

// ---------------------------------------------------------------- ⏳ Verouderd: end-of-life (1.227.0)
let eolData = null;
let eolFilter = 'bad';
const EOL_LV = { crit: ['⛔', () => L('Buiten ondersteuning')], warn: ['⏳', () => L('Binnenkort')], info: ['ℹ️', () => L('Ter info')], ok: ['✔', () => L('Ondersteund')] };
async function loadEol() {
  const box = $('#eol-list');
  if (!box) return;
  try { eolData = await api('eol'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderEol();
}
function renderEol() {
  const d = eolData, box = $('#eol-list');
  if (!d || !box) return;
  const c = d.counts || {};
  const bad = (c.crit || 0) + (c.warn || 0);
  if ($('#vt-n-eol')) $('#vt-n-eol').textContent = bad ? String(bad) : '';
  $('#eol-counts').innerHTML = ['crit', 'warn', 'info', 'ok'].map((k) => `<span class="hc hc-${k === 'crit' ? 'crit' : k === 'warn' ? 'warn' : k === 'ok' ? 'ok' : 'unknown'}"><b>${c[k] || 0}</b> ${EOL_LV[k][0]} ${esc(EOL_LV[k][1]())}</span>`).join('');
  const st = d.state || {};
  $('#eol-info').textContent = st.ok ? L('Bijgewerkt {t}', { t: new Date(st.ok * 1000).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' }) }) + (st.error ? ' · ⚠ ' + st.error : '') : (st.error || L('Nog niet opgezocht: dat gebeurt eens per dag'));
  const chips = [['bad', L('Aandacht')], ['all', L('Alles')]];
  $('#eol-filter').innerHTML = chips.map(([k, l]) => `<button type="button" class="dchip ${eolFilter === k ? 'on' : ''}" data-eol-f="${k}">${l}</button>`).join('');
  const q = ($('#eol-search').value || '').toLowerCase();
  const rows = d.rows.filter((r) => (eolFilter === 'all' || ['crit', 'warn'].includes(r.level)) && (!q || `${r.host} ${r.label} ${r.cycle}`.toLowerCase().includes(q)));
  if (!d.rows.length) { box.innerHTML = `<p class="muted">${L('Nog geen softwarelijsten van de agents. Die komen met het volgende rapport (agent 1.47.0 of nieuwer).')}</p>`; return; }
  box.innerHTML = rows.length ? `<div class="table-scroll"><table class="vuln-table eol-tbl"><thead><tr><th>${L('Server')}</th><th>${L('Software')}</th><th>${L('Versie')}</th><th>${L('Updates tot')}</th><th>${L('Opvolger')}</th></tr></thead><tbody>${rows.map((r) => {
    const lv = EOL_LV[r.level] || EOL_LV.ok;
    const until = !r.known ? `<span class="muted">${L('onbekend bij endoflife.date')}</span>` : r.eol ? `${esc(new Date(r.eol + 'T12:00').toLocaleDateString(LOCALE, { dateStyle: 'medium' }))} <span class="muted small">${r.days < 0 ? L('{n} dagen geleden', { n: -r.days }) : L('over {n} dagen', { n: r.days })}</span>` : r.state === 'eol' ? L('al voorbij') : L('geen einddatum bekend');
    return `<tr class="eol-${r.level}"><td>${esc(r.host)}</td><td>${lv[0]} <b>${esc(r.label)} ${esc(r.cycle)}</b>${r.kind === 'os' ? ` <span class="pill">${L('systeem')}</span>` : ''}${r.lts ? ' <span class="pill">LTS</span>' : ''}${r.level === 'info' ? `<div class="muted small">${L('uit de pakketbron van je distributie: die levert vaak nog beveiligingsupdates')}</div>` : ''}</td>
      <td class="mono small">${esc(r.version)}</td><td>${until}</td><td>${r.successor ? esc(r.label + ' ' + r.successor) : '–'}</td></tr>`;
  }).join('')}</tbody></table></div>` : `<p class="muted">✔ ${L('Niets wat aandacht vraagt.')}</p>`;
}
function initEol() {
  initVulnTabs();
  if (!$('#eol-list')) return;
  $('#eol-search').oninput = renderEol;
  $('#eol-filter').onclick = (e) => { const b = e.target.closest('[data-eol-f]'); if (b) { eolFilter = b.dataset.eolF; renderEol(); } };
  $('#eol-refresh').onclick = async (e) => {
    e.target.disabled = true;
    try { eolData = await api('eol_refresh', {}); renderEol(); toast(L('Opgezocht bij endoflife.date')); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
}
