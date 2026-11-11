// ---------------------------------------------------------------- Popup van een check
const ST_ICON = ['✅', '🟠', '🔴', '⚪'];
/** "37 min", "4 u 47 min", "3 d 2 u" */
function durText(sec) {
  sec = Math.max(0, Math.round(sec));
  if (sec < 60) return `${sec} s`;
  const m = Math.floor(sec / 60), h = Math.floor(m / 60), d = Math.floor(h / 24);
  return d ? `${d} ${L('d')}${h % 24 ? ` ${h % 24} ${L('u')}` : ''}` : h ? `${h} ${L('u')}${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`;
}
const fmtPct = (v) => `${(+v).toLocaleString(LOCALE, { maximumFractionDigits: 1 })}%`;
const toTime = (dt) => new Date(dt.replace(' ', 'T')).getTime();
/** Datum en tijd kort: "14:02" vandaag, anders "di 14 okt 14:02" */
function whenText(dt) {
  const d = new Date(dt.replace(' ', 'T'));
  const today = new Date().toDateString() === d.toDateString();
  return (today ? '' : d.toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' }) + ' ') + dt.slice(11, 16);
}

async function checkInfo(id, live = false, more = null) {
  let r;
  if (more === null) more = live && $('#modal').dataset.ciMore === String(id);
  try { r = await api('check_info', null, more ? { id, more: 1 } : { id }); } catch (ex) { return live ? null : toast(ex.message, 'err'); }
  // Live (1.109.0): alleen bijwerken als dit venster nog open is voor deze check
  if (live && !($('#modal').open && $('#modal').dataset.live === 'check:' + id)) return;
  const c = r.check;
  const [sc, h] = findCheck(c.id);   // dashboardgegevens (onderhoud, bevestigd) als die er zijn
  const now = toTime(r.now);
  const t = TYPES[c.type] || { label: c.type };
  const st = +c.status;
  const problem = st !== 0 && +c.enabled && !+c.info_only;   // een info-check is nooit een storing
  const unit = t.unit || '';
  const rows = [
    ['Type', `${esc(t.label)}${c.target ? ` · <code>${esc(c.target)}</code>` : ''}`],
    ['Interval', fmtInterval(c.interval_sec)],
    ...(unit && (c.warn_threshold !== null || c.crit_threshold !== null) ? [[r.smart && r.smart.mode !== 'manual' ? L('Vaste drempels') : L('Drempels'), `${t.low ? L('onder') + ' ' : ''}${c.warn_threshold ?? '–'} / ${c.crit_threshold ?? '–'} ${esc(L(unit))}`
      + (r.smart && r.smart.mode !== 'manual' ? ` <span class="muted small">(${r.smart.days >= r.smart.min_days ? L('niet in gebruik: slimme drempels') : L('in gebruik tot RzSense genoeg geleerd heeft')})</span>` : '')]] : []),
    [L('Laatste check'), c.last_checked ? ago(c.last_checked) + (c.run_ms !== null && c.run_ms !== undefined ? ` · ${L('duurde {n} ms', { n: (+c.run_ms).toLocaleString(LOCALE) })}` : '') : L('nog niet')],
    [L('Beschikbaar'), r.uptime.d1 === null ? `<span class="muted">${L('nog geen metingen')}</span>` : `${L('24 u')} <b>${fmtPct(r.uptime.d1)}</b> · ${L('7 d')} <b>${fmtPct(r.uptime.d7)}</b>`],
  ];
  const tags = [
    c.notify ? '🔔 ' + L('eigen meldregels') : '🔔 ' + L('algemene meldregels'),
    (() => { const q = c.quiet_eff; return q ? (q.never ? '🌙✕ ' + L('nooit stil') : `${esc(q.icon || '🌙')} ${esc(q.name)} (${QUIET_SRC()[q.src] || q.src})`) : ''; })(),   // 1.189.0
    c.reverse_ok != null && REVERSIBLE.includes(c.type) ? '⇄ ' + L('omgekeerd: slaagt = {a}, mislukt = {b}', { a: STATUS_WORD[c.reverse_ok], b: STATUS_WORD[c.reverse_fail ?? 0] }) : '',
    sc && sc.maintenance ? `🔧 ${esc(maintLabel(sc.maintenance))}` : '',
    c.acked_at ? '✔ ' + L('bevestigd door {who}', { who: esc(c.acked_by || '?') }) : '',
    !+c.enabled ? '⏸ ' + L('uitgeschakeld') : '',
  ].filter(Boolean);
  const changes = r.changes.map((ch, i) => {
    const sent = ch.alerts.filter((a) => a.kind !== 'ack' && a.kind !== 'info');
    const acks = ch.alerts.filter((a) => a.kind === 'ack');
    const dur = ((ch.ended_at ? toTime(ch.ended_at) : now) - toTime(ch.created_at)) / 1000;
    const why = sent.length ? sent.map((a) => `📨 ${L('gemeld {t}', { t: esc(whenText(a.created_at)) })} · ${channelIcons(a)}`).join('<br>')
      : ch.note ? `<span class="muted">${L('niet gemeld: {note}', { note: esc(ch.note) })}</span>`
      : i === 0 && +ch.to_status !== 0 ? `<span class="muted">${L('nog niet gemeld (wacht op de meldregel, of stille uren)')}</span>`
      : `<span class="muted">${+ch.to_status === 0 ? L('niet gemeld') : L('niet gemeld (te kort, flapt, stille uren of de meldregel staat uit)')}</span>`;
    return `<li class="ci-change s${+ch.to_status}">
      <div class="ci-ch-head"><span class="ci-when" title="${esc(ch.created_at)}">${esc(whenText(ch.created_at))}</span>
        <b>${ST_ICON[+ch.from_status]} ${STATUS[+ch.from_status]} → ${ST_ICON[+ch.to_status]} ${STATUS[+ch.to_status]}</b>
        <span class="muted small">${ch.ended_at ? L('duurde') : L('duurt nu')} ${durText(dur)}</span></div>
      <div class="ci-msg">${esc(ch.message || '–')}</div>
      <div class="small">${why}${acks.length ? `<br>✔ ${L('bevestigd {t}', { t: esc(whenText(acks[0].created_at)) })}` : ''}</div></li>`;
  }).join('');
  const since = c.status_since ? ` ${L('sinds {t}', { t: esc(whenText(c.status_since)) })} (${durText((now - toTime(c.status_since)) / 1000)})` : '';
  const close = () => $('#modal').close();
  const btns = [
    ...(aiOn() && (problem || aiHas('check', c.id)) && (state.ai.ask || aiHas('check', c.id)) ? [{ label: '🤖 ' + L('AI-advies'), onClick: () => aiOpen('check', c.id) }] : []),
    ...(problem && !c.acked_at ? [{ label: '✔ ' + L('Bevestigen'), cls: 'need-user', onClick: () => ackDialog('check', c.id, `${c.host_name} – ${c.name}`, c.last_message) }] : []),
    { label: '🔧 ' + L('Onderhoud'), cls: 'need-user', onClick: () => maintenanceDialog('check', c.id, `${c.host_name} – ${c.name}`, sc && sc.maintenance && sc.maintenance.scope === 'check' ? sc.maintenance : null) },
    { label: '↻ ' + L('Nu checken'), cls: 'need-user', onClick: async (btn) => {
      btn.disabled = true;
      try { await api('check_run', { id: c.id }); await refresh(); checkInfo(c.id); } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
    } },
    ...(r.smart && r.smart.mode === 'manual' && r.smart.days >= r.smart.min_days ? [{ label: '🧠 ' + L('Slim met vangnet'), cls: 'need-admin', onClick: async (btn) => {
      btn.disabled = true;
      try { await api('smart_set', { ids: [c.id], mode: 'guard' }); toast(L('Deze check gebruikt nu slimme drempels met vangnet')); await refresh(); checkInfo(c.id); } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
    } }] : []),
    ...(sc ? [{ label: '📈 ' + L('Grafiek'), onClick: () => { close(); showHistory(sc, h); } }] : []),
    ...(sc ? [{ label: '✎ ' + L('Bewerken'), cls: 'need-admin', onClick: () => checkForm(sc, h) }] : []),
  ];
  // ---- 1.176.0: de nieuwe popup (status, 24 uur, beschikbaarheid, details, melden, samenhang, AI, instellingen, tijdlijn) ----
  const x = r.extra || {};
  const cnt = x.counts || {}, out = x.outages || {}, nt = x.notify || {};
  const n0 = (v) => (+v || 0).toLocaleString(LOCALE);
  const sec = (title, body, cls = '') => `<section class="ci2-sec ${cls}"><h4 class="ci2-sh">${title}</h4>${body}</section>`;
  const kv = (list) => `<dl class="ci2-kv">${list.filter(Boolean).map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>`;
  // 1. status nu, met meter als er een waarde en drempels zijn
  const hasVal = c.last_value !== null && c.last_value !== undefined && unit;
  const w = c.warn_threshold !== null ? +c.warn_threshold : null, cr = c.crit_threshold !== null ? +c.crit_threshold : null;
  let meter = '';
  if (hasVal) {
    const [vv, uu] = keyValue(c);
    const top = unit === '%' ? 100 : Math.max(+c.last_value, cr ?? 0, w ?? 0) * 1.15 || 1;
    const p = (v) => Math.max(0, Math.min(100, v / top * 100));
    const zones = !t.low && w !== null && cr !== null ? `background:linear-gradient(90deg,var(--ok) 0 ${p(w)}%,var(--warn) ${p(w)}% ${p(cr)}%,var(--crit) ${p(cr)}% 100%)` : '';
    meter = `<div class="ci2-meter"><div class="ci2-mv"><span class="muted small">${L('Huidige waarde')}</span><b class="mono">${vv}<small>${esc(uu)}</small></b></div>
      ${zones ? `<div class="ci2-mbar" style="${zones}"><i style="left:${p(+c.last_value)}%"></i></div>
      <div class="ci2-mtk mono"><span style="left:0">0</span><span style="left:${p(w)}%">${dec(String(w))}</span><span style="left:${p(cr)}%">${dec(String(cr))}</span></div>` : ''}</div>`;
  } else if (ctOf(c)) meter = `<div class="ci2-meter ci2-mct">${ctLabel(c.cstate, true)}</div>`;
  const hero = `<div class="ci2-hero s${st}">
    <div class="ci2-hl"><div class="ci2-st"><i class="ci2-pulse"></i><b>${STATUS[st]}</b>${c.status_since ? `<span class="mono muted small">${L('sinds {t}', { t: esc(whenText(c.status_since)) })}</span>` : ''}</div>
      <div class="ci2-msg">${esc(c.last_message || '–')}</div>
      ${c.status_since && st !== 0 ? `<span class="ci2-dur mono">${L('duurt al {d}', { d: durText((now - toTime(c.status_since)) / 1000) })}</span>` : ''}
      ${ciFlow(r.flow, sc ? Object.assign({}, c, { maintenance: sc.maintenance }) : c)}</div>
    ${meter}</div>`;
  // 2. grafiek van 24 uur (geladen in de achtergrond)
  // 1.191.1: live bijwerken laat de huidige inhoud staan (geen "laden…" tussendoor); daarna morpht alleen wat veranderde
  const keep = (sel) => (live && $(sel) && $(sel).innerHTML.trim()) || `<p class="muted small">${L('laden…')}</p>`;
  const chart = unit ? sec(L('Laatste 24 uur'), `<div class="ci2-chart" id="ci2-chart" data-morph>${keep('#ci2-chart')}</div>`) : '';
  // 3. beschikbaarheid en hoe vaak gecheckt
  const C = 2 * Math.PI * 24;
  const ringOf = (lbl, v) => `<div class="ci2-ring"><svg width="58" height="58" viewBox="0 0 58 58" role="img" aria-label="${esc(lbl)} ${v === null ? '–' : fmtPct(v)}"><circle cx="29" cy="29" r="24" class="bg"/>${v === null ? '' : `<circle cx="29" cy="29" r="24" class="fg${v < 99 ? ' bad' : ''}" stroke-dasharray="${(C * v / 100).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 29 29)"/>`}<text x="29" y="33" text-anchor="middle">${v === null ? '–' : (v >= 99.95 ? '100' : dec(v.toFixed(v >= 99 ? 1 : 0)))}%</text></svg><span>${lbl}</span></div>`;
  const avail = sec(L('Beschikbaarheid'), `<div class="ci2-rings">${ringOf(L('24 uur'), r.uptime.d1)}${ringOf(L('7 dagen'), r.uptime.d7)}${ringOf(L('30 dagen'), out.up30 ?? null)}
      ${kv([[L('Storingen 30 d'), `<b>${out.count || 0}</b>`], [L('Gem. hersteltijd'), out.mttr ? durText(out.mttr) : '–'], [L('Langste storing'), out.longest ? durText(out.longest) : '–']])}</div>
    <div class="ci2-counts">
      <div><span>${L('Gecheckt 24 u')}</span><b class="mono">${n0(cnt.n24)}×</b>${cnt.f24 ? `<em class="bad">· ${L('{n} mislukt', { n: n0(cnt.f24) })}</em>` : ''}</div>
      <div><span>${L('Gecheckt 7 d')}</span><b class="mono">${n0(cnt.n7)}×</b>${cnt.f7 ? `<em class="bad">· ${L('{n} mislukt', { n: n0(cnt.f7) })}</em>` : ''}</div>
      <div><span>${cnt.since ? L('Sinds {d}', { d: esc(new Date(cnt.since.replace(' ', 'T')).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' })) }) : L('Totaal bewaard')}</span><b class="mono">${n0(cnt.total)}×</b>${cnt.total ? `<em class="${cnt.total_fail ? 'warn' : 'good'}">· ${fmtPct(Math.round((1 - cnt.total_fail / cnt.total) * 1000) / 10)} ${L('goed')}</em>` : ''}</div>
    </div>`);
  // 4. details per soort (container, disk, certificaat), anders de basis
  const d = x.detail;
  let det;
  if (d && d.kind === 'container') {
    det = ([[L('Status'), `${ctLabel(d.state)} <span class="muted small">${esc(d.raw_state)}${d.state !== 'running' ? ` · exitcode ${d.exit_code}` : ''}</span>`],
      d.state === 'failed' && ctExit(d.exit_code) ? [L('Betekent'), esc(ctExit(d.exit_code))] : null,
      [L('Image'), `<code>${esc(d.image || '–')}</code>`], d.health ? [L('Health'), esc(d.health)] : null,
      [L('Herstarts'), `<b class="${d.restarts ? 'ct-bad' : ''}">${d.restarts}×</b>`],
      d.started ? [d.state === 'running' ? L('Draait sinds') : L('Laatst gestart'), esc(new Date(d.started * 1000).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' }))] : null,
      d.cpu !== null ? ['CPU', `${dec(String(d.cpu))}%`] : null,
      d.mem !== null ? [L('Geheugen'), `${fmtBytes(d.mem)}${d.mem_limit && d.mem_limit < 1e12 ? ' / ' + fmtBytes(d.mem_limit) : ''}`] : null,
      [L('Schijf'), `${fmtBytes(d.size_rw)}${d.volumes ? ' + ' + L('volumes {n}', { n: fmtBytes(d.volumes) }) : ''}`],
      d.pod ? ['Pod', esc(d.pod)] : null]);
  } else if (d && d.kind === 'disk') {
    det = ([[L('Gebruikt'), `<b>${dec(String(Math.round(d.pct * 10) / 10))}%</b>`],
      [L('Groei'), d.per_day !== null ? `${d.per_day >= 0 ? '+' : ''}${dec(String(Math.round(d.per_day * 100) / 100))} ${L('procentpunt per dag')}` : `<span class="muted">${L('nog te weinig gegevens')}</span>`],
      [L('Vol over'), d.days_to_full !== null && d.days_to_full !== undefined ? `<b class="${d.days_to_full < 14 ? 'ct-bad' : ''}">± ${Math.round(d.days_to_full)} ${L('dagen')}</b>` : `<span class="muted">${L('niet in zicht')}</span>`]]);
  } else if (d && d.kind === 'db') {
    // 1.200.0: database van binnen: versie, verbindingen, cache, replicatie, grootte en lopende query's
    const m = d.m || {}, cl = d.calc || {}, r = m.repl;
    const sizes = Object.entries(m.sizes || {}).slice(0, 5);
    det = [[L('Database'), `<b>${esc(m.version || (m.engine === 'pgsql' ? 'PostgreSQL' : 'MariaDB/MySQL'))}</b> <span class="muted small">${d.via === 'agent' ? (d.where === 'container' ? L('via de agent, container {c}', { c: esc(d.container) }) : L('via de agent, lokaal')) : L('RzSense maakt verbinding')}</span>`],
      m.uptime !== null && m.uptime !== undefined ? [L('Draait'), esc(durText(m.uptime))] : null,
      m.conn !== null && m.conn !== undefined ? [L('Verbindingen'), `<b class="${m.conn_pct >= 90 ? 'ct-bad' : ''}">${m.conn} / ${m.max_conn}</b> <span class="muted small">(${dec(String(m.conn_pct ?? 0))}%) · ${L('{n} actief', { n: m.active ?? 0 })}</span>`] : null,
      cl.qps !== null && cl.qps !== undefined ? [L('Query\'s per seconde'), dec(String(cl.qps))] : null,
      cl.cache !== null && cl.cache !== undefined ? [L('Cache-hit'), `<b class="${cl.cache < 90 ? 'ct-bad' : ''}">${dec(String(cl.cache))}%</b>`] : null,
      r ? [L('Replicatie'), r.role === 'primary' ? L('primair, {n} replica\'s', { n: r.replicas }) : (!r.io || !r.sql ? `<b class="ct-bad">${L('staat stil')}</b> ${esc(r.err || '')}` : r.lag !== null ? L('replica, {s} s achter', { s: r.lag }) : L('replica'))] : null,
      m.galera ? ['Galera', `${esc(m.galera.status)} · ${L('{n} nodes', { n: m.galera.size })}`] : null,
      m.wrap_pct !== null && m.wrap_pct !== undefined ? [L('Wraparound'), `<b class="${m.wrap_pct >= 50 ? 'ct-bad' : ''}">${dec(String(m.wrap_pct))}%</b>`] : null,
      m.idle_tx ? [L('Idle in transaction'), `<b class="ct-bad">${m.idle_tx}</b>`] : null,
      cl.slow_n || cl.deadlocks ? [L('Sinds de vorige check'), [cl.slow_n ? L('{n} trage query\'s', { n: cl.slow_n }) : '', cl.deadlocks ? L('{n} deadlocks', { n: cl.deadlocks }) : ''].filter(Boolean).join(' · ')] : null,
      m.size !== null && m.size !== undefined ? [L('Grootte'), `<b>${fmtBytes(m.size)}</b>${sizes.length > 1 ? `<br><span class="muted small">${sizes.map(([k, v]) => `${esc(k)} ${fmtBytes(v)}`).join(' · ')}</span>` : ''}`] : null,
      (m.longq || []).length ? [L('Lopende query\'s'), m.longq.slice(0, 3).map((q) => `<div class="small"><b class="${q.sec >= 300 ? 'ct-bad' : ''}">${esc(durText(q.sec))}</b> <span class="muted">${esc(q.user)}${q.db ? '@' + esc(q.db) : ''}</span><br><code>${esc(q.q.slice(0, 160))}</code></div>`).join('')] : null];
  } else if (d && d.kind === 'mail') {
    // 1.227.0: e-mailbeveiliging: per punt het oordeel, en het DNS-record om te plakken
    const ic = { ok: '✔', info: 'ℹ️', warn: '⚠️', crit: '✘' };
    det = [[L('Score'), `<b class="${d.worst === 'crit' ? 'ct-bad' : ''}">${d.score}/100</b>${d.at ? ` <span class="muted small">${L('opgezocht {t}', { t: esc(new Date(d.at * 1000).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' })) })}</span>` : ''}`],
      ...d.items.map((i) => [`${ic[i.st] || ''} ${esc(i.title)}`, `${i.detail ? `<span class="small">${esc(i.detail)}</span>` : ''}${i.fix ? `<div class="mail-fix"><code>${esc(i.fix)}</code> <button type="button" class="btn small-btn" data-mail-copy="${esc(i.fix)}" title="${esc(L('Kopiëren'))}">📋</button></div>` : ''}`])];
  } else if (d && d.kind === 'days') {
    det = ([[L('Nog geldig'), `<b class="${d.days < 14 ? 'ct-bad' : ''}">${Math.floor(d.days)} ${L('dagen')}</b>`], [L('Verloopt'), esc(new Date(Date.now() + d.days * 86400000).toLocaleDateString(LOCALE, { dateStyle: 'medium' }))]]);
  } else det = rows.slice(0, 2);
  const details = sec(d ? L('Details') : L('Over deze check'), kv(d ? [...det, rows[0]] : det));
  // 5. melden
  const steps = Math.max(1, Math.min(10, nt.needed || 1));
  const done = st === 0 ? 0 : Math.min(steps, nt.streak || 0);
  const last = nt.last;
  const melden = sec(L('Melden'), `${st !== 0 ? `<div class="ci2-steps" aria-hidden="true">${Array.from({ length: steps }, (_, i) => `<i class="${i < done ? 'on s' + st : ''}"></i>`).join('')}</div>` : ''}
    ${kv([[L('Melden na'), steps === 1 ? L('de eerste mislukte check') : L('{n} mislukte checks', { n: steps }) + (st !== 0 ? ` · <b>${done >= steps ? L('bereikt') : L('{a} van {b}', { a: done, b: steps })}</b>` : '')],
      [L('Laatst gemeld'), last ? `${esc(whenText(last.created_at))} · ${channelIcons(last)}` : `<span class="muted">${L('nog nooit')}</span>`],
      nt.escalated ? [L('Escalatie'), `⏫ ${L('al geëscaleerd')}`] : nt.escalation ? [L('Escalatie'), nt.escalation.in > 0 ? L('over {d}, als niet bevestigd', { d: durText(nt.escalation.in) }) : L('bij de volgende herhaling')] : null,
      c.acked_at ? [L('Bevestigd'), `✔ ${esc(c.acked_by || '?')} · ${esc(whenText(c.acked_at))}${c.ack_comment ? `<br><i class="muted">“${esc(c.ack_comment)}”</i>` : ''}`] : null])}
    <p class="small muted ci-tags">${tags.join(' · ')}</p>`);
  // 6. samenhang: andere problemen op dezelfde server en de server erboven
  const others = h ? h.checks.filter((o) => +o.id !== +c.id && isProblem(o)).sort((a, b) => STATUS_RANK[b.status] - STATUS_RANK[a.status]).slice(0, 4) : [];
  const parent = h && h.parent_id ? findHost(h.parent_id) : null;
  const pDown = parent && ((state.health && state.health.down) || []).some((q) => +q.id === +parent.id);
  const samen = sec(L('Samenhang'), `${others.length ? `<p class="small muted">${L('Ook niet in orde op {h}, mogelijk dezelfde oorzaak', { h: esc(c.host_name) })}</p>
      <div class="ci2-rel">${others.map((o) => `<button type="button" class="ci2-ro s${o.status}" data-ci-open="${o.id}"><i></i><b>${esc(o.name)}</b><span>${esc((o.last_message || '').slice(0, 90))}</span>${ctOf(o) || `<em>${STATUS[+o.status]}</em>`}</button>`).join('')}</div>`
      : `<p class="small muted">${L('Geen andere problemen op {h}.', { h: esc(c.host_name) })}</p>`}
    ${parent ? `<p class="small">${L('Hangt af van')}: <b>${esc(parent.name)}</b> <span class="${pDown ? 'ct-bad' : 'ci2-ok'}">· ${pDown ? L('onbereikbaar') : L('bereikbaar')}</span></p>` : ''}`);
  // 7. laatste AI-advies
  const ai = x.ai;
  const VERD = { real: [L('Echt probleem'), 'bad'], suspicious: [L('Verdacht'), 'warn'], benign: [L('Onschuldig'), 'good'] };
  const aiSec = ai && aiOn() ? sec(L('Laatste AI-advies') + ` <span class="muted small">${esc(whenText(ai.at))} · ${esc(ai.model || ai.provider)}${ai.cost ? ' · ' + esc(aiMoney(ai.cost)) : ''}</span>`,
    `<div class="ci2-ai">${ai.verdict && VERD[ai.verdict] ? `<span class="ci2-verd ${VERD[ai.verdict][1]}">${VERD[ai.verdict][0]}</span>` : ''}<span>${esc(ai.summary || L('Zie het volledige advies.'))}</span>
      <button type="button" class="btn small-btn" data-ci-ai="${c.id}">${L('Volledig advies')}</button></div>`, 'ci2-aisec') : '';
  // 8. instellingen
  const setRows = [rows[1], rows[2] && rows[2][0] !== L('Laatste check') ? rows[2] : null,
    r.smart && r.smart.mode !== 'manual' ? [L('Drempels'), esc(SMART_MODES[r.smart.mode] || '')] : null,
    [L('Melden na'), steps === 1 ? L('meteen') : L('{n} mislukte checks', { n: steps })],
    [L('Laatste check'), rows.find((q) => q[0] === L('Laatste check'))[1]],
    x.changed ? [L('Gewijzigd'), `${esc(whenText(x.changed.at))} ${L('door {u}', { u: esc(x.changed.username || '?') })}`] : null];
  const inst = sec(L('Instellingen'), kv(setRows));
  // 9. tijdlijn van statuswijzigingen
  const tl = r.changes.map((ch) => {
    const sent = ch.alerts.filter((a) => a.kind !== 'ack' && a.kind !== 'info');
    const acks = ch.alerts.filter((a) => a.kind === 'ack');
    const dd = ((ch.ended_at ? toTime(ch.ended_at) : now) - toTime(ch.created_at)) / 1000;
    const why = sent.length ? `${L('gemeld {t}', { t: esc(whenText(sent[0].created_at)) })} · ${channelIcons(sent[0])}` : ch.note ? L('niet gemeld: {note}', { note: esc(ch.note) }) : +ch.to_status === 0 ? '' : L('niet gemeld');
    return `<li class="s${+ch.to_status}"><i class="ci2-dot"></i><div><div class="ci2-th"><span class="mono muted small">${esc(whenText(ch.created_at))}</span><b>${STATUS[+ch.from_status]} → ${STATUS[+ch.to_status]}</b><span class="muted small">${ch.ended_at ? L('duurde') : L('duurt nu')} ${durText(dd)}</span></div>
      <div class="small">${esc((ch.message || '–').slice(0, 160))}</div>${why || acks.length ? `<div class="muted small">${why}${acks.length ? ` · ✔ ${L('bevestigd {t}', { t: esc(whenText(acks[0].created_at)) })}` : ''}</div>` : ''}</div></li>`;
  }).join('');
  const moreBtn = (r.changes_total || 0) > r.changes.length ? `<button type="button" class="btn small-btn ci2-more" data-ci-more="${c.id}">＋ ${L('{n} eerdere tonen', { n: Math.min(25, r.changes_total) - r.changes.length })}</button>` : '';
  const tlSec = sec(L('Statuswijzigingen'), tl ? `<ol class="ci2-tl">${tl}</ol>${moreBtn}` : `<p class="muted small">${L('Nog geen statuswijzigingen bewaard.')}</p>`);
  const pathSec = c.type === 'path' ? `<div id="ci2-path" data-morph>${keep('#ci2-path')}</div>` : '';   // 1.177.0
  const ciBody = `<div class="ci2" data-motion="${afMotion(null)}">${hero}${pathSec}${chart}
      <div class="ci2-g2">${avail}${details}</div>
      <div class="ci2-g2">${melden}${samen}</div>
      ${aiSec}
      <div class="ci2-g2 ci2-g2b">${inst}${tlSec}</div>
      ${r.smart ? `<section class="ci2-sec">${smartBlock(r.smart, c)}</section>` : ''}
    </div>`;
  const title = `${ST_ICON[st]} ${c.name} – ${c.host_name}`;
  if (unit) setTimeout(() => ciChart(c, t), 0);
  if (c.type === 'path') setTimeout(() => npLoadInfo(c.id), 0);
  if (live) {
    $('#modal-title').textContent = title;
    withMorph(() => { $('#modal-body').innerHTML = ciBody; });
    // Knoppen alleen opnieuw als er een bij kwam of af ging (bv. Bevestigen), anders blijven ze staan
    const sig = btns.map((b) => b.label).join('|');
    if ($('#modal').dataset.btns === sig) return;
  }
  modal({ title, wide: true, body: ciBody, buttons: btns });
  // 1.227.0: e-mailbeveiliging: het DNS-record kopiëren
  $('#modal-body').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-mail-copy]');
    if (!b) return;
    try { await navigator.clipboard.writeText(b.dataset.mailCopy); toast(L('Gekopieerd')); } catch (ex) { toast(L('Kopiëren lukte niet'), 'err'); }
  }, { signal: modalSignal() });
  $('#modal').dataset.live = 'check:' + id;
  $('#modal').dataset.ciMore = more ? String(id) : '';
  if (!live) { const mb = $('#modal-body'); if (mb && !more) mb.scrollTop = 0; }
  $('#modal').dataset.btns = btns.map((b) => b.label).join('|');
}

/** 1.176.0: grafiek van 24 uur in de popup: waarde, geleerde band, drempels en de statuswissels als stip */
const ciChartCache = new Map();
async function ciChart(c, t) {
  let d = ciChartCache.get(c.id);
  if (!d || Date.now() - d.at > 60000) {
    try { d = { at: Date.now(), r: await api('history', null, { check_id: c.id, hours: 24 }) }; ciChartCache.set(c.id, d); } catch { d = null; }
  }
  const el = $('#ci2-chart');
  if (!el || !d) return;
  const pts = (d.r.points || []).filter((p) => p.v !== null && p.v !== undefined);
  if (pts.length < 2) { el.innerHTML = `<p class="muted small">${L('Nog te weinig metingen voor een grafiek.')}</p>`; return; }
  const W = 600, H = 130, t0 = pts[0].t, t1 = pts[pts.length - 1].t;
  const band = d.r.band;
  const w = c.warn_threshold !== null ? +c.warn_threshold : null, cr = c.crit_threshold !== null ? +c.crit_threshold : null;
  const vals = pts.map((p) => +p.v).concat(band ? band.flatMap((b) => [b[0], b[1]]).filter((v) => v !== null) : []);
  let lo = Math.min(...vals), hi = Math.max(...vals);
  if (t.unit === '%') { lo = Math.min(lo, 0); hi = Math.max(hi, 100); }
  [w, cr].forEach((v) => { if (v !== null && v <= hi * 1.4 && v >= lo - Math.abs(hi - lo)) { hi = Math.max(hi, v); lo = Math.min(lo, v); } });
  if (hi === lo) hi = lo + 1;
  const pad = (hi - lo) * .08;
  const X = (tt) => ((tt - t0) / Math.max(1, t1 - t0) * W).toFixed(1), Y = (v) => (H - 6 - ((v - (lo - pad)) / ((hi + pad) - (lo - pad))) * (H - 12)).toFixed(1);
  const line = pts.map((p) => `${X(p.t)},${Y(+p.v)}`).join(' ');
  const area = `M${X(t0)},${H} L${pts.map((p) => `${X(p.t)},${Y(+p.v)}`).join(' L')} L${X(t1)},${H} Z`;
  let bandPath = '';
  if (band && band.length === (d.r.points || []).length) {
    const all = d.r.points.map((p, i) => [p.t, band[i]]).filter(([, b]) => b && b[0] !== null && b[1] !== null);
    if (all.length > 1) bandPath = `<path class="ci2-band" d="M${all.map(([tt, b]) => `${X(tt)},${Y(b[1])}`).join(' L')} L${all.reverse().map(([tt, b]) => `${X(tt)},${Y(b[0])}`).join(' L')} Z"/>`;
  }
  const thr = (v, cls) => (v !== null && v >= lo - pad && v <= hi + pad ? `<line class="${cls}" x1="0" x2="${W}" y1="${Y(v)}" y2="${Y(v)}"/>` : '');
  const dots = [];
  for (let i = 1; i < pts.length; i++) if (+pts[i].s !== +pts[i - 1].s) dots.push(`<circle class="ci2-cd s${+pts[i].s}" cx="${X(pts[i].t)}" cy="${Y(+pts[i].v)}" r="4.5"/>`);
  const lab = (tt) => new Date(tt * 1000).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  const leg = [`<span><i class="lg-v"></i>${L('waarde')}</span>`, bandPath ? `<span><i class="lg-b"></i>${L('geleerde band')}</span>` : '', w !== null ? `<span><i class="lg-w"></i>warning</span>` : '', cr !== null ? `<span><i class="lg-c"></i>critical</span>` : ''].join('');
  el.innerHTML = `<div class="ci2-leg small">${leg}</div><svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" class="ci2-svg" role="img" aria-label="${esc(L('Verloop van de laatste 24 uur'))}">${bandPath}
    ${thr(w, 'ci2-tw')}${thr(cr, 'ci2-tc')}<path class="ci2-area" d="${area}"/><polyline class="ci2-line" points="${line}"/>${dots.join('')}</svg>
    <div class="ci2-ax mono small"><span>${lab(t0)}</span><span>${lab(t0 + (t1 - t0) / 2)}</span><span>${L('nu')}</span></div>`;
}
document.addEventListener('click', (e) => {
  const m = e.target.closest('#modal [data-ci-more], #modal [data-ci-open], #modal [data-ci-ai]');
  if (!m) return;
  if (m.dataset.ciMore) return checkInfo(+m.dataset.ciMore, false, true);
  if (m.dataset.ciOpen) return checkInfo(+m.dataset.ciOpen);
  if (m.dataset.ciAi) return aiOpen('check', +m.dataset.ciAi);
});

/**
 * 🧠 Slimme drempels in de popup (1.107.0): modus, wat er nu geldt, de week als grafiekje (normale band en warning per uur),
 * en hoeveel dagen met een warning het met vaste en met slimme drempels was.
 */
function smartBlock(m, c) {
  const u = m.unit === '%' || m.unit === '°C' ? m.unit : m.unit ? ' ' + m.unit : '';
  const n = (v) => (v == null ? '–' : dec(Math.abs(v) >= 10 ? Math.round(v) : Math.round(v * 10) / 10));
  const days = [L('ma'), L('di'), L('wo'), L('do'), L('vr'), L('za'), L('zo')];
  const ready = m.days >= m.min_days && m.w;
  const guard = c.thresh_mode === 'guard' && c.guard_threshold != null ? +c.guard_threshold : null;
  let head = `<b>${SMART_MODES[m.mode] || SMART_MODES.guard}</b>`;
  if (m.mode !== 'manual' && !ready) head += ` · ${L('leert nog ({d}/{n} dagen); tot die tijd gelden de vaste drempels', { d: m.days, n: m.min_days })}`;
  let now = '';
  if (ready) {
    const h = m.how;
    let w = m.w[h], cr = m.c[h];
    if (guard !== null) { cr = Math.min(cr, guard); w = Math.min(w, cr); }
    now = `<p class="small">${L('Nu ({d} {h}:00): normaal {lo}–{hi}{u}', { d: days[Math.floor(h / 24)], h: h % 24, lo: n(m.lo[h]), hi: n(m.hi[h]), u })}`
      + (m.mode === 'manual' ? ` · ${L('slim zou zijn: warning vanaf {w}{u}, critical vanaf {c}{u}', { w: n(w), c: n(cr), u })}` : ` · ${L('warning vanaf {w}{u}, critical vanaf {c}{u}', { w: n(w), c: n(cr), u })}`)
      + (guard !== null ? ` · 🛡️ ${L('altijd critical vanaf {g}{u}', { g: n(guard), u })}` : '') + '</p>';
  }
  let sim = m.sim && ready && m.sim.fixed != null ? `<p class="small">${L('Afgelopen {d} dagen: met vaste drempels op {f} dag(en) een warning, met slimme drempels op {s}.', { d: m.sim.days, f: m.sim.fixed, s: m.sim.smart })}`
    + (m.sim.need > 1 ? ' ' + L('Geteld zoals een melding: {n} metingen achter elkaar te hoog.', { n: m.sim.need }) : '') + '</p>' : '';
  // 1.108.1: grillige check (piekt bijna elke dag even) → tip
  if (m.sim && ready && m.sim.spiky) {
    const net = ['http', 'tcp'].includes(c.type);
    sim += `<p class="small smart-tip">💡 ${m.sim.need >= 3
      ? L('Deze check piekt bijna elke dag even. Met melden na {n} wordt dat geen melding: dat is goed zo.', { n: m.sim.need })
      : L('Deze check piekt bijna elke dag even, maar dat houdt meestal niet aan. Slimme drempels helpen daar weinig; zet hem op melden na 3 (✎ → 🔔 Meldingen → Melden na … mislukte checks).')
        + (net ? ' ' + L('Gaat het je om de bereikbaarheid, dan is een ping-check betrouwbaarder dan de responstijd.') : '')}</p>`;
  }
  const drift = m.drift ? `<p class="small warn-txt">⚠️ ${L('De normaalwaarde stijgt: van {a}{u} naar {b}{u} in twee weken. Zolang dat zo is, schuiven de drempels niet mee omhoog.', { a: n(m.drift[0]), b: n(m.drift[1]), u })}</p>` : '';
  return `<div class="smart-box"><h3 class="ci-h">🧠 ${L('Drempels')}</h3><p class="small">${head}</p>${now}${ready ? smartWeek(m, guard, u) : ''}${sim}${drift}</div>`;
}

/** De week (168 uur): band 10e–95e percentiel, warning als lijn; critical boven de grafiek valt weg */
function smartWeek(m, guard, u) {
  const W = 672, H = 80, top = 4, bot = H - 2;
  const ws = m.w.map((w, i) => (guard !== null ? Math.min(w, guard) : w));
  const hi = Math.max(...m.hi, 1) * 1.35;
  const cap = Math.max(hi, Math.min(Math.max(...ws), hi * 1.6));
  const x = (i) => (i / 167) * W;
  const y = (v) => bot - Math.min(v, cap) / cap * (bot - top);
  const band = m.hi.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ') + ' ' + m.lo.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).reverse().join(' ');
  const wl = ws.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`).join('');
  const days = [L('ma'), L('di'), L('wo'), L('do'), L('vr'), L('za'), L('zo')];
  const hits = m.w.map((_, i) => `<rect x="${(x(i) - 2).toFixed(1)}" y="0" width="4" height="${bot}" fill="transparent"><title>${days[Math.floor(i / 24)]} ${i % 24}:00 · ${L('normaal')} ${dec(Math.round(m.lo[i]))}–${dec(Math.round(m.hi[i]))}${u} · ${L('warning')} ${dec(Math.round(ws[i]))}${u}</title></rect>`).join('');
  const now = `<line x1="${x(m.how)}" x2="${x(m.how)}" y1="${top}" y2="${bot}" class="smart-now"/>`;
  return `<div class="smart-week"><svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="${esc(L('Normaal per uur van de week'))}">
    <polygon points="${band}" class="smart-band"/><path d="${wl}" class="smart-warn" vector-effect="non-scaling-stroke"/>${now}${hits}
    </svg><div class="smart-days">${days.map((d) => `<span>${d}</span>`).join('')}</div>
    <p class="small muted">${L('Grijs: normaal (10e–95e percentiel) per uur van de week. Lijn: warning. Streep: nu.')}</p></div>`;
}

/** Waarom een check wel of niet in het voorstel zit (1.108.1), in gewone woorden */
function smartWhy(p) {
  let why;
  if (p.fixed == null) why = L('nu zonder drempel: met slim wordt hij bewaakt');
  else if (p.gain > 0) why = '✔ ' + L('{n} dag(en) minder met een warning', { n: p.gain });
  else if (p.gain < 0) why = L('slim is strenger: {n} dag(en) meer met een warning', { n: -p.gain });
  else if (p.smart === 0) why = L('geen verschil: met allebei rustig');
  else why = L('geen winst: met slim even vaak een warning');
  if (p.spiky) why += ' · ' + (p.need != null && p.need < 3 ? L('grillig: piekt bijna elke dag even (tip: melden na 3)') : L('grillig: piekt bijna elke dag even'));
  if (p.need == null) why += ' · ' + L('wordt vannacht opnieuw berekend');
  return why;
}

/** Instellingen → Monitoring → Advies: handmatige checks waar slim minder warnings had gegeven */
async function loadSmartProposals() {
  const el = $('#smart-proposals');
  if (!el) return;
  let r;
  try { r = await api('smart_proposals'); } catch (ex) { el.innerHTML = `<p class="err-txt small">${esc(ex.message)}</p>`; return; }
  if (!r.list.length) { el.innerHTML = `<p class="muted small">${L('Nog geen voorstellen: RzSense heeft per check minstens 7 dagen meetwaarden nodig, of al je checks die kunnen leren staan al op slim.')}</p>`; return; }
  el.innerHTML = `<div class="table-wrap"><table class="small smart-prop"><thead><tr><th><input type="checkbox" id="smart-all" ${isAdmin() ? '' : 'disabled'}></th><th>${L('Check')}</th><th>${L('Server')}</th>
      <th title="${esc(L('Dagen met een warning, met de drempels die nu gelden'))}">${L('Dagen met warning – nu')}</th><th title="${esc(L('Dagen met een warning als de check slimme drempels had gehad'))}">${L('– met slim')}</th><th>${L('Waarom')}</th></tr></thead><tbody>
    ${r.list.map((p) => `<tr class="${p.gain > 0 ? '' : 'smart-nogain'}"><td><input type="checkbox" data-smart-id="${p.id}" ${p.gain > 0 ? 'checked' : ''} ${isAdmin() ? '' : 'disabled'}></td>
      <td><button type="button" class="link-btn" data-smart-open="${p.id}">${esc(p.name)}</button></td><td>${esc(p.host)}</td>
      <td>${p.fixed == null ? '–' : L('{n} van {d} dagen', { n: p.fixed, d: p.days })}</td><td class="${p.gain > 0 ? 'good-txt' : ''}">${L('{n} van {d} dagen', { n: p.smart, d: p.days })}</td>
      <td class="smart-why">${esc(smartWhy(p))}</td></tr>`).join('')}
    </tbody></table></div>
    <div class="btn-row need-admin" style="margin-top:.6rem"><button type="button" class="btn primary" data-smart-go="guard">🧠🛡️ ${L('Gekozen checks: slim met vangnet')}</button>
      <button type="button" class="btn" data-smart-go="smart">🧠 ${L('Slim')}</button></div>`;
  $('#smart-all').onchange = (e) => $$('[data-smart-id]', el).forEach((c) => { c.checked = e.target.checked; });
  $$('[data-smart-open]', el).forEach((b) => { b.onclick = () => checkInfo(+b.dataset.smartOpen); });
  $$('[data-smart-go]', el).forEach((b) => {
    b.onclick = async () => {
      const ids = $$('[data-smart-id]:checked', el).map((c) => +c.dataset.smartId);
      if (!ids.length) return toast(L('Kies een of meer checks'), 'err');
      try {
        const res = await api('smart_set', { ids, mode: b.dataset.smartGo });
        toast(L('{n} check(s) omgezet', { n: res.n }));
        await refresh();
        loadSmartProposals();
      } catch (ex) { toast(ex.message, 'err'); }
    };
  });
}

// ---------------------------------------------------------------- 💡 Slimme voorstellen en vaste onderhoudsvensters (1.108.0)
const ADVICE_KIND = { fails: ['🔔', L('Melden na N mislukte checks')], maint: ['🗓️', L('Vast onderhoudsvenster')], dep: ['🔗', L('Afhankelijkheid')] };
const WIN_DAYS = () => [L('elke dag'), L('maandag'), L('dinsdag'), L('woensdag'), L('donderdag'), L('vrijdag'), L('zaterdag'), L('zondag')];
async function loadAdvice() {
  const el = $('#advice-list');
  if (!el) return;
  let r;
  try { r = await api('advice_list'); } catch (ex) { el.innerHTML = `<p class="err-txt small">${esc(ex.message)}</p>`; return; }
  // 1.156.0: melden na N zelf instellen (staat buiten het instellingenformulier: meteen opslaan)
  const au = $('#alert-auto');
  if (au) {
    au.checked = !!r.auto;
    au.onchange = async () => { try { await api('settings_save', { alert_auto: au.checked ? '1' : '0' }); toast(au.checked ? L('Aangezet') : L('Uitgezet')); } catch (ex) { toast(ex.message, 'err'); au.checked = !au.checked; } };
  }
  el.innerHTML = r.list.length ? `<div class="adv-list">${r.list.map((a) => `<div class="adv-item">
      <span class="adv-ic" title="${esc(ADVICE_KIND[a.kind][1])}">${ADVICE_KIND[a.kind][0]}</span>
      <div class="adv-txt"><b>${esc(a.label)}</b><span class="small">${esc(a.text)}</span></div>
      <div class="adv-btns need-admin"><button type="button" class="btn small-btn primary" data-adv="${a.id}" data-apply="1">${L('Toepassen')}</button>
        <button type="button" class="btn small-btn" data-adv="${a.id}" data-apply="0">${L('Negeren')}</button></div></div>`).join('')}</div>`
    : `<p class="muted small">✔ ${L('Geen open voorstellen.')} ${r.last ? L('Laatst bekeken: {d}.', { d: esc(r.last) }) : L('De eerste keer vannacht (of nu met 🔄).')}</p>`;
  $$('[data-adv]', el).forEach((b) => {
    b.onclick = async () => {
      b.disabled = true;
      try { const x = await api('advice_decide', { id: +b.dataset.adv, apply: b.dataset.apply === '1' }); toast(x.message); refresh(); loadAdvice(); }
      catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
    };
  });
  // Vaste onderhoudsvensters: lijst, toevoegen, weghalen
  const days = WIN_DAYS();
  const opts = state.hosts.map((h) => `<option value="host:${h.id}">🖥️ ${esc(h.name)} (${L('hele server')})</option>${h.checks.map((c) => `<option value="check:${c.id}">&nbsp;&nbsp;${esc(h.name)} – ${esc(c.name)}</option>`).join('')}`).join('');
  $('#window-list').innerHTML = `${r.windows.length ? `<div class="table-wrap"><table class="small adv-win"><thead><tr><th>${L('Voor')}</th><th>${L('Wanneer')}</th><th>${L('Reden')}</th><th></th></tr></thead><tbody>
      ${r.windows.map((w) => `<tr><td>${w.scope === 'host' ? '🖥️ ' : ''}${esc(w.label)}</td><td>${esc(days[w.dow])} ${esc(w.start)} · ${w.minutes} min</td><td>${esc(w.reason)}</td>
        <td><button type="button" class="btn small-btn" data-win-del="${w.id}" title="${esc(L('Weghalen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>` : `<p class="muted small">${L('Nog geen vaste onderhoudsvensters.')}</p>`}
    <details class="adv-add"><summary>＋ ${L('Venster toevoegen')}</summary>
      <div class="row2"><label>${L('Voor')}<select id="win-ref">${opts}</select></label><label>${L('Wanneer')}<select id="win-dow">${days.map((d, i) => `<option value="${i}">${esc(d)}</option>`).join('')}</select></label></div>
      <div class="row3"><label>${L('Vanaf')}<input id="win-start" type="time" value="02:00"></label><label>${L('Minuten')}<input id="win-min" type="number" min="5" max="1440" value="30"></label>
        <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input id="win-reason" maxlength="200"></label></div>
      <button type="button" class="btn primary" id="win-add">${L('Toevoegen')}</button></details>`;
  $$('[data-win-del]').forEach((b) => {
    b.onclick = async () => { try { await api('window_delete', { id: +b.dataset.winDel }); toast(L('Weggehaald')); refresh(); loadAdvice(); } catch (ex) { toast(ex.message, 'err'); } };
  });
  $('#win-add').onclick = async () => {
    const [scope, ref] = $('#win-ref').value.split(':');
    try {
      await api('window_save', { scope, ref_id: +ref, dow: +$('#win-dow').value, start: $('#win-start').value, minutes: +$('#win-min').value, reason: $('#win-reason').value });
      toast(L('Vast onderhoudsvenster toegevoegd')); refresh(); loadAdvice();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- 🟢 Statuspagina (1.107.0)
async function loadStatusPage() {
  const el = $('#statuspage-body');
  if (!el) return;
  let d;
  try { d = await api('status_get'); } catch (ex) { el.innerHTML = `<p class="err-txt small">${esc(ex.message)}</p>`; return; }
  const local = d.has_base ? d.url : location.origin + location.pathname.replace(/[^/]*$/, '') + d.url.replace(/^\//, '');
  // 1.128.0: de link met het lokale adres (base_url) of het externe adres (Instellingen → Server en PHP)
  let link = d.link;
  const url = () => (link === 'external' && d.url_ext ? d.url_ext : local);
  // Per onderdeel: een naam en checks om aan te vinken (per server), met een samenvatting van wat gekozen is
  const names = (ids) => state.hosts.flatMap((h) => h.checks.filter((c) => ids.includes(+c.id)).map((c) => `${h.name} – ${c.name}`));
  const sum = (ids) => (ids.length ? L('{n} check(s): {list}', { n: ids.length, list: names(ids).slice(0, 3).join(', ') + (ids.length > 3 ? ' …' : '') }) : L('kies checks'));
  const row = (it) => {
    const sel = (it.checks || []).map(Number);
    return `<div class="sp-row"><input class="sp-label" maxlength="60" placeholder="${esc(L('bv. Internet, NAS, Plex'))}" value="${esc(it.label || '')}">
      <details class="sp-checks"><summary>${esc(sum(sel))}</summary><div class="sp-pick">
        ${state.hosts.map((h) => `<div class="sp-host">${esc(h.name)}</div>${h.checks.map((c) => `<label class="sp-c"><input type="checkbox" value="${c.id}" ${sel.includes(+c.id) ? 'checked' : ''}> ${esc(c.name)}</label>`).join('')}`).join('')}
      </div></details>
      <button type="button" class="btn small-btn sp-del" title="${esc(L('Weghalen'))}">✕</button></div>`;
  };
  el.innerHTML = `<label class="switch"><input type="checkbox" id="sp-on" ${d.on ? 'checked' : ''}><span></span> <b>${L('Statuspagina aan')}</b></label>
    <label>${L('Titel')}<input id="sp-title" maxlength="80" value="${esc(d.title)}" placeholder="${esc(L('Status'))}"></label>
    <label>${L('Mededeling')} <span class="hint">${L('(optioneel, bv. "Zaterdag 9:00–10:00 onderhoud aan de NAS")')}</span><textarea id="sp-note" rows="2" maxlength="500">${esc(d.note)}</textarea></label>
    <h3 class="small" style="margin:1rem 0 .3rem">${L('Onderdelen')}</h3>
    ${xpHtml(L('Per onderdeel een naam en de checks die erbij horen.'), L('Geef elk onderdeel een naam en klik de checks aan die erbij horen. <b>Status:</b> de slechtste van die checks telt; een check in onderhoud toont <i>Onderhoud</i>.'))}
    <div id="sp-rows">${(d.items.length ? d.items : [{ label: '', checks: [] }]).map(row).join('')}</div>
    <button type="button" class="btn small-btn" id="sp-add">＋ ${L('Onderdeel')}</button>
    <div class="sp-link-head"><h3 class="small">${L('Geheime link')}</h3>
      <div class="seg" id="sp-link" role="tablist" aria-label="${esc(L('Adres in de link'))}">
        <button type="button" class="seg-btn ${link === 'local' ? 'active' : ''}" data-link="local">🏠 ${L('Lokaal')}</button>
        <button type="button" class="seg-btn ${link === 'external' ? 'active' : ''}" data-link="external" ${d.url_ext ? '' : 'disabled'}
          title="${esc(d.url_ext ? L('Link met het externe adres, voor buiten je netwerk') : L('Vul eerst de externe URL in (Instellingen → Server en PHP)'))}">🌐 ${L('Extern')}</button></div></div>
    <div class="copy-line"><pre class="code" id="sp-url">${esc(url())}</pre><button type="button" class="btn" id="sp-copy">${L('Kopieer')}</button></div>
    <p class="hint">${d.url_ext ? '' : `${L('Wil je de link ook buiten je netwerk gebruiken? Vul dan de externe URL in.')} <a href="#" data-goto-set="server">${L('Naar Server en PHP')}</a> `}${d.has_base ? '' : L('Tip: vul bij Instellingen → Server en PHP het adres van RzSense in (Basis-URL). ')}${L('Een nieuwe link maken? Dan werkt de oude meteen niet meer.')}
      <button type="button" class="btn small-btn" id="sp-new">↻ ${L('Nieuwe link')}</button> <a href="${esc(url())}" target="_blank" rel="noopener" class="btn small-btn" id="sp-open">${L('Openen')} ↗</a></p>
    <div class="btn-row" style="margin-top:.8rem"><button type="button" class="btn primary" id="sp-save">${L('Opslaan')}</button></div>`;
  const rows = $('#sp-rows');
  rows.addEventListener('click', (e) => { if (e.target.closest('.sp-del')) e.target.closest('.sp-row').remove(); });
  rows.addEventListener('change', (e) => {
    const d = e.target.closest('.sp-checks');
    if (d) $('summary', d).textContent = sum($$('input:checked', d).map((i) => +i.value));
  });
  $('#sp-add').onclick = () => rows.insertAdjacentHTML('beforeend', row({}));
  $('#sp-copy').onclick = () => { navigator.clipboard?.writeText($('#sp-url').textContent); toast(L('Gekopieerd')); };
  $('#sp-link').onclick = async (e) => {
    const b = e.target.closest('[data-link]');
    if (!b || b.disabled || b.dataset.link === link) return;
    try {
      await api('status_link', { link: b.dataset.link });
      link = b.dataset.link;
      $$('#sp-link .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
      $('#sp-url').textContent = url(); $('#sp-open').href = url();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('[data-goto-set="server"]', el)?.addEventListener('click', (e) => { e.preventDefault(); showTab('settings/server'); });
  $('#sp-new').onclick = async () => {
    if (!confirm(L('Nieuwe link maken? De oude link werkt dan meteen niet meer.'))) return;
    try { await api('status_token_new', {}); loadStatusPage(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#sp-save').onclick = async () => {
    const items = $$('.sp-row', rows).map((r) => ({ label: $('.sp-label', r).value.trim(), checks: $$('.sp-checks input:checked', r).map((i) => +i.value) })).filter((x) => x.label);
    try { await api('status_save', { on: $('#sp-on').checked, title: $('#sp-title').value, note: $('#sp-note').value, items }); toast(L('Opgeslagen')); loadStatusPage(); }
    catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- ⌨️ Zoeken en doen (Ctrl+K, 1.107.0)
// Servers, checks, tabbladen, instellingen en IP-adressen; met een werkwoord in de zoekopdracht ("onderhoud", "nu",
// "bevestig", "grafiek", "blokkeer", "bewerk", "agent") ook acties. Alleen wat je met je rol mag.
const CMDK_VERBS = {
  run: ['nu', 'check', 'checken', 'run', 'now'], maint: ['onderhoud', 'maintenance', 'pauze'], ack: ['bevestig', 'bevestigen', 'ack', 'acknowledge'],
  graph: ['grafiek', 'graph', 'chart', 'historie'], edit: ['bewerk', 'bewerken', 'edit', 'wijzig'], block: ['blokkeer', 'blokkeren', 'block'], agent: ['agent', 'manager'],
};
let cmdkSel = 0, cmdkItems = [];
function cmdkRole() { return document.body.dataset.role || 'readonly'; }
function cmdkBuild(q) {
  const role = cmdkRole(), user = role !== 'readonly', admin = role === 'admin';
  const words = q.toLowerCase().split(/\s+/).filter(Boolean);
  const verb = (k) => words.some((w) => CMDK_VERBS[k].includes(w));
  const plain = words.filter((w) => !Object.values(CMDK_VERBS).some((v) => v.includes(w)));
  const match = (hay) => plain.every((w) => hay.includes(w));
  const items = [];
  const add = (score, icon, label, sub, run, hay) => { if (match((hay || `${label} ${sub}`).toLowerCase())) items.push({ score, icon, label, sub, run }); };
  // IP-adres: tijdlijn (en blokkeren voor beheerders)
  const ip = q.trim().split(/\s+/).find((w) => IP_RE.test(w));
  if (ip) {
    items.push({ score: 100, icon: '🔎', label: L('Tijdlijn van {ip}', { ip }), sub: L('IDS, logins en blokkades van dit adres'), run: () => ipTimeline(ip) });
    if (admin) items.push({ score: 99, icon: '⛔', label: L('{ip} blokkeren', { ip }), sub: L('op al je servers'), run: () => blockDialog(ip) });
  }
  const free = ip ? plain.filter((w) => w !== ip.toLowerCase()) : plain;
  if (ip && !free.length) return items;
  // Servers en hun checks
  for (const h of state.hosts || []) {
    const hHay = `${h.name} ${h.address || ''}`;
    if (!verb('run') && !verb('maint') && !verb('agent') && !verb('edit')) add(60, '🖥️', h.name, h.address || '', () => { showTab('dashboard'); jumpToHost(h.id); }, hHay);
    if (user && verb('maint')) add(70, '🔧', L('Onderhoud: {n}', { n: h.name }), L('hele server'), () => maintenanceDialog('host', h.id, h.name, null), hHay);
    if (admin && verb('agent') && h.mode === 'agent') add(70, '🛰️', L('Agent Manager: {n}', { n: h.name }), h.address || '', () => agentInstructions(h), hHay);
    if ((isAdmin() || isUser()) && h.mode === 'agent') add(60, '📜', L('Logs: {n}', { n: h.name }), L('zoeken in de logs van deze server'), () => openLogsFor(h), hHay);   // 1.211.0
    if (admin && verb('edit')) add(65, '✎', L('Server bewerken: {n}', { n: h.name }), h.address || '', () => hostForm(h), hHay);
    for (const c of h.checks || []) {
      const hay = `${c.name} ${h.name} ${c.target || ''} ${(TYPES[c.type] || {}).label || c.type}`;
      const st = +c.status, prob = st !== 0 && +c.enabled && !+c.info_only;
      const tl = (TYPES[c.type] || {}).label || c.type;
      const sub = `${h.name} · ${tl} · ${STATUS[st] || ''}${c.last_message ? ' · ' + String(c.last_message).slice(0, 60) : ''}`;
      const where = `${h.name} · ${tl}`;
      if (!Object.keys(CMDK_VERBS).some(verb)) add(prob ? 55 : 50, ST_ICON[st] || '•', c.name, sub, () => checkInfo(c.id), hay);
      if (user && verb('run')) add(72, '↻', L('Nu checken: {c}', { c: c.name }), where, async () => { try { await api('check_run', { id: c.id }); toast(L('{c} gecheckt', { c: c.name })); refresh(); } catch (ex) { toast(ex.message, 'err'); } }, hay);
      if (user && verb('maint')) add(68, '🔧', L('Onderhoud: {c}', { c: c.name }), where, () => maintenanceDialog('check', c.id, `${h.name} – ${c.name}`, null), hay);
      if (user && verb('ack') && prob && !c.acked_at) add(75, '✔', L('Bevestigen: {c}', { c: c.name }), sub, () => ackDialog('check', c.id, `${h.name} – ${c.name}`, c.last_message), hay);
      if (verb('graph')) add(66, '📈', L('Grafiek: {c}', { c: c.name }), where, () => showHistory(c, h), hay);
      if (admin && verb('edit')) add(64, '✎', L('Check bewerken: {c}', { c: c.name }), where, () => checkForm(c, h), hay);
    }
  }
  // Tabbladen en instellingen
  $$('.main-tabs .tab').forEach((t) => add(40, '📑', t.textContent.trim(), L('tabblad'), () => showTab(t.dataset.tab)));
  if (admin && $('#tab-settings')) {
    for (const [, icon, group, subs] of SET_NAV) for (const [k, label] of subs) add(35, icon, label, `${L('Instellingen')} › ${group}`, () => showTab('settings/' + k));
  }
  if (user && !free.length && verb('run')) items.push({ score: 80, icon: '↻', label: L('Alles nu checken'), sub: '', run: () => $('#run-all')?.click() });
  if (admin && !free.length && verb('maint')) items.push({ score: 80, icon: '🔧', label: L('Onderhoud: alles'), sub: L('alle meldingen pauzeren'), run: () => maintenanceDialog('all', 0, L('alle servers'), state.maintenance) });
  // Beste eerst: hogere score, en een naam die met de zoekterm begint
  const first = (plain[0] || '');
  items.forEach((it) => { if (first && it.label.toLowerCase().startsWith(first)) it.score += 5; });
  return items.sort((a, b) => b.score - a.score || a.label.localeCompare(b.label)).slice(0, 40);
}
function cmdkRender() {
  const q = $('#cmdk-in').value;
  cmdkItems = q.trim() ? cmdkBuild(q) : cmdkBuild('').filter((it) => it.icon === '📑' || it.score >= 55).slice(0, 12);
  cmdkSel = Math.min(cmdkSel, Math.max(0, cmdkItems.length - 1));
  $('#cmdk-list').innerHTML = cmdkItems.length
    ? cmdkItems.map((it, i) => `<button type="button" class="cmdk-item${i === cmdkSel ? ' sel' : ''}" role="option" aria-selected="${i === cmdkSel}" data-i="${i}">
        <span class="cmdk-ic">${it.icon}</span><span class="cmdk-txt"><span class="cmdk-l">${esc(it.label)}</span>${it.sub ? `<span class="cmdk-s">${esc(it.sub)}</span>` : ''}</span></button>`).join('')
    : `<p class="muted small cmdk-none">${L('Niets gevonden')}</p>`;
  $('.cmdk-item.sel')?.scrollIntoView({ block: 'nearest' });
}
function cmdkOpen() {
  const el = $('#cmdk');
  if (!el) return;
  el.hidden = false;
  $('#cmdk-in').value = '';
  cmdkSel = 0;
  cmdkRender();
  $('#cmdk-in').focus();
}
function cmdkClose() { const el = $('#cmdk'); if (el) el.hidden = true; }
function cmdkDo(i) {
  const it = cmdkItems[i];
  if (!it) return;
  cmdkClose();
  it.run();
}
function initCmdk() {
  const el = $('#cmdk');
  if (!el) return;
  $('#cmdk-btn').onclick = cmdkOpen;
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); el.hidden ? cmdkOpen() : cmdkClose(); }
  });
  $('#cmdk-in').addEventListener('input', () => { cmdkSel = 0; cmdkRender(); });
  $('#cmdk-in').addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); cmdkSel = Math.min(cmdkSel + 1, cmdkItems.length - 1); cmdkRender(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cmdkSel = Math.max(cmdkSel - 1, 0); cmdkRender(); }
    else if (e.key === 'Enter') { e.preventDefault(); cmdkDo(cmdkSel); }
    else if (e.key === 'Escape') { e.preventDefault(); cmdkClose(); }
  });
  $('#cmdk-list').addEventListener('click', (e) => { const b = e.target.closest('[data-i]'); if (b) cmdkDo(+b.dataset.i); });
  el.addEventListener('mousedown', (e) => { if (e.target === el) cmdkClose(); });
}

// ---------------------------------------------------------------- Modal helper
/**
 * 1.224.4: elk venster heeft een eigen "generatie" (modalGen) en een AbortController (modalSignal()). Een nieuw venster
 * haalt de klik- en wijzigingsafhandelingen van het vorige weg, en polls van het vorige venster stoppen. Daarvoor stapelden
 * ze op: na een paar keer openen deed één klik het twee keer, of voor een andere server.
 */
let modalGen = 0, modalAC = null, pairTok = 0;
const modalSignal = () => (modalAC || (modalAC = new AbortController())).signal;
function modal({ title, body, buttons = [], wide = false, page = false, onSubmit }) {
  const dlg = $('#modal');
  const form = $('#modal-form');
  if (modalAC) modalAC.abort();
  modalAC = new AbortController();
  modalGen++;
  form.onclick = form.oninput = form.onchange = null;
  const mb = $('#modal-body');
  mb.onclick = mb.oninput = mb.onchange = null;
  dlg.dataset.live = '';   // een ander venster: niet meer live bijwerken als check (checkInfo zet het weer)
  dlg.classList.toggle('wide', wide);
  dlg.classList.remove('upd-modal');   // 1.204.0: alleen de bijwerk-vensters
  dlg.classList.toggle('page', page);   // 1.121.0: als pagina (bijna het hele scherm), bv. de Agent Manager
  $('#modal-title').textContent = title;
  $('#modal-body').innerHTML = body;
  setTimeout(() => fieldHelp(mb), 0);   // 1.241.0: ⓘ bij de velden
  const err = $('.form-error', form);
  err.hidden = true;
  const footer = $('#modal-footer');
  footer.innerHTML = '';
  buttons.forEach((b) => {
    const btn = document.createElement('button');
    btn.className = 'btn ' + (b.cls || '');
    btn.textContent = b.label;
    btn.type = b.submit ? 'submit' : 'button';
    if (b.style) btn.style.cssText = b.style;
    if (b.onClick) btn.onclick = () => b.onClick(btn);
    footer.append(btn);
  });
  form.onsubmit = async (e) => {
    if (e.submitter?.value === 'cancel') return;
    e.preventDefault();
    if (!onSubmit) return dlg.close();
    const sb = $('button[type=submit]', footer);
    if (sb) sb.disabled = true;
    try {
      // 'keep': het venster blijft open, omdat onSubmit er al iets anders in zette (bv. de voortgang)
      if (await onSubmit(Object.fromEntries(new FormData(form)), form) === 'keep') return;
      dlg.close();
      await refresh();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
    }
    if (sb) sb.disabled = false;
  };
  if (!dlg.open) dlg.showModal();
  return { dlg, form, err };
}

function modalError(msg) {
  const err = $('#modal-form .form-error');
  err.textContent = msg;
  err.hidden = false;
}

// ---------------------------------------------------------------- Host form
/** Eerste optie bij Soort apparaat: wat RzSense zelf herkent */
function kindAutoText(h, k = null) {
  const auto = k || (h && h.id ? [h.kind_auto].find((x) => x && KIND_INFO[x]) || (isHa(h) ? 'smarthome' : ['agent', 'local'].includes(h.mode) ? 'server'
    : CAMERA_NAME.test(h.name || '') ? 'camera' : null) : null);
  return auto ? L('Vanzelf: {kind}', { kind: kindLabel(auto) }) : L('Vanzelf (nog niet herkend: overig)');
}

/** Sjablonen die aan deze server gekoppeld zijn (1.94.0) */
const hostTpls = (h) => (h && h.id ? ((state.host_tpls || {})[h.id] || []) : []).map(Number);

function hostForm(h, preset = null) {
  const isNew = !h;
  h = h || { name: '', address: '', mode: 'remote', enabled: 1, ...(preset || {}) };
  modal({
    title: isNew ? L('Server toevoegen') : `${L('Server bewerken')} – ${h.name}`,
    body: `
      <label>${L('Naam')}<input name="name" value="${esc(h.name)}" required placeholder="${L('bv. Webserver 01')}"></label>
      <label>${L('Hostname / IP-adres')}<input name="address" value="${esc(h.address)}" required placeholder="192.168.1.10" class="mono"></label>
      <label>${L('Type')}
        <select name="mode">${Object.entries(MODES).map(([k, v]) => `<option value="${k}" ${h.mode === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
        <span class="hint">${L('Lokaal = disk/services van deze monitor-server. Agent = remote server die disk/services rapporteert via rzmon-agent.sh. Remote = alleen ping/poort/http.')}</span>
      </label>
      <label>${L('Soort apparaat')}
        <select name="kind">
          <option value="" id="kind-auto-opt">${kindAutoText(h)}</option>
          ${Object.keys(KIND_INFO).map((k) => `<option value="${k}" ${h.kind === k ? 'selected' : ''}>${esc(kindLabel(k))}</option>`).join('')}
        </select>
        <input type="hidden" name="kind_auto" value="">
        <span class="hint">${L('Voor het dashboard (Servers, Netwerk, Opslag …). Vanzelf = herkend via de agent of SNMP.')}</span>
      </label>
      <label>${L('Groep')} <span class="hint">${L('(optioneel)')}</span>
        <input name="grp" value="${esc(h.grp || '')}" list="grp-list" maxlength="60" placeholder="${L('bv. Thuis, Kantoor, Klant A')}">
        <datalist id="grp-list">${[...new Set(state.hosts.map((x) => (x.grp || '').trim()).filter(Boolean))].sort().map((g) => `<option value="${esc(g)}">`).join('')}</datalist>
        <span class="hint">${L('Je eigen indeling, voor Monitoring → Beschikbaarheid. Leeg = geen groep.')}</span>
      </label>
      <label>🌙 ${L('Stille uren')} <span class="hint">${L('(leeg = volgt de groep of algemeen)')}</span>
        <select name="quiet">${quietOptions(h.quiet || '', L('volgt groep of algemeen'))}</select></label>
      <label>${L('Afhankelijk van')} <span class="hint">${L('(optioneel)')}</span>
        <select name="parent_id">
          <option value="">— ${L('geen')} —</option>
          ${parentOptions(h)}
        </select>
        <span class="hint">${L('Bereikbaar via deze server, bv. een router, switch, VPN of hypervisor. Is die onbereikbaar, dan krijg je één melding voor die server en niet ook voor deze.')}</span>
      </label>
      <label class="switch" ${h.mode === 'agent' || isNew ? '' : 'hidden'} id="iperf-ok-wrap"><input type="checkbox" name="iperf_ok" ${+(h.iperf_ok ?? 1) ? 'checked' : ''}><span></span> ${L('Mag doorvoertest ontvangen (iperf3)')} <span class="hint">${L('voor de pad-check van een andere server; de agent start dan kort een ontvanger die na één meting stopt')}</span></label>
      ${snmpHostFields(h.snmp)}
      ${swsOpen({ id: 'fim-host-wrap', cb: 'id="fim-host-sw"', icon: '📁', title: L('Bestanden bewaken (IDS)'), on: false, sub: `<span id="fim-host-label">${L('laden…')}</span>`, hidden: h.mode !== 'agent' })}
        ${xpHtml(L('Extra bestanden om te bewaken, alleen op deze server.'), L('Deze paden komen bovenop de algemene lijst (<i>Instellingen → IDS → Algemeen</i>). Staat hetzelfde pad in allebei, dan geldt dit pad, bijvoorbeeld om de ernst te veranderen.'))}
        <div id="fim-host"></div>
      </div></section>
      ${(state.templates || []).length ? `${swsOpen({ id: 'tpl-wrap', cb: null, icon: '📋', title: L('Sjablonen'), on: isNew || hostTpls(h).length > 0,
        sub: hostTpls(h).length ? esc(hostTpls(h).map((id) => (state.templates.find((t) => +t.id === id) || {}).name).join(', ')) : L('geen') })}
        ${xpHtml(L('Sjablonen zetten vaste checks op deze server.'), L('De checks uit een sjabloon komen vanzelf op deze server en veranderen mee als je het sjabloon aanpast. <b>Uitvinken</b> koppelt het sjabloon los; de checks blijven dan gewoon staan.'))}
        ${state.templates.map((t) => `<label class="switch"><input type="checkbox" data-tpl="${t.id}" ${hostTpls(h).includes(+t.id) ? 'checked' : ''}><span></span> ${esc(t.name)}</label>`).join('')}
      </div></section>` : ''}
      <label class="switch"><input type="checkbox" name="enabled" ${+h.enabled ? 'checked' : ''}><span></span> ${L('Actief')}</label>
      ${isNew ? `<label class="switch"><input type="checkbox" name="add_ping" checked><span></span> ${L('Direct een ping-check toevoegen')}</label>` : ''}`,
    buttons: [
      ...(isNew ? [] : [{ label: '🗑 ' + L('Verwijderen'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Server "{name}" en al zijn checks verwijderen?', { name: h.name }))) return;
        await api('host_delete', { id: h.id });
        $('#modal').close(); toast(L('Server verwijderd')); refresh();
      } }]),
      { label: L('Opslaan'), cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      const snmp = snmpData(d);
      const r = await api('host_save', { ...d, id: h.id, enabled: !!d.enabled, add_ping: !!d.add_ping, iperf_ok: !!d.iperf_ok, snmp, ...(fimHost ? { ids_fim: $('#fim-host-sw').checked ? fimHost.get() : [] } : {}) });
      // Sjablonen koppelen of loskoppelen (de checks van een losgekoppeld sjabloon blijven staan)
      const had = hostTpls(h);
      for (const cb of $$('#modal-body [data-tpl]')) {
        const tid = +cb.dataset.tpl;
        if (cb.checked && !had.includes(tid)) await api('template_link', { host_id: r.id, template_id: tid });
        if (!cb.checked && had.includes(tid)) await api('template_unlink', { host_id: r.id, template_id: tid, keep: true });
      }
      toast(L('Server opgeslagen'));
      if (snmp.on && !h.snmp) {
        // SNMP net aangezet: meteen laten zien wat er te bewaken valt
        await refresh();
        setTimeout(() => snmpChecks(findHost(r.id)), 50);
        return;
      }
      if (isNew && d.mode === 'agent') {
        await refresh();
        setTimeout(() => agentInstructions(findHost(r.id)), 50);
      }
    },
  });
  hostFimInit(h);
  snmpHostInit(h);
}


// ---------------------------------------------------------------- 1.178.0: schakelaarblokken
/**
 * Blok met een schakelaar in de kop in plaats van een uitklaplijst: aan = de velden eronder verschijnen (en gelden),
 * uit = de algemene instelling. Sluiten met </div></section>. cb = attributen van de checkbox (null = geen schakelaar,
 * het blok staat dan altijd open). De verandering gaat via de gewone change van de checkbox.
 */
function swsOpen({ id, cb, icon, title, sub = '', on, hidden = false }) {
  const open = cb === null || on;
  return `<section class="sws${open ? ' on' : ''}" id="${id}"${hidden ? ' hidden' : ''}>
    <label class="sws-h"><span class="sws-ic" aria-hidden="true">${icon}</span><span class="sws-t"><b>${title}</b>${sub ? `<small>${sub}</small>` : ''}</span>
      ${cb === null ? '' : `<input type="checkbox" class="sws-cb" ${cb} ${on ? 'checked' : ''}><i class="sws-sw" aria-hidden="true"></i>`}</label>
    <div class="sws-b"${open ? '' : ' hidden'}>`;
}
/** Blok open of dicht zetten vanuit de code (zonder change-event) */
function swsSet(sec, on) {
  if (!sec) return;
  const cb = $('.sws-cb', sec);
  if (cb) cb.checked = on;
  sec.classList.toggle('on', on);
  const b = sec.querySelector(':scope > .sws-b');
  if (b) b.hidden = !on;
}
/** Keuze uit een paar waarden als knoppen naast elkaar; geeft het event "sw-seg" (detail = waarde) */
function swSeg(name, opts, cur, attrs = '') {
  return `<div class="sw-seg" data-sw-seg="${name}" role="radiogroup" ${attrs}>${opts.map(([v, l]) => `<button type="button" role="radio" data-v="${esc(String(v))}" aria-checked="${String(v) === String(cur)}">${l}</button>`).join('')}</div>`;
}
document.addEventListener('change', (e) => {
  const cb = e.target.closest && e.target.closest('.sws-cb');
  if (!cb) return;
  const sec = cb.closest('.sws');
  sec.classList.toggle('on', cb.checked);
  const b = sec.querySelector(':scope > .sws-b');
  if (b) b.hidden = !cb.checked;
});
document.addEventListener('click', (e) => {
  const btn = e.target.closest && e.target.closest('.sw-seg > button');
  if (!btn || btn.disabled) return;
  const seg = btn.parentElement;
  $$('button', seg).forEach((x) => x.setAttribute('aria-checked', String(x === btn)));
  seg.dispatchEvent(new CustomEvent('sw-seg', { detail: btn.dataset.v }));
});

// ---------------------------------------------------------------- SNMP
// ---------------------------------------------------------------- 1.182.0: Instellingen → Overzicht → Functies
let featData = null;
const featF = { q: '', g: '', st: '' };
const FEAT_ST = () => ({ on: ['✅', L('aan')], off: ['⚪', L('uit')], unset: ['⚠️', L('niet ingesteld')], always: ['✔', L('altijd')] });
async function loadFeatures() {
  const box = $('#features-box');
  if (!box) return;
  try { featData = await api('features'); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  const st = FEAT_ST();
  const cnt = (k) => featData.rows.filter((r) => r.status === k).length;
  box.innerHTML = `<div class="feat-sum">
      <div class="feat-n"><b>${featData.rows.length}</b><small>${L('functies')}</small></div>
      ${['on', 'off', 'unset', 'always'].map((k) => `<button type="button" class="feat-n feat-st-${k}" data-feat-st="${k}"><b>${cnt(k)}</b><small>${st[k][0]} ${esc(st[k][1])}</small></button>`).join('')}
    </div>
    <div class="feat-bar">
      <input type="search" id="feat-q" placeholder="${esc(L('Zoek een functie…'))}" aria-label="${esc(L('Zoek een functie'))}">
      <select id="feat-g" aria-label="${esc(L('Onderdeel'))}"><option value="">${L('Alle onderdelen')}</option>
        ${Object.entries(featData.groups).map(([k, [ic, n]]) => `<option value="${esc(k)}">${ic} ${esc(n)}</option>`).join('')}</select>
      <select id="feat-st" aria-label="${esc(L('Status'))}"><option value="">${L('Elke status')}</option>
        ${['on', 'off', 'unset', 'always'].map((k) => `<option value="${k}">${st[k][0]} ${esc(st[k][1])}</option>`).join('')}</select>
    </div>
    <div class="feat-wrap"><table class="feat-tbl"><thead><tr><th>${L('Onderdeel')}</th><th>${L('Functie')}</th><th>${L('Sinds')}</th><th>${L('Status')}</th><th></th></tr></thead><tbody id="feat-body"></tbody></table></div>
    <p class="muted small" id="feat-foot"></p>`;
  $('#feat-q').value = featF.q; $('#feat-g').value = featF.g; $('#feat-st').value = featF.st;
  $('#feat-q').oninput = (e) => { featF.q = e.target.value; featRender(); };
  $('#feat-g').onchange = (e) => { featF.g = e.target.value; featRender(); };
  $('#feat-st').onchange = (e) => { featF.st = e.target.value; featRender(); };
  box.onchange = async (e) => {   // 1.226.0: aan/uit meteen vanuit het overzicht
    const t = e.target.closest('[data-feat-tog]');
    if (!t) return;
    try {
      const r = await api('feature_toggle', { key: t.dataset.featTog, on: t.checked });
      featData.rows = r.rows;
      featRender();
      toast(t.checked ? L('Aangezet') : L('Uitgezet'));
    } catch (ex) { t.checked = !t.checked; toast(ex.message, 'err'); }
  };
  box.onclick = (e) => {
    const s = e.target.closest('[data-feat-st]');
    if (s) { featF.st = featF.st === s.dataset.featSt ? '' : s.dataset.featSt; $('#feat-st').value = featF.st; featRender(); return; }
    const go = e.target.closest('[data-feat-go]');
    if (!go) return;
    const t = go.dataset.featGo;
    if (t.startsWith('#')) { const [tab, sub] = t.slice(1).split(':'); showTab(tab); if (sub) (tab === 'net' ? setNetSub : setMonSub)(sub); } else showSettingsSection(t);
  };
  featRender();
}
function featRender() {
  const st = FEAT_ST();
  const q = featF.q.trim().toLowerCase();
  const navOk = new Set(SET_NAV.flatMap((g) => g[3].map((s) => s[0])));
  const rows = featData.rows.filter((r) => (!featF.g || r.g === featF.g) && (!featF.st || r.status === featF.st)
    && (!q || (r.name + ' ' + r.what + ' ' + r.since).toLowerCase().includes(q)));
  $('#feat-body').innerHTML = rows.map((r) => {
    const [ic, gn] = featData.groups[r.g] || ['', r.g];
    const canGo = r.sec && (r.sec.startsWith('#') || navOk.has(r.sec));
    return `<tr class="feat-r-${r.status}"><td class="feat-g">${ic} ${esc(gn)}</td>
      <td><b>${esc(r.name)}</b><small>${esc(r.what)}</small></td>
      <td class="feat-v">${esc(r.since)}</td>
      <td>${r.toggle && isAdmin() ? `<label class="switch small" title="${esc(L('Aan of uit'))}"><input type="checkbox" data-feat-tog="${esc(r.toggle)}" ${r.status === 'on' ? 'checked' : ''}><span></span></label> ` : ''}<span class="feat-pill feat-st-${r.status}">${st[r.status][0]} ${esc(st[r.status][1])}</span></td>
      <td class="feat-go">${canGo ? `<button type="button" class="btn small-btn" data-feat-go="${esc(r.sec)}">${r.sec.startsWith('#') ? L('Openen') : (isAdmin() ? '⚙️ ' + L('Instellen') : L('Bekijken'))} →</button>` : ''}</td></tr>`;
  }).join('') || `<tr><td colspan="5" class="muted">${L('Geen functies gevonden.')}</td></tr>`;
  $('#feat-foot').textContent = L('{n} van {t} functies', { n: rows.length, t: featData.rows.length });
  $$('[data-feat-st]').forEach((b) => b.classList.toggle('on', b.dataset.featSt === featF.st));
}

// ---------------------------------------------------------------- 1.180.0: Instellingen → Rapporten
const REP_DAYS = () => [[1, L('ma')], [2, L('di')], [3, L('wo')], [4, L('do')], [5, L('vr')], [6, L('za')], [7, L('zo')]];
const REP_INFO = {
  all: () => L('Eén bericht met de onderdelen die je aanvinkt; standaard op de 1e van de maand over de vorige maand.'),
  ids_week: () => L('Geblokkeerde aanvallen, mislukte logins, de IP-adressen met de meeste pogingen, de honeypot, SSH-logins van buiten en open incidenten.'),
  ai_week: () => L('De AI schrijft in gewone taal wat er speelde, wat terugkwam en waar je eens naar moet kijken.'),
  month: () => L('Beschikbaarheid, storingen, meldingen, beveiliging, kwetsbaarheden en wat nu aanpakken, over de vorige maand.'),
  vulns: () => L('Wat er open staat per ernst, actief misbruikt, de top 5 van Eén update, veel opgelost en de CVE\'s met veel aandacht.'),
  avail: () => L('Per server hoe vaak hij bereikbaar was, de storingen en de langste.'),
  backup: () => L('Hoeveel back-ups er gemaakt zijn, hoeveel ruimte ze innemen, de laatste en of er een probleem is.'),
  leaks: () => L('Nieuwe datalekken op je adressen en wat nog niet afgehandeld is.'),
  traffic: () => L('Het verkeer van je internetlijn en de top 10 apparaten, met de verandering ten opzichte van de periode ervoor.'),
  nis2: () => L('De tien NIS2-maatregelen met wat RzSense ziet en je notities, en de meldtermijnen van significante incidenten. De stand van het moment van versturen; standaard elk kwartaal.'),
};
let repData = null;
let repTimer = null;
/** 1.233.0: de regel onder de titel van een rapport (wanneer, kanalen, eigen ontvangers) */
function repSub(c, fixed) {
  const f = fixed || c.freq;
  const n = c.to ? c.to.split(/[,;\s]+/).filter(Boolean).length : 0;
  return !c.on ? L('uit') : (f === 'week' ? L('elke {d} om {t}', { d: REP_DAYS().find(([v]) => v === c.day)[1], t: c.time })
    : f === 'quarter' ? L('elk kwartaal op de {d}e (jan, apr, jul, okt) om {t}', { d: c.day, t: c.time }) : L('elke maand op de {d}e om {t}', { d: c.day, t: c.time }))
    + ' · ' + [c.email ? L('e-mail') : '', c.push ? L('push') : ''].filter(Boolean).join(' + ')
    + (c.email && (n || c.own) ? ' · ' + (n ? L('{n} eigen ontvanger(s)', { n }) : L('eigen ontvangers')) + (c.also ? ' + ' + L('algemene') : '') : '');
}
async function loadReports() {
  const box = $('#reports-box');
  if (!box) return;
  try { repData = await api('reports_get'); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  const r = repData;
  const ro = !r.admin;
  const need = { ai_week: r.ai ? '' : L('AI-advies staat uit (Instellingen → Koppelingen → AI-advies).'), ids_week: r.ids ? '' : L('Het IDS staat uit.'),
    vulns: r.vulns ? '' : L('Kwetsbaarheden staat uit.'), leaks: r.hibp ? '' : L('Datalekken staat uit.'), nis2: r.nis2 ? '' : L('Het NIS2-overzicht staat uit (Instellingen → Overzicht → NIS2).') };
  const card = (m) => {
    const c = r.cfg[m.key];
    const dis = ro ? 'disabled' : '';
    const freq = m.fixed || c.freq;
    const sub = repSub(c, m.fixed);
    return `<div class="rep-card${m.key === 'all' ? ' rep-all' : ''}" data-rep="${m.key}">
      ${swsOpen({ id: 'rep-' + m.key, cb: `data-rep-on ${dis}`, icon: m.icon, title: esc(m.label), on: c.on, sub: `<span data-rep-sub>${esc(sub)}</span>` })}
        <p class="muted small rep-what">${esc(REP_INFO[m.key]())}</p>
        ${need[m.key] ? `<p class="warn-txt small">⚠️ ${esc(need[m.key])}</p>` : ''}
        <div class="rep-grid">
          ${m.fixed ? '' : `<label>${L('Hoe vaak')} ${swSeg('freq', [['week', L('elke week')], ['month', L('elke maand')], ['quarter', L('elk kwartaal')]], c.freq, `data-rep-f="freq" ${dis}`)}</label>`}
          <label data-rep-dayw ${freq === 'week' ? '' : 'hidden'}>${L('Dag')} ${swSeg('dayw', REP_DAYS(), c.day, `data-rep-f="dayw" ${dis}`)}</label>
          <label data-rep-daym ${freq !== 'week' ? '' : 'hidden'}>${freq === 'quarter' ? L('Dag (jan, apr, jul, okt)') : L('Dag van de maand')} <input type="number" min="1" max="28" value="${c.day}" data-rep-f="daym" ${dis}></label>
          <label>${L('Om')} <input type="time" value="${esc(c.time)}" data-rep-f="time" ${dis}></label>
        </div>
        <div class="rep-ch">
          <label class="switch small"><input type="checkbox" data-rep-f="email" ${c.email ? 'checked' : ''} ${dis}><span></span> 📧 ${L('E-mail (HTML)')}${r.smtp && (r.mail || c.to) ? '' : ` <span class="warn-txt">${L('nog niet ingesteld')}</span>`}</label>
          <label class="switch small"><input type="checkbox" data-rep-f="push" ${c.push ? 'checked' : ''} ${dis}><span></span> 📱 ${L('Push (korte samenvatting)')}${r.push ? '' : ` <span class="warn-txt">${L('nog niet ingesteld')}</span>`}</label>
        </div>
        ${r.admin ? `<div class="rep-to">
          <label>${L('Ontvangers van dit rapport')} <span class="hint">${L('(komma ertussen, max. {n}; leeg = de algemene ontvangers bij Instellingen → E-mail)', { n: r.max_to })}</span>
            <textarea rows="2" data-rep-f="to" spellcheck="false" autocomplete="off" placeholder="directie@voorbeeld.nl, adviseur@voorbeeld.nl">${esc(c.to || '')}</textarea></label>
          <label class="switch small"><input type="checkbox" data-rep-f="also" ${c.also ? 'checked' : ''}><span></span> ${L('Ook naar de algemene ontvangers')}</label>
        </div>` : c.own ? `<p class="muted small">📧 ${L('Dit rapport gaat naar eigen ontvangers, ingesteld door een beheerder.')}</p>` : ''}
        ${m.key === 'all' ? `<div class="rep-parts"><span class="muted small">${L('Onderdelen')}</span>${r.parts.map((p) => {
          const pm = r.meta.find((x) => x.key === p);
          return `<label class="switch small"><input type="checkbox" data-rep-part="${p}" ${c.parts.includes(p) ? 'checked' : ''} ${dis}><span></span> ${pm.icon} ${esc(pm.label)}</label>`;
        }).join('')}</div>` : ''}
      </div></section>
      <div class="rep-btns">
        ${r.admin ? `<button type="button" class="btn small-btn primary" data-rep-send>📤 ${L('Nu versturen')}</button>` : ''}
        <button type="button" class="btn small-btn" data-rep-prev>👁 ${L('Voorbeeld')}</button>
        <button type="button" class="btn small-btn" data-rep-me title="${esc(r.me ? L('Naar {e}', { e: r.me }) : L('Vul eerst je e-mailadres in bij Mijn account'))}">✉️ ${L('Verstuur naar mij')}</button>
        ${m.last ? `<span class="muted small">${L('laatst: {p}', { p: esc(m.last) })}</span>` : ''}
      </div>
    </div>`;
  };
  box.innerHTML = `${!r.smtp ? `<div class="callout">⚠️ ${L('E-mail is nog niet ingesteld (Instellingen → Meldingen → Kanalen). Zonder e-mail gaan rapporten alleen als push.')}</div>` : ''}
    <div class="rep-list">${r.meta.filter((m) => m.key === 'all').map(card).join('')}${r.meta.filter((m) => m.key !== 'all').map(card).join('')}</div>`;
  $$('.rep-card', box).forEach((el) => { repWire(el); const t = $('[data-rep-f=to]', el); if (t) smartList(t, { kind: 'email', sep: ', ', sources: SL_SRC.emails, max: 20 }); });   // 1.239.0
}
/** Wat er in één rapportkaart staat */
function repRead(el) {
  const k = el.dataset.rep;
  const c = { ...repData.cfg[k] };
  c.on = $('[data-rep-on]', el).checked;
  const fseg = $('[data-rep-f=freq] [aria-checked=true]', el);
  if (fseg) c.freq = fseg.dataset.v;
  const freq = (repData.meta.find((m) => m.key === k) || {}).fixed || c.freq;
  c.day = freq === 'week' ? +(($('[data-rep-f=dayw] [aria-checked=true]', el) || {}).dataset || {}).v || 1 : Math.max(1, Math.min(28, +$('[data-rep-f=daym]', el).value || 1));
  c.time = $('[data-rep-f=time]', el).value || '08:00';
  c.email = $('[data-rep-f=email]', el).checked;
  c.push = $('[data-rep-f=push]', el).checked;
  if ($('[data-rep-f=to]', el)) {
    c.to = $('[data-rep-f=to]', el).value;
    c.also = $('[data-rep-f=also]', el).checked;
  }
  if (k === 'all') c.parts = $$('[data-rep-part]', el).filter((x) => x.checked).map((x) => x.dataset.repPart);
  return c;
}
function repWire(el) {
  const k = el.dataset.rep;
  const save = () => {
    if (!repData.admin) return;
    const c = repRead(el);
    const freq = (repData.meta.find((m) => m.key === k) || {}).fixed || c.freq;
    $('[data-rep-dayw]', el).hidden = freq !== 'week';
    $('[data-rep-daym]', el).hidden = freq === 'week';
    $('[data-rep-daym]', el).firstChild.textContent = freq === 'quarter' ? L('Dag (jan, apr, jul, okt)') + ' ' : L('Dag van de maand') + ' ';
    clearTimeout(repTimer);
    repTimer = setTimeout(async () => {
      try {
        const r = await api('reports_save', { cfg: { [k]: c } });
        repData.cfg = r.cfg;
        const n = r.cfg[k];
        $('[data-rep-sub]', el).textContent = repSub(n, (repData.meta.find((m) => m.key === k) || {}).fixed);
        const ta = $('[data-rep-f=to]', el);
        if (ta && document.activeElement !== ta) ta.value = n.to;   // opgeschoond: kleine letters, zonder dubbele
        savedFlag($('.sws-h', el), true);
      } catch (ex) { toast(ex.message, 'err'); }
    }, 400);
  };
  el.addEventListener('change', save);
  $$('.sw-seg', el).forEach((seg) => seg.addEventListener('sw-seg', save));
  el.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-rep-send], [data-rep-me], [data-rep-prev]');
    if (!b) return;
    if (b.matches('[data-rep-prev]')) { window.open('api.php?' + new URLSearchParams({ action: 'report_preview', key: k }), '_blank', 'noopener'); return; }
    b.disabled = true;
    try {
      if (b.matches('[data-rep-send]')) {
        const r = await api('report_send', { key: k });
        toast(L('Verstuurd via {c}', { c: r.sent.map((x) => (x === 'email' ? L('e-mail') : L('push'))).join(' + ') }) + (r.warn ? ' · ' + r.warn : ''), r.warn ? 'err' : undefined);
      } else {
        const r = await api('report_me', { key: k });
        toast(L('Verstuurd naar {e}', { e: r.to }));
      }
    } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  });
}

/** 1.178.0: SNMP-velden uit een blok (de knoppen schrijven in verborgen velden) */
function snmpRootData(root) {
  const g = (n) => ($(`[name=${n}]`, root) || {}).value;
  return { v: g('snmp_v'), port: g('snmp_port'), community: g('snmp_community'), user: g('snmp_user'), level: g('snmp_level'),
    auth_proto: g('snmp_auth_proto'), auth_pass: g('snmp_auth_pass'), priv_proto: g('snmp_priv_proto'), priv_pass: g('snmp_priv_pass') };
}

/** 1.178.0: Instellingen → Koppelingen → SNMP: de algemene instelling en welke apparaten hem gebruiken */
async function loadSnmpGlobal() {
  const box = $('#snmp-global');
  if (!box) return;
  let r;
  try { r = await api('snmp_global'); } catch (ex) { box.innerHTML = `<p class="bad small">${esc(ex.message)}</p>`; return; }
  const nGlob = r.hosts.filter((x) => x.global).length;
  box.innerHTML = `${r.available ? '' : `<div class="callout">⚠️ ${L('De PHP-extensie snmp ontbreekt op de RzSense-server. Rocky Linux: sudo dnf install php-snmp en herstart PHP (of voer install-rocky.sh opnieuw uit)')}</div>`}
    ${swsOpen({ id: 'snmpg-wrap', cb: 'id="snmpg-on"', icon: '🌐', title: L('Algemene instelling'), on: !!r.cfg,
      sub: r.cfg ? L('{n} apparaten gebruiken hem', { n: nGlob }) : L('uit: elk apparaat heeft dan eigen instellingen nodig') })}
      <div id="snmpg-fields">${snmpFieldsHtml(r.cfg)}</div>
    </div></section>
    <div class="snmpg-btns"><button type="button" class="btn primary" id="snmpg-save">${L('Opslaan')}</button>
      <button type="button" class="btn" id="snmpg-test" ${r.cfg && nGlob ? '' : 'disabled'}>🔍 ${L('Alles testen')}</button><span class="small muted" id="snmpg-out"></span></div>
    <h3 class="set-sub">${L('Apparaten met SNMP')}</h3>
    ${r.hosts.length ? `<div class="snmpg-list">${r.hosts.map((x) => `<div class="snmpg-row">
        <span class="snmpg-n"><b>${esc(x.name)}</b><small class="mono muted">${esc(x.address)}</small></span>
        <span class="snmpg-src ${x.global ? 'g' : 'o'}">${x.global ? L('algemeen') : L('eigen')}${x.v ? ' · v' + esc(x.v) : ''}</span>
        <span class="snmpg-st small" id="snmpg-st-${x.id}"></span>
        <button type="button" class="btn small-btn" data-snmpg-edit="${x.id}">✎ ${L('Bewerken')}</button></div>`).join('')}</div>`
      : `<p class="muted small">${L('Nog geen apparaten met SNMP. Zet SNMP aan bij een apparaat (Server bewerken → 📡 SNMP).')}</p>`}`;
  snmpFieldsInit($('#snmpg-fields'));
  $('#snmpg-save').onclick = async () => {
    const out = $('#snmpg-out');
    try {
      await api('snmp_global_save', { on: $('#snmpg-on').checked, ...snmpRootData($('#snmpg-fields')) });
      toast(L('Opgeslagen'));
      await refresh();
      loadSnmpGlobal();
    } catch (ex) { out.className = 'small bad'; out.textContent = '✘ ' + ex.message; }
  };
  $('#snmpg-test').onclick = async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    $('#snmpg-out').className = 'small muted';
    $('#snmpg-out').textContent = L('Bezig…');
    r.hosts.filter((x) => x.global).forEach((x) => { $(`#snmpg-st-${x.id}`).innerHTML = '<span class="muted">…</span>'; });
    try {
      const t = await api('snmp_global_test', {});
      let ok = 0;
      t.results.forEach((x) => {
        const el = $(`#snmpg-st-${x.id}`);
        if (x.ok) ok++;
        if (el) el.innerHTML = x.ok ? `<span class="good">✔ ${x.ms} ms${x.name ? ' · ' + esc(x.name) : ''}</span>` : `<span class="bad" title="${esc(x.error)}">✘ ${esc(x.error)}</span>`;
      });
      $('#snmpg-out').textContent = L('{a} van {n} antwoorden', { a: ok, n: t.results.length });
    } catch (ex) { $('#snmpg-out').className = 'small bad'; $('#snmpg-out').textContent = '✘ ' + ex.message; }
    btn.disabled = false;
  };
  $$('[data-snmpg-edit]', box).forEach((b) => (b.onclick = () => { const h = findHost(+b.dataset.snmpgEdit); if (h) hostForm(h); }));
}

/** 1.178.0: de SNMP-velden (versie t/m wachtwoorden), gedeeld door het serverformulier, de Agent Manager en de algemene instelling */
function snmpFieldsHtml(c) {
  c = { v: '2c', port: 161, community: '', level: 'authPriv', auth_proto: 'SHA', priv_proto: 'AES', ...(c || {}) };
  const seg = (name, list, cur) => `<input type="hidden" name="${name}" value="${esc(cur)}">${swSeg(name, list, cur, `data-snmp-field="${name}"`)}`;
  return `<div class="snmp-grid">
      <label>${L('Versie')} ${seg('snmp_v', [['2c', 'v2c'], ['3', 'v3']], c.v)}</label>
      <label>${L('Poort')} <input name="snmp_port" type="number" min="1" max="65535" value="${+c.port || 161}"></label>
    </div>
    <label data-snmp-v="2c">Community <span class="hint">${L('(alleen lezen; liever niet "public")')}</span>
      <input name="snmp_community" class="mono" autocomplete="off" spellcheck="false" maxlength="100" value="${esc(c.community || '')}"></label>
    <div data-snmp-v="3">
      <label>${L('Gebruiker')} <input name="snmp_user" class="mono" autocomplete="off" spellcheck="false" maxlength="64" value="${esc(c.user || '')}"></label>
      <label>${L('Beveiliging')} ${seg('snmp_level', [['authPriv', L('inloggen + versleutelen')], ['authNoPriv', L('alleen inloggen')], ['noAuthNoPriv', L('geen')]], c.level || 'authPriv')}</label>
      <div class="snmp-grid" data-snmp-lvl="auth">
        <label>${L('Auth-protocol')} ${seg('snmp_auth_proto', [['SHA', 'SHA'], ['SHA256', 'SHA-256'], ['SHA512', 'SHA-512'], ['MD5', 'MD5']], c.auth_proto || 'SHA')}</label>
        <label>${L('Auth-wachtwoord')} <input name="snmp_auth_pass" type="password" autocomplete="new-password" value="${esc(c.auth_pass || '')}"></label>
      </div>
      <div class="snmp-grid" data-snmp-lvl="priv">
        <label>${L('Privacy-protocol')} ${seg('snmp_priv_proto', [['AES', 'AES'], ['DES', L('DES (verouderd)')]], c.priv_proto || 'AES')}</label>
        <label>${L('Privacy-wachtwoord')} <input name="snmp_priv_pass" type="password" autocomplete="new-password" value="${esc(c.priv_pass || '')}"></label>
      </div>
    </div>`;
}
/** Velden tonen die bij de gekozen versie en beveiliging horen; de knoppen schrijven in het verborgen veld */
function snmpFieldsInit(root, onChange = () => {}) {
  const val = (n) => ($(`[name=${n}]`, root) || {}).value;
  const show = () => {
    const v = val('snmp_v'), lvl = val('snmp_level');
    $$('[data-snmp-v]', root).forEach((el) => { el.hidden = el.dataset.snmpV !== v; });
    $('[data-snmp-lvl=auth]', root).hidden = lvl === 'noAuthNoPriv';
    $('[data-snmp-lvl=priv]', root).hidden = lvl !== 'authPriv';
    onChange();
  };
  $$('[data-snmp-field]', root).forEach((seg) => seg.addEventListener('sw-seg', (e) => { $(`[name=${seg.dataset.snmpField}]`, root).value = e.detail; show(); }));
  show();
}
/** Wat er geldt als een server de algemene instelling volgt */
function snmpGlobalText() {
  const g = state.snmp_global;
  return g ? L('uit = de algemene instelling (v{v}{u})', { v: g.v, u: g.v === '3' && g.user ? ', ' + g.user : '' })
    : L('nog geen algemene instelling: vul die in bij Instellingen → Koppelingen → SNMP');
}

/** Blok "📡 SNMP" in het serverformulier (1.178.0: schakelaar, en eigen instellingen of de algemene); geheimen gemaskeerd */
function snmpHostFields(s) {
  const own = s ? s.use !== 'global' : !state.snmp_global;
  return `${swsOpen({ id: 'snmp-wrap', cb: 'name="snmp_on"', icon: '📡', title: 'SNMP', on: !!s, sub: `<span id="snmp-label"></span>` })}
    <label class="switch"><input type="checkbox" name="snmp_own" ${own ? 'checked' : ''}><span></span> ${L('Eigen instellingen')} <span class="hint">${esc(snmpGlobalText())}</span></label>
    <div id="snmp-fields" ${own ? '' : 'hidden'}>${snmpFieldsHtml(s || state.snmp_global)}</div>
    <div class="snmp-test-row"><button type="button" class="btn" id="snmp-test">🔍 ${L('Test SNMP')}</button><span class="small" id="snmp-test-out"></span></div>
    ${xpHtml(L('RzSense leest dit apparaat zelf uit via SNMP.'), L('<b>Wat het doet:</b> RzSense vraagt het apparaat zelf om gegevens (UDP-poort 161), zonder agent. <b>Instellen:</b> zet SNMP aan op het apparaat en geef het een community (v2c) of een gebruiker (v3). <b>Algemeen of eigen:</b> zonder <i>Eigen instellingen</i> gebruikt dit apparaat de algemene instelling uit <i>Instellingen → Koppelingen → SNMP</i>; verander je die, dan geldt dat meteen voor alle apparaten zonder eigen instellingen.'))}
  </div></section>`;
}

/** SNMP-velden uit het formulier, zoals host_save, host_snmp en snmp_test ze verwachten */
function snmpData(d) {
  return { on: !!d.snmp_on, ...(d.snmp_own ? {} : { use: 'global' }), v: d.snmp_v, port: d.snmp_port, community: d.snmp_community, user: d.snmp_user, level: d.snmp_level,
    auth_proto: d.snmp_auth_proto, auth_pass: d.snmp_auth_pass, priv_proto: d.snmp_priv_proto, priv_pass: d.snmp_priv_pass };
}

/** Uitslag van 🔍 Test SNMP als tekst */
function snmpSysText(s) {
  return `✔ ${esc(s.name || L('(geen naam)'))}${s.vendor ? ` · ${esc(s.vendor)}` : ''}${s.kind && KIND_INFO[s.kind] ? ` · ${esc(kindLabel(s.kind))}` : ''}${s.uptime !== null && s.uptime !== undefined ? ` · ${L('aan sinds {t}', { t: esc(fmtDur(s.uptime)) })}` : ''}`;
}

function snmpHostInit(h) {
  const form = $('#modal-form');
  const f = (n) => $(`[name=${n}]`, form);
  const label = () => {
    const on = f('snmp_on').checked, own = f('snmp_own').checked;
    $('#snmp-fields').hidden = !own;
    const v = own ? f('snmp_v').value : (state.snmp_global || {}).v;
    $('#snmp-label').textContent = !on ? L('uit') : own ? L('eigen instellingen (v{v})', { v }) : (v ? L('algemene instelling (v{v})', { v }) : L('algemene instelling (nog niet ingevuld)'));
  };
  snmpFieldsInit($('#snmp-fields'), label);
  f('snmp_on').addEventListener('change', label);
  f('snmp_own').addEventListener('change', label);
  label();
  $('#snmp-test').onclick = async () => {
    const out = $('#snmp-test-out');
    out.className = 'small muted';
    out.textContent = L('Bezig…');
    try {
      const d = Object.fromEntries(new FormData(form));
      const r = await api('snmp_test', { id: h && h.id, address: d.address, snmp: snmpData(d) });
      const s = r.sys;
      out.className = 'small good';
      if (s.kind && KIND_INFO[s.kind]) {
        $('#kind-auto-opt').textContent = kindAutoText(null, s.kind);
        $('[name=kind_auto]', form).value = s.kind;
      }
      out.innerHTML = snmpSysText(s) + (s.descr ? `<br><span class="muted">${esc(s.descr)}</span>` : '');
    } catch (ex) {
      out.className = 'small bad';
      out.textContent = '✘ ' + ex.message;
    }
  };
}

/** "3 d 4 u", "2 u 10 min" */
function fmtDur(sec) {
  const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
  return d > 0 ? `${d} ${L('d')} ${h} ${L('u')}` : h > 0 ? `${h} ${L('u')} ${m} min` : `${m} min`;
}

/** 📡 SNMP-checks: het apparaat doorzoeken en aanvinken wat je wilt bewaken; bestaande checks staan er al bij */
async function snmpChecks(h) {
  modal({ title: `📡 ${L('SNMP-checks')} – ${h.name}`, wide: true, body: `<p class="muted">${L('Het apparaat wordt doorzocht…')}</p>`, buttons: [] });
  let r;
  try {
    r = await api('snmp_discover', null, { id: h.id });
  } catch (ex) {
    modal({ title: `📡 ${L('SNMP-checks')} – ${h.name}`, body: `<p class="bad">✘ ${esc(ex.message)}</p><p class="muted small">${L('Controleer de SNMP-instellingen bij ✎ Bewerken (🔍 Test SNMP).')}</p>`,
      buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }] });
    return;
  }
  const have = new Set(h.checks.filter((c) => c.type === 'snmp').map((c) => `${c.metric}|${c.target || ''}`));
  const groups = {};
  r.items.forEach((it, i) => { (groups[it.group] = groups[it.group] || []).push([i, it]); });
  const s = r.sys;
  modal({
    title: `📡 ${L('SNMP-checks')} – ${h.name}`,
    wide: true,
    body: `<p style="margin-top:0"><b>${esc(s.name || h.name)}</b>${s.vendor ? ` · ${esc(s.vendor)}` : ''}${s.uptime !== null ? ` · ${L('aan sinds {d}', { d: esc(fmtDur(s.uptime)) })}` : ''}${s.location ? ` · ${esc(s.location)}` : ''}<br>
        <span class="muted small">${esc(s.descr)}</span></p>
      <p class="small">${L('Vink aan wat je wilt bewaken. De drempels kun je daarna per check aanpassen (✎). Iets anders nodig? Gebruik <b>＋ Eigen OID</b>.')}</p>
      <div class="rec-list">${Object.entries(groups).map(([g, list]) => `
        <div class="snmp-grp"><label class="switch small"><input type="checkbox" data-snmp-grp="${esc(g)}"><span></span> <b>${esc(g)}</b> <span class="muted small">(${list.length})</span></label></div>
        ${list.map(([i, it]) => {
          const exists = have.has(`${it.metric}|${it.target}`);
          return `<label class="rec-row" data-grp="${esc(g)}"><input type="checkbox" name="snmp_${i}" ${exists ? 'disabled' : it.checked ? 'checked' : ''}>
            <span><b>${esc(it.name)}</b> <span class="muted small">${esc(SNMP_METRICS[it.metric].label)}${it.target !== '' ? ` · ${esc(it.target)}` : ''}${exists ? ' · ' + L('wordt al bewaakt') : ''}</span><br>
            <span class="small muted">${esc(it.info)}</span></span></label>`;
        }).join('')}`).join('')}</div>`,
    buttons: [
      { label: L('＋ Eigen OID'), style: 'margin-right:auto', onClick: () => checkForm(null, h, { type: 'snmp', metric: 'oid', warn_threshold: null, crit_threshold: null }) },
      { label: L('Toevoegen'), cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      const chosen = r.items.filter((it, i) => d['snmp_' + i]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const it of chosen) {
        const m = SNMP_METRICS[it.metric];
        await api('check_save', { host_id: h.id, type: 'snmp', metric: it.metric, target: it.target, name: it.name, interval_sec: 60, enabled: true,
          warn_threshold: m.warn ?? '', crit_threshold: m.crit ?? '' });
      }
      toast(L('{n} SNMP-check(s) toegevoegd', { n: chosen.length }));
    },
  });
  // Groep in één keer aan of uit
  $$('[data-snmp-grp]').forEach((g) => {
    const rows = $$(`.rec-row[data-grp="${CSS.escape(g.dataset.snmpGrp)}"] input:not(:disabled)`);
    g.checked = rows.length > 0 && rows.every((x) => x.checked);
    g.onchange = () => rows.forEach((x) => { x.checked = g.checked; });
  });
}

/** Serverformulier: lijst "Bestanden bewaken" van deze server (alleen agent-servers, niet de HA-add-on) */
let fimHost = null;
async function hostFimInit(h) {
  fimHost = null;
  const wrap = $('#fim-host-wrap');
  const sel = $('#modal [name=mode]');
  const show = () => { wrap.hidden = sel.value !== 'agent'; };
  sel.addEventListener('change', show);
  show();
  let own = [], ha = false;
  if (h.id && isAdmin()) {
    try { const x = (await api('ids')).hosts.find((y) => +y.id === +h.id); if (x) { own = JSON.parse(x.ids_fim || '[]') || []; ha = !!x.ha; } } catch (e) { /* leeg beginnen */ }
  }
  if (!$('#fim-host')) return;   // formulier al dicht
  if (ha) { wrap.hidden = true; return; }
  const label = () => { const n = fimHost.get().length; $('#fim-host-label').textContent = n ? (n === 1 ? L('1 extra pad') : L('{n} extra paden', { n })) : L('alleen de algemene lijst'); };
  fimHost = fimEditor($('#fim-host'), own, h.id || null, label);
  label();
  if (own.length) swsSet(wrap, true);
}

/** Servers die als parent kunnen dienen: niet de server zelf en niets wat (indirect) eronder hangt. */
function parentOptions(h) {
  const below = new Set(h.id ? [+h.id] : []);
  let grew = true;
  while (grew) {
    grew = false;
    state.hosts.forEach((x) => {
      if (x.parent_id && below.has(+x.parent_id) && !below.has(+x.id)) { below.add(+x.id); grew = true; }
    });
  }
  return state.hosts
    .filter((x) => !below.has(+x.id))
    .map((x) => `<option value="${x.id}" ${+h.parent_id === +x.id ? 'selected' : ''}>${esc(x.name)} (${esc(x.address)})</option>`)
    .join('');
}

// ---------------------------------------------------------------- Acknowledge
function ackDialog(scope, id, label, detail) {
  modal({
    title: `✔ ${L('Bevestigen')} – ${label}`,
    body: `
      <p class="muted small" style="margin-top:0">${esc(detail || '')}</p>
      <p style="margin-top:0">${L('Na bevestigen krijg je voor deze storing <b>geen meldingen meer</b>: geen herhalingen, en ook niet als hij erger wordt. Zodra de check weer OK is vervalt de bevestiging; gaat het later opnieuw mis, dan krijg je gewoon weer een melding.')}</p>
      <label>${L('Opmerking')} <span class="hint">${L('(optioneel)')}</span><input name="comment" maxlength="200" placeholder="${L('bv. schijf wordt morgen vervangen')}" autofocus></label>`,
    buttons: [{ label: '✔ ' + L('Bevestigen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api(scope === 'host' ? 'ack_host' : 'ack', { id, comment: d.comment });
      toast(scope === 'host' ? L('{n} storing(en) bevestigd', { n: r.count }) : L('Storing bevestigd'));
    },
  });
}

// ---------------------------------------------------------------- Maintenance
function maintenanceDialog(scope, id, label, current) {
  const presets = [[15, '15 min'], [30, '30 min'], [60, L('1 uur')], [120, L('{n} uur', { n: 2 })], [240, L('{n} uur', { n: 4 })], [480, L('{n} uur', { n: 8 })], [1440, L('{n} uur', { n: 24 })], [10080, L('1 week')]];
  modal({
    title: `🔧 ${L('Onderhoud')} – ${label}`,
    body: `
      ${xpHtml(L('Tijdens onderhoud lopen de checks door, maar gaan er geen meldingen uit.'), L('De checks blijven draaien en je ziet de status gewoon, maar er gaan <b>geen meldingen</b> uit (Pushover, ntfy, e-mail en webhook). Is er na afloop nog iets mis, dan krijg je alsnog een melding.'))}
      ${current ? `<p class="maint-now">${L('Nu actief: {m}', { m: esc(maintLabel(current)) })}</p>` : ''}
      <div class="presets">${presets.map(([m, l]) => `<button type="button" class="btn" data-min="${m}">${l}</button>`).join('')}</div>
      <label>${L('Of tot een vast tijdstip')}<input type="datetime-local" name="until"></label>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200" placeholder="${L('bv. updates installeren')}" value="${esc(current?.reason || '')}"></label>`,
    buttons: [
      ...(current ? [{ label: L('Onderhoud beëindigen'), cls: 'danger', style: 'margin-right:auto', onClick: () => endMaintenance(scope, id) }] : []),
      { label: L('Tot dit tijdstip'), cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      if (!d.until) throw new Error(L('Kies een duur of een tijdstip'));
      await api('maintenance_set', { scope, id, until: d.until, reason: d.reason });
      toast(L('Onderhoud ingesteld'));
    },
  });
  $$('[data-min]', $('#modal-body')).forEach((b) => (b.onclick = async () => {
    try {
      await api('maintenance_set', { scope, id, minutes: +b.dataset.min, reason: $('#modal-form').reason.value });
      $('#modal').close();
      toast(L('Onderhoud: {d}', { d: b.textContent }));
      refresh();
    } catch (ex) { modalError(ex.message); }
  }));
}

async function endMaintenance(scope, id) {
  try {
    await api('maintenance_set', { scope, id, minutes: 0 });
    if ($('#modal').open) $('#modal').close();
    toast(L('Onderhoud beëindigd'));
    refresh();
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Check form
/** Adres van de heartbeat-URL van een check (zonder token: nog niet opgeslagen) */
function hbUrl(c) {
  const base = (state.base_url || location.origin + location.pathname.replace(/[^/]*$/, '')).replace(/\/+$/, '');
  return c && c.hb_token ? `${base}/hb.php/${c.hb_token}` : null;
}

/** Velden van een heartbeat-check: schema, speling, maximale duur, eenheid, URL en voorbeelden */
function hbFields(c, h, o) {
  const sched = o.sched || 'daily';
  const every = +(o.every || 1440);
  const [en, eu] = every % 1440 === 0 ? [every / 1440, 1440] : every % 60 === 0 ? [every / 60, 60] : [every, 1];
  const days = [L('zondag'), L('maandag'), L('dinsdag'), L('woensdag'), L('donderdag'), L('vrijdag'), L('zaterdag')];
  const name = c.type === 'heartbeat' && c.target ? c.target : 'NAAM';
  const url = c.type === 'heartbeat' ? hbUrl(c) : null;
  const agent = h.mode === 'agent' && !isHaHost(h);
  const wrap = 'rzmon-hb %N% start; /pad/naar/backup.sh && rzmon-hb %N% ok || rzmon-hb %N% fail "exit $?"';
  // %N% = naam van de check; wordt bijgewerkt terwijl je de naam typt
  const cmd = (s) => `<div class="hb-cmd"><code data-tpl="${esc(s)}">${esc(s.replaceAll('%N%', name))}</code><button type="button" class="icon-btn" data-copy="${esc(s.replaceAll('%N%', name))}" title="${esc(L('Kopiëren'))}">⧉</button></div>`;
  return `
    <div class="row2">
      <label>${L('Verwacht')}
        <select name="hb_sched">
          ${[['every', L('elke … (minuten, uren, dagen)')], ['daily', L('elke dag om …')], ['weekly', L('elke week op …')], ['cron', L('eigen cron-schema')]]
            .map(([v, l]) => `<option value="${v}" ${sched === v ? 'selected' : ''}>${l}</option>`).join('')}
        </select></label>
      <label data-hb="every">${L('Elke')}
        <span class="hb-every"><input name="hb_every_n" type="number" min="1" value="${en}">
        <select name="hb_every_u">${[[1, L('minuten')], [60, L('uur')], [1440, L('dagen')]].map(([v, l]) => `<option value="${v}" ${eu === v ? 'selected' : ''}>${l}</option>`).join('')}</select></span></label>
      <label data-hb="weekly">${L('Dag')}
        <select name="hb_dow">${days.map((d, i) => `<option value="${i}" ${+(o.dow ?? 0) === i ? 'selected' : ''}>${d}</option>`).join('')}</select></label>
      <label data-hb="daily weekly">${L('Om')} <input name="hb_at" type="time" value="${esc(o.at || '03:00')}"></label>
      <label data-hb="cron">${L('Cron-schema')} <span class="hint">${L('minuut uur dag maand weekdag')}</span>
        <input name="hb_cron" class="mono" value="${esc(o.cron || '')}" placeholder="30 2 * * 1-5"></label>
    </div>
    <div class="row2">
      <label>${L('Speling')} <span class="hint">${L('minuten; daarna WARNING, na 2× CRITICAL')}</span><input name="hb_grace" type="number" min="1" value="${o.grace ?? 60}"></label>
      <label>${L('Maximale duur')} <span class="hint">${L('minuten, na start (leeg = vanzelf: 2× het gemiddelde)')}</span><input name="hb_max" type="number" min="1" value="${o.max ?? ''}" placeholder="${esc(L('vanzelf'))}"></label>
    </div>
    <label>${L('Eenheid van een meegestuurde waarde')} <span class="hint">${L('(optioneel, bv. GB of bestanden; dan gelden de drempels hieronder)')}</span>
      <input name="hb_unit" maxlength="12" value="${esc(o.unit || '')}" placeholder="GB"></label>
    <label class="switch small"><input type="checkbox" name="hb_ext" ${o.ext ? 'checked' : ''}><span></span> ${L('De URL werkt ook van buiten het lokale netwerk')}</label>
    <div class="hb-box">
      ${agent ? `<p class="hint" style="margin:0 0 .3rem"><b>${L('Op {name} (agent 1.52.0 of nieuwer), versleuteld via de agent:', { name: esc(h.name) })}</b></p>
        ${cmd('rzmon-hb %N% start')}${cmd('rzmon-hb %N% ok')}${cmd('rzmon-hb %N% fail "reden"')}
        <p class="hint" style="margin:.2rem 0 .6rem">${L('Rond een script:')} <code data-tpl="${esc(wrap)}">${esc(wrap.replaceAll('%N%', name))}</code>. ${L('Met <code>--value 42</code> stuur je een waarde mee.')}</p>` : ''}
      ${url ? `<p class="hint" style="margin:0 0 .3rem"><b>${L('Via de geheime URL')}</b> ${o.ext ? '' : `(${L('alleen vanaf je lokale netwerk')})`}:</p>
        ${cmd(`curl -fsS -m 10 ${url}`)}${cmd(`curl -fsS -m 10 ${url}/start`)}${cmd(`curl -fsS -m 10 ${url}/fail --data "reden"`)}
        <label class="switch small"><input type="checkbox" name="hb_new_token"><span></span> ${L('Nieuwe geheime URL maken (de oude werkt dan meteen niet meer)')}</label>`
        : `<p class="hint" style="margin:0">${L('Na het opslaan staat hier de geheime URL.')}</p>`}
    </div>`;
}

/**
 * Check toevoegen of bewerken. tpl (1.94.0): { id, name, onSaved } = een check in een sjabloon (h is dan een denkbeeldige
 * server); opslaan gaat naar het sjabloon en van daaruit naar de gekoppelde servers.
 */
function checkForm(c, h, preset = {}, tpl = null) {
  const isNew = !c;
  const pt = TYPES[(preset || {}).type] || TYPES.ping;   // 1.138.0: standaarddrempels van het gekozen type, niet die van ping
  c = c || { type: 'ping', target: '', name: '', warn_threshold: pt.warn ?? null, crit_threshold: pt.crit ?? null, interval_sec: 60, enabled: 1, ...preset };
  const quietOwn = quietOf(c);
  const cmdCfg = (() => { try { return JSON.parse(c.command || '{}') || {}; } catch (e) { return {}; } })();
  const allowed = (h.mode === 'remote' ? ['ping', 'tcp', 'http', 'ssl', 'dns', 'ntp', 'mysql', 'domain', 'mqtt', 'vpn', 'snmp', 'heartbeat', 'internet', 'traffic']
    : Object.keys(TYPES).filter((k) => h.mode === 'agent' || !['podman', 'container', 'log', 'ha', 'command', 'pve', 'truenas', 'smart', 'ups', 'path'].includes(k))
      .filter((k) => k !== 'pve' || isPveHost(h) || c?.type === 'pve')
      .filter((k) => k !== 'truenas' || isTnHost(h) || c?.type === 'truenas')   // 1.174.0
      .filter((k) => k !== 'reboot' || h.mode !== 'remote' || c?.type === 'reboot')
      .filter((k) => k !== 'ha' || h.metrics.some((m) => m.startsWith('ha:') || m === 'service:supervisor') || c?.type === 'ha'))
    .filter((k) => k !== 'snmp' || h.snmp || c?.type === 'snmp')
    .filter((k) => k !== 'process' || !isHaHost(h) || c?.type === 'process');
  const disks = h.metrics.filter((m) => m.startsWith('disk:')).map((m) => m.slice(5));
  const svcs = h.metrics.filter((m) => m.startsWith('service:')).map((m) => m.slice(8));
  let opts = {};
  try { opts = JSON.parse(c.options || '{}') || {}; } catch (e) { opts = {}; }
  const certs = h.metrics.filter((m) => m.startsWith('cert:')).map((m) => m.slice(5));
  const containers = h.metrics.filter((m) => m.startsWith('container:')).map((m) => m.slice(10));

  // Formulier → wat de server verwacht (voor opslaan en voor 🧪 Nu proberen)
  // 1.107.0: slimme drempels. Nieuwe check: de standaard uit Instellingen; 'auto' (nog niet gedraaid) ook
  const smartMode = !c.thresh_mode || c.thresh_mode === 'auto' ? (state.thresh_default || 'guard') : c.thresh_mode;
  const payloadOf = (d) => {
    const notifySw = $('#notify-sw').checked;   // 1.178.0: schakelaar uit = de algemene instellingen
    if (!notifySw) d.notify_fails = '';
    if (!$('#confirm-sw').checked) { d.confirm_fails = ''; d.confirm_ok = ''; }   // 1.185.0: schakelaar uit = de algemene instellingen
    const notify = notifySw && $('#notify-own').checked && ownEditor ? ownEditor.get() : null;
    const qv = $('#quiet-sw').checked ? $('#quiet-sel').value : '';   // 1.189.0: een profiel of nooit stil
    const quiet = qv === 'never' ? { mode: 'never' } : qv.startsWith('p:') ? { mode: 'profile', id: +qv.slice(2) } : null;
    const command = d.type === 'command' ? { cmd: d.cmd, expect: d.cmd_expect, match: d.cmd_match, timeout: d.cmd_timeout, fail: d.cmd_fail } : undefined;
    const res = {};
    ['cpu', 'mem', 'disk'].forEach((k) => { res[k] = { on: !!d[`res_${k}`], warn: d[`res_${k}_warn`], crit: d[`res_${k}_crit`] }; });
    if (d.type === 'heartbeat') {
      d.hb_every = Math.round(+d.hb_every_n * +d.hb_every_u);
      d.hb_ext = !!d.hb_ext;
      d.hb_new_token = !!d.hb_new_token;
    }
    if (d.type === 'path') ['tp', 'link', 'hop', 'change'].forEach((k) => { d[k] = !!d[k]; });   // 1.177.0
    return { ...d, res, notify, quiet, command, enabled: !!d.enabled, verify_ssl: !!d.verify_ssl, deep: !!d.deep, info_only: !!d.info_only };
  };
  const { form } = modal({
    title: tpl ? `📋 ${tpl.name}: ${isNew ? L('check toevoegen') : c.name}` : isNew ? L('Check toevoegen – {name}', { name: h.name }) : L('Check bewerken – {name}', { name: c.name }),
    body: `
      ${tpl ? `${xpHtml(L('Met {adres} en {naam} vult RzSense per server het juiste adres en de naam in.'), L('Gebruik <code>{adres}</code> en <code>{naam}</code> in het doel of de naam: die worden per server ingevuld (bijvoorbeeld <code>https://{adres}/</code>). <b>Opslaan</b> werkt alle gekoppelde servers bij, behalve checks die daar zelf zijn aangepast.'))}` : ''}
      ${!tpl && c.template_item_id ? `<div class="callout small">📋 ${L('Uit het sjabloon <b>{t}</b>.', { t: esc((state.tpl_items || {})[c.template_item_id] || '?') })}
        ${+c.tpl_override ? `${L('Op deze server aangepast: wijzigingen aan het sjabloon gaan hier niet meer mee.')} <button type="button" class="btn small-btn" id="tpl-reset">↩ ${L('Terug naar het sjabloon')}</button>`
          : L('Pas je hem hier aan, dan gaat hij niet meer mee met het sjabloon (terugzetten kan altijd).')}</div>` : ''}
      <label>${L('Type')}
        <select name="type">${allowed.map((k) => `<option value="${k}" ${c.type === k ? 'selected' : ''}>${TYPES[k].label}</option>`).join('')}</select>
        <span class="hint" id="type-help"></span>
      </label>
      <label id="tn-wrap" hidden>${L('Bewaken')}
        <select name="metric">${Object.entries(TN_METRICS).map(([k, m]) => `<option value="${k}" ${(c.type === 'truenas' ? c.metric || 'alerts' : 'alerts') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="tn-help"></span>
      </label>
      <div id="path-wrap" hidden>
        <label>${L('Tussenliggende apparaten')} <span class="hint">${L('(optioneel: switches en andere apparaten op de weg, IP-adressen met komma\'s)')}</span>
          <input name="via" class="mono" list="path-via-list" value="${esc((opts.via || []).join(', '))}" placeholder="${L('bv. 192.168.2.5, 192.168.2.10')}">
          <datalist id="path-via-list">${pathViaOptions(h)}</datalist>
          <span class="hint">${L('Een switch zie je niet in een traceroute; met zijn IP-adres pingt RzSense hem wel en zet hem op de juiste plek in het pad.')}</span>
        </label>
        <label class="switch"><input type="checkbox" name="tp" ${opts.tp === 0 ? '' : 'checked'}><span></span> ${L('Doorvoer meten met iperf3')}</label>
        <div class="row2">
          <label>${L('Doorvoer naar')}<select name="tp_host"><option value="0">${L('Automatisch (een server met iperf3 in hetzelfde netwerk)')}</option>
            ${state.hosts.filter((x) => x.mode === 'agent' && +x.id !== +h.id).map((x) => `<option value="${x.id}" ${+opts.tp_host === +x.id ? 'selected' : ''}>${esc(x.name)}${+x.iperf_ok === 0 ? ' · ' + L('uit') : ''}</option>`).join('')}</select></label>
          <label>${L('Hoe vaak')}<select name="tp_every">${[[3600, L('Elk uur (max. 3 s)')], [86400, L('Eens per dag')], [0, L('Alleen met de knop')]].map(([v, l]) => `<option value="${v}" ${+(opts.tp_every ?? 3600) === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        </div>
        <div class="row2">
          <label>${L('Doorvoer onder')} <span class="hint">Mbit/s → WARNING</span><input name="tp_min" type="number" min="1" value="${esc(String(opts.tp_min ?? 200))}"></label>
          <label>${L('Verlies naar het doel')} <span class="hint">${L('warning / critical in %')}</span>
            <span style="display:flex;gap:.5rem"><input name="loss_warn" type="number" min="1" max="100" value="${esc(String(opts.loss_warn ?? 5))}" aria-label="warning %"><input name="loss_crit" type="number" min="1" max="100" value="${esc(String(opts.loss_crit ?? 20))}" aria-label="critical %"></span></label>
        </div>
        <label class="switch"><input type="checkbox" name="link" ${opts.link === 0 ? '' : 'checked'}><span></span> ${L('Melden als een linksnelheid zakt (bv. 1 Gbit → 100 Mbit)')}</label>
        <label class="switch"><input type="checkbox" name="hop" ${opts.hop === 0 ? '' : 'checked'}><span></span> ${L('Melden als een tussenliggend apparaat niet reageert (CRITICAL)')}</label>
        <label class="switch"><input type="checkbox" name="change" ${+opts.change ? 'checked' : ''}><span></span> ${L('Pad gewijzigd als ℹ️ info melden')}</label>
        <p class="hint">${L('De vertraging naar het doel staat hierboven bij de drempels (ms). Op de server zijn mtr (of traceroute) en voor de doorvoer iperf3 nodig, ook op het apparaat waar naartoe gemeten wordt; dat start daar kort een ontvanger die na één meting stopt.')}</p>
      </div>
      <label id="dm-wrap" hidden>${L('Wat controleren')}
        <select name="metric">${Object.entries(DOMAIN_METRICS).map(([k, m]) => `<option value="${k}" ${(c.type === 'domain' ? c.metric || '' : '') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
      </label>
      <div id="mail-x-wrap" hidden>
        <label>${L('DKIM-selectors')} <span class="hint">${L('(optioneel; de selector van je mailprovider, bv. google of selector1. De bekendste probeert RzSense zelf)')}</span>
          <input name="mail_dkim" class="mono" maxlength="300" value="${esc(c.type === 'domain' ? (opts.dkim || []).join(', ') : '')}" placeholder="google, selector1"></label>
        <label class="chk"><input type="checkbox" name="mail_nomail" ${opts.nomail ? 'checked' : ''}> ${L('Dit domein verstuurt geen e-mail (dan hoort er v=spf1 -all en DMARC p=reject te staan)')}</label>
        <label class="chk"><input type="checkbox" name="mail_starttls" ${opts.starttls ? 'checked' : ''}> ${L('Ook testen of de mailserver STARTTLS doet (poort 25; vaak dicht op een thuisverbinding)')}</label>
      </div>
      <label id="vpn-wrap" hidden>${L('Welke VPN')}
        <select name="metric">${Object.entries(VPN_METRICS).filter(([k]) => k !== 'wg' || h.mode === 'agent' || c.metric === 'wg').map(([k, m]) => `<option value="${k}" ${(c.type === 'vpn' ? c.metric || 'gluetun' : 'gluetun') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="vpn-help"></span>
      </label>
      <div id="cmd-wrap" hidden>
        <p class="hint" id="cmd-mode-info"></p>
        <label>${L('Voorinstelling')} <select id="cmd-preset">${presetOptions('linux')}</select></label>
        <p class="hint" id="cmd-preset-info"></p>
        <label>${L('Commando')} <textarea name="cmd" class="mono" rows="2" maxlength="1000" placeholder="${L('bv. zpool status -x')}">${esc(cmdCfg.cmd || '')}</textarea></label>
        <div class="row2">
          <label>${L('Goed als de uitvoer')}
            <select name="cmd_match">${Object.entries(CMD_MATCHES).map(([k, l]) => `<option value="${k}" ${(cmdCfg.match || 'equals') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
          </label>
          <label id="cmd-expect-wrap">${L('Verwacht antwoord')} <textarea name="cmd_expect" class="mono" rows="1" maxlength="1000" placeholder="${L('bv. all pools are healthy')}">${esc(cmdCfg.expect || '')}</textarea></label>
        </div>
        <div class="row2">
          <label>${L('Klopt het niet, dan')}
            <select name="cmd_fail"><option value="crit" ${cmdCfg.fail !== 'warn' ? 'selected' : ''}>🔴 Critical</option><option value="warn" ${cmdCfg.fail === 'warn' ? 'selected' : ''}>🟠 Warning</option></select>
          </label>
          <label>${L('Time-out')} <span class="hint">${L('seconden (1–60)')}</span><input name="cmd_timeout" type="number" min="1" max="60" value="${cmdCfg.timeout || 10}"></label>
        </div>
        ${xpHtml(L('Hoe RzSense de uitvoer vergelijkt en hoe het commando draait.'), L('<b>Vergelijken:</b> spaties en regeleindes aan het begin en eind tellen niet mee. Een regex schrijf je zonder scheidingstekens, bijvoorbeeld <code>^ok\\b</code> of <code>temp=[0-5][0-9]</code>. <b>Uitvoeren:</b> het commando draait in <code>bash</code>, zonder invoer, als <code>nobody</code>, of als root als dat voor deze server is toegestaan.'))}
      </div>
      <div id="hb-wrap" hidden>${hbFields(c, h, opts)}</div>
      <div id="log-wrap" hidden>
        <label>${L('Voorinstelling')}
          <select name="preset">${Object.entries(LOG_PRESETS).map(([k, p]) => `<option value="${k}" ${(opts.preset || 'custom') === k ? 'selected' : ''}>${p.label}</option>`).join('')}</select>
        </label>
        <label>${L('Zoek naar')} <span class="hint">${L('(regex; hoofdletters maakt niet uit; | = of)')}</span>
          <input name="pattern" class="mono" value="${esc(opts.pattern || '')}" placeholder="${L('bv. Failed password|Invalid user')}"></label>
        <span class="hint">${L('Bron: een logbestand (alleen in mappen die op de server zijn toegestaan, standaard /var/log), <code>journal</code> (systemlog), <code>journal:sshd</code> (log van één service) of <code>kernel</code>. Drempels: aantal treffers in de periode.')}</span>
      </div>
      <label id="snmp-metric-wrap" hidden>${L('Bewaken')}
        <select name="metric" id="snmp-metric">${Object.entries(SNMP_METRICS).map(([k, m]) => `<option value="${k}" ${(c.metric || 'uptime') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="snmp-help"></span>
      </label>
      <label id="target-wrap"><span id="target-label"></span><input name="target" value="${esc(c.target)}" list="target-list" class="mono"></label>
      <div id="http-x-wrap" hidden>
        <div class="row2">
          <label>${L('Methode')} <select name="http_method">${['GET', 'POST', 'HEAD', 'PUT'].map((m) => `<option ${(opts.method || 'GET') === m ? 'selected' : ''}>${m}</option>`).join('')}</select></label>
          <label class="http-body-x">${L('Content-Type')} <input name="http_ctype" class="mono" maxlength="100" value="${esc(opts.ctype || '')}" placeholder="application/json"></label>
        </div>
        <label class="http-body-x">${L('Body')} <textarea name="http_body" class="mono" rows="2" maxlength="4000" placeholder='{"ping":true}'>${esc(opts.body || '')}</textarea></label>
        <label>${L('Antwoord moet deze tekst bevatten')} <span class="hint">${L('(optioneel)')}</span><input name="http_expect" maxlength="200" value="${esc(opts.expect || '')}" placeholder="${L('bv. OK')}"></label>
        <div class="row2">
          <label>${L('JSON-veld')} <span class="hint">${L('(optioneel, bv. status of data.0.ok)')}</span><input name="http_json" class="mono" maxlength="100" value="${esc(opts.json || '')}"></label>
          <label>${L('met waarde')} <input name="http_json_value" class="mono" maxlength="200" value="${esc(opts.json_value || '')}" placeholder="ok"></label>
        </div>
      </div>
      <div id="dns-x-wrap" hidden>
        <div class="row2">
          <label>${L('Record')} <select name="dns_type">${['A', 'AAAA', 'MX', 'TXT', 'CNAME', 'NS'].map((r) => `<option ${(opts.rtype || 'A') === r ? 'selected' : ''}>${r}</option>`).join('')}</select></label>
          <label>${L('DNS-server')} <span class="hint">${L('(optioneel, IP)')}</span><input name="dns_server" class="mono" maxlength="45" value="${esc(opts.server || '')}" placeholder="192.168.1.1"></label>
        </div>
        <label>${L('Verwachte waarde')} <span class="hint">${L('(optioneel: moet in het antwoord staan)')}</span><input name="dns_expect" class="mono" maxlength="200" value="${esc(opts.expect || '')}" placeholder="203.0.113.10"></label>
      </div>
      <div id="db-x-wrap" hidden>
        <div class="row2">
          <label>${L('Database')} <select name="db_engine"><option value="mysql">MariaDB / MySQL</option><option value="pgsql" ${opts.engine === 'pgsql' ? 'selected' : ''}>PostgreSQL</option></select></label>
          <label>${L('Bewaken')} <select name="metric" id="db-metric">${Object.entries(DB_METRICS).map(([k, m]) => `<option value="${k}" ${(c.type === 'mysql' ? c.metric || 'overview' : 'overview') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select></label>
        </div>
        ${h.mode === 'agent' ? `<div class="row2">
          <label>${L('Meten')} <select name="db_via"><option value="agent">${L('Via de agent (lokaal, aanbevolen)')}</option><option value="rzmon" ${c.type === 'mysql' && !isNew && (opts.via || 'rzmon') === 'rzmon' ? 'selected' : ''}>${L('RzSense maakt verbinding (netwerk)')}</option></select></label>
          <label id="db-where-wrap">${L('Waar')} <select name="db_where"><option value="local">${L('Op de server zelf')}</option><option value="container" ${opts.where === 'container' ? 'selected' : ''}>${L('In een container')}</option></select></label>
        </div>
        <label id="db-ct-wrap">${L('Container')} <input name="db_container" list="db-ct-list" maxlength="160" value="${esc(opts.container || '')}" placeholder="mariadb"><datalist id="db-ct-list">${containers.map((x) => `<option value="${esc(x)}">`).join('')}</datalist></label>` : ''}
        <p class="hint" id="db-metric-help"></p>
        <div class="row2">
          <label>${L('Gebruiker')} <span class="hint" id="db-user-opt"></span><input name="db_user" maxlength="80" autocomplete="off" value="${esc(opts.user || '')}" placeholder="rzmon_check"></label>
          <label>${L('Wachtwoord')} <input name="db_pass" type="password" maxlength="200" autocomplete="new-password" value="${esc(opts.pass || '')}"></label>
        </div>
        <div class="row2">
          <label>${L('Naam van de database')} <span class="hint">${L('(optioneel)')}</span><input name="db_name" maxlength="64" value="${esc(opts.db || '')}"></label>
          <label id="db-longq-wrap">${L('Query "te lang" vanaf')} <span class="hint">${L('seconden')}</span><input name="db_longq" type="number" min="10" max="86400" value="${esc(opts.longq || 300)}"></label>
        </div>
        <p class="hint" id="db-hint"></p>
      </div>
      <div id="glu-x-wrap" hidden>
        <div class="row2">
          <label>${L('API-sleutel')} <span class="hint">${L('(optioneel)')}</span><input name="vpn_key" type="password" maxlength="200" autocomplete="new-password" value="${esc(c.type === 'vpn' ? opts.pass || '' : '')}"></label>
          <label>${L('Verwacht land')} <span class="hint">${L('(optioneel)')}</span><input name="vpn_country" maxlength="60" value="${esc(opts.country || '')}" placeholder="${L('bv. Netherlands')}"></label>
        </div>
      </div>
      <div id="mqtt-x-wrap" hidden>
        <div class="row2">
          <label>${L('Gebruiker')} <span class="hint">${L('(optioneel)')}</span><input name="net_user" maxlength="80" autocomplete="off" value="${esc(c.type === 'mqtt' ? opts.user || '' : '')}"></label>
          <label>${L('Wachtwoord')} <input name="net_pass" type="password" maxlength="200" autocomplete="new-password" value="${esc(c.type === 'mqtt' ? opts.pass || '' : '')}"></label>
        </div>
        <label class="chk"><input type="checkbox" name="mqtt_tls" ${opts.tls ? 'checked' : ''}> ${L('TLS (versleuteld, poort 8883; het certificaat moet kloppen)')}</label>
        <label class="chk"><input type="checkbox" name="mqtt_rt" ${c.type !== 'mqtt' || opts.rt === undefined || opts.rt ? 'checked' : ''}> ${L('Testbericht sturen en terug ontvangen (topic rzmon/check/…)')}</label>
        <div class="row2">
          <label>${L('Topic bekijken')} <span class="hint">${L('(optioneel)')}</span><input name="mqtt_topic" class="mono" maxlength="200" value="${esc(opts.topic || '')}" placeholder="zigbee2mqtt/bridge/state"></label>
          <label>${L('moet bevatten')} <span class="hint">${L('(optioneel)')}</span><input name="mqtt_expect" class="mono" maxlength="100" value="${esc(opts.expect || '')}" placeholder="online"></label>
        </div>
      </div>
      <div id="proc-pick-wrap" hidden>
        <div class="svc-pick-head"><span class="hint" id="proc-pick-info">${L('Processen op deze server ophalen…')}</span></div>
        <div class="svc-pick" id="proc-pick" role="listbox"></div>
      </div>
      <div id="cert-pick-wrap" hidden>
        <div class="svc-pick-head"><span class="hint" id="cert-pick-info">${L('Gevonden certificaten ophalen…')}</span>
          <button type="button" class="btn small-btn" id="cert-rescan">🔄 ${L('Opnieuw zoeken')}</button></div>
        <div class="svc-pick" id="cert-pick" role="listbox"></div>
      </div>
      <div id="pve-wrap" hidden>
        <label>${L('Bewaken')}<select name="metric">${Object.entries(PVE_METRICS).map(([k, m]) => `<option value="${k}" ${(c.type === 'pve' ? c.metric || 'overview' : 'overview') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
          <span class="hint" id="pve-help"></span></label>
        <label id="pve-per-wrap" hidden>${L('Afwijkend per guest')} <span class="hint">${L('(bv. <code>101=7/14 105=uit</code>: warning/critical in dagen, of niet bewaken)')}</span>
          <input name="pve_per" value="${esc(opts.per || '')}" placeholder="101=7/14 105=uit"></label>
      </div>
      <label id="tr-wrap" hidden>${L('Meten')}
        <select name="metric">${Object.entries(TRAFFIC_METRICS).map(([k, m]) => `<option value="${k}" ${(c.type === 'traffic' ? c.metric || 'rate' : 'rate') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="tr-help"></span>
      </label>
      <label id="inet-wrap" hidden>${L('Meten')}
        <select name="metric">${Object.entries(INET_METRICS).map(([k, m]) => `<option value="${k}" ${(c.type === 'internet' ? c.metric || 'ping' : 'ping') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="inet-help"></span>
      </label>
      <label id="ha-wrap" hidden>${L('Bewaken')}
        <select name="metric">${Object.entries(HA_METRICS).map(([k, m]) => `<option value="${k}" ${(c.metric || 'backup') === k ? 'selected' : ''}>${m.label}</option>`).join('')}</select>
        <span class="hint" id="ha-help"></span>
      </label>
      <div id="snmp-wrap-chk" hidden>
        <div id="oid-wrap" hidden>
          <div class="row2">
            <label>${L('Soort waarde')}
              <select name="oid_mode">${[['gauge', L('Getal (met drempels)')], ['counter', L('Teller (waarde per seconde)')], ['text', L('Tekst vergelijken')]].map(([v, l]) => `<option value="${v}" ${(opts.mode || 'gauge') === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
            </label>
            <label data-oid="num">${L('Eenheid')} <span class="hint">${L('(optioneel)')}</span><input name="oid_unit" maxlength="12" value="${esc(opts.unit || '')}" placeholder="${L('bv. °C, W, V')}"></label>
          </div>
          <div class="row2" data-oid="num">
            <label>${L('Vermenigvuldigen met')} <span class="hint">${L('(bv. 0.1 als het apparaat tienden geeft)')}</span><input name="oid_scale" type="number" step="any" value="${opts.scale ?? 1}"></label>
            <label class="switch" style="align-self:end"><input type="checkbox" name="oid_low" ${opts.low ? 'checked' : ''}><span></span> ${L('Lager is slechter')}</label>
          </div>
          <div class="row2" data-oid="text">
            <label>${L('Goed als de waarde')}
              <select name="oid_match">${Object.entries(OID_MATCHES).map(([v, l]) => `<option value="${v}" ${(opts.match || 'equals') === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
            </label>
            <label>${L('Verwacht')} <input name="oid_expect" class="mono" maxlength="150" value="${esc(opts.expect || '')}"></label>
          </div>
          <label data-oid="text">${L('Klopt het niet, dan')}
            <select name="oid_fail"><option value="crit" ${opts.fail !== 'warn' ? 'selected' : ''}>🔴 Critical</option><option value="warn" ${opts.fail === 'warn' ? 'selected' : ''}>🟠 Warning</option></select>
          </label>
        </div>
      </div>
      <div id="proc-wrap" hidden>
        <div class="row2">
          <label>${L('Zoeken op')}
            <select name="proc_mode"><option value="name" ${opts.mode !== 'pattern' ? 'selected' : ''}>${L('Naam van het proces')}</option><option value="pattern" ${opts.mode === 'pattern' ? 'selected' : ''}>${L('Patroon in de opdrachtregel (regex)')}</option></select>
          </label>
          <label>${L('Alleen van gebruiker')} <span class="hint">${L('(optioneel)')}</span><input name="proc_user" class="mono" maxlength="32" value="${esc(opts.user || '')}" placeholder="${L('bv. www-data')}"></label>
        </div>
        <div class="row2">
          <label>${L('Minimaal aantal')} <span class="hint">${L('(optioneel; minder = WARNING)')}</span><input name="proc_min" type="number" min="1" max="10000" value="${opts.min ?? ''}"></label>
          <label>${L('Maximaal aantal')} <span class="hint">${L('(optioneel; meer = WARNING)')}</span><input name="proc_max" type="number" min="1" max="10000" value="${opts.max ?? ''}"></label>
        </div>
      </div>
      <div id="ssl-from-wrap" hidden>
        <label>${L('Certificaat lezen')}
          <select name="cert_from">${[['', L('Automatisch (bestand en localhost via de agent, de rest vanaf RzSense)')], ['rzmon', L('Vanaf RzSense (via het netwerk)')], ['agent', L('Op de server zelf (agent)')]]
            .map(([v, l]) => `<option value="${v}" ${(opts.from || '') === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
          <span class="hint">${L('Via de agent zie je wat de server zelf aanbiedt, ook op poorten die alleen lokaal open staan.')}</span>
        </label>
      </div>
      <div id="res-wrap" hidden>
        <p class="hint" style="margin-top:0" id="res-intro">${L('✓ De <b>status</b> wordt altijd bewaakt: gestopt of ongezond = CRITICAL, gepauzeerd of net automatisch herstart = WARNING. Vink aan wat je daarnaast wilt bewaken:')}</p>
        ${['cpu', 'mem', 'disk'].map((k) => {
          const r = (opts.res || {})[k];
          const u = k === 'disk' ? 'MB' : '%';
          const def = c.type === 'process' ? PROC_RES[k] || [] : [METRICS[k].warn, METRICS[k].crit];
          return `<div class="res-row" data-res="${k}">
            <label class="switch small" title="${esc(METRICS[k].help)}"><input type="checkbox" name="res_${k}" ${r ? 'checked' : ''}><span></span> ${METRICS[k].label}</label>
            <label>${L('Warning vanaf')} <span class="hint res-unit">${u}</span><input type="number" step="any" min="0" name="res_${k}_warn" value="${r ? (r[0] ?? '') : def[0] ?? ''}"></label>
            <label>${L('Critical vanaf')} <span class="hint res-unit">${u}</span><input type="number" step="any" min="0" name="res_${k}_crit" value="${r ? (r[1] ?? '') : def[1] ?? ''}"></label>
          </div>`;
        }).join('')}
        <p class="hint" id="res-foot"></p>
      </div>
      <datalist id="target-list"></datalist>
      <div id="svc-pick-wrap" hidden>
        <div class="svc-pick-head"><span class="hint" id="svc-pick-info">${L('Services op deze server ophalen…')}</span></div>
        <div class="svc-pick" id="svc-pick" role="listbox"></div>
      </div>
      <label>${L('Naam')} <span class="hint">${L('(optioneel)')}</span><input name="name" value="${esc(c.name)}"></label>
      <div id="rev-wrap" class="row2">
        <label>${L('Status als de check slaagt')}
          <select name="reverse_ok">${REV_OK.map(([v, l]) => `<option value="${v}" ${+(c.reverse_ok ?? 0) === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        </label>
        <label id="rev-fail-wrap">${L('Status als de check mislukt')}
          <select name="reverse_fail">${REV_FAIL.map(([v, l]) => `<option value="${v}" ${+(c.reverse_fail ?? 0) === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        </label>
        <span class="hint" id="rev-hint" style="grid-column:1/-1"></span>
      </div>
      <label class="switch"><input type="checkbox" name="info_only" ${+c.info_only ? 'checked' : ''}><span></span> ℹ️ <b>${L('Alleen info-meldingen')}</b></label>
      ${xpHtml(L('Een info-check is nooit een storing; hij geeft alleen een ℹ️ info-melding.'), L('Bij een statuswissel komt er een ℹ️ info-melding in plaats van warning, critical of hersteld, en de check telt nooit als storing. <b>Waarheen:</b> dat staat bij de rij ℹ️ <i>Info</i> (Instellingen → Meldingen, of de eigen meldregels hieronder). Standaard staat info uit.'))}
      ${swsOpen({ id: 'notify-wrap', cb: 'id="notify-sw"', icon: '🔔', title: L('Meldingen'), on: !!(c.notify || c.notify_fails),
        sub: `<span id="notify-mode-label">${c.notify ? L('eigen regels') : L('standaard (algemene instellingen)')}</span><span id="notify-fails-label">${c.notify_fails ? ' · ' + L('na {n}× mislukt', { n: +c.notify_fails }) : ''}</span>` })}
        <label>${L('Melden na … mislukte checks achter elkaar')} <span class="hint">${L('(leeg = standaard, nu {n})', { n: state.notify_fails || 1 })}</span>
          <input name="notify_fails" type="number" min="1" max="100" value="${c.notify_fails ?? ''}" placeholder="${state.notify_fails || 1}" style="max-width:8rem"></label>
        <label class="switch small"><input type="checkbox" id="notify-own" ${c.notify ? 'checked' : ''}><span></span> <span id="notify-own-label">${L('Eigen meldregels voor deze check')}</span></label>
        <div id="notify-own-editor" ${c.notify ? '' : 'hidden'}></div>
      </div></section>
      ${swsOpen({ id: 'confirm-wrap', cb: 'id="confirm-sw"', icon: '⏳', title: L('Status bevestigen'), on: c.confirm_fails != null || c.confirm_ok != null,
        sub: `<span id="confirm-label">${c.confirm_fails != null || c.confirm_ok != null ? L('eigen aantallen') : L('standaard (algemene instellingen)')}</span>` })}
        ${xpHtml(L('Pas van status wisselen na een paar controles achter elkaar.'), L('Leeg = de algemene instelling (Instellingen → Meldingen → Wanneer melden). 1 = meteen. Warning, critical en onbekend tellen samen; is de check tussendoor één keer goed, dan begint de telling opnieuw.'))}
        <div class="conf-row">
          <label>${L('Warning of critical pas na … mislukte controles')} <span class="hint">${L('(leeg = standaard, nu {n})', { n: +state.confirm_fails > 1 ? state.confirm_fails : L('meteen') })}</span>
            <input name="confirm_fails" type="number" min="1" max="100" value="${c.confirm_fails ?? ''}" placeholder="${+state.confirm_fails || 1}" style="max-width:8rem"></label>
          <label>${L('Weer OK pas na … goede controles')} <span class="hint">${L('(leeg = standaard, nu {n})', { n: +state.confirm_ok > 1 ? state.confirm_ok : L('meteen') })}</span>
            <input name="confirm_ok" type="number" min="1" max="100" value="${c.confirm_ok ?? ''}" placeholder="${+state.confirm_ok || 1}" style="max-width:8rem"></label>
        </div>
      </div></section>
      ${swsOpen({ id: 'quiet-wrap', cb: 'id="quiet-sw"', icon: '🌙', title: L('Stille uren'), on: !!quietOwn, sub: `<span id="quiet-mode-label">${quietOwn ? esc(quietLabel(quietOwn)) : L('volgt de server') + (c.quiet_eff ? ': ' + esc(c.quiet_eff.name) : '')}</span>` })}
        <label>${L('Stille uren voor deze check')}<select id="quiet-sel">${quietOptions(quietOwn ? (quietOwn.mode === 'never' ? 'never' : quietOwn.mode === 'profile' ? 'p:' + quietOwn.id : '') : '', null)}</select></label>
        <span class="hint">${L('Profielen maak je bij Instellingen → Meldingen → Stille uren.')}</span>
      </div></section>
      <div class="row3" id="thr-wrap">
        <label><span class="thr-word">${L('Warning vanaf')}</span> <span class="hint unit"></span><input name="warn_threshold" type="number" step="any" value="${c.warn_threshold ?? ''}"></label>
        <label><span class="thr-word">${L('Critical vanaf')}</span> <span class="hint unit"></span><input name="crit_threshold" type="number" step="any" value="${c.crit_threshold ?? ''}"></label>
        <label>${L('Interval')}<select name="interval_sec">${intervalOptions(c.interval_sec)}</select></label>
      </div>
      <div id="smart-wrap" class="smart-wrap" hidden>
        <div class="smart-row">
          <label>${L('Drempels')}<select name="thresh_mode">${Object.entries(SMART_MODES).map(([k, l]) => `<option value="${k}" ${smartMode === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
          <label data-smart="guard"><span>${L('Altijd critical vanaf')}</span> <span class="hint unit"></span><input name="guard_threshold" type="number" step="any" value="${c.guard_threshold ?? ''}"></label>
          <label data-smart="smart guard">${L('Gevoeligheid')}<select name="smart_sens">
            <option value="">${L('standaard ({s})', { s: SMART_SENS[state.smart_sens] || SMART_SENS.normal })}</option>
            ${Object.entries(SMART_SENS).map(([k, l]) => `<option value="${k}" ${c.smart_sens === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        </div>
        <p class="hint" id="smart-hint"></p>
      </div>
      <div id="heal-wrap" hidden>
        <label>🩹 ${L('Zelf herstellen')}<select name="heal" id="heal-sel">
          <option value="">${L('uit')}</option>
          <option value="restart" ${c.heal === 'restart' ? 'selected' : ''}>${L('herstarten (service of container)')}</option>
          ${c.heal && c.heal.startsWith('cmd:') ? `<option value="${esc(c.heal)}" selected>${L('herstelcommando {n}', { n: esc(c.heal.slice(4)) })}</option>` : ''}
        </select></label>
        <p class="hint" id="heal-hint"></p>
      </div>
      <div id="deep-wrap" hidden>
        <label class="switch"><input type="checkbox" name="deep" ${+(opts.deep ?? 1) ? 'checked' : ''}><span></span> ${L('Diepere controle')}</label>
        <span class="hint">${L('Niet alleen "aan", maar ook: luistert hij op zijn poorten, antwoordt hij (HTTP, database, SSH, mail, DNS…), hoe vaak is hij herstart en staan er fouten in het log.')}</span>
        <label style="margin-top:.6rem">${L('Eigen poort(en) of socket')} <span class="hint">${L('(optioneel)')}</span>
          <input name="ports" class="mono" value="${esc(opts.ports || '')}" placeholder="${L('bv. 8080, 127.0.0.1:9000 of /run/php-fpm/www.sock')}">
          <span class="hint">${L('Voor als de agent de poort zelf niet vindt (container, socket, andere procesnaam). Wordt altijd getest, en de test van de dienst (HTTP, SSH…) gebruikt deze poort.')}</span>
        </label>
        <div class="row2" style="margin-top:.6rem">
          <label>${L('Extra test')} <span class="hint">${L('(optioneel)')}</span>
            <select name="test_type">
              <option value="">${L('Geen')}</option>
              <option value="url" ${opts.test_type === 'url' ? 'selected' : ''}>${L('URL moet antwoorden')}</option>
              <option value="port" ${opts.test_type === 'port' ? 'selected' : ''}>${L('Poort moet antwoorden')}</option>
            </select>
          </label>
          <label id="test-target-wrap">${L('Adres')}<input name="test_target" class="mono" value="${esc(opts.test_target || '')}"></label>
        </div>
        <label id="test-expect-wrap">${L('Moet deze tekst bevatten')} <span class="hint">${L('(optioneel)')}</span><input name="test_expect" value="${esc(opts.test_expect || '')}" placeholder="${L('bv. OK of &quot;status&quot;:&quot;up&quot;')}"></label>
        <span class="hint">${L('De agent voert de test uit op de server zelf, dus <code>localhost</code> werkt ook. Nieuwe tests worden binnen 10 minuten opgepikt.')}</span>
      </div>
      <label class="switch" id="verify-wrap"><input type="checkbox" name="verify_ssl" ${+(c.verify_ssl ?? 1) ? 'checked' : ''}><span></span> ${L('Certificaat controleren')} <span class="hint">${L('(uit bij self-signed, bv. Proxmox of een NAS)')}</span></label>
      <label class="switch"><input type="checkbox" name="enabled" ${+c.enabled ? 'checked' : ''}><span></span> ${L('Actief')}</label>`,
    buttons: [
      ...(isNew || tpl ? [] : [{ label: '🗑 ' + L('Verwijderen'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Check "{name}" verwijderen?', { name: c.name }))) return;
        await api('check_delete', { id: c.id });
        $('#modal').close(); toast(L('Check verwijderd')); refresh();
      } }]),
      ...(tpl ? [] : [{ label: '🧪 ' + L('Nu proberen'), onClick: (btn) => tryCheck(btn) }]),
      { label: tpl ? L('Opslaan in sjabloon') : isNew ? L('Toevoegen & checken') : L('Opslaan & checken'), cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      const payload = payloadOf(d);
      if (tpl) {
        await api('template_item_save', { ...payload, template_id: tpl.id, item_id: c.id });
        toast(L('Opgeslagen in het sjabloon'));
        setTimeout(() => tpl.onSaved && tpl.onSaved(), 50);
        return;
      }
      await api('check_save', { ...payload, id: c.id, host_id: h.id });
      toast(L('Check opgeslagen'));
    },
  });

  // 🧪 Nu proberen (1.95.0): wat de check nu zou zeggen, zonder iets op te slaan
  async function tryCheck(btn) {
    let out = $('#try-out');
    if (!out) { $('#modal-body').insertAdjacentHTML('beforeend', '<div id="try-out" class="try-out"></div>'); out = $('#try-out'); }
    btn.disabled = true;
    out.innerHTML = `<span class="muted">${L('Bezig…')}</span>`;
    try {
      const r = await api('check_try', { ...payloadOf(Object.fromEntries(new FormData(form))), host_id: h.id, check_id: c.id || 0 });
      out.innerHTML = `<b>${ST_ICON[r.status] || ''} ${esc(STATUS[r.status] || '')}</b>${r.value != null ? ` · ${esc(dec(+r.value))}${(TYPES[form.type.value] || {}).unit ? ' ' + esc(L(TYPES[form.type.value].unit)) : ''}` : ''}
        <div class="small">${esc(r.message)}</div>${h.mode === 'agent' && ['service', 'log', 'command', 'container', 'podman', 'process'].includes(form.type.value)
          ? `<div class="hint">${L('Op een agent-server gebruikt RzSense het laatste rapport; een nieuwe service, log of commando ziet de agent pas bij zijn volgende rapport.')}</div>` : ''}`;
    } catch (ex) { out.innerHTML = `<span class="bad">${esc(ex.message)}</span>`; }
    btn.disabled = false;
    out.scrollIntoView({ block: 'nearest' });
  }
  if ($('#tpl-reset')) $('#tpl-reset').onclick = async () => {
    try { await api('check_tpl_reset', { id: c.id }); $('#modal').close(); toast(L('Terug naar het sjabloon')); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  };
  // Eigen meldregels: start met wat er al was, of met de algemene instellingen
  let ownEditor = null;
  const showOwn = async () => {
    const on = $('#notify-own').checked;
    const info = form.info_only.checked;
    $('#notify-own-editor').hidden = !on;
    $('#notify-mode-label').textContent = on ? (info ? L('eigen info-kanalen') : L('eigen regels')) : L('standaard (algemene instellingen)');
    if (on) $('#modal').classList.add('wide');   // de tabel heeft ruimte nodig
    if (on && !ownEditor) {
      // Basis: de algemene instellingen; daaroverheen wat deze check al zelf had
      let own = null;
      try { own = JSON.parse(c.notify || 'null'); } catch (e) { own = null; }
      const g = await api('settings');
      const parse = (v) => { try { return JSON.parse(v || '{}') || {}; } catch (e) { return {}; } };
      const cfg = { rules: { ...parse(g.notify_rules), ...((own && own.rules) || {}) }, channels: { ...parse(g.notify_channels), ...((own && own.channels) || {}) },
        flap: (own && own.flap) || parse(g.notify_flap) };
      ownEditor = notifyEditor($('#notify-own-editor'), cfg,
        { push_enabled: g.pushover_enabled === '1' || g.ntfy_enabled === '1', email_enabled: g.email_enabled === '1', ha_webhook_enabled: g.ha_webhook_enabled === '1' });
    }
    ownEditor?.infoOnly(info);
  };
  $('#notify-own').onchange = showOwn;
  // Alleen info-meldingen: dan zijn alleen de info-kanalen van belang; uit = alles weer zichtbaar
  const applyInfoOnly = () => {
    const info = form.info_only.checked;
    $('#notify-own-label').textContent = info ? L('Eigen info-kanalen voor deze check') : L('Eigen meldregels voor deze check');
    showOwn();
  };
  form.info_only.onchange = applyInfoOnly;
  if (+c.info_only) applyInfoOnly();
  $('[name=notify_fails]', form).oninput = (e) => { $('#notify-fails-label').textContent = e.target.value ? ' · ' + L('na {n}× mislukt', { n: +e.target.value }) : ''; };
  if (c.notify) showOwn();
  // Stille uren (1.189.0): volgt de server, een profiel of nooit stil
  $('#quiet-sel').addEventListener('change', () => { $('#quiet-mode-label').textContent = $('#quiet-sel').selectedOptions[0]?.textContent || ''; });   // 1.189.0
  $('#notify-sw').addEventListener('change', () => {
    $('#notify-mode-label').textContent = $('#notify-sw').checked && $('#notify-own').checked ? L('eigen regels') : L('standaard (algemene instellingen)');
  });
  const typeSel = $('[name=type]', form);
  const haSel = $('#ha-wrap [name=metric]', form);
  const pveSel = $('#pve-wrap [name=metric]', form);
  const inetSel = $('#inet-wrap [name=metric]', form);
  const trSel = $('#tr-wrap [name=metric]', form);   // 1.187.0
  const vpnSel = $('#vpn-wrap [name=metric]', form);   // 1.170.0
  const dmSel = $('#dm-wrap [name=metric]', form);   // 1.227.0
  const tnSel = $('#tn-wrap [name=metric]', form);   // 1.174.0
  const dbSel = $('#db-metric', form);   // 1.200.0
  const dbVia = () => (h.mode === 'agent' && $('[name=db_via]', form) ? $('[name=db_via]', form).value : 'rzmon');
  /** 1.200.0: velden van de databasecheck: wat er bij deze database kan, en wat er nodig is */
  const dbForm = () => {
    const eng = $('[name=db_engine]', form).value, via = dbVia();
    $$('option', dbSel).forEach((o) => { const only = DB_METRICS[o.value].only; o.hidden = o.disabled = !!only && only !== eng; });
    if (dbSel.selectedOptions[0] && dbSel.selectedOptions[0].disabled) { dbSel.value = 'overview'; }
    const where = $('[name=db_where]', form) ? $('[name=db_where]', form).value : 'local';
    if ($('#db-where-wrap')) { $('#db-where-wrap').hidden = via !== 'agent'; $('[name=db_where]', form).disabled = via !== 'agent'; }
    if ($('#db-ct-wrap')) { const on = via === 'agent' && where === 'container'; $('#db-ct-wrap').hidden = !on; $('[name=db_container]', form).disabled = !on; $('[name=db_container]', form).required = on; }
    $('#db-longq-wrap').hidden = dbSel.value !== 'overview';
    $('#db-metric-help').textContent = DB_METRICS[dbSel.value].help;
    $('#db-user-opt').textContent = via === 'agent' ? L('(optioneel)') : '';
    $('#db-hint').innerHTML = via === 'agent'
      ? (where === 'container'
        ? L('De agent leest de database in de container uit met <code>docker exec</code> of <code>podman exec</code>. Zonder gebruiker: MariaDB/MySQL als root met het wachtwoord uit de container zelf (<code>MARIADB_ROOT_PASSWORD</code>), PostgreSQL als <code>postgres</code>.')
        : L('Zonder gebruiker leest de agent (als root) de database zonder wachtwoord: MariaDB/MySQL via de socket, PostgreSQL als gebruiker <code>postgres</code>. Op de server is de client nodig (<code>mariadb</code>/<code>mysql</code> of <code>psql</code>).'))
        + ' ' + L('Een wachtwoord stuurt RzSense alleen naar een versleutelde agent.')
      : (eng === 'pgsql'
        ? L('Een gebruiker met alleen leesrechten:') + ' <code>CREATE ROLE rzmon_check LOGIN PASSWORD \'…\' IN ROLE pg_monitor;</code>'
        : L('Een gebruiker met alleen leesrechten:') + ' <code>CREATE USER \'rzmon_check\'@\'%\' IDENTIFIED BY \'…\'; GRANT PROCESS, REPLICATION CLIENT ON *.* TO \'rzmon_check\'@\'%\';</code>');
  };
  dbSel.onchange = () => update('metric');
  $('[name=db_engine]', form).onchange = () => { const before = dbSel.value; dbForm(); update(dbSel.value !== before ? 'metric' : false); };
  if ($('[name=db_via]', form)) $('[name=db_via]', form).onchange = () => update(false);
  if ($('[name=db_where]', form)) $('[name=db_where]', form).onchange = () => update(false);
  $('[name=reverse_ok]', form).onchange = () => update(false);
  const snmpSel = $('#snmp-metric');
  const update = (setDefaults) => {
    const isContainer = typeSel.value === 'container';
    const isHa = typeSel.value === 'ha';
    const isPve = typeSel.value === 'pve';
    const isInet = typeSel.value === 'internet';
    const isTr = typeSel.value === 'traffic';
    const isVpn = typeSel.value === 'vpn';
    const isDm = typeSel.value === 'domain';   // 1.227.0
    const isTn = typeSel.value === 'truenas';
    const isDb = typeSel.value === 'mysql';   // 1.200.0
    const isSnmp = typeSel.value === 'snmp';
    const oidMode = $('[name=oid_mode]', form).value;
    const isHb = typeSel.value === 'heartbeat';
    const t = isHa ? { ...TYPES.ha, ...HA_METRICS[haSel.value] }
      : isPve ? { ...TYPES.pve, ...PVE_METRICS[pveSel.value] }
      : isInet ? { ...TYPES.internet, ...INET_METRICS[inetSel.value] }
      : isTr ? { ...TYPES.traffic, ...TRAFFIC_METRICS[trSel.value] }
      : isVpn ? { ...TYPES.vpn, ...VPN_METRICS[vpnSel.value] }
      : isDm && dmSel.value === 'mail' ? { ...TYPES.domain, ...DOMAIN_METRICS.mail }
      : isTn ? { ...TYPES.truenas, ...TN_METRICS[tnSel.value] }
      : isDb ? { ...TYPES.mysql, ...DB_METRICS[dbSel.value], target: dbVia() === 'rzmon' ? TYPES.mysql.target : null }
      : isSnmp ? snmpType({ metric: snmpSel.value, options: JSON.stringify({ mode: oidMode, unit: $('[name=oid_unit]', form).value, low: $('[name=oid_low]', form).checked }) })
        : isHb ? { ...TYPES.heartbeat, unit: $('[name=hb_unit]', form).value.trim() || null }
          : TYPES[typeSel.value];
    $('#hb-wrap').hidden = !isHb;
    $$('#hb-wrap input, #hb-wrap select', form).forEach((el) => { el.disabled = !isHb; });
    if (isHb) {
      const sc = $('[name=hb_sched]', form).value;
      $$('[data-hb]', form).forEach((el) => { el.hidden = !el.dataset.hb.split(' ').includes(sc); });
    }
    $('#ha-wrap').hidden = !isHa;
    haSel.disabled = !isHa;   // niet meesturen bij andere types
    $('#pve-wrap').hidden = !isPve;
    pveSel.disabled = !isPve;
    $('#inet-wrap').hidden = !isInet;
    inetSel.disabled = !isInet;
    $('#tr-wrap').hidden = !isTr;
    trSel.disabled = !isTr;
    $('#tr-help').textContent = isTr ? TRAFFIC_METRICS[trSel.value].help : '';
    $('#inet-help').textContent = isInet ? INET_METRICS[inetSel.value].help : '';
    $('#vpn-wrap').hidden = !isVpn;
    vpnSel.disabled = !isVpn;
    $('#dm-wrap').hidden = !isDm;
    dmSel.disabled = !isDm;
    $('#mail-x-wrap').hidden = !(isDm && dmSel.value === 'mail');
    $$('#mail-x-wrap input', form).forEach((el) => { el.disabled = !(isDm && dmSel.value === 'mail'); });
    $('#tn-wrap').hidden = !isTn;
    tnSel.disabled = !isTn;
    const isPath = typeSel.value === 'path';   // 1.177.0
    $('#path-wrap').hidden = !isPath;
    $$('#path-wrap input, #path-wrap select', form).forEach((el) => { el.disabled = !isPath; });
    if (isPath && isNew && $('[name=interval_sec]', form).value === '60') $('[name=interval_sec]', form).value = '300';
    $('#tn-help').textContent = isTn ? TN_METRICS[tnSel.value].help : '';
    $('#vpn-help').textContent = isVpn ? VPN_METRICS[vpnSel.value].help : '';
    $('#glu-x-wrap').hidden = !(isVpn && vpnSel.value === 'gluetun');
    $('#mqtt-x-wrap').hidden = typeSel.value !== 'mqtt';
    $('#pve-per-wrap').hidden = !isPve || pveSel.value !== 'backups';
    $('[name=pve_per]', form).disabled = !isPve || pveSel.value !== 'backups';
    $('#pve-help').textContent = isPve ? PVE_METRICS[pveSel.value].help : '';
    $('#snmp-wrap-chk').hidden = !isSnmp;
    $('#snmp-metric-wrap').hidden = !isSnmp;
    snmpSel.disabled = !isSnmp;
    $('#snmp-help').textContent = isSnmp ? SNMP_METRICS[snmpSel.value].help : '';
    const isOid = isSnmp && snmpSel.value === 'oid';
    $('#oid-wrap').hidden = !isOid;
    $$('#oid-wrap [data-oid]', form).forEach((el) => { el.hidden = (el.dataset.oid === 'text') !== (oidMode === 'text'); });
    $('#ha-help').textContent = isHa ? HA_METRICS[haSel.value].help : '';
    $('#type-help').textContent = isDm && dmSel.value === 'mail' ? DOMAIN_METRICS.mail.help : TYPES[typeSel.value].help;   // 1.227.0
    const isProc = typeSel.value === 'process';
    $('#res-wrap').hidden = !isContainer && !isProc;
    $('#proc-wrap').hidden = !isProc;
    $$('#proc-wrap input, #proc-wrap select', form).forEach((el) => { el.disabled = !isProc; });
    $('[data-res=disk]', form).hidden = isProc;
    $('[name=res_disk]', form).disabled = isProc;
    $$('[data-res=mem] .res-unit', form).forEach((u) => (u.textContent = isProc ? 'MB' : '%'));
    $('#res-intro').innerHTML = isProc ? L('Vink aan wat je daarnaast wilt bewaken (alle processen met deze naam of dit patroon samen):')
      : L('✓ De <b>status</b> wordt altijd bewaakt: gestopt of ongezond = CRITICAL, gepauzeerd of net automatisch herstart = WARNING. Vink aan wat je daarnaast wilt bewaken:');
    $('#res-foot').textContent = isProc ? L('CPU: 100% = één volledige core. RAM: werkgeheugen (RSS) in MB.')
      : L('CPU: 100% = één volledige core. RAM: percentage van de geheugenlimiet van de container. Disk: schrijflaag plus volumes.');
    const isSsl = typeSel.value === 'ssl';
    $('#ssl-from-wrap').hidden = !isSsl || h.mode === 'remote';
    $('[name=cert_from]', form).disabled = !isSsl;
    $('#cert-pick-wrap').hidden = !(isSsl && h.mode === 'agent' && certList);
    $('#proc-pick-wrap').hidden = !(isProc && procList);
    if (isSsl && h.mode === 'agent' && h.id && !certList) loadCerts();
    if (isProc && h.mode !== 'remote' && h.id && !procList) loadProcs();
    if (isSsl) renderCertPick();
    if (isProc) renderProcPick();
    $$('#res-wrap input[type=number]', form).forEach((i) => { i.disabled = !$(`[name=${i.name.replace(/_(warn|crit)$/, '')}]`, form).checked; });
    $('#target-wrap').hidden = !t.target;
    $('#target-label').textContent = t.target || '';
    $('[name=target]', form).placeholder = t.ph || '';
    $$('.unit', form).forEach((u) => (u.textContent = t.unit ? L(t.unit) : ''));
    $$('#thr-wrap label', form).slice(0, 2).forEach((l) => (l.hidden = !t.unit || !!t.nothresh));
    // Slimme drempels: alleen bij checks die kunnen leren (niet in een sjabloon, niet omgekeerd)
    const smartOk = !tpl && smartEligible(typeSel.value, isSnmp ? snmpSel.value : isInet ? inetSel.value : isDb ? dbSel.value : typeSel.value === 'container' ? ($('[name=metric]:not(:disabled)', form) || {}).value : null) && !(+$('[name=reverse_ok]', form).value > 0);
    $('#smart-wrap').hidden = !smartOk;
    $$('#smart-wrap select, #smart-wrap input', form).forEach((el) => { el.disabled = !smartOk; });
    const sm = smartOk ? $('[name=thresh_mode]', form).value : 'manual';
    $$('#smart-wrap [data-smart]', form).forEach((el) => {
      const on = el.dataset.smart.split(' ').includes(sm);
      el.hidden = !on;
      $$('input, select', el).forEach((i) => { i.disabled = !smartOk || !on; });
    });
    // 🩹 Zelf herstellen: alleen op een server met de agent (niet in een sjabloon)
    const healOk = !tpl && h.mode === 'agent' && !isHaHost(h);
    $('#heal-wrap').hidden = !healOk;
    $('#heal-sel').disabled = !healOk;
    const canRestart = ['service', 'container'].includes(typeSel.value);
    const rOpt = $('#heal-sel option[value=restart]');
    rOpt.hidden = rOpt.disabled = !canRestart;
    if (!canRestart && $('#heal-sel').value === 'restart') $('#heal-sel').value = '';
    const hv = $('#heal-sel').value;
    $('#heal-hint').innerHTML = !healOk ? '' : hv === 'restart'
      ? L('Draait de {w} niet, dan herstart de agent hem zelf, vóór zijn rapport. Lukt dat, dan krijg je alleen een ℹ️-melding; lukt het niet, de gewone melding. Hooguit één poging per 10 minuten en 3 per dag.', { w: typeSel.value === 'service' ? L('service') : L('container') })
      : hv.startsWith('cmd:') ? L('Gaat deze check mis, dan voert de agent het herstelcommando uit (alleen via een versleutelde verbinding) en meldt RzSense pas als dat niet helpt, of na 5 minuten. Het commando zelf staat alleen op de server, in <code>/etc/rzmon-agent/heal.d/</code>.')
      : L('Eigen herstelcommando\'s zet je op de server in <code>/etc/rzmon-agent/heal.d/</code> (uitvoerbaar, van root, niet door anderen te wijzigen); daarna staan ze hier.');
    if (smartOk && sm === 'guard' && $('[name=guard_threshold]', form).value === '' && setDefaults) $('[name=guard_threshold]', form).value = smartGuard(typeSel.value, isSnmp ? snmpSel.value : null) ?? '';
    $('#smart-hint').innerHTML = !smartOk ? '' : sm === 'manual' ? L('De drempels hierboven gelden altijd.')
      : L('RzSense leert {n} dagen wat normaal is, per uur van de week; tot die tijd gelden de drempels hierboven. Daarna stelt hij ze elke nacht bij (hooguit 10% per nacht). Elke aanpassing staat in het wijzigingslogboek, terug te draaien.', { n: 7 })
        + (sm === 'guard' ? ' ' + L('Vanaf het vangnet is het altijd CRITICAL, wat RzSense ook geleerd heeft.') : '');
    const words = $$('.thr-word', form);
    words[0].textContent = t.low ? L('Warning onder') : L('Warning vanaf');
    words[1].textContent = t.low ? L('Critical onder') : L('Critical vanaf');
    $('#verify-wrap').hidden = !['http', 'ssl'].includes(typeSel.value) || (isSsl && $('[name=target]', form).value.trim().startsWith('/'));
    // Omgekeerde status: alleen bij werkt-het-wel-of-niet-checks
    const rev = REVERSIBLE.includes(typeSel.value);
    $('#rev-wrap').hidden = !rev;
    $('[name=reverse_ok]', form).disabled = !rev;
    const revOn = rev && +$('[name=reverse_ok]', form).value > 0;
    $('#rev-fail-wrap').hidden = !revOn;
    $('[name=reverse_fail]', form).disabled = !revOn;
    $('#rev-hint').innerHTML = revOn ? L('⇄ <b>Omgekeerd:</b> reageert het (ook met een waarschuwing), dan krijgt de check de eerste status; reageert het niet, de tweede. Handig voor iets dat juist níet mag draaien of bereikbaar mag zijn. Onbekend blijft onbekend.') : '';
    // Diepere controle en extra test: alleen services op agent-servers
    const deep = typeSel.value === 'service' && h.mode === 'agent';
    $('#deep-wrap').hidden = !deep;
    const tt = $('[name=test_type]', form).value;
    $('#test-target-wrap').hidden = !tt;
    $('#test-expect-wrap').hidden = tt !== 'url';
    $('[name=test_target]', form).placeholder = tt === 'port' ? L('8080 of localhost:8080') : 'http://localhost:8080/health';
    $('[name=test_target]', form).required = deep && !!tt;
    $('[name=target]', form).required = !!t.target && !['http', 'ssl', 'ntp', 'smart', 'ups', 'mysql', 'mqtt', 'vpn'].includes(typeSel.value);
    $('[name=target]', form).disabled = !t.target;   // niet meesturen als er geen doel is (bv. HA-checks zonder doel)
    const haLogs = ['ha:host', 'ha:core', 'ha:supervisor', ...svcs.filter((x) => x.startsWith('addon:')).map((x) => `ha:${x}`)];
    const usbs = h.metrics.filter((m) => m.startsWith('usb:')).map((m) => m.slice(4));
    const list = isHa && haSel.value === 'usb' ? usbs
      : isPve ? h.metrics.filter((m) => m.startsWith(pveSel.value === 'storage' ? 'pves:' : 'pveg:')).map((m) => m.slice(5))
      : { disk: disks, service: svcs, ssl: certs, container: containers, log: isHaHost(h) ? haLogs : LOG_SOURCES,
        smart: h.metrics.filter((m) => m.startsWith('smart:')).map((m) => m.slice(6)), ups: h.metrics.filter((m) => m.startsWith('ups:')).map((m) => m.slice(4)),
        vpn: vpnSel.value === 'wg' ? h.metrics.filter((m) => m.startsWith('wg:')).map((m) => m.slice(3)) : [] }[typeSel.value] || [];
    $('#log-wrap').hidden = typeSel.value !== 'log';
    // 1.138.0: extra velden voor HTTP, DNS en MariaDB
    $('#http-x-wrap').hidden = typeSel.value !== 'http';
    $$('.http-body-x', form).forEach((x) => (x.hidden = !['POST', 'PUT'].includes($('[name=http_method]', form).value)));
    $('#dns-x-wrap').hidden = typeSel.value !== 'dns';
    $('#db-x-wrap').hidden = !isDb;
    dbSel.disabled = !isDb;
    if (isDb) dbForm();
    $('[name=db_user]', form).required = isDb && dbVia() === 'rzmon';
    const isCmd = typeSel.value === 'command';
    $('#cmd-wrap').hidden = !isCmd;
    $('[name=cmd]', form).required = isCmd;
    $('#cmd-expect-wrap').hidden = $('[name=cmd_match]', form).value === 'exit0';
    if (isCmd && !cmdInfoLoaded) {
      cmdInfoLoaded = true;
      api('agent_services', null, { id: h.id }).then((r) => {
        const { platform } = detectPlatform(r.list);
        const sel = $('#cmd-preset');
        if (sel) sel.innerHTML = presetOptions(platform, sel.value);
      }).catch(() => {});
      api('agent_commands').then((r) => {
        const m = (r.hosts.find((x) => x.id === +h.id) || {}).mode || 'off';
        cmdMode = m;
        $('#cmd-mode-info').innerHTML = m === 'off'
          ? L('<b class="warn-txt">⚠️ Commando\'s staan voor {name} uit.</b> De check blijft onbekend tot je ze aanzet: Instellingen → Agents → Commando-checks, of per server in de Agent Manager.', { name: esc(h.name) })
          : L('Commando\'s op {name}: <b>{mode}</b>.', { name: esc(h.name), mode: esc(CMD_MODES[m]) });
      }).catch(() => {});
    }
    $('[name=pattern]', form).required = typeSel.value === 'log';
    $('#target-list').innerHTML = list.map((v) => `<option value="${esc(v)}">`).join('');
    // 1.240.0: bij netwerkchecks ook de apparaten uit de netwerkscan en je servers als keuze
    if (['ping', 'tcp'].includes(typeSel.value)) {
      const tSel = typeSel.value;
      pickSources().then((d) => {
        if (typeSel.value !== tSel || !$('#target-list')) return;
        $('#target-list').insertAdjacentHTML('beforeend', PL_DEV(d || {}).filter(([v]) => !list.includes(v)).map(([v, l]) => `<option value="${esc(v)}" label="${esc(l || '')}">`).join(''));
      });
    }
    $('#svc-pick-wrap').hidden = !(typeSel.value === 'service' && h.mode === 'agent' && svcList);
    if (typeSel.value === 'service' && h.mode === 'agent') renderSvcPick();
    if (setDefaults && setDefaults !== 'smart') {
      $('[name=warn_threshold]', form).value = t.warn ?? '';
      $('[name=crit_threshold]', form).value = t.crit ?? '';
      if (setDefaults !== 'metric') $('[name=target]', form).value = typeSel.value === 'disk' ? '/' : '';
      // 1.98.0: nieuwe certificaatcheck telt alleen de vervaldatum (self-signed en eigen CA's zijn dan ook goed)
      if (isSsl && isNew) form.verify_ssl.checked = false;
      if (isProc || isContainer) {
        ['cpu', 'mem'].forEach((k) => {
          const d = isProc ? PROC_RES[k] : [METRICS[k].warn, METRICS[k].crit];
          if (!$(`[name=res_${k}]`, form).checked) { $(`[name=res_${k}_warn]`, form).value = d[0]; $(`[name=res_${k}_crit]`, form).value = d[1]; }
        });
      }
    }
  };
  let cmdInfoLoaded = false;
  let cmdMode = 'off';
  const presetInfo = () => {
    const p = CMD_PRESETS[$('#cmd-preset').value];
    $('#cmd-preset-info').innerHTML = !p ? '' : [
      p.root ? (cmdMode === 'root' ? L('Draait als root.') : L('<b class="warn-txt">⚠️ Heeft root nodig</b>, en commando\'s op {name} staan {mode}: zet ze op <i>als root</i> (Agent Manager), anders mislukt hij.', { name: esc(h.name), mode: esc(CMD_MODES[cmdMode]) })) : L('Werkt als nobody.'),
      p.tested ? '' : `<span class="muted">${L('Nog niet op een echt systeem getest; laat weten als hij niet goed uitkomt.')}</span>`,
    ].filter(Boolean).join(' ');
  };
  // Servicekiezer (agent-servers): alle services op de server, met status; filtert op wat je typt
  let svcList = null;
  const watched = new Set((h.checks || []).filter((x) => x.type === 'service' && (!c.id || +x.id !== +c.id)).map((x) => x.target));
  const renderSvcPick = () => {
    if (!svcList) return;
    const q = $('[name=target]', form).value.trim().toLowerCase();
    const rows = svcList.filter(([n]) => !q || n.toLowerCase().includes(q));
    const label = { active: L('actief'), failed: L('mislukt'), inactive: L('uit'), activating: L('start op'), deactivating: L('stopt'), stopped: L('gestopt'), error: L('fout'), update: L('update beschikbaar') };
    $('#svc-pick').innerHTML = rows.length ? rows.map(([n, st, en]) => `
      <button type="button" class="svc-item${$('[name=target]', form).value === n ? ' sel' : ''}" data-svc="${esc(n)}" ${watched.has(n) ? 'disabled' : ''}>
        <span class="svc-dot ${st === 'active' ? 'ok' : st === 'failed' || st === 'error' ? 'crit' : st === 'update' ? 'warn' : 'off'}"></span>
        <span class="mono">${esc(n)}</span>
        <span class="hint">${esc(label[st] || st)}${en ? '' : ' · ' + L('start niet automatisch')}${watched.has(n) ? ' · ' + L('wordt al bewaakt') : ''}</span>
      </button>`).join('') : `<p class="hint">${q ? L('Geen service gevonden met "{q}". Je kunt de naam ook zelf typen.', { q: esc(q) }) : L('Geen service gevonden. Je kunt de naam ook zelf typen.')}</p>`;
    $('#svc-pick-info').textContent = L('{n} services op {name} (die aan staan of draaien). Klik om te kiezen; de agent bewaakt hem dan vanzelf. Staat hij er niet bij, typ dan de naam.', { n: svcList.length, name: h.name });
  };
  if (h.mode === 'agent') {
    api('agent_services', null, { id: h.id }).then((r) => {
      if (!r.list) { $('#svc-pick-info').textContent = L('Deze agent stuurt nog geen lijst met services (bijwerken naar de nieuwste versie). Typ de naam zelf.'); }
      svcList = r.list || [];
      update(false);
      $('#svc-pick-wrap').hidden = !(typeSel.value === 'service');
      if (!r.list) $('#svc-pick').innerHTML = '';
    }).catch(() => {});
    $('#svc-pick').onclick = (e) => {
      const b = e.target.closest('[data-svc]');
      if (!b) return;
      const name = $('[name=name]', form);
      if (!name.value || name.value === $('[name=target]', form).value) name.value = b.dataset.svc;
      $('[name=target]', form).value = b.dataset.svc;
      renderSvcPick();
    };
    $('[name=target]', form).addEventListener('input', () => { if (typeSel.value === 'service') renderSvcPick(); });
  }
  // Proceskiezer (1.98.0): wat er nu draait op de server (agent, of RzSense zelf), filtert op wat je typt
  let procList = null;
  const loadProcs = () => {
    procList = [];
    api('agent_procs', null, { id: h.id }).then((r) => {
      procList = r.items || [];
      if (!procList.length) $('#proc-pick-info').textContent = h.mode === 'agent' ? L('Deze agent stuurt nog geen lijst met processen (agent 1.59.0 of nieuwer). Typ de naam zelf.') : L('Geen processen gevonden. Typ de naam zelf.');
      update(false);
    }).catch(() => {});
  };
  const procWatched = new Set((h.checks || []).filter((x) => x.type === 'process' && (!c.id || +x.id !== +c.id)).map((x) => x.target));
  const renderProcPick = () => {
    if (!procList || !procList.length) { $('#proc-pick').innerHTML = ''; return; }
    const q = $('[name=target]', form).value.trim().toLowerCase();
    const rows = procList.filter(([n, , , a]) => !q || n.toLowerCase().includes(q) || String(a || '').toLowerCase().includes(q)).slice(0, 150);
    $('#proc-pick').innerHTML = rows.length ? rows.map(([n, cnt, u, a]) => `
      <button type="button" class="svc-item${$('[name=target]', form).value === n ? ' sel' : ''}" data-proc="${esc(n)}" data-user="${esc(u || '')}" ${procWatched.has(n) ? 'disabled' : ''}>
        <span class="svc-dot ok"></span>
        <span class="pick-main"><span class="mono">${esc(n)}</span><span class="hint mono" title="${esc(a || '')}">${esc(a || '')}</span></span>
        <span class="hint">${cnt > 1 ? `${cnt}× · ` : ''}${esc(u || '')}${procWatched.has(n) ? ' · ' + L('wordt al bewaakt') : ''}</span>
      </button>`).join('') : `<p class="hint">${L('Geen proces gevonden met "{q}". Je kunt de naam of een patroon ook zelf typen.', { q: esc(q) })}</p>`;
    $('#proc-pick-info').textContent = L('{n} processen op {name}. Klik om te kiezen, of typ een naam of patroon.', { n: procList.length, name: h.name });
  };
  $('#proc-pick').onclick = (e) => {
    const b = e.target.closest('[data-proc]');
    if (!b) return;
    const name = $('[name=name]', form);
    if (!name.value || name.value === $('[name=target]', form).value || name.value === L('Proces')) name.value = b.dataset.proc;
    $('[name=target]', form).value = b.dataset.proc;
    form.proc_mode.value = 'name';
    renderProcPick();
  };
  // Certificaatkiezer (1.98.0): bestanden die de agent op de server vond, met vervaldatum
  let certList = null;
  let certInfo = {};
  const loadCerts = () => {
    certList = certList || [];
    api('agent_certs', null, { id: h.id }).then((r) => { certList = r.items || []; certInfo = r; update(false); }).catch(() => {});
  };
  const renderCertPick = () => {
    if (!certList) return;
    const q = $('[name=target]', form).value.trim().toLowerCase();
    const rows = certList.filter((x) => !q || x.path.toLowerCase().includes(q) || String(x.cn || '').toLowerCase().includes(q));
    const now = Date.now() / 1000;
    $('#cert-pick').innerHTML = rows.length ? rows.map((x) => {
      const end = Math.min(x.not_after || Infinity, x.chain_min || Infinity);
      const days = end === Infinity ? null : Math.floor((end - now) / 86400);
      const cls = days === null ? 'off' : days < 7 ? 'crit' : days < 21 ? 'warn' : 'ok';
      const mine = $('[name=target]', form).value === x.path;
      return `<button type="button" class="svc-item${mine ? ' sel' : ''}" data-cert="${esc(x.path)}" data-cn="${esc(x.cn || '')}" ${x.monitored && !mine ? 'disabled' : ''}>
        <span class="svc-dot ${cls}"></span>
        <span class="pick-main"><span class="mono" title="${esc(x.path)}">${esc(x.path)}</span><span class="hint">${esc(x.cn || '?')}${x.issuer && x.issuer !== x.cn ? ' · ' + esc(x.issuer) : x.issuer ? ' · ' + L('self-signed') : ''}${+x.n > 1 ? ' · ' + L('keten van {n}', { n: +x.n }) : ''}</span></span>
        <span class="hint">${days === null ? '?' : days < 0 ? L('VERLOPEN') : new Date(end * 1000).toLocaleDateString(LOCALE)}${x.monitored && !mine ? ' · ' + L('wordt al bewaakt') : ''}</span>
      </button>`;
    }).join('') : `<p class="hint">${q && certList.length ? L('Geen certificaat gevonden met "{q}". Je kunt een pad of adres ook zelf typen.', { q: esc(q) })
      : L('Nog geen certificaatbestanden gevonden. De agent (1.59.0 of nieuwer) zoekt eens per dag, of nu via 🔄 Opnieuw zoeken. Je kunt een pad of adres ook zelf typen.')}</p>`;
    $('#cert-pick-info').textContent = certList.length ? L('{n} certificaatbestanden op {name}, gezocht {ago}. Klik om te kiezen.', { n: certList.length, name: h.name, ago: ago(certInfo.at) }) : '';
  };
  $('#cert-pick').onclick = (e) => {
    const b = e.target.closest('[data-cert]');
    if (!b) return;
    const name = $('[name=name]', form);
    const t = $('[name=target]', form);
    if (!name.value || name.value === t.value || name.value.startsWith(L('Certificaat'))) name.value = L('Certificaat {n}', { n: b.dataset.cn || b.dataset.cert.split('/').pop() });
    t.value = b.dataset.cert;
    form.cert_from.value = '';
    update(false);
  };
  $('#cert-rescan').onclick = async (e) => {
    e.target.disabled = true;
    try {
      await api('certscan', { host_id: h.id });
      toast(L('De agent zoekt bij zijn volgende rapport opnieuw (meestal binnen een paar minuten)'));
      $('#cert-pick-info').textContent = L('Zoeken aangevraagd…');
      setTimeout(() => { if ($('#cert-pick')) loadCerts(); }, 90000);
    } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('[name=target]', form).addEventListener('input', () => {
    if (typeSel.value === 'process') renderProcPick();
    if (typeSel.value === 'ssl') { renderCertPick(); $('#verify-wrap').hidden = $('[name=target]', form).value.trim().startsWith('/'); }
  });
  typeSel.onchange = () => update(true);
  $('[name=http_method]', form).onchange = () => update(false);
  $('[name=hb_sched]', form).onchange = () => update(false);
  $('[name=thresh_mode]', form).onchange = () => update('smart');
  $('#heal-sel').onchange = () => update('smart');
  // Herstelcommando's die op deze server staan (de agent meldt ze)
  if (h.mode === 'agent' && !tpl && isAdmin()) {
    api('agent_status', null, { id: h.id }).then((r) => {
      const sel = $('#heal-sel');
      if (!sel) return;
      (r.heal_cmds || []).forEach((n) => {
        if (!$(`option[value="cmd:${CSS.escape(n)}"]`, sel)) sel.insertAdjacentHTML('beforeend', `<option value="cmd:${esc(n)}">${L('herstelcommando {n}', { n: esc(n) })}</option>`);
      });
    }).catch(() => {});
  }
  $('[name=hb_unit]', form).oninput = () => update(false);
  $('[name=target]', form).addEventListener('input', (e) => {
    const n = e.target.value.trim() || 'NAAM';
    $$('#hb-wrap code[data-tpl]', form).forEach((el) => {
      el.textContent = el.dataset.tpl.replaceAll('%N%', n);
      if (el.nextElementSibling) el.nextElementSibling.dataset.copy = el.textContent;
    });
  });
  $$('[data-copy]', form).forEach((b) => (b.onclick = async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); toast(L('Gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren(b.previousElementSibling); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  }));
  haSel.onchange = () => update('metric');
  pveSel.onchange = () => update('metric');
  vpnSel.onchange = () => update('metric');   // 1.170.0
  dmSel.onchange = () => update('metric');   // 1.227.0
  tnSel.onchange = () => update('metric');   // 1.174.0
  snmpSel.onchange = () => update('metric');
  $('[name=oid_mode]', form).onchange = () => update(false);
  $('[name=oid_low]', form).onchange = () => update(false);
  $('[name=oid_unit]', form).oninput = () => update(false);
  $$('#res-wrap input[type=checkbox]', form).forEach((cb) => (cb.onchange = () => update(false)));
  $('[name=test_type]', form).onchange = () => update(false);
  $('[name=cmd_match]', form).onchange = () => update(false);
  $('#cmd-preset').onchange = (e) => {
    const p = CMD_PRESETS[e.target.value];
    if (p) {
      const f = (n) => $(`[name=${n}]`, form);
      f('cmd').value = p.cmd; f('cmd_match').value = p.match; f('cmd_expect').value = p.expect; f('cmd_fail').value = p.fail;
      f('cmd_timeout').value = p.timeout;
      if (![...f('interval_sec').options].some((o) => +o.value === +p.interval)) f('interval_sec').innerHTML = intervalOptions(p.interval);
      f('interval_sec').value = p.interval;
      if (!f('name').value || Object.values(CMD_PRESETS).some((x) => x.name === f('name').value)) f('name').value = p.name;
      update(false);
    }
    presetInfo();
  };
  // Voorinstelling kiezen: bron, patroon, drempels en naam invullen
  $('[name=preset]', form).onchange = (e) => {
    const p = LOG_PRESETS[e.target.value];
    if (!p || e.target.value === 'custom') return;
    const f = (n) => $(`[name=${n}]`, form);
    f('target').value = p.src;
    f('pattern').value = p.pat;
    f('warn_threshold').value = p.warn;
    f('crit_threshold').value = p.crit;
    // Naam invullen, tenzij je zelf al iets anders hebt getypt
    const plain = (x) => x.label.replace(/^\S+\s/, '');
    if (!f('name').value || Object.values(LOG_PRESETS).some((x) => f('name').value === plain(x))) f('name').value = plain(p);
  };
  update(false);
}

// ---------------------------------------------------------------- History
async function showHistory(c, h) {
  const t = typeOf(c);
  modal({
    title: `${h.name} – ${c.name}`,
    wide: true,
    body: `<div class="chart">
      <div class="chart-head">
        <span id="uptime" class="muted"></span>
        <select id="range"><option value="6">${L('{n} uur', { n: 6 })}</option><option value="24" selected>${L('{n} uur', { n: 24 })}</option><option value="168">${L('{n} dagen', { n: 7 })}</option><option value="720">${L('{n} dagen', { n: 30 })}</option><option value="2160">${L('{n} dagen', { n: 90 })}</option><option value="4320">${L('{n} dagen', { n: 180 })}</option></select>
      </div>
      <div id="chart-svg"><p class="muted">${L('Laden…')}</p></div></div>
      <div id="log-lines"></div>`,
  });
  const load = async () => {
    const hours = $('#range').value;
    const d = await api('history', null, { check_id: c.id, hours });
    const vals = d.points.map((p) => p.v).filter((v) => v !== null);
    const unit = t.unit === 'dagen' ? ' d' : t.unit ? ' ' + t.unit : '';
    const mma = t.unit && vals.length
      ? ` · min <b>${fmt(Math.min(...vals))}</b> · ${L('gem')} <b>${fmt(vals.reduce((a, b) => a + b, 0) / vals.length)}</b> · max <b>${fmt(Math.max(...vals))}</b>${unit}` : '';
    const fc = d.forecast
      ? (d.forecast.days_to_full !== null
        ? ` · <span class="${d.forecast.days_to_full <= 14 ? 'bad' : ''}">${L('trend +{p}%/dag: vol over ~{d} dagen', { p: dec(Math.round(d.forecast.per_day * 100) / 100), d: Math.round(d.forecast.days_to_full) })}</span>`
        : ' · ' + L('trend: stabiel'))
      : '';
    $('#uptime').innerHTML = d.uptime === null ? L('Geen data')
      : c.type === 'log' ? `${L('Treffers per controle')}${mma}`   // "beschikbaarheid" zegt bij een logbestand niets
      : `${L('Beschikbaarheid')}: <b>${pct(d.uptime)}</b>${mma}${fc}`;
    // Logbestand: de laatst gevonden regels
    const ll = $('#log-lines');
    if (ll) ll.innerHTML = d.log_lines && d.log_lines.length
      ? `<h4>${L('Laatst gevonden regels')}</h4><pre class="code log-lines">${d.log_lines.map((l) => `<span class="muted">${esc(fmtUntil(l.time))}</span>  ${esc(l.line)}`).join('\n')}</pre>`
      : (d.log_lines ? `<p class="muted small">${L('Nog geen regels gevonden.')}</p>` : '');
    // Container: een grafiek per bewaakte meting (CPU, RAM, disk), elk met eigen schaal en drempels
    if (d.series && d.series.length) {
      $('#chart-svg').innerHTML = d.series.map((sr) => {
        const pts = d.points.map((p) => ({ t: p.t, s: p.s, v: p.x && p.x[sr.key] !== undefined ? p.x[sr.key] : null }));
        const sv = pts.map((p) => p.v).filter((v) => v !== null);
        const stats = sv.length ? ` <span class="muted small">min ${fmt(Math.min(...sv))} · ${L('gem')} ${fmt(sv.reduce((a, b) => a + b, 0) / sv.length)} · max ${fmt(Math.max(...sv))} ${sr.unit}</span>` : ` <span class="muted small">${L('nog geen metingen')}</span>`;
        return `<h4 class="chart-sub">${esc(sr.label)}${stats}</h4>`
          + chartSvg(pts, { warn_threshold: sr.warn, crit_threshold: sr.crit }, { unit: sr.unit }, +hours, 170);
      }).join('');
      return;
    }
    $('#chart-svg').innerHTML = chartSvg(d.points, c, t, +hours, 260, d.band);
  };
  $('#range').onchange = load;
  load();
}

/** @param band 1.107.0: per punt [laag, hoog, warning, critical] van de slimme drempels (of null) */
function chartSvg(pts, c, t, hours, H = 260, band = null) {
  if (!pts.length) return `<p class="muted">${L('Nog geen meetgegevens.')}</p>`;
  const W = 860, PL = 48, R = 12, T = 12, B = 40;
  const t1 = Math.floor(Date.now() / 1000), t0 = t1 - hours * 3600;
  const x = (ts) => PL + ((ts - t0) / (t1 - t0)) * (W - PL - R);
  const vals = pts.map((p) => p.v).filter((v) => v !== null);
  const hasVals = t.unit && vals.length;
  const smart = band && band.length === pts.length && c.thresh_mode && c.thresh_mode !== 'manual';
  let max = hasVals ? Math.max(...vals, ...(smart ? band.map((b) => b[1]) : [+c.crit_threshold || 0, +c.warn_threshold || 0])) * 1.1 : 1;
  if (t.unit === '%') max = Math.max(100, ...vals);   // CPU van een container kan boven 100% (meer cores)
  const y = (v) => T + (1 - v / max) * (H - T - B - 14);
  let svg = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" style="height:${H}px">`;

  if (hasVals) {
    svg += '<g class="grid">';
    for (let i = 0; i <= 4; i++) {
      const v = (max / 4) * i;
      svg += `<line x1="${PL}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}"/><text x="${PL - 6}" y="${y(v) + 4}" text-anchor="end">${Math.round(v)}</text>`;
    }
    svg += '</g>';
    if (smart) {
      // Slimme drempels: de normale band per uur en de geleerde warning (gestippeld); wat buiten de grafiek valt, niet
      const cl = (v) => Math.min(v, max);
      svg += `<polygon class="chart-band" points="${pts.map((p, i) => `${x(p.t).toFixed(1)},${y(cl(band[i][1])).toFixed(1)}`).join(' ')} ${pts.map((p, i) => `${x(p.t).toFixed(1)},${y(cl(band[i][0])).toFixed(1)}`).reverse().join(' ')}"/>`;
      svg += `<path class="chart-bandw" d="${pts.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(cl(band[i][2])).toFixed(1)}`).join('')}"/>`;
    } else {
      if (c.warn_threshold !== null) svg += `<line class="thr-w" x1="${PL}" x2="${W - R}" y1="${y(+c.warn_threshold)}" y2="${y(+c.warn_threshold)}"/>`;
      if (c.crit_threshold !== null) svg += `<line class="thr-c" x1="${PL}" x2="${W - R}" y1="${y(+c.crit_threshold)}" y2="${y(+c.crit_threshold)}"/>`;
    }
    const segs = [];
    let cur = [];
    pts.forEach((p) => { if (p.v === null) { if (cur.length) segs.push(cur); cur = []; } else cur.push(p); });
    if (cur.length) segs.push(cur);
    segs.forEach((s) => {
      const line = s.map((p, i) => `${i ? 'L' : 'M'}${x(p.t).toFixed(1)},${y(p.v).toFixed(1)}`).join('');
      svg += `<path class="area" d="${line}L${x(s[s.length - 1].t)},${y(0)}L${x(s[0].t)},${y(0)}Z"/><path class="line" d="${line}"/>`;
    });
  }
  // Statusstrook onderaan
  const sy = H - B + 4;
  svg += '<g class="strip">';
  pts.forEach((p, i) => {
    const x1 = x(p.t), x2 = i < pts.length - 1 ? x(pts[i + 1].t) : x(t1);
    svg += `<rect class="s${p.s}" x="${x1}" y="${sy}" width="${Math.max(1, x2 - x1)}" height="10"><title>${STATUS[p.s]} – ${new Date(p.t * 1000).toLocaleString(LOCALE)}${p.v !== null ? ' – ' + fmt(p.v, t.unit) : ''}</title></rect>`;
  });
  svg += '</g>';
  for (let i = 0; i <= 4; i++) {
    const ts = t0 + ((t1 - t0) / 4) * i;
    const d = new Date(ts * 1000);
    const lbl = hours > 24 ? d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }) : d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
    svg += `<text x="${x(ts)}" y="${H - 8}" text-anchor="${i === 0 ? 'start' : i === 4 ? 'end' : 'middle'}">${lbl}</text>`;
  }
  return svg + '</svg>';
}

// ---------------------------------------------------------------- Port scan
function portScan(h) {
  modal({
    title: L('Poortscan – {name}', { name: h.name }),
    wide: true,
    body: `
      ${xpHtml(L('Zoekt welke poorten op deze server open staan.'), L('RzSense scant vanaf zijn eigen server welke TCP-poorten op <code>{address}</code> open staan. Een open poort voeg je met één klik toe als TCP-check, zodat je een melding krijgt als de dienst wegvalt.', { address: esc(h.address) }))}
      <div class="scan-form">
        <label>${L('Bereik')}
          <select id="scan-preset">
            <option value="common">${L('Bekende poorten (SSH, web, databases, HA, NAS…)')}</option>
            <option value="top1024">${L('Poort 1-1024')}</option>
            <option value="custom">${L('Eigen lijst / range')}</option>
          </select></label>
        <label id="scan-custom-wrap" hidden>${L('Poorten')} <span class="hint">${L('bv. 22,80,443,8000-8100')}</span><input id="scan-ports" class="mono" placeholder="22,80,443,8000-8100"></label>
        <button type="button" class="btn primary" id="scan-go">🔍 ${L('Scannen')}</button>
      </div>
      <div id="scan-result"></div>`,
  });
  $('#scan-preset').onchange = (e) => ($('#scan-custom-wrap').hidden = e.target.value !== 'custom');
  $('#scan-go').onclick = () => runScan(h);
  $('#scan-result').addEventListener('click', (e) => addPortCheck(e, h));
  runScan(h);
}

async function runScan(h) {
  const btn = $('#scan-go');
  const out = $('#scan-result');
  btn.disabled = true;
  out.innerHTML = `<p class="scan-summary">⏳ ${L('Bezig met scannen…')}</p>`;
  try {
    const r = await api('port_scan', { host_id: h.id, preset: $('#scan-preset').value, ports: $('#scan-ports').value });
    const certMon = new Set(h.checks.filter((c) => c.type === 'ssl').map((c) => +((c.target || '').match(/:(\d+)\/?$/) || [0, 443])[1]));
    const row = (p, extra = '') => {
      const mon = r.monitored.includes(+p.port);
      const tls = TLS_PORTS.includes(+p.port);
      return `<tr><td class="num">${p.port}</td><td>${esc(p.service || '–')}</td>${extra}
        <td style="text-align:right;white-space:nowrap">${mon ? `<span class="muted small">✓ ${L('bewaakt')}</span>`
          : `<button type="button" class="btn need-admin" data-port="${p.port}" data-service="${esc(p.service || '')}">＋ Check</button>`}
          ${tls ? (certMon.has(+p.port) ? ' <span class="muted small">✓ cert</span>'
            : ` <button type="button" class="btn need-admin" data-cert="${p.port}" title="${L('Certificaat bewaken (vervaldatum)')}">＋ Cert</button>`) : ''}</td></tr>`;
    };
    let html = `<p class="scan-summary">${L('{n} open van {m} gescande poorten', { n: r.open.length, m: r.scanned })} · ${r.seconds}s</p>`;
    html += r.open.length
      ? `<table class="port-table"><thead><tr><th>${L('Poort')}</th><th>${L('Dienst')}</th><th>${L('Reactie')}</th><th></th></tr></thead><tbody>
          ${r.open.map((p) => row(p, `<td class="muted">${p.ms} ms</td>`)).join('')}</tbody></table>`
      : `<p class="muted">${L('Geen open poorten gevonden (of een firewall blokkeert de scan).')}</p>`;
    if (r.listening) {
      html += `<h4 style="margin:1.4rem 0 .2rem">${L('Luisterende poorten volgens de agent')}</h4>
        <p class="muted small" style="margin:0">${L('Ook poorten die alleen lokaal luisteren of door een firewall worden afgeschermd.')}</p>`;
      html += r.listening.length
        ? `<table class="port-table"><thead><tr><th>${L('Poort')}</th><th>${L('Dienst')}</th><th>${L('Adres')}</th><th>${L('Proces')}</th><th></th></tr></thead><tbody>
            ${r.listening.map((p) => row(p, `<td class="mono small">${esc(p.addr)}</td><td>${esc(p.proc || '–')}</td>`)).join('')}</tbody></table>`
        : `<p class="muted small">${L('Nog geen gegevens — werk de agent bij naar de nieuwste versie (vereist <code>ss</code>).')}</p>`;
    }
    out.innerHTML = html;
  } catch (ex) {
    out.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`;
  }
  btn.disabled = false;
}

// Poorten waarop meestal TLS draait: daar kan direct een certificaatcheck bij
const TLS_PORTS = [443, 465, 636, 853, 990, 993, 995, 1443, 2083, 2087, 3269, 4443, 5001, 5986, 6443, 7443, 8006, 8443, 8883, 8920, 9443, 10443];

/** Adres in het eigen netwerk? Daar zijn self-signed certificaten gewoon, dus niet streng controleren. */
function isLocalAddress(a) {
  return /^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|\[?f[cd]|\[?::1)/i.test(a)
    || !a.includes('.') || /\.(lan|local|home|box|internal|localdomain|home\.arpa)$/i.test(a);
}

async function addCertCheck(b, h) {
  b.disabled = true;
  const port = +b.dataset.cert;
  try {
    await api('check_save', { host_id: h.id, type: 'ssl', target: port === 443 ? '' : `${h.address}:${port}`,
      name: port === 443 ? L('Certificaat') : L('Certificaat {port}', { port }), warn_threshold: TYPES.ssl.warn, crit_threshold: TYPES.ssl.crit,
      interval_sec: 3600, enabled: true, verify_ssl: !isLocalAddress(h.address) });
    b.outerHTML = '<span class="muted small">✓ cert</span>';
    toast(isLocalAddress(h.address) ? L('Certificaatcheck op poort {port} toegevoegd (lokaal adres: certificaatcontrole uit, i.v.m. self-signed)', { port }) : L('Certificaatcheck op poort {port} toegevoegd', { port }));
    refresh();
  } catch (ex) {
    toast(ex.message, 'err');
    b.disabled = false;
  }
}

async function addPortCheck(e, h) {
  const cb = e.target.closest('[data-cert]');
  if (cb) return addCertCheck(cb, h);
  const b = e.target.closest('[data-port]');
  if (!b) return;
  b.disabled = true;
  const name = b.dataset.service ? `${b.dataset.service} (${b.dataset.port})` : L('Poort {port}', { port: b.dataset.port });
  try {
    await api('check_save', { host_id: h.id, type: 'tcp', target: b.dataset.port, name,
      warn_threshold: TYPES.tcp.warn, crit_threshold: TYPES.tcp.crit, interval_sec: 60, enabled: true });
    $$(`[data-port="${b.dataset.port}"]`, $('#scan-result')).forEach((x) => x.outerHTML = `<span class="muted small">✓ ${L('bewaakt')}</span>`);
    toast(L('Check "{name}" toegevoegd', { name }));
    refresh();
  } catch (ex) {
    toast(ex.message, 'err');
    b.disabled = false;
  }
}

// ---------------------------------------------------------------- Podman
const fmtBytes = (b) => {
  if (b === null || b === undefined) return '–';
  const u = ['B', 'kB', 'MB', 'GB', 'TB'];
  let i = 0;
  while (b >= 1000 && i < u.length - 1) { b /= 1000; i++; }
  return dec(`${b >= 100 || i === 0 ? Math.round(b) : b.toFixed(1)} ${u[i]}`);
};

async function podmanOverview(h) {
  modal({ title: `📦 Containers – ${h.name}`, wide: true, body: `<div id="podman-body"><p class="muted">${L('Laden…')}</p></div>` });
  const body = $('#podman-body');
  let d;
  try { d = await api('podman', null, { id: h.id }); } catch (ex) { body.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sm = d.summary;
  const checkOf = (key) => d.checks.find((c) => c.type === 'container' && c.target === key);
  const resLabel = (c) => [L('status'), ...Object.keys((JSON.parse(c.options || '{}') || {}).res || {}).map((k) => ({ cpu: 'CPU', mem: 'RAM', disk: 'disk' }[k]))].join(', ');
  const stateCls = (c) => (c.state === 'running' ? (c.health === 'unhealthy' ? 's2' : 's0') : c.state === 'paused' ? 's1' : +c.exit_code ? 's2' : 's3');
  const stateText = (c) => (c.state === 'running' ? L('draait') + (c.health ? ` (${c.health === 'healthy' ? L('gezond') : c.health === 'unhealthy' ? L('ONGEZOND') : L('start op')})` : '')
    : c.state === 'paused' ? L('gepauzeerd') : c.state === 'exited' ? L('gestopt (exit {code})', { code: c.exit_code }) : c.state);
  const rows = d.containers.map((c) => {
    const memPct = c.mem !== null && c.mem_limit ? (c.mem / c.mem_limit) * 100 : null;
    const disk = (c.size_rw || 0) + (c.volumes || 0);
    const chk = checkOf(c.key);
    const watch = chk
      ? `<button type="button" class="btn small-btn need-admin pc-watched" data-pc-edit="${chk.id}" title="${L('Aanpassen wat er bewaakt wordt')}">✓ ${esc(resLabel(chk))} ✎</button>`
      : `<button type="button" class="btn small-btn need-admin" data-pc="${esc(c.key)}" title="${L('Status en (naar keuze) CPU, RAM en disk bewaken')}">＋ ${L('Bewaken')}</button>`;
    return `<tr>
      <td><span class="dot-inline ${stateCls(c)}"></span><b>${esc(c.key)}</b>${c.pod ? `<br><span class="muted small">pod ${esc(c.pod)}</span>` : ''}
        <br><span class="muted small mono">${esc(c.image)}</span></td>
      <td>${esc(stateText(c))}${+c.restarts ? `<br><span class="muted small">${L('{n}× herstart', { n: c.restarts })}</span>` : ''}</td>
      <td class="num">${c.cpu !== null && c.cpu !== undefined ? dec(Math.round(c.cpu * 10) / 10) + '%' : '–'}</td>
      <td class="num">${c.mem !== null ? fmtBytes(c.mem) : '–'}${memPct !== null ? `<br><span class="muted small">${L('{p}% van {max}', { p: dec(memPct.toFixed(1)), max: fmtBytes(c.mem_limit) })}</span>` : ''}</td>
      <td class="num">${fmtBytes(disk)}${c.volumes ? `<br><span class="muted small">volumes ${fmtBytes(c.volumes)}</span>` : ''}</td>
      <td class="pc-actions">${watch}</td>
    </tr>`;
  }).join('');
  const missing = d.containers.filter((c) => !checkOf(c.key));
  const ha = sm.version === 'Home Assistant';
  body.innerHTML = `
    <p class="scan-summary">${ha ? L('Home Assistant: Core, Supervisor, plugins en add-ons') : `${/^docker /.test(sm.version || '') ? '' : 'Podman '}${esc(sm.version || '?')}`} · ${L('{n} van {m} containers draaien', { n: d.containers.filter((c) => c.state === 'running').length, m: d.containers.length })}
      ${(sm.pods || []).length ? ' · pods: ' + sm.pods.map((p) => `${esc(p.name)} (${esc(p.status)})`).join(', ') : ''}
      <br><span class="muted small">${ha ? L('Gestopte add-ons met exitcode 0 zijn bewust uitgezet; een add-on met een fout staat als gestopt (exit 1).')
        : `Images ${fmtBytes(sm.images_size)} · containers ${fmtBytes(sm.containers_size)} · volumes ${fmtBytes(sm.volumes_size)}
      ${sm.reclaimable ? ` · ${L('{size} op te ruimen', { size: fmtBytes(sm.reclaimable) })} (<code>podman system prune</code>)` : ''}`} · ${L('gemeld {ago}', { ago: ago(d.updated_at) })}
      ${(sm.users || []).length > 1 ? ' · ' + L('gebruikers') + ': ' + sm.users.map(esc).join(', ') : ''}</span></p>
    ${sm.error ? `<p class="form-error">${L('Podman-fout: {e}', { e: esc(sm.error) })}</p>` : ''}
    <div class="podman-actions">
      ${d.checks.some((c) => c.type === 'podman') ? `<span class="muted small">✓ ${L('{x}-check aanwezig', { x: 'Containers' })}</span>`
        : `<button type="button" class="btn need-admin" id="pc-overall">＋ ${L('{x}-check (algehele status)', { x: 'Containers' })}</button>`}
      ${missing.length ? `<button type="button" class="btn need-admin" id="pc-all" title="${L('Alleen de status; CPU, RAM en disk kun je daarna per container aanvinken')}">＋ ${missing.length === d.containers.length ? L('Alle {n} container(s) bewaken', { n: missing.length }) : L('De overige {n} container(s) bewaken', { n: missing.length })}</button>` : ''}
    </div>
    <div class="table-scroll"><table class="port-table podman-table">
      <thead><tr><th>Container</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>${L('Bewaken')}</th></tr></thead>
      <tbody>${rows || `<tr><td colspan="6" class="muted">${L('Geen containers.')}</td></tr>`}</tbody></table></div>
    ${xpHtml(L('Hoe CPU en disk van containers worden geteld.'), L('<b>CPU:</b> zoals <code>podman stats</code>; 100% is één core. <b>Disk:</b> de schrijflaag van de container plus zijn volumes. <b>Rootless:</b> containers van gewone gebruikers staan als <code>gebruiker/naam</code>.'))}`;

  const add = async (payload) => api('check_save', { host_id: h.id, interval_sec: 60, enabled: true, ...payload });
  const addContainer = (key) => add({ type: 'container', target: key, name: '', res: {} });
  body.onclick = async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    b.disabled = true;
    // Eén container bewaken of aanpassen: het checkformulier met status + vinkjes voor CPU, RAM en disk
    if (b.dataset.pc) return checkForm(null, findHost(h.id), { type: 'container', target: b.dataset.pc, name: '', options: '{"res":{}}' });
    if (b.dataset.pcEdit) { const [c] = findCheck(b.dataset.pcEdit); if (c) return checkForm(c, findHost(h.id)); }
    try {
      if (b.id === 'pc-overall') await add({ type: 'podman', name: ha ? 'Containers' : 'Podman' });
      else if (b.id === 'pc-all') for (const c of missing) await addContainer(c.key);
      toast(L('Check(s) toegevoegd'));
      await refresh();
      podmanOverview(findHost(h.id));
    } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
  };
}

// ---------------------------------------------------------------- Agent
/** Status van de realtime-watcher, voor de Agent Manager en de IDS-pagina */
function realtimeText(rt) {
  if (!rt || !rt.on) return `<span class="muted">⚡ ${L('Realtime: uit')}</span>`;
  const s = rt.status;
  if (!s || s.state !== 'active') {
    return `<span class="warn-txt">⚡ ${L('Realtime: aan, maar de watcher is (nog) niet actief')}</span> <span class="muted">— ${L('agent 1.43.0 met systemd nodig; bijwerken met ⬆, of REALTIME=0 op de server')}</span>`;
  }
  const parts = [s.journal === 'ok' ? L('systemlog') + ' ✔' : s.journal === 'supervisor' ? 'Supervisor ✔' : L('systemlog') + ' –',
    s.files === 'ok' ? L('bestanden') + ' ✔' : s.files === 'missing' ? L('bestanden: inotify-tools ontbreekt') : null].filter(Boolean);
  return `<span class="good">⚡ ${L('Realtime actief')}</span> <span class="muted">${L('sinds {t}', { t: new Date(s.since * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) })} · ${parts.join(' · ')}</span>`;
}

/**
 * Ontbreekt er iets voor bestanden in realtime (inotify-tools, op Rocky/Alma/RHEL via EPEL)? Melding met de oplossing,
 * of '' als alles er is. via: hoe de agent zichzelf bijwerkt (alleen dan helpt "Opnieuw installeren").
 */
function toolsWarning(t, via) {
  if (!t || t.inotify) return '';
  const fix = via === 'systemd' || via === 'direct' ? L('klik hierboven op <b>⬆ Opnieuw installeren</b> (de agent installeert het dan zelf, als de server internet heeft), of op de server:') : L('op de server:');
  if (t.family === 'rhel' && !t.epel) {
    return t.no_epel ? `<span class="muted">⚠️ ${L('EPEL staat uit (EPEL=0): bestanden worden niet in realtime gevolgd, alleen bij de gewone runs.')}</span>`
      : `<span class="warn-txt">⚠️ ${L('EPEL is niet geïnstalleerd:')}</span> ${L('bestanden worden niet in realtime gevolgd (logins en services wel). Oplossen: {fix}', { fix })}
        <code>sudo dnf install epel-release &amp;&amp; sudo dnf install inotify-tools</code>`;
  }
  return `<span class="warn-txt">⚠️ ${L('inotify-tools ontbreekt:')}</span> ${L('bestanden worden niet in realtime gevolgd (logins en services wel). Oplossen: {fix}', { fix })}
    <code>${t.family === 'rhel' ? 'sudo dnf install inotify-tools' : 'sudo apt install inotify-tools'}</code>`;
}

