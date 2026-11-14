// ---------------------------------------------------------------- 1.264.0: statistieken bovenaan de pagina Reverse proxy
// Wat er gebeurde: verzoeken, doorgelaten en tegengehouden (aantal en %), waardoor tegengehouden, statuscodes, de meest
// aangevallen paden, sites, landen en IP's, en een grafiek. 24 uur, 7 of 30 dagen; alle sites of één. Tellingen komen van
// de agent (1.102.0) op de proxyserver; de rest (WAF, IDS, ClamAV, Suricata) had RzSense al.

let pxsPeriod = '24h', pxsSite = '', pxsSeq = 0;
try { pxsPeriod = localStorage.getItem('rzs-pxs-period') || '24h'; } catch (ex) { /* geen opslag */ }

const PXS_REASONS = () => ({
  waf: [1, L('WAF'), L('aanvalspatronen in het verzoek (SQL-injectie, XSS …)')],
  vpatch: [2, L('Dichtgezette paden'), L('virtual patching: bekende lekken afgeschermd')],
  lan: [3, L('Alleen eigen netwerk'), L('adminpagina\'s die alleen vanaf je netwerk mogen')],
  bots: [4, L('Slechte bots'), L('scanners zoals sqlmap, nikto, nuclei')],
  rate: [5, L('Rate limit'), L('te veel verzoeken achter elkaar (429)')],
  nohost: [6, L('Onbekende hostnaam'), L('verzoeken op je IP-adres zonder bekende site (444)')],
  other: [7, L('Overig 403'), L('geweigerd door de app zelf of een andere regel')],
});

const pxsSiteName = (s) => (s === '-' ? `<span class="muted">${L('zonder hostnaam')}</span>` : esc(s).replace(/\./g, '.<wbr>'));
const pxsNum = (n) => (+n || 0).toLocaleString(LOCALE);
const pxsPct = (p) => `${dec((+p || 0).toFixed((+p || 0) >= 10 || +p === 0 ? 0 : 1))}%`;
/** Verschil met de vorige periode: ↑/↓ in % (of in procentpunten met pp) */
function pxsDelta(cur, prev, { pp = false, goodUp = true, neutral = false } = {}) {
  if (pp) {
    const d = Math.round(((+cur || 0) - (+prev || 0)) * 10) / 10;
    if (!prev && !cur) return '';
    if (Math.abs(d) < 0.1) return `<span class="pxs-d">= ${L('vorige periode')}</span>`;
    return `<span class="pxs-d ${(d > 0) === goodUp ? 'up' : 'down'}">${d > 0 ? '↑' : '↓'} ${dec(Math.abs(d).toFixed(1))} pp</span>`;
  }
  if (!prev) return cur ? `<span class="pxs-d">${L('nieuw')}</span>` : '';
  const r = Math.round(((cur - prev) / prev) * 100);
  if (!r) return `<span class="pxs-d">= ${L('vorige periode')}</span>`;
  return `<span class="pxs-d ${neutral ? '' : (r > 0) === goodUp ? 'up' : 'down'}">${r > 0 ? '↑' : '↓'} ${pxsNum(Math.abs(r))}%</span>`;
}

function pxsTile(icon, label, value, sub, title) {
  return `<div class="pxs-tile" ${title ? `title="${esc(title)}"` : ''}><span class="pxs-l">${icon} ${label}</span><b class="pxs-v">${value}</b><span class="pxs-s">${sub || ''}</span></div>`;
}

/** Gestapelde balk + legenda (kleur volgt de reden, nooit de rang) */
function pxsReasons(d) {
  const R = PXS_REASONS();
  const rows = (d.reasons || []).slice().sort((a, b) => R[a.k][0] - R[b.k][0]);
  if (!rows.length) return `<p class="small muted">${L('Niets tegengehouden in deze periode.')}</p>`;
  const total = rows.reduce((s, r) => s + r.n, 0);
  const bar = rows.map((r) => `<span class="pxs-seg pxs-c${R[r.k][0]}" style="flex-grow:${r.n}" title="${esc(`${R[r.k][1]}: ${pxsNum(r.n)} (${pxsPct(r.pct)})`)}"></span>`).join('');
  const legend = rows.sort((a, b) => b.n - a.n).map((r) => `<li title="${esc(R[r.k][2])}"><i class="pxs-sw pxs-c${R[r.k][0]}"></i><span>${R[r.k][1]}</span><b>${pxsNum(r.n)}</b><span class="muted">${pxsPct(r.pct)}</span></li>`).join('');
  return `<div class="pxs-bar" role="img" aria-label="${esc(L('Tegengehouden door: {n} in totaal', { n: pxsNum(total) }))}">${bar}</div><ul class="pxs-legend">${legend}</ul>`;
}

