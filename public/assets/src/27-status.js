// ---------------------------------------------------------------- 1.265.0: status van een melding in één oogopslag
// Vooraan elke beveiligingsmelding één label: wat er gebeurde (geblokkeerd, tegengehouden, alleen gemeld, mogelijk gelukt …).
// RzSense berekent het (Protect::outcome); hier de labels, de kleur en het filter. Dezelfde volgorde als in PHP.

const OUT_ORDER = ['maybe', 'failed', 'isolated', 'blocked', 'stopped', 'pending', 'learn', 'notified', 'unblocked', 'expired', 'allowed'];
const OUT_META = () => ({
  blocked: ['⛔', L('Geblokkeerd'), 'good', L('Het IP kan er nu niet meer in (firewall of proxy).')],
  stopped: ['🛡️', L('Tegengehouden'), 'good', L('Dit verzoek of proces is gestopt; het IP zelf is niet geblokkeerd.')],
  pending: ['⏳', L('Wordt gestopt'), 'wait', L('De agent voert het bij de volgende ronde uit.')],
  isolated: ['🧱', L('Geïsoleerd'), 'good', L('De server is van het netwerk gehaald (alleen je eigen netwerk mag er nog bij).')],
  maybe: ['⚠️', L('Doorgelaten – mogelijk gelukt'), 'bad', L('De app gaf een gewoon antwoord op een aanval. Kijk of het lukte.')],
  notified: ['👁', L('Alleen gemeld'), 'neutral', L('Doorgelaten: er is niets tegen gedaan (ernst te laag, of blokkeren staat uit).')],
  learn: ['🎓', L('Leren'), 'neutral', L('Modus Leren: in de modus Blokkeren was hier ingegrepen.')],
  allowed: ['✅', L('Toegestaan'), 'good', L('Je eigen netwerk, de whitelist of RzSense zelf: wordt nooit geblokkeerd.')],
  unblocked: ['↩', L('Gedeblokkeerd'), 'neutral', L('Was geblokkeerd, met de hand vrijgegeven.')],
  expired: ['⌛', L('Blokkade verlopen'), 'neutral', L('Was geblokkeerd; de blokkade is voorbij.')],
  failed: ['✘', L('Mislukt'), 'bad', L('De actie lukte niet (bv. een proces dat niet te stoppen was).')],
});

/** De status van een IDS-incident uit de gegevens van de lijst (prot), of null als die er niet is (oud en afgehandeld) */
function outcomeOf(i, prot = (idsData && idsData.prot) || {}) {
  const p = prot[i.id];
  return p && p.o ? p.o : null;
}
/** Het label vooraan een regel */
function outcomePill(o, small = false) {
  if (!o) return '';
  const m = OUT_META()[o.k];
  if (!m) return '';
  const until = o.until ? (o.until === 'permanent' ? ' · ' + L('permanent') : ' · ' + L('tot {t}', { t: o.until })) : '';
  const tip = `${m[1]}${until}: ${m[3]}${o.text && o.text !== m[1] ? '\n' + o.text : ''}${o.warn ? '\n⚠️ ' + o.warn : ''}`;
  return `<span class="out-pill out-${m[2]} out-k-${o.k}${small ? ' sm' : ''}" title="${esc(tip)}">${m[0]} ${esc(m[1])}${esc(until)}${o.warn ? ' <b class="out-warn" aria-label="' + esc(o.warn) + '">⚠️</b>' : ''}</span>`;
}

// Filter op status in de IDS-lijst (chips met aantallen)
let idsOutcome = '';
function outcomeChips(items) {
  const n = {};
  items.forEach((i) => { const o = outcomeOf(i); if (o) n[o.k] = (n[o.k] || 0) + 1; });
  const ks = OUT_ORDER.filter((k) => n[k]);
  if (!ks.length) return '';
  const M = OUT_META();
  return `<span class="out-lbl">${esc(L('Status'))}</span>${ks.map((k) => `<button type="button" class="dchip out-chip out-${M[k][2]} ${idsOutcome === k ? 'on' : ''}" data-iout="${k}" title="${esc(M[k][3])}" aria-label="${esc(L('Status: {s}', { s: M[k][1] }))}">${M[k][0]} ${esc(M[k][1])} <b>${n[k]}</b></button>`).join('')}`;
}
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('[data-iout]');
  if (!b) return;
  idsOutcome = idsOutcome === b.dataset.iout ? '' : b.dataset.iout;
  renderIds();
});

// 1.266.0: IDS overzichtelijk: filter op server (chips boven de lijst) en het menu "Meer" bij de tabbladen
let idsHost = 0;
document.addEventListener('click', (e) => {
  const h = e.target.closest && e.target.closest('[data-ihost]');
  if (h) { idsHost = +h.dataset.ihost === +idsHost ? 0 : +h.dataset.ihost; renderIds(); return; }
  const mb = e.target.closest && e.target.closest('#ids-more-btn');
  const menu = $('#ids-more-menu');
  if (mb && menu) {
    menu.hidden = !menu.hidden;
    mb.setAttribute('aria-expanded', String(!menu.hidden));
    if (!menu.hidden) { const first = $('[data-it]', menu); if (first) first.focus(); }
    return;
  }
  if (menu && !menu.hidden && !(e.target.closest && e.target.closest('#ids-more-menu'))) { menu.hidden = true; $('#ids-more-btn').setAttribute('aria-expanded', 'false'); }
});
document.addEventListener('keydown', (e) => {
  const menu = $('#ids-more-menu');
  if (e.key === 'Escape' && menu && !menu.hidden) { menu.hidden = true; $('#ids-more-btn').setAttribute('aria-expanded', 'false'); $('#ids-more-btn').focus(); }
});

