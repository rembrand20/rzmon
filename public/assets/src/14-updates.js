// ---------------------------------------------------------------- 🩹 Updates installeren (patchbeheer, 1.230.0, agent 1.92.0)
// Kwetsbaarheden → Updates (overzicht en nu installeren), de kaart in de Agent Manager (keuze per server) en
// Instellingen → Beveiliging → Updates. Bijwerken zelf loopt via dezelfde opdracht als "pakketten bijwerken" (Vulns),
// dus de voortgang is hetzelfde venster (vulnUpgradeDialog).
const PATCH_MODE = { manual: () => L('met de hand'), window: () => L('in het onderhoudsvenster'), auto: () => L('elke dag') };
const PATCH_KIND = { ha: () => '🏠 Home Assistant', truenas: () => '🗄️ TrueNAS', linux: () => '🐧 Linux' };
let patchData = null;

const patchModeTxt = (h) => (h.mode === 'auto' ? L('elke dag om {t}', { t: h.time }) : (PATCH_MODE[h.mode] || (() => h.mode))());
const patchCount = (h) => (h.kind === 'ha' ? h.list.filter((x) => x[0] !== 'os').length : h.scope === 'all' ? h.avail : h.sec);

function patchBadge(h) {
  if (h.kind === 'truenas') return `<span class="muted small">${L('via TrueNAS zelf')}</span>`;
  if (!h.agent_ok) return `<span class="muted small">${L('agent 1.92.0 nodig')}</span>`;
  if (!h.at) return `<span class="muted small">${L('wacht op het eerste rapport')}</span>`;
  if (h.kind === 'ha') return h.avail ? `<b class="warn">${L('{n} updates', { n: h.avail })}</b>` : `<span class="ok">✔ ${L('alles bij')}</span>`;
  return h.avail ? `${h.sec ? `<b class="bad">🛡 ${L('{n} beveiliging', { n: h.sec })}</b> · ` : ''}${L('{n} updates', { n: h.avail })}` : `<span class="ok">✔ ${L('alles bij')}</span>`;
}

