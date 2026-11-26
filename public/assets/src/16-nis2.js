// ---------------------------------------------------------------- 🇪🇺 NIS2 (1.232.0): maatregelen, notities en meldtermijnen
const NIS2_ST = { ok: ['✔', () => L('geregeld'), 'ok'], manual: ['✔', () => L('zelf als geregeld gemarkeerd'), 'ok'], partial: ['◐', () => L('deels'), 'warn'], missing: ['✘', () => L('ontbreekt'), 'bad'] };
const nis2Left = (due) => {
  const s = Math.round((new Date(due.replace(' ', 'T')).getTime() - Date.now()) / 1000);
  if (s <= 0) return `<b class="bad">${L('verlopen')}</b>`;
  return s >= 86400 ? L('nog {d} d {h} u', { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600) }) : `<b class="warn">${L('nog {h} u {m} min', { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) })}</b>`;
};

let compTab = 'nis2';   // 1.330.0: Compliance: NIS2 of Instellingen (die stonden in de beheerportal)
/** Het tabblad Instellingen: NIS2 aan of uit, organisatie en CSIRT */
function nis2SetHtml(d) {
  return `<div class="nis2-set">
    <label class="switch"><input type="checkbox" id="nis2s-on" ${d.on ? 'checked' : ''}><span></span> ${L('<b>NIS2-overzicht</b> en meldtermijnen')}</label>
    <label>${L('Organisatie')} <span class="hint">${L('(voor het rapport)')}</span><input id="nis2s-org" maxlength="120" value="${esc(d.org || '')}"></label>
    <label>${L('CSIRT')} <span class="hint">${L('(waar je meldt, bv. het NCSC of het CSIRT van je sector)')}</span><input id="nis2s-csirt" maxlength="200" value="${esc(d.csirt || '')}"></label>
    <p class="small muted">${L('Het rapport automatisch versturen (bijvoorbeeld elk kwartaal naar je directie of adviseur) stel je in bij Rapporten.')} <a href="#settings/reports" data-goto="settings/reports">${L('Naar Rapporten')} →</a></p>
    <div class="btn-row"><button type="button" class="btn primary" data-comp-save>${L('Opslaan')}</button></div></div>`;
}

