// ---------------------------------------------------------------- Dashboard (1.134.0)
// De startpagina: alles samen. Servers en checks komen uit `state` (live), de rest uit api('home') (hooguit eens per minuut).
const HM_PERIODS = [['7d', () => L('7 dagen')], ['30d', () => L('30 dagen')], ['90d', () => L('90 dagen')]];
let hmPeriod = uiGet('hm_period', 'rzmon-hm-period', '7d');
let hmData = null;
let hmAt = 0;
const HM_ST = { ok: () => L('In orde'), warn: () => L('Aandacht'), crit: () => L('Storing'), unk: () => L('Onbekend') };
// Vorm naast kleur: rondje = in orde, vierkant = aandacht, driehoek = storing (ook zonder kleur te lezen)
const hmShape = (st, size = 10) => `<svg class="hm-shape ${st}" width="${size}" height="${size}" viewBox="0 0 10 10" role="img" aria-label="${esc(HM_ST[st]())}">${
  st === 'ok' ? '<circle cx="5" cy="5" r="5"/>' : st === 'warn' ? '<rect width="10" height="10" rx="2"/>' : st === 'unk' ? '<circle cx="5" cy="5" r="3.8" fill="none" stroke-width="2.4"/>' : '<path d="M5 0L10 10H0Z"/>'}</svg>`;
const hmStOf = (s) => (s === 2 ? 'crit' : s === 1 ? 'warn' : 'ok');
const hmNum = (n) => Number(n || 0).toLocaleString(LOCALE);

async function loadHome(fresh = false) {
  const cc = dashMode() === 'cc';   // 1.154.0: het Command center heeft ook "Vandaag"
  const per = cc ? CC_PERIODS : HM_PERIODS, cur = cc ? ccPeriod : hmPeriod;
  $('#hm-period').innerHTML = per.map(([k, l]) => `<button type="button" class="seg-btn ${k === cur ? 'active' : ''}" data-hmp="${k}">${esc(l())}</button>`).join('');
  $$('[data-hmp]').forEach((b) => (b.onclick = () => {
    if (dashMode() === 'cc') { ccPeriod = b.dataset.hmp; uiSet('cc_period', 'rzmon-cc-period', ccPeriod); }
    else { hmPeriod = b.dataset.hmp; uiSet('hm_period', 'rzmon-hm-period', hmPeriod); }
    loadHome();
  }));
  if ($('#hm-tools')) $('#hm-tools').innerHTML = ccTools();
  if ($('#hm-live')) $('#hm-live').innerHTML = livePill();   // 1.157.3: rechtsboven op de pagina
  const q = { period: cc ? (['30d', '90d'].includes(ccPeriod) ? ccPeriod : '7d') : hmPeriod, ...(fresh ? { fresh: 1 } : {}) };
  if (cc) Object.assign(q, { cc: 1, ccp: ccPeriod, ...(ccSeenAt ? { seen: ccSeenAt } : {}) });
  try { hmData = await api('home', null, q); hmAt = Date.now(); } catch (ex) { $('#home').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  $('#hm-period').hidden = !hmData.full && !cc;
  renderHome();
}

/** Dagreeks {datum: waarde} → [waarden] (zonder lege dagen) */
const hmVals = (series) => Object.values(series || {}).filter((v) => v !== null && v !== undefined).map(Number);

/** Klein lijntje onder een tegel */
function hmSpark(vals, cls) {
  if (vals.length < 2) return '<div class="hm-spark-empty"></div>';
  const W = 220, H = 40, mn = Math.min(...vals), mx = Math.max(...vals), r = mx - mn || 1;
  const pts = vals.map((v, i) => [i * W / (vals.length - 1), 4 + (H - 8) * (1 - (v - mn) / r)]);
  const line = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('');
  return `<svg class="hm-spark ${cls}" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" aria-hidden="true"><path class="a" d="${line}L${W} ${H}L0 ${H}Z"/><path class="l" d="${line}"/></svg>`;
}

/** Wat de servers en checks nu doen (uit state) */
function hmLive() {
  const checks = state.hosts.flatMap((h) => h.checks).filter((c) => +c.enabled && !c.maintenance);
  const n = [0, 0, 0, 0];
  checks.forEach((c) => n[Math.min(3, +c.status)]++);
  return { checks, n, total: checks.length };
}

function renderHome() {
  if (!hmData || !state || !state.hosts) return;
  if (dashMode() === 'cc') return hmData.cc ? renderCC() : loadHome();   // 1.154.0
  $('.hm-title').textContent = L('Zo staat het ervoor');
  const d = hmData, live = hmLive();
  $('#hm-sub').textContent = L('Monitoring, beveiliging, kwetsbaarheden en netwerk op één plek') + ' · '
    + L('bijgewerkt {t}', { t: new Date(hmAt).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) });
  const score = d.full ? d.score : (state.health ? state.health.score : 100);
  const todo = d.full ? d.todo : live.checks.filter((c) => +c.status === 2 || +c.status === 1).sort((a, b) => b.status - a.status)
    .map((c) => ({ sev: +c.status === 2 ? 2 : 1, text: `${c.name} – ${(state.hosts.find((h) => +h.id === +c.host_id) || {}).name || ''}`, go: 'check:' + c.id }));
  const open = todo.filter((t) => t.sev >= 1).length;
  const scoreCls = score >= 90 ? 'good' : score >= 60 ? 'warn' : 'bad';
  const prevAvail = d.full && d.avail.prev !== null ? d.avail.now - d.avail.prev : null;

  // Tegels (1.135.0, zoals het ontwerp): Hosts, Meldingen, Kritieke CVE en Agents; elk een groot getal, de status en een lijntje
  const tiles = [];
  const hl = state.health || {};
  const down = (hl.hosts_total || 0) - (hl.hosts_up || 0);
  tiles.push({ go: 'dashboard', label: L('Hosts'), st: down ? 'crit' : 'ok', big: hmNum(hl.hosts_up || 0), unit: L('/ {n} online', { n: hl.hosts_total || 0 }),
    sub: down ? L('{n} onbereikbaar', { n: down }) : L('alles bereikbaar'), spark: d.full ? hmSpark(hmVals(d.avail.series), 's1') : '' });
  const probs = live.n[1] + live.n[2];
  tiles.push({ go: 'alerts', label: L('Meldingen'), st: live.n[2] ? 'crit' : live.n[1] ? 'warn' : 'ok', big: hmNum(probs), unit: L('open problemen'),
    sub: probs ? [live.n[1] && L('{n} aandacht', { n: live.n[1] }), live.n[2] && L('{n} storing', { n: live.n[2] })].filter(Boolean).join(' · ') : L('/ {n} checks OK', { n: live.total }).replace(/^\/ /, ''),
    spark: d.full ? hmSpark(hmVals(d.ids.series.crit).map((v, i) => v + (hmVals(d.ids.series.warn)[i] || 0)), 's2') : '' });
  if (d.vulns) {
    const v = d.vulns;
    tiles.push({ go: 'vulns', label: L('Kritieke CVE'), st: v.sev[4] ? 'crit' : v.sev[3] ? 'warn' : 'ok', big: hmNum(v.sev[4]), unit: v.kev ? L('{n} in KEV', { n: v.kev }) : L('geen in KEV'),
      sub: L('{n} open · {f} met een update', { n: hmNum(v.total), f: hmNum(v.fix) }), spark: hmSpark(hmVals(v.series), 'crit') });
  }
  const agents = state.hosts.filter((h) => h.mode === 'agent' && +h.enabled !== 0);
  if (agents.length) {
    const now = Date.now() / 1000;
    const stil = agents.filter((h) => !h.contact || !h.contact.seen || now - h.contact.seen > h.contact.stale).length;
    const old = agents.filter((h) => h.agent_version && state.agent_latest && verCmp(h.agent_version, state.agent_latest) < 0).length;
    tiles.push({ go: isAdmin() ? 'settings/agents' : 'dashboard', label: L('Agents'), st: stil ? 'warn' : 'ok', big: `${agents.length - stil}<small>/${agents.length}</small>`, unit: L('rapporteren'),
      sub: [stil ? L('{n} stil', { n: stil }) : L('allemaal actief'), old && L('{n} op een oudere versie', { n: old })].filter(Boolean).join(' · '), spark: '' });
  }

  const html = [];
  // 1.144.0: eerst één zin hoe het ervoor staat, dan wat je nu moet doen (incidenten en aandachtspunten op urgentie)
  const now = d.full && d.now ? d.now : todo.map((t) => ({ kind: 'todo', sev: t.sev, text: t.text, urgency: t.sev === 2 ? 50 : t.sev === 1 ? 25 : 5, why: [], go: t.go }));
  const nowOpen = now.filter((t) => t.sev >= 1);
  const urgentN = now.filter((t) => t.urgency >= 60).length;
  const incN = now.filter((t) => t.kind === 'inc').length;
  const probHosts = hmProblemHosts(d);
  if ($('#inc-badge') && d.full && d.now) {   // teller op het tabblad Incidenten: open incidenten (nog zonder eigenaar)
    const nOpen = d.now.filter((t) => t.kind === 'inc' && t.status === 'open').length;
    $('#inc-badge').hidden = !nOpen;
    $('#inc-badge').textContent = nOpen || '';
  }
  const enabledHosts = state.hosts.filter((h) => +h.enabled !== 0).length;
  const sentence = !nowOpen.length && !probHosts.length
    ? L('Alles in orde: {h} servers en {c} checks zonder problemen.', { h: enabledHosts, c: live.total })
    : [nowOpen.length ? (nowOpen.length === 1 ? L('1 ding om nu te doen') : L('{n} dingen om nu te doen', { n: nowOpen.length })) + (urgentN ? ' (' + L('{n} dringend', { n: urgentN }) + ')' : '') : '',
      incN ? (incN === 1 ? L('1 open incident') : L('{n} open incidenten', { n: incN })) : '',
      probHosts.length ? (probHosts.length === 1 ? L('1 server vraagt aandacht') : L('{n} servers vragen aandacht', { n: probHosts.length })) : ''].filter(Boolean).join(' · ') + '.';
  html.push(`<section class="hm-status ${scoreCls}" aria-label="${esc(L('Zo staat het ervoor'))}">
    <span class="hm-status-score" title="${esc(L('Totaalscore'))}">${score}</span>
    <div><div class="hm-status-txt">${esc(sentence)}</div>
      ${prevAvail !== null && Math.abs(prevAvail) >= 0.01 ? `<div class="hm-score-sub">${esc(L('Bereikbaarheid {d} t.o.v. de vorige periode', { d: (prevAvail > 0 ? '↑ ' : '↓ ') + dec(Math.abs(prevAvail).toFixed(2)) + '%' }))}</div>` : ''}</div>
  </section>`);
  html.push(hmNow(now, d.full));
  if (probHosts.length) html.push(hmServers(probHosts, L('Servers die aandacht vragen'), d));

  // De rest, compacter
  html.push(`<section class="hm-sum hm-sum-tiles" aria-label="${esc(L('Samenvatting'))}">
    ${tiles.map((t) => `<button type="button" class="hm-tile" data-hm-go="${t.go}">
      <span class="hm-tile-top"><span class="hm-tile-lbl">${esc(t.label)}</span><span class="hm-st ${t.st}">${hmShape(t.st)} ${esc(HM_ST[t.st]())}</span></span>
      <span class="hm-tile-val"><b>${t.big}</b> <span>${esc(t.unit)}</span></span>
      <span class="hm-tile-sub">${esc(t.sub)}</span>${t.spark}</button>`).join('')}
  </section>`);

  // 1.135.0: resourcegebruik van 24 uur en de recente incidenten (zoals het ontwerp)
  html.push(`<div class="hm-row">${hmResChart(d.resources)}${hmIncidents(d)}</div>`);

  // Netwerkkaart en de servers zonder problemen, compact
  html.push(`<div class="hm-row">${hmMap()}${hmAttacks()}</div>`);   // 1.149.0: met de aanvallen van vandaag
  html.push(hmServers(hmAllHosts().filter((x) => !probHosts.some((p) => p.h.id === x.h.id)), L('Overige servers'), d, true));

  // Grafieken
  if (d.full) {
    html.push(`<div class="hm-charts">${hmAvailChart(d)}${hmIdsChart(d)}${d.vulns ? hmVulnCard(d.vulns) : ''}</div>`);
  }
  html.push(`<div class="hm-row">${d.net ? hmNetCard(d.net) : ''}${hmTimeline(d.events)}</div>`);
  $('#home').innerHTML = html.join('');
  if ($('#hm-atk')) hmAtkWire($('#hm-atk'));
  $$('[data-hm-risk]', $('#home')).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); e.preventDefault(); exposureDialog(+b.dataset.hmRisk); }));
  $$('[data-hm-go]', $('#home')).forEach((b) => (b.onclick = () => {
    const go = b.dataset.hmGo;
    if (go.startsWith('check:')) checkInfo(go.slice(6));
    else if (go.startsWith('incident:')) { showTab('incidents'); caseDialog(+go.slice(9)); }
    else showTab(go);
  }));
}

