// ---------------------------------------------------------------- 🇪🇺 NIS2 (1.232.0): maatregelen, notities en meldtermijnen
const NIS2_ST = { ok: ['✔', () => L('geregeld'), 'ok'], manual: ['✔', () => L('zelf als geregeld gemarkeerd'), 'ok'], partial: ['◐', () => L('deels'), 'warn'], missing: ['✘', () => L('ontbreekt'), 'bad'] };
const nis2Left = (due) => {
  const s = Math.round((new Date(due.replace(' ', 'T')).getTime() - Date.now()) / 1000);
  if (s <= 0) return `<b class="bad">${L('verlopen')}</b>`;
  return s >= 86400 ? L('nog {d} d {h} u', { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600) }) : `<b class="warn">${L('nog {h} u {m} min', { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) })}</b>`;
};

async function loadNis2() {
  const box = $('#nis2-box');
  if (!box) return;
  let d;
  try { d = await api('nis2'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const cnt = (s) => d.measures.filter((m) => m.status === s || (s === 'ok' && m.status === 'manual')).length;
  const step = (i, w, label) => `<div class="nis2-step ${i[w] ? 'done' : ''}"><b>${label}</b><br>${i[w] ? `✔ ${L('verstuurd')} ${agoSpan(i[w])}` : `${L('uiterlijk {d}', { d: esc(fmtDateTime(i[w + '_due'])) })}<br>${nis2Left(i[w + '_due'])}`}
    <label class="small need-admin"><input type="checkbox" data-nis2-sent="${w}" data-id="${i.id}" ${i[w] ? 'checked' : ''}> ${L('verstuurd')}</label></div>`;
  box.innerHTML = `${!d.on ? `<div class="callout small">${L('Het NIS2-overzicht staat uit; je ziet het wel, maar er komen geen meldingen over termijnen.')}</div>` : ''}
    <div class="app-sum"><span class="app-sum-b"><span class="ok">✔</span> <b>${cnt('ok')}</b> ${L('geregeld')}</span><span class="app-sum-b"><span class="warn">◐</span> <b>${cnt('partial')}</b> ${L('deels')}</span>
      <span class="app-sum-b"><span class="bad">✘</span> <b>${cnt('missing')}</b> ${L('ontbreekt')}</span>
      <a class="btn small-btn" href="api.php?action=nis2_report" target="_blank" rel="noopener">📄 ${L('NIS2-rapport (afdrukken als PDF)')}</a></div>
    <h3 class="set-sub">${L('Maatregelen (art. 21 lid 2)')}</h3>
    <div class="nis2-list">${d.measures.map((m) => { const [ic, lbl, c] = NIS2_ST[m.status]; return `<details class="nis2-m nis2-${c}" ${m.status === 'missing' ? 'open' : ''}>
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
  box.onclick = async (e) => {
    const s = e.target.closest('[data-nis2-save]');
    const un = e.target.closest('[data-nis2-unmark]');
    try {
      if (s) {
        const c = s.dataset.nis2Save;
        await api('nis2_note', { code: c, note: $(`[data-nis2-note="${c}"]`, box).value, manual: $(`[data-nis2-manual="${c}"]`, box).checked });
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
  };
  box.onchange = async (e) => {
    const c = e.target.closest('[data-nis2-sent]');
    if (!c) return;
    try { await api('nis2_sent', { id: +c.dataset.id, which: c.dataset.nis2Sent, on: c.checked }); loadNis2(); } catch (ex) { toast(ex.message, 'err'); }
  };
}
