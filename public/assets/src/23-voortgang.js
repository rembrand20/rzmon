// ---------------------------------------------------------------- 1.252.0: voortgang overal
// 1. Elke knop die iets laat doen (opslaan, testen, scannen …) laat zien dat hij bezig is: een balkje onderin de knop,
//    de verstreken tijd, en hoe lang het meestal duurt (de browser onthoudt dat per actie). Na lang wachten: "duurt langer".
// 2. Een dun balkje bovenaan de pagina zolang er iets loopt dat je zelf startte.
// 3. Lange taken op de server (netwerkscan, poortscan): de knop wordt ⏹ Stoppen en is zelf de voortgangsbalk, met de
//    stappen eronder. De taak loopt door als je de pagina verlaat; kom je terug, dan zie je hem weer.
// 4. Reeksen die de browser zelf afwerkt (meerdere checks toevoegen …): de knop telt "7 van 25" en kan stoppen.

const RZB = { click: null, top: 0, topTimer: 0 };
/** Duur per actie (ms), gemiddeld over de laatste keren; alleen in deze browser */
const rzDur = {
  get(a) { try { return (JSON.parse(localStorage.getItem('rz-dur') || '{}'))[a] || 0; } catch (e) { return 0; } },
  put(a, ms) {
    try {
      const all = JSON.parse(localStorage.getItem('rz-dur') || '{}');
      all[a] = all[a] ? Math.round(all[a] * 0.6 + ms * 0.4) : Math.round(ms);
      const keys = Object.keys(all);
      if (keys.length > 300) delete all[keys[0]];
      localStorage.setItem('rz-dur', JSON.stringify(all));
    } catch (e) { /* geen opslag: dan zonder verwachte duur */ }
  },
};
const rzSecs = (ms) => (ms < 60000 ? L('{n} s', { n: Math.round(ms / 1000) }) : `${Math.floor(ms / 60000)}:${String(Math.round(ms / 1000) % 60).padStart(2, '0')}`);

// Welke knop werd net aangeklikt? Een verzoek dat daar vlak na begint, hoort bij die knop.
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('button, .btn');
  RZB.click = b ? { b, t: performance.now() } : null;
}, true);

/** Knoppen die niet "bezig" moeten tonen: navigatie, tabbladen, schakelaars, en knoppen met hun eigen voortgang */
const rzNoBusy = (b) => !b || b.classList.contains('rz-job') || b.closest('[data-no-busy], .tab, .tabs, nav, .seg, [role=tab], .side, .sidebar, .lf-tabs');

/** Aangeroepen door api(): geeft een functie terug die je aanroept als het verzoek klaar is */
function rzBusyStart(action, isPost) {
  const now = performance.now();
  const c = RZB.click;
  let b = c && now - c.t < 350 && c.b.isConnected ? c.b : null;
  if (b && rzNoBusy(b)) b = null;
  if (b) RZB.click = { b, t: now };   // een volgend verzoek uit dezelfde klik hoort er ook bij
  if (!b && !isPost) return null;   // een poll op de achtergrond: niets tonen
  const t0 = now;
  // Balkje bovenaan (na 400 ms, zodat snelle dingen niet knipperen)
  RZB.top++;
  if (!RZB.topTimer) RZB.topTimer = setTimeout(rzTopShow, 400);
  let tm = 0, span = null;
  const typical = rzDur.get(action);
  if (b) {
    b._rzBusy = (b._rzBusy || 0) + 1;
    tm = setTimeout(function tickBusy() {
      if (!b.isConnected) return;
      const ms = performance.now() - t0;
      b.classList.add('rz-busy');
      b.setAttribute('aria-busy', 'true');
      if (!span || !span.isConnected) { span = document.createElement('span'); span.className = 'rz-busy-t'; b.append(span); }
      const slow = ms > Math.max(30000, typical * 2.5);
      span.textContent = (slow ? '⚠ ' : '') + rzSecs(ms) + (typical > 2500 && !slow ? ' / ±' + rzSecs(typical) : '');
      b.title = slow ? L('Dit duurt langer dan normaal. Je kunt wachten; het loopt nog.') : typical > 2500 ? L('Duurt meestal ongeveer {t}', { t: rzSecs(typical) }) : L('Bezig…');
      if (typical > 2500) { b.classList.add('rz-det'); b.style.setProperty('--rzp', Math.min(95, (ms / typical) * 100).toFixed(1) + '%'); }
      tm = setTimeout(tickBusy, 500);
    }, 400);
  }
  return () => {
    const ms = performance.now() - t0;
    if (ms > 300) rzDur.put(action, ms);
    RZB.top = Math.max(0, RZB.top - 1);
    if (!RZB.top) { clearTimeout(RZB.topTimer); RZB.topTimer = 0; rzTopHide(); }
    if (!b) return;
    clearTimeout(tm);
    b._rzBusy = Math.max(0, (b._rzBusy || 1) - 1);
    if (!b._rzBusy) {
      b.classList.remove('rz-busy', 'rz-det');
      b.removeAttribute('aria-busy');
      b.style.removeProperty('--rzp');
      if (span) span.remove();
      if (b.title && /Bezig|langer|meestal|Busy|longer|usually/.test(b.title)) b.removeAttribute('title');
    }
  };
}