// ---------------------------------------------------------------- 1.154.0: tweede dashboard "Command center"
// Per gebruiker te kiezen (accountmenu of de knop bovenaan), in te delen (kaarten verbergen en verslepen) en met een
// TV-modus voor een scherm aan de muur. Volgt het thema (licht of donker), net als de Live Attack Flow.
const dashMode = () => (isCC() ? 'cc' : 'classic');   // 1.158.0: volgt de lay-out (CC = Command center, anders klassiek)
const CC_PERIODS = [['today', () => L('Vandaag')], ['7d', () => L('7 dagen')], ['30d', () => L('30 dagen')], ['90d', () => L('90 dagen')]];
let ccPeriod = (() => { const p = uiGet('cc_period', 'rzmon-cc-period', 'today'); return CC_PERIODS.some(([k]) => k === p) ? p : 'today'; })();
const ccSeenAt = PREFS.dash_seen || '';   // het vorige bezoek (bij het laden van de pagina), voor "sinds je laatste bezoek"
let ccSeenSaved = false, ccEdit = false, ccAtk = null, ccAtkAt = 0, ccDrag = null;
const ccNowStr = () => { const d = new Date(), p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`; };

/** De kaarten: sleutel, breedte (van 12), titel en of hij er is (alleen-lezen ziet geen analyse, CVE's of netwerk) */
const CC_W = [
  ['overview', 12, () => L('Overzicht')], ['security', 12, () => L('Beveiliging in één oogopslag')], ['toppers', 12, () => L('De toppers')],
  ['now', 4, () => L('Nu doen')], ['attacks', 4, () => L('Live aanvallen')], ['logins', 4, () => L('Wie logt er in')],
  ['monitoring', 12, () => L('Monitoring')], ['servers', 12, () => L('Servers')],
  ['idschart', 4, () => L('IDS-gebeurtenissen')], ['avail', 4, () => L('Beschikbaarheid')], ['net', 4, () => L('Netwerk')],
  ['resources', 6, () => L('Resourcegebruik · 24 uur')], ['vulns', 6, () => L('Kwetsbaarheden (CVE)')], ['timeline', 12, () => L('Laatste gebeurtenissen')],
];
function ccLayout() {
  const l = PREFS.dash_layout || {}, keys = CC_W.map((w) => w[0]);
  const order = (l.order || []).filter((k) => keys.includes(k));
  keys.forEach((k) => { if (!order.includes(k)) order.splice(keys.indexOf(k), 0, k); });   // nieuwe kaarten op hun standaardplek
  return { order, hide: (l.hide || []).filter((k) => keys.includes(k)) };
}
const ccSaveLayout = (lay) => savePref('dash_layout', lay);

/** Verschil met de vorige periode: "↑ 38%" (rood als meer slecht is, groen als meer goed is) */
function ccDelta(n, prev, moreIsBad = true) {
  if (!prev && !n) return '';
  if (!prev) return `<span class="cc-d ${moreIsBad ? 'up-bad' : 'up-good'}">${esc(L('nieuw'))}</span>`;
  const p = Math.round((n - prev) / prev * 100);
  if (!p) return `<span class="cc-d">= </span>`;
  const bad = (p > 0) === moreIsBad;
  return `<span class="cc-d ${bad ? 'up-bad' : 'up-good'}">${p > 0 ? '↑' : '↓'} ${Math.abs(p)}%</span>`;
}
const ccPrevLbl = () => (ccPeriod === 'today' ? L('t.o.v. gisteren') : L('t.o.v. de periode ervoor'));
const ccTime = (at) => { const t = new Date(String(at).replace(' ', 'T')); return t.toDateString() === new Date().toDateString()
  ? t.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) : t.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) + ' ' + t.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }); };
const ccCard = (key, inner, cls = '') => `<section class="cc-card ${cls}" aria-label="${esc((CC_W.find((w) => w[0] === key) || [0, 0, () => key])[2]())}">${inner}</section>`;
const ccHead = (title, right = '') => `<div class="cc-h"><span class="cc-hl">${esc(title)}</span>${right}</div>`;
const ccGo = (go, label) => `<button type="button" class="cc-link" data-hm-go="${esc(go)}">${esc(label)} →</button>`;

/** 1.157.2: live-lampje zoals op Monitoring: ● Live · laatste check … (lampje grijs als de live-verbinding wegvalt) */
function livePill() {
  return `<span class="live-pill" title="${esc(L('Live: wijzigingen komen binnen een paar seconden binnen'))}"><span class="live-dot${document.querySelector('#live-dot.off') ? ' off' : ''}"></span> <b>${L('Live')}</b>${state.last_run ? ' · ' + esc(L('laatste check')) + ' ' + agoSpan(state.last_run) : ''}</span>`;
}
/** Kop van het dashboard: begroeting, sinds je laatste bezoek, periode, live, indeling, TV-modus en de keuze van het dashboard */
function ccTools() {
  const tv = document.body.classList.contains('tv');
  return `<div class="cc-tools">
    ${dashMode() === 'cc' ? `<button type="button" class="cc-tbtn ${ccEdit ? 'on' : ''}" data-cc-edit aria-pressed="${ccEdit}">✎ ${esc(ccEdit ? L('Klaar') : L('Indeling'))}</button>
      <button type="button" class="cc-tbtn ${tv ? 'on' : ''}" data-cc-tv aria-pressed="${tv}">▣ ${esc(L('TV-modus'))}</button>` : ''}</div>`;   // 1.168.1: de lay-out kies je alleen nog in het accountmenu en bij Mijn account
}
function ccSetDash(v) {
  if (v === dashMode()) return;
  return setLayout(v === 'cc' ? 'cc' : 'blox');   // 1.158.0: het dashboard volgt de lay-out
}
const markDash = () => $$('[data-dash-set]').forEach((b) => b.classList.toggle('active', b.dataset.dashSet === dashMode()));
/** TV-modus: schermvullend, zonder menu en kopbalk, grotere letters. Per apparaat onthouden; Esc of de knop zet hem uit. */
function ccTv(on) {
  document.body.classList.toggle('tv', on);
  try { on ? localStorage.setItem('rzmon-tv', '1') : localStorage.removeItem('rzmon-tv'); } catch (e) { /* alleen voor nu */ }
  if (on && document.documentElement.requestFullscreen && !document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {});
  if (!on && document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(() => {});
  if ($('#cc-tvexit')) $('#cc-tvexit').hidden = !on;
}

function renderCC() {
  const d = hmData, c = d.cc;
  if (!c) return;
  const name = ($('.acct-name') || {}).textContent || '';
  const hr = new Date().getHours();
  const hi = hr < 6 ? L('Goedenacht') : hr < 12 ? L('Goedemorgen') : hr < 18 ? L('Goedemiddag') : L('Goedenavond');
  $('.hm-title').textContent = 'Command center';
  const s = c.seen;
  const seenTxt = s ? (s.crit || s.alerts || s.blocks || s.logins
    ? L('Sinds je laatste bezoek ({t})', { t: ccTime(s.at) }) + ': ' + [s.crit ? L('{n} nieuwe kritieke meldingen', { n: s.crit }) : '', s.alerts ? L('{n} meldingen', { n: s.alerts }) : '',
      s.blocks ? L('{n} blokkades', { n: s.blocks }) : '', s.logins ? L('{n} SSH-logins', { n: s.logins }) : ''].filter(Boolean).join(' · ')
    : L('Niets nieuws sinds je laatste bezoek ({t})', { t: ccTime(s.at) })) : '';
  $('#hm-sub').innerHTML = `<b>${esc(hi)}${name && !/lokaal|local/i.test(name) ? ', ' + esc(name.trim()) : ''}</b>${seenTxt ? ' · ' + (s && s.crit ? `<span class="crit-txt">${esc(seenTxt)}</span>` : esc(seenTxt)) : ''}
    · <span class="muted">${esc(L('bijgewerkt {t}', { t: new Date(hmAt).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }))}</span>`;
  if (!ccSeenSaved && !prefsLocal) { ccSeenSaved = true; setTimeout(() => savePref('dash_seen', ccNowStr()), 4000); }
  if (!ccSeenSaved && prefsLocal) { ccSeenSaved = true; savePref('dash_seen', ccNowStr()); }
  const lay = ccLayout(), m = afMotion({ live: { motion: c.motion ?? 2 } });
  const R = { overview: ccOverview, security: ccSecurity, toppers: ccToppers, now: ccNow, attacks: ccAttacks, logins: ccLogins, monitoring: ccMonitoring, servers: ccServers,
    idschart: () => (d.full ? hmIdsChart(d) : ''), avail: () => (d.full ? hmAvailChart(d) : ''), net: () => (d.net ? hmNetCard(d.net) : ''), resources: () => hmResChart(d.resources),
    vulns: () => (d.vulns ? hmVulnCard(d.vulns) : ''), timeline: () => hmTimeline(d.events) };
  const html = [];
  lay.order.forEach((k) => {
    const w = CC_W.find((x) => x[0] === k), hidden = lay.hide.includes(k);
    if (hidden && !ccEdit) return;
    let inner = '';
    try { inner = R[k](d, c); } catch (ex) { inner = `<p class="form-error">${esc(ex.message)}</p>`; }
    if (!inner) return;
    html.push(`<div class="cc-w s${w[1]} ${hidden ? 'cc-hidden' : ''}" data-ccw="${k}" ${ccEdit ? 'draggable="true"' : ''}>
      ${ccEdit ? `<div class="cc-edit"><span class="cc-grip" aria-hidden="true">⠿</span><b>${esc(w[2]())}</b>
        <button type="button" data-cc-move="-1" aria-label="${esc(L('Omhoog'))}">↑</button><button type="button" data-cc-move="1" aria-label="${esc(L('Omlaag'))}">↓</button>
        <button type="button" data-cc-hide>${hidden ? '👁 ' + esc(L('Tonen')) : '⦸ ' + esc(L('Verbergen'))}</button></div>` : ''}${inner}</div>`);
  });
  $('#home').innerHTML = `<div class="cc m${m} ${ccEdit ? 'editing' : ''}">
    ${ccEdit ? `<div class="cc-editbar">${esc(L('Sleep de kaarten of gebruik ↑ ↓ om de volgorde te kiezen; verberg wat je niet nodig hebt. Dit geldt voor jouw account.'))}
      <button type="button" class="btn small-btn" data-cc-reset>${esc(L('Standaardindeling'))}</button><button type="button" class="btn small-btn primary" data-cc-edit>${esc(L('Klaar'))}</button></div>` : ''}
    <div class="cc-grid">${html.join('')}</div></div>`;
  ccWire();
  if (lay.order.includes('attacks') && !lay.hide.includes('attacks')) ccAtkLoad();
}

function ccWire() {
  const box = $('#home');
  $$('[data-hm-go]', box).forEach((b) => (b.onclick = (e) => {
    e.stopPropagation();
    const go = b.dataset.hmGo;
    if (go.startsWith('check:')) checkInfo(go.slice(6));
    else if (go.startsWith('incident:')) { showTab('incidents'); caseDialog(+go.slice(9)); }
    else if (go.startsWith('ip:')) amDetail(go.slice(3));
    else if (go.startsWith('block:')) blockDialog(go.slice(6));
    else showTab(go);
  }));
  $$('[data-hm-risk]', box).forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); e.preventDefault(); exposureDialog(+b.dataset.hmRisk); }));
  if (!box.dataset.ccWired) {   // de knopen en lijnen in "Live aanvallen" (die kaart wordt los bijgewerkt)
    box.dataset.ccWired = '1';
    box.addEventListener('click', (e) => { const t = e.target.closest('[data-cc-ip]'); if (t && dashMode() === 'cc') amDetail(t.getAttribute('data-cc-ip')); });
  }
  if (!ccEdit) return;
  const lay = ccLayout();
  const save = () => { ccSaveLayout(lay); renderCC(); };
  $$('.cc-w', box).forEach((w) => {
    const k = w.dataset.ccw;
    $$('[data-cc-move]', w).forEach((b) => (b.onclick = () => { const i = lay.order.indexOf(k), j = i + +b.dataset.ccMove; if (j < 0 || j >= lay.order.length) return; lay.order.splice(i, 1); lay.order.splice(j, 0, k); save(); }));
    $('[data-cc-hide]', w).onclick = () => { lay.hide = lay.hide.includes(k) ? lay.hide.filter((x) => x !== k) : [...lay.hide, k]; save(); };
    w.addEventListener('dragstart', (e) => { ccDrag = k; w.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', k); } catch (ex) { /* oude browsers */ } });
    w.addEventListener('dragend', () => { ccDrag = null; w.classList.remove('dragging'); $$('.cc-w.over', box).forEach((x) => x.classList.remove('over')); });
    w.addEventListener('dragover', (e) => { if (ccDrag && ccDrag !== k) { e.preventDefault(); w.classList.add('over'); } });
    w.addEventListener('dragleave', () => w.classList.remove('over'));
    w.addEventListener('drop', (e) => {
      e.preventDefault();
      if (!ccDrag || ccDrag === k) return;
      lay.order.splice(lay.order.indexOf(ccDrag), 1);
      lay.order.splice(lay.order.indexOf(k), 0, ccDrag);
      save();
    });
  });
}

/** Score en de vijf domeinen */
function ccOverview(d, c) {
  const live = hmLive(), hl = state.health || {};
  const score = d.full ? d.score : (hl.score ?? 100), cls = score >= 90 ? 'ok' : score >= 60 ? 'warn' : 'crit';
  const down = (hl.hosts_total || 0) - (hl.hosts_up || 0);
  const agents = state.hosts.filter((h) => h.mode === 'agent' && +h.enabled !== 0);
  const nowS = Date.now() / 1000;
  const stil = agents.filter((h) => !h.contact || !h.contact.seen || nowS - h.contact.seen > h.contact.stale).length;
  const old = agents.filter((h) => h.agent_version && state.agent_latest && verCmp(h.agent_version, state.agent_latest) < 0).length;
  const probs = live.n[1] + live.n[2];
  const atk = c.sec.ssh_fail.n + c.sec.web.n;
  const tiles = [
    ['dashboard', L('Monitoring'), `${hmNum(hl.hosts_up || 0)}<small>/${hmNum(hl.hosts_total || 0)}</small>`, down ? L('{n} onbereikbaar', { n: down }) : L('alles bereikbaar'), down ? 'crit' : 'ok', d.full ? hmSpark(hmVals(d.avail.series), 's1') : ''],
    ['alerts', L('Meldingen'), hmNum(probs), probs ? [live.n[2] && L('{n} storing', { n: live.n[2] }), live.n[1] && L('{n} aandacht', { n: live.n[1] })].filter(Boolean).join(' · ') : L('{n} checks OK', { n: live.total }), live.n[2] ? 'crit' : live.n[1] ? 'warn' : 'ok', ''],
    ['ids/inc', L('IDS'), hmNum(c.sec.ids_inc.n), [L('nieuwe incidenten'), c.sec.ids_inc.crit ? L('{n} kritiek', { n: c.sec.ids_inc.crit }) : '', atk ? L('{n} pogingen', { n: hmNum(atk) }) : ''].filter(Boolean).join(' · '), c.sec.ids_inc.crit ? 'crit' : c.sec.ids_inc.n ? 'warn' : 'ok', d.full ? hmSpark(hmVals(d.ids.series.blk), 's2') : ''],
  ];
  if (d.vulns) tiles.push(['vulns', L('Kwetsbaarheden'), hmNum(d.vulns.sev[4]), L('kritiek') + ' · ' + (d.vulns.kev ? L('{n} in KEV', { n: d.vulns.kev }) : L('geen in KEV')) + ' · ' + L('{n} hoog', { n: d.vulns.sev[3] || 0 }), d.vulns.sev[4] ? 'crit' : d.vulns.sev[3] ? 'warn' : 'ok', hmSpark(hmVals(d.vulns.series), 'crit')]);
  if (agents.length) tiles.push([isAdmin() ? 'settings/agents' : 'dashboard', L('Agents'), `${agents.length - stil}<small>/${agents.length}</small>`, [stil ? L('{n} stil', { n: stil }) : L('allemaal actief'), old ? L('{n} op een oudere versie', { n: old }) : ''].filter(Boolean).join(' · '), stil ? 'warn' : 'ok', '']);
  const r = 50, C = 2 * Math.PI * r, f = Math.max(0, Math.min(100, score)) / 100;
  const why = d.full && d.now && d.now[0] ? d.now[0].text : '';
  return `<div class="cc-ov">
    <div class="cc-card cc-score ${cls}"><svg width="124" height="124" viewBox="0 0 124 124" aria-hidden="true"><circle cx="62" cy="62" r="${r}" class="cc-ring-bg"/>
        <circle cx="62" cy="62" r="${r}" class="cc-ring" stroke-dasharray="${(C * f).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 62 62)"/><circle cx="62" cy="62" r="38" class="cc-ring-spin"/>
        <text x="62" y="68" text-anchor="middle" class="cc-ring-n">${score}</text><text x="62" y="86" text-anchor="middle" class="cc-ring-s">${esc(L('van 100'))}</text></svg>
      <div><span class="cc-hl ${cls}">${esc(cls === 'ok' ? L('Alles in orde') : cls === 'warn' ? L('Aandacht nodig') : L('Actie nodig'))}</span>
        <div class="cc-score-t">${esc(live.n[2] ? L('{n} checks in storing', { n: live.n[2] }) : L('{n} checks OK', { n: live.total }))}${c.sec.ids_inc.crit ? ' · ' + esc(L('{n} kritiek', { n: c.sec.ids_inc.crit })) : ''}</div>
        ${why ? `<div class="cc-mu small">${esc(L('Eerst: {x}', { x: why }))}</div>` : ''}</div></div>
    ${tiles.map(([go, l, big, sub, st, sp]) => `<button type="button" class="cc-card cc-tile ${st}" data-hm-go="${esc(go)}"><span class="cc-hl">${esc(l)}</span><b class="cc-big">${big}</b>
      <span class="cc-mu small">${esc(sub)}</span>${sp}<span class="cc-st ${st}">${hmShape(st === 'crit' ? 'crit' : st, 8)} ${esc(HM_ST[st === 'crit' ? 'crit' : st]())}</span></button>`).join('')}</div>`;
}

/** Acht getallen over de beveiliging, elk klikbaar */
function ccSecurity(d, c) {
  const s = c.sec;
  const n = (go, lbl, val, sub, cls, delta) => `<button type="button" class="cc-num ${cls}" data-hm-go="${esc(go)}"><span class="cc-mu small">${esc(lbl)}</span><b>${hmNum(val)}</b>
    <span class="small">${delta}${sub ? ` <span class="cc-mu">${esc(sub)}</span>` : ''}</span></button>`;
  return ccCard('security', `${ccHead(L('Beveiliging in één oogopslag') + ' · ' + CC_PERIODS.find(([k]) => k === ccPeriod)[1](), `<span class="cc-mu small">${esc(ccPrevLbl())} · ${esc(L('klik op een getal voor de lijst'))}</span>`)}
    <div class="cc-nums">
      ${n('ids/acc?type=ssh&result=ok', L('SSH-logins'), s.ssh_login.n, [L('{n} gebruikers', { n: s.ssh_login.users }), s.ssh_login.new_ips ? L('{n} nieuw IP', { n: s.ssh_login.new_ips }) : ''].filter(Boolean).join(' · '), s.ssh_login.new_ips ? 'warn' : 'ok', ccDelta(s.ssh_login.n, s.ssh_login.prev, false))}
      ${n('ids/acc?type=ssh&result=fail', L('Mislukte SSH-logins'), s.ssh_fail.n, L('{n} IP-adressen', { n: s.ssh_fail.ips }), s.ssh_fail.n ? 'crit' : '', ccDelta(s.ssh_fail.n, s.ssh_fail.prev))}
      ${n('ids/blk', L('SSH-blokkades'), s.ssh_blk.n, L('{n} nog actief', { n: s.ssh_blk.active }), '', ccDelta(s.ssh_blk.n, s.ssh_blk.prev, false))}
      ${n('ids/blk', L('IDS-blokkades'), s.ids_blk.n, L('{n} actief op {h} servers', { n: s.ids_blk.active, h: s.ids_blk.hosts }), '', ccDelta(s.ids_blk.n, s.ids_blk.prev, false))}
      ${n('ids/blk', L('Tegengehouden'), s.stopped, L('pogingen door blokkades'), 'cy', '')}
      ${n('ids/acc?type=sudo', L('sudo en su'), s.sudo.n, [L('{n} gebruikers', { n: s.sudo.users }), s.sudo.fail ? L('{n} mislukt', { n: s.sudo.fail }) : L('niets mislukt')].join(' · '), s.sudo.fail ? 'warn' : '', ccDelta(s.sudo.n, s.sudo.prev))}
      ${n('ids/map', L('Webaanvallen'), s.web.n, L('{n} IP-adressen', { n: s.web.ips }), s.web.n ? 'warn' : '', ccDelta(s.web.n, s.web.prev))}
      ${n('ids/map', L('Verdacht uitgaand'), s.out.n, s.out.last ? s.out.last.slice(0, 40) : L('niets gezien'), s.out.n ? 'out' : '', ccDelta(s.out.n, s.out.prev))}
    </div>`);
}

/** De toppers: grootste SSH- en IDS-blokkade, grootste aanvaller, meest aangevallen server en land */
function ccToppers(d, c) {
  const t = c.top;
  const ip = (x) => `<button type="button" class="cc-ip" data-hm-go="ip:${esc(x.ip)}">${afCcBadge(x.cc, x.cc === 'LAN')}<span class="mono">${esc(x.ip)}</span></button>`;
  const blk = (x, title) => (x ? `<div class="cc-card cc-top crit">${ccHead(title)}${ip(x)}
      <span class="cc-mu small">${esc(L('{n} pogingen tegengehouden', { n: hmNum(x.hits) }))}${x.users.length ? ' · ' + esc(x.users.join(', ')) : x.reason ? ' · ' + esc(x.reason) : ''}</span>
      <span class="small"><span class="cc-ok">⛔ ${esc(L('geblokkeerd op {list} sinds {t}', { list: x.on.join(', '), t: ccTime(x.at) }))}</span>${x.not_on.length ? ` · <span class="cc-wa">${esc(L('nog niet op {list}', { list: x.not_on.join(', ') }))}</span>` : ''}</span>
      ${x.not_on.length && isAdmin() ? `<button type="button" class="cc-link" data-hm-go="block:${esc(x.ip)}">${esc(L('Overal blokkeren'))} →</button>` : ''}</div>`
    : `<div class="cc-card cc-top">${ccHead(title)}<span class="cc-mu small">${esc(L('Geen actieve blokkade'))}</span></div>`);
  const a = t.attacker, h = t.host, cs = t.countries || [];
  return `<div class="cc-tops">
    ${blk(t.ssh_block, L('Top SSH-blokkade'))}${blk(t.ids_block, L('Top IDS-blokkade'))}
    ${a ? `<div class="cc-card cc-top ${a.blocked ? '' : 'warn'}">${ccHead(L('Grootste aanvaller'))}${ip(a)}<span class="cc-mu small">${esc(a.title)}</span>
      <span class="small">${a.blocked ? `<span class="cc-ok">⛔ ${esc(L('geblokkeerd'))}</span>` : `<span class="cc-wa">● ${esc(L('nog niet geblokkeerd'))}</span>`} · ${esc(L('{n} pogingen', { n: hmNum(a.hits) }))}${a.hosts > 1 ? ' · ' + esc(L('{n} servers', { n: a.hosts })) : ''}</span>
      ${!a.blocked && isAdmin() && a.cc !== 'LAN' ? `<button type="button" class="cc-link" data-hm-go="block:${esc(a.ip)}">${esc(L('Blokkeren'))} →</button>` : ''}</div>`
      : `<div class="cc-card cc-top">${ccHead(L('Grootste aanvaller'))}<span class="cc-mu small">${esc(L('Geen aanvallen in deze periode.'))}</span></div>`}
    ${h ? `<button type="button" class="cc-card cc-top" data-hm-go="ids/map">${ccHead(L('Meest aangevallen'))}<b class="cc-name">${esc(h.name)}</b>
      <span class="cc-mu small">${esc(L('{n} pogingen · {i} bronnen', { n: hmNum(h.hits), i: h.ips }))}${h.svc.length ? ' · ' + esc(h.svc.slice(0, 3).join(', ')) : ''}</span>
      <span class="small">${h.blocks ? `<span class="cc-ok">${esc(L('{n} blokkades actief', { n: h.blocks }))}</span>` : `<span class="cc-wa">${esc(L('geen blokkades'))}</span>`}</span></button>`
      : `<div class="cc-card cc-top">${ccHead(L('Meest aangevallen'))}<span class="cc-mu small">–</span></div>`}
    <div class="cc-card cc-top">${ccHead(L('Top land'))}${cs.length ? `<b class="cc-name">${afCcBadge(cs[0].cc, cs[0].cc === 'LAN')} ${esc(amCcName(cs[0].cc))}</b>
      <span class="cc-mu small">${esc(L('{p}% van alle pogingen · {n} IP-adressen', { p: cs[0].pct, n: cs[0].ips }))}</span>
      <span class="cc-chips">${cs.slice(1).map((x) => `<span class="cc-chip">${esc(x.cc === 'LAN' ? L('eigen netwerk') : x.cc)} ${x.pct}%</span>`).join('')}</span>` : `<span class="cc-mu small">–</span>`}</div>
  </div>`;
}

/** Nu doen: dezelfde lijst als op het klassieke dashboard, compacter */
function ccNow(d) {
  const live = hmLive();
  const list = d.full && d.now ? d.now : live.checks.filter((c) => +c.status === 2 || +c.status === 1).sort((a, b) => b.status - a.status)
    .map((c) => ({ kind: 'todo', sev: +c.status === 2 ? 2 : 1, text: `${c.name} – ${(state.hosts.find((h) => +h.id === +c.host_id) || {}).name || ''}`, urgency: +c.status === 2 ? 50 : 25, why: [], go: 'check:' + c.id }));
  const shown = list.slice(0, 6);
  return ccCard('now', `${ccHead(L('Nu doen'), `<span class="cc-mu small">${esc(L('{n} open', { n: list.length }))}</span>`)}
    ${shown.length ? `<ul class="cc-list">${shown.map((t) => `<li><button type="button" class="cc-row" data-hm-go="${esc(t.go)}"><i class="cc-bar ${t.urgency >= 60 ? 'crit' : t.urgency >= 30 ? 'warn' : 'cy'}"></i>
      <span><b>${t.kind === 'inc' ? '⚠ ' : t.icon ? esc(t.icon) + ' ' : ''}${esc(t.text)}</b><small class="cc-mu">${esc((t.why || []).slice(1, 3).join(' · '))}${t.owner ? ' · 👤 ' + esc(t.owner) : ''}</small></span>${urgPill(t.urgency, t.why)}</button></li>`).join('')}</ul>`
      : `<p class="cc-mu">${esc(L('Niets te doen. Mooi zo.'))}</p>`}
    ${d.full ? ccGo('analytics', L('Alles bekijken')) : ''}`);
}

/** Wie logt er in: SSH, console, sudo en su */
function ccLogins(d, c) {
  const ic = { ssh: '🔑', console: '🖥️', sudo: '⚡', su: '⇄' };
  const meth = { publickey: L('sleutel'), password: L('wachtwoord'), 'keyboard-interactive/pam': L('wachtwoord') };
  return ccCard('logins', `${ccHead(L('Wie logt er in'), ccGo('ids/acc', L('Toegang')))}
    ${c.logins.length ? `<ul class="cc-list">${c.logins.map((x) => `<li class="cc-lg"><span class="mono cc-mu">${esc(ccTime(x.at).slice(-5))}</span>
      <span><b>${esc(x.user)}</b> ${x.type === 'ssh' || x.type === 'console' ? '→' : ''} ${esc(x.type === 'sudo' || x.type === 'su' ? L('{t} op {host}', { t: x.type + (x.as && x.as !== 'root' ? ' ' + x.as : '') + (x.cmd ? ' ' + x.cmd : ''), host: x.host }) : x.host)}
        <small class="cc-mu">${[x.type === 'ssh' ? 'SSH' : x.type === 'console' ? L('console') : '', meth[x.m] || x.m, x.ip, x.cc && x.cc !== 'LAN' ? x.cc : ''].filter(Boolean).map(esc).join(' · ')}</small></span>
      <span class="cc-tag ${x.new ? 'warn' : x.type === 'sudo' || x.type === 'su' ? '' : 'ok'}">${x.new ? esc(L('nieuw IP')) : ic[x.type] || ''}</span></li>`).join('')}</ul>`
      : `<p class="cc-mu">${esc(L('Nog niemand ingelogd in deze periode.'))}</p>`}`);
}

/** Live aanvallen: een kleine Attack Flow van de laatste 15 minuten (eigen gegevens, hooguit eens per 20 seconden) */
async function ccAtkLoad() {
  if (Date.now() - ccAtkAt < 20000 && ccAtk) return ccAtkPaint();
  ccAtkAt = Date.now();
  try { ccAtk = await api('ids_attack_flow', null, { days: 1, mins: 15, top: 6 }); } catch (ex) { ccAtk = { error: ex.message }; }
  ccAtkPaint();
}
function ccAttacks() {
  return ccCard('attacks', `${ccHead(L('Live aanvallen · laatste 15 min'), ccGo('ids/map', 'Attack Flow'))}<div id="cc-atk">${ccAtk ? ccAtkSvg(ccAtk) : `<p class="cc-mu">${esc(L('Laden…'))}</p>`}</div>`, 'cc-atkcard');
}
function ccAtkPaint() { const el = $('#cc-atk'); if (el && ccAtk) el.innerHTML = ccAtkSvg(ccAtk); }
function ccAtkSvg(a) {
  if (a.error) return `<p class="form-error">${esc(a.error)}</p>`;
  const lv = a.live || {}, lines = (a.lines || []).slice(0, 6);
  if (!lines.length) return `<div class="cc-calm">${amSvg('shield', 'am-ic big cc-okc')}<span>${esc(L('Rustig: geen aanvallen in de laatste 15 minuten.'))}</span></div>`;
  const srcs = [...new Set(lines.map((l) => l.ip))].slice(0, 5), tgts = [...new Set(lines.map((l) => l.host_id))].slice(0, 4);
  const W = 340, rowS = 40, H = Math.max(srcs.length, tgts.length) * rowS + 8;
  const ys = (i, n) => (H - n * rowS) / 2 + i * rowS + rowS / 2;
  const names = Object.fromEntries((a.targets || []).map((t) => [t.id, t.name]));
  const now = performance.now() / 1000, hit = new Set(lv.hit || []);
  const parts = [`<defs><filter id="ccGlow" x="-20%" y="-60%" width="140%" height="220%"><feGaussianBlur stdDeviation="2.2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs>`];
  lines.forEach((l, idx) => {
    const si = srcs.indexOf(l.ip), ti = tgts.indexOf(l.host_id);
    if (si < 0 || ti < 0) return;
    const y1 = ys(si, srcs.length), y2 = ys(ti, tgts.length), x1 = 118, x2 = 232, xs = 212, mid = (x1 + x2) / 2;
    const cls = l.dir === 'o' ? 'out' : l.sev >= 2 ? 'crit' : 'warn';
    const blocked = (l.outcome || {}).k === 'blocked' && l.dir !== 'o';
    const curve = `M${x1} ${y1.toFixed(1)} C${mid} ${y1.toFixed(1)} ${mid} ${y2.toFixed(1)} ${xs} ${y2.toFixed(1)}`;
    const ll = (lv.lines || [])[a.lines.indexOf(l)] || {};
    const rate = +ll.rate || 0, dur = rate >= 6 ? 0.5 : rate >= 2 ? 0.85 : rate > 0 ? 1.4 : 2.6;
    parts.push(`<g class="af-l ${cls}"><path class="af-base" d="${curve} H${x2}" stroke-width="6"/>${l.obs && ll.recent
      ? `<path class="af-p" d="${l.dir === 'o' ? `M${x2} ${y2.toFixed(1)} H${xs} C${mid} ${y2.toFixed(1)} ${mid} ${y1.toFixed(1)} ${x1} ${y1.toFixed(1)}` : blocked ? curve : curve + ' H' + x2}" stroke-width="4" style="animation-duration:${dur}s;animation-delay:-${(now % dur).toFixed(2)}s"/>`
      : `<path class="af-core${l.obs ? '' : ' inf'}" d="${curve} H${x2}" stroke-width="2"/>`}
      ${blocked ? `<g class="af-shield" transform="translate(${xs + 12} ${y2.toFixed(1)})"><circle class="af-bu" r="6"/><circle class="af-sh" r="8"/><path d="M-3 -3 l6 6 M3 -3 l-6 6"/></g>` : ''}
      <path class="am-hit" d="${curve} H${x2}" data-cc-ip="${esc(l.ip)}"><title>${esc(`${l.ip} → ${names[l.host_id] || '?'} · ${amSvc(l)} · ${amN(l.hits)}×`)}</title></path></g>`);
    void idx;
  });
  srcs.forEach((ip, i) => {
    const s = (a.sources || []).find((x) => x.ip === ip) || { ip, cc: '??' }, y = ys(i, srcs.length);
    parts.push(`<g class="cc-fn" data-cc-ip="${esc(ip)}"><rect x="1" y="${(y - 15).toFixed(1)}" width="117" height="30" rx="8"/><text x="8" y="${(y + 3.5).toFixed(1)}" class="cc-fn-cc">${esc(s.internal ? 'LAN' : s.cc)}</text>
      <text x="32" y="${(y + 3.5).toFixed(1)}" class="cc-fn-ip">${esc(ip)}</text></g>`);
  });
  tgts.forEach((id, i) => {
    const y = ys(i, tgts.length);
    parts.push(`<g class="cc-fn r ${hit.has(+id) ? 'hit' : ''}"><rect x="232" y="${(y - 15).toFixed(1)}" width="107" height="30" rx="8"/><text x="240" y="${(y + 3.5).toFixed(1)}" class="cc-fn-h">${esc((names[id] || '?').slice(0, 14))}</text></g>`);
  });
  const b = lv.busy || {};
  return `<svg class="cc-atk-svg" viewBox="0 0 ${W} ${H}" aria-hidden="true">${parts.join('')}</svg>
    <div class="cc-mu small cc-atk-sum"><span><b>${hmNum(b.active || 0)}</b> ${esc(L('aanvallers actief'))}</span><span><b>${esc(dec(b.per_min || 0))}</b> ${esc(L('pogingen/min'))}</span>
      ${(lv.hit || []).length ? `<span class="cc-cr">${esc(L('{n} server(s) nu geraakt', { n: lv.hit.length }))}</span>` : ''}</div>`;
}

