// ---------------------------------------------------------------- Alerts
/** Bericht zonder "[niet verzonden: …]" aan het eind; de reden apart (die staat bij het rode kanaal-icoon) */
function splitSent(msg) {
  const m = /\s*\[(?:niet verzonden|not sent): (.+)\]\s*$/s.exec(msg || '');
  return m ? [msg.slice(0, m.index), m[1]] : [msg || '', ''];
}

function channelIcons(a) {
  const tip = (title, icon) => `<span class="chi" title="${esc(title)}">${icon}</span>`;   // 1.224.4: de tekst al vertaald (L met een vaste tekst)
  const why = splitSent(a.message)[1];
  const bad = (title, icon) => `<span class="chi bad" title="${esc(title + (why ? ': ' + why : ''))}">${icon}</span>`;
  if (a.kind === 'auth' || a.kind === 'authfail') return `<span class="muted" title="${L('Alleen op deze pagina, niet verstuurd')}">–</span>`;
  if (a.kind === 'ack') return tip(L('Bevestiging'), '✔');
  const out = [];
  if (+a.sent_ok) out.push(tip(L('Verstuurd via push (Pushover/ntfy)'), '📲'));
  else if (/Pushover|ntfy/.test(why)) out.push(bad(L('Push mislukt'), '📲'));
  if (a.email_ok !== null && a.email_ok !== undefined) out.push(+a.email_ok ? tip(L('Verstuurd per e-mail'), '📧') : bad(L('E-mail mislukt'), '📧'));
  if (a.webhook_ok !== null) out.push(+a.webhook_ok ? tip(L('Verstuurd naar Home Assistant'), '🏠') : bad(L('Webhook mislukt'), '🏠'));
  // Nergens heen verstuurd (kanaal of regel staat uit): alleen op deze pagina. Geen ✉️, dat leek op een mislukte e-mail.
  if (!out.length) out.push(tip(L('Niet verstuurd: alleen op deze pagina (zie de meldregels)'), '🔕'));
  return out.join(' ');
}

/** "🔴 Critical", "✅ Hersteld", "✔ Bevestigd"… voor de meldingentabel */
function alertStatusText(a) {
  if (a.kind === 'ids') return '🛡️ IDS';
  if (a.kind === 'sec') return '⚠️ ' + L('Geweigerd');
  if (a.kind === 'auth') return '🔑 ' + L('Toegang');
  if (a.kind === 'authfail') return '❌ ' + L('Mislukt');
  if (a.kind === 'ack') return '✔ ' + L('Bevestigd');
  if (a.kind === 'maint') return '🔧 ' + L('Onderhoud');
  if (a.kind === 'info') return 'ℹ️ Info';
  const ev = (a.title.match(/^\S+ (HERSTELD|STABIEL|FLAPPEND|RECOVERED|STABLE|FLAPPING)/) || [])[1];
  if (ev) return { HERSTELD: '✅ ' + L('Hersteld'), RECOVERED: '✅ ' + L('Hersteld'), STABIEL: '⏸ ' + L('Stabiel'), STABLE: '⏸ ' + L('Stabiel'),
    FLAPPEND: '🔀 ' + L('Flapt'), FLAPPING: '🔀 ' + L('Flapt') }[ev];
  return ['✅ OK', '🟠 Warning', '🔴 Critical', '⚪ ' + L('Onbekend')][+a.status] || '';
}

// ---------------------------------------------------------------- Meldingen, per dag
// Alle meldingen van de bewaartermijn, per dag in een uitklapblok. Vandaag staat open; wat je open- of
// dichtklapt blijft zo tot je de pagina herlaadt.

async function loadAlerts() {
  try { alertData = await api('alerts', null, { days: histDays.alerts }); } catch (ex) { toast(ex.message, 'err'); return; }
  keepOpen($('#alerts'), renderAlerts);
}

/**
 * Vandaag staat altijd open (1.78.0), op Meldingen, IDS-incidenten en Logins. Klapte je vandaag dicht en komt er iets
 * nieuws bij, dan gaat hij weer open. @return true als er vandaag iets bij kwam (dan data-fresh op het blok)
 */
const todayCount = {};
function todayFresh(kind, store, today, n) {
  const prev = todayCount[kind];
  todayCount[kind] = { day: today, n };
  if (prev && prev.day === today && n > prev.n) {
    delete store[today];
    return true;
  }
  return false;
}
/** Leeg blok voor vandaag ("Vandaag · geen meldingen 👌"), zodat je ziet dat het rustig is */
function todayEmpty(today, text) {
  return `<div class="alert-day today-empty"><div class="ad-sum"><span class="ad-title">${esc(dayLabel(today, today))}</span><span class="muted small">${text}</span></div></div>`;
}

/**
 * Opnieuw tekenen (elke 20 seconden) zonder dat wat je openklapte weer dichtgaat: <details> (zoals Uitvoer of een
 * logboek) en uitgeklapte berichten in L1nes. Herkend aan de server, dag of check eromheen en de kop zonder getallen.
 */
function keepOpen(root, draw) {
  if (!root) return draw();
  const ids = (el) => (el ? [el.dataset.host, el.dataset.day, el.dataset.cid, el.dataset.id].filter(Boolean).join(',') : '');
  const key = (el) => {
    const head = el.tagName === 'DETAILS' ? ($(':scope > summary', el)?.textContent || '') : el.textContent;
    return [ids(el.parentElement && el.parentElement.closest('[data-host],[data-day],[data-cid],[data-id]')), ids(el),
      head.replace(/[\d\s.,:%×()+–-]+/g, ' ').trim().slice(0, 80)].join('|');
  };
  const was = new Map();
  root.querySelectorAll('details').forEach((d) => was.set(key(d), d.open));
  const rows = new Set([...root.querySelectorAll('tr.at-open')].map(key));
  const y = window.scrollY;
  withMorph(draw);
  root.querySelectorAll('details').forEach((d) => { const k = key(d); if (!d.dataset.fresh && was.has(k) && d.open !== was.get(k)) d.open = was.get(k); });
  if (rows.size) root.querySelectorAll('tr').forEach((tr) => { if (rows.has(key(tr))) tr.classList.add('at-open'); });
  if (Math.abs(window.scrollY - y) > 2) window.scrollTo(0, y);
}

// ---------------------------------------------------------------- IDS
const IDS_FW = {
  active: (h) => `⛔ ${L('blokkeren actief')}${h.blocks ? ` · ${L('{n} geblokkeerd', { n: h.blocks })}` : ''}`,
  off: () => L('alleen melden'),
  unsupported: () => L('TrueNAS: alleen detecteren (aan te zetten bij 🛡️ Hardening)'),
  disabled: () => L('blokkeren uit op de server (IDS_BLOCK=0)'),
  error: (h) => `<span class="bad">✘ ${L('firewall: {e}', { e: esc(h.fw_error || L('fout')) })}</span>`,
};

/**
 * 1.217.0: incidenten afhandelen zonder de pagina opnieuw te laden: de regels gaan meteen weg (of terug), de gegevens op de
 * pagina worden bijgewerkt en de server krijgt één aanvraag. Mislukt die, dan haalt de pagina alles opnieuw op.
 */
let idsRedrawT = 0;
async function idsAct(ids, action) {
  if (!ids.length) return;
  const set = new Set(ids.map(Number));
  const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', ' ');
  (idsData ? idsData.incidents : []).forEach((i) => {
    if (!set.has(+i.id)) return;
    if (action === 'reopen') { i.status = 'open'; i.handled_by = null; i.handled_at = null; }
    else if (i.status === 'open') { i.status = 'done'; i.handled_by = ($('.acct-name') || {}).textContent || ''; i.handled_at = now; }
  });
  if (action !== 'reopen') {
    $$('#tab-ids [data-id], #tab-ids [data-many], #tab-ids [data-sel-id]').forEach((x) => {
      const mine = (x.dataset.many || x.dataset.id || x.dataset.selId || '').split(',').map(Number);
      if (!mine.length || !mine.every((y) => set.has(y))) return;
      const r = x.closest('.irow, .arow, .ids-noisy-i');
      if (r) r.classList.add('ids-going');
    });
  }
  IDS_SEL.clear(); idsSelBar();
  if (state.ids_open !== undefined) {
    state.ids_open = Math.max(0, (+state.ids_open || 0) + (action === 'reopen' ? ids.length : -ids.length));
    const bd = $('#ids-badge'); if (bd) { bd.hidden = !state.ids_open; bd.textContent = state.ids_open || ''; }
  }
  clearTimeout(idsRedrawT);
  idsRedrawT = setTimeout(() => keepOpen($('#tab-ids'), () => renderIds()), 320);   // na de animatie, zonder te laden
  try {
    await api('ids_incident', { ids, action });
    if (action === 'normal') toast(ids.length > 1 ? L('{n} afgehandeld; dit wordt voortaan niet meer gemeld', { n: ids.length }) : L('Afgehandeld; dit wordt voortaan niet meer gemeld'));
  } catch (ex) { toast(ex.message, 'err'); clearTimeout(idsRedrawT); loadIds(); }
}

/** 1.217.0: meerdere incidenten aanvinken en in één keer afhandelen */
const IDS_SEL = new Set();
function idsSelBar() {
  let bar = $('#ids-selbar');
  if (!bar) {
    const host = $('#ids-incidents'); if (!host) return;
    bar = document.createElement('div'); bar.id = 'ids-selbar'; bar.className = 'ids-selbar need-admin'; bar.hidden = true;
    host.parentNode.insertBefore(bar, host);
  }
  $$('#ids-incidents .ids-sel').forEach((c) => { c.checked = IDS_SEL.has(+c.dataset.selId); });
  bar.hidden = !IDS_SEL.size;
  if (!IDS_SEL.size) return;
  const ids = [...IDS_SEL].join(',');
  bar.innerHTML = `<b>${L('{n} gekozen', { n: IDS_SEL.size })}</b>
    <button type="button" class="btn small-btn primary" data-ids="handle" data-many="${ids}">✔ ${L('Afhandelen')}</button>
    <button type="button" class="btn small-btn" data-ids="normal" data-many="${ids}" title="${esc(L('Afhandelen en dit voortaan niet meer melden'))}">${L('Dit is normaal')}</button>
    <button type="button" class="linkbtn" data-ids-sel-clear>${L('Keuze wissen')}</button>`;
}
document.addEventListener('change', (e) => {
  const c = e.target.closest && e.target.closest('#ids-incidents .ids-sel');
  if (!c) return;
  (c.dataset.selId.split(',').map(Number)).forEach((id) => (c.checked ? IDS_SEL.add(id) : IDS_SEL.delete(id)));
  idsSelBar();
});
document.addEventListener('click', (e) => {
  if (e.target.closest && e.target.closest('[data-ids-sel-clear]')) { IDS_SEL.clear(); idsSelBar(); }
  const m = e.target.closest && e.target.closest('[data-ids-more]');
  if (m) { const day = m.dataset.idsMore; idsDayLimit[day] = (idsDayLimit[day] || IDS_DAY_ROWS) + 200; idsFillDay(m.closest('.alert-day')); }
});

/** 1.217.0: een dag pas opbouwen als hij open gaat, en eerst de eerste regels (Meer tonen voor de rest) */
const IDS_DAY_ROWS = 60;
const idsDayLimit = {};
let idsDayItems = new Map();
function idsFillDay(det) {
  if (!det) return;
  const items = idsDayItems.get(det.dataset.day) || [];
  const groups = bundleRuns(items, (i) => `${i.type}|${i.host_id}|${i.status}`, true);
  const lim = idsDayLimit[det.dataset.day] || IDS_DAY_ROWS;
  const html = groups.slice(0, lim).map((g) => {
    if (g.items.length === 1) return idsRow(g.items[0]);
    // In het venster loop je met de nummers door precies de incidenten van deze bundel
    const key = 'i' + g.items[g.items.length - 1].id;
    return `<div class="bundle${idsBundles.has(key) ? ' open' : ''}" data-bundle="${key}">${idsRow(g.items[0], g.items, g.items)}${g.items.slice(1).map((i) => idsRow(i, null, g.items)).join('')}</div>`;
  }).join('');
  const rest = groups.length - lim;
  $('.ad-body', det).innerHTML = html + (rest > 0 ? `<button type="button" class="attn-more" data-ids-more="${esc(det.dataset.day)}">▾ ${L('Meer tonen ({n})', { n: rest })}</button>` : '');
  det.dataset.built = '1';
  idsSelBar();
}

/** 1.217.0: wat vaak terugkomt (zelfde soort en titel op dezelfde server): voorstel om het als normaal te markeren */
function idsNoisy(d) {
  const g = new Map();
  d.incidents.forEach((i) => {
    const k = `${i.host_id}|${i.type}|${i.title}`;
    if (!g.has(k)) g.set(k, { i, n: 0, open: [] });
    const x = g.get(k); x.n += Math.max(1, +i.hits || 1); if (i.status === 'open') x.open.push(i.id);
  });
  const top = [...g.values()].filter((x) => x.n >= 10 && x.open.length).sort((a, b) => b.n - a.n).slice(0, 3);
  if (!top.length) return '';
  return `<div class="card ids-noisy need-admin"><div class="ids-noisy-h">🔁 ${L('Komt vaak terug')} <span class="muted small">· ${L('is dit normaal? Dan meldt het IDS het voortaan niet meer')}</span></div>
    ${top.map((x) => `<div class="ids-noisy-i"><span><b>${esc(x.i.title)}</b> <span class="muted">· ${esc(x.i.host_name)} · ${L('{n}× in {d} dagen', { n: x.n.toLocaleString(LOCALE), d: d.days })} · ${L('{n} open', { n: x.open.length })}</span></span>
      <span class="ids-actions"><button type="button" class="btn small-btn" data-ids="normal" data-many="${x.open.join(',')}">${L('Dit is normaal')}</button>
      <button type="button" class="btn small-btn" data-ids="handle" data-many="${x.open.join(',')}">✔ ${L('Afhandelen ({n})', { n: x.open.length })}</button></span></div>`).join('')}</div>`;
}

/*
 * 1.220.0: de IDS-pagina voelt direct:
 *  1. eerst het vorige beeld (uit het geheugen, of na herladen uit sessionStorage van dit tabblad) met "bijwerken…";
 *  2. de incidenten tekenen zodra ze binnen zijn, Toegang (logins) daarna op de achtergrond;
 *  3. de allereerste keer grijze blokken in plaats van een lege pagina.
 * Hoe lang het duurde staat bij de tijd rechtsboven (muis erop) en per onderdeel bij Instellingen → Prestaties en fouten.
 */
const IDS_CACHE_KEY = 'rzs-ids-cache';
let idsBusy = false, idsAgain = false;
function idsCacheLoad() {
  if (idsData) return true;
  try {
    const c = JSON.parse(sessionStorage.getItem(IDS_CACHE_KEY) || 'null');
    if (c && c.ids && Date.now() - c.at < 6 * 3600000) { idsData = c.ids; accData = c.acc || accData; return true; }
  } catch (ex) { /* geen opslag */ }
  return false;
}
function idsCacheSave() {
  try {
    const raw = JSON.stringify({ at: Date.now(), ids: idsData, acc: accData });
    if (raw.length < 3e6) sessionStorage.setItem(IDS_CACHE_KEY, raw); else sessionStorage.removeItem(IDS_CACHE_KEY);
  } catch (ex) { /* vol of uit: dan de volgende keer gewoon laden */ }
}
function idsSkeleton() {
  const sk = (n, h) => Array.from({ length: n }, () => `<div class="sk-blk" style="height:${h}px"></div>`).join('');
  if ($('#ids-kpis')) $('#ids-kpis').innerHTML = `<div class="sk-row">${sk(5, 92)}</div>`;
  if ($('#ids-attn')) $('#ids-attn').innerHTML = sk(4, 46);
}
async function loadIds() {
  if (idsBusy) { idsAgain = true; return; }   // al bezig: daarna nog één keer (bv. na een actie)
  idsBusy = true;
  const t0 = performance.now();
  const had = idsCacheLoad();
  const tab = $('#tab-ids');
  tab.classList.add('ids-syncing');
  if (had) { keepOpen(tab, () => { renderIds(); if (accData) renderAccess(); }); showIdsTab(); } else idsSkeleton();
  const accP = api('ids_access').then((d) => { accData = d; }).catch(() => null);
  try {
    idsData = await api('ids', null, { days: histDays.ids });
  } catch (ex) { idsBusy = false; tab.classList.remove('ids-syncing'); toast(ex.message, 'err'); return; }
  const t1 = performance.now();
  keepOpen(tab, () => renderIds());
  if (!had) showIdsTab();   // Live en Regels worden daar (opnieuw) geladen
  loadWebSites();   // 1.222.0: los, zodat het de pagina niet ophoudt
  const t2 = performance.now();
  await accP;
  if (accData) keepOpen(tab, () => { renderIds(); renderAccess(); });
  tab.classList.remove('ids-syncing');
  idsBusy = false;
  idsCacheSave();
  const s = (ms) => dec((ms / 1000).toFixed(1));
  const tip = L('Geladen in {a} s: incidenten {b} s, tekenen {c} s, toegang {d} s', { a: s(performance.now() - t0), b: s(t1 - t0), c: s(t2 - t1), d: s(performance.now() - t2) });
  if ($('#ids-last')) $('#ids-last').title = tip;
  if (idsAgain) { idsAgain = false; loadIds(); }
}

function showIdsTab() {
  if (!['inc', 'map', 'acc', 'blk', 'prot', 'live', 'rules', 'anom', 'mitre'].includes(idsTab)) idsTab = 'inc';
  $$('#ids-tabs .seg-btn').forEach((x) => x.classList.toggle('active', x.dataset.it === idsTab));
  $$('#tab-ids .ids-pane').forEach((x) => { x.hidden = x.dataset.pane !== idsTab; });
  const nAnom = ((idsData && idsData.incidents) || []).filter((i) => (i.type === 'chain' || i.type === 'anomaly') && i.status === 'open').length;
  $('#it-n-anom').textContent = nAnom || '';
  withPageWait('ids', () => {   // 1.202.0
    if (idsTab === 'blk' && isAdmin()) loadBlocks();
    if (idsTab === 'live') loadEdrFeed();
    if (idsTab === 'rules') { ruleWhere = 'ids'; if (ruleSrc === 'exc') ruleSrc = ''; loadEdrRules(); }
    if (idsTab === 'anom') loadAnom();
    if (idsTab === 'mitre') loadMitre();
    if (idsTab === 'prot') loadProt();
    if (idsTab === 'map') { initAttackMap(); loadAttackMap(); }   // 1.149.0
  });
  crumbs();   // 1.199.0
}

// ---------------------------------------------------------------- IDS: 🛰️ Live en 🔏 Regels (endpoint-bewaking, 1.110.0)
let edrFeed = null, edrRules = null, ruleSrc = '';
/* 1.162.0: de regels staan op twee plekken: IDS → Regels (bekijken, met treffers) en Instellingen → IDS → Endpoint-bewaking
   → Regels en uitzonderingen (aan/uit en uitzonderingen). ruleWhere zegt welke nu getekend wordt. */