function rzTopShow() {
  let el = document.getElementById('rz-topbar');
  if (!el) { el = document.createElement('div'); el.id = 'rz-topbar'; el.setAttribute('role', 'progressbar'); el.setAttribute('aria-label', L('Bezig…')); el.innerHTML = '<i></i>'; document.body.append(el); }
  el.classList.add('on');
}
function rzTopHide() { const el = document.getElementById('rz-topbar'); if (el) el.classList.remove('on'); }

// ---------------------------------------------------------------- lange taken op de server (Job)
const JOB_STAGE = () => ({ ping: L('Pingen'), devices: L('Apparaten verwerken'), names: L('Namen zoeken (DNS, NetBIOS, mDNS, SSDP)'), ports: L('Poorten scannen'), details: L('Gegevens bewaren') });
/** Welke stappen bij hoeveel stappen horen (netwerkscan: licht = 2, diep = 3, beide = 5) */
const jobStages = (key, steps) => (key.startsWith('portscan:') ? ['ports'] : steps === 5 ? ['ping', 'devices', 'names', 'ports', 'details'] : steps === 3 ? ['names', 'ports', 'details'] : ['ping', 'devices']);
const RZJ = new Map();   // key → { timer, btn, html, rate }

/** Hoe ver (0..1) over alle stappen */
function jobPct(j) {
  if (!j.steps) return 0;
  const inStep = j.total ? Math.min(1, j.done / j.total) : 0.3;
  return Math.max(0, Math.min(1, ((Math.max(1, j.step) - 1) + inStep) / j.steps));
}

/** De knop als voortgangsbalk + Stoppen; details = element waarin de stappen komen (mag null) */
function jobPaint(key, j, btn, details) {
  const st = RZJ.get(key) || {};
  const stages = jobStages(key, j.steps);
  const name = JOB_STAGE()[j.stage] || j.stage || L('Starten…');
  // Nog ongeveer: uit het tempo binnen deze stap
  let eta = '';
  if (j.total && j.done > 0 && j.done < j.total) {
    const now = Date.now();
    if (!st.rate || st.rate.stage !== j.stage + j.step) st.rate = { stage: j.stage + j.step, t: now, d: j.done };
    else if (j.done > st.rate.d && now - st.rate.t > 1500) eta = ' · ' + L('nog ±{t}', { t: rzSecs(((j.total - j.done) * (now - st.rate.t)) / (j.done - st.rate.d)) });
  }
  if (btn && btn.isConnected) {
    if (!btn.classList.contains('rz-job')) { st.html = btn.innerHTML; btn.classList.add('rz-job'); }
    btn.disabled = false;
    btn.setAttribute('aria-label', L('Stoppen'));
    btn.title = L('Klik om te stoppen. Wat al klaar is, blijft bewaard.');
    btn.style.setProperty('--rzp', (jobPct(j) * 100).toFixed(1) + '%');
    btn.innerHTML = `<span class="rz-job-in">${j.stop ? '⏳ ' + esc(L('Stoppen…')) : '⏹ ' + esc(L('Stoppen'))}
      <small>${j.steps > 1 ? esc(L('stap {a}/{b}', { a: Math.max(1, j.step), b: j.steps })) + ' · ' : ''}${j.total ? `${dec(j.done)}/${dec(j.total)}` : esc(name)} · ${esc(rzSecs(j.elapsed * 1000))}</small></span>`;
  }
  if (details && details.isConnected) {
    details.hidden = false;
    details.innerHTML = `<ol class="rz-steps">${stages.map((s, i) => {
      const n = i + 1, cur = n === j.step, done = n < j.step;
      const cnt = cur && j.total ? ` <span class="muted">${dec(j.done)}/${dec(j.total)}</span>` : '';
      return `<li class="${done ? 'done' : cur ? 'cur' : ''}"><span class="rz-st-i">${done ? '✔' : cur ? '⏳' : '·'}</span>${esc(JOB_STAGE()[s] || s)}${cnt}
        ${cur && j.total ? `<span class="rz-st-bar"><i style="width:${Math.min(100, (j.done / j.total) * 100).toFixed(1)}%"></i></span>` : ''}
        ${cur && j.label ? `<span class="muted small mono">${esc(j.label)}</span>` : ''}</li>`;
    }).join('')}</ol>
    <p class="muted small">${esc(L('Bezig sinds {t}', { t: rzSecs(j.elapsed * 1000) }))}${eta}${j.who ? '' : ' · ' + esc(L('geplande scan'))} · ${esc(L('Je kunt de pagina gewoon verlaten: de taak loopt door.'))}</p>`;
  }
  RZJ.set(key, st);
}

