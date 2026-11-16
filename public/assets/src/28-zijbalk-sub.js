// ---------------------------------------------------------------- Subpagina's in de zijbalk (1.277.0)
// Op een breed scherm staan de tabbladen van een pagina als subpagina's onder die pagina in de zijbalk. Alleen de
// pagina waar je bent klapt open (met ▸ klap je een andere open). De tabbladen bovenaan de pagina zijn dan verborgen;
// op een telefoon (geen zijbalk) blijven ze staan. De zijbalk bedient de bestaande tabbladen: adressen, tellers en
// alles wat een tabblad doet blijven dus hetzelfde. Ingeklapte zijbalk: een klein menu als je over het icoon beweegt.

/**
 * Per pagina: q = de knoppen van de tabbladen (in die volgorde), go = het adres van een subpagina, cur = welke nu open is.
 * Pagina's waarvan de tabbladen pas bij het openen gebouwd worden: items = vaste lijst, sel = de knop om aan te klikken.
 */
const SUBNAV = {
  dashboard: { q: '#mon-tabs [data-msub]', key: 'msub', go: (k) => 'dashboard/' + k, cur: () => monSub },
  incidents: { q: '#inc-src [data-isrc]', key: 'isrc', go: (k) => 'incidents/' + k, cur: () => incSrc },
  ids: { q: '#ids-tabs > [data-it]:not([data-it="ovw"]), #ids-more-menu [data-it], #ids-an-seg [data-it]', key: 'it', go: (k) => 'ids/' + k, cur: () => idsTab, n: { anom: '#it-n-anom' } },
  vulns: { q: '#vuln-tabs [data-vt]', key: 'vt', go: (k) => 'vulns/' + k, cur: () => vulnTab },
  net: { q: '#net-tabs [data-nsub]', key: 'nsub', go: (k) => 'net/' + k, cur: () => netSub },
  logs: { items: () => [['search', L('Zoeken')], ['watch', L('Vaste zoekacties'), '#lp-w-n'], ['in', L('Binnenkomst')]], sel: (k) => `#logs-sub [data-lp-sub="${k}"]`, cur: () => LP.sub },
  analytics: {
    items: () => [['trends', L('Trends')], ['attacks', L('Aanvallen')], ['surface', L('Blootstelling')], ['rel', L('Betrouwbaarheid')], ['cap', L('Capaciteit')], ['net', L('Netwerk')], ['bk', L('Back-ups')], ['noise', L('Meldingen en ruis')]],
    sel: (k) => `.ia-tabs [data-ia-tab="${k}"]`, cur: () => iaTab,
  },
};
let sideOpen = '';   // met ▸ opengeklapt (naast de pagina waar je bent)

/** De subpagina's van een pagina: [sleutel, naam, teller] */
function sideSubItems(page) {
  const c = SUBNAV[page];
  if (c.items) return c.items().map(([k, l, nSel]) => [k, l, nSel && $(nSel) ? $(nSel).textContent.trim() : '']);
  return $$(c.q).filter((b) => !b.closest('[hidden]') || b.closest('#ids-an-seg, #ids-more-menu')).map((b) => {
    const k = b.dataset[c.key];
    const n = (c.n && c.n[k] && $(c.n[k])) || $('.it-n', b);
    return [k, crumbText(b), n ? n.textContent.trim() : ''];
  });
}

function sideSub() {
  const nav = $('#main-tabs');
  if (!nav) return;
  const active = currentTab();
  for (const page of Object.keys(SUBNAV)) {
    const tab = $(`#main-tabs .tab[data-tab="${page}"]`);
    if (!tab) continue;
    const items = sideSubItems(page);
    let box = tab.nextElementSibling && tab.nextElementSibling.classList.contains('side-sub') ? tab.nextElementSibling : null;
    if (items.length < 2) { if (box) box.remove(); tab.classList.remove('has-sub', 'sub-open'); continue; }
    if (!$('.tab-chev', tab)) tab.insertAdjacentHTML('beforeend', `<span class="tab-chev" data-chev="${page}" role="button" aria-label="${esc(L('Subpagina\'s tonen'))}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></span>`);
    if (!box) { box = document.createElement('div'); box.className = 'side-sub'; box.setAttribute('role', 'group'); box.dataset.subOf = page; tab.after(box); }
    const cur = page === active ? String(SUBNAV[page].cur()) : '';
    const html = items.map(([k, l, n]) => `<button type="button" class="side-sub-i ${k === cur ? 'active' : ''}" data-sub-page="${page}" data-sub="${esc(k)}"${k === cur ? ' aria-current="page"' : ''}><span>${esc(l)}</span><span class="side-sub-n">${esc(n)}</span></button>`).join('');
    if (box.dataset.html !== html) { box.innerHTML = html; box.dataset.html = html; }
    const open = page === active || page === sideOpen;
    box.hidden = !open;
    tab.classList.add('has-sub');
    tab.classList.toggle('sub-open', open);
  }
  moveTabIndicator(false);
}

