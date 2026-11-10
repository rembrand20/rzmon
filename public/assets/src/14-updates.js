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
    ${hs.length ? `<div class="table-scroll"><table class="vuln-table small patch-table"><thead><tr><th>${L('Server')}</th><th>${L('Klaar')}</th><th>${L('Hoe')}</th><th>${L('Laatst')}</th><th></th></tr></thead><tbody>
      ${hs.map((h) => `<tr data-ph="${h.id}"><td><b>${esc(h.name)}</b><br><span class="muted">${(PATCH_KIND[h.kind] || (() => h.kind))()}${h.self ? ' · ' + L('RzSense zelf') : ''}</span></td>
        <td>${patchBadge(h)}${h.rb ? `<br><span class="warn small">↻ ${L('herstart nodig (doe dat zelf)')}</span>` : ''}${h.list.length ? `<br><button type="button" class="linkish small" data-ph-list="${h.id}">${L('lijst')}</button>` : ''}</td>
        <td>${h.kind === 'truenas' ? '–' : esc(patchModeTxt(h)) + (h.kind === 'linux' ? `<br><span class="muted">${h.scope === 'all' ? L('alle updates') : L('alleen beveiligingsupdates')}${h.snap ? ' · 📸' : ''}</span>` : '')}
          ${h.mode === 'window' && !h.windows.length ? `<br><span class="warn small">${L('geen onderhoudsvenster ingesteld')}</span>` : ''}</td>
        <td>${h.status && ['queued', 'running'].includes(h.status.state) || h.ha_req ? `<b>⏳ ${L('bezig')}</b>` : h.last ? agoSpan(h.last) : '–'}</td>
        <td class="nowrap need-admin">${!h.blocked && patchCount({ ...h, scope: 'security' }) && h.kind === 'linux' ? `<button type="button" class="btn small-btn primary" data-ph-now="security">🛡 ${L('Beveiliging')}</button> ` : ''}
          ${!h.blocked && h.avail ? `<button type="button" class="btn small-btn" data-ph-now="all">⬆ ${h.kind === 'ha' ? L('Core en add-ons') : L('Alles')}</button> ` : ''}
          ${h.kind !== 'truenas' ? `<button type="button" class="btn small-btn" data-ph-am="1">${L('Instellen')} →</button>` : ''}
          ${h.blocked && h.kind !== 'truenas' && h.agent_ok ? `<br><span class="muted small">${esc(h.blocked)}</span>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`}`;
  box.onclick = (e) => {
    const tr = e.target.closest('[data-ph]');
    if (!tr) return;
    const h = hs.find((x) => x.id === +tr.dataset.ph);
    if (e.target.closest('[data-ph-list]')) return patchList(h);
    if (e.target.closest('[data-ph-am]')) return agentInstructions(findHost(h.id));
    const now = e.target.closest('[data-ph-now]');
    if (now) patchNow(h, now.dataset.phNow, loadPatch);
  };
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
      ${!ha && h.snap ? `<p class="small">📸 ${L('Eerst een snapshot van / (ZFS, snapper of btrfs), zodat je terug kunt.')}</p>` : ''}
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
  if (d.kind === 'truenas') { box.innerHTML = `<p class="muted small">${L('TrueNAS werk je bij met een TrueNAS-update (System → Update), nooit met apt. RzSense doet hier niets.')}</p>`; return; }
  const ha = d.kind === 'ha';
  box.innerHTML = `${!d.agent_ok ? `<div class="callout small">${L('Agent 1.92.0 of nieuwer nodig; werk de agent bij.')}</div>` : ''}
    <p class="small">${patchBadge(d)}${d.rb ? ` · <span class="warn">↻ ${L('herstart nodig (doe dat zelf)')}</span>` : ''}${d.at ? ` · <span class="muted">${L('bekeken')} ${agoSpan(new Date(d.at * 1000).toISOString())}</span>` : ''}</p>
    ${amRow('🤖 ' + L('Wanneer'), d.self ? L('dit is de RzSense-server zelf: alleen met de hand') : L('met de hand, in het vaste onderhoudsvenster van deze server, of elke dag om een tijd'),
    `<select id="pt-mode" ${d.self ? 'disabled' : ''}>${['manual', 'window', 'auto'].map((m) => `<option value="${m}" ${d.mode === m ? 'selected' : ''}>${esc(PATCH_MODE[m]())}</option>`).join('')}</select> <input id="pt-time" type="time" value="${esc(d.time)}" ${d.mode === 'auto' ? '' : 'hidden'} aria-label="${esc(L('Tijd'))}">`)}
    <p class="muted small" id="pt-win" ${d.mode === 'window' ? '' : 'hidden'}>${d.windows.length ? '🗓️ ' + d.windows.map((w) => esc(`${w.dow ? ['', L('ma'), L('di'), L('wo'), L('do'), L('vr'), L('za'), L('zo')][w.dow] : L('elke dag')} ${w.start} (${w.minutes} min)`)).join(', ')
    : `⚠ ${L('Deze server heeft nog geen vast onderhoudsvenster.')} <a href="#settings/advice" data-goto="settings/advice">${L('Instellen')} →</a>`}</p>
    ${ha ? `<p class="muted small">${L('Home Assistant: Core en add-ons, altijd met een back-up vooraf. Het OS niet (dat herstart alles).')}</p>`
    : `${amRow('🛡 ' + L('Wat'), L('alleen beveiligingsupdates is het veiligst; alles neemt ook nieuwe versies mee'),
      `<select id="pt-scope"><option value="security" ${d.scope === 'security' ? 'selected' : ''}>${L('alleen beveiligingsupdates')}</option><option value="all" ${d.scope === 'all' ? 'selected' : ''}>${L('alle updates')}</option></select>`)}
      ${amRow('📸 ' + L('Eerst een snapshot'), L('van / (ZFS, snapper of btrfs), zodat je terug kunt; anders gaat het gewoon door'), amSw('pt-snap', d.snap))}`}
    <div class="btn-row"><button type="button" class="btn primary small-btn" id="pt-save">${L('Opslaan')}</button>
      ${!d.blocked && (ha ? d.avail : d.sec) ? `<button type="button" class="btn small-btn" id="pt-now">⬆ ${ha ? L('Nu bijwerken') : L('Beveiligingsupdates nu')}</button>` : ''}
      <a class="btn small-btn" href="#vulns/patch" data-goto="vulns/patch">${L('Alle servers')} →</a></div>
    ${d.blocked && d.agent_ok ? `<p class="muted small">${esc(d.blocked)}</p>` : ''}`;
  $('#pt-mode', box).onchange = (e) => { $('#pt-time', box).hidden = e.target.value !== 'auto'; $('#pt-win', box).hidden = e.target.value !== 'window'; };
  $('#pt-save', box).onclick = async () => {
    try {
      await api('patch_save', { host_id: h.id, mode: $('#pt-mode', box).value, time: $('#pt-time', box).value, scope: ha ? 'security' : $('#pt-scope', box).value, snap: ha ? true : $('#pt-snap', box).checked });
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
  box.innerHTML = d.hosts.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Server')}</th><th>${L('Hoe')}</th><th>${L('Klaar')}</th><th></th></tr></thead><tbody>${d.hosts.map((h) => `<tr>
    <td><b>${esc(h.name)}</b><br><span class="muted small">${(PATCH_KIND[h.kind] || (() => h.kind))()}${h.self ? ' · ' + L('RzSense zelf') : ''}</span></td>
    <td class="small">${h.kind === 'truenas' ? L('via TrueNAS zelf') : esc(patchModeTxt(h))}</td><td class="small">${patchBadge(h)}</td>
    <td>${h.kind !== 'truenas' ? `<button type="button" class="btn small-btn" data-pt-am="${h.id}">${L('Instellen')} →</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
    <p class="small"><a href="#vulns/patch" data-goto="vulns/patch">${L('Naar Kwetsbaarheden → Updates')} →</a></p>`
    : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`;
  box.onclick = (e) => { const b = e.target.closest('[data-pt-am]'); if (b) agentInstructions(findHost(+b.dataset.ptAm)); };
}
