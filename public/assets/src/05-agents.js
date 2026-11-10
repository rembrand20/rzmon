// ---------------------------------------------------------------- Instellingen: 🔐 Versleuteling en ⛔ Blokkades
let secInfo = null;
/** Agenttabel sorteren: op naam, of langst geen contact bovenaan (1.99.0; per browser onthouden) */
let agentSort = (() => { try { return localStorage.getItem('rzmon_agent_sort') || 'name'; } catch (e) { return 'name'; } })();
async function loadSecurity() {
  if (!isAdmin() || !$('#enc-card')) return;
  try { secInfo = await api('security'); } catch (ex) { $('#enc-info').textContent = ex.message; return; }
  syncClock(secInfo.now_ts);
  renderSecurity();
}

/**
 * Agents-overzicht (1.121.0), zoals het dashboard: tegels, wat aandacht nodig heeft en een kaart per agent. De instellingen
 * per agent (versleuteling, vastzetten, IP-slot, https) staan in de Agent Manager; die stonden hier dubbel.
 */
function agentOverview(keyText) {
  const hs = secInfo.hosts;
  if (!hs.length) return `<p class="muted">${L('Nog geen agents. Voeg een server toe met het type <i>Remote met agent</i>.')}</p>`;
  const now = nowS();
  const online = (h) => h.contact && h.contact.seen && now - h.contact.seen <= h.contact.stale;
  const newest = secInfo.agent_latest || state.agent_latest;   // 1.131.3: uit het overzicht zelf (de rest kan nog laden)
  const latest = (h) => h.agent_version && (!newest || verCmp(h.agent_version, newest) >= 0);
  const secure = (h) => +h.agent_key_seen === 1 && +h.enc_seen === 1;
  const issues = (h) => {
    const a = secInfo.audit ? secInfo.audit[h.id] : null;
    const out = [];
    if (!h.contact || !h.contact.seen) out.push(L('nog geen rapport'));
    else if (!online(h)) out.push(L('offline sinds {t}', { t: clockText(h.contact.seen) }));
    if (h.agent_version && !latest(h)) out.push(L('versie {v} (nieuwste {n})', { v: h.agent_version, n: newest }));
    if (a && a.bad) out.push(...a.issues.filter((x) => !/^Versie/i.test(x)));
    if (h.agent_ip_new) out.push(L('geweigerd vanaf nieuw IP-adres {ip}', { ip: h.agent_ip_new }));
    return out;
  };
  const n = hs.length, nOn = hs.filter(online).length, nNew = hs.filter(latest).length, nSec = hs.filter(secure).length;
  const nOk = hs.filter((h) => !issues(h).length).length;
  const tile = (lbl, v, sub, cls) => `<div class="kpi ${cls}"><div class="kpi-lbl">${lbl}</div><div class="kpi-val">${v}<small>/${n}</small></div><div class="kpi-sub">${sub}</div></div>`;
  const att = hs.filter((h) => issues(h).length);
  const sorted = [...hs].sort((x, y) => issues(y).length - issues(x).length || (agentSort === 'contact' ? (x.contact?.seen || 0) - (y.contact?.seen || 0) : x.name.localeCompare(y.name)));
  const badge = (ok, txt, title) => `<span class="ag-b ${ok === true ? 'ok' : ok === false ? 'warn' : ''}" title="${esc(title || '')}">${txt}</span>`;
  return `<div class="kpis ag-kpis">
      ${tile(L('Online'), nOn, nOn === n ? L('allemaal bereikbaar') : L('{n} offline', { n: n - nOn }), nOn < n ? 'bad' : '')}
      ${tile(L('Nieuwste versie'), nNew, newest ? L('agent {v}', { v: esc(newest) }) : '', nNew < n ? 'warn' : '')}
      ${tile(L('Eigen sleutel en versleuteld'), nSec, nSec === n ? L('alles versleuteld') : L('{n} nog niet', { n: n - nSec }), nSec < n ? 'warn' : '')}
      ${tile(L('Verbinding in orde'), nOk, nOk === n ? L('geen aandachtspunten') : L('{n} met aandachtspunten', { n: n - nOk }), nOk < n ? 'warn' : '')}
    </div>
    ${att.length ? `<div class="sec-h"><h2>⚠️ ${L('Nu aandacht nodig')}</h2><span>${att.length === 1 ? L('1 agent') : L('{n} agents', { n: att.length })}</span></div>
      <div class="attn ag-attn">${att.map((h) => `<div class="arow"><i class="adot s${online(h) ? 1 : 2}"></i><b class="a-srv">${esc(h.name)}</b>
        <span class="a-msg">${esc(issues(h).join(' · '))}</span>
        <span class="a-acts">${h.agent_version && !latest(h) ? `<button type="button" class="btn small-btn" data-ag-update="${h.id}">⬆ ${L('Bijwerken')}</button>` : ''}<button type="button" class="btn small-btn" data-am="${h.id}">${L('Agent Manager')}</button></span></div>`).join('')}
        ${secInfo.audit && Object.values(secInfo.audit).some((a) => a.bad) ? `<div class="ag-attn-foot"><button type="button" class="btn small-btn" id="ag-cleanup">🧹 ${L('Alles opruimen')}</button> <span class="muted small">${L('oude tokens wissen en agents opnieuw laten installeren met een reservekopie')}</span></div>` : ''}</div>`
      : `<div class="attn-ok">✔ ${L('Alle agents zijn online, bijgewerkt en veilig verbonden')}</div>`}
    <div class="sec-h hosts-h ag-h"><h2>${L('Agents')}</h2><span>${sorted.filter((h) => !h.truenas).length}</span>
      <button type="button" class="th-sort" id="contact-sort" title="${esc(agentSort === 'contact' ? L('Weer op naam sorteren') : L('Langst geen contact bovenaan'))}">${agentSort === 'contact' ? L('Op contact') : L('Op naam')} ⇅</button></div>
    <div class="ag-cards">${sorted.filter((h) => !h.truenas).map((h) => agCard(h)).join('')}</div>
    ${sorted.some((h) => h.truenas) ? `<div class="sec-h hosts-h ag-h ag-tn-h"><h2>🗄️ TrueNAS</h2><span>${sorted.filter((h) => h.truenas).length}</span></div>
      <p class="muted small ag-tn-note">${L('TrueNAS SCALE draait de agent op de pool, gestart door een cronjob van TrueNAS zelf. Bijwerken van pakketten gaat via een TrueNAS-update; het IDS blokkeert alleen als je dat per server aanzet.')}</p>
      <div class="ag-cards">${sorted.filter((h) => h.truenas).map((h) => agCard(h)).join('')}</div>` : ''}`;
  function agCard(h) {
      const iss = issues(h).length;
      return `<article class="ag-card ${!online(h) ? 's2' : iss ? 's1' : 's0'}">
        <div class="ag-head"><i class="adot s${!online(h) ? 2 : iss ? 1 : 0}"></i><strong>${h.truenas ? '🗄️ ' : ''}${esc(h.name)}</strong>
          <span class="ag-ver ${latest(h) ? '' : 'warn-txt'}">${h.agent_version ? 'v' + esc(h.agent_version) : '–'}${h.agent_version && !latest(h) ? ' ⬆' : ''}</span></div>
        <div class="small ag-contact">${contactHtml(h, 'short')}</div>
        <div class="ag-badges">
          ${badge(+h.agent_key_seen === 1, +h.agent_key_seen === 1 ? '🔑 ' + L('eigen sleutel') : '🔑 ' + L('token'), L('Sleutel'))}
          ${badge(+h.enc_seen === 1, +h.enc_seen === 1 ? '🔒 ' + L('versleuteld') : '🔓 ' + L('onversleuteld'))}
          ${badge(h.agent_conn === 'http' || h.agent_fb_at ? false : h.agent_conn ? true : null, h.agent_conn === 'http' ? 'http' : h.agent_conn === 'proxy' ? L('https via proxy') : h.agent_conn ? 'https' : '–', L('Verbinding'))}
          ${badge(null, '⏱ ' + esc(fmtInterval(+h.agent_tick || secInfo.agent_tick || 60)), L('Ritme'))}
          ${+h.realtime ? badge(true, '⚡ realtime') : ''}
          ${h.agent_ips ? badge(null, '🌐 ' + esc(h.agent_ips), L('Bekend IP-adres')) : ''}
        </div>
        <div class="ag-acts"><button type="button" class="btn small-btn primary" data-am="${h.id}">${L('Agent Manager')}</button>
          <button type="button" class="btn small-btn" data-pair="${h.id}" title="${esc(L('Koppelcode maken en de agent (opnieuw) koppelen'))}">🔗 ${L('Koppelen')}</button>
          <button type="button" class="btn small-btn" data-url-host="${h.id}" title="${esc(L('Adres van RzSense voor deze agent wijzigen'))}">🌐 ${L('Adres')}</button></div>
      </article>`;
  }
}

function renderSecurity() {
  const e = secInfo.enc;
  $('#enc-mode').innerHTML = ENC_MODES.map(([k, l]) => `<option value="${k}" ${e.mode === k ? 'selected' : ''}>${l}</option>`).join('');
  $('#enc-key').value = e.key || '';
  $('#enc-show').disabled = $('#enc-copy').disabled = !e.key;
  $('#enc-generate').textContent = '🎲 ' + (e.key ? L('Nieuwe sleutel genereren') : L('Sleutel genereren'));
  $('#enc-info').innerHTML = e.key
    ? `${L('Kenmerk')}: <code>${esc(e.key_id)}</code>${e.old_until ? ' · ' + L('de vorige sleutel geldt nog tot {t}', { t: esc(fmtDateTime(e.old_until)) }) : ''}`
    : `<span class="warn-txt">${L('Nog geen sleutel: de agents versturen alles onversleuteld.')}</span>`;
  // Beveiligde verbinding: vernieuwen, certificaat vastzetten, vast IP-adres
  const c = secInfo.conn;
  $('#conn-days').value = String(c.key_days);
  $('#conn-pin').checked = c.pin;
  $('#conn-iplock').checked = c.ip_lock;
  $('#conn-https').checked = c.https_only;
  $('#conn-ownkey').checked = !!c.ownkey_only;
  $('#conn-pin-info').innerHTML = !c.pin ? L('Uit: agents met <code>INSECURE=1</code> accepteren elk certificaat.')
    : c.pin_value ? `${L('Vingerafdruk van RzSense')}: <code>sha256//${esc(c.pin_value.slice(0, 16))}…</code> · ${L('de agents krijgen hem vanzelf (versleuteld); bij een nieuw certificaat controleren ze het met hun sleutel')}`
    : c.pin_value === '' ? (c.https ? L('Niet nodig: het certificaat van RzSense is geldig (bekende uitgever), de agents controleren het gewoon.') : L('Niet nodig: je opent RzSense via http. Met https en een eigen certificaat zet RzSense het vanzelf vast.'))
    : L('De vingerafdruk is nog niet te bepalen (bv. achter een proxy); dat gebeurt bij het volgende rapport van een agent.');
  const wait = secInfo.hosts.filter((h) => h.agent_ip_new);
  $('#conn-ipwait').innerHTML = wait.map((h) => `<div class="callout ip-wait">⚠️ ${L('<b>{name}</b> wil verbinden vanaf <code>{ip}</code> en is geweigerd (bekend: {known}).', { name: esc(h.name), ip: esc(h.agent_ip_new), known: `<code>${esc(h.agent_ips || '–')}</code>` })}
      <span class="muted small">${esc(ago(h.agent_ip_new_at))}</span>
      ${ipViaWan(h) ? `<br><span class="small bad">${L('Een publiek adres, terwijl deze agent tot nu toe uit je eigen netwerk kwam: waarschijnlijk gaat hij via je router naar buiten en weer naar binnen, omdat de naam van RzSense op die server niet naar je reverse proxy wijst (DNS-rewrite). Los dat liever daar op dan dit adres toe te staan.')}</span><br>` : ''}
      <button type="button" class="btn primary" data-ip-allow="${h.id}">✔ ${L('Toestaan')}</button></div>`).join('');
  const keyText = (h) => +h.agent_key_seen === 1 ? `🔑 ${L('eigen sleutel')}${h.key_days != null ? ` <span class="muted">· ${L('{n} d oud', { n: h.key_days })}</span>` : ''}`
    : h.pairing ? `⏳ ${L('koppelcode klaar')}`
    : h.agent_version && verCmp(h.agent_version, c.ownkey_min) < 0 ? `<span class="warn-txt">${L('agent {v} nodig', { v: c.ownkey_min })}</span>`
    : `<span class="warn-txt">${L('nog geen eigen sleutel')}</span>`;
  $('#enc-hosts').innerHTML = agentOverview(keyText);
  loadBlocks();
  settingsSummaries();
}

