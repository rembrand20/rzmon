'use strict';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let CSRF = $('meta[name=csrf-token]')?.content;   // wordt bijgewerkt uit elk antwoord (sessie vernieuwd)
// Taal: teksten staan in het Nederlands in de code; L() zoekt de Engelse vertaling op (lang/en.php), {naam} wordt ingevuld
const LANG = window.LANG || 'nl';
const LOCALE = LANG === 'en' ? 'en-GB' : 'nl-NL';
/** Decimaalteken: komma in het Nederlands, punt in het Engels */
const dec = (v) => (LANG === 'en' ? String(v) : String(v).replace('.', ','));
// Interval van een check (1.90.1): leesbaar in minuten en uren, en de keuzes in het formulier
const INTERVALS = [60, 120, 300, 600, 900, 1800, 3600, 7200, 14400, 21600, 43200, 86400];
function fmtInterval(sec) {
  const s = +sec;
  if (s < 60) return L('{n} seconden', { n: s });
  if (s < 3600) { const m = Math.round(s / 6) / 10; return m === 1 ? L('1 minuut') : L('{n} minuten', { n: dec(m) }); }
  const h = Math.round(s / 360) / 10;
  return h === 1 ? L('1 uur') : L('{n} uur', { n: dec(h) });
}
/** <option>s voor het interval; een afwijkende waarde van een bestaande check komt erbij als "(huidig)" */
const intervalOptions = (cur) => [...INTERVALS, ...(INTERVALS.includes(+cur) ? [] : [+cur])].sort((a, b) => a - b)
  .map((v) => `<option value="${v}" ${+v === +cur ? 'selected' : ''}>${esc(fmtInterval(v))}${INTERVALS.includes(v) ? '' : ` (${L('huidig')})`}</option>`).join('');
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
  ssl:     { label: L('SSL-certificaat'), target: L('Host[:poort] of bestand'), unit: 'dagen', warn: 21, crit: 7, low: true, ph: L('example.com, localhost:443 of /etc/ssl/mijn.crt'), help: L('Waarschuwt als het certificaat bijna verloopt. Leeg = adres van de server op poort 443. Werkt op elke TLS-poort (8443, 993, 8883…), ook op internet. Een bestand (.crt/.pem) of localhost:poort leest de agent op de server zelf; kies een bestand uit de lijst die de agent vond. Bij een keten telt het certificaat dat het eerst verloopt.') },
  disk:    { label: L('Diskruimte'),    target: L('Mount / pad'),   unit: '%',  warn: 80, crit: 90, ph: '/', help: L('Percentage gebruikt. Op agent-servers: mount zoals de agent meldt.') },
  cpu:     { label: L('CPU'),           target: null,            unit: '%',  warn: 85, crit: 95, help: L('CPU-gebruik gemeten over 2 seconden, met load average in het bericht. Tip: zet "alarm na N fouten" op 3+ om korte pieken te negeren.') },
  ram:     { label: L('RAM'),           target: null,            unit: '%',  warn: 85, crit: 95, help: L('Percentage geheugen in gebruik (op basis van MemAvailable, dus cache telt niet mee). Swap staat in het bericht.') },
  podman:  { label: L('Podman (algemeen)'), target: null, unit: null, help: L('Algehele status van Podman op deze server: draait podman, hoeveel containers draaien er, en zijn er containers gestopt met een fout, ongezond (healthcheck) of gepauzeerd, of pods niet in orde? Vereist de agent.') },
  container: { label: L('Container (Podman)'), target: L('Container'), unit: null, ph: L('naam, of gebruiker/naam (rootless)'), help: L('Eén check per container: de status (draait, healthcheck, herstarts) altijd, en daarnaast de CPU, RAM en disk die je hieronder aanvinkt, elk met eigen drempels. Kies de naam uit de lijst die de agent meldt.') },
  log:     { label: L('Logbestand (zoeken)'), target: L('Bron'), unit: 'treffers', warn: 5, crit: 20, ph: L('/var/log/secure, journal, journal:sshd of kernel'), help: L('De agent leest de nieuwe regels van een logbestand of het systemlog en telt hoeveel er op je zoekpatroon passen in de laatste periode (Instellingen → Alarmgedrag, standaard 15 min). Kies hieronder een voorinstelling of stel zelf in.') },
  command: { label: L('Commando'), target: null, unit: null, help: L('De agent voert een commando uit op de server, en RzMon vergelijkt de uitvoer met het antwoord dat je verwacht. Alleen als commando\'s voor deze server aan staan (Instellingen → Agents → Commando-checks).') },
  pve:     { label: L('Proxmox VE'), target: null, unit: null, help: L('Via de agent op een Proxmox-host (agent 1.61.0 of nieuwer, als root): de guests (VM\'s en containers), back-ups, opslag en ZFS. Kies hieronder wat je wilt bewaken.') },
  ha:      { label: L('Home Assistant'), target: null, unit: null, help: L('Via de Home Assistant-add-on: de laatste automatische back-up, integraties die niet geladen zijn, of de systeemstatus van de Supervisor. Kies hieronder wat je wilt bewaken.') },
  snmp:    { label: L('SNMP'),          target: null,            unit: null, help: L('RzMon leest het apparaat zelf uit via SNMP (switches, access points, NAS, UPS, printers, Proxmox…). Interfaces, schijven en voorraden kies je het makkelijkst via 📡 SNMP-checks bij de server.') },
  reboot:  { label: L('Uptime'), target: null, unit: 'uur', help: L('Ziet wanneer de server opnieuw is opgestart (de uptime wordt korter) en stuurt dan een ℹ️ info-melding. De check zelf is altijd OK. Info staat standaard uit: zet hem aan bij Instellingen → 🔔 Meldingen (rij Info), of alleen voor deze check bij 🔔 Meldingen hieronder. Op agent-servers is agent 1.46.0 of nieuwer nodig.') },
  heartbeat: { label: L('Heartbeat'), target: L('Naam (voor rzmon-hb)'), unit: null, ph: 'backup-db', help: L('Een script, cronjob of apparaat meldt zich zelf bij RzMon (rzmon-hb op een server met de agent, of de geheime URL). Komt er binnen het schema plus de speling niets binnen: WARNING, na twee keer de speling CRITICAL. Mislukt (fail) = CRITICAL. Met start en klaar meet RzMon de duur.') },
  process: { label: L('Proces'),        target: L('Naam of patroon'), unit: null, ph: 'nginx', help: L('Draait het proces? Op naam (zoals in ps of top) of op een patroon in de opdrachtregel, eventueel alleen van één gebruiker. Draait het niet: CRITICAL. Daarnaast optioneel het aantal en het CPU- en RAM-gebruik. Is het herstart, dan komt er een ℹ️ info-melding. Op agent-servers kies je hem uit de lijst; agent 1.59.0 of nieuwer nodig.') },
  service: { label: L('Service'),       target: L('Servicenaam'),   unit: null, ph: 'nginx', help: L('De systemd-service moet "active" zijn. Op agent-servers kies je hem uit de lijst: de agent bewaakt hem dan vanzelf, en met Diepere controle wordt ook gekeken of hij echt werkt. Home Assistant-agent: homeassistant, supervisor, updates of addon:<slug>.') },
};
// Voorinstellingen voor logbestanden: bron, zoekpatroon (regex, hoofdletters maakt niet uit) en drempels
// Standaarddrempels voor CPU (%) en RAM (MB) bij een proces-check (1.98.0)
const PROC_RES = { cpu: [80, 95], mem: [1024, 2048] };
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
  unifi_clients: { label: L('UniFi clients'), short: L('UniFi'), target: L('Band of SSID'), ph: 'band:5g · ssid:Thuis', unit: 'clients', warn: null, crit: null, help: L('Aantal verbonden clients op een UniFi access point: totaal (leeg), per band (band:2g, band:5g, band:6g) of per SSID (ssid:naam). Drempels zijn optioneel (meer = drukker).') },
  unifi_ap:      { label: L('UniFi AP'), short: L('UniFi'), unit: null, help: L('Isolated (het AP heeft geen bekabelde verbinding meer, bv. een mesh-AP waarvan de uplink weg is) = CRITICAL. Toont model, firmware en uplink, en geeft een ℹ️-melding bij nieuwe firmware.') },
  unifi_cu:      { label: L('UniFi kanaalbezetting'), short: L('UniFi'), target: L('Band'), ph: '2g · 5g · 6g', unit: '%', warn: 60, crit: 80, help: L('Hoe vol het kanaal is (ook door buren en storing): de beste maat voor trage wifi. Boven 60% wordt het merkbaar, boven 80% traag.') },
  unifi_ssid:    { label: L('UniFi SSID'), short: L('UniFi'), target: L('SSID'), unit: null, help: L('Wordt het SSID uitgezonden? Nergens = CRITICAL, op één band uit = WARNING.') },
  unifi_traffic: { label: L('UniFi verkeer per SSID'), short: L('UniFi'), target: L('SSID'), unit: 'Mbit/s', warn: null, crit: null, help: L('Doorvoer van een SSID over alle radio\'s samen (download en upload van de clients). Drempels zijn optioneel.') },
  unifi_quality: { label: L('UniFi kwaliteit per SSID'), short: L('UniFi'), target: L('SSID'), unit: '%', warn: 15, crit: 30, help: L('Aandeel pakketten dat opnieuw verzonden moest worden: veel herhalingen wijzen op storing, slecht bereik of een vol kanaal. Met de CCQ (verbindingskwaliteit) in het bericht.') },
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
/** Proxmox VE (1.105.0): wat een pve-check bewaakt */
const PVE_METRICS = {
  overview: { label: L('Overzicht van de node'), unit: null, help: L('CRITICAL als een guest die automatisch moet starten uit staat, een opslag niet beschikbaar is, een ZFS-pool niet gezond is of de cluster geen quorum heeft; WARNING bij een gepauzeerde of vergrendelde guest of een mislukte back-uptaak.') },
  guest: { label: L('Eén guest (VM of container)'), unit: '%', warn: 90, crit: 95, target: L('VMID'), ph: '100', help: L('Draait de guest? Uit = CRITICAL. De drempels gelden voor het RAM-gebruik (%). Ook WARNING als de guest agent niet antwoordt, of de guest vergrendeld of gepauzeerd is.') },
  backups: { label: L('Back-ups'), unit: 'dagen', warn: 2, crit: 7, target: L('VMID (leeg = alle guests)'), ph: L('leeg = alle'), help: L('Hoe oud de nieuwste back-up per guest is (warning en critical in dagen). Nooit een back-up = CRITICAL; een guest die in geen enkele back-uptaak zit of een mislukte taak = WARNING. Afwijkend per guest: zie hieronder.') },
  storage: { label: L('Opslag'), unit: '%', warn: 85, crit: 95, target: L('Opslag (leeg = alle)'), ph: L('leeg = alle'), help: L('Hoe vol de opslag is (%), en CRITICAL als een opslag niet beschikbaar is (bv. een NFS-share die weg is).') },
  zfs: { label: L('ZFS-pools'), unit: '%', warn: 80, crit: 90, help: L('Gezondheid van de pools (niet ONLINE = CRITICAL), hoe vol ze zijn (%), en WARNING als de laatste scrub langer dan 35 dagen geleden is.') },
};
const isPveHost = (h) => h.metrics.includes('pve');
/** Home Assistant-add-on? (meldt ha:-gegevens of de Supervisor) */
function isHaHost(h) { return h.agent_update_via === 'ha' || h.metrics.some((m) => m.startsWith('ha:') || m === 'service:supervisor'); }
/** Type-beschrijving van een check (container: één check met status + resources, geen eigen eenheid). */
function typeOf(c) {
  if (c.type === 'snmp') return snmpType(c);
  if (c.type === 'ha') return { ...TYPES.ha, ...(HA_METRICS[c.metric] || {}), label: 'Home Assistant' };
  if (c.type === 'pve') return { ...TYPES.pve, ...(PVE_METRICS[c.metric] || PVE_METRICS.overview), label: 'Proxmox' };
  return c.type === 'container' && c.metric ? { ...TYPES.container, ...METRICS[c.metric], label: TYPES.container.label } : TYPES[c.type];
}

const MODES = { local: L('Lokaal (deze server)'), agent: L('Remote met agent'), remote: L('Remote (alleen netwerk)') };

let state = { hosts: [], alerts: [] };
// Omgekeerde status (zie Checks::REVERSIBLE)
const REVERSIBLE = ['ping', 'tcp', 'http', 'service', 'container', 'podman', 'command', 'ha'];
// 1.107.0: slimme drempels (zoals Smart::SPEC): welke checks kunnen leren, en het standaard vangnet
const SMART_GUARD = { ping: 1000, tcp: 3000, http: 10000, cpu: null, ram: 98, 'snmp:cpu': null, 'snmp:mem': 98, 'snmp:if': 98, 'snmp:load': null, 'snmp:temp': 85,
  'snmp:ups_load': 98, 'snmp:ups_temp': 45, 'snmp:syno_system': 75, 'snmp:syno_disk': 62, 'snmp:unifi_cu': null, 'snmp:unifi_quality': null };
const smartKey = (type, metric) => (type === 'snmp' ? `snmp:${metric}` : type);
const smartEligible = (type, metric) => smartKey(type, metric) in SMART_GUARD;
const smartGuard = (type, metric) => SMART_GUARD[smartKey(type, metric)];
const SMART_MODES = { manual: '✋ ' + L('Handmatig'), smart: '🧠 ' + L('Slim'), guard: '🧠🛡️ ' + L('Slim met vangnet') };
const SMART_SENS = { calm: L('rustig'), normal: L('normaal'), sharp: L('scherp') };
const REV_OK = [[0, L('OK (normaal)')], [1, '🟠 ' + L('WARNING (omgekeerd)')], [2, '🔴 ' + L('CRITICAL (omgekeerd)')]];
const REV_FAIL = [[0, '✅ OK'], [1, '🟠 WARNING'], [2, '🔴 CRITICAL']];
const STATUS_WORD = ['OK', 'WARNING', 'CRITICAL', L('onbekend')];

let stats = { uptime: {}, sparks: {} };
// Sorteren op de pagina Meldingen (per apparaat onthouden): { key: 'time'|'status'|'server'|'check'|'message'|'channels', dir: 1|-1 }
const ALERT_SORTS = { time: L('Tijd'), status: 'Status', server: 'Server', check: 'Check', message: L('Bericht'), channels: L('Kanalen') };
// IDS: tabblad, en Toegang (gegevens, filters, sorteren, open dagen)
const liveLoaded = {};   // wanneer meldingen en IDS voor het laatst geladen zijn (live bijwerken, 1.119.0)
// 1.119.0: hoeveel dagen Meldingen en IDS ophalen; ouder op verzoek (knop onderaan, of zoeken)
const histDays = { alerts: 7, ids: 14 };
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
let idsCard = '';             // 1.115.0: alleen incidenten van één kaart van Bescherming
let idsCat = '';              // '' of een soort: ports, files, procs, logins (chips)
const idsBundles = new Set(); // uitgeklapte bundels van incidenten en toegang
const idsDayOpen = {};

// ---------------------------------------------------------------- API
async function api(action, body, params = {}, retried = false) {
  const qs = new URLSearchParams({ action, ...params });
  const res = await fetch('api.php?' + qs, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({ error: L('Ongeldig antwoord van server') }));
  const tok = res.headers.get('X-CSRF-Token');
  if (tok) CSRF = tok;
  // Sessie intussen vernieuwd (lang stil, opnieuw ingelogd via "onthoud mij"): één keer opnieuw met het nieuwe token
  if (res.status === 403 && data.csrf && tok && !retried) return api(action, body, params, true);
  if (res.status === 401 && !action.startsWith('login')) { location.reload(); }
  if (res.status === 403 && data.must_change) { location.reload(); }   // wachtwoord in een datalek: eerst een nieuw kiezen
  // status en data mee in de fout: zo hoeft niemand de (vertaalde) tekst te herkennen
  if (!res.ok) throw Object.assign(new Error(data.error || `HTTP ${res.status}`), { status: res.status, data });
  return data;
}

/** Na het instellen van een wachtwoord: waarschuwing als het in een datalek staat (opslaan mag wel) */
function pwnedWarn(r) {
  if (r && r.pwned > 0) toast('⚠️ ' + L('Dit wachtwoord staat {n}× in datalekken. Bij het inloggen moet dan eerst een ander worden gekozen.', { n: (+r.pwned).toLocaleString(LOCALE) }), 'err');
}

// ---------------------------------------------------------------- Voorkeuren per gebruiker (1.71.0)
// open_mode: 'closed' (bij openen alles dicht, standaard) of 'smart' (zoals vroeger); fx: filters per pagina;
// fxviews: opgeslagen filters. Zonder account (lokaal netwerk): alleen in deze browser.
const prefsLocal = document.body.dataset.prefs === 'local';
const PREFS = (() => {
  try {
    return (prefsLocal ? JSON.parse(localStorage.getItem('rzmon-prefs') || '{}') : JSON.parse(document.body.dataset.prefs || '{}')) || {};
  } catch (e) { return {}; }
})();
const prefTimers = {};
function savePref(key, value) {
  PREFS[key] = value;
  if (prefsLocal) { try { localStorage.setItem('rzmon-prefs', JSON.stringify(PREFS)); } catch (e) { /* alleen voor nu */ } return; }
  clearTimeout(prefTimers[key]);
  prefTimers[key] = setTimeout(() => api('prefs_save', { key, value }).catch(() => {}), 500);
}
// Chip bovenaan de meldingen (1.84.0): per gebruiker onthouden (eerder alleen in deze browser)
if (PREFS.alert_filter) alertFilter = PREFS.alert_filter;
/** Slim openen (vandaag open, ernstige dingen open, onthouden): alleen als de gebruiker dat koos */
const smartOpen = () => PREFS.open_mode === 'smart';

// ---------------------------------------------------------------- Filters op elke pagina (1.71.0)
// Per pagina een paneel met een keuzelijst per onderdeel (meerdere waarden, met aantallen), een periode, actieve filters
// als chips, opgeslagen filters met een naam, en de filters in het adres (#alerts?server=NAS,Pi&status=crit).
const FX = {};           // pagina → { bar, fields: [{ k, label, get(item), fmt?, order? }], time?(item), items(), render() }
const fxSel = {};        // pagina → { veld: [waarden], period, from, to }
let fxPanelOpen = null;  // pagina waarvan het paneel open staat
const FX_PERIODS = [['today', () => L('vandaag')], ['24h', () => L('24 uur')], ['7d', () => L('7 dagen')], ['30d', () => L('30 dagen')]];
const FX_TAB = { dash: 'dashboard', alerts: 'alerts', ids: 'ids/inc', acc: 'ids/acc', blk: 'ids/blk', vulns: 'vulns/cve', leaks: 'vulns/leak' };

const fxList = (v) => (Array.isArray(v) ? v : [v]).filter((x) => x !== null && x !== undefined && x !== '').map(String);
function fxGet(page) {
  if (!fxSel[page]) {
    try { fxSel[page] = JSON.parse(JSON.stringify((PREFS.fx || {})[page] || {})); } catch (e) { fxSel[page] = {}; }
  }
  return fxSel[page];
}
/** Aantal actieve filters (de periode telt als één) */
function fxCount(page) {
  const s = fxGet(page);
  return (s.period ? 1 : 0) + Object.entries(s).filter(([k, v]) => Array.isArray(v) && FX[page]?.fields.some((f) => f.k === k)).reduce((n, [, v]) => n + v.length, 0);
}
const fxActive = (page) => fxCount(page) > 0;
const fxHas = (page, k) => (fxGet(page)[k] || []).length > 0;
const fxToday = () => new Date().toLocaleDateString('sv');   // JJJJ-MM-DD in de eigen tijdzone

function fxPeriodOk(s, t) {
  if (!s.period || !t) return true;
  const day = t.slice(0, 10);
  if (s.period === 'custom') return (!s.from || day >= s.from) && (!s.to || day <= s.to);
  if (s.period === 'today') return day === fxToday();
  const age = Date.now() - new Date(t.replace(' ', 'T')).getTime();
  return age <= { '24h': 1, '7d': 7, '30d': 30 }[s.period] * 864e5;
}
function fxPeriodLabel(s) {
  if (s.period === 'custom') return `${s.from ? fmtDay(s.from) : '…'} – ${s.to ? fmtDay(s.to) : '…'}`;
  const p = FX_PERIODS.find(([k]) => k === s.period);
  return p ? p[1]() : s.period;
}

/** Past dit item bij de filters van de pagina? skip: dit onderdeel niet meetellen (voor de aantallen in het paneel) */
function fxPass(page, it, skip = null) {
  const def = FX[page];
  if (!def) return true;
  const s = fxGet(page);
  if (def.time && skip !== 'period' && !fxPeriodOk(s, def.time(it))) return false;
  for (const f of def.fields) {
    const want = s[f.k];
    if (f.k === skip || !Array.isArray(want) || !want.length) continue;
    const have = fxList(f.get(it));
    if (!want.some((w) => have.includes(w))) return false;
  }
  return true;
}

/** Knop, chips en (als het open is) het paneel van een pagina bijwerken; de pagina roept dit aan na het tekenen */
function fxRender(page) {
  const def = FX[page];
  const bar = def && $(def.bar);
  if (!bar) return;
  let btn = $('.fx-btn', bar);
  if (!btn) {
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn fx-btn';
    btn.dataset.fxBtn = page;
    btn.setAttribute('aria-expanded', 'false');
    const sp = $('.dash-sp', bar);
    if (sp) bar.insertBefore(btn, sp); else bar.append(btn);
    bar.insertAdjacentHTML('afterend', `<div class="fx-wrap" data-fx="${page}"><div class="fx-panel" hidden></div><div class="fx-chips"></div></div>`);
  }
  const s = fxGet(page), n = fxCount(page);
  btn.innerHTML = `⚲ ${L('Filters')}${n ? ` <b class="fx-n">${n}</b>` : ''}`;
  btn.classList.toggle('on', n > 0);
  btn.setAttribute('aria-expanded', String(fxPanelOpen === page));
  const wrap = bar.nextElementSibling;
  const chips = [];
  if (s.period) chips.push(['period', '', `${L('Periode')}: ${fxPeriodLabel(s)}`]);
  def.fields.forEach((f) => (s[f.k] || []).forEach((v) => chips.push([f.k, v, `${f.label()}: ${f.fmt ? f.fmt(v) : v}`])));
  $('.fx-chips', wrap).innerHTML = chips.length ? chips.map(([k, v, l]) => `<button type="button" class="fx-chip" data-fx-del="${esc(k)}" data-v="${esc(v)}" title="${esc(L('Filter weghalen'))}">${esc(l)} <span aria-hidden="true">✕</span></button>`).join('')
    + `<button type="button" class="linkbtn" data-fx-clear>${L('Alles wissen')}</button>` : '';
  const panel = $('.fx-panel', wrap);
  if (fxPanelOpen === page) fxPanel(page, panel); else panel.hidden = true;
}

function fxPanel(page, panel) {
  const def = FX[page], s = fxGet(page), items = def.items() || [];
  const wasOpen = new Set($$('details[open]', panel).map((d) => d.dataset.k));
  const finds = Object.fromEntries($$('.fx-find', panel).map((i) => [i.dataset.k, i.value]));
  const groups = def.fields.map((f) => {
    const cnt = new Map();
    items.forEach((it) => { if (fxPass(page, it, f.k)) new Set(fxList(f.get(it))).forEach((v) => cnt.set(v, (cnt.get(v) || 0) + 1)); });
    const sel = s[f.k] || [];
    sel.forEach((v) => { if (!cnt.has(v)) cnt.set(v, 0); });
    if (!cnt.size) return '';
    const lbl = (v) => String(f.fmt ? f.fmt(v) : v);
    const vals = [...cnt].sort(f.order ? (a, b) => f.order(a[0], b[0]) : (a, b) => lbl(a[0]).localeCompare(lbl(b[0]), LANG, { numeric: true, sensitivity: 'base' }));
    const many = vals.length > 8;
    return `<details class="fx-field" data-k="${f.k}" ${sel.length || wasOpen.has(f.k) ? 'open' : ''}>
      <summary>${esc(f.label())}${sel.length ? ` <b class="fx-n">${sel.length}</b>` : ''}<small class="muted">${vals.length}</small></summary>
      ${many ? `<input type="search" class="fx-find" data-k="${f.k}" placeholder="${esc(L('Zoek…'))}" value="${esc(finds[f.k] || '')}">` : ''}
      <div class="fx-opts">${vals.map(([v, n]) => `<label class="fx-opt" ${finds[f.k] && !lbl(v).toLowerCase().includes(finds[f.k].toLowerCase()) ? 'hidden' : ''}>
        <input type="checkbox" data-k="${f.k}" value="${esc(v)}" ${sel.includes(v) ? 'checked' : ''}><span>${esc(lbl(v))}</span><small>${n}</small></label>`).join('')}</div></details>`;
  }).join('');
  const period = def.time ? `<div class="fx-period"><span class="fx-lbl">${L('Periode')}</span>
      ${[['', L('alles')], ...FX_PERIODS.map(([k, l]) => [k, l()]), ['custom', L('van – tot')]].map(([k, l]) => `<button type="button" class="dchip ${(s.period || '') === k ? 'on' : ''}" data-fx-period="${k}">${l}</button>`).join('')}
      ${s.period === 'custom' ? `<span class="fx-dates"><input type="date" data-fx-date="from" value="${esc(s.from || '')}" aria-label="${esc(L('van'))}"> – <input type="date" data-fx-date="to" value="${esc(s.to || '')}" aria-label="${esc(L('tot'))}"></span>` : ''}</div>` : '';
  const views = (PREFS.fxviews || {})[page] || [];
  panel.innerHTML = period + `<div class="fx-grid">${groups || `<p class="muted small">${L('Niets om op te filteren.')}</p>`}</div>
    <div class="fx-foot">
      <span class="fx-views">${views.length ? `<span class="fx-lbl">⭐ ${L('Opgeslagen')}</span>` : ''}${views.map((v, i) => `<span class="fx-view"><button type="button" class="dchip" data-fx-view="${i}" title="${esc(L('Dit filter toepassen'))}">${esc(v.n)}</button><button type="button" class="fx-x" data-fx-view-del="${i}" title="${esc(L('Opgeslagen filter verwijderen'))}">✕</button></span>`).join('')}</span>
      <span class="dash-sp"></span>
      <button type="button" class="btn" data-fx-save ${fxActive(page) ? '' : 'disabled'}>💾 ${L('Opslaan als…')}</button>
      <button type="button" class="btn" data-fx-clear ${fxActive(page) ? '' : 'disabled'}>${L('Alles wissen')}</button>
      <button type="button" class="btn primary" data-fx-close>${L('Klaar')}</button></div>`;
  panel.hidden = false;
}

/** Filters gewijzigd: opruimen, bewaren (per gebruiker), in het adres zetten en de pagina opnieuw tekenen */
function fxChanged(page) {
  const s = fxGet(page);
  Object.keys(s).forEach((k) => { if (Array.isArray(s[k]) && !s[k].length) delete s[k]; });
  if (!s.period) { delete s.period; delete s.from; delete s.to; } else if (s.period !== 'custom') { delete s.from; delete s.to; }
  savePref('fx', { ...(PREFS.fx || {}), [page]: s });
  fxSyncHash();
  FX[page].render();
}

/** Pagina die nu in beeld is (voor het adres) */
function fxCurrent() {
  const tab = ($('.tab-panel:not([hidden])') || {}).id?.replace('tab-', '');
  if (tab === 'dashboard') return 'dash';
  if (tab === 'alerts') return 'alerts';
  if (tab === 'ids') return { inc: 'ids', acc: 'acc', blk: 'blk' }[idsTab] || 'ids';
  if (tab === 'vulns') return $('#vuln-tabs') ? (vulnTab === 'leak' ? 'leaks' : 'vulns') : ($('#leak-list') && !$('#vuln-search') ? 'leaks' : 'vulns');
  return null;
}
function fxQuery(page) {
  const s = fxGet(page);
  const parts = Object.entries(s).filter(([, v]) => (Array.isArray(v) ? v.length : v))
    .map(([k, v]) => `${encodeURIComponent(k)}=${Array.isArray(v) ? v.map(encodeURIComponent).join(',') : encodeURIComponent(v)}`);
  return parts.length ? '?' + parts.join('&') : '';
}
function fxSyncHash() {
  const page = fxCurrent();
  if (!page) return;
  const tab = FX_TAB[page];
  const plain = tab === 'dashboard' || tab === 'alerts' || (page === 'ids' && !fxActive(page)) || (page === 'vulns' && !$('#vuln-tabs') && !fxActive(page));
  history.replaceState(null, '', '#' + (plain ? tab.split('/')[0] : tab) + fxQuery(page));
}
/** Filters uit het adres (gaan voor op de bewaarde) */
function fxFromQuery(page, qs) {
  const s = {};
  qs.split('&').filter(Boolean).forEach((p) => {
    const [k, v = ''] = p.split('=');
    const key = decodeURIComponent(k);
    if (['period', 'from', 'to'].includes(key)) s[key] = decodeURIComponent(v);
    else s[key] = v.split(',').filter(Boolean).map(decodeURIComponent);
  });
  fxSel[page] = s;
}

document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-fx-btn]');
  if (b) { fxPanelOpen = fxPanelOpen === b.dataset.fxBtn ? null : b.dataset.fxBtn; fxRender(b.dataset.fxBtn); return; }
  const w = e.target.closest('.fx-wrap');
  if (!w) return;
  const page = w.dataset.fx, s = fxGet(page);
  let t;
  if ((t = e.target.closest('[data-fx-del]'))) {
    if (t.dataset.fxDel === 'period') delete s.period; else s[t.dataset.fxDel] = (s[t.dataset.fxDel] || []).filter((v) => v !== t.dataset.v);
    fxChanged(page);
  } else if (e.target.closest('[data-fx-clear]')) { fxSel[page] = {}; fxChanged(page); }
  else if (e.target.closest('[data-fx-close]')) { fxPanelOpen = null; fxRender(page); }
  else if ((t = e.target.closest('[data-fx-period]'))) { s.period = t.dataset.fxPeriod; fxChanged(page); }
  else if ((t = e.target.closest('[data-fx-view]'))) {
    const v = ((PREFS.fxviews || {})[page] || [])[+t.dataset.fxView];
    if (v) { fxSel[page] = JSON.parse(JSON.stringify(v.s)); fxChanged(page); }
  } else if ((t = e.target.closest('[data-fx-view-del]'))) {
    const all = { ...(PREFS.fxviews || {}) };
    all[page] = (all[page] || []).filter((x, i) => i !== +t.dataset.fxViewDel);
    savePref('fxviews', all);
    fxRender(page);
  } else if (e.target.closest('[data-fx-save]')) {
    const name = (prompt(L('Naam voor dit filter, bv. "NAS critical"')) || '').trim().slice(0, 40);
    if (!name) return;
    const all = { ...(PREFS.fxviews || {}) };
    all[page] = [...(all[page] || []).filter((x) => x.n !== name), { n: name, s: JSON.parse(JSON.stringify(s)) }].slice(-20);
    savePref('fxviews', all);
    fxRender(page);
    toast(L('Filter "{n}" opgeslagen', { n: name }));
  }
});
document.addEventListener('change', (e) => {
  const w = e.target.closest('.fx-wrap');
  if (!w) return;
  const page = w.dataset.fx, s = fxGet(page);
  if (e.target.matches('input[type=checkbox][data-k]')) {
    const set = new Set(s[e.target.dataset.k] || []);
    if (e.target.checked) set.add(e.target.value); else set.delete(e.target.value);
    s[e.target.dataset.k] = [...set];
    fxChanged(page);
  } else if (e.target.matches('[data-fx-date]')) { s[e.target.dataset.fxDate] = e.target.value; fxChanged(page); }
});
document.addEventListener('input', (e) => {
  if (!e.target.matches('.fx-find')) return;
  const q = e.target.value.trim().toLowerCase();
  $$('.fx-opt', e.target.closest('.fx-field')).forEach((o) => { o.hidden = !!q && !o.textContent.toLowerCase().includes(q); });
});

// Onderdelen per pagina (de waarden komen uit wat er op de pagina staat)
const fxYesNo = (v) => (v === '1' ? L('ja') : L('nee'));
const fxMap = (m) => (v) => { const w = m[v]; return typeof w === 'function' ? w() : (w ?? v); };
const HOST_KIND = { ha: 'Home Assistant', agent: 'Agent', remote: 'Remote', local: () => L('Lokaal') };
const DASH_STATUS = { 2: 'Critical', 1: 'Warning', 3: () => L('Onbekend'), 0: 'OK', maint: () => L('Onderhoud'), off: () => L('Uit') };
const STATUS_ORDER = ['2', '1', '3', '0', 'maint', 'off'];
const byOrder = (list) => (a, b) => list.indexOf(a) - list.indexOf(b);
const DASH_CHECK_FX = ['status', 'type', 'check', 'acked'];   // onderdelen van een check (de rest hoort bij de server)
FX.dash = {
  bar: '#tab-dashboard .dash-bar',
  items: () => (state && state.hosts ? state.hosts.flatMap((h) => (h.checks.length ? h.checks.map((c) => ({ h, c })) : [{ h, c: null }])) : []),
  render: () => render(),
  fields: [
    { k: 'server', label: () => 'Server', get: (x) => x.h.name },
    { k: 'devkind', label: () => L('Soort apparaat'), get: (x) => hostKind(x.h), fmt: (v) => kindLabel(v), order: (a, b) => byOrder(Object.keys(KIND_INFO))(a, b) },
    { k: 'kind', label: () => L('Bewaking'), get: (x) => (isHa(x.h) ? 'ha' : x.h.mode), fmt: fxMap(HOST_KIND) },
    { k: 'group', label: () => L('Groep (bovenliggend)'), get: (x) => (x.h.parent_id ? (findHost(x.h.parent_id) || {}).name : '-'), fmt: (v) => (v === '-' ? L('geen') : v) },
    { k: 'status', label: () => 'Status', get: (x) => x.c && (!+x.c.enabled ? 'off' : x.c.maintenance ? 'maint' : String(x.c.status)), fmt: fxMap(DASH_STATUS), order: byOrder(STATUS_ORDER) },
    { k: 'type', label: () => L('Soort check'), get: (x) => x.c && x.c.type },
    { k: 'check', label: () => 'Check', get: (x) => x.c && x.c.name },
    { k: 'acked', label: () => L('Bevestigd'), get: (x) => (x.c && +x.c.status !== 0 ? (x.c.acked_at ? '1' : '0') : null), fmt: fxYesNo },
    { k: 'agent', label: () => L('Agentversie'), get: (x) => x.h.agent_version || null },
  ],
};

const ALERT_KINDS = { notify: () => L('Check'), system: () => L('Systeem'), info: 'Info', sec: () => L('Beveiliging'), ids: 'IDS', ack: () => L('Bevestiging'), maint: () => L('Onderhoud') };
const ALERT_CH = { pushover: '📲 Pushover', email: () => '📧 ' + L('E-mail'), webhook: '🏠 Home Assistant', none: () => '🔕 ' + L('nergens heen') };
/** Naar welke kanalen een melding ging (of zou gaan) */
function alertChannels(a) {
  if (a.kind === 'auth' || a.kind === 'authfail') return ['none'];
  const why = splitSent(a.message)[1];
  const out = [];
  if (+a.sent_ok || /Pushover/.test(why)) out.push('pushover');
  if (a.email_ok !== null && a.email_ok !== undefined) out.push('email');
  if (a.webhook_ok !== null && a.webhook_ok !== undefined) out.push('webhook');
  return out.length ? out : ['none'];
}
FX.alerts = {
  bar: '#tab-alerts .dash-bar',
  items: () => (alertData ? alertData.alerts : []),
  render: () => renderAlerts(),
  time: (a) => a.created_at,
  fields: [
    { k: 'server', label: () => 'Server', get: (a) => alertCols(a).host },
    { k: 'devkind', label: () => L('Soort apparaat'), get: (a) => kindByName(alertCols(a).host), fmt: (v) => kindLabel(v), order: (a, b) => byOrder(Object.keys(KIND_INFO))(a, b) },
    { k: 'check', label: () => 'Check', get: (a) => alertCols(a).check },
    { k: 'status', label: () => 'Status', get: (a) => alertCat(a), fmt: (k) => (k === 'other' ? L('Overig') : catLabel(k)), order: byOrder(['crit', 'warn', 'unk', 'ok', 'info', 'sec', 'other']) },
    { k: 'kind', label: () => L('Soort'), get: (a) => a.kind, fmt: fxMap(ALERT_KINDS) },
    { k: 'channel', label: () => L('Kanaal'), get: (a) => alertChannels(a), fmt: fxMap(ALERT_CH) },
    { k: 'sent', label: () => L('Verzonden'), get: (a) => (splitSent(a.message)[1] ? 'fail' : alertChannels(a)[0] === 'none' ? 'none' : 'ok'),
      fmt: fxMap({ ok: () => L('verstuurd'), fail: () => L('mislukt'), none: () => L('niet verstuurd') }) },
    { k: 'ip', label: () => L('IP-adres'), get: (a) => a.ip },
  ],
};

const IDS_STATUS = { open: 'Open', handled: () => L('Afgehandeld'), ignored: () => L('Genegeerd'), blocked: () => L('Geblokkeerd') };
FX.ids = {
  bar: '.ids-pane[data-pane="inc"] .dash-bar',
  items: () => (idsData ? idsData.incidents : []),
  render: () => renderIds(),
  time: (i) => i.created_at,
  fields: [
    { k: 'server', label: () => 'Server', get: (i) => i.host_name },
    { k: 'devkind', label: () => L('Soort apparaat'), get: (i) => kindByName(i.host_name), fmt: (v) => kindLabel(v), order: (a, b) => byOrder(Object.keys(KIND_INFO))(a, b) },
    { k: 'cat', label: () => L('Categorie'), get: (i) => idsCatOf(i) || 'other', fmt: (k) => ((IDS_CATS.find((c) => c[0] === k) || [0, () => L('Overig')])[1]()) },
    { k: 'type', label: () => L('Soort incident'), get: (i) => i.type },
    { k: 'sev', label: () => L('Ernst'), get: (i) => String(i.severity), fmt: fxMap({ 2: 'Critical', 1: 'Warning' }), order: byOrder(['2', '1']) },
    { k: 'status', label: () => 'Status', get: (i) => i.status, fmt: fxMap(IDS_STATUS) },
    { k: 'ip', label: () => L('IP-adres'), get: (i) => i.ip },
  ],
};

/** IP-adres van een login (uit de gegevens, anders uit de tekst) */
function accIp(r) {
  const d = accDet(r);
  return d.ip || d.from || ((r.message || '').match(/\b(\d{1,3}(?:\.\d{1,3}){3})\b/) || [])[1] || null;
}
FX.acc = {
  bar: '.ids-pane[data-pane="acc"] .dash-bar',
  items: () => (accData ? accData.rows : []),
  render: () => renderAccess(),
  time: (r) => r.created_at,
  fields: [
    { k: 'server', label: () => 'Server', get: (r) => r.host_name },
    { k: 'type', label: () => L('Soort'), get: (r) => r.type, fmt: (v) => ACC_TYPES[v] || v },
    { k: 'result', label: () => L('Resultaat'), get: (r) => (r.kind === 'authfail' ? 'fail' : 'ok'), fmt: fxMap({ ok: () => '🔑 ' + L('gelukt'), fail: () => '❌ ' + L('mislukt') }) },
    { k: 'user', label: () => L('Gebruiker'), get: (r) => accWho(r, accDet(r)) },
    { k: 'ip', label: () => L('IP-adres / vanaf'), get: (r) => accIp(r) },
  ],
};

FX.blk = {
  bar: '.ids-pane[data-pane="blk"] .dash-bar',
  items: () => (idsData ? [...idsData.blocks.map((b) => ({ ...b, act: '1' })), ...idsData.removed.map((b) => ({ ...b, act: '0' }))] : []),
  render: () => renderIds(),
  time: (b) => b.created_at,
  fields: [
    { k: 'server', label: () => 'Server', get: (b) => b.host_name },
    { k: 'ip', label: () => 'IP', get: (b) => b.ip },
    { k: 'reason', label: () => L('Reden'), get: (b) => b.reason },
    { k: 'by', label: () => L('Door'), get: (b) => (+b.auto ? 'auto' : 'hand'), fmt: fxMap({ auto: () => L('automatisch'), hand: () => L('met de hand') }) },
    { k: 'active', label: () => L('Nog actief'), get: (b) => b.act, fmt: fxYesNo },
  ],
};

const vulnHost = (f) => (vulnData.hosts.find((h) => +h.id === +f.host_id) || {});
FX.vulns = {
  bar: '.vuln-pane[data-vpane="cve"] .dash-bar',
  items: () => (vulnData ? vulnData.findings : []),
  render: () => renderVulns(),
  fields: [
    { k: 'server', label: () => 'Server', get: (f) => vulnHost(f).name },
    { k: 'pkg', label: () => L('Pakket'), get: (f) => f.package },
    { k: 'sev', label: () => L('Ernst'), get: (f) => String(f.severity), fmt: fxMap({ 4: 'Critical', 3: 'High', 2: 'Medium', 1: 'Low', 0: () => L('Onbekend') }), order: byOrder(['4', '3', '2', '1', '0']) },
    { k: 'cve', label: () => 'CVE', get: (f) => f.cve },
    { k: 'fix', label: () => L('Met oplossing'), get: (f) => (f.fixed ? '1' : '0'), fmt: fxYesNo },
    { k: 'exposed', label: () => L('Bereikbaar'), get: (f) => ((vulnHost(f).exposed || {})[f.package] ? '1' : '0'), fmt: fxYesNo },
    { k: 'kev', label: () => L('Actief misbruikt'), get: (f) => (f.kev ? '1' : '0'), fmt: fxYesNo },
    { k: 'ign', label: () => L('Geaccepteerd'), get: (f) => (f.ignored ? '1' : '0'), fmt: fxYesNo },
    { k: 'reboot', label: () => L('Herstart nodig'), get: (f) => (vulnHost(f).reboot ? '1' : '0'), fmt: fxYesNo },
  ],
};

FX.leaks = {
  bar: '.vuln-pane[data-vpane="leak"] .dash-bar',
  items: () => (leakData ? leakData.emails.flatMap((e) => (e.breaches.length ? e.breaches.map((b) => ({ ...b, email: e.email, source: e.source })) : [{ email: e.email, source: e.source, none: 1 }])) : []),
  render: () => renderLeaks(),
  fields: [
    { k: 'email', label: () => L('Adres'), get: (b) => b.email },
    { k: 'source', label: () => L('Bron'), get: (b) => b.source, fmt: (v) => (LEAK_SRC[v] || LEAK_SRC.list)() },
    { k: 'breach', label: () => L('Lek'), get: (b) => (b.none ? null : b.title || b.name) },
    { k: 'data', label: () => L('Soort gegevens'), get: (b) => b.data_classes_l || [] },
    { k: 'year', label: () => L('Jaar van het lek'), get: (b) => (b.breach_date || '').slice(0, 4), order: (a, b) => b.localeCompare(a) },
    { k: 'status', label: () => 'Status', get: (b) => (b.none ? null : b.handled_at ? 'done' : 'open'), fmt: fxMap({ open: 'Open', done: () => L('Afgehandeld') }) },
  ],
};

// ---------------------------------------------------------------- Live bijwerken zonder opnieuw opbouwen (1.109.0)
/*
 * morph(el, html): zet de nieuwe HTML niet in één keer neer, maar past alleen aan wat anders is. Wat je open hebt
 * (uitgeklapte onderdelen, menu's, een veld waarin je typt, je scrollpositie) blijft zo staan. Elementen met een sleutel
 * (id, data-cid, data-host, data-day …) worden op sleutel herkend, de rest op plek en soort.
 * Live-effecten: een getal in [data-flash] licht even op en telt naar de nieuwe waarde; een check die van status
 * wisselt, kleurt even mee.
 */
const MORPH_KEYS = ['id', 'data-cid', 'data-host', 'data-day', 'data-key', 'data-email', 'data-bundle', 'data-k', 'data-st', 'data-kpi'];
let morphOn = 0;
function morphKey(n) {
  if (n.nodeType !== 1) return null;
  for (const k of MORPH_KEYS) { const v = n.getAttribute(k); if (v !== null && v !== '') return n.nodeName + '|' + k + '=' + v; }
  return null;
}
function morph(el, html) {
  const tpl = document.createElement('template');
  tpl.innerHTML = html;   // een template hangt niet in de pagina: gewone innerHTML
  morphKids(el, tpl.content);
}
function morphKids(from, to) {
  const olds = [...from.childNodes];
  const keyed = new Map(), dup = new Set();
  olds.forEach((n) => { const k = morphKey(n); if (k) { if (keyed.has(k)) dup.add(k); keyed.set(k, n); } });
  dup.forEach((k) => keyed.delete(k));
  const used = new Set();
  let cur = 0;
  [...to.childNodes].forEach((nw, i) => {
    const k = morphKey(nw);
    let old = k && keyed.has(k) && !used.has(keyed.get(k)) ? keyed.get(k) : null;
    if (!old) {
      for (let j = cur; j < olds.length; j++) {
        const o = olds[j];
        if (used.has(o) || (morphKey(o) && keyed.has(morphKey(o)))) continue;
        if (o.nodeType === nw.nodeType && o.nodeName === nw.nodeName && (!k || !morphKey(o))) { old = o; cur = j + 1; break; }
      }
    }
    const at = from.childNodes[i] || null;
    if (old) {
      used.add(old);
      if (old !== at) from.insertBefore(old, at);
      morphNode(old, nw);
    } else {
      from.insertBefore(nw.cloneNode(true), at);
    }
  });
  olds.forEach((o) => { if (!used.has(o) && o.parentNode === from) o.remove(); });
}
function morphNode(a, b) {
  if (a.nodeType === 3 || a.nodeType === 8) {
    if (a.nodeValue !== b.nodeValue) liveText(a, b.nodeValue);
    return;
  }
  if (a.nodeType !== 1 || a.isEqualNode(b)) return;
  // Kenmerken: alles van de nieuwe, behalve "open" van een <details> die je zelf open- of dichtklapte
  const keepOpen = a.nodeName === 'DETAILS' && !b.hasAttribute('data-fresh');
  const sBefore = a.hasAttribute('data-cid') ? (a.className.match(/\bs(\d)\b/) || [])[1] : null;
  for (const at of [...a.attributes]) if (!b.hasAttribute(at.name) && !(keepOpen && at.name === 'open')) a.removeAttribute(at.name);
  for (const at of [...b.attributes]) if (a.getAttribute(at.name) !== at.value && !(keepOpen && at.name === 'open')) a.setAttribute(at.name, at.value);
  if (sBefore !== null) {
    const sAfter = (a.className.match(/\bs(\d)\b/) || [])[1];
    if (sAfter !== sBefore) { a.classList.remove('live-pulse'); void a.offsetWidth; a.classList.add('live-pulse'); }
  }
  // Velden: wat je typt of aanvinkt blijft (de browser laat de waarde staan zodra je iets wijzigde)
  if (a.nodeName === 'TEXTAREA' && a === document.activeElement) return;
  morphKids(a, b);
}
/** Tekst wijzigen; in een [data-flash] met een getal: oplichten en naar de nieuwe waarde tellen */
function liveText(node, text) {
  const host = node.parentElement && node.parentElement.closest('[data-flash], .kpi-val');
  const re = /-?\d+(?:[.,]\d+)?/;
  const mo = host && (node.nodeValue || '').match(re), mn = host && text.match(re);
  if (!host) { node.nodeValue = text; return; }
  host.classList.remove('live-flash'); void host.offsetWidth; host.classList.add('live-flash');
  const from = mo ? parseFloat(mo[0].replace(',', '.')) : NaN, to = mn ? parseFloat(mn[0].replace(',', '.')) : NaN;
  if (isNaN(from) || isNaN(to) || from === to || matchMedia('(prefers-reduced-motion: reduce)').matches) { node.nodeValue = text; return; }
  const decs = (mn[0].split(/[.,]/)[1] || '').length, sep = mn[0].includes(',') ? ',' : '.';
  const t0 = performance.now(), D = 600;
  node._liveTo = text;
  const step = (t) => {
    if (node._liveTo !== text) return;   // er kwam al een nieuwere waarde
    const f = Math.min(1, (t - t0) / D), e = 1 - Math.pow(1 - f, 3);
    node.nodeValue = f >= 1 ? text : text.replace(re, (from + (to - from) * e).toFixed(decs).replace('.', sep));
    if (f < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}
/** Tijdens fn: elke innerHTML = … in de pagina wordt een morph */
function withMorph(fn) {
  morphOn++;
  try { return fn(); } finally { morphOn--; }
}
(() => {
  const d = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
  Object.defineProperty(Element.prototype, 'innerHTML', {
    configurable: true,
    get() { return d.get.call(this); },
    set(v) { if (morphOn && this.isConnected) morph(this, String(v)); else d.set.call(this, v); },
  });
})();
/** "12 s geleden" telt gewoon door: <span class="ago" data-ts="…"> */
const agoSpan = (dt) => (dt ? `<span class="ago" data-ts="${esc(dt)}">${ago(dt)}</span>` : ago(dt));
setInterval(() => { if (!document.hidden) $$('.ago[data-ts]').forEach((e) => { const t = ago(e.dataset.ts); if (e.textContent !== t) e.textContent = t; }); }, 1000);

function toast(msg, type = 'ok', onClick = null) {
  const el = document.createElement(onClick ? 'button' : 'div');
  el.className = 'toast ' + type + (onClick ? ' toast-click' : '');
  el.textContent = msg;
  if (onClick) { el.type = 'button'; el.onclick = () => { el.remove(); onClick(); }; }
  $('#toasts').append(el);
  setTimeout(() => el.remove(), onClick ? 9000 : 4000);
}

// ---- Contact met de agent (1.99.0): laatste en volgende contact, live bijgewerkt
let clockSkew = 0;   // seconden dat de klok van RzMon voorloopt op die van de browser
const nowS = () => Date.now() / 1000 + clockSkew;
const syncClock = (ts) => { if (ts) clockSkew = +ts - Date.now() / 1000; };
const clockText = (ts) => {
  const d = new Date((ts - clockSkew) * 1000);
  return Math.abs(nowS() - ts) > 20 * 3600 ? d.toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
    : d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
};
/** Wat de agent ongeveer aanhoudt: het gemeten ritme (als dat er is), anders het ingestelde */
const contactEvery = (c) => (c.rhythm && c.n >= 5 ? c.rhythm : c.tick);
/** Live element: "full" (tabel, Agent Manager) of "short" (serverkaart) */
function contactHtml(h, mode = 'full') {
  const c = h.contact;
  if (!c) return '';
  if (!c.seen) return `<span class="muted">${L('nog niet gezien')}</span>`;
  const slow = c.rhythm && c.n >= 5 && c.rhythm > c.tick * 1.5;
  const rhythm = mode !== 'full' || !c.rhythm || c.n < 5 ? ''
    : `<div class="small ${slow ? 'warn-txt' : 'muted'}" title="${esc(slow ? L('De agent komt minder vaak dan ingesteld. Draait hij via cron (minimaal elke minuut) in plaats van de systemd-timer, of duurt een ronde langer dan het ritme?') : L('Gemeten over de laatste {n} contacten', { n: c.n }))}">${slow ? '⚠️ ' + L('werkelijk elke {r} (ingesteld {t})', { r: durText(c.rhythm), t: durText(c.tick) }) : L('ritme: elke {r}', { r: durText(c.rhythm) })}</div>`;
  return `<span class="contact-live" data-host="${h.id}" data-mode="${mode}" data-seen="${c.seen}" data-every="${contactEvery(c)}" data-stale="${c.stale}" data-rt="${c.realtime ? 1 : 0}">${contactText(c.seen, contactEvery(c), c.stale, c.realtime, mode)}</span>${rhythm}`;
}
function contactText(seen, every, stale, rt, mode) {
  const now = nowS();
  const since = now - seen;
  const next = seen + every;
  const late = now - next;
  const grace = Math.max(15, every * 0.25);
  const nextTxt = since > stale ? `<span class="bad">🔴 ${L('offline sinds {t}', { t: clockText(seen) })}</span>`
    : late > grace ? `<span class="warn-txt">🟠 ${L('{d} te laat', { d: durText(late) })}</span>`
      : late > 0 ? L('nu verwacht') : L('over {d}', { d: durText(-late) });
  if (mode === 'short') return `${L('agent {ago}', { ago: L('{d} geleden', { d: durText(since) }) })}${since > stale ? ' · ' + nextTxt : ` · ${L('volgende')} ${nextTxt}`}`;
  return `<div>${L('Laatste')}: <b>${clockText(seen)}</b> <span class="muted">· ${L('{d} geleden', { d: durText(since) })}</span></div>`
    + `<div>${L('Volgende')}: ${since > stale ? nextTxt : `<b>${clockText(next)}</b> <span class="muted">·</span> ${nextTxt}`}${rt && since <= stale ? ` <span class="muted">· ${L('of eerder bij een gebeurtenis ⚡')}</span>` : ''}</div>`;
}
let contactTick = 0;
setInterval(async () => {
  const els = $$('.contact-live');
  if (!els.length) return;
  // Elke 5 seconden de laatste contacten ophalen (ook als er een venster openstaat en het dashboard niet ververst)
  if (++contactTick % 5 === 0 && !document.hidden) {
    try {
      const r = await api('contacts');
      syncClock(r.now_ts);
      els.forEach((el) => { const c = r.hosts[el.dataset.host]; if (c && c.seen) { el.dataset.seen = c.seen; el.dataset.every = contactEvery(c); } });
    } catch (e) { /* volgende keer */ }
  }
  els.forEach((el) => {
    const d = el.dataset;
    el.innerHTML = contactText(+d.seen, +d.every, +d.stale, d.rt === '1', d.mode);
  });
}, 1000);

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
      const r = await api('pw_reset', { token, password: resetForm.password.value, code: resetForm.code.value });
      $('#reset-fields').hidden = true;
      err.hidden = true;
      $('#reset-status').hidden = false;
      $('#reset-status').innerHTML = '<b class="good-txt">✔ ' + L('Je wachtwoord is gewijzigd.') + '</b> ' + L('Log nu in met je nieuwe wachtwoord.')
        + (r.pwned > 0 ? '<br><span class="warn-txt">⚠️ ' + L('Dit wachtwoord staat {n}× in datalekken. Bij het inloggen moet dan eerst een ander worden gekozen.', { n: (+r.pwned).toLocaleString(LOCALE) }) + '</span>' : '');
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      if (ex.data?.dead) $('#reset-fields').hidden = true;
    }
    b.disabled = false;
  });
}

// Wachtwoord staat in een datalek: eerst een nieuw kiezen (een gelekt wachtwoord wordt hier geweigerd)
const pwForce = $('#pwforce-form');
if (pwForce) {
  const err = $('.form-error', pwForce);
  pwForce.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (pwForce.password.value !== pwForce.password2.value) { err.textContent = L('De twee wachtwoorden zijn niet gelijk'); err.hidden = false; return; }
    const b = $('button.primary', pwForce);
    b.disabled = true;
    try { await api('pw_forced', { new: pwForce.password.value }); toast(L('Wachtwoord gewijzigd')); location.replace(location.pathname); }
    catch (ex) { err.textContent = ex.message; err.hidden = false; b.disabled = false; }
  });
  $('#pwforce-logout').onclick = async (e) => { e.preventDefault(); try { await api('logout', {}); } catch (ex) { /* toch weg */ } location.replace(location.pathname + '?login=1'); };
}

// ---------------------------------------------------------------- Login
/** base64url ↔ ArrayBuffer (WebAuthn) */
const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const unb64u = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0)).buffer;
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
  // Inloggen met een passkey (1.86.0): Face ID, vingerafdruk of beveiligingssleutel, in plaats van wachtwoord en 2FA
  const pkBtn = $('#login-passkey');
  if (pkBtn && window.PublicKeyCredential) {
    pkBtn.hidden = false;
    pkBtn.onclick = async () => {
      const err = $('.form-error', loginForm);
      err.hidden = true;
      try {
        const o = await api('passkey_login_options', {});
        const cred = await navigator.credentials.get({ publicKey: { ...o, challenge: unb64u(o.challenge) } });
        const r = cred.response;
        await api('passkey_login', { id: cred.id, clientDataJSON: b64u(r.clientDataJSON), authenticatorData: b64u(r.authenticatorData),
          signature: b64u(r.signature), userHandle: r.userHandle ? b64u(r.userHandle) : null, remember: loginForm.remember.checked });
        location.replace(location.pathname);
      } catch (ex) {
        if (ex.name === 'NotAllowedError' || ex.name === 'AbortError') return;   // zelf geannuleerd
        err.textContent = ex.message;
        err.hidden = false;
      }
    };
  }
} else if (!resetForm && !pwForce) {
  // Pas starten als het hele script geladen is: opent de pagina direct op een tabblad (bv. #settings),
  // dan gebruikt dat ook constanten die verderop in dit bestand staan
  queueMicrotask(init);
}

// ---------------------------------------------------------------- App
function init() {
  if ($('#vuln-search')) initVulns();
  if ($('#net-search')) initNet();
  if ($('#an-body')) initAnalytics();
  if ($('#leak-list')) initLeaks();
  $('#netlinks-test').onclick = async (e) => {   // 1.104.0: UniFi en AdGuard Home
    const btn = e.currentTarget, box = $('#netlinks-result');
    btn.disabled = true;
    box.innerHTML = `<span class="muted">⏳ ${L('Testen…')}</span>`;
    try {
      const r = await api('net_links_test', {});
      const line = (x, lbl) => `<div class="${x.ok ? 'good-txt' : 'bad'}">${x.ok ? '✔' : '✘'} <b>${lbl}</b>: ${esc(x.msg)}</div>`;
      box.innerHTML = [r.unifi ? line(r.unifi, 'UniFi') : '', ...(r.adguard || []).map((a) => line(a, 'AdGuard'))].join('') || `<span class="muted">${L('Nog niets ingevuld.')}</span>`;
    } catch (ex) { box.innerHTML = `<span class="bad">${esc(ex.message)}</span>`; }
    btn.disabled = false;
  };
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
  initCmdk();
  if ($('#advice-run')) $('#advice-run').onclick = async (e) => {
    e.target.disabled = true;
    try { const r = await api('advice_run', {}); toast(L('{n} nieuwe voorstel(len)', { n: r.n })); loadAdvice(); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  if ($('#logout')) $('#logout').onclick = async () => { await api('logout', {}); location.reload(); };
  $('#add-host').onclick = () => hostForm();
  $('#run-all').onclick = async (e) => {
    e.target.disabled = true;
    try { const r = await api('run_all', {}); toast(L('{n} checks uitgevoerd', { n: r.count })); await refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#filter').addEventListener('input', render);
  const setDashFilter = (k) => { dashFilter = k; savePref('dash_filter', k); render(); };
  $('#dash-chips').addEventListener('click', (e) => {
    const b = e.target.closest('[data-dfilter]');
    if (b) { statusFilter = null; setDashFilter(b.dataset.dfilter); }
  });
  $('#dash-sort').onchange = (e) => { dashSort = e.target.value; try { localStorage.setItem('rzmon-dash-sort', dashSort); } catch (ex) {} render(); };
  $('#health-counts').addEventListener('click', (e) => {
    const b = e.target.closest('[data-st]');
    if (!b) return;
    const st = +b.dataset.st;
    statusFilter = statusFilter === st ? null : st;
    if (statusFilter !== null) dashFilter = 'all';
    render();
    if (statusFilter !== null) $('#hosts').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  $('#health').addEventListener('click', (e) => {
    const b = e.target.closest('[data-host]');
    if (b) return jumpToHost(b.dataset.host);
    if (e.target.closest('[data-kpi=problems]')) { statusFilter = null; setDashFilter(dashFilter === 'problems' ? 'all' : 'problems'); }
  });
  $('#dash-attn').addEventListener('click', (e) => {
    const host = e.target.closest('[data-host]');
    if (host) return jumpToHost(host.dataset.host);
    if (e.target.closest('[data-attn-more]')) { attnOpen = !attnOpen; return renderAttention(); }
    if (e.target.closest('[data-attn-acked]')) { statusFilter = null; return setDashFilter('problems'); }
    onHostsClick(e);
  });
  // Menu's ⋯ (dashboard: onderhoud, alles nu checken, alles openen; andere pagina's: alles openen e.d.)
  // Menu ⋯: standaard rechts uitgelijnd (klapt naar links uit); past dat niet op het scherm (de knop staat links,
  // bv. als de balk op een smal scherm doorloopt), dan naar rechts uitklappen
  const menuOpen = (btn, on) => {
    const m = btn.nextElementSibling;
    m.hidden = !on;
    btn.setAttribute('aria-expanded', on);
    if (!on) return;
    m.classList.remove('to-right');
    if (m.getBoundingClientRect().left < 8) m.classList.add('to-right');
  };
  $$('[data-menu-btn]').forEach((btn) => {
    btn.onclick = (e) => { e.stopPropagation(); const on = btn.nextElementSibling.hidden; $$('[data-menu-btn]').forEach((x) => menuOpen(x, false)); menuOpen(btn, on); };
    btn.nextElementSibling.addEventListener('click', () => menuOpen(btn, false));
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.dash-more')) $$('[data-menu-btn]').forEach((x) => menuOpen(x, false)); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') $$('[data-menu-btn]').forEach((x) => menuOpen(x, false)); });
  // De tellerbalk blijft onder de header staan: hoe hoog is de header?
  const topH = () => document.documentElement.style.setProperty('--top-h', ($('.topbar') ? $('.topbar').offsetHeight : 56) + 'px');
  topH(); window.addEventListener('resize', topH);
  $('#hosts').addEventListener('click', onHostsClick);
  $('#hosts').addEventListener('keydown', (e) => {
    const head = e.target.closest('.host-head[data-act=toggle], .line-row[data-act=toggle], .ok-row[data-act=toggle]');
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
  $('#update-start').onclick = () => openUpdateDialog();
  $('#update-pick').onclick = () => openUpdateDialog(true);
  // Kwetsbaarheden aan/uit: meteen opslaan (zoals het IDS); het tabblad komt erbij of gaat weg, dus de pagina herladen
  $('#vuln-enabled').onchange = async (e) => {
    try {
      await api('vuln_set', { enabled: e.target.checked });
      toast(e.target.checked ? L('Kwetsbaarheden aangezet') : L('Kwetsbaarheden uitgezet'));
      setTimeout(() => location.reload(), 700);
    } catch (ex) { toast(ex.message, 'err'); e.target.checked = !e.target.checked; }
  };
  // Bekende aanvallers en land per IP (1.82.0)
  const ipintelShow = (s) => {
    $('#ipintel-status').innerHTML = [
      s.bl_at ? `☠️ ${L('{n} bereiken met bekende aanvallers, bijgewerkt {t}', { n: (+s.bl_n).toLocaleString(LOCALE), t: esc(fmtDateTime(s.bl_at)) })}` : `☠️ ${L('nog niet opgehaald')}`,
      s.bl_error ? `<span class="warn-txt">${esc(s.bl_error)}</span>` : '',
      s.geo_at ? `🌍 ${L('{n} bereiken met een land, bijgewerkt {t}', { n: (+s.geo_n).toLocaleString(LOCALE), t: esc(fmtDateTime(s.geo_at)) })}` : `🌍 ${L('nog niet opgehaald')}`,
      s.geo_error ? `<span class="warn-txt">${esc(s.geo_error)}</span>` : '',
    ].filter(Boolean).join(' · ');
  };
  api('ipintel_status').then(ipintelShow).catch(() => {});
  // Dreigingsinformatie (1.112.0): stand, sleutels testen, lijsten bijwerken
  const tiShow = (s) => {
    const prov = { abuse: 'AbuseIPDB', greynoise: 'GreyNoise', shodan: 'Shodan', circl: 'CIRCL', bazaar: 'MalwareBazaar', report: L('gemeld') };
    $('#ti-status').innerHTML = [
      s.lists_at ? L('Lijsten bijgewerkt {t}: {list}', { t: esc(fmtDateTime(s.lists_at)), list: esc(Object.entries(s.lists).map(([k, n]) => `${k} ${(+n).toLocaleString(LOCALE)}`).join(', ') || '–') }) : L('Lijsten nog niet opgehaald'),
      s.lists_error ? `<span class="warn-txt">${esc(s.lists_error)}</span>` : '',
      Object.keys(s.used).length ? L('Vandaag: {list}', { list: esc(Object.entries(s.used).map(([k, n]) => `${prov[k] || k} ${n}/${s.limits[k]}`).join(', ')) }) : '',
      s.malicious ? L('{n} bekende aanvallers in 7 dagen', { n: s.malicious }) : '',
      s.reported ? L('{n} aanvallers gemeld bij AbuseIPDB', { n: s.reported }) : '',
      ...Object.entries(s.errors || {}).map(([k, e]) => `<span class="warn-txt">${esc(prov[k] || k)}: ${esc(e.msg)} (${esc(fmtDateTime(e.at))})</span>`),
    ].filter(Boolean).join(' · ');
    const dn = s.dns || {};
    $('#dns-status').innerHTML = !dn.adguard ? `<span class="warn-txt">${L('Nog geen AdGuard gekoppeld (Instellingen → Koppelingen → AdGuard).')}</span>`
      : [dn.at ? L('Laatst gekeken {t}', { t: esc(fmtDateTime(dn.at)) }) : L('Nog niet gekeken'), L('vandaag {n} DNS-vragen, {h} verdacht', { n: (+dn.n).toLocaleString(LOCALE), h: +dn.hits }),
        dn.error ? `<span class="warn-txt">${esc(dn.error)}</span>` : ''].filter(Boolean).join(' · ');
  };
  const tiLoad = () => api('ti_status').then(tiShow).catch(() => {});
  tiLoad();
  $('#ids-ti-card').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-ti-test]');
    if (!b) return;
    const res = $(`[data-ti-res="${b.dataset.tiTest}"]`);
    b.disabled = true;
    res.textContent = '⏳';
    try { const r = await api('ti_test', { p: b.dataset.tiTest }); res.innerHTML = r.ok ? `<span class="good">✔ ${esc(r.msg)}</span>` : `<span class="bad">✘ ${esc(r.msg)}</span>`; } catch (ex) { res.textContent = ex.message; }
    b.disabled = false;
  });
  $('#ti-lists').onclick = async (e) => {
    e.target.disabled = true;
    toast(L('Bijwerken… (kan een minuut duren)'));
    try { const r = await api('ti_lists', {}); toast(r.msg); tiLoad(); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#ipintel-update').onclick = async (e) => {
    e.target.disabled = true;
    toast(L('Bijwerken… (het land kan een minuut duren)'));
    try { const r = await api('ipintel_update', { geo: true }); toast(r.result); ipintelShow(r); } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
  };
  $('#ids-weekly-test').onclick = async (e) => {
    e.target.disabled = true;
    try {
      const r = await api('ids_weekly_test', {});
      toast(r.result);
      modal({ title: '📬 ' + L('Wekelijks overzicht'), body: `<pre class="code" style="white-space:pre-wrap">${esc(r.text)}</pre>`, buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }] });
    } catch (ex) { toast(ex.message, 'err'); }
    e.target.disabled = false;
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
    const b = e.target.closest('[data-if], [data-icat], [data-icard-off]');
    if (!b) return;
    if (b.dataset.icardOff) idsCard = '';
    else if (b.dataset.if) { idsFilter = b.dataset.if; idsCat = ''; } else idsCat = idsCat === b.dataset.icat ? '' : b.dataset.icat;
    renderIds();
  };
  let idsSearchT;
  $('#ids-search').addEventListener('input', () => { clearTimeout(idsSearchT); idsSearchT = setTimeout(renderIds, 200); });
  $('#acc-search').addEventListener('input', () => { clearTimeout(idsSearchT); idsSearchT = setTimeout(renderAccess, 200); });
  const idsGo = (e) => {
    const b = e.target.closest('[data-igo]');
    if (!b) return;
    idsTab = b.dataset.igo;
    if (idsTab === 'acc') { accFilter = b.dataset.fail ? 'fail' : 'all'; $$('#acc-filter .seg-btn').forEach((x) => { x.classList.toggle('active', x.dataset.ac === accFilter); x.classList.toggle('on', x.dataset.ac === accFilter); }); renderAccess(); }
    if (idsTab === 'inc') { idsFilter = 'open'; idsCat = ''; renderIds(); }
    showIdsTab();
  };
  $('#ids-counts').addEventListener('click', idsGo);
  $('#ids-kpis').addEventListener('click', idsGo);
  $('#ids-toggle').onclick = () => {
    const days = $$('#ids-incidents .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-block-add').onclick = () => idsBlockDialog();
  $('#anom-host').onchange = () => loadAnom();
  $('#anom-days').onclick = (e) => {
    const b = e.target.closest('[data-ad]');
    if (!b) return;
    anomDays = +b.dataset.ad;
    $$('#anom-days .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    loadAnom();
  };
  $('#anom-learn').onclick = async (e) => {
    const b = e.target.closest('[data-learn]');
    if (!b) return;
    const finish = b.dataset.learn === 'finish';
    if (!confirm(finish ? L('Leren nu afronden? Vanaf nu meldt RzMon alles wat deze server nog niet deed.') : L('Opnieuw leren? Alles wat RzMon over deze server geleerd heeft, gaat weg; de komende dagen wordt er niets als afwijking gemeld.'))) return;
    try { await api('edr_learn', { host_id: +b.dataset.h, action: b.dataset.learn }); toast(finish ? L('Leren afgerond') : L('De server leert opnieuw')); loadAnom(); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#prot-cards').onchange = async (e) => {
    const c = e.target.closest('[data-prot-toggle]');
    if (!c) return;
    try { await api('settings_save', { [c.dataset.protToggle]: c.checked ? '1' : '0' }); toast(c.checked ? L('Aangezet') : L('Uitgezet')); loadProt(); } catch (ex) { toast(ex.message, 'err'); c.checked = !c.checked; }
  };
  $('#prot-cards').onclick = (e) => {
    const cfg = e.target.closest('[data-prot-cfg]');
    if (cfg) return protCfgDialog(cfg.dataset.protCfg);
    const b = e.target.closest('[data-prot-show]');
    if (!b) return;
    idsCard = b.dataset.protShow; idsFilter = 'all'; idsCat = '';
    showTab('ids/inc');
  };
  $('#mitre-host').onchange = () => loadMitre();
  $('#mitre-cover').onchange = () => renderMitre();
  $('#mitre-days').onclick = (e) => {
    const b = e.target.closest('[data-md]');
    if (!b) return;
    mitreDays = +b.dataset.md;
    $$('#mitre-days .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    loadMitre();
  };
  $('#mitre-matrix').onclick = (e) => {
    const b = e.target.closest('[data-mt]');
    if (b) mitreSignals(b.dataset.mt, b.dataset.ta);
  };
  $('#edr-host').onchange = () => loadEdrFeed();
  $('#edr-hits').onchange = () => loadEdrFeed();
  $('#edr-own').onchange = () => loadEdrFeed();
  $('#rule-q').oninput = () => renderEdrRules();
  $('#rule-src').onclick = (e) => {
    const b = e.target.closest('[data-rs]');
    if (!b) return;
    ruleSrc = b.dataset.rs;
    $$('#rule-src .seg-btn').forEach((x) => x.classList.toggle('active', x === b));
    renderEdrRules();
  };
  $('#edr-rules').onchange = async (e) => {
    const c = e.target.closest('[data-rule]');
    if (!c) return;
    try { await api('edr_rule_set', { id: c.dataset.rule, on: c.checked }); toast(c.checked ? L('Regel aan') : L('Regel uit')); loadEdrRules(); } catch (ex) { toast(ex.message, 'err'); c.checked = !c.checked; }
  };
  $('#ids-tabs').onclick = (e) => {
    const b = e.target.closest('[data-it]');
    if (!b) return;
    idsTab = b.dataset.it;
    try { localStorage.setItem('rzmon-ids-tab', idsTab); } catch (ex) { /* alleen voor nu */ }
    showIdsTab();
    fxSyncHash();
  };
  $('#acc-filter').onclick = (e) => {
    const b = e.target.closest('[data-ac]');
    if (!b) return;
    accFilter = b.dataset.ac;
    $$('#acc-filter .seg-btn').forEach((x) => { x.classList.toggle('active', x === b); x.classList.toggle('on', x === b); });
    renderAccess();
  };
  $('#acc-host').onchange = (e) => { accHost = e.target.value; renderAccess(); };
  $('#acc-toggle').onclick = () => {
    const days = $$('#ids-access .alert-day');
    const all = days.every((x) => x.open);
    days.forEach((x) => { x.open = !all; });
  };
  $('#ids-access').addEventListener('click', (e) => {
    if (toggleBundle(e)) return;
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
    if (e.target.closest('#ids-incidents') && toggleBundle(e)) return;
    const hsb = e.target.closest('[data-hostsec]');
    if (hsb) return hostSecDialog(+hsb.dataset.hostsec);
    if (e.target.closest('[data-ids-attn-more]')) { idsAttnOpen = !idsAttnOpen; return renderIdsAttention(idsData.incidents.filter((i) => i.status === 'open')); }
    const ih = e.target.closest('[data-ihost]');
    if (ih) { const h = idsData.hosts.find((x) => +x.id === +ih.dataset.ihost); idsFilter = 'open'; idsCat = ''; $('#ids-search').value = h ? h.name : ''; showTab('ids/inc'); renderIds(); return $('#ids-incidents').scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    const io = e.target.closest('[data-inc]');
    if (io) return incidentDialog(+io.dataset.inc, io.dataset.incList ? io.dataset.incList.split(',').map(Number) : null);
    const b = e.target.closest('[data-ids], [data-unblock], [data-rebase]');
    if (!b) return;
    try {
      if (b.dataset.ids === 'block') return idsBlockDialog(b.dataset.ip, b.dataset.host);
      if (b.dataset.ids === 'release') return wlDialog({ type: 'ip', value: b.dataset.ip, src: 'ids', wl: false });
      // Een bundel in één keer: elk incident apart afhandelen
      if (b.dataset.many) { for (const id of b.dataset.many.split(',')) await api('ids_incident', { id: +id, action: b.dataset.ids }); }
      else if (b.dataset.ids) await api('ids_incident', { id: +b.dataset.id, action: b.dataset.ids });
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
    // Gebundelde herhalingen (5×) uitklappen
    if (toggleBundle(e, alertBundles)) return;
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
  const setAlertFilter = (f) => {
    alertFilter = alertFilter === f && f !== 'all' ? 'all' : f;
    try { localStorage.setItem('rzmon-alert-filter', alertFilter); } catch (ex) { /* alleen voor nu */ }
    savePref('alert_filter', alertFilter);
    renderAlerts();
  };
  $('#alerts-filter').addEventListener('click', (e) => { const b = e.target.closest('[data-af]'); if (b) setAlertFilter(b.dataset.af); });
  $('#alerts-counts').addEventListener('click', (e) => { const b = e.target.closest('[data-af]'); if (b) setAlertFilter(b.dataset.af); });
  let alertSearchT;
  $('#alerts-search').addEventListener('input', () => { clearTimeout(alertSearchT); alertSearchT = setTimeout(renderAlerts, 200); });
  $('#alerts-host').onchange = renderAlerts;
  $('#alerts-kpis').addEventListener('click', (e) => {
    const go = e.target.closest('[data-go]');
    if (go) showTab(go.dataset.go);
  });
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
  // Bij openen: alles dicht of slim openen (voor je account, 1.71.0)
  $$('[data-view-open]').forEach((b) => (b.onclick = () => {
    savePref('open_mode', b.dataset.viewOpen);
    showViewPrefs();
    toast(b.dataset.viewOpen === 'smart' ? L('Slim openen: vandaag en ernstige dingen staan open') : L('Bij openen staat alles dicht, behalve vandaag'));
  }));
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
  initTemplates();
  initAllChecks();
  initSystemTools();
  $('#api-keys').addEventListener('click', apiKeyClick);
  $('#access-form').addEventListener('submit', saveAccess);
  $('#access-form').addEventListener('change', (e) => saveAccess(null, e.target));
  initHttps();
  initHibp();

  refresh();
  // 1.107.0: live bijwerken. Elke 3 s een klein kenmerk ophalen (statuswissels, meldingen, nieuwe meetwaarden,
  // bevestigd, onderhoud, wijzigingen); verandert het, dan de stand ophalen. Licht voor de server: geen open
  // verbindingen die PHP bezet houden. 1.109.0: alleen wat veranderde wordt bijgewerkt (morph), dus ook met een
  // venster open; dat venster werkt zelf mee (liveModal).
  let liveRev = null, liveParts = {}, liveFail = 0, liveFull = Date.now(), liveBusy = false;
  setInterval(async () => {
    if (document.hidden || liveBusy) return;
    liveBusy = true;
    try {
      const r = await api('rev');
      liveFail = 0;
      $('#live-dot')?.classList.remove('off');
      // 1.119.0: alleen herladen wat veranderde: meldingen, IDS en de live-feed elk apart
      const p = r.parts || {}, was = liveParts, first = liveRev === null;
      const what = { alerts: !first && p.a !== was.a, ids: !first && p.i !== was.i };
      const full = Date.now() - liveFull > 60000;
      if ((!first && (r.rev !== liveRev || what.alerts || what.ids)) || full) {
        if (full) liveFull = Date.now();
        await refresh(true, what);
        liveModal();
      } else if (!first && p.e !== was.e && !$('#tab-ids').hidden && idsTab === 'live') {
        loadEdrFeed();   // alleen nieuwe gebeurtenissen: alleen de feed
      }
      liveRev = r.rev;
      liveParts = p;
    } catch (e) {
      if (++liveFail >= 3) $('#live-dot')?.classList.add('off');
    } finally {
      liveBusy = false;
    }
  }, 3000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

function showTab(name) {
  let sub = null;
  // Filters in het adres (1.71.0): #alerts?server=NAS,Pi&status=crit, #ids/acc?result=fail, #vulns/leak?status=open
  const qi = name.indexOf('?');
  const qs = qi >= 0 ? name.slice(qi + 1) : '';
  if (qi >= 0) name = name.slice(0, qi);
  if (name === 'settings/blocks') name = 'ids/blk';   // 1.92.0: blokkades en whitelist staan in het IDS-tabblad
  if (/^(ids|vulns)\//.test(name)) {
    const [tab, s] = name.split('/');
    name = tab;
    if (tab === 'ids' && ['inc', 'acc', 'blk', 'prot', 'live', 'rules', 'anom', 'mitre'].includes(s)) idsTab = s;
    if (tab === 'vulns' && ['cve', 'leak'].includes(s)) vulnTab = s;
  }
  if (qs) {
    const page = { dashboard: 'dash', alerts: 'alerts', ids: { inc: 'ids', acc: 'acc', blk: 'blk' }[idsTab], vulns: vulnTab === 'leak' ? 'leaks' : 'vulns' }[name];
    if (page) fxFromQuery(page, qs);
  }
  if (name.startsWith('settings/')) { sub = name.slice(9); name = 'settings'; }
  if (name === 'settings' && !isAdmin()) name = $('#tab-account') ? 'account' : 'dashboard';   // instellingen: alleen beheerders
  if (!$('#tab-' + name)) name = 'dashboard';
  $$('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
  moveTabIndicator();
  $$('.tab-panel').forEach((p) => (p.hidden = p.id !== 'tab-' + name));
  history.replaceState(null, '', '#' + name);
  fxSyncHash();
  if (name === 'settings') { loadSettings(); showSettingsSection(sub || setSection); }
  if (name === 'account') loadAccount();
  if (name === 'alerts') loadAlerts();
  if ((name === 'dashboard' || name === 'alerts') && state.badges) refresh();   // tab gezien: teller naar 0
  if (name === 'ids') loadIds();
  if (name === 'net') loadNet();
  if (name === 'analytics') loadAnalytics();
  if (name === 'vulns') { showVulnTab(); if ($('#vuln-search')) loadVulns(); if ($('#leak-list')) loadLeaks(); }
}

/** Het open venster live bijwerken (1.109.0): het venster van een check; de Agent Manager doet dat zelf al */
function liveModal() {
  const dlg = $('#modal');
  if (!dlg.open || !dlg.dataset.live) return;
  const [kind, id] = dlg.dataset.live.split(':');
  if (kind === 'check') checkInfo(id, true);
}

/** Nieuwe problemen (er ging echt een melding uit: melden na N gehaald) als klikbaar meldinkje rechtsonder */
function liveToasts(prev) {
  if (!prev) return;
  const fresh = [];
  state.hosts.forEach((h) => h.checks.forEach((c) => {
    const p = prev.get(+c.id);
    const s = +c.notified_status;
    if (p === undefined || !+c.enabled || +c.info_only || c.maintenance || c.acked_at || !(s === 1 || s === 2) || p === s || +c.status !== s) return;
    fresh.push([c, h]);
  }));
  if (fresh.length > 3) return toast(L('{n} nieuwe problemen', { n: fresh.length }), 'err', () => showTab('dashboard'));
  fresh.forEach(([c, h]) => toast(`${ST_ICON[+c.status]} ${h.name} – ${c.name}: ${STATUS[+c.status]}`, +c.status === 2 ? 'err' : 'warn', () => checkInfo(c.id)));
}

let statsAt = 0;
async function refresh(live = false, what = null) {
  try {
    // Openstaande tab (Dashboard of Meldingen) telt als gezien, maar alleen als je echt kijkt
    const seen = document.hidden ? '' : ['dashboard', 'alerts'].find((t) => !$('#tab-' + t).hidden) || '';
    const prev = live && state && state.hosts ? new Map(state.hosts.flatMap((h) => h.checks.map((c) => [+c.id, +c.notified_status]))) : null;
    state = await api('state', null, seen ? { seen } : {});
    syncClock(state.now_ts);
    // Info-check: telt nooit als storing. Voor tellers, filters en "Problemen" is hij OK; de echte status staat in info_status
    state.hosts.forEach((h) => h.checks.forEach((c) => { if (+c.info_only && +c.status !== 0) { c.info_status = +c.status; c.status = 0; } }));
    if (state.role && document.body.dataset.role !== state.role) location.reload();   // rol gewijzigd
    render();
    liveToasts(prev);
    // Live: de meldingen en de IDS-pagina alleen als daar iets veranderde (anders na hooguit 5 minuten)
    const stale = (k) => !what || what[k] || Date.now() - (liveLoaded[k] || 0) > 300000;
    if (!$('#tab-alerts').hidden && stale('alerts')) { liveLoaded.alerts = Date.now(); loadAlerts(); }
    if (!$('#tab-ids').hidden && stale('ids')) { liveLoaded.ids = Date.now(); loadIds(); }
    if ($('#tab-vulns') && !$('#tab-vulns').hidden) loadVulns();
    if (live && $('#tab-net') && !$('#tab-net').hidden) loadNet();
    renderTabBadges();
    if ($('#net-badge')) { $('#net-badge').hidden = !state.net_new; $('#net-badge').textContent = state.net_new || ''; }
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
  // Beschikbaarheid en minigrafieken apart: mag iets langer duren; live hooguit eens per 30 s
  if (live && Date.now() - statsAt < 30000) return;
  try { stats = await api('stats'); statsAt = Date.now(); render(); } catch (ex) { /* niet kritiek */ }
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

// Filterchips en sortering van het dashboard (per apparaat onthouden)
// Keuze bovenaan (Alle, Problemen, of een soort apparaat): per gebruiker (1.75.0), daarvoor per browser
let dashFilter = PREFS.dash_filter || (() => { try { return localStorage.getItem('rzmon-dash-filter') || 'all'; } catch (e) { return 'all'; } })();
let dashSort = (() => { try { return localStorage.getItem('rzmon-dash-sort') || 'status'; } catch (e) { return 'status'; } })();
let attnOpen = false;   // "Nu aandacht nodig": alles tonen (anders 6, op de telefoon 3)
const isHa = (h) => h.checks.some((c) => c.type === 'ha') || h.metrics.includes('service:homeassistant');
// Soort apparaat (1.75.0; zie HostKind.php): zelf gekozen, anders herkend (agent, SNMP), anders op het type
const KIND_INFO = {
  server: ['🖥️', () => L('Server')], router: ['🌐', () => L('Router')], switch: ['🔀', () => L('Switch')], ap: ['📶', () => L('Access point')],
  firewall: ['🧱', () => L('Firewall')], nas: ['🗄️', () => 'NAS'], ups: ['🔋', () => 'UPS'], camera: ['📷', () => L('Camera')], smarthome: ['🏠', () => L('Smart home')],
  printer: ['🖨️', () => L('Printer')], other: ['📦', () => L('Overig')],
};
const KIND_GROUP = { server: 'server', router: 'network', switch: 'network', ap: 'network', firewall: 'network', nas: 'storage', ups: 'power',
  camera: 'camera', smarthome: 'smarthome', printer: 'other', other: 'other' };
const GROUP_INFO = {
  server: ['🖥️', () => L('Servers')], network: ['🌐', () => L('Netwerk')], storage: ['🗄️', () => L('Opslag')], power: ['🔋', () => L('Stroom')],
  camera: ['📷', () => L('Camera\'s')], smarthome: ['🏠', () => L('Smart home')], other: ['📦', () => L('Overig')],
};
// Zelfde regel als HostKind::CAMERA_NAME: een naam als "Cam voordeur" of "IPCam2" is vanzelf een camera
const CAMERA_NAME = /\b(ip)?cam(era)?s?[-_ ]?\d*\b|cctv|deurbel|doorbell/i;
const hostKind = (h) => [h.kind, h.kind_auto].find((k) => k && KIND_INFO[k])
  || (isHa(h) ? 'smarthome' : ['agent', 'local'].includes(h.mode) ? 'server' : CAMERA_NAME.test(h.name || '') ? 'camera' : 'other');
const hostGroup = (h) => KIND_GROUP[hostKind(h)];
const kindLabel = (k) => `${KIND_INFO[k][0]} ${KIND_INFO[k][1]()}`;
/** Soort apparaat van een server op naam (meldingen en IDS kennen alleen de naam) */
const kindByName = (name) => { const h = state && state.hosts.find((x) => x.name === name); return h ? hostKind(h) : null; };
/** Icoon voor de soort apparaat */
const hostIcon = (h) => KIND_INFO[hostKind(h)][0];
const DASH_FILTERS = [
  ['all', () => L('Alle'), () => true],
  ['problems', () => L('Problemen'), (h) => +h.enabled && h.checks.some(isProblem)],
  ['maint', () => L('Onderhoud'), (h) => !!h.maintenance || h.checks.some((c) => c.maintenance)],
  ...Object.keys(GROUP_INFO).map((g) => ['g:' + g, () => `${GROUP_INFO[g][0]} ${GROUP_INFO[g][1]()}`, (h) => hostGroup(h) === g]),
];
if (!DASH_FILTERS.some((f) => f[0] === dashFilter)) dashFilter = 'all';   // bv. 'agent' van vóór 1.75.0
/** Gekozen soort op het dashboard (of null bij Alle, Problemen, Onderhoud) */
const dashGroup = () => (dashFilter.startsWith('g:') ? dashFilter.slice(2) : null);

/** Kerncijfers, tellers en "Nu aandacht nodig" bovenaan het dashboard. Toestand en score komen van de server (Health.php). */
function renderHealth() {
  const g = dashGroup();
  const hl = (g && state.health_groups && state.health_groups[g]) || state.health;
  if (!hl) return;
  const inGroup = (id) => !g || (findHost(id) && hostGroup(findHost(id)) === g);
  // Tellers (klikbaar: alleen die checks tonen)
  const WORD = { 0: 'OK', 1: 'Warning', 2: 'Critical', 3: L('Onbekend'), 4: L('Onderhoud') };
  $('#health-counts').innerHTML = HEALTH_COUNTS.map(([st, key]) => {
    const n = hl.counts[key];
    return `<button type="button" class="hc s${st} ${statusFilter === st ? 'on' : ''} ${n ? '' : 'zero'}" data-st="${st}"
      title="${n} check${n === 1 ? '' : 's'} ${HEALTH_TITLES[st]}${statusFilter === st ? ' – ' + L('klik om het filter op te heffen') : ' – ' + L('klik om alleen deze te tonen')}">
      <span class="hc-dot"></span><b data-flash>${n}</b> <span class="hc-w">${WORD[st]}</span></button>`;
  }).join('');

  // Kerncijfers als tegels
  const stateCls = { ok: 'ok', attention: 'warn', outage: 'bad' }[hl.state];
  const hostLink = (id, text) => `<button type="button" class="hl-link" data-host="${id}">${esc(text)}</button>`;
  const why = { agent: L('agent stil'), parent: L('achter uitgevallen server') };
  const downs = hl.down.map((d) => hostLink(d.id, d.name) + (why[d.reason] ? ` <span class="muted">(${why[d.reason]})</span>` : '') + (d.acknowledged ? ' ✔' : '')).join(', ');
  const nProb = hl.counts.critical + hl.counts.warning + hl.counts.unknown;
  const u = Object.entries(stats.uptime).filter(([id]) => inGroup(id)).map(([, x]) => x.h24).filter((v) => v !== null && v !== undefined);
  const avail = u.length ? u.reduce((a, b) => a + b, 0) / u.length : null;
  const o = hl.oldest_problem;
  const extra = [hl.quiet_until ? `🌙 ${L('stil tot {t}', { t: esc(fmtUntil(hl.quiet_until)) })}` : '',
    hl.flapping ? `🔀 ${hl.flapping} ${L('flapt')}` : '', hl.counts.maintenance ? `🔧 ${hl.counts.maintenance} ${L('in onderhoud')}` : ''].filter(Boolean).join(' · ');
  $('#health').className = 'kpis k-' + hl.state;
  $('#health').innerHTML = `
    <div class="kpi kpi-main ${stateCls}" data-kpi="problems" title="${L('Klik: alleen problemen tonen')}">
      <div class="ring" style="--p:${hl.score}"><span data-flash>${hl.score}%</span></div>
      <div class="kpi-txt"><div class="kpi-state">${{ ok: '🟢', attention: '🟠', outage: '🔴' }[hl.state]} ${L(hl.label)}</div>
        <div class="kpi-sub">${nProb ? [hl.counts.critical ? `${hl.counts.critical} critical` : '', hl.counts.warning ? `${hl.counts.warning} warning` : '', hl.counts.unknown ? L('{n} onbekend', { n: hl.counts.unknown }) : ''].filter(Boolean).join(' · ') : L('Geen problemen')}</div>
        ${extra ? `<div class="kpi-sub">${extra}</div>` : ''}</div></div>
    <div class="kpi ${hl.down.length ? 'bad' : ''}" title="${L('Servers die reageren (ping, of de agent rapporteert)')}">
      <div class="kpi-lbl">${!g || g === 'server' ? L('Servers bereikbaar') : L('Apparaten bereikbaar')}</div><div class="kpi-val">${hl.hosts_up}<small>/${hl.hosts_total}</small></div>
      <div class="kpi-sub">${downs ? `${L('onbereikbaar')}: ${downs}` : L('allemaal bereikbaar')}</div></div>
    <div class="kpi" title="${L('Actieve checks met status OK (checks in onderhoud tellen niet mee)')}">
      <div class="kpi-lbl">${L('Checks OK')}</div><div class="kpi-val">${hl.checks_ok}<small>/${hl.checks_total}</small></div>
      <div class="kpi-sub">${hl.checks_total ? pct(hl.checks_ok / hl.checks_total * 100) : '–'}</div></div>
    <div class="kpi ${hl.open_problems ? 'bad' : ''}" title="${L('Problemen die nog niet bevestigd zijn')}">
      <div class="kpi-lbl">${L('Open problemen')}</div><div class="kpi-val">${hl.open_problems}</div>
      <div class="kpi-sub">${o ? `${L('langst')}: ${hostLink(o.host_id, o.host + ' – ' + o.check)} (${dur(o.since)})` : L('niets open')}</div></div>
    <div class="kpi" title="${L('Gemiddelde bereikbaarheid van alle servers over de afgelopen 24 uur; het lijntje: het deel van de checks dat OK was')}">
      <div class="kpi-lbl">${L('Beschikbaar 24 u')}</div><div class="kpi-val">${avail === null ? '–' : pct(avail).replace('%', '<small>%</small>')}</div>
      ${healthSpark(inGroup)}</div>`;
  renderAttention();
}

/** Lijntje over 24 uur: per half uur het deel van alle checks dat OK was (uit de minigrafieken) */
function healthSpark(inGroup = () => true) {
  const hostOf = {};
  state.hosts.forEach((h) => h.checks.forEach((c) => { hostOf[c.id] = h.id; }));
  const rows = Object.entries(stats.sparks || {}).filter(([cid]) => inGroup(hostOf[cid])).map(([, r]) => r);
  if (!rows.length) return '';
  const pts = [];
  for (let i = 0; i < 48; i++) {
    let ok = 0, n = 0;
    rows.forEach((r) => { const d = r[i]; if (d && d[1] !== null && d[1] !== undefined) { n++; if (+d[1] === 0) ok++; } });
    pts.push(n ? ok / n : null);
  }
  const vals = pts.filter((v) => v !== null);
  if (vals.length < 2) return '';
  const min = Math.min(...vals), lo = Math.max(0, min - .05);
  const y = (v) => 24 - ((v - lo) / Math.max(.05, 1 - lo)) * 20;
  let d = '';
  pts.forEach((v, i) => { if (v !== null) d += `${d ? 'L' : 'M'}${(i / 47 * 100).toFixed(1)},${y(v).toFixed(1)}`; });
  const last = vals[vals.length - 1];
  const col = last >= .99 ? 'var(--ok)' : last >= .8 ? 'var(--warn)' : 'var(--crit)';
  return `<svg class="kpi-spark" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${d}L100,26L0,26Z" fill="${col}" opacity=".14"/><path d="${d}" fill="none" stroke="${col}" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>`;
}

/** Alle open problemen in één lijst, critical eerst; bevestigde staan er niet in (wel geteld) */
function renderAttention() {
  const all = [];
  const g = dashGroup();
  state.hosts.forEach((h) => +h.enabled && (!g || hostGroup(h) === g) && h.checks.forEach((c) => isProblem(c) && all.push([h, c])));
  const open = all.filter(([, c]) => !c.acked_at)
    .sort(([, a], [, b]) => STATUS_RANK[+b.status] - STATUS_RANK[+a.status] || String(a.problem_since || '').localeCompare(String(b.problem_since || '')));
  const acked = all.length - open.length;
  const box = $('#dash-attn');
  // Niets open: de tegel ernaast zegt al "Alles in orde". Alleen bevestigde problemen nog als klein regeltje (1.77.0).
  box.hidden = !open.length && !acked;
  if (!open.length) {
    box.innerHTML = acked ? `<div class="attn-acked">✔ ${L('Geen open problemen')} · ${acked === 1 ? L('1 bevestigd') : L('{n} bevestigd', { n: acked })}
      <button type="button" class="link-btn" data-attn-acked>${L('bekijken')}</button></div>` : '';
    return;
  }
  box.innerHTML = `<div class="sec-h"><h2>⚠️ ${L('Nu aandacht nodig')}</h2><span>${open.length === 1 ? L('1 probleem') : L('{n} problemen', { n: open.length })} · ${L('critical eerst')}${acked ? ' · ' + L('{n} bevestigd', { n: acked }) : ''}</span></div>
    <div class="attn ${attnOpen ? 'all' : ''}">${open.map(([h, c]) => `<div class="arow" data-cid="${c.id}">
      <i class="adot s${c.status}"></i>
      <button type="button" class="a-srv hl-link" data-host="${h.id}">${esc(h.name)}</button>
      <span class="a-chk">${esc(c.name)}</span>
      <span class="a-msg">${esc(c.last_message || '')}</span>
      <span class="a-since">${c.problem_since ? dur(c.problem_since) : ''}</span>
      <span class="a-acts"><button class="btn small-btn ack-btn need-user" data-act="ack" data-id="${c.id}" title="${L('Storing bevestigen: geen meldingen meer')}">✔ ${L('Bevestig')}</button><button class="btn small-btn need-user" data-act="maint-check" data-id="${c.id}" title="${L('Onderhoud')}">🔧</button></span>
    </div>`).join('')}
    ${open.length > 3 ? `<button type="button" class="attn-more ${open.length > 6 ? '' : 'mob-only'}" data-attn-more>${attnOpen ? '▴ ' + L('Minder tonen') : '▾ ' + L('Alle {n} tonen', { n: open.length })}</button>` : ''}</div>`;
}

/** Naar een server springen en hem openklappen (vanuit de kerncijfers of de aandachtlijst). */
function jumpToHost(id) {
  statusFilter = null;
  $('#filter').value = '';
  if (!DASH_FILTERS.find((f) => f[0] === dashFilter)[2](findHost(id))) dashFilter = 'all';
  openHosts.add(+id);
  saveOpen();
  render();
  const head = $(`#hosts [data-act=toggle][data-id="${id}"]`);
  if (head) {
    const art = head.closest('article');
    art.scrollIntoView({ behavior: 'smooth', block: 'center' });
    art.classList.add('flash');
    setTimeout(() => art.classList.remove('flash'), 1600);
  }
}

/** Dashboard tekenen; live: alleen wat veranderde (morph) */
function render() { withMorph(renderNow); }
function renderNow() {
  renderHealth();
  const banner = $('#maint-banner');
  banner.hidden = !state.maintenance;
  if (state.maintenance) {
    banner.innerHTML = `<span><b>🔧 ${L('Onderhoud actief')}</b> ${L('tot {t}', { t: esc(fmtUntil(state.maintenance.until)) })}${state.maintenance.reason ? ' – ' + esc(state.maintenance.reason) : ''}.
      ${L('Checks draaien door, maar er gaan geen meldingen uit.')}</span>
      <button class="btn need-user" data-maint-end="all">${L('Beëindigen')}</button>`;
  }
  $('#last-run').innerHTML = state.last_run ? esc(L('Laatste check')) + ' ' + agoSpan(state.last_run) : '';
  let open = 0;
  if (state.badges) open = state.badges.dashboard + state.badges.alerts;   // nieuwe problemen + nieuwe meldingen
  else state.hosts.forEach((h) => +h.enabled && h.checks.forEach((c) => +c.enabled && !c.maintenance && +c.status === 2 && !c.acked_at && open++));
  document.title = (open ? `(${open}) ` : '') + 'RzMon';

  // Filterchips: alleen soorten die er zijn, met hun aantal
  const chips = DASH_FILTERS.map(([k, label, fn]) => [k, label(), state.hosts.filter(fn).length]).filter(([k, , n]) => k === 'all' || k === 'problems' || n);
  if (!chips.some(([k]) => k === dashFilter)) dashFilter = 'all';
  const probIn = (k) => k.startsWith('g:') && state.hosts.some((h) => +h.enabled && hostGroup(h) === k.slice(2) && h.checks.some((c) => isProblem(c) && !c.acked_at));
  $('#dash-chips').innerHTML = chips.map(([k, l, n]) => `<button type="button" class="dchip ${dashFilter === k ? 'on' : ''}" data-dfilter="${k}">${esc(l)} <b>${n}</b>${probIn(k) ? '<i class="dchip-dot"></i>' : ''}</button>`).join('');
  $('#dash-sort').value = dashSort;

  const f = $('#filter').value.trim().toLowerCase();
  const onlyProblems = dashFilter === 'problems';
  const narrowed = onlyProblems || statusFilter !== null;
  const keep = DASH_FILTERS.find((x) => x[0] === dashFilter)[2];
  const byName = (a, b) => a.name.localeCompare(b.name, LANG, { numeric: true, sensitivity: 'base' });
  // Filters (1.71.0): een server telt mee als hij zelf past; met een filter op checks alleen de checks die passen
  const fxOn = fxActive('dash');
  const fxChecks = DASH_CHECK_FX.some((k) => fxHas('dash', k));
  const hosts = state.hosts.filter(keep)
    .filter((h) => !fxOn || (h.checks.length ? h.checks.some((c) => fxPass('dash', { h, c })) : fxPass('dash', { h, c: null })))
    .map((h) => {
      let checks = h.checks;
      const hostMatch = !f || (h.name + ' ' + h.address).toLowerCase().includes(f);
      if (!hostMatch) checks = checks.filter((c) => (c.name + ' ' + c.target).toLowerCase().includes(f));
      if (onlyProblems) checks = checks.filter((c) => +c.status !== 0 && +c.enabled);
      if (statusFilter !== null) checks = checks.filter((c) => +h.enabled && +c.enabled && (statusFilter === 4 ? !!c.maintenance : !c.maintenance && +c.status === statusFilter));
      if (fxChecks) checks = checks.filter((c) => fxPass('dash', { h, c }));
      return { ...h, shown: checks, match: hostMatch, autoOpen: ((!!f && !hostMatch) || fxChecks) && checks.length > 0 };
    })
    .filter((h) => (h.match && !narrowed && !fxChecks) || h.shown.length)
    .sort((a, b) => (dashSort === 'name' ? 0 : STATUS_RANK[hostStatus(b)] - STATUS_RANK[hostStatus(a)]) || byName(a, b));

  fxRender('dash');
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
  // Servers met problemen (of onderhoud, of nog zonder checks) als kaart of rij; servers waar alles OK is compact eronder
  const { layout, density } = viewPrefs();
  const okHost = (h) => +h.enabled && hostStatus(h) === 0 && !h.maintenance && !h.autoOpen;
  const head = (title, n) => `<div class="sec-h hosts-h"><h2>${title}</h2><span>${n}</span></div>`;
  const lines = layout === 'l1nes';
  const list = (arr) => (lines ? (density === 'large' ? lineHeader() : '') + arr.map((h) => hostLine(h, density === 'large')).join('')
    : arr.map((h) => hostCard(h, density === 'small')).join(''));
  const cls = `hosts layout-${layout} density-${density}`;
  const block = (arr, grouped) => {
    const bad = arr.filter((h) => !okHost(h)), good = arr.filter(okHost);
    return (bad.length ? (good.length ? head(grouped ? L('Met problemen') : L('Servers met problemen'), bad.length) : '') + `<div class="${cls}">${list(bad)}</div>` : '')
      + (good.length ? head('✔ ' + L('Alles in orde'), good.length === 1 ? L('1 apparaat') : L('{n} apparaten', { n: good.length }))
        + (lines ? `<div class="${cls}">${list(good)}</div>` : `<div class="ok-list density-${density}">${good.map(okRow).join('')}</div>`) : '');
  };
  // Bij Alle (of Problemen, Onderhoud): een blok per soort apparaat; met één soort gekozen alleen die
  const groups = Object.keys(GROUP_INFO).filter((g) => hosts.some((h) => hostGroup(h) === g));
  el.className = 'dash-hosts';
  el.innerHTML = dashGroup() || groups.length < 2 ? block(hosts, !!dashGroup() || groups[0] !== 'server') : groups.map((g) => {
    const arr = hosts.filter((h) => hostGroup(h) === g);
    const nBad = arr.filter((h) => !okHost(h)).length;
    return `<section class="grp-block"><div class="grp-h"><h2>${GROUP_INFO[g][0]} ${esc(GROUP_INFO[g][1]())}</h2>
      <span>${arr.length === 1 ? L('1 apparaat') : L('{n} apparaten', { n: arr.length })}${nBad ? ` · <b class="bad">${L('{n} met problemen', { n: nBad })}</b>` : ''}</span></div>
      ${block(arr, true)}</section>`;
  }).join('');
  $('#toggle-all').textContent = state.hosts.length && state.hosts.every((h) => openHosts.has(+h.id)) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

/** Compacte regel voor een server waar alles OK is (Blox); klik = uitklappen met alle checks */
function okRow(h) {
  const p = hostParts(h);
  const u = stats.uptime[h.id];
  const n = h.checks.filter((c) => +c.enabled).length;
  return `<article class="ok-item ${p.isOpen ? 'open' : ''}">
    <div class="ok-row" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}" title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="ok-ic">${hostIcon(h)}</span>
      <span class="ok-nm"><strong>${esc(h.name)}</strong><small>${esc(h.address)}${p.via ? ' · ' + p.via : ''}</small></span>
      <span class="ok-st">● ${n === 1 ? L('1 check OK') : L('{n} checks OK', { n })}</span>
      <span class="ok-mets">${hostSparks(h, true)}</span>
      <span class="ok-up" title="${L('Bereikbaar afgelopen 24 uur')}">${u && u.h24 !== null ? pct(u.h24) : ''}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    ${p.isOpen ? hostDetail(h) : ''}
  </article>`;
}

/** Mini-grafiekjes van CPU, RAM en de volste disk over 24 uur (uit de minigrafieken van de checks) */
function hostSparks(h, mini = false) {
  const pick = (type) => h.checks.filter((c) => c.type === type && +c.enabled && c.last_value !== null).sort((a, b) => b.last_value - a.last_value)[0];
  const items = [['cpu', 'CPU'], ['ram', 'RAM'], ['disk', 'Disk']].map(([t, l]) => [l, pick(t)]).filter(([, c]) => c);
  if (!items.length) {
    // Zonder CPU/RAM/disk: de eerste check met een getal (bv. ping)
    const c = h.checks.find((x) => +x.enabled && x.last_value !== null && stats.sparks[x.id]);
    if (c) items.push([c.name, c]);
  }
  return items.map(([l, c]) => {
    const v = c.last_value === null ? '–' : typeOf(c).unit === '%' ? Math.round(+c.last_value) + '%' : dec(fmt(c.last_value, typeOf(c).unit));
    return `<span class="hs s${c.maintenance ? 4 : c.status}" title="${esc(c.name)}: ${esc(c.last_message || '')}">
      <span class="hs-l">${esc(l)} <b>${v}</b></span>${miniSpark(c, mini ? 16 : 24)}</span>`;
  }).join('');
}

/** Kleine lijn van een check over 24 uur (zonder statusstrook) */
function miniSpark(c, H) {
  const data = stats.sparks[c.id];
  if (!data) return `<svg class="hs-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none"></svg>`;
  const vals = data.filter((d) => d && d[0] !== null).map((d) => +d[0]);
  if (!vals.length) return `<svg class="hs-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none"></svg>`;
  const max = typeOf(c).unit === '%' ? 100 : Math.max(...vals, 1) * 1.15;
  let d = '';
  data.forEach((x, i) => { if (x && x[0] !== null) d += `${d ? 'L' : 'M'}${(i / 47 * 100).toFixed(1)},${(H - 2 - (+x[0] / max) * (H - 4)).toFixed(1)}`; });
  return `<svg class="hs-svg" viewBox="0 0 100 ${H}" preserveAspectRatio="none" aria-hidden="true"><path d="${d}" vector-effect="non-scaling-stroke"/></svg>`;
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
  $$('[data-view-open]').forEach((b) => b.classList.toggle('active', b.dataset.viewOpen === (smartOpen() ? 'smart' : 'closed')));
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
// Bij openen alles dicht (1.71.0): wat je openklapt, blijft open tot je herlaadt. "Slim openen": onthouden in deze browser.
const openHosts = new Set((() => { try { return smartOpen() ? JSON.parse(localStorage.getItem('rzmon-open') || '[]') : []; } catch (e) { return []; } })());
function saveOpen() { if (!smartOpen()) return; try { localStorage.setItem('rzmon-open', JSON.stringify([...openHosts])); } catch (e) {} }
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
    ? `<span class="muted small">${contactHtml(h, 'short')}</span>
       <button class="btn need-admin" data-act="agent" data-id="${h.id}">Agent Manager</button>` : '';
  return `
    <div class="host-detail">
      <div class="detail-bar">
        ${h.checks.some((c) => +c.enabled && +c.status !== 0 && !c.acked_at) ? `<button class="btn ack-btn need-user" data-act="ack-host" data-id="${h.id}">✔ ${L('Alles bevestigen')}</button>` : ''}
        <button class="btn need-user" data-act="maint-host" data-id="${h.id}">🔧 ${L('Onderhoud')}</button>
        <button class="btn need-admin" data-act="add-check" data-id="${h.id}">＋ Check</button>
        ${+h.discover_n ? `<button class="btn need-admin discover-btn" data-act="discover" data-id="${h.id}" title="${esc(L('Nog niet bewaakt: disks, services, containers, certificaten en poorten die de agent ziet'))}">💡 ${+h.discover_n === 1 ? L('1 nieuw gevonden') : L('{n} nieuw gevonden', { n: h.discover_n })}</button>` : ''}
        <button class="btn need-user" data-act="scan" data-id="${h.id}">🔍 ${L('Poorten')}</button>
        ${h.snmp ? `<button class="btn need-admin" data-act="snmp" data-id="${h.id}">📡 ${L('SNMP-checks')}</button>` : ''}
        ${hostKind(h) === 'camera' ? `<button class="btn need-admin" data-act="cam-rec" data-id="${h.id}">${L('＋ Aanbevolen checks')}</button>` : ''}
        ${h.metrics.includes('podman') ? `<button class="btn" data-act="podman" data-id="${h.id}">📦 Containers</button>` : ''}
        ${isPveHost(h) ? `<button class="btn" data-act="pve" data-id="${h.id}">🖥️ Proxmox</button>` : ''}
        <button class="btn need-admin" data-act="edit-host" data-id="${h.id}">✎ ${L('Bewerken')}</button>
        ${agentInfo ? `<span class="spacer"></span>${agentInfo}` : ''}
      </div>
      ${uptimeLine(h, false)}
      ${lines ? checkLines(h, lines === 'large') : checkTiles(h)}
    </div>`;
}

// Blox: een tegel per server
/** Blox-tegel. Small: compacte tegel (naam, status, problemen), meer per rij; Large: met meters en details. */
/**
 * Endpoint-bewaking (1.110.0): ontbreekt er iets op deze server (auditd, inotify-tools), dan een regel onder de naam,
 * alleen voor beheerders. Klik: de Agent Manager, waar je het kunt laten installeren.
 */
const EDR_COMP = { auditd: ['auditd', () => L('nieuwe processen, kernelmodules en bewaakte paden')], inotify: ['inotify-tools', () => L('nieuwe bestanden in tijdelijke mappen en webmappen')],
  yara: ['YARA', () => L('nieuwe bestanden op malware controleren (optioneel)')] };
function edrNote(h) {
  if (!isAdmin() || h.mode !== 'agent' || !+(h.edr_on ?? 1) || !h.edr_info) return '';
  let i;
  try { i = JSON.parse(h.edr_info); } catch (e) { return ''; }
  // 1.123.0: per onderdeel te verbergen ("Niet meer tonen"); ontbreekt er later iets anders, dan komt het wel
  const hidden = edrHidden(h.id);
  const miss = [i.audit === 'missing' ? ['auditd', 'auditd'] : null, i.audit === 'stopped' ? ['auditd_stopped', L('auditd draait niet')] : null, i.file === 'missing' ? ['inotify', 'inotify-tools'] : null]
    .filter((m) => m && !hidden.includes(m[0]));
  if (!miss.length) return '';
  return `<button type="button" class="edr-note" data-act="edr-note" data-id="${h.id}" data-keys="${esc(miss.map((m) => m[0]).join(','))}" title="${esc(L('Klik: installeren of niet meer tonen'))}">🛡️ ${esc(L('ontbreekt: {what}', { what: miss.map((m) => m[1]).join(', ') }))} · ${L('installeren?')}</button>`;
}
const edrHidden = (id) => ((state && state.edr_note_hide) || {})[id] || [];
/**
 * Velden in de hele interface (1.126.0): de titel en de toelichting ernaast (bv. de eenheid "ms") op één regel, in plaats
 * van de toelichting op een eigen regel tussen titel en veld. Ook voor wat later geladen wordt (vensters, kaarten).
 */
function labelLines(root) {
  $$('label:not(.switch):not(.radio-row):not([data-ll])', root).forEach((l) => {
    l.dataset.ll = '1';
    const kids = [...l.childNodes];
    const ctl = kids.findIndex((n) => n.nodeType === 1 && (n.matches('input, select, textarea, .inline-row, .copy-line') || n.querySelector?.('input, select, textarea')));
    if (ctl < 1) return;
    const before = kids.slice(0, ctl);
    if (!before.some((n) => n.nodeType === 1 && n.matches('.hint, .muted.small, .muted'))) return;
    if (before.some((n) => n.nodeType === 1 && n.matches('.hint, .muted.small, .muted') && n.textContent.trim().length > 60)) return;   // lang: wordt in de instellingen een ⓘ
    if (before.some((n) => n.nodeType === 1 && n.matches('.lbl-line, div, p, br'))) return;
    const line = document.createElement('span');
    line.className = 'lbl-line';
    before.forEach((n) => line.append(n));
    l.prepend(line);
  });
}
(() => {
  let t = null;
  const run = () => labelLines(document.body);
  document.addEventListener('DOMContentLoaded', run);
  new MutationObserver(() => { clearTimeout(t); t = setTimeout(run, 20); }).observe(document.documentElement, { childList: true, subtree: true });
})();

/** Klik op de regel: installeren via de Agent Manager, of deze melding voor deze server niet meer tonen */
function edrNoteDialog(h, keys) {
  modal({
    title: '🛡️ ' + L('Endpoint-bewaking op {name}', { name: h.name }),
    body: `<p style="margin-top:0">${L('Op deze server ontbreekt iets voor de endpoint-bewaking. Zonder werkt de rest gewoon, maar ziet RzMon minder (bv. geen nieuwe processen zonder auditd).')}</p>
      <p class="muted small">${L('Niet meer tonen geldt voor deze server en voor wat nu ontbreekt. Weer tonen kan in de Agent Manager, bij Functies.')}</p>`,
    buttons: [{ label: '🙈 ' + L('Niet meer tonen'), style: 'margin-right:auto', onClick: async () => {
      try { await edrHideSave(h.id, [...new Set([...edrHidden(h.id), ...keys])]); $('#modal').close(); toast(L('Wordt niet meer getoond voor {name}', { name: h.name })); } catch (ex) { toast(ex.message, 'err'); }
    } }, { label: L('Sluiten'), onClick: () => $('#modal').close() }, { label: L('Installeren…'), cls: 'primary', onClick: () => agentInstructions(h) }],
  });
}
async function edrHideSave(id, list) {
  const all = { ...((state && state.edr_note_hide) || {}) };
  if (list.length) all[id] = list; else delete all[id];
  await api('settings_save', { edr_note_hide: JSON.stringify(all) });
  state.edr_note_hide = all;
  render();
}

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
        <span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.maint}${edrNote(h)}
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
      <span class="host-ic" aria-hidden="true">${hostIcon(h)}</span>
      <div class="host-title">
        <strong>${esc(h.name)}${+h.enabled ? '' : ` <span class="badge">${L('uit')}</span>`}</strong>
        <span class="muted mono host-sub">${esc(h.address)} · ${isHa(h) ? 'Home Assistant' : esc(h.mode)}${h.mode === 'agent' && h.agent_version ? ' ' + esc(h.agent_version) + (+h.agent_key_seen === 1 ? ' 🔑' : '') : ''}</span>${p.via}
        ${p.maint}${edrNote(h)}
      </div>
      <span class="counts">${p.counts}</span>
      <span class="chev" aria-hidden="true">›</span>
    </div>
    <div class="host-sum" data-act="toggle" data-id="${h.id}">
      <div class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</div>
      ${p.chips(4) ? `<div class="chips">${p.chips(4)}</div>` : ''}
      ${hostSparks(h) ? `<div class="host-sparks">${hostSparks(h)}</div>` : ''}
      <div class="muted small sum-foot">${p.lastChecked ? L('Laatste check') + ' ' + agoSpan(p.lastChecked) : ''}${h.mode === 'agent' ? ` · ${h.agent_seen ? contactHtml(h, 'short') : 'agent ' + L('nog niet gezien')}${agentVer(h)}` : ''}${uptimeLine(h, true)}</div>
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
    <span class="meter-bar"><i style="width:${v}%"></i></span><b data-flash>${v}%</b></span>`;
}

function hostLine(h, large) {
  const p = hostParts(h);
  const u = stats.uptime[h.id];
  const agent = h.mode === 'agent' ? `${h.agent_seen ? contactHtml(h, 'short') : 'agent ' + L('nog niet gezien')}${agentVer(h)}` : '';
  const cells = large
    ? `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span>${p.chips(5) ? `<span class="chips">${p.chips(5)}</span>` : ''}</span>
       <span class="lc-m">${meterCell(h, 'cpu')}</span>
       <span class="lc-m">${meterCell(h, 'ram')}</span>
       <span class="lc-m">${meterCell(h, 'disk')}</span>
       <span class="lc-up" title="${L('Bereikbaar afgelopen 7 dagen')}">${u ? pct(u.d7) : '<span class="muted">–</span>'}</span>
       <span class="lc-last muted small">${p.lastChecked ? agoSpan(p.lastChecked) : ''}${agent ? `<br>${agent}` : ''}</span>`
    : `<span class="lc-status"><span class="sum-line ${p.sum.cls}">${esc(p.sum.text)}</span></span>
       <span class="counts">${p.counts}</span>`;
  return `
  <article class="${p.cls} line ${large ? 'large' : 'small'}">
    <div class="line-row" data-act="toggle" data-id="${h.id}" role="button" tabindex="0" aria-expanded="${p.isOpen}"
         title="${p.isOpen ? L('Inklappen') : L('Klik voor alle checks')}">
      <span class="dot"></span>
      <span class="lc-name"><strong><span class="lc-ic" aria-hidden="true">${hostIcon(h)}</span> ${p.title}</strong>${large ? `<span class="muted mono small">${esc(h.address)}</span>${p.via}${p.maint}` : ''}${edrNote(h)}</span>
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
    <span class="chk-name">${esc(c.name)}<span class="type">${b.t.label}${b.num ? ` · <span data-flash>${b.num}</span>` : ''}</span>${b.flags}</span>
    <span class="chk-actions">${b.actions}
    </span>
    ${bar}
    ${sparkSvg(c)}
    <span class="chk-msg">${b.msg} · ${agoSpan(c.last_checked)}</span>
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
    ? `<span class="cl-meter" title="${fmt(b.pctVal, '%')}"><span class="bar"><i style="width:${b.pctVal}%"></i>${b.ticks}</span><b data-flash>${Math.round(b.pctVal)}%</b></span>`
    : b.num ? `<span class="cl-num" data-flash>${b.num}</span>` : '<span class="muted">–</span>';
  return `
  <div class="cl chk ${b.cls}" title="${b.title}" data-cid="${c.id}">
    <span class="dot"></span>
    <span class="cl-name"><span class="cl-title">${esc(c.name)}</span>${large ? `<span class="type">${b.t.label}</span>` : ` <span class="type">${b.t.label}</span>`}${b.flags ? `<span class="cl-flags">${b.flags}</span>` : ''}</span>
    <span class="cl-value">${value}</span>
    ${large ? `<span class="cl-spark">${sparkSvg(c) || '<span class="muted small">–</span>'}</span>` : ''}
    <span class="cl-msg">${b.msg}</span>
    <span class="cl-last muted small">${agoSpan(c.last_checked)}</span>
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
    case 'edr-am': agentInstructions(findHost(id)); return;
    case 'edr-note': edrNoteDialog(findHost(id), (b.dataset.keys || '').split(',').filter(Boolean)); return;
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
    case 'discover': return discoverDialog(findHost(id));
    case 'scan': return portScan(findHost(id));
    case 'snmp': return snmpChecks(findHost(id));
    case 'cam-rec': return cameraRecommended(findHost(id));
    case 'podman': return podmanOverview(findHost(id));
    case 'pve': return pveOverview(findHost(id));
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

async function checkInfo(id, live = false) {
  let r;
  try { r = await api('check_info', null, { id }); } catch (ex) { return live ? null : toast(ex.message, 'err'); }
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
      + (r.smart && r.smart.mode !== 'manual' ? ` <span class="muted small">(${r.smart.days >= r.smart.min_days ? L('niet in gebruik: slimme drempels') : L('in gebruik tot RzMon genoeg geleerd heeft')})</span>` : '')]] : []),
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
    ...(r.smart && r.smart.mode === 'manual' && r.smart.days >= r.smart.min_days ? [{ label: '🧠 ' + L('Slim met vangnet'), cls: 'need-admin', onClick: async (btn) => {
      btn.disabled = true;
      try { await api('smart_set', { ids: [c.id], mode: 'guard' }); toast(L('Deze check gebruikt nu slimme drempels met vangnet')); await refresh(); checkInfo(c.id); } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
    } }] : []),
    ...(sc ? [{ label: '📈 ' + L('Grafiek'), onClick: () => { close(); showHistory(sc, h); } }] : []),
    ...(sc ? [{ label: '✎ ' + L('Bewerken'), cls: 'need-admin', onClick: () => checkForm(sc, h) }] : []),
  ];
  const ciBody = `<div class="ci">
      <p class="ci-status s${st}"><b>${STATUS[st]}</b>${since}<br><span class="ci-msg">${esc(c.last_message || '–')}</span></p>
      <dl class="ci-grid">${rows.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('')}</dl>
      <p class="small muted ci-tags">${tags.join(' · ')}</p>
      ${r.smart ? smartBlock(r.smart, c) : ''}
      <h3 class="ci-h">${L('Laatste 5 statuswijzigingen')}</h3>
      ${changes ? `<ol class="ci-changes">${changes}</ol>` : `<p class="muted small">${L('Nog geen statuswijzigingen bewaard. Ze worden bijgehouden vanaf versie 1.37.0.')}</p>`}
    </div>`;
  const title = `${ST_ICON[st]} ${c.name} – ${c.host_name}`;
  if (live) {
    $('#modal-title').textContent = title;
    withMorph(() => { $('#modal-body').innerHTML = ciBody; });
    // Knoppen alleen opnieuw als er een bij kwam of af ging (bv. Bevestigen), anders blijven ze staan
    const sig = btns.map((b) => b.label).join('|');
    if ($('#modal').dataset.btns === sig) return;
  }
  modal({ title, wide: true, body: ciBody, buttons: btns });
  $('#modal').dataset.live = 'check:' + id;
  $('#modal').dataset.btns = btns.map((b) => b.label).join('|');
}

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
  if (!r.list.length) { el.innerHTML = `<p class="muted small">${L('Nog geen voorstellen: RzMon heeft per check minstens 7 dagen meetwaarden nodig, of al je checks die kunnen leren staan al op slim.')}</p>`; return; }
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
  el.innerHTML = r.list.length ? `<div class="adv-list">${r.list.map((a) => `<div class="adv-item">
      <span class="adv-ic" title="${esc(ADVICE_KIND[a.kind][1])}">${ADVICE_KIND[a.kind][0]}</span>
      <div class="adv-txt"><b>${esc(a.label)}</b><span class="small">${esc(a.text)}</span></div>
      <div class="adv-btns"><button type="button" class="btn small-btn primary" data-adv="${a.id}" data-apply="1">${L('Toepassen')}</button>
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
  const url = d.has_base ? d.url : location.origin + location.pathname.replace(/[^/]*$/, '') + d.url.replace(/^\//, '');
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
    <p class="hint" style="margin-top:0">${L('Per onderdeel een naam en de checks die erbij horen (klik om ze aan te vinken). Het ergste van die checks telt; checks in onderhoud tonen "Onderhoud".')}</p>
    <div id="sp-rows">${(d.items.length ? d.items : [{ label: '', checks: [] }]).map(row).join('')}</div>
    <button type="button" class="btn small-btn" id="sp-add">＋ ${L('Onderdeel')}</button>
    <h3 class="small" style="margin:1rem 0 .3rem">${L('Geheime link')}</h3>
    <div class="copy-line"><pre class="code" id="sp-url">${esc(url)}</pre><button type="button" class="btn" id="sp-copy">${L('Kopieer')}</button></div>
    <p class="hint">${d.has_base ? '' : L('Tip: vul bij Instellingen → Systeem het adres van RzMon in (base_url) als RzMon van buiten anders heet. ')}${L('Een nieuwe link maken? Dan werkt de oude meteen niet meer.')}
      <button type="button" class="btn small-btn" id="sp-new">↻ ${L('Nieuwe link')}</button> <a href="${esc(url)}" target="_blank" rel="noopener" class="btn small-btn">${L('Openen')} ↗</a></p>
    <div class="btn-row" style="margin-top:.8rem"><button type="button" class="btn primary" id="sp-save">${L('Opslaan')}</button></div>`;
  const rows = $('#sp-rows');
  rows.addEventListener('click', (e) => { if (e.target.closest('.sp-del')) e.target.closest('.sp-row').remove(); });
  rows.addEventListener('change', (e) => {
    const d = e.target.closest('.sp-checks');
    if (d) $('summary', d).textContent = sum($$('input:checked', d).map((i) => +i.value));
  });
  $('#sp-add').onclick = () => rows.insertAdjacentHTML('beforeend', row({}));
  $('#sp-copy').onclick = () => { navigator.clipboard?.writeText($('#sp-url').textContent); toast(L('Gekopieerd')); };
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
function modal({ title, body, buttons = [], wide = false, page = false, onSubmit }) {
  const dlg = $('#modal');
  const form = $('#modal-form');
  dlg.dataset.live = '';   // een ander venster: niet meer live bijwerken als check (checkInfo zet het weer)
  dlg.classList.toggle('wide', wide);
  dlg.classList.toggle('page', page);   // 1.121.0: als pagina (bijna het hele scherm), bv. de Agent Manager
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
/** Eerste optie bij Soort apparaat: wat RzMon zelf herkent */
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
        <p class="hint" style="margin-top:0">${L('Extra paden voor deze server, bovenop de algemene lijst (<i>Instellingen → IDS → Algemeen</i>). Hetzelfde pad hier gaat voor, bv. om de ernst te veranderen.')}</p>
        <div id="fim-host"></div>
      </details>
      ${(state.templates || []).length ? `<details class="notify-own" ${isNew || hostTpls(h).length ? 'open' : ''}>
        <summary>📋 ${L('Sjablonen')}: <span>${hostTpls(h).length ? esc(hostTpls(h).map((id) => (state.templates.find((t) => +t.id === id) || {}).name).join(', ')) : L('geen')}</span></summary>
        <p class="hint" style="margin-top:0">${L('De checks uit een sjabloon komen vanzelf op deze server, en veranderen mee als je het sjabloon aanpast. Uitvinken koppelt los; de checks blijven dan gewoon staan.')}</p>
        ${state.templates.map((t) => `<label class="switch"><input type="checkbox" data-tpl="${t.id}" ${hostTpls(h).includes(+t.id) ? 'checked' : ''}><span></span> ${esc(t.name)}</label>`).join('')}
      </details>` : ''}
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
      if (s.kind && KIND_INFO[s.kind]) {
        $('#kind-auto-opt').textContent = kindAutoText(null, s.kind);
        $('[name=kind_auto]', form).value = s.kind;
      }
      out.innerHTML = `✔ ${esc(s.name || L('(geen naam)'))}${s.vendor ? ` · ${esc(s.vendor)}` : ''}${s.kind && KIND_INFO[s.kind] ? ` · ${esc(kindLabel(s.kind))}` : ''}${s.uptime !== null ? ` · ${L('aan sinds {t}', { t: esc(fmtDur(s.uptime)) })}` : ''}<br><span class="muted">${esc(s.descr)}</span>`;
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
  c = c || { type: 'ping', target: '', name: '', warn_threshold: TYPES.ping.warn, crit_threshold: TYPES.ping.crit, interval_sec: 60, enabled: 1, ...preset };
  const quietOwn = quietOf(c);
  const cmdCfg = (() => { try { return JSON.parse(c.command || '{}') || {}; } catch (e) { return {}; } })();
  const allowed = (h.mode === 'remote' ? ['ping', 'tcp', 'http', 'ssl', 'snmp', 'heartbeat']
    : Object.keys(TYPES).filter((k) => h.mode === 'agent' || !['podman', 'container', 'log', 'ha', 'command', 'pve'].includes(k))
      .filter((k) => k !== 'pve' || isPveHost(h) || c?.type === 'pve')
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
    const notify = $('#notify-own').checked && ownEditor ? ownEditor.get() : null;
    const qm = $('#quiet-mode').value;
    const quiet = qm === 'global' ? null : qm === 'never' ? { mode: 'never' } : { mode: 'own', windows: quietOwnEditor ? quietOwnEditor.get().windows : [] };
    if (qm === 'own' && !quiet.windows.length) throw new Error(L('Stille uren: vul minstens één venster in met een dag'));
    const command = d.type === 'command' ? { cmd: d.cmd, expect: d.cmd_expect, match: d.cmd_match, timeout: d.cmd_timeout, fail: d.cmd_fail } : undefined;
    const res = {};
    ['cpu', 'mem', 'disk'].forEach((k) => { res[k] = { on: !!d[`res_${k}`], warn: d[`res_${k}_warn`], crit: d[`res_${k}_crit`] }; });
    if (d.type === 'heartbeat') {
      d.hb_every = Math.round(+d.hb_every_n * +d.hb_every_u);
      d.hb_ext = !!d.hb_ext;
      d.hb_new_token = !!d.hb_new_token;
    }
    return { ...d, res, notify, quiet, command, enabled: !!d.enabled, verify_ssl: !!d.verify_ssl, deep: !!d.deep, info_only: !!d.info_only };
  };
  const { form } = modal({
    title: tpl ? `📋 ${tpl.name}: ${isNew ? L('check toevoegen') : c.name}` : isNew ? L('Check toevoegen – {name}', { name: h.name }) : L('Check bewerken – {name}', { name: c.name }),
    body: `
      ${tpl ? `<p class="hint" style="margin-top:0">${L('Gebruik <code>{adres}</code> en <code>{naam}</code> in het doel of de naam: die worden per server ingevuld (bv. <code>https://{adres}/</code>). Opslaan werkt alle gekoppelde servers bij, behalve checks die daar zelf zijn aangepast.')}</p>` : ''}
      ${!tpl && c.template_item_id ? `<div class="callout small">📋 ${L('Uit het sjabloon <b>{t}</b>.', { t: esc((state.tpl_items || {})[c.template_item_id] || '?') })}
        ${+c.tpl_override ? `${L('Op deze server aangepast: wijzigingen aan het sjabloon gaan hier niet meer mee.')} <button type="button" class="btn small-btn" id="tpl-reset">↩ ${L('Terug naar het sjabloon')}</button>`
          : L('Pas je hem hier aan, dan gaat hij niet meer mee met het sjabloon (terugzetten kan altijd).')}</div>` : ''}
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
          <select name="cert_from">${[['', L('Automatisch (bestand en localhost via de agent, de rest vanaf RzMon)')], ['rzmon', L('Vanaf RzMon (via het netwerk)')], ['agent', L('Op de server zelf (agent)')]]
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
          ? `<div class="hint">${L('Op een agent-server gebruikt RzMon het laatste rapport; een nieuwe service, log of commando ziet de agent pas bij zijn volgende rapport.')}</div>` : ''}`;
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
  const pveSel = $('#pve-wrap [name=metric]', form);
  $('[name=reverse_ok]', form).onchange = () => update(false);
  const snmpSel = $('#snmp-metric');
  const update = (setDefaults) => {
    const isContainer = typeSel.value === 'container';
    const isHa = typeSel.value === 'ha';
    const isPve = typeSel.value === 'pve';
    const isSnmp = typeSel.value === 'snmp';
    const oidMode = $('[name=oid_mode]', form).value;
    const isHb = typeSel.value === 'heartbeat';
    const t = isHa ? { ...TYPES.ha, ...HA_METRICS[haSel.value] }
      : isPve ? { ...TYPES.pve, ...PVE_METRICS[pveSel.value] }
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
    $('#type-help').textContent = TYPES[typeSel.value].help;
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
    $$('#thr-wrap label', form).slice(0, 2).forEach((l) => (l.hidden = !t.unit));
    // Slimme drempels: alleen bij checks die kunnen leren (niet in een sjabloon, niet omgekeerd)
    const smartOk = !tpl && smartEligible(typeSel.value, isSnmp ? snmpSel.value : null) && !(+$('[name=reverse_ok]', form).value > 0);
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
      : hv.startsWith('cmd:') ? L('Gaat deze check mis, dan voert de agent het herstelcommando uit (alleen via een versleutelde verbinding) en meldt RzMon pas als dat niet helpt, of na 5 minuten. Het commando zelf staat alleen op de server, in <code>/etc/rzmon-agent/heal.d/</code>.')
      : L('Eigen herstelcommando\'s zet je op de server in <code>/etc/rzmon-agent/heal.d/</code> (uitvoerbaar, van root, niet door anderen te wijzigen); daarna staan ze hier.');
    if (smartOk && sm === 'guard' && $('[name=guard_threshold]', form).value === '' && setDefaults) $('[name=guard_threshold]', form).value = smartGuard(typeSel.value, isSnmp ? snmpSel.value : null) ?? '';
    $('#smart-hint').innerHTML = !smartOk ? '' : sm === 'manual' ? L('De drempels hierboven gelden altijd.')
      : L('RzMon leert {n} dagen wat normaal is, per uur van de week; tot die tijd gelden de drempels hierboven. Daarna stelt hij ze elke nacht bij (hooguit 10% per nacht). Elke aanpassing staat in het wijzigingslogboek, terug te draaien.', { n: 7 })
        + (sm === 'guard' ? ' ' + L('Vanaf het vangnet is het altijd CRITICAL, wat RzMon ook geleerd heeft.') : '');
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
    $('[name=target]', form).required = !!t.target && !['http', 'ssl'].includes(typeSel.value);
    $('[name=target]', form).disabled = !t.target;   // niet meesturen als er geen doel is (bv. HA-checks zonder doel)
    const haLogs = ['ha:host', 'ha:core', 'ha:supervisor', ...svcs.filter((x) => x.startsWith('addon:')).map((x) => `ha:${x}`)];
    const usbs = h.metrics.filter((m) => m.startsWith('usb:')).map((m) => m.slice(4));
    const list = isHa && haSel.value === 'usb' ? usbs
      : isPve ? h.metrics.filter((m) => m.startsWith(pveSel.value === 'storage' ? 'pves:' : 'pveg:')).map((m) => m.slice(5))
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
          ? L('<b class="warn-txt">⚠️ Commando\'s staan voor {name} uit.</b> De check blijft onbekend tot je ze aanzet: Instellingen → Agents → Commando-checks, of per server in de Agent Manager.', { name: esc(h.name) })
          : L('Commando\'s op {name}: <b>{mode}</b>.', { name: esc(h.name), mode: esc(CMD_MODES[m]) });
      }).catch(() => {});
    }
    $('[name=pattern]', form).required = typeSel.value === 'log';
    $('#target-list').innerHTML = list.map((v) => `<option value="${esc(v)}">`).join('');
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
  // Proceskiezer (1.98.0): wat er nu draait op de server (agent, of RzMon zelf), filtert op wat je typt
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
/** Agenttabel sorteren: op naam, of langst geen contact bovenaan (1.99.0; per browser onthouden) */
let agentSort = (() => { try { return localStorage.getItem('rzmon_agent_sort') || 'name'; } catch (e) { return 'name'; } })();
async function loadSecurity() {
  if (!isAdmin() || !$('#enc-card')) return;
  try { secInfo = await api('security'); } catch (ex) { $('#enc-info').textContent = ex.message; return; }
  syncClock(secInfo.now_ts);
  renderSecurity();
}

/**
 * Agents-overzicht (1.121.0), zoals het dashboard: tegels, wat aandacht nodig heeft en een kaart per agent. De instellingen
 * per agent (versleuteling, vastzetten, IP-slot, https) staan in de Agent Manager; die stonden hier dubbel.
 */
function agentOverview(keyText) {
  const hs = secInfo.hosts;
  if (!hs.length) return `<p class="muted">${L('Nog geen agents. Voeg een server toe met het type <i>Remote met agent</i>.')}</p>`;
  const now = nowS();
  const online = (h) => h.contact && h.contact.seen && now - h.contact.seen <= h.contact.stale;
  const latest = (h) => h.agent_version && state.agent_latest && verCmp(h.agent_version, state.agent_latest) >= 0;
  const secure = (h) => +h.agent_key_seen === 1 && +h.enc_seen === 1;
  const issues = (h) => {
    const a = secInfo.audit ? secInfo.audit[h.id] : null;
    const out = [];
    if (!h.contact || !h.contact.seen) out.push(L('nog geen rapport'));
    else if (!online(h)) out.push(L('offline sinds {t}', { t: clockText(h.contact.seen) }));
    if (h.agent_version && !latest(h)) out.push(L('versie {v} (nieuwste {n})', { v: h.agent_version, n: state.agent_latest }));
    if (a && a.bad) out.push(...a.issues.filter((x) => !/^Versie/i.test(x)));
    if (h.agent_ip_new) out.push(L('geweigerd vanaf nieuw IP-adres {ip}', { ip: h.agent_ip_new }));
    return out;
  };
  const n = hs.length, nOn = hs.filter(online).length, nNew = hs.filter(latest).length, nSec = hs.filter(secure).length;
  const nOk = hs.filter((h) => !issues(h).length).length;
  const tile = (lbl, v, sub, cls) => `<div class="kpi ${cls}"><div class="kpi-lbl">${lbl}</div><div class="kpi-val">${v}<small>/${n}</small></div><div class="kpi-sub">${sub}</div></div>`;
  const att = hs.filter((h) => issues(h).length);
  const sorted = [...hs].sort((x, y) => issues(y).length - issues(x).length || (agentSort === 'contact' ? (x.contact?.seen || 0) - (y.contact?.seen || 0) : x.name.localeCompare(y.name)));
  const badge = (ok, txt, title) => `<span class="ag-b ${ok === true ? 'ok' : ok === false ? 'warn' : ''}" title="${esc(title || '')}">${txt}</span>`;
  return `<div class="kpis ag-kpis">
      ${tile(L('Online'), nOn, nOn === n ? L('allemaal bereikbaar') : L('{n} offline', { n: n - nOn }), nOn < n ? 'bad' : '')}
      ${tile(L('Nieuwste versie'), nNew, state.agent_latest ? L('agent {v}', { v: esc(state.agent_latest) }) : '', nNew < n ? 'warn' : '')}
      ${tile(L('Eigen sleutel en versleuteld'), nSec, nSec === n ? L('alles versleuteld') : L('{n} nog niet', { n: n - nSec }), nSec < n ? 'warn' : '')}
      ${tile(L('Verbinding in orde'), nOk, nOk === n ? L('geen aandachtspunten') : L('{n} met aandachtspunten', { n: n - nOk }), nOk < n ? 'warn' : '')}
    </div>
    ${att.length ? `<div class="sec-h"><h2>⚠️ ${L('Nu aandacht nodig')}</h2><span>${att.length === 1 ? L('1 agent') : L('{n} agents', { n: att.length })}</span></div>
      <div class="attn ag-attn">${att.map((h) => `<div class="arow"><i class="adot s${online(h) ? 1 : 2}"></i><b class="a-srv">${esc(h.name)}</b>
        <span class="a-msg">${esc(issues(h).join(' · '))}</span>
        <span class="a-acts">${h.agent_version && !latest(h) ? `<button type="button" class="btn small-btn" data-ag-update="${h.id}">⬆ ${L('Bijwerken')}</button>` : ''}<button type="button" class="btn small-btn" data-am="${h.id}">${L('Agent Manager')}</button></span></div>`).join('')}
        ${secInfo.audit && Object.values(secInfo.audit).some((a) => a.bad) ? `<div class="ag-attn-foot"><button type="button" class="btn small-btn" id="ag-cleanup">🧹 ${L('Alles opruimen')}</button> <span class="muted small">${L('oude tokens wissen en agents opnieuw laten installeren met een reservekopie')}</span></div>` : ''}</div>`
      : `<div class="attn-ok">✔ ${L('Alle agents zijn online, bijgewerkt en veilig verbonden')}</div>`}
    <div class="sec-h hosts-h ag-h"><h2>${L('Agents')}</h2><span>${n}</span>
      <button type="button" class="th-sort" id="contact-sort" title="${esc(agentSort === 'contact' ? L('Weer op naam sorteren') : L('Langst geen contact bovenaan'))}">${agentSort === 'contact' ? L('Op contact') : L('Op naam')} ⇅</button></div>
    <div class="ag-cards">${sorted.map((h) => {
      const iss = issues(h).length;
      return `<article class="ag-card ${!online(h) ? 's2' : iss ? 's1' : 's0'}">
        <div class="ag-head"><i class="adot s${!online(h) ? 2 : iss ? 1 : 0}"></i><strong>${esc(h.name)}</strong>
          <span class="ag-ver ${latest(h) ? '' : 'warn-txt'}">${h.agent_version ? 'v' + esc(h.agent_version) : '–'}${h.agent_version && !latest(h) ? ' ⬆' : ''}</span></div>
        <div class="small ag-contact">${contactHtml(h, 'short')}</div>
        <div class="ag-badges">
          ${badge(+h.agent_key_seen === 1, +h.agent_key_seen === 1 ? '🔑 ' + L('eigen sleutel') : '🔑 ' + L('token'), L('Sleutel'))}
          ${badge(+h.enc_seen === 1, +h.enc_seen === 1 ? '🔒 ' + L('versleuteld') : '🔓 ' + L('onversleuteld'))}
          ${badge(h.agent_conn === 'http' || h.agent_fb_at ? false : h.agent_conn ? true : null, h.agent_conn === 'http' ? 'http' : h.agent_conn === 'proxy' ? L('https via proxy') : h.agent_conn ? 'https' : '–', L('Verbinding'))}
          ${badge(null, '⏱ ' + esc(fmtInterval(+h.agent_tick || secInfo.agent_tick || 60)), L('Ritme'))}
          ${+h.realtime ? badge(true, '⚡ realtime') : ''}
          ${h.agent_ips ? badge(null, '🌐 ' + esc(h.agent_ips), L('Bekend IP-adres')) : ''}
        </div>
        <div class="ag-acts"><button type="button" class="btn small-btn primary" data-am="${h.id}">${L('Agent Manager')}</button>
          <button type="button" class="btn small-btn" data-pair="${h.id}" title="${esc(L('Koppelcode maken en de agent (opnieuw) koppelen'))}">🔗 ${L('Koppelen')}</button>
          <button type="button" class="btn small-btn" data-url-host="${h.id}" title="${esc(L('Adres van RzMon voor deze agent wijzigen'))}">🌐 ${L('Adres')}</button></div>
      </article>`;
    }).join('')}</div>`;
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
  // Beveiligde verbinding: vernieuwen, certificaat vastzetten, vast IP-adres
  const c = secInfo.conn;
  $('#conn-days').value = String(c.key_days);
  $('#conn-pin').checked = c.pin;
  $('#conn-iplock').checked = c.ip_lock;
  $('#conn-https').checked = c.https_only;
  $('#conn-ownkey').checked = !!c.ownkey_only;
  $('#conn-pin-info').innerHTML = !c.pin ? L('Uit: agents met <code>INSECURE=1</code> accepteren elk certificaat.')
    : c.pin_value ? `${L('Vingerafdruk van RzMon')}: <code>sha256//${esc(c.pin_value.slice(0, 16))}…</code> · ${L('de agents krijgen hem vanzelf (versleuteld); bij een nieuw certificaat controleren ze het met hun sleutel')}`
    : c.pin_value === '' ? (c.https ? L('Niet nodig: het certificaat van RzMon is geldig (bekende uitgever), de agents controleren het gewoon.') : L('Niet nodig: je opent RzMon via http. Met https en een eigen certificaat zet RzMon het vanzelf vast.'))
    : L('De vingerafdruk is nog niet te bepalen (bv. achter een proxy); dat gebeurt bij het volgende rapport van een agent.');
  const wait = secInfo.hosts.filter((h) => h.agent_ip_new);
  $('#conn-ipwait').innerHTML = wait.map((h) => `<div class="callout ip-wait">⚠️ ${L('<b>{name}</b> wil verbinden vanaf <code>{ip}</code> en is geweigerd (bekend: {known}).', { name: esc(h.name), ip: esc(h.agent_ip_new), known: `<code>${esc(h.agent_ips || '–')}</code>` })}
      <span class="muted small">${esc(ago(h.agent_ip_new_at))}</span>
      ${ipViaWan(h) ? `<br><span class="small bad">${L('Een publiek adres, terwijl deze agent tot nu toe uit je eigen netwerk kwam: waarschijnlijk gaat hij via je router naar buiten en weer naar binnen, omdat de naam van RzMon op die server niet naar je reverse proxy wijst (DNS-rewrite). Los dat liever daar op dan dit adres toe te staan.')}</span><br>` : ''}
      <button type="button" class="btn primary" data-ip-allow="${h.id}">✔ ${L('Toestaan')}</button></div>`).join('');
  const keyText = (h) => +h.agent_key_seen === 1 ? `🔑 ${L('eigen sleutel')}${h.key_days != null ? ` <span class="muted">· ${L('{n} d oud', { n: h.key_days })}</span>` : ''}`
    : h.pairing ? `⏳ ${L('koppelcode klaar')}`
    : h.agent_version && verCmp(h.agent_version, c.ownkey_min) < 0 ? `<span class="warn-txt">${L('agent {v} nodig', { v: c.ownkey_min })}</span>`
    : `<span class="warn-txt">${L('nog geen eigen sleutel')}</span>`;
  $('#enc-hosts').innerHTML = agentOverview(keyText);
  loadBlocks();
  settingsSummaries();
}

function initSecurity() {
  $('#conn-cert').onclick = () => agentCertDialog();
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
  const connSave = (extra = {}) => api('conn_save', { key_days: +$('#conn-days').value, pin: $('#conn-pin').checked, ip_lock: $('#conn-iplock').checked, https_only: $('#conn-https').checked,
    ownkey_only: $('#conn-ownkey').checked, ...extra });
  $('#conn-cleanup').onclick = () => agentsCleanupDialog();
  $('#conn-url-all').onclick = () => agentUrlDialog();
  const connChange = async (e) => {
    const off = (e.target.id === 'conn-pin' && !e.target.checked && secInfo.conn.pin) || (e.target.id === 'conn-iplock' && !e.target.checked && secInfo.conn.ip_lock)
      || (e.target.id === 'conn-https' && !e.target.checked && secInfo.conn.https_only) || (e.target.id === 'conn-ownkey' && !e.target.checked && secInfo.conn.ownkey_only);
    if (!off) {
      try { await connSave(); savedFlag(e.target, true); loadSecurity(); } catch (ex) { savedFlag(e.target, false, ex.message); toast(ex.message, 'err'); if (e.target.id === 'conn-https' || e.target.id === 'conn-ownkey') e.target.checked = false; }
      return;
    }
    // Uitzetten maakt de verbinding minder veilig: eerst bevestigen
    modal({
      title: '⚠️ ' + L('Minder veilig maken?'),
      body: `<p style="margin-top:0">${e.target.id === 'conn-pin' ? L('Zonder vastgezet certificaat accepteren agents met <code>INSECURE=1</code> elk certificaat, ook dat van een nep-RzMon.')
        : e.target.id === 'conn-https' ? L('Agents mogen dan ook weer via http verbinden (de berichten blijven versleuteld).')
          : e.target.id === 'conn-ownkey' ? L('Agents mogen zich dan weer met alleen een token melden (onversleuteld, zonder eigen sleutel).') : L('Agents worden dan vanaf elk IP-adres geaccepteerd (met hun sleutel).')}</p>${confirmField('UITZETTEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); loadSecurity(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
      onSubmit: async (d) => { await connSave({ confirm: d.confirm }); toast(L('Opgeslagen')); loadSecurity(); },
    });
  };
  ['#conn-days', '#conn-pin', '#conn-iplock', '#conn-https', '#conn-ownkey'].forEach((id) => ($(id).onchange = connChange));
  $('#conn-ipwait').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-ip-allow]');
    if (!b) return;
    const h = secInfo.hosts.find((x) => +x.id === +b.dataset.ipAllow);
    if (h && ipViaWan(h) && !confirm(L('{ip} is een publiek adres, terwijl {name} tot nu toe uit je eigen netwerk kwam. Waarschijnlijk werkt de DNS-rewrite op die server niet (AdGuard: filteren aan voor die client; Home Assistant: ha dns info). Toestaan zet het IP-slot open voor alles wat via dat adres binnenkomt. Toch toestaan?', { ip: h.agent_ip_new, name: h.name }))) return;
    try { const r = await api('agent_ip_allow', { id: +b.dataset.ipAllow }); toast(L('{ip} toegestaan; de agent komt bij zijn volgende rapport weer binnen', { ip: r.ip })); loadSecurity(); refresh(); }
    catch (ex) { toast(ex.message, 'err'); }
  });
  $('#enc-hosts').addEventListener('click', async (e) => {
    if (e.target.closest('#contact-sort')) {
      agentSort = agentSort === 'contact' ? 'name' : 'contact';
      try { localStorage.setItem('rzmon_agent_sort', agentSort); } catch (ex) { /* alleen voor deze keer */ }
      return loadSecurity();
    }
    const am = e.target.closest('[data-am]');
    if (am) return agentInstructions(findHost(+am.dataset.am));
    if (e.target.closest('#ag-cleanup')) return agentsCleanupDialog();
    const agu = e.target.closest('[data-ag-update]');
    if (agu) {
      agu.disabled = true;
      try { await api('agent_update', { id: +agu.dataset.agUpdate }); toast(L('De agent wordt bijgewerkt bij zijn volgende rapport')); refresh(); } catch (ex) { toast(ex.message, 'err'); agu.disabled = false; }
      return;
    }
    const urlB = e.target.closest('[data-url-host]');
    if (urlB) return agentUrlDialog(secInfo.hosts.find((x) => +x.id === +urlB.dataset.urlHost));
    const pair = e.target.closest('[data-pair]'), renew = e.target.closest('[data-key-renew]'), reset = e.target.closest('[data-ip-reset]');
    try {
      if (pair) { const h = findHost(+pair.dataset.pair); if (h) agentInstructions(h, true); }
      if (renew && confirm(L('Nieuwe sleutel voor deze agent? Hij krijgt hem bij zijn volgende rapport; de huidige blijft nog 24 uur geldig.'))) {
        await api('agent_key_renew', { id: +renew.dataset.keyRenew }); toast(L('Nieuwe sleutel klaar')); loadSecurity();
      }
      if (reset && confirm(L('Bekende IP-adressen van deze agent vergeten? Het volgende adres waarvan hij rapporteert, wordt het bekende.'))) {
        await api('agent_ip_reset', { id: +reset.dataset.ipReset }); toast(L('Vergeten')); loadSecurity();
      }
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#enc-hosts').addEventListener('change', async (e) => {
    const hc = e.target.closest('[data-host-conn]');
    if (hc) return saveHostConn(hc, () => { loadSecurity(); refresh(); });
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
  initBlocks();
}

// ---------------------------------------------------------------- Blokkades en whitelist (1.83.0)
let blData = null, blTab = 'active', blShown = [];
const BL_SRC = { rz: () => '⛔ ' + L('IP in RzMon'), user: () => '🔒 ' + L('Account'), ids: () => '🛡️ IDS' };
const WL_KIND = { ip: () => L('IP-adres of netwerk'), user: () => L('Account: nooit vergrendelen'), decoy: () => L('Geen honeypot-naam') };
const WL_DAYS = [[1, L('1 dag')], [7, L('7 dagen')], [30, L('30 dagen')], [0, L('altijd')]];

async function loadBlocks() {
  if (!$('#blocks-list')) return;
  try { blData = await api('blocks_overview'); } catch (e) { $('#blocks-list').innerHTML = `<p class="bad small">${esc(e.message)}</p>`; return; }
  renderBlocks();
}

function blWhat(x) {
  return (x.type === 'user' ? `👤 <b>${esc(x.value)}</b>${x.ip ? `<br><span class="muted small">${L('vanaf')} <code>${esc(x.ip)}</code>${ccTag(x.ip_cc)}</span>` : ''}`
    : `<code>${esc(x.value)}</code>${ccTag(x.cc)}`) + (x.wl ? ` <span class="u-tag ok" title="${esc(L('Staat op de whitelist'))}">✅</span>` : '');
}

function renderBlocks() {
  const d = blData;
  if (!d) return;
  const tabs = $$('#bl-tabs [data-bl-tab]');
  tabs[0].textContent = `${L('Nu geblokkeerd')} (${d.active.length})`;
  tabs[1].textContent = `${L('Eerder')} (${d.history.length})`;
  tabs.forEach((b) => b.classList.toggle('active', b.dataset.blTab === blTab));
  const src = $('#bl-src').value, qv = $('#bl-q').value.trim().toLowerCase();
  const act = blTab === 'active';
  blShown = (act ? d.active : d.history).filter((x) => (!src || x.src === src)
    && (!qv || [x.value, x.ip, x.where, x.reason, x.by, x.removed_by, x.decoy, x.cc].some((v) => v && String(v).toLowerCase().includes(qv))));
  const when = (x) => act ? (x.until ? esc(fmtDateTime(x.until)) : `<b>${L('tot je deblokkeert')}</b>`)
    : x.removed_by ? L('opgeheven {t} door {who}', { t: esc(fmtDateTime(x.removed_at)), who: esc(x.removed_by) }) : L('verlopen {t}', { t: esc(fmtDateTime(x.removed_at)) });
  const where = (x) => x.src === 'ids' ? esc(x.where) + (x.all ? `<br><span class="muted small" title="${esc(L('Automatische blokkades gelden op al je servers met de IDS op blokkeren'))}">🌐 ${L('alle servers')}</span>` : '') : 'RzMon';
  const btns = (x, i) => (act ? `<button type="button" class="btn small-btn" data-bl-lift="${i}" title="${esc(L('Blokkade meteen opheffen'))}">🔓 ${L('Deblokkeren')}</button> ` : '')
    + (!x.wl || x.decoy ? `<button type="button" class="btn small-btn" data-bl-wl="${i}" title="${esc(L('Op de whitelist zetten: nooit meer blokkeren'))}">✅ ${L('Whitelist')}</button>` : '');
  $('#blocks-list').innerHTML = blShown.length ? `<div class="table-scroll"><table class="vuln-table bl-table">
    <thead><tr><th>${L('Wat')}</th><th>${L('Waar')}</th><th>${L('Reden')}</th><th>${L('Sinds')} → ${act ? L('tot') : L('opgeheven')}</th><th>${L('Door')}</th><th></th></tr></thead><tbody>
    ${blShown.map((x, i) => `<tr><td>${blWhat(x)}<br><span class="muted small nowrap">${BL_SRC[x.src]()}</span></td><td class="small">${where(x)}</td>
      <td class="small">${esc(x.reason || '')}${x.decoy ? `<br><span class="muted">🍯 ${L('honeypot-naam {u}', { u: `<code>${esc(x.decoy)}</code>` })}</span>` : ''}${act && +x.hits ? `<br><span class="muted">${L('{n}× tegengehouden', { n: x.hits })}</span>` : ''}</td>
      <td class="small">${x.since ? esc(fmtDateTime(x.since)) : '–'}<br><span class="muted">→</span> ${when(x)}</td>
      <td class="small">${+x.auto && x.src !== 'user' ? L('automatisch') : esc(x.by || '')}</td><td><span class="bl-acts">${btns(x, i)}</span></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${(src || qv) && (act ? d.active : d.history).length ? L('Niets gevonden.') : act ? L('Nu is er niets geblokkeerd. Je eigen IP-adres is {ip}.', { ip: `<code>${esc(d.my_ip)}</code>` })
      : L('Niets in de laatste {n} dagen.', { n: d.days })}</p>`;
  $('#wl-list').innerHTML = d.whitelist.length ? `<div class="table-scroll"><table class="vuln-table">
    <thead><tr><th>${L('Soort')}</th><th>${L('Wat')}</th><th>${L('Reden')}</th><th>${L('Tot')}</th><th>${L('Door')}</th><th></th></tr></thead><tbody>
    ${d.whitelist.map((w) => `<tr><td class="small">${WL_KIND[w.kind] ? WL_KIND[w.kind]() : esc(w.kind)}</td><td>${w.kind === 'user' ? '👤 <b>' + esc(w.value) + '</b>' : `<code>${esc(w.value)}</code>${ccTag(w.cc)}`}</td>
      <td class="small">${esc(w.reason || '')}</td><td class="small">${w.until ? esc(fmtDateTime(w.until)) : `<b>${L('altijd')}</b>`}</td>
      <td class="small">${esc(w.created_by || '')}<br><span class="muted">${esc(fmtDateTime(w.created_at))}</span></td>
      <td><button type="button" class="icon-btn" data-wl-del="${w.id}" title="${esc(L('Van de whitelist halen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('De whitelist is leeg.')}</p>`;
}

/** Op de whitelist zetten; x = een regel uit het overzicht (dan kies je uit wat erbij hoort), of niets (zelf invullen) */
function wlDialog(x = null) {
  const opts = [];
  if (x && x.type !== 'user' && !x.wl) opts.push(['ip', x.value, x.type === 'net' ? L('Dit netwerk nooit blokkeren') : L('Dit IP-adres nooit blokkeren'), L('in RzMon en door het IDS op al je servers')]);
  if (x && x.type === 'user') opts.push(['user', x.value, L('Account {u} nooit vergrendelen', { u: x.value }), L('het IP-adres van de aanvaller wordt wel geblokkeerd')]);
  if (x && x.decoy) opts.push(['decoy', x.decoy, L('{u} is geen honeypot-naam', { u: x.decoy }), L('valt af van de honeypot-namen, op alle servers')]);
  const ip = !x || opts[0][0] === 'ip';
  modal({
    title: '✅ ' + L('Op de whitelist zetten'),
    body: (x ? `${opts.map(([k, v, l, h], i) => `<label class="radio-row"><input type="radio" name="choice" value="${k}|${esc(v)}" ${i ? '' : 'checked'}><span><b>${esc(l)}</b>${k === 'user' ? '' : ` <code>${esc(v)}</code>`}<br><span class="muted small">${esc(h)}</span></span></label>`).join('')}`
      : `<label>${L('Soort')}<select name="kind">${Object.entries(WL_KIND).map(([k, l]) => `<option value="${k}">${esc(l())}</option>`).join('')}</select></label>
        <label>${L('IP-adres, netwerk of naam')}<input name="value" required class="mono" placeholder="${esc(L('bv. 203.0.113.10, 198.51.100.0/24, jan of admin'))}" autocomplete="off"></label>`)
      + `<div><span class="small"><b>${L('Hoe lang')}</b></span><div class="wl-days">${WL_DAYS.map(([v, l]) => `<label><input type="radio" name="days" value="${v}" ${v === (ip ? 30 : 0) ? 'checked' : ''}> ${esc(l)}</label>`).join(' ')}</div></div>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200" autocomplete="off"></label>
      ${x && x.src !== 'user' && opts.some((o) => o[0] === 'ip') ? `<p class="muted small">${L('De blokkade wordt meteen opgeheven, ook op de andere servers. Wordt dit adres later aangevallen, dan krijg je nog wel de meldingen.')}</p>` : ''}
      ${confirmField('WHITELIST')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✅ ' + L('Op de whitelist'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const [kind, ...rest] = d.choice ? d.choice.split('|') : [d.kind, d.value];
      const r = await api('whitelist_add', { kind, value: d.choice ? rest.join('|') : d.value, days: +d.days, reason: d.reason, confirm: d.confirm });
      toast(L('{v} staat op de whitelist', { v: r.value }) + (r.lifted ? ' · ' + L('{n} blokkade(s) opgeheven', { n: r.lifted }) : ''));
      loadBlocks();
    },
  });
  // Een IP standaard 30 dagen, een account of honeypot-naam standaard altijd
  $$('#modal [name=choice]').forEach((r) => (r.onchange = () => { const v = r.value.startsWith('ip|') ? '30' : '0'; $(`#modal [name=days][value="${v}"]`).checked = true; }));
}

function initBlocks() {
  $('#bl-tabs').onclick = (e) => { const b = e.target.closest('[data-bl-tab]'); if (b) { blTab = b.dataset.blTab; renderBlocks(); } };
  $('#bl-src').onchange = renderBlocks;
  $('#bl-q').oninput = renderBlocks;
  $('#wl-add').onclick = () => wlDialog();
  $('#blocks-list').addEventListener('click', async (e) => {
    const lift = e.target.closest('[data-bl-lift]'), wl = e.target.closest('[data-bl-wl]');
    if (wl) return wlDialog(blShown[+wl.dataset.blWl]);
    if (!lift) return;
    const x = blShown[+lift.dataset.blLift];
    try {
      await api('block_lift', { src: x.src, id: x.id });
      toast(x.src === 'ids' ? L('Gedeblokkeerd; de agent past het binnen een minuut aan') : L('{v} gedeblokkeerd', { v: x.value }));
      loadBlocks();
    } catch (ex) { toast(ex.message, 'err'); }
  });
  $('#wl-list').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-wl-del]');
    if (!b || !confirm(L('Van de whitelist halen? Daarna kan er weer geblokkeerd worden.'))) return;
    try { await api('whitelist_remove', { id: +b.dataset.wlDel }); toast(L('Van de whitelist gehaald')); loadBlocks(); } catch (ex) { toast(ex.message, 'err'); }
  });
  // Links naar een ander onderdeel (bv. vanuit IDS-instellingen naar de whitelist)
  $('#tab-settings').addEventListener('click', (e) => {
    const go = e.target.closest('a[data-goto]');
    if (go) { e.preventDefault(); showTab(go.dataset.goto); }
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
/** Verbindingscontrole in de agenttabel (1.100.0): ✅ alles goed of ⚠️ met het aantal punten; de punten als uitleg */
function auditBadge(id) {
  const a = secInfo && secInfo.audit ? secInfo.audit[id] : null;
  if (!a) return '<span class="muted">–</span>';
  return a.bad ? `<button type="button" class="link-btn audit-badge warn-txt" data-am="${id}" title="${esc(a.issues.join(', '))}">⚠️ ${a.bad}</button>`
    : `<span class="audit-badge good" title="${esc(L('{n} punten in orde', { n: a.good }))}">✅ ${a.good}</span>`;
}

/** 🧹 Alle agents opruimen (1.100.0), met wachtwoord en het woord OPRUIMEN */
function agentsCleanupDialog(after) {
  modal({
    title: '🧹 ' + L('Alle agents opruimen'),
    body: `<p style="margin-top:0">${L('Dit doet RzMon:')}</p><ul class="small">
      <li>${L('het oude token wissen van elke agent met een eigen sleutel;')}</li>
      <li>${L('als alle agents een eigen sleutel hebben: de algemene sleutel wissen en <b>alleen agents met een eigen sleutel</b> aanzetten (koppelen met een koppelcode blijft kunnen);')}</li>
      <li>${L('elke agent die zichzelf kan bijwerken installeert zichzelf opnieuw: eerst een reservekopie van de huidige agent en instellingen op de server, daarna de nieuwste agent met alleen de regels die nog nodig zijn.')}</li></ul>
      <p class="small muted">${L('Wat je zelf instelde (adres, certificaten, commando-checks …) blijft staan. Een agent die het niet zelf kan: op de server')} <code>sudo rzmon-agent-setup --update</code>.</p>${confirmField('OPRUIMEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🧹 ' + L('Opruimen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const r = await api('agents_cleanup', { confirm: d.confirm });
      toast(L('Opgeruimd: {t} token(s) gewist, {r} agent(s) installeren zichzelf opnieuw', { t: r.tokens, r: r.reinstall })
        + (r.global_key ? ' · ' + L('algemene sleutel gewist') : '') + (r.ownkey_only ? ' · ' + L('alleen agents met een eigen sleutel: aan') : ''));
      loadSecurity(); refresh();
      if (after) after();
    },
  });
}

/** Hoe kwam het laatste rapport binnen: 🔒 versleuteld, 🔓 niet, of nog niets */
function encSeenText(h) {
  if (!h.agent_seen) return L('nog geen rapport');
  if (+h.enc_seen === 1) return '🔒 ' + L('laatste rapport versleuteld');
  return '🔓 ' + L('laatste rapport niet versleuteld') + (h.agent_version && verCmp(h.agent_version, '1.49.0') < 0 ? ' · ' + L('agent {v} of nieuwer nodig', { v: '1.49.0' }) : '');
}

/** Hoe de agent verbindt (1.72.0): https via de proxy, https, http, of via het reserve-adres; met het adres en een lopende overstap */
/** Wil een agent uit het eigen netwerk ineens vanaf een publiek adres verbinden? (meestal: DNS-rewrite werkt niet, via de router) */
function ipViaWan(h) {
  const known = String(h.agent_ips || '').split(/\s+/).filter(Boolean);
  return !!h.agent_ip_new && !isLocalAddress(h.agent_ip_new) && known.length > 0 && known.every(isLocalAddress);
}

function connText(h) {
  const via = h.agent_fb_at ? `<b class="bad">⚠️ ${L('via reserve-adres')}</b> <span class="muted">${esc(ago(h.agent_fb_at))}</span>`
    : h.agent_conn === 'proxy' ? `🔒 ${L('https via proxy')}` : h.agent_conn === 'https' ? `🔒 https` : h.agent_conn === 'http' ? `<span class="warn-txt">🔓 http</span>` : '<span class="muted">–</span>';
  return `${via}${h.agent_url ? `<br><span class="muted mono conn-url" title="${esc(L('Adres van RzMon dat de agent gebruikt'))}">${esc(h.agent_url)}</span>${specIcon(h.agent_url, h.agent_url_pin)}` : ''}`
    + (h.agent_url_fb ? `<br><span class="muted conn-url" title="${esc(L('Reserve-adres'))}">↪ ${esc(h.agent_url_fb)}</span>${specIcon(h.agent_url_fb, h.agent_url_fb_pin)}` : '')
    + (h.agent_url_status ? `<br><span class="small ${h.agent_url_status.startsWith('✘') ? 'bad' : h.agent_url_status.startsWith('⏳') ? 'muted' : 'good-txt'}">${esc(h.agent_url_status)}</span>` : '');
}

/** Hoe de agent een adres controleert (1.78.0): 📌 eigen certificaat vastgezet, 🔒 geldig certificaat, of niets bekend */
function specIcon(url, spec) {
  if (!url || !spec) return '';
  if (spec.startsWith('sha256//')) return ` <span title="${esc(L('Alleen het eigen certificaat van RzMon (vastgezet)'))}">📌</span>`;
  return /^https:/.test(url) ? ` <span title="${esc(L('Geldig certificaat (gewone controle)'))}">🔒</span>` : '';
}

/** Wat er op een adres staat, gezien vanaf RzMon (1.78.0) */
function urlTrustText(r) {
  if (!r) return '';
  return ({
    own: `<span class="good-txt">🔒📌 ${L('het eigen certificaat van RzMon: de agent zet het vast (agent {v} nodig)', { v: '1.53.0' })}</span>`,
    ca: `<span class="good-txt">🔒 ${L('geldig certificaat')}${r.issuer ? ` (${esc(r.issuer)})` : ''}</span>`,
    http: `<span class="warn-txt">🔓 ${L('http: niet versleuteld (de berichten zelf blijven wel versleuteld)')}</span>`,
    other: `<span class="bad">✘ ${L('onbekend certificaat: niet van RzMon en niet vertrouwd')}</span>`,
    err: `<span class="warn-txt">⚠️ ${L('vanaf RzMon niet bereikbaar')}${r.error ? `: ${esc(r.error)}` : ''} · ${L('de agent test het zelf')}</span>`,
  })[r.kind] || '';
}

/**
 * Venster: adres van RzMon en reserve-adres voor één agent (of alle agents). De agent test het nieuwe adres eerst
 * (agent 1.51.0). Vanaf 1.78.0 een keuzelijst (IP-adressen, namen, beheeradres), http of https naar keuze, het
 * reserve-adres uit te zetten, en per adres wat er op staat (eigen certificaat = vastzetten, agent 1.53.0).
 */
async function agentUrlDialog(h = null) {
  const c = (secInfo && secInfo.conn) || {};
  let o;
  try { o = await api('agent_url_options'); } catch (ex) { return toast(ex.message, 'err'); }
  const opts = o.options || [];
  const trust = new Map(opts.map((x) => [x.url, x]));
  const hosts = ((secInfo && secInfo.hosts) || state.hosts.filter((x) => x.mode === 'agent')).filter((x) => !h || +x.id === +h.id);
  const src = { ip: L('IP-adres van RzMon'), dns: L('naam bij het IP-adres (DNS)'), host: L('hostnaam van RzMon'), cert: L('naam uit het certificaat'), admin: L('beheeradres (via je proxy)') };
  // Advies: rechtstreeks (eigen certificaat) als gewoon adres, het beheeradres (DNS-rewrite naar de proxy) als reserve
  const own = opts.find((x) => x.kind === 'own');
  const admin = opts.find((x) => x.source === 'admin');
  const defUrl = (h && h.agent_url) || (own || admin || opts[0] || {}).url || location.origin;
  const defFb = h ? (h.agent_url_fb || '') : (admin && admin.url !== defUrl ? admin.url : '');
  const field = (name, cur, none) => {
    const known = opts.some((x) => x.url === cur);
    return `<select data-url-sel="${name}">${none ? `<option value="" ${cur ? '' : 'selected'}>${L('Geen reserve-adres')}</option>` : ''}
        ${opts.map((x) => `<option value="${esc(x.url)}" ${x.url === cur ? 'selected' : ''} ${x.kind === 'other' ? 'disabled' : ''}>${esc(x.url)} · ${esc(src[x.source] || '')}</option>`).join('')}
        <option value="custom" ${cur && !known ? 'selected' : ''}>✏️ ${L('Zelf invullen (IP-adres of naam)…')}</option></select>
      <span class="url-custom" ${cur && !known ? '' : 'hidden'}><input name="${name}" type="text" value="${esc(cur)}" placeholder="https://rzmon.thuis.lan" spellcheck="false">
        <button type="button" class="btn small-btn" data-url-test="${name}">${L('Testen')}</button></span>
      <div class="small url-trust" data-trust="${name}"></div>`;
  };
  modal({
    title: '🌐 ' + (h ? L('Adres van RzMon – {name}', { name: h.name }) : L('Alle agents naar een ander adres')),
    body: `<p class="small" style="margin-top:0">${L('De agent krijgt het nieuwe adres versleuteld, <b>test het eerst</b> (verbinding, certificaat en zijn eigen sleutel) en gebruikt het pas als alles klopt; anders blijft het oude adres. Het resultaat zie je na één of twee rapporten in de kolom <i>Verbinding</i>.')}</p>
      <p class="small muted">💡 ${L('Advies: rechtstreeks naar RzMon (https op het IP-adres, het eigen certificaat wordt vastgezet) als gewoon adres, en je beheeradres (via de DNS-rewrite naar je proxy) als reserve. Zo hangen de agents niet af van je proxy.')}</p>
      ${h && h.agent_url ? `<p class="small">${L('Nu')}: <code>${esc(h.agent_url)}</code>${specIcon(h.agent_url, h.agent_url_pin)}${h.agent_url_fb ? ` · ${L('reserve')}: <code>${esc(h.agent_url_fb)}</code>${specIcon(h.agent_url_fb, h.agent_url_fb_pin)}` : ''}</p>` : ''}
      <label>${L('Adres')}</label>${field('url', defUrl, false)}
      <label style="margin-top:.6rem">${L('Reserve-adres')} <span class="hint">${L('(als het adres hierboven niet bereikbaar is)')}</span></label>${field('fallback', defFb, true)}
      <div class="small" id="url-warn"></div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🌐 ' + L('Testen en overstappen'), cls: 'primary', submit: true }],
    onSubmit: async () => {
      const url = value('url');
      if (!url) throw new Error(L('Kies een adres'));
      const r = await api('agent_url_set', { id: h ? h.id : 'all', url, fallback: value('fallback'), keep_fb: false });
      toast(L('{n} agent(s) krijgen het nieuwe adres bij hun volgende rapport', { n: r.n }) + (r.skipped.length ? ' · ' + L('overgeslagen: {names}', { names: r.skipped.join(', ') }) : ''));
      loadSecurity();
      refresh();
    },
  });
  const m = $('#modal');
  const value = (name) => {
    const sel = $(`[data-url-sel="${name}"]`, m).value;
    return (sel === 'custom' ? $(`input[name="${name}"]`, m).value : sel).trim().replace(/\/+$/, '');
  };
  // Waarschuwingen: http terwijl "alleen via https" geldt; eigen certificaat met agents ouder dan 1.53.0
  const warn = () => {
    const urls = [value('url'), value('fallback')].filter(Boolean);
    const out = [];
    const httpsOnly = hosts.filter((x) => x.agent_https === 'on' || (!x.agent_https && c.https_only));
    if (urls.some((u) => /^http:/.test(u)) && httpsOnly.length) out.push(`⚠️ ${L('"Alleen via https" staat aan voor: {names}. Via http weigert RzMon hun rapporten.', { names: httpsOnly.map((x) => esc(x.name)).join(', ') })}`);
    const pinMin = o.pinurl_min || '1.53.0';
    const old = hosts.filter((x) => !x.agent_version || verCmp(x.agent_version, pinMin) < 0);
    if (urls.some((u) => (trust.get(u) || {}).kind === 'own') && old.length) out.push(`⚠️ ${L('Voor het eigen certificaat is agent {v} nodig; deze worden overgeslagen: {names}. Werk ze eerst bij.', { v: pinMin, names: old.map((x) => esc(x.name)).join(', ') })}`);
    else {
      const old2 = hosts.filter((x) => !x.agent_version || verCmp(x.agent_version, o.url_min || '1.51.0') < 0);
      if (old2.length) out.push(`⚠️ ${L('Agent {v} of nieuwer nodig; deze worden overgeslagen: {names}. Werk ze eerst bij, of pas het adres op de server zelf aan.', { v: o.url_min || '1.51.0', names: old2.map((x) => esc(x.name)).join(', ') })}`);
    }
    if (urls.length === 2 && urls[0] === urls[1]) out.push(`⚠️ ${L('Het reserve-adres is hetzelfde als het gewone adres')}`);
    $('#url-warn', m).innerHTML = out.map((x) => `<p class="warn-txt" style="margin:.3rem 0">${x}</p>`).join('');
  };
  const show = (name) => {
    const u = value(name);
    if (u && !trust.has(u)) return test(name);   // zelf ingevuld: eerst bekijken wat erop staat
    $(`[data-trust="${name}"]`, m).innerHTML = u ? urlTrustText(trust.get(u)) : '';
    warn();
  };
  const test = async (name) => {
    const u = value(name);
    if (!u) return;
    $(`[data-trust="${name}"]`, m).innerHTML = `<span class="muted">${L('Testen…')}</span>`;
    try { trust.set(u, await api('agent_url_test', { url: u })); } catch (ex) { trust.set(u, { kind: 'err', error: ex.message }); }
    show(name);
  };
  $$('[data-url-sel]', m).forEach((sel) => (sel.onchange = () => {
    const name = sel.dataset.urlSel;
    const box = $(`.url-custom input[name="${name}"]`, m).parentElement;
    box.hidden = sel.value !== 'custom';
    if (sel.value === 'custom') $(`input[name="${name}"]`, m).focus();
    show(name);
  }));
  $$('[data-url-test]', m).forEach((b) => (b.onclick = () => test(b.dataset.urlTest)));
  $$('.url-custom input', m).forEach((i) => (i.onchange = () => test(i.name)));
  show('url');
  show('fallback');
}

/** Keuzelijst per server voor 📌 vastzetten of 🌐 vast IP-adres: algemene instelling (nu aan/uit), aan of uit */
function connSelect(kind, h, globalOn, short = false) {
  const cur = ({ pin: h.pin_mode, ip: h.ip_lock, https: h.agent_https })[kind] || '';
  return `<select data-host-conn="${kind}" data-host="${h.id}" title="${esc(({ pin: L('Certificaat van RzMon vastzetten'), ip: L('Alleen vanaf het bekende IP-adres'), https: L('Alleen via https') })[kind])}">`
    + [['', `${short ? L('algemeen') : L('algemene instelling')} (${globalOn ? L('aan') : L('uit')})`], ['on', L('aan')], ['off', L('uit')]]
      .map(([k, l]) => `<option value="${k}" ${cur === k ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
}
/** Wijziging per server opslaan; uitzetten waar het nu geldt vraagt om je wachtwoord */
async function saveHostConn(sel, done) {
  const send = (extra = {}) => api('host_conn', { host_id: +sel.dataset.host, [({ ip: 'ip_lock', pin: 'pin', https: 'https' })[sel.dataset.hostConn]]: sel.value, ...extra });
  try { await send(); savedFlag(sel, true); done(); }
  catch (ex) {
    if (!/UITZETTEN|wachtwoord|password|confirm/i.test(ex.message)) { toast(ex.message, 'err'); done(); return; }
    modal({
      title: '⚠️ ' + L('Minder veilig maken?'),
      body: `<p style="margin-top:0">${({ pin: L('Zonder vastgezet certificaat accepteert deze agent met <code>INSECURE=1</code> elk certificaat, ook dat van een nep-RzMon.'),
        ip: L('Deze agent wordt dan vanaf elk IP-adres geaccepteerd (met zijn sleutel).'), https: L('Deze agent mag dan ook weer via http verbinden (de berichten blijven versleuteld).') })[sel.dataset.hostConn]}</p>${confirmField('UITZETTEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); done(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
      onSubmit: async (d) => { await send({ confirm: d.confirm }); toast(L('Opgeslagen')); done(); },
    });
  }
}

/** "30 s", "2 min" */
function tickText(t) { return +t >= 120 ? `${+t / 60} min` : `${+t} s`; }

/**
 * Installatiecommando met een koppelcode: eerst het installatiescript downloaden en controleren (SHA-256), dan
 * installeren; het script controleert de agent ook. Bij een eigen certificaat alleen dat certificaat (--pinnedpubkey).
 */
/** Downloadsleutel (1.86.0): van buiten levert RzMon het installatiescript alleen hiermee */
const dlArg = () => (state.dl_key ? `&k=${state.dl_key}` : '');
function pairCommand(base, r, extra = '') {
  const pin = r.pin && base.startsWith('https') ? r.pin : '';
  return `curl -fsSL${pin ? ` -k --pinnedpubkey 'sha256//${pin}'` : ''} '${base}/api.php?action=agent_install${dlArg()}' -o rzmon-install.sh`
    + ` && echo '${r.setup_sha256}  rzmon-install.sh' | sha256sum -c -`
    + ` && sudo bash rzmon-install.sh --url '${base}' --pair '${r.code}' --agent-sha '${r.agent_sha256}'${pin ? ` --pin '${pin}'` : ''}${extra}`;
}
const pairButton = () => `<div class="pair-box"><button type="button" class="btn primary" data-make-pair>🔗 ${L('Koppelcode maken')}</button>
  <span class="muted small">${L('Eenmalig en 15 minuten geldig. De agent haalt er zijn eigen sleutel mee op; er gaat geen token of sleutel leesbaar over de lijn.')}</span></div>`;

function agentInstructions(h, pairNow = false) {
  const base = state.base_url || location.origin + location.pathname.replace(/\/[^/]*$/, '');
  const scriptUrl = location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api.php?action=agent_script';
  // De oude manier (token, en eventueel de algemene sleutel), voor agents ouder dan 1.50.0
  const keyArg = state.enc_key && h.enc !== 'off' ? ` --key '${state.enc_key}'` : '';
  const installCmd = `curl -fsSL '${base}/api.php?action=agent_install${dlArg()}' | sudo bash -s -- --url '${base}' --token '${h.agent_token || ''}'${keyArg}`;
  // 1.121.0: als pagina, alles in één keer zichtbaar: status bovenaan, daaronder Functies en Verbinding naast elkaar,
  // en Installeren ingeklapt (open als er nog nooit een rapport kwam, of bij opnieuw koppelen)
  modal({
    title: `Agent Manager – ${h.name}`,
    wide: true,
    page: true,
    body: `
      <section class="am-sec am-top">
      <div class="agent-live" id="agent-live"><span class="muted">${L('Status ophalen…')}</span></div>
      <div class="cmd-host-row contact-row">📡 ${L('Contact:')} <div>${contactHtml(h, 'full')}</div></div>
      </section>
      <div class="am-grid">
      <section class="am-sec need-admin"><h3 class="am-h">${L('Functies')}</h3>
      <div class="cmd-host-row need-admin">⏱ ${L('Rapporteren elke:')}
        <select id="host-tick"><option value="0">${L('standaard ({t})', { t: tickText(state.agent_tick) })}</option>${[30, 60, 120, 300].map((t) => `<option value="${t}" ${+h.agent_tick === t ? 'selected' : ''}>${tickText(t)}</option>`).join('')}</select>
        <span class="muted small" id="host-tick-info">${h.agent_update_via === 'direct' ? L('via cron/TrueNAS: minimaal elke minuut') : ''}</span></div>
      <div class="cmd-host-row need-admin">⚡ Realtime:
        <select id="host-realtime"><option value="0" ${+h.realtime ? '' : 'selected'}>${L('uit')}</option><option value="1" ${+h.realtime ? 'selected' : ''}>${L('aan')}</option></select>
        <span class="muted small" id="host-realtime-info">${h.agent_update_via === 'direct' ? L('werkt alleen met systemd (niet via cron of op TrueNAS)') : L('logins, sudo, services en 🔴-bestanden meteen')}</span></div>
      <div class="cmd-host-row need-admin">🐕 ${L('Waakhond:')}
        <select id="host-watchdog"><option value="0" ${+h.watchdog ? '' : 'selected'}>${L('uit')}</option><option value="1" ${+h.watchdog ? 'selected' : ''}>${L('aan')}</option></select>
        ${L('melden na')} <input id="host-wd-min" type="number" min="2" max="120" value="${+h.watchdog_min || 5}" style="width:4.5rem"> ${L('min')}
        <span class="muted small" id="host-wd-info">${L('deze agent stuurt zelf een Pushover-bericht als RzMon onbereikbaar is of de checks stilstaan')}</span></div>
      <div class="cmd-host-row need-admin">🛡️ ${L('Endpoint-bewaking:')}
        <select id="host-edr"><option value="1" ${+(h.edr_on ?? 1) ? 'selected' : ''}>${L('aan')}</option><option value="0" ${+(h.edr_on ?? 1) ? '' : 'selected'}>${L('uit')}</option></select>
        <span class="muted small">${L('processen, netwerk, bestanden en containers meteen bekeken; RzMon grijpt in bij een aanval')}</span></div>
      <div id="host-edr-info" class="need-admin edr-host"></div>
      ${edrHidden(h.id).length ? `<div class="cmd-host-row need-admin small muted">🙈 ${L('Melding over ontbrekende onderdelen verborgen ({what})', { what: esc(edrHidden(h.id).join(', ')) })}
        <button type="button" class="btn small-btn" id="host-edr-unhide">${L('Weer tonen')}</button></div>` : ''}
      <div class="cmd-host-row need-admin">ℹ️ ${L('Info bij agent-updates:')}
        <select id="host-info-agent"><option value="1" ${+(h.info_agent ?? 1) ? 'selected' : ''}>${L('aan (volgt de algemene info-regel)')}</option><option value="0" ${+(h.info_agent ?? 1) ? '' : 'selected'}>${L('uit')}</option></select></div>
      <div class="cmd-host-row need-admin">🖥️ ${L('Commando-checks op deze server:')}
        <select id="host-commands"><option value="global">${L('algemene instelling')}</option>${Object.entries(CMD_MODES).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
        <span class="muted small" id="host-commands-info"></span></div>
      </section>
      <section class="am-sec need-admin"><h3 class="am-h">${L('Verbinding')}</h3>
      <div class="cmd-host-row need-admin">🔐 ${L('Versleuteld versturen:')}
        <select id="host-enc">${ENC_HOST_MODES.map(([k, l]) => `<option value="${k}" ${(h.enc || '') === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <span class="muted small">${encSeenText(h)} · ${+h.agent_key_seen === 1 ? '🔑 ' + L('eigen sleutel') : L('nog geen eigen sleutel: maak hieronder een koppelcode')}</span></div>
      <div class="cmd-host-row need-admin">📌 ${L('Certificaat van RzMon vastzetten:')} ${connSelect('pin', h, state.conn_pin)}</div>
      <div class="cmd-host-row need-admin">🌐 ${L('Alleen vanaf het bekende IP-adres:')} ${connSelect('ip', h, state.conn_ip_lock)}</div>
      <div class="cmd-host-row need-admin">🔒 ${L('Alleen via https:')} ${connSelect('https', h, state.conn_https)}</div>
      <div class="cmd-host-row need-admin am-url">🌍 ${L('Adres van RzMon:')} <span class="small">${connText(h)}</span>
        <button type="button" class="btn small-btn" data-am-url>✏️ ${L('Wijzigen')}</button></div>
      ${h.agent_ip_new ? `<div class="callout need-admin">⚠️ ${L('Geweigerd vanaf het nieuwe IP-adres <code>{ip}</code>. Toestaan kan bij Instellingen → Agents → Standaardinstellingen.', { ip: esc(h.agent_ip_new) })}</div>` : ''}
      </section>
      </div>
      <details class="am-sec am-install" ${pairNow || !h.agent_seen ? 'open' : ''}><summary><h3 class="am-h">${L('Installeren of opnieuw koppelen')}</h3></summary>
      <div class="seg" role="tablist">
        <button type="button" class="seg-btn active" data-seg="linux">🐧 ${L('Linux-server')}</button>
        <button type="button" class="seg-btn" data-seg="ha">🏠 Home Assistant OS</button>
        <button type="button" class="seg-btn" data-seg="truenas">🗄️ TrueNAS SCALE</button>
        <button type="button" class="seg-btn" data-seg="proxmox">🖥️ Proxmox VE</button>
      </div>
      <div data-pane="proxmox" hidden>
        <p>${L('Proxmox VE is Debian: de gewone agent draait er, als <b>root</b> op de host (niet in een VM of container). Vanaf agent 1.61.0 leest hij ook Proxmox zelf uit met <code>pvesh</code>: de guests (VM\'s en containers), de opslag, ZFS-pools, de cluster, de nieuwste back-up per guest en de back-uptaken. Een update van Proxmox laat de agent staan.')}</p>
        <ol class="steps">
          <li>${L('Open de shell van de node (<i>Datacenter → node → Shell</i>, of SSH als root).')}</li>
          <li>${L('Maak een koppelcode en voer het commando uit:')}
            <div data-pair-pane="proxmox">${pairButton()}</div></li>
          <li>${L('Daarna: <b>＋ Aanbevolen checks</b> (overzicht, back-ups, opslag, ZFS en guests naar keuze), en <b>🖥️ Proxmox</b> bij deze server voor het overzicht.')}</li>
        </ol>
        <p class="muted small">${L('Meerdere losse nodes? Zet ze in het overzicht (🖥️ Proxmox) in dezelfde <i>cluster</i>; dan zie je ze samen. Uitzetten op een node: <code>PVE=0</code> in <code>/etc/default/rzmon-agent</code>. Controleren: <code>sudo rzmon-agent-setup --status</code> toont de regel <i>Proxmox VE</i>.')}</p>
      </div>
      <div data-pane="truenas" hidden>
        <p>${L('Op TrueNAS SCALE horen <code>/usr</code> en <code>/etc</code> bij TrueNAS zelf. De agent komt daarom op je <b>pool</b> (<code>/mnt/&lt;pool&gt;/rzmon-agent</code>), en draait via een <b>cronjob van TrueNAS</b> die ook na een TrueNAS-update blijft staan.')}</p>
        <ol class="steps">
          <li>${L('Open in TrueNAS <i>System → Shell</i> (of log in met SSH als beheerder).')}</li>
          <li>${L('Maak een koppelcode en voer het commando uit:')}
            <div data-pair-pane="truenas">${pairButton()}</div></li>
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
          <li>${L('Tabblad <b>Configuratie</b>: vul de koppelcode in (eenmalig, 15 minuten geldig):')}</li>
        </ol>
        <div data-pair-pane="ha">${pairButton()}</div>
        <ol class="steps" start="4">
          <li>${L('<b>Starten</b>. In het logboek moet <code>Verbonden met RzMon ✔</code> staan.')}</li>
          <li>${L('Voeg hier checks toe met <b>＋</b>: <i>Service</i> <code>homeassistant</code>, <code>supervisor</code>, <code>updates</code> of <code>addon:&lt;slug&gt;</code>, en <i>Diskruimte</i> <code>/data</code>.')}</li>
        </ol>
        <p class="muted small">${L('Tip: zet er ook een ping-check en een TCP-check op poort 8123 bij. Die werken los van de agent.')}</p>
      </div>
      <div data-pane="linux">
      <p>${L('Voer dit één commando uit op <b>{name}</b> (Rocky/RHEL/Alma, Debian/Ubuntu, Raspberry Pi OS, Proxmox). Het installeert de agent, stelt de services voor die er draaien, zet een systemd-timer aan en stuurt een testrapport.', { name: esc(h.name) })}</p>
      <div data-pair-pane="linux">${pairButton()}</div>
      <p class="muted small">${L('Daarna: klik hier op <b>＋</b> en voeg checks toe (CPU, RAM, diskruimte, services). Bijwerken gaat daarna met de knop hierboven (of <code>sudo rzmon-agent-setup --update</code>) · verwijderen: <code>sudo rzmon-agent-setup --uninstall</code>.')}</p>
      <p class="muted small">🩺 ${L('<b>Komt er niets binnen?</b> Voer op de server <code>sudo rzmon-agent-setup --test</code> uit. Die controleert stap voor stap planning, netwerk, adres, token en een proefrapport, en zegt wat er mis is.')}</p>
      <p class="muted small">⚠️ ${L('De server moet RzMon kunnen bereiken op <code>{url}</code>. Klopt dat adres niet, pas dan de <i>Basis-URL</i> aan in Setup.', { url: esc(base) })}</p>
      <details class="small api-docs">
        <summary>${L('Oude manier: met het token (agent ouder dan 1.50.0)')}</summary>
      <p class="muted small">${L('Zonder koppelcode: de agent stuurt dan zijn token mee. Een agent van 1.50.0 of nieuwer krijgt bij zijn eerste versleutelde rapport alsnog een eigen sleutel.')}</p>
      <div class="copy-line"><pre class="code" id="agent-oneliner">${esc(installCmd)}</pre>
        <button type="button" class="btn" id="copy-oneliner">${L('Kopieer')}</button></div>
      </details>
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
      </div>
      </details>`,
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
  const unhide = $('#host-edr-unhide');
  if (unhide) unhide.onclick = async () => { try { await edrHideSave(h.id, []); unhide.parentElement.remove(); toast(L('Wordt weer getoond')); } catch (ex) { toast(ex.message, 'err'); } };
  $('#host-edr').onchange = async (e) => {
    try {
      await api('edr_host_set', { host_id: h.id, on: e.target.value === '1' });
      toast(e.target.value === '1' ? L('Endpoint-bewaking aan voor {name}; de agent pakt het binnen een minuut op', { name: h.name }) : L('Endpoint-bewaking uit voor {name}', { name: h.name }));
      loadEdrHost(h);
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  loadEdrHost(h);
  $('#host-realtime').onchange = async (e) => {
    try {
      await api('host_realtime', { id: h.id, on: e.target.value === '1' });
      toast(e.target.value === '1' ? L('Realtime aan voor {name}; de agent pakt het binnen een minuut op', { name: h.name }) : L('Realtime uit voor {name}; de agent pakt het binnen een minuut op', { name: h.name }));
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  const wdSave = async () => {
    try {
      await api('host_watchdog', { id: h.id, on: $('#host-watchdog').value === '1', min: +$('#host-wd-min').value || 5 });
      toast($('#host-watchdog').value === '1' ? L('Waakhond aan voor {name}; de agent pakt het binnen een paar minuten op', { name: h.name }) : L('Waakhond uit voor {name}', { name: h.name }));
      refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#host-watchdog').onchange = wdSave;
  $('#host-wd-min').onchange = () => { if ($('#host-watchdog').value === '1') wdSave(); };
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
  // Koppelcode: één code voor alle drie de manieren; daarna wachten tot de agent gekoppeld is
  const makePair = async () => {
    let r;
    try { r = await api('agent_pair_code', { id: h.id }); } catch (ex) { toast(ex.message, 'err'); return; }
    const copyLine = (txt) => `<div class="copy-line"><pre class="code">${esc(txt)}</pre><button type="button" class="btn" data-copy="${esc(txt)}">${L('Kopieer')}</button></div>`;
    const head = `<div class="pair-code"><span class="muted small">${L('Koppelcode')}</span> <b class="mono">${esc(r.code)}</b> <span class="pair-status muted small"></span></div>`;
    const ha = `monitor_url: ${base}\npair_code: ${r.code}${r.pin && base.startsWith('https') ? `\npin: ${r.pin}` : ''}`;
    $$('[data-pair-pane]', body).forEach((p) => {
      const k = p.dataset.pairPane;
      p.innerHTML = head + (k === 'ha' ? copyLine(ha) : copyLine(pairCommand(base, r, k === 'truenas' ? ' --truenas' : '')))
        + `<p class="muted small">${k === 'ha' ? L('Opslaan en de add-on (her)starten. In het logboek moet <code>Gekoppeld met RzMon</code> staan.')
          : L('Het commando controleert eerst het installatiescript en de agent (SHA-256)')}${r.pin ? ' · 📌 ' + L('alleen het certificaat van deze RzMon') : ''}</p>`;
    });
    const until = Date.now() + r.minutes * 60000;
    const tick = async () => {
      if (!$('#modal').open || !$('.pair-status', body)) return;
      const left = Math.max(0, Math.round((until - Date.now()) / 1000));
      let done = false;
      try { const me = (await api('security')).hosts.find((x) => +x.id === +h.id); done = me && !+me.pairing && +me.agent_key_seen === 1; } catch (ex) { /* volgende keer */ }
      $$('.pair-status', body).forEach((el) => {
        el.className = 'pair-status small ' + (done ? 'good-txt' : left ? 'muted' : 'warn-txt');
        el.textContent = done ? '✔ ' + L('Gekoppeld: de agent heeft zijn eigen sleutel') : left ? '⏳ ' + L('wacht op de agent · nog {t}', { t: `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}` }) : L('verlopen: maak een nieuwe koppelcode');
      });
      if (done) { refresh(); loadSecurity(); } else if (left) setTimeout(tick, 3000);
    };
    tick();
  };
  body.addEventListener('click', async (e) => {
    if (e.target.closest('[data-make-pair]')) return makePair();
    const c = e.target.closest('[data-copy]');
    if (!c) return;
    try { await navigator.clipboard.writeText(c.dataset.copy); toast(L('Gekopieerd')); }
    catch (ex) { getSelection().selectAllChildren(c.previousElementSibling); toast(L('Geselecteerd — druk Ctrl+C'), 'err'); }
  });
  $$('[data-host-conn]', body).forEach((sel) => (sel.onchange = () => saveHostConn(sel, () => { refresh(); loadSecurity(); })));
  const amUrl = $('[data-am-url]', body);
  if (amUrl) amUrl.onclick = () => agentUrlDialog(findHost(h.id));
  if (pairNow && isAdmin()) makePair();
  $('#agent-live').onclick = async (e) => {
    if (e.target.closest('[data-agent-https]')) return agentUrlDialog(findHost(h.id));
    const cl = e.target.closest('[data-agent-clean]');
    if (cl) {
      if (!confirm(L('Opruimen: RzMon wist het oude token van deze server, en de agent installeert zichzelf opnieuw (eerst een reservekopie van de huidige agent en instellingen, daarna zonder overbodige regels). Doorgaan?'))) return;
      cl.disabled = true;
      try {
        const r = await api('agents_cleanup', { id: h.id });
        toast(r.reinstall ? L('Opruimen gevraagd; de agent pakt het op bij zijn volgende rapport') : L('Token gewist. Deze agent kan zichzelf niet opnieuw installeren: op de server rzmon-agent-setup --update'));
      } catch (ex) { toast(ex.message, 'err'); }
      return pollAgentStatus(h.id);
    }
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
  else if (isPveHost(h) || /proxmox|\bpve/i.test(h.name) || h.metrics.includes('service:pvedaemon')) show('proxmox');
}

/** Aanbevolen commando-checks voor deze server (TrueNAS, Proxmox of Linux), met vinkjes; bestaande slaan we over. */
/** Aanbevolen checks voor een Proxmox-host (1.105.0): overzicht, back-ups, opslag, ZFS en guests naar keuze */
async function pveRecommended(h) {
  let o;
  try { o = await api('pve_overview', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const me = o.nodes.find((n) => +n.host_id === +h.id);
  if (!me) return toast(L('Nog geen Proxmox-gegevens van deze server'), 'err');
  const has = (metric, target = '') => h.checks.some((c) => c.type === 'pve' && c.metric === metric && (c.target || '') === target);
  const items = [
    { key: 'overview', label: PVE_METRICS.overview.label, on: true, data: { metric: 'overview' } },
    { key: 'backups', label: L('Back-ups van alle guests (2 / 7 dagen)'), on: true, data: { metric: 'backups', warn_threshold: 2, crit_threshold: 7 } },
    { key: 'storage', label: L('Opslag (alle, 85 / 95%)'), on: true, data: { metric: 'storage', warn_threshold: 85, crit_threshold: 95 } },
    ...(me.node.zfs.length ? [{ key: 'zfs', label: L('ZFS-pools ({list})', { list: me.node.zfs.map((z) => z.name).join(', ') }), on: true, data: { metric: 'zfs', warn_threshold: 80, crit_threshold: 90 } }] : []),
    ...me.guests.filter((g) => !+g.template).map((g) => ({ key: 'g' + g.vmid, guest: g, on: false,
      label: `${g.kind === 'ct' ? '📦' : '🖥️'} ${g.kind === 'ct' ? 'CT' : 'VM'} ${g.vmid} ${g.name}`, data: { metric: 'guest', target: String(g.vmid), warn_threshold: 90, crit_threshold: 95 } })),
  ].map((x) => ({ ...x, exists: has(x.data.metric, x.data.target || '') }));
  const row = (x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${x.key}" ${x.exists ? 'disabled' : x.on ? 'checked' : ''}>
    <span><b>${esc(x.label)}</b>${x.guest ? ` <span class="muted small">${esc(x.guest.status)}${+x.guest.onboot ? ' · ' + L('start automatisch') : ''}</span>` : ''}${x.exists ? ' · ' + L('al aanwezig') : ''}</span></label>`;
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    wide: true,
    body: `<p style="margin-top:0">${L('Herkend als <b>Proxmox VE</b> ({v}). Vink aan wat je wilt bewaken.', { v: esc((me.node.version || '').replace(/^pve-manager\/([\d.]+).*$/, '$1')) })}</p>
      <div class="rec-list">${items.filter((x) => !x.guest).map(row).join('')}</div>
      <h4 class="acc-sub">${L('Guests: een eigen check per guest (aan of uit, RAM, guest agent)')}</h4>
      <p class="muted small" style="margin-top:0">${L('Het overzicht meldt al een guest die automatisch moet starten en uit staat. Kies hier de guests die je los wilt volgen.')}</p>
      <div class="rec-list">${items.filter((x) => x.guest).map(row).join('') || `<p class="muted small">${L('Geen guests gevonden.')}</p>`}</div>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const x of chosen) await api('check_save', { host_id: h.id, type: 'pve', enabled: true, interval_sec: x.data.metric === 'backups' ? 900 : 120, verify_ssl: true, ...x.data });
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

const pveBytes = (b) => (+b ? (+b / 1073741824 >= 1024 ? dec((+b / 1099511627776).toFixed(1)) + ' TB' : dec((+b / 1073741824).toFixed(+b >= 10737418240 ? 0 : 1)) + ' GB') : '–');
const pveAgo = (ts) => (ts ? ago(new Date(ts * 1000).toISOString()) : '–');

/** Overzicht van een Proxmox-node, of van alle nodes in dezelfde (aangemerkte) cluster */
async function pveOverview(h) {
  let o;
  try { o = await api('pve_overview', null, { host_id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const now = Date.now() / 1000;
  const bkCls = (g) => (!g.bk_last ? 'crit-txt' : now - g.bk_last > 7 * 86400 ? 'crit-txt' : now - g.bk_last > 2 * 86400 ? 'warn-txt' : 'good-txt');
  const nodeHtml = (n) => {
    const p = n.node;
    const chk = (metric, target = '') => n.checks.find((c) => c.metric === metric && (c.target || '') === String(target));
    const guests = n.guests.map((g) => {
      const run = g.status === 'running';
      const memPct = +g.maxmem ? Math.round(g.mem / g.maxmem * 100) : null;
      const c = chk('guest', g.vmid);
      return `<tr class="${+g.template ? 'muted' : ''}"><td class="nowrap">${g.kind === 'ct' ? '📦' : '🖥️'} <b>${g.vmid}</b></td>
        <td><b>${esc(g.name || '–')}</b>${+g.template ? ` <span class="badge">${L('sjabloon')}</span>` : ''}${g.lock ? ` <span class="badge-root">🔒 ${esc(g.lock)}</span>` : ''}
          <br><span class="muted small">${g.kind === 'ct' ? L('container') : 'VM'}${+g.onboot ? ' · ' + L('start automatisch') : ''}${g.kind === 'vm' && +g.agent ? ' · ' + (g.ga === 0 ? '⚠️ ' + L('guest agent antwoordt niet') : L('guest agent')) : ''}</span></td>
        <td>${run ? `<span class="good-txt">● ${L('draait')}</span>` : `<span class="${+g.onboot && !+g.template ? 'crit-txt' : 'muted'}">○ ${esc(g.status)}</span>`}${run ? `<br><span class="muted small">${L('aan sinds {t}', { t: esc(pveAgo(now - g.uptime)) })}</span>` : ''}</td>
        <td class="small">${run ? `CPU ${dec(g.cpu ?? 0)}% · ${g.cpus} vCPU<br>RAM ${pveBytes(g.mem)} / ${pveBytes(g.maxmem)}${memPct !== null ? ` (${memPct}%)` : ''}` : `<span class="muted">RAM ${pveBytes(g.maxmem)}</span>`}</td>
        <td class="small">${+g.template ? '–' : `<span class="${bkCls(g)}">${g.bk_last ? esc(pveAgo(g.bk_last)) : L('nooit')}</span>${g.bk_n ? `<br><span class="muted">${L('{n}× op {s}', { n: g.bk_n, s: esc(g.bk_store || '') })}</span>` : ''}${g.job === 0 ? `<br><span class="warn-txt">${L('in geen back-uptaak')}</span>` : ''}`}</td>
        <td class="nowrap">${c ? `<span class="badge" title="${esc(L('Er is al een check voor deze guest'))}">✔ ${L('check')}</span>` : +g.template ? '' : `<button type="button" class="btn small-btn need-admin" data-pve-add="${n.host_id}:${g.vmid}">＋ Check</button>`}</td></tr>`;
    }).join('');
    const stor = p.storage.map((s) => {
      const pct = +s.total ? Math.round(s.used / s.total * 100) : null;
      return `<tr><td><b>${esc(s.name)}</b><br><span class="muted small">${esc(s.type)}${+s.shared ? ' · ' + L('gedeeld') : ''}</span></td>
        <td>${!+s.enabled ? `<span class="muted">${L('uit')}</span>` : +s.active ? `<span class="good-txt">● ${L('actief')}</span>` : `<span class="crit-txt">✘ ${L('niet beschikbaar')}</span>`}</td>
        <td class="small">${pct !== null ? `<span class="${pct >= 95 ? 'crit-txt' : pct >= 85 ? 'warn-txt' : ''}">${pct}%</span> · ${pveBytes(s.used)} / ${pveBytes(s.total)}` : '–'}</td>
        <td class="small muted">${esc(s.content.replace(/,/g, ', '))}</td></tr>`;
    }).join('');
    const zfs = p.zfs.map((z) => `<li><b>${esc(z.name)}</b>: <span class="${z.health === 'ONLINE' ? 'good-txt' : 'crit-txt'}">${esc(z.health)}</span> · ${z.cap}% ${L('vol')} (${pveBytes(z.alloc)} / ${pveBytes(z.size)})
      <br><span class="muted small">${esc(z.scan || '')}</span></li>`).join('');
    const jobs = p.jobs.map((j) => `<li>${j.on ? '✔' : `<span class="muted">${L('uit')}</span>`} <b>${esc(j.id || '?')}</b> · ${esc(j.schedule || '')} → ${esc(j.storage || '')} · ${j.all ? L('alle guests') : j.pool ? L('pool {p}', { p: esc(j.pool) }) : esc(j.vmids.join(', '))}</li>`).join('');
    const lt = p.last_task, lf = p.last_fail;
    return `<section class="pve-node">
      <h3 style="margin:.4rem 0">🖥️ ${esc(p.node || n.host)} <span class="muted small">${esc((p.version || '').replace(/^pve-manager\/([\d.]+).*$/, 'PVE $1'))} · ${L('{r} van {t} draaien', { r: p.n.running, t: p.n.vm + p.n.ct })} · ${L('bijgewerkt {t}', { t: esc(pveAgo(p.at)) })}</span></h3>
      <div class="table-scroll"><table class="vuln-table"><thead><tr><th>ID</th><th>${L('Naam')}</th><th>${L('Status')}</th><th>${L('Gebruik')}</th><th>${L('Laatste back-up')}</th><th></th></tr></thead><tbody>${guests || `<tr><td colspan="6" class="muted">${L('Geen guests.')}</td></tr>`}</tbody></table></div>
      <h4 class="acc-sub">💾 ${L('Opslag')}</h4>
      <div class="table-scroll"><table class="vuln-table"><tbody>${stor}</tbody></table></div>
      ${zfs ? `<h4 class="acc-sub">🗄️ ZFS</h4><ul class="small">${zfs}</ul>` : ''}
      <h4 class="acc-sub">📅 ${L('Back-uptaken')}</h4>
      ${jobs ? `<ul class="small">${jobs}</ul>` : `<p class="warn-txt small">${L('Geen back-uptaken (Datacenter → Backup).')}</p>`}
      <p class="small">${lt ? L('Laatste taak: {t} · {s}', { t: esc(new Date(lt.start * 1000).toLocaleString(LOCALE)), s: lt.status === 'OK' ? '✔ OK' : '✘ ' + esc(lt.status) }) : L('Nog geen back-uptaak gezien.')}
        ${lf && (!lt || lf.start !== lt.start) ? `<br><span class="muted">${L('Laatst mislukt: {t} ({s})', { t: esc(new Date(lf.start * 1000).toLocaleString(LOCALE)), s: esc(lf.status) })}</span>` : ''}</p>
    </section>`;
  };
  const clusterOpts = o.clusters.map((c) => `<option value="${esc(c)}">`).join('');
  modal({
    title: '🖥️ ' + (o.cluster ? L('Proxmox-cluster {c}', { c: o.cluster }) : L('Proxmox – {name}', { name: h.name })),
    wide: true,
    body: `<div class="cmd-host-row need-admin">🔗 ${L('Cluster:')} <input id="pve-cluster" list="pve-clusters" value="${esc(o.cluster)}" placeholder="${esc(L('geen (losse node)'))}" style="max-width:14rem">
        <datalist id="pve-clusters">${clusterOpts}</datalist> <button type="button" class="btn small-btn" id="pve-cluster-save">${L('Opslaan')}</button>
        <span class="muted small">${L('Geef losse nodes dezelfde naam om ze hier samen te zien.')}</span></div>
      ${o.nodes.length ? o.nodes.map(nodeHtml).join('') : `<p class="muted">${L('Nog geen Proxmox-gegevens. Werk de agent bij naar {v} of nieuwer.', { v: '1.61.0' })}</p>`}`,
    buttons: [...(isAdmin() ? [{ label: L('＋ Aanbevolen checks'), style: 'margin-right:auto', onClick: () => { $('#modal').close(); pveRecommended(findHost(h.id)); } }] : []),
      { label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  $('#pve-cluster-save').onclick = async () => {
    try { await api('pve_cluster_set', { host_id: h.id, name: $('#pve-cluster').value }); toast(L('Opgeslagen')); $('#modal').close(); pveOverview(h); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#modal-body').onclick = async (e) => {
    const b = e.target.closest('[data-pve-add]');
    if (!b) return;
    const [hid, vmid] = b.dataset.pveAdd.split(':');
    b.disabled = true;
    try {
      await api('check_save', { host_id: +hid, type: 'pve', metric: 'guest', target: vmid, enabled: true, interval_sec: 120, verify_ssl: true, warn_threshold: 90, crit_threshold: 95 });
      b.outerHTML = `<span class="badge">✔ ${L('check')}</span>`;
      toast(L('Check toegevoegd'));
      refresh();
    } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
  };
}

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

/** Aanbevolen checks voor een camera (1.76.0): bereikbaar, videostroom (RTSP) en de webpagina */
function cameraRecommended(h) {
  const has = (type, target = '') => h.checks.some((c) => c.type === type && (target === '' || String(c.target) === target));
  const tcp = (port, name) => ({ type: 'tcp', target: String(port), name, warn_threshold: TYPES.tcp.warn ?? '', crit_threshold: TYPES.tcp.crit ?? '' });
  const items = [
    { key: 'ping', label: L('Ping: is de camera bereikbaar?'), exists: has('ping'), on: true, data: { type: 'ping', name: 'Ping', warn_threshold: 100, crit_threshold: 500 } },
    { key: 'rtsp', label: L('RTSP (poort 554): komt de videostroom door?'), exists: has('tcp', '554'), on: true, data: tcp(554, 'RTSP') },
    { key: 'http', label: L('Webpagina (poort 80)'), exists: has('tcp', '80'), on: false, data: tcp(80, L('Webpagina')) },
    { key: 'https', label: L('Webpagina via https (poort 443)'), exists: has('tcp', '443'), on: false, data: tcp(443, L('Webpagina (https)')) },
  ];
  modal({
    title: L('＋ Aanbevolen checks – {name}', { name: h.name }),
    body: `<p style="margin-top:0">${L('Herkend als <b>📷 camera</b>. Vink aan wat je wilt bewaken; je kunt elke check later nog aanpassen.')}</p>
      <div class="rec-list">${items.map((x) => `<label class="rec-row ${x.exists ? 'muted' : ''}"><input type="checkbox" name="rec_${x.key}" ${x.exists ? 'disabled' : x.on ? 'checked' : ''}>
        <span><b>${esc(x.label)}</b>${x.exists ? ' · ' + L('al aanwezig') : ''}</span></label>`).join('')}</div>
      <p class="muted small">${L('Geeft de camera zijn beeld via een andere poort (bv. 8554 of 7447 bij UniFi Protect), voeg dan zelf een poort-check toe (＋ Check → Poort).')}</p>`,
    buttons: [{ label: L('Toevoegen'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const chosen = items.filter((x) => !x.exists && d['rec_' + x.key]);
      if (!chosen.length) throw new Error(L('Vink minstens één check aan'));
      for (const x of chosen) await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, ...x.data });
      toast(L('{n} check(s) toegevoegd', { n: chosen.length }));
      refresh();
    },
  });
}

async function recommendedChecks(h) {
  if (isHaHost(h)) return haRecommended(h);
  if (isPveHost(h)) return pveRecommended(h);
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

/** Endpoint-bewaking in de Agent Manager: welke sensoren werken, wat ontbreekt (met installeren), en de voortgang */
const EDR_AUDIT = { ok: ['✔', () => L('processen en bewaakte paden (auditd)')], missing: ['✘', () => L('auditd ontbreekt')], stopped: ['⚠️', () => L('auditd is geïnstalleerd maar draait niet')],
  locked: ['⚠️', () => L('auditd staat op slot (-e 2): regels pas na een herstart')], noroot: ['✘', () => L('de agent draait niet als root')], off: ['·', () => L('uit')] };
async function loadEdrHost(h, poll = false) {
  const box = $('#host-edr-info');
  if (!box || !$('#modal').open) return;
  let d;
  try { d = await api('edr_host', null, { id: h.id }); } catch (ex) { box.innerHTML = `<span class="small bad">${esc(ex.message)}</span>`; return; }
  const i = d.info || {};
  const lines = [];
  if (!d.ids) lines.push(`<span class="warn-txt">⚠️ ${L('Werkt pas als het IDS aan staat (Instellingen → IDS).')}</span>`);
  else if (!d.global) lines.push(`<span class="muted">${L('Staat voor alle servers uit (Instellingen → IDS → Endpoint-bewaking).')}</span>`);
  else if (!d.on) lines.push(`<span class="muted">${L('Uit voor deze server.')}</span>`);
  else if (!d.at) lines.push(`<span class="muted">${L('Nog geen gegevens van de agent (agent {v} of nieuwer nodig).', { v: '1.63.0' })}</span>`);
  else {
    const a = EDR_AUDIT[i.audit] || ['·', () => esc(i.audit || '?')];
    lines.push([`${a[0]} ${a[1]()}`, i.file === 'ok' ? `✔ ${L('bestanden (inotify)')}` : `✘ ${L('inotify-tools ontbreekt')}`, `✔ ${L('netwerk')}`,
      i.ctr && i.ctr !== 'none' ? `✔ ${L('containers ({rt})', { rt: esc(i.ctr) })}` : `· ${L('geen containers')}`,
      i.watch === 'on' ? `✔ ${L('realtime-watcher actief')}` : `⚠️ ${+i.systemd ? L('de realtime-watcher draait (nog) niet') : L('geen systemd: alleen bij de gewone runs')}`,
      ...(i.yara ? [i.yara === 'ok' ? `✔ YARA${i.ysha ? '' : ' ' + L('(regels volgen)')}` : `○ ${L('geen YARA')}`] : []),
      ...(i.sur === 'ok' ? [`✔ Suricata`] : i.sur === 'noeve' ? [`⚠️ ${L('Suricata zonder eve.json')}`] : [])].join(' · '));
    if (+i.drop > 0) lines.push(`<span class="warn-txt">${L('{n} gebeurtenissen overgeslagen (te veel tegelijk)', { n: +i.drop })}</span>`);
  }
  const up = d.install && d.install.mode === 'install' ? d.install : null;
  const busy = up && (up.state === 'queued' || up.state === 'running');
  if (busy) lines.push(`⏳ ${L('Wordt geïnstalleerd: {p}', { p: esc((up.pkgs || []).join(' ')) })}${up.pct ? ` (${+up.pct}%)` : ''}`);
  else if (up && up.state === 'ok' && Date.now() - new Date(up.at.replace(' ', 'T')) < 3600e3) lines.push(`<span class="good">✔ ${L('Geïnstalleerd: {p}', { p: esc((up.pkgs || []).join(' ')) })}</span>`);
  else if (up && up.state === 'error') lines.push(`<span class="bad">✘ ${L('Installeren mislukt: {p}', { p: esc((up.pkgs || []).join(' ')) })}</span>${up.log ? `<pre class="code small">${esc(up.log.slice(-400))}</pre>` : ''}`);
  const comps = [...d.missing, ...(d.optional || [])];
  const rpm = d.fam === 'rhel';
  // 1.124.0: precies wat de agent uitvoert (zelfde pakketten als Edr::install), en de versie om zelf te typen
  const pkgsOf = (c) => (c === 'auditd' ? [rpm ? 'audit' : 'auditd'] : [...(rpm && !+i.epel ? ['epel-release'] : []), c === 'yara' ? 'yara' : 'inotify-tools']);
  const agentCmd = (c, own = null) => {
    const p = own || pkgsOf(c), out = [];
    if (rpm) { if (p[0] === 'epel-release') out.push('dnf -y install epel-release'); out.push(`dnf -y install ${p.filter((x) => x !== 'epel-release').join(' ')}`); }
    else out.push('apt-get update', `apt-get install -y --no-install-recommends ${p.join(' ')}`);
    if (c === 'auditd') out.push('systemctl enable --now auditd');
    return out;
  };
  const ownCmd = (c, own = null) => agentCmd(c, own).map((x) => 'sudo ' + x.replace(/ -y\b/, '').replace(' --no-install-recommends', '')).join(' && ');
  if (d.on && d.ids && comps.length && !busy) {
    lines.push(comps.map((c, k) => `<div class="edr-miss"><b>${esc(EDR_COMP[c][0])}</b> <span class="muted small">${EDR_COMP[c][1]()}</span>
      ${d.install_blocked ? `<span class="muted small">· ${L('via de agent kan het hier niet: {why}', { why: esc(d.install_blocked) })}</span>`
        : `<button type="button" class="btn small-btn primary" data-edr-install="${c}">⬇ ${L('Installeren via de agent')}</button>`}
      <details class="cmd-dd small"><summary>${L('Commando')}</summary>
        ${d.install_blocked ? '' : `<div class="muted">${L('Dit voert de agent uit (als root):')}</div><pre class="code" data-edr-preview="${c}">${esc(agentCmd(c).join('\n'))}</pre>
          <label class="small edr-own">${L('Eigen pakketnamen')} <span class="muted">${L('(optioneel; gescheiden door spaties, hooguit 10; agent {v} of nieuwer)', { v: '1.66.0' })}</span>
            <span class="inline-row"><input class="mono" data-edr-own="${c}" value="${esc(pkgsOf(c).join(' '))}" spellcheck="false" autocomplete="off">
            <button type="button" class="btn small-btn" data-edr-install="${c}" data-own="1">⬇ ${L('Met deze pakketten installeren')}</button></span></label>`}
        <div class="muted">${L('Of zelf op de server:')}</div>${copyBox(`edr-own-${k}`, ownCmd(c))}</details></div>`).join(''));
  }
  withMorph(() => { box.innerHTML = `<div class="small">${lines.join('<br>')}</div>`; });
  wireCopy(box);
  // Eigen pakketnamen: het voorbeeld van wat de agent uitvoert loopt mee
  $$('[data-edr-own]', box).forEach((inp) => {
    inp.oninput = () => {
      const names = inp.value.trim().split(/[\s,]+/).filter(Boolean);
      const pre = $(`[data-edr-preview="${inp.dataset.edrOwn}"]`, box);
      if (pre) pre.textContent = agentCmd(inp.dataset.edrOwn, names.length ? names : null).join('\n');
      const mine = inp.closest('.cmd-dd') && $('.copy-line pre', inp.closest('.cmd-dd'));
      if (mine) mine.textContent = ownCmd(inp.dataset.edrOwn, names.length ? names : null);
    };
  });
  $$('[data-edr-install]', box).forEach((b) => {
    b.onclick = async () => {
      const c = b.dataset.edrInstall;
      const def = pkgsOf(c).join(' ');
      const ownVal = b.dataset.own ? ($(`[data-edr-own="${c}"]`, box) || {}).value || '' : '';
      const own = ownVal.trim().split(/[\s,]+/).filter(Boolean).join(' ') === def ? '' : ownVal.trim();
      const pw = await askConfirm({ title: L('{c} installeren op {name}?', { c: own ? own : EDR_COMP[c][0], name: h.name }),
        text: L('De agent installeert dit als root bij zijn volgende rapport.'), word: 'INSTALLEREN', ok: '⬇ ' + L('Installeren') });
      if (pw === null) return;
      try {
        await api('edr_install', { host_id: h.id, comp: c, confirm: pw, ...(own ? { pkgs: own } : {}) });
        toast(L('De agent installeert {c} bij zijn volgende rapport', { c: EDR_COMP[c][0] }));
        loadEdrHost(h, true);
      } catch (ex) { toast(ex.message, 'err'); }
    };
  });
  if (busy || poll) setTimeout(() => loadEdrHost(h, busy), 4000);
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
      // 🐕 Waakhond: werkt alleen met Pushover en een versleutelde verbinding (de gegevens gaan naar de agent)
      const wdi = $('#host-wd-info');
      if (wdi && s.watchdog) {
        wdi.innerHTML = !s.watchdog.pushover ? `<span class="warn-txt">⚠️ ${L('Zet eerst Pushover aan (Meldingen → Kanalen): de waakhond meldt via Pushover')}</span>`
          : !s.watchdog.enc ? `<span class="warn-txt">⚠️ ${L('Werkt alleen versleuteld: koppel de agent met een koppelcode (de Pushover-gegevens gaan naar de agent)')}</span>`
          : s.watchdog.on ? `✔ ${L('actief: na {n} min zonder RzMon een Pushover-bericht, en een bericht als de checks stilstaan', { n: s.watchdog.min })}`
          : L('deze agent stuurt zelf een Pushover-bericht als RzMon onbereikbaar is of de checks stilstaan');
      }
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
    // Verbindingscontrole (1.100.0)
    if (s.mode === 'agent' && s.seen && s.audit && s.audit.length) {
      const bad = s.audit.filter((i) => i.ok === false);
      const fixable = bad.some((i) => ['config', 'token', 'integrity', 'version'].includes(i.key));
      html += `<details class="audit-box" ${bad.length ? 'open' : ''}><summary class="small">🔐 <b>${L('Verbindingscontrole')}</b>: ${bad.length
        ? `<span class="warn-txt">${L('{n} aandachtspunt(en)', { n: bad.length })}</span>` : `<span class="good">${L('alles in orde')}</span>`}</summary>
        <ul class="audit-list">${s.audit.map((i) => `<li class="small"><span>${i.ok === true ? '✅' : i.ok === false ? '⚠️' : '·'}</span> <b>${esc(i.label)}</b> <span class="muted">${esc(i.detail)}</span></li>`).join('')}</ul>
        ${isAdmin() ? `<div class="btn-row">${fixable ? `<button type="button" class="btn small-btn primary" data-agent-clean>🧹 ${L('Opruimen')}</button>` : ''}
          ${s.audit.some((i) => i.key === 'https' && i.ok === false) ? `<button type="button" class="btn small-btn" data-agent-https>🌐 ${L('Naar https')}</button>` : ''}
          <span class="muted small">${L('Alles over de verbinding, op de server zelf:')} <code>rzmon-agent-setup --status</code></span></div>` : ''}</details>`;
    }
    if (s.report_warn && s.report_warn.length) {
      html += `<div class="fails"><span class="small warn-txt">⚠️ ${L('Het laatste rapport was deels onleesbaar; dit onderdeel is weggelaten (de rest kwam gewoon binnen):')}</span>
        ${s.report_warn.map((w) => `<div class="small"><b>${esc(w.key)}</b> · ${esc(w.error)}<br><code class="small">${esc(w.near)}</code></div>`).join('')}</div>`;
    }
    if (s.failures.length) {
      html += `<div class="fails"><span class="muted small">${L('Geweigerde pogingen (24 uur):')}</span>
        ${s.failures.map((f) => `<div class="small"><code>${esc(f.ip)}</code> · ${ago(f.created_at)}${+f.n > 1 ? ` (${f.n}×)` : ''} · ${esc(f.reason)}</div>`).join('')}</div>`;
    }
    if (gen === agentPollGen) withMorph(() => { box.innerHTML = html; });   // 1.109.0: wat je openklapte blijft open
  } catch (ex) {
    if (gen === agentPollGen) box.innerHTML = `<span class="muted small">${esc(ex.message)}</span>`;
  }
  setTimeout(() => pollAgentStatus(hostId, gen), 5000);
}

// ---------------------------------------------------------------- Alerts
/** Bericht zonder "[niet verzonden: …]" aan het eind; de reden apart (die staat bij het rode kanaal-icoon) */
function splitSent(msg) {
  const m = /\s*\[(?:niet verzonden|not sent): (.+)\]\s*$/s.exec(msg || '');
  return m ? [msg.slice(0, m.index), m[1]] : [msg || '', ''];
}

function channelIcons(a) {
  const tip = (title, icon) => `<span class="chi" title="${L(title)}">${icon}</span>`;
  const why = splitSent(a.message)[1];
  const bad = (title, icon) => `<span class="chi bad" title="${esc(L(title) + (why ? ': ' + why : ''))}">${icon}</span>`;
  if (a.kind === 'auth' || a.kind === 'authfail') return `<span class="muted" title="${L('Alleen op deze pagina, niet verstuurd')}">–</span>`;
  if (a.kind === 'ack') return tip('Bevestiging', '✔');
  const out = [];
  if (+a.sent_ok) out.push(tip('Verstuurd via Pushover', '📲'));
  else if (/Pushover/.test(why)) out.push(bad('Pushover mislukt', '📲'));
  if (a.email_ok !== null && a.email_ok !== undefined) out.push(+a.email_ok ? tip('Verstuurd per e-mail', '📧') : bad('E-mail mislukt', '📧'));
  if (a.webhook_ok !== null) out.push(+a.webhook_ok ? tip('Verstuurd naar Home Assistant', '🏠') : bad('Webhook mislukt', '🏠'));
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
  try { alertData = await api('alerts', null, { days: histDays.alerts }); } catch (ex) { toast(ex.message, 'err'); return; }
  keepOpen($('#alerts'), renderAlerts);
}

/**
 * Vandaag staat altijd open (1.78.0), op Meldingen, IDS-incidenten en Logins. Klapte je vandaag dicht en komt er iets
 * nieuws bij, dan gaat hij weer open. @return true als er vandaag iets bij kwam (dan data-fresh op het blok)
 */
const todayCount = {};
function todayFresh(kind, store, today, n) {
  const prev = todayCount[kind];
  todayCount[kind] = { day: today, n };
  if (prev && prev.day === today && n > prev.n) {
    delete store[today];
    return true;
  }
  return false;
}
/** Leeg blok voor vandaag ("Vandaag · geen meldingen 👌"), zodat je ziet dat het rustig is */
function todayEmpty(today, text) {
  return `<div class="alert-day today-empty"><div class="ad-sum"><span class="ad-title">${esc(dayLabel(today, today))}</span><span class="muted small">${text}</span></div></div>`;
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
  withMorph(draw);
  root.querySelectorAll('details').forEach((d) => { const k = key(d); if (!d.dataset.fresh && was.has(k) && d.open !== was.get(k)) d.open = was.get(k); });
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
  try { [idsData, accData] = await Promise.all([api('ids', null, { days: histDays.ids }), api('ids_access')]); } catch (ex) { toast(ex.message, 'err'); return; }
  keepOpen($('#tab-ids'), () => { renderIds(); renderAccess(); });
  showIdsTab();   // Live en Regels worden daar (opnieuw) geladen
}

function showIdsTab() {
  if (!['inc', 'acc', 'blk', 'prot', 'live', 'rules', 'anom', 'mitre'].includes(idsTab)) idsTab = 'inc';
  $$('#ids-tabs .seg-btn').forEach((x) => x.classList.toggle('active', x.dataset.it === idsTab));
  $$('#tab-ids .ids-pane').forEach((x) => { x.hidden = x.dataset.pane !== idsTab; });
  const nAnom = ((idsData && idsData.incidents) || []).filter((i) => (i.type === 'chain' || i.type === 'anomaly') && i.status === 'open').length;
  $('#it-n-anom').textContent = nAnom || '';
  if (idsTab === 'blk' && isAdmin()) loadBlocks();
  if (idsTab === 'live') loadEdrFeed();
  if (idsTab === 'rules') loadEdrRules();
  if (idsTab === 'anom') loadAnom();
  if (idsTab === 'mitre') loadMitre();
  if (idsTab === 'prot') loadProt();
}

// ---------------------------------------------------------------- IDS: 🛰️ Live en 🔏 Regels (endpoint-bewaking, 1.110.0)
let edrFeed = null, edrRules = null, ruleSrc = '';
const EDR_KIND_IC = { exec: '⚙️', net: '🌐', file: '📄', audit: '🔐', ctr: '📦', sur: '🦉' };
const EDR_AUDIT_KEY = { rzmon_id: () => L('accounts/sudo'), rzmon_persist: () => L('cron/systemd/ssh'), rzmon_mod: () => L('kernelmodule') };
const edrBase = (p) => String(p || '').split('/').pop();
function edrSummary(e) {
  const v = e.ev || {};
  switch (e.kind) {
    case 'exec': return `<b>${esc(edrBase(v.exe) || v.comm || '?')}</b> <code class="edr-cmd">${esc((v.cmd || '').slice(0, 160))}</code> <span class="muted">· ${esc(v.u || v.uid || '?')} · ${L('ouder')} ${esc(v.pc || '?')}</span>`;
    case 'net': return v.dir === 'listen' ? `<b>${esc(v.comm || edrBase(v.exe) || '?')}</b> ${L('luistert op poort {p}', { p: +v.lp })}`
      : `<b>${esc(v.comm || edrBase(v.exe) || '?')}</b> → <code>${esc(v.rip || '')}:${+v.rp}</code>`;
    case 'file': return `<code>${esc(v.path || '')}</code> <span class="muted">· ${fmtBytes(+v.size || 0)}${+v.elf ? ' · ELF' : ''}${v.susp ? ' · ' + esc(v.susp) : ''}</span>`;
    case 'audit': return `${esc((EDR_AUDIT_KEY[v.key] || (() => v.key))())}: <code>${esc(v.name || '')}</code> <span class="muted">· ${esc(edrBase(v.exe))} · ${esc(v.u || v.uid || '?')}</span>`;
    case 'ctr': return `${esc(v.rt || '')} <b>${esc(v.act || '')}</b> ${esc(v.name || '')} <span class="muted">${esc(v.img || '')}${v.cmd ? ' · ' + esc(v.cmd) : ''}${+v.priv ? ' · --privileged' : ''}</span>`;
    default: return esc(e.sig);
  }
}
/** Groene regel: iets van RzMon zelf, toegestaan (1.114.0) */
const EDR_OWN = { agent: () => L('agent'), rzmon: () => 'RzMon', net: () => L('verkeer met RzMon') };
function edrOwn(e) {
  const v = e.ev || {};
  const who = (EDR_OWN[e.own] || (() => e.own))();
  const tag = `<span class="own-tag">✔ ${L('toegestaan')} · ${esc(who)}</span>`;
  if (e.kind === 'own') {
    const n = v.n || {};
    const parts = [n.exec ? L('{n} proces(sen)', { n: n.exec }) : '', n.net ? L('{n} verbinding(en)', { n: n.net }) : '', n.audit ? L('{n} bewaakt(e) pad(en)', { n: n.audit }) : '', n.file ? L('{n} bestand(en)', { n: n.file }) : ''].filter(Boolean);
    return `<details class="own-det"><summary>${tag} ${esc(parts.join(', '))}</summary><ul class="small">${(v.s || []).map((x) => `<li><code>${esc(x)}</code></li>`).join('')}</ul></details>`;
  }
  if (e.kind === 'sur') return `${tag} Suricata: ${esc(v.sig || '')} <span class="muted">· ${esc(v.src)} → ${esc(v.dst)}:${+v.dport}</span>`;
  if (e.kind === 'dns') return `${tag} ${esc(v.title || '')}`;
  if (e.kind === 'web') return `${tag} ${esc(v.method || '')} <code>${esc(v.uri || '')}</code> <span class="muted">${L('van {ip}', { ip: esc(v.ip) })}</span>`;
  return `${tag} ${edrSummary(e)}${v._would ? `<br><span class="muted small">${L('zou {r} raken, maar is RzMon zelf', { r: esc(v._would) })}</span>` : ''}`;
}
async function loadEdrFeed() {
  const host = $('#edr-host').value, hits = $('#edr-hits').checked, own = $('#edr-own').checked;
  try { edrFeed = await api('edr_feed', null, { limit: 300, ...(host ? { host } : {}), ...(hits ? { hits: 1 } : {}), ...(own ? {} : { own: 0 }) }); } catch (ex) { $('#edr-feed').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sel = $('#edr-host');
  if (sel.options.length < 2) sel.innerHTML += (state.hosts || []).filter((h) => h.mode === 'agent').map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
  const evs = edrFeed.events;
  $('#edr-info').textContent = !edrFeed.on ? L('Endpoint-bewaking staat uit (Instellingen → IDS → Endpoint-bewaking)') : L('{n} gebeurtenissen (laatste 3 dagen, hooguit 300)', { n: evs.length });
  withMorph(() => {
    $('#edr-feed').innerHTML = evs.length ? `<div class="edr-list">${evs.map((e) => `<div class="edr-row ${e.rule ? 'hit s' + e.sev : ''}${e.own ? ' own' : ''}" data-key="e${e.id}">
      <span class="m-t" title="${esc(e.at)}">${esc(e.at.slice(5, 16).replace(' ', ' · '))}</span>
      <span class="edr-k" title="${esc(e.kind)}">${e.own ? '✔' : EDR_KIND_IC[e.kind] || '·'}</span>
      <span class="m-sv">${esc(e.host)}</span>
      <span class="edr-sum">${e.own ? edrOwn(e) : edrSummary(e)}${e.rule ? `<br><span class="edr-hit">${e.sev === 2 ? '🔴' : '🟠'} ${esc(e.rule_title || e.rule)}</span>` : ''}</span></div>`).join('')}</div>`
      : `<p class="muted">${edrFeed.on ? L('Nog niets gezien. Staat de endpoint-bewaking aan op je servers (Agent Manager), en is de agent 1.63.0 of nieuwer?') : ''}</p>`;
  });
}
async function loadEdrRules() {
  try { edrRules = await api('edr_rules'); } catch (ex) { $('#edr-rules').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderEdrRules();
}
function renderEdrRules() {
  if (!edrRules) return;
  const q = ($('#rule-q').value || '').trim().toLowerCase();
  const list = edrRules.rules.filter((r) => (!ruleSrc || r.src === ruleSrc) && (!q || (r.title + ' ' + r.what + ' ' + r.tags.join(' ') + ' ' + r.id).toLowerCase().includes(q)));
  const act = (a) => a.map((x) => ({ kill: '⛔ ' + L('stopt het proces'), block: '🚫 ' + L('blokkeert het IP'), isolate: '🧱 ' + L('isoleert de server') }[x] || x)).join(' · ');
  const on = edrRules.rules.filter((r) => r.on).length;
  withMorph(() => {
    $('#edr-rules').innerHTML = `<p class="muted small" style="margin-top:0">${L('{on} van {n} regels aan. Eigen regels van RzMon (met uitleg) en de Linux-regels van SigmaHQ (licentie DRL 1.1). Een regel uitzetten geldt voor alle servers; "Dit is normaal" bij een incident geldt voor één server.', { on, n: edrRules.rules.length })}
      ${edrRules.sigma_on ? '' : ` <span class="warn-txt">${L('Sigma-regels staan uit (Instellingen → IDS → Endpoint-bewaking).')}</span>`}</p>
      <div class="table-scroll"><table class="vuln-table rule-table"><thead><tr><th></th><th>${L('Regel')}</th><th>${L('Ernst')}</th><th>${L('Doet zelf')}</th><th>MITRE</th><th title="${esc(L('Treffers in de laatste 3 dagen'))}">${L('Treffers')}</th></tr></thead><tbody>
      ${list.map((r) => `<tr class="${r.on ? '' : 'muted'}" data-key="${esc(r.id)}"><td><label class="switch small need-admin"><input type="checkbox" data-rule="${esc(r.id)}" ${r.on ? 'checked' : ''}><span></span></label></td>
        <td><b>${esc(r.title)}</b> <span class="badge">${r.src === 'sigma' ? 'Sigma' : 'RzMon'}</span><br><span class="small muted">${esc((r.what || '').slice(0, 260))}${r.src === 'sigma' ? ` · ${esc(r.author || '')} · <a href="${esc(r.url)}" target="_blank" rel="noopener">${L('bron')}</a>` : ''}</span></td>
        <td class="nowrap">${r.sev === 2 ? '🔴 Critical' : '🟠 Warning'}</td><td class="small">${act(r.act) || '<span class="muted">–</span>'}</td>
        <td class="small mono">${r.tags.map((t) => `<a href="https://attack.mitre.org/techniques/${esc(t.replace('.', '/'))}/" target="_blank" rel="noopener">${esc(t)}</a>`).join(' ')}</td><td>${r.hits || ''}</td></tr>`).join('')}
      </tbody></table></div>`;
  });
}

// ---------------------------------------------------------------- IDS: 🧬 Afwijkingen, ketens en 🧭 MITRE (1.111.0)
let anomData = null, anomDays = 7, mitreData = null, mitreDays = 30;
const TACTIC_NL = {
  reconnaissance: () => L('Verkennen'), 'resource-development': () => L('Voorbereiden'), 'initial-access': () => L('Binnenkomen'), execution: () => L('Uitvoeren'),
  persistence: () => L('Blijven'), 'privilege-escalation': () => L('Rechten verhogen'), stealth: () => L('Verbergen'), 'defense-impairment': () => L('Beveiliging uitschakelen'),
  'credential-access': () => L('Wachtwoorden stelen'), discovery: () => L('Rondkijken'), 'lateral-movement': () => L('Verder het netwerk in'), collection: () => L('Verzamelen'),
  'command-and-control': () => L('Op afstand besturen'), exfiltration: () => L('Gegevens weghalen'), impact: () => L('Schade'),
};
const tacticName = (s) => (TACTIC_NL[s] || (() => s))();
const techLink = (t) => `<a class="chip" href="https://attack.mitre.org/techniques/${esc(t.replace('.', '/'))}/" target="_blank" rel="noopener">${esc(t)}</a>`;
const scoreTag = (n) => `<span class="score-tag ${n >= 60 ? 'crit' : n >= 40 ? 'warn' : ''}" title="${esc(L('Score: hoe ongewoon (0–100)'))}">${n}</span>`;
function hostOptions(sel) {
  if (sel.options.length < 2) sel.innerHTML += (state.hosts || []).filter((h) => h.mode === 'agent').map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
}
/** Stappen van een keten als tijdlijn (op de pagina en in het incidentvenster) */
function chainSteps(steps, attr = 'data-inc') {
  return `<ol class="chain-steps">${steps.map((s) => `<li class="${s.score >= 60 ? 'crit' : s.score >= 40 ? 'warn' : ''}">
    <span class="cs-t">${esc(fmtDateTime(s.at))}</span>
    <span class="cs-tac">${(s.tactics || []).slice(0, 2).map((t) => `<span class="chip tac">${esc(tacticName(t))}</span>`).join('')}</span>
    <span class="cs-l">${s.ref ? `<button type="button" class="linklike" ${attr}="${s.ref}">${esc(s.label)}</button>` : esc(s.label)}
      <span class="muted small">${s.src === 'anom' ? L('afwijking') : s.src === 'rule' ? L('regel') : 'IDS'}${(s.techs || []).length ? ' · ' + s.techs.slice(0, 3).map(esc).join(' ') : ''}</span></span></li>`).join('')}</ol>`;
}
async function loadAnom() {
  const host = $('#anom-host').value;
  try { anomData = await api('ids_anom', null, { days: anomDays, ...(host ? { host } : {}) }); } catch (ex) { $('#anom-learn').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  hostOptions($('#anom-host'));
  const d = anomData;
  $('#anom-info').textContent = !d.on ? L('Endpoint-bewaking staat uit (Instellingen → IDS → Endpoint-bewaking)') : !d.anom ? L('Afwijkingen staan uit (Instellingen → IDS → Endpoint-bewaking)') : '';
  withMorph(() => {
    $('#anom-learn').innerHTML = `<h3 class="set-sub" style="margin-top:0">🎓 ${L('Wat is normaal? (leren per server)')}</h3>
      <p class="muted small">${L('Elke server leert {d} dagen wat normaal is: welke programma\'s, gebruikers, verbindingen en poorten. Daarna meldt RzMon wat die server nog nooit deed. Na een grote wijziging (nieuwe software) kun je hem opnieuw laten leren.', { d: d.learn_days })}</p>
      ${d.hosts.length ? `<div class="table-scroll"><table class="vuln-table learn-table"><thead><tr><th>Server</th><th>${L('Leren')}</th><th>${L('Geleerd')}</th><th>${L('Afwijkingen (7 dagen)')}</th><th class="need-admin"></th></tr></thead><tbody>
      ${d.hosts.map((h) => `<tr data-key="lh${h.id}"><td><b>${esc(h.name)}</b>${h.on ? '' : ` <span class="muted small">(${L('uit')})</span>`}</td>
        <td>${h.learning ? `<span class="cl-meter"><span class="bar"><i style="width:${h.pct}%"></i></span> <span class="small">${L('dag {n} van {d}', { n: Math.max(1, h.day), d: h.days })}</span></span>`
          : `✔ <span class="small">${L('klaar, meldt sinds {t}', { t: esc(fmtDateTime(h.until)) })}</span>`}</td>
        <td class="small">${L('{n} kenmerken', { n: h.learned })}</td><td>${h.anoms || '<span class="muted">0</span>'}</td>
        <td class="need-admin nowrap">${h.learning ? `<button type="button" class="btn small-btn" data-learn="finish" data-h="${h.id}">${L('Leren afronden')}</button>` : ''}
          <button type="button" class="btn small-btn" data-learn="restart" data-h="${h.id}">↺ ${L('Opnieuw leren')}</button></td></tr>`).join('')}</tbody></table></div>`
        : `<p class="muted">${L('Nog geen servers met endpoint-bewaking (agent 1.63.0 of nieuwer, en aan in de Agent Manager).')}</p>`}`;
    $('#anom-chains').innerHTML = `<h3 class="set-sub" style="margin-top:0">🔗 ${L('Ketens')} <span class="muted small">${L('(laatste 30 dagen)')}</span></h3>
      ${!d.chain ? `<p class="warn-txt small">${L('Ketens staan uit (Instellingen → IDS → Endpoint-bewaking).')}</p>` : ''}
      ${d.chains.length ? d.chains.map((c) => `<div class="chain-card s${c.severity}" data-key="ch${c.id}">
        <div class="chain-head">${c.severity === 2 ? '🔴' : '🟠'} <button type="button" class="linklike" data-inc="${c.id}"><b>${esc(c.title)}</b></button>
          <span class="muted small">· ${esc(c.host)} · ${esc(fmtDateTime(c.created_at))}${c.hits > 1 ? ' · ' + L('{n}× gegroeid', { n: c.hits }) : ''} · ${c.status === 'open' ? `<b class="warn-txt">${L('open')}</b>` : '✔'}</span></div>
        ${chainSteps(c.steps)}${c.done.length ? `<p class="small">⛔ ${esc(c.done.join(', '))}</p>` : ''}</div>`).join('')
        : `<p class="muted">${L('Geen ketens 👌')}</p>`}
      <details class="small"><summary>${L('Welke ketens herkent RzMon?')}</summary><ul>${Object.values(d.scenarios).map((s) => `<li><b>${esc(s.title)}</b>: ${esc(s.what)}</li>`).join('')}</ul></details>`;
    $('#anom-list').innerHTML = `<h3 class="set-sub" style="margin-top:0">🧬 ${L('Afwijkingen')} <span class="muted small">${L('(vanaf score {n} ook een incident)', { n: d.min })}</span></h3>
      ${d.anomalies.length ? `<div class="edr-list">${d.anomalies.map((a) => `<div class="edr-row" data-key="a${a.id}">
        <span class="m-t" title="${esc(a.at)}">${esc(a.at.slice(5, 16).replace(' ', ' · '))}</span>${scoreTag(a.score)}
        <span class="m-sv">${esc(a.host)}</span>
        <span class="edr-sum">${a.ref ? `<button type="button" class="linklike" data-inc="${a.ref}">${esc(a.label)}</button>` : esc(a.label)}
          ${a.tactics.slice(0, 2).map((t) => `<span class="chip tac">${esc(tacticName(t))}</span>`).join('')}</span></div>`).join('')}</div>`
        : `<p class="muted">${d.hosts.some((h) => !h.learning) ? L('Geen afwijkingen 👌') : L('Nog niets: de servers zijn nog aan het leren.')}</p>`}`;
  });
}
async function loadMitre() {
  const host = $('#mitre-host').value;
  try { mitreData = await api('ids_mitre', null, { days: mitreDays, ...(host ? { host } : {}) }); } catch (ex) { $('#mitre-matrix').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  hostOptions($('#mitre-host'));
  renderMitre();
}
function renderMitre() {
  const d = mitreData;
  if (!d) return;
  const cover = $('#mitre-cover').checked;
  const total = d.tactics.reduce((s, t) => s + t.n, 0);
  withMorph(() => {
    $('#mitre-matrix').innerHTML = `<p class="muted small" style="margin-top:0">${L('Alles wat RzMon de laatste {d} dagen zag (regels, afwijkingen en IDS-meldingen), gerangschikt naar de stappen van een aanval volgens MITRE ATT&CK. Klik op een techniek voor de meldingen.', { d: d.days })}
      ${total ? '' : ' <b>' + L('Niets gezien 👌') + '</b>'}</p>
      <div class="mitre-scroll"><div class="mitre-grid">${d.tactics.map((t) => {
        const techs = t.techs.filter((x) => x.n > 0 || (cover && x.nrules > 0));
        return `<div class="mt-col ${t.n ? 'hit' : ''}" data-key="${esc(t.slug)}"><div class="mt-head" title="${esc(t.name + ' (' + t.id + ')')}"><b>${esc(t.nl)}</b><span class="muted small">${esc(t.name)}</span>${t.n ? `<span class="mt-n">${t.n}</span>` : ''}</div>
          ${techs.map((x) => `<button type="button" class="mt-cell ${x.n ? (x.score >= 60 ? 'crit' : 'warn') : 'cov'}" data-mt="${esc(x.id)}" data-ta="${esc(t.slug)}"
            title="${esc((x.id ? x.id + ' · ' : '') + x.name + (x.nrules ? '\n' + L('{n} regel(s) kunnen dit zien', { n: x.nrules }) + ': ' + x.rules.join(', ') : ''))}">
            <span class="mono small">${esc(x.id || '–')}</span> ${esc(x.name)}${x.n ? ` <b class="mt-n">${x.n}</b>` : ` <span class="muted small">🛡 ${x.nrules}</span>`}</button>`).join('') || `<span class="muted small">–</span>`}</div>`;
      }).join('')}</div></div>
      <p class="muted small">${esc(d.source)}. ${esc(d.license)}</p>`;
  });
}
async function mitreSignals(tech, tactic) {
  const host = $('#mitre-host').value;
  let d;
  try { d = await api('ids_mitre', null, { days: mitreDays, tech, tactic, ...(host ? { host } : {}) }); } catch (ex) { return toast(ex.message, 'err'); }
  const info = mitreData.tactics.find((t) => t.slug === tactic)?.techs.find((x) => x.id === tech) || {};
  modal({
    title: `🧭 ${esc(tech || L('overig'))} ${esc(info.name || '')}`,
    wide: true,
    body: `<p class="small" style="margin-top:0">${L('Stap: {t}', { t: esc(tacticName(tactic)) })}${tech ? ` · <a href="https://attack.mitre.org/techniques/${esc(tech.replace('.', '/'))}/" target="_blank" rel="noopener">${L('uitleg bij MITRE')}</a>` : ''}</p>
      ${d.signals.length ? `<div class="edr-list">${d.signals.map((s) => `<div class="edr-row"><span class="m-t">${esc(s.at.slice(5, 16).replace(' ', ' · '))}</span>${scoreTag(s.score)}<span class="m-sv">${esc(s.host)}</span>
        <span class="edr-sum">${s.ref ? `<button type="button" class="linklike" data-inc-step="${s.ref}">${esc(s.label)}</button>` : esc(s.label)} <span class="muted small">${s.src === 'anom' ? L('afwijking') : s.src === 'rule' ? L('regel') : 'IDS'}</span></span></div>`).join('')}</div>`
        : `<p class="muted">${L('Geen meldingen in deze periode. Deze regels kunnen het zien:')}</p><ul class="small">${(info.rules || []).map((r) => `<li>${esc(r)}</li>`).join('')}</ul>`}`,
  });
  $$('#modal [data-inc-step]').forEach((b) => { b.onclick = () => incidentDialog(+b.dataset.incStep); });
}

// ---------------------------------------------------------------- IDS: 🛡️ Bescherming en Waarom/Trigger/Actie (1.115.0)
let protData = null;
/** Labels met wat RzMon deed, achter de titel in de lijst (alleen echte acties, geen "gemeld") */
function actChips(p) {
  if (!p || !p.acts) return '';
  const short = { block: L('geblokkeerd'), kill: L('gestopt'), isolate: L('geïsoleerd'), close: L('afgehandeld'), learn: L('niet ingegrepen'), escalate: 'critical' };
  return p.acts.filter((a) => short[a[2]]).map(([icon, text, t, ok]) => ` <span class="act-chip ${ok === true ? 'ok' : ok === false ? 'bad' : ''} t-${t}" title="${esc(text)}">${icon} ${esc(short[t])}</span>`).join('');
}
/** Waarom / Trigger / Actie in het incidentvenster */
function explainHtml(x) {
  return `<table class="kv-table small explain"><tbody>
    ${x.why ? `<tr><th>${L('Waarom')}</th><td>${esc(x.why)}${x.card ? ` <a href="#ids/prot" class="small" data-goto="ids/prot" data-prot-card="${esc(x.card.id)}">🛡️ ${esc(x.card.title)}</a>` : ''}</td></tr>` : ''}
    ${x.trigger ? `<tr><th>${L('Trigger')}</th><td>${esc(x.trigger)}</td></tr>` : ''}
    <tr><th>${L('Actie')}</th><td>${x.actions.map((a) => `<div class="act-line ${a.ok === true ? 'ok' : a.ok === false ? 'bad' : ''}">${a.icon} ${esc(a.text)}${a.ok === true ? ' ✔' : a.ok === false ? ' ✘' : ''}</div>`).join('')}</td></tr>
  </tbody></table>`;
}
async function loadProt() {
  try { protData = await api('ids_protect'); } catch (ex) { $('#prot-cards').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderProt();
}
function renderProt() {
  const d = protData;
  if (!d) return;
  const block = d.mode === 'block';
  $('#it-n-prot').textContent = d.attention || '';
  withMorph(() => {
    $('#prot-mode').innerHTML = !d.enabled ? `<div class="prot-mode off">⚠️ ${L('Het IDS staat uit: er wordt niets bewaakt.')} <a href="#settings/ids" data-goto="settings/ids">${L('Aanzetten')}</a></div>`
      : `<div class="prot-mode ${block ? 'block' : 'learn'}">${block ? '⛔ ' + L('Modus Blokkeren: RzMon voert de acties hieronder zelf uit.') : '🎓 ' + L('Modus Leren: alles wordt alleen gemeld, RzMon grijpt nergens in.')}
        <a href="#settings/ids" data-goto="settings/ids" class="need-admin">${block ? L('Naar Leren') : L('Overschakelen naar Blokkeren')}</a>
        <br><span class="small muted">${L('{on} van {n} onderdelen aan', { on: d.on, n: d.total })}${d.attention ? ` · <span class="warn-txt">${L('{n} aandachtspunt(en)', { n: d.attention })}</span>` : ''}</span></div>`;
    $('#prot-cards').innerHTML = Object.entries(d.groups).map(([g, [icon, name]]) => `<h3 class="set-sub">${icon} ${esc(name)}</h3><div class="prot-grid">
      ${d.cards.filter((c) => c.g === g).map((c) => `<div class="prot-card ${c.toggle && !c.toggle.on ? 'off' : ''}" data-key="${esc(c.id)}" id="prot-${esc(c.id)}">
        <div class="prot-head"><b>${esc(c.title)}</b>${PROT_CFG[c.id] ? `<button type="button" class="icon-btn need-admin prot-cfg" data-prot-cfg="${esc(c.id)}" title="${esc(L('Drempels en details'))}">⚙️</button>` : ''}${c.toggle ? `<label class="switch small need-admin" title="${esc(c.toggle.label)}"><input type="checkbox" data-prot-toggle="${esc(c.toggle.key)}" ${c.toggle.on ? 'checked' : ''}><span></span></label>` : ''}</div>
        <p class="small muted">${esc(c.what)}</p>
        <div class="prot-acts">${c.sev === 2 ? '<span class="sev-chip s2">🔴 critical</span>' : c.sev === 1 ? '<span class="sev-chip s1">🟠 warning</span>' : ''} →
          ${c.acts.map(([icon, text, a]) => `<span class="act-chip t-${a} ${!block && ['block', 'kill', 'isolate', 'preblock'].includes(a) ? 'learn' : ''}" title="${esc(!block && ['block', 'kill', 'isolate', 'preblock'].includes(a) ? L('in de modus Leren alleen melden') : text)}">${icon} ${esc(text)}</span>`).join(' ')}
          ${c.toggle ? `<span class="small muted">(${esc(c.toggle.label)}: ${c.toggle.on ? L('aan') : L('uit')})</span>` : ''}</div>
        ${c.why ? `<p class="small">${esc(c.why)}</p>` : ''}
        <p class="small">${c.stat ? esc(c.stat) : c.n ? `${L('7 dagen: {n}×', { n: c.n })} · ${L('laatst {t}', { t: esc(fmtDateTime(c.last.at)) })} · <button type="button" class="linklike" data-prot-show="${esc(c.id)}">${L('Toon incidenten')}</button>`
          : `<span class="muted">${L('7 dagen: niets')}</span>`}</p>
        ${c.attention ? `<p class="small warn-txt">⚠️ ${esc(c.attention)}</p>` : ''}</div>`).join('')}</div>`).join('');
  });
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
/** Land van een IP (1.82.0): vlag en code, met de naam als tooltip; ☠️ = op een lijst met bekende aanvallers */
function ccTag(cc, bad = null) {
  const flag = cc && /^[A-Z]{2}$/.test(cc) ? String.fromCodePoint(...[...cc].map((c) => 0x1F1E6 + c.charCodeAt(0) - 65)) : '';
  let name = cc || '';
  try { if (cc) name = new Intl.DisplayNames([LOCALE], { type: 'region' }).of(cc) || cc; } catch (e) { /* oude browser */ }
  return (flag ? ` <span class="cc-tag" title="${esc(name)}">${flag} ${esc(cc)}</span>` : '') + (bad ? ` <span class="cc-tag bad" title="${esc(L('Bekende aanvaller ({list})', { list: bad }))}">☠️</span>` : '');
}
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
  $('#it-n-acc').textContent = d.rows.length ? d.rows.length : '';
  $('#acc-info').textContent = L('{ok} gelukt · {fail} mislukt · laatste {days} dagen', { ok: d.rows.length - fails, fail: fails, days: d.days }) + (d.truncated ? ' ' + L('(alleen de nieuwste 10.000)') : '');
  const off = d.enabled ? '' : '<p class="warn-txt small">' + L('Toegang bijhouden staat uit: er komt niets nieuws bij. Aanzetten onder <a href="#settings/ids" data-goto="settings/ids">Instellingen → IDS → Algemeen</a>.') + '</p>';
  const q = $('#acc-search').value.trim().toLowerCase();
  const list = d.rows.filter((r) => (accFilter === 'all' || (accFilter === 'fail' ? r.kind === 'authfail' : r.type === accFilter))
    && (!accHost || +r.host_id === +accHost) && (!q || `${r.message} ${r.host_name} ${accWho(r, accDet(r))}`.toLowerCase().includes(q)) && fxPass('acc', r));
  fxRender('acc');
  const narrowed = !!q || !!accHost || fxActive('acc');
  if (!list.length) {
    el.innerHTML = off + `<p class="muted">${d.rows.length ? L('Niets gevonden met dit filter.') : L('Nog geen logins, sudo of su.')}</p>`;
    $('#acc-toggle').hidden = true;
    return;
  }
  const byDay = new Map();
  list.forEach((r) => { const day = r.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(r); });
  const fresh = todayFresh('acc', accDayOpen, d.today, (byDay.get(d.today) || []).length);
  const isOpen = (day) => accDayOpen[day] ?? (day === d.today || narrowed);
  el.innerHTML = off + (!narrowed && accFilter === 'all' && !byDay.has(d.today) ? todayEmpty(d.today, L('nog geen logins, sudo of su')) : '') + [...byDay].map(([day, rows]) => {
    const nf = rows.filter((r) => r.kind === 'authfail').length;
    return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''} ${fresh && day === d.today ? 'data-fresh="1"' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${rows.length - nf ? `<span class="ad-count" title="${L('gelukt')}">🔑 ${rows.length - nf}</span>` : ''}${nf ? `<span class="ad-count" title="${L('mislukt')}">❌ ${nf}</span>` : ''}</span>
        <span class="muted small ad-total">${rows.length === 1 ? L('1 regel') : L('{n} regels', { n: rows.length })}</span></summary>
      <div class="ad-body">${isOpen(day) ? accBody(rows) : ''}</div></details>`;
  }).join('');
  $$('.alert-day', el).forEach((det) => (det.ontoggle = () => {
    accDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = accBody(byDay.get(det.dataset.day));
    accToggleLabel();
  }));
  accToggleLabel();
}

function accToggleLabel() {
  const days = $$('#ids-access .alert-day');
  $('#acc-toggle').hidden = days.length < 2;
  $('#acc-toggle').textContent = days.length && days.every((x) => x.open) ? '⊟ ' + L('Alles sluiten') : '⊞ ' + L('Alles openen');
}

/** L1nes: de tabel; Blox: compacte rijen zoals bij Meldingen, gelijke regels achter elkaar gebundeld */
function accBody(rows) {
  if (viewPrefs().layout === 'l1nes') return accTable(rows);
  const row = (r, group = null) => {
    const det = accDet(r);
    const fail = r.kind === 'authfail';
    return `<div class="mrow compact">
      <span class="m-t" title="${esc(r.created_at)}">${esc(r.created_at.slice(11, 16))}</span><i class="adot ${fail ? 's1' : 'info'}"></i>
      <span class="m-tt">${fail ? '❌ ' : '🔑 '}${esc(ACC_TYPES[r.type] || r.type)} · ${esc(accWho(r, det))}${fail && +det.n > 1 ? ` <span class="muted small">${+det.n}×</span>` : ''}${group ? ` <button type="button" class="rep" data-bundle-btn>${group}×</button>` : ''}<small>${esc(r.message)}${ccTag(det.cc, det.bad)}</small></span>
      <span class="m-sv">${esc(r.host_name)}</span>
      <span class="m-ch need-admin"><button type="button" class="linkbtn" data-acc="ignore" data-id="${r.id}" title="${L('Deze gebruiker of dit IP-adres voortaan niet meer tonen')}">🙈</button>${det.ip ? ` <button type="button" class="linkbtn" data-acc="block" data-id="${r.id}" title="${L('IP-adres {ip} blokkeren', { ip: esc(det.ip) })}">⛔</button>` : ''}</span></div>`;
  };
  return bundleRuns(rows, (r) => `${r.kind}|${r.type}|${r.host_id}|${accWho(r, accDet(r))}|${r.message}`).map((g) => {
    if (g.items.length === 1) return row(g.items[0]);
    const key = 'a' + g.items[g.items.length - 1].id;
    return `<div class="bundle${idsBundles.has(key) ? ' open' : ''}" data-bundle="${key}">${row(g.items[0], g.items.length)}${g.items.slice(1).map((r) => row(r)).join('')}</div>`;
  }).join('');
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
      <p class="muted small">${L('Een mislukte poging met meerdere gebruikers wordt alleen verborgen als <i>alle</i> gebruikers genegeerd worden, of het IP-adres. De lijst pas je aan onder <i>Instellingen → IDS → Algemeen → Negeren</i>. Brute-forcedetectie van het IDS blijft gewoon werken.')}</p>`,
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

const IDS_CATS = [['ports', () => L('Poorten'), ['port_new', 'kev_port']], ['net', () => '🌐 ' + L('Uitgaand'), ['outbound', 'out_bad', 'out_country']],
  ['web', () => '🕸️ ' + L('Webshell en diensten'), ['webshell', 'unit_new']], ['weblog', () => '📜 ' + L('Webdiensten'), ['web_attack']], ['files', () => L('Bestanden'), ['file', 'fim', 'fim_bulk']],
  ['procs', () => L('Processen'), ['proc', 'proc_suspicious', 'revshell']], ['logins', () => L('Logins'), ['bruteforce', 'breakin', 'rootlogin', 'newip', 'user_new', 'ssh_login', 'group_add', 'uid0']], ['decoy', () => '🍯 ' + L('Honeypot'), ['decoy_user', 'decoy_port']]];
const idsCatOf = (i) => (IDS_CATS.find((c) => c[2].includes(i.type)) || [''])[0];

/** Toegang in de laatste 24 uur: [gelukt, mislukt (pogingen), per uur mislukt] */
function accLast24() {
  if (!accData) return [0, 0, []];
  const since = Date.now() - 86400000;
  const hours = new Array(24).fill(0);
  let ok = 0, fail = 0;
  accData.rows.forEach((r) => {
    const t = new Date(r.created_at.replace(' ', 'T')).getTime();
    if (t < since) return;
    if (r.kind === 'authfail') { const n = +accDet(r).n || 1; fail += n; hours[Math.min(23, Math.floor((t - since) / 3600000))] += n; } else ok++;
  });
  return [ok, fail, hours];
}

let idsAttnOpen = false;   // "Nu aandacht nodig" op de IDS-pagina: alles tonen (anders 5)

/** Leren of Blokkeren als pil in de tellerbalk (1.117.0); klik = naar de instellingen */
function idsModePill(d) {
  if (!d.enabled) return `<a class="mode-pill off" href="#settings/ids" data-goto="settings/ids">⚠️ ${L('IDS staat uit')}</a>`;
  return `<a class="mode-pill ${d.mode === 'block' ? 'block' : 'learn'}" href="#settings/ids" data-goto="settings/ids" title="${esc(d.mode === 'block' ? L('brute force wordt gemeld én geblokkeerd') : L('alles wordt gemeld, er wordt niets geblokkeerd'))}">${d.mode === 'block' ? '⛔ ' + L('Blokkeren') : '🎓 ' + L('Leren')}</a>`;
}

/** Score-tegel met ring (1.117.0): gemiddelde hardening van de bewaakte servers, de toestand uit de open incidenten */
function idsScoreTile(d, open, o2) {
  const scores = d.hosts.filter((h) => !h.ha && h.hard && h.hard.score !== null).map((h) => +h.hard.score);
  const score = scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : null;
  const chains = open.filter((i) => i.type === 'chain').length;
  const st = !d.enabled ? 'warn' : o2 || chains ? 'bad' : open.length ? 'warn' : 'ok';
  const label = !d.enabled ? L('IDS staat uit') : o2 || chains ? L('Actie nodig') : open.length ? L('Aandacht') : L('Rustig');
  return `<div class="kpi kpi-main ${st}" data-igo="inc" title="${esc(L('Klik: de open incidenten'))}">
    <div class="ring" style="--p:${score ?? 0}" title="${esc(L('Gemiddelde hardening-score van je servers'))}"><span data-flash>${score ?? '–'}</span></div>
    <div class="kpi-txt"><div class="kpi-state">${{ ok: '🟢', warn: '🟠', bad: '🔴' }[st]} ${label}</div>
      <div class="kpi-sub">${open.length ? [o2 ? `${o2} critical` : '', open.length - o2 ? `${open.length - o2} warning` : '', chains ? L('{n} aanvalsketen(s)', { n: chains }) : ''].filter(Boolean).join(' · ') : L('niets open')}</div>
      <div class="kpi-sub">🛡️ ${score === null ? L('nog geen hardening-score') : L('hardening gemiddeld {n}', { n: score })}</div></div></div>`;
}

/** Nu aandacht nodig (1.117.0): open critical-incidenten en aanvalsketens, nieuwste eerst; maximaal 5, de rest uitklappen */
function renderIdsAttention(open) {
  const box = $('#ids-attn');
  const list = open.filter((i) => +i.severity === 2 || i.type === 'chain');
  if (!list.length) {
    box.innerHTML = `<div class="attn-ok">✔ ${L('Niets dringends')} 👌${open.length ? ` <span class="muted small">· ${L('{n} warning(s) open', { n: open.length })}</span>` : ''}</div>`;
    return;
  }
  box.innerHTML = `<div class="sec-h"><h2>⚠️ ${L('Nu aandacht nodig')}</h2><span>${list.length === 1 ? L('1 incident') : L('{n} incidenten', { n: list.length })} · ${L('critical en aanvalsketens')}</span></div>
    <div class="attn ids-attn-l ${idsAttnOpen ? 'all' : ''}">${list.map((i) => `<div class="arow">
      <i class="adot s2"></i>
      <span class="a-srv">${esc(i.host_name)}</span>
      <button type="button" class="a-chk linklike" data-inc="${i.id}" title="${esc(L('Details, advies en acties'))}">${i.type === 'chain' ? '🔗 ' : ''}${esc(i.title)}</button>
      <span class="a-msg">${esc(i.detail || '')}</span>
      <span class="a-since">${dur(i.created_at)}</span>
      <span class="a-acts need-admin"><button type="button" class="btn small-btn" data-ids="handle" data-id="${i.id}">✔ ${L('Afhandelen')}</button>${i.ip ? `<button type="button" class="btn small-btn" data-ids="block" data-ip="${esc(i.ip)}" data-host="${i.host_id}" title="${esc(L('Blokkeer IP'))}">⛔</button>` : ''}</span>
    </div>`).join('')}
    ${list.length > 3 ? `<button type="button" class="attn-more ${list.length > 5 ? '' : 'mob-only'}" data-ids-attn-more>${idsAttnOpen ? '▴ ' + L('Minder tonen') : '▾ ' + L('Alle {n} tonen', { n: list.length })}</button>` : ''}</div>`;
}

/** Incidenten per dag van één server, de laatste n dagen: [[dag, aantal, critical], …] */
function idsPerDay(hostId, n) {
  const out = [];
  for (let k = n - 1; k >= 0; k--) {
    const t = new Date(idsData.today + 'T12:00:00');
    t.setDate(t.getDate() - k);
    const day = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
    const xs = idsData.incidents.filter((i) => (hostId === null || +i.host_id === +hostId) && i.created_at.slice(0, 10) === day);
    out.push([day, xs, xs.filter((i) => +i.severity === 2).length]);
  }
  return out;
}

/** Kaart van een server met open incidenten (1.117.0): aantallen, hardening, blokkades en een staafje per dag (7 dagen) */
function idsHostCard(h) {
  const n = h.open[1] + h.open[2];
  const hs = h.hard && h.hard.score !== null ? +h.hard.score : null;
  const days = idsPerDay(h.id, 7);
  const max = Math.max(1, ...days.map((x) => x[1].length));
  const bars = days.map(([day, xs, c], k) => `<rect class="${c ? 'c' : xs.length ? 'w' : 'z'}" x="${k * 14 + 1}" y="${xs.length ? 26 - Math.max(3, xs.length / max * 24) : 24}" width="11" height="${xs.length ? Math.max(3, xs.length / max * 24) : 2}" rx="2"><title>${esc(dayLabel(day, idsData.today))}: ${xs.length}</title></rect>`).join('');
  return `<article class="ids-card ${h.open[2] ? 's2' : 's1'}">
    <div class="ic-head"><span class="ok-ic">🖥️</span><span class="ok-nm"><strong>${esc(h.name)}</strong><small>${h.last_report ? L('IDS-rapport {ago}', { ago: ago(h.last_report) }) : L('nog geen IDS-rapport (agent 1.35.0+)')}</small></span>
      <button type="button" class="hard-chip ${hs === null ? '' : hs >= 85 ? 'good' : hs >= 60 ? 'warn' : 'bad'}" data-hostsec="${h.id}" title="${esc(L('Hardening, isoleren en gebruikers'))}">🛡️ ${hs === null ? '–' : hs}</button></div>
    <div class="ic-nums">
      <button type="button" class="ic-n" data-ihost="${h.id}" title="${esc(L('Toon de open incidenten van deze server'))}"><b class="${h.open[2] ? 'danger-txt' : 'warn-txt'}">${n}</b><small>${L('open')}</small></button>
      <span class="ic-n"><b>${h.open[2]}</b><small>critical</small></span>
      <span class="ic-n"><b>${h.blocks || 0}</b><small>${L('geblokkeerd')}</small></span>
      <svg class="ic-spark" viewBox="0 0 98 26" role="img" aria-label="${esc(L('Incidenten per dag, 7 dagen'))}">${bars}</svg>
    </div>
    ${h.iso ? `<div class="small"><span class="iso-chip">🧱 ${h.iso.isolate_until ? L('geïsoleerd tot {t}', { t: esc(fmtUntil(h.iso.isolate_until)) }) : esc(h.iso.isolate_state)}</span></div>` : ''}
  </article>`;
}

/** Aanvallen per dag (1.117.0): 14 dagen, gestapeld per soort; uitklapbaar en standaard dicht */
const IDS_CHART = [['login', () => L('Logins'), ['logins', 'decoy']], ['web', () => L('Web'), ['web', 'weblog']], ['net', () => L('Netwerk'), ['ports', 'net']],
  ['host', () => L('Processen en bestanden'), ['procs', 'files']], ['other', () => L('Overig'), null]];
function renderIdsChart() {
  const box = $('#ids-chart');
  let isOpen = false;
  try { isOpen = localStorage.getItem('rzmon-ids-chart') === '1'; } catch (e) { /* standaard dicht */ }
  const days = idsPerDay(null, 14);
  const grp = (i) => { const c = idsCatOf(i); return (IDS_CHART.find((g) => g[2] && g[2].includes(c)) || IDS_CHART[4])[0]; };
  const rows = days.map(([day, xs]) => [day, IDS_CHART.map(([k]) => xs.filter((i) => grp(i) === k).length)]);
  const total = rows.reduce((a, [, v]) => a + v.reduce((x, y) => x + y, 0), 0);
  const sums = IDS_CHART.map((g, k) => rows.reduce((a, [, v]) => a + v[k], 0));
  const max = Math.max(1, ...rows.map(([, v]) => v.reduce((x, y) => x + y, 0)));
  const W = 700, H = 120, bw = W / 14;
  const bars = rows.map(([day, v], k) => {
    let y = H;
    const segs = v.map((n, s) => {
      if (!n) return '';
      const h = n / max * (H - 8);
      y -= h;
      return `<rect class="ser-${s + 1}" x="${k * bw + 6}" y="${y.toFixed(1)}" width="${bw - 12}" height="${Math.max(1, h - 2).toFixed(1)}" rx="3"/>`;
    }).join('');
    const tip = `${dayLabel(day, idsData.today)}: ${v.reduce((x, y2) => x + y2, 0)}\n` + IDS_CHART.map(([, l], s) => v[s] ? `${l()}: ${v[s]}` : '').filter(Boolean).join('\n');
    return `<g class="ch-col"><rect class="ch-hit" x="${k * bw}" y="0" width="${bw}" height="${H}"><title>${esc(tip)}</title></rect>${segs}</g>`;
  }).join('');
  const lbl = rows.map(([day], k) => (k % 2 === 1 || k === 13 ? `<span style="left:${((k + .5) / 14 * 100).toFixed(2)}%">${esc(new Date(day + 'T12:00:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short' }))}</span>` : '')).join('');
  box.innerHTML = `<details class="card ids-chart" ${isOpen ? 'open' : ''}><summary><b>📊 ${L('Aanvallen per dag')}</b> <span class="muted small">${L('14 dagen · {n} incidenten', { n: total })}</span></summary>
    <div class="ch-legend small">${IDS_CHART.map(([, l], k) => sums[k] ? `<span><i class="ser-${k + 1}"></i>${esc(l())} <b>${sums[k]}</b></span>` : '').join('')}</div>
    <svg class="ch-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="${esc(L('Aanvallen per dag'))}"><line x1="0" y1="${H - .5}" x2="${W}" y2="${H - .5}" class="ch-base"/>${bars}</svg>
    <div class="ch-x small muted">${lbl}</div></details>`;
  $('details', box).ontoggle = (e) => { try { localStorage.setItem('rzmon-ids-chart', e.target.open ? '1' : '0'); } catch (ex) { /* alleen voor nu */ } };
}

function renderIds() {
  const d = idsData;
  if (!d) return;
  const open = d.incidents.filter((i) => i.status === 'open');
  const o2 = open.filter((i) => +i.severity === 2).length;
  const [accOk, accFail, failHours] = accLast24();
  // Tellers
  const cnt = (cls, n, word, attrs, zeroOk = false) => `<button type="button" class="hc ${cls} ${n || zeroOk ? '' : 'zero'}" ${attrs}><span class="hc-dot"></span><b data-flash>${n}</b> <span class="hc-w">${word}</span></button>`;
  $('#ids-counts').innerHTML = cnt('s2', o2, 'Critical', 'data-igo="inc"') + cnt('s1', open.length - o2, 'Warning', 'data-igo="inc"')
    + cnt('sx2', d.blocks.length, '⛔ ' + L('Geblokkeerd'), 'data-igo="blk"') + cnt('si', accOk, '🔑 ' + L('Logins 24 u'), 'data-igo="acc"')
    + cnt('sx', accFail, '❌ ' + L('Mislukt 24 u'), 'data-igo="acc" data-fail="1"');
  const lastRep = d.hosts.map((h) => h.last_report).filter(Boolean).sort().pop();
  $('#ids-last').textContent = lastRep ? L('IDS-rapport {ago}', { ago: ago(lastRep) }) : '';
  // Kerncijfers
  const real = d.hosts.filter((h) => !h.ha);
  const watched = real.filter((h) => h.last_report);
  const missing = real.find((h) => !h.last_report);
  const oldest = open[open.length - 1];
  const maxF = Math.max(1, ...failHours);
  let fp = '';
  failHours.forEach((v, i) => { fp += `${fp ? 'L' : 'M'}${(i / 23 * 100).toFixed(1)},${(24 - v / maxF * 20).toFixed(1)}`; });
  const modern = d.modern !== false;
  $('#tab-ids').classList.toggle('ids-modern', modern);
  if (modern) $('#ids-last').innerHTML = idsModePill(d) + (lastRep ? ` <span>${esc(L('IDS-rapport {ago}', { ago: ago(lastRep) }))}</span>` : '');
  $('#ids-kpis').innerHTML = `
    ${modern ? idsScoreTile(d, open, o2) : `<div class="kpi kpi-main ${!d.enabled ? 'warn' : ''}"><div class="kpi-txt"><div class="kpi-lbl">${L('Modus')}</div>
      ${d.enabled ? `<div class="ids-mode"><span class="${d.mode !== 'block' ? 'on' : ''}">🎓 ${L('Leren')}</span><span class="${d.mode === 'block' ? 'on bad' : ''}">⛔ ${L('Blokkeren')}</span></div>
        <div class="kpi-sub">${d.mode === 'block' ? L('brute force wordt gemeld én geblokkeerd') : L('alles wordt gemeld, er wordt niets geblokkeerd')} · <a href="#settings/ids" data-goto="settings/ids">${L('instellingen')}</a></div>`
        : `<div class="kpi-state">${L('IDS staat uit')}</div><div class="kpi-sub"><a href="#settings/ids" data-goto="settings/ids">${L('aanzetten')}</a></div>`}</div></div>`}
    <div class="kpi ${missing && d.enabled ? 'warn' : ''}"><div class="kpi-lbl">${L('Servers bewaakt')}</div><div class="kpi-val">${watched.length}<small>/${real.length}</small></div>
      <div class="kpi-sub">${missing && d.enabled ? L('{name}: nog geen IDS-rapport', { name: esc(missing.name) }) : real.length ? L('allemaal') : L('nog geen servers met agent')}</div></div>
    <div class="kpi ${o2 ? 'bad' : open.length ? 'warn' : ''}"><div class="kpi-lbl">${L('Open incidenten')}</div><div class="kpi-val">${open.length}</div>
      <div class="kpi-sub">${oldest ? `${L('langst')}: ${esc(oldest.title)} (${dur(oldest.created_at)})` : L('niets open')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Nu geblokkeerd')}</div><div class="kpi-val">${d.blocks.length}</div>
      <div class="kpi-sub">${d.blocks.length ? esc(d.blocks.slice(0, 3).map((b) => b.ip).join(' · ')) : d.mode === 'block' ? L('niets') : L('modus leren')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Mislukte logins 24 u')}</div><div class="kpi-val">${accFail}</div>
      <svg class="kpi-spark" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${fp}L100,26L0,26Z" fill="var(--warn)" opacity=".14"/><path d="${fp}" fill="none" stroke="var(--warn)" stroke-width="2" vector-effect="non-scaling-stroke"/></svg></div>`;
  $('#ids-attn').hidden = !modern;
  $('#ids-chart').hidden = !modern;
  if (modern) { renderIdsAttention(open); renderIdsChart(); }
  // Servers: compacte regels (modern: met open incidenten als kaart, de rest compact eronder)
  const hostRow = (h) => {
    if (h.ha) return `<div class="srv-row"><span class="ok-ic">🏠</span><span class="ok-nm"><strong>${esc(h.name)}</strong><small>${L('niet beschikbaar in de Home Assistant-add-on: die draait in een container en ziet het systeem zelf niet')}</small></span><span class="srv-st"></span><span class="srv-n muted small">–</span></div>`;
    const st = [!h.baseline_until ? '' : h.learning ? L('📏 basismeting tot {t}', { t: esc(fmtUntil(h.baseline_until)) }) : '✔ ' + L('bewaakt'),
      h.fw_state ? (IDS_FW[h.fw_state] || (() => esc(h.fw_state)))(h) : '',
      h.decoy_state ? `<span class="warn-txt">🍯 ${esc(h.decoy_state)}</span>` : '',
      h.fim_count != null ? '📁 ' + L(+h.fim_count === 1 ? '{n} bestand bewaakt' : '{n} bestanden bewaakt', { n: (+h.fim_count).toLocaleString(LOCALE) }) : '',
      +h.realtime ? ((x) => (x && x.state === 'active' ? '<span class="good">⚡ realtime</span>' : `<span class="warn-txt">⚡ ${L('niet actief')}</span>`))(JSON.parse(h.rt || 'null')) : '',
      +h.realtime ? ((t) => (t && !t.inotify ? `<span class="warn-txt">${t.family === 'rhel' && !t.epel ? L('EPEL ontbreekt') : L('inotify-tools ontbreekt')}</span>` : ''))(JSON.parse(h.tools || 'null')) : ''].filter(Boolean).join(' · ');
    const n = h.open[1] + h.open[2];
    // Hardening-score en isolatie (1.88.0): klik voor details, isoleren en gebruikers
    const hs = h.hard && h.hard.score !== null ? h.hard.score : null;
    const sec = `<button type="button" class="hard-chip ${hs === null ? '' : hs >= 85 ? 'good' : hs >= 60 ? 'warn' : 'bad'}" data-hostsec="${h.id}" title="${esc(L('Hardening, isoleren en gebruikers'))}">🛡️ ${hs === null ? '–' : hs}${h.hard && h.hard.bad ? ` <small>· ${L('{n} punt(en)', { n: h.hard.bad })}</small>` : ''}</button>`
      + (h.iso ? ` <span class="iso-chip" title="${esc(h.iso.isolate_state || '')}">🧱 ${h.iso.isolate_until ? L('geïsoleerd tot {t}', { t: esc(fmtUntil(h.iso.isolate_until)) }) : esc(h.iso.isolate_state)}</span>` : '');
    return `<div class="srv-row"><span class="ok-ic">🖥️</span>
      <span class="ok-nm"><strong>${esc(h.name)}</strong><small>${h.last_report ? L('IDS-rapport {ago}', { ago: ago(h.last_report) }) : d.enabled ? L('nog geen IDS-rapport (agent 1.35.0+)') : ''}</small></span>
      <span class="srv-st small">${sec} ${st}${h.baseline_until ? ` <button type="button" class="linkbtn need-admin" data-rebase="${h.id}" title="${L('Alles wat er nu is als normaal vastleggen')}">↺ ${L('basismeting opnieuw')}</button>` : ''}</span>
      <span class="srv-n">${n ? `<b class="${h.open[2] ? 'danger-txt' : 'warn-txt'}">● ${L('{n} open', { n })}</b>` : `<b class="good-txt">● ${L('geen incidenten')}</b>`}</span></div>`;
  };
  const busy = (h) => !h.ha && (h.open[1] + h.open[2] > 0 || !!h.iso);
  const cards = modern ? d.hosts.filter(busy) : [];
  const rows = d.hosts.filter((h) => !cards.includes(h));
  $('#ids-hosts').className = modern ? 'ids-hosts-m' : 'srv-list';
  $('#ids-hosts').innerHTML = !d.hosts.length ? '<p class="muted">' + L('Nog geen servers met agent.') + '</p>'
    : !modern ? rows.map(hostRow).join('')
    : (cards.length ? `<div class="sec-h hosts-h"><h2>🖥️ ${L('Servers met open incidenten')}</h2><span>${cards.length}</span></div><div class="ids-cards">${cards.map(idsHostCard).join('')}</div>` : '')
      + (rows.length ? `<div class="sec-h hosts-h"><h2>✔ ${L('Rustig')}</h2><span>${rows.length === 1 ? L('1 server') : L('{n} servers', { n: rows.length })}</span></div><div class="srv-list">${rows.map(hostRow).join('')}</div>` : '');

  // Incidenten per dag; dezelfde soort op dezelfde server achter elkaar gebundeld
  const q = $('#ids-search').value.trim().toLowerCase();
  const scope = d.incidents.filter((i) => idsFilter === 'all' || i.status === 'open');
  $('#ids-filter').innerHTML = [['open', 'Open', open.length], ['all', L('Alles'), d.incidents.length]].map(([k, l, n]) => `<button type="button" class="dchip ${idsFilter === k && !idsCat ? 'on' : ''}" data-if="${k}">${l} <b>${n}</b></button>`).join('')
    + IDS_CATS.map(([k, l]) => [k, l(), scope.filter((i) => idsCatOf(i) === k).length]).filter(([, , n]) => n)
      .map(([k, l, n]) => `<button type="button" class="dchip ${idsCat === k ? 'on' : ''}" data-icat="${k}">${l} <b>${n}</b></button>`).join('');
  if (idsCard) {
    const cardTitle = (((protData && protData.cards) || []).find((c) => c.id === idsCard) || {}).title || idsCard;
    $('#ids-filter').innerHTML += ` <button type="button" class="dchip on" data-icard-off="1" title="${esc(L('Filter weghalen'))}">🛡️ ${esc(cardTitle)} ✕</button>`;
  }
  const list = scope.filter((i) => (!idsCard || ((d.prot || {})[i.id] || {}).card === idsCard) && (!idsCat || idsCatOf(i) === idsCat) && (!q || `${i.title} ${i.detail} ${i.host_name} ${i.ip || ''}`.toLowerCase().includes(q)) && fxPass('ids', i));
  fxRender('ids');
  const narrowed = !!q || !!idsCat || !!idsCard || fxActive('ids');
  $('#ids-info').textContent = L('{open} open · {n} in de laatste {days} dagen', { open: open.length, n: d.incidents.length, days: d.days });
  $('#it-n-inc').textContent = open.length ? open.length : '';
  const el = $('#ids-incidents');
  if (q) moreDaysWhenSearching('ids', d);
  const more = idsFilter === 'all' || q ? moreDays('ids', d) : '';   // open incidenten zijn er altijd allemaal
  if (!list.length) {
    el.innerHTML = `<p class="muted">${idsFilter === 'open' && !q && !idsCat ? L('Geen open incidenten.') + ' 👌' : L('Niets gevonden.')}</p>` + more;
  } else {
    const byDay = new Map();
    list.forEach((i) => { const day = i.created_at.slice(0, 10); if (!byDay.has(day)) byDay.set(day, []); byDay.get(day).push(i); });
    const fresh = todayFresh('ids', idsDayOpen, d.today, (byDay.get(d.today) || []).length);
    const isOpen = (day) => idsDayOpen[day] ?? (day === d.today || narrowed || (smartOpen() && idsFilter === 'open'));
    el.innerHTML = (!narrowed && !byDay.has(d.today) ? todayEmpty(d.today, (idsFilter === 'open' ? L('geen nieuwe open incidenten') : L('geen incidenten')) + ' 👌') : '') + [...byDay].map(([day, items]) => {
      const n2 = items.filter((i) => +i.severity === 2).length, n1 = items.length - n2;
      return `<details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''} ${fresh && day === d.today ? 'data-fresh="1"' : ''}>
        <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
          <span class="ad-counts">${n2 ? `<span class="ad-count">🔴 ${n2}</span>` : ''}${n1 ? `<span class="ad-count">🟠 ${n1}</span>` : ''}</span>
          <span class="muted small ad-total">${items.length === 1 ? L('1 incident') : L('{n} incidenten', { n: items.length })}</span></summary>
        <div class="ad-body">${bundleRuns(items, (i) => `${i.type}|${i.host_id}|${i.status}`, true).map((g) => {
          if (g.items.length === 1) return idsRow(g.items[0]);
          // In het venster loop je met de nummers door precies de incidenten van deze bundel
          const key = 'i' + g.items[g.items.length - 1].id;
          return `<div class="bundle${idsBundles.has(key) ? ' open' : ''}" data-bundle="${key}">${idsRow(g.items[0], g.items, g.items)}${g.items.slice(1).map((i) => idsRow(i, null, g.items)).join('')}</div>`;
        }).join('')}</div></details>`;
    }).join('') + more;
    $$('.alert-day', el).forEach((det) => (det.ontoggle = () => { idsDayOpen[det.dataset.day] = det.open; idsToggleLabel(); }));
  }
  idsToggleLabel();

  // Blokkades
  $('#ids-block-info').textContent = L('{n} actief', { n: d.blocks.length });
  $('#it-n-blk').textContent = d.blocks.length ? d.blocks.length : '';
  const row = (b, active) => `<tr>
      <td class="mono">${esc(b.ip)}${ccTag(b.cc)}</td><td>${esc(b.host_name)}</td><td>${esc(b.reason)}</td>
      <td class="at-time" title="${esc(b.created_at)}">${esc(fmtUntil(b.created_at))}</td>
      <td class="at-time">${active ? (b.until ? esc(fmtUntil(b.until)) : '<b>' + L('permanent') + '</b>') : b.removed_at ? (b.removed_by ? L('gedeblokkeerd {t} door {who}', { t: esc(fmtUntil(b.removed_at)), who: esc(b.removed_by) }) : L('gedeblokkeerd {t}', { t: esc(fmtUntil(b.removed_at)) })) : L('verlopen')}</td>
      <td>${+b.auto ? L('automatisch') : esc(b.created_by)}</td>
      <td title="${L('Tegengehouden pakketten sinds de laatste wijziging van de lijst')}">${+b.hits || 0}</td>
      <td>${active ? `<button type="button" class="btn need-admin small-btn" data-unblock="${b.id}">🔓 ${L('Deblokkeren')}</button>` : ''}</td></tr>`;
  const head = `<thead><tr><th>IP</th><th>Server</th><th>${L('Reden')}</th><th>${L('Sinds')}</th><th>${L('Tot')}</th><th>${L('Door')}</th><th>${L('Pakketten')}</th><th></th></tr></thead>`;
  const blocks = d.blocks.filter((b) => fxPass('blk', { ...b, act: '1' })), removed = d.removed.filter((b) => fxPass('blk', { ...b, act: '0' }));
  const fxB = fxActive('blk');
  $('#ids-blocks').hidden = isAdmin();
  $('#ids-block-info').closest('.dash-bar').hidden = isAdmin();   // beheerders: de kaart hieronder heeft een eigen zoekveld
  $('#ids-blocks').innerHTML = (blocks.length ? `<div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${blocks.map((b) => row(b, true)).join('')}</tbody></table></div>`
      : `<p class="muted">${fxB && d.blocks.length ? L('Niets gevonden.') : L('Niets geblokkeerd.')}${d.mode !== 'block' && !fxB ? ' ' + L('(Modus <i>leren</i>: er wordt niets geblokkeerd.)') : ''}</p>`)
    + (removed.length ? `<details class="small api-docs" ${fxB ? 'open' : ''}><summary>${L('Eerder geblokkeerd ({n})', { n: removed.length })}</summary>
        <div class="table-scroll"><table class="alert-table large ids-blocks">${head}<tbody>${removed.map((b) => row(b, false)).join('')}</tbody></table></div></details>` : '');
  fxRender('blk');
}

// ---------------------------------------------------------------- Beveiliging per server (1.88.0)
/** Hardening-score, isoleren en gebruikers vergrendelen voor één server */
async function hostSecDialog(hostId) {
  const h = (idsData?.hosts || []).find((x) => +x.id === hostId) || { name: '?' };
  let d;
  try { d = await api('harden_detail', null, { host_id: hostId }); } catch (ex) { toast(ex.message, 'err'); return; }
  const ic = (i) => (i.ignored ? '<span class="muted">–</span>' : i.ok === true ? '<b class="good-txt">✔</b>' : i.ok === false ? '<b class="danger-txt">✘</b>' : '<span class="muted">?</span>');
  const iso = d.iso && d.iso.isolate_until && new Date(d.iso.isolate_until.replace(' ', 'T')) > new Date();
  const locks = d.userlocks.filter((u) => u.action === 'lock');
  modal({
    title: `🛡️ ${esc(h.name)}`,
    wide: true,
    body: `<h3 class="set-sub" style="margin-top:0">${L('Hardening')} ${d.score !== null ? `<span class="hard-chip ${d.score >= 85 ? 'good' : d.score >= 60 ? 'warn' : 'bad'}">${d.score}/100</span>` : ''}</h3>
      ${d.items.length ? `<p class="muted small" style="margin-top:-.3rem">${L('Klik op een punt voor uitleg, hoe je het controleert en hoe je het dichtzet.')}</p>
        <div class="hard-list">${d.items.map((i, n) => { const hp = i.help || {}; return `<details class="hard-item ${i.ignored ? 'muted' : ''}" ${i.ok === false && !i.ignored ? 'open' : ''}>
        <summary>${ic(i)} <b>${esc(i.title)}</b>${i.value ? ` <code>${esc(i.value)}</code>` : ''}
          <label class="small need-admin hard-ign" onclick="event.stopPropagation()"><input type="checkbox" data-hard-ign="${esc(i.item)}" ${i.ignored ? 'checked' : ''}> ${L('negeren')}</label></summary>
        <div class="hard-help">${hp.what ? `<p><b>${L('Wat is het?')}</b> ${esc(i.ok === true ? hp.why : hp.what + ' ' + hp.why)}</p>` : ''}
          ${hp.check ? `<div class="adv-cmd"><span class="small muted">${L('Controleren')}</span>${copyBox(`hard-chk-${n}`, hp.check)}</div>` : ''}
          ${i.ok !== true ? `<p><b>${L('Oplossen')}</b> ${esc(hp.fix || i.advice)}</p>${cmdList(hp.cmds || [], `hard-fix-${n}`)}` : `<p class="good small">✔ ${L('In orde; niets te doen.')}</p>`}
          ${hp.caution && i.ok !== true ? `<p class="callout small">⚠️ ${esc(hp.caution)}</p>` : ''}</div></details>`; }).join('')}</div>`
        : `<p class="muted small">${L('Nog geen gegevens: agent 1.57.0 nodig; hij stuurt ze elk uur.')}</p>`}
      <div class="need-admin">
      <h3 class="set-sub">🧱 ${L('Isoleren')}</h3>
      <p class="muted small" style="margin-top:-.2rem">${L('Bij een hack: alleen RzMon, je lokale netwerken en jouw IP-adres ({ip}) kunnen er nog bij; al het andere verkeer (in, uit en containers) stopt. Vervalt vanzelf na de gekozen tijd, ook als RzMon onbereikbaar is.', { ip: `<code>${esc(d.my_ip)}</code>` })}</p>
      ${iso ? `<p><b class="danger-txt">🧱 ${L('Geïsoleerd tot {t}', { t: esc(fmtDateTime(d.iso.isolate_until)) })}</b> · ${L('door {by}', { by: esc(d.iso.isolate_by || '?') })} · <span class="muted">${esc(d.iso.isolate_state || '')}</span>
          <button type="button" class="btn" data-unisolate>🔓 ${L('Opheffen')}</button></p>`
        : `<div class="btn-row">${[1, 4, 24, 72].map((n) => `<button type="button" class="btn danger" data-isolate="${n}">🧱 ${n < 24 ? L('{n} uur', { n }) : n === 24 ? L('1 dag') : L('3 dagen')}</button>`).join('')}</div>
          ${d.iso && d.iso.isolate_state && d.iso.isolate_state !== 'off' ? `<p class="muted small">${L('Laatste stand')}: ${esc(d.iso.isolate_state)}</p>` : ''}`}
      <h3 class="set-sub">🔒 ${L('Gebruiker vergrendelen')}</h3>
      <p class="muted small" style="margin-top:-.2rem">${L('Het account op deze server op slot (geen wachtwoord, geen SSH-sleutel) en al zijn processen gestopt. Bv. als een gebruiker is overgenomen. Root kan niet.')}</p>
      <div class="inline-row"><input id="ul-name" placeholder="${esc(L('gebruikersnaam'))}" class="mono" autocomplete="off"> <button type="button" class="btn danger" data-ulock>🔒 ${L('Vergrendelen')}</button></div>
      ${locks.length ? `<ul class="small">${locks.map((u) => `<li><code>${esc(u.username)}</code> · ${L('door {by}', { by: esc(u.created_by || '?') })} · ${u.done_at ? esc(u.result || '') : L('wacht op de agent')}
        <button type="button" class="linkbtn" data-uunlock="${esc(u.username)}">${L('vrijgeven')}</button></li>`).join('')}</ul>` : ''}
      </div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  const body = $('#modal-form');
  wireCopy(body);
  body.onchange = async (e) => {
    const c = e.target.closest('[data-hard-ign]');
    if (!c) return;
    try { await api('harden_ignore', { host_id: hostId, item: c.dataset.hardIgn, ignored: c.checked }); $('#modal').close(); hostSecDialog(hostId); loadIds(); } catch (ex) { toast(ex.message, 'err'); }
  };
  body.onclick = async (e) => {
    const isoB = e.target.closest('[data-isolate]'), un = e.target.closest('[data-unisolate]'), ul = e.target.closest('[data-ulock]'), uu = e.target.closest('[data-uunlock]');
    if (!isoB && !un && !ul && !uu) return;
    e.preventDefault();
    try {
      if (isoB) {
        const pw = await askConfirm({ title: L('{name} isoleren?', { name: h.name }), danger: true, word: 'ISOLEREN', ok: '🧱 ' + L('Isoleren'),
          text: L('Alleen RzMon, je lokale netwerken en jouw IP-adres kunnen er nog bij; al het andere verkeer stopt. Vervalt vanzelf na de gekozen tijd.') });
        if (pw === null) return;
        await api('host_isolate', { host_id: hostId, hours: +isoB.dataset.isolate, confirm: pw });
        toast(L('{name} wordt geïsoleerd; de agent past het binnen een minuut toe', { name: h.name }));
      } else if (un) {
        await api('host_unisolate', { host_id: hostId }); toast(L('Isolatie wordt opgeheven'));
      } else if (ul) {
        const name = $('#ul-name').value.trim();
        if (!name) return;
        const pw = await askConfirm({ title: L('{user} op {name} vergrendelen?', { user: name, name: h.name }), danger: true, word: 'VERGRENDELEN', ok: '🔒 ' + L('Vergrendelen'),
          text: L('Het account gaat op slot (geen wachtwoord, geen SSH-sleutel) en al zijn processen worden gestopt.') });
        if (pw === null) return;
        await api('host_userlock', { host_id: hostId, user: name, lock: true, confirm: pw }); toast(L('{user} wordt vergrendeld', { user: name }));
      } else {
        await api('host_userlock', { host_id: hostId, user: uu.dataset.uunlock, lock: false }); toast(L('{user} wordt vrijgegeven', { user: uu.dataset.uunlock }));
      }
      $('#modal').close(); hostSecDialog(hostId); loadIds();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- Tijdlijn per IP (1.88.0)
const TL_SRC = { ids: '🛡️', block: '⛔', unblock: '🔓', login: '🔑', fail: '❌', alert: '🔔' };
async function ipTimeline(ip) {
  let d;
  try { d = await api('ip_timeline', null, { ip }); } catch (ex) { toast(ex.message, 'err'); return; }
  const day = (s) => s.slice(0, 10);
  let last = '';
  modal({
    title: `🔎 ${ip}`,
    wide: true,
    body: `<p style="margin-top:0" class="small">${ccTag(d.cc) ? ccTag(d.cc) + ' · ' : ''}${[d.internal ? '🏠 ' + L('je eigen netwerk') : '',
        d.bad ? '☠️ ' + L('bekende aanvaller ({list})', { list: esc(d.bad) }) : '',
        d.whitelist ? '✅ ' + L('op de whitelist') + (d.whitelist.until ? ` (${L('tot {t}', { t: esc(fmtDateTime(d.whitelist.until)) })})` : '') : '',
        d.blocked_rz ? '⛔ ' + L('geblokkeerd in RzMon') : '', d.blocked_ids.length ? '⛔ ' + L('geblokkeerd op {list}', { list: esc(d.blocked_ids.join(', ')) }) : '',
        d.first ? L('eerst gezien {t}', { t: esc(fmtDateTime(d.first)) }) : '', d.hosts.length ? L('servers: {list}', { list: esc(d.hosts.join(', ')) }) : ''].filter(Boolean).join(' · ') || L('Nog niets bekend van dit IP-adres.')}</p>
      ${d.events.length ? `<div class="tl">${d.events.map((x) => {
        const head = day(x.at) !== last ? `<div class="tl-day">${esc(new Date(x.at.replace(' ', 'T')).toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</div>` : '';
        last = day(x.at);
        return `${head}<div class="tl-row ${x.sev === 2 ? 'crit' : ''}"><span class="tl-t">${esc(x.at.slice(11, 16))}</span><span>${TL_SRC[x.src] || '•'}</span><span class="tl-x">${esc(x.text)}${x.host ? ` <span class="muted small">· ${esc(x.host)}</span>` : ''}</span></div>`;
      }).join('')}</div>` : ''}
      <div class="btn-row need-admin">${!d.whitelist && !d.internal ? `<button type="button" class="btn" data-tl-wl>✅ ${L('Op de whitelist')}</button>` : ''}
        <a href="#ids/blk" class="btn" data-tl-go>⛔ ${L('Blokkades en whitelist')}</a></div>`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  $('#modal-form').onclick = (e) => {
    if (e.target.closest('[data-tl-wl]')) { e.preventDefault(); $('#modal').close(); wlDialog({ type: 'ip', value: ip, wl: false }); }
    if (e.target.closest('[data-tl-go]')) { e.preventDefault(); $('#modal').close(); showTab('ids/blk'); }
  };
}
// Klik op een IP-adres (in een <code>) op de pagina's Meldingen, IDS en bij de blokkades: de tijdlijn van dat IP
const IP_RE = /^(\d{1,3}(\.\d{1,3}){3}|[0-9a-f]*:[0-9a-f:]+)$/i;
document.addEventListener('click', (e) => {
  const c = e.target.closest('code');
  if (!c || c.closest('#modal') || !c.closest('#tab-alerts, #tab-ids, #blocks-card')) return;
  const ip = c.textContent.trim();
  if (IP_RE.test(ip)) { e.preventDefault(); ipTimeline(ip); }
});
document.addEventListener('mouseover', (e) => {
  const c = e.target.closest && e.target.closest('code');
  if (c && !c.dataset.ipc && IP_RE.test(c.textContent.trim())) { c.dataset.ipc = '1'; c.classList.add('ip-link'); c.title = L('Tijdlijn van dit IP-adres'); }
});

// ---------------------------------------------------------------- Incidentvenster met advies (1.101.0)
/** Commando's om te kopiëren, met een uitleg erboven */
function cmdList(list, prefix) {
  return list.map(([label, cmd], n) => `<div class="adv-cmd"><span class="small muted">${esc(label)}</span>${copyBox(`${prefix}-${n}`, cmd)}</div>`).join('');
}
/** Gegevens over een IP-adres (eigenaar, DNS, land, eerder gezien) in het incidentvenster; apart opgehaald met time-outs */
/** Dreigingsinformatie bij een IP (1.112.0): oordeel, AbuseIPDB, GreyNoise, Shodan, met links */
const TI_VERDICT = { malicious: () => '☠️ ' + L('bekende aanvaller'), suspicious: () => '⚠️ ' + L('verdacht'), benign: () => '✔ ' + L('onschuldig'), scanner: () => '🤖 ' + L('scanner'),
  tor: () => '🧅 Tor', clean: () => L('niet bekend als aanvaller'), unknown: () => L('onbekend') };
function tiRows(t, ip) {
  const e = encodeURIComponent(ip);
  const rows = [[L('Oordeel'), `<b class="${t.verdict === 'malicious' ? 'bad' : t.verdict === 'suspicious' ? 'warn-txt' : t.verdict === 'benign' ? 'good' : ''}">${(TI_VERDICT[t.verdict] || (() => t.verdict))()}</b>
    <span class="score-tag ${t.score >= 80 ? 'crit' : t.score >= 40 ? 'warn' : ''}">${+t.score}</span>${(t.why || []).length ? `<br><span class="small">${esc(t.why.join(' · '))}</span>` : ''}`]];
  if (t.abuse) rows.push(['AbuseIPDB', `<a href="https://www.abuseipdb.com/check/${e}" target="_blank" rel="noopener">${L('{s}% · {n} meldingen', { s: t.abuse.score, n: t.abuse.reports })}</a>
    <span class="muted small">${esc([t.abuse.usage, t.abuse.isp, t.abuse.domain].filter(Boolean).join(' · '))}${t.abuse.last ? ' · ' + L('laatst {t}', { t: esc(fmtDateTime(t.abuse.last)) }) : ''}</span>`]);
  if (t.gn) rows.push(['GreyNoise', `<a href="https://viz.greynoise.io/ip/${e}" target="_blank" rel="noopener">${esc(t.gn.riot ? L('bekende dienst') : t.gn.class || (t.gn.noise ? L('scant het internet') : L('niet gezien')))}</a>${t.gn.name ? ` <span class="muted small">${esc(t.gn.name)}</span>` : ''}`]);
  if (t.shodan) rows.push(['Shodan', `<a href="https://www.shodan.io/host/${e}" target="_blank" rel="noopener">${t.shodan.ports.length ? L('poorten {list}', { list: esc(t.shodan.ports.slice(0, 12).join(', ')) }) : L('geen open poorten bekend')}</a>
    ${t.shodan.tags.length ? ' · ' + esc(t.shodan.tags.join(', ')) : ''}${t.shodan.nvulns ? ` · <span class="warn-txt">${L('{n} lekken', { n: t.shodan.nvulns })}</span>` : ''}`]);
  return rows;
}
async function fillIpInfo(box, ip, fresh = false) {
  box.innerHTML = `<span class="muted small">⏳ ${L('Gegevens van {ip} ophalen (hooguit een paar seconden)…', { ip: esc(ip) })}</span>`;
  let d;
  try { d = await api('ip_info', null, fresh ? { ip, fresh: 1 } : { ip }); } catch (ex) { box.innerHTML = `<span class="bad small">${esc(ex.message)}</span>`; return; }
  const r = d.rdap || {};
  const rows = [
    [L('Waar'), d.internal ? '🏠 ' + L('je eigen netwerk') + (d.rzmon_host ? ` · ${L('server {name} in RzMon', { name: `<b>${esc(d.rzmon_host)}</b>` })}` : '') : (ccTag(d.cc || r.country) || '–')],
    [L('DNS-naam'), d.rdns && d.rdns.name ? `<code>${esc(d.rdns.name)}</code> ${d.rdns.confirmed ? `<span class="good small">✔ ${L('wijst terug naar dit IP')}</span>`
      : `<span class="warn-txt small">${L('wijst niet terug naar dit IP (niet te vertrouwen)')}</span>`}` : `<span class="muted">${d.rdns ? L('geen') : L('niet op te zoeken')}</span>`],
    ...(d.internal ? [] : [
      [L('Eigenaar'), r.error ? `<span class="muted">${esc(r.error)}</span>` : esc(r.org || '–') + (d.hosting ? ` <span class="warn-txt small">· ${L('hosting/cloud: vandaar komen vooral scanners en gehackte servers')}</span>` : '')],
      [L('Netwerk'), r.error ? '' : esc([r.name, r.cidr || r.range].filter(Boolean).join(' · ') || '–') + (r.registry ? ` <span class="muted small">(${esc(r.registry)})</span>` : '')],
      [L('Misbruik melden'), r.abuse ? `<a href="mailto:${esc(r.abuse)}">${esc(r.abuse)}</a>` : '<span class="muted">–</span>'],
    ]),
    [L('Bekende aanvaller'), d.bad ? `<b class="bad">☠️ ${esc(d.bad)}</b>` : `<span class="muted">${L('niet op de lijsten')}</span>`],
    ...(d.ti ? tiRows(d.ti, ip) : (d.ti_on && !d.internal ? [[L('Dreigingsinformatie'), `<span class="muted">${L('niets bekend (of de limiet van vandaag is bereikt)')}</span>`]] : [])),
    [L('In RzMon'), [d.whitelist ? '✅ ' + L('op de whitelist') : '', d.blocked_rz ? '⛔ ' + L('geblokkeerd in RzMon') : '', d.blocked_ids.length ? '⛔ ' + L('geblokkeerd op {list}', { list: esc(d.blocked_ids.join(', ')) }) : '',
      d.events ? L('{n} gebeurtenissen sinds {t}', { n: d.events, t: esc(fmtDateTime(d.first)) }) : L('nog niet eerder gezien'), d.hosts.length ? L('servers: {list}', { list: esc(d.hosts.join(', ')) }) : ''].filter(Boolean).join(' · ')],
  ].filter(([, v]) => v !== '');
  box.innerHTML = `<table class="kv-table small">${rows.map(([k, v]) => `<tr><th>${k}</th><td>${v}</td></tr>`).join('')}</table>
    <p class="muted small">${d.cached_at ? L('Opgezocht {t}', { t: esc(fmtDateTime(d.cached_at)) }) + ' · ' : ''}<button type="button" class="linkbtn need-admin" data-ip-fresh>${L('opnieuw opzoeken')}</button></p>`;
  const fb = $('[data-ip-fresh]', box);
  if (fb) fb.onclick = () => fillIpInfo(box, ip, true);
}

/**
 * 🔎 Inspectie (1.110.0): het bewijs bij een incident. Webverzoeken met de techniek en het verdachte stuk gemarkeerd;
 * processen, verbindingen, bestanden en containers met alle gegevens, en de regel die raak was (met MITRE en bron).
 */
function inspectHtml(list) {
  if (!list.length) return '';
  const mark = (txt, parts) => {
    let h = esc(txt);
    for (const p of parts) { const e = esc(p); if (e && e.length >= 2) h = h.split(e).join(`<mark>${e}</mark>`); }
    return h;
  };
  const web = list.filter((x) => x.kind === 'web');
  const other = list.filter((x) => x.kind !== 'web');
  let html = '';
  if (web.length) {
    const techs = [...new Set(web.flatMap((x) => (x.ev.tech || []).map((t) => L(t[1]))))];
    const cves = [...new Set(web.map((x) => x.ev.cve && x.ev.cve[0]).filter(Boolean))];
    html += `<div class="insp-tags">${techs.map((t) => `<span class="chip">${esc(t)}</span>`).join('')}${cves.map((c) => `<a class="chip crit" href="https://nvd.nist.gov/vuln/detail/${esc(c)}" target="_blank" rel="noopener">${esc(c)}</a>`).join('')}
      ${web.some((x) => x.ev.scanner) ? `<span class="chip">🤖 ${esc(web.find((x) => x.ev.scanner).ev.scanner)}</span>` : ''}</div>
      <div class="table-scroll"><table class="vuln-table insp-web"><thead><tr><th>${L('Tijd')}</th><th>${L('Verzoek')}</th><th>${L('Status')}</th><th>User-Agent</th></tr></thead><tbody>
      ${web.map((x) => { const v = x.ev; const hit = (v.tech || []).map((t) => t[2]);
        return `<tr><td class="small">${esc(new Date(v.at * 1000).toLocaleString(LOCALE, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' }))}</td>
          <td class="mono small insp-uri"><b>${esc(v.method)}</b> ${mark(v.uri, hit)}${v.dec ? `<br><span class="muted" title="${esc(L('Gedecodeerd'))}">↳ ${mark(v.dec, hit)}</span>` : ''}${v.cve ? `<br><span class="crit-txt">${esc(v.cve[0] ? v.cve[0] + ' · ' : '')}${esc(L(v.cve[1]))}</span>` : ''}</td>
          <td class="${v.status >= 200 && v.status < 400 ? 'warn-txt' : 'muted'}" title="${esc(v.status >= 200 && v.status < 400 ? L('Gewoon antwoord: kijk of het lukte') : L('Geweigerd of niet gevonden'))}">${v.status}</td>
          <td class="small">${mark(v.ua, hit)}</td></tr>`; }).join('')}
      </tbody></table></div>`;
  }
  for (const x of other.filter((y) => y.kind === 'chain').slice(0, 1)) {
    const r = x.info || {};
    html += `<div class="insp-ev"><div class="insp-head">🔗 <b>${esc(r.title || '')}</b> ${(r.tactics || []).map((t) => `<span class="chip tac">${esc(tacticName(t))}</span>`).join('')}
        <span class="muted small">${esc(fmtDateTime(x.at))}</span></div>
      ${chainSteps(x.ev.steps || [], 'data-inc-step')}
      ${(x.ev._done || []).length ? `<p class="small">⛔ ${L('RzMon deed')}: ${esc(x.ev._done.join(', '))}</p>` : ''}
      ${r.what ? `<p class="small muted">${esc(r.what)}</p>` : ''}
      <p class="small">${(r.tags || []).map(techLink).join(' ')}</p></div>`;
  }
  const evs = other.filter((y) => y.kind !== 'chain').slice(0, 20);
  if (evs.length > 1) {
    html += `<div class="ev-pager small"><span class="muted">${L('Gebeurtenis')}</span> ${evs.map((x, k) => `<button type="button" class="pg-n ${k === 0 ? 'on' : ''}" data-ev-nav="${k}" title="${esc(fmtDateTime(x.at))}">${k + 1}</button>`).join('')}
      <span class="muted">${L('{n} keer gezien; nieuwste eerst', { n: evs.length })}</span></div>`;
  }
  for (const [k, x] of evs.entries()) {
    const v = x.ev, r = x.info || {};
    const rows = {
      exec: [[L('Programma'), `<code>${esc(v.exe || v.comm)}</code>`], [L('Opdracht'), `<code class="insp-cmd">${esc(v.cmd || '')}</code>`], [L('Ouder'), esc(v.pc || '?')],
        [L('Gebruiker'), `${esc(v.u || '?')} (uid ${esc(v.uid)}${+v.auid && +v.auid < 4294967295 ? ', ' + L('ingelogd als uid {n}', { n: esc(v.auid) }) : ''})`], ['pid', esc(v.pid)], [L('Map'), esc(v.cwd || '')]],
      net: [[L('Programma'), `<code>${esc(v.exe || v.comm)}</code>`], [v.dir === 'listen' ? L('Luistert op') : L('Naar'), v.dir === 'listen' ? esc(v.lp) : `<code>${esc(v.rip)}:${esc(v.rp)}</code>`], [L('Gebruiker'), esc(v.u || '?')], ['pid', esc(v.pid)]],
      file: [[L('Bestand'), `<code>${esc(v.path)}</code>`], [L('Grootte'), fmtBytes(+v.size || 0)], ['ELF', +v.elf ? L('ja (programma)') : L('nee')], [L('Verdachte code'), esc(v.susp || '–')], ...(v.yara ? [['YARA', `<b class="bad">${esc(v.yara)}</b>`]] : []), ['SHA-256', `<code class="small">${esc(v.sha || '')}</code>`],
        ...(x.ti ? [[L('Bekend als'), x.ti.verdict === 'malware' ? `<b class="bad">☠️ ${esc((x.ti.bazaar && x.ti.bazaar.family) || L('malware'))}</b> <a href="https://bazaar.abuse.ch/sample/${esc(v.sha)}/" target="_blank" rel="noopener">MalwareBazaar</a>`
          : x.ti.verdict === 'known_good' ? `<span class="good">✔ ${L('bekend, goed bestand')}</span> <span class="muted small">CIRCL · ${esc((x.ti.circl && (x.ti.circl.name || x.ti.circl.source)) || '')}</span>` : `<span class="muted">${L('niet bekend bij {src}', { src: esc((x.ti.src || []).join(', ') || '–') })}</span>`]] : [])],
      audit: [[L('Pad'), `<code>${esc(v.name)}</code>`], [L('Door'), `<code>${esc(v.exe)}</code> (${esc(v.u || v.uid)})`], [L('Sleutel'), esc(v.key)]],
      sur: [[L('Melding'), `<b>${esc(v.sig || '')}</b> <span class="muted small">sid ${esc(v.sid)}</span>`], [L('Categorie'), esc(v.cat || '–')],
        [L('Verkeer'), `<code>${esc(v.src)}:${esc(v.sport)} → ${esc(v.dst)}:${esc(v.dport)}</code> ${esc(v.proto || '')}`], [L('Ernst'), `${esc(v.sev)}${v.act === 'blocked' ? ' · ' + L('Suricata blokkeerde het') : ''}`]],
      ctr: [[L('Container'), `${esc(v.name)} <span class="muted">${esc(v.img || '')}</span>`], [L('Actie'), esc(v.act) + (+v.priv ? ' · --privileged' : '')], [L('Opdracht'), `<code>${esc(v.cmd || '')}</code>`]],
    }[x.kind] || [];
    html += `<div class="insp-ev" data-evi="${k}" ${k > 0 ? 'hidden' : ''}><div class="insp-head">${EDR_KIND_IC[x.kind] || ''} <b>${esc(r.title || x.rule)}</b> <span class="badge">${r.src === 'sigma' ? 'Sigma' : r.src === 'anomaly' ? L('Afwijking') : r.src === 'suricata' ? 'Suricata' : 'RzMon'}</span>
        ${(r.tags || []).map((t) => `<a class="chip" href="https://attack.mitre.org/techniques/${esc(t.replace('.', '/'))}/" target="_blank" rel="noopener">${esc(t)}</a>`).join('')}
        <span class="muted small">${esc(fmtDateTime(x.at))}</span></div>
      <table class="kv-table small">${rows.map(([k, val]) => `<tr><th>${k}</th><td>${val}</td></tr>`).join('')}
        ${(v._done || []).length ? `<tr><th>${L('RzMon deed')}</th><td>⛔ ${esc(v._done.join(', '))}</td></tr>` : ''}</table>
      ${r.what ? `<p class="small muted">${esc(r.what)}</p>` : ''}
      ${r.src === 'sigma' ? `<p class="small muted">${L('Sigma-regel van {a}', { a: esc(r.author || '?') })} · <a href="${esc(r.url)}" target="_blank" rel="noopener">${L('bron')}</a> · DRL 1.1${(r.fp || []).length ? ' · ' + L('kan ook: {fp}', { fp: esc(r.fp.join(', ')) }) : ''}</p>` : ''}</div>`;
  }
  return `<h3 class="set-sub">🔎 ${L('Inspectie')}</h3>${html}`;
}

document.addEventListener('keydown', (e) => {
  const m = $('#modal');
  if (!m || !m.open || !m.dataset.incNav || e.altKey || e.ctrlKey || e.metaKey || /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''))) return;
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
  const nav = m.dataset.incNav.split(',').map(Number);
  const pos = nav.indexOf(+m.dataset.incCur);
  e.preventDefault();
  incidentDialog(nav[(pos + (e.key === 'ArrowRight' ? 1 : -1) + nav.length) % nav.length], nav);
});
/** Nummers onderin het venster: ‹ 1 2 [3] 4 … 12 › met per nummer ✔ (afgehandeld) of • (open) */
function incidentPager(nav, id) {
  if (nav.length < 2) return '';
  const all = (idsData && idsData.incidents) || [];
  const st = (x) => (all.find((y) => +y.id === x) || {}).status;
  const pos = nav.indexOf(+id);
  const show = new Set([0, nav.length - 1, pos - 2, pos - 1, pos, pos + 1, pos + 2].filter((k) => k >= 0 && k < nav.length));
  const nums = [];
  let prev = -1;
  [...show].sort((x, y) => x - y).forEach((k) => {
    if (prev >= 0 && k > prev + 1) nums.push('<span class="pg-gap">…</span>');
    const s = st(nav[k]);
    nums.push(`<button type="button" class="pg-n ${k === pos ? 'on' : ''} ${s === 'open' ? 'open' : 'done'}" data-inc-nav="${nav[k]}" title="${esc(s === 'open' ? L('open') : L('afgehandeld'))}">${k + 1}<i>${s === 'open' ? '•' : '✔'}</i></button>`);
    prev = k;
  });
  const done = nav.filter((x) => st(x) !== 'open').length;
  return `<div class="inc-pager"><button type="button" class="pg-arrow" data-inc-nav="${nav[(pos - 1 + nav.length) % nav.length]}" title="${esc(L('Vorige (←)'))}">‹</button>${nums.join('')}
    <button type="button" class="pg-arrow" data-inc-nav="${nav[(pos + 1) % nav.length]}" title="${esc(L('Volgende (→)'))}">›</button>
    <span class="small muted">${L('{p} van {n} · ✔ {d} afgehandeld · {o} open', { p: pos + 1, n: nav.length, d: done, o: nav.length - done })}</span></div>`;
}

async function incidentDialog(id, nav = null) {
  let d;
  try { d = await api('ids_incident_info', null, { id }); } catch (ex) { toast(ex.message, 'err'); return; }
  nav = nav && nav.includes(+id) ? nav : [+id];   // alleen de incidenten van de regel waarop je klikte (1.118.0)
  const i = d.incident, a = d.advice;
  const open = i.status === 'open';
  const agentHost = i.host_mode === 'agent';
  const sevTxt = +i.severity === 2 ? '🔴 Critical' : '🟠 Warning';
  modal({
    title: `${+i.severity === 2 ? '🔴' : '🟠'} ${esc(i.title)}`,
    wide: true,
    body: `<p class="small" style="margin-top:0">${sevTxt} · <b>${esc(i.host_name)}</b> · ${esc(fmtDateTime(i.created_at))}${+i.hits > 1 ? ` · ${L('{n}× gezien, laatst {t}', { n: i.hits, t: esc(fmtDateTime(i.last_seen || i.created_at)) })}` : ''}
        · ${open ? `<b class="warn-txt">${L('open')}</b>` : `✔ ${L('afgehandeld door {who}', { who: esc(i.handled_by || '?') })}${i.handled_at ? ' (' + esc(fmtDateTime(i.handled_at)) + ')' : ''}`}${d.ignored ? ` · <span class="muted">${L('wordt niet meer gemeld (normaal)')}</span>` : ''}</p>
      ${d.explain ? explainHtml(d.explain) : i.detail ? `<p class="small inc-detail">${esc(i.detail)}</p>` : ''}
      ${inspectHtml(d.evidence || [])}
      <h3 class="set-sub">💡 ${L('Wat is dit?')}</h3><p>${esc(a.what)}</p>
      ${a.risk ? `<h3 class="set-sub">⚖️ ${L('Hoe erg is het?')}</h3><p>${esc(a.risk)}</p>` : ''}
      ${a.ask && open ? `<div class="callout inc-ask need-admin"><b>❓ ${L('Was jij dit (of ken je het)?')}</b>
        <div class="btn-row"><button type="button" class="btn primary" data-inc-act="normal">✅ ${L('Ja, dit was ik / bekend')}</button>
        <button type="button" class="btn danger" data-inc-no>❌ ${L('Nee, ik ken dit niet')}</button></div>
        <p class="muted small">${L('"Ja" handelt het af en meldt dit voortaan niet meer (voor deze server). "Nee" toont meteen de noodstappen.')}</p></div>` : ''}
      <div id="inc-emergency" ${a.ask && open ? 'hidden' : ''}>
        <h3 class="set-sub">🚨 ${a.ask ? L('Niet van jou: dit moet je nu doen') : L('Als het misgaat: noodstappen')}</h3>
        <ol>${a.emergency.map((x) => `<li>${esc(x)}</li>`).join('')}</ol>${cmdList(a.ecmds, 'inc-ec')}</div>
      ${a.steps.length || a.cmds.length ? `<h3 class="set-sub">🛠️ ${L('Wat te doen')}</h3>${a.steps.length ? `<ul>${a.steps.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>` : ''}${cmdList(a.cmds, 'inc-c')}` : ''}
      ${a.prevent ? `<h3 class="set-sub">🛡️ ${L('Voorkomen')}</h3><p>${esc(a.prevent)}</p>` : ''}
      ${a.ip ? `<h3 class="set-sub">🌐 ${L('IP-adres')} <code>${esc(a.ip)}</code></h3><div id="inc-ip"></div>` : ''}
      <div class="btn-row need-admin inc-actions">
        ${open ? `<button type="button" class="btn" data-inc-act="handle">✔ ${L('Afhandelen')}</button>
          <button type="button" class="btn" data-inc-act="normal" title="${esc(L('Afhandelen en dit voortaan niet meer melden'))}">🚫 ${L('Dit is normaal')}</button>`
          : `<button type="button" class="btn" data-inc-act="reopen">↺ ${L('Heropenen')}</button>`}
        ${a.ip ? `<button type="button" class="btn" data-inc-block>⛔ ${L('Blokkeer IP')}</button>
          <button type="button" class="btn" data-inc-wl>✅ ${L('Op de whitelist')}</button>
          <button type="button" class="btn" data-inc-tl>🔎 ${L('Tijdlijn van dit IP')}</button>` : ''}
        ${a.user && agentHost && a.user !== 'root' ? `<button type="button" class="btn danger" data-inc-lock>🔒 ${L('Vergrendel {user}', { user: esc(a.user) })}</button>` : ''}
        ${agentHost ? `<button type="button" class="btn danger" data-inc-iso>🧱 ${L('Isoleer server (24 uur)')}</button>
          <button type="button" class="btn" data-inc-sec>🛡️ ${L('Beveiliging van {name}', { name: esc(i.host_name) })}</button>
          <button type="button" class="btn" data-inc-am>⚙️ Agent Manager</button>` : ''}
      </div>
      ${incidentPager(nav, id)}`,
    buttons: [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
  });
  const body = $('#modal-form');
  $('#modal').dataset.incNav = nav.length > 1 ? nav.join(',') : '';
  $('#modal').dataset.incCur = id;
  wireCopy(body);
  $$('[data-inc-step]', body).forEach((b) => { b.onclick = (e) => { e.preventDefault(); e.stopPropagation(); incidentDialog(+b.dataset.incStep); }; });
  $$('[data-prot-card]', body).forEach((b) => {
    b.onclick = async (e) => {
      e.preventDefault(); e.stopPropagation();
      $('#modal').close();
      showTab('ids/prot');
      await loadProt();
      const c = $('#prot-' + b.dataset.protCard);
      if (c) { c.scrollIntoView({ block: 'center' }); c.classList.add('live-pulse'); }
    };
  });
  if (a.ip) fillIpInfo($('#inc-ip'), a.ip);
  // Na afhandelen: in een bundel door naar het volgende open incident, anders sluiten
  const reload = async () => {
    await loadIds();
    const opens = nav.filter((x) => x !== +i.id && ((idsData.incidents || []).find((y) => +y.id === x) || {}).status === 'open');
    const after = opens.find((x) => nav.indexOf(x) > nav.indexOf(+i.id)) || opens[0];
    if (after) incidentDialog(after, nav); else $('#modal').close();
  };
  body.onclick = async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.incNav) return incidentDialog(+b.dataset.incNav, nav);
    if (b.dataset.evNav !== undefined) {   // door de gebeurtenissen van één incident (herhalingen)
      $$('[data-evi]', body).forEach((x) => { x.hidden = x.dataset.evi !== b.dataset.evNav; });
      $$('[data-ev-nav]', body).forEach((x) => x.classList.toggle('on', x === b));
      return;
    }
    try {
      if (b.dataset.incAct) {
        await api('ids_incident', { id: i.id, action: b.dataset.incAct });
        toast(b.dataset.incAct === 'normal' ? L('Afgehandeld; dit wordt voortaan niet meer gemeld') : b.dataset.incAct === 'reopen' ? L('Weer open') : L('Afgehandeld'));
        return reload();
      }
      if (b.hasAttribute('data-inc-no')) { $('#inc-emergency').hidden = false; $('#inc-emergency').scrollIntoView({ block: 'nearest' }); return; }
      if (b.hasAttribute('data-inc-block')) { $('#modal').close(); return idsBlockDialog(a.ip, i.host_id, i.title.slice(0, 200)); }
      if (b.hasAttribute('data-inc-wl')) { $('#modal').close(); return wlDialog({ type: 'ip', value: a.ip, src: 'ids', wl: false }); }
      if (b.hasAttribute('data-inc-tl')) { $('#modal').close(); return ipTimeline(a.ip); }
      if (b.hasAttribute('data-inc-sec')) { $('#modal').close(); return hostSecDialog(+i.host_id); }
      if (b.hasAttribute('data-inc-am')) { $('#modal').close(); return agentInstructions(findHost(+i.host_id)); }
      if (b.hasAttribute('data-inc-lock')) {
        const pw = await askConfirm({ title: L('{user} op {name} vergrendelen?', { user: a.user, name: i.host_name }), danger: true, word: 'VERGRENDELEN', ok: '🔒 ' + L('Vergrendelen'),
          text: L('Het account gaat op slot (geen wachtwoord, geen SSH-sleutel) en al zijn processen worden gestopt.') });
        if (pw === null) return;
        await api('host_userlock', { host_id: +i.host_id, user: a.user, lock: true, confirm: pw });
        toast(L('{user} wordt vergrendeld', { user: a.user }));
      }
      if (b.hasAttribute('data-inc-iso')) {
        const pw = await askConfirm({ title: L('{name} isoleren (24 uur)?', { name: i.host_name }), danger: true, word: 'ISOLEREN', ok: '🧱 ' + L('Isoleren'),
          text: L('Alleen RzMon, je lokale netwerken en jouw IP-adres kunnen er nog bij; al het andere verkeer stopt. Vervalt vanzelf na de gekozen tijd.') });
        if (pw === null) return;
        await api('host_isolate', { host_id: +i.host_id, hours: 24, confirm: pw });
        toast(L('{name} wordt geïsoleerd; de agent past het binnen een minuut toe', { name: i.host_name }));
      }
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

/** Eén incident als compacte rij; group = alle incidenten van een bundel (dan met 3× en een knop voor allemaal); nav = de bundel voor de nummers in het venster */
function idsRow(i, group = null, nav = null) {
  const sev = +i.severity === 2 ? 2 : 1;
  const done = i.status !== 'open';
  const fim = i.type === 'fim' || i.type === 'fim_bulk';
  const okLabel = fim ? (i.type === 'fim_bulk' ? L('✔ Alles goedkeuren') : L('✔ Wijziging goedkeuren')) : L('✔ Afhandelen');
  const normal = fim ? [L('🚫 Niet meer bewaken'), i.type === 'fim_bulk' ? L('Goedkeuren en wijzigingen onder dit pad voortaan niet meer melden') : L('Goedkeuren en dit bestand voortaan niet meer bewaken')]
    : i.type === 'webshell' ? ['✅ ' + L('Vrijgeven'), L('Dit is normaal voor deze website: voortaan niet meer melden en niet meer stoppen')]
    : [L('Dit is normaal'), L('Afhandelen en dit voortaan niet meer melden')];
  // Uitgaand naar een bekende aanvaller: vrijgeven = het IP op de whitelist (dan ook niet meer geblokkeerd)
  const release = i.type === 'out_bad' && i.ip ? ` <button type="button" class="btn small-btn" data-ids="release" data-ip="${esc(i.ip)}" title="${esc(L('Dit IP op de whitelist: nooit meer melden of blokkeren'))}">✅ ${L('Vrijgeven')}</button>` : '';
  const many = group && !done ? group.filter((x) => x.status === 'open').map((x) => x.id).join(',') : '';
  return `<div class="irow s${sev} ${done ? 'ids-done' : ''}">
    <i class="adot s${sev}"></i>
    <span class="m-tt"><button type="button" class="inc-open" data-inc="${i.id}" ${nav && nav.length > 1 ? `data-inc-list="${nav.map((x) => x.id).join(',')}"` : ''} title="${esc(L('Details, advies en acties'))}">${esc(i.title)}</button>${actChips(((idsData && idsData.prot) || {})[i.id])}${+i.hits > 1 ? ` <span class="rep hits" tabindex="0" title="${esc(L('Dit incident is {n}× gezien · eerst {a} · laatst {b}', { n: i.hits, a: fmtDateTime(i.created_at), b: fmtDateTime(i.last_seen || i.created_at) }))}">${i.hits}×</span>` : ''}${group ? ` <button type="button" class="rep" data-bundle-btn title="${esc(L('{n} incidenten van deze soort op {host} ({o} open) · van {a} tot {b} · klik om ze te tonen; klik op de titel om ze één voor één te bekijken', { n: group.length, host: i.host_name, o: group.filter((x) => x.status === 'open').length, a: group[group.length - 1].created_at.slice(11, 16), b: group[0].created_at.slice(11, 16) }))}">${group.length}×</button>` : ''}
      <small>${esc(i.detail)}${i.ip ? ` · IP <code>${esc(i.ip)}</code>${ccTag(i.cc)}` : ''}${done ? ` · ✔ ${L('afgehandeld door {who}', { who: esc(i.handled_by || '?') })}` : ''}</small></span>
    <span class="m-sv">${esc(i.host_name)}</span>
    <span class="m-t" title="${esc(i.created_at)}">${esc(i.created_at.slice(11, 16))}</span>
    <span class="ids-actions need-admin">${done ? `<button type="button" class="linkbtn" data-ids="reopen" data-id="${i.id}">↺ ${L('heropenen')}</button>`
      : many ? `<button type="button" class="btn small-btn" data-ids="handle" data-many="${many}" title="${esc(L('Alle {n} afhandelen', { n: many.split(',').length }))}">${okLabel} (${many.split(',').length})</button>`
      : `<button type="button" class="btn small-btn" data-ids="handle" data-id="${i.id}">${okLabel}</button>
         ${release || `<button type="button" class="btn small-btn" data-ids="normal" data-id="${i.id}" title="${normal[1]}">${normal[0]}</button>`}`}
      ${i.ip && !many ? `<button type="button" class="btn small-btn" data-ids="block" data-ip="${esc(i.ip)}" data-host="${i.host_id}">⛔ ${L('Blokkeer IP')}</button>` : ''}</span>
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
          <td class="at-msg" title="${density === 'small' ? L('Klik voor het hele bericht') : ''}">${esc(splitSent(a.message)[0])}</td>
          <td class="at-ch">${channelIcons(a)}${alertBlock(a)}</td></tr>`;
      }).join('')}</tbody></table>`;
  }
  // Blox: compacte rijen; dezelfde melding direct achter elkaar wordt gebundeld (5×, uit te klappen)
  const dot = { crit: 's2', warn: 's1', unk: 's3', ok: 's0', info: 'info', sec: 'sec', other: 'ack' };
  const row = (a, n = 0, bkey = '') => {
    const { host } = alertCols(a);
    return `<div class="mrow ${density === 'small' ? 'compact' : ''}${a.check_id ? ' clickable' : ''}"${cid(a)}>
      <span class="m-t" title="${esc(a.created_at)}">${time(a)}</span><i class="adot ${dot[alertCat(a)]}"></i>
      <span class="m-tt">${esc(a.title)}${n ? ` <button type="button" class="rep" data-bundle-btn title="${esc(L('{n} keer dezelfde melding – klik om ze te tonen', { n }))}">${n}×</button>` : ''}<small>${esc(splitSent(a.message)[0])}</small></span>
      <span class="m-sv">${esc(host)}</span>
      <span class="m-ch">${channelIcons(a)}${alertBlock(a)}</span></div>`;
  };
  const groups = [];
  list.forEach((a) => {
    const k = `${a.kind}|${a.title}|${splitSent(a.message)[0]}`;
    const g = groups[groups.length - 1];
    if (g && g.k === k) g.items.push(a); else groups.push({ k, items: [a] });
  });
  return groups.map((g) => {
    if (g.items.length === 1) return row(g.items[0]);
    const bkey = 'b' + g.items[g.items.length - 1].id;   // de oudste blijft gelijk als er een nieuwe bijkomt
    return `<div class="bundle${alertBundles.has(bkey) ? ' open' : ''}" data-bundle="${bkey}">${row(g.items[0], g.items.length)}${g.items.slice(1).map((a) => row(a)).join('')}</div>`;
  }).join('');
}

const alertBundles = new Set();   // uitgeklapte bundels
/** Klik op 5×: bundel uit- of inklappen (en onthouden tot de pagina herladen wordt). true = afgehandeld */
function toggleBundle(e, set = idsBundles) {
  const rb = e.target.closest('[data-bundle-btn]');
  if (!rb) return false;
  const b = rb.closest('.bundle');
  b.classList.toggle('open') ? set.add(b.dataset.bundle) : set.delete(b.dataset.bundle);
  return true;
}
/** Items met dezelfde sleutel samen: [{ k, items }]. Alleen direct achter elkaar, of (anywhere) overal in de lijst, op de plek van de eerste */
function bundleRuns(list, key, anywhere = false) {
  const out = [], seen = new Map();
  list.forEach((x) => {
    const k = key(x);
    const g = anywhere ? seen.get(k) : out[out.length - 1];
    if (g && g.k === k) g.items.push(x);
    else { const n = { k, items: [x] }; out.push(n); seen.set(k, n); }
  });
  return out;
}
/** Soort melding: crit, warn, unk, ok (hersteld), info, sec (beveiliging: geweigerd of IDS), other (bevestigd, onderhoud) */
function alertCat(a) {
  if (a.kind === 'sec' || a.kind === 'ids') return 'sec';
  if (a.kind === 'info') return 'info';
  if (a.kind === 'ack' || a.kind === 'maint') return 'other';
  if (/^\S+ (HERSTELD|RECOVERED)/.test(a.title)) return 'ok';
  return ['ok', 'warn', 'crit', 'unk'][+a.status] || 'other';
}
const ALERT_CATS = [['crit', 2, 'Critical'], ['warn', 1, 'Warning'], ['unk', 3, () => L('Onbekend')], ['ok', 0, () => L('Hersteld')], ['info', 'i', 'Info'], ['sec', 'x', () => L('Beveiliging')]];
const catLabel = (k) => { const w = ALERT_CATS.find((c) => c[0] === k)[2]; return typeof w === 'function' ? w() : w; };
// 'noinfo' (1.84.0): alles behalve de ℹ️-systeeminfo
const alertMatch = (f, a) => f === 'all' || (f === 'noinfo' ? alertCat(a) !== 'info' : f === 'problems' ? ['crit', 'warn', 'unk'].includes(alertCat(a)) : alertCat(a) === f);

/** Tellers (vandaag) en kerncijfers bovenaan de pagina Meldingen */
function renderAlertTop(d) {
  // Zonder info: ook de tegels tellen de ℹ️-systeeminfo niet mee (de tellers per soort wel, die tonen elke soort)
  const noInfo = alertFilter === 'noinfo';
  const base = noInfo ? d.alerts.filter((a) => alertMatch('noinfo', a)) : d.alerts;
  const today = d.alerts.filter((a) => a.created_at.startsWith(d.today));
  const todayB = base.filter((a) => a.created_at.startsWith(d.today));
  const n = (f) => today.filter((a) => alertMatch(f, a)).length;
  $('#alerts-counts').innerHTML = ALERT_CATS.map(([k, st]) => {
    const c = n(k);
    return `<button type="button" class="hc s${st} ${alertFilter === k ? 'on' : ''} ${c ? '' : 'zero'}" data-af="${k}" title="${esc(L('Klik om alleen deze te tonen'))}"><span class="hc-dot"></span><b>${c}</b> <span class="hc-w">${catLabel(k)}</span></button>`;
  }).join('');
  const y = new Date(d.today + 'T12:00:00'); y.setDate(y.getDate() - 1);
  const yday = y.toISOString().slice(0, 10);
  const nY = base.filter((a) => a.created_at.startsWith(yday)).length;
  const diff = nY ? Math.round((todayB.length - nY) / nY * 100) : null;
  // Per uur vandaag (tot nu)
  const hours = new Array(new Date().getHours() + 1).fill(0);
  todayB.forEach((a) => { const h = +a.created_at.slice(11, 13); if (h < hours.length) hours[h]++; });
  const max = Math.max(1, ...hours);
  let path = '';
  hours.forEach((v, i) => { path += `${path ? 'L' : 'M'}${(hours.length > 1 ? i / (hours.length - 1) * 100 : 50).toFixed(1)},${(24 - v / max * 20).toFixed(1)}`; });
  // Laatste 24 uur: niet verzonden en drukste server
  const since = new Date(Date.now() - 86400000);
  const pad = (x) => String(x).padStart(2, '0');
  const sinceStr = `${since.getFullYear()}-${pad(since.getMonth() + 1)}-${pad(since.getDate())} ${pad(since.getHours())}:${pad(since.getMinutes())}`;
  const last24 = base.filter((a) => a.created_at >= sinceStr);
  const failed = last24.filter((a) => splitSent(a.message)[1] || (a.email_ok !== null && a.email_ok !== undefined && !+a.email_ok) || (a.webhook_ok !== null && a.webhook_ok !== undefined && !+a.webhook_ok));
  const reasons = {};
  failed.forEach((a) => { const r = splitSent(a.message)[1].split(';')[0].trim(); if (r) reasons[r] = (reasons[r] || 0) + 1; });
  const topReason = Object.entries(reasons).sort((p, q) => q[1] - p[1])[0];
  const hosts = {};
  last24.forEach((a) => { const h = alertCols(a).host; hosts[h] = (hosts[h] || 0) + 1; });
  const busy = Object.entries(hosts).sort((p, q) => q[1] - p[1])[0];
  const open = state.health ? state.health.open_problems : 0;
  const lastA = base[0];
  $('#alerts-kpis').innerHTML = `
    <div class="kpi kpi-main" title="${esc(L('Per uur vandaag'))}"><div class="kpi-txt"><div class="kpi-lbl">${noInfo ? L('Meldingen vandaag, zonder info') : L('Meldingen vandaag')}</div><div class="kpi-val">${todayB.length}</div>
      <div class="kpi-sub">${L('gisteren {n}', { n: nY })}${diff !== null && diff !== 0 ? ` · <b class="${diff > 0 ? 'danger-txt' : 'good-txt'}">${diff > 0 ? '+' : ''}${diff}%</b>` : ''}</div></div>
      ${hours.length > 1 ? `<svg class="kpi-spark kpi-spark-big" viewBox="0 0 100 26" preserveAspectRatio="none" aria-hidden="true"><path d="${path}L100,26L0,26Z" fill="var(--primary)" opacity=".14"/><path d="${path}" fill="none" stroke="var(--primary)" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>` : ''}</div>
    <div class="kpi ${open ? 'bad' : ''}"><div class="kpi-lbl">${L('Nu open')}</div><div class="kpi-val">${open}</div>
      <div class="kpi-sub">${open ? L('storingen') + ' · ' : ''}<button type="button" class="linkbtn" data-go="dashboard">${L('naar het dashboard')}</button></div></div>
    <div class="kpi ${failed.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Niet verzonden 24 u')}</div><div class="kpi-val">${failed.length}</div>
      <div class="kpi-sub" title="${esc(topReason ? topReason[0] : '')}">${topReason ? esc(topReason[0]) : L('alles verzonden')}${failed.length && isAdmin() ? ` · <button type="button" class="linkbtn" data-go="settings/channels">${L('kanalen')}</button>` : ''}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Drukste server 24 u')}</div><div class="kpi-val kpi-val-txt">${busy ? esc(busy[0]) : '–'}</div>
      <div class="kpi-sub">${busy ? (busy[1] === 1 ? L('1 melding') : L('{n} meldingen', { n: busy[1] })) : L('geen meldingen')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Laatste melding')}</div><div class="kpi-val kpi-val-txt">${lastA ? esc(lastA.created_at.startsWith(d.today) ? lastA.created_at.slice(11, 16) : fmtDateTime(lastA.created_at)) : '–'}</div>
      <div class="kpi-sub">${lastA ? esc(lastA.title) : ''}</div></div>`;
  // Chips met aantallen (alle dagen) en de keuzelijst met servers
  const chips = [['all', L('Alles')], ['noinfo', L('Zonder info')], ['problems', L('Storingen')], ['ok', L('Hersteld')], ['info', 'Info'], ['sec', L('Beveiliging')]];
  $('#alerts-filter').innerHTML = chips.map(([k, l]) => `<button type="button" class="dchip ${alertFilter === k ? 'on' : ''}" data-af="${k}">${l} <b>${d.alerts.filter((a) => alertMatch(k, a)).length}</b></button>`).join('')
    + (['crit', 'warn', 'unk'].includes(alertFilter) ? `<button type="button" class="dchip on" data-af="${alertFilter}" title="${esc(L('Filter opheffen'))}">${catLabel(alertFilter)} ✕</button>` : '');
  const sel = $('#alerts-host'), cur = sel.value;
  const names = [...new Set(d.alerts.map((a) => alertCols(a).host))].sort((p, q) => p.localeCompare(q, LANG, { sensitivity: 'base' }));
  sel.innerHTML = `<option value="">${L('Alle servers')}</option>` + names.map((h) => `<option value="${esc(h)}">${esc(h)}</option>`).join('');
  sel.value = names.includes(cur) ? cur : '';
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
    + ' · ' + (d.days === 1 ? L('van de laatste dag') : L('van de laatste {n} dagen', { n: d.days }))
    + (d.truncated ? ' ' + L('(alleen de nieuwste 10.000)') : '');
  if (!['all', 'noinfo', 'problems', 'crit', 'warn', 'unk', 'ok', 'info', 'sec'].includes(alertFilter)) alertFilter = 'all';
  renderAlertTop(d);
  const q = $('#alerts-search').value.trim().toLowerCase();
  const host = $('#alerts-host').value;
  const shown = d.alerts.filter((a) => alertMatch(alertFilter, a) && (!host || alertCols(a).host === host)
    && (!q || `${a.title} ${a.message} ${alertCols(a).host}`.toLowerCase().includes(q)) && fxPass('alerts', a));
  fxRender('alerts');
  const narrowed = !!q || !!host || fxActive('alerts');
  if (q) moreDaysWhenSearching('alerts', d);
  if (!shown.length) { el.innerHTML = `<p class="muted">${d.alerts.length ? L('Geen meldingen met dit filter.') : L('Nog geen meldingen.') + ' 👌'}</p>` + moreDays('alerts', d); $('#alerts-toggle').hidden = true; return; }
  const byDay = new Map();
  shown.forEach((a) => {
    const day = a.created_at.slice(0, 10);
    if (!byDay.has(day)) byDay.set(day, []);
    byDay.get(day).push(a);
  });
  // Vandaag altijd open (1.78.0); de andere dagen dicht, met zoeken of een filter de dagen met treffers open
  const fresh = todayFresh('alerts', alertDayOpen, d.today, (byDay.get(d.today) || []).length);
  const isOpen = (day) => alertDayOpen[day] ?? (day === d.today || narrowed);
  el.innerHTML = (!narrowed && !byDay.has(d.today) ? todayEmpty(d.today, L('geen meldingen') + ' 👌') : '') + [...byDay].map(([day, list]) => `
    <details class="alert-day" data-day="${day}" ${isOpen(day) ? 'open' : ''} ${fresh && day === d.today ? 'data-fresh="1"' : ''}>
      <summary><span class="ad-title">${esc(dayLabel(day, d.today))}</span>
        <span class="ad-counts">${alertDayCounts(list)}</span>
        <span class="muted small ad-total">${list.length === 1 ? L('1 melding') : L('{n} meldingen', { n: list.length })}</span></summary>
      <div class="ad-body">${isOpen(day) ? alertRows(list, layout, density) : ''}</div>
    </details>`).join('') + moreDays('alerts', d);
  // Pas bij openen opbouwen: snel, ook met duizenden meldingen
  $$('.alert-day', el).forEach((det) => (det.ontoggle = () => {
    alertDayOpen[det.dataset.day] = det.open;
    if (det.open && !$('.ad-body', det).innerHTML) $('.ad-body', det).innerHTML = alertRows(byDay.get(det.dataset.day), layout, density);
    alertToggleLabel();
  }));
  $('#alerts-toggle').hidden = byDay.size < 2;
  alertToggleLabel();
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
  const idle = (m) => (m < 60 ? L('{n} minuten', { n: m }) : m === 1440 ? L('1 dag') : L('{n} uur', { n: m / 60 }));
  f.session_idle.innerHTML = (a.session_idle_choices || []).map((m) => `<option value="${m}" ${m === a.session_idle ? 'selected' : ''}>${esc(idle(m))}</option>`).join('');
  $('#ip-status').innerHTML = `${L('Jouw IP-adres: {ip}', { ip: `<code>${esc(a.client_ip)}</code>` })}${a.client_ip !== a.remote_addr ? ` <span class="muted small">${L('(via proxy {ip})', { ip: esc(a.remote_addr) })}</span>` : ''}
    — ${a.client_trusted ? '<b class="yes">' + L('valt binnen je lokale netwerken') + '</b>' : '<b class="no">' + L('valt buiten je lokale netwerken') + '</b>'}
    ${a.unknown_proxy ? `<div class="bad small">⚠️ ${L('Je komt via een proxy ({ip}) die niet bij Reverse proxy IP\'s staat: RzMon behandelt je daarom als extern.', { ip: esc(a.remote_addr) })}</div>` : ''}`;
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
      login_lock_account: f.login_lock_account.checked, login_lock_ip: f.login_lock_ip.checked, session_idle: +f.session_idle.value });
    if (el && el.form === f) savedFlag(el, true);
    loadAccess();
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
    if (el && el.form === f) savedFlag(el, false, ex.message);
  }
}

// ---------------------------------------------------------------- Beheer via https (1.68.0)
let httpsInfo = null;
async function loadHttps() {
  try { httpsInfo = await api('https_info'); } catch (ex) { return; }
  renderHttps();
}

/** Eén certificaat als blok: uitgever, namen, geldigheid, vingerafdruk */
/** selfOk: het eigen certificaat van RzMon (nooddeur, agents): niet door een bekende uitgever ondertekend is dan normaal */
function certBlock(title, c, extra = '', selfOk = false) {
  if (!c) return '';
  if (!c.ok) return `<div class="cert-box bad"><div class="cert-h">${title}</div><div class="small"><b class="bad">✘ ${esc(c.error || L('geen verbinding'))}</b></div>${extra}</div>`;
  const left = c.days < 0 ? `<b class="bad">${L('VERLOPEN')}</b>` : c.days <= ((httpsInfo && httpsInfo.warn_days) || 14) ? `<b class="warn-txt">${L('nog {n} dagen', { n: c.days })}</b>` : L('nog {n} dagen', { n: c.days });
  const d = (t) => new Date(t * 1000).toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
  const own = selfOk && !c.trusted;   // eigen certificaat: self-signed, of van de eigen CA van de webserver (bv. mod_ssl op Rocky)
  const trust = c.trusted ? `<b class="good">✔ ${L('vertrouwd')}</b>` : own || c.self_signed ? `<span class="muted" title="${esc(L('Niet ondertekend door een bekende uitgever. Voor het eigen certificaat van RzMon is dat normaal: de agents zetten het vast, en je browser vraagt eenmalig om "toch doorgaan".'))}">🔏 ${L('self-signed')}</span>` : `<b class="bad">✘ ${esc(c.error || L('niet vertrouwd'))}</b>`;
  const okish = (c.trusted || own) && c.days > ((httpsInfo && httpsInfo.warn_days) || 14);
  return `<div class="cert-box ${okish ? 'ok' : c.days >= 0 && (c.trusted || c.self_signed || own) ? 'warn' : 'bad'}">
    <div class="cert-h">${title} ${trust}</div>
    <dl class="cert-dl small">
      <dt>${L('Uitgever')}</dt><dd>${esc(c.issuer)}</dd>
      <dt>${L('Namen')}</dt><dd>${esc((c.names && c.names.length ? c.names : [c.cn]).join(', '))}</dd>
      <dt>${L('Geldig')}</dt><dd>${d(c.from)} – ${d(c.to)} · ${left}</dd>
      <dt>${L('Vingerafdruk')}</dt><dd class="mono fp">SHA-256 ${esc(c.fp)}</dd>
    </dl>${extra}</div>`;
}

function renderHttps() {
  const h = httpsInfo;
  if (!h) return;
  const r = h.req;
  const ok = (b, yes, no) => (b ? `<span class="good">✔ ${yes}</span>` : `<span class="bad">✘ ${no}</span>`);
  // Hoe zit je er nu?
  const rows = [
    ok(r.https, L('je bent nu via https verbonden'), L('je bent nu via http verbonden')) + ` <span class="muted small">(${esc(r.host)})</span>`,
    r.unknown_proxy ? `<span class="bad">✘ ${L('via een proxy die niet bij Vertrouwde proxy\'s staat ({ip}): je geldt als extern', { ip: esc(r.remote_addr) })}</span>`
      : r.via_proxy ? (r.no_proto ? `<span class="warn-txt">⚠️ ${L('via je proxy {ip}, maar die meldt geen https (X-Forwarded-Proto)', { ip: esc(r.remote_addr) })}</span>` : `<span class="good">✔ ${L('via je vertrouwde proxy {ip}', { ip: esc(r.remote_addr) })}</span>`)
      : `<span class="muted">${L('rechtstreeks, zonder proxy')}</span>`,
    r.internal ? `<span class="good">🏠 ${L('vanaf je interne netwerk ({ip})', { ip: esc(r.client_ip) })}</span>` : `<span class="warn-txt">🌍 ${L('van buiten je interne netwerken ({ip})', { ip: esc(r.client_ip) })}</span>`,
  ];
  if (h.admin_url) rows.push(r.on_admin ? `<span class="good">✔ ${L('je zit op het beheeradres')}</span>` : `<span class="muted">${L('je zit niet op het beheeradres')} – <a href="${esc(h.admin_url)}/">${esc(h.admin_url)}</a></span>`);
  $('#https-now').innerHTML = rows.map((x) => `<div>${x}</div>`).join('');
  const urlIn = $('#https-url');
  if (document.activeElement !== urlIn) urlIn.value = h.admin_url || '';
  // Certificaten en DNS
  const s = h.state || {};
  const host = h.admin_url ? h.admin_url.replace(/^https:\/\//, '').replace(/[:/].*$/, '') : '';
  let html = '';
  if (h.admin_url && s.admin) {
    const dns = s.dns ? (s.dns.ips.length ? (s.dns.private ? `<div class="small"><span class="good">✔ ${L('DNS: {host} wijst intern naar {ip}', { host: esc(host), ip: esc(s.dns.ips.join(', ')) })}</span></div>`
      : `<div class="small"><span class="warn-txt">⚠️ ${L('DNS: {host} wijst naar {ip} (openbaar). Zet in AdGuard Home een DNS-rewrite naar het LAN-IP van SWAG.', { host: esc(host), ip: esc(s.dns.ips.join(', ')) })}</span></div>`)
      : `<div class="small"><span class="bad">✘ ${L('DNS: {host} is vanaf de RzMon-server niet te vinden', { host: esc(host) })}</span></div>`) : '';
    html += certBlock('🌍 ' + L('Beheeradres'), s.admin, dns);
  } else if (h.admin_url) html += `<p class="muted small">${L('Nog niet gecontroleerd: klik op Nu controleren.')}</p>`;
  if (s.local) html += certBlock('🚪 ' + L('Nooddeur') + (h.door.includes('<') ? ` <span class="small muted">${esc(h.door)}</span>` : ` <a class="small" href="${esc(h.door)}">${esc(h.door)}</a>`), s.local, `<div class="muted small">${L('Alleen vanaf je interne netwerken; het certificaat van RzMon zelf.')}</div>`, true);
  if (h.checked_at) html += `<p class="muted small" style="margin:.3rem 0 0">${L('Gecontroleerd {ago}; daarna elke dag. ℹ️ {n} dagen voor het verloopt, ⚠️ als het verlopen of ongeldig is.', { ago: ago(h.checked_at), n: h.warn_days })}</p>`;
  $('#https-cert').innerHTML = html;
  // Schakelaars
  $('#https-only').checked = h.https_only;
  $('#https-block').checked = h.http_login_block;
  $('#https-mfa').checked = h.mfa_external;
  $('#https-hsts').innerHTML = h.hsts ? (h.hsts.full_at ? L('HSTS: 1 dag (testfase). Na {d} zonder problemen met het certificaat vanzelf 1 jaar.', { d: esc(fmtDateTime(h.hsts.full_at)) }) : L('HSTS: 1 jaar.'))
    : (h.admin_url && !r.on_admin ? L('Aanzetten kan alleen als je RzMon nu via het beheeradres opent.') : '');
  $('#https-mfa-info').innerHTML = h.no2fa.length ? L('Zonder 2FA (van buiten dan geweigerd): {names}', { names: h.no2fa.map(esc).join(', ') }) : L('Alle gebruikers hebben 2FA.');
  // Voorbeeld voor SWAG
  $('#https-conf').textContent = `## nginx/proxy-confs/rzmon.subdomain.conf\nserver {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name ${host ? host.split('.')[0] : 'rzmon'}.*;\n    include /config/nginx/ssl.conf;\n    client_max_body_size 64m;\n\n    location / {\n        include /config/nginx/proxy.conf;\n        include /config/nginx/resolver.conf;\n        set $upstream_app ${s.server_ip || '192.168.1.10'};\n        set $upstream_port 80;\n        set $upstream_proto http;\n        proxy_pass $upstream_proto://$upstream_app:$upstream_port;\n    }\n}`;
}

async function saveHttps(extra = {}, el = null) {
  const err = $('#https-card .form-error');
  err.hidden = true;
  try {
    await api('https_save', { admin_url: $('#https-url').value.trim(), https_only: $('#https-only').checked, http_login_block: $('#https-block').checked, mfa_external: $('#https-mfa').checked, ...extra });
    if (el) savedFlag(el, true);
    toast(L('Opgeslagen'));
  } catch (ex) {
    err.textContent = ex.message;
    err.hidden = false;
    if (el) savedFlag(el, false, ex.message);
  }
  await loadHttps();
  loadSettingsHealth();
}

function initHttps() {
  const card = $('#https-card');
  if (!card) return;
  const sw = { 'https-only': 'https_only', 'https-block': 'http_login_block', 'https-mfa': 'mfa_external' };
  card.addEventListener('change', (e) => {
    const t = e.target;
    if (t.id === 'https-url') { saveHttps({}, t); return; }
    if (!sw[t.id] || !httpsInfo) return;
    if (t.checked || !httpsInfo[sw[t.id]]) { saveHttps({}, t); return; }
    // Uitzetten maakt het beheer minder veilig: eerst bevestigen
    modal({
      title: '⚠️ ' + L('Minder veilig maken?'),
      body: `<p style="margin-top:0">${t.id === 'https-only' ? L('De beheerpagina is dan ook weer via http bereikbaar. Je browser krijgt het signaal om HSTS te vergeten.')
        : t.id === 'https-block' ? L('Inloggen via http kan dan weer: je wachtwoord gaat dan leesbaar over het netwerk.')
        : L('Van buiten je interne netwerken is dan een wachtwoord genoeg.')}</p>${confirmField('UITZETTEN')}`,
      buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); renderHttps(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
      onSubmit: async (d) => { await api('https_save', { admin_url: $('#https-url').value.trim(), https_only: $('#https-only').checked, http_login_block: $('#https-block').checked, mfa_external: $('#https-mfa').checked, confirm: d.confirm }); toast(L('Opgeslagen')); loadHttps(); loadSettingsHealth(); },
    });
  });
  $('#https-check').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      if ($('#https-url').value.trim() !== (httpsInfo && httpsInfo.admin_url || '')) await saveHttps();
      else { await api('https_check', {}); await loadHttps(); loadSettingsHealth(); }
    } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  };
}

// ---------------------------------------------------------------- Datalekken: instellingen (1.69.0)
let hibpInfo = null;
async function loadHibp() {
  try { hibpInfo = await api('hibp_info'); } catch (ex) { return; }
  const h = hibpInfo;
  $('#hibp-on').checked = h.enabled;
  $('#hibp-body').classList.toggle('dim', !h.enabled);
  const set = (id, v) => { if (document.activeElement !== $(id)) $(id).value = v; };
  set('#hibp-threshold', h.threshold);
  set('#hibp-emails', h.emails);
  set('#hibp-domain', h.domain);
  $('#hibp-key').value = '';
  $('#hibp-key').placeholder = h.has_key ? L('ingevuld (laat leeg om te houden)') : L('nog geen sleutel');
  $('#hibp-key-state').innerHTML = h.has_key ? `<span class="good-txt">✔ ${L('ingevuld')}</span> <a href="#" id="hibp-key-del">${L('verwijderen')}</a>` : '';
  const s = h.summary;
  $('#hibp-status').innerHTML = !h.enabled ? L('Staat uit: er gaat niets naar Have I Been Pwned.')
    : [h.has_key ? L('{n} adressen; {r} verzoeken per minuut', { n: h.count, r: h.rpm }) : L('Zonder sleutel: alleen wachtwoorden.'),
      h.check_at ? L('laatst gecontroleerd {t}', { t: ago(h.check_at) }) : '',
      s && s.open ? `<b class="warn-txt">${L('{n} open lekken', { n: s.open })}</b>` : '',
      h.last_error ? `<b class="bad">⚠️ ${esc(h.last_error)}</b>` : '', h.domain_error ? `<b class="bad">⚠️ ${esc(h.domain_error)}</b>` : ''].filter(Boolean).join(' · ');
  $('#hibp-key-del')?.addEventListener('click', async (e) => { e.preventDefault(); if (!confirm(L('API-sleutel verwijderen?'))) return; await saveHibp({ key: '' }); });
}

async function saveHibp(data, el) {
  const err = $('#hibp-card .form-error');
  err.hidden = true;
  try {
    await api('hibp_save', data);
    if (el) savedFlag(el, true);
    await loadHibp();
    loadSettingsHealth();
    return true;
  } catch (ex) {
    err.textContent = ex.message; err.hidden = false;
    if (el) savedFlag(el, false, ex.message);
    return false;
  }
}

function initHibp() {
  if (!$('#hibp-card')) return;
  $('#hibp-on').onchange = async (e) => {
    if (await saveHibp({ enabled: e.target.checked }, e.target)) toast(e.target.checked ? L('Controle op datalekken staat aan') : L('Controle op datalekken staat uit'));
    else e.target.checked = !e.target.checked;
  };
  $('#hibp-threshold').onchange = (e) => saveHibp({ threshold: +e.target.value }, e.target);
  $('#hibp-emails').onchange = (e) => saveHibp({ emails: e.target.value }, e.target);
  $('#hibp-domain').onchange = (e) => saveHibp({ domain: e.target.value }, e.target);
  $('#hibp-key').onchange = (e) => { if (e.target.value.trim()) saveHibp({ key: e.target.value.trim() }, e.target); };
  $('#hibp-test').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    const out = $('#hibp-test-out');
    try {
      const r = await api('hibp_test', { key: $('#hibp-key').value.trim() });
      out.innerHTML = `<span class="good-txt">✔ ${L('Sleutel werkt: {name}', { name: esc(r.name) })}</span>${r.rpm ? ' · ' + L('{n} verzoeken per minuut', { n: r.rpm }) : ''}${r.until ? ' · ' + L('tot {d}', { d: esc(fmtDay(r.until.slice(0, 10))) }) : ''}`;
    } catch (ex) { out.innerHTML = `<span class="bad">✘ ${esc(ex.message)}</span>`; }
    b.disabled = false;
  };
}

// ---------------------------------------------------------------- Certificaat voor de agents (1.70.0)
/** Venster: welk certificaat de agents zien, de vingerafdruk per agent, downloaden en vernieuwen */
async function agentCertDialog() {
  let d;
  try { d = await api('agent_cert'); } catch (ex) { return toast(ex.message, 'err'); }
  const loc = d.local || {};
  const httpAgents = d.scheme !== 'https';
  const where = !d.base ? `<span class="warn-txt">${L('Geen Basis-URL ingesteld (Setup); de agents gebruiken het adres uit hun installatiecommando.')}</span>`
    : httpAgents ? `<code>${esc(d.base)}</code> · 🔓 ${L('http: geen certificaat nodig. De berichten zijn wel versleuteld met de eigen sleutel van elke agent.')}`
    : d.own ? `<code>${esc(d.base)}</code> · ${L('het eigen certificaat van RzMon (hieronder)')}`
    : `<code>${esc(d.base)}</code> · ${L('een ander certificaat, bv. van je reverse proxy (Let\'s Encrypt): dat vernieuwt de proxy zelf')}`;
  const pinRow = !d.pin_on ? `<p class="small muted">📌 ${L('Certificaat vastzetten staat uit.')}</p>`
    : d.pin ? `<p class="small cert-pin">📌 ${L('Vingerafdruk die de agents vastzetten')}: <code class="small">sha256//${esc(d.pin)}</code>
        ${loc.pin ? (loc.pin === d.pin ? ` <span class="good-txt">✔ ${L('klopt met het certificaat')}</span>` : ` <span class="warn-txt">⏳ ${L('wordt bij het volgende rapport opnieuw bepaald')}</span>`) : ''}</p>`
    : (d.pinned || []).length && loc.pin ? `<p class="small cert-pin">📌 ${L('Vingerafdruk die de agents vastzetten')}: <code class="small">sha256//${esc(loc.pin)}</code></p>`
    : `<p class="small muted">📌 ${L('Niets vast te zetten: de agents gebruiken http, of een certificaat dat al vertrouwd is.')}</p>`;
  const hostRows = d.hosts.map((h) => {
    const off = h.pin_mode === 'off' || (!h.pin_mode && !d.pin_on);
    // Agent 1.53.0: vastzetten per adres (het eigen certificaat), met een klaargezet nieuw certificaat erbij
    const specs = `${h.agent_url_pin || ''};${h.agent_url_fb_pin || ''}`;
    const perUrl = loc.pin && specs.includes('sha256//') ? (specs.includes('sha256//' + loc.pin)
      ? `<span class="good-txt">✔ ${L('kent het huidige certificaat')}</span>${d.next ? (specs.includes('sha256//' + d.next.pin) ? ` · <span class="good-txt">✔ ${L('en het nieuwe')}</span>` : ` · <span class="warn-txt">⏳ ${L('het nieuwe nog niet')}</span>`) : ''}`
      : `<span class="warn-txt">⏳ ${L('krijgt het nieuwe bij zijn volgende rapport')}</span>`) : '';
    const st = perUrl || (off ? `<span class="muted">${L('vastzetten uit')}</span>` : !d.pin ? '<span class="muted">–</span>'
      : h.agent_pin_sent === d.pin ? `<span class="good-txt">✔ ${L('kent het huidige certificaat')}</span>`
      : h.agent_pin_sent ? `<span class="warn-txt">⏳ ${L('krijgt het nieuwe bij zijn volgende rapport')}</span>` : `<span class="muted">${L('nog niet')}</span>`);
    return `<tr><td>${esc(h.name)}</td><td class="mono small">${esc(h.agent_version || '–')}</td><td class="small">${st}</td>
      <td class="small muted">${h.agent_pin_sent_at ? esc(ago(h.agent_pin_sent_at)) : ''}</td></tr>`;
  }).join('');
  const canRenew = d.paths && d.via !== 'cli';
  // Klaargezet nieuw certificaat (1.78.0): de agents krijgen eerst de vingerafdruk, daarna wisselt RzMon
  const nx = d.next;
  const waiting = (d.pinned || []).filter((a) => !a.ready);
  const nextBox = nx ? `<div class="callout">🔐 <b>${L('Nieuw certificaat klaargezet')}</b> <span class="muted small">${esc(ago(new Date(nx.at * 1000).toISOString()))}</span><br>
      <span class="small">${(d.pinned || []).length ? L('{r} van {t} agents hebben de nieuwe vingerafdruk.', { r: d.pinned.length - waiting.length, t: d.pinned.length }) : ''}
      ${waiting.length ? L('Nog niet: {names}.', { names: waiting.map((a) => esc(a.name)).join(', ') }) + ' ' : ''}${L('RzMon wisselt zodra alle agents hem hebben, uiterlijk {d}.', { d: new Date(nx.deadline * 1000).toLocaleDateString(LOCALE) })}</span>
      <div class="btn-row" style="margin-top:.4rem">${canRenew ? `<button type="button" class="btn small-btn" data-cert-act>🔄 ${L('Nu wisselen')}</button>` : ''}
        <button type="button" class="btn small-btn" data-cert-cancel>${L('Annuleren')}</button></div></div>` : '';
  modal({
    title: '🔐 ' + L('Certificaat van RzMon'),
    wide: true,
    body: `
      <p class="small" style="margin-top:0"><b>${L('Adres van de agents')}:</b> ${where}</p>
      ${d.agent_cert && !d.own ? certBlock('🌍 ' + L('Certificaat op het adres van de agents'), d.agent_cert) : ''}
      ${loc.ok === false ? `<div class="cert-box bad"><div class="cert-h">🚪 ${L('Eigen certificaat van RzMon (poort 443)')}</div><div class="small">✘ ${esc(loc.error || '')}</div></div>`
        : certBlock('🚪 ' + L('Eigen certificaat van RzMon (poort 443)'), loc, `<div class="muted small">${L('Voor agents via https op het IP-adres, en de nooddeur.')}</div>`, true)}
      ${pinRow}
      ${nextBox}
      ${d.hosts.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Agent')}</th><th>${L('Versie')}</th><th>📌 ${L('Certificaat')}</th><th>${L('Laatst gekregen')}</th></tr></thead><tbody>${hostRows}</tbody></table></div>` : ''}
      <h3 class="set-sub">🔄 ${L('Vernieuwen')}</h3>
      <p class="small muted" style="margin-top:0">${L('Een nieuw certificaat met een nieuwe sleutel, {n} jaar geldig. Agents met een vastgezet certificaat gaan vanzelf mee: ze krijgen de nieuwe vingerafdruk in een versleuteld antwoord. Je browser vraagt bij https op het IP-adres opnieuw om "toch doorgaan".', { n: Math.round(d.days / 365) })}</p>
      ${(d.pinned || []).length ? `<p class="small">📌 ${L('{n} agent(s) zetten het eigen certificaat vast. RzMon zet het nieuwe daarom eerst klaar: de agents krijgen de nieuwe vingerafdruk, en pas als ze die allemaal hebben (uiterlijk na {d} dagen) gaat RzMon het nieuwe gebruiken. Zo weigert geen enkele agent het certificaat.', { n: d.pinned.length, d: d.next_days })}</p>` : ''}
      <div class="small"><b>${L('Namen in het nieuwe certificaat')}:</b> <span class="cert-names">${d.names.map((n) => `<span class="lc">${esc(n)}</span>`).join('')}</span></div>
      <label class="small">${L('Extra namen of IP-adressen')} <span class="hint">${L('(één per regel; de rest gaat automatisch)')}</span>
        <textarea id="cert-extra" rows="2" spellcheck="false" placeholder="nas.thuis.lan">${esc(d.extra)}</textarea></label>
      ${!d.paths ? `<p class="small warn-txt">⚠️ ${L('Waar het certificaat staat, is niet bekend (geen installatie met install-rocky.sh). Zet ssl_cert en ssl_key in config.php.')}</p>`
        : d.via === 'cli' ? `<p class="small">${L('Vernieuwen kan hier alleen als root (de hulpdienst is niet geïnstalleerd). Op de server:')}</p>${copyBox('cmd-renew-cert', d.cli)}`
        : `<p class="small muted">${L('Bestanden')}: <code>${esc(d.paths.cert)}</code> · <code>${esc(d.paths.key)}</code>${d.via === 'helper' ? ' · ' + L('via de hulpdienst') : ''}</p>`}`,
    buttons: [
      { label: '⬇ ' + L('Downloaden (.crt)'), style: 'margin-right:auto', onClick: () => { if (loc.ok) agentCertDownload(); } },
      ...(canRenew ? [{ label: '🔄 ' + L('Vernieuwen'), cls: 'danger', onClick: () => agentCertRenew(d) }] : []),
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  $('#cert-extra').onchange = async (e) => {
    try { await api('agent_cert_names', { names: e.target.value }); savedFlag(e.target, true); agentCertDialog(); }
    catch (ex) { savedFlag(e.target, false, ex.message); toast(ex.message, 'err'); }
  };
  const act = $('[data-cert-act]');
  if (act) act.onclick = () => agentCertRenew(d, 'activate');
  const cancel = $('[data-cert-cancel]');
  if (cancel) cancel.onclick = async () => { try { await api('agent_cert_cancel', {}); toast(L('Geannuleerd')); agentCertDialog(); } catch (ex) { toast(ex.message, 'err'); } };
}

function agentCertDownload() {
  modal({
    title: '⬇ ' + L('Certificaat downloaden'),
    body: `<p style="margin-top:0" class="small">${L('Zet het certificaat in je browser of besturingssysteem als vertrouwd: dan geeft https op het IP-adres van RzMon geen waarschuwing meer.')}</p>${confirmField('DOWNLOADEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => agentCertDialog() }, { label: L('Downloaden'), cls: 'primary', submit: true }],
    onSubmit: async (f) => {
      const r = await api('agent_cert_download', { confirm: f.confirm });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([r.pem], { type: 'application/x-x509-ca-cert' }));
      a.download = r.name;
      document.body.append(a);
      a.click();
      a.remove();
      toast(L('Certificaat gedownload'));
      setTimeout(agentCertDialog, 50);
    },
  });
}

/** Vernieuwen (met vastgezette agents eerst klaarzetten, of meteen), of (what = 'activate') het klaargezette gaan gebruiken */
function agentCertRenew(d, what = 'renew') {
  const activate = what === 'activate';
  const pinned = (d.pinned || []).length > 0;
  const waiting = (d.pinned || []).filter((a) => !a.ready);
  modal({
    title: '🔄 ' + (activate ? L('Nieuw certificaat nu gebruiken?') : L('Certificaat vernieuwen?')),
    body: activate
      ? `<p style="margin-top:0" class="small">${waiting.length ? L('Deze agents hebben de nieuwe vingerafdruk nog niet: {names}. Ze weigeren het nieuwe certificaat één keer en halen de vingerafdruk dan versleuteld op (meestal via hun reserve-adres).', { names: waiting.map((a) => esc(a.name)).join(', ') })
        : L('Alle agents hebben de nieuwe vingerafdruk.')}</p>${confirmField('WISSELEN')}`
      : `<p style="margin-top:0" class="small">${L('RzMon maakt een nieuw certificaat voor: {names}. Het oude blijft als reservekopie naast het origineel staan; mislukt herladen, dan wordt het teruggezet.', { names: d.names.map(esc).join(', ') })}</p>
        ${pinned ? `<label class="switch small"><input type="checkbox" name="now"><span></span> ${L('Meteen wisselen (niet wachten tot de agents de nieuwe vingerafdruk hebben)')}</label>` : ''}${confirmField('VERNIEUWEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => agentCertDialog() }, { label: activate ? L('Nu wisselen') : L('Vernieuwen'), cls: 'danger', submit: true }],
    onSubmit: async (f) => {
      let r = await api(activate ? 'agent_cert_activate' : 'agent_cert_renew', { confirm: f.confirm, now: !!f.now });
      for (let i = 0; r.pending && i < 40; i++) {
        await new Promise((ok) => setTimeout(ok, 1500));
        r = await api('agent_cert_renew_status');
      }
      toast(r.prepared ? L('Nieuw certificaat klaargezet; de agents krijgen eerst de nieuwe vingerafdruk')
        : L('Nieuw certificaat, geldig tot {d}', { d: new Date(r.to * 1000).toLocaleDateString(LOCALE) }));
      setTimeout(agentCertDialog, 50);
    },
  });
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
// 1.120.0: nieuwe indeling, met lijn-iconen (zelfde stijl als de bovenbalk) en per onderdeel één korte zin.
// [groep, icoon, naam, [[sleutel, titel, kaarten, korte omschrijving, tabbladen?], …]]
const SET_IC = (d) => `<svg class="tab-ic set-ic" viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
const SET_NAV = [
  ['overzicht', SET_IC('<path d="M3 12h4l3 8 4-16 3 8h4"/>'), L('Overzicht'), [
    ['overview', L('Status'), ['overview'], L('Wat er nog aandacht nodig heeft, met een knop om het te regelen.')],
    ['conn', L('Achtergrondtaken'), ['conn'], L('Verbindingen en taken die RzMon elke minuut uitvoert.')],
    ['syscheck', L('Gezondheid'), ['syscheck'], L('Systeemcheck, database, snelheid en fouten van RzMon zelf.')]]],
  ['checks', SET_IC('<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 17.5h7M17.5 14v7"/>'), L('Monitoring'), [
    ['allchecks', L('Alle checks'), ['allchecks'], L('Alle checks van alle servers in één lijst, ook samen bewerken.')],
    ['templates', L('Sjablonen'), ['templates'], L('Vaste sets checks voor een nieuwe server.')],
    ['advice', L('Advies'), ['smart', 'advice', 'smartmore', 'cleanup'], L('Slimme drempels, voorstellen en checks om op te schonen.'),
      [['drempels', L('Slimme drempels'), ['smart']], ['voorstellen', L('Voorstellen'), ['advice', 'smartmore']], ['opschonen', L('Opschonen'), ['cleanup']]]]]],
  ['meldingen', SET_IC('<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'), L('Meldingen'), [
    ['channels', L('Kanalen'), ['pushover', 'email', 'webhook', 'chain'], L('Waar meldingen heen gaan: Pushover, e-mail en webhooks.')],
    ['rules', L('Wanneer melden'), ['notify', 'alarm'], L('Meldregels, stille uren en wanneer een probleem een melding wordt.')],
    ['sysinfo', L('Systeemmeldingen'), ['sysinfo'], L('Meldingen over RzMon zelf, zoals updates en certificaten.')]]],
  ['ids', SET_IC('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>'), L('IDS'), [
    ['ids', L('Algemeen'), ['ids'], L('Aan of uit, leren of blokkeren, brute force en honeypot.')],
    ['ids_edr', L('Endpoint-bewaking'), ['ids_edr'], L('Processen, bestanden en netwerk op je servers; afwijkingen en aanvalsketens.')],
    ['ids_ti', L('Dreigingsinformatie'), ['ids_ti'], L('Bekende aanvallers en malware opzoeken, en DNS en Suricata.')],
    ['ids_attack', L('Netwerk en aanvallers'), ['ids_attack'], L('Blocklists, landen, uitgaand verkeer en de logs van webdiensten.')],
    ['ids_servers', L('Per server'), ['ids_servers'], L('Welke servers het IDS gebruiken, en wat ze bewaken.')]]],
  ['beveiliging', SET_IC('<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'), L('Beveiliging'), [
    ['access', L('Inloggen'), ['access'], L('Lokaal netwerk, uitloggen en blokkeren na mislukte pogingen.')],
    ['outside', L('Beheer via https'), ['https', 'outside'], L('Veilig beheren van buiten je netwerk.')],
    ['users', L('Gebruikers'), ['users'], L('Accounts, rollen en tweestapsverificatie.')],
    ['vulns', L('Kwetsbaarheden'), ['vulns'], L('Bekende lekken in de software op je servers.')],
    ['hibp', L('Datalekken'), ['hibp'], L('Staan je e-mailadressen of domein in een datalek?')],
    ['netscan', L('Netwerkscan'), ['netscan'], L('Nieuwe apparaten en open poorten in je netwerk.')]]],
  ['agents', SET_IC('<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'), L('Agents'), [
    ['agents', L('Overzicht'), ['agents'], L('Alle agents: status, versie, verbinding en wat aandacht nodig heeft.')],
    ['agentdef', L('Standaardinstellingen'), ['agentdef', 'enc'], L('Wat voor alle agents geldt: bijwerken, ritme, versleuteling en certificaat.')],
    ['commands', L('Commando-checks'), ['commands'], L('Eigen commando\'s die agents mogen uitvoeren.')]]],
  ['koppelingen', SET_IC('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'), L('Koppelingen'), [
    ['netlinks', L('UniFi en AdGuard'), ['netlinks'], L('Apparaten en DNS uit je UniFi-controller en AdGuard Home.')],
    ['mqtt', L('Home Assistant (MQTT)'), ['mqtt'], L('Sensoren en meldingen in Home Assistant.')],
    ['api', L('REST API'), ['api'], L('API-sleutels voor andere toepassingen.')],
    ['statuspage', L('Statuspagina'), ['statuspage'], L('Een openbare pagina met de status, via een geheime link.')]]],
  ['systeem', SET_IC('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'), L('Systeem'), [
    ['update', L('Bijwerken'), ['update'], L('Nieuwe versies van RzMon, met of zonder automatisch bijwerken.')],
    ['backup', L('Back-up'), ['backup'], L('Automatische back-ups en terugzetten.')],
    ['db', L('Database en server'), ['dbconn', 'db', 'storage', 'server'], L('Databaseverbinding, bewaartermijnen, tijdzone en PHP.')],
    ['changelog', L('Wijzigingslogboek'), ['changelog'], L('Wie wat wijzigde, met terugdraaien.')],
    ['transfer', L('Exporteren en importeren'), ['transfer'], L('Servers, checks en instellingen overzetten.')]]],
];
/** Oude adressen (vóór 1.92.0) → nieuwe plek */
const SET_ALIAS = { enc: 'agentdef', https: 'outside', setup: 'syscheck', smart: 'advice', cleanup: 'advice', alarm: 'rules', notify: 'rules', server: 'db' };   // 1.120.0: samengevoegde onderdelen
const SET_TAB_OF = { smart: 'drempels', cleanup: 'opschonen' };   // oude adressen → tabblad op de pagina Advies
let setTab = {};   // per onderdeel met tabbladen: het gekozen tabblad
let setSection = (() => { try { return localStorage.getItem('rzmon-set-section') || 'overview'; } catch (e) { return 'overview'; } })();
let setHealthAt = 0;
let setHealth = {};   // onderdeel → slechtste status uit "Hoe staat het ervoor?" (voor de bolletjes in de zijbalk)

function setFind(id) {
  for (const [g, icon, label, subs] of SET_NAV) for (const s of subs) if (s[0] === id) return { g, icon, group: label, sub: s };
  return null;
}

function showSettingsSection(id, focusEl) {
  if (SET_TAB_OF[id]) setTab[SET_ALIAS[id]] = SET_TAB_OF[id];
  // Gevonden via zoeken in een kaart op een ander tabblad: dat tabblad kiezen
  const fk = focusEl && focusEl.closest('[data-sk]') && focusEl.closest('[data-sk]').dataset.sk;
  const f = setFind(SET_ALIAS[id] || id) || setFind('overview');
  setSection = f.sub[0];
  try { localStorage.setItem('rzmon-set-section', setSection); } catch (e) {}
  // 1.120.0: tabbladen binnen een onderdeel (bv. Advies: drempels, voorstellen, opschonen)
  const tabs = f.sub[4] || null;
  if (tabs && fk) { const t = tabs.find((x) => x[2].includes(fk)); if (t) setTab[setSection] = t[0]; }
  const tab = tabs ? (tabs.find((x) => x[0] === setTab[setSection]) || tabs[0]) : null;
  $('#set-tabs').hidden = !tabs;
  $('#set-tabs').innerHTML = tabs ? tabs.map(([k, l]) => `<button type="button" class="seg-btn ${k === tab[0] ? 'active' : ''}" data-set-tab="${k}" role="tab">${esc(l)}</button>`).join('') : '';
  const cards = tab ? tab[2] : f.sub[2];
  $$('#tab-settings [data-sk]').forEach((c) => c.classList.toggle('set-off', !cards.includes(c.dataset.sk)));
  // De knop "Instellingen opslaan" alleen bij onderdelen uit dat formulier
  const inForm = cards.some((k) => { const c = $(`#tab-settings [data-sk="${k}"]`); return c && c.closest('#settings-form'); });
  $('#settings-form').classList.toggle('set-noform', !inForm);
  $('#set-title').textContent = f.sub[1];
  $('#set-lead').textContent = f.sub[3] || '';
  $('#set-crumb').textContent = `${L('Instellingen')} › ${f.group}`;
  // Eén kaart op de pagina: zijn titel is gelijk aan de paginatitel, dus weg (tenzij er knoppen in staan)
  const shown = $$('#tab-settings [data-sk]').filter((c) => !c.classList.contains('set-off'));
  $$('#tab-settings [data-sk] > h2').forEach((h) => h.classList.toggle('set-dup', shown.length === 1 && shown[0] === h.parentElement && !h.querySelector('button, input, a')));
  renderSettingsNav();
  $('#set-mselect').innerHTML = SET_NAV.map(([g, icon, label, subs]) => `<optgroup label="${esc(label)}">${subs.map(([k, l]) => `<option value="${k}" ${k === setSection ? 'selected' : ''}>${esc(l)}</option>`).join('')}</optgroup>`).join('');
  history.replaceState(null, '', '#settings/' + setSection);
  if (setSection === 'overview' || !setHealthAt) loadSettingsHealth();
  if (setSection === 'conn') loadConn();
  if (setSection === 'templates') loadTemplates();
  if (setSection === 'allchecks') loadAllChecks();
  if (setSection === 'statuspage') loadStatusPage();
  if (setSection === 'advice') { if (cards.includes('smart')) loadSmartProposals(); if (cards.includes('advice')) loadAdvice(); if (cards.includes('cleanup')) loadCleanup(); }
  if (setSection === 'changelog') loadChangeLog();
  if (setSection === 'syscheck') loadSyscheck();
  if (setSection === 'db') loadSetupInfo();
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
  items.push({ key: 'https_client', status: https ? 'ok' : 'warn', title: 'HTTPS', detail: https ? L('de verbinding met RzMon is versleuteld') : L('je opent RzMon via http: wachtwoorden gaan onversleuteld over het netwerk'), section: 'access' });
  const rank = { bad: 0, warn: 1, info: 2, ok: 3 };
  items.sort((a, b) => rank[a.status] - rank[b.status]);
  setHealth = {};
  setHealthAt = Date.now();
  for (const it of items) {
    it.section = SET_ALIAS[it.section] || it.section;
    if (rank[it.status] < rank[setHealth[it.section] || 'ok']) setHealth[it.section] = it.status;
  }
  // 1.122.0: punten die bij elkaar horen als één regel (de losse punten eronder)
  items = ovGroup(items, rank);
  const counted = items.filter((i) => i.status !== 'info');
  const score = counted.length ? Math.round(counted.reduce((n, i) => n + (i.status === 'ok' ? 1 : i.status === 'warn' ? 0.5 : 0), 0) / counted.length * 100) : 100;
  const todo = items.filter((i) => i.status === 'bad' || i.status === 'warn').length;
  const cls = score >= 90 ? 'good' : score >= 60 ? 'warn' : 'bad';
  const icon = { ok: '✔', warn: '!', bad: '✘', info: 'i' };
  box.innerHTML = `<div class="ov-head"><div class="ov-score ${cls}" style="--p:${score}"><span><b>${score}<small>%</small></b></span></div>
      <div><h2>${todo ? L('{n} aandachtspunten', { n: todo }) : L('Alles staat goed ingesteld')}</h2>
      <p class="muted small">${L('Klik op een punt om het meteen te regelen. Grijze punten zijn optioneel en tellen niet mee.')}</p></div></div>
    <div class="ov-list">${items.map((i, n) => `<div class="ov-cell"><button type="button" class="ov-item ${i.status}" data-set-go="${esc(i.section)}">
      <span class="ov-ic">${icon[i.status]}</span><span class="ov-txt"><b>${esc(i.title)}</b><small>${esc(i.detail)}</small></span><span class="ov-go">›</span></button>
      ${i.subs ? `<ul class="ov-subs">${i.subs.filter((s) => s.status !== 'ok').map((s) => `<li class="${s.status}"><span class="ov-ic">${icon[s.status]}</span> <b>${esc(s.title)}</b> <span class="muted">${esc(s.detail)}</span></li>`).join('')}</ul>` : ''}
      ${i.code || i.acts ? `<div class="ov-extra">${i.code ? copyBox(`ov-code-${n}`, i.code) : ''}${(i.acts || []).map((a) => `<button type="button" class="btn small-btn primary" data-ov-act="${esc(a[0])}">${esc(a[1] || L('Regelen'))}</button>`).join('')}</div>` : ''}</div>`).join('')}</div>`;
  wireCopy(box);
  $$('[data-ov-act]', box).forEach((b) => (b.onclick = () => healthAction(b.dataset.ovAct, b)));
  renderSettingsNav();
}

/**
 * IDS-schakelaars op één plek (1.122.0): aan/uit staat op de kaarten van IDS → Bescherming. De drempels van een kaart
 * staan achter ⚙️ (zelfde velden als in de instellingen, uit het instellingenformulier gekopieerd).
 */
const PROT_CFG = {
  bruteforce: ['ids_fail_count', 'ids_fail_minutes', 'ids_block_hours', 'ids_repeat_days', 'ids_block_shared'],
  decoy: ['ids_decoy_users', 'ids_decoy_port'],
  anomaly: ['edr_learn_days', 'edr_anom_min'],
  chain: ['edr_chain_min'], chain_act: ['edr_chain_min'],
  ti_block: ['ti_block_score'], ti_report: ['ti_abuseipdb_key'],
  web_attack: ['wl_404_n', 'wl_auth_n', 'wl_nohost_n', 'wl_ha_n', 'wl_vw_n', 'wl_minutes'],
  outbound: ['ids_outbound_country'],
  blocklists: ['ids_geo', 'ids_geo_allowed'],
};
const PROT_KEYS = ['ti_escalate', 'ids_blocklists', 'ti_cve', 'edr_block', 'weblog_block', 'ti_close_benign', 'edr_kill', 'edr_isolate', 'edr_yara', 'ids_persist', 'edr_sigma', 'ids_harden',
  'ti_block', 'ids_outbound', 'suricata_block', 'dns_on', 'edr_anom', 'edr_chain_act', 'edr_chain', 'ti_report'];
async function protCfgDialog(id) {
  const names = PROT_CFG[id] || [];
  let vals;
  try { vals = await api('settings'); } catch (ex) { return toast(ex.message, 'err'); }
  // De velden zoals in de instellingen: hun label (of regel) kopiëren, zonder dubbele
  const seen = new Set();
  const parts = [];
  names.forEach((n) => {
    const el = $(`#settings-form [name="${n}"]`);
    const box = el && (el.closest('.lock-row') || el.closest('label'));
    if (!box || seen.has(box)) return;
    seen.add(box);
    const c = box.cloneNode(true);
    $$('.saved-flag', c).forEach((x) => x.remove());
    $$('[data-pol]', c).forEach((x) => x.removeAttribute('data-pol'));
    parts.push(c.outerHTML);
  });
  const card = ((protData && protData.cards) || []).find((c) => c.id === id) || {};
  modal({
    title: '⚙️ ' + (card.title || L('Drempels')),
    body: `<div class="prot-cfg-form">${parts.join('')}</div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d, form) => {
      const diff = {};
      names.forEach((n) => {
        const el = form.querySelector(`[name="${n}"]`);
        if (!el) return;
        const v = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        if (String(vals[n] ?? '') !== v) diff[n] = v;
      });
      if (Object.keys(diff).length) {
        await api('settings_save', diff);
        if (settingsSaved) Object.assign(settingsSaved, diff);
        Object.entries(diff).forEach(([n, v]) => { const el = $(`#settings-form [name="${n}"]`); if (el) { if (el.type === 'checkbox') el.checked = v === '1'; else el.value = v; } });
        toast(L('Opgeslagen'));
      }
      loadProt();
      return undefined;
    },
  });
  names.forEach((n) => {
    const el = $(`#modal-body [name="${n}"]`);
    if (!el) return;
    if (el.type === 'checkbox') el.checked = vals[n] === '1'; else el.value = vals[n] ?? '';
  });
}

/** Groepen op Status (1.122.0): alle punten over agents samen, en alles over https samen */
const OV_GROUPS = [
  ['agents_all', () => L('Agents'), 'agents', ['agents', 'enc', 'ownkey', 'agent_https', 'agent_https_only', 'agent_audit', 'agent_ownkey_only', 'agent_ip']],
  ['https_all', () => L('Beheer via https'), 'outside', ['https', 'https_client', 'cert', 'dns', 'proxy_leg', 'http_login', 'split_dns', 'mfa_ext']],
];
function ovGroup(items, rank) {
  items.forEach((i) => { if (i.act) i.acts = [[i.act, i.label]]; });
  let out = items;
  for (const [key, title, section, keys] of OV_GROUPS) {
    const subs = out.filter((i) => keys.includes(i.key));
    if (subs.length < 2) continue;
    const worst = subs.reduce((a, b) => (rank[b.status] < rank[a.status] ? b : a));
    const bad = subs.filter((s) => s.status === 'bad' || s.status === 'warn');
    const merged = { key, title: title(), section, status: worst.status, subs,
      detail: bad.length ? (bad.length === 1 ? L('1 punt') : L('{n} punten', { n: bad.length })) + ': ' + bad.map((s) => s.title).join(', ') : L('alles in orde ({n} punten)', { n: subs.length }),
      acts: subs.flatMap((s) => (s.status === 'ok' ? [] : s.acts || [])), code: subs.map((s) => (s.status === 'ok' ? '' : s.code || '')).find(Boolean) || '' };
    const at = out.indexOf(subs[0]);
    out = out.filter((i) => !keys.includes(i.key));
    out.splice(Math.min(at, out.length), 0, merged);
  }
  return out.sort((a, b) => rank[a.status] - rank[b.status]);
}

/** Knop bij een punt van "Hoe staat het ervoor?" (1.78.0) */
async function healthAction(act, b) {
  b.disabled = true;
  try {
    if (act === 'agent_https_only') {
      const c = (await api('security')).conn;
      await api('conn_save', { key_days: c.key_days, pin: c.pin, ip_lock: c.ip_lock, https_only: true });
      toast(L('Agents alleen via https: aan'));
    } else if (act === 'agent_ownkey_only') {
      const c = (await api('security')).conn;
      await api('conn_save', { key_days: c.key_days, pin: c.pin, ip_lock: c.ip_lock, https_only: c.https_only, ownkey_only: true });
      toast(L('Alleen agents met een eigen sleutel: aan'));
    } else if (act === 'agents_cleanup') {
      b.disabled = false;
      return agentsCleanupDialog(() => loadSettingsHealth());
    } else if (act === 'http_login_block') {
      const s = await api('https_info');
      await api('https_save', { admin_url: s.admin_url, https_only: s.https_only, http_login_block: true, mfa_external: s.mfa_external });
      toast(L('Inloggen via http wordt nu geweigerd'));
    }
    loadSettingsHealth();
  } catch (ex) { toast(ex.message, 'err'); b.disabled = false; }
}

// ---- Lange uitleg korter (1.120.0): een oranje ⓘ-blok, ingeklapt tot één regel; klik voor de hele tekst.
// Titels zonder emoji (de zijbalk en de bovenbalk hebben lijn-iconen). Ook voor wat later geladen wordt.
const EMOJI_LEAD = /^[\s\p{Extended_Pictographic}\u{FE0F}\u{200D}\u{20E3}]+/u;
function polishSettings(root) {
  $$('[data-sk] p.muted.small:not([id]):not([data-pol]), [data-sk] p.hint:not([id]):not([data-pol])', root).forEach((p) => {
    p.dataset.pol = '1';
    if (p.textContent.trim().length < 120 || p.closest('#set-overview, .ov-item, td, .kv, li')) return;
    const d = document.createElement('details');
    d.className = 'info-i';
    d.dataset.pol = '1';
    if (p.getAttribute('style')) d.setAttribute('style', p.getAttribute('style'));
    const s = document.createElement('summary');
    s.innerHTML = '<span class="info-ic" aria-hidden="true">i</span>';
    const t = document.createElement('span');
    t.className = 'info-txt';
    while (p.firstChild) t.append(p.firstChild);
    s.append(t);
    d.append(s);
    // Staat er al een ⓘ-blok vlak boven? Dan één blok met twee alinea's (niet een rij oranje blokken)
    const prev = p.previousElementSibling;
    if (prev && prev.classList.contains('info-i')) {
      const more = document.createElement('span');
      more.className = 'info-more';
      while (t.firstChild) more.append(t.firstChild);
      prev.querySelector('.info-txt').append(more);
      p.remove();
    } else p.replaceWith(d);
  });
  // Schakelaar met tekst in meerdere stukken (bv. met <b>): één geheel, anders zet de rij-opmaak de stukken door elkaar
  $$('[data-sk] label.switch:not([data-pol])', root).forEach((l) => {
    l.dataset.pol = '1';
    const sl = l.querySelector(':scope > input + span');
    if (!sl) return;
    const rest = [];
    for (let n = sl.nextSibling; n; n = n.nextSibling) rest.push(n);
    if (rest.filter((n) => n.nodeType === 1 || n.textContent.trim()).length <= 1 && !rest.some((n) => n.nodeType === 3 && n.textContent.trim())) return;
    const w = document.createElement('span');
    w.className = 'sw-txt';
    rest.forEach((n) => w.append(n));
    l.append(w);
  });
  // IDS: aan/uit van een onderdeel staat op IDS → Bescherming (1.122.0); hier niet nog een keer
  PROT_KEYS.forEach((k) => {
    const inp = $(`[data-sk^="ids"] label.switch > input[name="${k}"]`, root);
    const l = inp && inp.parentElement;
    if (!l || l.hidden) return;
    l.hidden = true;
    const nx = l.nextElementSibling;
    if (nx && (nx.classList.contains('info-i') || (nx.tagName === 'P' && nx.matches('.muted, .hint')))) nx.hidden = true;
  });
  // Een kopje waarvan alles eronder nu op Bescherming staat: ook weg
  $$('[data-sk^="ids"] h3.set-sub', root).forEach((h) => {
    let any = false;
    for (let n = h.nextElementSibling; n && !n.matches('h3.set-sub'); n = n.nextElementSibling) if (!n.hidden) { any = true; break; }
    h.hidden = !any;
  });
  $$('[data-sk^="ids"]:not([data-sk="ids_servers"]) > h2', root).forEach((h) => {
    if (h.nextElementSibling && h.nextElementSibling.classList.contains('prot-hint')) return;
    h.insertAdjacentHTML('afterend', `<div class="prot-hint">${L('Onderdelen aan- of uitzetten, en de drempels per onderdeel (⚙️), doe je bij <a href="#ids/prot" data-goto="ids/prot">IDS → Bescherming</a>. Hier staan de algemene instellingen.')}</div>`);
  });
  $$('[data-sk] h2:not([data-pol]), [data-sk] h3.set-sub:not([data-pol])', root).forEach((h) => {
    h.dataset.pol = '1';
    const n = h.firstChild;
    if (n && n.nodeType === 3) n.textContent = n.textContent.replace(EMOJI_LEAD, '');
  });
}
function initSettingsTips() {
  const tab = $('#tab-settings');
  polishSettings(tab);
  let t = null;
  new MutationObserver(() => { clearTimeout(t); t = setTimeout(() => polishSettings(tab), 30); }).observe(tab, { childList: true, subtree: true });
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
  $('#set-tabs').addEventListener('click', (e) => {
    const b = e.target.closest('[data-set-tab]');
    if (!b) return;
    setTab[setSection] = b.dataset.setTab;
    showSettingsSection(setSection);
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
    me = (await api('users')).users.find((u) => u.me);
    [devices, logins] = await Promise.all([api('sessions', null, { user_id: me.id }), api('my_logins')]);
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
  $('#acct-devices-body').innerHTML = `<p class="muted small" style="margin-top:0">${L('Waar je nu bent ingelogd. Een onthouden apparaat (📌) blijft ingelogd zonder wachtwoord en 2FA; na 30 dagen niet gebruiken, of als je je wachtwoord wijzigt, wordt het vergeten.')}</p>
    ${sessionRows(devices, false)}
    ${devices.some((d) => !d.current) ? `<button type="button" class="btn danger" id="acct-forget-all" style="margin-top:.6rem">${L('Alle andere apparaten uitloggen')}</button>` : ''}`;
  $('#acct-devices-body').onclick = async (e) => {
    const b = e.target.closest('[data-sess]');
    if (b) return sessionAction(b, loadAccount);
    try {
      if (e.target.closest('#acct-forget-all') && confirm(L('Alle andere apparaten uitloggen (en vergeten)? Dit apparaat blijft ingelogd.'))) {
        await api('sessions_logout_user', { user_id: me.id }); toast(L('Alle andere apparaten uitgelogd')); loadAccount();
      }
    } catch (ex) { toast(ex.message, 'err'); }
  };
  loadAccountOutside();
  $('#acct-logins-body').innerHTML = logins.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Wanneer')}</th><th>IP</th><th>${L('Hoe')}</th><th>${L('Apparaat')}</th></tr></thead><tbody>
    ${logins.map((x) => `<tr class="${+x.ok ? '' : 'bad-row'}"><td class="small">${esc(fmtDateTime(x.created_at))}</td><td class="mono small">${esc(x.ip)}</td>
      <td class="small">${+x.ok ? esc(L(x.how)) : `<span class="bad">✘ ${esc(L(x.how))}</span>`}</td><td class="small">${esc(x.device || '–')}</td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted" style="margin:0">${L('Nog geen logins bewaard.')}</p>`;
}

// ---------------------------------------------------------------- Van buiten: toegestane apparaten en passkeys (1.86.0)
/** Tabellen met apparaten en passkeys; admin: van iedereen (met de gebruiker erbij) */
function accessTables(devices, passkeys, admin, current = null) {
  const cc = (x) => (x ? ccTag(x) : '');
  const dev = devices.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr>${admin ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Apparaat')}</th><th>${L('Toegestaan')}</th><th>${L('Laatst gezien')}</th><th>${L('Passkeys')}</th><th></th></tr></thead><tbody>
    ${devices.map((d) => `<tr>${admin ? `<td>${esc(d.username)}</td>` : ''}<td>${devIcon(d.name)} <b>${esc(d.name)}</b>${d.current || +d.id === current ? ` <span class="badge">${L('dit apparaat')}</span>` : ''}</td>
      <td class="small">${esc(fmtDateTime(d.created_at))}<br><span class="muted mono">${esc(d.created_ip || '')}</span></td>
      <td class="small">${esc(ago(d.last_seen))}<br><span class="muted mono">${esc(d.last_ip || '')}</span>${cc(d.last_cc)}</td><td class="small">${+d.passkeys ? '🗝️ ' + d.passkeys : '–'}</td>
      <td class="nowrap"><button type="button" class="icon-btn" data-acc-ren="dev:${d.id}" title="${esc(L('Hernoemen'))}">✎</button> <button type="button" class="icon-btn" data-acc-del="dev:${d.id}" title="${esc(L('Intrekken: kan RzMon niet meer van buiten openen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Nog geen apparaten toegestaan.')}</p>`;
  const pk = passkeys.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr>${admin ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Passkey')}</th><th>${L('Domein')}</th><th>${L('Aangemaakt')}</th><th>${L('Laatst gebruikt')}</th><th></th></tr></thead><tbody>
    ${passkeys.map((p) => `<tr>${admin ? `<td>${esc(p.username)}</td>` : ''}<td>🗝️ <b>${esc(p.name)}</b>${p.device_name ? `<br><span class="muted small">${L('hoort bij {d}', { d: esc(p.device_name) })}</span>` : ''}</td>
      <td class="small mono">${esc(p.rp_id)}</td><td class="small">${esc(fmtDateTime(p.created_at))}</td><td class="small">${p.last_used ? esc(ago(p.last_used)) : L('nog niet')}</td>
      <td class="nowrap"><button type="button" class="icon-btn" data-acc-ren="pk:${p.id}" title="${esc(L('Hernoemen'))}">✎</button> <button type="button" class="icon-btn" data-acc-del="pk:${p.id}" title="${esc(L('Verwijderen'))}">✕</button></td></tr>`).join('')}</tbody></table></div>`
    : `<p class="muted small">${L('Nog geen passkeys.')}</p>`;
  return `<h4 class="acc-sub">📱 ${L('Toegestane apparaten')}</h4>${dev}<h4 class="acc-sub">🗝️ ${L('Passkeys')}</h4>${pk}`;
}

/** Hernoemen en intrekken in zo'n tabel; reload: daarna opnieuw laden */
async function accessAction(e, admin, reload) {
  const ren = e.target.closest('[data-acc-ren]'), del = e.target.closest('[data-acc-del]');
  const [kind, id] = ((ren || del)?.dataset.accRen || (ren || del)?.dataset.accDel || ':').split(':');
  if (!id) return;
  try {
    if (ren) {
      const name = prompt(L('Nieuwe naam'));
      if (!name) return;
      await api(kind === 'dev' ? 'device_rename' : 'passkey_rename', { id: +id, name, admin });
    } else {
      if (!confirm(kind === 'dev' ? L('Dit apparaat intrekken? Het kan RzMon dan niet meer van buiten openen; de passkeys van dit apparaat worden ook verwijderd.') : L('Deze passkey verwijderen?'))) return;
      await api(kind === 'dev' ? 'device_revoke' : 'passkey_delete', { id: +id, admin });
      toast(kind === 'dev' ? L('Apparaat ingetrokken') : L('Passkey verwijderd'));
    }
    reload();
  } catch (ex) { toast(ex.message, 'err'); }
}

/** Een passkey aanmaken op dit apparaat (na bevestigen met je wachtwoord) */
function passkeyCreate(done) {
  modal({
    title: '🗝️ ' + L('Passkey aanmaken'),
    body: `<p style="margin-top:0">${L('Je telefoon of computer maakt een sleutel die op het apparaat blijft. Daarna log je in met Face ID, je vingerafdruk of je pincode, zonder wachtwoord en 2FA-code. Wachtwoord en 2FA blijven werken als reserve.')}</p>
      <label>${L('Naam')}<input name="name" value="${esc(navigator.userAgent.includes('iPhone') ? 'iPhone' : navigator.userAgent.includes('Android') ? 'Android' : L('Deze computer'))}" maxlength="100"></label>
      ${confirmField('PASSKEY')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '🗝️ ' + L('Aanmaken'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const o = await api('passkey_reg_options', { confirm: d.confirm });
      let cred;
      try {
        cred = await navigator.credentials.create({ publicKey: { ...o, challenge: unb64u(o.challenge), user: { ...o.user, id: unb64u(o.user.id) },
          excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: unb64u(c.id) })) } });
      } catch (ex) {
        throw new Error(ex.name === 'InvalidStateError' ? L('Op dit apparaat staat al een passkey voor RzMon') : ex.name === 'NotAllowedError' ? L('Geannuleerd') : ex.message);
      }
      await api('passkey_reg_finish', { name: d.name, clientDataJSON: b64u(cred.response.clientDataJSON), attestationObject: b64u(cred.response.attestationObject) });
      toast(L('Passkey aangemaakt'));
      done();
    },
  });
}

async function loadAccountOutside() {
  const box = $('#acct-outside-body');
  if (!box) return;
  let a;
  try { a = await api('my_access'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const door = a.door_url ? (a.door_url.startsWith('/') ? location.origin + location.pathname.replace(/\/[^/]*$/, '') + a.door_url : a.door_url) : '';
  const isDev = a.current !== null;
  box.innerHTML = `<p class="muted small" style="margin-top:0">${a.ui_locked
      ? L('Van buiten je eigen netwerk opent RzMon alleen op een <b>toegestaan apparaat</b> (anders een gewone 404). Zet dit apparaat thuis op de lijst, en maak er een <b>passkey</b> bij: dan log je onderweg in met Face ID of je vingerafdruk.')
      : L('Een beheerder kan instellen dat RzMon van buiten alleen op <b>toegestane apparaten</b> opent. Je kunt je apparaten nu al op de lijst zetten, en een <b>passkey</b> maken om zonder wachtwoord in te loggen.')}</p>
    <div class="btn-row">
      ${isDev ? `<span class="good-txt">✔ ${L('Dit apparaat is toegestaan van buiten')}</span>`
        : a.can_trust ? `<button type="button" class="btn primary" id="acct-trust">📱 ${L('Dit apparaat ook van buiten toestaan')}</button>`
        : `<span class="muted small">${L('Dit apparaat toestaan kan alleen thuis (vanaf je eigen netwerk) of na een noodlink.')}</span>`}
      ${a.passkey_possible ? `<button type="button" class="btn" id="acct-passkey">🗝️ ${L('Passkey aanmaken')}</button>`
        : a.passkeys_enabled ? `<span class="muted small">${L('Passkeys werken alleen via https met een domeinnaam (bv. {rp}), niet via een IP-adres.', { rp: '<code>' + esc(a.rp_id) + '</code>' })}</span>` : ''}
    </div>
    ${accessTables(a.devices, a.passkeys, false, a.current)}
    ${a.door && door ? `<p class="muted small">🚪 ${L('Sleutel kwijt (browser gewist, nieuwe telefoon) terwijl je onderweg bent? Open dan {url}: je krijgt een 404, maar er gaat een <b>noodlink</b> naar je e-mailadres (en Pushover voor een beheerder). Die werkt één keer, 15 minuten. Bewaar dit adres.', { url: `<code class="sel-all">${esc(door)}</code>` })}</p>` : ''}`;
  if ($('#acct-trust')) $('#acct-trust').onclick = () => modal({
    title: '📱 ' + L('Dit apparaat toestaan van buiten'),
    body: `<p style="margin-top:0">${L('Dit apparaat krijgt een eigen sleutel (een cookie, 1 jaar). Daarmee opent RzMon ook van buiten je netwerk, alleen voor jouw account. Wis je de gegevens van je browser, dan moet je het opnieuw doen.')}</p>
      <label>${L('Naam')}<input name="name" value="${esc(navigator.userAgent.includes('iPhone') ? 'iPhone' : navigator.userAgent.includes('Android') ? 'Android' : L('Deze computer'))}" maxlength="100"></label>
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '📱 ' + L('Toestaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('device_trust', { name: d.name, confirm: d.confirm }); toast(L('Dit apparaat is toegestaan')); loadAccountOutside(); },
  });
  if ($('#acct-passkey')) $('#acct-passkey').onclick = () => passkeyCreate(loadAccountOutside);
  box.onclick = (e) => accessAction(e, false, loadAccountOutside);
}

async function loadStealth() {
  const box = $('#stealth-switches');
  if (!box) return;
  let s;
  try { s = await api('stealth_info'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const sw = (k, on, label, hint) => `<label class="switch"><input type="checkbox" data-stealth="${k}" ${on ? 'checked' : ''}><span></span> <span>${label}</span></label>${hint ? `<p class="muted small" style="margin:-.3rem 0 .6rem 2.6rem">${hint}</p>` : ''}`;
  box.innerHTML = sw('hide', s.hide, L('<b>Niet herkenbaar</b> van buiten'), L('Geen naam of versie, het agent-script alleen voor bekende agents of met de downloadsleutel, en geweigerd of onbekend krijgt een 404. Waarom iets geweigerd is, zie je in Meldingen.'))
    + sw('ui', s.ui, L('Webinterface van buiten alleen op <b>toegestane apparaten</b>'), L('Zonder toegestaan apparaat ook geen inlogscherm. Elk apparaat hoort bij één gebruiker. Zet eerst je eigen apparaten op de lijst (Mijn account).')
      + (s.ui && !s.devices_all.length ? ` <b class="warn-txt">${L('Er staat nog geen apparaat op de lijst: van buiten kan nu niemand in RzMon.')}</b>` : ''))
    + sw('countries', s.countries, L('Alleen vanuit je <b>normale landen</b> ({list})', { list: esc(s.allowed_countries.join(', ') || '–') }),
      (s.geo_data ? L('In te stellen bij <a href="#settings/ids_attack" data-goto="settings/ids_attack">IDS: aanvallers en verkeer</a>. Zonder landgegevens geldt dit niet.') : `<span class="warn-txt">${L('Er zijn nog geen landgegevens (<a href="#settings/ids_attack" data-goto="settings/ids_attack">IDS: aanvallers en verkeer</a>); tot dan geldt dit niet.')}</span>`))
    + sw('door', s.door, L('<b>Noodlink</b> als je sleutel kwijt is'), L('Via een vast adres (zie Mijn account) gaat een link naar je e-mail (en Pushover voor een beheerder), één keer te gebruiken, 15 minuten. Je krijgt altijd een melding als iemand hem aanvraagt.'))
    + sw('passkeys', s.passkeys, L('<b>Passkeys</b> (inloggen met Face ID, vingerafdruk of beveiligingssleutel)'), L('In plaats van wachtwoord en 2FA; die blijven de reserve. Alleen via https met een domeinnaam.'))
    + sw('requests', s.requests_on, L('<b>Geweigerde apparaten</b> bijhouden, om ze hier toe te staan'), L('Een browser uit je normale landen die van buiten een 404 krijgt, komt onder <i>Wachtende apparaten</i>. Hij krijgt dezelfde 404, plus een wachtende sleutel in een cookie; sta je hem toe, dan krijgt hij bij zijn volgende bezoek het inlogscherm. Scanners en bots tellen niet mee.'));
  renderDeviceRequests(s);
  box.onchange = async (e) => {
    const c = e.target.closest('[data-stealth]');
    if (!c) return;
    const body = { [c.dataset.stealth]: c.checked };
    const save = async (extra = {}) => { await api('stealth_save', { ...body, ...extra }); savedFlag(c, true); loadStealth(); };
    if (!c.checked && (c.dataset.stealth === 'hide' || c.dataset.stealth === 'ui')) {
      return modal({
        title: '⚠️ ' + L('Minder veilig maken?'),
        body: `<p style="margin-top:0">${c.dataset.stealth === 'ui' ? L('Van buiten is het inlogscherm dan weer voor iedereen te zien.') : L('Van buiten is dan weer te zien dat hier RzMon draait, en welke versie.')}</p>${confirmField('UITZETTEN')}`,
        buttons: [{ label: L('Annuleren'), onClick: () => { $('#modal').close(); loadStealth(); } }, { label: L('Uitzetten'), cls: 'danger', submit: true }],
        onSubmit: (d) => save({ confirm: d.confirm }),
      });
    }
    try { await save(); } catch (ex) { toast(ex.message, 'err'); loadStealth(); }
  };
  $('#stealth-devices').innerHTML = accessTables(s.devices_all, s.passkeys_all, true);
  $('#stealth-devices').onclick = (e) => accessAction(e, true, loadStealth);
}

/** Wachtende apparaten (1.102.0): van buiten geweigerd, toe te staan voor één gebruiker */
function renderDeviceRequests(s) {
  const box = $('#stealth-requests');
  if (!box) return;
  if (!s.ui || !s.requests_on) { box.innerHTML = ''; return; }
  const rows = s.requests || [];
  box.innerHTML = `<h3 class="set-sub">⏳ ${L('Wachtende apparaten')}${rows.length ? ` <span class="badge-root">${rows.length}</span>` : ''}</h3>`
    + (rows.length ? `<p class="muted small" style="margin-top:-.2rem">${L('Van buiten geweigerd (404). Sta een apparaat alleen toe als je weet van wie het is; inloggen (met 2FA) blijft daarna nodig.')}</p>
      <div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Apparaat')}</th><th>${L('IP-adres')}</th><th>${L('Eerst gezien')}</th><th>${L('Laatst gezien')}</th><th></th></tr></thead><tbody>
      ${rows.map((r) => `<tr><td>${devIcon(r.name)} <b>${esc(r.name)}</b>${+r.hits > 1 ? ` <span class="muted small">${r.hits}×</span>` : ''}</td>
        <td class="small"><span class="mono">${esc(r.last_ip)}</span>${r.last_cc ? ccTag(r.last_cc) : ''}${r.created_ip !== r.last_ip ? `<br><span class="muted">${L('eerst: {ip}', { ip: esc(r.created_ip) })}</span>` : ''}</td>
        <td class="small">${esc(fmtDateTime(r.created_at))}</td><td class="small">${esc(ago(r.last_seen))}</td>
        <td class="nowrap"><button type="button" class="btn small-btn" data-req-ok="${r.id}">✅ ${L('Toestaan')}</button>
          <button type="button" class="icon-btn" data-req-ign="${r.id}" title="${esc(L('Negeren: niet meer tonen en geen meldingen meer van dit apparaat'))}">🙈</button>
          <button type="button" class="icon-btn" data-req-block="${esc(r.last_ip)}" title="${esc(L('IP blokkeren'))}">⛔</button></td></tr>`).join('')}</tbody></table></div>`
      : `<p class="muted small" style="margin-top:-.2rem">${L('Geen wachtende apparaten. Krijgt een browser van buiten een 404, dan zie je hem hier.')}</p>`);
  box.onclick = async (e) => {
    const ok = e.target.closest('[data-req-ok]'), ign = e.target.closest('[data-req-ign]'), blk = e.target.closest('[data-req-block]');
    if (ok) return deviceRequestDialog(rows.find((r) => +r.id === +ok.dataset.reqOk), s.users);
    if (blk) return blockDialog(blk.dataset.reqBlock);
    if (ign) {
      try { await api('device_request_ignore', { id: +ign.dataset.reqIgn }); toast(L('Genegeerd')); loadStealth(); } catch (ex) { toast(ex.message, 'err'); }
    }
  };
}

function deviceRequestDialog(r, users) {
  if (!r) return;
  modal({
    title: '✅ ' + L('Apparaat toestaan'),
    body: `<p style="margin-top:0">${devIcon(r.name)} <b>${esc(r.name)}</b> · <span class="mono">${esc(r.last_ip)}</span>${r.last_cc ? ccTag(r.last_cc) : ''} · ${L('{n}× geprobeerd, laatst {t}', { n: r.hits, t: esc(ago(r.last_seen)) })}</p>
      <p class="muted small mono" style="word-break:break-all">${esc(r.ua)}</p>
      <div id="req-ipinfo"></div>
      <label>${L('Voor gebruiker')}<select name="user_id" required>${users.map((u) => `<option value="${u.id}">${esc(u.username)}</option>`).join('')}</select></label>
      <label>${L('Naam van het apparaat')}<input name="name" value="${esc(r.name)}" maxlength="100"></label>
      <p class="muted small">${L('Bij het volgende bezoek krijgt dit apparaat het inlogscherm, alleen voor deze gebruiker en met 2FA als die aan staat. Intrekken kan altijd hieronder.')}</p>
      ${confirmField('TOESTAAN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '✅ ' + L('Toestaan'), submit: true }],
    onSubmit: async (d) => { await api('device_request_approve', { id: +r.id, user_id: +d.user_id, name: d.name, confirm: d.confirm }); toast(L('Apparaat toegestaan')); loadStealth(); },
  });
  fillIpInfo($('#req-ipinfo'), r.last_ip);
}

/** Waar de agents de logs van SWAG, Home Assistant en Vaultwarden vonden (1.89.0) */
async function loadWeblogStatus() {
  const box = $('#weblog-status');
  if (!box) return;
  let rows;
  try { rows = await api('weblog_status'); } catch (ex) { return; }
  const names = { swag: 'SWAG', ha: 'Home Assistant', vw: 'Vaultwarden' };
  const found = rows.filter((r) => !r.err && r.path);
  box.innerHTML = (found.length ? found.map((r) => `✔ <b>${names[r.src] || esc(r.src)}</b> ${L('op {host}', { host: esc(r.host) })}: <code>${esc(r.path)}</code>`
      + (r.hint ? `<br><span class="warn-txt">⚠️ ${esc(r.hint)}</span>` : '')).join('<br>')
    : `<span class="muted">${L('Nog nergens gevonden (agent 1.58.0 nodig; het zoeken gebeurt bij het volgende rapport).')}</span>`)
    + ['swag', 'ha', 'vw'].filter((s) => !found.some((r) => r.src === s) && rows.some((r) => r.src === s)).map((s) => `<br><span class="muted">– ${names[s]}: ${L('op geen enkele server gevonden')}</span>`).join('');
}

async function loadSettings() {
  settingsLoaded = true;
  loadUsers();
  if (!isAdmin()) return;   // de rest van de instellingen is alleen voor beheerders
  loadApiKeys();
  loadStealth();
  loadWeblogStatus();
  loadSecurity();
  loadAccess();
  loadHttps();
  loadHibp();
  loadDbInfo();
  loadNetscanSettings();
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
  [L('Agents'), [['agent_update', '⬆ ' + L('Agent bijgewerkt')], ['agent_update_fail', '✘ ' + L('Agent bijwerken mislukt')], ['agent_connect', '🔌 ' + L('Agent verbonden')],
    ['agent_sec', '⚠️ ' + L('Agent verbindt niet veilig (en weer veilig)')]]],
  [L('Inloggen'), [['login_ok', '🔑 ' + L('Ingelogd')], ['login_fail', '❌ ' + L('Mislukte login')], ['server_login', '🖥️ ' + L('Login op een server, van binnen (SSH of console)')]]],
  [L('Blokkades'), [['account_lock', '🔒 ' + L('Account geblokkeerd')], ['account_unlock', '🔓 ' + L('Account gedeblokkeerd')],
    ['ip_block', '⛔ ' + L('IP geblokkeerd')], ['ip_unblock', '✅ ' + L('Blokkade opgeheven')]]],
  [L('Kwetsbaarheden'), [['vuln_new', '🐞 ' + L('Nieuwe kwetsbaarheden')], ['vuln_upgrade', '📦 ' + L('Pakketten bijgewerkt of mislukt')]]],
  [L('Beveiliging'), [['cert', '🔐 ' + L('Certificaat verloopt binnenkort')], ['breach', '🔓 ' + L('Datalek (zonder wachtwoorden of eerste controle)')],
    ['local_only', '🏠 ' + L('Inlogpoging van buiten op een lokaal account')], ['sec', '⚠️ ' + L('Niet-geautoriseerde verbinding')]]],
  [L('Onzichtbaar van buiten'), [['device_request', '⏳ ' + L('Geweigerd apparaat van buiten (wachtend)')], ['device_trust', '📱 ' + L('Apparaat toegestaan of ingetrokken')], ['passkey', '🔑 ' + L('Passkey aangemaakt of verwijderd')],
    ['stealth_door', '🚪 ' + L('Noodlink aangevraagd of gebruikt')]]],
  ['IDS', [['isolate', '🧱 ' + L('Server geïsoleerd of opgeheven')]]],
  [L('Netwerk'), [['net_new', '🆕 ' + L('Nieuw apparaat op het netwerk')]]],
  [L('Checks'), [['smart_drift', '🧠 ' + L('Normaalwaarde stijgt sluipend (slimme drempels)')], ['heal', '🩹 ' + L('Zelf hersteld (of herstel mislukt)')],
    ['watchdog', '🐕 ' + L('Waakhond: RzMon was onbereikbaar of de checks stonden stil')]]],
  ['RzMon', [['task_fail', '⚠️ ' + L('Achtergrondtaak lukt niet (en weer gelukt)')]]],
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
    const r = await api('password', Object.fromEntries(new FormData(e.target)));
    e.target.reset();
    toast(L('Wachtwoord gewijzigd'));
    pwnedWarn(r);
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Gebruikers & tweestapsverificatie
let users = [];
let usersHibp = false;   // datalek-controle staat aan (hoofdschakelaar)
const ROLE_LABEL = { admin: '🔑 ' + L('Beheerder'), user: '👤 ' + L('Gebruiker'), readonly: '👁 ' + L('Alleen lezen') };
async function loadUsers() {
  if ($('#tab-account') && !$('#tab-account').hidden) loadAccount();   // bv. na 2FA instellen vanaf Mijn account
  if ($('#users-card').hidden || !isAdmin()) return;
  const ur = await api('users');
  users = ur.users;
  usersHibp = ur.hibp;
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
        ${u.locked ? `<span class="locked-tag" title="${esc(L('Te veel mislukte pogingen vanaf {ip}; vanaf het lokale netwerk kan inloggen nog wel', { ip: u.locked_ip || '?' }))}">🔒 ${u.locked_until ? L('geblokkeerd tot {t}', { t: esc(fmtDateTime(u.locked_until)) }) : L('geblokkeerd')}</span>` : ''}
        ${u.local_only ? `<span class="u-tag local" title="${L('Mag alleen vanaf je interne netwerken inloggen (Toegang → Lokale netwerken)')}">🏠 ${L('alleen lokaal')}</span>` : ''}
        ${usersHibp && !u.hibp ? `<span class="u-tag" title="${L('Wachtwoord en e-mailadres van dit account worden niet gecontroleerd')}">🔕 ${L('geen datalek-controle')}</span>` : ''}
        ${usersHibp && u.hibp && +u.pw_pwned > 0 ? `<span class="u-tag bad" title="${L('Bij het inloggen moet eerst een nieuw wachtwoord worden gekozen')}">🔓 ${L('wachtwoord gelekt ({n}×)', { n: (+u.pw_pwned).toLocaleString(LOCALE) })}</span>` : ''}
        ${usersHibp && u.hibp && u.pw_pwned !== null && +u.pw_pwned === 0 ? `<span class="u-tag ok" title="${esc(L('Gecontroleerd {ago}', { ago: ago(u.pw_pwned_at) }))}">✔ ${L('wachtwoord niet gelekt')}</span>` : ''}<br>
        <span class="muted small">${u.email ? `✉ ${esc(u.email)}` : `<span title="${L('Nodig voor wachtwoord vergeten')}">✉ ${L('geen e-mailadres')}</span>`} · ${L('Laatst ingelogd: {ago}', { ago: u.last_login ? ago(u.last_login) : L('nooit') })}</span></span>
      <span class="row-actions">
        ${u.me ? `<button class="btn" data-uact="mine" data-id="${u.id}" title="${L('Je eigen wachtwoord, 2FA, e-mail en apparaten')}">👤 ${L('Mijn account')}</button>${userFlags(u)}` : `${u.totp
          ? `<button class="btn" data-uact="totp-reset" data-id="${u.id}" title="${L('Tweestapsverificatie uitzetten, bv. bij een verloren telefoon')}">${L('2FA resetten')}</button>`
          : `<button class="btn" data-uact="totp-setup" data-id="${u.id}">🔐 ${L('2FA instellen')}</button>`}
        ${u.locked ? `<button class="btn need-admin" data-uact="unlock" data-id="${u.id}">🔓 ${L('Deblokkeren')}</button> <a class="btn need-admin" href="#ids/blk" data-goto="ids/blk" title="${esc(L('Overzicht van alle blokkades, met de whitelist'))}">⛔ ${L('Blokkades')}</a>` : ''}
        ${u.me ? '' : `<select class="role-select need-admin" data-uact="role" data-id="${u.id}" title="${L('Rol wijzigen')}">
          ${Object.entries(ROLE_LABEL).map(([k, l]) => `<option value="${k}" ${u.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select>`}
        <button class="btn small-btn" data-uact="devices" data-id="${u.id}" title="${L('Ingelogde apparaten van {user}', { user: esc(u.username) })}">📱 ${+u.devices}</button>
        ${userFlags(u)}
        <button class="icon-btn" data-uact="email" data-id="${u.id}" title="${L('E-mailadres (voor wachtwoord vergeten)')}">✉</button>
        <button class="icon-btn need-admin" data-uact="password" data-id="${u.id}" title="${L('Nieuw wachtwoord instellen')}">🔑</button>
        <button class="icon-btn need-admin" data-uact="delete" data-id="${u.id}" title="${L('Verwijderen')}">🗑</button>`}
      </span>
    </div>`).join('');
  loadSessionsAll();
  settingsSummaries();
}

/** Per account: alleen lokaal (🏠) en de datalek-controle (🔓 aan, 🔕 uit); alleen beheerders */
function userFlags(u) {
  return `<button class="icon-btn need-admin flag-btn ${u.local_only ? 'on' : ''}" data-uact="flag-local" data-id="${u.id}" title="${esc(u.local_only ? L('Alleen lokaal: aan (klik om uit te zetten)') : L('Alleen vanaf de interne netwerken laten inloggen (bv. een noodaccount)'))}">🏠</button>`
    + (usersHibp ? `<button class="icon-btn need-admin flag-btn ${u.hibp ? 'on' : ''}" data-uact="flag-hibp" data-id="${u.id}" title="${esc(u.hibp ? L('Datalek-controle: aan (klik om uit te zetten)') : L('Datalek-controle: uit (klik om aan te zetten)'))}">${u.hibp ? '🔓' : '🔕'}</button>` : '');
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
    pwnedWarn(r);
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
      case 'flag-local':
        if (!u.local_only && !confirm(L('{user} mag dan alleen nog vanaf je interne netwerken inloggen (Toegang → Lokale netwerken). Doorgaan?', { user: u.username }))) return;
        await api('user_flags', { id: u.id, local_only: !u.local_only });
        toast(!u.local_only ? L('{user}: alleen lokaal', { user: u.username }) : L('{user} mag weer overal inloggen', { user: u.username }));
        return loadUsers();
      case 'flag-hibp':
        await api('user_flags', { id: u.id, hibp: !u.hibp });
        toast(!u.hibp ? L('Datalek-controle aan voor {user}', { user: u.username }) : L('Datalek-controle uit voor {user}', { user: u.username }));
        return loadUsers();
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
          onSubmit: async (d) => { const r = await api('user_password', { id: u.id, password: d.password }); toast(L('Wachtwoord gewijzigd')); pwnedWarn(r); loadUsers(); },
        });
    }
  } catch (ex) { toast(ex.message, 'err'); }
}

// ---------------------------------------------------------------- Ingelogde apparaten (1.79.0)
const SESS_HOW = {
  password: () => '🔑 ' + L('wachtwoord'), '2fa': () => '🔐 ' + L('wachtwoord + 2FA'), passkey: () => '🗝️ ' + L('passkey'), remember: () => '📌 ' + L('onthouden apparaat'),
  lan: () => '🏠 ' + L('LAN, zonder wachtwoord'), existing: () => L('al ingelogd'),
};
const devIcon = (d) => (/iPhone|Android/.test(d) ? '📱' : /iPad/.test(d) ? '📲' : '💻');

/** Tabel met ingelogde apparaten; withUser: met de gebruiker erbij (overzicht voor beheerders) */
function sessionRows(list, withUser) {
  if (!list.length) return `<p class="muted" style="margin:0">${L('Geen ingelogde apparaten.')}</p>`;
  return `<div class="table-scroll"><table class="vuln-table sess-table"><thead><tr>${withUser ? `<th>${L('Gebruiker')}</th>` : ''}<th>${L('Apparaat')}</th><th>IP</th><th>${L('Hoe')}</th><th>${L('Sinds')}</th><th>${L('Laatst actief')}</th><th></th></tr></thead><tbody>
    ${list.map((s) => `<tr class="${s.kind === 'device' ? 'sess-idle' : ''}">${withUser ? `<td><b>${esc(s.user_id === null ? L('lokaal netwerk') : s.username)}</b></td>` : ''}
      <td>${devIcon(s.device)} ${esc(s.device || L('Onbekend apparaat'))}${s.current ? ` <span class="badge">${L('dit apparaat')}</span>` : ''}
        ${s.kind === 'device' ? `<br><span class="muted small">💤 ${L('onthouden, nu niet actief')}</span>` : s.remembered ? ` <span title="${esc(L('Onthouden: blijft ingelogd zonder wachtwoord'))}">📌</span>` : ''}</td>
      <td class="mono small">${esc(s.ip)} <span title="${esc(s.internal ? L('Binnen je netwerk') : L('Van buiten je netwerk'))}">${s.internal ? '🏠' : '🌍'}</span></td>
      <td class="small">${SESS_HOW[s.how] ? SESS_HOW[s.how]() : esc(s.how)}</td>
      <td class="small">${esc(fmtDateTime(s.since))}</td><td class="small">${s.current ? L('nu') : ago(s.last)}</td>
      <td>${s.current ? '' : `<button type="button" class="btn small-btn" data-sess="${s.kind}:${s.id}">${s.kind === 'device' ? L('Vergeten') : L('Uitloggen')}</button>`}</td></tr>`).join('')}</tbody></table></div>`;
}

/** Uitloggen (sessie) of vergeten (onthouden apparaat zonder actieve sessie) */
async function sessionAction(btn, done) {
  const [kind, id] = btn.dataset.sess.split(':');
  btn.disabled = true;
  try {
    await api(kind === 'device' ? 'device_forget' : 'session_logout', { id: +id });
    toast(kind === 'device' ? L('Apparaat vergeten') : L('Uitgelogd'));
    done();
  } catch (ex) { toast(ex.message, 'err'); btn.disabled = false; }
}

/** Overzicht voor beheerders: alle ingelogde apparaten, te filteren op gebruiker */
let sessUser = '';
async function loadSessionsAll() {
  const box = $('#sess-all');
  if (!box || !isAdmin()) return;
  let r;
  try { r = await api('sessions_all'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const names = [...new Map(r.sessions.map((s) => [s.user_id === null ? 'lan' : String(s.user_id), s.user_id === null ? L('lokaal netwerk') : s.username])).entries()];
  if (sessUser && !names.some(([k]) => k === sessUser)) sessUser = '';
  $('#sess-user').innerHTML = `<option value="">${L('Alle gebruikers ({n})', { n: r.sessions.length })}</option>`
    + names.map(([k, n]) => `<option value="${k}" ${k === sessUser ? 'selected' : ''}>${esc(n)} (${r.sessions.filter((s) => (s.user_id === null ? 'lan' : String(s.user_id)) === k).length})</option>`).join('');
  const list = r.sessions.filter((s) => !sessUser || (s.user_id === null ? 'lan' : String(s.user_id)) === sessUser);
  box.innerHTML = sessionRows(list, true);
  $('#sess-user-out').hidden = !sessUser || sessUser === 'lan';
  $('#sess-user').onchange = (e) => { sessUser = e.target.value; loadSessionsAll(); };
  box.onclick = (e) => { const b = e.target.closest('[data-sess]'); if (b) sessionAction(b, () => { loadSessionsAll(); loadUsers(); }); };
  $('#sess-user-out').onclick = async () => {
    const n = names.find(([k]) => k === sessUser)?.[1] || '';
    if (!confirm(L('Alle apparaten van {user} uitloggen (en vergeten)?', { user: n }))) return;
    try { await api('sessions_logout_user', { user_id: +sessUser }); toast(L('Alles van {user} uitgelogd', { user: n })); loadSessionsAll(); loadUsers(); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  $('#sess-all-out').onclick = () => modal({
    title: '⛔ ' + L('Iedereen uitloggen?'),
    body: `<p style="margin-top:0" class="small">${L('Alle sessies van alle gebruikers worden uitgelogd en alle onthouden apparaten vergeten, behalve dit apparaat. Handig als je denkt dat er iemand meekijkt. Iedereen moet daarna opnieuw inloggen (met 2FA als dat aan staat).')}</p>${confirmField('UITLOGGEN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Iedereen uitloggen'), cls: 'danger', submit: true }],
    onSubmit: async (f) => { const x = await api('sessions_logout_all', { confirm: f.confirm }); toast(L('{n} sessies en apparaten uitgelogd', { n: x.n })); loadSessionsAll(); loadUsers(); },
  });
}

/** Ingelogde apparaten van één gebruiker: bekijken en uitloggen */
async function devicesDialog(u) {
  let list;
  try { list = await api('sessions', null, { user_id: u.id }); } catch (ex) { return toast(ex.message, 'err'); }
  modal({
    title: `📱 ${L('Ingelogde apparaten')} – ${u.username}`,
    wide: true,
    body: `<p class="muted small" style="margin-top:0">${L('Waar {user} nu is ingelogd. Een onthouden apparaat (📌) blijft ingelogd zonder wachtwoord en 2FA; het wordt vanzelf vergeten na 30 dagen niet gebruiken, en bij een wachtwoordwijziging.', { user: esc(u.username) })}</p>
      <div id="device-list">${sessionRows(list, false)}</div>`,
    buttons: [
      ...(list.some((d) => !d.current) ? [{ label: L('Alles uitloggen'), cls: 'danger', style: 'margin-right:auto', onClick: async () => {
        if (!confirm(L('Alle apparaten van {user} uitloggen (en vergeten)?', { user: u.username }))) return;
        try { await api('sessions_logout_user', { user_id: u.id }); toast(L('Alles van {user} uitgelogd', { user: u.username })); $('#modal').close(); loadUsers(); }
        catch (ex) { toast(ex.message, 'err'); }
      } }] : []),
      { label: L('Sluiten'), cls: 'primary', submit: true },
    ],
  });
  $('#device-list').addEventListener('click', (e) => {
    const b = e.target.closest('[data-sess]');
    if (b) sessionAction(b, () => { loadUsers(); devicesDialog(u); });
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
// ---------------------------------------------------------------- 💡 Nog niet bewaakt (1.95.0)
const DISCOVER_KIND = { disk: () => '💾 ' + L('Disks'), service: () => '⚙️ ' + L('Services'), container: () => '📦 ' + L('Containers'), cert: () => '🔒 ' + L('Certificaten'), port: () => '🌐 ' + L('Poorten') };
async function discoverDialog(h) {
  let d;
  try { d = await api('discover', null, { id: h.id }); } catch (ex) { return toast(ex.message, 'err'); }
  const groups = Object.keys(DISCOVER_KIND).map((k) => [k, d.items.filter((i) => i.kind === k)]).filter(([, l]) => l.length);
  modal({
    title: '💡 ' + L('Nog niet bewaakt – {name}', { name: h.name }),
    wide: true,
    body: `<p class="hint" style="margin-top:0">${L('Dit ziet de agent op de server, maar er is nog geen check voor. Vink aan wat je wilt bewaken; de rest kun je negeren, dan komt het niet meer terug.')}</p>
      ${groups.length ? groups.map(([k, list]) => `<h3 class="set-sub">${DISCOVER_KIND[k]()}</h3>
        ${list.map((i) => `<label class="switch"><input type="checkbox" data-dk="${esc(i.key)}" ${k === 'port' ? '' : 'checked'}><span></span> <span><b>${esc(i.label)}</b></span></label>`).join('')}`).join('')
        : `<p class="muted">${L('Alles wordt al bewaakt.')}</p>`}
      ${d.ignored ? `<p class="small muted">${L('{n} genegeerd.', { n: d.ignored })} <a href="#" id="dk-unignore">${L('Weer tonen')}</a></p>` : ''}`,
    buttons: groups.length ? [{ label: '🙈 ' + L('Gekozen negeren'), style: 'margin-right:auto', onClick: async () => {
      const keys = $$('#modal-body [data-dk]').filter((x) => x.checked).map((x) => x.dataset.dk);
      if (!keys.length) return toast(L('Niets gekozen'), 'err');
      await api('discover_ignore', { host_id: h.id, keys }); $('#modal').close(); toast(L('Genegeerd')); refresh();
    } }, { label: L('Checks toevoegen'), cls: 'primary', submit: true }] : [{ label: L('Sluiten'), cls: 'primary', submit: true }],
    onSubmit: groups.length ? async () => {
      const keys = new Set($$('#modal-body [data-dk]').filter((x) => x.checked).map((x) => x.dataset.dk));
      if (!keys.size) throw new Error(L('Niets gekozen'));
      let n = 0;
      for (const i of d.items.filter((x) => keys.has(x.key))) { await api('check_save', { host_id: h.id, enabled: true, interval_sec: 60, verify_ssl: true, ...i.data }); n++; }
      toast(n === 1 ? L('1 check toegevoegd') : L('{n} checks toegevoegd', { n }));
    } : null,
  });
  const un = $('#dk-unignore');
  if (un) un.onclick = async (e) => { e.preventDefault(); await api('discover_ignore', { host_id: h.id, keys: [], undo: true }); $('#modal').close(); refresh(); setTimeout(() => discoverDialog(findHost(h.id)), 300); };
}

// ---------------------------------------------------------------- 🧩 Sjablonen en alle checks (1.94.0)
let tplData = null;
const thrText = (c) => {
  const u = (TYPES[c.type] || {}).unit;
  return c.warn_threshold == null && c.crit_threshold == null ? '–'
    : `${c.warn_threshold == null ? '–' : dec(+c.warn_threshold)} / ${c.crit_threshold == null ? '–' : dec(+c.crit_threshold)}${u ? ' ' + esc(L(u)) : ''}`;
};
const typeLabel = (c) => esc((TYPES[c.type] || {}).label || c.type);

/** Keuze met twee knoppen: alleen loskoppelen (checks blijven) of ook de checks op de servers weghalen */
function keepOrDrop(title, text, onChoice, needConfirm = true) {
  modal({
    title,
    body: `<p style="margin-top:0">${text}</p><label class="switch"><input type="checkbox" name="drop"><span></span> ${L('Ook de checks op de servers verwijderen')}</label>
      <div id="kd-confirm" hidden>${needConfirm ? confirmField('VERWIJDEREN') : ''}</div>`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Doorgaan'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { await onChoice(!d.drop, d.confirm); },
  });
  const cb = $('#modal-body [name=drop]');
  cb.onchange = () => { $('#kd-confirm').hidden = !cb.checked; const i = $('#kd-confirm input'); if (i) i.required = cb.checked; };
  const i = $('#kd-confirm input'); if (i) i.required = false;
}

async function loadTemplates() {
  const box = $('#tpl-list');
  if (!box) return;
  try { tplData = await api('templates'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  renderTemplates();
}

function renderTemplates() {
  const box = $('#tpl-list');
  const d = tplData;
  box.innerHTML = d.templates.length ? d.templates.map((t) => {
    const linked = new Set(t.hosts.map((h) => h.id));
    return `<div class="tpl-block" data-tpl="${t.id}">
      <div class="tpl-head"><div class="grow"><h3>📋 ${esc(t.name)}</h3>${t.description ? `<p class="muted small">${esc(t.description)}</p>` : ''}</div>
        <button type="button" class="btn small-btn" data-tpl-edit="${t.id}" title="${esc(L('Naam en omschrijving'))}">✎</button>
        <button type="button" class="btn small-btn danger" data-tpl-del="${t.id}" title="${esc(L('Sjabloon verwijderen'))}">🗑</button></div>
      ${t.items.length ? `<div class="table-scroll"><table class="vuln-table"><thead><tr><th>${L('Check')}</th><th>${L('Type')}</th><th>${L('Doel')}</th><th>${L('Drempels')}</th><th>${L('Interval')}</th><th></th></tr></thead><tbody>
        ${t.items.map((c) => `<tr><td>${esc(c.name)}${+c.enabled ? '' : ` <span class="muted small">(${L('uit')})</span>`}</td><td class="small">${typeLabel(c)}</td>
          <td class="mono small">${esc(c.target || '–')}</td><td class="small nowrap">${thrText(c)}</td><td class="small nowrap">${esc(fmtInterval(c.interval_sec))}</td>
          <td class="nowrap"><button type="button" class="btn small-btn" data-ti-edit="${c.id}">✎</button> <button type="button" class="btn small-btn" data-ti-del="${c.id}">🗑</button></td></tr>`).join('')}
        </tbody></table></div>` : `<p class="muted small">${L('Nog geen checks in dit sjabloon.')}</p>`}
      <div class="btn-row"><button type="button" class="btn small-btn" data-ti-add="${t.id}">＋ ${L('Check toevoegen')}</button></div>
      <div class="tpl-hosts"><b class="small">${L('Servers')}:</b>
        ${t.hosts.map((h) => `<span class="tpl-chip">${esc(h.name)} <button type="button" class="icon-btn" data-tpl-unlink="${t.id}" data-host="${h.id}" title="${esc(L('Loskoppelen'))}">✕</button></span>`).join('') || `<span class="muted small">${L('nog geen')}</span>`}
        <select data-tpl-link="${t.id}"><option value="">＋ ${L('Server koppelen…')}</option>${d.hosts.filter((h) => !linked.has(+h.id)).map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select>
        ${t.overridden ? `<span class="muted small">· ${t.overridden === 1 ? L('1 check op een server zelf aangepast') : L('{n} checks op servers zelf aangepast', { n: t.overridden })}</span>` : ''}</div>
    </div>`;
  }).join('') : `<p class="muted">${L('Nog geen sjablonen.')}</p>`;
}

function tplNameDialog(t) {
  modal({
    title: t ? L('Sjabloon bewerken') : L('Nieuw sjabloon'),
    body: `<label>${L('Naam')}<input name="name" value="${esc(t ? t.name : '')}" required placeholder="${esc(L('bv. Linux-webserver'))}"></label>
      <label>${L('Omschrijving')} <span class="hint">${L('(optioneel)')}</span><input name="description" value="${esc(t ? t.description : '')}"></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: async (d) => { await api('template_save', { id: t ? t.id : 0, ...d }); toast(L('Opgeslagen')); setTimeout(loadTemplates, 50); },
  });
}

function tplFromHostDialog() {
  const hosts = (state.hosts || []).filter((h) => h.checks.length);
  const render = (hid) => {
    const h = findHost(+hid);
    $('#tfh-checks').innerHTML = h ? h.checks.filter((c) => !c.template_item_id).map((c) => `<label class="switch"><input type="checkbox" data-cid="${c.id}" checked><span></span> ${esc(c.name)} <span class="muted small">${typeLabel(c)}</span></label>`).join('')
      || `<p class="muted small">${L('Alle checks van deze server komen al uit een sjabloon.')}</p>` : '';
  };
  modal({
    title: L('Sjabloon maken van een server'),
    body: `<label>${L('Server')}<select name="host_id">${hosts.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('')}</select></label>
      <label>${L('Naam van het sjabloon')}<input name="name" required></label>
      <p class="hint">${L('Het adres van de server wordt in het sjabloon <code>{adres}</code>, zodat het op elke server werkt. Deze server wordt meteen gekoppeld.')}</p>
      <div id="tfh-checks"></div>`,
    buttons: [{ label: L('Maken'), cls: 'primary', submit: true }],
    onSubmit: async (d) => {
      const ids = $$('#tfh-checks [data-cid]').filter((x) => x.checked).map((x) => +x.dataset.cid);
      if (!ids.length) throw new Error(L('Kies minstens één check'));
      await api('template_from_host', { host_id: +d.host_id, name: d.name, check_ids: ids });
      toast(L('Sjabloon gemaakt')); setTimeout(loadTemplates, 50);
    },
  });
  const sel = $('#modal-body [name=host_id]');
  sel.onchange = () => { render(sel.value); const h = findHost(+sel.value); const n = $('#modal-body [name=name]'); if (h && !n.dataset.touched) n.value = h.name; };
  $('#modal-body [name=name]').oninput = (e) => { e.target.dataset.touched = 1; };
  sel.onchange();
}

function initTemplates() {
  if (!$('#tpl-list')) return;
  $('#tpl-new').onclick = () => tplNameDialog(null);
  $('#tpl-from-host').onclick = tplFromHostDialog;
  const box = $('#tpl-list');
  const tplOf = (id) => tplData.templates.find((t) => t.id === +id);
  const pseudoHost = (t) => ({ id: 0, name: t.name, address: '{adres}', mode: 'agent', metrics: [], snmp: true, checks: [] });
  box.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    try {
      if (b.dataset.tplEdit) return tplNameDialog(tplOf(b.dataset.tplEdit));
      if (b.dataset.tplDel) {
        const t = tplOf(b.dataset.tplDel);
        return keepOrDrop(L('Sjabloon "{name}" verwijderen', { name: t.name }), L('De checks die uit dit sjabloon komen, blijven op de servers staan (los van het sjabloon), tenzij je hieronder kiest om ze ook te verwijderen.'),
          async (keep, confirm) => { await api('template_delete', { id: t.id, keep, confirm }); toast(L('Sjabloon verwijderd')); setTimeout(loadTemplates, 50); });
      }
      if (b.dataset.tiAdd) {
        const t = tplOf(b.dataset.tiAdd);
        return checkForm(null, pseudoHost(t), {}, { id: t.id, name: t.name, onSaved: loadTemplates });
      }
      if (b.dataset.tiEdit) {
        const t = tplData.templates.find((x) => x.items.some((c) => c.id === +b.dataset.tiEdit));
        return checkForm(t.items.find((c) => c.id === +b.dataset.tiEdit), pseudoHost(t), {}, { id: t.id, name: t.name, onSaved: loadTemplates });
      }
      if (b.dataset.tiDel) {
        return keepOrDrop(L('Check uit het sjabloon halen'), L('De check verdwijnt uit het sjabloon. Op de servers blijft hij staan (los), tenzij je hieronder kiest om hem daar ook te verwijderen.'),
          async (keep) => { await api('template_item_delete', { id: +b.dataset.tiDel, keep }); toast(L('Verwijderd')); setTimeout(loadTemplates, 50); }, false);
      }
      if (b.dataset.tplUnlink) {
        return keepOrDrop(L('Server loskoppelen'), L('De server hoort niet meer bij dit sjabloon. Zijn checks blijven staan (los), tenzij je hieronder kiest om ze te verwijderen.'),
          async (keep) => { await api('template_unlink', { template_id: +b.dataset.tplUnlink, host_id: +b.dataset.host, keep }); toast(L('Losgekoppeld')); setTimeout(loadTemplates, 50); }, false);
      }
    } catch (ex) { toast(ex.message, 'err'); }
  });
  box.addEventListener('change', async (e) => {
    const sel = e.target.closest('[data-tpl-link]');
    if (!sel || !sel.value) return;
    try {
      const r = await api('template_link', { template_id: +sel.dataset.tplLink, host_id: +sel.value });
      toast(L('Gekoppeld: {c} nieuwe checks, {a} bestaande overgenomen', { c: r.created, a: r.adopted }));
      loadTemplates(); refresh();
    } catch (ex) { toast(ex.message, 'err'); sel.value = ''; }
  });
}

// ---- Alle checks: zoeken, aanvinken en meerdere tegelijk aanpassen
let acData = null;
const acSel = new Set();
async function loadAllChecks() {
  if (!$('#ac-list')) return;
  try { acData = await api('checks_all'); } catch (ex) { $('#ac-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const hs = $('#ac-host'), ts = $('#ac-type');
  const hv = hs.value, tv = ts.value;
  hs.innerHTML = `<option value="">${L('Alle servers')}</option>` + acData.hosts.map((h) => `<option value="${h.id}">${esc(h.name)}</option>`).join('');
  ts.innerHTML = `<option value="">${L('Alle types')}</option>` + [...new Set(acData.checks.map((c) => c.type))].sort().map((t) => `<option value="${t}">${esc((TYPES[t] || {}).label || t)}</option>`).join('');
  hs.value = hv; ts.value = tv;
  const ids = new Set(acData.checks.map((c) => +c.id));
  [...acSel].forEach((id) => { if (!ids.has(id)) acSel.delete(id); });
  renderAllChecks();
}

function acShown() {
  const q = setSearchNorm($('#ac-q').value), hv = $('#ac-host').value, tv = $('#ac-type').value;
  return acData.checks.filter((c) => (!hv || +c.host_id === +hv) && (!tv || c.type === tv)
    && (!q || setSearchNorm(`${c.name} ${c.target} ${c.host_name} ${c.template_name || ''}`).includes(q)));
}

function renderAllChecks() {
  const list = acShown();
  const all = list.length && list.every((c) => acSel.has(+c.id));
  $('#ac-list').innerHTML = list.length ? `<div class="table-scroll"><table class="vuln-table ac-table"><thead><tr>
      <th><input type="checkbox" id="ac-all" ${all ? 'checked' : ''} aria-label="${esc(L('Alles aanvinken'))}"></th><th>${L('Server')}</th><th>${L('Check')}</th><th>${L('Type')}</th>
      <th>${L('Drempels')}</th><th>${L('Interval')}</th><th>${L('Status')}</th><th>${L('Sjabloon')}</th></tr></thead><tbody>
    ${list.map((c) => `<tr class="${+c.enabled ? '' : 'muted'}"><td><input type="checkbox" data-ac="${c.id}" ${acSel.has(+c.id) ? 'checked' : ''}></td>
      <td class="small">${esc(c.host_name)}</td><td><a href="#" data-ac-edit="${c.id}">${esc(c.name)}</a>${+c.enabled ? '' : ` <span class="small">(${L('uit')})</span>`}
        ${c.maintenance_until && new Date(c.maintenance_until.replace(' ', 'T')) > new Date() ? ' 🔧' : ''}${c.target ? `<div class="mono small muted">${esc(c.target)}</div>` : ''}</td>
      <td class="small">${typeLabel(c)}</td><td class="small nowrap">${thrText(c)}</td><td class="small nowrap">${esc(fmtInterval(c.interval_sec))}</td>
      <td class="small">${ST_ICON[+c.status] || ''} ${esc(STATUS[+c.status] || '')}</td>
      <td class="small">${c.template_name ? `📋 ${esc(c.template_name)}${+c.tpl_override ? ` <span class="muted">(${L('aangepast')})</span>` : ''}` : '–'}</td></tr>`).join('')}
    </tbody></table></div>` : `<p class="muted">${L('Niets gevonden.')}</p>`;
  acBulkBar();
}

function acBulkBar() {
  const bar = $('#ac-bulk');
  const n = acSel.size;
  bar.hidden = !n;
  if (!n) return;
  const chosen = acData.checks.filter((c) => acSel.has(+c.id));
  const oneType = new Set(chosen.map((c) => `${c.type}|${c.metric || ''}`)).size === 1;
  bar.innerHTML = `<b>${n === 1 ? L('1 check gekozen') : L('{n} checks gekozen', { n })}</b>
    <button type="button" class="btn small-btn" data-bulk="interval">⏱ ${L('Interval')}</button>
    <button type="button" class="btn small-btn" data-bulk="thr" ${oneType ? '' : `disabled title="${esc(L('Alleen voor checks van hetzelfde type'))}"`}>🎚 ${L('Drempels')}</button>
    <button type="button" class="btn small-btn" data-bulk="smart">🧠 ${L('Slim')}</button>
    <button type="button" class="btn small-btn" data-bulk="notify">🔔 ${L('Melden')}</button>
    <button type="button" class="btn small-btn" data-bulk="maint">🔧 ${L('Onderhoud')}</button>
    <button type="button" class="btn small-btn" data-bulk="on">▶ ${L('Aan')}</button>
    <button type="button" class="btn small-btn" data-bulk="off">⏸ ${L('Uit')}</button>
    <button type="button" class="btn small-btn" data-bulk="copy">📋 ${L('Kopiëren naar…')}</button>
    <button type="button" class="btn small-btn danger" data-bulk="delete">🗑 ${L('Verwijderen')}</button>
    <button type="button" class="btn small-btn" data-bulk="none">✕ ${L('Niets kiezen')}</button>
    ${chosen.some((c) => c.template_item_id) ? `<span class="muted small">${L('Checks uit een sjabloon gaan na aanpassen niet meer mee met het sjabloon.')}</span>` : ''}`;
}

async function acBulk(kind) {
  const ids = [...acSel];
  const chosen = acData.checks.filter((c) => acSel.has(+c.id));
  const done = (r) => { toast(L('{n} checks bijgewerkt', { n: r.n })); setTimeout(() => { loadAllChecks(); refresh(); }, 50); };
  const set = (values) => api('checks_bulk', { ids, action: 'set', values }).then(done);
  if (kind === 'none') { acSel.clear(); return renderAllChecks(); }
  if (kind === 'on' || kind === 'off') return set({ enabled: kind === 'on' }).catch((ex) => toast(ex.message, 'err'));
  if (kind === 'interval') return modal({ title: L('Interval voor {n} checks', { n: ids.length }),
    body: `<label>${L('Interval')}<select name="interval_sec">${intervalOptions(chosen[0].interval_sec)}</select></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }], onSubmit: (d) => set({ interval_sec: +d.interval_sec }) });
  if (kind === 'thr') {
    const t = TYPES[chosen[0].type] || {};
    return modal({ title: L('Drempels voor {n} checks', { n: ids.length }),
      body: `<div class="row2"><label>${L('Warning')}${t.unit ? ` (${esc(L(t.unit))})` : ''}<input name="warn" type="number" step="any" value="${chosen[0].warn_threshold ?? ''}"></label>
        <label>${L('Critical')}${t.unit ? ` (${esc(L(t.unit))})` : ''}<input name="crit" type="number" step="any" value="${chosen[0].crit_threshold ?? ''}"></label></div>
        <p class="hint">${L('Leeg = geen drempel.')}</p>`,
      buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }], onSubmit: (d) => set({ warn_threshold: d.warn, crit_threshold: d.crit }) });
  }
  if (kind === 'smart') return modal({ title: L('Drempels voor {n} checks', { n: ids.length }),
    body: `<label>${L('Drempels')}<select name="mode"><option value="">${L('ongewijzigd')}</option>${Object.entries(SMART_MODES).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
      <label>${L('Gevoeligheid')}<select name="sens"><option value="-">${L('ongewijzigd')}</option><option value="">${L('standaard')}</option>${Object.entries(SMART_SENS).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
      <p class="hint">${L('Alleen voor checks die kunnen leren (ping, TCP, HTTP, CPU, RAM, SNMP); de andere blijven zoals ze zijn. Bij slim met vangnet krijgt een check zonder vangnet het standaard vangnet.')}</p>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => set({ ...(d.mode ? { thresh_mode: d.mode } : {}), ...(d.sens !== '-' ? { smart_sens: d.sens || null } : {}) }) });
  if (kind === 'notify') return modal({ title: L('Melden voor {n} checks', { n: ids.length }),
    body: `<label>${L('Melden na zoveel mislukte checks achter elkaar')} <span class="hint">${L('(leeg = de algemene instelling)')}</span><input name="fails" type="number" min="1" max="100"></label>
      <label class="switch"><input type="checkbox" name="defaults"><span></span> ${L('Eigen meldregels weghalen: de algemene meldregels gebruiken')}</label>
      <label>${L('Alleen info (telt nooit als storing)')}<select name="info"><option value="">${L('ongewijzigd')}</option><option value="1">${L('aan')}</option><option value="0">${L('uit')}</option></select></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => set({ notify_fails: d.fails === '' ? null : +d.fails, ...(d.info !== '' ? { info_only: d.info === '1' } : {}), ...(d.defaults ? { notify_default: true } : {}) }) });
  if (kind === 'maint') return modal({ title: L('Onderhoud voor {n} checks', { n: ids.length }),
    body: `<label>${L('Hoe lang')}<select name="minutes">${[[60, L('1 uur')], [240, L('4 uur')], [1440, L('1 dag')], [10080, L('1 week')], [0, L('Onderhoud beëindigen')]].map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></label>
      <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason"></label>`,
    buttons: [{ label: L('Opslaan'), cls: 'primary', submit: true }],
    onSubmit: (d) => api('checks_bulk', { ids, action: 'maintenance', minutes: +d.minutes, reason: d.reason }).then(done) });
  if (kind === 'copy') return modal({ title: L('{n} checks kopiëren naar…', { n: ids.length }),
    body: `<p class="hint" style="margin-top:0">${L('Elke gekozen server krijgt een kopie (los, niet uit een sjabloon). Staat het adres van de oorspronkelijke server in het doel, dan wordt dat het adres van de nieuwe server.')}</p>
      ${acData.hosts.map((h) => `<label class="switch"><input type="checkbox" data-copy-to="${h.id}"><span></span> ${esc(h.name)}</label>`).join('')}`,
    buttons: [{ label: L('Kopiëren'), cls: 'primary', submit: true }],
    onSubmit: async () => {
      const to = $$('#modal-body [data-copy-to]').filter((x) => x.checked).map((x) => +x.dataset.copyTo);
      if (!to.length) throw new Error(L('Kies minstens één server'));
      const r = await api('checks_bulk', { ids, action: 'copy', host_ids: to });
      toast(L('{n} checks gekopieerd', { n: r.n })); setTimeout(() => { loadAllChecks(); refresh(); }, 50);
    } });
  if (kind === 'delete') return modal({ title: L('{n} checks verwijderen', { n: ids.length }),
    body: `<p style="margin-top:0">${L('De checks en hun geschiedenis worden verwijderd. Checks uit een sjabloon komen niet terug, tenzij je de server opnieuw koppelt.')}</p>${confirmField('VERWIJDEREN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Verwijderen'), cls: 'danger', submit: true }],
    onSubmit: async (d) => { const r = await api('checks_bulk', { ids, action: 'delete', confirm: d.confirm }); acSel.clear(); toast(L('{n} checks verwijderd', { n: r.n })); setTimeout(() => { loadAllChecks(); refresh(); }, 50); } });
}

function initAllChecks() {
  if (!$('#ac-list')) return;
  ['#ac-q', '#ac-host', '#ac-type'].forEach((s) => { $(s).addEventListener(s === '#ac-q' ? 'input' : 'change', renderAllChecks); });
  $('#ac-list').addEventListener('change', (e) => {
    if (e.target.id === 'ac-all') { acShown().forEach((c) => (e.target.checked ? acSel.add(+c.id) : acSel.delete(+c.id))); return renderAllChecks(); }
    const cb = e.target.closest('[data-ac]');
    if (cb) { cb.checked ? acSel.add(+cb.dataset.ac) : acSel.delete(+cb.dataset.ac); acBulkBar(); }
  });
  $('#ac-list').addEventListener('click', (e) => {
    const a = e.target.closest('[data-ac-edit]');
    if (!a) return;
    e.preventDefault();
    const c = acData.checks.find((x) => +x.id === +a.dataset.acEdit);
    const h = findHost(+c.host_id);
    const full = h && h.checks.find((x) => +x.id === +c.id);
    if (full) checkForm(full, h);
  });
  $('#ac-bulk').addEventListener('click', (e) => { const b = e.target.closest('[data-bulk]'); if (b) acBulk(b.dataset.bulk); });
  // Na een venster (check bewerkt, sjabloon aangepast): de lijst bijwerken
  $('#modal').addEventListener('close', () => {
    if (setSection === 'allchecks' && !$('#tab-settings').hidden) setTimeout(loadAllChecks, 300);
  });
}

// ---- 🧹 Opschoonadvies (1.96.0)
const CLEANUP_KIND = { unknown: () => '❔ ' + L('Al dagen onbekend'), noisy: () => '📣 ' + L('Ruismakers'), flap: () => '🔁 ' + L('Wisselt steeds'),
  warn: () => '🟠 ' + L('Al lang in warning'), tight: () => '🎚 ' + L('Net onder de drempel'), off: () => '⏸ ' + L('Al lang uit') };
let cleanupData = null;
async function loadCleanup() {
  const box = $('#cleanup-list');
  if (!box) return;
  $('#cleanup-reload').onclick = loadCleanup;
  try { cleanupData = (await api('cleanup')).items; } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const groups = Object.keys(CLEANUP_KIND).map((k) => [k, cleanupData.filter((i) => i.kind === k)]).filter(([, l]) => l.length);
  const fixBtn = (i) => ({ fails3: `<button type="button" class="btn small-btn" data-cl-fix="fails3" data-id="${i.check_id}">${L('Melden na 3')}</button>`,
    disable: `<button type="button" class="btn small-btn" data-cl-fix="disable" data-id="${i.check_id}">⏸ ${L('Uitzetten')}</button>`,
    delete: `<button type="button" class="btn small-btn danger" data-cl-fix="delete" data-id="${i.check_id}">🗑 ${L('Verwijderen')}</button>` })[i.fix] || '';
  box.innerHTML = groups.length ? groups.map(([k, list]) => `<h3 class="set-sub">${CLEANUP_KIND[k]()} <span class="muted small">${list.length}</span></h3>
    ${list.map((i) => `<div class="cl-row"><div class="grow"><b>${esc(i.host)} · <a href="#" data-cl-edit="${i.check_id}">${esc(i.name)}</a></b> <span class="muted small">${esc((TYPES[i.type] || {}).label || i.type)}</span>
        <div class="small">${esc(i.detail)}</div><div class="muted small">💡 ${esc(i.advice)}</div></div>
      <div class="cl-act">${fixBtn(i)} <button type="button" class="btn small-btn" data-cl-ign="${esc(i.kind)}" data-id="${i.check_id}" title="${esc(L('Dit is normaal: niet meer tonen'))}">🙈</button></div></div>`).join('')}`).join('')
    : `<p class="good-txt">✔ ${L('Niets op te ruimen. Alle checks zien er gezond uit.')}</p>`;
  box.onclick = async (e) => {
    const ed = e.target.closest('[data-cl-edit]');
    if (ed) { e.preventDefault(); const h = state.hosts.find((x) => x.checks.some((c) => +c.id === +ed.dataset.clEdit)); if (h) checkForm(h.checks.find((c) => +c.id === +ed.dataset.clEdit), h); return; }
    const b = e.target.closest('[data-cl-fix], [data-cl-ign]');
    if (!b) return;
    const id = +b.dataset.id;
    try {
      if (b.dataset.clIgn) { await api('cleanup_ignore', { check_id: id, kind: b.dataset.clIgn }); toast(L('Wordt niet meer getoond')); }
      else if (b.dataset.clFix === 'fails3') { await api('checks_bulk', { ids: [id], action: 'set', values: { notify_fails: 3 } }); toast(L('Meldt nu pas na 3 mislukte checks')); }
      else if (b.dataset.clFix === 'disable') { await api('checks_bulk', { ids: [id], action: 'set', values: { enabled: false } }); toast(L('Uitgezet')); }
      else if (b.dataset.clFix === 'delete') {
        return modal({ title: L('Check verwijderen'), body: confirmField('VERWIJDEREN'), buttons: [{ label: L('Verwijderen'), cls: 'danger', submit: true }],
          onSubmit: async (d) => { await api('checks_bulk', { ids: [id], action: 'delete', confirm: d.confirm }); toast(L('Check verwijderd')); setTimeout(loadCleanup, 50); } });
      }
      loadCleanup(); refresh();
    } catch (ex) { toast(ex.message, 'err'); }
  };
}

// ---------------------------------------------------------------- 📜 Wijzigingslogboek, 📦 export/import, 🧪 meldketen (1.97.0)
const CL_KIND = { setting: () => '⚙️ ' + L('Instelling'), host: () => '🖥️ ' + L('Server'), check: () => '✔ ' + L('Check'), template: () => '📋 ' + L('Sjabloon'), system: () => '🛠️ ' + L('Systeem') };
const CL_ACTION = { create: () => L('aangemaakt'), update: () => L('gewijzigd'), delete: () => L('verwijderd'), undo: () => L('teruggezet'), smart: () => L('geleerd'), info: () => '' };
async function loadChangeLog() {
  const box = $('#cl-list');
  if (!box) return;
  let d;
  try { d = await api('changelog', null, { kind: $('#cl-kind').value }); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  $('#cl-days').value = String(d.days);
  const val = (v) => (v === null ? `<span class="muted">${L('leeg')}</span>` : `<code>${esc(v)}</code>`);
  box.innerHTML = d.items.length ? d.items.map((i) => `<div class="cl-row${i.undone_at ? ' muted' : ''}"><div class="grow">
      <b>${(CL_KIND[i.kind] || (() => i.kind))()}</b> ${esc(i.label)} <span class="muted small">${esc((CL_ACTION[i.action] || (() => i.action))())}</span>
      <div class="muted small">${esc(fmtDateTime(i.at))} · ${esc(i.username)} · ${esc(i.ip)}${i.undone_at ? ' · ' + L('teruggezet op {t}', { t: esc(fmtDateTime(i.undone_at)) }) : ''}</div>
      ${i.changes.length ? `<details class="small"><summary>${i.changes.length === 1 ? L('1 wijziging') : L('{n} wijzigingen', { n: i.changes.length })}</summary>
        <table class="kv">${i.changes.map(([k, o, n]) => `<tr><th class="mono">${esc(k)}</th><td>${val(o)} → ${val(n)}</td></tr>`).join('')}</table></details>` : ''}</div>
      <div class="cl-act">${i.can_undo ? `<button type="button" class="btn small-btn" data-undo="${i.id}" title="${esc(L('Terugzetten naar hoe het was'))}">↩ ${L('Terugzetten')}</button>` : ''}</div></div>`).join('')
    : `<p class="muted">${L('Nog niets.')}</p>`;
}

function initSystemTools() {
  if ($('#cl-list')) {
    $('#cl-reload').onclick = loadChangeLog;
    $('#cl-kind').onchange = loadChangeLog;
    $('#cl-days').onchange = async (e) => { try { await api('changelog_days', { days: +e.target.value }); toast(L('Opgeslagen')); } catch (ex) { toast(ex.message, 'err'); } };
    $('#cl-list').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-undo]');
      if (!b || !confirm(L('Deze wijziging terugzetten?'))) return;
      try { const r = await api('changelog_undo', { id: +b.dataset.undo }); toast(r.message); loadChangeLog(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
    });
  }
  if ($('#tr-export')) {
    $('#tr-export').onclick = () => { location.href = 'api.php?' + new URLSearchParams({ action: 'export', settings: $('#tr-settings').checked ? '1' : '0' }); };
    $('#tr-import').onclick = () => $('#tr-file').click();
    $('#tr-file').onchange = async (e) => {
      const f = e.target.files[0];
      e.target.value = '';
      if (!f) return;
      let data;
      try { data = JSON.parse(await f.text()); } catch (ex) { return toast(L('Dit is geen geldig JSON-bestand'), 'err'); }
      const withSettings = $('#tr-settings').checked;
      let pre;
      try { pre = (await api('import', { data, settings: withSettings, apply: false })).counts; } catch (ex) { return toast(ex.message, 'err'); }
      modal({
        title: '⬆ ' + L('Importeren: {f}', { f: f.name }),
        body: `<p style="margin-top:0">${L('Dit gebeurt er:')}</p><table class="kv">
          <tr><th>${L('Servers')}</th><td>${L('{a} nieuw, {b} bestaan al (blijven zoals ze zijn)', { a: pre.hosts_new, b: pre.hosts_existing })}</td></tr>
          <tr><th>${L('Sjablonen')}</th><td>${L('{a} nieuw, {b} bestaan al (blijven zoals ze zijn)', { a: pre.templates_new, b: pre.templates_existing })}</td></tr>
          <tr><th>${L('Checks')}</th><td>${L('{a} nieuw, {b} bestaan al (overgeslagen)', { a: pre.checks_new, b: pre.checks_existing })}</td></tr>
          ${withSettings ? `<tr><th>${L('Instellingen')}</th><td>${L('{n} overgenomen', { n: pre.settings })}</td></tr>` : ''}</table>
          <p class="hint">${L('Nieuwe agent-servers moet je daarna nog koppelen (Agent Manager → Koppelcode).')}</p>${confirmField('IMPORTEREN')}`,
        buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Importeren'), cls: 'primary', submit: true }],
        onSubmit: async (dd) => {
          const r = await api('import', { data, settings: withSettings, apply: true, confirm: dd.confirm });
          toast(L('Geïmporteerd: {h} servers, {c} checks, {t} sjablonen', { h: r.counts.hosts_new, c: r.counts.checks_new, t: r.counts.templates_new }));
        },
      });
    };
  }
  if ($('#chain-test')) {
    $('#chain-test').onclick = async (e) => {
      const b = e.currentTarget;
      b.disabled = true;
      $('#chain-out').innerHTML = `<p class="muted">${L('Bezig…')}</p>`;
      try {
        const r = (await api('chain_test', {})).channels;
        const names = { pushover: '📲 Pushover', email: '📧 ' + L('E-mail'), webhook: '🏠 ' + L('Home Assistant-webhook'), mqtt: '📡 MQTT', page: '📄 ' + L('Meldingenpagina') };
        $('#chain-out').innerHTML = `<ul class="checklist" style="margin-top:.8rem">${Object.entries(names).map(([k, l]) => {
          const c = r[k];
          return `<li class="${c === null ? 'warn' : c.ok ? 'ok' : 'bad'}"><span class="ic">${c === null ? '–' : c.ok ? '✓' : '✕'}</span><span><strong>${esc(l)}</strong><br>
            <span class="muted small">${c === null ? L('staat uit') : c.ok ? L('verstuurd') : esc(c.error || L('mislukt'))}</span></span></li>`;
        }).join('')}</ul>`;
      } catch (ex) { $('#chain-out').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
      b.disabled = false;
    };
  }
}

// ---------------------------------------------------------------- 📡 Verbindingen en taken (1.91.0)
const tsAgo = (ts) => (ts ? ago(new Date(ts * 1000).toISOString()) : L('nooit'));
const tsWhen = (ts) => (ts ? new Date(ts * 1000).toLocaleString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '–');
const TASK_ST = { ok: ['ok', '✔ ' + L('loopt')], warn: ['bad', '! ' + L('laatste poging mislukt')], bad: ['bad', '✘ ' + L('lukt niet')], wait: ['', L('nog niet gedaan')], off: ['', L('uit')] };

async function loadConn() {
  const box = $('#conn-body');
  if (!box) return;
  $('#conn-reload').onclick = loadConn;
  let d;
  try { d = await api('connections'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const i = d.inbound;
  const table = (head, rows) => `<div class="table-scroll"><table class="vuln-table"><thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>`;
  const tag = (st) => { const [c, l] = TASK_ST[st] || ['', st]; return `<span class="u-tag ${c}">${esc(l)}</span>`; };
  const stale = i.agents.filter((a) => a.stale).length;
  const users = new Set(i.sessions.map((x) => x.username)).size;
  const keyLast = i.api_keys.map((k) => k.last_used).filter(Boolean).sort().pop();
  const hbLast = i.heartbeats.map((h) => h.last).filter(Boolean).sort().pop();
  const line = (icon, what, count, when, go, goLabel) => `<div class="conn-row"><span class="conn-ic">${icon}</span><div class="conn-txt"><b>${what}</b>: ${count}
      <div class="muted small">${when}</div></div>${go ? `<a href="#${go}" data-goto="${go}" class="small nowrap">${goLabel} →</a>` : ''}</div>`;
  box.innerHTML = `<h3 class="set-sub">⬇️ ${L('Binnenkomend')}</h3>
    ${line('🖥️', L('Agents'), i.agents.length ? L('{n} actief', { n: i.agents.length - stale }) + (stale ? ` · <span class="bad">${L('{n} stil', { n: stale })}</span>` : '') : L('geen'),
      L('Starten elke 30 s en sturen een rapport als hun ritme om is (standaard {t}, per server in te stellen in de Agent Manager). Met realtime gaat er bij een login, een gestopte service of een gewijzigd bestand meteen een rapport uit.', { t: fmtInterval(i.agent_tick) })
        + (i.agents.some((a) => a.realtime) ? ' ' + L('Realtime aan op: {names}.', { names: i.agents.filter((a) => a.realtime).map((a) => esc(a.name)).join(', ') }) : ''),
      'settings/agents', L('Agents'))}
    ${line('🌐', L('Browsers (laatste 24 uur)'), i.sessions.length ? (users === 1 ? L('{n} apparaten van 1 gebruiker', { n: i.sessions.length }) : L('{n} apparaten van {u} gebruikers', { n: i.sessions.length, u: users })) : L('geen'),
      L('Een geopend tabblad ververst elke 20 s, alleen als het zichtbaar is.'), 'settings/users', L('Ingelogde apparaten'))}
    ${line('🔑', L('API-sleutels'), i.api_keys.length ? (i.api_keys.length === 1 ? L('1 sleutel') : L('{n} sleutels', { n: i.api_keys.length })) + (keyLast ? ' · ' + L('laatst gebruikt {t}', { t: esc(ago(keyLast)) }) : '') : L('geen'),
      L('Wanneer iets erom vraagt, bijvoorbeeld REST-sensoren in Home Assistant.'), 'settings/api', L('API-sleutels'))}
    ${line('💓', L('Heartbeats'), i.heartbeats.length ? (i.heartbeats.length === 1 ? L('1 check') : L('{n} checks', { n: i.heartbeats.length })) + (hbLast ? ' · ' + L('laatst gemeld {t}', { t: esc(ago(hbLast)) }) : '') : L('geen'),
      L('Wanneer je scripts, cronjobs of apparaten zich melden.'), i.heartbeats.length ? 'dashboard' : '', L('Dashboard'))}
    <h3 class="set-sub">⬆️ ${L('Achtergrondtaken van RzMon')}</h3>
    <p class="muted small">${L('RzMon draait elke minuut (cron): checks naar je apparaten volgens hun interval, meldingen naar Pushover, e-mail en Home Assistant, en deze taken.')}</p>
    ${table([L('Taak'), L('Hoe vaak'), L('Laatst gelukt'), L('Volgende'), L('Stand')], d.tasks.map((t) => `<tr${t.status === 'off' ? ' class="muted"' : ''}>
      <td><b>${esc(t.label)}</b>${t.error && t.status !== 'off' ? `<div class="bad small">${esc(t.error)}</div>` : ''}${t.note ? `<div class="muted small">${esc(t.note)}</div>` : ''}</td>
      <td class="small">${esc(t.every)}</td><td>${t.status === 'off' ? '–' : esc(tsAgo(t.last_ok))}</td>
      <td class="small">${t.status === 'off' ? '–' : esc(tsWhen(t.next))}</td><td>${tag(t.status)}</td></tr>`))}`;
}

// ---------------------------------------------------------------- 🛠️ Systeem: wat eerst op de setup-pagina stond (1.92.0)
let setupInfo = null;
async function loadSyscheck() {
  const box = $('#syscheck-body');
  if (!box) return;
  $('#syscheck-reload').onclick = loadSyscheck;
  let d;
  try { d = await api('setup_info'); } catch (ex) { box.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  setupInfo = d;
  box.innerHTML = `<ul class="checklist">${d.checks.map((c) => `<li class="${c.ok === true ? 'ok' : c.ok === null ? 'warn' : 'bad'}">
      <span class="ic">${c.ok === true ? '✓' : c.ok === null ? '!' : '✕'}</span><span><strong>${esc(c.label)}</strong><br><span class="muted small">${esc(c.detail)}</span></span></li>`).join('')}</ul>
    <details class="small api-docs"><summary>${L('PHP-omgeving')}</summary>
      <table class="kv">${Object.entries(d.php_env).map(([k, v]) => `<tr><th>${esc(k)}</th><td class="mono">${esc(v)}</td></tr>`).join('')}</table></details>
    ${selfReport(d.self)}
    ${perfReport(d.perf)}
    <h3 class="set-sub">⏱️ Cron</h3>
    <p class="muted small">${L('De checks draaien elke minuut via cron. Plaats deze regel op de server (als root):')}</p>${copyBox('cron-line', d.cron)}`;
  wireCopy(box);
  perfWire(box);
}

const GZIP_APACHE = `# /etc/httpd/conf.d/rzmon-snelheid.conf  (daarna: systemctl reload httpd)
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
</IfModule>
<IfModule mod_expires.c>
  <LocationMatch "/assets/.*\\.(js|css|svg|png|woff2)$">
    ExpiresActive On
    ExpiresDefault "access plus 30 days"
  </LocationMatch>
</IfModule>`;
const GZIP_NGINX = `# in het server-blok van RzMon  (daarna: nginx -t && systemctl reload nginx)
gzip on;
gzip_types text/css application/javascript application/json image/svg+xml;
location ~* /assets/.*\\.(js|css|svg|png|woff2)$ { expires 30d; }`;

/** Snelheid (1.119.0): OPcache, compressie, de traagste verzoeken en cron-onderdelen, de grootste tabellen */
function perfReport(p) {
  if (!p) return '';
  const tbl = (rows, cols) => `<div class="table-scroll"><table class="vuln-table small perf-t"><thead><tr>${cols.map((c) => `<th>${c[0]}</th>`).join('')}</tr></thead>
    <tbody>${rows.map((r) => `<tr>${cols.map((c) => `<td>${c[1](r)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
  const ms = (n) => (n >= 1000 ? dec(n / 1000) + ' s' : `${(+n).toLocaleString(LOCALE)} ms`);
  const slow = (n, lim) => (n >= lim ? `<b class="bad">${ms(n)}</b>` : n >= lim / 3 ? `<span class="warn-txt">${ms(n)}</span>` : ms(n));
  const op = p.opcache || {};
  return `<h3 class="set-sub">🚀 ${L('Snelheid')} <span class="muted small">${L('(laatste 24 uur)')}</span></h3>
    <ul class="checklist perf-list">
      <li class="${op.on ? 'ok' : 'bad'}"><span class="ic">${op.on ? '✓' : '✕'}</span><span><strong>OPcache</strong><br><span class="muted small">${op.on
        ? L('aan · {h}% uit het geheugen · {m} in gebruik', { h: dec(op.hit), m: fmtBytes(op.mem) }) + (op.full ? ' · <b class="bad">' + L('vol: geef het meer geheugen (opcache.memory_consumption)') + '</b>' : '')
        : L('uit: PHP leest bij elk verzoek alle code opnieuw in. Zet het aan:')}</span>${op.on ? '' : copyBox('perf-opc', 'dnf install -y php-opcache && systemctl restart php-fpm')}</span></li>
      <li class="warn" id="perf-gzip"><span class="ic">…</span><span><strong>${L('Compressie en cache van de webserver')}</strong><br><span class="muted small">${L('Controleren…')}</span></span></li>
    </ul>
    <label class="switch small"><input type="checkbox" id="perf-on" ${p.on ? 'checked' : ''}><span></span> ${L('Snelheid meten (een regel per verzoek in de database)')}</label>
    ${p.api.length ? `<details class="small api-docs" open><summary>${L('Verzoeken van de interface')}</summary>${tbl(p.api, [[L('Verzoek'), (r) => `<code>${esc(r.name)}</code>`], [L('Aantal'), (r) => r.n.toLocaleString(LOCALE)],
      [L('Gemiddeld'), (r) => slow(r.avg, 1000)], [L('Langste'), (r) => slow(r.max, 3000)], [L('Grootte'), (r) => `${r.kb.toLocaleString(LOCALE)} KB`], [L('Totaal'), (r) => ms(r.total)]])}</details>` : ''}
    ${p.agent.length ? `<details class="small api-docs"><summary>${L('Rapporten van agents')}</summary>${tbl(p.agent, [[L('Verzoek'), (r) => `<code>${esc(r.name)}</code>`], [L('Aantal'), (r) => r.n.toLocaleString(LOCALE)],
      [L('Gemiddeld'), (r) => slow(r.avg, 1000)], [L('Langste'), (r) => slow(r.max, 5000)]])}</details>` : ''}
    ${p.cron.length ? `<details class="small api-docs" open><summary>${L('Onderdelen van een cron-ronde')}</summary>${tbl(p.cron.filter((r) => r.name !== 'start'), [[L('Onderdeel'), (r) => esc(r.name)],
      [L('Per ronde'), (r) => slow(r.per_run, 10000)], [L('Langste'), (r) => slow(r.max, 30000)], [L('Keren'), (r) => r.n.toLocaleString(LOCALE)]])}</details>` : ''}
    <details class="small api-docs"><summary>${L('Tabellen in de database')}</summary>${tbl(p.tables, [[L('Tabel'), (t) => `<code>${esc(t.name)}</code>`], [L('Grootte'), (t) => fmtBytes(+t.bytes)],
      [L('Ongebruikt'), (t) => (+t.free > 1048576 ? `<span class="warn-txt">${fmtBytes(+t.free)}</span>` : fmtBytes(+t.free))], [L('Rijen'), (t) => (+t.rows).toLocaleString(LOCALE)]])}
      <p><button type="button" class="btn" id="perf-optimize">🧹 ${L('Database optimaliseren')}</button>
      <span class="muted">${L('Bouwt de grootste tabellen opnieuw op: ongebruikte ruimte terug en snellere indexen. Duurt even (tot een paar minuten); doe het buiten drukke uren.')}</span></p>
      <div id="perf-opt-res"></div></details>`;
}

/** Knoppen van het snelheidsoverzicht, en de controle op compressie (vanuit de browser: die ziet wat de webserver doet) */
function perfWire(box) {
  const on = $('#perf-on', box);
  if (on) on.onchange = async () => { try { await api('settings_save', { perf_on: on.checked ? '1' : '0' }); toast(on.checked ? L('Aangezet') : L('Uitgezet')); } catch (ex) { toast(ex.message, 'err'); on.checked = !on.checked; } };
  const opt = $('#perf-optimize', box);
  if (opt) {
    opt.onclick = async () => {
      if (!confirm(L('De database nu optimaliseren? RzMon kan tijdens het optimaliseren even trager reageren.'))) return;
      opt.disabled = true;
      opt.textContent = '⏳ ' + L('Bezig…');
      try {
        const r = await api('db_optimize', {});
        const saved = r.tables.reduce((a, t) => a + Math.max(0, t.before - t.after), 0);
        $('#perf-opt-res').innerHTML = `<p class="good-txt">✔ ${L('{n} tabellen geoptimaliseerd · {s} vrijgekomen', { n: r.tables.length, s: fmtBytes(saved) })}</p>`;
      } catch (ex) { toast(ex.message, 'err'); }
      opt.disabled = false;
      opt.textContent = '🧹 ' + L('Database optimaliseren');
    };
  }
  const li = $('#perf-gzip', box);
  const src = ($('script[src*="assets/app.js"]') || {}).src;
  if (!li || !src) return;
  fetch(src, { method: 'HEAD', cache: 'no-store' }).then((res) => {
    const enc = res.headers.get('content-encoding') || '';
    const cache = res.headers.get('cache-control') || res.headers.get('expires') || '';
    const ok = /gzip|br|deflate|zstd/.test(enc), cached = /max-age=[1-9]|expires|immutable/i.test(cache) || !!res.headers.get('expires');
    li.className = ok && cached ? 'ok' : 'warn';
    li.innerHTML = `<span class="ic">${ok && cached ? '✓' : '!'}</span><span><strong>${L('Compressie en cache van de webserver')}</strong><br><span class="muted small">`
      + (ok ? L('compressie: {e}', { e: esc(enc) }) : L('geen compressie: de interface (800 KB) en de antwoorden gaan onverkleind over het netwerk'))
      + ' · ' + (cached ? L('bestanden worden bewaard in de browser') : L('de browser vraagt de bestanden steeds opnieuw op')) + '</span>'
      + (ok && cached ? '' : `<br><span class="small">${L('Zet het aan in je webserver (één keer, als root):')}</span>
        <details class="small"><summary>Apache (httpd)</summary>${copyBox('perf-ap', GZIP_APACHE)}</details>
        <details class="small"><summary>nginx</summary>${copyBox('perf-ng', GZIP_NGINX)}</details>`) + '</span>';
    wireCopy(li);
  }).catch(() => { li.innerHTML = `<span class="ic">?</span><span><strong>${L('Compressie en cache van de webserver')}</strong><br><span class="muted small">${L('Kon niet gecontroleerd worden.')}</span></span>`; });
}

/** Gezondheid van RzMon (1.96.0): database, cron, traagste checks, PHP-fouten */
function selfReport(r) {
  if (!r) return '';
  const db = r.db, c = r.cron;
  const dbLine = `${L('Database: {s}', { s: fmtBytes(db.size) })}${db.growth_day != null ? ' · ' + L('groeit {g} per dag', { g: fmtBytes(db.growth_day) }) : ` · <span class="muted">${L('groei: na een paar dagen bekend')}</span>`}`
    + (db.free != null ? ' · ' + L('vrij op de disk: {f}', { f: fmtBytes(db.free) }) : '')
    + (db.full_days != null ? ` · <b class="${db.full_days < 30 ? 'bad' : db.full_days < 90 ? 'warn-txt' : ''}">${L('vol over ~{n} dagen', { n: db.full_days })}</b>` : '');
  return `<h3 class="set-sub">🗄️ ${L('Database')}</h3><p class="small">${dbLine}</p>
    <details class="small api-docs"><summary>${L('Grootste tabellen')}</summary><table class="kv">${db.tables.map((t) => `<tr><th class="mono">${esc(t.name)}</th><td>${fmtBytes(+t.bytes)} · ${L('{n} rijen', { n: (+t.rows).toLocaleString(LOCALE) })}</td></tr>`).join('')}</table>
      <p class="muted">${L('Meethistorie en meldingen bewaren stel je in bij Database en opslag.')}</p></details>
    <h3 class="set-sub">⏱️ ${L('Duur van een ronde')}</h3>
    <p class="small">${c ? L('Laatste {l} s · gemiddeld {a} s · langste {m} s (laatste {n} rondes)', { l: dec(c.last), a: dec(c.avg), m: dec(c.max), n: c.n })
      + (c.over ? ` · <b class="bad">${L('{n} keer bijna een minuut of langer', { n: c.over })}</b>` : '')
      + (c.overlap_at ? `<br><span class="warn-txt">${L('Laatst uitgelopen (vorige ronde nog bezig): {t}', { t: esc(fmtDateTime(c.overlap_at)) })}</span>` : '') : `<span class="muted">${L('Nog niet gemeten.')}</span>`}</p>
    <h3 class="set-sub">🐢 ${L('Traagste checks')}</h3>
    ${r.slow.length ? `<table class="kv small">${r.slow.map((s) => `<tr><th>${esc(s.host)} · ${esc(s.name)}</th><td>${(+s.run_ms).toLocaleString(LOCALE)} ms</td></tr>`).join('')}</table>
      <p class="muted small">${L('Een trage check (time-out) houdt de rest van de ronde op. Kijk of het doel bereikbaar is, of geef hem een langer interval.')}</p>` : `<p class="small good-txt">✔ ${L('Geen trage checks (alles onder 0,1 seconde).')}</p>`}
    <h3 class="set-sub">⚠️ ${L('PHP-fouten (24 uur)')}</h3>
    ${r.errors.length ? `<div class="table-scroll"><table class="vuln-table small"><thead><tr><th>${L('Fout')}</th><th>${L('Waar')}</th><th>${L('Aantal')}</th><th>${L('Laatst')}</th></tr></thead><tbody>
      ${r.errors.map((e) => `<tr><td><b class="${e.level === 'warning' ? 'warn-txt' : 'bad'}">${esc(e.level)}</b> ${esc(e.message)}</td><td class="mono">${esc(e.file)}:${e.line}<br><span class="muted">${esc(e.url)}</span></td>
        <td>${e.count}</td><td class="nowrap">${esc(ago(e.last_at))}</td></tr>`).join('')}</tbody></table></div>` : `<p class="small good-txt">✔ ${L('Geen fouten.')}</p>`}`;
}

/** config.php kon niet geschreven worden (map vergrendeld): de inhoud om zelf te plaatsen */
function configManual(r) {
  modal({
    title: L('config.php zelf plaatsen'),
    body: `<p style="margin-top:0">${L('De installatiemap is vergrendeld (zo hoort het). Zet deze inhoud als root in {path}:', { path: `<code>${esc(r.path)}</code>` })}</p>${copyBox('cfg-content', r.content)}`,
    buttons: [{ label: L('Sluiten'), cls: 'primary', submit: true }],
  });
  wireCopy($('#modal-body'));
}

async function loadSetupInfo() {
  if (!$('#server-body')) return;
  try { setupInfo = await api('setup_info'); } catch (ex) { $('#server-body').innerHTML = $('#dbconn-body').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
  const d = setupInfo;
  $('#server-body').innerHTML = `<form id="server-form">
      <div class="row2"><label>${L('Tijdzone')}<select name="timezone">${d.timezones.map((t) => `<option ${t === d.timezone ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
        <label>${L('Basis-URL')} <span class="hint">${L('(voor agent-instructies)')}</span><input name="base_url" type="url" value="${esc(d.base_url || '')}" placeholder="http://rzmon.lan"></label></div>
      <label>${L('Pad naar ping')}<input name="ping_bin" value="${esc(d.ping_bin || '')}" class="mono"></label>
      <div class="row2"><label>memory_limit <span class="hint">${L('nu')} ${esc(d.php_now.memory_limit)}</span><input name="memory_limit" value="${esc(d.php.memory_limit || '')}" placeholder="${esc(L('bv. 256M'))}"></label>
        <label>max_execution_time <span class="hint">${L('sec')}</span><input name="max_execution_time" value="${esc(d.php.max_execution_time || '')}" placeholder="${esc(L('bv. 120'))}"></label></div>
      <label class="switch"><input type="checkbox" name="display_errors" ${d.php.display_errors === '1' ? 'checked' : ''}><span></span> ${L('display_errors (PHP-fouten op het scherm tonen)')}</label>
      <p class="hint">${L('Deze PHP-waarden past RzMon zelf toe (via <code>ini_set</code>). Leeg = waarde uit php.ini. Zet display_errors alleen aan bij het debuggen.')}</p>
      <button class="btn primary">${L('Opslaan')}</button></form>`;
  $('#server-form').onsubmit = async (e) => {
    e.preventDefault();
    const f = e.target;
    try {
      const r = await api('setup_app_save', { timezone: f.timezone.value, base_url: f.base_url.value, ping_bin: f.ping_bin.value,
        memory_limit: f.memory_limit.value, max_execution_time: f.max_execution_time.value, display_errors: f.display_errors.checked });
      if (r.written) toast(L('Opgeslagen')); else configManual(r);
    } catch (ex) { toast(ex.message, 'err'); }
  };
  const db = d.db;
  $('#dbconn-body').innerHTML = `<form id="dbconn-form">
      <div class="row3"><label>${L('Host')}<input name="db_host" value="${esc(db.host)}" required></label>
        <label>${L('Poort')}<input name="db_port" type="number" value="${esc(db.port || 3306)}" min="1" max="65535"></label>
        <label>${L('Database')}<input name="db_name" value="${esc(db.name)}" required pattern="\\w+"></label></div>
      <div class="row2"><label>${L('Gebruiker')}<input name="db_user" value="${esc(db.user)}" required autocomplete="off"></label>
        <label>${L('Wachtwoord')} <span class="hint">${db.has_pass ? L('(leeg = ongewijzigd)') : ''}</span><input name="db_pass" type="password" autocomplete="new-password"></label></div>
      <div class="btn-row"><button type="button" class="btn" id="dbconn-test">🔌 ${L('Verbinding testen')}</button>
        <button type="button" class="btn" id="dbconn-save">💾 ${L('Opslaan en tabellen installeren')}</button>
        <button type="button" class="btn" id="dbconn-schema">↻ ${L('Tabellen bijwerken')}</button></div></form>`;
  const vals = () => { const f = $('#dbconn-form'); return { db_host: f.db_host.value, db_port: f.db_port.value, db_name: f.db_name.value, db_user: f.db_user.value, db_pass: f.db_pass.value }; };
  $('#dbconn-test').onclick = async () => {
    try { const r = await api('setup_db_test', vals()); toast(L('Verbinding OK: {v}', { v: r.version }) + (r.db_exists ? '' : ' · ' + L('database bestaat nog niet'))); }
    catch (ex) { toast(ex.message, 'err'); }
  };
  $('#dbconn-schema').onclick = async () => {
    try { toast((await api('setup_schema')).message); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#dbconn-save').onclick = () => modal({
    title: '🔌 ' + L('Databaseverbinding opslaan'),
    body: `<p style="margin-top:0">${L('RzMon gaat hierna de nieuwe database gebruiken en installeert of werkt daar de tabellen bij. De gegevens in de oude database gaan niet vanzelf mee: zet eerst een back-up terug in de nieuwe database als je verhuist.')}</p>${confirmField('DATABASE')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: L('Opslaan'), cls: 'danger', submit: true }],
    onSubmit: async (f) => {
      const r = await api('setup_db_save', { ...vals(), confirm: f.confirm });
      if (r.written) toast(r.message); else setTimeout(() => configManual(r), 50);
    },
  });
}

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
/**
 * Bevestigen met je wachtwoord (1.127.0), in plaats van de prompt van de browser (die het wachtwoord leesbaar liet zien):
 * een eigen venster bovenop wat er open staat, met een verborgen wachtwoordveld (👁 om het even te tonen). Op het
 * lokale netwerk zonder account typ je het woord (bv. ISOLEREN). @return het wachtwoord of woord, of null bij annuleren
 */
function askConfirm({ title, text = '', word, ok = L('Bevestigen'), danger = false }) {
  return new Promise((resolve) => {
    const lan = !!document.querySelector('.lan-badge');
    let dlg = $('#confirm-dlg');
    if (!dlg) { dlg = document.createElement('dialog'); dlg.id = 'confirm-dlg'; dlg.className = 'confirm-dlg'; document.body.append(dlg); }
    dlg.innerHTML = `<form method="dialog" class="cf-form">
      <div class="cf-head"><span class="cf-ic ${danger ? 'danger' : ''}" aria-hidden="true">${danger ? '!' : '🔒'}</span><h3>${esc(title)}</h3></div>
      ${text ? `<p class="cf-text">${text}</p>` : ''}
      <label class="cf-label">${lan ? L('Typ <b>{word}</b> om te bevestigen', { word: esc(L(word)) }) : L('Je wachtwoord')}
        <span class="cf-field"><input name="pw" type="${lan ? 'text' : 'password'}" required autocomplete="${lan ? 'off' : 'current-password'}" spellcheck="false" ${lan ? `placeholder="${esc(L(word))}"` : ''}>
        ${lan ? '' : `<button type="button" class="cf-eye" title="${esc(L('Wachtwoord tonen'))}" aria-label="${esc(L('Wachtwoord tonen'))}">👁</button>`}</span></label>
      <div class="cf-btns"><button type="button" class="btn" value="cancel" data-cf-cancel>${L('Annuleren')}</button>
        <button type="submit" class="btn ${danger ? 'danger' : 'primary'}">${esc(ok)}</button></div></form>`;
    const inp = $('input', dlg);
    let done = false;
    const finish = (v) => { if (done) return; done = true; dlg.close(); resolve(v); };
    $('.cf-eye', dlg)?.addEventListener('click', (e) => { inp.type = inp.type === 'password' ? 'text' : 'password'; e.currentTarget.classList.toggle('on', inp.type === 'text'); inp.focus(); });
    $('[data-cf-cancel]', dlg).onclick = () => finish(null);
    $('form', dlg).onsubmit = (e) => { e.preventDefault(); if (inp.value) finish(inp.value); };
    dlg.oncancel = (e) => { e.preventDefault(); finish(null); };
    dlg.showModal();
    inp.focus();
  });
}

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
async function openUpdateDialog(pick = false) {
  let u = updateInfo;
  try { u = await api('update_info'); updateInfo = u; } catch (ex) { if (!u) { toast(ex.message, 'err'); return; } }
  if (u.status && ['queued', 'running'].includes(u.status.state)) { showUpdateDialog(u.status, '', true); pollUpdate(); return; }
  const versions = (u.versions || []).filter((v) => v.version !== u.current);
  if ((!u.available && !(pick && versions.length)) || u.unavailable) {
    // Niets bij te werken, of het kan (nog) niet vanuit RzMon: de uitleg staat bij Instellingen
    showTab('settings/update');
    return;
  }
  modal({
    title: '⬆ ' + L('RzMon bijwerken'),
    body: `<p style="margin-top:0">${u.available ? L('RzMon bijwerken van <b>{from}</b> naar <b>{to}</b>?', { from: esc(u.current), to: esc(u.latest) }) : L('Je gebruikt <b>{v}</b>.', { v: esc(u.current) })}</p>
      ${versions.length ? `<label>${L('Versie')} <span class="hint">${L('(niets kiezen = de nieuwste)')}</span>
        <select name="version"><option value="">${L('De nieuwste ({v})', { v: esc(u.latest || '?') })}</option>
          ${versions.map((v) => `<option value="${esc(v.version)}">${esc(v.version)}${v.date ? ` – ${esc(v.date)}` : ''}${verCmp(v.version, u.current) < 0 ? ` · ${L('ouder: terugzetten')}` : ''}</option>`).join('')}</select></label>
        <div id="upd-older" class="callout small" hidden>${L('⚠️ <b>Terugzetten naar een oudere versie.</b> Je instellingen en gegevens blijven staan; de database blijft zoals hij is (nieuwere kolommen worden niet gebruikt). Wat er sindsdien bij kwam, werkt niet meer. Automatisch bijwerken pauzeert tot je weer naar de nieuwste gaat. Agents hoef je niet terug te zetten.')}
          ${confirmField('TERUGZETTEN')}</div>` : ''}
      ${u.notes.length ? `<details class="update-notes upd-notes"><summary>${u.notes.length === 1 ? L('Wat is er nieuw (1 versie)') : L('Wat is er nieuw ({n} versies)', { n: u.notes.length })}</summary>
        ${u.notes.map((n) => `<div class="un-ver"><h3>${esc(n.version)} <span class="muted small">${esc(n.date)}</span></h3>${mdLite(n.text)}</div>`).join('')}</details>` : ''}
      <label class="switch"><input type="checkbox" name="backup" ${u.backup ? 'checked' : ''}><span></span> ${L('Eerst een back-up maken')}</label>
      <p class="muted small">${L('RzMon is tijdens het installeren even niet bereikbaar. Dit venster laat zien hoe ver de installatie is.')}</p>`,
    buttons: [
      { label: L('Annuleren'), onClick: () => $('#modal').close() },
      { label: '⬆ ' + L('Bijwerken'), cls: 'primary', onClick: async (btn) => {
        const f = $('#modal-form');
        const backup = f.backup.checked;
        const version = f.version ? f.version.value : '';
        btn.disabled = true;
        try {
          await api('update_start', { backup, version, confirm: f.confirm ? f.confirm.value : '' });
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
  // Een oudere versie gekozen: waarschuwing en bevestigen
  const sel = $('#modal-form').version;
  if (sel) sel.onchange = () => { $('#upd-older').hidden = !(sel.value && verCmp(sel.value, u.current) < 0); };
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

// ---------------------------------------------------------------- Analyse (1.106.0)
let anPeriod = (() => { try { return localStorage.getItem('rzmon-an-period') || '30d'; } catch (e) { return '30d'; } })();
let anData = null;
const AN_PERIODS = [['1d', () => L('24 uur')], ['7d', () => L('7 dagen')], ['30d', () => L('30 dagen')], ['90d', () => L('90 dagen')], ['365d', () => L('1 jaar')]];

function initAnalytics() {
  $('#an-period').onclick = (e) => { const b = e.target.closest('[data-anp]'); if (b) { anPeriod = b.dataset.anp; try { localStorage.setItem('rzmon-an-period', anPeriod); } catch (ex) { /* alleen nu */ } loadAnalytics(); } };
  $('#an-go').onclick = () => { const f = $('#an-from').value, t = $('#an-to').value; if (f && t && f <= t) { anPeriod = `${f}..${t}`; loadAnalytics(); } };
  $('#an-body').onclick = (e) => {
    const g = e.target.closest('[data-an-go]');
    if (!g) return;
    const go = g.dataset.anGo;
    if (go.startsWith('check:')) checkInfo(go.slice(6)); else showTab(go);
  };
  $('#an-report').onchange = async (e) => {
    if (!e.target.matches('[data-an-report]')) return;
    try { await api('settings_save', { report_monthly: e.target.checked ? '1' : '0' }); toast(e.target.checked ? L('Maandrapport staat aan: op de 1e van elke maand per e-mail') : L('Maandrapport staat uit')); } catch (ex) { toast(ex.message, 'err'); }
  };
  $('#an-report').onclick = async (e) => {
    if (!e.target.closest('[data-an-send]')) return;
    try { await api('analytics_report_send', {}); toast(L('Rapport van vorige maand verstuurd')); } catch (ex) { toast(ex.message, 'err'); }
  };
}

async function loadAnalytics(fresh = false) {
  $('#an-period').innerHTML = AN_PERIODS.map(([k, l]) => `<button type="button" class="dchip ${anPeriod === k ? 'on' : ''}" data-anp="${k}">${l()}</button>`).join('');
  $('#an-info').textContent = '⏳';
  try { anData = await api('analytics', null, { period: anPeriod, ...(fresh ? { fresh: 1 } : {}) }); } catch (ex) { $('#an-body').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; return; }
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
  $('#an-report').innerHTML = `<label class="switch small" title="${esc(L('Op de 1e van elke maand een samenvatting per e-mail (naar het e-mailadres bij Meldingen → Kanalen)'))}"><input type="checkbox" data-an-report ${d.report_monthly ? 'checked' : ''} ${d.mail ? '' : 'disabled'}><span></span> 📧 ${L('Maandrapport')}</label>
    <button type="button" class="btn small-btn" data-an-send ${d.mail ? '' : 'disabled'} title="${esc(d.mail ? L('Het rapport van vorige maand nu versturen') : L('Stel eerst e-mail in (Meldingen → Kanalen)'))}">${L('Nu versturen')}</button>`;
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
    ${anBox(L('RzMon zelf'), `<p class="small">${b.rzmon ? L('Laatste back-up van RzMon: {t}', { t: esc(fmtDateTime(b.rzmon)) }) : `<span class="warn-txt">${L('Nog geen back-up van RzMon zelf.')}</span>`}</p>
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
  $('#an-body').innerHTML = kpis + todo + rel + cap + sec + net + bk + noise;
}

// ---------------------------------------------------------------- Netwerk (1.103.0)
const NET_KIND = {
  router: ['🌐', () => L('Router')], network: ['🔀', () => L('Netwerkapparaat')], server: ['🖥️', () => L('Server')], nas: ['🗄️', () => 'NAS'],
  computer: ['💻', () => L('Computer')], phone: ['📱', () => L('Telefoon of tablet')], media: ['📺', () => L('Tv of media')], printer: ['🖨️', () => L('Printer')],
  camera: ['📷', () => L('Camera')], iot: ['💡', () => L('Slim apparaat (IoT)')], smarthome: ['🏠', () => L('Smart home')], unknown: ['❔', () => L('Onbekend')],
};
/** Soort in RzMon (hostForm) bij de soort uit de netwerkscan */
const NET_TO_HOST = { router: 'router', network: 'switch', server: 'server', nas: 'nas', printer: 'printer', camera: 'camera', smarthome: 'smarthome', iot: 'smarthome' };
let netData = null;
let netFilter = 'all';
const netKind = (d) => d.kind_user || d.kind || 'unknown';

function initNet() {
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
  $('#net-list').onclick = netClick;
}

async function loadNet() {
  try { netData = await api('net_devices'); renderNet(); } catch (ex) { $('#net-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

async function netScanNow(btn) {
  btn.disabled = true;
  const old = btn.textContent;
  btn.textContent = '⏳ ' + L('Scannen…');
  try { const r = await api('net_scan_now', { deep: true }); toast(r.message); await loadNet(); refresh(); } catch (ex) { toast(ex.message, 'err'); }
  btn.disabled = false;
  btn.textContent = old;
}

function renderNet() { withMorph(renderNetNow); }
function renderNetNow() {
  const d = netData;
  if (!d) return;
  const devs = d.devices;
  const day = Date.now() - 86400e3;
  const online = (x) => new Date(String(x.last_seen).replace(' ', 'T')).getTime() > day;
  const cnt = {
    all: devs.length, new: devs.filter((x) => x.status === 'new').length, norz: devs.filter((x) => !x.host && x.status !== 'ignored').length,
    rz: devs.filter((x) => x.host).length, risky: devs.filter((x) => x.risky.length && x.status !== 'ignored').length,
    known: devs.filter((x) => x.status === 'known').length, ignored: devs.filter((x) => x.status === 'ignored').length, online: devs.filter(online).length,
    blocked: devs.filter((x) => x.blocked).length,
  };
  $('#net-counts').innerHTML = [['new', '🆕', L('Nieuw'), 's1'], ['risky', '⚠️', L('Riskante poort'), 's2'], ['norz', '➕', L('Niet in RzMon'), 'si'], ['rz', '✔', L('In RzMon'), 's0']]
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
    <div class="kpi"><div class="kpi-lbl">${L('Niet in RzMon')}</div><div class="kpi-val">${cnt.norz}</div><div class="kpi-sub">${L('{n} wel', { n: cnt.rz })}</div></div>
    <div class="kpi ${cnt.risky ? 'bad' : ''}"><div class="kpi-lbl">${L('Riskante poort open')}</div><div class="kpi-val">${cnt.risky}</div><div class="kpi-sub">${cnt.risky ? L('Telnet, FTP, VNC …') : L('niets gevonden')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Netwerken')}</div><div class="kpi-val kpi-val-txt">${esc(nets.scan.join(', ') || '–')}</div>
      <div class="kpi-sub">${st.sweep_ms ? L('scan duurde {s} s', { s: dec((st.sweep_ms / 1000).toFixed(1)) }) : ''}</div></div>`;
  $('#net-filter').innerHTML = [['all', L('Alles')], ['new', '🆕 ' + L('Nieuw')], ['norz', L('Niet in RzMon')], ['known', L('Bekend')], ['risky', '⚠️ ' + L('Riskant')], ['blocked', '⛔ ' + L('Geweerd')], ['ignored', L('Genegeerd')]]
    .map(([k, l]) => `<button type="button" class="dchip ${netFilter === k ? 'on' : ''}" data-nf="${k}">${l} <b>${cnt[k]}</b></button>`).join('');
  const q = $('#net-search').value.trim().toLowerCase();
  const list = devs.filter((x) => (netFilter === 'all' ? x.status !== 'ignored' : netFilter === 'new' ? x.status === 'new' : netFilter === 'norz' ? !x.host && x.status !== 'ignored'
      : netFilter === 'rz' ? x.host : netFilter === 'known' ? x.status === 'known' : netFilter === 'risky' ? x.risky.length && x.status !== 'ignored' : netFilter === 'blocked' ? x.blocked : x.status === 'ignored')
    && (!q || [x.label, x.ip, x.mac, x.vendor, x.hostname, x.name, x.info.netbios, x.info.ssdp?.model].some((v) => String(v || '').toLowerCase().includes(q))));
  if (!devs.length) { $('#net-list').innerHTML = `<p class="muted">${d.enabled ? L('Nog geen apparaten gevonden.') : L('De netwerkscan staat uit.')}</p>`; return; }
  if (!list.length) { $('#net-list').innerHTML = `<p class="muted">${L('Niets gevonden met dit filter.')}</p>`; return; }
  $('#net-list').innerHTML = `<div class="table-scroll"><table class="vuln-table net-table"><thead><tr><th>${L('Apparaat')}</th><th>${L('IP-adres')}</th><th>${L('MAC en fabrikant')}</th><th>${L('Poorten')}</th><th>${L('Gezien')}</th><th></th></tr></thead><tbody>
    ${list.map((x) => {
      const [ic, kl] = NET_KIND[netKind(x)] || NET_KIND.unknown;
      const u = x.info.unifi || {};
      const sub = [kl(), x.name && x.hostname ? x.hostname : '', x.info.ssdp?.model || '', u.essid ? '📶 ' + u.essid + (u.ap ? ' · ' + u.ap : '') : u.wired ? '🔌 ' + L('bedraad') : ''].filter(Boolean).join(' · ');
      return `<tr class="${x.status === 'ignored' ? 'muted' : ''}" data-net="${x.id}"><td><button type="button" class="link-btn net-open" data-net-open="${x.id}"><span class="net-ic">${ic}</span> <b>${esc(x.label)}</b></button>
          ${x.status === 'new' ? ` <span class="badge-root">${L('nieuw')}</span>` : ''}${x.blocked ? ` <span class="badge-root bad-badge" title="${esc(netBlockedText(x))}">⛔ ${L('geweerd')}</span>` : ''}${x.host ? ` <span class="badge" title="${esc(L('In RzMon als {name}', { name: x.host.name }))}">✔ RzMon</span>` : ''}
          <br><span class="muted small">${esc(sub)}</span></td>
        <td class="small"><span class="mono">${esc(x.ip)}</span>${online(x) ? '' : ` <span class="muted">(${L('offline')})</span>`}</td>
        <td class="small">${x.mac ? `<span class="mono">${esc(x.mac.toUpperCase())}</span>` : '–'}${+x.random_mac ? ` <span class="badge" title="${esc(L('Willekeurig MAC-adres (privé-wifiadres): telefoons en laptops gebruiken dat per netwerk, en soms wisselt het'))}">🎭 ${L('privé')}</span>` : ''}<br><span class="muted">${esc(x.vendor || '')}</span></td>
        <td class="small">${x.ports.length ? x.ports.map((p) => `<span class="port-tag ${x.risky.includes(p) ? 'bad' : ''}" title="${esc(x.risky.includes(p) ? d.risky_reasons[p] : '')}">${p}</span>`).join(' ') : `<span class="muted">${x.deep_at ? L('geen') : '…'}</span>`}</td>
        <td class="small">${esc(ago(x.last_seen))}<br><span class="muted">${L('sinds {d}', { d: esc(fmtDay(x.first_seen)) })}</span></td>
        <td class="nowrap">${!x.host ? `<button type="button" class="btn small-btn need-admin" data-net-add="${x.id}" title="${esc(L('Toevoegen aan RzMon'))}">＋ RzMon</button>` : ''}
          ${x.status !== 'known' ? `<button type="button" class="icon-btn need-admin" data-net-st="known:${x.id}" title="${esc(L('Bekend: dit apparaat hoort hier'))}">✔</button>` : ''}
          ${x.status !== 'ignored' ? `<button type="button" class="icon-btn need-admin" data-net-st="ignored:${x.id}" title="${esc(L('Negeren: niet meer tonen'))}">🙈</button>` : `<button type="button" class="icon-btn need-admin" data-net-st="new:${x.id}" title="${esc(L('Weer tonen'))}">↩</button>`}</td></tr>`;
    }).join('')}</tbody></table></div>`;
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
        ${row(L('In RzMon'), x.host ? `✔ ${esc(x.host.name)}` : L('nee'))}
        ${row(L('Gezien'), `${esc(fmtDateTime(x.first_seen))} – ${esc(ago(x.last_seen))}`)}
      </table>
      ${x.risky.length ? `<div class="callout bad-callout"><b>⚠️ ${L('Riskante poorten')}</b><ul>${x.risky.map((p) => `<li><b>${p}</b>: ${esc(netData.risky_reasons[p] || '')}</li>`).join('')}</ul>
        <p class="small" style="margin:0">${L('Heb je deze dienst niet nodig, zet hem dan uit in de instellingen van het apparaat. Anders: zorg voor een sterk wachtwoord en laat de poort nooit van internet bereikbaar zijn.')}</p></div>` : ''}
      ${x.blocked ? `<div class="callout bad-callout"><b>⛔ ${L('Geweerd')}</b>: ${esc(netBlockedText(x))}</div>` : ''}
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
      ...(!x.host ? [{ label: '＋ ' + L('Toevoegen aan RzMon'), onClick: () => { $('#modal').close(); netAddHost(x); } }] : []),
      x.blocked ? { label: '✅ ' + L('Weer toelaten'), onClick: () => netUnblock(x) } : { label: '⛔ ' + L('Weren'), cls: 'danger', onClick: () => netBlockDialog(x) },
      { label: L('Opslaan'), cls: 'primary', submit: true },
    ] : [{ label: L('Sluiten'), onClick: () => $('#modal').close() }],
    onSubmit: async (f) => { await api('net_device_save', { id: +x.id, name: f.name, kind_user: f.kind_user, notes: f.notes, status: f.status }); toast(L('Opgeslagen')); loadNet(); },
  });
  const dom = $('#net-dom');
  if (dom) dom.ontoggle = () => { if (dom.open && !dom.dataset.loaded) { dom.dataset.loaded = '1'; netDomains(x, $('#net-dom-body')); } };
}

function netBlockedText(x) {
  const b = x.blocked || {};
  const via = { unifi: L('UniFi (wifi)'), adguard: L('AdGuard (DNS)'), rzmon: L('RzMon en je servers') };
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

/** Weren (1.104.0): van het wifi (UniFi), geen DNS (AdGuard) en geen toegang tot RzMon en je servers */
function netBlockDialog(x) {
  const lk = netData.links || {};
  const canU = lk.unifi_block && x.mac, canA = lk.adguard;
  const opt = (k, on, can, lbl, why) => `<label class="switch"><input type="checkbox" name="${k}" ${on && can ? 'checked' : ''} ${can ? '' : 'disabled'}><span></span> <span>${lbl}${can ? '' : ` <span class="muted small">(${why})</span>`}</span></label>`;
  modal({
    title: '⛔ ' + L('{name} weren', { name: x.label }),
    body: `<p style="margin-top:0"><span class="mono">${esc(x.ip)}</span>${x.mac ? ` · <span class="mono">${esc(x.mac.toUpperCase())}</span>` : ''}${+x.random_mac ? ' · 🎭 ' + L('privé-adres: het apparaat kan een nieuw MAC-adres nemen') : ''}</p>
      ${opt('unifi', true, canU, '📶 ' + L('<b>UniFi</b>: niet meer op het wifi'), !lk.unifi_block ? L('UniFi niet ingesteld of alleen een API-sleutel') : L('geen MAC-adres bekend'))}
      ${opt('adguard', true, canA, '🌐 ' + L('<b>AdGuard</b>: geen DNS meer (geen internet via namen)'), L('AdGuard Home niet ingesteld'))}
      ${opt('rzmon', true, true, '🛡️ ' + L('<b>RzMon en je servers</b>: geen toegang tot RzMon, en geblokkeerd op je servers met agent'), '')}
      <div class="row2"><label>${L('Hoe lang')}<select name="days"><option value="1">${L('1 dag')}</option><option value="7">${L('7 dagen')}</option><option value="0">${L('Tot ik het weer toelaat')}</option></select></label>
        <label>${L('Reden')} <span class="hint">${L('(optioneel)')}</span><input name="reason" maxlength="200"></label></div>
      <p class="muted small">${L('Een bedraad apparaat haal je niet van het netwerk via UniFi-wifi; daarvoor zet je de poort op de switch uit. Een apparaat met een eigen DNS-server of een vast IP-adres komt langs AdGuard.')}</p>
      ${confirmField('WEREN')}`,
    buttons: [{ label: L('Annuleren'), onClick: () => $('#modal').close() }, { label: '⛔ ' + L('Weren'), cls: 'danger', submit: true }],
    onSubmit: async (d) => {
      const r = await api('net_block', { id: +x.id, unifi: !!d.unifi, adguard: !!d.adguard, rzmon: !!d.rzmon, days: +d.days, reason: d.reason, confirm: d.confirm });
      toast(L('Geweerd via {via}', { via: r.done.map((v) => ({ unifi: 'UniFi', adguard: 'AdGuard', rzmon: 'RzMon' }[v] || v)).join(', ') }) + (r.errors.length ? ' · ⚠️ ' + r.errors.join(' · ') : ''), r.errors.length ? 'err' : undefined);
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

// ---------------------------------------------------------------- Kwetsbaarheden
const VULN_SEV = [['⚪', L('onbekend')], ['🔵', 'low'], ['🟡', 'medium'], ['🟠', 'high'], ['🔴', 'critical']];
let vulnData = null;
let vulnFilter = 'all';
let vulnSharedErr = new Set();   // scanfouten die als één melding bovenaan staan
// Open of dicht (1.93.0): standaard dicht; wat je open- of dichtklapt, onthoudt RzMon per account (h: servers, e: e-mailadressen).
// Met zoeken of een filter gaan de blokken met treffers tijdelijk open (vulnTmp); daarna weer jouw stand.
const vulnTmp = { h: new Map(), e: new Map() };
const vulnSaved = (kind, id) => ((PREFS.vuln_open || {})[kind] || []).map(String).includes(String(id));
function vulnOpenSave(kind, list) {   // list: [[id, open], …]
  const v = PREFS.vuln_open || {};
  const s = new Set((v[kind] || []).map(String));
  list.forEach(([id, open]) => (open ? s.add(String(id)) : s.delete(String(id))));
  savePref('vuln_open', { h: v.h || [], e: v.e || [], [kind]: kind === 'h' ? [...s].map(Number) : [...s] });
}

function initVulns() {
  $('#vuln-search').oninput = () => renderVulns();
  $('#vuln-sev').onchange = () => renderVulns();
  $('#vuln-filter').onclick = (e) => {
    const b = e.target.closest('[data-vf]');
    if (b) { vulnFilter = b.dataset.vf; renderVulns(); }
  };
  // Tellers: critical/high/medium = ernst vanaf, actief misbruikt = dat filter
  $('#vuln-counts').onclick = (e) => {
    const b = e.target.closest('[data-vsev], [data-vf]');
    if (!b) return;
    if (b.dataset.vf) vulnFilter = vulnFilter === 'kev' ? 'all' : 'kev';
    else { const v = b.dataset.vsev; $('#vuln-sev').value = $('#vuln-sev').value === v ? '0' : v; }
    renderVulns();
  };
  $('#vuln-banner').onclick = (e) => { if (e.target.closest('[data-vscan]')) vulnScan(e.target.closest('[data-vscan]')); };
  $('#vuln-scan').onclick = (e) => vulnScan(e.currentTarget);
  $('#vuln-toggle').onclick = () => {
    const cards = $$('#vuln-hosts details.vuln-host');
    const all = cards.length && cards.every((c) => c.open);
    cards.forEach((c) => { c.open = !all; vulnTmp.h.set(+c.dataset.host, !all); });
    vulnOpenSave('h', cards.map((c) => [+c.dataset.host, !all]));
  };
  $('#vuln-hosts').addEventListener('click', vulnClick);
}

async function loadVulns() {
  try {
    vulnData = await api('vulns');
    keepOpen($('#vuln-hosts'), renderVulns);
  } catch (ex) { $('#vuln-hosts').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

// ---------------------------------------------------------------- Datalekken (Have I Been Pwned, 1.69.0)
let leakData = null;
let leakFilter = 'open';
let vulnTab = (() => { try { return localStorage.getItem('rzmon-vuln-tab') || 'cve'; } catch (e) { return 'cve'; } })();
const LEAK_SRC = { user: () => '👤 ' + L('gebruiker'), list: () => '📋 ' + L('eigen lijst'), domain: () => '🌐 ' + L('domein') };

function showVulnTab() {
  if (!$('#vuln-tabs')) return;
  if (!['cve', 'leak'].includes(vulnTab)) vulnTab = 'cve';
  $$('#vuln-tabs .seg-btn').forEach((x) => x.classList.toggle('active', x.dataset.vt === vulnTab));
  $$('#tab-vulns .vuln-pane').forEach((x) => { x.hidden = x.dataset.vpane !== vulnTab; });
}

function initLeaks() {
  if ($('#vuln-tabs')) {
    $('#vuln-tabs').onclick = (e) => {
      const b = e.target.closest('[data-vt]');
      if (!b) return;
      vulnTab = b.dataset.vt;
      try { localStorage.setItem('rzmon-vuln-tab', vulnTab); } catch (ex) { /* alleen voor nu */ }
      showVulnTab();
      fxSyncHash();
    };
    showVulnTab();
  }
  $('#leak-search').oninput = () => renderLeaks();
  $('#leak-toggle').onclick = () => {
    const cards = $$('#leak-list details.leak-card');
    const all = cards.length && cards.every((c) => c.open);
    cards.forEach((c) => { c.open = !all; vulnTmp.e.set(c.dataset.email, !all); });
    vulnOpenSave('e', cards.map((c) => [c.dataset.email, !all]));
  };
  $('#leak-filter').onclick = (e) => { const b = e.target.closest('[data-lf]'); if (b) { leakFilter = b.dataset.lf; renderLeaks(); } };
  $('#leak-counts').onclick = (e) => { const b = e.target.closest('[data-lf]'); if (b) { leakFilter = leakFilter === b.dataset.lf ? 'all' : b.dataset.lf; renderLeaks(); } };
  $('#leak-run').onclick = async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      const r = await api('hibp_run', {});
      toast(r.left > 0 ? L('Controle gestart: nog {n} adres(sen) te gaan, de rest volgt binnen een paar minuten', { n: r.left }) : L('Controle klaar'));
      loadLeaks();
    } catch (ex) { toast(ex.message, 'err'); }
    b.disabled = false;
  };
  $('#leak-list').addEventListener('click', async (e) => {
    const det = e.target.closest('details.leak-card');
    if (e.target.closest('summary') && det && $(':scope > summary', det).contains(e.target)) {   // open/dicht onthouden (per account)
      setTimeout(() => { vulnTmp.e.set(det.dataset.email, det.open); vulnOpenSave('e', [[det.dataset.email, det.open]]); }, 0);
      return;
    }
    const b = e.target.closest('[data-lhandle]');
    if (!b) return;
    try { await api('hibp_handle', { email: b.dataset.email, name: b.dataset.lhandle, handled: b.dataset.done === '1' }); loadLeaks(); }
    catch (ex) { toast(ex.message, 'err'); }
  });
}

async function loadLeaks() {
  try {
    leakData = await api('hibp_overview');
    keepOpen($('#leak-list'), renderLeaks);
  } catch (ex) { $('#leak-list').innerHTML = `<p class="form-error">${esc(ex.message)}</p>`; }
}

function leakVisible(e) {
  const q = $('#leak-search').value.trim().toLowerCase();
  if (!e.breaches.length && !fxPass('leaks', { email: e.email, source: e.source, none: 1 })) return null;
  const bs = e.breaches.filter((b) => fxPass('leaks', { ...b, email: e.email, source: e.source })
    && (leakFilter === 'handled' ? b.handled_at : leakFilter === 'all' || leakFilter === 'none' ? true : !b.handled_at)
    && (leakFilter !== 'pw' || b.passwords)
    && (!q || e.email.includes(q) || (b.title || b.name).toLowerCase().includes(q) || (b.domain || '').toLowerCase().includes(q)));
  if (leakFilter === 'none') return e.breaches.length ? null : bs;
  if ((leakFilter !== 'all' || fxActive('leaks')) && !bs.length && e.breaches.length) return null;
  if (leakFilter === 'all' && q && !bs.length && !e.email.includes(q)) return null;
  return bs;
}

function renderLeaks() {
  if (!leakData) return;
  const d = leakData;
  const all = d.emails.flatMap((e) => e.breaches.map((b) => ({ ...b, email: e.email })));
  const open = all.filter((b) => !b.handled_at);
  const pw = open.filter((b) => b.passwords);
  const hit = new Set(open.map((b) => b.email));
  const pwUsers = d.users.filter((u) => +u.hibp && +u.pw_pwned > 0);
  const handled = all.length - open.length;
  // Tellers
  $('#leak-counts').innerHTML = [
    ['open', open.length, L('Open lekken'), 's2'], ['pw', pw.length, L('Met wachtwoorden'), 's2'], ['', hit.size, L('Adressen getroffen'), 's1'],
    ['', pwUsers.length, L('Gelekte wachtwoorden'), 's2'], ['handled', handled, L('Afgehandeld'), 's0'],
  ].map(([k, n, w, c]) => `<button type="button" class="hc ${c} ${n ? '' : 'zero'} ${k && leakFilter === k ? 'on' : ''}" ${k ? `data-lf="${k}"` : ''}><span class="hc-dot"></span><b data-flash>${n}</b> <span class="hc-w">${w}</span></button>`).join('');
  $('#leak-info').textContent = d.check_at ? L('laatste controle {t}', { t: ago(d.check_at) }) + ' · ' + L('dagelijks') : '';
  if ($('#vt-n-leak')) $('#vt-n-leak').textContent = open.length || '';
  // Meldingen bovenaan: geen sleutel, fouten
  $('#leak-banner').innerHTML = [
    !d.has_key ? `<div class="v-banner info">ℹ️ <span>${L('Geen API-sleutel: alleen de wachtwoorden van RzMon-accounts worden gecontroleerd. Een sleutel vul je in bij Instellingen → Beveiliging → Datalekken.')}</span></div>` : '',
    d.last_error ? `<div class="v-banner">⚠️ <span><b>HIBP:</b> ${esc(d.last_error)}</span></div>` : '',
    d.domain_error ? `<div class="v-banner">⚠️ <span><b>${L('Domein')}:</b> ${esc(d.domain_error)}</span></div>` : '',
  ].join('');
  const newest = open.slice().sort((a, b) => String(b.added_date || '').localeCompare(String(a.added_date || '')))[0];
  const checked = d.emails.filter((e) => e.checked_at).length;
  $('#leak-kpis').innerHTML = `
    <div class="kpi kpi-main ${d.emails.some((e) => e.error) ? 'warn' : ''}"><div class="kpi-txt"><div class="kpi-lbl">${L('Adressen gecontroleerd')}</div>
      <div class="kpi-val">${checked}<small>/${d.emails.length}</small></div>
      <div class="kpi-sub">${d.domain ? L('incl. domein {d}', { d: esc(d.domain) }) : L('gebruikers en eigen lijst')}</div></div></div>
    <div class="kpi ${open.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Open lekken')}</div><div class="kpi-val">${open.length}</div>
      <div class="kpi-sub">${L('op {n} adressen', { n: hit.size })}</div></div>
    <div class="kpi ${pw.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Met wachtwoorden')}</div><div class="kpi-val">${pw.length}</div>
      <div class="kpi-sub">${pw.length ? L('wijzig die wachtwoorden') : L('geen')}</div></div>
    <div class="kpi ${pwUsers.length ? 'bad' : ''}"><div class="kpi-lbl">${L('Gelekte wachtwoorden in RzMon')}</div><div class="kpi-val">${pwUsers.length}</div>
      <div class="kpi-sub">${pwUsers.length ? esc(pwUsers.map((u) => u.username).join(', ')) : L('geen bekend')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Nieuwste lek')}</div><div class="kpi-val kpi-val-txt">${newest ? esc(newest.title || newest.name) : '–'}</div>
      <div class="kpi-sub">${newest && newest.breach_date ? esc(fmtDay(newest.breach_date)) : ''}</div></div>`;
  const cnt = { all: all.length, open: open.length, pw: pw.length, handled, none: d.emails.filter((e) => !e.breaches.length).length };
  $('#leak-filter').innerHTML = [['open', L('Open')], ['pw', '🔑 ' + L('Met wachtwoorden')], ['handled', L('Afgehandeld')], ['none', L('Zonder lekken')], ['all', L('Alles')]]
    .map(([k, l]) => `<button type="button" class="dchip ${leakFilter === k ? 'on' : ''}" data-lf="${k}">${l} <b>${cnt[k]}</b></button>`).join('');
  // Accounts met een gelekt wachtwoord
  $('#leak-users').innerHTML = pwUsers.length ? `<div class="v-banner">🔓 <span><b>${L('Wachtwoord in een datalek')}:</b> ${pwUsers.map((u) => esc(u.username)).join(', ')}.
    ${L('Bij de volgende keer inloggen moet eerst een nieuw wachtwoord worden gekozen.')}</span></div>` : '';
  fxRender('leaks');
  // Per adres
  const cards = d.emails.map((e) => [e, leakVisible(e)]).filter(([, bs]) => bs !== null)
    .sort(([a, x], [b, y]) => y.filter((z) => z.passwords && !z.handled_at).length - x.filter((z) => z.passwords && !z.handled_at).length || y.length - x.length || a.email.localeCompare(b.email));
  $('#leak-list').innerHTML = cards.length ? cards.map(([e, bs]) => leakCard(e, bs)).join('')
    : `<div class="card"><p class="muted" style="margin:0">${d.emails.length ? (leakFilter === 'open' ? '✔ ' + L('Geen open lekken.') : L('Niets gevonden.'))
      : L('Nog geen e-mailadressen: vul een API-sleutel in, en e-mailadressen bij de gebruikers of in de eigen lijst.')}</p></div>`;
}

function leakCard(e, bs) {
  const openN = e.breaches.filter((b) => !b.handled_at).length;
  const pwN = e.breaches.filter((b) => !b.handled_at && b.passwords).length;
  const narrowed = !!$('#leak-search').value.trim() || fxActive('leaks');
  if (!narrowed) vulnTmp.e.clear();
  const isOpen = narrowed ? vulnTmp.e.get(e.email) ?? bs.length > 0 : vulnSaved('e', e.email);
  return `<details class="card vuln-host leak-card ${pwN ? 'vh-w4' : openN ? 'vh-w2' : 'vh-w-1'}" data-email="${esc(e.email)}" ${isOpen ? 'open' : ''}>
    <summary><span class="host-ic" aria-hidden="true">✉️</span>
      <span class="vh-name"><b>${esc(e.email)}</b><span class="muted small">${(LEAK_SRC[e.source] || LEAK_SRC.list)()}${e.checked_at ? ' · ' + L('gecontroleerd {t}', { t: ago(e.checked_at) }) : ' · ' + L('nog niet gecontroleerd')}</span></span>
      <span class="vh-counts">${e.error ? `<span class="warn-txt small" title="${esc(e.error)}">⚠️ ${esc(e.error)}</span>` : ''}
        ${pwN ? `<span class="vpill sev4" title="${L('met wachtwoorden')}">🔑 ${pwN}</span>` : ''}${openN - pwN ? `<span class="vpill sev2" title="${L('open lekken')}">${openN - pwN}</span>` : ''}
        ${!e.breaches.length && e.checked_at ? `<span class="good-txt">✔ ${L('in geen enkel bekend lek')}</span>` : ''}${e.breaches.length && !openN ? `<span class="muted small">✔ ${L('alles afgehandeld')}</span>` : ''}</span></summary>
    ${bs.length ? `<div class="leak-rows">${bs.map((b) => `
      <div class="leak-row ${b.handled_at ? 'done' : ''} ${b.passwords ? 'pw' : ''}">
        <div class="leak-main"><div><b>${esc(b.title || b.name)}</b> <span class="muted small">${b.domain ? esc(b.domain) + ' · ' : ''}${b.breach_date ? esc(fmtDay(b.breach_date)) : '?'}${+b.pwn_count ? ' · ' + L('{n} accounts', { n: (+b.pwn_count).toLocaleString(LOCALE) }) : ''}${+b.verified ? '' : ' · ' + L('niet geverifieerd')}</span></div>
          <div class="leak-classes">${(b.data_classes_l || []).map((c, i) => `<span class="lc ${b.data_classes[i] === 'Passwords' ? 'pw' : ''}">${esc(c)}</span>`).join('')}</div>
          ${b.description ? `<details class="small leak-desc"><summary>${L('Wat is er gebeurd?')}</summary><p>${esc(b.description)}</p></details>` : ''}
          ${b.handled_at ? `<div class="muted small">✔ ${L('Afgehandeld {t} door {by}', { t: ago(b.handled_at), by: esc(b.handled_by || '?') })}</div>` : ''}</div>
        <button type="button" class="btn small-btn" data-lhandle="${esc(b.name)}" data-email="${esc(e.email)}" data-done="${b.handled_at ? '0' : '1'}">${b.handled_at ? '↺ ' + L('Heropenen') : '✔ ' + L('Afgehandeld')}</button>
      </div>`).join('')}</div>` : (e.breaches.length ? `<p class="muted small" style="margin:.4rem 0 0">${L('Geen lekken in deze selectie.')}</p>` : '')}
  </details>`;
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
    if (!fxPass('vulns', f)) return false;
    if (vulnFilter === 'ignored' ? !f.ignored : f.ignored && !fxHas('vulns', 'ign')) return false;
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
  const sevSel = $('#vuln-sev').value;
  const WORD = { 4: 'Critical', 3: 'High', 2: 'Medium', 1: 'Low', 0: L('Onbekend') };
  $('#vuln-counts').innerHTML = [4, 3, 2, 1, 0].filter((sv) => n[sv] || sv >= 1)
    .map((sv) => `<button type="button" class="hc vsev${sv} ${n[sv] ? '' : 'zero'} ${sv >= 2 && sevSel === String(sv) ? 'on' : ''}" ${sv >= 2 ? `data-vsev="${sv}" title="${esc(L('Klik: vanaf deze ernst'))}"` : ''}><span class="hc-dot"></span><b>${n[sv]}</b> <span class="hc-w">${WORD[sv]}</span></button>`).join('')
    + `<button type="button" class="hc vkev ${kev ? '' : 'zero'} ${vulnFilter === 'kev' ? 'on' : ''}" data-vf="kev" title="${esc(L('Actief misbruikt (CISA KEV)'))}"><span class="hc-dot"></span><b>${kev}</b> <span class="hc-w">⚠️ ${L('Actief misbruikt')}</span></button>`;
  const scanned = d.hosts.filter((h) => h.scanned_at && !h.error);
  const failedH = d.hosts.filter((h) => h.error);
  $('#vuln-info').textContent = d.hosts.length ? (d.last_scan ? L('laatste scan {t}', { t: ago(d.last_scan) }) + ' · ' : '') + L('dagelijks om {t}', { t: d.scan_time }) : '';
  // Dezelfde scanfout op meerdere servers: één melding bovenaan
  const errs = {};
  failedH.forEach((h) => (errs[h.error] ||= []).push(h.name));
  const shared = Object.entries(errs).filter(([, hs]) => hs.length > 1);
  vulnSharedErr = new Set(shared.map(([e]) => e));
  $('#vuln-banner').innerHTML = shared.map(([e, hs]) => `<div class="v-banner">⚠️ <span><b>${L('Scan mislukt op {n} servers', { n: hs.length })}</b> (${esc(hs.join(', '))}): ${esc(e)}</span>
    <button type="button" class="btn need-admin" data-vscan>🔍 ${L('Opnieuw scannen')}</button></div>`).join('');
  // Kerncijfers
  const pk = new Set(live.map((f) => f.host_id + '|' + f.package));
  const fixPk = new Set(live.filter((f) => f.fixed).map((f) => f.host_id + '|' + f.package));
  const kevF = live.find((f) => f.kev);
  const noList = d.hosts.filter((h) => !h.packages).length;
  const [hh, mm] = String(d.scan_time || '03:30').split(':').map(Number);
  const next = new Date(); next.setHours(hh, mm, 0, 0); if (next <= new Date()) next.setDate(next.getDate() + 1);
  const left = Math.round((next - new Date()) / 60000);
  $('#vuln-kpis').innerHTML = `
    <div class="kpi kpi-main ${failedH.length ? 'warn' : ''}"><div class="kpi-txt"><div class="kpi-lbl">${L('Servers gescand')}</div>
      <div class="kpi-val">${scanned.length}<small>/${d.hosts.length}</small></div>
      <div class="kpi-sub">${[failedH.length ? L('{n} met een fout', { n: failedH.length }) : '', noList ? L('{n} zonder softwarelijst', { n: noList }) : ''].filter(Boolean).join(' · ') || L('allemaal gelukt')}</div></div></div>
    <div class="kpi ${pk.size ? 'bad' : ''}"><div class="kpi-lbl">${L('Kwetsbare pakketten')}</div><div class="kpi-val">${pk.size}</div>
      <div class="kpi-sub">${L('{n} kwetsbaarheden', { n: uniq(live).size })}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Met oplossing')}</div><div class="kpi-val ${fixPk.size ? 'good-txt' : ''}">${fixPk.size}</div>
      <div class="kpi-sub">${fixPk.size ? L('bij te werken met ⬆') : L('niets bij te werken')}</div></div>
    <div class="kpi ${kev ? 'bad' : ''}"><div class="kpi-lbl">${L('Actief misbruikt')}</div><div class="kpi-val">${kev}</div>
      <div class="kpi-sub">${kevF ? esc(kevF.cve + ' · ' + kevF.package) : L('geen (CISA KEV)')}</div></div>
    <div class="kpi"><div class="kpi-lbl">${L('Volgende scan')}</div><div class="kpi-val kpi-val-txt">${esc(d.scan_time)}</div>
      <div class="kpi-sub">${L('over {t}', { t: left >= 60 ? `${Math.floor(left / 60)} ${L('u')} ${left % 60} min` : `${left} min` })}</div></div>`;
  // Chips met aantallen
  const cntF = (k) => new Set(d.findings.filter((f) => (k === 'ignored' ? f.ignored : !f.ignored)
    && (k !== 'exposed' || (d.hosts.find((h) => +h.id === +f.host_id)?.exposed || {})[f.package]) && (k !== 'fix' || f.fixed) && (k !== 'kev' || f.kev)).map((f) => f.host_id + '|' + f.cve)).size;
  $('#vuln-filter').innerHTML = [['all', L('Alles')], ['exposed', '🌐 ' + L('Bereikbaar')], ['fix', L('Met oplossing')], ['kev', '⚠️ ' + L('Actief misbruikt')], ['ignored', L('Geaccepteerd')]]
    .map(([k, l]) => `<button type="button" class="dchip ${vulnFilter === k ? 'on' : ''}" data-vf="${k}">${l} <b>${cntF(k)}</b></button>`).join('');
  $('#vuln-badge').hidden = !(n[4] + kev);
  $('#vuln-badge').textContent = n[4] + kev || '';
  if (!d.hosts.length) {
    $('#vuln-hosts').innerHTML = `<div class="card"><p class="muted">${L('Nog geen softwarelijsten. Werk de agents bij naar {v} of nieuwer: dan sturen ze hun pakketten mee.', { v: '1.47.0' })}</p></div>`;
    return;
  }
  const byHost = {};
  list.forEach((f) => (byHost[f.host_id] ||= []).push(f));
  fxRender('vulns');
  // Met een filter alleen de servers met treffers
  const hostsShown = fxActive('vulns') ? d.hosts.filter((h) => (byHost[h.id] || []).length) : d.hosts;
  $('#vuln-hosts').innerHTML = hostsShown.length ? hostsShown.map((h) => vulnHostCard(h, byHost[h.id] || [])).join('')
    : `<div class="card"><p class="muted" style="margin:0">${L('Niets gevonden.')}</p></div>`;
}

function vulnHostCard(h, fs) {
  const sharedErr = h.error && vulnSharedErr.has(h.error);   // staat al bovenaan als één melding
  // Per pakket: alle CVE's, ernstigste eerst; pakketten met de ergste en bereikbare bovenaan
  const pk = {};
  fs.forEach((f) => (pk[f.package] ||= { name: f.package, version: f.version, fixed: f.fixed, binaries: f.binaries, list: [] }).list.push(f));
  const rank = (p) => Math.max(...p.list.map((f) => f.severity * 2 + (f.kev ? 10 : 0))) + (h.exposed[p.name] ? 1 : 0);
  const pkgs = Object.values(pk).sort((a, b) => rank(b) - rank(a) || a.name.localeCompare(b.name));
  pkgs.forEach((p) => {
    p.list.sort((a, b) => (b.kev - a.kev) || (b.severity - a.severity) || (b.cvss || 0) - (a.cvss || 0));
    p.fixed = p.list.map((f) => f.fixed).filter(Boolean).sort().pop() || null;
  });
  const cnt = [4, 3, 2, 1, 0].map((s) => [s, new Set(fs.filter((f) => f.severity === s).map((f) => f.cve)).size]).filter(([, c]) => c);
  // Dicht, tenzij je hem open had (per account); met zoeken of een filter tijdelijk open als er treffers zijn
  const narrowed = !!$('#vuln-search').value.trim() || fxActive('vulns');
  if (!narrowed) vulnTmp.h.clear();
  const open = narrowed ? vulnTmp.h.get(+h.id) ?? fs.length > 0 : vulnSaved('h', h.id);
  const up = h.upgrade;
  const upTxt = !up ? '' : up.state === 'queued' || up.state === 'running'
    ? `<a href="#" data-vact="progress" data-host="${h.id}" title="${L('Voortgang tonen')}">⏳ ${up.state === 'queued' ? L('Bijwerken gevraagd; de agent pakt het op bij zijn volgende rapport')
      : L('Bezig met bijwerken') + (up.pct ? ` ${up.pct}%` : '')}</a>`
    : up.state === 'ok' ? `<span class="good-txt">✔ ${L('Bijgewerkt {t}', { t: ago(up.at) })}: ${esc((up.pkgs || []).join(' '))}</span>`
    : `<span class="bad">✘ ${L('Bijwerken mislukt {t}', { t: ago(up.at) })}</span>`;
  const notes = [
    h.error && !sharedErr ? `<span class="warn-txt">⚠️ ${esc(h.error)}</span>` : '',   // ook in de kop; hier volledig
    h.agent_old ? `<span class="warn-txt">⬆ ${L('Agent {v} of nieuwer nodig voor de softwarelijst', { v: '1.47.0' })}</span>` : '',
    h.reboot ? `<span class="warn-txt" title="${esc(L('Draait: {a} · geïnstalleerd: {b}', { a: h.kernel, b: h.reboot }))}">↻ ${L('Herstart nodig voor de nieuwe kernel')}</span>` : '',
    h.restart && h.restart.length ? `<span class="warn-txt" title="${esc(h.restart.join(', '))}">↻ ${L('{n} service(s) draaien nog met oude bestanden', { n: h.restart.length })}</span>` : '',
    upTxt,
  ].filter(Boolean).join(' · ');
  const fixable = pkgs.filter((p) => p.fixed).map((p) => p.name);
  const total = cnt.reduce((a, [, c]) => a + c, 0);
  const worst = cnt.length ? cnt[0][0] : -1;
  const hh = state && state.hosts ? findHost(h.id) : null;
  return `<details class="card vuln-host vh-w${worst} ${h.error ? 'vh-err' : ''}" data-host="${h.id}" ${open ? 'open' : ''}>
    <summary><span class="host-ic" aria-hidden="true">${hh ? hostIcon(hh) : '🖥️'}</span>
      <span class="vh-name"><b>${esc(h.name)}</b><span class="muted small">${esc(h.os || '')}${h.packages ? ' · ' + L('{n} pakketten', { n: h.packages }) : ''}${h.scanned_at ? ' · ' + L('gescand {t}', { t: ago(h.scanned_at) }) : ''}</span></span>
      <span class="vh-counts">${h.error ? `<span class="warn-txt small" title="${esc(h.error)}">⚠️ ${sharedErr ? L('scan mislukt (zie boven)') : esc(h.error.length > 70 ? h.error.slice(0, 70) + '…' : h.error)}</span>` : ''}${cnt.length ? cnt.map(([s, c]) => `<span class="vpill sev${s}" title="${c} ${VULN_SEV[s][1]}">${c}</span>`).join('') : (h.scanned_at && !h.error ? `<span class="good-txt">✔ ${L('geen bekende kwetsbaarheden')}</span>` : '')}</span>
      ${total ? `<span class="sevbar">${cnt.map(([s, c]) => `<i class="sev${s}" style="width:${(c / total * 100).toFixed(1)}%"></i>`).join('')}</span>` : ''}</summary>
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
  if (det && $(':scope > summary', det).contains(e.target)) {   // open/dicht onthouden (per account)
    setTimeout(() => { vulnTmp.h.set(+det.dataset.host, det.open); vulnOpenSave('h', [[+det.dataset.host, det.open]]); }, 0);
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
