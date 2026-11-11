// ---------------------------------------------------------------- ⓘ Veldhulp (1.241.0)
// Eén bouwsteen voor uitleg bij een invulveld: tik op ⓘ en je ziet wat het veld doet, in welke eenheid, het bereik,
// wat 0 of leeg betekent en de aanbevolen waarde (met een knop om die te gebruiken). Naast grote getallen staat wat
// het betekent ("≈ 1 dag"). Uitleg in title="…" (alleen zichtbaar met een muis) wordt ook een ⓘ, zodat hij ook op
// een telefoon te lezen is. De velden staan in FH(); een veld zonder eigen uitleg maar met title krijgt die uitleg.

/** Eenheden: [enkelvoud, meervoud] */
const FH_U = () => ({
  s: [L('seconde'), L('seconden')], min: [L('minuut'), L('minuten')], h: [L('uur'), L('uur')], d: [L('dag'), L('dagen')],
  n: ['', ''], pct: ['%', '%'], mb: ['MB', 'MB'], gb: ['GB', 'GB'], tok: ['token', 'tokens'], eur: ['€', '€'], x: ['×', '×'], score: ['', ''],
});
const fhUnit = (u, v) => { const w = FH_U()[u]; return w ? (+v === 1 ? w[0] : w[1]) : ''; };

let fhCache = null;
/**
 * Per veld: h = uitleg, u = eenheid, d = aanbevolen waarde (in de eenheid van het veld), z = wat 0 of leeg betekent,
 * show = [factor, eenheid]: toon het veld in een andere eenheid dan het wordt opgeslagen (bv. uren als dagen).
 */