let ruleWhere = 'ids';
const ruleBox = () => $(ruleWhere === 'set' ? '#edr-rules-set' : '#edr-rules');
const ruleQ = () => $(ruleWhere === 'set' ? '#rule-q-set' : '#rule-q');
const EDR_KIND_IC = { exec: '⚙️', net: '🌐', file: '📄', audit: '🔐', ctr: '📦', sur: '🦉' };
const EDR_AUDIT_KEY = { rzmon_id: () => L('accounts/sudo'), rzmon_persist: () => L('cron/systemd/ssh'), rzmon_mod: () => L('kernelmodule') };
const edrBase = (p) => String(p || '').split('/').pop();
function edrSummary(e) {
  const v = e.ev || {};
  switch (e.kind) {
    case 'exec': return `<b>${esc(edrBase(v.exe) || v.comm || '?')}</b> <code class="edr-cmd">${esc((v.cmd || '').slice(0, 160))}</code> <span class="muted">· ${esc(v.u || v.uid || '?')} · ${L('ouder')} ${esc(v.pc || '?')}</span>`;
    case 'net': return v.dir === 'listen' ? `<b>${esc(v.comm || edrBase(v.exe) || '?')}</b> ${L('luistert op poort {p}', { p: +v.lp })}`
      : `<b>${esc(v.comm || edrBase(v.exe) || '?')}</b> → <code>${esc(v.rip || '')}:${+v.rp}</code>`;
    case 'file': return `<code>${esc(v.path || '')}</code> <span class="muted">· ${fmtBytes(+v.size || 0)}${+v.elf ? ' · ELF' : ''}${v.susp ? ' · ' + esc(v.susp) : ''}</span>`;
    case 'audit': return `${esc((EDR_AUDIT_KEY[v.key] || (() => v.key))())}: <code>${esc(v.name || '')}</code> <span class="muted">· ${esc(edrBase(v.exe))} · ${esc(v.u || v.uid || '?')}</span>`;
    case 'ctr': return `${esc(v.rt || '')} <b>${esc(v.act || '')}</b> ${esc(v.name || '')} <span class="muted">${esc(v.img || '')}${v.cmd ? ' · ' + esc(v.cmd) : ''}${+v.priv ? ' · --privileged' : ''}</span>`;
    default: return esc(e.sig);
  }
}
/** Groene regel: iets van RzSense zelf, toegestaan (1.114.0) */
const EDR_OWN = { agent: () => L('agent'), rzmon: () => 'RzSense', net: () => L('verkeer met RzSense'), exc: () => L('uitzondering') };
function edrOwn(e) {
  const v = e.ev || {};
  const who = (EDR_OWN[e.own] || (() => e.own))() + (e.own === 'exc' && v._exc ? ': ' + v._exc : '');
  const tag = `<span class="own-tag">✔ ${L('toegestaan')} · ${esc(who)}</span>`;
  if (e.kind === 'own') {
    const n = v.n || {};
    const parts = [n.exec ? L('{n} proces(sen)', { n: n.exec }) : '', n.net ? L('{n} verbinding(en)', { n: n.net }) : '', n.audit ? L('{n} bewaakt(e) pad(en)', { n: n.audit }) : '', n.file ? L('{n} bestand(en)', { n: n.file }) : ''].filter(Boolean);
    return `<details class="own-det"><summary>${tag} ${esc(parts.join(', '))}</summary><ul class="small">${(v.s || []).map((x) => `<li><code>${esc(x)}</code></li>`).join('')}</ul></details>`;
  }
  if (e.kind === 'sur') return `${tag} Suricata: ${esc(v.sig || '')} <span class="muted">· ${esc(v.src)} → ${esc(v.dst)}:${+v.dport}</span>`;
  if (e.kind === 'dns') return `${tag} ${esc(v.title || '')}`;
  if (e.kind === 'web') return `${tag} ${esc(v.method || '')} <code>${esc(v.uri || '')}</code> <span class="muted">${L('van {ip}', { ip: esc(v.ip) })}</span>`;
  if (e.kind === 'ids') return `${tag} ${esc(v.title || '')} <span class="muted small">${esc((v.detail || '').slice(0, 160))}</span>`;   // 1.132.0
  return `${tag} ${edrSummary(e)}${v._would ? `<br><span class="muted small">${e.own === 'exc' ? L('zou {r} raken', { r: esc(v._would) }) : L('zou {r} raken, maar is RzSense zelf', { r: esc(v._would) })}</span>` : ''}`;
}
// ---------------------------------------------------------------- IDS → Live (1.219.0: begrijpelijk)
// Bovenaan een oordeel met de laatste 24 uur per uur, de soorten met aantallen, en Aandacht / Alles / Toegestaan.
// Elke regel in gewone taal; herhalingen samengevoegd; bij een treffer uitleg, wat je kunt doen en knoppen.
let lvMode = uiGet('edr_mode', 'rzmon-edr-mode', 'hits'), lvKind = '', lvOpen = new Set(), lvSeenPrev = null;
const LV_IC = {
  exec: '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9z"/>',
  net: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  file: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
  audit: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  ctr: '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/>',
  ids: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
  own: '<path d="M20 6L9 17l-5-5"/>',
};
const lvIc = (k) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${LV_IC[k] || LV_IC.ids}</svg>`;
const LV_KIND = { exec: () => L('Proces'), net: () => L('Verbinding'), file: () => L('Bestand'), audit: () => L('Systeem'), ctr: () => L('Container'), sur: () => L('Netwerk-IDS'), dns: () => 'DNS', web: () => L('Web'), ids: () => 'IDS', own: () => L('RzSense') };
const LV_KINDS = { exec: () => L('Processen'), net: () => L('Verbindingen'), file: () => L('Bestanden'), audit: () => L('Accounts en systeem'), ctr: () => L('Containers'), sur: () => L('Netwerk-IDS'), own: () => L('RzSense zelf') };
const lvIcKind = (k) => (['sur', 'dns', 'web', 'ids'].includes(k) ? 'ids' : k);
/** Eén zin in gewone taal, met een korte toelichting eronder */
function lvSentence(e) {
  const v = e.ev || {}, prog = esc(edrBase(v.exe) || v.comm || '?'), who = esc(v.u || (v.uid !== undefined ? 'uid ' + v.uid : '?'));
  switch (e.kind) {
    case 'exec':
      if (v.pc === 'cron' || v.pc === 'crond') return [L('<b>cron</b> startte <b>{p}</b>', { p: prog }), `${L('gebruiker {u}', { u: who })} · <code>${esc((v.cmd || '').slice(0, 120))}</code>`];
      return [L('<b>{u}</b> startte het programma <b>{p}</b>', { u: who, p: prog }), `<code>${esc((v.cmd || '').slice(0, 140))}</code>${v.pc ? ' · ' + L('gestart door {x}', { x: esc(v.pc) }) : ''}`];
    case 'net':
      if (v.dir === 'listen') return [L('<b>{p}</b> luistert op poort <b>{n}</b>', { p: esc(v.comm || edrBase(v.exe) || '?'), n: +v.lp }), ''];
      return [L('<b>{p}</b> maakte verbinding met <b>{a}</b>', { p: esc(v.comm || edrBase(v.exe) || '?'), a: esc(`${v.rip || ''}:${+v.rp}`) }), v.dir === 'in' ? L('inkomend') : L('uitgaand')];
    case 'file': return [L('Nieuw {w} neergezet: <b>{f}</b>', { w: +v.elf ? L('programma') : L('bestand'), f: esc(v.path || '') }), [fmtBytes(+v.size || 0), v.susp ? esc(v.susp) : ''].filter(Boolean).join(' · ')];
    case 'audit': {
      const what = { rzmon_id: L('Accounts of sudo-rechten gewijzigd'), rzmon_persist: L('Geplande taak, dienst of SSH-sleutels gewijzigd'), rzmon_mod: L('Kernelmodule geladen') }[v.key] || L('Bewaakt bestand gewijzigd');
      return [`${what}: <b>${esc(v.name || '')}</b>`, L('door {p}, gebruiker {u}', { p: prog, u: who })];
    }
    case 'ctr': {
      const act = { start: L('gestart'), stop: L('gestopt'), die: L('gestopt'), restart: L('opnieuw gestart'), exec: L('iemand opende er een shell in'), create: L('aangemaakt') }[v.act] || esc(v.act || '');
      return [L('Container <b>{n}</b>: {a}', { n: esc(v.name || ''), a: act }), [esc(v.img || ''), +v.priv ? '--privileged' : ''].filter(Boolean).join(' · ')];
    }
    default: return [edrOwn(e), ''];
  }
}
/** Technische details (inklapbaar) */
function lvTech(e) {
  const v = e.ev || {}, rows = [];
  const add = (k, x) => { if (x !== undefined && x !== null && x !== '') rows.push(`<dt>${k}</dt><dd>${esc(String(x))}</dd>`); };
  add(L('Programma'), v.exe ? v.exe + (v.pid ? ` (pid ${v.pid})` : '') : v.comm);
  add(L('Opdracht'), v.cmd);
  add(L('Gebruiker'), v.u || v.uid);
  add(L('Gestart door'), v.pc);
  add(L('Adres'), v.rip ? `${v.rip}:${v.rp}` : v.lp ? L('poort {n}', { n: v.lp }) : '');
  add(L('Bestand'), v.path || v.name);
  add('Image', v.img);
  add(L('Regel'), e.rule ? `${e.rule_title || e.rule} (${e.rule})` : '');
  add(L('Tijd'), fmtDateTime(e.at));
  return rows.length ? `<details class="lv-tech"><summary>${L('Technische details')}</summary><dl>${rows.join('')}</dl></details>` : '';
}
function lvHitOpen(e) {
  const blocking = !!(edrFeed.sum && edrFeed.sum.blocking);
  const what = e.rule_what || L('Een regel van de endpoint-bewaking was raak. Bekijk het incident voor het bewijs en de uitleg.');
  const canKill = isAdmin() && !e.own && ['exec', 'audit', 'net'].includes(e.kind) && +(e.ev || {}).pid > 1 && +(e.ev || {}).st > 0 && Date.now() - new Date(e.at.replace(' ', 'T')).getTime() < 86400000;
  return `<div class="lv-open">
    <div class="lv-box mean"><h5>${L('Wat betekent dit?')}</h5>${esc(what)}</div>
    <div class="lv-box"><h5>${L('Wat kun je doen?')}</h5>${L('1. Herken je het en deed jij (of een update) dit? Dan is het normaal.')}<br>${L('2. Niet herkend? Bekijk het incident en stop zo nodig het proces.')}
      <br><span class="muted">${blocking ? L('Modus Blokkeren: RzSense grijpt zelf in als de regel dat zegt (zie het incident).') : L('Modus Leren: RzSense greep niet in.')}</span></div>
    <div class="lv-btns">${e.inc ? `<button type="button" class="btn small-btn primary" data-lv-inc="${e.inc}">${L('Bekijk incident')}</button>` : `<a class="btn small-btn primary" href="#ids/inc" data-goto="ids/inc">${L('Naar incidenten')}</a>`}
      ${canKill ? `<button type="button" class="btn small-btn" data-lv-kill="${e.id}">⛔ ${L('Proces stoppen')}</button>` : ''}
      ${e.inc ? `<button type="button" class="btn small-btn need-admin" data-lv-normal="${e.inc}">✔ ${L('Dit is normaal')}</button>${aiBtn('ids', e.inc, true)}` : ''}</div>
    ${lvTech(e)}</div>`;
}
const lvDay = (at) => { const d = at.slice(0, 10), t = new Date(), y = new Date(Date.now() - 86400000);
  const loc = (x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
  return d === loc(t) ? L('Vandaag') : d === loc(y) ? L('Gisteren') : new Date(d + 'T12:00:00').toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long' }); };
function lvRow(e) {
  const hit = e.rule && !e.own, cls = hit ? (e.sev >= 2 ? 'c' : 'w') : e.own ? 'ok' : '';
  const [s1, s2] = lvSentence(e);
  const open = hit && lvOpen.has(e.id);
  const t = e.at.slice(11, 16), ki = e.kind === 'own' ? 'own' : lvIcKind(e.kind);
  const ownTag = e.own && e.kind !== 'own' ? `<span class="okl">✔ ${L('toegestaan')} · ${esc((EDR_OWN[e.own] || (() => e.own))())}${e.own === 'exc' && (e.ev || {})._exc ? ': ' + esc(e.ev._exc) : ''}</span>` : '';
  const span = e.n > 1 ? L('{n}× tussen {a} en {b}', { n: e.n, a: e.first.slice(11, 16), b: t }) : '';
  return `<div class="lv-row ${cls}${open ? ' open' : ''}" data-key="e${e.id}" ${hit ? `data-lv-row="${e.id}" tabindex="0" role="button" aria-expanded="${open}"` : ''}>
    <span class="st"></span><span class="lv-t" title="${esc(fmtDateTime(e.at))}">${t}</span>
    <span class="lv-k">${lvIc(ki)} ${esc((LV_KIND[e.kind] || (() => e.kind))())}</span>
    <span class="lv-s">${s1}${s2 ? `<span class="why">${s2}</span>` : ''}${hit ? `<span class="hitl">${e.sev >= 2 ? '●' : '▲'} ${esc(e.rule_title || e.rule)} · ${e.sev >= 2 ? 'Critical' : 'Warning'}</span>` : ''}${ownTag ? `<span class="why">${ownTag}</span>` : ''}</span>
    ${e.n > 1 ? `<span class="lv-n" title="${esc(span)}">${e.n}×</span>` : '<span></span>'}<span class="lv-h">${esc(e.host)}</span><span class="lv-go">${hit ? (open ? '▾' : '▸') : ''}</span>
    <span class="lv-meta">${t} · ${esc(e.host)}${span ? ' · ' + esc(span) : ''}</span>
    ${open ? lvHitOpen(e) : ''}</div>`;
}
/** Herhalingen samenvoegen: zelfde server, soort en handtekening (geen treffers) op dezelfde dag */
function lvGroup(evs) {
  const out = [], seen = new Map();
  for (const e of evs) {
    if (e.rule && !e.own) { out.push(e); continue; }
    const k = `${e.host_id}|${e.kind}|${e.sig}|${e.own || ''}|${e.at.slice(0, 10)}`;
    const g = seen.get(k);
    if (g) { g.n++; g.first = e.at; continue; }
    const x = { ...e, n: 1, first: e.at };
    seen.set(k, x); out.push(x);
  }
  return out;
}
function lvHero(sum, hitsListed) {
  const d = sum.day, crit = sum.kinds && Object.values(sum.kinds).some((k) => k.crit > 0);
  const st = hitsListed ? (crit ? 2 : 1) : 0;
  const title = st ? (hitsListed === 1 ? L('1 ding vraagt aandacht') : L('{n} dingen vragen aandacht', { n: hitsListed })) : L('Alles rustig');
  const sub = d.n ? L('<b>{n} gebeurtenissen</b> gezien op <b>{h} servers</b> in de laatste 24 uur. {o} daarvan zijn van RzSense zelf of een uitzondering.', { n: d.n.toLocaleString(LOCALE), h: d.hosts, o: d.own.toLocaleString(LOCALE) })
    : L('In de laatste 24 uur kwam er niets binnen van de endpoint-bewaking.');
  const mx = Math.max(1, ...sum.hours.map((h) => h.n));
  const hr = (back) => { const x = new Date(Date.now() - back * 3600000); return String(x.getHours()).padStart(2, '0') + ':00'; };
  const bars = sum.hours.map((h, i) => `<span class="${h.sev >= 2 ? 'h' : h.sev ? 'w' : ''}" style="height:${h.n ? Math.max(4, Math.round(h.n / mx * 100)) : 2}%" title="${esc(L('{t}: {n} gebeurtenissen', { t: hr(23 - i), n: h.n }))}"></span>`).join('');
  return `<div class="lv-hero s${st}"><span class="big" aria-hidden="true">${st === 2 ? '🔴' : st ? '🟠' : '🟢'}</span>
    <div class="lv-hero-t"><h3>${title}</h3><p>${sub}</p>
      <div class="act">${st && lvMode !== 'hits' ? `<button type="button" class="btn small-btn primary" data-lv-mode="hits">${hitsListed === 1 ? L('Bekijk de treffer') : L('Bekijk de {n} treffers', { n: hitsListed })}</button>` : ''}</div>
      ${xpHtml(L('Wat is deze pagina?'), L('<b>Live:</b> alles wat de agents de laatste 3 dagen zagen op je servers: programma\'s die starten, verbindingen, nieuwe bestanden, wijzigingen aan accounts en systeem, en containers. <b>Treffer:</b> een gebeurtenis die op een regel past; die wordt ook een IDS-incident (rood = critical, oranje = warning). <b>Toegestaan:</b> iets van RzSense of de agent zelf, of iets waarvoor je een uitzondering maakte (groen); dat wordt nooit tegengehouden. <b>Samengevoegd:</b> wat vaak terugkomt staat er één keer, met hoe vaak (bv. 48×).'))}</div>
    <div class="lv-spark"><div class="lg"><span><i class="n"></i>${L('gebeurtenissen per uur')}</span><span><i class="c"></i>${L('met een treffer')}</span></div>
      <div class="bars">${bars}</div><div class="lv-ax"><span>${hr(24)}</span><span>${hr(18)}</span><span>${hr(12)}</span><span>${hr(6)}</span><span>${L('nu')}</span></div></div></div>`;
}
async function loadEdrFeed() {
  const host = $('#edr-host').value;
  if (lvSeenPrev === null) {   // "nieuw sinds je laatste bezoek": het moment van het vorige bezoek, deze sessie vast
    try { lvSeenPrev = sessionStorage.getItem('rzs-live-prev') ?? localStorage.getItem('rzs-live-seen') ?? ''; sessionStorage.setItem('rzs-live-prev', lvSeenPrev); } catch (ex) { lvSeenPrev = ''; }
  }
  try { localStorage.setItem('rzs-live-seen', new Date().toISOString().slice(0, 19).replace('T', ' ')); } catch (ex) { /* geen opslag */ }
  try {
    edrFeed = await api('edr_feed', null, { limit: 300, ...(host ? { host } : {}), ...(lvMode === 'hits' ? { hits: 1, own: 0 } : lvMode === 'own' ? { only_own: 1 } : {}) });
  } catch (ex) { $('#edr-feed').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sel = $('#edr-host');
  if (sel.options.length < 2) sel.innerHTML += (state.hosts || []).filter((h) => h.mode === 'agent').map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
  renderLive();
}
function renderLive() {
  if (!edrFeed) return;
  const sum = edrFeed.sum || { day: { n: 0, hits: 0, own: 0, hosts: 0 }, kinds: {}, hours: Array(24).fill({ n: 0, sev: 0 }), list: { all: 0, hits: 0, own: 0 } };
  const nf = (n) => (+n || 0).toLocaleString(LOCALE);
  $('#edr-n-hits').textContent = nf(sum.list.hits); $('#edr-n-all').textContent = nf(sum.list.all); $('#edr-n-own').textContent = nf(sum.list.own);
  $$('#edr-mode [data-lv-mode]').forEach((b) => { b.classList.toggle('on', b.dataset.lvMode === lvMode); b.setAttribute('aria-selected', b.dataset.lvMode === lvMode); });
  $('#edr-hero').innerHTML = edrFeed.on ? lvHero(sum, sum.list.hits) : `<div class="lv-hero s1"><span class="big">⚠️</span><div class="lv-hero-t"><h3>${L('Endpoint-bewaking staat uit')}</h3><p>${L('Zet hem aan bij Instellingen → IDS → Endpoint-bewaking.')}</p></div></div>`;
  const order = ['exec', 'net', 'file', 'audit', 'ctr', 'sur'];
  const kinds = Object.entries(sum.kinds).filter(([k]) => k !== 'own' && k !== 'dns' && k !== 'web' && k !== 'ids').sort((a, b) => order.indexOf(a[0]) - order.indexOf(b[0]));
  $('#edr-kinds').innerHTML = kinds.length ? kinds.map(([k, x]) => `<button type="button" class="lv-kind${lvKind === k ? ' on' : ''}" data-lv-kind="${k}" aria-pressed="${lvKind === k}" title="${esc(L('Laatste 24 uur · klik om alleen deze soort te tonen'))}">
      ${lvIc(lvIcKind(k))} ${esc((LV_KINDS[k] || (() => k))())} <b>${nf(x.n)}</b>${x.hits ? ` <span class="hit ${x.crit ? 'c' : 'w'}">· ${x.hits}</span>` : ''}</button>`).join('') : '';
  $('#edr-info').textContent = edrFeed.on ? `● ${L('live')} · ${L('laatste 3 dagen')}${lvMode !== 'hits' ? ' · ' + L('herhalingen samengevoegd') : ''}` : '';
  // nieuwste eerst op tijd (een agent kan oudere gebeurtenissen later sturen, na een storing)
  let evs = [...edrFeed.events].sort((x, y) => (x.at < y.at ? 1 : x.at > y.at ? -1 : y.id - x.id));
  if (lvKind) evs = evs.filter((e) => e.kind === lvKind);
  const rows = lvGroup(evs);
  let html = '', day = '', newShown = false;
  const newer = lvSeenPrev && rows.some((e) => e.at > lvSeenPrev) && rows.some((e) => e.at <= lvSeenPrev);
  for (const e of rows) {
    const dd = lvDay(e.at);
    if (dd !== day) { html += `${day ? '</div>' : ''}<div class="lv-day">${esc(dd)}</div><div class="lv-list">`; day = dd; }
    if (newer && !newShown && e.at <= lvSeenPrev) { html += `<div class="lv-new">${L('nieuw sinds je laatste bezoek ({t})', { t: esc(lvSeenPrev.slice(11, 16)) })}</div>`; newShown = true; }
    html += lvRow(e);
  }
  if (day) html += '</div>';
  const legend = `<div class="lv-legend"><span><i class="c"></i>${L('treffer, critical')}</span><span><i class="w"></i>${L('treffer, warning')}</span><span><i class="n"></i>${L('gezien, niets bijzonders')}</span><span><i class="ok"></i>${L('toegestaan (RzSense zelf of een uitzondering)')}</span><span>· <b>48×</b> = ${L('zo vaak samengevoegd')}</span></div>`;
  const empty = !edrFeed.on ? '' : lvMode === 'hits'
    ? `<p class="lv-empty">✔ ${L('Niets verdachts in de laatste 3 dagen.')} ${sum.list.all ? `<button type="button" class="btn small-btn" data-lv-mode="all">${L('Alles tonen ({n})', { n: nf(sum.list.all) })}</button>` : ''}</p>`
    : `<p class="muted">${L('Nog niets gezien. Staat de endpoint-bewaking aan op je servers (Agent Manager), en is de agent 1.63.0 of nieuwer?')}</p>`;
  withMorph(() => { $('#edr-feed').innerHTML = rows.length ? html + (lvMode === 'hits' && sum.list.all > sum.list.hits ? `<p class="muted small lv-more">${L('Verder niets verdachts. Kies <b>Alles</b> om alle {n} gebeurtenissen te zien.', { n: nf(sum.list.all) })}</p>` : '') + legend : empty; });
}
function initLive() {
  const pane = $('.ids-pane[data-pane="live"]');
  if (!pane || pane.dataset.lvInit) return;
  pane.dataset.lvInit = '1';
  pane.addEventListener('click', async (e) => {
    const m = e.target.closest('[data-lv-mode]');
    if (m) { lvMode = m.dataset.lvMode; uiSet('edr_mode', 'rzmon-edr-mode', lvMode); lvOpen.clear(); $('#edr-feed').innerHTML = `<p class="muted">${L('Laden…')}</p>`; return loadEdrFeed(); }
    const k = e.target.closest('[data-lv-kind]');
    if (k) { lvKind = lvKind === k.dataset.lvKind ? '' : k.dataset.lvKind; return renderLive(); }
    const inc = e.target.closest('[data-lv-inc]');
    if (inc) return incidentDialog(+inc.dataset.lvInc);
    const kill = e.target.closest('[data-lv-kill]');
    if (kill) {
      if (!confirm(L('Dit proces op de server stoppen? De agent controleert eerst dat het nog hetzelfde proces is.'))) return undefined;
      try { await api('edr_kill', { id: +kill.dataset.lvKill }); toast(L('Wordt gestopt bij het volgende contact met de agent')); kill.disabled = true; } catch (ex) { toast(ex.message, 'err'); }
      return undefined;
    }
    const nor = e.target.closest('[data-lv-normal]');
    if (nor) {
      if (!confirm(L('Afhandelen en dit voortaan niet meer melden op deze server?'))) return undefined;
      try { await api('ids_incident', { id: +nor.dataset.lvNormal, action: 'normal' }); toast(L('Afgehandeld; dit wordt voortaan niet meer gemeld')); nor.disabled = true; } catch (ex) { toast(ex.message, 'err'); }
      return undefined;
    }
    if (e.target.closest('a, button, details, .xp')) return undefined;
    const row = e.target.closest('[data-lv-row]');
    if (row) { const id = +row.dataset.lvRow; if (lvOpen.has(id)) lvOpen.delete(id); else lvOpen.add(id); renderLive(); }
    return undefined;
  });
  pane.addEventListener('keydown', (e) => {
    const row = e.target.closest && e.target.closest('[data-lv-row]');
    if (row && (e.key === 'Enter' || e.key === ' ') && e.target === row) { e.preventDefault(); row.click(); }
  });
}
async function loadEdrRules() {
  try { edrRules = await api('edr_rules'); } catch (ex) { ruleBox().innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderEdrRules();
}
function renderEdrRules() {
  ruleQ().hidden = ruleSrc === 'exc';
  if (ruleSrc === 'exc') return loadExceptions();   // 1.132.0
  if (!edrRules) return;
  const q = (ruleQ().value || '').trim().toLowerCase();
  const list = edrRules.rules.filter((r) => (!ruleSrc || r.src === ruleSrc) && (!q || (r.title + ' ' + r.what + ' ' + r.tags.join(' ') + ' ' + r.id).toLowerCase().includes(q)));
  const act = (a) => a.map((x) => ({ kill: '⛔ ' + L('stopt het proces'), block: '🚫 ' + L('blokkeert het IP'), isolate: '🧱 ' + L('isoleert de server') }[x] || x)).join(' · ');
  const on = edrRules.rules.filter((r) => r.on).length;
  withMorph(() => {
    ruleBox().innerHTML = `<p class="small" style="margin-top:0"><b>${L('{on} van {n} regels aan.', { on, n: edrRules.rules.length })}</b>
      ${edrRules.sigma_on ? '' : ` <span class="warn-txt">${L('Sigma-regels staan uit (Instellingen → IDS → Endpoint-bewaking).')}</span>`}</p>${xpHtml(L('Over de regels: van RzSense zelf en van SigmaHQ.'), L('<b>Bronnen:</b> de eigen regels van RzSense (met uitleg) en de Linux-regels van SigmaHQ (licentie DRL 1.1). <b>Uitzetten:</b> een regel uitzetten geldt voor alle servers; <i>Dit is normaal</i> bij een incident geldt voor één server.'))}
      <div class="table-scroll"><table class="vuln-table rule-table"><thead><tr><th></th><th>${L('Regel')}</th><th>${L('Ernst')}</th><th>${L('Doet zelf')}</th><th>MITRE</th><th title="${esc(L('Treffers in de laatste 3 dagen'))}">${L('Treffers')}</th></tr></thead><tbody>
      ${list.map((r) => `<tr class="${r.on ? '' : 'muted'}" data-key="${esc(r.id)}"><td><label class="switch small need-admin"><input type="checkbox" data-rule="${esc(r.id)}" ${r.on ? 'checked' : ''}><span></span></label></td>
        <td><b>${esc(r.title)}</b> <span class="badge">${r.src === 'sigma' ? 'Sigma' : 'RzSense'}</span><br><span class="small muted">${esc((r.what || '').slice(0, 260))}${r.src === 'sigma' ? ` · ${esc(r.author || '')} · <a href="${esc(r.url)}" target="_blank" rel="noopener">${L('bron')}</a>` : ''}</span></td>
        <td class="nowrap">${r.sev === 2 ? '🔴 Critical' : '🟠 Warning'}</td><td class="small">${act(r.act) || '<span class="muted">–</span>'}</td>
        <td class="small mono">${r.tags.map((t) => `<a href="https://attack.mitre.org/techniques/${esc(t.replace('.', '/'))}/" target="_blank" rel="noopener">${esc(t)}</a>`).join(' ')}</td><td>${r.hits || ''}</td></tr>`).join('')}
      </tbody></table></div>`;
  });
}

// ---------------------------------------------------------------- IDS: 🛡️ Uitzonderingen (1.132.0)
// Wat in een bepaalde situatie normaal is: geen melding, niet ingrijpen, niet meetellen voor afwijkingen; wel een groene
// regel in Live. Sets met één knop, voorstellen uit je eigen meldingen, eigen uitzonderingen, exporteren en importeren.
let excData = null, excProps = null;
const EXC_KEYS = {
  program: () => L('Programma'), parent: () => L('Ouderproces'), user: () => L('Gebruiker'), cmd: () => L('Opdracht bevat'), path: () => L('Pad'),
  ip: () => L('IP-adres of netwerk'), port: () => L('Poort'), container: () => L('Container'), text: () => L('Tekst bevat'),
};
const EXC_GROUPS = { server: () => L('Servers'), network: () => L('Netwerk'), storage: () => L('Opslag'), power: () => L('Stroom'), camera: () => L("Camera's"), smarthome: () => L('Smart home'), other: () => L('Overig') };
const excCond = (c) => Object.entries(c || {}).map(([k, v]) => `<span class="exc-c"><span class="muted">${esc((EXC_KEYS[k] || (() => k))())}</span> ${(v || []).map((x) => `<code>${esc(x)}</code>`).join(' ')}</span>`).join(' ');
const excRule = (r, target) => (!r ? (target === 'ids' ? L('alle IDS-meldingen') : L('alle regels')) : esc(((excData?.rules || []).find((x) => x.id === r) || {}).title || r));
const excScope = (x) => (x.scope === 'host' ? esc(x.host_name || ('#' + x.scope_val)) : x.scope === 'group' ? esc((EXC_GROUPS[x.scope_val] || (() => x.scope_val))()) : L('alle servers'));
async function loadExceptions() {
  const box = $('#edr-rules-set');
  if (!excData) box.innerHTML = `<p class="muted">${L('Laden…')}</p>`;
  try { excData = await api('exc_list'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderExceptions();
  try { excProps = (await api('exc_proposals')).proposals; } catch (ex) { excProps = []; }
  renderExceptions();
}
function renderExceptions() {
  if (ruleSrc !== 'exc' || !excData) return;
  const list = excData.list;
  const sets = excData.sets.map((s) => {
    const n = s.items.length, would = s.items.reduce((a, i) => a + i.would, 0);
    return `<div class="exc-set ${s.added ? 'on' : ''}"><div class="exc-set-h"><span class="exc-ic">${esc(s.icon)}</span><b>${esc(s.name)}</b>
        ${s.added ? `<span class="badge good">✔ ${L('toegevoegd')}</span>` : ''}</div>
      <p class="small muted">${esc(s.desc)}</p>
      <ul class="small exc-items">${s.items.map((i) => `<li>${i.added ? '✔' : '·'} ${esc(i.name)}${i.would ? ` <span class="muted">· ${L('zou {n}× raak zijn', { n: i.would.toLocaleString(LOCALE) })}</span>` : ''}</li>`).join('')}</ul>
      <div class="btn-row">${s.items.every((i) => i.added) ? `<button type="button" class="btn small-btn" data-exc-unset="${esc(s.key)}">${L('Weghalen')}</button>`
        : `<button type="button" class="btn small-btn primary" data-exc-set="${esc(s.key)}">＋ ${n === 1 ? L('Set toevoegen (1 regel)') : L('Set toevoegen ({n} regels)', { n })}</button>`}
        ${would ? `<span class="muted small">${L('{n}× in 30 dagen', { n: would.toLocaleString(LOCALE) })}</span>` : ''}</div></div>`;
  }).join('');
  const props = excProps === null ? `<p class="muted small">${L('Voorstellen zoeken…')}</p>` : !excProps.length ? `<p class="muted small">✔ ${L('Geen voorstellen: niets van de laatste 30 dagen kwam vaak terug, of het is al uitgezonderd.')}</p>`
    : `<div class="table-scroll"><table class="vuln-table small exc-props"><thead><tr><th><input type="checkbox" id="exc-all" aria-label="${esc(L('Alles'))}"></th><th>${L('Wat')}</th><th>${L('Geldt voor')}</th><th>${L('Keer')}</th><th>${L('Laatst')}</th></tr></thead><tbody>
      ${excProps.map((p) => `<tr><td><input type="checkbox" data-exc-prop="${esc(p.id)}" ${p.checked ? 'checked' : ''}></td><td><b>${esc(p.title)}</b><br>${excCond(p.cond)}${p.rule ? ` <span class="muted">· ${excRule(p.rule, p.target)}</span>` : ''}</td>
        <td>${p.scope === 'host' ? esc(p.host_names[0]) : L('alle servers ({n})', { n: p.host_names.length })}</td><td class="nowrap"><b>${(+p.hits).toLocaleString(LOCALE)}×</b></td><td class="nowrap muted">${esc(ago(p.last))}</td></tr>`).join('')}
      </tbody></table></div>
      <div class="btn-row"><button type="button" class="btn primary" id="exc-add">＋ ${L('Geselecteerde toevoegen')}</button>
        <span class="muted small">${L('Aangevinkt: wat 20× of vaker terugkwam. Wat van RzSense of de agent zelf is, staat hier niet: dat herkent RzSense vanaf 1.132.0 (agent 1.67.0) vanzelf. Wijzigingen aan gevoelige bestanden (wachtwoorden, sudo, SSH-sleutels) worden nooit voorgesteld.')}</span></div>`;
  const rows = list.length ? `<div class="table-scroll"><table class="vuln-table small exc-list"><thead><tr><th></th><th>${L('Uitzondering')}</th><th>${L('Geldt voor')}</th><th>${L('Voorwaarden')}</th><th>${L('Raak')}</th><th></th></tr></thead><tbody>
      ${list.map((x) => `<tr class="${+x.enabled && !x.expired ? '' : 'muted'}"><td><label class="switch small"><input type="checkbox" data-exc-on="${x.id}" ${+x.enabled ? 'checked' : ''}><span></span></label></td>
        <td><b>${esc(x.name)}</b>${x.set_key && x.set_key !== 'found' ? ` <span class="badge">${esc(((excData.sets.find((s) => s.key === x.set_key)) || {}).name || x.set_key)}</span>` : x.set_key === 'found' ? ` <span class="badge">${L('voorstel')}</span>` : ''}
          <br><span class="muted">${x.target === 'ids' ? 'IDS' : 'EDR'} · ${excRule(x.rule, x.target)}${x.until ? ' · ' + (x.expired ? `<span class="warn-txt">${L('verlopen')}</span>` : L('tot {t}', { t: esc(fmtDateTime(x.until)) })) : ''}${x.reason ? ' · ' + esc(x.reason) : ''}</span></td>
        <td class="nowrap">${excScope(x)}</td><td>${excCond(x.cond)}</td>
        <td class="nowrap">${+x.hits ? `<b>${(+x.hits).toLocaleString(LOCALE)}×</b><br><span class="muted">${esc(ago(x.last_hit))}</span>` : '<span class="muted">–</span>'}</td>
        <td class="nowrap"><button type="button" class="btn small-btn" data-exc-edit="${x.id}" title="${esc(L('Bewerken'))}">✎</button> <button type="button" class="btn small-btn" data-exc-del="${x.id}" title="${esc(L('Weghalen'))}">✕</button></td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted small">${L('Nog geen uitzonderingen.')}</p>`;
  withMorph(() => {
    $('#edr-rules-set').innerHTML = `${xpHtml(L('Wat hier staat is normaal: geen melding en RzSense grijpt niet in.'), L('<b>Wat het doet:</b> wat op een uitzondering past, geeft geen melding, RzSense grijpt niet in en het telt niet mee voor afwijkingen. In <i>Live</i> zie je het wel als groene regel <i>✔ toegestaan</i>. <b>Nooit uit te zonderen:</b> malware (YARA), programma\'s in /dev/shm, lokaas, bekende kwade adressen en ketens.'))}
      <div class="btn-row"><button type="button" class="btn primary" id="exc-new">＋ ${L('Uitzondering')}</button>
        <button type="button" class="btn" id="exc-export">⇩ ${L('Exporteren')}</button>
        <label class="btn" for="exc-import">⇧ ${L('Importeren')}</label><input type="file" id="exc-import" accept="application/json,.json" hidden></div>
      <h3 class="set-sub">${L('Sets')} <span class="muted small">${L('(een hele groep met één knop; daarna per regel aan of uit)')}</span></h3>
      <div class="exc-sets">${sets}</div>
      <h3 class="set-sub">${L('Voorstellen uit je meldingen')} <span class="muted small">${L('(laatste 30 dagen)')}</span></h3>${props}
      <h3 class="set-sub">${L('Jouw uitzonderingen')} <span class="muted small">${list.length}</span></h3>${rows}`;
  });
}
/** Bewerkvenster: naam, IDS of EDR, regel, voor wie, voorwaarden (één per regel), hoe lang en waarom */
function excDialog(x = null) {
  const d = excData;
  const c = x ? x.cond : {};
  const ruleOpts = (t) => `<option value="">${t === 'ids' ? L('alle IDS-meldingen') : L('alle regels')}</option>` + d.rules.filter((r) => r.target === t).map((r) => `<option value="${esc(r.id)}" ${x && x.rule === r.id ? 'selected' : ''}>${esc(r.title)}</option>`).join('');
  modal({
    title: x ? L('Uitzondering bewerken') : L('Nieuwe uitzondering'), wide: true,
    body: `<div class="row2"><label>${L('Naam')}<input name="name" required maxlength="100" value="${esc(x?.name || '')}" placeholder="${esc(L('bv. Healthchecks op RL02'))}"></label>
        <label>${L('Soort')}<select name="target" id="exc-target"><option value="edr" ${x?.target !== 'ids' ? 'selected' : ''}>${L('Endpoint-bewaking (processen, bestanden, netwerk)')}</option><option value="ids" ${x?.target === 'ids' ? 'selected' : ''}>${L('IDS (logins, uitgaand verkeer, webshell, bestanden)')}</option></select></label></div>
      <div class="row2"><label>${L('Regel')}<select name="rule" id="exc-rule">${ruleOpts(x?.target || 'edr')}</select></label>
        <label>${L('Geldt voor')}<select name="scopex"><option value="all">${L('alle servers')}</option>
          <optgroup label="${esc(L('Groep'))}">${d.groups.map((g) => `<option value="group:${g}" ${x?.scope === 'group' && x.scope_val === g ? 'selected' : ''}>${esc((EXC_GROUPS[g] || (() => g))())}</option>`).join('')}</optgroup>
          <optgroup label="${esc(L('Server'))}">${d.hosts.map((h) => `<option value="host:${h.id}" ${x?.scope === 'host' && +x.scope_val === +h.id ? 'selected' : ''}>${esc(h.name)}</option>`).join('')}</optgroup></select></label></div>
      ${xpHtml(L('Alles wat je invult moet kloppen; jokers mogen.'), L('<b>Voorwaarden:</b> alles wat je invult moet kloppen; per veld is één van de regels genoeg. <b>Jokers:</b> <code>*</code> (binnen een map) en <code>**</code> (ook dieper), bijvoorbeeld <code>/var/tmp/dracut.*/**</code>.'))}
      <div class="exc-grid">${Object.keys(EXC_KEYS).map((k) => `<label>${esc(EXC_KEYS[k]())}<textarea name="c_${k}" rows="2" spellcheck="false" class="mono">${esc((c[k] || []).join('\n'))}</textarea></label>`).join('')}</div>
      <div class="row2"><label>${L('Geldig')}<select name="days"><option value="0">${x?.until ? L('zoals nu (tot {t})', { t: fmtDateTime(x.until) }) : L('altijd')}</option><option value="1">${L('1 dag')}</option><option value="7">${L('7 dagen')}</option><option value="30">${L('30 dagen')}</option>${x?.until ? `<option value="-1">${L('altijd')}</option>` : ''}</select></label>
        <label>${L('Reden')}<input name="reason" maxlength="200" value="${esc(x?.reason || '')}" placeholder="${esc(L('bv. elke 6 uur een nieuw VPN-adres'))}"></label></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (f) => {
      const cond = Object.fromEntries(Object.keys(EXC_KEYS).map((k) => [k, (f['c_' + k] || '').split('\n').map((v) => v.trim()).filter(Boolean)]));
      const [scope, sv] = f.scopex === 'all' ? ['all', null] : f.scopex.split(':');
      const pw = await askConfirm({ title: L('Uitzondering opslaan?'), text: L('Wat erop past, wordt niet meer gemeld.'), word: 'UITZONDEREN' });
      if (pw === null) return 'keep';
      const body = { id: x?.id || 0, name: f.name, target: f.target, rule: f.rule, scope, scope_val: sv, cond, reason: f.reason, confirm: pw };
      if (+f.days > 0) body.days = +f.days; else if (+f.days === 0 && x?.until) body.until = x.until;
      await api('exc_save', body);
      toast(L('Opgeslagen'));
      loadExceptions();
      return undefined;
    },
  });
  $('#exc-target').onchange = (e) => { $('#exc-rule').innerHTML = ruleOpts(e.target.value); };
}
function initExceptions() {
  const box = $('#edr-rules-set');   // 1.162.0: uitzonderingen alleen in de Instellingen
  if (!box) return;
  box.addEventListener('click', async (e) => {
    if (ruleSrc !== 'exc') return;
    const t = e.target;
    const confirmPw = (title, text) => askConfirm({ title, text, word: 'UITZONDEREN' });
    try {
      if (t.closest('#exc-new')) return excDialog();
      if (t.closest('[data-exc-edit]')) return excDialog(excData.list.find((x) => +x.id === +t.closest('[data-exc-edit]').dataset.excEdit));
      if (t.closest('#exc-export')) {
        const data = await api('exc_export');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
        a.download = 'rzmon-uitzonderingen.json';
        a.click();
        return undefined;
      }
      if (t.closest('#exc-all')) { $$('[data-exc-prop]', box).forEach((c) => { c.checked = t.checked; }); return undefined; }
      const setB = t.closest('[data-exc-set]');
      if (setB) {
        const s = excData.sets.find((x) => x.key === setB.dataset.excSet);
        const pw = await confirmPw(L('Set "{name}" toevoegen?', { name: s.name }), L('{n} uitzonderingen in één keer; daarna kun je ze per regel uitzetten.', { n: s.items.length }));
        if (pw === null) return undefined;
        const r = await api('exc_set', { key: s.key, confirm: pw });
        toast(L('{n} uitzondering(en) toegevoegd', { n: r.added }));
        return loadExceptions();
      }
      const unset = t.closest('[data-exc-unset]');
      if (unset) {
        if (!confirm(L('De hele set weghalen? Wat erop paste, wordt dan weer gemeld.'))) return undefined;
        await api('exc_delete', { set: unset.dataset.excUnset });
        return loadExceptions();
      }
      if (t.closest('#exc-add')) {
        const ids = $$('[data-exc-prop]:checked', box).map((c) => c.dataset.excProp);
        if (!ids.length) return toast(L('Niets geselecteerd'), 'err');
        const pw = await confirmPw(L('{n} uitzondering(en) toevoegen?', { n: ids.length }), L('Wat erop past, wordt niet meer gemeld.'));
        if (pw === null) return undefined;
        const r = await api('exc_add', { ids, confirm: pw });
        toast(L('{n} uitzondering(en) toegevoegd', { n: r.added }));
        return loadExceptions();
      }
      const del = t.closest('[data-exc-del]');
      if (del) {
        if (!confirm(L('Deze uitzondering weghalen? Wat erop paste, wordt dan weer gemeld.'))) return undefined;
        await api('exc_delete', { id: +del.dataset.excDel });
        return loadExceptions();
      }
    } catch (ex) { toast(ex.message, 'err'); }
    return undefined;
  });
  box.addEventListener('change', async (e) => {
    if (ruleSrc !== 'exc') return;
    const on = e.target.closest('[data-exc-on]');
    if (on) {
      try {
        let pw = '';
        if (on.checked) {
          pw = await askConfirm({ title: L('Uitzondering aanzetten?'), text: L('Wat erop past, wordt niet meer gemeld.'), word: 'UITZONDEREN' });
          if (pw === null) { on.checked = false; return; }
        }
        await api('exc_toggle', { id: +on.dataset.excOn, on: on.checked, confirm: pw });
        loadExceptions();
      } catch (ex) { toast(ex.message, 'err'); on.checked = !on.checked; }
      return;
    }
    if (e.target.id === 'exc-import' && e.target.files[0]) {
      const file = e.target.files[0];
      e.target.value = '';
      try {
        const data = JSON.parse(await file.text());
        const n = (data.exceptions || []).length;
        const pw = await askConfirm({ title: L('{n} uitzondering(en) importeren?', { n }), text: L('Uit {f}. Servers worden op naam gezocht; wat hier niet bestaat, wordt overgeslagen.', { f: esc(file.name) }), word: 'UITZONDEREN' });
        if (pw === null) return;
        const r = await api('exc_import', { data, confirm: pw });
        toast(L('{n} geïmporteerd', { n: r.added }) + (r.skipped.length ? ' · ' + L('{n} overgeslagen: {why}', { n: r.skipped.length, why: r.skipped.slice(0, 3).join('; ') }) : ''), r.skipped.length ? 'err' : undefined);
        loadExceptions();
      } catch (ex) { toast(ex instanceof SyntaxError ? L('Geen geldig JSON-bestand') : ex.message, 'err'); }
    }
  });
}

// ---------------------------------------------------------------- IDS: 🧬 Afwijkingen, ketens en 🧭 MITRE (1.111.0)
let anomData = null, anomDays = 7, mitreData = null, mitreDays = 30;
const TACTIC_NL = {
  reconnaissance: () => L('Verkennen'), 'resource-development': () => L('Voorbereiden'), 'initial-access': () => L('Binnenkomen'), execution: () => L('Uitvoeren'),
  persistence: () => L('Blijven'), 'privilege-escalation': () => L('Rechten verhogen'), stealth: () => L('Verbergen'), 'defense-impairment': () => L('Beveiliging uitschakelen'),
  'credential-access': () => L('Wachtwoorden stelen'), discovery: () => L('Rondkijken'), 'lateral-movement': () => L('Verder het netwerk in'), collection: () => L('Verzamelen'),
  'command-and-control': () => L('Op afstand besturen'), exfiltration: () => L('Gegevens weghalen'), impact: () => L('Schade'),
};
const tacticName = (s) => (TACTIC_NL[s] || (() => s))();
const techLink = (t) => `<a class="chip" href="https://attack.mitre.org/techniques/${esc(t.replace('.', '/'))}/" target="_blank" rel="noopener">${esc(t)}</a>`;
const scoreTag = (n) => `<span class="score-tag ${n >= 60 ? 'crit' : n >= 40 ? 'warn' : ''}" title="${esc(L('Score: hoe ongewoon (0–100)'))}">${n}</span>`;
function hostOptions(sel) {
  if (sel.options.length < 2) sel.innerHTML += (state.hosts || []).filter((h) => h.mode === 'agent').map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
}
/** Stappen van een keten als tijdlijn (op de pagina en in het incidentvenster) */
function chainSteps(steps, attr = 'data-inc') {
  return `<ol class="chain-steps">${steps.map((s) => `<li class="${s.score >= 60 ? 'crit' : s.score >= 40 ? 'warn' : ''}">
    <span class="cs-t">${esc(fmtDateTime(s.at))}</span>
    <span class="cs-tac">${(s.tactics || []).slice(0, 2).map((t) => `<span class="chip tac">${esc(tacticName(t))}</span>`).join('')}</span>
    <span class="cs-l">${s.ref ? `<button type="button" class="linklike" ${attr}="${s.ref}">${esc(s.label)}</button>` : esc(s.label)}
      <span class="muted small">${s.src === 'anom' ? L('afwijking') : s.src === 'rule' ? L('regel') : 'IDS'}${(s.techs || []).length ? ' · ' + s.techs.slice(0, 3).map(esc).join(' ') : ''}</span></span></li>`).join('')}</ol>`;
}
async function loadAnom() {
  const host = $('#anom-host').value;
  try { anomData = await api('ids_anom', null, { days: anomDays, ...(host ? { host } : {}) }); } catch (ex) { $('#anom-learn').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  hostOptions($('#anom-host'));
  const d = anomData;
  $('#anom-info').textContent = !d.on ? L('Endpoint-bewaking staat uit (Instellingen → IDS → Endpoint-bewaking)') : !d.anom ? L('Afwijkingen staan uit (Instellingen → IDS → Endpoint-bewaking)') : '';
  withMorph(() => {
    $('#anom-learn').innerHTML = `<h3 class="set-sub" style="margin-top:0">🎓 ${L('Wat is normaal? (leren per server)')}</h3>
      ${xpHtml(L('Elke server leert eerst {d} dagen wat normaal is.'), L('Elke server leert {d} dagen wat normaal is: welke programma\'s, gebruikers, verbindingen en poorten. Daarna meldt RzSense wat die server nog nooit deed. <b>Na een grote wijziging</b> (nieuwe software) kun je hem opnieuw laten leren.', { d: d.learn_days }))}
      ${d.hosts.length ? `<div class="table-scroll"><table class="vuln-table learn-table"><thead><tr><th>Server</th><th>${L('Leren')}</th><th>${L('Geleerd')}</th><th>${L('Afwijkingen (7 dagen)')}</th><th class="need-admin"></th></tr></thead><tbody>
      ${d.hosts.map((h) => `<tr data-key="lh${h.id}"><td><b>${esc(h.name)}</b>${h.on ? '' : ` <span class="muted small">(${L('uit')})</span>`}</td>
        <td>${h.learning ? `<span class="cl-meter"><span class="bar"><i style="width:${h.pct}%"></i></span> <span class="small">${L('dag {n} van {d}', { n: Math.max(1, h.day), d: h.days })}</span></span>`
          : `✔ <span class="small">${L('klaar, meldt sinds {t}', { t: esc(fmtDateTime(h.until)) })}</span>`}</td>
        <td class="small">${L('{n} kenmerken', { n: h.learned })}</td><td>${h.anoms || '<span class="muted">0</span>'}</td>
        <td class="need-admin nowrap">${h.learning ? `<button type="button" class="btn small-btn" data-learn="finish" data-h="${h.id}">${L('Leren afronden')}</button>` : ''}
          <button type="button" class="btn small-btn" data-learn="restart" data-h="${h.id}">↺ ${L('Opnieuw leren')}</button></td></tr>`).join('')}</tbody></table></div>`
        : `<p class="muted">${L('Nog geen servers met endpoint-bewaking (agent 1.63.0 of nieuwer, en aan in de Agent Manager).')}</p>`}`;
    $('#anom-chains').innerHTML = `<h3 class="set-sub" style="margin-top:0">🔗 ${L('Ketens')} <span class="muted small">${L('(laatste 30 dagen)')}</span></h3>
      ${!d.chain ? `<p class="warn-txt small">${L('Ketens staan uit (Instellingen → IDS → Endpoint-bewaking).')}</p>` : ''}
      ${d.chains.length ? d.chains.map((c) => `<div class="chain-card s${c.severity}" data-key="ch${c.id}">
        <div class="chain-head">${c.severity === 2 ? '🔴' : '🟠'} <button type="button" class="linklike" data-inc="${c.id}"><b>${esc(c.title)}</b></button>
          <span class="muted small">· ${esc(c.host)} · ${esc(fmtDateTime(c.created_at))}${c.hits > 1 ? ' · ' + L('{n}× gegroeid', { n: c.hits }) : ''} · ${c.status === 'open' ? `<b class="warn-txt">${L('open')}</b>` : '✔'}</span></div>
        ${chainSteps(c.steps)}${c.done.length ? `<p class="small">⛔ ${esc(c.done.join(', '))}</p>` : ''}</div>`).join('')
        : `<p class="muted">${L('Geen ketens 👌')}</p>`}
      <details class="small"><summary>${L('Welke ketens herkent RzSense?')}</summary><ul>${Object.values(d.scenarios).map((s) => `<li><b>${esc(s.title)}</b>: ${esc(s.what)}</li>`).join('')}</ul></details>`;
    $('#anom-list').innerHTML = `<h3 class="set-sub" style="margin-top:0">🧬 ${L('Afwijkingen')} <span class="muted small">${L('(vanaf score {n} ook een incident)', { n: d.min })}</span></h3>
      ${d.anomalies.length ? `<div class="edr-list">${d.anomalies.map((a) => `<div class="edr-row" data-key="a${a.id}">
        <span class="m-t" title="${esc(a.at)}">${esc(a.at.slice(5, 16).replace(' ', ' · '))}</span>${scoreTag(a.score)}
        <span class="m-sv">${esc(a.host)}</span>
        <span class="edr-sum">${a.ref ? `<button type="button" class="linklike" data-inc="${a.ref}">${esc(a.label)}</button>` : esc(a.label)}
          ${a.tactics.slice(0, 2).map((t) => `<span class="chip tac">${esc(tacticName(t))}</span>`).join('')}</span></div>`).join('')}</div>`
        : `<p class="muted">${d.hosts.some((h) => !h.learning) ? L('Geen afwijkingen 👌') : L('Nog niets: de servers zijn nog aan het leren.')}</p>`}`;
  });
}
async function loadMitre() {
  const host = $('#mitre-host').value;
  try { mitreData = await api('ids_mitre', null, { days: mitreDays, ...(host ? { host } : {}) }); } catch (ex) { $('#mitre-matrix').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  hostOptions($('#mitre-host'));
  renderMitre();
}
function renderMitre() {
  const d = mitreData;
  if (!d) return;
  const cover = $('#mitre-cover').checked;
  const total = d.tactics.reduce((s, t) => s + t.n, 0);
  withMorph(() => {
    $('#mitre-matrix').innerHTML = `${xpHtml(L('Alles wat RzSense de laatste {d} dagen zag, per stap van een aanval.', { d: d.days }) + (total ? '' : ' <b>' + L('Niets gezien 👌') + '</b>'), L('Alle treffers van de laatste {d} dagen (regels, afwijkingen en IDS-meldingen), gerangschikt naar de stappen van een aanval volgens <b>MITRE ATT&CK</b>, een bekende indeling van aanvalstechnieken. Klik op een techniek voor de meldingen.', { d: d.days }))}
      <div class="mitre-scroll"><div class="mitre-grid">${d.tactics.map((t) => {
        const techs = t.techs.filter((x) => x.n > 0 || (cover && x.nrules > 0));
        return `<div class="mt-col ${t.n ? 'hit' : ''}" data-key="${esc(t.slug)}"><div class="mt-head" title="${esc(t.name + ' (' + t.id + ')')}"><b>${esc(t.nl)}</b><span class="muted small">${esc(t.name)}</span>${t.n ? `<span class="mt-n">${t.n}</span>` : ''}</div>
          ${techs.map((x) => `<button type="button" class="mt-cell ${x.n ? (x.score >= 60 ? 'crit' : 'warn') : 'cov'}" data-mt="${esc(x.id)}" data-ta="${esc(t.slug)}"
            title="${esc((x.id ? x.id + ' · ' : '') + x.name + (x.nrules ? '\n' + L('{n} regel(s) kunnen dit zien', { n: x.nrules }) + ': ' + x.rules.join(', ') : ''))}">
            <span class="mono small">${esc(x.id || '–')}</span> ${esc(x.name)}${x.n ? ` <b class="mt-n">${x.n}</b>` : ` <span class="muted small">🛡 ${x.nrules}</span>`}</button>`).join('') || `<span class="muted small">–</span>`}</div>`;
      }).join('')}</div></div>
      <p class="muted small">${esc(d.source)}. ${esc(d.license)}</p>`;
  });
}
async function mitreSignals(tech, tactic) {
  const host = $('#mitre-host').value;
  let d;
  try { d = await api('ids_mitre', null, { days: mitreDays, tech, tactic, ...(host ? { host } : {}) }); } catch (ex) { return toast(ex.message, 'err'); }
  const info = mitreData.tactics.find((t) => t.slug === tactic)?.techs.find((x) => x.id === tech) || {};
  modal({
    title: `🧭 ${tech || L('overig')} ${info.name || ''}`,
    wide: true,
    body: `<p class="small" style="margin-top:0">${L('Stap: {t}', { t: esc(tacticName(tactic)) })}${tech ? ` · <a href="https://attack.mitre.org/techniques/${esc(tech.replace('.', '/'))}/" target="_blank" rel="noopener">${L('uitleg bij MITRE')}</a>` : ''}</p>
      ${d.signals.length ? `<div class="edr-list">${d.signals.map((s) => `<div class="edr-row"><span class="m-t">${esc(s.at.slice(5, 16).replace(' ', ' · '))}</span>${scoreTag(s.score)}<span class="m-sv">${esc(s.host)}</span>
        <span class="edr-sum">${s.ref ? `<button type="button" class="linklike" data-inc-step="${s.ref}">${esc(s.label)}</button>` : esc(s.label)} <span class="muted small">${s.src === 'anom' ? L('afwijking') : s.src === 'rule' ? L('regel') : 'IDS'}</span></span></div>`).join('')}</div>`
        : `<p class="muted">${L('Geen meldingen in deze periode. Deze regels kunnen het zien:')}</p><ul class="small">${(info.rules || []).map((r) => `<li>${esc(r)}</li>`).join('')}</ul>`}`,
  });
  $$('#modal [data-inc-step]').forEach((b) => { b.onclick = () => incidentDialog(+b.dataset.incStep); });
}

// ---------------------------------------------------------------- IDS: 🛡️ Bescherming en Waarom/Trigger/Actie (1.115.0)
let protData = null;
/** Labels met wat RzSense deed, achter de titel in de lijst (alleen echte acties, geen "gemeld") */
function actChips(p) {
  if (!p || !p.acts) return '';
  const short = { block: L('geblokkeerd'), kill: L('gestopt'), isolate: L('geïsoleerd'), close: L('afgehandeld'), learn: L('niet ingegrepen'), escalate: 'critical' };
  return p.acts.filter((a) => short[a[2]]).map(([icon, text, t, ok]) => ` <span class="act-chip ${ok === true ? 'ok' : ok === false ? 'bad' : ''} t-${t}" title="${esc(text)}">${icon} ${esc(short[t])}</span>`).join('');
}
/** Waarom / Trigger / Actie in het incidentvenster */
function explainHtml(x) {
  return `<table class="kv-table small explain"><tbody>
    ${x.why ? `<tr><th>${L('Waarom')}</th><td>${esc(x.why)}${x.card ? ` <a href="#ids/prot" class="small" data-goto="ids/prot" data-prot-card="${esc(x.card.id)}">🛡️ ${esc(x.card.title)}</a>` : ''}</td></tr>` : ''}
    ${x.trigger ? `<tr><th>${L('Trigger')}</th><td>${esc(x.trigger)}</td></tr>` : ''}
    <tr><th>${L('Actie')}</th><td>${x.actions.map((a) => `<div class="act-line ${a.ok === true ? 'ok' : a.ok === false ? 'bad' : ''}">${a.icon} ${esc(a.text)}${a.ok === true ? ' ✔' : a.ok === false ? ' ✘' : ''}</div>`).join('')}</td></tr>
  </tbody></table>`;
}
async function loadProt() {
  try { protData = await api('ids_protect'); } catch (ex) { $('#prot-cards').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderProt();
}
function renderProt() {
  const d = protData;
  if (!d) return;
  const block = d.mode === 'block';
  $('#it-n-prot').textContent = d.attention || '';
  withMorph(() => {
    $('#prot-mode').innerHTML = !d.enabled ? `<div class="prot-mode off">⚠️ ${L('Het IDS staat uit: er wordt niets bewaakt.')} <a href="#settings/ids" data-goto="settings/ids">${L('Aanzetten')}</a></div>`
      : `<div class="prot-mode ${block ? 'block' : 'learn'}">${block ? '⛔ ' + L('Modus Blokkeren: RzSense voert de acties hieronder zelf uit.') : '🎓 ' + L('Modus Leren: alles wordt alleen gemeld, RzSense grijpt nergens in.')}
        <a href="#settings/ids" data-goto="settings/ids" class="need-admin">${block ? L('Naar Leren') : L('Overschakelen naar Blokkeren')}</a>
        <br><span class="small muted">${L('{on} van {n} onderdelen aan', { on: d.on, n: d.total })}${d.attention ? ` · <span class="warn-txt">${L('{n} aandachtspunt(en)', { n: d.attention })}</span>` : ''}</span></div>`;
    $('#prot-cards').innerHTML = Object.entries(d.groups).map(([g, [icon, name]]) => `<h3 class="set-sub">${icon} ${esc(name)}</h3><div class="prot-grid">
      ${d.cards.filter((c) => c.g === g).map((c) => `<div class="prot-card ${c.toggle && !c.toggle.on ? 'off' : ''}" data-key="${esc(c.id)}" id="prot-${esc(c.id)}">
        <div class="prot-head"><b>${esc(c.title)}</b>${c.toggle ? `<span class="prot-st ${c.toggle.on ? 'on' : 'off'}">${c.toggle.on ? L('aan') : L('uit')}</span>` : ''}${c.toggle || PROT_CFG[c.id] ? `<button type="button" class="btn small-btn need-admin prot-set" data-prot-set="${esc(c.id)}" title="${esc(L('Instellen in de beheerportal'))}">⚙️ ${L('Instellen')}</button>` : ''}</div>
        <p class="small muted">${esc(c.what)}</p>
        <div class="prot-acts">${c.sev === 2 ? '<span class="sev-chip s2">🔴 critical</span>' : c.sev === 1 ? '<span class="sev-chip s1">🟠 warning</span>' : ''} →
          ${c.acts.map(([icon, text, a]) => `<span class="act-chip t-${a} ${!block && ['block', 'kill', 'isolate', 'preblock'].includes(a) ? 'learn' : ''}" title="${esc(!block && ['block', 'kill', 'isolate', 'preblock'].includes(a) ? L('in de modus Leren alleen melden') : text)}">${icon} ${esc(text)}</span>`).join(' ')}
          ${c.toggle ? `<span class="small muted">(${esc(c.toggle.label)}: ${c.toggle.on ? L('aan') : L('uit')})</span>` : ''}</div>
        ${c.why ? `<p class="small">${esc(c.why)}</p>` : ''}
        <p class="small">${c.stat ? esc(c.stat) : c.n ? `${L('7 dagen: {n}×', { n: c.n })} · ${L('laatst {t}', { t: esc(fmtDateTime(c.last.at)) })} · <button type="button" class="linklike" data-prot-show="${esc(c.id)}">${L('Toon incidenten')}</button>`
          : `<span class="muted">${L('7 dagen: niets')}</span>`}</p>
        ${c.attention ? `<p class="small warn-txt">⚠️ ${esc(c.attention)}</p>` : ''}</div>`).join('')}</div>`).join('');
  });
}

// ---------------------------------------------------------------- IDS: bestanden bewaken
const FIM_SEV = [[1, '🟠 warning'], [2, '🔴 critical']];
/** Voorstel per gangbare map (die de agent op de server heeft gezien): pad en ernst */
function fimSuggest(dir) {
  if (/^\/(var|srv)\/(www|http)$/.test(dir)) return { p: `${dir}/**/*.php`, s: 2, why: L('nieuwe of gewijzigde PHP-bestanden (webshells)') };
  if (/\/s?bin$/.test(dir)) return { p: dir, s: 2, why: L('eigen programma\'s en scripts') };
  return { p: dir, s: 1, why: dir === '/opt' ? L('eigen software') : L('configuratie') };
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
        <input class="mono" data-f="p" value="${esc(r.p)}" placeholder="/usr/local/bin" maxlength="300" aria-label="${L('Pad')}">
        <select data-f="s" aria-label="${L('Ernst')}">${FIM_SEV.map(([v, l]) => `<option value="${v}" ${r.s === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <button type="button" class="icon-btn" data-fim="del" title="${L('Weghalen')}">✕</button></div>`).join('')
      || '<p class="muted small fim-empty">' + L('Nog geen paden.') + '</p>'}</div>
      <div class="fim-tools"><button type="button" class="btn small-btn" data-fim="add" ${rows.length >= 20 ? 'disabled' : ''}>＋ ${L('Pad')}</button>
        <button type="button" class="btn small-btn" data-fim="suggest">＋ ${L('Aanbevolen')}</button></div>`;
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
      if (!sugg.length) return toast(ids.enabled ? L('Nog geen voorstellen: de agents (1.40.0+) melden binnen een paar minuten welke mappen er zijn') : L('Zet eerst het IDS aan: dan melden de agents welke mappen er zijn'), 'err');
      // Eigen venster bovenop het formulier (het formulier zelf blijft open)
      const dlg = document.createElement('dialog');
      dlg.className = 'fim-dlg';
      dlg.innerHTML = `<form method="dialog" class="modal-inner"><header><h3>＋ ${L('Aanbevolen paden')}</h3></header>
        <div class="fim-dlg-body"><p class="muted small" style="margin-top:0">${hostId ? L('Mappen die de agent op deze server gevonden heeft. Vink aan wat je wilt bewaken.') : L('Mappen die de agents gevonden hebben. Vink aan wat je wilt bewaken.')}</p>
        ${sugg.map((x, i) => `<label class="switch small fim-sug"><input type="checkbox" name="s${i}" ${have.has(x.p) ? 'disabled' : 'checked'}><span></span>
          <span class="fim-sug-t"><code>${esc(x.p)}</code> ${x.s === 2 ? '🔴' : '🟠'}<br><span class="muted small">${esc(x.why)}${hostId ? '' : ` · ${esc(x.hosts.join(', '))}`}${have.has(x.p) ? ' · ' + L('staat er al') : ''}</span></span></label>`).join('')}</div>
        <footer><button class="btn" value="cancel">${L('Annuleren')}</button><button class="btn primary" value="ok">${L('Toevoegen')}</button></footer></form>`;
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

/** Pad controleren zoals RzSense dat doet (Ids::cleanFim), zodat je het meteen ziet */
function fimCheck(input) {
  const p = input.value.trim();
  input.setCustomValidity(!p || (p.startsWith('/') && p.replace(/\/+$/, '') !== '' && !/^\/(proc|sys|dev|run)(\/|$)/.test(p)) ? ''
    : L('Gebruik een volledig pad (begint met /), niet / zelf en niet onder /proc, /sys, /dev of /run'));
  input.title = input.validationMessage;
}

// ---------------------------------------------------------------- IDS → Toegang
const ACC_TYPES = { ssh: 'SSH', console: 'Console', sudo: 'sudo', su: 'su' };
const ACC_COLS = { time: L('Tijd'), type: L('Soort'), server: 'Server', who: L('Wie'), what: L('Wat / vanaf') };
function accDet(r) { try { return JSON.parse(r.detail || '{}') || {}; } catch (e) { return {}; } }
/** Land van een IP (1.82.0): vlag en code, met de naam als tooltip; ☠️ = op een lijst met bekende aanvallers */
function ccTag(cc, bad = null) {
  const flag = cc && /^[A-Z]{2}$/.test(cc) ? String.fromCodePoint(...[...cc].map((c) => 0x1F1E6 + c.charCodeAt(0) - 65)) : '';
  let name = cc || '';
  try { if (cc) name = new Intl.DisplayNames([LOCALE], { type: 'region' }).of(cc) || cc; } catch (e) { /* oude browser */ }
  return (flag ? ` <span class="cc-tag" title="${esc(name)}">${flag} ${esc(cc)}</span>` : '') + (bad ? ` <span class="cc-tag bad" title="${esc(L('Bekende aanvaller ({list})', { list: bad }))}">☠️</span>` : '');
}
function accWho(r, d) {
  const who = (d.users ? d.users.join(', ') : d.user) || '?';
  return who + (d.as && (r.type === 'su' || r.type === 'sudo') ? ` → ${d.as}` : '');
}

function renderAccess() {
  const d = accData;
  const el = $('#ids-access');
  if (!d) { el.innerHTML = '<p class="muted">' + L('Laden…') + '</p>'; return; }
  // Serverkeuze: alleen servers die in de lijst voorkomen
  const hosts = [...new Map(d.rows.map((r) => [r.host_id, r.host_name])).entries()].sort((a, b) => a[1].localeCompare(b[1], LANG));
  $('#acc-host').innerHTML = '<option value="">' + L('Alle servers') + '</option>' + hosts.map(([id, n]) => `<option value="${id}" ${+accHost === +id ? 'selected' : ''}>${esc(n)}</option>`).join('');
  const fails = d.rows.filter((r) => r.kind === 'authfail').length;
  $('#it-n-acc').textContent = d.rows.length ? d.rows.length : '';
  $('#acc-info').textContent = L('{ok} gelukt · {fail} mislukt · laatste {days} dagen', { ok: d.rows.length - fails, fail: fails, days: d.days }) + (d.truncated ? ' ' + L('(alleen de nieuwste 10.000)') : '');
  const off = d.enabled ? '' : '<p class="warn-txt small">' + L('Toegang bijhouden staat uit: er komt niets nieuws bij. Aanzetten onder <a href="#settings/ids" data-goto="settings/ids">Instellingen → IDS → Algemeen</a>.') + '</p>';
  const q = $('#acc-search').value.trim().toLowerCase();
  // 1.152.0: bij SSH: Alles, Gelukt (sessies met begin, einde en duur) of Mislukt
  const sshBar = $('#acc-ssh');
  if (sshBar) {
    sshBar.hidden = accFilter !== 'ssh';
    $$('[data-acs]', sshBar).forEach((b) => { b.classList.toggle('active', b.dataset.acs === accSsh); b.classList.toggle('on', b.dataset.acs === accSsh); });
  }
  if (accFilter === 'ssh' && accSsh === 'ok') return renderSshSessions(q, off);
  const list = d.rows.filter((r) => (accFilter === 'all' || (accFilter === 'fail' ? r.kind === 'authfail' : r.type === accFilter && (accFilter !== 'ssh' || accSsh === 'all' || (accSsh === 'fail') === (r.kind === 'authfail'))))
    && (!accHost || +r.host_id === +accHost) && (!q || `${r.message} ${r.host_name} ${accWho(r, accDet(r))}`.toLowerCase().includes(q)) && fxPass('acc', r));
  fxRender('acc');
  const narrowed = !!q || !!accHost || fxActive('acc');
  if (!list.length) {
    el.innerHTML = off + `<p class="muted">${d.rows.length ? L('Niets gevonden met dit filter.') : L('Nog geen logins, sudo of su.')}</p>`;
    $('#acc-toggle').hidden = true;
    return;
  }
  const byDay = new Map();
  list.forEach((r) => { const day = r.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(r); });
  const fresh = todayFresh('acc', accDayOpen, d.today, (byDay.get(d.today) || []).length);
  const isOpen = (day) => accDayOpen[day] ?? (day === d.today || narrowed);
  el.innerHTML = off + (!narrowed && accFilter === 'all' && !byDay.has(d.today) ? todayEmpty(d.today, L('nog geen logins, sudo of su')) : '') + [...byDay].map(([day, rows]) => {
    const nf = rows.filter((r) => r.kind === 'authfail').length;
    return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''} ${fresh && day === d.today ? 'data-fresh="1"' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${rows.length - nf ? `<span class="ad-count" title="${L('gelukt')}">🔑 ${rows.length - nf}</span>` : ''}${nf ? `<span class="ad-count" title="${L('mislukt')}">❌ ${nf}</span>` : ''}</span>
        <span class="muted small ad-total">${rows.length === 1 ? L('1 regel') : L('{n} regels', { n: rows.length })}</span></summary>
      <div class="ad-body">${isOpen(day) ? accBody(rows) : ''}</div></details>`;
  }).join('');
  $$('.alert-day', el).forEach((det) => (det.ontoggle = () => {
    accDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = accBody(byDay.get(det.dataset.day));
    accToggleLabel();
  }));
  accToggleLabel();
}

/** 1.152.0: geslaagde SSH-sessies (agent 1.74.0): wanneer, wie, vanaf waar, hoe en hoe lang */
async function renderSshSessions(q, off) {
  const el = $('#ids-access');
  $('#acc-toggle').hidden = true;
  if (!sshSess || Date.now() - sshSess.at > 60000) {
    el.innerHTML = off + `<p class="muted">${L('Laden…')}</p>`;
    try { sshSess = { at: Date.now(), d: await api('ssh_sessions', null, { days: Math.min(30, (accData && accData.days) || 7) }) }; } catch (ex) { el.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
    if (accFilter !== 'ssh' || accSsh !== 'ok') return;
  }
  const list = sshSess.d.sessions.filter((s) => (!accHost || +s.host_id === +accHost) && (!q || `${s.user} ${s.ip} ${s.host} ${s.method || ''}`.toLowerCase().includes(q)));
  const dur = (s) => (s.secs == null ? `<span class="good">● ${L('nog bezig')}</span>` : s.secs < 60 ? L('{n} s', { n: s.secs }) : s.secs < 3600 ? L('{n} min', { n: Math.round(s.secs / 60) })
    : L('{h} u {m} min', { h: Math.floor(s.secs / 3600), m: Math.round((s.secs % 3600) / 60) }));
  $('#acc-info').textContent = L('{n} sessies · laatste {d} dagen', { n: list.length, d: sshSess.d.days });
  el.innerHTML = off + (list.length ? `<div class="card"><div class="table-scroll"><table class="am-table ssh-sess"><thead><tr><th>${L('Begin')}</th><th>${L('Server')}</th><th>${L('Gebruiker')}</th>
      <th>${L('Vanaf')}</th><th>${L('Methode')}</th><th>${L('Duur')}</th><th>${L('Eerst mislukt')}</th></tr></thead><tbody>
    ${list.map((s) => `<tr><td class="mono nowrap">${esc(fmtDateTime(s.at))}</td><td>${esc(s.host)}</td><td><b>${esc(s.user)}</b></td>
      <td class="nowrap"><code>${esc(s.ip)}</code>${s.port ? `<span class="muted small">:${s.port}</span>` : ''} ${s.internal ? `<span class="muted small">🏠 ${L('eigen netwerk')}</span>` : ccTag(s.cc)}</td>
      <td>${esc({ publickey: L('sleutel'), password: L('wachtwoord'), 'keyboard-interactive/pam': L('wachtwoord') }[s.method] || s.method || '–')}</td>
      <td class="nowrap">${dur(s)}${s.closed_at ? `<br><span class="muted small">${L('tot {t}', { t: esc(fmtDateTime(s.closed_at).slice(-5)) })}</span>` : ''}</td>
      <td>${s.fails ? `<span class="${s.fails >= 5 ? 'bad' : 'warn-txt'}">${L('{n}×', { n: s.fails })}</span>` : '–'}</td></tr>`).join('')}</tbody></table></div></div>`
    : `<p class="muted">${sshSess.d.sessions.length ? L('Niets gevonden met dit filter.') : L('Nog geen SSH-sessies. Sessies met begin, einde en duur komen van agent 1.74.0 en nieuwer; eerdere logins staan onder Alles.')}</p>`);
}

function accToggleLabel() {
  const days = $$('#ids-access .alert-day');
  $('#acc-toggle').hidden = days.length < 2;
  $('#acc-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

/** L1nes: de tabel; Blox: compacte rijen zoals bij Meldingen, gelijke regels achter elkaar gebundeld */
function accBody(rows) {
  if (viewPrefs().layout === 'l1nes') return accTable(rows);
  const row = (r, group = null) => {
    const det = accDet(r);
    const fail = r.kind === 'authfail';
    return `<div class="mrow compact">
      <span class="m-t" title="${esc(r.created_at)}">${esc(r.created_at.slice(11, 16))}</span><i class="adot ${fail ? 's1' : 'info'}"></i>
      <span class="m-tt">${fail ? '❌ ' : '🔑 '}${esc(ACC_TYPES[r.type] || r.type)} · ${esc(accWho(r, det))}${fail && +det.n > 1 ? ` <span class="muted small">${+det.n}×</span>` : ''}${group ? ` <button type="button" class="rep" data-bundle-btn>${group}×</button>` : ''}<small>${esc(r.message)}${ccTag(det.cc, det.bad)}</small></span>
      <span class="m-sv">${esc(r.host_name)}</span>
      <span class="m-ch need-admin"><button type="button" class="linkbtn" data-acc="ignore" data-id="${r.id}" title="${L('Deze gebruiker of dit IP-adres voortaan niet meer tonen')}">🙈</button>${det.ip ? ` <button type="button" class="linkbtn" data-acc="block" data-id="${r.id}" title="${L('IP-adres {ip} blokkeren', { ip: esc(det.ip) })}">⛔</button>` : ''}</span></div>`;
  };
  return bundleRuns(rows, (r) => `${r.kind}|${r.type}|${r.host_id}|${accWho(r, accDet(r))}|${r.message}`).map((g) => {
    if (g.items.length === 1) return row(g.items[0]);
    const key = 'a' + g.items[g.items.length - 1].id;
    return `<div class="bundle${idsBundles.has(key) ? ' open' : ''}" data-bundle="${key}">${row(g.items[0], g.items.length)}${g.items.slice(1).map((r) => row(r)).join('')}</div>`;
  }).join('');
}

function accTable(rows) {
  const val = {
    time: (r) => r.created_at, type: (r) => (r.kind === 'authfail' ? '1' : '0') + r.type, server: (r) => r.host_name,
    who: (r) => accWho(r, accDet(r)), what: (r) => r.message,
  }[accSort.key];
  if (val) {
    rows = rows.map((r) => [r, val(r)]).sort((p, q) => accSort.dir * String(p[1]).localeCompare(String(q[1]), LANG, { numeric: true, sensitivity: 'base' })
      || (q[0].created_at > p[0].created_at ? 1 : -1)).map(([r]) => r);
  }
  const th = (k) => `<th class="sortable${accSort.key === k ? ' sorted' : ''}" data-sort="${k}" title="${L('Sorteren op {k}', { k: ACC_COLS[k].toLowerCase() })}">${ACC_COLS[k]}${accSort.key === k ? (accSort.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`;
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
          <button type="button" class="linkbtn" data-acc="ignore" data-id="${r.id}" title="${L('Deze gebruiker of dit IP-adres voortaan niet meer tonen')}">🙈 ${L('Negeer')}</button>
          ${det.ip ? `<button type="button" class="linkbtn" data-acc="block" data-id="${r.id}" title="${L('IP-adres {ip} blokkeren', { ip: esc(det.ip) })}">⛔ ${L('Blokkeer')}</button>` : ''}
        </td></tr>`;
    }).join('')}</tbody></table></div>`;
}

/** 🙈 Negeer: kies de gebruiker(s) en/of het IP-adres die voortaan niet meer getoond worden */
function accIgnoreDialog(r) {
  const det = accDet(r);
  const users = [...new Set((det.users || [det.user]).filter((u) => u && u !== '?'))];
  const opts = [...users.map((u) => ['user', u, L('gebruiker {u}', { u: `<b>${esc(u)}</b>` })]), ...(det.ip ? [['ip', det.ip, L('IP-adres {ip}', { ip: `<code>${esc(det.ip)}</code>` })]] : [])];
  if (!opts.length) return toast(L('Geen gebruiker of IP-adres om te negeren'), 'err');
  modal({
    title: '🙈 ' + L('Negeren'),
    body: `<p style="margin-top:0">${L('Wat wil je voortaan niet meer zien in <i>Toegang</i>? Ook de regels die er al staan, verdwijnen.')}</p>
      ${opts.map(([k, v, l], i) => `<label class="switch small"><input type="checkbox" name="ign${i}" value="${esc(v)}" ${opts.length === 1 || k === 'ip' ? 'checked' : ''}><span></span> ${l}</label>`).join('')}
      ${xpHtml(L('Negeren verbergt alleen; brute-forcedetectie blijft werken.'), L('Een mislukte poging met meerdere gebruikers wordt alleen verborgen als <i>alle</i> gebruikers genegeerd worden, of het IP-adres. De lijst pas je aan bij <i>Instellingen → IDS → Algemeen → Negeren</i>. De brute-forcedetectie van het IDS blijft gewoon werken.'))}`,
    buttons: [{ label: '🙈 ' + L('Negeren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const add = Object.keys(d).filter((k) => k.startsWith('ign')).map((k) => d[k]);
      if (!add.length) throw new Error(L('Kies minstens één gebruiker of IP-adres'));
      await api('ids_ignore', { add: add.join(' ') });
      toast(L('Genegeerd: {list}', { list: add.join(', ') }));
      setTimeout(loadIds, 50);
    },
  });
}

const IDS_CATS = [['ports', () => L('Poorten'), ['port_new', 'kev_port']], ['net', () => '🌐 ' + L('Uitgaand'), ['outbound', 'out_bad', 'out_country']],
  ['web', () => '🕸️ ' + L('Webshell en diensten'), ['webshell', 'unit_new']], ['weblog', () => '📜 ' + L('Webdiensten'), ['web_attack']], ['files', () => L('Bestanden'), ['file', 'fim', 'fim_bulk']],
  ['procs', () => L('Processen'), ['proc', 'proc_suspicious', 'revshell']], ['logins', () => L('Logins'), ['bruteforce', 'breakin', 'rootlogin', 'newip', 'user_new', 'ssh_login', 'group_add', 'uid0']], ['decoy', () => '🍯 ' + L('Honeypot'), ['decoy_user', 'decoy_port']]];
const idsCatOf = (i) => (IDS_CATS.find((c) => c[2].includes(i.type)) || [''])[0];

/** Toegang in de laatste 24 uur: [gelukt, mislukt (pogingen), per uur mislukt] */
function accLast24() {
  if (!accData) return [0, 0, []];
  const since = Date.now() - 86400000;
  const hours = new Array(24).fill(0);
  let ok = 0, fail = 0;
  accData.rows.forEach((r) => {
    const t = new Date(r.created_at.replace(' ', 'T')).getTime();
    if (t < since) return;
    if (r.kind === 'authfail') { const n = +accDet(r).n || 1; fail += n; hours[Math.min(23, Math.floor((t - since) / 3600000))] += n; } else ok++;
  });
  return [ok, fail, hours];
}

let idsAttnOpen = false;   // "Nu aandacht nodig" op de IDS-pagina: alles tonen (anders 5)

/** Leren of Blokkeren als pil in de tellerbalk (1.117.0); klik = naar de instellingen */
function idsModePill(d) {
  if (!d.enabled) return `<a class="mode-pill off" href="#settings/ids" data-goto="settings/ids">⚠️ ${L('IDS staat uit')}</a>`;
  return `<a class="mode-pill ${d.mode === 'block' ? 'block' : 'learn'}" href="#settings/ids" data-goto="settings/ids" title="${esc(d.mode === 'block' ? L('brute force wordt gemeld én geblokkeerd') : L('alles wordt gemeld, er wordt niets geblokkeerd'))}">${d.mode === 'block' ? '⛔ ' + L('Blokkeren') : '🎓 ' + L('Leren')}</a>`;
}

/** Score-tegel met ring (1.117.0): gemiddelde hardening van de bewaakte servers, de toestand uit de open incidenten */
function idsScoreTile(d, open, o2) {
  const scores = d.hosts.filter((h) => !h.ha && h.hard && h.hard.score !== null).map((h) => +h.hard.score);
  const score = scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : null;
  const chains = open.filter((i) => i.type === 'chain').length;
  const st = !d.enabled ? 'warn' : o2 || chains ? 'bad' : open.length ? 'warn' : 'ok';
  const label = !d.enabled ? L('IDS staat uit') : o2 || chains ? L('Actie nodig') : open.length ? L('Aandacht') : L('Rustig');
  return `<div class="kpi kpi-main ${st}" data-igo="inc" title="${esc(L('Klik: de open incidenten'))}">
    <div class="ring" style="--p:${score ?? 0}" title="${esc(L('Gemiddelde hardening-score van je servers'))}"><span data-flash>${score ?? '–'}</span></div>
    <div class="kpi-txt"><div class="kpi-state">${{ ok: '🟢', warn: '🟠', bad: '🔴' }[st]} ${label}</div>
      <div class="kpi-sub">${open.length ? [o2 ? `${o2} critical` : '', open.length - o2 ? `${open.length - o2} warning` : '', chains ? L('{n} aanvalsketen(s)', { n: chains }) : ''].filter(Boolean).join(' · ') : L('niets open')}</div>
      <div class="kpi-sub">🛡️ ${score === null ? L('nog geen hardening-score') : L('hardening gemiddeld {n}', { n: score })}</div></div></div>`;
}

/** Nu aandacht nodig (1.117.0): open critical-incidenten en aanvalsketens, nieuwste eerst; maximaal 5, de rest uitklappen */
function renderIdsAttention(open) {
  const box = $('#ids-attn');
  const list = open.filter((i) => +i.severity === 2 || i.type === 'chain');
  if (!list.length) {
    box.innerHTML = `<div class="attn-ok">✔ ${L('Niets dringends')} 👌${open.length ? ` <span class="muted small">· ${L('{n} warning(s) open', { n: open.length })}</span>` : ''}</div>`;
    return;
  }
  box.innerHTML = `<div class="sec-h"><h2>⚠️ ${L('Nu aandacht nodig')}</h2><span>${list.length === 1 ? L('1 incident') : L('{n} incidenten', { n: list.length })} · ${L('critical en aanvalsketens')}</span></div>
    <div class="attn ids-attn-l ${idsAttnOpen ? 'all' : ''}">${list.map((i) => `<div class="arow">
      <i class="adot s2"></i>
      <span class="a-srv">${esc(i.host_name)}</span>
      <button type="button" class="a-chk linklike" data-inc="${i.id}" title="${esc(L('Details, advies en acties'))}">${i.type === 'chain' ? '🔗 ' : ''}${esc(i.title)}</button>
      <span class="a-msg">${esc(i.detail || '')}</span>
      <span class="a-since">${dur(i.created_at)}</span>
      <span class="a-acts">${aiTriage(i.id)}${aiBtn('ids', i.id)}<span class="need-admin a-acts-in"><button type="button" class="btn small-btn" data-ids="handle" data-id="${i.id}">✔ ${L('Afhandelen')}</button>${i.ip ? `<button type="button" class="btn small-btn" data-ids="block" data-ip="${esc(i.ip)}" data-host="${i.host_id}" title="${esc(L('Blokkeer IP'))}">⛔</button>` : ''}</span></span>
    </div>`).join('')}
    ${list.length > 3 ? `<button type="button" class="attn-more ${list.length > 5 ? '' : 'mob-only'}" data-ids-attn-more>${idsAttnOpen ? '▴ ' + L('Minder tonen') : '▾ ' + L('Alle {n} tonen', { n: list.length })}</button>` : ''}</div>`;
}

/** Incidenten per dag van één server, de laatste n dagen: [[dag, aantal, critical], …] */
function idsPerDay(hostId, n) {
  const out = [];
  for (let k = n - 1; k >= 0; k--) {
    const t = new Date(idsData.today + 'T12:00:00');
    t.setDate(t.getDate() - k);
    const day = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    // 1.217.0: uit de tellingen per dag (de lijst zelf bevat maar een paar dagen)
    const xs = idsData.perday ? idsData.perday.filter((r) => r.d === day && (hostId === null || +r.host_id === +hostId)).flatMap((r) => Array(+r.n).fill(r))
      : idsData.incidents.filter((i) => (hostId === null || +i.host_id === +hostId) && i.created_at.slice(0, 10) === day);
    out.push([day, xs, xs.filter((i) => +i.severity === 2).length]);
  }
  return out;
}

/** Kaart van een server met open incidenten (1.117.0): aantallen, hardening, blokkades en een staafje per dag (7 dagen) */
function idsHostCard(h, d = idsData) {
  const n = h.open[1] + h.open[2];
  // 1.165.0: modus erbij, een label onder elk getal en het woord Hardening
  return `<article class="ids-card ${h.open[2] ? 's2' : 's1'}">
    <div class="ic-head"><span class="ok-ic">🖥️</span><span class="ok-nm"><strong>${esc(h.name)}</strong><small>${h.last_report ? L('IDS-rapport {ago}', { ago: ago(h.last_report) }) : L('nog geen IDS-rapport (agent 1.35.0+)')}</small></span>
      ${idsSecChip(h)}</div>
    <div class="ic-mode">${idsModeChip(h, d)}</div>
    <div class="ic-nums">
      <button type="button" class="ic-n" data-ihost="${h.id}" title="${esc(L('Toon de open incidenten van deze server'))}"><b class="${h.open[2] ? 'danger-txt' : 'warn-txt'}">${n}</b><small>${L('open')}</small></button>
      <span class="ic-n"><b>${h.open[2]}</b><small>critical</small></span>
      <span class="ic-n"><b>${h.blocks || 0}</b><small>${L('geblokkeerd')}</small></span>
      <span class="ic-wk">${idsWeekSvg(h)}<small>${L('per dag, 7 dagen')}</small></span>
    </div>
    ${(() => { const ids = d.incidents.filter((i) => i.status === 'open' && +i.host_id === +h.id).map((i) => i.id); return ids.length > 1
      ? `<div class="ic-acts need-admin"><button type="button" class="btn small-btn" data-ids="handle" data-many="${ids.join(',')}" title="${esc(L('Alle open incidenten van deze server afhandelen'))}">✔ ${L('Alles van deze server afhandelen ({n})', { n: ids.length })}</button></div>` : ''; })()}
  </article>`;
}

/** Aanvallen per dag (1.117.0): 14 dagen, gestapeld per soort; uitklapbaar en standaard dicht */
// 1.166.0: Overig opgesplitst: bekende kwade adressen en malware, en afwijkingen en aanvalsketens apart
const IDS_CHART = [['login', () => L('Inloggen en lokaas'), ['logins', 'decoy']], ['web', () => L('Webaanvallen'), ['web', 'weblog', 'web_payload', 'web_cve']],
  ['net', () => L('Netwerk en poorten'), ['ports', 'net', 'suricata']], ['host', () => L('Processen en bestanden'), ['procs', 'files', 'edr']],
  ['ti', () => L('Bekende kwade adressen en malware'), ['ti_ip', 'ti_malware']], ['anom', () => L('Afwijkingen en ketens'), ['anomaly', 'chain']], ['other', () => L('Overig'), null]];
/** 1.166.0: wat het IDS de laatste 24 uur deed, en de meest actieve aanvallers (klik op het IP voor de tijdlijn) */
function idsDid(d) {
  if (!d) return '';
  const open = d.incidents.filter((i) => i.status === 'open'), crit = open.filter((i) => +i.severity === 2).length;
  const top = d.top24 || [], mx = Math.max(1, ...top.map((t) => +t.n));
  const kv = (n, l, cls) => `<div><b class="${n ? cls : ''}">${(+n).toLocaleString(LOCALE)}</b><span>${esc(l)}</span></div>`;
  return `<div class="card ids-did">${kv(d.blocked24 || 0, L('geblokkeerd (24 u)'), 'danger-txt')}${kv(open.length - crit, L('open waarschuwingen'), 'warn-txt')}${kv(crit, L('critical'), 'danger-txt')}</div>
    <div class="card ids-top"><div class="ids-top-h">${L('Meest actieve aanvallers (24 u)')}</div>
      ${top.length ? top.map((t) => `<div class="ids-top-i"><span><code>${esc(t.ip)}</code>${t.cc ? ` · ${esc(t.cc)}` : ''}${t.bad ? ` · <span class="danger-txt">☠️ ${esc(t.bad)}</span>` : ''} <span class="muted">→ ${esc(t.host)}${+t.hosts > 1 ? ' ' + esc(L('+{n} andere', { n: t.hosts - 1 })) : ''}</span>
        <span class="ids-top-b"><i style="width:${(t.n / mx * 100).toFixed(0)}%"></i></span></span><b>${(+t.n).toLocaleString(LOCALE)}</b></div>`).join('')
        : `<p class="muted small" style="margin:.2rem 0 0">${L('Geen aanvallen van buiten in de laatste 24 uur.')}</p>`}
      <p class="muted small" style="margin:.4rem 0 0">${L('Pogingen en treffers per IP-adres; klik op een adres voor de tijdlijn.')}</p></div>`;
}
function renderIdsChart() {
  const box = $('#ids-chart');
  let isOpen = isCC();   // 1.159.0: in CC staat de grafiek altijd open
  try { isOpen = isOpen || localStorage.getItem('rzmon-ids-chart') === '1'; } catch (e) { /* standaard dicht */ }
  const days = idsPerDay(null, 14);
  const grp = (i) => { const c = idsCatOf(i); return (IDS_CHART.find((g) => g[2] && (g[2].includes(c) || g[2].includes(i.type))) || IDS_CHART[IDS_CHART.length - 1])[0]; };
  const rows = days.map(([day, xs]) => [day, IDS_CHART.map(([k]) => xs.filter((i) => grp(i) === k).length)]);
  const total = rows.reduce((a, [, v]) => a + v.reduce((x, y) => x + y, 0), 0);
  const sums = IDS_CHART.map((g, k) => rows.reduce((a, [, v]) => a + v[k], 0));
  const max = Math.max(1, ...rows.map(([, v]) => v.reduce((x, y) => x + y, 0)));
  const W = 700, H = 120, bw = W / 14;
  // 1.166.0: een as met ronde getallen
  const step = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000].find((x) => max / x <= 4) || Math.ceil(max / 4);
  const ticks = []; for (let v = 0; v <= max + step * .01; v += step) ticks.push(v);
  const grid = ticks.map((v) => `<line x1="0" x2="${W}" y1="${(H - v / max * (H - 8)).toFixed(1)}" y2="${(H - v / max * (H - 8)).toFixed(1)}" class="ch-grid"/>`).join('');
  const yl = ticks.map((v) => `<span style="bottom:${(v / max * (H - 8) / H * 100).toFixed(1)}%">${v.toLocaleString(LOCALE)}</span>`).join('');
  const bars = rows.map(([day, v], k) => {
    let y = H;
    const segs = v.map((n, s) => {
      if (!n) return '';
      const h = n / max * (H - 8);
      y -= h;
      return `<rect class="ser-${s + 1}" x="${k * bw + 6}" y="${y.toFixed(1)}" width="${bw - 12}" height="${Math.max(1, h - 2).toFixed(1)}" rx="3"/>`;
    }).join('');
    const tip = `${dayLabel(day, idsData.today)}: ${v.reduce((x, y2) => x + y2, 0)}\n` + IDS_CHART.map(([, l], s) => v[s] ? `${l()}: ${v[s]}` : '').filter(Boolean).join('\n');
    return `<g class="ch-col"><rect class="ch-hit" x="${k * bw}" y="0" width="${bw}" height="${H}"><title>${esc(tip)}</title></rect>${segs}</g>`;
  }).join('');
  const lbl = rows.map(([day], k) => (k % 2 === 1 || k === 13 ? `<span style="left:${((k + .5) / 14 * 100).toFixed(2)}%">${esc(new Date(day + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }))}</span>` : '')).join('');
  box.innerHTML = `<details class="card ids-chart" ${isOpen ? 'open' : ''}><summary><b>📊 ${L('Aanvallen per dag')}</b> <span class="muted small">${L('14 dagen · {n} incidenten', { n: total })}</span></summary>
    <div class="ch-legend small">${IDS_CHART.map(([, l], k) => sums[k] ? `<span><i class="ser-${k + 1}"></i>${esc(l())} <b>${sums[k]}</b></span>` : '').join('')}</div>
    <div class="ch-plot"><div class="ch-y small muted" aria-hidden="true">${yl}</div>    <svg class="ch-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="${esc(L('Aanvallen per dag'))}">${grid}<line x1="0" y1="${H - .5}" x2="${W}" y2="${H - .5}" class="ch-base"/>${bars}</svg></div>
    <div class="ch-x small muted">${lbl}</div></details>${idsDid(idsData)}`;
  $('details', box).ontoggle = (e) => { try { localStorage.setItem('rzmon-ids-chart', e.target.open ? '1' : '0'); } catch (ex) { /* alleen voor nu */ } };
}

/** 1.165.0: wat het IDS op een server bewaakt (basismeting, firewall, lokaas, bestanden, realtime) */
function idsStLine(h, fw = true) {
  return [!h.baseline_until ? '' : h.learning ? L('📏 basismeting tot {t}', { t: esc(fmtUntil(h.baseline_until)) }) : '✔ ' + L('bewaakt'),
      fw && h.fw_state ? (IDS_FW[h.fw_state] || (() => esc(h.fw_state)))(h) : '',
      !fw && h.fw_state === 'error' ? IDS_FW.error(h) : '',
      h.decoy_state ? `<span class="warn-txt">🍯 ${esc(h.decoy_state)}</span>` : '',
      h.fim_count != null ? '📁 ' + L(+h.fim_count === 1 ? '{n} bestand bewaakt' : '{n} bestanden bewaakt', { n: (+h.fim_count).toLocaleString(LOCALE) }) : '',
      +h.realtime ? ((x) => (x && x.state === 'active' ? '<span class="good">⚡ realtime</span>' : `<span class="warn-txt">⚡ ${L('niet actief')}</span>`))(JSON.parse(h.rt || 'null')) : '',
      +h.realtime ? ((t) => (t && !t.inotify ? `<span class="warn-txt">${t.family === 'rhel' && !t.epel ? L('EPEL ontbreekt') : L('inotify-tools ontbreekt')}</span>` : ''))(JSON.parse(h.tools || 'null')) : ''].filter(Boolean).join(' · ');
}
/** 1.165.0: hardening-knop (met het woord Hardening) en een eventuele isolatie */
function idsSecChip(h) {
  const hs = h.hard && h.hard.score !== null ? h.hard.score : null;
  return `<button type="button" class="hard-chip ${hs === null ? '' : hs >= 85 ? 'good' : hs >= 60 ? 'warn' : 'bad'}" data-hostsec="${h.id}" title="${esc(L('Hardening, isoleren en gebruikers'))}">🛡️ ${L('Hardening')} ${hs === null ? '–' : hs}${h.hard && h.hard.bad ? ` <small>· ${L('{n} punt(en)', { n: h.hard.bad })}</small>` : ''}</button>`
      + (h.iso ? ` <span class="iso-chip" title="${esc(h.iso.isolate_state || '')}">🧱 ${h.iso.isolate_until ? L('geïsoleerd tot {t}', { t: esc(fmtUntil(h.iso.isolate_until)) }) : esc(h.iso.isolate_state)}</span>` : '');
}
/** 1.165.0: modus van het IDS op deze server, als chip */
function idsModeChip(h, d) {
  if (h.ha) return `<span class="ids-mode-chip na">${esc(L('geen IDS'))}</span>`;
  if (!d.enabled) return `<span class="ids-mode-chip na">${esc(L('IDS uit'))}</span>`;
  if (!h.last_report) return `<span class="ids-mode-chip na">${esc(L('geen rapport'))}</span>`;
  if (h.learning) return `<span class="ids-mode-chip learn" title="${esc(L('Eerst vastleggen wat normaal is'))}">📏 ${esc(L('basismeting'))}</span>`;
  if (d.mode !== 'block') return `<span class="ids-mode-chip learn">🎓 ${esc(L('Leren'))}</span>`;
  const m = { active: ['block', '⛔ ' + L('Blokkeren')], off: ['detect', '👁 ' + L('Alleen melden')], unsupported: ['detect', '👁 ' + L('Alleen detecteren'), L('TrueNAS: blokkeren staat uit. Zet het aan bij de server: 🛡️ Hardening → TrueNAS: ingrijpen door het IDS.')],
    disabled: ['detect', '👁 ' + L('Blokkeren uit')], error: ['err', '✘ ' + L('Firewall-fout')] }[h.fw_state] || ['block', '⛔ ' + L('Blokkeren')];
  return `<span class="ids-mode-chip ${m[0]}" ${m[2] ? `title="${esc(m[2])}"` : ''}>${esc(m[1])}</span>`;
}
/** 1.165.0: incidenten per dag (7 dagen) als staafjes */
function idsWeekSvg(h) {
  const days = idsPerDay(h.id, 7);
  const max = Math.max(1, ...days.map((x) => x[1].length));
  const bars = days.map(([day, xs, c], k) => `<rect class="${c ? 'c' : xs.length ? 'w' : 'z'}" x="${k * 14 + 1}" y="${xs.length ? 26 - Math.max(3, xs.length / max * 24) : 24}" width="11" height="${xs.length ? Math.max(3, xs.length / max * 24) : 2}" rx="2"><title>${esc(day)}: ${xs.length}</title></rect>`).join('');
  return `<svg class="ic-spark" viewBox="0 0 98 26" role="img" aria-label="${esc(L('Incidenten per dag, 7 dagen'))}">${bars}</svg>`;
}
const idsRebase = (h) => (h.baseline_until ? ` <button type="button" class="linkbtn need-admin" data-rebase="${h.id}" title="${L('Alles wat er nu is als normaal vastleggen')}">↺ ${L('basismeting opnieuw')}</button>` : '');
/** 1.165.0 L1nes (A): alle servers in één tabel, overal dezelfde kolommen */
function idsHostTable(d) {
  const order = (h) => (h.ha ? 3 : h.open[2] ? 0 : h.open[1] || h.iso ? 1 : 2);
  const hosts = [...d.hosts].sort((x, y) => order(x) - order(y) || (y.open[1] + y.open[2]) - (x.open[1] + x.open[2]) || String(x.name).localeCompare(String(y.name)));
  const num = (v, cls) => `<b class="ids-tn ${v ? cls : 'z'}">${v}</b>`;
  const rows = hosts.map((h) => {
    const n = h.open[1] + h.open[2], cls = h.ha ? 'na' : h.open[2] ? 's2' : n || h.iso ? 's1' : 's0';
    if (h.ha) return `<tr class="ids-tr na"><td><strong>${esc(h.name)}</strong><br><span class="muted small">${esc(L('Home Assistant-add-on: draait in een container en ziet het systeem zelf niet'))}</span></td><td>${idsModeChip(h, d)}</td><td colspan="6" class="muted small">–</td></tr>`;
    return `<tr class="ids-tr ${cls}">
      <td><strong>${esc(h.name)}</strong><br><span class="muted small">${h.last_report ? esc(L('IDS-rapport {ago}', { ago: ago(h.last_report) })) : d.enabled ? esc(L('nog geen IDS-rapport (agent 1.35.0+)')) : ''}</span></td>
      <td>${idsModeChip(h, d)}</td>
      <td>${n ? `<button type="button" class="ic-n" data-ihost="${h.id}" title="${esc(L('Toon de open incidenten van deze server'))}">${num(n, h.open[2] ? 'c' : 'w')}</button>` : num(0, 'w')}</td>
      <td>${num(h.open[2], 'c')}</td><td>${num(+h.blocks || 0, 'c')}</td>
      <td>${idsWeekSvg(h)}</td><td>${idsSecChip(h)}</td>
      <td class="small ids-st">${idsStLine(h, false)}${idsRebase(h)}</td></tr>`;
  }).join('');
  return `<div class="table-scroll"><table class="ids-tbl"><thead><tr><th>${L('Server')}</th><th>${L('Modus')}</th><th>${L('Open')}</th><th>Critical</th><th>${L('Geblokkeerd')}</th><th>${L('Incidenten 7 dagen')}</th><th>${L('Hardening')}</th><th>${L('Bewaakt')}</th></tr></thead><tbody>${rows}</tbody></table></div>
    <p class="muted small ids-tbl-key">${L('<b>Hardening</b>: hoe goed de server is dichtgezet (0–100); klik voor wat er beter kan. Klik op het aantal open voor de incidenten.')}</p>`;
}
/** 1.165.0 CC en Blox (B): wat nu aandacht vraagt, in gewone zinnen, met een knop */
function idsTodo(d) {
  const items = [];
  const cat = (i) => { const c = IDS_CATS.find((x) => x[2].includes(i.type)); return c ? c[1]().replace(EMOJI_LEAD, '') : L('overig'); };
  d.hosts.filter((h) => !h.ha && h.open[1] + h.open[2] > 0).sort((x, y) => y.open[2] - x.open[2] || (y.open[1] + y.open[2]) - (x.open[1] + x.open[2])).forEach((h) => {
    const open = d.incidents.filter((i) => +i.host_id === +h.id && i.status === 'open');
    const by = {};
    open.forEach((i) => { const k = cat(i); by[k] = (by[k] || 0) + 1; });
    const top = Object.entries(by).sort((x, y) => y[1] - x[1])[0];
    const n = h.open[1] + h.open[2];
    const txt = (n === 1 ? L('1 open incident') : L('{n} open incidenten', { n })) + (h.open[2] ? ' · ' + L('{n} critical', { n: h.open[2] }) : '')
      + (top ? ' · ' + L('meest: {c}', { c: top[0].toLowerCase() }) : '') + (+h.blocks ? ' · ' + L('{n} geblokkeerd', { n: h.blocks }) : '');
    items.push([h.open[2] ? 's2' : 's1', h.name, txt, `<button type="button" class="btn small-btn" data-ihost="${h.id}">${L('Bekijk')} →</button>`]);
  });
  d.hosts.filter((h) => !h.ha && h.hard && h.hard.score !== null && +h.hard.score < 60).forEach((h) => {
    items.push(['s1', h.name, L('Hardening {s}/100', { s: h.hard.score }) + (h.hard.bad ? ' · ' + L('{n} punt(en) om dicht te zetten', { n: h.hard.bad }) : ''), `<button type="button" class="btn small-btn" data-hostsec="${h.id}">${L('Dichtzetten')} →</button>`]);
  });
  if (d.enabled) d.hosts.filter((h) => !h.ha && !h.last_report).forEach((h) => items.push(['s3', h.name, L('nog geen IDS-rapport (agent 1.35.0+)'), '']));
  return `<div class="ids-todo card"><div class="ids-todo-h">${L('Wat nu aandacht vraagt')}</div>${items.length ? items.map(([c, n, t, btn]) => `<div class="ids-todo-i ${c}"><span class="dot"></span><span><b>${esc(n)}</b> · <span class="muted">${esc(t)}</span></span>${btn}</div>`).join('')
    : `<p class="muted small" style="margin:.2rem 0 0">✔ ${L('Niets dat nu aandacht vraagt.')}</p>`}</div>`;
}
/** 1.165.0 CC en Blox (B): rustige servers als chips; klik voor de details */
function idsQuietChip(h, d) {
  const hs = h.hard && h.hard.score !== null ? h.hard.score : null;
  return `<details class="ids-q ${h.ha ? 'na' : ''}"><summary><span class="dot"></span><b>${esc(h.name)}</b> ${idsModeChip(h, d)}${hs !== null ? ` <span class="muted small">${L('Hardening')} ${hs}</span>` : ''}</summary>
    <div class="ids-q-d small">${h.ha ? esc(L('Home Assistant-add-on: draait in een container en ziet het systeem zelf niet')) : `${idsSecChip(h)} ${idsStLine(h, false)}${idsRebase(h)}<br><span class="muted">${h.last_report ? esc(L('IDS-rapport {ago}', { ago: ago(h.last_report) })) : ''}</span>`}</div></details>`;
}

function renderIds() {
  const d = idsData;
  if (!d) return;
  const open = d.incidents.filter((i) => i.status === 'open');
  const o2 = open.filter((i) => +i.severity === 2).length;
  const [accOk, accFail, failHours] = accLast24();
  // Tellers
  const cnt = (cls, n, word, attrs, zeroOk = false) => `<button type="button" class="hc ${cls} ${n || zeroOk ? '' : 'zero'}" ${attrs}><span class="hc-dot"></span><b data-flash>${n}</b> <span class="hc-w">${word}</span></button>`;
  $('#ids-counts').innerHTML = cnt('s2', o2, 'Critical', 'data-igo="inc"') + cnt('s1', open.length - o2, 'Warning', 'data-igo="inc"')
    + cnt('sx2', d.blocks.length, '⛔ ' + L('Geblokkeerd'), 'data-igo="blk"') + cnt('si', accOk, '🔑 ' + L('Logins 24 u'), 'data-igo="acc"')
    + cnt('sx', accFail, '❌ ' + L('Mislukt 24 u'), 'data-igo="acc" data-fail="1"');
  const lastRep = d.hosts.map((h) => h.last_report).filter(Boolean).sort().pop();
  $('#ids-last').textContent = lastRep ? L('IDS-rapport {ago}', { ago: ago(lastRep) }) : '';
  // Kerncijfers
  const real = d.hosts.filter((h) => !h.ha);
  const watched = real.filter((h) => h.last_report);
  const missing = real.find((h) => !h.last_report);
  const oldest = open[open.length - 1];
  const maxF = Math.max(1, ...failHours);
  let fp = '';
  failHours.forEach((v, i) => { fp += `${fp ? 'L' : 'M'}${(i / 23 * 100).toFixed(1)},${(24 - v / maxF * 20).toFixed(1)}`; });
  const modern = PREFS.ids_modern ? PREFS.ids_modern === 'on' : d.modern !== false;   // 1.162.0: per account
  $('#tab-ids').classList.toggle('ids-modern', modern);
  if (modern) $('#ids-last').innerHTML = idsModePill(d) + (lastRep ? ` <span>${esc(L('IDS-rapport {ago}', { ago: ago(lastRep) }))}</span>` : '');
  $('#ids-kpis').innerHTML = `
    ${modern ? idsScoreTile(d, open, o2) : `<div class="kpi kpi-main ${!d.enabled ? 'warn' : ''}"><div class="kpi-txt"><div class="kpi-lbl">${L('Modus')}</div>
      ${d.enabled ? `<div class="ids-mode"><span class="${d.mode !== 'block' ? 'on' : ''}">🎓 ${L('Leren')}</span><span class="${d.mode === 'block' ? 'on bad' : ''}">⛔ ${L('Blokkeren')}</span></div>
        <div class="kpi-sub">${d.mode === 'block' ? L('brute force wordt gemeld én geblokkeerd') : L('alles wordt gemeld, er wordt niets geblokkeerd')} · <a href="#settings/ids" data-goto="settings/ids">${L('instellingen')}</a></div>`
        : `<div class="kpi-state">${L('IDS staat uit')}</div><div class="kpi-sub"><a href="#settings/ids" data-goto="settings/ids">${L('aanzetten')}</a></div>`}</div></div>`}
    <div class="kpi ${missing && d.enabled ? 'warn' : ''}"><div class="kpi-lbl">${L('Servers bewaakt')}</div><div class="kpi-val">${watched.length}<small>/${real.length}</small></div>
      <div class="kpi-sub">${missing && d.enabled ? L('{name}: nog geen IDS-rapport', { name: esc(missing.name) }) : real.length ? L('allemaal') : L('nog geen servers met agent')}</div></div>
    <div class="kpi ${o2 ? 'bad' : open.length ? 'warn' : ''}"><div class="kpi-lbl">${L('Open incidenten')}</div><div class="kpi-val">${open.length}</div>
      <div class="kpi-sub">${oldest ? `${L('langst')}: ${esc(oldest.title)} (${dur(oldest.created_at)})` : L('niets open')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Nu geblokkeerd')}</div><div class="kpi-val">${d.blocks.length}</div>
      <div class="kpi-sub">${d.blocks.length ? esc(d.blocks.slice(0, 3).map((b) => b.ip).join(' · ')) : d.mode === 'block' ? L('niets') : L('modus leren')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Mislukte logins 24 u')}</div><div class="kpi-val">${accFail}</div>
      <svg class="kpi-spark" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${fp}L100,26L0,26Z" fill="var(--warn)" opacity=".14"/><path d="${fp}" fill="none" stroke="var(--warn)" stroke-width="2" vector-effect="non-scaling-stroke"/></svg></div>`;
  $('#ids-attn').hidden = !modern;
  $('#ids-chart').hidden = !modern;
  if (modern) { renderIdsAttention(open); renderIdsChart(); }
  // Servers: compacte regels (modern: met open incidenten als kaart, de rest compact eronder)
  const hostRow = (h) => {
    if (h.ha) return `<div class="srv-row"><span class="ok-ic">🏠</span><span class="ok-nm"><strong>${esc(h.name)}</strong><small>${L('niet beschikbaar in de Home Assistant-add-on: die draait in een container en ziet het systeem zelf niet')}</small></span><span class="srv-st"></span><span class="srv-n muted small">–</span></div>`;
    const st = idsStLine(h);
    const n = h.open[1] + h.open[2];
    // Hardening-score en isolatie (1.88.0): klik voor details, isoleren en gebruikers
    const hs = h.hard && h.hard.score !== null ? h.hard.score : null;
    const sec = idsSecChip(h);
    return `<div class="srv-row"><span class="ok-ic">🖥️</span>
      <span class="ok-nm"><strong>${esc(h.name)}</strong><small>${h.last_report ? L('IDS-rapport {ago}', { ago: ago(h.last_report) }) : d.enabled ? L('nog geen IDS-rapport (agent 1.35.0+)') : ''}</small></span>
      <span class="srv-st small">${sec} ${st}${h.baseline_until ? ` <button type="button" class="linkbtn need-admin" data-rebase="${h.id}" title="${L('Alles wat er nu is als normaal vastleggen')}">↺ ${L('basismeting opnieuw')}</button>` : ''}</span>
      <span class="srv-n">${n ? `<b class="${h.open[2] ? 'danger-txt' : 'warn-txt'}">● ${L('{n} open', { n })}</b>` : `<b class="good-txt">● ${L('geen incidenten')}</b>`}</span></div>`;
  };
  const busy = (h) => !h.ha && (h.open[1] + h.open[2] > 0 || !!h.iso);
  const cards = modern ? d.hosts.filter(busy) : [];
  const rows = d.hosts.filter((h) => !cards.includes(h));
  const lay = layoutPref();   // 1.165.0
  $('#ids-hosts').className = modern ? 'ids-hosts-m' : 'srv-list';
  $('#ids-hosts').innerHTML = !d.hosts.length ? '<p class="muted">' + L('Nog geen servers met agent.') + '</p>'
    : !modern ? rows.map(hostRow).join('')
    : lay === 'l1nes' ? `<div class="sec-h hosts-h"><h2>🖥️ ${L('Servers')}</h2><span>${d.hosts.length === 1 ? L('1 server') : L('{n} servers', { n: d.hosts.length })}</span></div>${idsHostTable(d)}`
    : lay === 'blox' || lay === 'cc' ? idsTodo(d)
      + (cards.length ? `<div class="sec-h hosts-h"><h2>🖥️ ${L('Servers met open incidenten')}</h2><span>${cards.length}</span></div><div class="ids-cards">${cards.map((h) => idsHostCard(h, d)).join('')}</div>` : '')
      + (rows.length ? `<div class="sec-h hosts-h"><h2>✔ ${L('Rustig')}</h2><span>${rows.length === 1 ? L('1 server') : L('{n} servers', { n: rows.length })}</span></div><div class="ids-quiet">${rows.map((h) => idsQuietChip(h, d)).join('')}</div>` : '')
    : (cards.length ? `<div class="sec-h hosts-h"><h2>🖥️ ${L('Servers met open incidenten')}</h2><span>${cards.length}</span></div><div class="ids-cards">${cards.map(idsHostCard).join('')}</div>` : '')
      + (rows.length ? `<div class="sec-h hosts-h"><h2>✔ ${L('Rustig')}</h2><span>${rows.length === 1 ? L('1 server') : L('{n} servers', { n: rows.length })}</span></div><div class="srv-list">${rows.map(hostRow).join('')}</div>` : '');

  // Incidenten per dag; dezelfde soort op dezelfde server achter elkaar gebundeld
  const q = $('#ids-search').value.trim().toLowerCase();
  const scope = d.incidents.filter((i) => idsFilter === 'all' || i.status === 'open');
  $('#ids-filter').innerHTML = [['open', 'Open', open.length], ['all', L('Alles'), d.incidents.length]].map(([k, l, n]) => `<button type="button" class="dchip ${idsFilter === k && !idsCat ? 'on' : ''}" data-if="${k}">${l} <b>${n}</b></button>`).join('')
    + IDS_CATS.map(([k, l]) => [k, l(), scope.filter((i) => idsCatOf(i) === k).length]).filter(([, , n]) => n)
      .map(([k, l, n]) => `<button type="button" class="dchip ${idsCat === k ? 'on' : ''}" data-icat="${k}">${l} <b>${n}</b></button>`).join('');
  if (idsCard) {
    const cardTitle = (((protData && protData.cards) || []).find((c) => c.id === idsCard) || {}).title || idsCard;
    $('#ids-filter').innerHTML += ` <button type="button" class="dchip on" data-icard-off="1" title="${esc(L('Filter weghalen'))}">🛡️ ${esc(cardTitle)} ✕</button>`;
  }
  const list = scope.filter((i) => (!idsCard || ((d.prot || {})[i.id] || {}).card === idsCard) && (!idsCat || idsCatOf(i) === idsCat) && (!q || `${i.title} ${i.detail} ${i.host_name} ${i.ip || ''}`.toLowerCase().includes(q)) && fxPass('ids', i));
  fxRender('ids');
  const narrowed = !!q || !!idsCat || !!idsCard || fxActive('ids');
  $('#ids-info').textContent = L('{open} open · {n} in de laatste {days} dagen', { open: open.length, n: d.incidents.length, days: d.days });
  $('#it-n-inc').textContent = open.length ? open.length : '';
  const el = $('#ids-incidents');
  if (q) moreDaysWhenSearching('ids', d);
  const more = idsFilter === 'all' || q ? moreDays('ids', d) : '';   // open incidenten zijn er altijd allemaal
  if (!list.length) {
    el.innerHTML = `<p class="muted">${idsFilter === 'open' && !q && !idsCat ? L('Geen open incidenten.') + ' 👌' : L('Niets gevonden.')}</p>` + more;
  } else {
    const byDay = new Map();
    list.forEach((i) => { const day = i.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(i); });
    const fresh = todayFresh('ids', idsDayOpen, d.today, (byDay.get(d.today) || []).length);
    const isOpen = (day) => idsDayOpen[day] ?? (day === d.today || narrowed || (smartOpen() && idsFilter === 'open'));
    idsDayItems = byDay;
    el.innerHTML = (idsFilter === 'open' && !narrowed ? idsNoisy(d) : '') + (!narrowed && !byDay.has(d.today) ? todayEmpty(d.today, (idsFilter === 'open' ? L('geen nieuwe open incidenten') : L('geen incidenten')) + ' 👌') : '') + [...byDay].map(([day, items]) => {
      const n2 = items.filter((i) => +i.severity === 2).length, n1 = items.length - n2;
      return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''} ${fresh && day === d.today ? 'data-fresh="1"' : ''}>
        <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
          <span class="ad-counts">${n2 ? `<span class="ad-count">🔴 ${n2}</span>` : ''}${n1 ? `<span class="ad-count">🟠 ${n1}</span>` : ''}</span>
          <span class="muted small ad-total">${items.length === 1 ? L('1 incident') : L('{n} incidenten', { n: items.length })}</span></summary>
        <div class="ad-body"></div></details>`;
    }).join('') + more;
    $$('.alert-day', el).forEach((det) => {
      if (det.open) idsFillDay(det);   // dichte dagen worden pas opgebouwd als je ze openklapt (1.217.0)
      det.ontoggle = () => { idsDayOpen[det.dataset.day] = det.open; if (det.open && !det.dataset.built) idsFillDay(det); idsToggleLabel(); };
    });
    idsSelBar();
  }
  idsToggleLabel();

  // Blokkades
  $('#ids-block-info').textContent = L('{n} actief', { n: d.blocks.length });
  $('#it-n-blk').textContent = d.blocks.length ? d.blocks.length : '';
  const row = (b, active) => `<tr>
      <td class="mono">${esc(b.ip)}${ccTag(b.cc)}</td><td>${esc(b.host_name)}</td><td>${esc(b.reason)}</td>
      <td class="at-time" title="${esc(b.created_at)}">${esc(fmtUntil(b.created_at))}</td>
      <td class="at-time">${active ? (b.until ? esc(fmtUntil(b.until)) : '<b>' + L('permanent') + '</b>') : b.removed_at ? (b.removed_by ? L('gedeblokkeerd {t} door {who}', { t: esc(fmtUntil(b.removed_at)), who: esc(b.removed_by) }) : L('gedeblokkeerd {t}', { t: esc(fmtUntil(b.removed_at)) })) : L('verlopen')}</td>
      <td>${+b.auto ? L('automatisch') : esc(b.created_by)}</td>
      <td title="${L('Tegengehouden pakketten sinds de laatste wijziging van de lijst')}">${+b.hits || 0}</td>
      <td>${active ? `<button type="button" class="btn need-admin small-btn" data-unblock="${b.id}">🔓 ${L('Deblokkeren')}</button>` : ''}</td></tr>`;
  const head = `<thead><tr><th>IP</th><th>Server</th><th>${L('Reden')}</th><th>${L('Sinds')}</th><th>${L('Tot')}</th><th>${L('Door')}</th><th>${L('Pakketten')}</th><th></th></tr></thead>`;
  const blocks = d.blocks.filter((b) => fxPass('blk', { ...b, act: '1' })), removed = d.removed.filter((b) => fxPass('blk', { ...b, act: '0' }));
  const fxB = fxActive('blk');
  $('#ids-blocks').hidden = isAdmin();
  $('#ids-block-info').closest('.dash-bar').hidden = isAdmin();   // beheerders: de kaart hieronder heeft een eigen zoekveld
  $('#ids-blocks').innerHTML = (blocks.length ? `<div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${blocks.map((b) => row(b, true)).join('')}</tbody></table></div>`
      : `<p class="muted">${fxB && d.blocks.length ? L('Niets gevonden.') : L('Niets geblokkeerd.')}${d.mode !== 'block' && !fxB ? ' ' + L('(Modus <i>leren</i>: er wordt niets geblokkeerd.)') : ''}</p>`)
    + (removed.length ? `<details class="small api-docs" ${fxB ? 'open' : ''}><summary>${L('Eerder geblokkeerd ({n})', { n: removed.length })}</summary>
        <div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${removed.map((b) => row(b, false)).join('')}</tbody></table></div></details>` : '');
  fxRender('blk');
}

// ---------------------------------------------------------------- Beveiliging per server (1.88.0)
/** Hardening-score, isoleren en gebruikers vergrendelen voor één server */
async function hostSecDialog(hostId) {
  const h = (idsData?.hosts || []).find((x) => +x.id === hostId) || { name: '?' };
  let d;
  try { d = await api('harden_detail', null, { host_id: hostId }); } catch (ex) { toast(ex.message, 'err'); return; }
  const ic = (i) => (i.ignored ? '<span class="muted">–</span>' : i.ok === true ? '<b class="good-txt">✔</b>' : i.ok === false ? '<b class="danger-txt">✘</b>' : '<span class="muted">?</span>');
  const iso = d.iso && d.iso.isolate_until && new Date(d.iso.isolate_until.replace(' ', 'T')) > new Date();
  const locks = d.userlocks.filter((u) => u.action === 'lock');
  modal({
    title: `🛡️ ${h.name}`,
    wide: true,
    body: `<h3 class="set-sub" style="margin-top:0">${L('Hardening')} ${d.score !== null ? `<span class="hard-chip ${d.score >= 85 ? 'good' : d.score >= 60 ? 'warn' : 'bad'}">${d.score}/100</span>` : ''}</h3>
      ${d.items.length ? `<p class="muted small" style="margin-top:-.3rem">${L('Klik op een punt voor uitleg, hoe je het controleert en hoe je het dichtzet.')}</p>
        <div class="hard-list">${d.items.map((i, n) => { const hp = i.help || {}; return `<details class="hard-item ${i.ignored ? 'muted' : ''}" ${i.ok === false && !i.ignored ? 'open' : ''}>
        <summary>${ic(i)} <b>${esc(i.title)}</b>${i.value ? ` <code>${esc(i.value)}</code>` : ''}
          <label class="small need-admin hard-ign"><input type="checkbox" data-hard-ign="${esc(i.item)}" ${i.ignored ? 'checked' : ''}> ${L('negeren')}</label></summary>
        <div class="hard-help">${hp.what ? `<p><b>${L('Wat is het?')}</b> ${esc(i.ok === true ? hp.why : hp.what + ' ' + hp.why)}</p>` : ''}
          ${hp.check ? `<div class="adv-cmd"><span class="small muted">${L('Controleren')}</span>${copyBox(`hard-chk-${n}`, hp.check)}</div>` : ''}
          ${i.ok !== true ? `<p><b>${L('Oplossen')}</b> ${esc(hp.fix || i.advice)}</p>${cmdList(hp.cmds || [], `hard-fix-${n}`)}` : `<p class="good small">✔ ${L('In orde; niets te doen.')}</p>`}
          ${hp.caution && i.ok !== true ? `<p class="callout small">⚠️ ${esc(hp.caution)}</p>` : ''}</div></details>`; }).join('')}</div>`
        : `<p class="muted small">${L('Nog geen gegevens: agent 1.57.0 nodig; hij stuurt ze elk uur.')}</p>`}
      <div class="need-admin">
      ${d.truenas ? `<h3 class="set-sub">🗄️ ${L('TrueNAS: ingrijpen door het IDS')}</h3>
        <label class="switch"><input type="checkbox" data-tn-fw ${d.truenas.fw ? 'checked' : ''}><span></span> <b>${L('IDS mag hier blokkeren')}</b></label>
        <p class="muted small">${L('Standaard alleen detecteren: TrueNAS beheert zijn eigen netwerk. Zet je dit aan (met je wachtwoord), dan mag het IDS aanvallers blokkeren en werkt de honeypot-poort, net als op andere servers. De agent gebruikt een eigen firewalltabel en zet die elke minuut terug, ook na een herstart of update van TrueNAS. Je lokale netwerken en RzSense worden nooit geblokkeerd. Agent 1.77.0 of nieuwer nodig.')}</p>
        ${d.truenas.fw && d.truenas.state === 'unsupported' ? `<p class="warn-txt small">⏳ ${L('Aangezet; de agent neemt het over bij zijn volgende ronde (agent 1.77.0 of nieuwer).')}</p>` : ''}` : ''}
      <h3 class="set-sub">🧱 ${L('Isoleren')}</h3>
      ${xpHtml(L('Sluit de server af van alles behalve RzSense, je eigen netwerk en jou.'), L('<b>Bij een hack:</b> alleen RzSense, je lokale netwerken en jouw IP-adres ({ip}) kunnen er nog bij; al het andere verkeer (in, uit en containers) stopt. <b>Goed om te weten:</b> het vervalt vanzelf na de gekozen tijd, ook als RzSense onbereikbaar is.', { ip: `<code>${esc(d.my_ip)}</code>` }))}
      ${iso ? `<p><b class="danger-txt">🧱 ${L('Geïsoleerd tot {t}', { t: esc(fmtDateTime(d.iso.isolate_until)) })}</b> · ${L('door {by}', { by: esc(d.iso.isolate_by || '?') })} · <span class="muted">${esc(d.iso.isolate_state || '')}</span>
          <button type="button" class="btn" data-unisolate>🔓 ${L('Opheffen')}</button></p>`
        : `<div class="btn-row">${[1, 4, 24, 72].map((n) => `<button type="button" class="btn danger" data-isolate="${n}">🧱 ${n < 24 ? L('{n} uur', { n }) : n === 24 ? L('1 dag') : L('3 dagen')}</button>`).join('')}</div>
          ${d.iso && d.iso.isolate_state && d.iso.isolate_state !== 'off' ? `<p class="muted small">${L('Laatste stand')}: ${esc(d.iso.isolate_state)}</p>` : ''}`}
      <h3 class="set-sub">🔒 ${L('Gebruiker vergrendelen')}</h3>
      ${xpHtml(L('Zet het account op slot en stopt al zijn processen.'), L('Het account op deze server gaat op slot (geen wachtwoord, geen SSH-sleutel) en al zijn processen worden gestopt, bijvoorbeeld als een gebruiker is overgenomen. Root kan niet.'))}
      <div class="inline-row"><input id="ul-name" placeholder="${esc(L('gebruikersnaam'))}" class="mono" autocomplete="off"> <button type="button" class="btn danger" data-ulock>🔒 ${L('Vergrendelen')}</button></div>
      ${locks.length ? `<ul class="small">${locks.map((u) => `<li><code>${esc(u.username)}</code> · ${L('door {by}', { by: esc(u.created_by || '?') })} · ${u.done_at ? esc(u.result || '') : L('wacht op de agent')}
        <button type="button" class="linkbtn" data-uunlock="${esc(u.username)}">${L('vrijgeven')}</button></li>`).join('')}</ul>` : ''}
      </div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  const body = $('#modal-form');
  wireCopy(body);
  $$('.hard-ign', body).forEach((l) => l.addEventListener('click', (e) => e.stopPropagation()));   // 1.143.0: was een inline onclick (CSP)
  body.onchange = async (e) => {
    const c = e.target.closest('[data-hard-ign]');
    if (!c) return;
    try { await api('harden_ignore', { host_id: hostId, item: c.dataset.hardIgn, ignored: c.checked }); $('#modal').close(); hostSecDialog(hostId); loadIds(); } catch (ex) { toast(ex.message, 'err'); }
  };
  body.addEventListener('change', async (e) => {   // 1.174.0: TrueNAS mag blokkeren
    const tn = e.target.closest('[data-tn-fw]');
    if (!tn) return;
    const on = tn.checked;
    tn.checked = !on;
    try {
      let pw = '';
      if (on) {
        pw = await askConfirm({ title: L('IDS laten blokkeren op {name}?', { name: h.name }), danger: true, word: 'TOESTAAN', ok: L('Aanzetten'),
          text: L('Het IDS mag dan aanvallers op deze TrueNAS blokkeren, met een eigen firewalltabel. Je lokale netwerken en RzSense worden nooit geblokkeerd.') });
        if (pw === null) return;
      }
      await api('host_truenas_fw', { host_id: hostId, on: on ? 1 : 0, confirm: pw });
      tn.checked = on;
      toast(on ? L('Aangezet; de agent neemt het over bij zijn volgende ronde') : L('Uitgezet: alleen detecteren'));
    } catch (ex) { toast(ex.message, 'err'); }
  }, { signal: modalSignal() });   // 1.224.4: weg zodra er een ander venster komt
  body.onclick = async (e) => {
    const isoB = e.target.closest('[data-isolate]'), un = e.target.closest('[data-unisolate]'), ul = e.target.closest('[data-ulock]'), uu = e.target.closest('[data-uunlock]');
    if (!isoB && !un && !ul && !uu) return;
    e.preventDefault();
    try {
      if (isoB) {
        const pw = await askConfirm({ title: L('{name} isoleren?', { name: h.name }), danger: true, word: 'ISOLEREN', ok: '🧱 ' + L('Isoleren'),
          text: L('Alleen RzSense, je lokale netwerken en jouw IP-adres kunnen er nog bij; al het andere verkeer stopt. Vervalt vanzelf na de gekozen tijd.') });
        if (pw === null) return;
        await api('host_isolate', { host_id: hostId, hours: +isoB.dataset.isolate, confirm: pw });
        toast(L('{name} wordt geïsoleerd; de agent past het binnen een minuut toe', { name: h.name }));
      } else if (un) {
        await isoLift(hostId, h.name);
      } else if (ul) {
        const name = $('#ul-name').value.trim();
        if (!name) return;
        const pw = await askConfirm({ title: L('{user} op {name} vergrendelen?', { user: name, name: h.name }), danger: true, word: 'VERGRENDELEN', ok: '🔒 ' + L('Vergrendelen'),
          text: L('Het account gaat op slot (geen wachtwoord, geen SSH-sleutel) en al zijn processen worden gestopt.') });
        if (pw === null) return;
        await api('host_userlock', { host_id: hostId, user: name, lock: true, confirm: pw }); toast(L('{user} wordt vergrendeld', { user: name }));
      } else {
        await api('host_userlock', { host_id: hostId, user: uu.dataset.uunlock, lock: false }); toast(L('{user} wordt vrijgegeven', { user: uu.dataset.uunlock }));
      }
      $('#modal').close(); hostSecDialog(hostId); loadIds();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- Tijdlijn per IP (1.88.0)
const TL_SRC = { ids: '🛡️', block: '⛔', unblock: '🔓', login: '🔑', fail: '❌', alert: '🔔' };
/** 1.149.0: de samenvatting en de tijdlijn van een IP als HTML (ook in de popup van de aanvalskaart) */
function ipTlHtml(d) {
  const day = (s) => s.slice(0, 10);
  let last = '';
  return `<p style="margin-top:0" class="small">${ccTag(d.cc) ? ccTag(d.cc) + ' · ' : ''}${[d.internal ? '🏠 ' + L('je eigen netwerk') : '',
        d.bad ? '☠️ ' + L('bekende aanvaller ({list})', { list: esc(d.bad) }) : '',
        d.whitelist ? '✅ ' + L('op de whitelist') + (d.whitelist.until ? ` (${L('tot {t}', { t: esc(fmtDateTime(d.whitelist.until)) })})` : '') : '',
        d.blocked_rz ? '⛔ ' + L('geblokkeerd in RzSense') : '', d.blocked_ids.length ? '⛔ ' + L('geblokkeerd op {list}', { list: esc(d.blocked_ids.join(', ')) }) : '',
        d.first ? L('eerst gezien {t}', { t: esc(fmtDateTime(d.first)) }) : '', d.hosts.length ? L('servers: {list}', { list: esc(d.hosts.join(', ')) }) : ''].filter(Boolean).join(' · ') || L('Nog niets bekend van dit IP-adres.')}</p>
      ${d.events.length ? `<div class="tl">${d.events.map((x) => {
        const head = day(x.at) !== last ? `<div class="tl-day">${esc(new Date(x.at.replace(' ', 'T')).toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</div>` : '';
        last = day(x.at);
        return `${head}<div class="tl-row ${x.sev === 2 ? 'crit' : ''}"><span class="tl-t">${esc(x.at.slice(11, 16))}</span><span>${TL_SRC[x.src] || '•'}</span><span class="tl-x">${esc(x.text)}${x.host ? ` <span class="muted small">· ${esc(x.host)}</span>` : ''}</span></div>`;
      }).join('')}</div>` : ''}`;
}
async function ipTimeline(ip) {
  let d;
  try { d = await api('ip_timeline', null, { ip }); } catch (ex) { toast(ex.message, 'err'); return; }
  modal({
    title: `🔎 ${ip}`,
    wide: true,
    body: `${ipTlHtml(d)}
      <div class="btn-row need-admin">${!d.whitelist && !d.internal ? `<button type="button" class="btn" data-tl-wl>✅ ${L('Op de whitelist')}</button>` : ''}
        <a href="#ids/blk" class="btn" data-tl-go>⛔ ${L('Blokkades en whitelist')}</a></div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  $('#modal-form').onclick = (e) => {
    if (e.target.closest('[data-tl-wl]')) { e.preventDefault(); $('#modal').close(); wlDialog({ type: 'ip', value: ip, wl: false }); }
    if (e.target.closest('[data-tl-go]')) { e.preventDefault(); $('#modal').close(); showTab('ids/blk'); }
  };
}
// Klik op een IP-adres (in een <code>) op de pagina's Meldingen, IDS en bij de blokkades: de tijdlijn van dat IP
const IP_RE = /^(\d{1,3}(\.\d{1,3}){3}|[0-9a-f]*:[0-9a-f:]+)$/i;
document.addEventListener('click', (e) => {
  const c = e.target.closest('code');
  if (!c || c.closest('#modal') || !c.closest('#tab-alerts, #tab-ids, #blocks-card')) return;
  const ip = c.textContent.trim();
  if (IP_RE.test(ip)) { e.preventDefault(); ipTimeline(ip); }
});
document.addEventListener('mouseover', (e) => {
  const c = e.target.closest && e.target.closest('code');
  if (c && !c.dataset.ipc && IP_RE.test(c.textContent.trim())) { c.dataset.ipc = '1'; c.classList.add('ip-link'); c.title = L('Tijdlijn van dit IP-adres'); }
});

// ---------------------------------------------------------------- IDS: 🗺️ Aanvalskaart (1.149.0)
// Wie (IP, land) valt wat aan (server), via welke dienst of poort, en is het tegengehouden. Twee weergaven: stromen
// (bron → dienst → doelwit, met bewegende bolletjes in de richting van het verkeer) en een wereldkaart. Uitgaand
// verkeer (een apparaat van jou dat naar buiten belt) is paars en loopt de andere kant op.
const AM_CAT = { ssh: () => 'SSH', web: () => L('Webdiensten'), decoy: () => L('Honeypot'), net: () => 'Suricata', out: () => L('Uitgaand'), other: () => L('Overig') };
const AM_IC = {
  server: '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01"/>',
  network: '<rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 16.5h.01M11 16.5h.01M12 13V8M8.5 6.5a5 5 0 0 1 7 0M6 4a8.5 8.5 0 0 1 12 0"/>',
  storage: '<ellipse cx="12" cy="5.5" rx="8" ry="2.5"/><path d="M4 5.5v13c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5v-13M4 12c0 1.4 3.6 2.5 8 2.5s8-1.1 8-2.5"/>',
  camera: '<path d="M3 7h12l3 3v4l-3 3H3z"/><circle cx="9" cy="12" r="2.5"/><path d="M18 10l3-2v8l-3-2"/>',
  smarthome: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
  power: '<rect x="3" y="7" width="16" height="10" rx="2"/><path d="M21 10v4M8 12h6"/>',
  other: '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M4 10h16"/>',
  home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
  shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  stop: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
};
const amSvg = (k, cls = 'am-ic') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true">${AM_IC[k] || AM_IC.other}</svg>`;
const amKindIc = (h) => {
  const sh = (state && state.hosts || []).find((x) => +x.id === +h.id);
  const k = hostKind(sh || h) || (sh && isHa(sh) ? 'smarthome' : 'server');
  return KIND_GROUP[k] || 'other';
};
const amN = (n) => (+n || 0).toLocaleString(LOCALE);
function amCcName(cc) {
  if (cc === 'LAN') return L('Eigen netwerk');
  if (!cc || cc === '??') return L('Onbekend land');
  try { return new Intl.DisplayNames([LOCALE], { type: 'region' }).of(cc) || cc; } catch (e) { return cc; }
}
let amData = null, amWorld = null, amRO = null;
/** 1.153.0: venster van de aanvalskaart: nu (15 minuten), een uur, een dag, een week of een maand. Hoe korter, hoe vaker bijwerken. */
const AM_WIN = { '15m': { mins: 15, days: 1, refresh: 10 }, '1h': { mins: 60, days: 1, refresh: 10 }, '24h': { days: 1, refresh: 20 }, '7d': { days: 7, refresh: 60 }, '30d': { days: 30, refresh: 60 } };
const AM_WIN_LBL = { '15m': () => L('laatste 15 minuten'), '1h': () => L('laatste uur'), '24h': () => L('laatste 24 uur'), '7d': () => L('laatste 7 dagen'), '30d': () => L('laatste 30 dagen') };
const amOpt = { host: '', cc: '', cat: '', dir: '', view: (() => { try { return localStorage.getItem('rzmon-am-view') || 'flow'; } catch (e) { return 'flow'; } })(),
  win: (() => { try { const w = localStorage.getItem('rzmon-am-win'); return AM_WIN[w] ? w : '15m'; } catch (e) { return '15m'; } })() };
amOpt.days = AM_WIN[amOpt.win].days;

async function amLoadWorld() {
  if (amWorld) return amWorld;
  const v = ((document.querySelector('script[src*="app.js"]') || {}).src || '').split('v=')[1] || '';
  const r = await fetch(`assets/world.json?v=${encodeURIComponent(v)}`, { credentials: 'same-origin' });
  if (!r.ok) throw new Error(L('Wereldkaart niet gevonden'));
  amWorld = await r.json();
  return amWorld;
}

let afTimer = null, afBusy = false, afAgain = false, afLoadedAt = 0;
async function loadAttackMap() {
  const box = $('#am-body');
  if (!box) return;
  if (afBusy) { afAgain = true; return; }   // 1.224.4: een wijziging tijdens het laden niet kwijtraken: straks nog een keer
  if (!amData) box.innerHTML = `<p class="muted">${L('Laden…')}</p>`;
  const w = AM_WIN[amOpt.win] || AM_WIN['15m'];
  amOpt.days = w.days;
  const q = { days: w.days };
  if (w.mins) q.mins = w.mins;
  ['host', 'cc', 'cat', 'dir'].forEach((k) => { if (amOpt[k]) q[k] = amOpt[k]; });
  afBusy = true;
  try {
    const [d] = await Promise.all([api('ids_attack_flow', null, q), amOpt.view === 'world' ? amLoadWorld() : null]);   // 1.152.0: met Attack Flow
    amData = d;
    afLoadedAt = Date.now();
  } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; afBusy = false; afSchedule(); return; }
  afBusy = false;
  if (afAgain) { afAgain = false; return loadAttackMap(); }
  amFilters();
  renderAttackMap();
  afSchedule();
}
/** Live: opnieuw laden na 10 seconden (nu, een uur), 20 seconden (een dag) of een minuut (een week of maand); niet als de pagina niet zichtbaar is */
function afSchedule() {
  clearTimeout(afTimer);
  const w = AM_WIN[amOpt.win] || AM_WIN['15m'];
  afTimer = setTimeout(() => {
    const pane = $('#tab-ids [data-pane="map"]');
    if (document.hidden || idsTab !== 'map' || !pane || pane.hidden || $('#tab-ids').hidden || $('#modal').open) return afSchedule();
    loadAttackMap();
  }, w.refresh * 1000);
}

function amFilters() {
  const f = amData.filters;
  const sel = (id, list, first, cur) => {
    const el = $(id);
    if (!el) return;
    el.innerHTML = `<option value="">${esc(first)}</option>` + list.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(cur) ? 'selected' : ''}>${esc(l)}</option>`).join('');
  };
  sel('#am-host', f.hosts.map((h) => [h.id, h.name]), L('Alle servers'), amOpt.host);
  sel('#am-cc', f.ccs.map((c) => [c, amCcName(c)]).sort((a, b) => a[1].localeCompare(b[1])), L('Alle landen'), amOpt.cc);
  sel('#am-cat', Object.entries(AM_CAT).map(([k, fn]) => [k, fn()]), L('Alle soorten'), amOpt.cat);
  $$('#am-win .seg-btn').forEach((b) => b.classList.toggle('active', b.dataset.amw === amOpt.win));
  $$('#am-dir .seg-btn').forEach((b) => b.classList.toggle('active', b.dataset.amdir === amOpt.dir));
  $$('#am-view .seg-btn').forEach((b) => b.classList.toggle('active', b.dataset.amv === amOpt.view));
}

function initAttackMap() {
  const pane = $('#tab-ids [data-pane="map"]');
  if (!pane || pane.dataset.init) return;
  pane.dataset.init = '1';
  $$('#am-win .seg-btn').forEach((b) => (b.onclick = () => {
    amOpt.win = b.dataset.amw;
    try { localStorage.setItem('rzmon-am-win', amOpt.win); } catch (e) { /* alleen voor nu */ }
    loadAttackMap();
  }));
  $$('#am-dir .seg-btn').forEach((b) => (b.onclick = () => { amOpt.dir = b.dataset.amdir; loadAttackMap(); }));
  $$('#am-view .seg-btn').forEach((b) => (b.onclick = () => {
    amOpt.view = b.dataset.amv;
    try { localStorage.setItem('rzmon-am-view', amOpt.view); } catch (e) { /* alleen voor nu */ }
    loadAttackMap();
  }));
  ['host', 'cc', 'cat'].forEach((k) => ($(`#am-${k}`).onchange = (e) => { amOpt[k] = e.target.value; loadAttackMap(); }));
  // Klikken: een bron of lijn → details; een doelwit → filter op die server; een land → filter op dat land
  $('#am-body').addEventListener('click', (e) => {
    const t = e.target.closest('[data-am-ip], [data-am-line], [data-am-host], [data-am-cc], [data-am-inc], [data-am-clear], [data-af-tl], [data-af-blockall]');
    if (!t) return;
    e.preventDefault();
    if (t.dataset.amClear !== undefined) { Object.assign(amOpt, { host: '', cc: '', cat: '', dir: '' }); loadAttackMap(); }
    else if (t.dataset.afTl !== undefined) afTimelineOpen();
    else if (t.dataset.afBlockall) blockDialog(t.dataset.afBlockall);
    else if (t.dataset.amInc) incidentDialog(+t.dataset.amInc);
    else if (t.dataset.amIp) amDetail(t.dataset.amIp);
    else if (t.dataset.amLine !== undefined) { const l = afFD().lines[+t.dataset.amLine]; if (l) amDetail(l.ip); }
    else if (t.dataset.amHost) { amOpt.host = amOpt.host === t.dataset.amHost ? '' : t.dataset.amHost; loadAttackMap(); }
    else if (t.dataset.amCc) { amOpt.cc = t.dataset.amCc; amOpt.view = 'flow'; loadAttackMap(); }
  });
  let rt = null;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { if (idsTab === 'map' && amOpt.view === 'flow') afDraw(); afTimelineDraw(); }, 120); });
  $('#am-body').addEventListener('change', (e) => {
    const c = e.target.closest('[data-af-opt]');
    if (!c) return;
    afOpt[c.dataset.afOpt] = c.checked;
    afSave();
    renderAttackMap();
  });
  // 1.153.0: de tijdlijn staat in een eigen venster; afspelen en stappen werken daar
  document.addEventListener('click', (e) => {
    const t = e.target.closest('.af-tl [data-af-play], .af-tl [data-af-step], .af-tl [data-af-reset]');
    if (!t) return;
    e.preventDefault();
    if (t.dataset.afPlay !== undefined) afPlayToggle(); else if (t.dataset.afStep !== undefined) afStepNext(); else afStop(true);
  });
  $('#modal').addEventListener('close', () => { if (afPlay) afStop(true); });
  // "… s geleden" bij LIVE
  setInterval(afAgoTick, 1000);
}

function afAgoTick() {
  const el = $('#am-body .af-ago');
  if (!el || !afLoadedAt) return;
  const s = Math.max(0, Math.round((Date.now() - afLoadedAt) / 1000));
  el.textContent = s < 60 ? L('{n} s geleden', { n: s }) : L('{n} min geleden', { n: Math.round(s / 60) });
}

function renderAttackMap() {
  const d = amData, box = $('#am-body');
  if (!d || !box) return;
  if (amOpt.view !== 'world') return renderLiveFlow(d, box);
  const k = d.kpi;
  const filtered = amOpt.host || amOpt.cc || amOpt.cat || amOpt.dir;
  const kpi = (lbl, val, sub, cls = '') => `<div class="kpi ${cls}"><div class="kpi-lbl">${esc(lbl)}</div><div class="kpi-val">${val}</div><div class="kpi-sub">${esc(sub)}</div></div>`;
  const open = k.shown - k.blocked;
  const html = [`<div class="am-kpis">
    ${kpi(L('Aanvallen'), amN(k.attacks), [L('{n} aanvalslijnen', { n: k.lines }), k.crit ? L('{n} kritiek', { n: k.crit }) : ''].filter(Boolean).join(' · '), k.crit ? 'bad' : '')}
    ${kpi(L('Bronnen'), amN(k.sources), [L('uit {n} landen', { n: k.countries }), k.internal ? L('{n} in je eigen netwerk', { n: k.internal }) : '', k.out ? L('{n} uitgaand', { n: k.out }) : ''].filter(Boolean).join(' · '))}
    ${kpi(L('Doelwitten'), amN(k.targets), k.multi ? L('{n} bron(nen) vallen meerdere servers aan', { n: k.multi }) : L('elke bron valt één server aan'))}
    ${kpi(L('Geblokkeerd'), `${amN(k.blocked)}<small> / ${amN(k.shown)}</small>`, open ? L('{n} bron(nen) nog niet tegengehouden', { n: open }) : L('alle getoonde bronnen tegengehouden'), open ? 'bad' : '')}
  </div>`];
  if (!d.lines.length) {
    html.push(afEmpty(filtered));
    box.innerHTML = html.join('');
    return;
  }
  const legend = `<div class="am-legend small"><span><i class="am-sw crit"></i>${L('Kritiek')}</span><span><i class="am-sw warn"></i>${L('Waarschuwing')}</span>
    <span><i class="am-sw out"></i>${L('Uitgaand (van jouw apparaat naar buiten)')}</span>
    <span>${amSvg('stop', 'am-ic sm ok')}${L('Geblokkeerd')}</span><span class="muted">${L('Dikte = aantal pogingen · bolletjes = richting')}</span></div>`;
  html.push(`<section class="card am-card"><div class="am-head"><h2>${L('Herkomst op de wereldkaart')}</h2>${legend}</div>
    ${amWorldHtml(d, false)}
    ${xpHtml(L('De lijnen lopen van het land van herkomst naar je eigen netwerk.'), L('De lijnen lopen van het land van herkomst naar je eigen netwerk ({cc}). Het land is waar het IP-adres geregistreerd staat, niet de exacte plek. Klik op een land voor de aanvallen daarvandaan.', { cc: amCcName(d.home) }))}
  </section>`);
  html.push(`<div class="am-row">${amCountries(d)}${amRecent(d)}</div>`);
  box.innerHTML = html.join('');
}
const afEmpty = (filtered) => `<div class="card am-empty">${amSvg('stop', 'am-ic big')}<div><b>${L('Geen aanvallen in deze periode')}</b>
  <p class="muted small">${filtered ? L('Met deze filters is er niets te zien.') + ` <a href="#" data-am-clear>${L('Filters wissen')}</a>` : L('Er zijn geen incidenten met een IP-adres; zodra iemand je servers aanvalt, zie je het hier.')}</p></div></div>`;

/** 1.153.0: hoeveel beweging (instelling af_motion: 0 = stil, 1 = rustig, 2 = vol); "minder beweging" van het apparaat telt als stil */
function afMotion(d) {
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return 0;
  const own = PREFS.af_motion;   // 1.162.0: per account; anders wat de beheerder instelde
  const m = own !== undefined && own !== '' ? +own : (d && d.live ? +d.live.motion : 2);
  return [0, 1, 2].includes(m) ? m : 2;
}

/** 1.172.0: wat de stroom toont: eerst wie nu (15 min) aanvalt, aangevuld tot tien met de grootste van de laatste 24 uur (of het langere venster) */
const afFD = (d = amData) => (d && d.flow ? { ...d, ...d.flow, live: { ...(d.live || {}), ...d.flow.live } } : d);
const afOld = (s) => !!s && s.live === false;

/** 1.172.0: banner bovenaan als een server geïsoleerd is: groot op de IDS-pagina, smal op Dashboard en Monitoring */
const ISO_PAGES = { ids: 'big', home: 'slim', dashboard: 'slim' };
function renderIsoBanner(tab) {
  const el = $('#iso-banner');
  if (!el || !state) return;
  tab = tab || (($('.tab-panel:not([hidden])') || {}).id || '').replace('tab-', '');
  const list = state.isolated || [], size = ISO_PAGES[tab];
  el.hidden = !list.length || !size;
  if (el.hidden) { el.innerHTML = ''; el.dataset.key = ''; return; }
  const stTxt = (s) => (s === 'active' ? ['ok', L('actief op de server')] : /^(fout|error)/.test(s) ? ['bad', s] : s === 'off' ? ['warn', L('nog niet actief')] : ['warn', s || L('wacht op de agent')]);
  const key = size + '|' + list.map((x) => [x.id, x.until, x.state, x.reason].join('/')).join(',');
  if (el.dataset.key === key) { isoTick(); return; }
  el.dataset.key = key;
  const rows = list.map((x) => {
    const [sc, st] = stTxt(x.state);
    return `<div class="iso-row">
      <div class="iso-who"><b>${esc(x.name)}</b>${x.address ? `<span class="mono iso-mut">${esc(x.address)}</span>` : ''}</div>
      <div class="iso-meta">${x.reason ? `<span><span class="iso-mut">${L('Reden')}:</span> ${esc(x.reason)}</span>` : ''}
        <span><span class="iso-mut">${L('door')}</span> ${esc(x.by || '?')}</span>
        ${x.at ? `<span><span class="iso-mut">${L('sinds')}</span> ${esc(fmtUntil(x.at))}</span>` : ''}
        <span class="iso-st ${sc}">● ${esc(st)}</span></div>
      <div class="iso-cd"><small>${L('vervalt over')}</small><b class="mono" data-iso-cd="${esc(x.until)}">–</b><small>${L('tot {t}', { t: esc(fmtUntil(x.until)) })}</small></div>
      <div class="iso-acts">${size === 'slim' ? `<button type="button" class="iso-btn ghost" data-iso-go>${L('Bekijk')}</button>` : ''}
        <button type="button" class="iso-btn need-admin" data-iso-un="${x.id}" data-iso-name="${esc(x.name)}">🔓 ${L('Opheffen')}</button></div></div>`;
  }).join('');
  el.className = 'iso-banner ' + size;
  el.innerHTML = `<div class="iso-scan" aria-hidden="true"></div><div class="iso-grid" aria-hidden="true"></div>
    <div class="iso-head"><span class="iso-ic" aria-hidden="true"><i></i>🧱</span>
      <div><div class="iso-kick"><i class="af-blink"></i>${esc(list.length === 1 ? L('SERVER IN ISOLATIE') : L('{n} SERVERS IN ISOLATIE', { n: list.length }))}</div>
      ${size === 'big' ? `<div class="iso-mut small">${L('Al het verkeer van en naar deze server is geblokkeerd. Alleen RzSense, je lokale netwerken en het IP-adres van wie isoleerde kunnen er nog bij.')}</div>` : ''}</div></div>
    <div class="iso-rows">${rows}</div>`;
  isoTick();
}
/** Afteller in de banner (elke seconde) */
function isoTick() {
  $$('#iso-banner [data-iso-cd]').forEach((b) => {
    let s = Math.max(0, Math.round((new Date(b.dataset.isoCd.replace(' ', 'T')) - Date.now()) / 1000));
    const d = Math.floor(s / 86400);
    s %= 86400;
    const p = (n) => String(n).padStart(2, '0');
    b.textContent = (d ? d + 'd ' : '') + `${Math.floor(s / 3600)}:${p(Math.floor(s / 60) % 60)}:${p(s % 60)}`;
  });
}
setInterval(() => { const el = document.getElementById('iso-banner'); if (!document.hidden && el && !el.hidden) isoTick(); }, 1000);
/** Isolatie opheffen (met je wachtwoord): vanuit de banner en vanuit Hardening en isoleren */
async function isoLift(hostId, name) {
  const pw = await askConfirm({ title: L('Isolatie van {name} opheffen?', { name }), danger: true, word: 'OPHEFFEN', ok: '🔓 ' + L('Opheffen'),
    text: L('De server is daarna weer gewoon bereikbaar. Doe dit pas als de oorzaak is opgelost.') });
  if (pw === null) return false;
  await api('host_unisolate', { host_id: hostId, confirm: pw });
  toast(L('Isolatie wordt opgeheven'));
  refresh();
  return true;
}
function initIsoBanner() {
  const el = $('#iso-banner');
  if (!el) return;
  el.addEventListener('click', async (e) => {
    const un = e.target.closest('[data-iso-un]'), go = e.target.closest('[data-iso-go]');
    if (go) { showTab('ids'); return; }
    if (!un) return;
    try { await isoLift(+un.dataset.isoUn, un.dataset.isoName); } catch (ex) { toast(ex.message, 'err'); }
  });
}

/** 1.153.0: Live Attack Flow: bovenaan wie nu het hardst aanvalt en wat het IDS deed, dan de stroom, onderaan de ticker en de top 10 */
function renderLiveFlow(d, box) {
  const lv = d.live || {}, m = afMotion(d);
  const filtered = amOpt.host || amOpt.cc || amOpt.cat || amOpt.dir;
  const html = [`<div class="af-livebox m${m}">`, afHero(d, lv)];
  const fd = afFD(d), nOld = (fd.sources || []).filter(afOld).length;
  const pool = AM_WIN_LBL[{ 1: '24h', 7: '7d', 30: '30d' }[fd.days] || '24h']();
  if (!fd.lines.length) html.push(afEmpty(filtered));
  else {
    html.push(`<section class="af-live" aria-label="Live Attack Flow">
      <div class="af-lhead"><h2>Live Attack Flow</h2>
        <span class="af-pill-live" title="${esc(L('Wordt elke {n} seconden bijgewerkt', { n: (AM_WIN[amOpt.win] || AM_WIN['15m']).refresh }))}"><i class="af-blink"></i>LIVE · <span class="af-ago"></span></span>
        <span class="af-win">${esc(d.flow ? L('{n} live · {m} uit de top 10 van de {p}', { n: fd.nlive || 0, m: nOld, p: pool }) : AM_WIN_LBL[amOpt.win]())}</span>
        <div class="af-opts small"><label class="switch small"><input type="checkbox" data-af-opt="inferred" ${afOpt.inferred ? 'checked' : ''}><span></span> ${L('Afgeleide verbanden tonen')}</label>
          <label class="switch small"><input type="checkbox" data-af-opt="group" ${afOpt.group ? 'checked' : ''}><span></span> ${L('Samenvoegen per IP')}</label></div>
        <button type="button" class="af-tlbtn" data-af-tl>${amSvg('clock', 'am-ic sm')} ${L('Tijdlijn')}</button></div>
      <div class="af-legend small">
        <span><svg width="38" height="10" aria-hidden="true"><path d="M2 5 H36" class="af-key-p"/></svg>${L('waargenomen (de deeltjes zijn echte pogingen)')}</span>
        <span><svg width="38" height="10" aria-hidden="true"><path d="M2 5 H36" class="af-key-i"/></svg>${L('afgeleid')}</span>
        <span>${amSvg('shield', 'am-ic sm af-okc')}${L('schild = het IDS houdt het tegen')}</span>
        <span><i class="af-key-hit"></i>${L('doelwit wordt nu geraakt')}</span>
        ${nOld ? `<span><i class="af-key-old"></i>${L('gedimd = top 10, de laatste 15 minuten niet actief')}</span>` : ''}
        <span class="af-mut">${L('hoe sneller de deeltjes, hoe meer pogingen per minuut')}</span></div>
      <div class="af-cols3 small"><span>${L('Aanvaller')}</span><span>${L('Dienst en poort')}</span><span>${L('Doelwit')}</span></div>
      ${afHtml(fd)}
    </section>`);
  }
  html.push(`<div class="af-bottom">${afTicker(lv)}${afTop10(lv)}</div></div>`);
  box.innerHTML = html.join('');
  afAgoTick();
  afDraw();
  // 1.149.2: was het paneel nog verborgen (breedte 0), dan tekenen zodra het zichtbaar wordt of van breedte verandert
  const wrap = $('#am-body .af-wrap');
  if (wrap && window.ResizeObserver) {
    amRO = amRO || new ResizeObserver(() => afDraw());
    amRO.disconnect();
    amRO.observe(wrap);
  }
}

/** Een klein lijntje (sparkline) */
function afSpark(arr, w, h, cls = '') {
  const a = (arr || []).map((x) => +x || 0);
  if (a.length < 2) return '';
  const max = Math.max(1, ...a), step = w / (a.length - 1);
  const pts = a.map((v, i) => `${(i * step).toFixed(1)} ${(h - 2 - (v / max) * (h - 4)).toFixed(1)}`).join(' L');
  return `<svg class="af-spark ${cls}" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="M${pts}"/></svg>`;
}
const afCcBadge = (cc, internal, cls = '') => `<span class="af-cc ${cls}">${internal || cc === 'LAN' ? amSvg('home', 'am-ic sm') : esc(!cc || cc === '??' ? '?' : cc)}</span>`;

function afHero(d, lv) {
  const t = lv.top, b = lv.busy || { active: 0, per_min: 0, bars: [] }, a = lv.actions || { blocks: 0, kills: 0, open: 0 };
  let top;
  if (!t) {
    top = `<div class="af-hcard top calm"><div class="af-hl">${L('Grootste aanvaller nu')}</div>
      <div class="af-calm">${amSvg('shield', 'am-ic big af-okc')}<div><b>${L('Rustig')}</b><br><span class="af-mut">${L('Geen aanvallers in deze periode.')}</span></div></div></div>`;
  } else {
    const ln = t.line || {};
    const what = [ln.svc ? esc(amSvc(ln)) : '', ln.host ? `${L('op')} <b>${esc(ln.host)}</b>` : '', t.ntargets > 1 ? esc(L('+ {n} andere servers', { n: t.ntargets - 1 })) : '',
      t.users && t.users.length ? esc(L('gebruikers {list}', { list: t.users.join(', ') })) : ''].filter(Boolean).join(' · ');
    const hitsBlk = (t.blocks || []).reduce((s, x) => s + (+x.hits || 0), 0);
    let ids, idsCls = 'ok';
    if (ln.outcome && ln.outcome.k === 'login') { ids = `<b>${L('Er is ingelogd!')}</b> <span>${esc(L('Bekijk wat dit IP deed.'))}</span>`; idsCls = 'bad'; }
    else if ((t.blocks || []).length) {
      ids = `<b>${esc(L('IDS: geblokkeerd op {list} om {t}', { list: t.blocks.map((x) => x.host).join(', '), t: afTime(t.blocks[0].at) }))}</b>`
        + (hitsBlk ? ` <span>· ${esc(L('daarna {n} pogingen tegengehouden', { n: amN(hitsBlk) }))}</span>` : '')
        + (t.not_on && t.not_on.length ? ` <span class="af-warnc">· ${esc(L('nog niet op {list}', { list: t.not_on.join(', ') }))}</span>` : '');
    } else if (t.blocked_rz) ids = `<b>${L('Geblokkeerd in RzSense')}</b>`;
    else { ids = `<b>${L('IDS: nog niet geblokkeerd')}</b>`; idsCls = 'warn'; }
    const canBlock = isAdmin() && !t.internal && ((t.not_on && t.not_on.length) || !(t.blocks || []).length);
    top = `<div class="af-hcard top s${t.sev}">
      <div class="af-hl">${t.now ? L('Grootste aanvaller nu') : L('Grootste aanvaller in deze periode')}</div>
      <div class="af-tmain"><button type="button" class="af-tip" data-am-ip="${esc(t.ip)}">${afCcBadge(t.cc, t.internal, 'big')}
          <span class="af-tid"><span class="mono">${esc(t.ip)}</span><span class="af-mut">${what}</span></span></button>
        <div class="af-rate">${t.now ? `<b>${esc(dec(t.per_min))}</b><small>/min</small>` : `<b>${amN(t.hits)}</b><small>${L('pogingen')}</small>`}
          ${afSpark(t.spark, 120, 28, 'crit')}</div></div>
      <div class="af-ids ${idsCls}">${amSvg(idsCls === 'ok' ? 'shield' : 'stop', 'am-ic sm')}<span>${ids}</span>
        ${canBlock ? `<button type="button" class="af-btn" data-af-blockall="${esc(t.ip)}">${L('Overal blokkeren')}</button>` : ''}</div></div>`;
  }
  const bars = b.bars || [], bm = Math.max(1, ...bars);
  const busy = `<div class="af-hcard"><div class="af-hl">${L('Nu bezig')}</div>
    <div class="af-big"><b>${amN(b.active)}</b><span>${esc(b.active === 1 ? L('aanvaller actief') : L('aanvallers actief'))} · <b>${esc(dec(b.per_min))}</b> ${L('pogingen/min')}</span></div>
    <div class="af-hbars" aria-hidden="true">${bars.map((v) => `<i style="height:${Math.max(6, (v / bm) * 100).toFixed(0)}%"></i>`).join('')}</div>
    <span class="af-mut small">${L('laatste 15 minuten, per 2 minuten')}</span></div>`;
  const last = a.last ? (a.last.k === 'block'
    ? `⛔ <b class="mono">${esc(a.last.ip)}</b> ${esc(L('geblokkeerd op {host}', { host: a.last.host }))} · ${esc(afTime(a.last.at))}`
    : `⚙ ${esc(L('proces {p} gestopt op {host}', { p: a.last.what || '?', host: a.last.host }))} · ${esc(afTime(a.last.at))}`) : esc(L('nog geen acties'));
  const acts = `<div class="af-hcard"><div class="af-hl">${L('IDS-acties · laatste uur')}</div>
    <div class="af-acts"><span><b class="af-okc">${amN(a.blocks)}</b>${esc(L('geblokkeerd'))}</span><span><b class="af-cyc">${amN(a.kills)}</b>${esc(a.kills === 1 ? L('proces gestopt') : L('processen gestopt'))}</span>
      <span><b class="${a.open ? 'af-warnc' : ''}">${amN(a.open)}</b>${esc(L('nog open'))}</span></div>
    <span class="af-mut small">${L('Laatste')}: ${last}</span></div>`;
  return `<div class="af-hero">${top}${busy}${acts}</div>`;
}

/** De rijen voor de stroom: links wie van buiten komt (of de bestemming bij uitgaand, of een eigen server als bron), rechts je eigen apparaat */
function afLiveRows(d) {
  const lv = (d.live && d.live.lines) || [];
  return afRows(d).map((r) => ({ ...r, left: r.out ? { k: 'dest', key: 'i' + r.s.ip, s: r.s } : r.from, right: r.t, lv: lv[r.idx] || {} }));
}
const AF_G = { row: 60, gap: 14, src: 0.25, tgt: 0.79 };
const afSeen = new Set();

/** Verdeling van de knopen (in px, behalve x: dat volgt in afDraw uit de breedte) */
function afLayout(d) {
  const rows = afLiveRows(d), g = AF_G;
  const left = [];
  rows.forEach((r) => {
    const key = afOpt.group ? r.left.key : r.left.key + '#' + r.idx;
    let n = left.length && left[left.length - 1].key === key ? left[left.length - 1] : null;
    if (!n) { n = { key, r, rows: [] }; left.push(n); }
    n.rows.push(r);
    r.li = left.length - 1;
  });
  const rmap = new Map();
  rows.forEach((r) => {
    if (!rmap.has(r.right.id)) rmap.set(r.right.id, { id: r.right.id, h: r.right, rows: [], pos: 0 });
    const n = rmap.get(r.right.id);
    n.rows.push(r);
    n.pos += r.li;
  });
  const right = [...rmap.values()].sort((a, b) => a.pos / a.rows.length - b.pos / b.rows.length);
  right.forEach((n, i) => n.rows.forEach((r) => { r.ri = i; }));
  const step = g.row + g.gap, H = Math.max(left.length, right.length) * step - g.gap;
  const off = (n) => (H - (n * step - g.gap)) / 2;
  left.forEach((n, i) => { n.y = off(left.length) + i * step; });
  right.forEach((n, i) => { n.y = off(right.length) + i * step; });
  // Waar een lijn begint en eindigt: verspreid over de hoogte van de knoop
  const spread = (list, n) => { const k = list.length, s = Math.min(12, (g.row - 20) / Math.max(1, k - 1)); list.forEach((r, j) => { r[n] = (j - (k - 1) / 2) * s; }); };
  left.forEach((n) => spread(n.rows, 'ly'));
  right.forEach((n) => spread(n.rows, 'ry'));
  return { rows, left, right, H };
}

function afHtml(d) {
  const lay = afLayout(d), g = AF_G, lv = d.live || {};
  if (!lay.rows.length) return `<p class="af-mut">${L('Alleen afgeleide verbanden in deze periode; zet "Afgeleide verbanden tonen" aan om ze te zien.')}</p>`;
  const hit = new Set(lv.hit || []);
  const html = [];
  lay.left.forEach((n) => {
    const r = n.r, rate = n.rows.reduce((s, x) => s + (+x.lv.rate || 0), 0), hits = n.rows.reduce((s, x) => s + x.l.hits, 0), sev = Math.max(...n.rows.map((x) => x.l.sev));
    const ks = n.rows.map((x) => (x.l.outcome || {}).k);
    const st = ks.includes('login') ? ['login', L('INGELOGD')] : r.left.k === 'ext' && r.left.s.blocked ? ['ok', L('BLOK')] : ks.includes('open') ? ['open', L('OPEN')] : ['inf', L('?')];
    const isTop = lv.top && lv.top.ip === r.l.ip, old = afOld(r.left.s);
    const sub = [old ? L('top 10 · laatst {t}', { t: fmtUntil(String(r.left.s.last)) }) : '', rate > 0 ? `${dec(Math.round(rate * 10) / 10)}/min` : L('{n} pogingen', { n: amN(hits) }), isTop ? L('#1 aanvaller') : '',
      r.left.k === 'dest' ? L('bestemming') : '', r.left.k === 'ext' && r.left.s.bad ? L('bekende aanvaller') : '', n.rows.length > 1 ? L('{n} doelwitten', { n: n.rows.length }) : ''].filter(Boolean).join(' · ');
    let inner;
    if (r.left.k === 'int') {
      const piv = (d.pivots || []).some((p) => p.ip === r.left.s.ip);
      inner = `<span class="af-cc int">${amSvg('network', 'am-ic sm')}</span><span class="af-ntx"><b>${esc(r.left.host.name)}</b><span class="af-mut">${esc(piv ? L('Interne aanvaller · mogelijk gecompromitteerd') : L('Interne bron'))} · <span class="mono">${esc(r.left.s.ip)}</span></span></span>`;
    } else {
      const s = r.left.s;
      inner = `${afCcBadge(s.cc, s.internal)}<span class="af-ntx"><span class="mono">${esc(s.ip)}</span><span class="af-mut">${esc(s.internal ? L('eigen netwerk') + ' · ' + sub : sub)}</span></span>`;
    }
    html.push(`<button type="button" class="af-n af-nl s${sev} ${r.left.k} ${isTop ? 'top' : ''} ${old ? 'old' : ''}" data-am-ip="${esc(r.left.k === 'int' ? r.left.s.ip : r.l.ip)}" style="top:${n.y}px;height:${g.row}px;width:${g.src * 100}%">
      ${inner}<span class="af-st ${st[0]}">${esc(st[1])}</span></button>`);
  });
  lay.right.forEach((n) => {
    const h = n.h, isHit = hit.has(+h.id), out = n.rows.some((r) => r.out), blocked = n.rows.every((r) => (r.l.outcome || {}).k === 'blocked'), old = !isHit && n.rows.every((r) => afOld(r.s));
    const sub = isHit ? L('nu geraakt') : out ? L('belt naar buiten') : blocked ? L('beschermd') : L('{n} bron(nen)', { n: n.rows.length });
    html.push(`<button type="button" class="af-n af-nr ${isHit ? 'hit' : ''} ${out ? 'out' : ''} ${old ? 'old' : ''}" data-am-host="${h.id}" style="top:${n.y}px;height:${g.row}px;left:${g.tgt * 100}%;width:${(1 - g.tgt) * 100}%">
      <span class="af-tic">${amSvg(amKindIc(h), 'am-ic sm')}</span><span class="af-ntx"><b>${esc(h.name)}</b><span class="af-mut"><span class="mono">${esc(h.address || '')}</span> · ${esc(sub)}</span></span></button>`);
  });
  const list = `<details class="af-asl"><summary class="small">${L('Als lijst')}</summary><ul class="am-list">${lay.rows.map((r) => `<li><button type="button" class="linkbtn" data-am-ip="${esc(r.l.ip)}">
      ${esc(r.left.k === 'int' ? r.left.host.name : r.s.ip)} ${r.out ? '←' : '→'} ${esc(r.right.name)}
      <span class="muted">${esc(amSvc(r.l))} · ${amN(r.l.hits)}× · ${r.l.obs ? L('waargenomen') : L('afgeleid')} · ${esc({ blocked: L('Geblokkeerd'), login: L('Ingelogd'), open: L('Niet geblokkeerd'), unconfirmed: L('Niet bevestigd') }[(r.l.outcome || {}).k] || '')}</span></button></li>`).join('')}</ul></details>`;
  return `<div class="af-wrap" style="height:${lay.H}px"><svg class="af-svg" aria-hidden="true"></svg><div class="af-lbls"></div>${html.join('')}</div>
    ${d.rest.n ? `<p class="af-mut small af-rest">${L('+ {n} kleinere aanvallers met samen {h} pogingen · zie de top 10 hieronder', { n: d.rest.n, h: amN(d.rest.hits) })}</p>` : ''}${list}`;
}
const afTime = (s) => String(s || '').slice(11, 16);

/** De lijnen met deeltjes, schilden en labels, op de echte breedte (na het tekenen en als het venster van breedte verandert) */
function afDraw() {
  const wrap = $('#am-body .af-wrap');
  if (!wrap || !amData) return;
  const W = wrap.clientWidth, H = wrap.clientHeight, g = AF_G;
  if (!W) return;
  const fd = afFD();
  const lay = afLayout(fd), maxH = Math.max(1, ...lay.rows.map((r) => r.l.hits)), m = afMotion(amData);
  const xL = g.src * W, xR = g.tgt * W, mid = (xL + xR) / 2, xS = xR - 30;
  const now = performance.now() / 1000;
  const svg = [`<defs><filter id="afGlow" x="-20%" y="-60%" width="140%" height="220%"><feGaussianBlur stdDeviation="2.6" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>`];
  const lbl = [], boxes = [];
  const bez = (t, x1, y1, x2, y2) => { const u = 1 - t, mx = (x1 + x2) / 2; return [u * u * u * x1 + 3 * mx * t * u * u + 3 * mx * t * t * u + x2 * t * t * t, y1 + (y2 - y1) * (3 * t * t - 2 * t * t * t)]; };
  lay.rows.forEach((r) => {
    const l = r.l, y1 = lay.left[r.li].y + g.row / 2 + r.ly, y2 = lay.right[r.ri].y + g.row / 2 + r.ry;
    const blocked = (l.outcome || {}).k === 'blocked' && !r.out;
    const cls = `${r.out || r.left.k === 'int' ? 'out' : l.sev >= 2 ? 'crit' : 'warn'}${l.obs ? '' : ' inf'}${afOld(r.s) ? ' old' : ''}`;
    const w = 1.6 + 4 * Math.sqrt(l.hits / maxH);
    const curve = `M${xL.toFixed(1)} ${y1.toFixed(1)} C${mid.toFixed(1)} ${y1.toFixed(1)} ${mid.toFixed(1)} ${y2.toFixed(1)} ${xS.toFixed(1)} ${y2.toFixed(1)}`;
    const full = `${curve} H${xR.toFixed(1)}`;
    // Uitgaand: de deeltjes lopen van je eigen apparaat (rechts) naar buiten (links)
    const rev = `M${xR.toFixed(1)} ${y2.toFixed(1)} H${xS.toFixed(1)} C${mid.toFixed(1)} ${y2.toFixed(1)} ${mid.toFixed(1)} ${y1.toFixed(1)} ${xL.toFixed(1)} ${y1.toFixed(1)}`;
    const key = `${l.ip}|${l.host_id}|${l.port}|${l.dir}`;
    const fresh = r.lv.fresh && !afSeen.has(key) && m > 0;
    afSeen.add(key);
    const parts = [`<path class="af-base" d="${full}" stroke-width="${(w + 3).toFixed(1)}"/>`];
    if (l.obs && (r.lv.recent || m === 0)) {
      const rate = +r.lv.rate || 0;
      let dur = rate >= 6 ? 0.5 : rate >= 2 ? 0.85 : rate > 0 ? 1.4 : 2.6;
      if (m === 1) dur *= 2;
      parts.push(`<path class="af-p" d="${r.out ? rev : blocked ? curve : full}" stroke-width="${(w + 1.5).toFixed(1)}" style="animation-duration:${dur}s;animation-delay:-${(now % dur).toFixed(2)}s"/>`);
    } else if (l.obs) parts.push(`<path class="af-core" d="${full}" stroke-width="${Math.max(1.2, w / 2).toFixed(1)}"/>`);
    else parts.push(`<path class="af-core inf" d="${full}"/>`);
    if (fresh) parts.push(`<path class="af-draw" d="${full}" stroke-width="${(w + 1).toFixed(1)}"/>`);
    if (blocked) {
      parts.push(`<g class="af-shield" transform="translate(${(xS + 13).toFixed(1)} ${y2.toFixed(1)})"><circle class="af-bu" r="7"/><circle class="af-sh" r="9"/><path d="M-3.5 -3.5 l7 7 M3.5 -3.5 l-7 7"/></g>`);
    }
    const title = esc(`${r.left.k === 'int' ? r.left.host.name : r.s.ip} ${r.out ? '←' : '→'} ${r.right.name} · ${amSvc(l)} · ${amN(l.hits)}× · ${l.obs ? L('waargenomen') : L('afgeleid')}`);
    parts.push(`<path class="am-hit" d="${full}" data-am-line="${r.idx}"><title>${title}</title></path>`);
    svg.push(`<g class="af-l ${cls}">${parts.join('')}</g>`);
    // Label met dienst en poort op de lijn; schuift op over de lijn als het op een ander label zou vallen
    const txt = [l.svc || L('dienst onbekend'), l.port ? `${(l.proto || 'tcp').toUpperCase()}/${l.port}` : '', `${amN(l.hits)}×`].filter(Boolean).join(' · ');
    const bw = txt.length * 6.4 + 22, bh = 30;
    let pos = null;
    for (const t of [0.42, 0.3, 0.56, 0.22, 0.66, 0.36, 0.5]) {
      const [px, py] = bez(t, xL, y1, xS, y2);
      const bx = { x: px - bw / 2, y: py - bh / 2, w: bw, h: bh };
      if (!boxes.some((o) => bx.x < o.x + o.w && o.x < bx.x + bx.w && bx.y < o.y + o.h && o.y < bx.y + bx.h)) { pos = bx; break; }
      pos = pos || bx;
    }
    boxes.push(pos);
    lbl.push(`<button type="button" class="af-pl ${cls} ${fresh || (r.lv.fresh && m === 0) ? 'new' : ''}" data-am-line="${r.idx}" style="left:${(pos.x + bw / 2).toFixed(0)}px;top:${(pos.y + bh / 2).toFixed(0)}px">
      <b>${esc(txt)}</b><span>${r.out ? '↩ ' + L('uitgaand') : l.obs ? esc((AF_VIA[l.via] || (() => l.via))()) : L('afgeleid')}${r.lv.fresh ? ' · ' + L('NIEUW') : ''}</span></button>`);
  });
  // Afgeleid: een server die eerst werd aangevallen en daarna zelf een bron is
  if (afOpt.inferred) {
    (fd.pivots || []).forEach((p) => {
      const ti = lay.right.findIndex((n) => +n.id === +p.host_id);
      const fi = lay.left.findIndex((n) => n.r.left.k === 'int' && n.r.left.s.ip === p.ip);
      if (ti < 0 || fi < 0) return;
      const x1 = xR + 24, y1 = lay.right[ti].y + g.row, x2 = xL - 24, y2 = lay.left[fi].y;
      svg.push(`<path class="af-pivot" d="M${x1} ${y1} C${x1} ${y1 + 60} ${x2} ${y2 - 60} ${x2} ${y2}"/>`);
      lbl.push(`<span class="af-via inf piv" style="left:${((x1 + x2) / 2).toFixed(0)}px;top:${((y1 + y2) / 2 - 8).toFixed(0)}px">${L('afgeleid · zelfde server, {m} min later', { m: Math.max(0, p.minutes) })}</span>`);
    });
  }
  const el = $('.af-svg', wrap);
  el.setAttribute('viewBox', `0 0 ${W} ${H}`);
  el.setAttribute('width', W);
  el.setAttribute('height', H);
  el.innerHTML = svg.join('');
  $('.af-lbls', wrap).innerHTML = lbl.join('');
}

/** 1.153.0: wat er net gebeurde, als lopende band (stilstaan met de muis erop) */
function afTicker(lv) {
  const rows = (lv.tick || []).map((e) => {
    const t = esc(String(e.at || '').slice(11, 19));
    const ip = e.ip ? `<span class="mono">${esc(e.ip)}</span>` : '';
    const [tag, cls, txt] = {
      try: ['✕ SSH', 'crit', `${ip} → ${esc(e.host)} · ${esc(e.u || '?')}`],
      login: ['◆ LOGIN', 'ok', `${esc(L('{u} op {host}', { u: e.u || '?', host: e.host }))} ${L('vanaf')} ${ip}${e.method ? ' · ' + esc(e.method) : ''}`],
      block: ['⛔ IDS', 'ok', `${ip} ${esc(L('geblokkeerd op {host}', { host: e.host }))}${e.what ? ' · ' + esc(e.what) : ''}`],
      out: ['↩ ' + L('UIT'), 'out', `${esc(e.what || '')} · ${esc(e.host)}`],
      inc: [e.sev >= 2 ? '! ' + L('KRITIEK') : '! IDS', e.sev >= 2 ? 'crit' : 'warn', `${esc(e.what || '')} · ${esc(e.host)}`],
    }[e.k] || ['·', '', esc(e.what || '')];
    return (ti) => `<button type="button" class="af-trow" ${e.ip ? `data-am-ip="${esc(e.ip)}"` : ''} ${ti}><span class="af-tt">${t}</span><span class="af-tag ${cls}">${esc(tag)}</span><span class="af-tx">${txt}</span></button>`;
  });
  const roll = rows.length > 8;
  const body = rows.length
    ? `<div class="af-tick-win ${roll ? 'roll' : ''}"><div class="af-tick-in" style="animation-duration:${(rows.length * 2.4).toFixed(0)}s"><div>${rows.map((f) => f('')).join('')}</div>${roll ? `<div aria-hidden="true">${rows.map((f) => f('tabindex="-1"')).join('')}</div>` : ''}</div></div>`
    : `<p class="muted small">${L('Vandaag nog niets gebeurd.')}</p>`;
  return `<section class="card af-tick"><div class="am-head"><h2>${L('Live gebeurtenissen')}</h2><i class="af-blink"></i>${roll ? `<span class="muted small">${L('stilstaan: zet de muis erop')}</span>` : ''}</div>${body}</section>`;
}

/** 1.153.0: de tien grootste aanvallers van de periode, eenvoudig als tabel */
function afTop10(lv) {
  const list = lv.top10 || [];
  const st = { blocked: ['ok', L('Geblokkeerd')], login: ['bad', L('Ingelogd')], open: ['warn', L('Open')], unconfirmed: ['muted', L('Niet bevestigd')] };
  const rows = list.map((x, i) => {
    const s = st[x.state] || st.unconfirmed;
    const tgt = x.dir === 'o' ? `${esc(x.targets.join(', '))} → ${L('uit')}${x.port ? ' · ' + x.port : ''}` : `${esc(x.targets.join(', '))}${x.svc ? ' · ' + esc(x.svc) : ''}`;
    return `<tr data-am-ip="${esc(x.ip)}"><td class="muted">${i + 1}</td><td><button type="button" class="linkbtn" data-am-ip="${esc(x.ip)}">${afCcBadge(x.cc, x.internal, 'sm')} <span class="mono">${esc(x.ip)}</span></button></td>
      <td>${tgt}</td><td class="num"><b>${amN(x.hits)}</b>${x.up ? ` <span class="af-up" title="${esc(L('nu bezig'))}">▲</span>` : ''}</td><td>${afSpark(x.trend, 64, 16, x.up ? 'crit' : 'muted')}</td>
      <td><span class="af-bdg ${s[0]}">${esc(s[1])}</span></td></tr>`;
  }).join('');
  return `<section class="card af-top"><div class="am-head"><h2>${L('Top 10 aanvallen')}</h2><span class="muted small">${esc(AM_WIN_LBL[amOpt.win]())} · ${L('op pogingen')}</span></div>
    ${list.length ? `<div class="table-wrap"><table class="small af-top-t"><thead><tr><th>#</th><th>${L('Aanvaller')}</th><th>${L('Doel · dienst')}</th><th class="num">${L('Pogingen')}</th><th>${L('Trend')}</th><th>IDS</th></tr></thead>
      <tbody>${rows}</tbody></table></div>` : `<p class="muted small">${L('Geen aanvallen in deze periode.')}</p>`}</section>`;
}

/** Tijdlijn (1.153.0: in een eigen venster): een baan per bron; pogingen, logins, blokkades en meldingen (een open ring = afgeleid) */
function afTimelineOpen() {
  if (!amData) return;
  afStop(true);
  modal({ title: L('Tijdlijn') + ' · ' + AM_WIN_LBL[amOpt.win](), wide: true, body: afTimeline(amData) || `<p class="muted">${L('Geen gebeurtenissen in deze periode.')}</p>` });
  afTimelineDraw();
}

/** 1.152.0: Attack Flow — aanvaller → doelwit → dienst → afloop. Doorgetrokken lijn = waargenomen verkeer (auth.log,
 * de log van een webdienst, Suricata, de firewall of de agent); stippellijn = een verband dat RzSense uit meldingen afleidt.
 * Een eigen server die zelf een bron wordt, staat links als interne aanvaller, met een lijn naar waar hij werd aangevallen. */
const amSvc = (l) => [l.svc || L('dienst onbekend'), l.port ? (l.proto ? l.proto.toUpperCase() + ' ' : '') + l.port : ''].filter(Boolean).join(' · ');
const AF_VIA = { 'auth.log': () => 'auth.log', weblog: () => L('log van de webdienst'), Suricata: () => 'Suricata', firewall: () => L('firewall'), agent: () => L('agent'),
  keten: () => L('keten'), RzSense: () => L('meldingen') };
let afOpt = (() => { try { return JSON.parse(localStorage.getItem('rzmon-af') || '{}') || {}; } catch (e) { return {}; } })();
afOpt = { inferred: afOpt.inferred !== false, group: afOpt.group !== false };
const afSave = () => { try { localStorage.setItem('rzmon-af', JSON.stringify(afOpt)); } catch (e) { /* alleen voor nu */ } };
let afPlay = null;

/** De rijen: één per lijn, gegroepeerd per bron (eerst van buiten, dan je eigen servers als bron) */
function afRows(d) {
  const sIdx = new Map(d.sources.map((s, i) => [s.ip, i]));
  const rows = d.lines.map((l, idx) => {
    const s = d.sources[sIdx.get(l.ip)] || { ip: l.ip, cc: l.cc, internal: l.internal };
    const t = d.targets.find((x) => x.id === l.host_id) || { id: l.host_id, name: '?', address: '' };
    const out = l.dir === 'o';
    // Wie begint: bij uitgaand je eigen apparaat, anders de bron (een eigen server als hij bekend is)
    const from = out ? { k: 'own', key: 'h' + t.id, host: t } : s.host ? { k: 'int', key: 'h' + s.host.id, host: s.host, s } : { k: 'ext', key: 'i' + s.ip, s };
    const to = out ? { k: 'dest', s } : { k: 'host', host: t };
    return { l, idx, s, t, out, from, to, rank: (from.k === 'int' ? 2 : out ? 1 : 0) * 1e4 + (sIdx.get(l.ip) ?? 999) };
  }).filter((r) => afOpt.inferred || r.l.obs);
  rows.sort((a, b) => a.rank - b.rank || b.l.hits - a.l.hits);
  return rows;
}

/** Tijdlijn: een baan per bron; pogingen, logins, blokkades en meldingen (een open ring = afgeleid). Afspelen laat zien hoe het ging. */
function afTimeline(d) {
  const rows = afRows(d);
  const lanes = [...new Map(rows.map((r) => [r.l.ip, r])).values()].slice(0, 14);
  if (!lanes.length) return '';
  const ev = (d.events || []).filter((e) => lanes.some((r) => r.l.ip === e.ip));
  const ts = ev.map((e) => Date.parse(e.at.replace(' ', 'T'))).filter((n) => !Number.isNaN(n));
  const t1 = Date.parse(d.now.replace(' ', 'T'));
  const since = Date.parse(d.since.replace(' ', 'T'));   // 1.153.0: niet vóór het begin van de gekozen periode
  const t0 = ts.length ? Math.max(since, Math.min(...ts) - 60000) : since;
  afPlay = null;
  return `<section class="af-tl" aria-label="${esc(L('Tijdlijn'))}" data-t0="${t0}" data-t1="${t1}">
    <div class="am-head"><h2>${L('Tijdlijn')}</h2><span class="muted small" id="af-tl-range"></span>
      <div class="af-tl-btns"><button type="button" class="btn small-btn" data-af-play>▶ ${L('Afspelen')}</button><button type="button" class="btn small-btn" data-af-step>⏭ ${L('Stap')}</button>
        <button type="button" class="btn small-btn" data-af-reset hidden>${L('Alles tonen')}</button></div></div>
    <div class="af-tl-body">${lanes.map((r) => `<div class="af-lane"><button type="button" class="linkbtn af-lane-lbl" data-am-ip="${esc(r.l.ip)}">${esc(r.from.k === 'ext' ? r.s.ip : r.from.host.name)}</button>
      <div class="af-lane-track" data-ip="${esc(r.l.ip)}"></div></div>`).join('')}
      <div class="af-axis"></div><div class="af-head" hidden></div></div>
    <p class="muted small">${L('Punt = poging · ruit = login · groene streep = blokkade · ring = melding (open ring = afgeleid). Afspelen loopt van de eerste gebeurtenis tot nu.')}</p></section>`;
}

function afTimelineDraw() {
  const box = $('.af-tl');
  if (!box || !amData) return;
  const t0 = +box.dataset.t0, t1 = +box.dataset.t1, span = Math.max(60000, t1 - t0);
  const pos = (at) => Math.max(0, Math.min(100, ((Date.parse(at.replace(' ', 'T')) - t0) / span) * 100));
  const sevOf = new Map(amData.sources.map((s) => [s.ip, s.sev]));
  $$('.af-lane-track', box).forEach((tr) => {
    const ip = tr.dataset.ip;
    tr.innerHTML = (amData.events || []).filter((e) => e.ip === ip).map((e) => {
      const p = pos(e.at).toFixed(2);
      const title = esc(`${fmtDateTime(e.at)} · ${{ try: L('mislukte poging'), login: L('login'), block: L('geblokkeerd'), inc: L('melding') }[e.k] || e.k}${e.n > 1 ? ' ×' + e.n : ''}${e.u ? ' · ' + e.u : ''}`);
      return `<i class="af-ev k-${e.k} ${e.k === 'try' && (sevOf.get(ip) || 0) >= 2 ? 'crit' : ''} ${e.k === 'inc' && e.obs === false ? 'inf' : ''}" data-at="${esc(e.at)}" style="left:${p}%" title="${title}"></i>`;
    }).join('');
  });
  const fmt = (t) => { const dt = new Date(t); return span > 86400000 * 1.5 ? dt.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) + ' ' + dt.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) : dt.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }); };
  $('.af-axis', box).innerHTML = [0, 0.25, 0.5, 0.75, 1].map((f) => `<span style="left:${f * 100}%">${esc(fmt(t0 + f * span))}</span>`).join('');
  $('#af-tl-range').textContent = `${fmt(t0)} – ${fmt(t1)}`;
}

/** Afspelen: alles na tijdstip t is (nog) vaag; de knopen en lijnen verschijnen op het moment van hun eerste melding */
function afApplyTime(t) {
  const box = $('.af-tl');
  if (!box) return;
  const t0 = +box.dataset.t0, t1 = +box.dataset.t1;
  const later = (s) => Date.parse(String(s).replace(' ', 'T')) > t;
  $$('.af-ev', box).forEach((el) => el.classList.toggle('af-future', later(el.dataset.at)));
  const head = $('.af-head', box);
  head.hidden = false;
  head.style.left = `calc(var(--af-lbl) + (100% - var(--af-lbl)) * ${((t - t0) / Math.max(1, t1 - t0)).toFixed(4)})`;
  head.dataset.t = new Date(t).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  $('[data-af-reset]', box).hidden = false;
}
function afStop(reset) {
  if (afPlay && afPlay.raf) cancelAnimationFrame(afPlay.raf);
  const btn = $('.af-tl [data-af-play]');
  if (btn) btn.textContent = '▶ ' + L('Afspelen');
  if (reset) {
    afPlay = null;
    $$('.af-tl .af-future').forEach((el) => el.classList.remove('af-future'));
    const box = $('.af-tl');
    if (box) { $('.af-head', box).hidden = true; $('[data-af-reset]', box).hidden = true; }
  } else if (afPlay) afPlay.raf = null;
}
function afPlayToggle() {
  const box = $('.af-tl');
  if (!box) return;
  if (afPlay && afPlay.raf) return afStop(false);
  const t0 = +box.dataset.t0, t1 = +box.dataset.t1;
  const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const start = afPlay && afPlay.t < t1 ? afPlay.t : t0;
  afPlay = { t: start, raf: null };
  $('[data-af-play]', box).textContent = '⏸ ' + L('Pauze');
  const dur = reduce ? 1 : 12000, began = performance.now(), from = start;
  const tick = (now) => {
    const f = Math.min(1, (now - began) / dur);
    afPlay.t = from + (t1 - from) * f;
    afApplyTime(afPlay.t);
    if (f < 1) afPlay.raf = requestAnimationFrame(tick); else afStop(false);
  };
  afPlay.raf = requestAnimationFrame(tick);
}
function afStepNext() {
  const box = $('.af-tl');
  if (!box || !amData) return;
  afStop(false);
  const cur = afPlay ? afPlay.t : +box.dataset.t0;
  const next = (amData.events || []).map((e) => Date.parse(e.at.replace(' ', 'T'))).filter((t) => t > cur + 1000).sort((a, b) => a - b)[0];
  afPlay = { t: next || +box.dataset.t1, raf: null };
  afApplyTime(afPlay.t);
}

// ---------------------------------------------------------------- Popup per IP (1.152.0): in het kort, gebeurtenissen, tijdlijn, IP-adres
const AF_ROWK = { fail: () => L('Pogingen'), login: () => L('Logins'), ev: () => L('Bewijs'), block: () => L('Acties') };
function afSummary(d, lines) {
  const hosts = [...new Set(d.rows.filter((r) => ['fail', 'login', 'ev', 'inc'].includes(r.k)).map((r) => r.host))];
  const who = d.internal ? L('Een apparaat in je eigen netwerk') : (d.bad ? L('Een bekende aanvaller') : L('Een adres')) + (d.cc ? ' ' + L('uit {land}', { land: amCcName(d.cc) }) : '');
  const web = d.rows.filter((r) => r.k === 'ev' && r.via === 'weblog').length, sur = d.rows.filter((r) => r.k === 'ev' && r.via === 'Suricata').length;
  const parts = [];
  if (d.counts.fails) parts.push(L('probeerde {n} keer in te loggen', { n: amN(d.counts.fails) }));
  if (web) parts.push(L('deed {n} verdachte webverzoeken', { n: web }));
  if (sur) parts.push(L('werd {n} keer gezien door Suricata', { n: sur }));
  if (!parts.length && lines && lines.length) parts.push(L('viel aan via {svc}', { svc: [...new Set(lines.map(amSvc))].join(', ') }));
  if (!parts.length) parts.push(L('kwam voor in {n} melding(en)', { n: d.counts.incidents }));
  const on = hosts.length ? ' ' + L('op {list}', { list: hosts.slice(0, 4).join(', ') + (hosts.length > 4 ? ' …' : '') }) : '';
  const login = d.counts.logins ? ` <b class="bad">${L('Er is {n} keer ingelogd.', { n: d.counts.logins })}</b>` : d.counts.fails ? ` <b>${L('Er is niet ingelogd.')}</b>` : '';
  const blk = d.blocks.length ? ' ' + L('Geblokkeerd op {list} sinds {t}.', { list: d.blocks.map((b) => b.host).join(', '), t: afTime(d.blocks[d.blocks.length - 1].at) })
    + (d.counts.stopped ? ' ' + L('Daarna zijn nog {n} pogingen tegengehouden.', { n: amN(d.counts.stopped) }) : '')
    : d.blocked_rz ? ' ' + L('Geblokkeerd in RzSense.') : d.internal ? '' : ' ' + L('Niet geblokkeerd.');
  return `${esc(who)} ${esc(parts.join(', '))}${esc(on)}.${login}${esc(blk)}`;
}
/** Pogingen per tijdvak, met de blokkade erin */
function afChart(d) {
  const pts = d.rows.filter((r) => r.k === 'fail' || r.k === 'ev').map((r) => ({ t: Date.parse(r.at.replace(' ', 'T')), n: r.n || 1 }));
  if (!pts.length) return '';
  const blocks = d.rows.filter((r) => r.k === 'block').map((r) => Date.parse(r.at.replace(' ', 'T')));
  const tMin = Math.min(...pts.map((p) => p.t), ...blocks), tMax = Math.max(...pts.map((p) => p.t), ...blocks);
  const sizes = [60, 300, 900, 3600, 21600, 86400].map((s) => s * 1000);
  const size = sizes.find((s) => (tMax - tMin) / s <= 36) || sizes[sizes.length - 1];
  const n = Math.max(1, Math.floor((tMax - tMin) / size) + 1);
  const b = new Array(n).fill(0);
  pts.forEach((p) => { b[Math.min(n - 1, Math.floor((p.t - tMin) / size))] += p.n; });
  const max = Math.max(...b);
  const blockAt = new Set(blocks.map((t) => Math.min(n - 1, Math.floor((t - tMin) / size))));
  const label = { 60000: L('minuut'), 300000: L('5 minuten'), 900000: L('kwartier'), 3600000: L('uur'), 21600000: L('6 uur'), 86400000: L('dag') }[size];
  return `<div class="af-box"><b>${L('Pogingen per {p}', { p: label })}</b>
    <div class="af-bars">${b.map((v, i) => `${blockAt.has(i) ? '<i class="af-bar-blk" title="' + esc(L('blokkade')) + '"></i>' : ''}<i class="af-bar" style="height:${Math.max(v ? 6 : 2, Math.round(64 * v / max))}px" title="${v}"></i>`).join('')}</div>
    <span class="muted small">${esc(new Date(tMin).toLocaleString(LOCALE, { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' }))} – ${esc(new Date(tMax).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }))}
      · ${L('groene streep = blokkade')}${d.counts.stopped ? ' · ' + L('daarna {n} tegengehouden door de firewall', { n: amN(d.counts.stopped) }) : ''}</span></div>`;
}
function afEventsTable(rows, max = 0) {
  const st = { fail: ['bad', () => L('mislukt')], login: ['bad', () => L('ingelogd')], stopped: ['good', () => L('tegengehouden')], seen: ['', () => L('gezien')],
    detect: ['warn-txt', () => L('melding')], action: ['good', () => L('actie')], info: ['muted', () => L('info')] };
  const list = max ? rows.slice(0, max) : rows;
  return `<div class="table-scroll"><table class="am-table af-ev-t"><thead><tr><th>${L('Tijd')}</th><th>${L('Doel')}</th><th>${L('Poort')}</th><th>${L('Wat')}</th><th>${L('Bron')}</th><th>${L('Status')}</th></tr></thead><tbody>
    ${list.map((r) => { const [c, t] = st[r.st] || ['', () => r.st]; return `<tr><td class="mono nowrap">${esc(fmtDateTime(r.at))}</td><td>${esc(r.host || '')}</td>
      <td class="mono nowrap">${r.sport ? ':' + r.sport + ' → ' : ''}${r.dport ? esc((r.proto || 'TCP') + '/' + r.dport) : '–'}</td>
      <td>${r.id ? `<button type="button" class="linkbtn" data-am-inc="${r.id}">${esc(r.what)}</button>` : esc(r.what)}${r.n > 1 ? ` <span class="muted">×${r.n}</span>` : ''}</td>
      <td class="nowrap">${esc((AF_VIA[r.via] || (() => r.via))())}</td><td class="${c} nowrap">${esc(t())}</td></tr>`; }).join('')}</tbody></table></div>`;
}
function afBlockBox(d) {
  return `<div class="af-box af-blk"><div><span class="muted small">${L('Blokkade')}</span><br>${d.blocks.length ? d.blocks.map((b) => `<span class="good">${amSvg('stop', 'am-ic sm')} ${esc(b.host)}</span> <span class="muted small">${b.until ? esc(L('tot {t}', { t: fmtDateTime(b.until) })) : esc(L('blijvend'))}${b.hits ? ' · ' + esc(L('{n} tegengehouden', { n: amN(b.hits) })) : ''}</span>`).join('<br>')
      : d.blocked_rz ? `<span class="good">${L('geblokkeerd in RzSense')}</span>` : `<span class="warn-txt">${L('niet geblokkeerd')}</span>`}</div>
    ${d.not_blocked_on.length ? `<div><span class="muted small">${L('Nog niet geblokkeerd op')}</span><br>${esc(d.not_blocked_on.slice(0, 8).join(', '))}${d.not_blocked_on.length > 8 ? ' …' : ''}</div>` : ''}
    <div><span class="muted small">${L('Reputatie')}</span><br>${d.bad ? `<span class="bad">☠️ ${esc(L('bekende aanvaller ({list})', { list: d.bad }))}</span>` : d.internal ? esc(L('je eigen netwerk')) : '–'}</div></div>`;
}
function afExplain(x) {
  if (!x) return '';
  return `<div class="af-box"><b>${L('Waarom dit een aanval is')}</b>${explainHtml(x)}</div>`;
}

async function amDetail(ip) {
  const days = (typeof amOpt !== 'undefined' && amOpt.days) || 7;
  let d, tl = null;
  try { [d, tl] = await Promise.all([api('ids_ip_events', null, { ip, days: Math.max(days, 1) }), api('ip_timeline', null, { ip }).catch(() => null)]); } catch (ex) { return toast(ex.message, 'err'); }
  const fd = afFD(), inWin = amData ? amData.lines.filter((l) => l.ip === ip) : [];
  const lines = inWin.length || !fd ? inWin : fd.lines.filter((l) => l.ip === ip);   // 1.172.0: een aanvaller uit de top 10 van 24 uur
  const sev = Math.max(0, ...d.incidents.map((i) => i.sev));
  const obs = d.incidents.some((i) => i.observed) || d.rows.some((r) => r.k === 'fail' || r.k === 'login' || r.k === 'ev');
  const bands = lines.map((l) => {
    const t = [...(amData.targets || []), ...((fd && fd.targets) || [])].find((x) => x.id === l.host_id) || { name: '?', address: '' };
    const cls = l.dir === 'o' ? 'out' : l.sev >= 2 ? 'crit' : 'warn', path = l.dir === 'o' ? 'M140 10 H4' : 'M4 10 H140';
    return `<div class="am-band"><div class="am-bflow"><div class="am-bside"><span class="am-badge s${l.sev}">${d.internal ? amSvg('home') : esc(d.cc || '?')}</span>
        <span><span class="muted small">${L('Aanvaller')}</span><br><span class="mono">${esc(ip)}</span><br><span class="small muted">${esc(amCcName(d.internal ? 'LAN' : d.cc))}</span></span></div>
      <div class="am-bmid"><svg viewBox="0 0 144 20" aria-hidden="true"><g class="am-line ${cls} ${l.obs ? '' : 'inf'}"><path class="am-base" d="${path}" stroke-width="4"/>${l.obs ? `<path class="am-dots" d="${path}" stroke-width="4"/>` : ''}</g></svg>
        <span class="small">${esc(amSvc(l))}</span><span class="muted small">${l.obs ? L('waargenomen') : L('afgeleid')} · ${esc((AF_VIA[l.via] || (() => l.via || ''))())}</span></div>
      <div class="am-bside r"><span><span class="muted small">${L('Doelwit')}</span><br><b>${esc(t.name)}</b><br><span class="small muted mono">${esc(t.address || '')}</span></span><span class="am-tic">${amSvg(amKindIc(t))}</span></div></div></div>`;
  }).join('');
  const kinds = Object.keys(AF_ROWK).filter((k) => d.rows.some((r) => r.k === k));
  const main = d.incidents[0];
  modal({
    title: main ? main.title : ip, wide: true,
    body: `<div class="am-pop af-pop">
      <p class="am-pills">${sev ? `<span class="am-pill s${sev}">${sev >= 2 ? L('Kritiek') : L('Waarschuwing')}</span>` : ''}
        <span class="am-pill ${d.blocks.length || d.blocked_rz ? 'ok' : 'open'}">${d.blocks.length ? esc(L('Geblokkeerd op {list}', { list: d.blocks.map((b) => b.host).join(', ') })) : d.blocked_rz ? L('Geblokkeerd in RzSense') : L('Niet geblokkeerd')}</span>
        <span class="am-pill">${obs ? L('Waargenomen') : L('Afgeleid')}</span>${d.incidents.length > 1 ? `<span class="am-pill">${esc(L('{n} meldingen samengevoegd', { n: d.incidents.length }))}</span>` : ''}
        ${d.internal ? `<span class="am-pill">${L('Eigen netwerk')}</span>` : ''}</p>
      <p class="af-sum"><b>${L('In het kort')}:</b> ${afSummary(d, lines)}</p>
      <div class="seg af-ptabs" role="tablist">${[['ov', L('Overzicht')], ['ev', L('Gebeurtenissen') + ` (${d.rows.length}${d.truncated ? '+' : ''})`], ['tl', L('Tijdlijn van het IP')], ['ip', L('Over het IP-adres')]]
        .map(([k, l], n) => `<button type="button" class="seg-btn ${n ? '' : 'active'}" data-ptab="${k}" role="tab">${esc(l)}</button>`).join('')}</div>
      <div data-ppane="ov">${bands}<div class="af-2col">${afChart(d)}${afExplain(d.explain)}</div>
        ${d.rows.length ? `<h3>${L('Laatste gebeurtenissen')}</h3>${afEventsTable(d.rows, 6)}${d.rows.length > 6 ? `<button type="button" class="linkbtn" data-ptab-go="ev">${L('Alle {n} gebeurtenissen', { n: d.rows.length })}</button>` : ''}` : ''}
        ${afBlockBox(d)}
        ${d.incidents.length ? `<h3>${L('Meldingen')}</h3><ul class="af-incs">${d.incidents.map((i) => `<li><button type="button" class="linkbtn" data-am-inc="${i.id}">${esc(i.title)}</button>
          <span class="muted small">${esc(i.host)} · ${esc(fmtDateTime(i.last))}${i.hits > 1 ? ' · ' + i.hits + '×' : ''} · ${i.observed ? L('waargenomen') : L('afgeleid')}${i.status !== 'open' ? ' · ✔' : ''}</span></li>`).join('')}</ul>` : ''}</div>
      <div data-ppane="ev" hidden><div class="dash-chips af-evf"><button type="button" class="dchip seg-btn on active" data-evf="">${L('Alles')}</button>${kinds.map((k) => `<button type="button" class="dchip seg-btn" data-evf="${k}">${esc(AF_ROWK[k]())}</button>`).join('')}</div>
        <div class="af-evlist">${afEventsTable(d.rows)}</div>${d.truncated ? `<p class="muted small">${L('Alleen de nieuwste 500.')}</p>` : ''}</div>
      <div data-ppane="tl" hidden>${tl ? ipTlHtml(tl) : `<p class="muted small">${L('Geen tijdlijn beschikbaar.')}</p>`}</div>
      <div data-ppane="ip" hidden><div class="am-ipinfo"></div></div></div>`,
    buttons: [
      ...(isAdmin() && !d.internal && !d.blocked_rz ? [{ label: '⛔ ' + L('Overal blokkeren'), cls: 'primary', onClick: () => { $('#modal').close(); blockDialog(ip); } }] : []),
      ...(main ? [{ label: L('Naar incident'), onClick: () => { $('#modal').close(); incidentDialog(main.id); } }] : []),
      { label: L('Sluiten'), onClick: () => $('#modal').close() },
    ],
  });
  const root = $('#modal');
  let ipLoaded = false;
  const showTabP = (k) => {
    $$('[data-ptab]', root).forEach((b) => b.classList.toggle('active', b.dataset.ptab === k));
    $$('[data-ppane]', root).forEach((p) => { p.hidden = p.dataset.ppane !== k; });
    if (k === 'ip' && !ipLoaded) { ipLoaded = true; fillIpInfo($('.am-ipinfo', root), ip); }
  };
  $('#modal-form').onclick = (e) => {
    const t = e.target.closest('[data-ptab], [data-ptab-go], [data-am-inc], [data-evf]');
    if (!t) return;
    e.preventDefault();
    if (t.dataset.ptab) showTabP(t.dataset.ptab);
    else if (t.dataset.ptabGo) showTabP(t.dataset.ptabGo);
    else if (t.dataset.amInc) { root.close(); incidentDialog(+t.dataset.amInc); }
    else if (t.dataset.evf !== undefined) {
      $$('[data-evf]', root).forEach((b) => { b.classList.toggle('active', b === t); b.classList.toggle('on', b === t); });
      const k = t.dataset.evf;
      $('.af-evlist', root).innerHTML = afEventsTable(k ? d.rows.filter((r) => r.k === k || (k === 'block' && r.k === 'unblock') || (k === 'login' && r.k === 'end')) : d.rows);
    }
  };
}

/** In het venster van een IDS-incident (1.152.0): het korte verhaal van het IP, de pogingen, de blokkade en de laatste gebeurtenissen */
async function afIncSummary(box, ip) {
  if (!box) return;
  let d;
  try { d = await api('ids_ip_events', null, { ip, days: 7 }); } catch (e) { box.remove(); return; }
  if (!d.rows.length) { box.remove(); return; }
  box.innerHTML = `<p class="af-sum"><b>${L('In het kort')}:</b> ${afSummary(d, [])}</p>
    <div class="af-2col">${afChart(d)}${afBlockBox(d)}</div>
    <h3 class="set-sub">${L('Laatste gebeurtenissen van dit IP')}</h3>${afEventsTable(d.rows, 5)}
    <p><button type="button" class="btn small-btn" data-af-all>🔎 ${L('Alles over dit IP ({n} gebeurtenissen)', { n: d.rows.length })}</button></p>`;
  $('[data-af-all]', box).onclick = (e) => { e.preventDefault(); e.stopPropagation(); amDetail(ip); };
  $$('[data-am-inc]', box).forEach((b) => (b.onclick = (e) => { e.preventDefault(); e.stopPropagation(); incidentDialog(+b.dataset.amInc); }));
}

/** Wereldkaart: landen gekleurd naar het aantal pogingen (één kleur, licht → donker), bogen naar je eigen netwerk */
function amWorldHtml(d, mini) {
  const w = amWorld;
  if (!w) return `<p class="muted">${L('Laden…')}</p>`;
  const by = new Map(d.countries.map((c) => [c.cc, c]));
  const max = Math.max(1, ...d.countries.filter((c) => c.cc !== 'LAN' && c.cc !== '??').map((c) => c.hits));
  const home = w.c[d.home] ? d.home : 'NL';
  const [hx, hy] = w.c[home] || [w.w / 2, w.h / 3];
  const paths = Object.entries(w.p).map(([cc, p]) => {
    const c = by.get(cc);
    const lv = c && c.hits ? 1 + Math.min(4, Math.floor(4 * Math.sqrt(c.hits / max))) : 0;
    return `<path d="${p}" class="am-cty l${lv} ${cc === home ? 'home' : ''}" ${c ? `data-am-cc="${cc}"` : ''}><title>${esc(amCcName(cc))}${c ? ': ' + L('{n} pogingen', { n: amN(c.hits) }) : ''}</title></path>`;
  }).join('');
  const arcs = [], dots = [];
  d.countries.forEach((c) => {
    const p = w.c[c.cc];
    if (!p || c.cc === home) return;
    const [x, y] = p;
    const dist = Math.hypot(hx - x, hy - y);
    const mx = (x + hx) / 2, my = (y + hy) / 2 - Math.min(140, dist * 0.35);
    const sw = (1 + 3.5 * Math.sqrt(c.hits / max)).toFixed(1);
    if (c.in) arcs.push(`<g class="am-line ${c.sev >= 2 ? 'crit' : 'warn'}" data-am-cc="${c.cc}"><path class="am-base" d="M${x} ${y} Q${mx} ${my} ${hx} ${hy}" stroke-width="${sw}"/><path class="am-dots" d="M${x} ${y} Q${mx} ${my} ${hx} ${hy}" stroke-width="${sw}"/></g>`);
    if (c.out) arcs.push(`<g class="am-line out" data-am-cc="${c.cc}"><path class="am-base" d="M${hx} ${hy} Q${mx} ${my + 18} ${x} ${y}" stroke-width="${sw}"/><path class="am-dots" d="M${hx} ${hy} Q${mx} ${my + 18} ${x} ${y}" stroke-width="${sw}"/></g>`);
    dots.push(`<circle class="am-pin ${c.out && !c.in ? 'out' : c.sev >= 2 ? 'crit' : 'warn'}" cx="${x}" cy="${y}" r="${(2.5 + 5 * Math.sqrt(c.hits / max)).toFixed(1)}" data-am-cc="${c.cc}"><title>${esc(amCcName(c.cc))}: ${esc(L('{n} pogingen', { n: amN(c.hits) }))}</title></circle>`);
  });
  const lan = by.get('LAN');
  return `<div class="am-world ${mini ? 'mini' : ''}"><svg viewBox="0 0 ${w.w} ${w.h}" role="img" aria-label="${esc(L('Wereldkaart met de herkomst van aanvallen'))}">
      <g class="am-land">${paths}</g>${arcs.join('')}${dots.join('')}
      <g class="am-home" transform="translate(${hx} ${hy})"><circle r="9" class="am-home-ring"/><circle r="4.5"/></g></svg>
    ${!mini && lan ? `<button type="button" class="am-lan" data-am-cc="LAN">${amSvg('home', 'am-ic sm')} ${L('Eigen netwerk: {n} pogingen', { n: amN(lan.hits) })}</button>` : ''}
    ${mini ? '' : `<p class="am-src-note muted">${esc(w.src)}</p>`}</div>`;
}

function amCountries(d) {
  const list = d.countries.slice(0, 8);
  const max = Math.max(1, ...list.map((c) => c.hits));
  return `<section class="card am-side"><h3>${L('Herkomst')}</h3><div class="am-bars">${list.map((c) => `<button type="button" class="am-barrow" data-am-cc="${esc(c.cc)}">
      <span class="am-bcc">${esc(c.cc === '??' ? '?' : c.cc)}</span><span class="am-bname">${esc(amCcName(c.cc))}</span>
      <span class="am-meter"><i class="${c.out && !c.in ? 'out' : c.sev >= 2 ? 'crit' : 'warn'}" style="width:${Math.max(3, Math.round(100 * c.hits / max))}%"></i></span><span class="am-bn">${amN(c.hits)}</span></button>`).join('')}</div>
    ${d.countries.length > 8 ? `<p class="muted small">${L('+ {n} andere landen', { n: d.countries.length - 8 })}</p>` : ''}</section>`;
}

function amRecent(d) {
  return `<section class="card am-side am-recent"><h3>${L('Laatste aanvallen')}</h3><div class="table-scroll"><table class="am-table"><thead><tr>
      <th>${L('Tijd')}</th><th>${L('Bron')}</th><th>${L('Doelwit')}</th><th>${L('Dienst')}</th><th>${L('Wat')}</th></tr></thead><tbody>
    ${d.recent.map((r) => `<tr class="s${r.sev}"><td class="mono">${esc(r.at.slice(11, 16))}</td>
      <td><button type="button" class="linkbtn mono" data-am-ip="${esc(r.ip)}">${esc(r.cc === 'LAN' ? '' : r.cc + ' ')}${esc(r.ip)}</button></td>
      <td>${r.dir === 'o' ? `<span class="am-dot out"></span>${L('uitgaand')} · ` : ''}${esc(r.host)}</td><td>${esc(amSvc(r))}</td>
      <td><button type="button" class="linkbtn" data-am-inc="${r.id}">${esc(r.title)}</button></td></tr>`).join('')}</tbody></table></div></section>`;
}


/** Dashboard: klein kaartje met de aanvallen van de laatste 24 uur (de gegevens hooguit een minuut oud) */
let hmAtk = null, hmAtkAt = 0, hmAtkBusy = false;
function hmAttacks() {
  if (!hmAtkBusy && (!hmAtk || Date.now() - hmAtkAt > 60000)) hmAtkLoad();
  return `<section class="hm-card hm-atk" id="hm-atk" aria-labelledby="hm-h-atk">${hmAtkInner()}</section>`;
}
async function hmAtkLoad() {
  hmAtkBusy = true;
  try {
    const [d] = await Promise.all([api('ids_flows', null, { days: 1, top: 5 }), amLoadWorld()]);
    hmAtk = d;
    hmAtkAt = Date.now();
    const el = $('#hm-atk');
    if (el) { el.innerHTML = hmAtkInner(); hmAtkWire(el); }
  } catch (e) { /* het kaartje is extra */ } finally { hmAtkBusy = false; }
}
function hmAtkWire(el) {
  $$('[data-hm-go]', el).forEach((b) => (b.onclick = () => showTab(b.dataset.hmGo)));
  $$('[data-am-cc]', el).forEach((b) => b.addEventListener('click', () => { amOpt.cc = b.dataset.amCc; amOpt.view = 'flow'; showTab('ids/map'); }));
}
function hmAtkInner() {
  const head = `<div class="hm-card-head"><h2 id="hm-h-atk">${esc(L('Aanvallen (24 uur)'))}</h2><button type="button" class="link-btn" data-hm-go="ids/map">${esc(L('Naar de aanvalskaart'))} →</button></div>`;
  if (!hmAtk) return head + `<p class="muted small">${L('Laden…')}</p>`;
  const k = hmAtk.kpi;
  if (!k.attacks) return head + `<p class="hm-card-sub">${L('Geen aanvallen in de laatste 24 uur.')}</p>`;
  const open = k.shown - k.blocked;
  return head + `<p class="hm-card-sub"><b>${amN(k.attacks)}</b> ${L('pogingen')} · <b>${amN(k.sources)}</b> ${L('bronnen uit {n} landen', { n: k.countries })} ·
      ${open ? `<span class="warn-txt">${L('{n} niet geblokkeerd', { n: open })}</span>` : `<span class="good">${L('alles geblokkeerd')}</span>`}</p>
    ${amWorld ? amWorldHtml(hmAtk, true) : ''}`;
}

// ---------------------------------------------------------------- Incidentvenster met advies (1.101.0)
/** Commando's om te kopiëren, met een uitleg erboven */
function cmdList(list, prefix) {
  return list.map(([label, cmd], n) => `<div class="adv-cmd"><span class="small muted">${esc(label)}</span>${copyBox(`${prefix}-${n}`, cmd)}</div>`).join('');
}
/** Gegevens over een IP-adres (eigenaar, DNS, land, eerder gezien) in het incidentvenster; apart opgehaald met time-outs */
/** Dreigingsinformatie bij een IP (1.112.0): oordeel, AbuseIPDB, GreyNoise, Shodan, met links */
const TI_VERDICT = { malicious: () => '☠️ ' + L('bekende aanvaller'), suspicious: () => '⚠️ ' + L('verdacht'), benign: () => '✔ ' + L('onschuldig'), scanner: () => '🤖 ' + L('scanner'),
  tor: () => '🧅 Tor', clean: () => L('niet bekend als aanvaller'), unknown: () => L('onbekend') };
function tiRows(t, ip) {
  const e = encodeURIComponent(ip);
  const rows = [[L('Oordeel'), `<b class="${t.verdict === 'malicious' ? 'bad' : t.verdict === 'suspicious' ? 'warn-txt' : t.verdict === 'benign' ? 'good' : ''}">${(TI_VERDICT[t.verdict] || (() => t.verdict))()}</b>
    <span class="score-tag ${t.score >= 80 ? 'crit' : t.score >= 40 ? 'warn' : ''}">${+t.score}</span>${(t.why || []).length ? `<br><span class="small">${esc(t.why.join(' · '))}</span>` : ''}`]];
  if (t.abuse) rows.push(['AbuseIPDB', `<a href="https://www.abuseipdb.com/check/${e}" target="_blank" rel="noopener">${L('{s}% · {n} meldingen', { s: t.abuse.score, n: t.abuse.reports })}</a>
    <span class="muted small">${esc([t.abuse.usage, t.abuse.isp, t.abuse.domain].filter(Boolean).join(' · '))}${t.abuse.last ? ' · ' + L('laatst {t}', { t: esc(fmtDateTime(t.abuse.last)) }) : ''}</span>`]);
  if (t.gn) rows.push(['GreyNoise', `<a href="https://viz.greynoise.io/ip/${e}" target="_blank" rel="noopener">${esc(t.gn.riot ? L('bekende dienst') : t.gn.class || (t.gn.noise ? L('scant het internet') : L('niet gezien')))}</a>${t.gn.name ? ` <span class="muted small">${esc(t.gn.name)}</span>` : ''}`]);
  if (t.shodan) rows.push(['Shodan', `<a href="https://www.shodan.io/host/${e}" target="_blank" rel="noopener">${t.shodan.ports.length ? L('poorten {list}', { list: esc(t.shodan.ports.slice(0, 12).join(', ')) }) : L('geen open poorten bekend')}</a>
    ${t.shodan.tags.length ? ' · ' + esc(t.shodan.tags.join(', ')) : ''}${t.shodan.nvulns ? ` · <span class="warn-txt">${L('{n} lekken', { n: t.shodan.nvulns })}</span>` : ''}`]);
  return rows;
}
async function fillIpInfo(box, ip, fresh = false) {
  box.innerHTML = `<span class="muted small">⏳ ${L('Gegevens van {ip} ophalen (hooguit een paar seconden)…', { ip: esc(ip) })}</span>`;
  let d;
  try { d = await api('ip_info', null, fresh ? { ip, fresh: 1 } : { ip }); } catch (ex) { box.innerHTML = `<span class="bad small">${esc(ex.message)}</span>`; return; }
  const r = d.rdap || {};
  const rows = [
    [L('Waar'), d.internal ? '🏠 ' + L('je eigen netwerk') + (d.rzmon_host ? ` · ${L('server {name} in RzSense', { name: `<b>${esc(d.rzmon_host)}</b>` })}` : '') : (ccTag(d.cc || r.country) || '–')],
    [L('DNS-naam'), d.rdns && d.rdns.name ? `<code>${esc(d.rdns.name)}</code> ${d.rdns.confirmed ? `<span class="good small">✔ ${L('wijst terug naar dit IP')}</span>`
      : `<span class="warn-txt small">${L('wijst niet terug naar dit IP (niet te vertrouwen)')}</span>`}` : `<span class="muted">${d.rdns ? L('geen') : L('niet op te zoeken')}</span>`],
    ...(d.internal ? [] : [
      [L('Eigenaar'), r.error ? `<span class="muted">${esc(r.error)}</span>` : esc(r.org || '–') + (d.hosting ? ` <span class="warn-txt small">· ${L('hosting/cloud: vandaar komen vooral scanners en gehackte servers')}</span>` : '')],
      [L('Netwerk'), r.error ? '' : esc([r.name, r.cidr || r.range].filter(Boolean).join(' · ') || '–') + (r.registry ? ` <span class="muted small">(${esc(r.registry)})</span>` : '')],
      [L('Misbruik melden'), r.abuse ? `<a href="mailto:${esc(r.abuse)}">${esc(r.abuse)}</a>` : '<span class="muted">–</span>'],
    ]),
    [L('Bekende aanvaller'), d.bad ? `<b class="bad">☠️ ${esc(d.bad)}</b>` : `<span class="muted">${L('niet op de lijsten')}</span>`],
    ...(d.ti ? tiRows(d.ti, ip) : (d.ti_on && !d.internal ? [[L('Dreigingsinformatie'), `<span class="muted">${L('niets bekend (of de limiet van vandaag is bereikt)')}</span>`]] : [])),
    [L('In RzSense'), [d.whitelist ? '✅ ' + L('op de whitelist') : '', d.blocked_rz ? '⛔ ' + L('geblokkeerd in RzSense') : '', d.blocked_ids.length ? '⛔ ' + L('geblokkeerd op {list}', { list: esc(d.blocked_ids.join(', ')) }) : '',
      d.events ? L('{n} gebeurtenissen sinds {t}', { n: d.events, t: esc(fmtDateTime(d.first)) }) : L('nog niet eerder gezien'), d.hosts.length ? L('servers: {list}', { list: esc(d.hosts.join(', ')) }) : ''].filter(Boolean).join(' · ')],
  ].filter(([, v]) => v !== '');
  box.innerHTML = `<table class="kv-table small">${rows.map(([k, v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('')}</table>
    <p class="muted small">${d.cached_at ? L('Opgezocht {t}', { t: esc(fmtDateTime(d.cached_at)) }) + ' · ' : ''}<button type="button" class="linkbtn need-admin" data-ip-fresh>${L('opnieuw opzoeken')}</button></p>`;
  const fb = $('[data-ip-fresh]', box);
  if (fb) fb.onclick = () => fillIpInfo(box, ip, true);
}

/**
 * 🔎 Inspectie (1.110.0): het bewijs bij een incident. Webverzoeken met de techniek en het verdachte stuk gemarkeerd;
 * processen, verbindingen, bestanden en containers met alle gegevens, en de regel die raak was (met MITRE en bron).
 */
function inspectHtml(list) {
  if (!list.length) return '';
  const mark = (txt, parts) => {
    let h = esc(txt);
    for (const p of parts) { const e = esc(p); if (e && e.length >= 2) h = h.split(e).join(`<mark>${e}</mark>`); }
    return h;
  };
  const web = list.filter((x) => x.kind === 'web');
  const other = list.filter((x) => x.kind !== 'web');
  let html = '';
  if (web.length) {
    const techs = [...new Set(web.flatMap((x) => (x.ev.tech || []).map((t) => L(t[1]))))];
    const cves = [...new Set(web.map((x) => x.ev.cve && x.ev.cve[0]).filter(Boolean))];
    html += `<div class="insp-tags">${techs.map((t) => `<span class="chip">${esc(t)}</span>`).join('')}${cves.map((c) => `<a class="chip crit" href="https://nvd.nist.gov/vuln/detail/${esc(c)}" target="_blank" rel="noopener">${esc(c)}</a>`).join('')}
      ${web.some((x) => x.ev.scanner) ? `<span class="chip">🤖 ${esc(web.find((x) => x.ev.scanner).ev.scanner)}</span>` : ''}</div>
      <div class="table-scroll"><table class="vuln-table insp-web"><thead><tr><th>${L('Tijd')}</th><th>${L('Verzoek')}</th><th>${L('Status')}</th><th>User-Agent</th></tr></thead><tbody>
      ${web.map((x) => { const v = x.ev; const hit = (v.tech || []).map((t) => t[2]);
        return `<tr><td class="small">${esc(new Date(v.at * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' }))}</td>
          <td class="mono small insp-uri"><b>${esc(v.method)}</b> ${mark(v.uri, hit)}${v.dec ? `<br><span class="muted" title="${esc(L('Gedecodeerd'))}">↳ ${mark(v.dec, hit)}</span>` : ''}${v.cve ? `<br><span class="crit-txt">${esc(v.cve[0] ? v.cve[0] + ' · ' : '')}${esc(L(v.cve[1]))}</span>` : ''}</td>
          <td class="${v.status >= 200 && v.status < 400 ? 'warn-txt' : 'muted'}" title="${esc(v.status >= 200 && v.status < 400 ? L('Gewoon antwoord: kijk of het lukte') : L('Geweigerd of niet gevonden'))}">${v.status}</td>
          <td class="small">${mark(v.ua, hit)}</td></tr>`; }).join('')}
      </tbody></table></div>`;
  }
  for (const x of other.filter((y) => y.kind === 'chain').slice(0, 1)) {
    const r = x.info || {};
    html += `<div class="insp-ev"><div class="insp-head">🔗 <b>${esc(r.title || '')}</b> ${(r.tactics || []).map((t) => `<span class="chip tac">${esc(tacticName(t))}</span>`).join('')}
        <span class="muted small">${esc(fmtDateTime(x.at))}</span></div>
      ${chainSteps(x.ev.steps || [], 'data-inc-step')}
      ${(x.ev._done || []).length ? `<p class="small">⛔ ${L('RzSense deed')}: ${esc(x.ev._done.join(', '))}</p>` : ''}
      ${r.what ? `<p class="small muted">${esc(r.what)}</p>` : ''}
      <p class="small">${(r.tags || []).map(techLink).join(' ')}</p></div>`;
  }
  const evs = other.filter((y) => y.kind !== 'chain').slice(0, 20);
  if (evs.length > 1) {
    html += `<div class="ev-pager small"><span class="muted">${L('Gebeurtenis')}</span> ${evs.map((x, k) => `<button type="button" class="pg-n ${k === 0 ? 'on' : ''}" data-ev-nav="${k}" title="${esc(fmtDateTime(x.at))}">${k + 1}</button>`).join('')}
      <span class="muted">${L('{n} keer gezien; nieuwste eerst', { n: evs.length })}</span></div>`;
  }
  for (const [k, x] of evs.entries()) {
    const v = x.ev, r = x.info || {};
    const rows = {
      exec: [[L('Programma'), `<code>${esc(v.exe || v.comm)}</code>`], [L('Opdracht'), `<code class="insp-cmd">${esc(v.cmd || '')}</code>`], [L('Ouder'), esc(v.pc || '?')],
        [L('Gebruiker'), `${esc(v.u || '?')} (uid ${esc(v.uid)}${+v.auid && +v.auid < 4294967295 ? ', ' + L('ingelogd als uid {n}', { n: esc(v.auid) }) : ''})`], ['pid', esc(v.pid)], [L('Map'), esc(v.cwd || '')]],
      net: [[L('Programma'), `<code>${esc(v.exe || v.comm)}</code>`], [v.dir === 'listen' ? L('Luistert op') : L('Naar'), v.dir === 'listen' ? esc(v.lp) : `<code>${esc(v.rip)}:${esc(v.rp)}</code>`], [L('Gebruiker'), esc(v.u || '?')], ['pid', esc(v.pid)]],
      file: [[L('Bestand'), `<code>${esc(v.path)}</code>`], [L('Grootte'), fmtBytes(+v.size || 0)], ['ELF', +v.elf ? L('ja (programma)') : L('nee')], [L('Verdachte code'), esc(v.susp || '–')], ...(v.yara ? [['YARA', `<b class="bad">${esc(v.yara)}</b>`]] : []), ['SHA-256', `<code class="small">${esc(v.sha || '')}</code>`],
        ...(x.ti ? [[L('Bekend als'), x.ti.verdict === 'malware' ? `<b class="bad">☠️ ${esc((x.ti.bazaar && x.ti.bazaar.family) || L('malware'))}</b> <a href="https://bazaar.abuse.ch/sample/${esc(v.sha)}/" target="_blank" rel="noopener">MalwareBazaar</a>`
          : x.ti.verdict === 'known_good' ? `<span class="good">✔ ${L('bekend, goed bestand')}</span> <span class="muted small">CIRCL · ${esc((x.ti.circl && (x.ti.circl.name || x.ti.circl.source)) || '')}</span>` : `<span class="muted">${L('niet bekend bij {src}', { src: esc((x.ti.src || []).join(', ') || '–') })}</span>`]] : [])],
      audit: [[L('Pad'), `<code>${esc(v.name)}</code>`], [L('Door'), `<code>${esc(v.exe)}</code> (${esc(v.u || v.uid)})`], [L('Sleutel'), esc(v.key)]],
      sur: [[L('Melding'), `<b>${esc(v.sig || '')}</b> <span class="muted small">sid ${esc(v.sid)}</span>`], [L('Categorie'), esc(v.cat || '–')],
        [L('Verkeer'), `<code>${esc(v.src)}:${esc(v.sport)} → ${esc(v.dst)}:${esc(v.dport)}</code> ${esc(v.proto || '')}`], [L('Ernst'), `${esc(v.sev)}${v.act === 'blocked' ? ' · ' + L('Suricata blokkeerde het') : ''}`]],
      ctr: [[L('Container'), `${esc(v.name)} <span class="muted">${esc(v.img || '')}</span>`], [L('Actie'), esc(v.act) + (+v.priv ? ' · --privileged' : '')], [L('Opdracht'), `<code>${esc(v.cmd || '')}</code>`]],
    }[x.kind] || [];
    html += `<div class="insp-ev" data-evi="${k}" ${k > 0 ? 'hidden' : ''}><div class="insp-head">${EDR_KIND_IC[x.kind] || ''} <b>${esc(r.title || x.rule)}</b> <span class="badge">${r.src === 'sigma' ? 'Sigma' : r.src === 'anomaly' ? L('Afwijking') : r.src === 'suricata' ? 'Suricata' : 'RzSense'}</span>
        ${(r.tags || []).map((t) => `<a class="chip" href="https://attack.mitre.org/techniques/${esc(t.replace('.', '/'))}/" target="_blank" rel="noopener">${esc(t)}</a>`).join('')}
        <span class="muted small">${esc(fmtDateTime(x.at))}</span></div>
      <table class="kv-table small">${rows.map(([k, val]) => `<tr><th>${k}</th><td>${val}</td></tr>`).join('')}
        ${(v._done || []).length ? `<tr><th>${L('RzSense deed')}</th><td>⛔ ${esc(v._done.join(', '))}</td></tr>` : ''}</table>
      ${r.what ? `<p class="small muted">${esc(r.what)}</p>` : ''}
      ${r.src === 'sigma' ? `<p class="small muted">${L('Sigma-regel van {a}', { a: esc(r.author || '?') })} · <a href="${esc(r.url)}" target="_blank" rel="noopener">${L('bron')}</a> · DRL 1.1${(r.fp || []).length ? ' · ' + L('kan ook: {fp}', { fp: esc(r.fp.join(', ')) }) : ''}</p>` : ''}</div>`;
  }
  return `<h3 class="set-sub">🔎 ${L('Inspectie')}</h3>${html}`;
}

document.addEventListener('keydown', (e) => {
  const m = $('#modal');
  if (!m || !m.open || !m.dataset.incNav || e.altKey || e.ctrlKey || e.metaKey || /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''))) return;
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
  const nav = m.dataset.incNav.split(',').map(Number);
  const pos = nav.indexOf(+m.dataset.incCur);
  e.preventDefault();
  incidentDialog(nav[(pos + (e.key === 'ArrowRight' ? 1 : -1) + nav.length) % nav.length], nav);
});
/** Nummers onderin het venster: ‹ 1 2 [3] 4 … 12 › met per nummer ✔ (afgehandeld) of • (open) */
function incidentPager(nav, id) {
  if (nav.length < 2) return '';
  const all = (idsData && idsData.incidents) || [];
  const st = (x) => (all.find((y) => +y.id === x) || {}).status;
  const pos = nav.indexOf(+id);
  const show = new Set([0, nav.length - 1, pos - 2, pos - 1, pos, pos + 1, pos + 2].filter((k) => k >= 0 && k < nav.length));
  const nums = [];
  let prev = -1;
  [...show].sort((x, y) => x - y).forEach((k) => {
    if (prev >= 0 && k > prev + 1) nums.push('<span class="pg-gap">…</span>');
    const s = st(nav[k]);
    nums.push(`<button type="button" class="pg-n ${k === pos ? 'on' : ''} ${s === 'open' ? 'open' : 'done'}" data-inc-nav="${nav[k]}" title="${esc(s === 'open' ? L('open') : L('afgehandeld'))}">${k + 1}<i>${s === 'open' ? '•' : '✔'}</i></button>`);
    prev = k;
  });
  const done = nav.filter((x) => st(x) !== 'open').length;
  return `<div class="inc-pager"><button type="button" class="pg-arrow" data-inc-nav="${nav[(pos - 1 + nav.length) % nav.length]}" title="${esc(L('Vorige (←)'))}">‹</button>${nums.join('')}
    <button type="button" class="pg-arrow" data-inc-nav="${nav[(pos + 1) % nav.length]}" title="${esc(L('Volgende (→)'))}">›</button>
    <span class="small muted">${L('{p} van {n} · ✔ {d} afgehandeld · {o} open', { p: pos + 1, n: nav.length, d: done, o: nav.length - done })}</span></div>`;
}

/** Vlag bij een landcode (bv. NL) */
const ccFlagOf = (cc) => (cc && /^[A-Z]{2}$/.test(cc) ? String.fromCodePoint(...[...cc].map((c) => 0x1F1E6 + c.charCodeAt(0) - 65)) : '');

/**
 * 1.222.0: het pad van een webaanval in het incidentvenster (extra blok; de rest van het venster blijft gewoon staan):
 * aanvaller → reverse proxy → site → dienst, met een oordeel. Zonder site in de log alleen aanvaller → proxy en een tip.
 */
function webPathHtml(w) {
  const arrow = (top, bottom, stop) => `<div class="wp-a${stop ? ' stop' : ''}"><span>${top || '&nbsp;'}</span><i></i><span>${bottom || '&nbsp;'}</span></div>`;
  const att = `<div class="wp-n att"><span class="k">${L('Aanvaller')}</span><span class="t mono">${esc(w.ip)}</span>
    <span class="s">${w.cc ? `${ccFlagOf(w.cc)} ${esc(w.cc)}` : ''}${w.ua ? `${w.cc ? '<br>' : ''}${L('scanner')} <code>${esc(w.ua)}</code>` : ''}</span>
    ${w.blocked ? `<span class="bdg red">⛔ ${L('geblokkeerd')}</span>` : ''}</div>`;
  const px = `<div class="wp-n px"><span class="k">${L('Reverse proxy')}</span><span class="t">${esc(w.proxy.label)}</span>
    <span class="s">${L('op {host}', { host: `<b>${esc(w.proxy.host)}</b>` })}${w.proxy.ctr ? ` · ${L('container')} <code>${esc(w.proxy.ctr)}</code>` : ''}</span></div>`;
  const total = w.sites.reduce((n, x) => n + x.n, 0) || w.hits;
  let rows = '';
  if (!w.sites.length) {
    rows = `<div class="wp-row wp-row2">${att}${arrow('HTTPS', L('{n} verzoeken', { n: total }))}${px}</div>`;
  } else {
    rows = w.sites.map((x, i) => {
      const fwd = x.site !== '' && x.up !== '';
      const site = `<div class="wp-n"><span class="k">${L('Website')}</span><span class="t">${x.site ? esc(x.site) : L('(geen bekende site)')}</span>
        <span class="s">${x.site ? L('{n} verzoeken', { n: x.n }) : L('vroeg naar je IP-adres')}</span></div>`;
      const svc = `<div class="wp-n ${fwd ? 'end' : ''}"><span class="k">${L('Dienst')}</span><span class="t">${fwd && x.svc ? esc(x.svc) : '—'}</span>
        <span class="s">${fwd ? (x.svc_where ? (x.svc_where === w.proxy.host ? L('container op {host}', { host: `<b>${esc(x.svc_where)}</b>` }) : `<code>${esc(x.svc_where)}</code>`) : '') : L('niets bereikt')}</span>
        ${fwd ? `<span class="bdg ${x.ok ? 'warn' : 'ok'}">${x.ok ? L('{n}× gewoon antwoord', { n: x.ok }) : L('alleen foutcodes')}</span>` : ''}</div>`;
      return `<div class="wp-row">${i ? '<div></div><div></div><div></div>' : att + arrow('HTTPS', L('{n} verzoeken', { n: total })) + px}${arrow(fwd ? L('site') : L('niet doorgestuurd'), '', !fwd)}${site}${arrow(fwd && x.up ? '→ ' + esc(x.up) : '', '', !fwd)}${svc}</div>`;
    }).join('');
  }
  const v = w.verdict;
  return `<h3 class="set-sub">🛤️ ${L('Pad van de aanval')}</h3><div class="wp">${rows}
    ${v ? `<div class="wp-v ${v.lvl}">${v.lvl === 'warn' ? '⚠️' : '✅'} <span>${esc(v.text)}</span></div>` : ''}
    ${!w.full ? `<p class="small muted wp-tip">💡 ${L('Wil je ook zien welke site en welke dienst erachter werd aangevallen? Zet bij Instellingen → IDS → Netwerk en aanvallers het extra logbestand voor je reverse proxy aan.')}</p>` : ''}</div>`;
}

/** 1.222.0: IDS → Overzicht: aanvallen per site (24 uur), alleen als de log van de proxy de site heeft */
async function loadWebSites() {
  const card = $('#ids-sites-card'), box = $('#ids-sites');
  if (!card) return;
  let d;
  try { d = await api('web_sites', null, { hours: 24 }); } catch (ex) { return; }
  const list = d.sites || [];
  card.hidden = !list.length;
  if (!list.length) return;
  const max = Math.max(...list.filter((x) => x.site).map((x) => x.n), 1);
  const proxies = [...new Set(list.map((x) => (x.kind ? x.kind + ' ' : '') + L('op {host}', { host: x.proxy })))].join(', ');
  box.innerHTML = `<h3 class="set-sub" style="margin-top:0">🌍 ${L('Aanvallen per site')} <span class="muted small">(${L('24 uur, via {p}', { p: esc(proxies) })})</span></h3>
    <table class="ws-t"><thead><tr><th>${L('Site')}</th><th class="hide-m">${L('Dienst erachter')}</th><th class="num">${L('Verzoeken')}</th><th class="num hide-m">${L("IP's")}</th><th class="hide-m">${L('Meest gevraagd')}</th><th>${L('Resultaat')}</th><th class="hide-m"></th></tr></thead><tbody>
    ${list.map((x) => `<tr class="${x.site ? '' : 'dim'}"><td class="site"><b>${x.site ? esc(x.site) : L('(je IP-adres, geen site)')}</b><span class="show-m">${esc(x.svc || '')}</span></td>
      <td class="hide-m">${x.site ? esc(x.svc || '–') + (x.svc_where && x.svc_where !== x.proxy ? ` <span class="muted small">${esc(x.svc_where)}</span>` : '') : esc(L('{p} weigert zelf', { p: x.kind || L('de proxy') }))}</td>
      <td class="n">${x.n.toLocaleString(LOCALE)}</td><td class="n hide-m">${x.ips}</td><td class="hide-m"><code>${esc(x.top || '–')}</code></td>
      <td><span class="ws-res ${x.ok ? 'w' : 'ok'}">${!x.site ? '✔ ' + L('niet doorgestuurd') : x.ok ? '⚠ ' + L('{n}× gewoon antwoord', { n: x.ok }) : '✔ ' + L('alleen foutcodes')}</span></td>
      <td class="hide-m" style="width:110px">${x.site ? `<div class="ws-bar"><i style="width:${Math.max(3, Math.round(x.n / max * 100))}%"></i></div>` : ''}</td></tr>`).join('')}
    </tbody></table>
    <div class="ws-foot">${L('Alleen verdachte verzoeken van buiten (aanvalspaden, scanners, veel 404/401/403, 444). "Gewoon antwoord" = 2xx of 3xx: kijk dan of er iets gevonden is.')}</div>`;
}

async function incidentDialog(id, nav = null) {
  let d;
  try { d = await api('ids_incident_info', null, { id }); } catch (ex) { toast(ex.message, 'err'); return; }
  nav = nav && nav.includes(+id) ? nav : [+id];   // alleen de incidenten van de regel waarop je klikte (1.118.0)
  const i = d.incident, a = d.advice;
  const open = i.status === 'open';
  const agentHost = i.host_mode === 'agent';
  const sevTxt = +i.severity === 2 ? '🔴 Critical' : '🟠 Warning';
  modal({
    title: `${+i.severity === 2 ? '🔴' : '🟠'} ${i.title}`,
    wide: true,
    body: `<p class="small" style="margin-top:0">${sevTxt} · <b>${esc(i.host_name)}</b> · ${esc(fmtDateTime(i.created_at))}${+i.hits > 1 ? ` · ${L('{n}× gezien, laatst {t}', { n: i.hits, t: esc(fmtDateTime(i.last_seen || i.created_at)) })}` : ''}
        · ${open ? `<b class="warn-txt">${L('open')}</b>` : `✔ ${L('afgehandeld door {who}', { who: esc(i.handled_by || '?') })}${i.handled_at ? ' (' + esc(fmtDateTime(i.handled_at)) + ')' : ''}`}${d.ignored ? ` · <span class="muted">${L('wordt niet meer gemeld (normaal)')}</span>` : ''}</p>
      ${i.ip ? '<div class="af-incsum" id="af-incsum"><p class="muted small">' + L('Laden…') + '</p></div>' : ''}
      ${d.explain ? explainHtml(d.explain) : i.detail ? `<p class="small inc-detail">${esc(i.detail)}</p>` : ''}
      ${inspectHtml(d.evidence || [])}
      ${d.wpath ? webPathHtml(d.wpath) : ''}
      <h3 class="set-sub">💡 ${L('Wat is dit?')}</h3><p>${esc(a.what)}</p>
      ${a.risk ? `<h3 class="set-sub">⚖️ ${L('Hoe erg is het?')}</h3><p>${esc(a.risk)}</p>` : ''}
      ${a.ask && open ? `<div class="callout inc-ask need-admin"><b>❓ ${L('Was jij dit (of ken je het)?')}</b>
        <div class="btn-row"><button type="button" class="btn primary" data-inc-act="normal">✅ ${L('Ja, dit was ik / bekend')}</button>
        <button type="button" class="btn danger" data-inc-no>❌ ${L('Nee, ik ken dit niet')}</button></div>
        <p class="muted small">${L('"Ja" handelt het af en meldt dit voortaan niet meer (voor deze server). "Nee" toont meteen de noodstappen.')}</p></div>` : ''}
      <div id="inc-emergency" ${a.ask && open ? 'hidden' : ''}>
        <h3 class="set-sub">🚨 ${a.ask ? L('Niet van jou: dit moet je nu doen') : L('Als het misgaat: noodstappen')}</h3>
        <ol>${a.emergency.map((x) => `<li>${esc(x)}</li>`).join('')}</ol>${cmdList(a.ecmds, 'inc-ec')}</div>
      ${a.steps.length || a.cmds.length ? `<h3 class="set-sub">🛠️ ${L('Wat te doen')}</h3>${a.steps.length ? `<ul>${a.steps.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : ''}${cmdList(a.cmds, 'inc-c')}` : ''}
      ${a.prevent ? `<h3 class="set-sub">🛡️ ${L('Voorkomen')}</h3><p>${esc(a.prevent)}</p>` : ''}
      ${a.ip ? `<h3 class="set-sub">🌐 ${L('IP-adres')} <code>${esc(a.ip)}</code></h3><div id="inc-ip"></div>` : ''}
      ${aiOn() ? `<div class="btn-row">${aiTriage(+id)}${aiBtn('ids', +id, true)}</div>${triageEdit(+id)}` : ''}
      <div class="btn-row need-admin inc-actions">
        ${open ? `<button type="button" class="btn" data-inc-act="handle">✔ ${L('Afhandelen')}</button>
          <button type="button" class="btn" data-inc-act="normal" title="${esc(L('Afhandelen en dit voortaan niet meer melden'))}">🚫 ${L('Dit is normaal')}</button>`
          : `<button type="button" class="btn" data-inc-act="reopen">↺ ${L('Heropenen')}</button>`}
        ${a.ip ? `<button type="button" class="btn" data-inc-block>⛔ ${L('Blokkeer IP')}</button>
          <button type="button" class="btn" data-inc-wl>✅ ${L('Op de whitelist')}</button>
          <button type="button" class="btn" data-inc-tl>🔎 ${L('Tijdlijn van dit IP')}</button>` : ''}
        ${a.user && agentHost && a.user !== 'root' ? `<button type="button" class="btn danger" data-inc-lock>🔒 ${L('Vergrendel {user}', { user: esc(a.user) })}</button>` : ''}
        ${agentHost ? `<button type="button" class="btn danger" data-inc-iso>🧱 ${L('Isoleer server (24 uur)')}</button>
          <button type="button" class="btn" data-inc-sec>🛡️ ${L('Beveiliging van {name}', { name: esc(i.host_name) })}</button>
          <button type="button" class="btn" data-inc-am>⚙️ Agent Manager</button>` : ''}
      </div>
      ${incidentPager(nav, id)}`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  const body = $('#modal-form');
  if (i.ip) afIncSummary($('#af-incsum'), i.ip);   // 1.152.0
  $('#modal').dataset.incNav = nav.length > 1 ? nav.join(',') : '';
  $('#modal').dataset.incCur = id;
  wireCopy(body);
  $$('[data-inc-step]', body).forEach((b) => { b.onclick = (e) => { e.preventDefault(); e.stopPropagation(); incidentDialog(+b.dataset.incStep); }; });
  $$('[data-prot-card]', body).forEach((b) => {
    b.onclick = async (e) => {
      e.preventDefault(); e.stopPropagation();
      $('#modal').close();
      showTab('ids/prot');
      await loadProt();
      const c = $('#prot-' + b.dataset.protCard);
      if (c) { c.scrollIntoView({ block: 'center' }); c.classList.add('live-pulse'); }
    };
  });
  if (a.ip) fillIpInfo($('#inc-ip'), a.ip);
  // Na afhandelen: in een bundel door naar het volgende open incident, anders sluiten
  const reload = async () => {
    // 1.217.0: alleen dit incident bijwerken op de pagina, niet alles opnieuw ophalen
    const act = ((body.querySelector('[data-inc-act].busy') || {}).dataset || {}).incAct;
    const x = (idsData && idsData.incidents || []).find((y) => +y.id === +i.id);
    if (x) { x.status = act === 'reopen' ? 'open' : 'done'; x.handled_by = act === 'reopen' ? null : (($('.acct-name') || {}).textContent || ''); }
    clearTimeout(idsRedrawT); idsRedrawT = setTimeout(() => keepOpen($('#tab-ids'), () => renderIds()), 50);
    const opens = nav.filter((x) => x !== +i.id && ((idsData.incidents || []).find((y) => +y.id === x) || {}).status === 'open');
    const after = opens.find((x) => nav.indexOf(x) > nav.indexOf(+i.id)) || opens[0];
    if (after) incidentDialog(after, nav); else $('#modal').close();
  };
  body.onclick = async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.incNav) return incidentDialog(+b.dataset.incNav, nav);
    if (b.dataset.evNav !== undefined) {   // door de gebeurtenissen van één incident (herhalingen)
      $$('[data-evi]', body).forEach((x) => { x.hidden = x.dataset.evi !== b.dataset.evNav; });
      $$('[data-ev-nav]', body).forEach((x) => x.classList.toggle('on', x === b));
      return;
    }
    try {
      if (b.dataset.incAct) {
        b.classList.add('busy');
        await api('ids_incident', { id: i.id, action: b.dataset.incAct });
        toast(b.dataset.incAct === 'normal' ? L('Afgehandeld; dit wordt voortaan niet meer gemeld') : b.dataset.incAct === 'reopen' ? L('Weer open') : L('Afgehandeld'));
        return reload();
      }
      if (b.hasAttribute('data-inc-no')) {
        $('#inc-emergency').hidden = false; $('#inc-emergency').scrollIntoView({ block: 'nearest' });
        api('ids_incident', { id: i.id, action: 'notme' }).then((r) => { if (r && r.triage && state.ai) state.ai.triage = r.triage; }).catch(() => null);   // 1.223.0: voortaan "lijkt echt" voor deze soort
        return;
      }
      if (b.dataset.triSet) {   // 1.223.0: jouw oordeel, ook voor de andere open incidenten van deze soort
        const r = await api('ids_incident', { id: i.id, action: 'triage', verdict: b.dataset.triSet });
        if (r && r.triage && state.ai) state.ai.triage = r.triage;
        toast(L('Oordeel opgeslagen; geldt voortaan voor deze soort'));
        clearTimeout(idsRedrawT); idsRedrawT = setTimeout(() => keepOpen($('#tab-ids'), () => renderIds()), 50);
        return incidentDialog(+i.id, nav);
      }
      if (b.hasAttribute('data-inc-block')) { $('#modal').close(); return idsBlockDialog(a.ip, i.host_id, i.title.slice(0, 200)); }
      if (b.hasAttribute('data-inc-wl')) { $('#modal').close(); return wlDialog({ type: 'ip', value: a.ip, src: 'ids', wl: false }); }
      if (b.hasAttribute('data-inc-tl')) { $('#modal').close(); return ipTimeline(a.ip); }
      if (b.hasAttribute('data-inc-sec')) { $('#modal').close(); return hostSecDialog(+i.host_id); }
      if (b.hasAttribute('data-inc-am')) { $('#modal').close(); return agentInstructions(findHost(+i.host_id)); }
      if (b.hasAttribute('data-inc-lock')) {
        const pw = await askConfirm({ title: L('{user} op {name} vergrendelen?', { user: a.user, name: i.host_name }), danger: true, word: 'VERGRENDELEN', ok: '🔒 ' + L('Vergrendelen'),
          text: L('Het account gaat op slot (geen wachtwoord, geen SSH-sleutel) en al zijn processen worden gestopt.') });
        if (pw === null) return;
        await api('host_userlock', { host_id: +i.host_id, user: a.user, lock: true, confirm: pw });
        toast(L('{user} wordt vergrendeld', { user: a.user }));
      }
      if (b.hasAttribute('data-inc-iso')) {
        const pw = await askConfirm({ title: L('{name} isoleren (24 uur)?', { name: i.host_name }), danger: true, word: 'ISOLEREN', ok: '🧱 ' + L('Isoleren'),
          text: L('Alleen RzSense, je lokale netwerken en jouw IP-adres kunnen er nog bij; al het andere verkeer stopt. Vervalt vanzelf na de gekozen tijd.') });
        if (pw === null) return;
        await api('host_isolate', { host_id: +i.host_id, hours: 24, confirm: pw, incident_id: +i.id });
        toast(L('{name} wordt geïsoleerd; de agent past het binnen een minuut toe', { name: i.host_name }));
      }
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

/** Eén incident als compacte rij; group = alle incidenten van een bundel (dan met 3× en een knop voor allemaal); nav = de bundel voor de nummers in het venster */
function idsRow(i, group = null, nav = null) {
  const sev = +i.severity === 2 ? 2 : 1;
  const done = i.status !== 'open';
  const fim = i.type === 'fim' || i.type === 'fim_bulk';
  const okLabel = fim ? (i.type === 'fim_bulk' ? L('✔ Alles goedkeuren') : L('✔ Wijziging goedkeuren')) : L('✔ Afhandelen');
  const normal = fim ? [L('🚫 Niet meer bewaken'), i.type === 'fim_bulk' ? L('Goedkeuren en wijzigingen onder dit pad voortaan niet meer melden') : L('Goedkeuren en dit bestand voortaan niet meer bewaken')]
    : i.type === 'webshell' ? ['✅ ' + L('Vrijgeven'), L('Dit is normaal voor deze website: voortaan niet meer melden en niet meer stoppen')]
    : [L('Dit is normaal'), L('Afhandelen en dit voortaan niet meer melden')];
  // Uitgaand naar een bekende aanvaller: vrijgeven = het IP op de whitelist (dan ook niet meer geblokkeerd)
  const release = i.type === 'out_bad' && i.ip ? ` <button type="button" class="btn small-btn" data-ids="release" data-ip="${esc(i.ip)}" title="${esc(L('Dit IP op de whitelist: nooit meer melden of blokkeren'))}">✅ ${L('Vrijgeven')}</button>` : '';
  const many = group && !done ? group.filter((x) => x.status === 'open').map((x) => x.id).join(',') : '';
  const selIds = !done ? (many || String(i.id)) : '';
  return `<div class="irow s${sev} ${done ? 'ids-done' : ''}">
    <span class="ids-selw">${selIds && (!group || many) ? `<input type="checkbox" class="ids-sel need-admin" data-sel-id="${selIds}" aria-label="${esc(L('Kiezen'))}" ${selIds.split(',').every((x) => IDS_SEL.has(+x)) ? 'checked' : ''}>` : ''}<i class="adot s${sev}"></i></span>
    <span class="m-tt"><button type="button" class="inc-open" data-inc="${i.id}" ${nav && nav.length > 1 ? `data-inc-list="${nav.map((x) => x.id).join(',')}"` : ''} title="${esc(L('Details, advies en acties'))}">${esc(i.title)}</button>${aiTriage(i.id)}${done ? '' : aiBtn('ids', i.id)}${actChips(((idsData && idsData.prot) || {})[i.id])}${+i.hits > 1 ? ` <span class="rep hits" tabindex="0" title="${esc(L('Dit incident is {n}× gezien · eerst {a} · laatst {b}', { n: i.hits, a: fmtDateTime(i.created_at), b: fmtDateTime(i.last_seen || i.created_at) }))}">${i.hits}×</span>` : ''}${group ? ` <button type="button" class="rep" data-bundle-btn title="${esc(L('{n} incidenten van deze soort op {host} ({o} open) · van {a} tot {b} · klik om ze te tonen; klik op de titel om ze één voor één te bekijken', { n: group.length, host: i.host_name, o: group.filter((x) => x.status === 'open').length, a: group[group.length - 1].created_at.slice(11, 16), b: group[0].created_at.slice(11, 16) }))}">${group.length}×</button>` : ''}
      <small>${esc(i.detail)}${i.ip ? ` · IP <code>${esc(i.ip)}</code>${ccTag(i.cc)}` : ''}${done ? ` · ✔ ${L('afgehandeld door {who}', { who: esc(i.handled_by || '?') })}` : ''}</small></span>
    <span class="m-sv">${esc(i.host_name)}</span>
    <span class="m-t" title="${esc(i.created_at)}">${esc(i.created_at.slice(11, 16))}</span>
    <span class="ids-actions need-admin">${done ? `<button type="button" class="linkbtn" data-ids="reopen" data-id="${i.id}">↺ ${L('heropenen')}</button>`
      : many ? `<button type="button" class="btn small-btn" data-ids="handle" data-many="${many}" title="${esc(L('Alle {n} afhandelen', { n: many.split(',').length }))}">${okLabel} (${many.split(',').length})</button>`
      : `<button type="button" class="btn small-btn" data-ids="handle" data-id="${i.id}">${okLabel}</button>
         ${release || `<button type="button" class="btn small-btn" data-ids="normal" data-id="${i.id}" title="${normal[1]}">${normal[0]}</button>`}`}
      ${i.ip && !many ? `<button type="button" class="btn small-btn" data-ids="block" data-ip="${esc(i.ip)}" data-host="${i.host_id}">⛔ ${L('Blokkeer IP')}</button>` : ''}</span>
  </div>`;
}

function idsToggleLabel() {
  const days = $$('#ids-incidents .alert-day');
  $('#ids-toggle').hidden = days.length < 2;
  $('#ids-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

function idsBlockDialog(ip = '', hostId = '', reason = '') {
  const hosts = ((idsData && idsData.hosts) || []).filter((h) => !h.ha);
  modal({
    title: L('⛔ IP blokkeren'),
    body: `<label>${L('IP-adres')}<input name="ip" class="mono" value="${esc(ip)}" required placeholder="${L('bv. {x}', { x: '203.0.113.9' })}"></label>
      <div class="row2">
        <label>${L('Op server')}<select name="host_id">${hosts.map((h) => `<option value="${h.id}" ${+h.id === +hostId ? 'selected' : ''}>${esc(h.name)}${h.fw_state === 'unsupported' ? ' ' + L('(alleen detecteren)') : ''}</option>`).join('')}</select></label>
        <label>${L('Hoe lang')}<select name="hours"><option value="24">${L('24 uur')}</option><option value="168">${L('7 dagen')}</option><option value="720">${L('30 dagen')}</option><option value="0">${L('Permanent')}</option></select></label>
      </div>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200" value="${esc(reason)}"></label>
      ${idsData && idsData.mode !== 'block' ? '<p class="warn-txt small">' + L('IDS staat op <i>leren</i>: de blokkade wordt vastgelegd, maar pas echt actief als je overschakelt naar <i>blokkeren</i>.') + '</p>' : ''}
      <p class="muted small">${L('Je eigen IP-adres ({ip}) kan niet geblokkeerd worden. Deblokkeren kan altijd hier; de agent past het binnen een minuut aan.', { ip: esc((idsData && idsData.my_ip) || '?') })}</p>`,
    buttons: [{ label: '⛔ ' + L('Blokkeren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('ids_block', { ip: d.ip, host_id: +d.host_id, hours: +d.hours, reason: d.reason });
      toast(L('{ip} geblokkeerd', { ip: d.ip }));
      setTimeout(loadIds, 50);
    },
  });
}

/** "Vandaag", "Gisteren" of "di 14 okt", met het jaar erbij als het niet dit jaar is */
function dayLabel(day, today) {
  const d = new Date(day + 'T12:00:00'), t = new Date(today + 'T12:00:00');
  const diff = Math.round((t - d) / 86400000);
  const opts = { weekday: 'long', day: 'numeric', month: 'long', ...(d.getFullYear() !== t.getFullYear() ? { year: 'numeric' } : {}) };
  const date = d.toLocaleDateString(LOCALE, opts);
  return diff === 0 ? `${L('Vandaag')} · ${date}` : diff === 1 ? `${L('Gisteren')} · ${date}` : date.charAt(0).toUpperCase() + date.slice(1);
}

const AUTH_TYPES = { ssh: 'SSH', console: 'Console', sudo: 'sudo', su: 'su' };
/** Extra gegevens van een login/sudo-regel (wie, wat, IP), of null */
function alertDetail(a) {
  if (a.kind !== 'auth' && a.kind !== 'authfail') return null;
  try { return JSON.parse(a.detail || '{}'); } catch (e) { return {}; }
}

/** Server en check van een melding; van een verwijderde check uit de titel ("🔴 CRITICAL: server – check") */
function alertCols(a) {
  const t = a.title.match(/:\s*(.+?) – (.+)$/);
  const det = alertDetail(a);
  const who = det ? (det.users ? det.users.join(', ') : det.user) || '?' : '';
  // Geweigerde verbinding, login of agent die verbindt: de titel (zonder het teken ervoor) is de "check"
  if (a.kind === 'sec' || (a.kind === 'info' && !a.check_name && !t)) return { host: a.host_name || 'RzSense', check: a.title.replace(/^\S+\s+/, '') };
  return {
    host: a.host_name || (t ? t[1] : 'RzSense'),
    check: det ? `${AUTH_TYPES[det.type] || det.type} · ${who}${det.as && det.type === 'su' ? ' → ' + det.as : ''}` : a.check_name || (t ? t[2] : '–'),
  };
}

/** Volgorde van de status: critical, warning, onbekend, OK/hersteld, bevestigd, logins */
function alertRank(a) {
  if (a.kind === 'authfail') return 5;
  if (a.kind === 'auth') return 6;
  if (a.kind === 'ack') return 4;
  if (a.kind === 'info') return 3.5;
  if (a.kind === 'sec') return 1;
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
  const cmp = (x, y) => (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), LANG, { numeric: true, sensitivity: 'base' }));
  return list.map((a) => [a, val(a)]).sort((p, q) => dir * cmp(p[1], q[1]) || (q[0].created_at > p[0].created_at ? 1 : -1)).map(([a]) => a);
}

/** ⛔-knop bij een melding met een IP (geweigerde verbinding, mislukte login); alleen voor beheerders */
function alertBlock(a) {
  if (!a.ip || !isAdmin() || !(a.kind === 'sec' || (a.detail || '').includes('"loginfail"'))) return '';
  return a.blocked ? ` <span class="muted small" title="${esc(a.ip)}">⛔ ${L('geblokkeerd')}</span>`
    : !isIpAddr(a.ip) ? '' : ` <button type="button" class="linkbtn" data-block-ip="${esc(a.ip)}" title="${esc(L('{ip} blokkeren', { ip: a.ip }))}">⛔ ${L('blokkeren')}</button>`;
}

const alertBundles = new Set();   // uitgeklapte bundels
/** Klik op 5×: bundel uit- of inklappen (en onthouden tot de pagina herladen wordt). true = afgehandeld */
function toggleBundle(e, set = idsBundles) {
  const rb = e.target.closest('[data-bundle-btn]');
  if (!rb) return false;
  const b = rb.closest('.bundle');
  b.classList.toggle('open') ? set.add(b.dataset.bundle) : set.delete(b.dataset.bundle);
  return true;
}
/** Items met dezelfde sleutel samen: [{ k, items }]. Alleen direct achter elkaar, of (anywhere) overal in de lijst, op de plek van de eerste */
function bundleRuns(list, key, anywhere = false) {
  const out = [], seen = new Map();
  list.forEach((x) => {
    const k = key(x);
    const g = anywhere ? seen.get(k) : out[out.length - 1];
    if (g && g.k === k) g.items.push(x);
    else { const n = { k, items: [x] }; out.push(n); seen.set(k, n); }
  });
  return out;
}
/** Soort melding: crit, warn, unk, ok (hersteld), info, sec (beveiliging: geweigerd of IDS), other (bevestigd, onderhoud) */
function alertCat(a) {
  if (a.kind === 'sec' || a.kind === 'ids') return 'sec';
  if (a.kind === 'info') return 'info';
  if (a.kind === 'ack' || a.kind === 'maint') return 'other';
  if (/^\S+ (HERSTELD|RECOVERED)/.test(a.title)) return 'ok';
  return ['ok', 'warn', 'crit', 'unk'][+a.status] || 'other';
}
// [filter, soort voor kleur en vorm (al2), naam]
const ALERT_CATS = [['crit', 'crit', 'Critical'], ['warn', 'warn', 'Warning'], ['unk', 'unk', () => L('Onbekend')], ['ok', 'ok', () => L('Hersteld')], ['info', 'ichk', 'Info'], ['isys', 'isys', () => L('Systeeminfo')], ['sec', 'sec', () => L('Beveiliging')]];
const catLabel = (k) => { const w = ALERT_CATS.find((c) => c[0] === k)[2]; return typeof w === 'function' ? w() : w; };
// 'noinfo' (1.84.0, 1.207.0): alles behalve de systeeminfo; 'isys' alleen systeeminfo, 'info' alleen info van een check
// 'open' (1.207.0) gaat over een storing, niet over één melding: dat regelt renderAlerts
function alertMatch(f, a) {
  if (f === 'all') return true;
  if (f === 'noinfo') return !alertIsSys(a);
  if (f === 'isys') return alertIsSys(a);
  if (f === 'open') return false;
  if (f === 'problems') return ['crit', 'warn', 'unk'].includes(alertCat(a));
  if (f === 'info') return alertCat(a) === 'info' && !alertIsSys(a);
  if (f === 'ok') return alertCat(a) === 'ok' && !alertIsSys(a) && a.kind !== 'authfail';
  return alertCat(a) === f;
}

/** Tellers (vandaag) en kerncijfers bovenaan de pagina Meldingen */
function renderAlertTop(d) {
  // Zonder info: ook de tegels tellen de ℹ️-systeeminfo niet mee (de tellers per soort wel, die tonen elke soort)
  const noInfo = alertFilter === 'noinfo';
  const base = noInfo ? d.alerts.filter((a) => alertMatch('noinfo', a)) : d.alerts;
  const today = d.alerts.filter((a) => a.created_at.startsWith(d.today));
  const todayB = base.filter((a) => a.created_at.startsWith(d.today));
  const n = (f) => today.filter((a) => alertMatch(f, a)).length;
  $('#alerts-counts').innerHTML = ALERT_CATS.map(([k, k2]) => {
    const c = n(k);
    return `<button type="button" class="hc al2-hc k-${k2} ${alertFilter === k ? 'on' : ''} ${c ? '' : 'zero'}" data-af="${k}" title="${esc(L('Klik om alleen deze te tonen'))}"><span class="al2-shape" aria-hidden="true">${AL2_SHAPE[k2]}</span><b>${c}</b> <span class="hc-w">${catLabel(k)}</span></button>`;
  }).join('');
  const y = new Date(d.today + 'T12:00:00'); y.setDate(y.getDate() - 1);
  const yday = y.toISOString().slice(0, 10);
  const nY = base.filter((a) => a.created_at.startsWith(yday)).length;
  const diff = nY ? Math.round((todayB.length - nY) / nY * 100) : null;
  // Per uur vandaag (tot nu)
  const hours = new Array(new Date().getHours() + 1).fill(0);
  todayB.forEach((a) => { const h = +a.created_at.slice(11, 13); if (h < hours.length) hours[h]++; });
  const max = Math.max(1, ...hours);
  let path = '';
  hours.forEach((v, i) => { path += `${path ? 'L' : 'M'}${(hours.length > 1 ? i / (hours.length - 1) * 100 : 50).toFixed(1)},${(24 - v / max * 20).toFixed(1)}`; });
  // Laatste 24 uur: niet verzonden en drukste server
  const since = new Date(Date.now() - 86400000);
  const pad = (x) => String(x).padStart(2, '0');
  const sinceStr = `${since.getFullYear()}-${pad(since.getMonth() + 1)}-${pad(since.getDate())} ${pad(since.getHours())}:${pad(since.getMinutes())}`;
  const last24 = base.filter((a) => a.created_at >= sinceStr);
  const failed = last24.filter((a) => splitSent(a.message)[1] || (a.email_ok !== null && a.email_ok !== undefined && !+a.email_ok) || (a.webhook_ok !== null && a.webhook_ok !== undefined && !+a.webhook_ok));
  const reasons = {};
  failed.forEach((a) => { const r = splitSent(a.message)[1].split(';')[0].trim(); if (r) reasons[r] = (reasons[r] || 0) + 1; });
  const topReason = Object.entries(reasons).sort((p, q) => q[1] - p[1])[0];
  const hosts = {};
  last24.forEach((a) => { const h = alertCols(a).host; hosts[h] = (hosts[h] || 0) + 1; });
  const busy = Object.entries(hosts).sort((p, q) => q[1] - p[1])[0];
  const open = state.health ? state.health.open_problems : 0;
  const lastA = base[0];
  $('#alerts-kpis').innerHTML = `
    <div class="kpi kpi-main" title="${esc(L('Per uur vandaag'))}"><div class="kpi-txt"><div class="kpi-lbl">${noInfo ? L('Meldingen vandaag, zonder systeeminfo') : L('Meldingen vandaag')}</div><div class="kpi-val">${todayB.length}</div>
      <div class="kpi-sub">${L('gisteren {n}', { n: nY })}${diff !== null && diff !== 0 ? ` · <b class="${diff > 0 ? 'danger-txt' : 'good-txt'}">${diff > 0 ? '+' : ''}${diff}%</b>` : ''}</div></div>
      ${hours.length > 1 ? `<svg class="kpi-spark kpi-spark-big" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${path}L100,26L0,26Z" fill="var(--primary)" opacity=".14"/><path d="${path}" fill="none" stroke="var(--primary)" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>` : ''}</div>
    <div class="kpi ${open ? 'bad' : ''}"><div class="kpi-lbl">${L('Nu open')}</div><div class="kpi-val">${open}</div>
      <div class="kpi-sub">${open ? L('storingen') + ' · ' : ''}<button type="button" class="linkbtn" data-go="dashboard">${L('naar het dashboard')}</button></div></div>
    <div class="kpi ${failed.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Niet verzonden 24 u')}</div><div class="kpi-val">${failed.length}</div>
      <div class="kpi-sub" title="${esc(topReason ? topReason[0] : '')}">${topReason ? esc(topReason[0]) : L('alles verzonden')}${failed.length && isAdmin() ? ` · <button type="button" class="linkbtn" data-go="settings/channels">${L('kanalen')}</button>` : ''}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Drukste server 24 u')}</div><div class="kpi-val kpi-val-txt">${busy ? esc(busy[0]) : '–'}</div>
      <div class="kpi-sub">${busy ? (busy[1] === 1 ? L('1 melding') : L('{n} meldingen', { n: busy[1] })) : L('geen meldingen')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Laatste melding')}</div><div class="kpi-val kpi-val-txt">${lastA ? esc(lastA.created_at.startsWith(d.today) ? lastA.created_at.slice(11, 16) : fmtDateTime(lastA.created_at)) : '–'}</div>
      <div class="kpi-sub">${lastA ? esc(lastA.title) : ''}</div></div>`;
  // Chips met aantallen (alle dagen) en de keuzelijst met servers
  const chips = [['all', L('Alles')], ['noinfo', L('Zonder systeeminfo')], ['open', L('Nog open')], ['problems', L('Storingen')], ['ok', L('Hersteld')], ['info', 'Info'], ['isys', L('Systeeminfo')], ['sec', L('Beveiliging')]];
  const nOpen = alertEpisodesCache.filter((it) => it.open).length;
  $('#alerts-filter').innerHTML = chips.map(([k, l]) => `<button type="button" class="dchip ${alertFilter === k ? 'on' : ''}${k === 'open' && nOpen ? ' al2-hot' : ''}" data-af="${k}">${l} <b>${k === 'open' ? nOpen : d.alerts.filter((a) => alertMatch(k, a)).length}</b></button>`).join('')
    + (['crit', 'warn', 'unk'].includes(alertFilter) ? `<button type="button" class="dchip on" data-af="${alertFilter}" title="${esc(L('Filter opheffen'))}">${catLabel(alertFilter)} ✕</button>` : '');
  const sel = $('#alerts-host'), cur = sel.value;
  const names = [...new Set(d.alerts.map((a) => alertCols(a).host))].sort((p, q) => p.localeCompare(q, LANG, { sensitivity: 'base' }));
  sel.innerHTML = `<option value="">${L('Alle servers')}</option>` + names.map((h) => `<option value="${esc(h)}">${esc(h)}</option>`).join('');
  sel.value = names.includes(cur) ? cur : '';
}

// ---------------------------------------------------------------- 1.207.0: meldingen in een nieuwe vorm
/* Eén regel per storing (de storing en zijn herstel samen, met de duur), "Nu open" bovenaan, kleur per soort die van links
   naar rechts uitloopt, een golf zolang een storing open is, een vorm naast de kleur, en een zijpaneel met details. */
const AL2_SHAPE = { crit: '⬣', warn: '▲', unk: '?', ok: '✓', ichk: 'ⓘ', isys: 'ⓘ', sec: '⛨', oth: '•' };
const AL2_PROBLEM = ['crit', 'warn', 'unk'];
const al2Rank = (k) => ({ crit: 3, warn: 2, unk: 1 }[k] || 0);
/** Systeeminfo: info zonder check (RzSense bijgewerkt, agent bijgewerkt …) en ingelogd */
const alertIsSys = (a) => (a.kind === 'info' && !a.check_id && !a.log_watch) || a.kind === 'auth';
/** Soort voor de kleur: crit, warn, unk, ok, ichk (info van een check), isys (systeeminfo), sec (beveiliging), oth (bevestigd, onderhoud, flapt) */
function alertKind2(a) {
  if (a.kind === 'auth') return 'isys';
  if (a.kind === 'authfail') return 'sec';
  if (/^\S+ (STABIEL|FLAPPEND|STABLE|FLAPPING)/.test(a.title || '')) return 'oth';
  const c = alertCat(a);
  if (c === 'info') return a.check_id || a.log_watch ? 'ichk' : 'isys';
  return c === 'other' ? 'oth' : c;
}
function al2Label(k, a) {
  if (k === 'oth') return alertStatusText(a).replace(EMOJI_LEAD, '').trim();
  return { crit: 'Critical', warn: 'Warning', unk: L('Onbekend'), ok: L('Hersteld'), ichk: 'Info', isys: L('Systeeminfo'), sec: L('Beveiliging') }[k];
}
const al2Plain = (a) => !['info', 'sec', 'ids', 'ack', 'maint', 'auth', 'authfail'].includes(a.kind || '') && !/^\S+ (STABIEL|FLAPPEND|STABLE|FLAPPING)/.test(a.title || '');
const al2Ts = (dt) => new Date(String(dt).replace(' ', 'T')).getTime();
function al2Dur(ms) {
  const m = Math.max(1, Math.round(ms / 60000));
  if (m < 60) return L('{n} min', { n: m });
  const h = Math.floor(m / 60), r = m % 60;
  if (h < 48) return r ? L('{h} u {m} min', { h, m: r }) : L('{h} u', { h });
  return L('{n} dagen', { n: Math.round(h / 24) });
}
/** Wanneer: vandaag hoe lang geleden, anders de tijd */
function al2When(dt, today) {
  if (!dt.startsWith(today)) return esc(dt.slice(11, 16));
  const ms = Date.now() - al2Ts(dt);
  return ms < 60000 ? L('nu') : esc(al2Dur(ms));
}
/** Bericht in delen: de kop (eerste regel, zonder drempel) en chips (drempel) */
function al2Parts(msg) {
  let head = String(msg || '').split('\n')[0].trim();
  const chips = [];
  head = head.replace(/\s*\(((?:drempel|threshold)[^)]*)\)/gi, (m, x) => { chips.push(x.trim()); return ''; }).trim();
  return { head, chips };
}
/**
 * Meldingen → regels. Een storing (critical/warning/onbekend) van een check en de meldingen erna tot het herstel worden één
 * regel (episode); het herstel hoort bij die regel. De rest blijft een losse regel. Nieuwste (op begintijd) eerst.
 */
function alertEpisodes(all) {
  const asc = [...all].sort((x, y) => (x.created_at < y.created_at ? -1 : x.created_at > y.created_at ? 1 : x.id - y.id));
  const open = new Map(), items = [];
  // Storing: van een check, of (1.212.0) van een vaste zoekactie op de logs per server
  const epKey = (a) => (a.check_id ? 'c' + a.check_id : a.log_watch ? 'l' + a.log_watch : null);
  for (const a of asc) {
    const k = alertKind2(a), key = epKey(a);
    if (key && al2Plain(a) && AL2_PROBLEM.includes(k)) {
      let ep = open.get(key);
      if (!ep) { ep = { ep: true, k, first: a, last: a, alerts: [a], rec: null }; open.set(key, ep); items.push(ep); }
      else { ep.alerts.push(a); ep.last = a; if (al2Rank(k) > al2Rank(ep.k)) ep.k = k; }
    } else if (key && al2Plain(a) && k === 'ok' && open.has(key)) {
      const ep = open.get(key);
      ep.rec = a; ep.alerts.push(a);
      open.delete(key);
    } else items.push({ ep: false, k, first: a, last: a, alerts: [a], rec: null });
  }
  // Nog open? Dat zegt de check zelf (een herstel kan buiten de geladen dagen vallen, of niet gemeld zijn)
  const checks = new Map();
  ((state && state.hosts) || []).forEach((h) => h.checks.forEach((c) => checks.set(+c.id, c)));
  items.forEach((it) => {
    if (!it.ep || it.rec) return;
    if (it.first.log_watch) { it.open = it.alerts.some((x) => x.lw_open); it.nowOk = !it.open; return; }   // 1.212.0: dat weet RzSense
    const c = checks.get(+it.first.check_id);
    it.open = !!c && +c.status !== 0;
    it.nowOk = !c || +c.status === 0;
  });
  return items.reverse();
}
/** Kanalen van een regel: ✕ als er iets niet aankwam, ✓ als er iets verstuurd is */
function al2Channels(it) {
  let bad = '', sent = false;
  it.alerts.forEach((a) => {
    const why = splitSent(a.message)[1];
    if (why || (a.email_ok !== null && a.email_ok !== undefined && !+a.email_ok) || (a.webhook_ok !== null && a.webhook_ok !== undefined && !+a.webhook_ok)) bad = why || L('niet alles verzonden');
    if (+a.sent_ok || +a.email_ok || +a.webhook_ok) sent = true;
  });
  if (bad) return `<span class="al2-ch bad" title="${esc(L('Niet verzonden') + ': ' + bad)}">✕</span>`;
  return sent ? `<span class="al2-ch ok" title="${esc(L('Verzonden'))}">✓</span>` : '';
}
function al2Life(it) {
  if (!it.ep) return '';
  const t0 = al2Ts(it.first.created_at);
  if (it.rec) {
    const ms = al2Ts(it.rec.created_at) - t0;
    return `<span class="al2-life"><span class="mono muted">${esc(it.first.created_at.slice(11, 16))}</span>→<b class="al2-okt">✓ ${esc(it.rec.created_at.slice(11, 16))}</b>
      <span class="al2-bar" style="--w:${Math.min(100, Math.max(6, ms / 144000))}%"><i></i></span><span class="muted">${esc(al2Dur(ms))}</span></span>`;
  }
  if (it.open) {
    const ms = Date.now() - t0, seen = al2Seen(it);
    return `<span class="al2-life"><span class="al2-bar" style="--w:${Math.min(100, Math.max(6, ms / 144000))}%"><i></i></span><span class="al2-open">${esc(L('nog open · {t}', { t: al2Dur(ms) }))}</span>${seen ? `<span class="al2-seen-ic" title="${esc(al2SeenText(seen))}" aria-label="${esc(al2SeenText(seen))}">👁</span>` : ''}</span>`;
  }
  return `<span class="al2-life"><b class="al2-okt">✓ ${esc(L('nu OK'))}</b></span>`;
}
const AL2_ITEMS = new Map();   // id van de eerste melding → regel (voor het zijpaneel)
/**
 * 1.208.0: bekeken? Een open storing waarop iemand klikte, golft niet meer (voor iedereen). Komt er daarna een
 * ergere melding bij (warning → critical) die nog niemand zag, dan golft hij weer.
 */
function al2Seen(it) {
  const prob = it.alerts.filter((a) => a !== it.rec);
  const seen = prob.filter((a) => a.seen_at);
  if (!seen.length) return null;
  const max = Math.max(...seen.map((a) => al2Rank(alertKind2(a))));
  if (prob.some((a) => !a.seen_at && al2Rank(alertKind2(a)) > max)) return null;
  return seen.reduce((p, a) => (a.seen_at > p.seen_at ? a : p));   // de laatste keer bekeken: door wie en wanneer
}
/** Klik op een open storing: als bekeken markeren (beheerder en gebruiker; alleen-lezen alleen in deze pagina) */
function al2MarkSeen(it) {
  if (!it.open || al2Seen(it)) return;
  const todo = it.alerts.filter((a) => a !== it.rec && !a.seen_at);
  const now = new Date(), pad = (x) => String(x).padStart(2, '0');
  const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  todo.forEach((a) => { a.seen_at = stamp; a.seen_by = a.seen_by || ''; });
  // Uit Nu open naar de dag waarop hij begon (1.209.0)
  try { renderAlerts(); } catch (ex) { $$(`#alerts .al2-row[data-aid="${it.first.id}"]`).forEach((r) => { r.classList.remove('al2-wave'); r.classList.add('al2-seen'); }); }
  if (!(isAdmin() || isUser())) return;
  api('alert_seen', { ids: todo.map((a) => a.id) }).then((r) => {
    todo.forEach((a) => { a.seen_by = r.by; a.seen_at = r.at; });
    if (al2Sel === String(it.first.id)) { const s = $('#al2-seenby'); if (s) s.textContent = al2SeenText(al2Seen(it)); }
  }).catch(() => { /* lukt het niet, dan golft hij bij de volgende keer weer: niet erg */ });
}
const al2SeenText = (a) => (a ? (a.seen_by ? L('gezien door {u} om {t}', { u: a.seen_by, t: a.seen_at.slice(11, 16) }) : L('gezien')) : '');
function al2Row(it, today, sub = false) {
  const a = it.first, k = it.k, last = it.ep ? it.alerts.filter((x) => x !== it.rec).pop() : a;
  AL2_ITEMS.set(String(a.id), it);
  const { host, check } = alertCols(a);
  const { head, chips } = al2Parts(splitSent(last.message)[0]);
  const quiet = k === 'isys' || k === 'oth' || (k === 'ok' && !it.ep);
  const seen = it.open && al2Seen(it);
  const cls = ['al2-row', 'al2-tint', 'k-' + k, it.open && (k === 'crit' || k === 'warn') ? (seen ? 'al2-seen' : 'al2-wave') : '', quiet ? 'al2-quiet' : '', it.ep && !it.open ? 'al2-done' : '', sub ? 'al2-subrow' : ''].filter(Boolean).join(' ');
  return `<div class="${cls}" role="row" tabindex="0" data-aid="${a.id}">
    <span class="al2-when" title="${esc(fmtDateTime(a.created_at))}">${al2When(a.created_at, today)}</span>
    <span><span class="al2-badge"><span class="al2-shape" aria-hidden="true">${AL2_SHAPE[k]}</span>${esc(al2Label(k, a))}</span></span>
    <span class="al2-src">${esc(host)}${check && check !== '–' ? `<span class="al2-sep">›</span><span class="al2-chk">${esc(check)}</span>` : ''}</span>
    <span class="al2-what"><b>${esc(head || a.title)}</b>${chips.map((c) => `<span class="al2-vchip">${esc(c)}</span>`).join('')}${it.ep && it.alerts.length - (it.rec ? 1 : 0) > 1 ? `<span class="al2-vchip" title="${esc(L('Meldingen in deze storing'))}">${it.alerts.length - (it.rec ? 1 : 0)}×</span>` : ''}${it.rep ? `<button type="button" class="al2-rep" data-al2-rep="${a.id}">${esc(it.rep)}</button>` : ''}${alertBlock(a)}</span>
    <span class="al2-lifec">${al2Life(it)}</span>
    <span class="al2-chc">${al2Channels(it)}</span></div>`;
}
function al2Head() {
  const h = (k, t, cls = '', title = '') => `<span class="al2-sortable${cls}${alertSort.key === k ? ' sorted' : ''}" data-sort="${k}" title="${esc(title || L('Sorteren op {k}', { k: t.toLowerCase() }))}">${t}${alertSort.key === k ? (alertSort.dir > 0 ? ' ▲' : ' ▼') : ''}</span>`;
  return `<div class="al2-row al2-headrow" role="row">${h('time', L('Wanneer'))}${h('status', 'Status')}${h('server', L('Bron'))}${h('message', L('Wat'))}<span class="al2-lifec">${L('Verloop')}</span>${h('channels', '📨', ' al2-chc', L('Kanalen'))}</div>`;
}
/** Regels van één dag (of van Nu open), met herhalingen achter elkaar gebundeld (4× in 2 u) */
function al2List(items, today, layout) {
  const groups = [];
  items.forEach((it) => {
    const key = it.ep ? null : `${it.k}|${it.first.title}|${al2Parts(splitSent(it.first.message)[0]).head}`;
    const g = groups[groups.length - 1];
    if (key && g && g.key === key) g.items.push(it); else groups.push({ key, items: [it] });
  });
  return `<div class="al2-list${layout === 'l1nes' ? '' : ' al2-blox'}"><div class="al2-grid" role="table">${al2Head()}${groups.map((g) => {
    const [first, ...rest] = g.items;
    if (!rest.length) return al2Row(first, today);
    const span = al2Ts(first.first.created_at) - al2Ts(rest[rest.length - 1].first.created_at);
    first.rep = L('{n}× in {t}', { n: g.items.length, t: al2Dur(span) });
    const key = 'b' + rest[rest.length - 1].first.id;
    return al2Row(first, today) + `<div class="al2-sub${alertBundles.has(key) ? '' : ' closed'}" data-b="${key}" data-bfor="${first.first.id}">${rest.map((x) => al2Row(x, today, true)).join('')}</div>`;
  }).join('')}</div></div>`;
}
/** Dagkop: aantallen per soort, en hoe lang er storing was */
function al2DayCounts(items) {
  const n = {};
  let down = 0;
  items.forEach((it) => {
    n[it.k] = (n[it.k] || 0) + 1;
    if (it.ep && it.rec) down += al2Ts(it.rec.created_at) - al2Ts(it.first.created_at);
  });
  const out = ['crit', 'warn', 'unk', 'ok', 'ichk', 'isys', 'sec', 'oth'].filter((k) => n[k])
    .map((k) => `<span class="al2-dc k-${k}"><b>${n[k]}</b> ${esc((k === 'oth' ? L('overig') : al2Label(k, {})).toLowerCase())}</span>`);
  if (down) out.push(`<span class="al2-dc">${esc(L('{t} storing', { t: al2Dur(down) }))}</span>`);
  return out.join('');
}