/** Monitoring: hoeveel checks, hoe vaak ze liepen, wat het vaakst fout ging en de meeste meldingen gaf */
function ccMonitoring(d, c) {
  const m = c.mon;
  const series = m.series || [], mx = Math.max(1, ...series.map((x) => x.n)), mf = Math.max(1, ...series.map((x) => x.f));
  const W = 460, H = 120, bw = series.length ? Math.max(2, W / series.length - 3) : 0;
  const chart = series.length > 1 ? `<svg class="cc-mchart" viewBox="0 0 ${W} ${H + 16}" preserveAspectRatio="none" role="img" aria-label="${esc(L('Checks per {p}: uitgevoerd en fout', { p: ccPeriod === 'today' ? L('uur') : L('dag') }))}">
      ${series.map((x, i) => `<rect class="cc-mb" x="${(i * W / series.length).toFixed(1)}" y="${(H - x.n / mx * (H - 10)).toFixed(1)}" width="${bw.toFixed(1)}" height="${(x.n / mx * (H - 10)).toFixed(1)}" rx="2"><title>${esc(x.k)}: ${esc(L('{n} uitgevoerd, {f} fout', { n: hmNum(x.n), f: hmNum(x.f) }))}</title></rect>`).join('')}
      <path class="cc-mf" d="${series.map((x, i) => `${i ? 'L' : 'M'}${(i * W / series.length + bw / 2).toFixed(1)} ${(H - x.f / mf * (H - 14) - 2).toFixed(1)}`).join('')}"/>
      <text x="0" y="${H + 13}" class="cc-axt">${esc(series[0].k.slice(ccPeriod === 'today' ? 11 : 5))}</text><text x="${W}" y="${H + 13}" text-anchor="end" class="cc-axt">${esc(series[series.length - 1].k.slice(ccPeriod === 'today' ? 11 : 5))}</text></svg>
      <div class="cc-mu small cc-leg"><span><i class="sw cc-sw-n"></i>${esc(L('uitgevoerd'))}</span><span><i class="sw cc-sw-f"></i>${esc(L('fout (warning of critical)'))}</span></div>`
    : `<p class="cc-mu small">${esc(L('Nog geen uurtotalen in deze periode; die komen elk uur bij.'))}</p>`;
  const row = (x, val, sub, cls) => `<li><button type="button" class="cc-row" data-hm-go="check:${x.id}"><span><b>${esc(x.name)}</b><small class="cc-mu">${esc(x.host)}${sub ? ' · ' + esc(sub) : ''}</small></span>
    <span class="cc-val ${cls}">${val}</span></button></li>`;
  const mr = m.most_run;
  return ccCard('monitoring', `${ccHead(L('Monitoring') + ' · ' + CC_PERIODS.find(([k]) => k === ccPeriod)[1](), `<span class="cc-mu small">${esc(ccPrevLbl())}</span>`)}
    <div class="cc-mon">
      <div class="cc-nums cc-nums-5">
        <button type="button" class="cc-num" data-hm-go="settings/allchecks"><span class="cc-mu small">${esc(L('Actieve checks'))}</span><b>${hmNum(m.active)}</b>
          <span class="small cc-mu">${esc(m.types.slice(0, 3).map((t) => `${t.n} ${t.type}`).join(' · '))}</span></button>
        <div class="cc-num"><span class="cc-mu small">${esc(L('Uitgevoerd'))}</span><b>${hmNum(m.runs.n)}</b><span class="small">${ccDelta(m.runs.n, m.runs.prev, false)} <span class="cc-mu">${esc(L('{n} checks liepen', { n: m.checks_ran }))}</span></span></div>
        <div class="cc-num ${m.fails.n ? 'warn' : 'ok'}"><span class="cc-mu small">${esc(L('Fout gegaan'))}</span><b>${hmNum(m.fails.n)}</b><span class="small">${ccDelta(m.fails.n, m.fails.prev)} <span class="cc-mu">${esc(L('warning of critical'))}</span></span></div>
        <div class="cc-num ${m.ok_pct !== null && m.ok_pct < 95 ? 'warn' : 'ok'}"><span class="cc-mu small">${esc(L('Gelukt'))}</span><b>${m.ok_pct === null ? '–' : esc(dec(m.ok_pct)) + '<small>%</small>'}</b><span class="small cc-mu">${esc(L('van alle uitvoeringen'))}</span></div>
        <button type="button" class="cc-num ${m.alerts.n ? 'warn' : ''}" data-hm-go="alerts"><span class="cc-mu small">${esc(L('Meldingen verstuurd'))}</span><b>${hmNum(m.alerts.n)}</b><span class="small">${ccDelta(m.alerts.n, m.alerts.prev)} <span class="cc-mu">${esc(L('naar je kanalen'))}</span></span></button>
      </div>
      <div class="cc-mgrid">
        <div>${chart}${mr ? `<p class="small cc-mu" style="margin:.4rem 0 0">${esc(L('Vaakst uitgevoerd'))}: <button type="button" class="cc-link" data-hm-go="check:${mr.id}">${esc(mr.name)} · ${esc(mr.host)}</button> (${esc(L('{n}×', { n: hmNum(mr.n) }))})</p>` : ''}</div>
        <div><span class="cc-sub">${esc(L('Vaakst fout gegaan'))}</span>${m.most_failed.length ? `<ul class="cc-list">${m.most_failed.map((x) => row(x, `${hmNum(x.fail)}<small>×</small>`, L('{p}% van {n} keer', { p: dec(x.pct), n: hmNum(x.n) }) + (x.crit ? ' · ' + L('{n} critical', { n: hmNum(x.crit) }) : ''), x.crit ? 'crit' : 'warn')).join('')}</ul>` : `<p class="cc-mu small">${esc(L('Niets fout gegaan.'))}</p>`}</div>
        <div><span class="cc-sub">${esc(L('Meeste meldingen'))}</span>${m.fired.length ? `<ul class="cc-list">${m.fired.map((x) => row(x, `${hmNum(x.n)}<small>×</small>`, (x.crit ? L('{n} critical', { n: x.crit }) + ' · ' : '') + L('laatste {t}', { t: ccTime(x.last) }), x.crit ? 'crit' : 'warn')).join('')}</ul>` : `<p class="cc-mu small">${esc(L('Geen meldingen verstuurd.'))}</p>`}</div>
      </div></div>`);
}