function initSecurity() {
  $('#conn-cert').onclick = () => agentCertDialog();
  if (!$('#enc-card')) return;
  $('#enc-mode').onchange = async (e) => {
    try { await api('enc_mode', { mode: e.target.value }); toast(L('Opgeslagen')); loadSecurity(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#enc-show').onclick = () => { const k = $('#enc-key'); k.type = k.type === 'password' ? 'text' : 'password'; };
  $('#enc-copy').onclick = async () => {
    try { await navigator.clipboard.writeText($('#enc-key').value); toast(L('Gekopieerd')); }
    catch (e) { const k = $('#enc-key'); k.type = 'text'; k.select(); document.execCommand('copy'); toast(L('Gekopieerd')); }
  };
  $('#enc-generate').onclick = () => modal({
    title: '🎲 ' + L('Sleutel genereren'),
    body: `<p style="margin-top:0">${secInfo && secInfo.enc.key ? L('Er komt een nieuwe sleutel. De agents krijgen hem bij hun volgende rapport (versleuteld met de huidige); de huidige sleutel blijft nog <b>24 uur</b> geldig, zodat er niets misgaat. Een agent die langer offline is, krijgt de nieuwe bij zijn eerste onversleutelde rapport, of geef hem mee met <code>--key</code>.')
      : L('RzSense maakt een willekeurige sleutel van 32 bytes. De agents (1.49.0 of nieuwer) krijgen hem bij hun volgende rapport en versturen daarna alles versleuteld.')}</p>${confirmField('SLEUTEL')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🎲 ' + L('Genereren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('enc_generate', { confirm: d.confirm }); toast(L('Nieuwe sleutel opgeslagen')); loadSecurity(); },
  });
  const connSave = (extra = {}) => api('conn_save', { key_days: +$('#conn-days').value, pin: $('#conn-pin').checked, ip_lock: $('#conn-iplock').checked, https_only: $('#conn-https').checked,
    ownkey_only: $('#conn-ownkey').checked, ...extra });
  $('#conn-cleanup').onclick = () => agentsCleanupDialog();
  $('#conn-url-all').onclick = () => agentUrlDialog();
  const connChange = async (e) => {
    const off = (e.target.id === 'conn-pin' && !e.target.checked && secInfo.conn.pin) || (e.target.id === 'conn-iplock' && !e.target.checked && secInfo.conn.ip_lock)
      || (e.target.id === 'conn-https' && !e.target.checked && secInfo.conn.https_only) || (e.target.id === 'conn-ownkey' && !e.target.checked && secInfo.conn.ownkey_only);
    if (!off) {
      try { await connSave(); savedFlag(e.target, true); loadSecurity(); } catch (ex) { savedFlag(e.target, false, ex.message); toast(ex.message, 'err'); if (e.target.id === 'conn-https' || e.target.id === 'conn-ownkey') e.target.checked = false; }
      return;
    }
    // Uitzetten maakt de verbinding minder veilig: eerst bevestigen
    modal({
      title: '⚠️ ' + L('Minder veilig maken?'),
      body: `<p style="margin-top:0">${e.target.id === 'conn-pin' ? L('Zonder vastgezet certificaat accepteren agents met <code>INSECURE=1</code> elk certificaat, ook dat van een nep-RzSense.')
        : e.target.id === 'conn-https' ? L('Agents mogen dan ook weer via http verbinden (de berichten blijven versleuteld).')
          : e.target.id === 'conn-ownkey' ? L('Agents mogen zich dan weer met alleen een token melden (onversleuteld, zonder eigen sleutel).') : L('Agents worden dan vanaf elk IP-adres geaccepteerd (met hun sleutel).')}</p>${confirmField('UITZETTEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); loadSecurity(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
      onSubmit: async (d) => { await connSave({ confirm: d.confirm }); toast(L('Opgeslagen')); loadSecurity(); },
    });
  };
  ['#conn-days', '#conn-pin', '#conn-iplock', '#conn-https', '#conn-ownkey'].forEach((id) => ($(id).onchange = connChange));
  $('#conn-ipwait').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-ip-allow]');
    if (!b) return;
    const h = secInfo.hosts.find((x) => +x.id === +b.dataset.ipAllow);
    if (h && ipViaWan(h) && !confirm(L('{ip} is een publiek adres, terwijl {name} tot nu toe uit je eigen netwerk kwam. Waarschijnlijk werkt de DNS-rewrite op die server niet (AdGuard: filteren aan voor die client; Home Assistant: ha dns info). Toestaan zet het IP-slot open voor alles wat via dat adres binnenkomt. Toch toestaan?', { ip: h.agent_ip_new, name: h.name }))) return;
    try { const r = await api('agent_ip_allow', { id: +b.dataset.ipAllow }); toast(L('{ip} toegestaan; de agent komt bij zijn volgende rapport weer binnen', { ip: r.ip })); loadSecurity(); refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
  });
  $('#enc-hosts').addEventListener('click', async (e) => {
    if (e.target.closest('#contact-sort')) {
      agentSort = agentSort === 'contact' ? 'name' : 'contact';
      try { localStorage.setItem('rzmon_agent_sort', agentSort); } catch (ex) { /* alleen voor deze keer */ }
      return loadSecurity();
    }
    const am = e.target.closest('[data-am]');
    if (am) return agentInstructions(findHost(+am.dataset.am));
    if (e.target.closest('#ag-cleanup')) return agentsCleanupDialog();
    const agu = e.target.closest('[data-ag-update]');
    if (agu) {
      agu.disabled = true;
      try { await api('agent_update', { id: +agu.dataset.agUpdate }); toast(L('De agent wordt bijgewerkt bij zijn volgende rapport')); refresh(); } catch (ex) { toast(ex.message, 'err'); agu.disabled = false; }
      return;
    }
    const urlB = e.target.closest('[data-url-host]');
    if (urlB) return agentUrlDialog(secInfo.hosts.find((x) => +x.id === +urlB.dataset.urlHost));
    const pair = e.target.closest('[data-pair]'), renew = e.target.closest('[data-key-renew]'), reset = e.target.closest('[data-ip-reset]');
    try {
      if (pair) { const h = findHost(+pair.dataset.pair); if (h) agentInstructions(h, true); }
      if (renew && confirm(L('Nieuwe sleutel voor deze agent? Hij krijgt hem bij zijn volgende rapport; de huidige blijft nog 24 uur geldig.'))) {
        await api('agent_key_renew', { id: +renew.dataset.keyRenew }); toast(L('Nieuwe sleutel klaar')); loadSecurity();
      }
      if (reset && confirm(L('Bekende IP-adressen van deze agent vergeten? Het volgende adres waarvan hij rapporteert, wordt het bekende.'))) {
        await api('agent_ip_reset', { id: +reset.dataset.ipReset }); toast(L('Vergeten')); loadSecurity();
      }
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#enc-hosts').addEventListener('change', async (e) => {
    const hc = e.target.closest('[data-host-conn]');
    if (hc) return saveHostConn(hc, () => { loadSecurity(); refresh(); });
    const sel = e.target.closest('[data-enc-host]');
    if (!sel) return;
    try { await api('host_enc', { host_id: +sel.dataset.encHost, enc: sel.value }); toast(L('Opgeslagen; de agent pakt het op bij zijn volgende rapport')); refresh(); }
    catch (ex) { toast(ex.message, 'err'); loadSecurity(); }
  });
  $('#block-add').onclick = async () => {
    try {
      await api('block_add', { net: $('#block-net').value, reason: $('#block-reason').value });
      toast(L('{n} geblokkeerd', { n: $('#block-net').value.trim() }));
      $('#block-net').value = $('#block-reason').value = '';
      loadSecurity();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  initBlocks();
}

// ---------------------------------------------------------------- Blokkades en whitelist (1.83.0)
let blData = null, blTab = 'active', blShown = [];
const BL_SRC = { rz: () => '⛔ ' + L('IP in RzSense'), user: () => '🔒 ' + L('Account'), ids: () => '🛡️ IDS' };
const WL_KIND = { ip: () => L('IP-adres of netwerk'), user: () => L('Account: nooit vergrendelen'), decoy: () => L('Geen honeypot-naam') };
const WL_DAYS = [[1, L('1 dag')], [7, L('7 dagen')], [30, L('30 dagen')], [0, L('altijd')]];

async function loadBlocks() {
  if (!$('#blocks-list')) return;
  try { blData = await api('blocks_overview'); } catch (e) { $('#blocks-list').innerHTML = `<p class="bad small">${esc(e.message)}</p>`; return; }
  renderBlocks();
}

function blWhat(x) {
  return (x.type === 'user' ? `👤 <b>${esc(x.value)}</b>${x.ip ? `<br><span class="muted small">${L('vanaf')} <code>${esc(x.ip)}</code>${ccTag(x.ip_cc)}</span>` : ''}`
    : `<code>${esc(x.value)}</code>${ccTag(x.cc)}`) + (x.wl ? ` <span class="u-tag ok" title="${esc(L('Staat op de whitelist'))}">✅</span>` : '');
}

function renderBlocks() {
  const d = blData;
  if (!d) return;
  const tabs = $$('#bl-tabs [data-bl-tab]');
  tabs[0].textContent = `${L('Nu geblokkeerd')} (${d.active.length})`;
  tabs[1].textContent = `${L('Eerder')} (${d.history.length})`;
  tabs.forEach((b) => b.classList.toggle('active', b.dataset.blTab === blTab));
  const src = $('#bl-src').value, qv = $('#bl-q').value.trim().toLowerCase();
  const act = blTab === 'active';
  blShown = (act ? d.active : d.history).filter((x) => (!src || x.src === src)
    && (!qv || [x.value, x.ip, x.where, x.reason, x.by, x.removed_by, x.decoy, x.cc].some((v) => v && String(v).toLowerCase().includes(qv))));
  const when = (x) => act ? (x.until ? esc(fmtDateTime(x.until)) : `<b>${L('tot je deblokkeert')}</b>`)
    : x.removed_by ? L('opgeheven {t} door {who}', { t: esc(fmtDateTime(x.removed_at)), who: esc(x.removed_by) }) : L('verlopen {t}', { t: esc(fmtDateTime(x.removed_at)) });
  const where = (x) => x.src === 'ids' ? esc(x.where) + (x.all ? `<br><span class="muted small" title="${esc(L('Automatische blokkades gelden op al je servers met de IDS op blokkeren'))}">🌐 ${L('alle servers')}</span>` : '') : 'RzSense';
  const btns = (x, i) => (act ? `<button type="button" class="btn small-btn" data-bl-lift="${i}" title="${esc(L('Blokkade meteen opheffen'))}">🔓 ${L('Deblokkeren')}</button> ` : '')
    + (!x.wl || x.decoy ? `<button type="button" class="btn small-btn" data-bl-wl="${i}" title="${esc(L('Op de whitelist zetten: nooit meer blokkeren'))}">✅ ${L('Whitelist')}</button>` : '');
  $('#blocks-list').innerHTML = blShown.length ? `<div class="table-scroll"><table class="vuln-table bl-table">
    <thead><tr><th>${L('Wat')}</th><th>${L('Waar')}</th><th>${L('Reden')}</th><th>${L('Sinds')} → ${act ? L('tot') : L('opgeheven')}</th><th>${L('Door')}</th><th></th></tr></thead><tbody>
    ${blShown.map((x, i) => `<tr><td>${blWhat(x)}<br><span class="muted small nowrap">${BL_SRC[x.src]()}</span></td><td class="small">${where(x)}</td>
      <td class="small">${esc(x.reason || '')}${x.decoy ? `<br><span class="muted">🍯 ${L('honeypot-naam {u}', { u: `<code>${esc(x.decoy)}</code>` })}</span>` : ''}${act && +x.hits ? `<br><span class="muted">${L('{n}× tegengehouden', { n: x.hits })}</span>` : ''}</td>
      <td class="small">${x.since ? esc(fmtDateTime(x.since)) : '–'}<br><span class="muted">→</span> ${when(x)}</td>
      <td class="small">${+x.auto && x.src !== 'user' ? L('automatisch') : esc(x.by || '')}</td><td><span class="bl-acts">${btns(x, i)}</span></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${(src || qv) && (act ? d.active : d.history).length ? L('Niets gevonden.') : act ? L('Nu is er niets geblokkeerd. Je eigen IP-adres is {ip}.', { ip: `<code>${esc(d.my_ip)}</code>` })
      : L('Niets in de laatste {n} dagen.', { n: d.days })}</p>`;
  if ($('#wl-count')) $('#wl-count').textContent = L('{n} op de whitelist', { n: d.whitelist.length });
  $('#wl-list').innerHTML = d.whitelist.length ? `<div class="table-scroll"><table class="vuln-table">
    <thead><tr><th>${L('Soort')}</th><th>${L('Wat')}</th><th>${L('Reden')}</th><th>${L('Tot')}</th><th>${L('Door')}</th><th></th></tr></thead><tbody>
    ${d.whitelist.map((w) => `<tr><td class="small">${WL_KIND[w.kind] ? WL_KIND[w.kind]() : esc(w.kind)}</td><td>${w.kind === 'user' ? '👤 <b>' + esc(w.value) + '</b>' : `<code>${esc(w.value)}</code>${ccTag(w.cc)}`}</td>
      <td class="small">${esc(w.reason || '')}</td><td class="small">${w.until ? esc(fmtDateTime(w.until)) : `<b>${L('altijd')}</b>`}</td>
      <td class="small">${esc(w.created_by || '')}<br><span class="muted">${esc(fmtDateTime(w.created_at))}</span></td>
      <td><button type="button" class="icon-btn" data-wl-del="${w.id}" title="${esc(L('Van de whitelist halen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('De whitelist is leeg.')}</p>`;
}

/** Op de whitelist zetten; x = een regel uit het overzicht (dan kies je uit wat erbij hoort), of niets (zelf invullen) */
function wlDialog(x = null) {
  const opts = [];
  if (x && x.type !== 'user' && !x.wl) opts.push(['ip', x.value, x.type === 'net' ? L('Dit netwerk nooit blokkeren') : L('Dit IP-adres nooit blokkeren'), L('in RzSense en door het IDS op al je servers')]);
  if (x && x.type === 'user') opts.push(['user', x.value, L('Account {u} nooit vergrendelen', { u: x.value }), L('het IP-adres van de aanvaller wordt wel geblokkeerd')]);
  if (x && x.decoy) opts.push(['decoy', x.decoy, L('{u} is geen honeypot-naam', { u: x.decoy }), L('valt af van de honeypot-namen, op alle servers')]);
  const ip = !x || opts[0][0] === 'ip';
  modal({
    title: '✅ ' + L('Op de whitelist zetten'),
    body: (x ? `${opts.map(([k, v, l, h], i) => `<label class="radio-row"><input type="radio" name="choice" value="${k}|${esc(v)}" ${i ? '' : 'checked'}><span><b>${esc(l)}</b>${k === 'user' ? '' : ` <code>${esc(v)}</code>`}<br><span class="muted small">${esc(h)}</span></span></label>`).join('')}`
      : `<label>${L('Soort')}<select name="kind">${Object.entries(WL_KIND).map(([k, l]) => `<option value="${k}">${esc(l())}</option>`).join('')}</select></label>
        <label>${L('IP-adres, netwerk of naam')}<input name="value" required class="mono" placeholder="${esc(L('bv. 203.0.113.10, 198.51.100.0/24, jan of admin'))}" autocomplete="off"></label>`)
      + `<div><span class="small"><b>${L('Hoe lang')}</b></span><div class="wl-days">${WL_DAYS.map(([v, l]) => `<label><input type="radio" name="days" value="${v}" ${v === (ip ? 30 : 0) ? 'checked' : ''}> ${esc(l)}</label>`).join(' ')}</div></div>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200" autocomplete="off"></label>
      ${x && x.src !== 'user' && opts.some((o) => o[0] === 'ip') ? `<p class="muted small">${L('De blokkade wordt meteen opgeheven, ook op de andere servers. Wordt dit adres later aangevallen, dan krijg je nog wel de meldingen.')}</p>` : ''}
      ${confirmField('WHITELIST')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✅ ' + L('Op de whitelist'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const [kind, ...rest] = d.choice ? d.choice.split('|') : [d.kind, d.value];
      const r = await api('whitelist_add', { kind, value: d.choice ? rest.join('|') : d.value, days: +d.days, reason: d.reason, confirm: d.confirm });
      toast(L('{v} staat op de whitelist', { v: r.value }) + (r.lifted ? ' · ' + L('{n} blokkade(s) opgeheven', { n: r.lifted }) : ''));
      loadBlocks();
    },
  });
  // Een IP standaard 30 dagen, een account of honeypot-naam standaard altijd
  $$('#modal [name=choice]').forEach((r) => (r.onchange = () => { const v = r.value.startsWith('ip|') ? '30' : '0'; $(`#modal [name=days][value="${v}"]`).checked = true; }));
}

function initBlocks() {
  $('#bl-tabs').onclick = (e) => { const b = e.target.closest('[data-bl-tab]'); if (b) { blTab = b.dataset.blTab; renderBlocks(); } };
  $('#bl-src').onchange = renderBlocks;
  $('#bl-q').oninput = renderBlocks;
  $('#wl-add').onclick = () => wlDialog();
  $('#blocks-list').addEventListener('click', async (e) => {
    const lift = e.target.closest('[data-bl-lift]'), wl = e.target.closest('[data-bl-wl]');
    if (wl) return wlDialog(blShown[+wl.dataset.blWl]);
    if (!lift) return;
    const x = blShown[+lift.dataset.blLift];
    try {
      await api('block_lift', { src: x.src, id: x.id });
      toast(x.src === 'ids' ? L('Gedeblokkeerd; de agent past het binnen een minuut aan') : L('{v} gedeblokkeerd', { v: x.value }));
      loadBlocks();
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#wl-list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-wl-del]');
    if (!b || !confirm(L('Van de whitelist halen? Daarna kan er weer geblokkeerd worden.'))) return;
    try { await api('whitelist_remove', { id: +b.dataset.wlDel }); toast(L('Van de whitelist gehaald')); loadBlocks(); } catch (ex) { toast(ex.message, 'err'); }
  });
  // Links naar een ander onderdeel (bv. vanuit IDS-instellingen naar de whitelist)
  $('#tab-settings').addEventListener('click', (e) => {
    const go = e.target.closest('a[data-goto]');
    if (go) { e.preventDefault(); showTab(go.dataset.goto); }
  });
}

/** Is dit een IP-adres (IPv4 met vier delen tot 255, of IPv6 met minstens twee dubbele punten)? */
function isIpAddr(s) {
  s = String(s || '').trim();
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(s)) return s.split('.').every((x) => +x <= 255);
  return /^[0-9a-f:]+$/i.test(s) && (s.match(/:/g) || []).length >= 2;
}

/** Venster om een IP-adres (of het netwerk eromheen) te blokkeren, vanuit een melding */
function blockDialog(ip) {
  // 1.223.3: alleen een echt IP-adres (geen poortnummer zoals 2049 dat per ongeluk als adres in een melding stond)
  if (!isIpAddr(ip)) { toast(L('{v} is geen IP-adres; daar valt niets te blokkeren', { v: ip }), 'err'); return; }
  const v4 = /^\d+\.\d+\.\d+\.\d+$/.test(ip);
  const net = v4 ? ip.replace(/\.\d+$/, '.0/24') : ip.split(':').slice(0, 4).join(':') + '::/64';
  modal({
    title: '⛔ ' + L('{ip} blokkeren', { ip }),
    body: `<p style="margin-top:0">${L('Een geblokkeerd adres krijgt nergens meer toegang tot RzSense: niet de webpagina, niet het inloggen, niet de API en geen agents.')}</p>
      <label class="radio-row"><input type="radio" name="what" value="${esc(ip)}" checked><span>${L('Alleen dit adres')}: <code>${esc(ip)}</code></span></label>
      <label class="radio-row"><input type="radio" name="what" value="${esc(net)}"><span>${L('Het hele netwerk')}: <code>${esc(net)}</code></span></label>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" value="${esc(L('vanuit Meldingen'))}"></label>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⛔ ' + L('Blokkeren'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { await api('block_add', { net: d.what, reason: d.reason }); toast(L('{n} geblokkeerd', { n: d.what })); loadAlerts(); loadSecurity(); },
  });
}

// ---------------------------------------------------------------- Versleuteling van de agents
const ENC_MODES = [['off', L('Uit')], ['on', L('Aan (oude agents mogen nog onversleuteld)')], ['required', L('Verplicht')]];
const ENC_HOST_MODES = [['', L('algemene instelling')], ['on', L('aan')], ['required', L('verplicht')], ['off', L('uit')]];
/** 🧹 Alle agents opruimen (1.100.0), met wachtwoord en het woord OPRUIMEN */
function agentsCleanupDialog(after) {
  modal({
    title: '🧹 ' + L('Alle agents opruimen'),
    body: `<p style="margin-top:0">${L('Dit doet RzSense:')}</p><ul class="small">
      <li>${L('het oude token wissen van elke agent met een eigen sleutel;')}</li>
      <li>${L('als alle agents een eigen sleutel hebben: de algemene sleutel wissen en <b>alleen agents met een eigen sleutel</b> aanzetten (koppelen met een koppelcode blijft kunnen);')}</li>
      <li>${L('elke agent die zichzelf kan bijwerken installeert zichzelf opnieuw: eerst een reservekopie van de huidige agent en instellingen op de server, daarna de nieuwste agent met alleen de regels die nog nodig zijn.')}</li></ul>
      <p class="small muted">${L('Wat je zelf instelde (adres, certificaten, commando-checks …) blijft staan. Een agent die het niet zelf kan: op de server')} <code>sudo rzmon-agent-setup --update</code>.</p>${confirmField('OPRUIMEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🧹 ' + L('Opruimen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('agents_cleanup', { confirm: d.confirm });
      toast(L('Opgeruimd: {t} token(s) gewist, {r} agent(s) installeren zichzelf opnieuw', { t: r.tokens, r: r.reinstall })
        + (r.global_key ? ' · ' + L('algemene sleutel gewist') : '') + (r.ownkey_only ? ' · ' + L('alleen agents met een eigen sleutel: aan') : ''));
      loadSecurity(); refresh();
      if (after) after();
    },
  });
}

/** Hoe kwam het laatste rapport binnen: 🔒 versleuteld, 🔓 niet, of nog niets */
function encSeenText(h) {
  if (!h.agent_seen) return L('nog geen rapport');
  if (+h.enc_seen === 1) return '🔒 ' + L('laatste rapport versleuteld');
  return '🔓 ' + L('laatste rapport niet versleuteld') + (h.agent_version && verCmp(h.agent_version, '1.49.0') < 0 ? ' · ' + L('agent {v} of nieuwer nodig', { v: '1.49.0' }) : '');
}

/** Hoe de agent verbindt (1.72.0): https via de proxy, https, http, of via het reserve-adres; met het adres en een lopende overstap */
/** Wil een agent uit het eigen netwerk ineens vanaf een publiek adres verbinden? (meestal: DNS-rewrite werkt niet, via de router) */
function ipViaWan(h) {
  const known = String(h.agent_ips || '').split(/\s+/).filter(Boolean);
  return !!h.agent_ip_new && !isLocalAddress(h.agent_ip_new) && known.length > 0 && known.every(isLocalAddress);
}

function connText(h) {
  const via = h.agent_fb_at ? `<b class="bad">⚠️ ${L('via reserve-adres')}</b> <span class="muted">${esc(ago(h.agent_fb_at))}</span>`
    : h.agent_conn === 'proxy' ? `🔒 ${L('https via proxy')}` : h.agent_conn === 'https' ? `🔒 https` : h.agent_conn === 'http' ? `<span class="warn-txt">🔓 http</span>` : '<span class="muted">–</span>';
  return `${via}${h.agent_url ? `<br><span class="muted mono conn-url" title="${esc(L('Adres van RzSense dat de agent gebruikt'))}">${esc(h.agent_url)}</span>${specIcon(h.agent_url, h.agent_url_pin)}` : ''}`
    + (h.agent_url_fb ? `<br><span class="muted conn-url" title="${esc(L('Reserve-adres'))}">↪ ${esc(h.agent_url_fb)}</span>${specIcon(h.agent_url_fb, h.agent_url_fb_pin)}` : '')
    + (h.agent_url_status ? `<br><span class="small ${h.agent_url_status.startsWith('✘') ? 'bad' : h.agent_url_status.startsWith('⏳') ? 'muted' : 'good-txt'}">${esc(h.agent_url_status)}</span>` : '');
}

/** Hoe de agent een adres controleert (1.78.0): 📌 eigen certificaat vastgezet, 🔒 geldig certificaat, of niets bekend */
function specIcon(url, spec) {
  if (!url || !spec) return '';
  if (spec.startsWith('sha256//')) return ` <span title="${esc(L('Alleen het eigen certificaat van RzSense (vastgezet)'))}">📌</span>`;
  return /^https:/.test(url) ? ` <span title="${esc(L('Geldig certificaat (gewone controle)'))}">🔒</span>` : '';
}

/** Wat er op een adres staat, gezien vanaf RzSense (1.78.0) */
function urlTrustText(r) {
  if (!r) return '';
  return ({
    own: `<span class="good-txt">🔒📌 ${L('het eigen certificaat van RzSense: de agent zet het vast (agent {v} nodig)', { v: '1.53.0' })}</span>`,
    ca: `<span class="good-txt">🔒 ${L('geldig certificaat')}${r.issuer ? ` (${esc(r.issuer)})` : ''}</span>`,
    http: `<span class="warn-txt">🔓 ${L('http: niet versleuteld (de berichten zelf blijven wel versleuteld)')}</span>`,
    other: `<span class="bad">✘ ${L('onbekend certificaat: niet van RzSense en niet vertrouwd')}</span>`,
    err: `<span class="warn-txt">⚠️ ${L('vanaf RzSense niet bereikbaar')}${r.error ? `: ${esc(r.error)}` : ''} · ${L('de agent test het zelf')}</span>`,
  })[r.kind] || '';
}

/**
 * Venster: adres van RzSense en reserve-adres voor één agent (of alle agents). De agent test het nieuwe adres eerst
 * (agent 1.51.0). Vanaf 1.78.0 een keuzelijst (IP-adressen, namen, beheeradres), http of https naar keuze, het
 * reserve-adres uit te zetten, en per adres wat er op staat (eigen certificaat = vastzetten, agent 1.53.0).
 */
async function agentUrlDialog(h = null) {
  const c = (secInfo && secInfo.conn) || {};
  let o;
  try { o = await api('agent_url_options'); } catch (ex) { return toast(ex.message, 'err'); }
  const opts = o.options || [];
  const trust = new Map(opts.map((x) => [x.url, x]));
  const hosts = ((secInfo && secInfo.hosts) || state.hosts.filter((x) => x.mode === 'agent')).filter((x) => !h || +x.id === +h.id);
  const src = { ip: L('IP-adres van RzSense'), dns: L('naam bij het IP-adres (DNS)'), host: L('hostnaam van RzSense'), cert: L('naam uit het certificaat'), admin: L('beheeradres (via je proxy)') };
  // Advies: rechtstreeks (eigen certificaat) als gewoon adres, het beheeradres (DNS-rewrite naar de proxy) als reserve
  const own = opts.find((x) => x.kind === 'own');
  const admin = opts.find((x) => x.source === 'admin');
  const defUrl = (h && h.agent_url) || (own || admin || opts[0] || {}).url || location.origin;
  const defFb = h ? (h.agent_url_fb || '') : (admin && admin.url !== defUrl ? admin.url : '');
  const field = (name, cur, none) => {
    const known = opts.some((x) => x.url === cur);
    return `<select data-url-sel="${name}">${none ? `<option value="" ${cur ? '' : 'selected'}>${L('Geen reserve-adres')}</option>` : ''}
        ${opts.map((x) => `<option value="${esc(x.url)}" ${x.url === cur ? 'selected' : ''} ${x.kind === 'other' ? 'disabled' : ''}>${esc(x.url)} · ${esc(src[x.source] || '')}</option>`).join('')}
        <option value="custom" ${cur && !known ? 'selected' : ''}>✏️ ${L('Zelf invullen (IP-adres of naam)…')}</option></select>
      <span class="url-custom" ${cur && !known ? '' : 'hidden'}><input name="${name}" type="text" value="${esc(cur)}" placeholder="https://rzmon.thuis.lan" spellcheck="false">
        <button type="button" class="btn small-btn" data-url-test="${name}">${L('Testen')}</button></span>
      <div class="small url-trust" data-trust="${name}"></div>`;
  };
  modal({
    title: '🌐 ' + (h ? L('Adres van RzSense – {name}', { name: h.name }) : L('Alle agents naar een ander adres')),
    body: `<p class="small" style="margin-top:0">${L('De agent krijgt het nieuwe adres versleuteld, <b>test het eerst</b> (verbinding, certificaat en zijn eigen sleutel) en gebruikt het pas als alles klopt; anders blijft het oude adres. Het resultaat zie je na één of twee rapporten in de kolom <i>Verbinding</i>.')}</p>
      <p class="small muted">💡 ${L('Advies: rechtstreeks naar RzSense (https op het IP-adres, het eigen certificaat wordt vastgezet) als gewoon adres, en je beheeradres (via de DNS-rewrite naar je proxy) als reserve. Zo hangen de agents niet af van je proxy.')}</p>
      ${h && h.agent_url ? `<p class="small">${L('Nu')}: <code>${esc(h.agent_url)}</code>${specIcon(h.agent_url, h.agent_url_pin)}${h.agent_url_fb ? ` · ${L('reserve')}: <code>${esc(h.agent_url_fb)}</code>${specIcon(h.agent_url_fb, h.agent_url_fb_pin)}` : ''}</p>` : ''}
      <label>${L('Adres')}</label>${field('url', defUrl, false)}
      <label style="margin-top:.6rem">${L('Reserve-adres')} <span class="hint">${L('(als het adres hierboven niet bereikbaar is)')}</span></label>${field('fallback', defFb, true)}
      <div class="small" id="url-warn"></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🌐 ' + L('Testen en overstappen'), cls: 'primary', submit: true }],
    onSubmit: async () => {
      const url = value('url');
      if (!url) throw new Error(L('Kies een adres'));
      const r = await api('agent_url_set', { id: h ? h.id : 'all', url, fallback: value('fallback'), keep_fb: false });
      toast(L('{n} agent(s) krijgen het nieuwe adres bij hun volgende rapport', { n: r.n }) + (r.skipped.length ? ' · ' + L('overgeslagen: {names}', { names: r.skipped.join(', ') }) : ''));
      loadSecurity();
      refresh();
    },
  });
  const m = $('#modal');
  const value = (name) => {
    const sel = $(`[data-url-sel="${name}"]`, m).value;
    return (sel === 'custom' ? $(`input[name="${name}"]`, m).value : sel).trim().replace(/\/+$/, '');
  };
  // Waarschuwingen: http terwijl "alleen via https" geldt; eigen certificaat met agents ouder dan 1.53.0
  const warn = () => {
    const urls = [value('url'), value('fallback')].filter(Boolean);
    const out = [];
    const httpsOnly = hosts.filter((x) => x.agent_https === 'on' || (!x.agent_https && c.https_only));
    if (urls.some((u) => /^http:/.test(u)) && httpsOnly.length) out.push(`⚠️ ${L('"Alleen via https" staat aan voor: {names}. Via http weigert RzSense hun rapporten.', { names: httpsOnly.map((x) => esc(x.name)).join(', ') })}`);
    const pinMin = o.pinurl_min || '1.53.0';
    const old = hosts.filter((x) => !x.agent_version || verCmp(x.agent_version, pinMin) < 0);
    if (urls.some((u) => (trust.get(u) || {}).kind === 'own') && old.length) out.push(`⚠️ ${L('Voor het eigen certificaat is agent {v} nodig; deze worden overgeslagen: {names}. Werk ze eerst bij.', { v: pinMin, names: old.map((x) => esc(x.name)).join(', ') })}`);
    else {
      const old2 = hosts.filter((x) => !x.agent_version || verCmp(x.agent_version, o.url_min || '1.51.0') < 0);
      if (old2.length) out.push(`⚠️ ${L('Agent {v} of nieuwer nodig; deze worden overgeslagen: {names}. Werk ze eerst bij, of pas het adres op de server zelf aan.', { v: o.url_min || '1.51.0', names: old2.map((x) => esc(x.name)).join(', ') })}`);
    }
    if (urls.length === 2 && urls[0] === urls[1]) out.push(`⚠️ ${L('Het reserve-adres is hetzelfde als het gewone adres')}`);
    $('#url-warn', m).innerHTML = out.map((x) => `<p class="warn-txt" style="margin:.3rem 0">${x}</p>`).join('');
  };
  const show = (name) => {
    const u = value(name);
    if (u && !trust.has(u)) return test(name);   // zelf ingevuld: eerst bekijken wat erop staat
    $(`[data-trust="${name}"]`, m).innerHTML = u ? urlTrustText(trust.get(u)) : '';
    warn();
  };
  const test = async (name) => {
    const u = value(name);
    if (!u) return;
    $(`[data-trust="${name}"]`, m).innerHTML = `<span class="muted">${L('Testen…')}</span>`;
    try { trust.set(u, await api('agent_url_test', { url: u })); } catch (ex) { trust.set(u, { kind: 'err', error: ex.message }); }
    show(name);
  };
  $$('[data-url-sel]', m).forEach((sel) => (sel.onchange = () => {
    const name = sel.dataset.urlSel;
    const box = $(`.url-custom input[name="${name}"]`, m).parentElement;
    box.hidden = sel.value !== 'custom';
    if (sel.value === 'custom') $(`input[name="${name}"]`, m).focus();
    show(name);
  }));
  $$('[data-url-test]', m).forEach((b) => (b.onclick = () => test(b.dataset.urlTest)));
  $$('.url-custom input', m).forEach((i) => (i.onchange = () => test(i.name)));
  show('url');
  show('fallback');
}

/** Wijziging per server opslaan; uitzetten waar het nu geldt vraagt om je wachtwoord */
async function saveHostConn(sel, done) {
  const send = (extra = {}) => api('host_conn', { host_id: +sel.dataset.host, [({ ip: 'ip_lock', pin: 'pin', https: 'https' })[sel.dataset.hostConn]]: sel.value, ...extra });
  try { await send(); savedFlag(sel, true); done(); }
  catch (ex) {
    if (!/UITZETTEN|wachtwoord|password|confirm/i.test(ex.message)) { toast(ex.message, 'err'); done(); return; }
    modal({
      title: '⚠️ ' + L('Minder veilig maken?'),
      body: `<p style="margin-top:0">${({ pin: L('Zonder vastgezet certificaat accepteert deze agent met <code>INSECURE=1</code> elk certificaat, ook dat van een nep-RzSense.'),
        ip: L('Deze agent wordt dan vanaf elk IP-adres geaccepteerd (met zijn sleutel).'), https: L('Deze agent mag dan ook weer via http verbinden (de berichten blijven versleuteld).') })[sel.dataset.hostConn]}</p>${confirmField('UITZETTEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); done(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
      onSubmit: async (d) => { await send({ confirm: d.confirm }); toast(L('Opgeslagen')); done(); },
    });
  }
}

// ---------------------------------------------------------------- 1.210.0: logs verzamelen (Agent Manager › Logs)
const LS_KINDS = () => ({
  file: [L('Bestand of map'), '/var/log/nginx/*.log', L('Volledig pad, met * voor meerdere bestanden. Alleen binnen LOG_DIRS van de server.')],
  journal: [L('Journal (alles)'), '', L('Het hele systeemlog van systemd.')],
  unit: [L('Journal van één service'), 'mariadb.service', L('De naam van de service, zoals bij systemctl.')],
  docker: [L('Container (Docker/Podman)'), 'vaultwarden', L('De naam van de container.')],
  ha: ['Home Assistant', 'core', L('host, core, supervisor of addon:<slug> (alleen in de add-on).')],
});
const LS_LEVELS = () => [[0, L('Alles')], [1, L('Info en erger')], [2, L('Warning en erger')], [3, L('Alleen errors')]];
const LS_PRESETS = () => [
  ['nginx', 'file', '/var/log/nginx/*.log'], ['apache2', 'file', '/var/log/apache2/*.log'], ['syslog', 'file', '/var/log/syslog'],
  ['auth.log', 'file', '/var/log/auth.log'], ['fail2ban', 'file', '/var/log/fail2ban.log'], [L('Systeem (journal)'), 'journal', ''],
  ['TrueNAS middleware', 'file', '/var/log/middlewared.log'], ['Home Assistant Core', 'ha', 'core'],
];
const lsLvlText = (n) => (LS_LEVELS().find(([k]) => k === +n) || [0, '?'])[1];
const LS_LVL_PILL = ['debug', 'info', 'warning', 'error', 'critical'];

// ---------------------------------------------------------------- 1.213.0: logs die de agent op de server vond, om uit te kiezen
const LF = { tab: 'dirs', timer: 0, waitUntil: 0, sel: new Set(), host: 0 };
const LF_TABS = () => [['dirs', '📁', L('Logmappen')], ['docker', '🐳', L('Containers')], ['units', '⚙️', L('Services')], ['ha', '🏠', 'Home Assistant']];
const lfAgo = (ts) => { const s = Math.max(0, Date.now() / 1000 - ts); return s < 90 ? L('nu') : s < 3600 ? L('{n} min', { n: Math.round(s / 60) }) : s < 86400 ? L('{n} uur', { n: Math.round(s / 3600) }) : s < 60 * 86400 ? L('{n} dagen', { n: Math.round(s / 86400) }) : L('{n} mnd', { n: Math.round(s / (30 * 86400)) }); };

async function lfLoad(h, keepChecks = false) {
  const box = $('#ls-found');
  if (!box) return;
  clearTimeout(LF.timer);
  let d;
  try { d = await api('log_found', null, { host_id: h.id }); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  if (!box.isConnected) return;
  const g = d.groups;
  // Wat aangevinkt is, over alle tabbladen heen; de eerste keer de aanbevolen logs die nog niet verzameld worden
  if (!keepChecks || LF.host !== h.id) {
    LF.host = h.id;
    LF.sel = new Set(Object.values(g).flat().filter((i) => i.rec && !i.have).map((i) => i.kind + ':' + i.spec));
  }
  const names = new Map();
  Object.values(g).flat().forEach((i) => { names.set(i.kind + ':' + i.spec, i); (i.files || []).forEach((f) => names.set('file:' + f.path, { name: f.path.split('/').pop() })); });
  if (!g[LF.tab] || (!g[LF.tab].length && g.dirs.length)) LF.tab = 'dirs';
  const key = (it) => it.kind + ':' + it.spec;
  const tag = (it) => [it.have ? `<span class="lf-tag have">✓ ${L('verzameld')}</span>` : '', it.rec && !it.have ? `<span class="lf-tag rec">${L('aanbevolen')}</span>` : '',
    it.new && !it.have ? `<span class="lf-tag new">${L('nieuw')}</span>` : '', it.quiet ? `<span class="lf-tag">${it.kind === 'docker' || it.kind === 'ha' ? esc(it.state || L('gestopt')) : L('stil sinds {t}', { t: lfAgo(it.mtime) })}</span>` : ''].join(' ');
  const cb = (it, sub = '') => (it.have ? '<span></span>' : `<input type="checkbox" data-lf="${esc(sub || key(it))}" ${LF.sel.has(sub || key(it)) ? 'checked' : ''} aria-label="${esc(L('{n} toevoegen', { n: it.name }))}">`);
  const row = (it) => {
    let sub = '', info = '';
    if (it.kind === 'file') info = it.folder ? `${esc(it.spec)} · ${it.files.length === 1 ? L('1 bestand') : L('{n} bestanden', { n: it.files.length })}` : esc(it.spec);
    else if (it.kind === 'docker') info = `${esc(it.engine)} · ${esc(it.image)} · ${esc(it.state)}`;
    else if (it.kind === 'unit' || it.kind === 'journal') info = `journal · ${L('{n} regels in 24 uur', { n: dec(it.lines) })}`;
    else info = `ha:${esc(it.spec)} · ${esc(it.state)}`;
    if (it.folder) {
      sub = it.files.map((f) => `<div class="lf-it lf-sub" data-lf-of="${esc(it.spec)}" hidden>${it.have ? '<span></span>' : `<input type="checkbox" data-lf="file:${esc(f.path)}" ${LF.sel.has('file:' + f.path) ? 'checked' : ''} aria-label="${esc(f.path)}">`}
        <span class="lf-nm"><span class="lf-p">${esc(f.path.split('/').pop())}</span></span><span class="lf-sz">${fmtBytes(f.size)}</span><span class="lf-tm">${esc(lfAgo(f.mtime))}</span></div>`).join('');
    }
    return `<div class="lf-it${it.quiet ? ' dim' : ''}">${cb(it)}
      ${it.folder ? `<button type="button" class="lf-tw" data-lf-x="${esc(it.spec)}" aria-expanded="false" aria-label="${esc(L('Bestanden tonen'))}">▸</button>` : '<span></span>'}
      <span class="lf-nm"><b>${it.folder ? '📁 ' : ''}${esc(it.name)}</b> ${tag(it)}<span class="lf-p">${info}</span></span>
      <span class="lf-sz">${it.size !== undefined ? fmtBytes(it.size) : ''}</span><span class="lf-tm">${it.mtime ? esc(lfAgo(it.mtime)) : ''}</span></div>${sub}`;
  };
  const items = g[LF.tab] || [];
  const waiting = d.pending && Date.now() < LF.waitUntil + 1000;
  box.innerHTML = `<div class="lf-head"><b>🔍 ${L('Gevonden op deze server')}</b>
      <span class="muted small">${d.at ? L('gezocht {t}', { t: ago(d.at) }) : L('nog niet gezocht')}${d.new ? ' · ' + L('{n} nieuw', { n: d.new }) : ''}</span><span class="lp-sp"></span>
      <button type="button" class="btn small-btn" id="lf-scan" ${waiting ? 'disabled' : ''}>${waiting ? '⏳ ' + L('Wacht op de agent…') : '🔄 ' + L('Opnieuw zoeken')}</button></div>
    ${d.at ? `<div class="seg lf-tabs" role="tablist">${LF_TABS().map(([k, ic, t]) => `<button type="button" class="seg-btn${k === LF.tab ? ' active' : ''}" data-lf-tab="${k}" role="tab" ${g[k].length ? '' : 'disabled'}>${ic} ${esc(t)} <b>${g[k].length}</b></button>`).join('')}</div>
      <div class="lf-list">${items.length ? items.map(row).join('') : `<p class="muted small lf-empty">${L('Niets gevonden.')}</p>`}</div>
      <div class="lf-foot"><button type="button" class="btn primary small-btn" id="lf-add" disabled>＋ ${L('Toevoegen')}</button><span class="muted small" id="lf-sel"></span></div>
      ${LF.tab === 'dirs' ? `<p class="muted small">${L('Overgeslagen: gedraaide bestanden (.1, .gz), binaire bestanden (wtmp, btmp, lastlog) en de map journal (die staat bij Services).')}</p>` : ''}
      ${LF.tab === 'dirs' && d.noread.length ? `<p class="warn-txt small">${L('Geen leesrechten: {d}', { d: d.noread.map(esc).join(', ') })}</p>` : ''}
      ${LF.tab === 'dirs' ? `<div class="lf-note small"><b>${L('Een log in een andere map?')}</b> ${L('De agent kijkt alleen in de toegestane mappen (LOG_DIRS, nu {d}). Zet een andere map op de server erbij in /etc/default/rzmon-agent en klik daarna op Opnieuw zoeken:', { d: `<code>${esc(d.dirs || '/var/log')}</code>` })}<pre class="code">LOG_DIRS="${esc(d.dirs || '/var/log')} /opt/myapp/logs"</pre></div>` : ''}`
    : `<p class="muted small">${d.pending ? L('De agent zoekt bij zijn volgende rapport (meestal binnen een minuut). Werkt het niet, dan is de agent misschien te oud: bijwerken kan bij Versie hierboven.') : L('De agent heeft nog niet gezocht. Klik op Opnieuw zoeken, of wacht tot hij het vanzelf doet (één keer per dag).')}</p>`}`;
  const sel = () => {
    const n = LF.sel.size;
    const btn = $('#lf-add', box);
    if (!btn) return;
    btn.disabled = !n;
    btn.textContent = '＋ ' + (n ? L('{n} toevoegen', { n }) : L('Toevoegen'));
    $('#lf-sel', box).textContent = n ? [...LF.sel].map((k) => (names.get(k) || {}).name || k.split('/').pop()).slice(0, 8).join(', ') + (n > 8 ? ' …' : '') + ' · ' + L('Ze gaan mee bij het volgende rapport van de agent.') : '';
  };
  sel();
  box.onchange = (e) => {
    const c = e.target.closest('[data-lf]');
    if (!c) return;
    if (c.checked) LF.sel.add(c.dataset.lf); else LF.sel.delete(c.dataset.lf);
    sel();
  };
  box.onclick = async (e) => {
    const t = e.target.closest('[data-lf-tab]');
    if (t) { LF.tab = t.dataset.lfTab; return lfLoad(h, true); }
    const x = e.target.closest('[data-lf-x]');
    if (x) {
      const open = x.getAttribute('aria-expanded') !== 'true';
      x.setAttribute('aria-expanded', String(open)); x.textContent = open ? '▾' : '▸';
      $$('[data-lf-of]', box).filter((r) => r.dataset.lfOf === x.dataset.lfX).forEach((r) => { r.hidden = !open; });
      return;
    }
    if (e.target.closest('#lf-scan')) {
      try { await api('log_scan', { host_id: h.id }); LF.waitUntil = Date.now() + 180000; lfLoad(h, true); } catch (ex) { toast(ex.message, 'err'); }
      return;
    }
    if (e.target.closest('#lf-add')) {
      const pick = [...LF.sel].map((k) => {
        const [kind, ...rest] = k.split(':'), spec = rest.join(':');
        const it = names.get(k);
        return { kind, spec, name: it ? (kind === 'unit' ? it.name.replace(/\.service$/, '') : it.name) : spec.split('/').pop() };
      });
      try {
        const r = await api('log_found_add', { host_id: h.id, items: pick });
        toast(r.errors.length ? r.errors.join('; ') : L('{n} toegevoegd; de agent pakt het binnen een minuut op', { n: r.added }), r.errors.length ? 'err' : '');
        LF.host = 0;
        amLogs(h);
      } catch (ex) { toast(ex.message, 'err'); }
    }
  };
  // Wachten op de agent: elke 8 seconden kijken, hooguit 3 minuten
  if (waiting) LF.timer = setTimeout(() => { if (box.isConnected) lfLoad(h, true); }, 8000);
}

async function amLogs(h) {
  const box = $('#am-logs');
  if (!box) return;
  let d;
  try { d = await api('log_sources', null, { host_id: h.id }); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  const kinds = LS_KINDS();
  const iv = d.interval ? +d.interval : 0;
  const rows = d.sources.map((s) => {
    const st = !s.enabled ? `<span class="lg-pill mut">${L('uit')}</span>`
      : s.status === 'err' ? `<span class="lg-pill bad">✕ ${L('fout')}</span>`
      : s.status === 'ok' ? `<span class="lg-pill ok">✓${s.kind === 'file' && s.files !== null ? ' ' + (s.files === 1 ? L('1 bestand') : L('{n} bestanden', { n: s.files })) : ''}</span>`
      : `<span class="lg-pill mut">${L('wacht op de agent')}</span>`;
    return `<tr data-ls="${s.id}">
      <td>${amSw('ls-on-' + s.id, s.enabled, `data-ls-on="${s.id}"`)}</td>
      <td><div class="lg-src"><b>${esc(s.name)}</b>${s.spec ? `<span class="mono muted small">${esc(s.kind === 'ha' ? 'ha:' + s.spec : s.kind === 'docker' ? 'docker:' + s.spec : s.kind === 'unit' ? 'journal:' + s.spec : s.spec)}</span>` : ''}
        ${s.enabled && s.status === 'err' && s.status_msg ? `<span class="danger-txt small">${esc(s.status_msg)}</span>` : ''}
        ${s.dropped_today ? `<span class="warn-txt small">${L('{n} regels niet opgeslagen (daglimiet)', { n: dec(s.dropped_today) })}</span>` : ''}</div></td>
      <td class="nowrap">${esc(kinds[s.kind] ? kinds[s.kind][0] : s.kind)}</td>
      <td class="nowrap">${esc(lsLvlText(s.level))}</td>
      <td class="num">${s.lines_today ? dec(s.lines_today) : '–'}</td>
      <td class="num">${s.bytes_today ? fmtBytes(s.bytes_today) : '–'}</td>
      <td>${st}${s.last_at ? `<div class="muted small">${esc(ago(s.last_at))}</div>` : ''}</td>
      <td class="nowrap"><button type="button" class="btn small-btn" data-ls-lines="${s.id}" title="${esc(L('Laatste regels'))}">≡</button>
        <button type="button" class="btn small-btn" data-ls-edit="${s.id}" title="${esc(L('Bewerken'))}">✎</button>
        <button type="button" class="btn small-btn" data-ls-del="${s.id}" title="${esc(L('Verwijderen'))}">🗑</button></td></tr>
      <tr class="lg-lines" data-ls-lines-for="${s.id}" hidden><td colspan="8"><pre class="code small">${L('Laden…')}</pre></td></tr>`;
  }).join('');
  const limitPct = d.day_limit ? Math.min(100, Math.round(d.used_today / d.day_limit * 100)) : 0;
  box.innerHTML = `${d.on ? '' : `<div class="callout">⏸ ${L('Logs verzamelen staat uit voor alle servers: Instellingen → Monitoring → Logs.')}</div>`}
    ${d.sources.length ? amRow('🔎 ' + L('Zoeken in deze logs'), L('de pagina Logs, met deze server al gekozen'), `<button type="button" class="btn small-btn" id="ls-find">${L('Logs')} →</button>`) : ''}
    ${amRow(L('Versturen elke'), L('pakketje met de nieuwe regels, gecomprimeerd en versleuteld · algemeen: {n} min', { n: d.default_interval }),
      `<select id="ls-iv">${[[0, L('algemeen')], [1, '1 min'], [2, '2 min'], [5, '5 min'], [10, '10 min'], [15, '15 min'], [30, '30 min'], [60, '60 min']]
        .map(([k, t]) => `<option value="${k}" ${k === iv ? 'selected' : ''}>${t}</option>`).join('')}</select>`)}
    ${amRow(L('Vandaag'), L('{used} van de daglimiet van {lim} (ongecomprimeerd)', { used: fmtBytes(d.used_today), lim: fmtBytes(d.day_limit) }),
      `<span class="lg-bar${limitPct >= 100 ? ' full' : ''}"><i style="width:${limitPct}%"></i></span>`)}
    <p class="small muted lg-note">🔒 ${L('De agent leest alleen bestanden binnen de mappen die je óp de server toestaat: <code>LOG_DIRS="/var/log"</code> in <code>/etc/default/rzmon-agent</code> (meerdere mappen met een spatie). Een nieuwe bron begint bij het einde: oude regels worden niet ingelezen.')}</p>
    ${d.sources.length ? `<div class="table-scroll"><table class="vuln-table lg-table"><thead><tr><th>${L('Aan')}</th><th>${L('Bron')}</th><th>${L('Soort')}</th><th>${L('Niveau')}</th>
      <th class="num">${L('Regels vandaag')}</th><th class="num">${L('Vandaag')}</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
      : `<p class="muted">${L('Nog geen bronnen. Kies hieronder wat de agent op de server vond, of voeg er zelf een toe.')}</p>`}
    <div class="lf-box" id="ls-found"><p class="muted small">${L('Laden…')}</p></div>
    <div class="lg-form" id="ls-form">
      <div class="lg-form-h"><b id="ls-form-t">＋ ${L('Zelf een bron toevoegen')}</b><span class="lg-presets">${LS_PRESETS().map(([n, k, sp], i) => `<button type="button" class="dchip" data-ls-preset="${i}">${esc(n)}</button>`).join('')}</span></div>
      <input type="hidden" name="id" value="">
      <div class="lg-fields">
        <label>${L('Soort')}<select name="kind">${Object.entries(kinds).map(([k, [t]]) => `<option value="${k}">${esc(t)}</option>`).join('')}</select></label>
        <label class="lg-spec">${L('Bron')}<input name="spec" placeholder="${esc(kinds.file[1])}"><small class="muted" id="ls-spec-hint">${esc(kinds.file[2])}</small></label>
        <label>${L('Naam')}<input name="name" maxlength="100" placeholder="${esc(L('bv. nginx'))}"></label>
        <label>${L('Niveau')}<select name="level">${LS_LEVELS().map(([k, t]) => `<option value="${k}">${esc(t)}</option>`).join('')}</select></label>
      </div>
      <div class="lg-form-b"><button type="button" class="btn primary" id="ls-save">${L('Bron opslaan')}</button><button type="button" class="btn" id="ls-cancel" hidden>${L('Annuleren')}</button>
        <span class="muted small">${L('Niveau herkent de agent aan de tekst (error, warning, …); bij de journal aan de prioriteit.')}</span></div>
    </div>`;
  // Geen <form>: de Agent Manager is zelf al een formulier (een formulier daarin laat de browser weg)
  const f = $('#ls-form', box);
  const F = (n) => f.querySelector(`[name="${n}"]`);
  const kindHint = () => {
    const k = kinds[F('kind').value] || kinds.file;
    F('spec').placeholder = k[1]; $('#ls-spec-hint', box).textContent = k[2];
    F('spec').disabled = F('kind').value === 'journal';
  };
  const resetForm = () => { F('kind').value = 'file'; F('spec').value = ''; F('name').value = ''; F('level').value = '0'; F('id').value = ''; $('#ls-form-t', box).textContent = '＋ ' + L('Bron toevoegen'); $('#ls-cancel', box).hidden = true; kindHint(); };
  F('kind').onchange = kindHint;
  kindHint();
  $('#ls-cancel', box).onclick = resetForm;
  if ($('#ls-find', box)) $('#ls-find', box).onclick = () => openLogsFor(h);
  lfLoad(h);   // 1.213.0: gevonden op de server
  const save = async () => {
    try {
      await api('log_source_save', { host_id: h.id, id: +F('id').value || 0, kind: F('kind').value, spec: F('spec').value, name: F('name').value, level: +F('level').value, enabled: 1 });
      toast(L('Opgeslagen; de agent pakt het binnen een minuut op'));
      amLogs(h);
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#ls-save', box).onclick = save;
  f.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); save(); } });
  $('#ls-iv', box).onchange = async (e) => {
    try { await api('host_logs_interval', { id: h.id, minutes: +e.target.value || null }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); }
  };
  box.onclick = async (e) => {
    const pre = e.target.closest('[data-ls-preset]');
    if (pre) {
      const [n, k, sp] = LS_PRESETS()[+pre.dataset.lsPreset];
      F('kind').value = k; kindHint(); F('spec').value = sp; F('name').value = n; F('spec').focus();
      return;
    }
    const ed = e.target.closest('[data-ls-edit]');
    if (ed) {
      const s = d.sources.find((x) => x.id === +ed.dataset.lsEdit);
      F('id').value = s.id; F('kind').value = s.kind; kindHint(); F('spec').value = s.spec; F('name').value = s.name; F('level').value = s.level;
      $('#ls-form-t', box).textContent = '✎ ' + L('Bron wijzigen'); $('#ls-cancel', box).hidden = false;
      f.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      return;
    }
    const del = e.target.closest('[data-ls-del]');
    if (del) {
      if (!del.classList.contains('danger')) {   // eerst vragen, in de knop zelf (de Agent Manager is al een venster)
        del.classList.add('danger'); del.textContent = L('Zeker?');
        setTimeout(() => { if (del.isConnected) { del.classList.remove('danger'); del.textContent = '🗑'; } }, 4000);
        return;
      }
      try { await api('log_source_delete', { id: +del.dataset.lsDel }); toast(L('Verwijderd')); amLogs(h); } catch (ex) { toast(ex.message, 'err'); }
      return;
    }
    const ln = e.target.closest('[data-ls-lines]');
    if (ln) {
      const tr = $(`[data-ls-lines-for="${ln.dataset.lsLines}"]`, box);
      tr.hidden = !tr.hidden;
      if (!tr.hidden) {
        try {
          const r = await api('log_source_lines', null, { id: ln.dataset.lsLines });
          $('pre', tr).innerHTML = r.lines.length
            ? r.lines.map((x) => `<span class="muted">${esc(x.ts.slice(11))}</span> <span class="lg-lv lv${x.level}">${LS_LVL_PILL[x.level] || ''}</span> ${esc(x.msg)}`).join('\n')
            : esc(L('Nog geen regels binnen (de agent stuurt elke paar minuten een pakketje).'));
        } catch (ex) { $('pre', tr).textContent = ex.message; }
      }
    }
  };
  box.onchange = async (e) => {
    const sw = e.target.closest('[data-ls-on]');
    if (!sw) return;
    try { await api('log_source_toggle', { id: +sw.dataset.lsOn, on: sw.checked }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); sw.checked = !sw.checked; }
  };
}

// ---------------------------------------------------------------- 1.212.0: vaste zoekacties die een melding geven
const LW = { list: null, max: 50, testBusy: 0, testTimer: 0 };
const LW_WIN = () => [[1, L('1 minuut')], [5, L('5 minuten')], [15, L('15 minuten')], [60, L('1 uur')], [1440, L('24 uur')]];
const LW_WIN_NONE = () => [[60, L('1 uur')], [360, L('6 uur')], [1560, L('26 uur')], [10080, L('7 dagen')]];
const LW_REST = () => [[15, L('15 minuten')], [30, L('30 minuten')], [60, L('1 uur')], [240, L('4 uur')], [1440, L('24 uur')]];
const LW_SEV = () => [[0, 'Info'], [1, 'Warning'], [2, 'Critical']];
const lwWinText = (m) => (m % 1440 === 0 ? L('{n} dag(en)', { n: m / 1440 }) : m % 60 === 0 ? L('{n} uur', { n: m / 60 }) : L('{n} min', { n: m }));

/** De zoekopdracht van de zoekpagina, met de gekozen servers, bronnen en het niveau erin */
function lwQueryFromSearch() {
  const fs = (LP.facets && LP.facets.sources) || [];
  const word = (n) => String(n || '').split(/\s+/)[0];
  const parts = [($('#lp-q') ? $('#lp-q').value : LP.q).trim()];
  LP.hosts.forEach((id) => { const s = fs.find((x) => x.host_id === id); if (s) parts.push('server:' + word(s.host)); });
  LP.sources.forEach((id) => { const s = fs.find((x) => x.id === id); if (s) parts.push('server:' + word(s.host), 'bron:' + word(s.name)); });
  if (LP.level !== '' && LP.level !== null && LP.level !== undefined) parts.push('niveau:' + LP_LV[+LP.level]);
  return [...new Set(parts.filter(Boolean))].join(' ');
}

async function lwLoad() {
  const box = $('#lp-watch-box');
  if (!box) return;
  try {
    const r = await api('log_watches');
    LW.list = r.watches; LW.max = r.max;
  } catch (ex) { box.innerHTML = `<p class="danger-txt">${esc(ex.message)}</p>`; return; }
  lwCount();
  const can = isAdmin() || isUser();
  const cond = (w) => (w.mode === 'none' ? L('geen treffer in {w}', { w: lwWinText(w.win) }) : L('≥ {n} in {w}', { n: dec(w.threshold), w: lwWinText(w.win) }))
    + ' · ' + (w.per_host ? L('per server') : L('samen'));
  const sevTxt = (s) => `<span class="lw-sev lw-sev${s}">${['info', 'warning', 'critical'][s]}</span>`;
  const status = (w) => {
    if (!w.enabled) return `<span class="lw-pill off">${w.off_reason === 'slow' ? L('Uit: te traag') : L('Uit')}</span>`;
    const open = w.state.filter((s) => s.open);
    if (open.length) return open.map((s) => `<span class="lw-pill bad k${w.severity}">${esc(L('Melding open'))}${s.host ? ' · ' + esc(s.host) : ''}</span>`).join(' ');
    if (w.mode === 'none') {
      const last = w.state.map((s) => s.last_hit).filter(Boolean).sort().pop();
      return `<span class="lw-pill ok">OK${last ? ' · ' + esc(L('laatst {t}', { t: fmtDateTime(last) })) : ''}</span>`;
    }
    return '<span class="lw-pill ok">OK</span>';
  };
  const spark = (w) => {
    const m = Math.max(1, ...w.hours);
    const hot = w.mode === 'many' ? w.threshold : Infinity;
    return `<span class="lw-spark" role="img" aria-label="${esc(L('Treffers per uur, laatste 24 uur: {n} in totaal', { n: dec(w.hours.reduce((a, b) => a + b, 0)) }))}">${w.hours.map((v) => `<i class="${v >= hot ? 'h' : ''}" style="height:${Math.max(8, v / m * 100)}%"></i>`).join('')}</span>`;
  };
  box.innerHTML = `<div class="lp-row"><p class="muted small lw-intro">${L('Een vaste zoekactie geeft een melding als er te veel (of geen) regels binnenkomen die erop passen. Maak er een met 🔔 Bewaren als melding op het tabblad Zoeken.')}</p><span class="lp-sp"></span>
      ${can ? `<button type="button" class="btn" id="lw-new">＋ ${L('Nieuwe zoekactie')}</button>` : ''}</div>
    ${LW.list.length ? `<div class="lw-tw"><table class="lw-table"><thead><tr><th>${L('Aan')}</th><th>${L('Naam en zoekopdracht')}</th><th>${L('Melden als')}</th><th>${L('Laatste 24 uur')}</th><th>${L('Status')}</th><th>${L('Laatst gemeld')}</th><th></th></tr></thead><tbody>
      ${LW.list.map((w) => `<tr data-lw="${w.id}">
        <td>${can ? amSw('lw-on-' + w.id, w.enabled, `data-lw-on="${w.id}"`) : (w.enabled ? '✓' : '—')}</td>
        <td><b>${esc(w.name)}</b><code class="lw-q" title="${esc(w.q)}">${esc(w.q)}</code></td>
        <td class="small">${esc(cond(w))} · ${sevTxt(w.severity)}</td>
        <td>${spark(w)}</td>
        <td>${status(w)}</td>
        <td class="mono small">${w.last_alert ? esc(new Date(w.last_alert.replace(' ', 'T')).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })) : '—'}</td>
        <td><div class="lw-acts"><button type="button" class="btn small-btn" data-lw-find="${w.id}">🔎 ${L('Zoeken')}</button>
          ${can ? `<button type="button" class="btn small-btn" data-lw-edit="${w.id}" aria-label="${esc(L('Bewerken'))}">✎</button><button type="button" class="btn small-btn" data-lw-del="${w.id}" aria-label="${esc(L('Verwijderen'))}">🗑</button>` : ''}</div></td></tr>`).join('')}
      </tbody></table></div>
      <p class="muted small">${L('Rode streepjes: een uur waarin de drempel gehaald werd. Meldingen gaan via je gewone kanalen en staan op de pagina Meldingen.')}</p>`
    : `<p class="muted lp-empty">${L('Nog geen vaste zoekacties. Zoek op het tabblad Zoeken naar iets dat je wilt bewaken, bijvoorbeeld "failed password", en klik op 🔔 Bewaren als melding.')}</p>`}`;
  box.onclick = async (e) => {
    if (e.target.closest('#lw-new')) return lwEdit({ q: '' });
    const f = e.target.closest('[data-lw-find]');
    if (f) { const w = LW.list.find((x) => x.id === +f.dataset.lwFind); LP.q = w.q; LP.hosts = []; LP.sources = []; LP.level = ''; LP.hour = null; LP.limit = 100; LP.allLv = null; LP.sub = 'search'; return loadLogsPage(); }
    const ed = e.target.closest('[data-lw-edit]');
    if (ed) return lwEdit(LW.list.find((x) => x.id === +ed.dataset.lwEdit));
    const del = e.target.closest('[data-lw-del]');
    if (del) {
      if (!del.classList.contains('confirm')) { del.classList.add('confirm'); del.textContent = L('Zeker?'); setTimeout(() => { del.classList.remove('confirm'); del.textContent = '🗑'; }, 4000); return; }
      try { await api('log_watch_delete', { id: +del.dataset.lwDel }); toast(L('Verwijderd')); lwLoad(); } catch (ex) { toast(ex.message, 'err'); }
    }
  };
  box.onchange = async (e) => {
    const sw = e.target.closest('[data-lw-on]');
    if (!sw) return;
    try { await api('log_watch_toggle', { id: +sw.dataset.lwOn, on: sw.checked }); toast(L('Opgeslagen')); lwLoad(); } catch (ex) { toast(ex.message, 'err'); sw.checked = !sw.checked; }
  };
}

function lwCount() {
  const n = $('#lp-w-n');
  if (!n || !LW.list) return;
  const open = LW.list.filter((w) => w.enabled && w.state.some((s) => s.open)).length;
  n.textContent = LW.list.length ? String(LW.list.length) : '';
  n.classList.toggle('bad', open > 0);
  n.title = open ? L('{n} met een open melding', { n: open }) : '';
}

/** Venster Bewaren als melding (nieuw, of ✎ bij een bestaande), met de proef op de afgelopen 24 uur */
function lwEdit(w) {
  const v = Object.assign({ id: 0, name: '', q: '', mode: 'many', threshold: 10, win: 5, per_host: true, severity: 1, rest: 30, recover: true }, w || {});
  const seg = (name, opts, cur) => `<div class="seg lw-seg" data-lw-seg="${name}">${opts.map(([k, t]) => `<button type="button" class="seg-btn${String(k) === String(cur) ? ' active' : ''}" data-v="${k}">${esc(t)}</button>`).join('')}</div><input type="hidden" name="${name}" value="${esc(String(cur))}">`;
  const opts = (list, cur) => list.map(([k, t]) => `<option value="${k}"${+k === +cur ? ' selected' : ''}>${esc(t)}</option>`).join('');
  const { form } = modal({
    title: v.id ? L('Vaste zoekactie bewerken') : '🔔 ' + L('Bewaren als melding'), wide: true,
    body: `<div class="lw-split"><div class="lw-form">
        <input type="hidden" name="id" value="${v.id || ''}">
        <div class="lw-f"><label for="lw-name">${L('Naam')}</label><input id="lw-name" name="name" maxlength="100" required value="${esc(v.name)}" placeholder="${esc(L('bv. SSH-aanvallen'))}">
          <label for="lw-q">${L('Zoekopdracht')}</label><input id="lw-q" name="q" class="mono" maxlength="500" required value="${esc(v.q)}" spellcheck="false">
          <span class="lw-sub">${L('Dezelfde regels als bij zoeken: woorden, "exacte zin", -uitsluiten, server:, bron:, niveau: en /regex/.')}</span>
          <span class="lw-lbl">${L('Melden als')}</span><div>${seg('mode', [['many', L('Te veel treffers')], ['none', L('Geen treffers')]], v.mode)}</div>
          <span class="lw-lbl">${L('Drempel')}</span>
          <div class="lw-inl" id="lw-many" ${v.mode === 'none' ? 'hidden' : ''}><span>${L('minstens')}</span><input type="number" name="threshold" min="1" max="100000" value="${v.threshold}" class="lw-n" aria-label="${esc(L('Aantal treffers'))}"><span>${L('treffers in')}</span>
            <select name="win_many" aria-label="${esc(L('Venster'))}">${opts(LW_WIN(), v.mode === 'many' ? v.win : 5)}</select></div>
          <div class="lw-inl" id="lw-none" ${v.mode === 'none' ? '' : 'hidden'}><span>${L('geen enkele treffer in')}</span><select name="win_none" aria-label="${esc(L('Venster'))}">${opts(LW_WIN_NONE(), v.mode === 'none' ? v.win : 1560)}</select></div>
          <span class="lw-lbl">${L('Servers')}</span><div>${seg('per_host', [[1, L('Per server apart')], [0, L('Alle servers samen')]], v.per_host ? 1 : 0)}</div>
          <span class="lw-lbl">${L('Ernst')}</span><div>${seg('severity', LW_SEV(), v.severity)}</div>
          <label for="lw-rest">${L('Rustperiode')}</label><div class="lw-inl"><select id="lw-rest" name="rest">${opts(LW_REST(), v.rest)}</select><span class="muted small">${L('tussen twee meldingen van deze zoekactie')}</span></div>
          <span class="lw-lbl">${L('Herstel')}</span><label class="lw-check"><input type="checkbox" name="recover" ${v.recover ? 'checked' : ''}> ${L('Melding vanzelf sluiten als het een heel venster rustig is (of er weer een treffer is), met een herstelmelding')}</label>
        </div>
        <div class="lw-sentence" id="lw-sent" aria-live="polite"></div>
        <p class="muted small">${L('Stille uren gelden ook hiervoor (die van de server, of de algemene).')}</p>
      </div>
      <div class="lw-proef"><div class="lp-row"><b>${L('Proef: de afgelopen 24 uur')}</b><span class="lp-sp"></span><span class="muted small" id="lw-ms"></span></div>
        <div class="lw-big-row"><span class="lw-big" id="lw-pn">…</span><span id="lw-pt" class="small"></span></div>
        <div class="lw-hist" id="lw-ph" role="img" aria-label="${esc(L('Treffers per 5 minuten, met de drempel'))}"></div>
        <div class="lp-ax" id="lw-pax"></div>
        <div class="lw-times" id="lw-times"></div>
        <p class="muted small">${L('Zo zie je vóór het bewaren of de drempel te gevoelig is. Oranje: een tijdvak waarin de drempel gehaald werd; door de rustperiode worden vensters dicht bij elkaar één melding.')}</p></div></div>`,
    buttons: [{ label: L('Annuleren'), cls: '', onClick: () => $('#modal').close() }, { label: v.id ? L('Opslaan') : L('Bewaren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('log_watch_save', lwVals(d));
      toast(v.id ? L('Opgeslagen') : L('Bewaard: je krijgt een melding als het zover is'));
      LP.sub = 'watch';
      setTimeout(() => { if ($('#tab-logs') && !$('#tab-logs').hidden) loadLogsPage(); }, 50);
      return r;
    },
  });
  form.onclick = (e) => {
    const b = e.target.closest('[data-lw-seg] [data-v]');
    if (!b) return;
    const g = b.closest('[data-lw-seg]');
    $$('[data-v]', g).forEach((x) => x.classList.toggle('active', x === b));
    $(`input[name="${g.dataset.lwSeg}"]`, form).value = b.dataset.v;
    if (g.dataset.lwSeg === 'mode') { $('#lw-many').hidden = b.dataset.v === 'none'; $('#lw-none').hidden = b.dataset.v !== 'none'; }
    lwChanged(form);
  };
  form.oninput = () => lwChanged(form);
  form.onchange = () => lwChanged(form);
  lwChanged(form, 0);
  setTimeout(() => { const n = $('#lw-name'); if (n && !n.value) n.focus(); }, 30);
}

function lwVals(d) {
  return { id: d.id ? +d.id : 0, name: d.name, q: d.q, mode: d.mode, threshold: +d.threshold || 1, win: +(d.mode === 'none' ? d.win_none : d.win_many),
    per_host: d.per_host === '1', severity: +d.severity, rest: +d.rest, recover: !!d.recover };
}

function lwChanged(form, delay = 450) {
  const v = lwVals(Object.fromEntries(new FormData(form)));
  const sev = ['info', 'warning', 'critical'][v.severity];
  const per = v.per_host ? L('per server') : L('voor alle servers samen');
  const q = `<code>${esc(v.q || '…')}</code>`;
  $('#lw-sent').innerHTML = v.mode === 'none'
    ? L('Een {sev}-melding als {q} {w} lang niet voorkomt, {per}.', { sev: `<b>${sev}</b>`, q, w: `<b>${esc(lwWinText(v.win))}</b>`, per })
    : L('Een {sev}-melding als {q} minstens {n}× voorkomt in {w}, {per}; daarna minstens {r} rust.', { sev: `<b>${sev}</b>`, q, n: `<b>${dec(v.threshold)}</b>`, w: `<b>${esc(lwWinText(v.win))}</b>`, per, r: esc(lwWinText(v.rest)) });
  clearTimeout(LW.testTimer);
  LW.testTimer = setTimeout(() => lwTest(v), delay);
}

async function lwTest(v) {
  const busy = ++LW.testBusy;
  if (!v.q.trim()) { $('#lw-pn').textContent = '–'; $('#lw-pt').textContent = L('Vul een zoekopdracht in'); $('#lw-ph').innerHTML = ''; $('#lw-times').innerHTML = ''; return; }
  let r;
  try { r = await api('log_watch_test', Object.assign({}, v, { name: v.name || 'proef' })); } catch (ex) {
    if (busy !== LW.testBusy || !$('#lw-pn')) return;
    $('#lw-pn').textContent = '–'; $('#lw-pt').innerHTML = `<span class="danger-txt">${esc(ex.message)}</span>`; $('#lw-ph').innerHTML = ''; $('#lw-times').innerHTML = '';
    return;
  }
  if (busy !== LW.testBusy || !$('#lw-pn')) return;
  const byHost = Object.entries(r.by_host).map(([h, n]) => `${h || L('alle servers')} ${n}×`).join(', ');
  $('#lw-pn').textContent = dec(r.count);
  $('#lw-pt').textContent = (r.mode === 'none'
    ? (r.count ? L('keer een melding nu (geen treffer in het venster)') : L('keer: er is een treffer binnen het venster')) + (r.last ? ' · ' + L('laatste treffer {t}', { t: new Date(r.last * 1000).toLocaleString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }) : ' · ' + L('nog geen treffer gezien'))
    : L('keer een melding')) + (byHost && r.mode !== 'none' ? ` (${byHost})` : '') + (r.partial ? ' · ' + L('onvolledig: te veel logs') : '');
  $('#lw-ms').textContent = L('{ms} ms', { ms: dec(r.ms) });
  const m = Math.max(1, ...r.hist, r.mode === 'many' ? r.threshold : 0);
  const thr = r.mode === 'many' ? r.threshold : null;
  $('#lw-ph').innerHTML = r.hist.map((n, i) => `<i class="${thr !== null && r.max_win[i] >= thr ? 'hit' : ''}" style="height:${Math.max(2, n / m * 100)}%" title="${esc(new Date((r.start + i * r.bucket) * 1000).toTimeString().slice(0, 5) + ': ' + n)}"></i>`).join('')
    + (thr !== null ? `<span class="lw-thr" style="bottom:${Math.min(100, thr / m * 100)}%"><span>${dec(thr)}</span></span>` : '');
  const t = (s) => new Date(s * 1000).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  $('#lw-pax').innerHTML = [0, 0.25, 0.5, 0.75, 1].map((f) => `<span>${esc(t(r.start + f * (r.hist.length * r.bucket)))}</span>`).join('');
  $('#lw-times').innerHTML = r.fires.slice(-24).map((f) => `<span class="lw-tm">${esc(t(f.ts))}${f.host ? ' · ' + esc(f.host) : ''}${f.n ? ' · ' + dec(f.n) : ''}</span>`).join('');
}

// ---------------------------------------------------------------- 1.211.0: pagina Logs (zoeken en binnenkomst)
const LP = { q: '', range: '24h', from: 0, to: 0, level: '', hosts: [], sources: [], limit: 100, hour: null, sub: 'search', facets: null, last: null, busy: 0 };
const LP_RANGES = () => [['15m', L('Laatste 15 minuten'), 900], ['1h', L('Laatste uur'), 3600], ['24h', L('Laatste 24 uur'), 86400], ['today', L('Vandaag'), 0],
  ['7d', L('Laatste 7 dagen'), 7 * 86400], ['all', L('Alles'), 365 * 86400], ['custom', L('Zelf kiezen…'), 0]];
const LP_LV = ['debug', 'info', 'warning', 'error', 'critical'];

/** Vanaf een server: de zoekpagina met server: ingevuld */
function openLogsFor(h) {
  LP.q = h ? `server:${/\s/.test(h.name) ? h.name.split(/\s/)[0] : h.name}` : '';
  LP.hosts = []; LP.sources = []; LP.hour = null; LP.sub = 'search';
  if ($('#modal') && $('#modal').open) $('#modal').close();
  showTab('logs');
}

function lpPeriod() {
  if (LP.hour) return [LP.hour[0], LP.hour[1]];
  const now = Math.floor(Date.now() / 1000);
  if (LP.range === 'today') { const d = new Date(); d.setHours(0, 0, 0, 0); return [Math.floor(d / 1000), now]; }
  if (LP.range === 'custom' && LP.from) return [LP.from, LP.to || now];
  const r = LP_RANGES().find(([k]) => k === LP.range) || LP_RANGES()[2];
  return [now - r[2], now];
}

/** 1.214.0: de Logs-pagina komt binnen zoals Containers: kaarten, staafjes en regels na elkaar (een paar seconden lang) */
function lpEnter(el) {
  el.classList.remove('lp-enter'); void el.offsetWidth; el.classList.add('lp-enter');
  clearTimeout(lpEnter.t); lpEnter.t = setTimeout(() => el.classList.remove('lp-enter'), 2500);
}
async function loadLogsPage() {
  const el = $('#logs-page');
  if (!el) return;
  if (!el.dataset.built) lpBuild(el);
  lpEnter(el);   // 1.214.0: binnenkomen met een animatie, zoals de andere pagina's
  $$('#logs-sub [data-lp-sub]').forEach((b) => b.classList.toggle('active', b.dataset.lpSub === LP.sub));
  $('#lp-search').hidden = LP.sub !== 'search';
  $('#lp-in').hidden = LP.sub !== 'in';
  $('#lp-watch').hidden = LP.sub !== 'watch';
  if (LP.sub === 'in') { loadLogsOverview('#lp-in-box'); return; }
  if (LP.sub === 'watch') { lwLoad(); return; }   // 1.212.0
  if (!LW.list) api('log_watches').then((r) => { LW.list = r.watches; lwCount(); }).catch(() => {});
  $('#lp-custom').hidden = LP.range !== 'custom';
  $('#lp-q').value = LP.q;
  $('#lp-range').value = LP.range;
  // 1.220.1: bronnen en zoeken tegelijk starten (meteen, niet na elkaar), zodat de laadanimatie loopt tot de regels er zijn,
  // en de regels met de binnenkomst-animatie verschijnen, ook als het zoeken langer duurt
  LP.enter = true;
  const facets = LP.facets ? null : api('log_facets').catch(() => ({ sources: [], days: 14 }));
  lpSearch();
  if (facets) LP.facets = await facets;
  lpChips();
}

function lpBuild(el) {
  el.dataset.built = '1';
  el.innerHTML = `
    <div class="lp-bar"><div class="seg" id="logs-sub" role="tablist">
      <button type="button" class="seg-btn active" data-lp-sub="search" role="tab">🔎 ${L('Zoeken')}</button>
      <button type="button" class="seg-btn" data-lp-sub="watch" role="tab">🔔 ${L('Vaste zoekacties')} <b class="lp-w-n" id="lp-w-n"></b></button>
      <button type="button" class="seg-btn" data-lp-sub="in" role="tab">📥 ${L('Binnenkomst')}</button></div></div>
    <section id="lp-search" class="card lp-card">
      <div class="lp-q">
        <label class="lp-in"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14"/></svg>
          <input id="lp-q" type="search" autocomplete="off" spellcheck="false" placeholder="${esc(L('Zoek in de logs, bv. "failed password" server:web01 -192.168.1.'))}" aria-label="${esc(L('Zoeken in logs'))}"></label>
        <select id="lp-range" aria-label="${esc(L('Periode'))}">${LP_RANGES().map(([k, t]) => `<option value="${k}">${esc(t)}</option>`).join('')}</select>
        <button type="button" class="btn primary" id="lp-go">${L('Zoeken')}</button>
        ${isAdmin() || isUser() ? `<button type="button" class="btn lp-save-w" id="lp-save-w" title="${esc(L('Een melding als deze zoekopdracht te vaak (of nooit) voorkomt'))}">🔔 ${L('Bewaren als melding')}</button>` : ''}
      </div>
      <div class="lp-custom" id="lp-custom" hidden><label>${L('Van')} <input type="datetime-local" id="lp-from"></label><label>${L('Tot')} <input type="datetime-local" id="lp-to"></label></div>
      <div class="lp-help small muted"><span>${L('Zo zoek je:')}</span>${['woord', '"exacte zin"', '-uitsluiten', 'server:', 'bron:', 'niveau:error', '/regex/'].map((t) => `<button type="button" class="lp-tok" data-lp-tok="${esc(t)}">${esc(t)}</button>`).join('')}
        <span>· ${L('hoofdletters maken niet uit · alle woorden moeten erin staan')}</span></div>
      <div class="lp-row"><div class="lp-chips" id="lp-levels" role="group" aria-label="${esc(L('Niveau'))}"></div><span class="lp-sp"></span>
        <div class="lp-chips" id="lp-scope"></div></div>
      <div class="lp-hist-box"><div class="lp-row"><b class="small" id="lp-total"></b><span class="muted small" id="lp-stat"></span><span class="lp-sp"></span>
        <span class="lp-legend small muted"><span style="--c:var(--crit)">error</span><span style="--c:var(--warn)">warning</span><span style="--c:var(--primary)">info</span></span></div>
        <div class="lp-hist" id="lp-hist" role="img"></div><div class="lp-ax" id="lp-ax"></div>
        <p class="muted small lp-hint" id="lp-hint">${L('Klik op een balk om alleen dat tijdvak te zien; nog eens klikken heft het op.')}</p></div>
      <div id="lp-res" class="lp-lines"></div>
      <div class="lp-more" id="lp-more"></div>
    </section>
    <section id="lp-watch" class="card lp-card" hidden><div id="lp-watch-box"><p class="muted">${L('Laden…')}</p></div></section>
    <section id="lp-in" class="card lp-card" hidden><div id="lp-in-box"><p class="muted">${L('Laden…')}</p></div></section>`;
  const go = () => { LP.q = $('#lp-q').value.trim(); LP.limit = 100; LP.hour = null; LP.allLv = null; lpSearch(); };
  $('#lp-go').onclick = go;
  $('#lp-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
  $('#lp-range').onchange = (e) => {
    LP.range = e.target.value; LP.hour = null;
    $('#lp-custom').hidden = LP.range !== 'custom';
    if (LP.range !== 'custom') { LP.limit = 100; lpSearch(); }
  };
  const cust = () => {
    const f = $('#lp-from').value, t = $('#lp-to').value;
    if (!f) return;
    LP.from = Math.floor(new Date(f) / 1000); LP.to = t ? Math.floor(new Date(t) / 1000) : 0; LP.limit = 100; lpSearch();
  };
  $('#lp-from').onchange = cust; $('#lp-to').onchange = cust;
  el.addEventListener('click', async (e) => {
    const sub = e.target.closest('[data-lp-sub]');
    if (sub) { LP.sub = sub.dataset.lpSub; loadLogsPage(); return; }
    const tok = e.target.closest('[data-lp-tok]');
    if (tok) { const q = $('#lp-q'); q.value = (q.value + ' ' + tok.dataset.lpTok).trim(); q.focus(); return; }
    const lv = e.target.closest('[data-lp-lv]');
    if (lv) { LP.level = lv.dataset.lpLv; LP.limit = 100; lpSearch(); return; }
    const rm = e.target.closest('[data-lp-rm]');
    if (rm) { const [k, id] = rm.dataset.lpRm.split(':'); LP[k] = LP[k].filter((x) => x !== +id); LP.limit = 100; lpChips(); lpSearch(); return; }
    const bar = e.target.closest('[data-lp-b]');
    if (bar && LP.last) {
      const i = +bar.dataset.lpB, s = LP.last.start + i * LP.last.bucket;
      LP.hour = LP.hour && LP.hour[0] === s ? null : [s, s + LP.last.bucket - 1];
      LP.limit = 100; lpSearch(); return;
    }
    if (e.target.closest('#lp-more-btn')) { LP.limit = Math.min(1000, LP.limit + 100); lpSearch(); return; }
    if (e.target.closest('#lp-csv')) { lpCsv(); return; }
    if (e.target.closest('#lp-save-w')) { lwEdit({ q: lwQueryFromSearch() }); return; }   // 1.212.0
    const ln = e.target.closest('.lp-ln[data-ref]');
    if (ln && !e.target.closest('a, button')) lpContext(ln);
  });
  el.addEventListener('change', (e) => {
    const s = e.target.closest('[data-lp-add]');
    if (!s || !s.value) return;
    const k = s.dataset.lpAdd, id = +s.value;
    if (!LP[k].includes(id)) LP[k].push(id);
    LP.limit = 100; lpChips(); lpSearch();
  });
}

function lpChips() {
  const fs = (LP.facets && LP.facets.sources) || [];
  const hostName = (id) => (fs.find((s) => s.host_id === id) || {}).host || '?';
  const hosts = [...new Map(fs.map((s) => [s.host_id, s.host])).entries()];
  const chips = [
    ...LP.hosts.map((id) => `<button type="button" class="dchip on" data-lp-rm="hosts:${id}">${esc(hostName(id))} <span aria-hidden="true">✕</span></button>`),
    ...LP.sources.map((id) => { const s = fs.find((x) => x.id === id); return `<button type="button" class="dchip on" data-lp-rm="sources:${id}">${esc(s ? s.host + ' › ' + s.name : '?')} <span aria-hidden="true">✕</span></button>`; }),
  ];
  $('#lp-scope').innerHTML = chips.join('')
    + `<select class="lp-add" data-lp-add="hosts" aria-label="${esc(L('Server toevoegen'))}"><option value="">＋ ${L('Server')}</option>${hosts.filter(([id]) => !LP.hosts.includes(id)).map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('')}</select>`
    + `<select class="lp-add" data-lp-add="sources" aria-label="${esc(L('Bron toevoegen'))}"><option value="">＋ ${L('Bron')}</option>${fs.filter((s) => !LP.sources.includes(s.id) && (!LP.hosts.length || LP.hosts.includes(s.host_id))).map((s) => `<option value="${s.id}">${esc(s.host + ' › ' + s.name)}</option>`).join('')}</select>`;
}

async function lpSearch() {
  const [from, to] = lpPeriod();
  const busy = ++LP.busy;
  $('#lp-res').classList.add('lp-loading');
  let r;
  try {
    r = await api('log_search', null, { q: LP.q, from, to, level: LP.level, hosts: LP.hosts.join(','), sources: LP.sources.join(','), limit: LP.limit });
  } catch (ex) {
    if (busy !== LP.busy) return;
    $('#lp-res').classList.remove('lp-loading');
    $('#lp-res').innerHTML = `<p class="danger-txt lp-empty">${esc(ex.message)}</p>`;
    return;
  }
  if (busy !== LP.busy) return;   // er kwam al een nieuwere zoekopdracht
  LP.last = r;
  $('#lp-res').classList.remove('lp-loading');
  if (LP.enter) { LP.enter = false; lpEnter($('#logs-page')); }   // 1.220.1: de regels komen binnen met de animatie
  // Niveaus (telling over de hele periode)
  // Aantallen per niveau van de zoekopdracht zonder niveaufilter (anders staan de andere niveaus op 0)
  if (LP.level === '') LP.allLv = r.levels;
  const lv = (LP.level !== '' && LP.allLv) || r.levels, all = lv.reduce((a, b) => a + b, 0);
  $('#lp-levels').innerHTML = [['', L('Alles'), all], ['3', L('Error en erger'), lv[3] + lv[4]], ['2', 'Warning', lv[2]], ['1', 'Info', lv[0] + lv[1]]]
    .map(([k, t, n]) => `<button type="button" class="dchip ${LP.level === k ? 'on' : ''}" data-lp-lv="${k}">${esc(t)} <b>${dec(n)}</b></button>`).join('');
  $('#lp-total').textContent = r.total === 1 ? L('1 treffer') : L('{n} treffers', { n: dec(r.total) });
  $('#lp-stat').textContent = L('{l} regels doorzocht in {b} blokken ({s} s)', { l: dec(r.scanned), b: dec(r.blocks), s: dec((r.ms / 1000).toFixed(2)) })
    + (r.partial ? ' · ' + L('telling onvolledig: te veel om binnen 8 s te tellen, kies een kortere periode') : '');
  // Grafiek
  const max = Math.max(1, ...r.hist.map((b) => b[0] + b[1] + b[2]));
  const fmt = (t) => { const d = new Date(t * 1000); return r.bucket >= 86400 ? d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) : r.bucket >= 3600 && to - from > 86400 ? d.toLocaleString(LOCALE, { weekday: 'short', hour: '2-digit', minute: '2-digit' }) : d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }); };
  $('#lp-hist').innerHTML = r.hist.map((b, i) => {
    const n = b[0] + b[1] + b[2], s = r.start + i * r.bucket, sel = LP.hour && LP.hour[0] === s;
    return `<button type="button" class="lp-b${sel ? ' sel' : ''}" data-lp-b="${i}" style="--b:${i};--h:${n ? Math.max(3, n / max * 100) : 0}%" title="${esc(fmt(s) + ': ' + L('{n} treffers', { n: dec(n) }))}"><i class="e" style="flex:${b[2]}"></i><i class="w" style="flex:${b[1]}"></i><i class="n" style="flex:${b[0]}"></i></button>`;
  }).join('');
  $('#lp-hist').setAttribute('aria-label', L('Treffers per tijdvak'));
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => r.start + Math.round(f * (r.hist.length - 1)) * r.bucket);
  $('#lp-ax').innerHTML = ticks.map((t) => `<span>${esc(fmt(t))}</span>`).join('');
  $('#lp-hint').textContent = LP.hour ? L('Alleen {t}; klik nog eens op de balk voor de hele periode.', { t: fmt(LP.hour[0]) }) : L('Klik op een balk om alleen dat tijdvak te zien; nog eens klikken heft het op.');
  // Treffers
  const words = r.words || [];
  const mark = (s) => {
    let h = esc(s);
    words.forEach((w) => { const e = esc(w).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); h = h.replace(new RegExp(e, 'gi'), (m) => `<mark>${m}</mark>`); });
    return h.replace(/••••/g, '<span class="lp-mask">••••</span>');
  };
  $('#lp-res').innerHTML = r.hits.length ? r.hits.map((h) => lpLine(h, mark)).join('')
    : `<p class="muted lp-empty">${(LP.facets && LP.facets.sources.length) ? L('Niets gevonden in deze periode.') : L('Nog geen logs. Kies per server welke logs hij stuurt: Agent Manager › kaart Logs.')}</p>`;
  $$('#lp-res > .lp-ln').forEach((ln, k) => { if (k < 40) ln.style.setProperty('--i', k); });   // 1.214.0: na elkaar binnenkomen
  $('#lp-more').innerHTML = r.hits.length ? `${r.hits.length >= r.limit && r.limit < 1000 ? `<button type="button" class="btn" id="lp-more-btn">${L('Meer laden (100)')}</button>` : ''}
    <button type="button" class="btn" id="lp-csv">⤓ CSV</button><span class="muted small">${L('nieuwste eerst · {n} getoond', { n: dec(r.hits.length) })}</span>` : '';
}

const lpLine = (h, mark, ctx = false) => `<div class="lp-ln lv${h.level}${ctx ? ' ctx' : ''}" ${h.ref ? `data-ref="${esc(h.ref)}" tabindex="0"` : ''}>
  <span class="lp-t" title="${esc(h.time)}">${esc(h.time.slice(5, 16).replace('-', '/'))}<small>${esc(h.time.slice(16))}</small></span>
  <span><span class="lp-lv lp-lv${h.level}">${LP_LV[h.level] || ''}</span></span>
  <span class="lp-h">${h.host ? esc(h.host) + ' <small>› ' + esc(h.source) + '</small>' : `<small>${esc(h.source || '')}</small>`}</span>
  <span class="lp-m">${mark(h.msg)}</span>${h.ref ? '<span class="lp-o" aria-hidden="true">›</span>' : '<span></span>'}</div>`;

async function lpContext(ln) {
  const next = ln.nextElementSibling;
  if (next && next.classList.contains('lp-ctx')) { next.remove(); ln.classList.remove('open'); return; }
  ln.classList.add('open');
  const box = document.createElement('div');
  box.className = 'lp-ctx';
  box.innerHTML = `<p class="muted small">${L('Laden…')}</p>`;
  ln.after(box);
  try {
    const c = await api('log_context', null, { ref: ln.dataset.ref });
    const plain = (s) => esc(s).replace(/••••/g, '<span class="lp-mask">••••</span>');
    const row = (x) => lpLine({ ...x, host: '', source: x.source || '' }, plain, true);
    box.innerHTML = (c.before.length ? c.before.map(row).join('') : '')
      + `<div class="lp-ctx-here small muted">▲ ${L('ervoor')} · ${L('erna')} ▼</div>`
      + (c.after.length ? c.after.map(row).join('') : `<p class="muted small">${L('Geen regels erna.')}</p>`)
      + (c.other.length ? `<div class="lp-ctx-h small"><b>${L('Andere bronnen van deze server, zelfde minuut')}</b></div>${c.other.map(row).join('')}` : '');
  } catch (ex) { box.innerHTML = `<p class="danger-txt small">${esc(ex.message)}</p>`; }
}

/** CSV: de huidige zoekopdracht, tot 5000 regels (downloaden via een link, zoals de SBOM) */
function lpCsv() {
  const [from, to] = lpPeriod();
  const a = document.createElement('a');
  a.href = 'api.php?' + new URLSearchParams({ action: 'log_export', q: LP.q, from, to, level: LP.level, hosts: LP.hosts.join(','), sources: LP.sources.join(',') });
  a.download = '';
  document.body.appendChild(a); a.click(); a.remove();
}

// ---------------------------------------------------------------- 1.210.0: Instellingen › Monitoring › Logs (opslag en binnenkomst)
async function loadLogsOverview(sel = '#logs-ov') {
  const box = $(sel);
  if (!box) return;
  let o;
  try { o = await api('log_overview'); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  const pct = (x) => (o.max ? Math.max(x ? 1 : 0, Math.min(100, x / o.max * 100)) : 0);
  const daysLeft = o.days_left === null ? '' : o.days_left >= o.days
    ? L('vol over {n} dagen bij dit tempo; past ruim in {d} dagen bewaren', { n: dec(o.days_left), d: o.days })
    : L('vol over {n} dagen bij dit tempo: eerder dan {d} dagen bewaren, dan gaat de oudste dag eerder weg', { n: dec(o.days_left), d: o.days });
  const problems = o.hosts.filter((h) => h.paused || h.errors);
  box.innerHTML = `<div class="lg-ov">
    <div class="lg-store">
      <div class="lg-big"><b>${fmtBytes(o.used)}</b> <span class="muted">${L('van {max}', { max: fmtBytes(o.max) })}</span></div>
      <div class="lg-meter" role="img" aria-label="${esc(L('{a} van {b} gebruikt', { a: fmtBytes(o.used), b: fmtBytes(o.max) }))}">
        <i style="width:${pct(o.used - o.today)}%;background:var(--primary)"></i><i style="width:${pct(o.today)}%;background:var(--warn)"></i></div>
      <div class="lg-legend small muted"><span style="--c:var(--primary)">${L('eerdere dagen')}</span><span style="--c:var(--warn)">${L('vandaag {b}', { b: fmtBytes(o.today) })}</span></div>
      <dl class="lg-kv small">
        <dt>${L('Regels')}</dt><dd>${dec(o.rows)} · ${o.tables === 1 ? L('1 dag') : L('{n} dagen', { n: o.tables })}</dd>
        <dt>${L('Ongecomprimeerd')}</dt><dd>${fmtBytes(o.raw)}</dd>
        <dt>${L('Compressie')}</dt><dd>${o.ratio ? L('{x}× kleiner', { x: dec(o.ratio) }) : '–'}</dd>
        <dt>${L('Per dag')}</dt><dd>${o.avg_stored ? L('gem. {b} opgeslagen', { b: fmtBytes(o.avg_stored) }) : '–'}</dd>
        ${daysLeft ? `<dt>${L('Ruimte')}</dt><dd>${esc(daysLeft)}</dd>` : ''}
      </dl>
    </div>
    <div class="lg-in">
      <h3 class="small">${L('Binnenkomst vandaag')}</h3>
      ${o.hosts.length ? `<div class="table-scroll"><table class="vuln-table lg-table"><thead><tr><th>Server</th><th>${L('Bronnen')}</th><th class="num">${L('Regels vandaag')}</th>
        <th>${L('Van de daglimiet')}</th><th>${L('Elke')}</th><th>${L('Laatste regels')}</th><th>Status</th></tr></thead><tbody>
        ${o.hosts.map((h) => {
          const p = h.limit ? Math.min(100, Math.round(h.bytes_today / h.limit * 100)) : 0;
          const st = !h.sources ? `<span class="lg-pill mut">${L('geen bronnen')}</span>`
            : h.paused ? `<span class="lg-pill bad">${L('daglimiet bereikt')}</span>`
            : h.errors ? `<span class="lg-pill warn">${h.errors === 1 ? L('1 bron met een fout') : L('{n} bronnen met een fout', { n: h.errors })}</span>`
            : `<span class="lg-pill ok">✓</span>`;
          return `<tr><td>${isAdmin() ? `<button type="button" class="linkbtn" data-ls-host="${h.id}"><b>${esc(h.name)}</b></button>` : `<b>${esc(h.name)}</b>`}</td>
            <td>${h.sources ? (h.sources_on === h.sources ? h.sources : L('{a} van {b}', { a: h.sources_on, b: h.sources })) : '–'}</td>
            <td class="num">${h.lines_today ? dec(h.lines_today) : '–'}${h.dropped_today ? `<div class="warn-txt small">${L('{n} niet opgeslagen', { n: dec(h.dropped_today) })}</div>` : ''}</td>
            <td>${h.sources ? `<span class="lg-bar${p >= 100 || h.paused ? ' full' : ''}"><i style="width:${h.paused ? 100 : p}%"></i></span> <span class="muted small">${fmtBytes(h.bytes_today)}</span>` : '–'}</td>
            <td class="nowrap">${h.sources ? `${h.interval} min${h.own_interval ? ' <span class="muted small">(' + L('eigen') + ')</span>' : ''}` : '–'}</td>
            <td class="nowrap">${h.last_at ? esc(ago(h.last_at)) : '–'}</td><td>${st}</td></tr>`;
        }).join('')}</tbody></table></div>` : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`}
      ${problems.length ? `<p class="small muted">${L('Klik op een server voor de bronnen (Agent Manager › Logs). Een daglimiet gaat om middernacht vanzelf weer open; daarna gaat de agent verder waar hij was.')}</p>` : ''}
    </div></div>`;
  box.onclick = (e) => {
    const b = e.target.closest('[data-ls-host]');
    if (!b) return;
    const h = findHost(+b.dataset.lsHost);
    if (h) agentInstructions(h);
  };
}

// ---------------------------------------------------------------- 1.178.0: Agent Manager (kaarten met schakelaars)
/** Schakelaar (aan/uit) */
const amSw = (id, on, attrs = '') => `<label class="am-sw"><input type="checkbox" id="${id}" ${on ? 'checked' : ''} ${attrs}><span aria-hidden="true"></span></label>`;
/** Rij in een kaart: titel en uitleg links, rechts een schakelaar, keuze of knop */
const amRow = (title, sub, right, cls = '') => `<div class="am-row${cls}"><div class="am-rt"><b>${title}</b>${sub ? `<small>${sub}</small>` : ''}</div><div class="am-rr">${right}</div></div>`;
/** Instelling met de algemene waarde als standaard: schakelaar = eigen keuze, daaronder de keuze */
function amOwn(key, title, sub, cur, opts, globalText) {
  const own = cur !== '';
  return `${amRow(title, `${sub ? sub + ' · ' : ''}<span data-am-own-txt="${key}">${own ? L('eigen keuze') : esc(globalText)}</span>`, amSw(`am-own-${key}`, own, `data-am-own="${key}" data-global="${esc(globalText)}"`))}
    <div class="am-sub" data-am-own-sub="${key}" ${own ? '' : 'hidden'}>${swSeg('own-' + key, opts, own ? cur : opts[0][0])}</div>`;
}
/** Bovenaan: in één oogopslag hoe deze agent ervoor staat */
function amChips(h) {
  const chip = (ok, txt) => `<span class="am-chip ${ok === true ? 'ok' : ok === false ? 'w' : ''}">${txt}</span>`;
  const out = [];
  if (h.mode === 'agent') {
    out.push(chip(+h.agent_key_seen === 1, +h.agent_key_seen === 1 ? '🔑 ' + L('eigen sleutel') : '🔑 ' + L('token')));
    out.push(chip(+h.enc_seen === 1, +h.enc_seen === 1 ? '🔐 ' + L('versleuteld') : '🔓 ' + L('onversleuteld')));
    if (h.agent_conn) out.push(chip(h.agent_conn !== 'http' && !h.agent_fb_at, h.agent_fb_at ? '⚠ ' + L('reserve-adres') : h.agent_conn === 'http' ? '🌐 http' : '🔒 https'));
    out.push(chip(+(h.edr_on ?? 1) ? true : null, '🛡️ EDR ' + (+(h.edr_on ?? 1) ? L('aan') : L('uit'))));
    if (+h.watchdog) out.push(chip(true, '🐕 ' + L('waakhond')));
  }
  out.push(chip(h.snmp ? true : null, '📡 SNMP: ' + (!h.snmp ? L('uit') : h.snmp.use === 'global' ? L('algemeen') + (h.snmp.v ? ` (v${esc(h.snmp.v)})` : '') : L('eigen') + ` (v${esc(h.snmp.v)})`)));
  return out.join('');
}
/** Kaart SNMP: uit, de algemene instelling, of eigen instellingen (met de velden) */
function amSnmp(h) {
  const s = h.snmp, own = s ? s.use !== 'global' : !state.snmp_global;
  return `${amRow(L('SNMP gebruiken'), `<span id="am-snmp-txt">${!s ? L('uit') : own ? L('eigen instellingen (v{v})', { v: esc(s.v) }) : L('algemene instelling (v{v})', { v: esc(s.v || '?') })}</span>`, amSw('am-snmp-on', !!s))}
    <div id="am-snmp-body" ${s ? '' : 'hidden'}>
      ${amRow(L('Eigen instellingen'), esc(snmpGlobalText()), amSw('am-snmp-own', own))}
      <div class="am-sub" id="am-snmp-fields" ${own ? '' : 'hidden'}>${snmpFieldsHtml(s || state.snmp_global)}
        <div class="snmpg-btns"><button type="button" class="btn primary small-btn" id="am-snmp-save">${L('Opslaan')}</button></div></div>
      ${amRow('🔍 ' + L('Test SNMP'), `<span id="am-snmp-out">${L('vraagt het apparaat nu om zijn systeeminformatie')}</span>`, `<button type="button" class="btn small-btn" id="am-snmp-test">${L('Test')}</button>`)}
    </div>`;
}
/** Alle schakelaars en keuzes van de Agent Manager */
function amInit(h, body) {
  const done = () => { refresh(); loadSecurity(); };
  const err = (ex) => toast(ex.message, 'err');
  // Rapporteren
  $('[data-sw-seg=tick]', body).addEventListener('sw-seg', async (e) => {
    try {
      const r = await api('host_tick', { id: h.id, tick: +e.detail });
      toast(L('{name} rapporteert elke {tick}; de agent pakt het binnen een minuut op', { name: h.name, tick: tickText(r.tick) }));
      refresh();
    } catch (ex) { err(ex); }
  });
  $('#host-realtime').onchange = async (e) => {
    try {
      await api('host_realtime', { id: h.id, on: e.target.checked });
      toast(e.target.checked ? L('Realtime aan voor {name}; de agent pakt het binnen een minuut op', { name: h.name }) : L('Realtime uit voor {name}; de agent pakt het binnen een minuut op', { name: h.name }));
      refresh();
    } catch (ex) { e.target.checked = !e.target.checked; err(ex); }
  };
  // Beveiliging
  $('#host-edr').onchange = async (e) => {
    try {
      await api('edr_host_set', { host_id: h.id, on: e.target.checked });
      toast(e.target.checked ? L('Endpoint-bewaking aan voor {name}; de agent pakt het binnen een minuut op', { name: h.name }) : L('Endpoint-bewaking uit voor {name}', { name: h.name }));
      loadEdrHost(h);
      refresh();
    } catch (ex) { e.target.checked = !e.target.checked; err(ex); }
  };
  loadEdrHost(h);
  api('agent_commands').then((r) => {
    const me = r.hosts.find((x) => x.id === +h.id);
    if (!me) return;
    const sw = $('#host-cmd-own'), sub = $('#host-cmd-sub'), seg = $('[data-sw-seg=cmd]', body);
    const glob = L('algemene instelling: {m}', { m: CMD_MODES[r.global] || r.global });
    sw.checked = !!me.own;
    sub.hidden = !me.own;
    $$('button', seg).forEach((b) => b.setAttribute('aria-checked', String(b.dataset.v === (me.own || me.mode))));
    $('#host-commands-info').textContent = me.own ? L('eigen keuze: {m}', { m: CMD_MODES[me.own] }) : glob;
    const again = () => agentInstructions(findHost(h.id));
    sw.onchange = () => {
      sub.hidden = !sw.checked;
      if (!sw.checked) setAgentCommands(h.id, 'global', me.mode, h.name, again);
    };
    seg.addEventListener('sw-seg', (e) => setAgentCommands(h.id, e.detail, me.mode, h.name, again));
  }).catch(() => {});
  // Meldingen
  const wdSave = async () => {
    try {
      await api('host_watchdog', { id: h.id, on: $('#host-watchdog').checked, min: +$('#host-wd-min').value || 5 });
      toast($('#host-watchdog').checked ? L('Waakhond aan voor {name}; de agent pakt het binnen een paar minuten op', { name: h.name }) : L('Waakhond uit voor {name}', { name: h.name }));
      refresh();
    } catch (ex) { err(ex); }
  };
  $('#host-watchdog').onchange = () => { $('#host-wd-sub').hidden = !$('#host-watchdog').checked; wdSave(); };
  $('#host-wd-min').onchange = () => { if ($('#host-watchdog').checked) wdSave(); };
  $('#host-info-agent').onchange = async (e) => {
    try { await api('host_info_agent', { id: h.id, on: e.target.checked }); toast(L('Opgeslagen')); refresh(); }
    catch (ex) { e.target.checked = !e.target.checked; err(ex); }
  };
  // Verbinding: eigen keuze of de algemene instelling
  const saveOwn = (key, val) => {
    if (key === 'enc') return api('host_enc', { host_id: h.id, enc: val }).then(() => { toast(L('Opgeslagen; de agent pakt het op bij zijn volgende rapport')); done(); }).catch(err);
    const el = $(`[data-am-own-sub="${key}"]`, body);
    el.dataset.host = h.id;
    el.dataset.hostConn = key;
    el.value = val;
    return saveHostConn(el, () => agentInstructions(findHost(h.id)));
  };
  $$('[data-am-own]', body).forEach((sw) => {
    const key = sw.dataset.amOwn, sub = $(`[data-am-own-sub="${key}"]`, body);
    sw.onchange = () => {
      sub.hidden = !sw.checked;
      $(`[data-am-own-txt="${key}"]`, body).textContent = sw.checked ? L('eigen keuze') : sw.dataset.global;
      const cur = $('[aria-checked=true]', sub);
      saveOwn(key, sw.checked ? (cur ? cur.dataset.v : 'on') : '');
    };
    $('.sw-seg', sub).addEventListener('sw-seg', (e) => saveOwn(key, e.detail));
  });
  // SNMP
  const sn = $('#am-snmp', body);
  const snmpWire = () => {
    snmpFieldsInit($('#am-snmp-fields', sn));
    const send = async (snmp) => {
      try {
        const r = await api('host_snmp', { id: h.id, snmp });
        toast(L('Opgeslagen'));
        await refresh();
        const hh = findHost(h.id);
        hh.snmp = r.snmp;
        sn.innerHTML = amSnmp(hh);
        $('.am-chips', body).innerHTML = amChips(hh);
        snmpWire();
      } catch (ex) { err(ex); sn.innerHTML = amSnmp(findHost(h.id)); snmpWire(); }
    };
    const data = () => ({ on: true, ...($('#am-snmp-own', sn).checked ? {} : { use: 'global' }), ...snmpRootData($('#am-snmp-fields', sn)) });
    $('#am-snmp-on', sn).onchange = (e) => {
      $('#am-snmp-body', sn).hidden = !e.target.checked;
      if (!e.target.checked) return send({ on: false });
      if (!$('#am-snmp-own', sn).checked) send(data());   // de algemene instelling: meteen opslaan; eigen: eerst invullen
    };
    $('#am-snmp-own', sn).onchange = (e) => {
      $('#am-snmp-fields', sn).hidden = !e.target.checked;
      if (!e.target.checked && $('#am-snmp-on', sn).checked) send(data());
    };
    $('#am-snmp-save', sn).onclick = () => send(data());
    $('#am-snmp-test', sn).onclick = async () => {
      const out = $('#am-snmp-out', sn);
      out.className = 'muted';
      out.textContent = L('Bezig…');
      try {
        const r = await api('snmp_test', { id: h.id, address: h.address, snmp: data() });
        out.className = 'good';
        out.innerHTML = snmpSysText(r.sys);
      } catch (ex) { out.className = 'bad'; out.textContent = '✘ ' + ex.message; }
    };
  };
  snmpWire();
}

/** 1.178.0: 🧪 Test: wat RzSense meteen weet en test, en (agent 1.79.0) wat de agent bij zijn volgende ronde over zichzelf meldt */
async function agentTestDialog(h) {
  let r;
  try { r = await api('agent_selftest', { id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const testId = r.test, started = Date.now();
  const icon = { ok: '✔', warn: '⚠', bad: '✘', skip: '–' };
  const list = (items) => `<ul class="at-list">${items.map((x) => `<li class="at-${x.ok}"><span class="at-ic" aria-hidden="true">${icon[x.ok] || '–'}</span><span class="at-t"><b>${esc(x.t)}</b><small>${esc(x.d)}</small></span></li>`).join('')}</ul>`;
  const score = (items) => {
    const n = { ok: 0, warn: 0, bad: 0 };
    items.forEach((x) => { if (n[x.ok] !== undefined) n[x.ok]++; });
    return n;
  };
  const verdict = (all) => {
    const n = score(all);
    return n.bad ? ['bad', L('{n} probleem/problemen gevonden', { n: n.bad })] : n.warn ? ['warn', L('Werkt, met {n} aandachtspunt(en)', { n: n.warn })] : ['ok', L('Alles in orde')];
  };
  const render = (x) => {
    const agentPart = h.mode !== 'agent' ? '' : x.agent ? list(x.agent)
      : x.can === false ? `<p class="muted small">${L('Deze agent is te oud om zichzelf te testen (agent {v} of nieuwer nodig). Werk hem bij met ⬆ Bijwerken.', { v: '1.79.0' })}</p>`
      : x.pending ? `<div class="at-wait"><span class="at-spin" aria-hidden="true"></span><span>${L('Wacht op de agent: hij test zichzelf bij zijn volgende ronde (elke {n} s)…', { n: x.tick })}<small class="muted" id="at-left"></small></span></div>`
      : `<p class="warn-txt small">${L('De agent antwoordde niet binnen 5 minuten. Draait hij nog? Kijk bij het laatste rapport hierboven.')}</p>`;
    const all = [...x.server, ...(x.agent || [])];
    const [cls, txt] = x.pending && h.mode === 'agent' ? ['run', L('Bezig met testen…')] : verdict(all);
    return `<div class="at-hero at-${cls}"><span class="at-big" aria-hidden="true">${cls === 'run' ? '🧪' : icon[cls]}</span><div><b>${txt}</b><small>${esc(h.name)} · ${esc(h.address)}</small></div></div>
      <div class="at-cols"><div><h3 class="at-h">🖥️ ${L('Vanuit RzSense')}</h3>${list(x.server)}</div>
      ${h.mode === 'agent' ? `<div><h3 class="at-h">🤖 ${L('Op de server (door de agent zelf)')}</h3>${agentPart}</div>` : ''}</div>`;
  };
  modal({
    title: `🧪 ${L('Test')} – ${h.name}`, wide: true,
    body: `<div class="at-body" id="at-body">${render(r)}</div>`,
    buttons: [{ label: L('Opnieuw testen'), onClick: () => agentTestDialog(findHost(h.id)) }, { label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  const gen = modalGen;   // 1.224.4: na "Opnieuw testen" alleen de nieuwe test
  const poll = async () => {
    const box = $('#at-body');
    if (gen !== modalGen || !box || !$('#modal').open) return;
    let x;
    try { x = await api('agent_selftest_get', null, { id: h.id, test: testId }); } catch (ex) { return; }
    if (gen !== modalGen || !$('#at-body')) return;
    box.innerHTML = render(x);
    const left = $('#at-left');
    if (left) left.textContent = ' · ' + L('{s} s bezig', { s: Math.round((Date.now() - started) / 1000) });
    if (x.pending) setTimeout(poll, 3000);
  };
  $('#modal-body').scrollTop = 0;
  if (r.pending) setTimeout(poll, 3000);
}

/** "30 s", "2 min" */
function tickText(t) { return +t >= 120 ? `${+t / 60} min` : `${+t} s`; }

/**
 * Installatiecommando met een koppelcode: eerst het installatiescript downloaden en controleren (SHA-256), dan
 * installeren; het script controleert de agent ook. Bij een eigen certificaat alleen dat certificaat (--pinnedpubkey).
 */
/** Downloadsleutel (1.86.0): van buiten levert RzSense het installatiescript alleen hiermee */
const dlArg = () => (state.dl_key ? `&k=${state.dl_key}` : '');
function pairCommand(base, r, extra = '') {
  const pin = r.pin && base.startsWith('https') ? r.pin : '';
  return `curl -fsSL${pin ? ` -k --pinnedpubkey 'sha256//${pin}'` : ''} '${base}/api.php?action=agent_install${dlArg()}' -o rzmon-install.sh`
    + ` && echo '${r.setup_sha256}  rzmon-install.sh' | sha256sum -c -`
    + ` && sudo bash rzmon-install.sh --url '${base}' --pair '${r.code}' --agent-sha '${r.agent_sha256}'${pin ? ` --pin '${pin}'` : ''}${extra}`;
}
const pairButton = () => `<div class="pair-box"><button type="button" class="btn primary" data-make-pair>🔗 ${L('Koppelcode maken')}</button>
  <span class="muted small">${L('Eenmalig en 15 minuten geldig. De agent haalt er zijn eigen sleutel mee op; er gaat geen token of sleutel leesbaar over de lijn.')}</span></div>`;

/**
 * Adres van RzSense in de installatiecommando's (1.130.0): de Basis-URL, maar mogen agents alleen via https (algemeen of
 * voor deze server) en is die http, dan hetzelfde adres met https. De agent komt dan meteen op https te staan.
 */
function agentBase(h) {
  const base = (state.base_url || location.origin + location.pathname.replace(/\/[^/]*$/, '')).replace(/\/+$/, '');
  const only = h?.agent_https === 'on' || (h?.agent_https !== 'off' && state.agent_https_only);
  return only && base.startsWith('http://') ? 'https://' + base.slice(7).replace(/^([^/]+):80(?=\/|$)/, '$1') : base;
}

function agentInstructions(h, pairNow = false) {
  const base = agentBase(h);
  const scriptUrl = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api.php?action=agent_script';
  // De oude manier (token, en eventueel de algemene sleutel), voor agents ouder dan 1.50.0
  const keyArg = state.enc_key && h.enc !== 'off' ? ` --key '${state.enc_key}'` : '';
  const installCmd = `curl -fsSL '${base}/api.php?action=agent_install${dlArg()}' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}'${keyArg}`;
  // 1.121.0: als pagina, alles in één keer zichtbaar: status bovenaan, daaronder Functies en Verbinding naast elkaar,
  // en Installeren ingeklapt (open als er nog nooit een rapport kwam, of bij opnieuw koppelen)
  modal({
    title: `Agent Manager – ${h.name}`,
    wide: true,
    page: true,
    body: `
      <section class="am-sec am-top am-hero">
      <div class="am-hero-bar"><div class="am-chips">${amChips(h)}</div>
        ${isAdmin() ? `<div class="am-hero-btns"><button type="button" class="btn" data-am-test>🧪 ${L('Test')}</button><button type="button" class="btn primary" data-am-rec>＋ ${L('Aanbevolen checks')}</button></div>` : ''}</div>
      <div class="agent-live" id="agent-live"><span class="muted">${L('Status ophalen…')}</span></div>
      <div class="cmd-host-row contact-row">📡 ${L('Contact:')} <div>${contactHtml(h, 'full')}</div></div>
      </section>
      <div class="pg-tools" data-pgtools="am"></div>
      <div class="am-grid am-grid3" data-pgrid="am">
      <section class="am-sec am-card need-admin" data-pk="run" data-pw="4" style="--c: var(--primary)"><h3 class="am-h"><i aria-hidden="true">⏱</i>${L('Rapporteren')}</h3>
        <p class="am-lead">${L('Hoe vaak en hoe snel de agent meldt')}</p>
        ${amRow(L('Elke'), `<span id="host-tick-info">${h.agent_update_via === 'direct' ? L('via cron/TrueNAS: minimaal elke minuut') : L('standaard {t}', { t: tickText(state.agent_tick) })}</span>`,
          swSeg('tick', [[0, L('std')], [30, '30 s'], [60, '1 m'], [120, '2 m'], [300, '5 m']], +h.agent_tick || 0))}
        ${amRow('⚡ Realtime', `<span id="host-realtime-info">${h.agent_update_via === 'direct' ? L('werkt alleen met systemd (niet via cron of op TrueNAS)') : L('logins, sudo, services en 🔴-bestanden meteen')}</span>`, amSw('host-realtime', +h.realtime))}
      </section>
      <section class="am-sec am-card need-admin" data-pk="sec" data-pw="4" style="--c: var(--crit)"><h3 class="am-h"><i aria-hidden="true">🛡️</i>${L('Beveiliging')}</h3>
        <p class="am-lead">${L('Wat de agent bewaakt en mag doen')}</p>
        ${amRow(L('Endpoint-bewaking (EDR)'), L('processen, netwerk, bestanden en containers meteen bekeken; RzSense grijpt in bij een aanval'), amSw('host-edr', +(h.edr_on ?? 1)))}
        <div id="host-edr-info" class="edr-host am-sub"></div>
        ${amRow(L('Commando-checks'), `<span id="host-commands-info">${L('laden…')}</span>`, amSw('host-cmd-own', false, 'title="' + esc(L('Eigen keuze voor deze server')) + '"'))}
        <div class="am-sub" id="host-cmd-sub" hidden>${swSeg('cmd', Object.entries(CMD_MODES), 'off')}</div>
      </section>
      <section class="am-sec am-card need-admin" data-pk="notify" data-pw="4" style="--c: var(--warn)"><h3 class="am-h"><i aria-hidden="true">🔔</i>${L('Meldingen')}</h3>
        <p class="am-lead">${L('Wanneer deze server zelf iets laat weten')}</p>
        ${amRow('🐕 ' + L('Waakhond'), `<span id="host-wd-info">${L('deze agent stuurt zelf een pushbericht (Pushover of ntfy) als RzSense onbereikbaar is of de checks stilstaan')}</span>`, amSw('host-watchdog', +h.watchdog))}
        <div class="am-sub" id="host-wd-sub" ${+h.watchdog ? '' : 'hidden'}>${L('melden na')} <input id="host-wd-min" type="number" min="2" max="120" value="${+h.watchdog_min || 5}" style="width:4.5rem"> ${L('min')}</div>
        ${amRow('ℹ️ ' + L('Info bij agent-updates'), L('volgt de algemene info-regel'), amSw('host-info-agent', +(h.info_agent ?? 1)))}
        ${edrHidden(h.id).length ? amRow('🙈 ' + L('Melding over ontbrekende onderdelen'), L('verborgen: {what}', { what: esc(edrHidden(h.id).join(', ')) }),
          `<button type="button" class="btn small-btn" id="host-edr-unhide">${L('Weer tonen')}</button>`) : ''}
      </section>
      <section class="am-sec am-card need-admin" data-pk="conn" data-pw="4" style="--c: var(--ok)"><h3 class="am-h"><i aria-hidden="true">🔗</i>${L('Verbinding')}</h3>
        <p class="am-lead">${L('Schakelaar uit = de algemene instelling · aan = een eigen keuze voor deze server')}</p>
        ${amOwn('enc', '🔐 ' + L('Versleuteld versturen'), `${encSeenText(h)} · ${+h.agent_key_seen === 1 ? '🔑 ' + L('eigen sleutel') : L('nog geen eigen sleutel: koppel opnieuw (Onderhoud)')}`,
          h.enc || '', [['on', L('aan')], ['required', L('verplicht')], ['off', L('uit')]], L('algemene instelling'))}
        ${amOwn('pin', '📌 ' + L('Certificaat van RzSense vastzetten'), '', h.pin_mode || '', [['on', L('aan')], ['off', L('uit')]], `${L('algemeen')}: ${state.conn_pin ? L('aan') : L('uit')}`)}
        ${amOwn('ip', '🌐 ' + L('Alleen vanaf het bekende IP-adres'), '', h.ip_lock || '', [['on', L('aan')], ['off', L('uit')]], `${L('algemeen')}: ${state.conn_ip_lock ? L('aan') : L('uit')}`)}
        ${amOwn('https', '🔒 ' + L('Alleen via https'), '', h.agent_https || '', [['on', L('aan')], ['off', L('uit')]], `${L('algemeen')}: ${state.conn_https ? L('aan') : L('uit')}`)}
        ${amRow('🌍 ' + L('Adres van RzSense'), connText(h), `<button type="button" class="btn small-btn" data-am-url>✏️ ${L('Wijzigen')}</button>`, ' am-url')}
        ${h.agent_ip_new ? `<div class="callout">⚠️ ${L('Geweigerd vanaf het nieuwe IP-adres <code>{ip}</code>. Toestaan kan bij Instellingen → Agents → Standaardinstellingen.', { ip: esc(h.agent_ip_new) })}</div>` : ''}
      </section>
      <section class="am-sec am-card need-admin" data-pk="snmp" data-pw="4" style="--c: var(--purple, #7a49d9)"><h3 class="am-h"><i aria-hidden="true">📡</i>SNMP</h3>
        <p class="am-lead">${L('RzSense leest het apparaat zelf uit (zonder agent)')}</p>
        <div id="am-snmp">${amSnmp(h)}</div>
      </section>
      ${h.mode === 'agent' ? `<section class="am-sec am-card need-admin" data-pk="logs" data-pw="12" style="--c: var(--info, #2563eb)"><h3 class="am-h"><i aria-hidden="true">📜</i>${L('Logs')}</h3>
        <p class="am-lead">${L('Welke logs deze server naar RzSense stuurt: gecomprimeerd en versleuteld, elke paar minuten')}</p>
        <div id="am-logs"><p class="muted">${L('Laden…')}</p></div>
      </section>` : ''}
      <section class="am-sec am-card need-admin" data-pk="maint" data-pw="4" style="--c: var(--muted)"><h3 class="am-h"><i aria-hidden="true">🧰</i>${L('Onderhoud')}</h3>
        <p class="am-lead">${L('Sleutels en tokens')}</p>
        ${amRow('🔗 ' + L('Opnieuw koppelen'), L('nieuwe koppelcode; de agent haalt er een nieuwe eigen sleutel mee op'), `<button type="button" class="btn small-btn" data-am-pair>${L('Koppelcode')}</button>`)}
        ${amRow('↻ ' + L('Nieuw token'), L('alleen voor agents ouder dan 1.50.0; de oude agent werkt dan niet meer'), `<button type="button" class="btn small-btn" data-am-token>${L('Nieuw token')}</button>`)}
      </section>
      </div>
      <details class="am-sec am-install" ${pairNow || !h.agent_seen ? 'open' : ''}><summary><h3 class="am-h">${L('Installeren of opnieuw koppelen')}</h3></summary>
      <div class="seg" role="tablist">
        <button type="button" class="seg-btn active" data-seg="linux">🐧 ${L('Linux-server')}</button>
        <button type="button" class="seg-btn" data-seg="ha">🏠 Home Assistant OS</button>
        <button type="button" class="seg-btn" data-seg="truenas">🗄️ TrueNAS SCALE</button>
        <button type="button" class="seg-btn" data-seg="proxmox">🖥️ Proxmox VE</button>
      </div>
      <div data-pane="proxmox" hidden>
        <p>${L('Proxmox VE is Debian: de gewone agent draait er, als <b>root</b> op de host (niet in een VM of container). Vanaf agent 1.61.0 leest hij ook Proxmox zelf uit met <code>pvesh</code>: de guests (VM\'s en containers), de opslag, ZFS-pools, de cluster, de nieuwste back-up per guest en de back-uptaken. Een update van Proxmox laat de agent staan.')}</p>
        <ol class="steps">
          <li>${L('Open de shell van de node (<i>Datacenter → node → Shell</i>, of SSH als root).')}</li>
          <li>${L('Maak een koppelcode en voer het commando uit:')}
            <div data-pair-pane="proxmox">${pairButton()}</div></li>
          <li>${L('Daarna: <b>＋ Aanbevolen checks</b> (overzicht, back-ups, opslag, ZFS en guests naar keuze), en <b>🖥️ Proxmox</b> bij deze server voor het overzicht.')}</li>
        </ol>
        ${xpHtml(L('Losse Proxmox-nodes samen zien, uitzetten of controleren.'), L('<b>Meerdere nodes:</b> zet ze in het overzicht (🖥️ Proxmox) in dezelfde <i>cluster</i>; dan zie je ze samen. <b>Uitzetten op een node:</b> <code>PVE=0</code> in <code>/etc/default/rzmon-agent</code>. <b>Controleren:</b> <code>sudo rzmon-agent-setup --status</code> toont de regel <i>Proxmox VE</i>.'))}
      </div>
      <div data-pane="truenas" hidden>
        <p>${L('Op TrueNAS SCALE horen <code>/usr</code> en <code>/etc</code> bij TrueNAS zelf. De agent komt daarom op je <b>pool</b> (<code>/mnt/&lt;pool&gt;/rzmon-agent</code>), en draait via een <b>cronjob van TrueNAS</b> die ook na een TrueNAS-update blijft staan.')}</p>
        <ol class="steps">
          <li>${L('Open in TrueNAS <i>System → Shell</i> (of log in met SSH als beheerder).')}</li>
          <li>${L('Maak een koppelcode en voer het commando uit:')}
            <div data-pair-pane="truenas">${pairButton()}</div></li>
        </ol>
        ${xpHtml(L('Wat het installatiescript op TrueNAS doet.'), L('<b>Pool:</b> de eerste pool wordt gekozen (niet <code>boot-pool</code>); een andere kies je met <code>--pool naam</code>. <b>Wat het doet:</b> het script zet de agent en de instellingen op de pool, maakt de cronjob <i>RzSense agent</i> aan (elke minuut, uitvoer verborgen) en stuurt een testrapport. <b>Al met de hand geïnstalleerd?</b> Dan worden je instellingen en cronjob overgenomen.'))}
        ${xpHtml(L('Bijwerken, testen en verwijderen op TrueNAS, en een tip voor je pools.'), L('<b>Bijwerken:</b> met de knop hierboven. <b>Testen:</b> <code>bash /mnt/&lt;pool&gt;/rzmon-agent/rzmon-agent-setup --test</code>. <b>Verwijderen:</b> <code>… --uninstall</code>. <b>Tip:</b> een <b>Commando</b>-check met <code>zpool status -x</code> en het verwachte antwoord <code>all pools are healthy</code> bewaakt je pools.'))}
      </div>
      <div data-pane="ha" hidden>
        <p>${L('Op Home Assistant OS / Supervised draait de agent als <b>add-on</b>. Hij meldt CPU, RAM, de data-partitie, Core, Supervisor, plugins en add-ons (ook als containers met CPU/RAM), updates, back-ups, onverwachte herstarts, de SD-kaart, USB-sticks en logs. Daarna: <b>＋ Aanbevolen checks</b>.')}</p>
        <ol class="steps">
          <li>${L('In Home Assistant: <i>Instellingen → Add-ons → Add-on winkel</i> → ⋮ → <b>Repositories</b> → voeg <code>https://github.com/rembrand20/rzmon</code> toe.')}
            <span class="muted small">${L('(Of kopieer de map <code>ha-addon/rzmon_agent</code> naar de share <code>addons</code> met de <i>Samba share</i>-add-on.)')}</span></li>
          <li>${L('⋮ → <b>Controleren op updates</b> en installeer <b>RzSense Agent</b>.')}</li>
          <li>${L('Tabblad <b>Configuratie</b>: vul de koppelcode in (eenmalig, 15 minuten geldig):')}</li>
        </ol>
        <div data-pair-pane="ha">${pairButton()}</div>
        <ol class="steps" start="4">
          <li>${L('<b>Starten</b>. In het logboek moet <code>Verbonden met RzSense ✔</code> staan.')}</li>
          <li>${L('Voeg hier checks toe met <b>＋</b>: <i>Service</i> <code>homeassistant</code>, <code>supervisor</code>, <code>updates</code> of <code>addon:&lt;slug&gt;</code>, en <i>Diskruimte</i> <code>/data</code>.')}</li>
        </ol>
        <p class="muted small">${L('Tip: zet er ook een ping-check en een TCP-check op poort 8123 bij. Die werken los van de agent.')}</p>
      </div>
      <div data-pane="linux">
      <p>${L('Voer dit één commando uit op <b>{name}</b> (Rocky/RHEL/Alma, Debian/Ubuntu, Raspberry Pi OS, Proxmox). Het installeert de agent, stelt de services voor die er draaien, zet een systemd-timer aan en stuurt een testrapport.', { name: esc(h.name) })}</p>
      <div data-pair-pane="linux">${pairButton()}</div>
      ${xpHtml(L('Daarna: checks toevoegen, bijwerken of verwijderen.'), L('<b>Checks:</b> klik hier op <b>＋</b> en voeg checks toe (CPU, RAM, diskruimte, services). <b>Bijwerken:</b> met de knop hierboven, of <code>sudo rzmon-agent-setup --update</code>. <b>Verwijderen:</b> <code>sudo rzmon-agent-setup --uninstall</code>.'))}
      ${xpHtml(L('Komt er niets binnen? Er is een test die zegt wat er mis is.'), L('Voer op de server <code>sudo rzmon-agent-setup --test</code> uit. Die controleert stap voor stap de planning, het netwerk, het adres, het token en een proefrapport, en zegt wat er mis is.'))}
      <p class="muted small">⚠️ ${L('De server moet RzSense kunnen bereiken op <code>{url}</code>. Klopt dat adres niet, pas dan de <i>Basis-URL</i> aan in Setup.', { url: esc(base) })}</p>
      <details class="small api-docs">
        <summary>${L('Oude manier: met het token (agent ouder dan 1.50.0)')}</summary>
      ${xpHtml(L('Zonder koppelcode werkt het ook, met het token.'), L('Zonder koppelcode stuurt de agent zijn token mee. Een agent van 1.50.0 of nieuwer krijgt bij zijn eerste versleutelde rapport alsnog een eigen sleutel.'))}
      <div class="copy-line"><pre class="code" id="agent-oneliner">${esc(installCmd)}</pre>
        <button type="button" class="btn" id="copy-oneliner">${L('Kopieer')}</button></div>
      </details>
      <details class="small api-docs">
        <summary>${L('Liever handmatig installeren?')}</summary>
      <p class="muted small">${L('1. Download <a href="{url}" target="_blank">rzmon-agent.sh</a> en kopieer het naar de server.', { url: esc(scriptUrl) })}</p>
      <p class="muted small">${L('2. Installeer en configureer:')}</p>
      <pre class="code">sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
sudo tee /etc/default/rzmon-agent &gt;/dev/null &lt;&lt;'EOF'
MONITOR_URL="${esc(base)}"
TOKEN="${esc(h.agent_token || '')}"
CERTS="/etc/letsencrypt/live/*/cert.pem localhost:8443"
EOF
sudo chmod 600 /etc/default/rzmon-agent
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh &gt;/dev/null 2&gt;&amp;1' | sudo tee /etc/cron.d/rzmon-agent
# Testen:
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'</pre>
      ${xpHtml(L('Certificaten en services die de agent zelf bewaakt.'), L('<b>CERTS:</b> certificaatbestanden en/of <code>host:poort</code> (ook op internet) waarvan de agent de vervaldatum meldt. <b>Services:</b> die kies je na het eerste rapport hier bij <i>Check toevoegen</i> uit een lijst van de server; de agent bewaakt ze dan vanzelf.'))}
      </details>
      </div>
      </details>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  const body = $('#modal-body');
  if (pgEdit === 'am') pgEdit = null;
  pgApply('am'); pgTools('am');   // 1.160.0: Functies en Verbinding als kaarten (lay-out CC)
  const show = (seg) => {
    $$('.seg-btn', body).forEach((b) => b.classList.toggle('active', b.dataset.seg === seg));
    $$('[data-pane]', body).forEach((p) => (p.hidden = p.dataset.pane !== seg));
  };
  $$('.seg-btn', body).forEach((b) => (b.onclick = () => show(b.dataset.seg)));
  pollAgentStatus(h.id);
  const unhide = $('#host-edr-unhide');
  if (unhide) unhide.onclick = async () => { try { await edrHideSave(h.id, []); unhide.closest('.am-row').remove(); toast(L('Wordt weer getoond')); } catch (ex) { toast(ex.message, 'err'); } };
  if (isAdmin()) amInit(h, body);
  if (isAdmin() && h.mode === 'agent') amLogs(h);   // 1.210.0
  // Koppelcode: één code voor alle drie de manieren; daarna wachten tot de agent gekoppeld is
  const makePair = async () => {
    let r;
    try { r = await api('agent_pair_code', { id: h.id }); } catch (ex) { toast(ex.message, 'err'); return; }
    const copyLine = (txt) => `<div class="copy-line"><pre class="code">${esc(txt)}</pre><button type="button" class="btn" data-copy-text="${esc(txt)}">${L('Kopieer')}</button></div>`;
    const head = `<div class="pair-code"><span class="muted small">${L('Koppelcode')}</span> <b class="mono">${esc(r.code)}</b> <span class="pair-status muted small"></span></div>`;
    const ha = `monitor_url: ${base}\npair_code: ${r.code}${r.pin && base.startsWith('https') ? `\npin: ${r.pin}` : ''}`;
    $$('[data-pair-pane]', body).forEach((p) => {
      const k = p.dataset.pairPane;
      p.innerHTML = head + (k === 'ha' ? copyLine(ha) : copyLine(pairCommand(base, r, k === 'truenas' ? ' --truenas' : '')))
        + `<p class="muted small">${k === 'ha' ? L('Opslaan en de add-on (her)starten. In het logboek moet <code>Gekoppeld met RzSense</code> staan.')
          : L('Het commando controleert eerst het installatiescript en de agent (SHA-256)')}${r.pin && base.startsWith('https') ? ' · 📌 ' + L('alleen het certificaat van deze RzSense') : ''}${base.startsWith('https') && !String(state.base_url || '').startsWith('https') ? ' · 🔒 ' + L('agents mogen alleen via https: daarom {url}', { url: `<code>${esc(base)}</code>` }) : ''}</p>`;
    });
    const until = Date.now() + r.minutes * 60000;
    const gen = modalGen, tok = ++pairTok;   // 1.224.4: alleen dit venster en de laatste koppelcode
    const tick = async () => {
      if (gen !== modalGen || tok !== pairTok || !$('#modal').open || !$('.pair-status', body)) return;
      const left = Math.max(0, Math.round((until - Date.now()) / 1000));
      let done = false;
      try { const me = (await api('security')).hosts.find((x) => +x.id === +h.id); done = me && !+me.pairing && +me.agent_key_seen === 1; } catch (ex) { /* volgende keer */ }
      $$('.pair-status', body).forEach((el) => {
        el.className = 'pair-status small ' + (done ? 'good-txt' : left ? 'muted' : 'warn-txt');
        el.textContent = done ? '✔ ' + L('Gekoppeld: de agent heeft zijn eigen sleutel') : left ? '⏳ ' + L('wacht op de agent · nog {t}', { t: `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}` }) : L('verlopen: maak een nieuwe koppelcode');
      });
      if (done) { refresh(); loadSecurity(); } else if (left) setTimeout(tick, 3000);
    };
    tick();
  };
  body.addEventListener('click', async (e) => {   // 1.224.4: alleen voor dit venster (signal)
    if (e.target.closest('[data-make-pair]')) return makePair();
    if (e.target.closest('[data-am-pair]')) { const det = $('.am-install', body); det.open = true; det.scrollIntoView({ behavior: 'smooth', block: 'start' }); return makePair(); }
    if (e.target.closest('[data-am-test]')) return agentTestDialog(findHost(h.id));
    if (e.target.closest('[data-am-rec]')) return recommendedChecks(findHost(h.id));
    if (e.target.closest('[data-am-token]')) {
      if (!confirm(L('Nieuw token genereren? De oude agent werkt dan niet meer.'))) return;
      await api('host_token', { id: h.id }); await refresh(); agentInstructions(findHost(h.id)); return;
    }
    const c = e.target.closest('[data-copy-text]');   // 1.224.4: de tekst zelf (data-copy is elders een element-id)
    if (!c) return;
    try { await navigator.clipboard.writeText(c.dataset.copyText); toast(L('Gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren(c.previousElementSibling); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  }, { signal: modalSignal() });   // 1.224.4: weg zodra er een ander venster komt
  const amUrl = $('[data-am-url]', body);
  if (amUrl) amUrl.onclick = () => agentUrlDialog(findHost(h.id));
  if (pairNow && isAdmin()) makePair();
  $('#agent-live').onclick = async (e) => {
    if (e.target.closest('[data-agent-https]')) return agentUrlDialog(findHost(h.id));
    const cl = e.target.closest('[data-agent-clean]');
    if (cl) {
      if (!confirm(L('Opruimen: RzSense wist het oude token van deze server, en de agent installeert zichzelf opnieuw (eerst een reservekopie van de huidige agent en instellingen, daarna zonder overbodige regels). Doorgaan?'))) return;
      cl.disabled = true;
      try {
        const r = await api('agents_cleanup', { id: h.id });
        toast(r.reinstall ? L('Opruimen gevraagd; de agent pakt het op bij zijn volgende rapport') : L('Token gewist. Deze agent kan zichzelf niet opnieuw installeren: op de server rzmon-agent-setup --update'));
      } catch (ex) { toast(ex.message, 'err'); }
      return pollAgentStatus(h.id);
    }
    const b = e.target.closest('[data-agent-upd]');
    if (!b) return;
    b.disabled = true;
    try {
      await api(b.dataset.agentUpd === 'go' ? 'agent_update' : 'agent_update_cancel', { id: h.id });
      toast(b.dataset.agentUpd === 'go' ? L('Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport') : L('Geannuleerd'));
    } catch (ex) { toast(ex.message, 'err'); }
    pollAgentStatus(h.id);
  };
  $('#copy-oneliner').onclick = async () => {
    try { await navigator.clipboard.writeText(installCmd); toast(L('Commando gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren($('#agent-oneliner')); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  };
  // Raad Home Assistant bij een naam of agent-gegevens die erop wijzen
  if (/home.?assistant|hass|haos/i.test(h.name) || h.metrics.includes('service:homeassistant')) show('ha');
  else if (/truenas|freenas/i.test(h.name) || h.metrics.some((m) => m.startsWith('service:middlewared'))) show('truenas');
  else if (isPveHost(h) || /proxmox|\bpve/i.test(h.name) || h.metrics.includes('service:pvedaemon')) show('proxmox');
}

/** Aanbevolen commando-checks voor deze server (TrueNAS, Proxmox of Linux), met vinkjes; bestaande slaan we over. */
/** Aanbevolen checks voor een Proxmox-host (1.105.0): overzicht, back-ups, opslag, ZFS en guests naar keuze */
async function pveRecommended(h) {
  let o;
  try { o = await api('pve_overview', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const me = o.nodes.find((n) => +n.host_id === +h.id);
  if (!me) return toast(L('Nog geen Proxmox-gegevens van deze server'), 'err');
  const has = (metric, target = '') => h.checks.some((c) => c.type === 'pve' && c.metric === metric && (c.target || '') === target);
  const items = [
    { key: 'overview', label: PVE_METRICS.overview.label, on: true, data: { metric: 'overview' } },
    { key: 'backups', label: L('Back-ups van alle guests (2 / 7 dagen)'), on: true, data: { metric: 'backups', warn_threshold: 2, crit_threshold: 7 } },
    { key: 'storage', label: L('Opslag (alle, 85 / 95%)'), on: true, data: { metric: 'storage', warn_threshold: 85, crit_threshold: 95 } },
    ...(me.node.zfs.length ? [{ key: 'zfs', label: L('ZFS-pools ({list})', { list: me.node.zfs.map((z) => z.name).join(', ') }), on: true, data: { metric: 'zfs', warn_threshold: 80, crit_threshold: 90 } }] : []),
    ...me.guests.filter((g) => !+g.template).map((g) => ({ key: 'g' + g.vmid, guest: g, on: false,
      label: `${g.kind === 'ct' ? '📦' : '🖥️'} ${g.kind === 'ct' ? 'CT' : 'VM'} ${g.vmid} ${g.name}`, data: { metric: 'guest', target: String(g.vmid), warn_threshold: 90, crit_threshold: 95 } })),
  ].map((x) => ({ ...x, exists: has(x.data.metric, x.data.target || '') }));
  const row = (x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${x.key}" ${x.exists ? 'disabled' : x.on ? 'checked' : ''}>
    <span><b>${esc(x.label)}</b>${x.guest ? ` <span class="muted small">${esc(x.guest.status)}${+x.guest.onboot ? ' · ' + L('start automatisch') : ''}</span>` : ''}${x.exists ? ' · ' + L('al aanwezig') : ''}</span></label>`;
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    wide: true,
    body: `<p style="margin-top:0">${L('Herkend als <b>Proxmox VE</b> ({v}). Vink aan wat je wilt bewaken.', { v: esc((me.node.version || '').replace(/^pve-manager\/([\d.]+).*$/, '$1')) })}</p>
      <div class="rec-list">${items.filter((x) => !x.guest).map(row).join('')}</div>
      <h4 class="acc-sub">${L('Guests: een eigen check per guest (aan of uit, RAM, guest agent)')}</h4>
      <p class="muted small" style="margin-top:0">${L('Het overzicht meldt al een guest die automatisch moet starten en uit staat. Kies hier de guests die je los wilt volgen.')}</p>
      <div class="rec-list">${items.filter((x) => x.guest).map(row).join('') || `<p class="muted small">${L('Geen guests gevonden.')}</p>`}</div>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const x of chosen) await api('check_save', { host_id: h.id, type: 'pve', enabled: true, interval_sec: x.data.metric === 'backups' ? 900 : 120, verify_ssl: true, ...x.data });
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

const pveBytes = (b) => (+b ? (+b / 1073741824 >= 1024 ? dec((+b / 1099511627776).toFixed(1)) + ' TB' : dec((+b / 1073741824).toFixed(+b >= 10737418240 ? 0 : 1)) + ' GB') : '–');
const pveAgo = (ts) => (ts ? ago(new Date(ts * 1000).toISOString()) : '–');

/** Overzicht van een Proxmox-node, of van alle nodes in dezelfde (aangemerkte) cluster */
/** 1.174.0: TrueNAS SCALE: wat de agent via midclt uitleest, met een knop per onderdeel om een check aan te maken */
const TN_TASK = { repl: () => L('Replicatie'), snap: () => L('Snapshots'), cloud: () => L('Cloud sync'), rsync: () => 'Rsync' };
const tnState = (s) => (['ERROR', 'FAILED', 'ABORTED', 'CRASHED'].includes(s) ? 'crit-txt' : ['RUNNING', 'FINISHED', 'SUCCESS'].includes(s) ? 'good-txt' : s === 'STOPPED' ? 'warn-txt' : 'muted');
const tnLevel = (l) => (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(l) ? 'crit-txt' : l === 'WARNING' ? 'warn-txt' : 'muted');
async function tnOverview(h) {
  let o;
  try { o = await api('truenas_overview', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const has = (metric, target = '') => o.checks.some((c) => c.metric === metric && (c.target || '') === String(target));
  const addBtn = (metric, target = '', label = '') => (has(metric, target) ? `<span class="badge" title="${esc(L('Er is al zo\'n check'))}">✔ ${L('check')}</span>`
    : `<button type="button" class="btn small-btn need-admin" data-tn-add="${metric}" data-tn-target="${esc(target)}">＋ ${esc(label || L('Check'))}</button>`);
  const when = (ts) => (ts ? esc(ago(new Date(ts * 1000).toISOString())) : '–');
  const alerts = o.alerts.length
    ? `<ul class="tn-list">${o.alerts.map((a) => `<li><b class="${tnLevel(a.l)}">${esc(a.l)}</b> ${esc(a.t)} <span class="muted small">${when(a.at)}</span></li>`).join('')}</ul>`
    : `<p class="good-txt small">✔ ${L('Geen meldingen')}</p>`;
  const tasks = o.tasks.length
    ? `<div class="table-wrap"><table class="small"><thead><tr><th>${L('Soort')}</th><th>${L('Taak')}</th><th>${L('Laatste keer')}</th><th></th></tr></thead><tbody>
      ${o.tasks.map((x) => `<tr class="${x.on ? '' : 'muted'}"><td>${esc((TN_TASK[x.k] || (() => x.k))())}</td><td>${esc(x.n)}${x.on ? '' : ` <span class="muted">(${L('uit')})</span>`}</td>
        <td><span class="${tnState(x.st)}">${esc(x.st || '–')}</span> · ${when(x.at)}${x.err ? `<br><span class="crit-txt">${esc(x.err)}</span>` : ''}</td>
        <td>${x.on ? addBtn('tasks', x.n, L('Bewaken')) : ''}</td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Geen taken ingesteld (replicatie, snapshots, cloud sync, rsync).')}</p>`;
  const apps = o.apps.length
    ? `<div class="table-wrap"><table class="small"><thead><tr><th>${L('App')}</th><th>${L('Status')}</th><th>${L('Versie')}</th><th></th></tr></thead><tbody>
      ${o.apps.map((a) => `<tr><td><b>${esc(a.n)}</b></td><td><span class="${tnState(a.st)}">● ${esc(a.st)}</span></td>
        <td>${esc(a.v || '')}${a.up ? ` <span class="warn-txt">⬆ ${L('update beschikbaar')}</span>` : ''}</td><td>${addBtn('apps', a.n, L('Bewaken'))}</td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Geen apps.')}</p>`;
  const temps = Object.entries(o.temps || {}).sort((a, b) => b[1] - a[1]);
  const tempHtml = temps.length ? `<div class="tn-temps">${temps.map(([d, t]) => `<span class="tn-temp ${t >= 60 ? 'crit' : t >= 50 ? 'warn' : ''}"><b>${esc(d)}</b> ${dec(t)} °C</span>`).join('')}</div>`
    : `<p class="muted small">${L('Geen schijftemperaturen ontvangen.')}</p>`;
  const u = o.update;
  const upd = !u ? `<span class="muted">${L('nog niet gecontroleerd')}</span>` : u.reboot ? `<b class="warn-txt">${L('bijgewerkt, wacht op een herstart')}</b>`
    : u.av ? `<b class="warn-txt">⬆ ${L('update beschikbaar: {v}', { v: esc(u.v || '?') })}</b>` : `<span class="good-txt">✔ ${L('bijgewerkt')}</span>`;
  const sec = (title, metric, body, label) => `<div class="tn-sec"><div class="tn-sec-h"><h3 class="set-sub">${title}</h3>${addBtn(metric, '', label)}</div>${body}</div>`;
  const { form } = modal({
    title: `🗄️ TrueNAS · ${h.name}`, wide: true,
    body: `<p class="muted small" style="margin-top:0">${esc(o.v || 'TrueNAS')} · ${L('bijgewerkt {t}', { t: when(o.at) })}${o.fresh ? '' : ` · <b class="warn-txt">${L('niet vers')}</b>`} · ${L('update')}: ${upd}</p>
      ${sec('🔔 ' + L('Meldingen van TrueNAS'), 'alerts', alerts, L('Meldingen bewaken'))}
      ${sec('🔁 ' + L('Taken'), 'tasks', tasks, L('Alle taken bewaken'))}
      ${sec('📦 ' + L('Apps'), 'apps', apps, L('Alle apps bewaken'))}
      ${sec('🌡️ ' + L('Schijftemperaturen'), 'temp', tempHtml, L('Temperatuur bewaken'))}
      ${sec('⬆ ' + L('TrueNAS-update'), 'update', '', L('Update bewaken'))}`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  form.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-tn-add]');
    if (!b) return;
    e.preventDefault();
    const metric = b.dataset.tnAdd, target = b.dataset.tnTarget || '', m = TN_METRICS[metric];
    b.disabled = true;
    try {
      await api('check_save', { host_id: h.id, type: 'truenas', metric, target, enabled: true, verify_ssl: true, interval_sec: metric === 'update' ? 3600 : 300,
        warn_threshold: m.warn ?? null, crit_threshold: m.crit ?? null });
      toast(L('Check toegevoegd'));
      $('#modal').close();
      await refresh();
      tnOverview(findHost(h.id));
    } catch (ex) { b.disabled = false; toast(ex.message, 'err'); }
  }, { signal: modalSignal() });   // 1.224.4: weg zodra er een ander venster komt
}

async function pveOverview(h) {
  let o;
  try { o = await api('pve_overview', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const now = Date.now() / 1000;
  const bkCls = (g) => (!g.bk_last ? 'crit-txt' : now - g.bk_last > 7 * 86400 ? 'crit-txt' : now - g.bk_last > 2 * 86400 ? 'warn-txt' : 'good-txt');
  const nodeHtml = (n) => {
    const p = n.node;
    const chk = (metric, target = '') => n.checks.find((c) => c.metric === metric && (c.target || '') === String(target));
    const guests = n.guests.map((g) => {
      const run = g.status === 'running';
      const memPct = +g.maxmem ? Math.round(g.mem / g.maxmem * 100) : null;
      const c = chk('guest', g.vmid);
      return `<tr class="${+g.template ? 'muted' : ''}"><td class="nowrap">${g.kind === 'ct' ? '📦' : '🖥️'} <b>${g.vmid}</b></td>
        <td><b>${esc(g.name || '–')}</b>${+g.template ? ` <span class="badge">${L('sjabloon')}</span>` : ''}${g.lock ? ` <span class="badge-root">🔒 ${esc(g.lock)}</span>` : ''}
          <br><span class="muted small">${g.kind === 'ct' ? L('container') : 'VM'}${+g.onboot ? ' · ' + L('start automatisch') : ''}${g.kind === 'vm' && +g.agent ? ' · ' + (g.ga === 0 ? '⚠️ ' + L('guest agent antwoordt niet') : L('guest agent')) : ''}</span></td>
        <td>${run ? `<span class="good-txt">● ${L('draait')}</span>` : `<span class="${+g.onboot && !+g.template ? 'crit-txt' : 'muted'}">○ ${esc(g.status)}</span>`}${run ? `<br><span class="muted small">${L('aan sinds {t}', { t: esc(pveAgo(now - g.uptime)) })}</span>` : ''}</td>
        <td class="small">${run ? `CPU ${dec(g.cpu ?? 0)}% · ${g.cpus} vCPU<br>RAM ${pveBytes(g.mem)} / ${pveBytes(g.maxmem)}${memPct !== null ? ` (${memPct}%)` : ''}` : `<span class="muted">RAM ${pveBytes(g.maxmem)}</span>`}</td>
        <td class="small">${+g.template ? '–' : `<span class="${bkCls(g)}">${g.bk_last ? esc(pveAgo(g.bk_last)) : L('nooit')}</span>${g.bk_n ? `<br><span class="muted">${L('{n}× op {s}', { n: g.bk_n, s: esc(g.bk_store || '') })}</span>` : ''}${g.job === 0 ? `<br><span class="warn-txt">${L('in geen back-uptaak')}</span>` : ''}`}</td>
        <td class="nowrap">${c ? `<span class="badge" title="${esc(L('Er is al een check voor deze guest'))}">✔ ${L('check')}</span>` : +g.template ? '' : `<button type="button" class="btn small-btn need-admin" data-pve-add="${n.host_id}:${g.vmid}">＋ Check</button>`}</td></tr>`;
    }).join('');
    const stor = p.storage.map((s) => {
      const pct = +s.total ? Math.round(s.used / s.total * 100) : null;
      return `<tr><td><b>${esc(s.name)}</b><br><span class="muted small">${esc(s.type)}${+s.shared ? ' · ' + L('gedeeld') : ''}</span></td>
        <td>${!+s.enabled ? `<span class="muted">${L('uit')}</span>` : +s.active ? `<span class="good-txt">● ${L('actief')}</span>` : `<span class="crit-txt">✘ ${L('niet beschikbaar')}</span>`}</td>
        <td class="small">${pct !== null ? `<span class="${pct >= 95 ? 'crit-txt' : pct >= 85 ? 'warn-txt' : ''}">${pct}%</span> · ${pveBytes(s.used)} / ${pveBytes(s.total)}` : '–'}</td>
        <td class="small muted">${esc(s.content.replace(/,/g, ', '))}</td></tr>`;
    }).join('');
    const zfs = p.zfs.map((z) => `<li><b>${esc(z.name)}</b>: <span class="${z.health === 'ONLINE' ? 'good-txt' : 'crit-txt'}">${esc(z.health)}</span> · ${z.cap}% ${L('vol')} (${pveBytes(z.alloc)} / ${pveBytes(z.size)})
      <br><span class="muted small">${esc(z.scan || '')}</span></li>`).join('');
    const jobs = p.jobs.map((j) => `<li>${j.on ? '✔' : `<span class="muted">${L('uit')}</span>`} <b>${esc(j.id || '?')}</b> · ${esc(j.schedule || '')} → ${esc(j.storage || '')} · ${j.all ? L('alle guests') : j.pool ? L('pool {p}', { p: esc(j.pool) }) : esc(j.vmids.join(', '))}</li>`).join('');
    const lt = p.last_task, lf = p.last_fail;
    return `<section class="pve-node">
      <h3 style="margin:.4rem 0">🖥️ ${esc(p.node || n.host)} <span class="muted small">${esc((p.version || '').replace(/^pve-manager\/([\d.]+).*$/, 'PVE $1'))} · ${L('{r} van {t} draaien', { r: p.n.running, t: p.n.vm + p.n.ct })} · ${L('bijgewerkt {t}', { t: esc(pveAgo(p.at)) })}</span></h3>
      <div class="table-scroll"><table class="vuln-table"><thead><tr><th>ID</th><th>${L('Naam')}</th><th>${L('Status')}</th><th>${L('Gebruik')}</th><th>${L('Laatste back-up')}</th><th></th></tr></thead><tbody>${guests || `<tr><td colspan="6" class="muted">${L('Geen guests.')}</td></tr>`}</tbody></table></div>
      <h4 class="acc-sub">💾 ${L('Opslag')}</h4>
      <div class="table-scroll"><table class="vuln-table"><tbody>${stor}</tbody></table></div>
      ${zfs ? `<h4 class="acc-sub">🗄️ ZFS</h4><ul class="small">${zfs}</ul>` : ''}
      <h4 class="acc-sub">📅 ${L('Back-uptaken')}</h4>
      ${jobs ? `<ul class="small">${jobs}</ul>` : `<p class="warn-txt small">${L('Geen back-uptaken (Datacenter → Backup).')}</p>`}
      <p class="small">${lt ? L('Laatste taak: {t} · {s}', { t: esc(new Date(lt.start * 1000).toLocaleString(LOCALE)), s: lt.status === 'OK' ? '✔ OK' : '✘ ' + esc(lt.status) }) : L('Nog geen back-uptaak gezien.')}
        ${lf && (!lt || lf.start !== lt.start) ? `<br><span class="muted">${L('Laatst mislukt: {t} ({s})', { t: esc(new Date(lf.start * 1000).toLocaleString(LOCALE)), s: esc(lf.status) })}</span>` : ''}</p>
    </section>`;
  };
  const clusterOpts = o.clusters.map((c) => `<option value="${esc(c)}">`).join('');
  modal({
    title: '🖥️ ' + (o.cluster ? L('Proxmox-cluster {c}', { c: o.cluster }) : L('Proxmox – {name}', { name: h.name })),
    wide: true,
    body: `<div class="cmd-host-row need-admin">🔗 ${L('Cluster:')} <input id="pve-cluster" list="pve-clusters" value="${esc(o.cluster)}" placeholder="${esc(L('geen (losse node)'))}" style="max-width:14rem">
        <datalist id="pve-clusters">${clusterOpts}</datalist> <button type="button" class="btn small-btn" id="pve-cluster-save">${L('Opslaan')}</button>
        <span class="muted small">${L('Geef losse nodes dezelfde naam om ze hier samen te zien.')}</span></div>
      ${o.nodes.length ? o.nodes.map(nodeHtml).join('') : `<p class="muted">${L('Nog geen Proxmox-gegevens. Werk de agent bij naar {v} of nieuwer.', { v: '1.61.0' })}</p>`}`,
    buttons: [...(isAdmin() ? [{ label: L('＋ Aanbevolen checks'), style: 'margin-right:auto', onClick: () => { $('#modal').close(); pveRecommended(findHost(h.id)); } }] : []),
      { label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  $('#pve-cluster-save').onclick = async () => {
    try { await api('pve_cluster_set', { host_id: h.id, name: $('#pve-cluster').value }); toast(L('Opgeslagen')); $('#modal').close(); pveOverview(h); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#modal-body').onclick = async (e) => {
    const b = e.target.closest('[data-pve-add]');
    if (!b) return;
    const [hid, vmid] = b.dataset.pveAdd.split(':');
    b.disabled = true;
    try {
      await api('check_save', { host_id: +hid, type: 'pve', metric: 'guest', target: vmid, enabled: true, interval_sec: 120, verify_ssl: true, warn_threshold: 90, crit_threshold: 95 });
      b.outerHTML = `<span class="badge">✔ ${L('check')}</span>`;
      toast(L('Check toegevoegd'));
      refresh();
    } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
  };
}

/** Aanbevolen checks voor de Home Assistant-add-on: OS, containers, USB-sticks en logs */
function haRecommended(h) {
  const has = (type, metric, target = '') => h.checks.some((c) => c.type === type && (c.metric || '') === metric && (target === '' || c.target === target));
  const items = [
    ...['backup', 'integrations', 'repairs', 'reboot', 'disklife', 'osupdate'].map((m) => ({ key: `ha_${m}`, label: HA_METRICS[m].label, exists: has('ha', m),
      data: { type: 'ha', metric: m, warn_threshold: HA_METRICS[m].warn ?? '', crit_threshold: HA_METRICS[m].crit ?? '' } })),
    ...h.metrics.filter((m) => m.startsWith('usb:')).map((m) => ({ key: `usb_${m}`, label: L('USB-apparaat {name}', { name: m.slice(4) }), exists: has('ha', 'usb', m.slice(4)),
      data: { type: 'ha', metric: 'usb', target: m.slice(4) } })),
    ...['homeassistant', 'hassio_supervisor'].filter((n) => h.metrics.includes(`container:${n}`)).map((n) => ({ key: `ct_${n}`,
      label: L('Container {name}: status, CPU en RAM', { name: n === 'homeassistant' ? 'Home Assistant Core' : 'Supervisor' }), exists: h.checks.some((c) => c.type === 'container' && c.target === n),
      data: { type: 'container', target: n, name: n === 'homeassistant' ? '📦 Home Assistant Core' : '📦 Supervisor', res: { cpu: { on: true, warn: 80, crit: 95 }, mem: { on: true, warn: 85, crit: 95 }, disk: { on: false } } } })),
    ...[['ha_power', 'ha:host'], ['ha_core', 'ha:core']].map(([p, src]) => ({ key: `log_${p}`, label: LOG_PRESETS[p].label, exists: h.checks.some((c) => c.type === 'log' && c.target === src),
      data: { type: 'log', target: src, name: LOG_PRESETS[p].label.replace(/^\S+\s/, ''), preset: p, pattern: LOG_PRESETS[p].pat, warn_threshold: LOG_PRESETS[p].warn, crit_threshold: LOG_PRESETS[p].crit } })),
  ];
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    wide: true,
    body: `<p style="margin-top:0">${L('Herkend als <b>Home Assistant</b>. Vink aan wat je wilt bewaken; je kunt elke check later nog aanpassen.')}</p>
      <div class="rec-list">${items.map((x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${esc(x.key)}" ${x.exists ? 'disabled' : 'checked'}>
        <span><b>${esc(x.label)}</b>${x.exists ? ' · ' + L('al aanwezig') : ''}</span></label>`).join('')}</div>
      ${xpHtml(L('Containers, USB en logs vragen een nieuwere add-on.'), L('Containers, USB-apparaten en logs vragen add-on 1.42.0 of nieuwer met de rol <i>manager</i>. <b>Goed om te weten:</b> dit is nog niet op een echte Home Assistant getest; laat het weten als iets niet goed uitkomt.'))}`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const x of chosen) await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, verify_ssl: true, ...x.data });
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

/** Aanbevolen checks voor een camera (1.76.0): bereikbaar, videostroom (RTSP) en de webpagina */
function cameraRecommended(h) {
  const has = (type, target = '') => h.checks.some((c) => c.type === type && (target === '' || String(c.target) === target));
  const tcp = (port, name) => ({ type: 'tcp', target: String(port), name, warn_threshold: TYPES.tcp.warn ?? '', crit_threshold: TYPES.tcp.crit ?? '' });
  const items = [
    { key: 'ping', label: L('Ping: is de camera bereikbaar?'), exists: has('ping'), on: true, data: { type: 'ping', name: 'Ping', warn_threshold: 100, crit_threshold: 500 } },
    { key: 'rtsp', label: L('RTSP (poort 554): komt de videostroom door?'), exists: has('tcp', '554'), on: true, data: tcp(554, 'RTSP') },
    { key: 'http', label: L('Webpagina (poort 80)'), exists: has('tcp', '80'), on: false, data: tcp(80, L('Webpagina')) },
    { key: 'https', label: L('Webpagina via https (poort 443)'), exists: has('tcp', '443'), on: false, data: tcp(443, L('Webpagina (https)')) },
  ];
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    body: `<p style="margin-top:0">${L('Herkend als <b>📷 camera</b>. Vink aan wat je wilt bewaken; je kunt elke check later nog aanpassen.')}</p>
      <div class="rec-list">${items.map((x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${x.key}" ${x.exists ? 'disabled' : x.on ? 'checked' : ''}>
        <span><b>${esc(x.label)}</b>${x.exists ? ' · ' + L('al aanwezig') : ''}</span></label>`).join('')}</div>
      <p class="muted small">${L('Geeft de camera zijn beeld via een andere poort (bv. 8554 of 7447 bij UniFi Protect), voeg dan zelf een poort-check toe (＋ Check → Poort).')}</p>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const x of chosen) await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, ...x.data });
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

async function recommendedChecks(h) {
  if (isHaHost(h)) return haRecommended(h);
  if (isPveHost(h)) return pveRecommended(h);
  let list = null, mode = 'off';
  try { list = (await api('agent_services', null, { id: h.id })).list; } catch (e) { /* zonder lijst: Linux */ }
  try { mode = ((await api('agent_commands')).hosts.find((x) => x.id === +h.id) || {}).mode || 'off'; } catch (e) { /* leeg */ }
  const { platform, os } = detectPlatform(list);
  const have = new Set(h.checks.filter((c) => c.type === 'command').map((c) => c.name));
  const names = new Set((list || []).map((x) => x[0]));
  // ZFS en Docker ook voorstellen als die diensten op de server draaien
  const offered = (k, p) => platform in p.rec || (k === 'zfs_health' && names.has('zfs-zed')) || (k === 'docker' && names.has('docker'));
  const items = Object.entries(CMD_PRESETS).filter(([k, p]) => offered(k, p) && (!p.os || !os || p.os === os));
  // Standaard aangevinkt: aanbevolen voor dit platform (bij onbekende Linux-variant niet de distro-specifieke)
  const checked = (k, p) => (p.rec[platform] && (!p.os || p.os === os)) || (k === 'zfs_health' && names.has('zfs-zed')) || (k === 'docker' && names.has('docker'));
  const row = ([k, p]) => {
    const exists = have.has(p.name);
    return `<label class="rec-row ${exists ? 'muted' : ''}"><input type="checkbox" name="rec_${k}" ${!exists && checked(k, p) ? 'checked' : ''} ${exists ? 'disabled' : ''}>
      <span><b>${esc(p.label)}</b>${p.root ? ' <span class="badge-root">root</span>' : ''}${exists ? ' · ' + L('al aanwezig') : ''}<br>
      <code class="small">${esc(p.cmd.length > 90 ? p.cmd.slice(0, 87) + '…' : p.cmd)}</code></span></label>`;
  };
  const needRoot = items.some(([, p]) => p.root);
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    wide: true,
    body: `<p style="margin-top:0">${L('Herkend als <b>{p}</b>{os}. Vink aan wat je wilt bewaken; elke regel wordt een <i>Commando</i>-check die je later nog kunt aanpassen.', { p: CMD_GROUPS[platform], os: os ? ` (${os === 'rhel' ? 'Rocky/RHEL' : L('Debian-familie')})` : '' })}</p>
      ${mode === 'off' ? `<p class="warn-txt small">${L('<b>⚠️ Commando\'s staan voor {name} uit.</b> De checks blijven onbekend tot je ze aanzet (Agent Manager → Commando-checks op deze server).', { name: esc(h.name) })}</p>`
        : mode === 'nobody' && needRoot ? '<p class="warn-txt small">' + L('⚠️ Checks met <b>root</b> werken alleen als commando\'s op deze server <i>als root</i> mogen.') + '</p>' : ''}
      <div class="rec-list">${items.map(row).join('')}</div>
      <p class="muted small">${L('Voorinstellingen voor TrueNAS en Proxmox zijn nog niet op een echt systeem getest; laat weten als er een niet goed uitkomt.')}</p>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter(([k]) => d['rec_' + k]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const [, p] of chosen) {
        await api('check_save', { host_id: h.id, type: 'command', name: p.name, interval_sec: p.interval, enabled: true,
          command: { cmd: p.cmd, expect: p.expect, match: p.match, timeout: p.timeout, fail: p.fail } });
      }
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

/** Versies vergelijken ("1.10.0" > "1.9.2"); negatief als a ouder is dan b. */
function verCmp(a, b) {
  const pa = String(a).split('.').map(Number), pb = String(b).split('.').map(Number);
  for (let i = 0; i < 3; i++) if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) - (pb[i] || 0);
  return 0;
}
/** Agent-versie als tekst, met ⬆ als er een nieuwere agent is (eigen nummer, los van RzSense). */
function agentVer(h) {
  const busy = h.agent_update_req ? ' <span title="' + L('Wordt bijgewerkt') + '">⏳</span>' : '';
  if (!h.agent_version) return h.agent_seen ? ` · ${L('versie onbekend')} ⬆${busy}` : '';
  return ` · v${esc(h.agent_version)}${state.agent_latest && verCmp(h.agent_version, state.agent_latest) < 0 ? ' ⬆' : ''}${busy}`;
}

/** Endpoint-bewaking in de Agent Manager: welke sensoren werken, wat ontbreekt (met installeren), en de voortgang */
const EDR_AUDIT = { ok: ['✔', () => L('processen en bewaakte paden (auditd)')], missing: ['✘', () => L('auditd ontbreekt')], stopped: ['⚠️', () => L('auditd is geïnstalleerd maar draait niet')],
  locked: ['⚠️', () => L('auditd staat op slot (-e 2): regels pas na een herstart')], noroot: ['✘', () => L('de agent draait niet als root')], off: ['·', () => L('uit')] };
async function loadEdrHost(h, poll = false, gen = modalGen) {
  const box = $('#host-edr-info');
  if (gen !== modalGen || !box || !$('#modal').open) return;   // 1.224.4: niet in het venster van een andere server
  let d;
  try { d = await api('edr_host', null, { id: h.id }); } catch (ex) { box.innerHTML = `<span class="small bad">${esc(ex.message)}</span>`; return; }
  if (gen !== modalGen) return;
  const i = d.info || {};
  const lines = [];
  if (!d.ids) lines.push(`<span class="warn-txt">⚠️ ${L('Werkt pas als het IDS aan staat (Instellingen → IDS).')}</span>`);
  else if (!d.global) lines.push(`<span class="muted">${L('Staat voor alle servers uit (Instellingen → IDS → Endpoint-bewaking).')}</span>`);
  else if (!d.on) lines.push(`<span class="muted">${L('Uit voor deze server.')}</span>`);
  else if (!d.at) lines.push(`<span class="muted">${L('Nog geen gegevens van de agent (agent {v} of nieuwer nodig).', { v: '1.63.0' })}</span>`);
  else {
    const a = EDR_AUDIT[i.audit] || ['·', () => esc(i.audit || '?')];
    lines.push([`${a[0]} ${a[1]()}`, i.file === 'ok' ? `✔ ${L('bestanden (inotify)')}` : `✘ ${L('inotify-tools ontbreekt')}`, `✔ ${L('netwerk')}`,
      i.ctr && i.ctr !== 'none' ? `✔ ${L('containers ({rt})', { rt: esc(i.ctr) })}` : `· ${L('geen containers')}`,
      i.watch === 'on' ? `✔ ${L('realtime-watcher actief')}` : `⚠️ ${+i.systemd ? L('de realtime-watcher draait (nog) niet') : L('geen systemd: alleen bij de gewone runs')}`,
      ...(i.yara ? [i.yara === 'ok' ? `✔ YARA${i.ysha ? '' : ' ' + L('(regels volgen)')}` : `○ ${L('geen YARA')}`] : []),
      ...(i.sur === 'ok' ? [`✔ Suricata`] : i.sur === 'noeve' ? [`⚠️ ${L('Suricata zonder eve.json')}`] : [])].join(' · '));
    if (+i.drop > 0) lines.push(`<span class="warn-txt">${L('{n} gebeurtenissen overgeslagen (te veel tegelijk)', { n: +i.drop })}</span>`);
  }
  const up = d.install && d.install.mode === 'install' ? d.install : null;
  const busy = up && (up.state === 'queued' || up.state === 'running');
  if (busy) lines.push(`⏳ ${L('Wordt geïnstalleerd: {p}', { p: esc((up.pkgs || []).join(' ')) })}${up.pct ? ` (${+up.pct}%)` : ''}`);
  else if (up && up.state === 'ok' && Date.now() - new Date(up.at.replace(' ', 'T')) < 3600e3) lines.push(`<span class="good">✔ ${L('Geïnstalleerd: {p}', { p: esc((up.pkgs || []).join(' ')) })}</span>`);
  else if (up && up.state === 'error') lines.push(`<span class="bad">✘ ${L('Installeren mislukt: {p}', { p: esc((up.pkgs || []).join(' ')) })}</span>${up.log ? `<pre class="code small">${esc(up.log.slice(-400))}</pre>` : ''}`);
  const comps = [...d.missing, ...(d.optional || [])];
  const rpm = d.fam === 'rhel';
  // 1.124.0: precies wat de agent uitvoert (zelfde pakketten als Edr::install), en de versie om zelf te typen
  const pkgsOf = (c) => (c === 'auditd' ? [rpm ? 'audit' : 'auditd'] : [...(rpm && !+i.epel ? ['epel-release'] : []), c === 'yara' ? 'yara' : 'inotify-tools']);
  const agentCmd = (c, own = null) => {
    const p = own || pkgsOf(c), out = [];
    if (rpm) { if (p[0] === 'epel-release') out.push('dnf -y install epel-release'); out.push(`dnf -y install ${p.filter((x) => x !== 'epel-release').join(' ')}`); }
    else out.push('apt-get update', `apt-get install -y --no-install-recommends ${p.join(' ')}`);
    if (c === 'auditd') out.push('systemctl enable --now auditd');
    return out;
  };
  const ownCmd = (c, own = null) => agentCmd(c, own).map((x) => 'sudo ' + x.replace(/ -y\b/, '').replace(' --no-install-recommends', '')).join(' && ');
  if (d.on && d.ids && comps.length && !busy) {
    lines.push(comps.map((c, k) => `<div class="edr-miss"><b>${esc(EDR_COMP[c][0])}</b> <span class="muted small">${EDR_COMP[c][1]()}</span>
      ${d.install_blocked ? `<span class="muted small">· ${L('via de agent kan het hier niet: {why}', { why: esc(d.install_blocked) })}</span>`
        : `<button type="button" class="btn small-btn primary" data-edr-install="${c}">⬇ ${L('Installeren via de agent')}</button>`}
      <details class="cmd-dd small"><summary>${L('Commando')}</summary>
        ${d.install_blocked ? '' : `<div class="muted">${L('Dit voert de agent uit (als root):')}</div><pre class="code" data-edr-preview="${c}">${esc(agentCmd(c).join('\n'))}</pre>
          <label class="small edr-own">${L('Eigen pakketnamen')} <span class="muted">${L('(optioneel; gescheiden door spaties, hooguit 10; agent {v} of nieuwer)', { v: '1.66.0' })}</span>
            <span class="inline-row"><input class="mono" data-edr-own="${c}" value="${esc(pkgsOf(c).join(' '))}" spellcheck="false" autocomplete="off">
            <button type="button" class="btn small-btn" data-edr-install="${c}" data-own="1">⬇ ${L('Met deze pakketten installeren')}</button></span></label>`}
        <div class="muted">${L('Of zelf op de server:')}</div>${copyBox(`edr-own-${k}`, ownCmd(c))}</details></div>`).join(''));
  }
  withMorph(() => { box.innerHTML = `<div class="small">${lines.join('<br>')}</div>`; });
  wireCopy(box);
  // Eigen pakketnamen: het voorbeeld van wat de agent uitvoert loopt mee
  $$('[data-edr-own]', box).forEach((inp) => {
    inp.oninput = () => {
      const names = inp.value.trim().split(/[\s,]+/).filter(Boolean);
      const pre = $(`[data-edr-preview="${inp.dataset.edrOwn}"]`, box);
      if (pre) pre.textContent = agentCmd(inp.dataset.edrOwn, names.length ? names : null).join('\n');
      const mine = inp.closest('.cmd-dd') && $('.copy-line pre', inp.closest('.cmd-dd'));
      if (mine) mine.textContent = ownCmd(inp.dataset.edrOwn, names.length ? names : null);
    };
  });
  $$('[data-edr-install]', box).forEach((b) => {
    b.onclick = async () => {
      const c = b.dataset.edrInstall;
      const def = pkgsOf(c).join(' ');
      const ownVal = b.dataset.own ? ($(`[data-edr-own="${c}"]`, box) || {}).value || '' : '';
      const own = ownVal.trim().split(/[\s,]+/).filter(Boolean).join(' ') === def ? '' : ownVal.trim();
      const pw = await askConfirm({ title: L('{c} installeren op {name}?', { c: own ? own : EDR_COMP[c][0], name: h.name }),
        text: L('De agent installeert dit als root bij zijn volgende rapport.'), word: 'INSTALLEREN', ok: '⬇ ' + L('Installeren') });
      if (pw === null) return;
      try {
        await api('edr_install', { host_id: h.id, comp: c, confirm: pw, ...(own ? { pkgs: own } : {}) });
        toast(L('De agent installeert {c} bij zijn volgende rapport', { c: EDR_COMP[c][0] }));
        loadEdrHost(h, true);
      } catch (ex) { toast(ex.message, 'err'); }
    };
  });
  if (busy || poll) setTimeout(() => loadEdrHost(h, busy, gen), 4000);
}

/** Live-status van de agent in het venster, elke 5 s zolang het open is. */
let agentPollGen = 0;
async function pollAgentStatus(hostId, gen = ++agentPollGen) {
  const box = $('#agent-live');
  // Stoppen als het venster dicht is of opnieuw geopend (dan loopt er al een nieuwe lus)
  if (!box || !$('#modal').open || gen !== agentPollGen) return;
  try {
    const s = await api('agent_status', null, { id: hostId });
    const age = s.seen ? Math.round((new Date(s.now.replace(' ', 'T')) - new Date(s.seen.replace(' ', 'T'))) / 1000) : null;
    const fresh = age !== null && age < 180;
    let html = s.mode !== 'agent'
      ? `<b class="bad">${L('✘ Deze server staat op type "{mode}".', { mode: esc(s.mode) })}</b> ${L('Een agent werkt alleen bij type <b>Remote met agent</b> (✎ Bewerken).')}`
      : s.seen
        ? `<b class="${fresh ? 'good' : 'bad'}">${fresh ? '✔' : '⚠'} ${L('Laatste rapport {ago}', { ago: ago(s.seen) })}</b>${s.ip ? ' ' + L('vanaf {ip}', { ip: `<code>${esc(s.ip)}</code>` }) : ''}${fresh ? '' : ' — ' + L('de agent rapporteert niet meer')}`
        : '<b class="wait">' + L('⏳ Nog geen rapport ontvangen') + '</b> <span class="muted small">' + L('(wordt elke 5 s bijgewerkt)') + '</span>';
    if (s.mode === 'agent' && s.seen) {
      const old = !s.agent_version || verCmp(s.agent_version, s.version) < 0;
      const u = s.update || {};
      const canRemote = u.via === 'systemd' || u.via === 'direct';
      html += `<div class="small">${L('Agent-versie <b>{v}</b>', { v: s.agent_version ? esc(s.agent_version) : L('onbekend (ouder dan 1.6.0)') })}${old ? ', ' + L('nieuwste agent {v}', { v: esc(s.version) }) : ' ✔ ' + L('nieuwste')}</div>`;
      if (u.status) html += `<div class="small agent-upd-status">${esc(u.status)}</div>`;
      html += `<div class="small">${realtimeText(s.realtime)}</div>`;
      // 🐕 Waakhond: werkt alleen met Pushover en een versleutelde verbinding (de gegevens gaan naar de agent)
      const wdi = $('#host-wd-info');
      if (wdi && s.watchdog) {
        wdi.innerHTML = !s.watchdog.pushover ? `<span class="warn-txt">⚠️ ${L('Zet eerst Pushover of ntfy aan (Meldingen → Kanalen): de waakhond meldt via push')}</span>`
          : !s.watchdog.enc ? `<span class="warn-txt">⚠️ ${L('Werkt alleen versleuteld: koppel de agent met een koppelcode (de pushgegevens gaan naar de agent)')}</span>`
          : s.watchdog.on ? `✔ ${L('actief: na {n} min zonder RzSense een pushbericht via {via}, en een bericht als de checks stilstaan', { n: s.watchdog.min, via: s.watchdog.push || 'Pushover' })}`
            + (/ntfy/.test(s.watchdog.push || '') && verCmp(s.agent_version || '0', '1.72.0') < 0 ? ` <span class="warn-txt">· ${L('ntfy vanaf agent 1.72.0')}</span>` : '')
          : L('deze agent stuurt zelf een pushbericht (Pushover of ntfy) als RzSense onbereikbaar is of de checks stilstaan');
      }
      const tw = toolsWarning(s.realtime && s.realtime.tools, s.update && s.update.via);
      if (tw) html += `<div class="small tools-warn">${tw}</div>`;
      if (isAdmin() && u.pending) {
        html += '<div class="btn-row agent-upd"><button type="button" class="btn" data-agent-upd="cancel">' + L('Bijwerken annuleren') + '</button></div>';
      } else if (isAdmin() && (old || canRemote)) {
        html += `<div class="btn-row agent-upd"><button type="button" class="btn ${old ? 'primary' : ''}" data-agent-upd="go">⬆ ${old ? L('Bijwerken naar {v}', { v: esc(s.version) }) : L('Opnieuw installeren')}</button>
          <span class="muted small">${canRemote ? L('De agent werkt zichzelf bij binnen een minuut.')
            : u.via === 'ha' ? L('Home Assistant-add-on: bijwerken via de add-on winkel.')
            : u.via === 'off' ? L('Op afstand bijwerken staat uit op deze server (REMOTE_UPDATE=0).')
            : L('Deze agent kan dat nog niet: werk hem één keer bij op de server met <code>sudo rzmon-agent-setup --update</code>.')}</span></div>`;
      }
    }
    // Verbindingscontrole (1.100.0)
    if (s.mode === 'agent' && s.seen && s.audit && s.audit.length) {
      const bad = s.audit.filter((i) => i.ok === false);
      const fixable = bad.some((i) => ['config', 'token', 'integrity', 'version'].includes(i.key));
      html += `<details class="audit-box" ${bad.length ? 'open' : ''}><summary class="small">🔐 <b>${L('Verbindingscontrole')}</b>: ${bad.length
        ? `<span class="warn-txt">${L('{n} aandachtspunt(en)', { n: bad.length })}</span>` : `<span class="good">${L('alles in orde')}</span>`}</summary>
        <ul class="audit-list">${s.audit.map((i) => `<li class="small"><span>${i.ok === true ? '✅' : i.ok === false ? '⚠️' : '·'}</span> <b>${esc(i.label)}</b> <span class="muted">${esc(i.detail)}</span></li>`).join('')}</ul>
        ${isAdmin() ? `<div class="btn-row">${fixable ? `<button type="button" class="btn small-btn primary" data-agent-clean>🧹 ${L('Opruimen')}</button>` : ''}
          ${s.audit.some((i) => i.key === 'https' && i.ok === false) ? `<button type="button" class="btn small-btn" data-agent-https>🌐 ${L('Naar https')}</button>` : ''}
          <span class="muted small">${L('Alles over de verbinding, op de server zelf:')} <code>rzmon-agent-setup --status</code></span></div>` : ''}</details>`;
    }
    // 1.137.0 (agent 1.69.0): bezig (hartslag), duur van de laatste run per onderdeel, en wat de agent hier kan
    if (s.mode === 'agent' && s.busy) {
      html += `<div class="small agent-busy">⏳ ${L('Bezig met een run sinds {t}: de agent meldt elke tik dat hij leeft, het rapport volgt.', { t: fmtUntil(s.busy) })}</div>`;
    }
    if (s.mode === 'agent' && s.timing && s.timing.total) {
      const tot = s.timing.total, top = Object.entries(s.timing.parts || {}).filter(([, ms]) => ms >= 200).slice(0, 5);
      const secs = (ms) => dec((ms / 1000).toFixed(ms < 10000 ? 1 : 0)) + ' s';
      const name = (k) => ({ disk: L('Disks'), containers_prep: L('Containers (voorbereiden)'), containers: L('Containers'), cpu: 'CPU', ram: 'RAM', ports: L('Poorten'),
        services: L('Services'), svc_deep: L('Diepere service-checks'), commands: L('Commando-checks'), logs: L('Logbestanden'), logins: L('Logins'), ids: 'IDS',
        weblogs: L('Logs van webdiensten'), edr: L('Endpoint-bewaking'), proxmox: 'Proxmox', certs: L('Certificaten'), cert_search: L('Certificaten zoeken'),
        procs: L('Processen'), software: L('Software'), ha: 'Home Assistant', ha_os: 'Home Assistant OS' })[k] || k;
      html += `<details class="audit-box agent-timing"${tot > 30000 ? ' open' : ''}><summary class="small">⏱ <b>${L('Laatste run')}</b>: ${secs(tot)}${top.length ? ` · ${L('traagst')}: ${esc(name(top[0][0]))} ${secs(top[0][1])}` : ''}</summary>
        ${top.length ? `<table class="kv small">${top.map(([k, ms]) => `<tr><th>${esc(name(k))}</th><td><span class="tbar"><i style="width:${Math.max(2, Math.round(ms / tot * 100))}%"></i></span> ${secs(ms)}</td></tr>`).join('')}</table>` : ''}
        ${xpHtml(L('Een trage commando-check houdt het rapport niet op.'), L('Commando-checks draaien op de achtergrond, dus een trage check houdt het rapport niet op. Duurt een run langer dan de tik, dan meldt de agent tussendoor dat hij nog leeft.'))}</details>`;
    }
    if (s.mode === 'agent' && s.update_sig) {
      html += `<div class="small">${{ ok: `🔏 ${L('Laatste update: handtekening van de ontwikkelaar gecontroleerd')}`, old_openssl: `⚠️ ${L('Laatste update: handtekening niet te controleren (OpenSSL op de server is ouder dan 3.0; alleen het controlegetal)')}`,
        off: `⚠️ ${L('Handtekeningen staan uit op deze server (UPDATE_UNSIGNED=1)')}`, bad: `<b class="bad">✘ ${L('Laatste update geweigerd: de handtekening klopte niet')}</b>` }[s.update_sig] || ''}</div>`;
    }
    if (s.mode === 'agent' && s.caps && s.caps.length) {
      html += `<div class="small agent-caps"><span class="muted">${L('Kan op deze server')}:</span> ${s.caps.map((c) => `<span class="chip-s">${esc(c)}</span>`).join(' ')}</div>`;
    }
    if (s.report_warn && s.report_warn.length) {
      html += `<div class="fails"><span class="small warn-txt">⚠️ ${L('Het laatste rapport was deels onleesbaar; dit onderdeel is weggelaten (de rest kwam gewoon binnen):')}</span>
        ${s.report_warn.map((w) => `<div class="small"><b>${esc(w.key)}</b> · ${esc(w.error)}<br><code class="small">${esc(w.near)}</code></div>`).join('')}</div>`;
    }
    if (s.failures.length) {
      html += `<div class="fails"><span class="muted small">${L('Geweigerde pogingen (24 uur):')}</span>
        ${s.failures.map((f) => `<div class="small"><code>${esc(f.ip)}</code> · ${ago(f.created_at)}${+f.n > 1 ? ` (${f.n}×)` : ''} · ${esc(f.reason)}</div>`).join('')}</div>`;
    }
    if (gen === agentPollGen) withMorph(() => { box.innerHTML = html; });   // 1.109.0: wat je openklapte blijft open
  } catch (ex) {
    if (gen === agentPollGen) box.innerHTML = `<span class="muted small">${esc(ex.message)}</span>`;
  }
  setTimeout(() => pollAgentStatus(hostId, gen), 5000);
}

