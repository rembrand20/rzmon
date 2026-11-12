'use strict';
// RzSense-interface. Gebouwd uit public/assets/src/*.js met tools/build-js.sh: wijzig de modules, niet public/assets/app.js.

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
/* 1.161.0: uitleg als één regel met "Meer info"; de volledige tekst klapt open. Altijd dicht tot je hem opent,
   en weer dicht zodra je van pagina wisselt (xpClose in showTab en showSettingsSection). */
const xpHtml = (short, more, cls = '') => `<div class="xp${cls ? ' ' + cls : ''}"><p class="xp-s"><span>${short}</span> <button type="button" class="xp-btn" aria-expanded="false">${esc(L('Meer info'))}</button></p><div class="xp-d" hidden><p>${String(more).split(/\s+(?=<b>[^<]{2,40}:<\/b>)/).join('</p><p>')}</p></div></div>`;
function xpClose(root = document) {
  root.querySelectorAll('.xp.open').forEach((x) => {
    x.classList.remove('open');
    const d = x.querySelector('.xp-d'), b = x.querySelector('.xp-btn');
    if (d) d.hidden = true;
    if (b) b.setAttribute('aria-expanded', 'false');
  });
}
document.addEventListener('click', (e) => {
  const b = e.target.closest && e.target.closest('.xp-btn');
  if (!b) return;
  e.preventDefault();
  const x = b.closest('.xp'), d = x && x.querySelector('.xp-d');
  if (!d) return;
  const open = !x.classList.contains('open');
  x.classList.toggle('open', open);
  d.hidden = !open;
  b.setAttribute('aria-expanded', String(open));
});

