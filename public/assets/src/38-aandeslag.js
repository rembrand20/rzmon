// ---------------------------------------------------------------- 1.323.0: Aan de slag
// Een wizard voor de beheerder na de eerste installatie (profiel, eerste server, e-mail, RzSense beveiligen, aanraders),
// een checklist bovenaan Instellingen → Status en functies, en een rondleiding voor gewone gebruikers.
// De wizard geeft alleen advies: elke knop opent de pagina waar je het zelf instelt. Een vinkje komt pas als het echt werkt.

let obData = null, obHealth = null, wzStep = 1, wzPoll = 0;
const WZ_STEPS = () => [L('Profiel'), L('Eerste server'), L('Meldingen'), L('RzSense beveiligen'), L('Aanraders'), L('Klaar')];
const OB_PROFILES = () => ({
  thuis: [L('Thuis'), L('Een paar servers, een NAS, Home Assistant en Shelly\'s.')],
  homelab: [L('Homelab'), L('Veel servers en containers, een reverse proxy, apps van buiten bereikbaar.')],
  bedrijf: [L('Bedrijf'), L('Servers voor werk of klanten, rapporten en NIS2.')],
});
/** Aanraders per profiel: [naam, aanbevolen?, waarom, waar (pagina), knoptekst] */
const OB_RECS = () => ({
  thuis: [
    [L('Shelly\'s'), true, L('Verbruik en oververhitting, en een melding als de vriezer stil valt.'), 'dashboard/shelly', L('Monitoring → Shelly\'s')],
    [L('IDS'), true, L('Ziet inlogpogingen en aanvallen op je servers, en kan de aanvaller blokkeren.'), 'settings/ids', L('Instellingen → IDS algemeen')],
    [L('Kwetsbaarheden'), false, L('Welke updates echte lekken dichten.'), 'vulns', L('Kwetsbaarheden')],
    [L('Home Assistant'), false, L('Optioneel: je servers als sensoren in Home Assistant, en meldingen in de app.'), 'settings/mqtt', L('Instellingen → Home Assistant')],
  ],
  homelab: [
    [L('IDS en blokkeren'), true, L('Ziet aanvallen op je servers en blokkeert de aanvaller op al je servers tegelijk.'), 'settings/ids', L('Instellingen → IDS algemeen')],
    [L('Reverse proxy en WAF'), true, L('Je apps van buiten: beheerpagina\'s alleen thuis, aanvallen tegengehouden.'), 'settings/proxy', L('Instellingen → Reverse proxy en WAF')],
    [L('Kwetsbaarheden'), true, L('Welke lekken je echt draait, en welke update ze dicht.'), 'vulns', L('Kwetsbaarheden')],
    [L('Containers'), false, L('Welke containers draaien, herstarten of verouderd zijn.'), 'dashboard/cont', L('Monitoring → Containers')],
  ],
  bedrijf: [
    [L('Rapporten'), true, L('Wekelijks per e-mail: beschikbaarheid, incidenten en wat er gedaan is.'), 'settings/reports', L('Instellingen → Rapporten')],
    [L('NIS2 en compliance'), true, L('Wat je al op orde hebt en wat nog ontbreekt.'), 'compliance', L('Compliance')],
    [L('IDS en blokkeren'), true, L('Aanvallen zien en tegenhouden, met een logboek van wat er gebeurde.'), 'settings/ids', L('Instellingen → IDS algemeen')],
    [L('Gebruikers en rechten'), false, L('Collega\'s met alleen-lezen of eigen rechten.'), 'settings/users', L('Instellingen → Gebruikers')],
  ],
});

async function obLoad() {
  try { obData = await api('onboard_get'); } catch (ex) { obData = null; }
  return obData;
}
async function obLoadHealth() {
  try { obHealth = (await api('settings_health')).items; } catch (ex) { obHealth = []; }
  return obHealth;
}

/**
 * De punten van de checklist, uit Aan de slag (profiel, server, e-mail) en de bestaande controles van Status en functies.
 * @returns {{key: string, ok: boolean, bad: boolean, title: string, sub: string, go: string, hk: string}[]}
 */
