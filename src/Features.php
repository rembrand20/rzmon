<?php
declare(strict_types=1);

/**
 * 1.182.0: overzicht van alle functies van RzSense, voor Instellingen → Overzicht → Functies
 * en de tabel bovenaan FEATURES.md (tools/features-table.php).
 *
 * Elke rij: [groep, naam (nl, en), wat het doet (nl, en), sinds, status, onderdeel in de beheerportal]
 * - status '' = altijd beschikbaar;
 *   'naam' = aan als de instelling niet leeg, '0' of 'off' is;
 *   'naam|nodig1,nodig2' = idem, maar "niet ingesteld" zolang een van de nodige instellingen leeg is;
 *   '@reports' = aan als minstens één rapport aan staat.
 * - onderdeel: de sectie in de beheerportal (SET_NAV) of een pagina ('#dashboard', '#ids/prot' …); '' = geen.
 */
final class Features
{
    public const GROUPS = [
        'checks'  => ['🧩', 'Checks en monitoring', 'Checks and monitoring'],
        'servers' => ['🖥️', 'Servers en agents', 'Servers and agents'],
        'dash'    => ['🧭', 'Dashboard en weergave', 'Dashboard and display'],
        'notify'  => ['🔔', 'Meldingen', 'Notifications'],
        'outage'  => ['🔧', 'Storingen en onderhoud', 'Outages and maintenance'],
        'ids'     => ['🛡️', 'IDS en endpoint-bewaking', 'IDS and endpoint monitoring'],
        'sec'     => ['🔐', 'Toegang en beveiliging', 'Access and security'],
        'vuln'    => ['🐞', 'Kwetsbaarheden en datalekken', 'Vulnerabilities and breaches'],
        'net'     => ['🌐', 'Netwerk', 'Network'],
        'ai'      => ['🤖', 'Analyse, advies en AI', 'Analysis, advice and AI'],
        'reports' => ['📬', 'Rapporten', 'Reports'],
        'links'   => ['🔗', 'Koppelingen', 'Integrations'],
        'admin'   => ['⚙️', 'Beheer', 'Administration'],
    ];

    /**
     * 1.226.0: functies die je in het overzicht met één schakelaar aan en uit zet (gewone aan/uit-instellingen, 1 of 0).
     * Wat meer vraagt (een sleutel, je wachtwoord, een keuze) zet je aan op zijn eigen pagina.
     */
    public const TOGGLE = ['edr_on', 'ti_on', 'dns_on', 'suricata_on', 'logs_on', 'cve_buzz_on', 'anomaly_on', 'context_on', 'esc_on', 'perf_on',
        'smart_auto', 'net_traffic', 'ids_block_shared', 'ids_geo', 'ids_blocklists', 'mailsec_on', 'eol_on', 'ransom_on'];

