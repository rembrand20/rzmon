// ---------------------------------------------------------------- 1.336.0: de beheerportal Eenvoudig of Uitgebreid
// Eenvoudig (de standaard) toont per kaart alleen de instellingen die je echt nodig hebt, en in de zijbalk alleen de
// onderdelen die de meeste mensen gebruiken. Uitgebreid toont alles, zoals het altijd was. Er verandert niets aan de
// instellingen zelf: een verborgen veld blijft in het formulier staan en houdt zijn waarde. De keuze geldt per account.
//
// SET_BASIC: per kaart (data-sk) de velden die in Eenvoudig blijven staan (een naam of een patroon). Kaarten die hier
// niet staan, blijven helemaal zichtbaar. SET_ADV: onderdelen van de zijbalk die alleen in Uitgebreid staan.
const SET_BASIC = {
  pushover: ['pushover_enabled', 'pushover_user', 'pushover_token'],
  ntfy: ['ntfy_enabled', 'ntfy_server', 'ntfy_topic'],
  confirm: ['confirm_fails', 'confirm_ok'],
  idspush: ['ids_push_mode', 'ids_digest_on', 'ids_digest_time'],
  alarm: ['disk_forecast_days', 'trend_alert_days', 'auto_maint_enabled'],
  chat: [/^chat_\w+_(on|url)$/],
  mqtt: ['mqtt_enabled', 'mqtt_host', 'mqtt_port', 'mqtt_user', 'mqtt_pass'],
  ai: ['ai_on', 'ai_provider', 'ai_ollama_url', /^ai_\w+_(key|model)$/],
  ids: ['ids_fail_count', 'ids_fail_minutes', 'ids_block_hours'],
  ids_edr: ['edr_on', 'edr_kill', 'edr_block', 'edr_isolate'],
  ids_ti: ['ti_on', 'ti_abuseipdb_key', 'ti_block'],
  ids_net: ['dns_on', 'suricata_on', 'edr_yara'],
  dnsbl: ['dnsbl_on', 'dnsbl_ti'],
  ids_attack: ['ids_blocklists', 'ids_geo', 'ids_geo_allowed', 'ids_outbound'],
  proxy: ['proxy_on', 'waf_on', 'waf_mode', 'av_on', 'nids_on'],
  cvebuzz: ['cve_buzz_on', 'cve_buzz_alert'],
  netscan: ['net_scan', 'net_sweep_minutes'],
  logs: ['logs_on', 'logs_max_gb'],
  internet: ['inet_down', 'inet_up'],
  smartmore: ['anomaly_on', 'disk_jump_on'],
  agentdef: ['agent_auto_update', 'agent_stale_minutes'],
  backup: ['backup_freq', 'backup_time', 'backup_keep'],
  storage: ['retention_days', 'logs_days'],
  access: ['lan_no_login', 'session_idle', 'login_lock_fails', 'login_lock_minutes'],
};
const SET_ADV = new Set(['logs', 'secch', 'hibp', 'ids_ti', 'ids_dns', 'ids_ransom', 'ids_apps', 'ids_attack', 'mailsec', 'snmp', 'relays', 'sudo', 'commands',
  'reserve', 'db', 'perf', 'conn', 'api', 'transfer']);
const SET_FIELDS = 'input[name]:not([type=hidden]), select[name], textarea[name]';
let setSimpleBusy = false, setSimpleTimer = null;

/** 'simple' (de standaard) of 'full' */
const setMode = () => (PREFS.set_mode === 'full' ? 'full' : 'simple');
/** Een onderdeel dat in Eenvoudig niet in de zijbalk staat (het onderdeel dat nu open is blijft altijd staan) */
const setAdvHidden = (key) => setMode() === 'simple' && SET_ADV.has(key) && key !== setSection;

function setModeSet(mode) {
  savePref('set_mode', mode === 'full' ? 'full' : 'simple');
  setModePaint();
  renderSettingsNav();
  setSimpleApply();
}

