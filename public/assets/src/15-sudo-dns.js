// ---------------------------------------------------------------- 🔑 Tijdelijk sudo en 🚫 AdGuard-blokkadelijst (1.231.0, agent 1.93.0)
const SUDO_ST = { pending: () => '⏳ ' + L('wacht op de agent'), active: () => '🔑 ' + L('actief'), expired: () => L('verlopen'), revoked: () => L('ingetrokken'),
  replaced: () => L('vervangen'), failed: () => '⚠ ' + L('mislukt') };
const sudoLeft = (s) => (s >= 3600 ? L('nog {h} u {m} min', { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) }) : L('nog {m} min', { m: Math.max(1, Math.ceil(s / 60)) }));

function sudoRows(grants, withHost) {
  return grants.length ? `<div class="table-scroll"><table class="vuln-table small"><tbody>${grants.map((g) => `<tr>
    ${withHost ? `<td><b>${esc(g.host)}</b></td>` : ''}<td><code>${esc(g.user)}</code></td>
    <td>${esc((SUDO_ST[g.state] || (() => g.state))())}${g.left && ['pending', 'active'].includes(g.state) ? ` · <b>${esc(sudoLeft(g.left))}</b>` : ''}${g.detail ? `<br><span class="muted">${esc(g.detail)}</span>` : ''}</td>
    <td class="muted">${esc(g.reason)}<br>${esc(g.by || '')} · ${agoSpan(g.at)}${g.ended_by ? ' · ' + L('ingetrokken door {u}', { u: esc(g.ended_by) }) : ''}</td>
    <td class="need-admin">${['pending', 'active'].includes(g.state) && g.left ? `<button type="button" class="btn small-btn danger" data-sudo-revoke="${g.id}">${L('Intrekken')}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Nog geen.')}</p>`;
}

async function sudoRevoke(id, done) {
  try { await api('sudo_revoke', { id }); toast(L('Ingetrokken; de agent haalt het binnen een minuut weg')); done(); } catch (ex) { toast(ex.message, 'err'); }
}

async function amSudo(h, body) {
  const box = $('#am-sudo', body);
  if (!box) return;
  let d;
  try { d = await api('sudo_list', null, { host_id: h.id }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const active = d.grants.filter((g) => ['pending', 'active'].includes(g.state) && g.left);
  box.innerHTML = `${!d.on ? `<div class="callout small">${L('Tijdelijk sudo staat uit (Instellingen → Beveiliging → Tijdelijk sudo).')}</div>` : ''}
    ${!d.agent_ok ? `<div class="callout small">${L('Agent 1.93.0 of nieuwer nodig (op Linux, als root, met visudo).')}</div>` : ''}
    ${active.length ? `<p class="small"><b>${L('Nu actief')}</b></p>${sudoRows(active, false)}` : `<p class="muted small">${L('Nu heeft niemand tijdelijk sudo op deze server.')}</p>`}
    <div class="btn-row"><button type="button" class="btn primary small-btn" id="sudo-new" ${!d.on || !d.agent_ok ? 'disabled' : ''}>🔑 ${L('Tijdelijk sudo geven')}</button></div>
    ${d.grants.length > active.length ? `<details class="small"><summary>${L('Eerder ({n})', { n: d.grants.length - active.length })}</summary>${sudoRows(d.grants.filter((g) => !active.includes(g)), false)}</details>` : ''}`;
  box.onclick = (e) => {
    const r = e.target.closest('[data-sudo-revoke]');
    if (r) sudoRevoke(+r.dataset.sudoRevoke, () => amSudo(h, body));
  };
  const nb = $('#sudo-new', box);
  if (nb) nb.onclick = () => modal({
    title: L('Tijdelijk sudo op {name}', { name: h.name }),
    body: `<div style="padding:1rem 1.2rem">${xpHtml(L('Een gebruiker krijgt even beheerrechten; daarna haalt de agent ze vanzelf weer weg.'), L('<b>Wat het doet:</b> de agent geeft de gebruiker sudo voor de tijd die je kiest en neemt het daarna terug, ook als niemand eraan denkt. <b>Zo stel je het in:</b> kies de gebruiker en zo kort mogelijk; schrijf erbij waarvoor. <b>Goed om te weten:</b> het staat in het Logboek, met wie het gaf en wanneer het afliep.'))}
      <label>${L('Gebruiker')}<input name="user" list="sudo-users" required pattern="[a-z_][a-z0-9_-]{0,31}" autocomplete="off" class="mono"></label>
      <datalist id="sudo-users">${d.users.map((u) => `<option value="${esc(u)}">`).join('')}</datalist>
      <label>${L('Hoe lang')}<select name="minutes">${d.durations.map((m) => `<option value="${m}" ${m === 30 ? 'selected' : ''}>${m < 60 ? L('{n} minuten', { n: m }) : L('{n} uur', { n: m / 60 })}</option>`).join('')}</select></label>
      <label>${L('Waarom')} <span class="hint">${L('(komt in het logboek)')}</span><input name="reason" required minlength="3" maxlength="200"></label>
      <p class="muted small">${L('De agent zet een eigen bestand in /etc/sudoers.d (gecontroleerd met visudo) en haalt het daarna vanzelf weg. Root kan niet.')}</p>
      ${confirmField('SUDO')}
      <label>${L('2FA-code')} <span class="hint">${L('(als je tweestapsverificatie hebt)')}</span><input name="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="8" class="mono"></label></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🔑 ' + L('Geven'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      await api('sudo_grant', { host_id: h.id, user: v.user, minutes: +v.minutes, reason: v.reason, confirm: v.confirm, totp: v.totp });
      toast(L('Gegeven; de agent zet het binnen een minuut klaar'));
      agentInstructions(findHost(h.id));
    },
  });
}

async function loadSudoSet() {
  const box = $('#sudo-list');
  if (!box) return;
  let d;
  try { d = await api('sudo_list'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  box.innerHTML = sudoRows(d.grants.slice(0, 50), true) + `<p class="muted small">${L('Geven doe je per server in de Agent Manager (kaart Tijdelijk sudo).')}</p>`;
  box.onclick = (e) => { const r = e.target.closest('[data-sudo-revoke]'); if (r) sudoRevoke(+r.dataset.sudoRevoke, loadSudoSet); };
}

// ---------------------------------------------------------------- 🚫 AdGuard-blokkadelijst
async function loadDnsbl() {
  const box = $('#dnsbl-box');
  if (!box || !isAdmin()) return;
  let d;
  try { d = await api('dnsbl_status'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const per = d.per || {};
  box.innerHTML = `<p class="small">${L('{n} domeinen', { n: d.n })} <span class="muted">(${['urlhaus', 'threatfox', 'ioc', 'own'].filter((k) => per[k]).map((k) => `${esc({ urlhaus: 'URLhaus', threatfox: 'ThreatFox', ioc: L('bewaakt'), own: L('eigen') }[k])} ${per[k]}`).join(' · ') || L('nog leeg')})</span>
      ${d.fetched ? ` · ${L('laatst opgehaald')} ${agoSpan(d.fetched)} ${L('door {ip}', { ip: esc(d.fetched_by || '?') })}` : ` · <span class="muted">${L('nog niet opgehaald')}</span>`}</p>
    ${d.on ? `<label>${L('Geheime URL voor AdGuard')} <span class="hint">${L('(alleen vanaf je lokale netwerk)')}</span></label>${copyBox('dnsbl-url', d.url)}
    <div class="btn-row">${d.adguard ? `<button type="button" class="btn small-btn primary" id="dnsbl-add">➕ ${L('Toevoegen aan AdGuard')}</button>` : `<span class="muted small">${L('Koppel AdGuard (Koppelingen → AdGuard) om hem met één klik toe te voegen.')}</span>`}
      <button type="button" class="btn small-btn" id="dnsbl-new">🔄 ${L('Nieuwe geheime URL')}</button></div>` : `<p class="muted small">${L('Zet de lijst aan om de URL te zien.')}</p>`}
    <div id="dnsbl-res"></div>`;
  wireCopy(box);
  if ($('#dnsbl-add', box)) $('#dnsbl-add', box).onclick = async () => {
    try {
      const r = await api('dnsbl_adguard_add', { url: d.url });
      $('#dnsbl-res', box).innerHTML = r.results.map((x) => `<p class="small ${x.ok ? 'ok' : 'bad'}">${x.ok ? '✔' : '✘'} ${esc(x.host)}: ${esc(x.msg)}</p>`).join('');
    } catch (ex) { toast(ex.message, 'err'); }
  };
  if ($('#dnsbl-new', box)) $('#dnsbl-new', box).onclick = () => modal({
    title: L('Nieuwe geheime URL'),
    body: `<div style="padding:1rem 1.2rem"><p>${L('De oude URL werkt daarna niet meer: zet de nieuwe in AdGuard (of klik daarna op Toevoegen aan AdGuard en haal de oude daar weg).')}</p>${confirmField('VERNIEUWEN')}</div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Vernieuwen'), cls: 'primary', submit: true }],
    onSubmit: async (v) => { await api('dnsbl_token_new', { confirm: v.confirm }); $('#modal').close(); loadDnsbl(); },
  });
}