function pxsStatus(d) {
  const s = d.status || {}, p = d.status_pct || {};
  const ks = [['2xx', 'ok', L('gewoon antwoord')], ['3xx', 'neutral', L('doorgestuurd')], ['4xx', 'warn', L('geweigerd of niet gevonden')], ['5xx', 'crit', L('fout in de app')]];
  if (!(d.tiles && d.tiles.total.n)) return '';
  return `<div class="pxs-bar slim">${ks.filter(([k]) => s[k]).map(([k, c, t]) => `<span class="pxs-seg st-${c}" style="flex-grow:${s[k]}" title="${esc(`${k} (${t}): ${pxsNum(s[k])} · ${pxsPct(p[k])}`)}"></span>`).join('')}</div>
    <ul class="pxs-legend inline">${ks.map(([k, c, t]) => `<li title="${esc(t)}"><i class="pxs-sw st-${c}"></i><span>${k}</span><b>${pxsPct(p[k])}</b><span class="muted">${pxsNum(s[k])}</span></li>`).join('')}</ul>
    ${(+p['5xx'] || 0) >= 2 ? `<p class="small warn-txt">⚠ ${L('{p} van de verzoeken gaf een fout in een app (5xx). Kijk bij Sites welke.', { p: pxsPct(p['5xx']) })}</p>` : ''}`;
}

/** Staafjes per uur of per dag: doorgelaten en tegengehouden; tooltip per staaf */
function pxsChart(d) {
  const c = d.chart || [];
  if (!c.some((x) => x.passed || x.blocked)) return '';
  const max = Math.max(1, ...c.map((x) => x.passed + x.blocked));
  const W = 100 / c.length;
  const bars = c.map((x, i) => {
    const hp = (x.passed / max) * 100, hb = (x.blocked / max) * 100;
    const lbl = d.period === '24h' ? x.b.slice(11, 16) : new Date(x.b + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });
    const tip = `${lbl}: ${L('{a} doorgelaten', { a: pxsNum(x.passed) })} · ${L('{b} tegengehouden', { b: pxsNum(x.blocked) })}`;
    return `<g><title>${esc(tip)}</title><rect x="${i * W}" y="0" width="${W}" height="100" fill="transparent"/>`
      + `<rect class="pxs-cp" x="${i * W + W * 0.15}" y="${100 - hp - hb}" width="${W * 0.7}" height="${Math.max(0, hp)}" rx="0.6"/>`
      + `<rect class="pxs-cb" x="${i * W + W * 0.15}" y="${100 - hb}" width="${W * 0.7}" height="${Math.max(0, hb)}" rx="0.6"/></g>`;
  }).join('');
  const first = c[0].b, last = c[c.length - 1].b;
  const fl = (b) => (d.period === '24h' ? b.slice(11, 16) : new Date(b + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }));
  return `<div class="pxs-chart"><svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label="${esc(L('Verzoeken per {u}', { u: d.period === '24h' ? L('uur') : L('dag') }))}">${bars}</svg>
    <div class="pxs-axis"><span>${esc(fl(first))}</span><span>${L('hoogste: {n}', { n: pxsNum(max) })}</span><span>${esc(fl(last))}</span></div>
    <ul class="pxs-legend inline"><li><i class="pxs-sw cp"></i><span>${L('Doorgelaten')}</span></li><li><i class="pxs-sw cb"></i><span>${L('Tegengehouden')}</span></li></ul></div>`;
}