// 1.267.0: geen dubbel werk tussen Reverse proxy en IDS
/** ClamAV-vondst in het venster van een incident "Malware gevonden", met Terugzetten/Weghalen (stond eerst op de pagina Reverse proxy) */
function avIncHtml(e) {
  const [c, t] = AV_EV()[e.status] || ['', e.status];
  return `<h3 class="set-sub">🦠 ${L('Bestand')}</h3><div class="av-inc" data-av-inc="${e.id}"><code class="mono small">${esc(e.path)}</code>
    <div class="small">${esc(e.sig)} · <span class="lf-tag ${c}">${t}</span>${e.pending ? ` <span class="lf-tag wait">⏳ ${e.pending === 'restore' ? L('terugzetten') : L('weghalen')}</span>` : ''}${e.msg ? ` <span class="muted">${esc(e.msg)}</span>` : ''}</div>
    ${e.status === 'quarantined' && !e.pending ? `<div class="btn-row need-admin"><button type="button" class="btn small-btn" data-av-inc-op="restore">↩ ${L('Terugzetten')}</button>
      <button type="button" class="btn small-btn danger" data-av-inc-op="delete">🗑 ${L('Definitief weghalen')}</button>
      <span class="muted small">${L('Terugzetten alleen als je zeker weet dat het bestand veilig is.')}</span></div>` : ''}</div>`;
}
document.addEventListener('click', async (e) => {
  const op = e.target.closest && e.target.closest('[data-av-inc-op]');
  if (op) {
    const box = op.closest('[data-av-inc]');
    if (op.dataset.avIncOp === 'delete' && !confirm(L('Het bestand definitief weghalen?'))) return;
    try { await api('av_op', { id: +box.dataset.avInc, op: op.dataset.avIncOp }); toast(L('Aangevraagd; de agent doet het bij zijn volgende ronde')); } catch (ex) { toast(ex.message, 'err'); return; }
    $$('[data-av-inc-op]', box).forEach((b) => { b.disabled = true; });
    return;
  }
  const cat = e.target.closest && e.target.closest('[data-to-idscat]');
  if (cat) {
    e.preventDefault();
    idsCat = cat.dataset.toIdscat; idsFilter = 'all'; idsTab = 'inc'; idsHost = 0;
    const q = $('#ids-search'); if (q) q.value = '';
    showTab('ids/inc');
    if (idsData) renderIds();
    return;
  }
  const ip = e.target.closest && e.target.closest('[data-px-ip]');
  if (ip) { e.preventDefault(); ipTimeline(ip.dataset.pxIp); }
});

// 1.268.0: de route van een aanval in het venster van een incident: per station wat er met dit IP gebeurde
const RT_STATE = () => ({ stop: L('gestopt'), pass: L('door'), warn: L('deels'), bad: L('gelukt of verdacht'), skip: L('niet bereikt'), na: L('geen gegevens') });
function routeHtml(r) {
  const st = r.stations || [];
  if (!st.length) return '';
  const bad = st.findIndex((x) => x.state === 'bad');
  const stop = st.findIndex((x) => x.state === 'stop');
  const sel = bad >= 0 ? bad : stop >= 0 ? stop : st.length - 1;
  const sum = bad >= 0 ? `<span class="rt-sum bad">⚠️ ${esc(L('Let op bij: {s}', { s: st[bad].label }))}</span>`
    : stop >= 0 ? `<span class="rt-sum ok">✅ ${esc(L('Gestopt bij: {s}', { s: st[stop].label }))}</span>`
    : `<span class="rt-sum">👁 ${esc(L('Doorgelaten; niets verdachts gezien'))}</span>`;
  return `<div class="rt" data-rt-box>
    <div class="rt-h"><b>🛤️ ${L('Route van de aanval')}</b> ${sum}</div>
    <div class="rt-strip" role="tablist" aria-label="${esc(L('Route van de aanval'))}">${st.map((x, n) => `<button type="button" role="tab" class="rt-st rt-${x.state}${n === sel ? ' on' : ''}" data-rt="${n}" aria-selected="${n === sel}" title="${esc(x.label + ': ' + (RT_STATE()[x.state] || x.state))}"><span class="rt-i">${x.icon}</span><span class="rt-l">${esc(x.label)}</span></button>`).join('<span class="rt-ar" aria-hidden="true">→</span>')}</div>
    ${st.map((x, n) => `<div class="rt-d" data-rt-d="${n}" ${n === sel ? '' : 'hidden'}><div><b>${x.icon} ${esc(x.label)}</b> <span class="rt-tag rt-${x.state}">${esc(RT_STATE()[x.state] || x.state)}</span></div>
      <p>${esc(x.text || '')}</p>${(x.lines || []).length ? `<ul>${x.lines.map((l) => `<li>${esc(l)}</li>`).join('')}</ul>` : ''}</div>`).join('')}
  </div>`;
}
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('[data-rt]');
  if (!b) return;
  const box = b.closest('[data-rt-box]');
  $$('[data-rt]', box).forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', String(x === b)); });
  $$('[data-rt-d]', box).forEach((x) => { x.hidden = x.dataset.rtD !== b.dataset.rt; });
});
