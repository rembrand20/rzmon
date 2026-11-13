// ---------------------------------------------------------------- 1.251.0 (agent 1.96.0): logmappen per server
// Agent Manager › Logs: extra mappen voor deze server (alleen binnen het plafond LOG_DIRS_MAX op de server), en een map
// doorbladeren via de agent (alleen namen, groottes en tijden). De agent antwoordt bij zijn volgende rapport.

const LD = { host: 0, path: '', timer: 0, started: 0, tick: 0 };
const LD_STATE = () => ({ ok: ['ok', '✔ ' + L('actief')], wait: ['wait', '⏳ ' + L('wacht op de agent')], refused: ['bad', '✘ ' + L('geweigerd')] });
/** Redenen van de agent in begrijpelijke taal */
const ldWhy = (w) => ({ 'bestaat niet': L('bestaat niet op de server'), 'geen volledig pad': L('geen volledig pad'), 'map met geheimen': L('map met sleutels of wachtwoorden'),
  'LOG_DIRS_MAX staat uit op de server': L('LOG_DIRS_MAX staat uit op de server'), 'geen leesrechten': L('geen leesrechten'),
  'buiten LOG_DIRS en LOG_DIRS_MAX': L('buiten het plafond (LOG_DIRS_MAX)') }[w] || (w.startsWith('buiten LOG_DIRS_MAX') ? L('buiten het plafond (LOG_DIRS_MAX)') : w));

