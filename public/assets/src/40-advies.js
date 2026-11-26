// ---------------------------------------------------------------- 1.333.0: Advies op één plek
// RzSense gaf advies op zeven plaatsen (Analyse en advies, slimme voorstellen, de statuscontroles van de beheerportal,
// Aan de slag, trends, voorstellen voor de reverse proxy en wat RzSense zelf aanpaste). Het dashboard toont ze nu samen
// in één blok, de belangrijkste bovenaan; "Alles bekijken" opent de hele lijst per bron. De bronnen zelf beslissen.
const ADV_SRC = { auto: () => L('Zelf aangepast'), start: () => L('Aan de slag'), status: () => L('Status van RzSense'), smart: () => L('Slimme voorstellen'),
  trends: () => L('Trends'), proxy: () => L('Reverse proxy'), analyse: () => L('Analyse en advies') };
let advAll = null, advHidden = 0;

/** Wat er op de knop staat */
function advLabel(x, act) {
  if (act === 'go') return x.src === 'status' || x.src === 'start' ? L('Instellen') : x.src === 'auto' ? L('Logboek') : L('Bekijken');
  if (act === 'apply') return L('Toepassen');
  if (act === 'later') return L('Later');
  return x.src === 'auto' ? L('Gezien') : L('Negeren');   // ignore (voorstel) en na (niet van toepassing)
}

/** Eén advies als rij; max = hoeveel knoppen (op het dashboard twee, in het venster alle) */
function advRow(x, max = 2, src = true) {
  const st = x.sev === 2 ? 'crit' : x.sev === 1 ? 'warn' : 'unk';
  return `<div class="hd-row hd-arow" data-k="adv-${esc(x.id)}">
    ${st === 'unk' ? `<span class="hd-ic" aria-hidden="true">${esc(x.icon || '💡')}</span>` : hmShape(st)}
    <span class="hd-txt"><b>${esc(x.title)}</b><small>${esc([x.sub, src ? ADV_SRC[x.src]().toLowerCase() : ''].filter(Boolean).join(' · '))}</small></span>
    <span class="hd-acts">${(x.acts || []).slice(0, max).map((a, i) => `<button type="button" class="btn small-btn${i === 0 ? ' primary' : ''}" data-adv-act="${esc(a)}" data-adv-id="${esc(x.id)}">${esc(advLabel(x, a))}</button>`).join('')}</span></div>`;
}

/** Het blok op het dashboard (L1nes) */
function advBox(d) {
  const a = d.advice;
  if (!a) return '';
  return `<section class="hd-box hd-adv" aria-labelledby="hd-h-adv">
    <div class="hd-head"><h2 id="hd-h-adv">${esc(L('Advies'))}</h2><span class="hd-right">${a.n ? `<span class="hd-count" title="${esc(a.high ? L('{n} met hoge prioriteit', { n: a.high }) : '')}">${a.n}</span>` : ''}
      ${a.n ? `<button type="button" class="hd-link" data-adv-all>${esc(L('Alles bekijken'))}</button>` : ''}</span></div>
    ${a.top.length ? a.top.map((x) => advRow(x)).join('')
      + (a.n > a.top.length ? `<button type="button" class="hd-more" data-adv-all>${esc(L('en nog {n}', { n: a.n - a.top.length }))} →</button>` : '')
    : `<p class="hd-empty">${hmShape('ok', 12)} ${esc(L('Geen advies: RzSense heeft niets gevonden om te verbeteren'))}</p>`}
  </section>`;
}

/** De kaart in Bloxzz: de bovenste adviezen; een klik opent de hele lijst */
function advCard(d) {
  const a = d.advice;
  if (!a) return '';
  return ccCard('advice', `${ccHead(L('Advies'), `<span class="cc-mu small">${esc(L('{n} open', { n: a.n }))}</span>`)}
    ${d.morning ? `<p class="cc-morning">☀️ ${esc(d.morning.text)}</p>` : ''}
    ${a.top.length ? `<ul class="cc-list">${a.top.map((x) => `<li><button type="button" class="cc-row" data-adv-all><i class="cc-bar ${x.sev === 2 ? 'crit' : x.sev === 1 ? 'warn' : 'cy'}"></i>
      <span><b>${esc(x.title)}</b><small class="cc-mu">${esc([x.sub, ADV_SRC[x.src]().toLowerCase()].filter(Boolean).join(' · '))}</small></span></button></li>`).join('')}</ul>`
    : `<p class="cc-mu">${esc(L('Geen advies: RzSense heeft niets gevonden om te verbeteren'))}</p>`}
    ${a.n ? `<button type="button" class="cc-link" data-adv-all>${esc(L('Alles bekijken'))} →</button>` : ''}`);
}

