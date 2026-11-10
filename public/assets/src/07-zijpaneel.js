// ---------------------------------------------------------------- zijpaneel
let al2Sel = null;
function al2Drawer() {
  let d = $('#al2-drawer');
  if (!d) {
    document.body.insertAdjacentHTML('beforeend', '<div class="al2-scrim" id="al2-scrim" hidden></div><aside class="al2-drawer" id="al2-drawer" aria-label="" hidden></aside>');
    d = $('#al2-drawer');
    $('#al2-scrim').onclick = al2Close;
    d.addEventListener('click', al2DrawerClick);
  }
  return d;
}
/** 1.218.0: op een telefoon is het paneel een blad van onderaf; omlaag vegen bij de kop sluit het */
document.addEventListener('touchstart', (e) => {
  const h = e.target.closest && e.target.closest('#al2-drawer .al2-dh');
  if (!h || !matchMedia('(max-width: 760px), (pointer: coarse)').matches) return;
  const d = $('#al2-drawer'), y0 = e.touches[0].clientY;
  let dy = 0;
  const mv = (ev) => { dy = Math.max(0, ev.touches[0].clientY - y0); d.style.transition = 'none'; d.style.transform = `translateY(${dy}px)`; };
  const up = () => {
    d.style.transition = ''; d.style.transform = '';
    document.removeEventListener('touchmove', mv); document.removeEventListener('touchend', up);
    if (dy > 90) al2Close();
  };
  document.addEventListener('touchmove', mv, { passive: true }); document.addEventListener('touchend', up);
}, { passive: true });
function al2Close() {
  const d = $('#al2-drawer');
  if (!d || d.hidden) return;
  d.classList.remove('on');
  $('#al2-scrim').classList.remove('on');
  setTimeout(() => { if (!d.classList.contains('on')) { d.hidden = true; $('#al2-scrim').hidden = true; } }, 260);
  $$('#alerts .al2-row.al2-sel').forEach((r) => r.classList.remove('al2-sel'));
  al2Sel = null;
}
/** 1.218.0: het bericht leesbaar: zonder het voorvoegsel ("⏳ in orde, nog niet bevestigd (1/2): ") en zonder "(flapt: …)" */
function al2Clean(msg) {
  let m = String(splitSent(msg || '')[0] || '').trim();
  m = m.replace(/^[^:\n]{0,60}?\(\d+\/\d+\):\s*/, '').replace(/\s*\((flapt|flapping)[^)]*\)/gi, '').trim();
  return m;
}
/** 1.218.0: één regel bovenaan: de waarde groot, de drempel, en bij load hoeveel dat is ten opzichte van de cores */
function al2Summary(a, c) {
  const m = al2Clean(a.message);
  const first = m.split(' · ')[0];
  const mm = first.match(/^(.*?)(-?\d+(?:[.,]\d+)?)\s*(%|ms|°C|MB|GB|s|Mbit\/s)?(.*)$/);
  const parts = [];
  if (mm) parts.push(`${esc(mm[1])}<b class="al2-big">${esc(dec(mm[2]))}${esc(mm[3] || '')}</b>${esc(mm[4])}`); else parts.push(esc(first));
  if (c && (c.warn_threshold !== null || c.crit_threshold !== null)) {
    const t = typeOf(c), u = t.unit === '%' ? '%' : t.unit ? ' ' + L(t.unit) : '';
    parts.push(esc([c.warn_threshold !== null ? L('warning vanaf {v}', { v: dec(+c.warn_threshold) + u }) : '', c.crit_threshold !== null ? L('critical vanaf {v}', { v: dec(+c.crit_threshold) + u }) : ''].filter(Boolean).join(', ')));
  }
  const ld = m.match(/load\s+([\d.]+)[^(]*\((\d+)\s*cores?\)/i);
  if (ld) parts.push(esc(L('load {l} op {c} cores ({p})', { l: dec(ld[1]), c: ld[2], p: Math.round(+ld[1] / +ld[2] * 100) + '%' })));
  return `<div>${parts[0]}</div>${parts.length > 1 ? `<div class="muted small">${parts.slice(1).join(' · ')}</div>` : ''}`;
}
/** 1.218.0: korte datum: "vandaag 16:49", "gisteren 10:15", "do 22:45" */
function al2Short(ts) {
  const d = new Date(String(ts).replace(' ', 'T')), now = new Date();
  const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const diff = Math.round((day(now) - day(d)) / 86400000), hm = String(ts).slice(11, 16);
  if (diff === 0) return L('vandaag {t}', { t: hm });
  if (diff === 1) return L('gisteren {t}', { t: hm });
  if (diff < 7) return d.toLocaleDateString(LOCALE, { weekday: 'short' }) + ' ' + hm;
  return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) + ' ' + hm;
}
/** 1.218.0: bezorging in woorden: per kanaal ✓ of ✕ */
function al2Deliv(x) {
  const why = splitSent(x.message)[1];
  if (x.kind === 'auth' || x.kind === 'authfail') return `<span class="muted">${esc(L('alleen op deze pagina'))}</span>`;
  const out = [];
  const ch = (ok, name) => `<span class="al2-ch ${ok ? 'ok' : 'bad'}">${esc(name)} ${ok ? '✓' : '✕'}</span>`;
  if (+x.sent_ok) out.push(ch(true, 'Push')); else if (/Pushover|ntfy/.test(why || '')) out.push(ch(false, 'Push'));
  if (x.email_ok !== null && x.email_ok !== undefined) out.push(ch(+x.email_ok, L('E-mail')));
  if (x.webhook_ok !== null && x.webhook_ok !== undefined) out.push(ch(+x.webhook_ok, 'Home Assistant'));
  if (!out.length) out.push(`<span class="muted">🔕 ${esc(why && /stil/i.test(why) ? L('stille uren: niet verstuurd') : L('niet verstuurd: alleen op deze pagina'))}</span>`);
  return out.join(' ') + (why && out.length && !/stil/i.test(why) ? ` <span class="bad small">${esc(why)}</span>` : '');
}
/** 1.218.0: strookje met de storingen van deze check over 7 dagen */
function al2Strip(eps, it) {
  const to = Date.now(), from = to - 7 * 86400000;
  const xs = eps.filter((x) => al2Ts(x.first.created_at) >= from);
  const tick = (x) => `<i class="${x === it ? 'cur' : ''}" style="left:${((al2Ts(x.first.created_at) - from) / (to - from) * 100).toFixed(2)}%"></i>`;
  return `<div class="al2-strip" title="${esc(L('Storingen van deze check, 7 dagen'))}">${xs.map(tick).join('')}<span>${esc(L('7 dagen'))}</span><span>${esc(L('nu'))}</span></div>`;
}