function obItems(ob, health) {
  const h = (k) => (health || []).find((x) => x.key === k);
  const https = h('https');
  const list = [
    { key: 'profile', ok: !!ob.profile, title: L('Profiel kiezen'), sub: ob.profile ? OB_PROFILES()[ob.profile][0] : L('Zo stelt de wizard voor wat bij je past.'), go: 'wizard:1' },
    { key: 'server', ok: ob.agents > 0, title: L('Eerste server met agent'), sub: ob.first ? L('{s} meldt zich', { s: ob.first.name }) : L('Plak de installatieopdracht op je server; de agent meldt zich binnen een minuut.'), go: 'wizard:2' },
    { key: 'mail', ok: ob.mail_tested, hk: 'channels', title: L('E-mail instellen en een proefmelding sturen'), sub: ob.mail_tested ? L('proefmelding verstuurd') : ob.mail ? L('ingesteld; stuur nog een proefmelding') : L('Je vangnet: werkt ook als een app het even niet doet.'), go: 'settings/channels' },
  ];
  const fromHealth = (k, title, go) => { const x = h(k); if (x) list.push({ key: k, hk: k, ok: x.status === 'ok', bad: x.status === 'bad', title, sub: x.detail || '', go: go || 'settings/' + x.section }); };
  if (https) fromHealth('https', 'HTTPS', 'settings/outside');
  else list.push({ key: 'https', ok: location.protocol === 'https:', title: 'HTTPS', sub: location.protocol === 'https:' ? L('de verbinding is versleuteld') : L('je opent RzSense via http: wachtwoorden gaan onversleuteld over het netwerk'), go: 'settings/outside' });
  fromHealth('2fa', L('Inloggen in twee stappen (2FA)'));
  fromHealth('backup', L('Back-up van RzSense'), 'settings/backup');
  fromHealth('update', L('RzSense is bijgewerkt'), 'settings/update');
  return list;
}

/** Ga naar een punt: de wizard op een stap, of een pagina (de wizard klapt dan in tot een knopje "verder") */
function obGo(go) {
  if (go.startsWith('wizard:')) return wzOpen(+go.slice(7));
  showTab(go);
}

// ---------------------------------------------------------------- De checklist op Status en functies
/** HTML van het blok Aan de slag, of '' als alles klaar is. keys: controles die daardoor niet dubbel onderaan hoeven */
function obChecklist(ob, health) {
  const items = obItems(ob, health);
  const done = items.filter((x) => x.ok).length;
  if (done === items.length) return { html: '', keys: [] };
  const ic = (x, i) => `<span class="ads-ic ${x.ok ? 'ok' : x.bad ? 'bad' : 'warn'}" aria-hidden="true">${x.ok ? '✔' : i + 1}</span>`;
  const html = `<section class="ads" aria-labelledby="ads-h">
    <div class="ads-head"><div class="ads-hl"><h2 id="ads-h">${esc(L('Aan de slag'))} <span class="muted">· ${esc(L('{n} van {t} klaar', { n: done, t: items.length }))}</span></h2>
      <div class="ads-bar" aria-hidden="true"><i style="width:${Math.round(done / items.length * 100)}%"></i></div></div>
      <button type="button" class="btn small-btn" data-ob-open="wizard">${esc(L('Wizard openen'))}</button></div>
    ${items.map((x, i) => `<div class="ads-row${x.ok ? ' done' : ''}">${ic(x, i)}<span class="ads-txt"><b>${esc(x.title)}</b>${x.sub ? `<small>${esc(x.sub)}</small>` : ''}</span>
      ${x.ok ? '' : `<button type="button" class="link-btn" data-ob-go="${esc(x.go)}">${esc(L('Openen'))} →</button>`}</div>`).join('')}
    <p class="ads-foot muted small">${esc(L('Een vinkje komt pas als het echt werkt. Is alles klaar, dan verdwijnt dit blok vanzelf.'))}</p>
  </section>`;
  return { html, keys: items.map((x) => x.hk).filter(Boolean) };
}

