// ---------------------------------------------------------------- 1.246.0: Agent Manager als overzichtelijke lijst
// Alle onderdelen onder elkaar, in groepen. Per regel: icoon, naam, een korte samenvatting van hoe het nu staat en
// een status (eigen instelling, aandacht). Klik op een regel om hem open te klappen; er is er steeds één open.
// De onderdelen zelf (schakelaars, keuzes, knoppen) blijven precies dezelfde: ze worden alleen verplaatst.

/** Groepen en hun onderdelen (data-pk van de oude kaarten) */
const AM_GROUPS = () => [
  ['conn', L('Verbinding en agent'), ['run', 'conn', 'maint']],
  ['prot', L('Bescherming'), ['sec', 'ransom', 'apps']],
  ['rights', L('Rechten op de server'), ['cmd', 'sudo'], () => xpHtml(L('Alles wat op deze server als root kan gebeuren, bij elkaar.'), L('<b>Commando-checks:</b> RzSense laat de agent een commando uitvoeren dat jij in een check hebt gezet, en vergelijkt het antwoord. Staat dit op <i>als root</i>, dan mag zo\'n commando alles; de regel krijgt dan een waarschuwing. <b>Tijdelijk sudo:</b> RzSense voert niets uit, maar geeft een gebruiker op de server voor korte tijd sudo, zodat die zelf iets als root kan doen. Het gaat vanzelf weer weg. <b>Wat verder als root draait:</b> zelf herstellen (alleen een service of container herstarten, of een script dat je zelf in <code>/etc/rzmon-agent/heal.d/</code> zet), updates installeren (de pakketbeheerder van de server) en de diagnose voor AI-advies (een vaste lijst die alleen leest). Daarbij kan RzSense geen eigen commando meesturen. <b>Op de server zelf</b> heb je altijd het laatste woord: <code>COMMANDS=0</code>, <code>SUDO_JIT=0</code> of <code>HEAL=0</code> in <code>/etc/default/rzmon-agent</code>.'), 'am-grp-xp')],
  ['upd', L('Updates'), ['patch']],
  ['notify', L('Meldingen'), ['notify']],
  ['logs', L('Logs'), ['logs', 'actlog']],
  ['snmp', 'SNMP', ['snmp']],
];

/** Welke onderdelen niet bij deze server passen (die komen onder "Meer tonen") */
function amHidden(h) {
  const out = new Set();
  if (h.mode !== 'agent') ['run', 'sec', 'notify', 'conn', 'maint'].forEach((k) => out.add(k));
  if (h.mode === 'agent' && !h.snmp) out.add('snmp');
  if (h.mode === 'agent' && isHaHost(h)) ['ransom', 'sudo', 'cmd'].forEach((k) => out.add(k));
  return out;
}

/** Samenvatting, eigen instelling en aandacht per onderdeel, uit wat RzSense al weet */
function amState(h, pk) {
  const onOff = (v) => (v ? L('aan') : L('uit'));
  switch (pk) {
    case 'run': return {
      s: `${+h.agent_tick ? L('elke {t}', { t: tickText(+h.agent_tick) }) : L('standaard (elke {t})', { t: tickText(state.agent_tick) })} · ${L('realtime')} ${onOff(+h.realtime)}`,
      own: +h.agent_tick > 0,
    };
    case 'conn': {
      const own = ['enc', 'pin_mode', 'ip_lock', 'agent_https'].filter((k) => (h[k] || '') !== '').length;
      return {
        s: `${+h.enc_seen === 1 ? '🔐 ' + L('versleuteld') : '🔓 ' + L('onversleuteld')} · ${h.agent_fb_at ? '⚠ ' + L('via het reserve-adres') : h.agent_conn === 'http' ? 'http' : 'https'}`,
        own: own > 0, attn: !!(h.agent_ip_new || h.agent_fb_at),
      };
    }
    case 'maint': return {
      s: !h.agent_seen ? L('nog nooit verbonden: installeer de agent') : +h.agent_key_seen === 1 ? '🔑 ' + L('eigen sleutel') : L('nog geen eigen sleutel: koppel opnieuw'),
      attn: !h.agent_seen || +h.agent_key_seen !== 1,
    };
    case 'sec': return { s: `EDR ${onOff(+(h.edr_on ?? 1))}` };
    case 'notify': return { s: `🐕 ${L('Waakhond')} ${+h.watchdog ? L('aan, na {n} min', { n: +h.watchdog_min || 5 }) : L('uit')}` };
    case 'snmp': return {
      s: !h.snmp ? L('uit') : h.snmp.use === 'global' ? L('algemene instelling') : L('eigen instellingen (v{v})', { v: esc(h.snmp.v || '?') }),
      own: !!(h.snmp && h.snmp.use !== 'global'),
    };
    default: return null;
  }
}