/** Het blok "Logmappen voor deze server", onder de gevonden logs; d = antwoord van log_found */
function ldRender(h, d) {
  const box = $('#ls-dirs');
  if (!box) return;
  const st = LD_STATE();
  const words = (s) => (s || '').split(/\s+/).filter(Boolean);
  const local = words(d.local || d.dirs).filter((x) => !(d.extra || []).some((e) => e.dir === x));
  const max = words(d.max);
  const oldIn = $('#ld-in', box), keepVal = oldIn ? oldIn.value : '', keepFocus = oldIn && document.activeElement === oldIn;
  const rows = (d.extra || []).map((e) => `<div class="ld-row"><code class="ld-dir">${esc(e.dir)}</code>
      <span class="lf-tag ${st[e.state][0]}">${st[e.state][1]}${e.state === 'refused' && e.why ? ': ' + esc(ldWhy(e.why)) : ''}</span><span class="lp-sp"></span>
      <button type="button" class="btn small-btn" data-ld-open="${esc(e.dir)}" title="${esc(L('Bladeren'))}">📂</button>
      <button type="button" class="btn small-btn" data-ld-del="${esc(e.dir)}" title="${esc(L('Weghalen'))}" aria-label="${esc(L('{d} weghalen', { d: e.dir }))}">✕</button></div>`).join('');
  box.innerHTML = `<div class="ld-h"><b>📂 ${L('Logmappen voor deze server')}</b></div>
    ${xpHtml(L('Kies per server in welke mappen de agent naar logs zoekt. Daarna vink je ze hierboven aan bij Logmappen.'),
      L('<b>Veilig:</b> RzSense kan alleen mappen kiezen binnen het plafond dat óp de server staat (<code>LOG_DIRS_MAX</code> in <code>/etc/default/rzmon-agent</code>). Daarin leest de agent alleen bestanden die op een log lijken (.log, .out, .err, .txt of "log" in de naam), en nooit sleutels, wachtwoorden, .env-bestanden of instellingen. Zo kan niemand via RzSense andere bestanden van je server opvragen. <b>Van de server zelf:</b> wat in <code>LOG_DIRS</code> staat, leest de agent altijd. <b>Bladeren:</b> de agent stuurt alleen namen, groottes en tijden, nooit de inhoud.'))}
    ${d.at && !d.supported ? `<div class="callout small">⬆ ${L('Deze agent is te oud voor logmappen uit RzSense: agent 1.96.0 of nieuwer nodig (bijwerken bij Versie hierboven). Tot die tijd: zet de map op de server in LOG_DIRS.')}</div>` : ''}
    <div class="ld-row"><code class="ld-dir">${esc(local.join(' ') || '/var/log')}</code><span class="lf-tag">🔒 ${L('van de server zelf (LOG_DIRS)')}</span><span class="lp-sp"></span>
      ${local.slice(0, 1).map((x) => `<button type="button" class="btn small-btn" data-ld-open="${esc(x)}" title="${esc(L('Bladeren'))}">📂</button>`).join('')}</div>
    ${rows}
    <div class="ld-add"><input type="text" class="mono" id="ld-in" placeholder="/opt/mijnapp/logs" aria-label="${esc(L('Map toevoegen'))}" spellcheck="false" autocomplete="off">
      <button type="button" class="btn small-btn primary" id="ld-add">＋ ${L('Map toevoegen')}</button>
      <button type="button" class="btn small-btn" id="ld-browse">📂 ${L('Bladeren…')}</button></div>
    <p class="muted small">${max.length ? L('Plafond op deze server (LOG_DIRS_MAX): {d}', { d: max.map((x) => `<code>${esc(x)}</code>`).join(' ') })
      : d.supported ? `<span class="warn-txt">${L('LOG_DIRS_MAX staat uit op deze server: RzSense kan hier geen mappen toevoegen.')}</span>` : ''}
      ${L('Ruimer of uit: pas LOG_DIRS_MAX aan in /etc/default/rzmon-agent.')}</p>
    <div id="ls-browse" class="ld-browse"${LD.host === h.id && LD.path ? '' : ' hidden'}></div>`;
  const save = async (list) => {
    try {
      await api('log_dirs_save', { host_id: h.id, dirs: list.join(' ') });
      toast(L('Opgeslagen; de agent zoekt bij zijn volgende rapport opnieuw'));
      LF.waitUntil = Date.now() + 180000;
      lfLoad(h, true);
    } catch (ex) { toast(ex.message, 'err'); }
  };
  const extra = (d.extra || []).map((e) => e.dir);
  box.onclick = (e) => {
    const del = e.target.closest('[data-ld-del]');
    if (del) return save(extra.filter((x) => x !== del.dataset.ldDel));
    const op = e.target.closest('[data-ld-open]');
    if (op) return ldBrowse(h, d, op.dataset.ldOpen);
    if (e.target.closest('#ld-browse')) return ldBrowse(h, d, $('#ld-in', box).value.trim() || '');
    if (e.target.closest('#ld-add')) {
      const v = $('#ld-in', box).value.trim().replace(/\/+$/, '');
      if (!v) return $('#ld-in', box).focus();
      if (extra.includes(v)) return toast(L('Die map staat er al'), 'err');
      return save([...extra, v]);
    }
    const add = e.target.closest('[data-ld-addthis]');
    if (add) {
      const v = add.dataset.ldAddthis;
      return extra.includes(v) ? toast(L('Die map staat er al'), 'err') : save([...extra, v]);
    }
    const go = e.target.closest('[data-ld-go]');
    if (go) return ldBrowse(h, d, go.dataset.ldGo);
    if (e.target.closest('[data-ld-close]')) { LD.path = ''; clearTimeout(LD.timer); $('#ls-browse', box).hidden = true; }
  };
  if (keepVal) $('#ld-in', box).value = keepVal;
  if (keepFocus) $('#ld-in', box).focus();
  $('#ld-in', box).onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#ld-add', box).click(); } };
  // Was er al een map open (de lijst werd net opnieuw getekend)? Dan die weer tonen.
  if (LD.host === h.id && LD.path) { clearTimeout(LD.timer); ldShow(h, d); }
}

/** Een map doorbladeren: '' = beginpunten (de mappen van de server en het plafond), anders de agent vragen */
async function ldBrowse(h, d, path) {
  clearTimeout(LD.timer);
  LD.host = h.id;
  LD.path = path || '/';
  LD.started = Date.now();
  LD.res = null;
  if (LD.path !== '/') {
    try { await api('log_browse', { host_id: h.id, path: LD.path }); } catch (ex) { toast(ex.message, 'err'); LD.path = ''; return; }
  }
  ldShow(h, d);
}