function setModePaint() {
  const box = $('#set-mode');
  if (!box) return;
  $$('[data-set-mode]', box).forEach((b) => { const on = b.dataset.setMode === setMode(); b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  document.body.classList.toggle('set-simple', setMode() === 'simple');
}

/**
 * Eenvoudig toepassen op de kaarten die nu in beeld zijn: velden buiten SET_BASIC verbergen (met hun uitleg, en met een
 * rij of tussenkop waar dan niets meer in staat), en onderaan de kaart zeggen hoeveel er in Uitgebreid nog zijn.
 */
function setSimpleApply() {
  const root = $('#tab-settings');
  if (!root || setSimpleBusy) return;
  setSimpleBusy = true;
  try {
    $$('.set-adv', root).forEach((el) => el.classList.remove('set-adv'));
    $$('.set-more', root).forEach((el) => el.remove());
    if (setMode() !== 'simple') return;
    for (const [sk, basic] of Object.entries(SET_BASIC)) {
      const card = $(`[data-sk="${sk}"]`, root);
      if (!card || card.classList.contains('set-off')) continue;
      const keep = (name) => basic.some((b) => (b instanceof RegExp ? b.test(name) : b === name));
      const blocks = new Set();
      $$(SET_FIELDS, card).forEach((f) => {
        if (keep(f.name)) return;
        const blk = f.closest('label') || f.parentElement;
        if (blk && blk !== card && !$$(SET_FIELDS, blk).some((x) => keep(x.name))) blocks.add(blk);
      });
      if (!blocks.size) continue;
      const hideXp = (el) => { let n = el.nextElementSibling; while (n && n.classList.contains('xp')) { n.classList.add('set-adv'); n = n.nextElementSibling; } };
      blocks.forEach((b) => { b.classList.add('set-adv'); hideXp(b); });
      // een rij of groep waarin elk veld verborgen is
      $$('.row2, .row3, .row4, fieldset, details', card).forEach((c) => {
        const fs = $$(SET_FIELDS, c);
        if (fs.length && fs.every((f) => f.closest('.set-adv'))) { c.classList.add('set-adv'); hideXp(c); }
      });
      // een tussenkop waar alleen nog uitleg of verborgen velden onder staan
      $$('h3, h4', card).forEach((h) => {
        const under = [];
        for (let n = h.nextElementSibling; n && !/^H[2-4]$/.test(n.tagName); n = n.nextElementSibling) under.push(n);
        if (under.length && under.some((n) => n.classList.contains('set-adv')) && under.every((n) => n.classList.contains('set-adv') || n.classList.contains('xp'))) {
          h.classList.add('set-adv');
          under.forEach((n) => n.classList.add('set-adv'));
        }
      });
      const n = $$(SET_FIELDS, card).filter((f) => f.closest('.set-adv')).length;
      if (n) {
        card.insertAdjacentHTML('beforeend', `<p class="set-more muted small">${esc(n === 1 ? L('Nog 1 instelling in Uitgebreid.') : L('Nog {n} instellingen in Uitgebreid.', { n }))}
          <button type="button" class="link-btn" data-set-mode="full">${esc(L('Toon alles'))}</button></p>`);
      }
    }
  } finally {
    setSimpleBusy = false;
  }
}

function initSetMode() {
  const root = $('#tab-settings');
  if (!root || !$('#set-mode')) return;
  setModePaint();
  root.addEventListener('click', (e) => { const b = e.target.closest('[data-set-mode]'); if (b) setModeSet(b.dataset.setMode); });
  // Een kaart die zijn velden later tekent (na het laden): opnieuw toepassen
  new MutationObserver(() => {
    if (setSimpleBusy || setMode() !== 'simple' || root.hidden) return;
    clearTimeout(setSimpleTimer);
    setSimpleTimer = setTimeout(setSimpleApply, 120);
  }).observe(root, { childList: true, subtree: true });
  // Een verborgen veld met een ongeldige waarde zou het opslaan stil tegenhouden: dan alles tonen
  root.addEventListener('invalid', (e) => { if (e.target.closest && e.target.closest('.set-adv')) setModeSet('full'); }, true);
}
