// ---------------------------------------------------------------- 🛰️ Netwerken en relays (1.282.0, agent 1.109.0)
// Beheer → Agents en koppelingen → Netwerken en relays. Een netwerk zonder verbinding met RzSense krijgt een relay:
// agent op server → relay → RzSense. Hier: de relays (installeren, adres), de netwerken (adresreeksen, relay en
// reserve-relay) en per server in welk netwerk hij zit en of hij moet overstappen.
const RELAY_IMAGE = 'ghcr.io/rembrand20/rzsense-relay:latest';
const RELAY_STATE = {
  ok: () => '✔ ' + L('goed'),
  switch: () => '↪ ' + L('moet overstappen'),
  pending: () => '⏳ ' + L('overstap klaargezet'),
  old: () => '⚠ ' + L('agent te oud (1.53.0 nodig)'),
  nokey: () => '⚠ ' + L('eerst koppelen met een koppelcode'),
  no_url: () => '⚠ ' + L('relay heeft nog geen adres'),
  no_direct: () => '⚠ ' + L('rechtstreeks adres onbekend'),
};
let relayData = null;

const relayName = (id) => (relayData.relays.find((r) => r.id === id) || {}).name || '–';
const relayPinShort = (p) => (p ? `<code title="sha256//${esc(p)}">${esc(p.slice(0, 10))}…</code>` : '–');