/** Servers als tegels met drie ringen (CPU, RAM, de volste disk); de rand gloeit in de kleur van de status */
function ccServers(d) {
  const risk = (d && d.risk) || {}, rank = { crit: 0, warn: 1, unk: 2, ok: 3 };
  const all = hmAllHosts().sort((a, b) => rank[a.st] - rank[b.st] || a.h.name.localeCompare(b.h.name));
  if (!all.length) return '';
  const ring = (v, lbl, x) => {
    const r = 17, C = 2 * Math.PI * r, f = v === null ? 0 : Math.min(100, v) / 100, cls = v === null ? 'na' : v >= 90 ? 'crit' : v >= 75 ? 'warn' : 'ok';
    return `<g transform="translate(${x} 24)"><circle r="${r}" class="cc-ring-bg"/>${v === null ? '' : `<circle r="${r}" class="cc-sring ${cls}" stroke-dasharray="${(C * f).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90)"/>`}
      <text y="4" text-anchor="middle" class="cc-sring-n">${v === null ? '–' : v}</text><text y="33" text-anchor="middle" class="cc-sring-l">${esc(lbl)}</text></g>`;
  };
  const why = (h) => { const p = h.checks.filter((c) => +c.enabled && !c.maintenance && +c.status >= 1 && +c.status <= 2).sort((a, b) => b.status - a.status); return p.length ? (p.length > 1 ? L('{n} problemen', { n: p.length }) : p[0].name) : ''; };
  return ccCard('servers', `${ccHead(L('Servers'), `<span class="cc-mu small">${esc(L('ringen = CPU · RAM · disk · de rand = status · klik voor de server'))}</span>`)}
    <div class="cc-srv">${all.map(({ h, st, cpu, ram, disk }) => `<button type="button" class="cc-s ${st}" data-hm-go="dashboard?server=${encodeURIComponent(h.name)}">
      <span class="cc-s-top"><b>${esc(h.name)}</b>${risk[h.id] && risk[h.id].score >= 40 ? urgPill(risk[h.id].score, risk[h.id].reasons, true) : ''}</span>
      <span class="cc-mu small">${esc(kindLabel(hostKind(h)))} · ${esc(st === 'ok' ? HM_ST.ok() : why(h) || HM_ST[st]())}</span>
      ${cpu === null && ram === null && disk === null ? `<span class="cc-mu small cc-s-none">${esc(L('geen CPU-, RAM- of diskcheck'))}</span>`
        : `<svg viewBox="0 0 156 64" class="cc-s-rings" aria-label="${esc(`CPU ${cpu ?? '–'}% · RAM ${ram ?? '–'}% · ${L('Disk')} ${disk ?? '–'}%`)}" role="img">${ring(cpu, 'CPU', 26)}${ring(ram, 'RAM', 78)}${ring(disk, L('Disk'), 130)}</svg>`}
    </button>`).join('')}</div>`);
}

