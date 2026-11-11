// ---------------------------------------------------------------- 1.219.0: de RzSense-kern
// Reactor-meters voor de server waar RzSense zelf op draait (CPU, RAM, disk, cron) en een paneel met de rest.
// Vervangt de meters van "de zwaarste server" (1.199.0). Gegevens: api self_status, elke 30 s zolang Monitoring open is.
let selfStat = null, selfAt = 0, selfBusy = false;
async function selfLoad(force) {
  if (selfBusy || document.hidden || (!force && Date.now() - selfAt < 25000)) return;
  if (!$('#mini-res') || ($('#tab-dashboard') && $('#tab-dashboard').hidden)) return;
  selfBusy = true;
  try { selfStat = await api('self_status'); selfAt = Date.now(); } catch (ex) { selfAt = Date.now(); } finally { selfBusy = false; }
  miniRes();
}
setInterval(() => selfLoad(false), 30000);
const rzPolar = (r, a) => [31 + r * Math.cos((a - 90) * Math.PI / 180), 31 + r * Math.sin((a - 90) * Math.PI / 180)];
const rzArc = (r, a0, a1) => { const [x0, y0] = rzPolar(r, a0), [x1, y1] = rzPolar(r, a1); return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`; };
/** Eén ronde meter: lvl 0 = cyaan, 1 = oranje, 2 = rood */
function rzGauge(key, label, pct, val, unit, sub, lvl, tip) {
  const a0 = -135, a1 = 135, av = pct === null ? a0 : a0 + (a1 - a0) * Math.max(0, Math.min(1, pct / 100));
  let ticks = '';
  for (let i = 0; i < 28; i++) {
    const a = a0 + (a1 - a0) * i / 27, [x0, y0] = rzPolar(29.5, a), [x1, y1] = rzPolar(i % 9 === 0 ? 26 : 27.5, a);
    ticks += `<line x1="${x0.toFixed(1)}" y1="${y0.toFixed(1)}" x2="${x1.toFixed(1)}" y2="${y1.toFixed(1)}" class="${a <= av && pct !== null ? 'on' : ''}"/>`;
  }
  const [dx, dy] = rzPolar(22, av);
  return `<div class="rzk-g l${lvl}" title="${esc(tip || '')}"><svg viewBox="0 0 62 62" aria-hidden="true"><defs><linearGradient id="rzk-${key}" x1="0" y1="1" x2="1" y2="0">
      <stop offset="0" class="s0"/><stop offset="1" class="s1"/></linearGradient></defs><g class="tk">${ticks}</g>
      <path class="bg" d="${rzArc(22, a0, a1)}"/>${pct === null ? '' : `<path class="arc" d="${rzArc(22, a0, Math.max(a0 + 2, av))}" stroke="url(#rzk-${key})"/><circle class="tip" cx="${dx.toFixed(1)}" cy="${dy.toFixed(1)}" r="2.6"/>`}</svg>
    <span class="v">${esc(val)}<small>${esc(unit)}</small></span><span class="k">${esc(label)}</span><span class="s">${esc(sub)}</span></div>`;
}
const RZ_SHIELD = '<path d="M27 15l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9v-6z" class="sh"/>';
function rzCoreSvg(st) {
  const mark = st === 0 ? '<path d="M23.5 24l2.5 2.5 4.5-5" class="ok"/>' : `<path d="M27 20.5v5" class="mk"/><circle cx="27" cy="28.6" r="1.1" class="mkd"/>`;
  return `<svg viewBox="0 0 54 54" class="rzk-core-ic" aria-hidden="true"><circle cx="27" cy="27" r="25" class="ring"/>
    <g class="spin">${[0, 120, 240].map((a) => `<path d="${rzArcC(22, a, a + 70)}" class="seg"/>`).join('')}</g>
    <g class="spin2"><circle cx="27" cy="27" r="18" class="dash"/></g>${RZ_SHIELD}${mark}</svg>`;
}
function rzArcC(r, a0, a1) { const p = (a) => [27 + r * Math.cos((a - 90) * Math.PI / 180), 27 + r * Math.sin((a - 90) * Math.PI / 180)]; const [x0, y0] = p(a0), [x1, y1] = p(a1); return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 0 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`; }
const rzUp = (s) => (s === null || s === undefined ? '' : s >= 86400 ? L('up {n} d', { n: Math.floor(s / 86400) }) : s >= 3600 ? L('up {n} u', { n: Math.floor(s / 3600) }) : L('up {n} min', { n: Math.max(1, Math.floor(s / 60)) }));
const rzAgo = (s) => (s === null || s === undefined ? '–' : s < 60 ? L('{n} s geleden', { n: s }) : s < 7200 ? L('{n} min geleden', { n: Math.floor(s / 60) }) : s < 172800 ? L('{n} u geleden', { n: Math.floor(s / 3600) }) : L('{n} d geleden', { n: Math.floor(s / 86400) }));
/** Meetwaarden en niveaus, gedeeld door de balk en het paneel */
function rzParts(d) {
  const ax = d.approx ? '≈' : '';
  const c = d.cpu, m = d.mem, k = d.disk, cr = d.cron || {};
  const diskLvl = !k ? 0 : (k.pct >= 95 || (k.full_days !== null && k.full_days <= 7)) ? 2 : (k.pct >= 80 || (k.full_days !== null && k.full_days <= 30)) ? 1 : 0;
  const cronLvl = cr.ago === null || cr.ago === undefined ? 1 : cr.ago > 300 ? 2 : (cr.ago > 150 || cr.over > 0) ? 1 : 0;
  return {
    cpu: c ? { pct: c.pct, val: ax + dec(Math.round(c.pct)), sub: L('load {l} · {n}c', { l: dec((c.load[0] || 0).toFixed(2)), n: c.cores }), lvl: c.pct >= 95 ? 2 : (c.cores && (c.load[1] || 0) > c.cores * 1.5) || c.pct >= 85 ? 1 : 0 } : null,
    mem: m ? { pct: m.pct, val: ax + dec(Math.round(m.pct)), sub: `${fmtBytes(m.used)} / ${fmtBytes(m.total)}`, lvl: m.pct >= 95 ? 2 : m.pct >= 90 ? 1 : 0 } : null,
    disk: k ? { pct: k.pct, val: dec(Math.round(k.pct)), sub: k.full_days !== null ? L('vol ~{n} d', { n: k.full_days }) : L('{b} vrij', { b: fmtBytes(k.total - k.used) }), lvl: diskLvl } : null,
    cron: { pct: cr.last === null || cr.last === undefined ? null : Math.min(100, cr.last / 60 * 100), val: cr.last === null || cr.last === undefined ? '–' : dec(Math.round(cr.last * 10) / 10), sub: rzAgo(cr.ago), lvl: cronLvl },
  };
}
const RZ_STATE = () => [L('Gezond'), L('Aandacht'), L('Probleem')];
function miniRes() {
  const el = $('#mini-res');
  if (!el) return;
  if (!selfStat) { el.innerHTML = ''; selfLoad(true); return; }
  if (Date.now() - selfAt > 30000) selfLoad(false);
  const d = selfStat, p = rzParts(d), st = +d.status || 0;
  const tip = (d.issues || []).map((x) => x.txt).join(' · ') || L('Alles werkt zoals het hoort.');
  const g = (key, lbl, x, unit, t) => (x ? rzGauge(key, lbl, x.pct, x.val, unit, x.sub, x.lvl, t) : '');
  const html = `<div class="rzk s${st}${$('#rzk-pop') ? ' open' : ''}">
    <button type="button" class="rzk-core" id="rzk-btn" aria-expanded="${$('#rzk-pop') ? 'true' : 'false'}" title="${esc(tip)}">${rzCoreSvg(st)}
      <span class="t"><b>${esc(L('RZSENSE-KERN'))}</b><span>${esc(RZ_STATE()[st])}</span><i>${esc(d.host)}${d.uptime ? ' · ' + esc(rzUp(d.uptime)) : ''}</i></span></button>
    <div class="rzk-gs">${g('cpu', 'CPU', p.cpu, '%', L('CPU van de RzSense-server'))}${g('mem', 'RAM', p.mem, '%', L('Geheugen van de RzSense-server'))}${g('disk', 'DISK', p.disk, '%', L('Disk waar de database staat'))}${g('cron', 'CRON', p.cron, 's', L('Hoe lang de laatste cron-ronde duurde'))}</div></div>`;
  el.innerHTML = html;
  const m = $('#rz-core-m');
  // 1.224.4: ook de verloop-id's een eigen naam (anders tekent de telefoon het verloop van de verborgen kopie: lege meters)
  if (m) m.innerHTML = html.replace('id="rzk-btn"', 'id="rzk-btn-m"').replace(/(id="|url\(#)rzk-([a-z]+)/g, '$1rzkm-$2');
  if ($('#rzk-pop')) rzPopFill();
}
/** Het paneel met de hele gezondheid (klik op de kern) */
function rzPopFill() {
  const box = $('#rzk-pop'), d = selfStat;
  if (!box || !d) return;
  const p = rzParts(d), st = +d.status || 0;
  const bar = (pct, lvl) => (pct === null || pct === undefined ? '' : `<div class="bar l${lvl}"><b style="width:${Math.max(2, Math.min(100, pct))}%"></b></div>`);
  const cell = (k, right, v, sub, pct, lvl) => `<div class="rzk-cell l${lvl}"><div class="k"><span>${k}</span><span>${right || ''}</span></div><div class="v">${v}</div><div class="s">${sub}</div>${bar(pct, lvl)}</div>`;
  const c = d.cpu, m = d.mem, k = d.disk, db = d.db || {}, cr = d.cron || {}, e = d.errors || { n: 0, fatal: 0 };
  const per = (b) => (b ? (b > 0 ? '+' : '') + fmtBytes(b) + L('/dag') : '');
  const verdict = st === 0 ? `<b>✔ ${L('Gezond.')}</b> ${L('Alles werkt zoals het hoort.')}`
    : `<b>${st === 2 ? '✖ ' + L('Probleem.') : '▲ ' + L('Aandacht.')}</b> ${(d.issues || []).map((x) => esc(x.txt)).join(' · ')}`;
  box.innerHTML = `<div class="rzk-pop-h">${rzCoreSvg(st)}<div><b>${L('RzSense-server')}</b><span>${esc([d.host, d.os, 'RzSense ' + d.version].filter(Boolean).join(' · '))}</span></div>
      <button type="button" class="rzk-x" data-rzk-close aria-label="${esc(L('Sluiten'))}">✕</button></div>
    <div class="rzk-verdict s${st}">${verdict}</div>
    <div class="rzk-grid">
      ${c ? cell('CPU', c.load.length ? L('load {a} · {b} · {c}', { a: dec(c.load[0].toFixed(2)), b: dec((c.load[1] || 0).toFixed(2)), c: dec((c.load[2] || 0).toFixed(2)) }) : '', p.cpu.val + '%', L('{n} cores', { n: c.cores }), c.pct, p.cpu.lvl) : ''}
      ${m ? cell(L('Geheugen'), m.swap !== null && m.swap !== undefined ? L('swap {p}%', { p: m.swap }) : '', p.mem.val + '%', L('{u} van {t} in gebruik', { u: fmtBytes(m.used), t: fmtBytes(m.total) }), m.pct, p.mem.lvl) : ''}
      ${k ? cell(L('Disk') + ' ' + esc(k.path), per(k.growth_day), p.disk.val + '%', L('{u} van {t}', { u: fmtBytes(k.used), t: fmtBytes(k.total) }) + (k.full_days !== null ? ' · ' + L('vol over ~{n} dagen', { n: k.full_days }) : ''), k.pct, p.disk.lvl) : ''}
      ${cell(L('Database'), per(db.growth_day), fmtBytes(db.size || 0), (db.top || []).length ? L('grootste: {t}', { t: db.top.map((x) => `${esc(x.name)} (${fmtBytes(x.bytes)})`).join(', ') }) : '', null, 0)}
      ${cell(L('Cron-ronde'), cr.avg !== null && cr.avg !== undefined ? L('gem. {s} s', { s: dec(cr.avg) }) : '', cr.last !== null && cr.last !== undefined ? dec(Math.round(cr.last * 10) / 10) + ' s' : '–',
        L('laatste ronde {t}', { t: rzAgo(cr.ago) }) + (cr.over ? ' · ' + L('{n}× uitgelopen', { n: cr.over }) : ''), p.cron.pct, p.cron.lvl)}
      ${cell(L('Fouten'), L('24 u'), String(e.n), e.fatal ? L('{n} ernstig', { n: e.fatal }) : e.n ? L('PHP-waarschuwingen') : L('geen PHP-fouten'), null, e.fatal ? 2 : e.n ? 1 : 0)}
    </div>
    <div class="rzk-foot"><a class="btn small-btn primary need-admin" href="#settings/db" data-goto="settings/db">${L('Database en server')}</a>
      <a class="btn small-btn need-admin" href="#settings/perf" data-goto="settings/perf">${L('Prestaties en fouten')}</a>
      <span class="muted small">${d.src === 'agent' ? L('CPU en geheugen: agent op deze server ({h})', { h: esc(d.agent || '') }) : d.approx ? L('≈ RzSense draait in een container: CPU en geheugen zijn bij benadering') : L('gemeten door RzSense zelf')}</span></div>`;
}
function rzPopToggle(anchor) {
  const old = $('#rzk-pop');
  if (old) { old.remove(); document.querySelectorAll('.rzk').forEach((x) => x.classList.remove('open')); return; }
  const box = document.createElement('div');
  box.id = 'rzk-pop'; box.className = 'rzk-pop'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-label', L('RzSense-server'));
  document.body.appendChild(box);
  rzPopFill();
  const r = anchor.getBoundingClientRect();
  if (innerWidth > 760) { box.style.top = `${Math.round(r.bottom + 10)}px`; box.style.left = `${Math.round(Math.max(12, Math.min(innerWidth - box.offsetWidth - 12, r.left)))}px`; }
  anchor.closest('.rzk')?.classList.add('open');
  selfLoad(true);
}
document.addEventListener('click', (e) => {
  const b = e.target.closest('#rzk-btn, #rzk-btn-m');
  if (b) { rzPopToggle(b); return; }
  const pop = $('#rzk-pop');
  if (pop && (e.target.closest('[data-rzk-close]') || e.target.closest('[data-goto]') || !e.target.closest('#rzk-pop'))) { pop.remove(); document.querySelectorAll('.rzk').forEach((x) => x.classList.remove('open')); }
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('#rzk-pop')) { $('#rzk-pop').remove(); document.querySelectorAll('.rzk').forEach((x) => x.classList.remove('open')); } });
window.addEventListener('scroll', () => { const pop = $('#rzk-pop'); if (pop && innerWidth > 760) { pop.remove(); document.querySelectorAll('.rzk').forEach((x) => x.classList.remove('open')); } }, { passive: true });
document.addEventListener('visibilitychange', () => { if (!document.hidden) selfLoad(false); });

/** Kerncijfers, tellers en "Nu aandacht nodig" bovenaan het dashboard. Toestand en score komen van de server (Health.php). */
function renderHealth() {
  const g = dashGroup();
  const hl = (g && state.health_groups && state.health_groups[g]) || state.health;
  if (!hl) return;
  const inGroup = (id) => !g || (findHost(id) && hostGroup(findHost(id)) === g);
  // Tellers (klikbaar: alleen die checks tonen)
  const WORD = { 0: 'OK', 1: 'Warning', 2: 'Critical', 3: L('Onbekend'), 4: L('Onderhoud') };
  $('#health-counts').innerHTML = HEALTH_COUNTS.map(([st, key]) => {
    const n = hl.counts[key];
    return `<button type="button" class="hc s${st} ${statusFilter === st ? 'on' : ''} ${n ? '' : 'zero'}" data-st="${st}"
      title="${n} check${n === 1 ? '' : 's'} ${HEALTH_TITLES[st]}${statusFilter === st ? ' – ' + L('klik om het filter op te heffen') : ' – ' + L('klik om alleen deze te tonen')}">
      <span class="hc-dot"></span><b data-flash>${n}</b> <span class="hc-w">${WORD[st]}</span></button>`;
  }).join('');

  miniRes();   // 1.199.0; 1.219.0: de RzSense-kern
  // Kerncijfers als tegels
  const stateCls = { ok: 'ok', attention: 'warn', outage: 'bad' }[hl.state];
  const hostLink = (id, text) => `<button type="button" class="hl-link" data-host="${id}">${esc(text)}</button>`;
  const why = { agent: L('agent stil'), parent: L('achter uitgevallen server') };
  const downs = hl.down.map((d) => hostLink(d.id, d.name) + (why[d.reason] ? ` <span class="muted">(${why[d.reason]})</span>` : '') + (d.acknowledged ? ' ✔' : '')).join(', ');
  const nProb = hl.counts.critical + hl.counts.warning + hl.counts.unknown;
  const u = Object.entries(stats.uptime).filter(([id]) => inGroup(id)).map(([, x]) => x.h24).filter((v) => v !== null && v !== undefined);
  const avail = u.length ? u.reduce((a, b) => a + b, 0) / u.length : null;
  const u7 = Object.entries(stats.uptime).filter(([id]) => inGroup(id)).map(([, x]) => x.d7).filter((v) => v !== null && v !== undefined);
  const avail7 = u7.length ? u7.reduce((a, b) => a + b, 0) / u7.length : null;
  // 1.157.0: aantal checks met een probleem per half uur (24 uur), voor het lijntje en de trend
  const probSeries = sparkSeries(inGroup, (st) => (st !== null ? (st !== 0 ? 1 : 0) : null), 'sum');
  const probVals = probSeries.filter((v) => v !== null);
  const probNow = probVals.length ? probVals[probVals.length - 1] : null, probThen = probVals.length ? probVals[0] : null;
  const o = hl.oldest_problem;
  const extra = [hl.quiet_until ? `🌙 ${L('stil tot {t}', { t: esc(fmtUntil(hl.quiet_until)) })}` : '',
    hl.flapping ? `🔀 ${hl.flapping} ${L('flapt')}` : '', hl.counts.maintenance ? `🔧 ${hl.counts.maintenance} ${L('in onderhoud')}` : ''].filter(Boolean).join(' · ');
  $('#health').className = 'kpis k-' + hl.state;
  $('#health').innerHTML = `
    <div class="kpi kpi-main ${stateCls}" data-kpi="problems" title="${L('Klik: alleen problemen tonen')}">
      <div class="ring" style="--p:${hl.score}"><span data-flash>${hl.score}%</span></div>
      <div class="kpi-txt"><div class="kpi-state">${{ ok: '🟢', attention: '🟠', outage: '🔴' }[hl.state]} ${L(hl.label)}</div>
        <div class="kpi-sub">${nProb ? [hl.counts.critical ? `${hl.counts.critical} critical` : '', hl.counts.warning ? `${hl.counts.warning} warning` : '', hl.counts.unknown ? L('{n} onbekend', { n: hl.counts.unknown }) : ''].filter(Boolean).join(' · ') : L('Geen problemen')}</div>
        ${extra ? `<div class="kpi-sub">${extra}</div>` : ''}</div></div>
    <div class="kpi ${hl.down.length ? 'bad' : ''}" title="${L('Servers die reageren (ping, of de agent rapporteert)')}">
      <div class="kpi-lbl">${!g || g === 'server' ? L('Servers bereikbaar') : L('Apparaten bereikbaar')}</div><div class="kpi-val">${hl.hosts_up}<small>/${hl.hosts_total}</small></div>
      <div class="kpi-sub">${downs ? `${L('onbereikbaar')}: ${downs}` : L('allemaal bereikbaar')}</div></div>
    <div class="kpi" title="${L('Actieve checks met status OK (checks in onderhoud tellen niet mee); het lijntje: het aantal checks met een probleem, 24 uur')}">
      <div class="kpi-lbl">${L('Checks OK')}</div><div class="kpi-val">${hl.checks_ok}<small>/${hl.checks_total}</small> ${kpiTrend(probThen, probNow)}</div>
      <div class="kpi-sub">${hl.checks_total ? pct(hl.checks_ok / hl.checks_total * 100) : '–'}</div>${kpiSpark(probSeries, 'var(--primary)', true)}</div>
    <div class="kpi ${hl.open_problems ? 'bad' : ''}" title="${L('Problemen die nog niet bevestigd zijn')}">
      <div class="kpi-lbl">${L('Open problemen')}</div><div class="kpi-val">${hl.open_problems}</div>
      <div class="kpi-sub">${o ? `${L('langst')}: ${hostLink(o.host_id, o.host + ' – ' + o.check)} (${dur(o.since)})` : L('niets open')}</div>${kpiSpark(probSeries, 'var(--crit)')}</div>
    <div class="kpi" title="${L('Gemiddelde bereikbaarheid van alle servers over de afgelopen 24 uur, vergeleken met 7 dagen; het lijntje: het deel van de checks dat OK was')}">
      <div class="kpi-lbl">${L('Beschikbaar 24 u')}</div><div class="kpi-val">${avail === null ? '–' : pct(avail).replace('%', '<small>%</small>')} ${avail !== null && avail7 !== null ? kpiTrend(avail, avail7, false, true) : ''}</div>
      ${avail7 !== null ? `<div class="kpi-sub">${L('7 dagen: {p}', { p: pct(avail7) })}</div>` : ''}${healthSpark(inGroup)}</div>`;
  renderAttention();
}

/** Lijntje over 24 uur: per half uur het deel van alle checks dat OK was (uit de minigrafieken) */
function healthSpark(inGroup = () => true) {
  const hostOf = {};
  state.hosts.forEach((h) => h.checks.forEach((c) => { hostOf[c.id] = h.id; }));
  const rows = Object.entries(stats.sparks || {}).filter(([cid]) => inGroup(hostOf[cid])).map(([, r]) => r);
  if (!rows.length) return '';
  const pts = [];
  for (let i = 0; i < 48; i++) {
    let ok = 0, n = 0;
    rows.forEach((r) => { const d = r[i]; if (d && d[1] !== null && d[1] !== undefined) { n++; if (+d[1] === 0) ok++; } });
    pts.push(n ? ok / n : null);
  }
  const vals = pts.filter((v) => v !== null);
  if (vals.length < 2) return '';
  const min = Math.min(...vals), lo = Math.max(0, min - .05);
  const y = (v) => 24 - ((v - lo) / Math.max(.05, 1 - lo)) * 20;
  let d = '';
  pts.forEach((v, i) => { if (v !== null) d += `${d ? 'L' : 'M'}${(i / 47 * 100).toFixed(1)},${y(v).toFixed(1)}`; });
  const last = vals[vals.length - 1];
  const col = last >= .99 ? 'var(--ok)' : last >= .8 ? 'var(--warn)' : 'var(--crit)';
  return `<svg class="kpi-spark" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${d}L100,26L0,26Z" fill="${col}" opacity=".14"/><path d="${d}" fill="none" stroke="${col}" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>`;
}

/**
 * 1.176.0: "Nu aandacht nodig" in de lay-out CC: één compacte lijst met vaste kolommen (status, server, check, melding,
 * duur, 24 uur, acties), filters per status, gebundelde oorzaken als ingesprongen regels en bevestigd ingeklapt onderaan.
 */
let axFilter = 'all';
const AX_ICON = {
  box: '<path d="M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8"/>', clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  disk: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/>', temp: '<path d="M14 14.8V4a2 2 0 1 0-4 0v10.8a4 4 0 1 0 4 0z"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>', lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  cpu: '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>', pulse: '<path d="M2 12h4l3-7 4 14 3-7h6"/>',
  off: '<path d="M5 12.5a7 7 0 0 1 14 0M8.5 12.5a3.5 3.5 0 0 1 7 0"/><path d="M3 3l18 18"/>', srv: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/>',
  ok: '<path d="M20 6 9 17l-5-5"/>', ai: '<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"/>', go: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  up: '<path d="M6 15l6-6 6 6"/>', down: '<path d="M6 9l6 6 6-6"/>', warn: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
};
const axSvg = (k, s = 16) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${AX_ICON[k] || AX_ICON.pulse}</svg>`;
const axIconOf = (c) => ({ container: 'box', podman: 'box', heartbeat: 'clock', disk: 'disk', smart: 'disk', truenas: 'temp', ssl: 'lock', domain: 'globe', http: 'globe', dns: 'globe', cpu: 'cpu', ram: 'cpu' }[c.type] || 'pulse');
const axWord = (s) => [L('OK'), 'WARNING', 'CRITICAL', L('ONBEKEND')][s] || '?';
const axStatusKey = (s) => (s === 2 ? 'crit' : s === 1 ? 'warn' : 'unk');

function renderAttnCC(box, items, open, acked, ackedList, down, why, since) {
  const worstOf = (it) => it.list.reduce((m, c) => (STATUS_RANK[+c.status] > STATUS_RANK[m] ? +c.status : m), 0);
  const n = { crit: 0, warn: 0, unk: 0 };
  items.forEach((it) => { n[axStatusKey(worstOf(it))]++; });
  const shown = items.filter((it) => axFilter === 'all' || axStatusKey(worstOf(it)) === axFilter);
  const hosts = new Set(items.map((it) => +it.h.id)).size;
  const okN = state.hosts.reduce((s, h) => s + h.checks.filter((c) => +c.enabled && +c.status === 0).length, 0);
  const newest = open.map(([, c]) => String(c.problem_since || '')).filter(Boolean).sort().pop();
  const lbl = (s, c) => (c && ctOf(c) ? ctOf(c) : `<span class="ax-lbl s${s}">${axWord(s)}</span>`);
  const acts = (c) => `<span class="ax-acts">${!c.acked_at ? `<button type="button" class="ax-ib ok need-user" data-act="ack" data-id="${c.id}" aria-label="${esc(L('Bevestigen'))}" title="${esc(L('Bevestigen'))}">${axSvg('ok', 15)}</button>` : ''}${aiOn() ? `<span class="ax-ai">${aiBtn('check', c.id)}</span>` : ''}<button type="button" class="ax-ib" data-ax-open="${c.id}" aria-label="${esc(L('Openen'))}" title="${esc(L('Openen'))}">${axSvg('go', 15)}</button></span>`;
  let k = 0;
  const row = (h, c, sub = false) => `<div class="ax-row s${c.status}${sub ? ' sub' : ''}" data-cid="${c.id}" style="--i:${Math.min(k++, 20)}">
      ${sub ? '<span class="ax-tree" aria-hidden="true"></span>' : `<span class="ax-si s${c.status}">${axSvg(axIconOf(c))}</span>`}
      <span>${sub ? '' : lbl(+c.status, c)}</span>
      <span>${sub ? '' : `<button type="button" class="ax-srv" data-host="${h.id}">${axSvg('srv', 12)}<span>${esc(h.name)}</span></button>`}</span>
      <span class="ax-chk"><b>${esc(c.name)}</b>${sub ? ` ${lbl(+c.status, c)}` : `<small class="ax-hs">${esc(h.name)}</small>`}</span>
      <span class="ax-msg" title="${esc(c.last_message || '')}">${esc(c.last_message || '')}</span>
      <span class="ax-dur mono">${c.problem_since ? dur(c.problem_since) : ''}</span>
      <span class="ax-tl">${checkTimeline(c)}</span>
      ${acts(c)}
    </div>`;
  const bundle = (it) => {
    const st = worstOf(it), d = down.get(+it.h.id), isOpen = attnBundles.has(+it.h.id), s0 = since(it);
    const cause = d ? (why[d.reason] || L('onbereikbaar')) : '';
    const unacked = it.list.some((c) => !c.acked_at);
    return `<div class="ax-row ax-bundle s${st}" style="--i:${Math.min(k++, 20)}">
      <span class="ax-si s${st}">${axSvg(d ? 'off' : 'warn')}</span>
      <span>${lbl(st)}</span>
      <span><button type="button" class="ax-srv" data-host="${it.h.id}">${axSvg('srv', 12)}<span>${esc(it.h.name)}</span></button></span>
      <span class="ax-chk">${d ? `<b>${esc(cause)}</b>` : ''}<span class="ax-cnt">${L('{n} checks', { n: it.list.length })}</span><small class="ax-hs">${esc(it.h.name)}</small></span>
      <span class="ax-msg">${esc(it.list.map((c) => c.name).slice(0, 6).join(', '))}${it.list.length > 6 ? ' …' : ''}</span>
      <span class="ax-dur mono">${s0 ? dur(s0) : ''}</span>
      <span class="ax-tl">${hostTimeline(it.h)}</span>
      <span class="ax-acts"><button type="button" class="ax-ib" data-attn-bundle="${it.h.id}" aria-expanded="${isOpen}" aria-label="${esc(isOpen ? L('Dichtklappen') : L('Openklappen'))}">${axSvg(isOpen ? 'up' : 'down', 15)}</button>${unacked ? `<button type="button" class="ax-ib ok need-user" data-act="ack-host" data-id="${it.h.id}" aria-label="${esc(L('Alles bevestigen'))}" title="${esc(L('Alles bevestigen'))}">${axSvg('ok', 15)}</button>` : ''}${aiOn() ? `<span class="ax-ai">${aiBtn('bundle', it.h.id)}</span>` : ''}</span>
    </div>${isOpen ? it.list.map((c) => row(it.h, c, true)).join('') : ''}`;
  };
  const limit = attnOpen ? Infinity : 8;
  const list = shown.slice(0, limit);
  const chip = (key, label, cnt, dot) => `<button type="button" class="${axFilter === key ? 'on' : ''}" data-ax-f="${key}">${dot ? `<i class="ax-d ${dot}"></i>` : ''}${label}${cnt !== null ? ` <b>${cnt}</b>` : ''}</button>`;
  const total = n.crit + n.warn + n.unk || 1;
  box.innerHTML = `<div class="ax" data-motion="${afMotion(null)}"><span class="ax-scan" aria-hidden="true"></span>
    <div class="ax-head">
      <span class="ax-hi">${axSvg('warn', 20)}</span>
      <div class="ax-ht"><span class="ax-k">${L('Nu aandacht nodig')}</span>
        <b>${open.length === 1 ? L('1 open probleem') : L('{n} open problemen', { n: open.length })}</b> <span class="muted">${L('op {n} servers', { n: hosts })}${acked ? ' · ' + L('{n} bevestigd', { n: acked }) : ''}${newest ? ' · ' + L('nieuwste {t} geleden', { t: dur(newest) }) : ''}</span></div>
      <div class="ax-meter"><span class="ax-mbar" aria-hidden="true">${n.crit ? `<i class="crit" style="flex:${n.crit / total}"></i>` : ''}${n.warn ? `<i class="warn" style="flex:${n.warn / total}"></i>` : ''}${n.unk ? `<i class="unk" style="flex:${n.unk / total}"></i>` : ''}</span>
        <span class="ax-ml mono small"><span><b class="c">${n.crit}</b> critical</span><span><b class="w">${n.warn}</b> warning</span><span><b>${n.unk}</b> ${L('onbekend')}</span><span class="ax-okn">${L('{n} checks in orde', { n: okN })}</span></span></div>
      <div class="ax-seg" role="group" aria-label="${esc(L('Filter'))}">${chip('all', L('Alle'), items.length)}${chip('crit', 'Critical', n.crit, 'crit')}${chip('warn', 'Warning', n.warn, 'warn')}${chip('unk', L('Onbekend'), n.unk, 'unk')}</div>
    </div>
    <div class="ax-body"><div class="ax-row ax-th" aria-hidden="true"><span></span><span>${L('Status')}</span><span>${L('Server')}</span><span>${L('Check')}</span><span>${L('Melding')}</span><span>${L('Duur')}</span><span>${L('24 uur')}</span><span class="r">${L('Actie')}</span></div>
      ${list.map((it) => (it.bundle ? bundle(it) : row(it.h, it.list[0]))).join('') || `<p class="muted small ax-none">${L('Niets met dit filter.')}</p>`}
      ${shown.length > 8 ? `<button type="button" class="ax-more" data-attn-more>${attnOpen ? L('Minder tonen') : L('Alle {n} tonen', { n: shown.length })}</button>` : ''}</div>
    ${acked ? `<button type="button" class="ax-acked" data-attn-acked>${axSvg('ok', 15)}<b>${L('Bevestigd · {n}', { n: acked })}</b><span>${esc(ackedList.slice(0, 3).map(([h, c]) => `${c.name} (${h.name})`).join(' · '))}${ackedList.length > 3 ? ' …' : ''}</span>${axSvg('down', 15)}</button>` : ''}
  </div>`;
}

/** Alle open problemen in één lijst, critical eerst; bevestigde staan er niet in (wel geteld) */
function renderAttention() {
  const all = [];
  const g = dashGroup();
  state.hosts.forEach((h) => +h.enabled && (!g || hostGroup(h) === g) && h.checks.forEach((c) => isProblem(c) && all.push([h, c])));
  const open = all.filter(([, c]) => !c.acked_at)
    .sort(([, a], [, b]) => STATUS_RANK[+b.status] - STATUS_RANK[+a.status] || String(a.problem_since || '').localeCompare(String(b.problem_since || '')));
  const acked = all.length - open.length;
  const box = $('#dash-attn');
  // Niets open: de tegel ernaast zegt al "Alles in orde". Alleen bevestigde problemen nog als klein regeltje (1.77.0).
  box.hidden = !open.length && !acked;
  box.classList.toggle('ax-on', isCC());
  if (!open.length && !(isCC() && acked)) {
    box.innerHTML = acked ? `<div class="attn-acked">✔ ${L('Geen open problemen')} · ${acked === 1 ? L('1 bevestigd') : L('{n} bevestigd', { n: acked })}
      <button type="button" class="link-btn" data-attn-acked>${L('bekijken')}</button></div>` : '';
    return;
  }
  // 1.157.0: gebundeld op oorzaak. Een server die onbereikbaar is (of waarvan de agent stil is) wordt één regel
  // met al zijn checks, net als een server met 4 of meer problemen; de rest per check.
  const hl = (g && state.health_groups && state.health_groups[g]) || state.health || {};
  const down = new Map((hl.down || []).map((d) => [+d.id, d]));
  const byHost = new Map();
  open.forEach(([h, c]) => { if (!byHost.has(h.id)) byHost.set(h.id, [h, []]); byHost.get(h.id)[1].push(c); });
  const items = [];
  byHost.forEach(([h, list]) => {
    if ((down.has(+h.id) && list.length >= 2) || list.length >= 4) items.push({ h, list, bundle: true });
    else list.forEach((c) => items.push({ h, list: [c], bundle: false }));
  });
  const worst = (it) => Math.max(...it.list.map((c) => STATUS_RANK[+c.status]));
  const since = (it) => it.list.map((c) => String(c.problem_since || '')).filter(Boolean).sort()[0] || '';
  items.sort((a, b) => worst(b) - worst(a) || since(a).localeCompare(since(b)));
  const why = { agent: L('agent stil'), parent: L('achter een uitgevallen server') };
  if (isCC()) return renderAttnCC(box, items, open, acked, all.filter(([, c]) => c.acked_at), down, why, since);   // 1.176.0
  const row = ([h, c], sub = false) => `<div class="arow${sub ? ' sub' : ''}" data-cid="${c.id}">
      <i class="adot s${c.status}"></i>
      <button type="button" class="a-srv hl-link" data-host="${h.id}">${esc(h.name)}</button>
      <span class="a-chk">${esc(c.name)}</span>
      <span class="a-msg">${esc(c.last_message || '')}</span>
      ${checkTimeline(c)}
      <span class="a-since">${c.problem_since ? dur(c.problem_since) : ''}</span>
      <span class="a-acts">${aiBtn('check', c.id)}<button class="btn small-btn ack-btn need-user" data-act="ack" data-id="${c.id}" title="${L('Storing bevestigen: geen meldingen meer')}">✔ ${L('Bevestig')}</button><button class="btn small-btn need-user" data-act="maint-check" data-id="${c.id}" title="${L('Onderhoud')}">🔧</button></span>
    </div>`;
  const bundleRow = (it) => {
    const st = it.list.reduce((m, c) => (STATUS_RANK[+c.status] > STATUS_RANK[m] ? +c.status : m), 0);
    const d = down.get(+it.h.id);
    const cause = d ? (why[d.reason] || L('onbereikbaar')) : L('{n} problemen op deze server', { n: it.list.length });
    const isOpen = attnBundles.has(+it.h.id);
    const s0 = since(it);
    return `<div class="arow bundle${isOpen ? ' open' : ''}" data-bundle="h${it.h.id}">
      <i class="adot s${st}"></i>
      <button type="button" class="a-srv hl-link" data-host="${it.h.id}">${esc(it.h.name)}</button>
      <span class="a-chk"><button type="button" class="link-btn" data-attn-bundle="${it.h.id}" aria-expanded="${isOpen}">${isOpen ? '▾' : '▸'} ${L('{n} checks', { n: it.list.length })}</button></span>
      <span class="a-msg"><b>${esc(cause)}</b> · ${esc(it.list.map((c) => c.name).slice(0, 5).join(', '))}${it.list.length > 5 ? ' …' : ''}</span>
      ${hostTimeline(it.h)}
      <span class="a-since">${s0 ? dur(s0) : ''}</span>
      <span class="a-acts">${aiBtn('bundle', it.h.id)}</span>
    </div>${isOpen ? it.list.map((c) => row([it.h, c], true)).join('') : ''}`;
  };
  const nCause = items.length;
  box.innerHTML = `<div class="sec-h"><h2>⚠️ ${L('Nu aandacht nodig')}</h2><span>${nCause === open.length ? (open.length === 1 ? L('1 probleem') : L('{n} problemen', { n: open.length }))
      : L('{c} oorzaken · {n} checks', { c: nCause, n: open.length })} · ${L('critical eerst')}${acked ? ' · ' + L('{n} bevestigd', { n: acked }) : ''}</span><span class="a-24 muted small">${L('24 uur')} →</span></div>
    <div class="attn ${attnOpen ? 'all' : ''}">${items.map((it) => (it.bundle ? bundleRow(it) : row([it.h, it.list[0]]))).join('')}
    ${items.length > 3 ? `<button type="button" class="attn-more ${items.length > 6 ? '' : 'mob-only'}" data-attn-more>${attnOpen ? '▴ ' + L('Minder tonen') : '▾ ' + L('Alle {n} tonen', { n: items.length })}</button>` : ''}</div>`;
}

/** Naar een server springen en hem openklappen (vanuit de kerncijfers of de aandachtlijst). */
function jumpToHost(id) {
  statusFilter = null;
  $('#filter').value = '';
  if (!DASH_FILTERS.find((f) => f[0] === dashFilter)[2](findHost(id))) dashFilter = 'all';
  openHosts.add(+id);
  saveOpen();
  render();
  const head = $(`#hosts [data-act=toggle][data-id="${id}"]`);
  if (head) {
    const art = head.closest('article');
    art.scrollIntoView({ behavior: 'smooth', block: 'center' });
    art.classList.add('flash');
    setTimeout(() => art.classList.remove('flash'), 1600);
  }
}