/** Een knop klikken zodra hij er is (Logs en Analyse bouwen hun tabbladen pas bij het openen) */
function sideClickWhenReady(sel, tries = 40) {
  const b = $(sel);
  if (b) { b.click(); return; }
  if (tries > 0) setTimeout(() => sideClickWhenReady(sel, tries - 1), 100);
}

function sideSubGo(page, k) {
  const c = SUBNAV[page];
  sideFlyHide();
  if (c.go) showTab(c.go(k));
  else { if (currentTab() !== page) showTab(page); sideClickWhenReady(c.sel(k)); }
  setTimeout(sideSub, 60);
}

// ▸ klapt open of dicht zonder naar de pagina te gaan (vóór de klik op het tabblad zelf)
document.addEventListener('click', (e) => {
  const ch = e.target.closest && e.target.closest('.tab-chev');
  if (!ch) return;
  e.preventDefault();
  e.stopPropagation();
  const p = ch.dataset.chev;
  if (p !== currentTab()) sideOpen = sideOpen === p ? '' : p;   // de pagina waar je bent blijft open
  sideSub();
}, true);
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('[data-sub-page]');
  if (b) { sideSubGo(b.dataset.subPage, b.dataset.sub); return; }
  // een tabblad op de pagina zelf (telefoon) of een link: de zijbalk volgt
  if (e.target.closest && e.target.closest('[data-msub], [data-isrc], [data-it], [data-vt], [data-nsub], [data-lp-sub], [data-ia-tab], [data-goto], .main-tabs .tab')) setTimeout(sideSub, 80);
});
// Tellers komen later binnen (na het laden van een pagina): af en toe bijwerken, alleen als het scherm zichtbaar is
setInterval(() => { if (!document.hidden && SIDE_WIDE.matches) sideSub(); }, 3000);

// ---------------------------------------------------------------- ingeklapte zijbalk: menu met de subpagina's
function sideFlyHide() { const f = $('#side-fly'); if (f) f.hidden = true; }
document.addEventListener('mouseover', (e) => {
  if (!document.body.classList.contains('side-mini') || !e.target.closest) return;
  if (e.target.closest('#side-fly')) return;
  const t = e.target.closest('.app-top .main-tabs .tab[data-tab]');
  if (!t || !SUBNAV[t.dataset.tab]) { if (!e.target.closest('.app-top .main-tabs')) sideFlyHide(); return; }
  const page = t.dataset.tab;
  const items = sideSubItems(page);
  if (items.length < 2) return;
  let f = $('#side-fly');
  if (!f) { f = document.createElement('div'); f.id = 'side-fly'; f.className = 'side-fly'; f.setAttribute('role', 'menu'); document.body.appendChild(f); }
  const cur = page === currentTab() ? String(SUBNAV[page].cur()) : '';
  f.innerHTML = `<div class="side-fly-h">${esc(crumbText($('.tab-lbl', t) || t))}</div>` + items.map(([k, l, n]) =>
    `<button type="button" role="menuitem" class="side-sub-i ${k === cur ? 'active' : ''}" data-sub-page="${page}" data-sub="${esc(k)}"><span>${esc(l)}</span><span class="side-sub-n">${esc(n)}</span></button>`).join('');
  const r = t.getBoundingClientRect(), side = $('.app-top').getBoundingClientRect();
  f.style.left = Math.round(side.right + 6) + 'px';
  f.style.top = Math.round(Math.min(r.top, window.innerHeight - 40 - items.length * 34)) + 'px';
  f.hidden = false;
  const tip = $('#side-tip');
  if (tip) tip.hidden = true;
});
document.addEventListener('mouseout', (e) => {
  const to = e.relatedTarget;
  if (to && to.closest && (to.closest('#side-fly') || to.closest('.app-top .main-tabs .tab[data-tab]'))) return;
  if (e.target.closest && (e.target.closest('#side-fly') || e.target.closest('.app-top .main-tabs .tab[data-tab]'))) sideFlyHide();
});