/** 1.146.0: aanvalspad en impact van één server: van buiten → open poorten (met pakket en ergste kwetsbaarheid) → server → wie ervan afhangt */
async function exposureDialog(hostId) {
  let x;
  try { x = await api('host_exposure', null, { id: hostId }); } catch (ex) { return toast(ex.message, 'err'); }
  const ports = x.ports || [];
  const vulnPorts = ports.filter((p) => p.vuln);
  modal({
    title: `🎯 ${L('Aanvalspad en impact')}: ${x.host.name}`, wide: true,
    body: `<div style="padding:1rem 1.2rem">
      <p class="small">${urgPill(x.risk.score, x.risk.reasons, true)} <b>${L('Risico {u}', { u: x.risk.score })}</b>${x.risk.reasons.length ? ': ' + esc(x.risk.reasons.join(' · ')) : ''}</p>
      <div class="exp-path">
        <div class="exp-col"><b>🌐 ${L('Van buiten / netwerk')}</b></div><i aria-hidden="true">→</i>
        <div class="exp-col"><b>${L('{n} open poort(en)', { n: ports.length })}</b>${vulnPorts.length ? `<span class="crit-txt small">${L('{n} met een kwetsbaarheid', { n: vulnPorts.length })}</span>` : ''}</div><i aria-hidden="true">→</i>
        <div class="exp-col"><b>🖥️ ${esc(x.host.name)}</b>${x.incidents.length ? `<span class="warn-txt small">${L('{n} open incident(en)', { n: x.incidents.length })}</span>` : ''}</div><i aria-hidden="true">→</i>
        <div class="exp-col"><b>${L('{n} afhankelijke server(s)', { n: x.dependents.length })}</b><span class="muted small">${esc(x.dependents.slice(0, 6).join(', ')) || L('geen')}</span></div>
      </div>
      <h3>${L('Open poorten')}</h3>
      ${ports.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Poort')}</th><th>${L('Programma')}</th><th>${L('Pakket')}</th><th>${L('Ergste kwetsbaarheid')}</th></tr></thead><tbody>
        ${ports.map((p) => `<tr><td class="mono">${p.port}<span class="muted small"> ${esc(p.addr)}</span></td><td>${esc(p.proc || '–')}</td><td>${esc(p.pkg || '–')}</td>
          <td>${p.vuln ? `${urgPill(p.vuln.risk, [], true)} <button type="button" class="linkbtn" data-exp-cve="${esc(p.vuln.cve)}">${esc(p.vuln.cve)}</button>${p.vuln.kev ? ' ⚠️' : ''}${p.vuln.fixed ? ` <span class="muted small">→ ${esc(p.vuln.fixed)}</span>` : ''}` : '<span class="muted">–</span>'}</td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted small">${x.host.mode === 'agent' ? L('Geen poorten buiten localhost (of de agent stuurde ze nog niet).') : L('Alleen bekend voor servers met een agent.')}</p>`}
      ${x.incidents.length ? `<h3>${L('Open incidenten')}</h3><ul>${x.incidents.map((i) => `<li><button type="button" class="linkbtn" data-exp-inc="${i.id}">${esc(i.title)}</button></li>`).join('')}</ul>` : ''}
      <p class="muted small">${L('Een schatting: RzSense kent je firewall niet. Een poort die op alle adressen luistert, kan van buiten toch dicht zijn.')}</p>
    </div>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', onClick: () => $('#modal').close() }],
  });
  $$('[data-exp-cve]', $('#modal')).forEach((b) => (b.onclick = () => { $('#modal').close(); vulnDetail(b.dataset.expCve); }));
  $$('[data-exp-inc]', $('#modal')).forEach((b) => (b.onclick = () => { $('#modal').close(); showTab('incidents'); caseDialog(+b.dataset.expInc); }));
}

/** 1.144.0: urgentie 0–100 als pil (zelfde kleuren als het risico) */
function urgPill(u, why, risk = false) {
  const lvl = u >= 70 ? 'high' : u >= 40 ? 'mid' : 'low';
  return `<span class="risk-pill risk-${lvl}" title="${esc((risk ? L('Risico {u} van 100', { u }) : L('Urgentie {u} van 100', { u })) + (why && why.length ? ': ' + why.join(', ') : ''))}">${u}</span>`;
}

/** 1.144.0: "Nu doen": open incidenten en aandachtspunten samen, op urgentie */
function hmNow(list, full) {
  const shown = list.slice(0, 8);
  return `<section class="hm-card hm-now" aria-labelledby="hm-h-now">
    <div class="hm-card-head"><h2 id="hm-h-now">${esc(L('Nu doen'))}</h2>
      <span>${$('#tab-incidents') ? `<button type="button" class="link-btn" data-hm-go="incidents">${esc(L('Incidenten'))}</button>` : ''}${full ? ` · <button type="button" class="link-btn" data-hm-go="analytics">${esc(L('Alles bekijken'))}</button>` : ''}</span></div>
    ${shown.length ? `<ul>${shown.map((t) => `<li>${urgPill(t.urgency, t.why)}
      <span class="hm-attn-txt">${t.kind === 'inc' ? '⚠ ' : t.icon ? esc(t.icon) + ' ' : ''}${esc(t.text)}
        <small>${esc((t.why || []).slice(1).join(' · '))}${t.kind === 'inc' ? ` <span class="inc-st inc-st-${t.status}">${esc(INC_ST[t.status]())}</span>${t.owner ? ' 👤 ' + esc(t.owner) : ''}${t.parts > 1 ? ' · ' + esc(L('{n} stappen', { n: t.parts })) : ''}` : ''}</small></span>
      <button type="button" class="btn small-btn" data-hm-go="${esc(t.go)}">${esc(L('Openen'))}</button></li>`).join('')}</ul>
      ${list.length > shown.length ? `<p class="muted small">${esc(L('en nog {n}', { n: list.length - shown.length }))}</p>` : ''}`
      : `<p class="hm-empty">${hmShape('ok', 14)} ${esc(L('Niets dat nu aandacht nodig heeft'))}</p>`}
  </section>`;
}