/** Dashboard tekenen; live: alleen wat veranderde (morph) */
// ============ 1.169.0: Monitoring → Beschikbaarheid (per soort apparaat of eigen groep) ============
let monSub = 'checks';
let avData = null, avAt = 0, avKey = '', avBusy = false;
const avOpenGroups = new Set();
const AV_PERIODS = [['1', () => L('24 u')], ['7', () => L('7 dagen')], ['30', () => L('30 dagen')], ['90', () => L('90 dagen')]];
const AV_TARGETS = ['95', '98', '99', '99.5', '99.9', '99.95', '99.99'];
const avGroupBy = () => uiGet('av_group', 'rzmon-av-group', 'kind');
const avPeriod = () => uiGet('av_period', 'rzmon-av-period', '7');
const avTarget = () => +uiGet('av_target', 'rzmon-av-target', '99.9');
const avPct = (v) => (v === null || v === undefined ? '–' : dec(v >= 99.9995 ? '100' : v.toFixed(v >= 99.9 ? 3 : v >= 99 ? 2 : 1)) + '%');
const avMean = (a) => { const v = a.filter((x) => x !== null && x !== undefined); return v.length ? v.reduce((s, x) => s + x, 0) / v.length : null; };

function setMonSub(s) {
  monSub = s === 'avail' || s === 'cont' || s === 'paths' ? s : 'checks';
  $('#tab-dashboard').classList.toggle('mon-av', monSub === 'avail');
  $('#tab-dashboard').classList.toggle('mon-ct', monSub === 'cont');   // 1.176.0
  if (monSub === 'cont') withPageWait('dashboard', ctLoad);
  $('#tab-dashboard').classList.toggle('mon-np', monSub === 'paths');   // 1.177.0
  if (monSub === 'paths') withPageWait('dashboard', npLoad);
  $$('#mon-tabs [data-msub]').forEach((b) => { const on = b.dataset.msub === monSub; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
  if (monSub === 'avail') withPageWait('dashboard', avLoad);
  crumbs();   // 1.199.0
}

async function avLoad(force = false) {
  const key = avPeriod();
  if (!force && avData && avKey === key && Date.now() - avAt < 60000) return renderAvail();
  if (avBusy) return;
  avBusy = true;
  if (!avData || avKey !== key) $('#avail').innerHTML = `<p class="muted">${L('laden…')}</p>`;
  try {
    avData = await api('availability', null, { days: key });
    avKey = key; avAt = Date.now();
  } catch (ex) {
    $('#avail').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`;
    return;
  } finally { avBusy = false; }
  renderAvail();
}

/** Hoe een server er nu voor staat: up, down, maint (onderhoud), none (geen checks) */
function avNow(h) {
  if (h.maintenance) return 'maint';
  if (!h.checks.some((c) => +c.enabled)) return 'none';
  return ((state.health && state.health.down) || []).some((d) => +d.id === +h.id) ? 'down' : 'up';
}
/** "offline", of waarom: agent stil, achter een uitgevallen server */
function avNowText(h, now) {
  if (now !== 'down') return AV_NOW[now][1]();
  const d = ((state.health && state.health.down) || []).find((x) => +x.id === +h.id);
  return d && d.reason === 'agent' ? L('agent stil') : d && d.reason === 'parent' ? L('achter uitgevallen server') : AV_NOW.down[1]();
}
const AV_NOW = { up: ['up', () => L('online')], down: ['down', () => L('offline')], maint: ['maint', () => L('onderhoud')], none: ['none', () => L('geen checks')] };

/** De servers ingedeeld: per soort apparaat (zoals de filterknoppen) of per eigen groep */
function avGroups() {
  const own = avGroupBy() === 'own';
  const map = new Map();
  state.hosts.filter((h) => +h.enabled).forEach((h) => {
    const k = own ? (h.grp || '').trim() : hostGroup(h);
    if (!map.has(k)) map.set(k, []);
    map.get(k).push(h);
  });
  const order = Object.keys(GROUP_INFO);
  return [...map.entries()].map(([k, hosts]) => ({
    key: (own ? 'o:' : 'k:') + k, hosts: hosts.sort((a, b) => a.name.localeCompare(b.name, LANG, { numeric: true, sensitivity: 'base' })),
    icon: own ? (k ? '🏷️' : '▫️') : GROUP_INFO[k][0], name: own ? (k || L('Zonder groep')) : GROUP_INFO[k][1](),
    sort: own ? (k ? '0' + k.toLowerCase() : '1') : String(order.indexOf(k)).padStart(2, '0'),
  })).sort((a, b) => a.sort.localeCompare(b.sort, LANG));
}

/** Cijfers van een groep over de periode */
function avStats(g) {
  const D = avData.hosts || {};
  const rows = g.hosts.map((h) => ({ h, now: avNow(h), d: D[h.id] || null }));
  const measured = rows.filter((r) => r.now !== 'none' && r.now !== 'maint');
  const pctv = avMean(rows.map((r) => (r.d ? r.d.pct : null)));
  const len = avKey === '1' ? 24 : +avKey;
  const series = Array.from({ length: len }, (_, i) => avMean(rows.map((r) => (r.d && r.d.series ? r.d.series[i] : null))));
  const n = rows.reduce((s, r) => s + (r.d ? r.d.n : 0), 0);
  const down = rows.reduce((s, r) => s + (r.d ? r.d.down_s : 0), 0);
  const longest = rows.reduce((m, r) => Math.max(m, r.d ? r.d.longest_s : 0), 0);
  return { rows, measured, up: measured.filter((r) => r.now === 'up').length, maint: rows.filter((r) => r.now === 'maint').length, pct: pctv, series, n, down, longest };
}

/** SLA: haalt het percentage het doel, en hoeveel tijd is er nog "over" (of te veel) */
function avSla(p, target, span) {
  if (p === null) return { cls: 'none', text: L('nog geen metingen') };
  const budget = (1 - target / 100) * span, used = (1 - p / 100) * span, left = budget - used;
  return left >= 0
    ? { cls: 'ok', text: `✔ ${L('doel gehaald')} · ${L('nog {t} over', { t: durText(left) })}` }
    : { cls: 'bad', text: `✘ ${L('onder het doel')} · ${L('{t} te veel', { t: durText(-left) })}` };
}
const avCls = (p, target) => (p === null ? 'none' : p >= target ? 'ok' : p >= Math.min(99, target - 0.5) ? 'warn' : 'bad');

function avBucketLabel(i) {
  const from = avData.from * 1000;
  if (avKey === '1') { const d = new Date(from + i * 3600000); return d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }); }
  return new Date(from + i * 86400000).toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' });
}
/** Blokje per uur of dag, in de kleur van de beschikbaarheid */
function avCells(series, target) {
  return `<span class="av-cells" aria-hidden="true">${series.map((v, i) => `<i class="${avCls(v, target)}" title="${esc(avBucketLabel(i) + ': ' + (v === null ? L('geen metingen') : avPct(v)))}"></i>`).join('')}</span>`;
}
const avWhen = (ts) => { const d = new Date(ts * 1000); const today = new Date().toDateString() === d.toDateString();
  return (today ? '' : d.toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' }) + ' ') + d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }); };

function renderAvail() {
  if (!avData) return;
  withMorph(renderAvailNow);
}
function renderAvailNow() {
  const target = avTarget(), span = avData.span, own = avGroupBy() === 'own';
  const groups = avGroups().map((g) => ({ ...g, st: avStats(g) }));
  const all = groups.flatMap((g) => g.st.rows);
  const measured = all.filter((r) => r.now !== 'none' && r.now !== 'maint');
  const upN = measured.filter((r) => r.now === 'up').length;
  const pAll = avMean(all.map((r) => (r.d ? r.d.pct : null)));
  const nOut = all.reduce((s, r) => s + (r.d ? r.d.n : 0), 0), downAll = all.reduce((s, r) => s + (r.d ? r.d.down_s : 0), 0);
  const withPct = groups.filter((g) => g.st.pct !== null), okG = withPct.filter((g) => g.st.pct >= target).length;
  const perLbl = (AV_PERIODS.find((p) => p[0] === avKey) || AV_PERIODS[1])[1]();
  const groupOf = new Map(groups.flatMap((g) => g.hosts.map((h) => [+h.id, g])));
  const noOwn = own && !state.hosts.some((h) => (h.grp || '').trim());

  const tools = `<div class="av-tools">
      <span class="seg" role="group" aria-label="${L('Indelen')}">${[['kind', '🗂️ ' + L('Soort apparaat')], ['own', '🏷️ ' + L('Eigen groep')]].map(([k, l]) => `<button type="button" class="seg-btn ${avGroupBy() === k ? 'active' : ''}" data-av-group="${k}">${esc(l)}</button>`).join('')}</span>
      <span class="seg" role="group" aria-label="${L('Periode')}">${AV_PERIODS.map(([k, l]) => `<button type="button" class="seg-btn ${avKey === k ? 'active' : ''}" data-av-period="${k}">${esc(l())}</button>`).join('')}</span>
      <label class="av-target" title="${L('Het doel (SLA): hoeveel procent van de tijd een groep minstens bereikbaar moet zijn')}">🎯 ${L('Doel')}
        <select id="av-target">${AV_TARGETS.map((t) => `<option value="${t}" ${+t === target ? 'selected' : ''}>${dec(t)}%</option>`).join('')}</select></label>
    </div>`;
  const kpis = `<div class="kpis av-kpis">
      <div class="kpi ${measured.length - upN ? 'bad' : ''}"><div class="kpi-lbl">${L('Online nu')}</div><div class="kpi-val">${upN}<small>/${measured.length}</small></div>
        <div class="kpi-sub">${measured.length - upN ? L('{n} offline', { n: measured.length - upN }) : L('alles online')}${all.some((r) => r.now === 'maint') ? ' · ' + L('{n} in onderhoud', { n: all.filter((r) => r.now === 'maint').length }) : ''}</div></div>
      <div class="kpi ${pAll !== null && pAll < target ? 'bad' : ''}"><div class="kpi-lbl">${L('Beschikbaar')} · ${esc(perLbl)}</div><div class="kpi-val">${avPct(pAll).replace('%', '<small>%</small>')}</div>
        <div class="kpi-sub">${L('gemiddelde van alle apparaten')}</div></div>
      <div class="kpi ${nOut ? 'warn' : ''}"><div class="kpi-lbl">${L('Storingen')}</div><div class="kpi-val">${nOut}</div><div class="kpi-sub">${esc(perLbl)}</div></div>
      <div class="kpi"><div class="kpi-lbl">${L('Downtime')}</div><div class="kpi-val">${downAll ? esc(durText(downAll)) : '0'}</div><div class="kpi-sub">${L('opgeteld over alle apparaten')}</div></div>
      <div class="kpi ${withPct.length && okG < withPct.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Doel gehaald')} (${dec(String(target))}%)</div><div class="kpi-val">${okG}<small>/${withPct.length}</small></div>
        <div class="kpi-sub">${L('groepen')}</div></div>
    </div>`;
  const hint = noOwn ? `<p class="av-hint">ℹ️ ${L('Nog geen eigen groepen. Geef een server een groep bij ✎ Server bewerken → Groep (bv. Thuis, Kantoor, Klant A).')}</p>` : '';

  const card = (g) => {
    const s = g.st, open = avOpenGroups.has(g.key), sla = avSla(s.pct, target, span);
    const dots = s.rows.map((r) => `<i class="av-dot ${r.now}" title="${esc(r.h.name + ': ' + avNowText(r.h, r.now))}"></i>`).join('');
    const vals = s.series.filter((v) => v !== null);
    const rows = s.rows.map((r) => {
      const u = stats.uptime[r.h.id] || {};
      return `<tr class="${r.now}">
        <td><button type="button" class="hl-link" data-avhost="${r.h.id}">${esc(hostIcon(r.h))} ${esc(r.h.name)}</button></td>
        <td><span class="av-now ${r.now}">${esc(avNowText(r.h, r.now))}</span></td>
        <td class="num"><b>${avPct(r.d ? r.d.pct : null)}</b></td>
        <td class="num av-hide-s">${avPct(u.h24)}</td><td class="num av-hide-s">${avPct(u.d7)}</td><td class="num av-hide-s">${avPct(u.d30)}</td>
        <td class="av-hide-s">${r.d ? avCells(r.d.series, target) : ''}</td>
        <td class="num">${r.d && r.d.n ? r.d.n + (r.d.open ? ' <span class="av-busy" title="' + esc(L('nog bezig')) + '">●</span>' : '') : '0'}</td>
        <td class="num">${r.d && r.d.down_s ? esc(durText(r.d.down_s)) : '–'}</td></tr>`;
    }).join('');
    return `<article class="av-g ${avCls(s.pct, target)}${s.measured.length - s.up ? ' has-down' : ''}${open ? ' open' : ''}" data-avg="${esc(g.key)}">
      <button type="button" class="av-g-head" data-av-toggle="${esc(g.key)}" aria-expanded="${open}">
        <span class="av-g-name">${g.icon} ${esc(g.name)} <small class="muted">${g.hosts.length}</small></span>
        <span class="av-g-pct">${avPct(s.pct)}</span>
      </button>
      <div class="av-g-on"><span><b>${s.up}</b>/${s.measured.length} ${L('online')}</span>${s.maint ? ` · ${L('{n} in onderhoud', { n: s.maint })}` : ''} <span class="av-dots">${dots}</span></div>
      <div class="av-sla ${sla.cls}">${esc(sla.text)}</div>
      ${vals.length > 1 ? hmSpark(vals, 'av-spark ' + avCls(s.pct, target)) : '<div class="hm-spark-empty"></div>'}
      <div class="av-g-foot">${L('Storingen')} <b>${s.n}</b> · ${L('downtime')} <b>${s.down ? esc(durText(s.down)) : '0'}</b>${s.longest ? ` · ${L('langste')} <b>${esc(durText(s.longest))}</b>` : ''}
        <button type="button" class="av-more" data-av-toggle="${esc(g.key)}">${open ? '▾ ' + L('verbergen') : '▸ ' + L('per server')}</button></div>
      ${open ? `<div class="av-tbl-wrap"><table class="av-tbl"><thead><tr><th>${L('Server')}</th><th>${L('Nu')}</th><th class="num">${esc(perLbl)}</th>
        <th class="num av-hide-s">${L('24 u')}</th><th class="num av-hide-s">${L('7 d')}</th><th class="num av-hide-s">${L('30 d')}</th>
        <th class="av-hide-s">${avKey === '1' ? L('per uur') : L('per dag')}</th><th class="num">${L('Storingen')}</th><th class="num">${L('Downtime')}</th></tr></thead><tbody>${rows}</tbody></table></div>` : ''}
    </article>`;
  };

  // Minst betrouwbaar: laagste percentage, dan de meeste storingen
  const worst = all.filter((r) => r.d && (r.d.n || (r.d.pct !== null && r.d.pct < 100)))
    .sort((a, b) => (a.d.pct ?? 101) - (b.d.pct ?? 101) || b.d.n - a.d.n).slice(0, 5);
  const worstHtml = worst.length ? `<ol class="av-list">${worst.map((r) => `<li>
      <button type="button" class="hl-link" data-avhost="${r.h.id}">${esc(hostIcon(r.h))} ${esc(r.h.name)}</button>
      <span class="muted small">${esc(groupOf.get(+r.h.id) ? groupOf.get(+r.h.id).name : '')}</span>
      <span class="av-l-r"><b class="av-c-${avCls(r.d.pct, target)}">${avPct(r.d.pct)}</b> <span class="muted small">${L('{n} storingen', { n: r.d.n })}${r.d.down_s ? ' · ' + esc(durText(r.d.down_s)) : ''}</span></span>
      <span class="av-meter"><i class="${avCls(r.d.pct, target)}" style="width:${Math.max(2, Math.min(100, r.d.pct ?? 0))}%"></i></span></li>`).join('')}</ol>`
    : `<p class="muted">✔ ${L('Alles was de hele periode bereikbaar.')}</p>`;
  const byId = new Map(state.hosts.map((h) => [+h.id, h]));
  const outs = (avData.outages || []).filter((o) => byId.has(+o.host_id) && +byId.get(+o.host_id).enabled).slice(0, 12);
  const outHtml = outs.length ? `<ul class="av-list av-outs">${outs.map((o) => { const h = byId.get(+o.host_id); return `<li class="${o.end === null ? 'busy' : ''}">
      <button type="button" class="hl-link" data-avhost="${h.id}">${esc(hostIcon(h))} ${esc(h.name)}</button>
      <span class="muted small">${o.from_start ? L('al vóór de periode') : esc(avWhen(o.start))}${o.end ? ' → ' + esc(avWhen(o.end)) : ''}</span>
      <span class="av-l-r">${o.end === null ? `<span class="av-now down">${L('nog bezig')}</span> ` : ''}<b>${esc(durText(o.dur))}</b></span></li>`; }).join('')}</ul>`
    : `<p class="muted">✔ ${L('Geen storingen in deze periode.')}</p>`;

  $('#avail').innerHTML = tools + hint + kpis
    + `<div class="av-groups">${groups.map(card).join('') || `<p class="muted">${L('Nog geen servers.')}</p>`}</div>`
    + `<div class="av-cols">
        <section class="card av-box"><h3>📉 ${L('Minst betrouwbaar')} <span class="muted small">${esc(perLbl)}</span></h3>${worstHtml}</section>
        <section class="card av-box"><h3>⏱️ ${L('Laatste storingen')} <span class="muted small">${esc(perLbl)}</span></h3>${outHtml}</section>
      </div>`
    + `<p class="muted small av-foot">${esc(L('Beschikbaar = het deel van de metingen van ping (of zonder ping: TCP en HTTP) dat niet CRITICAL was. Een storing = de tijd dat de server onbereikbaar was: al die checks tegelijk CRITICAL. Het groepspercentage is het gemiddelde van de apparaten.'))}</p>`;
}

function initAvail() {
  $('#mon-tabs').addEventListener('click', (e) => { const b = e.target.closest('[data-msub]'); if (b) setMonSub(b.dataset.msub); });
  $('#avail').addEventListener('click', (e) => {
    const g = e.target.closest('[data-av-group]');
    if (g) { uiSet('av_group', 'rzmon-av-group', g.dataset.avGroup); avOpenGroups.clear(); return renderAvail(); }
    const p = e.target.closest('[data-av-period]');
    if (p) { uiSet('av_period', 'rzmon-av-period', p.dataset.avPeriod); return avLoad(true); }
    const t = e.target.closest('[data-av-toggle]');
    if (t) { const k = t.dataset.avToggle; if (avOpenGroups.has(k)) avOpenGroups.delete(k); else avOpenGroups.add(k); return renderAvail(); }
    const h = e.target.closest('[data-avhost]');
    if (h) { setMonSub('checks'); jumpToHost(h.dataset.avhost); }
  });
  $('#avail').addEventListener('change', (e) => { if (e.target.id === 'av-target') { uiSet('av_target', 'rzmon-av-target', e.target.value); renderAvail(); } });
}

// ============ 1.176.0: Monitoring → Containers (alle containers van alle servers, Podman en Docker) ============
let ctData = null, ctAt = 0, ctBusy = false;
const ctOpen = new Set();   // uitgeklapte containers (host:key)
const CT_STATE = { running: ['run', 'Running'], failed: ['fail', 'Failed'], stopped: ['stop', 'Stopped'], paused: ['pause', 'Paused'] };
const ctFilter = () => uiGet('ct_filter', 'rzmon-ct-filter', 'all');
const ctGroup = () => uiGet('ct_group', 'rzmon-ct-group', 'host');
const ctRt = () => uiGet('ct_rt', 'rzmon-ct-rt', 'all');
let ctQuery = '';
/** Label Running / Failed / Stopped / Paused (ook gebruikt in de checks) */
function ctLabel(s, big = false) {
  const [cls, txt] = CT_STATE[s] || CT_STATE.stopped;
  return `<span class="ct-l ${cls}${big ? ' big' : ''}"><i></i>${txt}</span>`;
}
const ctIcon = {
  box: '<path d="M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8"/>',
  srv: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
  shield: '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
  chev: '<path d="M6 9l6 6 6-6"/>',
};
const ctSvg = (p, s = 16) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;

async function ctLoad(force = false) {
  if (!force && ctData && Date.now() - ctAt < 30000) return renderContainers();
  if (ctBusy) return;
  ctBusy = true;
  if (!ctData) $('#containers').innerHTML = `<p class="muted">${L('laden…')}</p>`;
  try { ctData = await api('containers'); ctAt = Date.now(); } catch (ex) {
    $('#containers').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return;
  } finally { ctBusy = false; }
  renderContainers();
}

function ctExit(code) {
  return { 137: L('gestopt door geheugentekort (OOM) of kill'), 143: L('netjes afgesloten na een stopsignaal'), 1: L('het programma gaf een fout'), 125: L('Podman/Docker kon hem niet starten'), 126: L('commando niet uitvoerbaar'), 127: L('commando niet gevonden'), 139: L('vastgelopen (segfault)') }[code] || '';
}

function renderContainers() {
  if (!ctData) return;
  const el = $('#containers');
  const t = ctData.total, f = ctFilter(), g = ctGroup(), rt = ctRt(), q = ctQuery.trim().toLowerCase();
  const pct = (a, b) => (b ? Math.round(a / b * 100) : 0);
  const ring = (() => {
    const C = 2 * Math.PI * 26, run = t.n ? t.running / t.n * C : 0, bad = t.n ? t.failed / t.n * C : 0;
    return `<svg class="ct-ring" width="64" height="64" viewBox="0 0 64 64" role="img" aria-label="${esc(L('{n} van {t} draaien', { n: t.running, t: t.n }))}"><circle cx="32" cy="32" r="26" class="ct-ring-bg"/>`
      + `<circle cx="32" cy="32" r="26" class="ct-ring-ok" stroke-dasharray="${run.toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 32 32)"/>`
      + (bad ? `<circle cx="32" cy="32" r="26" class="ct-ring-bad" stroke-dasharray="${bad.toFixed(1)} ${C.toFixed(1)}" stroke-dashoffset="${(-run).toFixed(1)}" transform="rotate(-90 32 32)"/>` : '')
      + `<text x="32" y="37" text-anchor="middle">${t.running}</text></svg>`;
  })();
  const memLimit = ctData.hosts.reduce((s, h) => s + h.containers.reduce((x, c) => x + (c.state === 'running' && c.mem_limit ? Math.min(c.mem_limit, 1e12) : 0), 0), 0);
  const failedNames = ctData.hosts.flatMap((h) => h.containers.filter((c) => c.state === 'failed').map((c) => c.name));
  const disk = t.images + t.volumes + t.rw;
  const kpis = `<div class="ct-kpis">
    <div class="ct-k ct-k-main"><span class="ct-scan" aria-hidden="true"></span>${ring}<div><span class="ct-kl">${L('Containers')}</span><div><b class="ct-kv">${t.n}</b> <span class="muted small">${L('op {n} servers', { n: t.hosts })}</span>
      <div class="small">Podman ${t.podman} · Docker ${t.docker}${t.pods ? ' · ' + L('{n} pods', { n: t.pods }) : ''}</div></div></div></div>
    <button type="button" class="ct-k ct-k-fail${t.failed ? ' on' : ''}" data-ct-f="failed"><span class="ct-kl">Failed</span><b class="ct-kv">${t.failed}</b><span class="muted small">${esc(failedNames.slice(0, 3).join(', ') || L('geen'))}</span></button>
    <button type="button" class="ct-k" data-ct-f="stopped"><span class="ct-kl">Stopped</span><b class="ct-kv">${t.stopped}</b><span class="muted small">${L('netjes gestopt')}</span></button>
    <button type="button" class="ct-k" data-ct-f="paused"><span class="ct-kl">Paused</span><b class="ct-kv">${t.paused}</b><span class="muted small">${L('gepauzeerd')}</span></button>
    <div class="ct-k"><span class="ct-kl">${L('CPU en geheugen')}</span>
      <span class="ct-kr"><span>CPU <b class="mono">${dec(String(t.cpu))}%</b></span><span class="muted mono">${L('100% = één core')}</span></span><span class="ct-bar"><i style="width:${Math.min(100, t.cpu / Math.max(1, t.running) )}%" class="c-cpu"></i></span>
      <span class="ct-kr"><span>${L('Geheugen')} <b class="mono">${fmtBytes(t.mem)}</b></span>${memLimit ? `<span class="muted mono">${L('van {n}', { n: fmtBytes(memLimit) })}</span>` : ''}</span><span class="ct-bar"><i style="width:${memLimit ? pct(t.mem, memLimit) : 0}%" class="c-mem"></i></span></div>
    <div class="ct-k"><span class="ct-kl">${L('Schijf')} · ${fmtBytes(disk)}</span>
      <span class="ct-stack" aria-hidden="true"><i class="c-img" style="flex:${t.images || 0}"></i><i class="c-vol" style="flex:${t.volumes || 0}"></i><i class="c-rw" style="flex:${t.rw || 0}"></i></span>
      <span class="ct-kr small"><span>images <b>${fmtBytes(t.images)}</b></span><span>volumes <b>${fmtBytes(t.volumes)}</b></span><span>containers <b>${fmtBytes(t.rw)}</b></span></span>
      ${t.reclaim > 0 ? `<span class="ct-warn small">${L('{n} op te ruimen (ongebruikte images)', { n: fmtBytes(t.reclaim) })}</span>` : ''}</div>
  </div>`;
  const chip = (k, lbl, n, dot) => `<button type="button" class="seg-btn${f === k ? ' active' : ''}" data-ct-f="${k}">${dot ? `<i class="ct-dot ${dot}"></i>` : ''}${lbl} ${n}</button>`;
  const tools = `<div class="ct-tools">
    <input type="search" id="ct-q" placeholder="${esc(L('Zoek container of image'))}" value="${esc(ctQuery)}" aria-label="${esc(L('Zoeken'))}">
    <div class="seg" role="group">${chip('all', L('Alle'), t.n)}${chip('running', 'Running', t.running, 'run')}${chip('failed', 'Failed', t.failed, 'fail')}${chip('stopped', 'Stopped', t.stopped, 'stop')}${chip('paused', 'Paused', t.paused, 'pause')}</div>
    <div class="seg" role="group">${[['host', L('Per server')], ['stack', L('Per stack')], ['none', L('Eén lijst')]].map(([k, l]) => `<button type="button" class="seg-btn${g === k ? ' active' : ''}" data-ct-g="${k}">${l}</button>`).join('')}</div>
    <div class="seg ct-rt" role="group">${[['all', 'Podman + Docker'], ['podman', 'Podman'], ['docker', 'Docker']].map(([k, l]) => `<button type="button" class="seg-btn${rt === k ? ' active' : ''}" data-ct-rt="${k}">${l}</button>`).join('')}</div>
  </div>`;
  // Alle containers in één lijst, met de server erbij
  const all = ctData.hosts.flatMap((h) => h.containers.map((c) => ({ ...c, host: h })));
  const shown = all.filter((c) => (f === 'all' || c.state === f) && (rt === 'all' || c.runtime === rt)
    && (!q || (c.name + ' ' + c.image + ' ' + c.pod + ' ' + c.host.name).toLowerCase().includes(q)));
  const stackOf = (c) => c.pod || (c.name.includes('_') ? c.name.split('_')[0] : '') || L('zonder stack');
  let groups;
  if (g === 'none') groups = [{ key: 'all', title: L('Alle containers'), sub: '', list: shown }];
  else if (g === 'stack') {
    const m = new Map(); shown.forEach((c) => { const k = stackOf(c); if (!m.has(k)) m.set(k, []); m.get(k).push(c); });
    groups = [...m.entries()].sort((a, b) => a[0].localeCompare(b[0])).map(([k, list]) => ({ key: 's:' + k, title: k, sub: L('{n} containers', { n: list.length }), list }));
  } else {
    groups = ctData.hosts.map((h) => ({ key: 'h:' + h.id, host: h, title: h.name, list: shown.filter((c) => c.host.id === h.id) })).filter((x) => x.list.length);
  }
  const head = `<div class="ct-tr ct-th"><span>${L('Container')}</span><span>${L('Status')}</span><span>${L('Image')}</span><span>${L('Draait')}</span><span>CPU</span><span>${L('Geheugen')}</span><span>${L('Schijf')}</span><span>${L('Herstart')}</span><span>${L('24 uur')}</span><span class="r">${L('Check')}</span></div>`;
  const row = (c, i) => {
    const id = c.host.id + ':' + c.key, open = ctOpen.has(id), run = c.state === 'running';
    const memW = c.mem_pct !== null ? Math.min(100, c.mem_pct) : 0;
    const sub = [g !== 'host' ? c.host.name : '', c.pod ? 'pod: ' + c.pod : '', c.user && c.user !== 'root' && c.user !== 'docker' ? L('gebruiker: {u}', { u: c.user }) : '', c.health === 'unhealthy' ? L('ongezond (healthcheck faalt)') : c.health === 'healthy' ? L('gezond') : ''].filter(Boolean).join(' · ');
    const chk = c.check ? `<button type="button" class="ct-mon" data-ct-check="${c.check.id}">${ctSvg(ctIcon.shield, 14)}${L('bewaakt')}</button>`
      : (isAdmin() ? `<button type="button" class="btn small-btn" data-ct-add="${c.host.id}" data-ct-key="${esc(c.key)}">＋ ${L('Check')}</button>` : `<span class="muted small">–</span>`);
    return `<div class="ct-tr ct-s-${c.state}${open ? ' open' : ''}" data-ct-row="${esc(id)}" style="--i:${Math.min(i, 20)}" role="button" tabindex="0" aria-expanded="${open}">
      <span class="ct-name"><b>${esc(c.name)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</span>
      <span>${ctLabel(c.state)}</span>
      <span class="mono ct-img" data-l="${esc(L('Image'))}" title="${esc(c.image)}">${esc(c.image || '–')}</span>
      <span class="mono" data-l="${esc(L('Draait'))}">${run && c.started ? durText(Date.now() / 1000 - c.started) : '–'}</span>
      <span class="ct-cpu" data-l="CPU">${c.cpu !== null ? `<span class="ct-bar"><i class="c-cpu" style="width:${Math.min(100, c.cpu)}%"></i></span><b class="mono">${dec(String(c.cpu))}%</b>` : '<span class="muted">–</span>'}</span>
      <span class="ct-mem" data-l="${esc(L('Geheugen'))}"><span class="mono small">${c.mem !== null ? fmtBytes(c.mem) + (c.mem_limit && c.mem_limit < 1e12 ? ' / ' + fmtBytes(c.mem_limit) : '') : '–'}</span>${c.mem_pct !== null ? `<span class="ct-bar"><i class="${memW >= 90 ? 'c-bad' : memW >= 75 ? 'c-warn' : 'c-mem'}" style="width:${memW}%"></i></span>` : ''}</span>
      <span class="mono small" data-l="${esc(L('Schijf'))}" title="${esc(L('container {a} · volumes {b}', { a: fmtBytes(c.size_rw), b: fmtBytes(c.volumes) }))}">${fmtBytes(c.size_rw + c.volumes)}</span>
      <span class="mono ${c.restarts ? 'ct-bad' : 'muted'}" data-l="${esc(L('Herstart'))}">${c.restarts}</span>
      <span class="ct-24" data-l="${esc(L('24 uur'))}">${c.check ? timelineOf([c.check.id]) : '<span class="muted small">–</span>'}</span>
      <span class="r">${chk}</span>
    </div>${open ? ctDetail(c) : ''}`;
  };
  const groupHtml = (x, gi) => {
    const h = x.host;
    const cnt = (s) => x.list.filter((c) => c.state === s).length;
    const sum = [L('{n} containers', { n: x.list.length }), cnt('failed') ? cnt('failed') + ' failed' : '', cnt('stopped') ? cnt('stopped') + ' stopped' : '', cnt('paused') ? cnt('paused') + ' paused' : ''].filter(Boolean).join(' · ');
    const cpu = x.list.reduce((s, c) => s + (c.cpu || 0), 0), mem = x.list.reduce((s, c) => s + (c.mem || 0), 0), dsk = x.list.reduce((s, c) => s + c.size_rw + c.volumes, 0);
    return `<section class="ct-grp" style="--g:${gi}">
      <div class="ct-gh"><span class="ct-gi">${ctSvg(h ? ctIcon.srv : ctIcon.box, 18)}</span><b>${esc(x.title)}</b>
        ${h ? `<span class="mono muted small">${esc(h.version ? (h.containers.some((c) => c.runtime === 'docker') && !h.containers.some((c) => c.runtime === 'podman') ? 'Docker ' : 'Podman ') + h.version : '')}</span>` : ''}
        <span class="small">${esc(sum)}</span>${h && h.stale ? `<span class="ct-l stop">${L('agent stil')}</span>` : ''}${h && h.error ? `<span class="ct-warn small">${esc(h.error)}</span>` : ''}
        <span class="ct-gs small"><span>CPU <b class="mono">${dec(cpu.toFixed(1))}%</b></span><span>RAM <b class="mono">${fmtBytes(mem)}</b></span><span>${L('Schijf')} <b class="mono">${fmtBytes(dsk)}</b></span></span></div>
      <div class="ct-tbl">${head}${x.list.map(row).join('')}</div></section>`;
  };
  const empty = !ctData.hosts.length
    ? `<div class="av-hint">${L('Nog geen containers: de agent meldt ze zodra Podman of Docker op een server draait (agent 1.70.0 of nieuwer).')}</div>`
    : (!shown.length ? `<p class="muted">${L('Geen containers met deze filters.')}</p>` : '');
  el.dataset.motion = String(afMotion(null));
  el.innerHTML = kpis + tools + empty + groups.map(groupHtml).join('')
    + `<p class="muted small av-foot">${esc(L('Running = draait. Failed = gestopt met een foutcode, vastgelopen of ongezond (CRITICAL). Stopped = netjes gestopt met exitcode 0 (WARNING). Paused = gepauzeerd (WARNING). De gegevens komen van de agent, elke minuut.'))}</p>`;
}

function ctDetail(c) {
  const why = c.state === 'failed' ? ctExit(c.exit_code) : '';
  const kv = [
    [L('Laatste status'), `${esc(c.raw_state || '–')}${c.state !== 'running' ? ` · exitcode <b>${c.exit_code}</b>` : ''}`],
    why ? [L('Betekent'), esc(why)] : null,
    [L('Status van de runtime'), esc(c.status_text || '–')],
    c.started ? [c.state === 'running' ? L('Draait sinds') : L('Laatst gestart'), esc(new Date(c.started * 1000).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' }))] : null,
    c.net_in !== null ? [L('Netwerk'), `↓ ${fmtBytes(c.net_in)} · ↑ ${fmtBytes(c.net_out)}`] : null,
    c.pids !== null ? [L('Processen'), String(c.pids)] : null,
    [L('Runtime'), esc(c.runtime === 'docker' ? 'Docker' : 'Podman' + (c.user && c.user !== 'root' ? ` (rootless, ${c.user})` : ''))],
  ].filter(Boolean);
  return `<div class="ct-det ct-s-${c.state}">
    <div class="ct-kvs">${kv.map(([k, v]) => `<span>${k}</span><span>${v}</span>`).join('')}</div>
    <div class="ct-det-a">${c.check ? `<button type="button" class="btn small-btn" data-ct-check="${c.check.id}">${L('Check openen')}</button>${aiBtn('check', c.check.id)}`
      : (isAdmin() ? `<button type="button" class="btn small-btn" data-ct-add="${c.host.id}" data-ct-key="${esc(c.key)}">＋ ${L('Check maken')}</button>` : '')}
      <button type="button" class="btn small-btn" data-ct-host="${c.host.id}">${L('Naar de server')}</button></div>
  </div>`;
}

function initContainers() {
  const el = $('#containers');
  el.addEventListener('click', (e) => {
    const fb = e.target.closest('[data-ct-f]');
    if (fb) { uiSet('ct_filter', 'rzmon-ct-filter', ctFilter() === fb.dataset.ctF && fb.classList.contains('ct-k') ? 'all' : fb.dataset.ctF); return renderContainers(); }
    const gb = e.target.closest('[data-ct-g]');
    if (gb) { uiSet('ct_group', 'rzmon-ct-group', gb.dataset.ctG); return renderContainers(); }
    const rb = e.target.closest('[data-ct-rt]');
    if (rb) { uiSet('ct_rt', 'rzmon-ct-rt', rb.dataset.ctRt); return renderContainers(); }
    const ck = e.target.closest('[data-ct-check]');
    if (ck) { e.stopPropagation(); return checkInfo(+ck.dataset.ctCheck); }
    const add = e.target.closest('[data-ct-add]');
    if (add) { e.stopPropagation(); return checkForm(null, findHost(+add.dataset.ctAdd), { type: 'container', target: add.dataset.ctKey, name: '', options: '{"res":{}}' }); }
    const hb = e.target.closest('[data-ct-host]');
    if (hb) { setMonSub('checks'); return jumpToHost(hb.dataset.ctHost); }
    if (e.target.closest('button, a, [data-ai]')) return;
    const r = e.target.closest('[data-ct-row]');
    if (r) { const k = r.dataset.ctRow; if (ctOpen.has(k)) ctOpen.delete(k); else ctOpen.add(k); renderContainers(); }
  });
  el.addEventListener('keydown', (e) => {
    const r = e.target.closest('[data-ct-row]');
    if (r && (e.key === 'Enter' || e.key === ' ') && e.target === r) { e.preventDefault(); r.click(); }
  });
  el.addEventListener('input', (e) => {
    if (e.target.id !== 'ct-q') return;
    ctQuery = e.target.value;
    const pos = e.target.selectionStart;
    renderContainers();
    const n = $('#ct-q'); if (n) { n.focus(); n.setSelectionRange(pos, pos); }
  });
}

// ============ 1.177.0: pad-check (popup, Monitoring → Netwerkpaden, summier bij de server) ============
const NP_ICON = {
  src: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
  virt: '<rect x="4" y="5" width="16" height="11" rx="2"/><path d="M8 20h8M12 16v4M8 9l2 2-2 2M13 13h3"/>',
  switch: '<rect x="3" y="8" width="18" height="8" rx="2"/><path d="M7 12h.01M10 12h.01M13 12h.01M16 12h.01"/>',
  via: '<rect x="3" y="8" width="18" height="8" rx="2"/><path d="M7 12h.01M10 12h.01M13 12h.01M16 12h.01"/>',
  router: '<rect x="4" y="14" width="16" height="6" rx="2"/><path d="M8 17h.01M12 11V4M9 7l3-3 3 3"/>',
  hop: '<rect x="4" y="14" width="16" height="6" rx="2"/><path d="M8 17h.01M12 11V4M9 7l3-3 3 3"/>',
  ext: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  hidden: '<circle cx="12" cy="12" r="9"/><path d="M9 12h6"/>',
  dst: '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2"/>',
};
const npSvg = (k, s = 22) => `<svg width="${s}" height="${s}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${NP_ICON[k] || NP_ICON.hop}</svg>`;
const NP_KIND = () => ({ src: L('BRON'), virt: L('VIRTUEEL'), switch: L('SWITCH'), via: L('APPARAAT'), router: L('ROUTER'), hop: L('INTERN'), ext: L('EXTERN'), hidden: L('GEEN ANTWOORD'), dst: L('DOEL') });
const npMs = (v) => (v === null || v === undefined ? '–' : dec(String(v >= 100 ? Math.round(v) : Math.round(v * 10) / 10)) + ' ms');
const npSpeed = (m) => (!m ? '' : m >= 1000 ? dec(String(Math.round(m / 100) / 10)) + ' Gbit' : m + ' Mbit');
const npMbit = (m) => (m === null || m === undefined ? '' : m >= 1000 ? dec(String(Math.round(m / 100) / 10)) + ' Gbit/s' : Math.round(m) + ' Mbit/s');

/** Het pad van links naar rechts: knooppunten met een icoon, lijnen met pulsjes, vertraging erboven en snelheid eronder */
function npStrip(m) {
  if (!m || !m.nodes) return `<p class="muted small">${L('Nog geen meting.')}</p>`;
  const kinds = NP_KIND();
  const tp = m.tp && m.tp.mbit ? m.tp : null;
  const list = [];
  m.nodes.forEach((n) => {
    if (n.kind === 'ext' || n.kind === 'hidden') {
      const last = list[list.length - 1];
      if (last && last.kind === 'net') { last.count++; last.avg = n.avg ?? last.avg; if (n.st === 'w' || n.st === 'c') last.st = n.st; return; }
      list.push({ kind: 'net', name: L('Internet'), ip: n.ip, avg: n.avg, st: n.st === 'w' || n.st === 'c' ? n.st : 'x', count: 1 });
      return;
    }
    list.push(n);
  });
  list.forEach((n) => { if (n.kind === 'net') { n.kind = 'ext'; n.ip = L('{n} hops', { n: n.count }); } });
  const parts = list.map((n, i) => {
    const next = list[i + 1];
    const sub = n.kind === 'src' ? [n.dev, npSpeed(n.speed)].filter(Boolean).join(' · ')
      : n.kind === 'switch' ? [n.via === 'unifi' ? 'UniFi' : n.via === 'lldp' ? 'LLDP' : '', n.port ? L('poort {p}', { p: n.port }) : ''].filter(Boolean).join(' · ')
      : n.kind === 'virt' ? n.ip : n.ip && n.ip !== n.name ? n.ip : '';
    let link = '';
    if (next) {
      const ms = next.avg !== null && next.avg !== undefined ? npMs(next.avg) : '';
      // Snelheid onder de lijn: linksnelheid (UniFi of de netwerkkaart) en, binnen je netwerk, de gemeten doorvoer
      let low = '', how = '';
      if (n.kind === 'switch' && n.uplink_speed) { low = npSpeed(n.uplink_speed); how = L('uplink poort {p}', { p: n.uplink_port || '?' }); }
      else if (next.kind === 'switch' && next.speed) { low = npSpeed(next.speed); how = L('poort {p}', { p: next.port }); }
      else if (n.kind === 'src' && n.speed) { low = npSpeed(n.speed); how = n.dev || ''; }
      if (tp && (next.kind === 'switch' || (i === 0 && !list.some((x) => x.kind === 'switch')))) { low = npMbit(tp.mbit); how = 'iperf3' + (tp.name ? ' → ' + tp.name : ''); }
      link = `<div class="np-link" data-st="${next.st}"><span class="np-lt">${ms}</span><span class="np-wire"><i></i><i></i></span><span class="np-lb">${esc(low)}</span><span class="np-lh">${esc(how)}</span></div>`;
    }
    return `<div class="np-node k-${n.kind}" data-st="${n.st}" title="${esc(n.ip || '')}"><span class="np-ic">${npSvg(n.kind)}</span><b>${esc(n.name || '?')}</b>${sub ? `<span class="mono">${esc(sub)}</span>` : ''}<span class="np-tag">${kinds[n.kind] || ''}</span></div>${link}`;
  }).join('');
  return `<div class="np-strip" data-motion="${afMotion(null)}">${parts}</div>`;
}

/**
 * 1.190.0: het pad als lichtspoor, voor tegels en rijen. Past altijd: elk knooppunt staat op een plek in procenten.
 * Bekende punten (bron, VM, switch, gateway, eerste hop van de provider, tussenliggend apparaat, doel, en elke hop
 * met een probleem) zijn een gloeiend bolletje, de rest een streepje. code = status per knooppunt, kinds = soort.
 */
const NP_KIND_LETTER = { src: 's', virt: 'v', switch: 'w', via: 'p', hidden: 'h', router: 'r', hop: 'o', ext: 'e', dst: 'd' };
const npStCls = (ch) => ({ w: 'w', c: 'c', h: 'u', x: 'e', d: 'd', v: 'd' }[ch] || 'g');   // e = hop buiten de deur die antwoordt
function npDots(code, kinds) {
  const st = String(code || '').split('');
  if (!st.length) return '';
  let k = String(kinds || '').split('');
  if (k.length !== st.length) k = st.map((ch, i) => (i === 0 ? 's' : i === st.length - 1 ? 'd' : ch === 'h' ? 'h' : ch === 'x' ? 'e' : 'o'));   // oude samenvatting
  const firstExt = k.indexOf('e');
  const lbl = (i) => ({ r: 'GW', w: 'SW', v: 'VM', p: 'VIA', d: L('DOEL') }[k[i]] || (i === firstExt ? 'ISP' : ''));
  const big = st.map((ch, i) => 'svwprd'.includes(k[i]) || i === firstExt || ch === 'w' || ch === 'c');
  // Een bolletje krijgt meer ruimte dan een streepje; eerste en laatste precies aan de randen
  const wt = big.map((b) => (b ? 3 : 1));
  let acc = 0;
  const mid = wt.map((w) => { const m = acc + w / 2; acc += w; return m; });
  const span = mid[mid.length - 1] - mid[0] || 1;
  const pos = mid.map((m) => Math.round(((m - mid[0]) / span) * 1000) / 10);
  const at = (pc) => `calc(6px + (100% - 12px) * ${pc / 100})`;
  const col = { g: 'var(--ok)', w: 'var(--warn)', c: 'var(--crit)', u: 'color-mix(in srgb, var(--unknown) 60%, transparent)', e: 'color-mix(in srgb, var(--ok) 55%, var(--unknown))', d: 'var(--ok)' };
  // Labels die te dicht op elkaar staan vallen weg (DOEL blijft altijd)
  const show = new Set();
  let lastAt = -100;
  st.forEach((_, i) => { if (big[i] && lbl(i) && i < st.length - 1 && pos[i] - lastAt >= 15 && pos[st.length - 1] - pos[i] >= 15) { show.add(i); lastAt = pos[i]; } });
  show.add(st.length - 1);
  const stops = st.slice(1).map((ch, j) => `${col[npStCls(ch)]} ${pos[j]}% ${pos[j + 1]}%`).join(', ');
  const worst = st.includes('c') ? 'c' : st.includes('w') ? 'w' : 'g';
  const nodes = st.map((ch, i) => (big[i]
    ? `<i class="np-n s-${npStCls(ch)}${k[i] === 'd' ? ' dst' : ''}" style="left:${at(pos[i])}"></i>${lbl(i) && show.has(i) ? `<em class="${i === 0 ? 'l0' : i === st.length - 1 ? 'l1' : ''}" style="left:${at(pos[i])}">${lbl(i)}</em>` : ''}`
    : `<i class="np-t s-${npStCls(ch)}" style="left:${at(pos[i])}"></i>`)).join('');
  return `<span class="np-trail w-${worst}" aria-hidden="true"><span class="np-rail" style="background:linear-gradient(90deg, ${stops})"></span>${nodes}<span class="np-pulse"></span></span>`;
}
const pathSum = (c) => { if (!c || c.type !== 'path' || !c.path_sum) return null; try { return JSON.parse(c.path_sum); } catch { return null; } };
/** In de lijst met checks: bolletjes + kort wat er speelt */
function pathMini(c) {
  const p = pathSum(c);
  if (!p) return '';
  return `<span class="np-mini">${npDots(p.d, p.k)}<small>${esc([p.w, p.ms !== null ? npMs(p.ms) : '', p.mb ? npMbit(p.mb) : ''].filter(Boolean).join(' · '))}</small></span>`;
}

/** De popup van een pad-check: het pad, per hop een tabel met 24 uur, doorvoer per uur en wat er veranderde */
async function npLoadInfo(id) {
  const el = $('#ci2-path');
  if (!el) return;
  let r;
  try { r = await api('path_info', null, { id }); } catch (ex) { el.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const m = r.model;
  if (!m) {
    el.innerHTML = `<section class="ci2-sec"><h4 class="ci2-sh">${L('Het pad')}</h4><p class="muted small">${L('Wacht op de eerste meting. Nodig op {h}: de agent 1.78.0 of nieuwer, en mtr of traceroute.', { h: esc(r.check.host) })}
      ${r.caps.length ? ' ' + L('Gevonden: {l}', { l: esc(r.caps.join(', ')) }) : ''}</p></section>`;
    return;
  }
  const kinds = NP_KIND();
  const segs = (b) => `<span class="tl24">${(b || Array(24).fill(null)).map((s) => `<i class="${s === null || s === undefined ? 'n' : 's' + s}"></i>`).join('')}</span>`;
  const rows = m.nodes.map((n, i) => {
    const bar = n.kind === 'dst' ? r.bars.dst : r.bars[n.ip];
    const link = n.kind === 'switch' ? [n.speed ? L('poort {p}: {s}', { p: n.port, s: npSpeed(n.speed) }) : '', n.uplink_speed ? L('uplink: {s}', { s: npSpeed(n.uplink_speed) }) : ''].filter(Boolean).join(' · ')
      : n.kind === 'src' ? (n.speed ? `${esc(n.dev || '')} ${npSpeed(n.speed)}` : '–') : '–';
    return `<div class="np-tr" data-st="${n.st}"><b class="mono">${n.n ?? (n.kind === 'src' ? 0 : '·')}</b>
      <span class="np-tn"><b>${esc(n.name || '?')}</b><span class="mono">${esc(n.ip && n.ip !== n.name ? n.ip : '')}</span></span>
      <span><span class="np-tag k-${n.kind}">${kinds[n.kind] || ''}</span></span>
      <b class="mono">${npMs(n.avg)}</b><span class="mono small">${n.best !== null && n.best !== undefined ? `${npMs(n.best)} / ${npMs(n.worst)}` : '–'}</span>
      <span class="mono small">${n.jit !== null && n.jit !== undefined ? dec(String(n.jit)) : '–'}</span>
      <span class="mono small ${n.loss >= (m.opts.loss_warn || 5) && n.kind !== 'ext' ? 'ct-bad' : ''}">${n.loss !== null && n.loss !== undefined ? Math.round(n.loss) + '%' : '–'}</span>
      <span class="mono small">${esc(link)}</span>${segs(bar)}</div>`;
  }).join('');
  const tp = m.tp;
  const th = r.tp_hist || [];
  const maxMb = Math.max(1, ...th.map((x) => x.mbit));
  const bars = th.length ? `<div class="np-bars" role="img" aria-label="${esc(L('Doorvoer per meting, laatste 24 uur'))}">${th.slice(-24).map((x) => `<span style="height:${Math.max(4, Math.round(x.mbit / maxMb * 100))}%" class="${x.mbit < (m.opts.tp_min || 200) ? 'low' : ''}" title="${esc(npMbit(x.mbit))}"></span>`).join('')}</div>` : '';
  const tpText = !m.opts.tp ? L('Doorvoertest staat uit.')
    : m.tp_busy ? L('Doorvoertest loopt (de ontvanger op het doel start bij de volgende ronde van de agent)…')
    : tp && tp.mbit ? L('Laatst gemeten: {m} naar {t} ({w}).', { m: npMbit(tp.mbit), t: esc(tp.name || tp.to || '?'), w: esc(whenText(new Date(tp.at * 1000).toISOString().slice(0, 19).replace('T', ' '))) })
    : tp && tp.err ? `<span class="ct-bad">${esc(tp.err)}</span>` : L('Nog niet gemeten.');
  const ev = (r.events || []).map((e) => `<li class="k-${esc(e.kind)}"><i class="ci2-dot"></i><div><span class="mono muted small">${esc(whenText(e.at))}</span><br>${esc(e.text)}</div></li>`).join('');
  el.innerHTML = `<section class="ci2-sec np-sec"><h4 class="ci2-sh">${L('Het pad')} <span class="muted small">${esc(m.tool || '')} · ${L('{n} hops', { n: m.hops })} · ${L('{n} intern', { n: m.intern })}${m.unifi && m.unifi.error ? ' · UniFi: ' + esc(m.unifi.error) : m.ul ? ' · UniFi' : ''}</span></h4>${npStrip(m)}</section>
    <section class="ci2-sec"><h4 class="ci2-sh">${L('Per hop')}</h4>
      <div class="np-tbl"><div class="np-tr np-th"><span>#</span><span>${L('Apparaat')}</span><span>${L('Soort')}</span><span>${L('Vertraging')}</span><span>${L('Beste / slechtste')}</span><span>Jitter</span><span>${L('Verlies')}</span><span>${L('Link')}</span><span>${L('24 uur')}</span></div>${rows}</div>
      <p class="muted small">${L('Verlies op een tussenhop van je provider telt niet als storing zolang de hops erna niets verliezen: die routers beantwoorden pings met lage voorrang.')}</p></section>
    <div class="ci2-g2"><section class="ci2-sec"><h4 class="ci2-sh">${L('Doorvoer (iperf3)')}</h4>${bars}<p class="small">${tpText}</p>
        ${m.opts.tp && !m.tp_busy ? `<button type="button" class="btn small-btn need-user" data-np-tp="${r.check.id}">${L('Doorvoer nu testen')}</button>` : ''}</section>
      <section class="ci2-sec"><h4 class="ci2-sh">${L('Wat er veranderde')}</h4>${ev ? `<ol class="ci2-tl">${ev}</ol>` : `<p class="muted small">${L('Nog niets veranderd.')}</p>`}</section></div>`;
}
document.addEventListener('click', async (e) => {
  const b = e.target.closest('#modal [data-np-tp]');
  if (!b) return;
  b.disabled = true;
  try { const r = await api('path_tp_now', { id: +b.dataset.npTp }); toast(L('Doorvoertest aangevraagd naar {t}; de uitkomst staat er binnen een minuut of twee', { t: r.to })); npLoadInfo(+b.dataset.npTp); } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
});

// ---- Monitoring → Netwerkpaden ----
let npData = null, npAt = 0, npBusy = false;
async function npLoad(force = false) {
  if (!force && npData && Date.now() - npAt < 30000) return renderNetPaths();
  if (npBusy) return;
  npBusy = true;
  if (!npData) $('#netpaths').innerHTML = `<p class="muted">${L('laden…')}</p>`;
  try { npData = await api('paths'); npAt = Date.now(); } catch (ex) { $('#netpaths').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; } finally { npBusy = false; }
  renderNetPaths();
}

/** Alle paden samen als kaart: kolommen bron → virtueel → switch → intern → internet → doel */
function npMap(paths) {
  const col = { src: 0, virt: 1, switch: 2, via: 2, router: 3, hop: 3, ext: 4, hidden: 4, dst: 5 };
  const nodes = new Map(), edges = new Map();
  const RANK = { c: 4, w: 3, h: 1, x: 1, g: 0, v: 0, d: 0 };
  paths.forEach((p) => {
    if (!p.model) return;
    let prev = null;
    p.model.nodes.forEach((n) => {
      const k = n.kind === 'src' ? 'h' + p.host_id : n.kind === 'virt' ? 'v' + n.name : (n.kind === 'ext' || n.kind === 'hidden') ? 'net' : n.kind === 'dst' ? 'd' + n.ip : 'i' + (n.ip || n.name);
      if (prev === k) return;
      const cur = nodes.get(k);
      const st = n.kind === 'ext' || n.kind === 'hidden' ? 'x' : n.st;
      if (!cur) nodes.set(k, { k, col: col[n.kind] ?? 3, name: n.kind === 'ext' || n.kind === 'hidden' ? 'Internet' : n.name, ip: n.kind === 'ext' ? '' : n.ip, kind: n.kind === 'hidden' ? 'ext' : n.kind, st });
      else if (RANK[st] > RANK[cur.st]) cur.st = st;
      if (prev) {
        const ek = prev + '>' + k;
        const e0 = edges.get(ek);
        const ms = n.avg !== null && n.avg !== undefined ? n.avg : null;
        const est = n.kind === 'ext' || n.kind === 'hidden' || (n.kind === 'dst' && prev === 'net' && n.st === 'g') ? 'x' : n.st;
        if (!e0) edges.set(ek, { a: prev, b: k, st: est, ms, n: 1 });
        else { e0.n++; if (RANK[n.st] > RANK[e0.st]) e0.st = n.st; }
      }
      prev = k;
    });
  });
  const byCol = [[], [], [], [], [], []];
  nodes.forEach((n) => byCol[n.col].push(n));
  const H = Math.max(300, Math.max(...byCol.map((c) => c.length)) * 92 + 40);
  const used = byCol.map((c, i) => (c.length ? i : -1)).filter((i) => i >= 0);
  byCol.forEach((list, ci) => list.forEach((n, i) => {
    n.x = 60 + (used.indexOf(ci) / Math.max(1, used.length - 1)) * 880;
    n.y = (i + 1) * (H / (list.length + 1));
  }));
  const col2 = { g: 'var(--ok)', w: 'var(--warn)', c: 'var(--crit)', x: 'var(--unknown)', h: 'var(--unknown)', v: 'var(--primary)', d: 'var(--primary)' };
  const lines = [...edges.values()].map((e) => {
    const a = nodes.get(e.a), b = nodes.get(e.b), m = (a.x + b.x) / 2;
    const d = `M${a.x},${a.y} C${m},${a.y} ${m},${b.y} ${b.x},${b.y}`;
    return `<path d="${d}" class="np-mbg" style="stroke:${col2[e.st] || col2.g}"/><path d="${d}" class="np-mfl" style="stroke:${col2[e.st] || col2.g}"/>`;
  }).join('');
  const labels = [...edges.values()].filter((e) => e.ms !== null).map((e) => {
    const a = nodes.get(e.a), b = nodes.get(e.b);
    return `<span class="np-ml" data-st="${e.st}" style="left:${((a.x + b.x) / 2 / 10).toFixed(1)}%;top:${((a.y + b.y) / 2).toFixed(0)}px">${npMs(e.ms)}</span>`;
  }).join('');
  const nd = [...nodes.values()].map((n) => `<div class="np-mn k-${n.kind}" data-st="${n.st}" style="left:${(n.x / 10).toFixed(1)}%;top:${n.y.toFixed(0)}px"><span class="np-ic">${npSvg(n.kind, 20)}</span><b>${esc(n.name || '?')}</b>${n.ip && n.ip !== n.name ? `<span class="mono">${esc(n.ip)}</span>` : ''}</div>`).join('');
  return `<div class="np-map" style="height:${H}px"><svg viewBox="0 0 1000 ${H}" preserveAspectRatio="none" aria-hidden="true">${lines}</svg>${labels}${nd}</div>`;
}

function renderNetPaths() {
  if (!npData) return;
  const el = $('#netpaths');
  const ps = npData.paths;
  const bad = ps.filter((p) => +p.status === 1 || +p.status === 2).length;
  const tps = ps.map((p) => p.model && p.model.tp && p.model.tp.mbit).filter(Boolean);
  const minTp = tps.length ? Math.min(...tps) : null;
  const inet = ps.map((p) => p.model && p.model.nodes && p.model.nodes[p.model.nodes.length - 1]).filter((n) => n && n.avg !== null && n.avg !== undefined && !n.intern);
  const avg = inet.length ? inet.reduce((s, n) => s + n.avg, 0) / inet.length : null;
  const slowPorts = (npData.ports || []).filter((p) => p.speed && p.speed < 1000).length;
  if (!ps.length) {
    el.innerHTML = `<div class="av-hint">${L('Nog geen pad-checks. Voeg er een toe bij een server met de agent: ＋ Check → Pad (traceroute), met het doel (bijvoorbeeld 1.1.1.1) en eventueel je switches als tussenliggende apparaten.')}</div>`;
    return;
  }
  const rows = ps.map((p) => {
    const m = p.model;
    const code = m ? m.nodes.map((n) => (n.kind === 'dst' ? (n.st === 'g' ? 'd' : n.st) : n.st)).join('') : '';
    const kinds = m ? m.nodes.map((n) => NP_KIND_LETTER[n.kind] || 'o').join('') : '';
    const dst = m ? m.nodes[m.nodes.length - 1] : null;
    return `<div class="np-pr s${p.status}" data-cid="${p.id}" role="button" tabindex="0"><span class="np-src"><span><b>${esc(p.host)}</b> <span class="muted">→</span> <span class="mono">${esc(p.target)}</span></span><small class="muted">${esc(p.name)}</small></span>
      <span><span class="ax-lbl s${p.status === 0 ? 0 : p.status}" style="${p.status === 0 ? 'color:var(--ok);border-color:color-mix(in srgb,var(--ok) 45%,transparent);background:color-mix(in srgb,var(--ok) 10%,transparent)' : ''}">${STATUS[+p.status]}</span></span>
      <span class="mono">${m ? m.hops : '–'}</span><b class="mono">${dst ? npMs(dst.avg) : '–'}</b><span class="mono ${m && m.tp && m.tp.mbit && m.tp.mbit < (m.opts.tp_min || 200) ? 'ct-bad' : ''}">${m && m.tp && m.tp.mbit ? npMbit(m.tp.mbit) : '–'}</span>
      <span>${npDots(code, kinds)}</span><span>${checkTimeline({ id: p.id })}</span></div>`;
  }).join('');
  const ports = (npData.ports || []).slice(0, 14).map((p) => `<div class="np-port${p.speed && p.speed < 1000 ? ' slow' : ''}"><span><b>${esc(p.switch)} · ${L('poort {p}', { p: p.port })}</b><br><span class="muted small">${esc(p.to || p.name)}</span></span>
    <span class="r"><b class="mono">${npSpeed(p.speed) || '–'}</b><br><span class="mono small muted">${p.full ? L('vol-duplex') : L('half-duplex')}${p.errors ? ' · ' + L('{n} fouten', { n: p.errors }) : ''}</span></span></div>`).join('');
  el.dataset.motion = String(afMotion(null));
  el.innerHTML = `<div class="np-kpis">
      <div class="ct-k"><span class="ct-kl">${L('Paden')}</span><b class="ct-kv">${ps.length}</b><span class="small muted">${L('{a} in orde · {b} met een probleem', { a: ps.length - bad, b: bad })}</span></div>
      <div class="ct-k"><span class="ct-kl">${L('Naar internet')}</span><b class="ct-kv mono">${avg !== null ? npMs(avg) : '–'}</b><span class="small muted">${L('gemiddeld')}</span></div>
      <div class="ct-k${minTp !== null && minTp < 200 ? ' ct-k-fail on' : ''}"><span class="ct-kl">${L('Laagste doorvoer')}</span><b class="ct-kv mono">${minTp !== null ? npMbit(minTp) : '–'}</b><span class="small muted">iperf3</span></div>
      <div class="ct-k"><span class="ct-kl">${L('Links (UniFi)')}</span><b class="ct-kv">${npData.unifi === 'ok' ? (npData.ports || []).length : '–'}</b><span class="small muted">${npData.unifi === 'ok' ? L('{n} langzamer dan 1 Gbit', { n: slowPorts }) : npData.unifi === 'off' ? L('UniFi niet gekoppeld (met account)') : esc(npData.unifi)}</span></div>
      <div class="ct-k"><span class="ct-kl">${L('Pad gewijzigd')}</span><b class="ct-kv">${npData.changes7}×</b><span class="small muted">${L('in 7 dagen')}</span></div>
    </div>
    <section class="ct-grp np-mapsec"><div class="ct-gh"><span class="ct-gi">${npSvg('ext', 18)}</span><b>${L('Netwerkkaart')}</b><span class="small muted">${L('alle paden samen · kleur = status · stippel = virtueel of buiten je netwerk')}</span></div>${npMap(ps)}</section>
    <div class="np-cols">
      <section class="ct-grp"><div class="ct-gh"><b>${L('Paden')}</b></div>
        <div class="np-pr np-th"><span>${L('Bron → doel')}</span><span>${L('Status')}</span><span>${L('Hops')}</span><span>${L('Vertraging')}</span><span>${L('Doorvoer')}</span><span>${L('Pad')}</span><span>${L('24 uur')}</span></div>${rows}</section>
      <section class="ct-grp"><div class="ct-gh"><b>${L('Links (UniFi)')}</b><span class="small muted">${L('langzaamste eerst')}</span></div>${ports || `<p class="muted small" style="padding:.8rem 1rem">${npData.unifi === 'ok' ? L('Geen poorten gevonden.') : L('Koppel UniFi met een lokaal account (Instellingen → Koppelingen) om de switchpoorten en hun snelheid te zien.')}</p>`}</section>
    </div>`;
  npFocusApply();   // 1.191.0
}
/** 1.191.0: naar Monitoring → Netwerkpaden, met dit pad in beeld en even opgelicht */
let npFocus = null, npFlash = null;
function npGo(checkId) {
  npFocus = +checkId;
  showTab('dashboard');
  setMonSub('paths');
  npFocusApply();
}
function npFocusApply() {
  // De tabel kan vlak na het openen nog een keer opgebouwd worden: het oplichten loopt dan gewoon door
  if (npFlash && Date.now() < npFlash.until) { const r = $(`#netpaths .np-pr[data-cid="${npFlash.id}"]`); if (r) r.classList.add('np-flash'); }
  if (!npFocus) return;
  const row = $(`#netpaths .np-pr[data-cid="${npFocus}"]`);
  if (!row) return;   // nog aan het laden: renderNetPaths roept dit opnieuw aan
  npFlash = { id: npFocus, until: Date.now() + 2600 };
  npFocus = null;
  row.scrollIntoView({ block: 'center', behavior: afMotion(null) ? 'smooth' : 'auto' });
  row.classList.add('np-flash');
  setTimeout(() => { $$('#netpaths .np-pr.np-flash').forEach((e) => e.classList.remove('np-flash')); npFlash = null; }, 2600);
}
/* ---------------------------------------------------------------- 1.194.0: mDS (Mobile device summary)
   Alleen op een telefoon (≤ 720 px), vooraan in de tabbalk en daar de startpagina. Kort maar krachtig: één oordeel,
   wat nu aandacht vraagt (met snelle acties), beveiliging, netwerk en de servers. Problemen en servers komen uit de
   gewone stand (state); beveiliging en netwerk uit api('mds'), hooguit eens per minuut. */
async function loadMds() {
  mdsAt = Date.now();
  try { mdsData = await api('mds'); } catch (e) { /* de rest staat er toch */ }
  renderMds();
}
const mdsTime = (t) => new Date(t).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
function mdsBar(v, cls = '') {
  if (v === null || v === undefined || Number.isNaN(+v)) return '<span class="mds-bar none"></span>';
  const p = Math.max(0, Math.min(100, +v));
  return `<span class="mds-bar ${cls} ${p >= 90 ? 'crit' : p >= 75 ? 'warn' : ''}"><i style="width:${p}%"></i></span>`;
}
let mdsGroup = uiGet('mds_group', 'rzmon-mds-group', '');
/** 1.220.0: bytes per seconde als Mb/s, één decimaal onder de 100 */
const mdsMbit = (bps) => { const m = (+bps || 0) * 8 / 1e6; return dec(m >= 100 ? String(Math.round(m)) : m.toFixed(1)); };
/** Staafjes van de laatste pingtijden (null = geen antwoord); de hoogste in oranje als hij er duidelijk uitspringt */
function mdsSpark(vals, n, cls = 'mds-spk') {
  const v = vals.slice(-n), ok = v.filter((x) => x !== null), mx = Math.max(1, ...ok), avg = ok.length ? ok.reduce((a, b) => a + b, 0) / ok.length : 0;
  return `<span class="${cls}" aria-hidden="true">${v.map((x) => `<span class="${x === null ? 'miss' : x > avg * 2 && x === Math.max(...ok) ? 'hi' : ''}" style="height:${x === null ? 100 : Math.max(12, Math.round(x / mx * 100))}%"></span>`).join('')}</span>`;
}
function renderMds() {
  const el = $('#mds');
  if (!el || !state || !state.hosts) return;
  const hosts = state.hosts.filter((h) => +h.enabled);
  const checks = hosts.flatMap((h) => h.checks.filter((c) => +c.enabled).map((c) => [h, c]));
  const probs = checks.filter(([, c]) => isProblem(c) && !+c.info_only)
    .sort(([, a], [, b]) => !!a.acked_at - !!b.acked_at || STATUS_RANK[+b.status] - STATUS_RANK[+a.status] || String(a.problem_since || '').localeCompare(String(b.problem_since || '')));
  const open = probs.filter(([, c]) => !c.acked_at);
  const crit = open.filter(([, c]) => +c.status === 2).length;
  const okHosts = hosts.filter((h) => hostStatus(h) === 0).length;
  const okChecks = checks.filter(([, c]) => +c.status === 0 || c.maintenance).length;
  const ups = hosts.map((h) => (stats.uptime && stats.uptime[h.id] ? stats.uptime[h.id].h24 : null)).filter((v) => v !== null);
  const up = ups.length ? ups.reduce((s, v) => s + v, 0) / ups.length : null;
  const worst = crit ? 2 : open.length ? 1 : 0;
  const user = isAdmin() || isUser();
  // 1. Het oordeel
  const hero = `<section class="mds-hero s${worst}">
    <span class="mds-live"><i></i>${L('live')} · ${mdsTime(Date.now())}</span>
    <div class="mds-v">${worst ? (worst === 2 ? '🔴' : '🟠') : '✅'} <b>${worst ? (open.length === 1 ? L('1 probleem') : L('{n} problemen', { n: open.length })) : L('Alles in orde')}</b></div>
    <div class="mds-hs">${crit ? `<span class="crit">${L('{n} kritiek', { n: crit })}</span> · ` : ''}${probs.length > open.length ? `${L('{n} bevestigd', { n: probs.length - open.length })} · ` : ''}${L('{a}/{b} servers', { a: okHosts, b: hosts.length })} · ${L('{a}/{b} checks', { a: okChecks, b: checks.length })}${up !== null ? ` · ${L('24 u')} ${dec(String(Math.round(up * 10) / 10))}%` : ''}</div>
</section>`;
  // 2. Nu aandacht nodig
  const acts = (c) => (user ? `<div class="mds-acts">
      ${!c.acked_at ? `<button type="button" data-mds="ack" data-id="${c.id}" title="${esc(L('Bevestigen'))}">✔<small>${L('Bevestig')}</small></button>` : ''}
      <button type="button" data-mds="maint" data-id="${c.id}" title="${esc(L('Onderhoud 1 uur'))}">🔧<small>1 u</small></button>
      <button type="button" data-mds="quiet" data-id="${c.id}" title="${esc(L('Nu stil, 1 uur'))}">🌙<small>1 u</small></button>
      <button type="button" data-mds="run" data-id="${c.id}" title="${esc(L('Nu checken'))}">↻<small>${L('Check')}</small></button></div>` : '');
  const rows = probs.slice(0, mdsAll ? 60 : 5).map(([h, c]) => `<div class="mds-p s${+c.status}${c.acked_at ? ' acked' : ''}">
      <button type="button" class="mds-pt" data-mds="open" data-id="${c.id}"><i class="mds-dot"></i><span><b>${esc(h.name)} · ${esc(c.name)}</b><small>${esc(String(c.last_message || '').replace(/\s+/g, ' ').slice(0, 90))}</small></span>
        <span class="mds-dur">${c.acked_at ? '✔ ' : ''}${c.problem_since ? esc(dur(c.problem_since)) : ''}</span></button>${acts(c)}</div>`).join('');
  const attn = `<section class="mds-sec"><h3>${L('Nu aandacht nodig')}${probs.length > 5 ? ` <button type="button" class="mds-more" data-mds="all">${mdsAll ? L('minder') + ' ▴' : L('alle {n}', { n: probs.length }) + ' ▾'}</button>` : ''}</h3>
    ${rows || `<p class="mds-calm">✔ ${L('Niets dat aandacht vraagt')}</p>`}</section>`;
  // 3. Beveiliging
  const s = mdsData && mdsData.sec;
  const tile = (n, lbl, sub, cls, tab) => `<button type="button" class="mds-t ${cls}" data-mds="tab" data-tab="${tab}"><b>${n}</b><span>${lbl}</span>${sub ? `<small>${sub}</small>` : ''}</button>`;
  const sec = s ? `<section class="mds-sec"><h3>${L('Beveiliging')}</h3><div class="mds-tiles">
      ${tile(s.inc, L('incidenten open'), s.inc_crit ? L('{n} kritiek', { n: s.inc_crit }) : '', s.inc_crit ? 'crit' : s.inc ? 'warn' : 'ok', 'ids')}
      ${tile(s.blk_today, L('geblokkeerd vandaag'), L('{n} nu actief', { n: s.blk_now }), '', 'ids')}
      ${tile(s.logins, L('logins vandaag'), s.fails ? L('{n} mislukt', { n: s.fails }) : '', s.fails > 20 ? 'warn' : '', 'ids')}
      ${s.vulns ? tile(s.vulns.crit, L('kritieke CVE\'s'), s.vulns.kev ? L('{n} actief misbruikt', { n: s.vulns.kev }) : '', s.vulns.kev ? 'crit' : s.vulns.crit ? 'warn' : 'ok', 'vulns') : ''}
      ${s.leaks !== null && s.leaks !== undefined ? tile(s.leaks, L('datalekken'), '', s.leaks ? 'crit' : 'ok', 'vulns') : ''}</div></section>` : '';
  // 4. Netwerk
  const n = mdsData && mdsData.net;
  let net = '';
  if (n && (n.inet || n.wan || n.new !== null || (n.top && n.top.length))) {
    const i = n.inet;
    const inet = i ? `<div class="mds-row"><span>🌍 ${L('Internet')}</span><b class="mono">↓ ${i.down !== null ? npMbit(i.down) : '–'} · ↑ ${i.up !== null ? npMbit(i.up) : '–'}${i.lat !== null ? ` · ${npMs(i.lat)}` : ''}</b></div>` : '';
    const w = n.wan;
    const wan = w ? `<div class="mds-row"><span>📊 ${L('Deze maand')}</span><b class="mono">${fmtBytes(w.used)}${w.cap ? ` / ${fmtBytes(w.cap)}` : ''}</b></div>${w.cap ? mdsBar(100 * w.used / w.cap, 'wide') : ''}` : '';
    const nw = n.new !== null && n.new !== undefined ? `<button type="button" class="mds-row" data-mds="tab" data-tab="net"><span>📡 ${L('Nieuwe apparaten')}</span><b class="${n.new ? 'warn-txt' : ''}">${n.new}</b></button>` : '';
    const top = n.top && n.top.length ? `<div class="mds-top"><small>${L('Meeste verkeer vandaag')}${n.today ? ` · ${L('totaal {b}', { b: fmtBytes(n.today) })}` : ''}</small>${n.top.map((d, k) => `<div class="mds-row"><span>${k + 1}. ${esc(d.name)}</span><b class="mono">${fmtBytes(d.bytes)}</b></div>`).join('')}</div>` : '';
    net = `<section class="mds-sec"><h3>${L('Netwerk')}</h3>${inet}${wan}${nw}${top}</section>`;
  }
  // 5. Servers en apparaten (1.220.0: per soort, met netwerkverkeer bij servers en de ping bij apparaten zonder CPU/RAM/disk)
  const val = (h, t) => { const v = h.checks.filter((c) => c.type === t && +c.enabled && c.last_value !== null).map((c) => +c.last_value); return v.length ? Math.max(...v) : null; };
  const extra = (mdsData && mdsData.hosts) || {};
  const groups = {};
  hosts.forEach((h) => { const g = hostGroup(h) || 'other'; (groups[g] = groups[g] || []).push(h); });
  const order = ['server', 'network', 'storage', 'smarthome', 'power', 'camera', 'other'].filter((g) => groups[g]);
  if (mdsGroup && !groups[mdsGroup]) mdsGroup = '';
  const chips = order.length > 1 ? `<div class="mds-chips" role="group" aria-label="${esc(L('Soort'))}"><button type="button" class="mds-chip${mdsGroup ? '' : ' on'}" data-mds="grp" data-g="">${L('Alle')} <b>${hosts.length}</b></button>${order.map((g) => {
    const bad = groups[g].some((h) => hostStatus(h) === 2);
    return `<button type="button" class="mds-chip${mdsGroup === g ? ' on' : ''}" data-mds="grp" data-g="${g}">${GROUP_INFO[g][0]} ${esc(GROUP_INFO[g][1]())} <b>${groups[g].length}</b>${bad ? '<i aria-label="' + esc(L('probleem')) + '"></i>' : ''}</button>`;
  }).join('')}</div>` : '';
  const row = (h) => {
    const st = hostStatus(h), x = extra[h.id] || {};
    const quiet = h.mode === 'agent' && h.agent_seen && Date.now() - toTime(h.agent_seen) > 10 * 60000;
    const c = val(h, 'cpu'), r = val(h, 'ram'), d = val(h, 'disk');
    let right = '';
    if (c !== null || r !== null || d !== null) {
      right = `<span class="mds-m"><em>CPU</em>${mdsBar(c)}<em>RAM</em>${mdsBar(r)}<em>${L('Disk')}</em>${mdsBar(d)}${x.net ? `<em>Net</em><span class="mds-net">↓<b>${mdsMbit(x.net.rx)}</b> ↑<b>${mdsMbit(x.net.tx)}</b> Mb/s</span>` : ''}</span>`;
    } else if (x.ping) {
      const now = x.ping.last.length ? x.ping.last[x.ping.last.length - 1] : null;
      const down = now === null && st === 2;
      right = `<span class="mds-ping${down ? ' down' : ''}"><em>PING</em><b>${down ? esc(L('geen antwoord')) : now === null ? '–' : `${dec(now)}<small>ms</small>`}</b>${mdsSpark(x.ping.last, 12)}</span>`;
    } else {
      const up = stats.uptime && stats.uptime[h.id] ? stats.uptime[h.id].h24 : null;
      right = `<span class="mds-ping${st === 2 ? ' down' : ''}"><em>${st === 2 ? '' : L('bereikbaar')}</em><b>${st === 2 ? esc(L('onbereikbaar')) : up !== null ? pct(up) : '✔'}</b></span>`;
    }
    return `<button type="button" class="mds-s s${st}" data-mds="host" data-id="${h.id}"><i class="mds-dot"></i><span class="mds-sn"><b>${esc(h.name)}</b>${quiet ? `<small class="warn-txt">${L('agent stil {d}', { d: dur(h.agent_seen) })}</small>` : ''}</span>${right}</button>`;
  };
  const byStatus = (a, b) => STATUS_RANK[hostStatus(b)] - STATUS_RANK[hostStatus(a)] || String(a.name).localeCompare(String(b.name));
  const body = order.filter((g) => !mdsGroup || g === mdsGroup).map((g) => {
    const hs = groups[g].slice().sort(byStatus), bad = hs.filter((h) => hostStatus(h) === 2).length, warn = hs.filter((h) => hostStatus(h) === 1).length;
    const tag = bad ? `<span class="bad">${bad === 1 ? L('1 probleem') : L('{n} problemen', { n: bad })}</span>` : warn ? `<span class="warn">${L('{n} let op', { n: warn })}</span>` : `<span class="ok">✔ ${L('alles in orde')}</span>`;
    return `${order.length > 1 ? `<div class="mds-gh">${GROUP_INFO[g][0]} ${esc(GROUP_INFO[g][1]())} · ${hs.length} ${tag}</div>` : ''}<div class="mds-srv">${hs.map(row).join('')}</div>`;
  }).join('');
  const servers = `<section class="mds-sec"><h3>${L('Servers en apparaten')}</h3>${chips}${body}</section>`;
  el.innerHTML = hero + attn + sec + net + servers;
  const badge = $('#mds-badge');
  if (badge) { badge.hidden = !open.length; badge.textContent = open.length; badge.classList.toggle('s1', !crit); }
}
/* 1.195.0: mDS volledig met de vinger: bladen van onderaf (vegen om te sluiten), trekken om te verversen, grote tikvlakken */
const mdsBuzz = () => { try { if (navigator.vibrate) navigator.vibrate(12); } catch (e) { /* geen trilling */ } };
function mdsSheet(html) {
  let sh = $('#mds-sheet');
  if (!sh) {
    sh = document.createElement('div');
    sh.id = 'mds-sheet';
    sh.className = 'mds-sheet';
    sh.innerHTML = `<div class="mds-sheet-bg" data-mds="close"></div><div class="mds-sheet-p" role="dialog" aria-modal="true"><div class="mds-grip" data-mds="close" aria-label="${esc(L('Sluiten'))}"></div><div class="mds-sheet-c"></div></div>`;
    document.body.append(sh);
    // Vegen naar beneden sluit het blad (alleen als de inhoud bovenaan staat)
    const p = $('.mds-sheet-p', sh);
    let y0 = null, dy = 0;
    p.addEventListener('touchstart', (e) => { y0 = $('.mds-sheet-c', sh).scrollTop <= 0 ? e.touches[0].clientY : null; dy = 0; }, { passive: true });
    p.addEventListener('touchmove', (e) => { if (y0 === null) return; dy = Math.max(0, e.touches[0].clientY - y0); p.style.transform = dy ? `translateY(${dy}px)` : ''; }, { passive: true });
    p.addEventListener('touchend', () => { p.style.transform = ''; if (y0 !== null && dy > 90) mdsSheetClose(); y0 = null; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sh.classList.contains('open')) mdsSheetClose(); });
  }
  $('.mds-sheet-c', sh).innerHTML = html;
  $('.mds-sheet-c', sh).scrollTop = 0;
  sh.hidden = false;
  requestAnimationFrame(() => sh.classList.add('open'));
  document.body.classList.add('mds-sheet-on');
}
function mdsSheetClose() {
  const sh = $('#mds-sheet');
  if (!sh) return;
  sh.classList.remove('open');
  document.body.classList.remove('mds-sheet-on');
  setTimeout(() => { if (!sh.classList.contains('open')) sh.hidden = true; }, 260);
}
function mdsCheckSheet(id) {
  const [c, h] = findCheck(id);
  if (!c) return;
  const st = c.maintenance ? 4 : +c.status, [v, un] = keyValue(c);
  const user = isAdmin() || isUser();
  const btn = (act, ic, lbl, cls = '') => `<button type="button" class="mds-big ${cls}" data-mds="${act}" data-id="${c.id}">${ic}<span>${lbl}</span></button>`;
  mdsSheet(`<div class="mds-sh s${st}"><i class="mds-dot"></i><div><b>${esc(h.name)} · ${esc(c.name)}</b>
      <small>${esc(STATUS_WORD[+c.status] || '?')}${c.problem_since && +c.status ? ' · ' + L('al {d}', { d: esc(dur(c.problem_since)) }) : ''}${c.acked_at ? ' · ✔ ' + L('bevestigd') : ''}</small></div></div>
    <p class="mds-msg">${esc(c.last_message || '–')}</p>
    <div class="mds-kv">${v !== '' && v !== null && v !== undefined ? `<span>${L('Waarde')}</span><b class="mono">${v}${esc(un || '')}</b>` : ''}
      <span>${L('Laatste check')}</span><b>${c.last_checked ? agoSpan(c.last_checked) : '–'}</b>
      ${c.pend_status != null && +c.pend_count > 0 ? `<span>${L('Bevestigen')}</span><b>⏳ ${esc(STATUS_WORD[+c.pend_status] || '?')} ${+c.pend_count}×</b>` : ''}</div>
    ${user ? `<div class="mds-bigs">${!c.acked_at && +c.status ? btn('ack', '✔', L('Bevestigen'), 'pri') : ''}${btn('run', '↻', L('Nu checken'))}${btn('maint', '🔧', L('Onderhoud 1 uur'))}${btn('quiet', '🌙', L('Stil 1 uur'))}</div>` : ''}
    <button type="button" class="mds-link" data-mds="full" data-id="${c.id}">${L('Volledige check openen')} ›</button>`);
}
/** 1.220.0: de ping groot in het blad: nu, gemiddeld en hoogste over 24 uur, per uur, en het verlies */
function mdsPingBlock(pg) {
  const now = pg.last.length ? pg.last[pg.last.length - 1] : null;
  const ms = (v) => (v === null || v === undefined ? '–' : `${dec(v)} ms`);
  const hours = pg.h24.filter((x) => x !== null);
  return `<div class="mds-pbig"><div><span>${L('Ping nu')}</span><b>${now === null ? esc(L('geen antwoord')) : ms(now)}</b></div><div><span>${L('Gem. 24 u')}</span><b>${ms(pg.avg)}</b></div><div><span>${L('Hoogste')}</span><b>${ms(pg.max)}</b></div></div>
    ${hours.length ? `${mdsSpark(pg.h24, 24, 'mds-pspk')}<div class="mds-pax"><span>${L('24 u geleden')}</span><span>${L('12 u')}</span><span>${L('nu')}</span></div>` : ''}
    ${pg.loss !== null ? `<div class="mds-row"><span>${L('Pakketverlies 24 u')}</span><b class="mono${pg.loss > 1 ? ' warn-txt' : ''}">${dec(pg.loss)}%</b></div>` : ''}`;
}
function mdsHostSheet(id) {
  const h = findHost(id);
  if (!h) return;
  const st = hostStatus(h), user = isAdmin() || isUser();
  const probs = h.checks.filter(isProblem).sort((a, b) => STATUS_RANK[+b.status] - STATUS_RANK[+a.status]);
  const val = (t) => { const x = h.checks.filter((c) => c.type === t && +c.enabled && c.last_value !== null).map((c) => +c.last_value); return x.length ? Math.max(...x) : null; };
  const met = [['CPU', val('cpu')], ['RAM', val('ram')], [L('Disk'), val('disk')]].filter(([, x]) => x !== null);
  const up = stats.uptime && stats.uptime[h.id] ? stats.uptime[h.id].h24 : null;
  const xh = ((mdsData && mdsData.hosts) || {})[h.id] || {};   // 1.220.0: verkeer en ping
  mdsSheet(`<div class="mds-sh s${st}"><i class="mds-dot"></i><div><b>${esc(h.name)}</b><small class="mono">${esc(h.address || '')}${up !== null ? ` · ${L('24 u')} ${pct(up)}` : ''}</small></div></div>
    ${h.mode === 'agent' && h.agent_seen ? `<p class="mds-msg muted">${L('agent {ago}', { ago: L('{d} geleden', { d: dur(h.agent_seen) }) })}</p>` : ''}
    ${met.length || xh.net ? `<div class="mds-mets">${met.map(([l, x]) => `<div><span>${l}</span>${mdsBar(x, 'wide')}<b class="mono">${dec(String(Math.round(x)))}%</b></div>`).join('')}${xh.net ? `<div><span>Net</span><span class="mds-net wide">↓ <b>${mdsMbit(xh.net.rx)}</b> Mb/s · ↑ <b>${mdsMbit(xh.net.tx)}</b> Mb/s${xh.net.today !== null ? ` · ${L('vandaag')} <b>${fmtBytes(xh.net.today)}</b>` : ''}</span></div>` : ''}</div>` : ''}
    ${!met.length && xh.ping ? mdsPingBlock(xh.ping) : ''}
    <h4 class="mds-h4">${probs.length ? L('Vraagt aandacht') + ` · ${probs.length}` : '✔ ' + L('Alle checks in orde')}</h4>
    ${probs.map((c) => `<button type="button" class="mds-li s${+c.status}" data-mds="open" data-id="${c.id}"><i class="mds-dot"></i><span><b>${esc(c.name)}</b><small>${esc(String(c.last_message || '').slice(0, 80))}</small></span><span class="mds-dur">›</span></button>`).join('')}
    ${user ? `<div class="mds-bigs">${btnHost('hmaint', '🔧', L('Onderhoud 1 uur'), h.id)}${btnHost('hquiet', '🌙', L('Stil 1 uur'), h.id)}</div>` : ''}
    <button type="button" class="mds-link" data-mds="hostpage" data-id="${h.id}">${L('Op Monitoring openen')} ›</button>`);
}
/** 1.196.0: "Meer" in de tabbalk op een telefoon: de overige pagina's als grote tegels in een blad van onderaf */
function moreSheet() {
  const tiles = MORE_TABS.map((t) => $(`#main-tabs .tab[data-tab="${t}"]`)).filter(Boolean).map((b) => {
    const t = b.dataset.tab, badge = $('.tab-badge', b);
    const n = badge && !badge.hidden ? badge.textContent.trim() : '';
    const ic = $('.tab-ic', b);
    const lbl = ($('.tab-lbl', b) || b).textContent.trim();
    return `<button type="button" class="mds-more-t${t === currentTab() ? ' on' : ''}" data-mds="tab" data-tab="${esc(t)}">${ic ? ic.outerHTML : ''}<span>${esc(lbl)}</span>${n ? `<b class="mds-more-n${badge.classList.contains('s1') ? ' s1' : ''}">${esc(n)}</b>` : ''}</button>`;
  }).join('');
  mdsSheet(`<h4 class="mds-h4">${L('Meer')}</h4><div class="mds-more-g">${tiles}</div>`);
}
const currentTab = () => { const a = $('#main-tabs .tab.active[data-tab]'); return a ? a.dataset.tab : ''; };
function moreBadge() {
  const b = $('#more-badge');
  if (!b) return;
  let n = 0, crit = false;
  MORE_TABS.forEach((t) => { const x = $(`#main-tabs .tab[data-tab="${t}"] .tab-badge`); if (x && !x.hidden && +x.textContent) { n += +x.textContent; if (!x.classList.contains('s1')) crit = true; } });
  b.hidden = !n;
  b.textContent = n > 99 ? '99+' : String(n);
  b.classList.toggle('s1', !crit);
}
document.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('#tab-more')) moreSheet(); });
setInterval(() => { if (MDS_MQ.matches && !document.hidden) moreBadge(); }, 3000);
const btnHost = (act, ic, lbl, id) => `<button type="button" class="mds-big" data-mds="${act}" data-id="${id}">${ic}<span>${lbl}</span></button>`;
document.addEventListener('click', async (e) => {
  const b = e.target.closest && e.target.closest('#mds [data-mds], #mds-sheet [data-mds]');
  if (!b) return;
  const id = +b.dataset.id, act = b.dataset.mds;
  if (act === 'close') return mdsSheetClose();
  if (act === 'open') return mdsCheckSheet(id);
  if (act === 'host') return mdsHostSheet(id);
  if (act === 'all') { mdsAll = !mdsAll; return renderMds(); }
  if (act === 'grp') { mdsGroup = b.dataset.g || ''; uiSet('mds_group', 'rzmon-mds-group', mdsGroup); return renderMds(); }   // 1.220.0
  if (act === 'full') { mdsSheetClose(); return checkInfo(id); }
  if (act === 'tab') { mdsSheetClose(); return showTab(b.dataset.tab); }
  if (act === 'hostpage') { mdsSheetClose(); showTab('dashboard'); setTimeout(() => { const t = $(`[data-hid="${id}"]`); if (t) t.scrollIntoView({ block: 'start', behavior: 'smooth' }); }, 300); return; }
  b.disabled = true;
  b.classList.add('busy');
  try {
    if (act === 'ack') { await api('ack', { id, comment: '' }); toast('✔ ' + L('Bevestigd')); }
    if (act === 'maint') { await api('maintenance_set', { scope: 'check', id, minutes: 60 }); toast('🔧 ' + L('Onderhoud 1 uur')); }
    if (act === 'quiet') { await api('quiet_now', { what: 'check', id, minutes: 60 }); toast('🌙 ' + L('Stil tot {t}', { t: mdsTime(Date.now() + 3600000) })); }
    if (act === 'hmaint') { await api('maintenance_set', { scope: 'host', id, minutes: 60 }); toast('🔧 ' + L('Onderhoud 1 uur')); }
    if (act === 'hquiet') { await api('quiet_now', { what: 'host', id, minutes: 60 }); toast('🌙 ' + L('Stil tot {t}', { t: mdsTime(Date.now() + 3600000) })); }
    if (act === 'run') { const r = await api('check_run', { id }); toast(`${STATUS[r.result.status]}: ${r.result.message || ''}`, +r.result.status === 0 ? 'ok' : 'warn'); }
    mdsBuzz();
    mdsSheetClose();
    await refresh();
  } catch (ex) { toast(ex.message, 'err'); b.disabled = false; b.classList.remove('busy'); }
});
// Trekken om te verversen (alleen op mDS, alleen als de pagina bovenaan staat)
(() => {
  let y0 = null, dy = 0, busy = false;
  const ind = () => { let i = $('#mds-ptr'); if (!i) { i = document.createElement('div'); i.id = 'mds-ptr'; i.className = 'mds-ptr'; i.innerHTML = '<i>↻</i>'; document.body.append(i); } return i; };
  document.addEventListener('touchstart', (e) => {
    if (busy || $('#tab-mds').hidden || document.body.classList.contains('mds-sheet-on') || (window.scrollY || document.documentElement.scrollTop) > 0) { y0 = null; return; }
    y0 = e.touches[0].clientY; dy = 0;
  }, { passive: true });
  document.addEventListener('touchmove', (e) => {
    if (y0 === null) return;
    dy = Math.max(0, e.touches[0].clientY - y0);
    const i = ind(), d = Math.min(90, dy * 0.5);
    i.style.transform = `translate(-50%, ${d - 40}px) rotate(${dy * 2}deg)`;
    i.classList.toggle('ready', dy > 110);
    i.classList.toggle('on', dy > 8);
  }, { passive: true });
  document.addEventListener('touchend', async () => {
    if (y0 === null) return;
    const i = ind(), go = dy > 110;
    y0 = null;
    if (!go) { i.classList.remove('on', 'ready'); i.style.transform = ''; return; }
    busy = true; i.classList.add('spin'); mdsBuzz();
    try { await Promise.all([refresh(), loadMds()]); } finally { busy = false; i.classList.remove('on', 'ready', 'spin'); i.style.transform = ''; }
  });
})();

function initNetPaths() {
  $('#netpaths').addEventListener('click', (e) => {
    const r = e.target.closest('[data-cid]');
    if (r) checkInfo(+r.dataset.cid);
  });
  $('#netpaths').addEventListener('keydown', (e) => {
    const r = e.target.closest('[data-cid]');
    if (r && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); checkInfo(+r.dataset.cid); }
  });
}

// ============ 1.171.0: AI-advies (staat AI uit, dan is state.ai null en is er nergens iets van te zien) ============
const aiOn = () => !!(state && state.ai);
const aiHas = (kind, ref) => aiOn() && !!(state.ai.have || {})[`${kind}:${ref}`];
/** Knop voor AI-advies: alleen als AI aan staat, en voor wie mag vragen (of als er al een advies is om te lezen) */
function aiBtn(kind, ref, big = false) {
  if (!aiOn()) return '';
  const has = aiHas(kind, ref);
  if (!state.ai.ask && !has) return '';
  return big
    ? `<button type="button" class="btn small-btn ai-btn${has ? ' has' : ''}" data-ai-kind="${kind}" data-ai-ref="${ref}">🤖 ${has ? L('AI-advies') : L('Vraag AI-advies')}</button>`
    : `<button type="button" class="icon-btn ai-btn${has ? ' has' : ''}" data-ai-kind="${kind}" data-ai-ref="${ref}" title="${esc(has ? L('AI-advies bekijken') : L('AI-advies vragen'))}">🤖</button>`;
}

/** 1.173.0: bedragen (euro) en tokens */
const aiMoney = (v) => { const n = +v || 0, d = n && Math.abs(n) < 0.01 ? 4 : 2; return '€ ' + n.toLocaleString(LOCALE, { minimumFractionDigits: d, maximumFractionDigits: d }); };
const aiTok = (n) => (+n || 0).toLocaleString(LOCALE);
const aiDay = (d) => new Date(String(d).slice(0, 10) + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });
/** 1.173.0: oordeel van de AI bij een IDS-incident (alleen een label) */
const AI_VERDICT = { benign: ['ok', () => L('lijkt onschuldig')], suspect: ['warn', () => L('verdacht')], real: ['bad', () => L('lijkt echt')] };
/** 1.223.0: waar een oordeel vandaan komt: jij, een regel, een eerder oordeel over dezelfde soort, of de AI */
const TRI_SRC = { u: ['👤', () => L('Jouw oordeel over deze soort')], r: ['⚙️', () => L('Regel van RzSense (zonder AI)')], h: ['♻️', () => L('Eerder oordeel over dezelfde soort (geen nieuwe AI-vraag)')], a: ['🤖', () => L('Oordeel van de AI')] };
function aiTriage(id) {
  const t = aiOn() && state.ai.triage && state.ai.triage[id];
  if (!t || !AI_VERDICT[t[0]]) return '';
  const [cls, lbl] = AI_VERDICT[t[0]];
  const [ic, src] = TRI_SRC[t[2]] || TRI_SRC.a;
  return `<button type="button" class="ai-verdict ${cls}" data-ai-kind="triage" data-ai-ref="${id}" title="${esc(src() + ' · ' + L('alleen een label, er wordt niets afgehandeld') + (t[1] ? ': ' + t[1] : ''))}">${ic} ${esc(lbl())}</button>`;
}
/** In het incidentvenster: het oordeel zelf aanpassen (geldt voortaan voor deze soort) */
function triageEdit(id) {
  if (!aiOn() || !state.ai.triage) return '';
  const cur = (state.ai.triage[id] || [])[0];
  return `<div class="tri-edit need-admin"><span class="muted small">🏷️ ${L('Jouw oordeel (geldt voortaan voor deze soort):')}</span>
    ${['real', 'suspect', 'benign'].map((v) => `<button type="button" class="ai-verdict ${AI_VERDICT[v][0]} ${cur === v ? 'on' : 'dim'}" data-tri-set="${v}">${esc(AI_VERDICT[v][1]())}</button>`).join('')}</div>`;
}