    public const LIST = [
        // Checks en monitoring
        ['checks', ['Ping, poort en HTTP(S)', 'Ping, port and HTTP(S)'], ['Bereikbaarheid, responstijd, statuscode en tekst op de pagina.', 'Reachability, response time, status code and text on the page.'], '1.0.0', '', '#dashboard'],
        ['checks', ['CPU, RAM, disk en load', 'CPU, RAM, disk and load'], ['Via de agent, met drempels per check.', 'Through the agent, with thresholds per check.'], '1.0.0', '', '#dashboard'],
        ['checks', ['Services met diepere controle', 'Services with deep checks'], ['Draait de service echt: poorten, sockets, een URL of een eigen test.', 'Is the service really working: ports, sockets, a URL or your own test.'], '1.11.0', '', '#dashboard'],
        ['checks', ['SSL-certificaat', 'SSL certificate'], ['Dagen tot het verloopt, ook bestanden op de server via de agent.', 'Days until it expires, also files on the server through the agent.'], '1.7.0', '', '#dashboard'],
        ['checks', ['Containers (Podman en Docker)', 'Containers (Podman and Docker)'], ['Status, healthcheck, herstarts, CPU, RAM en disk per container.', 'Status, health check, restarts, CPU, RAM and disk per container.'], '1.9.0', '', '#dashboard:cont'],
        ['checks', ['Logbestand doorzoeken', 'Search log files'], ['Telt nieuwe regels met een tekst of patroon.', 'Counts new lines with a text or pattern.'], '1.16.0', '', '#dashboard'],
        ['checks', ['Commando-checks', 'Command checks'], ['De agent voert een commando uit; RzSense beoordeelt de uitkomst.', 'The agent runs a command; RzSense judges the result.'], '1.31.0', 'agent_commands', 'commands'],
        ['checks', ['SNMP', 'SNMP'], ['Switches, routers, access points en NAS via SNMP v2c of v3, met een algemene instelling.', 'Switches, routers, access points and NAS through SNMP v2c or v3, with a global setting.'], '1.47.0', '', 'snmp'],
        ['checks', ['Heartbeat (passief)', 'Heartbeat (passive)'], ['Een script of cronjob meldt zich zelf; blijft het stil, dan volgt een melding.', 'A script or cron job checks in; if it goes quiet, you get notified.'], '1.77.0', '', '#dashboard'],
        ['checks', ['DNS, NTP, SMART, HTTP+JSON, MariaDB en UPS', 'DNS, NTP, SMART, HTTP+JSON, MariaDB and UPS'], ['Extra checktypes voor diensten en hardware.', 'Extra check types for services and hardware.'], '1.138.0', '', '#dashboard'],
        ['checks', ['Internet', 'Internet'], ['Ping, responstijd en snelheidstest van je internetverbinding.', 'Ping, response time and speed test of your internet connection.'], '1.157.0', '', 'internet'],
        ['checks', ['Domein, MQTT en VPN', 'Domain, MQTT and VPN'], ['Verloopdatum van je domein (RDAP), een MQTT-rondgang en Gluetun/WireGuard.', 'Domain expiry (RDAP), an MQTT round trip and Gluetun/WireGuard.'], '1.170.0', '', '#dashboard'],
        ['checks', ['Pad-check (traceroute)', 'Path check (traceroute)'], ['Per hop vertraging en verlies, switches, linksnelheid en doorvoer met iperf3.', 'Delay and loss per hop, switches, link speed and throughput with iperf3.'], '1.177.0', '', '#dashboard:paths'],
        ['checks', ['Slimme drempels', 'Smart thresholds'], ['RzSense leert per check wat normaal is, per uur van de week.', 'RzSense learns what is normal per check, per hour of the week.'], '1.107.0', 'smart_auto', 'advice'],
        ['checks', ['Sjablonen', 'Templates'], ['Een set checks in één keer op een server zetten.', 'Add a set of checks to a server in one go.'], '1.94.0', '', 'templates'],
        ['checks', ['Databases van binnen', 'Databases from the inside'], ['MariaDB/MySQL en PostgreSQL: verbindingen, query\'s, cache, replicatie, grootte en wraparound, via de agent zonder wachtwoord of via het netwerk.', 'MariaDB/MySQL and PostgreSQL: connections, queries, cache, replication, size and wraparound, through the agent without a password or over the network.'], '1.200.0', '', ''],
        ['checks', ['Nog niet bewaakt', 'Not monitored yet'], ['Wat de agent ziet maar nog geen check heeft, met één klik toevoegen.', 'What the agent sees but has no check yet, added with one click.'], '1.95.0', '', '#dashboard'],
        ['checks', ['Beschikbaarheid en SLA', 'Availability and SLA'], ['Per server en groep: online, %, storingen, trend en minst betrouwbaar.', 'Per server and group: online, %, outages, trend and least reliable.'], '1.169.0', '', '#dashboard:avail'],
        ['checks', ['Grafieken en diskvoorspelling', 'Charts and disk forecast'], ['Van 6 uur tot 30 dagen, en wanneer een disk vol is.', 'From 6 hours to 30 days, and when a disk will be full.'], '1.7.0', '', '#dashboard'],
        ['checks', ['Popup per check', 'Popup per check'], ['Status, grafiek, geschiedenis, instellingen en acties van één check.', 'Status, chart, history, settings and actions of one check.'], '1.37.0', '', '#dashboard'],
        ['checks', ['Opschoonadvies', 'Clean-up advice'], ['Checks die al dagen onbekend zijn of nooit iets melden.', 'Checks that have been unknown for days or never report anything.'], '1.96.0', '', 'allchecks'],

        // Servers en agents
        ['servers', ['Linux-agent', 'Linux agent'], ['Eén installatiecommando; rapporteert elke 30 s tot 5 min.', 'One install command; reports every 30 s to 5 min.'], '1.4.0', '', 'agents'],
        ['servers', ['Agent Manager', 'Agent Manager'], ['Per server alles van de agent op één pagina, met schakelaars.', 'Everything about the agent per server on one page, with switches.'], '1.121.0', '', 'agents'],
        ['servers', ['Agent zelftest', 'Agent self-test'], ['Een echte test vanuit RzSense en op de server, in een eigen popup.', 'A real test from RzSense and on the server, in its own popup.'], '1.178.0', '', 'agents'],
        ['servers', ['Agents bijwerken vanuit RzSense', 'Update agents from RzSense'], ['Met ondertekende updates en een reservekopie.', 'With signed updates and a backup.'], '1.19.0', '', 'agents'],
        ['servers', ['Versleutelde agents', 'Encrypted agents'], ['Rapporten versleuteld, met een eigen sleutel per agent.', 'Reports encrypted, with a key per agent.'], '1.57.0', '', 'agents'],
        ['servers', ['Agents via https', 'Agents over https'], ['Ook lokaal zonder proxy, met een vastgezet certificaat en een reserve-adres.', 'Also locally without a proxy, with a pinned certificate and a fallback address.'], '1.72.0', '', 'agents'],
        ['servers', ['Standaardinstellingen voor agents', 'Agent defaults'], ['Tik, onderdelen en meldingen voor nieuwe agents.', 'Tick, components and notifications for new agents.'], '1.92.0', '', 'agentdef'],
        ['servers', ['Realtime-watcher', 'Realtime watcher'], ['Meldt meteen als een service of container stopt.', 'Reports immediately when a service or container stops.'], '1.43.0', '', 'agents'],
        ['servers', ['Soort apparaat', 'Device type'], ['Server, router, switch, access point, NAS, camera …', 'Server, router, switch, access point, NAS, camera …'], '1.75.0', '', '#dashboard'],
        ['servers', ['Proxmox VE', 'Proxmox VE'], ['VM\'s, containers en opslag van een Proxmox-host.', 'VMs, containers and storage of a Proxmox host.'], '1.105.0', '', '#dashboard'],
        ['servers', ['TrueNAS SCALE', 'TrueNAS SCALE'], ['Meldingen, taken, apps, updates en temperaturen via midclt.', 'Alerts, jobs, apps, updates and temperatures through midclt.'], '1.174.0', '', '#dashboard'],
        ['servers', ['Home Assistant-add-on', 'Home Assistant add-on'], ['De agent als add-on: HA Core, Supervisor, add-ons en HA OS.', 'The agent as an add-on: HA Core, Supervisor, add-ons and HA OS.'], '1.24.0', '', 'agents'],
        ['servers', ['Logs verzamelen', 'Log collection'], ['Logs per server gecomprimeerd en versleuteld naar RzSense, gemaskeerd en gecomprimeerd bewaard, met daglimiet en bewaartermijn.', 'Logs per server sent compressed and encrypted to RzSense, masked and stored compressed, with a daily limit and retention.'], '1.210.0', 'logs_on', 'logs'],
        ['servers', ['Logs vinden op de server', 'Find logs on the server'], ['De agent zoekt welke logs er zijn (mappen in LOG_DIRS, containers, services, Home Assistant); je vinkt ze aan in de Agent Manager.', 'The agent finds which logs there are (folders in LOG_DIRS, containers, services, Home Assistant); you tick them in the Agent Manager.'], '1.213.0', '', ''],
        ['servers', ['Vaste zoekacties op de logs', 'Saved log searches'], ['Een zoekopdracht als melding: te veel treffers (≥ N in X minuten) of geen treffers, per server of samen, met rustperiode, herstel en een proef op de laatste 24 uur.', 'A search as an alert: too many hits (≥ N in X minutes) or no hits, per server or together, with a rest period, recovery and a trial on the last 24 hours.'], '1.212.0', '', '#logs'],
        ['servers', ['Logs zoeken', 'Log search'], ['Zoeken in de logs van al je servers: woorden, zinnen, uitsluiten, server, bron, niveau en regex, met een grafiek, de regels eromheen en CSV.', 'Search the logs of all your servers: words, phrases, exclusions, server, source, level and regex, with a chart, the lines around a hit and CSV.'], '1.211.0', '', '#logs'],
        ['servers', ['Store-and-forward', 'Store-and-forward'], ['De agent bewaart rapporten als RzSense even weg is.', 'The agent keeps reports while RzSense is away.'], '1.137.0', '', 'agents'],
        ['servers', ['Waakhond', 'Watchdog'], ['De agent meldt via Pushover als RzSense of cron stilstaat.', 'The agent notifies through Pushover when RzSense or cron stops.'], '1.107.0', '', 'agents'],

        // Dashboard en weergave
        ['dash', ['Dashboard: zo staat het ervoor', 'Dashboard: how things are'], ['Totaalscore, Nu doen, servers die aandacht vragen en grafieken.', 'Overall score, To do now, servers that need attention and charts.'], '1.134.0', '', '#home'],
        ['dash', ['Command center', 'Command center'], ['Een tweede dashboard met indeling naar keuze en TV-modus.', 'A second dashboard with your own layout and TV mode.'], '1.154.0', '', '#home'],
        ['dash', ['Lay-outs Blox, L1nes, Compact en CC', 'Layouts Blox, L1nes, Compact and CC'], ['Per account te kiezen.', 'Chosen per account.'], '1.158.0', '', '#account'],
        ['sec', ['Bevestigen bij acties', 'Confirming actions'], ['Per rol een vinkje in plaats van je wachtwoord, binnen een zelf ingesteld aantal minuten na het inloggen.', 'Per role a tick instead of your password, within a number of minutes you choose after logging in.'], '1.206.0', 'confirm_skip_admin', '#settings/users'],
        ['servers', ['Agents wachten tijdens een update', 'Agents wait during an update'], ['Tijdens een update van RzSense houden agents hun metingen vast en geven ze geen alarm, tot de vrijgave.', 'During a RzSense update agents hold their measurements and raise no alarm until release.'], '1.205.0', '', '#settings/update'],
        ['dash', ['Nieuw update-venster', 'New update window'], ['Bijwerken met wat er nieuw is als kaartjes, een voortgangsring, tijdlijn per stap en duidelijke fouten.', 'Updating with what\'s new as cards, a progress ring, timeline per step and clear errors.'], '1.204.0', '', '#settings/update'],
        ['dash', ['Zijbalk vanzelf inklappen', 'Sidebar collapses by itself'], ['Smaller slepen of een smal venster = alleen icoontjes; je eigen keuze blijft.', 'Drag it narrower or use a narrow window = icons only; your own choice stays.'], '1.203.0', '', ''],
        ['dash', ['Laadteller per pagina', 'Loading counter per page'], ['Duurt een pagina lang, dan een teller, de pols-animatie per onderdeel en na 8 s Opnieuw.', 'If a page takes long: a counter, the pulse animation per part and after 8 s Retry.'], '1.202.0', '', ''],
        ['dash', ['Liquid Glass', 'Liquid Glass'], ['Kaarten, menu\'s en vensters als glas, met licht dat er zacht achter beweegt, per account.', 'Cards, menus and windows as glass, with light moving softly behind them, per account.'], '1.201.0', '', '#account'],
        ['dash', ['Laadanimatie', 'Loading animation'], ['Bij het openen lichten je servers een voor een op; overal waar je wacht een hartslag.', 'On opening your servers light up one by one; wherever you wait a heartbeat.'], '1.201.0', '', '#account'],
        ['dash', ['Thema\'s', 'Themes'], ['Licht, donker en meer, per account.', 'Light, dark and more, per account.'], '1.135.0', '', '#account'],
        ['dash', ['Weergave voor nieuwe accounts', 'Defaults for new accounts'], ['De standaard IDS-weergave en beweging.', 'The default IDS view and motion.'], '1.181.0', '', 'looks'],
        ['dash', ['Echt live', 'Truly live'], ['Wijzigingen binnen een paar seconden op je scherm.', 'Changes on your screen within a few seconds.'], '1.109.0', '', ''],
        ['dash', ['Kruimelpad', 'Breadcrumbs'], ['Boven elke pagina waar je bent, elk stuk klikbaar, met de filters die aan staan.', 'Above every page where you are, every part clickable, with the active filters.'], '1.199.0', '', ''],
        ['dash', ['CPU, RAM en disk in de tellerbalk', 'CPU, RAM and disk in the counter bar'], ['De hoogste waarde met de server, de top 3 en sorteren met één klik.', 'The highest value with the server, the top 3 and sorting with one click.'], '1.199.0', '', ''],
        ['dash', ['Snelzoeken (Ctrl+K)', 'Quick search (Ctrl+K)'], ['Servers, checks, pagina\'s en instellingen zoeken.', 'Search servers, checks, pages and settings.'], '1.107.0', '', ''],
        ['dash', ['Filters op elke pagina', 'Filters on every page'], ['Per onderdeel meerdere waarden, onthouden per account.', 'Several values per part, remembered per account.'], '1.71.0', '', ''],
        ['dash', ['Nederlands en Engels', 'Dutch and English'], ['De hele interface en de meldingen.', 'The whole interface and the notifications.'], '1.50.0', '', '#account'],
        ['dash', ['Statuspagina', 'Status page'], ['Een openbare pagina met een geheime link.', 'A public page with a secret link.'], '1.107.0', 'status_link', 'statuspage'],
        ['dash', ['mDS (Mobile device summary)', 'mDS (Mobile device summary)'], ['Op een telefoon de startpagina: één oordeel, wat nu aandacht vraagt (met bevestigen, onderhoud, stil en nu checken), beveiliging, netwerk en de servers.', 'The start page on a phone: one verdict, what needs attention (with acknowledge, maintenance, quiet and check now), security, network and the servers.'], '1.194.0', '', ''],
        ['dash', ['Rz//Sense AI: nieuwe naam en logo', 'Rz//Sense AI: new name and logo'], ['Schild met signaalbogen, één tegel voor licht en donker, een ronde rand om de inhoud en #monitoring in het adres.', 'Shield with signal arcs, one tile for light and dark, a rounded frame around the content and #monitoring in the address.'], '1.214.0', '', ''],
        ['dash', ['Bewegende inlogpagina', 'Animated login page'], ['Een HUD-raster met horizon en scanbalk, ook op een telefoon; toont of de verbinding versleuteld is.', 'A HUD grid with horizon and scan bar, also on a phone; shows whether the connection is encrypted.'], '1.215.0', '', ''],
        ['dash', ['Statuslampje en paginakop', 'Status light and page header'], ['Een levend lampje in de zijbalk en bovenaan elke pagina een klikbaar pad met titel.', 'A live light in the sidebar and a clickable path with title on top of every page.'], '1.216.0', '', ''],
        ['ids', ['Incidenten snel afhandelen', 'Handle incidents quickly'], ['Meteen, in bundels of een keuze, per server, en voorstellen voor wat vaak terugkomt.', 'Instantly, in bundles or a selection, per server, with suggestions for what keeps coming back.'], '1.217.0', '', '#ids/inc'],
        ['notify', ['Meldingskaart met advies', 'Alert card with advice'], ['Leesbare samenvatting, drempels in de grafiek en een voorstel als een check flapt.', 'Readable summary, thresholds in the chart and a suggestion when a check flaps.'], '1.218.0', '', '#alerts'],
        ['dash', ['RzSense-kern', 'RzSense core'], ['CPU, RAM, disk en cron van de server waar RzSense op draait, als futuristische meters met één oordeel.', 'CPU, RAM, disk and cron of the server RzSense runs on, as futuristic gauges with one verdict.'], '1.219.0', '', '#monitoring'],
        ['ids', ['Live begrijpelijk', 'Live made clear'], ['Oordeel, soorten, alleen wat aandacht vraagt, gewone taal, herhalingen samengevoegd en uitleg bij een treffer.', 'Verdict, kinds, only what needs attention, plain language, merged repeats and an explanation per hit.'], '1.219.0', '', '#ids/live'],
        ['dash', ['mDS per soort, met netwerk en ping', 'mDS by kind, with network and ping'], ['Servers en apparaten per soort; Net bij servers en de ping bij apparaten zonder CPU/RAM/disk.', 'Servers and devices by kind; Net for servers and ping for devices without CPU/RAM/disk.'], '1.220.0', '', '#mds'],

        // Meldingen
        ['notify', ['Pushover', 'Pushover'], ['Met prioriteit per status en bevestigen vanuit de app.', 'With priority per status and acknowledge from the app.'], '1.0.0', 'pushover_enabled|pushover_token,pushover_user', 'channels'],
        ['notify', ['E-mail', 'Email'], ['SMTP met STARTTLS of SSL/TLS.', 'SMTP with STARTTLS or SSL/TLS.'], '1.7.0', 'email_enabled|smtp_host', 'channels'],
        ['notify', ['ntfy', 'ntfy'], ['Gratis pushmeldingen via ntfy.sh of je eigen server.', 'Free push notifications through ntfy.sh or your own server.'], '1.140.0', 'ntfy_enabled|ntfy_topic', 'channels'],
        ['notify', ['Home Assistant-webhook', 'Home Assistant webhook'], ['Elke melding als webhook naar Home Assistant.', 'Every notification as a webhook to Home Assistant.'], '1.2.0', 'ha_webhook_enabled|ha_webhook_url', 'channels'],
        ['notify', ['Melden na N mislukte checks', 'Notify after N failed checks'], ['Algemeen en per check.', 'Global and per check.'], '1.48.0', '', 'rules'],
        ['notify', ['Status bevestigen', 'Confirm status'], ['Pas warning/critical (of weer OK) na N controles achter elkaar; algemeen en per check.', 'Only warning/critical (or OK again) after N checks in a row; global and per check.'], '1.185.0', 'confirm_fails', 'rules'],
        ['notify', ['Anti-flapping', 'Anti-flapping'], ['Eén melding als een check steeds wisselt.', 'One notification when a check keeps changing.'], '1.26.0', '', 'rules'],
        ['notify', ['Stille uren', 'Quiet hours'], ['Periodes zonder meldingen, behalve critical als je wilt.', 'Periods without notifications, except critical if you like.'], '1.28.0', '', 'quiet'],
        ['notify', ['Stille-urenprofielen', 'Quiet hours profiles'], ['Profielen met een naam (ook een periode zoals een vakantie), algemeen en per groep, soort, server of check; met Nu stil en critical die door mag.', 'Named profiles (also a period such as a holiday), general and per group, kind, server or check; with Quiet now and critical that may come through.'], '1.189.0', 'quiet_global', 'quiet'],
        ['notify', ['Bundelen per server', 'Bundle per server'], ['Eén melding als meerdere checks tegelijk misgaan.', 'One notification when several checks fail at once.'], '1.25.0', 'notify_bundle_hosts', 'rules'],
        ['notify', ['Escaleren', 'Escalation'], ['Een volgende stap als niemand reageert.', 'A next step when nobody responds.'], '1.108.0', 'esc_on', 'rules'],
        ['notify', ['Info-meldingen', 'Info notifications'], ['Gebeurtenissen die geen storing zijn: herstart, ingelogd, bijgewerkt …', 'Events that are not an outage: restart, logged in, updated …'], '1.52.0', '', 'sysinfo'],
        ['notify', ['Meldingenpagina per storing', 'Alerts page per outage'], ['Een storing en zijn herstel op één regel met de duur, Nu open bovenaan, kleur en golf zoals op Monitoring, en een zijpaneel met grafiek en acties.', 'An outage and its recovery on one line with the duration, Open now at the top, colour and wave as on Monitoring, and a side panel with chart and actions.'], '1.207.0', '', '#alerts'],
        ['notify', ['Oorzaak erbij', 'Cause included'], ['Bij een melding wat er net daarvoor gebeurde.', 'What happened just before, with a notification.'], '1.108.0', 'context_on', 'rules'],

        // Storingen en onderhoud
        ['outage', ['Storingen bevestigen', 'Acknowledge outages'], ['In het dashboard, via een link of in de Pushover-app.', 'In the dashboard, through a link or in the Pushover app.'], '1.3.0', '', '#dashboard'],
        ['outage', ['Onderhoudsmodus', 'Maintenance mode'], ['Voor alles, per server of per check, ook met vaste vensters.', 'For everything, per server or per check, also with fixed windows.'], '1.2.0', '', '#dashboard'],
        ['outage', ['Automatisch onderhoud', 'Automatic maintenance'], ['Na herhaalde meldingen, tot het opgelost is.', 'After repeated notifications, until it is solved.'], '1.13.0', 'auto_maint_enabled', 'rules'],
        ['outage', ['Afhankelijkheden', 'Dependencies'], ['Eén melding als een router of switch weg is.', 'One notification when a router or switch is down.'], '1.2.0', '', '#dashboard'],
        ['outage', ['Storingen bundelen', 'Bundle outages'], ['Een gemeenschappelijke oorzaak herkennen.', 'Recognise a common cause.'], '1.107.0', '', ''],
        ['outage', ['Zelf herstellen', 'Self-healing'], ['Een service of container herstarten, of een eigen herstelcommando.', 'Restart a service or container, or your own repair command.'], '1.107.0', '', 'agents'],
        ['outage', ['Incidenten', 'Incidents'], ['Eén lijst uit monitoring en IDS, met eigenaar en status.', 'One list from monitoring and the IDS, with owner and status.'], '1.142.0', '', '#incidents'],
        ['outage', ['Urgentie en risico', 'Urgency and risk'], ['Eén getal van 0–100 per incident en per server.', 'One number from 0–100 per incident and per server.'], '1.144.0', '', '#home'],

        // IDS
        ['ids', ['IDS (inbraakdetectie)', 'IDS (intrusion detection)'], ['Mislukte logins, scans en aanvallen, in de modus Leren of Blokkeren.', 'Failed logins, scans and attacks, in Learn or Block mode.'], '1.35.0', 'ids_enabled', 'ids'],
        ['ids', ['Beschermingen', 'Protections'], ['Per bescherming aan of uit, met de drempels.', 'Each protection on or off, with its thresholds.'], '1.181.0', 'ids_enabled', 'ids_prot'],
        ['ids', ['Bescherming (overzicht)', 'Protection (overview)'], ['Wat elke bescherming de laatste 7 dagen deed.', 'What each protection did in the last 7 days.'], '1.115.0', 'ids_enabled', '#ids/prot'],
        ['ids', ['Honeypot', 'Honeypot'], ['Lokaas-gebruikers en een lokaas-poort: dat doen alleen aanvallers.', 'Decoy users and a decoy port: only attackers do that.'], '1.81.0', 'ids_enabled', 'ids_prot'],
        ['ids', ['Bekende aanvallers vooraf blokkeren', 'Block known attackers in advance'], ['Spamhaus DROP, FireHOL en Feodo in de firewall van de agents.', 'Spamhaus DROP, FireHOL and Feodo in the agents\' firewall.'], '1.82.0', 'ids_blocklists', 'ids_prot'],
        ['ids', ['GeoIP', 'GeoIP'], ['Logins uit een ander land opvallen of blokkeren.', 'Flag or block logins from another country.'], '1.82.0', 'ids_geo', 'ids_attack'],
        ['ids', ['Gedeelde blokkades', 'Shared blocks'], ['Een blokkade op één server geldt op alle servers.', 'A block on one server applies to all servers.'], '1.81.0', 'ids_block_shared', 'ids_attack'],
        ['ids', ['Bestanden bewaken', 'File monitoring'], ['Wijzigingen in gevoelige paden.', 'Changes in sensitive paths.'], '1.40.0', 'ids_enabled', 'ids_edr'],
        ['ids', ['Webshell en persistentie', 'Web shell and persistence'], ['Een webserver die een shell start, nieuwe cronjobs en services.', 'A web server starting a shell, new cron jobs and services.'], '1.87.0', 'ids_enabled', 'ids_prot'],
        ['ids', ['Hardening-score en isoleren', 'Hardening score and isolation'], ['Score per server met advies, en een server van het netwerk halen.', 'Score per server with advice, and taking a server off the network.'], '1.88.0', '', '#ids'],
        ['ids', ['Logs van webdiensten', 'Web service logs'], ['Reverse proxy (nginx), Home Assistant en Vaultwarden.', 'Reverse proxy (nginx), Home Assistant and Vaultwarden.'], '1.89.0', 'ids_enabled', 'ids_prot'],
        ['ids', ['Pad van een webaanval', 'Path of a web attack'], ['Aanvaller → reverse proxy → site → dienst, met een oordeel; aanvallen per site.', 'Attacker → reverse proxy → site → service, with a verdict; attacks per site.'], '1.222.0', 'ids_enabled', 'ids_attack'],
        ['ids', ['Endpoint-bewaking (EDR)', 'Endpoint monitoring (EDR)'], ['Processen, verbindingen en bestanden via auditd, met Sigma-regels.', 'Processes, connections and files through auditd, with Sigma rules.'], '1.110.0', 'edr_on', 'ids_edr'],
        ['ids', ['Afwijkingen en aanvalsketens', 'Anomalies and attack chains'], ['14 dagen leren wat normaal is; ketens met MITRE ATT&CK.', 'Learn what is normal for 14 days; chains with MITRE ATT&CK.'], '1.111.0', 'anomaly_on', 'ids_edr'],
        ['ids', ['Dreigingsinformatie', 'Threat intelligence'], ['AbuseIPDB, GreyNoise, Shodan, abuse.ch, Tor en CIRCL.', 'AbuseIPDB, GreyNoise, Shodan, abuse.ch, Tor and CIRCL.'], '1.112.0', 'ti_on', 'ids_ti'],
        ['ids', ['DNS-controle via AdGuard', 'DNS monitoring through AdGuard'], ['Verdachte domeinen in de query-log.', 'Suspicious domains in the query log.'], '1.113.0', 'dns_on', 'ids_prot'],
        ['ids', ['Suricata en YARA', 'Suricata and YARA'], ['Netwerk-IDS en bestandsscans via de agent.', 'Network IDS and file scans through the agent.'], '1.113.0', 'suricata_on', 'ids_edr'],
        ['ids', ['Uitzonderingen', 'Exceptions'], ['Wat je vertrouwt, uitsluiten van IDS en EDR.', 'Exclude what you trust from the IDS and EDR.'], '1.132.0', '', '#ids/rules'],
        ['ids', ['Live Attack Flow', 'Live Attack Flow'], ['Wie nu aanvalt, op welke server en poort, live.', 'Who is attacking now, on which server and port, live.'], '1.153.0', '', '#ids/map'],
        ['ids', ['IDS per server', 'IDS per server'], ['Per server aan/uit, en of de IDS mag blokkeren.', 'On/off per server, and whether the IDS may block.'], '1.110.0', '', 'ids_servers'],

        // Toegang en beveiliging
        ['sec', ['Gebruikers en rollen', 'Users and roles'], ['Beheerder, gebruiker en alleen lezen.', 'Admin, user and read-only.'], '1.14.0', '', 'users'],
        ['sec', ['Tweestapsverificatie (2FA)', 'Two-factor authentication (2FA)'], ['Met een authenticator-app.', 'With an authenticator app.'], '1.7.0', '', 'users'],
        ['sec', ['Passkeys', 'Passkeys'], ['Inloggen met vingerafdruk, gezicht of beveiligingssleutel.', 'Log in with fingerprint, face or security key.'], '1.86.0', 'passkeys_enabled', 'access'],
        ['sec', ['Wachtwoord vergeten', 'Forgot password'], ['Een link per e-mail, 30 minuten geldig.', 'A link by email, valid for 30 minutes.'], '1.22.0', 'email_enabled|smtp_host', 'access'],
        ['sec', ['Inlogbeveiliging', 'Login protection'], ['Account of IP blokkeren na te veel mislukte pogingen.', 'Block an account or IP after too many failed attempts.'], '1.58.0', 'login_lock_account', 'access'],
        ['sec', ['Ingelogde apparaten', 'Signed-in devices'], ['Elke sessie met apparaat en IP, op afstand uitloggen.', 'Every session with device and IP, log out remotely.'], '1.79.0', '', '#account'],
        ['sec', ['Uitloggen zonder activiteit', 'Log out when idle'], ['Na een ingestelde tijd.', 'After a set time.'], '1.85.0', 'session_idle_minutes', 'access'],
        ['sec', ['Beheer via https', 'Management over https'], ['Https verplichten, HSTS, eigen certificaat en controle.', 'Require https, HSTS, own certificate and checks.'], '1.68.0', 'https_only', 'outside'],
        ['sec', ['Onzichtbaar van buiten', 'Invisible from outside'], ['Alleen toegestane apparaten en landen zien RzSense.', 'Only allowed devices and countries see RzSense.'], '1.86.0', 'stealth_ui', 'outside'],
        ['sec', ['Blokkades en whitelist', 'Blocks and whitelist'], ['Eén overzicht van alle blokkades, met uitzonderingen.', 'One overview of all blocks, with exceptions.'], '1.83.0', '', '#ids/blk'],
        ['sec', ['Beveiligingsheaders', 'Security headers'], ['Content-Security-Policy en meer op elke pagina.', 'Content Security Policy and more on every page.'], '1.143.0', '', ''],
        ['sec', ['Inloggen op het lokale netwerk', 'Local network login'], ['Zonder wachtwoord binnen als alleen lezen.', 'Enter without a password as read-only.'], '1.14.0', 'lan_no_login', 'access'],

        // Kwetsbaarheden en datalekken
        ['vuln', ['Kwetsbaarheden', 'Vulnerabilities'], ['Software per server met de bekende CVE\'s (OSV, KEV, EPSS).', 'Software per server with known CVEs (OSV, KEV, EPSS).'], '1.54.0', 'vuln_enabled', 'vulns'],
        ['vuln', ['Risicoscore per pakket', 'Risk score per package'], ['CVSS, actief misbruikt, kans op misbruik en bereikbaarheid.', 'CVSS, actively exploited, exploit probability and exposure.'], '1.141.0', 'vuln_enabled', 'vulns'],
        ['vuln', ['Rugbaarheid per CVE', 'Buzz per CVE'], ['Exploits en hoeveel erover gepraat wordt, met een melding.', 'Exploits and how much it is talked about, with a notification.'], '1.179.0', 'cve_buzz_on', 'vulns'],
        ['vuln', ['Eén update, veel opgelost', 'One update, many fixes'], ['Welke update de meeste kwetsbaarheden oplost.', 'Which update fixes the most vulnerabilities.'], '1.179.0', 'vuln_enabled', '#vulns'],
        ['vuln', ['Datalekken (Have I Been Pwned)', 'Data breaches (Have I Been Pwned)'], ['E-mailadressen en je domein controleren op lekken.', 'Check email addresses and your domain for breaches.'], '1.69.0', 'hibp_enabled|hibp_key', 'hibp'],

        // Netwerk
        ['net', ['Netwerkscan', 'Network scan'], ['Welke apparaten er op je netwerk zitten, en wat nieuw is.', 'Which devices are on your network, and what is new.'], '1.103.0', 'net_scan', 'netscan'],
        ['net', ['Netwerkkaart', 'Network map'], ['Internet → router → switches en access points → apparaten, met tellers per soort en per netwerk.', 'Internet → router → switches and access points → devices, with counts per type and per network.'], '1.184.0', 'net_scan', '#net:map'],
        ['net', ['Verkeer per apparaat', 'Traffic per device'], ['Download en upload per apparaat (UniFi): top 10, per uur en per dag, en een melding bij ongewoon veel of nachtelijk verkeer.', 'Download and upload per device (UniFi): top 10, per hour and per day, and an event for unusually high or night-time traffic.'], '1.186.0', 'net_traffic|unifi_user', '#net:map'],
        ['net', ['Verkeer zonder UniFi', 'Traffic without UniFi'], ['Verkeer via SNMP (switchpoort met één apparaat) en de netwerkkaarten van servers met de agent; UniFi gaat voor.', 'Traffic through SNMP (switch port with one device) and the network cards of servers with the agent; UniFi wins.'], '1.188.0', 'net_traffic', '#net'],
        ['net', ['Internetlijn per maand', 'Internet line per month'], ['Verkeer van je internetlijn per dag (UniFi-gateway), met een datalimiet en een verwachting voor de maand.', 'Traffic of your internet line per day (UniFi gateway), with a data limit and a forecast for the month.'], '1.187.0', 'net_traffic|unifi_user', '#net:map'],
        ['net', ['Diensten per apparaat (DPI)', 'Services per device (DPI)'], ['Welke soort verkeer een apparaat doet (streaming, updates, sociale media …), uit UniFi.', 'What kind of traffic a device does (streaming, updates, social media …), from UniFi.'], '1.187.0', 'net_traffic|unifi_user', '#net'],
        ['checks', ['Check Verkeer', 'Traffic check'], ['Warning of critical als een apparaat te veel verkeer doet (nu of vandaag).', 'Warning or critical when a device does too much traffic (now or today).'], '1.187.0', '', '#dashboard'],
        ['net', ['Ontdekken', 'Discover'], ['Nieuwe apparaten, veranderingen (IP, poorten, domeinen, weg of terug), voorstellen met één klik en aanwezigheid per uur.', 'New devices, changes (IP, ports, domains, gone or back), one-click suggestions and presence per hour.'], '1.183.0', 'net_scan', '#net:disc'],
        ['net', ['Apparaten groeperen', 'Group devices'], ['Per soort, fabrikant of verbinding (wifi-netwerk of bedraad).', 'By type, vendor or connection (wifi network or wired).'], '1.183.0', 'net_scan', '#net'],
        ['net', ['UniFi', 'UniFi'], ['Namen, wifi, access point, verkeer en weren uit je UniFi-controller.', 'Names, wifi, access point, traffic and blocking from your UniFi controller.'], '1.104.0', '', 'unifi'],
        ['net', ['AdGuard Home', 'AdGuard Home'], ['Namen, DNS-verzoeken en DNS-blokkeren uit AdGuard Home.', 'Names, DNS queries and DNS blocking from AdGuard Home.'], '1.104.0', '', 'adguard'],
        ['net', ['Poortscan', 'Port scan'], ['Open poorten per server, met één klik een check.', 'Open ports per server, a check with one click.'], '1.8.0', '', '#dashboard'],
        ['net', ['Aanvalspad en impact', 'Attack path and impact'], ['Wat een aanvaller via een server kan bereiken.', 'What an attacker could reach through a server.'], '1.146.0', '', '#home'],
        ['net', ['Netwerkpaden', 'Network paths'], ['Alle pad-checks als netwerkkaart.', 'All path checks as a network map.'], '1.177.0', '', '#dashboard:paths'],

        // Analyse, advies en AI
        ['ai', ['Analyse en advies', 'Analysis and advice'], ['Score, adviezen en analyses over langere tijd.', 'Score, advice and analyses over a longer period.'], '1.155.0', '', '#analytics'],
        ['ai', ['Trends', 'Trends'], ['Wat langzaam verandert over 90 dagen (accu, SSD, responstijd, geheugen, aanvallen), met een datum waarop het misgaat.', 'What slowly changes over 90 days (battery, SSD, response time, memory, attacks), with a date when it goes wrong.'], '1.224.0', '', '#analytics'],
        ['ai', ['Advies voor checks', 'Check advice'], ['Voorstellen voor melden na N, onderhoudsvensters en afhankelijkheden.', 'Suggestions for notify after N, maintenance windows and dependencies.'], '1.108.0', '', 'advice'],
        ['ai', ['Slimme oordelen bij IDS', 'Smart IDS verdicts'], ['Per soort, regels en jouw keuze eerst; de AI alleen als het nog onzeker is.', 'Per kind, rules and your choice first; the AI only when still uncertain.'], '1.223.0', 'ai_triage', 'ai'],
        ['ai', ['AI-advies', 'AI advice'], ['Uitleg en een oplossing met Claude, ChatGPT, Gemini, Grok of Ollama.', 'Explanation and a fix with Claude, ChatGPT, Gemini, Grok or Ollama.'], '1.171.0', 'ai_on|ai_provider', 'ai'],
        ['ai', ['AI: kosten en budget', 'AI: costs and budget'], ['Verbruik, tegoed en een maandbudget.', 'Usage, credit and a monthly budget.'], '1.173.0', 'ai_on|ai_provider', 'ai'],
        ['ai', ['Raakt dit jou echt?', 'Does this really affect you?'], ['AI-oordeel per CVE voor jouw servers.', 'AI verdict per CVE for your servers.'], '1.179.0', 'ai_on|ai_provider', 'vulns'],

        // Rapporten
        ['reports', ['Rapporten op één plek', 'Reports in one place'], ['IDS, AI, maand, kwetsbaarheden, beschikbaarheid, back-ups en datalekken.', 'IDS, AI, month, vulnerabilities, availability, backups and breaches.'], '1.180.0', '@reports', 'reports'],
        ['reports', ['Maandoverzicht alles-in-één', 'All-in-one monthly overview'], ['Alle rapporten samen in één mail.', 'All reports together in one email.'], '1.180.0', '@reports', 'reports'],
        ['reports', ['Naar mij', 'To me'], ['Een rapport naar je eigen e-mailadres sturen.', 'Send a report to your own email address.'], '1.180.0', 'email_enabled|smtp_host', 'reports'],

        // Koppelingen
        ['links', ['Home Assistant (MQTT)', 'Home Assistant (MQTT)'], ['Elke server als apparaat in Home Assistant, met sensoren.', 'Every server as a device in Home Assistant, with sensors.'], '1.7.0', 'mqtt_enabled|mqtt_host', 'mqtt'],
        ['links', ['REST API', 'REST API'], ['Status lezen en acties, met rechten per API-sleutel.', 'Read status and actions, with permissions per API key.'], '1.27.0', '', 'api'],
        ['links', ['Home Assistant-netwerkkaart', 'Home Assistant network card'], ['Een kaart voor je HA-dashboard met het netwerk in het kort, zoals mDS: oordeel, internet, verbruik, apparaten, paden, IDS en de servers.', 'A card for your HA dashboard with the network at a glance, like mDS: verdict, internet, usage, devices, paths, IDS and the servers.'], '1.197.0', '', 'api'],

        // Beheer
        ['admin', ['RzSense bijwerken', 'Update RzSense'], ['Vanuit de webinterface, ook automatisch en naar een gekozen versie.', 'From the web interface, also automatically and to a chosen version.'], '1.29.0', 'update_auto', 'update'],
        ['admin', ['Back-up en herstel', 'Backup and restore'], ['Automatisch of met de hand, met terugzetten.', 'Automatic or manual, with restore.'], '1.18.0', 'backup_freq', 'backup'],
        ['admin', ['Wijzigingslogboek', 'Change log'], ['Wie veranderde wat en wanneer, met terugdraaien.', 'Who changed what and when, with undo.'], '1.97.0', '', 'changelog'],
        ['ids', ['Ransomware herkennen', 'Ransomware detection'], ['Lokaasbestanden, massale wijzigingen en versleutelde bestanden in je mappen; meteen een snapshot en eventueel stoppen.', 'Decoy files, mass changes and encrypted files in your folders; an instant snapshot and optionally stopping.'], '1.228.0', 'ransom_on', 'ids_ransom'],
        ['vuln', ['E-mailbeveiliging van je domein', 'E-mail security of your domain'], ['SPF, DKIM, DMARC en MTA-STS: kan iemand mailen namens jouw domein? Met het record om te plakken.', 'SPF, DKIM, DMARC and MTA-STS: can someone send mail as your domain? With the record to paste.'], '1.227.0', 'mailsec_on', 'mailsec'],
        ['vuln', ['End-of-life', 'End of life'], ['Besturingssysteem en software die geen updates meer krijgen, of binnenkort niet (endoflife.date).', 'Operating systems and software that no longer get updates, or soon will not (endoflife.date).'], '1.227.0', 'eol_on', 'vulns'],
        ['servers', ['Logboek per server', 'Log per server'], ['Wat de agent, RzSense en beheerders op een server deden, en wat er veranderde.', 'What the agent, RzSense and administrators did on a server, and what changed.'], '1.226.0', '', 'actlog'],
        ['admin', ['Exporteren en importeren', 'Export and import'], ['Checks en instellingen naar een andere RzSense.', 'Checks and settings to another RzSense.'], '1.97.0', '', 'transfer'],
        ['admin', ['Database en server', 'Database and server'], ['Systeemcheck, grootte, groei en bewaartermijnen.', 'System check, size, growth and retention.'], '1.92.0', '', 'db'],
        ['admin', ['Prestaties en fouten', 'Performance and errors'], ['Snelheid, cron-rondes, traagste checks en PHP-fouten.', 'Speed, cron runs, slowest checks and PHP errors.'], '1.151.0', 'perf_on', 'perf'],
        ['admin', ['Status van RzSense', 'RzSense status'], ['Wat er nog aandacht nodig heeft, met een knop om het te regelen.', 'What still needs attention, with a button to fix it.'], '1.62.0', '', 'overview'],
        ['admin', ['Functies', 'Features'], ['Dit overzicht: alle functies met hun status.', 'This overview: all features with their status.'], '1.182.0', '', 'features'],
    ];

