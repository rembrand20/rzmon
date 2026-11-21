// ---------------------------------------------------------------- 1.311.0: live-aanvalsband
// Boven Dashboard, IDS en Netwerk: wat de IDS de laatste minuten zag, als stippen die onderweg zijn. Elke stip is een
// echte gebeurtenis (ids_band). Rood = van buiten (tegengehouden: spat uiteen op het schild; alleen gezien: loopt door
// naar de server), oranje = binnen het netwerk (van een eigen server of het LAN). Beweging (Stil · Rustig · Vol) geldt:
// bij Stil alleen de lijst. De band pauzeert als het tabblad niet zichtbaar is.

const ATKB_TABS = ['home', 'ids', 'net'];
const atkb = { on: false, since: 0, timer: 0, key: '', hosts: [], n: 0 };
const ATKB_NS = 'http://www.w3.org/2000/svg';
const monBand = () => PREFS.atk_band !== 'off';

/** Hoogte van server i van n in de tekening */
const atkbY = (i, n) => Math.round(18 + (152 / n) * (i + 0.5));
const atkbHostPath = (y) => `M462 70 C 560 70, 600 ${y}, 690 ${y}`;

function atkbShow(tab) {
  const el = $('#atk-band');
  if (!el) return;
  const on = ATKB_TABS.includes(tab) && monBand();
  if (!on) { el.hidden = true; atkb.on = false; clearTimeout(atkb.timer); return; }
  if (!atkb.on) { atkb.on = true; atkb.since = 0; atkb.key = ''; atkbPoll(true); }
  else if (atkb.key) el.hidden = false;
}

async function atkbPoll(first) {
  clearTimeout(atkb.timer);
  if (!atkb.on) return;
  if (!document.hidden || first) {
    try { atkbData(await api('ids_band', null, { since: atkb.since || 0 }), first); } catch (ex) { /* geen rechten of even geen verbinding: volgende keer */ }
  }
  if (atkb.on) atkb.timer = setTimeout(() => atkbPoll(false), afMotion(null) ? 5000 : 15000);
}

function atkbHtml(hosts) {
  const n = Math.max(1, hosts.length);
  const wires = hosts.map((h, i) => `<path class="atkb-wire" d="${atkbHostPath(atkbY(i, n))}"/><path class="atkb-flow" d="${atkbHostPath(atkbY(i, n))}"/>`).join('');
  const nodes = hosts.map((h, i) => {
    const y = atkbY(i, n);
    return `<g class="atkb-host" data-h="${+h.id}"><rect x="690" y="${y - 15}" width="190" height="30" rx="9" class="atkb-node"/>
      <text x="785" y="${y + 4}" text-anchor="middle" class="atkb-lbl">${esc(h.name.length > 22 ? h.name.slice(0, 21) + '…' : h.name)}</text></g>`;
  }).join('');
  return `<div class="atkb-head">
      <div class="atkb-title"><span class="live-dot" aria-hidden="true"></span><b>${L('Aanvallen live')}</b>
        <span class="muted small" id="atkb-stat"></span></div>
      <div class="atkb-acts"><button type="button" class="atkb-x" data-atkb-close title="${esc(L('Verbergen (terugzetten bij Mijn account → Weergave)'))}" aria-label="${esc(L('Verbergen'))}">✕</button></div>
    </div>
    <div class="atkb-body">
      <svg class="atkb-svg" viewBox="0 0 900 190" role="img" aria-label="${esc(L('Aanvallen van internet en binnen het netwerk naar je servers'))}">
        <path class="atkb-wire" d="M98 70 C 240 70, 300 70, 398 70"/><path class="atkb-flow" d="M98 70 C 240 70, 300 70, 398 70"/>
        ${wires}
        <circle cx="66" cy="70" r="32" class="atkb-node"/>
        <text x="66" y="74" text-anchor="middle" class="atkb-lbl">${L('Internet')}</text>
        <rect x="36" y="138" width="60" height="26" rx="8" class="atkb-node"/>
        <text x="66" y="155" text-anchor="middle" class="atkb-lbl2">LAN</text>
        <path id="atkb-shield" class="atkb-shield" d="M430 32 l32 11 v28 c0 25 -15 40 -32 48 c-17 -8 -32 -23 -32 -48 v-28 z"/>
        <text x="430" y="78" text-anchor="middle" class="atkb-lbl">IDS</text>
        ${nodes}
        <g id="atkb-fx"></g>
      </svg>
      <div class="atkb-feed" id="atkb-feed" aria-live="polite"></div>
    </div>`;
}