const advFind = (id) => [...(advAll || []), ...((hmData && hmData.advice && hmData.advice.top) || [])].find((x) => x.id === id);

/** Naar de plek waar je het regelt */
function advGo(x) {
  if ($('#modal').open) $('#modal').close();
  if (x.go.startsWith('wizard:')) return obGo(x.go);
  if (x.go.startsWith('insight:')) { iaOpen.add(x.go.slice(8)); iaFilter = 'open'; return showTab('analytics'); }
  if (x.go === 'trends') { iaTab = 'trends'; return showTab('analytics'); }
  showTab(x.go);
}

async function advReload() {
  const r = await api('advice_hub');
  advAll = r.items; advHidden = r.hidden || 0;
  if (hmData) { hmData.advice = { n: advAll.length, high: advAll.filter((x) => x.sev === 2).length, top: advAll.slice(0, 5) }; renderHome(); }
  const box = $('#adv-all');
  if (box) box.innerHTML = advAllHtml();
}

function advAllHtml() {
  const list = advAll || [];
  if (!list.length && !advHidden) return `<p class="muted">✔ ${esc(L('Geen advies: RzSense heeft niets gevonden om te verbeteren'))}</p>`;
  const groups = Object.keys(ADV_SRC).map((s) => [s, list.filter((x) => x.src === s)]).filter(([, l]) => l.length);
  return groups.map(([s, l]) => `<h3 class="adv-h">${esc(ADV_SRC[s]())} <span class="hd-count">${l.length}</span></h3><div class="adv-grp">${l.map((x) => advRow(x, 3, false)).join('')}</div>`).join('')
    + (advHidden ? `<p class="muted small adv-foot">${esc(advHidden === 1 ? L('1 advies is verborgen (later of genegeerd).') : L('{n} adviezen zijn verborgen (later of genegeerd).', { n: advHidden }))}
        <button type="button" class="link-btn" data-adv-reset>${esc(L('Weer tonen'))}</button></p>` : '');
}

async function advDialog() {
  modal({ title: '💡 ' + L('Advies'), wide: true, body: `<p class="muted small">${esc(L('Alles wat RzSense je aanraadt, op één plek. De belangrijkste staan bovenaan.'))}</p><div id="adv-all"><p class="muted">${esc(L('Laden…'))}</p></div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }] });
  try { await advReload(); } catch (ex) { const box = $('#adv-all'); if (box) box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

document.addEventListener('click', async (e) => {
  // 1.335.0: de zoek- en vraagbalk en het wegklikken van de ochtendsamenvatting op het dashboard
  const o = e.target.closest ? e.target.closest('[data-cmdk-open], [data-morning-hide]') : null;
  if (o && o.dataset.cmdkOpen !== undefined) return cmdkOpen();
  if (o) { try { localStorage.setItem('rzmon-morning-hide', o.dataset.morningHide); } catch (ex) { /* geen opslag: dan alleen nu */ } if (hmData) hmData.morning = null; return renderHome(); }
  const b = e.target.closest ? e.target.closest('[data-adv-act], [data-adv-all], [data-adv-reset]') : null;
  if (!b) return;
  if (b.dataset.advAll !== undefined) { e.stopPropagation(); return advDialog(); }
  b.disabled = true;
  try {
    if (b.dataset.advReset !== undefined) { await api('advice_hub_set', { id: 'all', status: 'open' }); return await advReload(); }
    const id = b.dataset.advId, act = b.dataset.advAct, x = advFind(id);
    if (!x) return;
    if (act === 'go') { b.disabled = false; return advGo(x); }
    if (act === 'apply' || act === 'ignore') {
      const r = await api('advice_decide', { id: +id.split(':')[1], apply: act === 'apply' });
      toast(r.message);
      if (act === 'apply') refresh();
    } else {
      await api('advice_hub_set', { id, status: act });
      toast(act === 'later' ? L('Dit advies komt over 7 dagen terug') : L('Dit advies komt niet meer terug'));
    }
    await advReload();
  } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
});
