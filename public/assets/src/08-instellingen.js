// ---------------------------------------------------------------- Settings
// ---------------------------------------------------------------- Access
let accessLock = null;   // inlogbeveiliging, voor de samenvatting in L1nes
async function loadAccess() {
  const a = await api('access_info');
  const f = $('#access-form');
  f.lan_no_login.checked = a.lan_no_login;
  f.lan_as_admin.checked = a.lan_as_admin;
  f.trusted_networks.value = a.trusted_networks;
  f.trusted_proxies.value = a.trusted_proxies;
  if (a.lock) {
    f.login_lock_fails.value = a.lock.fails; f.login_lock_window.value = a.lock.window; f.login_lock_minutes.value = a.lock.minutes;
    f.login_lock_account.checked = a.lock.account; f.login_lock_ip.checked = a.lock.ip;
    accessLock = a.lock;
  }
  const idle = (m) => (m < 60 ? L('{n} minuten', { n: m }) : m === 1440 ? L('1 dag') : L('{n} uur', { n: m / 60 }));
  f.session_idle.innerHTML = (a.session_idle_choices || []).map((m) => `<option value="${m}" ${m === a.session_idle ? 'selected' : ''}>${esc(idle(m))}</option>`).join('');
  $('#ip-status').innerHTML = `${L('Jouw IP-adres: {ip}', { ip: `<code>${esc(a.client_ip)}</code>` })}${a.client_ip !== a.remote_addr ? ` <span class="muted small">${L('(via proxy {ip})', { ip: esc(a.remote_addr) })}</span>` : ''}
    — ${a.client_trusted ? '<b class="yes">' + L('valt binnen je lokale netwerken') + '</b>' : '<b class="no">' + L('valt buiten je lokale netwerken') + '</b>'}
    ${a.unknown_proxy ? `<div class="bad small">⚠️ ${L('Je komt via een proxy ({ip}) die niet bij Reverse proxy IP\'s staat: RzSense behandelt je daarom als extern.', { ip: esc(a.remote_addr) })}</div>` : ''}`;
  settingsSummaries();
}

async function saveAccess(e, el) {
  if (e) e.preventDefault();
  const f = $('#access-form');
  el = el || document.activeElement;
  const err = $('.form-error', f);
  err.hidden = true;
  try {
    await api('access_save', { lan_no_login: f.lan_no_login.checked, lan_as_admin: f.lan_as_admin.checked, trusted_networks: f.trusted_networks.value, trusted_proxies: f.trusted_proxies.value,
      login_lock_fails: f.login_lock_fails.value, login_lock_window: f.login_lock_window.value, login_lock_minutes: f.login_lock_minutes.value,
      login_lock_account: f.login_lock_account.checked, login_lock_ip: f.login_lock_ip.checked, session_idle: +f.session_idle.value });
    if (el && el.form === f) savedFlag(el, true);
    loadAccess();
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
    if (el && el.form === f) savedFlag(el, false, ex.message);
  }
}

// ---------------------------------------------------------------- Beheer via https (1.68.0)
let httpsInfo = null;
async function loadHttps() {
  try { httpsInfo = await api('https_info'); } catch (ex) { return; }
  renderHttps();
}

/** Eén certificaat als blok: uitgever, namen, geldigheid, vingerafdruk */
/** selfOk: het eigen certificaat van RzSense (nooddeur, agents): niet door een bekende uitgever ondertekend is dan normaal */
function certBlock(title, c, extra = '', selfOk = false) {
  if (!c) return '';
  if (!c.ok) return `<div class="cert-box bad"><div class="cert-h">${title}</div><div class="small"><b class="bad">✘ ${esc(c.error || L('geen verbinding'))}</b></div>${extra}</div>`;
  const left = c.days < 0 ? `<b class="bad">${L('VERLOPEN')}</b>` : c.days <= ((httpsInfo && httpsInfo.warn_days) || 14) ? `<b class="warn-txt">${L('nog {n} dagen', { n: c.days })}</b>` : L('nog {n} dagen', { n: c.days });
  const d = (t) => new Date(t * 1000).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
  const own = selfOk && !c.trusted;   // eigen certificaat: self-signed, of van de eigen CA van de webserver (bv. mod_ssl op Rocky)
  const trust = c.trusted ? `<b class="good">✔ ${L('vertrouwd')}</b>` : own || c.self_signed ? `<span class="muted" title="${esc(L('Niet ondertekend door een bekende uitgever. Voor het eigen certificaat van RzSense is dat normaal: de agents zetten het vast, en je browser vraagt eenmalig om "toch doorgaan".'))}">🔏 ${L('self-signed')}</span>` : `<b class="bad">✘ ${esc(c.error || L('niet vertrouwd'))}</b>`;
  const okish = (c.trusted || own) && c.days > ((httpsInfo && httpsInfo.warn_days) || 14);
  return `<div class="cert-box ${okish ? 'ok' : c.days >= 0 && (c.trusted || c.self_signed || own) ? 'warn' : 'bad'}">
    <div class="cert-h">${title} ${trust}</div>
    <dl class="cert-dl small">
      <dt>${L('Uitgever')}</dt><dd>${esc(c.issuer)}</dd>
      <dt>${L('Namen')}</dt><dd>${esc((c.names && c.names.length ? c.names : [c.cn]).join(', '))}</dd>
      <dt>${L('Geldig')}</dt><dd>${d(c.from)} – ${d(c.to)} · ${left}</dd>
      <dt>${L('Vingerafdruk')}</dt><dd class="mono fp">SHA-256 ${esc(c.fp)}</dd>
    </dl>${extra}</div>`;
}