const TYPES = {
  ping:    { label: L('Ping'),          target: null,            unit: 'ms', warn: 100, crit: 500, help: L('Gemiddelde responstijd van 3 pings. Geen antwoord = CRITICAL.') },
  tcp:     { label: L('TCP poort'),     target: L('Poort'),         unit: 'ms', warn: 500, crit: 2000, ph: '22', help: L('Controleert of de poort open staat (bv. 22 SSH, 3306 MariaDB, 443 HTTPS).') },
  http:    { label: L('HTTP(S)'),       target: L('URL of pad'),    unit: 'ms', warn: 1000, crit: 3000, ph: L('https://example.com/ of /status'), help: L('HTTP status ≥ 400 = CRITICAL. SSL-certificaat < 14 dagen = WARNING (voor eigen drempels: gebruik de check SSL-certificaat).') },
  ssl:     { label: L('SSL-certificaat'), target: L('Host[:poort] of bestand'), unit: 'dagen', warn: 21, crit: 7, low: true, ph: L('example.com, localhost:443 of /etc/ssl/mijn.crt'), help: L('Waarschuwt als het certificaat bijna verloopt. Leeg = adres van de server op poort 443. Werkt op elke TLS-poort (8443, 993, 8883…), ook op internet. Een bestand (.crt/.pem) of localhost:poort leest de agent op de server zelf; kies een bestand uit de lijst die de agent vond. Bij een keten telt het certificaat dat het eerst verloopt.') },
  disk:    { label: L('Diskruimte'),    target: L('Mount / pad'),   unit: '%',  warn: 80, crit: 90, ph: '/', help: L('Percentage gebruikt. Op agent-servers: mount zoals de agent meldt.') },
  cpu:     { label: L('CPU'),           target: null,            unit: '%',  warn: 85, crit: 95, help: L('CPU-gebruik gemeten over 2 seconden, met load average in het bericht. Tip: zet "alarm na N fouten" op 3+ om korte pieken te negeren.') },
  ram:     { label: L('RAM'),           target: null,            unit: '%',  warn: 85, crit: 95, help: L('Percentage geheugen in gebruik (op basis van MemAvailable, dus cache telt niet mee). Swap staat in het bericht.') },
  podman:  { label: L('Containers (algemeen)'), target: null, unit: null, help: L('Algehele status van Podman en/of Docker op deze server: draait het, hoeveel containers draaien er, en zijn er containers gestopt met een fout, ongezond (healthcheck) of gepauzeerd, of pods niet in orde? Vereist de agent.') },
  container: { label: L('Container (Podman/Docker)'), target: L('Container'), unit: null, ph: L('naam, of gebruiker/naam (rootless)'), help: L('Eén check per container: de status (draait, healthcheck, herstarts) altijd, en daarnaast de CPU, RAM en disk die je hieronder aanvinkt, elk met eigen drempels. Kies de naam uit de lijst die de agent meldt.') },
  log:     { label: L('Logbestand (zoeken)'), target: L('Bron'), unit: 'treffers', warn: 5, crit: 20, ph: L('/var/log/secure, journal, journal:sshd of kernel'), help: L('De agent leest de nieuwe regels van een logbestand of het systemlog en telt hoeveel er op je zoekpatroon passen in de laatste periode (Instellingen → Alarmgedrag, standaard 15 min). Kies hieronder een voorinstelling of stel zelf in.') },
  command: { label: L('Commando'), target: null, unit: null, help: L('De agent voert een commando uit op de server, en RzSense vergelijkt de uitvoer met het antwoord dat je verwacht. Alleen als commando\'s voor deze server aan staan (Instellingen → Agents → Commando-checks).') },
  path:    { label: L('Pad (traceroute)'), target: L('Doel'), unit: 'ms', warn: 50, crit: 150, ph: L('IP-adres of hostnaam, bv. 1.1.1.1'), help: L('Meet vanaf deze server het pad naar een doel: vertraging, jitter en verlies per hop, de switches (UniFi en de apparaten die je hieronder opgeeft) en de doorvoer (iperf3) binnen je netwerk. Agent 1.78.0 of nieuwer, met mtr of traceroute.') },
  truenas: { label: L('TrueNAS'), target: null, unit: null, help: L('Via de agent op TrueNAS SCALE (agent 1.77.0 of nieuwer, als root): TrueNAS zelf uitgelezen met midclt, zonder API-sleutel.') },
  pve:     { label: L('Proxmox VE'), target: null, unit: null, help: L('Via de agent op een Proxmox-host (agent 1.61.0 of nieuwer, als root): de guests (VM\'s en containers), back-ups, opslag en ZFS. Kies hieronder wat je wilt bewaken.') },
  ha:      { label: L('Home Assistant'), target: null, unit: null, help: L('Via de Home Assistant-add-on: de laatste automatische back-up, integraties die niet geladen zijn, of de systeemstatus van de Supervisor. Kies hieronder wat je wilt bewaken.') },
  snmp:    { label: L('SNMP'),          target: null,            unit: null, help: L('RzSense leest het apparaat zelf uit via SNMP (switches, access points, NAS, UPS, printers, Proxmox…). Interfaces, schijven en voorraden kies je het makkelijkst via 📡 SNMP-checks bij de server.') },
  reboot:  { label: L('Uptime'), target: null, unit: 'uur', help: L('Ziet wanneer de server opnieuw is opgestart (de uptime wordt korter) en stuurt dan een ℹ️ info-melding. De check zelf is altijd OK. Info staat standaard uit: zet hem aan bij Instellingen → 🔔 Meldingen (rij Info), of alleen voor deze check bij 🔔 Meldingen hieronder. Op agent-servers is agent 1.46.0 of nieuwer nodig.') },
  heartbeat: { label: L('Heartbeat'), target: L('Naam (voor rzmon-hb)'), unit: null, ph: 'backup-db', help: L('Een script, cronjob of apparaat meldt zich zelf bij RzSense (rzmon-hb op een server met de agent, of de geheime URL). Komt er binnen het schema plus de speling niets binnen: WARNING, na twee keer de speling CRITICAL. Mislukt (fail) = CRITICAL. Met start en klaar meet RzSense de duur.') },
  process: { label: L('Proces'),        target: L('Naam of patroon'), unit: null, ph: 'nginx', help: L('Draait het proces? Op naam (zoals in ps of top) of op een patroon in de opdrachtregel, eventueel alleen van één gebruiker. Draait het niet: CRITICAL. Daarnaast optioneel het aantal en het CPU- en RAM-gebruik. Is het herstart, dan komt er een ℹ️ info-melding. Op agent-servers kies je hem uit de lijst; agent 1.59.0 of nieuwer nodig.') },
  // 1.138.0: DNS, tijd, schijfgezondheid, UPS en MariaDB
  dns:     { label: L('DNS'), target: L('Naam'), unit: 'ms', warn: 200, crit: 1000, ph: 'example.com', help: L('Geeft deze naam antwoord? Kies het soort record en eventueel een eigen DNS-server (bv. je router of AdGuard) en de waarde die je verwacht. Geen antwoord of een andere waarde = CRITICAL; de waarde is de responstijd.') },
  ntp:     { label: L('Tijd (NTP)'), target: L('NTP-server (optioneel)'), unit: 'ms', warn: 100, crit: 1000, ph: L('leeg = klok van deze server'), help: L('Loopt de klok goed? Op een server met de agent: de klok van die server (chrony of systemd-timesyncd), niet gesynchroniseerd = WARNING. Of vul een NTP-server in (bv. je router): dan vraagt RzSense die zelf. De waarde is de afwijking in ms.') },
  smart:   { label: L('Schijfgezondheid (SMART)'), target: L('Schijf'), unit: '°C', warn: 55, crit: 65, ph: L('leeg = alle schijven'), help: L('Via de agent en smartctl (smartmontools): de gezondheidstest, de temperatuur (de drempels hierboven), slijtage van SSD\'s en NVMe (80% = WARNING, 90% = CRITICAL) en vervangen of twijfelachtige sectoren (WARNING). Schijven in slaapstand worden niet wakker gemaakt. Agent 1.70.0 of nieuwer.') },
  ups:     { label: L('UPS (NUT)'), target: L('UPS'), unit: '%', warn: 50, crit: 20, low: true, ph: L('leeg = de eerste'), help: L('Via de agent en NUT (upsc) op de server waar de UPS aan hangt: op de batterij = WARNING, batterij bijna leeg = CRITICAL, en de lading met de drempels hierboven. In het bericht de resterende tijd en de belasting. Agent 1.70.0 of nieuwer.') },
  mysql:   { label: L('Database (MariaDB, MySQL, PostgreSQL)'), target: L('Adres[:poort]'), unit: 'ms', warn: 500, crit: 2000, ph: L('leeg = adres van de server (poort 3306 of 5432)'), help: L('Bewaakt een database echt van binnen: verbindingen, lopende en trage query\'s, cache, replicatie, grootte en (PostgreSQL) vacuum. Kies het overzicht (alles in één check) of één waarde met eigen drempels en grafiek. Op een server met de agent leest de agent de database lokaal uit, zonder dat hij open op het netwerk hoeft. Alleen lezen; het wachtwoord gaat nooit terug naar de browser.') },
  // 1.170.0: domein, MQTT-broker en VPN
  domain:  { label: L('Domein (verloopdatum of e-mail)'), target: L('Domein'), unit: 'dagen', warn: 30, crit: 14, low: true, ph: 'example.nl', help: L('Wanneer verloopt je domein? RzSense vraagt het hooguit twee keer per dag op bij de registry (RDAP, de opvolger van WHOIS). Sommige registries, zoals die van .nl, publiceren geen verloopdatum: dan controleert RzSense of het domein nog geregistreerd en actief is (status als opheffing of quarantaine = CRITICAL).') },
  mqtt:    { label: L('MQTT-broker'), target: L('Adres[:poort]'), unit: 'ms', warn: 500, crit: 2000, ph: L('leeg = adres van de server (poort 1883, met TLS 8883)'), help: L('RzSense logt in bij de broker (bv. Mosquitto), stuurt een testbericht en ontvangt het zelf weer: de waarde is die rondgang in ms. Lukt inloggen niet, of komt het bericht niet terug, dan CRITICAL. Daarnaast kun je een topic bekijken, bv. zigbee2mqtt/bridge/state met "online".') },
  vpn:     { label: L('VPN (Gluetun of WireGuard)'), target: null, unit: null, help: L('Werkt je VPN? Kies hieronder Gluetun of WireGuard.') },
  // 1.187.0: verkeer van een apparaat (uit UniFi)
  traffic: { label: L('Verkeer (UniFi)'), target: L('MAC- of IP-adres'), unit: 'Mbit/s', warn: 50, crit: 200, ph: L('leeg = adres van de server'), help: L('Hoeveel verkeer een apparaat doet, uit de tellers van UniFi (Netwerk → Verkeer per apparaat). Kies wat: de snelheid nu of de hoeveelheid vandaag. Warning en critical gaan af als het meer is dan je drempel.') },
  // 1.157.0: de internetlijn, vanaf de server van RzSense
  internet: { label: L('Internet'), target: null, unit: 'ms', warn: 50, crit: 150, help: L('Bewaakt je internetlijn vanaf de server van RzSense. Kies wat: ping, responstijd, download of upload. De snelheidstest draait op vaste tijden; abonnement, drempels en tijden staan bij Instellingen → Monitoring → Internet.') },
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
  status: { label: L('Status (draait, healthcheck, herstarts)'), unit: null, help: L('OK (Running) als de container draait. CRITICAL (Failed) als hij met een foutcode gestopt, vastgelopen of ongezond (healthcheck) is. WARNING (Stopped) als hij netjes gestopt is met exitcode 0, ook WARNING als hij gepauzeerd is of in het afgelopen kwartier automatisch herstart is (crash).') },
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
/** 1.174.0: TrueNAS SCALE: wat een truenas-check bewaakt */
const TN_METRICS = {
  alerts: { label: L('TrueNAS-meldingen'), unit: null, help: L('De meldingen van TrueNAS zelf (de bel rechtsboven in TrueNAS): een melding WARNING = WARNING; ERROR, CRITICAL of erger = CRITICAL. Meldingen die je in TrueNAS wegklikt tellen niet mee.') },
  tasks: { label: L('Taken (replicatie, snapshots, cloud sync, rsync)'), unit: null, target: L('Taak (leeg = alle)'), ph: L('leeg = alle, of een deel van de naam'), help: L('Is de laatste keer van een taak mislukt (ERROR of FAILED), dan CRITICAL, met de foutmelding. Alleen taken die aan staan.') },
  apps: { label: L('Apps'), unit: null, target: L('App (leeg = alle)'), ph: L('leeg = alle, of de naam van één app'), help: L('Alle apps: CRITICAL als een app vastgelopen is (CRASHED); gestopte apps staan in de melding. Eén app: CRITICAL als die niet draait. Een beschikbare app-update staat in de melding.') },
  update: { label: L('TrueNAS-update'), unit: null, help: L('WARNING als er een nieuwe TrueNAS-versie klaarstaat, of als TrueNAS bijgewerkt is en op een herstart wacht. Elke 6 uur gecontroleerd.') },
  temp: { label: L('Schijftemperatuur'), unit: '°C', warn: 50, crit: 60, target: L('Schijf (leeg = de warmste)'), ph: L('leeg = alle, of bv. sda'), help: L('De temperatuur van de schijven volgens TrueNAS; de warmste telt. Elk kwartier gemeten.') },
};
const isTnHost = (h) => h.metrics.includes('truenas');
/** VPN (1.170.0): Gluetun via zijn control server, of WireGuard via de agent */
const VPN_METRICS = {
  gluetun: { label: L('Gluetun (control server)'), target: L('Control server'), unit: null, ph: L('leeg = http://<adres van de server>:8000'), help: L('RzSense vraagt de control server van Gluetun of de VPN draait en via welk publiek IP het verkeer gaat. Is dat je eigen IP (van de internetlijn van RzSense), dan lekt de VPN: CRITICAL. Staat de control server achter een API-sleutel, vul die hieronder in.') },
  wg: { label: L('WireGuard (via de agent)'), target: L('Peer'), unit: 's', warn: 300, crit: 900, ph: L('leeg = alle peers, of bv. wg0'), help: L('Hoe lang geleden de laatste handshake was (de oudste peer telt). Met verkeer of PersistentKeepalive is dat elke 2 minuten; een tunnel zonder verkeer en zonder keepalive maakt geen handshakes. Agent 1.75.0 als root nodig.') },
};
/** 1.227.0: Domein-check: de verloopdatum, of de e-mailbeveiliging van het domein */
const DOMAIN_METRICS = {
  '': { label: L('📅 Verloopdatum (RDAP)'), help: '' },
  mail: { label: L('✉️ E-mailbeveiliging (SPF, DKIM, DMARC)'), unit: null, nothresh: true, help: L('Kan iemand anders mail versturen uit naam van dit domein? RzSense kijkt in DNS naar SPF, DKIM, DMARC, MTA-STS en TLS-rapporten, en zegt per punt wat er mis is en welk DNS-record je kunt plakken. Geen SPF of DMARC = CRITICAL; DMARC op p=none of een korte DKIM-sleutel = WARNING. Eens per 3 uur opnieuw opgezocht.') },
};
/** 1.200.0: wat een databasecheck bewaakt (zoals Db::UNITS); only = alleen bij die database */
const DB_METRICS = {
  overview: { label: L('Overzicht (alles in één check)'), unit: 'ms', warn: 500, crit: 2000, help: L('Eén check met de ergste van alles: geen verbinding = CRITICAL; 90% van de verbindingen in gebruik, een replica die stilstaat of achterloopt, een query die te lang loopt, "idle in transaction" en (PostgreSQL) wraparound = WARNING of CRITICAL. De meetwaarde is hoe lang de meting duurde.') },
  conn: { label: L('Verbindingen in gebruik'), unit: '%', warn: 80, crit: 95, help: L('Hoeveel procent van max_connections in gebruik is.') },
  active: { label: L('Actieve query\'s'), unit: "query's", warn: 20, crit: 50, help: L('Hoeveel query\'s er nu tegelijk bezig zijn.') },
  qps: { label: L('Query\'s per seconde'), unit: '/s', warn: null, crit: null, help: L('Gemiddeld sinds de vorige check. Handig met slimme drempels of alleen voor de grafiek.') },
  slow: { label: L('Trage query\'s'), unit: '/min', warn: 5, crit: 20, only: 'mysql', help: L('Trage query\'s per minuut (long_query_time van de database).') },
  cache: { label: L('Cache-hit (uit het geheugen)'), unit: '%', warn: 95, crit: 90, low: true, help: L('Welk deel van het lezen uit het geheugen kwam (InnoDB buffer pool of shared buffers). Laag = te weinig geheugen voor de database.') },
  repl: { label: L('Replicatie (achterstand)'), unit: 's', warn: 60, crit: 300, help: L('Op een replica: hoeveel seconden hij achterloopt; staat de replicatie stil, dan CRITICAL. Op een primaire database: hoeveel replica\'s verbonden zijn.') },
  size: { label: L('Grootte'), unit: 'GB', warn: null, crit: null, help: L('Alle databases samen, of alleen de database hieronder. MariaDB/MySQL: eens per half uur gemeten.') },
  longq: { label: L('Langste query'), unit: 's', warn: 60, crit: 300, help: L('Hoe lang de langst lopende query al bezig is.') },
  deadlocks: { label: L('Deadlocks'), unit: 'deadlocks', warn: 1, crit: 10, help: L('Aantal deadlocks sinds de vorige check.') },
  tmpdisk: { label: L('Tijdelijke tabellen op schijf'), unit: '%', warn: 25, crit: 50, only: 'mysql', help: L('Welk deel van de tijdelijke tabellen op schijf terechtkwam (te klein tmp_table_size).') },
  wrap: { label: L('Wraparound (transactie-ID)'), unit: '%', warn: 50, crit: 75, only: 'pgsql', help: L('Hoe ver de oudste database op weg is naar transaction ID wraparound. Bij 100% stopt PostgreSQL; VACUUM (FREEZE) lost het op.') },
  idle: { label: L('Idle in transaction'), unit: 'verbindingen', warn: 1, crit: 5, only: 'pgsql', help: L('Verbindingen die al langer dan 5 minuten "idle in transaction" staan: ze houden locks vast en blokkeren vacuum.') },
};
/** Internet (1.157.0): wat een internet-check meet */
const INET_METRICS = {
  ping: { label: L('Ping (pingtijd, jitter, verlies)'), unit: 'ms', warn: 50, crit: 150, help: L('Pingt 1.1.1.1 en 8.8.8.8 (5 keer) en je router. De waarde is de beste pingtijd. Verlies van 20% of meer, of één adres weg = WARNING; geen van beide = CRITICAL, met in de melding of je router nog antwoordt (dan zit het probleem buiten).') },
  resp: { label: L('Responstijd (DNS, verbinden, eerste antwoord)'), unit: 'ms', warn: 500, crit: 2000, help: L('Hoe snel google.com en cloudflare.com antwoorden, met een nieuwe verbinding: DNS, verbinden, TLS en het eerste antwoord. Eén site weg = WARNING, allebei = CRITICAL.') },
  down: { label: L('Download (snelheidstest)'), unit: 'Mbit/s', nothresh: true, help: L('Uit de laatste snelheidstest. Warning en critical zijn een percentage van je abonnement (Instellingen → Monitoring → Internet).') },
  up: { label: L('Upload (snelheidstest)'), unit: 'Mbit/s', nothresh: true, help: L('Uit de laatste snelheidstest. Warning en critical zijn een percentage van je abonnement (Instellingen → Monitoring → Internet).') },
};
/** 1.187.0: wat een verkeer-check meet */
const TRAFFIC_METRICS = {
  rate: { label: L('Snelheid nu (download + upload)'), unit: 'Mbit/s', warn: 50, crit: 200, help: L('De snelheid volgens UniFi bij de laatste meting (elke 5 minuten).') },
  up_rate: { label: L('Upload nu'), unit: 'Mbit/s', warn: 10, crit: 50, help: L('Alleen wat het apparaat verstuurt: handig voor camera\'s en slimme apparaten die niet veel horen te versturen.') },
  day: { label: L('Vandaag (download + upload)'), unit: 'GB', warn: 20, crit: 50, help: L('Hoeveel het apparaat vandaag deed, vanaf middernacht.') },
  up_day: { label: L('Upload vandaag'), unit: 'GB', warn: 2, crit: 10, help: L('Hoeveel het apparaat vandaag verstuurde, vanaf middernacht.') },
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
  if (c.type === 'internet') return { ...TYPES.internet, ...(INET_METRICS[c.metric] || INET_METRICS.ping), label: L('Internet') };
  if (c.type === 'traffic') return { ...TYPES.traffic, ...(TRAFFIC_METRICS[c.metric] || TRAFFIC_METRICS.rate), label: L('Verkeer') };   // 1.187.0
  if (c.type === 'vpn') return { ...TYPES.vpn, ...(VPN_METRICS[c.metric] || VPN_METRICS.gluetun), label: 'VPN' };   // 1.170.0
  if (c.type === 'domain' && c.metric === 'mail') return { ...TYPES.domain, ...DOMAIN_METRICS.mail, unit: '/100', low: true };   // 1.247.2: score, geen dagen
  if (c.type === 'truenas') return { ...TYPES.truenas, ...(TN_METRICS[c.metric] || TN_METRICS.alerts), label: 'TrueNAS' };   // 1.174.0
  return c.type === 'container' && c.metric ? { ...TYPES.container, ...METRICS[c.metric], label: TYPES.container.label } : TYPES[c.type];
}

const MODES = { local: L('Lokaal (deze server)'), agent: L('Remote met agent'), remote: L('Remote (alleen netwerk)') };

let state = { hosts: [], alerts: [] };
// Omgekeerde status (zie Checks::REVERSIBLE)
const REVERSIBLE = ['ping', 'tcp', 'http', 'service', 'container', 'podman', 'command', 'ha', 'dns', 'mysql'];
// 1.107.0: slimme drempels (zoals Smart::SPEC): welke checks kunnen leren, en het standaard vangnet
const SMART_GUARD = { ping: 1000, tcp: 3000, http: 10000, cpu: null, ram: 98, 'snmp:cpu': null, 'snmp:mem': 98, 'snmp:if': 98, 'snmp:load': null, 'snmp:temp': 85,
  'snmp:ups_load': 98, 'snmp:ups_temp': 45, 'snmp:syno_system': 75, 'snmp:syno_disk': 62, 'snmp:unifi_cu': null, 'snmp:unifi_quality': null,
  'container:cpu': null, 'container:mem': 98, dns: 3000, ntp: 1000, mysql: 5000, 'internet:ping': 500, 'internet:resp': 5000 };
const smartKey = (type, metric) => (['snmp', 'container', 'internet'].includes(type) ? `${type}:${metric || ''}` : type === 'mysql' && metric && metric !== 'overview' ? `mysql:${metric}` : type);
const smartEligible = (type, metric) => smartKey(type, metric) in SMART_GUARD;
const smartGuard = (type, metric) => SMART_GUARD[smartKey(type, metric)];
const SMART_MODES = { manual: '✋ ' + L('Handmatig'), smart: '🧠 ' + L('Slim'), guard: '🧠🛡️ ' + L('Slim met vangnet') };
const SMART_SENS = { calm: L('rustig'), normal: L('normaal'), sharp: L('scherp') };
const REV_OK = [[0, L('OK (normaal)')], [1, '🟠 ' + L('WARNING (omgekeerd)')], [2, '🔴 ' + L('CRITICAL (omgekeerd)')]];
const REV_FAIL = [[0, '✅ OK'], [1, '🟠 WARNING'], [2, '🔴 CRITICAL']];
const STATUS_WORD = ['OK', 'WARNING', 'CRITICAL', L('onbekend')];
// 1.194.0: mDS (Mobile device summary) alleen op een telefoon
const MDS_MQ = window.matchMedia ? window.matchMedia('(max-width: 720px)') : { matches: false, addEventListener() {} };
let mdsData = null, mdsAt = 0, mdsAll = false;
// 1.196.0: op een telefoon staan deze pagina's onder "Meer" (de tabbalk houdt mDS, Checks, Meldingen, IDS en Meer)
const MORE_TABS = ['home', 'incidents', 'vulns', 'analytics', 'logs', 'net', 'settings'];

let stats = { uptime: {}, sparks: {} };
// Sorteren op de pagina Meldingen (per apparaat onthouden): { key: 'time'|'status'|'server'|'check'|'message'|'channels', dir: 1|-1 }
const ALERT_SORTS = { time: L('Tijd'), status: 'Status', server: 'Server', check: 'Check', message: L('Bericht'), channels: L('Kanalen') };
// IDS: tabblad, en Toegang (gegevens, filters, sorteren, open dagen)
const liveLoaded = {};   // wanneer meldingen en IDS voor het laatst geladen zijn (live bijwerken, 1.119.0)
// 1.119.0: hoeveel dagen Meldingen en IDS ophalen; ouder op verzoek (knop onderaan, of zoeken)
const histDays = { alerts: 7, ids: 2 };   // 1.217.0: IDS standaard de open incidenten en de laatste 2 dagen; ouder op verzoek
let idsTab = (() => { try { return localStorage.getItem('rzmon-ids-tab') || 'inc'; } catch (e) { return 'inc'; } })();
let accData = null, accFilter = 'all', accHost = '', accDayOpen = {}, accSsh = 'all', sshSess = null;
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
// ---------------------------------------------------------------- 1.202.0: laadteller per pagina
/* Opent een pagina (of een tabblad op een pagina), dan telt RzSense de verzoeken die daarbij vertrekken. Duurt het
   langer dan 0,4 s: een dun balkje bovenaan, een teller in het kruimelpad en een draaiertje bij het menu-item.
   Na 1,5 s: de pols-animatie over de pagina (niet over het hele scherm), met per onderdeel ✓ of …. Na 8 s: een
   melding met de gewone duur en ↻ Opnieuw. Verversen op de achtergrond telt nooit mee (dat loopt niet via pageWait). */
let curWait = null;    // de pagina die nu geopend wordt (alleen tijdens het synchroon starten van de laders)
let pageWait = null;   // de lopende wachttijd (met teller)
const WAIT_BAR = 400, WAIT_ANIM = 1500, WAIT_LONG = 8000;
const WAIT_LABELS = () => ({
  ids: L('Incidenten'), ids_access: L('Toegang'), blocks_overview: L('Blokkades'), ids_attack_flow: L('Aanvalskaart'),
  edr_feed: L('Live'), edr_rules: L('Regels'), ids_anom: L('Afwijkingen'), ids_mitre: L('MITRE'), ids_protect: L('Bescherming'),
  alerts: L('Meldingen'), home: L('Dashboard'), analytics: L('Analyse'), incidents: L('Incidenten'), vulns: L('Kwetsbaarheden'),
  hibp_overview: L('Datalekken'), net_devices: L('Apparaten'), net_discover: L('Ontdekken'), settings: L('Instellingen'),
  availability: L('Beschikbaarheid'), containers: L('Containers'), paths: L('Netwerkpaden'), mds: L('mDS'),
  log_search: L('Logregels'), log_facets: L('Bronnen'), log_overview: L('Binnenkomst'), log_watches: L('Vaste zoekacties'),
});
function waitTimes() { try { return JSON.parse(localStorage.getItem('rzmon-waits') || '{}') || {}; } catch (ex) { return {}; } }
function waitPageName(p) {
  if (p === 'settings') return L('Instellingen');
  if (p === 'account') return L('Mijn account');
  return crumbText($(`#main-tabs .tab[data-tab="${p}"] .tab-lbl`) || $(`#main-tabs .tab[data-tab="${p}"]`)) || p;
}
/** Begin met tellen voor deze pagina; een lopende telling voor dezelfde pagina loopt gewoon door. */
function pageWaitOpen(page) {
  if (pageWait && pageWait.page !== page) pageWaitEnd(pageWait, false);
  if (!pageWait) {
    const w = { page, t0: performance.now(), reqs: [], pending: 0, timer: 0, ui: 0, el: null, cv: null };
    w.add = (action) => {
      if (pageWait !== w) return null;
      const r = { action, done: false };
      w.reqs.push(r); w.pending++;
      return () => { if (r.done) return; r.done = true; w.pending--; if (pageWait === w) { pageWaitDraw(w); if (!w.pending) pageWaitEnd(w, true); } };
    };
    pageWait = w;
  }
  curWait = pageWait;
}
function pageWaitClose() {
  curWait = null;
  const w = pageWait;
  if (!w) return;
  if (!w.pending) { pageWaitEnd(w, false); return; }   // alles kwam uit het geheugen: niets te tonen
  if (!w.timer) w.timer = setInterval(() => pageWaitDraw(w), 100);
}
/** Een tabblad op een pagina: alleen tellen als er niet al een pagina geopend wordt (dan hoort het daarbij). */
function withPageWait(page, fn) {
  const own = !curWait;
  if (own) pageWaitOpen(page);
  try { fn(); } finally { if (own) pageWaitClose(); }
}
function pageWaitDraw(w) {
  if (pageWait !== w) return;
  const ms = performance.now() - w.t0;
  if (ms < WAIT_BAR) return;
  const s = (ms / 1000).toLocaleString(LOCALE, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  const name = waitPageName(w.page);
  // Balkje bovenaan: loopt langzaam naar 90 %
  let bar = $('#pg-bar');
  if (!bar) { bar = document.createElement('div'); bar.id = 'pg-bar'; bar.setAttribute('aria-hidden', 'true'); bar.innerHTML = '<i></i>'; document.body.appendChild(bar); }
  bar.classList.remove('done'); bar.hidden = false;
  bar.firstChild.style.width = (90 * (1 - Math.exp(-ms / 2500))).toFixed(1) + '%';
  // Teller in het kruimelpad
  const cr = $('#crumbs');
  if (cr && !cr.hidden) {
    let chip = $('.crumb-wait', cr);
    if (!chip) { chip = document.createElement('span'); chip.className = 'crumb-wait'; chip.setAttribute('role', 'status'); cr.appendChild(chip); }
    chip.textContent = L('{p} laden · {s} s', { p: name, s });
  }
  const tab = $(`#main-tabs .tab[data-tab="${w.page}"]`);
  if (tab) tab.classList.add('tab-wait');
  const panel = $('#tab-' + w.page);
  if (panel) panel.classList.add('pg-loading');
  // De pols over de pagina
  if (ms >= WAIT_ANIM && panel && !document.body.classList.contains('no-loader') && !$('#rz-boot')) {
    if (!w.el) {
      w.el = document.createElement('div');
      w.el.className = 'pg-wait';
      w.el.innerHTML = `<canvas aria-hidden="true"></canvas><div class="pg-wait-t"><b></b><span class="pg-wait-s"></span><small class="pg-wait-l"></small></div>`;
      panel.prepend(w.el);
      w.cv = $('canvas', w.el);
      const hosts = ((state && state.hosts) || []).filter((h) => +h.enabled).map((h) => ({ name: h.name, st: hostStatus(h) })).sort((a, b) => b.st - a.st);
      if (window.RzPulse) RzPulse.attach(w.cv, hosts, name);
      requestAnimationFrame(() => w.el && w.el.classList.add('on'));
    }
    $('b', w.el).textContent = L('{p} laden · {s} s', { p: name, s });
    const lab = WAIT_LABELS();
    const seen = new Set();
    $('.pg-wait-s', w.el).innerHTML = w.reqs.filter((r) => { const k = lab[r.action] || r.action; if (seen.has(k)) return false; seen.add(k); return !!lab[r.action]; })
      .map((r) => { const busy = w.reqs.some((x) => !x.done && (lab[x.action] || x.action) === lab[r.action]); return `<span class="${busy ? 'busy' : 'ok'}">${esc(lab[r.action])} ${busy ? '…' : '✓'}</span>`; }).join('');
    const l = $('.pg-wait-l', w.el);
    if (ms >= WAIT_LONG && !l.dataset.on) {
      l.dataset.on = '1';
      const avg = waitTimes()[w.page];
      const usual = avg ? ' ' + L('(meestal {s} s)', { s: (avg / 1000).toLocaleString(LOCALE, { maximumFractionDigits: 1 }) }) : '';
      l.innerHTML = `${esc(L('Dit duurt langer dan normaal') + usual)}. <button type="button" class="btn btn-sm">↻ ${esc(L('Opnieuw'))}</button>`;
      $('button', l).onclick = () => { const p = w.page; pageWaitEnd(w, false); showTab(p); };
    }
  }
}
function pageWaitEnd(w, record) {
  if (w.timer) clearInterval(w.timer);
  w.timer = 0;
  if (pageWait === w) pageWait = null;
  if (curWait === w) curWait = null;
  const ms = performance.now() - w.t0;
  if (record && ms > 150) {
    try { const t = waitTimes(); t[w.page] = Math.round(t[w.page] ? t[w.page] * 0.7 + ms * 0.3 : ms); localStorage.setItem('rzmon-waits', JSON.stringify(t)); } catch (ex) { /* niet erg */ }
  }
  const bar = $('#pg-bar');
  if (bar && !bar.hidden) { bar.firstChild.style.width = '100%'; bar.classList.add('done'); setTimeout(() => { if (!pageWait && bar.classList.contains('done')) bar.hidden = true; }, 350); }
  $$('.crumb-wait').forEach((c) => c.remove());
  $$('#main-tabs .tab-wait').forEach((t) => t.classList.remove('tab-wait'));
  $$('.tab-panel.pg-loading').forEach((p) => p.classList.remove('pg-loading'));
  if (w.el) {
    const el = w.el, cv = w.cv;
    w.el = null;
    el.classList.remove('on');
    setTimeout(() => { if (window.RzPulse) RzPulse.detach(cv); el.remove(); }, 300);
  }
}
async function api(action, body, params = {}, retried = false) {
  const w = curWait, rec = w ? w.add(action) : null;
  try { return await apiRaw(action, body, params, retried); } finally { if (rec) rec(); }
}

async function apiRaw(action, body, params = {}, retried = false) {
  const qs = new URLSearchParams({ action, ...params });
  const opts = {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  };
  // 1.179.2: verbinding weggevallen ("Failed to fetch"), bv. een oude keep-alive-verbinding die de server of de reverse
  // proxy net sloot: een leesverzoek (GET) nog twee keer proberen. Iets wijzigen (POST) nooit vanzelf opnieuw.
  let res;
  for (let i = 0; ; i++) {
    try { res = await fetch('api.php?' + qs, opts); break; }
    catch (ex) {
      if (body || i >= 2) throw Object.assign(new Error(L('Geen verbinding met RzSense (het netwerk of de proxy verbrak de verbinding). Probeer het opnieuw.')), { status: 0, network: true, cause: ex });
      await new Promise((ok) => setTimeout(ok, i ? 1200 : 300));
    }
  }
  // 1.223.1: geen JSON (foutpagina van de webserver of proxy): zeg wat er waarschijnlijk aan de hand is
  const data = await res.json().catch(() => ({ error: [502, 503, 504].includes(res.status)
    ? L('De webserver of reverse proxy brak het verzoek af (HTTP {c}): het duurde waarschijnlijk te lang. Probeer het nog eens.', { c: res.status })
    : res.status >= 500 ? L('Fout op de server (HTTP {c}). Kijk bij Instellingen → Systeem → Prestaties en fouten (PHP-fouten).', { c: res.status })
      : L('Ongeldig antwoord van server') + (res.status && res.status !== 200 ? ` (HTTP ${res.status})` : '') }));
  const tok = res.headers.get('X-CSRF-Token');
  if (tok) CSRF = tok;
  // 1.206.0: hoe lang het vinkje nog geldt (na het invullen van je wachtwoord)
  const cLeft = res.headers.get('X-RzMon-Confirm-Left');
  if (cLeft !== null) CONFIRM.until = Date.now() / 1000 + (+cLeft || 0);
  if (data.need_password) {   // het vinkje geldt hier niet (meer): in het venster het wachtwoordveld terug
    $$('#modal .confirm-sure').forEach((l) => { l.outerHTML = confirmField(l.dataset.word || '', true); });
  }
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
/* 1.162.0: weergave-keuzes per account (ui): grootte per soort scherm (telefoon of computer), sortering en periode.
   Eerst wat het account weet, anders wat deze browser nog weet (van vóór 1.162.0), anders de standaard. */
const uiDev = () => (window.matchMedia && window.matchMedia('(max-width: 700px)').matches ? 'm' : 'd');
function uiGet(k, ls, def) {
  const u = PREFS.ui || {};
  if (typeof u[k] === 'string' && u[k] !== '') return u[k];
  try { const v = localStorage.getItem(ls); if (v !== null && v !== '') return v; } catch (e) { /* geen opslag */ }
  return def;
}
function uiSet(k, ls, v) {
  try { localStorage.setItem(ls, v); } catch (e) { /* alleen het account */ }
  savePref('ui', { ...(PREFS.ui || {}), [k]: String(v) });
}
/** Sortering als "kolom:richting" (account) of JSON (oude opslag in de browser) */
function uiSortGet(k, ls, cur) {
  const u = (PREFS.ui || {})[k];
  if (typeof u === 'string' && /^[a-z_]*:-?1$/.test(u)) { const [key, dir] = u.split(':'); return { key, dir: +dir }; }
  return cur;
}
const uiSortSet = (k, ls, s) => { try { localStorage.setItem(ls, JSON.stringify(s)); } catch (e) { /* alleen het account */ } savePref('ui', { ...(PREFS.ui || {}), [k]: `${s.key || ''}:${s.dir < 0 ? -1 : 1}` }); };
function savePref(key, value) {
  PREFS[key] = value;
  if (prefsLocal) { try { localStorage.setItem('rzmon-prefs', JSON.stringify(PREFS)); } catch (e) { /* alleen voor nu */ } return; }
  clearTimeout(prefTimers[key]);
  prefTimers[key] = setTimeout(() => api('prefs_save', { key, value }).catch(() => {}), 500);
}
// Chip bovenaan de meldingen (1.84.0): per gebruiker onthouden (eerder alleen in deze browser)
if (PREFS.alert_filter) alertFilter = PREFS.alert_filter;
alertSort = uiSortGet('alert_sort', 'rzmon-alert-sort', alertSort);
accSort = uiSortGet('acc_sort', 'rzmon-acc-sort', accSort);
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
  if (tab === 'vulns') return $('#vuln-tabs') ? (vulnTab === 'leak' ? 'leaks' : vulnTab === 'eol' ? 'eol' : 'vulns') : ($('#leak-list') && !$('#vuln-search') ? 'leaks' : 'vulns');
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
  history.replaceState(null, '', '#' + hashName(plain ? tab.split('/')[0] : tab) + fxQuery(page));
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
const ALERT_CH = { pushover: '📲 Push', email: () => '📧 ' + L('E-mail'), webhook: '🏠 Home Assistant', none: () => '🔕 ' + L('nergens heen') };
/** Naar welke kanalen een melding ging (of zou gaan) */
function alertChannels(a) {
  if (a.kind === 'auth' || a.kind === 'authfail') return ['none'];
  const why = splitSent(a.message)[1];
  const out = [];
  if (+a.sent_ok || /Pushover|ntfy/.test(why)) out.push('pushover');
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
  if (!host || document.body.classList.contains('no-anim')) { node.nodeValue = text; return; }
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
    // 1.191.1: ook een element met data-morph (bv. het pad en de grafiek in de popup) morpht altijd: live bijwerken zonder knipperen
    set(v) { if ((morphOn || (this.dataset && this.dataset.morph !== undefined && this.firstChild)) && this.isConnected) morph(this, String(v)); else d.set.call(this, v); },
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
let clockSkew = 0;   // seconden dat de klok van RzSense voorloopt op die van de browser
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
        ? '⚠️ ' + L('RzSense kan op dit moment geen e-mail versturen. <b>Vraag een beheerder</b> om je wachtwoord opnieuw in te stellen.')
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
  $('#pwforce-logout').onclick = async (e) => { e.preventDefault(); try { sessionStorage.removeItem('rzs-ids-cache'); await api('logout', {}); } catch (ex) { /* toch weg */ } location.replace(location.pathname + '?login=1'); };
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

