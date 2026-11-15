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