function FH() {
  if (fhCache) return fhCache;
  fhCache = {
    // Alarmgedrag
    confirm_fails: { u: 'n', z: L('leeg = meteen'), d: 2, h: L('Hoeveel controles achter elkaar mis moeten gaan voor een check warning of critical wordt. Voorkomt een rode check door één hapering.') },
    confirm_ok: { u: 'n', z: L('leeg = meteen'), d: 1, h: L('Hoeveel goede controles achter elkaar nodig zijn voor een check weer OK is. Hoger voorkomt heen-en-weer (flapping).') },
    notify_fails: { u: 'n', d: 1, h: L('Pas een melding na zoveel mislukte checks achter elkaar. 1 = bij de eerste. Per check aan te passen.') },
    notify_group_seconds: { u: 's', z: L('0 = niet bundelen'), d: 60, h: L('Gaan op één server binnen deze tijd meerdere checks mis, dan krijg je één bericht in plaats van losse berichten.') },
    esc_warn_min: { u: 'min', z: L('0 = nooit'), d: 30, h: L('Een warning die niemand bevestigt, wordt na deze tijd opnieuw gemeld als critical.') },
    esc_crit_min: { u: 'min', z: L('0 = nooit'), d: 15, h: L('Een critical die niemand bevestigt, wordt na deze tijd opnieuw gemeld met hoge prioriteit of als nood.') },
    disk_forecast_days: { u: 'd', z: L('0 = uit'), d: 7, h: L('Waarschuwt als een disk in dit tempo binnen zoveel dagen vol is. 7 dagen geeft tijd om op te ruimen.') },
    trend_alert_days: { u: 'd', z: L('0 = uit'), d: 30, h: L('Meldt als een trend (Analyse → Trends) binnen zoveel dagen een grens bereikt.') },
    trend_ups_min: { u: 'min', d: 10, h: L('De kortste looptijd op de accu die nog goed is. Daalt de looptijd richting deze waarde, dan zie je dat bij Trends.') },
    log_window: { u: 'min', d: 15, h: L('Een logcheck telt de treffers over deze laatste periode.') },
    auto_maint_after: { u: 'n', d: 3, h: L('Na zoveel meldingen over dezelfde storing gaat alleen die check in onderhoud. Hij stopt vanzelf als hij weer OK is.') },
    smart_fast: { u: 's', d: 20, h: L('Twijfelt een snelle check (ping, TCP, HTTP, SNMP), dan controleert RzSense hem zo vaak opnieuw tot het zeker is.') },
    smart_slow: { u: 'x', d: 2, h: L('Is een check lang stabiel, dan mag hij tot zoveel keer zijn gewone interval wachten. Nooit trager dan een uur.') },
    disk_jump_pct: { u: 'pct', d: 5, h: L('Procentpunten groei in één uur waarbij je een ℹ️-melding krijgt, bv. door een vastgelopen log.') },
    agent_stale_minutes: { u: 'min', d: 5, h: L('Stuurt een agent zo lang niets, dan staat hij op offline en volgt een melding.') },
    // AI
    ai_daily: { u: 'n', z: L('0 = geen limiet'), d: 50, h: L('Hoeveel vragen RzSense per dag aan de AI mag stellen. Beschermt je tegoed.') },
    ai_timeout: { u: 's', d: 120, h: L('Zo lang wacht RzSense op een antwoord. Lokale modellen (Ollama) hebben vaak meer tijd nodig.') },
    ai_max_tokens: { u: 'tok', d: 1500, h: L('Het langste antwoord. Meer tokens = uitgebreider en duurder.') },
    ai_cache_hours: { u: 'h', d: 6, h: L('Dezelfde vraag binnen deze tijd krijgt het bewaarde antwoord, zonder nieuwe kosten.') },
    ai_triage_max: { u: 'n', z: L('0 = alleen regels en eerdere oordelen'), d: 20, h: L('Hoeveel IDS-gebeurtenissen per dag de AI mag beoordelen.') },
    ai_budget_month: { u: 'eur', z: L('0 = geen budget'), h: L('Boven dit bedrag per maand stelt RzSense geen vragen meer aan de AI.') },
    // IDS en bescherming
    ids_baseline_hours: { u: 'h', show: [24, 'd'], d: 24, h: L('De eerste tijd legt het IDS alleen vast wat normaal is en meldt het nog niets. Zelfde eenheid als de andere leerperiodes.') },
    ids_fail_count: { u: 'n', d: 10, h: L('Zoveel mislukte SSH-logins van één adres binnen de tijd hiernaast tellen als brute force.') },
    ids_fail_minutes: { u: 'min', d: 10, h: L('De periode waarbinnen de mislukte logins worden geteld.') },
    ids_block_hours: { u: 'h', d: 24, h: L('Zo lang blijft een aanvaller geblokkeerd.') },
    ids_repeat_days: { u: 'd', d: 7, h: L('Komt hetzelfde adres binnen deze tijd terug, dan wordt de blokkade langer.') },
    edr_learn_days: { u: 'd', d: 14, h: L('Zo lang leert RzSense wat normaal is op een server, voor het afwijkingen meldt.') },
    edr_anom_min: { u: 'score', d: 40, h: L('Afwijkingen vanaf deze score (1–100) worden een incident; lager zie je alleen op de pagina.') },
    edr_chain_min: { u: 'min', d: 60, h: L('Stappen van een aanval binnen deze tijd worden samen één keten.') },
    ti_block_score: { u: 'score', d: 80, h: L('Vanaf deze score (0–100) geldt een adres als bekende aanvaller en kan het meteen geblokkeerd worden.') },
    dns_nrd_days: { u: 'd', d: 30, h: L('Een domein dat jonger is dan dit, geldt als nieuw (vaak gebruikt voor phishing).') },
    wl_minutes: { u: 'min', d: 10, h: L('De periode waarbinnen de webverzoeken en logins hierboven worden geteld.') },
    sudo_max: { u: 'min', d: 240, h: L('Langer dan dit kan tijdelijk sudo nooit aan staan.') },
    appctl_learn: { u: 'd', d: 7, z: L('0 = niet leren'), h: L('Zo lang leert RzSense welke programma\'s normaal zijn, voor het onbekende meldt.') },
    cve_buzz_alert: { u: 'score', d: 60, h: L('Melden als er over een kwetsbaarheid zoveel te doen is (0–100), ook voor hij als actief misbruikt bekend is.') },
    login_lock_fails: { u: 'n', d: 3, h: L('Na zoveel mislukte inlogpogingen op RzSense volgt een blokkade.') },
    login_lock_window: { u: 'min', d: 10, h: L('De periode waarbinnen de mislukte pogingen worden geteld.') },
    login_lock_minutes: { u: 'min', z: L('0 = alleen een beheerder heft hem op'), d: 60, h: L('Na deze tijd wordt de blokkade vanzelf opgeheven.') },
    confirm_grace_min: { u: 'min', d: 60, h: L('Zo lang hoef je je wachtwoord niet opnieuw in te vullen voor een gevoelige actie.') },
    update_hold_min: { u: 'min', d: 5, h: L('Tijdens een update van RzSense wachten de agents hooguit zo lang met rapporteren.') },
    // Logs, internet, bewaren
    logs_interval: { u: 'min', d: 5, h: L('Zo vaak sturen de agents hun logs. Per server aan te passen.') },
    logs_max_gb: { u: 'gb', d: 5, h: L('Zoveel ruimte mogen de logs in totaal gebruiken; daarboven gaat de oudste dag weg.') },
    logs_day_mb: { u: 'mb', d: 200, h: L('Zoveel logs per server per dag (ongecomprimeerd); daarna tot middernacht niets meer.') },
    inet_warn_pct: { u: 'pct', d: 70, h: L('Warning als de snelheid onder dit deel van je abonnement komt.') },
    inet_crit_pct: { u: 'pct', d: 40, h: L('Critical als de snelheid onder dit deel van je abonnement komt.') },
    inet_speed_mb: { u: 'mb', d: 250, h: L('Zoveel data gebruikt één snelheidstest hooguit per richting.') },
    backup_keep: { u: 'n', d: 7, h: L('Zoveel automatische back-ups blijven bewaard; de oudste gaat weg.') },
    backup_keep_manual: { u: 'n', z: L('0 = alles bewaren'), d: 0, h: L('Zoveel back-ups die je zelf maakte, blijven bewaard.') },
    retention_days: { u: 'd', d: 30, h: L('Zo lang blijft elke meting bewaard; daarna alleen het uurgemiddelde.') },
    hourly_days: { u: 'd', d: 180, h: L('Zo lang blijven de uurgemiddelden bewaard (grafieken tot zo ver terug).') },
    daily_days: { u: 'd', d: 730, h: L('Zo lang blijven de daggegevens bewaard (Analyse en Dashboard).') },
    alerts_days: { u: 'd', h: L('Zo lang blijven meldingen op de pagina Meldingen.') },
    logs_days: { u: 'd', d: 14, h: L('Zo lang blijven de logs van je servers bewaard.') },
    // Vensters
    hb_grace: { u: 'min', h: L('Zo lang mag een heartbeat te laat zijn voor hij warning wordt; na twee keer deze tijd critical.') },
    cmd_timeout: { u: 's', d: 10, h: L('Zo lang mag het commando duren; daarna wordt het gestopt en is de check onbekend.') },
    db_longq: { u: 's', d: 300, h: L('Een query die langer loopt, telt als "te lang".') },
  };
  return fhCache;
}

