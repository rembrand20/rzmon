// ---------------------------------------------------------------- Analyse (1.106.0)
let anPeriod = uiGet('an_period', 'rzmon-an-period', '30d');
let anData = null;
const AN_PERIODS = [['1d', () => L('24 uur')], ['7d', () => L('7 dagen')], ['30d', () => L('30 dagen')], ['90d', () => L('90 dagen')], ['365d', () => L('1 jaar')]];

// ---------------------------------------------------------------- 1.155.0: Analyse en advies
// Slimme analyses (Insights): elk advies combineert gegevens uit agent, CVE-scan, IDS, netwerkscan en checks, met het
// bewijs, de stappen en (voor beheerders) een knop om het meteen te regelen. Daaronder de analyses in tabbladen.
let iaData = null, iaAn = null, iaFilter = 'open';
let iaTab = (() => { try { return localStorage.getItem('rzmon-an-tab') || 'trends'; } catch (e) { return 'trends'; } })();   // 1.224.0: Trends eerst
const iaOpen = new Set();
const IA_DOM = { access: ['🔑', () => L('Toegang en SSH')], exposure: ['🌐', () => L('Blootstelling van buiten')], vulns: ['🐞', () => L('Kwetsbaarheden en updates')],
  detect: ['🛡️', () => L('Detectie en reactie')], network: ['📡', () => L('Netwerk en apparaten')], recovery: ['💾', () => L('Herstel en monitoring')] };
const IA_SEV = [['t-mu', () => L('LAAG')], ['t-wa', () => L('MIDDEL')], ['t-cr', () => L('HOOG')]];
const IA_EFF = { click: () => L('1 KLIK'), 5: () => L('5 MIN'), 10: () => L('10 MIN'), 15: () => L('15 MIN'), 30: () => L('30 MIN'), 60: () => L('1 UUR+') };
const IA_ST = { done: () => L('Gemeld als gedaan'), later: () => L('Later'), na: () => L('Niet van toepassing') };
const iaScoreCls = (s) => (s >= 85 ? 'ok' : s >= 65 ? 'warn' : 'crit');

async function iaLoad() {
  const days = { '1d': 7, '7d': 7, '30d': 30, '90d': 90, '365d': 90 }[anPeriod] || 30;
  try { [iaData, iaAn] = await Promise.all([api('insights'), api('insight_analyses', null, { days })]); } catch (ex) { iaData = { error: ex.message }; }
}

/** De lijst die nu getoond wordt (filter bovenaan) */
function iaShown() {
  const all = iaData.advices || [];
  const vis = (a) => !a.status || a.status === 'done';
  if (iaFilter === 'open') return all.filter(vis);
  if (iaFilter === 'new') return all.filter((a) => vis(a) && a.new);
  if (iaFilter === 'quick') return all.filter((a) => vis(a) && (a.effort === 'click' || +a.effort <= 5));
  if (iaFilter === 'hidden') return all.filter((a) => a.status === 'later' || a.status === 'na');
  return all.filter((a) => vis(a) && a.dom === iaFilter);
}

function iaTop() {
  const d = iaData, s = d.score, cls = iaScoreCls(s);
  const open = (d.advices || []).filter((a) => !a.status || a.status === 'done');
  const quick = open.filter((a) => a.effort === 'click' || +a.effort <= 5).slice(0, 5);
  const after = Math.min(100, s + quick.reduce((t, a) => t + a.gain, 0));
  const r = 54, C = 2 * Math.PI * r;
  const hist = (d.history || []).map((h) => h.total);
  const domBars = Object.keys(IA_DOM).map((k) => {
    const v = d.domains[k] ?? 100, n = open.filter((a) => a.dom === k).length, worst = open.find((a) => a.dom === k);
    return `<button type="button" class="ia-dom ${iaFilter === k ? 'on' : ''}" data-ia-filter="${k}"><span class="ia-dom-h"><b>${IA_DOM[k][0]} ${esc(IA_DOM[k][1]())}</b>
      <span><b class="ia-v ${iaScoreCls(v)}">${v}</b>${n ? ` <span class="muted small">· ${esc(L('{n} adviezen', { n }))}</span>` : ''}</span></span>
      <span class="ia-meter"><i class="${iaScoreCls(v)}" style="width:${v}%"></i></span><span class="muted small ia-dom-s">${esc(worst ? worst.title : L('Niets te doen'))}</span></button>`;
  }).join('');
  return `<div class="ia-top">
    <div class="cc-card cc-score ia-score ${cls}"><svg width="132" height="132" viewBox="0 0 132 132" aria-hidden="true"><circle cx="66" cy="66" r="${r}" class="cc-ring-bg"/>
        <circle cx="66" cy="66" r="${r}" class="cc-ring" stroke-dasharray="${(C * s / 100).toFixed(1)} ${C.toFixed(1)}" transform="rotate(-90 66 66)"/>
        <text x="66" y="72" text-anchor="middle" class="cc-ring-n">${s}</text><text x="66" y="92" text-anchor="middle" class="cc-ring-s">${esc(L('veiligheid'))}</text></svg>
      <div class="ia-score-t"><span class="cc-hl ${cls}">${esc(cls === 'ok' ? L('Goed beveiligd') : cls === 'warn' ? L('Redelijk · kan beter') : L('Kwetsbaar · nu aanpakken'))}</span>
        <div>${esc(L('{n} open adviezen', { n: open.length }))}${open.filter((a) => a.sev === 2).length ? ` · <b class="crit-txt">${esc(L('{n} hoog risico', { n: open.filter((a) => a.sev === 2).length }))}</b>` : ''}</div>
        ${quick.length && after > s ? `<div class="small">${L('Met de {n} snelste adviezen ga je naar <b>≈ {s}</b>.', { n: quick.length, s: after })}</div>` : ''}
        ${hist.length > 1 ? `${afSpark(hist.slice(-60), 190, 34, '')}<span class="muted small">${esc(L('verloop van de score'))} · ${hist.length >= 2 ? (hist[hist.length - 1] - hist[0] >= 0 ? '↑ ' : '↓ ') + Math.abs(hist[hist.length - 1] - hist[0]) + ' ' + esc(L('punten')) : ''}</span>`
          : `<span class="muted small">${esc(L('Het verloop verschijnt na een paar dagen (elke nacht bewaard)'))}</span>`}</div></div>
    <div class="cc-card ia-doms"><div class="cc-h"><span class="cc-hl">${esc(L('Score per onderdeel'))}</span><span class="muted small">${esc(L('klik voor de adviezen van dat onderdeel'))}</span></div>
      <div class="ia-domgrid">${domBars}</div></div></div>`;
}

function iaCard(a) {
  const open = iaOpen.has(a.key);
  const admin = iaData.admin;
  const acts = (a.acts || []).map((x, i) => {
    const lbl = { upgrade: '⬆ ' + (x.label ? L('Bijwerken op {h}', { h: x.label }) : L('Bijwerken via de agent')), cve: '🐞 ' + x.cve, ip: '🔎 ' + L('Alles over {ip}', { ip: x.ip }),
      block: '⛔ ' + L('Blokkeren'), ids_block: '⛔ ' + L('Nu op Blokkeren zetten'), ids_on: L('IDS aanzetten'), set: '✔ ' + L('Nu aanzetten'), go: (x.label || L('Bekijken')) + ' →' }[x.k] || x.k;
    // 1.181.0: wat iets verandert (bijwerken, blokkeren, een instelling) alleen voor beheerders
    const adm = ['upgrade', 'ids_block', 'set', 'block', 'ids_on'].includes(x.k) ? ' need-admin' : '';
    return `<button type="button" class="btn small-btn${adm} ${i === 0 && adm ? 'primary' : ''}" data-ia-act="${i}">${esc(lbl)}</button>`;
  }).join('');
  const cmds = (a.cmds || []).map(([l, c]) => `<div class="ia-cmd"><span class="small muted">${esc(l)}</span><pre>${esc(c)}</pre><button type="button" class="linkbtn small" data-ia-copy>${esc(L('Kopiëren'))}</button></div>`).join('');
  const st = a.status ? `<span class="cc-tag ${a.status === 'done' ? 'ok' : ''}">${esc(IA_ST[a.status]())}</span>` : '';
  const stBtns = a.status === 'later' || a.status === 'na' ? `<button type="button" class="btn small-btn" data-ia-st="open">${esc(L('Weer tonen'))}</button>`
    : `<button type="button" class="btn small-btn" data-ia-st="done">✔ ${esc(L('Ik heb het gedaan'))}</button><button type="button" class="btn small-btn" data-ia-st="later">${esc(L('Later (7 dagen)'))}</button>
       <button type="button" class="btn small-btn" data-ia-st="na">${esc(L('Niet van toepassing'))}</button>`;
  return `<article class="ia s${a.sev} ${open ? 'open' : ''}" data-ia="${esc(a.key)}">
    <button type="button" class="ia-head" data-ia-toggle aria-expanded="${open}"><i class="ia-bar s${a.sev}"></i>
      <span class="ia-ttl"><b>${a.new ? `<span class="cc-tag warn">${esc(L('NIEUW'))}</span> ` : ''}${esc(a.title)}</b>
        <small class="muted">${IA_DOM[a.dom][0]} ${esc(IA_DOM[a.dom][1]())}${a.host ? ' · ' + esc(a.host) : ''}${a.crit ? ' · 🚨 ' + esc(L('wereldwijd bekend, gemeld')) : ''}</small></span>
      <span class="ia-tags"><span class="tag-s ${IA_SEV[a.sev][0]}">${esc(IA_SEV[a.sev][1]())}</span><span class="tag-s t-cy">${esc((IA_EFF[a.effort] || IA_EFF[30])())}</span><span class="tag-s t-ok">+${a.gain}</span>${st}</span>
      <span class="ia-chev" aria-hidden="true">▾</span></button>
    ${open ? `<div class="ia-body">
      <div class="ia-why"><h4>${esc(L('Waarom (uit je gegevens)'))}</h4><ul>${a.why.map((w) => `<li>${esc(w)}</li>`).join('')}</ul></div>
      <div class="ia-do"><h4>${esc(L('Wat te doen'))}</h4><p>${esc(a.do)}</p>${cmds}${a.note ? `<p class="small warn-txt">⚠ ${esc(a.note)}</p>` : ''}${a.check ? `<p class="small muted">${esc(L('Controleren'))}: <code>${esc(a.check)}</code></p>` : ''}</div>
      <div class="ia-acts">${acts}<span class="ia-sp"></span>${a.status === 'done' ? `<span class="small warn-txt">${esc(L('RzSense ziet het nog; het verdwijnt vanzelf zodra het echt is opgelost.'))}</span>` : ''}${stBtns}</div>
      ${!admin && (a.acts || []).length < 1 ? '' : ''}</div>` : ''}</article>`;
}

function iaList() {
  const all = iaData.advices || [];
  const vis = all.filter((a) => !a.status || a.status === 'done');
  const chips = [['open', L('Alle'), vis.length], ['new', L('Nieuw'), vis.filter((a) => a.new).length], ['quick', L('Snelle winst'), vis.filter((a) => a.effort === 'click' || +a.effort <= 5).length],
    ...Object.keys(IA_DOM).map((k) => [k, IA_DOM[k][0], vis.filter((a) => a.dom === k).length]), ['hidden', L('Verborgen'), all.filter((a) => a.status === 'later' || a.status === 'na').length]];
  const list = iaShown();
  if (!iaOpen.size && !iaList.done) { list.filter((a) => a.sev === 2).slice(0, 2).forEach((a) => iaOpen.add(a.key)); iaList.done = true; }
  const res = iaData.resolved || [];
  return `<section class="ia-sec"><div class="ia-sec-h"><div><h2>${esc(L('Adviezen'))}</h2><span class="muted small">${esc(L('belangrijkste eerst: risico, en winst per minuut werk · elk advies met het bewijs uit je eigen gegevens'))}</span></div>
      <div class="dash-chips ia-chips">${chips.map(([k, l, n]) => `<button type="button" class="dchip ${iaFilter === k ? 'on' : ''}" data-ia-filter="${k}" title="${esc(IA_DOM[k] ? IA_DOM[k][1]() : l)}">${esc(l)} <b>${n}</b></button>`).join('')}</div></div>
    ${list.length ? `<div class="ia-list">${list.map(iaCard).join('')}</div>` : `<p class="good-txt">✔ ${esc(iaFilter === 'open' ? L('Geen adviezen. Netjes!') : L('Niets in deze selectie.'))}</p>`}
    ${res.length ? `<details class="ia-res"><summary>${esc(L('Opgelost in de laatste 30 dagen ({n})', { n: res.length }))}</summary><ul>${res.map((r) => `<li><span class="good-txt">✔</span> ${esc(r.title)}
      <span class="muted small">· ${esc(fmtDateTime(r.at))} · +${r.gain}</span></li>`).join('')}</ul></details>` : ''}</section>`;
}

/** Analyses: aanvallen (wanneer, gebruikersnamen, werken de blokkades) en het aanvalsoppervlak per server */
function iaAttacks(sec) {
  const a = iaAn;
  if (!a) return sec;
  const max = Math.max(1, ...a.heat.flat());
  const days = [L('ma'), L('di'), L('wo'), L('do'), L('vr'), L('za'), L('zo')];
  const heat = `<div class="ia-heat" role="img" aria-label="${esc(L('Aanvallen per weekdag en uur'))}"><span></span>${Array.from({ length: 24 }, (_, h) => `<span class="ia-hh">${h % 3 ? '' : h}</span>`).join('')}
    ${a.heat.map((row, d) => `<span class="ia-hd">${esc(days[d])}</span>${row.map((v, h) => `<i style="opacity:${v ? (0.12 + 0.88 * v / max).toFixed(2) : 0.05}" title="${esc(`${days[d]} ${h}:00 · ${v}`)}"></i>`).join('')}`).join('')}</div>`;
  let peak = [0, 0, 0];
  a.heat.forEach((row, d) => row.forEach((v, h) => { if (v > peak[2]) peak = [d, h, v]; }));
  const users = a.users.length ? a.users.map((u) => `<div class="ia-u"><span class="mono">${esc(u.u)}</span><span class="ia-meter"><i class="${u.exists ? 'crit' : 'warn'}" style="width:${Math.min(100, u.pct * 2)}%"></i></span>
      <span class="small">${esc(dec(u.pct))}%</span><span class="tag-s ${u.exists ? 't-cr' : 't-mu'}">${esc(u.exists ? L('BESTAAT') : L('NIET'))}</span></div>`).join('')
    : `<p class="muted small">${esc(L('Nog geen losse SSH-pogingen (agent 1.74.0 en nieuwer).'))}</p>`;
  const exist = a.users.filter((u) => u.exists).reduce((t, u) => t + u.pct, 0);
  const b = a.blocks;
  const ports = a.ports.length ? anHbars(a.ports.map((p) => ({ label: `${p.svc || L('poort')} ${p.port}`, v: p.n }))) : `<p class="muted small">${esc(L('Geen aanvallen in deze periode.'))}</p>`;
  return anSec('⚔️', L('Aanvallen'), `<div class="an-grid">
    ${anBox(L('Wanneer wordt er aangevallen'), heat + (peak[2] ? `<p class="small muted">${esc(L('Het drukst op {d} rond {h}:00 ({n} pogingen in dat uur).', { d: days[peak[0]], h: peak[1], n: peak[2] }))}</p>` : ''), '', true)}
    ${anBox(L('Welke gebruikersnamen proberen ze'), users + (a.users.length ? `<p class="small muted">${esc(L('{p}% van de pogingen richt zich op accounts die bij jou bestaan.', { p: dec(exist.toFixed(0)) }))}</p>` : ''))}
    ${anBox(L('Werken de blokkades?'), `<div class="an-stats"><div><b class="${b.drop !== null && b.drop >= 80 ? 'good-txt' : 'warn-txt'}">${b.drop === null ? '–' : (b.drop > 0 ? '−' : '') + Math.abs(b.drop) + '%'}</b><span>${esc(L('pogingen in het uur na een blokkade'))}</span></div>
      <div><b>${b.ttb === null ? '–' : esc(anDur(b.ttb))}</b><span>${esc(L('van eerste poging tot blokkade (gem.)'))}</span></div><div><b class="${b.repeat ? 'warn-txt' : ''}">${b.repeat}</b><span>${esc(L('kwamen terug na hun blokkade'))}</span></div></div>`,
      L('Vergelijkt de losse SSH-pogingen in het uur voor en na elke blokkade.'))}
    ${anBox(L('Poorten onder vuur'), ports)}
  </div>`) + sec;
}

function iaSurface() {
  const s = (iaAn && iaAn.surface) || [];
  if (!s.length) return anSec('🎯', L('Aanvalsoppervlak per server'), `<p class="muted">${esc(L('Nog geen servers met een agent die open poorten meldt.'))}</p>`);
  return anSec('🎯', L('Aanvalsoppervlak per server'), `<p class="muted small">${esc(L('Per server de open poorten, de ergste kwetsbaarheid daarop, de aanvallen van buiten, de hardening-score en het risico.'))}</p><div class="table-wrap"><table class="small ia-surf"><thead><tr><th>${esc(L('Server'))}</th><th>${esc(L('Open poorten'))}</th><th>${esc(L('Ergste kwetsbaarheid'))}</th><th class="num">${esc(L('Aanvallen'))}</th><th class="num">${esc(L('Hardening'))}</th><th class="num">${esc(L('Risico'))}</th><th>${esc(L('Adviezen'))}</th></tr></thead><tbody>
    ${s.map((h) => { const n = (iaData.advices || []).filter((x) => x.host_id === h.id && !x.status).length;
      return `<tr><td><b>${esc(h.name)}</b></td><td class="mono">${h.ports.map((p) => `<span title="${esc(p.proc || '')}">${p.port}${p.out ? ' <span class="crit-txt">●</span>' : ''}</span>`).join(' ') || '–'}</td>
      <td>${h.worst ? `<button type="button" class="linkbtn" data-ia-cve="${esc(h.worst.cve)}">${esc(h.worst.cve)}</button> ${urgPill(h.worst.risk, [], true)}${h.worst.kev ? ' ⚠️' : ''}` : '<span class="muted">–</span>'}</td>
      <td class="num">${amN(h.attacks)}</td><td class="num">${h.hardening === null ? '–' : h.hardening}</td><td class="num">${urgPill(h.risk, [], true)}</td>
      <td>${n ? `<button type="button" class="linkbtn" data-ia-host="${h.id}">${esc(L('{n} adviezen', { n }))}</button>` : `<span class="good-txt small">${esc(L('in orde'))}</span>`}</td></tr>`; }).join('')}
  </tbody></table></div><p class="muted small"><span class="crit-txt">●</span> = ${esc(L('in deze periode van buiten aangevallen, dus bereikbaar vanaf internet'))}</p>`);
}

