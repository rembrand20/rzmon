'use strict';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const CSRF = $('meta[name=csrf-token]')?.content;
// Taal: teksten staan in het Nederlands in de code; L() zoekt de Engelse vertaling op (lang/en.php), {naam} wordt ingevuld
const LANG = window.LANG || 'nl';
const LOCALE = LANG === 'en' ? 'en-GB' : 'nl-NL';
/** Decimaalteken: komma in het Nederlands, punt in het Engels */
const dec = (v) => (LANG === 'en' ? String(v) : String(v).replace('.', ','));
function L(s, vars) {
  let r = (window.I18N && window.I18N[s]) || s;
  if (vars) Object.entries(vars).forEach(([k, v]) => { r = r.split(`{${k}}`).join(String(v)); });
  return r;
}
const STATUS = ['OK', 'WARNING', 'CRITICAL', L('ONBEKEND')];

const TYPES = {
  ping:    { label: L('Ping'),          target: null,            unit: 'ms', warn: 100, crit: 500, help: L('Gemiddelde responstijd van 3 pings. Geen antwoord = CRITICAL.') },
  tcp:     { label: L('TCP poort'),     target: L('Poort'),         unit: 'ms', warn: 500, crit: 2000, ph: '22', help: L('Controleert of de poort open staat (bv. 22 SSH, 3306 MariaDB, 443 HTTPS).') },
  http:    { label: L('HTTP(S)'),       target: L('URL of pad'),    unit: 'ms', warn: 1000, crit: 3000, ph: L('https://example.com/ of /status'), help: L('HTTP status ≥ 400 = CRITICAL. SSL-certificaat < 14 dagen = WARNING (voor eigen drempels: gebruik de check SSL-certificaat).') },
  ssl:     { label: L('SSL-certificaat'), target: L('Host[:poort]'), unit: 'dagen', warn: 21, crit: 7, low: true, ph: L('example.com of nas.lan:5001'), help: L('Waarschuwt als het certificaat bijna verloopt. Leeg = adres van de server op poort 443. Werkt op elke TLS-poort (8443, 993, 8883…), ook op internet. Bij een agent-server kun je ook kiezen uit de certificaten die de agent meldt (bestanden en adressen gezien vanaf die server).') },
  disk:    { label: L('Diskruimte'),    target: L('Mount / pad'),   unit: '%',  warn: 80, crit: 90, ph: '/', help: L('Percentage gebruikt. Op agent-servers: mount zoals de agent meldt.') },
  cpu:     { label: L('CPU'),           target: null,            unit: '%',  warn: 85, crit: 95, help: L('CPU-gebruik gemeten over 2 seconden, met load average in het bericht. Tip: zet "alarm na N fouten" op 3+ om korte pieken te negeren.') },
  ram:     { label: L('RAM'),           target: null,            unit: '%',  warn: 85, crit: 95, help: L('Percentage geheugen in gebruik (op basis van MemAvailable, dus cache telt niet mee). Swap staat in het bericht.') },
  podman:  { label: L('Podman (algemeen)'), target: null, unit: null, help: L('Algehele status van Podman op deze server: draait podman, hoeveel containers draaien er, en zijn er containers gestopt met een fout, ongezond (healthcheck) of gepauzeerd, of pods niet in orde? Vereist de agent.') },
  container: { label: L('Container (Podman)'), target: L('Container'), unit: null, ph: L('naam, of gebruiker/naam (rootless)'), help: L('Eén check per container: de status (draait, healthcheck, herstarts) altijd, en daarnaast de CPU, RAM en disk die je hieronder aanvinkt, elk met eigen drempels. Kies de naam uit de lijst die de agent meldt.') },
  log:     { label: L('Logbestand (zoeken)'), target: L('Bron'), unit: 'treffers', warn: 5, crit: 20, ph: L('/var/log/secure, journal, journal:sshd of kernel'), help: L('De agent leest de nieuwe regels van een logbestand of het systemlog en telt hoeveel er op je zoekpatroon passen in de laatste periode (Instellingen → Alarmgedrag, standaard 15 min). Kies hieronder een voorinstelling of stel zelf in.') },
  command: { label: L('Commando'), target: null, unit: null, help: L('De agent voert een commando uit op de server, en RzMon vergelijkt de uitvoer met het antwoord dat je verwacht. Alleen als commando\'s voor deze server aan staan (Instellingen → ⬆ Agents bijwerken).') },
  ha:      { label: L('Home Assistant'), target: null, unit: null, help: L('Via de Home Assistant-add-on: de laatste automatische back-up, integraties die niet geladen zijn, of de systeemstatus van de Supervisor. Kies hieronder wat je wilt bewaken.') },
  snmp:    { label: L('SNMP'),          target: null,            unit: null, help: L('RzMon leest het apparaat zelf uit via SNMP (switches, access points, NAS, UPS, printers, Proxmox…). Interfaces, schijven en voorraden kies je het makkelijkst via 📡 SNMP-checks bij de server.') },
  reboot:  { label: L('🔄 Herstart'), target: null, unit: 'uur', help: L('Ziet wanneer de server opnieuw is opgestart (de uptime wordt korter) en stuurt dan een ℹ️ info-melding. De check zelf is altijd OK. Info staat standaard uit: zet hem aan bij Instellingen → 🔔 Meldingen (rij Info), of alleen voor deze check bij 🔔 Meldingen hieronder. Op agent-servers is agent 1.46.0 of nieuwer nodig.') },
  service: { label: L('Service'),       target: L('Servicenaam'),   unit: null, ph: 'nginx', help: L('De systemd-service moet "active" zijn. Op agent-servers kies je hem uit de lijst: de agent bewaakt hem dan vanzelf, en met Diepere controle wordt ook gekeken of hij echt werkt. Home Assistant-agent: homeassistant, supervisor, updates of addon:<slug>.') },
};
// Voorinstellingen voor logbestanden: bron, zoekpatroon (regex, hoofdletters maakt niet uit) en drempels
const LOG_PRESETS = {
  ssh:      { label: L('🔐 Mislukte logins (SSH)'), src: 'journal', warn: 5, crit: 20,
              pat: 'Failed password|Invalid user|authentication failure|Connection closed by authenticating user|maximum authentication attempts' },
  sudo:     { label: L('🔐 Mislukte sudo / su'), src: 'journal', warn: 1, crit: 5,
              pat: 'incorrect password attempt|sudo:.*authentication failure|FAILED SU|su:.*authentication failure' },
  disk:     { label: L('💾 Diskfouten'), src: 'kernel', warn: 1, crit: 5,
              pat: 'I/O error|blk_update_request|Buffer I/O error|medium error|ata[0-9.]+: (exception|error|failed command)|EXT4-fs error|XFS .*(error|corrupt)|BTRFS .*(error|corrupt)|nvme.*(timeout|reset|error)' },
  hardware: { label: L('🔥 Hardwarefouten (CPU, geheugen, PCIe, temperatuur)'), src: 'kernel', warn: 1, crit: 3,
              pat: 'Machine check|mce: |EDAC|Hardware Error|PCIe Bus Error|AER: |temperature above threshold|thermal.*throttl|Corrected error|Uncorrected error' },
  smart:    { label: L('💽 SMART-waarschuwingen (smartd)'), src: 'journal:smartd', warn: 1, crit: 3,
              pat: 'FAILURE|Prefailure|Currently unreadable|Offline uncorrectable|failed self-test|SMART Failure' },
  oom:      { label: L('🧠 Geheugen vol (OOM)'), src: 'kernel', warn: 1, crit: 3, pat: 'Out of memory|oom-kill|Killed process' },
  crash:    { label: L('💥 Programma\'s gecrasht'), src: 'kernel', warn: 1, crit: 5, pat: 'segfault|general protection|core dumped|traps: .* trap' },
  failed:   { label: L('⚙️ Mislukte services (systemd)'), src: 'journal', warn: 1, crit: 5, pat: 'Failed to start|failed with result|Main process exited, code=(exited|killed|dumped), status=[1-9]' },
  fw:       { label: L('🧱 Firewall-blokkades'), src: 'kernel', warn: 100, crit: 1000, pat: 'REJECT|DROP|filter_IN_.*(REJECT|DROP)|nft.*(drop|reject)' },
  f2b:      { label: L('🚫 Fail2ban-bans'), src: '/var/log/fail2ban.log', warn: 5, crit: 20, pat: ' Ban ' },
  web5xx:   { label: L('🌐 Webserver: serverfouten (5xx)'), src: '/var/log/nginx/access.log', warn: 10, crit: 50, pat: '" 5[0-9][0-9] ' },
  web404:   { label: L('🌐 Webserver: niet gevonden (404, scans)'), src: '/var/log/nginx/access.log', warn: 100, crit: 500, pat: '" 404 ' },
  weberr:   { label: L('🌐 Webserver: foutlog'), src: '/var/log/nginx/error.log', warn: 5, crit: 20, pat: '\\[(error|crit|alert|emerg)\\]' },
  mail:     { label: L('✉️ Mail: mislukte logins'), src: 'journal', warn: 5, crit: 20, pat: 'SASL [A-Z]+ authentication failed|auth failed|authentication failure.*(dovecot|postfix)' },
  db:       { label: L('🗄️ Database: fouten'), src: 'journal:mariadb', warn: 1, crit: 5, pat: '\\[ERROR\\]|Access denied for user|Aborted connection' },
  ha_power: { label: L('🏠 HA OS: te lage voeding (Raspberry Pi)'), src: 'ha:host', warn: 1, crit: 5, pat: 'under-voltage|Undervoltage detected|voltage normalised' },
  ha_disk:  { label: L('🏠 HA OS: diskfouten (SD-kaart/SSD)'), src: 'ha:host', warn: 1, crit: 5, pat: 'I/O error|mmc[0-9].*(error|timeout)|EXT4-fs error|blk_update_request' },
  ha_core:  { label: L('🏠 Home Assistant Core: fouten'), src: 'ha:core', warn: 5, crit: 20, pat: ' ERROR | CRITICAL ' },
  ha_sup:   { label: L('🏠 Supervisor: fouten'), src: 'ha:supervisor', warn: 1, crit: 5, pat: ' ERROR | CRITICAL ' },
  custom:   { label: L('✏️ Zelf instellen'), src: '', warn: 1, crit: 10, pat: '' },
};
const LOG_SOURCES = ['journal', 'journal:sshd', 'journal:ssh', 'kernel', '/var/log/secure', '/var/log/auth.log', '/var/log/messages',
  '/var/log/syslog', '/var/log/kern.log', '/var/log/fail2ban.log', '/var/log/nginx/access.log', '/var/log/nginx/error.log',
  '/var/log/httpd/access_log', '/var/log/httpd/error_log', '/var/log/maillog', '/var/log/mail.log', '/var/log/mariadb/mariadb.log'];

// Container-checks: wat er gemeten wordt (eigen eenheid en drempels)
const METRICS = {
  status: { label: L('Status (draait, healthcheck, herstarts)'), unit: null, help: L('OK als de container draait. CRITICAL als hij gestopt of ongezond (healthcheck) is. WARNING als hij gepauzeerd is, of in het afgelopen kwartier automatisch herstart is (crash).') },
  cpu:    { label: L('CPU-gebruik'), unit: '%', warn: 80, crit: 95, help: L('CPU-gebruik zoals podman stats het toont: 100% = één volledige core (kan dus boven 100% komen).') },
  mem:    { label: L('Geheugen (RAM)'), unit: '%', warn: 85, crit: 95, help: L('Percentage van de geheugenlimiet van de container, of van het totale geheugen als er geen limiet is.') },
  disk:   { label: L('Diskgebruik'), unit: 'MB', warn: 5000, crit: 10000, help: L('Schrijflaag van de container plus de grootte van zijn volumes (volumes worden elk uur gemeten).') },
};
// Home Assistant (add-on): wat er bewaakt kan worden, met standaarddrempels
// Wat een SNMP-check kan bewaken (zie SnmpMonitor::METRICS); low = lager is slechter
const SNMP_METRICS = {
  uptime:        { label: L('Uptime (herstart)'), short: L('Uptime'), unit: 'uur', warn: 1, crit: null, low: true, help: L('WARNING zolang het apparaat korter aan staat dan de drempel: zo zie je een herstart. Bij Linux en Proxmox de uptime van het systeem, anders die van de SNMP-agent.') },
  if:            { label: L('Interface'), short: L('Interface'), target: L('Interface-index'), unit: '%', warn: 80, crit: 95, help: L('Down terwijl de poort aan staat = CRITICAL; uitgeschakeld = OK. Drempels: bezetting in % van de linksnelheid (in of uit, de hoogste). Het verkeer staat in het bericht.') },
  cpu:           { label: L('CPU'), short: L('CPU'), unit: '%', warn: 85, crit: 95, help: L('Gemiddelde belasting over alle cores (HOST-RESOURCES), of de CPU-meting van TP-Link of net-snmp.') },
  mem:           { label: L('Geheugen'), short: L('Geheugen'), unit: '%', warn: 85, crit: 95, help: L('Geheugen in gebruik. Bij Linux en Proxmox (net-snmp) zonder buffers en cache.') },
  storage:       { label: L('Opslag (schijf of volume)'), short: L('Opslag'), target: L('Opslag-index'), unit: '%', warn: 80, crit: 90, help: L('Percentage gebruikt van een schijf of volume (HOST-RESOURCES).') },
  load:          { label: L('Load (5 min)'), short: L('Load'), unit: 'load', warn: 4, crit: 8, help: L('Load average over 5 minuten (net-snmp: Linux, Proxmox). Vuistregel: boven het aantal cores wordt het druk.') },
  temp:          { label: L('Temperatuur'), short: L('Temperatuur'), unit: '°C', warn: 60, crit: 75, help: L('MikroTik, of de warmste sensor via lm-sensors (Linux en Proxmox met net-snmp).') },
  ups_status:    { label: L('UPS status'), short: L('UPS'), unit: null, help: L('Op batterij of bypass = WARNING; batterij bijna leeg, leeg of in storing = CRITICAL; batterij vervangen (APC) of actieve alarmen = WARNING. UPS-MIB (Eaton, CyberPower, Riello…) of APC.') },
  ups_charge:    { label: L('UPS batterijlading'), short: L('UPS'), unit: '%', warn: 50, crit: 20, low: true, help: L('Lading van de accu in %.') },
  ups_runtime:   { label: L('UPS looptijd'), short: L('UPS'), unit: 'min', warn: 15, crit: 5, low: true, help: L('Hoe lang de UPS de huidige belasting nog op batterij kan dragen.') },
  ups_load:      { label: L('UPS belasting'), short: L('UPS'), unit: '%', warn: 80, crit: 95, help: L('Belasting van de uitgang. Hoe hoger, hoe korter de looptijd op batterij.') },
  ups_temp:      { label: L('UPS batterijtemperatuur'), short: L('UPS'), unit: '°C', warn: 35, crit: 45, help: L('Warmte is de grootste vijand van een loodaccu: elke 10 °C boven 25 °C halveert de levensduur ongeveer.') },
  syno_system:   { label: L('Synology systeem'), short: L('Synology'), unit: '°C', warn: 60, crit: 70, help: L('Systeem, voeding en ventilatoren (defect = CRITICAL), en de systeemtemperatuur met drempels. Meldt ook een DSM-update.') },
  syno_raid:     { label: L('Synology volume (RAID)'), short: L('Synology'), target: L('Volume-index'), unit: '%', warn: 80, crit: 90, help: L('Degraded of crashed = CRITICAL, wordt hersteld = WARNING; drempels op de vulling.') },
  syno_disk:     { label: L('Synology schijf'), short: L('Synology'), target: L('Schijf-index'), unit: '°C', warn: 50, crit: 60, help: L('Status en gezondheid van de schijf (DSM 7) en de temperatuur.') },
  printer:       { label: L('Printer (toner, inkt, drum)'), short: L('Printer'), target: L('Voorraad-index'), unit: '%', warn: 20, crit: 5, low: true, help: L('Niveau van toner, inkt, drum of afvalbak in %.') },
  unifi_clients: { label: L('UniFi clients'), short: L('UniFi'), unit: 'clients', warn: null, crit: null, help: L('Aantal verbonden clients op een UniFi access point. Drempels zijn optioneel (meer = drukker).') },
  oid:           { label: L('Eigen OID'), short: L('OID'), target: L('OID'), ph: '1.3.6.1.4.1.…', unit: '', warn: null, crit: null, help: L('Elke OID die het apparaat kent (numeriek): als getal met drempels, als teller (waarde per seconde) of als tekst die je vergelijkt. Tip: zoek de OID op met snmpwalk of in de MIB van de fabrikant.') },
};
const OID_MATCHES = { equals: L('is precies'), contains: L('bevat'), not_contains: L('bevat niet'), regex: L('past op regex') };
/** Type en eenheid van een SNMP-check; bij een eigen OID uit de opties */
function snmpType(c) {
  const m = SNMP_METRICS[c.metric] || SNMP_METRICS.oid;
  if (c.metric !== 'oid') return { ...TYPES.snmp, ...m, label: `SNMP · ${m.short}` };
  let o = {};
  try { o = JSON.parse(c.options || '{}') || {}; } catch (e) { o = {}; }
  return { ...TYPES.snmp, ...m, label: 'SNMP · OID', unit: o.mode === 'text' ? null : (o.unit || ''), low: !!o.low };
}

const HA_METRICS = {
  backup:       { label: L('Laatste back-up'), unit: 'dagen', warn: 2, crit: 7, help: L('Hoe oud de laatste geslaagde automatische back-up is (HA 2025.2 of nieuwer, met automatische back-ups). Ook WARNING als de laatste poging mislukt is.') },
  integrations: { label: L('Integraties'), unit: 'stuks', warn: 1, crit: 3, help: L('Aantal integraties dat niet geladen is: fout bij instellen, opnieuw proberen of uitladen mislukt. Uitgeschakelde integraties tellen niet mee.') },
  repairs:      { label: L('Systeemstatus (reparaties)'), unit: null, help: L('Reparaties van de Supervisor: "niet gezond" = CRITICAL; "niet ondersteund" of een openstaande melding (bv. weinig vrije ruimte) = WARNING.') },
  reboot:       { label: L('Onverwachte herstart (HA OS)'), unit: null, help: L('CRITICAL (een uur lang) als Home Assistant OS opnieuw is opgestart zonder OS-update: stroomuitval, crash of een handmatige herstart. Een herstart door een OS-update wordt alleen vermeld.') },
  disklife:     { label: L('Levensduur SD-kaart / eMMC'), unit: '%', warn: 75, crit: 90, help: L('Hoeveel procent van de levensduur van de opslag verbruikt is. Alleen als de hardware dat meldt (eMMC, sommige SD-kaarten en SSD\'s).') },
  osupdate:     { label: L('OS-update mislukt (A/B-terugval)'), unit: null, help: L('CRITICAL als een OS-update niet wilde opstarten en Home Assistant OS is teruggevallen op de vorige versie.') },
  usb:          { label: L('USB-apparaat aanwezig'), unit: null, target: L('USB-apparaat'), ph: L('kies uit de lijst'), help: L('CRITICAL als het apparaat (Zigbee- of Z-Wave-stick, Bluetooth) niet meer gezien wordt door Home Assistant OS.') },
};
/** Home Assistant-add-on? (meldt ha:-gegevens of de Supervisor) */
function isHaHost(h) { return h.agent_update_via === 'ha' || h.metrics.some((m) => m.startsWith('ha:') || m === 'service:supervisor'); }
/** Type-beschrijving van een check (container: één check met status + resources, geen eigen eenheid). */
function typeOf(c) {
  if (c.type === 'snmp') return snmpType(c);
  if (c.type === 'ha') return { ...TYPES.ha, ...(HA_METRICS[c.metric] || {}), label: 'Home Assistant' };
  return c.type === 'container' && c.metric ? { ...TYPES.container, ...METRICS[c.metric], label: TYPES.container.label } : TYPES[c.type];
}

const MODES = { local: L('Lokaal (deze server)'), agent: L('Remote met agent'), remote: L('Remote (alleen netwerk)') };

let state = { hosts: [], alerts: [] };
// Omgekeerde status (zie Checks::REVERSIBLE)
const REVERSIBLE = ['ping', 'tcp', 'http', 'service', 'container', 'podman', 'command', 'ha'];
const REV_OK = [[0, L('OK (normaal)')], [1, '🟠 ' + L('WARNING (omgekeerd)')], [2, '🔴 ' + L('CRITICAL (omgekeerd)')]];
const REV_FAIL = [[0, '✅ OK'], [1, '🟠 WARNING'], [2, '🔴 CRITICAL']];
const STATUS_WORD = ['OK', 'WARNING', 'CRITICAL', L('onbekend')];

let stats = { uptime: {}, sparks: {} };
// Sorteren op de pagina Meldingen (per apparaat onthouden): { key: 'time'|'status'|'server'|'check'|'message'|'channels', dir: 1|-1 }
const ALERT_SORTS = { time: L('Tijd'), status: 'Status', server: 'Server', check: 'Check', message: L('Bericht'), channels: L('Kanalen') };
// IDS: tabblad, en Toegang (gegevens, filters, sorteren, open dagen)
let idsTab = (() => { try { return localStorage.getItem('rzmon-ids-tab') || 'inc'; } catch (e) { return 'inc'; } })();
let accData = null, accFilter = 'all', accHost = '', accDayOpen = {};
let accSort = (() => { try { return JSON.parse(localStorage.getItem('rzmon-acc-sort') || 'null') || { key: '', dir: 1 }; } catch (e) { return { key: '', dir: 1 }; } })();
// Checks onder een server in groepen (vaste volgorde); dichtgeklapte groepen per server per apparaat onthouden
const CHECK_GROUPS = [
  ['sys', L('📊 Systeem'), ['cpu', 'ram', 'disk', 'reboot']],
  ['net', L('🌐 Bereikbaarheid'), ['ping', 'tcp', 'http']],
  ['svc', L('⚙️ Services'), ['service']],
  ['cnt', L('📦 Containers'), ['podman', 'container']],
  ['cmd', L("🖥️ Commando's"), ['command']],
  ['log', L('📜 Logbestanden'), ['log']],
  ['ssl', L('🔒 Certificaten'), ['ssl']],
  ['ha', L('🏠 Home Assistant'), ['ha']],
  ['snmp', L('📡 SNMP'), ['snmp']],
];
const GROUP_MIN = 5;   // pas kopjes vanaf zoveel checks; daaronder alleen de volgorde
let grpClosed = (() => { try { return JSON.parse(localStorage.getItem('rzmon-grp-closed') || '{}') || {}; } catch (e) { return {}; } })();
// Filter op de pagina Meldingen: all, noinfo (zonder ℹ️ info) of info; per apparaat onthouden
let alertFilter = (() => { try { return localStorage.getItem('rzmon-alert-filter') || 'all'; } catch (e) { return 'all'; } })();
let alertSort = (() => { try { return JSON.parse(localStorage.getItem('rzmon-alert-sort') || 'null') || { key: '', dir: 1 }; } catch (e) { return { key: '', dir: 1 }; } })();
// Instellingen in L1nes (zie applySettingsLayout); hier bovenaan omdat de weergave al bij het opstarten wordt toegepast
const SET_GROUPS = { pushover: 'meldingen', notify: 'meldingen', sysinfo: 'meldingen', email: 'meldingen', webhook: 'meldingen', mqtt: 'meldingen', alarm: 'monitoring',
  ids: 'monitoring', vulns: 'monitoring', update: 'systeem', agents: 'systeem', backup: 'systeem', db: 'systeem', access: 'toegang', enc: 'toegang', blocks: 'toegang', users: 'toegang', api: 'toegang', account: 'toegang', password: 'toegang' };
let setOpen = (() => { try { return JSON.parse(localStorage.getItem('rzmon-set-open') || '{}') || {}; } catch (e) { return {}; } })();
function saveSetOpen() { try { localStorage.setItem('rzmon-set-open', JSON.stringify(setOpen)); } catch (e) {} }
const setCards = () => $$('#tab-settings [data-sk]:not(#set-overview)');
/** Zichtbaar binnen de instellingen (niet verborgen door rechten of hidden), ook als het tabblad zelf dicht is. */
function setVisible(el) {
  for (let n = el; n && n.id !== 'tab-settings'; n = n.parentElement) {
    if (n.hidden || getComputedStyle(n).display === 'none') return false;
  }
  return true;
}

let settingsLoaded = false;   // samenvattingen pas tonen als de instellingen geladen zijn
let updateInfo = null;        // laatste update_info (voor de samenvatting in L1nes)
let alertData = null;         // meldingen van de bewaartermijn (pagina Meldingen)
const alertDayOpen = {};      // datum → open/dicht (anders: alleen vandaag open)
let idsData = null;           // pagina 🛡️ IDS
let idsFilter = 'open';       // open | all
const idsDayOpen = {};

// ---------------------------------------------------------------- API
async function api(action, body, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const res = await fetch('api.php?' + qs, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({ error: L('Ongeldig antwoord van server') }));
  if (res.status === 401 && !action.startsWith('login')) { location.reload(); }
  // status en data mee in de fout: zo hoeft niemand de (vertaalde) tekst te herkennen
  if (!res.ok) throw Object.assign(new Error(data.error || `HTTP ${res.status}`), { status: res.status, data });
  return data;
}

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.textContent = msg;
  $('#toasts').append(el);
  setTimeout(() => el.remove(), 4000);
}

function ago(dt) {
  if (!dt) return L('nooit');
  const s = Math.round((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 1000);
  if (s < 60) return L('{n}s geleden', { n: Math.max(0, s) });
  if (s < 3600) return L('{n} min geleden', { n: Math.floor(s / 60) });
  if (s < 86400) return L('{n} uur geleden', { n: Math.floor(s / 3600) });
  const d = Math.floor(s / 86400);
  return d === 1 ? L('1 dag geleden') : L('{n} dagen geleden', { n: d });
}

const fmt = (v, unit) => (v === null || v === undefined ? '' : `${Math.round(v * 10) / 10}${unit ? ' ' + L(unit) : ''}`);

// ---------------------------------------------------------------- Wachtwoord vergeten
const forgotForm = $('#forgot-form');
if (forgotForm) {
  const show = (forgot) => {
    $('#login-form').hidden = forgot;
    forgotForm.hidden = !forgot;
    (forgot ? forgotForm.ident : $('#login-form').username).focus();
  };
  $('#forgot-link').onclick = (e) => {
    e.preventDefault();
    forgotForm.ident.value = $('#login-form').username.value;
    show(true);
  };
  $('#forgot-back').onclick = (e) => { e.preventDefault(); show(false); };
  forgotForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('.form-error', forgotForm);
    const b = $('button', forgotForm);
    b.disabled = true;
    try {
      const r = await api('pw_forgot', { ident: forgotForm.ident.value });
      $('#forgot-fields').hidden = true;
      $('#forgot-done').hidden = false;
      $('#forgot-done').innerHTML = r.no_mail
        ? '⚠️ ' + L('RzMon kan op dit moment geen e-mail versturen. <b>Vraag een beheerder</b> om je wachtwoord opnieuw in te stellen.')
        : '✉️ ' + L('Als dit account bestaat en een e-mailadres heeft, is er een mail met een herstellink verstuurd. Kijk ook in je spammap. De link is 30 minuten geldig.');
      err.hidden = true;
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
    b.disabled = false;
  });
}

// Nieuw wachtwoord kiezen (link uit de mail)
const resetForm = $('#reset-form');
if (resetForm) {
  const token = resetForm.dataset.token;
  history.replaceState(null, '', location.pathname);   // de sleutel niet in de adresbalk of geschiedenis laten staan
  const err = $('.form-error', resetForm);
  api('pw_reset_check', { token }).then((r) => {
    $('#reset-user').textContent = r.username;
    resetForm.username.value = r.username;
    $('#reset-code-wrap').hidden = !r.totp;
    resetForm.code.required = r.totp;
    $('#reset-fields').hidden = false;
    $('#reset-status').hidden = true;
    resetForm.password.focus();
  }).catch((ex) => { $('#reset-status').textContent = ex.message; });
  resetForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (resetForm.password.value !== resetForm.password2.value) {
      err.textContent = L('De twee wachtwoorden zijn niet gelijk');
      err.hidden = false;
      return;
    }
    const b = $('#reset-fields button', resetForm);
    b.disabled = true;
    try {
      await api('pw_reset', { token, password: resetForm.password.value, code: resetForm.code.value });
      $('#reset-fields').hidden = true;
      err.hidden = true;
      $('#reset-status').hidden = false;
      $('#reset-status').innerHTML = '<b class="good-txt">✔ ' + L('Je wachtwoord is gewijzigd.') + '</b> ' + L('Log nu in met je nieuwe wachtwoord.');
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      if (ex.data?.dead) $('#reset-fields').hidden = true;
    }
    b.disabled = false;
  });
}

// ---------------------------------------------------------------- Login
const loginForm = $('#login-form');
if (loginForm) {
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('.form-error', loginForm);
    const step2 = !$('#login-step2').hidden;
    try {
      if (step2) {
        await api('login_totp', { code: loginForm.code.value });
        return location.replace(location.pathname);   // zonder ?login=1, anders weer uitgelogd
      }
      const r = await api('login', { username: loginForm.username.value, password: loginForm.password.value, remember: loginForm.remember.checked });
      if (!r.totp_required) return location.replace(location.pathname);
      // Wachtwoord klopt; nu de code uit de authenticator-app
      $('#login-step1').hidden = true;
      $('#login-step2').hidden = false;
      $$('#login-step1 input').forEach((i) => (i.required = false));
      loginForm.code.required = true;
      err.hidden = true;
      loginForm.code.focus();
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      if (step2 && ex.data?.restart) {
        $('#login-step1').hidden = false;
        $('#login-step2').hidden = true;
        $$('#login-step1 input').forEach((i) => (i.required = true));
        loginForm.code.required = false;
        loginForm.code.value = '';
        loginForm.password.value = '';
        loginForm.password.focus();
      } else if (step2) {
        loginForm.code.select();
      }
    }
  });
} else if (!resetForm) {
  // Pas starten als het hele script geladen is: opent de pagina direct op een tabblad (bv. #settings),
  // dan gebruikt dat ook constanten die verderop in dit bestand staan
  queueMicrotask(init);
}

// ---------------------------------------------------------------- App
function init() {
  if ($('#tab-vulns')) initVulns();
  $('#vuln-scan-now').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      const r = await api('vuln_scan', {});
      $('#vuln-scan-info').textContent = r.message || (r.pending ? L('Scan gestart: nog {n} server(s) te gaan, de rest volgt binnen een paar minuten', { n: r.pending }) : L('Scan klaar'));
    } catch (ex) { $('#vuln-scan-info').textContent = ex.message; }
    b.disabled = false;
  };
  $$('.tab').forEach((t) => t.addEventListener('click', () => showTab(t.dataset.tab)));
  initMainTabs();
  showTab(location.hash.slice(1) || 'dashboard');

  initAccountMenu();
  initSettingsNav();
  if ($('#logout')) $('#logout').onclick = async () => { await api('logout', {}); location.reload(); };
  $('#add-host').onclick = () => hostForm();
  $('#run-all').onclick = async (e) => {
    e.target.disabled = true;
    try { const r = await api('run_all', {}); toast(L('{n} checks uitgevoerd', { n: r.count })); await refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#filter').addEventListener('input', render);
  $('#only-problems').addEventListener('change', () => { statusFilter = null; render(); });
  $('#health-state').onclick = () => {
    const op = $('#only-problems');
    op.checked = statusFilter !== null || !op.checked;
    statusFilter = null;
    render();
  };
  $('#health-counts').addEventListener('click', (e) => {
    const b = e.target.closest('[data-st]');
    if (!b) return;
    const st = +b.dataset.st;
    statusFilter = statusFilter === st ? null : st;
    $('#only-problems').checked = false;
    render();
  });
  $('#health-info').addEventListener('click', (e) => {
    const b = e.target.closest('[data-host]');
    if (b) jumpToHost(b.dataset.host);
  });
  $('#hosts').addEventListener('click', onHostsClick);
  $('#hosts').addEventListener('keydown', (e) => {
    const head = e.target.closest('.host-head[data-act=toggle], .line-row[data-act=toggle]');
    if (head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleHost(head.dataset.id); }
  });
  $('#toggle-all').onclick = () => {
    const all = state.hosts.every((h) => openHosts.has(+h.id));
    openHosts.clear();
    if (!all) state.hosts.forEach((h) => openHosts.add(+h.id));
    saveOpen();
    render();
  };

  $('#settings-form').addEventListener('submit', saveSettings);
  $('#backup-now').onclick = backupNow;
  $('#update-start').onclick = openUpdateDialog;
  // Kwetsbaarheden aan/uit: meteen opslaan (zoals het IDS); het tabblad komt erbij of gaat weg, dus de pagina herladen
  $('#vuln-enabled').onchange = async (e) => {
    try {
      await api('vuln_set', { enabled: e.target.checked });
      toast(e.target.checked ? L('Kwetsbaarheden aangezet') : L('Kwetsbaarheden uitgezet'));
      setTimeout(() => location.reload(), 700);
    } catch (ex) { toast(ex.message, 'err'); e.target.checked = !e.target.checked; }
  };
  $('#ids-enabled').onchange = async (e) => {
    try { await api('ids_set', { enabled: e.target.checked }); toast(e.target.checked ? L('IDS aangezet') : L('IDS uitgezet')); }
    catch (ex) { toast(ex.message, 'err'); e.target.checked = !e.target.checked; }
    idsModeInfo();
  };
  $('#ids-mode').onchange = (e) => {
    const block = e.target.checked;
    e.target.checked = !block;   // pas omzetten als het gelukt is
    if (!block) {
      api('ids_set', { mode: 'learn' }).then(() => { $('#ids-mode').checked = false; idsModeInfo(); toast(L('IDS: leren (alleen melden)')); }).catch((ex) => toast(ex.message, 'err'));
      return;
    }
    modal({
      title: '⛔ ' + L('IDS: blokkeren aanzetten'),
      body: `<p style="margin-top:0">${L('RzMon gaat dan echt IP-adressen blokkeren op je servers (eigen nftables-tabel van de agent), bij brute force en als jij op ⛔ klikt. Je LAN, RzMon zelf en de lijst <i>Nooit blokkeren</i> worden nooit automatisch geblokkeerd; op TrueNAS wordt alleen gedetecteerd.')}</p>
        <p class="muted small">${L('Op een server zelf zet je blokkeren altijd uit met <code>IDS_BLOCK=0</code> in de instellingen van de agent. Doe het eerste gebruik bij voorkeur terwijl je ook via de console kunt inloggen. Deze wijziging komt in het logboek.')}</p>
        ${confirmField('BLOKKEREN')}`,
      buttons: [{ label: L('Blokkeren aanzetten'), cls: 'primary', submit: true }],
      onSubmit: async (d) => {
        await api('ids_set', { mode: 'block', confirm: d.confirm });
        toast(L('IDS: blokkeren staat aan'));
        setTimeout(() => { showTab('settings/ids'); }, 50);
      },
    });
  };
  $('#agent-commands').onchange = (e) => {
    const sel = e.target;
    setAgentCommands(null, sel.value, sel.dataset.current || 'off', L('alle agents'), () => { showTab('settings/agents'); loadAgentCommands(); });
  };
  $('#ids-filter').onclick = (e) => {
    const b = e.target.closest('[data-if]');
    if (!b) return;
    idsFilter = b.dataset.if;
    $$('#ids-filter .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    renderIds();
  };
  $('#ids-toggle').onclick = () => {
    const days = $$('#ids-incidents .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-block-add').onclick = () => idsBlockDialog();
  $('#ids-tabs').onclick = (e) => {
    const b = e.target.closest('[data-it]');
    if (!b) return;
    idsTab = b.dataset.it;
    try { localStorage.setItem('rzmon-ids-tab', idsTab); } catch (ex) { /* alleen voor nu */ }
    showIdsTab();
  };
  $('#acc-filter').onclick = (e) => {
    const b = e.target.closest('[data-ac]');
    if (!b) return;
    accFilter = b.dataset.ac;
    $$('#acc-filter .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    renderAccess();
  };
  $('#acc-host').onchange = (e) => { accHost = e.target.value; renderAccess(); };
  $('#acc-toggle').onclick = () => {
    const days = $$('#ids-access .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-access').addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sort]');
    if (th) {
      const k = th.dataset.sort;
      accSort = accSort.key !== k ? { key: k, dir: 1 } : accSort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 };
      try { localStorage.setItem('rzmon-acc-sort', JSON.stringify(accSort)); } catch (ex) { /* alleen voor nu */ }
      return renderAccess();
    }
    const b = e.target.closest('[data-acc]');
    if (!b) return;
    const r = accData.rows.find((x) => +x.id === +b.dataset.id);
    if (!r) return;
    if (b.dataset.acc === 'block') return idsBlockDialog(accDet(r).ip, r.host_id, `${r.type === 'ssh' ? 'SSH' : r.type}: ${r.kind === 'authfail' ? L('mislukte pogingen') : 'login'}`);
    if (b.dataset.acc === 'ignore') accIgnoreDialog(r);
  });
  $('#tab-ids').addEventListener('click', async (e) => {
    const go = e.target.closest('[data-goto]');
    if (go) { e.preventDefault(); showTab(go.dataset.goto); return; }
    const b = e.target.closest('[data-ids], [data-unblock], [data-rebase]');
    if (!b) return;
    try {
      if (b.dataset.ids === 'block') return idsBlockDialog(b.dataset.ip, b.dataset.host);
      if (b.dataset.ids) await api('ids_incident', { id: +b.dataset.id, action: b.dataset.ids });
      else if (b.dataset.unblock) {
        if (!confirm(L('Deze blokkade opheffen?'))) return;
        await api('ids_unblock', { id: +b.dataset.unblock });
        toast(L('Gedeblokkeerd; de agent past het binnen een minuut aan'));
      } else if (b.dataset.rebase) {
        if (!confirm(L('Basismeting opnieuw doen? Wat er nu op de server staat wordt als normaal vastgelegd; tot het einde van de basismeting worden afwijkingen niet gemeld.'))) return;
        await api('ids_rebaseline', { host_id: +b.dataset.rebase });
      }
      loadIds();
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#alerts-toggle').onclick = () => {
    const days = $$('#alerts .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });   // het toggle-event bouwt de inhoud op en onthoudt de stand
  };
  $('#alerts').addEventListener('click', (e) => {
    // ⛔ bij een geweigerde verbinding of mislukte login: dat IP blokkeren
    const bl = e.target.closest('[data-block-ip]');
    if (bl) { e.stopPropagation(); return blockDialog(bl.dataset.blockIp); }
    // Kolomkop: sorteren (oplopend → aflopend → standaard)
    const th = e.target.closest('th[data-sort]');
    if (th) {
      const k = th.dataset.sort;
      alertSort = alertSort.key !== k ? { key: k, dir: 1 } : alertSort.dir > 0 ? { key: k, dir: -1 } : { key: '', dir: 1 };
      return saveAlertSort();
    }
    // Small: klik op het bericht klapt het hele bericht uit
    const td = e.target.closest('.alert-table.small .at-msg');
    if (td) return td.parentElement.classList.toggle('at-open');
    // Anders: popup van de check
    const row = e.target.closest('[data-cid]');
    if (row && !e.target.closest('button, a')) checkInfo(row.dataset.cid);
  });
  $$('#alerts-filter .seg-btn').forEach((b) => (b.onclick = () => {
    alertFilter = b.dataset.af;
    try { localStorage.setItem('rzmon-alert-filter', alertFilter); } catch (ex) { /* alleen voor nu */ }
    renderAlerts();
  }));
  $('#alerts-sort').onchange = (e) => {
    const [key, dir] = e.target.value.split(':');
    alertSort = { key, dir: +dir || 1 };
    saveAlertSort();
  };
  $('#tab-settings').addEventListener('click', (e) => {
    const h = e.target.closest('.set-l1nes [data-sk] > h2');
    if (h && !e.target.closest('a, button, input, label')) toggleSetting(h.parentElement);
  });
  $('#tab-settings').addEventListener('keydown', (e) => {
    const h = e.target.closest('.set-l1nes [data-sk] > h2');
    if (h && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleSetting(h.parentElement); }
  });
  $('#set-toggle-all').onclick = () => {
    const shown = setCards().filter(setVisible);
    const all = shown.every((c) => c.classList.contains('set-open'));
    shown.forEach((c) => (setOpen[c.dataset.sk] = !all));
    saveSetOpen();
    applySettingsLayout();
  };
  $('#settings-form').addEventListener('input', () => settingsSummaries());
  $('#settings-form').addEventListener('change', (e) => { if (!e.target.closest('[data-nodirty]')) markSettingsDirty(true, e.target); settingsSummaries(); });
  // Knoppen die iets toevoegen of weghalen (stille uren): ook opslaan
  $('#settings-form').addEventListener('click', (e) => { const b = e.target.closest('[data-q=add], [data-q=del]'); if (b) markSettingsDirty(true, $('#quiet-editor')); });
  $('#settings-form').update_auto.addEventListener('change', backupFormToggle);
  $('#update-check').onclick = async (e) => { const btn = e.currentTarget; btn.disabled = true; await loadUpdate(true); btn.disabled = false; };
  $('#update-badge').onclick = openUpdateDialog;
  $('#agents-update-all').onclick = updateAllAgents;
  $('#backup-upload').onclick = () => $('#backup-file').click();
  $('#backup-file').onchange = (e) => backupUpload(e.target.files[0]);
  $('#backup-list').addEventListener('click', onBackupListClick);
  $('#settings-form').backup_freq.addEventListener('change', backupFormToggle);
  $('#pushover-test').onclick = testPushover;
  $('#webhook-test').onclick = testWebhook;
  $('#email-test').onclick = (e) => testChannel(e, 'email_test', L('Testmail verstuurd 📧'), L('E-mail'));
  $('#mqtt-test').onclick = (e) => testChannel(e, 'mqtt_test', null, 'MQTT');
  $('#maint-all').onclick = () => maintenanceDialog('all', 0, L('alle servers'), state.maintenance);
  $('#maint-banner').addEventListener('click', (e) => e.target.closest('[data-maint-end]') && endMaintenance('all', 0));
  if ($('#password-form')) $('#password-form').addEventListener('submit', changePassword);   // alleen op Mijn account (niet zonder account via het LAN)
  $('#user-form').addEventListener('submit', createUser);
  $('#db-rotate').onclick = dbRotateDialog;
  $$('[data-view-layout]').forEach((b) => (b.onclick = () => setViewPref('layout', b.dataset.viewLayout)));
  $$('[data-view-density]').forEach((b) => (b.onclick = () => setViewPref('density', b.dataset.viewDensity)));
  showViewPrefs();
  $('#users').addEventListener('click', onUsersClick);
  $('#users').addEventListener('change', async (e) => {
    const sel = e.target.closest('select[data-uact=role]');
    if (!sel) return;
    try { await api('user_role', { id: sel.dataset.id, role: sel.value }); toast(L('Rol gewijzigd')); }
    catch (ex) { toast(ex.message, 'err'); }
    loadUsers();
  });
  // Taal: EN links, NL rechts; bewaard bij je account, daarna de pagina opnieuw laden
  const langToggle = $('#lang-toggle');
  if (langToggle) {
    langToggle.onchange = async () => {
      try { await api('lang_set', { lang: langToggle.checked ? 'nl' : 'en' }); location.reload(); }
      catch (ex) { toast(ex.message, 'err'); langToggle.checked = !langToggle.checked; }
    };
  }
  $('#api-key-form').addEventListener('submit', createApiKey);
  initSecurity();
  initSysinfo();
  $('#api-keys').addEventListener('click', apiKeyClick);
  $('#access-form').addEventListener('submit', saveAccess);
  $('#access-form').addEventListener('change', (e) => saveAccess(null, e.target));

  refresh();
  setInterval(() => { if (!document.hidden && !$('#modal').open) refresh(); }, 20000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

function showTab(name) {
  let sub = null;
  if (name.startsWith('settings/')) { sub = name.slice(9); name = 'settings'; }
  if (name === 'settings' && !isAdmin()) name = $('#tab-account') ? 'account' : 'dashboard';   // instellingen: alleen beheerders
  if (!$('#tab-' + name)) name = 'dashboard';
  $$('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  moveTabIndicator();
  $$('.tab-panel').forEach((p) => (p.hidden = p.id !== 'tab-' + name));
  history.replaceState(null, '', '#' + name);
  if (name === 'settings') { loadSettings(); showSettingsSection(sub || setSection); }
  if (name === 'account') loadAccount();
  if (name === 'alerts') loadAlerts();
  if ((name === 'dashboard' || name === 'alerts') && state.badges) refresh();   // tab gezien: teller naar 0
  if (name === 'ids') loadIds();
  if (name === 'vulns') loadVulns();
}

async function refresh() {
  try {
    // Openstaande tab (Dashboard of Meldingen) telt als gezien, maar alleen als je echt kijkt
    const seen = document.hidden ? '' : ['dashboard', 'alerts'].find((t) => !$('#tab-' + t).hidden) || '';
    state = await api('state', null, seen ? { seen } : {});
    // Info-check: telt nooit als storing. Voor tellers, filters en "Problemen" is hij OK; de echte status staat in info_status
    state.hosts.forEach((h) => h.checks.forEach((c) => { if (+c.info_only && +c.status !== 0) { c.info_status = +c.status; c.status = 0; } }));
    if (state.role && document.body.dataset.role !== state.role) location.reload();   // rol gewijzigd
    render();
    if (!$('#tab-alerts').hidden) loadAlerts();
    if (!$('#tab-ids').hidden) loadIds();
    if ($('#tab-vulns') && !$('#tab-vulns').hidden) loadVulns();
    renderTabBadges();
    $('#ids-badge').hidden = !state.ids_open;
    $('#ids-badge').textContent = state.ids_open || '';
    if (isAdmin()) renderAgentUpdates();
    const ub = $('#update-badge');
    ub.hidden = !state.update && !state.update_running;
    ub.textContent = state.update_running ? '⏳ ' + L('Bijwerken…') : state.update ? '⬆ ' + state.update : '';
    // Loopt er een update (ook na herladen of in een ander tabblad): het voortgangsvenster weer openen
    if (state.update_running && !$('#modal').open) { showUpdateDialog({ state: 'queued', step: 'queued', message: '' }, ''); pollUpdate(); }
  } catch (ex) {
    toast(ex.message, 'err');
  }
  // Beschikbaarheid en minigrafieken apart: mag iets langer duren
  try { stats = await api('stats'); render(); } catch (ex) { /* niet kritiek */ }
}

/** Tellers op de tabs Dashboard (nieuwe problemen, kleur van het ergste) en Meldingen (nieuwe meldingen). */
function renderTabBadges() {
  const b = state.badges || { dashboard: 0, alerts: 0 };
  const set = (el, n, title) => { el.hidden = !n; el.textContent = n > 99 ? '99+' : n || ''; el.title = title; };
  set($('#dash-badge'), b.dashboard, L('{n} nieuwe problemen sinds je laatste bezoek', { n: b.dashboard }));
  $('#dash-badge').className = 'tab-badge s' + (b.dashboard_status ?? 2);
  set($('#alerts-badge'), b.alerts, L('{n} nieuwe meldingen sinds je laatste bezoek', { n: b.alerts }));
}

const pct = (v) => (v === null || v === undefined ? '–' : dec(v >= 99.995 ? '100' : v.toFixed(v >= 99 ? 2 : 1)) + '%');

/** Beschikbaarheid van een server (24 u / 7 d / 30 d), of '' zonder netwerkchecks. */
function uptimeLine(h, short) {
  const u = stats.uptime[h.id];
  if (!u) return '';
  if (short) return ` · <span title="${L('Bereikbaar afgelopen 7 dagen')}">⏱ ${pct(u.d7)}</span>`;
  return `<span class="uptime" title="${L('Percentage van de metingen (ping, of anders poort/HTTP) dat de server bereikbaar was')}">
    ${L('Bereikbaar')}: ${L('24 u')} <b>${pct(u.h24)}</b> · ${L('7 d')} <b>${pct(u.d7)}</b> · ${L('30 d')} <b>${pct(u.d30)}</b></span>`;
}

/** Minigrafiek van de laatste 24 uur: lijn voor de waarde, gekleurde streepjes voor de status. */
function sparkSvg(c) {
  const data = stats.sparks[c.id];
  if (!data) return '';
  const W = 240, H = 26;
  const t = typeOf(c);
  const vals = data.filter((d) => d && d[0] !== null).map((d) => d[0]);
  const max = t.unit === '%' ? 100 : Math.max(...vals, +c.warn_threshold || 0, 1) * 1.1;
  const x = (i) => (i / 47) * W;
  const y = (v) => H - 6 - (v / max) * (H - 8);
  // Lege blokjes overslaan: de lijn loopt door naar het volgende meetpunt
  let line = '';
  data.forEach((d, i) => {
    if (d && d[0] !== null) line += `${line ? 'L' : 'M'}${x(i).toFixed(1)},${y(d[0]).toFixed(1)}`;
  });
  const strip = data.map((d, i) => (d ? `<rect class="s${d[1]}" x="${x(i) - W / 96}" y="${H - 3}" width="${W / 48 + .3}" height="3"/>` : '')).join('');
  return `<svg class="spark" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" data-act="history" data-id="${c.id}" role="img">
    <title>${L('Laatste 24 uur – klik voor de grafiek')}</title>${t.unit && vals.length ? `<path d="${line}"/>` : ''}<g>${strip}</g></svg>`;
}

// 4 = alles in onderhoud
function hostStatus(h) {
  const enabled = h.checks.filter((c) => +c.enabled);
  const active = enabled.filter((c) => !c.maintenance);
  if (!active.length) return enabled.length || h.maintenance ? 4 : 3;
  const sts = active.map((c) => +c.status);
  if (sts.includes(2)) return 2;
  if (sts.includes(1)) return 1;
  if (sts.includes(3)) return 3;
  return 0;
}

const STATUS_RANK = { 2: 4, 1: 3, 3: 2, 0: 1, 4: 0 };

/** "14:30" voor vandaag, anders "di 21 okt 14:30" */
function fmtUntil(dt) {
  const d = new Date(dt.replace(' ', 'T'));
  const time = d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === new Date().toDateString()
    ? time : d.toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' }) + ' ' + time;
}

function maintLabel(m) {
  // Automatisch onderhoud loopt "tot opgelost" (de datum is een placeholder ver in de toekomst)
  if (m.auto) return `🔧 ${L('automatisch, tot opgelost')}${m.reason ? ' – ' + m.reason : ''}`;
  return `🔧 ${L('tot {t}', { t: fmtUntil(m.until) })}${m.reason ? ' – ' + m.reason : ''}`;
}

// ---------------------------------------------------------------- Algehele gezondheid
// De toestand en score komen van de server (Health.php); hier alleen weergave en filters.
let statusFilter = null;   // null, of 0-3 (status) / 4 (onderhoud): alleen die checks tonen
const HEALTH_COUNTS = [[0, 'ok', 'OK'], [1, 'warning', 'W'], [2, 'critical', 'C'], [3, 'unknown', '?'], [4, 'maintenance', '🔧']];
const HEALTH_TITLES = { 0: 'OK', 1: 'warning', 2: 'critical', 3: L('onbekend'), 4: L('in onderhoud') };

/** "12 min", "2 u 14 min", "3 d 4 u" */
function dur(dt) {
  const m = Math.max(0, Math.round((Date.now() - new Date(dt.replace(' ', 'T')).getTime()) / 60000));
  if (m < 60) return `${m} min`;
  if (m < 1440) return `${Math.floor(m / 60)} ${L('u')}${m % 60 ? ` ${m % 60} min` : ''}`;
  return `${Math.floor(m / 1440)} ${L('d')}${Math.floor(m % 1440 / 60) ? ` ${Math.floor(m % 1440 / 60)} ${L('u')}` : ''}`;
}

function renderHealth() {
  const hl = state.health;
  if (!hl) return;
  const box = $('#health');
  box.className = 'summary health h-' + hl.state;
  $('#health-label').textContent = { ok: '🟢', attention: '🟠', outage: '🔴' }[hl.state] + ' ' + L(hl.label);
  $('#health-state').title = $('#only-problems').checked ? L('Klik: alles weer tonen') : L('Klik: alleen problemen tonen');
  $('#health-pct').textContent = hl.score + '%';
  $('#health-bar').style.width = hl.score + '%';
  $('#health-counts').innerHTML = HEALTH_COUNTS.map(([st, key, short]) => {
    const n = hl.counts[key];
    if (st === 4 && !n) return '';
    return `<button type="button" class="hc s${st} ${statusFilter === st ? 'on' : ''} ${n ? '' : 'zero'}" data-st="${st}"
      title="${n} check${n === 1 ? '' : 's'} ${HEALTH_TITLES[st]}${statusFilter === st ? ' – ' + L('klik om het filter op te heffen') : ' – ' + L('klik om alleen deze te tonen')}">
      ${st === 4 ? '' : '<span class="hc-dot"></span>'}<b>${n}</b> ${short}</button>`;
  }).join('');

  const parts = [];
  if (hl.quiet_until) parts.push(`<span class="quiet-now" title="${L('Stille uren: er gaan geen meldingen uit. Daarna volgt één bericht met wat dan nog open is.')}">🌙 <b>${L('Stil tot {t}', { t: esc(fmtUntil(hl.quiet_until)) })}</b></span>`);
  const hostLink = (id, text) => `<button type="button" class="hl-link" data-host="${id}">${esc(text)}</button>`;
  const why = { agent: L('agent stil'), parent: L('achter uitgevallen server') };
  const downs = hl.down.map((d) => hostLink(d.id, d.name) + (why[d.reason] ? ` (${why[d.reason]})` : '') + (d.acknowledged ? ' ✔' : '')).join(', ');
  parts.push(`<span class="${hl.down.length ? 'bad' : ''}" title="${L('Servers die reageren (ping, of de agent rapporteert)')}">🖥 ${L('Servers bereikbaar')} <b>${hl.hosts_up}/${hl.hosts_total}</b>${downs ? ` – ${L('onbereikbaar')}: ${downs}` : ''}</span>`);
  parts.push(`<span title="${L('Actieve checks met status OK (checks in onderhoud tellen niet mee)')}">✔ ${L('Checks OK')} <b>${hl.checks_ok}/${hl.checks_total}</b></span>`);
  if (hl.open_problems) {
    const o = hl.oldest_problem;
    parts.push(`<span title="${L('Problemen die nog niet bevestigd zijn')}">⏳ <b>${hl.open_problems}</b> ${hl.open_problems === 1 ? L('open probleem') : L('open problemen')}${o
      ? ` · ${L('langst')}: ${hostLink(o.host_id, o.host + ' – ' + o.check)} (${dur(o.since)})` : ''}</span>`);
  } else if (hl.state === 'ok') {
    parts.push(`<span>${L('Geen open problemen')}</span>`);
  }
  const u = Object.values(stats.uptime).map((x) => x.h24).filter((v) => v !== null && v !== undefined);
  if (u.length) parts.push(`<span title="${L('Gemiddelde bereikbaarheid van alle servers over de afgelopen 24 uur')}">📈 ${L('24 u beschikbaar')} <b>${pct(u.reduce((a, b) => a + b, 0) / u.length)}</b></span>`);
  if (hl.flapping) parts.push(`<span title="${L('Checks die steeds van status wisselen')}">🔀 <b>${hl.flapping}</b> ${L('flapt')}</span>`);
  if (hl.counts.maintenance) parts.push(`<span title="${L('Checks in onderhoud: geen meldingen')}">🔧 <b>${hl.counts.maintenance}</b> ${L('in onderhoud')}</span>`);
  $('#health-info').innerHTML = parts.join('<span class="hl-sep">·</span>');
}

/** Naar een server springen en hem openklappen (vanuit de gezondheidsbalk). */
function jumpToHost(id) {
  statusFilter = null;
  $('#filter').value = '';
  openHosts.add(+id);
  saveOpen();
  render();
  const head = $(`#hosts [data-act=toggle][data-id="${id}"]`);
  if (head) {
    const art = head.closest('article');
    art.scrollIntoView({ behavior: 'smooth', block: 'start' });
    art.classList.add('flash');
    setTimeout(() => art.classList.remove('flash'), 1600);
  }
}

function render() {
  renderHealth();
  const banner = $('#maint-banner');
  banner.hidden = !state.maintenance;
  if (state.maintenance) {
    banner.innerHTML = `<span><b>🔧 ${L('Onderhoud actief')}</b> ${L('tot {t}', { t: esc(fmtUntil(state.maintenance.until)) })}${state.maintenance.reason ? ' – ' + esc(state.maintenance.reason) : ''}.
      ${L('Checks draaien door, maar er gaan geen meldingen uit.')}</span>
      <button class="btn need-user" data-maint-end="all">${L('Beëindigen')}</button>`;
  }
  $('#last-run').textContent = state.last_run ? L('Laatste check') + ': ' + ago(state.last_run) : '';
  let open = 0;
  if (state.badges) open = state.badges.dashboard + state.badges.alerts;   // nieuwe problemen + nieuwe meldingen
  else state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && !c.maintenance && +c.status === 2 && !c.acked_at && open++));
  document.title = (open ? `(${open}) ` : '') + 'RzMon';

  const f = $('#filter').value.trim().toLowerCase();
  const onlyProblems = $('#only-problems').checked;
  const narrowed = onlyProblems || statusFilter !== null;
  const hosts = state.hosts
    .map((h) => {
      let checks = h.checks;
      const hostMatch = !f || (h.name + ' ' + h.address).toLowerCase().includes(f);
      if (!hostMatch) checks = checks.filter((c) => (c.name + ' ' + c.target).toLowerCase().includes(f));
      if (onlyProblems) checks = checks.filter((c) => +c.status !== 0 && +c.enabled);
      if (statusFilter !== null) checks = checks.filter((c) => +h.enabled && +c.enabled && (statusFilter === 4 ? !!c.maintenance : !c.maintenance && +c.status === statusFilter));
      return { ...h, shown: checks, match: hostMatch, autoOpen: !!f && !hostMatch && checks.length > 0 };
    })
    .filter((h) => (h.match && !narrowed) || h.shown.length)
    .sort((a, b) => STATUS_RANK[hostStatus(b)] - STATUS_RANK[hostStatus(a)] || a.name.localeCompare(b.name));

  const el = $('#hosts');
  if (!state.hosts.length) {
    el.innerHTML = `<div class="card empty">${L('Nog geen servers. Klik op <b>＋ Server</b> om te beginnen.')}</div>`;
    return;
  }
  if (!hosts.length) {
    el.innerHTML = `<div class="card empty">${statusFilter !== null ? L('Geen checks met status {s}.', { s: HEALTH_COUNTS.find((k) => k[0] === statusFilter)[2] })
      : onlyProblems ? '🎉 ' + L('Geen problemen gevonden!') : L('Niets gevonden.')}</div>`;
    return;
  }
  const { layout, density } = viewPrefs();
  el.className = 'hosts layout-' + layout + ' density-' + density;
  el.innerHTML = layout === 'l1nes'
    ? (density === 'large' ? lineHeader() : '') + hosts.map((h) => hostLine(h, density === 'large')).join('')
    : hosts.map((h) => hostCard(h, density === 'small')).join('');
  $('#toggle-all').textContent = state.hosts.length && state.hosts.every((h) => openHosts.has(+h.id)) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

// ---------------------------------------------------------------- Weergave (per apparaat)
// Blox = tegels (standaard), L1nes = balken onder elkaar; allebei in Small of Large, elk met een eigen keuze.
// Blox staat standaard op Large (de tegels zoals altijd), L1nes op Small.
function viewPrefs() {
  let layout = 'blox', density = 'large';
  try {
    layout = localStorage.getItem('rzmon-layout') === 'l1nes' ? 'l1nes' : 'blox';
    density = layout === 'l1nes'
      ? (localStorage.getItem('rzmon-density') === 'large' ? 'large' : 'small')
      : (localStorage.getItem('rzmon-blox-density') === 'small' ? 'small' : 'large');
  } catch (e) { /* geen opslag: standaard */ }
  return { layout, density };
}
function setViewPref(key, value) {
  if (key === 'density' && viewPrefs().layout === 'blox') key = 'blox-density';
  try { localStorage.setItem('rzmon-' + key, value); } catch (e) {}
  showViewPrefs();
  render();
}
function showViewPrefs() {
  const { layout, density } = viewPrefs();
  $$('[data-view-layout]').forEach((b) => b.classList.toggle('active', b.dataset.viewLayout === layout));
  $$('[data-view-density]').forEach((b) => { b.classList.toggle('active', b.dataset.viewDensity === density); });
  applySettingsLayout();
  if (alertData) renderAlerts();
}

// ---------------------------------------------------------------- Instellingen in L1nes
// Blox: kaarten naast elkaar (zoals altijd). L1nes: rijen over de volle breedte in groepen, open te klappen;
// Small = standaard dicht, Large = standaard open. Wat je open- of dichtklapt, wordt per apparaat onthouden.
function applySettingsLayout() {
  const { density } = viewPrefs();
  const lines = false;   // 1.60.0: de instellingen hebben één vaste opmaak (zijbalk), los van Blox/L1nes
  const sec = $('#tab-settings');
  if (!sec) return;
  sec.classList.toggle('set-l1nes', lines);
  setCards().forEach((card) => {
    const h = $('h2', card);
    if (!$('.set-sum', h)) h.insertAdjacentHTML('beforeend', '<span class="set-sum"></span>');
    const open = !lines || (setOpen[card.dataset.sk] ?? density === 'large');
    card.classList.toggle('set-open', open);
    if (lines) { h.setAttribute('role', 'button'); h.tabIndex = 0; h.setAttribute('aria-expanded', open); }
    else { h.removeAttribute('role'); h.removeAttribute('tabindex'); h.removeAttribute('aria-expanded'); }
  });
  // Een groep zonder zichtbare onderdelen (bv. voor een gebruiker zonder beheerrechten) niet tonen
  $$('.set-group', sec).forEach((g) => {
    g.hidden = !setCards().some((c) => SET_GROUPS[c.dataset.sk] === g.dataset.group && setVisible(c));
  });
  const shown = setCards().filter(setVisible);
  $('#set-toggle-all').textContent = shown.length && shown.every((c) => c.classList.contains('set-open')) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
  settingsSummaries();
}

function toggleSetting(card) {
  setOpen[card.dataset.sk] = !card.classList.contains('set-open');
  saveSetOpen();
  applySettingsLayout();
}

/** Korte samenvatting achter elke titel in L1nes, uit wat er nu in het formulier staat. */
function settingsSummaries() {
  const f = $('#settings-form');
  if (!settingsLoaded || !f || !$('#tab-settings').classList.contains('set-l1nes')) return;
  const on = (x) => `<span class="good-txt">✔ ${L('aan')}</span>${x ? ' · ' + x : ''}`;
  const off = `<span class="muted">✘ ${L('uit')}</span>`;
  const opt = (sel) => (sel && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent.trim() : '');
  const when = (r) => (!r ? '?' : r.mode === 'never' ? L('nooit') : r.mode === 'now' ? L('direct') : L('na {n} min', { n: r.min }));
  const txt = (sel) => ($(sel) ? $(sel).textContent.replace(/\s+/g, ' ').trim() : '');
  const sum = {};
  if (isAdmin()) {
    sum.pushover = f.pushover_enabled.checked ? on(`critical: ${opt(f.pushover_priority_crit)} · warning: ${opt(f.pushover_priority_warn)}`) : off;
    if (notifyGlobal) {
      const n = notifyGlobal.get(), q = quietGlobal ? quietGlobal.get() : null;
      sum.notify = esc([`warning: ${when(n.rules.warn)}`, `critical: ${when(n.rules.crit)}`, n.rules.crit && n.rules.crit.repeat ? L('herhalen elke {n} min', { n: n.rules.crit.repeat }) : '',
        q && q.on && q.windows.length ? `🌙 ${quietText(q.windows)}` : '', n.flap.on ? 'anti-flapping' : ''].filter(Boolean).join(' · '));
    }
    sum.email = f.email_enabled.checked ? on(esc(f.mail_to.value)) : off;
    sum.webhook = f.ha_webhook_enabled.checked ? on('') : off;
    sum.mqtt = f.mqtt_enabled.checked ? on(esc(txt('#mqtt-status').replace(/^✔ /, ''))) : off;
    sum.ids = !$('#ids-enabled').checked ? off
      : $('#ids-mode').checked ? `<span class="warn-txt">⛔ ${L('blokkeren')}</span>` : `<span class="good-txt">✔ ${L('aan')}</span> · ${L('leren (alleen melden)')}`;
    sum.ids += f.auth_events.checked ? ' · 🔑 ' + L('toegang') : '';
    if (fimGlobal && fimGlobal.get().length) sum.ids += ' · 📁 ' + (fimGlobal.get().length === 1 ? L('{n} pad', { n: 1 }) : L('{n} paden', { n: fimGlobal.get().length }));
    if ($('#vuln-enabled')) sum.vulns = $('#vuln-enabled').checked ? on(esc(L('dagelijks om {t}', { t: f.vuln_time.value }))) : off;
    if (sysinfoCfg) {
      const all = SYSINFO_ROWS.flatMap(([, rows]) => rows.map(([k]) => k));
      const out = all.filter((k) => sysinfoCfg[k] && (sysinfoCfg[k].pushover || sysinfoCfg[k].email || sysinfoCfg[k].webhook)).length;
      sum.sysinfo = esc(L('{n} van {t} naar buiten', { n: out, t: all.length })) + ' · ' + esc(L('{n} op de meldingenpagina', { n: all.filter((k) => sysinfoCfg[k] && sysinfoCfg[k].page).length }));
    }
    if (secInfo) {
      sum.enc = !secInfo.enc.key ? `<span class="warn-txt">⚠️ ${L('geen sleutel')}</span>` : secInfo.enc.mode === 'off' ? off
        : `<span class="good-txt">🔒 ${esc(ENC_MODES.find((m) => m[0] === secInfo.enc.mode)[1].split(' (')[0])}</span> · ${L('{n} van {t} versleuteld', { n: secInfo.hosts.filter((h) => +h.enc_seen === 1).length, t: secInfo.hosts.length })}`;
      sum.blocks = secInfo.blocks.length ? `⛔ ${secInfo.blocks.length === 1 ? L('1 blokkade') : L('{n} blokkades', { n: secInfo.blocks.length })}` : `<span class="muted">${L('geen')}</span>`;
    }
    sum.alarm = esc(L('agent offline na {a} min · historie {h} dagen · meldingen {m} dagen', { a: f.agent_stale_minutes.value, h: f.retention_days.value, m: f.alerts_days.value }));
    if (updateInfo) {
      sum.update = (updateInfo.available ? `<span class="warn-txt">⬆ ${L('{v} beschikbaar', { v: esc(updateInfo.latest) })}</span>` : `✔ ${esc(updateInfo.current)}${updateInfo.latest ? ' · ' + L('nieuwste versie') : ''}`)
        + (f.update_auto.checked ? ' · ' + L('automatisch om {t}', { t: esc(f.update_time.value) }) : '');
    }
    sum.agents = esc(txt('#agents-status')) + ($('#agent-commands').dataset.current && $('#agent-commands').dataset.current !== 'off'
      ? ' · ' + L('commando\'s {m}', { m: esc(CMD_MODES[$('#agent-commands').dataset.current]) }) : '');
    if (backupInfo && !backupInfo.problem) {
      const last = backupInfo.list[0];
      sum.backup = esc(f.backup_freq.value === 'off' ? L('automatisch uit') : L('{freq} om {t}', { freq: opt(f.backup_freq).toLowerCase(), t: f.backup_time.value }))
        + (last ? ' · ' + L('laatste: {t}', { t: ago(last.at) }) : '') + (backupInfo.last_error ? ` · <span class="warn-txt">✘ ${L('mislukt')}</span>` : '');
    } else if (backupInfo) sum.backup = `<span class="warn-txt">⚠️ ${L('niet ingesteld')}</span>`;
    sum.db = esc(txt('#db-status').split(' · ')[0]);
    const af = $('#access-form');
    sum.access = af.lan_no_login.checked ? (af.lan_as_admin.checked ? L('LAN zonder inloggen (als beheerder)') : L('LAN zonder inloggen')) : L('altijd inloggen');
    if (accessLock && (accessLock.account || accessLock.ip)) sum.access += ' · 🔐 ' + L('blokkeren na {n} pogingen', { n: accessLock.fails });
    const nKeys = $$('#api-keys .key-row').length;
    sum.api = nKeys === 1 ? L('{n} sleutel', { n: nKeys }) : L('{n} sleutels', { n: nKeys });
  }
  if (Array.isArray(users)) sum.users = users.length === 1 ? L('{n} gebruiker', { n: users.length }) : L('{n} gebruikers', { n: users.length });
  setCards().forEach((card) => { const el = $('.set-sum', card); if (el) el.innerHTML = sum[card.dataset.sk] || ''; });
}

function idsModeInfo() {
  const on = $('#ids-enabled').checked, block = $('#ids-mode').checked;
  $('#ids-mode').disabled = !on;
  $('#ids-l-learn').classList.toggle('active', !block);
  $('#ids-l-block').classList.toggle('active', block);
  $('#ids-mode-info').innerHTML = !on ? L('IDS staat uit: de agents verzamelen niets en er wordt niets geblokkeerd.')
    : block ? L('<b class="warn-txt">Blokkeren:</b> brute force wordt gemeld <b>en</b> geblokkeerd; met ⛔ blokkeer je zelf een IP.')
    : L('<b>Leren:</b> alles wordt gemeld, er wordt niets geblokkeerd.');
}

// ---------------------------------------------------------------- Meteen opslaan (1.61.0)
// Elke wijziging gaat vanzelf naar de server: alleen wat anders is dan de opgeslagen stand. Schakelaars en keuzelijsten
// meteen, tekstvelden zodra je ze verlaat (of op Enter). Naast het veld staat dan ✔ opgeslagen (of ✘ met de reden).
let settingsSaved = null;    // de stand die de server heeft
let autosaveTimer = null;
let autosaveEls = new Set();
function markSettingsDirty(dirty, el) {
  if (!dirty) { settingsSaved = settingsData(); setAutosaveState(''); return; }
  if (el) autosaveEls.add(el);
  clearTimeout(autosaveTimer);
  setAutosaveState('busy');
  autosaveTimer = setTimeout(autosaveSettings, 350);
}

async function autosaveSettings() {
  clearTimeout(autosaveTimer);
  if (!settingsSaved) return;
  const now = settingsData();
  const diff = Object.fromEntries(Object.entries(now).filter(([k, v]) => settingsSaved[k] !== v));
  const els = [...autosaveEls];
  autosaveEls = new Set();
  if (!Object.keys(diff).length) { setAutosaveState(''); return; }
  try {
    await api('settings_save', diff);
    Object.assign(settingsSaved, diff);
    els.forEach((el) => savedFlag(el, true));
    setAutosaveState('ok');
  } catch (ex) {
    els.forEach((el) => savedFlag(el, false, ex.message));
    setAutosaveState('err', ex.message);
    toast(ex.message, 'err');
  }
}

/** ✔ opgeslagen / ✘ niet opgeslagen naast het veld (of bij de titel van de kaart) */
function savedFlag(el, ok, why) {
  if (!el || !el.isConnected) return;
  const anchor = el.closest('label, td, .view-row, .acct-ctl, .lock-row, .notify-row') || el.closest('.card')?.querySelector('h2') || el;
  let f = anchor.querySelector(':scope > .saved-flag');
  if (!f) { f = document.createElement('span'); f.className = 'saved-flag'; anchor.append(f); }
  f.textContent = ok ? '✔ ' + L('opgeslagen') : '✘ ' + L('niet opgeslagen');
  f.title = why || '';
  f.classList.toggle('bad', !ok);
  f.classList.remove('fade'); void f.offsetWidth; f.classList.add('show');
  clearTimeout(f._t);
  if (ok) f._t = setTimeout(() => f.classList.add('fade'), 2200);
}

function setAutosaveState(st, why) {
  const el = $('#save-state');
  if (!el) return;
  el.className = 'autosave-state ' + st;
  el.textContent = st === 'busy' ? L('opslaan…') : st === 'ok' ? '✔ ' + L('alles opgeslagen') : st === 'err' ? '✘ ' + L('niet opgeslagen') + (why ? ': ' + why : '') : '';
}

// ---------------------------------------------------------------- Server-tegels
// Ingeklapt: alleen de algehele status. Klik = uitklappen met alle checks.
const openHosts = new Set((() => { try { return JSON.parse(localStorage.getItem('rzmon-open') || '[]'); } catch (e) { return []; } })());
function saveOpen() { try { localStorage.setItem('rzmon-open', JSON.stringify([...openHosts])); } catch (e) {} }
function toggleHost(id) {
  openHosts.has(+id) ? openHosts.delete(+id) : openHosts.add(+id);
  saveOpen();
  render();
}

function hostSummary(h) {
  const enabled = h.checks.filter((c) => +c.enabled);
  if (!enabled.length) return { text: L('Nog geen checks'), cls: 's3' };
  const active = enabled.filter((c) => !c.maintenance);
  if (!active.length) return { text: '🔧 ' + L('In onderhoud'), cls: 's4' };
  const n = [0, 0, 0, 0];
  active.forEach((c) => n[+c.status]++);
  const acked = active.filter((c) => +c.status !== 0 && c.acked_at).length;
  const parts = [];
  if (n[2]) parts.push(L('{n} kritiek', { n: n[2] }));
  if (n[1]) parts.push(L('{n} waarschuwing', { n: n[1] }));
  if (n[3]) parts.push(L('{n} onbekend', { n: n[3] }));
  if (!parts.length) return { text: `${L('Alles OK')} · ${active.length} check${active.length === 1 ? '' : 's'}`, cls: 's0' };
  return { text: parts.join(' · ') + (acked ? ` (${L('{n} bevestigd', { n: acked })})` : ''), cls: 's' + hostStatus(h) };
}

/** Mini-meters voor CPU, RAM en de volste disk */
function hostMeters(h) {
  const pick = (type) => h.checks.filter((c) => c.type === type && +c.enabled && c.last_value !== null)
    .sort((a, b) => b.last_value - a.last_value)[0];
  return [['cpu', 'CPU'], ['ram', 'RAM'], ['disk', 'Disk']].map(([t, l]) => {
    const c = pick(t);
    if (!c) return '';
    const v = Math.min(100, Math.round(+c.last_value));
    return `<span class="meter s${c.maintenance ? 4 : c.status}" title="${esc(c.name)}: ${esc(c.last_message || '')}">
      <span>${l}</span><span class="meter-bar"><i style="width:${v}%"></i></span><b>${v}%</b></span>`;
  }).join('');
}

/** Onderdelen die Blox en L1nes allebei gebruiken. */
function hostParts(h) {
  const problems = h.checks
    .filter((c) => +c.enabled && +c.status !== 0)
    .sort((a, b) => STATUS_RANK[b.maintenance ? 4 : +b.status] - STATUS_RANK[a.maintenance ? 4 : +a.status]);
  const chips = (max) => problems.slice(0, max).map((c) =>
    `<span class="pchip s${c.maintenance ? 4 : c.status} ${c.acked_at ? 'acked' : ''}" title="${esc(c.last_message || '')}">${c.acked_at ? '✔ ' : ''}${esc(c.name)}</span>`).join('')
    + (problems.length > max ? `<span class="pchip more">+${problems.length - max}</span>` : '');
  const counts = [0, 1, 2, 3].map((s) => [s, h.checks.filter((c) => +c.enabled && !c.maintenance && +c.status === s).length])
    .filter(([, n]) => n).map(([s, n]) => `<span class="cnt s${s}" title="${STATUS[s]}">${n}</span>`).join('');
  const parent = h.parent_id ? findHost(h.parent_id) : null;
  return {
    st: hostStatus(h),
    sum: hostSummary(h),
    isOpen: openHosts.has(+h.id) || h.autoOpen,
    chips,
    counts,
    lastChecked: h.checks.reduce((m, c) => (c.last_checked && (!m || c.last_checked > m) ? c.last_checked : m), null),
    title: `${esc(h.name)} <span class="badge">${esc(h.mode)}</span>${+h.enabled ? '' : `<span class="badge">${L('uit')}</span>`}`,
    via: parent ? ` <span class="via" title="${L('Afhankelijk van {name}: geen aparte meldingen als die onbereikbaar is', { name: esc(parent.name) })}">↳ via ${esc(parent.name)}</span>` : '',
    maint: h.maintenance ? `<span class="maint-tag">${esc(maintLabel(h.maintenance))}</span>` : '',
    cls: `card host s${hostStatus(h)} ${+h.enabled ? '' : 'disabled'} ${h.maintenance ? 'in-maint' : ''} ${openHosts.has(+h.id) || h.autoOpen ? 'open' : ''}`,
  };
}

/**
 * Uitgeklapt deel: knoppen en alle checks, in groepen (Systeem, Bereikbaarheid, Services…). Blox: checks als
 * tegels. L1nes (lines = 'small' of 'large'): checks als rijen, net als de servers.
 */
/**
 * Checks van een server in groepen: [[sleutel, titel, checks], …]. Binnen een groep problemen eerst
 * (kritiek → waarschuwing → onbekend → OK → onderhoud → uitgeschakeld), daarna (bij Systeem: CPU, RAM, disks) op naam.
 */
function groupChecks(checks) {
  const rank = (c) => (+c.enabled ? STATUS_RANK[c.maintenance ? 4 : +c.status] : -1);
  const name = (c) => (c.type === 'disk' ? `disk ${c.target}` : c.name);
  const out = CHECK_GROUPS.map(([k, title, types]) => [k, title, checks.filter((c) => types.includes(c.type))
    .sort((a, b) => rank(b) - rank(a) || types.indexOf(a.type) - types.indexOf(b.type)
      || name(a).localeCompare(name(b), LANG, { numeric: true, sensitivity: 'base' }))]);
  const known = CHECK_GROUPS.flatMap((g) => g[2]);
  const other = checks.filter((c) => !known.includes(c.type));
  if (other.length) out.push(['other', L('Overig'), other]);
  return out.filter((g) => g[2].length);
}

/** Een probleem: aan, niet in onderhoud en warning, critical of onbekend */
const isProblem = (c) => +c.enabled && !c.maintenance && +c.status !== 0;

/**
 * Groep "⚠️ Problemen" bovenaan een server: [[sleutel, titel, checks], …] per status (critical → warning → onbekend),
 * bevestigde storingen onderaan apart. Binnen een status op naam.
 */
function problemGroups(list) {
  const byName = (a, b) => a.name.localeCompare(b.name, LANG, { numeric: true, sensitivity: 'base' });
  const open = list.filter((c) => !c.acked_at);
  return [
    ['2', '🔴 ' + L('Critical'), open.filter((c) => +c.status === 2).sort(byName)],
    ['1', '🟠 ' + L('Warning'), open.filter((c) => +c.status === 1).sort(byName)],
    ['3', '⚪ ' + L('Onbekend'), open.filter((c) => +c.status === 3).sort(byName)],
    ['ack', '✔ ' + L('Bevestigd'), list.filter((c) => c.acked_at).sort((a, b) => STATUS_RANK[+b.status] - STATUS_RANK[+a.status] || byName(a, b))],
  ].filter((g) => g[2].length);
}

/**
 * Is de groep Problemen ingeklapt? Bij inklappen onthouden we welke checks er toen in stonden;
 * komt er een nieuw probleem bij, dan gaat de groep vanzelf weer open.
 */
function problemsClosed(h, list) {
  const was = grpClosed[`${h.id}:problems`];
  if (typeof was !== 'string') return false;
  const ids = was.split(',');
  return list.every((c) => ids.includes(String(c.id)));
}

/** Kop van een groep: titel, aantal en de statussen die aandacht vragen */
function groupHead(h, k, title, list, tag) {
  const n = { 2: 0, 1: 0, 3: 0 };
  list.forEach((c) => { if (+c.enabled && !c.maintenance && n[+c.status] !== undefined) n[+c.status]++; });
  const sum = [[2, '🔴'], [1, '🟠'], [3, '⚪']].filter(([s]) => n[s]).map(([s, i]) => `<span class="ad-count">${i} ${n[s]}</span>`).join('') || '<span class="ad-count">✅</span>';
  const closed = k === 'problems' ? problemsClosed(h, list) : !!grpClosed[`${h.id}:${k}`];
  const sig = k === 'problems' ? ` data-sig="${list.map((c) => c.id).join(',')}"` : '';
  return `<${tag} class="grp-head${k === 'problems' ? ' grp-problems' : ''}" data-act="grp" data-id="${h.id}" data-g="${k}"${sig} role="button" tabindex="0" aria-expanded="${!closed}">
    <span class="grp-chev">${closed ? '▸' : '▾'}</span><span class="grp-title">${title}</span>
    <span class="grp-sum"><span class="muted small">${list.length}</span>${sum}</span></${tag}>`;
}

function hostDetail(h, lines = null) {
  const agentInfo = h.mode === 'agent'
    ? `<span class="muted small">Agent: ${h.agent_seen ? ago(h.agent_seen) : L('nog niet gezien')}</span>
       <button class="btn need-admin" data-act="agent" data-id="${h.id}">Agent Manager</button>` : '';
  return `
    <div class="host-detail">
      <div class="detail-bar">
        ${h.checks.some((c) => +c.enabled && +c.status !== 0 && !c.acked_at) ? `<button class="btn ack-btn need-user" data-act="ack-host" data-id="${h.id}">✔ ${L('Alles bevestigen')}</button>` : ''}
        <button class="btn need-user" data-act="maint-host" data-id="${h.id}">🔧 ${L('Onderhoud')}</button>
        <button class="btn need-admin" data-act="add-check" data-id="${h.id}">＋ Check</button>
        <button class="btn need-user" data-act="scan" data-id="${h.id}">🔍 ${L('Poorten')}</button>
        ${h.snmp ? `<button class="btn need-admin" data-act="snmp" data-id="${h.id}">📡 ${L('SNMP-checks')}</button>` : ''}
        ${h.metrics.includes('podman') ? `<button class="btn" data-act="podman" data-id="${h.id}">📦 Containers</button>` : ''}
        <button class="btn need-admin" data-act="edit-host" data-id="${h.id}">✎ ${L('Bewerken')}</button>
        ${agentInfo ? `<span class="spacer"></span>${agentInfo}` : ''}
      </div>
      ${uptimeLine(h, false)}
      ${lines ? checkLines(h, lines === 'large') : checkTiles(h)}
    </div>`;
}

// Blox: een tegel per server
/** Blox-tegel. Small: compacte tegel (naam, status, problemen), meer per rij; Large: met meters en details. */
function hostCard(h, small) {
  const p = hostParts(h);
  if (small) {
    return `
  <article class="${p.cls} tile tile-small">
    <div class="host-head" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${esc(h.address)} · ${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="dot"></span>
      <div class="host-title">
        <strong>${esc(h.name)}</strong>
        <span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.maint}
      </div>
      <span class="counts">${p.counts}</span>
    </div>
    ${p.chips(3) ? `<div class="host-sum" data-act="toggle" data-id="${h.id}"><div class="chips">${p.chips(3)}</div></div>` : ''}
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
  }
  return `
  <article class="${p.cls} tile">
    <div class="host-head" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="dot"></span>
      <div class="host-title">
        <strong>${p.title}</strong>
        <span class="muted mono">${esc(h.address)}</span>${p.via}
        ${p.maint}
      </div>
      <span class="counts">${p.counts}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    <div class="host-sum" data-act="toggle" data-id="${h.id}">
      <div class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</div>
      ${p.chips(4) ? `<div class="chips">${p.chips(4)}</div>` : ''}
      ${hostMeters(h) ? `<div class="meters">${hostMeters(h)}</div>` : ''}
      <div class="muted small sum-foot">${p.lastChecked ? L('Laatste check') + ' ' + ago(p.lastChecked) : ''}${h.mode === 'agent' ? ` · agent ${h.agent_seen ? ago(h.agent_seen) : L('nog niet gezien')}${agentVer(h)}` : ''}${uptimeLine(h, true)}</div>
    </div>
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
}

// L1nes: een balk over de volle breedte per server, als een tabel (Small of Large)
/** Eén meter (CPU, RAM of de volste disk) als tabelcel, of een streepje. */
function meterCell(h, type) {
  const c = h.checks.filter((x) => x.type === type && +x.enabled && x.last_value !== null).sort((a, b) => b.last_value - a.last_value)[0];
  if (!c) return '<span class="muted">–</span>';
  const v = Math.min(100, Math.round(+c.last_value));
  return `<span class="meter s${c.maintenance ? 4 : c.status}" title="${esc(c.name)}: ${esc(c.last_message || '')}">
    <span class="meter-bar"><i style="width:${v}%"></i></span><b>${v}%</b></span>`;
}

function hostLine(h, large) {
  const p = hostParts(h);
  const u = stats.uptime[h.id];
  const agent = h.mode === 'agent' ? `agent ${h.agent_seen ? ago(h.agent_seen) : L('nog niet gezien')}${agentVer(h)}` : '';
  const cells = large
    ? `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.chips(5) ? `<span class="chips">${p.chips(5)}</span>` : ''}</span>
       <span class="lc-m">${meterCell(h, 'cpu')}</span>
       <span class="lc-m">${meterCell(h, 'ram')}</span>
       <span class="lc-m">${meterCell(h, 'disk')}</span>
       <span class="lc-up" title="${L('Bereikbaar afgelopen 7 dagen')}">${u ? pct(u.d7) : '<span class="muted">–</span>'}</span>
       <span class="lc-last muted small">${p.lastChecked ? ago(p.lastChecked) : ''}${agent ? `<br>${agent}` : ''}</span>`
    : `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span></span>
       <span class="counts">${p.counts}</span>`;
  return `
  <article class="${p.cls} line ${large ? 'large' : 'small'}">
    <div class="line-row" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="dot"></span>
      <span class="lc-name"><strong>${p.title}</strong>${large ? `<span class="muted mono small">${esc(h.address)}</span>${p.via}${p.maint}` : ''}</span>
      ${large ? '' : `<span class="lc-addr muted mono small">${esc(h.address)}</span>`}
      ${cells}
      <span class="chev" aria-hidden="true">›</span>
    </div>
    ${p.isOpen ? hostDetail(h, large ? 'large' : 'small') : ''}
  </article>`;
}

/** Koppen boven de kolommen van L1nes Large. */
function lineHeader() {
  return `<div class="line-head" aria-hidden="true"><span></span><span>Server</span><span>Status</span>
    <span class="lc-m">CPU</span><span class="lc-m">RAM</span><span class="lc-m">Disk</span><span class="lc-up">${L('Bereikbaar')}</span><span class="lc-last">${L('Laatste check')}</span><span></span></div>`;
}

/** Wat een check-tegel (Blox) en een check-rij (L1nes) allebei tonen. */
function checkBits(c) {
  const t = typeOf(c);
  const s = +c.status;
  const pctVal = t.unit === '%' && c.last_value !== null ? Math.min(100, +c.last_value) : null;
  const ticks = (pctVal !== null ? (c.warn_threshold !== null ? `<b style="left:${+c.warn_threshold}%"></b>` : '')
    + (c.crit_threshold !== null ? `<b style="left:${+c.crit_threshold}%"></b>` : '') : '');
  const num = (['ms', 'dagen', 'MB', 'treffers', 'stuks'].includes(t.unit) || (c.type === 'snmp' && t.unit !== null && t.unit !== '%')) && c.last_value !== null
    ? (t.unit === 'dagen' && c.type !== 'ha' ? Math.floor(c.last_value) + ' ' + L('dagen') : dec(fmt(c.last_value, t.unit ? L(t.unit) : t.unit))) : '';
  const own = c.maintenance && c.maintenance.scope === 'check';
  const problem = s !== 0 && +c.enabled;
  const ackTitle = c.acked_at ? `${L('Bevestigd door {who}', { who: c.acked_by || '?' })} – ${ago(c.acked_at)}${c.ack_comment ? ': ' + c.ack_comment : ''}. ${L('Klik om in te trekken.')}` : '';
  // Nog niet gemeld: wacht op meer mislukte checks achter elkaar (melden na N)
  const need = +c.notify_fails || +state.notify_fails || 1;
  const pending = problem && !c.acked_at && !c.maintenance && +c.notified_status !== s && need > 1 && +c.status_streak < need
    ? ` <span class="pend-tag" title="${esc(L('Nog niet gemeld: een melding volgt na {need} mislukte checks achter elkaar met deze status (nu {n})', { need, n: +c.status_streak }))}">⏳ ${+c.status_streak}/${need}</span>` : '';
  const flags = pending + (problem && c.acked_at ? ` <button class="ack-tag need-user-act" data-act="unack" data-id="${c.id}" title="${esc(ackTitle)}">✔ ${L('bevestigd')}</button>` : '')
    + (own ? ` <span class="maint-tag" title="${esc(maintLabel(c.maintenance))}">${c.maintenance.auto ? '🔧 ' + L('auto, tot opgelost') : `🔧 ${L('tot {t}', { t: esc(fmtUntil(c.maintenance.until)) })}`}</span>` : '')
    + (c.suppressed_by ? ` <span class="supp-tag" title="${L('Geen melding: een server erboven is onbereikbaar')}">🔕 ${L('onderdrukt')}</span>` : '')
    + (+c.info_only ? ` <span class="info-tag" title="${L('Info-check: geeft alleen ℹ️ info-meldingen en telt nooit als storing')}">ℹ️ ${c.info_status ? STATUS_WORD[c.info_status] : 'info'}</span>` : '')
    + (c.notify ? ` <span class="own-notify" title="${L('Deze check heeft eigen meldregels')}">🔔</span>` : '')
    + (c.reverse_ok != null && REVERSIBLE.includes(c.type) ? ` <span class="rev-tag" title="${L('Omgekeerd: reageert het, dan {a}; reageert het niet, dan {b}', { a: STATUS_WORD[c.reverse_ok], b: STATUS_WORD[c.reverse_fail ?? 0] })}">⇄</span>` : '')
    + ((q) => (q && q.mode === 'never' ? ` <span class="quiet-tag" title="${L('Nooit stil: meldt ook tijdens de stille uren')}">🌙✕</span>`
      : q && q.mode === 'own' ? ` <span class="quiet-tag" title="${L('Eigen stille uren')}: ${esc(quietText(q.windows))}">🌙</span>` : ''))(quietOf(c))
    + (c.flapping_since ? ` <span class="flap-tag" title="${L('Wisselt steeds van status sinds {t}: per status hooguit één melding tot hij stabiel is', { t: esc(fmtUntil(c.flapping_since)) })}">🔀 ${L('flapt')}</span>` : '');
  const actions = `
      ${problem && !c.acked_at ? `<button class="icon-btn ack-btn need-user" title="${L('Storing bevestigen: geen meldingen meer')}" data-act="ack" data-id="${c.id}">✔</button>` : ''}
      <button class="icon-btn need-user" title="${L('Onderhoud')}" data-act="maint-check" data-id="${c.id}">🔧</button>
      <button class="icon-btn need-user" title="${L('Nu checken')}" data-act="run" data-id="${c.id}">↻</button>
      <button class="icon-btn" title="${L('Historie')}" data-act="history" data-id="${c.id}">📈</button>
      <button class="icon-btn need-admin" title="${L('Bewerken')}" data-act="edit-check" data-id="${c.id}">✎</button>`;
  return {
    t, s, pctVal, ticks, num, problem, flags, actions,
    cls: `${c.info_status ? 's-info' : 's' + s} ${+c.enabled ? '' : 'disabled'} ${c.maintenance ? 'in-maint' : ''} ${problem && c.acked_at ? 'acked' : ''}`,
    title: `${c.info_status ? 'ℹ️ ' + STATUS[c.info_status] : STATUS[s]} – ${L('laatst')} ${ago(c.last_checked)}`,
    msg: esc(c.last_message || L('Nog niet gecontroleerd')),
  };
}

// Blox: een check als tegel
function checkRow(c) {
  const b = checkBits(c);
  const bar = b.pctVal !== null ? `<div class="bar" title="${fmt(b.pctVal, '%')}"><i style="width:${b.pctVal}%"></i>${b.ticks}</div>` : '';
  return `
  <li class="chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
    <span class="dot"></span>
    <span class="chk-name">${esc(c.name)}<span class="type">${b.t.label}${b.num ? ` · ${b.num}` : ''}</span>${b.flags}</span>
    <span class="chk-actions">${b.actions}
    </span>
    ${bar}
    ${sparkSvg(c)}
    <span class="chk-msg">${b.msg} · ${ago(c.last_checked)}</span>
  </li>`;
}

// Blox: checks als tegels; problemen bovenaan, de rest in groepen (zie groupChecks)
function checkTiles(h) {
  if (!h.shown.length) return `<ul class="checks"><li class="empty small">${L('Nog geen checks — klik op ＋ Check')}</li></ul>`;
  const probs = h.shown.filter(isProblem);
  const top = probs.length ? `
    <section class="chk-group grp-problems${problemsClosed(h, probs) ? ' closed' : ''}">
      ${groupHead(h, 'problems', '⚠️ ' + L('Problemen'), probs, 'div')}
      <div class="prob-body">${problemGroups(probs).map(([s, title, list]) => `
        <div class="prob-sub s${s}">${title} <span class="muted">(${list.length})</span></div>
        <ul class="checks">${list.map(checkRow).join('')}</ul>`).join('')}</div>
    </section>` : '';
  const rest = h.shown.filter((c) => !isProblem(c));
  const groups = groupChecks(rest);
  if (h.shown.length < GROUP_MIN) return `<div class="chk-groups">${top}${rest.length ? `<ul class="checks">${groups.flatMap((g) => g[2]).map(checkRow).join('')}</ul>` : ''}</div>`;
  return `<div class="chk-groups">${top}${groups.map(([k, title, list]) => `
    <section class="chk-group${grpClosed[`${h.id}:${k}`] ? ' closed' : ''}">
      ${groupHead(h, k, title, list, 'div')}
      <ul class="checks">${list.map(checkRow).join('')}</ul>
    </section>`).join('')}</div>`;
}

// L1nes: checks als rijen onder de server, in dezelfde groepen
function checkLines(h, large) {
  if (!h.shown.length) return `<p class="empty small check-lines-empty">${L('Nog geen checks — klik op ＋ Check')}</p>`;
  const probs = h.shown.filter(isProblem);
  const rest = h.shown.filter((c) => !isProblem(c));
  const groups = groupChecks(rest);
  const head = large ? `<div class="cl-head" aria-hidden="true"><span></span><span>Check</span><span>${L('Waarde')}</span><span class="cl-spark">${L('24 uur')}</span>
    <span>${L('Bericht')}</span><span class="cl-last">${L('Laatst')}</span><span></span></div>` : '';
  const top = probs.length ? `<div class="cl-group grp-problems${problemsClosed(h, probs) ? ' closed' : ''}">
        ${groupHead(h, 'problems', '⚠️ ' + L('Problemen'), probs, 'div')}
        <div class="cl-group-body">${problemGroups(probs).map(([s, title, list]) => `<div class="prob-sub s${s}">${title} <span class="muted">(${list.length})</span></div>
          ${list.map((c) => checkLine(c, large)).join('')}`).join('')}</div></div>` : '';
  const body = top + (h.shown.length < GROUP_MIN ? groups.flatMap((g) => g[2]).map((c) => checkLine(c, large)).join('')
    : groups.map(([k, title, list]) => `<div class="cl-group${grpClosed[`${h.id}:${k}`] ? ' closed' : ''}">
        ${groupHead(h, k, title, list, 'div')}
        <div class="cl-group-body">${list.map((c) => checkLine(c, large)).join('')}</div></div>`).join(''));
  return `<div class="check-lines ${large ? 'large' : 'small'}">${head}${body}</div>`;
}

function checkLine(c, large) {
  const b = checkBits(c);
  const value = b.pctVal !== null
    ? `<span class="cl-meter" title="${fmt(b.pctVal, '%')}"><span class="bar"><i style="width:${b.pctVal}%"></i>${b.ticks}</span><b>${Math.round(b.pctVal)}%</b></span>`
    : b.num ? `<span class="cl-num">${b.num}</span>` : '<span class="muted">–</span>';
  return `
  <div class="cl chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
    <span class="dot"></span>
    <span class="cl-name"><span class="cl-title">${esc(c.name)}</span>${large ? `<span class="type">${b.t.label}</span>` : ` <span class="type">${b.t.label}</span>`}${b.flags ? `<span class="cl-flags">${b.flags}</span>` : ''}</span>
    <span class="cl-value">${value}</span>
    ${large ? `<span class="cl-spark">${sparkSvg(c) || '<span class="muted small">–</span>'}</span>` : ''}
    <span class="cl-msg">${b.msg}</span>
    <span class="cl-last muted small">${ago(c.last_checked)}</span>
    <span class="chk-actions">${b.actions}
    </span>
  </div>`;
}

function findHost(id) { return state.hosts.find((h) => +h.id === +id); }
function findCheck(id) {
  for (const h of state.hosts) { const c = h.checks.find((c) => +c.id === +id); if (c) return [c, h]; }
  return [null, null];
}

async function onHostsClick(e) {
  const b = e.target.closest('[data-act]');
  if (!b) {
    // Klik op een check (niet op een knop, link of de minigrafiek): popup met meer informatie
    const chk = e.target.closest('[data-cid]');
    if (chk && !e.target.closest('button, a, input, select, label')) checkInfo(chk.dataset.cid);
    return;
  }
  const id = b.dataset.id;
  switch (b.dataset.act) {
    case 'grp': {
      // Groep in- of uitklappen (zonder alles opnieuw op te bouwen)
      const key = `${id}:${b.dataset.g}`;
      // Problemen: onthouden welke checks er bij het inklappen in stonden (een nieuw probleem klapt weer open)
      const closing = !b.parentElement.classList.contains('closed');
      if (closing) grpClosed[key] = b.dataset.sig ?? true; else delete grpClosed[key];
      try { localStorage.setItem('rzmon-grp-closed', JSON.stringify(grpClosed)); } catch (ex) { /* alleen voor nu */ }
      b.parentElement.classList.toggle('closed', closing);
      b.setAttribute('aria-expanded', String(!closing));
      $('.grp-chev', b).textContent = closing ? '▸' : '▾';
      return;
    }
    case 'add-check': return checkForm(null, findHost(id));
    case 'edit-host': return hostForm(findHost(id));
    case 'edit-check': { const [c, h] = findCheck(id); return checkForm(c, h); }
    case 'history': { const [c, h] = findCheck(id); return showHistory(c, h); }
    case 'agent': return agentInstructions(findHost(id));
    case 'scan': return portScan(findHost(id));
    case 'snmp': return snmpChecks(findHost(id));
    case 'podman': return podmanOverview(findHost(id));
    case 'toggle': return toggleHost(id);
    case 'ack': { const [c, h] = findCheck(id); return ackDialog('check', id, `${h.name} – ${c.name}`, c.last_message); }
    case 'ack-host': { const h = findHost(id); const n = h.checks.filter((c) => +c.enabled && +c.status !== 0 && !c.acked_at).length; return ackDialog('host', id, h.name, L('{n} onbevestigde storing(en)', { n })); }
    case 'unack':
      if (!confirm(L('Bevestiging intrekken? Er worden dan weer meldingen verstuurd voor deze storing.'))) return;
      try { await api('unack', { id }); toast(L('Bevestiging ingetrokken')); refresh(); } catch (ex) { toast(ex.message, 'err'); }
      return;
    case 'maint-host': { const h = findHost(id); return maintenanceDialog('host', id, h.name, h.maintenance && h.maintenance.scope === 'host' ? h.maintenance : null); }
    case 'maint-check': { const [c, h] = findCheck(id); return maintenanceDialog('check', id, `${h.name} – ${c.name}`, c.maintenance && c.maintenance.scope === 'check' ? c.maintenance : null); }
    case 'run':
      b.disabled = true;
      try {
        const r = await api('check_run', { id });
        toast(`${STATUS[r.result.status]}: ${r.result.message}`, r.result.status === 0 ? 'ok' : 'err');
        await refresh();
      } catch (ex) { toast(ex.message, 'err'); }
      b.disabled = false;
  }
}

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

async function checkInfo(id) {
  let r;
  try { r = await api('check_info', null, { id }); } catch (ex) { return toast(ex.message, 'err'); }
  const c = r.check;
  const [sc, h] = findCheck(c.id);   // dashboardgegevens (onderhoud, bevestigd) als die er zijn
  const now = toTime(r.now);
  const t = TYPES[c.type] || { label: c.type };
  const st = +c.status;
  const problem = st !== 0 && +c.enabled && !+c.info_only;   // een info-check is nooit een storing
  const unit = t.unit || '';
  const rows = [
    ['Type', `${esc(t.label)}${c.target ? ` · <code>${esc(c.target)}</code>` : ''}`],
    ['Interval', `${+c.interval_sec} s`],
    ...(unit && (c.warn_threshold !== null || c.crit_threshold !== null) ? [[L('Drempels'), `${t.low ? L('onder') + ' ' : ''}${c.warn_threshold ?? '–'} / ${c.crit_threshold ?? '–'} ${esc(L(unit))}`]] : []),
    [L('Laatste check'), c.last_checked ? ago(c.last_checked) : L('nog niet')],
    [L('Beschikbaar'), r.uptime.d1 === null ? `<span class="muted">${L('nog geen metingen')}</span>` : `${L('24 u')} <b>${fmtPct(r.uptime.d1)}</b> · ${L('7 d')} <b>${fmtPct(r.uptime.d7)}</b>`],
  ];
  const tags = [
    c.notify ? '🔔 ' + L('eigen meldregels') : '🔔 ' + L('algemene meldregels'),
    (() => { const q = quietOf(c); return q ? (q.mode === 'never' ? '🌙✕ ' + L('nooit stil') : '🌙 ' + L('eigen stille uren')) : '🌙 ' + L('algemene stille uren'); })(),
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
    ...(problem && !c.acked_at ? [{ label: '✔ ' + L('Bevestigen'), cls: 'need-user', onClick: () => ackDialog('check', c.id, `${c.host_name} – ${c.name}`, c.last_message) }] : []),
    { label: '🔧 ' + L('Onderhoud'), cls: 'need-user', onClick: () => maintenanceDialog('check', c.id, `${c.host_name} – ${c.name}`, sc && sc.maintenance && sc.maintenance.scope === 'check' ? sc.maintenance : null) },
    { label: '↻ ' + L('Nu checken'), cls: 'need-user', onClick: async (btn) => {
      btn.disabled = true;
      try { await api('check_run', { id: c.id }); await refresh(); checkInfo(c.id); } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
    } },
    ...(sc ? [{ label: '📈 ' + L('Grafiek'), onClick: () => { close(); showHistory(sc, h); } }] : []),
    ...(sc ? [{ label: '✎ ' + L('Bewerken'), cls: 'need-admin', onClick: () => checkForm(sc, h) }] : []),
  ];
  modal({
    title: `${ST_ICON[st]} ${c.name} – ${c.host_name}`,
    wide: true,
    body: `<div class="ci">
      <p class="ci-status s${st}"><b>${STATUS[st]}</b>${since}<br><span class="ci-msg">${esc(c.last_message || '–')}</span></p>
      <dl class="ci-grid">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
      <p class="small muted ci-tags">${tags.join(' · ')}</p>
      <h3 class="ci-h">${L('Laatste 5 statuswijzigingen')}</h3>
      ${changes ? `<ol class="ci-changes">${changes}</ol>` : `<p class="muted small">${L('Nog geen statuswijzigingen bewaard. Ze worden bijgehouden vanaf versie 1.37.0.')}</p>`}
    </div>`,
    buttons: btns,
  });
}

// ---------------------------------------------------------------- Modal helper
function modal({ title, body, buttons = [], wide = false, onSubmit }) {
  const dlg = $('#modal');
  const form = $('#modal-form');
  dlg.classList.toggle('wide', wide);
  $('#modal-title').textContent = title;
  $('#modal-body').innerHTML = body;
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
function hostForm(h) {
  const isNew = !h;
  h = h || { name: '', address: '', mode: 'remote', enabled: 1 };
  modal({
    title: isNew ? L('Server toevoegen') : `${L('Server bewerken')} – ${h.name}`,
    body: `
      <label>${L('Naam')}<input name="name" value="${esc(h.name)}" required placeholder="${L('bv. Webserver 01')}"></label>
      <label>${L('Hostname / IP-adres')}<input name="address" value="${esc(h.address)}" required placeholder="192.168.1.10" class="mono"></label>
      <label>${L('Type')}
        <select name="mode">${Object.entries(MODES).map(([k, v]) => `<option value="${k}" ${h.mode === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
        <span class="hint">${L('Lokaal = disk/services van deze monitor-server. Agent = remote server die disk/services rapporteert via rzmon-agent.sh. Remote = alleen ping/poort/http.')}</span>
      </label>
      <label>${L('Afhankelijk van')} <span class="hint">${L('(optioneel)')}</span>
        <select name="parent_id">
          <option value="">— ${L('geen')} —</option>
          ${parentOptions(h)}
        </select>
        <span class="hint">${L('Bereikbaar via deze server, bv. een router, switch, VPN of hypervisor. Is die onbereikbaar, dan krijg je één melding voor die server en niet ook voor deze.')}</span>
      </label>
      ${snmpHostFields(h.snmp)}
      <details class="notify-own" id="fim-host-wrap" ${h.mode === 'agent' ? '' : 'hidden'}>
        <summary>📁 ${L('Bestanden bewaken (IDS)')}: <span id="fim-host-label">${L('laden…')}</span></summary>
        <p class="hint" style="margin-top:0">${L('Extra paden voor deze server, bovenop de algemene lijst (<i>Instellingen → 🛡️ IDS</i>). Hetzelfde pad hier gaat voor, bv. om de ernst te veranderen.')}</p>
        <div id="fim-host"></div>
      </details>
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
      const r = await api('host_save', { ...d, id: h.id, enabled: !!d.enabled, add_ping: !!d.add_ping, snmp, ...(fimHost ? { ids_fim: fimHost.get() } : {}) });
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

// ---------------------------------------------------------------- SNMP
/** Blok "📡 SNMP" in het serverformulier; geheimen staan er gemaskeerd in (••••••••) */
function snmpHostFields(s) {
  const c = s || { v: '2c', port: 161, community: '', level: 'authPriv', auth_proto: 'SHA', priv_proto: 'AES' };
  const opt = (list, cur) => list.map(([v, l]) => `<option value="${v}" ${cur === v ? 'selected' : ''}>${l}</option>`).join('');
  return `<details class="notify-own" id="snmp-wrap" ${s ? 'open' : ''}>
    <summary>📡 SNMP: <span id="snmp-label">${s ? L('aan (v{v})', { v: esc(s.v) }) : L('uit')}</span></summary>
    <label class="switch"><input type="checkbox" name="snmp_on" ${s ? 'checked' : ''}><span></span> ${L('SNMP gebruiken')}</label>
    <div id="snmp-fields">
      <div class="row2">
        <label>${L('Versie')} <select name="snmp_v">${opt([['2c', 'v2c (community)'], ['3', L('v3 (gebruiker en wachtwoorden)')]], c.v)}</select></label>
        <label>${L('Poort')} <input name="snmp_port" type="number" min="1" max="65535" value="${+c.port || 161}"></label>
      </div>
      <label data-snmp-v="2c">Community <span class="hint">${L('(alleen lezen; liever niet "public")')}</span>
        <input name="snmp_community" class="mono" autocomplete="off" spellcheck="false" maxlength="100" value="${esc(c.community || '')}"></label>
      <div data-snmp-v="3">
        <div class="row2">
          <label>${L('Gebruiker')} <input name="snmp_user" class="mono" autocomplete="off" spellcheck="false" maxlength="64" value="${esc(c.user || '')}"></label>
          <label>${L('Beveiliging')} <select name="snmp_level">${opt([['authPriv', L('Inloggen + versleutelen (aanbevolen)')], ['authNoPriv', L('Alleen inloggen')], ['noAuthNoPriv', L('Geen')]], c.level || 'authPriv')}</select></label>
        </div>
        <div class="row2" data-snmp-lvl="auth">
          <label>${L('Auth-protocol')} <select name="snmp_auth_proto">${opt([['SHA', 'SHA'], ['SHA256', 'SHA-256'], ['SHA512', 'SHA-512'], ['MD5', L('MD5 (verouderd)')]], c.auth_proto || 'SHA')}</select></label>
          <label>${L('Auth-wachtwoord')} <input name="snmp_auth_pass" type="password" autocomplete="new-password" value="${esc(c.auth_pass || '')}"></label>
        </div>
        <div class="row2" data-snmp-lvl="priv">
          <label>${L('Privacy-protocol')} <select name="snmp_priv_proto">${opt([['AES', 'AES'], ['DES', L('DES (verouderd)')]], c.priv_proto || 'AES')}</select></label>
          <label>${L('Privacy-wachtwoord')} <input name="snmp_priv_pass" type="password" autocomplete="new-password" value="${esc(c.priv_pass || '')}"></label>
        </div>
      </div>
      <div class="snmp-test-row"><button type="button" class="btn" id="snmp-test">🔍 ${L('Test SNMP')}</button><span class="small" id="snmp-test-out"></span></div>
      <p class="hint" style="margin:0">${L('RzMon leest het apparaat zelf uit (UDP-poort 161): zet SNMP aan op het apparaat en geef het IP-adres van RzMon toegang. Daarna kies je bij de server via <b>📡 SNMP-checks</b> wat je wilt bewaken. De community en wachtwoorden worden niet teruggestuurd naar de browser.')}</p>
    </div>
  </details>`;
}

/** SNMP-velden uit het formulier, zoals host_save en snmp_test ze verwachten */
function snmpData(d) {
  return { on: !!d.snmp_on, v: d.snmp_v, port: d.snmp_port, community: d.snmp_community, user: d.snmp_user, level: d.snmp_level,
    auth_proto: d.snmp_auth_proto, auth_pass: d.snmp_auth_pass, priv_proto: d.snmp_priv_proto, priv_pass: d.snmp_priv_pass };
}

function snmpHostInit(h) {
  const form = $('#modal-form');
  const f = (n) => $(`[name=${n}]`, form);
  const show = () => {
    const on = f('snmp_on').checked, v = f('snmp_v').value, lvl = f('snmp_level').value;
    $('#snmp-fields').hidden = !on;
    $$('[data-snmp-v]', form).forEach((el) => { el.hidden = el.dataset.snmpV !== v; });
    $('[data-snmp-lvl=auth]', form).hidden = lvl === 'noAuthNoPriv';
    $('[data-snmp-lvl=priv]', form).hidden = lvl !== 'authPriv';
    $('#snmp-label').textContent = on ? L('aan (v{v})', { v }) : L('uit');
  };
  ['snmp_on', 'snmp_v', 'snmp_level'].forEach((n) => { f(n).onchange = show; });
  show();
  $('#snmp-test').onclick = async () => {
    const out = $('#snmp-test-out');
    out.className = 'small muted';
    out.textContent = L('Bezig…');
    try {
      const d = Object.fromEntries(new FormData(form));
      const r = await api('snmp_test', { id: h && h.id, address: d.address, snmp: snmpData(d) });
      const s = r.sys;
      out.className = 'small good';
      out.innerHTML = `✔ ${esc(s.name || L('(geen naam)'))}${s.vendor ? ` · ${esc(s.vendor)}` : ''}${s.uptime !== null ? ` · ${L('aan sinds {t}', { t: esc(fmtDur(s.uptime)) })}` : ''}<br><span class="muted">${esc(s.descr)}</span>`;
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
  if (own.length) wrap.open = true;
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
      <p class="muted small" style="margin-top:0">${L('Checks blijven draaien en je ziet de status gewoon, maar er gaan <b>geen meldingen</b> uit (Pushover en webhook). Is er na afloop nog iets mis, dan krijg je alsnog een melding.')}</p>
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
function checkForm(c, h, preset = {}) {
  const isNew = !c;
  c = c || { type: 'ping', target: '', name: '', warn_threshold: TYPES.ping.warn, crit_threshold: TYPES.ping.crit, interval_sec: 60, enabled: 1, ...preset };
  const quietOwn = quietOf(c);
  const cmdCfg = (() => { try { return JSON.parse(c.command || '{}') || {}; } catch (e) { return {}; } })();
  const allowed = (h.mode === 'remote' ? ['ping', 'tcp', 'http', 'ssl', 'snmp']
    : Object.keys(TYPES).filter((k) => h.mode === 'agent' || !['podman', 'container', 'log', 'ha', 'command'].includes(k))
      .filter((k) => k !== 'reboot' || h.mode !== 'remote' || c?.type === 'reboot')
      .filter((k) => k !== 'ha' || h.metrics.some((m) => m.startsWith('ha:') || m === 'service:supervisor') || c?.type === 'ha'))
    .filter((k) => k !== 'snmp' || h.snmp || c?.type === 'snmp');
  const disks = h.metrics.filter((m) => m.startsWith('disk:')).map((m) => m.slice(5));
  const svcs = h.metrics.filter((m) => m.startsWith('service:')).map((m) => m.slice(8));
  let opts = {};
  try { opts = JSON.parse(c.options || '{}') || {}; } catch (e) { opts = {}; }
  const certs = h.metrics.filter((m) => m.startsWith('cert:')).map((m) => m.slice(5));
  const containers = h.metrics.filter((m) => m.startsWith('container:')).map((m) => m.slice(10));

  const { form } = modal({
    title: isNew ? L('Check toevoegen – {name}', { name: h.name }) : L('Check bewerken – {name}', { name: c.name }),
    body: `
      <label>${L('Type')}
        <select name="type">${allowed.map((k) => `<option value="${k}" ${c.type === k ? 'selected' : ''}>${TYPES[k].label}</option>`).join('')}</select>
        <span class="hint" id="type-help"></span>
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
        <p class="hint">${L('Spaties en regeleindes aan begin en eind tellen niet mee. Regex zonder scheidingstekens, bv. <code>^ok\\b</code> of <code>temp=[0-5][0-9]</code>. Het commando draait als <code>nobody</code>, of als root als dat voor deze server is toegestaan, in <code>bash</code>, zonder invoer.')}</p>
      </div>
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
      <div id="res-wrap" hidden>
        <p class="hint" style="margin-top:0">${L('✓ De <b>status</b> wordt altijd bewaakt: gestopt of ongezond = CRITICAL, gepauzeerd of net automatisch herstart = WARNING. Vink aan wat je daarnaast wilt bewaken:')}</p>
        ${['cpu', 'mem', 'disk'].map((k) => {
          const r = (opts.res || {})[k];
          const u = k === 'disk' ? 'MB' : '%';
          return `<div class="res-row">
            <label class="switch small" title="${esc(METRICS[k].help)}"><input type="checkbox" name="res_${k}" ${r ? 'checked' : ''}><span></span> ${METRICS[k].label}</label>
            <label>${L('Warning vanaf')} <span class="hint">${u}</span><input type="number" step="any" min="0" name="res_${k}_warn" value="${r ? (r[0] ?? '') : METRICS[k].warn}"></label>
            <label>${L('Critical vanaf')} <span class="hint">${u}</span><input type="number" step="any" min="0" name="res_${k}_crit" value="${r ? (r[1] ?? '') : METRICS[k].crit}"></label>
          </div>`;
        }).join('')}
        <p class="hint">${L('CPU: 100% = één volledige core. RAM: percentage van de geheugenlimiet van de container. Disk: schrijflaag plus volumes.')}</p>
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
      <p class="hint" style="margin-top:-.4rem">${L('Deze check telt nooit als storing: bij een statuswissel komt er een ℹ️ info-melding in plaats van warning, critical of hersteld. Welke kanalen dat zijn, staat bij de rij ℹ️ Info (Instellingen → 🔔 Meldingen, of eigen meldregels hieronder); standaard staat info uit.')}</p>
      <details class="notify-own" ${c.notify || c.notify_fails ? 'open' : ''}>
        <summary>🔔 ${L('Meldingen')}: <span id="notify-mode-label">${c.notify ? L('eigen regels') : L('standaard (algemene instellingen)')}</span><span class="muted" id="notify-fails-label">${c.notify_fails ? ' · ' + L('na {n}× mislukt', { n: +c.notify_fails }) : ''}</span></summary>
        <label>${L('Melden na … mislukte checks achter elkaar')} <span class="hint">${L('(leeg = standaard, nu {n})', { n: state.notify_fails || 1 })}</span>
          <input name="notify_fails" type="number" min="1" max="100" value="${c.notify_fails ?? ''}" placeholder="${state.notify_fails || 1}" style="max-width:8rem"></label>
        <label class="switch small"><input type="checkbox" id="notify-own" ${c.notify ? 'checked' : ''}><span></span> <span id="notify-own-label">${L('Eigen meldregels voor deze check')}</span></label>
        <div id="notify-own-editor" ${c.notify ? '' : 'hidden'}></div>
      </details>
      <details class="notify-own" ${quietOwn ? 'open' : ''}>
        <summary>🌙 ${L('Stille uren')}: <span id="quiet-mode-label">${QUIET_MODES[quietOwn ? quietOwn.mode : 'global']}</span></summary>
        <select id="quiet-mode">${Object.entries(QUIET_MODES).map(([k, l]) => `<option value="${k}" ${(quietOwn ? quietOwn.mode : 'global') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <div id="quiet-own-editor" hidden></div>
      </details>
      <div class="row3" id="thr-wrap">
        <label><span class="thr-word">${L('Warning vanaf')}</span> <span class="hint unit"></span><input name="warn_threshold" type="number" step="any" value="${c.warn_threshold ?? ''}"></label>
        <label><span class="thr-word">${L('Critical vanaf')}</span> <span class="hint unit"></span><input name="crit_threshold" type="number" step="any" value="${c.crit_threshold ?? ''}"></label>
        <label>Interval <span class="hint">sec</span><input name="interval_sec" type="number" min="30" step="30" value="${c.interval_sec}"></label>
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
      ...(isNew ? [] : [{ label: '🗑 ' + L('Verwijderen'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Check "{name}" verwijderen?', { name: c.name }))) return;
        await api('check_delete', { id: c.id });
        $('#modal').close(); toast(L('Check verwijderd')); refresh();
      } }]),
      { label: isNew ? L('Toevoegen & checken') : L('Opslaan & checken'), cls: 'primary', submit: true },
    ],
    onSubmit: async (d) => {
      const notify = $('#notify-own').checked && ownEditor ? ownEditor.get() : null;
      const qm = $('#quiet-mode').value;
      const quiet = qm === 'global' ? null : qm === 'never' ? { mode: 'never' } : { mode: 'own', windows: quietOwnEditor ? quietOwnEditor.get().windows : [] };
      if (qm === 'own' && !quiet.windows.length) throw new Error(L('Stille uren: vul minstens één venster in met een dag'));
      const command = d.type === 'command' ? { cmd: d.cmd, expect: d.cmd_expect, match: d.cmd_match, timeout: d.cmd_timeout, fail: d.cmd_fail } : undefined;
      const res = {};
      ['cpu', 'mem', 'disk'].forEach((k) => { res[k] = { on: !!d[`res_${k}`], warn: d[`res_${k}_warn`], crit: d[`res_${k}_crit`] }; });
      await api('check_save', { ...d, res, notify, quiet, command, id: c.id, host_id: h.id, enabled: !!d.enabled, verify_ssl: !!d.verify_ssl, deep: !!d.deep, info_only: !!d.info_only });
      toast(L('Check opgeslagen'));
    },
  });

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
        { pushover_enabled: g.pushover_enabled === '1', email_enabled: g.email_enabled === '1', ha_webhook_enabled: g.ha_webhook_enabled === '1' });
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
  // Stille uren: algemeen, eigen vensters (start met de algemene) of nooit stil
  let quietOwnEditor = null;
  const showQuiet = async () => {
    const mode = $('#quiet-mode').value;
    $('#quiet-mode-label').textContent = QUIET_MODES[mode];
    $('#quiet-own-editor').hidden = mode !== 'own';
    if (mode === 'own' && !quietOwnEditor) {
      let windows = quietOwn && quietOwn.windows;
      if (!windows || !windows.length) {
        try { windows = JSON.parse((await api('settings')).notify_quiet || '{}').windows; } catch (e) { windows = null; }
      }
      quietOwnEditor = quietEditor($('#quiet-own-editor'), { windows: windows || [] }, false);
    }
  };
  $('#quiet-mode').onchange = showQuiet;
  showQuiet();
  const typeSel = $('[name=type]', form);
  const haSel = $('#ha-wrap [name=metric]', form);
  $('[name=reverse_ok]', form).onchange = () => update(false);
  const snmpSel = $('#snmp-metric');
  const update = (setDefaults) => {
    const isContainer = typeSel.value === 'container';
    const isHa = typeSel.value === 'ha';
    const isSnmp = typeSel.value === 'snmp';
    const oidMode = $('[name=oid_mode]', form).value;
    const t = isHa ? { ...TYPES.ha, ...HA_METRICS[haSel.value] }
      : isSnmp ? snmpType({ metric: snmpSel.value, options: JSON.stringify({ mode: oidMode, unit: $('[name=oid_unit]', form).value, low: $('[name=oid_low]', form).checked }) })
        : TYPES[typeSel.value];
    $('#ha-wrap').hidden = !isHa;
    haSel.disabled = !isHa;   // niet meesturen bij andere types
    $('#snmp-wrap-chk').hidden = !isSnmp;
    $('#snmp-metric-wrap').hidden = !isSnmp;
    snmpSel.disabled = !isSnmp;
    $('#snmp-help').textContent = isSnmp ? SNMP_METRICS[snmpSel.value].help : '';
    const isOid = isSnmp && snmpSel.value === 'oid';
    $('#oid-wrap').hidden = !isOid;
    $$('#oid-wrap [data-oid]', form).forEach((el) => { el.hidden = (el.dataset.oid === 'text') !== (oidMode === 'text'); });
    $('#ha-help').textContent = isHa ? HA_METRICS[haSel.value].help : '';
    $('#type-help').textContent = TYPES[typeSel.value].help;
    $('#res-wrap').hidden = !isContainer;
    $$('#res-wrap input[type=number]', form).forEach((i) => { i.disabled = !$(`[name=${i.name.replace(/_(warn|crit)$/, '')}]`, form).checked; });
    $('#target-wrap').hidden = !t.target;
    $('#target-label').textContent = t.target || '';
    $('[name=target]', form).placeholder = t.ph || '';
    $$('.unit', form).forEach((u) => (u.textContent = t.unit ? L(t.unit) : ''));
    $$('#thr-wrap label', form).slice(0, 2).forEach((l) => (l.hidden = !t.unit));
    const words = $$('.thr-word', form);
    words[0].textContent = t.low ? L('Warning onder') : L('Warning vanaf');
    words[1].textContent = t.low ? L('Critical onder') : L('Critical vanaf');
    $('#verify-wrap').hidden = !['http', 'ssl'].includes(typeSel.value);
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
    $('[name=target]', form).required = !!t.target && !['http', 'ssl'].includes(typeSel.value);
    $('[name=target]', form).disabled = !t.target;   // niet meesturen als er geen doel is (bv. HA-checks zonder doel)
    const haLogs = ['ha:host', 'ha:core', 'ha:supervisor', ...svcs.filter((x) => x.startsWith('addon:')).map((x) => `ha:${x}`)];
    const usbs = h.metrics.filter((m) => m.startsWith('usb:')).map((m) => m.slice(4));
    const list = isHa && haSel.value === 'usb' ? usbs
      : { disk: disks, service: svcs, ssl: certs, container: containers, log: isHaHost(h) ? haLogs : LOG_SOURCES }[typeSel.value] || [];
    $('#log-wrap').hidden = typeSel.value !== 'log';
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
          ? L('<b class="warn-txt">⚠️ Commando\'s staan voor {name} uit.</b> De check blijft onbekend tot je ze aanzet: Instellingen → ⬆ Agents bijwerken, of per server in de Agent Manager.', { name: esc(h.name) })
          : L('Commando\'s op {name}: <b>{mode}</b>.', { name: esc(h.name), mode: esc(CMD_MODES[m]) });
      }).catch(() => {});
    }
    $('[name=pattern]', form).required = typeSel.value === 'log';
    $('#target-list').innerHTML = list.map((v) => `<option value="${esc(v)}">`).join('');
    $('#svc-pick-wrap').hidden = !(typeSel.value === 'service' && h.mode === 'agent' && svcList);
    if (typeSel.value === 'service' && h.mode === 'agent') renderSvcPick();
    if (setDefaults) {
      $('[name=warn_threshold]', form).value = t.warn ?? '';
      $('[name=crit_threshold]', form).value = t.crit ?? '';
      if (setDefaults !== 'metric') $('[name=target]', form).value = typeSel.value === 'disk' ? '/' : '';
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
  typeSel.onchange = () => update(true);
  haSel.onchange = () => update('metric');
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
      f('cmd_timeout').value = p.timeout; f('interval_sec').value = p.interval;
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
        <select id="range"><option value="6">${L('{n} uur', { n: 6 })}</option><option value="24" selected>${L('{n} uur', { n: 24 })}</option><option value="168">${L('{n} dagen', { n: 7 })}</option><option value="720">${L('{n} dagen', { n: 30 })}</option></select>
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
    $('#chart-svg').innerHTML = chartSvg(d.points, c, t, +hours);
  };
  $('#range').onchange = load;
  load();
}

function chartSvg(pts, c, t, hours, H = 260) {
  if (!pts.length) return `<p class="muted">${L('Nog geen meetgegevens.')}</p>`;
  const W = 860, PL = 48, R = 12, T = 12, B = 40;
  const t1 = Math.floor(Date.now() / 1000), t0 = t1 - hours * 3600;
  const x = (ts) => PL + ((ts - t0) / (t1 - t0)) * (W - PL - R);
  const vals = pts.map((p) => p.v).filter((v) => v !== null);
  const hasVals = t.unit && vals.length;
  let max = hasVals ? Math.max(...vals, +c.crit_threshold || 0, +c.warn_threshold || 0) * 1.1 : 1;
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
    if (c.warn_threshold !== null) svg += `<line class="thr-w" x1="${PL}" x2="${W - R}" y1="${y(+c.warn_threshold)}" y2="${y(+c.warn_threshold)}"/>`;
    if (c.crit_threshold !== null) svg += `<line class="thr-c" x1="${PL}" x2="${W - R}" y1="${y(+c.crit_threshold)}" y2="${y(+c.crit_threshold)}"/>`;
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
      <p class="muted small" style="margin-top:0">${L('Scant vanaf de RzMon-server welke TCP-poorten op <code>{address}</code> open staan. Open poorten kun je direct als TCP-check toevoegen, zodat je een melding krijgt als de dienst wegvalt.', { address: esc(h.address) })}</p>
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
    <p class="scan-summary">${ha ? L('Home Assistant: Core, Supervisor, plugins en add-ons') : `Podman ${esc(sm.version || '?')}`} · ${L('{n} van {m} containers draaien', { n: d.containers.filter((c) => c.state === 'running').length, m: d.containers.length })}
      ${(sm.pods || []).length ? ' · pods: ' + sm.pods.map((p) => `${esc(p.name)} (${esc(p.status)})`).join(', ') : ''}
      <br><span class="muted small">${ha ? L('Gestopte add-ons met exitcode 0 zijn bewust uitgezet; een add-on met een fout staat als gestopt (exit 1).')
        : `Images ${fmtBytes(sm.images_size)} · containers ${fmtBytes(sm.containers_size)} · volumes ${fmtBytes(sm.volumes_size)}
      ${sm.reclaimable ? ` · ${L('{size} op te ruimen', { size: fmtBytes(sm.reclaimable) })} (<code>podman system prune</code>)` : ''}`} · ${L('gemeld {ago}', { ago: ago(d.updated_at) })}
      ${(sm.users || []).length > 1 ? ' · ' + L('gebruikers') + ': ' + sm.users.map(esc).join(', ') : ''}</span></p>
    ${sm.error ? `<p class="form-error">${L('Podman-fout: {e}', { e: esc(sm.error) })}</p>` : ''}
    <div class="podman-actions">
      ${d.checks.some((c) => c.type === 'podman') ? `<span class="muted small">✓ ${L('{x}-check aanwezig', { x: ha ? 'Containers' : 'Podman' })}</span>`
        : `<button type="button" class="btn need-admin" id="pc-overall">＋ ${L('{x}-check (algehele status)', { x: ha ? 'Containers' : 'Podman' })}</button>`}
      ${missing.length ? `<button type="button" class="btn need-admin" id="pc-all" title="${L('Alleen de status; CPU, RAM en disk kun je daarna per container aanvinken')}">＋ ${missing.length === d.containers.length ? L('Alle {n} container(s) bewaken', { n: missing.length }) : L('De overige {n} container(s) bewaken', { n: missing.length })}</button>` : ''}
    </div>
    <div class="table-scroll"><table class="port-table podman-table">
      <thead><tr><th>Container</th><th>Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th>${L('Bewaken')}</th></tr></thead>
      <tbody>${rows || `<tr><td colspan="6" class="muted">${L('Geen containers.')}</td></tr>`}</tbody></table></div>
    <p class="muted small">${L('CPU zoals <code>podman stats</code>: 100% = één core. Disk = schrijflaag van de container + zijn volumes. Containers van gewone gebruikers (rootless) staan als <code>gebruiker/naam</code>.')}</p>`;

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
      if (b.id === 'pc-overall') await add({ type: 'podman', name: 'Podman' });
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

// ---------------------------------------------------------------- Instellingen: 🔐 Versleuteling en ⛔ Blokkades
let secInfo = null;
async function loadSecurity() {
  if (!isAdmin() || !$('#enc-card')) return;
  try { secInfo = await api('security'); } catch (ex) { $('#enc-info').textContent = ex.message; return; }
  renderSecurity();
}

function renderSecurity() {
  const e = secInfo.enc;
  $('#enc-mode').innerHTML = ENC_MODES.map(([k, l]) => `<option value="${k}" ${e.mode === k ? 'selected' : ''}>${l}</option>`).join('');
  $('#enc-key').value = e.key || '';
  $('#enc-show').disabled = $('#enc-copy').disabled = !e.key;
  $('#enc-generate').textContent = '🎲 ' + (e.key ? L('Nieuwe sleutel genereren') : L('Sleutel genereren'));
  $('#enc-info').innerHTML = e.key
    ? `${L('Kenmerk')}: <code>${esc(e.key_id)}</code>${e.old_until ? ' · ' + L('de vorige sleutel geldt nog tot {t}', { t: esc(fmtDateTime(e.old_until)) }) : ''}`
    : `<span class="warn-txt">${L('Nog geen sleutel: de agents versturen alles onversleuteld.')}</span>`;
  $('#enc-hosts').innerHTML = secInfo.hosts.length ? `<div class="table-scroll"><table class="vuln-table enc-table">
    <thead><tr><th>${L('Server')}</th><th>${L('Agent')}</th><th>${L('Versleuteld versturen')}</th><th>${L('Laatste rapport')}</th></tr></thead><tbody>
    ${secInfo.hosts.map((h) => `<tr><td>${esc(h.name)}</td><td class="mono small">${esc(h.agent_version || '–')}</td>
      <td><select data-enc-host="${h.id}">${ENC_HOST_MODES.map(([k, l]) => `<option value="${k}" ${(h.enc || '') === k ? 'selected' : ''}>${k === '' ? `${l} (${ENC_MODES.find((m) => m[0] === e.mode)[1].split(' (')[0].toLowerCase()})` : l}</option>`).join('')}</select></td>
      <td class="small">${encSeenText(h)}</td></tr>`).join('')}</tbody></table></div>` : '';
  const b = secInfo.blocks;
  $('#blocks-list').innerHTML = b.length ? `<div class="table-scroll"><table class="vuln-table">
    <thead><tr><th>${L('IP-adres of netwerk')}</th><th>${L('Reden')}</th><th>${L('Door')}</th><th>${L('Sinds')}</th><th>${L('Geweigerd')}</th><th></th></tr></thead><tbody>
    ${b.map((x) => `<tr class="${x.until && new Date(x.until.replace(' ', 'T')) < new Date() ? 'muted' : ''}"><td class="mono">${esc(x.net)}${+x.auto ? ` <span class="badge" title="${esc(L('Door de inlogbeveiliging'))}">${L('auto')}</span>` : ''}</td>
      <td class="small">${esc(x.reason || '')}${x.until ? `<br><span class="muted">${new Date(x.until.replace(' ', 'T')) < new Date() ? L('verlopen') : L('tot {t}', { t: esc(fmtDateTime(x.until)) })}</span>` : ''}</td><td class="small">${esc(x.created_by || '')}</td>
      <td class="small">${esc(fmtDateTime(x.created_at))}</td><td class="small">${+x.hits ? `${x.hits}× · ${ago(x.last_hit)}` : '–'}</td>
      <td><button type="button" class="icon-btn" data-unblock="${x.id}" title="${esc(L('Blokkade opheffen'))}">🗑</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Nog niets geblokkeerd. Je eigen IP-adres is {ip}.', { ip: `<code>${esc(secInfo.my_ip)}</code>` })}</p>`;
  settingsSummaries();
}

function initSecurity() {
  if (!$('#enc-card')) return;
  $('#enc-mode').onchange = async (e) => {
    try { await api('enc_mode', { mode: e.target.value }); toast(L('Opgeslagen')); loadSecurity(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#enc-show').onclick = () => { const k = $('#enc-key'); k.type = k.type === 'password' ? 'text' : 'password'; };
  $('#enc-copy').onclick = async () => {
    try { await navigator.clipboard.writeText($('#enc-key').value); toast(L('Gekopieerd')); }
    catch (e) { const k = $('#enc-key'); k.type = 'text'; k.select(); document.execCommand('copy'); toast(L('Gekopieerd')); }
  };
  $('#enc-generate').onclick = () => modal({
    title: '🎲 ' + L('Sleutel genereren'),
    body: `<p style="margin-top:0">${secInfo && secInfo.enc.key ? L('Er komt een nieuwe sleutel. De agents krijgen hem bij hun volgende rapport (versleuteld met de huidige); de huidige sleutel blijft nog <b>24 uur</b> geldig, zodat er niets misgaat. Een agent die langer offline is, krijgt de nieuwe bij zijn eerste onversleutelde rapport, of geef hem mee met <code>--key</code>.')
      : L('RzMon maakt een willekeurige sleutel van 32 bytes. De agents (1.49.0 of nieuwer) krijgen hem bij hun volgende rapport en versturen daarna alles versleuteld.')}</p>${confirmField('SLEUTEL')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🎲 ' + L('Genereren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('enc_generate', { confirm: d.confirm }); toast(L('Nieuwe sleutel opgeslagen')); loadSecurity(); },
  });
  $('#enc-hosts').addEventListener('change', async (e) => {
    const sel = e.target.closest('[data-enc-host]');
    if (!sel) return;
    try { await api('host_enc', { host_id: +sel.dataset.encHost, enc: sel.value }); toast(L('Opgeslagen; de agent pakt het op bij zijn volgende rapport')); refresh(); }
    catch (ex) { toast(ex.message, 'err'); loadSecurity(); }
  });
  $('#block-add').onclick = async () => {
    try {
      await api('block_add', { net: $('#block-net').value, reason: $('#block-reason').value });
      toast(L('{n} geblokkeerd', { n: $('#block-net').value.trim() }));
      $('#block-net').value = $('#block-reason').value = '';
      loadSecurity();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#blocks-list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-unblock]');
    if (!b || !confirm(L('Deze blokkade opheffen?'))) return;
    try { await api('block_remove', { id: +b.dataset.unblock }); toast(L('Blokkade opgeheven')); loadSecurity(); } catch (ex) { toast(ex.message, 'err'); }
  });
}

/** Venster om een IP-adres (of het netwerk eromheen) te blokkeren, vanuit een melding */
function blockDialog(ip) {
  const v4 = /^\d+\.\d+\.\d+\.\d+$/.test(ip);
  const net = v4 ? ip.replace(/\.\d+$/, '.0/24') : ip.split(':').slice(0, 4).join(':') + '::/64';
  modal({
    title: '⛔ ' + L('{ip} blokkeren', { ip }),
    body: `<p style="margin-top:0">${L('Een geblokkeerd adres krijgt nergens meer toegang tot RzMon: niet de webpagina, niet het inloggen, niet de API en geen agents.')}</p>
      <label class="radio-row"><input type="radio" name="what" value="${esc(ip)}" checked><span>${L('Alleen dit adres')}: <code>${esc(ip)}</code></span></label>
      <label class="radio-row"><input type="radio" name="what" value="${esc(net)}"><span>${L('Het hele netwerk')}: <code>${esc(net)}</code></span></label>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" value="${esc(L('vanuit Meldingen'))}"></label>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⛔ ' + L('Blokkeren'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { await api('block_add', { net: d.what, reason: d.reason }); toast(L('{n} geblokkeerd', { n: d.what })); loadAlerts(); loadSecurity(); },
  });
}

// ---------------------------------------------------------------- Versleuteling van de agents
const ENC_MODES = [['off', L('Uit')], ['on', L('Aan (oude agents mogen nog onversleuteld)')], ['required', L('Verplicht')]];
const ENC_HOST_MODES = [['', L('algemene instelling')], ['on', L('aan')], ['required', L('verplicht')], ['off', L('uit')]];
/** Hoe kwam het laatste rapport binnen: 🔒 versleuteld, 🔓 niet, of nog niets */
function encSeenText(h) {
  if (!h.agent_seen) return L('nog geen rapport');
  if (+h.enc_seen === 1) return '🔒 ' + L('laatste rapport versleuteld');
  return '🔓 ' + L('laatste rapport niet versleuteld') + (h.agent_version && verCmp(h.agent_version, '1.49.0') < 0 ? ' · ' + L('agent {v} of nieuwer nodig', { v: '1.49.0' }) : '');
}

/** "30 s", "2 min" */
function tickText(t) { return +t >= 120 ? `${+t / 60} min` : `${+t} s`; }

function agentInstructions(h) {
  const base = state.base_url || location.origin + location.pathname.replace(/\/[^/]*$/, '');
  const scriptUrl = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api.php?action=agent_script';
  // Met een sleutel (Instellingen → 🔐 Versleuteling) gaat die mee, zodat de agent meteen versleuteld verstuurt
  const keyArg = state.enc_key && h.enc !== 'off' ? ` --key '${state.enc_key}'` : '';
  const installCmd = `curl -fsSL '${base}/api.php?action=agent_install' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}'${keyArg}`;
  const truenasCmd = `curl -fsSL '${base}/api.php?action=agent_install' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}'${keyArg} --truenas`;
  modal({
    title: `Agent Manager – ${h.name}`,
    wide: true,
    body: `
      <div class="agent-live" id="agent-live"><span class="muted">${L('Status ophalen…')}</span></div>
      <div class="cmd-host-row need-admin">⏱ ${L('Rapporteren elke:')}
        <select id="host-tick"><option value="0">${L('standaard ({t})', { t: tickText(state.agent_tick) })}</option>${[30, 60, 120, 300].map((t) => `<option value="${t}" ${+h.agent_tick === t ? 'selected' : ''}>${tickText(t)}</option>`).join('')}</select>
        <span class="muted small" id="host-tick-info">${h.agent_update_via === 'direct' ? L('via cron/TrueNAS: minimaal elke minuut') : ''}</span></div>
      <div class="cmd-host-row need-admin">⚡ Realtime:
        <select id="host-realtime"><option value="0" ${+h.realtime ? '' : 'selected'}>${L('uit')}</option><option value="1" ${+h.realtime ? 'selected' : ''}>${L('aan')}</option></select>
        <span class="muted small" id="host-realtime-info">${h.agent_update_via === 'direct' ? L('werkt alleen met systemd (niet via cron of op TrueNAS)') : L('logins, sudo, services en 🔴-bestanden meteen')}</span></div>
      <div class="cmd-host-row need-admin">ℹ️ ${L('Info bij agent-updates:')}
        <select id="host-info-agent"><option value="1" ${+(h.info_agent ?? 1) ? 'selected' : ''}>${L('aan (volgt de algemene info-regel)')}</option><option value="0" ${+(h.info_agent ?? 1) ? '' : 'selected'}>${L('uit')}</option></select></div>
      <div class="cmd-host-row need-admin">🔐 ${L('Versleuteld versturen:')}
        <select id="host-enc">${ENC_HOST_MODES.map(([k, l]) => `<option value="${k}" ${(h.enc || '') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <span class="muted small">${encSeenText(h)}</span></div>
      <div class="cmd-host-row need-admin">🖥️ ${L('Commando-checks op deze server:')}
        <select id="host-commands"><option value="global">${L('algemene instelling')}</option>${Object.entries(CMD_MODES).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
        <span class="muted small" id="host-commands-info"></span></div>
      <div class="seg" role="tablist">
        <button type="button" class="seg-btn active" data-seg="linux">🐧 ${L('Linux-server')}</button>
        <button type="button" class="seg-btn" data-seg="ha">🏠 Home Assistant OS</button>
        <button type="button" class="seg-btn" data-seg="truenas">🗄️ TrueNAS SCALE</button>
      </div>
      <div data-pane="truenas" hidden>
        <p>${L('Op TrueNAS SCALE horen <code>/usr</code> en <code>/etc</code> bij TrueNAS zelf. De agent komt daarom op je <b>pool</b> (<code>/mnt/&lt;pool&gt;/rzmon-agent</code>), en draait via een <b>cronjob van TrueNAS</b> die ook na een TrueNAS-update blijft staan.')}</p>
        <ol class="steps">
          <li>${L('Open in TrueNAS <i>System → Shell</i> (of log in met SSH als beheerder).')}</li>
          <li>${L('Voer dit uit:')}
            <div class="copy-line"><pre class="code" id="truenas-oneliner">${esc(truenasCmd)}</pre>
              <button type="button" class="btn" id="copy-truenas">${L('Kopieer')}</button></div></li>
        </ol>
        <p class="muted small">${L('De eerste pool wordt gekozen (niet <code>boot-pool</code>); een andere kies je met <code>--pool naam</code>. Het script zet de agent en de instellingen op de pool, maakt de cronjob <i>RzMon agent</i> aan (elke minuut, uitvoer verborgen) en stuurt een testrapport. Had je de agent al met de hand geïnstalleerd? Dan worden je instellingen en cronjob overgenomen.')}</p>
        <p class="muted small">${L('Daarna: bijwerken met de knop hierboven · testen: <code>bash /mnt/&lt;pool&gt;/rzmon-agent/rzmon-agent-setup --test</code> · verwijderen: <code>… --uninstall</code>. Tip: een <b>Commando</b>-check met <code>zpool status -x</code> en verwacht antwoord <code>all pools are healthy</code> bewaakt je pools.')}</p>
      </div>
      <div data-pane="ha" hidden>
        <p>${L('Op Home Assistant OS / Supervised draait de agent als <b>add-on</b>. Hij meldt CPU, RAM, de data-partitie, Core, Supervisor, plugins en add-ons (ook als containers met CPU/RAM), updates, back-ups, onverwachte herstarts, de SD-kaart, USB-sticks en logs. Daarna: <b>＋ Aanbevolen checks</b>.')}</p>
        <ol class="steps">
          <li>${L('In Home Assistant: <i>Instellingen → Add-ons → Add-on winkel</i> → ⋮ → <b>Repositories</b> → voeg <code>https://github.com/rembrand20/rzmon</code> toe.')}
            <span class="muted small">${L('(Of kopieer de map <code>ha-addon/rzmon_agent</code> naar de share <code>addons</code> met de <i>Samba share</i>-add-on.)')}</span></li>
          <li>${L('⋮ → <b>Controleren op updates</b> en installeer <b>RzMon Agent</b>.')}</li>
          <li>${L('Tabblad <b>Configuratie</b>:')}</li>
        </ol>
        <pre class="code">monitor_url: ${esc(base)}
token: ${esc(h.agent_token || '')}
verify_ssl: true</pre>
        <ol class="steps" start="4">
          <li>${L('<b>Starten</b>. In het logboek moet <code>Verbonden met RzMon ✔</code> staan.')}</li>
          <li>${L('Voeg hier checks toe met <b>＋</b>: <i>Service</i> <code>homeassistant</code>, <code>supervisor</code>, <code>updates</code> of <code>addon:&lt;slug&gt;</code>, en <i>Diskruimte</i> <code>/data</code>.')}</li>
        </ol>
        <p class="muted small">${L('Tip: zet er ook een ping-check en een TCP-check op poort 8123 bij. Die werken los van de agent.')}</p>
      </div>
      <div data-pane="linux">
      <p>${L('Voer dit één commando uit op <b>{name}</b> (Rocky/RHEL/Alma, Debian/Ubuntu, Raspberry Pi OS, Proxmox). Het installeert de agent, stelt de services voor die er draaien, zet een systemd-timer aan en stuurt een testrapport.', { name: esc(h.name) })}</p>
      <div class="copy-line"><pre class="code" id="agent-oneliner">${esc(installCmd)}</pre>
        <button type="button" class="btn" id="copy-oneliner">${L('Kopieer')}</button></div>
      ${base.startsWith('https') ? `<p class="muted small">${L('Self-signed certificaat op RzMon? Voeg <code>-k</code> toe aan curl en <code>--insecure</code> aan het eind.')}</p>` : ''}
      <p class="muted small">${L('Daarna: klik hier op <b>＋</b> en voeg checks toe (CPU, RAM, diskruimte, services). Bijwerken gaat daarna met de knop hierboven (of <code>sudo rzmon-agent-setup --update</code>) · verwijderen: <code>sudo rzmon-agent-setup --uninstall</code>.')}</p>
      <p class="muted small">🩺 ${L('<b>Komt er niets binnen?</b> Voer op de server <code>sudo rzmon-agent-setup --test</code> uit. Die controleert stap voor stap planning, netwerk, adres, token en een proefrapport, en zegt wat er mis is.')}</p>
      <p class="muted small">⚠️ ${L('De server moet RzMon kunnen bereiken op <code>{url}</code>. Klopt dat adres niet, pas dan de <i>Basis-URL</i> aan in Setup.', { url: esc(base) })}</p>
      <details class="small api-docs">
        <summary>${L('Liever handmatig installeren?')}</summary>
      <p class="muted small">${L('1. Download <a href="{url}" target="_blank">rzmon-agent.sh</a> en kopieer het naar de server.', { url: esc(scriptUrl) })}</p>
      <p class="muted small">${L('2. Installeer en configureer:')}</p>
      <pre class="code">sudo install -m 755 rzmon-agent.sh /usr/local/bin/rzmon-agent.sh
sudo tee /etc/default/rzmon-agent &gt;/dev/null &lt;&lt;'EOF'
MONITOR_URL="${esc(base)}"
TOKEN="${esc(h.agent_token || '')}"
CERTS="/etc/letsencrypt/live/*/cert.pem localhost:8443"
EOF
sudo chmod 600 /etc/default/rzmon-agent
echo '* * * * * root set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh &gt;/dev/null 2&gt;&amp;1' | sudo tee /etc/cron.d/rzmon-agent
# Testen:
sudo bash -c 'set -a; . /etc/default/rzmon-agent; /usr/local/bin/rzmon-agent.sh'</pre>
      <p class="muted small">${L('<code>CERTS</code>: certificaatbestanden en/of <code>host:poort</code> (ook op internet) waarvan de agent de vervaldatum meldt. Services kies je na de eerste rapportage hier bij "Check toevoegen" uit een lijst van de server; de agent bewaakt ze dan vanzelf.')}</p>
      </details>
      </div>`,
    buttons: [
      ...(isAdmin() ? [{ label: L('＋ Aanbevolen checks'), style: 'margin-right:auto', onClick: () => recommendedChecks(findHost(h.id)) }] : []),
      { label: L('↻ Nieuw token'), onClick: async () => {
        if (!confirm(L('Nieuw token genereren? De oude agent werkt dan niet meer.'))) return;
        await api('host_token', { id: h.id }); await refresh(); agentInstructions(findHost(h.id));
      } },
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  const body = $('#modal-body');
  const show = (seg) => {
    $$('.seg-btn', body).forEach((b) => b.classList.toggle('active', b.dataset.seg === seg));
    $$('[data-pane]', body).forEach((p) => (p.hidden = p.dataset.pane !== seg));
  };
  $$('.seg-btn', body).forEach((b) => (b.onclick = () => show(b.dataset.seg)));
  pollAgentStatus(h.id);
  $('#host-realtime').onchange = async (e) => {
    try {
      await api('host_realtime', { id: h.id, on: e.target.value === '1' });
      toast(e.target.value === '1' ? L('Realtime aan voor {name}; de agent pakt het binnen een minuut op', { name: h.name }) : L('Realtime uit voor {name}; de agent pakt het binnen een minuut op', { name: h.name }));
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#host-enc').onchange = async (e) => {
    try { await api('host_enc', { host_id: h.id, enc: e.target.value }); toast(L('Opgeslagen; de agent pakt het op bij zijn volgende rapport')); refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  $('#host-info-agent').onchange = async (e) => {
    try { await api('host_info_agent', { id: h.id, on: e.target.value === '1' }); toast(L('Opgeslagen')); refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  $('#host-tick').onchange = async (e) => {
    try {
      const r = await api('host_tick', { id: h.id, tick: +e.target.value });
      toast(L('{name} rapporteert elke {tick}; de agent pakt het binnen een minuut op', { name: h.name, tick: tickText(r.tick) }));
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  if (isAdmin()) {
    api('agent_commands').then((r) => {
      const me = r.hosts.find((x) => x.id === +h.id);
      if (!me) return;
      const sel = $('#host-commands');
      sel.value = me.own || 'global';
      $('#host-commands-info').textContent = me.own ? '' : L('(nu: {m})', { m: CMD_MODES[me.mode] });
      sel.onchange = () => setAgentCommands(h.id, sel.value, me.mode, h.name, () => agentInstructions(findHost(h.id)));
    }).catch(() => {});
  }
  $('#copy-truenas').onclick = async () => {
    try { await navigator.clipboard.writeText(truenasCmd); toast(L('Commando gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren($('#truenas-oneliner')); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  };
  $('#agent-live').onclick = async (e) => {
    const b = e.target.closest('[data-agent-upd]');
    if (!b) return;
    b.disabled = true;
    try {
      await api(b.dataset.agentUpd === 'go' ? 'agent_update' : 'agent_update_cancel', { id: h.id });
      toast(b.dataset.agentUpd === 'go' ? L('Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport') : L('Geannuleerd'));
    } catch (ex) { toast(ex.message, 'err'); }
    pollAgentStatus(h.id);
  };
  $('#copy-oneliner').onclick = async () => {
    try { await navigator.clipboard.writeText(installCmd); toast(L('Commando gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren($('#agent-oneliner')); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  };
  // Raad Home Assistant bij een naam of agent-gegevens die erop wijzen
  if (/home.?assistant|hass|haos/i.test(h.name) || h.metrics.includes('service:homeassistant')) show('ha');
  else if (/truenas|freenas/i.test(h.name) || h.metrics.some((m) => m.startsWith('service:middlewared'))) show('truenas');
}

/** Aanbevolen commando-checks voor deze server (TrueNAS, Proxmox of Linux), met vinkjes; bestaande slaan we over. */
/** Aanbevolen checks voor de Home Assistant-add-on: OS, containers, USB-sticks en logs */
function haRecommended(h) {
  const has = (type, metric, target = '') => h.checks.some((c) => c.type === type && (c.metric || '') === metric && (target === '' || c.target === target));
  const items = [
    ...['backup', 'integrations', 'repairs', 'reboot', 'disklife', 'osupdate'].map((m) => ({ key: `ha_${m}`, label: HA_METRICS[m].label, exists: has('ha', m),
      data: { type: 'ha', metric: m, warn_threshold: HA_METRICS[m].warn ?? '', crit_threshold: HA_METRICS[m].crit ?? '' } })),
    ...h.metrics.filter((m) => m.startsWith('usb:')).map((m) => ({ key: `usb_${m}`, label: L('USB-apparaat {name}', { name: m.slice(4) }), exists: has('ha', 'usb', m.slice(4)),
      data: { type: 'ha', metric: 'usb', target: m.slice(4) } })),
    ...['homeassistant', 'hassio_supervisor'].filter((n) => h.metrics.includes(`container:${n}`)).map((n) => ({ key: `ct_${n}`,
      label: L('Container {name}: status, CPU en RAM', { name: n === 'homeassistant' ? 'Home Assistant Core' : 'Supervisor' }), exists: h.checks.some((c) => c.type === 'container' && c.target === n),
      data: { type: 'container', target: n, name: n === 'homeassistant' ? '📦 Home Assistant Core' : '📦 Supervisor', res: { cpu: { on: true, warn: 80, crit: 95 }, mem: { on: true, warn: 85, crit: 95 }, disk: { on: false } } } })),
    ...[['ha_power', 'ha:host'], ['ha_core', 'ha:core']].map(([p, src]) => ({ key: `log_${p}`, label: LOG_PRESETS[p].label, exists: h.checks.some((c) => c.type === 'log' && c.target === src),
      data: { type: 'log', target: src, name: LOG_PRESETS[p].label.replace(/^\S+\s/, ''), preset: p, pattern: LOG_PRESETS[p].pat, warn_threshold: LOG_PRESETS[p].warn, crit_threshold: LOG_PRESETS[p].crit } })),
  ];
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    wide: true,
    body: `<p style="margin-top:0">${L('Herkend als <b>Home Assistant</b>. Vink aan wat je wilt bewaken; je kunt elke check later nog aanpassen.')}</p>
      <div class="rec-list">${items.map((x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${esc(x.key)}" ${x.exists ? 'disabled' : 'checked'}>
        <span><b>${esc(x.label)}</b>${x.exists ? ' · ' + L('al aanwezig') : ''}</span></label>`).join('')}</div>
      <p class="muted small">${L('Containers, USB-apparaten en logs vragen add-on 1.42.0 of nieuwer met de rol <i>manager</i>. Nog niet op een echte Home Assistant getest: laat weten als iets niet goed uitkomt.')}</p>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const x of chosen) await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, verify_ssl: true, ...x.data });
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

async function recommendedChecks(h) {
  if (isHaHost(h)) return haRecommended(h);
  let list = null, mode = 'off';
  try { list = (await api('agent_services', null, { id: h.id })).list; } catch (e) { /* zonder lijst: Linux */ }
  try { mode = ((await api('agent_commands')).hosts.find((x) => x.id === +h.id) || {}).mode || 'off'; } catch (e) { /* leeg */ }
  const { platform, os } = detectPlatform(list);
  const have = new Set(h.checks.filter((c) => c.type === 'command').map((c) => c.name));
  const names = new Set((list || []).map((x) => x[0]));
  // ZFS en Docker ook voorstellen als die diensten op de server draaien
  const offered = (k, p) => platform in p.rec || (k === 'zfs_health' && names.has('zfs-zed')) || (k === 'docker' && names.has('docker'));
  const items = Object.entries(CMD_PRESETS).filter(([k, p]) => offered(k, p) && (!p.os || !os || p.os === os));
  // Standaard aangevinkt: aanbevolen voor dit platform (bij onbekende Linux-variant niet de distro-specifieke)
  const checked = (k, p) => (p.rec[platform] && (!p.os || p.os === os)) || (k === 'zfs_health' && names.has('zfs-zed')) || (k === 'docker' && names.has('docker'));
  const row = ([k, p]) => {
    const exists = have.has(p.name);
    return `<label class="rec-row ${exists ? 'muted' : ''}"><input type="checkbox" name="rec_${k}" ${!exists && checked(k, p) ? 'checked' : ''} ${exists ? 'disabled' : ''}>
      <span><b>${esc(p.label)}</b>${p.root ? ' <span class="badge-root">root</span>' : ''}${exists ? ' · ' + L('al aanwezig') : ''}<br>
      <code class="small">${esc(p.cmd.length > 90 ? p.cmd.slice(0, 87) + '…' : p.cmd)}</code></span></label>`;
  };
  const needRoot = items.some(([, p]) => p.root);
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    wide: true,
    body: `<p style="margin-top:0">${L('Herkend als <b>{p}</b>{os}. Vink aan wat je wilt bewaken; elke regel wordt een <i>Commando</i>-check die je later nog kunt aanpassen.', { p: CMD_GROUPS[platform], os: os ? ` (${os === 'rhel' ? 'Rocky/RHEL' : L('Debian-familie')})` : '' })}</p>
      ${mode === 'off' ? `<p class="warn-txt small">${L('<b>⚠️ Commando\'s staan voor {name} uit.</b> De checks blijven onbekend tot je ze aanzet (Agent Manager → Commando-checks op deze server).', { name: esc(h.name) })}</p>`
        : mode === 'nobody' && needRoot ? '<p class="warn-txt small">' + L('⚠️ Checks met <b>root</b> werken alleen als commando\'s op deze server <i>als root</i> mogen.') + '</p>' : ''}
      <div class="rec-list">${items.map(row).join('')}</div>
      <p class="muted small">${L('Voorinstellingen voor TrueNAS en Proxmox zijn nog niet op een echt systeem getest; laat weten als er een niet goed uitkomt.')}</p>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter(([k]) => d['rec_' + k]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const [, p] of chosen) {
        await api('check_save', { host_id: h.id, type: 'command', name: p.name, interval_sec: p.interval, enabled: true,
          command: { cmd: p.cmd, expect: p.expect, match: p.match, timeout: p.timeout, fail: p.fail } });
      }
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

/** Versies vergelijken ("1.10.0" > "1.9.2"); negatief als a ouder is dan b. */
function verCmp(a, b) {
  const pa = String(a).split('.').map(Number), pb = String(b).split('.').map(Number);
  for (let i = 0; i < 3; i++) if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) - (pb[i] || 0);
  return 0;
}
/** Agent-versie als tekst, met ⬆ als er een nieuwere agent is (eigen nummer, los van RzMon). */
function agentVer(h) {
  const busy = h.agent_update_req ? ' <span title="' + L('Wordt bijgewerkt') + '">⏳</span>' : '';
  if (!h.agent_version) return h.agent_seen ? ` · ${L('versie onbekend')} ⬆${busy}` : '';
  return ` · v${esc(h.agent_version)}${state.agent_latest && verCmp(h.agent_version, state.agent_latest) < 0 ? ' ⬆' : ''}${busy}`;
}

/** Live-status van de agent in het venster, elke 5 s zolang het open is. */
let agentPollGen = 0;
async function pollAgentStatus(hostId, gen = ++agentPollGen) {
  const box = $('#agent-live');
  // Stoppen als het venster dicht is of opnieuw geopend (dan loopt er al een nieuwe lus)
  if (!box || !$('#modal').open || gen !== agentPollGen) return;
  try {
    const s = await api('agent_status', null, { id: hostId });
    const age = s.seen ? Math.round((new Date(s.now.replace(' ', 'T')) - new Date(s.seen.replace(' ', 'T'))) / 1000) : null;
    const fresh = age !== null && age < 180;
    let html = s.mode !== 'agent'
      ? `<b class="bad">${L('✘ Deze server staat op type "{mode}".', { mode: esc(s.mode) })}</b> ${L('Een agent werkt alleen bij type <b>Remote met agent</b> (✎ Bewerken).')}`
      : s.seen
        ? `<b class="${fresh ? 'good' : 'bad'}">${fresh ? '✔' : '⚠'} ${L('Laatste rapport {ago}', { ago: ago(s.seen) })}</b>${s.ip ? ' ' + L('vanaf {ip}', { ip: `<code>${esc(s.ip)}</code>` }) : ''}${fresh ? '' : ' — ' + L('de agent rapporteert niet meer')}`
        : '<b class="wait">' + L('⏳ Nog geen rapport ontvangen') + '</b> <span class="muted small">' + L('(wordt elke 5 s bijgewerkt)') + '</span>';
    if (s.mode === 'agent' && s.seen) {
      const old = !s.agent_version || verCmp(s.agent_version, s.version) < 0;
      const u = s.update || {};
      const canRemote = u.via === 'systemd' || u.via === 'direct';
      html += `<div class="small">${L('Agent-versie <b>{v}</b>', { v: s.agent_version ? esc(s.agent_version) : L('onbekend (ouder dan 1.6.0)') })}${old ? ', ' + L('nieuwste agent {v}', { v: esc(s.version) }) : ' ✔ ' + L('nieuwste')}</div>`;
      if (u.status) html += `<div class="small agent-upd-status">${esc(u.status)}</div>`;
      html += `<div class="small">${realtimeText(s.realtime)}</div>`;
      const tw = toolsWarning(s.realtime && s.realtime.tools, s.update && s.update.via);
      if (tw) html += `<div class="small tools-warn">${tw}</div>`;
      if (isAdmin() && u.pending) {
        html += '<div class="btn-row agent-upd"><button type="button" class="btn" data-agent-upd="cancel">' + L('Bijwerken annuleren') + '</button></div>';
      } else if (isAdmin() && (old || canRemote)) {
        html += `<div class="btn-row agent-upd"><button type="button" class="btn ${old ? 'primary' : ''}" data-agent-upd="go">⬆ ${old ? L('Bijwerken naar {v}', { v: esc(s.version) }) : L('Opnieuw installeren')}</button>
          <span class="muted small">${canRemote ? L('De agent werkt zichzelf bij binnen een minuut.')
            : u.via === 'ha' ? L('Home Assistant-add-on: bijwerken via de add-on winkel.')
            : u.via === 'off' ? L('Op afstand bijwerken staat uit op deze server (REMOTE_UPDATE=0).')
            : L('Deze agent kan dat nog niet: werk hem één keer bij op de server met <code>sudo rzmon-agent-setup --update</code>.')}</span></div>`;
      }
    }
    if (s.failures.length) {
      html += `<div class="fails"><span class="muted small">${L('Geweigerde pogingen (24 uur):')}</span>
        ${s.failures.map((f) => `<div class="small"><code>${esc(f.ip)}</code> · ${ago(f.created_at)}${+f.n > 1 ? ` (${f.n}×)` : ''} · ${esc(f.reason)}</div>`).join('')}</div>`;
    }
    if (gen === agentPollGen) box.innerHTML = html;
  } catch (ex) {
    if (gen === agentPollGen) box.innerHTML = `<span class="muted small">${esc(ex.message)}</span>`;
  }
  setTimeout(() => pollAgentStatus(hostId, gen), 5000);
}

// ---------------------------------------------------------------- Alerts
function channelIcons(a) {
  const tip = (title, icon) => `<span title="${L(title)}">${icon}</span>`;
  if (a.kind === 'auth' || a.kind === 'authfail') return `<span class="muted" title="${L('Alleen op deze pagina, niet verstuurd')}">–</span>`;
  if (a.kind === 'ack') return tip('Bevestiging', '✔');
  const out = [];
  if (+a.sent_ok) out.push(tip('Verstuurd via Pushover', '📲'));
  if (a.email_ok !== null && a.email_ok !== undefined) out.push(+a.email_ok ? tip('Verstuurd per e-mail', '📧') : tip('E-mail mislukt', '📧✕'));
  if (a.webhook_ok !== null) out.push(+a.webhook_ok ? tip('Verstuurd naar Home Assistant', '🏠') : tip('Webhook mislukt', '🏠✕'));
  // Nergens heen verstuurd (kanaal of regel staat uit): alleen op deze pagina. Geen ✉️, dat leek op een mislukte e-mail.
  if (!out.length) out.push(tip('Niet verstuurd: alleen op deze pagina (zie de meldregels)', '🔕'));
  return out.join(' ');
}

/** "🔴 Critical", "✅ Hersteld", "✔ Bevestigd"… voor de meldingentabel */
function alertStatusText(a) {
  if (a.kind === 'ids') return '🛡️ IDS';
  if (a.kind === 'sec') return '⚠️ ' + L('Geweigerd');
  if (a.kind === 'auth') return '🔑 ' + L('Toegang');
  if (a.kind === 'authfail') return '❌ ' + L('Mislukt');
  if (a.kind === 'ack') return '✔ ' + L('Bevestigd');
  if (a.kind === 'maint') return '🔧 ' + L('Onderhoud');
  if (a.kind === 'info') return 'ℹ️ Info';
  const ev = (a.title.match(/^\S+ (HERSTELD|STABIEL|FLAPPEND|RECOVERED|STABLE|FLAPPING)/) || [])[1];
  if (ev) return { HERSTELD: '✅ ' + L('Hersteld'), RECOVERED: '✅ ' + L('Hersteld'), STABIEL: '⏸ ' + L('Stabiel'), STABLE: '⏸ ' + L('Stabiel'),
    FLAPPEND: '🔀 ' + L('Flapt'), FLAPPING: '🔀 ' + L('Flapt') }[ev];
  return ['✅ OK', '🟠 Warning', '🔴 Critical', '⚪ ' + L('Onbekend')][+a.status] || '';
}

// ---------------------------------------------------------------- Meldingen, per dag
// Alle meldingen van de bewaartermijn, per dag in een uitklapblok. Vandaag staat open; wat je open- of
// dichtklapt blijft zo tot je de pagina herlaadt.

async function loadAlerts() {
  try { alertData = await api('alerts'); } catch (ex) { toast(ex.message, 'err'); return; }
  keepOpen($('#alerts'), renderAlerts);
}

/**
 * Opnieuw tekenen (elke 20 seconden) zonder dat wat je openklapte weer dichtgaat: <details> (zoals Uitvoer of een
 * logboek) en uitgeklapte berichten in L1nes. Herkend aan de server, dag of check eromheen en de kop zonder getallen.
 */
function keepOpen(root, draw) {
  if (!root) return draw();
  const ids = (el) => (el ? [el.dataset.host, el.dataset.day, el.dataset.cid, el.dataset.id].filter(Boolean).join(',') : '');
  const key = (el) => {
    const head = el.tagName === 'DETAILS' ? ($(':scope > summary', el)?.textContent || '') : el.textContent;
    return [ids(el.parentElement && el.parentElement.closest('[data-host],[data-day],[data-cid],[data-id]')), ids(el),
      head.replace(/[\d\s.,:%×()+–-]+/g, ' ').trim().slice(0, 80)].join('|');
  };
  const was = new Map();
  root.querySelectorAll('details').forEach((d) => was.set(key(d), d.open));
  const rows = new Set([...root.querySelectorAll('tr.at-open')].map(key));
  const y = window.scrollY;
  draw();
  root.querySelectorAll('details').forEach((d) => { const k = key(d); if (was.has(k) && d.open !== was.get(k)) d.open = was.get(k); });
  if (rows.size) root.querySelectorAll('tr').forEach((tr) => { if (rows.has(key(tr))) tr.classList.add('at-open'); });
  if (Math.abs(window.scrollY - y) > 2) window.scrollTo(0, y);
}

// ---------------------------------------------------------------- IDS
const IDS_FW = {
  active: (h) => `⛔ ${L('blokkeren actief')}${h.blocks ? ` · ${L('{n} geblokkeerd', { n: h.blocks })}` : ''}`,
  off: () => L('alleen melden'),
  unsupported: () => L('TrueNAS: alleen detecteren'),
  disabled: () => L('blokkeren uit op de server (IDS_BLOCK=0)'),
  error: (h) => `<span class="bad">✘ ${L('firewall: {e}', { e: esc(h.fw_error || L('fout')) })}</span>`,
};

async function loadIds() {
  try { [idsData, accData] = await Promise.all([api('ids'), api('ids_access')]); } catch (ex) { toast(ex.message, 'err'); return; }
  keepOpen($('#tab-ids'), () => { renderIds(); renderAccess(); });
  showIdsTab();
}

function showIdsTab() {
  if (!['inc', 'acc', 'blk'].includes(idsTab)) idsTab = 'inc';
  $$('#ids-tabs .seg-btn').forEach((x) => x.classList.toggle('active', x.dataset.it === idsTab));
  $$('#tab-ids .ids-pane').forEach((x) => { x.hidden = x.dataset.pane !== idsTab; });
}

// ---------------------------------------------------------------- IDS: bestanden bewaken
const FIM_SEV = [[1, '🟠 warning'], [2, '🔴 critical']];
/** Voorstel per gangbare map (die de agent op de server heeft gezien): pad en ernst */
function fimSuggest(dir) {
  if (/^\/(var|srv)\/(www|http)$/.test(dir)) return { p: `${dir}/**/*.php`, s: 2, why: L('nieuwe of gewijzigde PHP-bestanden (webshells)') };
  if (/\/s?bin$/.test(dir)) return { p: dir, s: 2, why: L('eigen programma\'s en scripts') };
  return { p: dir, s: 1, why: dir === '/opt' ? L('eigen software') : L('configuratie') };
}

/**
 * Lijst met paden en ernst. hostId: alleen voorstellen van die server (anders van alle servers).
 * onChange na elke wijziging. @return {{ get: () => {p: string, s: number}[] }}
 */
function fimEditor(el, list, hostId, onChange) {
  let rows = (Array.isArray(list) ? list : []).map((r) => ({ p: String(r.p || ''), s: +r.s === 2 ? 2 : 1 }));
  const changed = () => { if (onChange) onChange(); };
  const draw = () => {
    el.innerHTML = `<div class="fim-rows">${rows.map((r, i) => `<div class="fim-row" data-i="${i}">
        <input class="mono" data-f="p" value="${esc(r.p)}" placeholder="/usr/local/bin" maxlength="300" aria-label="${L('Pad')}">
        <select data-f="s" aria-label="${L('Ernst')}">${FIM_SEV.map(([v, l]) => `<option value="${v}" ${r.s === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <button type="button" class="icon-btn" data-fim="del" title="${L('Weghalen')}">✕</button></div>`).join('')
      || '<p class="muted small fim-empty">' + L('Nog geen paden.') + '</p>'}</div>
      <div class="fim-tools"><button type="button" class="btn small-btn" data-fim="add" ${rows.length >= 20 ? 'disabled' : ''}>＋ ${L('Pad')}</button>
        <button type="button" class="btn small-btn" data-fim="suggest">＋ ${L('Aanbevolen')}</button></div>`;
  };
  el.oninput = el.onchange = (e) => {
    const row = e.target.closest('.fim-row');
    if (!row || !e.target.dataset.f) return;
    const r = rows[+row.dataset.i];
    if (!r || !el.contains(row)) return;   // lijst is intussen opnieuw opgebouwd
    if (e.target.dataset.f === 'p') { r.p = e.target.value.trim(); fimCheck(e.target); } else r.s = +e.target.value;
    changed();
  };
  el.onclick = async (e) => {
    const b = e.target.closest('[data-fim]');
    if (!b) return;
    if (b.dataset.fim === 'add') { rows.push({ p: '', s: 1 }); draw(); $$('.fim-row input', el).pop().focus(); }
    else if (b.dataset.fim === 'del') { rows.splice(+b.closest('.fim-row').dataset.i, 1); draw(); changed(); }
    else if (b.dataset.fim === 'suggest') {
      let ids;
      try { ids = await api('ids'); } catch (ex) { return toast(ex.message, 'err'); }
      // Gangbare mappen die de agent(s) gezien hebben, met op welke servers
      const where = new Map();
      ids.hosts.filter((x) => !hostId || +x.id === +hostId).forEach((x) => (x.fim_cand || '').split(' ').filter(Boolean)
        .forEach((d) => { if (!where.has(d)) where.set(d, []); where.get(d).push(x.name); }));
      const have = new Set(rows.map((r) => r.p));
      const sugg = [...where].map(([d, hosts]) => ({ ...fimSuggest(d), hosts })).sort((a, b) => b.s - a.s || a.p.localeCompare(b.p));
      if (!sugg.length) return toast(ids.enabled ? L('Nog geen voorstellen: de agents (1.40.0+) melden binnen een paar minuten welke mappen er zijn') : L('Zet eerst het IDS aan: dan melden de agents welke mappen er zijn'), 'err');
      // Eigen venster bovenop het formulier (het formulier zelf blijft open)
      const dlg = document.createElement('dialog');
      dlg.className = 'fim-dlg';
      dlg.innerHTML = `<form method="dialog" class="modal-inner"><header><h3>＋ ${L('Aanbevolen paden')}</h3></header>
        <div class="fim-dlg-body"><p class="muted small" style="margin-top:0">${hostId ? L('Mappen die de agent op deze server gevonden heeft. Vink aan wat je wilt bewaken.') : L('Mappen die de agents gevonden hebben. Vink aan wat je wilt bewaken.')}</p>
        ${sugg.map((x, i) => `<label class="switch small fim-sug"><input type="checkbox" name="s${i}" ${have.has(x.p) ? 'disabled' : 'checked'}><span></span>
          <span class="fim-sug-t"><code>${esc(x.p)}</code> ${x.s === 2 ? '🔴' : '🟠'}<br><span class="muted small">${esc(x.why)}${hostId ? '' : ` · ${esc(x.hosts.join(', '))}`}${have.has(x.p) ? ' · ' + L('staat er al') : ''}</span></span></label>`).join('')}</div>
        <footer><button class="btn" value="cancel">${L('Annuleren')}</button><button class="btn primary" value="ok">${L('Toevoegen')}</button></footer></form>`;
      document.body.append(dlg);
      dlg.addEventListener('close', () => {
        if (dlg.returnValue === 'ok') {
          sugg.forEach((x, i) => { if (!have.has(x.p) && dlg.querySelector(`[name=s${i}]`).checked && rows.length < 20) rows.push({ p: x.p, s: x.s }); });
          draw(); changed();
        }
        dlg.remove();
      });
      dlg.showModal();
    }
  };
  draw();
  $$('.fim-row input', el).forEach(fimCheck);
  return { get: () => rows.filter((r) => r.p).map((r) => ({ p: r.p, s: r.s })) };
}

/** Pad controleren zoals RzMon dat doet (Ids::cleanFim), zodat je het meteen ziet */
function fimCheck(input) {
  const p = input.value.trim();
  input.setCustomValidity(!p || (p.startsWith('/') && p.replace(/\/+$/, '') !== '' && !/^\/(proc|sys|dev|run)(\/|$)/.test(p)) ? ''
    : L('Gebruik een volledig pad (begint met /), niet / zelf en niet onder /proc, /sys, /dev of /run'));
  input.title = input.validationMessage;
}

// ---------------------------------------------------------------- IDS → Toegang
const ACC_TYPES = { ssh: 'SSH', console: 'Console', sudo: 'sudo', su: 'su' };
const ACC_COLS = { time: L('Tijd'), type: L('Soort'), server: 'Server', who: L('Wie'), what: L('Wat / vanaf') };
function accDet(r) { try { return JSON.parse(r.detail || '{}') || {}; } catch (e) { return {}; } }
function accWho(r, d) {
  const who = (d.users ? d.users.join(', ') : d.user) || '?';
  return who + (d.as && (r.type === 'su' || r.type === 'sudo') ? ` → ${d.as}` : '');
}

function renderAccess() {
  const d = accData;
  const el = $('#ids-access');
  if (!d) { el.innerHTML = '<p class="muted">' + L('Laden…') + '</p>'; return; }
  // Serverkeuze: alleen servers die in de lijst voorkomen
  const hosts = [...new Map(d.rows.map((r) => [r.host_id, r.host_name])).entries()].sort((a, b) => a[1].localeCompare(b[1], LANG));
  $('#acc-host').innerHTML = '<option value="">' + L('Alle servers') + '</option>' + hosts.map(([id, n]) => `<option value="${id}" ${+accHost === +id ? 'selected' : ''}>${esc(n)}</option>`).join('');
  const fails = d.rows.filter((r) => r.kind === 'authfail').length;
  $('#it-n-acc').textContent = d.rows.length ? `(${d.rows.length})` : '';
  $('#acc-info').textContent = L('{ok} gelukt · {fail} mislukt · laatste {days} dagen', { ok: d.rows.length - fails, fail: fails, days: d.days }) + (d.truncated ? ' ' + L('(alleen de nieuwste 10.000)') : '');
  const off = d.enabled ? '' : '<p class="warn-txt small">' + L('Toegang bijhouden staat uit: er komt niets nieuws bij. Aanzetten onder <a href="#settings/ids" data-goto="settings/ids">Instellingen → 🛡️ IDS</a>.') + '</p>';
  const list = d.rows.filter((r) => (accFilter === 'all' || (accFilter === 'fail' ? r.kind === 'authfail' : r.type === accFilter))
    && (!accHost || +r.host_id === +accHost));
  if (!list.length) {
    el.innerHTML = off + `<p class="muted">${d.rows.length ? L('Niets gevonden met dit filter.') : L('Nog geen logins, sudo of su.')}</p>`;
    $('#acc-toggle').hidden = true;
    return;
  }
  const byDay = new Map();
  list.forEach((r) => { const day = r.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(r); });
  const isOpen = (day) => accDayOpen[day] ?? day === d.today;
  el.innerHTML = off + [...byDay].map(([day, rows]) => {
    const nf = rows.filter((r) => r.kind === 'authfail').length;
    return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${rows.length - nf ? `<span class="ad-count" title="${L('gelukt')}">🔑 ${rows.length - nf}</span>` : ''}${nf ? `<span class="ad-count" title="${L('mislukt')}">❌ ${nf}</span>` : ''}</span>
        <span class="muted small ad-total">${rows.length === 1 ? L('1 regel') : L('{n} regels', { n: rows.length })}</span></summary>
      <div class="ad-body">${isOpen(day) ? accTable(rows) : ''}</div></details>`;
  }).join('');
  $$('.alert-day', el).forEach((det) => det.addEventListener('toggle', () => {
    accDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = accTable(byDay.get(det.dataset.day));
    accToggleLabel();
  }));
  accToggleLabel();
}

function accToggleLabel() {
  const days = $$('#ids-access .alert-day');
  $('#acc-toggle').hidden = days.length < 2;
  $('#acc-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

function accTable(rows) {
  const val = {
    time: (r) => r.created_at, type: (r) => (r.kind === 'authfail' ? '1' : '0') + r.type, server: (r) => r.host_name,
    who: (r) => accWho(r, accDet(r)), what: (r) => r.message,
  }[accSort.key];
  if (val) {
    rows = rows.map((r) => [r, val(r)]).sort((p, q) => accSort.dir * String(p[1]).localeCompare(String(q[1]), LANG, { numeric: true, sensitivity: 'base' })
      || (q[0].created_at > p[0].created_at ? 1 : -1)).map(([r]) => r);
  }
  const th = (k) => `<th class="sortable${accSort.key === k ? ' sorted' : ''}" data-sort="${k}" title="${L('Sorteren op {k}', { k: ACC_COLS[k].toLowerCase() })}">${ACC_COLS[k]}${accSort.key === k ? (accSort.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`;
  return `<div class="table-scroll"><table class="alert-table large acc-table"><thead><tr>${Object.keys(ACC_COLS).map(th).join('')}<th></th></tr></thead><tbody>
    ${rows.map((r) => {
      const det = accDet(r);
      const fail = r.kind === 'authfail';
      return `<tr class="${fail ? 's1' : 'auth'}">
        <td class="at-time" title="${esc(r.created_at)}">${esc(r.created_at.slice(11, 16))}</td>
        <td class="at-st">${fail ? '❌ ' : '🔑 '}${esc(ACC_TYPES[r.type] || r.type)}${fail && +det.n > 1 ? ` <span class="muted small">${+det.n}×</span>` : ''}</td>
        <td>${esc(r.host_name)}</td>
        <td>${esc(accWho(r, det))}</td>
        <td class="at-msg">${esc(r.message)}</td>
        <td class="acc-actions need-admin">
          <button type="button" class="linkbtn" data-acc="ignore" data-id="${r.id}" title="${L('Deze gebruiker of dit IP-adres voortaan niet meer tonen')}">🙈 ${L('Negeer')}</button>
          ${det.ip ? `<button type="button" class="linkbtn" data-acc="block" data-id="${r.id}" title="${L('IP-adres {ip} blokkeren', { ip: esc(det.ip) })}">⛔ ${L('Blokkeer')}</button>` : ''}
        </td></tr>`;
    }).join('')}</tbody></table></div>`;
}

/** 🙈 Negeer: kies de gebruiker(s) en/of het IP-adres die voortaan niet meer getoond worden */
function accIgnoreDialog(r) {
  const det = accDet(r);
  const users = [...new Set((det.users || [det.user]).filter((u) => u && u !== '?'))];
  const opts = [...users.map((u) => ['user', u, L('gebruiker {u}', { u: `<b>${esc(u)}</b>` })]), ...(det.ip ? [['ip', det.ip, L('IP-adres {ip}', { ip: `<code>${esc(det.ip)}</code>` })]] : [])];
  if (!opts.length) return toast(L('Geen gebruiker of IP-adres om te negeren'), 'err');
  modal({
    title: '🙈 ' + L('Negeren'),
    body: `<p style="margin-top:0">${L('Wat wil je voortaan niet meer zien in <i>Toegang</i>? Ook de regels die er al staan, verdwijnen.')}</p>
      ${opts.map(([k, v, l], i) => `<label class="switch small"><input type="checkbox" name="ign${i}" value="${esc(v)}" ${opts.length === 1 || k === 'ip' ? 'checked' : ''}><span></span> ${l}</label>`).join('')}
      <p class="muted small">${L('Een mislukte poging met meerdere gebruikers wordt alleen verborgen als <i>alle</i> gebruikers genegeerd worden, of het IP-adres. De lijst pas je aan onder <i>Instellingen → 🛡️ IDS → Negeren</i>. Brute-forcedetectie van het IDS blijft gewoon werken.')}</p>`,
    buttons: [{ label: '🙈 ' + L('Negeren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const add = Object.keys(d).filter((k) => k.startsWith('ign')).map((k) => d[k]);
      if (!add.length) throw new Error(L('Kies minstens één gebruiker of IP-adres'));
      await api('ids_ignore', { add: add.join(' ') });
      toast(L('Genegeerd: {list}', { list: add.join(', ') }));
      setTimeout(loadIds, 50);
    },
  });
}

function renderIds() {
  const d = idsData;
  if (!d) return;
  $('#ids-status').innerHTML = !d.enabled ? L('<b class="warn-txt">IDS staat uit.</b> Zet het aan onder <a href="#settings/ids" data-goto="settings/ids">Instellingen → 🛡️ IDS</a>.')
    : `${L('Modus:')} <b>${d.mode === 'block' ? L('⛔ blokkeren') : L('🎓 leren (alleen melden)')}</b> · <a href="#settings/ids" data-goto="settings/ids">${L('instellingen')}</a>`;
  $('#ids-hosts').innerHTML = d.hosts.length ? d.hosts.map((h) => h.ha ? `
    <div class="ids-host"><div><b>${esc(h.name)}</b></div>
      <div class="small muted">${L('niet beschikbaar in de Home Assistant-add-on: die draait in een container en ziet het systeem zelf niet')}</div></div>` : `
    <div class="ids-host">
      <div><b>${esc(h.name)}</b> <span class="muted small">${h.last_report ? L('IDS-rapport {ago}', { ago: ago(h.last_report) }) : d.enabled ? L('nog geen IDS-rapport (agent 1.35.0+)') : ''}</span></div>
      <div class="small">${!h.baseline_until ? '' : h.learning ? L('📏 basismeting tot {t}', { t: esc(fmtUntil(h.baseline_until)) }) : L('✔ bewaakt')}
        ${h.fw_state ? ' · ' + (IDS_FW[h.fw_state] || (() => esc(h.fw_state)))(h) : ''}${h.fim_count != null ? ' · 📁 ' + L(+h.fim_count === 1 ? '{n} bestand bewaakt' : '{n} bestanden bewaakt', { n: (+h.fim_count).toLocaleString(LOCALE) }) : ''}${+h.realtime ? ((x) => (x && x.state === 'active' ? ' · <span class="good" title="' + L('Realtime-watcher actief') + '">⚡ realtime</span>' : ' · <span class="warn-txt" title="' + L('Realtime staat aan, maar de watcher is niet actief') + '">⚡ ' + L('niet actief') + '</span>'))(JSON.parse(h.rt || 'null')) : ''}${+h.realtime ? ((t) => (t && !t.inotify ? ` · <span class="warn-txt" title="${L('Bestanden worden niet in realtime gevolgd; zie de Agent Manager')}">${t.family === 'rhel' && !t.epel ? L('EPEL ontbreekt') : L('inotify-tools ontbreekt')}</span>` : ''))(JSON.parse(h.tools || 'null')) : ''}</div>
      <div class="small">${h.open[2] ? `<span class="ad-count">🔴 ${h.open[2]}</span>` : ''} ${h.open[1] ? `<span class="ad-count">🟠 ${h.open[1]}</span>` : ''}
        ${!h.open[1] && !h.open[2] ? '<span class="muted">' + L('geen open incidenten') + '</span>' : ''}
        ${h.baseline_until ? `<button type="button" class="linkbtn need-admin" data-rebase="${h.id}" title="${L('Alles wat er nu is als normaal vastleggen')}">↺ ${L('basismeting opnieuw')}</button>` : ''}</div>
    </div>`).join('') : '<p class="muted">' + L('Nog geen servers met agent.') + '</p>';

  // Incidenten per dag
  const list = d.incidents.filter((i) => idsFilter === 'all' || i.status === 'open');
  const open = d.incidents.filter((i) => i.status === 'open');
  $('#ids-info').textContent = L('{open} open · {n} in de laatste {days} dagen', { open: open.length, n: d.incidents.length, days: d.days });
  $('#it-n-inc').textContent = open.length ? `(${open.length})` : '';
  const el = $('#ids-incidents');
  if (!list.length) {
    el.innerHTML = `<p class="muted">${idsFilter === 'open' ? L('Geen open incidenten.') + ' 👌' : L('Nog geen incidenten.')}</p>`;
  } else {
    const byDay = new Map();
    list.forEach((i) => { const day = i.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(i); });
    const isOpen = (day) => idsDayOpen[day] ?? (day === d.today || idsFilter === 'open');
    el.innerHTML = [...byDay].map(([day, items]) => {
      const n2 = items.filter((i) => +i.severity === 2).length, n1 = items.length - n2;
      return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''}>
        <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
          <span class="ad-counts">${n2 ? `<span class="ad-count">🔴 ${n2}</span>` : ''}${n1 ? `<span class="ad-count">🟠 ${n1}</span>` : ''}</span>
          <span class="muted small ad-total">${items.length === 1 ? L('1 incident') : L('{n} incidenten', { n: items.length })}</span></summary>
        <div class="ad-body">${items.map(idsRow).join('')}</div></details>`;
    }).join('');
    $$('.alert-day', el).forEach((det) => det.addEventListener('toggle', () => { idsDayOpen[det.dataset.day] = det.open; idsToggleLabel(); }));
  }
  idsToggleLabel();

  // Blokkades
  $('#ids-block-info').textContent = L('{n} actief', { n: d.blocks.length });
  $('#it-n-blk').textContent = d.blocks.length ? `(${d.blocks.length})` : '';
  const row = (b, active) => `<tr>
      <td class="mono">${esc(b.ip)}</td><td>${esc(b.host_name)}</td><td>${esc(b.reason)}</td>
      <td class="at-time" title="${esc(b.created_at)}">${esc(fmtUntil(b.created_at))}</td>
      <td class="at-time">${active ? (b.until ? esc(fmtUntil(b.until)) : '<b>' + L('permanent') + '</b>') : b.removed_at ? (b.removed_by ? L('gedeblokkeerd {t} door {who}', { t: esc(fmtUntil(b.removed_at)), who: esc(b.removed_by) }) : L('gedeblokkeerd {t}', { t: esc(fmtUntil(b.removed_at)) })) : L('verlopen')}</td>
      <td>${+b.auto ? L('automatisch') : esc(b.created_by)}</td>
      <td title="${L('Tegengehouden pakketten sinds de laatste wijziging van de lijst')}">${+b.hits || 0}</td>
      <td>${active ? `<button type="button" class="btn need-admin small-btn" data-unblock="${b.id}">🔓 ${L('Deblokkeren')}</button>` : ''}</td></tr>`;
  const head = `<thead><tr><th>IP</th><th>Server</th><th>${L('Reden')}</th><th>${L('Sinds')}</th><th>${L('Tot')}</th><th>${L('Door')}</th><th>${L('Pakketten')}</th><th></th></tr></thead>`;
  $('#ids-blocks').innerHTML = (d.blocks.length ? `<div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${d.blocks.map((b) => row(b, true)).join('')}</tbody></table></div>`
      : `<p class="muted">${L('Niets geblokkeerd.')}${d.mode !== 'block' ? ' ' + L('(Modus <i>leren</i>: er wordt niets geblokkeerd.)') : ''}</p>`)
    + (d.removed.length ? `<details class="small api-docs"><summary>${L('Eerder geblokkeerd ({n})', { n: d.removed.length })}</summary>
        <div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${d.removed.map((b) => row(b, false)).join('')}</tbody></table></div></details>` : '');
}

function idsRow(i) {
  const sev = +i.severity === 2 ? 2 : 1;
  const done = i.status !== 'open';
  const fim = i.type === 'fim' || i.type === 'fim_bulk';
  const okLabel = fim ? (i.type === 'fim_bulk' ? L('✔ Alles goedkeuren') : L('✔ Wijziging goedkeuren')) : L('✔ Afhandelen');
  const normal = fim ? [L('🚫 Niet meer bewaken'), i.type === 'fim_bulk' ? L('Goedkeuren en wijzigingen onder dit pad voortaan niet meer melden') : L('Goedkeuren en dit bestand voortaan niet meer bewaken')]
    : [L('Dit is normaal'), L('Afhandelen en dit voortaan niet meer melden')];
  return `<div class="alert-item ids-item s${sev} ${done ? 'ids-done' : ''}">
    <span class="dot"></span>
    <strong>${sev === 2 ? '🔴' : '🟠'} ${esc(i.title)}${+i.hits > 1 ? ` <span class="muted small">(${i.hits}×)</span>` : ''}</strong>
    <span class="muted small" title="${esc(i.created_at)}">${esc(i.created_at.slice(11, 16))} · ${esc(i.host_name)}</span>
    <span class="msg">${esc(i.detail)}${i.ip ? ` · IP <code>${esc(i.ip)}</code>` : ''}${done ? ` · ✔ ${L('afgehandeld door {who}', { who: esc(i.handled_by || '?') })}` : ''}</span>
    <span class="ids-actions need-admin">${done ? `<button type="button" class="linkbtn" data-ids="reopen" data-id="${i.id}">↺ ${L('heropenen')}</button>`
      : `<button type="button" class="btn small-btn" data-ids="handle" data-id="${i.id}">${okLabel}</button>
         <button type="button" class="btn small-btn" data-ids="normal" data-id="${i.id}" title="${normal[1]}">${normal[0]}</button>`}
      ${i.ip ? `<button type="button" class="btn small-btn" data-ids="block" data-ip="${esc(i.ip)}" data-host="${i.host_id}">⛔ ${L('Blokkeer IP')}</button>` : ''}</span>
  </div>`;
}

function idsToggleLabel() {
  const days = $$('#ids-incidents .alert-day');
  $('#ids-toggle').hidden = days.length < 2;
  $('#ids-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

function idsBlockDialog(ip = '', hostId = '', reason = '') {
  const hosts = ((idsData && idsData.hosts) || []).filter((h) => !h.ha);
  modal({
    title: L('⛔ IP blokkeren'),
    body: `<label>${L('IP-adres')}<input name="ip" class="mono" value="${esc(ip)}" required placeholder="${L('bv. {x}', { x: '203.0.113.9' })}"></label>
      <div class="row2">
        <label>${L('Op server')}<select name="host_id">${hosts.map((h) => `<option value="${h.id}" ${+h.id === +hostId ? 'selected' : ''}>${esc(h.name)}${h.fw_state === 'unsupported' ? ' ' + L('(alleen detecteren)') : ''}</option>`).join('')}</select></label>
        <label>${L('Hoe lang')}<select name="hours"><option value="24">${L('24 uur')}</option><option value="168">${L('7 dagen')}</option><option value="720">${L('30 dagen')}</option><option value="0">${L('Permanent')}</option></select></label>
      </div>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200" value="${esc(reason)}"></label>
      ${idsData && idsData.mode !== 'block' ? '<p class="warn-txt small">' + L('IDS staat op <i>leren</i>: de blokkade wordt vastgelegd, maar pas echt actief als je overschakelt naar <i>blokkeren</i>.') + '</p>' : ''}
      <p class="muted small">${L('Je eigen IP-adres ({ip}) kan niet geblokkeerd worden. Deblokkeren kan altijd hier; de agent past het binnen een minuut aan.', { ip: esc((idsData && idsData.my_ip) || '?') })}</p>`,
    buttons: [{ label: '⛔ ' + L('Blokkeren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('ids_block', { ip: d.ip, host_id: +d.host_id, hours: +d.hours, reason: d.reason });
      toast(L('{ip} geblokkeerd', { ip: d.ip }));
      setTimeout(loadIds, 50);
    },
  });
}

/** "Vandaag", "Gisteren" of "di 14 okt", met het jaar erbij als het niet dit jaar is */
function dayLabel(day, today) {
  const d = new Date(day + 'T12:00:00'), t = new Date(today + 'T12:00:00');
  const diff = Math.round((t - d) / 86400000);
  const opts = { weekday: 'long', day: 'numeric', month: 'long', ...(d.getFullYear() !== t.getFullYear() ? { year: 'numeric' } : {}) };
  const date = d.toLocaleDateString(LOCALE, opts);
  return diff === 0 ? `${L('Vandaag')} · ${date}` : diff === 1 ? `${L('Gisteren')} · ${date}` : date.charAt(0).toUpperCase() + date.slice(1);
}

const AUTH_TYPES = { ssh: 'SSH', console: 'Console', sudo: 'sudo', su: 'su' };
/** Extra gegevens van een login/sudo-regel (wie, wat, IP), of null */
function alertDetail(a) {
  if (a.kind !== 'auth' && a.kind !== 'authfail') return null;
  try { return JSON.parse(a.detail || '{}'); } catch (e) { return {}; }
}

/** Tellers in de kop van een dag: hoeveel critical, warning, onbekend, hersteld en bevestigd */
function alertDayCounts(list) {
  const n = { crit: 0, warn: 0, unknown: 0, ok: 0, info: 0, ack: 0, auth: 0, authfail: 0 };
  list.forEach((a) => {
    if (a.kind === 'auth' || a.kind === 'authfail' || a.kind === 'info') n[a.kind]++;
    else if (a.kind === 'ack') n.ack++;
    else if (+a.status === 2) n.crit++;
    else if (+a.status === 1) n.warn++;
    else if (+a.status === 3) n.unknown++;
    else n.ok++;
  });
  return [['crit', '🔴', 'critical'], ['warn', '🟠', 'warning'], ['unknown', '⚪', L('onbekend')], ['ok', '✅', L('hersteld/OK')], ['info', 'ℹ️', 'info'], ['ack', '✔', L('bevestigd')], ['auth', '🔑', L('logins en sudo')], ['authfail', '❌', L('mislukte logins/sudo')]]
    .filter(([k]) => n[k]).map(([k, i, t]) => `<span class="ad-count" title="${n[k]} ${t}">${i} ${n[k]}</span>`).join('');
}

/** Server en check van een melding; van een verwijderde check uit de titel ("🔴 CRITICAL: server – check") */
function alertCols(a) {
  const t = a.title.match(/:\s*(.+?) – (.+)$/);
  const det = alertDetail(a);
  const who = det ? (det.users ? det.users.join(', ') : det.user) || '?' : '';
  // Geweigerde verbinding, login of agent die verbindt: de titel (zonder het teken ervoor) is de "check"
  if (a.kind === 'sec' || (a.kind === 'info' && !a.check_name && !t)) return { host: a.host_name || 'RzMon', check: a.title.replace(/^\S+\s+/, '') };
  return {
    host: a.host_name || (t ? t[1] : 'RzMon'),
    check: det ? `${AUTH_TYPES[det.type] || det.type} · ${who}${det.as && det.type === 'su' ? ' → ' + det.as : ''}` : a.check_name || (t ? t[2] : '–'),
  };
}

/** Volgorde van de status: critical, warning, onbekend, OK/hersteld, bevestigd, logins */
function alertRank(a) {
  if (a.kind === 'authfail') return 5;
  if (a.kind === 'auth') return 6;
  if (a.kind === 'ack') return 4;
  if (a.kind === 'info') return 3.5;
  if (a.kind === 'sec') return 1;
  return [3, 1, 0, 2][+a.status] ?? 3;
}
/** Mislukte verzendingen tellen zwaar, daarna hoe weinig er verstuurd is */
function alertChannelScore(a) {
  if (a.kind === 'auth' || a.kind === 'authfail' || a.kind === 'ack') return 100;
  const sent = +a.sent_ok + (+a.email_ok || 0) + (+a.webhook_ok || 0);
  const failed = (a.email_ok !== null && a.email_ok !== undefined && !+a.email_ok ? 1 : 0) + (a.webhook_ok !== null && a.webhook_ok !== undefined && !+a.webhook_ok ? 1 : 0) + (sent ? 0 : 1);
  return -failed * 10 + sent;
}
function sortAlerts(list) {
  const { key, dir } = alertSort;
  if (!ALERT_SORTS[key]) return list;
  const val = {
    time: (a) => a.created_at, status: alertRank, server: (a) => alertCols(a).host, check: (a) => alertCols(a).check,
    message: (a) => a.message || '', channels: alertChannelScore,
  }[key];
  const cmp = (x, y) => (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), LANG, { numeric: true, sensitivity: 'base' }));
  return list.map((a) => [a, val(a)]).sort((p, q) => dir * cmp(p[1], q[1]) || (q[0].created_at > p[0].created_at ? 1 : -1)).map(([a]) => a);
}

/** ⛔-knop bij een melding met een IP (geweigerde verbinding, mislukte login); alleen voor beheerders */
function alertBlock(a) {
  if (!a.ip || !isAdmin() || !(a.kind === 'sec' || (a.detail || '').includes('"loginfail"'))) return '';
  return a.blocked ? ` <span class="muted small" title="${esc(a.ip)}">⛔ ${L('geblokkeerd')}</span>`
    : ` <button type="button" class="linkbtn" data-block-ip="${esc(a.ip)}" title="${esc(L('{ip} blokkeren', { ip: a.ip }))}">⛔ ${L('blokkeren')}</button>`;
}

function alertRows(list, layout, density) {
  const time = (a) => esc(a.created_at.slice(11, 16));
  list = sortAlerts(list);
  const cid = (a) => (a.check_id ? ` data-cid="${a.check_id}"` : '');
  if (layout === 'l1nes') {
    const th = (k) => `<th class="sortable${alertSort.key === k ? ' sorted' : ''}" data-sort="${k}" title="${L('Sorteren op {k}', { k: ALERT_SORTS[k].toLowerCase() })}">${ALERT_SORTS[k]}${alertSort.key === k ? (alertSort.dir > 0 ? ' ▲' : ' ▼') : ''}</th>`;
    return `<table class="alert-table ${density}"><thead><tr>${Object.keys(ALERT_SORTS).map(th).join('')}</tr></thead><tbody>
      ${list.map((a) => {
        const { host, check } = alertCols(a);
        return `<tr class="${a.kind === 'ack' ? 'ack' : a.kind === 'auth' ? 'auth' : a.kind === 'info' ? 'info' : 's' + a.status}${a.check_id ? ' clickable' : ''}"${cid(a)}>
          <td class="at-time" title="${esc(a.created_at)}">${time(a)}</td>
          <td class="at-st">${alertStatusText(a)}</td>
          <td>${esc(host)}</td>
          <td>${esc(check)}</td>
          <td class="at-msg" title="${density === 'small' ? L('Klik voor het hele bericht') : ''}">${esc(a.message)}</td>
          <td class="at-ch">${channelIcons(a)}${alertBlock(a)}</td></tr>`;
      }).join('')}</tbody></table>`;
  }
  return list.map((a) => `
    <div class="alert-item ${density === 'small' ? 'compact ' : ''}${a.kind === 'ack' ? 'ack' : a.kind === 'auth' ? 'auth' : a.kind === 'info' ? 'info' : 's' + a.status}${a.check_id ? ' clickable' : ''}"${cid(a)}>
      <span class="dot"></span>
      <strong>${esc(a.title)}</strong>
      <span class="muted small" title="${esc(a.created_at)}">${time(a)} ${channelIcons(a)}${alertBlock(a)}</span>
      <span class="msg">${esc(a.message)}</span>
    </div>`).join('');
}

function renderAlerts() {
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
    + ' ' + (d.days === 1 ? L('van de laatste dag') : L('van de laatste {n} dagen', { n: d.days }))
    + (d.truncated ? ' ' + L('(alleen de nieuwste 10.000)') : '');
  $$('#alerts-filter .seg-btn').forEach((b) => b.classList.toggle('active', b.dataset.af === alertFilter));
  const shown = d.alerts.filter((a) => alertFilter === 'all' || (alertFilter === 'info') === (a.kind === 'info'));
  if (!shown.length) { el.innerHTML = `<p class="muted">${d.alerts.length ? L('Geen meldingen met dit filter.') : L('Nog geen meldingen.') + ' 👌'}</p>`; $('#alerts-toggle').hidden = true; return; }
  const byDay = new Map();
  shown.forEach((a) => {
    const day = a.created_at.slice(0, 10);
    if (!byDay.has(day)) byDay.set(day, []);
    byDay.get(day).push(a);
  });
  const isOpen = (day) => alertDayOpen[day] ?? day === d.today;
  el.innerHTML = [...byDay].map(([day, list]) => `
    <details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${alertDayCounts(list)}</span>
        <span class="muted small ad-total">${list.length === 1 ? L('1 melding') : L('{n} meldingen', { n: list.length })}</span></summary>
      <div class="ad-body">${isOpen(day) ? alertRows(list, layout, density) : ''}</div>
    </details>`).join('');
  // Pas bij openen opbouwen: snel, ook met duizenden meldingen
  $$('.alert-day', el).forEach((det) => det.addEventListener('toggle', () => {
    alertDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = alertRows(byDay.get(det.dataset.day), layout, density);
    alertToggleLabel();
  }));
  $('#alerts-toggle').hidden = byDay.size < 2;
  alertToggleLabel();
}

function saveAlertSort() {
  try { localStorage.setItem('rzmon-alert-sort', JSON.stringify(alertSort)); } catch (e) { /* alleen voor deze sessie */ }
  renderAlerts();
}

function alertToggleLabel() {
  const days = $$('#alerts .alert-day');
  $('#alerts-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

// ---------------------------------------------------------------- Settings
// ---------------------------------------------------------------- Access
let accessLock = null;   // inlogbeveiliging, voor de samenvatting in L1nes
async function loadAccess() {
  const a = await api('access_info');
  const f = $('#access-form');
  f.lan_no_login.checked = a.lan_no_login;
  f.lan_as_admin.checked = a.lan_as_admin;
  f.trusted_networks.value = a.trusted_networks;
  f.trusted_proxies.value = a.trusted_proxies;
  if (a.lock) {
    f.login_lock_fails.value = a.lock.fails; f.login_lock_window.value = a.lock.window; f.login_lock_minutes.value = a.lock.minutes;
    f.login_lock_account.checked = a.lock.account; f.login_lock_ip.checked = a.lock.ip;
    accessLock = a.lock;
  }
  $('#ip-status').innerHTML = `${L('Jouw IP-adres: {ip}', { ip: `<code>${esc(a.client_ip)}</code>` })}${a.client_ip !== a.remote_addr ? ` <span class="muted small">${L('(via proxy {ip})', { ip: esc(a.remote_addr) })}</span>` : ''}
    — ${a.client_trusted ? '<b class="yes">' + L('valt binnen je lokale netwerken') + '</b>' : '<b class="no">' + L('valt buiten je lokale netwerken') + '</b>'}`;
  settingsSummaries();
}

async function saveAccess(e, el) {
  if (e) e.preventDefault();
  const f = $('#access-form');
  el = el || document.activeElement;
  const err = $('.form-error', f);
  err.hidden = true;
  try {
    await api('access_save', { lan_no_login: f.lan_no_login.checked, lan_as_admin: f.lan_as_admin.checked, trusted_networks: f.trusted_networks.value, trusted_proxies: f.trusted_proxies.value,
      login_lock_fails: f.login_lock_fails.value, login_lock_window: f.login_lock_window.value, login_lock_minutes: f.login_lock_minutes.value,
      login_lock_account: f.login_lock_account.checked, login_lock_ip: f.login_lock_ip.checked });
    if (el && el.form === f) savedFlag(el, true);
    loadAccess();
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
    if (el && el.form === f) savedFlag(el, false, ex.message);
  }
}

// ---------------------------------------------------------------- API keys
function apiBase() {
  return location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/v1.php';
}

const API_RIGHTS = {
  read: [L('📖 Status lezen'), L('servers, checks, meetwaarden, meldingen')],
  ids_read: [L('🛡️ IDS lezen'), L('incidenten, blokkades, logins')],
  vulns_read: [L('🐞 Kwetsbaarheden lezen'), L('CVE\'s per server')],
  ids_block: [L('⛔ IDS blokkeren'), L('IP\'s blokkeren en deblokkeren')],
  checks: [L('✔ Checks bedienen'), L('bevestigen, onderhoud, nu checken')],
};

/** Rechten, alleen vanaf en geldigheid; voor een nieuwe sleutel en voor ✎ (k = bestaande sleutel) */
function apiKeyFields(k) {
  const has = k ? String(k.rights || 'read').split(',') : ['read'];
  return `<div class="api-rights">${Object.entries(API_RIGHTS).map(([r, [label, hint]]) => `
      <label><input type="checkbox" name="rights" value="${r}" ${has.includes(r) ? 'checked' : ''}><span>${label}<small>${hint}</small></span></label>`).join('')}</div>
    <div class="row2">
      <label>${L('Alleen vanaf')} <span class="hint">${L('(optioneel; IP\'s of netwerken, bv. 192.168.1.10 10.0.0.0/24)')}</span>
        <input name="allow_from" maxlength="500" spellcheck="false" value="${esc(k?.allow_from || '')}" placeholder="${L('overal vandaan')}"></label>
      <label>${k ? L('Verlengen') : L('Geldig')}
        <select name="months">${k ? '<option value="0">' + L('niet verlengen') + '</option>' : ''}
          ${[6, 12, 24].map((m) => `<option value="${m}" ${!k && m === 12 ? 'selected' : ''}>${k ? L('met {m} maanden vanaf nu', { m }) : L('{m} maanden', { m })}</option>`).join('')}</select></label>
    </div>`;
}

/** Wat het formulier stuurt: rights als lijst */
function apiKeyData(form) {
  const fd = new FormData(form);
  return { ...Object.fromEntries(fd), rights: fd.getAll('rights') };
}

const fmtDay = (dt) => new Date(dt.replace(' ', 'T')).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });

function apiKeyExpiry(k) {
  if (!k.expires_at) return '<span class="muted">' + L('geen vervaldatum') + '</span>';
  const days = Math.floor((new Date(k.expires_at.replace(' ', 'T')) - Date.now()) / 86400000);
  if (days < 0) return `<span class="crit-txt">${L('verlopen op {date}', { date: esc(fmtDay(k.expires_at)) })}</span>`;
  return `<span class="${days <= 30 ? 'warn-txt' : 'muted'}">${L('geldig tot {date}', { date: esc(fmtDay(k.expires_at)) })}${days <= 30 ? ' ' + (days === 1 ? L('(nog 1 dag)') : L('(nog {n} dagen)', { n: days })) : ''}</span>`;
}

let apiKeys = [];
async function loadApiKeys() {
  $('#api-base').textContent = apiBase();
  if (!$('#api-key-fields').innerHTML) $('#api-key-fields').innerHTML = apiKeyFields();
  apiKeys = await api('api_keys');
  $('#api-keys').innerHTML = apiKeys.length ? apiKeys.map((k) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(k.name)}</strong> <code class="muted">${esc(k.key_hint)}</code><br>
        ${String(k.rights || 'read').split(',').map((r) => `<span class="api-right ${r}">${esc(API_RIGHTS[r]?.[0] || r)}</span>`).join('')}<br>
        <span class="muted small">${k.allow_from ? L('Alleen vanaf {ip}', { ip: esc(k.allow_from) }) + ' · ' : ''}${apiKeyExpiry(k)} · ${L('laatst gebruikt: {t}', { t: k.last_used ? ago(k.last_used) : L('nooit') })}</span></span>
      <button class="icon-btn" title="${L('Rechten, adressen en geldigheid')}" data-edit="${k.id}">✎</button>
      <button class="icon-btn" title="${L('Intrekken')}" data-del="${k.id}" data-name="${esc(k.name)}">🗑</button>
    </div>`).join('') : '<p class="muted small">' + L('Nog geen sleutels.') + '</p>';
  settingsSummaries();
}

/** ⛔ blokkeren via de API vraagt om bevestiging; daarna (of meteen) opslaan */
function apiKeyConfirm(title, data, save) {
  modal({
    title,
    body: `<p style="margin-top:0">${L('Met het recht <b>⛔ IDS blokkeren</b> kan wie deze sleutel heeft IP-adressen blokkeren op je servers (als het IDS op <i>blokkeren</i> staat; in de leerstand wordt het alleen vastgelegd). Je LAN, RzMon zelf en de lijst <i>Nooit blokkeren</i> weigert de API altijd. Overweeg <i>Alleen vanaf</i> in te vullen.')}</p>
      ${confirmField('BLOKKEREN')}`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => save({ ...data, confirm: d.confirm }),
  });
}

async function createApiKey(e) {
  e.preventDefault();
  const form = e.target;
  const data = apiKeyData(form);
  if (!data.rights.length) return toast(L('Kies minstens één recht'), 'err');
  const save = async (d) => {
    const r = await api('api_key_create', d);
    form.reset();
    $('#api-key-fields').innerHTML = apiKeyFields();
    loadApiKeys();
    setTimeout(() => newApiKeyDialog(r.key, d.rights), 0);
  };
  if (data.rights.includes('ids_block')) return apiKeyConfirm(L('⛔ Sleutel met blokkeerrecht'), data, save);
  try { await save(data); } catch (ex) { toast(ex.message, 'err'); }
}

function apiKeyEdit(k) {
  const before = String(k.rights || '').split(',');
  modal({
    title: `✎ ${L('API-sleutel {name}', { name: k.name })}`,
    body: `<div class="api-key-form">${apiKeyFields(k)}<p class="muted small" style="margin:0">${apiKeyExpiry(k)}</p></div>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d, form) => {
      const data = { ...apiKeyData(form), id: k.id };
      if (!data.rights.length) throw new Error(L('Kies minstens één recht'));
      const save = async (x) => { await api('api_key_update', x); toast(L('Sleutel aangepast')); loadApiKeys(); };
      if (data.rights.includes('ids_block') && !before.includes('ids_block')) {
        setTimeout(() => apiKeyConfirm('⛔ ' + L('Blokkeerrecht toevoegen'), data, save), 0);
        return;
      }
      await save(data);
    },
  });
}

function newApiKeyDialog(key, rights) {
  const auth = `-H "Authorization: Bearer ${esc(key)}"`;
  const ex = [];
  if (rights.includes('read')) ex.push(`curl ${auth} ${esc(apiBase())}/status`);
  if (rights.includes('ids_read')) ex.push(`curl ${auth} ${esc(apiBase())}/ids/incidents?status=open`);
  if (rights.includes('vulns_read')) ex.push(`curl ${auth} ${esc(apiBase())}/vulnerabilities`);
  if (rights.includes('ids_block')) ex.push(`curl ${auth} -H "Content-Type: application/json" \\
  -d '{"host":"web01","ip":"203.0.113.7","hours":24,"reason":"fail2ban"}' ${esc(apiBase())}/ids/block`);
  if (rights.includes('checks')) ex.push(`curl ${auth} -X POST -d '{"comment":"bezig"}' ${esc(apiBase())}/checks/1/ack`);
  modal({
    title: L('Nieuwe API-sleutel'),
    body: `<p>${L('Kopieer deze sleutel nu — hij wordt <b>niet</b> opnieuw getoond.')}</p>
      <div class="new-key"><input id="new-key" readonly value="${esc(key)}"><button type="button" class="btn" id="copy-key">${L('Kopieer')}</button></div>
      <p class="muted small" style="margin-top:1rem">Test:</p>
      <pre class="code">${ex.join('\n\n')}</pre>`,
    buttons: [{ label: L('Klaar'), cls: 'primary', submit: true }],
  });
  $('#copy-key').onclick = async () => {
    $('#new-key').select();
    try { await navigator.clipboard.writeText(key); } catch (e) { document.execCommand('copy'); }
    toast(L('Gekopieerd'));
  };
}

async function apiKeyClick(e) {
  const ed = e.target.closest('[data-edit]');
  if (ed) return apiKeyEdit(apiKeys.find((k) => String(k.id) === ed.dataset.edit));
  const b = e.target.closest('[data-del]');
  if (!b || !confirm(L('API-sleutel "{name}" intrekken? Toepassingen die hem gebruiken verliezen direct toegang.', { name: b.dataset.name }))) return;
  try { await api('api_key_delete', { id: b.dataset.del }); toast(L('Sleutel ingetrokken')); loadApiKeys(); }
  catch (ex) { toast(ex.message, 'err'); }
}

function isAdmin() { return document.body.dataset.role === 'admin'; }

// ---------------------------------------------------------------- Accountmenu (rechtsboven)
function initAccountMenu() {
  const btn = $('#acct-btn'), menu = $('#acct-menu');
  if (!btn) return;
  const open = (on) => { menu.hidden = !on; btn.setAttribute('aria-expanded', on); };
  btn.onclick = (e) => { e.stopPropagation(); open(menu.hidden); };
  document.addEventListener('click', (e) => { if (!menu.hidden && !e.target.closest('#acct')) open(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); } });
  menu.addEventListener('click', async (e) => {
    const go = e.target.closest('[data-acct]');
    if (go) { open(false); return showTab(go.dataset.acct === 'settings' ? 'settings/overview' : go.dataset.acct); }
    const th = e.target.closest('[data-theme-set]');
    if (th) {
      const v = th.dataset.themeSet;
      if (v === 'auto') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = v;
      try { v === 'auto' ? localStorage.removeItem('rzmon-theme') : localStorage.setItem('rzmon-theme', v); } catch (ex) {}
      return markTheme();
    }
    const lg = e.target.closest('[data-lang-set]');
    if (lg && !lg.classList.contains('active')) {
      try { await api('lang_set', { lang: lg.dataset.langSet }); location.reload(); } catch (ex) { toast(ex.message, 'err'); }
    }
  });
  markTheme();
}
function markTheme() {
  const cur = document.documentElement.dataset.theme || 'auto';
  $$('[data-theme-set]').forEach((b) => b.classList.toggle('active', b.dataset.themeSet === cur));
}

// ---------------------------------------------------------------- Tabbladen bovenaan: een vlak dat naar het actieve tabblad schuift
function moveTabIndicator(anim = true) {
  const ind = $('#main-tabs .tab-ind');
  if (!ind) return;
  const t = $('#main-tabs .tab.active');
  ind.classList.toggle('no-anim', !anim);
  // Instellingen of Mijn account: geen tabblad actief, dan ook geen vlak
  ind.style.opacity = t ? '1' : '0';
  if (t) { ind.style.width = t.offsetWidth + 'px'; ind.style.transform = `translateX(${t.offsetLeft}px)`; }
}

function initMainTabs() {
  const nav = $('#main-tabs');
  if (!nav) return;
  moveTabIndicator(false);
  let rt;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => moveTabIndicator(false), 100); });
  if (document.fonts) document.fonts.ready.then(() => moveTabIndicator(false));
  // Teller omhoog: het bolletje pulseert even
  $$('.tab-badge', nav).forEach((b) => {
    let last = parseInt(b.textContent, 10) || 0;
    new MutationObserver(() => {
      const n = b.hidden ? 0 : parseInt(b.textContent, 10) || 0;
      if (n > last) { b.classList.remove('pulse'); void b.offsetWidth; b.classList.add('pulse'); }
      last = n;
      moveTabIndicator();   // met een teller wordt het tabblad breder
    }).observe(b, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
  });
}

// ---------------------------------------------------------------- Instellingen: zijbalk met onderdelen
// Elk onderdeel toont een of meer kaarten (data-sk); de kaarten zelf (en hoe ze opslaan) zijn niet veranderd.
const SET_NAV = [
  ['overzicht', '✅', L('Overzicht'), [['overview', L('Hoe staat het ervoor?'), ['overview']]]],
  ['meldingen', '🔔', L('Meldingen'), [['channels', L('Kanalen'), ['pushover', 'email', 'webhook', 'mqtt']], ['rules', L('Meldregels en stille uren'), ['notify']], ['sysinfo', L('Systeeminfo'), ['sysinfo']]]],
  ['monitoring', '📈', L('Monitoring'), [['alarm', L('Alarmgedrag'), ['alarm']], ['vulns', L('Kwetsbaarheden'), ['vulns']], ['ids', 'IDS', ['ids']]]],
  ['beveiliging', '🔐', L('Beveiliging'), [['access', L('Toegang en inlogbeveiliging'), ['access']], ['users', L('Gebruikers'), ['users']], ['api', L('API-sleutels'), ['api']], ['blocks', L('Blokkades'), ['blocks']]]],
  ['agents', '🖥️', L('Agents'), [['agents', L('Agents bijwerken en commando\'s'), ['agents']], ['enc', L('Versleuteling'), ['enc']]]],
  ['systeem', '🛠️', L('Systeem'), [['update', L('RzMon bijwerken'), ['update']], ['backup', L('Back-up'), ['backup']], ['db', L('Database'), ['db']], ['setup', 'Setup', ['setup']]]],
];
let setSection = (() => { try { return localStorage.getItem('rzmon-set-section') || 'overview'; } catch (e) { return 'overview'; } })();
let setHealthAt = 0;
let setHealth = {};   // onderdeel → slechtste status uit "Hoe staat het ervoor?" (voor de bolletjes in de zijbalk)

function setFind(id) {
  for (const [g, icon, label, subs] of SET_NAV) for (const s of subs) if (s[0] === id) return { g, icon, group: label, sub: s };
  return null;
}

function showSettingsSection(id, focusEl) {
  const f = setFind(id) || setFind('overview');
  setSection = f.sub[0];
  try { localStorage.setItem('rzmon-set-section', setSection); } catch (e) {}
  const cards = f.sub[2];
  $$('#tab-settings [data-sk]').forEach((c) => c.classList.toggle('set-off', !cards.includes(c.dataset.sk)));
  // De knop "Instellingen opslaan" alleen bij onderdelen uit dat formulier
  const inForm = cards.some((k) => { const c = $(`#tab-settings [data-sk="${k}"]`); return c && c.closest('#settings-form'); });
  $('#settings-form').classList.toggle('set-noform', !inForm);
  $('#set-title').textContent = f.sub[1];
  $('#set-crumb').textContent = `${L('Instellingen')} › ${f.icon} ${f.group} › ${f.sub[1]}`;
  renderSettingsNav();
  $('#set-mselect').innerHTML = SET_NAV.map(([g, icon, label, subs]) => `<optgroup label="${icon} ${esc(label)}">${subs.map(([k, l]) => `<option value="${k}" ${k === setSection ? 'selected' : ''}>${esc(l)}</option>`).join('')}</optgroup>`).join('');
  history.replaceState(null, '', '#settings/' + setSection);
  if (setSection === 'overview' || !setHealthAt) loadSettingsHealth();
  if (focusEl) {
    // Gevonden via zoeken: ingeklapte "Geavanceerd" openen, ernaartoe en even laten oplichten
    for (let d = focusEl.closest('details'); d; d = d.parentElement.closest('details')) d.open = true;
    focusEl.scrollIntoView({ block: 'center' });
    focusEl.classList.remove('set-hit');
    void focusEl.offsetWidth;
    focusEl.classList.add('set-hit');
    const ctl = focusEl.matches('input, select, textarea') ? focusEl : focusEl.querySelector('input:not([type=hidden]), select, textarea');
    if (ctl) ctl.focus({ preventScroll: true });
  } else window.scrollTo(0, 0);
  refreshClamps();
}

const dotFor = (st) => (st === 'bad' || st === 'warn' ? `<i class="set-dot ${st}" title="${esc(st === 'bad' ? L('Hier moet iets gebeuren') : L('Aandachtspunt'))}"></i>` : '');

function renderSettingsNav() {
  const q = setSearchNorm($('#set-search').value);
  const side = $('#set-side');
  side.classList.toggle('searching', !!q);
  if (q) {
    const hits = setSearch(q);
    $('#set-navlist').innerHTML = hits.length ? `<div class="set-results">${hits.map((h, i) => `<button type="button" data-set-go="${h.sub[0]}" data-hit="${i}" class="${i === 0 ? 'first' : ''}">
        <b>${h.icon} ${esc(h.sub[1])}</b>${h.label ? `<small>${esc(h.label)}</small>` : ''}</button>`).join('')}</div>`
      : `<p class="muted small set-none">${L('Niets gevonden')}</p>`;
    side._hits = hits;
    return;
  }
  const f = setFind(setSection);
  $('#set-navlist').innerHTML = SET_NAV.map(([g, icon, label, subs]) => {
    const worst = subs.map(([k]) => setHealth[k]).find((s) => s === 'bad') || subs.map(([k]) => setHealth[k]).find((s) => s === 'warn');
    return `<div class="set-nav-group ${g === f.g ? 'open' : ''}">
      <button type="button" data-set-go="${subs[0][0]}" class="set-nav-head ${g === f.g ? 'cur' : ''}"><span>${icon}</span> ${esc(label)}${subs.length === 1 || g !== f.g ? dotFor(worst) : ''}</button>
      <div class="set-nav-subs">${subs.length > 1 ? subs.map(([k, l]) => `<button type="button" data-set-go="${k}" class="${k === setSection ? 'active' : ''}">${esc(l)}${dotFor(setHealth[k])}</button>`).join('') : ''}</div></div>`;
  }).join('');
}

// ---- Zoeken: op de namen van de onderdelen en op alles wat in de kaarten staat (koppen, velden, schakelaars)
const setSearchNorm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
function setSearch(q) {
  const words = q.split(' ');
  const has = (t) => words.every((w) => t.includes(w));
  const out = [];
  for (const [, icon, group, subs] of SET_NAV) {
    for (const sub of subs) {
      if (has(setSearchNorm(`${group} ${sub[1]}`))) { out.push({ icon, sub, label: '', el: null }); continue; }
      let found = null;
      for (const k of sub[2]) {
        const card = $(`#tab-settings [data-sk="${k}"]`);
        if (!card || card.closest('[hidden]:not(#tab-settings)')) continue;
        for (const el of $$('h2, h3, label, summary, th, .lock-row, .btn', card)) {
          const own = setSearchNorm(el.textContent + ' ' + (el.querySelector('[data-tip]')?.dataset.tip || '') + ' ' + (el.querySelector('input, textarea')?.name || ''));
          if (own && has(own)) { found = el; break; }
        }
        if (found) break;
      }
      if (found) out.push({ icon, sub, label: found.textContent.replace(/ⓘ/g, '').replace(/\s+/g, ' ').trim().slice(0, 70), el: found });
    }
  }
  return out;
}

// ---- Hoe staat het ervoor?
async function loadSettingsHealth() {
  const box = $('#set-overview');
  if (!box.innerHTML) box.innerHTML = `<p class="muted">${L('Laden…')}</p>`;
  let items;
  try { items = (await api('settings_health')).items; } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const https = location.protocol === 'https:';
  items.push({ key: 'https', status: https ? 'ok' : 'warn', title: 'HTTPS', detail: https ? L('de verbinding met RzMon is versleuteld') : L('je opent RzMon via http: wachtwoorden gaan onversleuteld over het netwerk'), section: 'access' });
  const rank = { bad: 0, warn: 1, info: 2, ok: 3 };
  items.sort((a, b) => rank[a.status] - rank[b.status]);
  setHealth = {};
  setHealthAt = Date.now();
  for (const it of items) if (rank[it.status] < rank[setHealth[it.section] || 'ok']) setHealth[it.section] = it.status;
  const counted = items.filter((i) => i.status !== 'info');
  const score = counted.length ? Math.round(counted.reduce((n, i) => n + (i.status === 'ok' ? 1 : i.status === 'warn' ? 0.5 : 0), 0) / counted.length * 100) : 100;
  const todo = items.filter((i) => i.status === 'bad' || i.status === 'warn').length;
  const cls = score >= 90 ? 'good' : score >= 60 ? 'warn' : 'bad';
  const icon = { ok: '✔', warn: '!', bad: '✘', info: 'i' };
  box.innerHTML = `<div class="ov-head"><div class="ov-score ${cls}" style="--p:${score}"><span><b>${score}<small>%</small></b></span></div>
      <div><h2>${todo ? L('{n} aandachtspunten', { n: todo }) : L('Alles staat goed ingesteld')}</h2>
      <p class="muted small">${L('Klik op een punt om het meteen te regelen. Grijze punten zijn optioneel en tellen niet mee.')}</p></div></div>
    <div class="ov-list">${items.map((i) => `<button type="button" class="ov-item ${i.status}" data-set-go="${esc(i.section)}">
      <span class="ov-ic">${icon[i.status]}</span><span class="ov-txt"><b>${esc(i.title)}</b><small>${esc(i.detail)}</small></span><span class="ov-go">›</span></button>`).join('')}</div>`;
  renderSettingsNav();
}

// ---- Lange uitleg korter: alinea's inklappen tot twee regels, lange toelichting bij een veld als ⓘ
function initSettingsTips() {
  $$('#tab-settings [data-sk] p.muted.small:not([id])').forEach((p) => {
    if (p.textContent.trim().length < 150 || p.closest('#set-overview')) return;
    p.classList.add('clamp');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'more-btn';
    b.textContent = 'ⓘ ' + L('meer uitleg');
    b.onclick = () => { const open = p.classList.toggle('open'); b.textContent = open ? '▴ ' + L('minder') : 'ⓘ ' + L('meer uitleg'); };
    p.after(b);
  });
  $$('#tab-settings [data-sk] label > span.hint, #tab-settings [data-sk] label > span.muted.small').forEach((s) => {
    const txt = s.textContent.trim().replace(/^\((.*)\)$/, '$1');
    if (txt.length < 40 || s.querySelector('a')) return;
    const t = document.createElement('span');
    t.className = 'tip';
    t.tabIndex = 0;
    t.dataset.tip = txt;
    t.setAttribute('aria-label', txt);
    t.textContent = 'ⓘ';
    // Het label is een kolom: de tekst ervoor en de ⓘ samen op één regel
    const line = document.createElement('span');
    line.className = 'lbl-line';
    const label = s.parentElement;
    while (label.firstChild && label.firstChild !== s) line.append(label.firstChild);
    line.append(t);
    s.replaceWith(line);
  });
}

// Alleen "meer uitleg" tonen als de alinea echt is ingekort (dat hangt af van de breedte)
function refreshClamps() {
  $$('#tab-settings p.clamp').forEach((p) => {
    const b = p.nextElementSibling;
    if (!b || !b.classList.contains('more-btn') || p.classList.contains('open') || !p.offsetParent) return;
    b.hidden = p.scrollHeight <= p.clientHeight + 2;
  });
}

function initSettingsNav() {
  $('#set-side').addEventListener('click', (e) => {
    const b = e.target.closest('[data-set-go]');
    if (!b) return;
    const hit = b.dataset.hit != null ? $('#set-side')._hits[+b.dataset.hit] : null;
    if (hit) $('#set-search').value = '';
    showSettingsSection(b.dataset.setGo, hit?.el);
  });
  $('#set-overview').addEventListener('click', (e) => {
    const b = e.target.closest('[data-set-go]');
    if (b) showSettingsSection(b.dataset.setGo);
  });
  const search = $('#set-search');
  search.addEventListener('input', renderSettingsNav);
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const hit = ($('#set-side')._hits || [])[0];
      if (!hit || !search.value.trim()) return;
      search.value = '';
      showSettingsSection(hit.sub[0], hit.el);
    } else if (e.key === 'Escape') { search.value = ''; renderSettingsNav(); }
  });
  $('#set-mselect').onchange = (e) => showSettingsSection(e.target.value);
  initSettingsTips();
  let rt;
  window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(refreshClamps, 200); });
}

// ---------------------------------------------------------------- Mijn account (alleen als je met een account bent ingelogd)
async function loadAccount() {
  if (!$('#tab-account')) return;
  let me, devices = [], logins = [];
  try {
    me = (await api('users')).find((u) => u.me);
    [devices, logins] = await Promise.all([api('devices', null, { user_id: me.id }), api('my_logins')]);
  } catch (ex) { $('#acct-data-body').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  if (!me) return;
  const row = (label, ctl, hint) => `<div class="acct-field"><span class="acct-lbl">${label}${hint ? `<small>${hint}</small>` : ''}</span><span class="acct-ctl">${ctl}</span></div>`;
  $('#acct-data-body').innerHTML = row(L('Gebruikersnaam'), `<b>${esc(me.username)}</b> <span class="role-tag r-${esc(me.role)}">${ROLE_LABEL[me.role] || esc(me.role)}</span>`, L('wijzigen kan alleen een beheerder'))
    + row(L('E-mailadres'), `<input type="email" id="acct-email" value="${esc(me.email || '')}" maxlength="190" placeholder="${esc(L('jij@voorbeeld.nl'))}" autocomplete="email">`, L('voor "wachtwoord vergeten"'))
    + row(L('Taal'), `<span class="seg"><button type="button" data-lang-set="nl" class="${LANG === 'nl' ? 'active' : ''}">Nederlands</button><button type="button" data-lang-set="en" class="${LANG === 'en' ? 'active' : ''}">English</button></span>`)
    + row(L('Laatst ingelogd'), me.last_login ? esc(fmtDateTime(me.last_login)) : L('nooit'));
  $('#acct-email').onchange = async (e) => {
    try { await api('user_email', { id: me.id, email: e.target.value }); savedFlag(e.target, true); } catch (ex) { savedFlag(e.target, false, ex.message); toast(ex.message, 'err'); }
  };
  $$('#acct-data-body [data-lang-set]').forEach((b) => (b.onclick = async () => {
    if (b.classList.contains('active')) return;
    try { await api('lang_set', { lang: b.dataset.langSet }); location.reload(); } catch (ex) { toast(ex.message, 'err'); }
  }));
  $('#acct-2fa-body').innerHTML = me.totp
    ? row(L('Status'), `<b class="good-txt">✔ ${L('aan')}</b> <button type="button" class="btn" id="acct-2fa-off">${L('2FA uitzetten')}</button>`, L('bij het inloggen ook een code uit je authenticator-app'))
    : row(L('Status'), `<b class="warn-txt">✘ ${L('uit')}</b> <button type="button" class="btn primary" id="acct-2fa-on">🔐 ${L('2FA instellen')}</button>`, L('aanbevolen: naast je wachtwoord een code uit een app op je telefoon'));
  if ($('#acct-2fa-on')) $('#acct-2fa-on').onclick = () => totpSetup(me.id, me.username);
  if ($('#acct-2fa-off')) $('#acct-2fa-off').onclick = async () => {
    if (!confirm(L('Tweestapsverificatie uitzetten? Inloggen kan daarna weer met alleen je wachtwoord.'))) return;
    try { await api('totp_reset', { id: me.id }); toast(L('2FA staat uit')); loadAccount(); } catch (ex) { toast(ex.message, 'err'); }
  };
  const icon = (d) => (/iPhone|Android/.test(d) ? '📱' : /iPad/.test(d) ? '📲' : '💻');
  $('#acct-devices-body').innerHTML = devices.length ? `<p class="muted small" style="margin-top:0">${L('Hier blijf je ingelogd zonder wachtwoord en 2FA. Na 30 dagen niet gebruiken, of als je je wachtwoord wijzigt, wordt een apparaat vergeten.')}</p>
    <div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Apparaat')}</th><th>${L('Laatst gebruikt')}</th><th>IP</th><th></th></tr></thead><tbody>
    ${devices.map((d) => `<tr><td>${icon(d.device)} ${esc(d.device || L('Onbekend apparaat'))}${d.current ? ` <span class="badge">${L('dit apparaat')}</span>` : ''}</td><td class="small">${ago(d.last_used)}</td><td class="mono small">${esc(d.ip)}</td>
      <td><button type="button" class="btn small-btn" data-forget="${d.id}">${L('Vergeten')}</button></td></tr>`).join('')}</tbody></table></div>
    <button type="button" class="btn danger" id="acct-forget-all" style="margin-top:.6rem">${L('Alle apparaten vergeten')}</button>`
    : `<p class="muted" style="margin:0">${L('Geen onthouden apparaten. Zet bij het inloggen <b>Onthoud mij op dit apparaat</b> aan.')}</p>`;
  $('#acct-devices-body').onclick = async (e) => {
    const b = e.target.closest('[data-forget]');
    try {
      if (b) { await api('device_forget', { id: b.dataset.forget }); toast(L('Apparaat vergeten')); return loadAccount(); }
      if (e.target.closest('#acct-forget-all') && confirm(L('Alle onthouden apparaten vergeten? Daar moet je dan opnieuw inloggen.'))) {
        await api('device_forget_all', { user_id: me.id }); toast(L('Alle apparaten vergeten')); loadAccount();
      }
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#acct-logins-body').innerHTML = logins.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Wanneer')}</th><th>IP</th><th>${L('Hoe')}</th><th>${L('Apparaat')}</th></tr></thead><tbody>
    ${logins.map((x) => `<tr class="${+x.ok ? '' : 'bad-row'}"><td class="small">${esc(fmtDateTime(x.created_at))}</td><td class="mono small">${esc(x.ip)}</td>
      <td class="small">${+x.ok ? esc(L(x.how)) : `<span class="bad">✘ ${esc(L(x.how))}</span>`}</td><td class="small">${esc(x.device || '–')}</td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted" style="margin:0">${L('Nog geen logins bewaard.')}</p>`;
}

async function loadSettings() {
  settingsLoaded = true;
  loadUsers();
  if (!isAdmin()) return;   // de rest van de instellingen is alleen voor beheerders
  loadApiKeys();
  loadSecurity();
  loadAccess();
  loadDbInfo();
  const s = await api('settings');
  const f = $('#settings-form');
  $('#mqtt-status').innerHTML = s.mqtt_enabled !== '1' ? ''
    : s.mqtt_last_error ? `<b class="bad">✘ ${esc(s.mqtt_last_error)}</b>`
    : s.mqtt_last_ok ? `<b class="good">✔ ${L('Laatst gepubliceerd {ago}', { ago: ago(s.mqtt_last_ok) })}</b>` : `<span class="muted">${L('Nog niet gepubliceerd')}</span>`;
  Object.entries(s).forEach(([k, v]) => {
    const el = f.elements[k];
    if (!el) return;
    if (el.type === 'checkbox') el.checked = v === '1';
    else el.value = v ?? '';
  });
  const parse = (v) => { try { return JSON.parse(v || '{}') || {}; } catch (e) { return {}; } };
  notifyGlobal = notifyEditor($('#notify-editor'), { rules: parse(s.notify_rules), channels: parse(s.notify_channels), flap: parse(s.notify_flap) },
    { pushover_enabled: s.pushover_enabled === '1', email_enabled: s.email_enabled === '1', ha_webhook_enabled: s.ha_webhook_enabled === '1' });
  quietGlobal = quietEditor($('#quiet-editor'), parse(s.notify_quiet), true);
  sysinfoCfg = parse(s.sysinfo);
  renderSysinfo();
  vulnExcludeEditor();
  $('#vuln-enabled').checked = s.vuln_enabled === '1';
  $('#ids-enabled').checked = s.ids_enabled === '1';
  $('#ids-mode').checked = s.ids_mode === 'block';
  fimGlobal = fimEditor($('#fim-editor'), parse(s.ids_fim || '[]'), null, () => { markSettingsDirty(true); settingsSummaries(); });
  idsModeInfo();
  backupFormToggle();
  markSettingsDirty(false);
  applySettingsLayout();
  loadBackups();
  loadUpdate(false);
  loadAgentCommands();
  refresh();   // verse agentversies voor de kaart "Agents bijwerken"
}

/** Kaart "Agents": hoeveel agents verouderd zijn, en de knop om ze allemaal bij te werken. */
function renderAgentUpdates() {
  const agents = state.hosts.filter((h) => h.mode === 'agent' && +h.enabled && h.agent_seen);
  const old = agents.filter((h) => !h.agent_version || verCmp(h.agent_version, state.agent_latest) < 0);
  const busy = agents.filter((h) => h.agent_update_req);
  $('#agents-status').innerHTML = !agents.length ? L('Nog geen agents.')
    : (old.length ? `<b class="warn-txt">${L('{n} van {m} agent(s) verouderd', { n: old.length, m: agents.length })}</b>: ${old.map((h) => esc(h.name) + (h.agent_version ? ` (${esc(h.agent_version)})` : '')).join(', ')}`
      : `<b class="good-txt">✔ ${L('Alle {n} agent(s) zijn up-to-date (agent {v})', { n: agents.length, v: esc(state.agent_latest) })}</b>`)
      + (busy.length ? ` · ⏳ ${L('{n} wordt bijgewerkt', { n: busy.length })}` : '');
  $('#agents-update-all').hidden = !old.length;
  settingsSummaries();
}

async function updateAllAgents(e) {
  const b = e.currentTarget;
  b.disabled = true;
  try {
    const r = await api('agent_update', { all: true });
    toast(L('{n} agent(s) worden bijgewerkt bij hun volgende rapport', { n: r.count }));
    await refresh();
    renderAgentUpdates();
  } catch (ex) { toast(ex.message, 'err'); }
  b.disabled = false;
}

// ---------------------------------------------------------------- Meldregels (algemeen en per check)
const NOTIFY_ROWS = [
  ['warn', '🟠 Warning'], ['crit', '🔴 Critical'], ['unknown', '⚪ ' + L('Onbekend')], ['recovery', '✅ ' + L('Hersteld')], ['info', 'ℹ️ ' + L('Info (checks)')],
];
const NOTIFY_CHANNELS = [['pushover', 'Pushover', 'pushover_enabled'], ['email', L('E-mail'), 'email_enabled'], ['webhook', 'Home Assistant', 'ha_webhook_enabled']];

/** Tabel met per status: melden (nooit / direct / na X min), herhalen en kanalen. Geeft {get()} terug. */
function notifyEditor(el, cfg, enabled = {}) {
  const r = cfg.rules || {}, ch = cfg.channels || {}, fl = cfg.flap || {};
  el.innerHTML = `<div class="table-scroll"><table class="notify-table">
    <thead><tr><th>${L('Status')}</th><th>${L('Melden')}</th><th class="nt-rep">${L('Herhalen')}</th>
      ${NOTIFY_CHANNELS.map(([k, l, set]) => `<th class="nt-ch" title="${enabled[set] === false ? L('Dit kanaal staat uit (zie hieronder)') : ''}">${l}${enabled[set] === false ? ` <span class="muted">(${L('uit')})</span>` : ''}</th>`).join('')}
      <th class="nt-ch" title="${L('Alleen bij info: problemen staan altijd op de pagina Meldingen')}">📋 ${L('Meldingenpagina')}</th></tr></thead>
    <tbody>${NOTIFY_ROWS.map(([k, label]) => {
      const x = r[k] || { mode: k === 'info' ? 'never' : 'now', min: 0, repeat: 0 };   // info: standaard uit
      return `<tr data-k="${k}">
        <td class="nt-label">${label}</td>
        <td><div class="nt-when">
          <select data-f="mode">
            <option value="never" ${x.mode === 'never' ? 'selected' : ''}>${L('Nooit')}</option>
            <option value="now" ${x.mode === 'now' ? 'selected' : ''}>${L('Direct')}</option>
            ${k === 'info' ? '' : `<option value="after" ${x.mode === 'after' ? 'selected' : ''}>${k === 'recovery' ? L('Na … min OK') : L('Na … minuten')}</option>`}
          </select>
          <input type="number" data-f="min" min="1" max="10080" value="${x.mode === 'after' ? x.min : 5}" ${x.mode === 'after' ? '' : 'hidden'} title="${L('minuten')}">
        </div></td>
        <td class="nt-rep">${k === 'recovery' || k === 'info' ? '<span class="muted small">–</span>'
          : `<span class="nt-rep">${L('elke {input} min', { input: `<input type="number" data-f="repeat" min="0" max="10080" value="${x.repeat || 0}">` })}</span>`}</td>
        ${NOTIFY_CHANNELS.map(([c]) => `<td class="nt-ch"><input type="checkbox" data-c="${c}" ${(ch[k] || {})[c] !== false ? 'checked' : ''}></td>`).join('')}
        <td class="nt-ch">${k === 'info' ? `<input type="checkbox" data-c="page" ${(ch.info || {}).page !== false ? 'checked' : ''}>` : `<span class="muted small" title="${L('Altijd')}">✓</span>`}</td>
      </tr>`;
    }).join('')}</tbody></table></div>
    <p class="hint"><span class="nt-rep-hint">${L('Herhalen: elke X minuten opnieuw zolang het probleem blijft (0 = nooit).')}</span> ${L('Info: gebeurtenissen die geen storing zijn (herstart, vernieuwd certificaat, agent of RzMon bijgewerkt), stil via Pushover; tijdens stille uren niet verstuurd, wel op de pagina Meldingen.')}</p>
    <div class="flap-box">
      <label class="switch small"><input type="checkbox" data-flap="on" ${fl.on !== false ? 'checked' : ''}><span></span> <b>Anti-flapping</b></label>
      <div class="flap-fields">
        ${L('Een check flapt bij {changes} statuswisselingen binnen {window} minuten; weer stabiel na {stable} minuten zonder wisseling.', {
          changes: `<input type="number" data-flap="changes" min="2" max="100" value="${fl.changes ?? 4}">`,
          window: `<input type="number" data-flap="window" min="1" max="1440" value="${fl.window ?? 30}">`,
          stable: `<input type="number" data-flap="stable" min="1" max="1440" value="${fl.stable ?? 15}">` })}
      </div>
      <p class="hint">${L('Flapt een check, dan krijg je één melding "staat te flappen", daarna per status (warning, critical, onbekend, hersteld) hooguit één melding, en een melding als hij weer stabiel is.')} ${L('Ook hier geldt "melden na … mislukte checks achter elkaar": zolang dat aantal niet gehaald wordt, flapt hij stil.')}</p>
    </div>`;
  const sync = () => { $('.flap-fields', el).classList.toggle('off', !$('[data-flap=on]', el).checked); $$('tr[data-k]', el).forEach((tr) => {
    const mode = $('[data-f=mode]', tr).value;
    $('[data-f=min]', tr).hidden = mode !== 'after';
    $$('input', tr).forEach((i) => { if (i.dataset.f !== 'min') i.disabled = mode === 'never' && i.dataset.f === 'repeat'; });
  }); };
  el.onchange = sync;
  sync();
  return {
    /** Info-check: alleen de rij Info tonen; de andere rijen blijven bewaard (weer zichtbaar als het uit gaat) */
    infoOnly(on) {
      $$('tr[data-k]', el).forEach((tr) => (tr.hidden = on && tr.dataset.k !== 'info'));
      $$('.flap-box, .nt-rep-hint, th.nt-rep, td.nt-rep', el).forEach((x) => (x.hidden = on));
    },
    get() {
      const out = { rules: {}, channels: {} };
      $$('tr[data-k]', el).forEach((tr) => {
        const k = tr.dataset.k;
        out.rules[k] = { mode: $('[data-f=mode]', tr).value, min: +$('[data-f=min]', tr).value || 1, repeat: +($('[data-f=repeat]', tr)?.value || 0) };
        out.channels[k] = Object.fromEntries($$('[data-c]', tr).map((i) => [i.dataset.c, i.checked]));
      });
      out.flap = { on: $('[data-flap=on]', el).checked, changes: +$('[data-flap=changes]', el).value, window: +$('[data-flap=window]', el).value, stable: +$('[data-flap=stable]', el).value };
      return out;
    },
  };
}
let notifyGlobal = null;   // editor van de algemene meldregels
let fimGlobal = null;      // editor van de algemene lijst 'Bestanden bewaken' (IDS)
let quietGlobal = null;    // editor van de algemene stille uren

// ---------------------------------------------------------------- Stille uren
const QUIET_DAYS = [[1, L('ma')], [2, L('di')], [3, L('wo')], [4, L('do')], [5, L('vr')], [6, L('za')], [7, L('zo')]];
const QUIET_DEFAULT = () => ({ from: '23:00', to: '07:00', days: [1, 2, 3, 4, 5, 6, 7] });
const QUIET_MODES = { global: L('algemeen (Instellingen)'), own: L('eigen vensters'), never: L('nooit stil: altijd melden') };
/** Eigen stille uren van een check ({mode, windows}) of null (= algemeen). */
function quietOf(c) { try { return JSON.parse(c.quiet || 'null'); } catch (e) { return null; } }
/** "ma–vr 23:00–07:00 · za, zo 00:00–10:00" */
function quietText(windows) {
  return (windows || []).map((w) => {
    const d = w.days.length === 7 ? L('elke dag') : w.days.join(',') === '1,2,3,4,5' ? L('ma–vr') : w.days.join(',') === '6,7' ? L('za–zo')
      : w.days.map((x) => QUIET_DAYS[x - 1][1]).join(', ');
    return `${d} ${w.from}–${w.to}`;
  }).join(' · ');
}

/** Vensters (van–tot, dagen) met optioneel een aan/uit-schakelaar. Geeft {get()} terug: { on, windows }. */
function quietEditor(el, cfg, withSwitch) {
  const row = (w) => `<div class="quiet-row">
      <span class="quiet-time">${L('van {from} tot {to}', { from: `<input type="time" data-q="from" value="${esc(w.from)}" required>`, to: `<input type="time" data-q="to" value="${esc(w.to)}" required>` })}</span>
      <span class="quiet-days">${QUIET_DAYS.map(([d, l]) => `<label class="day-chip"><input type="checkbox" data-day="${d}" ${w.days.includes(d) ? 'checked' : ''}><span>${l}</span></label>`).join('')}</span>
      <button type="button" class="icon-btn" data-q="del" title="${L('Venster verwijderen')}">🗑</button>
    </div>`;
  const wins = cfg.windows && cfg.windows.length ? cfg.windows : [QUIET_DEFAULT()];
  el.innerHTML = `${withSwitch ? `<label class="switch small"><input type="checkbox" data-q="on" ${cfg.on ? 'checked' : ''}><span></span> <b>${L('Stille uren aan')}</b></label>` : ''}
    <div class="quiet-fields"><div class="quiet-list">${wins.map(row).join('')}</div>
      <button type="button" class="btn small-btn" data-q="add">＋ ${L('Venster')}</button>
      <p class="hint">${L('Over middernacht mag (23:00 tot 07:00 hoort bij de dag waarop het begint). Van gelijk aan tot = 24 uur. Meerdere vensters, bv. door de week 23:00–07:00 en in het weekend 00:00–10:00.')}</p></div>`;
  const sync = () => {
    if (withSwitch) $('.quiet-fields', el).classList.toggle('off', !$('[data-q=on]', el).checked);
    $$('[data-q=del]', el).forEach((b) => (b.disabled = $$('.quiet-row', el).length < 2));
  };
  el.onclick = (e) => {
    if (e.target.closest('[data-q=add]')) $('.quiet-list', el).insertAdjacentHTML('beforeend', row({ from: '00:00', to: '08:00', days: [6, 7] }));
    const del = e.target.closest('[data-q=del]');
    if (del) del.closest('.quiet-row').remove();
    sync();
  };
  el.onchange = sync;
  sync();
  return {
    get() {
      return {
        on: withSwitch ? $('[data-q=on]', el).checked : true,
        windows: $$('.quiet-row', el).map((r) => ({ from: $('[data-q=from]', r).value, to: $('[data-q=to]', r).value,
          days: $$('[data-day]', r).filter((i) => i.checked).map((i) => +i.dataset.day) })).filter((w) => w.from && w.to && w.days.length),
      };
    },
  };
}

// ---------------------------------------------------------------- ℹ️ Systeeminfo: per melding de kanalen
const SYSINFO_ROWS = [
  ['RzMon', [['rzmon_update', '⬆ ' + L('RzMon bijgewerkt')]]],
  [L('Agents'), [['agent_update', '⬆ ' + L('Agent bijgewerkt')], ['agent_update_fail', '✘ ' + L('Agent bijwerken mislukt')], ['agent_connect', '🔌 ' + L('Agent verbonden')]]],
  [L('Inloggen'), [['login_ok', '🔑 ' + L('Ingelogd')], ['login_fail', '❌ ' + L('Mislukte login')]]],
  [L('Blokkades'), [['account_lock', '🔒 ' + L('Account geblokkeerd')], ['account_unlock', '🔓 ' + L('Account gedeblokkeerd')],
    ['ip_block', '⛔ ' + L('IP geblokkeerd')], ['ip_unblock', '✅ ' + L('Blokkade opgeheven')]]],
  [L('Kwetsbaarheden'), [['vuln_new', '🐞 ' + L('Nieuwe kwetsbaarheden')], ['vuln_upgrade', '📦 ' + L('Pakketten bijgewerkt of mislukt')]]],
  [L('Beveiliging'), [['sec', '⚠️ ' + L('Niet-geautoriseerde verbinding')]]],
];
const SYSINFO_CH = [['pushover', '📲 Pushover', 'pushover_enabled'], ['email', '📧 ' + L('E-mail'), 'email_enabled'], ['webhook', '🏠 Home Assistant', 'ha_webhook_enabled'], ['page', '📄 ' + L('Meldingenpagina'), null]];
let sysinfoCfg = null;

function renderSysinfo() {
  const t = $('#sysinfo');
  if (!t || !sysinfoCfg) return;
  const f = $('#settings-form');
  const off = (k) => k && f[k] && !f[k].checked;   // kanaal staat helemaal uit
  t.innerHTML = `<thead><tr><th>${L('Melding')}</th>${SYSINFO_CH.map(([, l, k]) => `<th class="si-ch">${l}${off(k) ? ` <span class="muted small">(${L('uit')})</span>` : ''}</th>`).join('')}
    <th>${L('Pushover-geluid')}</th><th></th></tr></thead><tbody>
    ${SYSINFO_ROWS.map(([group, rows]) => `<tr class="si-group"><td colspan="7">${esc(group)}</td></tr>` + rows.map(([k, label]) => {
      const c = sysinfoCfg[k] || {};
      return `<tr data-si="${k}"><td>${esc(label)}${k === 'sec' ? `<div class="muted small">${L('ook tijdens stille uren')}</div>` : ''}</td>
        ${SYSINFO_CH.map(([ch]) => {
          const fixed = k === 'sec' && ch === 'page';   // de telling per IP staat op de meldingenpagina
          return `<td class="si-ch"><input type="checkbox" data-ch="${ch}" ${c[ch] || fixed ? 'checked' : ''} ${fixed ? `disabled title="${esc(L('Altijd: hier telt RzMon de pogingen per IP'))}"` : ''}></td>`;
        }).join('')}
        <td><select data-prio ${c.pushover ? '' : 'disabled'}><option value="-1" ${+c.prio < 0 ? 'selected' : ''}>🔕 ${L('stil')}</option><option value="0" ${+c.prio >= 0 ? 'selected' : ''}>🔔 ${L('normaal')}</option></select></td>
        <td><button type="button" class="icon-btn" data-si-test title="${esc(L('Testmelding sturen naar de aangevinkte kanalen'))}">🧪</button></td></tr>`;
    }).join('')).join('')}</tbody>`;
}

function initSysinfo() {
  const t = $('#sysinfo');
  if (!t) return;
  t.addEventListener('change', (e) => {
    const tr = e.target.closest('tr[data-si]');
    if (!tr || !sysinfoCfg) return;
    const c = (sysinfoCfg[tr.dataset.si] ||= {});
    if (e.target.dataset.ch) c[e.target.dataset.ch] = e.target.checked;
    if (e.target.matches('[data-prio]')) c.prio = +e.target.value;
    $('[data-prio]', tr).disabled = !c.pushover;
  });
  t.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-si-test]');
    if (!b) return;
    const tr = b.closest('tr[data-si]');
    const label = $('td', tr).firstChild.textContent.trim();
    const c = sysinfoCfg[tr.dataset.si];
    const to = SYSINFO_CH.filter(([ch]) => c[ch] || (tr.dataset.si === 'sec' && ch === 'page')).map(([, l]) => l);
    if (!to.length) return toast(L('Vink eerst minstens één kanaal aan'), 'err');
    b.disabled = true;
    try { await api('sysinfo_test', { event: tr.dataset.si, label, cfg: c }); toast(L('Testmelding verstuurd naar: {to}', { to: to.join(', ') })); }
    catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  });
  // Kanaal aan/uit (Pushover, e-mail, webhook): "(uit)" in de kop bijwerken
  ['pushover_enabled', 'email_enabled', 'ha_webhook_enabled'].forEach((k) => $('#settings-form')[k] && $('#settings-form')[k].addEventListener('change', renderSysinfo));
}

function settingsData() {
  const f = $('#settings-form');
  if (notifyGlobal) {
    const n = notifyGlobal.get();
    f.notify_rules.value = JSON.stringify(n.rules);
    f.notify_channels.value = JSON.stringify(n.channels);
    f.notify_flap.value = JSON.stringify(n.flap);
  }
  if (quietGlobal) f.notify_quiet.value = JSON.stringify(quietGlobal.get());
  if (sysinfoCfg) f.sysinfo.value = JSON.stringify(sysinfoCfg);
  if (fimGlobal) f.ids_fim.value = JSON.stringify(fimGlobal.get());
  const d = {};
  [...f.elements].forEach((el) => {
    if (!el.name) return;
    d[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
  });
  return d;
}

async function saveSettings(e) {
  e.preventDefault();   // Enter in een veld: meteen opslaan
  if (document.activeElement && document.activeElement.form === e.target) markSettingsDirty(true, document.activeElement);
  try { await autosaveSettings(); }
  catch (ex) { toast(ex.message, 'err'); }
}

async function testPushover(e) {
  const b = e.target;
  b.disabled = true;
  try { await api('pushover_test', settingsData()); toast(L('Testbericht verstuurd! Check je telefoon') + ' 📱'); }
  catch (ex) { toast('Pushover: ' + ex.message, 'err'); }
  b.disabled = false;
}

async function testWebhook(e) {
  const b = e.target;
  b.disabled = true;
  try { await api('webhook_test', settingsData()); toast(L('Testbericht naar Home Assistant verstuurd') + ' 🏠'); }
  catch (ex) { toast('Webhook: ' + ex.message, 'err'); }
  b.disabled = false;
}

/** Testknop voor een meldkanaal, met de (nog niet opgeslagen) waarden uit het formulier. */
async function testChannel(e, action, okMsg, label) {
  const b = e.target;
  b.disabled = true;
  try {
    const r = await api(action, settingsData());
    toast(okMsg || r.message);
    if (action === 'mqtt_test') loadSettings();
  } catch (ex) { toast(`${label}: ${ex.message}`, 'err'); }
  b.disabled = false;
}

async function changePassword(e) {
  e.preventDefault();
  try {
    await api('password', Object.fromEntries(new FormData(e.target)));
    e.target.reset();
    toast(L('Wachtwoord gewijzigd'));
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Gebruikers & tweestapsverificatie
let users = [];
const ROLE_LABEL = { admin: '🔑 ' + L('Beheerder'), user: '👤 ' + L('Gebruiker'), readonly: '👁 ' + L('Alleen lezen') };
async function loadUsers() {
  if ($('#tab-account') && !$('#tab-account').hidden) loadAccount();   // bv. na 2FA instellen vanaf Mijn account
  if ($('#users-card').hidden || !isAdmin()) return;
  users = await api('users');
  if (isAdmin()) {
    api('pw_reset_info').then((r) => {
      $('#pw-reset-info').innerHTML = (r.unavailable
        ? `<span class="warn-txt">⚠️ ${L('Wachtwoord vergeten werkt nu niet: {reason}.', { reason: esc(r.unavailable) })}</span> `
        : '🔑 ' + L('Wachtwoord vergeten werkt voor accounts met een e-mailadres.') + ' ')
        + `<a href="#" id="pw-reset-log">${L('Logboek bekijken ({n})', { n: r.log.length })}</a>`;
      $('#pw-reset-log').onclick = (e) => { e.preventDefault(); pwResetLog(r.log); };
    }).catch(() => {});
  }
  $('#users').innerHTML = users.map((u) => `
    <div class="key-row">
      <span class="grow"><strong>${esc(u.username)}</strong>${u.me ? ` <span class="badge">${L('jij')}</span>` : ''}
        <span class="role-tag r-${esc(u.role)}">${ROLE_LABEL[u.role] || esc(u.role)}</span>
        ${u.totp ? `<span class="tfa on" title="${L('Tweestapsverificatie staat aan')}">🔐 ${L('2FA aan')}</span>` : `<span class="tfa" title="${L('Alleen wachtwoord')}">${L('2FA uit')}</span>`}
        ${u.locked ? `<span class="locked-tag" title="${esc(L('Te veel mislukte pogingen vanaf {ip}; vanaf het lokale netwerk kan inloggen nog wel', { ip: u.locked_ip || '?' }))}">🔒 ${u.locked_until ? L('geblokkeerd tot {t}', { t: esc(fmtDateTime(u.locked_until)) }) : L('geblokkeerd')}</span>` : ''}<br>
        <span class="muted small">${u.email ? `✉ ${esc(u.email)}` : `<span title="${L('Nodig voor wachtwoord vergeten')}">✉ ${L('geen e-mailadres')}</span>`} · ${L('Laatst ingelogd: {ago}', { ago: u.last_login ? ago(u.last_login) : L('nooit') })}</span></span>
      <span class="row-actions">
        ${u.me ? `<button class="btn" data-uact="mine" data-id="${u.id}" title="${L('Je eigen wachtwoord, 2FA, e-mail en apparaten')}">👤 ${L('Mijn account')}</button>` : `${u.totp
          ? `<button class="btn" data-uact="totp-reset" data-id="${u.id}" title="${L('Tweestapsverificatie uitzetten, bv. bij een verloren telefoon')}">${L('2FA resetten')}</button>`
          : `<button class="btn" data-uact="totp-setup" data-id="${u.id}">🔐 ${L('2FA instellen')}</button>`}
        ${u.locked ? `<button class="btn need-admin" data-uact="unlock" data-id="${u.id}">🔓 ${L('Deblokkeren')}</button>` : ''}
        ${u.me ? '' : `<select class="role-select need-admin" data-uact="role" data-id="${u.id}" title="${L('Rol wijzigen')}">
          ${Object.entries(ROLE_LABEL).map(([k, l]) => `<option value="${k}" ${u.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select>`}
        <button class="btn small-btn" data-uact="devices" data-id="${u.id}" title="${L('Apparaten waarop {user} ingelogd blijft', { user: esc(u.username) })}">📱 ${+u.devices}</button>
        <button class="icon-btn" data-uact="email" data-id="${u.id}" title="${L('E-mailadres (voor wachtwoord vergeten)')}">✉</button>
        <button class="icon-btn need-admin" data-uact="password" data-id="${u.id}" title="${L('Nieuw wachtwoord instellen')}">🔑</button>
        <button class="icon-btn need-admin" data-uact="delete" data-id="${u.id}" title="${L('Verwijderen')}">🗑</button>`}
      </span>
    </div>`).join('');
  settingsSummaries();
}

/** Logboek van "wachtwoord vergeten": aanvragen, verstuurde links en herstelde wachtwoorden. */
function pwResetLog(log) {
  modal({
    title: '🔑 ' + L('Wachtwoord vergeten – logboek'),
    wide: true,
    body: log.length ? `<div class="table-scroll"><table class="port-table"><thead><tr><th>${L('Wanneer')}</th><th>${L('Account')}</th><th>${L('Ingevuld')}</th><th>${L('IP-adres')}</th><th>${L('Wat')}</th></tr></thead><tbody>
      ${log.map((l) => `<tr><td title="${esc(l.created_at)}">${ago(l.created_at)}</td><td>${l.username ? esc(l.username) : '<span class="muted">–</span>'}</td>
        <td class="mono small">${esc(l.ident)}</td><td class="mono small">${esc(l.ip)}</td><td>${esc(l.event)}</td></tr>`).join('')}</tbody></table></div>
      <p class="muted small">${L('De laatste 50 regels; ouder dan 90 dagen wordt opgeruimd.')}</p>`
      : `<p class="muted">${L('Nog geen aanvragen.')}</p>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
}

async function createUser(e) {
  e.preventDefault();
  const f = e.target;
  try {
    const r = await api('user_create', { username: f.username.value, password: f.password.value, email: f.email.value, role: f.role.value });
    const name = f.username.value;
    const withTotp = f.totp.checked;
    f.reset();
    toast(L('Gebruiker {name} aangemaakt', { name }));
    await loadUsers();
    if (withTotp) totpSetup(r.id, name);
  } catch (ex) { toast(ex.message, 'err'); }
}

async function onUsersClick(e) {
  const b = e.target.closest('button[data-uact]');
  if (!b) return;
  const u = users.find((x) => +x.id === +b.dataset.id);
  try {
    switch (b.dataset.uact) {
      case 'totp-setup': return totpSetup(u.id, u.username);
      case 'devices': return devicesDialog(u);
      case 'mine': return showTab('account');
      case 'unlock':
        await api('user_unlock', { id: u.id });
        toast(L('{user} gedeblokkeerd', { user: u.username }));
        return loadUsers();
      case 'totp-reset':
        if (!confirm(L('Tweestapsverificatie van {user} resetten?', { user: u.username }) + '\n' + L('Inloggen kan daarna weer met alleen het wachtwoord, tot 2FA opnieuw is ingesteld.'))) return;
        await api('totp_reset', { id: u.id });
        toast(L('2FA van {user} gereset', { user: u.username }));
        return loadUsers();
      case 'delete':
        if (!confirm(L('Gebruiker {user} verwijderen?', { user: u.username }))) return;
        await api('user_delete', { id: u.id });
        toast(L('{name} verwijderd', { name: u.username }));
        return loadUsers();
      case 'email':
        return modal({
          title: `${L('E-mailadres')} – ${u.username}`,
          body: `<p class="muted small" style="margin-top:0">${u.me ? L('Hierheen gaat de link als je wachtwoord vergeten is. Leeg = geen herstel per e-mail.') : L('Hierheen gaat de link als {user} zijn of haar wachtwoord vergeten is. Leeg = geen herstel per e-mail.', { user: esc(u.username) })}</p>
            <label>${L('E-mailadres')}<input type="email" name="email" value="${esc(u.email || '')}" maxlength="190" autocomplete="email" autofocus></label>`,
          buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
          onSubmit: async (d) => { await api('user_email', { id: u.id, email: d.email }); toast(L('E-mailadres opgeslagen')); loadUsers(); },
        });
      case 'password':
        return modal({
          title: `${L('Nieuw wachtwoord')} – ${u.username}`,
          body: `<label>${L('Nieuw wachtwoord')}<input type="password" name="password" minlength="8" required autocomplete="new-password" autofocus></label>`,
          buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
          onSubmit: async (d) => { await api('user_password', { id: u.id, password: d.password }); toast(L('Wachtwoord gewijzigd')); },
        });
    }
  } catch (ex) { toast(ex.message, 'err'); }
}

/** Onthouden apparaten van een gebruiker: bekijken en vergeten. */
async function devicesDialog(u) {
  let list;
  try { list = await api('devices', null, { user_id: u.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const icon = (d) => (/iPhone|Android/.test(d) ? '📱' : /iPad/.test(d) ? '📲' : '💻');
  modal({
    title: `📱 ${L('Onthouden apparaten')} – ${u.username}`,
    body: list.length ? `
      <p class="muted small" style="margin-top:0">${L('Op deze apparaten blijft {user} ingelogd (zonder wachtwoord en 2FA). Een apparaat wordt vanzelf vergeten na 30 dagen niet gebruiken, en bij een wachtwoordwijziging.', { user: esc(u.username) })}</p>
      <div class="key-list" id="device-list">${list.map((d) => `
        <div class="key-row">
          <span class="grow"><strong>${icon(d.device)} ${esc(d.device || L('Onbekend apparaat'))}</strong>${d.current ? ` <span class="badge">${L('dit apparaat')}</span>` : ''}<br>
            <span class="muted small">${L('Laatst gebruikt {ago}', { ago: ago(d.last_used) })} · ${esc(d.ip)} · ${L('sinds {date}', { date: esc(fmtUntil(d.created_at)) })}</span></span>
          <button type="button" class="btn small-btn" data-forget="${d.id}">${L('Vergeten')}</button>
        </div>`).join('')}</div>`
      : `<p class="muted">${L('Geen onthouden apparaten. Zet bij het inloggen <b>Onthoud mij op dit apparaat</b> aan.')}</p>`,
    buttons: [
      ...(list.length ? [{ label: L('Alle apparaten vergeten'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Alle onthouden apparaten van {user} vergeten? Daar moet dan opnieuw worden ingelogd.', { user: u.username }))) return;
        try { await api('device_forget_all', { user_id: u.id }); toast(L('Alle apparaten vergeten')); $('#modal').close(); loadUsers(); }
        catch (ex) { toast(ex.message, 'err'); }
      } }] : []),
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  $('#device-list')?.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-forget]');
    if (!b) return;
    try {
      await api('device_forget', { id: b.dataset.forget });
      toast(L('Apparaat vergeten'));
      loadUsers();
      devicesDialog(u);
    } catch (ex) { toast(ex.message, 'err'); }
  });
}

async function totpSetup(id, username) {
  let r;
  try { r = await api('totp_setup', { id }); } catch (ex) { return toast(ex.message, 'err'); }
  let qr = '';
  try {
    const q = qrcode(0, 'M');
    q.addData(r.uri);
    q.make();
    qr = q.createSvgTag(5, 4);
  } catch (ex) { qr = `<p class="muted small">${L('QR-code maken lukte niet; gebruik de code hieronder.')}</p>`; }
  modal({
    title: `🔐 ${L('Tweestapsverificatie')} – ${username}`,
    body: `
      <ol class="steps">
        <li>${L('Open een authenticator-app op de telefoon van <b>{user}</b> en scan deze QR-code:', { user: esc(username) })}</li>
      </ol>
      <div class="qr">${qr}</div>
      <p class="muted small" style="text-align:center">${L('Scannen lukt niet? Voer deze sleutel handmatig in:')}<br><code class="secret">${esc(r.secret.replace(/(.{4})/g, '$1 ').trim())}</code></p>
      <ol class="steps" start="2">
        <li>${L('Vul de 6-cijferige code in die de app nu toont. Pas daarna staat 2FA aan.')}</li>
      </ol>
      <label>${L('Code')}<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required class="mono totp-input" autofocus></label>`,
    buttons: [{ label: L('Activeren'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('totp_confirm', { id, code: d.code });
      toast(L('2FA staat aan voor {user}', { user: username }) + ' 🔐');
      loadUsers();
    },
  });
}

// ---------------------------------------------------------------- Databasewachtwoord vernieuwen
let dbInfo = null;
async function loadDbInfo() {
  try { dbInfo = await api('db_info'); } catch (ex) { return; }
  $('#db-status').innerHTML = L('Gebruiker {user} op {host}, database {db}', { user: `<code>${esc(dbInfo.db.user)}</code>`, host: `<code>${esc(dbInfo.db.host)}</code>`, db: `<code>${esc(dbInfo.db.name)}</code>` }) + ' · '
    + (dbInfo.helper ? `<b class="good-txt">${L('hulpdienst actief')}</b>` : dbInfo.direct ? L('RzMon mag config.php zelf opslaan') : `<b class="warn-txt">${L('hulpdienst niet geïnstalleerd')}</b>`);
  settingsSummaries();
}

/** Een commando met kopieerknop. */
function copyBox(id, cmd) {
  return `<div class="copy-line"><pre class="code" id="${id}">${esc(cmd)}</pre><button type="button" class="btn" data-copy="${id}">${L('Kopieer')}</button></div>`;
}
function wireCopy(root) {
  $$('[data-copy]', root).forEach((b) => (b.onclick = async () => {
    const el = $('#' + b.dataset.copy);
    try { await navigator.clipboard.writeText(el.textContent); toast(L('Gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren(el); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  }));
}

/** Bevestigen met je eigen wachtwoord, of een woord typen als je automatisch beheerder bent op het LAN. */
// ---------------------------------------------------------------- Commando-checks: aan/uit
const CMD_MODES = { off: L('uit'), nobody: L('als nobody'), root: L('als root') };
const CMD_MATCHES = { equals: L('is gelijk aan'), contains: L('bevat'), regex: L('komt overeen met (regex)'), exit0: L('exitcode is 0') };
const CMD_RANK = { off: 0, nobody: 1, root: 2 };
const CMD_GROUPS = { truenas: '🗄️ TrueNAS SCALE / ZFS', proxmox: '🖥️ Proxmox VE', linux: '🐧 Linux' };
// Kant-en-klare commando-checks. rec: in "Aanbevolen checks" voor dat platform (true = standaard aangevinkt);
// os: alleen voor Debian/Ubuntu of Rocky/RHEL; root: werkt niet als nobody; tested: op een echt systeem getest.
const CMD_PRESETS = {
  zfs_health: {"group": "truenas", "label": L('ZFS-pools gezond'), "name": "ZFS-pools", "cmd": "zpool status -x", "match": "equals", "expect": "all pools are healthy", "fail": "crit", "root": false, "interval": 300, "rec": {"truenas": true, "proxmox": false}, "timeout": 10, "tested": false},
  zfs_cap: {"group": "truenas", "label": L('ZFS-pools onder 80% vol'), "name": "ZFS-capaciteit", "cmd": "zpool list -H -o capacity | tr -d '%' | sort -n | tail -1", "match": "regex", "expect": "^[0-7]?[0-9]$", "fail": "warn", "root": false, "interval": 300, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  zfs_scrub: {"group": "truenas", "label": L('Scrub zonder fouten'), "name": "ZFS-scrub", "cmd": "out=$(zpool status) || exit 2; ! grep -E 'repaired [1-9]|with [1-9][0-9]* errors|errors: [0-9]+ data errors' <<<\"$out\"", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 900, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  truenas_alerts: {"group": "truenas", "label": L('Geen TrueNAS-meldingen (ERROR/CRITICAL)'), "name": "TrueNAS-meldingen", "cmd": "midclt call alert.list | python3 -c 'import json,sys; a=[x for x in json.load(sys.stdin) if not x.get(\"dismissed\") and x.get(\"level\") in (\"ERROR\",\"CRITICAL\",\"ALERT\",\"EMERGENCY\")]; print(len(a)); [print((x.get(\"formatted\") or x.get(\"klass\") or \"\")[:120]) for x in a[:3]]'", "match": "equals", "expect": "0", "fail": "warn", "root": true, "interval": 300, "rec": {"truenas": true}, "timeout": 10, "tested": false},
  truenas_apps: {"group": "truenas", "label": L('Apps (Docker) draaien'), "name": "TrueNAS-apps", "cmd": "s=$(docker ps -a --filter status=exited --filter status=dead --format '{{.Names}}') || exit 2; [ -z \"$s\" ] || { echo \"gestopt: $(echo $s | head -c 200)\"; exit 1; }", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"truenas": false}, "timeout": 10, "tested": false},
  pve_quorum: {"group": "proxmox", "label": L('Cluster heeft quorum (alleen bij een cluster)'), "name": "Proxmox-quorum", "cmd": "pvecm status | grep -q 'Quorate:.*Yes' && echo ok", "match": "equals", "expect": "ok", "fail": "crit", "root": true, "interval": 120, "rec": {"proxmox": false}, "timeout": 10, "tested": false},
  pve_storage: {"group": "proxmox", "label": L('Opslag onder 85% vol'), "name": "Proxmox-opslag", "cmd": "pvesm status | awk 'NR>1 { p=$7; gsub(\"%\",\"\",p); if (p+0 > 85) { print $1 \" \" $7; f=1 } } END { exit f }'", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  pve_backup: {"group": "proxmox", "label": L('Laatste back-uptaak (vzdump) geslaagd'), "name": "Proxmox-back-up", "cmd": "awk -F: '$6==\"vzdump\" {l=$0} END {n=split(l,a,\" \"); s=\"\"; for(i=3;i<=n;i++) s=s (i>3?\" \":\"\") a[i]; print (s==\"\"?\"geen back-uptaak gevonden\":s)}' /var/log/pve/tasks/index", "match": "equals", "expect": "OK", "fail": "crit", "root": true, "interval": 900, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  pve_ceph: {"group": "proxmox", "label": L('Ceph gezond (alleen met Ceph)'), "name": "Ceph", "cmd": "ceph health", "match": "equals", "expect": "HEALTH_OK", "fail": "crit", "root": true, "interval": 120, "rec": {"proxmox": false}, "timeout": 10, "tested": false},
  pve_updates: {"group": "proxmox", "label": L('Geen Proxmox-updates'), "name": "Proxmox-updates", "cmd": "apt list --upgradable 2>/dev/null | grep -c -E '^(pve|proxmox)'", "match": "equals", "expect": "0", "fail": "warn", "root": false, "interval": 3600, "rec": {"proxmox": true}, "timeout": 10, "tested": false},
  failed_units: {"group": "linux", "label": L('Geen mislukte services'), "name": "Mislukte services", "cmd": "! systemctl --failed --no-legend --plain | awk '{print $1}' | grep .", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 300, "rec": {"linux": true, "proxmox": true}, "timeout": 10, "tested": false},
  reboot_deb: {"group": "linux", "os": "debian", "label": L('Geen herstart nodig (Debian/Ubuntu/Proxmox)'), "name": "Herstart nodig", "cmd": "if [ -f /var/run/reboot-required ]; then echo \"herstart nodig\"; head -3 /var/run/reboot-required.pkgs 2>/dev/null; exit 1; fi", "match": "exit0", "expect": "", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true, "proxmox": true}, "timeout": 10, "tested": true},
  reboot_rhel: {"group": "linux", "os": "rhel", "label": L('Geen herstart nodig (Rocky/RHEL)'), "name": "Herstart nodig", "cmd": "needs-restarting -r", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 900, "rec": {"linux": true}, "timeout": 10, "tested": false},
  updates_deb: {"group": "linux", "os": "debian", "label": L('Geen updates (Debian/Ubuntu)'), "name": "Updates", "cmd": "apt list --upgradable 2>/dev/null | grep -c upgradable", "match": "equals", "expect": "0", "fail": "warn", "root": false, "interval": 3600, "rec": {"linux": true}, "timeout": 10, "tested": true},
  security_rhel: {"group": "linux", "os": "rhel", "label": L('Geen beveiligingsupdates (Rocky/RHEL)'), "name": "Beveiligingsupdates", "cmd": "dnf -q updateinfo list --security 2>/dev/null | wc -l", "match": "equals", "expect": "0", "fail": "warn", "root": true, "interval": 3600, "timeout": 60, "rec": {"linux": true}, "tested": false},
  ntp: {"group": "linux", "label": L('Klok gesynchroniseerd (NTP)'), "name": "Tijd (NTP)", "cmd": "timedatectl show -p NTPSynchronized --value", "match": "equals", "expect": "yes", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true, "proxmox": true, "truenas": true}, "timeout": 10, "tested": false},
  inodes: {"group": "linux", "label": L('Inodes onder 90% (alle disks)'), "name": "Inodes", "cmd": "df --output=ipcent -x tmpfs -x devtmpfs -x squashfs -x overlay 2>/dev/null | tail -n +2 | tr -d ' %' | grep -v -- '-' | sort -n | tail -1", "match": "regex", "expect": "^[0-8]?[0-9]$", "fail": "warn", "root": false, "interval": 900, "rec": {"linux": true}, "timeout": 10, "tested": true},
  temp: {"group": "linux", "label": L('CPU-temperatuur onder 70 °C'), "name": "Temperatuur", "cmd": "cat /sys/class/thermal/thermal_zone0/temp", "match": "regex", "expect": "^([0-9]{1,4}|[1-6][0-9]{4})$", "fail": "warn", "root": false, "interval": 300, "rec": {}, "timeout": 10, "tested": false},
  mdadm: {"group": "linux", "label": L('Software-RAID (mdadm) heel'), "name": "RAID (mdadm)", "cmd": "if grep -q '\\[[U_]*_[U_]*\\]' /proc/mdstat 2>/dev/null; then grep -B1 '_' /proc/mdstat | head -4; exit 1; fi", "match": "exit0", "expect": "", "fail": "crit", "root": false, "interval": 300, "rec": {"linux": false}, "timeout": 10, "tested": true},
  smart: {"group": "linux", "label": L('SMART: alle fysieke schijven gezond'), "name": "SMART", "cmd": "fail=; for d in $(lsblk -dno NAME -e 7,11); do o=$(smartctl -H /dev/$d 2>/dev/null); if grep -qE \"overall-health|Health Status\" <<<\"$o\" && ! grep -qE \"PASSED|: OK\" <<<\"$o\"; then fail=\"$fail $d\"; fi; done; [ -z \"$fail\" ] || { echo \"geen OK van:$fail\"; exit 1; }", "match": "exit0", "expect": "", "fail": "crit", "root": true, "interval": 3600, "timeout": 30, "rec": {"proxmox": true, "linux": false}, "tested": false},
  docker: {"group": "linux", "label": L('Docker-containers draaien'), "name": "Docker-containers", "cmd": "s=$(docker ps -a --filter status=exited --filter status=dead --format '{{.Names}}') || exit 2; [ -z \"$s\" ] || { echo \"gestopt: $(echo $s | head -c 200)\"; exit 1; }", "match": "exit0", "expect": "", "fail": "warn", "root": true, "interval": 300, "rec": {"linux": false}, "timeout": 10, "tested": false},
};

/** Platform van een agent-server uit zijn servicelijst: TrueNAS, Proxmox of Linux, en Debian- of RHEL-familie. */
function detectPlatform(list) {
  const names = new Set((list || []).map((s) => s[0]));
  const platform = names.has('middlewared') ? 'truenas' : (names.has('pvedaemon') || names.has('pve-cluster')) ? 'proxmox' : 'linux';
  const os = names.has('apt-daily') || platform === 'proxmox' || platform === 'truenas' ? 'debian' : names.has('dnf-makecache') ? 'rhel' : null;
  return { platform, os };
}

/** Keuzelijst met voorinstellingen; de groep van dit platform bovenaan. */
function presetOptions(platform, selected = '') {
  const order = [platform, ...Object.keys(CMD_GROUPS).filter((g) => g !== platform)];
  return `<option value="">— ${L('Zelf invullen')} —</option>` + order.map((g) => `<optgroup label="${CMD_GROUPS[g]}">${Object.entries(CMD_PRESETS)
    .filter(([, p]) => p.group === g).map(([k, p]) => `<option value="${k}" ${k === selected ? 'selected' : ''}>${esc(p.label)}${p.root ? ' (root)' : ''}</option>`).join('')}</optgroup>`).join('');
}

/**
 * Commando's algemeen (hostId null) of per server zetten. Ruimer maken (uit → nobody → root) vraagt om je
 * wachtwoord; daarna terug naar wat erna moet gebeuren (then).
 */
async function setAgentCommands(hostId, mode, current, label, then) {
  const target = mode === 'global' ? null : mode;
  const widens = target !== null && CMD_RANK[target] > CMD_RANK[current];
  if (!widens) {
    try { await api('agent_commands_set', { host_id: hostId, mode }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); }
    return then();
  }
  modal({
    title: `🖥️ ${L('Commando\'s toestaan')} – ${label}`,
    body: `<p style="margin-top:0">${L('Hiermee mag RzMon commando\'s laten uitvoeren op {label}, <b>{mode}</b>.', { label: esc(label), mode: esc(CMD_MODES[target]) })}
        ${(hostId ? L('Iedereen die in RzMon beheerder is, kan dan commando\'s op deze server draaien{extra}.', { extra: target === 'root' ? L(', <b>met volledige rechten</b>') : '' })
          : L('Iedereen die in RzMon beheerder is, kan dan commando\'s op je agents draaien{extra}.', { extra: target === 'root' ? L(', <b>met volledige rechten</b>') : '' }))}</p>
      <p class="muted small">${L('Op een server zelf kun je het altijd blokkeren met <code>COMMANDS=0</code> (of <code>COMMANDS=nobody</code>) in de instellingen van de agent. Deze wijziging komt in het logboek.')}</p>
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: L('Toestaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      await api('agent_commands_set', { host_id: hostId, mode, confirm: d.confirm });
      toast(L('Opgeslagen'));
      setTimeout(then, 50);
    },
  });
}

async function loadAgentCommands() {
  let r;
  try { r = await api('agent_commands'); } catch (ex) { return; }
  const sel = $('#agent-commands');
  sel.value = r.global;
  sel.dataset.current = r.global;
  const own = r.hosts.filter((x) => x.own);
  $('#agent-commands-hosts').innerHTML = own.length ? L('Per server anders:') + ' ' + own.map((x) => `<b>${esc(x.name)}</b> ${esc(CMD_MODES[x.own])}`).join(' · ') : '';
  $('#audit-log').innerHTML = r.log.length ? r.log.map((l) => `<div class="key-row"><span class="grow small"><b>${esc(l.action)}</b>: ${esc(l.detail)}<br>
      <span class="muted">${esc(fmtDateTime(l.created_at))} · ${esc(l.username)} · ${esc(l.ip)}</span></span></div>`).join('') : `<p class="muted small">${L('Nog niets.')}</p>`;
  settingsSummaries();
}

/** Bevestiging: je wachtwoord, of op het LAN een woord typen (de server accepteert het Nederlandse en het vertaalde woord) */
function confirmField(word) {
  const lan = !!document.querySelector('.lan-badge');
  return `<label>${lan ? L('Typ <b>{word}</b> om te bevestigen', { word: L(word) }) : L('Bevestig met je eigen wachtwoord')}
    <input name="confirm" type="${lan ? 'text' : 'password'}" required autocomplete="${lan ? 'off' : 'current-password'}" autofocus></label>`;
}

function dbRotateDialog() {
  const info = dbInfo || {};
  const canClick = info.helper || info.direct;
  const terminal = `
    <details class="small api-docs" ${canClick ? '' : 'open'}>
      <summary>${L('Via de terminal (als root)')}</summary>
      <p class="muted small">${L('Dit doet hetzelfde als de knop, en werkt altijd:')}</p>
      ${copyBox('cmd-rotate', info.cli_cmd || '')}
      ${info.helper ? '' : `<p class="muted small" style="margin-top:.8rem">${L('Hulpdienst installeren, zodat de knop ook werkt als de RzMon-map vergrendeld is:')}</p>${copyBox('cmd-helper', info.helper_cmd || '')}`}
    </details>`;
  modal({
    title: '🔑 ' + L('Databasewachtwoord vernieuwen'),
    body: canClick ? `
      <p style="margin-top:0">${L('RzMon maakt een nieuw wachtwoord van 32 tekens en slaat het op in de database en in <code>config.php</code>{extra}.', { extra: info.helper ? ' ' + L('(en in {file})', { file: '/root/rzmon-install.txt' }) : '' })}
        ${L('Daarna test RzMon de verbinding; lukt dat niet, dan wordt het oude wachtwoord automatisch teruggezet.')}</p>
      <p class="muted small">${L('Je ziet het nieuwe wachtwoord <b>één keer</b>. RzMon zelf heeft het niet nodig van je; bewaar het alleen als je zelf met de database wilt werken.')}</p>
      ${confirmField('VERNIEUWEN')}
      ${terminal}`
      : `<p style="margin-top:0">${L('De RzMon-map is vergrendeld (veilig), en de <b>hulpdienst</b> die de wissel als root uitvoert is nog niet geïnstalleerd. Voer op de server één van deze commando\'s uit:')}</p>
        <p class="small">${L('<b>1. Hulpdienst installeren</b> (eenmalig; daarna werkt deze knop):')}</p>${copyBox('cmd-helper', info.helper_cmd || '')}
        <p class="small" style="margin-top:1rem">${L('<b>2. Of nu meteen vernieuwen</b> in de terminal:')}</p>${copyBox('cmd-rotate', info.cli_cmd || '')}`,
    buttons: canClick ? [{ label: '🔑 ' + L('Vernieuwen'), cls: 'primary', submit: true }] : [{ label: L('Sluiten'), cls: 'primary', submit: true }],
    onSubmit: canClick ? async (d) => {
      let r = await api('db_rotate', { confirm: d.confirm });
      for (let i = 0; r.pending && i < 40; i++) {   // hulpdienst: even wachten op het resultaat
        await new Promise((res) => setTimeout(res, 750));
        try { r = await api('db_rotate_status'); }
        catch (ex) { if (ex.status === 404 || ex.status === 504) throw ex; }   // tijdens de wissel kan één verzoek mislukken
      }
      if (r.pending) throw new Error(L('Geen antwoord van de hulpdienst'));
      setTimeout(() => showNewDbPassword(r), 50);
    } : null,
  });
  wireCopy($('#modal-body'));
}

function showNewDbPassword(r) {
  modal({
    title: '✔ ' + L('Nieuw databasewachtwoord'),
    body: `<p style="margin-top:0">${L('Opgeslagen en getest; RzMon werkt gewoon door. Dit wachtwoord wordt <b>niet opnieuw getoond</b>.')}</p>
      ${copyBox('new-db-pass', r.password)}
      ${(r.warnings || []).map((w) => `<p class="callout small">⚠️ ${esc(w)}</p>`).join('')}`,
    buttons: [{ label: L('Klaar'), cls: 'primary', submit: true }],
  });
  wireCopy($('#modal-body'));
  loadDbInfo();
}

// ---------------------------------------------------------------- RzMon bijwerken
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
    parts.push(`<br><b class="warn-txt">${L('Bijwerken vanuit RzMon kan nog niet:')}</b> ${esc(u.unavailable)}. ${L('Voer één keer uit op de server:')}`
      + copyBox('cmd-update-setup', 'curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh && sudo bash install-rocky.sh'));
  }
  st.innerHTML = parts.join(' ');
  $('#update-notes').innerHTML = u.available && u.notes.length ? `<details class="update-notes" open><summary>${u.notes.length === 1 ? L('Wat is er nieuw (1 versie)') : L('Wat is er nieuw ({n} versies)', { n: u.notes.length })}</summary>
    ${u.notes.map((n) => `<div class="un-ver"><h3>${esc(n.version)} <span class="muted small">${esc(n.date)}</span></h3>${mdLite(n.text)}</div>`).join('')}</details>` : '';
  const b = $('#update-start');
  b.hidden = !u.available || !!u.unavailable;
  b.textContent = `⬆ ${L('Nu bijwerken naar {v}', { v: u.latest })}`;
  updateInfo = u;
  settingsSummaries();
  showUpdateProgress(u.status, '');
  if (u.status && ['queued', 'running'].includes(u.status.state)) pollUpdate();
}

const UPDATE_STEPS = [['check', L('Controleren')], ['backup', L('Back-up maken')], ['download', L('Nieuwste versie ophalen')], ['install', L('Installeren')], ['klaar', L('Klaar')]];

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

let updateFails = 0;   // opeenvolgende keren geen antwoord (RzMon wordt herstart)
let updatePollAt = 0;  // laatste vraag die een antwoord (of fout) kreeg; voor de waakhond hieronder
function pollUpdate() {
  clearTimeout(updatePoll);
  updatePoll = setTimeout(async () => {
    let r;
    // Hooguit 10 seconden wachten: tijdens het herstarten van PHP of Apache kan een verzoek anders blijven hangen
    try { r = await Promise.race([api('update_status'), new Promise((res, rej) => setTimeout(() => rej(new Error('timeout')), 10000))]); updateFails = 0; }
    catch (ex) {   // even onbereikbaar tijdens het installeren
      updatePollAt = Date.now();
      updateFails++;
      if (updateDialogShown()) $('#upd-now').innerHTML = '🔄 ' + L('Even geduld, RzMon wordt herstart…');
      pollUpdate();
      return;
    }
    updatePollAt = Date.now();
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
// Alleen het venster van de RzMon-update; dat van pakketten bijwerken (Kwetsbaarheden) heeft dezelfde opmaak, maar is vu-dlg
const updateDialogShown = () => $('#modal').open && !!$('#modal-body .upd-dlg:not(.vu-dlg)');
let updatePct = 0;   // de balk gaat tijdens één update nooit terug

/** Vraag "wil je bijwerken?" (met back-up), of meteen de voortgang als er al een update loopt. */
async function openUpdateDialog() {
  let u = updateInfo;
  try { u = await api('update_info'); updateInfo = u; } catch (ex) { if (!u) { toast(ex.message, 'err'); return; } }
  if (u.status && ['queued', 'running'].includes(u.status.state)) { showUpdateDialog(u.status, '', true); pollUpdate(); return; }
  if (!u.available || u.unavailable) {
    // Niets bij te werken, of het kan (nog) niet vanuit RzMon: de uitleg staat bij Instellingen
    showTab('settings/update');
    return;
  }
  modal({
    title: '⬆ ' + L('RzMon bijwerken'),
    body: `<p style="margin-top:0">${L('RzMon bijwerken van <b>{from}</b> naar <b>{to}</b>?', { from: esc(u.current), to: esc(u.latest) })}</p>
      ${u.notes.length ? `<details class="update-notes upd-notes"><summary>${u.notes.length === 1 ? L('Wat is er nieuw (1 versie)') : L('Wat is er nieuw ({n} versies)', { n: u.notes.length })}</summary>
        ${u.notes.map((n) => `<div class="un-ver"><h3>${esc(n.version)} <span class="muted small">${esc(n.date)}</span></h3>${mdLite(n.text)}</div>`).join('')}</details>` : ''}
      <label class="switch"><input type="checkbox" name="backup" ${u.backup ? 'checked' : ''}><span></span> ${L('Eerst een back-up maken')}</label>
      <p class="muted small">${L('RzMon is tijdens het installeren even niet bereikbaar. Dit venster laat zien hoe ver de installatie is.')}</p>`,
    buttons: [
      { label: L('Annuleren'), onClick: () => $('#modal').close() },
      { label: '⬆ ' + L('Bijwerken'), cls: 'primary', onClick: async (btn) => {
        const backup = $('#modal-form').backup.checked;
        btn.disabled = true;
        try {
          await api('update_start', { backup });
          if ($('#settings-form')) $('#settings-form').update_backup.checked = backup;   // keuze is opgeslagen
          updatePct = 0;
          const s = { state: 'queued', step: 'queued', message: L('Wachten op de hulpdienst…'), at: Date.now() / 1000 };
          showUpdateDialog(s, '', true);
          showUpdateProgress(s, '');
          pollUpdate();
        } catch (ex) { modalError(ex.message); btn.disabled = false; }
      } },
    ],
  });
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
  const pct = updatePercent(s);
  const cur = UPDATE_STEPS.findIndex(([k]) => k === s.step);
  const icon = (i) => (s.state === 'ok' || i < cur ? '✔' : i === cur ? (s.state === 'error' ? '✘' : '⏳') : '·');
  // Waar de installatie nu mee bezig is: de stap, en bij Installeren het onderdeel van het installatiescript
  const part = (s.detail || '').match(/^(\d+\/\d+) (.*)$/);
  const step = s.state === 'queued' ? L('Wachten op de hulpdienst…') : cur >= 0 ? UPDATE_STEPS[cur][1] : esc(s.message || '');
  const now = s.state === 'ok' ? '✔ ' + L('Bijgewerkt naar {v}. De pagina wordt zo herladen…', { v: esc(s.to || '') })
    : s.state === 'error' ? '✘ ' + esc(s.message || L('Bijwerken mislukt'))
    : '⏳ ' + step + (part ? `: ${esc(L(part[2]))} <span class="muted">(${part[1]})</span>` : '');
  const tail = (log || '').trim().split('\n').slice(-8).join('\n');
  modal({
    title: '⬆ ' + L('RzMon bijwerken') + (s.from && s.to ? ` ${esc(s.from)} → ${esc(s.to)}` : ''),
    body: `<div class="upd-dlg ${s.state}">
      <div class="upd-top"><b>${s.state === 'ok' ? L('Klaar') : s.state === 'error' ? L('Mislukt') : L('Bezig met bijwerken…')}</b><b>${pct}%</b></div>
      <div class="upd-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><i style="width:${pct}%"></i></div>
      <ol class="update-steps">${UPDATE_STEPS.map(([k, l], i) => `<li class="${i === cur ? 'cur' : ''}">${icon(i)} ${l}</li>`).join('')}</ol>
      <p class="upd-now" id="upd-now">${now}</p>
      ${s.state === 'queued' && s.stuck ? `<p class="warn-txt small">${L('De hulpdienst reageert niet. Controleer op de server:')} <code>systemctl status rzmon-update.path</code></p>` : ''}
      ${s.stale ? `<p class="small">${L('Controleer op de server:')} <code>systemctl status rzmon-update.service</code> ${L('en')} <code>tail -30 /var/lib/rzmon/update.log</code></p>` : ''}
      ${tail ? `<pre class="update-log upd-log">${esc(tail)}</pre>` : ''}
    </div>`,
    buttons: s.state === 'ok' ? []
      : s.state === 'error' ? [{ label: L('Sluiten'), cls: 'primary', onClick: () => $('#modal').close() }]
      : [{ label: L('Op de achtergrond'), onClick: () => $('#modal').close() }],
  });
  const lg = $('#modal-body .upd-log');
  if (lg) lg.scrollTop = lg.scrollHeight;
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
  if (b.last_error) parts.push(`<b class="bad">✘ ${L('Automatische back-up mislukt: {err}', { err: esc(b.last_error) })}</b> ${L('(RzMon probeert het elk uur opnieuw)')}`);
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
        <li>${L('RzMon maakt eerst een <b>veiligheidsback-up</b> van nu. Daarmee kun je altijd terug.')}</li>
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

// ---------------------------------------------------------------- Kwetsbaarheden
const VULN_SEV = [['⚪', L('onbekend')], ['🔵', 'low'], ['🟡', 'medium'], ['🟠', 'high'], ['🔴', 'critical']];
let vulnData = null;
let vulnFilter = 'all';
const vulnOpen = new Set();

function initVulns() {
  $('#vuln-search').oninput = () => renderVulns();
  $('#vuln-sev').onchange = () => renderVulns();
  $$('#vuln-filter .seg-btn').forEach((b) => (b.onclick = () => {
    vulnFilter = b.dataset.vf;
    $$('#vuln-filter .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    renderVulns();
  }));
  $('#vuln-scan').onclick = (e) => vulnScan(e.currentTarget);
  $('#vuln-hosts').addEventListener('click', vulnClick);
}

async function loadVulns() {
  try {
    vulnData = await api('vulns');
    keepOpen($('#vuln-hosts'), renderVulns);
  } catch (ex) { $('#vuln-hosts').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

async function vulnScan(btn) {
  btn.disabled = true;
  try {
    const r = await api('vuln_scan', {});
    toast(r.message || (r.pending ? L('Scan gestart: nog {n} server(s) te gaan, de rest volgt binnen een paar minuten', { n: r.pending }) : L('Scan klaar')));
    loadVulns();
  } catch (ex) { toast(ex.message, 'err'); }
  btn.disabled = false;
}

/** Bevindingen die nu zichtbaar zijn (zoeken, filter, ernst; minimum uit de instellingen) */
function vulnVisible() {
  const q = $('#vuln-search').value.trim().toLowerCase();
  const sev = Math.max(+$('#vuln-sev').value || 0, vulnData.min_severity || 0);
  const hostName = Object.fromEntries(vulnData.hosts.map((h) => [h.id, h.name.toLowerCase()]));
  const exposed = Object.fromEntries(vulnData.hosts.map((h) => [h.id, h.exposed || {}]));
  return vulnData.findings.filter((f) => {
    if (vulnFilter === 'ignored' ? !f.ignored : f.ignored) return false;
    if (f.severity < sev && !(sev && f.kev)) return false;
    if (vulnFilter === 'exposed' && !exposed[f.host_id][f.package]) return false;
    if (vulnFilter === 'fix' && !f.fixed) return false;
    if (vulnFilter === 'kev' && !f.kev) return false;
    return !q || f.package.toLowerCase().includes(q) || f.cve.toLowerCase().includes(q) || f.vuln_id.toLowerCase().includes(q)
      || (f.binaries || '').toLowerCase().includes(q) || hostName[f.host_id].includes(q) || (f.summary || '').toLowerCase().includes(q);
  });
}

function vulnChip(f) {
  const [icon] = VULN_SEV[f.severity] || VULN_SEV[0];
  return `<button type="button" class="cve-chip sev${f.severity}${f.kev ? ' kev' : ''}" data-cve="${esc(f.cve)}"
    title="${esc((f.summary || f.vuln_id) + (f.cvss ? ` · CVSS ${f.cvss}` : '') + (f.kev ? ' · ' + L('actief misbruikt') : ''))}">${icon} ${esc(f.cve)}${f.kev ? ' ⚠️' : ''}</button>`;
}

function renderVulns() {
  if (!vulnData) return;
  const d = vulnData;
  const list = vulnVisible();
  // Tellers per ernst (unieke CVE's per server), zonder geaccepteerde
  const live = d.findings.filter((f) => !f.ignored);
  const uniq = (fs) => new Set(fs.map((f) => f.host_id + '|' + f.cve));
  const n = [0, 1, 2, 3, 4].map((s) => uniq(live.filter((f) => f.severity === s)).size);
  const kev = uniq(live.filter((f) => f.kev)).size;
  $('#vuln-counts').innerHTML = [[4, n[4]], [3, n[3]], [2, n[2]], [1, n[1]], [0, n[0]]].filter(([s, c]) => c || s >= 2)
    .map(([s, c]) => `<span class="vc sev${s}">${VULN_SEV[s][0]} ${c} ${VULN_SEV[s][1]}</span>`).join('')
    + (kev ? `<span class="vc kev">⚠️ ${L('{n} actief misbruikt', { n: kev })}</span>` : '');
  const scanned = d.hosts.filter((h) => h.scanned_at && !h.error);
  const failed = d.hosts.filter((h) => h.error).length;
  $('#vuln-info').textContent = d.hosts.length
    ? L('{n} van {m} server(s) gescand', { n: scanned.length, m: d.hosts.length }) + (failed ? ' · ⚠️ ' + L('{n} met een fout', { n: failed }) : '')
      + (d.last_scan ? ' · ' + L('laatste scan {t}', { t: ago(d.last_scan) }) : '') + ' · ' + L('dagelijks om {t}', { t: d.scan_time })
    : '';
  $('#vuln-badge').hidden = !(n[4] + kev);
  $('#vuln-badge').textContent = n[4] + kev || '';
  if (!d.hosts.length) {
    $('#vuln-hosts').innerHTML = `<div class="card"><p class="muted">${L('Nog geen softwarelijsten. Werk de agents bij naar {v} of nieuwer: dan sturen ze hun pakketten mee.', { v: '1.47.0' })}</p></div>`;
    return;
  }
  const byHost = {};
  list.forEach((f) => (byHost[f.host_id] ||= []).push(f));
  $('#vuln-hosts').innerHTML = d.hosts.map((h) => vulnHostCard(h, byHost[h.id] || [])).join('');
}

function vulnHostCard(h, fs) {
  // Per pakket: alle CVE's, ernstigste eerst; pakketten met de ergste en bereikbare bovenaan
  const pk = {};
  fs.forEach((f) => (pk[f.package] ||= { name: f.package, version: f.version, fixed: f.fixed, binaries: f.binaries, list: [] }).list.push(f));
  const rank = (p) => Math.max(...p.list.map((f) => f.severity * 2 + (f.kev ? 10 : 0))) + (h.exposed[p.name] ? 1 : 0);
  const pkgs = Object.values(pk).sort((a, b) => rank(b) - rank(a) || a.name.localeCompare(b.name));
  pkgs.forEach((p) => {
    p.list.sort((a, b) => (b.kev - a.kev) || (b.severity - a.severity) || (b.cvss || 0) - (a.cvss || 0));
    p.fixed = p.list.map((f) => f.fixed).filter(Boolean).sort().pop() || null;
  });
  const cnt = [4, 3, 2, 1].map((s) => [s, new Set(fs.filter((f) => f.severity === s).map((f) => f.cve)).size]).filter(([, c]) => c);
  const open = vulnOpen.has(h.id) || (vulnOpen.size === 0 && fs.some((f) => f.severity >= 3 || f.kev));
  const up = h.upgrade;
  const upTxt = !up ? '' : up.state === 'queued' || up.state === 'running'
    ? `<a href="#" data-vact="progress" data-host="${h.id}" title="${L('Voortgang tonen')}">⏳ ${up.state === 'queued' ? L('Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport')
      : L('Bezig met bijwerken') + (up.pct ? ` ${up.pct}%` : '')}</a>`
    : up.state === 'ok' ? `<span class="good-txt">✔ ${L('Bijgewerkt {t}', { t: ago(up.at) })}: ${esc((up.pkgs || []).join(' '))}</span>`
    : `<span class="bad">✘ ${L('Bijwerken mislukt {t}', { t: ago(up.at) })}</span>`;
  const notes = [
    h.error ? `<span class="warn-txt">⚠️ ${esc(h.error)}</span>` : '',   // ook in de kop; hier volledig
    h.agent_old ? `<span class="warn-txt">⬆ ${L('Agent {v} of nieuwer nodig voor de softwarelijst', { v: '1.47.0' })}</span>` : '',
    h.reboot ? `<span class="warn-txt" title="${esc(L('Draait: {a} · geïnstalleerd: {b}', { a: h.kernel, b: h.reboot }))}">↻ ${L('Herstart nodig voor de nieuwe kernel')}</span>` : '',
    h.restart && h.restart.length ? `<span class="warn-txt" title="${esc(h.restart.join(', '))}">↻ ${L('{n} service(s) draaien nog met oude bestanden', { n: h.restart.length })}</span>` : '',
    upTxt,
  ].filter(Boolean).join(' · ');
  const fixable = pkgs.filter((p) => p.fixed).map((p) => p.name);
  return `<details class="card vuln-host" data-host="${h.id}" ${open ? 'open' : ''}>
    <summary><span class="vh-name"><b>${esc(h.name)}</b> <span class="muted small">${esc(h.os || '')}${h.packages ? ' · ' + L('{n} pakketten', { n: h.packages }) : ''}${h.scanned_at ? ' · ' + L('gescand {t}', { t: ago(h.scanned_at) }) : ''}</span></span>
      <span class="vh-counts">${h.error ? `<span class="warn-txt small" title="${esc(h.error)}">⚠️ ${esc(h.error.length > 70 ? h.error.slice(0, 70) + '…' : h.error)}</span>` : ''}${cnt.length ? cnt.map(([s, c]) => `<span class="vc sev${s}">${VULN_SEV[s][0]} ${c}</span>`).join('') : (h.scanned_at && !h.error ? `<span class="good-txt">✔ ${L('geen bekende kwetsbaarheden')}</span>` : '')}</span></summary>
    ${notes ? `<p class="small vh-notes">${notes}${up && up.log ? ` <details class="small"><summary>${L('Uitvoer')}</summary><pre class="code">${esc(up.log)}</pre></details>` : ''}</p>` : ''}
    <div class="vh-actions">
      ${fixable.length ? `<button type="button" class="btn need-admin" data-vact="upgrade-all" data-host="${h.id}">⬆ ${L('Alle beveiligingsupdates installeren ({n})', { n: fixable.length })}</button>` : ''}
      ${h.packages ? `<button type="button" class="btn" data-vact="software" data-host="${h.id}">📦 ${L('Alle software')}</button>` : ''}
    </div>
    ${pkgs.length ? `<div class="table-scroll"><table class="vuln-table">
      <thead><tr><th>${L('Pakket')}</th><th>${L('Geïnstalleerd')}</th><th>${L('Opgelost in')}</th><th>CVE's</th><th></th></tr></thead>
      <tbody>${pkgs.map((p) => `<tr>
        <td><b>${esc(p.name)}</b>${h.exposed[p.name] ? ` <span class="exp-tag" title="${esc(L('Luistert op het netwerk: {p}', { p: h.exposed[p.name].join(', ') }))}">🌐 ${esc(h.exposed[p.name].join(', '))}</span>` : ''}
          ${p.binaries && p.binaries !== p.name ? `<div class="muted small" title="${esc(p.binaries)}">${esc(p.binaries.length > 60 ? p.binaries.slice(0, 60) + '…' : p.binaries)}</div>` : ''}</td>
        <td class="mono small">${esc(p.version)}</td>
        <td class="mono small">${p.fixed ? esc(p.fixed) : `<span class="muted">${L('nog geen oplossing')}</span>`}</td>
        <td class="cve-cell">${p.list.slice(0, 6).map(vulnChip).join(' ')}${p.list.length > 6 ? ` <button type="button" class="cve-more" data-vact="more" data-host="${h.id}" data-pkg="${esc(p.name)}">+${p.list.length - 6}</button>` : ''}</td>
        <td>${p.fixed ? `<button type="button" class="icon-btn need-admin" title="${esc(L('Bijwerken'))}" data-vact="upgrade" data-host="${h.id}" data-pkg="${esc(p.name)}">⬆</button>` : ''}</td>
      </tr>`).join('')}</tbody></table></div>` : (h.scanned_at ? `<p class="muted small">${vulnFilter === 'all' && !$('#vuln-search').value ? L('Geen kwetsbaarheden gevonden.') : L('Niets met dit filter.')}</p>` : `<p class="muted small">${L('Nog niet gescand.')}</p>`)}
  </details>`;
}

function vulnClick(e) {
  const det = e.target.closest('details.vuln-host');
  if (e.target.closest('summary') && det) {   // open/dicht onthouden
    setTimeout(() => { const id = +det.dataset.host; if (det.open) vulnOpen.add(id); else vulnOpen.delete(id); }, 0);
    return;
  }
  const chip = e.target.closest('[data-cve]');
  if (chip) return vulnDetail(chip.dataset.cve);
  const b = e.target.closest('[data-vact]');
  if (!b) return;
  const h = vulnData.hosts.find((x) => x.id === +b.dataset.host);
  if (b.dataset.vact === 'upgrade') return vulnUpgrade(h, [b.dataset.pkg]);
  if (b.dataset.vact === 'upgrade-all') return vulnUpgrade(h, null);
  if (b.dataset.vact === 'software') return vulnSoftware(h);
  if (b.dataset.vact === 'progress') return vulnUpgradeDialog(h, true);
  if (b.dataset.vact === 'more') {
    const fs = vulnVisible().filter((f) => f.host_id === h.id && f.package === b.dataset.pkg);
    b.parentElement.innerHTML = fs.map(vulnChip).join(' ');
  }
}

/** CVSS-vector in gewone woorden: hoe is hij te misbruiken? */
function cvssWords(v) {
  if (!v) return '';
  const m = Object.fromEntries(v.split('/').map((p) => p.split(':')));
  return [
    { N: L('via het netwerk'), A: L('vanaf het lokale netwerk'), L: L('alleen lokaal op de server'), P: L('alleen met fysieke toegang') }[m.AV],
    { N: L('zonder in te loggen'), L: L('met een gewoon account'), H: L('met beheerdersrechten') }[m.PR],
    { N: L('zonder dat een gebruiker iets doet'), R: L('een gebruiker moet iets doen (bv. een link openen)'), P: L('een gebruiker moet iets doen') }[m.UI],
    m.AC === 'H' ? L('moeilijk uit te voeren') : null,
  ].filter(Boolean).join(' · ');
}

async function vulnDetail(cve) {
  let d;
  try { d = await api('vuln_detail', null, { cve }); } catch (ex) { return toast(ex.message, 'err'); }
  const [icon, word] = VULN_SEV[d.severity] || VULN_SEV[0];
  const rows = [
    [L('Ernst'), `${icon} <b>${esc(word)}</b>${d.cvss !== null ? ` · CVSS <b>${dec(d.cvss)}</b>` : ''}${d.vector ? `<div class="muted small">${esc(cvssWords(d.vector))}</div><div class="mono small muted">${esc(d.vector)}</div>` : ''}`],
    [L('Actief misbruikt'), d.kev ? `<b class="bad">⚠️ ${L('ja')}</b>${d.kev_added ? ' · ' + L('op de lijst van CISA sinds {t}', { t: esc(d.kev_added) }) : ''}` : L('niet bekend')],
    [L('Kans op misbruik'), d.epss !== null ? L('{p} in de komende 30 dagen (EPSS)', { p: dec((d.epss * 100).toFixed(d.epss < 0.01 ? 2 : 1)) + '%' }) : '–'],
    [L('Gepubliceerd'), d.published ? esc(fmtDateTime(d.published.slice(0, 19).replace('T', ' '))) : '–'],
    ...(d.aliases.length ? [[L('Ook bekend als'), d.aliases.map(esc).join(', ')]] : []),
  ];
  const hostRows = d.hosts.map((x, i) => `<div class="vd-host">
      <div><b>${esc(x.host)}</b> · <span class="mono small">${esc(x.package)} ${esc(x.version)}</span> → ${x.fixed ? `<span class="mono small good-txt">${esc(x.fixed)}</span>` : `<span class="muted">${L('nog geen oplossing')}</span>`}
        ${x.ignored ? ` · <span class="muted">${L('geaccepteerd')}</span>` : ''}</div>
      ${x.command ? copyBox('vd-cmd-' + i, x.command) : ''}
      ${x.fixed ? (x.upgrade_blocked ? `<p class="muted small">${L('Bijwerken via de agent kan hier niet: {why}. Voer het commando hierboven zelf uit op de server.', { why: esc(x.upgrade_blocked) })}</p>`
        : `<button type="button" class="btn need-admin" data-vd-up="${i}">⬆ ${L('Bijwerken via de agent')}</button>`) : ''}
    </div>`).join('');
  const { form } = modal({
    title: `${icon} ${cve}`, wide: true,
    body: `<div style="padding:1rem 1.2rem">
      ${d.summary ? `<p><b>${esc(d.summary)}</b></p>` : ''}
      ${d.details ? `<details class="vd-details" ${d.details.length < 600 ? 'open' : ''}><summary>${L('Omschrijving')}</summary><p class="small" style="white-space:pre-wrap">${esc(d.details)}</p></details>` : ''}
      <table class="kv">${rows.map(([k, v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('')}</table>
      <h3>${L('Zo los je het op')}</h3>
      <p class="muted small">${L('Installeer de nieuwe versie uit de eigen pakketbron van de distributie (zoals hieronder); een download bij de maker is daarvoor niet nodig. Bij de kernel of een bibliotheek is daarna een herstart van de server of van de services nodig.')}</p>
      ${hostRows}
      <h3>${L('Meer informatie en adviezen')}</h3>
      <ul class="vd-links">${d.links.map((l) => `<li><a href="${esc(l.url)}" target="_blank" rel="noopener noreferrer">${esc(l.label)}</a> <span class="muted small">${esc(l.url.replace(/^https?:\/\//, '').slice(0, 70))}</span></li>`).join('')}</ul>
    </div>`,
    buttons: [
      { label: '✔ ' + L('Risico accepteren…'), cls: 'need-user', style: 'margin-right:auto', onClick: () => vulnIgnore(d) },
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  wireCopy(form);
  $$('[data-vd-up]', form).forEach((b) => (b.onclick = () => {
    const x = d.hosts[+b.dataset.vdUp];
    vulnUpgrade(vulnData.hosts.find((h) => h.id === x.host_id) || { id: x.host_id, name: x.host }, [x.package]);
  }));
}

function vulnIgnore(d) {
  modal({
    title: L('Risico accepteren: {cve}', { cve: d.cve }),
    body: `<div style="padding:1rem 1.2rem">
      <p class="muted small">${L('Een geaccepteerde kwetsbaarheid telt niet meer mee en geeft geen melding. Je ziet hem terug onder het filter Geaccepteerd.')}</p>
      <label>${L('Waarvoor')}<select name="scope">
        <option value="all">${L('Alle servers')}</option>
        ${d.hosts.map((x, i) => `<option value="${i}">${esc(x.host)} · ${esc(x.package)}</option>`).join('')}
      </select></label>
      <label>${L('Reden')} <span class="hint">${L('(bv. niet bereikbaar van buitenaf)')}</span><input name="reason" maxlength="300"></label>
    </div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✔ ' + L('Accepteren'), cls: 'primary', submit: true }],
    onSubmit: async (v) => {
      const x = v.scope === 'all' ? null : d.hosts[+v.scope];
      await api('vuln_ignore', { cve: d.cve, reason: v.reason, host_id: x ? x.host_id : null, package: x ? x.package : null });
      toast(L('Geaccepteerd'));
      loadVulns();
    },
  });
}

/** Bijwerken via de agent (beheerders); kan dat niet, dan het commando om zelf uit te voeren */
function vulnUpgrade(h, pkgs) {
  const all = pkgs === null;
  modal({
    title: all ? L('Alle beveiligingsupdates installeren op {name}', { name: h.name }) : L('Bijwerken op {name}', { name: h.name }),
    body: `<div style="padding:1rem 1.2rem">
      <p>${all ? L('De agent installeert de nieuwe versie van alle pakketten waarvoor een oplossing is.') : L('De agent installeert de nieuwe versie van: {p}', { p: `<b>${esc(pkgs.join(' '))}</b>` })}</p>
      <p class="muted small">${L('Dit gebeurt bij het volgende rapport van de agent, met de pakketbeheerder van de server (dnf of apt), en komt in het logboek. Er wordt niets herstart; is dat nodig, dan zie je ↻ bij de server.')}</p>
      ${h.upgrade_blocked ? `<p class="warn-txt">${L('Bijwerken via de agent kan hier niet: {why}.', { why: esc(h.upgrade_blocked) })}</p>` : confirmField('BIJWERKEN')}
      <div id="vu-cmd"></div>
    </div>`,
    buttons: h.upgrade_blocked ? [{ label: L('Commando tonen'), cls: 'primary', submit: true }] : [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⬆ ' + L('Bijwerken'), cls: 'primary', submit: true }],
    onSubmit: async (v, form) => {
      try {
        const res = await api('vuln_upgrade', { host_id: h.id, all, packages: pkgs || [], confirm: v.confirm });
        // In hetzelfde venster meteen de voortgang tonen (eerst "wachten op de agent"), niet eerst sluiten
        vulnUpgradeDialog(h, true, { state: 'queued', pkgs: res.packages || pkgs || [] });
        loadVulns();
        return 'keep';
      } catch (ex) {
        // Kan het niet via de agent? Dan het commando tonen om zelf uit te voeren
        if (ex.data && ex.data.command) {
          $('#vu-cmd', form).innerHTML = `<p class="muted small">${L('Voer dit zelf uit op de server:')}</p>${copyBox('vu-cmd-line', ex.data.command)}`;
          wireCopy(form);
          throw new Error(ex.message);
        }
        throw ex;
      }
    },
  });
}

// ---------------------------------------------------------------- Bijwerken via de agent: voortgang
// Agent 1.48.0+ meldt elke paar seconden de stap, het percentage en het pakket; oudere agents alleen het begin en de uitkomst.
const VULN_UP_STEPS = [['queued', L('Wachten op de agent')], ['update', L('Pakketlijst ophalen')], ['download', L('Downloaden')], ['install', L('Installeren')], ['done', L('Klaar')]];
let vulnUpPoll = null;
const vulnUpShown = () => $('#modal').open && !!$('#modal-body .vu-dlg');

/** Venster met de voortgang van het bijwerken op server h; force = openen, ook als er een ander venster openstaat. */
async function vulnUpgradeDialog(h, force, first) {
  clearTimeout(vulnUpPoll);
  let r;
  if (first) r = { status: first, live: true };   // meteen iets tonen; de echte stand volgt hieronder
  else {
    try { r = await api('vuln_upgrade_status', null, { host_id: h.id }); }
    catch (ex) {
      // Even geen antwoord: het venster blijft staan en vraagt het zo opnieuw
      if (force && !vulnUpShown()) r = { status: { state: 'queued', pkgs: [] }, live: true };
      else { if (vulnUpShown()) vulnUpPoll = setTimeout(() => vulnUpgradeDialog(h), 3000); return; }
    }
  }
  if (!force && !vulnUpShown()) return;   // venster gesloten: stoppen
  const st = r.status || { state: 'queued', pkgs: [] };
  const busy = ['queued', 'running'].includes(st.state);
  const step = st.state === 'queued' ? 'queued' : st.state === 'ok' || st.state === 'error' ? 'done'
    : st.stage === 'verify' ? 'install' : st.stage || 'update';
  const cur = VULN_UP_STEPS.findIndex(([k]) => k === step);
  const icon = (i) => (st.state === 'ok' || i < cur ? '✔' : i === cur ? (st.state === 'error' ? '✘' : '⏳') : '·');
  // Zonder tussenstand (oude agent, of nog niets gehoord): een bewegende balk zonder percentage
  const known = st.state === 'ok' || st.state === 'error' || typeof st.pct === 'number';
  const pct = st.state === 'ok' ? 100 : st.state === 'queued' ? 2 : known ? st.pct : 100;
  const since = st.sent ? ' · ' + L('gestart {t}', { t: ago(st.sent) }) : '';
  const rescanned = st.state === 'ok' && r.scanned_at && r.scanned_at >= st.at;
  const label = VULN_UP_STEPS[cur][1];
  const now = st.state === 'queued' ? '⏳ ' + L('Wachten op de agent: hij pakt de opdracht op bij zijn volgende rapport.')
    : st.state === 'ok' ? '✔ ' + L('Bijgewerkt: {p}', { p: esc((st.pkgs || []).join(' ')) })
    : st.state === 'error' ? '✘ ' + L('Bijwerken mislukt')
    : known ? `⏳ ${st.stage === 'verify' ? L('Controleren') : label}${st.detail ? `: <b>${esc(st.detail)}</b>` : ''}${st.count ? ` <span class="muted">(${esc(st.count)})</span>` : ''}`
    : '⏳ ' + L('Bezig met bijwerken') + since;
  const after = st.state !== 'ok' ? ''
    : rescanned ? `<p class="good-txt small">🔍 ${L('Opnieuw gescand: de lijst met kwetsbaarheden is bijgewerkt.')}</p>`
    : `<p class="muted small">🔍 ${L('RzMon scant opnieuw zodra de agent de nieuwe softwarelijst stuurt (meestal binnen een paar minuten).')}</p>`;
  const tail = (st.log || '').trim().split('\n').slice(-8).join('\n');
  modal({
    title: '⬆ ' + L('Bijwerken op {name}', { name: h.name }),
    body: `<div class="upd-dlg vu-dlg ${st.state === 'ok' ? 'ok' : st.state === 'error' ? 'error' : ''}">
      <div class="upd-top"><b>${st.state === 'ok' ? L('Klaar') : st.state === 'error' ? L('Mislukt') : L('Bezig met bijwerken…')}</b><b>${known || st.state === 'queued' ? pct + '%' : ''}</b></div>
      <div class="upd-bar ${known || st.state === 'queued' ? '' : 'indet'}" role="progressbar" aria-valuemin="0" aria-valuemax="100" ${known ? `aria-valuenow="${pct}"` : ''}><i style="width:${pct}%"></i></div>
      <ol class="update-steps">${VULN_UP_STEPS.map(([k, l], i) => `<li class="${i === cur ? 'cur' : ''}">${icon(i)} ${l}</li>`).join('')}</ol>
      <p class="upd-now">${now}</p>
      ${busy && st.state === 'running' && !r.live ? `<p class="muted small">${L('Deze agent meldt geen tussenstand. Werk hem bij naar {v} voor een voortgangsbalk.', { v: '1.48.0' })}</p>` : ''}
      ${after}
      <p class="muted small">${L('Pakketten')}: <span class="mono">${esc((st.pkgs || []).join(' '))}</span></p>
      ${tail ? `<pre class="update-log upd-log">${esc(tail)}</pre>` : ''}
    </div>`,
    buttons: busy ? [{ label: L('Op de achtergrond'), onClick: () => { $('#modal').close(); loadVulns(); } }]
      : [{ label: L('Sluiten'), cls: 'primary', onClick: () => { $('#modal').close(); loadVulns(); } }],
  });
  const lg = $('#modal-body .upd-log');
  if (lg) lg.scrollTop = lg.scrollHeight;
  // Doorvragen tot klaar, en daarna tot de nieuwe scan er is (hooguit 15 minuten)
  if (busy || (st.state === 'ok' && !rescanned && Date.now() - new Date(st.at.replace(' ', 'T')) < 900000)) {
    vulnUpPoll = setTimeout(() => vulnUpgradeDialog(h), busy ? 2000 : 5000);
  } else if (!$('#tab-vulns').hidden) loadVulns();
}

async function vulnSoftware(h) {
  let r;
  try { r = await api('vuln_packages', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const { form } = modal({
    title: L('Alle software op {name}', { name: h.name }), wide: true,
    body: `<div style="padding:1rem 1.2rem">
      <p class="muted small">${esc(h.os || '')} · ${L('{n} pakketten', { n: r.packages.length })}</p>
      <input type="search" id="vs-search" placeholder="${L('Zoek pakket…')}" autocomplete="off">
      <div class="table-scroll" style="max-height:55vh"><table class="vuln-table" id="vs-table"></table></div>
    </div>`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  const draw = () => {
    const q = $('#vs-search', form).value.trim().toLowerCase();
    const list = r.packages.filter((p) => !q || p.name.toLowerCase().includes(q) || p.source.toLowerCase().includes(q)).slice(0, 2000);
    $('#vs-table', form).innerHTML = `<thead><tr><th>${L('Pakket')}</th><th>${L('Versie')}</th><th>${L('Architectuur')}</th><th>${L('Bron')}</th><th></th></tr></thead><tbody>
      ${list.map((p) => `<tr><td>${esc(p.name)}</td><td class="mono small">${esc(p.version)}</td><td class="small">${esc(p.arch)}</td><td class="small muted">${p.source !== p.name ? esc(p.source) : ''}</td>
        <td>${p.vulns ? `<span class="vc sev3">🐞 ${p.vulns}</span>` : ''}</td></tr>`).join('')}</tbody>`;
  };
  $('#vs-search', form).oninput = draw;
  draw();
}

/** Instellingen: welke servers niet gescand worden (vinkjes → verborgen veld vuln_exclude) */
function vulnExcludeEditor() {
  const f = $('#settings-form');
  const el = $('#vuln-exclude-list');
  if (!f || !el || !f.vuln_exclude) return;
  const ex = new Set(String(f.vuln_exclude.value || '').split(',').filter(Boolean).map(Number));
  const hosts = state.hosts.filter((h) => h.mode !== 'remote');
  el.innerHTML = hosts.length ? hosts.map((h) => `<label class="chk-inline"><input type="checkbox" data-vex="${h.id}" ${ex.has(+h.id) ? 'checked' : ''}> ${esc(h.name)}</label>`).join(' ')
    : `<span class="muted small">${L('geen servers met een agent')}</span>`;
  $$('[data-vex]', el).forEach((c) => (c.onchange = () => {
    f.vuln_exclude.value = $$('[data-vex]', el).filter((x) => x.checked).map((x) => x.dataset.vex).join(',');
    markSettingsDirty(true, c);
  }));
}