/** Eenvoudige, veilige opmaak van het antwoord: kopjes, lijsten, vet, code en codeblokken met een kopieerknop */
function aiMd(src) {
  return String(src || '').split(/```[A-Za-z0-9_+-]*[ \t]*\n?/).map((part, i) => {
    if (i % 2) return `<div class="ai-code"><pre><code>${esc(part.replace(/\n$/, ''))}</code></pre><button type="button" class="ai-copy" data-ai-copy title="${esc(L('Kopiëren'))}">⧉</button></div>`;
    return esc(part).split('\n').map((line) => {
      const l = line.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|[\s(])\*([^*\s][^*]*)\*(?=[\s.,;:)!?]|$)/g, '$1<i>$2</i>');
      if (/^\s*#{1,4}\s/.test(line)) return `<h4>${l.replace(/^\s*#{1,4}\s*/, '')}</h4>`;
      if (/^\s*\d+[.)]\s+/.test(line)) return `<div class="ai-li num">${l.trim()}</div>`;
      if (/^\s*[-*•]\s+/.test(line)) return `<div class="ai-li">• ${l.replace(/^\s*[-*•]\s+/, '')}</div>`;
      return l.trim() ? `<p>${l}</p>` : '';
    }).join('');
  }).join('');
}

const AI_ACT = (a) => (a === 'restart' ? L('Herstarten') : L('Herstelscript {n}', { n: a.slice(4) }));
let aiCur = null;
function aiTitle(kind, ref) {
  if (kind === 'check') { const [c, h] = findCheck(ref); return c ? `${h ? h.name + ' – ' : ''}${c.name}` : '#' + ref; }
  if (kind === 'bundle') { const h = findHost(ref); return h ? h.name : '#' + ref; }
  if (kind === 'week') return L('Weekoverzicht') + ' ' + L('week {w}', { w: String(ref).slice(4) });
  if (kind === 'triage') return L('Oordeel') + ' · ' + L('IDS-incident') + ' #' + ref;
  if (kind === 'report') return L('Verslag') + ' · ' + L('Incident') + ' #' + ref;
  if (kind === 'cve') { const e = vulnData && vulnData.buzz ? Object.entries(vulnData.buzz).find(([, v]) => v.id === +ref) : null; return L('Raakt dit jou echt?') + (e ? ' · ' + e[0] : ''); }
  return (kind === 'ids' ? L('IDS-incident') : L('Incident')) + ' #' + ref;
}
async function aiOpen(kind, ref) {
  aiCur = { kind, ref, ran: false };
  const cur = aiCur;
  modal({ title: '🤖 ' + L('AI-advies') + ' – ' + aiTitle(kind, ref), wide: true, body: `<div class="ai-box" id="ai-box"><p class="muted">${L('laden…')}</p></div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }] });
  let r;
  try { r = await api('ai_get', null, { kind, ref }); } catch (ex) { r = { error: ex.message }; }
  if (cur === aiCur) aiShow(r.advice || null, r.error);
}
async function aiAsk(force) {
  const cur = aiCur;
  aiShow({ status: 'pending', provider_label: '', model: '' });
  try {
    const r = await api('ai_ask', { kind: cur.kind, ref: cur.ref, force: force ? 1 : 0 });
    if (cur === aiCur) aiShow(r.advice);
  } catch (ex) {
    if (cur !== aiCur) return;
    // Een te lange vraag kan een time-out van de webserver geven terwijl RzSense doorgaat: blijven kijken
    if (/504|502|time|Ongeldig antwoord/i.test(ex.message)) aiPoll(cur, null); else aiShow(null, ex.message);
  }
}
function aiPoll(cur, id) {
  setTimeout(async () => {
    if (cur !== aiCur || !$('#modal').open || !$('#ai-box')) return;
    try {
      const r = await api('ai_get', null, id ? { id } : { kind: cur.kind, ref: cur.ref });
      if (cur === aiCur) aiShow(r.advice);
    } catch (ex) { aiPoll(cur, id); }
  }, 3000);
}
function aiShow(a, err = '') {
  const box = $('#ai-box');
  if (!box || !aiCur) return;
  const cur = aiCur;
  const canAsk = state.ai && state.ai.ask;
  const busy = a && ['pending', 'diag', 'running'].includes(a.status);
  const tps = a && a.provider === 'ollama' && a.tok_out && a.ms ? ' · ' + L('{n} tokens/s', { n: (a.tok_out / (a.ms / 1000)).toLocaleString(LOCALE, { maximumFractionDigits: 1 }) }) : '';
  const meta = a && a.id ? [a.provider_label + (a.model ? ' · ' + a.model : ''), a.created_at ? whenText(a.created_at) : '', a.auto ? L('automatisch') : (a.created_by || ''),
    a.ms ? (a.ms / 1000).toLocaleString(LOCALE, { maximumFractionDigits: 1 }) + ' s' + tps : '', a.has_diag ? '🔎 ' + L('met diagnose') : '',
    a.tok_in != null || a.tok_out != null ? L('{i} + {o} tokens', { i: aiTok(a.tok_in), o: aiTok(a.tok_out) }) : '',
    a.status === 'done' ? (a.cost != null ? (a.cloud ? '± ' + aiMoney(a.cost) : L('gratis')) : (a.cloud ? L('prijs onbekend') : '')) : ''].filter(Boolean).join(' · ') : '';
  let html = '';
  if (err) html += `<p class="form-error">${esc(err)}</p>`;
  if (!a && !err) html += `<p>${L('Er is nog geen advies.')}</p>`;
  if (a && a.status === 'diag') html += `<p class="ai-wait"><span class="ai-spin"></span> 🔎 ${L('De agent verzamelt gegevens over de storing (meestal 1–2 minuten)…')}</p>`;
  if (a && (a.status === 'pending' || a.status === 'running')) html += `<p class="ai-wait"><span class="ai-spin"></span> 🤖 ${esc(a.provider_label || L('De AI'))} ${L('denkt na…')}</p>`;
  if (a && a.status === 'error') html += `<p class="form-error">✘ ${esc(a.error || L('Onbekende fout'))}</p>${a.billing ? `<p><a class="btn small-btn" href="${esc(a.billing)}" target="_blank" rel="noopener noreferrer">💳 ${L('Opwaarderen bij {p}', { p: esc(a.provider_label) })} ↗</a></p>` : ''}`;
  if (a && a.note) html += `<p class="muted small">ℹ️ ${esc(a.note)}</p>`;
  if (a && a.status === 'done') {
    if (cur.kind === 'triage' && a.verdict && AI_VERDICT[a.verdict]) html += `<p><span class="ai-verdict ${AI_VERDICT[a.verdict][0]}">🤖 ${esc(AI_VERDICT[a.verdict][1]())}</span> <span class="muted small">${L('Alleen een label: er wordt niets afgehandeld.')}</span></p>`;
    html += `<div class="ai-answer">${aiMd(a.answer)}</div>`;
    if ((a.actions || []).length && state.ai.exec) {
      html += `<div class="ai-exec"><b>🩹 ${L('Voorgestelde herstelactie')}</b> ${a.actions.map((x) => `<button type="button" class="btn small-btn" data-ai-exec="${esc(x)}">▶ ${esc(AI_ACT(x))}</button>`).join(' ')}
        <span class="muted small">${L('Gaat via zelf herstellen bij de volgende ronde van de agent; een service wordt alleen herstart als hij echt stil ligt.')}</span></div>`;
    } else if ((a.actions || []).length) {
      html += `<p class="muted small">🩹 ${L('Voorgestelde herstelactie')}: ${esc(a.actions.map(AI_ACT).join(', '))} (${L('uitvoeren staat uit: alleen tekst')})</p>`;
    }
  }
  if (a && a.status === 'done' && a.id && canAsk && !['week', 'triage'].includes(cur.kind)) {
    // 1.173.0: geheugen: hielp het? (gaat bij dezelfde storing mee naar de AI)
    html += `<div class="ai-fb"><span class="muted small">${L('Hielp dit?')}</span>
      <button type="button" class="btn small-btn ${a.rating === 1 ? 'on' : ''}" data-ai-fb="rating" data-v="${a.rating === 1 ? 0 : 1}" title="${esc(L('Hielp'))}">👍</button>
      <button type="button" class="btn small-btn ${a.rating === -1 ? 'on' : ''}" data-ai-fb="rating" data-v="${a.rating === -1 ? 0 : -1}" title="${esc(L('Hielp niet'))}">👎</button>
      <button type="button" class="btn small-btn ${a.solved ? 'on' : ''}" data-ai-fb="solved" data-v="${a.solved ? 0 : 1}">✔ ${L('Dit loste het op')}</button></div>`;
  }
  if (meta) html += `<p class="muted small ai-meta">${esc(meta)}</p>`;
  html += `<div class="btn-row">${canAsk && !busy ? `<button type="button" class="btn ${a ? '' : 'primary'}" data-ai-ask="${a ? 1 : 0}">${a ? '🔄 ' + L('Opnieuw vragen') : '🤖 ' + L('Vraag advies')}</button>` : ''}</div>
    <p class="muted small ai-note">⚠️ ${L('AI kan fouten maken. Lees een commando goed voordat je het uitvoert.')}</p>`;
  box.innerHTML = html;
  if (a && a.status === 'done' && a.id) {
    state.ai.have = { ...(state.ai.have || {}), [`${cur.kind}:${cur.ref}`]: a.id };
    render();
  }
  if (busy) {
    if (a.status === 'pending' && a.has_diag && !cur.ran && a.id) {
      cur.ran = true;   // de diagnose is binnen: nu het model vragen
      api('ai_run', { id: a.id }).then((r) => { if (cur === aiCur) aiShow(r.advice); }).catch(() => aiPoll(cur, a.id));
    }
    aiPoll(cur, a.id || null);
  }
}
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-ai-kind]');
  if (b) { e.preventDefault(); e.stopPropagation(); aiOpen(b.dataset.aiKind, +b.dataset.aiRef); return; }
  const ask = e.target.closest('[data-ai-ask]');
  if (ask && $('#ai-box') && $('#ai-box').contains(ask)) { e.preventDefault(); aiAsk(ask.dataset.aiAsk === '1'); return; }
  const fb = e.target.closest('[data-ai-fb]');
  if (fb && aiCur && $('#ai-box') && $('#ai-box').contains(fb)) {
    e.preventDefault();
    const cur = aiCur;
    try {
      const r0 = await api('ai_get', null, { kind: cur.kind, ref: cur.ref });
      if (!r0.advice) return;
      const r = await api('ai_feedback', { id: r0.advice.id, [fb.dataset.aiFb]: +fb.dataset.v });
      if (cur === aiCur) aiShow(r.advice);
      toast(L('Dank je, dat onthoudt RzSense'));
    } catch (err) { toast(err.message, 'err'); }
    return;
  }
  const cp = e.target.closest('[data-ai-copy]');
  if (cp) {
    e.preventDefault();
    try { await navigator.clipboard.writeText(cp.previousElementSibling.textContent); toast(L('Gekopieerd')); } catch (ex) { getSelection().selectAllChildren(cp.previousElementSibling); }
    return;
  }
  const ex = e.target.closest('[data-ai-exec]');
  if (ex && aiCur) {
    e.preventDefault();
    const cur = aiCur;
    const r0 = await api('ai_get', null, { kind: cur.kind, ref: cur.ref }).catch(() => null);
    const id = r0 && r0.advice && r0.advice.id;
    if (!id || !window.confirm(L('{a} uitvoeren op de server? Dit komt in het logboek.', { a: AI_ACT(ex.dataset.aiExec) }))) return;
    try { const r = await api('ai_exec', { id, action: ex.dataset.aiExec }); toast(r.message); } catch (err) { toast(err.message, 'err'); }
  }
}, true);

// ---- Instellingen → Koppelingen → AI-advies
function aiFormVals() {
  const f = $('#settings-form');
  const out = {};
  [...f.elements].forEach((el) => { if (el.name && el.name.startsWith('ai_')) out[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value; });
  return out;
}
function aiProvUi() {
  const f = $('#settings-form');
  const p = f.elements.ai_provider ? f.elements.ai_provider.value : 'ollama';
  // 1.173.0: het adres en model van Ollama ook tonen bij een cloudaanbieder als terugvallen op Ollama aan staat
  const fb = p !== 'ollama' && f.elements.ai_fallback && f.elements.ai_fallback.checked;
  $$('#ai-card [data-prov]').forEach((el) => { el.hidden = el.dataset.prov !== p && !(fb && el.dataset.prov === 'ollama'); });
  $$('#ai-card [data-fb-note]').forEach((el) => { el.hidden = !fb; });
  const on = f.elements.ai_on && f.elements.ai_on.checked;
  $('#ai-body').classList.toggle('ai-off', !on);
  const cloud = p !== 'ollama';
  $('#ai-mask-wrap').hidden = !cloud;
  $('#ai-daily-wrap').hidden = !cloud;
}
async function loadAiSettings() {
  aiProvUi();
  let r;
  try { r = await api('ai_info'); } catch (ex) { $('#ai-usage').textContent = ex.message; return; }
  aiProvUi();   // 1.173.0: de waarden van het formulier zijn er nu zeker
  $('#ai-diag').checked = r.diag;
  $('#ai-exec').checked = r.exec;
  $('#ai-usage').innerHTML = r.on ? L('Vandaag {n} vragen naar een cloudmodel.', { n: r.used }) : '';
  // 1.223.0: wat de slimme oordelen vandaag deden (AI alleen voor wat nog onzeker was)
  const tr = r.triage;
  if ($('#ai-triage-stats')) $('#ai-triage-stats').innerHTML = tr ? `🏷️ ${L('Oordelen vandaag: {a} AI-vragen (van max. {m}), {h} hergebruikt, {r} via regels, {u} van jou · {k} soorten onthouden', { a: tr.ai, m: tr.max, h: tr.by.h, r: tr.by.r, u: tr.by.u, k: tr.kinds })}` : '';
  aiWeek = r.week_last || r.week_ref;
  aiCostRender(r.usage);
  $('#ai-recent').innerHTML = r.recent.length ? `<ul class="ai-recent">${r.recent.map((a) => `<li><span class="ai-st ${a.status}">${esc({ done: '✔', error: '✘', diag: '🔎', pending: '…', running: '…' }[a.status] || a.status)}</span>
      ${esc(whenText(a.created_at))} · ${esc(a.kind)} #${a.ref_id} · ${esc(a.provider_label)}${a.model ? ' · ' + esc(a.model) : ''}${a.auto ? ' · ' + L('automatisch') : ''}${a.status === 'done' && a.cost != null && a.cloud ? ' · ± ' + aiMoney(a.cost) : ''}${a.note ? ' · ' + esc(a.note) : ''}${a.error ? ` · <span class="bad">${esc(a.error)}</span>` : ''}</li>`).join('')}</ul>` : `<p class="muted small">${L('Nog geen adviezen gevraagd.')}</p>`;
}
let aiWeek = null, aiUsage = null;
/** 1.173.0: verbruik en tegoed: kerncijfers, kosten per dag, prijzen per model en het bijgehouden tegoed */
function aiCostRender(u) {
  if (!u || !$('#ai-cost-kpi')) return;
  aiUsage = u;
  $('#ai-credit-out').innerHTML = (u.credit_out || []).map((f) => `<p class="ai-out">💳 <b>${L('Tegoed op bij {p}', { p: esc(f.label) })}</b> <span class="muted small">${f.at ? esc(whenText(f.at)) : ''}</span>
    <a class="btn small-btn" href="${esc(f.url)}" target="_blank" rel="noopener noreferrer">${L('Opwaarderen')} ↗</a></p>`).join('');
  const b = +u.budget || 0, m = u.month, pct = b > 0 ? Math.min(100, (m.cost / b) * 100) : 0;
  const credits = Object.entries(u.credits || {});
  const kpi = (label, big, sub, bar = null, cls = '') => `<div class="ai-kpi ${cls}"><span class="muted small">${label}</span><b>${big}</b><span class="muted small">${sub}</span>${bar !== null ? `<i class="ai-bar"><i style="width:${bar.toFixed(0)}%"></i></i>` : ''}</div>`;
  $('#ai-cost-kpi').innerHTML = [
    kpi(L('Vandaag'), aiMoney(u.today.cost), L('{n} vragen · {t} tokens', { n: u.today.n, t: aiTok(u.today.tin + u.today.tout) })),
    kpi(L('Deze maand'), aiMoney(m.cost), (b > 0 ? L('van {b} budget', { b: aiMoney(b) }) + ' · ' : '') + L('{n} vragen · {t} tokens', { n: m.n, t: aiTok(m.tin + m.tout) }), b > 0 ? pct : null, pct >= 100 ? 'bad' : pct >= 80 ? 'warn' : ''),
    ...credits.map(([p, c]) => kpi(L('Tegoed {p}', { p: esc((u.models.find((x) => x.provider === p) || {}).label || p) }), '± ' + aiMoney(Math.max(0, c.left)),
      L('van {a} sinds {d}', { a: aiMoney(c.amount), d: aiDay(c.since) }), c.pct, c.pct <= 0 ? 'bad' : c.pct <= 20 ? 'warn' : '')),
  ].join('') + (m.unpriced ? `<p class="muted small ai-kpi-note">⚠️ ${L('{n} adviezen deze maand zonder prijs: vul hieronder de prijs per model in, dan rekent RzSense ze alsnog mee.', { n: m.unpriced })}</p>` : '');
  // Kosten per dag (30 dagen); zonder kosten (Ollama of geen prijzen): tokens per dag
  const byCost = u.days.some((d) => d.cost > 0), val = (d) => (byCost ? d.cost : d.tok), max = Math.max(...u.days.map(val), 0);
  $('#ai-cost-chart').innerHTML = max > 0 ? `<div class="ai-chart"><div class="ai-chart-h small"><b>${byCost ? L('Kosten per dag') : L('Tokens per dag')}</b> <span class="muted">${L('laatste 30 dagen')} · ${L('hoogste {v}', { v: byCost ? aiMoney(max) : aiTok(max) })}</span></div>
    <div class="ai-bars" role="img" aria-label="${esc(byCost ? L('Kosten per dag') : L('Tokens per dag'))}">${u.days.map((d) => `<i class="${val(d) ? '' : 'zero'}" style="height:${Math.max(val(d) ? 6 : 2, (val(d) / max) * 100).toFixed(0)}%" title="${esc(aiDay(d.d) + ': ' + (byCost ? aiMoney(d.cost) : aiTok(d.tok) + ' tokens') + ' · ' + L('{n} vragen', { n: d.n }))}"></i>`).join('')}</div>
    <div class="ai-chart-x small muted"><span>${esc(aiDay(u.days[0].d))}</span><span>${L('vandaag')}</span></div></div>` : '';
  // Prijzen per model (deze maand gebruikt of ingesteld) en het bijgehouden tegoed per aanbieder
  const cloud = u.models.filter((x) => x.cloud);
  const provs = [...new Set([...cloud.map((x) => x.provider), ...Object.keys(u.credit_cfg || {})])];
  const plabel = (p) => (u.models.find((x) => x.provider === p) || {}).label || p;
  const num = (v) => (v == null ? '' : String(v));
  $('#ai-cost-edit').innerHTML = cloud.length || provs.length ? `<div class="table-wrap"><table class="small ai-price-t"><thead><tr><th>${L('Model')}</th><th class="num">${L('Vragen')}</th><th class="num">${L('Tokens in / uit')}</th><th class="num">${L('Kosten')}</th>
      <th>${L('€ per 1 miljoen tokens in')}</th><th>${L('… uit')}</th></tr></thead><tbody>
      ${cloud.map((x) => `<tr><td>${esc(x.label)}<br><span class="mono muted">${esc(x.model)}</span></td><td class="num">${x.n}</td><td class="num">${aiTok(x.tin)} / ${aiTok(x.tout)}</td>
        <td class="num">${x.price ? aiMoney(x.cost) : '<span class="muted">–</span>'}</td>
        <td><input type="number" min="0" step="0.001" inputmode="decimal" data-price="${esc(x.provider + '|' + x.model)}" data-i="0" value="${esc(num(x.price && x.price[0]))}" placeholder="${esc(L('bv. 1'))}"></td>
        <td><input type="number" min="0" step="0.001" inputmode="decimal" data-price="${esc(x.provider + '|' + x.model)}" data-i="1" value="${esc(num(x.price && x.price[1]))}" placeholder="${esc(L('bv. 5'))}"></td></tr>`).join('')}</tbody></table></div>
    <p class="muted small">${L('De prijs staat op de website van de aanbieder (per miljoen tokens, vaak in dollars: reken om naar euro). Ollama is altijd gratis.')}</p>
    ${provs.length ? `<div class="table-wrap"><table class="small ai-price-t"><thead><tr><th>${L('Tegoed')}</th><th>${L('Opgewaardeerd (€)')}</th><th>${L('Sinds')}</th><th></th></tr></thead><tbody>
      ${provs.map((p) => { const c = (u.credit_cfg || {})[p] || {}; return `<tr><td>${esc(plabel(p))}</td>
        <td><input type="number" min="0" step="0.01" inputmode="decimal" data-credit="${esc(p)}" data-f="amount" value="${esc(num(c.amount))}" placeholder="${esc(L('leeg = niet bijhouden'))}"></td>
        <td><input type="date" data-credit="${esc(p)}" data-f="since" value="${esc(c.since || '')}"></td>
        <td>${u.billing[p] ? `<a href="${esc(u.billing[p])}" target="_blank" rel="noopener noreferrer" class="small">${L('naar de aanbieder')} ↗</a>` : ''}</td></tr>`; }).join('')}</tbody></table></div>` : ''}
    <div class="btn-row"><button type="button" class="btn small-btn primary need-admin" id="ai-cost-save">💾 ${L('Prijzen en tegoed opslaan')}</button></div>`
    : `<p class="muted small">${L('Kies een cloudaanbieder met een API-sleutel en een model; dan vul je hier de prijs en je tegoed in.')}</p>`;
}
function initAiSettings() {
  const card = $('#ai-card');
  if (!card) return;
  card.addEventListener('click', async (e) => {
    if (e.target.closest('#ai-week-open')) { e.preventDefault(); aiOpen('week', aiWeek || 0); return; }
    if (!e.target.closest('#ai-cost-save')) return;
    e.preventDefault();
    const prices = {}, credit = {};
    $$('#ai-cost-edit [data-price]').forEach((el) => { (prices[el.dataset.price] ??= ['', ''])[+el.dataset.i] = el.value.trim(); });
    Object.keys(prices).forEach((k) => { if (prices[k].some((v) => v === '')) delete prices[k]; });
    $$('#ai-cost-edit [data-credit]').forEach((el) => { (credit[el.dataset.credit] ??= {})[el.dataset.f] = el.value.trim(); });
    try { const r = await api('ai_cost_set', { prices, credit }); aiCostRender(r.usage); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); }
  });
  card.addEventListener('change', (e) => { if (['ai_provider', 'ai_on', 'ai_fallback'].includes(e.target.name)) aiProvUi(); });
  $('#ai-models').onclick = async (e) => {
    const b = e.currentTarget, p = $('#settings-form').elements.ai_provider.value;
    b.disabled = true;
    try {
      const r = await api('ai_models', aiFormVals());
      $(`#ai-ml-${p}`).innerHTML = r.models.map((m) => `<option value="${esc(m)}">`).join('');
      const inp = $('#settings-form').elements[`ai_${p}_model`];
      if (inp && !inp.value && (r.suggest || r.models[0])) { inp.value = r.suggest || r.models[0]; markSettingsDirty(true, inp); }
      toast(L('{n} modellen gevonden; kies er een in het veld Model', { n: r.models.length }) + (r.suggest ? ' · ' + L('voorstel: {m}', { m: r.suggest }) : ''));
      inp && inp.focus();
    } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  };
  $('#ai-test').onclick = async (e) => {
    const b = e.currentTarget, out = $('#ai-test-res');
    b.disabled = true;
    out.innerHTML = `<span class="ai-spin"></span> ${L('Even geduld…')}`;
    try { const r = await api('ai_test', aiFormVals()); out.innerHTML = `<b class="good">✔ ${L('Werkt')}</b> (${(r.ms / 1000).toLocaleString(LOCALE, { maximumFractionDigits: 1 })} s): ${esc(r.text)}`; }
    catch (ex) { out.innerHTML = `<b class="bad">✘ ${esc(ex.message)}</b>`; }
    b.disabled = false;
  };
  const guard = (id, key, label) => {
    $(id).onchange = (e) => {
      const el = e.currentTarget, on = el.checked;
      el.checked = !on;
      const go = async (confirm) => { await api('ai_guard_set', { key, on: on ? 1 : 0, confirm }); el.checked = on; toast(on ? L('Aangezet') : L('Uitgezet')); };
      if (!on) { go('').catch((ex) => toast(ex.message, 'err')); return; }
      modal({ title: '🤖 ' + label, body: `<p style="margin-top:0">${key === 'diag'
        ? L('Bij een CRITICAL op een server met de agent voert de agent een vaste lijst commando\'s uit die alleen lezen (status, logs, schijf, processen, poorten). De uitvoer gaat naar de AI, zonder wachtwoorden. Op een server met COMMANDS=0 gebeurt het nooit.')
        : L('Een advies mag dan een bestaande herstelactie voorstellen (herstarten of een herstelscript uit heal.d). Die gaat pas na jouw klik en bevestiging, via zelf herstellen, en komt in het logboek. Alleen voor beheerders.')}</p>${confirmField('TOESTAAN')}`,
        buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Aanzetten'), cls: 'primary', submit: true }],
        onSubmit: (d) => go(d.confirm) });
    };
  };
  guard('#ai-diag', 'diag', L('Diep zoeken via de agent'));
  guard('#ai-exec', 'exec', L('Uitvoeren vanuit een advies'));
}

