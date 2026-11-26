// ============ 1.328.0: Netwerk → Netwerksnelheid ============
// Eén keten van de bron naar het doel (in de stijl van de traceroute), per schakel vertraging, linksnelheid en gemeten
// doorvoer, met de smalste schakel; daaronder de wifi per access point. Routes beheren en Nu meten: alleen beheerders.
let nsData = null, nsBusy = false, nsMeasuring = false;
let nsRoute = (() => { try { return +localStorage.getItem('rzmon-ns-route') || 0; } catch (ex) { return 0; } })();
let nsDays = (() => { try { return +localStorage.getItem('rzmon-ns-days') === 30 ? 30 : 7; } catch (ex) { return 7; } })();
const NS_ICON = {
  isp: '<circle cx="12" cy="12" r="2.5"/><path d="M6.3 12a5.7 5.7 0 0 1 11.4 0M3 12a9 9 0 0 1 18 0"/>',
  net: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
};
const nsSvg = (k) => (NS_ICON[k] ? `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${NS_ICON[k]}</svg>`
  : npSvg(k === 'dst' ? 'dst' : k === 'virt' ? 'virt' : k === 'switch' ? 'switch' : k === 'router' ? 'router' : k === 'hop' ? 'hop' : 'src'));
const NS_KIND = () => ({ src: L('bron'), virt: L('virtueel'), switch: L('switch'), router: L('router'), hop: L('intern'), isp: L('provider'), net: L('doel'), dst: L('doel') });
const NS_SRC = () => ({ agent: L('agent'), snmp: 'SNMP', unifi: 'UniFi', lldp: 'LLDP', test: L('snelheidstest') });
const nsMbit = (v) => (v === null || v === undefined ? '–' : v >= 1000 ? dec(String(Math.round(v / 100) / 10)) + ' Gbit/s' : Math.round(v) + ' Mbit/s');
const nsAt = (t) => (t ? new Date(t * 1000).toLocaleString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '–');

/** Een klein lijntje (48 blokjes, lege overgeslagen) */
function nsSpark(vals, warn = false, w = 110, h = 22) {
  const d = sparkD(vals, w, h);
  if (!d) return '';
  return `<svg class="ns-spark${warn ? ' w' : ''}" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="${d}"/></svg>`;
}

async function nsLoad() {
  const box = $('#net-speed');
  if (!box || nsBusy) return;
  nsBusy = true;
  if (!nsData) box.innerHTML = `<p class="muted">${L('laden…')}</p>`;
  try {
    nsData = await api('speed', null, { id: nsRoute, days: nsDays });
    if (nsData.route) nsRoute = nsData.route.id;
    renderNetSpeed();
  } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
  finally { nsBusy = false; }
}