    /** De rijen in de taal van nu, met de live status */
    public static function rows(): array
    {
        $en = I18n::lang() === 'en';
        $s = settings();
        $repOn = null;
        $out = [];
        foreach (self::LIST as $i => [$g, $name, $what, $since, $st, $sec]) {
            if ($st === '@reports') {
                $repOn ??= self::anyReport();
                $status = $repOn ? 'on' : 'off';
            } else {
                $status = self::status($st, $s);
            }
            $key = explode('|', $st, 2)[0];
            $out[] = ['id' => $i, 'g' => $g, 'name' => $name[$en ? 1 : 0], 'what' => $what[$en ? 1 : 0], 'since' => $since, 'status' => $status, 'sec' => $sec,
                'toggle' => in_array($key, self::TOGGLE, true) && $status !== 'unset' ? $key : null];
        }
        return $out;
    }

    /** Groepen in de taal van nu: sleutel => [icoon, naam] */
    public static function groups(): array
    {
        $en = I18n::lang() === 'en';
        $out = [];
        foreach (self::GROUPS as $k => $g) $out[$k] = [$g[0], $g[$en ? 2 : 1]];
        return $out;
    }

    /** always / on / off / unset */
    public static function status(string $spec, array $settings): string
    {
        if ($spec === '') return 'always';
        [$key, $need] = array_pad(explode('|', $spec, 2), 2, '');
        foreach ($need === '' ? [] : explode(',', $need) as $n) {
            if (trim((string) ($settings[$n] ?? '')) === '') return 'unset';
        }
        $v = trim((string) ($settings[$key] ?? ''));
        return in_array($v, ['', '0', 'off'], true) ? 'off' : 'on';
    }

    private static function anyReport(): bool
    {
        try {
            foreach (Reports::cfg() as $c) if (is_array($c) && !empty($c['on'])) return true;
        } catch (Throwable $e) {
        }
        return false;
    }

    /** Markdown-tabel voor FEATURES.md (altijd Nederlands) */
    public static function markdown(): string
    {
        $lines = ['| Onderdeel | Functie | Wat het doet | Sinds |', '|---|---|---|---|'];
        foreach (self::LIST as [$g, $name, $what, $since]) {
            $grp = self::GROUPS[$g];
            $lines[] = '| ' . $grp[0] . ' ' . $grp[1] . ' | **' . $name[0] . '** | ' . str_replace('|', '\\|', $what[0]) . ' | ' . $since . ' |';
        }
        return implode("\n", $lines) . "\n";
    }
}