/** De hele pagina: score, adviezen, en de analyses als tabbladen (de bestaande onderdelen van Analyse blijven) */
function iaRender(p) {
  if (!iaData || iaData.error) return `${iaData && iaData.error ? `<p class="form-error">${esc(iaData.error)}</p>` : ''}${p.kpis}${p.qual}${p.rel}${p.cap}${p.sec}${p.net}${p.bk}${p.noise}`;
  if (iaTab === 'trends' && !tnData) tnLoad();   // 1.224.0: pas laden als je het tabblad opent
  const tabs = [['trends', '📉 ' + L('Trends'), tnRender()], ['attacks', '⚔️ ' + L('Aanvallen'), iaAttacks(p.sec)], ['surface', '🎯 ' + L('Blootstelling'), iaSurface()], ['rel', '🟢 ' + L('Betrouwbaarheid'), p.kpis + p.rel],
    ['cap', '📈 ' + L('Capaciteit'), p.cap], ['net', '🌐 ' + L('Netwerk'), p.net], ['bk', '💾 ' + L('Back-ups'), p.bk], ['noise', '🔔 ' + L('Meldingen en ruis'), p.noise + p.qual]];
  if (!tabs.some((t) => t[0] === iaTab)) iaTab = 'trends';
  return `${iaTop()}${iaList()}
    <section class="ia-sec"><div class="ia-sec-h"><div><h2>${esc(L('Analyses'))}</h2><span class="muted small">${esc(L('de patronen achter de adviezen'))}</span></div>
      <div class="seg ia-tabs" role="tablist">${tabs.map(([k, l]) => `<button type="button" class="seg-btn ${k === iaTab ? 'active' : ''}" role="tab" aria-selected="${k === iaTab}" data-ia-tab="${k}">${esc(l)}</button>`).join('')}</div></div>
      <div class="ia-pane">${tabs.find((t) => t[0] === iaTab)[2]}</div></section>`;
}

/** Klikken in het advies-deel (één keer gekoppeld aan #an-body) */
function iaClick(e) {
  const t = e.target.closest('[data-ia-filter], [data-ia-toggle], [data-ia-st], [data-ia-act], [data-ia-copy], [data-ia-tab], [data-ia-cve], [data-ia-host]');
  if (!t) return false;
  e.preventDefault();
  const card = t.closest('[data-ia]'), key = card && card.dataset.ia, adv = key && (iaData.advices || []).find((a) => a.key === key);
  if (t.dataset.iaFilter) { iaFilter = iaFilter === t.dataset.iaFilter && t.dataset.iaFilter !== 'open' ? 'open' : t.dataset.iaFilter; renderAnalytics(); return true; }
  if (t.dataset.iaTab) { iaTab = t.dataset.iaTab; try { localStorage.setItem('rzmon-an-tab', iaTab); } catch (ex) { /* alleen nu */ } renderAnalytics(); return true; }
  if (t.dataset.iaCve) { vulnDetail(t.dataset.iaCve); return true; }
  if (t.dataset.iaHost) { iaFilter = 'open'; const h = (iaData.advices || []).find((a) => a.host_id === +t.dataset.iaHost); if (h) iaOpen.add(h.key); renderAnalytics(); $('.ia-list')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); return true; }
  if (t.dataset.iaToggle !== undefined && key) { iaOpen.has(key) ? iaOpen.delete(key) : iaOpen.add(key); renderAnalytics(); return true; }
  if (t.dataset.iaCopy !== undefined) { const pre = t.parentElement.querySelector('pre'); navigator.clipboard?.writeText(pre.textContent).then(() => toast(L('Gekopieerd')), () => {}); return true; }
  if (t.dataset.iaSt && key) {
    api('insight_set', { key, status: t.dataset.iaSt }).then(async () => {
      toast({ done: L('Gemarkeerd als gedaan; RzSense controleert het zelf'), later: L('7 dagen verborgen'), na: L('Verborgen: niet van toepassing'), open: L('Weer zichtbaar') }[t.dataset.iaSt]);
      await iaLoad(); renderAnalytics();
    }).catch((ex) => toast(ex.message, 'err'));
    return true;
  }
  if (t.dataset.iaAct !== undefined && adv) iaAction(adv, adv.acts[+t.dataset.iaAct]);
  return true;
}

/** Een knop van een advies: altijd met bevestiging als er iets verandert */
function iaAction(a, x) {
  if (!x) return;
  const done = async (msg) => { toast(msg); await iaLoad(); renderAnalytics(); };
  if (x.k === 'go') { if (x.go.startsWith('check:')) checkInfo(x.go.slice(6)); else showTab(x.go); return; }
  if (x.k === 'cve') return vulnDetail(x.cve);
  if (x.k === 'ip') return amDetail(x.ip);
  if (x.k === 'block') return blockDialog(x.ip);
  if (x.k === 'upgrade') return vulnUpgrade({ id: x.host_id, name: x.label || a.host || '?' }, x.pkgs);
  if (x.k === 'ids_block' || x.k === 'ids_on') {
    return modal({
      title: '⛔ ' + (x.k === 'ids_on' ? L('IDS aanzetten') : L('IDS: blokkeren aanzetten')),
      body: `<div style="padding:1rem 1.2rem"><p style="margin-top:0">${L('RzSense gaat dan echt IP-adressen blokkeren op je servers (eigen nftables-tabel van de agent), bij brute force en als jij op ⛔ klikt. Je LAN, RzSense zelf en de lijst <i>Nooit blokkeren</i> worden nooit automatisch geblokkeerd; op TrueNAS wordt alleen gedetecteerd.')}</p>
        ${x.k === 'ids_block' ? confirmField('BLOKKEREN') : ''}</div>`,
      buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: x.k === 'ids_on' ? L('IDS aanzetten') : L('Blokkeren aanzetten'), cls: 'primary', submit: true }],
      onSubmit: async (d) => { await api('ids_set', x.k === 'ids_on' ? { enabled: true } : { mode: 'block', confirm: d.confirm }); await done(L('Gedaan')); },
    });
  }
  if (x.k === 'set') {
    return modal({
      title: a.title, body: `<div style="padding:1rem 1.2rem"><p style="margin-top:0">${esc(a.do)}</p><p class="muted small">${esc(L('Dit verandert een instelling van RzSense en komt in het wijzigingslogboek, waar je het kunt terugdraaien.'))}</p></div>`,
      buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Nu aanzetten'), cls: 'primary', submit: true }],
      onSubmit: async () => { await api('settings_save', { [x.name]: x.value }); await done(L('Instelling aangepast')); },
    });
  }
}

function initAnalytics() {
  $('#an-period').onclick = (e) => { const b = e.target.closest('[data-anp]'); if (b) { anPeriod = b.dataset.anp; uiSet('an_period', 'rzmon-an-period', anPeriod); loadAnalytics(); } };
  $('#an-go').onclick = () => { const f = $('#an-from').value, t = $('#an-to').value; if (f && t && f <= t) { anPeriod = `${f}..${t}`; loadAnalytics(); } };
  $('#an-body').onclick = (e) => {
    const tf = e.target.closest('[data-tr-f]');   // 1.224.0: filter van Trends
    if (tf) { tnFilter = tf.dataset.trF; renderAnalytics(); return; }
    if (iaClick(e)) return;   // 1.155.0: adviezen en tabbladen
    const g = e.target.closest('[data-an-go]');
    if (!g) return;
    const go = g.dataset.anGo;
    if (go.startsWith('check:')) checkInfo(go.slice(6)); else showTab(go);
  };
  $('#an-report').onclick = (e) => { const g = e.target.closest('a[data-goto]'); if (g) { e.preventDefault(); showTab(g.dataset.goto); } };   // 1.180.0

}