function renderNetSpeed() {
  const d = nsData, box = $('#net-speed');
  if (!d || !box) return;
  const r = d.route, t = d.tiles || {};
  const admin = isAdmin();
  const bar = `<div class="ns-bar">
    <label class="ns-sel">📍 <select id="ns-route" aria-label="${esc(L('Route'))}">${(d.routes || []).map((x) => `<option value="${x.id}"${r && +x.id === +r.id ? ' selected' : ''}>${esc(x.name)}${+x.enabled ? '' : ' (' + esc(L('uit')) + ')'}</option>`).join('')}</select></label>
    <span class="ns-sp"></span>
    <div class="seg-group ns-days" role="group" aria-label="${esc(L('Periode'))}">${[7, 30].map((n) => `<button type="button" class="seg-btn${nsDays === n ? ' active' : ''}" data-ns-days="${n}">${esc(L('{n} dagen', { n }))}</button>`).join('')}</div>
    ${admin ? `<button type="button" class="btn" data-ns-routes>⚙️ ${esc(L('Routes beheren'))}</button>
      <button type="button" class="btn primary" data-ns-measure${nsMeasuring || !r ? ' disabled' : ''}>${nsMeasuring ? '⏳ ' + esc(L('Meten…')) : '▶ ' + esc(L('Nu meten'))}</button>` : ''}
  </div>`;
  if (!r) {
    box.innerHTML = bar + `<p class="muted">${esc(L('Nog geen route. Maak er een met Routes beheren.'))}</p>`;
    return;
  }
  const pct = (v, p) => (v !== null && v !== undefined && p ? Math.round(v / p * 100) : null);
  const dp = pct(t.down, t.plan_down), up = pct(t.up, t.plan_up);
  const tile = (lbl, val, unit, sub, warn, spark) => `<div class="ns-tile${warn ? ' warn' : ''}"><span class="ns-k">${esc(lbl)}</span>
    <span class="ns-v">${val}${unit ? ` <small>${esc(unit)}</small>` : ''}</span><span class="ns-s">${sub}</span>${spark || ''}</div>`;
  const tiles = `<div class="ns-tiles">
    ${tile(L('Internet download'), t.down !== null ? Math.round(t.down) : '–', t.down !== null ? 'Mbit/s' : '', t.down !== null ? esc(L('{p}% van je abonnement ({a})', { p: dp, a: t.plan_down })) : esc(t.test_err ? L('snelheidstest mislukt') : L('nog geen snelheidstest')), dp !== null && dp < t.warn_pct, nsSpark(d.hist.down))}
    ${tile(L('Internet upload'), t.up !== null ? Math.round(t.up) : '–', t.up !== null ? 'Mbit/s' : '', t.up !== null ? esc(L('{p}% van je abonnement ({a})', { p: up, a: t.plan_up })) : '', up !== null && up < t.warn_pct, nsSpark(d.hist.up))}
    ${tile(L('Ping naar {d}', { d: r.dst }), t.ms !== null && t.ms !== undefined ? dec(String(t.ms >= 100 ? Math.round(t.ms) : Math.round(t.ms * 10) / 10)) : '–', t.ms !== null && t.ms !== undefined ? 'ms' : '',
      t.loss !== null && t.loss !== undefined ? esc(L('jitter {j} ms · {l}% verlies', { j: dec(String(Math.round((t.jit || 0) * 10) / 10)), l: Math.round(t.loss) })) : esc(L('nog geen meting')), (t.loss || 0) >= 5)}
    ${tile(L('Doorvoer in je netwerk'), t.tp !== null && t.tp !== undefined ? Math.round(t.tp) : '–', t.tp !== null && t.tp !== undefined ? 'Mbit/s' : '',
      t.tp !== null && t.tp !== undefined ? esc(L('iperf3 naar {d}', { d: t.tp_to || r.dst })) : esc(r.dst_kind === 'host' ? (t.tp_err || L('nog geen doorvoertest')) : L('maak een route naar een server voor een doorvoertest')), false, nsSpark(d.hist.tp))}
  </div>`;

  // De keten
  const kinds = NS_KIND(), srcs = NS_SRC();
  let chain = '';
  (d.nodes || []).forEach((n, i) => {
    chain += `<div class="ns-node ${esc(n.st || 'g')}${n.k === 'isp' || n.k === 'net' ? ' ext' : ''}"><div class="ns-ico">${nsSvg(n.k)}</div><span class="ns-kind">${esc(kinds[n.k] || n.k)}</span>
      <b>${esc(n.name)}</b>${n.ip && n.ip !== n.name ? `<span class="ns-ip">${esc(n.ip)}</span>` : ''}${n.sub ? `<span class="ns-nsub">${esc(n.sub)}</span>` : ''}</div>`;
    const l = (d.links || [])[i];
    if (!l) return;
    const cls = [l.narrow ? 'narrow' : '', l.st === 'w' ? 'w' : '', l.u ? 'u' : ''].filter(Boolean).join(' ');
    const spd = l.down !== null || l.up !== null ? `↓ ${l.down ?? '–'} · ↑ ${l.up ?? '–'}` : l.spd ? npSpeed(l.spd) : l.note === L('virtueel') ? L('virtueel') : '?';
    const meas = [l.tp ? L('{v} gemeten', { v: nsMbit(l.tp) }) : '', l.wan ? L('WAN {s}', { s: npSpeed(l.wan) }) : '', l.down !== null || l.up !== null ? 'Mbit/s' : '', l.note && l.note !== L('virtueel') ? l.note : '',
      l.err ? L('{n} fouten', { n: l.err }) : l.err === 0 ? L('0 fouten') : '', l.u && l.note !== L('virtueel') ? L('snelheid onbekend') : ''].filter(Boolean);
    const lat = l.ms !== null && l.ms !== undefined ? dec(String(l.ms >= 100 ? Math.round(l.ms) : Math.round(l.ms * 10) / 10)) + ' ms' + (l.loss ? ' · ' + Math.round(l.loss) + '%' : '') : '';
    const spdW = (l.narrow && l.narrow !== 'tp') || l.st === 'w';
    chain += `<div class="ns-link ${cls}${spdW ? ' sw' : ''}"><span class="ns-lat">${esc(lat)}</span><span class="ns-wire" aria-hidden="true"></span>
      <b class="ns-spd">${esc(spd)}</b><span class="ns-meas">${meas.map(esc).join(' · ')}</span>${l.spd_src ? `<span class="ns-src">${esc(srcs[l.spd_src] || l.spd_src)}</span>` : ''}
      ${nsSpark(l.down !== null && l.down !== undefined ? d.hist.down : l.hist, l.narrow || l.st === 'w')}</div>`;
  });
  const v = d.verdict;
  const card = `<div class="card ns-card">
    <h2>🧭 ${esc(r.name)}</h2>
    <p class="muted small ns-subline">${d.at ? esc(L('Laatst gemeten {t}', { t: nsAt(d.at) })) + (d.tool ? ' · ' + esc(d.tool) : '') : ''}${d.local_agent && !r.src_host_id ? ' · ' + esc(L('gemeten door de agent op {h}', { h: d.local_agent })) : ''}
      ${r.dst_kind === 'internet' ? ' · ' + esc(L('snelheidstest: {t}', { t: nsAt(t.test_at) })) : ''}</p>
    ${chain ? `<div class="ns-chain">${chain}</div>` : ''}
    ${v ? `<div class="ns-verdict ${v.st === 'w' ? 'w' : ''}">${v.st === 'w' ? '⚠️' : '💡'} <span>${esc(v.text)}</span></div>` : ''}
    ${(d.hints || []).length ? `<ul class="ns-hints">${d.hints.map((h) => `<li>${esc(h)}</li>`).join('')}</ul>` : ''}
    ${chain ? `<div class="ns-legend"><span><i class="g"></i>${esc(L('goed'))}</span><span><i class="w"></i>${esc(L('smalste schakel of afwijkend'))}</span><span><i class="u"></i>${esc(L('snelheid onbekend'))}</span>
      <span>${esc(L('boven de lijn: vertraging tot dat punt · onder: linksnelheid, gemeten doorvoer en het verloop'))}</span></div>` : ''}
  </div>`;
  box.innerHTML = bar + tiles + card + nsWifiHtml(d.wifi || {});
}