function render() { withMorph(renderNow); crumbs(); sbStatus(); }
/** 1.218.1: de tellerbalk wordt dicht (met schaduw) zodra hij onder de kop blijft hangen */
let dcRaf = 0;
function dcStuck() {
  dcRaf = 0;
  $$('main.container .dash-counters').forEach((el) => {
    if (!el.offsetParent) return;
    const top = parseFloat(getComputedStyle(el).top) || 0;
    el.classList.toggle('stuck', el.getBoundingClientRect().top <= top + 1 && window.scrollY > 0);
  });
}
window.addEventListener('scroll', () => { if (!dcRaf) dcRaf = requestAnimationFrame(dcStuck); }, { passive: true });
/** 1.216.0: open problemen (warning/critical, niet bevestigd, niet in onderhoud), voor het statuslampje en de paginakop */
function openCounts() {
  const n = { crit: 0, warn: 0, checks: 0, hosts: 0 };
  (state.hosts || []).forEach((h) => {
    if (!+h.enabled) return;
    n.hosts++;
    h.checks.forEach((c) => {
      if (!+c.enabled) return;
      n.checks++;
      if (c.maintenance || c.acked_at) return;
      if (+c.status === 2) n.crit++; else if (+c.status === 1) n.warn++;
    });
  });
  return n;
}
/** 1.216.0: het levende statuslampje onder het logo: rood bij critical, oranje bij alleen warnings, groen als alles in orde is */
function sbStatus() {
  const el = $('#sb-status');
  if (!el || !state.hosts) return;
  const n = openCounts(), tot = n.crit + n.warn;
  el.hidden = false;
  el.className = 'sb-status ' + (n.crit ? 's2' : n.warn ? 's1' : 's0');
  $('.sb-t', el).textContent = tot ? (tot === 1 ? L('1 probleem') : L('{n} problemen', { n: tot })) : L('Alles in orde');
  el.dataset.n = tot || '';
  $('.sb-t', el).dataset.n = tot || '';
  el.title = tot ? L('{c} critical, {w} warning, nog niet bevestigd: klik voor Monitoring', { c: n.crit, w: n.warn }) : L('Alle checks zijn in orde');
}
document.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('#sb-status')) showTab('dashboard'); });
/** 1.216.0: tooltip bij de ingeklapte zijbalk (naam en teller van het menu-item) */
document.addEventListener('mouseover', (e) => {
  const t = e.target.closest && e.target.closest('.app-top .main-tabs .tab');
  let tip = $('#side-tip');
  if (!t || !document.body.classList.contains('side-mini')) { if (tip) tip.hidden = true; return; }
  if (!tip) { tip = document.createElement('div'); tip.id = 'side-tip'; tip.className = 'side-tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); }
  const lbl = crumbText($('.tab-lbl', t) || t), b = $('.tab-badge:not([hidden])', t);
  tip.textContent = lbl + (b && b.textContent ? ' · ' + b.textContent : '');
  const r = t.getBoundingClientRect(), side = $('.app-top').getBoundingClientRect();
  tip.style.left = Math.round(side.right + 10) + 'px';
  tip.style.top = Math.round(r.top + r.height / 2) + 'px';
  tip.hidden = false;
});
document.addEventListener('mouseout', (e) => { if (e.target.closest && e.target.closest('.app-top .main-tabs .tab') && !(e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('.app-top .main-tabs .tab'))) { const tip = $('#side-tip'); if (tip) tip.hidden = true; } });
function renderNow() {
  renderHealth();
  renderIsoBanner();
  if (monSub === 'avail') avLoad();   // 1.169.0
  if (monSub === 'cont') ctLoad();   // 1.176.0 (elke 30 s opnieuw)
  if (monSub === 'paths') npLoad();   // 1.177.0
  const banner = $('#maint-banner');
  banner.hidden = !state.maintenance;
  if (state.maintenance) {
    banner.innerHTML = `<span><b>🔧 ${L('Onderhoud actief')}</b> ${L('tot {t}', { t: esc(fmtUntil(state.maintenance.until)) })}${state.maintenance.reason ? ' – ' + esc(state.maintenance.reason) : ''}.
      ${L('Checks draaien door, maar er gaan geen meldingen uit.')}</span>
      <button class="btn need-user" data-maint-end="all">${L('Beëindigen')}</button>`;
  }
  $('#last-run').innerHTML = `<b>${L('Live')}</b>` + (state.last_run ? ' · ' + esc(L('laatste check')) + ' ' + agoSpan(state.last_run) : '');
  let open = 0;
  if (state.badges) open = state.badges.dashboard + state.badges.alerts;   // nieuwe problemen + nieuwe meldingen
  else state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && !c.maintenance && +c.status === 2 && !c.acked_at && open++));
  document.title = (open ? `(${open}) ` : '') + 'Rz//Sense AI';

  // Filterchips: alleen soorten die er zijn, met hun aantal
  const chips = DASH_FILTERS.map(([k, label, fn]) => [k, label(), state.hosts.filter(fn).length]).filter(([k, , n]) => k === 'all' || k === 'problems' || n);
  if (!chips.some(([k]) => k === dashFilter)) dashFilter = 'all';
  const probIn = (k) => k.startsWith('g:') && state.hosts.some((h) => +h.enabled && hostGroup(h) === k.slice(2) && h.checks.some((c) => isProblem(c) && !c.acked_at));
  $('#dash-chips').innerHTML = chips.map(([k, l, n]) => `<button type="button" class="dchip ${dashFilter === k ? 'on' : ''}" data-dfilter="${k}">${esc(l)} <b>${n}</b>${probIn(k) ? '<i class="dchip-dot"></i>' : ''}</button>`).join('');
  $('#dash-sort').value = dashSort;

  const f = $('#filter').value.trim().toLowerCase();
  const onlyProblems = dashFilter === 'problems';
  const narrowed = onlyProblems || statusFilter !== null;
  const keep = DASH_FILTERS.find((x) => x[0] === dashFilter)[2];
  const byName = (a, b) => a.name.localeCompare(b.name, LANG, { numeric: true, sensitivity: 'base' });
  // Filters (1.71.0): een server telt mee als hij zelf past; met een filter op checks alleen de checks die passen
  const fxOn = fxActive('dash');
  const fxChecks = DASH_CHECK_FX.some((k) => fxHas('dash', k));
  const hosts = state.hosts.filter(keep)
    .filter((h) => !fxOn || (h.checks.length ? h.checks.some((c) => fxPass('dash', { h, c })) : fxPass('dash', { h, c: null })))
    .map((h) => {
      let checks = h.checks;
      const hostMatch = !f || (h.name + ' ' + h.address).toLowerCase().includes(f);
      if (!hostMatch) checks = checks.filter((c) => (c.name + ' ' + c.target).toLowerCase().includes(f));
      if (onlyProblems) checks = checks.filter((c) => +c.status !== 0 && +c.enabled);
      if (statusFilter !== null) checks = checks.filter((c) => +h.enabled && +c.enabled && (statusFilter === 4 ? !!c.maintenance : !c.maintenance && +c.status === statusFilter));
      if (fxChecks) checks = checks.filter((c) => fxPass('dash', { h, c }));
      return { ...h, shown: checks, match: hostMatch, autoOpen: ((!!f && !hostMatch) || fxChecks) && checks.length > 0 };
    })
    .filter((h) => (h.match && !narrowed && !fxChecks) || h.shown.length)
    .sort((a, b) => (MINI_RES.some((m) => m[0] === dashSort) ? (hostMax(b, dashSort) ?? -1) - (hostMax(a, dashSort) ?? -1)
      : dashSort === 'name' ? 0 : STATUS_RANK[hostStatus(b)] - STATUS_RANK[hostStatus(a)]) || byName(a, b));

  fxRender('dash');
  const el = $('#hosts');
  el.dataset.motion = String(afMotion(null));   // 1.175.0: Beweging (Stil · Rustig · Vol) voor de CC-animaties
  if (!state.hosts.length) {
    el.innerHTML = `<div class="card empty">${L('Nog geen servers. Klik op <b>＋ Server</b> om te beginnen.')}</div>`;
    return;
  }
  if (!hosts.length) {
    el.innerHTML = `<div class="card empty">${statusFilter !== null ? L('Geen checks met status {s}.', { s: HEALTH_COUNTS.find((k) => k[0] === statusFilter)[2] })
      : onlyProblems ? '🎉 ' + L('Geen problemen gevonden!') : L('Niets gevonden.')}</div>`;
    return;
  }
  // Servers met problemen (of onderhoud, of nog zonder checks) als kaart of rij; servers waar alles OK is compact eronder
  const { layout, density } = viewPrefs();
  const okHost = (h) => +h.enabled && hostStatus(h) === 0 && !h.maintenance && !h.autoOpen;
  const head = (title, n) => `<div class="sec-h hosts-h"><h2>${title}</h2><span>${n}</span></div>`;
  const lines = layout === 'l1nes';
  if (layout === 'compact') { el.className = 'dash-hosts'; el.innerHTML = compactView(hosts); return; }
  const cc = isCC();   // 1.164.0: in CC compacte blokken die openklappen tot een tabel
  const list = (arr) => (cc ? arr.map(ccHostCard).join('') : lines ? (density === 'large' ? lineHeader() : '') + arr.map((h) => hostLine(h, density === 'large')).join('')
    : arr.map((h) => hostCard(h, density === 'small')).join(''));
  const cls = cc ? 'hosts hb-grid' : `hosts layout-${layout} density-${density}`;
  const block = (arr, grouped) => {
    // 1.168.0: drie groepen: open problemen (zoals bij Nu aandacht nodig), alles al bevestigd, en in orde
    const good = arr.filter(okHost), acked = arr.filter((h) => !okHost(h) && ackedOnly(h)), bad = arr.filter((h) => !okHost(h) && !ackedOnly(h));
    // 1.175.0: in CC één raster (aandacht, bevestigd, in orde); geen aparte secties meer
    if (cc) return `<div class="${cls} cc2-grid">${list([...bad, ...acked, ...good])}</div>`;
    return (bad.length ? (good.length || acked.length ? head(grouped ? L('Met open problemen') : L('Servers met open problemen'), bad.length) : '') + `<div class="${cls}">${list(bad)}</div>` : '')
      + (acked.length ? head('✔ ' + L('Bevestigd'), (acked.length === 1 ? L('1 apparaat') : L('{n} apparaten', { n: acked.length })) + ' · ' + L('je weet ervan; er is nu niets te doen')) + `<div class="${cls} acked-grp">${list(acked)}</div>` : '')
      + (good.length ? head('✔ ' + L('Alles in orde'), good.length === 1 ? L('1 apparaat') : L('{n} apparaten', { n: good.length }))
        + (cc || lines || density === 'large' ? `<div class="${cls}">${list(good)}</div>` : `<div class="ok-list density-${density}">${good.map(okRow).join('')}</div>`) : '');
  };
  // Bij Alle (of Problemen, Onderhoud): een blok per soort apparaat; met één soort gekozen alleen die
  const groups = Object.keys(GROUP_INFO).filter((g) => hosts.some((h) => hostGroup(h) === g));
  el.className = 'dash-hosts';
  el.innerHTML = dashGroup() || groups.length < 2 ? block(hosts, !!dashGroup() || groups[0] !== 'server') : groups.map((g) => {
    const arr = hosts.filter((h) => hostGroup(h) === g);
    const nBad = arr.filter((h) => !okHost(h) && !ackedOnly(h)).length, nAck = arr.filter((h) => !okHost(h) && ackedOnly(h)).length;   // 1.168.0
    return `<section class="grp-block"><div class="grp-h"><h2>${GROUP_INFO[g][0]} ${esc(GROUP_INFO[g][1]())}</h2>
      <span>${arr.length === 1 ? L('1 apparaat') : L('{n} apparaten', { n: arr.length })}${nBad ? ` · <b class="bad">${L('{n} met problemen', { n: nBad })}</b>` : ''}${nAck ? ` · ✔ ${L('{n} bevestigd', { n: nAck })}` : ''}</span></div>
      ${block(arr, true)}</section>`;
  }).join('');
  $('#toggle-all').textContent = state.hosts.length && state.hosts.every((h) => (isCC() ? ccOpen : openHosts).has(+h.id)) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

