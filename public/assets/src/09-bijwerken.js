// ---------------------------------------------------------------- RzSense bijwerken
let updatePoll = null;

/** Changelog-tekst (Markdown) als eenvoudige HTML: kopjes, opsommingen, vet, cursief en code. */
function mdLite(text) {
  const inline = (t) => esc(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\*(.+?)\*/g, '<i>$1</i>').replace(/`([^`]+)`/g, '<code>$1</code>');
  let html = '', depth = 0;
  for (const line of text.split('\n')) {
    const m = line.match(/^(\s*)- (.*)$/);
    const d = m ? Math.floor(m[1].length / 2) + 1 : 0;
    while (depth < d) { html += '<ul>'; depth++; }
    while (depth > d) { html += '</ul>'; depth--; }
    if (m) html += `<li>${inline(m[2])}</li>`;
    else if (/^### /.test(line)) html += `<h4>${inline(line.slice(4))}</h4>`;
    else if (line.trim()) html += `<p>${inline(line)}</p>`;
  }
  while (depth-- > 0) html += '</ul>';
  return html;
}

async function loadUpdate(force) {
  const st = $('#update-status');
  let u;
  try { u = await api('update_info', null, force ? { force: 1 } : {}); } catch (ex) { st.textContent = ex.message; return; }
  const parts = [L('Je gebruikt <b>{v}</b>.', { v: esc(u.current) })];
  if (u.error) parts.push(`<b class="warn-txt">⚠️ ${esc(u.error)}</b>`);
  else if (u.available) parts.push(`<b class="good-txt">${L('Nieuwe versie beschikbaar: {v}', { v: esc(u.latest) })}</b>`);
  else if (u.latest) parts.push(`<span class="good-txt">✔ ${L('Dit is de nieuwste versie.')}</span>`);
  if (u.checked_at) parts.push(`<span class="muted">${L('Gecontroleerd {ago}.', { ago: ago(u.checked_at) })}</span>`);
  if (u.available && u.unavailable) {
    parts.push(`<br><b class="warn-txt">${L('Bijwerken vanuit RzSense kan nog niet:')}</b> ${esc(u.unavailable)}. ${L('Voer één keer uit op de server:')}`
      + copyBox('cmd-update-setup', 'curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh && sudo bash install-rocky.sh'));
  }
  st.innerHTML = parts.join(' ');
  $('#update-notes').innerHTML = u.available && u.notes.length ? `<details class="update-notes" open><summary>${u.notes.length === 1 ? L('Wat is er nieuw (1 versie)') : L('Wat is er nieuw ({n} versies)', { n: u.notes.length })}</summary>
    ${u.notes.map((n) => `<div class="un-ver"><h3>${esc(n.version)} <span class="muted small">${esc(n.date)}</span></h3>${mdLite(n.text)}</div>`).join('')}</details>` : '';
  if (u.pinned) parts.push(`<br><span class="warn-txt">📌 ${L('Je hebt bewust versie {v} gekozen: automatisch bijwerken staat op pauze tot je weer naar de nieuwste gaat.', { v: esc(u.pinned) })}</span>`);
  st.innerHTML = parts.join(' ');
  $('#update-pick').hidden = !!u.unavailable || !(u.versions || []).some((v) => v.version !== u.current);
  const b = $('#update-start');
  b.hidden = !u.available || !!u.unavailable;
  b.textContent = `⬆ ${L('Nu bijwerken naar {v}', { v: u.latest })}`;
  updateInfo = u;
  settingsSummaries();
  showUpdateProgress(u.status, '');
  if (u.status && ['queued', 'running'].includes(u.status.state)) pollUpdate();
}

// 1.221.0: eerst ophalen, dan de back-up (snel of volledig, afhankelijk van de nieuwe versie)
const UPDATE_STEPS = [['check', L('Controleren')], ['download', L('Nieuwste versie ophalen')], ['backup', L('Back-up maken')], ['install', L('Installeren')], ['klaar', L('Klaar')]];

function showUpdateProgress(s, log) {
  const el = $('#update-progress');
  // Een afgeronde update van langer dan een dag geleden niet meer tonen
  if (!s || (['ok', 'error'].includes(s.state) && s.at < Date.now() / 1000 - 86400)) { el.hidden = true; return; }
  el.hidden = false;
  const cur = UPDATE_STEPS.findIndex(([k]) => k === s.step);
  const icon = (i) => s.state === 'ok' || i < cur ? '✔' : i === cur ? (s.state === 'error' ? '✘' : '⏳') : '·';
  el.innerHTML = `<div class="update-box ${s.state}">
    <b>${s.state === 'ok' ? '✔ ' : s.state === 'error' ? '✘ ' : '⏳ '}${esc(s.message || '')}</b>
    ${s.state === 'queued' && s.stuck ? `<p class="warn-txt small">${L('De hulpdienst reageert niet. Controleer op de server:')} <code>systemctl status rzmon-update.path</code></p>` : ''}
    ${s.state !== 'queued' ? `<ol class="update-steps">${UPDATE_STEPS.map(([k, l], i) => `<li class="${i === cur ? 'cur' : ''}">${icon(i)} ${l}</li>`).join('')}</ol>` : ''}
    ${log ? `<details ${s.state === 'error' ? 'open' : ''}><summary>${L('Logboek')}</summary><pre class="update-log">${esc(log)}</pre></details>` : ''}
  </div>`;
}

let updateFails = 0;   // opeenvolgende keren geen antwoord (RzSense wordt herstart)
let updatePollAt = 0;  // laatste vraag die een antwoord (of fout) kreeg; voor de waakhond hieronder
function pollUpdate() {
  clearTimeout(updatePoll);
  updatePoll = setTimeout(async () => {
    let r;
    // Hooguit 10 seconden wachten: tijdens het herstarten van PHP of Apache kan een verzoek anders blijven hangen
    try { r = await Promise.race([api('update_status'), new Promise((res, rej) => setTimeout(() => rej(new Error('timeout')), 10000))]); updateFails = 0; if (updDownAt) { updDownMs += Date.now() - updDownAt; updDownAt = 0; } }
    catch (ex) {   // even onbereikbaar tijdens het installeren
      updatePollAt = Date.now();
      updateFails++;
      if (!updDownAt) updDownAt = Date.now();
      const rn = $('#upd-now');
      if (rn) rn.hidden = false;
      pollUpdate();
      return;
    }
    updatePollAt = Date.now();
    // 1.206.1: een "klaar" van een update van vóór deze aanvraag (oude RzSense) is nog niet deze update
    if (r.status && r.status.state === 'ok' && updReqAt && ((+r.status.started || +r.status.at || 0) * 1000) < updReqAt - 30000) {
      r.status = { state: 'queued', step: 'queued', message: L('Wachten op de hulpdienst…'), at: updReqAt / 1000, stuck: Date.now() - updReqAt > 120000 };
    }
    showUpdateProgress(r.status, r.log);
    showUpdateDialog(r.status, r.log);
    if (r.status && ['queued', 'running'].includes(r.status.state)) return pollUpdate();
    if (r.status && r.status.state === 'ok') {
      toast(r.status.message);
      setTimeout(() => location.reload(), 3000);   // nieuwe versie laden
    }
  }, 1500);
}

// Waakhond: staat het venster open maar kwam er 20 seconden geen antwoord (bv. de telefoon sliep), dan opnieuw beginnen
setInterval(() => { if (updateDialogShown() && Date.now() - updatePollAt > 20000) { updatePollAt = Date.now(); pollUpdate(); } }, 5000);
document.addEventListener('visibilitychange', () => { if (!document.hidden && updateDialogShown()) pollUpdate(); });

// ---------------------------------------------------------------- Update-venster: vraag, voortgangsbalk en wat er gebeurt
// Alleen het venster van de RzSense-update; dat van pakketten bijwerken (Kwetsbaarheden) heeft dezelfde opmaak, maar is vu-dlg
const updateDialogShown = () => $('#modal').open && !!$('#modal-body .upd-dlg:not(.vu-dlg)');
let updatePct = 0;   // de balk gaat tijdens één update nooit terug

// 1.204.0: de bijwerk-vensters (RzSense en Pakketten bijwerken) in een nieuw jasje: kop met versies, ring, tijdlijn, terminal
const UPD_ARROW = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
const UPD_SRV = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/></svg>';
function updHead(title, chips = [], icon = UPD_ARROW) {
  return `<div class="upd-head"><span class="upd-ic">${icon}</span><div class="upd-ht"><b>${title}</b>${chips.length ? `<div class="upd-vers">${chips.join('<span class="upd-arrow">→</span>')}</div>` : ''}</div>
    <button type="button" class="upd-x icon-btn" value="cancel" formnovalidate aria-label="${esc(L('Sluiten'))}">✕</button></div>`;
}
const updChip = (v, isNew) => `<span class="upd-chip${isNew ? ' new' : ''}">${esc(v)}</span>`;
/** Voortgangsring; pct null = onbekend (een draaiende boog). */
function updRing(pct, sub) {
  const C = +(2 * Math.PI * 60).toFixed(2);
  const known = typeof pct === 'number';
  return `<div class="upd-ring${known ? '' : ' indet'}" role="progressbar" aria-valuemin="0" aria-valuemax="100" ${known ? `aria-valuenow="${pct}"` : ''}>
    <svg viewBox="0 0 140 140"><circle class="trk" cx="70" cy="70" r="60"/><circle class="val" cx="70" cy="70" r="60" stroke-dasharray="${C}" stroke-dashoffset="${known ? (C * (1 - pct / 100)).toFixed(2) : (C * 0.72).toFixed(2)}"/></svg>
    <div class="upd-pct"><b>${known ? pct + '%' : '…'}</b>${sub ? `<small>${sub}</small>` : ''}</div></div>`;
}
/** Tijdlijn van de stappen: klaar ✓, bezig (pulserend), mislukt ✕; detail onder de huidige stap, duur rechts. */
function updSteps(steps, cur, state, detail, durs = []) {
  return `<ol class="upd-tl">${steps.map(([, l], i) => {
    const cls = state === 'ok' || i < cur ? 'done' : i === cur ? (state === 'error' ? 'fail' : 'cur') : '';
    const mark = cls === 'done' ? '✓' : cls === 'fail' ? '✕' : i + 1;
    return `<li class="${cls}"><span class="upd-dot">${mark}</span><span class="upd-st">${l}${i === cur && detail && state !== 'ok' ? `<small>${detail}</small>` : ''}</span><span class="upd-dur">${durs[i] || ''}</span></li>`;
  }).join('')}</ol>`;
}
// 1.206.2: het venster wordt elke paar seconden opnieuw opgebouwd; wat je zelf open- of dichtklapte (logboek, Alles lezen)
// en waar je in het logboek stond, blijft zo staan
const updKept = { log: null, notes: null, scroll: null };
document.addEventListener('toggle', (e) => {
  const d = e.target;
  if (!d.closest || !d.closest('#modal-body .upd-dlg')) return;
  if (d.classList.contains('upd-term')) updKept.log = d.open;
  if (d.classList.contains('upd-notes')) updKept.notes = d.open;
}, true);
function updKeepScroll() {
  const lg = $('#modal-body .upd-log');
  // onderaan (of nog nooit gescrold) = mee naar beneden; anders de plek houden
  updKept.scroll = lg && lg.scrollTop + lg.clientHeight < lg.scrollHeight - 8 ? lg.scrollTop : null;
}
function updLogScroll() {
  const lg = $('#modal-body .upd-log');
  if (lg) lg.scrollTop = updKept.scroll === null ? lg.scrollHeight : updKept.scroll;
}
function updLog(log, open, n = 12) {
  if (updKept.log !== null) open = updKept.log;
  const lines = (log || '').trim().split('\n').filter(Boolean);
  if (!lines.length) return '';
  return `<details class="upd-term" ${open ? 'open' : ''}><summary><span>▸ ${L('Logboek')}</span><span>${L('{n} regels', { n: lines.length })}</span></summary><pre class="upd-log">${esc(lines.slice(-n).join('\n'))}</pre></details>`;
}
const updMmss = (ms) => { const s = Math.max(0, Math.round(ms / 1000)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`; };
const updSecs = (ms) => (ms >= 60000 ? updMmss(ms) : L('{n} s', { n: Math.max(1, Math.round(ms / 1000)) }));
/** Highlights uit de changelog: de vetgedrukte punten (eerst Nieuw), als kaartjes. */
function updHighlights(notes) {
  const out = [];
  const plain = (t) => String(t).replace(/\*\*|`/g, '').replace(/\*(.+?)\*/g, '$1').trim();
  for (const n of notes || []) {
    let sec = '';
    for (const line of String(n.text || '').split('\n')) {
      if (/^### /.test(line)) { sec = line.slice(4).trim(); continue; }
      const m = line.match(/^- \*\*(.+?)\*\*[:\s]*(.*)$/) || line.match(/^- (.+?)()$/);
      if (!m) continue;
      const t = plain(m[1]);
      const lead = (t.match(EMOJI_LEAD) || [''])[0].trim();
      let d = plain(m[2]);
      if (d.length > 110) d = d.slice(0, 107).replace(/\s+\S*$/, '') + '…';
      out.push({ v: n.version, fix: /opgelost|fixed/i.test(sec), ic: lead || (/opgelost|fixed/i.test(sec) ? '🛠️' : '✨'), t: t.replace(EMOJI_LEAD, '').replace(/[:.]$/, ''), d });
    }
  }
  return out.sort((a, b) => a.fix - b.fix).slice(0, 3);
}
function updNotesBlock(notes) {
  if (!(notes || []).length) return '';
  const hl = updHighlights(notes);
  const all = notes.length === 1 ? L('Alles lezen (1 versie)') : L('Alles lezen ({n} versies)', { n: notes.length });
  return `<div class="upd-news"><div class="upd-news-h"><span>${L('Wat is er nieuw')}</span></div>
    ${hl.map((h) => `<div class="upd-hl"><span class="upd-hl-ic">${esc(h.ic)}</span><div><b>${esc(h.t)}</b>${h.d ? `<span>${esc(h.d)}</span>` : ''}</div><em>${esc(h.v)}</em></div>`).join('')}
    <details class="update-notes upd-notes"><summary>${all}</summary>
      ${notes.map((n) => `<div class="un-ver"><h3>${esc(n.version)} <span class="muted small">${esc(n.date)}</span></h3>${mdLite(n.text)}</div>`).join('')}</details></div>`;
}

// Tijden van deze update (in de browser): wanneer elke stap begon, en hoe lang RzSense onbereikbaar was
let updT = {}, updT0 = 0, updEnd = 0, updDownAt = 0, updDownMs = 0, updBackup = null, updReqAt = 0;
function updResetTimes() { updT = {}; updT0 = Date.now(); updEnd = 0; updDownAt = 0; updDownMs = 0; }

/** Vraag "wil je bijwerken?" (met back-up), of meteen de voortgang als er al een update loopt. */
async function openUpdateDialog(pick = false) {
  let u = updateInfo;
  try { u = await api('update_info'); updateInfo = u; } catch (ex) { if (!u) { toast(ex.message, 'err'); return; } }
  if (u.status && ['queued', 'running'].includes(u.status.state)) { showUpdateDialog(u.status, '', true); pollUpdate(); return; }
  const versions = (u.versions || []).filter((v) => v.version !== u.current);
  if ((!u.available && !(pick && versions.length)) || u.unavailable) {
    // Niets bij te werken, of het kan (nog) niet vanuit RzSense: de uitleg staat bij Instellingen
    showTab('settings/update');
    return;
  }
  const to = u.available ? u.latest : '?';
  modal({
    title: L('RzSense bijwerken'),
    body: `<div class="upd-dlg upd-ask">
      ${updHead(L('RzSense bijwerken'), [updChip(u.current), `<span id="upd-to">${updChip(to, true)}</span>`])}
      <div class="upd-body">
        ${updNotesBlock(u.notes)}
        <div class="upd-opts">
          <label class="upd-opt"><span class="grow">${L('Eerst een back-up maken')}<small>${L('Database en instellingen. Aanbevolen.')}</small></span>
            <span class="switch"><input type="checkbox" name="backup" ${u.backup ? 'checked' : ''}><span></span></span></label>
          ${versions.length ? `<label class="upd-opt"><span class="grow">${L('Versie')}<small>${L('Een oudere kiezen = terugzetten (met bevestigen)')}</small></span>
            <select name="version"><option value="">${L('De nieuwste ({v})', { v: esc(u.latest || '?') })}</option>
              ${versions.map((v) => `<option value="${esc(v.version)}">${esc(v.version)}${v.date ? ` – ${esc(v.date)}` : ''}${verCmp(v.version, u.current) < 0 ? ` · ${L('ouder: terugzetten')}` : ''}</option>`).join('')}</select></label>` : ''}
        </div>
        ${versions.length ? `<div id="upd-older" class="callout small" hidden>${L('⚠️ <b>Terugzetten naar een oudere versie.</b> Je instellingen en gegevens blijven staan; de database blijft zoals hij is (nieuwere kolommen worden niet gebruikt). Wat er sindsdien bij kwam, werkt niet meer. Automatisch bijwerken pauzeert tot je weer naar de nieuwste gaat. Agents hoef je niet terug te zetten.')}
          ${confirmField('TERUGZETTEN')}</div>` : ''}
        <div class="upd-facts"><span>${L('RzSense is tijdens het installeren even niet bereikbaar')}</span><span>${L('Agents houden hun metingen vast en geven tot {n} min geen alarm', { n: u.hold_min || 5 })}</span><span>${L('Dit venster laat zien hoe ver het is')}</span></div>
        ${(u.hold_old || []).length ? `<p class="callout small">⚠️ ${L('Deze agents zijn ouder dan {v} of onversleuteld en kunnen tijdens de update alarm geven: {names}', { v: '1.82.0', names: esc(u.hold_old.slice(0, 8).join(', ') + (u.hold_old.length > 8 ? ' …' : '')) })}</p>` : ''}
      </div></div>`,
    buttons: [
      { label: L('Annuleren'), onClick: () => $('#modal').close() },
      { label: '⬆ ' + (u.available ? L('Bijwerken naar {v}', { v: u.latest }) : L('Bijwerken')), cls: 'primary', onClick: async (btn) => {
        const f = $('#modal-form');
        const backup = f.backup.checked;
        const version = f.version ? f.version.value : '';
        btn.disabled = true;
        try {
          await api('update_start', { backup, version, confirm: f.confirm ? f.confirm.value : '' });
          if ($('#settings-form')) $('#settings-form').update_backup.checked = backup;   // keuze is opgeslagen
          updatePct = 0;
          updResetTimes();
          updReqAt = Date.now();   // 1.206.1
          updBackup = backup;
          const s = { state: 'queued', step: 'queued', message: L('Wachten op de hulpdienst…'), at: Date.now() / 1000, from: u.current, to: version || u.latest };
          showUpdateDialog(s, '', true);
          showUpdateProgress(s, '');
          pollUpdate();
        } catch (ex) { modalError(ex.message); btn.disabled = false; }
      } },
    ],
  });
  $('#modal').classList.add('upd-modal');
  // Een andere versie gekozen: de chip mee, en bij een oudere de waarschuwing en bevestigen
  const sel = $('#modal-form').version;
  if (sel) sel.onchange = () => {
    $('#upd-older').hidden = !(sel.value && verCmp(sel.value, u.current) < 0);
    $('#upd-to').innerHTML = updChip(sel.value || u.latest || '?', true);
  };
}

/** Percentage van de balk: van het script (pct), anders een schatting per stap. */
function updatePercent(s) {
  if (s.state === 'ok') return 100;
  const guess = { queued: 2, check: 4, backup: 10, download: 30, install: 45, klaar: 100 }[s.step] ?? 2;
  updatePct = Math.max(updatePct, typeof s.pct === 'number' ? s.pct : guess);
  return updatePct;
}

/** Het voortgangsvenster bijwerken (of openen, als er geen ander venster openstaat). */
function showUpdateDialog(s, log, force = false) {
  if (!s) return;
  if (!force && $('#modal').open && !updateDialogShown()) return;   // niet over een ander venster heen
  const now = Date.now();
  if (!updT0) updT0 = s.at ? Math.min(now, s.at * 1000) : now;
  const pct = updatePercent(s);
  const cur = s.state === 'ok' ? UPDATE_STEPS.length - 1 : UPDATE_STEPS.findIndex(([k]) => k === s.step);
  if (cur >= 0 && !updT[cur]) updT[cur] = now;
  if (s.state !== 'running' && s.state !== 'queued' && !updEnd) updEnd = now;
  // Duur per afgeronde stap (alleen als we het begin van die stap en de volgende gezien hebben)
  const durs = UPDATE_STEPS.map((_, i) => (updT[i] && updT[i + 1] && i < cur ? updSecs(updT[i + 1] - updT[i]) : i === cur && s.state === 'running' ? '…' : ''));
  // Waar de installatie nu mee bezig is: bij Installeren het onderdeel van het installatiescript
  const part = (s.detail || '').match(/^(\d+\/\d+) (.*)$/);
  const detail = s.state === 'queued' ? (s.agents ? esc(s.message || '') : L('Wachten op de hulpdienst…'))   // 1.205.0: eerst de agents waarschuwen
    : s.state === 'error' ? esc(s.message || L('Bijwerken mislukt'))
    : part ? L('Onderdeel {n}: {what}', { n: esc(part[1].replace('/', ' ' + L('van') + ' ')), what: esc(L(part[2])) }) : esc(s.message || '');
  const chips = s.from && s.to ? [updChip(s.from), updChip(s.to, true)] : s.to ? [updChip(s.to, true)] : [];
  const elapsed = updMmss((updEnd || now) - updT0);
  let body;
  if (s.state === 'ok') {
    const took = updEnd - updT0;
    body = `<div class="upd-dlg ok">${updHead(L('Bijgewerkt'), chips)}
      <div class="upd-body">
        <div class="upd-done"><span class="upd-check"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
          <b>${L('RzSense draait nu op {v}', { v: esc(s.to || '') })}</b><span class="muted">${L('De pagina wordt over 3 seconden herladen.')}</span></div>
        <div class="upd-stats">
          <div><b>${took > 1500 ? updSecs(took) : '–'}</b><small>${L('duur')}</small></div>
          <div><b>${updBackup === null ? '–' : updBackup ? '✓' : '✕'}</b><small>${L('back-up gemaakt')}</small></div>
          <div><b>${updDownMs ? updSecs(updDownMs) : '–'}</b><small>${L('niet bereikbaar')}</small></div>
        </div>
        ${s.detail ? `<p class="muted small upd-durs">${esc(s.detail)}</p>` : ''}
        <div class="upd-reload"><i></i></div>
      </div></div>`;
  } else {
    const nothing = s.state === 'error' && cur >= 0 && cur < UPDATE_STEPS.findIndex(([k]) => k === 'install');
    body = `<div class="upd-dlg ${s.state}">${updHead(s.state === 'error' ? L('Bijwerken mislukt') : L('Bezig met bijwerken…'), chips)}
      <div class="upd-body">
        <div class="upd-prog">${updRing(pct, s.state === 'error' ? L('gestopt') : L('{t} verstreken', { t: elapsed }))}${updSteps(UPDATE_STEPS, Math.max(cur, 0), s.state === 'queued' ? 'running' : s.state, detail, durs)}</div>
        <div class="upd-restart" id="upd-now" ${updateFails ? '' : 'hidden'}><i></i>${L('RzSense wordt herstart; dit venster gaat vanzelf verder zodra hij terug is.')}</div>
        ${s.state === 'queued' && s.stuck ? `<p class="warn-txt small">${L('De hulpdienst reageert niet. Controleer op de server:')} <code>systemctl status rzmon-update.path</code></p>` : ''}
        ${s.state === 'error' ? `<div class="upd-err"><b>${nothing ? L('Er is niets veranderd.') : L('Het bijwerken is gestopt.')}</b>
          <span>${nothing ? L('Je draait nog steeds op {v}.', { v: esc(s.from || (updateInfo && updateInfo.current) || '') }) : L('Kijk in het logboek wat er misging.')}${updBackup ? ' ' + L('De back-up van vandaag staat bij Instellingen → Back-up.') : ''}</span></div>` : ''}
        ${s.stale ? `<p class="small">${L('Controleer op de server:')} <code>systemctl status rzmon-update.service</code> ${L('en')} <code>tail -30 /var/lib/rzmon/update.log</code></p>` : ''}
        ${updLog(log, s.state === 'error')}
      </div></div>`;
  }
  if (updateDialogShown()) updKeepScroll(); else { updKept.log = null; updKept.scroll = null; }
  modal({
    title: L('RzSense bijwerken'),
    body,
    buttons: s.state === 'ok' ? [{ label: L('Nu herladen'), cls: 'primary', onClick: () => location.reload() }]
      : s.state === 'error' ? [{ label: L('Sluiten'), onClick: () => $('#modal').close() }, { label: '↻ ' + L('Opnieuw proberen'), cls: 'primary', onClick: () => { $('#modal').close(); openUpdateDialog(); } }]
      : [{ label: L('Op de achtergrond'), onClick: () => $('#modal').close() }],
  });
  const dlg = $('#modal');
  dlg.classList.add('upd-modal');
  const ft = $('#modal-footer');
  if (s.state === 'running' || s.state === 'queued') ft.insertAdjacentHTML('afterbegin', `<span class="upd-note">${L('Je kunt dit venster sluiten; het bijwerken gaat door.')}</span>`);
  updLogScroll();
}

// ---------------------------------------------------------------- Back-up
let backupInfo = null;
let backupUnlockedUntil = 0;   // downloaden zonder opnieuw te bevestigen tot dit moment
const fmtSize = (b) => (b >= 1048576 ? `${dec((b / 1048576).toFixed(1))} MB` : `${Math.max(1, Math.round(b / 1024))} kB`);
const fmtDateTime = (dt) => new Date(dt.replace(' ', 'T')).toLocaleString(LOCALE,
  { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

function backupFormToggle() {
  const f = $('#settings-form');
  $('.backup-weekday', f).hidden = f.backup_freq.value !== 'weekly';
  $('.update-time', f).hidden = !f.update_auto.checked;   // tijdstip alleen bij automatisch bijwerken
}

async function loadBackups() {
  const st = $('#backup-status');
  try { backupInfo = await api('backup_info'); } catch (ex) { st.textContent = ex.message; return; }
  const b = backupInfo;
  backupUnlockedUntil = Date.now() + b.unlocked * 1000 - 5000;
  ['#backup-now', '#backup-upload'].forEach((id) => ($(id).disabled = !!b.problem));
  if (b.problem) {
    st.innerHTML = `<b class="warn-txt">⚠️ ${esc(b.problem)}.</b> ${L('Voer dit één keer uit op de server:')}${copyBox('cmd-backup-fix', b.fix_cmd)}`;
    wireCopy(st);
    $('#backup-list').innerHTML = '';
    return;
  }
  const parts = [];
  if (b.last_error) parts.push(`<b class="bad">✘ ${L('Automatische back-up mislukt: {err}', { err: esc(b.last_error) })}</b> ${L('(RzSense probeert het elk uur opnieuw)')}`);
  const lastAuto = b.list.find((x) => x.type === 'auto');
  if (lastAuto) parts.push(L('Laatste automatische: <b>{ago}</b>', { ago: ago(lastAuto.at) }));
  parts.push(b.next ? L('Volgende: {date}', { date: fmtDateTime(b.next) }) : L('Automatische back-up staat uit'));
  if (!b.has_pass) parts.push(`<b class="warn-txt">⚠️ ${L('Geen zip-wachtwoord: back-ups worden niet versleuteld')}</b>`);
  if (!b.encryption) parts.push(`<b class="warn-txt">⚠️ ${L('Deze PHP kan geen versleutelde zip maken (libzip te oud)')}</b>`);
  st.innerHTML = parts.join(' · ');
  $('#backup-list').innerHTML = b.list.length ? b.list.map((x) => `
    <div class="key-row">
      <span class="grow"><strong>${fmtDateTime(x.at)}</strong> <span class="muted small">· ${esc(x.type_label)}</span><br>
        <span class="muted small">${fmtSize(x.size)} · ${x.encrypted ? '🔒 ' + L('versleuteld') : '🔓 ' + L('niet versleuteld')}${x.history ? '' : ' · ' + L('zonder meethistorie')}${x.version ? ` · v${esc(x.version)}` : ''}${x.server ? ` · ${esc(x.server)}` : ''}${x.valid ? '' : ` · <b class="bad">${L('beschadigd')}</b>`}</span></span>
      <button type="button" class="icon-btn" title="${L('Downloaden als zip')}" data-bk="dl" data-file="${esc(x.file)}">⬇</button>
      <button type="button" class="icon-btn" title="${L('Terugzetten')}" data-bk="restore" data-file="${esc(x.file)}" ${x.valid ? '' : 'disabled'}>↺</button>
      <button type="button" class="icon-btn" title="${L('Verwijderen')}" data-bk="del" data-file="${esc(x.file)}">🗑</button>
    </div>`).join('') : `<p class="muted small">${L('Nog geen back-ups.')}</p>`;
  settingsSummaries();
}

async function backupNow(e) {
  const b = e.currentTarget;
  b.disabled = true;
  b.textContent = '⏳ ' + L('Bezig…');
  try {
    const r = await api('backup_create', {});
    toast(L('Back-up gemaakt ({size})', { size: fmtSize(r.size) }));
  } catch (ex) { toast(L('Back-up') + ': ' + ex.message, 'err'); }
  b.disabled = false;
  b.textContent = '💾 ' + L('Nu back-up maken');
  loadBackups();
}

async function onBackupListClick(e) {
  const b = e.target.closest('[data-bk]');
  if (!b) return;
  const file = b.dataset.file;
  if (b.dataset.bk === 'dl') backupDownload(file);
  else if (b.dataset.bk === 'restore') backupRestore(file);
  else if (b.dataset.bk === 'del') {
    if (!confirm(L('Deze back-up verwijderen?'))) return;
    try { await api('backup_delete', { file }); toast(L('Back-up verwijderd')); }
    catch (ex) { toast(ex.message, 'err'); }
    loadBackups();
  }
}

function backupDownload(file) {
  const go = () => {
    const a = document.createElement('a');
    a.href = 'api.php?' + new URLSearchParams({ action: 'backup_download', file });
    a.download = file;
    document.body.append(a);
    a.click();
    a.remove();
  };
  if (Date.now() < backupUnlockedUntil) return go();
  modal({
    title: '⬇ ' + L('Back-up downloaden'),
    body: `<p style="margin-top:0">${L('De zip bevat ook <code>config.php</code> met het databasewachtwoord. Bevestig daarom eerst; daarna kun je 10 minuten downloaden zonder opnieuw te bevestigen.')}</p>
      ${backupInfo?.has_pass ? '' : `<p class="callout small">⚠️ ${L('Deze back-up is niet versleuteld. Stel een zip-wachtwoord in voor de volgende back-ups.')}</p>`}
      ${confirmField('DOWNLOADEN')}`,
    buttons: [{ label: '⬇ ' + L('Downloaden'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('backup_unlock', { confirm: d.confirm });
      backupUnlockedUntil = Date.now() + r.unlocked * 1000 - 5000;
      setTimeout(go, 50);
    },
  });
}

function backupRestore(file) {
  const x = (backupInfo?.list || []).find((b) => b.file === file);
  if (!x) return;
  modal({
    title: '↺ ' + L('Back-up terugzetten'),
    body: `<p style="margin-top:0">${L('De database gaat terug naar <b>{date}</b>{server}.', { date: fmtDateTime(x.at), server: x.server ? ' ' + L('(server {name})', { name: esc(x.server) }) : '' })}
        ${L('Wat daarna is veranderd aan servers, checks, instellingen en gebruikers gaat verloren.')}</p>
      <ul class="small">
        <li>${L('RzSense maakt eerst een <b>veiligheidsback-up</b> van nu. Daarmee kun je altijd terug.')}</li>
        <li>${L('Het back-upschema en het zip-wachtwoord blijven zoals ze nu zijn.')}</li>
        ${x.history ? '' : `<li>${L('Deze back-up is zonder meethistorie: de huidige grafieken blijven staan.')}</li>`}
        <li>${L('Gebruikers en wachtwoorden worden die uit de back-up; misschien moet je opnieuw inloggen.')}</li>
        <li>${L('<code>config.php</code> wordt hier niet teruggezet. Voor een nieuwe server: zie de handleiding.')}</li>
      </ul>
      ${x.encrypted ? `<label>${L('Wachtwoord van deze zip')} <span class="hint">${L('(leeg = het ingestelde zip-wachtwoord)')}</span><input name="zip_pass" type="password" autocomplete="off"></label>` : ''}
      ${confirmField('HERSTELLEN')}`,
    buttons: [{ label: '↺ ' + L('Terugzetten'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('backup_restore', { ...d, file });
      setTimeout(() => modal({
        title: '✔ ' + L('Back-up teruggezet'),
        body: `<p style="margin-top:0">${L('De database is teruggezet.')}${r.safety ? ' ' + L('De toestand van daarvoor staat in de veiligheidsback-up {file}.', { file: `<code>${esc(r.safety)}</code>` }) : ''}</p>
          <p class="muted small">${L('De pagina wordt opnieuw geladen.')}</p>`,
        buttons: [{ label: 'OK', cls: 'primary', submit: true }],
        onSubmit: async () => location.reload(),
      }), 50);
    },
  });
}

/** Zip uploaden in stukken van 1 MB (dan maakt de upload-limiet van PHP niet uit). */
async function backupUpload(file) {
  if (!file) return;
  const btn = $('#backup-upload');
  const CHUNK = 1024 * 1024;
  if (file.size > (backupInfo?.max_upload || Infinity)) { toast(L('Bestand is te groot (maximaal 2 GB)'), 'err'); return; }
  const id = [...crypto.getRandomValues(new Uint8Array(8))].map((v) => v.toString(16).padStart(2, '0')).join('');
  btn.disabled = true;
  try {
    let r = null;
    for (let off = 0; ; off += CHUNK) {
      const final = off + CHUNK >= file.size;
      btn.textContent = `⬆ ${Math.round((Math.min(off + CHUNK, file.size) / (file.size || 1)) * 100)}%`;
      const res = await fetch('api.php?' + new URLSearchParams({ action: 'backup_upload', id, offset: off, final: final ? '1' : '0' }), {
        method: 'POST',
        headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': CSRF },
        body: file.slice(off, off + CHUNK),
        credentials: 'same-origin',
      });
      r = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
      if (!res.ok) throw new Error(r.error || `HTTP ${res.status}`);
      if (final) break;
    }
    toast(L('Zip geüpload'));
    await loadBackups();
    backupRestore(r.file);
  } catch (ex) { toast('Upload: ' + ex.message, 'err'); }
  btn.disabled = false;
  btn.textContent = '⬆ ' + L('Zip uploaden');
  $('#backup-file').value = '';
}