// ---------------------------------------------------------------- De wizard
function wzEl() {
  let el = $('#wz');
  if (!el) {
    el = document.createElement('div');
    el.id = 'wz';
    el.className = 'wz';
    el.hidden = true;
    el.innerHTML = `<div class="wz-scrim"></div><section class="wz-box" role="dialog" aria-modal="true" aria-labelledby="wz-title"><nav class="wz-nav" aria-label="${esc(L('Stappen'))}"></nav><div class="wz-main"></div></section>`;
    document.body.append(el);
    el.addEventListener('click', wzClick);
    el.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); wzClose(); } });
  }
  return el;
}
async function wzOpen(step) {
  if (!isAdmin()) return;
  const pill = $('#wz-pill');
  if (pill) pill.remove();
  if (step) wzStep = step;
  const el = wzEl();
  el.hidden = false;
  document.body.classList.add('wz-on');
  if (!obData) await obLoad();
  if (!obData) { el.hidden = true; document.body.classList.remove('wz-on'); return toast(L('Aan de slag kon niet laden'), 'err'); }
  if (wzStep >= 4 && !obHealth) await obLoadHealth();
  wzRender(true);
}
/** Later verder: dicht, deze sessie niet meer vanzelf */
function wzClose() {
  const el = $('#wz');
  if (el) el.hidden = true;
  document.body.classList.remove('wz-on');
  clearTimeout(wzPoll);
  try { sessionStorage.setItem('rzs-wz-later', '1'); } catch (e) { /* alleen nu */ }
}
/** Naar een pagina om het daar zelf in te stellen; de wizard wordt een knopje rechtsonder om verder te gaan */
function wzAway(to) {
  wzClose();
  showTab(to);
  if ($('#wz-pill')) return;
  const p = document.createElement('div');
  p.id = 'wz-pill';
  p.className = 'wz-pill';
  p.innerHTML = `<button type="button" class="wz-pill-go" data-ob-open="wizard">🧭 ${esc(L('Aan de slag · stap {n} van {t}', { n: wzStep, t: WZ_STEPS().length }))} <b>${esc(L('Verder'))} →</b></button>
    <button type="button" class="wz-pill-x" aria-label="${esc(L('Verbergen'))}">✕</button>`;
  p.querySelector('.wz-pill-x').onclick = () => p.remove();
  document.body.append(p);
}

function wzNav() {
  const ob = obData || {};
  const done = (i) => (i === 1 ? !!ob.profile : i === 2 ? ob.agents > 0 : i === 3 ? !!ob.mail_tested : false);
  return `<p class="wz-eyebrow">${esc(L('Aan de slag'))}</p>
    ${WZ_STEPS().map((l, k) => { const i = k + 1, on = i === wzStep, ok = done(i) && !on;
      return `<button type="button" class="wz-step${on ? ' on' : ''}${ok ? ' ok' : ''}" data-wz-step="${i}" ${on ? 'aria-current="step"' : ''}><span class="wz-dot">${ok ? '✔' : i}</span><span>${esc(l)}</span></button>`; }).join('')}
    <p class="wz-note">${esc(L('De wizard geeft alleen advies. Aanzetten doe je zelf; hij verandert niets.'))}</p>`;
}