/** Compacte regel voor een server waar alles OK is (Blox); klik = uitklappen met alle checks */
function okRow(h) {
  const p = hostParts(h);
  const u = stats.uptime[h.id];
  const n = h.checks.filter((c) => +c.enabled).length;
  return `<article class="ok-item ${p.isOpen ? 'open' : ''}">
    <div class="ok-row" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}" title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="ok-ic">${hostIcon(h)}</span>
      <span class="ok-nm"><strong>${esc(h.name)}</strong><small>${esc(h.address)}${p.via ? ' · ' + p.via : ''}</small></span>
      <span class="ok-st">● ${n === 1 ? L('1 check OK') : L('{n} checks OK', { n })}</span>
      <span class="ok-mets">${hostSparks(h, true)}</span>
      <span class="ok-up" title="${L('Bereikbaar afgelopen 24 uur')}">${u && u.h24 !== null ? pct(u.h24) : ''}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
}

/** Mini-grafiekjes van CPU, RAM en de volste disk over 24 uur (uit de minigrafieken van de checks) */
function hostSparks(h, mini = false) {
  const pick = (type) => h.checks.filter((c) => c.type === type && +c.enabled && c.last_value !== null).sort((a, b) => b.last_value - a.last_value)[0];
  const items = [['cpu', 'CPU'], ['ram', 'RAM'], ['disk', 'Disk']].map(([t, l]) => [l, pick(t)]).filter(([, c]) => c);
  if (!items.length) {
    // Zonder CPU/RAM/disk: de eerste check met een getal (bv. ping)
    const c = h.checks.find((x) => +x.enabled && x.last_value !== null && stats.sparks[x.id]);
    if (c) items.push([c.name, c]);
  }
  return items.map(([l, c]) => {
    const v = c.last_value === null ? '–' : typeOf(c).unit === '%' ? Math.round(+c.last_value) + '%' : dec(fmt(c.last_value, typeOf(c).unit));
    return `<span class="hs s${c.maintenance ? 4 : c.status}" title="${esc(c.name)}: ${esc(c.last_message || '')}">
      <span class="hs-l">${esc(l)} <b>${v}</b></span>${miniSpark(c, mini ? 16 : 24)}</span>`;
  }).join('');
}

// ---------------------------------------------------------------- Lay-out CC (1.158.0)
// Vier lay-outs, per account: Blox, L1nes en Compact (klassiek dashboard) en CC: alles in de stijl van het Command center,
// met kaarten die je kunt slepen, smaller of breder maken en verbergen (✎ Indeling, per pagina en per account).
const LAYOUTS = ['blox', 'l1nes', 'compact', 'cc'];
function layoutPref() {
  if (LAYOUTS.includes(PREFS.layout)) return PREFS.layout;
  let l = null;
  try { l = localStorage.getItem('rzmon-layout'); } catch (e) { /* geen opslag */ }
  if (LAYOUTS.includes(l) && l !== 'cc') return PREFS.dash === 'cc' ? 'cc' : l;
  return PREFS.dash === 'cc' ? 'cc' : 'blox';   // wie het Command center al gebruikte, krijgt CC
}
const isCC = () => layoutPref() === 'cc';
let pgEdit = null;   // pagina waarvan de indeling open staat
const PG_W = { 3: '¼', 4: '⅓', 6: '½', 9: '¾', 12: '1' };
/** Sleutel voor de opslag: de instellingen per onderdeel (bv. set_ids) */
const pgKey = (page) => (page === 'set' ? 'set_' + String(setSection || 'overview').replace(/[^a-z0-9_]/g, '') : page);
/** Sleutel van een kaart: data-pk (eigen kaart), data-sk (kaart van de instellingen) of het id */
const pgCardKey = (c) => (c.dataset.pk || c.dataset.sk || (c.id ? c.id.replace(/[^a-z0-9_]/gi, '_').toLowerCase() : ''));
function pgLay(page) {
  const l = (PREFS.pages || {})[pgKey(page)] || {};
  return { order: Array.isArray(l.order) ? l.order : [], hide: Array.isArray(l.hide) ? l.hide : [], w: l.w && typeof l.w === 'object' ? l.w : {} };
}
function pgSave(page, lay) { savePref('pages', { ...(PREFS.pages || {}), [pgKey(page)]: lay }); }
/** Volgorde, breedte en verbergen toepassen (met CSS order: de kaarten zelf blijven staan, dus live bijwerken gaat gewoon door) */
function pgApply(page) {
  document.querySelectorAll(`[data-pgrid="${page}"]`).forEach((grid) => pgApplyGrid(grid, page));
}
function pgApplyGrid(grid, page) {
  const lay = pgLay(page), cc = isCC(), edit = cc && pgEdit === page;
  const cards = [...grid.children].filter((c) => pgCardKey(c) && !c.classList.contains('pg-tools'));
  const keys = cards.map(pgCardKey);
  const order = [...lay.order.filter((k) => keys.includes(k)), ...keys.filter((k) => !lay.order.includes(k))];
  grid.classList.toggle('pg-editing', edit);
  cards.forEach((c) => {
    const k = pgCardKey(c);
    c.classList.toggle('pgk', cc);
    const w = +(lay.w[k] || c.dataset.pw || 12);
    c.style.order = cc ? String(order.indexOf(k)) : '';
    c.style.setProperty('--pw', String(w));
    c.classList.toggle('pg-hidden', cc && lay.hide.includes(k));
    c.draggable = edit;
    let bar = c.querySelector(':scope > .pg-bar');
    if (!edit) { if (bar) bar.remove(); return; }
    if (!bar) { bar = document.createElement('div'); bar.className = 'pg-bar'; c.prepend(bar); }
    const title = c.dataset.pt || ($(':scope > h2, :scope > h3, :scope > summary, :scope > .card-head h2', c) || {}).textContent || k;
    bar.innerHTML = `<span class="pg-grip" aria-hidden="true">⋮⋮</span><b>${esc(String(title).trim().slice(0, 60))}</b>
      ${Object.entries(PG_W).map(([x, t]) => `<button type="button" class="pg-w ${+x === w ? 'on' : ''}" data-pgw="${x}" title="${esc(L('Breedte'))}">${t}</button>`).join('')}
      <button type="button" class="pg-eye ${lay.hide.includes(k) ? 'off' : ''}" data-pghide title="${esc(lay.hide.includes(k) ? L('Tonen') : L('Verbergen'))}">${lay.hide.includes(k) ? '🚫' : '👁'}</button>`;
  });
}
/** Knoppen rechtsboven op een pagina in CC: live, ✎ Indeling, ▣ TV-modus */
function pgTools(page) {
  const el = document.querySelector(`[data-pgtools="${page}"]`);
  if (!el) return;
  if (!isCC()) { el.innerHTML = ''; return; }
  const ed = pgEdit === page, tv = document.body.classList.contains('tv');
  const dlg = page === 'am';   // de Agent Manager is een venster: geen Live en geen TV-modus
  el.innerHTML = `${page !== 'mon' && !dlg ? livePill() : ''}
    <button type="button" class="cc-tbtn ${ed ? 'on' : ''}" data-pgedit="${page}" aria-pressed="${ed}">✎ ${esc(ed ? L('Klaar') : L('Indeling'))}</button>
    ${dlg ? '' : `<button type="button" class="cc-tbtn ${tv ? 'on' : ''}" data-pgtv aria-pressed="${tv}">▣ ${esc(L('TV-modus'))}</button>`}
    ${ed ? `<div class="cc-editbar pg-editbar">${esc(L('Sleep een kaart aan ⋮⋮, kies de breedte en verberg wat je niet nodig hebt. Dit geldt voor jouw account.'))}
      <button type="button" class="btn small-btn" data-pgreset="${page}">↺ ${esc(L('Standaard'))}</button></div>` : ''}`;
}
const PG_PAGES = ['mon', 'alerts', 'ids', 'inc', 'vuln', 'net', 'an', 'set', 'acct', 'am'];   // 1.159.0: IDS t/m Analyse; 1.160.0: instellingen (per onderdeel), Mijn account en Agent Manager
function applyLayout() {
  document.body.classList.toggle('lay-cc', isCC());
  PG_PAGES.forEach((p) => { pgApply(p); pgTools(p); });
}
function initPageGrid() {
  let drag = null;
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-pgedit], [data-pgreset], [data-pgw], [data-pghide], [data-pgtv]');
    if (!t) return;
    if (t.dataset.pgtv !== undefined) { ccTv(!document.body.classList.contains('tv')); PG_PAGES.forEach(pgTools); return; }
    if (t.dataset.pgedit) { pgEdit = pgEdit === t.dataset.pgedit ? null : t.dataset.pgedit; pgApply(t.dataset.pgedit); pgTools(t.dataset.pgedit); return; }
    if (t.dataset.pgreset) { pgSave(t.dataset.pgreset, { order: [], hide: [], w: {} }); pgApply(t.dataset.pgreset); return; }
    const card = t.closest('.pgk'), grid = card && card.parentElement && card.parentElement.closest('[data-pgrid]');
    if (!grid) return;
    const page = grid.dataset.pgrid, k = pgCardKey(card), lay = pgLay(page);
    if (t.dataset.pgw) lay.w = { ...lay.w, [k]: +t.dataset.pgw };
    else lay.hide = lay.hide.includes(k) ? lay.hide.filter((x) => x !== k) : [...lay.hide, k];
    pgSave(page, lay);
    pgApply(page);
  });
  document.addEventListener('dragstart', (e) => {
    const c = e.target.closest && e.target.closest('.pg-editing > .pgk');
    if (!c) return;
    drag = c;
    c.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', pgCardKey(c)); } catch (ex) { /* oude browsers */ }
  });
  document.addEventListener('dragend', () => { if (drag) drag.classList.remove('dragging'); drag = null; $$('.pg-editing > .over').forEach((x) => x.classList.remove('over')); });
  document.addEventListener('dragover', (e) => {
    const c = drag && e.target.closest && e.target.closest('.pg-editing > .pgk');
    if (c && c !== drag && c.parentElement === drag.parentElement) { e.preventDefault(); $$('.pg-editing > .over').forEach((x) => x !== c && x.classList.remove('over')); c.classList.add('over'); }
  });
  document.addEventListener('drop', (e) => {
    const c = drag && e.target.closest && e.target.closest('.pg-editing > .pgk');
    if (!c || c === drag || c.parentElement !== drag.parentElement) return;
    e.preventDefault();
    const grid = c.parentElement, page = grid.dataset.pgrid, lay = pgLay(page);
    const here = [...grid.children].filter((x) => x.classList.contains('pgk')).sort((a, b) => (+a.style.order || 0) - (+b.style.order || 0)).map(pgCardKey);
    here.splice(here.indexOf(pgCardKey(drag)), 1);
    here.splice(here.indexOf(pgCardKey(c)), 0, pgCardKey(drag));
    // de volgorde van de andere rasters van deze pagina (instellingen: binnen en buiten het formulier) blijft staan
    lay.order = [...here, ...lay.order.filter((k) => !here.includes(k))];
    pgSave(page, lay);
    pgApply(page);
  });
  applyLayout();
}
/** 1.159.0: bouwstenen voor de grafieken in CC (licht SVG, kleuren uit het thema) */
function ccBarsSvg(slots, cols, labels = [], H = 110) {
  const W = 600, n = slots.length, bw = W / n;
  const mx = Math.max(1, ...slots.map((x) => x.reduce((a, b) => a + b, 0)));
  let bars = '';
  slots.forEach((sl, i) => {
    let y = H;
    sl.forEach((v, j) => { if (!v) return; const h = (v / mx) * (H - 12); y -= h; bars += `<rect x="${(i * bw + 2).toFixed(1)}" y="${y.toFixed(1)}" width="${Math.max(1, bw - 4).toFixed(1)}" height="${h.toFixed(1)}" rx="2" fill="${cols[j]}"><title>${sl.reduce((a, b) => a + b, 0)}</title></rect>`; });
  });
  const lab = labels.map(([i, t]) => `<text x="${(i * bw + bw / 2).toFixed(1)}" y="${H + 12}" class="pg-ax" text-anchor="${i === 0 ? 'start' : i >= n - 1 ? 'end' : 'middle'}">${esc(t)}</text>`).join('');
  return `<svg viewBox="0 0 ${W} ${H + 16}" class="pg-bars" preserveAspectRatio="none" aria-hidden="true">${bars}${lab}</svg>`;
}
const ccHbars = (rows, col = 'var(--fx-cy)') => { const m = Math.max(1, ...rows.map((r) => r[1])); return rows.map(([n, v, c]) => `<div class="pg-hbar"><span>${esc(n)}</span><span class="pg-hb"><i style="width:${(v / m * 100).toFixed(0)}%;background:${c || col};box-shadow:0 0 8px ${c || col}"></i></span><b>${v}</b></div>`).join(''); };
function ccDonut(parts, center = '') {
  const tot = parts.reduce((a, p) => a + p[1], 0), r = 38, c = 2 * Math.PI * r;
  let off = 0;
  const segs = tot ? parts.map(([, v, col]) => { const l = v / tot * c; const s = `<circle cx="48" cy="48" r="${r}" fill="none" stroke="${col}" stroke-width="11" stroke-dasharray="${l.toFixed(1)} ${(c - l).toFixed(1)}" stroke-dashoffset="${(-off).toFixed(1)}"/>`; off += l; return s; }).join('') : '';
  return `<div class="pg-donut"><svg viewBox="0 0 96 96" width="110" height="110" aria-hidden="true"><g transform="rotate(-90 48 48)"><circle cx="48" cy="48" r="${r}" fill="none" stroke="var(--fx-bd)" stroke-width="11"/>${segs}</g><text x="48" y="53" text-anchor="middle" class="pg-dn">${esc(String(center))}</text></svg>
    <div class="pg-dl">${parts.map(([n, v, col]) => `<span><i style="background:${col}"></i>${esc(n)} <b>${v}</b></span>`).join('')}</div></div>`;
}
const ccDay = (t) => { const d = new Date(String(t).replace(' ', 'T')); return isNaN(d) ? null : new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(); };
/** Incidenten in CC: geopend en opgelost per dag (30 dagen) en per bron */
function ccIncCharts() {
  const a = $('#inc-cc-chart'), b = $('#inc-cc-src');
  if (!a || !b || !isCC() || !incData) return;
  const today = ccDay(new Date().toISOString().slice(0, 10) + ' 00:00:00') ?? 0, D = 86400000;
  const slots = Array.from({ length: 30 }, () => [0, 0]);
  incData.incidents.forEach((i) => {
    const o = ccDay(i.opened_at), r = i.resolved_at ? ccDay(i.resolved_at) : null;
    const io = o === null ? -1 : 29 - Math.round((today - o) / D), ir = r === null ? -1 : 29 - Math.round((today - r) / D);
    if (io >= 0 && io < 30) slots[io][0]++;
    if (ir >= 0 && ir < 30) slots[ir][1]++;
  });
  const lab = [0, 10, 20, 29].map((k) => [k, new Date(today - (29 - k) * D).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' })]);
  a.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Geopend en opgelost · 30 dagen'))}</span></div>${ccBarsSvg(slots, ['var(--fx-cr)', 'var(--fx-ok)'], lab)}
    <div class="pg-key small"><i style="background:var(--fx-cr)"></i>${esc(L('geopend'))} <i style="background:var(--fx-ok)"></i>${esc(L('opgelost'))}</div>`;
  const act = incData.incidents.filter((i) => i.status !== 'resolved');
  const SRC = { check: ['📈 Monitoring', 'var(--fx-cy)'], ids: ['🛡️ IDS', 'var(--fx-cr)'], vuln: ['🐞 ' + L('Kwetsbaarheden'), 'var(--fx-wa)'] };
  const parts = Object.entries(SRC).map(([k, [n, c]]) => [n, act.filter((i) => i.source === k).length, c]);
  const other = act.length - parts.reduce((x, p) => x + p[1], 0);
  if (other > 0) parts.push([L('Overig'), other, 'var(--fx-mu)']);
  b.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Nog bezig, per bron'))}</span></div>${ccDonut(parts, act.length)}`;
}
/** Kwetsbaarheden in CC: per ernst en de servers met de meeste */
function ccVulnCharts() {
  const a = $('#vuln-cc-sev'), b = $('#vuln-cc-hosts');
  if (!a || !b || !isCC() || !vulnData) return;
  const live = vulnData.findings.filter((f) => !f.ignored);
  const uniq = (fs) => new Set(fs.map((f) => f.host_id + '|' + f.cve)).size;
  const SEV = [[4, 'Critical', 'var(--fx-cr)'], [3, 'High', 'var(--fx-wa)'], [2, 'Medium', 'var(--fx-ou)'], [1, 'Low', 'var(--fx-cy)']];
  const parts = SEV.map(([s, n, c]) => [n, uniq(live.filter((f) => f.severity === s)), c]);
  const kev = uniq(live.filter((f) => f.kev));
  a.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Per ernst'))}</span>${kev ? `<span class="cc-cr small">⚠ ${esc(L('{n} actief misbruikt (KEV)', { n: kev }))}</span>` : ''}</div>${ccDonut(parts, parts.reduce((x, p) => x + p[1], 0))}`;
  const per = {};
  live.forEach((f) => { (per[f.host_id] ||= new Set()).add(f.cve); });
  const name = (id) => (findHost(id) || {}).name || ('#' + id);
  const rows = Object.entries(per).map(([id, set]) => [name(id), set.size]).sort((x, y) => y[1] - x[1]).slice(0, 6);
  b.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Servers met de meeste kwetsbaarheden'))}</span></div>${rows.length ? ccHbars(rows, 'var(--fx-wa)') : `<p class="cc-mu small">${esc(L('Geen open kwetsbaarheden.'))}</p>`}`;
}
/** Netwerk in CC: de apparaten in één oogopslag */
function ccNetChart() {
  const a = $('#net-cc-chart');
  if (!a || !isCC() || !netData) return;
  const devs = netData.devices, day = Date.now() - 86400e3;
  const online = devs.filter((x) => new Date(String(x.last_seen).replace(' ', 'T')).getTime() > day).length;
  const rows = [[L('Online (24 uur)'), online, 'var(--fx-ok)'], [L('Nieuw'), devs.filter((x) => x.status === 'new').length, 'var(--fx-wa)'],
    [L('Riskante poort'), devs.filter((x) => x.risky.length && x.status !== 'ignored').length, 'var(--fx-cr)'], [L('In RzSense'), devs.filter((x) => x.host).length, 'var(--fx-cy)'],
    [L('Bekend'), devs.filter((x) => x.status === 'known').length, 'var(--fx-ou)'], [L('Genegeerd'), devs.filter((x) => x.status === 'ignored').length, 'var(--fx-mu)']];
  a.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Apparaten · {n} in totaal', { n: devs.length }))}</span></div><div class="pg-hgrid">${ccHbars(rows)}</div>`;
}
/** Meldingen in CC: per uur over 24 uur (gestapeld per status) en per kanaal over de geladen dagen */
function renderAlertCharts() {
  const hb = $('#alerts-ch-hour'), cb = $('#alerts-ch-chan');
  if (!hb || !cb || !alertData || !isCC()) return;
  const list = alertData.alerts || [];
  const now = Date.now(), H = 3600000;
  const slots = Array.from({ length: 24 }, () => [0, 0, 0, 0]);   // critical, warning, hersteld, info/overig
  list.forEach((a) => {
    const t = new Date(String(a.created_at).replace(' ', 'T')).getTime();
    const i = 23 - Math.floor((now - t) / H);
    if (i < 0 || i > 23) return;
    const st = +a.status;
    slots[i][a.kind && a.kind !== 'notify' ? 3 : st === 2 ? 0 : st === 1 ? 1 : st === 0 ? 2 : 3]++;
  });
  const mx = Math.max(1, ...slots.map((x) => x[0] + x[1] + x[2] + x[3]));
  const cols = ['var(--fx-cr)', 'var(--fx-wa)', 'var(--fx-ok)', 'color-mix(in srgb, var(--fx-cy) 70%, transparent)'];
  const W = 600, Ht = 110, bw = W / 24;
  let bars = '';
  slots.forEach((sl, i) => {
    let y = Ht;
    sl.forEach((v, j) => { if (!v) return; const h = (v / mx) * (Ht - 12); y -= h; bars += `<rect x="${(i * bw + 2).toFixed(1)}" y="${y.toFixed(1)}" width="${(bw - 4).toFixed(1)}" height="${h.toFixed(1)}" rx="2" fill="${cols[j]}"><title>${sl[0] + sl[1] + sl[2] + sl[3]}</title></rect>`; });
  });
  const hrs = [0, 6, 12, 18].map((k) => { const d = new Date(now - (23 - k) * H); return `<text x="${(k * bw + bw / 2).toFixed(1)}" y="${Ht + 12}" class="pg-ax">${String(d.getHours()).padStart(2, '0')}:00</text>`; }).join('');
  const total = slots.reduce((a, x) => a + x[0] + x[1] + x[2] + x[3], 0);
  hb.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Meldingen per uur · 24 uur'))}</span><span class="cc-mu small">${esc(L('{n} meldingen', { n: total }))}</span></div>
    ${total ? `<svg viewBox="0 0 ${W} ${Ht + 16}" class="pg-bars" preserveAspectRatio="none" role="img" aria-label="${esc(L('Meldingen per uur'))}">${bars}${hrs}</svg>` : `<p class="cc-mu small">${esc(L('Geen meldingen in de laatste 24 uur.'))}</p>`}
    <div class="pg-key small"><i style="background:var(--fx-cr)"></i>critical <i style="background:var(--fx-wa)"></i>warning <i style="background:var(--fx-ok)"></i>${esc(L('hersteld'))} <i style="background:var(--fx-cy)"></i>info</div>`;
  const ch = [[L('Push (Pushover, ntfy)'), list.filter((a) => +a.sent_ok).length], [L('E-mail'), list.filter((a) => +a.email_ok).length],
    [L('Webhook / Home Assistant'), list.filter((a) => +a.webhook_ok).length], [L('Alleen op de pagina'), list.filter((a) => !+a.sent_ok && !+a.email_ok && !+a.webhook_ok).length]];
  const cm = Math.max(1, ...ch.map((x) => x[1]));
  cb.innerHTML = `<div class="cc-h"><span class="cc-hl">${esc(L('Per kanaal · {d} dagen', { d: alertData.days || 7 }))}</span></div>
    ${ch.map(([n, v]) => `<div class="pg-hbar"><span>${esc(n)}</span><span class="pg-hb"><i style="width:${(v / cm * 100).toFixed(0)}%"></i></span><b>${v}</b></div>`).join('')}`;
}

// ---------------------------------------------------------------- Monitoring live (1.157.0)
/** Rode gloed en live-animaties (Mijn account → Weergave, per account; standaard aan) */
const monGlow = () => PREFS.mon_glow !== 'off';
// 1.201.0: Liquid Glass (standaard uit) en de laadanimatie (standaard aan), per account
/** 1.244.0: het thema (Standaard, @S, Liquid Glass); een oude Liquid Glass-schakelaar telt als het thema Liquid Glass */
const skinCur = () => (['std', 's', 'glass', 'neo'].includes(PREFS.skin) ? PREFS.skin : PREFS.mon_glass === 'on' ? 'glass' : 'std');
const monGlass = () => skinCur() === 'glass';
const monLoader = () => PREFS.mon_loader !== 'off';
const monVivid = () => PREFS.mon_vivid === 'on';   // 1.216.0: Liquid Glass levendig (anders rustig)
const MON_PREF_KEY = { glow: 'mon_glow', anim: 'mon_anim', glass: 'mon_glass', loader: 'mon_loader', vivid: 'mon_vivid' };
const monAnim = () => PREFS.mon_anim !== 'off';
document.addEventListener('pointermove', (e) => {
  if (!document.body.classList.contains('lg') || e.pointerType === 'touch') return;
  const g = e.target.closest && e.target.closest('.card, .pcard, .ccx, .hcard');
  if (!g) return;
  const r = g.getBoundingClientRect();
  g.style.setProperty('--mx', `${Math.round(e.clientX - r.left)}px`);
  g.style.setProperty('--my', `${Math.round(e.clientY - r.top)}px`);
}, { passive: true });
function applyMonPrefs() {
  document.body.classList.toggle('no-glow', !monGlow());
  document.body.classList.toggle('no-anim', !monAnim());
  document.body.classList.toggle('lg', monGlass());
  document.body.classList.toggle('no-loader', !monLoader());
  document.body.classList.toggle('lg-vivid', monGlass() && monVivid());
  document.body.classList.toggle('sk-s', skinCur() === 's');
  document.body.classList.toggle('sk-neo', skinCur() === 'neo');   // 1.245.0: thema Neo
  if ($('#vivid-row')) $('#vivid-row').hidden = !monGlass();   // levendige kleuren alleen bij Liquid Glass
  if (window.RzPulse) RzPulse.refreshColors();
  $$('[data-mon-pref]').forEach((c) => { c.checked = ({ glow: monGlow, anim: monAnim, glass: monGlass, loader: monLoader, vivid: monVivid }[c.dataset.monPref] || (() => false))(); });
  // 1.162.0: IDS-weergave en beweging, per account (standaard: wat de beheerder instelde)
  const vc = $('.view-card');
  if (vc) {
    const im = $('[data-acct-ids-modern]', vc), mo = $('[data-acct-motion]', vc);
    if (im) im.checked = (PREFS.ids_modern || vc.dataset.idsModern) === 'on';
    if (mo) mo.value = PREFS.af_motion !== undefined && PREFS.af_motion !== '' ? PREFS.af_motion : vc.dataset.afMotion;
  }
}
/** Een server met een critical check die nog niet bevestigd is */
const glowHost = (h) => +h.enabled && h.checks.some((c) => +c.enabled && !c.maintenance && +c.status === 2 && !c.acked_at);

/** Volgorde voor "de 3 belangrijkste checks": eerst wat stuk is, dan checks met een meetwaarde, in deze volgorde */
const KEY_RANK = ['internet:down', 'internet:up', 'internet:ping', 'cpu', 'ram', 'disk', 'internet:resp', 'ping', 'http', 'tcp', 'dns', 'mysql', 'ntp',
  'container', 'snmp', 'pve', 'truenas', 'ha', 'ups', 'smart', 'process', 'service', 'ssl', 'heartbeat', 'log', 'command', 'reboot'];
const keyKind = (c) => (c.type === 'internet' ? 'internet:' + (c.metric || '') : c.type);
function keyChecks(h, n = 3) {
  const on = h.checks.filter((c) => +c.enabled && !c.maintenance);
  const rank = (c) => { const i = KEY_RANK.indexOf(keyKind(c)); return i < 0 ? 99 : i; };
  const hasVal = (c) => c.last_value !== null && c.last_value !== undefined && typeOf(c).unit;
  const out = on.filter((c) => +c.status !== 0 && !c.acked_at)
    .sort((a, b) => STATUS_RANK[+b.status] - STATUS_RANK[+a.status] || rank(a) - rank(b)).slice(0, n);
  // Met een meetwaarde: één per soort (de volste disk, de drukste CPU), daarna de rest
  const seen = new Set(out.map(keyKind));
  const vals = on.filter((c) => !out.includes(c) && hasVal(c)).sort((a, b) => rank(a) - rank(b) || (typeOf(a).unit === '%' ? b.last_value - a.last_value : 0));
  vals.forEach((c) => { if (out.length < n && !seen.has(keyKind(c))) { out.push(c); seen.add(keyKind(c)); } });
  vals.forEach((c) => { if (out.length < n && !out.includes(c)) out.push(c); });
  on.filter((c) => !out.includes(c)).sort((a, b) => rank(a) - rank(b)).forEach((c) => { if (out.length < n) out.push(c); });
  return out;
}
/** Waarde van een check: [getal, eenheid], of een woord als er geen getal is */
function keyValue(c) {
  const t = typeOf(c);
  if (c.last_value === null || c.last_value === undefined || !t.unit) return [+c.status === 0 ? 'OK' : STATUS[+c.status] === 'CRITICAL' ? '✕' : +c.status === 1 ? '!' : '?', ''];
  const v = +c.last_value;
  const n = t.unit === '%' || t.unit === 'Mbit/s' || Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10;
  return [dec(n.toLocaleString(LOCALE)), t.unit === '%' ? '%' : ' ' + L(t.unit)];
}
const keyText = (c) => { const [v, u] = keyValue(c); return `<span class="s${c.status}">${esc(c.name)} <b>${v}${esc(u)}</b></span>`; };
/** Lijntje van 24 uur met een vlakje eronder, in de kleur van de status */
function keySpark(c, H = 22) {
  const data = stats.sparks[c.id];
  const pts = (data || []).map((d, i) => [i, d && d[0] !== null && d[0] !== undefined ? +d[0] : null]).filter(([, v]) => v !== null);
  if (pts.length < 2) return `<svg class="km-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none" aria-hidden="true"></svg>`;
  const vs = pts.map(([, v]) => v);
  const pctU = typeOf(c).unit === '%';
  const lo = pctU ? 0 : Math.min(...vs), hi = pctU ? 100 : Math.max(...vs);
  const y = (v) => (H - 2 - ((v - lo) / Math.max(hi - lo, pctU ? 1 : Math.abs(hi) * .05 || 1)) * (H - 4)).toFixed(1);
  const x = (i) => (i / 47 * 100).toFixed(1);
  const d = pts.map(([i, v], k) => `${k ? 'L' : 'M'}${x(i)},${y(v)}`).join('');
  const [lx, ly] = [x(pts[pts.length - 1][0]), y(pts[pts.length - 1][1])];
  return `<svg class="km-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none" aria-hidden="true"><path class="km-fill" d="${d}L${lx},${H}L${x(pts[0][0])},${H}Z"/><path class="km-line" d="${d}"/><circle class="km-dot" cx="${lx}" cy="${ly}" r="1.6"/></svg>`;
}
/** Eén van de 3 belangrijkste checks: naam, waarde, lijntje (en een balkje bij procenten). Klik = popup van de check. */
function keyBox(c, compact = false) {
  const [v, u] = keyValue(c);
  const t = typeOf(c);
  const st = c.maintenance ? 4 : +c.status;
  const bar = t.unit === '%' && c.last_value !== null && !compact ? `<span class="km-bar"><i style="width:${Math.min(100, Math.max(0, +c.last_value))}%"></i></span>` : '';
  const msg = (c.last_message || '').replace(/\s+/g, ' ');
  return `<span class="km s${st}" data-cid="${c.id}" title="${esc(c.name)}: ${esc(msg)}">
    <span class="km-top"><span class="km-n"><i class="km-sd"></i>${esc(c.name)}</span><b class="km-v" data-flash>${v}<small>${esc(u)}</small></b></span>
    <span class="km-mid">${keySpark(c, compact ? 18 : 24)}${compact ? '' : `<span class="km-msg">${esc(msg.slice(0, 70))}</span>`}</span>${bar}</span>`;
}
/** Status per uur over 24 uur (uit de minigrafieken): het ergste van die checks */
function timelineOf(ids) {
  const out = [];
  for (let i = 0; i < 24; i++) {
    let w = null;
    ids.forEach((id) => {
      const r = stats.sparks[id];
      if (!r) return;
      [r[i * 2], r[i * 2 + 1]].forEach((d) => { if (d && d[1] !== null && d[1] !== undefined) { const s = +d[1]; if (w === null || STATUS_RANK[s] > STATUS_RANK[w]) w = s; } });
    });
    out.push(w);
  }
  return `<span class="tl24" aria-hidden="true">${out.map((s) => `<i class="${s === null ? 'n' : 's' + s}"></i>`).join('')}</span>`;
}
const hostTimeline = (h) => timelineOf(h.checks.filter((c) => +c.enabled).map((c) => c.id));
const checkTimeline = (c) => timelineOf([c.id]);
/** Reeks van 48 halve uren uit de minigrafieken: per half uur f(status) opgeteld of gemiddeld */
function sparkSeries(inGroup, f, how = 'avg') {
  const hostOf = {};
  state.hosts.forEach((h) => h.checks.forEach((c) => { hostOf[c.id] = h.id; }));
  const rows = Object.entries(stats.sparks || {}).filter(([cid]) => inGroup(hostOf[cid])).map(([, r]) => r);
  const out = [];
  for (let i = 0; i < 48; i++) {
    let sum = 0, n = 0;
    rows.forEach((r) => { const d = r[i]; const v = d ? f(d[1] === null || d[1] === undefined ? null : +d[1]) : null; if (v !== null) { sum += v; n++; } });
    out.push(n ? (how === 'sum' ? sum : sum / n) : null);
  }
  return out;
}
function kpiSpark(series, col, invert = false) {
  const vals = series.filter((v) => v !== null);
  if (vals.length < 2) return '';
  const hi = Math.max(...vals, 1);
  let d = '';
  series.forEach((v, i) => { if (v !== null) d += `${d ? 'L' : 'M'}${(i / 47 * 100).toFixed(1)},${(24 - (v / hi) * 20).toFixed(1)}`; });
  return `<svg class="kpi-spark${invert ? ' inv' : ''}" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${d}L100,26L0,26Z" fill="${col}" opacity=".12"/><path d="${d}" fill="none" stroke="${col}" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>`;
}
/** ▲/▼ met het verschil; lowerBetter: minder is beter (problemen), pctPts: verschil in procentpunten */
function kpiTrend(now, then, lowerBetter = false, pctPts = false) {
  if (now === null || then === null || now === undefined || then === undefined) return '';
  const d = Math.round((now - then) * 10) / 10;
  if (Math.abs(d) < (pctPts ? 0.1 : 1)) return `<span class="kpi-tr eq" title="${L('gelijk aan 24 uur geleden')}">=</span>`;
  const good = lowerBetter ? d < 0 : d > 0;
  const txt = pctPts ? dec(String(Math.abs(d))) : String(Math.abs(Math.round(d)));
  return `<span class="kpi-tr ${good ? 'up' : 'down'}" title="${pctPts ? L('vergeleken met 7 dagen') : L('vergeleken met 24 uur geleden')}">${d > 0 ? '▲' : '▼'} ${txt}</span>`;
}
/** Compact: één blokje per check, per server een regel (veel checks of een TV-scherm). Klik = popup van de check. */
function compactView(hosts) {
  const rank = (c) => (c.maintenance ? 0 : STATUS_RANK[+c.status]);
  return `<div class="hm-list">${hosts.map((h) => {
    const cs = (h.shown && h.shown.length ? h.shown : h.checks).filter((c) => +c.enabled).sort((a, b) => rank(b) - rank(a) || a.name.localeCompare(b.name, LANG));
    return `<div class="hm-row s${hostStatus(h)}${glowHost(h) ? ' glow' : ''}" data-host="${h.id}">
      <span class="hm-name"><span aria-hidden="true">${hostIcon(h)}</span> <b>${esc(h.name)}</b></span>
      <span class="hm-cells">${cs.map((c) => { const [v, u] = keyValue(c); return `<i class="hm-c s${c.maintenance ? 4 : c.status}" data-cid="${c.id}" title="${esc(c.name)}: ${v}${esc(u)} · ${esc((c.last_message || '').slice(0, 120))}"></i>`; }).join('')}</span>
      ${hostTimeline(h)}
    </div>`;
  }).join('')}</div>`;
}

