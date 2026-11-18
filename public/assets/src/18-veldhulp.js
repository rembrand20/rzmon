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
  p.dataset.fh = '1';
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
    // 1.247.5: geen regel met een id (of met een id erin): die vult het formulier zelf, bv. #type-help bij elk type
    .find((x) => !x.id && !x.querySelector('[id], a') && x.textContent.trim().length >= 40) || null;
}

/** 1.276.0: de eerste zin van een tekst (voor de korte uitleg met de muis erover) */
function fhFirst(t) {
  const m = String(t || '').trim().match(/^[\s\S]{20,180}?[.!?](?=\s|$)/);
  return (m ? m[0] : String(t || '').trim()).slice(0, 200);
}
/** 1.276.0: geen eigen uitleg? Dan de korte uitleg van het onderdeel waar het veld in staat (nooit een veld zonder ?) */
function fhSection(el) {
  let n = el;
  while (n && n !== document.body) {
    let p = n.previousElementSibling;
    while (p) {
      const x = p.matches('.xp') ? p : null;
      if (x) return x.querySelector('.xp-s > span')?.textContent.trim() || '';
      if (p.matches('h2, h3.set-sub')) break;
      p = p.previousElementSibling;
    }
    n = n.parentElement;
    if (n && n.matches('.set-sec, .modal-body, form, section')) {
      const x = n.querySelector(':scope > .xp .xp-s > span');
      if (x) return x.textContent.trim();
    }
  }
  return '';
}

