// ---------------------------------------------------------------- 🧩 Onbekende programma's en 🔎 Jagen op sporen (1.229.0, agent 1.91.0)
// IDS → Programma's (wat er draait, toestaan/blokkeren), IDS → Jagen (sporen zoeken, ook op de servers, en bewaken),
// de kaart in de Agent Manager en Instellingen → IDS → Programma's / Jagen op sporen.
const APP_ST = {
  new: () => ['⚠', L('onbekend'), 'warn'], block: () => ['⛔', L('geblokkeerd'), 'bad'], ok: () => ['✔', L('toegestaan'), 'ok'],
  learn: () => ['🎓', L('geleerd'), 'muted'], pkg: () => ['📦', L('uit een pakket'), 'muted'],
};
const appChip = (s) => { const [ic, t, c] = (APP_ST[s] || (() => ['?', s, 'muted']))(); return `<span class="app-chip app-${c}">${ic} ${esc(t)}</span>`; };
const appVerdict = (v) => (v === 'malware' ? ` <b class="bad">☣ ${L('bekende malware')}</b>` : v === 'known_good' ? ` <span class="ok small">✔ ${L('bekend bestand (CIRCL)')}</span>` : '');
let appsOpt = { status: '', host: '', q: '' }, appsData = null;

async function loadApps() {
  const box = $('#apps-body');
  if (!box) return;
  try { appsData = await api('apps', null, appsOpt); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const d = appsData;
  const hs = $('#apps-host');
  if (hs && hs.options.length <= 1) hs.insertAdjacentHTML('beforeend', d.hosts.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join(''));
  const c = d.counts;
  $('#it-n-apps').textContent = c.new + c.block || '';
  const learning = d.hosts.filter((h) => h.on && h.learning && h.until);
  const noAgent = d.hosts.filter((h) => h.on && !h.agent_ok).length;
  box.innerHTML = `${!d.on ? `<div class="callout small">${L('Onbekende programma\'s staat uit (Instellingen → IDS → Programma\'s).')}</div>` : ''}
    <div class="app-sum">${['new', 'block', 'ok', 'learn', 'pkg'].map((s) => `<button type="button" class="app-sum-b${appsOpt.status === s ? ' active' : ''}" data-app-st="${s}">${appChip(s)} <b>${c[s] || 0}</b></button>`).join('')}
      <button type="button" class="app-sum-b${appsOpt.status === '' ? ' active' : ''}" data-app-st="">${L('Zonder pakket')}</button><button type="button" class="app-sum-b${appsOpt.status === 'all' ? ' active' : ''}" data-app-st="all">${L('Alles')}</button></div>
    ${learning.length ? `<p class="muted small">🎓 ${L('Leert nog (wat nu draait wordt als normaal gezien):')} ${learning.map((h) => `${esc(h.name)} ${L('tot {d}', { d: new Date(h.until.replace(' ', 'T')).toLocaleDateString(LOCALE) })}`).join(' · ')}</p>` : ''}
    ${noAgent ? `<p class="muted small">${L('{n} servers hebben agent 1.91.0 of nieuwer nodig.', { n: noAgent })}</p>` : ''}
    ${d.rows.length ? `<div class="table-scroll"><table class="vuln-table small app-table"><thead><tr><th>${L('Status')}</th><th>${L('Programma')}</th><th>${L('Server')}</th><th>${L('Gebruiker')}</th><th>${L('Laatst gezien')}</th><th></th></tr></thead><tbody>
      ${d.rows.map((r, i) => `<tr><td>${appChip(r.status)}</td><td><code>${esc(r.path)}</code>${appVerdict(r.verdict)}<br><span class="muted">${r.pkg ? '📦 ' + esc(r.pkg) + ' · ' : ''}sha256 <code title="${esc(r.sha)}">${esc(r.sha.slice(0, 16))}…</code></span>${r.cmd ? `<br><code class="edr-cmd">${esc(r.cmd.slice(0, 160))}</code>` : ''}</td>
        <td>${esc(r.host)}</td><td>${esc(r.user || '–')}</td><td>${agoSpan(r.last)}</td>
        <td class="nowrap need-admin">${r.status !== 'ok' ? `<button type="button" class="btn small-btn" data-app-do="allow" data-i="${i}">✔ ${L('Toestaan')}</button>` : ''} ${r.status !== 'block' ? `<button type="button" class="btn small-btn danger" data-app-do="block" data-i="${i}">⛔</button>` : ''}</td></tr>`).join('')}
      </tbody></table></div>` : `<p class="muted">${L('Niets in deze lijst.')}</p>`}
    <h3 class="set-sub">${L('Wat je besliste')}</h3>
    ${d.rules.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Keuze')}</th><th>${L('Waarop')}</th><th>${L('Server')}</th><th>${L('Notitie')}</th><th>${L('Door')}</th><th></th></tr></thead><tbody>
      ${d.rules.map((r) => `<tr><td>${r.action === 'block' ? appChip('block') : appChip('ok')}</td><td>${r.sha ? `sha256 <code title="${esc(r.sha)}">${esc(r.sha.slice(0, 16))}…</code>` : `<code>${esc(r.path)}</code>${r.path.endsWith('/') ? ' ' + L('(alles in deze map)') : ''}`}</td>
        <td>${r.host_id ? esc(r.host || '?') : L('alle servers')}</td><td>${esc(r.note)}</td><td class="muted">${esc(r.by || '')} · ${agoSpan(r.at)}</td>
        <td class="need-admin"><button type="button" class="btn small-btn" data-app-rule-del="${r.id}" title="${esc(L('Keuze weghalen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
      : `<p class="muted small">${L('Nog niets toegestaan of geblokkeerd.')}</p>`}`;
  box.onclick = async (e) => {
    const st = e.target.closest('[data-app-st]');
    if (st) { appsOpt.status = st.dataset.appSt; return loadApps(); }
    const del = e.target.closest('[data-app-rule-del]');
    if (del) {
      try { await api('apps_rule_delete', { id: +del.dataset.appRuleDel }); toast(L('Keuze weggehaald')); loadApps(); } catch (ex) { toast(ex.message, 'err'); }
      return;
    }
    const b = e.target.closest('[data-app-do]');
    if (b) appDecide(b.dataset.appDo, d.rows[+b.dataset.i], loadApps);
  };
}

/** Toestaan of blokkeren: op vingerafdruk, dit pad of de map; voor deze server of alle servers */
function appDecide(action, r, done) {
  const dir = r.path.replace(/[^/]+$/, '');
  modal({
    title: action === 'block' ? L('Programma blokkeren') : L('Programma toestaan'),
    body: `<p><code>${esc(r.path)}</code><br><span class="muted small">sha256 ${esc(r.sha)}</span></p>
      <label>${L('Waarop')}<select name="on"><option value="sha">${L('Deze vingerafdruk (precies dit programma)')}</option><option value="path">${L('Dit pad (ook na een update)')}</option>
        ${action === 'allow' && dir.length > 1 ? `<option value="dir">${L('Alles in {d}', { d: esc(dir) })}</option>` : ''}</select></label>
      <label>${L('Voor')}<select name="scope"><option value="host">${L('Alleen {h}', { h: esc(r.host || L('deze server')) })}</option><option value="all">${L('Alle servers')}</option></select></label>
      <label>${L('Notitie')} <span class="hint">${L('(waarom, voor later)')}</span><input name="note" maxlength="200"></label>
      ${action === 'block' ? `<p class="muted small">${L('Draait het, dan krijg je een critical incident. De agent stopt het alleen als "Geblokkeerde programma\'s stoppen" aan staat bij die server (Agent Manager).')}</p>` : ''}`,
    buttons: [{ label: action === 'block' ? '⛔ ' + L('Blokkeren') : '✔ ' + L('Toestaan'), cls: action === 'block' ? 'danger' : 'primary', submit: true }],
    onSubmit: async (f) => {
      const on = f.on;
      await api('apps_decide', { action, sha: on === 'sha' ? r.sha : '', path: on === 'path' ? r.path : on === 'dir' ? dir : '', host_id: f.scope === 'all' ? 0 : r.host_id, note: f.note });
      $('#modal').close();
      toast(action === 'block' ? L('Geblokkeerd; de agent krijgt het binnen een minuut') : L('Toegestaan'));
      done();
    },
  });
}

function initApps() {
  const hs = $('#apps-host'), q = $('#apps-q');
  if (!hs || hs.dataset.wired) return;
  hs.dataset.wired = '1';
  hs.onchange = () => { appsOpt.host = hs.value; loadApps(); };
  let tm = null;
  q.oninput = () => { clearTimeout(tm); tm = setTimeout(() => { appsOpt.q = q.value.trim(); loadApps(); }, 300); };
}

// ---------------------------------------------------------------- 🔎 Jagen
const IOC_KIND = { ip: () => 'IP', domain: () => L('domein'), sha: () => 'sha256', path: () => L('pad') };
const IOC_SRC = { ids: () => 'IDS', ssh: () => 'SSH', block: () => L('blokkade'), login: () => L('inloggen RzSense'), web: () => L('websites'), apps: () => L('programma\'s'),
  ti: () => L('dreigingsinfo'), edr: () => 'EDR', logs: () => L('logs') };
let huntCur = null, huntPoll = null;
const nTreffer = (n) => (+n === 1 ? L('1 treffer') : L('{n} treffers', { n }));

async function loadHunt() {
  const box = $('#hunt-side');
  if (!box) return;
  let d;
  try { d = await api('ioc_hunts'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  $('#hunt-off').hidden = d.on;
  box.innerHTML = `<h3 class="set-sub">👁 ${L('Bewaakte sporen')} <span class="muted small">(${d.watch.length})</span></h3>
    <p class="muted small">${L('Elke 10 minuten vergeleken met wat er nieuw binnenkomt; een treffer is een critical incident.')}</p>
    ${d.watch.length ? `<div class="table-scroll hunt-watch"><table class="vuln-table small"><tbody>${d.watch.map((w) => `<tr><td class="need-admin"><input type="checkbox" data-hw="${w.id}" aria-label="${esc(L('Kiezen'))}"></td>
      <td><span class="muted">${esc((IOC_KIND[w.kind] || (() => w.kind))())}</span> <code>${esc(w.value)}</code>${w.note ? `<br><span class="muted">${esc(w.note)}</span>` : ''}</td>
      <td class="small">${w.hits ? `<b class="bad">${nTreffer(w.hits)}</b><br>${agoSpan(w.last_hit)}` : `<span class="muted">${L('nog niet gezien')}</span>`}</td></tr>`).join('')}</tbody></table></div>
      <div class="btn-row need-admin"><button type="button" class="btn small-btn" id="hunt-watch-del">✕ ${L('Gekozen weghalen')}</button></div>` : `<p class="muted small">${L('Nog geen. Plak sporen links en kies "Bewaken".')}</p>`}
    <h3 class="set-sub">🕘 ${L('Eerdere jachten')}</h3>
    ${d.hunts.length ? `<ul class="hunt-hist">${d.hunts.map((h) => `<li><button type="button" class="linkish" data-hunt="${h.id}">${agoSpan(h.at)} · ${L('{n} sporen', { n: h.n })} · ${h.hits ? `<b class="bad">${nTreffer(h.hits)}</b>` : L('niets gevonden')}</button>
      <br><span class="muted small">${esc(h.iocs.map((i) => i.value).join(', ').slice(0, 90))}${h.by ? ' · ' + esc(h.by) : ''}</span></li>`).join('')}</ul>` : `<p class="muted small">${L('Nog geen.')}</p>`}`;
  box.onclick = async (e) => {
    const h = e.target.closest('[data-hunt]');
    if (h) { try { huntShow(await api('ioc_get', null, { id: +h.dataset.hunt })); } catch (ex) { toast(ex.message, 'err'); } return; }
    if (e.target.closest('#hunt-watch-del')) {
      const ids = $$('[data-hw]', box).filter((x) => x.checked).map((x) => +x.dataset.hw);
      if (!ids.length) return toast(L('Kies eerst een spoor'), 'err');
      try { await api('ioc_watch_delete', { ids }); toast(L('Weggehaald')); loadHunt(); } catch (ex) { toast(ex.message, 'err'); }
    }
  };
}

function huntShow(h) {
  huntCur = h;
  const box = $('#hunt-result');
  const r = h.result || { iocs: [] };
  const ag = h.agents;
  const agHits = ag ? Object.values(ag.done || {}).flatMap((x) => (x.hits || []).map((y) => ({ ...y, host: x.host }))) : [];
  box.innerHTML = `<div class="hunt-head"><b>${L('Jacht van {t}', { t: esc(new Date(h.at.replace(' ', 'T')).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' })) })}</b>
      · ${L('{n} sporen', { n: r.iocs.length })} · ${L('laatste {d} dagen', { d: h.days })} · ${h.hits ? `<b class="bad">${nTreffer(h.hits)}</b>` : `<span class="ok">✔ ${L('niets gevonden')}</span>`}
      ${r.partial ? `<span class="muted small"> · ${L('niet alles doorzocht (te veel): kies minder dagen')}</span>` : ''}</div>
    ${h.skipped && h.skipped.length ? `<p class="muted small">${L('Overgeslagen (geen spoor):')} ${esc(h.skipped.slice(0, 10).join(', '))}</p>` : ''}
    ${ag ? `<div class="callout small">🖥 ${L('Op de servers: {d} van {a} klaar', { d: Object.keys(ag.done || {}).length, a: ag.asked })}${ag.waiting ? ' · ⏳ ' + L('{n} wachten nog (de agent zoekt bij zijn volgende ronde, hooguit een paar minuten)', { n: ag.waiting }) : ''}
      ${Object.values(ag.done || {}).map((x) => `<br>${esc(x.host)}: ${x.hits.length ? `<b class="bad">${nTreffer(x.hits.length)}</b>` : L('niets')} <span class="muted">(${L('{n} bestanden in {s} s', { n: x.files, s: x.secs })})</span>`).join('')}</div>` : ''}
    ${agHits.length ? `<div class="hunt-ioc hunt-hit"><b>🖥 ${L('Gevonden op de servers')}</b><ul>${agHits.map((x) => `<li><b>${esc(x.host)}</b> · <code>${esc(x.p)}</code> <span class="muted">(${esc(x.t === 'sha' ? 'sha256 ' + x.v.slice(0, 16) + '…' : L('pad bestaat'))})</span></li>`).join('')}</ul></div>` : ''}
    ${r.iocs.map((i) => `<div class="hunt-ioc${i.n ? ' hunt-hit' : ''}"><div><span class="muted">${esc((IOC_KIND[i.kind] || (() => i.kind))())}</span> <code>${esc(i.value)}</code>
        ${i.n ? `<b class="bad">${nTreffer(i.n)}</b>` : `<span class="muted small">${L('niet gezien')}</span>`}
        ${i.ti ? ` <span class="bad small">☣ ${L('bekend bij {s}', { s: esc(i.ti.src || '') })}${i.ti.info ? ': ' + esc(i.ti.info) : ''}</span>` : ''}</div>
      ${i.n ? `<table class="vuln-table small"><tbody>${i.hits.map((x) => `<tr><td class="nowrap">${esc((IOC_SRC[x.src] || (() => x.src))())}</td><td>${esc(x.host)}</td><td class="nowrap">${x.at ? agoSpan(x.at) : ''}</td><td>${esc(x.text)}</td></tr>`).join('')}</tbody></table>` : ''}</div>`).join('')}`;
  clearTimeout(huntPoll);
  if (ag && ag.waiting && idsTab === 'hunt') {
    const id = h.id;
    huntPoll = setTimeout(async () => {
      if (idsTab !== 'hunt' || !huntCur || huntCur.id !== id) return;
      try { huntShow({ ...(await api('ioc_get', null, { id })), skipped: h.skipped }); } catch { /* volgende keer */ }
    }, 8000);
  }
}

function initHunt() {
  const go = $('#hunt-go');
  if (!go || go.dataset.wired) return;
  go.dataset.wired = '1';
  go.onclick = async () => {
    const text = $('#hunt-text').value;
    if (!text.trim()) return toast(L('Plak eerst sporen (IP, domein, URL, sha256 of pad)'), 'err');
    go.disabled = true;
    $('#hunt-result').innerHTML = `<p class="muted">⏳ ${L('Zoeken…')}</p>`;
    try { huntShow(await api('ioc_hunt', { text, days: +$('#hunt-days').value, agents: $('#hunt-agents').checked })); loadHunt(); }
    catch (ex) { $('#hunt-result').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
    go.disabled = false;
  };
  $('#hunt-watch').onclick = async () => {
    const text = $('#hunt-text').value;
    if (!text.trim()) return toast(L('Plak eerst sporen (IP, domein, URL, sha256 of pad)'), 'err');
    const note = prompt(L('Notitie bij deze sporen (bv. waar ze vandaan komen):'), '') ?? null;
    if (note === null) return;
    try {
      const r = await api('ioc_watch_add', { text, note });
      toast(L('{n} sporen bewaakt', { n: r.added }) + (r.skipped.length ? ' · ' + L('{n} overgeslagen', { n: r.skipped.length }) : ''));
      loadHunt();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- 🧩 Agent Manager: programma's per server
async function amApps(h, body) {
  const box = $('#am-apps', body);
  if (!box) return;
  let d;
  try { d = await api('apps_info', null, { host_id: h.id }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const c = d.counts;
  const until = d.since ? new Date(new Date(d.since.replace(' ', 'T')).getTime() + d.learn * 86400000) : null;
  box.innerHTML = `${!d.on ? `<div class="callout small">${L('Onbekende programma\'s staat uit voor alle servers (Instellingen → IDS → Programma\'s).')}</div>` : ''}
    ${!d.agent_ok ? `<div class="callout small">${L('Agent 1.91.0 of nieuwer nodig (en als root); werk de agent bij.')}</div>` : ''}
    ${amRow('🧩 ' + L('Programma\'s bijhouden'), L('wat er draait, met vingerafdruk en pakket; na de leerperiode valt een onbekend programma op'), amSw('ap-on', d.host_on))}
    ${amRow('⛔ ' + L('Geblokkeerde programma\'s stoppen'), L('de agent stopt een programma dat je blokkeerde zodra het draait. Aanzetten vraagt je wachtwoord.'), amSw('ap-kill', d.kill))}
    <p class="small">${d.since ? (d.learning && until ? `🎓 ${L('Leert tot {d}: wat nu draait geldt als normaal.', { d: until.toLocaleDateString(LOCALE) })}` : `✔ ${L('Leerperiode voorbij: nieuwe programma\'s zonder pakket vallen op.')}`) : `<span class="muted">${L('Wacht op het eerste rapport.')}</span>`}
      ${d.last ? ` · ${L('{n} programma\'s draaien nu', { n: d.last.n })}` : ''}</p>
    <div class="app-sum">${['new', 'block', 'ok', 'learn', 'pkg'].map((s) => `<span class="app-sum-b">${appChip(s)} <b>${c[s] || 0}</b></span>`).join('')}</div>
    ${d.rows.length ? `<div class="table-scroll"><table class="vuln-table small"><tbody>${d.rows.slice(0, 30).map((r, i) => `<tr><td>${appChip(r.status)}</td><td><code>${esc(r.path)}</code><br><span class="muted">${esc(r.user || '')} · ${agoSpan(r.last)}</span></td>
      <td class="nowrap">${r.status !== 'ok' ? `<button type="button" class="btn small-btn" data-ap-do="allow" data-i="${i}">✔</button>` : ''} ${r.status !== 'block' ? `<button type="button" class="btn small-btn danger" data-ap-do="block" data-i="${i}">⛔</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
    <div class="btn-row"><button type="button" class="btn primary small-btn" id="ap-save">${L('Opslaan')}</button> <a class="btn small-btn" href="#ids/apps" data-goto="ids/apps">${L('Alle programma\'s')} →</a></div>`;
  box.onclick = (e) => {
    const b = e.target.closest('[data-ap-do]');
    if (b) appDecide(b.dataset.apDo, { ...d.rows[+b.dataset.i], host_id: h.id, host: h.name }, () => agentInstructions(findHost(h.id)));
  };
  const send = async (data, reopen) => {
    await api('apps_host_save', data);
    toast(L('Opgeslagen; de agent pakt het binnen een minuut op'));
    if (reopen) agentInstructions(findHost(h.id)); else amApps(h, body);
  };
  $('#ap-save', box).onclick = () => {
    const data = { host_id: h.id, on: $('#ap-on', box).checked, kill: $('#ap-kill', box).checked };
    if (data.kill && !d.kill) {
      return modal({ title: L('Geblokkeerde programma\'s stoppen'), body: `<p>${L('De agent stopt een programma dat je blokkeerde zodra het draait (alleen dat proces, op PID). Bevestig met je wachtwoord.')}</p>${confirmField('STOPPEN')}`,
        buttons: [{ label: L('Bevestigen'), cls: 'primary', submit: true }], onSubmit: (f) => send({ ...data, confirm: f.confirm }, true) });
    }
    send(data, false).catch((ex) => toast(ex.message, 'err'));
  };
}

// ---------------------------------------------------------------- Instellingen → IDS → Programma's
async function loadAppsSet() {
  const box = $('#apps-hosts');
  if (!box) return;
  let d;
  try { d = await api('apps', null, { status: 'new' }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  // 1.236.0: alleen een samenvatting; per server staat het op IDS → Programma's en in de Agent Manager
  const on = d.hosts.filter((h) => h.on).length, learn = d.hosts.filter((h) => h.on && h.learning).length;
  box.innerHTML = d.hosts.length ? `<p class="small">${L('Bijgehouden op {a} van {n} servers', { a: on, n: d.hosts.length })}${learn ? ' · 🎓 ' + L('{n} nog aan het leren', { n: learn }) : ''}
    · ${L('{n} onbekend', { n: d.counts.new })} <a class="btn small-btn" href="#ids/apps" data-goto="ids/apps">${L('Per server bekijken en instellen')} →</a></p>`
    : `<p class="muted">${L('Nog geen servers met de agent.')}</p>`;
}