async function loadRelaySet() {
  const box = $('#relay-body');
  if (!box) return;
  try { relayData = await api('relay_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const d = relayData;
  const sw = d.hosts.filter((h) => h.state === 'switch');
  box.innerHTML = `
    <h3 class="set-sub">${L('Relays')}</h3>
    ${d.relays.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Relay')}</th><th>${L('Status')}</th><th>${L('Adres voor de agents')}</th><th>${L('Netwerken')}</th><th>${L('Verkeer')}</th><th></th></tr></thead><tbody>
      ${d.relays.map((r) => `<tr data-relay="${r.id}">
        <td><b>${esc(r.name)}</b><br><span class="muted">${r.kind === 'agent' ? '🐧 ' + (r.of_name ? esc(L('losse agent op {s}', { s: r.of_name })) : esc(L('losse agent'))) : r.kind === 'container' ? '📦 ' + esc(L('container')) : ''}
          ${r.relay_ver ? ' · ' + esc(r.relay_ver) : ' · ' + L('nog niet gemeld')}</span>
          ${r.outdated && r.kind === 'agent' ? `<br><button type="button" class="linkish small need-admin" data-relay-upd="${r.id}">⬆ ${L('Bijwerken naar {v}', { v: esc(relayData.latest) })}</button>` : ''}
          ${r.outdated && r.kind === 'container' ? `<br><span class="warn-txt small">⬆ ${esc(L('nieuwe image: podman pull'))}</span>` : ''}
          ${r.update_status && r.kind === 'agent' ? `<br><span class="muted small">${esc(r.update_status)}</span>` : ''}</td>
        <td>${!r.paired ? `<span class="warn-txt">⏳ ${L('nog niet gekoppeld')}</span>` : r.down ? `<b class="danger-txt">🔴 ${L('weg')}</b>` : `<span class="good-txt">🟢 ${L('online')}</span>`}
          ${r.seen ? `<br><span class="muted">${agoSpan(r.seen)}</span>` : ''}</td>
        <td>${r.url ? `<code>${esc(r.url)}</code>` : `<span class="warn-txt">${L('nog geen adres')}</span>`}<br><span class="muted">📌 ${relayPinShort(r.pin)}${r.ips.length ? ' · ' + esc(r.ips.slice(0, 3).join(', ')) : ''}</span></td>
        <td>${r.allow.length ? esc(r.allow.join(', ')) : `<span class="muted">${L('nog geen: de relay laat niemand door')}</span>`}<br><span class="muted">${L('{n} agents erachter', { n: r.behind })}</span></td>
        <td class="muted">${r.st && r.st.req !== undefined ? `${L('{n} verzoeken', { n: r.st.req })} · ${L('{n} geweigerd', { n: r.st.deny || 0 })}${r.st.err ? ` · <span class="warn-txt">${L('{n} fout', { n: r.st.err })}</span>` : ''}${r.st.up ? `<br>→ ${esc(r.st.up)}` : ''}` : '–'}</td>
        <td class="nowrap need-admin"><button type="button" class="btn small-btn" data-relay-url="${r.id}">🌐 ${L('Adres')}</button>
          <button type="button" class="btn small-btn" data-relay-install="${r.id}">📦 ${L('Installeren')}</button>
          <button type="button" class="btn small-btn danger" data-relay-off="${r.id}">${L('Geen relay meer')}</button></td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted small">${L('Nog geen relays.')}</p>`}
    <div class="btn-row need-admin"><button type="button" class="btn primary small-btn" id="relay-new">＋ ${L('Relay toevoegen')}</button></div>

    <h3 class="set-sub">${L('Netwerken')}</h3>
    ${d.networks.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Netwerk')}</th><th>${L('Adresreeksen')}</th><th>${L('Via')}</th><th>${L('Servers')}</th><th></th></tr></thead><tbody>
      ${d.networks.map((n) => `<tr data-net="${n.id}"><td><b>${esc(n.name)}</b>${n.note ? `<br><span class="muted">${esc(n.note)}</span>` : ''}</td>
        <td><code>${esc(n.cidrs.join(', '))}</code></td>
        <td>${n.relay_id ? `🛰️ ${esc(relayName(n.relay_id))}${n.relay_fb_id ? `<br><span class="muted">${L('reserve: {n}', { n: esc(relayName(n.relay_fb_id)) })}</span>` : ''}` : `<span class="muted">${L('rechtstreeks')}</span>`}</td>
        <td>${d.hosts.filter((h) => !h.is_relay && h.net && h.net.id === n.id).length}</td>
        <td class="nowrap need-admin"><button type="button" class="btn small-btn" data-net-edit="${n.id}">✎ ${L('Bewerken')}</button>
          <button type="button" class="btn small-btn danger" data-net-del="${n.id}">${L('Verwijderen')}</button></td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted small">${L('Nog geen netwerken. Zonder netwerk praat elke agent rechtstreeks met RzSense, zoals altijd.')}</p>`}
    <div class="btn-row need-admin"><button type="button" class="btn primary small-btn" id="net-new">＋ ${L('Netwerk toevoegen')}</button></div>

    <h3 class="set-sub">${L('Servers')}</h3>
    ${d.hosts.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th class="need-admin"></th><th>${L('Server')}</th><th>${L('Netwerk')}</th><th>${L('Komt binnen')}</th><th>${L('Adres van RzSense')}</th></tr></thead><tbody>
      ${d.hosts.map((h) => `<tr data-rh="${h.id}"><td class="need-admin">${h.state === 'switch' ? `<input type="checkbox" data-rh-sel="${h.id}" checked aria-label="${esc(h.name)}">` : ''}</td>
        <td><b>${esc(h.name)}</b>${h.is_relay ? ' · 🛰️ ' + L('relay') : ''}<br><span class="muted">${esc(h.address || h.agent_ip || '')}</span></td>
        <td>${h.is_relay ? '<span class="muted">–</span>' : `<select data-rh-net="${h.id}" class="small">
          <option value="0">${h.net && h.net.auto ? esc(L('vanzelf ({n})', { n: h.net.name })) : esc(L('vanzelf (op IP-adres)'))}</option>
          ${d.networks.map((n) => `<option value="${n.id}" ${h.net_id === n.id ? 'selected' : ''}>${esc(n.name)}</option>`).join('')}</select>`}</td>
        <td>${h.via ? `🛰️ ${esc(relayName(h.via))}` : h.seen ? `<span class="muted">${L('rechtstreeks')}</span>` : '–'}</td>
        <td><code class="small">${esc(h.url || '–')}</code>${h.want && h.want !== h.url ? `<br>→ <code class="small">${esc(h.want)}</code>` : ''}
          <br><span class="${h.state === 'ok' ? 'muted' : h.state === 'switch' || h.state === 'pending' ? '' : 'warn-txt'}">${esc((RELAY_STATE[h.state] || (() => h.state))())}</span>
          ${h.status ? `<br><span class="muted">${esc(h.status)}</span>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted small">${L('Nog geen servers met de agent.')}</p>`}
    <div class="btn-row need-admin"><button type="button" class="btn primary small-btn" id="relay-apply" ${sw.length ? '' : 'disabled'}>↪ ${L('Adressen toepassen ({n})', { n: sw.length })}</button>
      <span class="muted small">${L('De agent test het nieuwe adres eerst; lukt dat niet, dan blijft het oude. Zijn rechtstreekse adres wordt het reserve-adres.')}</span></div>`;
  box.onclick = relayClick;
  box.onchange = async (e) => {
    const s = e.target.closest('[data-rh-net]');
    if (!s) return;
    try { await api('relay_host_net', { host_id: +s.dataset.rhNet, net_id: +s.value }); toast(L('Opgeslagen')); loadRelaySet(); } catch (ex) { toast(ex.message, 'err'); }
  };
}

async function relayClick(e) {
  const t = (sel) => e.target.closest(sel);
  if (t('#relay-new')) return relayNew();
  if (t('#net-new')) return relayNetDialog(null);
  if (t('[data-net-edit]')) return relayNetDialog(relayData.networks.find((n) => n.id === +t('[data-net-edit]').dataset.netEdit));
  if (t('[data-net-del]')) {
    const n = relayData.networks.find((x) => x.id === +t('[data-net-del]').dataset.netDel);
    if (!n || !confirm(L('Netwerk {n} verwijderen? De servers erin vallen dan weer vanzelf in een netwerk op hun IP-adres.', { n: n.name }))) return;
    try { await api('relay_net_delete', { id: n.id }); toast(L('Verwijderd')); loadRelaySet(); } catch (ex) { toast(ex.message, 'err'); }
    return;
  }
  if (t('[data-relay-url]')) return relayUrlDialog(relayData.relays.find((r) => r.id === +t('[data-relay-url]').dataset.relayUrl));
  if (t('[data-relay-install]')) return relayInstall(relayData.relays.find((r) => r.id === +t('[data-relay-install]').dataset.relayInstall));
  if (t('[data-relay-upd]')) {
    try { await api('agent_update', { id: +t('[data-relay-upd]').dataset.relayUpd }); toast(L('De relay wordt bijgewerkt bij zijn volgende rapport')); loadRelaySet(); } catch (ex) { toast(ex.message, 'err'); }
    return;
  }
  if (t('[data-relay-off]')) {
    const r = relayData.relays.find((x) => x.id === +t('[data-relay-off]').dataset.relayOff);
    if (!r || !confirm(L('{n} is dan geen relay meer. Netwerken die hem gebruiken gaan over op hun reserve-relay, of rechtstreeks. Doorgaan?', { n: r.name }))) return;
    try { await api('relay_disable', { id: r.id }); toast(L('Opgeslagen')); loadRelaySet(); } catch (ex) { toast(ex.message, 'err'); }
    return;
  }
  if (t('#relay-apply')) {
    const ids = $$('[data-rh-sel]:checked').map((c) => +c.dataset.rhSel);
    if (!ids.length) return toast(L('Vink eerst servers aan'), 'err');
    try { const r = await api('relay_apply', { ids }); toast(L('{n} agents stappen over bij hun volgende rapport', { n: r.n })); loadRelaySet(); } catch (ex) { toast(ex.message, 'err'); }
  }
}

function relayNew() {
  modal({
    title: '🛰️ ' + L('Relay toevoegen'),
    body: `<div style="padding:1rem 1.2rem">${xpHtml(L('Een relay is een kleine container in een ander netwerk. De agents daar praten met de relay, en de relay met RzSense.'),
      L('<b>Wat het doet:</b> de relay stuurt alleen agentverkeer door, en alleen uit de netwerken die je hier aan hem koppelt. De rapporten blijven versleuteld van de agent tot RzSense: de relay kan ze niet lezen. <b>Zo stel je het in:</b> geef de relay een naam, installeer de container met het commando dat je zo krijgt, zet daarna zijn adres (bijvoorbeeld https://192.168.50.10:8444) en maak een netwerk dat via deze relay gaat. <b>Goed om te weten:</b> de relay moet RzSense kunnen bereiken: via internet (SWAG), een VPN of een eigen route.'))}
      <label>${L('Naam')}<input name="name" required maxlength="100" placeholder="${esc(L('bijvoorbeeld Relay kantoor'))}"></label>
      <label>${L('Op welke server')} <span class="hint">${L('(losse agent op een server die je al bewaakt; leeg = eigen regel, bijvoorbeeld een container)')}</span>
        <select name="relay_of"><option value="0">${esc(L('geen (eigen regel)'))}</option>${relayData.hosts.filter((h) => !h.is_relay && !relayData.relays.some((x) => x.relay_of === h.id)).map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select></label></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      const r = await api('relay_create', { name: v.name, relay_of: +v.relay_of });
      await loadRelaySet();
      relayInstall(relayData.relays.find((x) => x.id === r.id), +v.relay_of ? 'native' : '');
    },
  });
}

function relayUrlDialog(r) {
  if (!r) return;
  const sug = r.ips.map((ip) => `https://${ip.includes(':') ? `[${ip}]` : ip}:${r.port}`);
  modal({
    title: '🌐 ' + L('Adres van {n}', { n: r.name }),
    body: `<div style="padding:1rem 1.2rem">${xpHtml(L('Het adres waarop de agents in dit netwerk de relay bereiken.'),
      L('<b>Wat het doet:</b> agents in een netwerk dat via deze relay gaat, krijgen dit adres als adres van RzSense (met het certificaat van de relay vastgezet). <b>Zo stel je het in:</b> https, het IP-adres of de naam van de relay in dat netwerk, en de poort (standaard 8444). <b>Goed om te weten:</b> de relay meldt zelf zijn adressen; kies er hieronder een.'))}
      <label>${L('Adres')}<input name="url" value="${esc(r.url || sug[0] || '')}" placeholder="https://192.168.50.10:8444" class="mono" list="relay-sug"></label>
      <datalist id="relay-sug">${sug.map((s) => `<option value="${esc(s)}">`).join('')}</datalist>
      <p class="muted small">${L('Poort van de relay: {p} (instelbaar in de container met RELAY_PORT).', { p: r.port })}</p></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      await api('relay_save', { id: r.id, url: v.url, port: r.port });
      toast(L('Opgeslagen'));
      loadRelaySet();
    },
  });
}

function relayNetDialog(n) {
  const rel = relayData.relays;
  const opt = (sel, none) => `<option value="0">${esc(none)}</option>${rel.map((r) => `<option value="${r.id}" ${sel === r.id ? 'selected' : ''}>${esc(r.name)}</option>`).join('')}`;
  modal({
    title: n ? '✎ ' + L('Netwerk {n}', { n: n.name }) : '＋ ' + L('Netwerk toevoegen'),
    body: `<div style="padding:1rem 1.2rem">${xpHtml(L('Een netwerk is een of meer adresreeksen. Servers met een adres daarin horen er vanzelf bij.'),
      L('<b>Wat het doet:</b> gaat het netwerk via een relay, dan rapporteren de agents erin via die relay, en mag de relay alleen verkeer uit deze adresreeksen doorsturen. <b>Zo stel je het in:</b> bijvoorbeeld <code>192.168.50.0/24</code>; meerdere met een komma. Kies de relay, en eventueel een reserve-relay. <b>Goed om te weten:</b> een server kun je ook zelf in een netwerk zetten (tabel Servers). Overstappen gaat pas als je op <i>Adressen toepassen</i> klikt.'))}
      <label>${L('Naam')}<input name="name" required maxlength="80" value="${esc(n ? n.name : '')}"></label>
      <label>${L('Adresreeksen')} <span class="hint">${L('(bijvoorbeeld 192.168.50.0/24, 10.20.0.0/16)')}</span><input name="cidrs" required class="mono" value="${esc(n ? n.cidrs.join(', ') : '')}"></label>
      <label>${L('Via')}<select name="relay_id">${opt(n ? n.relay_id : null, L('rechtstreeks (geen relay)'))}</select></label>
      <label>${L('Reserve-relay')} <span class="hint">${L('(als de eerste weg is)')}</span><select name="relay_fb_id">${opt(n ? n.relay_fb_id : null, L('geen'))}</select></label>
      <label>${L('Notitie')}<input name="note" maxlength="255" value="${esc(n ? n.note : '')}"></label></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      await api('relay_net_save', { id: n ? n.id : 0, name: v.name, cidrs: v.cidrs, relay_id: +v.relay_id, relay_fb_id: +v.relay_fb_id, note: v.note });
      toast(L('Opgeslagen'));
      loadRelaySet();
    },
  });
}

/** Installatiecommando's voor de relay-container (Podman met quadlet, Docker, compose), met een verse koppelcode */
async function relayInstall(r, first = '') {
  if (!r) return;
  let p;
  try { p = await api('agent_pair_code', { id: r.id }); } catch (ex) { toast(ex.message, 'err'); return; }
  const base = agentBase(null);
  const pin = p.pin && base.startsWith('https') ? p.pin : '';
  const env = `MONITOR_URL=${base}\nPAIR_CODE=${p.code}${pin ? `\nPIN=${pin}` : ''}\nRELAY_PORT=${r.port}\nTZ=${Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Amsterdam'}`;
  const raw = 'https://raw.githubusercontent.com/rembrand20/rzmon/main/relay';
  const podman = `sudo mkdir -p /etc/rzsense && sudo chmod 700 /etc/rzsense\nsudo tee /etc/rzsense/relay.env >/dev/null <<'EOF'\n${env}\nEOF\nsudo chmod 600 /etc/rzsense/relay.env\n`
    + `sudo curl -fsSL ${raw}/quadlet/rzsense-relay.container -o /etc/containers/systemd/rzsense-relay.container\n`
    + 'sudo systemctl daemon-reload && sudo systemctl start rzsense-relay';
  const docker = `docker run -d --name rzsense-relay --restart always --network host --cap-add NET_RAW --read-only --tmpfs /tmp \\\n  --security-opt no-new-privileges `
    + env.split('\n').map((l) => `-e '${l}'`).join(' ') + ` \\\n  -v rzsense-relay:/data ${RELAY_IMAGE}`;
  const compose = `# .env naast compose.yaml (${raw}/compose.yaml)\n${env}`;
  // 1.283.0: losse agent (rzmon-agent-relay): ondertekend installatiescript, eerst controleren (SHA-256)
  const curlPin = pin ? ` -k --pinnedpubkey 'sha256//${pin}'` : '';
  const native = `curl -fsSL${curlPin} '${base}/api.php?action=agent_relay_file&f=install${dlArg()}' -o rzmon-relay-install.sh`
    + ` && echo '${p.relay_setup_sha256}  rzmon-relay-install.sh' | sha256sum -c -`
    + ` && sudo bash rzmon-relay-install.sh --url '${base}' --pair '${p.code}' --agent-sha '${p.agent_sha256}'${pin ? ` --pin '${pin}'` : ''} --port ${r.port}`;
  const tab = first || (r.kind === 'container' ? 'podman' : 'native');
  modal({
    title: '📦 ' + L('{n} installeren', { n: r.name }),
    wide: true,
    body: `<div style="padding:1rem 1.2rem">${xpHtml(L('Installeer de relay op een machine in het andere netwerk: als losse agent of als container. Hij koppelt zich met de koppelcode.'),
      L('<b>Wat het doet:</b> de relay maakt zijn eigen certificaat, koppelt zich met RzSense en meldt zich hier. <b>Zo stel je het in:</b> kies hieronder de losse agent (rzmon-agent-relay, met systemd), Podman (aangeraden op Rocky), Docker of compose en voer het commando uit op die machine. Zet daarna het adres van de relay en maak een netwerk. <b>Goed om te weten:</b> de koppelcode werkt één keer en 15 minuten. De machine moet RzSense kunnen bereiken op het adres hieronder; anders zet je in <code>MONITOR_URL</code> het adres dat daar wél werkt (bijvoorbeeld via je VPN). Home Assistant en TrueNAS als app volgen in een volgende versie.'))}
      <p><span class="muted small">${L('Koppelcode')}</span> <b class="mono">${esc(p.code)}</b> <span class="muted small">· ${L('{m} minuten geldig', { m: p.minutes })}</span></p>
      <div class="seg" role="tablist">${[['native', '🐧 ' + L('Losse agent')], ['podman', 'Podman (quadlet)'], ['docker', 'Docker'], ['compose', 'Compose']]
        .map(([k, l]) => `<button type="button" class="seg-btn ${k === tab ? 'active' : ''}" data-rseg="${k}">${esc(l)}</button>`).join('')}</div>
      <div data-rpane="native" ${tab === 'native' ? '' : 'hidden'}>${copyBox('rl-native', native)}<p class="muted small">${L('Linux met systemd en Python 3.7+ (Rocky, Alma, Debian, Ubuntu, Proxmox). Draait als gebruiker rzrelay, niet als root; naast een gewone agent mag. Daarna: sudo rzmon-agent-relay-setup --status')}</p></div>
      <div data-rpane="podman" ${tab === 'podman' ? '' : 'hidden'}>${copyBox('rl-podman', podman)}</div>
      <div data-rpane="docker" hidden>${copyBox('rl-docker', docker)}</div>
      <div data-rpane="compose" hidden>${copyBox('rl-compose', compose)}<p class="muted small">${L('Dan: podman compose up -d (of docker compose up -d)')}</p></div>
      <p class="muted small">${L('Open in de firewall van die machine poort {p} voor de agents in dat netwerk.', { p: r.port })}${pin ? ' · 📌 ' + L('alleen het certificaat van deze RzSense') : ''}</p></div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => { $('#modal').close(); loadRelaySet(); } }],
  });
  const body = $('#modal-body');
  wireCopy(body);
  body.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rseg]');
    if (!b) return;
    $$('[data-rseg]', body).forEach((x) => x.classList.toggle('active', x === b));
    $$('[data-rpane]', body).forEach((x) => { x.hidden = x.dataset.rpane !== b.dataset.rseg; });
  }, { signal: modalSignal() });
}

/** 1.283.0: Agent Manager → Relay op deze server (losse agent rzmon-agent-relay, bij de server getoond) */
async function amRelay(h, body) {
  const box = $('#am-relay', body);
  if (!box) return;
  let d;
  try { d = await api('relay_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  relayData = d;
  const r = d.relays.find((x) => x.relay_of === +h.id);
  amSum('relay', r ? (r.down ? `<b class="danger-txt">${esc(L('weg'))}</b>` : esc(L('online · {n} agents erachter', { n: r.behind }))) : esc(L('geen')), !!(r && r.down));
  box.innerHTML = r ? `<p>${!r.paired ? `<span class="warn-txt">⏳ ${L('nog niet gekoppeld')}</span>` : r.down ? `<b class="danger-txt">🔴 ${L('weg')}</b>` : `<span class="good-txt">🟢 ${L('online')}</span>`}
      · ${r.url ? `<code>${esc(r.url)}</code>` : `<span class="warn-txt">${L('nog geen adres')}</span>`} · ${esc(L('{n} agents erachter', { n: r.behind }))}
      ${r.relay_ver ? ` · ${esc(r.relay_ver)}` : ''}</p>
      <p class="muted small">${r.allow.length ? esc(L('Netwerken: {n}', { n: r.allow.join(', ') })) : esc(L('nog geen: de relay laat niemand door'))}</p>
      <div class="btn-row need-admin">${r.outdated ? `<button type="button" class="btn small-btn" data-amr-upd="${r.id}">⬆ ${L('Bijwerken naar {v}', { v: esc(d.latest) })}</button>` : ''}
        <button type="button" class="btn small-btn" data-amr-inst="${r.id}">📦 ${L('Installeren')}</button>
        <button type="button" class="btn small-btn" data-goto-relays="1">🛰️ ${L('Netwerken en relays')} →</button></div>`
    : `<p class="muted small">${L('Op deze server draait geen relay. Een relay stuurt agents in een netwerk zonder verbinding met RzSense door, en draait hier als losse agent (rzmon-agent-relay) naast de gewone agent.')}</p>
      <div class="btn-row need-admin"><button type="button" class="btn small-btn primary" data-amr-new="1">🛰️ ${L('Relay op deze server installeren')}</button></div>`;
  box.onclick = async (e) => {
    if (e.target.closest('[data-goto-relays]')) { $('#modal').close(); return showTab('settings/relays'); }
    const u = e.target.closest('[data-amr-upd]');
    if (u) { try { await api('agent_update', { id: +u.dataset.amrUpd }); toast(L('De relay wordt bijgewerkt bij zijn volgende rapport')); amRelay(h, body); } catch (ex) { toast(ex.message, 'err'); } return; }
    const i = e.target.closest('[data-amr-inst]');
    if (i) return relayInstall(d.relays.find((x) => x.id === +i.dataset.amrInst), 'native');
    if (e.target.closest('[data-amr-new]')) {
      try {
        const n = await api('relay_create', { name: L('{s} relay', { s: h.name }), relay_of: +h.id });
        relayData = await api('relay_overview');
        relayInstall(relayData.relays.find((x) => x.id === n.id), 'native');
      } catch (ex) { toast(ex.message, 'err'); }
    }
  };
}