function renderHttps() {
  const h = httpsInfo;
  if (!h) return;
  const r = h.req;
  const ok = (b, yes, no) => (b ? `<span class="good">✔ ${yes}</span>` : `<span class="bad">✘ ${no}</span>`);
  // Hoe zit je er nu?
  const rows = [
    ok(r.https, L('je bent nu via https verbonden'), L('je bent nu via http verbonden')) + ` <span class="muted small">(${esc(r.host)})</span>`,
    r.unknown_proxy ? `<span class="bad">✘ ${L('via een proxy die niet bij Vertrouwde proxy\'s staat ({ip}): je geldt als extern', { ip: esc(r.remote_addr) })}</span>`
      : r.via_proxy ? (r.no_proto ? `<span class="warn-txt">⚠️ ${L('via je proxy {ip}, maar die meldt geen https (X-Forwarded-Proto)', { ip: esc(r.remote_addr) })}</span>` : `<span class="good">✔ ${L('via je vertrouwde proxy {ip}', { ip: esc(r.remote_addr) })}</span>`)
      : `<span class="muted">${L('rechtstreeks, zonder proxy')}</span>`,
    r.internal ? `<span class="good">🏠 ${L('vanaf je interne netwerk ({ip})', { ip: esc(r.client_ip) })}</span>` : `<span class="warn-txt">🌍 ${L('van buiten je interne netwerken ({ip})', { ip: esc(r.client_ip) })}</span>`,
  ];
  if (h.admin_url) rows.push(r.on_admin ? `<span class="good">✔ ${L('je zit op het beheeradres')}</span>` : `<span class="muted">${L('je zit niet op het beheeradres')} – <a href="${esc(h.admin_url)}/">${esc(h.admin_url)}</a></span>`);
  $('#https-now').innerHTML = rows.map((x) => `<div>${x}</div>`).join('');
  const urlIn = $('#https-url');
  if (document.activeElement !== urlIn) urlIn.value = h.admin_url || '';
  // Certificaten en DNS
  const s = h.state || {};
  const host = h.admin_url ? h.admin_url.replace(/^https:\/\//, '').replace(/[:/].*$/, '') : '';
  let html = '';
  if (h.admin_url && s.admin) {
    const dns = s.dns ? (s.dns.ips.length ? (s.dns.private ? `<div class="small"><span class="good">✔ ${L('DNS: {host} wijst intern naar {ip}', { host: esc(host), ip: esc(s.dns.ips.join(', ')) })}</span></div>`
      : `<div class="small"><span class="warn-txt">⚠️ ${L('DNS: {host} wijst naar {ip} (openbaar). Zet in AdGuard Home een DNS-rewrite naar het LAN-IP van SWAG.', { host: esc(host), ip: esc(s.dns.ips.join(', ')) })}</span></div>`)
      : `<div class="small"><span class="bad">✘ ${L('DNS: {host} is vanaf de RzSense-server niet te vinden', { host: esc(host) })}</span></div>`) : '';
    html += certBlock('🌍 ' + L('Beheeradres'), s.admin, dns);
  } else if (h.admin_url) html += `<p class="muted small">${L('Nog niet gecontroleerd: klik op Nu controleren.')}</p>`;
  if (s.local) html += certBlock('🚪 ' + L('Nooddeur') + (h.door.includes('<') ? ` <span class="small muted">${esc(h.door)}</span>` : ` <a class="small" href="${esc(h.door)}">${esc(h.door)}</a>`), s.local, `<div class="muted small">${L('Alleen vanaf je interne netwerken; het certificaat van RzSense zelf.')}</div>`, true);
  if (h.checked_at) html += `<p class="muted small" style="margin:.3rem 0 0">${L('Gecontroleerd {ago}; daarna elke dag. ℹ️ {n} dagen voor het verloopt, ⚠️ als het verlopen of ongeldig is.', { ago: ago(h.checked_at), n: h.warn_days })}</p>`;
  $('#https-cert').innerHTML = html;
  // Schakelaars
  $('#https-only').checked = h.https_only;
  $('#https-block').checked = h.http_login_block;
  $('#https-mfa').checked = h.mfa_external;
  $('#https-hsts').innerHTML = h.hsts ? (h.hsts.full_at ? L('HSTS: 1 dag (testfase). Na {d} zonder problemen met het certificaat vanzelf 1 jaar.', { d: esc(fmtDateTime(h.hsts.full_at)) }) : L('HSTS: 1 jaar.'))
    : (h.admin_url && !r.on_admin ? L('Aanzetten kan alleen als je RzSense nu via het beheeradres opent.') : '');
  $('#https-mfa-info').innerHTML = h.no2fa.length ? L('Zonder 2FA (van buiten dan geweigerd): {names}', { names: h.no2fa.map(esc).join(', ') }) : L('Alle gebruikers hebben 2FA.');
  // Voorbeeld voor SWAG
  $('#https-conf').textContent = `## nginx/proxy-confs/rzmon.subdomain.conf\nserver {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name ${host ? host.split('.')[0] : 'rzmon'}.*;\n    include /config/nginx/ssl.conf;\n    client_max_body_size 64m;\n\n    location / {\n        include /config/nginx/proxy.conf;\n        include /config/nginx/resolver.conf;\n        set $upstream_app ${s.server_ip || '192.168.1.10'};\n        set $upstream_port 80;\n        set $upstream_proto http;\n        proxy_pass $upstream_proto://$upstream_app:$upstream_port;\n    }\n}`;
}

async function saveHttps(extra = {}, el = null) {
  const err = $('#https-card .form-error');
  err.hidden = true;
  try {
    await api('https_save', { admin_url: $('#https-url').value.trim(), https_only: $('#https-only').checked, http_login_block: $('#https-block').checked, mfa_external: $('#https-mfa').checked, ...extra });
    if (el) savedFlag(el, true);
    toast(L('Opgeslagen'));
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
    if (el) savedFlag(el, false, ex.message);
  }
  await loadHttps();
  loadSettingsHealth();
}

function initHttps() {
  const card = $('#https-card');
  if (!card) return;
  const sw = { 'https-only': 'https_only', 'https-block': 'http_login_block', 'https-mfa': 'mfa_external' };
  card.addEventListener('change', (e) => {
    const t = e.target;
    if (t.id === 'https-url') { saveHttps({}, t); return; }
    if (!sw[t.id] || !httpsInfo) return;
    if (t.checked || !httpsInfo[sw[t.id]]) { saveHttps({}, t); return; }
    // Uitzetten maakt het beheer minder veilig: eerst bevestigen
    modal({
      title: '⚠️ ' + L('Minder veilig maken?'),
      body: `<p style="margin-top:0">${t.id === 'https-only' ? L('De beheerpagina is dan ook weer via http bereikbaar. Je browser krijgt het signaal om HSTS te vergeten.')
        : t.id === 'https-block' ? L('Inloggen via http kan dan weer: je wachtwoord gaat dan leesbaar over het netwerk.')
        : L('Van buiten je interne netwerken is dan een wachtwoord genoeg.')}</p>${confirmField('UITZETTEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); renderHttps(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
      onSubmit: async (d) => { await api('https_save', { admin_url: $('#https-url').value.trim(), https_only: $('#https-only').checked, http_login_block: $('#https-block').checked, mfa_external: $('#https-mfa').checked, confirm: d.confirm }); toast(L('Opgeslagen')); loadHttps(); loadSettingsHealth(); },
    });
  });
  $('#https-check').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      if ($('#https-url').value.trim() !== (httpsInfo && httpsInfo.admin_url || '')) await saveHttps();
      else { await api('https_check', {}); await loadHttps(); loadSettingsHealth(); }
    } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  };
}

// ---------------------------------------------------------------- Datalekken: instellingen (1.69.0)
let hibpInfo = null;
async function loadHibp() {
  try { hibpInfo = await api('hibp_info'); } catch (ex) { return; }
  const h = hibpInfo;
  $('#hibp-on').checked = h.enabled;
  $('#hibp-body').classList.toggle('dim', !h.enabled);
  const set = (id, v) => { if (document.activeElement !== $(id)) $(id).value = v; };
  set('#hibp-threshold', h.threshold);
  set('#hibp-emails', h.emails);
  set('#hibp-domain', h.domain);
  $('#hibp-key').value = '';
  $('#hibp-key').placeholder = h.has_key ? L('ingevuld (laat leeg om te houden)') : L('nog geen sleutel');
  $('#hibp-key-state').innerHTML = h.has_key ? `<span class="good-txt">✔ ${L('ingevuld')}</span> <a href="#" id="hibp-key-del">${L('verwijderen')}</a>` : '';
  const s = h.summary;
  $('#hibp-status').innerHTML = !h.enabled ? L('Staat uit: er gaat niets naar Have I Been Pwned.')
    : [h.has_key ? L('{n} adressen; {r} verzoeken per minuut', { n: h.count, r: h.rpm }) : L('Zonder sleutel: alleen wachtwoorden.'),
      h.check_at ? L('laatst gecontroleerd {t}', { t: ago(h.check_at) }) : '',
      s && s.open ? `<b class="warn-txt">${L('{n} open lekken', { n: s.open })}</b>` : '',
      h.last_error ? `<b class="bad">⚠️ ${esc(h.last_error)}</b>` : '', h.domain_error ? `<b class="bad">⚠️ ${esc(h.domain_error)}</b>` : ''].filter(Boolean).join(' · ');
  $('#hibp-key-del')?.addEventListener('click', async (e) => { e.preventDefault(); if (!confirm(L('API-sleutel verwijderen?'))) return; await saveHibp({ key: '' }); });
}

async function saveHibp(data, el) {
  const err = $('#hibp-card .form-error');
  err.hidden = true;
  try {
    await api('hibp_save', data);
    if (el) savedFlag(el, true);
    await loadHibp();
    loadSettingsHealth();
    return true;
  } catch (ex) {
    err.textContent = ex.message; err.hidden = false;
    if (el) savedFlag(el, false, ex.message);
    return false;
  }
}

function initHibp() {
  if (!$('#hibp-card')) return;
  $('#hibp-on').onchange = async (e) => {
    if (await saveHibp({ enabled: e.target.checked }, e.target)) toast(e.target.checked ? L('Controle op datalekken staat aan') : L('Controle op datalekken staat uit'));
    else e.target.checked = !e.target.checked;
  };
  $('#hibp-threshold').onchange = (e) => saveHibp({ threshold: +e.target.value }, e.target);
  $('#hibp-emails').onchange = (e) => saveHibp({ emails: e.target.value }, e.target);
  $('#hibp-domain').onchange = (e) => saveHibp({ domain: e.target.value }, e.target);
  $('#hibp-key').onchange = (e) => { if (e.target.value.trim()) saveHibp({ key: e.target.value.trim() }, e.target); };
  $('#hibp-test').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    const out = $('#hibp-test-out');
    try {
      const r = await api('hibp_test', { key: $('#hibp-key').value.trim() });
      out.innerHTML = `<span class="good-txt">✔ ${L('Sleutel werkt: {name}', { name: esc(r.name) })}</span>${r.rpm ? ' · ' + L('{n} verzoeken per minuut', { n: r.rpm }) : ''}${r.until ? ' · ' + L('tot {d}', { d: esc(fmtDay(r.until.slice(0, 10))) }) : ''}`;
    } catch (ex) { out.innerHTML = `<span class="bad">✘ ${esc(ex.message)}</span>`; }
    b.disabled = false;
  };
}

// ---------------------------------------------------------------- Certificaat voor de agents (1.70.0)
/** Venster: welk certificaat de agents zien, de vingerafdruk per agent, downloaden en vernieuwen */
async function agentCertDialog() {
  let d;
  try { d = await api('agent_cert'); } catch (ex) { return toast(ex.message, 'err'); }
  const loc = d.local || {};
  const httpAgents = d.scheme !== 'https';
  const where = !d.base ? `<span class="warn-txt">${L('Geen Basis-URL ingesteld (Setup); de agents gebruiken het adres uit hun installatiecommando.')}</span>`
    : httpAgents ? `<code>${esc(d.base)}</code> · 🔓 ${L('http: geen certificaat nodig. De berichten zijn wel versleuteld met de eigen sleutel van elke agent.')}`
    : d.own ? `<code>${esc(d.base)}</code> · ${L('het eigen certificaat van RzSense (hieronder)')}`
    : `<code>${esc(d.base)}</code> · ${L('een ander certificaat, bv. van je reverse proxy (Let\'s Encrypt): dat vernieuwt de proxy zelf')}`;
  const pinRow = !d.pin_on ? `<p class="small muted">📌 ${L('Certificaat vastzetten staat uit.')}</p>`
    : d.pin ? `<p class="small cert-pin">📌 ${L('Vingerafdruk die de agents vastzetten')}: <code class="small">sha256//${esc(d.pin)}</code>
        ${loc.pin ? (loc.pin === d.pin ? ` <span class="good-txt">✔ ${L('klopt met het certificaat')}</span>` : ` <span class="warn-txt">⏳ ${L('wordt bij het volgende rapport opnieuw bepaald')}</span>`) : ''}</p>`
    : (d.pinned || []).length && loc.pin ? `<p class="small cert-pin">📌 ${L('Vingerafdruk die de agents vastzetten')}: <code class="small">sha256//${esc(loc.pin)}</code></p>`
    : `<p class="small muted">📌 ${L('Niets vast te zetten: de agents gebruiken http, of een certificaat dat al vertrouwd is.')}</p>`;
  const hostRows = d.hosts.map((h) => {
    const off = h.pin_mode === 'off' || (!h.pin_mode && !d.pin_on);
    // Agent 1.53.0: vastzetten per adres (het eigen certificaat), met een klaargezet nieuw certificaat erbij
    const specs = `${h.agent_url_pin || ''};${h.agent_url_fb_pin || ''}`;
    const perUrl = loc.pin && specs.includes('sha256//') ? (specs.includes('sha256//' + loc.pin)
      ? `<span class="good-txt">✔ ${L('kent het huidige certificaat')}</span>${d.next ? (specs.includes('sha256//' + d.next.pin) ? ` · <span class="good-txt">✔ ${L('en het nieuwe')}</span>` : ` · <span class="warn-txt">⏳ ${L('het nieuwe nog niet')}</span>`) : ''}`
      : `<span class="warn-txt">⏳ ${L('krijgt het nieuwe bij zijn volgende rapport')}</span>`) : '';
    const st = perUrl || (off ? `<span class="muted">${L('vastzetten uit')}</span>` : !d.pin ? '<span class="muted">–</span>'
      : h.agent_pin_sent === d.pin ? `<span class="good-txt">✔ ${L('kent het huidige certificaat')}</span>`
      : h.agent_pin_sent ? `<span class="warn-txt">⏳ ${L('krijgt het nieuwe bij zijn volgende rapport')}</span>` : `<span class="muted">${L('nog niet')}</span>`);
    return `<tr><td>${esc(h.name)}</td><td class="mono small">${esc(h.agent_version || '–')}</td><td class="small">${st}</td>
      <td class="small muted">${h.agent_pin_sent_at ? esc(ago(h.agent_pin_sent_at)) : ''}</td></tr>`;
  }).join('');
  const canRenew = d.paths && d.via !== 'cli';
  // Klaargezet nieuw certificaat (1.78.0): de agents krijgen eerst de vingerafdruk, daarna wisselt RzSense
  const nx = d.next;
  const waiting = (d.pinned || []).filter((a) => !a.ready);
  const nextBox = nx ? `<div class="callout">🔐 <b>${L('Nieuw certificaat klaargezet')}</b> <span class="muted small">${esc(ago(new Date(nx.at * 1000).toISOString()))}</span><br>
      <span class="small">${(d.pinned || []).length ? L('{r} van {t} agents hebben de nieuwe vingerafdruk.', { r: d.pinned.length - waiting.length, t: d.pinned.length }) : ''}
      ${waiting.length ? L('Nog niet: {names}.', { names: waiting.map((a) => esc(a.name)).join(', ') }) + ' ' : ''}${L('RzSense wisselt zodra alle agents hem hebben, uiterlijk {d}.', { d: new Date(nx.deadline * 1000).toLocaleDateString(LOCALE) })}</span>
      <div class="btn-row" style="margin-top:.4rem">${canRenew ? `<button type="button" class="btn small-btn" data-cert-act>🔄 ${L('Nu wisselen')}</button>` : ''}
        <button type="button" class="btn small-btn" data-cert-cancel>${L('Annuleren')}</button></div></div>` : '';
  modal({
    title: '🔐 ' + L('Certificaat van RzSense'),
    wide: true,
    body: `
      <p class="small" style="margin-top:0"><b>${L('Adres van de agents')}:</b> ${where}</p>
      ${d.agent_cert && !d.own ? certBlock('🌍 ' + L('Certificaat op het adres van de agents'), d.agent_cert) : ''}
      ${loc.ok === false ? `<div class="cert-box bad"><div class="cert-h">🚪 ${L('Eigen certificaat van RzSense (poort 443)')}</div><div class="small">✘ ${esc(loc.error || '')}</div></div>`
        : certBlock('🚪 ' + L('Eigen certificaat van RzSense (poort 443)'), loc, `<div class="muted small">${L('Voor agents via https op het IP-adres, en de nooddeur.')}</div>`, true)}
      ${pinRow}
      ${nextBox}
      ${d.hosts.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Agent')}</th><th>${L('Versie')}</th><th>📌 ${L('Certificaat')}</th><th>${L('Laatst gekregen')}</th></tr></thead><tbody>${hostRows}</tbody></table></div>` : ''}
      <h3 class="set-sub">🔄 ${L('Vernieuwen')}</h3>
      <p class="small muted" style="margin-top:0">${L('Een nieuw certificaat met een nieuwe sleutel, {n} jaar geldig. Agents met een vastgezet certificaat gaan vanzelf mee: ze krijgen de nieuwe vingerafdruk in een versleuteld antwoord. Je browser vraagt bij https op het IP-adres opnieuw om "toch doorgaan".', { n: Math.round(d.days / 365) })}</p>
      ${(d.pinned || []).length ? `<p class="small">📌 ${L('{n} agent(s) zetten het eigen certificaat vast. RzSense zet het nieuwe daarom eerst klaar: de agents krijgen de nieuwe vingerafdruk, en pas als ze die allemaal hebben (uiterlijk na {d} dagen) gaat RzSense het nieuwe gebruiken. Zo weigert geen enkele agent het certificaat.', { n: d.pinned.length, d: d.next_days })}</p>` : ''}
      <div class="small"><b>${L('Namen in het nieuwe certificaat')}:</b> <span class="cert-names">${d.names.map((n) => `<span class="lc">${esc(n)}</span>`).join('')}</span></div>
      <label class="small">${L('Extra namen of IP-adressen')} <span class="hint">${L('(één per regel; de rest gaat automatisch)')}</span>
        <textarea id="cert-extra" rows="2" spellcheck="false" placeholder="nas.thuis.lan">${esc(d.extra)}</textarea></label>
      ${!d.paths ? `<p class="small warn-txt">⚠️ ${L('Waar het certificaat staat, is niet bekend (geen installatie met install-rocky.sh). Zet ssl_cert en ssl_key in config.php.')}</p>`
        : d.via === 'cli' ? `<p class="small">${L('Vernieuwen kan hier alleen als root (de hulpdienst is niet geïnstalleerd). Op de server:')}</p>${copyBox('cmd-renew-cert', d.cli)}`
        : `<p class="small muted">${L('Bestanden')}: <code>${esc(d.paths.cert)}</code> · <code>${esc(d.paths.key)}</code>${d.via === 'helper' ? ' · ' + L('via de hulpdienst') : ''}</p>`}`,
    buttons: [
      { label: '⬇ ' + L('Downloaden (.crt)'), style: 'margin-right:auto', onClick: () => { if (loc.ok) agentCertDownload(); } },
      ...(canRenew ? [{ label: '🔄 ' + L('Vernieuwen'), cls: 'danger', onClick: () => agentCertRenew(d) }] : []),
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  $('#cert-extra').onchange = async (e) => {
    try { await api('agent_cert_names', { names: e.target.value }); savedFlag(e.target, true); agentCertDialog(); }
    catch (ex) { savedFlag(e.target, false, ex.message); toast(ex.message, 'err'); }
  };
  const act = $('[data-cert-act]');
  if (act) act.onclick = () => agentCertRenew(d, 'activate');
  const cancel = $('[data-cert-cancel]');
  if (cancel) cancel.onclick = async () => { try { await api('agent_cert_cancel', {}); toast(L('Geannuleerd')); agentCertDialog(); } catch (ex) { toast(ex.message, 'err'); } };
}

function agentCertDownload() {
  modal({
    title: '⬇ ' + L('Certificaat downloaden'),
    body: `<p style="margin-top:0" class="small">${L('Zet het certificaat in je browser of besturingssysteem als vertrouwd: dan geeft https op het IP-adres van RzSense geen waarschuwing meer.')}</p>${confirmField('DOWNLOADEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => agentCertDialog() }, { label: L('Downloaden'), cls: 'primary', submit: true }],
    onSubmit: async (f) => {
      const r = await api('agent_cert_download', { confirm: f.confirm });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([r.pem], { type: 'application/x-x509-ca-cert' }));
      a.download = r.name;
      document.body.append(a);
      a.click();
      a.remove();
      toast(L('Certificaat gedownload'));
      setTimeout(agentCertDialog, 50);
    },
  });
}

/** Vernieuwen (met vastgezette agents eerst klaarzetten, of meteen), of (what = 'activate') het klaargezette gaan gebruiken */
function agentCertRenew(d, what = 'renew') {
  const activate = what === 'activate';
  const pinned = (d.pinned || []).length > 0;
  const waiting = (d.pinned || []).filter((a) => !a.ready);
  modal({
    title: '🔄 ' + (activate ? L('Nieuw certificaat nu gebruiken?') : L('Certificaat vernieuwen?')),
    body: activate
      ? `<p style="margin-top:0" class="small">${waiting.length ? L('Deze agents hebben de nieuwe vingerafdruk nog niet: {names}. Ze weigeren het nieuwe certificaat één keer en halen de vingerafdruk dan versleuteld op (meestal via hun reserve-adres).', { names: waiting.map((a) => esc(a.name)).join(', ') })
        : L('Alle agents hebben de nieuwe vingerafdruk.')}</p>${confirmField('WISSELEN')}`
      : `<p style="margin-top:0" class="small">${L('RzSense maakt een nieuw certificaat voor: {names}. Het oude blijft als reservekopie naast het origineel staan; mislukt herladen, dan wordt het teruggezet.', { names: d.names.map(esc).join(', ') })}</p>
        ${pinned ? `<label class="switch small"><input type="checkbox" name="now"><span></span> ${L('Meteen wisselen (niet wachten tot de agents de nieuwe vingerafdruk hebben)')}</label>` : ''}${confirmField('VERNIEUWEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => agentCertDialog() }, { label: activate ? L('Nu wisselen') : L('Vernieuwen'), cls: 'danger', submit: true }],
    onSubmit: async (f) => {
      let r = await api(activate ? 'agent_cert_activate' : 'agent_cert_renew', { confirm: f.confirm, now: !!f.now });
      for (let i = 0; r.pending && i < 40; i++) {
        await new Promise((ok) => setTimeout(ok, 1500));
        r = await api('agent_cert_renew_status');
      }
      toast(r.prepared ? L('Nieuw certificaat klaargezet; de agents krijgen eerst de nieuwe vingerafdruk')
        : L('Nieuw certificaat, geldig tot {d}', { d: new Date(r.to * 1000).toLocaleDateString(LOCALE) }));
      setTimeout(agentCertDialog, 50);
    },
  });
}

// ---------------------------------------------------------------- API keys
function apiBase() {
  return location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/v1.php';
}
/** 1.197.0: de YAML voor de Home Assistant-kaart (met of zonder sleutel) */
function haCardYaml(key) {
  const base = apiBase().replace(/\/v1\.php$/, '');
  return `type: custom:rzmon-network-card\nurl: ${base}\napi_key: ${key || '<sleutel met Status lezen>'}\nrefresh: 60`;
}
function haCardInfo() {
  const u = $('#ha-card-url');
  if (!u) return;
  const url = apiBase().replace(/\/v1\.php$/, '') + '/ha-card.php';
  u.textContent = url;
  $('#ha-card-yaml').textContent = haCardYaml('');
  $('#ha-card-copy').onclick = async () => { try { await navigator.clipboard.writeText(url); toast(L('Gekopieerd')); } catch (e) { /* niet erg */ } };
}

const API_RIGHTS = {
  read: [L('📖 Status lezen'), L('servers, checks, meetwaarden, meldingen')],
  ids_read: [L('🛡️ IDS lezen'), L('incidenten, blokkades, logins')],
  vulns_read: [L('🐞 Kwetsbaarheden lezen'), L('CVE\'s per server')],
  ids_block: [L('⛔ IDS blokkeren'), L('IP\'s blokkeren en deblokkeren')],
  checks: [L('✔ Checks bedienen'), L('bevestigen, onderhoud, nu checken')],
};

/** Rechten, alleen vanaf en geldigheid; voor een nieuwe sleutel en voor ✎ (k = bestaande sleutel) */
function apiKeyFields(k) {
  const has = k ? String(k.rights || 'read').split(',') : ['read'];
  return `<div class="api-rights">${Object.entries(API_RIGHTS).map(([r, [label, hint]]) => `
      <label><input type="checkbox" name="rights" value="${r}" ${has.includes(r) ? 'checked' : ''}><span>${label}<small>${hint}</small></span></label>`).join('')}</div>
    <div class="row2">
      <label>${L('Alleen vanaf')} <span class="hint">${L('(optioneel; IP\'s of netwerken, bv. 192.168.1.10 10.0.0.0/24)')}</span>
        <input name="allow_from" maxlength="500" spellcheck="false" value="${esc(k?.allow_from || '')}" placeholder="${L('overal vandaan')}"></label>
      <label>${k ? L('Verlengen') : L('Geldig')}
        <select name="months">${k ? '<option value="0">' + L('niet verlengen') + '</option>' : ''}
          ${[6, 12, 24].map((m) => `<option value="${m}" ${!k && m === 12 ? 'selected' : ''}>${k ? L('met {m} maanden vanaf nu', { m }) : L('{m} maanden', { m })}</option>`).join('')}</select></label>
    </div>`;
}

/** Wat het formulier stuurt: rights als lijst */
function apiKeyData(form) {
  const fd = new FormData(form);
  return { ...Object.fromEntries(fd), rights: fd.getAll('rights') };
}

const fmtDay = (dt) => new Date(dt.replace(' ', 'T')).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });

function apiKeyExpiry(k) {
  if (!k.expires_at) return '<span class="muted">' + L('geen vervaldatum') + '</span>';
  const days = Math.floor((new Date(k.expires_at.replace(' ', 'T')) - Date.now()) / 86400000);
  if (days < 0) return `<span class="crit-txt">${L('verlopen op {date}', { date: esc(fmtDay(k.expires_at)) })}</span>`;
  return `<span class="${days <= 30 ? 'warn-txt' : 'muted'}">${L('geldig tot {date}', { date: esc(fmtDay(k.expires_at)) })}${days <= 30 ? ' ' + (days === 1 ? L('(nog 1 dag)') : L('(nog {n} dagen)', { n: days })) : ''}</span>`;
}

let apiKeys = [];
async function loadApiKeys() {
  $('#api-base').textContent = apiBase();
  haCardInfo();   // 1.197.0
  if (!$('#api-key-fields').innerHTML) $('#api-key-fields').innerHTML = apiKeyFields();
  apiKeys = await api('api_keys');
  $('#api-keys').innerHTML = apiKeys.length ? apiKeys.map((k) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(k.name)}</strong> <code class="muted">${esc(k.key_hint)}</code><br>
        ${String(k.rights || 'read').split(',').map((r) => `<span class="api-right ${r}">${esc(API_RIGHTS[r]?.[0] || r)}</span>`).join('')}<br>
        <span class="muted small">${k.allow_from ? L('Alleen vanaf {ip}', { ip: esc(k.allow_from) }) + ' · ' : ''}${apiKeyExpiry(k)} · ${L('laatst gebruikt: {t}', { t: k.last_used ? ago(k.last_used) : L('nooit') })}</span></span>
      <button class="icon-btn" title="${L('Rechten, adressen en geldigheid')}" data-edit="${k.id}">✎</button>
      <button class="icon-btn" title="${L('Intrekken')}" data-del="${k.id}" data-name="${esc(k.name)}">🗑</button>
    </div>`).join('') : '<p class="muted small">' + L('Nog geen sleutels.') + '</p>';
  settingsSummaries();
}

/** ⛔ blokkeren via de API vraagt om bevestiging; daarna (of meteen) opslaan */
function apiKeyConfirm(title, data, save) {
  modal({
    title,
    body: `<p style="margin-top:0">${L('Met het recht <b>⛔ IDS blokkeren</b> kan wie deze sleutel heeft IP-adressen blokkeren op je servers (als het IDS op <i>blokkeren</i> staat; in de leerstand wordt het alleen vastgelegd). Je LAN, RzSense zelf en de lijst <i>Nooit blokkeren</i> weigert de API altijd. Overweeg <i>Alleen vanaf</i> in te vullen.')}</p>
      ${confirmField('BLOKKEREN')}`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => save({ ...data, confirm: d.confirm }),
  });
}

async function createApiKey(e) {
  e.preventDefault();
  const form = e.target;
  const data = apiKeyData(form);
  if (!data.rights.length) return toast(L('Kies minstens één recht'), 'err');
  const save = async (d) => {
    const r = await api('api_key_create', d);
    form.reset();
    $('#api-key-fields').innerHTML = apiKeyFields();
    loadApiKeys();
    setTimeout(() => newApiKeyDialog(r.key, d.rights), 0);
  };
  if (data.rights.includes('ids_block')) return apiKeyConfirm(L('⛔ Sleutel met blokkeerrecht'), data, save);
  try { await save(data); } catch (ex) { toast(ex.message, 'err'); }
}

function apiKeyEdit(k) {
  const before = String(k.rights || '').split(',');
  modal({
    title: `✎ ${L('API-sleutel {name}', { name: k.name })}`,
    body: `<div class="api-key-form">${apiKeyFields(k)}<p class="muted small" style="margin:0">${apiKeyExpiry(k)}</p></div>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d, form) => {
      const data = { ...apiKeyData(form), id: k.id };
      if (!data.rights.length) throw new Error(L('Kies minstens één recht'));
      const save = async (x) => { await api('api_key_update', x); toast(L('Sleutel aangepast')); loadApiKeys(); };
      if (data.rights.includes('ids_block') && !before.includes('ids_block')) {
        setTimeout(() => apiKeyConfirm('⛔ ' + L('Blokkeerrecht toevoegen'), data, save), 0);
        return;
      }
      await save(data);
    },
  });
}

function newApiKeyDialog(key, rights) {
  const auth = `-H "Authorization: Bearer ${esc(key)}"`;
  const ex = [];
  if (rights.includes('read')) ex.push(`curl ${auth} ${esc(apiBase())}/status`);
  if (rights.includes('ids_read')) ex.push(`curl ${auth} ${esc(apiBase())}/ids/incidents?status=open`);
  if (rights.includes('vulns_read')) ex.push(`curl ${auth} ${esc(apiBase())}/vulnerabilities`);
  if (rights.includes('ids_block')) ex.push(`curl ${auth} -H "Content-Type: application/json" \\
  -d '{"host":"web01","ip":"203.0.113.7","hours":24,"reason":"fail2ban"}' ${esc(apiBase())}/ids/block`);
  if (rights.includes('checks')) ex.push(`curl ${auth} -X POST -d '{"comment":"bezig"}' ${esc(apiBase())}/checks/1/ack`);
  modal({
    title: L('Nieuwe API-sleutel'),
    body: `<p>${L('Kopieer deze sleutel nu — hij wordt <b>niet</b> opnieuw getoond.')}</p>
      <div class="new-key"><input id="new-key" readonly value="${esc(key)}"><button type="button" class="btn" id="copy-key">${L('Kopieer')}</button></div>
      <p class="muted small" style="margin-top:1rem">Test:</p>
      <pre class="code">${ex.join('\n\n')}</pre>
      ${rights.includes('read') ? `<p class="muted small" style="margin-top:1rem">${L('Home Assistant-kaart (YAML):')}</p><pre class="code">${esc(haCardYaml(key))}</pre>` : ''}`,
    buttons: [{ label: L('Klaar'), cls: 'primary', submit: true }],
  });
  $('#copy-key').onclick = async () => {
    $('#new-key').select();
    try { await navigator.clipboard.writeText(key); } catch (e) { document.execCommand('copy'); }
    toast(L('Gekopieerd'));
  };
}

async function apiKeyClick(e) {
  const ed = e.target.closest('[data-edit]');
  if (ed) return apiKeyEdit(apiKeys.find((k) => String(k.id) === ed.dataset.edit));
  const b = e.target.closest('[data-del]');
  if (!b || !confirm(L('API-sleutel "{name}" intrekken? Toepassingen die hem gebruiken verliezen direct toegang.', { name: b.dataset.name }))) return;
  try { await api('api_key_delete', { id: b.dataset.del }); toast(L('Sleutel ingetrokken')); loadApiKeys(); }
  catch (ex) { toast(ex.message, 'err'); }
}

function isAdmin() { return document.body.dataset.role === 'admin'; }
/** 1.181.0: de rol gebruiker (niet beheerder, niet alleen-lezen): mag een paar onderdelen van de beheerportal bekijken */
function isUser() { return document.body.dataset.role === 'user'; }

// ---------------------------------------------------------------- Accountmenu (rechtsboven)
function initAccountMenu() {
  const btn = $('#acct-btn'), menu = $('#acct-menu');
  if (!btn) return;
  const open = (on) => {
    menu.hidden = !on;
    btn.setAttribute('aria-expanded', on);
    // 1.150.0: in de zijbalk zweeft het menu erboven (anders werd het afgekapt door de smalle, scrollende zijbalk)
    const side = window.matchMedia('(min-width: 1101px)').matches;
    menu.classList.toggle('floating', on && side);
    if (on && side) {
      const r = btn.getBoundingClientRect();
      menu.style.left = `${Math.max(8, r.left)}px`;
      menu.style.bottom = `${Math.max(8, window.innerHeight - r.top + 6)}px`;
    } else { menu.style.left = ''; menu.style.bottom = ''; }
  };
  btn.onclick = (e) => { e.stopPropagation(); open(menu.hidden); };
  window.addEventListener('resize', () => { if (!menu.hidden) open(false); });
  document.addEventListener('click', (e) => { if (!menu.hidden && !e.target.closest('#acct')) open(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); } });
  menu.addEventListener('click', async (e) => {
    const go = e.target.closest('[data-acct]');
    if (go) { open(false); return showTab(go.dataset.acct === 'settings' ? 'settings/overview' : go.dataset.acct); }
    const ds = e.target.closest('[data-dash-set]');   // 1.154.0: welk dashboard
    if (ds) { open(false); showTab('home'); return ccSetDash(ds.dataset.dashSet); }
    const th = e.target.closest('[data-theme-set]');
    if (th) {
      const v = th.dataset.themeSet;
      if (v === 'auto') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = v;
      try { v === 'auto' ? localStorage.removeItem('rzmon-theme') : localStorage.setItem('rzmon-theme', v); } catch (ex) {}
      if (!prefsLocal) savePref('theme', v);   // 1.135.0: per gebruiker, op elk apparaat
      return markTheme();
    }
    const lg = e.target.closest('[data-lang-set]');
    if (lg && !lg.classList.contains('active')) {
      try { await api('lang_set', { lang: lg.dataset.langSet }); location.reload(); } catch (ex) { toast(ex.message, 'err'); }
    }
  });
  markTheme();
}
// ---------------------------------------------------------------- 1.167.0: zijbalk breder of smaller slepen (per account)
const SIDE_MIN = 180, SIDE_MAX = 380, SIDE_DEF = 232;
function setSideW(w, save) {
  const v = w ? Math.max(SIDE_MIN, Math.min(SIDE_MAX, Math.round(w))) : 0;
  if (v) document.body.style.setProperty('--side-w', v + 'px'); else document.body.style.removeProperty('--side-w');
  if (save) savePref('side_w', v);
  requestAnimationFrame(() => moveTabIndicator(false));
}
function initSideResize() {
  const h = $('#side-resize');
  if (!h) return;
  /* 1.203.0: smaller dan 180 px slepen = de namen vervagen; loslaten onder 150 px = icoontjes. Ingeklapt kun je de rand
     ook pakken: voorbij 130 px klapt de zijbalk weer open, op de breedte waar je loslaat. */
  const SNAP = 150, OPEN = 130, EDGE = 12;
  const cur = () => parseInt(getComputedStyle(document.body).getPropertyValue('--side-w'), 10) || SIDE_DEF;
  const isMini = () => document.body.classList.contains('side-mini');
  let hint = null;
  const showHint = (on, x) => {
    if (on && !hint) { hint = document.createElement('div'); hint.className = 'side-hint'; hint.textContent = L('Loslaten = icoontjes'); document.body.appendChild(hint); }
    if (hint) { hint.hidden = !on; if (on) hint.style.left = (x + EDGE + 22) + 'px'; }
  };
  h.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    h.setPointerCapture(e.pointerId);
    h.classList.add('drag');
    document.body.classList.add('side-dragging');
    let x = isMini() ? 76 : cur();
    const w0 = isMini() ? SIDE_DEF : cur();
    const move = (ev) => {
      x = Math.max(76, Math.min(SIDE_MAX, ev.clientX - EDGE));   // 1.202.0: het paneel staat 12 px van de rand
      if (isMini()) {
        if (x > OPEN) sideSetMini(false, true);
        else return;
      }
      document.body.classList.toggle('side-fade', x < SIDE_MIN);
      showHint(x < SNAP, Math.max(SIDE_MIN, x));
      if (x >= SIDE_MIN) setSideW(x, false); else document.body.style.setProperty('--side-w', Math.round(x) + 'px');
    };
    const up = () => {
      h.removeEventListener('pointermove', move);
      h.classList.remove('drag');
      document.body.classList.remove('side-dragging', 'side-fade');
      showHint(false);
      if (!isMini() && x < SNAP) { sideSetMini(true, true); setSideW(w0, false); }   // de oude breedte terug voor als je weer uitklapt
      else if (!isMini()) setSideW(Math.max(SIDE_MIN, x), true);
    };
    h.addEventListener('pointermove', move);
    h.addEventListener('pointerup', up, { once: true });
    h.addEventListener('pointercancel', up, { once: true });
  });
  h.addEventListener('dblclick', () => { if (isMini()) sideSetMini(false, true); setSideW(0, true); });
  h.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      if (isMini()) { if (e.key === 'ArrowRight') { sideSetMini(false, true); setSideW(SIDE_MIN, true); } return; }
      const n = cur() + (e.key === 'ArrowRight' ? 12 : -12);
      if (n < SIDE_MIN) sideSetMini(true, true); else setSideW(n, true);
    }
    if (e.key === 'Home') { e.preventDefault(); if (isMini()) sideSetMini(false, true); setSideW(0, true); }
  });
}
// ---------------------------------------------------------------- 1.167.0: in Instellingen en Mijn account wordt de zijbalk het menu van die pagina
const SIDE_WIDE = window.matchMedia ? window.matchMedia('(min-width: 1101px)') : { matches: false, addEventListener() {} };
let sideTab = 'home', sideBackTo = 'home';
function sideMode(name = sideTab) {
  sideTab = name;
  if (name !== 'settings' && name !== 'account') sideBackTo = name;
  const box = $('#side-admin');
  if (!box) return;
  const on = (name === 'settings' || name === 'account') && SIDE_WIDE.matches && !document.body.classList.contains('side-mini');
  document.body.classList.toggle('side-admin-on', on);
  box.hidden = !on;
  // Het menu van de instellingen (met zoeken) verhuist naar de zijbalk, en terug als het niet past
  const ss = $('#set-side'), home = $('#tab-settings .set-layout');
  const target = on && name === 'settings' ? $('#side-admin-body') : home;
  if (ss && target && ss.parentElement !== target) target.prepend(ss);
  $('#side-admin-t').textContent = name === 'settings' ? L('Instellingen') : L('Mijn account');
  const acct = $('#side-acct');
  acct.hidden = !(on && name === 'account');
  if (on && name === 'account') sideAcctList();
}
/** Mijn account: de kaarten van de pagina als menu; klik = ernaartoe */
function sideAcctList() {
  const cards = $$('#tab-account .acct-page > .card').filter((c) => !c.hidden && getComputedStyle(c).display !== 'none' && $('h2', c));
  $('#side-acct').innerHTML = cards.map((c, i) => {
    if (!c.id) c.id = 'acct-card-' + i;
    return `<button type="button" class="side-acct-i" data-acct-go="${esc(c.id)}">${esc($('h2', c).textContent.replace(EMOJI_LEAD, '').trim())}</button>`;
  }).join('');
}
function initSideAdmin() {
  if (!$('#side-admin')) return;
  $('#side-back').onclick = () => showTab(sideBackTo || 'home');
  $('#side-acct').onclick = (e) => {
    const b = e.target.closest('[data-acct-go]');
    const c = b && document.getElementById(b.dataset.acctGo);
    if (!c) return;
    $$('#side-acct .side-acct-i').forEach((x) => x.classList.toggle('on', x === b));
    c.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    c.classList.add('acct-flash');
    setTimeout(() => c.classList.remove('acct-flash'), 1200);
  };
  const re = () => sideMode(sideTab);
  if (SIDE_WIDE.addEventListener) SIDE_WIDE.addEventListener('change', re);
}

// ---------------------------------------------------------------- Zijbalk inklappen tot iconen (1.150.0), per gebruiker onthouden
// 1.203.0: in een smal venster (1101–1280 px) vanzelf icoontjes; je eigen keuze blijft bewaard en komt terug als het venster breder wordt
const SIDE_AUTO = window.matchMedia ? window.matchMedia('(min-width: 1101px) and (max-width: 1280px)') : { matches: false, addEventListener() {} };
let sideUserMini = false, sideAutoOff = false;
let sideSetMini = () => {};
function initSideToggle() {
  const b = $('#side-toggle');
  if (!b) return;
  const draw = () => {
    const mini = sideUserMini || (SIDE_AUTO.matches && !sideAutoOff);
    document.body.classList.toggle('side-mini', mini);
    document.body.classList.toggle('side-auto', mini && !sideUserMini);
    b.setAttribute('aria-expanded', String(!mini));
    b.title = mini ? L('Zijbalk uitklappen') : L('Zijbalk inklappen tot iconen');
    $('.side-toggle-lbl', b).textContent = mini ? L('Uitklappen') : L('Inklappen');
    // Ingeklapt: de naam bij het aanwijzen (en voor schermlezers)
    $$('#main-tabs .tab').forEach((t) => {
      const lbl = ($('.tab-lbl', t) || {}).textContent || '';
      if (mini) { t.title = lbl.trim(); t.setAttribute('aria-label', lbl.trim()); } else { t.removeAttribute('title'); t.removeAttribute('aria-label'); }
    });
    const a = $('#acct-btn');
    if (a) a.classList.toggle('mini', mini);
    requestAnimationFrame(() => moveTabIndicator(false));
    if (typeof sideMode === 'function' && $('#side-admin')) sideMode(sideTab);   // 1.167.0: ingeklapt = het gewone menu
  };
  /** Inklappen of uitklappen door jou (knop, slepen, toetsen): onthouden per account */
  sideSetMini = (mini, save) => {
    sideUserMini = mini;
    sideAutoOff = !mini && SIDE_AUTO.matches;   // in een smal venster zelf uitgeklapt = zo laten tot het venster verandert
    if (save) savePref('side', mini ? 'mini' : 'full');
    draw();
  };
  sideUserMini = PREFS.side === 'mini' || document.body.classList.contains('side-mini');
  draw();
  b.onclick = () => sideSetMini(!document.body.classList.contains('side-mini'), true);
  if (SIDE_AUTO.addEventListener) SIDE_AUTO.addEventListener('change', () => { sideAutoOff = false; draw(); });
}
function markTheme() {
  const cur = document.documentElement.dataset.theme || 'auto';
  $$('[data-theme-set]').forEach((b) => b.classList.toggle('active', b.dataset.themeSet === cur));
  markDash();
}
// 1.154.0: knoppen in de kop van het dashboard (keuze, indeling, TV-modus) en de TV-modus bij het laden
function initCC() {
  const home = $('#tab-home');
  if (!home) return;
  const tools = () => { if ($('#hm-tools')) $('#hm-tools').innerHTML = ccTools(); if ($('#hm-live')) $('#hm-live').innerHTML = livePill(); };
  home.addEventListener('click', (e) => {
    const t = e.target.closest('[data-dash-set], [data-cc-edit], [data-cc-tv], [data-cc-reset]');
    if (!t || !home.contains(t)) return;
    if (t.dataset.dashSet) return ccSetDash(t.dataset.dashSet);
    if (t.dataset.ccTv !== undefined) { ccTv(!document.body.classList.contains('tv')); return tools(); }
    if (t.dataset.ccReset !== undefined) savePref('dash_layout', { order: [], hide: [] });
    else ccEdit = !ccEdit;
    tools();
    renderCC();
  });
  $('#cc-tvexit').onclick = () => { ccTv(false); tools(); };
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && document.body.classList.contains('tv') && !$('#modal').open) { ccTv(false); tools(); } });
  let fs = false;
  document.addEventListener('fullscreenchange', () => { if (document.fullscreenElement) fs = true; else if (fs) { fs = false; if (document.body.classList.contains('tv')) { ccTv(false); tools(); } } });
  try { if (localStorage.getItem('rzmon-tv') === '1' && dashMode() === 'cc') ccTv(true); } catch (e) { /* alleen voor nu */ }
  // Live aanvallen in het Command center: elke 20 seconden opnieuw (alleen als het dashboard in beeld is)
  setInterval(() => { if (!document.hidden && dashMode() === 'cc' && !$('#tab-home').hidden && $('#cc-atk')) ccAtkLoad(); }, 20000);
}

// ---------------------------------------------------------------- Tabbladen bovenaan: een vlak dat naar het actieve tabblad schuift
function moveTabIndicator(anim = true) {
  const ind = $('#main-tabs .tab-ind');
  if (!ind) return;
  const t = $('#main-tabs .tab.active');
  ind.classList.toggle('no-anim', !anim);
  // Instellingen of Mijn account: geen tabblad actief, dan ook geen vlak
  ind.style.opacity = t ? '1' : '0';
  // 1.135.0: in de zijbalk (breed scherm) staan de tabbladen onder elkaar: dan schuift het vlak omlaag
  const vert = getComputedStyle($('#main-tabs')).flexDirection === 'column';
  if (t && vert) { ind.style.width = ''; ind.style.height = t.offsetHeight + 'px'; ind.style.transform = `translateY(${t.offsetTop}px)`; }
  else if (t) { ind.style.height = ''; ind.style.width = t.offsetWidth + 'px'; ind.style.transform = `translateX(${t.offsetLeft}px)`; }
}

function initMainTabs() {
  const nav = $('#main-tabs');
  if (!nav) return;
  moveTabIndicator(false);
  let rt;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => moveTabIndicator(false), 100); });
  if (document.fonts) document.fonts.ready.then(() => moveTabIndicator(false));
  // Teller omhoog: het bolletje pulseert even
  $$('.tab-badge', nav).forEach((b) => {
    let last = parseInt(b.textContent, 10) || 0;
    new MutationObserver(() => {
      const n = b.hidden ? 0 : parseInt(b.textContent, 10) || 0;
      if (n > last) { b.classList.remove('pulse'); void b.offsetWidth; b.classList.add('pulse'); }
      last = n;
      moveTabIndicator();   // met een teller wordt het tabblad breder
    }).observe(b, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
  });
}

// ---------------------------------------------------------------- Instellingen: zijbalk met onderdelen
// Elk onderdeel toont een of meer kaarten (data-sk); de kaarten zelf (en hoe ze opslaan) zijn niet veranderd.
// 1.120.0: nieuwe indeling, met lijn-iconen (zelfde stijl als de bovenbalk) en per onderdeel één korte zin.
// [groep, icoon, naam, [[sleutel, titel, kaarten, korte omschrijving, tabbladen?], …]]
const SET_IC = (d) => `<svg class="tab-ic set-ic" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
const SET_NAV = [
  ['overzicht', SET_IC('<path d="M3 12h4l3 8 4-16 3 8h4"/>'), L('Overzicht'), [
    ['overview', L('Status'), ['overview'], L('Wat er nog aandacht nodig heeft, met een knop om het te regelen.')],
    ['features', L('Functies'), ['features'], L('Alle functies van RzSense, met wat er aan staat en waar je het instelt.')],
    ['conn', L('Achtergrondtaken'), ['conn'], L('Verbindingen en taken die RzSense elke minuut uitvoert.')]]],
  ['checks', SET_IC('<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 17.5h7M17.5 14v7"/>'), L('Monitoring'), [
    ['allchecks', L('Alle checks'), ['allchecks'], L('Alle checks van alle servers in één lijst, ook samen bewerken.')],
    ['templates', L('Sjablonen'), ['templates'], L('Vaste sets checks voor een nieuwe server.')],
    ['internet', L('Internet'), ['internet'], L('Snelheid, ping en responstijd van je internetlijn.')],
    ['logs', L('Logs'), ['logs'], L('Logs van je servers verzamelen: hoe vaak, hoe lang bewaren, hoeveel, maskeren en wat er binnenkomt.')],
    ['advice', L('Advies'), ['smart', 'advice', 'smartmore', 'cleanup'], L('Slimme drempels, voorstellen en checks om op te schonen.'),
      [['drempels', L('Slimme drempels'), ['smart']], ['voorstellen', L('Voorstellen'), ['advice', 'smartmore']], ['opschonen', L('Opschonen'), ['cleanup']]]]]],
  ['meldingen', SET_IC('<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'), L('Meldingen'), [
    ['channels', L('Kanalen'), ['pushover', 'ntfy', 'email', 'webhook', 'chain'], L('Waar meldingen heen gaan: Pushover, ntfy, e-mail en webhooks.')],
    ['quiet', L('Stille uren'), ['quiet'], L('Profielen met een naam, gekoppeld aan groepen, servers en checks.')],
    ['rules', L('Wanneer melden'), ['confirm', 'notify', 'alarm'], L('Meldregels, stille uren en wanneer een probleem een melding wordt.')],
    ['sysinfo', L('Systeemmeldingen'), ['sysinfo'], L('Meldingen over RzSense zelf, zoals updates en certificaten.')]]],
  ['ids', SET_IC('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>'), L('IDS'), [
    ['ids', L('Algemeen'), ['ids'], L('Aan of uit, leren of blokkeren, brute force en honeypot.')],
    ['ids_prot', L('Beschermingen'), ['ids_prot'], L('Wat het IDS doet bij een aanval: per bescherming aan of uit, met de drempels.')],
    ['ids_edr', L('Endpoint-bewaking'), ['ids_edr', 'ids_rules'], L('Processen, bestanden en netwerk op je servers; afwijkingen en aanvalsketens.'),
      [['bewaking', L('Bewaking'), ['ids_edr']], ['regels', L('Regels en uitzonderingen'), ['ids_rules']]]],
    ['ids_ti', L('Dreigingsinformatie'), ['ids_ti'], L('Bekende aanvallers en malware opzoeken, en DNS en Suricata.')],
    ['ids_ransom', L('Ransomware'), ['ransom'], L('Mappen bewaken op versleutelen, met lokaasbestanden, snapshot en stoppen.')],
    ['ids_attack', L('Netwerk en aanvallers'), ['ids_attack', 'whitelist'], L('Blocklists, landen, uitgaand verkeer, de logs van webdiensten en de whitelist.'),
      [['aanvallers', L('Aanvallers en verkeer'), ['ids_attack']], ['whitelist', L('Whitelist'), ['whitelist']]]],
    ['ids_servers', L('Per server'), ['ids_servers'], L('Welke servers het IDS gebruiken, en wat ze bewaken.')]]],
  ['beveiliging', SET_IC('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'), L('Beveiliging'), [
    ['access', L('Inloggen'), ['access'], L('Lokaal netwerk, uitloggen en blokkeren na mislukte pogingen.')],
    ['outside', L('Beheer via https'), ['https', 'outside'], L('Veilig beheren van buiten je netwerk.')],
    ['users', L('Gebruikers'), ['users', 'confirmpw'], L('Accounts, rollen, tweestapsverificatie en bevestigen bij acties.')],
    ['vulns', L('Kwetsbaarheden'), ['vulns', 'eol', 'cvebuzz'], L('Bekende lekken in de software op je servers, en software die geen updates meer krijgt.')],
    ['mailsec', L('E-mailbeveiliging'), ['mailsec'], L('SPF, DKIM en DMARC van je domeinen: kan iemand mailen namens jou?')],
    ['hibp', L('Datalekken'), ['hibp'], L('Staan je e-mailadressen of domein in een datalek?')],
    ['netscan', L('Netwerkscan'), ['netscan'], L('Nieuwe apparaten en open poorten in je netwerk.')]]],
  ['agents', SET_IC('<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'), L('Agents'), [
    ['agents', L('Overzicht'), ['agents'], L('Alle agents: status, versie, verbinding en wat aandacht nodig heeft.')],
    ['agentdef', L('Standaardinstellingen'), ['agentdef', 'enc'], L('Wat voor alle agents geldt: bijwerken, ritme, versleuteling en certificaat.')],
    ['commands', L('Commando-checks'), ['commands'], L('Eigen commando\'s die agents mogen uitvoeren.')],
    ['actlog', L('Logboek'), ['actlog'], L('Per server wat de agent, RzSense en beheerders deden, en wat er veranderde.')]]],
  ['rapporten', SET_IC('<path d="M4 4h16v16H4z"/><path d="M4 8l8 5 8-5"/>'), L('Rapporten'), [
    ['reports', L('Rapporten'), ['reports'], L('Alle overzichten die RzSense per e-mail of push verstuurt, en het Maandoverzicht.')]]],
  ['koppelingen', SET_IC('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'), L('Koppelingen'), [
    ['unifi', 'UniFi', ['unifi'], L('Namen, wifi, verkeer en weren van je apparaten uit je UniFi-controller.')],
    ['adguard', 'AdGuard Home', ['adguard'], L('Namen, DNS-verzoeken en DNS-blokkeren uit AdGuard Home, en de DNS-controle van het IDS.')],
    ['snmp', 'SNMP', ['snmp'], L('Eén SNMP-instelling voor al je apparaten, met per apparaat een uitzondering.')],
    ['mqtt', L('Home Assistant (MQTT)'), ['mqtt'], L('Sensoren en meldingen in Home Assistant.')],
    ['ai', L('AI-advies'), ['ai'], L('Advies van Claude, ChatGPT, Gemini, Grok of Ollama bij storingen.')],
    ['api', L('REST API'), ['api'], L('API-sleutels voor andere toepassingen.')],
    ['statuspage', L('Statuspagina'), ['statuspage'], L('Een openbare pagina met de status, via een geheime link.')]]],
  ['systeem', SET_IC('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'), L('Systeem'), [
    ['looks', L('Weergave'), ['looks'], L('De standaard weergave voor accounts die zelf nog niets kozen.')],
    ['update', L('Bijwerken'), ['update'], L('Nieuwe versies van RzSense, met of zonder automatisch bijwerken.')],
    ['backup', L('Back-up'), ['backup'], L('Automatische back-ups en terugzetten.')],
    ['db', L('Database en server'), ['dbconn', 'db', 'storage', 'dbuse', 'server', 'syscheck', 'cron'], L('Databaseverbinding, bewaartermijnen, tijdzone, PHP en de systeemcheck.'),
      [['database', L('Database'), ['dbconn', 'db', 'storage', 'dbuse']], ['server', L('Server'), ['server', 'syscheck', 'cron']]]],
    ['perf', L('Prestaties en fouten'), ['perf', 'errors'], L('Hoe snel RzSense is, hoe lang een cron-ronde duurt, de traagste checks en PHP-fouten.')],
    ['changelog', L('Wijzigingslogboek'), ['changelog'], L('Wie wat wijzigde, met terugdraaien.')],
    ['transfer', L('Exporteren en importeren'), ['transfer'], L('Servers, checks en instellingen overzetten.')]]],
];
/* 1.181.0: de rol gebruiker ziet alleen deze onderdelen, om te bekijken (de rest is voor beheerders) */
const SET_USER = { overview: null, features: null, reports: null, advice: ['smart', 'advice'], changelog: null, actlog: null };
if (document.body.dataset.role === 'user') {
  for (let gi = SET_NAV.length - 1; gi >= 0; gi--) {
    const subs = SET_NAV[gi][3];
    for (let si = subs.length - 1; si >= 0; si--) {
      const k = subs[si][0];
      if (!(k in SET_USER)) { subs.splice(si, 1); continue; }
      if (SET_USER[k]) {
        subs[si][2] = subs[si][2].filter((c) => SET_USER[k].includes(c));
        if (subs[si][4]) subs[si][4] = subs[si][4].map((t) => [t[0], t[1], t[2].filter((c) => SET_USER[k].includes(c))]).filter((t) => t[2].length);
      }
    }
    if (!subs.length) SET_NAV.splice(gi, 1);
  }
}
/** Oude adressen (vóór 1.92.0) → nieuwe plek */
const SET_ALIAS = { netlinks: 'unifi', ids_rules: 'ids_edr', whitelist: 'ids_attack', enc: 'agentdef', https: 'outside', setup: 'db', syscheck: 'db', smart: 'advice', cleanup: 'advice', alarm: 'rules', notify: 'rules', server: 'db' };   // 1.120.0: samengevoegde onderdelen
const SET_TAB_OF = { ids_rules: 'regels', whitelist: 'whitelist', smart: 'drempels', cleanup: 'opschonen', syscheck: 'server', setup: 'server' };   // oude adressen → tabblad op de pagina Advies
let setTab = {};   // per onderdeel met tabbladen: het gekozen tabblad
let setSection = (() => { try { return localStorage.getItem('rzmon-set-section') || 'overview'; } catch (e) { return 'overview'; } })();
let setHealthAt = 0;
let setHealth = {};   // onderdeel → slechtste status uit "Hoe staat het ervoor?" (voor de bolletjes in de zijbalk)
let setHealthWhy = {};   // 1.131.2: onderdeel → de punten zelf (tekst bij het bolletje)
let setHealthItems = {};   // 1.133.0: onderdeel → de oranje/rode punten (blok "Aandacht nodig" bovenaan de pagina)

function setFind(id) {
  for (const [g, icon, label, subs] of SET_NAV) for (const s of subs) if (s[0] === id) return { g, icon, group: label, sub: s };
  return null;
}

function showSettingsSection(id, focusEl) {
  xpClose();
  if (SET_TAB_OF[id]) setTab[SET_ALIAS[id]] = SET_TAB_OF[id];
  // Gevonden via zoeken in een kaart op een ander tabblad: dat tabblad kiezen
  const fk = focusEl && focusEl.closest('[data-sk]') && focusEl.closest('[data-sk]').dataset.sk;
  const f = setFind(SET_ALIAS[id] || id) || setFind('overview');
  setSection = f.sub[0];
  try { localStorage.setItem('rzmon-set-section', setSection); } catch (e) {}
  // 1.120.0: tabbladen binnen een onderdeel (bv. Advies: drempels, voorstellen, opschonen)
  const tabs = f.sub[4] || null;
  if (tabs && fk) { const t = tabs.find((x) => x[2].includes(fk)); if (t) setTab[setSection] = t[0]; }
  const tab = tabs ? (tabs.find((x) => x[0] === setTab[setSection]) || tabs[0]) : null;
  $('#set-tabs').hidden = !tabs;
  $('#set-tabs').innerHTML = tabs ? tabs.map(([k, l]) => `<button type="button" class="seg-btn ${k === tab[0] ? 'active' : ''}" data-set-tab="${k}" role="tab">${esc(l)}</button>`).join('') : '';
  const cards = tab ? tab[2] : f.sub[2];
  $$('#tab-settings [data-sk]').forEach((c) => c.classList.toggle('set-off', !cards.includes(c.dataset.sk)));
  if (pgEdit === 'set') pgEdit = null;
  pgApply('set');   // 1.160.0: indeling van dit onderdeel (lay-out CC)
  pgTools('set');
  // De knop "Instellingen opslaan" alleen bij onderdelen uit dat formulier
  const inForm = cards.some((k) => { const c = $(`#tab-settings [data-sk="${k}"]`); return c && c.closest('#settings-form') && !c.hasAttribute('data-nodirty'); });
  $('#settings-form').classList.toggle('set-noform', !inForm);
  $('#set-title').textContent = f.sub[1];
  $('#set-lead').textContent = (!isAdmin() ? '👁 ' + L('Alleen bekijken') + ' · ' : '') + (f.sub[3] || '');
  if (!isAdmin()) {
    $('#settings-form').classList.add('set-noform');
    $$('#tab-settings [data-sk] input, #tab-settings [data-sk] select, #tab-settings [data-sk] textarea').forEach((x) => { x.disabled = true; });
  }
  $('#set-crumb').textContent = `${L('Instellingen')} › ${f.group}`;
  crumbs();   // 1.199.0
  // Eén kaart op de pagina: zijn titel is gelijk aan de paginatitel, dus weg (tenzij er knoppen in staan)
  const shown = $$('#tab-settings [data-sk]').filter((c) => !c.classList.contains('set-off'));
  $$('#tab-settings [data-sk] > h2').forEach((h) => h.classList.toggle('set-dup', shown.length === 1 && shown[0] === h.parentElement && !h.querySelector('button, input, a')));
  renderSettingsNav();
  // 1.189.1: in de zijbalk het gekozen onderdeel in beeld houden
  if (document.body.classList.contains('side-admin-on')) { const a = $('#side-admin-body .set-nav-subs .active') || $('#side-admin-body .set-nav-head.cur'); if (a) a.scrollIntoView({ block: 'nearest' }); }
  $('#set-mselect').innerHTML = SET_NAV.map(([g, icon, label, subs]) => `<optgroup label="${esc(label)}">${subs.map(([k, l]) => `<option value="${k}" ${k === setSection ? 'selected' : ''}>${esc(l)}</option>`).join('')}</optgroup>`).join('');
  history.replaceState(null, '', '#settings/' + setSection);
  renderSetAttn();
  if (setSection === 'overview' || Date.now() - setHealthAt > 60000) loadSettingsHealth();
  if (setSection === 'conn') loadConn();
  if (setSection === 'templates') loadTemplates();
  if (setSection === 'allchecks') loadAllChecks();
  if (setSection === 'statuspage') loadStatusPage();
  if (setSection === 'internet') loadInet();
  if (setSection === 'logs') loadLogsOverview();   // 1.210.0
  if (setSection === 'ai') loadAiSettings();   // 1.171.0
  if (setSection === 'snmp') loadSnmpGlobal();   // 1.178.0
  if (setSection === 'reports') loadReports();   // 1.180.0
  if (setSection === 'ids_prot') loadProtSettings();   // 1.181.0
  if (setSection === 'features') loadFeatures();   // 1.182.0
  if (setSection === 'quiet') loadQuiet();   // 1.189.0
  if (setSection === 'advice') { if (cards.includes('smart')) loadSmartProposals(); if (cards.includes('advice')) loadAdvice(); if (cards.includes('cleanup')) loadCleanup(); }
  if (setSection === 'changelog') loadChangeLog();
  if (setSection === 'actlog') loadActLog();   // 1.226.0
  if (setSection === 'ids_ransom') loadRansomSet();   // 1.228.0
  if (setSection === 'ids_edr' && cards.includes('ids_rules')) { ruleWhere = 'set'; loadEdrRules(); }   // 1.162.0
  if (setSection === 'ids_attack' && cards.includes('whitelist')) loadBlocks();
  if (setSection === 'db' || setSection === 'perf') loadSyscheck();   // 1.151.0: systeemcheck, cron, database, prestaties
  if (setSection === 'db') loadSetupInfo();
  if (focusEl) {
    // Gevonden via zoeken: ingeklapte "Geavanceerd" openen, ernaartoe en even laten oplichten
    for (let d = focusEl.closest('details'); d; d = d.parentElement.closest('details')) d.open = true;
    focusEl.scrollIntoView({ block: 'center' });
    focusEl.classList.remove('set-hit');
    void focusEl.offsetWidth;
    focusEl.classList.add('set-hit');
    const ctl = focusEl.matches('input, select, textarea') ? focusEl : focusEl.querySelector('input:not([type=hidden]), select, textarea');
    if (ctl) ctl.focus({ preventScroll: true });
  } else window.scrollTo(0, 0);
  refreshClamps();
}

/** 1.133.0: bovenaan een onderdeel met een 🟠/🔴 bolletje: wat er mis is en (als het kan) de knop om het op te lossen */
function renderSetAttn() {
  const box = $('#set-attn');
  if (!box) return;
  const items = setSection === 'overview' ? [] : setHealthItems[setSection] || [];
  renderCardDots(items);
  box.hidden = !items.length;
  if (!items.length) { box.innerHTML = ''; return; }
  box.className = `set-attn ${items.some((i) => i.status === 'bad') ? 'bad' : 'warn'}`;
  box.innerHTML = `<h3>${items.length === 1 ? L('Aandacht nodig') : L('Aandacht nodig ({n} punten)', { n: items.length })}</h3>
    <ul>${items.map((i, n) => `<li class="${i.status}"><span class="set-attn-ic">${i.status === 'bad' ? '🔴' : '🟠'}</span><div><b>${cardOf(i) ? `<a href="#" data-attn-go="${n}">${esc(i.title)}</a>` : esc(i.title)}</b>${i.detail ? ` <span class="set-attn-txt">${esc(i.detail)}</span>` : ''}
      ${i.code || i.act ? `<div class="set-attn-do">${i.code ? copyBox(`attn-code-${n}`, i.code) : ''}${i.act ? `<button type="button" class="btn small-btn primary" data-attn-act="${esc(i.act)}">${esc(i.label || L('Regelen'))}</button>` : ''}</div>` : ''}</div></li>`).join('')}</ul>`;
  wireCopy(box);
  $$('[data-attn-act]', box).forEach((b) => (b.onclick = () => healthAction(b.dataset.attnAct, b)));
  // Klik op een punt: naar de kaart waar het over gaat, die even oplicht
  $$('[data-attn-go]', box).forEach((a) => (a.onclick = (e) => {
    e.preventDefault();
    const c = cardOf(items[+a.dataset.attnGo]);
    if (!c) return;
    c.scrollIntoView({ block: 'start', behavior: 'smooth' });
    c.classList.remove('set-hit');
    void c.offsetWidth;
    c.classList.add('set-hit');
  }));
}

/** 1.148.0: de kaart waar een punt over gaat (de kaart met dezelfde sleutel, anders de eerste kaart van het onderdeel) */
function cardOf(it) {
  const f = setFind(setSection);
  if (!f) return null;
  const keys = f.sub[2];
  const k = keys.includes(it.card) ? it.card : keys[0];
  const c = $(`#tab-settings [data-sk="${k}"]`);
  return c && !c.hidden && c.offsetParent ? c : null;
}

/** 1.148.0: een 🟠/🔴 bolletje bij de titel van elke kaart met een aandachtspunt */
function renderCardDots(items) {
  $$('#tab-settings .set-hdot').forEach((d) => d.remove());
  const per = new Map();
  items.forEach((it) => {
    const c = cardOf(it);
    if (!c) return;
    const p = per.get(c) || { st: 'warn', why: [] };
    if (it.status === 'bad') p.st = 'bad';
    p.why.push(`${it.status === 'bad' ? '🔴' : '🟠'} ${it.title}: ${it.detail || ''}`);
    per.set(c, p);
  });
  per.forEach((p, c) => {
    const h = [...c.querySelectorAll('h2, h3')].find((x) => !x.classList.contains('set-dup') && !x.hidden && x.offsetParent);
    if (!h) return;
    h.insertAdjacentHTML('beforeend', dotFor(p.st, p.why).replace('class="set-dot', 'class="set-dot set-hdot'));
  });
}

const dotFor = (st, why = []) => (st === 'bad' || st === 'warn'
  ? `<i class="set-dot ${st}" title="${esc((why.length ? why : [st === 'bad' ? L('Hier moet iets gebeuren') : L('Aandachtspunt')]).join('\n') + '\n\n' + L('Alles staat bij Overzicht → Status'))}"></i>` : '');

function renderSettingsNav() {
  const q = setSearchNorm($('#set-search').value);
  const side = $('#set-side');
  side.classList.toggle('searching', !!q);
  if (q) {
    const hits = setSearch(q);
    $('#set-navlist').innerHTML = hits.length ? `<div class="set-results">${hits.map((h, i) => `<button type="button" data-set-go="${h.sub[0]}" data-hit="${i}" class="${i === 0 ? 'first' : ''}">
        <b>${h.icon} ${esc(h.sub[1])}</b>${h.label ? `<small>${esc(h.label)}</small>` : ''}</button>`).join('')}</div>`
      : `<p class="muted small set-none">${L('Niets gevonden')}</p>`;
    side._hits = hits;
    return;
  }
  const f = setFind(setSection);
  $('#set-navlist').innerHTML = SET_NAV.map(([g, icon, label, subs]) => {
    const worst = subs.map(([k]) => setHealth[k]).find((s) => s === 'bad') || subs.map(([k]) => setHealth[k]).find((s) => s === 'warn');
    const why = subs.flatMap(([k]) => setHealthWhy[k] || []);
    return `<div class="set-nav-group ${g === f.g ? 'open' : ''}">
      <button type="button" data-set-go="${subs[0][0]}" class="set-nav-head ${g === f.g ? 'cur' : ''}"><span>${icon}</span> ${esc(label)}${subs.length === 1 || g !== f.g ? dotFor(worst, why) : ''}</button>
      <div class="set-nav-subs">${subs.length > 1 ? subs.map(([k, l]) => `<button type="button" data-set-go="${k}" class="${k === setSection ? 'active' : ''}">${esc(l)}${dotFor(setHealth[k], setHealthWhy[k])}</button>`).join('') : ''}</div></div>`;
  }).join('');
}

// ---- Zoeken: op de namen van de onderdelen en op alles wat in de kaarten staat (koppen, velden, schakelaars)
const setSearchNorm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
function setSearch(q) {
  const words = q.split(' ');
  const has = (t) => words.every((w) => t.includes(w));
  const out = [];
  for (const [, icon, group, subs] of SET_NAV) {
    for (const sub of subs) {
      if (has(setSearchNorm(`${group} ${sub[1]}`))) { out.push({ icon, sub, label: '', el: null }); continue; }
      let found = null;
      for (const k of sub[2]) {
        const card = $(`#tab-settings [data-sk="${k}"]`);
        if (!card || card.closest('[hidden]:not(#tab-settings)')) continue;
        for (const el of $$('h2, h3, label, summary, th, .lock-row, .btn', card)) {
          const own = setSearchNorm(el.textContent + ' ' + (el.querySelector('[data-tip]')?.dataset.tip || '') + ' ' + (el.querySelector('input, textarea')?.name || ''));
          if (own && has(own)) { found = el; break; }
        }
        if (found) break;
      }
      if (found) out.push({ icon, sub, label: found.textContent.replace(/ⓘ/g, '').replace(/\s+/g, ' ').trim().slice(0, 70), el: found });
    }
  }
  return out;
}

// ---- Hoe staat het ervoor?
/* 1.193.0: status bevestigen per soort check (tabel in de kaart Status bevestigen) */
const CONF_SLOW = ['disk', 'ssl', 'domain', 'heartbeat', 'path', 'smart', 'reboot'];
let confTypeVals = {};
function confTypes(vals) {
  confTypeVals = vals && typeof vals === 'object' ? vals : {};
  const el = $('#conf-types');
  if (!el) return;
  const used = new Set((state && state.hosts ? state.hosts.flatMap((h) => h.checks.map((c) => c.type)) : []).concat(CONF_SLOW, Object.keys(confTypeVals)));
  const types = [...used].filter((t) => TYPES[t]).sort((a, b) => String(TYPES[a].label).localeCompare(String(TYPES[b].label)));
  const n = (t) => (state && state.hosts ? state.hosts.reduce((s, h) => s + h.checks.filter((c) => c.type === t).length, 0) : 0);
  const v = (t, k) => esc((confTypeVals[t] || {})[k] || '');
  const cnt = (k) => (k === 1 ? L('1 check') : k ? L('{n} checks', { n: k }) : '');
  el.innerHTML = `<p class="ct-legend muted small">✘ ${L('= warning of critical pas na … mislukte controles')} · ✔ ${L('= weer OK pas na … goede controles')}</p>`
    + types.map((t) => `<div class="ct-row${(confTypeVals[t] || {}).f || (confTypeVals[t] || {}).o ? ' own' : ''}"><span class="ct-n">${esc(TYPES[t].label)} <span class="muted small">${cnt(n(t))}</span></span>
      <label class="ct-i" title="${esc(L('Warning of critical pas na … mislukte controles'))}">✘<input type="number" min="1" max="100" data-ct="${esc(t)}" data-k="f" value="${v(t, 'f')}" placeholder="${esc(String(state && state.confirm_fails || '–'))}"></label>
      <label class="ct-i" title="${esc(L('Weer OK pas na … goede controles'))}">✔<input type="number" min="1" max="100" data-ct="${esc(t)}" data-k="o" value="${v(t, 'o')}" placeholder="${esc(String(state && state.confirm_ok || '–'))}"></label></div>`).join('');
  const f = $('#settings-form');
  if (f && f.confirm_types) f.confirm_types.value = JSON.stringify(confTypeVals);
}
document.addEventListener('change', (e) => {
  const i = e.target.closest && e.target.closest('#conf-types input[data-ct]');
  if (!i) return;
  const t = i.dataset.ct, k = i.dataset.k, val = i.value.trim();
  confTypeVals[t] = Object.assign({ f: '', o: '' }, confTypeVals[t] || {}, { [k]: val === '' ? '' : String(Math.max(1, Math.min(100, +val || 1))) });
  if (!confTypeVals[t].f && !confTypeVals[t].o) delete confTypeVals[t];
  i.closest('.ct-row').classList.toggle('own', !!confTypeVals[t]);
  $('#settings-form').confirm_types.value = JSON.stringify(confTypeVals);
  markSettingsDirty(true, i);
});
document.addEventListener('click', (e) => {
  if (!e.target.closest || !e.target.closest('#conf-advice')) return;
  const rows = [
    [L('Critical'), L('direct melden')], [L('Warning'), L('melden na 10 minuten')], [L('Onbekend'), L('melden na 15 minuten')], [L('Hersteld'), L('direct melden')],
    [L('Melden na … mislukte checks'), '1'],
    [L('Bevestigen = 1 (meteen geldig)'), CONF_SLOW.map((t) => (TYPES[t] ? TYPES[t].label : t)).join(', ')],
  ];
  modal({ title: '✨ ' + L('Advies toepassen'), body: `<p>${L('Dit wordt ingesteld:')}</p><table class="conf-adv-t">${rows.map(([a, b]) => `<tr><td>${esc(a)}</td><td><b>${esc(b)}</b></td></tr>`).join('')}</table>
    <p class="muted small">${L('Herhalen en kanalen blijven zoals ze zijn. Checks met een eigen waarde houden die. Terugdraaien kan in het wijzigingslogboek.')}</p>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✨ ' + L('Toepassen'), cls: 'primary', submit: true }],
    onSubmit: async () => { await api('notify_advice_apply', {}); toast(L('Advies toegepast')); loadSettings(); } });
});

/** 1.193.0: waar staat deze check? Ruwe meting → bevestigen → melden → meldregel → wanneer de melding komt */
function ciFlow(f, c) {
  if (!f || !+c.enabled) return '';
  const W = (s) => (s === null || s === undefined ? '–' : STATUS_WORD[s] || '?');
  const dots = (n, need) => `<span class="cf-dots">${Array.from({ length: Math.min(need, 10) }, (_, i) => `<i class="${i < n ? 'on' : ''}"></i>`).join('')}</span>`;
  const target = f.pend !== null ? f.pend : f.status;
  const step = [];
  step.push(`<span class="cf-s s${f.raw ?? 3}" title="${esc(L('De laatste meting zelf, vóór bevestigen'))}"><small>${L('Meting')}</small><b>${esc(W(f.raw))}</b></span>`);
  step.push(f.pend !== null
    ? `<span class="cf-s busy" title="${esc(L('Wordt {s} na {need} controles achter elkaar', { s: W(f.pend), need: f.need }))}"><small>${L('Bevestigen')}</small>${dots(f.pend_n, f.need)}<b>${f.pend_n}/${f.need}</b></span>`
    : `<span class="cf-s done"><small>${L('Bevestigd')}</small><b>${esc(W(f.status))}</b></span>`);
  if (target !== 0) {
    const sN = f.pend !== null ? 0 : f.streak;
    step.push(`<span class="cf-s${sN >= f.fails_need ? ' done' : ' busy'}" title="${esc(L('Melden na {n} mislukte checks achter elkaar', { n: f.fails_need }))}"><small>${L('Melden na N')}</small>${f.fails_need > 1 ? dots(sN, f.fails_need) : ''}<b>${Math.min(sN, f.fails_need)}/${f.fails_need}</b></span>`);
    const r = f.rule;
    step.push(`<span class="cf-s"><small>${L('Meldregel')}</small><b>${!r ? '–' : r.mode === 'never' ? L('nooit') : r.mode === 'now' ? L('direct') : L('na {n} min', { n: r.min })}</b></span>`);
  }
  let end;
  if (target === 0 && f.pend === null) end = `<span class="cf-end ok">✔ ${L('in orde')}</span>`;
  else if (+f.notified === target && f.notified_at) end = `<span class="cf-end sent">🔔 ${L('gemeld om {t}', { t: esc(whenText(f.notified_at)) })}</span>`;
  else if (c.maintenance) end = `<span class="cf-end">🔧 ${L('onderhoud: geen melding')}</span>`;
  else if (f.why === 'never') end = `<span class="cf-end">🔕 ${L('geen melding (meldregel staat op nooit)')}</span>`;
  else if (f.why === 'acked') end = `<span class="cf-end">✔ ${L('bevestigd: geen melding')}</span>`;
  else if (f.eta !== null && f.eta !== undefined) end = `<span class="cf-end busy">⏳ ${target === 0 ? L('herstelmelding over ±{d}', { d: durText(f.eta) }) : f.eta <= 0 ? L('melding bij de volgende controle') : L('melding over ±{d}', { d: durText(f.eta) })}</span>`;
  else end = `<span class="cf-end">${L('geen melding')}</span>`;
  return `<div class="ci2-flow" aria-label="${esc(L('Van meting tot melding'))}">${step.join('<span class="cf-arr" aria-hidden="true">›</span>')}${end}</div>`;
}

async function loadSettingsHealth() {
  const box = $('#set-overview');
  if (!box.innerHTML) box.innerHTML = `<p class="muted">${L('Laden…')}</p>`;
  let items;
  try { items = (await api('settings_health')).items; } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const https = location.protocol === 'https:';
  items.push({ key: 'https_client', status: https ? 'ok' : 'warn', title: 'HTTPS', detail: https ? L('de verbinding met RzSense is versleuteld') : L('je opent RzSense via http: wachtwoorden gaan onversleuteld over het netwerk'), section: 'access' });
  const rank = { bad: 0, warn: 1, info: 2, ok: 3 };
  items.sort((a, b) => rank[a.status] - rank[b.status]);
  setHealth = {};
  setHealthWhy = {};
  setHealthItems = {};
  setHealthAt = Date.now();
  for (const it of items) {
    it.card = { db_pool: 'dbuse', db_growth: 'dbuse' }[it.key] || it.section;   // 1.148.0: de oude sleutel is vaak precies de kaart (bv. enc, https)
    it.section = SET_ALIAS[it.section] || it.section;
    if (rank[it.status] < rank[setHealth[it.section] || 'ok']) setHealth[it.section] = it.status;
    if (it.status === 'bad' || it.status === 'warn') {
      (setHealthWhy[it.section] ||= []).push(`${it.status === 'bad' ? '🔴' : '🟠'} ${it.title}: ${it.detail || ''}`);
      (setHealthItems[it.section] ||= []).push({ ...it });
    }
  }
  renderSetAttn();
  // 1.122.0: punten die bij elkaar horen als één regel (de losse punten eronder)
  items = ovGroup(items, rank);
  const counted = items.filter((i) => i.status !== 'info');
  const score = counted.length ? Math.round(counted.reduce((n, i) => n + (i.status === 'ok' ? 1 : i.status === 'warn' ? 0.5 : 0), 0) / counted.length * 100) : 100;
  const todo = items.filter((i) => i.status === 'bad' || i.status === 'warn').length;
  const cls = score >= 90 ? 'good' : score >= 60 ? 'warn' : 'bad';
  const icon = { ok: '✔', warn: '!', bad: '✘', info: 'i' };
  box.innerHTML = `<div class="ov-head"><div class="ov-score ${cls}" style="--p:${score}"><span><b>${score}<small>%</small></b></span></div>
      <div><h2>${todo ? L('{n} aandachtspunten', { n: todo }) : L('Alles staat goed ingesteld')}</h2>
      <p class="muted small">${L('Klik op een punt om het meteen te regelen. Grijze punten zijn optioneel en tellen niet mee.')}</p></div></div>
    <div class="ov-list">${items.map((i, n) => `<div class="ov-cell"><button type="button" class="ov-item ${i.status}" data-set-go="${esc(i.section)}">
      <span class="ov-ic">${icon[i.status]}</span><span class="ov-txt"><b>${esc(i.title)}</b><small>${esc(i.detail)}</small></span><span class="ov-go">›</span></button>
      ${i.subs ? `<ul class="ov-subs">${i.subs.filter((s) => s.status !== 'ok').map((s) => `<li class="${s.status}"><span class="ov-ic">${icon[s.status]}</span> <b>${esc(s.title)}</b> <span class="muted">${esc(s.detail)}</span></li>`).join('')}</ul>` : ''}
      ${i.code || i.acts ? `<div class="ov-extra">${i.code ? copyBox(`ov-code-${n}`, i.code) : ''}${(i.acts || []).map((a) => `<button type="button" class="btn small-btn primary need-admin" data-ov-act="${esc(a[0])}">${esc(a[1] || L('Regelen'))}</button>`).join('')}</div>` : ''}</div>`).join('')}</div>`;
  wireCopy(box);
  $$('[data-ov-act]', box).forEach((b) => (b.onclick = () => healthAction(b.dataset.ovAct, b)));
  renderSettingsNav();
}

/**
 * IDS-schakelaars op één plek (1.122.0): aan/uit staat op de kaarten van IDS → Bescherming. De drempels van een kaart
 * staan achter ⚙️ (zelfde velden als in de instellingen, uit het instellingenformulier gekopieerd).
 */
const PROT_CFG = {
  bruteforce: ['ids_fail_count', 'ids_fail_minutes', 'ids_block_hours', 'ids_repeat_days', 'ids_block_shared'],
  decoy: ['ids_decoy_users', 'ids_decoy_port'],
  anomaly: ['edr_learn_days', 'edr_anom_min'],
  chain: ['edr_chain_min'], chain_act: ['edr_chain_min'],
  ti_block: ['ti_block_score'], ti_report: ['ti_abuseipdb_key'],
  web_attack: ['wl_404_n', 'wl_auth_n', 'wl_nohost_n', 'wl_ha_n', 'wl_vw_n', 'wl_minutes'],
  outbound: ['ids_outbound_country'],
  blocklists: ['ids_geo', 'ids_geo_allowed'],
};
const PROT_KEYS = ['ti_escalate', 'ids_blocklists', 'ti_cve', 'edr_block', 'weblog_block', 'ti_close_benign', 'edr_kill', 'edr_isolate', 'edr_yara', 'ids_persist', 'edr_sigma', 'ids_harden',
  'ti_block', 'ids_outbound', 'suricata_block', 'dns_on', 'edr_anom', 'edr_chain_act', 'edr_chain', 'ti_report'];
async function protCfgDialog(id) {
  const names = PROT_CFG[id] || [];
  let vals;
  try { vals = await api('settings'); } catch (ex) { return toast(ex.message, 'err'); }
  // De velden zoals in de instellingen: hun label (of regel) kopiëren, zonder dubbele
  const seen = new Set();
  const parts = [];
  names.forEach((n) => {
    const el = $(`#settings-form [name="${n}"]`);
    const box = el && (el.closest('.lock-row') || el.closest('label'));
    if (!box || seen.has(box)) return;
    seen.add(box);
    const c = box.cloneNode(true);
    $$('.saved-flag', c).forEach((x) => x.remove());
    $$('[data-pol]', c).forEach((x) => x.removeAttribute('data-pol'));
    parts.push(c.outerHTML);
  });
  const card = ((protData && protData.cards) || []).find((c) => c.id === id) || {};
  modal({
    title: '⚙️ ' + (card.title || L('Drempels')),
    body: `<div class="prot-cfg-form">${parts.join('')}</div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d, form) => {
      const diff = {};
      names.forEach((n) => {
        const el = form.querySelector(`[name="${n}"]`);
        if (!el) return;
        const v = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        if (String(vals[n] ?? '') !== v) diff[n] = v;
      });
      if (Object.keys(diff).length) {
        const r = await api('settings_save', diff);
        if (r && r.pruned) toast(r.pruned === 1 ? L('1 oude back-up opgeruimd') : L('{n} oude back-ups opgeruimd', { n: r.pruned }));
        if (settingsSaved) Object.assign(settingsSaved, diff);
        Object.entries(diff).forEach(([n, v]) => { const el = $(`#settings-form [name="${n}"]`); if (el) { if (el.type === 'checkbox') el.checked = v === '1'; else el.value = v; } });
        toast(L('Opgeslagen'));
      }
      loadProt();
      return undefined;
    },
  });
  names.forEach((n) => {
    const el = $(`#modal-body [name="${n}"]`);
    if (!el) return;
    if (el.type === 'checkbox') el.checked = vals[n] === '1'; else el.value = vals[n] ?? '';
  });
}

/** Groepen op Status (1.122.0): alle punten over agents samen, en alles over https samen */
const OV_GROUPS = [
  ['agents_all', () => L('Agents'), 'agents', ['agents', 'enc', 'ownkey', 'agent_https', 'agent_https_only', 'agent_audit', 'agent_ownkey_only', 'agent_ip', 'agent_sign']],
  ['https_all', () => L('Beheer via https'), 'outside', ['https', 'https_client', 'cert', 'dns', 'proxy_leg', 'http_login', 'split_dns', 'mfa_ext']],
];
function ovGroup(items, rank) {
  items.forEach((i) => { if (i.act) i.acts = [[i.act, i.label]]; });
  let out = items;
  for (const [key, title, section, keys] of OV_GROUPS) {
    const subs = out.filter((i) => keys.includes(i.key));
    if (subs.length < 2) continue;
    const worst = subs.reduce((a, b) => (rank[b.status] < rank[a.status] ? b : a));
    const bad = subs.filter((s) => s.status === 'bad' || s.status === 'warn');
    const merged = { key, title: title(), section, status: worst.status, subs,
      detail: bad.length ? (bad.length === 1 ? L('1 punt') : L('{n} punten', { n: bad.length })) + ': ' + bad.map((s) => s.title).join(', ') : L('alles in orde ({n} punten)', { n: subs.length }),
      acts: subs.flatMap((s) => (s.status === 'ok' ? [] : s.acts || [])), code: subs.map((s) => (s.status === 'ok' ? '' : s.code || '')).find(Boolean) || '' };
    const at = out.indexOf(subs[0]);
    out = out.filter((i) => !keys.includes(i.key));
    out.splice(Math.min(at, out.length), 0, merged);
  }
  return out.sort((a, b) => rank[a.status] - rank[b.status]);
}

/** Knop bij een punt van "Hoe staat het ervoor?" (1.78.0) */
async function healthAction(act, b) {
  b.disabled = true;
  try {
    if (act === 'agent_https_only') {
      const c = (await api('security')).conn;
      await api('conn_save', { key_days: c.key_days, pin: c.pin, ip_lock: c.ip_lock, https_only: true });
      toast(L('Agents alleen via https: aan'));
    } else if (act === 'agent_ownkey_only') {
      const c = (await api('security')).conn;
      await api('conn_save', { key_days: c.key_days, pin: c.pin, ip_lock: c.ip_lock, https_only: c.https_only, ownkey_only: true });
      toast(L('Alleen agents met een eigen sleutel: aan'));
    } else if (act === 'agents_cleanup') {
      b.disabled = false;
      return agentsCleanupDialog(() => loadSettingsHealth());
    } else if (act === 'http_login_block') {
      const s = await api('https_info');
      await api('https_save', { admin_url: s.admin_url, https_only: s.https_only, http_login_block: true, mfa_external: s.mfa_external });
      toast(L('Inloggen via http wordt nu geweigerd'));
    }
    loadSettingsHealth();
  } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
}

// ---- Lange uitleg (1.148.0): gewone grijze tekst op de kaart, niet meer in een ingeklapt oranje ⓘ-blok (1.120.0).
// Titels zonder emoji (de zijbalk en de bovenbalk hebben lijn-iconen). Ook voor wat later geladen wordt.
const EMOJI_LEAD = /^[\s\p{Extended_Pictographic}\u{FE0F}\u{200D}\u{20E3}]+/u;
function polishSettings(root) {
  $$('[data-sk] p.muted.small:not([id]):not([data-pol]), [data-sk] p.hint:not([id]):not([data-pol])', root).forEach((p) => {
    p.dataset.pol = '1';
    if (p.textContent.trim().length >= 120 && !p.closest('#set-overview, .ov-item, td, .kv, li')) p.classList.add('set-expl');
  });
  // Schakelaar met tekst in meerdere stukken (bv. met <b>): één geheel, anders zet de rij-opmaak de stukken door elkaar
  $$('[data-sk] label.switch:not([data-pol])', root).forEach((l) => {
    l.dataset.pol = '1';
    const sl = l.querySelector(':scope > input + span');
    if (!sl) return;
    const rest = [];
    for (let n = sl.nextSibling; n; n = n.nextSibling) rest.push(n);
    if (rest.filter((n) => n.nodeType === 1 || n.textContent.trim()).length <= 1 && !rest.some((n) => n.nodeType === 3 && n.textContent.trim())) return;
    const w = document.createElement('span');
    w.className = 'sw-txt';
    rest.forEach((n) => w.append(n));
    l.append(w);
  });
  // IDS: aan/uit van een onderdeel staat op IDS → Bescherming (1.122.0); hier niet nog een keer
  PROT_KEYS.forEach((k) => {
    const inp = $(`[data-sk^="ids"] label.switch > input[name="${k}"]`, root);
    const l = inp && inp.parentElement;
    if (!l || l.hidden) return;
    l.hidden = true;
    const nx = l.nextElementSibling;
    if (nx && ((nx.tagName === 'P' && nx.matches('.muted, .hint')) || nx.classList.contains('xp'))) nx.hidden = true;
  });
  // Een kopje waarvan alles eronder nu op Bescherming staat: ook weg
  $$('[data-sk^="ids"] h3.set-sub', root).forEach((h) => {
    let any = false;
    for (let n = h.nextElementSibling; n && !n.matches('h3.set-sub'); n = n.nextElementSibling) if (!n.hidden) { any = true; break; }
    h.hidden = !any;
  });
  $$('[data-sk^="ids"]:not([data-sk="ids_servers"]):not([data-sk="ids_rules"]):not([data-sk="ids_prot"]) > h2', root).forEach((h) => {
    if (h.nextElementSibling && h.nextElementSibling.classList.contains('prot-hint')) return;
    h.insertAdjacentHTML('afterend', `<div class="prot-hint">${L('Onderdelen aan- of uitzetten, en de drempels per onderdeel (⚙️), doe je bij <a href="#settings/ids_prot" data-goto="settings/ids_prot">IDS → Beschermingen</a>. Hier staan de algemene instellingen.')}</div>`);
  });
  $$('[data-sk] h2:not([data-pol]), [data-sk] h3.set-sub:not([data-pol])', root).forEach((h) => {
    h.dataset.pol = '1';
    const n = h.firstChild;
    if (n && n.nodeType === 3) n.textContent = n.textContent.replace(EMOJI_LEAD, '');
  });
}
function initSettingsTips() {
  const tab = $('#tab-settings');
  polishSettings(tab);
  let t = null;
  new MutationObserver(() => { clearTimeout(t); t = setTimeout(() => polishSettings(tab), 30); }).observe(tab, { childList: true, subtree: true });
  $$('#tab-settings [data-sk] label > span.hint, #tab-settings [data-sk] label > span.muted.small').forEach((s) => {
    const txt = s.textContent.trim().replace(/^\((.*)\)$/, '$1');
    if (txt.length < 40 || s.querySelector('a')) return;
    const t = document.createElement('span');
    t.className = 'tip';
    t.tabIndex = 0;
    t.dataset.tip = txt;
    t.setAttribute('aria-label', txt);
    t.textContent = 'ⓘ';
    // Het label is een kolom: de tekst ervoor en de ⓘ samen op één regel
    const line = document.createElement('span');
    line.className = 'lbl-line';
    const label = s.parentElement;
    while (label.firstChild && label.firstChild !== s) line.append(label.firstChild);
    line.append(t);
    s.replaceWith(line);
  });
}

// Alleen "meer uitleg" tonen als de alinea echt is ingekort (dat hangt af van de breedte)
function refreshClamps() {
  $$('#tab-settings p.clamp').forEach((p) => {
    const b = p.nextElementSibling;
    if (!b || !b.classList.contains('more-btn') || p.classList.contains('open') || !p.offsetParent) return;
    b.hidden = p.scrollHeight <= p.clientHeight + 2;
  });
}

function initSettingsNav() {
  $('#set-side').addEventListener('click', (e) => {
    const b = e.target.closest('[data-set-go]');
    if (!b) return;
    const hit = b.dataset.hit != null ? $('#set-side')._hits[+b.dataset.hit] : null;
    if (hit) $('#set-search').value = '';
    showSettingsSection(b.dataset.setGo, hit?.el);
  });
  $('#set-overview').addEventListener('click', (e) => {
    const b = e.target.closest('[data-set-go]');
    if (b) showSettingsSection(b.dataset.setGo);
  });
  $('#set-tabs').addEventListener('click', (e) => {
    const b = e.target.closest('[data-set-tab]');
    if (!b) return;
    setTab[setSection] = b.dataset.setTab;
    showSettingsSection(setSection);
  });
  const search = $('#set-search');
  search.addEventListener('input', renderSettingsNav);
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const hit = ($('#set-side')._hits || [])[0];
      if (!hit || !search.value.trim()) return;
      search.value = '';
      showSettingsSection(hit.sub[0], hit.el);
    } else if (e.key === 'Escape') { search.value = ''; renderSettingsNav(); }
  });
  $('#set-mselect').onchange = (e) => showSettingsSection(e.target.value);
  initSettingsTips();
  let rt;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(refreshClamps, 200); });
}

// ---------------------------------------------------------------- Mijn account (alleen als je met een account bent ingelogd)
async function loadAccount() {
  if (!$('#tab-account')) return;
  let me, devices = [], logins = [];
  try {
    me = (await api('users')).users.find((u) => u.me);
    [devices, logins] = await Promise.all([api('sessions', null, { user_id: me.id }), api('my_logins')]);
  } catch (ex) { $('#acct-data-body').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (!me) return;
  const row = (label, ctl, hint) => `<div class="acct-field"><span class="acct-lbl">${label}${hint ? `<small>${hint}</small>` : ''}</span><span class="acct-ctl">${ctl}</span></div>`;
  $('#acct-data-body').innerHTML = row(L('Gebruikersnaam'), `<b>${esc(me.username)}</b> <span class="role-tag r-${esc(me.role)}">${ROLE_LABEL[me.role] || esc(me.role)}</span>`, L('wijzigen kan alleen een beheerder'))
    + row(L('E-mailadres'), `<span class="mono">${me.email ? esc(me.email) : `<span class="muted">${L('geen')}</span>`}</span> <button type="button" class="btn small-btn" id="acct-email">✎ ${L('Wijzigen')}</button>`, L('voor "wachtwoord vergeten"'))
    + row(L('Taal'), `<span class="seg"><button type="button" data-lang-set="nl" class="${LANG === 'nl' ? 'active' : ''}">Nederlands</button><button type="button" data-lang-set="en" class="${LANG === 'en' ? 'active' : ''}">English</button></span>`)
    + row(L('Laatst ingelogd'), me.last_login ? esc(fmtDateTime(me.last_login)) : L('nooit'));
  $('#acct-email').onclick = () => emailDialog({ ...me, me: true }, loadAccount);   // 1.224.1: met je wachtwoord
  $$('#acct-data-body [data-lang-set]').forEach((b) => (b.onclick = async () => {
    if (b.classList.contains('active')) return;
    try { await api('lang_set', { lang: b.dataset.langSet }); location.reload(); } catch (ex) { toast(ex.message, 'err'); }
  }));
  $('#acct-2fa-body').innerHTML = me.totp
    ? row(L('Status'), `<b class="good-txt">✔ ${L('aan')}</b> <button type="button" class="btn" id="acct-2fa-off">${L('2FA uitzetten')}</button>`, L('bij het inloggen ook een code uit je authenticator-app'))
    : row(L('Status'), `<b class="warn-txt">✘ ${L('uit')}</b> <button type="button" class="btn primary" id="acct-2fa-on">🔐 ${L('2FA instellen')}</button>`, L('aanbevolen: naast je wachtwoord een code uit een app op je telefoon'));
  if ($('#acct-2fa-on')) $('#acct-2fa-on').onclick = () => totpSetup(me.id, me.username);
  if ($('#acct-2fa-off')) $('#acct-2fa-off').onclick = async () => {
    totpResetDialog(me, L('Tweestapsverificatie uitzetten? Inloggen kan daarna weer met alleen je wachtwoord.'), () => { toast(L('2FA staat uit')); loadAccount(); });
  };
  $('#acct-devices-body').innerHTML = `${xpHtml(L('Waar je nu bent ingelogd, en welke apparaten RzSense onthoudt.'), L('Hier zie je waar je nu bent ingelogd. <b>Onthouden apparaat (📌):</b> blijft ingelogd zonder wachtwoord en 2FA. Het wordt vergeten na 30 dagen niet gebruiken, of als je je wachtwoord wijzigt.'))}
    ${sessionRows(devices, false)}
    ${devices.some((d) => !d.current) ? `<button type="button" class="btn danger" id="acct-forget-all" style="margin-top:.6rem">${L('Alle andere apparaten uitloggen')}</button>` : ''}`;
  $('#acct-devices-body').onclick = async (e) => {
    const b = e.target.closest('[data-sess]');
    if (b) return sessionAction(b, loadAccount);
    try {
      if (e.target.closest('#acct-forget-all') && confirm(L('Alle andere apparaten uitloggen (en vergeten)? Dit apparaat blijft ingelogd.'))) {
        await api('sessions_logout_user', { user_id: me.id }); toast(L('Alle andere apparaten uitgelogd')); loadAccount();
      }
    } catch (ex) { toast(ex.message, 'err'); }
  };
  loadAccountOutside();
  $('#acct-logins-body').innerHTML = logins.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Wanneer')}</th><th>IP</th><th>${L('Hoe')}</th><th>${L('Apparaat')}</th></tr></thead><tbody>
    ${logins.map((x) => `<tr class="${+x.ok ? '' : 'bad-row'}"><td class="small">${esc(fmtDateTime(x.created_at))}</td><td class="mono small">${esc(x.ip)}</td>
      <td class="small">${+x.ok ? esc(L(x.how)) : `<span class="bad">✘ ${esc(L(x.how))}</span>`}</td><td class="small">${esc(x.device || '–')}</td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted" style="margin:0">${L('Nog geen logins bewaard.')}</p>`;
}

// ---------------------------------------------------------------- Van buiten: toegestane apparaten en passkeys (1.86.0)
/** Tabellen met apparaten en passkeys; admin: van iedereen (met de gebruiker erbij) */
function accessTables(devices, passkeys, admin, current = null) {
  const cc = (x) => (x ? ccTag(x) : '');
  const dev = devices.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr>${admin ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Apparaat')}</th><th>${L('Toegestaan')}</th><th>${L('Laatst gezien')}</th><th>${L('Passkeys')}</th><th></th></tr></thead><tbody>
    ${devices.map((d) => `<tr>${admin ? `<td>${esc(d.username)}</td>` : ''}<td>${devIcon(d.name)} <b>${esc(d.name)}</b>${d.current || +d.id === current ? ` <span class="badge">${L('dit apparaat')}</span>` : ''}</td>
      <td class="small">${esc(fmtDateTime(d.created_at))}<br><span class="muted mono">${esc(d.created_ip || '')}</span></td>
      <td class="small">${esc(ago(d.last_seen))}<br><span class="muted mono">${esc(d.last_ip || '')}</span>${cc(d.last_cc)}</td><td class="small">${+d.passkeys ? '🗝️ ' + d.passkeys : '–'}</td>
      <td class="nowrap"><button type="button" class="icon-btn" data-acc-ren="dev:${d.id}" title="${esc(L('Hernoemen'))}">✎</button> <button type="button" class="icon-btn" data-acc-del="dev:${d.id}" title="${esc(L('Intrekken: kan RzSense niet meer van buiten openen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Nog geen apparaten toegestaan.')}</p>`;
  const pk = passkeys.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr>${admin ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Passkey')}</th><th>${L('Domein')}</th><th>${L('Aangemaakt')}</th><th>${L('Laatst gebruikt')}</th><th></th></tr></thead><tbody>
    ${passkeys.map((p) => `<tr>${admin ? `<td>${esc(p.username)}</td>` : ''}<td>🗝️ <b>${esc(p.name)}</b>${p.device_name ? `<br><span class="muted small">${L('hoort bij {d}', { d: esc(p.device_name) })}</span>` : ''}</td>
      <td class="small mono">${esc(p.rp_id)}</td><td class="small">${esc(fmtDateTime(p.created_at))}</td><td class="small">${p.last_used ? esc(ago(p.last_used)) : L('nog niet')}</td>
      <td class="nowrap"><button type="button" class="icon-btn" data-acc-ren="pk:${p.id}" title="${esc(L('Hernoemen'))}">✎</button> <button type="button" class="icon-btn" data-acc-del="pk:${p.id}" title="${esc(L('Verwijderen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Nog geen passkeys.')}</p>`;
  return `<h4 class="acc-sub">📱 ${L('Toegestane apparaten')}</h4>${dev}<h4 class="acc-sub">🗝️ ${L('Passkeys')}</h4>${pk}`;
}

/** Hernoemen en intrekken in zo'n tabel; reload: daarna opnieuw laden */
async function accessAction(e, admin, reload) {
  const ren = e.target.closest('[data-acc-ren]'), del = e.target.closest('[data-acc-del]');
  const [kind, id] = ((ren || del)?.dataset.accRen || (ren || del)?.dataset.accDel || ':').split(':');
  if (!id) return;
  try {
    if (ren) {
      const name = prompt(L('Nieuwe naam'));
      if (!name) return;
      await api(kind === 'dev' ? 'device_rename' : 'passkey_rename', { id: +id, name, admin });
    } else {
      if (!confirm(kind === 'dev' ? L('Dit apparaat intrekken? Het kan RzSense dan niet meer van buiten openen; de passkeys van dit apparaat worden ook verwijderd.') : L('Deze passkey verwijderen?'))) return;
      await api(kind === 'dev' ? 'device_revoke' : 'passkey_delete', { id: +id, admin });
      toast(kind === 'dev' ? L('Apparaat ingetrokken') : L('Passkey verwijderd'));
    }
    reload();
  } catch (ex) { toast(ex.message, 'err'); }
}

/** Een passkey aanmaken op dit apparaat (na bevestigen met je wachtwoord) */
function passkeyCreate(done) {
  modal({
    title: '🗝️ ' + L('Passkey aanmaken'),
    body: `<p style="margin-top:0">${L('Je telefoon of computer maakt een sleutel die op het apparaat blijft. Daarna log je in met Face ID, je vingerafdruk of je pincode, zonder wachtwoord en 2FA-code. Wachtwoord en 2FA blijven werken als reserve.')}</p>
      <label>${L('Naam')}<input name="name" value="${esc(navigator.userAgent.includes('iPhone') ? 'iPhone' : navigator.userAgent.includes('Android') ? 'Android' : L('Deze computer'))}" maxlength="100"></label>
      ${confirmField('PASSKEY')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🗝️ ' + L('Aanmaken'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const o = await api('passkey_reg_options', { confirm: d.confirm });
      let cred;
      try {
        cred = await navigator.credentials.create({ publicKey: { ...o, challenge: unb64u(o.challenge), user: { ...o.user, id: unb64u(o.user.id) },
          excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: unb64u(c.id) })) } });
      } catch (ex) {
        throw new Error(ex.name === 'InvalidStateError' ? L('Op dit apparaat staat al een passkey voor RzSense') : ex.name === 'NotAllowedError' ? L('Geannuleerd') : ex.message);
      }
      await api('passkey_reg_finish', { name: d.name, clientDataJSON: b64u(cred.response.clientDataJSON), attestationObject: b64u(cred.response.attestationObject) });
      toast(L('Passkey aangemaakt'));
      done();
    },
  });
}

async function loadAccountOutside() {
  const box = $('#acct-outside-body');
  if (!box) return;
  let a;
  try { a = await api('my_access'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const door = a.door_url ? (a.door_url.startsWith('/') ? location.origin + location.pathname.replace(/\/[^/]*$/, '') + a.door_url : a.door_url) : '';
  const isDev = a.current !== null;
  box.innerHTML = `<p class="muted small" style="margin-top:0">${a.ui_locked
      ? L('Van buiten je eigen netwerk opent RzSense alleen op een <b>toegestaan apparaat</b> (anders een gewone 404). Zet dit apparaat thuis op de lijst, en maak er een <b>passkey</b> bij: dan log je onderweg in met Face ID of je vingerafdruk.')
      : L('Een beheerder kan instellen dat RzSense van buiten alleen op <b>toegestane apparaten</b> opent. Je kunt je apparaten nu al op de lijst zetten, en een <b>passkey</b> maken om zonder wachtwoord in te loggen.')}</p>
    <div class="btn-row">
      ${isDev ? `<span class="good-txt">✔ ${L('Dit apparaat is toegestaan van buiten')}</span>`
        : a.can_trust ? `<button type="button" class="btn primary" id="acct-trust">📱 ${L('Dit apparaat ook van buiten toestaan')}</button>`
        : `<span class="muted small">${L('Dit apparaat toestaan kan alleen thuis (vanaf je eigen netwerk) of na een noodlink.')}</span>`}
      ${a.passkey_possible ? `<button type="button" class="btn" id="acct-passkey">🗝️ ${L('Passkey aanmaken')}</button>`
        : a.passkeys_enabled ? `<span class="muted small">${L('Passkeys werken alleen via https met een domeinnaam (bv. {rp}), niet via een IP-adres.', { rp: '<code>' + esc(a.rp_id) + '</code>' })}</span>` : ''}
    </div>
    ${accessTables(a.devices, a.passkeys, false, a.current)}
    ${a.door && door ? `${xpHtml(L('Sleutel kwijt terwijl je onderweg bent? Bewaar dit adres.'), L('Ben je je sleutel kwijt (browser gewist, nieuwe telefoon) terwijl je onderweg bent? Open dan {url}: je krijgt een 404, maar er gaat een <b>noodlink</b> naar je e-mailadres (en Pushover voor een beheerder). Die werkt één keer en 15 minuten.', { url: `<code class="sel-all">${esc(door)}</code>` }))}` : ''}`;
  if ($('#acct-trust')) $('#acct-trust').onclick = () => modal({
    title: '📱 ' + L('Dit apparaat toestaan van buiten'),
    body: `<p style="margin-top:0">${L('Dit apparaat krijgt een eigen sleutel (een cookie, 1 jaar). Daarmee opent RzSense ook van buiten je netwerk, alleen voor jouw account. Wis je de gegevens van je browser, dan moet je het opnieuw doen.')}</p>
      <label>${L('Naam')}<input name="name" value="${esc(navigator.userAgent.includes('iPhone') ? 'iPhone' : navigator.userAgent.includes('Android') ? 'Android' : L('Deze computer'))}" maxlength="100"></label>
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '📱 ' + L('Toestaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('device_trust', { name: d.name, confirm: d.confirm }); toast(L('Dit apparaat is toegestaan')); loadAccountOutside(); },
  });
  if ($('#acct-passkey')) $('#acct-passkey').onclick = () => passkeyCreate(loadAccountOutside);
  box.onclick = (e) => accessAction(e, false, loadAccountOutside);
}

async function loadStealth() {
  const box = $('#stealth-switches');
  if (!box) return;
  let s;
  try { s = await api('stealth_info'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sw = (k, on, label, hint) => `<label class="switch"><input type="checkbox" data-stealth="${k}" ${on ? 'checked' : ''}><span></span> <span>${label}</span></label>${!hint ? '' : String(hint).startsWith('<div class="xp') ? `<div style="margin:-.3rem 0 .6rem 2.6rem">${hint}</div>` : `<p class="muted small" style="margin:-.3rem 0 .6rem 2.6rem">${hint}</p>`}`;   // 1.163.0: lange uitleg als Meer info
  box.innerHTML = sw('hide', s.hide, L('<b>Niet herkenbaar</b> van buiten'), xpHtml(L('Van buiten geeft RzSense niets prijs.'), L('Geen naam of versie. Het agent-script krijgen alleen bekende agents of wie de downloadsleutel heeft; wie geweigerd wordt of onbekend is, krijgt een 404. Waarom iets geweigerd is, zie je bij Meldingen.')))
    + sw('ui', s.ui, L('Webinterface van buiten alleen op <b>toegestane apparaten</b>'), L('Zonder toegestaan apparaat ook geen inlogscherm. Elk apparaat hoort bij één gebruiker. Zet eerst je eigen apparaten op de lijst (Mijn account).')
      + (s.ui && !s.devices_all.length ? ` <b class="warn-txt">${L('Er staat nog geen apparaat op de lijst: van buiten kan nu niemand in RzSense.')}</b>` : ''))
    + sw('countries', s.countries, L('Alleen vanuit je <b>normale landen</b> ({list})', { list: esc(s.allowed_countries.join(', ') || '–') }),
      (s.geo_data ? L('In te stellen bij <a href="#settings/ids_attack" data-goto="settings/ids_attack">IDS: aanvallers en verkeer</a>. Zonder landgegevens geldt dit niet.') : `<span class="warn-txt">${L('Er zijn nog geen landgegevens (<a href="#settings/ids_attack" data-goto="settings/ids_attack">IDS: aanvallers en verkeer</a>); tot dan geldt dit niet.')}</span>`))
    + sw('door', s.door, L('<b>Noodlink</b> als je sleutel kwijt is'), xpHtml(L('Een link naar je e-mail als je sleutel kwijt is.'), L('Via een vast adres (zie Mijn account) gaat een link naar je e-mail (en Pushover voor een beheerder). Die werkt één keer en 15 minuten. Je krijgt altijd een melding als iemand hem aanvraagt.')))
    + sw('passkeys', s.passkeys, L('<b>Passkeys</b> (inloggen met Face ID, vingerafdruk of beveiligingssleutel)'), L('In plaats van wachtwoord en 2FA; die blijven de reserve. Alleen via https met een domeinnaam.'))
    + sw('requests', s.requests_on, L('<b>Geweigerde apparaten</b> bijhouden, om ze hier toe te staan'), xpHtml(L('Apparaten die van buiten geweigerd worden, kun je hier toestaan.'), L('Een browser uit je normale landen die van buiten een 404 krijgt, komt onder <i>Wachtende apparaten</i>. Hij krijgt dezelfde 404, plus een wachtende sleutel in een cookie. Sta je hem toe, dan krijgt hij bij zijn volgende bezoek het inlogscherm. Scanners en bots tellen niet mee.')));
  renderDeviceRequests(s);
  box.onchange = async (e) => {
    const c = e.target.closest('[data-stealth]');
    if (!c) return;
    const body = { [c.dataset.stealth]: c.checked };
    const save = async (extra = {}) => { await api('stealth_save', { ...body, ...extra }); savedFlag(c, true); loadStealth(); };
    if (!c.checked && (c.dataset.stealth === 'hide' || c.dataset.stealth === 'ui')) {
      return modal({
        title: '⚠️ ' + L('Minder veilig maken?'),
        body: `<p style="margin-top:0">${c.dataset.stealth === 'ui' ? L('Van buiten is het inlogscherm dan weer voor iedereen te zien.') : L('Van buiten is dan weer te zien dat hier RzSense draait, en welke versie.')}</p>${confirmField('UITZETTEN')}`,
        buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); loadStealth(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
        onSubmit: (d) => save({ confirm: d.confirm }),
      });
    }
    try { await save(); } catch (ex) { toast(ex.message, 'err'); loadStealth(); }
  };
  $('#stealth-devices').innerHTML = accessTables(s.devices_all, s.passkeys_all, true);
  $('#stealth-devices').onclick = (e) => accessAction(e, true, loadStealth);
}

/** Wachtende apparaten (1.102.0): van buiten geweigerd, toe te staan voor één gebruiker */
function renderDeviceRequests(s) {
  const box = $('#stealth-requests');
  if (!box) return;
  if (!s.ui || !s.requests_on) { box.innerHTML = ''; return; }
  const rows = s.requests || [];
  box.innerHTML = `<h3 class="set-sub">⏳ ${L('Wachtende apparaten')}${rows.length ? ` <span class="badge-root">${rows.length}</span>` : ''}</h3>`
    + (rows.length ? `<p class="muted small" style="margin-top:-.2rem">${L('Van buiten geweigerd (404). Sta een apparaat alleen toe als je weet van wie het is; inloggen (met 2FA) blijft daarna nodig.')}</p>
      <div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Apparaat')}</th><th>${L('IP-adres')}</th><th>${L('Eerst gezien')}</th><th>${L('Laatst gezien')}</th><th></th></tr></thead><tbody>
      ${rows.map((r) => `<tr><td>${devIcon(r.name)} <b>${esc(r.name)}</b>${+r.hits > 1 ? ` <span class="muted small">${r.hits}×</span>` : ''}</td>
        <td class="small"><span class="mono">${esc(r.last_ip)}</span>${r.last_cc ? ccTag(r.last_cc) : ''}${r.created_ip !== r.last_ip ? `<br><span class="muted">${L('eerst: {ip}', { ip: esc(r.created_ip) })}</span>` : ''}</td>
        <td class="small">${esc(fmtDateTime(r.created_at))}</td><td class="small">${esc(ago(r.last_seen))}</td>
        <td class="nowrap"><button type="button" class="btn small-btn" data-req-ok="${r.id}">✅ ${L('Toestaan')}</button>
          <button type="button" class="icon-btn" data-req-ign="${r.id}" title="${esc(L('Negeren: niet meer tonen en geen meldingen meer van dit apparaat'))}">🙈</button>
          <button type="button" class="icon-btn" data-req-block="${esc(r.last_ip)}" title="${esc(L('IP blokkeren'))}">⛔</button></td></tr>`).join('')}</tbody></table></div>`
      : `<p class="muted small" style="margin-top:-.2rem">${L('Geen wachtende apparaten. Krijgt een browser van buiten een 404, dan zie je hem hier.')}</p>`);
  box.onclick = async (e) => {
    const ok = e.target.closest('[data-req-ok]'), ign = e.target.closest('[data-req-ign]'), blk = e.target.closest('[data-req-block]');
    if (ok) return deviceRequestDialog(rows.find((r) => +r.id === +ok.dataset.reqOk), s.users);
    if (blk) return blockDialog(blk.dataset.reqBlock);
    if (ign) {
      try { await api('device_request_ignore', { id: +ign.dataset.reqIgn }); toast(L('Genegeerd')); loadStealth(); } catch (ex) { toast(ex.message, 'err'); }
    }
  };
}

function deviceRequestDialog(r, users) {
  if (!r) return;
  modal({
    title: '✅ ' + L('Apparaat toestaan'),
    body: `<p style="margin-top:0">${devIcon(r.name)} <b>${esc(r.name)}</b> · <span class="mono">${esc(r.last_ip)}</span>${r.last_cc ? ccTag(r.last_cc) : ''} · ${L('{n}× geprobeerd, laatst {t}', { n: r.hits, t: esc(ago(r.last_seen)) })}</p>
      <p class="muted small mono" style="word-break:break-all">${esc(r.ua)}</p>
      <div id="req-ipinfo"></div>
      <label>${L('Voor gebruiker')}<select name="user_id" required>${users.map((u) => `<option value="${u.id}">${esc(u.username)}</option>`).join('')}</select></label>
      <label>${L('Naam van het apparaat')}<input name="name" value="${esc(r.name)}" maxlength="100"></label>
      <p class="muted small">${L('Bij het volgende bezoek krijgt dit apparaat het inlogscherm, alleen voor deze gebruiker en met 2FA als die aan staat. Intrekken kan altijd hieronder.')}</p>
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✅ ' + L('Toestaan'), submit: true }],
    onSubmit: async (d) => { await api('device_request_approve', { id: +r.id, user_id: +d.user_id, name: d.name, confirm: d.confirm }); toast(L('Apparaat toegestaan')); loadStealth(); },
  });
  fillIpInfo($('#req-ipinfo'), r.last_ip);
}

/** Waar de agents de logs van de reverse proxy (nginx), Home Assistant en Vaultwarden vonden (1.89.0); 1.222.0 met de soort proxy en het hele pad */
async function loadWeblogStatus() {
  const box = $('#weblog-status');
  if (!box) return;
  let rows;
  try { rows = await api('weblog_status'); } catch (ex) { return; }
  const names = { swag: L('Reverse proxy (nginx)'), ha: 'Home Assistant', vw: 'Vaultwarden' };
  const found = rows.filter((r) => !r.err && r.path);
  const px = found.filter((r) => r.src === 'swag');
  const other = found.filter((r) => r.src !== 'swag');
  const ic = { SWAG: '🛡️', 'Nginx Proxy Manager': '🧭', nginx: '🌐' };
  const pxHtml = px.length ? `<div class="rp"><b>${L('Reverse proxy: gevonden op je servers')}</b>${px.map((r) => `<div class="rp-srv"><b>${esc(r.host)}</b>
      <span class="p">${r.label ? `${ic[r.label] || ''} <b>${esc(r.label)}</b>${r.ctr ? ` (${L('container')} <code>${esc(r.ctr)}</code>)` : ''} · ` : ''}<code>${esc(r.path)}</code>${r.sites ? `<br>${L('{n} sites, {r} verdachte verzoeken in 24 uur', { n: r.sites, r: (+r.req).toLocaleString(LOCALE) })}` : ''}</span>
      <span class="rp-st ${r.full ? 'ok' : 'w'}">${r.full ? '✔ ' + L('met site en dienst') : r.label ? '⚠ ' + L('zonder sitenaam') : L('agent 1.86.0 nodig voor het hele pad')}</span></div>`).join('')}
    ${px.filter((r) => !r.full && r.label && r.label !== 'Nginx Proxy Manager').map((r) => weblogFullHow(r)).join('')}
    ${px.filter((r) => r.full && /rzsense\.log$/.test(String(r.path))).map((r) => `<details class="adv"><summary>${L('De regels voor het extra logbestand op {host} (opnieuw) tonen', { host: esc(r.host) })}</summary>${weblogFullHow(r)}</details>`).join('')}</div>` : '';
  box.innerHTML = pxHtml + (other.length ? other.map((r) => `✔ <b>${names[r.src] || esc(r.src)}</b> ${L('op {host}', { host: esc(r.host) })}: <code>${esc(r.path)}</code>`
      + (r.hint ? `<br><span class="warn-txt">⚠️ ${esc(r.hint)}</span>` : '')).join('<br>') : '')
    + (!found.length ? `<span class="muted">${L('Nog nergens gevonden (agent 1.58.0 nodig; het zoeken gebeurt bij het volgende rapport).')}</span>` : '')
    + ['swag', 'ha', 'vw'].filter((s) => !found.some((r) => r.src === s) && rows.some((r) => r.src === s)).map((s) => `<br><span class="muted">– ${names[s]}: ${L('op geen enkele server gevonden')}</span>`).join('');
  wireCopy(box);
}

/** Uitleg: het extra logbestand voor het hele pad (site en dienst) aanzetten, voor SWAG of een gewone nginx */
function weblogFullHow(r) {
  const swag = r.label === 'SWAG';
  const dir = String(r.path).replace(/\/[^/]*$/, '');
  const inDir = swag ? '/config/log/nginx' : dir;
  const conf = swag ? '/config/nginx/nginx.conf' : '/etc/nginx/nginx.conf';
  const pid = 'wlf-' + String(r.host).replace(/[^A-Za-z0-9_-]/g, '_');
  // 1.222.1: zonder gebruikersnaam en verwijzer (die heeft RzSense niet nodig); de agent lakt tokens in adressen weg
  const lines = `log_format rzsense '$remote_addr - - [$time_local] "$request" $status $body_bytes_sent "-" "$http_user_agent" "$host" "$upstream_addr" $request_time';\naccess_log ${inDir}/rzsense.log rzsense;`;
  return `<div class="rp-snip"><b>🛤️ ${L('Het hele pad zien voor {p} op {host}', { p: esc(r.label), host: esc(r.host) })}</b> <span class="muted small">(${L('één keer, ± 1 minuut')})</span>
    <p class="small" style="margin:.3rem 0">${L('Nu weet RzSense wie en wat, maar niet welke site en welke dienst. Met een extra logbestand wel. Je bestaande log blijft zoals hij is, dus fail2ban blijft gewoon werken.')} ${L('Het extra logbestand bevat geen gebruikersnamen en geen verwijzers; tokens en wachtwoorden in een adres lakt de agent weg voordat iets de server verlaat.')}</p>
    <ol><li>${L('Open {f}', { f: `<code>${conf}</code>` })}${swag ? ` (${L('op de server: {f}', { f: `<code>${esc(dir.replace(/\/log\/nginx$/, '/nginx/nginx.conf'))}</code>` })})` : ''}.</li>
    <li>${L('Zet deze twee regels in het blok {b}, onder de regel met {a}:', { b: '<code>http { … }</code>', a: '<code>access_log</code>' })}</li></ol>
    <div class="rp-pre"><pre class="mono" id="${pid}">${esc(lines)}</pre><button type="button" class="btn" data-copy="${pid}">📋 ${L('Kopiëren')}</button></div>
    <ol start="3"><li>${swag ? L('Herstart SWAG: {c}', { c: `<code>docker restart ${esc(r.ctr || 'swag')}</code>` }) : L('Laad nginx opnieuw: {c}', { c: '<code>nginx -t && systemctl reload nginx</code>' })}.</li>
    <li>${L('Bij het volgende rapport van de agent staat hier {s}.', { s: `<span class="rp-st ok">✔ ${L('met site en dienst')}</span>` })}</li></ol></div>`;
}

async function loadSettings() {
  settingsLoaded = true;
  initAiSettings();
  loadUsers();
  if (!isAdmin()) return;   // de rest van de instellingen is alleen voor beheerders
  loadApiKeys();
  loadStealth();
  loadWeblogStatus();
  loadSecurity();
  loadAccess();
  loadHttps();
  loadHibp();
  loadDbInfo();
  loadNetscanSettings();
  const s = await api('settings');
  const f = $('#settings-form');
  $('#mqtt-status').innerHTML = s.mqtt_enabled !== '1' ? ''
    : s.mqtt_last_error ? `<b class="bad">✘ ${esc(s.mqtt_last_error)}</b>`
    : s.mqtt_last_ok ? `<b class="good">✔ ${L('Laatst gepubliceerd {ago}', { ago: ago(s.mqtt_last_ok) })}</b>` : `<span class="muted">${L('Nog niet gepubliceerd')}</span>`;
  // 1.136.0: grootte en groei van de database bij de bewaartermijnen
  const g = s.db_growth;
  $('#storage-growth').textContent = g ? L('Database nu {now}; de laatste {d} dagen gemiddeld {per} per dag erbij.', { now: fmtBytes(g.now), d: g.days, per: fmtBytes(Math.max(0, g.per_day)) }) : '';
  Object.entries(s).forEach(([k, v]) => {
    const el = f.elements[k];
    if (!el) return;
    if (el.type === 'checkbox') el.checked = v === '1';
    else el.value = v ?? '';
  });
  const parse = (v) => { try { return JSON.parse(v || '{}') || {}; } catch (e) { return {}; } };
  notifyGlobal = notifyEditor($('#notify-editor'), { rules: parse(s.notify_rules), channels: parse(s.notify_channels), flap: parse(s.notify_flap) },
    { push_enabled: s.pushover_enabled === '1' || s.ntfy_enabled === '1', email_enabled: s.email_enabled === '1', ha_webhook_enabled: s.ha_webhook_enabled === '1' });
  sysinfoCfg = parse(s.sysinfo);
  renderSysinfo();
  vulnExcludeEditor();
  $('#vuln-enabled').checked = s.vuln_enabled === '1';
  $('#ids-enabled').checked = s.ids_enabled === '1';
  $('#ids-mode').checked = s.ids_mode === 'block';
  fimGlobal = fimEditor($('#fim-editor'), parse(s.ids_fim || '[]'), null, () => { markSettingsDirty(true); settingsSummaries(); });
  confTypes(parse(s.confirm_types));   // 1.193.0
  idsModeInfo();
  backupFormToggle();
  markSettingsDirty(false);
  applySettingsLayout();
  loadBackups();
  loadUpdate(false);
  loadAgentCommands();
  refresh();   // verse agentversies voor de kaart "Agents bijwerken"
}

/** Kaart "Agents": hoeveel agents verouderd zijn, en de knop om ze allemaal bij te werken. */
function renderAgentUpdates() {
  const agents = state.hosts.filter((h) => h.mode === 'agent' && +h.enabled && h.agent_seen);
  const old = agents.filter((h) => !h.agent_version || verCmp(h.agent_version, state.agent_latest) < 0);
  const busy = agents.filter((h) => h.agent_update_req);
  $('#agents-status').innerHTML = !agents.length ? L('Nog geen agents.')
    : (old.length ? `<b class="warn-txt">${L('{n} van {m} agent(s) verouderd', { n: old.length, m: agents.length })}</b>: ${old.map((h) => esc(h.name) + (h.agent_version ? ` (${esc(h.agent_version)})` : '')).join(', ')}`
      : `<b class="good-txt">✔ ${L('Alle {n} agent(s) zijn up-to-date (agent {v})', { n: agents.length, v: esc(state.agent_latest) })}</b>`)
      + (busy.length ? ` · ⏳ ${L('{n} wordt bijgewerkt', { n: busy.length })}` : '');
  $('#agents-update-all').hidden = !old.length;
  settingsSummaries();
}

async function updateAllAgents(e) {
  const b = e.currentTarget;
  b.disabled = true;
  try {
    const r = await api('agent_update', { all: true });
    toast(L('{n} agent(s) worden bijgewerkt bij hun volgende rapport', { n: r.count }));
    await refresh();
    renderAgentUpdates();
  } catch (ex) { toast(ex.message, 'err'); }
  b.disabled = false;
}

// ---------------------------------------------------------------- Meldregels (algemeen en per check)
const NOTIFY_ROWS = [
  ['warn', '🟠 Warning'], ['crit', '🔴 Critical'], ['unknown', '⚪ ' + L('Onbekend')], ['recovery', '✅ ' + L('Hersteld')], ['info', 'ℹ️ ' + L('Info (checks)')],
];
const NOTIFY_CHANNELS = [['pushover', '📲 Push', 'push_enabled'], ['email', L('E-mail'), 'email_enabled'], ['webhook', 'Home Assistant', 'ha_webhook_enabled']];

/** Tabel met per status: melden (nooit / direct / na X min), herhalen en kanalen. Geeft {get()} terug. */
function notifyEditor(el, cfg, enabled = {}) {
  const r = cfg.rules || {}, ch = cfg.channels || {}, fl = cfg.flap || {};
  el.innerHTML = `<div class="table-scroll"><table class="notify-table">
    <thead><tr><th>${L('Status')}</th><th>${L('Melden')}</th><th class="nt-rep">${L('Herhalen')}</th>
      ${NOTIFY_CHANNELS.map(([k, l, set]) => `<th class="nt-ch" title="${enabled[set] === false ? L('Dit kanaal staat uit (zie hieronder)') : ''}">${l}${enabled[set] === false ? ` <span class="muted">(${L('uit')})</span>` : ''}</th>`).join('')}
      <th class="nt-ch" title="${L('Alleen bij info: problemen staan altijd op de pagina Meldingen')}">📋 ${L('Meldingenpagina')}</th></tr></thead>
    <tbody>${NOTIFY_ROWS.map(([k, label]) => {
      const x = r[k] || { mode: k === 'info' ? 'never' : 'now', min: 0, repeat: 0 };   // info: standaard uit
      return `<tr data-k="${k}">
        <td class="nt-label">${label}</td>
        <td><div class="nt-when">
          <select data-f="mode">
            <option value="never" ${x.mode === 'never' ? 'selected' : ''}>${L('Nooit')}</option>
            <option value="now" ${x.mode === 'now' ? 'selected' : ''}>${L('Direct')}</option>
            ${k === 'info' ? '' : `<option value="after" ${x.mode === 'after' ? 'selected' : ''}>${k === 'recovery' ? L('Na … min OK') : L('Na … minuten')}</option>`}
          </select>
          <input type="number" data-f="min" min="1" max="10080" value="${x.mode === 'after' ? x.min : 5}" ${x.mode === 'after' ? '' : 'hidden'} title="${L('minuten')}">
        </div></td>
        <td class="nt-rep">${k === 'recovery' || k === 'info' ? '<span class="muted small">–</span>'
          : `<span class="nt-rep">${L('elke {input} min', { input: `<input type="number" data-f="repeat" min="0" max="10080" value="${x.repeat || 0}">` })}</span>`}</td>
        ${NOTIFY_CHANNELS.map(([c]) => `<td class="nt-ch"><input type="checkbox" data-c="${c}" ${(ch[k] || {})[c] !== false ? 'checked' : ''}></td>`).join('')}
        <td class="nt-ch">${k === 'info' ? `<input type="checkbox" data-c="page" ${(ch.info || {}).page !== false ? 'checked' : ''}>` : `<span class="muted small" title="${L('Altijd')}">✓</span>`}</td>
      </tr>`;
    }).join('')}</tbody></table></div>
    <p class="hint"><span class="nt-rep-hint">${L('Herhalen: elke X minuten opnieuw zolang het probleem blijft (0 = nooit).')}</span> ${L('Info: gebeurtenissen die geen storing zijn (herstart, vernieuwd certificaat, agent of RzSense bijgewerkt), stil via push; tijdens stille uren niet verstuurd, wel op de pagina Meldingen.')}</p>
    <div class="flap-box">
      <label class="switch small"><input type="checkbox" data-flap="on" ${fl.on !== false ? 'checked' : ''}><span></span> <b>Anti-flapping</b></label>
      <div class="flap-fields">
        ${L('Een check flapt bij {changes} statuswisselingen binnen {window} minuten; weer stabiel na {stable} minuten zonder wisseling.', {
          changes: `<input type="number" data-flap="changes" min="2" max="100" value="${fl.changes ?? 4}">`,
          window: `<input type="number" data-flap="window" min="1" max="1440" value="${fl.window ?? 30}">`,
          stable: `<input type="number" data-flap="stable" min="1" max="1440" value="${fl.stable ?? 15}">` })}
      </div>
      ${xpHtml(L('Een check die steeds wisselt, geeft niet steeds een melding.'), L('Flapt een check, dan krijg je één melding <i>staat te flappen</i>, daarna per status (warning, critical, onbekend, hersteld) hooguit één melding, en een melding als hij weer stabiel is. <b>Goed om te weten:</b> ook hier geldt <i>melden na … mislukte checks achter elkaar</i>: zolang dat aantal niet gehaald wordt, flapt hij stil.'))}
    </div>`;
  const sync = () => { $('.flap-fields', el).classList.toggle('off', !$('[data-flap=on]', el).checked); $$('tr[data-k]', el).forEach((tr) => {
    const mode = $('[data-f=mode]', tr).value;
    $('[data-f=min]', tr).hidden = mode !== 'after';
    $$('input', tr).forEach((i) => { if (i.dataset.f !== 'min') i.disabled = mode === 'never' && i.dataset.f === 'repeat'; });
  }); };
  el.onchange = sync;
  sync();
  return {
    /** Info-check: alleen de rij Info tonen; de andere rijen blijven bewaard (weer zichtbaar als het uit gaat) */
    infoOnly(on) {
      $$('tr[data-k]', el).forEach((tr) => (tr.hidden = on && tr.dataset.k !== 'info'));
      $$('.flap-box, .nt-rep-hint, th.nt-rep, td.nt-rep', el).forEach((x) => (x.hidden = on));
    },
    get() {
      const out = { rules: {}, channels: {} };
      $$('tr[data-k]', el).forEach((tr) => {
        const k = tr.dataset.k;
        out.rules[k] = { mode: $('[data-f=mode]', tr).value, min: +$('[data-f=min]', tr).value || 1, repeat: +($('[data-f=repeat]', tr)?.value || 0) };
        out.channels[k] = Object.fromEntries($$('[data-c]', tr).map((i) => [i.dataset.c, i.checked]));
      });
      out.flap = { on: $('[data-flap=on]', el).checked, changes: +$('[data-flap=changes]', el).value, window: +$('[data-flap=window]', el).value, stable: +$('[data-flap=stable]', el).value };
      return out;
    },
  };
}
let notifyGlobal = null;   // editor van de algemene meldregels
let fimGlobal = null;      // editor van de algemene lijst 'Bestanden bewaken' (IDS)
let quietGlobal = null;    // editor van de algemene stille uren

// ---------------------------------------------------------------- Stille uren
const QUIET_DAYS = [[1, L('ma')], [2, L('di')], [3, L('wo')], [4, L('do')], [5, L('vr')], [6, L('za')], [7, L('zo')]];
const QUIET_DEFAULT = () => ({ from: '23:00', to: '07:00', days: [1, 2, 3, 4, 5, 6, 7] });
const QUIET_MODES = { global: L('algemeen (Instellingen)'), own: L('eigen vensters'), never: L('nooit stil: altijd melden') };
/** Eigen stille uren van een check ({mode, windows}) of null (= algemeen). */
function quietOf(c) { try { return JSON.parse(c.quiet || 'null'); } catch (e) { return null; } }
/** "ma–vr 23:00–07:00 · za, zo 00:00–10:00" */
function quietText(windows) {
  return (windows || []).map((w) => {
    const d = w.days.length === 7 ? L('elke dag') : w.days.join(',') === '1,2,3,4,5' ? L('ma–vr') : w.days.join(',') === '6,7' ? L('za–zo')
      : w.days.map((x) => QUIET_DAYS[x - 1][1]).join(', ');
    return `${d} ${w.from}–${w.to}`;
  }).join(' · ');
}

/** Vensters (van–tot, dagen) met optioneel een aan/uit-schakelaar. Geeft {get()} terug: { on, windows }. */
function quietEditor(el, cfg, withSwitch) {
  const row = (w) => `<div class="quiet-row">
      <span class="quiet-time">${L('van {from} tot {to}', { from: `<input type="time" data-q="from" value="${esc(w.from)}" required>`, to: `<input type="time" data-q="to" value="${esc(w.to)}" required>` })}</span>
      <span class="quiet-days">${QUIET_DAYS.map(([d, l]) => `<label class="day-chip"><input type="checkbox" data-day="${d}" ${w.days.includes(d) ? 'checked' : ''}><span>${l}</span></label>`).join('')}</span>
      <button type="button" class="icon-btn" data-q="del" title="${L('Venster verwijderen')}">🗑</button>
    </div>`;
  const wins = cfg.windows && cfg.windows.length ? cfg.windows : [QUIET_DEFAULT()];
  el.innerHTML = `${withSwitch ? `<label class="switch small"><input type="checkbox" data-q="on" ${cfg.on ? 'checked' : ''}><span></span> <b>${L('Stille uren aan')}</b></label>` : ''}
    <div class="quiet-fields"><div class="quiet-list">${wins.map(row).join('')}</div>
      <button type="button" class="btn small-btn" data-q="add">＋ ${L('Venster')}</button>
      ${xpHtml(L('Een venster mag over middernacht lopen; meerdere vensters kan ook.'), L('<b>Over middernacht:</b> 23:00 tot 07:00 hoort bij de dag waarop het begint. Van gelijk aan tot = 24 uur. <b>Meerdere vensters</b> kan ook, bijvoorbeeld door de week 23:00–07:00 en in het weekend 00:00–10:00.'))}</div>`;
  const sync = () => {
    if (withSwitch) $('.quiet-fields', el).classList.toggle('off', !$('[data-q=on]', el).checked);
    $$('[data-q=del]', el).forEach((b) => (b.disabled = $$('.quiet-row', el).length < 2));
  };
  el.onclick = (e) => {
    if (e.target.closest('[data-q=add]')) $('.quiet-list', el).insertAdjacentHTML('beforeend', row({ from: '00:00', to: '08:00', days: [6, 7] }));
    const del = e.target.closest('[data-q=del]');
    if (del) del.closest('.quiet-row').remove();
    sync();
  };
  el.onchange = sync;
  sync();
  return {
    get() {
      return {
        on: withSwitch ? $('[data-q=on]', el).checked : true,
        windows: $$('.quiet-row', el).map((r) => ({ from: $('[data-q=from]', r).value, to: $('[data-q=to]', r).value,
          days: $$('[data-day]', r).filter((i) => i.checked).map((i) => +i.dataset.day) })).filter((w) => w.from && w.to && w.days.length),
      };
    },
  };
}

// ---------------------------------------------------------------- 1.189.0: stille-urenprofielen
const QUIET_SRC = () => ({ global: L('algemeen'), kind: L('soort apparaat'), grp: L('groep'), host: L('server'), check: L('deze check'), host_now: L('Nu stil (server)'), check_now: L('Nu stil') });
const QUIET_ICONS = ['🌙', '🏢', '🏖️', '🎄', '📺', '😴', '🔧', '🌃'];
/** Keuzelijst: volgt (leeg), elk profiel, nooit stil */
function quietOptions(cur, followLabel) {
  const ps = state.quiet_profiles || [];
  return `${followLabel !== null ? `<option value="">${esc(followLabel)}</option>` : ''}${ps.map((p) => `<option value="p:${p.id}" ${cur === 'p:' + p.id ? 'selected' : ''}>${esc(p.icon)} ${esc(p.name)}</option>`).join('')}
    <option value="never" ${cur === 'never' ? 'selected' : ''}>🔔 ${L('nooit stil: altijd melden')}</option>`;
}
function quietLabel(q) {
  if (!q) return '';
  if (q.mode === 'never') return L('nooit stil: altijd melden');
  const p = (state.quiet_profiles || []).find((x) => +x.id === +q.id);
  return p ? `${p.icon} ${p.name}` : L('eigen vensters');
}
/** Label bij een check: nu stil (met bron), of een eigen keuze */
function quietTag(q) {
  if (!q) return '';
  const via = QUIET_SRC()[q.src] || q.src;
  if (q.now) return ` <span class="quiet-tag on" title="${esc(L('Stil tot {t}: {name} (via {via})', { t: fmtUntil(q.now), name: q.name, via }) + (q.crit ? ' · ' + L('critical komt wel door') : ''))}">🌙 ${esc(fmtUntil(q.now))}</span>`;
  if (q.src === 'check' && q.never) return ` <span class="quiet-tag" title="${L('Nooit stil: meldt ook tijdens de stille uren')}">🌙✕</span>`;
  if (q.src === 'check') return ` <span class="quiet-tag" title="${esc(L('Eigen stille uren: {name}', { name: q.name }))}">🌙</span>`;
  return '';
}
/** 🌙 Nu stil: voor een uur, vier uur, tot morgen 07:00 of tot een tijdstip; of opheffen */
function quietNowDialog(what, id, label, q) {
  const active = q && (q.src === what + '_now');
  const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1); tomorrow.setHours(7, 0, 0, 0);
  const pad = (n) => String(n).padStart(2, '0');
  const local = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  modal({
    title: '🌙 ' + L('Nu stil') + ' – ' + label,
    body: `<p class="small">${what === 'host' ? L('Geen meldingen van deze server en zijn checks tot het tijdstip dat je kiest; ook critical niet. De checks lopen door; na afloop komt een samenvatting van wat nog speelt.')
        : L('Geen meldingen van deze check tot het tijdstip dat je kiest; ook critical niet. De check loopt door; na afloop komt een samenvatting als er nog iets speelt.')}</p>
      ${active ? `<div class="callout">🌙 ${L('Nu stil tot {t}', { t: esc(fmtUntil(q.now)) })}</div>` : q && q.now ? `<p class="muted small">${L('Nu al stil via {via}: {name}, tot {t}', { via: esc(QUIET_SRC()[q.src] || q.src), name: esc(q.name), t: esc(fmtUntil(q.now)) })}</p>` : ''}
      <div class="qn-btns">${[[60, L('1 uur')], [240, L('4 uur')], [480, L('8 uur')]].map(([m, l]) => `<button type="button" class="btn" data-qn-min="${m}">${l}</button>`).join('')}
        <button type="button" class="btn" data-qn-until="${local(tomorrow)}">${L('tot morgen 07:00')}</button></div>
      <label>${L('Of tot')}<input type="datetime-local" name="until" value="${local(new Date(Date.now() + 2 * 3600e3))}"></label>`,
    buttons: [...(active ? [{ label: '✕ ' + L('Opheffen'), cls: 'danger', style: 'margin-right:auto', onClick: () => go({}) }] : []),
      { label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🌙 ' + L('Stil tot dit tijdstip'), cls: 'primary', submit: true }],
    onSubmit: (f) => go({ until: f.until }),
  });
  async function go(x) {
    try { await api('quiet_now', { what, id: +id, ...x }); $('#modal').close(); toast(x.minutes || x.until ? L('Stil gezet') : L('Nu stil opgeheven')); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  }
  $('#modal .qn-btns').onclick = (e) => {
    const m = e.target.closest('[data-qn-min]'); if (m) go({ minutes: +m.dataset.qnMin });
    const u = e.target.closest('[data-qn-until]'); if (u) go({ until: u.dataset.qnUntil });
  };
}
let quietData = null;
async function loadQuiet() {
  const box = $('#quiet-box');
  if (!box) return;
  try { quietData = await api('quiet_get'); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  const d = quietData;
  state.quiet_profiles = d.profiles.map((p) => ({ id: p.id, name: p.name, icon: p.icon }));
  const admin = isAdmin();
  const prof = (p) => {
    const when = [p.windows.length ? quietText(p.windows) : '', p.date_from ? L('van {a} tot en met {b}', { a: fmtDay(p.date_from), b: fmtDay(p.date_to) }) : ''].filter(Boolean).join(' · ') || L('de hele dag');
    const used = p.used.map((u) => `${{ grp: '👥', kind: '🏷️', host: '🖥️', check: '✅' }[u.type] || ''} ${esc(u.type === 'kind' ? (GROUP_INFO[u.label] || [0, () => u.label])[1]() : u.label)}`);
    return `<div class="qp-row ${p.active ? 'active' : ''}">
      <span class="qp-ic">${esc(p.icon)}</span>
      <div class="qp-t"><b>${esc(p.name)}</b>${+d.global === +p.id ? ` <span class="badge">★ ${L('algemeen')}</span>` : ''}${p.active ? ` <span class="badge on">${L('nu actief')}</span>` : ''}${p.crit ? ` <span class="badge crit">🔴 ${L('critical komt door')}</span>` : ''}
        <small>${esc(when)}</small>${used.length ? `<small class="qp-used">${L('gebruikt door')}: ${used.slice(0, 8).join(' · ')}${used.length > 8 ? ' ' + L('en nog {n}', { n: used.length - 8 }) : ''}</small>` : ''}</div>
      ${admin ? `<div class="qp-acts"><button type="button" class="btn small-btn" data-qp-edit="${p.id}" title="${esc(L('Bewerken'))}" aria-label="${esc(L('Bewerken'))}">✎</button><button type="button" class="icon-btn" data-qp-del="${p.id}" title="${esc(L('Verwijderen'))}" aria-label="${esc(L('Verwijderen'))}">🗑</button></div>` : ''}
    </div>`;
  };
  const grpRow = (g) => `<tr><td>${g.type === 'grp' ? '👥 ' + esc(g.label) : (GROUP_INFO[g.label] || ['🏷️'])[0] + ' ' + esc((GROUP_INFO[g.label] || [0, () => g.label])[1]())}</td>
      <td class="muted small">${g.type === 'grp' ? L('eigen groep') : L('soort apparaat')} · ${L('{n} servers', { n: g.hosts })}</td>
      <td><select data-qg="${esc(g.key)}" ${admin ? '' : 'disabled'}>${quietOptions(g.val || '', L('volgt algemeen'))}</select></td></tr>`;
  box.innerHTML = `<div class="qp-global"><label>★ ${L('Algemeen')} <span class="hint">${L('(geldt voor alles zonder eigen keuze)')}</span>
      <select id="qp-global" ${admin ? '' : 'disabled'}><option value="">${L('geen stille uren')}</option>${d.profiles.map((p) => `<option value="${p.id}" ${+d.global === +p.id ? 'selected' : ''}>${esc(p.icon)} ${esc(p.name)}</option>`).join('')}</select></label></div>
    <h3 class="set-sub">${L('Profielen')}</h3>
    <div class="qp-list">${d.profiles.map(prof).join('') || `<p class="muted small">${L('Nog geen profielen.')}</p>`}</div>
    ${admin ? `<button type="button" class="btn small-btn" data-qp-new>＋ ${L('Nieuw profiel')}</button>` : ''}
    <h3 class="set-sub">${L('Groepen')}</h3>
    <p class="muted small">${L('Een eigen groep gaat voor de soort apparaat. Een server of check met een eigen keuze gaat daar weer voor.')}</p>
    <div class="table-scroll"><table class="vuln-table qg-table"><tbody>${d.groups.filter((g) => g.hosts || g.val).map(grpRow).join('')}</tbody></table></div>
    ${d.never.length ? `<h3 class="set-sub">🔔 ${L('Nooit stil')}</h3><p class="small">${d.never.map((u) => esc(u.type === 'kind' ? (GROUP_INFO[u.label] || [0, () => u.label])[1]() : u.label)).join(' · ')}</p>` : ''}
    ${d.now.length ? `<h3 class="set-sub">🌙 ${L('Nu stil')}</h3><div class="qp-list">${d.now.map((n) => `<div class="qp-row"><span class="qp-ic">${n.type === 'host' ? '🖥️' : '✅'}</span>
        <div class="qp-t"><b>${esc(n.label)}</b><small>${L('tot {t}', { t: esc(fmtUntil(n.until)) })}</small></div>
        <div class="qp-acts need-user"><button type="button" class="btn small-btn" data-qn-off="${n.type}:${n.id}">✕ ${L('Opheffen')}</button></div></div>`).join('')}</div>` : ''}`;
  box.onchange = async (e) => {
    const g = e.target.closest('[data-qg]');
    try {
      if (e.target.id === 'qp-global') await api('quiet_global_save', { id: +e.target.value || 0 });
      else if (g) await api('quiet_group_save', { key: g.dataset.qg, val: g.value });
      else return;
      savedFlag(e.target.closest('label, td'), true);
      loadQuiet(); refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  box.onclick = async (e) => {
    const ed = e.target.closest('[data-qp-edit]'), nw = e.target.closest('[data-qp-new]'), del = e.target.closest('[data-qp-del]'), off = e.target.closest('[data-qn-off]');
    if (ed || nw) return quietProfileDialog(ed ? d.profiles.find((p) => +p.id === +ed.dataset.qpEdit) : null);
    if (del) {
      const p = d.profiles.find((x) => +x.id === +del.dataset.qpDel);
      if (!confirm(L('Profiel "{name}" verwijderen? Wat het gebruikt, volgt daarna weer het niveau erboven.', { name: p.name }))) return;
      try { await api('quiet_profile_delete', { id: p.id }); loadQuiet(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
    }
    if (off) { const [what, id] = off.dataset.qnOff.split(':'); try { await api('quiet_now', { what, id: +id }); loadQuiet(); refresh(); } catch (ex) { toast(ex.message, 'err'); } }
  };
}
/** Een profiel maken of bewerken */
function quietProfileDialog(p) {
  p = p || { name: '', icon: '🌙', windows: [QUIET_DEFAULT()], crit: false, date_from: null, date_to: null };
  let ed = null;
  modal({
    title: '🌙 ' + (p.id ? L('Profiel bewerken') : L('Nieuw profiel')),
    body: `<div class="row2"><label>${L('Naam')}<input name="name" value="${esc(p.name)}" maxlength="60" required placeholder="${L('bv. Nacht, Kantoortijden, Vakantie')}"></label>
        <label>${L('Icoon')}<select name="icon">${QUIET_ICONS.map((i) => `<option ${p.icon === i ? 'selected' : ''}>${i}</option>`).join('')}</select></label></div>
      <label class="switch"><input type="checkbox" name="crit" ${p.crit ? 'checked' : ''}><span></span> 🔴 <b>${L('Critical komt wel door')}</b> <span class="hint">${L('(warning en onbekend blijven stil)')}</span></label>
      <label class="switch"><input type="checkbox" name="period" ${p.date_from ? 'checked' : ''}><span></span> 🏖️ <b>${L('Alleen in een periode')}</b> <span class="hint">${L('(bv. een vakantie)')}</span></label>
      <div class="row2" id="qp-dates" ${p.date_from ? '' : 'hidden'}><label>${L('Van')}<input type="date" name="date_from" value="${esc(p.date_from || '')}"></label>
        <label>${L('Tot en met')}<input type="date" name="date_to" value="${esc(p.date_to || '')}"></label></div>
      <label class="switch" id="qp-allday-wrap" ${p.date_from ? '' : 'hidden'}><input type="checkbox" name="allday" ${p.date_from && !p.windows.length ? 'checked' : ''}><span></span> ${L('De hele dag (geen tijden)')}</label>
      <div id="qp-win"></div>`,
    wide: true,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (f) => {
      const period = !!f.period, allday = period && !!f.allday;
      await api('quiet_profile_save', { id: p.id || null, name: f.name, icon: f.icon, crit: !!f.crit, windows: allday ? [] : ed.get().windows,
        date_from: period ? f.date_from : null, date_to: period ? f.date_to : null });
      toast(L('Opgeslagen')); loadQuiet(); refresh();
    },
  });
  ed = quietEditor($('#qp-win'), { windows: p.windows.length ? p.windows : [QUIET_DEFAULT()] }, false);
  const sync = () => {
    const per = $('#modal [name=period]').checked;
    $('#qp-dates').hidden = !per; $('#qp-allday-wrap').hidden = !per;
    $('#qp-win').hidden = per && $('#modal [name=allday]').checked;
  };
  $('#modal [name=period]').onchange = sync; $('#modal [name=allday]').onchange = sync; sync();
}

// ---------------------------------------------------------------- ℹ️ Systeeminfo: per melding de kanalen
const SYSINFO_ROWS = [
  ['RzSense', [['rzmon_update', '⬆ ' + L('RzSense bijgewerkt')]]],
  [L('Agents'), [['agent_update', '⬆ ' + L('Agent bijgewerkt')], ['agent_update_fail', '✘ ' + L('Agent bijwerken mislukt')], ['agent_connect', '🔌 ' + L('Agent verbonden')],
    ['agent_sec', '⚠️ ' + L('Agent verbindt niet veilig (en weer veilig)')]]],
  [L('Inloggen'), [['login_ok', '🔑 ' + L('Ingelogd')], ['login_fail', '❌ ' + L('Mislukte login')], ['server_login', '🖥️ ' + L('Login op een server, van binnen (SSH of console)')]]],
  [L('Blokkades'), [['account_lock', '🔒 ' + L('Account geblokkeerd')], ['account_unlock', '🔓 ' + L('Account gedeblokkeerd')],
    ['ip_block', '⛔ ' + L('IP geblokkeerd')], ['ip_unblock', '✅ ' + L('Blokkade opgeheven')]]],
  [L('Kwetsbaarheden'), [['vuln_new', '🐞 ' + L('Nieuwe kwetsbaarheden')], ['vuln_buzz', '🔥 ' + L('Een kwetsbaarheid op je servers krijgt veel aandacht')], ['vuln_upgrade', '📦 ' + L('Pakketten bijgewerkt of mislukt')], ['advice_crit', '🚨 ' + L('Kritiek advies (wereldwijd bekende kwetsbaarheid)')]]],
  [L('Beveiliging'), [['cert', '🔐 ' + L('Certificaat verloopt binnenkort')], ['breach', '🔓 ' + L('Datalek (zonder wachtwoorden of eerste controle)')],
    ['local_only', '🏠 ' + L('Inlogpoging van buiten op een lokaal account')], ['sec', '⚠️ ' + L('Niet-geautoriseerde verbinding')]]],
  [L('Onzichtbaar van buiten'), [['device_request', '⏳ ' + L('Geweigerd apparaat van buiten (wachtend)')], ['device_trust', '📱 ' + L('Apparaat toegestaan of ingetrokken')], ['passkey', '🔑 ' + L('Passkey aangemaakt of verwijderd')],
    ['stealth_door', '🚪 ' + L('Noodlink aangevraagd of gebruikt')]]],
  ['IDS', [['isolate', '🧱 ' + L('Server geïsoleerd of opgeheven')]]],
  [L('Netwerk'), [['net_new', '🆕 ' + L('Nieuw apparaat op het netwerk')], ['net_traffic', '📈 ' + L('Ongewoon netwerkverkeer (veel of \'s nachts)')], ['net_cap', '📶 ' + L('Datalimiet internet bijna of helemaal op')], ['path_change', '🛣️ ' + L('Pad naar een doel gewijzigd (pad-check, als die dat mag)')]]],
  [L('Checks'), [['smart_drift', '🧠 ' + L('Normaalwaarde stijgt sluipend (slimme drempels)')], ['smart_auto', '🧠 ' + L('Drempels zelf aangepast (naar slim)')], ['alert_auto', '🔔 ' + L('Melden na N zelf aangepast')], ['heal', '🩹 ' + L('Zelf hersteld (of herstel mislukt)')],
    ['watchdog', '🐕 ' + L('Waakhond: RzSense was onbereikbaar of de checks stonden stil')], ['trend', '📉 ' + L('Trend: een grens wordt binnenkort bereikt (Analyse → Trends)')], ['eol', '⏳ ' + L('End-of-life: software krijgt binnenkort of geen updates meer')]]],
  ['RzSense', [['task_fail', '⚠️ ' + L('Achtergrondtaak lukt niet (en weer gelukt)')]]],
  [L('Logs'), [['logs_limit', '📜 ' + L('Daglimiet van een server bereikt')], ['logs_full', '📜 ' + L('Maximum bereikt: oudste dag weggegooid')], ['logs_watch_off', '📜 ' + L('Vaste zoekactie te traag, uitgezet')]]],   // 1.210.0
  [L('AI-advies'), [['ai_advice', '🤖 ' + L('Samenvatting van een automatisch advies')], ['ai_week', '📅 ' + L('Weekoverzicht')], ['ai_credit', '💳 ' + L('Tegoed of budget bijna op of op')]]],   // 1.173.0
];
const SYSINFO_CH = [['pushover', '📲 Push', 'push'], ['email', '📧 ' + L('E-mail'), 'email_enabled'], ['webhook', '🏠 Home Assistant', 'ha_webhook_enabled'], ['page', '📄 ' + L('Meldingenpagina'), null]];
let sysinfoCfg = null;

function renderSysinfo() {
  const t = $('#sysinfo');
  if (!t || !sysinfoCfg) return;
  const f = $('#settings-form');
  const off = (k) => (k === 'push' ? !f.pushover_enabled.checked && !f.ntfy_enabled.checked : k && f[k] && !f[k].checked);   // kanaal staat helemaal uit
  t.innerHTML = `<thead><tr><th>${L('Melding')}</th>${SYSINFO_CH.map(([, l, k]) => `<th class="si-ch">${l}${off(k) ? ` <span class="muted small">(${L('uit')})</span>` : ''}</th>`).join('')}
    <th>${L('Push-geluid')}</th><th></th></tr></thead><tbody>
    ${SYSINFO_ROWS.map(([group, rows]) => `<tr class="si-group"><td colspan="7">${esc(group)}</td></tr>` + rows.map(([k, label]) => {
      const c = sysinfoCfg[k] || {};
      return `<tr data-si="${k}"><td>${esc(label)}${k === 'sec' ? `<div class="muted small">${L('ook tijdens stille uren')}</div>` : ''}</td>
        ${SYSINFO_CH.map(([ch]) => {
          const fixed = k === 'sec' && ch === 'page';   // de telling per IP staat op de meldingenpagina
          return `<td class="si-ch"><input type="checkbox" data-ch="${ch}" ${c[ch] || fixed ? 'checked' : ''} ${fixed ? `disabled title="${esc(L('Altijd: hier telt RzSense de pogingen per IP'))}"` : ''}></td>`;
        }).join('')}
        <td><select data-prio ${c.pushover ? '' : 'disabled'}><option value="-1" ${+c.prio < 0 ? 'selected' : ''}>🔕 ${L('stil')}</option><option value="0" ${+c.prio >= 0 ? 'selected' : ''}>🔔 ${L('normaal')}</option></select></td>
        <td><button type="button" class="icon-btn" data-si-test title="${esc(L('Testmelding sturen naar de aangevinkte kanalen'))}">🧪</button></td></tr>`;
    }).join('')).join('')}</tbody>`;
}

function initSysinfo() {
  const t = $('#sysinfo');
  if (!t) return;
  t.addEventListener('change', (e) => {
    const tr = e.target.closest('tr[data-si]');
    if (!tr || !sysinfoCfg) return;
    const c = (sysinfoCfg[tr.dataset.si] ||= {});
    if (e.target.dataset.ch) c[e.target.dataset.ch] = e.target.checked;
    if (e.target.matches('[data-prio]')) c.prio = +e.target.value;
    $('[data-prio]', tr).disabled = !c.pushover;
  });
  t.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-si-test]');
    if (!b) return;
    const tr = b.closest('tr[data-si]');
    const label = $('td', tr).firstChild.textContent.trim();
    const c = sysinfoCfg[tr.dataset.si];
    const to = SYSINFO_CH.filter(([ch]) => c[ch] || (tr.dataset.si === 'sec' && ch === 'page')).map(([, l]) => l);
    if (!to.length) return toast(L('Vink eerst minstens één kanaal aan'), 'err');
    b.disabled = true;
    try { await api('sysinfo_test', { event: tr.dataset.si, label, cfg: c }); toast(L('Testmelding verstuurd naar: {to}', { to: to.join(', ') })); }
    catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  });
  // Kanaal aan/uit (Pushover, ntfy, e-mail, webhook): "(uit)" in de kop bijwerken
  ['pushover_enabled', 'ntfy_enabled', 'email_enabled', 'ha_webhook_enabled'].forEach((k) => $('#settings-form')[k] && $('#settings-form')[k].addEventListener('change', renderSysinfo));
}

function settingsData() {
  const f = $('#settings-form');
  if (notifyGlobal) {
    const n = notifyGlobal.get();
    f.notify_rules.value = JSON.stringify(n.rules);
    f.notify_channels.value = JSON.stringify(n.channels);
    f.notify_flap.value = JSON.stringify(n.flap);
  }
  if (sysinfoCfg) f.sysinfo.value = JSON.stringify(sysinfoCfg);
  if (fimGlobal) f.ids_fim.value = JSON.stringify(fimGlobal.get());
  const d = {};
  [...f.elements].forEach((el) => {
    if (!el.name) return;
    d[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
  });
  return d;
}

async function saveSettings(e) {
  e.preventDefault();   // Enter in een veld: meteen opslaan
  if (document.activeElement && document.activeElement.form === e.target) markSettingsDirty(true, document.activeElement);
  try { await autosaveSettings(); }
  catch (ex) { toast(ex.message, 'err'); }
}

async function testPushover(e) {
  const b = e.target;
  b.disabled = true;
  try { await api('pushover_test', settingsData()); toast(L('Testbericht verstuurd! Check je telefoon') + ' 📱'); }
  catch (ex) { toast('Pushover: ' + ex.message, 'err'); }
  b.disabled = false;
}

async function testWebhook(e) {
  const b = e.target;
  b.disabled = true;
  try { await api('webhook_test', settingsData()); toast(L('Testbericht naar Home Assistant verstuurd') + ' 🏠'); }
  catch (ex) { toast('Webhook: ' + ex.message, 'err'); }
  b.disabled = false;
}

/** Testknop voor een meldkanaal, met de (nog niet opgeslagen) waarden uit het formulier. */
async function testChannel(e, action, okMsg, label) {
  const b = e.target;
  b.disabled = true;
  try {
    const r = await api(action, settingsData());
    toast(okMsg || r.message);
    if (action === 'mqtt_test') loadSettings();
  } catch (ex) { toast(`${label}: ${ex.message}`, 'err'); }
  b.disabled = false;
}

async function changePassword(e) {
  e.preventDefault();
  try {
    const r = await api('password', Object.fromEntries(new FormData(e.target)));
    e.target.reset();
    toast(L('Wachtwoord gewijzigd'));
    pwnedWarn(r);
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Gebruikers & tweestapsverificatie
let users = [];
let usersHibp = false;   // datalek-controle staat aan (hoofdschakelaar)
const ROLE_LABEL = { admin: '🔑 ' + L('Beheerder'), user: '👤 ' + L('Gebruiker'), readonly: '👁 ' + L('Alleen lezen') };
async function loadUsers() {
  if ($('#tab-account') && !$('#tab-account').hidden) loadAccount();   // bv. na 2FA instellen vanaf Mijn account
  if ($('#users-card').hidden || !isAdmin()) return;
  const ur = await api('users');
  users = ur.users;
  usersHibp = ur.hibp;
  if (isAdmin()) {
    api('pw_reset_info').then((r) => {
      $('#pw-reset-info').innerHTML = (r.unavailable
        ? `<span class="warn-txt">⚠️ ${L('Wachtwoord vergeten werkt nu niet: {reason}.', { reason: esc(r.unavailable) })}</span> `
        : '🔑 ' + L('Wachtwoord vergeten werkt voor accounts met een e-mailadres.') + ' ')
        + `<a href="#" id="pw-reset-log">${L('Logboek bekijken ({n})', { n: r.log.length })}</a>`;
      $('#pw-reset-log').onclick = (e) => { e.preventDefault(); pwResetLog(r.log); };
    }).catch(() => {});
  }
  $('#users').innerHTML = users.map((u) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(u.username)}</strong>${u.me ? ` <span class="badge">${L('jij')}</span>` : ''}
        <span class="role-tag r-${esc(u.role)}">${ROLE_LABEL[u.role] || esc(u.role)}</span>
        ${u.totp ? `<span class="tfa on" title="${L('Tweestapsverificatie staat aan')}">🔐 ${L('2FA aan')}</span>` : `<span class="tfa" title="${L('Alleen wachtwoord')}">${L('2FA uit')}</span>`}
        ${u.locked ? `<span class="locked-tag" title="${esc(L('Te veel mislukte pogingen vanaf {ip}; vanaf het lokale netwerk kan inloggen nog wel', { ip: u.locked_ip || '?' }))}">🔒 ${u.locked_until ? L('geblokkeerd tot {t}', { t: esc(fmtDateTime(u.locked_until)) }) : L('geblokkeerd')}</span>` : ''}
        ${u.local_only ? `<span class="u-tag local" title="${L('Mag alleen vanaf je interne netwerken inloggen (Toegang → Lokale netwerken)')}">🏠 ${L('alleen lokaal')}</span>` : ''}
        ${usersHibp && !u.hibp ? `<span class="u-tag" title="${L('Wachtwoord en e-mailadres van dit account worden niet gecontroleerd')}">🔕 ${L('geen datalek-controle')}</span>` : ''}
        ${usersHibp && u.hibp && +u.pw_pwned > 0 ? `<span class="u-tag bad" title="${L('Bij het inloggen moet eerst een nieuw wachtwoord worden gekozen')}">🔓 ${L('wachtwoord gelekt ({n}×)', { n: (+u.pw_pwned).toLocaleString(LOCALE) })}</span>` : ''}
        ${usersHibp && u.hibp && u.pw_pwned !== null && +u.pw_pwned === 0 ? `<span class="u-tag ok" title="${esc(L('Gecontroleerd {ago}', { ago: ago(u.pw_pwned_at) }))}">✔ ${L('wachtwoord niet gelekt')}</span>` : ''}<br>
        <span class="muted small">${u.email ? `✉ ${esc(u.email)}` : `<span title="${L('Nodig voor wachtwoord vergeten')}">✉ ${L('geen e-mailadres')}</span>`} · ${L('Laatst ingelogd: {ago}', { ago: u.last_login ? ago(u.last_login) : L('nooit') })}</span></span>
      <span class="row-actions">
        ${u.me ? `<button class="btn" data-uact="mine" data-id="${u.id}" title="${L('Je eigen wachtwoord, 2FA, e-mail en apparaten')}">👤 ${L('Mijn account')}</button>${userFlags(u)}` : `${u.totp
          ? `<button class="btn" data-uact="totp-reset" data-id="${u.id}" title="${L('Tweestapsverificatie uitzetten, bv. bij een verloren telefoon')}">${L('2FA resetten')}</button>`
          : `<button class="btn" data-uact="totp-setup" data-id="${u.id}">🔐 ${L('2FA instellen')}</button>`}
        ${u.locked ? `<button class="btn need-admin" data-uact="unlock" data-id="${u.id}">🔓 ${L('Deblokkeren')}</button> <a class="btn need-admin" href="#ids/blk" data-goto="ids/blk" title="${esc(L('Overzicht van alle blokkades, met de whitelist'))}">⛔ ${L('Blokkades')}</a>` : ''}
        ${u.me ? '' : `<select class="role-select need-admin" data-uact="role" data-id="${u.id}" title="${L('Rol wijzigen')}">
          ${Object.entries(ROLE_LABEL).map(([k, l]) => `<option value="${k}" ${u.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select>`}
        <button class="btn small-btn" data-uact="devices" data-id="${u.id}" title="${L('Ingelogde apparaten van {user}', { user: esc(u.username) })}">📱 ${+u.devices}</button>
        ${userFlags(u)}
        <button class="icon-btn" data-uact="email" data-id="${u.id}" title="${L('E-mailadres (voor wachtwoord vergeten)')}">✉</button>
        <button class="icon-btn need-admin" data-uact="password" data-id="${u.id}" title="${L('Nieuw wachtwoord instellen')}">🔑</button>
        <button class="icon-btn need-admin" data-uact="delete" data-id="${u.id}" title="${L('Verwijderen')}">🗑</button>`}
      </span>
    </div>`).join('');
  loadSessionsAll();
  settingsSummaries();
}

/** Per account: alleen lokaal (🏠) en de datalek-controle (🔓 aan, 🔕 uit); alleen beheerders */
function userFlags(u) {
  return `<button class="icon-btn need-admin flag-btn ${u.local_only ? 'on' : ''}" data-uact="flag-local" data-id="${u.id}" title="${esc(u.local_only ? L('Alleen lokaal: aan (klik om uit te zetten)') : L('Alleen vanaf de interne netwerken laten inloggen (bv. een noodaccount)'))}">🏠</button>`
    + (usersHibp ? `<button class="icon-btn need-admin flag-btn ${u.hibp ? 'on' : ''}" data-uact="flag-hibp" data-id="${u.id}" title="${esc(u.hibp ? L('Datalek-controle: aan (klik om uit te zetten)') : L('Datalek-controle: uit (klik om aan te zetten)'))}">${u.hibp ? '🔓' : '🔕'}</button>` : '');
}

/** Logboek van "wachtwoord vergeten": aanvragen, verstuurde links en herstelde wachtwoorden. */
function pwResetLog(log) {
  modal({
    title: '🔑 ' + L('Wachtwoord vergeten – logboek'),
    wide: true,
    body: log.length ? `<div class="table-scroll"><table class="port-table"><thead><tr><th>${L('Wanneer')}</th><th>${L('Account')}</th><th>${L('Ingevuld')}</th><th>${L('IP-adres')}</th><th>${L('Wat')}</th></tr></thead><tbody>
      ${log.map((l) => `<tr><td title="${esc(l.created_at)}">${ago(l.created_at)}</td><td>${l.username ? esc(l.username) : '<span class="muted">–</span>'}</td>
        <td class="mono small">${esc(l.ident)}</td><td class="mono small">${esc(l.ip)}</td><td>${esc(l.event)}</td></tr>`).join('')}</tbody></table></div>
      <p class="muted small">${L('De laatste 50 regels; ouder dan 90 dagen wordt opgeruimd.')}</p>`
      : `<p class="muted">${L('Nog geen aanvragen.')}</p>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
}

async function createUser(e) {
  e.preventDefault();
  const f = e.target;
  try {
    const r = await api('user_create', { username: f.username.value, password: f.password.value, email: f.email.value, role: f.role.value });
    const name = f.username.value;
    const withTotp = f.totp.checked;
    f.reset();
    toast(L('Gebruiker {name} aangemaakt', { name }));
    pwnedWarn(r);
    await loadUsers();
    if (withTotp) totpSetup(r.id, name);
  } catch (ex) { toast(ex.message, 'err'); }
}

async function onUsersClick(e) {
  const b = e.target.closest('button[data-uact]');
  if (!b) return;
  const u = users.find((x) => +x.id === +b.dataset.id);
  try {
    switch (b.dataset.uact) {
      case 'totp-setup': return totpSetup(u.id, u.username);
      case 'devices': return devicesDialog(u);
      case 'flag-local':
        if (!u.local_only && !confirm(L('{user} mag dan alleen nog vanaf je interne netwerken inloggen (Toegang → Lokale netwerken). Doorgaan?', { user: u.username }))) return;
        await api('user_flags', { id: u.id, local_only: !u.local_only });
        toast(!u.local_only ? L('{user}: alleen lokaal', { user: u.username }) : L('{user} mag weer overal inloggen', { user: u.username }));
        return loadUsers();
      case 'flag-hibp':
        await api('user_flags', { id: u.id, hibp: !u.hibp });
        toast(!u.hibp ? L('Datalek-controle aan voor {user}', { user: u.username }) : L('Datalek-controle uit voor {user}', { user: u.username }));
        return loadUsers();
      case 'mine': return showTab('account');
      case 'unlock':
        await api('user_unlock', { id: u.id });
        toast(L('{user} gedeblokkeerd', { user: u.username }));
        return loadUsers();
      case 'totp-reset':
        return totpResetDialog(u, L('Tweestapsverificatie van {user} resetten?', { user: u.username }) + ' ' + L('Inloggen kan daarna weer met alleen het wachtwoord, tot 2FA opnieuw is ingesteld.'),
          () => { toast(L('2FA van {user} gereset', { user: u.username })); loadUsers(); });
      case 'delete':
        if (!confirm(L('Gebruiker {user} verwijderen?', { user: u.username }))) return;
        await api('user_delete', { id: u.id });
        toast(L('{name} verwijderd', { name: u.username }));
        return loadUsers();
      case 'email':
        return emailDialog(u, loadUsers);
      case 'password':
        return modal({
          title: `${L('Nieuw wachtwoord')} – ${u.username}`,
          body: `<label>${L('Nieuw wachtwoord')}<input type="password" name="password" minlength="8" required autocomplete="new-password" autofocus></label>`,
          buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
          onSubmit: async (d) => { const r = await api('user_password', { id: u.id, password: d.password }); toast(L('Wachtwoord gewijzigd')); pwnedWarn(r); loadUsers(); },
        });
    }
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Ingelogde apparaten (1.79.0)
const SESS_HOW = {
  password: () => '🔑 ' + L('wachtwoord'), '2fa': () => '🔐 ' + L('wachtwoord + 2FA'), passkey: () => '🗝️ ' + L('passkey'), remember: () => '📌 ' + L('onthouden apparaat'),
  lan: () => '🏠 ' + L('LAN, zonder wachtwoord'), existing: () => L('al ingelogd'),
};
const devIcon = (d) => (/iPhone|Android/.test(d) ? '📱' : /iPad/.test(d) ? '📲' : '💻');

/** Tabel met ingelogde apparaten; withUser: met de gebruiker erbij (overzicht voor beheerders) */
function sessionRows(list, withUser) {
  if (!list.length) return `<p class="muted" style="margin:0">${L('Geen ingelogde apparaten.')}</p>`;
  return `<div class="table-scroll"><table class="vuln-table sess-table"><thead><tr>${withUser ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Apparaat')}</th><th>IP</th><th>${L('Hoe')}</th><th>${L('Sinds')}</th><th>${L('Laatst actief')}</th><th></th></tr></thead><tbody>
    ${list.map((s) => `<tr class="${s.kind === 'device' ? 'sess-idle' : ''}">${withUser ? `<td><b>${esc(s.user_id === null ? L('lokaal netwerk') : s.username)}</b></td>` : ''}
      <td>${devIcon(s.device)} ${esc(s.device || L('Onbekend apparaat'))}${s.current ? ` <span class="badge">${L('dit apparaat')}</span>` : ''}
        ${s.kind === 'device' ? `<br><span class="muted small">💤 ${L('onthouden, nu niet actief')}</span>` : s.remembered ? ` <span title="${esc(L('Onthouden: blijft ingelogd zonder wachtwoord'))}">📌</span>` : ''}</td>
      <td class="mono small">${esc(s.ip)} <span title="${esc(s.internal ? L('Binnen je netwerk') : L('Van buiten je netwerk'))}">${s.internal ? '🏠' : '🌍'}</span></td>
      <td class="small">${SESS_HOW[s.how] ? SESS_HOW[s.how]() : esc(s.how)}</td>
      <td class="small">${esc(fmtDateTime(s.since))}</td><td class="small">${s.current ? L('nu') : ago(s.last)}</td>
      <td>${s.current ? '' : `<button type="button" class="btn small-btn" data-sess="${s.kind}:${s.id}">${s.kind === 'device' ? L('Vergeten') : L('Uitloggen')}</button>`}</td></tr>`).join('')}</tbody></table></div>`;
}

/** Uitloggen (sessie) of vergeten (onthouden apparaat zonder actieve sessie) */
async function sessionAction(btn, done) {
  const [kind, id] = btn.dataset.sess.split(':');
  btn.disabled = true;
  try {
    await api(kind === 'device' ? 'device_forget' : 'session_logout', { id: +id });
    toast(kind === 'device' ? L('Apparaat vergeten') : L('Uitgelogd'));
    done();
  } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
}

/** Overzicht voor beheerders: alle ingelogde apparaten, te filteren op gebruiker */
let sessUser = '';
async function loadSessionsAll() {
  const box = $('#sess-all');
  if (!box || !isAdmin()) return;
  let r;
  try { r = await api('sessions_all'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const names = [...new Map(r.sessions.map((s) => [s.user_id === null ? 'lan' : String(s.user_id), s.user_id === null ? L('lokaal netwerk') : s.username])).entries()];
  if (sessUser && !names.some(([k]) => k === sessUser)) sessUser = '';
  $('#sess-user').innerHTML = `<option value="">${L('Alle gebruikers ({n})', { n: r.sessions.length })}</option>`
    + names.map(([k, n]) => `<option value="${k}" ${k === sessUser ? 'selected' : ''}>${esc(n)} (${r.sessions.filter((s) => (s.user_id === null ? 'lan' : String(s.user_id)) === k).length})</option>`).join('');
  const list = r.sessions.filter((s) => !sessUser || (s.user_id === null ? 'lan' : String(s.user_id)) === sessUser);
  box.innerHTML = sessionRows(list, true);
  $('#sess-user-out').hidden = !sessUser || sessUser === 'lan';
  $('#sess-user').onchange = (e) => { sessUser = e.target.value; loadSessionsAll(); };
  box.onclick = (e) => { const b = e.target.closest('[data-sess]'); if (b) sessionAction(b, () => { loadSessionsAll(); loadUsers(); }); };
  $('#sess-user-out').onclick = async () => {
    const n = names.find(([k]) => k === sessUser)?.[1] || '';
    if (!confirm(L('Alle apparaten van {user} uitloggen (en vergeten)?', { user: n }))) return;
    try { await api('sessions_logout_user', { user_id: +sessUser }); toast(L('Alles van {user} uitgelogd', { user: n })); loadSessionsAll(); loadUsers(); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  $('#sess-all-out').onclick = () => modal({
    title: '⛔ ' + L('Iedereen uitloggen?'),
    body: `<p style="margin-top:0" class="small">${L('Alle sessies van alle gebruikers worden uitgelogd en alle onthouden apparaten vergeten, behalve dit apparaat. Handig als je denkt dat er iemand meekijkt. Iedereen moet daarna opnieuw inloggen (met 2FA als dat aan staat).')}</p>${confirmField('UITLOGGEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Iedereen uitloggen'), cls: 'danger', submit: true }],
    onSubmit: async (f) => { const x = await api('sessions_logout_all', { confirm: f.confirm }); toast(L('{n} sessies en apparaten uitgelogd', { n: x.n })); loadSessionsAll(); loadUsers(); },
  });
}

/** Ingelogde apparaten van één gebruiker: bekijken en uitloggen */
async function devicesDialog(u) {
  let list;
  try { list = await api('sessions', null, { user_id: u.id }); } catch (ex) { return toast(ex.message, 'err'); }
  modal({
    title: `📱 ${L('Ingelogde apparaten')} – ${u.username}`,
    wide: true,
    body: `${xpHtml(L('Waar {user} nu is ingelogd.'), L('Hier zie je waar {user} nu is ingelogd. <b>Onthouden apparaat (📌):</b> blijft ingelogd zonder wachtwoord en 2FA. Het wordt vanzelf vergeten na 30 dagen niet gebruiken, en bij een wachtwoordwijziging.', { user: esc(u.username) }))}
      <div id="device-list">${sessionRows(list, false)}</div>`,
    buttons: [
      ...(list.some((d) => !d.current) ? [{ label: L('Alles uitloggen'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Alle apparaten van {user} uitloggen (en vergeten)?', { user: u.username }))) return;
        try { await api('sessions_logout_user', { user_id: u.id }); toast(L('Alles van {user} uitgelogd', { user: u.username })); $('#modal').close(); loadUsers(); }
        catch (ex) { toast(ex.message, 'err'); }
      } }] : []),
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  $('#device-list').addEventListener('click', (e) => {
    const b = e.target.closest('[data-sess]');
    if (b) sessionAction(b, () => { loadUsers(); devicesDialog(u); });
  });
}

/** 1.224.1: e-mailadres voor "wachtwoord vergeten" wijzigen, met je wachtwoord (het oude adres krijgt bericht) */
function emailDialog(u, after) {
  modal({
    title: `${L('E-mailadres')} – ${u.username}`,
    body: `<p class="muted small" style="margin-top:0">${u.me ? L('Hierheen gaat de link als je wachtwoord vergeten is. Leeg = geen herstel per e-mail.') : L('Hierheen gaat de link als {user} zijn of haar wachtwoord vergeten is. Leeg = geen herstel per e-mail.', { user: esc(u.username) })}</p>
      <label>${L('E-mailadres')}<input type="email" name="email" value="${esc(u.email || '')}" maxlength="190" autocomplete="email" autofocus></label>
      ${confirmField('ACCOUNT')}`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('user_email', { id: u.id, email: d.email, confirm: d.confirm }); toast(L('E-mailadres opgeslagen')); after(); },
  });
}

/** 1.224.1: 2FA uitzetten of resetten, met je wachtwoord */
function totpResetDialog(u, question, after) {
  modal({
    title: `🔐 ${L('Tweestapsverificatie')} – ${u.username}`,
    body: `<p>${esc(question)}</p>${confirmField('ACCOUNT')}`,
    buttons: [{ label: L('Uitzetten'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { await api('totp_reset', { id: u.id, confirm: d.confirm }); after(); },
  });
}

async function totpSetup(id, username) {
  let r;
  try { r = await api('totp_setup', { id }); } catch (ex) { return toast(ex.message, 'err'); }
  let qr = '';
  try {
    const q = qrcode(0, 'M');
    q.addData(r.uri);
    q.make();
    qr = q.createSvgTag(5, 4);
  } catch (ex) { qr = `<p class="muted small">${L('QR-code maken lukte niet; gebruik de code hieronder.')}</p>`; }
  modal({
    title: `🔐 ${L('Tweestapsverificatie')} – ${username}`,
    body: `
      <ol class="steps">
        <li>${L('Open een authenticator-app op de telefoon van <b>{user}</b> en scan deze QR-code:', { user: esc(username) })}</li>
      </ol>
      <div class="qr">${qr}</div>
      <p class="muted small" style="text-align:center">${L('Scannen lukt niet? Voer deze sleutel handmatig in:')}<br><code class="secret">${esc(r.secret.replace(/(.{4})/g, '$1 ').trim())}</code></p>
      <ol class="steps" start="2">
        <li>${L('Vul de 6-cijferige code in die de app nu toont. Pas daarna staat 2FA aan.')}</li>
      </ol>
      <label>${L('Code')}<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required class="mono totp-input" autofocus></label>
      ${confirmField('ACCOUNT')}`,
    buttons: [{ label: L('Activeren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('totp_confirm', { id, code: d.code, confirm: d.confirm });
      toast(L('2FA staat aan voor {user}', { user: username }) + ' 🔐');
      loadUsers();
    },
  });
}

// ---------------------------------------------------------------- Databasewachtwoord vernieuwen
let dbInfo = null;
// ---------------------------------------------------------------- 💡 Nog niet bewaakt (1.95.0)
const DISCOVER_KIND = { disk: () => '💾 ' + L('Disks'), smart: () => '💽 ' + L('Schijfgezondheid'), service: () => '⚙️ ' + L('Services'), container: () => '📦 ' + L('Containers'),
  db: () => '🗄️ ' + L('Databases'), cert: () => '🔒 ' + L('Certificaten'), ups: () => '🔋 UPS', vpn: () => '🔐 VPN', ntp: () => '🕐 ' + L('Tijd'), port: () => '🌐 ' + L('Poorten') };
async function discoverDialog(h) {
  let d;
  try { d = await api('discover', null, { id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const groups = Object.keys(DISCOVER_KIND).map((k) => [k, d.items.filter((i) => i.kind === k)]).filter(([, l]) => l.length);
  modal({
    title: '💡 ' + L('Nog niet bewaakt – {name}', { name: h.name }),
    wide: true,
    body: `<p class="hint" style="margin-top:0">${L('Dit ziet de agent op de server, maar er is nog geen check voor. Vink aan wat je wilt bewaken; de rest kun je negeren, dan komt het niet meer terug.')}</p>
      ${groups.length ? groups.map(([k, list]) => `<h3 class="set-sub">${DISCOVER_KIND[k]()}</h3>
        ${list.map((i) => `<label class="switch"><input type="checkbox" data-dk="${esc(i.key)}" ${k === 'port' ? '' : 'checked'}><span></span> <span><b>${esc(i.label)}</b></span></label>`).join('')}`).join('')
        : `<p class="muted">${L('Alles wordt al bewaakt.')}</p>`}
      ${d.ignored ? `<p class="small muted">${L('{n} genegeerd.', { n: d.ignored })} <a href="#" id="dk-unignore">${L('Weer tonen')}</a></p>` : ''}`,
    buttons: groups.length ? [{ label: '🙈 ' + L('Gekozen negeren'), style: 'margin-right:auto', onClick: async () => {
      const keys = $$('#modal-body [data-dk]').filter((x) => x.checked).map((x) => x.dataset.dk);
      if (!keys.length) return toast(L('Niets gekozen'), 'err');
      await api('discover_ignore', { host_id: h.id, keys }); $('#modal').close(); toast(L('Genegeerd')); refresh();
    } }, { label: L('Checks toevoegen'), cls: 'primary', submit: true }] : [{ label: L('Sluiten'), cls: 'primary', submit: true }],
    onSubmit: groups.length ? async () => {
      const keys = new Set($$('#modal-body [data-dk]').filter((x) => x.checked).map((x) => x.dataset.dk));
      if (!keys.size) throw new Error(L('Niets gekozen'));
      let n = 0;
      for (const i of d.items.filter((x) => keys.has(x.key))) { await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, verify_ssl: true, ...i.data }); n++; }
      toast(n === 1 ? L('1 check toegevoegd') : L('{n} checks toegevoegd', { n }));
    } : null,
  });
  const un = $('#dk-unignore');
  if (un) un.onclick = async (e) => { e.preventDefault(); await api('discover_ignore', { host_id: h.id, keys: [], undo: true }); $('#modal').close(); refresh(); setTimeout(() => discoverDialog(findHost(h.id)), 300); };
}

// ---------------------------------------------------------------- 🧩 Sjablonen en alle checks (1.94.0)
let tplData = null;
const thrText = (c) => {
  const u = (TYPES[c.type] || {}).unit;
  return c.warn_threshold == null && c.crit_threshold == null ? '–'
    : `${c.warn_threshold == null ? '–' : dec(+c.warn_threshold)} / ${c.crit_threshold == null ? '–' : dec(+c.crit_threshold)}${u ? ' ' + esc(L(u)) : ''}`;
};
const typeLabel = (c) => esc((TYPES[c.type] || {}).label || c.type);

/** Keuze met twee knoppen: alleen loskoppelen (checks blijven) of ook de checks op de servers weghalen */
function keepOrDrop(title, text, onChoice, needConfirm = true) {
  modal({
    title,
    body: `<p style="margin-top:0">${text}</p><label class="switch"><input type="checkbox" name="drop"><span></span> ${L('Ook de checks op de servers verwijderen')}</label>
      <div id="kd-confirm" hidden>${needConfirm ? confirmField('VERWIJDEREN') : ''}</div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Doorgaan'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { await onChoice(!d.drop, d.confirm); },
  });
  const cb = $('#modal-body [name=drop]');
  cb.onchange = () => { $('#kd-confirm').hidden = !cb.checked; const i = $('#kd-confirm input'); if (i) i.required = cb.checked; };
  const i = $('#kd-confirm input'); if (i) i.required = false;
}

async function loadTemplates() {
  const box = $('#tpl-list');
  if (!box) return;
  try { tplData = await api('templates'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderTemplates();
}

function renderTemplates() {
  const box = $('#tpl-list');
  const d = tplData;
  box.innerHTML = d.templates.length ? d.templates.map((t) => {
    const linked = new Set(t.hosts.map((h) => h.id));
    return `<div class="tpl-block" data-tpl="${t.id}">
      <div class="tpl-head"><div class="grow"><h3>📋 ${esc(t.name)}</h3>${t.description ? `<p class="muted small">${esc(t.description)}</p>` : ''}</div>
        <button type="button" class="btn small-btn" data-tpl-edit="${t.id}" title="${esc(L('Naam en omschrijving'))}">✎</button>
        <button type="button" class="btn small-btn danger" data-tpl-del="${t.id}" title="${esc(L('Sjabloon verwijderen'))}">🗑</button></div>
      ${t.items.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Check')}</th><th>${L('Type')}</th><th>${L('Doel')}</th><th>${L('Drempels')}</th><th>${L('Interval')}</th><th></th></tr></thead><tbody>
        ${t.items.map((c) => `<tr><td>${esc(c.name)}${+c.enabled ? '' : ` <span class="muted small">(${L('uit')})</span>`}</td><td class="small">${typeLabel(c)}</td>
          <td class="mono small">${esc(c.target || '–')}</td><td class="small nowrap">${thrText(c)}</td><td class="small nowrap">${esc(fmtInterval(c.interval_sec))}</td>
          <td class="nowrap"><button type="button" class="btn small-btn" data-ti-edit="${c.id}" title="${esc(L('Bewerken'))}" aria-label="${esc(L('Bewerken'))}">✎</button> <button type="button" class="btn small-btn" data-ti-del="${c.id}" title="${esc(L('Verwijderen'))}" aria-label="${esc(L('Verwijderen'))}">🗑</button></td></tr>`).join('')}
        </tbody></table></div>` : `<p class="muted small">${L('Nog geen checks in dit sjabloon.')}</p>`}
      <div class="btn-row"><button type="button" class="btn small-btn" data-ti-add="${t.id}">＋ ${L('Check toevoegen')}</button></div>
      <div class="tpl-hosts"><b class="small">${L('Servers')}:</b>
        ${t.hosts.map((h) => `<span class="tpl-chip">${esc(h.name)} <button type="button" class="icon-btn" data-tpl-unlink="${t.id}" data-host="${h.id}" title="${esc(L('Loskoppelen'))}">✕</button></span>`).join('') || `<span class="muted small">${L('nog geen')}</span>`}
        <select data-tpl-link="${t.id}"><option value="">＋ ${L('Server koppelen…')}</option>${d.hosts.filter((h) => !linked.has(+h.id)).map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select>
        ${t.overridden ? `<span class="muted small">· ${t.overridden === 1 ? L('1 check op een server zelf aangepast') : L('{n} checks op servers zelf aangepast', { n: t.overridden })}</span>` : ''}</div>
    </div>`;
  }).join('') : `<p class="muted">${L('Nog geen sjablonen.')}</p>`;
}

function tplNameDialog(t) {
  modal({
    title: t ? L('Sjabloon bewerken') : L('Nieuw sjabloon'),
    body: `<label>${L('Naam')}<input name="name" value="${esc(t ? t.name : '')}" required placeholder="${esc(L('bv. Linux-webserver'))}"></label>
      <label>${L('Omschrijving')} <span class="hint">${L('(optioneel)')}</span><input name="description" value="${esc(t ? t.description : '')}"></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('template_save', { id: t ? t.id : 0, ...d }); toast(L('Opgeslagen')); setTimeout(loadTemplates, 50); },
  });
}

function tplFromHostDialog() {
  const hosts = (state.hosts || []).filter((h) => h.checks.length);
  const render = (hid) => {
    const h = findHost(+hid);
    $('#tfh-checks').innerHTML = h ? h.checks.filter((c) => !c.template_item_id).map((c) => `<label class="switch"><input type="checkbox" data-cid="${c.id}" checked><span></span> ${esc(c.name)} <span class="muted small">${typeLabel(c)}</span></label>`).join('')
      || `<p class="muted small">${L('Alle checks van deze server komen al uit een sjabloon.')}</p>` : '';
  };
  modal({
    title: L('Sjabloon maken van een server'),
    body: `<label>${L('Server')}<select name="host_id">${hosts.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select></label>
      <label>${L('Naam van het sjabloon')}<input name="name" required></label>
      <p class="hint">${L('Het adres van de server wordt in het sjabloon <code>{adres}</code>, zodat het op elke server werkt. Deze server wordt meteen gekoppeld.')}</p>
      <div id="tfh-checks"></div>`,
    buttons: [{ label: L('Maken'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const ids = $$('#tfh-checks [data-cid]').filter((x) => x.checked).map((x) => +x.dataset.cid);
      if (!ids.length) throw new Error(L('Kies minstens één check'));
      await api('template_from_host', { host_id: +d.host_id, name: d.name, check_ids: ids });
      toast(L('Sjabloon gemaakt')); setTimeout(loadTemplates, 50);
    },
  });
  const sel = $('#modal-body [name=host_id]');
  sel.onchange = () => { render(sel.value); const h = findHost(+sel.value); const n = $('#modal-body [name=name]'); if (h && !n.dataset.touched) n.value = h.name; };
  $('#modal-body [name=name]').oninput = (e) => { e.target.dataset.touched = 1; };
  sel.onchange();
}

function initTemplates() {
  if (!$('#tpl-list')) return;
  $('#tpl-new').onclick = () => tplNameDialog(null);
  $('#tpl-from-host').onclick = tplFromHostDialog;
  const box = $('#tpl-list');
  const tplOf = (id) => tplData.templates.find((t) => t.id === +id);
  const pseudoHost = (t) => ({ id: 0, name: t.name, address: '{adres}', mode: 'agent', metrics: [], snmp: true, checks: [] });
  box.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    try {
      if (b.dataset.tplEdit) return tplNameDialog(tplOf(b.dataset.tplEdit));
      if (b.dataset.tplDel) {
        const t = tplOf(b.dataset.tplDel);
        return keepOrDrop(L('Sjabloon "{name}" verwijderen', { name: t.name }), L('De checks die uit dit sjabloon komen, blijven op de servers staan (los van het sjabloon), tenzij je hieronder kiest om ze ook te verwijderen.'),
          async (keep, confirm) => { await api('template_delete', { id: t.id, keep, confirm }); toast(L('Sjabloon verwijderd')); setTimeout(loadTemplates, 50); });
      }
      if (b.dataset.tiAdd) {
        const t = tplOf(b.dataset.tiAdd);
        return checkForm(null, pseudoHost(t), {}, { id: t.id, name: t.name, onSaved: loadTemplates });
      }
      if (b.dataset.tiEdit) {
        const t = tplData.templates.find((x) => x.items.some((c) => c.id === +b.dataset.tiEdit));
        return checkForm(t.items.find((c) => c.id === +b.dataset.tiEdit), pseudoHost(t), {}, { id: t.id, name: t.name, onSaved: loadTemplates });
      }
      if (b.dataset.tiDel) {
        return keepOrDrop(L('Check uit het sjabloon halen'), L('De check verdwijnt uit het sjabloon. Op de servers blijft hij staan (los), tenzij je hieronder kiest om hem daar ook te verwijderen.'),
          async (keep) => { await api('template_item_delete', { id: +b.dataset.tiDel, keep }); toast(L('Verwijderd')); setTimeout(loadTemplates, 50); }, false);
      }
      if (b.dataset.tplUnlink) {
        return keepOrDrop(L('Server loskoppelen'), L('De server hoort niet meer bij dit sjabloon. Zijn checks blijven staan (los), tenzij je hieronder kiest om ze te verwijderen.'),
          async (keep) => { await api('template_unlink', { template_id: +b.dataset.tplUnlink, host_id: +b.dataset.host, keep }); toast(L('Losgekoppeld')); setTimeout(loadTemplates, 50); }, false);
      }
    } catch (ex) { toast(ex.message, 'err'); }
  });
  box.addEventListener('change', async (e) => {
    const sel = e.target.closest('[data-tpl-link]');
    if (!sel || !sel.value) return;
    try {
      const r = await api('template_link', { template_id: +sel.dataset.tplLink, host_id: +sel.value });
      toast(L('Gekoppeld: {c} nieuwe checks, {a} bestaande overgenomen', { c: r.created, a: r.adopted }));
      loadTemplates(); refresh();
    } catch (ex) { toast(ex.message, 'err'); sel.value = ''; }
  });
}

// ---- Alle checks: zoeken, aanvinken en meerdere tegelijk aanpassen
let acData = null;
const acSel = new Set();
async function loadAllChecks() {
  if (!$('#ac-list')) return;
  try { acData = await api('checks_all'); } catch (ex) { $('#ac-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const hs = $('#ac-host'), ts = $('#ac-type');
  const hv = hs.value, tv = ts.value;
  hs.innerHTML = `<option value="">${L('Alle servers')}</option>` + acData.hosts.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
  ts.innerHTML = `<option value="">${L('Alle types')}</option>` + [...new Set(acData.checks.map((c) => c.type))].sort().map((t) => `<option value="${t}">${esc((TYPES[t] || {}).label || t)}</option>`).join('');
  hs.value = hv; ts.value = tv;
  const ids = new Set(acData.checks.map((c) => +c.id));
  [...acSel].forEach((id) => { if (!ids.has(id)) acSel.delete(id); });
  renderAllChecks();
}

function acShown() {
  const q = setSearchNorm($('#ac-q').value), hv = $('#ac-host').value, tv = $('#ac-type').value;
  return acData.checks.filter((c) => (!hv || +c.host_id === +hv) && (!tv || c.type === tv)
    && (!q || setSearchNorm(`${c.name} ${c.target} ${c.host_name} ${c.template_name || ''}`).includes(q)));
}

function renderAllChecks() {
  const list = acShown();
  const all = list.length && list.every((c) => acSel.has(+c.id));
  $('#ac-list').innerHTML = list.length ? `<div class="table-scroll"><table class="vuln-table ac-table"><thead><tr>
      <th><input type="checkbox" id="ac-all" ${all ? 'checked' : ''} aria-label="${esc(L('Alles aanvinken'))}"></th><th>${L('Server')}</th><th>${L('Check')}</th><th>${L('Type')}</th>
      <th>${L('Drempels')}</th><th>${L('Interval')}</th><th>${L('Status')}</th><th>${L('Sjabloon')}</th></tr></thead><tbody>
    ${list.map((c) => `<tr class="${+c.enabled ? '' : 'muted'}"><td><input type="checkbox" data-ac="${c.id}" ${acSel.has(+c.id) ? 'checked' : ''}></td>
      <td class="small">${esc(c.host_name)}</td><td><a href="#" data-ac-edit="${c.id}">${esc(c.name)}</a>${+c.enabled ? '' : ` <span class="small">(${L('uit')})</span>`}
        ${c.maintenance_until && new Date(c.maintenance_until.replace(' ', 'T')) > new Date() ? ' 🔧' : ''}${c.target ? `<div class="mono small muted">${esc(c.target)}</div>` : ''}</td>
      <td class="small">${typeLabel(c)}</td><td class="small nowrap">${thrText(c)}</td><td class="small nowrap">${esc(fmtInterval(c.interval_sec))}</td>
      <td class="small">${ST_ICON[+c.status] || ''} ${esc(STATUS[+c.status] || '')}</td>
      <td class="small">${c.template_name ? `📋 ${esc(c.template_name)}${+c.tpl_override ? ` <span class="muted">(${L('aangepast')})</span>` : ''}` : '–'}</td></tr>`).join('')}
    </tbody></table></div>` : `<p class="muted">${L('Niets gevonden.')}</p>`;
  acBulkBar();
}

function acBulkBar() {
  const bar = $('#ac-bulk');
  const n = acSel.size;
  bar.hidden = !n;
  if (!n) return;
  const chosen = acData.checks.filter((c) => acSel.has(+c.id));
  const oneType = new Set(chosen.map((c) => `${c.type}|${c.metric || ''}`)).size === 1;
  bar.innerHTML = `<b>${n === 1 ? L('1 check gekozen') : L('{n} checks gekozen', { n })}</b>
    <button type="button" class="btn small-btn" data-bulk="interval">⏱ ${L('Interval')}</button>
    <button type="button" class="btn small-btn" data-bulk="thr" ${oneType ? '' : `disabled title="${esc(L('Alleen voor checks van hetzelfde type'))}"`}>🎚 ${L('Drempels')}</button>
    <button type="button" class="btn small-btn" data-bulk="smart">🧠 ${L('Slim')}</button>
    <button type="button" class="btn small-btn" data-bulk="notify">🔔 ${L('Melden')}</button>
    <button type="button" class="btn small-btn" data-bulk="maint">🔧 ${L('Onderhoud')}</button>
    <button type="button" class="btn small-btn" data-bulk="on">▶ ${L('Aan')}</button>
    <button type="button" class="btn small-btn" data-bulk="off">⏸ ${L('Uit')}</button>
    <button type="button" class="btn small-btn" data-bulk="copy">📋 ${L('Kopiëren naar…')}</button>
    <button type="button" class="btn small-btn danger" data-bulk="delete">🗑 ${L('Verwijderen')}</button>
    <button type="button" class="btn small-btn" data-bulk="none">✕ ${L('Niets kiezen')}</button>
    ${chosen.some((c) => c.template_item_id) ? `<span class="muted small">${L('Checks uit een sjabloon gaan na aanpassen niet meer mee met het sjabloon.')}</span>` : ''}`;
}

async function acBulk(kind) {
  const ids = [...acSel];
  const chosen = acData.checks.filter((c) => acSel.has(+c.id));
  const done = (r) => { toast(L('{n} checks bijgewerkt', { n: r.n })); setTimeout(() => { loadAllChecks(); refresh(); }, 50); };
  const set = (values) => api('checks_bulk', { ids, action: 'set', values }).then(done);
  if (kind === 'none') { acSel.clear(); return renderAllChecks(); }
  if (kind === 'on' || kind === 'off') return set({ enabled: kind === 'on' }).catch((ex) => toast(ex.message, 'err'));
  if (kind === 'interval') return modal({ title: L('Interval voor {n} checks', { n: ids.length }),
    body: `<label>${L('Interval')}<select name="interval_sec">${intervalOptions(chosen[0].interval_sec)}</select></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }], onSubmit: (d) => set({ interval_sec: +d.interval_sec }) });
  if (kind === 'thr') {
    const t = TYPES[chosen[0].type] || {};
    return modal({ title: L('Drempels voor {n} checks', { n: ids.length }),
      body: `<div class="row2"><label>${L('Warning')}${t.unit ? ` (${esc(L(t.unit))})` : ''}<input name="warn" type="number" step="any" value="${chosen[0].warn_threshold ?? ''}"></label>
        <label>${L('Critical')}${t.unit ? ` (${esc(L(t.unit))})` : ''}<input name="crit" type="number" step="any" value="${chosen[0].crit_threshold ?? ''}"></label></div>
        <p class="hint">${L('Leeg = geen drempel.')}</p>`,
      buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }], onSubmit: (d) => set({ warn_threshold: d.warn, crit_threshold: d.crit }) });
  }
  if (kind === 'smart') return modal({ title: L('Drempels voor {n} checks', { n: ids.length }),
    body: `<label>${L('Drempels')}<select name="mode"><option value="">${L('ongewijzigd')}</option>${Object.entries(SMART_MODES).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
      <label>${L('Gevoeligheid')}<select name="sens"><option value="-">${L('ongewijzigd')}</option><option value="">${L('standaard')}</option>${Object.entries(SMART_SENS).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
      ${xpHtml(L('Alleen checks die kunnen leren worden slim; de rest blijft zoals hij is.'), L('Dit geldt alleen voor checks die kunnen leren (ping, TCP, HTTP, CPU, RAM, SNMP); de andere blijven zoals ze zijn. Bij <i>slim met vangnet</i> krijgt een check zonder vangnet het standaard vangnet.'))}`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => set({ ...(d.mode ? { thresh_mode: d.mode } : {}), ...(d.sens !== '-' ? { smart_sens: d.sens || null } : {}) }) });
  if (kind === 'notify') return modal({ title: L('Melden voor {n} checks', { n: ids.length }),
    body: `<label>${L('Melden na zoveel mislukte checks achter elkaar')} <span class="hint">${L('(leeg = de algemene instelling)')}</span><input name="fails" type="number" min="1" max="100"></label>
      <label class="switch"><input type="checkbox" name="defaults"><span></span> ${L('Eigen meldregels weghalen: de algemene meldregels gebruiken')}</label>
      <label>${L('Alleen info (telt nooit als storing)')}<select name="info"><option value="">${L('ongewijzigd')}</option><option value="1">${L('aan')}</option><option value="0">${L('uit')}</option></select></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => set({ notify_fails: d.fails === '' ? null : +d.fails, ...(d.info !== '' ? { info_only: d.info === '1' } : {}), ...(d.defaults ? { notify_default: true } : {}) }) });
  if (kind === 'maint') return modal({ title: L('Onderhoud voor {n} checks', { n: ids.length }),
    body: `<label>${L('Hoe lang')}<select name="minutes">${[[60, L('1 uur')], [240, L('4 uur')], [1440, L('1 dag')], [10080, L('1 week')], [0, L('Onderhoud beëindigen')]].map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></label>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason"></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => api('checks_bulk', { ids, action: 'maintenance', minutes: +d.minutes, reason: d.reason }).then(done) });
  if (kind === 'copy') return modal({ title: L('{n} checks kopiëren naar…', { n: ids.length }),
    body: `${xpHtml(L('Elke gekozen server krijgt een eigen kopie van de check.'), L('Elke gekozen server krijgt een losse kopie (niet uit een sjabloon). Staat het adres van de oorspronkelijke server in het doel, dan wordt dat het adres van de nieuwe server.'))}
      ${acData.hosts.map((h) => `<label class="switch"><input type="checkbox" data-copy-to="${h.id}"><span></span> ${esc(h.name)}</label>`).join('')}`,
    buttons: [{ label: L('Kopiëren'), cls: 'primary', submit: true }],
    onSubmit: async () => {
      const to = $$('#modal-body [data-copy-to]').filter((x) => x.checked).map((x) => +x.dataset.copyTo);
      if (!to.length) throw new Error(L('Kies minstens één server'));
      const r = await api('checks_bulk', { ids, action: 'copy', host_ids: to });
      toast(L('{n} checks gekopieerd', { n: r.n })); setTimeout(() => { loadAllChecks(); refresh(); }, 50);
    } });
  if (kind === 'delete') return modal({ title: L('{n} checks verwijderen', { n: ids.length }),
    body: `<p style="margin-top:0">${L('De checks en hun geschiedenis worden verwijderd. Checks uit een sjabloon komen niet terug, tenzij je de server opnieuw koppelt.')}</p>${confirmField('VERWIJDEREN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Verwijderen'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { const r = await api('checks_bulk', { ids, action: 'delete', confirm: d.confirm }); acSel.clear(); toast(L('{n} checks verwijderd', { n: r.n })); setTimeout(() => { loadAllChecks(); refresh(); }, 50); } });
}

function initAllChecks() {
  if (!$('#ac-list')) return;
  ['#ac-q', '#ac-host', '#ac-type'].forEach((s) => { $(s).addEventListener(s === '#ac-q' ? 'input' : 'change', renderAllChecks); });
  $('#ac-list').addEventListener('change', (e) => {
    if (e.target.id === 'ac-all') { acShown().forEach((c) => (e.target.checked ? acSel.add(+c.id) : acSel.delete(+c.id))); return renderAllChecks(); }
    const cb = e.target.closest('[data-ac]');
    if (cb) { cb.checked ? acSel.add(+cb.dataset.ac) : acSel.delete(+cb.dataset.ac); acBulkBar(); }
  });
  $('#ac-list').addEventListener('click', (e) => {
    const a = e.target.closest('[data-ac-edit]');
    if (!a) return;
    e.preventDefault();
    const c = acData.checks.find((x) => +x.id === +a.dataset.acEdit);
    const h = findHost(+c.host_id);
    const full = h && h.checks.find((x) => +x.id === +c.id);
    if (full) checkForm(full, h);
  });
  $('#ac-bulk').addEventListener('click', (e) => { const b = e.target.closest('[data-bulk]'); if (b) acBulk(b.dataset.bulk); });
  // Na een venster (check bewerkt, sjabloon aangepast): de lijst bijwerken
  $('#modal').addEventListener('close', () => {
    if (setSection === 'allchecks' && !$('#tab-settings').hidden) setTimeout(loadAllChecks, 300);
  });
}

// ---- 🧹 Opschoonadvies (1.96.0)
const CLEANUP_KIND = { unknown: () => '❔ ' + L('Al dagen onbekend'), noisy: () => '📣 ' + L('Ruismakers'), flap: () => '🔁 ' + L('Wisselt steeds'),
  warn: () => '🟠 ' + L('Al lang in warning'), tight: () => '🎚 ' + L('Net onder de drempel'), off: () => '⏸ ' + L('Al lang uit') };
let cleanupData = null;
async function loadCleanup() {
  const box = $('#cleanup-list');
  if (!box) return;
  $('#cleanup-reload').onclick = loadCleanup;
  try { cleanupData = (await api('cleanup')).items; } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const groups = Object.keys(CLEANUP_KIND).map((k) => [k, cleanupData.filter((i) => i.kind === k)]).filter(([, l]) => l.length);
  const fixBtn = (i) => ({ fails3: `<button type="button" class="btn small-btn" data-cl-fix="fails3" data-id="${i.check_id}">${L('Melden na 3')}</button>`,
    disable: `<button type="button" class="btn small-btn" data-cl-fix="disable" data-id="${i.check_id}">⏸ ${L('Uitzetten')}</button>`,
    delete: `<button type="button" class="btn small-btn danger" data-cl-fix="delete" data-id="${i.check_id}">🗑 ${L('Verwijderen')}</button>` })[i.fix] || '';
  box.innerHTML = groups.length ? groups.map(([k, list]) => `<h3 class="set-sub">${CLEANUP_KIND[k]()} <span class="muted small">${list.length}</span></h3>
    ${list.map((i) => `<div class="cl-row"><div class="grow"><b>${esc(i.host)} · <a href="#" data-cl-edit="${i.check_id}">${esc(i.name)}</a></b> <span class="muted small">${esc((TYPES[i.type] || {}).label || i.type)}</span>
        <div class="small">${esc(i.detail)}</div><div class="muted small">💡 ${esc(i.advice)}</div></div>
      <div class="cl-act">${fixBtn(i)} <button type="button" class="btn small-btn" data-cl-ign="${esc(i.kind)}" data-id="${i.check_id}" title="${esc(L('Dit is normaal: niet meer tonen'))}">🙈</button></div></div>`).join('')}`).join('')
    : `<p class="good-txt">✔ ${L('Niets op te ruimen. Alle checks zien er gezond uit.')}</p>`;
  box.onclick = async (e) => {
    const ed = e.target.closest('[data-cl-edit]');
    if (ed) { e.preventDefault(); const h = state.hosts.find((x) => x.checks.some((c) => +c.id === +ed.dataset.clEdit)); if (h) checkForm(h.checks.find((c) => +c.id === +ed.dataset.clEdit), h); return; }
    const b = e.target.closest('[data-cl-fix], [data-cl-ign]');
    if (!b) return;
    const id = +b.dataset.id;
    try {
      if (b.dataset.clIgn) { await api('cleanup_ignore', { check_id: id, kind: b.dataset.clIgn }); toast(L('Wordt niet meer getoond')); }
      else if (b.dataset.clFix === 'fails3') { await api('checks_bulk', { ids: [id], action: 'set', values: { notify_fails: 3 } }); toast(L('Meldt nu pas na 3 mislukte checks')); }
      else if (b.dataset.clFix === 'disable') { await api('checks_bulk', { ids: [id], action: 'set', values: { enabled: false } }); toast(L('Uitgezet')); }
      else if (b.dataset.clFix === 'delete') {
        return modal({ title: L('Check verwijderen'), body: confirmField('VERWIJDEREN'), buttons: [{ label: L('Verwijderen'), cls: 'danger', submit: true }],
          onSubmit: async (d) => { await api('checks_bulk', { ids: [id], action: 'delete', confirm: d.confirm }); toast(L('Check verwijderd')); setTimeout(loadCleanup, 50); } });
      }
      loadCleanup(); refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- 📜 Wijzigingslogboek, 📦 export/import, 🧪 meldketen (1.97.0)
const CL_KIND = { setting: () => '⚙️ ' + L('Instelling'), host: () => '🖥️ ' + L('Server'), check: () => '✔ ' + L('Check'), template: () => '📋 ' + L('Sjabloon'), system: () => '🛠️ ' + L('Systeem') };
const CL_ACTION = { create: () => L('aangemaakt'), update: () => L('gewijzigd'), delete: () => L('verwijderd'), undo: () => L('teruggezet'), smart: () => L('geleerd'), info: () => '' };
async function loadChangeLog() {
  const box = $('#cl-list');
  if (!box) return;
  let d;
  try { d = await api('changelog', null, { kind: $('#cl-kind').value }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  $('#cl-days').value = String(d.days);
  const val = (v) => (v === null ? `<span class="muted">${L('leeg')}</span>` : `<code>${esc(v)}</code>`);
  box.innerHTML = d.items.length ? d.items.map((i) => `<div class="cl-row${i.undone_at ? ' muted' : ''}"><div class="grow">
      <b>${(CL_KIND[i.kind] || (() => i.kind))()}</b> ${esc(i.label)} <span class="muted small">${esc((CL_ACTION[i.action] || (() => i.action))())}</span>
      <div class="muted small">${esc(fmtDateTime(i.at))} · ${esc(i.username)} · ${esc(i.ip)}${i.undone_at ? ' · ' + L('teruggezet op {t}', { t: esc(fmtDateTime(i.undone_at)) }) : ''}</div>
      ${i.changes.length ? `<details class="small"><summary>${i.changes.length === 1 ? L('1 wijziging') : L('{n} wijzigingen', { n: i.changes.length })}</summary>
        <table class="kv">${i.changes.map(([k, o, n]) => `<tr><th class="mono">${esc(k)}</th><td>${val(o)} → ${val(n)}</td></tr>`).join('')}</table></details>` : ''}</div>
      <div class="cl-act">${i.can_undo ? `<button type="button" class="btn small-btn need-admin" data-undo="${i.id}" title="${esc(L('Terugzetten naar hoe het was'))}">↩ ${L('Terugzetten')}</button>` : ''}</div></div>`).join('')
    : `<p class="muted">${L('Nog niets.')}</p>`;
}

// ---------------------------------------------------------------- 📒 Logboek per server (1.226.0)
const AL_MOD = {
  agent: () => '🖥️ ' + L('Agent'), settings: () => '⚙️ ' + L('Instellingen'), checks: () => '✔ ' + L('Checks'), isolate: () => '🧱 ' + L('Isoleren'),
  userlock: () => '🔒 ' + L('Gebruikers'), kill: () => '⛔ ' + L('Processen'), heal: () => '🩹 ' + L('Zelf herstellen'), update: () => '⬆️ ' + L('Agent bijwerken'),
  packages: () => '📦 ' + L('Pakketten'), edr: () => '🛡️ EDR', firewall: () => '🧯 ' + L('Firewall'), logs: () => '📜 ' + L('Logs'),
  ransom: () => '🧬 ' + L('Ransomware'), patch: () => '🩹 ' + L('Updates'), sudo: () => '🔑 ' + L('Tijdelijk sudo'), appctl: () => '🧩 ' + L('Programma\'s'),
  ioc: () => '🔎 ' + L('Sporen'), dns: () => '🌐 DNS', harden: () => '🧰 ' + L('Hardening'),
};
const AL_SRC = { user: ['🧑', () => L('Beheerder')], rzsense: ['🤖', () => 'RzSense'], agent: ['🖥️', () => L('Agent')] };
/** Rijen van het logboek; withHost: de server erbij (overzicht van alle servers) */
function actlogRows(items, withHost) {
  if (!items.length) return `<p class="muted">${L('Niets in deze periode.')}</p>`;
  let day = '';
  return items.map((i) => {
    const d = (i.at || '').slice(0, 10), src = AL_SRC[i.source] || ['•', () => i.source];
    const head = d !== day ? `<div class="al-day">${esc(new Date(d + 'T12:00').toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long' }))}</div>` : '';
    day = d;
    const okTxt = i.ok === true ? `<span class="al-ok good" title="${esc(L('gelukt'))}">✔</span>` : i.ok === false ? `<span class="al-ok bad" title="${esc(L('mislukt'))}">✘</span>` : '<span class="al-ok muted">·</span>';
    return `${head}<div class="al-row${i.ok === false ? ' al-fail' : ''}${i.undone ? ' muted' : ''}">
      <span class="al-time mono">${esc((i.at || '').slice(11, 16))}</span>${okTxt}
      <div class="grow"><div><span class="al-mod">${esc((AL_MOD[i.module] || (() => i.module))())}</span> <b>${esc(i.action)}</b>
        ${withHost && i.host ? `<button type="button" class="linklike small" data-al-host="${i.host_id}">${esc(i.host)}</button>` : ''}</div>
        ${i.detail ? `<div class="muted small al-detail">${esc(i.detail)}</div>` : ''}
        <div class="muted small">${src[0]} ${esc(i.username || src[1]())}${i.ip ? ' · ' + esc(i.ip) : ''}${i.kind === 'change' ? ` · <button type="button" class="linklike" data-al-cl>${L('in het wijzigingslogboek')}${i.undone ? ' (' + L('teruggezet') + ')' : ''}</button>` : ''}</div></div></div>`;
  }).join('');
}
function actlogParams(prefix = 'al') {
  const v = (id) => ($('#' + prefix + '-' + id) || {}).value || '';
  return { host_id: v('host') || '0', module: v('module'), source: v('source'), days: v('days') || '30', q: v('q'), ok: ($('#' + prefix + '-fail') || {}).checked ? '0' : '' };
}
async function loadActLog() {
  const box = $('#al-list');
  if (!box) return;
  const hs = $('#al-host');
  if (hs.options.length <= 1) {
    for (const h of [...state.hosts].sort((a, b) => a.name.localeCompare(b.name))) hs.insertAdjacentHTML('beforeend', `<option value="${h.id}">${esc(h.name)}</option>`);
    for (const k of Object.keys(AL_MOD)) $('#al-module').insertAdjacentHTML('beforeend', `<option value="${k}">${esc(AL_MOD[k]())}</option>`);
  }
  const p = actlogParams();
  $('#al-csv').href = 'api.php?' + new URLSearchParams({ action: 'actlog_csv', ...p });
  let d;
  try { d = await api('actlog', null, p); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if ($('#al-keep')) $('#al-keep').value = String(d.days);
  box.innerHTML = actlogRows(d.items, +p.host_id === 0);
}
/** Agent Manager: het logboek van één server (laatste 30 dagen), met filter */
async function amActLog(h, body) {
  const box = $('#am-actlog', body);
  if (!box) return;
  if (document.body.dataset.role === 'readonly') { box.closest('.am-card').remove(); return; }   // alleen lezen: geen logboek
  const sel = $('#am-al-module', body), fail = $('#am-al-fail', body);
  const load = async () => {
    try {
      const d = await api('actlog', null, { host_id: h.id, days: 30, module: sel.value, ok: fail.checked ? '0' : '', limit: 150 });
      box.innerHTML = actlogRows(d.items, false);
    } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
  };
  if (sel.options.length <= 1) for (const k of Object.keys(AL_MOD)) sel.insertAdjacentHTML('beforeend', `<option value="${k}">${esc(AL_MOD[k]())}</option>`);
  sel.onchange = load;
  fail.onchange = load;
  $('#am-al-all', body).onclick = () => { $('#modal').close(); showTab('settings/actlog'); setTimeout(() => { const hs = $('#al-host'); if (hs) { hs.value = String(h.id); loadActLog(); } }, 300); };
  box.addEventListener('click', (e) => { if (e.target.closest('[data-al-cl]')) { $('#modal').close(); showTab('settings/changelog'); } }, { signal: modalSignal() });
  load();
}

function initSystemTools() {
  if ($('#al-list')) {   // 1.226.0
    let t;
    const again = () => { clearTimeout(t); t = setTimeout(loadActLog, 250); };
    ['al-host', 'al-module', 'al-source', 'al-days', 'al-fail'].forEach((id) => { $('#' + id).onchange = loadActLog; });
    $('#al-q').oninput = again;
    $('#al-reload').onclick = loadActLog;
    if ($('#al-keep')) $('#al-keep').onchange = async (e) => { try { await api('actlog_days', { days: +e.target.value }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); } };
    $('#al-list').addEventListener('click', (e) => {
      const hb = e.target.closest('[data-al-host]');
      if (hb) { $('#al-host').value = hb.dataset.alHost; loadActLog(); return; }
      if (e.target.closest('[data-al-cl]')) showTab('settings/changelog');
    });
  }
  if ($('#cl-list')) {
    $('#cl-reload').onclick = loadChangeLog;
    $('#cl-kind').onchange = loadChangeLog;
    $('#cl-days').onchange = async (e) => { try { await api('changelog_days', { days: +e.target.value }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); } };
    $('#cl-list').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-undo]');
      if (!b || !confirm(L('Deze wijziging terugzetten?'))) return;
      try { const r = await api('changelog_undo', { id: +b.dataset.undo }); toast(r.message); loadChangeLog(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
    });
  }
  if ($('#tr-export')) {
    $('#tr-export').onclick = () => { location.href = 'api.php?' + new URLSearchParams({ action: 'export', settings: $('#tr-settings').checked ? '1' : '0' }); };
    $('#tr-import').onclick = () => $('#tr-file').click();
    $('#tr-file').onchange = async (e) => {
      const f = e.target.files[0];
      e.target.value = '';
      if (!f) return;
      let data;
      try { data = JSON.parse(await f.text()); } catch (ex) { return toast(L('Dit is geen geldig JSON-bestand'), 'err'); }
      const withSettings = $('#tr-settings').checked;
      let pre;
      try { pre = (await api('import', { data, settings: withSettings, apply: false })).counts; } catch (ex) { return toast(ex.message, 'err'); }
      modal({
        title: '⬆ ' + L('Importeren: {f}', { f: f.name }),
        body: `<p style="margin-top:0">${L('Dit gebeurt er:')}</p><table class="kv">
          <tr><th>${L('Servers')}</th><td>${L('{a} nieuw, {b} bestaan al (blijven zoals ze zijn)', { a: pre.hosts_new, b: pre.hosts_existing })}</td></tr>
          <tr><th>${L('Sjablonen')}</th><td>${L('{a} nieuw, {b} bestaan al (blijven zoals ze zijn)', { a: pre.templates_new, b: pre.templates_existing })}</td></tr>
          <tr><th>${L('Checks')}</th><td>${L('{a} nieuw, {b} bestaan al (overgeslagen)', { a: pre.checks_new, b: pre.checks_existing })}</td></tr>
          ${withSettings ? `<tr><th>${L('Instellingen')}</th><td>${L('{n} overgenomen', { n: pre.settings })}</td></tr>` : ''}</table>
          <p class="hint">${L('Nieuwe agent-servers moet je daarna nog koppelen (Agent Manager → Koppelcode).')}</p>${confirmField('IMPORTEREN')}`,
        buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Importeren'), cls: 'primary', submit: true }],
        onSubmit: async (dd) => {
          const r = await api('import', { data, settings: withSettings, apply: true, confirm: dd.confirm });
          toast(L('Geïmporteerd: {h} servers, {c} checks, {t} sjablonen', { h: r.counts.hosts_new, c: r.counts.checks_new, t: r.counts.templates_new }));
        },
      });
    };
  }
  if ($('#chain-test')) {
    $('#chain-test').onclick = async (e) => {
      const b = e.currentTarget;
      b.disabled = true;
      $('#chain-out').innerHTML = `<p class="muted">${L('Bezig…')}</p>`;
      try {
        const r = (await api('chain_test', {})).channels;
        const names = { pushover: '📲 Pushover', ntfy: '📣 ntfy', email: '📧 ' + L('E-mail'), webhook: '🏠 ' + L('Home Assistant-webhook'), mqtt: '📡 MQTT', page: '📄 ' + L('Meldingenpagina') };
        $('#chain-out').innerHTML = `<ul class="checklist" style="margin-top:.8rem">${Object.entries(names).map(([k, l]) => {
          const c = r[k];
          return `<li class="${c === null ? 'warn' : c.ok ? 'ok' : 'bad'}"><span class="ic">${c === null ? '–' : c.ok ? '✓' : '✕'}</span><span><strong>${esc(l)}</strong><br>
            <span class="muted small">${c === null ? L('staat uit') : c.ok ? L('verstuurd') : esc(c.error || L('mislukt'))}</span></span></li>`;
        }).join('')}</ul>`;
      } catch (ex) { $('#chain-out').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
      b.disabled = false;
    };
  }
}

// ---------------------------------------------------------------- 📡 Verbindingen en taken (1.91.0)
const tsAgo = (ts) => (ts ? ago(new Date(ts * 1000).toISOString()) : L('nooit'));
const tsWhen = (ts) => (ts ? new Date(ts * 1000).toLocaleString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '–');
const TASK_ST = { ok: ['ok', '✔ ' + L('loopt')], warn: ['bad', '! ' + L('laatste poging mislukt')], bad: ['bad', '✘ ' + L('lukt niet')], wait: ['', L('nog niet gedaan')], off: ['', L('uit')] };

async function loadConn() {
  const box = $('#conn-body');
  if (!box) return;
  $('#conn-reload').onclick = loadConn;
  let d;
  try { d = await api('connections'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const i = d.inbound;
  const table = (head, rows) => `<div class="table-scroll"><table class="vuln-table"><thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
  const tag = (st) => { const [c, l] = TASK_ST[st] || ['', st]; return `<span class="u-tag ${c}">${esc(l)}</span>`; };
  const stale = i.agents.filter((a) => a.stale).length;
  const users = new Set(i.sessions.map((x) => x.username)).size;
  const keyLast = i.api_keys.map((k) => k.last_used).filter(Boolean).sort().pop();
  const hbLast = i.heartbeats.map((h) => h.last).filter(Boolean).sort().pop();
  const line = (icon, what, count, when, go, goLabel) => `<div class="conn-row"><span class="conn-ic">${icon}</span><div class="conn-txt"><b>${what}</b>: ${count}
      <div class="muted small">${when}</div></div>${go ? `<a href="#${go}" data-goto="${go}" class="small nowrap">${goLabel} →</a>` : ''}</div>`;
  box.innerHTML = `<h3 class="set-sub">⬇️ ${L('Binnenkomend')}</h3>
    ${line('🖥️', L('Agents'), i.agents.length ? L('{n} actief', { n: i.agents.length - stale }) + (stale ? ` · <span class="bad">${L('{n} stil', { n: stale })}</span>` : '') : L('geen'),
      L('Starten elke 30 s en sturen een rapport als hun ritme om is (standaard {t}, per server in te stellen in de Agent Manager). Met realtime gaat er bij een login, een gestopte service of een gewijzigd bestand meteen een rapport uit.', { t: fmtInterval(i.agent_tick) })
        + (i.agents.some((a) => a.realtime) ? ' ' + L('Realtime aan op: {names}.', { names: i.agents.filter((a) => a.realtime).map((a) => esc(a.name)).join(', ') }) : ''),
      'settings/agents', L('Agents'))}
    ${line('🌐', L('Browsers (laatste 24 uur)'), i.sessions.length ? (users === 1 ? L('{n} apparaten van 1 gebruiker', { n: i.sessions.length }) : L('{n} apparaten van {u} gebruikers', { n: i.sessions.length, u: users })) : L('geen'),
      L('Een geopend tabblad ververst elke 20 s, alleen als het zichtbaar is.'), 'settings/users', L('Ingelogde apparaten'))}
    ${line('🔑', L('API-sleutels'), i.api_keys.length ? (i.api_keys.length === 1 ? L('1 sleutel') : L('{n} sleutels', { n: i.api_keys.length })) + (keyLast ? ' · ' + L('laatst gebruikt {t}', { t: esc(ago(keyLast)) }) : '') : L('geen'),
      L('Wanneer iets erom vraagt, bijvoorbeeld REST-sensoren in Home Assistant.'), 'settings/api', L('API-sleutels'))}
    ${line('💓', L('Heartbeats'), i.heartbeats.length ? (i.heartbeats.length === 1 ? L('1 check') : L('{n} checks', { n: i.heartbeats.length })) + (hbLast ? ' · ' + L('laatst gemeld {t}', { t: esc(ago(hbLast)) }) : '') : L('geen'),
      L('Wanneer je scripts, cronjobs of apparaten zich melden.'), i.heartbeats.length ? 'dashboard' : '', L('Dashboard'))}
    <h3 class="set-sub">⬆️ ${L('Achtergrondtaken van RzSense')}</h3>
    ${xpHtml(L('Wat RzSense elke minuut op de achtergrond doet.'), L('RzSense draait elke minuut (cron): de checks naar je apparaten volgens hun interval, de meldingen naar Pushover, ntfy, e-mail en Home Assistant, en de taken hieronder.'))}
    ${table([L('Taak'), L('Hoe vaak'), L('Laatst gelukt'), L('Volgende'), L('Stand')], d.tasks.map((t) => `<tr${t.status === 'off' ? ' class="muted"' : ''}>
      <td><b>${esc(t.label)}</b>${t.error && t.status !== 'off' ? `<div class="bad small">${esc(t.error)}</div>` : ''}${t.note ? `<div class="muted small">${esc(t.note)}</div>` : ''}</td>
      <td class="small">${esc(t.every)}</td><td>${t.status === 'off' ? '–' : esc(tsAgo(t.last_ok))}</td>
      <td class="small">${t.status === 'off' ? '–' : esc(tsWhen(t.next))}</td><td>${tag(t.status)}</td></tr>`))}`;
}

// ---------------------------------------------------------------- 🛠️ Systeem: wat eerst op de setup-pagina stond (1.92.0)
let setupInfo = null;
// 1.151.0: wat eerst op Gezondheid stond, staat nu op Systeem → Database en server (systeemcheck, cron, gebruik van de
// database) en Systeem → Prestaties en fouten. Eén keer ophalen en vullen wat er op de pagina staat.
async function loadSyscheck() {
  const boxes = ['syscheck', 'cron', 'dbuse', 'perf', 'errors'].map((k) => $(`#${k}-body`)).filter(Boolean);
  if (!boxes.length) return;
  ['#syscheck-reload', '#perf-reload'].forEach((id) => { if ($(id)) $(id).onclick = loadSyscheck; });
  let d;
  try { d = await api('setup_info'); } catch (ex) { boxes.forEach((x) => (x.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`)); return; }
  setupInfo = d;
  const fill = (id, html) => { const x = $(`#${id}-body`); if (x) { x.innerHTML = html; wireCopy(x); } };
  fill('syscheck', `<ul class="checklist">${d.checks.map((c) => `<li class="${c.ok === true ? 'ok' : c.ok === null ? 'warn' : 'bad'}">
      <span class="ic">${c.ok === true ? '✓' : c.ok === null ? '!' : '✕'}</span><span><strong>${esc(c.label)}</strong><br><span class="muted small">${esc(c.detail)}</span></span></li>`).join('')}</ul>
    <details class="small api-docs"><summary>${L('PHP-omgeving')}</summary>
      <table class="kv">${Object.entries(d.php_env).map(([k, v]) => `<tr><th>${esc(k)}</th><td class="mono">${esc(v)}</td></tr>`).join('')}</table></details>`);
  fill('cron', `<p class="muted small">${L('De checks draaien elke minuut via cron. Plaats deze regel op de server (als root):')}</p>${copyBox('cron-line', d.cron)}`);
  fill('dbuse', (d.self ? selfDb(d.self) : '') + perfTables(d.perf));
  fill('perf', perfReport(d.perf) + selfCron(d.self));
  fill('errors', selfErrors(d.self));
  perfWire($('#tab-settings'));
}

const GZIP_APACHE = `# /etc/httpd/conf.d/rzmon-snelheid.conf  (daarna: systemctl reload httpd)
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json image/svg+xml
</IfModule>
<IfModule mod_expires.c>
  <LocationMatch "/assets/.*\\.(js|css|svg|png|woff2|json)$">
    ExpiresActive On
    ExpiresDefault "access plus 30 days"
  </LocationMatch>
</IfModule>
<IfModule mod_headers.c>
  <LocationMatch "/assets/.*\\.(js|css|svg|png|woff2|json)$">
    Header set Cache-Control "public, max-age=2592000, immutable"
  </LocationMatch>
</IfModule>`;
const GZIP_NGINX = `# in het server-blok van RzSense  (daarna: nginx -t && systemctl reload nginx)
gzip on;
gzip_types text/plain text/css text/javascript application/javascript application/json image/svg+xml;
location ~* /assets/.*\\.(js|css|svg|png|woff2|json)$ { expires 30d; add_header Cache-Control "public, immutable"; }`;

/** Snelheid (1.119.0): OPcache, compressie, de traagste verzoeken en cron-onderdelen, de grootste tabellen */
function perfReport(p) {
  if (!p) return '';
  const tbl = (rows, cols) => `<div class="table-scroll"><table class="vuln-table small perf-t"><thead><tr>${cols.map((c) => `<th>${c[0]}</th>`).join('')}</tr></thead>
    <tbody>${rows.map((r) => `<tr>${cols.map((c) => `<td>${c[1](r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  const ms = (n) => (n >= 1000 ? dec(n / 1000) + ' s' : `${(+n).toLocaleString(LOCALE)} ms`);
  const slow = (n, lim) => (n >= lim ? `<b class="bad">${ms(n)}</b>` : n >= lim / 3 ? `<span class="warn-txt">${ms(n)}</span>` : ms(n));
  const op = p.opcache || {};
  return `<h3 class="set-sub">🚀 ${L('Snelheid')} <span class="muted small">${L('(laatste 24 uur)')}</span></h3>
    <ul class="checklist perf-list">
      <li class="${op.on ? 'ok' : 'bad'}"><span class="ic">${op.on ? '✓' : '✕'}</span><span><strong>OPcache</strong><br><span class="muted small">${op.on
        ? L('aan · {h}% uit het geheugen · {m} in gebruik', { h: dec(op.hit), m: fmtBytes(op.mem) }) + (op.full ? ' · <b class="bad">' + L('vol: geef het meer geheugen (opcache.memory_consumption)') + '</b>' : '')
        : L('uit: PHP leest bij elk verzoek alle code opnieuw in. Zet het aan:')}</span>${op.on ? '' : copyBox('perf-opc', 'dnf install -y php-opcache && systemctl restart php-fpm')}</span></li>
      <li class="warn" id="perf-gzip"><span class="ic">…</span><span><strong>${L('Compressie en cache van de webserver')}</strong><br><span class="muted small">${L('Controleren…')}</span></span></li>
    </ul>
    <label class="switch small"><input type="checkbox" id="perf-on" ${p.on ? 'checked' : ''}><span></span> ${L('Snelheid meten (een regel per verzoek in de database)')}</label>
    ${p.api.length ? `<details class="small api-docs" open><summary>${L('Verzoeken van de interface')}</summary>${tbl(p.api, [[L('Verzoek'), (r) => `<code>${esc(r.name)}</code>`], [L('Aantal'), (r) => r.n.toLocaleString(LOCALE)],
      [L('Gemiddeld'), (r) => slow(r.avg, 1000)], [L('Langste'), (r) => slow(r.max, 3000)], [L('Grootte'), (r) => `${r.kb.toLocaleString(LOCALE)} KB`], [L('Totaal'), (r) => ms(r.total)]])}</details>` : ''}
    ${p.agent.length ? `<details class="small api-docs"><summary>${L('Rapporten van agents')}</summary>${tbl(p.agent, [[L('Verzoek'), (r) => `<code>${esc(r.name)}</code>`], [L('Aantal'), (r) => r.n.toLocaleString(LOCALE)],
      [L('Gemiddeld'), (r) => slow(r.avg, 1000)], [L('Langste'), (r) => slow(r.max, 5000)]])}</details>` : ''}
    ${(p.parts || []).length ? `<details class="small api-docs" ${p.parts.some((r) => r.max >= 5000) ? 'open' : ''}><summary>${L('Onderdelen van een agentrapport')}</summary>${tbl(p.parts, [[L('Onderdeel'), (r) => `<code>${esc(r.name.replace(/^rapport:/, ''))}</code>`],
      [L('Gemiddeld'), (r) => slow(r.avg, 1000)], [L('Langste'), (r) => slow(r.max, 5000)], [L('Totaal'), (r) => ms(r.total)], [L('Keren'), (r) => r.n.toLocaleString(LOCALE)]])}</details>` : ''}
    ${p.cron.length ? `<details class="small api-docs" open><summary>${L('Onderdelen van een cron-ronde')}</summary>${tbl(p.cron.filter((r) => r.name !== 'start'), [[L('Onderdeel'), (r) => esc(r.name)],
      [L('Per ronde'), (r) => slow(r.per_run, 10000)], [L('Langste'), (r) => slow(r.max, 30000)], [L('Keren'), (r) => r.n.toLocaleString(LOCALE)]])}</details>` : ''}`;
}

/** 1.151.0: de tabellen in de database, met optimaliseren (Systeem → Database en server) */
function perfTables(p) {
  if (!p) return '';
  const tbl = (rows, cols) => `<div class="table-scroll"><table class="vuln-table small perf-t"><thead><tr>${cols.map((c) => `<th>${c[0]}</th>`).join('')}</tr></thead>
    <tbody>${rows.map((r) => `<tr>${cols.map((c) => `<td>${c[1](r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  return `<details class="small api-docs"><summary>${L('Tabellen in de database')}</summary>${tbl(p.tables, [[L('Tabel'), (t) => `<code>${esc(t.name)}</code>`], [L('Grootte'), (t) => fmtBytes(+t.bytes)],
      [L('Ongebruikt'), (t) => (+t.free > 1048576 ? `<span class="warn-txt">${fmtBytes(+t.free)}</span>` : fmtBytes(+t.free))], [L('Rijen'), (t) => (+t.rows).toLocaleString(LOCALE)]])}
      <p><button type="button" class="btn" id="perf-optimize">🧹 ${L('Database optimaliseren')}</button>
      <span class="muted">${L('Bouwt de grootste tabellen opnieuw op: ongebruikte ruimte terug en snellere indexen. Duurt even (tot een paar minuten); doe het buiten drukke uren.')}</span></p>
      <div id="perf-opt-res"></div></details>`;
}

/** Knoppen van het snelheidsoverzicht, en de controle op compressie (vanuit de browser: die ziet wat de webserver doet) */
function perfWire(box) {
  const on = $('#perf-on', box);
  if (on) on.onchange = async () => { try { await api('settings_save', { perf_on: on.checked ? '1' : '0' }); toast(on.checked ? L('Aangezet') : L('Uitgezet')); } catch (ex) { toast(ex.message, 'err'); on.checked = !on.checked; } };
  const opt = $('#perf-optimize', box);
  if (opt) {
    opt.onclick = async () => {
      if (!confirm(L('De database nu optimaliseren? RzSense kan tijdens het optimaliseren even trager reageren.'))) return;
      opt.disabled = true;
      opt.textContent = '⏳ ' + L('Bezig…');
      try {
        const r = await api('db_optimize', {});
        const saved = r.tables.reduce((a, t) => a + Math.max(0, t.before - t.after), 0);
        $('#perf-opt-res').innerHTML = `<p class="good-txt">✔ ${L('{n} tabellen geoptimaliseerd · {s} vrijgekomen', { n: r.tables.length, s: fmtBytes(saved) })}</p>`;
      } catch (ex) { toast(ex.message, 'err'); }
      opt.disabled = false;
      opt.textContent = '🧹 ' + L('Database optimaliseren');
    };
  }
  const li = $('#perf-gzip', box);
  const src = ($('script[src*="assets/app.js"]') || {}).src;
  if (!li || !src) return;
  // 1.152.0: wat de browser bij het laden echt binnenkreeg (verkleind of niet); een HEAD-verzoek laat dat niet altijd zien
  const pe = (performance.getEntriesByName ? performance.getEntriesByName(src) : [])[0];
  const shrunk = pe && pe.decodedBodySize > 0 && pe.encodedBodySize > 0 && pe.encodedBodySize < pe.decodedBodySize * 0.9;
  fetch(src, { method: 'HEAD', cache: 'no-store' }).then((res) => {
    const enc = res.headers.get('content-encoding') || (shrunk ? 'gzip' : '');
    const cache = res.headers.get('cache-control') || res.headers.get('expires') || '';
    const ok = shrunk || /gzip|br|deflate|zstd/.test(enc), cached = /max-age=[1-9]|expires|immutable/i.test(cache) || !!res.headers.get('expires');
    li.className = ok && cached ? 'ok' : 'warn';
    li.innerHTML = `<span class="ic">${ok && cached ? '✓' : '!'}</span><span><strong>${L('Compressie en cache van de webserver')}</strong><br><span class="muted small">`
      + (ok ? L('compressie: {e}', { e: esc(enc) }) : L('geen compressie: de interface (800 KB) en de antwoorden gaan onverkleind over het netwerk'))
      + ' · ' + (cached ? L('bestanden worden bewaard in de browser') : L('de browser vraagt de bestanden steeds opnieuw op')) + '</span>'
      + (ok && cached ? '' : `<br><span class="small">${L('Zet het aan in je webserver (één keer, als root):')}</span>
        <details class="small"><summary>Apache (httpd)</summary>${copyBox('perf-ap', GZIP_APACHE)}</details>
        <details class="small"><summary>nginx</summary>${copyBox('perf-ng', GZIP_NGINX)}</details>`) + '</span>';
    wireCopy(li);
  }).catch(() => { li.innerHTML = `<span class="ic">?</span><span><strong>${L('Compressie en cache van de webserver')}</strong><br><span class="muted small">${L('Kon niet gecontroleerd worden.')}</span></span>`; });
}

/** Gezondheid van RzSense (1.96.0): database, cron, traagste checks, PHP-fouten */
/** 1.151.0: grootte en groei van de database (Systeem → Database en server) */
function selfDb(r) {
  if (!r) return '';
  const db = r.db, c = r.cron;
  const dbLine = `${L('Database: {s}', { s: fmtBytes(db.size) })}${db.growth_day != null ? ' · ' + L('groeit {g} per dag', { g: fmtBytes(db.growth_day) }) : ` · <span class="muted">${L('groei: na een paar dagen bekend')}</span>`}`
    + (db.free != null ? ' · ' + L('vrij op de disk: {f}', { f: fmtBytes(db.free) }) : '')
    + (db.full_days != null ? ` · <b class="${db.full_days < 30 ? 'bad' : db.full_days < 90 ? 'warn-txt' : ''}">${L('vol over ~{n} dagen', { n: db.full_days })}</b>` : '');
  return `<p class="small">${dbLine}</p>`;
}
/** 1.151.0: duur van een cron-ronde en de traagste checks (Systeem → Prestaties en fouten) */
function selfCron(r) {
  if (!r) return '';
  const c = r.cron;
  return `<h3 class="set-sub">⏱️ ${L('Duur van een ronde')}</h3>
    <p class="small">${c ? L('Laatste {l} s · gemiddeld {a} s · langste {m} s (laatste {n} rondes)', { l: dec(c.last), a: dec(c.avg), m: dec(c.max), n: c.n })
      + (c.over ? ` · <b class="bad">${L('{n} keer bijna een minuut of langer', { n: c.over })}</b>` : '')
      + (c.overlap_at ? `<br><span class="warn-txt">${L('Laatst uitgelopen (vorige ronde nog bezig): {t}', { t: esc(fmtDateTime(c.overlap_at)) })}</span>` : '') : `<span class="muted">${L('Nog niet gemeten.')}</span>`}</p>
    <h3 class="set-sub">🐢 ${L('Traagste checks')}</h3>
    ${r.slow.length ? `<table class="kv small">${r.slow.map((s) => `<tr><th>${esc(s.host)} · ${esc(s.name)}</th><td>${(+s.run_ms).toLocaleString(LOCALE)} ms</td></tr>`).join('')}</table>
      <p class="muted small">${L('Een trage check (time-out) houdt de rest van de ronde op. Kijk of het doel bereikbaar is, of geef hem een langer interval.')}</p>` : `<p class="small good-txt">✔ ${L('Geen trage checks (alles onder 0,1 seconde).')}</p>`}`;
}
/** 1.151.0: PHP-fouten van de laatste 24 uur */
function selfErrors(r) {
  if (!r) return '';
  return `${r.errors.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Fout')}</th><th>${L('Waar')}</th><th>${L('Aantal')}</th><th>${L('Laatst')}</th></tr></thead><tbody>
      ${r.errors.map((e) => `<tr><td><b class="${e.level === 'warning' ? 'warn-txt' : 'bad'}">${esc(e.level)}</b> ${esc(e.message)}</td><td class="mono">${esc(e.file)}:${e.line}<br><span class="muted">${esc(e.url)}</span></td>
        <td>${e.count}</td><td class="nowrap">${esc(ago(e.last_at))}</td></tr>`).join('')}</tbody></table></div>` : `<p class="small good-txt">✔ ${L('Geen fouten.')}</p>`}`;
}

/** 1.168.0: config.php wordt door de hulpdienst opgeslagen: even wachten op het resultaat; lukt dat niet, dan zelf plaatsen */
async function configSaved(r, okMsg) {
  if (r.written) { toast(okMsg || L('Opgeslagen')); return; }
  if (!r.helper || !r.nonce) { configManual(r); return; }
  toast(L('De hulpdienst slaat config.php op…'));
  for (let i = 0; i < 20; i++) {
    await new Promise((res) => setTimeout(res, 1000));
    let x;
    try { x = await api('config_result', { nonce: r.nonce }); } catch (ex) { continue; }
    if (!x.done) continue;
    if (x.ok) { toast((okMsg ? okMsg + ' · ' : '') + L('Opgeslagen door de hulpdienst')); if (typeof loadSetupInfo === 'function') loadSetupInfo(); return; }
    toast(x.error || L('Mislukt'), 'err');
    configManual(r);
    return;
  }
  toast(L('De hulpdienst reageert niet; zet config.php zelf neer.'), 'err');
  configManual(r);
}
/** config.php kon niet geschreven worden (map vergrendeld): de inhoud om zelf te plaatsen */
function configManual(r) {
  modal({
    title: L('config.php zelf plaatsen'),
    body: `<p style="margin-top:0">${L('De installatiemap is vergrendeld (zo hoort het). Zet deze inhoud als root in {path}:', { path: `<code>${esc(r.path)}</code>` })}</p>${copyBox('cfg-content', r.content)}
      ${r.helper ? '' : xpHtml(L('Liever dat RzSense dit zelf doet? Installeer de hulpdienst.'), L('De hulpdienst slaat config.php voor je op terwijl de map vergrendeld blijft. Hij neemt alleen de vaste instellingen over (tijdzone, adressen, PHP en database), controleert alles opnieuw, test een nieuwe database eerst en maakt een reservekopie in /var/lib/rzmon/backups. Installeren of bijwerken op de server: <code>sudo bash {dir}/install/install-helper.sh</code>. Bij bijwerken via de knop in RzSense (Rocky/RHEL/AlmaLinux) gebeurt dat vanzelf.', { dir: esc(String(r.path || '').replace(/\/config\.php$/, '')) }))}`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  wireCopy($('#modal-body'));
}

async function loadSetupInfo() {
  if (!$('#server-body')) return;
  try { setupInfo = await api('setup_info'); } catch (ex) { $('#server-body').innerHTML = $('#dbconn-body').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const d = setupInfo;
  $('#server-body').innerHTML = `<form id="server-form">
      <div class="row2"><label>${L('Tijdzone')}<select name="timezone">${d.timezones.map((t) => `<option ${t === d.timezone ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
        <label>${L('Basis-URL')} <span class="hint">${L('(voor agent-instructies)')}</span><input name="base_url" type="url" value="${esc(d.base_url || '')}" placeholder="http://rzmon.lan"></label></div>
      <label>${L('Externe URL')} <span class="hint">${L('(adres van buiten, voor de statuspagina)')}</span><input name="external_url" type="url" value="${esc(d.external_url || '')}" placeholder="https://status.voorbeeld.nl"></label>
      <label>${L('Pad naar ping')}<input name="ping_bin" value="${esc(d.ping_bin || '')}" class="mono"></label>
      <div class="row2"><label>memory_limit <span class="hint">${L('nu')} ${esc(d.php_now.memory_limit)}</span><input name="memory_limit" value="${esc(d.php.memory_limit || '')}" placeholder="${esc(L('bv. 256M'))}"></label>
        <label>max_execution_time <span class="hint">${L('sec')}</span><input name="max_execution_time" value="${esc(d.php.max_execution_time || '')}" placeholder="${esc(L('bv. 120'))}"></label></div>
      <label class="switch"><input type="checkbox" name="display_errors" ${d.php.display_errors === '1' ? 'checked' : ''}><span></span> ${L('display_errors (PHP-fouten op het scherm tonen)')}</label>
      <p class="hint">${L('Deze PHP-waarden past RzSense zelf toe (via <code>ini_set</code>). Leeg = waarde uit php.ini. Zet display_errors alleen aan bij het debuggen.')}</p>
      <button class="btn primary">${L('Opslaan')}</button></form>`;
  $('#server-form').onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
      const r = await api('setup_app_save', { timezone: f.timezone.value, base_url: f.base_url.value, external_url: f.external_url.value, ping_bin: f.ping_bin.value,
        memory_limit: f.memory_limit.value, max_execution_time: f.max_execution_time.value, display_errors: f.display_errors.checked });
      configSaved(r);   // 1.168.0: eventueel via de hulpdienst
    } catch (ex) { toast(ex.message, 'err'); }
  };
  const db = d.db;
  $('#dbconn-body').innerHTML = `<form id="dbconn-form">
      <div class="row3"><label>${L('Host')}<input name="db_host" value="${esc(db.host)}" required></label>
        <label>${L('Poort')}<input name="db_port" type="number" value="${esc(db.port || 3306)}" min="1" max="65535"></label>
        <label>${L('Database')}<input name="db_name" value="${esc(db.name)}" required pattern="\\w+"></label></div>
      <div class="row2"><label>${L('Gebruiker')}<input name="db_user" value="${esc(db.user)}" required autocomplete="off"></label>
        <label>${L('Wachtwoord')} <span class="hint">${db.has_pass ? L('(leeg = ongewijzigd)') : ''}</span><input name="db_pass" type="password" autocomplete="new-password"></label></div>
      <div class="btn-row"><button type="button" class="btn" id="dbconn-test">🔌 ${L('Verbinding testen')}</button>
        <button type="button" class="btn" id="dbconn-save">💾 ${L('Opslaan en tabellen installeren')}</button>
        <button type="button" class="btn" id="dbconn-schema">↻ ${L('Tabellen bijwerken')}</button></div></form>`;
  const vals = () => { const f = $('#dbconn-form'); return { db_host: f.db_host.value, db_port: f.db_port.value, db_name: f.db_name.value, db_user: f.db_user.value, db_pass: f.db_pass.value }; };
  $('#dbconn-test').onclick = async () => {
    try { const r = await api('setup_db_test', vals()); toast(L('Verbinding OK: {v}', { v: r.version }) + (r.db_exists ? '' : ' · ' + L('database bestaat nog niet'))); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  $('#dbconn-schema').onclick = async () => {
    try { toast((await api('setup_schema', {})).message); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#dbconn-save').onclick = () => modal({
    title: '🔌 ' + L('Databaseverbinding opslaan'),
    body: `<p style="margin-top:0">${L('RzSense gaat hierna de nieuwe database gebruiken en installeert of werkt daar de tabellen bij. De gegevens in de oude database gaan niet vanzelf mee: zet eerst een back-up terug in de nieuwe database als je verhuist.')}</p>${confirmField('DATABASE')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'danger', submit: true }],
    onSubmit: async (f) => {
      const r = await api('setup_db_save', { ...vals(), confirm: f.confirm });
      configSaved(r, r.message);   // 1.168.0: eventueel via de hulpdienst
    },
  });
}

async function loadDbInfo() {
  try { dbInfo = await api('db_info'); } catch (ex) { return; }
  $('#db-status').innerHTML = L('Gebruiker {user} op {host}, database {db}', { user: `<code>${esc(dbInfo.db.user)}</code>`, host: `<code>${esc(dbInfo.db.host)}</code>`, db: `<code>${esc(dbInfo.db.name)}</code>` }) + ' · '
    + (dbInfo.helper ? `<b class="good-txt">${L('hulpdienst actief')}</b>` : dbInfo.direct ? L('RzSense mag config.php zelf opslaan') : `<b class="warn-txt">${L('hulpdienst niet geïnstalleerd')}</b>`);
  settingsSummaries();
}

/** Een commando met kopieerknop. */
function copyBox(id, cmd) {
  return `<div class="copy-line"><pre class="code" id="${id}">${esc(cmd)}</pre><button type="button" class="btn" data-copy="${id}">${L('Kopieer')}</button></div>`;
}
function wireCopy(root) {
  $$('[data-copy]', root).forEach((b) => (b.onclick = async () => {
    const el = $('#' + b.dataset.copy);
    try { await navigator.clipboard.writeText(el.textContent); toast(L('Gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren(el); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  }));
}

/** Bevestigen met je eigen wachtwoord, of een woord typen als je automatisch beheerder bent op het LAN. */
// ---------------------------------------------------------------- Commando-checks: aan/uit
const CMD_MODES = { off: L('uit'), nobody: L('als nobody'), root: L('als root') };
const CMD_MATCHES = { equals: L('is gelijk aan'), contains: L('bevat'), regex: L('komt overeen met (regex)'), exit0: L('exitcode is 0') };
const CMD_RANK = { off: 0, nobody: 1, root: 2 };
const CMD_GROUPS = { truenas: '🗄️ TrueNAS SCALE / ZFS', proxmox: '🖥️ Proxmox VE', linux: '🐧 Linux' };
// Kant-en-klare commando-checks. rec: in "Aanbevolen checks" voor dat platform (true = standaard aangevinkt);
// os: alleen voor Debian/Ubuntu of Rocky/RHEL; root: werkt niet als nobody; tested: op een echt systeem getest.
const CMD_PRESETS = {
  zfs_health: {"group": "truenas", "label": L('ZFS-pools gezond'), "name": "ZFS-pools", "cmd": "zpool status -x", "match": "equals", "expect": "all pools are healthy", "fail": "crit", "root": false, "interval": 300, "rec": {"truenas": true, "proxmox": false}, "timeout": 10, "tested": false},
  zfs_cap: {"group": "truenas", "label": L('ZFS-pools onder 80% vol'), "name": "ZFS-capaciteit", "cmd": "zpool list -H -o capacity | tr -d '%' | sort -n | tail -1", "match": "regex", "expect": "^[0-7]?[0-9]$", "fail": "warn", "root": false, "interval": 300, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  zfs_scrub: {"group": "truenas", "label": L('Scrub zonder fouten'), "name": "ZFS-scrub", "cmd": "out=$(zpool status) || exit 2; ! grep -E 'repaired [1-9]|with [1-9][0-9]* errors|errors: [0-9]+ data errors' <<<\"$out\"", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 900, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  truenas_alerts: {"group": "truenas", "label": L('Geen TrueNAS-meldingen (ERROR/CRITICAL)'), "name": "TrueNAS-meldingen", "cmd": "midclt call alert.list | python3 -c 'import json,sys; a=[x for x in json.load(sys.stdin) if not x.get(\"dismissed\") and x.get(\"level\") in (\"ERROR\",\"CRITICAL\",\"ALERT\",\"EMERGENCY\")]; print(len(a)); [print((x.get(\"formatted\") or x.get(\"klass\") or \"\")[:120]) for x in a[:3]]'", "match": "equals", "expect": "0", "fail": "warn", "root": true, "interval": 300, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  truenas_apps: {"group": "truenas", "label": L('Apps (Docker) draaien'), "name": "TrueNAS-apps", "cmd": "s=$(docker ps -a --filter status=exited --filter status=dead --format '{{.Names}}') || exit 2; [ -z \"$s\" ] || { echo \"gestopt: $(echo $s | head -c 200)\"; exit 1; }", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"truenas": false}, "timeout": 10, "tested": false},
  pve_quorum: {"group": "proxmox", "label": L('Cluster heeft quorum (alleen bij een cluster)'), "name": "Proxmox-quorum", "cmd": "pvecm status | grep -q 'Quorate:.*Yes' && echo ok", "match": "equals", "expect": "ok", "fail": "crit", "root": true, "interval": 120, "rec": {"proxmox": false}, "timeout": 10, "tested": false},
  pve_storage: {"group": "proxmox", "label": L('Opslag onder 85% vol'), "name": "Proxmox-opslag", "cmd": "pvesm status | awk 'NR>1 { p=$7; gsub(\"%\",\"\",p); if (p+0 > 85) { print $1 \" \" $7; f=1 } } END { exit f }'", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  pve_backup: {"group": "proxmox", "label": L('Laatste back-uptaak (vzdump) geslaagd'), "name": "Proxmox-back-up", "cmd": "awk -F: '$6==\"vzdump\" {l=$0} END {n=split(l,a,\" \"); s=\"\"; for(i=3;i<=n;i++) s=s (i>3?\" \":\"\") a[i]; print (s==\"\"?\"geen back-uptaak gevonden\":s)}' /var/log/pve/tasks/index", "match": "equals", "expect": "OK", "fail": "crit", "root": true, "interval": 900, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  pve_ceph: {"group": "proxmox", "label": L('Ceph gezond (alleen met Ceph)'), "name": "Ceph", "cmd": "ceph health", "match": "equals", "expect": "HEALTH_OK", "fail": "crit", "root": true, "interval": 120, "rec": {"proxmox": false}, "timeout": 10, "tested": false},
  pve_updates: {"group": "proxmox", "label": L('Geen Proxmox-updates'), "name": "Proxmox-updates", "cmd": "apt list --upgradable 2>/dev/null | grep -c -E '^(pve|proxmox)'", "match": "equals", "expect": "0", "fail": "warn", "root": false, "interval": 3600, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  failed_units: {"group": "linux", "label": L('Geen mislukte services'), "name": "Mislukte services", "cmd": "! systemctl --failed --no-legend --plain | awk '{print $1}' | grep .", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 300, "rec": {"linux": true, "proxmox": true}, "timeout": 10, "tested": false},
  reboot_deb: {"group": "linux", "os": "debian", "label": L('Geen herstart nodig (Debian/Ubuntu/Proxmox)'), "name": "Herstart nodig", "cmd": "if [ -f /var/run/reboot-required ]; then echo \"herstart nodig\"; head -3 /var/run/reboot-required.pkgs 2>/dev/null; exit 1; fi", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true, "proxmox": true}, "timeout": 10, "tested": true},
  reboot_rhel: {"group": "linux", "os": "rhel", "label": L('Geen herstart nodig (Rocky/RHEL)'), "name": "Herstart nodig", "cmd": "needs-restarting -r", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 900, "rec": {"linux": true}, "timeout": 10, "tested": false},
  updates_deb: {"group": "linux", "os": "debian", "label": L('Geen updates (Debian/Ubuntu)'), "name": "Updates", "cmd": "apt list --upgradable 2>/dev/null | grep -c upgradable", "match": "equals", "expect": "0", "fail": "warn", "root": false, "interval": 3600, "rec": {"linux": true}, "timeout": 10, "tested": true},
  security_rhel: {"group": "linux", "os": "rhel", "label": L('Geen beveiligingsupdates (Rocky/RHEL)'), "name": "Beveiligingsupdates", "cmd": "dnf -q updateinfo list --security 2>/dev/null | wc -l", "match": "equals", "expect": "0", "fail": "warn", "root": true, "interval": 3600, "timeout": 60, "rec": {"linux": true}, "tested": false},
  ntp: {"group": "linux", "label": L('Klok gesynchroniseerd (NTP)'), "name": "Tijd (NTP)", "cmd": "timedatectl show -p NTPSynchronized --value", "match": "equals", "expect": "yes", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true, "proxmox": true, "truenas": true}, "timeout": 10, "tested": false},
  inodes: {"group": "linux", "label": L('Inodes onder 90% (alle disks)'), "name": "Inodes", "cmd": "df --output=ipcent -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null | tail -n +2 | tr -d ' %' | grep -v -- '-' | sort -n | tail -1", "match": "regex", "expect": "^[0-8]?[0-9]$", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true}, "timeout": 10, "tested": true},
  temp: {"group": "linux", "label": L('CPU-temperatuur onder 70 °C'), "name": "Temperatuur", "cmd": "cat /sys/class/thermal/thermal_zone0/temp", "match": "regex", "expect": "^([0-9]{1,4}|[1-6][0-9]{4})$", "fail": "warn", "root": false, "interval": 300, "rec": {}, "timeout": 10, "tested": false},
  mdadm: {"group": "linux", "label": L('Software-RAID (mdadm) heel'), "name": "RAID (mdadm)", "cmd": "if grep -q '\\[[U_]*_[U_]*\\]' /proc/mdstat 2>/dev/null; then grep -B1 '_' /proc/mdstat | head -4; exit 1; fi", "match": "exit0", "expect": "", "fail": "crit", "root": false, "interval": 300, "rec": {"linux": false}, "timeout": 10, "tested": true},
  smart: {"group": "linux", "label": L('SMART: alle fysieke schijven gezond'), "name": "SMART", "cmd": "fail=; for d in $(lsblk -dno NAME -e 7,11); do o=$(smartctl -H /dev/$d 2>/dev/null); if grep -qE \"overall-health|Health Status\" <<<\"$o\" && ! grep -qE \"PASSED|: OK\" <<<\"$o\"; then fail=\"$fail $d\"; fi; done; [ -z \"$fail\" ] || { echo \"geen OK van:$fail\"; exit 1; }", "match": "exit0", "expect": "", "fail": "crit", "root": true, "interval": 3600, "timeout": 30, "rec": {"proxmox": true, "linux": false}, "tested": false},
  docker: {"group": "linux", "label": L('Docker-containers draaien'), "name": "Docker-containers", "cmd": "s=$(docker ps -a --filter status=exited --filter status=dead --format '{{.Names}}') || exit 2; [ -z \"$s\" ] || { echo \"gestopt: $(echo $s | head -c 200)\"; exit 1; }", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"linux": false}, "timeout": 10, "tested": false},
};

/** Platform van een agent-server uit zijn servicelijst: TrueNAS, Proxmox of Linux, en Debian- of RHEL-familie. */
function detectPlatform(list) {
  const names = new Set((list || []).map((s) => s[0]));
  const platform = names.has('middlewared') ? 'truenas' : (names.has('pvedaemon') || names.has('pve-cluster')) ? 'proxmox' : 'linux';
  const os = names.has('apt-daily') || platform === 'proxmox' || platform === 'truenas' ? 'debian' : names.has('dnf-makecache') ? 'rhel' : null;
  return { platform, os };
}

/** Keuzelijst met voorinstellingen; de groep van dit platform bovenaan. */
function presetOptions(platform, selected = '') {
  const order = [platform, ...Object.keys(CMD_GROUPS).filter((g) => g !== platform)];
  return `<option value="">— ${L('Zelf invullen')} —</option>` + order.map((g) => `<optgroup label="${CMD_GROUPS[g]}">${Object.entries(CMD_PRESETS)
    .filter(([, p]) => p.group === g).map(([k, p]) => `<option value="${k}" ${k === selected ? 'selected' : ''}>${esc(p.label)}${p.root ? ' (root)' : ''}</option>`).join('')}</optgroup>`).join('');
}

/**
 * Commando's algemeen (hostId null) of per server zetten. Ruimer maken (uit → nobody → root) vraagt om je
 * wachtwoord; daarna terug naar wat erna moet gebeuren (then).
 */
async function setAgentCommands(hostId, mode, current, label, then) {
  const target = mode === 'global' ? null : mode;
  const widens = target !== null && CMD_RANK[target] > CMD_RANK[current];
  if (!widens) {
    try { await api('agent_commands_set', { host_id: hostId, mode }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); }
    return then();
  }
  modal({
    title: `🖥️ ${L('Commando\'s toestaan')} – ${label}`,
    body: `<p style="margin-top:0">${L('Hiermee mag RzSense commando\'s laten uitvoeren op {label}, <b>{mode}</b>.', { label: esc(label), mode: esc(CMD_MODES[target]) })}
        ${(hostId ? L('Iedereen die in RzSense beheerder is, kan dan commando\'s op deze server draaien{extra}.', { extra: target === 'root' ? L(', <b>met volledige rechten</b>') : '' })
          : L('Iedereen die in RzSense beheerder is, kan dan commando\'s op je agents draaien{extra}.', { extra: target === 'root' ? L(', <b>met volledige rechten</b>') : '' }))}</p>
      ${xpHtml(L('Op een server zelf kun je commando\'s altijd blokkeren.'), L('Op een server zelf blokkeer je het altijd met <code>COMMANDS=0</code> (of <code>COMMANDS=nobody</code>) in de instellingen van de agent. Deze wijziging komt in het logboek.'))}
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: L('Toestaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('agent_commands_set', { host_id: hostId, mode, confirm: d.confirm });
      toast(L('Opgeslagen'));
      setTimeout(then, 50);
    },
  });
}

async function loadAgentCommands() {
  let r;
  try { r = await api('agent_commands'); } catch (ex) { return; }
  const sel = $('#agent-commands');
  sel.value = r.global;
  sel.dataset.current = r.global;
  const own = r.hosts.filter((x) => x.own);
  $('#agent-commands-hosts').innerHTML = own.length ? L('Per server anders:') + ' ' + own.map((x) => `<b>${esc(x.name)}</b> ${esc(CMD_MODES[x.own])}`).join(' · ') : '';
  $('#audit-log').innerHTML = r.log.length ? r.log.map((l) => `<div class="key-row"><span class="grow small"><b>${esc(l.action)}</b>: ${esc(l.detail)}<br>
      <span class="muted">${esc(fmtDateTime(l.created_at))} · ${esc(l.username)} · ${esc(l.ip)}</span></span></div>`).join('') : `<p class="muted small">${L('Nog niets.')}</p>`;
  settingsSummaries();
}

/** Bevestiging: je wachtwoord, of op het LAN een woord typen (de server accepteert het Nederlandse en het vertaalde woord) */
/**
 * Bevestigen met je wachtwoord (1.127.0), in plaats van de prompt van de browser (die het wachtwoord leesbaar liet zien):
 * een eigen venster bovenop wat er open staat, met een verborgen wachtwoordveld (👁 om het even te tonen). Op het
 * lokale netwerk zonder account typ je het woord (bv. ISOLEREN). @return het wachtwoord of woord, of null bij annuleren
 */
function askConfirm({ title, text = '', word, ok = L('Bevestigen'), danger = false }) {
  return new Promise((resolve) => {
    const lan = !!document.querySelector('.lan-badge');
    const sure = confirmSkip(word);   // 1.206.0: een vinkje in plaats van je wachtwoord
    let dlg = $('#confirm-dlg');
    if (!dlg) { dlg = document.createElement('dialog'); dlg.id = 'confirm-dlg'; dlg.className = 'confirm-dlg'; document.body.append(dlg); }
    dlg.innerHTML = `<form method="dialog" class="cf-form">
      <div class="cf-head"><span class="cf-ic ${danger ? 'danger' : ''}" aria-hidden="true">${danger ? '!' : '🔒'}</span><h3>${esc(title)}</h3></div>
      ${text ? `<p class="cf-text">${text}</p>` : ''}
      ${sure ? `<label class="switch cf-sure"><input name="pw" type="checkbox" value="__sure__" required><span></span> ${L('Ik weet het zeker')}</label>`   // 1.206.0
    : `<label class="cf-label">${lan ? L('Typ <b>{word}</b> om te bevestigen', { word: esc(L(word)) }) : L('Je wachtwoord')}
        <span class="cf-field"><input name="pw" type="${lan ? 'text' : 'password'}" required autocomplete="${lan ? 'off' : 'current-password'}" spellcheck="false" ${lan ? `placeholder="${esc(L(word))}"` : ''}>
        ${lan ? '' : `<button type="button" class="cf-eye" title="${esc(L('Wachtwoord tonen'))}" aria-label="${esc(L('Wachtwoord tonen'))}">👁</button>`}</span></label>`}
      <div class="cf-btns"><button type="button" class="btn" value="cancel" data-cf-cancel>${L('Annuleren')}</button>
        <button type="submit" class="btn ${danger ? 'danger' : 'primary'}">${esc(ok)}</button></div></form>`;
    const inp = $('input', dlg);
    let done = false;
    const finish = (v) => { if (done) return; done = true; dlg.close(); resolve(v); };
    $('.cf-eye', dlg)?.addEventListener('click', (e) => { inp.type = inp.type === 'password' ? 'text' : 'password'; e.currentTarget.classList.toggle('on', inp.type === 'text'); inp.focus(); });
    $('[data-cf-cancel]', dlg).onclick = () => finish(null);
    $('form', dlg).onsubmit = (e) => { e.preventDefault(); if (sure ? inp.checked : inp.value) finish(inp.value); };
    dlg.oncancel = (e) => { e.preventDefault(); finish(null); };
    dlg.showModal();
    inp.focus();
  });
}

// 1.206.0: per rol een vinkje in plaats van het wachtwoord (RzSense zegt in elke statusvraag of en tot wanneer)
let CONFIRM = { skip: false, lan: false, until: 0, always: [] };
document.addEventListener('change', (e) => {
  if (!e.target.matches || !e.target.matches('.confirm-sure-cb')) return;
  const h = e.target.closest('.confirm-sure').querySelector('input[name="confirm"]');
  if (h) h.value = e.target.checked ? '__sure__' : '';
});
function confirmSkip(word) {
  const c = CONFIRM;
  return !!c.skip && !(c.always || []).includes(word) && (c.lan || Date.now() / 1000 < (c.until || 0) - 5);
}
function confirmField(word, noSure = false) {
  if (!noSure && confirmSkip(word)) {
    return `<label class="switch confirm-sure" data-word="${esc(word)}"><input type="checkbox" class="confirm-sure-cb" required><span></span> ${L('Ik weet het zeker')}
      <input type="hidden" name="confirm" value=""></label>`;
  }
  const lan = !!document.querySelector('.lan-badge');
  return `<label>${lan ? L('Typ <b>{word}</b> om te bevestigen', { word: L(word) }) : L('Bevestig met je eigen wachtwoord')}
    <input name="confirm" type="${lan ? 'text' : 'password'}" required autocomplete="${lan ? 'off' : 'current-password'}" autofocus></label>`;
}

function dbRotateDialog() {
  const info = dbInfo || {};
  const canClick = info.helper || info.direct;
  const terminal = `
    <details class="small api-docs" ${canClick ? '' : 'open'}>
      <summary>${L('Via de terminal (als root)')}</summary>
      <p class="muted small">${L('Dit doet hetzelfde als de knop, en werkt altijd:')}</p>
      ${copyBox('cmd-rotate', info.cli_cmd || '')}
      ${info.helper ? '' : `<p class="muted small" style="margin-top:.8rem">${L('Hulpdienst installeren, zodat de knop ook werkt als de RzSense-map vergrendeld is:')}</p>${copyBox('cmd-helper', info.helper_cmd || '')}`}
    </details>`;
  modal({
    title: '🔑 ' + L('Databasewachtwoord vernieuwen'),
    body: canClick ? `
      <p style="margin-top:0">${L('RzSense maakt een nieuw wachtwoord van 32 tekens en slaat het op in de database en in <code>config.php</code>{extra}.', { extra: info.helper ? ' ' + L('(en in {file})', { file: '/root/rzmon-install.txt' }) : '' })}
        ${L('Daarna test RzSense de verbinding; lukt dat niet, dan wordt het oude wachtwoord automatisch teruggezet.')}</p>
      <p class="muted small">${L('Je ziet het nieuwe wachtwoord <b>één keer</b>. RzSense zelf heeft het niet nodig van je; bewaar het alleen als je zelf met de database wilt werken.')}</p>
      ${confirmField('VERNIEUWEN')}
      ${terminal}`
      : `<p style="margin-top:0">${L('De RzSense-map is vergrendeld (veilig), en de <b>hulpdienst</b> die de wissel als root uitvoert is nog niet geïnstalleerd. Voer op de server één van deze commando\'s uit:')}</p>
        <p class="small">${L('<b>1. Hulpdienst installeren</b> (eenmalig; daarna werkt deze knop):')}</p>${copyBox('cmd-helper', info.helper_cmd || '')}
        <p class="small" style="margin-top:1rem">${L('<b>2. Of nu meteen vernieuwen</b> in de terminal:')}</p>${copyBox('cmd-rotate', info.cli_cmd || '')}`,
    buttons: canClick ? [{ label: '🔑 ' + L('Vernieuwen'), cls: 'primary', submit: true }] : [{ label: L('Sluiten'), cls: 'primary', submit: true }],
    onSubmit: canClick ? async (d) => {
      let r = await api('db_rotate', { confirm: d.confirm });
      for (let i = 0; r.pending && i < 40; i++) {   // hulpdienst: even wachten op het resultaat
        await new Promise((res) => setTimeout(res, 750));
        try { r = await api('db_rotate_status'); }
        catch (ex) { if (ex.status === 404 || ex.status === 504) throw ex; }   // tijdens de wissel kan één verzoek mislukken
      }
      if (r.pending) throw new Error(L('Geen antwoord van de hulpdienst'));
      setTimeout(() => showNewDbPassword(r), 50);
    } : null,
  });
  wireCopy($('#modal-body'));
}

function showNewDbPassword(r) {
  modal({
    title: '✔ ' + L('Nieuw databasewachtwoord'),
    body: `<p style="margin-top:0">${L('Opgeslagen en getest; RzSense werkt gewoon door. Dit wachtwoord wordt <b>niet opnieuw getoond</b>.')}</p>
      ${copyBox('new-db-pass', r.password)}
      ${(r.warnings || []).map((w) => `<p class="callout small">⚠️ ${esc(w)}</p>`).join('')}`,
    buttons: [{ label: L('Klaar'), cls: 'primary', submit: true }],
  });
  wireCopy($('#modal-body'));
  loadDbInfo();
}

// ---------------------------------------------------------------- 🧬 Instellingen → IDS → Ransomware (1.228.0)
async function loadRansomSet() {
  const box = $('#ransom-hosts');
  if (!box) return;
  let d;
  try { d = await api('ransom_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  box.innerHTML = d.hosts.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Server')}</th><th>${L('Bewaakt')}</th><th>${L('Bij alarm')}</th><th>${L('Laatste ronde')}</th><th></th></tr></thead><tbody>${d.hosts.map((h) => `<tr>
    <td><b>${esc(h.name)}</b>${!h.agent_ok ? `<br><span class="muted small">${L('agent 1.90.0 nodig')}</span>` : ''}</td>
    <td>${h.paths.length ? h.paths.map((p) => `<code>${esc(p)}</code>`).join('<br>') : `<span class="muted">${L('niets')}${h.cand ? ' · ' + L('{n} mappen voorgesteld', { n: h.cand }) : ''}</span>`}</td>
    <td class="small">${h.paths.length ? [h.canary ? '🪤 ' + L('lokaas') : '', h.snap ? '📸 ' + L('snapshot') : '', h.kill ? '⛔ ' + L('stoppen') : ''].filter(Boolean).join(' · ') || L('alleen melden') : ''}</td>
    <td class="small">${h.alarm ? `<b class="bad">🚨 ${L('alarm')}</b>` : h.at ? esc(new Date(h.at * 1000).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' })) : '–'}</td>
    <td><button type="button" class="btn small-btn" data-rs-am="${h.id}">${L('Instellen')} →</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`;
  box.onclick = (e) => { const b = e.target.closest('[data-rs-am]'); if (b) agentInstructions(findHost(+b.dataset.rsAm)); };
}