/** Een veld voorzien van ? (kort met de muis erover, de volledige uitleg met een klik) en de "≈"-regel */
function fhField(el, info) {
  if (el.dataset.fh) return;
  el.dataset.fh = '1';
  const lab = el.closest('label');
  const title = el.getAttribute('title') || lab?.getAttribute('title') || '';
  const hint = fhLongHint(lab);
  const isSw = el.type === 'checkbox';
  const sec = !info && !title && !hint && el.name ? fhSection(el) : '';
  if (!info && !title && !hint && !sec) return;
  if (sec) info = { h: sec };
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
  btn.textContent = '?';
  btn.setAttribute('aria-label', L('Uitleg'));
  btn.setAttribute('aria-expanded', 'false');
  btn.dataset.tip = [h, extra].filter(Boolean).join(' ');   // ook vindbaar met zoeken in de instellingen
  btn.dataset.short = fhFirst(info?.s || h || extra);   // 1.276.0: korte uitleg met de muis erover
  // 1.276.0: de naam van het veld en de ? op één regel (de tekst vóór het veld in een .lbl-line)
  if (lab && !isSw && !lab.querySelector(':scope > .lbl-line')) {
    const kids = [];
    for (const n of [...lab.childNodes]) {
      if (n.nodeType === 1 && n.matches('input, select, textarea, br, .inline-row, .fh-eq, .slf, [data-sl]')) break;
      kids.push(n);
    }
    if (kids.some((n) => n.textContent.trim())) {
      const w = document.createElement('span');
      w.className = 'lbl-line';
      lab.insertBefore(w, kids[0]);
      kids.forEach((n) => w.append(n));
    }
  }
  const line = lab && (lab.querySelector(':scope > .lbl-line') || lab);
  if (isSw && lab) (lab.querySelector('.sw-txt') || lab).append(btn);   // 1.276.0: bij een schakelaar achter de tekst
  else if (line === lab?.querySelector(':scope > .lbl-line')) line.append(btn);
  else if (line) {
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
    if (isSw) rows.length = 0;
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
  const sh = FH_SHORT();
  root.querySelectorAll('input, select, textarea').forEach((el) => {
    if (el.type === 'hidden' || el.type === 'radio' || el.dataset.sl || el.dataset.fh) return;
    // 1.276.0: ook schakelaars (alleen een vinkje in een label met tekst)
    if (el.type === 'checkbox' && !(el.closest('label.switch') && el.closest('label').textContent.trim())) return;
    const own = el.name ? reg[el.name] : undefined;
    const s = el.name ? sh[el.name] : undefined;
    fhField(el, own ? { ...own, s: s || own.s } : (s ? { h: s } : undefined));
  });
}
/** De ≈-regels opnieuw berekenen (na het laden van de waarden) */
function fieldHelpRefresh(root) { if (root) root.querySelectorAll('[data-fh]').forEach((el) => el.fhUpd && el.fhUpd()); }

/** 1.276.0: korte uitleg per veld (de ? met de muis erover); de volledige uitleg staat in FH() */
const FH_SHORT = () => ({
  nis2_on: L("Zet de pagina Compliance aan met de tien NIS2-maatregelen en de meldtermijnen bij een incident."),
  nis2_org: L("De naam van je organisatie bovenaan het NIS2-rapport."),
  nis2_csirt: L("Waar je een ernstig incident meldt; staat in het rapport en bij de meldtermijnen."),
  pushover_enabled: L("Meldingen naar je telefoon via de Pushover-app."),
  pushover_user: L("Je user key uit de Pushover-app (begint meestal met 'u')."),
  pushover_token: L("Het API-token van je eigen Pushover-applicatie. Gaat nooit terug naar de browser."),
  pushover_priority_crit: L("Hoe hard een critical binnenkomt. Emergency herhaalt tot je hem bevestigt."),
  pushover_priority_warn: L("Hoe hard een warning binnenkomt. Laag = zonder geluid."),
  pushover_sound: L("Het geluid van een melding; leeg = het standaardgeluid in je Pushover-app."),
  ntfy_enabled: L("Meldingen via ntfy (eigen server of ntfy.sh), als alternatief of naast Pushover."),
  ntfy_server: L("Het adres van de ntfy-server, bv. https://ntfy.sh of je eigen server."),
  ntfy_topic: L("Het onderwerp waarop je app is geabonneerd. Kies iets dat niemand raadt."),
  ntfy_token: L("Alleen nodig als je ntfy-server inloggen vraagt."),
  confirm_fast: L("Bij twijfel kijkt RzSense na 20 seconden opnieuw, zodat een echte storing sneller bevestigd is."),
  notify_unknown: L("Ook melden als een check geen uitslag geeft (bv. agent stil). Uit = alleen warning en critical."),
  notify_lang: L("In welke taal meldingen en checkberichten worden geschreven, los van de taal van je scherm."),
  notify_recovery_acked: L("Ook een herstelmelding sturen als iemand de storing al had bevestigd."),
  esc_on: L("Een storing die niemand bevestigt, komt na een tijd nog een keer binnen, een stap hoger."),
  esc_crit_prio: L("Hoe een onbevestigde critical opnieuw binnenkomt: hoge prioriteit of nood (herhaalt tot je bevestigt)."),
  esc_quiet: L("Tijdens stille uren niet escaleren; daarna wel."),
  notify_bundle_hosts: L("Gaan meerdere servers tegelijk mis, dan één melding met de waarschijnlijke oorzaak (bv. een switch)."),
  auto_maint_enabled: L("Een check die steeds opnieuw meldt, gaat vanzelf even in onderhoud, zodat je niet overspoeld wordt."),
  ha_webhook_enabled: L("Meldingen naar een webhook sturen, bv. een automatisering in Home Assistant."),
  ha_webhook_url: L("Het volledige adres van de webhook (Home Assistant: …/api/webhook/<id>)."),
  ha_webhook_verify_ssl: L("Controleert het certificaat van de webhook. Alleen uit bij een eigen (self-signed) certificaat."),
  chat_discord_on: L("Meldingen ook als bericht in een Discord-kanaal."),
  chat_discord_url: L("Discord: kanaal → Instellingen → Integraties → Webhooks → Nieuwe webhook → URL kopiëren. De URL is geheim: wie hem kent, kan in het kanaal schrijven."),
  chat_discord_min: L("Welke problemen in het kanaal komen: warning en critical, of alleen critical."),
  chat_discord_recovery: L("Ook een bericht als een probleem weer opgelost is."),
  chat_discord_info: L("Ook info- en systeemmeldingen (updates, logins, certificaten …)."),
  chat_discord_ids: L("Ook meldingen van de inbraakdetectie (IDS) en de dagelijkse IDS-samenvatting."),
  chat_slack_on: L("Meldingen ook als bericht in een Slack-kanaal."),
  chat_slack_url: L("Slack: maak een app met Incoming Webhooks en kies het kanaal; je krijgt een adres op hooks.slack.com. De URL is geheim: wie hem kent, kan in het kanaal schrijven."),
  chat_slack_min: L("Welke problemen in het kanaal komen: warning en critical, of alleen critical."),
  chat_slack_recovery: L("Ook een bericht als een probleem weer opgelost is."),
  chat_slack_info: L("Ook info- en systeemmeldingen (updates, logins, certificaten …)."),
  chat_slack_ids: L("Ook meldingen van de inbraakdetectie (IDS) en de dagelijkse IDS-samenvatting."),
  chat_teams_on: L("Meldingen ook als bericht in een Teams-kanaal."),
  chat_teams_url: L("Teams: workflow \"Post to a channel when a webhook request is received\"; kopieer het adres van de workflow. De URL is geheim: wie hem kent, kan in het kanaal schrijven."),
  chat_teams_min: L("Welke problemen in het kanaal komen: warning en critical, of alleen critical."),
  chat_teams_recovery: L("Ook een bericht als een probleem weer opgelost is."),
  chat_teams_info: L("Ook info- en systeemmeldingen (updates, logins, certificaten …)."),
  chat_teams_ids: L("Ook meldingen van de inbraakdetectie (IDS) en de dagelijkse IDS-samenvatting."),
  email_enabled: L("Meldingen en rapporten per e-mail."),
  email_mode: L("Altijd mailen, of alleen als reserve als Pushover uit staat of mislukt."),
  smtp_host: L("De uitgaande mailserver van je provider of mailserver, bv. smtp.provider.nl."),
  smtp_port: L("Meestal 587 (STARTTLS) of 465 (SSL/TLS). 25 alleen binnen je eigen netwerk."),
  smtp_security: L("Hoe de verbinding met de mailserver versleuteld wordt; past bij de poort."),
  smtp_user: L("Gebruikersnaam voor de mailserver; leeg = zonder inloggen."),
  smtp_pass: L("Wachtwoord voor de mailserver. Gaat nooit terug naar de browser."),
  mail_from: L("Het afzenderadres; leeg = de gebruikersnaam."),
  mail_to: L("Wie de meldingen en rapporten krijgt; meerdere adressen met een komma."),
  smtp_verify_ssl: L("Controleert het certificaat van de mailserver. Alleen uit bij een eigen (self-signed) certificaat."),
  ids_modern: L("Een ruimere IDS-pagina met kaarten en grafieken; uit = compacte lijst."),
  af_motion: L("Hoeveel er beweegt in de Attack Flow en het Command center. Stil = niets."),
  unifi_url: L("Het adres van je UniFi-controller of UniFi OS-apparaat."),
  unifi_site: L("De site in de controller; bijna altijd 'default'."),
  unifi_user: L("Een lokaal account op de controller (geen Ubiquiti-cloudaccount)."),
  unifi_pass: L("Wachtwoord van dat account. Gaat nooit terug naar de browser."),
  unifi_key: L("In plaats van een account: een API-sleutel (alleen lezen; apparaten weren kan dan niet)."),
  unifi_verify_ssl: L("Controleert het certificaat van de controller. UniFi heeft standaard een eigen certificaat: dan uit."),
  adguard_urls: L("Het adres van AdGuard Home; bij meerdere gesynchroniseerde instanties allemaal, één per regel."),
  adguard_user: L("Gebruikersnaam van AdGuard Home."),
  adguard_pass: L("Wachtwoord van AdGuard Home. Gaat nooit terug naar de browser."),
  adguard_verify_ssl: L("Controleert het certificaat; alleen aan bij https met een geldig certificaat."),
  mqtt_enabled: L("Stuurt de status van je servers en checks als sensoren naar Home Assistant (via MQTT)."),
  mqtt_host: L("Het adres van je MQTT-broker, bv. de Mosquitto-add-on van Home Assistant."),
  mqtt_port: L("1883 zonder versleuteling, 8883 met TLS."),
  mqtt_tls: L("Versleutelde verbinding met de broker; past bij de poort."),
  mqtt_user: L("Gebruikersnaam voor de broker."),
  mqtt_pass: L("Wachtwoord voor de broker. Gaat nooit terug naar de browser."),
  mqtt_prefix: L("Waar Home Assistant naar nieuwe apparaten luistert; standaard 'homeassistant'."),
  mqtt_base_topic: L("Onder welk topic RzSense zijn eigen berichten zet."),
  mqtt_verify_ssl: L("Controleert het certificaat van de broker bij TLS."),
  ai_on: L("Advies van een AI-model bij storingen, incidenten en de reverse proxy. Je kiest zelf welk model."),
  ai_provider: L("Welk AI-model: een cloudmodel (Claude, ChatGPT, Gemini, Grok) of Ollama op je eigen server."),
  ai_ollama_url: L("Het adres van je Ollama-server, bv. http://192.168.1.20:11434."),
  ai_ollama_model: L("Welk model Ollama gebruikt (bv. llama3.1). Moet op de server staan."),
  ai_claude_key: L("Je API-sleutel van Anthropic (console.anthropic.com). Gaat nooit terug naar de browser."),
  ai_claude_model: L("Welk Claude-model; een sneller model is goedkoper, een groter model denkt beter na."),
  ai_openai_key: L("Je API-sleutel van OpenAI. Gaat nooit terug naar de browser."),
  ai_openai_model: L("Welk ChatGPT-model; met ⟳ haal je de lijst op."),
  ai_gemini_key: L("Je API-sleutel van Google AI Studio. Gaat nooit terug naar de browser."),
  ai_gemini_model: L("Welk Gemini-model; met ⟳ haal je de lijst op."),
  ai_grok_key: L("Je API-sleutel van xAI. Gaat nooit terug naar de browser."),
  ai_grok_model: L("Welk Grok-model; met ⟳ haal je de lijst op."),
  ai_auto: L("Bij een nieuwe storing vraagt RzSense zelf advies, zonder dat je op een knop drukt (binnen de daglimiet)."),
  ai_mask: L("Namen, IP- en e-mailadressen gaan als labels naar de cloud en komen in het antwoord weer terug."),
  ai_memory: L("Wat eerder hielp bij dezelfde storing gaat mee naar de AI, zodat het advies beter wordt."),
  ai_cache: L("Dezelfde storing krijgt een tijd lang hetzelfde advies, zonder opnieuw te betalen."),
  ai_fallback: L("Werkt het cloudmodel niet of is het tegoed op, dan valt RzSense terug op Ollama."),
  ai_notify: L("Bij automatisch advies krijg je een korte melding met de waarschijnlijke oorzaak."),
  ai_triage: L("Nieuwe IDS-incidenten krijgen een label: lijkt onschuldig, verdacht of lijkt echt."),
  ai_report: L("Na een opgelost incident een knop voor een kort verslag (wat, hoe lang, wat hielp)."),
  ids_block_shared: L("Wordt een aanvaller op één server geblokkeerd, dan meteen op al je servers."),
  ids_push_mode: L("Wanneer een aanval een pushmelding geeft. Aanbevolen: alleen als je iets moet doen."),
  ids_digest_on: L("Elke dag één stille melding met de aanvallen van de afgelopen 24 uur."),
  ids_digest_time: L("Hoe laat de dagelijkse samenvatting komt."),
  ids_decoy_users: L("Gebruikersnamen die niemand echt gebruikt (bv. admin, oracle). Een inlogpoging ermee = blokkeren."),
  ids_decoy_port: L("Een poort waar niets draait. Wie hem probeert, scant je server en wordt geblokkeerd."),
  ids_fim_exclude: L("Bestanden en mappen die vaak veranderen en geen melding moeten geven."),
  auth_events: L("Houdt logins, sudo en su op je servers bij (IDS → Toegang)."),
  auth_ignore: L("Gebruikers of adressen die vaak inloggen en geen melding moeten geven, bv. een back-upaccount."),
  edr_on: L("Kijkt live mee naar processen, verbindingen en bestanden op je servers."),
  edr_sigma: L("Gebruikt naast de eigen regels ook de open Sigma-regels (SigmaHQ)."),
  edr_web: L("Bekijkt verdachte webverzoeken via je reverse proxy op SQL-injectie, XSS en bekende lekken."),
  edr_kill: L("Stopt een proces meteen als een regel dat zegt (webshell, reverse shell, cryptominer)."),
  edr_block: L("Blokkeert het IP-adres bij een webaanval met een herkende aanval erin."),
  edr_isolate: L("Sluit een server 24 uur af van alles behalve RzSense en je LAN bij een reverse shell of miner."),
  edr_anom: L("Meldt gedrag dat afwijkt van wat RzSense op die server als normaal heeft geleerd."),
  edr_chain: L("Meldt combinaties van gebeurtenissen die samen op een aanval wijzen."),
  edr_chain_act: L("Grijpt in bij zo'n keten: aanvaller blokkeren, of isoleren bij webshell of malware."),
  ti_on: L("Zoekt IP-adressen, domeinen en bestanden op in openbare lijsten van bekende aanvallers en malware."),
  ti_abuseipdb_key: L("Gratis sleutel van AbuseIPDB voor het opzoeken van IP-adressen (1.000 per dag)."),
  ti_report: L("Meldt aanvallers die RzSense automatisch blokkeert aan AbuseIPDB, zodat anderen ze ook kennen."),
  ti_greynoise: L("Zegt of een IP het hele internet scant, een bekende dienst is (Google, Shodan) of kwaadaardig."),
  ti_greynoise_key: L("Optioneel; zonder sleutel ongeveer 50 opzoekingen per dag."),
  ti_shodan: L("Open poorten, lekken en labels (zoals c2 of malware) van het aanvallende IP zelf."),
  ti_circl: L("Controleert of een nieuw programma een bekend, goed bestand is."),
  ti_tor: L("Herkent verkeer van het Tor-netwerk (dagelijkse lijst van uitgangen)."),
  ti_tor_block: L("Blokkeert Tor-uitgangen vooraf op je servers (modus Blokkeren)."),
  ti_feodo: L("Lijst van botnetservers; een verbinding ernaartoe is critical."),
  ti_urlhaus: L("Domeinen met malware, voor de DNS-controle via AdGuard."),
  ti_abusech_key: L("Gratis sleutel van abuse.ch voor MalwareBazaar en ThreatFox."),
  ti_block: L("Blokkeert bekende aanvallers vanaf deze score (0–100), in de modus Blokkeren."),
  ti_escalate: L("Een login vanaf een bekende aanvaller wordt meteen critical."),
  ti_close_benign: L("Een webaanval van een onschuldige scanner (volgens GreyNoise) wordt vanzelf afgehandeld."),
  ti_cve: L("Een webaanval op een lek dat de server echt heeft, is critical."),
  dns_on: L("Bekijkt de DNS-verzoeken van al je apparaten via AdGuard op malware, phishing en DNS-tunnels."),
  dns_nrd: L("Meldt domeinen die pas geregistreerd zijn; die worden vaak gebruikt voor phishing."),
  suricata_on: L("Gebruikt de alerts van Suricata als dat op een server draait."),
  suricata_block: L("Blokkeert de aanvaller bij een ernstig Suricata-alert (modus Blokkeren)."),
  suricata_block_sev: L("Vanaf welke ernst Suricata blokkeert. 1 = alleen ernstig (aanbevolen)."),
  edr_yara: L("Stuurt YARA-regels naar de agents om nieuwe bestanden op webshells, miners en malware te controleren."),
  dnsbl_on: L("Biedt een blokkadelijst aan die je in AdGuard toevoegt, zodat apparaten foute domeinen niet bereiken."),
  dnsbl_ti: L("Zet malwaredomeinen uit de dreigingsinformatie op de lijst."),
  dnsbl_ioc: L("Zet de domeinen die je bij IDS → Jagen bewaakt op de lijst."),
  dnsbl_own: L("Eigen domeinen die je wilt blokkeren."),
  dnsbl_allow: L("Domeinen die nooit op de lijst mogen, ook als een bron ze noemt."),
  ids_blocklists: L("Blokkeert netwerken die bekendstaan om aanvallen (Spamhaus DROP, FireHOL)."),
  ids_geo: L("Bepaalt het land van elk IP-adres (database op je eigen server, niets gaat naar buiten)."),
  ids_geo_allowed: L("Landen waar jij normaal vandaan inlogt; een SSH-login uit een ander land is critical."),
  ids_outbound: L("Meldt als een server zelf verbinding maakt met een bekende aanvaller (vaak een teken van besmetting)."),
  ids_outbound_country: L("Meldt verbindingen naar landen buiten je normale landen; per programma aan te leren."),
  ids_outbound_block: L("Blokkeert uitgaand verkeer naar bekende aanvallers, ook vanuit containers."),
  weblog_swag: L("Leest het log van je reverse proxy of webserver op aanvallen."),
  weblog_ha: L("Leest het log van Home Assistant op mislukte logins."),
  weblog_vw: L("Leest het log van Vaultwarden op mislukte logins."),
  weblog_swag_path: L("Waar het access-log van je proxy staat; meestal vindt de agent het zelf."),
  weblog_ha_path: L("Waar home-assistant.log staat."),
  weblog_vw_path: L("Het logbestand of de container van Vaultwarden."),
  wl_paths: L("Meldt meteen verzoeken naar bekende aanvalspaden (/.env, /.git/, wp-login.php)."),
  wl_ua: L("Meldt meteen bekende scanprogramma's (sqlmap, nikto, nuclei)."),
  wl_404: L("Meldt een adres dat veel niet-bestaande pagina's opvraagt (zoeken naar lekken)."),
  wl_auth: L("Meldt veel geweigerde logins vanaf één adres (wachtwoorden raden)."),
  wl_nohost: L("Meldt verzoeken zonder hostnaam: iemand scant je IP-adres."),
  weblog_block: L("Blokkeert zo'n adres ook, op al je servers (modus Blokkeren)."),
  ids_webshell: L("Meldt als een webserver een shell of een programma als curl of python start."),
  ids_webshell_kill: L("Stopt dat proces meteen (modus Blokkeren)."),
  ids_persist: L("Meldt een nieuwe dienst of timer die automatisch start (een manier om binnen te blijven)."),
  ids_harden: L("Geeft elke server een score voor zijn instellingen; slechter = een incident."),
  vuln_time: L("Hoe laat RzSense elke dag de software op je servers met de lijsten van lekken vergelijkt."),
  vuln_min_severity: L("Lekken onder deze ernst worden niet getoond."),
  proxy_on: L("De hoofdschakelaar: uit = de agent haalt alle eigen regels uit je reverse proxy."),
  proxy_def_lan: L("Een nieuwe site met een beheerprogramma of adminpagina's is standaard alleen thuis bereikbaar."),
  proxy_def_rate: L("Hoeveel verzoeken per seconde een nieuwe site per adres krijgt. Apps krijgen vanzelf de ruime stand."),
  proxy_def_bots: L("Een nieuwe site weert standaard bekende aanvalstools."),
  proxy_def_devices: L("Een nieuwe site laat standaard je toegestane apparaten van buiten door."),
  proxy_lan_extra: L("Netwerken die ook als 'thuis' tellen, bv. je VPN."),
  waf_on: L("Bekijkt elk verzoek naar je apps op aanvallen (CrowdSec AppSec op de proxy)."),
  waf_share: L("Deelt aanvallers met CrowdSec en gebruikt hun lijst van bekende aanvallers."),
  proxy_ai_on: L("AI-advies per site voor de reverse proxy."),
  proxy_ai_auto: L("Voert de AI het advies zelf door, of beslis jij per site?"),
  av_on: L("Scant uploads naar je apps op virussen (ClamAV op de proxyserver)."),
  av_notify: L("Een vondst wordt een IDS-incident met melding."),
  av_move: L("Zet een besmet bestand meteen apart (quarantaine); terugzetten kan."),
  nids_on: L("Suricata bekijkt het verkeer tussen je proxy en je apps."),
  nids_profile: L("Welke regels Suricata gebruikt: alleen webaanvallen, ook malware, of alles (veel lawaai)."),
  nids_min_sev: L("Vanaf welke ernst een Suricata-melding een incident wordt."),
  nids_iface: L("Welk netwerk Suricata bekijkt; auto = de netwerkbrug van je proxy."),
  sudo_on: L("Laat je een gebruiker voor korte tijd sudo geven via de agent, met je wachtwoord en 2FA."),
  patch_on: L("Laat de agent updates installeren, met de hand of automatisch in een venster."),
  ransom_on: L("Meldt als veel bestanden in korte tijd veranderen of versleuteld lijken."),
  ransom_min: L("Vanaf hoeveel gewijzigde bestanden per ronde het verdacht is."),
  appctl_on: L("Leert welke programma's normaal draaien en meldt nieuwe."),
  ioc_on: L("Zoekt naar sporen (IP's, domeinen, bestanden) op je servers en blijft ze bewaken."),
  eol_on: L("Meldt software en besturingssystemen die geen updates meer krijgen."),
  mailsec_on: L("Controleert SPF, DKIM en DMARC van je domeinen: kan iemand mailen namens jou?"),
  cve_buzz_on: L("Houdt bij of er over een lek veel gepraat wordt of een exploit is (dan sneller bijwerken)."),
  cve_github_token: L("Optioneel GitHub-token zonder rechten, voor meer opzoekingen per uur."),
  cve_mastodon_url: L("Een Mastodon-server om berichten over lekken te zoeken."),
  cve_mastodon_token: L("Optioneel token (alleen lezen) voor die server."),
  cve_exploitdb: L("Kijkt of er een openbare exploit voor een lek is."),
  cve_nuclei: L("Kijkt of scanners al naar een lek zoeken (Nuclei-templates)."),
  net_scan: L("Scant je netwerk op apparaten en open poorten (pagina Netwerk)."),
  net_sweep_minutes: L("Hoe vaak een snelle ronde kijkt welke apparaten er zijn."),
  net_deep_per_day: L("Hoe vaak per dag een diepere scan poorten, namen en merk bepaalt."),
  net_traffic: L("Houdt per apparaat bij hoeveel het downloadt en uploadt."),
  net_traffic_swap: L("Draait download en upload om, als je controller het andersom telt."),
  net_wan_cap_gb: L("Je datalimiet per maand; RzSense waarschuwt bij 80% en 100%."),
  logs_on: L("Hoofdschakelaar voor het verzamelen van logs; per server kies je de bronnen."),
  logs_mask: L("Haalt wachtwoorden, tokens en e-mailadressen uit logs voordat ze worden opgeslagen."),
  logs_mask_extra: L("Eigen patronen om te maskeren, één per regel (reguliere expressie)."),
  inet_down: L("De downloadsnelheid van je abonnement; een meting ver eronder geeft een melding."),
  inet_up: L("De uploadsnelheid van je abonnement."),
  inet_times: L("Wanneer de snelheidstest draait (bv. 03:00 en 15:00)."),
  thresh_default: L("Hoe een nieuwe check zijn grenzen bepaalt: zelf leren, met of zonder vaste grens als vangnet."),
  smart_sens: L("Hoe snel een slimme drempel iets afwijkends ziet. Scherp = sneller een melding."),
  smart_auto: L("Een handmatige check gaat na 7 dagen leren vanzelf naar slim, als dat minder meldingen geeft."),
  smart_interval: L("Bij een probleem vaker controleren; als alles een uur goed is minder vaak."),
  anomaly_on: L("Een ℹ️-melding als een waarde zonder drempel flink anders is dan normaal."),
  disk_jump_on: L("Een ℹ️-melding als een disk in een uur hard groeit."),
  context_on: L("Zet in de melding wat er vlak ervoor gebeurde, zoals een update of herstart."),
  hb_learn: L("Een heartbeat leert zijn ritme en waarschuwt als hij later is dan normaal."),
  confirm_skip_admin: L("Beheerders bevestigen een gevaarlijke actie met een vinkje in plaats van hun wachtwoord."),
  confirm_skip_user: L("Gebruikers bevestigen met een vinkje in plaats van hun wachtwoord."),
  confirm_skip_readonly: L("Alleen-lezen-accounts bevestigen met een vinkje in plaats van hun wachtwoord."),
  update_backup: L("Maakt eerst een back-up, zodat je terug kunt als een update misgaat."),
  update_auto: L("Werkt RzSense zelf bij als er een nieuwe versie is."),
  update_time: L("Hoe laat RzSense automatisch bijwerkt."),
  agent_auto_update: L("Agents werken zichzelf bij naar de nieuwste versie (alleen ondertekende versies)."),
  agent_tick: L("Hoe vaak agents rapporteren; vaker = sneller zien, iets meer belasting."),
  cert_dirs: L("Extra mappen waarin agents naar certificaten zoeken."),
  backup_freq: L("Hoe vaak RzSense vanzelf een back-up maakt."),
  backup_time: L("Hoe laat de back-up wordt gemaakt."),
  backup_weekday: L("Op welke dag de wekelijkse back-up wordt gemaakt."),
  backup_history: L("Neemt ook alle meetgegevens mee; de back-up wordt dan veel groter."),
  backup_pass: L("Versleutelt de back-up (AES-256). Bewaar het wachtwoord goed: zonder kun je niet terugzetten."),
  lan_no_login: L("Op je eigen netwerk hoef je niet in te loggen."),
  lan_as_admin: L("Op je eigen netwerk ben je zonder inloggen beheerder (anders alleen lezen)."),
  trusted_networks: L("Welke netwerken als 'thuis' tellen voor inloggen zonder wachtwoord."),
  trusted_proxies: L("Alleen invullen als RzSense achter een reverse proxy staat, zodat het echte IP-adres klopt."),
  login_lock_account: L("Blokkeert het account na te veel mislukte pogingen."),
  login_lock_ip: L("Blokkeert het IP-adres na te veel mislukte pogingen (nooit je eigen netwerk)."),
  role: L("Wat iemand mag: beheerder (alles), gebruiker (storingen afhandelen) of alleen lezen."),
  totp: L("Stelt meteen tweestapsverificatie in voor dit account."),
});
