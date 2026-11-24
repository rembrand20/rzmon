// ---------------------------------------------------------------- 1.320.0: het beveiligingskanaal
// Instellingen → Meldingen en rapporten → Beveiligingskanaal, en bovenaan IDS een balk met meldingen die nog op bevestiging
// wachten ("Ik kijk ernaar" stopt het herhalen overal). De knop in de melding zelf opent alleen RzSense.

const SEC_KINDS = () => [
  ['iso', L('Isolatie wacht op jou'), L('Aanvalsketen gevonden; zonder keuze isoleert RzSense na de wachttijd')],
  ['malware', L('Ransomware of malware op een server'), L('Bestanden worden massaal versleuteld, of een virusscanner vond iets')],
  ['edr', L('Webshell of verdacht proces'), L('Webserver of database start een shell, reverse shell, aanvalsketen')],
  ['login', L('Login of nieuwe beheerder van buiten'), L('Inbraak, root-login, nieuwe gebruiker of beheerder, logboek gewist')],
  ['unhandled', L('Aanval niet tegengehouden'), L('Beschermd IP, IDS op leren, of blokkeren mislukt')],
  ['cve', L('Lek dat je echt draait wordt aangevallen'), L('Een CVE uit Kwetsbaarheden in een aanval op jouw server')],
];