/** Wat een getal in een grotere eenheid betekent ("≈ 1,5 dag"); leeg als dat niets toevoegt */
function fhHuman(v, u) {
  v = +v;
  const s = { s: 1, min: 60, h: 3600, d: 86400 }[u];
  if (!s || !isFinite(v) || v <= 0) return '';
  const t = v * s, D = 86400, H = 3600, Y = 365 * D;
  const r = (x) => dec(Math.round(x * 10) / 10);
  if (t >= Y) return `≈ ${r(t / Y)} ${L('jaar')}`;
  if (u === 'd' && t >= 60 * D) return `≈ ${r(v / 30.4)} ${L('maanden')}`;
  if (u !== 'd' && t >= D) return `≈ ${r(t / D)} ${t === D ? L('dag') : L('dagen')}`;
  if ((u === 'min' || u === 's') && t >= 2 * H) return `≈ ${r(t / H)} ${L('uur')}`;
  if (u === 's' && t >= 120) return `≈ ${r(t / 60)} ${L('minuten')}`;
  return '';
}

/** Een veld in een andere eenheid tonen dan het wordt opgeslagen (bv. uren als dagen); het echte veld blijft verborgen */
function fhScale(el, f, unit) {
  const proto = Object.getPrototypeOf(el);
  const desc = Object.getOwnPropertyDescriptor(proto, 'value');
  const p = document.createElement('input');
  p.type = 'number';
  p.className = el.className;
  p.step = '0.25';
  if (el.min !== '') p.min = String(+el.min / f);
  if (el.max !== '') p.max = String(+el.max / f);
  p.setAttribute('aria-label', fhUnit(unit, 2));
  el.after(p);
  el.hidden = true;
  const show = () => { const v = desc.get.call(el); p.value = v === '' ? '' : String(Math.round((+v / f) * 100) / 100); };
  p.addEventListener('input', () => {
    desc.set.call(el, p.value === '' ? '' : String(Math.round(+p.value * f)));
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  });
  Object.defineProperty(el, 'value', { configurable: true, get: () => desc.get.call(el), set: (v) => { desc.set.call(el, v); show(); } });
  show();
  return p;
}

/** Een lange toelichting bij een veld (die wordt de uitleg achter ⓘ in plaats van een lap tekst boven het veld) */
function fhLongHint(lab) {
  if (!lab || lab.classList.contains('switch')) return null;
  return [...lab.querySelectorAll(':scope > .hint, :scope > .muted.small, :scope > .lbl-line > .hint, :scope > .lbl-line > .muted.small')]
    .find((x) => x.textContent.trim().length >= 40 && !x.querySelector('a')) || null;
}