function jobRestore(key, btn, details) {
  const st = RZJ.get(key);
  if (btn && btn.isConnected && st && st.html !== undefined) {
    btn.innerHTML = st.html;
    btn.classList.remove('rz-job');
    btn.style.removeProperty('--rzp');
    btn.removeAttribute('aria-label');
    btn.removeAttribute('title');
    btn.disabled = false;
  }
  if (details && details.isConnected) { details.hidden = true; details.innerHTML = ''; }
  if (st) clearTimeout(st.timer);
  RZJ.delete(key);
}

/**
 * Een lange taak volgen: de knop wordt ⏹ Stoppen met de voortgang, tot de taak klaar is. start = functie die de taak
 * begint (een api-belofte), of null om alleen een taak te volgen die al loopt. onEnd(job) na afloop.
 */
async function jobRun(key, btn, { start = null, details = null, onEnd = null } = {}) {
  if (RZJ.has(key) && RZJ.get(key).timer) return;   // volgen we al
  let ended = false;
  const finish = (j) => {
    if (ended) return;
    ended = true;
    jobRestore(key, btn, details);
    if (j) {
      if (j.state === 'stopped') toast('⏹ ' + (j.msg || L('Gestopt')));
      else if (j.state === 'error') toast(j.msg || L('Mislukt'), 'err');
      else if (start && j.msg) toast(j.msg);
    }
    if (onEnd) onEnd(j);
  };
  const click = async (e) => {
    if (!btn.classList.contains('rz-job')) return;
    e.preventDefault(); e.stopImmediatePropagation();
    try { await api('job_stop', { key }); btn.querySelector('.rz-job-in')?.firstChild && (btn.querySelector('.rz-job-in').firstChild.textContent = '⏳ ' + L('Stoppen…') + ' '); } catch (ex) { toast(ex.message, 'err'); }
  };
  if (btn) { btn.removeEventListener('click', btn._rzStop || (() => {}), true); btn._rzStop = click; btn.addEventListener('click', click, true); }
  jobPaint(key, { step: 0, steps: 1, stage: '', done: 0, total: 0, elapsed: 0, label: '', who: 'x', stop: false }, btn, null);
  const poll = async () => {
    let r;
    try { r = await apiRaw('job_status', null, { key }); } catch (ex) { r = null; }
    const j = r && r.job;
    if (j && j.running) {
      jobPaint(key, j, btn, details);
      const st = RZJ.get(key) || {};
      st.timer = setTimeout(() => { if (!ended && (!btn || btn.isConnected || (details && details.isConnected))) poll(); else if (!ended) { ended = true; RZJ.delete(key); } }, 1000);
      RZJ.set(key, st);
    } else if (!start || started) finish(j);
    else { const st = RZJ.get(key) || {}; st.timer = setTimeout(poll, 700); RZJ.set(key, st); }
  };
  let started = !start;
  if (start) {
    // De taak zelf: het antwoord komt pas aan het eind; intussen kijkt poll() hoe ver hij is
    start().then(() => { started = true; }, (ex) => {
      started = true;
      if (ex.status === 409 && ex.data && ex.data.running) return;   // er liep er al een: die volgen we
      if (ex.network || ex.status === 502 || ex.status === 504) return;   // de proxy brak het verzoek af; de taak loopt door
      ended = true; jobRestore(key, btn, details); toast(ex.message, 'err');
    });
  }
  poll();
}