function atkbData(d, first) {
  const el = $('#atk-band');
  if (!el || !atkb.on) return;
  atkb.since = d.now;
  if (!d.active) { el.hidden = true; return; }   // de IDS zag de laatste 7 dagen niets: geen band
  const key = d.hosts.map((h) => h.id).join(',');
  if (key !== atkb.key || !$('#atkb-feed', el)) {
    const keep = $('#atkb-feed', el) ? $('#atkb-feed', el).innerHTML : '';
    atkb.key = key; atkb.hosts = d.hosts;
    el.innerHTML = atkbHtml(d.hosts);
    $('#atkb-feed', el).innerHTML = keep;
  }
  el.hidden = false;
  $('#atkb-stat', el).textContent = L('vandaag {n} pogingen · {b} IP-adressen geblokkeerd', { n: (+d.today).toLocaleString(LOCALE), b: (+d.blocks).toLocaleString(LOCALE) });
  const evs = d.events || [];
  const feed = $('#atkb-feed', el);
  if (first && !evs.length && !feed.children.length) feed.innerHTML = `<p class="muted small atkb-calm">${L('Rustig: de laatste 15 minuten geen aanvallen.')}</p>`;
  if (evs.length) $('.atkb-calm', feed)?.remove();
  const live = first ? evs.slice(-3) : evs.slice(-8);
  evs.forEach((e) => { if (!live.includes(e)) atkbFeed(e); });
  live.forEach((e, i) => setTimeout(() => { if (atkb.on) atkbFly(e); }, i * 650));
}

function atkbFeed(e) {
  const feed = $('#atkb-feed');
  if (!feed) return;
  const t = new Date(e.ts * 1000).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const ext = e.src ? `${e.ip} (${e.src})` : e.ip;
  const how = e.blocked ? L('tegengehouden') : e.internal ? L('binnen het netwerk') : e.dir === 'o' ? L('naar buiten') : L('gezien');
  const row = document.createElement('button');
  row.type = 'button';
  row.className = 'atkb-row ' + (e.internal ? 'k-in' : 'k-out');
  row.dataset.dref = 'ids:' + e.id;
  row.dataset.drefClick = '';
  row.innerHTML = e.dir === 'o' && !e.internal
    ? `<span class="atkb-t">${esc(t)}</span> <b>${esc(e.host)}</b> → ${esc(ext)} · ${esc(e.what)} · <span class="atkb-how">${esc(how)}</span>`
    : `<span class="atkb-t">${esc(t)}</span> ${esc(ext)} → <b>${esc(e.host)}</b> · ${esc(e.what)} · <span class="atkb-how">${esc(how)}</span>`;
  feed.prepend(row);
  while (feed.children.length > 4) feed.lastElementChild.remove();
}