/** Servers met hun CPU, RAM, disk, status en (1.144.0) risico */
function hmAllHosts() {
  const val = (h, t) => { const v = h.checks.filter((c) => c.type === t && +c.enabled && c.last_value !== null).map((c) => +c.last_value); return v.length ? Math.round(Math.max(...v)) : null; };
  return state.hosts.filter((h) => +h.enabled !== 0)
    .map((h) => { const s = hostStatus(h); return { h, st: s === 2 ? 'crit' : s === 1 ? 'warn' : s === 0 ? 'ok' : 'unk', cpu: val(h, 'cpu'), ram: val(h, 'ram'), disk: val(h, 'disk') }; });
}

/** Probleemservers: niet OK, of een risico van 40 of meer; ergste eerst */
function hmProblemHosts(d) {
  const risk = (d && d.risk) || {};
  const rank = { crit: 0, warn: 1, unk: 2, ok: 3 };
  return hmAllHosts().filter((x) => x.st === 'crit' || x.st === 'warn' || ((risk[x.h.id] || {}).score || 0) >= 40)
    .sort((a, b) => rank[a.st] - rank[b.st] || ((risk[b.h.id] || {}).score || 0) - ((risk[a.h.id] || {}).score || 0) || a.h.name.localeCompare(b.h.name));
}

/** Netwerk in één oogopslag: internet → netwerkapparaten → per soort de apparaten met hun status */
function hmMap() {
  const hosts = state.hosts.filter((h) => +h.enabled !== 0);
  const st = (h) => { const s = hostStatus(h); return s === 2 ? 'crit' : s === 1 ? 'warn' : s === 0 ? 'ok' : 'unk'; };   // geen gegevens is nooit groen
  const chip = (h) => `<button type="button" class="hm-host ${st(h)}" data-hm-go="dashboard?server=${encodeURIComponent(h.name)}" title="${esc(h.name)}">${hmShape(st(h))}<span>${esc(h.name)}</span></button>`;
  const net = hosts.filter((h) => hostGroup(h) === 'network');
  const groups = ['server', 'storage', 'smarthome', 'camera', 'power', 'other'].map((g) => [g, hosts.filter((h) => hostGroup(h) === g)]).filter(([, l]) => l.length);
  const worst = (l) => ['crit', 'warn', 'unk'].find((x) => l.map(st).includes(x)) || 'ok';
  return `<section class="hm-card hm-map" aria-labelledby="hm-h-map">
    <div class="hm-card-head"><h2 id="hm-h-map">${esc(L('Netwerk in één oogopslag'))}</h2>
      <span class="hm-legend">${['ok', 'warn', 'crit', 'unk'].map((s) => `<span>${hmShape(s)} ${esc(HM_ST[s]())}</span>`).join('')}</span></div>
    <div class="hm-tree">
      <div class="hm-inet"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>${esc(L('Internet'))}</div>
      <div class="hm-vline"></div>
      <div class="hm-netrow">${net.length ? net.map(chip).join('') : `<span class="muted small">${esc(L('Router, switches en access points: geef apparaten de soort Router, Switch of Access point'))}</span>`}</div>
      <div class="hm-vline"></div>
      <div class="hm-groups">${groups.map(([g, l]) => `<div class="hm-group">
        <div class="hm-group-head"><b>${GROUP_INFO[g][0]} ${esc(GROUP_INFO[g][1]())}</b><span class="hm-st ${worst(l)}">${hmShape(worst(l))} ${l.length}</span></div>
        <div class="hm-hosts">${l.map(chip).join('')}</div></div>`).join('')}</div>
    </div></section>`;
}

/** Servers: CPU, RAM en de volste disk nu, uit de checks van die server; met (1.144.0) het risico en, compact, zonder balken */
function hmServers(all, title, d, compact = false) {
  const risk = (d && d.risk) || {};
  const list = compact ? all.filter((x) => x.cpu !== null || x.ram !== null || x.disk !== null).sort((a, b) => a.h.name.localeCompare(b.h.name)) : all;
  if (!list.length) return '';
  if (compact) {
    return `<section class="hm-card" aria-labelledby="hm-h-srv2"><div class="hm-card-head"><h2 id="hm-h-srv2">${esc(title)}</h2><span class="muted small">${esc(L('{n} servers', { n: list.length }))}</span></div>
      <div class="hm-srv-compact">${list.map(({ h, st, cpu, ram, disk }) => `<button type="button" class="hm-srv-mini ${st}" data-hm-go="dashboard?server=${encodeURIComponent(h.name)}">
        ${hmShape(st, 10)} <b>${esc(h.name)}</b><span class="muted small">${[cpu !== null ? 'CPU ' + cpu + '%' : '', ram !== null ? 'RAM ' + ram + '%' : '', disk !== null ? L('Disk') + ' ' + disk + '%' : ''].filter(Boolean).join(' · ')}</span>
        ${risk[h.id] && risk[h.id].score ? urgPill(risk[h.id].score, risk[h.id].reasons, true) : ''}</button>`).join('')}</div></section>`;
  }
  const bar = (k, v) => (v === null ? '' : `<div class="hm-bar"><span>${esc(k)}</span><div><i class="${v >= 90 ? 'crit' : v >= 75 ? 'warn' : ''}" style="width:${Math.min(100, v)}%"></i></div><b>${v}%</b></div>`);
  const why = (h) => { const p = h.checks.filter((c) => +c.enabled && !c.maintenance && +c.status >= 1 && +c.status <= 2).sort((a, b) => b.status - a.status)[0]; return p ? p.name : ''; };
  return `<section class="hm-card" aria-labelledby="hm-h-srv">
    <div class="hm-card-head"><h2 id="hm-h-srv">${esc(title)}</h2><span class="muted small">${esc(L('{n} servers · CPU, RAM en disk nu', { n: list.length }))}</span></div>
    <div class="hm-srv-grid">${list.map(({ h, st, cpu, ram, disk }) => {
      const up = stats.uptime[h.id];
      const rk = risk[h.id];
      return `<button type="button" class="hm-srv ${st}" data-hm-go="dashboard?server=${encodeURIComponent(h.name)}">
        <span class="hm-srv-top"><b>${esc(h.name)}</b><span class="hm-st ${st}">${hmShape(st)} ${esc(st === 'ok' ? HM_ST.ok() : why(h) || HM_ST[st]())}</span></span>
        <span class="hm-srv-sub">${esc(kindLabel(hostKind(h)))}${up && up.d7 !== null ? ' · ' + esc(L('{p} bereikbaar (7 d)', { p: pct(up.d7) })) : ''}</span>
        ${rk && rk.score ? `<span class="hm-srv-risk" data-hm-risk="${h.id}" title="${esc(L('Aanvalspad en impact'))}">${urgPill(rk.score, rk.reasons, true)} <span class="small">${esc(L('risico'))}: ${esc(rk.reasons.join(' · '))}</span></span>` : ''}
        ${bar('CPU', cpu)}${bar('RAM', ram)}${bar(L('Disk'), disk)}</button>`;
    }).join('')}</div></section>`;
}

const hmDays = (series) => Object.keys(series || {});
const hmDayLbl = (day, n) => new Date(day + 'T12:00:00').toLocaleDateString(LOCALE, n <= 7 ? { weekday: 'short' } : { day: 'numeric', month: 'numeric' });

/** Beschikbaarheid per dag: lijn met punten, één as in procenten */
function hmAvailChart(d) {
  const days = hmDays(d.avail.series), vals = days.map((k) => d.avail.series[k]);
  const have = vals.filter((v) => v !== null);
  const W = 320, H = 150, x0 = 40, x1 = 312, y0 = 10, y1 = 120;
  let body;
  if (have.length < 1) body = `<p class="hm-empty">${esc(L('Nog geen gegevens in deze periode'))}</p>`;
  else {
    const lo = Math.min(99, Math.floor(Math.min(...have))), hi = 100;
    const X = (i) => x0 + (days.length > 1 ? i * (x1 - x0) / (days.length - 1) : (x1 - x0) / 2);
    const Y = (v) => y0 + (hi - v) / (hi - lo || 1) * (y1 - y0);
    let path = ''; vals.forEach((v, i) => { if (v !== null) path += `${path ? 'L' : 'M'}${X(i).toFixed(1)} ${Y(v).toFixed(1)}`; });
    const step = Math.ceil(days.length / 8);
    body = `<svg viewBox="0 0 ${W} ${H}" class="hm-chart" role="img" aria-label="${esc(L('Beschikbaarheid per dag'))}">
      <g class="ax">${[hi, (hi + lo) / 2, lo].map((v) => `<text x="0" y="${(Y(v) + 4).toFixed(1)}">${dec(+v.toFixed(1))}%</text><path d="M${x0} ${Y(v).toFixed(1)}H${x1}"/>`).join('')}</g>
      <path class="ln s1" d="${path}"/>
      ${vals.map((v, i) => (v === null ? '' : `<circle class="dot s1" cx="${X(i).toFixed(1)}" cy="${Y(v).toFixed(1)}" r="4"><title>${esc(hmDayLbl(days[i], 99))}: ${pct(v)}</title></circle>`)).join('')}
      <g class="ax lbl">${days.map((k, i) => (i % step ? '' : `<text x="${X(i).toFixed(1)}" y="${H - 6}" text-anchor="middle">${esc(hmDayLbl(k, days.length))}</text>`)).join('')}</g></svg>`;
  }
  return `<section class="hm-card" aria-labelledby="hm-h-up"><h2 id="hm-h-up">${esc(L('Beschikbaarheid'))}</h2>
    <p class="hm-card-sub">${esc(L('Alle servers · deze periode'))}${d.avail.now !== null ? ` · <b>${pct(d.avail.now)}</b>` : ''}</p>${body}</section>`;
}

/** IDS per dag: gestapelde staven, geblokkeerd en gemeld */
function hmIdsChart(d) {
  const s = d.ids.series, days = hmDays(s.blk);
  const rep = days.map((k) => (+s.warn[k] || 0) + (+s.crit[k] || 0)), blk = days.map((k) => +s.blk[k] || 0);
  const max = Math.max(1, ...days.map((k, i) => rep[i] + blk[i]));
  const W = 320, H = 150, x0 = 8, x1 = 312, base = 120, gap = 2;
  const bw = Math.max(3, Math.min(26, (x1 - x0) / Math.max(1, days.length) - 6));
  const X = (i) => x0 + (i + 0.5) * (x1 - x0) / Math.max(1, days.length) - bw / 2;
  const step = Math.ceil(days.length / 8);
  return `<section class="hm-card" aria-labelledby="hm-h-ids"><h2 id="hm-h-ids">${esc(L('IDS-gebeurtenissen'))}</h2>
    <p class="hm-card-sub hm-legend"><span><i class="sw s1"></i>${esc(L('Geblokkeerd'))}</span><span><i class="sw s2"></i>${esc(L('Gemeld'))}</span></p>
    ${days.length ? `<svg viewBox="0 0 ${W} ${H}" class="hm-chart" role="img" aria-label="${esc(L('IDS-gebeurtenissen per dag: geblokkeerd en gemeld'))}">
      <g class="ax"><path d="M${x0} ${base}H${x1}"/></g>
      ${days.map((k, i) => { const hb = blk[i] / max * 108, hr = rep[i] / max * 108;
        return `<g><title>${esc(hmDayLbl(k, 99))}: ${esc(L('{b} geblokkeerd, {r} gemeld', { b: blk[i], r: rep[i] }))}</title>
          ${hb ? `<rect class="s1" x="${X(i).toFixed(1)}" y="${(base - hb).toFixed(1)}" width="${bw.toFixed(1)}" height="${hb.toFixed(1)}" rx="3"/>` : ''}
          ${hr ? `<rect class="s2" x="${X(i).toFixed(1)}" y="${(base - hb - (hb ? gap : 0) - hr).toFixed(1)}" width="${bw.toFixed(1)}" height="${hr.toFixed(1)}" rx="3"/>` : ''}
          ${i % step ? '' : `<text class="axt" x="${(X(i) + bw / 2).toFixed(1)}" y="${H - 6}" text-anchor="middle">${esc(hmDayLbl(k, days.length))}</text>`}</g>`; }).join('')}</svg>`
      : `<p class="hm-empty">${esc(L('Nog geen gegevens in deze periode'))}</p>`}</section>`;
}