/** Een samenvatting of aandacht later bijwerken (vanuit de onderdelen die hun gegevens zelf ophalen) */
function amSum(pk, text, attn = false) {
  const it = document.querySelector(`#modal-body .am-it[data-pk="${pk}"]`);
  if (!it) return;
  if (text !== null && text !== undefined) $('.am-it-s', it).innerHTML = text;
  it.classList.toggle('am-attn', !!attn);
  amSortGroup(it.closest('.am-grp'));
}
/** Eigen instelling later bijwerken (onderdelen die hun stand zelf ophalen); met de knop terug naar algemeen */
function amOwnSet(pk, own) {
  const it = document.querySelector(`#modal-body .am-it[data-pk="${pk}"]`);
  if (!it) return;
  it.classList.toggle('own', own);
  $('.am-it-own', it).hidden = !own;
  const r = $('.am-it-reset', it);
  if (own && !r) $('.am-it-body', it).insertAdjacentHTML('afterbegin', `<div class="am-it-reset"><span class="muted small">${L('Deze server heeft hier een eigen instelling.')}</span> <button type="button" class="btn small-btn" data-am-reset>↺ ${L('Terug naar algemeen')}</button></div>`);
  if (!own && r) r.remove();
}
/** Aandacht eerst binnen een groep */
function amSortGroup(grp) {
  if (!grp) return;
  const items = [...grp.querySelectorAll(':scope > .am-it')];   // (uitleg van de groep blijft bovenaan)
  items.sort((a, b) => (+b.classList.contains('am-attn') - +a.classList.contains('am-attn')) || (+a.dataset.ord - +b.dataset.ord));
  items.forEach((x) => grp.append(x));
}

/** Terug naar de algemene instelling: zet de eigen keuzes van dit onderdeel uit, via de gewone schakelaars */
function amReset(it) {
  const pk = it.dataset.pk;
  if (pk === 'run') { const b = $('[data-sw-seg=tick] [data-v="0"]', it); if (b) b.click(); }
  if (pk === 'conn') $$('[data-am-own]', it).forEach((sw) => { if (sw.checked) { sw.checked = false; sw.dispatchEvent(new Event('change', { bubbles: true })); } });
  if (pk === 'cmd') { const sw = $('#host-cmd-own', it); if (sw && sw.checked) { sw.checked = false; sw.dispatchEvent(new Event('change', { bubbles: true })); } }
  if (pk === 'snmp') { const sw = $('#am-snmp-own', it); if (sw && sw.checked) { sw.checked = false; sw.dispatchEvent(new Event('change', { bubbles: true })); } }
  it.classList.remove('own');
  $('.am-it-own', it).hidden = true;
}