function pxsTop(d) {
  const mini = (p) => `<span class="pxs-mini"><i style="width:${Math.min(100, +p || 0)}%"></i></span>`;
  // Compacte tabellen (ook op een telefoon een tabel, geen kaartjes): de site klein onder het pad
  const paths = (d.paths || []).length ? `<table class="pxs-t no-stack"><thead><tr><th>${L('Pad')} <span class="muted small">· ${L('Site')}</span></th><th class="r">${L('Hits')}</th><th class="r">${L('Tegengehouden')}</th></tr></thead><tbody>
      ${d.paths.map((r) => `<tr><td><code>${esc(r.path)}</code><div class="muted small">${pxsSiteName(r.site)}</div></td><td class="r">${pxsNum(r.hits)}</td><td class="r">${mini(r.pct)} ${pxsPct(r.pct)}</td></tr>`).join('')}</tbody></table>`
    : `<p class="small muted">${L('Geen aangevallen paden in deze periode.')}</p>`;
  const sites = (d.top_sites || []).length ? `<table class="pxs-t no-stack"><thead><tr><th>${L('Site')}</th><th class="r">${L('Verzoeken')}</th><th class="r pxs-err">${L('Fouten')}</th><th class="r">${L('Tegengehouden')}</th></tr></thead><tbody>
      ${d.top_sites.map((r) => `<tr><td>${pxsSiteName(r.site)}${r.err5_pct >= 2 ? ` <span class="lf-tag bad" title="${esc(L('{p} fouten in de app (5xx)', { p: pxsPct(r.err5_pct) }))}">5xx</span>` : ''}</td><td class="r">${pxsNum(r.total)}</td><td class="r pxs-err">${pxsPct(r.err_pct)}</td><td class="r">${mini(r.blocked_pct)} ${pxsPct(r.blocked_pct)}</td></tr>`).join('')}</tbody></table>`
    : `<p class="small muted">${L('Nog geen verzoeken geteld.')}</p>`;
  const cc = (d.countries || []).length ? `<ul class="pxs-list">${d.countries.map((r) => `<li><span>${r.cc === '??' ? '🌐 ' + L('onbekend') : `${ccFlagOf(r.cc)} ${esc(r.cc)}`}</span>${mini(r.pct)}<b>${pxsPct(r.pct)}</b><span class="muted">${pxsNum(r.hits)}</span></li>`).join('')}</ul>`
    : `<p class="small muted">${L('Geen aanvallers in deze periode.')}</p>`;
  const ips = (d.ips || []).length ? `<ul class="pxs-list">${d.ips.map((r) => `<li><code>${esc(r.ip)}</code><span>${r.cc ? ccFlagOf(r.cc) : ''}</span><b>${pxsNum(r.hits)}</b><span>${r.blocked ? `<span class="lf-tag bad" title="${esc(L('nu geblokkeerd'))}">⛔</span>` : `<span class="muted small">${L('open')}</span>`}</span></li>`).join('')}</ul>` : '';
  return `<div class="pxs-paths"><h4>${L('Meest aangevallen paden')}</h4>${paths}</div>
    <div class="pxs-grid">
    <div><h4>${L('Sites')}</h4>${sites}</div>
    <div><h4>${L('Landen van aanvallers')}</h4>${cc}${ips ? `<h4>${L('Meest actieve IP\'s')}</h4>${ips}` : ''}</div></div>`;
}