function wzBody() {
  const ob = obData, n = WZ_STEPS().length;
  const head = (t, sub) => `<p class="wz-count">${esc(L('Stap {n} van {t}', { n: wzStep, t: n }))}</p><h1 id="wz-title" tabindex="-1">${esc(t)}</h1>${sub ? `<p class="wz-lead">${sub}</p>` : ''}`;
  if (wzStep === 1) {
    const P = OB_PROFILES();
    return head(L('Welkom! Wat wil je bewaken?'), esc(L('Daarmee stelt de wizard voor wat bij jou past. Je kunt later altijd alles aanzetten.')))
      + `<div class="wz-profiles" role="radiogroup" aria-label="${esc(L('Profiel'))}">${Object.keys(P).map((k) => `<button type="button" role="radio" aria-checked="${ob.profile === k}" class="wz-prof${ob.profile === k ? ' on' : ''}" data-wz-prof="${k}"><b>${esc(P[k][0])}</b><span>${esc(P[k][1])}</span></button>`).join('')}</div>`;
  }
  if (wzStep === 2) {
    const ok = ob.agents > 0;
    return head(L('Je eerste server'), esc(L('Voeg een server toe en plak de installatieopdracht die je krijgt op die server. De agent meldt zich binnen een minuut; daarna vindt RzSense zelf de checks.')))
      + `<div class="wz-card"><div class="wz-card-h"><b>${esc(L('Server toevoegen'))}</b></div>
        <p class="muted small">${esc(L('Linux, Windows of de Home Assistant-add-on: in het venster kies je wat het is en krijg je de opdracht of het bestand om te kopiëren.'))}</p>
        <button type="button" class="btn primary" data-wz-addhost>＋ ${esc(L('Server toevoegen'))}</button></div>
      ${ok ? `<div class="wz-ok">✔ <span><b>${esc(L('{s} meldt zich!', { s: ob.first ? ob.first.name : L('Je server') }))}</b> ${ob.first ? esc(L('{n} checks gevonden.', { n: ob.first.checks })) : ''}</span></div>`
    : `<div class="wz-wait"><span class="wz-spin" aria-hidden="true"></span><span>${esc(L('Wacht op je server…'))} <span class="muted">${esc(L('dit scherm ziet het vanzelf'))}</span></span></div>`}
      <p class="wz-tip">${esc(L('Tip: begin met de server die het belangrijkst is. Meer servers voeg je later toe bij Servers.'))}</p>`;
  }
  if (wzStep === 3) {
    const st = ob.mail_tested ? ['ok', L('proefmelding verstuurd')] : ob.mail ? ['warn', L('ingesteld; stuur nog een proefmelding')] : ['warn', L('nog niet ingesteld')];
    return head(L('Hoe hoor je het als er iets mis is?'), esc(L('Ons advies: begin met e-mail. Dat werkt overal en is je vangnet als een app het even niet doet.')))
      + `<div class="wz-card"><div class="wz-card-h"><b>${esc(L('E-mail'))}</b><span class="wz-pill-st ${st[0]}">${esc(st[1])}</span></div>
        <p class="muted small">${esc(L('Wat je nodig hebt: de SMTP-server van je provider, poort 587, een gebruikersnaam met app-wachtwoord, en het adres waar de meldingen heen gaan. Stuur daarna een proefmelding.'))}</p>
        <button type="button" class="btn primary" data-wz-go="settings/channels">${esc(L('Open Instellingen → Kanalen'))}</button></div>
      <p class="wz-sub"><b>${esc(L('Daarna, als je wilt'))}</b><br><span class="muted">${esc(L('Home Assistant-app (meldingen op je telefoon) · Pushover · ntfy · Web Push in je browser'))}</span></p>`;
  }
  if (wzStep === 4) {
    const items = obItems(ob, obHealth).filter((x) => !['profile', 'server', 'mail'].includes(x.key));
    return head(L('RzSense zelf goed beveiligen'), esc(L('RzSense ziet al je servers. Deze punten houden hem veilig.')))
      + `<div class="wz-list">${items.map((x) => `<div class="wz-li"><span class="ads-ic ${x.ok ? 'ok' : x.bad ? 'bad' : 'warn'}" aria-hidden="true">${x.ok ? '✔' : x.bad ? '✘' : '!'}</span>
        <span class="ads-txt"><b>${esc(x.title)}</b><small>${esc(x.sub)}</small></span>${x.ok ? '' : `<button type="button" class="link-btn" data-wz-go="${esc(x.go)}">${esc(L('Openen'))} →</button>`}</div>`).join('')}</div>`;
  }
  if (wzStep === 5) {
    const prof = ob.profile || 'thuis', P = OB_PROFILES();
    return head(L('Aanraders voor {p}', { p: P[prof][0] }), esc(L('Wat de meeste waarde geeft voor jouw situatie. Je zet het zelf aan op de pagina erachter.'))
      + (ob.profile ? '' : ' ' + esc(L('(Kies bij stap 1 een profiel voor aanraders op maat.)'))))
      + `<div class="wz-recs">${OB_RECS()[prof].map(([name, rec, why, go, where]) => `<div class="wz-rec"><div class="wz-card-h"><b>${esc(name)}</b><span class="wz-tag${rec ? ' rec' : ''}">${esc(rec ? L('Aanbevolen') : L('Handig'))}</span></div>
        <span class="muted small">${esc(why)}</span><button type="button" class="link-btn" data-wz-go="${esc(go)}">${esc(where)} →</button></div>`).join('')}</div>`;
  }
  const items = obItems(ob, obHealth), done = items.filter((x) => x.ok).length;
  return head(L('Je bent op weg'), esc(L('{n} van de {t} punten zijn klaar. Wat nog openstaat, blijft staan bij Instellingen → Status en functies → Aan de slag, en verdwijnt vanzelf zodra het werkt.', { n: done, t: items.length })))
    + `<ul class="wz-sum">${items.map((x) => `<li class="${x.ok ? 'ok' : 'todo'}">${x.ok ? '✔' : '○'} ${esc(x.title)}</li>`).join('')}</ul>`;
}

