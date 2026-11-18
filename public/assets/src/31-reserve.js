// ---------------------------------------------------------------- 🛟 Reserve-RzSense (1.286.0)
// Instellingen → Systeem → Reserve-RzSense. Op de hoofdserver: koppelcode en de toestand van de reserve. Op een nieuwe
// RzSense: koppelen als reserve. Op de reserve: wanneer de laatste kopieën binnenkwamen.
// 1.287.0: het adres van de reserve voor de agents, en wanneer de reserve het werk overnam.

const rsWhen = (dt) => (dt ? `${esc(fmtDateTime(dt))} <span class="muted">(${esc(ago(dt))})</span>` : `<span class="muted">${L('nog niet')}</span>`);
const rsRow = (label, val) => `<div class="acct-field"><span class="acct-lbl">${label}</span><span class="acct-ctl">${val}</span></div>`;
/** De perioden waarin de reserve het werk deed (1.287.0) */
const rsPeriods = (list) => (list && list.length ? rsRow(L('Overgenomen'), list.slice(-5).reverse().map((x) => `${esc(fmtDateTime(x.from))} – ${esc(fmtDateTime(x.to))}${x.sent ? ` <span class="muted">(${L('geschiedenis terug: {n} rijen', { n: x.rows || 0 })})</span>` : ` <span class="muted">(${L('geschiedenis nog hier')})</span>`}`).join('<br>')) : '');
/** 1.288.0: geschiedenis van een overname bij de hoofdserver: binnen en ingelezen */
const rsHistIn = (list) => (list && list.length ? rsRow(L('Geschiedenis van de reserve'), list.slice(-3).reverse().map((x) => `${esc(fmtDateTime(x.from))} – ${esc(fmtDateTime(x.to))}: ${x.finished
  ? L('ingelezen, {a} rijen toegevoegd', { a: x.added }) : x.complete ? L('inlezen: deel {i} van {n} ({a} rijen toegevoegd)', { i: Math.min(x.pos[0], x.parts), n: x.parts, a: x.added }) : L('komt binnen ({n} delen)', { n: x.parts })}`).join('<br>')) : '');