async function al2Open(aid) {
  const it = AL2_ITEMS.get(String(aid));
  if (!it) return;
  al2Sel = String(aid);
  $$('#alerts .al2-row.al2-sel').forEach((r) => r.classList.remove('al2-sel'));
  const row = $(`#alerts .al2-row[data-aid="${aid}"]`);
  if (row) row.classList.add('al2-sel');
  al2MarkSeen(it);
  const d = al2Drawer(), a = it.first, k = it.k;
  const { host, check } = alertCols(a);
  const t0 = a.created_at, t1 = it.rec ? it.rec.created_at : null;
  const sub = it.ep ? (t1 ? L('{from} → hersteld {to} ({d})', { from: fmtDateTime(t0), to: t1.slice(11, 16), d: al2Dur(al2Ts(t1) - al2Ts(t0)) })
    : it.open ? L('{from} · nog open ({d})', { from: fmtDateTime(t0), d: al2Dur(Date.now() - al2Ts(t0)) }) : fmtDateTime(t0)) : fmtDateTime(t0);
  // Eerdere keren: andere storingen van dezelfde check in de geladen dagen
  const eps = a.check_id ? (alertEpisodesCache || []).filter((x) => x.ep && +x.first.check_id === +a.check_id) : [];
  const prev = eps.filter((x) => x !== it && x.first.created_at < a.created_at).slice(0, 6);
  const chk = a.check_id ? (findCheck(a.check_id) || [])[0] : null;
  // 1.218.0: flapt deze check? 3 of meer korte storingen (hooguit 10 min) in de geladen dagen
  const shortEp = eps.filter((x) => x.rec && al2Ts(x.rec.created_at) - al2Ts(x.first.created_at) <= 600000);
  const flaps = a.check_id && shortEp.length >= 3 ? shortEp.length : 0;
  const recov = !!it.rec;
  const canAct = (isAdmin() || isUser()) && a.check_id;
  const lwd = a.log_watch ? al2LogDetail(a) : null;   // 1.212.0: melding van een vaste zoekactie
  d.className = 'al2-drawer k-' + (recov && it.ep ? 'ok' : k);
  d.setAttribute('aria-label', L('Details van de melding'));
  d.innerHTML = `<div class="al2-dh"><span class="al2-grip" aria-hidden="true"></span>${recov && it.ep
      ? `<span class="al2-badge"><span class="al2-shape" aria-hidden="true">✓</span>${esc(L('Hersteld na {d}', { d: al2Dur(al2Ts(it.rec.created_at) - al2Ts(t0)) }))}</span> <span class="al2-was k-${k}">${esc(L('was {s}', { s: al2Label(k, a) }))}</span>`
      : `<span class="al2-badge"><span class="al2-shape" aria-hidden="true">${AL2_SHAPE[k]}</span>${esc(al2Label(k, a))}</span>`}
      <button type="button" class="al2-x icon-btn" data-al2-close aria-label="${esc(L('Sluiten'))}">✕</button>
      <h3>${esc(host)}${check && check !== '–' ? ' › ' + esc(check) : ''}</h3><div class="muted small">${esc(sub)}</div>
      ${it.open ? `<div class="muted small">👁 <span id="al2-seenby">${esc(al2SeenText(al2Seen(it)))}</span></div>` : ''}</div>
    <div class="al2-db">
      ${a.check_id ? `<div class="al2-sum">${al2Summary(a, chk)}</div>` : ''}
      ${flaps && (isAdmin() || isUser()) ? `<div class="al2-flap"><b>🔁 ${esc(L('Deze check slaat vaak kort aan'))}</b>
        <div class="small">${esc(L('{n} korte storingen (hooguit 10 min) in de laatste dagen, steeds vanzelf hersteld. Zo krijg je meldingen die je niets hoeven te zeggen.', { n: flaps }))}</div>
        <div class="al2-flap-a"><button type="button" class="btn small-btn primary" data-al2-act="fails3">${esc(L('Pas melden na {n} metingen', { n: Math.max(3, (+(chk && chk.notify_fails) || +state.notify_fails || 1) + 2) }))}</button>
          ${chk && (chk.warn_threshold !== null || chk.crit_threshold !== null) && chk.thresh_mode !== 'smart' ? `<button type="button" class="btn small-btn" data-al2-act="smart">🧠 ${esc(L('Slimme drempel'))}</button>` : ''}</div></div>` : ''}
      <div class="al2-ds"><h4>${L('Bericht')}</h4>${it.alerts.map((x) => `<div class="al2-msg">${it.alerts.length > 1 ? `<div class="muted small">${esc(fmtDateTime(x.created_at))} · ${esc(al2Label(alertKind2(x), x))}</div>` : ''}${esc(a.check_id ? al2Clean(x.message) : splitSent(x.message)[0])}</div>`).join('')}
        ${a.check_id && it.alerts.some((x) => al2Clean(x.message) !== String(splitSent(x.message)[0]).trim()) ? `<details class="al2-raw"><summary class="small muted">${L('Volledig bericht')}</summary>${it.alerts.map((x) => `<div class="small mono">${esc(splitSent(x.message)[0])}</div>`).join('')}</details>` : ''}</div>
      ${lwd && lwd.lines && lwd.lines.length ? `<div class="al2-ds"><h4>${L('Laatste regels')}</h4><div class="lp-lines al2-lw">${lwd.lines.map(([ts, h, s, m]) => `<div class="al2-lwl"><span class="lp-t">${esc(new Date(ts * 1000).toLocaleTimeString(LOCALE))}</span><span class="small muted">${esc(h)} › ${esc(s)}</span><span class="lp-m">${esc(m).replace(/••••/g, '<span class="lp-mask">••••</span>')}</span></div>`).join('')}</div></div>` : ''}
      ${lwd ? `<div class="al2-ds"><h4>${L('Zoekactie')}</h4><div class="small"><b>${esc(lwd.name || '')}</b> · <code>${esc(lwd.q || '')}</code>${lwd.mode === 'none' ? '' : ` · ${esc(L('drempel {n} in {w}', { n: dec(lwd.thr || 0), w: lwWinText(lwd.win || 5) }))}`}</div></div>` : ''}
      ${a.check_id ? `<div class="al2-ds"><h4>${L('Het uur eromheen')}</h4><div id="al2-chart" class="al2-chart"><p class="muted small">${L('Laden…')}</p></div></div>` : ''}
      ${a.check_id ? `<div class="al2-ds"><h4>${L('Eerdere keren')}</h4>${eps.length > 1 ? al2Strip(eps, it) : ''}<div class="al2-hist">${prev.length ? prev.map((x) => `<div class="al2-prev" data-al2-go="${esc(String(x.first.id))}" role="button" tabindex="0"><span>${esc(al2Short(x.first.created_at))}</span><span class="muted">${x.rec ? esc(L('hersteld na {d}', { d: al2Dur(al2Ts(x.rec.created_at) - al2Ts(x.first.created_at)) })) : esc(al2Label(x.k, x.first))}</span></div>`).join('') : `<span class="muted">${L('Niet eerder in deze periode.')}</span>`}</div></div>` : ''}
      <div class="al2-ds"><h4>${L('Bezorging')}</h4><div class="al2-deliv">${it.alerts.map((x) => `<div><span class="mono muted">${esc(x.created_at.slice(11, 16))}</span> ${al2Deliv(x)}</div>`).join('')}</div></div>
    </div>
    <div class="al2-da">${canAct && it.open ? `<button type="button" class="btn primary" data-al2-act="ack">✔ ${L('Bevestigen')}</button><button type="button" class="btn" data-al2-act="maint">🔕 ${L('Stil 1 uur')}</button>` : ''}
      ${canAct && !it.open ? `<button type="button" class="btn" data-al2-act="maint">🌙 ${L('Stil 1 uur')}</button>` : ''}
      ${a.check_id ? `<button type="button" class="btn" data-al2-act="check">${L('Naar de check')} →</button>` : ''}
      ${a.check_id && (isAdmin() || isUser()) ? `<button type="button" class="btn" data-al2-act="fails">🔔 ${L('Melden na…')}</button>` : ''}
      ${a.check_id ? aiBtn('check', a.check_id, true) : ''}
      ${lwd ? `<button type="button" class="btn primary" data-al2-act="logs">📜 ${L('In Logs bekijken')}</button>${isAdmin() || isUser() ? `<button type="button" class="btn" data-al2-act="lwedit">${L('Zoekactie aanpassen')}</button>` : ''}` : ''}</div>`;
  d.hidden = false;
  $('#al2-scrim').hidden = false;
  requestAnimationFrame(() => { d.classList.add('on'); $('#al2-scrim').classList.add('on'); });
  if (a.check_id) al2Chart(it);
}
/** 1.212.0: gegevens van een melding van een vaste zoekactie */
function al2LogDetail(a) {
  try { return JSON.parse(a.detail || '{}') || {}; } catch (e) { return {}; }
}
/** In Logs bekijken: de zoekopdracht rond het moment van de melding; of de zoekactie aanpassen */
async function al2LogAct(it, act) {
  const d = al2LogDetail(it.first);
  al2Close();
  if (act === 'lwedit') {
    try { const r = await api('log_watches'); const w = r.watches.find((x) => x.id === +d.watch); if (w) return lwEdit(w); toast(L('Deze zoekactie bestaat niet meer'), 'err'); } catch (ex) { toast(ex.message, 'err'); }
    return;
  }
  const t = Math.floor(al2Ts(it.first.created_at) / 1000), end = it.rec ? Math.floor(al2Ts(it.rec.created_at) / 1000) : Math.floor(Date.now() / 1000);
  LP.q = d.q || ''; LP.hosts = d.host_id ? [+d.host_id] : []; LP.sources = []; LP.level = ''; LP.limit = 100; LP.allLv = null; LP.sub = 'search';
  LP.range = 'custom'; LP.from = t - Math.max(15, +d.win || 5) * 60 - 300; LP.to = Math.min(end + 300, Math.floor(Date.now() / 1000)); LP.hour = null;
  showTab('logs');
}
async function al2Chart(it) {
  const a = it.first, t0 = al2Ts(a.created_at), t1 = it.rec ? al2Ts(it.rec.created_at) : (it.open ? Date.now() : t0);
  const from = t0 - 1800000, to = Math.min(Date.now(), t1 + 1800000);
  let r;
  try { r = await api('history', null, { check_id: a.check_id, hours: Math.max(1, Math.ceil((Date.now() - from) / 3600000) + 1) }); } catch (ex) { r = null; }
  const box = $('#al2-chart');
  if (!box || al2Sel !== String(a.id)) return;
  const pts = ((r && r.points) || []).filter((p) => p.t * 1000 >= from && p.t * 1000 <= to);
  if (pts.length < 2) { box.innerHTML = `<p class="muted small">${L('Geen meetwaarden uit die tijd.')}</p>`; return; }
  const useV = pts.some((p) => p.v !== null && p.v !== undefined);
  const val = (p) => (useV ? +p.v : 3 - Math.min(3, +p.s));
  const vs = pts.map(val), max = Math.max(...vs), min = Math.min(...vs, 0), span = max - min || 1;
  const W = 380, H = 64, X = (t) => ((t - from) / (to - from) * W).toFixed(1), Y = (v) => (H - 4 - (v - min) / span * (H - 10)).toFixed(1);
  const line = pts.map((p) => `${X(p.t * 1000)},${Y(val(p))}`).join(' ');
  const mark = (t, c) => `<line x1="${X(t)}" y1="0" x2="${X(t)}" y2="${H}" stroke="${c}" stroke-dasharray="3 3"/>`;
  // 1.218.0: drempels als lijnen, de storing als vlak, en gemiddeld · hoogste · nu
  const ck = (findCheck(a.check_id) || [])[0];
  const thr = (v, c, l) => (useV && v !== null && v !== undefined && +v >= min && +v <= max * 1.5 ? `<line x1="0" x2="${W}" y1="${Y(+v)}" y2="${Y(+v)}" stroke="${c}" stroke-dasharray="5 4" opacity=".8"/><text x="${W - 2}" y="${Math.max(8, +Y(+v) - 3)}" text-anchor="end" style="fill:${c}">${esc(l)}</text>` : '');
  const band = `<rect x="${X(t0)}" y="0" width="${Math.max(2, X(t1) - X(t0))}" height="${H}" fill="color-mix(in srgb, var(--crit) 14%, transparent)"/>`;
  const avg = vs.reduce((x, y) => x + y, 0) / vs.length, last = vs[vs.length - 1], r1 = (v) => dec(Math.round(v * 10) / 10);
  box.innerHTML = `<svg viewBox="0 0 ${W} ${H + 14}" preserveAspectRatio="none" role="img" aria-label="${esc(L('Verloop rond de melding'))}">
    ${band}<polyline points="${X(pts[0].t * 1000)},${H} ${line} ${X(pts[pts.length - 1].t * 1000)},${H}" fill="color-mix(in srgb, var(--c) 16%, transparent)"/><polyline points="${line}" fill="none" stroke="var(--c)" stroke-width="2" vector-effect="non-scaling-stroke"/>
    ${ck ? thr(ck.warn_threshold, 'var(--warn)', 'warning') + thr(ck.crit_threshold, 'var(--crit)', 'critical') : ''}${mark(t0, 'var(--c)')}${it.rec ? mark(t1, 'var(--ok)') : ''}<line x1="0" y1="${H}" x2="${W}" y2="${H}" stroke="var(--border)"/>
    <text x="0" y="${H + 12}">${esc(new Date(from).toTimeString().slice(0, 5))}</text><text x="${W}" y="${H + 12}" text-anchor="end">${esc(new Date(to).toTimeString().slice(0, 5))}</text></svg>
    ${useV ? `<div class="al2-stats small"><span>${L('gemiddeld')} <b>${r1(avg)}</b></span><span>${L('hoogste')} <b>${r1(max)}</b></span><span>${L('nu')} <b>${r1(last)}</b></span></div>` : `<div class="muted small">${L('status (hoger = beter)')}</div>`}`;
}
async function al2DrawerClick(e) {
  if (e.target.closest('[data-al2-close]')) return al2Close();
  const go = e.target.closest('[data-al2-go]');
  if (go) return al2Open(go.dataset.al2Go);   // 1.218.0: een eerdere keer openen
  const b = e.target.closest('[data-al2-act]');
  if (!b || !al2Sel) return;
  const it = AL2_ITEMS.get(al2Sel);
  if (it && it.first.log_watch && ['logs', 'lwedit'].includes(b.dataset.al2Act)) return al2LogAct(it, b.dataset.al2Act);   // 1.212.0
  const cid = it && it.first.check_id;
  if (!cid) return;
  try {
    if (b.dataset.al2Act === 'check') { al2Close(); return checkInfo(cid); }
    // 1.218.0: melden na N metingen en slimme drempel, rechtstreeks vanuit de melding
    if (b.dataset.al2Act === 'fails' || b.dataset.al2Act === 'fails3') {
      const c = (findCheck(cid) || [])[0], cur = +(c && c.notify_fails) || +state.notify_fails || 1;
      let n = Math.max(3, cur + 2);
      if (b.dataset.al2Act === 'fails') { const v = prompt(L('Pas melden na hoeveel mislukte metingen achter elkaar? (nu {n})', { n: cur }), String(n)); if (v === null) return; n = Math.max(1, Math.min(100, parseInt(v, 10) || cur)); }
      b.disabled = true;
      await api('checks_bulk', { ids: [cid], action: 'set', values: { notify_fails: n } });
      toast('🔔 ' + L('Deze check meldt nu pas na {n} mislukte metingen', { n }));
      const f = $('.al2-flap', b.closest('.al2-drawer')); if (f) f.remove();
      return refresh();
    }
    if (b.dataset.al2Act === 'smart') {
      b.disabled = true;
      await api('checks_bulk', { ids: [cid], action: 'set', values: { thresh_mode: 'smart' } });
      toast('🧠 ' + L('Slimme drempel aan: de check leert wat normaal is'));
      const f = $('.al2-flap', b.closest('.al2-drawer')); if (f) f.remove();
      return refresh();
    }
    b.disabled = true;
    if (b.dataset.al2Act === 'ack') { await api('ack', { id: cid, comment: '' }); toast('✔ ' + L('Bevestigd')); }
    if (b.dataset.al2Act === 'maint') { await api('maintenance_set', { scope: 'check', id: cid, minutes: 60 }); toast('🔕 ' + L('Stil tot over een uur')); }
    refresh();
  } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
}
document.addEventListener('keydown', (e) => {
  const d = $('#al2-drawer');
  if (!d || d.hidden || !al2Sel) return;
  if (e.key === 'Escape') { e.preventDefault(); return al2Close(); }
  if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
  if (e.target.closest && e.target.closest('input, select, textarea')) return;
  const rows = $$('#alerts .al2-row[data-aid]').filter((r) => r.offsetParent);
  const i = rows.findIndex((r) => r.dataset.aid === al2Sel);
  const n = rows[Math.max(0, Math.min(rows.length - 1, i + (e.key === 'ArrowDown' ? 1 : -1)))];
  if (n && n.dataset.aid !== al2Sel) { e.preventDefault(); al2Open(n.dataset.aid); n.scrollIntoView({ block: 'nearest' }); }
});
let alertEpisodesCache = [];

