// ---------------------------------------------------------------- 🌍 Van buitenaf (aanvalsoppervlak, 1.290.0)
// Instellingen → Bescherming → Van buitenaf: wat staat er van je netwerk open op internet. Bronnen, doelen, de lijst met
// open poorten (goedkeuren met één klik) en wat Shodan bij je IP-adressen ziet.

let expData = null;

async function loadExposure() {
  const box = $('#exposure-body');
  if (!box) return;
  try { expData = await api('exposure_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const d = expData;
  const sw = (k, on, label, hint) => `<label class="switch"><input type="checkbox" data-exp-set="${k}" ${on ? 'checked' : ''}><span></span> ${label}${hint ? ` <span class="muted small">${hint}</span>` : ''}</label>`;
  const src = (s) => ({ shodan: 'Shodan', self: 'RzSense', agent: L('meetpunt') }[s] || s);
  const open = d.rows.filter((r) => !r.expected), ok = d.rows.filter((r) => r.expected);
  const row = (r) => `<tr class="${r.risky && !r.expected ? 'exp-risky' : ''}"><td class="mono">${esc(r.ip)}${r.names ? `<br><span class="muted small">${esc(r.names)}</span>` : ''}</td>
    <td><b>${r.port}</b> ${r.service ? `<span class="muted">${esc(r.service)}</span>` : ''}${r.risky ? ` <span class="u-tag bad">⚠ ${L('risicovol')}</span>` : ''}</td>
    <td class="small">${r.sources.map(src).map(esc).join(', ')}</td><td class="small muted">${esc(ago(r.first_seen))}</td>
    <td class="nowrap">${r.expected ? `<button type="button" class="btn small-btn" data-exp-ok="${esc(r.ip)}|${r.port}|0">↺ ${L('Niet meer verwacht')}</button>`
      : `<button type="button" class="btn small-btn" data-exp-ok="${esc(r.ip)}|${r.port}|1">✔ ${L('Hoort zo')}</button>`}</td></tr>`;
  const info = Object.entries(d.ipinfo || {}).filter(([, i]) => (i.vulns || []).length || (i.cpes || []).length || (i.tags || []).length);
  box.innerHTML = `
    <div class="exp-sets">
      ${sw('exposure_on', d.on, L('Van buitenaf bekijken'))}
      ${sw('exposure_shodan', d.shodan, L('Shodan InternetDB'), L('(stuurt alleen je publieke IP-adressen naar Shodan)'))}
      ${sw('exposure_self', d.self, L('Vanuit RzSense zelf'), L('(via je router: niet altijd wat een aanvaller ziet)'))}
      ${sw('exposure_auto', d.auto, L('Doelen automatisch'), L('(je publieke IP en de domeinen van je reverse proxy)'))}
      ${sw('exposure_notify', d.notify, L('Melden als er iets nieuws open staat'))}
      <label>${L('Hoe vaak')}<select data-exp-set="exposure_hours">${[1, 3, 6, 12, 24, 72, 168].map((h) => `<option value="${h}" ${d.hours === h ? 'selected' : ''}>${h === 168 ? L('elke week') : h === 1 ? L('elk uur') : L('elke {n} uur', { n: h })}</option>`).join('')}</select></label>
      <label>${L('Poorten (vanuit RzSense)')} <span class="muted small">${L('leeg = de {n} bekendste; of bv. 1-1024, 8000-8999 (hooguit 2000)', { n: d.n_top })}</span>
        <span class="rs-agent-url"><input data-exp-ports value="${esc(d.ports)}" class="mono" placeholder="22, 80, 443, 8000-8999"><button type="button" class="btn small-btn" data-exp-ports-save>${L('Opslaan')}</button></span></label>
    </div>
    <div class="btn-row"><button type="button" class="btn primary" data-exp-run ${d.on ? '' : 'disabled'}>🔍 ${L('Nu bekijken')}</button>
      <span class="muted small">${d.last_run ? L('Laatst: {t}', { t: esc(ago(d.last_run)) }) : L('Nog niet bekeken')}${d.self ? ' · ' + L('{n} poorten', { n: d.n_ports }) : ''}</span></div>
    ${d.error ? `<p class="warn-txt small">⚠ ${esc(d.error)}</p>` : ''}
    <h3 class="set-sub">${L('Open op internet')} ${open.length ? `<span class="u-tag bad">${open.length}</span>` : ''}</h3>
    ${open.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Adres')}</th><th>${L('Poort')}</th><th>${L('Gezien door')}</th><th>${L('Sinds')}</th><th></th></tr></thead><tbody>${open.map(row).join('')}</tbody></table></div>`
      : `<p class="muted small">${d.last_run ? '✔ ' + L('Niets onverwachts open.') : L('Zet een bron aan en klik op Nu bekijken.')}</p>`}
    ${ok.length ? `<details class="adv"><summary>${L('Verwacht ({n})', { n: ok.length })}</summary><div class="table-scroll"><table class="vuln-table small"><tbody>${ok.map(row).join('')}</tbody></table></div></details>` : ''}
    ${info.length ? `<h3 class="set-sub">${L('Wat Shodan ziet')}</h3>${info.map(([ip, i]) => `<p class="small"><b class="mono">${esc(ip)}</b>
      ${(i.tags || []).map((t) => `<span class="u-tag">${esc(t)}</span>`).join(' ')}
      ${(i.cpes || []).length ? `<br><span class="muted">${L('Software')}:</span> ${(i.cpes || []).slice(0, 12).map((c) => `<code>${esc(c)}</code>`).join(' ')}` : ''}
      ${(i.vulns || []).length ? `<br><span class="bad">🐞 ${L('{n} bekende kwetsbaarheden', { n: i.vulns.length })}:</span> ${(i.vulns || []).slice(0, 20).map((v) => `<code>${esc(v)}</code>`).join(' ')}` : ''}</p>`).join('')}` : ''}
    <h3 class="set-sub">${L('Doelen')}</h3>
    <div class="table-scroll"><table class="vuln-table small"><tbody>${d.targets.map((t) => `<tr><td class="mono">${esc(t.value)}</td><td class="muted small">${t.source === 'auto' ? L('automatisch') : L('zelf toegevoegd')}${t.note ? ' · ' + esc(t.note) : ''}</td>
      <td>${t.id ? `<button type="button" class="btn small-btn danger" data-exp-del="${t.id}" title="${esc(L('Verwijderen'))}">✕</button>` : ''}</td></tr>`).join('') || `<tr><td class="muted">${L('Nog geen doelen')}</td></tr>`}</tbody></table></div>
    <div class="rs-agent-url" style="margin-top:.5rem"><input data-exp-new placeholder="${esc(L('openbaar IP-adres of domein, bv. thuis.voorbeeld.nl'))}" spellcheck="false">
      <button type="button" class="btn small-btn" data-exp-add>＋ ${L('Doel toevoegen')}</button></div>
    ${d.gone.length ? `<details class="adv"><summary>${L('Weer dicht (laatste 30 dagen)')}</summary><p class="small">${d.gone.map((g) => `<span class="mono">${esc(g.ip)}:${g.port}</span> <span class="muted">${esc(ago(g.gone_at))}</span>`).join(' · ')}</p></details>` : ''}`;
  expEvents(box);
}

function expEvents(box) {
  const save = async (data) => { try { await api('exposure_save', data); } catch (ex) { toast(ex.message, 'err'); } loadExposure(); };
  box.querySelectorAll('[data-exp-set]').forEach((el) => (el.onchange = () => save({ [el.dataset.expSet]: el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value })));
  const ps = $('[data-exp-ports-save]', box);
  if (ps) ps.onclick = () => save({ exposure_ports: $('[data-exp-ports]', box).value });
  const run = $('[data-exp-run]', box);
  if (run) run.onclick = async () => {
    run.disabled = true;
    run.textContent = '⏳ ' + L('Bezig…');
    try { const r = await api('exposure_run', {}); toast(r.message); } catch (ex) { toast(ex.message, 'err'); }
    loadExposure();
  };
  box.querySelectorAll('[data-exp-ok]').forEach((b) => (b.onclick = async () => {
    const [ip, port, on] = b.dataset.expOk.split('|');
    try { await api('exposure_expected', { ip, port: +port, on: on === '1' }); } catch (ex) { toast(ex.message, 'err'); }
    loadExposure();
  }));
  const add = $('[data-exp-add]', box);
  if (add) add.onclick = async () => {
    const v = $('[data-exp-new]', box).value.trim();
    if (!v) return;
    try { await api('exposure_target_add', { value: v }); toast(L('Toegevoegd')); } catch (ex) { toast(ex.message, 'err'); }
    loadExposure();
  };
  box.querySelectorAll('[data-exp-del]').forEach((b) => (b.onclick = async () => {
    try { await api('exposure_target_delete', { id: +b.dataset.expDel }); } catch (ex) { toast(ex.message, 'err'); }
    loadExposure();
  }));
}