function pxsRender(d) {
  const box = $('#pxs-box');
  if (!box) return;
  const t = d.tiles || {};
  const per = { '24h': L('24 uur'), '7d': L('7 dagen'), '30d': L('30 dagen') };
  const head = `<div class="pxs-head"><div class="seg" role="group" aria-label="${esc(L('Periode'))}">${Object.entries(per).map(([k, v]) => `<button type="button" data-pxs-period="${k}" class="seg-btn ${k === d.period ? 'active' : ''}" aria-pressed="${k === d.period}">${v}</button>`).join('')}</div>
    <select id="pxs-site" aria-label="${esc(L('Site'))}"><option value="">${L('Alle sites')}</option>${(d.sites || []).map((s) => `<option value="${esc(s)}" ${s === d.site ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select></div>`;
  if (!d.hosts) { box.innerHTML = ''; return; }
  const none = !d.has_data ? `<div class="callout small">📊 ${L('Nog geen tellingen van het proxylog. Werk de agent op de proxyserver bij naar 1.102.0 of nieuwer, en zet het proxylog aan bij Instellingen → IDS → Netwerk en aanvallers (SWAG: het logbestand rzsense.log). Wat RzSense al weet (WAF, aanvallers) staat hieronder.')}</div>` : '';
  const ex = d.extra;
  const tiles = `<div class="pxs-tiles">
    ${pxsTile('📨', L('Verzoeken'), pxsNum(t.total.n), pxsDelta(t.total.n, t.total.prev, { neutral: true }))}
    ${pxsTile('✅', L('Doorgelaten'), pxsPct(t.passed.pct), `${pxsNum(t.passed.n)} ${pxsDelta(t.passed.pct, t.passed.prev_pct, { pp: true })}`)}
    ${pxsTile('🛡️', L('Tegengehouden'), pxsNum(t.blocked.n), `${pxsPct(t.blocked.pct)} ${pxsDelta(t.blocked.pct, t.blocked.prev_pct, { pp: true, goodUp: false })}`, L('403, 429 en 444 samen'))}
    ${t.visitors ? pxsTile('👥', L('Bezoekers'), pxsNum(t.visitors.n), `${L('uniek per dag')} ${pxsDelta(t.visitors.n, t.visitors.prev)}`) : ''}
    ${pxsTile('🎯', L('Aanvallers'), pxsNum(t.attackers.n), `${L('{p} geblokkeerd', { p: pxsPct(t.attackers.pct) })} ${pxsDelta(t.attackers.n, t.attackers.prev, { goodUp: false })}`, L('IP\'s met een web-aanval in deze periode'))}
    ${pxsTile('🧱', L('WAF'), pxsNum(t.waf.n), pxsDelta(t.waf.n, t.waf.prev, { goodUp: false }))}
  </div>`;
  const extra = ex ? `<p class="small muted pxs-extra">${L('Buiten het proxylog:')} ⛔ ${L('{n} IP\'s geblokkeerd door het IDS ({h} keer tegengehouden)', { n: pxsNum(ex.ids_ips), h: pxsNum(ex.ids_hits) })} · 🦠 ${L('{n} bestanden in quarantaine (ClamAV)', { n: pxsNum(ex.av) })} · 👀 ${L('{n} meldingen van Suricata', { n: pxsNum(ex.nids) })}</p>` : '';
  let open = true;
  try { open = localStorage.getItem('rzs-pxs-open') !== '0'; } catch (ex2) { /* geen opslag */ }
  box.innerHTML = `<details class="pxs" ${open ? 'open' : ''}><summary><b>📊 ${L('Wat er gebeurde')}</b> <span class="muted small">${esc(per[d.period])}${d.site ? ' · ' + esc(d.site) : ''}</span></summary>
    ${head}${none}${tiles}
    <div class="pxs-row"><div><h4>${L('Tegengehouden door')}</h4>${pxsReasons(d)}</div><div><h4>${L('Statuscodes')}</h4>${pxsStatus(d) || `<p class="small muted">–</p>`}</div></div>
    ${extra}${pxsChart(d)}${pxsTop(d)}</details>`;
}

async function pxsLoad() {
  const box = $('#pxs-box');
  if (!box) return;
  const seq = ++pxsSeq;
  let d;
  try { d = await api('proxy_stats', null, { period: pxsPeriod, site: pxsSite }); } catch (ex) { if (seq === pxsSeq) box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (seq !== pxsSeq) return;
  pxsRender(d);
}

document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-pxs-period]');
  if (!b) return;
  pxsPeriod = b.dataset.pxsPeriod;
  try { localStorage.setItem('rzs-pxs-period', pxsPeriod); } catch (ex) { /* geen opslag */ }
  pxsLoad();
});
document.addEventListener('change', (e) => {
  if (e.target.id !== 'pxs-site') return;
  pxsSite = e.target.value;
  pxsLoad();
});
document.addEventListener('toggle', (e) => {
  if (!e.target.matches || !e.target.matches('details.pxs')) return;
  try { localStorage.setItem('rzs-pxs-open', e.target.open ? '1' : '0'); } catch (ex) { /* geen opslag */ }
}, true);