async function loadPatch() {
  const box = $('#patch-body');
  if (!box) return;
  try { patchData = await api('patch'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const d = patchData;
  const hs = d.hosts;
  const sec = hs.reduce((n, h) => n + (h.kind === 'linux' ? h.sec : 0), 0);
  if ($('#vt-n-patch')) $('#vt-n-patch').textContent = sec || '';
  box.innerHTML = `${!d.on ? `<div class="callout small">${L('Updates installeren staat uit (Instellingen → Beveiliging → Updates). Je ziet wel wat er klaarstaat.')}</div>` : ''}
    <div class="app-sum"><span class="app-sum-b">🛡 <b>${sec}</b> ${L('beveiligingsupdates')}</span><span class="app-sum-b">⬆ <b>${hs.reduce((n, h) => n + h.avail, 0)}</b> ${L('updates')}</span>
      <span class="app-sum-b">↻ <b>${hs.filter((h) => h.rb).length}</b> ${L('herstart nodig')}</span><span class="app-sum-b">🤖 <b>${hs.filter((h) => h.mode !== 'manual').length}</b> ${L('automatisch')}</span></div>
    ${hs.length ? `<div class="btn-row need-admin"><button type="button" class="btn small-btn primary" id="ph-bulk">⚙️ ${L('Meerdere servers instellen…')}</button>
      <span class="muted small">${L('Vink servers aan, of laat alles uit voor alle servers.')}</span></div>
    <div class="table-scroll"><table class="vuln-table small patch-table"><thead><tr><th class="need-admin"><input type="checkbox" id="ph-all" aria-label="${esc(L('Alles'))}"></th><th>${L('Server')}</th><th>${L('Klaar')}</th><th>${L('Hoe')}</th><th>${L('Laatst')}</th><th></th></tr></thead><tbody>
      ${hs.map((h) => `<tr data-ph="${h.id}"><td class="need-admin"><input type="checkbox" data-ph-sel="${h.id}" aria-label="${esc(h.name)}"></td><td><b>${esc(h.name)}</b><br><span class="muted">${(PATCH_KIND[h.kind] || (() => h.kind))()}${h.self ? ' · ' + L('RzSense zelf') : ''}</span></td>
        <td>${patchBadge(h)}${h.rb ? `<br><span class="warn small">↻ ${L('herstart nodig (doe dat zelf)')}</span>` : ''}${h.list.length ? `<br><button type="button" class="linkish small" data-ph-list="${h.id}">${L('lijst')}</button>` : ''}</td>
        <td>${h.kind === 'truenas' ? '–' : esc(patchModeTxt(h)) + (h.kind === 'linux' ? `<br><span class="muted">${h.scope === 'all' ? L('alle updates') : L('alleen beveiligingsupdates')}</span>` : '')}
          ${h.mode === 'window' && !h.windows.length ? `<br><span class="warn small">${L('geen onderhoudsvenster ingesteld')}</span>` : ''}</td>
        <td>${h.status && ['queued', 'running'].includes(h.status.state) ? `<button type="button" class="linkish" data-ph-prog="1" title="${esc(L('Voortgang tonen'))}">${agentSteps(h.status)}</button>`
          : h.ha_req ? agentSteps({ state: 'queued' }) : h.last ? agoSpan(h.last) : '–'}</td>
        <td class="nowrap need-admin">${!h.blocked && patchCount({ ...h, scope: 'security' }) && h.kind === 'linux' ? `<button type="button" class="btn small-btn primary" data-ph-now="security">🛡 ${L('Beveiliging')}</button> ` : ''}
          ${!h.blocked && h.avail ? `<button type="button" class="btn small-btn" data-ph-now="all">⬆ ${h.kind === 'ha' ? L('Core en add-ons') : L('Alles')}</button> ` : ''}
          ${h.kind !== 'truenas' ? `<button type="button" class="btn small-btn" data-ph-am="1">${L('Instellen')} →</button>` : ''}
          ${h.blocked && h.kind !== 'truenas' && h.agent_ok ? `<br><span class="muted small">${esc(h.blocked)}</span>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`}`;
  // 1.252.0: zolang een server bijwerkt, elke 5 seconden de stand vernieuwen (alleen als je op deze pagina bent)
  clearTimeout(loadPatch.t);
  if (hs.some((h) => (h.status && ['queued', 'running'].includes(h.status.state)) || h.ha_req)) loadPatch.t = setTimeout(() => { if (box.isConnected && box.offsetParent) loadPatch(); }, 5000);
  if ($('#ph-all', box)) $('#ph-all', box).onchange = (e) => $$('[data-ph-sel]', box).forEach((c) => { c.checked = e.target.checked; });
  box.onclick = (e) => {
    if (e.target.closest('#ph-bulk')) return patchBulk($$('[data-ph-sel]:checked', box).map((c) => +c.dataset.phSel));
    if (e.target.closest('[data-ph-sel]')) return;
    const tr = e.target.closest('[data-ph]');
    if (!tr) return;
    const h = hs.find((x) => x.id === +tr.dataset.ph);
    if (e.target.closest('[data-ph-list]')) return patchList(h);
    if (e.target.closest('[data-ph-am]')) return agentInstructions(findHost(h.id));
    if (e.target.closest('[data-ph-prog]')) return vulnUpgradeDialog(findHost(h.id) || h, true);
    const now = e.target.closest('[data-ph-now]');
    if (now) patchNow(h, now.dataset.phNow, loadPatch);
  };
}

/** 1.238.0: updates voor meerdere servers tegelijk instellen, met spreiding en een voorbeeld vooraf */
function patchBulk(ids) {
  modal({
    title: '⚙️ ' + (ids.length ? L('Updates instellen voor {n} servers', { n: ids.length }) : L('Updates instellen voor alle servers')),
    body: `<div style="padding:1rem 1.2rem">${xpHtml(L('Wanneer de servers hun updates installeren, gespreid zodat ze nooit allemaal tegelijk bezig zijn.'), L('<b>Wat het doet:</b> de agents installeren de updates zelf, op het moment dat je hier kiest. <b>Zo stel je het in:</b> kies <i>Elke dag om een tijd, gespreid</i> met 20 minuten tussen de servers, en alleen beveiligingsupdates. Of laat elke server zijn eigen onderhoudsvenster volgen. <b>Goed om te weten:</b> er wordt nooit vanzelf herstart; een server die dat nodig heeft, krijgt ↻. Per server pas je het aan in de Agent Manager.'))}
      <label>${L('Wanneer')} <select name="mode"><option value="auto">${L('Elke dag om een tijd, gespreid')}</option><option value="window">${L('In het onderhoudsvenster van elke server')}</option><option value="manual">${L('Met de hand')}</option></select></label>
      <div class="row2" data-pb-auto><label>${L('Eerste server om')} <input type="time" name="time" value="03:00"></label>
        <label>${L('Daarna elke server')} <select name="step"><option value="0">${L('tegelijk (niet aangeraden)')}</option><option value="10">+10 min</option><option value="20" selected>+20 min</option><option value="30">+30 min</option><option value="60">+60 min</option></select></label></div>
      <label>${L('Wat')} <select name="scope"><option value="security">${L('Alleen beveiligingsupdates (aangeraden)')}</option><option value="all">${L('Alle updates')}</option></select></label>
      <h4>${L('Voorbeeld')}</h4><div id="pb-plan" class="small"><p class="muted">${L('Laden…')}</p></div>
      <p class="muted small">${L('Volgorde: Linux-servers op naam, Home Assistant als laatste. De RzSense-server zelf en TrueNAS blijven met de hand. Alles komt in het Logboek en is terug te zetten.')}</p></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      const r = await api('patch_bulk', { ids, mode: v.mode, time: v.time, step: +v.step, scope: v.scope });
      toast(L('{n} servers ingesteld', { n: r.plan.filter((p) => p.ok).length }));
      loadPatch();
    },
  });
  const f = $('#modal-form');
  const preview = async () => {
    const v = Object.fromEntries(new FormData(f));
    $('[data-pb-auto]', f).hidden = v.mode !== 'auto';
    try {
      const r = await api('patch_bulk', { ids, mode: v.mode, time: v.time, step: +v.step, scope: v.scope, preview: true });
      $('#pb-plan').innerHTML = r.plan.length ? `<table class="vuln-table small"><tbody>${r.plan.map((p) => `<tr class="${p.ok ? '' : 'muted'}"><td><b>${esc(p.name)}</b> <span class="muted">${(PATCH_KIND[p.kind] || (() => p.kind))()}</span></td>
        <td>${!p.ok ? '✋ ' + esc(p.why) : v.mode === 'auto' ? `🕒 <b>${esc(p.time)}</b>` : v.mode === 'window' ? '🗓️ ' + L('in het venster') : L('met de hand')}
          ${p.ok && p.why ? `<br><span class="warn small">⚠️ ${esc(p.why)}</span>` : ''}</td></tr>`).join('')}</tbody></table>` : `<p class="muted">${L('Geen servers.')}</p>`;
    } catch (ex) { $('#pb-plan').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
  };
  f.addEventListener('change', preview, { signal: modalSignal() });
  preview();
}

function patchList(h) {
  modal({
    title: L('Updates voor {name}', { name: h.name }),
    body: `<div style="padding:1rem 1.2rem"><table class="vuln-table small"><thead><tr><th>${L('Pakket')}</th><th>${L('Nieuwe versie')}</th><th></th></tr></thead><tbody>
      ${h.list.map((x) => `<tr><td><code>${esc(x[3] || x[0])}</code></td><td>${esc(x[1])}</td><td>${+x[2] ? `<b class="bad">🛡 ${L('beveiliging')}</b>` : ''}${x[0] === 'os' ? `<span class="muted">${L('in Home Assistant zelf (herstart alles)')}</span>` : ''}</td></tr>`).join('')}
      </tbody></table>${h.list.length >= 300 ? `<p class="muted small">${L('De eerste 300.')}</p>` : ''}</div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
}

function patchNow(h, scope, done) {
  const ha = h.kind === 'ha';
  const n = ha ? h.list.filter((x) => x[0] !== 'os').length : scope === 'all' ? h.avail : h.sec;
  modal({
    title: ha ? L('Home Assistant bijwerken') : scope === 'all' ? L('Alle updates installeren op {name}', { name: h.name }) : L('Alle beveiligingsupdates installeren op {name}', { name: h.name }),
    body: `<div style="padding:1rem 1.2rem">
      <p>${ha ? L('De agent werkt Core en de add-ons met een update bij via de Supervisor, met een back-up vooraf. Het besturingssysteem (OS) niet: dat herstart het hele systeem.')
    : L('De agent installeert {n} pakketten met de pakketbeheerder van de server.', { n })}</p>
      <p class="small">${L('Er wordt niets herstart. Is dat daarna nodig, dan zie je ↻ bij de server.')}</p>
      ${h.self ? `<p class="warn-txt">${L('Dit is de server waar RzSense zelf op draait. Gaat er iets mis, dan zie je het hier niet meer: blijf erbij.')}</p>` : ''}
      ${confirmField('BIJWERKEN')}</div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⬆ ' + L('Bijwerken'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      await api('patch_now', { host_id: h.id, scope, confirm: v.confirm });
      if (ha) { $('#modal').close(); toast(L('Gevraagd; de agent begint bij zijn volgende ronde. De uitkomst komt in het logboek.')); }
      else { vulnUpgradeDialog({ id: h.id, name: h.name }, true, { state: 'queued', pkgs: [] }); }
      if (done) done();
      return ha ? undefined : 'keep';
    },
  });
}

// ---------------------------------------------------------------- Agent Manager: keuze per server
async function amPatch(h, body) {
  const box = $('#am-patch', body);
  if (!box) return;
  let d;
  try { d = (await api('patch')).hosts.find((x) => x.id === h.id); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (!d) { box.innerHTML = ''; return; }
  if (d.kind === 'truenas') { amSum('patch', esc(L('via TrueNAS zelf'))); box.innerHTML = `<p class="muted small">${L('TrueNAS werk je bij met een TrueNAS-update (System → Update), nooit met apt. RzSense doet hier niets.')}</p>`; return; }
  const ha = d.kind === 'ha';
  amSum('patch', patchBadge(d) + (d.rb ? ` · <span class="warn">↻ ${L('herstart nodig')}</span>` : ''), !!(d.sec || d.rb));   // 1.246.0
  box.innerHTML = `${!d.agent_ok ? `<div class="callout small">${L('Agent 1.92.0 of nieuwer nodig; werk de agent bij.')}</div>` : ''}
    <p class="small">${patchBadge(d)}${d.rb ? ` · <span class="warn">↻ ${L('herstart nodig (doe dat zelf)')}</span>` : ''}${d.at ? ` · <span class="muted">${L('bekeken')} ${agoSpan(new Date(d.at * 1000).toISOString())}</span>` : ''}</p>
    ${amRow('🤖 ' + L('Wanneer'), d.self ? L('dit is de RzSense-server zelf: alleen met de hand') : L('met de hand, in het vaste onderhoudsvenster van deze server, of elke dag om een tijd'),
    `<select id="pt-mode" ${d.self ? 'disabled' : ''}>${['manual', 'window', 'auto'].map((m) => `<option value="${m}" ${d.mode === m ? 'selected' : ''}>${esc(PATCH_MODE[m]())}</option>`).join('')}</select> <input id="pt-time" type="time" value="${esc(d.time)}" ${d.mode === 'auto' ? '' : 'hidden'} aria-label="${esc(L('Tijd'))}">`)}
    <p class="muted small" id="pt-win" ${d.mode === 'window' ? '' : 'hidden'}>${d.windows.length ? '🗓️ ' + d.windows.map((w) => esc(`${w.dow ? ['', L('ma'), L('di'), L('wo'), L('do'), L('vr'), L('za'), L('zo')][w.dow] : L('elke dag')} ${w.start} (${w.minutes} min)`)).join(', ')
    : `⚠ ${L('Deze server heeft nog geen vast onderhoudsvenster.')} <a href="#settings/advice" data-goto="settings/advice">${L('Instellen')} →</a>`}</p>
    ${ha ? `<p class="muted small">${L('Home Assistant: Core en add-ons, altijd met een back-up vooraf. Het OS niet (dat herstart alles).')}</p>`
    : `${amRow('🛡 ' + L('Wat'), L('alleen beveiligingsupdates is het veiligst; alles neemt ook nieuwe versies mee'),
      `<select id="pt-scope"><option value="security" ${d.scope === 'security' ? 'selected' : ''}>${L('alleen beveiligingsupdates')}</option><option value="all" ${d.scope === 'all' ? 'selected' : ''}>${L('alle updates')}</option></select>`)}`}
    <div class="btn-row"><button type="button" class="btn primary small-btn" id="pt-save">${L('Opslaan')}</button>
      ${!d.blocked && (ha ? d.avail : d.sec) ? `<button type="button" class="btn small-btn" id="pt-now">⬆ ${ha ? L('Nu bijwerken') : L('Beveiligingsupdates nu')}</button>` : ''}
      <a class="btn small-btn" href="#vulns/patch" data-goto="vulns/patch">${L('Alle servers')} →</a></div>
    ${d.blocked && d.agent_ok ? `<p class="muted small">${esc(d.blocked)}</p>` : ''}`;
  $('#pt-mode', box).onchange = (e) => { $('#pt-time', box).hidden = e.target.value !== 'auto'; $('#pt-win', box).hidden = e.target.value !== 'window'; };
  $('#pt-save', box).onclick = async () => {
    try {
      await api('patch_save', { host_id: h.id, mode: $('#pt-mode', box).value, time: $('#pt-time', box).value, scope: ha ? 'security' : $('#pt-scope', box).value });
      toast(L('Opgeslagen'));
      amPatch(h, body);
    } catch (ex) { toast(ex.message, 'err'); }
  };
  if ($('#pt-now', box)) $('#pt-now', box).onclick = () => patchNow(d, 'security', () => amPatch(h, body));
}

// ---------------------------------------------------------------- Instellingen → Beveiliging → Updates
async function loadPatchSet() {
  const box = $('#patch-hosts');
  if (!box) return;
  let d;
  try { d = await api('patch'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  // 1.236.0: alleen een samenvatting; de lijst per server (met instellen) staat op Kwetsbaarheden → Updates
  const auto = d.hosts.filter((h) => h.kind !== 'truenas' && h.mode && h.mode !== 'off').length;
  box.innerHTML = d.hosts.length ? `<p class="small">${L('{a} van {n} servers werken zichzelf automatisch bij.', { a: auto, n: d.hosts.length })}
    <a class="btn small-btn" href="#vulns/patch" data-goto="vulns/patch">${L('Per server bekijken en instellen')} →</a></p>`
    : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`;
}