/** Bij het tonen van een pagina: loopt er een taak? Dan de knop meteen weer als voortgang tonen. */
async function jobAttach(key, btn, opts = {}) {
  if (!btn || RZJ.has(key)) return;
  try {
    const r = await apiRaw('job_status', null, { key });
    if (r.job && r.job.running) jobRun(key, btn, opts);
  } catch (e) { /* geen rechten of oud: niets tonen */ }
}

// ---------------------------------------------------------------- reeksen in de browser
/** De knop telt "n van m" met een balk en kan stoppen. @return {{tick: function(number, string=): void, stopped: function(): boolean, end: function(): void}} */
function batchBtn(btn, total) {
  let stop = false;
  const html = btn.innerHTML;
  btn.classList.add('rz-job');
  btn.disabled = false;
  const onClick = (e) => { e.preventDefault(); e.stopImmediatePropagation(); stop = true; btn.querySelector('.rz-job-in').firstChild.textContent = '⏳ ' + L('Stoppen…') + ' '; };
  btn.addEventListener('click', onClick, true);
  const t0 = Date.now();
  const paint = (n, label = '') => {
    btn.style.setProperty('--rzp', ((n / Math.max(1, total)) * 100).toFixed(1) + '%');
    btn.innerHTML = `<span class="rz-job-in">${stop ? '⏳ ' + esc(L('Stoppen…')) : '⏹ ' + esc(L('Stoppen'))} <small>${esc(L('{n} van {m}', { n, m: total }))}${label ? ' · ' + esc(label) : ''} · ${esc(rzSecs(Date.now() - t0))}</small></span>`;
  };
  paint(0);
  return {
    tick: paint,
    stopped: () => stop,
    end: () => {
      btn.removeEventListener('click', onClick, true);
      if (!btn.isConnected) return;
      btn.classList.remove('rz-job');
      btn.style.removeProperty('--rzp');
      btn.innerHTML = html;
    },
  };
}

/** Een reeks één voor één afwerken met de teller in de knop (standaard de opslaan-knop van het venster). @return {Promise<{n: number, stopped: boolean}>} */
async function batchRun(items, fn, btn = $('#modal-footer button[type=submit]')) {
  const b = btn && items.length > 1 ? batchBtn(btn, items.length) : null;
  let n = 0;
  try {
    for (const it of items) {
      if (b && b.stopped()) break;
      await fn(it);
      n++;
      if (b) b.tick(n);
    }
  } finally { if (b) b.end(); }
  return { n, stopped: !!(b && b.stopped() && n < items.length) };
}

// ---------------------------------------------------------------- wat de agent doet: overal dezelfde vier stappen
const AGENT_STEPS = () => [L('aangevraagd'), L('opgepakt'), L('bezig'), L('klaar')];
/** st = { state: queued|running|ok|error, pct? }: kleine stappenbalk "aangevraagd → opgepakt → bezig (x%) → klaar" */
function agentSteps(st) {
  if (!st) return '';
  const s = st.state;
  const cur = s === 'queued' ? 0 : s === 'running' ? (typeof st.pct === 'number' ? 2 : 1) : 3;
  const names = AGENT_STEPS();
  const txt = s === 'queued' ? L('aangevraagd · wacht op de agent') : s === 'error' ? '✘ ' + L('mislukt') : s === 'ok' ? '✔ ' + names[3]
    : cur === 2 ? L('bezig · {p}%', { p: Math.round(st.pct) }) : L('opgepakt door de agent');
  return `<span class="ag-steps ${s}" title="${esc(names.join(' → '))}"><span class="ag-dots">${names.map((n, i) => `<i class="${i < cur || s === 'ok' ? 'done' : i === cur ? 'cur' : ''}"></i>`).join('')}</span>
    <span class="ag-txt">${esc(txt)}</span>${cur === 2 ? `<span class="ag-bar"><i style="width:${Math.max(2, Math.min(100, st.pct))}%"></i></span>` : ''}</span>`;
}