async function loadSecCh() {
  const box = $('#secch-body');
  if (!box) return;
  let d;
  try { d = await api('sec_ch_get'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sw = (name, on, label, sub = '', dis = false) => `<label class="switch"><input type="checkbox" data-sc="${name}" ${on ? 'checked' : ''} ${dis ? 'disabled' : ''}><span></span> ${label}${sub ? `<span class="muted small" style="display:block">${sub}</span>` : ''}</label>`;
  const off = (on) => (on ? '' : ` · <span class="warn-txt">${esc(L('staat uit bij Kanalen'))}</span>`);
  box.innerHTML = `
    ${sw('on', d.on, `<b>${L('Beveiligingskanaal aan')}</b>`, L('Gaat door Niet storen en stille uren heen, en blijft komen tot iemand bevestigt.'))}
    <h3 class="small" style="margin:1rem 0 .4rem">${L('Wat gaat via dit kanaal')}</h3>
    <label>${L('Welke incidenten')}<select data-sc="mode"><option value="action" ${d.mode === 'action' ? 'selected' : ''}>${esc(L('Alleen als je iets moet doen (aanbevolen)'))}</option><option value="all" ${d.mode === 'all' ? 'selected' : ''}>${esc(L('Elke critical'))}</option></select></label>
    <div class="secch-kinds">${SEC_KINDS().map(([k, t, ex]) => `<label class="check-row"><input type="checkbox" data-sk-kind="${k}" ${d.kinds.includes(k) ? 'checked' : ''}> <span><b>${esc(t)}</b><span class="muted small" style="display:block">${esc(ex)}</span></span></label>`).join('')}</div>
    <p class="muted small">${L('Met deze keuze zou het kanaal de afgelopen 30 dagen {n} keer afgegaan zijn.', { n: `<b>${d.month}</b>` })} ${d.month > 6 ? `<span class="warn-txt">${esc(L('Een alarm dat vaak afgaat, negeer je: zet dan minder soorten aan.'))}</span>` : ''}</p>
    <h3 class="small" style="margin:1rem 0 .4rem">${L('Hoe het binnenkomt')}</h3>
    ${sw('classic', d.classic, 'Pushover / ntfy', L('Pushover als noodmelding: herhaalt elke minuut tot je bevestigt (hooguit een uur). ntfy op urgent.') + off(d.pushover || d.ntfy))}
    ${sw('web', d.web, 'Web Push', L('Blijft op het scherm staan tot je hem wegtikt; elke 5 minuten opnieuw tot iemand bevestigt.') + off(d.webpush))}
    ${sw('mail', d.mail, L('E-mail'), L('Eén keer, als vangnet.') + off(d.email))}
    ${sw('ha', d.ha, 'Home Assistant-app', L('Kritieke melding: gaat door Niet storen en het stille knopje heen, op vol volume. Elke 5 minuten opnieuw tot iemand bevestigt.'))}
    <div class="row2 secch-ha" ${d.ha ? '' : 'hidden'}>
      <label>${L('Adres van Home Assistant')}<input data-sc="ha_url" value="${esc(d.ha_url)}" placeholder="https://homeassistant.local:8123"></label>
      <label>${L('Telefoon (notify-actie)')}<input data-sc="ha_service" value="${esc(d.ha_service)}" placeholder="mobile_app_iphone_van_jan"></label>
      <label>${L('Langlevend toegangstoken')}<input data-sc="ha_token" type="password" autocomplete="off" placeholder="${esc(d.ha_token ? L('ingesteld (leeg laten = houden)') : L('plak hier het token'))}"></label>
    </div>
    <p class="muted small secch-ha" ${d.ha ? '' : 'hidden'}>${L('Token maken: in Home Assistant je profiel → Beveiliging → Langlevende toegangstokens. De naam van de telefoon staat bij Ontwikkelaarshulpmiddelen → Acties (notify.mobile_app_…). Het token blijft op de server van RzSense.')}</p>
    <h3 class="small" style="margin:1rem 0 .4rem">${L('Bevestigen en stille uren')}</h3>
    <p class="small">${L('Bevestigen (<b>Ik kijk ernaar</b>) doe je bovenaan de pagina IDS of in de Pushover-app; dan stopt het overal. De knop in de melding opent alleen RzSense. Na een uur stopt het herhalen; het incident blijft open bij IDS.')}</p>
    ${sw('night', d.night, L("'s Nachts (23:00–07:00) alleen het ergste"), L('Isolatie die op je wacht, ransomware en malware. De rest komt als gewone melding.'))}
    ${d.last ? `<p class="muted small">${L('Laatste keer')}: ${esc(fmtDateTime(d.last.created_at))} · ${esc(d.last.title)}${d.last.ack_at ? ' · ' + esc(L('bevestigd door {u}', { u: d.last.ack_by })) : ''}</p>` : ''}
    <div class="btn-row"><button type="button" class="btn primary" id="secch-save">${L('Opslaan')}</button>
      <button type="button" class="btn" id="secch-test">${L('Proefmelding sturen')}</button></div>`;
  box.addEventListener('change', (e) => {
    if (e.target.dataset.sc === 'ha') $$('.secch-ha', box).forEach((x) => { x.hidden = !e.target.checked; });
  }, { signal: secChAC() });
  $('#secch-save', box).onclick = async () => {
    const v = (n) => $(`[data-sc="${n}"]`, box);
    const body = { mode: v('mode').value, kinds: $$('[data-sk-kind]', box).filter((x) => x.checked).map((x) => x.dataset.skKind),
      ha_url: v('ha_url').value, ha_service: v('ha_service').value, ha_token: v('ha_token').value };
    ['on', 'classic', 'web', 'mail', 'ha', 'night'].forEach((k) => { body[k] = v(k).checked; });
    try { await api('sec_ch_save', body); toast(L('Opgeslagen')); loadSecCh(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#secch-test', box).onclick = async () => {
    try {
      const r = await api('sec_ch_test', {});
      toast(r.ok ? L('Proefmelding verstuurd: bevestig hem bovenaan de pagina IDS') : L('Proefmelding niet aangekomen: {e}', { e: (r.errors || []).join('; ') }), r.ok ? '' : 'err');
    } catch (ex) { toast(ex.message, 'err'); }
  };
}
let secChCtl = null;
function secChAC() { if (secChCtl) secChCtl.abort(); secChCtl = new AbortController(); return secChCtl.signal; }

/** Bovenaan IDS: beveiligingsmeldingen die nog niemand bevestigde */
async function secActiveBar(el) {
  if (!el) return;
  let d;
  try { d = await api('sec_active'); } catch { el.innerHTML = ''; return; }
  el.innerHTML = (d.active || []).map((a) => `<div class="callout secch-bar" role="alert"><b>${esc(a.title)}</b>
    <span class="muted small">${esc(fmtDateTime(a.at))}${a.sends > 1 ? ' · ' + esc(L('{n} keer verstuurd', { n: a.sends })) : ''}</span>
    <span class="small" style="display:block">${esc(a.body)}</span>
    <div class="btn-row"><button type="button" class="btn primary small-btn" data-sec-ack="${a.id}">${L('Ik kijk ernaar')}</button></div></div>`).join('');
  $$('[data-sec-ack]', el).forEach((b) => {
    b.onclick = async () => {
      try { await api('sec_ack', { id: +b.dataset.secAck }); toast(L('Bevestigd: het herhalen stopt')); secActiveBar(el); } catch (ex) { toast(ex.message, 'err'); }
    };
  });
}
