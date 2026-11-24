// ---------------------------------------------------------------- 1.317.0: uitrollen naar veel Windows-servers (groepsbeleid)
// Een uitrolcode geldt voor veel servers (tot een datum, hooguit zoveel, eventueel alleen vanaf bepaalde netwerken). De
// MSI installeert de agent en meldt de server aan; de code zie je één keer.

function winMsiCommand(base, code, pin) {
  const p = pin && base.startsWith('https') ? ` PIN="sha256//${pin}"` : '';
  return `msiexec /i "\\\\domein\\NETLOGON\\RzSense\\rzsense-agent.msi" /qn URL="${base}" ENROLL="${code}"${p}`;
}

async function winEnroll() {
  let d;
  try { d = await api('agent_enroll_list'); } catch (ex) { toast(ex.message, 'err'); return; }
  const base = agentBase(null);
  const gen = modalGen + 1;
  const rows = d.codes.length ? `<table class="vuln-table small"><thead><tr><th>${L('Naam')}</th><th>${L('Servers')}</th><th>${L('Geldig tot')}</th><th>${L('Netwerken')}</th><th></th></tr></thead><tbody>
    ${d.codes.map((c) => `<tr class="${c.active ? '' : 'muted'}"><td><b>${esc(c.name)}</b>${c.grp ? `<br><span class="muted">${L('groep')}: ${esc(c.grp)}</span>` : ''}</td>
      <td>${c.used} / ${c.max}</td><td>${esc(fmtDay(c.until))}${c.revoked ? `<br><span class="warn">${L('ingetrokken')}</span>` : !c.active ? `<br><span class="muted">${L('niet meer geldig')}</span>` : ''}</td>
      <td>${c.nets ? `<span class="mono">${esc(c.nets)}</span>` : `<span class="muted">${L('overal')}</span>`}</td>
      <td>${c.active ? `<button type="button" class="btn small-btn" data-enroll-revoke="${c.id}">${L('Intrekken')}</button>` : ''}</td></tr>`).join('')}</tbody></table>`
    : `<p class="muted small">${L('Nog geen uitrolcodes.')}</p>`;
  modal({
    title: '🏢 ' + L('Uitrollen naar veel Windows-servers'), wide: true,
    body: `<p>${L('Met een <b>uitrolcode</b> melden Windows-servers zich zelf aan bij RzSense, bijvoorbeeld via <b>groepsbeleid</b> (de MSI) of een ander beheerprogramma. Elke server krijgt daarna zijn eigen sleutel; de naam in RzSense is de computernaam.')}</p>
      <p class="muted small">${L('Een uitrolcode is een geheim: wie hem heeft, kan servers aanmelden (tot het maximum). Geef hem alleen aan je beheerprogramma, beperk hem tot je eigen netwerken en trek hem in als de uitrol klaar is. Een bestaande servernaam wordt nooit overgenomen.')}</p>
      ${rows}
      <h4>${L('Nieuwe uitrolcode')}</h4>
      <div class="form-grid">
        <label>${L('Naam')}<input id="en-name" maxlength="100" placeholder="${esc(L('bv. Servers kantoor'))}"></label>
        <label>${L('Hoeveel servers')}<input id="en-max" type="number" min="1" max="1000" value="50"></label>
        <label>${L('Dagen geldig')}<input id="en-days" type="number" min="1" max="90" value="30"></label>
        <label>${L('Alleen vanaf (netwerken, leeg = overal)')}<input id="en-nets" placeholder="10.0.0.0/8 192.168.1.0/24"></label>
        <label>${L('Groep in RzSense (optioneel)')}<input id="en-grp" maxlength="60"></label>
      </div>
      <div class="btn-row"><button type="button" class="btn primary" id="en-make">🔑 ${L('Uitrolcode maken')}</button></div>
      <div id="en-out"></div>
      ${xpHtml(L('Zo rol je uit met groepsbeleid.'), `<ol class="small">
        <li>${d.msi ? `${L('Download de MSI')}: <a href="${esc(base)}/api.php?action=agent_win_msi${dlArg()}" download>rzsense-agent.msi</a>` : L('De MSI wordt door GitHub Actions gebouwd; is hij er nog niet, gebruik dan de .exe met <code>install --url … --enroll CODE</code>.')}</li>
        <li>${L('Zet hem in een gedeelde map die de computers kunnen lezen (bv. <code>\\\\domein\\NETLOGON\\RzSense</code>).')}</li>
        <li>${L('Software-installatie in groepsbeleid kan geen eigenschappen meegeven. Gebruik daarom een <b>opstartscript</b> (Computerconfiguratie → Beleid → Windows-instellingen → Scripts → Opstarten) met de regel hieronder, of je eigen beheerprogramma (Intune, SCCM, PDQ …).')}</li>
        <li>${L('Is de agent er al (een bestaande installatie), dan doet de MSI alleen een update en gebruikt hij de code niet.')}</li>
        <li>${L('Let op: een opstartscript kan iedereen in het domein lezen. Beperk de uitrolcode daarom tot je eigen netwerken en een klein aantal servers, en trek hem in zodra de uitrol klaar is.')}</li>
      </ol>`)}`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  const mb = $('#modal-body');
  mb.addEventListener('click', async (e) => {
    const rv = e.target.closest('[data-enroll-revoke]');
    if (rv) {
      if (!confirm(L('Uitrolcode intrekken? Servers die al aangemeld zijn, blijven werken.'))) return;
      try { await api('agent_enroll_revoke', { id: +rv.dataset.enrollRevoke }); toast(L('Ingetrokken')); if (modalGen === gen) winEnroll(); } catch (ex) { toast(ex.message, 'err'); }
      return;
    }
    if (!e.target.closest('#en-make')) return;
    let r;
    try {
      r = await api('agent_enroll_create', { name: $('#en-name').value, max: +$('#en-max').value, days: +$('#en-days').value, nets: $('#en-nets').value, grp: $('#en-grp').value });
    } catch (ex) { toast(ex.message, 'err'); return; }
    if (modalGen !== gen) return;
    const msi = winMsiCommand(base, r.code, d.pin);
    const exe = `& "C:\\Program Files\\RzSense\\rzsense-agent.exe" install --url "${base}" --enroll "${r.code}"${d.pin && base.startsWith('https') ? ` --pin "sha256//${d.pin}"` : ''}`;
    const line = (t) => `<div class="copy-line"><pre class="code">${esc(t)}</pre><button type="button" class="btn" data-copy-text="${esc(t)}">${L('Kopieer')}</button></div>`;
    $('#en-out').innerHTML = `<div class="callout small">✔ ${L('Uitrolcode gemaakt, geldig tot {t}. Je ziet hem alleen nu: kopieer hem.', { t: esc(fmtDay(r.until)) })}</div>
      <p class="small"><b>${L('Uitrolcode')}</b></p>${line(r.code)}
      <p class="small"><b>${L('Met de MSI (opstartscript of beheerprogramma)')}</b></p>${line(msi)}
      <p class="small"><b>${L('Met de .exe')}</b></p>${line(exe)}`;
  }, { signal: modalSignal() });
}