async function loadNis2() {
  const box = $('#nis2-box');
  if (!box) return;
  let d;
  try { d = await api('nis2'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const compBar = isAdmin() ? `<div class="subtabs" id="comp-tabs" role="tablist" aria-label="${esc(L('Compliance'))}">
    <button type="button" class="seg-btn ${compTab === 'nis2' ? 'active' : ''}" data-comp="nis2">🇪🇺 NIS2</button>
    <button type="button" class="seg-btn ${compTab === 'set' ? 'active' : ''}" data-comp="set">⚙️ ${L('Instellingen')}</button></div>` : '';
  box.onclick = async (e) => {
    const t = e.target.closest('[data-comp]');
    if (t) { compTab = t.dataset.comp === 'set' ? 'set' : 'nis2'; loadNis2(); return; }
    if (e.target.closest('[data-comp-save]')) {
      try {
        await api('settings_save', { nis2_on: $('#nis2s-on').checked ? '1' : '0', nis2_org: $('#nis2s-org').value.trim(), nis2_csirt: $('#nis2s-csirt').value.trim() });
        toast(L('Opgeslagen'));
        loadNis2();
      } catch (ex) { toast(ex.message, 'err'); }
      return;
    }
    nis2Click(e, box);
  };
  if (compTab === 'set' && isAdmin()) { box.innerHTML = compBar + nis2SetHtml(d); return; }
  const cnt = (s) => d.measures.filter((m) => m.status === s || (s === 'ok' && m.status === 'manual')).length;
  const step = (i, w, label) => `<div class="nis2-step ${i[w] ? 'done' : ''}"><b>${label}</b><br>${i[w] ? `✔ ${L('verstuurd')} ${agoSpan(i[w])}` : `${L('uiterlijk {d}', { d: esc(fmtDateTime(i[w + '_due'])) })}<br>${nis2Left(i[w + '_due'])}`}
    <label class="small need-admin"><input type="checkbox" data-nis2-sent="${w}" data-id="${i.id}" ${i[w] ? 'checked' : ''}> ${L('verstuurd')}</label></div>`;
  box.innerHTML = `${!d.on ? `<div class="callout small">${L('Het NIS2-overzicht staat uit; je ziet het wel, maar er komen geen meldingen over termijnen.')}</div>` : ''}
    <div class="app-sum"><span class="app-sum-b"><span class="ok">✔</span> <b>${cnt('ok')}</b> ${L('geregeld')}</span><span class="app-sum-b"><span class="warn">◐</span> <b>${cnt('partial')}</b> ${L('deels')}</span>
      <span class="app-sum-b"><span class="bad">✘</span> <b>${cnt('missing')}</b> ${L('ontbreekt')}</span>
      <a class="btn small-btn" href="api.php?action=nis2_report" target="_blank" rel="noopener">📄 ${L('NIS2-rapport (afdrukken als PDF)')}</a></div>
    <h3 class="set-sub">${L('Maatregelen (art. 21 lid 2)')}</h3>
    <div class="nis2-list">${d.measures.map((m) => { const [ic, lbl, c] = NIS2_ST[m.status]; return `<details class="nis2-m nis2-${c}" data-code="${esc(m.code)}">
      <summary><span class="${c}">${ic}</span> <b>${m.code.toUpperCase()}. ${esc(m.title)}</b> <span class="muted small">${esc(lbl())}</span></summary>
      <p class="muted small">${esc(m.desc)}</p>
      <ul class="nis2-ev">${m.evidence.map((e) => `<li>${e.ok === true ? '<span class="ok">✔</span>' : e.ok === false ? '<span class="bad">✘</span>' : '<span class="muted">•</span>'} ${esc(e.text)}${e.go ? ` <a href="#${esc(e.go)}" data-goto="${esc(e.go)}" class="small">${L('bekijken')} →</a>` : ''}</li>`).join('')}</ul>
      <label class="small">${L('Notitie')} <span class="hint">${L('(wat je verder geregeld hebt: beleid, afspraken, wie, wanneer)')}</span><textarea rows="3" data-nis2-note="${m.code}" class="need-admin-input">${esc(m.note)}</textarea></label>
      <div class="btn-row need-admin"><label class="switch small"><input type="checkbox" data-nis2-manual="${m.code}" ${m.manual ? 'checked' : ''}><span></span> ${L('Zelf als geregeld markeren')}</label>
        <button type="button" class="btn small-btn" data-nis2-save="${m.code}">${L('Opslaan')}</button>${m.by ? ` <span class="muted small">${esc(m.by)} · ${agoSpan(m.at)}</span>` : ''}</div></details>`; }).join('')}</div>
    <h3 class="set-sub">${L('Significante incidenten en meldtermijnen (art. 23)')}</h3>
    ${d.csirt ? `<p class="small">${L('Melden bij: {c}', { c: `<b>${esc(d.csirt)}</b>` })}</p>` : ''}
    ${d.incidents.length ? d.incidents.map((i) => `<div class="nis2-inc"><div><b>${esc(i.title)}</b> <span class="muted small">${esc(i.host || '')} · ${L('bekend sinds {d}', { d: esc(fmtDateTime(i.at)) })}</span>
        <button type="button" class="linkish small need-admin" data-nis2-unmark="${i.id}">${L('niet significant')}</button></div>
      <div class="nis2-steps">${step(i, 'warn', L('Vroegtijdige waarschuwing (24 uur)'))}${step(i, 'notify', L('Incidentmelding (72 uur)'))}${step(i, 'final', L('Eindverslag (een maand)'))}</div></div>`).join('')
    : `<p class="muted small">${L('Geen significante incidenten.')}</p>`}
    <div class="btn-row need-admin">${d.recent.length ? `<select id="nis2-pick" aria-label="${esc(L('Incident'))}"><option value="">${L('Incident van de laatste 30 dagen…')}</option>${d.recent.map((r) => `<option value="${r.id}">${esc(fmtDateTime(r.at))} · ${esc(r.title.slice(0, 80))}${r.host ? ' · ' + esc(r.host) : ''}</option>`).join('')}</select>
      <button type="button" class="btn small-btn" id="nis2-mark">⚠ ${L('Als significant markeren')}</button>` : `<span class="muted small">${L('Geen incidenten van de laatste 30 dagen om te markeren (Incidenten).')}</span>`}</div>
    <p class="muted small">${L('Dit is geen juridisch advies en geen certificering: het laat zien wat RzSense technisch ziet. Of je onder NIS2 valt en wat significant is, bepaal je zelf (of met je adviseur).')}</p>`;
  box.insertAdjacentHTML('afterbegin', compBar);
  box.onchange = async (e) => {
    const c = e.target.closest('[data-nis2-sent]');
    if (!c) return;
    try { await api('nis2_sent', { id: +c.dataset.id, which: c.dataset.nis2Sent, on: c.checked }); loadNis2(); } catch (ex) { toast(ex.message, 'err'); }
  };
}
/** 1.306.0: een maatregel in het detailpaneel; de knoppen werken daar zoals op de pagina */
function nis2Panel(code, o = {}) {
  const det = $(`#tab-compliance details.nis2-m[data-code="${CSS.escape(code)}"], details.nis2-m[data-code="${CSS.escape(code)}"]`);
  if (!det) return;
  const html = det.outerHTML.replace(/^<details class="/, '<details open class="in-panel-d ');
  panelFrom(html, { title: ($('summary b', det) || {}).textContent || code, ref: 'n2:' + code, push: !!o.push, onClick: (e) => nis2Click(e, $('#modal-body')) });
}
DETAIL_OPEN.n2 = (code, o) => nis2Panel(code, o);
async function nis2Click(e, box) {
  {
    const sum = e.target.closest('details.nis2-m:not(.in-panel-d) > summary');
    if (sum) { e.preventDefault(); nis2Panel(sum.parentElement.dataset.code); return; }
    const s = e.target.closest('[data-nis2-save]');
    const un = e.target.closest('[data-nis2-unmark]');
    try {
      if (s) {
        const c = s.dataset.nis2Save;
        const m = s.closest('.nis2-m') || box;
        await api('nis2_note', { code: c, note: $(`[data-nis2-note="${c}"]`, m).value, manual: $(`[data-nis2-manual="${c}"]`, m).checked });
        toast(L('Opgeslagen'));
        loadNis2();
      } else if (un) {
        await api('nis2_mark', { id: +un.dataset.nis2Unmark, on: false });
        loadNis2();
      } else if (e.target.closest('#nis2-mark')) {
        const id = +$('#nis2-pick', box).value;
        if (!id) return toast(L('Kies eerst een incident'), 'err');
        await api('nis2_mark', { id, on: true });
        loadNis2();
      }
    } catch (ex) { toast(ex.message, 'err'); }
  }
}