/** Kleine lijn van een check over 24 uur (zonder statusstrook) */
function miniSpark(c, H) {
  const data = stats.sparks[c.id];
  if (!data) return `<svg class="hs-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none"></svg>`;
  const vals = data.filter((d) => d && d[0] !== null).map((d) => +d[0]);
  if (!vals.length) return `<svg class="hs-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none"></svg>`;
  const max = typeOf(c).unit === '%' ? 100 : Math.max(...vals, 1) * 1.15;
  let d = '';
  data.forEach((x, i) => { if (x && x[0] !== null) d += `${d ? 'L' : 'M'}${(i / 47 * 100).toFixed(1)},${(H - 2 - (+x[0] / max) * (H - 4)).toFixed(1)}`; });
  return `<svg class="hs-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none" aria-hidden="true"><path d="${d}" vector-effect="non-scaling-stroke"/></svg>`;
}

// ---------------------------------------------------------------- Weergave (per apparaat)
// Blox = tegels (standaard), L1nes = balken onder elkaar; allebei in Small of Large, elk met een eigen keuze.
// Blox staat standaard op Large (de tegels zoals altijd), L1nes op Small.
function viewPrefs() {
  let layout = 'blox', density = 'large';
  try {
    const lay = layoutPref();   // 1.158.0: per account (CC toont Monitoring als Blox, in de CC-stijl)
    layout = lay === 'l1nes' || lay === 'compact' ? lay : 'blox';
    // 1.162.0: per account, apart voor telefoon en computer
    density = layout === 'l1nes'
      ? (uiGet('dens_l1_' + uiDev(), 'rzmon-density', 'small') === 'large' ? 'large' : 'small')
      : (uiGet('dens_blox_' + uiDev(), 'rzmon-blox-density', 'large') === 'small' ? 'small' : 'large');
  } catch (e) { /* geen opslag: standaard */ }
  return { layout, density };
}
function setViewPref(key, value) {
  if (key === 'layout') return setLayout(value);
  if (key === 'density') {
    const blox = viewPrefs().layout === 'blox';
    uiSet((blox ? 'dens_blox_' : 'dens_l1_') + uiDev(), blox ? 'rzmon-blox-density' : 'rzmon-density', value);
  } else { try { localStorage.setItem('rzmon-' + key, value); } catch (e) { /* alleen nu */ } }
  showViewPrefs();
  render();
}
/** 1.158.0: lay-out kiezen (per account): Blox, L1nes, Compact of CC */
function setLayout(v) {
  if (!LAYOUTS.includes(v) || v === layoutPref()) return;
  savePref('layout', v);
  try { localStorage.setItem('rzmon-layout', v); } catch (e) {}
  if (v !== 'cc') { pgEdit = null; if (document.body.classList.contains('tv')) ccTv(false); }
  applyLayout();
  showViewPrefs();
  render();
  if (alertData) renderAlerts();
  if (incData) renderIncidents();
  if (vulnData) renderVulns();
  if (netData) renderNet();
  if (idsData) renderIds();
  // Dashboard: klassiek of Command center, opnieuw laden (andere gegevens)
  ccEdit = false;
  markDash();
  hmData = null;
  if ($('#home')) { $('#home').innerHTML = `<p class="muted">${esc(L('Laden…'))}</p>`; loadHome(); }
}
function showViewPrefs() {
  const { layout, density } = viewPrefs();
  $$('[data-view-open]').forEach((b) => b.classList.toggle('active', b.dataset.viewOpen === (smartOpen() ? 'smart' : 'closed')));
  $$('[data-view-layout]').forEach((b) => b.classList.toggle('active', b.dataset.viewLayout === layoutPref()));
  $$('[data-view-density]').forEach((b) => { b.classList.toggle('active', b.dataset.viewDensity === density); });
  applySettingsLayout();
  if (alertData) renderAlerts();
}