function nsWifiHtml(w) {
  const aps = w.aps || [];
  const bars = (n, weak) => `<span class="ns-bars${weak ? ' w' : ''}" aria-hidden="true">${[1, 2, 3, 4].map((i) => `<i class="${i <= n ? 'on' : ''}"></i>`).join('')}</span>`;
  const band = (c) => [c.band ? c.band.replace('.', LANG === 'en' ? '.' : ',') + ' GHz' : '', c.width ? c.width + ' MHz' : '', c.chan ? L('kanaal {c}', { c: c.chan }) : ''].filter(Boolean).join(' · ');
  const sub = w.unifi ? L('Uit UniFi: elk access point met zijn verbinding naar het netwerk, en per apparaat de snelheid en het signaal (zwakste eerst).')
    : L('Zonder UniFi meldt elk apparaat met de agent zijn eigen wifi. Andere apparaten zie je hier pas met een UniFi-koppeling.');
  const list = aps.map((ap) => {
    const up = ap.uplink;
    const upTxt = up ? (up.mesh ? L('mesh (draadloos) → {t}', { t: up.to_name || '?' }) : L('bekabeld → {t}', { t: up.to_name || '?' }) + (up.port ? ' ' + L('poort {p}', { p: up.port }) : ''))
      + (up.speed ? ' · ' + npSpeed(up.speed) : '') : (ap.src === 'agent' ? L('gezien door {n} apparaten', { n: ap.clients.length }) : '');
    const more = ap.clients.length > 5 && ap.src === 'unifi';
    return `<div class="ns-ap"><div class="ns-ap-h">📶 <b>${esc(ap.name)}</b> <span class="muted small">${esc(upTxt)}</span>
        ${up && up.mesh ? `<span class="lf-tag warn">${esc(L('mesh: snelheid gedeeld'))}</span>` : ''}${ap.weak ? `<span class="lf-tag warn">${esc(L('{n} met zwak signaal', { n: ap.weak }))}</span>` : ''}
        <span class="lf-tag">${esc(ap.src === 'unifi' ? 'UniFi' : L('bron: agent'))}</span></div>
      <div class="table-scroll"><table class="vuln-table ns-wtable"><thead><tr><th>${esc(L('Apparaat'))}</th><th>${esc(L('Band'))}</th><th class="num">${esc(L('Verbinding'))}</th><th>${esc(L('Signaal'))}</th><th class="num">${esc(L('Gemeten'))}</th><th></th></tr></thead><tbody>
      ${ap.clients.map((c, i) => `<tr${more && i >= 5 ? ' class="ns-more" hidden' : ''}><td><b>${esc(c.name)}</b>${c.ssid ? ` <span class="muted small">${esc(c.ssid)}</span>` : ''}</td><td class="small">${esc(band(c))}</td>
        <td class="num">${c.rate ? esc(c.rate + ' Mbit/s') : '–'}</td><td class="nowrap">${bars(c.bars, c.weak)} ${c.sig !== null && c.sig !== undefined ? esc(c.sig + ' dBm') : '–'}</td>
        <td class="num">${c.tp ? esc(nsMbit(c.tp)) : '–'}</td><td>${c.bad ? `<span class="lf-tag crit">${esc(L('slecht signaal'))}</span>` : c.weak ? `<span class="lf-tag warn">${esc(L('zwak signaal'))}</span>` : nsSpark(c.hist)}</td></tr>`).join('')}
      </tbody></table></div>${more ? `<button type="button" class="link-btn" data-ns-more>${esc(L('Alle {n} apparaten', { n: ap.clients.length }))}</button>` : ''}</div>`;
  }).join('');
  return `<div class="card ns-card"><h2>📶 ${esc(L('Wifi per access point'))}</h2><p class="muted small">${esc(sub)}</p>
    ${list || `<p class="muted">${esc(L('Nog geen wifi-gegevens. Een laptop of pc met de agent (Linux of Windows) meldt hier zijn eigen wifi.'))}</p>`}
    ${(w.no_iw || []).map((x) => `<p class="muted small">💡 ${esc(L('{h} heeft wifi, maar iw kon niet geïnstalleerd worden ({e}). Installeer het zelf: sudo apt install iw of sudo dnf install iw.', { h: x.name, e: x.err }))}</p>`).join('')}
    ${w.unifi ? '' : `<div class="ns-empty">🔌 <b>${esc(L('Alle access points en wifi-apparaten zien?'))}</b> ${esc(L('Koppel UniFi bij Instellingen → Netwerk → UniFi. Dan komen hier ook de bekabeling of mesh van elk access point en de snelheid en het signaal van elk apparaat.'))}</div>`}
  </div>`;
}