function renderAlerts() {
  try { renderAlertCharts(); } catch (e) { /* grafiek mag de lijst nooit tegenhouden */ }
  const el = $('#alerts');
  if (!alertData) { el.innerHTML = `<p class="muted">${L('Laden…')}</p>`; return; }
  const { layout, density } = viewPrefs();
  const d = alertData;
  // Blox heeft geen kolomkoppen: daar een keuzelijst om te sorteren
  const sortSel = $('#alerts-sort');
  sortSel.hidden = layout === 'l1nes';
  sortSel.value = ALERT_SORTS[alertSort.key] ? `${alertSort.key}:${alertSort.dir}` : '';
  if (sortSel.selectedIndex < 0) sortSel.value = '';
  $('#alerts-info').textContent = (d.alerts.length === 1 ? L('1 melding') : L('{n} meldingen', { n: d.alerts.length }))
    + ' · ' + (d.days === 1 ? L('van de laatste dag') : L('van de laatste {n} dagen', { n: d.days }))
    + (d.truncated ? ' ' + L('(alleen de nieuwste 10.000)') : '');
  if (!['all', 'noinfo', 'open', 'problems', 'crit', 'warn', 'unk', 'ok', 'info', 'isys', 'sec'].includes(alertFilter)) alertFilter = 'all';
  // 1.207.0: een storing en zijn herstel worden één regel
  alertEpisodesCache = alertEpisodes(d.alerts);
  AL2_ITEMS.clear();
  renderAlertTop(d);
  const q = $('#alerts-search').value.trim().toLowerCase();
  const host = $('#alerts-host').value;
  const pass = (a) => (!host || alertCols(a).host === host)
    && (!q || `${a.title} ${a.message} ${alertCols(a).host}`.toLowerCase().includes(q)) && fxPass('alerts', a);
  let items = alertEpisodesCache.filter((it) => it.alerts.some((a) => pass(a) && (alertFilter === 'open' ? it.open : alertMatch(alertFilter, a))));
  if (ALERT_SORTS[alertSort.key]) {
    const byFirst = new Map(items.map((it) => [it.first, it]));
    items = sortAlerts(items.map((it) => it.first)).map((a) => byFirst.get(a));
  }
  fxRender('alerts');
  const narrowed = !!q || !!host || fxActive('alerts');
  if (q) moreDaysWhenSearching('alerts', d);
  if (!items.length) { el.innerHTML = `<p class="muted">${d.alerts.length ? L('Geen meldingen met dit filter.') : L('Nog geen meldingen.') + ' 👌'}</p>` + moreDays('alerts', d); $('#alerts-toggle').hidden = true; return; }
  // Nu open: bovenaan, los van de dagen
  // Ergste eerst, daarna wat het langst open staat (tenzij je zelf sorteert)
  // 1.209.0: alleen wat nog niemand bekeek; een bekeken storing staat (nog open, zonder golf) bij de dag waarop hij begon
  const fresh0 = (it) => it.open && !al2Seen(it);
  const openIt = items.filter(fresh0);
  if (!ALERT_SORTS[alertSort.key]) openIt.sort((x, y) => al2Rank(y.k) - al2Rank(x.k) || (x.first.created_at < y.first.created_at ? -1 : 1));
  const longest = openIt.reduce((m, it) => Math.max(m, Date.now() - al2Ts(it.first.created_at)), 0);
  const openHtml = openIt.length ? `<section class="al2-openblk"><div class="al2-sech al2-openh"><h3>${L('Nu open')}</h3>
      <span class="al2-sum"><span><b>${openIt.length}</b> ${openIt.length === 1 ? L('storing') : L('storingen')}</span><span>${esc(L('langste {t}', { t: al2Dur(longest) }))}</span></span></div>
      ${al2List(openIt, d.today, layout)}</section>` : '';
  const byDay = new Map();
  items.filter((it) => !fresh0(it)).forEach((it) => {
    const day = it.first.created_at.slice(0, 10);
    if (!byDay.has(day)) byDay.set(day, []);
    byDay.get(day).push(it);
  });
  const nAlerts = (list) => list.reduce((n, it) => n + it.alerts.length, 0);
  // Vandaag altijd open (1.78.0); de andere dagen dicht, met zoeken of een filter de dagen met treffers open
  const fresh = todayFresh('alerts', alertDayOpen, d.today, nAlerts(byDay.get(d.today) || []));
  const isOpen = (day) => alertDayOpen[day] ?? (day === d.today || narrowed);
  el.innerHTML = openHtml + (!narrowed && !byDay.has(d.today) && !openIt.length ? todayEmpty(d.today, L('geen meldingen') + ' 👌') : '') + [...byDay].map(([day, list]) => `
    <details class="alert-day al2-day" data-day="${day}" ${isOpen(day) ? 'open' : ''} ${fresh && day === d.today ? 'data-fresh="1"' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts al2-dcs">${al2DayCounts(list)}</span>
        <span class="muted small ad-total">${nAlerts(list) === 1 ? L('1 melding') : L('{n} meldingen', { n: nAlerts(list) })}</span></summary>
      <div class="ad-body">${isOpen(day) ? al2List(list, d.today, layout) : ''}</div>
    </details>`).join('') + moreDays('alerts', d);
  // Pas bij openen opbouwen: snel, ook met duizenden meldingen
  $$('.alert-day', el).forEach((det) => (det.ontoggle = () => {
    alertDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = al2List(byDay.get(det.dataset.day), d.today, layout);
    alertToggleLabel();
  }));
  $('#alerts-toggle').hidden = byDay.size < 2;
  alertToggleLabel();
  // Het zijpaneel blijft bij dezelfde regel
  if (al2Sel) {
    const r = $(`#alerts .al2-row[data-aid="${al2Sel}"]`);
    if (r) r.classList.add('al2-sel');
  }
}

