// ---------------------------------------------------------------- 1.337.0: schermmodus en de schermlink
// Schermmodus (ingelogd): Monitoring schermvullend, zonder menu, het scherm blijft aan en de pagina wisselt om de beurt
// tussen Monitoring, Netwerk en IDS. Esc of de knop zet hem uit. Gebruikt de bestaande TV-weergave (body.tv).
// Schermlink (zonder inloggen): een eigen pagina met een geheime link, in te stellen bij Instellingen → Statuspagina.
const SCR_PAGES = () => [['dashboard', L('Monitoring')], ...($('.tab[data-tab="net"]') ? [['net/speed', L('Netwerk')]] : []), ...($('.tab[data-tab="ids"]') ? [['ids', 'IDS']] : [])];
const SCR_SECS = 30;
let scrTimer = null, scrIdx = 0, scrLock = null, scrCrit = -1;

const scrOn = () => scrTimer !== null;

function scrBar() {
  let bar = $('#scr-bar');
  if (!scrOn()) { if (bar) bar.remove(); return; }
  if (!bar) { document.body.insertAdjacentHTML('beforeend', '<div class="scr-bar" id="scr-bar" role="status"></div>'); bar = $('#scr-bar'); }
  const pages = SCR_PAGES();
  bar.innerHTML = `<span>⟳ ${esc(L('wisselt elke {n} s', { n: SCR_SECS }))}: ${pages.map(([, l], i) => (i === scrIdx ? `<b>${esc(l)}</b>` : esc(l))).join(' · ')}</span>
    <button type="button" class="btn small-btn" data-scr-off>${esc(L('Schermmodus uit'))} (Esc)</button>`;
}

function scrStart() {
  if (scrOn()) return;
  scrIdx = 0; scrCrit = -1;
  showTab('dashboard');
  ccTv(true);
  scrTimer = setInterval(() => {
    if ($('#modal').open) return;   // iemand kijkt ergens naar: niet wegwisselen
    const pages = SCR_PAGES();
    // een nieuwe storing: terug naar Monitoring
    const crit = ((state && state.health && state.health.counts) || {}).critical || 0;
    scrIdx = scrCrit >= 0 && crit > scrCrit ? 0 : (scrIdx + 1) % pages.length;
    scrCrit = crit;
    showTab(pages[scrIdx][0]);
    scrBar();
  }, SCR_SECS * 1000);
  try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then((l) => { scrLock = l; }).catch(() => {}); } catch (e) { /* niet beschikbaar (http of oude browser) */ }
  scrBar();
}

/** Uitzetten; ccTv(false) roept dit ook aan (Esc, de knop, of volledig scherm verlaten) */
function scrStop() {
  if (!scrOn()) return;
  clearInterval(scrTimer); scrTimer = null;
  try { if (scrLock) scrLock.release(); } catch (e) { /* al losgelaten */ }
  scrLock = null;
  scrBar();
  if (document.body.classList.contains('tv')) ccTv(false);
  showTab('dashboard');
}

function initScreen() {
  document.addEventListener('click', (e) => {
    const b = e.target.closest ? e.target.closest('[data-scr-on], [data-scr-off]') : null;
    if (!b) return;
    if (b.dataset.scrOn !== undefined) scrStart(); else scrStop();
  });
  if (/[?&]scherm=1/.test(location.hash)) setTimeout(scrStart, 800);   // #dashboard?scherm=1: meteen in schermmodus
}

/** Instellingen → Systeem → Statuspagina: de kaart Schermlink */
async function loadScreenSet() {
  const el = $('#screen-body');
  if (!el) return;
  let d;
  try { d = await api('screen_get'); } catch (ex) { el.innerHTML = `<p class="err-txt small">${esc(ex.message)}</p>`; return; }
  const url = d.has_base ? d.url : location.origin + location.pathname.replace(/[^/]*$/, '') + d.url.replace(/^\//, '');
  const VIEWS = [['mon', L('Monitoring')], ['net', L('Netwerk')], ['ids', 'IDS']];
  el.innerHTML = `<label class="switch"><input type="checkbox" id="scr-on" ${d.on ? 'checked' : ''}><span></span> <b>${esc(L('Schermlink aan'))}</b></label>
    <label class="switch"><input type="checkbox" id="scr-lan" ${d.lan_only ? 'checked' : ''}><span></span> <span>${L('<b>Alleen vanaf mijn netwerk</b>: van buiten geeft de link een gewone 404')}</span></label>
    <p class="hint">${esc(L('Op het scherm staan de namen van je servers en open incidenten. Zet dit alleen uit als het scherm echt buiten je netwerk hangt.'))}</p>
    <div class="row2"><label>${esc(L('Wisselen elke … seconden'))} <span class="hint">${esc(L('(0 = niet wisselen)'))}</span><input type="number" id="scr-rot" min="0" max="600" step="5" value="${+d.rotate}"></label>
      <div><span class="lbl">${esc(L('Schermen'))}</span><div class="scr-views">${VIEWS.map(([k, l]) => `<label class="check-row"><input type="checkbox" data-scr-view="${k}" ${d.views.includes(k) ? 'checked' : ''} ${k === 'mon' ? 'disabled' : ''}> ${esc(l)}</label>`).join('')}</div></div></div>
    <h3 class="small" style="margin:1rem 0 .3rem">${esc(L('Geheime link'))}</h3>
    <div class="copy-line"><pre class="code" id="scr-url">${esc(url)}</pre><button type="button" class="btn" id="scr-copy">${esc(L('Kopieer'))}</button></div>
    <p class="hint"><button type="button" class="btn small-btn" id="scr-new">↻ ${esc(L('Nieuwe link'))}</button> <a href="${esc(url)}" target="_blank" rel="noopener" class="btn small-btn">${esc(L('Openen'))} ↗</a>
      ${d.on ? '' : esc(L('De link werkt pas als de schermlink aan staat.'))}</p>
    <div class="btn-row" style="margin-top:.8rem"><button type="button" class="btn primary" id="scr-save">${esc(L('Opslaan'))}</button></div>`;
  $('#scr-copy').onclick = () => { navigator.clipboard?.writeText($('#scr-url').textContent); toast(L('Gekopieerd')); };
  $('#scr-new').onclick = async () => {
    if (!confirm(L('Nieuwe link maken? De oude link werkt dan meteen niet meer.'))) return;
    try { await api('screen_token_new', {}); loadScreenSet(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#scr-save').onclick = async () => {
    try {
      await api('screen_save', { on: $('#scr-on').checked, lan_only: $('#scr-lan').checked, rotate: +$('#scr-rot').value || 0, views: $$('[data-scr-view]:checked', el).map((i) => i.dataset.scrView) });
      toast(L('Opgeslagen')); loadScreenSet();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}