async function ldShow(h, d) {
  const el = $('#ls-browse');
  if (!el) return;
  el.hidden = false;
  const tick = ++LD.tick;
  const crumbs = (p) => {
    const parts = p.split('/').filter(Boolean);
    return `<button type="button" class="linkish" data-ld-go="/">📂</button>` + parts.map((x, i) => ` / <button type="button" class="linkish" data-ld-go="/${esc(parts.slice(0, i + 1).join('/'))}">${esc(x)}</button>`).join('');
  };
  const head = (p, extra = '') => `<div class="ld-bh"><span class="ld-crumbs mono small">${crumbs(p)}</span><span class="lp-sp"></span>${extra}
    <button type="button" class="btn small-btn" data-ld-close aria-label="${esc(L('Sluiten'))}">✕</button></div>`;
  // Beginpunten: geen vraag aan de agent nodig
  if (LD.path === '/') {
    const roots = [...new Set([...(d.local || d.dirs || '/var/log').split(/\s+/), ...(d.max || '').split(/\s+/)].filter(Boolean))];
    el.innerHTML = head('/') + `<div class="ld-list">${roots.map((r) => `<button type="button" class="ld-it" data-ld-go="${esc(r)}"><span>📁 <span class="mono">${esc(r)}</span></span></button>`).join('')}</div>
      <p class="muted small">${L('Hier mag de agent kijken. Kies een map om erin te bladeren.')}</p>`;
    return;
  }
  let r;
  try { r = await api('log_browse_get', null, { host_id: h.id }); } catch (ex) { el.innerHTML = head(LD.path) + `<p class="bad small">${esc(ex.message)}</p>`; return; }
  if (tick !== LD.tick || !el.isConnected || LD.host !== h.id) return;
  const secs = Math.round((Date.now() - LD.started) / 1000);
  if (r.pending || !r.result || r.result.path !== LD.path) {
    if (secs > 180) {
      el.innerHTML = head(LD.path) + `<p class="warn-txt small">${L('De agent antwoordt niet. Is hij online en minstens versie 1.96.0?')}</p>`;
      return;
    }
    el.innerHTML = head(LD.path) + `<div class="ld-wait"><div class="ld-bar" role="progressbar" aria-label="${esc(L('Wacht op de agent'))}"><i></i></div>
      <span class="muted small">⏳ ${L('Wacht op de agent… {t}', { t: `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}` })} · ${L('meestal binnen een minuut (bij zijn volgende rapport)')}</span></div>`;
    LD.timer = setTimeout(() => { if (el.isConnected && LD.path) ldShow(h, d); }, 3000);
    return;
  }
  const res = r.result;
  const up = LD.path.replace(/\/[^/]+$/, '') || '/';
  const allowedHere = !res.err;
  const nLog = res.files.filter((f) => f[3] === 1).length;
  const fileTag = (ok) => (ok === 1 ? `<span class="lf-tag ok">✓ ${L('log')}</span>` : ok === 2 ? `<span class="lf-tag bad">🔒 ${L('geen leesrechten')}</span>` : `<span class="lf-tag">${L('overgeslagen')}</span>`);
  el.innerHTML = head(LD.path, allowedHere ? `<button type="button" class="btn small-btn primary" data-ld-addthis="${esc(LD.path)}">＋ ${L('Deze map toevoegen')}</button>` : '')
    + (res.err ? `<p class="warn-txt small">✘ ${esc(ldWhy(res.err))}</p>` : `<div class="ld-list">
      ${LD.path.split('/').filter(Boolean).length > 1 ? `<button type="button" class="ld-it" data-ld-go="${esc(up)}"><span>⬆ ..</span></button>` : ''}
      ${res.dirs.map(([n, c, ok]) => `<button type="button" class="ld-it${ok ? '' : ' dim'}" data-ld-go="${esc(LD.path.replace(/\/$/, '') + '/' + n)}" ${ok ? '' : 'disabled'}>
        <span>📁 ${esc(n)}</span><span class="muted small">${ok ? (c ? L('{n} logs', { n: c }) : L('geen logs')) : L('niet toegankelijk')}</span></button>`).join('')}
      ${res.files.map(([n, sz, mt, ok]) => `<div class="ld-it ld-file${ok === 1 ? '' : ' dim'}"><span>📄 ${esc(n)}</span><span class="muted small">${fmtBytes(sz)} · ${esc(lfAgo(mt))}</span>${fileTag(ok)}</div>`).join('')}
      ${!res.dirs.length && !res.files.length ? `<p class="muted small">${L('Deze map is leeg.')}</p>` : ''}</div>
      <p class="muted small">${nLog === 1 ? L('1 bestand hier zou de agent lezen. Voeg de map toe en vink het daarna hierboven aan.') : nLog ? L('{n} bestanden hier zou de agent lezen. Voeg de map toe en vink ze daarna hierboven aan.', { n: nLog }) : L('Hier staan geen logs; kies een submap.')} ${L('Overgeslagen: bestanden die niet op een log lijken, verborgen bestanden, sleutels en instellingen.')}</p>`);
}