async function nsMeasure() {
  if (!nsData || !nsData.route || nsMeasuring) return;
  nsMeasuring = true;
  renderNetSpeed();
  try {
    const r = await api('speed_measure', { id: nsData.route.id });
    toast(r.message || L('Gemeten'));
  } catch (ex) { toast(ex.message, 'err'); }
  nsMeasuring = false;
  await nsLoad();
}

/** Routes beheren: de lijst, en per route het formulier */
function nsRoutesDialog() {
  const d = nsData;
  const rows = (d.routes || []).map((r) => `<div class="ns-rt"><b>${esc(r.name)}</b>
      <span class="muted small">${esc(r.dst_kind === 'host' && +r.tp ? L('doorvoer: {e}', { e: nsEvery(r.every_sec) }) : '')}</span>
      ${+r.notify ? `<span class="lf-tag ok">${esc(L('melding aan'))}</span>` : ''}${+r.enabled ? '' : `<span class="lf-tag">${esc(L('uit'))}</span>`}
      <span class="ns-sp"></span><button type="button" class="btn small-btn" data-ns-edit="${r.id}">✏️ ${esc(L('Bewerken'))}</button></div>`).join('');
  modal({
    title: '⚙️ ' + L('Routes beheren'),
    body: `<p class="muted small">${esc(L('Een route meet van een bron (RzSense of een server met de agent) naar internet, een server of een adres. Vanaf een server met de agent maakt RzSense daarvoor een pad-check (alleen informatief).'))}</p>
      <div class="ns-rts">${rows || `<p class="muted">${esc(L('Nog geen routes.'))}</p>`}</div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }, { label: '＋ ' + L('Route toevoegen'), onClick: () => nsRouteForm(null) }],
  });
  $('#modal-body').addEventListener('click', (e) => {
    const b = e.target.closest('[data-ns-edit]');
    if (b) nsRouteForm((d.routes || []).find((r) => +r.id === +b.dataset.nsEdit));
  }, { signal: modalSignal() });
}

const nsEvery = (s) => ({ 3600: L('elk uur'), 21600: L('elke 6 uur'), 86400: L('elke dag') }[s] || L('elk uur'));

function nsRouteForm(r) {
  const d = nsData;
  const hosts = d.hosts || [];
  const cur = r || { id: 0, src_host_id: null, dst_kind: 'internet', dst_host_id: null, dst_addr: '', every_sec: 3600, tp: 1, notify: 0, notify_min: null, wan_if: null, enabled: 1, own_name: '' };
  const ifs = (d.route && +d.route.id === +cur.id ? d.route.router_ifs : null) || (r ? [] : (d.route || {}).router_ifs || []);
  const opt = (v, txt, sel) => `<option value="${esc(String(v))}"${sel ? ' selected' : ''}>${esc(txt)}</option>`;
  modal({
    title: (r ? '✏️ ' + L('Route bewerken') : '＋ ' + L('Route toevoegen')),
    body: `<div class="row2">
        <label>${esc(L('Van'))}<select name="src_host_id">${opt(0, 'RzSense', !cur.src_host_id)}${hosts.filter((h) => h.agent).map((h) => opt(h.id, h.name + ' (' + L('agent') + ')', +cur.src_host_id === +h.id)).join('')}</select>
          <span class="hint">${esc(L('RzSense of een server met de agent'))}</span></label>
        <label>${esc(L('Naar'))}<select name="dst_kind">${opt('internet', '🌍 ' + L('Internet'), cur.dst_kind === 'internet')}${opt('host', L('Een server in RzSense'), cur.dst_kind === 'host')}${opt('addr', L('Een IP-adres of naam'), cur.dst_kind === 'addr')}</select></label>
      </div>
      <div data-ns-dst="host"${cur.dst_kind === 'host' ? '' : ' hidden'}><label>${esc(L('Server'))}<select name="dst_host_id">${hosts.map((h) => opt(h.id, h.name, +cur.dst_host_id === +h.id)).join('')}</select></label>
        <label class="check-row"><input type="checkbox" name="tp"${+cur.tp ? ' checked' : ''}> ${esc(L('Doorvoer meten met iperf3 (de agent op de server start kort een ontvanger)'))}</label>
        <label>${esc(L('Doorvoer meten'))}<select name="every_sec">${[3600, 21600, 86400].map((s) => opt(s, nsEvery(s), +cur.every_sec === s)).join('')}</select></label></div>
      <div data-ns-dst="addr"${cur.dst_kind === 'addr' ? '' : ' hidden'}><label>${esc(L('Adres'))}<input name="dst_addr" value="${esc(cur.dst_addr || '')}" maxlength="253" placeholder="192.168.1.10 / nas.lan"></label></div>
      <div data-ns-dst="internet"${cur.dst_kind === 'internet' ? '' : ' hidden'}><p class="muted small">${esc(L('De snelheid van je internetlijn komt uit de snelheidstest van de check Internet (Instellingen → Netwerk → Internetcheck), vergeleken met je abonnement.'))}</p>
        ${ifs.length ? `<label>${esc(L('WAN-poort van de router (SNMP)'))}<select name="wan_if">${opt('', L('vanzelf (op de naam)'), !cur.wan_if)}${ifs.map((x) => opt(x.i, x.n, String(cur.wan_if) === String(x.i))).join('')}</select></label>` : ''}</div>
      <label>${esc(L('Naam'))} <span class="hint">${esc(L('(optioneel)'))}</span><input name="name" value="${esc(cur.own_name || '')}" maxlength="100"></label>
      <fieldset class="devreq-who"><legend>${esc(L('Melding'))}</legend>
        <label class="check-row"><input type="checkbox" name="notify"${+cur.notify ? ' checked' : ''}> ${esc(L('Melding als een link terugvalt, het doel onbereikbaar is of de smalste schakel onder de grens zakt'))}</label>
        <label>${esc(L('Grens (Mbit/s)'))} <span class="hint">${esc(L('(optioneel)'))}</span><input name="notify_min" type="number" min="1" max="100000" value="${cur.notify_min ?? ''}"></label>
        <p class="hint">${esc(L('Via je gewone meldingskanalen (Systeemmeldingen → Netwerksnelheid). Staat standaard uit.'))}</p></fieldset>
      <label class="check-row"><input type="checkbox" name="enabled"${+cur.enabled ? ' checked' : ''}> ${esc(L('Route aan'))}</label>`,
    buttons: [{ label: L('Annuleren'), onClick: () => nsRoutesDialog() }, ...(r ? [{ label: '🗑️ ' + L('Verwijderen'), onClick: async () => {
      if (!confirm(L('Route {n} verwijderen? De metingen gaan ook weg.', { n: r.name }))) return;
      try { await api('speed_route_delete', { id: r.id }); toast(L('Verwijderd')); if (+nsRoute === +r.id) nsRoute = 0; await nsLoad(); nsRoutesDialog(); } catch (ex) { toast(ex.message, 'err'); }
    } }] : []), { label: L('Opslaan'), submit: true }],
    onSubmit: async (f) => {
      const res = await api('speed_route_save', { id: cur.id, name: f.name, src_host_id: +f.src_host_id || 0, dst_kind: f.dst_kind, dst_host_id: +f.dst_host_id || 0, dst_addr: f.dst_addr || '',
        tp: !!f.tp, every_sec: +f.every_sec || 3600, notify: !!f.notify, notify_min: f.notify_min || null, wan_if: f.wan_if || null, enabled: !!f.enabled });
      toast(L('Opgeslagen'));
      nsRoute = res.id;
      try { localStorage.setItem('rzmon-ns-route', String(nsRoute)); } catch (ex) { /* niet erg */ }
      await nsLoad();
    },
  });
  $('#modal-body').addEventListener('change', (e) => {
    if (e.target.name !== 'dst_kind') return;
    $$('[data-ns-dst]', $('#modal-body')).forEach((x) => { x.hidden = x.dataset.nsDst !== e.target.value; });
  }, { signal: modalSignal() });
}

function initNetSpeed() {
  const box = $('#net-speed');
  if (!box) return;
  box.addEventListener('change', (e) => {
    if (e.target.id !== 'ns-route') return;
    nsRoute = +e.target.value;
    try { localStorage.setItem('rzmon-ns-route', String(nsRoute)); } catch (ex) { /* niet erg */ }
    nsLoad();
  });
  box.addEventListener('click', (e) => {
    const dy = e.target.closest('[data-ns-days]');
    if (dy) {
      nsDays = +dy.dataset.nsDays === 30 ? 30 : 7;
      try { localStorage.setItem('rzmon-ns-days', String(nsDays)); } catch (ex) { /* niet erg */ }
      return nsLoad();
    }
    if (e.target.closest('[data-ns-measure]')) return nsMeasure();
    if (e.target.closest('[data-ns-routes]')) return nsRoutesDialog();
    const more = e.target.closest('[data-ns-more]');
    if (more) {
      $$('.ns-more', more.closest('.ns-ap')).forEach((x) => { x.hidden = false; });
      more.remove();
    }
  });
}