// ---------------------------------------------------------------- Instellingen in L1nes
// Blox: kaarten naast elkaar (zoals altijd). L1nes: rijen over de volle breedte in groepen, open te klappen;
// Small = standaard dicht, Large = standaard open. Wat je open- of dichtklapt, wordt per apparaat onthouden.
function applySettingsLayout() {
  const { density } = viewPrefs();
  const lines = false;   // 1.60.0: de instellingen hebben één vaste opmaak (zijbalk), los van Blox/L1nes
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
  const shown = setCards().filter(setVisible);
  $('#set-toggle-all').textContent = shown.length && shown.every((c) => c.classList.contains('set-open')) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
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
  const on = (x) => `<span class="good-txt">✔ ${L('aan')}</span>${x ? ' · ' + x : ''}`;
  const off = `<span class="muted">✘ ${L('uit')}</span>`;
  const opt = (sel) => (sel && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent.trim() : '');
  const when = (r) => (!r ? '?' : r.mode === 'never' ? L('nooit') : r.mode === 'now' ? L('direct') : L('na {n} min', { n: r.min }));
  const txt = (sel) => ($(sel) ? $(sel).textContent.replace(/\s+/g, ' ').trim() : '');
  const sum = {};
  if (isAdmin()) {
    sum.pushover = f.pushover_enabled.checked ? on(`critical: ${opt(f.pushover_priority_crit)} · warning: ${opt(f.pushover_priority_warn)}`) : off;
    sum.ntfy = f.ntfy_enabled.checked ? on(esc(`${f.ntfy_server.value.replace(/^https?:\/\//, '').replace(/\/$/, '')}/${f.ntfy_topic.value}`)) : off;
    if (notifyGlobal) {
      const n = notifyGlobal.get(), q = null;
      sum.notify = esc([`warning: ${when(n.rules.warn)}`, `critical: ${when(n.rules.crit)}`, n.rules.crit && n.rules.crit.repeat ? L('herhalen elke {n} min', { n: n.rules.crit.repeat }) : '',
        q && q.on && q.windows.length ? `🌙 ${quietText(q.windows)}` : '', n.flap.on ? 'anti-flapping' : ''].filter(Boolean).join(' · '));
    }
    sum.email = f.email_enabled.checked ? on(esc(f.mail_to.value)) : off;
    sum.webhook = f.ha_webhook_enabled.checked ? on('') : off;
    sum.mqtt = f.mqtt_enabled.checked ? on(esc(txt('#mqtt-status').replace(/^✔ /, ''))) : off;
    sum.ids = !$('#ids-enabled').checked ? off
      : $('#ids-mode').checked ? `<span class="warn-txt">⛔ ${L('blokkeren')}</span>` : `<span class="good-txt">✔ ${L('aan')}</span> · ${L('leren (alleen melden)')}`;
    sum.ids += f.auth_events.checked ? ' · 🔑 ' + L('toegang') : '';
    if (fimGlobal && fimGlobal.get().length) sum.ids += ' · 📁 ' + (fimGlobal.get().length === 1 ? L('{n} pad', { n: 1 }) : L('{n} paden', { n: fimGlobal.get().length }));
    if ($('#vuln-enabled')) sum.vulns = $('#vuln-enabled').checked ? on(esc(L('dagelijks om {t}', { t: f.vuln_time.value }))) : off;
    if (sysinfoCfg) {
      const all = SYSINFO_ROWS.flatMap(([, rows]) => rows.map(([k]) => k));
      const out = all.filter((k) => sysinfoCfg[k] && (sysinfoCfg[k].pushover || sysinfoCfg[k].email || sysinfoCfg[k].webhook)).length;
      sum.sysinfo = esc(L('{n} van {t} naar buiten', { n: out, t: all.length })) + ' · ' + esc(L('{n} op de meldingenpagina', { n: all.filter((k) => sysinfoCfg[k] && sysinfoCfg[k].page).length }));
    }
    if (secInfo) {
      sum.enc = !secInfo.enc.key ? `<span class="warn-txt">⚠️ ${L('geen sleutel')}</span>` : secInfo.enc.mode === 'off' ? off
        : `<span class="good-txt">🔒 ${esc(ENC_MODES.find((m) => m[0] === secInfo.enc.mode)[1].split(' (')[0])}</span> · ${L('{n} van {t} versleuteld', { n: secInfo.hosts.filter((h) => +h.enc_seen === 1).length, t: secInfo.hosts.length })}`;
      sum.blocks = secInfo.blocks.length ? `⛔ ${secInfo.blocks.length === 1 ? L('1 blokkade') : L('{n} blokkades', { n: secInfo.blocks.length })}` : `<span class="muted">${L('geen')}</span>`;
    }
    sum.alarm = esc(L('agent offline na {a} min · historie {h} dagen · meldingen {m} dagen', { a: f.agent_stale_minutes.value, h: f.retention_days.value, m: f.alerts_days.value }));
    if (updateInfo) {
      sum.update = (updateInfo.available ? `<span class="warn-txt">⬆ ${L('{v} beschikbaar', { v: esc(updateInfo.latest) })}</span>` : `✔ ${esc(updateInfo.current)}${updateInfo.latest ? ' · ' + L('nieuwste versie') : ''}`)
        + (f.update_auto.checked ? ' · ' + L('automatisch om {t}', { t: esc(f.update_time.value) }) : '');
    }
    sum.agents = esc(txt('#agents-status')) + ($('#agent-commands').dataset.current && $('#agent-commands').dataset.current !== 'off'
      ? ' · ' + L('commando\'s {m}', { m: esc(CMD_MODES[$('#agent-commands').dataset.current]) }) : '');
    if (backupInfo && !backupInfo.problem) {
      const last = backupInfo.list[0];
      sum.backup = esc(f.backup_freq.value === 'off' ? L('automatisch uit') : L('{freq} om {t}', { freq: opt(f.backup_freq).toLowerCase(), t: f.backup_time.value }))
        + (last ? ' · ' + L('laatste: {t}', { t: ago(last.at) }) : '') + (backupInfo.last_error ? ` · <span class="warn-txt">✘ ${L('mislukt')}</span>` : '');
    } else if (backupInfo) sum.backup = `<span class="warn-txt">⚠️ ${L('niet ingesteld')}</span>`;
    sum.db = esc(txt('#db-status').split(' · ')[0]);
    const af = $('#access-form');
    sum.access = af.lan_no_login.checked ? (af.lan_as_admin.checked ? L('LAN zonder inloggen (als beheerder)') : L('LAN zonder inloggen')) : L('altijd inloggen');
    if (accessLock && (accessLock.account || accessLock.ip)) sum.access += ' · 🔐 ' + L('blokkeren na {n} pogingen', { n: accessLock.fails });
    const nKeys = $$('#api-keys .key-row').length;
    sum.api = nKeys === 1 ? L('{n} sleutel', { n: nKeys }) : L('{n} sleutels', { n: nKeys });
  }
  if (Array.isArray(users)) sum.users = users.length === 1 ? L('{n} gebruiker', { n: users.length }) : L('{n} gebruikers', { n: users.length });
  setCards().forEach((card) => { const el = $('.set-sum', card); if (el) el.innerHTML = sum[card.dataset.sk] || ''; });
}

function idsModeInfo() {
  const on = $('#ids-enabled').checked, block = $('#ids-mode').checked;
  $('#ids-mode').disabled = !on;
  $('#ids-l-learn').classList.toggle('active', !block);
  $('#ids-l-block').classList.toggle('active', block);
  $('#ids-mode-info').innerHTML = !on ? L('IDS staat uit: de agents verzamelen niets en er wordt niets geblokkeerd.')
    : block ? L('<b class="warn-txt">Blokkeren:</b> brute force wordt gemeld <b>en</b> geblokkeerd; met ⛔ blokkeer je zelf een IP.')
    : L('<b>Leren:</b> alles wordt gemeld, er wordt niets geblokkeerd.');
}

// ---------------------------------------------------------------- Meteen opslaan (1.61.0)
// Elke wijziging gaat vanzelf naar de server: alleen wat anders is dan de opgeslagen stand. Schakelaars en keuzelijsten
// meteen, tekstvelden zodra je ze verlaat (of op Enter). Naast het veld staat dan ✔ opgeslagen (of ✘ met de reden).
let settingsSaved = null;    // de stand die de server heeft
let autosaveTimer = null;
let autosaveEls = new Set();
function markSettingsDirty(dirty, el) {
  if (!dirty) { settingsSaved = settingsData(); setAutosaveState(''); return; }
  if (el) autosaveEls.add(el);
  clearTimeout(autosaveTimer);
  setAutosaveState('busy');
  autosaveTimer = setTimeout(autosaveSettings, 350);
}

async function autosaveSettings() {
  clearTimeout(autosaveTimer);
  if (!settingsSaved) return;
  const now = settingsData();
  const diff = Object.fromEntries(Object.entries(now).filter(([k, v]) => settingsSaved[k] !== v));
  const els = [...autosaveEls];
  autosaveEls = new Set();
  if (!Object.keys(diff).length) { setAutosaveState(''); return; }
  try {
    const r = await api('settings_save', diff);
    Object.assign(settingsSaved, diff);
    if (r && r.pruned) { toast(r.pruned === 1 ? L('1 oude back-up opgeruimd') : L('{n} oude back-ups opgeruimd', { n: r.pruned })); loadBackups(); }
    els.forEach((el) => savedFlag(el, true));
    setAutosaveState('ok');
    if ('ai_on' in diff) { refresh(); if (setSection === 'ai') loadAiSettings(); }   // 1.171.0: AI aan of uit: knoppen tonen of weghalen
    if (setHealthItems[setSection]) loadSettingsHealth();   // 1.133.0: misschien is een punt nu opgelost
  } catch (ex) {
    els.forEach((el) => savedFlag(el, false, ex.message));
    setAutosaveState('err', ex.message);
    toast(ex.message, 'err');
  }
}

/** ✔ opgeslagen / ✘ niet opgeslagen naast het veld (of bij de titel van de kaart) */
function savedFlag(el, ok, why) {
  if (!el || !el.isConnected) return;
  const anchor = el.closest('label, td, .view-row, .acct-ctl, .lock-row, .notify-row') || el.closest('.card')?.querySelector('h2') || el;
  let f = anchor.querySelector(':scope > .saved-flag');
  if (!f) { f = document.createElement('span'); f.className = 'saved-flag'; anchor.append(f); }
  f.textContent = ok ? '✔ ' + L('opgeslagen') : '✘ ' + L('niet opgeslagen');
  f.title = why || '';
  f.classList.toggle('bad', !ok);
  f.classList.remove('fade'); void f.offsetWidth; f.classList.add('show');
  clearTimeout(f._t);
  if (ok) f._t = setTimeout(() => f.classList.add('fade'), 2200);
}

function setAutosaveState(st, why) {
  const el = $('#save-state');
  if (!el) return;
  el.className = 'autosave-state ' + st;
  el.textContent = st === 'busy' ? L('opslaan…') : st === 'ok' ? '✔ ' + L('alles opgeslagen') : st === 'err' ? '✘ ' + L('niet opgeslagen') + (why ? ': ' + why : '') : '';
}

// ---------------------------------------------------------------- Server-tegels
// Ingeklapt: alleen de algehele status. Klik = uitklappen met alle checks.
// Bij openen alles dicht (1.71.0): wat je openklapt, blijft open tot je herlaadt. "Slim openen": onthouden in deze browser.
const openHosts = new Set((() => { try { return smartOpen() ? JSON.parse(localStorage.getItem('rzmon-open') || '[]') : []; } catch (e) { return []; } })());
function saveOpen() { if (!smartOpen()) return; try { localStorage.setItem('rzmon-open', JSON.stringify([...openHosts])); } catch (e) {} }
function toggleHost(id) {
  openHosts.has(+id) ? openHosts.delete(+id) : openHosts.add(+id);
  saveOpen();
  render();
}

function hostSummary(h) {
  const enabled = h.checks.filter((c) => +c.enabled);
  if (!enabled.length) return { text: L('Nog geen checks'), cls: 's3' };
  const active = enabled.filter((c) => !c.maintenance);
  if (!active.length) return { text: '🔧 ' + L('In onderhoud'), cls: 's4' };
  const n = [0, 0, 0, 0];
  active.forEach((c) => n[+c.status]++);
  const acked = active.filter((c) => +c.status !== 0 && c.acked_at).length;
  const parts = [];
  if (n[2]) parts.push(L('{n} kritiek', { n: n[2] }));
  if (n[1]) parts.push(L('{n} waarschuwing', { n: n[1] }));
  if (n[3]) parts.push(L('{n} onbekend', { n: n[3] }));
  if (!parts.length) return { text: `${L('Alles OK')} · ${active.length} check${active.length === 1 ? '' : 's'}`, cls: 's0' };
  if (acked && acked === n[1] + n[2] + n[3]) return { text: '✔ ' + L('alles bevestigd') + ' · ' + parts.join(' · '), cls: 's-ack' };   // 1.168.0
  return { text: parts.join(' · ') + (acked ? ` (${L('{n} bevestigd', { n: acked })})` : ''), cls: 's' + hostStatus(h) };
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
    title: `${esc(h.name)} <span class="badge">${esc(h.mode)}</span>${+h.enabled ? '' : `<span class="badge">${L('uit')}</span>`}`,
    via: parent ? ` <span class="via" title="${L('Afhankelijk van {name}: geen aparte meldingen als die onbereikbaar is', { name: esc(parent.name) })}">↳ via ${esc(parent.name)}</span>` : '',
    maint: h.maintenance ? `<span class="maint-tag">${esc(maintLabel(h.maintenance))}</span>` : '',
    cls: `card host s${hostStatus(h)} ${+h.enabled ? '' : 'disabled'} ${h.maintenance ? 'in-maint' : ''} ${openHosts.has(+h.id) || h.autoOpen ? 'open' : ''} ${ackedOnly(h) ? 'acked-only' : ''}`,
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
      || name(a).localeCompare(name(b), LANG, { numeric: true, sensitivity: 'base' }))]);
  const known = CHECK_GROUPS.flatMap((g) => g[2]);
  const other = checks.filter((c) => !known.includes(c.type));
  if (other.length) out.push(['other', L('Overig'), other]);
  return out.filter((g) => g[2].length);
}

/** Een probleem: aan, niet in onderhoud en warning, critical of onbekend */
const isProblem = (c) => +c.enabled && !c.maintenance && +c.status !== 0;
/** 1.168.0: alle problemen van deze server zijn al bevestigd (er is wel iets, maar niets meer te doen) */
const ackedOnly = (h) => { const p = h.checks.filter(isProblem); return +h.enabled && !h.maintenance && p.length > 0 && p.every((c) => c.acked_at); };

/**
 * Groep "⚠️ Problemen" bovenaan een server: [[sleutel, titel, checks], …] per status (critical → warning → onbekend),
 * bevestigde storingen onderaan apart. Binnen een status op naam.
 */
function problemGroups(list) {
  const byName = (a, b) => a.name.localeCompare(b.name, LANG, { numeric: true, sensitivity: 'base' });
  const open = list.filter((c) => !c.acked_at);
  return [
    ['2', '🔴 ' + L('Critical'), open.filter((c) => +c.status === 2).sort(byName)],
    ['1', '🟠 ' + L('Warning'), open.filter((c) => +c.status === 1).sort(byName)],
    ['3', '⚪ ' + L('Onbekend'), open.filter((c) => +c.status === 3).sort(byName)],
    ['ack', '✔ ' + L('Bevestigd'), list.filter((c) => c.acked_at).sort((a, b) => STATUS_RANK[+b.status] - STATUS_RANK[+a.status] || byName(a, b))],
  ].filter((g) => g[2].length);
}

/**
 * Is de groep Problemen ingeklapt? Bij inklappen onthouden we welke checks er toen in stonden;
 * komt er een nieuw probleem bij, dan gaat de groep vanzelf weer open.
 */
function problemsClosed(h, list) {
  const was = grpClosed[`${h.id}:problems`];
  if (typeof was !== 'string') return false;
  const ids = was.split(',');
  return list.every((c) => ids.includes(String(c.id)));
}

/** Kop van een groep: titel, aantal en de statussen die aandacht vragen */
function groupHead(h, k, title, list, tag) {
  const n = { 2: 0, 1: 0, 3: 0 };
  list.forEach((c) => { if (+c.enabled && !c.maintenance && n[+c.status] !== undefined) n[+c.status]++; });
  const sum = [[2, '🔴'], [1, '🟠'], [3, '⚪']].filter(([s]) => n[s]).map(([s, i]) => `<span class="ad-count">${i} ${n[s]}</span>`).join('') || '<span class="ad-count">✅</span>';
  const closed = k === 'problems' ? problemsClosed(h, list) : !!grpClosed[`${h.id}:${k}`];
  const sig = k === 'problems' ? ` data-sig="${list.map((c) => c.id).join(',')}"` : '';
  return `<${tag} class="grp-head${k === 'problems' ? ' grp-problems' : ''}" data-act="grp" data-id="${h.id}" data-g="${k}"${sig} role="button" tabindex="0" aria-expanded="${!closed}">
    <span class="grp-chev">${closed ? '▸' : '▾'}</span><span class="grp-title">${title}</span>
    <span class="grp-sum"><span class="muted small">${list.length}</span>${sum}</span></${tag}>`;
}

/** Knoppen van een server (bevestigen, onderhoud, check toevoegen …); ook in de open servertabel van CC (1.164.0) */
function detailBar(h) {
  const agentInfo = h.mode === 'agent'
    ? `<span class="muted small">${contactHtml(h, 'short')}</span>
       <button class="btn need-admin" data-act="agent" data-id="${h.id}">Agent Manager</button>
       <button class="btn need-user" data-act="logs" data-id="${h.id}" title="${esc(L('Zoeken in de logs van deze server'))}">📜 ${L('Logs')}</button>` : '';
  return `<div class="detail-bar">
        ${h.checks.some((c) => +c.enabled && +c.status !== 0 && !c.acked_at) ? `<button class="btn ack-btn need-user" data-act="ack-host" data-id="${h.id}">✔ ${L('Alles bevestigen')}</button>` : ''}
        <button class="btn need-user" data-act="maint-host" data-id="${h.id}">🔧 ${L('Onderhoud')}</button>
        <button class="btn need-user" data-act="quiet-host" data-id="${h.id}">🌙 ${h.quiet_eff && h.quiet_eff.src === 'host_now' ? L('Stil tot {t}', { t: fmtUntil(h.quiet_eff.now) }) : L('Nu stil')}</button>
        <button class="btn need-admin" data-act="add-check" data-id="${h.id}">＋ Check</button>
        ${+h.discover_n ? `<button class="btn need-admin discover-btn" data-act="discover" data-id="${h.id}" title="${esc(L('Nog niet bewaakt: disks, services, containers, certificaten en poorten die de agent ziet'))}">💡 ${+h.discover_n === 1 ? L('1 nieuw gevonden') : L('{n} nieuw gevonden', { n: h.discover_n })}</button>` : ''}
        <button class="btn need-user" data-act="scan" data-id="${h.id}">🔍 ${L('Poorten')}</button>
        ${h.snmp ? `<button class="btn need-admin" data-act="snmp" data-id="${h.id}">📡 ${L('SNMP-checks')}</button>` : ''}
        ${hostKind(h) === 'camera' ? `<button class="btn need-admin" data-act="cam-rec" data-id="${h.id}">${L('＋ Aanbevolen checks')}</button>` : ''}
        ${h.metrics.includes('podman') ? `<button class="btn" data-act="podman" data-id="${h.id}">📦 Containers</button>` : ''}
        ${isPveHost(h) ? `<button class="btn" data-act="pve" data-id="${h.id}">🖥️ Proxmox</button>` : ''}
        ${isTnHost(h) ? `<button class="btn" data-act="truenas" data-id="${h.id}">🗄️ TrueNAS</button>` : ''}
        <button class="btn need-admin" data-act="edit-host" data-id="${h.id}">✎ ${L('Bewerken')}</button>
        ${agentInfo ? `<span class="spacer"></span>${agentInfo}` : ''}
      </div>`;
}
function hostDetail(h, lines = null) {
  return `
    <div class="host-detail">
      ${detailBar(h)}
      ${uptimeLine(h, false)}
      ${lines ? checkLines(h, lines === 'large') : checkTiles(h)}
    </div>`;
}

// Blox: een tegel per server
/** Blox-tegel. Small: compacte tegel (naam, status, problemen), meer per rij; Large: met meters en details. */
/**
 * Endpoint-bewaking (1.110.0): ontbreekt er iets op deze server (auditd, inotify-tools), dan een regel onder de naam,
 * alleen voor beheerders. Klik: de Agent Manager, waar je het kunt laten installeren.
 */
const EDR_COMP = { auditd: ['auditd', () => L('nieuwe processen, kernelmodules en bewaakte paden')], inotify: ['inotify-tools', () => L('nieuwe bestanden in tijdelijke mappen en webmappen')],
  yara: ['YARA', () => L('nieuwe bestanden op malware controleren (optioneel)')] };
function edrNote(h) {
  if (!isAdmin() || h.mode !== 'agent' || !+(h.edr_on ?? 1) || !h.edr_info) return '';
  let i;
  try { i = JSON.parse(h.edr_info); } catch (e) { return ''; }
  // 1.123.0: per onderdeel te verbergen ("Niet meer tonen"); ontbreekt er later iets anders, dan komt het wel
  const hidden = edrHidden(h.id);
  const miss = [i.audit === 'missing' ? ['auditd', 'auditd'] : null, i.audit === 'stopped' ? ['auditd_stopped', L('auditd draait niet')] : null, i.file === 'missing' ? ['inotify', 'inotify-tools'] : null]
    .filter((m) => m && !hidden.includes(m[0]));
  if (!miss.length) return '';
  return `<button type="button" class="edr-note" data-act="edr-note" data-id="${h.id}" data-keys="${esc(miss.map((m) => m[0]).join(','))}" title="${esc(L('Klik: installeren of niet meer tonen'))}">🛡️ ${esc(L('ontbreekt: {what}', { what: miss.map((m) => m[1]).join(', ') }))} · ${L('installeren?')}</button>`;
}
const edrHidden = (id) => ((state && state.edr_note_hide) || {})[id] || [];
/**
 * Velden in de hele interface (1.126.0): de titel en de toelichting ernaast (bv. de eenheid "ms") op één regel, in plaats
 * van de toelichting op een eigen regel tussen titel en veld. Ook voor wat later geladen wordt (vensters, kaarten).
 */
function labelLines(root) {
  $$('label:not(.switch):not(.radio-row):not([data-ll])', root).forEach((l) => {
    l.dataset.ll = '1';
    const kids = [...l.childNodes];
    const ctl = kids.findIndex((n) => n.nodeType === 1 && (n.matches('input, select, textarea, .inline-row, .copy-line') || n.querySelector?.('input, select, textarea')));
    if (ctl < 1) return;
    const before = kids.slice(0, ctl);
    if (!before.some((n) => n.nodeType === 1 && n.matches('.hint, .muted.small, .muted'))) return;
    if (before.some((n) => n.nodeType === 1 && n.matches('.hint, .muted.small, .muted') && n.textContent.trim().length > 60)) return;   // lang: wordt in de instellingen een ⓘ
    if (before.some((n) => n.nodeType === 1 && n.matches('.lbl-line, div, p, br'))) return;
    const line = document.createElement('span');
    line.className = 'lbl-line';
    before.forEach((n) => line.append(n));
    l.prepend(line);
  });
}
(() => {
  let t = null;
  const run = () => labelLines(document.body);
  document.addEventListener('DOMContentLoaded', run);
  new MutationObserver(() => { clearTimeout(t); t = setTimeout(run, 20); }).observe(document.documentElement, { childList: true, subtree: true });
})();

/** Klik op de regel: installeren via de Agent Manager, of deze melding voor deze server niet meer tonen */
function edrNoteDialog(h, keys) {
  modal({
    title: '🛡️ ' + L('Endpoint-bewaking op {name}', { name: h.name }),
    body: `<p style="margin-top:0">${L('Op deze server ontbreekt iets voor de endpoint-bewaking. Zonder werkt de rest gewoon, maar ziet RzSense minder (bv. geen nieuwe processen zonder auditd).')}</p>
      <p class="muted small">${L('Niet meer tonen geldt voor deze server en voor wat nu ontbreekt. Weer tonen kan in de Agent Manager, bij Functies.')}</p>`,
    buttons: [{ label: '🙈 ' + L('Niet meer tonen'), style: 'margin-right:auto', onClick: async () => {
      try { await edrHideSave(h.id, [...new Set([...edrHidden(h.id), ...keys])]); $('#modal').close(); toast(L('Wordt niet meer getoond voor {name}', { name: h.name })); } catch (ex) { toast(ex.message, 'err'); }
    } }, { label: L('Sluiten'), onClick: () => $('#modal').close() }, { label: L('Installeren…'), cls: 'primary', onClick: () => agentInstructions(h) }],
  });
}
async function edrHideSave(id, list) {
  const all = { ...((state && state.edr_note_hide) || {}) };
  if (list.length) all[id] = list; else delete all[id];
  await api('settings_save', { edr_note_hide: JSON.stringify(all) });
  state.edr_note_hide = all;
  render();
}