/** Een veld voorzien van ⓘ (uitleg in een uitklapper) en de "≈"-regel */
function fhField(el, info) {
  if (el.dataset.fh) return;
  el.dataset.fh = '1';
  const lab = el.closest('label');
  const title = el.getAttribute('title') || lab?.getAttribute('title') || '';
  const hint = fhLongHint(lab);
  if (!info && !title && !hint) return;
  if (title) { el.removeAttribute('title'); lab?.removeAttribute('title'); }
  const long = hint ? hint.textContent.trim().replace(/^\(([\s\S]*)\)$/, '$1') : '';
  if (hint) hint.remove();
  const h = info?.h || '';
  const extra = [long, title].filter(Boolean).join(' ');
  let shown = el;
  let u = info?.u, fac = 1;
  if (info?.show) { [fac, u] = info.show; shown = fhScale(el, fac, u); }
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'fh-i';
  btn.textContent = 'ⓘ';
  btn.setAttribute('aria-label', L('Uitleg'));
  btn.setAttribute('aria-expanded', 'false');
  btn.dataset.tip = [h, extra].filter(Boolean).join(' ');   // ook vindbaar met zoeken in de instellingen
  const line = lab && (lab.querySelector(':scope > .lbl-line') || lab);
  if (line) {
    const first = [...line.childNodes].find((n) => n.nodeType === 1 && n.matches('input,select,textarea,.hint,.muted,br,.fh-eq,.inline-row'));
    line.insertBefore(btn, first || null);
  } else shown.after(btn);
  // ≈-regel naast het veld
  if (u && ['s', 'min', 'h', 'd'].includes(u)) {
    const eq = document.createElement('span');
    eq.className = 'fh-eq';
    shown.after(eq);
    el.fhUpd = () => { eq.textContent = fhHuman(shown.value, u); };
    shown.addEventListener('input', el.fhUpd);
    el.fhUpd();
  }
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const host = lab || shown.parentElement;
    const open = host.querySelector(':scope > .fh-pop');
    if (open) { open.remove(); btn.setAttribute('aria-expanded', 'false'); return; }
    const rows = [];
    if (u && fhUnit(u, 2)) rows.push([L('Eenheid'), esc(fhUnit(u, 2))]);
    if (shown.type === 'number' && (shown.min !== '' || shown.max !== '')) rows.push([L('Bereik'), esc([shown.min !== '' ? dec(shown.min) : '…', shown.max !== '' ? dec(shown.max) : '…'].join(' – '))]);
    if (info?.z) rows.push([L('Leeg of 0'), esc(info.z)]);
    if (info?.d !== undefined) {
      const dv = info.show ? info.d / fac : info.d;
      rows.push([L('Aanbevolen'), `${esc(dec(dv))} ${esc(fhUnit(u, dv))} <button type="button" class="btn sm fh-use">${L('Gebruiken')}</button>`]);
    }
    const pop = document.createElement('span');
    pop.className = 'fh-pop';
    pop.setAttribute('role', 'note');
    pop.innerHTML = `${h ? `<span class="fh-h">${esc(h)}</span>` : ''}${extra ? `<span class="fh-h">${esc(extra)}</span>` : ''}${rows.length ? `<span class="fh-rows">${rows.map(([k, v]) => `<span class="fh-k">${k}</span><span class="fh-v">${v}</span>`).join('')}</span>` : ''}`;
    host.append(pop);
    btn.setAttribute('aria-expanded', 'true');
    pop.addEventListener('click', (ev) => {
      ev.preventDefault();   // een klik in de uitleg zet de cursor niet in het veld
      if (!ev.target.closest('.fh-use')) return;
      shown.value = String(info.show ? info.d / fac : info.d);
      shown.dispatchEvent(new Event('input', { bubbles: true }));
      shown.dispatchEvent(new Event('change', { bubbles: true }));
      pop.remove();
      btn.setAttribute('aria-expanded', 'false');
    });
  });
}
/** Alle velden met uitleg in een stuk pagina voorzien van ⓘ (meerdere keren aanroepen kan) */
function fieldHelp(root) {
  if (!root) return;
  const reg = FH();
  root.querySelectorAll('input, select, textarea').forEach((el) => {
    if (el.type === 'hidden' || el.type === 'checkbox' || el.type === 'radio' || el.dataset.sl || el.dataset.fh) return;
    fhField(el, el.name ? reg[el.name] : undefined);
  });
}
/** De ≈-regels opnieuw berekenen (na het laden van de waarden) */
function fieldHelpRefresh(root) { if (root) root.querySelectorAll('[data-fh]').forEach((el) => el.fhUpd && el.fhUpd()); }