function wzRender(focus) {
  const el = wzEl();
  $('.wz-nav', el).innerHTML = wzNav();
  const last = wzStep === WZ_STEPS().length;
  $('.wz-main', el).innerHTML = `<div class="wz-top"><button type="button" class="wz-later" data-wz-later>${esc(L('Later verder'))} ✕</button></div>
    <div class="wz-body">${wzBody()}</div>
    <div class="wz-foot"><button type="button" class="btn" data-wz-prev ${wzStep === 1 ? 'disabled' : ''}>${esc(L('Vorige'))}</button><span class="wz-flex"></span>
      ${last ? '' : `<button type="button" class="link-btn" data-wz-next>${esc(L('Overslaan'))}</button>`}
      <button type="button" class="btn primary" ${last ? 'data-wz-finish' : 'data-wz-next'}>${esc(last ? L('Naar het Dashboard') : L('Volgende'))}</button></div>`;
  if (focus) $('#wz-title', el)?.focus();
  clearTimeout(wzPoll);
  // Stap 2 en 3: kijken of de server zich meldt of de proefmail verstuurd is (elke 5 s, zolang de wizard open staat)
  if ((wzStep === 2 && !(obData.agents > 0)) || (wzStep === 3 && !obData.mail_tested)) {
    wzPoll = setTimeout(async () => { if (!el.hidden && !document.hidden) { await obLoad(); if (obData && !el.hidden) wzRender(false); } }, 5000);
  }
}

async function wzClick(e) {
  const t = e.target.closest('button');
  if (!t) return;
  if (t.dataset.wzStep) { wzStep = +t.dataset.wzStep; if (wzStep >= 4 && !obHealth) await obLoadHealth(); return wzRender(true); }
  if (t.dataset.wzLater !== undefined) return wzClose();
  if (t.dataset.wzProf) {
    try { obData = await api('onboard_save', { profile: t.dataset.wzProf }); } catch (ex) { return toast(ex.message, 'err'); }
    return wzRender(false);
  }
  if (t.dataset.wzAddhost !== undefined) return hostForm();   // het bestaande venster; de wizard blijft eronder open
  if (t.dataset.wzGo) return wzAway(t.dataset.wzGo);
  if (t.dataset.wzPrev !== undefined) { wzStep = Math.max(1, wzStep - 1); return wzRender(true); }
  if (t.dataset.wzNext !== undefined) {
    wzStep = Math.min(WZ_STEPS().length, wzStep + 1);
    if (wzStep >= 4) await obLoadHealth();
    return wzRender(true);
  }
  if (t.dataset.wzFinish !== undefined) {
    try { obData = await api('onboard_save', { done: 1 }); } catch (ex) { /* niet erg */ }
    wzClose();
    showTab('home');
  }
}