async function loadAnalytics(fresh = false) {
  $('#an-period').innerHTML = AN_PERIODS.map(([k, l]) => `<button type="button" class="dchip ${anPeriod === k ? 'on' : ''}" data-anp="${k}">${l()}</button>`).join('');
  $('#an-info').textContent = '⏳';
  try { [anData] = await Promise.all([api('analytics', null, { period: anPeriod, ...(fresh ? { fresh: 1 } : {}) }), iaLoad()]); } catch (ex) { $('#an-body').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderAnalytics();
}

/** Getal met eenheid; dec() voor de komma */
const anNum = (v, d = 0) => (v === null || v === undefined ? '–' : dec((+v).toFixed(d)));
const anDur = (s) => (s === null || s === undefined ? '–' : s >= 86400 ? `${anNum(s / 86400, 1)} ${L('dagen')}` : s >= 3600 ? `${anNum(s / 3600, 1)} ${L('uur')}` : `${Math.max(1, Math.round(s / 60))} min`);
const anDay = (d) => new Date(d + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' });

/** Verschil met de vorige periode: ▲/▼ en of dat goed of slecht is */
function anDelta(now, prev, lowerBetter = false, unit = '%') {
  if (now === null || prev === null || prev === undefined || now === undefined) return `<span class="muted small">${L('geen vorige periode')}</span>`;
  const diff = unit === 'pt' ? now - prev : prev ? (now - prev) / prev * 100 : (now ? 100 : 0);
  if (Math.abs(diff) < (unit === 'pt' ? 0.01 : 0.5)) return `<span class="muted small">= ${L('gelijk')}</span>`;
  const better = lowerBetter ? diff < 0 : diff > 0;
  return `<span class="small ${better ? 'good-txt' : 'crit-txt'}" title="${esc(L('t.o.v. de vorige periode van dezelfde lengte'))}">${diff > 0 ? '▲' : '▼'} ${anNum(Math.abs(diff), unit === 'pt' ? 2 : 0)}${unit === 'pt' ? ' ' + L('punt') : '%'}</span> <span class="muted small">${L('vs. vorige')}</span>`;
}

/** Lijn (of vlak) over de dagen, één reeks, met een tooltip per dag */
function anLine(series, { unit = '', d = 0, h = 110, cls = '' } = {}) {
  const ks = Object.keys(series);
  const vals = ks.map((k) => series[k]);
  const nums = vals.filter((v) => v !== null && v !== undefined);
  if (!nums.length) return `<p class="muted small">${L('Nog geen gegevens in deze periode.')}</p>`;
  const W = 600, top = 6, bot = h - 18;
  let lo = Math.min(...nums), hi = Math.max(...nums);
  if (lo === hi) { lo = Math.min(0, lo); hi = hi || 1; }
  const x = (i) => (ks.length < 2 ? W / 2 : i / (ks.length - 1) * W);
  const y = (v) => bot - (v - lo) / (hi - lo) * (bot - top);
  // Lijn en vlak per aaneengesloten stuk: dagen zonder gegevens blijven leeg
  let path = '', area = '', seg = [];
  const close = () => {
    if (seg.length) area += `M${seg[0][0]},${bot}${seg.map(([px, py]) => `L${px},${py}`).join('')}L${seg[seg.length - 1][0]},${bot}Z`;
    seg = [];
  };
  vals.forEach((v, i) => {
    if (v === null || v === undefined) { close(); return; }
    const p = [x(i).toFixed(1), y(v).toFixed(1)];
    path += `${seg.length ? 'L' : 'M'}${p[0]},${p[1]}`;
    seg.push(p);
  });
  close();
  const step = ks.length < 2 ? W : W / (ks.length - 1);
  const hits = ks.map((k, i) => `<rect x="${(x(i) - step / 2).toFixed(1)}" y="0" width="${step.toFixed(1)}" height="${h}" fill="transparent"><title>${esc(anDay(k))}: ${vals[i] === null ? '–' : anNum(vals[i], d) + unit}</title></rect>`).join('');
  return `<div class="an-chart ${cls}"><svg viewBox="0 0 ${W} ${h}" preserveAspectRatio="none" role="img">
      <line x1="0" x2="${W}" y1="${bot}" y2="${bot}" class="an-axis"/><path d="${area}" class="an-area"/><path d="${path}" class="an-line" vector-effect="non-scaling-stroke"/>${hits}</svg>
    <div class="an-yl"><span>${anNum(hi, d)}${unit}</span><span>${anNum(lo, d)}${unit}</span></div>
    <div class="an-xl"><span>${esc(anDay(ks[0]))}</span><span>${esc(anDay(ks[ks.length - 1]))}</span></div></div>`;
}

/** Staafjes per dag (aantallen), één reeks */
function anBars(series, { h = 90, cls = '' } = {}) {
  const ks = Object.keys(series);
  const vals = ks.map((k) => +series[k] || 0);
  const hi = Math.max(...vals, 0);
  if (!hi) return `<p class="muted small">${L('Niets in deze periode.')}</p>`;
  const W = 600, bot = h - 2, bw = W / ks.length;
  const bars = vals.map((v, i) => {
    const bh = v ? Math.max(2, v / hi * (bot - 4)) : 0;
    return `<g><rect x="${(i * bw + 1).toFixed(1)}" y="0" width="${Math.max(1, bw - 2).toFixed(1)}" height="${h}" fill="transparent"/>${bh ? `<rect class="an-bar" x="${(i * bw + 1).toFixed(1)}" y="${(bot - bh).toFixed(1)}" width="${Math.max(1, bw - 2).toFixed(1)}" height="${bh.toFixed(1)}" rx="2"/>` : ''}<title>${esc(anDay(ks[i]))}: ${anNum(v)}</title></g>`;
  }).join('');
  return `<div class="an-chart ${cls}"><svg viewBox="0 0 ${W} ${h}" preserveAspectRatio="none" role="img"><line x1="0" x2="${W}" y1="${bot}" y2="${bot}" class="an-axis"/>${bars}</svg>
    <div class="an-yl"><span>${anNum(hi)}</span><span>0</span></div><div class="an-xl"><span>${esc(anDay(ks[0]))}</span><span>${esc(anDay(ks[ks.length - 1]))}</span></div></div>`;
}

/** Horizontale balken met label en waarde (ranglijst) */
function anHbars(rows, { max = null, fmt = (v) => anNum(v), cls = () => '' } = {}) {
  if (!rows.length) return `<p class="muted small">${L('Niets in deze periode.')}</p>`;
  const m = max ?? Math.max(...rows.map((r) => +r.v || 0), 1);
  return `<div class="an-hbars">${rows.map((r) => `<div class="an-hrow" title="${esc(r.title || '')}"><span class="an-hl">${r.html ?? esc(r.label)}</span>
    <span class="an-ht"><span class="an-hb ${cls(r)}" style="width:${Math.max(1, (+r.v || 0) / m * 100).toFixed(1)}%"></span></span><span class="an-hv">${fmt(r.v, r)}</span></div>`).join('')}</div>`;
}

/** Heatmap weekdag × uur (één kleur, donkerder = vaker) */
function anHeat(m) {
  const hi = Math.max(...m.flat(), 0);
  if (!hi) return `<p class="muted small">${L('Geen storingen in deze periode.')}</p>`;
  const days = [L('ma'), L('di'), L('wo'), L('do'), L('vr'), L('za'), L('zo')];
  return `<div class="an-heat"><span></span>${Array.from({ length: 24 }, (_, h) => `<span class="an-hh">${h % 3 === 0 ? h : ''}</span>`).join('')}
    ${m.map((row, w) => `<span class="an-hd">${days[w]}</span>${row.map((n, h) => `<span class="an-cell" style="--o:${n ? (0.18 + n / hi * 0.82).toFixed(2) : 0}" title="${esc(days[w])} ${h}:00–${h + 1}:00 · ${n} ${L('storing(en)')}"></span>`).join('')}`).join('')}</div>`;
}

const anSec = (icon, title, body, sub = '') => `<section class="card an-sec"><h2>${icon} ${title}${sub ? ` <span class="muted small">${sub}</span>` : ''}</h2>${body}</section>`;
const anBox = (title, body, help = '', wide = false) => `<div class="an-box${wide ? ' an-wide' : ''}"><h3>${title}</h3>${help ? `<p class="muted small an-help">${help}</p>` : ''}${body}</div>`;

function renderAnalytics() {
  const d = anData;
  const k = d.kpi;
  // Geen vorige periode als er toen nog geen gegevens waren (dan is elk verschil "100%")
  if (!d.since || d.prev[1] < d.since) ['avail', 'incidents', 'mttr', 'alerts', 'security'].forEach((x) => { k[x][1] = null; });
  $('#an-info').textContent = `${anDay(d.from)} – ${anDay(d.to)}${d.since ? ' · ' + L('gegevens sinds {d}', { d: anDay(d.since) }) : ''}${d.cached ? '' : ''}`;
  $('#an-from').value = d.from; $('#an-to').value = d.to;
  $('#an-report').innerHTML = `<a class="btn small-btn" href="#settings/reports" data-goto="settings/reports">📬 ${L('Rapporten')}</a>`;   // 1.180.0: instellen in de beheerportal
  const tile = (lbl, val, sub, spark = '', cls = '') => `<div class="kpi ${cls}"><div class="kpi-lbl">${lbl}</div><div class="kpi-val">${val}</div><div class="kpi-sub">${sub}</div>${spark}</div>`;
  const kspark = (s) => `<div class="an-kspark">${anLine(s, { h: 34, cls: 'an-mini' })}</div>`;
  const score = k.score;
  const kpis = `<div class="kpis an-kpis">
    ${tile(L('Score'), `${score}<small>/100</small>`, score >= 90 ? L('prima') : score >= 70 ? L('een paar aandachtspunten') : L('werk aan de winkel'), '', score >= 90 ? '' : score >= 70 ? 'warn' : 'bad')}
    ${tile(L('Beschikbaarheid'), k.avail[0] === null ? '–' : `${anNum(k.avail[0], 2)}<small>%</small>`, anDelta(k.avail[0], k.avail[1], false, 'pt'), kspark(k.avail[2]))}
    ${tile(L('Storingen'), anNum(k.incidents[0]), anDelta(k.incidents[0], k.incidents[1], true), kspark(k.incidents[2]), k.incidents[0] ? 'warn' : '')}
    ${tile(L('Hersteltijd (gemiddeld)'), anDur(k.mttr[0]), k.mttr[2] ? L('{n} storingen opgelost', { n: k.mttr[2] }) + (k.mttr[1] !== null && k.mttr[1] !== undefined ? ' · ' + anDelta(k.mttr[0], k.mttr[1], true) : '') : L('geen storingen opgelost'))}
    ${tile(L('Meldingen'), anNum(k.alerts[0]), anDelta(k.alerts[0], k.alerts[1], true), kspark(k.alerts[2]))}
    ${tile(L('IDS (critical)'), anNum(k.security[0]), anDelta(k.security[0], k.security[1], true), kspark(k.security[2]), k.security[0] ? 'bad' : '')}
  </div>`;
  // 🎯 Wat nu aanpakken
  const sevCls = ['muted', 'warn-txt', 'crit-txt'], sevIc = ['ℹ️', '⚠️', '🔴'];
  const todo = anSec('🎯', L('Wat nu aanpakken'), d.todo.length
    ? `<ol class="an-todo">${d.todo.map((t) => `<li><span class="${sevCls[t.sev]}">${sevIc[t.sev]}</span> ${t.icon} ${esc(t.text)} <button type="button" class="link-btn small" data-an-go="${esc(t.go)}">${L('bekijken')} →</button></li>`).join('')}</ol>`
    : `<p class="good-txt">✔ ${L('Niets dringends. Netjes!')}</p>`);
  // 🟢 Betrouwbaarheid
  const r = d.reliability;
  const availCls = (x) => (x.v < 95 ? 'crit' : x.v < 99 ? 'warn' : 'ok');
  const rel = anSec('🟢', L('Betrouwbaarheid'), `<div class="an-grid">
    ${anBox(L('Beschikbaarheid per dag (alle servers)'), anLine(d.avail_series, { unit: '%', d: 2 }))}
    ${anBox(L('Beschikbaarheid per server'), anHbars(r.hosts.map((h) => ({ label: h.name, v: h.pct, title: L('~{m} min onbereikbaar', { m: h.down_min }) + (h.mttr ? ' · ' + L('hersteltijd {t}', { t: anDur(h.mttr.avg) }) : '') })),
      { max: 100, fmt: (v, x) => `${anNum(v, 2)}% <span class="muted">${x.title.split(' · ')[0]}</span>`, cls: availCls }), L('Slechtste bovenaan. Gebaseerd op ping, anders TCP en HTTP.'))}
    ${anBox(L('Wanneer gaat het mis? (storingen per weekdag en uur)'), anHeat(r.heat), L('Een donker vakje dat steeds terugkomt wijst op iets geplands: back-ups, updates of een herstart.'))}
    ${anBox(L('Lastigste checks'), r.top.length ? `<table class="vuln-table an-table"><thead><tr><th>${L('Check')}</th><th>${L('Wissels')}</th><th>${L('Niet OK')}</th><th>${L('Laatste oorzaak')}</th></tr></thead><tbody>
      ${r.top.map((c) => `<tr><td><button type="button" class="link-btn" data-an-go="check:${c.id}">${esc(c.name)}</button><br><span class="muted small">${esc(c.host)}</span></td><td>${anNum(c.chg)}</td><td>${anNum(c.bad_pct, 1)}%</td>
        <td class="small muted">${c.last ? esc(String(c.last.message).slice(0, 90)) : '–'}</td></tr>`).join('')}</tbody></table>` : `<p class="muted small">${L('Geen wisselingen in deze periode.')}</p>`, '', true)}
    ${anBox(L('Responstijden (gemiddeld per dag)'), r.resp.length ? r.resp.map((c) => `<div class="an-resp"><div><b>${esc(c.name)}</b> <span class="muted small">${esc(c.host)} · ${L('gem.')} ${anNum(c.avg, 1)} ms${c.trend !== null ? ` · <span class="${c.trend > 20 ? 'warn-txt' : c.trend < -10 ? 'good-txt' : 'muted'}">${c.trend > 0 ? '▲' : '▼'} ${Math.abs(c.trend)}%</span>` : ''}</span></div>${anLine(c.series, { unit: ' ms', d: 1, h: 46, cls: 'an-mini' })}</div>`).join('') : `<p class="muted small">${L('Geen ping- of HTTP-metingen in deze periode.')}</p>`)}
    ${r.reboots.length ? anBox(L('Herstarts'), anHbars(r.reboots.map((x) => ({ label: x.name, v: +x.n })))) : ''}
  </div>`);
  // 📈 Capaciteit
  const c = d.capacity;
  const cap = anSec('📈', L('Capaciteit'), `<div class="an-grid">
    ${anBox(L('Disks: wanneer vol?'), c.disks.length ? anHbars(c.disks.map((x) => ({ label: `${x.host} ${x.mount}`, v: x.pct, days: x.days })), { max: 100,
      fmt: (v, x) => `${anNum(v, 1)}% <span class="${x.days !== null && x.days <= 30 ? 'crit-txt' : 'muted'}">${x.days !== null ? L('vol over ~{n} d', { n: Math.round(x.days) }) : ''}</span>`, cls: (x) => (x.v >= 90 ? 'crit' : x.v >= 80 ? 'warn' : '') }) : `<p class="muted small">${L('Geen diskchecks.')}</p>`, L('Voorspelling uit de groei van de laatste 7 dagen; de eerstvolgende bovenaan.'))}
    ${anBox(L('CPU en RAM per server'), Object.keys(c.res).length ? `<table class="vuln-table an-table"><thead><tr><th>${L('Server')}</th><th>CPU ${L('gem. / piek')}</th><th>RAM ${L('gem. / piek')}</th></tr></thead><tbody>
      ${Object.entries(c.res).map(([h, x]) => `<tr><td>${esc(h)}</td>${['cpu', 'ram'].map((t) => `<td>${x[t] ? `<span class="${x[t].avg >= 85 ? 'crit-txt' : x[t].avg >= 70 ? 'warn-txt' : ''}">${anNum(x[t].avg)}%</span> / ${anNum(x[t].max)}%` : '–'}</td>`).join('')}</tr>`).join('')}</tbody></table>
      <p class="muted small">${L('Hoog gemiddelde = structureel te krap; alleen een hoge piek = korte drukte.')}</p>` : `<p class="muted small">${L('Geen CPU- of RAM-checks.')}</p>`)}
    ${c.pve.length ? anBox(L('Proxmox: grootste guests (RAM)'), anHbars(c.pve.map((g) => ({ label: `${g.kind === 'ct' ? '📦' : '🖥️'} ${g.vmid} ${g.name}`, v: g.mem / 1073741824, title: g.host })), { fmt: (v, g) => `${anNum(v, 1)} GB <span class="muted">${esc(g.title)}</span>` })) : ''}
    ${anBox(L('Groei'), `<p class="small">${L('{h} servers, {c} checks', { h: c.now.hosts, c: c.now.checks })}</p>${anLine(c.growth.checks, { h: 60 })}`, L('Aantal checks per dag (vanaf nu bijgehouden).'))}
  </div>`);
  // 🛡️ Beveiliging
  const s = d.security;
  const lg = s.logins;
  const sec = anSec('🛡️', L('Beveiliging'), `<div class="an-grid">
    ${anBox(L('Mislukte logins per dag'), anBars(s.series.auth_fail))}
    ${anBox(L('IDS-incidenten per dag (critical)'), anBars(s.series.ids, { cls: 'an-crit' }))}
    ${anBox(L('Nieuwe blokkades per dag'), anBars(s.series.blk) + `<p class="muted small">${L('{t} IP\'s geblokkeerd, waarvan {a} automatisch', { t: anNum(s.blk_total), a: anNum(s.blk_auto) })}</p>`)}
    ${anBox(L('Waar komen aanvallen vandaan?'), anHbars(s.countries.map((x) => ({ html: ccTag(x.cc) || esc(x.cc), v: +x.n }))))}
    ${anBox(L('Meest actieve IP\'s'), s.ips.length ? `<table class="vuln-table an-table"><tbody>${s.ips.map((x) => `<tr><td class="mono small">${esc(x.ip)}${x.cc ? ccTag(x.cc) : ''}</td><td>${anNum(x.hits)}×</td><td class="small">${L('{n} server(s)', { n: x.hosts })}</td><td>${x.blocked ? `<span class="good-txt small">⛔ ${L('geblokkeerd')}</span>` : `<span class="warn-txt small">${L('niet geblokkeerd')}</span>`}</td></tr>`).join('')}</tbody></table>` : `<p class="muted small">${L('Niets in deze periode.')}</p>`, '', true)}
    ${anBox(L('Meest aangevallen servers'), anHbars(s.targets.map((x) => ({ label: x.name, v: x.n }))))}
    ${anBox(L('Logins op je servers'), `<div class="an-stats"><div><b>${anNum(lg.ok)}</b><span>${L('geslaagd')}</span></div><div><b class="${lg.outside ? 'warn-txt' : ''}">${anNum(lg.outside)}</b><span>${L('van buiten')}</span></div>
      <div><b class="${lg.night ? 'warn-txt' : ''}">${anNum(lg.night)}</b><span>${L('\'s nachts (0–6 uur)')}</span></div><div><b class="${lg.fail ? 'crit-txt' : ''}">${anNum(lg.fail)}</b><span>${L('mislukt')}</span></div></div>`)}
    ${s.vulns ? anBox(L('Kwetsbaarheden'), `<div class="an-stats">${[4, 3, 2, 1].map((sv) => { const x = s.vulns.by_sev.find((y) => +y.s === sv) || { n: 0, fix: 0 }; return `<div><b class="${sv >= 4 && +x.n ? 'crit-txt' : sv === 3 && +x.n ? 'warn-txt' : ''}">${anNum(x.n)}</b><span>${['', 'low', 'medium', 'high', 'critical'][sv]} · ${L('{n} op te lossen', { n: x.fix })}</span></div>`; }).join('')}</div>${anLine(s.vulns.series, { h: 60 })}`, L('Open kwetsbaarheden per dag (vanaf nu bijgehouden).')) : ''}
    ${s.hardening.length ? anBox(L('Hardening-score per server'), anHbars(s.hardening.map((x) => ({ label: x.name, v: x.score })), { max: 100, fmt: (v) => `${v}/100`, cls: (x) => (x.v < 70 ? 'crit' : x.v < 90 ? 'warn' : 'ok') })) : ''}
  </div>`);
  // 🌐 Netwerk
  const n = d.network;
  const nk = (k2) => (NET_KIND[k2] || NET_KIND.unknown);
  const net = !n.on ? anSec('🌐', L('Netwerk'), `<p class="muted">${L('De netwerkscan staat uit (Instellingen → Beveiliging → Netwerkscan).')}</p>`)
    : anSec('🌐', L('Netwerk'), `<div class="an-grid">
    ${anBox(L('Apparaten'), `<div class="an-stats"><div><b>${n.total}</b><span>${L('totaal')}</span></div><div><b class="${n.new_open ? 'warn-txt' : ''}">${n.new_open}</b><span>${L('nog niet beoordeeld')}</span></div><div><b>${n.blocked}</b><span>${L('geweerd')}</span></div><div><b class="${n.risky.length ? 'crit-txt' : ''}">${n.risky.length}</b><span>${L('met riskante poort')}</span></div></div>
      <h4 class="acc-sub">${L('Nieuwe apparaten per dag')}</h4>${anBars(n.series.new)}`)}
    ${anBox(L('Soorten apparaten'), anHbars(Object.entries(n.kinds).map(([k2, v]) => ({ html: `${nk(k2)[0]} ${esc(nk(k2)[1]())}`, v }))))}
    ${anBox(L('Fabrikanten'), anHbars(Object.entries(n.vendors).map(([k2, v]) => ({ label: k2, v }))))}
    ${Object.keys(n.aps).length ? anBox(L('Wifi: clients per access point'), anHbars(Object.entries(n.aps).map(([k2, v]) => ({ label: k2, v }))) + `<h4 class="acc-sub">${L('Per wifi-netwerk')}</h4>` + anHbars(Object.entries(n.ssids).map(([k2, v]) => ({ label: k2, v })))) : ''}
    ${n.adguard ? anBox(L('DNS (AdGuard Home)'), `<div class="an-stats"><div><b>${anNum(n.adguard.queries)}</b><span>${L('verzoeken')}</span></div><div><b>${anNum(n.adguard.blocked)}</b><span>${L('geblokkeerd')} (${n.adguard.queries ? anNum(n.adguard.blocked / n.adguard.queries * 100, 1) : 0}%)</span></div></div>
      <h4 class="acc-sub">${L('Meeste verzoeken')}</h4>${anHbars(Object.entries(n.adguard.clients).map(([k2, v]) => ({ label: k2, v })))}
      <h4 class="acc-sub">${L('Meest geblokkeerd')}</h4>${anHbars(Object.entries(n.adguard.blocked_domains).map(([k2, v]) => ({ label: k2, v })))}`, L('Uit de statistiek van AdGuard (zijn eigen periode, meestal 24 uur).')) : ''}
  </div>`);
  // 💾 Back-ups
  const b = d.backups;
  const now = Date.now() / 1000;
  const bk = anSec('💾', L('Back-ups'), `<div class="an-grid">
    ${b.pve.length ? anBox(L('Proxmox: laatste back-up per guest'), anHbars(b.pve.map((g) => ({ label: `${g.kind === 'ct' ? '📦' : '🖥️'} ${g.vmid} ${g.name}`, v: g.last ? (now - g.last) / 86400 : 30, g })), { max: 14,
      fmt: (v, x) => (x.g.last ? `${anNum(v, 1)} ${L('dagen')}` : `<span class="crit-txt">${L('nooit')}</span>`) + (x.g.job === 0 ? ` <span class="warn-txt">${L('geen taak')}</span>` : ''), cls: (x) => (!x.g.last || x.v > 7 ? 'crit' : x.v > 2 ? 'warn' : 'ok') }), L('Langer balkje = ouder. Groen tot 2 dagen, oranje tot 7, daarna rood.')) : ''}
    ${b.tasks.length ? anBox(L('Back-uptaken Proxmox'), b.tasks.map((t) => `<p class="small"><b>${esc(t.host)}</b>: ${t.last ? `${t.last.status === 'OK' ? '<span class="good-txt">✔ OK</span>' : '<span class="crit-txt">✘ ' + esc(t.last.status) + '</span>'} · ${esc(new Date(t.last.start * 1000).toLocaleString(LOCALE))}` : L('nog geen taak')}${t.fail && (!t.last || t.fail.start !== t.last.start) ? `<br><span class="muted">${L('Laatst mislukt: {t} ({s})', { t: esc(new Date(t.fail.start * 1000).toLocaleString(LOCALE)), s: esc(t.fail.status) })}</span>` : ''}</p>`).join('')) : ''}
    ${b.hb.length ? anBox(L('Heartbeats (scripts en back-ups)'), `<table class="vuln-table an-table"><tbody>${b.hb.map((x) => `<tr><td><button type="button" class="link-btn" data-an-go="check:${x.id}">${esc(x.name)}</button><br><span class="muted small">${esc(x.host)}</span></td>
      <td>${+x.status === 0 ? '<span class="good-txt">✔</span>' : +x.status === 1 ? '<span class="warn-txt">⚠️</span>' : +x.status === 2 ? '<span class="crit-txt">✘</span>' : '<span class="muted">?</span>'}</td>
      <td class="small">${x.last ? esc(ago(new Date(x.last * 1000).toISOString())) : '–'}${x.dur ? ` · ${L('duur {d}', { d: anDur(x.dur) })}` : ''}</td></tr>`).join('')}</tbody></table>`) : ''}
    ${anBox(L('RzSense zelf'), `<p class="small">${b.rzmon ? L('Laatste back-up van RzSense: {t}', { t: esc(fmtDateTime(b.rzmon)) }) : `<span class="warn-txt">${L('Nog geen back-up van RzSense zelf.')}</span>`}</p>
      ${b.tasks_rz.length ? `<p class="small warn-txt">${L('Achtergrondtaken met een probleem: {list}', { list: esc(b.tasks_rz.map((t) => t.label).join(', ')) })}</p>` : `<p class="small good-txt">✔ ${L('Alle achtergrondtaken lopen.')}</p>`}`)}
  </div>`);
  // 🔔 Meldingen en ruis
  const no = d.noise;
  const noise = anSec('🔔', L('Meldingen en ruis'), `<div class="an-grid">
    ${anBox(L('Meldingen per dag (storingen en herstel)'), anBars(no.series.notify))}
    ${anBox(L('Per kanaal'), anHbars([['📱 Pushover', no.totals.push], ['✉️ ' + L('E-mail'), no.totals.mail], ['🔗 Webhook', no.totals.hook]].map(([l, v]) => ({ html: l, v })))
      + `<p class="muted small">${L('Daarnaast {i} info-meldingen, {d} IDS en {s} beveiliging.', { i: anNum(no.totals.info), d: anNum(no.totals.ids), s: anNum(no.totals.sec) })}</p>`)}
    ${anBox(L('Meeste meldingen'), anHbars(no.top.map((x) => ({ html: `<button type="button" class="link-btn" data-an-go="check:${x.check_id}">${esc(x.name)}</button> <span class="muted small">${esc(x.host)}</span>`, v: +x.n }))))}
    ${anBox(L('Snel weer goed (ruis)'), no.quick.length ? anHbars(no.quick.map((x) => ({ html: `<button type="button" class="link-btn" data-an-go="check:${x.id}">${esc(x.name)}</button> <span class="muted small">${esc(x.host)}</span>`, v: x.n })), { fmt: (v) => `${v}× ${L('binnen 5 min')}` })
      + `<p class="muted small">${L('Zet bij zulke checks "melden na N fouten" op 3: dan krijg je alleen nog een melding als het echt mis is.')}</p>` : `<p class="good-txt small">✔ ${L('Geen checks die steeds binnen 5 minuten herstelden.')}</p>`)}
  </div>`);
  // 🎯 Doelen (SLO) en "na de actie" (1.146.0)
  const qu = d.quality || null;
  let qual = '';
  if (qu) {
    const s = qu.slo, lp = qu.loops;
    const pctTxt = (v, n = 1) => (v === null || v === undefined ? '–' : dec((+v).toFixed(n)) + '%');
    const okCls = (good) => (good ? 'good-txt' : 'crit-txt');
    qual = anSec('🎯', L('Doelen en "na de actie"'), `<div class="an-grid">
      ${anBox(L('Melding binnen {m} minuten', { m: s.notify.target_min }), s.notify.n
        ? `<p class="an-big ${okCls(s.notify.pct >= 95)}">${pctTxt(s.notify.pct)}</p><p class="small">${L('{w} van {n} storingen (doel 95%) · mediaan {a} · 90% binnen {b}', { w: s.notify.within, n: s.notify.n, a: anDur(s.notify.p50), b: anDur(s.notify.p90) })}</p>`
        : `<p class="muted small">${L('Nog geen storingen met een melding in deze periode.')}</p>`,
        L('Van de overgang naar critical tot de eerste melding. Je meldregels tellen mee: met "melden na 10 minuten" haal je 5 minuten nooit.'))}
      ${anBox(L('Agents rapporteren'), s.agents.agents
        ? `<p class="an-big ${okCls(s.agents.coverage >= 99.5)}">${pctTxt(s.agents.coverage, 2)}</p><p class="small">${L('{g} keer stil, samen {t} (doel 99,5%)', { g: s.agents.gaps, t: s.agents.silent_s ? anDur(s.agents.silent_s) : '0 min' })}</p>`
          + (s.agents.hosts.length ? anHbars(s.agents.hosts.map((x) => ({ label: x.host, v: x.secs / 60 })), { fmt: (v) => anDur(v * 60) }) : '')
        : `<p class="muted small">${L('Geen servers met een agent.')}</p>`, L('Vanaf 1.146.0 bijgehouden: elke keer dat een agent langer stil was dan de grens voor "agent stil".'))}
      ${anBox(L('Zelf herstellen'), lp.heal.n
        ? `<p class="an-big ${okCls(lp.heal.pct >= 80)}">${pctTxt(lp.heal.pct)}</p><p class="small">${L('{ok} van {n} keer gelukt (laatste {d} dagen)', { ok: lp.heal.ok, n: lp.heal.n, d: lp.days })}</p>`
          + anHbars(lp.heal.checks.map((x) => ({ html: `<button type="button" class="link-btn" data-an-go="check:${x.id}">${esc(x.name)}</button> <span class="muted small">${esc(x.host)}</span>`, v: x.n, x })),
            { fmt: (v, r) => L('{ok}/{n} gelukt', { ok: r.x.ok, n: v }), cls: (r) => (r.x.ok < r.x.n ? 'warn' : 'ok') })
        : `<p class="muted small">${L('Nog geen herstelacties (of nog niet bijgehouden: vanaf 1.146.0).')}</p>`)}
      ${anBox(L('Automatische blokkades'), lp.blocks.n
        ? `<p class="an-big ${okCls(lp.blocks.pct <= 5)}">${pctTxt(lp.blocks.pct)}</p><p class="small">${L('{e} van {n} vroegtijdig opgeheven (waarschijnlijk onterecht)', { e: lp.blocks.early, n: lp.blocks.n })}</p>`
          + (lp.blocks.list.length ? `<ul class="small">${lp.blocks.list.map((b) => `<li><code>${esc(b.ip)}</code> · ${esc(b.host)} · ${esc(b.reason)} <span class="muted">(${esc(b.removed_by || '?')})</span></li>`).join('')}</ul>` : '')
        : `<p class="muted small">${L('Geen automatische blokkades in deze periode.')}</p>`, L('Vroegtijdig opgeheven door iemand of via de whitelist. Komt een IP vaak terug in deze lijst, zet hem dan op de whitelist of pas de regel aan.'))}
      ${anBox(L('Slimme drempels met méér ruis'), lp.smart.length
        ? anHbars(lp.smart.map((x) => ({ html: `<button type="button" class="link-btn" data-an-go="check:${x.id}">${esc(x.name)}</button> <span class="muted small">${esc(x.host)}</span>`, v: x.after, x })),
            { fmt: (v, r) => L('{a} → {b} warnings ({d} dagen)', { a: r.x.before, b: v, d: r.x.days }), cls: () => 'warn' })
          + `<p class="muted small">${L('Na het aanzetten van slimme drempels méér warnings dan ervoor: zet de gevoeligheid lager of de check terug op vaste drempels.')}</p>`
        : `<p class="good-txt small">✔ ${L('Geen checks die met slimme drempels meer ruis gaven.')}</p>`)}
    </div>`);
  }
  void todo;   // 1.155.0: "Wat nu aanpakken" is vervangen door de adviezen
  $('#an-body').innerHTML = iaRender({ kpis, qual, rel, cap, sec, net, bk, noise });
}

// ---------------------------------------------------------------- 1.224.0: Trends (Analyse → Trends)
// Langzame veranderingen over 90 dagen: een robuuste lijn (Theil-Sen) door de dagwaarden, alleen "beweegt" als de
// verandering groot genoeg en consequent is, en een verwachting wanneer een grens geraakt wordt.
let tnData = null, tnFilter = 'moving';
const TN_GRP = { hw: ['🔋', () => L('Hardware veroudert')], slow: ['🐢', () => L('Langzaam trager')], cap: ['📦', () => L('Capaciteit')],
  rel: ['🟢', () => L('Betrouwbaarheid')], sec: ['🛡️', () => L('Beveiliging en onderhoud')] };

let tnBusy = false;
async function tnLoad() {
  if (tnBusy) return;
  tnBusy = true;
  try { tnData = await api('trends'); } catch (ex) { tnData = { error: ex.message }; }
  tnBusy = false;
  if (iaTab === 'trends') renderAnalytics();
}

const tnFmt = (v, x) => (v === null || v === undefined ? '–' : x.bytes ? fmtBytes(v) : anNum(v, x.d || 0) + (x.unit || ''));
const tnDate = (d) => new Date(d + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'long', year: 'numeric' });

/** Pijl en percentage; rood als de richting slecht is, groen als goed, grijs als het niet beweegt */
function tnChip(x) {
  if (!x.sig) return `<span class="tn-chg flat">→ ${L('stabiel')}</span>`;
  const up = x.chg > 0, cls = x.bad ? 'bad' : 'good';
  const val = `${up ? '+' : '−'}${x.chg_abs === null || x.chg_abs === undefined ? anNum(Math.abs(x.chg)) + '%' : x.unit === '%' ? anNum(Math.abs(x.chg_abs), 1) + ' ' + L('pt') : tnFmt(Math.abs(x.chg_abs), { ...x, d: Math.max(x.d || 0, Math.abs(x.chg_abs) < 10 ? 1 : 0) })}`;
  return `<span class="tn-chg ${cls}" title="${esc(L('verandering over {n} dagen volgens de trendlijn', { n: x.per || 30 }))}">${up ? '↗' : '↘'} ${val}<small>/${x.per === 7 ? L('week') : L('maand')}</small></span>`;
}

/** Lijn per dag (of staafjes per week), de trendlijn en de verwachting tot de grens */
function tnSpark(x, H = 74) {
  const ks = Object.keys(x.series || {});
  if (ks.length < 2) return `<p class="muted small">${L('Nog te weinig gegevens.')}</p>`;
  const vals = ks.map((k) => x.series[k]);
  const n = ks.length, ahead = x.sig && x.fit ? Math.min(x.ahead || 0, Math.round(n * 0.5)) : 0, tot = n - 1 + ahead;
  const fitAt = (i) => (x.fit ? x.fit[1] + x.fit[0] * i : null);
  const nums = vals.filter((v) => v !== null);
  let lo = Math.min(...nums), hi = Math.max(...nums);
  if (ahead) { lo = Math.min(lo, fitAt(tot)); hi = Math.max(hi, fitAt(tot)); }
  if (x.limit !== null && x.limit !== undefined && x.eta_days !== null && x.eta_days !== undefined && x.eta_days <= ahead) { lo = Math.min(lo, x.limit); hi = Math.max(hi, x.limit); }
  if (x.bars) lo = Math.min(0, lo);
  if (hi === lo) { hi += 1; lo -= 1; }
  const pad = (hi - lo) * 0.08;
  hi += pad; if (!x.bars) lo -= pad;
  const W = 300, top = 4, bot = H - 4;
  const X = (i) => (i / tot) * W, Y = (v) => bot - ((v - lo) / (hi - lo)) * (bot - top);
  let body = '';
  if (x.bars) {
    const bw = W / (tot + 1);
    body = vals.map((v, i) => (v === null ? '' : `<rect class="tn-bar" x="${(X(i) - bw / 2 + 1).toFixed(1)}" y="${Y(v).toFixed(1)}" width="${Math.max(1, bw - 2).toFixed(1)}" height="${Math.max(1, bot - Y(v)).toFixed(1)}" rx="1.5"><title>${esc(L('week van {d}', { d: anDay(ks[i]) }))}: ${esc(tnFmt(v, x))}</title></rect>`)).join('');
  } else {
    let p = '';
    vals.forEach((v, i) => { if (v === null) return; p += `${p && vals[i - 1] !== null ? 'L' : 'M'}${X(i).toFixed(1)},${Y(v).toFixed(1)}`; });
    const hits = ks.map((k, i) => (vals[i] === null ? '' : `<rect x="${(X(i) - W / tot / 2).toFixed(1)}" y="0" width="${(W / tot).toFixed(1)}" height="${H}" fill="transparent"><title>${esc(anDay(k))}: ${esc(tnFmt(vals[i], x))}</title></rect>`)).join('');
    body = `<path class="tn-ln" d="${p}" vector-effect="non-scaling-stroke"/>${hits}`;
  }
  let fit = '';
  if (x.fit && x.sig) {
    fit = `<line class="tn-fit ${x.bad ? 'bad' : 'good'}" x1="0" y1="${Y(fitAt(0)).toFixed(1)}" x2="${X(n - 1).toFixed(1)}" y2="${Y(fitAt(n - 1)).toFixed(1)}" vector-effect="non-scaling-stroke"/>`;
    if (ahead) fit += `<rect class="tn-ahead" x="${X(n - 1).toFixed(1)}" y="0" width="${(W - X(n - 1)).toFixed(1)}" height="${H}"/><line class="tn-fit ${x.bad ? 'bad' : 'good'} dash" x1="${X(n - 1).toFixed(1)}" y1="${Y(fitAt(n - 1)).toFixed(1)}" x2="${W}" y2="${Y(fitAt(tot)).toFixed(1)}" vector-effect="non-scaling-stroke"/>`;
  }
  const lim = x.limit !== null && x.limit !== undefined && x.limit >= lo && x.limit <= hi
    ? `<line class="tn-lim" x1="0" x2="${W}" y1="${Y(x.limit).toFixed(1)}" y2="${Y(x.limit).toFixed(1)}" vector-effect="non-scaling-stroke"/>` : '';
  const limLbl = lim ? `<span class="tn-liml" style="top:${(Y(x.limit) / H * 100).toFixed(1)}%">${esc(x.limit_lbl || L('grens'))} ${esc(tnFmt(x.limit, x))}</span>` : '';
  return `<div class="tn-spark"><svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="${esc(x.title)}">${lim}${fit}${body}</svg>${limLbl}
    <div class="tn-xl"><span>${esc(anDay(ks[0]))}</span>${ahead ? `<span class="tn-xa">${esc(L('verwachting'))} →</span>` : `<span>${esc(L('vandaag'))}</span>`}</div></div>`;
}

function tnCard(x) {
  const cls = !x.sig ? 'flat' : x.bad ? 'bad' : 'good';
  const eta = x.eta && x.sig && x.bad
    ? `<span class="tn-eta ${x.eta_days <= 30 ? 'crit' : x.eta_days <= 90 ? 'warn' : ''}">⏳ ${esc(x.eta_lbl || L('grens'))}: ${esc(tnDate(x.eta))} <small>(${esc(L('over ~{n} dagen', { n: x.eta_days }))})</small></span>` : '';
  return `<article class="tn-card ${cls}${tnData.moving && tnData.moving.includes(x.id) ? ' top' : ''}">
    <header class="tn-h"><span class="tn-ic">${x.icon}</span><div class="tn-t"><b>${esc(x.title)}</b><span>${esc(x.sub || '')}</span></div>${tnChip(x)}</header>
    <div class="tn-now"><b>${esc(tnFmt(x.now, x))}</b> <span class="muted small">${x.was !== null && x.was !== undefined ? esc(L('was {v} op {d}', { v: tnFmt(x.was, x), d: anDay(Object.keys(x.series)[0]) })) : ''}</span></div>
    ${tnSpark(x)}
    <p class="tn-txt">${esc(x.text)}</p>
    <footer class="tn-f">${eta}${x.go ? `<button type="button" class="link-btn small" data-an-go="${esc(x.go)}">${L('bekijken')} →</button>` : ''}</footer>
  </article>`;
}

function tnRender() {
  const d = tnData;
  if (!d) return `<p class="muted">⏳ ${L('Trends laden…')}</p>`;
  if (d.error) return `<p class="form-error">${esc(d.error)}</p>`;
  const items = d.items || [];
  if (!items.length) return `<p class="muted">${esc(L('Nog geen trends: RzSense heeft minstens 14 dagen aan dagwaarden nodig. Die worden vanzelf elke dag bewaard.'))}</p>`;
  const mov = items.filter((x) => x.sig), bad = mov.filter((x) => x.bad), good = mov.filter((x) => !x.bad), flat = items.filter((x) => !x.sig);
  const top = (d.moving || []).map((id) => items.find((x) => x.id === id)).filter(Boolean);
  const head = `<div class="tn-top">
    <div class="tn-sum"><div class="tn-k bad"><b>${bad.length}</b><span>${esc(L('gaan de verkeerde kant op'))}</span></div>
      <div class="tn-k good"><b>${good.length}</b><span>${esc(L('verbeteren'))}</span></div>
      <div class="tn-k"><b>${flat.length}</b><span>${esc(L('stabiel'))}</span></div></div>
    <div class="tn-moves"><h3>${esc(L('Wat beweegt er'))}</h3>${top.length ? `<ol>${top.map((x) => `<li><span class="tn-ic">${x.icon}</span><span><b>${esc(x.title)}</b> <span class="muted small">${esc(x.sub || '')}</span><br><span class="small">${esc(x.short || x.text)}</span></span>${tnChip(x)}</li>`).join('')}</ol>`
      : `<p class="good-txt small">✔ ${esc(L('Niets dat langzaam de verkeerde kant op gaat. Alles stabiel.'))}</p>`}</div></div>`;
  const chips = [['moving', L('Wat beweegt ({n})', { n: mov.length })], ['all', L('Alles ({n})', { n: items.length })],
    ...Object.keys(TN_GRP).filter((g) => items.some((x) => x.grp === g)).map((g) => [g, `${TN_GRP[g][0]} ${TN_GRP[g][1]()}`])];
  const bar = `<div class="dash-chips tn-chips" role="group">${chips.map(([k, l]) => `<button type="button" class="dchip ${tnFilter === k ? 'on' : ''}" data-tr-f="${k}">${esc(l)}</button>`).join('')}</div>`;
  const groups = Object.keys(TN_GRP).map((g) => {
    if (!['moving', 'all', g].includes(tnFilter)) return '';
    const list = items.filter((x) => x.grp === g), show = tnFilter === 'moving' ? list.filter((x) => x.sig) : list, rest = list.filter((x) => !x.sig);
    if (!list.length) return '';
    show.sort((a, b) => (b.sig - a.sig) || (b.bad - a.bad) || ((a.eta_days ?? 1e9) - (b.eta_days ?? 1e9)) || (Math.abs(b.chg) - Math.abs(a.chg)));
    return `<section class="tn-grp"><h3>${TN_GRP[g][0]} ${esc(TN_GRP[g][1]())}</h3>
      ${show.length ? `<div class="tn-grid">${show.map(tnCard).join('')}</div>` : ''}
      ${tnFilter === 'moving' && rest.length ? `<p class="tn-flat"><span class="muted small">→ ${esc(L('stabiel'))}:</span> ${rest.slice(0, 12).map((x) => `<span class="tn-pill" title="${esc(x.text)}">${x.icon} ${esc(x.title)}${x.sub ? ` <span class="muted">${esc(x.sub)}</span>` : ''}</span>`).join('')}${rest.length > 12 ? ` <button type="button" class="link-btn small" data-tr-f="${g}">${esc(L('+{n} meer', { n: rest.length - 12 }))}</button>` : ''}</p>` : ''}
    </section>`;
  }).join('');
  return `${head}${bar}${groups}<p class="muted small tn-foot">${esc(L('Uit de dagwaarden van de laatste {n} dagen. Dag- en weekritme (zoals nachtelijke back-ups) tellen niet mee: RzSense gebruikt een robuuste lijn (Theil-Sen) en noemt iets pas een trend als de verandering groot genoeg én consequent is. Bijgewerkt: {t}.', { n: d.days, t: fmtDateTime(d.at) }))}</p>`;
}

// ---------------------------------------------------------------- Netwerk (1.103.0)
const NET_KIND = {
  router: ['🌐', () => L('Router')], network: ['🔀', () => L('Netwerkapparaat')], server: ['🖥️', () => L('Server')], nas: ['🗄️', () => 'NAS'],
  computer: ['💻', () => L('Computer')], phone: ['📱', () => L('Telefoon of tablet')], media: ['📺', () => L('Tv of media')], printer: ['🖨️', () => L('Printer')],
  camera: ['📷', () => L('Camera')], iot: ['💡', () => L('Slim apparaat (IoT)')], smarthome: ['🏠', () => L('Smart home')], unknown: ['❔', () => L('Onbekend')],
};
/** Soort in RzSense (hostForm) bij de soort uit de netwerkscan */
const NET_TO_HOST = { router: 'router', network: 'switch', server: 'server', nas: 'nas', printer: 'printer', camera: 'camera', smarthome: 'smarthome', iot: 'smarthome' };
let netData = null;
let netFilter = 'all';
let netSortTr = false;   // 1.186.0: Apparaten sorteren op verkeer
const netKind = (d) => d.kind_user || d.kind || 'unknown';

function initNet() {
  $('#net-tabs').onclick = (e) => { const b = e.target.closest('[data-nsub]'); if (b) setNetSub(b.dataset.nsub); };
  $('#net-group').value = (() => { try { return localStorage.getItem('rzmon-net-group') || ''; } catch (ex) { return ''; } })();
  $('#net-group').onchange = (e) => { try { localStorage.setItem('rzmon-net-group', e.target.value); } catch (ex) { /* niet erg */ } renderNet(); };
  $('#net-disc').onclick = ndClick;
  $('#net-map').onclick = nmClick;
  setNetSub(netSub);
  $('#net-search').oninput = () => renderNet();
  $('#net-filter').onclick = (e) => { const b = e.target.closest('[data-nf]'); if (b) { netFilter = b.dataset.nf; renderNet(); } };
  $('#net-counts').onclick = (e) => { const b = e.target.closest('[data-nf]'); if (b) { netFilter = netFilter === b.dataset.nf ? 'all' : b.dataset.nf; renderNet(); } };
  $('#net-scan').onclick = (e) => netScanNow(e.currentTarget);
  $('#net-banner').onclick = (e) => { if (e.target.closest('[data-net-scan]')) netScanNow(e.target.closest('[data-net-scan]')); };
  $('#net-approve-all').onclick = async () => {
    const n = (netData?.devices || []).filter((d) => d.status === 'new').length;
    if (!n || !confirm(L('Alle {n} nieuwe apparaten als bekend markeren?', { n }))) return;
    try { await api('net_approve_all', {}); toast(L('{n} apparaten bekend', { n })); loadNet(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#net-list').onclick = (e) => { if (e.target.closest('[data-net-sort]')) { netSortTr = !netSortTr; renderNet(); return; } netClick(e); };
}

async function loadNet() {
  try { netData = await api('net_devices'); renderNet(); } catch (ex) { $('#net-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
  if (isAdmin()) jobAttach('netscan', $('#net-scan'), NET_JOB());   // 1.252.0: loopt er (nog) een scan? Dan de voortgang weer tonen
  if (netSub === 'disc') ndLoad();
}

// ---------------------------------------------------------------- 1.183.0: Netwerk → Ontdekken
let netSub = (() => { try { return localStorage.getItem('rzmon-net-sub') || 'map'; } catch (ex) { return 'map'; } })();   // 1.184.0: standaard het overzicht
let nmKind = '';
let ndData = null;
let ndFilter = '';
let ndPresKind = '';
function setNetSub(s) {
  netSub = ['disc', 'map'].includes(s) ? s : 'list';
  try { localStorage.setItem('rzmon-net-sub', netSub); } catch (ex) { /* niet erg */ }
  $('#tab-net').classList.toggle('net-dc', netSub === 'disc');
  $('#tab-net').classList.toggle('net-mp', netSub === 'map');
  if (netSub === 'map') renderNetMap();
  $$('#net-tabs [data-nsub]').forEach((b) => { const on = b.dataset.nsub === netSub; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
  if (netSub === 'disc') withPageWait('net', ndLoad);
  crumbs();   // 1.199.0
}
async function ndLoad() {
  if (!ndData) $('#net-disc').innerHTML = `<p class="muted">${L('laden…')}</p>`;
  try {
    const [d] = await Promise.all([api('net_discover'), netData ? null : api('net_devices').then((x) => { netData = x; })]);
    ndData = d;
    renderNetDisc();
  } catch (ex) { $('#net-disc').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}
const ND_EV = () => ({
  new: ['🆕', L('Nieuw apparaat')], ip: ['🔁', L('Ander IP-adres')], ports_new: ['🚪', L('Nieuwe poort open')], ports_gone: ['🔒', L('Poort dicht')],
  domains: ['🌐', L('Praat met nieuwe domeinen')], gone: ['💤', L('Al dagen weg')], back: ['👋', L('Weer terug')],
  traffic: ['📈', L('Ongewoon veel verkeer')], traffic_night: ['🌙', L('Verkeer \'s nachts')],
});
function ndEvText(e, dev) {
  const x = e.detail || {};
  switch (e.kind) {
    case 'new': return L('verscheen op je netwerk als {ip}', { ip: x.ip || dev?.ip || '?' });
    case 'ip': return L('{from} → {to}', { from: x.from || '?', to: x.to || '?' });
    case 'ports_new': return L('poort {p} open', { p: (x.ports || []).join(', ') }) + ((x.risky || []).length ? ' · ⚠️ ' + L('riskant') : '');
    case 'ports_gone': return L('poort {p} niet meer open', { p: (x.ports || []).join(', ') });
    case 'domains': return (x.domains || []).join(', ') + (x.n > (x.domains || []).length ? ' ' + L('en nog {n}', { n: x.n - x.domains.length }) : '');
    case 'gone': return L('niet meer gezien sinds {t}', { t: x.last ? fmtDay(x.last) : '?' });
    case 'back': return L('weer gezien na {d} dagen', { d: x.days ?? '?' });
    case 'traffic': return L('{dir} {b} op {day} (normaal {n})', { dir: x.dir === 'up' ? '↑ ' + L('upload') : '↓ ' + L('download'), b: fmtBytes(x.bytes), day: fmtDay(x.day), n: fmtBytes(x.normal) });
    case 'traffic_night': return L('{b} tussen 0 en 6 uur op {day}, terwijl het \'s nachts normaal stil is', { b: fmtBytes(x.bytes), day: fmtDay(x.day) });
    default: return '';
  }
}
function renderNetDisc() {
  const d = ndData, nd = netData;
  if (!d || !nd) return;
  const box = $('#net-disc');
  if (!d.enabled) { box.innerHTML = `<div class="v-banner">ℹ️ <span>${L('De netwerkscan staat uit.')} ${isAdmin() ? L('Zet hem aan bij <a href="#settings/netscan" data-goto="settings/netscan">Instellingen → Beveiliging → Netwerkscan</a>.') : L('Een beheerder kan hem aanzetten.')}</span></div>`; return; }
  const byId = Object.fromEntries(nd.devices.map((x) => [x.id, x]));
  const ev = ND_EV();
  const week = Date.now() - 7 * 86400e3;
  const ts = (s) => new Date(String(s).replace(' ', 'T')).getTime();
  const ev7 = d.events.filter((e) => ts(e.at) > week);
  const newDevs = nd.devices.filter((x) => x.status === 'new').sort((a, b) => ts(b.first_seen) - ts(a.first_seen));
  const hourAgo = Date.now() - 3600e3;
  const onlineNow = nd.devices.filter((x) => x.status !== 'ignored' && ts(x.last_seen) > hourAgo - 15 * 60e3).length;
  const devLink = (id, label) => `<button type="button" class="nd-link" data-nd-open="${id}">${esc(label)}</button>`;
  const icon = (x) => (NET_KIND[netKind(x)] || NET_KIND.unknown)[0];
  // Kerncijfers
  const kpis = `<div class="kpis nd-kpis">
    <div class="kpi ${newDevs.length ? 'warn' : ''}"><div class="kpi-lbl">🆕 ${L('Nieuw en onbekend')}</div><div class="kpi-val">${newDevs.length}</div><div class="kpi-sub">${L('{n} deze week verschenen', { n: ev7.filter((e) => e.kind === 'new').length })}</div></div>
    <div class="kpi"><div class="kpi-lbl">🔄 ${L('Veranderingen')}</div><div class="kpi-val">${ev7.filter((e) => e.kind !== 'new').length}</div><div class="kpi-sub">${L('laatste 7 dagen')}</div></div>
    <div class="kpi"><div class="kpi-lbl">💡 ${L('Voorstellen')}</div><div class="kpi-val">${d.suggestions.length}</div><div class="kpi-sub">${L('nog niet in RzSense')}</div></div>
    <div class="kpi"><div class="kpi-lbl">🟢 ${L('Nu online')}</div><div class="kpi-val">${onlineNow}<small>/${nd.devices.filter((x) => x.status !== 'ignored').length}</small></div><div class="kpi-sub">${L('het laatste uur gezien')}</div></div>
  </div>`;
  // Nieuw en onbekend, met nieuwe apparaten per dag
  const days = [];
  for (let i = 29; i >= 0; i--) { const t = new Date(Date.now() - i * 86400e3); days.push(t.toISOString().slice(0, 10)); }
  const max = Math.max(1, ...days.map((k) => d.new_per_day[k] || 0));
  const spark = `<div class="nd-bars" role="img" aria-label="${esc(L('Nieuwe apparaten per dag, laatste 30 dagen'))}">${days.map((k) => { const n = d.new_per_day[k] || 0;
    return `<span class="nd-bar ${n ? '' : 'z'}" style="--h:${Math.round((n / max) * 100)}%" title="${esc(fmtDay(k) + ': ' + n)}"></span>`; }).join('')}</div>
    <div class="nd-bars-ax muted small"><span>${esc(fmtDay(days[0]))}</span><span>${L('vandaag')}</span></div>`;
  const newHtml = `<section class="card nd-sec"><h3>🆕 ${L('Nieuw en onbekend')} <span class="muted small">${L('apparaten die je nog niet beoordeelde')}</span></h3>
    ${spark}
    ${newDevs.length ? `<div class="nd-list">${newDevs.slice(0, 30).map((x) => `<div class="nd-row">
        <span class="nd-ic">${icon(x)}</span>
        <div class="nd-t">${devLink(x.id, x.label)} <span class="muted small mono">${esc(x.ip)}</span><small>${esc([x.vendor, x.info.unifi?.essid ? '📶 ' + x.info.unifi.essid : ''].filter(Boolean).join(' · ') || L('onbekende fabrikant'))} · ${L('verschenen {t}', { t: ago(x.first_seen) })}</small></div>
        <div class="nd-acts need-admin"><button type="button" class="btn small-btn" data-nd-st="known:${x.id}">✔ ${L('Bekend')}</button><button type="button" class="icon-btn" data-nd-st="ignored:${x.id}" title="${esc(L('Negeren: niet meer tonen'))}">🙈</button></div>
      </div>`).join('')}${newDevs.length > 30 ? `<p class="muted small">${L('en nog {n} op Apparaten', { n: newDevs.length - 30 })}</p>` : ''}</div>`
      : `<p class="good-txt small">✔ ${L('Alles op je netwerk is beoordeeld.')}</p>`}</section>`;
  // Veranderingen
  const kinds = Object.keys(ev);
  const evList = d.events.filter((e) => !ndFilter || e.kind === ndFilter);
  const chHtml = `<section class="card nd-sec"><h3>🔄 ${L('Veranderingen')} <span class="muted small">${L('laatste 30 dagen')}</span></h3>
    <div class="dash-chips nd-chips"><button type="button" class="dchip ${ndFilter ? '' : 'on'}" data-nd-f="">${L('Alles')} <b>${d.events.length}</b></button>
      ${kinds.map((k) => { const n = d.events.filter((e) => e.kind === k).length; return n ? `<button type="button" class="dchip ${ndFilter === k ? 'on' : ''}" data-nd-f="${k}">${ev[k][0]} ${esc(ev[k][1])} <b>${n}</b></button>` : ''; }).join('')}</div>
    ${evList.length ? `<div class="nd-list">${evList.slice(0, 80).map((e) => { const x = byId[e.device]; const [ic, w] = ev[e.kind] || ['•', e.kind];
      const bad = (e.kind === 'ports_new' && (e.detail.risky || []).length) || e.kind === 'domains' || e.kind === 'traffic' || e.kind === 'traffic_night';
      return `<div class="nd-row ${bad ? 'nd-bad' : ''}"><span class="nd-ic">${ic}</span>
        <div class="nd-t">${x ? devLink(x.id, x.label) : `<span class="muted">${L('(vergeten apparaat)')}</span>`} <span class="muted small">· ${esc(w)}</span><small>${esc(ndEvText(e, x))}</small></div>
        <span class="muted small nowrap" title="${esc(e.at)}">${esc(ago(e.at))}</span></div>`; }).join('')}</div>`
      : `<p class="muted small">${L('Nog niets veranderd. RzSense houdt dit bij vanaf 1.183.0: nieuwe apparaten, een ander IP-adres, nieuwe of gesloten poorten, apparaten die al {g} dagen weg zijn{dom}.', { g: d.gone_days, dom: d.adguard ? L(' en nieuwe domeinen (via AdGuard, na {n} dagen leren)', { n: d.learn_days }) : '' })}</p>`}</section>`;
  // Voorstellen
  const sugHtml = `<section class="card nd-sec"><h3>💡 ${L('Voorstellen')} <span class="muted small">${L('apparaten die je waarschijnlijk wilt bewaken')}</span></h3>
    ${d.suggestions.length ? `<div class="nd-sug">${d.suggestions.slice(0, 24).map((s, i) => `<div class="nd-sc" data-nd-sug="${s.id}">
        <div class="nd-sh"><span class="nd-ic">${(NET_KIND[s.kind] || NET_KIND.unknown)[0]}</span><div>${devLink(s.id, s.label)}<small class="muted mono">${esc(s.ip)} · ${esc((NET_KIND[s.kind] || NET_KIND.unknown)[1]())}</small></div></div>
        <div class="nd-checks">${s.checks.map((c, j) => `<label class="nd-ck"><input type="checkbox" value="${esc(c.key)}" ${j < 3 ? 'checked' : ''} ${isAdmin() ? '' : 'disabled'}> ${esc(c.label)}${c.target && c.type !== 'tcp' ? '' : c.type === 'tcp' ? ` <span class="muted mono">:${esc(c.target)}</span>` : ''}</label>`).join('')}</div>
        ${s.snmp ? `<p class="muted small">📡 ${L('Ondersteunt dit apparaat SNMP? Zet dat daarna aan bij de server voor poorten, temperatuur en meer.')}</p>` : ''}
        <div class="nd-acts need-admin"><button type="button" class="btn small-btn primary" data-nd-adopt="${s.id}">＋ ${L('In RzSense met deze checks')}</button>
          <button type="button" class="btn small-btn" data-nd-form="${s.id}">✎ ${L('Zelf instellen')}</button><button type="button" class="icon-btn" data-nd-st="ignored:${s.id}" title="${esc(L('Negeren: niet meer voorstellen'))}">🙈</button></div>
      </div>`).join('')}</div>` : `<p class="good-txt small">✔ ${L('Geen voorstellen: wat je wilt bewaken, staat al in RzSense.')}</p>`}</section>`;
  // Aanwezigheid
  const pdays = [];
  for (let i = 6; i >= 0; i--) pdays.push(new Date(Date.now() - i * 86400e3).toISOString().slice(0, 10));
  const pres = nd.devices.filter((x) => x.status !== 'ignored' && d.presence[x.id] && (!ndPresKind || netKind(x) === ndPresKind))
    .map((x) => { const p = d.presence[x.id]; let on = 0, night = 0;
      pdays.forEach((k) => { const h = p[k] || 0; for (let b = 0; b < 24; b++) if (h & (1 << b)) { on++; if (b < 6) night++; } });
      return { x, p, on, night }; }).sort((a, b) => b.on - a.on);
  const presKinds = [...new Set(nd.devices.filter((x) => d.presence[x.id]).map(netKind))];
  const trG = d.traffic || {};
  const hasTr = Object.keys(trG).length > 0;
  const trMax = {};
  Object.entries(trG).forEach(([id, h]) => { trMax[id] = Math.max(1, ...Object.values(h)); });
  const presHtml = `<section class="card nd-sec"><h3>🕒 ${hasTr ? L('Aanwezigheid en verkeer') : L('Aanwezigheid')} <span class="muted small">${hasTr ? L('wanneer een apparaat online was en hoe druk, laatste 7 dagen') : L('wanneer een apparaat online was, laatste 7 dagen')}</span></h3>
    <div class="nd-pbar"><select id="nd-pkind" aria-label="${esc(L('Soort'))}"><option value="">${L('Alle soorten')}</option>${presKinds.map((k) => `<option value="${k}" ${ndPresKind === k ? 'selected' : ''}>${(NET_KIND[k] || NET_KIND.unknown)[0]} ${esc((NET_KIND[k] || NET_KIND.unknown)[1]())}</option>`).join('')}</select>
      <span class="muted small">${L('Elk blokje is een uur; 🌙 = ook \'s nachts (0–6 uur) online.')}${hasTr ? ' ' + L('Hoe donkerder, hoe meer verkeer in dat uur.') : ''}</span></div>
    ${pres.length ? `<div class="nd-pres"><div class="nd-prow nd-phead"><span></span>${pdays.map((k) => `<span class="nd-pday">${esc(new Date(k + 'T12:00').toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric' }))}</span>`).join('')}<span></span></div>
      ${pres.slice(0, 60).map(({ x, p, on, night }) => `<div class="nd-prow"><span class="nd-pname">${icon(x)} ${devLink(x.id, x.label)}</span>
        ${pdays.map((k) => { const h = p[k] || 0; const tg = trG[x.id] || {};
          return `<span class="nd-pday">${Array.from({ length: 24 }, (_, b) => { const tb = tg[`${k} ${String(b).padStart(2, '0')}`] || 0;
            const lv = tb ? Math.min(4, 1 + Math.floor(Math.sqrt(tb / trMax[x.id]) * 3.999)) : 0;
            return `<i class="${h & (1 << b) || tb ? 'on' : ''} ${b < 6 ? 'n' : ''} ${lv ? 't' + lv : ''}" ${tb ? `title="${esc(k + ' ' + b + ':00 · ' + fmtBytes(tb))}"` : ''}></i>`; }).join('')}</span>`; }).join('')}
        <span class="nd-ppct small">${Math.round((on / 168) * 100)}%${night ? ' 🌙' : ''}</span></div>`).join('')}</div>`
      : `<p class="muted small">${L('Nog geen gegevens: vanaf 1.183.0 houdt elke scan bij welke apparaten er zijn. Na een paar uur zie je hier de eerste blokjes.')}</p>`}</section>`;
  box.innerHTML = kpis + `<div class="nd-grid">${newHtml}${chHtml}</div>` + sugHtml + presHtml;
  $('#nd-pkind')?.addEventListener('change', (e) => { ndPresKind = e.target.value; renderNetDisc(); });
  const badge = $('#net-disc-badge');
  if (badge) { const n = newDevs.length; badge.hidden = !n; badge.textContent = n; }
}
async function ndClick(e) {
  const f = e.target.closest('[data-nd-f]');
  if (f) { ndFilter = f.dataset.ndF; return renderNetDisc(); }
  const find = (id) => netData.devices.find((x) => +x.id === +id);
  const op = e.target.closest('[data-nd-open]');
  if (op) return netDialog(find(op.dataset.ndOpen));
  const fm = e.target.closest('[data-nd-form]');
  if (fm) return netAddHost(find(fm.dataset.ndForm));
  const st = e.target.closest('[data-nd-st]');
  if (st) {
    const [status, id] = st.dataset.ndSt.split(':');
    try { await api('net_device_save', { id: +id, status }); await loadNet(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
    return;
  }
  const ad = e.target.closest('[data-nd-adopt]');
  if (ad) {
    const card = ad.closest('[data-nd-sug]');
    const checks = $$('input[type=checkbox]:checked', card).map((c) => c.value);
    const x = find(ad.dataset.ndAdopt);
    if (!checks.length) return toast(L('Kies minstens één check'), 'err');
    ad.disabled = true;
    try {
      await api('net_adopt', { id: +ad.dataset.ndAdopt, checks, name: x?.name || x?.label || '' });
      toast(L('{name} staat nu in RzSense met {n} checks', { name: x?.label || '', n: checks.length }));
      await loadNet(); refresh();
    } catch (ex) { toast(ex.message, 'err'); ad.disabled = false; }
  }
}

/** 1.252.0: de knop wordt ⏹ Stoppen met de voortgang; de stappen staan eronder. Loopt door als je weggaat. */
const NET_JOB = () => ({ details: $('#net-scan-steps'), onEnd: async () => { await loadNet(); refresh(); } });
function netScanNow(btn) {
  if (btn.classList.contains('rz-job')) return;
  const main = $('#net-scan') || btn;   // ook vanuit de banner: de voortgang staat bij de knop in de balk
  jobRun('netscan', main, { ...NET_JOB(), start: () => api('net_scan_now', { deep: true }) });
}

function renderNet() { withMorph(renderNetNow); if (netSub === 'map') renderNetMap(); }

// ---------------------------------------------------------------- 1.186.0: verkeer per apparaat
const fmtRate = (bps) => (bps >= 125000 ? dec(((bps * 8) / 1e6).toFixed(bps * 8 >= 1e8 ? 0 : 1)) + ' Mbit/s' : dec(((bps * 8) / 1e3).toFixed(0)) + ' kbit/s');
const netTr = (x) => netData?.traffic?.[x.id] || null;
/** Minigrafiek: 24 staafjes (bytes per uur) */
function trSpark(arr) {
  const max = Math.max(1, ...arr);
  return `<span class="tr-spark" aria-hidden="true">${arr.map((v) => `<i style="--h:${Math.max(v ? 8 : 0, Math.round((v / max) * 100))}%"></i>`).join('')}</span>`;
}
/** 1.188.0: welke bronnen het verkeer leveren */
const TR_SRC = () => ({ unifi: 'UniFi', snmp: L('SNMP (switchpoort)'), agent: L('agent (netwerkkaart)') });
function trSources(st) {
  const s = Object.keys(st.sources || {}).sort((a, b) => ['unifi', 'snmp', 'agent'].indexOf(a) - ['unifi', 'snmp', 'agent'].indexOf(b));
  return s.length ? L('bron: {s}', { s: s.map((k) => TR_SRC()[k] || k).join(', ') }) : L('volgens UniFi');
}
/** Top 10 van de laatste 24 uur (download en upload naast elkaar) */
function trTopHtml(limit = 10) {
  const d = netData;
  if (!d) return '';
  const st = d.traffic_state || {};
  const rows = d.devices.map((x) => ({ x, t: netTr(x) })).filter((r) => r.t && r.t.rx + r.t.tx > 0).sort((a, b) => (b.t.rx + b.t.tx) - (a.t.rx + a.t.tx)).slice(0, limit);
  const tt = d.traffic_totals || { today: [0, 0], yesterday: [0, 0] };
  const pct = (a, b) => (b > 0 ? Math.round(((a - b) / b) * 100) : null);
  const cmp = (a, b) => { const p = pct(a, b); return p === null ? '' : ` <small class="${p > 0 ? 'warn-txt' : 'good-txt'}">${p > 0 ? '+' : ''}${p}% ${L('t.o.v. gisteren')}</small>`; };
  if (!st.on) {
    return `<p class="muted small">${!st.unifi && st.setting ? L('Nog geen bron voor verkeer. Koppel UniFi (aanbevolen), zet SNMP aan op je switch, of werk de agent op je servers bij naar 1.80.0.') + ' ' + L('Koppel UniFi (Instellingen → Koppelingen → UniFi) om het verkeer per apparaat te zien.')
      : !st.account ? L('Verkeer per apparaat vraagt een lokaal UniFi-account; met alleen een API-sleutel geeft UniFi geen tellers.')
      : !st.setting ? L('Verkeer per apparaat staat uit (Instellingen → Beveiliging → Netwerkscan).') : L('De netwerkscan staat uit.')}</p>`;
  }
  if (st.error) return `<p class="warn-txt small">⚠️ ${esc(st.error)}</p>`;
  if (!rows.length) return `<p class="muted small">${L('Nog geen verkeer gemeten. Na 5 tot 10 minuten zie je hier de eerste cijfers.')}</p>`;
  const max = Math.max(1, ...rows.map((r) => Math.max(r.t.rx, r.t.tx)));
  return `<div class="tr-tot"><span>↓ <b>${fmtBytes(tt.today[0])}</b>${cmp(tt.today[0], tt.yesterday[0])}</span><span>↑ <b>${fmtBytes(tt.today[1])}</b>${cmp(tt.today[1], tt.yesterday[1])}</span><small class="muted">${L('vandaag, alle apparaten')}</small></div>
    <div class="tr-top">${rows.map(({ x, t }) => `<div class="tr-row"><button type="button" class="nd-link" data-nm-open="${x.id}">${(NET_KIND[netKind(x)] || NET_KIND.unknown)[0]} ${esc(x.label)}</button>
      <span class="tr-bars"><i class="dn" style="width:${(t.rx / max) * 100}%"></i><i class="up" style="width:${(t.tx / max) * 100}%"></i></span>
      <span class="tr-num small">↓ ${fmtBytes(t.rx)} · ↑ ${fmtBytes(t.tx)}</span></div>`).join('')}</div>
    <p class="muted small tr-legend"><i class="lg dn"></i> ${L('download')} <i class="lg up"></i> ${L('upload')} · ${L('laatste 24 uur')} · ${trSources(st)}</p>`;
}
/** 1.187.0: de internetlijn deze maand (UniFi-gateway, anders de som van de apparaten), met de datalimiet */
function wanHtml() {
  const w = netData?.wan;
  if (!w || !w.days || !w.days.length) return '';
  const used = w.rx + w.tx;
  const mx = Math.max(1, ...w.days.map((d) => d[1] + d[2]));
  const daysIn = new Date(+w.month.slice(0, 4), +w.month.slice(5, 7), 0).getDate();
  const byDay = Object.fromEntries(w.days.map((d) => [d[0], d]));
  const bars = Array.from({ length: daysIn }, (_, i) => { const k = `${w.month}-${String(i + 1).padStart(2, '0')}`; const d = byDay[k];
    return `<span title="${esc(fmtDay(k) + (d ? ': ↓ ' + fmtBytes(d[1]) + ' · ↑ ' + fmtBytes(d[2]) : ''))}">${d ? `<i class="up" style="height:${(d[2] / mx) * 100}%"></i><i class="dn" style="height:${(d[1] / mx) * 100}%"></i>` : ''}</span>`; }).join('');
  const pct = w.cap ? Math.min(100, Math.round((used / w.cap) * 100)) : null;
  return `<section class="card nm-wan"><h3>🌐 ${L('Internetlijn deze maand')}</h3>
    <div class="tr-tot"><span>↓ <b>${fmtBytes(w.rx)}</b></span><span>↑ <b>${fmtBytes(w.tx)}</b></span>
      ${w.forecast ? `<small class="muted">${L('verwacht aan het eind van de maand: {f}', { f: fmtBytes(w.forecast) })}</small>` : ''}</div>
    ${w.cap ? `<div class="wan-cap"><span class="wan-bar"><i class="${pct >= 100 ? 'bad' : pct >= 80 ? 'warn' : ''}" style="width:${pct}%"></i>${w.forecast ? `<b style="left:${Math.min(100, (w.forecast / w.cap) * 100)}%" title="${esc(L('verwacht'))}"></b>` : ''}</span>
      <small>${L('{u} van {c} ({p}%)', { u: fmtBytes(used), c: fmtBytes(w.cap), p: pct })}${w.forecast && w.forecast > w.cap ? ' · <span class="warn-txt">⚠️ ' + L('je komt er deze maand waarschijnlijk overheen') + '</span>' : ''}</small></div>` : ''}
    <div class="tr-days wan-days">${bars}</div>
    <p class="muted small tr-legend"><i class="lg dn"></i> ${L('download')} <i class="lg up"></i> ${L('upload')} · ${w.src === 'unifi' ? L('van je UniFi-gateway') : L('de som van alle apparaten (geen gegevens van de gateway)')}</p></section>`;
}

/** 1.187.0: diensten (UniFi DPI) in het venster van een apparaat */
async function netDpiBox(x, box) {
  let d;
  try { d = await api('net_dpi', null, { id: x.id }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (d.error) { box.innerHTML = `<p class="muted">${esc(d.error)}</p>`; return; }
  if (!d.cats.length) { box.innerHTML = `<p class="muted">${L('Geen gegevens. Staat DPI aan in UniFi (Settings → Traffic Identification)?')}</p>`; return; }
  const tot = d.cats.reduce((a, c) => a + c.rx + c.tx, 0) || 1;
  box.innerHTML = `<div class="dpi-list">${d.cats.slice(0, 10).map((c) => { const p = ((c.rx + c.tx) / tot) * 100;
    return `<div class="dpi-row"><span>${esc(c.name)}</span><span class="dpi-bar"><i style="width:${p}%"></i></span><span class="small muted nowrap">${dec(p.toFixed(p < 10 ? 1 : 0))}% · ↓ ${fmtBytes(c.rx)} ↑ ${fmtBytes(c.tx)}</span></div>`; }).join('')}</div>
    <p class="muted small">${L('Volgens UniFi (Traffic Identification), sinds het apparaat verbonden is.')}</p>`;
}

/** Verkeer in het venster van een apparaat */
async function netTrafficBox(x, box) {
  let d;
  try { d = await api('net_traffic_device', null, { id: x.id }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (!d.hours.length && !d.days.length) { box.innerHTML = `<p class="muted small">${L('Nog geen verkeer gemeten voor dit apparaat.')}</p>`; return; }
  // per uur, 7 dagen: twee lijnen (download, upload)
  const hrs = [];
  const start = new Date(); start.setHours(0, 0, 0, 0); start.setDate(start.getDate() - 6);
  const byH = Object.fromEntries(d.hours.map(([h, rx, tx]) => [h, [rx, tx]]));
  for (let i = 0; i < 7 * 24; i++) {
    const t = new Date(start.getTime() + i * 3600e3);
    const k = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')} ${String(t.getHours()).padStart(2, '0')}`;
    hrs.push([t, ...(byH[k] || [0, 0])]);
  }
  const W = 700, H = 150, P = 4;
  const mx = Math.max(1, ...hrs.map((h) => Math.max(h[1], h[2])));
  const xy = (i, v) => `${(P + (i / (hrs.length - 1)) * (W - 2 * P)).toFixed(1)},${(H - P - (v / mx) * (H - 2 * P - 14)).toFixed(1)}`;
  const line = (k) => hrs.map((h, i) => xy(i, h[k])).join(' ');
  const dayTicks = Array.from({ length: 7 }, (_, j) => { const xx = P + ((j * 24) / (hrs.length - 1)) * (W - 2 * P);
    return `<line x1="${xx}" y1="0" x2="${xx}" y2="${H}" class="tr-grid"/><text x="${xx + 4}" y="12" class="tr-ax">${esc(hrs[j * 24][0].toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric' }))}</text>`; }).join('');
  const days = d.days;
  const dmx = Math.max(1, ...days.map((r) => r[1] + r[2]));
  const sum = (k) => days.slice(-1).reduce((a, r) => a + r[k], 0);
  const n = d.normal;
  const ratio = (v, nv) => (nv > 0 ? v / nv : null);
  const rUp = n ? ratio(sum(2), n.tx) : null, rDn = n ? ratio(sum(1), n.rx) : null;
  box.innerHTML = `<div class="tr-dev">
    <div class="tr-dev-head"><span>↓ <b>${fmtBytes(sum(1))}</b> ↑ <b>${fmtBytes(sum(2))}</b> <small class="muted">${L('vandaag')}</small></span>
      ${d.rate ? `<span class="small">${L('nu')}: ↓ ${fmtRate(d.rate.rx)} · ↑ ${fmtRate(d.rate.tx)}</span>` : ''}
      ${n ? `<span class="small muted">${L('normaal per dag')}: ↓ ${fmtBytes(n.rx)} · ↑ ${fmtBytes(n.tx)}</span>` : `<span class="small muted">${L('nog aan het leren wat normaal is (7 dagen)')}</span>`}
      ${(rUp && rUp >= 5) || (rDn && rDn >= 5) ? `<span class="warn-txt small">⚠️ ${L('vandaag {x}× zoveel als normaal', { x: dec(Math.max(rUp || 0, rDn || 0).toFixed(1)) })}</span>` : ''}</div>
    <p class="small muted" style="margin:.4rem 0 .2rem">${L('Per uur, laatste 7 dagen')}</p>
    <svg viewBox="0 0 ${W} ${H}" class="tr-chart" role="img" aria-label="${esc(L('Verkeer per uur, laatste 7 dagen'))}">${dayTicks}
      <polyline points="${line(1)}" class="tr-l dn"/><polyline points="${line(2)}" class="tr-l up"/></svg>
    <p class="small muted" style="margin:.6rem 0 .2rem">${L('Per dag, laatste 30 dagen')}</p>
    <div class="tr-days">${days.map(([day, rx, tx]) => `<span title="${esc(fmtDay(day) + ': ↓ ' + fmtBytes(rx) + ' · ↑ ' + fmtBytes(tx))}"><i class="up" style="height:${(tx / dmx) * 100}%"></i><i class="dn" style="height:${(rx / dmx) * 100}%"></i></span>`).join('')}</div>
    <p class="muted small tr-legend"><i class="lg dn"></i> ${L('download')} <i class="lg up"></i> ${L('upload')} · ${d.src ? L('bron: {s}', { s: TR_SRC()[d.src] || d.src }) : L('volgens UniFi')}</p></div>`;
}

// ---------------------------------------------------------------- 1.184.0: Netwerk → Overzicht (netwerkkaart)
const ipNum = (ip) => String(ip).split('.').reduce((a, o) => a * 256 + (+o || 0), 0);
function ipInCidr(ip, cidr) {
  const [base, bits] = String(cidr).split('/');
  if (!/^\d+\.\d+\.\d+\.\d+$/.test(ip) || bits === undefined) return false;
  const size = 2 ** (32 - +bits);
  return Math.floor(ipNum(ip) / size) === Math.floor(ipNum(base) / size);
}
function renderNetMap() {
  const d = netData, box = $('#net-map');
  if (!box) return;
  if (!d) { box.innerHTML = `<p class="muted">${L('laden…')}</p>`; return; }
  if (!d.enabled) { box.innerHTML = `<div class="v-banner">ℹ️ <span>${L('De netwerkscan staat uit.')} ${isAdmin() ? L('Zet hem aan bij <a href="#settings/netscan" data-goto="settings/netscan">Instellingen → Beveiliging → Netwerkscan</a>.') : L('Een beheerder kan hem aanzetten.')}</span></div>`; return; }
  const day = Date.now() - 86400e3;
  const ts = (s) => new Date(String(s).replace(' ', 'T')).getTime();
  const devs = d.devices.filter((x) => x.status !== 'ignored');
  const online = (x) => ts(x.last_seen) > day;
  const icon = (x) => (NET_KIND[netKind(x)] || NET_KIND.unknown)[0];
  // Router: de standaardgateway, anders een apparaat van de soort router
  const router = devs.find((x) => x.info.gateway) || devs.find((x) => netKind(x) === 'router');
  // Infrastructuur uit UniFi: de namen van access points en switches waar clients aan hangen
  const infra = new Map();
  const norm = (s) => String(s || '').trim().toLowerCase();
  const infraDev = (name) => devs.find((x) => x !== router && [x.name, x.info.unifi?.name, x.label, x.hostname].some((v) => v && norm(v) === norm(name)));
  devs.forEach((x) => {
    const u = x.info.unifi || {};
    const via = u.essid && u.ap ? ['ap', u.ap] : u.sw ? ['sw', u.sw] : null;
    if (!via) return;
    const key = via[0] + ':' + via[1];
    if (!infra.has(key)) infra.set(key, { kind: via[0], name: via[1], dev: infraDev(via[1]), clients: [], ssids: new Set() });
    infra.get(key).clients.push(x);
    if (u.essid) infra.get(key).ssids.add(u.essid);
  });
  const infraDevs = new Set([...infra.values()].map((n) => n.dev).filter(Boolean));
  // Netwerkapparaten zonder clients (switch zonder UniFi-gegevens) ook als knoop
  devs.filter((x) => x !== router && netKind(x) === 'network' && !infraDevs.has(x)).forEach((x) => {
    infra.set('dev:' + x.id, { kind: 'net', name: x.label, dev: x, clients: [], ssids: new Set() });
    infraDevs.add(x);
  });
  const placed = new Set([router, ...infraDevs, ...[...infra.values()].flatMap((n) => n.clients)].filter(Boolean));
  const wired = devs.filter((x) => !placed.has(x) && x.info.unifi?.wired);
  const rest = devs.filter((x) => !placed.has(x) && !x.info.unifi?.wired);
  const hasUnifi = devs.some((x) => x.info.unifi);
  // Tellers per soort en per netwerk
  const kinds = {};
  devs.forEach((x) => { const k = netKind(x); kinds[k] = kinds[k] || { n: 0, on: 0 }; kinds[k].n++; if (online(x)) kinds[k].on++; });
  const nets = (d.networks?.scan || []).map((c) => { const xs = devs.filter((x) => ipInCidr(x.ip, c)); return { c, n: xs.length, on: xs.filter(online).length }; });
  const other = devs.filter((x) => !(d.networks?.scan || []).some((c) => ipInCidr(x.ip, c)));
  if (other.length) nets.push({ c: L('Andere netwerken'), n: other.length, on: other.filter(online).length });
  const kindChips = Object.entries(kinds).sort((a, b) => b[1].n - a[1].n).map(([k, v]) => `<button type="button" class="nm-kind ${nmKind === k ? 'on' : ''}" data-nm-kind="${k}">
      <span class="nm-ki">${(NET_KIND[k] || NET_KIND.unknown)[0]}</span><b>${v.n}</b><small>${esc((NET_KIND[k] || NET_KIND.unknown)[1]())}${v.on < v.n ? ` · ${L('{n} online', { n: v.on })}` : ''}</small></button>`).join('');
  const netRows = nets.map((n) => `<div class="nm-net"><span class="mono">${esc(n.c)}</span><span class="nm-netbar"><i style="width:${n.n ? Math.round((n.on / n.n) * 100) : 0}%"></i></span><span class="small">${L('{on} van {n} online', { on: n.on, n: n.n })}</span></div>`).join('');
  // Een apparaat als bolletje
  const dot = (x) => {
    const cls = [online(x) ? 'on' : 'off', x.status === 'new' ? 'new' : '', x.risky.length ? 'risky' : '', x.host ? 'rz' : '', nmKind && netKind(x) !== nmKind ? 'dim' : ''].join(' ');
    const tip = [x.label, x.ip, x.vendor, online(x) ? L('online') : L('offline'), x.status === 'new' ? L('nieuw') : '', x.risky.length ? L('riskante poort') : '', x.host ? L('in RzSense') : ''].filter(Boolean).join(' · ');
    const t = netTr(x);
    const tb = t ? t.rx + t.tx : 0;
    return `<button type="button" class="nm-dot ${cls} ${tb >= 1e9 ? 'heavy' : ''}" data-nm-open="${x.id}" title="${esc(tip + (t ? ` · ↓ ${fmtBytes(t.rx)} ↑ ${fmtBytes(t.tx)} (24 u)` : ''))}" aria-label="${esc(tip)}"><span>${icon(x)}</span><small>${esc(x.label)}</small>${tb ? `<em class="nm-tr">${fmtBytes(tb)}</em>` : ''}</button>`;
  };
  const node = (n) => {
    const x = n.dev;
    const ic = n.kind === 'ap' ? '📶' : '🔀';
    const on = n.clients.filter(online).length;
    return `<div class="nm-node ${nmKind && !n.clients.some((c) => netKind(c) === nmKind) && !(x && netKind(x) === nmKind) ? 'dim' : ''}">
      <div class="nm-nh">${x ? `<button type="button" class="nd-link" data-nm-open="${x.id}">${ic} ${esc(n.name)}</button>` : `<b>${ic} ${esc(n.name)}</b>`}
        <small class="muted">${n.kind === 'ap' ? L('access point') : n.kind === 'sw' ? L('switch') : L('netwerkapparaat')}${n.ssids.size ? ' · ' + [...n.ssids].map(esc).join(', ') : ''}${x ? ' · ' + esc(x.ip) : ''}</small>
        <span class="nm-cnt">${n.clients.length ? L('{on}/{n} online', { on, n: n.clients.length }) : ''}</span></div>
      <div class="nm-dots">${n.clients.sort((a, b) => netKind(a).localeCompare(netKind(b)) || a.label.localeCompare(b.label)).map(dot).join('') || `<span class="muted small">${L('geen clients bekend')}</span>`}</div></div>`;
  };
  const nodes = [...infra.values()].sort((a, b) => (a.kind === 'sw' ? 0 : 1) - (b.kind === 'sw' ? 0 : 1) || b.clients.length - a.clients.length);
  const bucket = (title, ic, xs, sub) => (xs.length ? `<div class="nm-node nm-bucket"><div class="nm-nh"><b>${ic} ${esc(title)}</b><small class="muted">${esc(sub)}</small><span class="nm-cnt">${L('{on}/{n} online', { on: xs.filter(online).length, n: xs.length })}</span></div>
    <div class="nm-dots">${xs.sort((a, b) => netKind(a).localeCompare(netKind(b)) || a.label.localeCompare(b.label)).map(dot).join('')}</div></div>` : '');
  box.innerHTML = `<div class="nm-top">
      <section class="card nm-kinds-card"><h3>${L('Wat zit er op je netwerk')}</h3><div class="nm-kinds">${kindChips}</div></section>
      <section class="card nm-nets-card"><h3>${L('Per netwerk')}</h3>${netRows || `<p class="muted small">${L('Nog geen netwerk ingesteld.')}</p>`}</section>
    </div>
    <div class="nm-tr2"><section class="card nm-traffic"><h3>📊 ${L('Verkeer vandaag')}</h3>${trTopHtml(10)}</section>${wanHtml()}</div>
    <section class="card nm-map">
      <div class="nm-legend small muted"><span><i class="lg on"></i> ${L('online')}</span><span><i class="lg off"></i> ${L('offline (24 uur niet gezien)')}</span><span><i class="lg new"></i> ${L('nieuw')}</span><span><i class="lg risky"></i> ${L('riskante poort')}</span><span><i class="lg rz"></i> ${L('in RzSense')}</span></div>
      <div class="nm-tree">
        <div class="nm-root"><div class="nm-inet">☁️ ${L('Internet')}</div><div class="nm-line"></div>
          <div class="nm-router">${router ? `<button type="button" class="nd-link" data-nm-open="${router.id}">🌐 ${esc(router.label)}</button><small class="muted mono">${esc(router.ip)}</small>` : `<b>🌐 ${L('Router')}</b><small class="muted">${L('nog niet herkend')}</small>`}</div>
          <div class="nm-line"></div></div>
        <div class="nm-level">${nodes.map(node).join('')}${bucket(L('Bedraad'), '🔌', wired, L('switch onbekend'))}${bucket(hasUnifi ? L('Verbinding onbekend') : L('Op je netwerk'), hasUnifi ? '❔' : '🏠', rest, hasUnifi ? L('niet in UniFi gezien') : L('koppel UniFi om te zien aan welk access point of welke switch elk apparaat hangt'))}</div>
      </div>
    </section>`;
}
function nmClick(e) {
  const k = e.target.closest('[data-nm-kind]');
  if (k) { nmKind = nmKind === k.dataset.nmKind ? '' : k.dataset.nmKind; return renderNetMap(); }
  const o = e.target.closest('[data-nm-open]');
  if (o) netDialog(netData.devices.find((x) => +x.id === +o.dataset.nmOpen));
}
function renderNetNow() {
  const d = netData;
  if (!d) return;
  try { ccNetChart(); } catch (e) { /* idem */ }
  const devs = d.devices;
  const day = Date.now() - 86400e3;
  const online = (x) => new Date(String(x.last_seen).replace(' ', 'T')).getTime() > day;
  const cnt = {
    all: devs.length, new: devs.filter((x) => x.status === 'new').length, norz: devs.filter((x) => !x.host && x.status !== 'ignored').length,
    rz: devs.filter((x) => x.host).length, risky: devs.filter((x) => x.risky.length && x.status !== 'ignored').length,
    known: devs.filter((x) => x.status === 'known').length, ignored: devs.filter((x) => x.status === 'ignored').length, online: devs.filter(online).length,
    blocked: devs.filter((x) => x.blocked).length,
  };
  $('#net-counts').innerHTML = [['new', '🆕', L('Nieuw'), 's1'], ['risky', '⚠️', L('Riskante poort'), 's2'], ['norz', '➕', L('Niet in RzSense'), 'si'], ['rz', '✔', L('In RzSense'), 's0']]
    .map(([k, ic, w, cls]) => `<button type="button" class="hc ${cls} ${cnt[k] ? '' : 'zero'} ${netFilter === k ? 'on' : ''}" data-nf="${k}"><span class="hc-dot"></span><b>${cnt[k]}</b> <span class="hc-w">${ic} ${w}</span></button>`).join('');
  const st = d.state || {};
  $('#net-last').textContent = st.sweep_at ? L('laatste scan {t}', { t: ago(new Date(st.sweep_at * 1000).toISOString()) }) + (st.deep_at ? ' · ' + L('diep {t}', { t: ago(new Date(st.deep_at * 1000).toISOString()) }) : '') : '';
  // Uitleg bovenaan: uit, geen netwerk, of nog nooit gescand
  const nets = d.networks || { scan: [], skip: [] };
  $('#net-banner').innerHTML = !d.enabled
    ? `<div class="v-banner">ℹ️ <span>${L('De netwerkscan staat uit.')} ${isAdmin() ? L('Zet hem aan bij <a href="#settings/netscan" data-goto="settings/netscan">Instellingen → Beveiliging → Netwerkscan</a>.') : L('Een beheerder kan hem aanzetten.')}</span></div>`
    : !nets.scan.length ? `<div class="v-banner">⚠️ <span>${L('Geen netwerk om te scannen: zet je netwerk (bv. 192.168.1.0/24) bij <a href="#settings/access" data-goto="settings/access">Toegang → Lokale netwerken</a>.')}</span></div>`
    : st.sweep_error ? `<div class="v-banner">⚠️ <span>${esc(st.sweep_error)}</span></div>`
    : st.links_error ? `<div class="v-banner">⚠️ <span>${esc(st.links_error)}</span> ${isAdmin() ? `<a href="#settings/netlinks" data-goto="settings/netlinks">${L('Instellingen')}</a>` : ''}</div>`
    : !st.sweep_at ? `<div class="v-banner">⏳ <span>${L('Nog niet gescand. De eerste scan volgt binnen een minuut, of start hem nu.')}</span> <button type="button" class="btn need-admin" data-net-scan>🔍 ${L('Nu scannen')}</button></div>` : '';
  $('#net-kpis').innerHTML = `
    <div class="kpi kpi-main"><div class="kpi-txt"><div class="kpi-lbl">${L('Apparaten')}</div><div class="kpi-val">${cnt.online}<small>/${cnt.all}</small></div>
      <div class="kpi-sub">${L('laatste 24 uur gezien')}</div></div></div>
    <div class="kpi ${cnt.new ? 'warn' : ''}"><div class="kpi-lbl">${L('Nieuw')}</div><div class="kpi-val">${cnt.new}</div><div class="kpi-sub">${cnt.new ? L('nog niet beoordeeld') : L('alles beoordeeld')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Niet in RzSense')}</div><div class="kpi-val">${cnt.norz}</div><div class="kpi-sub">${L('{n} wel', { n: cnt.rz })}</div></div>
    <div class="kpi ${cnt.risky ? 'bad' : ''}"><div class="kpi-lbl">${L('Riskante poort open')}</div><div class="kpi-val">${cnt.risky}</div><div class="kpi-sub">${cnt.risky ? L('Telnet, FTP, VNC …') : L('niets gevonden')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Netwerken')}</div><div class="kpi-val kpi-val-txt">${esc(nets.scan.join(', ') || '–')}</div>
      <div class="kpi-sub">${st.sweep_ms ? L('scan duurde {s} s', { s: dec((st.sweep_ms / 1000).toFixed(1)) }) : ''}</div></div>`;
  $('#net-filter').innerHTML = [['all', L('Alles')], ['new', '🆕 ' + L('Nieuw')], ['norz', L('Niet in RzSense')], ['known', L('Bekend')], ['risky', '⚠️ ' + L('Riskant')], ['blocked', '⛔ ' + L('Geweerd')], ['ignored', L('Genegeerd')]]
    .map(([k, l]) => `<button type="button" class="dchip ${netFilter === k ? 'on' : ''}" data-nf="${k}">${l} <b>${cnt[k]}</b></button>`).join('');
  const q = $('#net-search').value.trim().toLowerCase();
  const list = devs.filter((x) => (netFilter === 'all' ? x.status !== 'ignored' : netFilter === 'new' ? x.status === 'new' : netFilter === 'norz' ? !x.host && x.status !== 'ignored'
      : netFilter === 'rz' ? x.host : netFilter === 'known' ? x.status === 'known' : netFilter === 'risky' ? x.risky.length && x.status !== 'ignored' : netFilter === 'blocked' ? x.blocked : x.status === 'ignored')
    && (!q || [x.label, x.ip, x.mac, x.vendor, x.hostname, x.name, x.info.netbios, x.info.ssdp?.model].some((v) => String(v || '').toLowerCase().includes(q))));
  if (!devs.length) { $('#net-list').innerHTML = `<p class="muted">${d.enabled ? L('Nog geen apparaten gevonden.') : L('De netwerkscan staat uit.')}</p>`; return; }
  if (!list.length) { $('#net-list').innerHTML = `<p class="muted">${L('Niets gevonden met dit filter.')}</p>`; return; }
  // 1.183.0: groeperen per soort, fabrikant of verbinding
  const grp = $('#net-group')?.value || '';
  const gKey = (x) => (grp === 'kind' ? netKind(x) : grp === 'vendor' ? (x.vendor || (+x.random_mac ? '🎭' : '')) : grp === 'conn' ? (x.info.unifi?.essid ? 'w:' + x.info.unifi.essid : x.info.unifi?.wired ? 'cable' : '') : '');
  const gName = (k) => (grp === 'kind' ? (NET_KIND[k] || NET_KIND.unknown)[0] + ' ' + (NET_KIND[k] || NET_KIND.unknown)[1]()
    : grp === 'vendor' ? (k === '🎭' ? '🎭 ' + L('Privé-adres (geen fabrikant)') : k || L('Onbekende fabrikant'))
    : k.startsWith('w:') ? '📶 ' + k.slice(2) : k === 'cable' ? '🔌 ' + L('Bedraad') : L('Onbekend (geen UniFi-gegevens)'));
  const hasTr = !!(d.traffic_state?.on && Object.keys(d.traffic || {}).length);
  if (hasTr && netSortTr) list.sort((a, b) => ((netTr(b)?.rx || 0) + (netTr(b)?.tx || 0)) - ((netTr(a)?.rx || 0) + (netTr(a)?.tx || 0)));
  const groups = [];
  if (grp) {
    const m = new Map();
    list.forEach((x) => { const k = gKey(x); if (!m.has(k)) m.set(k, []); m.get(k).push(x); });
    [...m.entries()].sort((a, b) => b[1].length - a[1].length || String(a[0]).localeCompare(String(b[0]))).forEach(([k, xs]) => groups.push([gName(k), xs]));
  } else groups.push(['', list]);
  const row = (x) => {
      const [ic, kl] = NET_KIND[netKind(x)] || NET_KIND.unknown;
      const u = x.info.unifi || {};
      const sub = [kl(), x.name && x.hostname ? x.hostname : '', x.info.ssdp?.model || '', u.essid ? '📶 ' + u.essid + (u.ap ? ' · ' + u.ap : '') : u.wired ? '🔌 ' + L('bedraad') : ''].filter(Boolean).join(' · ');
      return `<tr class="${x.status === 'ignored' ? 'muted' : ''}" data-net="${x.id}"><td><button type="button" class="link-btn net-open" data-net-open="${x.id}"><span class="net-ic">${ic}</span> <b>${esc(x.label)}</b></button>
          ${x.status === 'new' ? ` <span class="badge-root">${L('nieuw')}</span>` : ''}${x.blocked ? ` <span class="badge-root bad-badge" title="${esc(netBlockedText(x))}">⛔ ${L('geweerd')}</span>` : ''}${x.host ? ` <span class="badge" title="${esc(L('In RzSense als {name}', { name: x.host.name }))}">✔ RzSense</span>` : ''}
          <br><span class="muted small">${esc(sub)}</span></td>
        <td class="small"><span class="mono">${esc(x.ip)}</span>${online(x) ? '' : ` <span class="muted">(${L('offline')})</span>`}</td>
        <td class="small">${x.mac ? `<span class="mono">${esc(x.mac.toUpperCase())}</span>` : '–'}${+x.random_mac ? ` <span class="badge" title="${esc(L('Willekeurig MAC-adres (privé-wifiadres): telefoons en laptops gebruiken dat per netwerk, en soms wisselt het'))}">🎭 ${L('privé')}</span>` : ''}<br><span class="muted">${esc(x.vendor || '')}</span></td>
        <td class="small">${x.ports.length ? x.ports.map((p) => `<span class="port-tag ${x.risky.includes(p) ? 'bad' : ''}" title="${esc(x.risky.includes(p) ? d.risky_reasons[p] : '')}">${p}</span>`).join(' ') : `<span class="muted">${x.deep_at ? L('geen') : '…'}</span>`}</td>
        ${hasTr ? `<td class="small nowrap">${netTr(x) ? `${trSpark(netTr(x).spark)}<br>↓ ${fmtBytes(netTr(x).rx)} · ↑ ${fmtBytes(netTr(x).tx)}` : '<span class="muted">–</span>'}</td>` : ''}
        <td class="small">${esc(ago(x.last_seen))}<br><span class="muted">${L('sinds {d}', { d: esc(fmtDay(x.first_seen)) })}</span></td>
        <td class="nowrap">${!x.host ? `<button type="button" class="btn small-btn need-admin" data-net-add="${x.id}" title="${esc(L('Toevoegen aan RzSense'))}">＋ RzSense</button>` : ''}
          ${x.status !== 'known' ? `<button type="button" class="icon-btn need-admin" data-net-st="known:${x.id}" title="${esc(L('Bekend: dit apparaat hoort hier'))}">✔</button>` : ''}
          ${x.status !== 'ignored' ? `<button type="button" class="icon-btn need-admin" data-net-st="ignored:${x.id}" title="${esc(L('Negeren: niet meer tonen'))}">🙈</button>` : `<button type="button" class="icon-btn need-admin" data-net-st="new:${x.id}" title="${esc(L('Weer tonen'))}">↩</button>`}</td></tr>`;
  };
  $('#net-list').innerHTML = `<div class="table-scroll"><table class="vuln-table net-table"><thead><tr><th>${L('Apparaat')}</th><th>${L('IP-adres')}</th><th>${L('MAC en fabrikant')}</th><th>${L('Poorten')}</th>${hasTr ? `<th><button type="button" class="link-btn" data-net-sort="tr">${L('Verkeer 24 u')} ${netSortTr ? '▼' : '⇅'}</button></th>` : ''}<th>${L('Gezien')}</th><th></th></tr></thead>
    ${groups.map(([name, xs]) => `<tbody>${name ? `<tr class="net-grp"><th colspan="${hasTr ? 7 : 6}">${esc(name)} <span class="muted">${xs.length}</span></th></tr>` : ''}${xs.map(row).join('')}</tbody>`).join('')}</table></div>`;
}

async function netClick(e) {
  const open = e.target.closest('[data-net-open]'), add = e.target.closest('[data-net-add]'), stb = e.target.closest('[data-net-st]');
  const find = (id) => netData.devices.find((x) => +x.id === +id);
  if (open) return netDialog(find(open.dataset.netOpen));
  if (add) return netAddHost(find(add.dataset.netAdd));
  if (stb) {
    const [status, id] = stb.dataset.netSt.split(':');
    try { await api('net_device_save', { id: +id, status }); await loadNet(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  }
}

/** Server toevoegen met naam, adres en soort uit de scan; ping-check aan */
function netAddHost(x) {
  if (!x) return;
  hostForm(null, { name: x.name || x.label, address: x.ip, kind: NET_TO_HOST[netKind(x)] || '' });
}

function netDialog(x) {
  if (!x) return;
  const [ic] = NET_KIND[netKind(x)] || NET_KIND.unknown;
  const i = x.info || {};
  const row = (k, v) => (v ? `<tr><th>${k}</th><td>${v}</td></tr>` : '');
  const admin = isAdmin();
  modal({
    title: `${ic} ${x.label}`,
    wide: true,
    body: `<table class="kv-table">
        ${row(L('IP-adres'), `<span class="mono">${esc(x.ip)}</span>${(i.ips || []).length ? ` <span class="muted small">(${L('eerder: {ips}', { ips: esc(i.ips.join(', ')) })})</span>` : ''}`)}
        ${row(L('MAC-adres'), x.mac ? `<span class="mono">${esc(x.mac.toUpperCase())}</span>${+x.random_mac ? ' · 🎭 ' + L('privé-adres (willekeurig)') : ''}` : L('onbekend (ander netwerk)'))}
        ${row(L('Fabrikant'), esc(x.vendor || ''))}
        ${row('UniFi', i.unifi ? esc([i.unifi.name, i.unifi.hostname, i.unifi.essid ? '📶 ' + i.unifi.essid : '', i.unifi.ap ? L('via {ap}', { ap: i.unifi.ap }) : '', i.unifi.wired ? '🔌 ' + L('bedraad') : '', i.unifi.blocked ? '⛔ ' + L('geblokkeerd in UniFi') : ''].filter(Boolean).join(' · ')) : '')}
        ${row('AdGuard', esc(i.adguard || ''))}
        ${row(L('DNS-naam'), esc(x.hostname || ''))}
        ${row('NetBIOS', esc(i.netbios || ''))}
        ${row('mDNS', i.mdns ? esc([i.mdns.name, i.mdns.host].filter(Boolean).join(' · ')) + (i.mdns.types?.length ? `<br><span class="muted small">${esc(i.mdns.types.join(', '))}</span>` : '') : '')}
        ${row('UPnP', i.ssdp ? esc([i.ssdp.name, i.ssdp.maker, i.ssdp.model, i.ssdp.type].filter(Boolean).join(' · ')) + (i.ssdp.server ? `<br><span class="muted small">${esc(i.ssdp.server)}</span>` : '') : '')}
        ${row(L('Webpagina'), i.http ? `${esc(i.http.title || '–')} <span class="muted small">(${L('poort {p}', { p: i.http.port })}${i.http.server ? ' · ' + esc(i.http.server) : ''})</span>` : '')}
        ${row(L('Open poorten'), x.ports.length ? x.ports.map((p) => `<span class="port-tag ${x.risky.includes(p) ? 'bad' : ''}">${p}</span>`).join(' ') : (x.deep_at ? L('geen gevonden') : L('nog niet gescand')))}
        ${row(L('In RzSense'), x.host ? `✔ ${esc(x.host.name)}` : L('nee'))}
        ${row(L('Gezien'), `${esc(fmtDateTime(x.first_seen))} – ${esc(ago(x.last_seen))}`)}
      </table>
      ${x.risky.length ? `<div class="callout bad-callout"><b>⚠️ ${L('Riskante poorten')}</b><ul>${x.risky.map((p) => `<li><b>${p}</b>: ${esc(netData.risky_reasons[p] || '')}</li>`).join('')}</ul>
        <p class="small" style="margin:0">${L('Heb je deze dienst niet nodig, zet hem dan uit in de instellingen van het apparaat. Anders: zorg voor een sterk wachtwoord en laat de poort nooit van internet bereikbaar zijn.')}</p></div>` : ''}
      ${x.blocked ? `<div class="callout bad-callout"><b>⛔ ${L('Geweerd')}</b>: ${esc(netBlockedText(x))}</div>` : ''}
      ${netData.traffic_state?.on ? `<details class="notify-own" id="net-tr" open><summary>📊 ${L('Verkeer')}</summary><div id="net-tr-body" class="small"><p class="muted">${L('laden…')}</p></div></details>` : ''}
      ${netData.traffic_state?.on ? `<details class="notify-own" id="net-dpi"><summary>🧩 ${L('Diensten (UniFi DPI)')}</summary><div id="net-dpi-body" class="small"></div></details>` : ''}
      ${netData.links.adguard ? `<details class="notify-own" id="net-dom"><summary>🌐 ${L('Met welke domeinen praat dit apparaat? (AdGuard)')}</summary><div id="net-dom-body" class="small"></div></details>` : ''}
      ${admin ? `<label>${L('Eigen naam')}<input name="name" value="${esc(x.name || '')}" maxlength="100" placeholder="${esc(x.label)}"></label>
      <label>${L('Soort apparaat')}<select name="kind_user"><option value="">${L('vanzelf: {k}', { k: (NET_KIND[x.kind] || NET_KIND.unknown)[1]() })}</option>
        ${Object.entries(NET_KIND).map(([k, [ki, kl]]) => `<option value="${k}" ${x.kind_user === k ? 'selected' : ''}>${ki} ${esc(kl())}</option>`).join('')}</select></label>
      <label>${L('Notitie')} <span class="hint">${L('(optioneel)')}</span><input name="notes" value="${esc(x.notes || '')}" maxlength="500"></label>
      <label>${L('Status')}<select name="status">${[['new', '🆕 ' + L('Nieuw (nog niet beoordeeld)')], ['known', '✔ ' + L('Bekend')], ['ignored', '🙈 ' + L('Negeren')]]
        .map(([k, l]) => `<option value="${k}" ${x.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>` : ''}`,
    buttons: admin ? [
      { label: '🗑 ' + L('Vergeten'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Dit apparaat vergeten? Bij de volgende scan komt het terug als nieuw, als het er nog is.'))) return;
        try { await api('net_forget', { id: +x.id }); $('#modal').close(); loadNet(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
      } },
      ...(!x.host ? [{ label: '＋ ' + L('Toevoegen aan RzSense'), onClick: () => { $('#modal').close(); netAddHost(x); } }] : []),
      x.blocked ? { label: '✅ ' + L('Weer toelaten'), onClick: () => netUnblock(x) } : { label: '⛔ ' + L('Weren'), cls: 'danger', onClick: () => netBlockDialog(x) },
      { label: L('Opslaan'), cls: 'primary', submit: true },
    ] : [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
    onSubmit: async (f) => { await api('net_device_save', { id: +x.id, name: f.name, kind_user: f.kind_user, notes: f.notes, status: f.status }); toast(L('Opgeslagen')); loadNet(); },
  });
  if ($('#net-tr-body')) netTrafficBox(x, $('#net-tr-body'));   // 1.186.0
  const dpi = $('#net-dpi');
  if (dpi) dpi.ontoggle = () => { if (dpi.open && !dpi.dataset.loaded) { dpi.dataset.loaded = '1'; netDpiBox(x, $('#net-dpi-body')); } };   // 1.187.0
  const dom = $('#net-dom');
  if (dom) dom.ontoggle = () => { if (dom.open && !dom.dataset.loaded) { dom.dataset.loaded = '1'; netDomains(x, $('#net-dom-body')); } };
}

function netBlockedText(x) {
  const b = x.blocked || {};
  const via = { unifi: L('UniFi (wifi)'), adguard: L('AdGuard (DNS)'), rzmon: L('RzSense en je servers') };
  return [L('via {via}', { via: (b.via || []).map((v) => via[v] || v).join(', ') }), b.until ? L('tot {t}', { t: fmtDateTime(b.until) }) : L('tot je het weer toelaat'),
    L('door {by}', { by: b.by || '?' }), b.reason || ''].filter(Boolean).join(' · ');
}

/** Domeinen uit de query-log van AdGuard Home (1.104.0) */
async function netDomains(x, box) {
  box.innerHTML = `<span class="muted">⏳ ${L('Query-log van AdGuard Home doorzoeken…')}</span>`;
  try {
    const d = await api('net_domains', null, { id: x.id });
    if (!d.total) { box.innerHTML = `<p class="muted">${L('Geen DNS-verzoeken van {ip} in de query-log.', { ip: esc(x.ip) })}</p>` + (d.errors || []).map((e) => `<p class="bad">${esc(e)}</p>`).join(''); return; }
    box.innerHTML = `<p class="muted" style="margin:.3rem 0">${L('{n} verzoeken naar {u} domeinen, waarvan {b} geblokkeerd', { n: d.total, u: d.unique, b: d.blocked })}${d.since ? ' · ' + L('sinds {t}', { t: esc(fmtDateTime(d.since.slice(0, 19).replace('T', ' '))) }) : ''}</p>
      <div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Domein')}</th><th>${L('Aantal')}</th><th>${L('Laatst')}</th></tr></thead><tbody>
      ${d.domains.map((r) => `<tr><td class="mono">${r.blocked ? '⛔ ' : ''}${esc(r.domain)}</td><td>${r.n}</td><td>${esc(ago(r.last.slice(0, 19).replace('T', ' ')))}</td></tr>`).join('')}</tbody></table></div>`
      + (d.errors || []).map((e) => `<p class="bad">${esc(e)}</p>`).join('');
  } catch (ex) { box.innerHTML = `<p class="bad">${esc(ex.message)}</p>`; }
}

/** Weren (1.104.0): van het wifi (UniFi), geen DNS (AdGuard) en geen toegang tot RzSense en je servers */
function netBlockDialog(x) {
  const lk = netData.links || {};
  const canU = lk.unifi_block && x.mac, canA = lk.adguard;
  const opt = (k, on, can, lbl, why) => `<label class="switch"><input type="checkbox" name="${k}" ${on && can ? 'checked' : ''} ${can ? '' : 'disabled'}><span></span> <span>${lbl}${can ? '' : ` <span class="muted small">(${why})</span>`}</span></label>`;
  modal({
    title: '⛔ ' + L('{name} weren', { name: x.label }),
    body: `<p style="margin-top:0"><span class="mono">${esc(x.ip)}</span>${x.mac ? ` · <span class="mono">${esc(x.mac.toUpperCase())}</span>` : ''}${+x.random_mac ? ' · 🎭 ' + L('privé-adres: het apparaat kan een nieuw MAC-adres nemen') : ''}</p>
      ${opt('unifi', true, canU, '📶 ' + L('<b>UniFi</b>: niet meer op het wifi'), !lk.unifi_block ? L('UniFi niet ingesteld of alleen een API-sleutel') : L('geen MAC-adres bekend'))}
      ${opt('adguard', true, canA, '🌐 ' + L('<b>AdGuard</b>: geen DNS meer (geen internet via namen)'), L('AdGuard Home niet ingesteld'))}
      ${opt('rzmon', true, true, '🛡️ ' + L('<b>RzSense en je servers</b>: geen toegang tot RzSense, en geblokkeerd op je servers met agent'), '')}
      <div class="row2"><label>${L('Hoe lang')}<select name="days"><option value="1">${L('1 dag')}</option><option value="7">${L('7 dagen')}</option><option value="0">${L('Tot ik het weer toelaat')}</option></select></label>
        <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200"></label></div>
      ${xpHtml(L('Weren werkt niet voor elk apparaat.'), L('<b>Bedraad:</b> een bedraad apparaat haal je niet van het netwerk via UniFi-wifi; daarvoor zet je de poort op de switch uit. <b>Eigen DNS of vast IP:</b> zo\'n apparaat komt langs AdGuard.'))}
      ${confirmField('WEREN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⛔ ' + L('Weren'), cls: 'danger', submit: true }],
    onSubmit: async (d) => {
      const r = await api('net_block', { id: +x.id, unifi: !!d.unifi, adguard: !!d.adguard, rzmon: !!d.rzmon, days: +d.days, reason: d.reason, confirm: d.confirm });
      toast(L('Geweerd via {via}', { via: r.done.map((v) => ({ unifi: 'UniFi', adguard: 'AdGuard', rzmon: 'RzSense' }[v] || v)).join(', ') }) + (r.errors.length ? ' · ⚠️ ' + r.errors.join(' · ') : ''), r.errors.length ? 'err' : undefined);
      loadNet();
    },
  });
}

async function netUnblock(x) {
  if (!confirm(L('{name} weer toelaten? Alles wat bij het weren gedaan is, wordt teruggedraaid.', { name: x.label }))) return;
  try {
    const r = await api('net_unblock', { id: +x.id });
    toast(r.ok ? L('Weer toegelaten') : '⚠️ ' + (r.errors || []).join(' · '), r.ok ? undefined : 'err');
    $('#modal').close(); loadNet();
  } catch (ex) { toast(ex.message, 'err'); }
}

/** Instellingen → Netwerkscan: welke netwerken gescand worden */
async function loadNetscanSettings() {
  const box = $('#netscan-nets');
  if (!box) return;
  try {
    const d = await api('net_devices');
    const n = d.networks;
    box.innerHTML = `<p>${n.scan.length ? L('Wordt gescand: {list}', { list: n.scan.map((x) => `<code>${esc(x)}</code>`).join(', ') }) : `<b class="warn-txt">${L('Er is geen netwerk om te scannen.')}</b>`}
      ${n.skip.length ? `<br><span class="muted">${L('Overgeslagen: {list}', { list: n.skip.map((x) => `<code>${esc(x.net)}</code> (${esc(x.why)})`).join(', ') })}</span>` : ''}
      <br><span class="muted">${L('Aanpassen bij <a href="#settings/access" data-goto="settings/access">Toegang → Lokale netwerken</a>.')} ${d.vendors ? L('Fabrikantenlijst: {n} fabrikanten.', { n: d.vendors.toLocaleString(LOCALE) }) : ''}</span></p>`;
  } catch (ex) { box.textContent = ex.message; }
}