/** De kaarten van de Agent Manager omzetten naar de lijst */
function amAccordion(h, body) {
  const grid = $('.am-grid', body);
  if (!grid) return;
  const cards = Object.fromEntries($$(':scope > section.am-card', grid).map((c) => [c.dataset.pk, c]));
  const install = $('details.am-install', body);
  const hidden = amHidden(h);
  const list = document.createElement('div');
  list.className = 'am-list';
  const more = [];
  let ord = 0;
  const groups = AM_GROUPS();
  if (h.mode !== 'agent') groups.unshift(groups.splice(groups.findIndex((g) => g[0] === 'snmp'), 1)[0]);   // apparaat zonder agent: SNMP eerst
  const make = (pk) => {
    const card = cards[pk];
    if (!card) return null;
    const h3 = $('h3.am-h', card);
    const ic = h3 ? ($('i', h3)?.textContent || '') : '';
    let title = h3 ? h3.textContent.replace(ic, '').trim() : pk;
    const lead = $('p.am-lead', card);
    if (pk === 'maint') title = L('Koppelen en installeren');
    const st = amState(h, pk) || {};
    const it = document.createElement('details');
    it.className = 'am-it' + (card.classList.contains('need-admin') ? ' need-admin' : '');
    it.dataset.pk = pk;
    it.dataset.ord = String(ord++);
    it.setAttribute('name', 'am-acc');   // één tegelijk open (en de terugval hieronder voor oudere browsers)
    it.innerHTML = `<summary class="am-it-sum"><span class="am-it-ic" aria-hidden="true">${esc(ic)}</span>
      <span class="am-it-t"><b>${esc(title)}</b><small class="am-it-s">${st.s || (lead ? esc(lead.textContent) : '')}</small></span>
      <span class="am-it-own" ${st.own ? '' : 'hidden'}>${L('eigen')}</span><span class="am-it-dot" aria-hidden="true"></span>
      <svg class="am-it-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></summary><div class="am-it-body"></div>`;
    const bodyEl = $('.am-it-body', it);
    if (st.own) bodyEl.insertAdjacentHTML('beforeend', `<div class="am-it-reset"><span class="muted small">${L('Deze server heeft hier een eigen instelling.')}</span> <button type="button" class="btn small-btn" data-am-reset>↺ ${L('Terug naar algemeen')}</button></div>`);
    [...card.childNodes].forEach((n) => { if (n !== h3) bodyEl.append(n); });
    if (pk === 'maint' && install) {   // samen: sleutels, tokens en installeren
      install.classList.remove('am-sec');
      install.open = !h.agent_seen;
      bodyEl.append(install);
    }
    it.classList.toggle('own', !!st.own);
    it.classList.toggle('am-attn', !!st.attn);
    card.remove();
    return it;
  };
  for (const [key, label, pks, intro] of groups) {
    const items = pks.filter((pk) => !hidden.has(pk)).map(make).filter(Boolean);
    pks.filter((pk) => hidden.has(pk)).forEach((pk) => { const it = make(pk); if (it) more.push(it); });
    if (!items.length) continue;
    const grp = document.createElement('section');
    grp.className = 'am-grp';
    grp.dataset.grp = key;
    grp.innerHTML = `<h3 class="am-grp-h">${esc(label)}</h3>${intro ? intro() : ''}`;
    items.forEach((x) => grp.append(x));
    amSortGroup(grp);
    if (items.every((x) => x.classList.contains('need-admin'))) grp.classList.add('need-admin');
    list.append(grp);
  }
  if (more.length) {
    const grp = document.createElement('section');
    grp.className = 'am-grp am-more';
    grp.dataset.grp = 'more';
    grp.hidden = true;
    grp.innerHTML = `<h3 class="am-grp-h">${L('Past minder bij deze server')}</h3>`;
    more.forEach((x) => grp.append(x));
    list.append(grp);
    list.insertAdjacentHTML('beforeend', `<button type="button" class="btn small-btn am-more-btn" data-am-more>${L('Meer tonen ({n})', { n: more.length })}</button>`);
  }
  grid.replaceWith(list);
  const tools = $('.pg-tools[data-pgtools="am"]', body);
  if (tools) tools.remove();
  // Eén tegelijk open, ook in browsers zonder <details name>
  list.addEventListener('toggle', (e) => {
    const it = e.target;
    if (!it.matches || !it.matches('.am-it') || !it.open) return;
    $$('.am-it[open]', list).forEach((x) => { if (x !== it) x.open = false; });
  }, true);
  list.addEventListener('click', (e) => {
    const r = e.target.closest('[data-am-reset]');
    if (r) { e.preventDefault(); amReset(r.closest('.am-it')); r.closest('.am-it-reset').remove(); return; }
    if (e.target.closest('[data-am-more]')) {
      const grp = $('.am-more', list);
      grp.hidden = !grp.hidden;
      e.target.closest('[data-am-more]').textContent = grp.hidden ? L('Meer tonen ({n})', { n: more.length }) : L('Minder tonen');
    }
  });
  // Koppelcode vanuit de statusbalk: het juiste onderdeel openen
  body.addEventListener('click', (e) => {
    if (!e.target.closest('[data-am-pair]')) return;
    const it = $('.am-it[data-pk="maint"]', list);
    if (it) { it.open = true; if (it.closest('.am-more')) it.closest('.am-more').hidden = false; }
  }, true);
  // Een onderdeel dat aandacht vraagt meteen open (het eerste)
  const first = $('.am-grp:not(.am-more) .am-it.am-attn', list);
  if (first && !h.agent_seen) first.open = true;
}