/** Een stip langs een pad; bij aankomst onEnd */
function atkbPacket(d, color, ms, onEnd) {
  const fx = $('#atkb-fx');
  if (!fx) return;
  const p = document.createElementNS(ATKB_NS, 'path');
  p.setAttribute('d', d); p.setAttribute('fill', 'none');
  const c = document.createElementNS(ATKB_NS, 'circle');
  c.setAttribute('r', 5); c.setAttribute('class', 'atkb-dot ' + color);
  fx.append(p, c);
  const L0 = p.getTotalLength(), t0 = performance.now(), D = ms * (document.body.classList.contains('mo-1') ? 1.6 : 1);
  const step = (t) => {
    if (!c.isConnected) return;
    const k = Math.min(1, (t - t0) / D), q = p.getPointAtLength(L0 * k);
    c.setAttribute('cx', q.x); c.setAttribute('cy', q.y);
    if (k < 1) { requestAnimationFrame(step); return; }
    c.remove(); p.remove();
    if (onEnd) onEnd();
  };
  requestAnimationFrame(step);
}

function atkbBurst(x, y, cls) {
  const fx = $('#atkb-fx');
  if (!fx || !document.body.classList.contains('mo-2')) return;
  const c = document.createElementNS(ATKB_NS, 'circle');
  c.setAttribute('cx', x); c.setAttribute('cy', y); c.setAttribute('r', 12); c.setAttribute('class', 'atkb-burst ' + cls);
  fx.append(c);
  setTimeout(() => c.remove(), 1200);
}
const atkbFlash = (node, cls) => {
  if (!node || !document.body.classList.contains('mo-2')) return;
  node.classList.remove(cls); void node.getBBox(); node.classList.add(cls);
};

/** Een gebeurtenis laten zien: eerst onderweg, dan in de lijst */
function atkbFly(e) {
  if (document.body.classList.contains('no-anim') || document.hidden) { atkbFeed(e); return; }
  const n = Math.max(1, atkb.hosts.length);
  const i = atkb.hosts.findIndex((h) => +h.id === +e.host_id);
  const y = atkbY(i < 0 ? n - 1 : i, n);
  const hostNode = i < 0 ? null : $(`#atk-band .atkb-host[data-h="${+e.host_id}"] rect`);
  if (e.internal) {
    const j = e.src_id ? atkb.hosts.findIndex((h) => +h.id === +e.src_id) : -1;
    const d = j >= 0 && j !== i ? `M690 ${atkbY(j, n)} C 610 ${atkbY(j, n)}, 610 ${y}, 690 ${y}` : `M96 151 C 380 151, 560 ${y}, 690 ${y}`;
    const fx = $('#atkb-fx'), line = document.createElementNS(ATKB_NS, 'path');
    line.setAttribute('d', d); line.setAttribute('class', 'atkb-lat');
    if (fx) fx.prepend(line);
    atkbPacket(d, 'in', 1300, () => { atkbFlash(hostNode, 'hit-in'); atkbFeed(e); setTimeout(() => line.remove(), 1400); });
    return;
  }
  if (e.dir === 'o') {   // de server maakt zelf verbinding naar buiten: van de server via het schild naar internet
    atkbFlash(hostNode, 'hit');
    atkbPacket(`M690 ${y} C 600 ${y}, 560 70, 462 70`, 'out', 800, () => {
      if (e.blocked) { atkbFlash($('#atkb-shield'), 'block'); atkbBurst(462, 70, 'out'); atkbFeed(e); return; }
      atkbPacket('M398 70 C 300 70, 240 70, 98 70', 'out', 1000, () => atkbFeed(e));
    });
    return;
  }
  atkbPacket('M98 70 C 240 70, 300 70, 398 70', 'out', 1000, () => {
    if (e.blocked) { atkbFlash($('#atkb-shield'), 'block'); atkbBurst(398, 70, 'out'); atkbFeed(e); return; }
    atkbPacket(atkbHostPath(y), 'out', 800, () => { atkbFlash(hostNode, 'hit'); atkbFeed(e); });
  });
}

document.addEventListener('click', (ev) => {
  if (!ev.target.closest('[data-atkb-close]')) return;
  savePref('atk_band', 'off');
  atkbShow('');
  toast(L('Verborgen. Terugzetten kan bij Mijn account → Weergave.'));
});
document.addEventListener('visibilitychange', () => { if (!document.hidden && atkb.on) atkbPoll(false); });