async function loadReserveSet() {
  const box = $('#reserve-body');
  if (!box) return;
  let d;
  try { d = await api('reserve_overview'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  box.innerHTML = d.role === 'primary' ? rsPrimary(d) : d.role === 'reserve' ? rsReserve(d) : rsNone(d);
  rsEvents(box, d);
}

/** Nog niet gekoppeld: een code maken (hoofdserver) of koppelen als reserve */
function rsNone(d) {
  return `<div class="rs-grid">
    <div><h3 class="set-sub">${L('Dit is de hoofdserver')}</h3>
      <p class="muted small">${L('Maak een koppelcode en vul die in op de reserve. De code is 1 uur geldig.')}</p>
      ${d.code_until ? `<p class="small">${L('Er is een code geldig tot {t}.', { t: esc(fmtDateTime(d.code_until)) })}</p>` : ''}
      <button type="button" class="btn primary" id="rs-code">🔑 ${L('Koppelcode maken')}</button></div>
    <div><h3 class="set-sub">${L('Dit wordt de reserve')}</h3>
      <p class="muted small">${L('Alleen op een <b>nieuwe</b> RzSense: na het koppelen wordt deze database elke 15 minuten overschreven met die van de hoofdserver. Daarna log je hier in met je account van de hoofdserver.')}</p>
      <label>${L('Adres van de hoofdserver')}<input id="rs-url" type="url" placeholder="https://rzsense.voorbeeld.nl" spellcheck="false"></label>
      <label>${L('Koppelcode')}<input id="rs-pcode" placeholder="ABCDE-FGHJK-LMNPQ-RSTUV" spellcheck="false" autocomplete="off"></label>
      <button type="button" class="btn" id="rs-pair">🛟 ${L('Koppelen als reserve')}</button></div></div>`;
}

function rsPrimary(d) {
  const st = d.status || {};
  const dl = st.dl && st.dl.size ? L('haalt nu de {k} op: {p}%', { k: st.dl.kind === 'full' ? L('volledige kopie') : L('kleine kopie'), p: Math.floor((st.dl.off / st.dl.size) * 100) }) : '';
  const late = !d.seen || Date.now() - new Date(d.seen.replace(' ', 'T')).getTime() > 5 * 60000;
  const ag = d.agents || { ready: 0, old: 0 };
  return `${st.state === 'active' ? `<p class="warn-txt"><b>🛟 ${L('De reserve doet nu het werk (sinds {t}).', { t: esc(fmtDateTime(st.active_since)) })}</b> ${L('Zodra hij deze server twee minuten achter elkaar bereikt, geeft hij het terug.')}</p>` : ''}
    ${rsRow(L('Reserve'), `<b>${esc(d.reserve_url || '?')}</b> · RzSense ${esc(d.reserve_version || '?')}${d.reserve_version && d.reserve_version !== d.version ? ` <span class="warn-txt">(${L('hier {v}', { v: esc(d.version) })})</span>` : ''}`)}
    ${rsRow(L('Laatste contact'), `${late ? '⚠ ' : '✔ '}${rsWhen(d.seen)}`)}
    ${rsRow(L('Adres voor de agents'), `<span class="rs-agent-url"><input id="rs-agent-url" type="url" spellcheck="false" value="${esc(d.agent_url)}" placeholder="${esc(d.reserve_url || 'https://reserve.voorbeeld.nl')}">
      <button type="button" class="btn small-btn" id="rs-agent-save">${L('Opslaan')}</button></span>
      <br><span class="muted small">${L('Leeg = het adres dat de reserve zelf meldt. Gebruik een adres met een geldig certificaat (bv. via SWAG).')}
      ${L('{n} agent(s) kennen de reserve', { n: ag.ready })}${ag.old ? ' · <span class="warn-txt">' + L('{n} agent(s) eerst bijwerken (1.111.0 of nieuwer)', { n: ag.old }) + '</span>' : ''}</span>`)}
    ${rsRow(L('Kleine kopie (elke 15 min)'), d.snap ? `${rsWhen(d.snap.at)} · ${esc(fmtBytes(d.snap.size))}` : `<span class="muted">${L('nog niet')}</span>`)}
    ${rsRow(L('Volledige kopie (elke nacht)'), d.full ? `${rsWhen(d.full.at)} · ${esc(fmtBytes(d.full.size))}` : `<span class="muted">${L('nog niet')}</span>`)}
    ${rsRow(L('Bij de reserve'), `${L('kleine kopie van {a}', { a: st.snap_at ? esc(fmtDateTime(st.snap_at)) : L('nog geen') })} · ${L('volledige van {a}', { a: st.full_at ? esc(fmtDateTime(st.full_at)) : L('nog geen') })}${dl ? ' · ' + esc(dl) : ''}`)}
    ${rsPeriods(st.periods)}${rsHistIn(d.hist_in)}
    ${d.build_error ? `<p class="form-error">${esc(d.build_error)}</p>` : ''}${st.apply_error ? `<p class="form-error">${L('Reserve')}: ${esc(st.apply_error)}</p>` : ''}
    <div class="btn-row"><button type="button" class="btn" id="rs-now">📦 ${L('Nu een kleine kopie maken')}</button>
      <button type="button" class="btn" id="rs-code">🔑 ${L('Nieuwe koppelcode')}</button>
      <button type="button" class="btn danger" id="rs-unpair">✕ ${L('Ontkoppelen')}</button></div>`;
}

function rsReserve(d) {
  const dl = d.dl && d.dl.size ? `${d.dl.kind === 'full' ? L('volledige kopie') : L('kleine kopie')}: ${Math.floor((d.dl.off / d.dl.size) * 100)}% (${esc(fmtBytes(d.dl.off))} / ${esc(fmtBytes(d.dl.size))})` : '';
  return `${d.state === 'active' ? `<p class="warn-txt"><b>🛟 ${L('Deze reserve doet nu het werk van de hoofdserver (sinds {t}).', { t: esc(fmtDateTime(d.active_since)) })}</b> ${L('Checks en meldingen lopen hier. Is de hoofdserver twee minuten achter elkaar bereikbaar, dan geeft de reserve het terug.')}</p>` : ''}
    ${rsRow(L('Hoofdserver'), `<b>${esc(d.primary_url)}</b> · RzSense ${esc(d.primary_version || '?')}`)}
    ${rsRow(L('Laatste contact'), `${d.down_since ? '⚠ ' : '✔ '}${rsWhen(d.primary_seen)}${d.down_since ? ` · <span class="warn-txt">${L('onbereikbaar sinds {t}', { t: esc(fmtDateTime(d.down_since)) })}</span>` : ''}`)}
    ${rsRow(L('Kleine kopie'), `${d.snap_at ? esc(fmtDateTime(d.snap_at)) : `<span class="muted">${L('nog niet')}</span>`}${d.snap_applied ? ` · ${L('teruggezet {t}', { t: esc(ago(d.snap_applied)) })}` : ''}`)}
    ${rsRow(L('Volledige kopie'), `${d.full_at ? esc(fmtDateTime(d.full_at)) : `<span class="muted">${L('nog niet')}</span>`}${d.full_applied ? ` · ${L('teruggezet {t}', { t: esc(ago(d.full_applied)) })}` : ''}`)}
    ${dl ? rsRow(L('Bezig'), esc(dl)) : ''}
    ${rsPeriods(d.periods)}${d.hist ? rsRow(L('Geschiedenis terugsturen'), esc(L('deel {i} van {n} verstuurd ({r} rijen)', { i: d.hist.next, n: d.hist.parts, r: d.hist.rows }))) : ''}${d.hold_full ? `<p class="muted small">${L('Volledige kopieën worden niet teruggezet zolang de geschiedenis van een overname alleen hier staat.')}</p>` : ''}
    ${d.error ? `<p class="form-error">${esc(d.error)}</p>` : ''}${d.apply_error ? `<p class="form-error">${esc(d.apply_error)}</p>` : ''}
    <div class="btn-row"><button type="button" class="btn" id="rs-now">↻ ${L('Nu bijwerken')}</button>
      <button type="button" class="btn danger" id="rs-unpair">✕ ${L('Ontkoppelen')}</button></div>`;
}

function rsEvents(box, d) {
  const code = $('#rs-code', box);
  if (code) code.onclick = async () => {
    const pw = await askConfirm({ title: L('Koppelcode voor een reserve maken?'), text: L('Met deze code krijgt een reserve een volledige kopie van deze RzSense, met wachtwoorden en sleutels. Geef hem alleen aan je eigen reserve.'), word: 'RESERVE' });
    if (pw === null) return;
    try {
      const r = await api('reserve_code', { confirm: pw });
      modal({ title: '🔑 ' + L('Koppelcode'), body: `<p>${L('Vul op de reserve in (Instellingen → Systeem → Reserve-RzSense):')}</p>
        ${rsRow(L('Adres van de hoofdserver'), `<code class="mono">${esc(r.url)}</code>`)}${rsRow(L('Koppelcode'), `<code class="mono" style="font-size:1.15rem">${esc(r.code)}</code>`)}
        <p class="muted small">${L('Geldig tot {t}. Een nieuwe code maakt deze ongeldig.', { t: esc(fmtDateTime(r.until)) })}</p>`, buttons: [] });
      loadReserveSet();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  const pair = $('#rs-pair', box);
  if (pair) pair.onclick = async () => {
    const url = $('#rs-url', box).value.trim(), c = $('#rs-pcode', box).value.trim();
    if (!url || !c) { toast(L('Vul het adres en de koppelcode in'), 'err'); return; }
    const pw = await askConfirm({ title: L('Deze RzSense als reserve koppelen?'), text: L('<b>Alles in deze RzSense wordt overschreven</b> met de gegevens van {u}, ook de gebruikers. Daarna log je hier in met je account van de hoofdserver.', { u: esc(url) }), word: 'RESERVE', danger: true });
    if (pw === null) return;
    pair.disabled = true;
    try { await api('reserve_pair', { url, code: c, confirm: pw }); toast(L('Gekoppeld: de eerste kopie komt binnen een paar minuten')); } catch (ex) { toast(ex.message, 'err'); }
    pair.disabled = false;
    loadReserveSet();
  };
  const asv = $('#rs-agent-save', box);
  if (asv) asv.onclick = async () => {
    try { await api('reserve_agent_url', { url: $('#rs-agent-url', box).value.trim() }); toast(L('Opgeslagen: de agents krijgen het adres bij hun volgende rapport')); } catch (ex) { toast(ex.message, 'err'); }
    loadReserveSet();
  };
  const now = $('#rs-now', box);
  if (now) now.onclick = async () => {
    now.disabled = true;
    try { const r = await api('reserve_now', {}); if (r.message) toast(r.message); } catch (ex) { toast(ex.message, 'err'); }
    now.disabled = false;
    loadReserveSet();
  };
  const un = $('#rs-unpair', box);
  if (un) un.onclick = async () => {
    const pw = await askConfirm({ title: L('Reserve ontkoppelen?'), text: d.role === 'primary' ? L('De reserve krijgt geen kopieën meer. De kopieën hier worden weggehaald.') : L('Deze RzSense haalt geen kopieën meer op. De gegevens blijven staan zoals ze nu zijn.'), word: 'ONTKOPPELEN', danger: true });
    if (pw === null) return;
    try { await api('reserve_unpair', { confirm: pw }); toast(L('Ontkoppeld')); } catch (ex) { toast(ex.message, 'err'); }
    loadReserveSet();
  };
}
