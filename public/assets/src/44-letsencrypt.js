// ---------------------------------------------------------------- Let's Encrypt (1.358.0)
// Instellingen → Toegang tot RzSense → Beheer van buiten → 🔒 Let's Encrypt-certificaat: aanvragen, koppelen, vernieuwen.
// Het werk zelf doet de hulpdienst (als root) of, in de container, RzSense op de achtergrond; hier alleen de knoppen,
// de voortgang en het stappenplan.
let leInfo = null;
let leTimer = null;

async function loadLe() {
  if (!$('#le-card')) return;
  try { leInfo = await api('le_info'); } catch (ex) { return; }
  renderLe();
  const st = leInfo.status && leInfo.status.state;
  if (st === 'running' || st === 'queued') lePoll();
}

function leCmd(id, cmd) {
  return copyBox('le-cmd-' + id, cmd);
}

function renderLe() {
  const i = leInfo;
  if (!i) return;
  const s = i.state || {};
  const rows = [];
  if (i.mode === 'apache') {
    rows.push(i.via === 'helper' ? `<span class="good">✔ ${L('Gewone installatie (Apache): RzSense doet het via de hulpdienst')}</span>`
      : `<span class="warn-txt">⚠️ ${L('De hulpdienst (als root) ontbreekt: installeer die eerst (stap 4 van het stappenplan), of gebruik het commando in de terminal')}</span>`);
    if (!i.apache_443) rows.push(`<span class="bad">✘ ${L('Apache heeft geen https-blok (poort 443) in rzmon.conf: voer install-rocky.sh opnieuw uit zonder --no-https')}</span>`);
    if (!i.apache_alias) rows.push(`<span class="warn-txt">⚠️ ${L('Apache geeft de controlebestanden van Let\'s Encrypt nog niet: werk RzSense bij (of voer install-rocky.sh opnieuw uit)')}</span>`);
  } else if (i.mode === 'container') {
    rows.push(`<span class="good">✔ ${L('Container: https met Let\'s Encrypt op poort {p} van de server', { p: i.tls_port })}</span>`);
    if (!i.certbot) rows.push(`<span class="bad">✘ ${L('certbot zit niet in deze image: haal de nieuwste image op en start de container opnieuw')}</span>`);
  } else {
    rows.push(`<span class="bad">✘ ${L('Hier kan RzSense geen certificaat koppelen (geen installatie met install-rocky.sh en geen container). Vraag het aan bij je webserver of reverse proxy.')}</span>`);
  }
  if (i.via_proxy) rows.push(`<span class="warn-txt">⚠️ ${L('Je komt nu via een reverse proxy binnen. Die regelt het certificaat meestal zelf; aanvragen hier werkt alleen als poort 80 en 443 van het domein rechtstreeks bij RzSense uitkomen.')}</span>`);
  let html = rows.map((x) => `<div class="small">${x}</div>`).join('');
  if (s.active) {
    const d = (t) => (t ? new Date(t * 1000).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' }) : '–');
    const extra = `<div class="muted small">${L('Aangevraagd {a} · vernieuwd {r} · RzSense vernieuwt vanzelf vanaf {n} dagen voor het verloopt.', { a: d(s.issued_at), r: s.renewed_at ? d(s.renewed_at) : L('nog niet'), n: i.renew_days })}</div>`
      + (s.error ? `<div class="small"><b class="bad">✘ ${L('Laatste poging mislukt')}:</b> ${esc(s.error)}</div>` : '');
    html += i.cert ? certBlock('🔒 ' + esc(s.domain), i.cert, extra) : `<div class="cert-box">${esc(s.domain)}${extra}</div>`;
    if (i.admin_url !== 'https://' + s.domain) {
      html += `<div class="small btn-row"><span class="warn-txt">💡 ${L('Het beheeradres is nog niet https://{d}.', { d: esc(s.domain) })}</span> <button type="button" class="btn small-btn" id="le-use-admin">${L('Als beheeradres gebruiken')}</button></div>`;
    } else if (!i.https_only) {
      html += `<div class="small">💡 ${L('Open RzSense via {url} en zet bij <i>Beheer via https</i> hierboven <b>Beheer alleen via https</b> en <b>2FA verplicht</b> aan.', { url: `<a href="https://${esc(s.domain)}/">https://${esc(s.domain)}</a>` })}</div>`;
    }
  } else if (s.error) {
    html += `<div class="small"><b class="bad">✘ ${L('Laatste poging mislukt')}:</b> ${esc(s.error)}</div>`;
  }
  $('#le-now').innerHTML = html;
  const dom = $('#le-domain');
  if (document.activeElement !== dom && !dom.value) dom.value = s.domain || i.domain || '';
  const em = $('#le-email');
  if (document.activeElement !== em && !em.value) em.value = i.email || '';
  const can = i.mode !== 'none';
  $('#le-renew').hidden = !s.active;
  $('#le-remove').hidden = !s.active;
  $('#le-issue').innerHTML = s.active ? '🔒 ' + L('Opnieuw aanvragen en koppelen') : '🔒 ' + L('Aanvragen en koppelen');
  ['le-test', 'le-issue', 'le-renew', 'le-remove', 'le-check'].forEach((b) => { $('#' + b).disabled = !can; });
  renderLeCheck();
  renderLeProgress(i.status);
  renderLeSteps();
  const ua = $('#le-use-admin');
  if (ua) ua.onclick = leUseAdmin;
  wireCopy($('#le-card'));
}

function renderLeCheck() {
  const c = leInfo && leInfo.check;
  const box = $('#le-checkout');
  if (!c || !c.notes || !c.notes.length) { box.innerHTML = ''; return; }
  const ic = { good: '✔', warn: '⚠️', bad: '✘' };
  const cls = { good: 'good', warn: 'warn-txt', bad: 'bad' };
  box.innerHTML = `<div class="cert-box ${c.notes.some((n) => n[0] === 'bad') ? 'bad' : c.notes.some((n) => n[0] === 'warn') ? 'warn' : 'ok'}">
    <div class="cert-h">🔍 ${L('Voorcontrole voor {d}', { d: esc(c.domain) })} <span class="muted small">${c.at ? ago(new Date(c.at * 1000).toISOString()) : ''}</span></div>
    ${c.notes.map((n) => `<div class="small"><span class="${cls[n[0]] || ''}">${ic[n[0]] || '•'} ${esc(n[1])}</span></div>`).join('')}
    ${c.public_ip ? `<div class="muted small">${L('Publiek IP-adres van deze internetlijn: {ip}', { ip: `<code>${esc(c.public_ip)}</code>` })}</div>` : ''}</div>`;
}

function renderLeProgress(st) {
  const box = $('#le-progress');
  if (!st) { box.innerHTML = ''; return; }
  const names = { test: L('Proefaanvraag'), issue: L('Aanvragen en koppelen'), renew: L('Vernieuwen'), force: L('Nu vernieuwen'), remove: L('Loskoppelen'), deploy: L('Koppelen') };
  const what = esc(names[st.action] || '');
  const log = st.log ? `<details class="adv"><summary>${L('Logboek')}</summary><pre class="mono small le-log">${esc(st.log)}</pre></details>` : '';
  if (st.state === 'running' || st.state === 'queued') {
    box.innerHTML = `<div class="cert-box"><div class="cert-h">⏳ ${what}</div><progress max="100" value="${+st.pct || 0}" style="width:100%"></progress>
      <div class="small">${esc(st.message || '')}</div>${log}</div>`;
  } else if (st.state === 'ok') {
    box.innerHTML = `<div class="cert-box ok"><div class="cert-h">✔ ${what}</div><div class="small">${esc(st.message || '')}</div>${log}</div>`;
  } else {
    box.innerHTML = `<div class="cert-box bad"><div class="cert-h">✘ ${what}</div><div class="small"><b>${esc(st.message || L('Mislukt'))}</b></div>
      ${st.hint ? `<div class="small">💡 ${esc(st.hint)}</div>` : ''}${log}</div>`;
  }
}

/** Het stappenplan: wat er nodig is, hoe, en waar (router, domeinprovider, server), voor deze installatie */
function renderLeSteps() {
  const i = leInfo;
  const dom = ($('#le-domain').value.trim() || i.domain || 'rzsense.jouwdomein.nl').toLowerCase();
  const pub = (i.check && i.check.public_ip) || L('<je publieke IP-adres>');
  const lan = (i.server_ips || []).filter((ip) => !ip.includes(':'))[0] || L('<LAN-IP van RzSense>');
  const steps = [];
  steps.push(`<li>${L('<b>Bij je domeinprovider</b> (TransIP, Cloudflare, Versio …): maak een <b>A-record</b> voor <code>{d}</code> naar je publieke IP-adres <code>{ip}</code>. Gebruik je Cloudflare, zet de proxy dan uit (grijze wolk, <i>DNS only</i>).', { d: esc(dom), ip: esc(pub) })}
    <div class="muted small">${L('Een AAAA-record (IPv6) alleen als poort 80 en 443 ook via IPv6 bij RzSense uitkomen; anders weghalen, want Let\'s Encrypt probeert IPv6 eerst.')}</div></li>`);
  steps.push(`<li>${L('<b>In je router</b> (port forwarding, ook wel <i>poorten doorsturen</i> of <i>NAT</i>): stuur <b>TCP 80</b> en <b>TCP 443</b> door naar <code>{lan}</code>, dezelfde poorten.', { lan: esc(lan) })}
    <div class="muted small">${L('Poort 80 is nodig: daar controleert Let\'s Encrypt bij elke aanvraag en vernieuwing dat het domein bij deze server hoort. RzSense stuurt http zelf door naar https als je <i>Beheer alleen via https</i> aanzet.')}</div></li>`);
  if (i.mode === 'container') {
    steps.push(`<li>${L('<b>Op de server</b> (de host van de container): laat de firewall 80 en 443 doorsturen naar de poorten van de container (http {h}, https {t}):', { h: i.http_port, t: i.tls_port })}
      ${leCmd('fw1', `sudo firewall-cmd --permanent --add-forward-port=port=80:proto=tcp:toport=${i.http_port}`)}
      ${leCmd('fw2', `sudo firewall-cmd --permanent --add-forward-port=port=443:proto=tcp:toport=${i.tls_port}`)}
      ${leCmd('fw3', 'sudo firewall-cmd --reload')}
      <div class="muted small">${L('Een andere https-poort? Zet <code>RZMON_TLS_PORT</code> in <code>/etc/rzsense/rzsense.env</code> en herstart: <code>sudo systemctl restart rzsense</code>. De nooddeur ({door}) blijft zoals hij is.', { door: esc((httpsInfo && httpsInfo.door) || ':8443') })}</div>
      ${!i.certbot ? `<div class="small"><b class="bad">${L('Eerst de nieuwste image:')}</b></div>${leCmd('pull', 'sudo podman pull ghcr.io/rembrand20/rzsense:latest && sudo systemctl restart rzsense')}` : ''}</li>`);
  } else if (i.mode === 'apache') {
    steps.push(`<li>${L('<b>Op de server</b>: http en https open in de firewall (install-rocky.sh doet dat al):')}
      ${leCmd('fw1', 'sudo firewall-cmd --permanent --add-service=http --add-service=https && sudo firewall-cmd --reload')}</li>`);
    if (i.via !== 'helper') {
      steps.push(`<li>${L('<b>Hulpdienst</b>: certbot en Apache hebben root nodig. Installeer de hulpdienst (eenmalig), dan werken de knoppen hier:')}
        ${leCmd('helper', `sudo bash ${i.dir}/install/install-helper.sh`)}
        <div class="muted small">${L('Of alles in de terminal:')}</div>${leCmd('cli', i.cli)}</li>`);
    }
  }
  steps.push(`<li>${L('<b>Thuis</b> (aangeraden): een <b>DNS-rewrite</b> in AdGuard Home (Filters → DNS-herschrijvingen): <code>{d}</code> → <code>{lan}</code>. Dan gebruik je thuis hetzelfde adres met hetzelfde certificaat, ook als je router geen <i>NAT-loopback</i> kent, en kan RzSense zijn eigen certificaat controleren.', { d: esc(dom), lan: esc(lan) })}</li>`);
  steps.push(`<li>${L('<b>Hier</b>: vul het domein in, klik op <b>Voorcontrole</b> en daarna op <b>Proefaanvraag</b> (bij de testserver van Let\'s Encrypt; telt niet mee voor hun limieten). Gelukt? Dan <b>Aanvragen en koppelen</b>.')}</li>`);
  steps.push(`<li>${L('<b>Daarna</b>: zet het beheeradres op <code>https://{d}</code>, open RzSense via dat adres en zet bij <i>Beheer via https</i> <b>Beheer alleen via https</b> en <b>2FA verplicht</b> aan. Overweeg ook <i>Onzichtbaar van buiten</i> hieronder.', { d: esc(dom) })}
    <div class="muted small">${L('De agents hoef je niet aan te passen: ze blijven via het IP-adres met het eigen, vastgezette certificaat van RzSense werken.')}</div></li>`);
  $('#le-steps-body').innerHTML = `<ol class="https-steps small">${steps.join('')}</ol>`;
}

async function leUseAdmin(e) {
  const s = leInfo && leInfo.state;
  if (!s || !s.domain) return;
  const b = e.currentTarget;
  b.disabled = true;
  try {
    const h = httpsInfo || (await api('https_info'));
    await api('https_save', { admin_url: 'https://' + s.domain, https_only: h.https_only, http_login_block: h.http_login_block, mfa_external: h.mfa_external });
    toast(L('Opgeslagen'));
    loadHttps();
    loadLe();
  } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
}

function lePoll() {
  clearTimeout(leTimer);
  leTimer = setTimeout(async () => {
    let r;
    try { r = await api('le_status'); } catch (ex) { lePoll(); return; }
    renderLeProgress(r.status);
    const st = r.status && r.status.state;
    if (st === 'running' || st === 'queued') { lePoll(); return; }
    loadLe();
    loadHttps();
    if (st === 'ok') toast(L('Let\'s Encrypt: klaar'));
    else if (st === 'error') toast(L('Let\'s Encrypt: mislukt'), 'err');
  }, 2000);
}

async function leRequest(what, extra = {}) {
  const err = $('#le-card .form-error');
  err.hidden = true;
  try {
    await api('le_request', { what, domain: $('#le-domain').value.trim(), email: $('#le-email').value.trim(), tos: $('#le-tos').checked, ...extra });
    renderLeProgress({ state: 'queued', action: what, pct: 2, message: L('Wacht op de start…') });
    lePoll();
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
  }
}

function initLe() {
  const card = $('#le-card');
  if (!card) return;
  $('#le-domain').addEventListener('input', () => { if (leInfo) renderLeSteps(); wireCopy(card); });
  $('#le-check').onclick = async (e) => {
    const b = e.currentTarget;
    const err = $('#le-card .form-error');
    err.hidden = true;
    b.disabled = true;
    try {
      const r = await api('le_check', { domain: $('#le-domain').value.trim() });
      if (leInfo) { leInfo.check = r.check; renderLeCheck(); renderLeSteps(); wireCopy(card); }
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
    b.disabled = false;
  };
  $('#le-test').onclick = () => leRequest('test');
  $('#le-issue').onclick = () => {
    const s = leInfo && leInfo.state;
    const dom = $('#le-domain').value.trim().toLowerCase();
    if (s && s.active && s.domain !== dom) {
      modal({
        title: '🔒 ' + L('Ander domein?'),
        body: `<p style="margin-top:0">${L('Er is nu een certificaat voor {a}. Met een nieuw certificaat voor {b} vervalt dat van {a} (het wordt opgeruimd zodra het nieuwe werkt).', { a: `<b>${esc(s.domain)}</b>`, b: `<b>${esc(dom)}</b>` })}</p>`,
        buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Aanvragen'), cls: 'primary', submit: true }],
        onSubmit: async () => { await leRequest('issue'); },
      });
      return;
    }
    leRequest('issue');
  };
  $('#le-renew').onclick = () => leRequest('force');
  $('#le-remove').onclick = () => {
    const s = (leInfo && leInfo.state) || {};
    modal({
      title: '⚠️ ' + L('Let\'s Encrypt loskoppelen?'),
      body: `<p style="margin-top:0">${L('Via {d} krijg je dan weer het eigen certificaat van RzSense: je browser waarschuwt, en van buiten werkt https niet meer goed. Het certificaat wordt verwijderd.', { d: `<b>${esc(s.domain || '')}</b>` })}</p>${confirmField('LOSKOPPELEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Loskoppelen'), cls: 'danger', submit: true }],
      onSubmit: async (d) => {
        await api('le_request', { what: 'remove', confirm: d.confirm });
        renderLeProgress({ state: 'queued', action: 'remove', pct: 2, message: L('Wacht op de start…') });
        lePoll();
      },
    });
  };
}