function hostCard(h, small) {
  const p = hostParts(h);
  if (small) {
    return `
  <article class="${p.cls} tile tile-small">
    <div class="host-head" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${esc(h.address)} · ${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="dot"></span>
      <div class="host-title">
        <strong>${esc(h.name)}</strong>
        <span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.maint}${edrNote(h)}
      </div>
      <span class="counts">${p.counts}</span>
    </div>
    ${p.chips(3) ? `<div class="host-sum" data-act="toggle" data-id="${h.id}"><div class="chips">${p.chips(3)}</div></div>` : ''}
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
  }
  const keys = keyChecks(h);
  const restProb = h.checks.filter((c) => +c.enabled && +c.status !== 0 && !keys.includes(c)).length;
  return `
  <article class="${p.cls} tile${glowHost(h) ? ' glow' : ''}">
    <div class="host-head" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="host-ic" aria-hidden="true">${hostIcon(h)}</span>
      <div class="host-title">
        <strong>${esc(h.name)}${+h.enabled ? '' : ` <span class="badge">${L('uit')}</span>`}</strong>
        <span class="muted mono host-sub">${esc(h.address)} · ${isHa(h) ? 'Home Assistant' : esc(h.mode)}${h.mode === 'agent' && h.agent_version ? ' ' + esc(h.agent_version) + (+h.agent_key_seen === 1 ? ' 🔑' : '') : ''}</span>${p.via}
        ${p.maint}${edrNote(h)}
      </div>
      <span class="counts">${p.counts}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    <div class="host-sum" data-act="toggle" data-id="${h.id}">
      <div class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</div>
    </div>
    ${keys.length ? `<div class="key3">${keys.map(keyBox).join('')}</div>` : ''}
    <div class="tile-foot" data-act="toggle" data-id="${h.id}">
      <span class="muted small">${restProb ? `<b class="bad">${L('+{n} met een probleem', { n: restProb })}</b> · ` : ''}${p.lastChecked ? L('Laatste check') + ' ' + agoSpan(p.lastChecked) : ''}${h.mode === 'agent' ? ` · ${h.agent_seen ? contactHtml(h, 'short') : 'agent ' + L('nog niet gezien')}${agentVer(h)}` : ''}</span>
      ${hostTimeline(h)}
    </div>
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
}

/* 1.164.0: Monitoring in CC. Elke server is een compact blok; klik = het blok groeit naar de volle breedte en wordt
   een tabelrij, met daaronder de checks (iets ingesprongen, rechts gelijk). Meerdere tegelijk open; je opent en sluit
   ze zelf. Niet onthouden: weg van Monitoring (of herladen) en alles is weer dicht. */
const ccOpen = new Set();
const ccWord = (c) => (!+c.enabled ? L('uit') : c.maintenance ? L('Onderhoud') : [L('OK'), L('Warning'), L('Critical'), L('Onbekend')][+c.status] || '?');
/* 1.175.0: CC-monitoring in een nieuw jasje. Eén raster per soort apparaat (wat aandacht vraagt eerst, geen aparte
   sectie meer); een tegel met ring (checks in orde), twee lijntjes, de laatste 24 uur en hoe lang geleden. Klik = de server
   klapt open over de volle breedte, direct onder zijn rij (de rest van de rij schuift erboven), met meters, knoppen,
   wat aandacht vraagt en de checks die in orde zijn (ingeklapt). Openen en sluiten met een veeranimatie zoals op een Mac;
   hoeveel beweging volgt de instelling Beweging (Stil · Rustig · Vol). Geen tabellen meer: niets schuift naar opzij. */
const ccAllOk = new Set();   // servers waarvan alle checks in orde getoond worden
const CC_OK_SHOW = 8;
/** Ring: hoeveel checks in orde zijn (van de checks die aan staan) */
function ccRing(ok, total, st, size = 42, label = '') {
  const r = size / 2 - 5, C = 2 * Math.PI * r, f = total ? ok / total : 0;
  return `<svg class="ccx-ring s${st}" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" role="img" aria-label="${esc(label || L('{n} van {t} checks in orde', { n: ok, t: total }))}">
    <circle cx="${size / 2}" cy="${size / 2}" r="${r}" class="ccx-ring-bg"/><circle cx="${size / 2}" cy="${size / 2}" r="${r}" class="ccx-ring-fg" style="--len:${(C * f).toFixed(1)};--c:${C.toFixed(1)}" transform="rotate(-90 ${size / 2} ${size / 2})"/>
    <text x="50%" y="50%" dy=".35em" text-anchor="middle">${ok}</text></svg>`;
}
/** Meter in het geopende blok: ring met percentage */
function ccGauge(label, v, cls = '') {
  const C = 2 * Math.PI * 18, f = Math.max(0, Math.min(100, v)) / 100;
  return `<div class="ccx-gauge ${cls}"><svg width="46" height="46" viewBox="0 0 46 46" aria-hidden="true"><circle cx="23" cy="23" r="18" class="ccx-ring-bg"/><circle cx="23" cy="23" r="18" class="ccx-ring-fg" style="--len:${(C * f).toFixed(1)};--c:${C.toFixed(1)}" transform="rotate(-90 23 23)"/></svg>
    <span><small>${esc(label)}</small><b>${dec(Math.round(v))}%</b></span></div>`;
}
/** 1.193.0: ⏳ zolang een check nog bevestigt (de status wisselt pas na N controles) */
const ccPend = (c) => (c.pend_status != null && +c.pend_count > 0 && +c.enabled
  ? ` <span class="ccx-pend s${+c.pend_status}" title="${esc(L('Nog niet bevestigd: wordt mogelijk {s} ({n} keer gezien)', { s: STATUS_WORD[+c.pend_status] || '?', n: +c.pend_count }))}">⏳</span>` : '');
const ccTop = (h, type) => h.checks.filter((c) => c.type === type && +c.enabled && c.last_value !== null).sort((a, b) => b.last_value - a.last_value)[0] || null;
function ccHostCard(h) {
  const p = hostParts(h), st = h.maintenance ? 4 : p.st, u = stats.uptime[h.id], open = ccOpen.has(+h.id);
  const on = h.checks.filter((c) => +c.enabled && !c.maintenance), okN = on.filter((c) => +c.status === 0).length;
  const kind = isHa(h) ? 'Home Assistant' : isTnHost(h) ? 'TrueNAS' : isPveHost(h) ? 'Proxmox' : h.mode === 'agent' ? 'agent' : h.mode === 'remote' ? L('netwerk') : h.mode;
  const ver = h.mode === 'agent' && h.agent_version ? ' ' + esc(h.agent_version) : '';
  const last = p.lastChecked ? agoSpan(p.lastChecked) : '–';
  const up = u && u.h24 !== null ? pct(u.h24) : '–';
  const name = `${esc(h.name)}${+h.enabled ? '' : ` <span class="badge">${L('uit')}</span>`}`;
  const sparks = keyChecks(h, 2).map((c) => { const [v, un] = keyValue(c); return `<span class="ccx-sp s${c.maintenance ? 4 : +c.status}"><span class="ccx-sp-h"><small>${esc(c.name)}</small><b>${v}<small>${esc(un)}</small></b></span>${keySpark(c, 26)}</span>`; }).join('');
  // Geopend: wat aandacht vraagt (ook bevestigd), en wat in orde is (ingeklapt)
  const rank = (c) => (+c.enabled ? STATUS_RANK[c.maintenance ? 4 : +c.status] : -9);
  // 1.191.0: pad-checks staan in hun eigen sectie Netwerkpad (tussen Vraagt aandacht en In orde), niet in de lijsten
  const isPath = (c) => c.type === 'path' && +c.enabled && pathSum(c);
  const paths = h.checks.filter(isPath);
  const bad = h.checks.filter((c) => !isPath(c) && +c.enabled && !c.maintenance && +c.status !== 0).sort((a, b) => !!a.acked_at - !!b.acked_at || rank(b) - rank(a) || String(a.name).localeCompare(String(b.name)));
  const good = h.checks.filter((c) => !bad.includes(c) && !isPath(c)).sort((a, b) => rank(b) - rank(a) || String(a.name).localeCompare(String(b.name)));
  const badRows = bad.map((c, i) => {
    const s = +c.status, [v, un] = keyValue(c), msg = (c.last_message || '').replace(/\s+/g, ' ');
    return `<div class="ccx-row s${s}${c.acked_at ? ' acked' : ''}" data-cid="${c.id}" style="--i:${Math.min(i, 12)}" role="button" tabindex="0" title="${esc(L('Klik voor de details van deze check'))}">
      <span class="ccx-st"><i></i>${esc(ccWord(c))}${c.acked_at ? ` <span class="ccx-ack" title="${esc(L('Bevestigd'))}">✔</span>` : ''}${ccPend(c)}</span>
      <span class="ccx-cn"><span class="ccx-ti" aria-hidden="true">${checkIcon(c)}</span><span><b>${esc(c.name)}</b><small>${esc(typeOf(c).label || c.type)}</small></span></span>
      <b class="ccx-v" data-flash>${ctOf(c) || pathMini(c) || (un ? `${v}<small>${esc(un)}</small>` : '')}</b>
      <span class="ccx-msg">${esc(msg.slice(0, 220)) || '<span class="muted">–</span>'}</span>
      <span class="ccx-tl">${checkTimeline(c)}</span>
      <span class="ccx-act">${aiBtn('check', c.id)}${!c.acked_at ? `<button type="button" class="btn small-btn need-user" data-act="ack" data-id="${c.id}">✔ ${L('Bevestig')}</button>` : ''}</span>
    </div>`;
  }).join('');
  const goodTiles = good.map((c, i) => {
    const s = !+c.enabled ? 'off' : c.maintenance ? 4 : +c.status, [v, un] = keyValue(c), msg = (c.last_message || '').replace(/\s+/g, ' ');
    return `<div class="ccx-ok s${s}${i >= CC_OK_SHOW ? ' more' : ''}" data-cid="${c.id}" role="button" tabindex="0" title="${esc(c.name)}: ${esc(msg)}">
      <i class="ccx-dot"></i><span class="ccx-ok-t"><b>${esc(c.name)}${ccPend(c)}</b><small>${esc(msg.slice(0, 80)) || esc(ccWord(c))}</small></span>${keySpark(c, 22)}<b class="ccx-v" data-flash>${ctOf(c) || `${v}<small>${esc(un)}</small>`}</b></div>`;
  }).join('');
  const pathRows = paths.map((c) => {
    const s = pathSum(c), st = c.maintenance ? 4 : +c.status, quiet = (String(s.d || '').match(/h/g) || []).length;
    const sub = [L('{n} hops', { n: s.h }) + (quiet ? ' · ' + L('{n} stil', { n: quiet }) : ''), s.l !== undefined && s.l !== null ? L('{l}% verlies', { l: s.l }) : '', s.mb ? npMbit(s.mb) : ''].filter(Boolean);
    return `<div class="ccx-prow s${st}" data-act="np-go" data-id="${c.id}" role="button" tabindex="0" title="${esc(L('Klik: naar Monitoring → Netwerkpaden'))}${s.w ? ' · ' + esc(s.w) : ''}">
      <span class="ccx-st"><i></i>${esc(ccWord(c))}</span>
      <span class="ccx-cn"><span class="ccx-ti" aria-hidden="true">${checkIcon(c)}</span><span><b>→ ${esc(s.t || c.target)}</b><small>${esc(c.name)}</small></span></span>
      ${npDots(s.d, s.k)}
      <b class="ccx-pv mono">${npMs(s.ms)}</b>
      <span class="ccx-pm">${esc(sub[0])}${sub.length > 1 ? `<br>${esc(sub.slice(1).join(' · '))}` : ''}</span>
      <span class="ccx-tl">${checkTimeline(c)}</span><span class="ccx-go" aria-hidden="true">›</span></div>`;
  }).join('');
  const cpu = ccTop(h, 'cpu'), ram = ccTop(h, 'ram'), disk = ccTop(h, 'disk');
  const gauges = [cpu ? ccGauge('CPU', +cpu.last_value, 'cpu') : '', ram ? ccGauge('RAM', +ram.last_value, 'ram') : '', disk ? ccGauge(disk.name, +disk.last_value, 'disk') : '',
    u && u.h24 !== null ? ccGauge(L('Beschikbaar 24 u'), +u.h24, 'up') : ''].join('');
  const moreOk = Math.max(0, good.length - CC_OK_SHOW);
  return `<article class="hb cc2 s${st}${open ? ' open' : ''}${ccAllOk.has(+h.id) ? ' all-ok' : ''}${glowHost(h) && !ackedOnly(h) ? ' glow' : ''}${+h.enabled ? '' : ' disabled'}${ackedOnly(h) ? ' acked-only' : ''}" data-hid="${h.id}">
    <div class="hb-tile" data-act="cc-open" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${open}" title="${esc(L('Klik: open met alle checks'))}">
      <span class="ccx-scan" aria-hidden="true"></span>
      <div class="hb-th"><span class="host-ic" aria-hidden="true">${hostIcon(h)}</span>
        <div class="hb-tn"><strong>${name}<i class="ccx-pulse" aria-hidden="true"></i></strong><span class="muted mono hb-sub">${esc(h.address)}</span>${p.via}${p.maint}${edrNote(h)}</div>
        ${ccRing(okN, on.length, st)}</div>
      <div class="ccx-chips"><span class="ccx-pill">${esc(p.sum.text)}</span><span class="ccx-kind">${esc(kind)}${ver}</span></div>
      ${sparks ? `<div class="ccx-sps">${sparks}</div>` : ''}
      <div class="ccx-foot"><span class="ccx-foot-h"><small>${L('24 uur')} · ${up}</small><small>${last}</small></span>${hostTimeline(h)}</div>
      ${h.checks.some(isProblem) ? `<div class="ccx-tile-ai">${aiBtn('bundle', h.id)}</div>` : ''}
    </div>
    <div class="hb-ov">
      <div class="ccx-head">
        <span class="host-ic big" aria-hidden="true">${hostIcon(h)}</span>
        <div class="ccx-head-t"><div class="ccx-head-n"><h3>${name}</h3><span class="muted mono">${esc(h.address)}</span><span class="ccx-kind">${esc(kind)}${ver}</span></div>
          <div class="muted small">${p.via ? p.via + ' · ' : ''}${h.mode === 'agent' && !h.agent_seen ? 'agent ' + L('nog niet gezien') + ' · ' : ''}${L('{n} checks', { n: h.checks.length })} · ${L('laatste check')} ${last}${p.maint}</div></div>
        <div class="ccx-gauges">${gauges}</div>
        <button type="button" class="icon-btn ccx-x" data-act="cc-close" data-id="${h.id}" title="${esc(L('Sluiten'))}" aria-label="${esc(L('Sluiten'))}">✕</button>
      </div>
      <div class="hb-bar">${detailBar(h)}</div>
      <div class="ccx-body">
        ${bad.length ? `<div class="ccx-sec warn"><b>${L('Vraagt aandacht')}</b><span>${bad.length}</span></div><div class="ccx-rows">${badRows}</div>` : paths.some((c) => !c.maintenance && +c.status !== 0) || !h.checks.length ? '' : `<div class="ccx-allgood">✔ ${L('Alle checks in orde')}</div>`}
        ${paths.length ? `<div class="ccx-sec path"><b>${L('Netwerkpad')}</b><span>${paths.length}</span></div><div class="ccx-paths">${pathRows}</div>` : ''}
        ${good.length ? `<div class="ccx-sec ok"><b>${L('In orde')}</b><span>${good.length}</span></div><div class="ccx-oks">${goodTiles}</div>
          ${moreOk ? `<button type="button" class="ccx-more" data-act="cc-allok" data-id="${h.id}"><span class="ccx-more-a">＋ ${L('{n} andere checks tonen', { n: moreOk })}</span><span class="ccx-more-b">− ${L('Minder tonen')}</span></button>` : ''}` : ''}
        ${!h.checks.length ? `<p class="muted">${L('Nog geen checks.')}</p>` : ''}
      </div>
    </div>
  </article>`;
}
/** Klein icoon per soort check */
function checkIcon(c) {
  const p = { cpu: '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>', ram: '<rect x="3" y="7" width="18" height="10" rx="2"/><path d="M7 17v3M11 17v3M15 17v3M7 10v4M11 10v4M15 10v4"/>',
    disk: '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/>', ping: '<path d="M2 12h4l3-7 4 14 3-7h6"/>', http: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    tcp: '<path d="M8 3v4M16 3v4M5 7h14v4a7 7 0 0 1-14 0zM12 18v3"/>', ssl: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>', service: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2"/>',
    container: '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>', truenas: '<path d="M14 14.8V4a2 2 0 1 0-4 0v10.8a4 4 0 1 0 4 0z"/>' }[c.type]
    || '<path d="M4 12l5 5L20 6"/>';
  return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
}
/** 1.175.0: openen of sluiten met een veeranimatie (zoals een venster op een Mac): het geopende blok groeit vanuit de tegel
 *  naar de volle breedte, de inhoud komt er gestaffeld in; de andere tegels schuiven veerkrachtig naar hun nieuwe plek.
 *  Sluiten: het blok krimpt terug tot de tegel. Beweging volgt de instelling (Stil = meteen, Rustig = korter, geen staffeling). */
const CC_SPRING = 'cubic-bezier(.32, .72, 0, 1)';   // de veercurve van macOS/iOS
function ccToggle(id, on) {
  const el = document.querySelector(`.hb[data-hid="${+id}"]`);
  if (on) ccOpen.add(+id); else ccOpen.delete(+id);
  if (!el) return render();
  const m = afMotion(null);
  const grid = el.parentElement, els = [...grid.querySelectorAll(':scope > .hb')];
  const first = new Map(els.map((e) => [e, e.getBoundingClientRect()]));
  const from = first.get(el);
  const apply = () => {
    el.classList.toggle('open', on);
    const t = el.querySelector('.hb-tile');
    if (t) t.setAttribute('aria-expanded', String(on));
  };
  if (!m || !el.animate) { apply(); if (on) el.scrollIntoView({ block: 'nearest' }); return; }
  const dur = m === 1 ? 380 : 620;
  const slide = () => els.forEach((e) => {   // de andere tegels naar hun nieuwe plek
    if (e === el) return;
    const a = first.get(e), b = e.getBoundingClientRect();
    if (Math.abs(a.left - b.left) < 1 && Math.abs(a.top - b.top) < 1) return;
    e.animate([{ transform: `translate(${a.left - b.left}px, ${a.top - b.top}px)` }, { transform: 'none' }], { duration: dur, easing: CC_SPRING });
  });
  if (on) {
    apply();
    const to = el.getBoundingClientRect();
    // Groeien vanuit de tegel: het blok begint op de plek en grootte van de tegel en veert open
    const clip = `inset(${Math.max(0, from.top - to.top)}px ${Math.max(0, to.right - from.right)}px ${Math.max(0, to.bottom - from.bottom)}px ${Math.max(0, from.left - to.left)}px round 16px)`;
    el.animate([{ clipPath: clip, transform: 'scale(.985)', boxShadow: '0 0 0 rgba(0,0,0,0)' }, { clipPath: 'inset(0 0 0 0 round 18px)', transform: 'none' }],
      { duration: dur, easing: CC_SPRING });
    const ov = el.querySelector('.hb-ov');
    if (ov) ov.animate([{ opacity: 0, filter: 'blur(4px)' }, { opacity: 1, filter: 'none' }], { duration: dur * .7, easing: 'ease-out', delay: dur * .12, fill: 'backwards' });
    if (m === 2) $$('.ccx-gauge, .ccx-row, .ccx-ok:not(.more)', el).forEach((x, i) => x.animate([{ opacity: 0, transform: 'translateY(8px) scale(.98)' }, { opacity: 1, transform: 'none' }],
      { duration: 420, easing: CC_SPRING, delay: 140 + Math.min(i, 16) * 28, fill: 'backwards' }));
    slide();
    setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), dur * .6);
    return;
  }
  // Sluiten: terug krimpen naar waar de tegel komt, dan dicht
  const toRect = (() => { apply(); const r = el.getBoundingClientRect(); el.classList.add('open'); return r; })();
  const big = el.getBoundingClientRect();
  const clip = `inset(${Math.max(0, toRect.top - big.top)}px ${Math.max(0, big.right - toRect.right)}px ${Math.max(0, big.bottom - toRect.bottom)}px ${Math.max(0, toRect.left - big.left)}px round 16px)`;
  const ov = el.querySelector('.hb-ov');
  if (ov) ov.animate([{ opacity: 1 }, { opacity: 0, filter: 'blur(3px)' }], { duration: dur * .45, easing: 'ease-in', fill: 'forwards' });
  const a = el.animate([{ clipPath: 'inset(0 0 0 0 round 18px)' }, { clipPath: clip, transform: 'scale(.99)' }], { duration: dur * .7, easing: CC_SPRING });
  a.onfinish = () => {
    const before = new Map(els.map((e) => [e, e.getBoundingClientRect()]));
    els.forEach((e) => first.set(e, before.get(e)));
    apply();
    if (ov) ov.getAnimations().forEach((x) => x.cancel());
    el.animate([{ transform: 'scale(.96)', opacity: .6 }, { transform: 'none', opacity: 1 }], { duration: dur * .8, easing: CC_SPRING });
    slide();
  };
}
/** "In orde": alles tonen of weer de eerste acht (zonder alles opnieuw te tekenen) */
function ccAllOkToggle(id) {
  const el = document.querySelector(`.hb[data-hid="${+id}"]`);
  const on = !ccAllOk.has(+id);
  if (on) ccAllOk.add(+id); else ccAllOk.delete(+id);
  if (!el) return render();
  el.classList.toggle('all-ok', on);
  if (on && afMotion(null) && el.animate) $$('.ccx-ok.more', el).forEach((x, i) => x.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
    { duration: 360, easing: CC_SPRING, delay: Math.min(i, 30) * 14, fill: 'backwards' }));
}

// L1nes: een balk over de volle breedte per server, als een tabel (Small of Large)
function hostLine(h, large) {
  const p = hostParts(h);
  const keys = keyChecks(h);
  const u = stats.uptime[h.id];
  const agent = h.mode === 'agent' ? `${h.agent_seen ? contactHtml(h, 'short') : 'agent ' + L('nog niet gezien')}${agentVer(h)}` : '';
  const cells = large
    ? `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.chips(5) ? `<span class="chips">${p.chips(5)}</span>` : ''}</span>
       ${[0, 1, 2].map((i) => `<span class="lc-k">${keys[i] ? keyBox(keys[i], true) : ''}</span>`).join('')}
       <span class="lc-up" title="${L('Status per uur (24 uur) en bereikbaar afgelopen 24 uur')}">${hostTimeline(h)}<span class="muted small">${u && u.h24 !== null ? pct(u.h24) : ''}</span></span>
       <span class="lc-last muted small">${p.lastChecked ? agoSpan(p.lastChecked) : ''}${agent ? `<br>${agent}` : ''}</span>`
    : `<span class="lc-status"><span class="sum-line key-txt">${keys.length ? keys.map(keyText).join(' · ') : esc(p.sum.text)}</span></span>
       <span class="counts">${hostTimeline(h)}</span>`;
  return `
  <article class="${p.cls} line ${large ? 'large' : 'small'}${glowHost(h) ? ' glow' : ''}">
    <div class="line-row" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="dot"></span>
      <span class="lc-name"><strong><span class="lc-ic" aria-hidden="true">${hostIcon(h)}</span> ${p.title}</strong>${large ? `<span class="muted mono small">${esc(h.address)}</span>${p.via}${p.maint}` : ''}${edrNote(h)}</span>
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
    <span class="lc-k">${L('Belangrijkste checks')}</span><span class="lc-k"></span><span class="lc-k"></span><span class="lc-up">${L('Status 24 u')}</span><span class="lc-last">${L('Laatste check')}</span><span></span></div>`;
}

/** Wat een check-tegel (Blox) en een check-rij (L1nes) allebei tonen. */
function checkBits(c) {
  const t = typeOf(c);
  const s = +c.status;
  const pctVal = t.unit === '%' && c.last_value !== null ? Math.min(100, +c.last_value) : null;
  const ticks = (pctVal !== null ? (c.warn_threshold !== null ? `<b style="left:${+c.warn_threshold}%"></b>` : '')
    + (c.crit_threshold !== null ? `<b style="left:${+c.crit_threshold}%"></b>` : '') : '');
  const num = (['ms', 'dagen', 'MB', 'treffers', 'stuks'].includes(t.unit) || (c.type === 'snmp' && t.unit !== null && t.unit !== '%')) && c.last_value !== null
    ? (t.unit === 'dagen' && c.type !== 'ha' ? Math.floor(c.last_value) + ' ' + L('dagen') : dec(fmt(c.last_value, t.unit ? L(t.unit) : t.unit))) : '';
  const own = c.maintenance && c.maintenance.scope === 'check';
  const problem = s !== 0 && +c.enabled;
  const ackTitle = c.acked_at ? `${L('Bevestigd door {who}', { who: c.acked_by || '?' })} – ${ago(c.acked_at)}${c.ack_comment ? ': ' + c.ack_comment : ''}. ${L('Klik om in te trekken.')}` : '';
  // Nog niet gemeld: wacht op meer mislukte checks achter elkaar (melden na N)
  const need = +c.notify_fails || +state.notify_fails || 1;
  const pending = problem && !c.acked_at && !c.maintenance && +c.notified_status !== s && need > 1 && +c.status_streak < need
    ? ` <span class="pend-tag" title="${esc(L('Nog niet gemeld: een melding volgt na {need} mislukte checks achter elkaar met deze status (nu {n})', { need, n: +c.status_streak }))}">⏳ ${+c.status_streak}/${need}</span>` : '';
  // 1.185.0: nieuwe status nog niet bevestigd (status pas na N controles)
  const ownN = (v) => (v === null || v === undefined || v === '' ? null : +v);
  const tNeed = (state.confirm_types || {})[c.type] || {};   // 1.193.0: per soort
  const cNeed = +c.pend_status === 0 ? (ownN(c.confirm_ok) ?? ownN(tNeed.o) ?? (+state.confirm_ok || 1)) : (ownN(c.confirm_fails) ?? ownN(tNeed.f) ?? (+state.confirm_fails || 1));
  const confirming = c.pend_status != null && +c.pend_count > 0 && +c.enabled
    ? ` <span class="pend-tag conf-tag" title="${esc(L('Nog niet bevestigd: wordt {s} na {need} controles achter elkaar (nu {n})', { s: STATUS_WORD[+c.pend_status] || '?', need: cNeed, n: +c.pend_count }))}">⏳ ${esc(STATUS_WORD[+c.pend_status] || '?')} ${+c.pend_count}/${cNeed}</span>` : '';
  const flags = confirming + pending + (problem && c.acked_at ? ` <button class="ack-tag need-user-act" data-act="unack" data-id="${c.id}" title="${esc(ackTitle)}">✔ ${L('bevestigd')}</button>` : '')
    + (own ? ` <span class="maint-tag" title="${esc(maintLabel(c.maintenance))}">${c.maintenance.auto ? '🔧 ' + L('auto, tot opgelost') : `🔧 ${L('tot {t}', { t: esc(fmtUntil(c.maintenance.until)) })}`}</span>` : '')
    + (c.suppressed_by ? ` <span class="supp-tag" title="${L('Geen melding: een server erboven is onbereikbaar')}">🔕 ${L('onderdrukt')}</span>` : '')
    + (+c.info_only ? ` <span class="info-tag" title="${L('Info-check: geeft alleen ℹ️ info-meldingen en telt nooit als storing')}">ℹ️ ${c.info_status ? STATUS_WORD[c.info_status] : 'info'}</span>` : '')
    + (c.notify ? ` <span class="own-notify" title="${L('Deze check heeft eigen meldregels')}">🔔</span>` : '')
    + (c.reverse_ok != null && REVERSIBLE.includes(c.type) ? ` <span class="rev-tag" title="${L('Omgekeerd: reageert het, dan {a}; reageert het niet, dan {b}', { a: STATUS_WORD[c.reverse_ok], b: STATUS_WORD[c.reverse_fail ?? 0] })}">⇄</span>` : '')
    + quietTag(c.quiet_eff)
    + (c.flapping_since ? ` <span class="flap-tag" title="${L('Wisselt steeds van status sinds {t}: per status hooguit één melding tot hij stabiel is', { t: esc(fmtUntil(c.flapping_since)) })}">🔀 ${L('flapt')}</span>` : '');
  const actions = `
      ${problem ? aiBtn('check', c.id) : ''}
      ${problem && !c.acked_at ? `<button class="icon-btn ack-btn need-user" title="${L('Storing bevestigen: geen meldingen meer')}" data-act="ack" data-id="${c.id}">✔</button>` : ''}
      <button class="icon-btn need-user" title="${L('Onderhoud')}" data-act="maint-check" data-id="${c.id}">🔧</button>
      <button class="icon-btn need-user" title="${L('Nu stil (geen meldingen)')}" data-act="quiet-check" data-id="${c.id}">🌙</button>
      <button class="icon-btn need-user" title="${L('Nu checken')}" data-act="run" data-id="${c.id}">↻</button>
      <button class="icon-btn" title="${L('Historie')}" data-act="history" data-id="${c.id}">📈</button>
      <button class="icon-btn need-admin" title="${L('Bewerken')}" data-act="edit-check" data-id="${c.id}">✎</button>`;
  return {
    t, s, pctVal, ticks, num, problem, flags, actions,
    cls: `${c.info_status ? 's-info' : 's' + s} ${+c.enabled ? '' : 'disabled'} ${c.maintenance ? 'in-maint' : ''} ${problem && c.acked_at ? 'acked' : ''}`,
    title: `${c.info_status ? 'ℹ️ ' + STATUS[c.info_status] : STATUS[s]} – ${L('laatst')} ${ago(c.last_checked)}`,
    msg: esc(c.last_message || L('Nog niet gecontroleerd')),
  };
}

// Blox: een check als tegel
function checkRow(c) {
  const b = checkBits(c);
  const bar = b.pctVal !== null ? `<div class="bar" title="${fmt(b.pctVal, '%')}"><i style="width:${b.pctVal}%"></i>${b.ticks}</div>` : '';
  return `
  <li class="chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
    <span class="dot"></span>
    <span class="chk-name">${esc(c.name)}<span class="type">${b.t.label}${ctOf(c) ? ' ' + ctOf(c) : b.num ? ` · <span data-flash>${b.num}</span>` : ''}</span>${b.flags}</span>
    <span class="chk-actions">${b.actions}
    </span>
    ${bar}
    ${sparkSvg(c)}
    <span class="chk-msg">${b.msg} · ${agoSpan(c.last_checked)}</span>
  </li>`;
}

// Blox: checks als tegels; problemen bovenaan, de rest in groepen (zie groupChecks)
function checkTiles(h) {
  if (!h.shown.length) return `<ul class="checks"><li class="empty small">${L('Nog geen checks — klik op ＋ Check')}</li></ul>`;
  const probs = h.shown.filter(isProblem);
  const top = probs.length ? `
    <section class="chk-group grp-problems${problemsClosed(h, probs) ? ' closed' : ''}">
      ${groupHead(h, 'problems', '⚠️ ' + L('Problemen'), probs, 'div')}
      <div class="prob-body">${problemGroups(probs).map(([s, title, list]) => `
        <div class="prob-sub s${s}">${title} <span class="muted">(${list.length})</span></div>
        <ul class="checks">${list.map(checkRow).join('')}</ul>`).join('')}</div>
    </section>` : '';
  const rest = h.shown.filter((c) => !isProblem(c));
  const groups = groupChecks(rest);
  if (h.shown.length < GROUP_MIN) return `<div class="chk-groups">${top}${rest.length ? `<ul class="checks">${groups.flatMap((g) => g[2]).map(checkRow).join('')}</ul>` : ''}</div>`;
  return `<div class="chk-groups">${top}${groups.map(([k, title, list]) => `
    <section class="chk-group${grpClosed[`${h.id}:${k}`] ? ' closed' : ''}">
      ${groupHead(h, k, title, list, 'div')}
      <ul class="checks">${list.map(checkRow).join('')}</ul>
    </section>`).join('')}</div>`;
}

// L1nes: checks als rijen onder de server, in dezelfde groepen
function checkLines(h, large) {
  if (!h.shown.length) return `<p class="empty small check-lines-empty">${L('Nog geen checks — klik op ＋ Check')}</p>`;
  const probs = h.shown.filter(isProblem);
  const rest = h.shown.filter((c) => !isProblem(c));
  const groups = groupChecks(rest);
  const head = large ? `<div class="cl-head" aria-hidden="true"><span></span><span>Check</span><span>${L('Waarde')}</span><span class="cl-spark">${L('24 uur')}</span>
    <span>${L('Bericht')}</span><span class="cl-last">${L('Laatst')}</span><span></span></div>` : '';
  const top = probs.length ? `<div class="cl-group grp-problems${problemsClosed(h, probs) ? ' closed' : ''}">
        ${groupHead(h, 'problems', '⚠️ ' + L('Problemen'), probs, 'div')}
        <div class="cl-group-body">${problemGroups(probs).map(([s, title, list]) => `<div class="prob-sub s${s}">${title} <span class="muted">(${list.length})</span></div>
          ${list.map((c) => checkLine(c, large)).join('')}`).join('')}</div></div>` : '';
  const body = top + (h.shown.length < GROUP_MIN ? groups.flatMap((g) => g[2]).map((c) => checkLine(c, large)).join('')
    : groups.map(([k, title, list]) => `<div class="cl-group${grpClosed[`${h.id}:${k}`] ? ' closed' : ''}">
        ${groupHead(h, k, title, list, 'div')}
        <div class="cl-group-body">${list.map((c) => checkLine(c, large)).join('')}</div></div>`).join(''));
  return `<div class="check-lines ${large ? 'large' : 'small'}">${head}${body}</div>`;
}

function checkLine(c, large) {
  const b = checkBits(c);
  const value = ctOf(c) ? ctOf(c) : pathMini(c) ? pathMini(c) : b.pctVal !== null
    ? `<span class="cl-meter" title="${fmt(b.pctVal, '%')}"><span class="bar"><i style="width:${b.pctVal}%"></i>${b.ticks}</span><b data-flash>${Math.round(b.pctVal)}%</b></span>`
    : b.num ? `<span class="cl-num" data-flash>${b.num}</span>` : '<span class="muted">–</span>';
  return `
  <div class="cl chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
    <span class="dot"></span>
    <span class="cl-name"><span class="cl-title">${esc(c.name)}</span>${large ? `<span class="type">${b.t.label}</span>` : ` <span class="type">${b.t.label}</span>`}${b.flags ? `<span class="cl-flags">${b.flags}</span>` : ''}</span>
    <span class="cl-value">${value}</span>
    ${large ? `<span class="cl-spark">${sparkSvg(c) || '<span class="muted small">–</span>'}</span>` : ''}
    <span class="cl-msg">${b.msg}</span>
    <span class="cl-last muted small">${agoSpan(c.last_checked)}</span>
    <span class="chk-actions">${b.actions}
    </span>
  </div>`;
}

function findHost(id) { return state.hosts.find((h) => +h.id === +id); }
/** 1.177.0: keuzelijst voor tussenliggende apparaten (servers en apparaten uit de netwerkscan met een IP-adres) */
function pathViaOptions(h) {
  const m = new Map();
  state.hosts.forEach((x) => { if (x.address && +x.id !== +h.id) m.set(x.address, x.name); });
  ((typeof netData !== 'undefined' && netData && netData.devices) || []).forEach((x) => { if (x.ip && !m.has(x.ip)) m.set(x.ip, x.name || x.hostname || x.ip); });
  return [...m.entries()].map(([ip, n]) => `<option value="${esc(ip)}">${esc(n)}</option>`).join('');
}
/** 1.176.0: label Running/Failed/Stopped/Paused bij een container-check (leeg bij andere checks) */
const ctOf = (c) => (c && c.type === 'container' && c.cstate && (!c.metric || c.metric === 'status') ? ctLabel(c.cstate) : '');
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
    case 'edr-am': agentInstructions(findHost(id)); return;
    case 'edr-note': edrNoteDialog(findHost(id), (b.dataset.keys || '').split(',').filter(Boolean)); return;
    case 'grp': {
      // Groep in- of uitklappen (zonder alles opnieuw op te bouwen)
      const key = `${id}:${b.dataset.g}`;
      // Problemen: onthouden welke checks er bij het inklappen in stonden (een nieuw probleem klapt weer open)
      const closing = !b.parentElement.classList.contains('closed');
      if (closing) grpClosed[key] = b.dataset.sig ?? true; else delete grpClosed[key];
      try { localStorage.setItem('rzmon-grp-closed', JSON.stringify(grpClosed)); } catch (ex) { /* alleen voor nu */ }
      b.parentElement.classList.toggle('closed', closing);
      b.setAttribute('aria-expanded', String(!closing));
      $('.grp-chev', b).textContent = closing ? '▸' : '▾';
      return;
    }
    case 'add-check': return checkForm(null, findHost(id));
    case 'edit-host': return hostForm(findHost(id));
    case 'edit-check': { const [c, h] = findCheck(id); return checkForm(c, h); }
    case 'history': { const [c, h] = findCheck(id); return showHistory(c, h); }
    case 'agent': return agentInstructions(findHost(id));
    case 'logs': return openLogsFor(findHost(id));   // 1.211.0
    case 'discover': return discoverDialog(findHost(id));
    case 'scan': return portScan(findHost(id));
    case 'snmp': return snmpChecks(findHost(id));
    case 'cam-rec': return cameraRecommended(findHost(id));
    case 'podman': return podmanOverview(findHost(id));
    case 'pve': return pveOverview(findHost(id));
    case 'truenas': return tnOverview(findHost(id));   // 1.174.0
    case 'toggle': return toggleHost(id);
    case 'cc-open': return ccToggle(id, !ccOpen.has(+id));
    case 'cc-close': return ccToggle(id, false);
    case 'cc-allok': return ccAllOkToggle(id);   // 1.175.0
    case 'np-go': return npGo(id);   // 1.191.0
    case 'ack': { const [c, h] = findCheck(id); return ackDialog('check', id, `${h.name} – ${c.name}`, c.last_message); }
    case 'ack-host': { const h = findHost(id); const n = h.checks.filter((c) => +c.enabled && +c.status !== 0 && !c.acked_at).length; return ackDialog('host', id, h.name, L('{n} onbevestigde storing(en)', { n })); }
    case 'unack':
      if (!confirm(L('Bevestiging intrekken? Er worden dan weer meldingen verstuurd voor deze storing.'))) return;
      try { await api('unack', { id }); toast(L('Bevestiging ingetrokken')); refresh(); } catch (ex) { toast(ex.message, 'err'); }
      return;
    case 'quiet-host': { const h = findHost(id); return quietNowDialog('host', id, h.name, h.quiet_eff); }   // 1.189.0
    case 'quiet-check': { const [c, h] = findCheck(id); return quietNowDialog('check', id, `${h.name} – ${c.name}`, c.quiet_eff); }
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