// ---------------------------------------------------------------- Rondleiding (gewone gebruikers; via het accountmenu voor iedereen)
let tourI = 0, tourList = [];
const TOUR = () => [
  { sel: '.main-tabs .tab[data-tab="home"]', tab: 'home', title: L('Het Dashboard'), text: L('Hier begin je. Bovenaan één zin hoe het ervoor staat; daaronder wat aandacht nodig heeft.') },
  { sel: '.main-tabs .tab[data-tab="dashboard"]', title: L('Monitoring'), text: L('Al je servers en checks. Klik een server open voor wat hij meet en de laatste 24 uur.') },
  { sel: '.main-tabs .tab[data-tab="alerts"]', title: L('Meldingen'), text: L('Wat er misging en wanneer. Wat je mag doen (bekijken, bevestigen, instellen) hangt af van je rechten.') },
  { sel: '#home .hd-strip, #home .hm-sum, #home .cc-grid', tab: 'home', title: L('Klik op een cijfer'), text: L('Elk cijfer of blok brengt je naar de pagina erachter, bijvoorbeeld de open problemen.') },
  { sel: '#acct-btn', title: L('Jouw weergave'), text: L('Thema, licht, donker of Schemer, en de lay-out kies je in dit menu. Dat geldt alleen voor jou. De rondleiding vind je hier ook terug.') },
];
function tourStart() {
  tourList = TOUR();
  tourI = 0;
  let el = $('#tour');
  if (!el) {
    el = document.createElement('div');
    el.id = 'tour';
    el.className = 'tour';
    el.innerHTML = `<div class="tour-spot" aria-hidden="true"></div><section class="tour-pop" role="dialog" aria-modal="true" aria-labelledby="tour-h"></section>`;
    document.body.append(el);
    el.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.tour === 'next') tourShow(tourI + 1);
      else if (b.dataset.tour === 'prev') tourShow(tourI - 1);
      else tourEnd();
    });
    el.addEventListener('keydown', (e) => { if (e.key === 'Escape') tourEnd(); });
    window.addEventListener('resize', () => { if (!el.hidden) tourPlace(); });
  }
  el.hidden = false;
  tourShow(0);
}
function tourEnd() {
  const el = $('#tour');
  if (el) el.hidden = true;
  if (!PREFS.tour_seen) savePref('tour_seen', '1');
}
function tourShow(i) {
  if (i >= tourList.length) return tourEnd();
  tourI = Math.max(0, i);
  const s = tourList[tourI];
  if (s.tab && $('#tab-' + s.tab)?.hidden) showTab(s.tab);
  const pop = $('#tour .tour-pop'), last = tourI === tourList.length - 1;
  pop.innerHTML = `<p class="tour-n">${esc(L('{n} van {t}', { n: tourI + 1, t: tourList.length }))}</p><h2 id="tour-h" tabindex="-1">${esc(s.title)}</h2><p>${esc(s.text)}</p>
    <div class="tour-acts"><button type="button" class="link-btn" data-tour="end">${esc(L('Overslaan'))}</button><span class="wz-flex"></span>
      ${tourI ? `<button type="button" class="btn" data-tour="prev">${esc(L('Vorige'))}</button>` : ''}<button type="button" class="btn primary" data-tour="next">${esc(last ? L('Klaar') : L('Volgende'))}</button></div>`;
  setTimeout(() => { tourPlace(); $('#tour-h')?.focus(); }, s.tab ? 350 : 0);
}
function tourPlace() {
  const s = tourList[tourI], el = $('#tour');
  if (!s || !el) return;
  const t = [...document.querySelectorAll(s.sel)].find((x) => x.offsetParent);
  const spot = $('.tour-spot', el), pop = $('.tour-pop', el);
  if (!t) { spot.style.cssText = 'left:50%;top:40%;width:0;height:0'; pop.style.left = Math.max(12, (innerWidth - pop.offsetWidth) / 2) + 'px'; pop.style.top = '30%'; return; }
  t.scrollIntoView({ block: 'nearest' });
  const r = t.getBoundingClientRect(), pad = 6;
  spot.style.cssText = `left:${r.left - pad}px;top:${r.top - pad}px;width:${r.width + pad * 2}px;height:${r.height + pad * 2}px`;
  const pw = pop.offsetWidth, ph = pop.offsetHeight, gap = 16;
  let x = r.right + gap, y = r.top - 8;
  if (x + pw > innerWidth - 12) { x = Math.min(Math.max(12, r.left), innerWidth - pw - 12); y = r.bottom + gap; }
  if (y + ph > innerHeight - 12) y = Math.max(12, r.top - ph - gap);
  pop.style.left = `${Math.max(12, Math.min(x, innerWidth - pw - 12))}px`;
  pop.style.top = `${Math.max(12, Math.min(y, innerHeight - ph - 12))}px`;   // altijd helemaal in beeld
}

// ---------------------------------------------------------------- Openen: vanzelf, het accountmenu en de checklist
document.addEventListener('click', (e) => {
  const o = e.target.closest && e.target.closest('[data-ob-open]');
  const g = e.target.closest && e.target.closest('[data-ob-go]');
  if (!o && !g) return;
  e.preventDefault();
  const menu = $('#acct-menu');
  if (menu && !menu.hidden) { menu.hidden = true; $('#acct-btn')?.setAttribute('aria-expanded', 'false'); }
  if (g) return obGo(g.dataset.obGo);
  if (o.dataset.obOpen === 'tour') return tourStart();
  wzOpen();
});
/** Bij het laden: een beheerder op een nieuwe installatie krijgt de wizard, een gewone gebruiker één keer de rondleiding */
function obAutoStart() {
  const role = document.body.dataset.role;
  if (!role || !$('#main-tabs') || $('#login-form')) return;
  if (role === 'admin') {
    let later = false;
    try { later = sessionStorage.getItem('rzs-wz-later') === '1'; } catch (e) { /* geen opslag */ }
    if (!later) obLoad().then((d) => { if (d && d.fresh) wzOpen(1); });
  } else if (!PREFS.tour_seen && !prefsLocal) tourStart();
}
setTimeout(obAutoStart, 2500);