/** Open CVE's per ernst */
function hmVulnCard(v) {
  const rows = [[4, L('Kritiek'), 'crit'], [3, L('Hoog'), 's2'], [2, L('Middel'), 's4'], [1, L('Laag'), 'mut']].map(([k, l, c]) => ({ n: v.sev[k] || 0, l, c }));
  return `<section class="hm-card" aria-labelledby="hm-h-cve"><h2 id="hm-h-cve">${esc(L('Kwetsbaarheden (CVE)'))}</h2>
    <p class="hm-card-sub"><b>${esc(L('{n} open', { n: hmNum(v.total) }))}</b> · ${esc(L('{n} op te lossen met een update', { n: hmNum(v.fix) }))}${v.kev ? ' · ' + esc(L('{n} in KEV (actief misbruikt)', { n: v.kev })) : ''}</p>
    ${v.total ? `<div class="hm-stack" role="img" aria-label="${esc(rows.map((r) => `${r.n} ${r.l}`).join(', '))}">${rows.filter((r) => r.n).map((r) => `<i class="${r.c}" style="flex:${r.n}"></i>`).join('')}</div>` : ''}
    <div class="hm-sevs">${rows.map((r) => `<button type="button" class="hm-sevbox" data-hm-go="vulns"><i class="sw ${r.c}"></i><span>${esc(r.l)}</span><b>${hmNum(r.n)}</b></button>`).join('')}</div>
    ${v.hosts.length ? `<p class="hm-card-sub">${esc(L('Meeste open'))}: ${v.hosts.map((h) => `<b>${esc(h.name)}</b> (${h.n})`).join(', ')}</p>` : ''}</section>`;
}

function hmNetCard(n) {
  const tiles = [[L('Apparaten online'), hmNum(n.online), L('{n} bekend · {m} nieuw', { n: hmNum(n.total), m: n.new })],
    n.adguard ? [L('DNS geblokkeerd'), dec((n.adguard.blocked / n.adguard.queries * 100).toFixed(1)) + '%', L('{b} van {q} vragen', { b: hmNum(n.adguard.blocked), q: hmNum(n.adguard.queries) })] : null,
    n.aps ? [L('Wifi-clients'), hmNum(n.wifi), L('{n} access points', { n: n.aps })] : null,
    [L('Geblokkeerd'), hmNum(n.blocked), n.risky ? L('{n} met een riskante poort', { n: n.risky }) : L('geen riskante poorten')]].filter(Boolean);
  const vals = hmVals(n.series);
  return `<section class="hm-card hm-grow" aria-labelledby="hm-h-net"><div class="hm-card-head"><h2 id="hm-h-net">${esc(L('Netwerk'))}</h2><button type="button" class="link-btn" data-hm-go="net">${esc(L('Naar Netwerk'))}</button></div>
    <div class="hm-mini">${tiles.map(([l, v, s]) => `<div><span>${esc(l)}</span><b>${v}</b><small>${esc(s)}</small></div>`).join('')}</div>
    ${vals.length > 1 ? `<p class="hm-card-sub">${esc(L('Apparaten op het netwerk per dag'))}</p>${hmSpark(vals, 's3 wide')}` : ''}</section>`;
}

function hmTimeline(evs) {
  const st = (e) => (e.kind === 'info' ? 'info' : e.kind === 'notify' ? hmStOf(e.status) : e.status >= 2 ? 'crit' : 'warn');
  const time = (at) => { const t = new Date(at.replace(' ', 'T')); return t.toDateString() === new Date().toDateString()
    ? t.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) : t.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }); };
  return `<section class="hm-card hm-grow" aria-labelledby="hm-h-tl"><div class="hm-card-head"><h2 id="hm-h-tl">${esc(L('Laatste gebeurtenissen'))}</h2><button type="button" class="link-btn" data-hm-go="alerts">${esc(L('Alle meldingen'))}</button></div>
    ${evs.length ? `<ol class="hm-tl">${evs.map((e) => { const s = st(e); return `<li><time>${esc(time(e.at))}</time>${s === 'info'
      ? `<svg class="hm-shape info" width="10" height="10" viewBox="0 0 10 10" role="img" aria-label="${esc(L('Info'))}"><circle cx="5" cy="5" r="4" fill="none" stroke-width="2"/></svg>` : hmShape(s)}
      <span><b>${esc(e.title)}</b>${e.host ? `<small>${esc(e.host)}</small>` : ''}</span></li>`; }).join('')}</ol>` : `<p class="hm-empty">${esc(L('Nog niets gebeurd'))}</p>`}</section>`;
}

/** 1.135.0: CPU en RAM over alle servers, gemiddeld per uur (24 uur), twee lijnen op één as in procenten */
function hmResChart(r) {
  const W = 640, H = 250, x0 = 34, x1 = 630, y0 = 10, y1 = 220;
  const have = (r.cpu || []).concat(r.ram || []).some((v) => v !== null);
  const X = (i) => x0 + i * (x1 - x0) / 23, Y = (v) => y1 - v / 100 * (y1 - y0);
  const path = (vals) => { let p = '', pen = false; vals.forEach((v, i) => { if (v === null) { pen = false; return; } p += `${pen ? 'L' : 'M'}${X(i).toFixed(1)} ${Y(v).toFixed(1)}`; pen = true; }); return p; };
  const hour = (i) => new Date((r.start + i * 3600) * 1000).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  const last = (vals) => { for (let i = vals.length - 1; i >= 0; i--) if (vals[i] !== null) return vals[i]; return null; };
  const now = { cpu: last(r.cpu || []), ram: last(r.ram || []) };
  return `<section class="hm-card hm-res" aria-labelledby="hm-h-res"><div class="hm-card-head"><h2 id="hm-h-res">${esc(L('Resourcegebruik · 24 uur'))}</h2>
    <span class="hm-legend"><span><i class="sw s1"></i>CPU${now.cpu !== null ? ` <b>${dec(now.cpu.toFixed(0))}%</b>` : ''}</span><span><i class="sw s3"></i>RAM${now.ram !== null ? ` <b>${dec(now.ram.toFixed(0))}%</b>` : ''}</span></span></div>
    <p class="hm-card-sub">${esc(L('Gemiddeld over {n} servers met een CPU- of RAM-check, per uur', { n: r.hosts }))}</p>
    ${have ? `<svg viewBox="0 0 ${W} ${H}" class="hm-chart" role="img" aria-label="${esc(L('CPU en RAM per uur over de laatste 24 uur'))}">
      <g class="ax">${[0, 50, 100].map((v) => `<text x="0" y="${(Y(v) + 4).toFixed(1)}">${v}%</text><path d="M${x0} ${Y(v).toFixed(1)}H${x1}"/>`).join('')}</g>
      <path class="ln s3" d="${path(r.ram)}"/><path class="ln s1" d="${path(r.cpu)}"/>
      ${(r.cpu || []).map((v, i) => `<rect class="hit" x="${(X(i) - 12).toFixed(1)}" y="${y0}" width="24" height="${y1 - y0}"><title>${esc(hour(i))} · CPU ${v === null ? '–' : dec(v.toFixed(1)) + '%'} · RAM ${r.ram[i] === null ? '–' : dec(r.ram[i].toFixed(1)) + '%'}</title></rect>`).join('')}
      <g class="ax lbl">${[0, 6, 12, 18, 23].map((i) => `<text x="${X(i).toFixed(1)}" y="${H - 8}" text-anchor="middle">${esc(hour(i))}</text>`).join('')}</g></svg>`
      : `<p class="hm-empty">${esc(L('Nog geen CPU- of RAM-metingen: voeg die checks toe bij je servers'))}</p>`}</section>`;
}

/** 1.135.0: recente incidenten (IDS), open eerst, met de ernst als woord en vorm */
function hmIncidents(d) {
  const list = d.incidents || [];
  const sev = (i) => (i.sev >= 2 ? ['crit', L('Kritiek')] : ['warn', L('Hoog')]);
  return `<section class="hm-card hm-inc" aria-labelledby="hm-h-inc"><div class="hm-card-head"><h2 id="hm-h-inc">${esc(L('Recente incidenten'))}</h2>
    <button type="button" class="link-btn" data-hm-go="ids">${esc(d.ids.open_total ? L('{n} open', { n: d.ids.open_total }) : L('Naar IDS'))}</button></div>
    ${list.length ? `<ul>${list.slice(0, 5).map((i) => { const [c, l] = sev(i); return `<li class="${i.open ? '' : 'done'}">${hmShape(i.open ? c : 'ok', 11)}
      <span><small class="hm-sevlbl ${i.open ? c : 'ok'}">${esc(i.open ? l : L('Afgehandeld'))}</small><b>${esc(i.title)}</b><small>${esc(i.host)} · ${esc(fmtUntil(i.at))}${i.hits > 1 ? ' · ' + esc(L('{n}×', { n: i.hits })) : ''}</small></span></li>`; }).join('')}</ul>`
      : `<p class="hm-empty">${hmShape('ok', 14)} ${esc(L('Geen incidenten de laatste 14 dagen'))}</p>`}</section>`;
}

// ---------------------------------------------------------------- ⚠ Incidenten (1.142.0)
// Eén lijst uit monitoring (critical ≥ 5 min), IDS (critical) en kwetsbaarheden (actief misbruikt), met status, eigenaar en tijdlijn
let incData = null;
let incFilter = 'active';
const INC_ST = { open: () => L('open'), ack: () => L('in behandeling'), resolved: () => L('opgelost') };
const INC_SRC = { check: ['📈', () => L('Monitoring')], ids: ['🛡️', () => 'IDS'], vuln: ['🐞', () => L('Kwetsbaarheden')] };