/** Onderaan Meldingen en IDS: ouder laden (1.119.0) */
function moreDays(key, d) {
  if (!d.max_days || d.days >= d.max_days) return '';
  return `<div class="more-days"><span class="muted small">${L('De laatste {n} dagen', { n: d.days })}</span>
    <button type="button" class="btn" data-more-days="${key}">${L('Ouder laden (tot {n} dagen)', { n: d.max_days })}</button></div>`;
}
/** Zoeken: dan alles ophalen, ook wat ouder is */
function moreDaysWhenSearching(key, d) {
  if (d.max_days && d.days < d.max_days && histDays[key] < d.max_days) loadMoreDays(key, d.max_days);
}
function loadMoreDays(key, days) {
  histDays[key] = days;
  return key === 'alerts' ? loadAlerts() : loadIds();
}
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-more-days]');
  if (!b) return;
  const d = b.dataset.moreDays === 'alerts' ? alertData : idsData;
  b.disabled = true;
  b.textContent = L('Laden…');
  loadMoreDays(b.dataset.moreDays, d.max_days);
});

function saveAlertSort() {
  uiSortSet('alert_sort', 'rzmon-alert-sort', alertSort);
  renderAlerts();
}

function alertToggleLabel() {
  const days = $$('#alerts .alert-day');
  $('#alerts-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