async function loadIncidents() {
  if (!$('#inc-list')) return;
  try { incData = await api('incidents'); } catch (ex) { $('#inc-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderIncidents();
}

function incVisible() {
  const q = ($('#inc-search').value || '').trim().toLowerCase();
  return incData.incidents.filter((i) => (incFilter === 'all' || (incFilter === 'active' ? i.status !== 'resolved' : i.status === incFilter))
    && (!q || [i.title, i.host || '', i.owner || '', i.detail].join(' ').toLowerCase().includes(q)));
}

function renderIncidents() {
  if (!incData) return;
  try { ccIncCharts(); } catch (e) { /* grafiek mag de lijst nooit tegenhouden */ }
  const c = incData.counts;
  $('#inc-badge').hidden = !c.open;
  $('#inc-badge').textContent = c.open || '';
  $('#inc-kpis').innerHTML = `
    <div class="kpi ${c.open ? 'bad' : ''}"><div class="kpi-lbl">${L('Open')}</div><div class="kpi-val">${c.open}</div><div class="kpi-sub">${L('nog niemand mee bezig')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('In behandeling')}</div><div class="kpi-val">${c.ack}</div><div class="kpi-sub">${L('met een eigenaar')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Opgelost (30 dagen)')}</div><div class="kpi-val ${c.resolved ? 'good-txt' : ''}">${c.resolved}</div><div class="kpi-sub">${L('vanzelf of door iemand')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Gemiddeld opgelost na')}</div><div class="kpi-val kpi-val-txt">${c.mttr !== null ? fmtDur(c.mttr) : '–'}</div><div class="kpi-sub">${L('laatste 30 dagen')}</div></div>`;
  const n = (f) => incData.incidents.filter((i) => (f === 'all' ? true : f === 'active' ? i.status !== 'resolved' : i.status === f)).length;
  $('#inc-filter').innerHTML = [['active', L('Nog bezig')], ['open', L('Open')], ['ack', L('In behandeling')], ['resolved', L('Opgelost')], ['all', L('Alles')]]
    .map(([k, l]) => `<button type="button" class="dchip ${incFilter === k ? 'on' : ''}" data-incf="${k}">${l} <b>${n(k)}</b></button>`).join('');
  const list = incVisible();
  $('#inc-list').innerHTML = list.length ? `<div class="card inc-card"><table class="inc-table"><thead><tr><th title="${esc(L('Urgentie: ernst, duur, bereikbaar, afhankelijke servers en beveiliging'))}">${L('Urgentie')}</th><th></th><th>${L('Incident')}</th><th>${L('Status')}</th><th>${L('Eigenaar')}</th><th>${L('Geopend')}</th><th class="num">${L('Duur')}</th></tr></thead><tbody>
    ${list.map((i) => {
      const [ic, src] = INC_SRC[i.source] || ['•', () => i.source];
      const end = i.resolved_at ? new Date(i.resolved_at.replace(' ', 'T')) : new Date();
      const dur = Math.max(0, (end - new Date(i.opened_at.replace(' ', 'T'))) / 1000);
      return `<tr class="inc-row inc-${i.status}" data-inc="${i.id}" tabindex="0">
        <td>${i.status === 'resolved' ? '<span class="muted small">–</span>' : urgPill(i.urgency, i.why)}</td>
        <td title="${esc(src())}">${ic}</td>
        <td><b>${esc(i.title)}</b>${i.status !== 'resolved' ? ' ' + aiBtn('incident', i.id) : ''}${i.parts > 1 ? ` <span class="muted small">· ${esc(L('{n} stappen', { n: i.parts }))}</span>` : ''}${i.detail ? `<div class="muted small">${esc(i.detail.length > 120 ? i.detail.slice(0, 120) + '…' : i.detail)}</div>` : ''}</td>
        <td><span class="inc-st inc-st-${i.status}">${INC_ST[i.status]()}</span>${i.notes ? ` <span class="muted small" title="${esc(L('notities'))}">💬 ${i.notes}</span>` : ''}</td>
        <td>${i.owner ? esc(i.owner) : '<span class="muted">–</span>'}</td>
        <td class="small">${agoSpan(i.opened_at)}</td>
        <td class="num small">${fmtDur(dur)}</td></tr>`;
    }).join('')}</tbody></table></div>`
    : `<div class="card"><p class="muted" style="margin:0">${incFilter === 'active' && !$('#inc-search').value ? '✔ ' + L('Geen open incidenten.') : L('Niets gevonden.')}</p></div>`;
}

/** 1.152.1: een zaak op het tabblad Incidenten (tabel incidents). Heette eerst ook incidentDialog en overschreef zo
 * het venster van een IDS-incident: dat gaf "Incident niet gevonden". */
async function caseDialog(id) {
  let i;
  try { i = await api('incident_info', null, { id }); } catch (ex) { return toast(ex.message, 'err'); }
  const [ic, src] = INC_SRC[i.source] || ['•', () => i.source];
  const users = (incData && incData.users) || [];
  const KIND = { system: '⚙️', status: '🔁', owner: '👤', note: '💬' };
  const go = i.source === 'check' ? ['dashboard', L('Naar Monitoring')] : i.source === 'ids' ? ['ids', L('Naar IDS')] : ['vulns', L('Naar Kwetsbaarheden')];
  const { form } = modal({
    title: `${ic} ${i.title}`, wide: true,
    body: `<div style="padding:1rem 1.2rem">
      <p class="muted small">${esc(src())}${i.host ? ' · ' + esc(i.host) : ''} · ${L('geopend {t}', { t: esc(fmtDateTime(i.opened_at)) })}${i.resolved_at ? ' · ' + L('opgelost {t}', { t: esc(fmtDateTime(i.resolved_at)) }) : ''}</p>
      ${i.detail ? `<p>${esc(i.detail)}</p>` : ''}
      ${aiOn() ? `<div class="btn-row">${aiBtn('incident', +id, true)}${i.status === 'resolved' && state.ai.report && state.ai.ask ? `<button type="button" class="btn small-btn ai-btn" data-ai-kind="report" data-ai-ref="${+id}">📝 ${L('AI-verslag')}</button>` : ''}</div>` : ''}
      ${i.status !== 'resolved' ? `<p class="small">${urgPill(i.urgency, i.why)} <b>${L('Urgentie {u}', { u: i.urgency })}</b>: ${esc(i.why.join(' · '))}</p>` : ''}
      ${i.path && i.path.length ? `<div class="inc-path" aria-label="${esc(L('Pad'))}">${i.path.map((p) => `<span>${esc(p)}</span>`).join('<i aria-hidden="true">→</i>')}</div>` : ''}
      ${i.steps && i.steps.length ? `<h3>${L('Stappen')}</h3><ol class="inc-steps">${i.steps.map((s) => `<li class="${s.severity >= 2 ? 'crit' : 'warn'}"><b>${esc(s.title)}</b>
        <span class="muted small">${esc(fmtDateTime(s.at))}${s.ip ? ' · ' + esc(s.ip) : ''}</span></li>`).join('')}</ol>` : ''}
      <div class="row2">
        <label>${L('Status')}<select name="status" class="need-user">${Object.keys(INC_ST).map((k) => `<option value="${k}" ${i.status === k ? 'selected' : ''}>${INC_ST[k]()}</option>`).join('')}</select></label>
        <label>${L('Eigenaar')}<select name="owner" class="need-user"><option value="">–</option>${users.map((u) => `<option ${i.owner === u ? 'selected' : ''}>${esc(u)}</option>`).join('')}</select></label>
      </div>
      <label>${L('Notitie')} <span class="hint">${L('(wat je gedaan of gevonden hebt)')}</span><textarea name="note" rows="3" maxlength="2000"></textarea></label>
      <h3>${L('Tijdlijn')}</h3>
      <ul class="inc-timeline">${i.notes.slice().reverse().map((n) => `<li class="k-${esc(n.kind)}"><span class="ic">${KIND[n.kind] || '•'}</span>
        <div><div>${esc(n.text).replace(/\n/g, '<br>')}</div><div class="muted small">${esc(n.by_user)} · ${esc(fmtDateTime(n.created_at))}</div></div></li>`).join('')}</ul>
    </div>`,
    buttons: [
      { label: go[1] + ' →', style: 'margin-right:auto', onClick: () => { $('#modal').close(); showTab(go[0]); } },
      ...(i.host_id ? [{ label: '🎯 ' + L('Aanvalspad'), onClick: () => exposureDialog(+i.host_id) }] : []),
      { label: L('Sluiten'), onClick: () => $('#modal').close() },
      { label: '✔ ' + L('Opslaan'), cls: 'primary need-user', submit: true },
    ],
    onSubmit: async (v) => {
      await api('incident_set', { id: i.id, status: v.status, owner: v.owner, note: v.note });
      toast(L('Opgeslagen'));
      loadIncidents();
    },
  });
  return form;
}

(function incWire() {
  if (!$('#inc-list')) return;
  $('#inc-filter').addEventListener('click', (e) => { const b = e.target.closest('[data-incf]'); if (b) { incFilter = b.dataset.incf; renderIncidents(); } });
  $('#inc-search').addEventListener('input', () => renderIncidents());
  const open = (e) => { const r = e.target.closest('[data-inc]'); if (r) caseDialog(+r.dataset.inc); };
  $('#inc-list').addEventListener('click', open);
  $('#inc-list').addEventListener('keydown', (e) => { if (e.key === 'Enter') open(e); });
})();

// 📄 Rapport (PDF): afdrukbare pagina in een nieuw venster, meteen het afdrukvenster (daar: Opslaan als PDF)
(function anPdfWire() {
  const b = $('#an-pdf');
  if (!b) return;
  b.onclick = () => {
    const w = window.open('api.php?' + new URLSearchParams({ action: 'report', period: anPeriod }), '_blank');
    if (!w) return toast(L('Sta pop-ups toe voor RzSense om het rapport te openen'), 'err');
    w.addEventListener('load', () => setTimeout(() => { try { w.print(); } catch (e) { /* zelf afdrukken */ } }, 300));
  };
})();

// ---------------------------------------------------------------- Internet (1.157.0): Instellingen → Monitoring → Internet
async function loadInet() {
  const box = $('#inet-state');
  if (!box) return;
  let r;
  try { r = await api('inet_state'); } catch (ex) { box.innerHTML = `<p class="err-txt small">${esc(ex.message)}</p>`; return; }
  const fmtT = (t) => new Date(t * 1000).toLocaleString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  const plan = { down: +($('[name=inet_down]').value || 1000), up: +($('[name=inet_up]').value || 1000) };
  const last = r.last;
  const val = (d) => (last && last[d] !== null ? `<b>${Math.round(last[d]).toLocaleString(LOCALE)}</b> <span class="muted">Mbit/s · ${Math.round(last[d] / plan[d] * 100)}%</span>` : '–');
  // Verloop van 30 dagen: download en upload als lijntjes, in procent van het abonnement
  const hist = r.history.filter((x) => x.down !== null);
  let chart = `<p class="muted small">${L('Het verloop verschijnt na een paar snelheidstesten.')}</p>`;
  if (hist.length >= 2) {
    const W = 600, H = 90, t0 = hist[0].at, t1 = hist[hist.length - 1].at || t0 + 1;
    const x = (t) => ((t - t0) / Math.max(1, t1 - t0)) * W;
    const y = (v, d) => H - Math.min(1.1, v / plan[d]) / 1.1 * (H - 6) - 3;
    const line = (d) => hist.map((p, i) => `${i ? 'L' : 'M'}${x(p.at).toFixed(1)},${y(p[d] || 0, d).toFixed(1)}`).join('');
    chart = `<svg class="inet-chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="${esc(L('Snelheid, 30 dagen'))}">
      <line x1="0" x2="${W}" y1="${y(plan.down, 'down')}" y2="${y(plan.down, 'down')}" class="inet-plan"/>
      <path d="${line('down')}" class="inet-down"/><path d="${line('up')}" class="inet-up"/></svg>
      <div class="muted small"><span class="inet-key d"></span> ${L('download')} <span class="inet-key u"></span> ${L('upload')} · ${L('stippellijn = je abonnement')} · ${L('{n} testen in 30 dagen', { n: hist.length })}</div>`;
  }
  box.innerHTML = `
    ${r.host ? '' : `<div class="callout"><p>${L('Er is nog geen Internet-check. Met één klik komt er een apparaat <b>Internet</b> bij met vier checks: ping, responstijd, download en upload.')}</p>
      <button type="button" class="btn primary" id="inet-install">🌍 ${L('Internet-check toevoegen')}</button></div>`}
    <div class="inet-grid">
      <div><span class="muted small">${L('Download')}</span><div class="inet-v">↓ ${val('down')}</div></div>
      <div><span class="muted small">${L('Upload')}</span><div class="inet-v">↑ ${val('up')}</div></div>
      <div><span class="muted small">${L('Ping naar de testserver')}</span><div class="inet-v">${last && last.lat !== null ? `<b>${dec(String(Math.round(last.lat * 10) / 10))}</b> <span class="muted">ms${last.colo ? ' · ' + esc(last.colo) : ''}</span>` : '–'}</div></div>
      <div><span class="muted small">${L('Laatste test')}</span><div>${last ? esc(fmtT(last.at)) + (last.err ? ` <span class="err-txt small">✘ ${esc(last.err)}</span>` : ` <span class="muted small">(${L('{n} MB', { n: Math.round(last.mb) })})</span>`) : L('nog niet')}</div>
        <span class="muted small">${r.next ? L('Volgende: {t}', { t: esc(fmtT(r.next)) }) : ''}</span></div>
    </div>
    ${chart}
    <p class="muted small">${r.gateway ? L('Je router (standaardgateway van deze server): {ip}', { ip: esc(r.gateway) }) : L('Geen standaardgateway gevonden op deze server.')}</p>
    ${r.host ? `<p><button type="button" class="btn" id="inet-now" ${r.running ? 'disabled' : ''}>⚡ ${r.running ? L('Test loopt…') : L('Nu testen')}</button>
      <span class="muted small">${L('Duurt tot een halve minuut en trekt je lijn even vol.')}</span></p>` : ''}`;
  const ins = $('#inet-install');
  if (ins) ins.onclick = async () => { ins.disabled = true; try { const x = await api('inet_install', {}); toast(x.message); refresh(); loadInet(); } catch (ex) { toast(ex.message, 'err'); ins.disabled = false; } };
  const now = $('#inet-now');
  if (now) now.onclick = async () => {
    now.disabled = true; now.textContent = '⏳ ' + L('Test loopt…');
    try { const x = await api('inet_speed_now', {}); toast(x.message); } catch (ex) { toast(ex.message, 'err'); }
    loadInet(); refresh();
  };
}
