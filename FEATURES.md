# Alle functies van RzSense

Een overzicht van alles wat RzSense en de agents kunnen, in één tabel. Hoe je iets instelt en gebruikt, staat in de
[handleiding](docs/README.md). De kolom *Sinds* zegt in welke versie een functie erbij kwam; wat er per versie
veranderd is, lees je in de [CHANGELOG](CHANGELOG.md).

Dezelfde tabel staat in RzSense bij *Instellingen → Overzicht → Functies*, met daar ook of de functie bij jou aan staat
en een knop naar de plek waar je hem instelt.

<!-- functies:begin -->
| Onderdeel | Functie | Wat het doet | Sinds |
|---|---|---|---|
| 🧩 Checks en monitoring | **Ping, poort en HTTP(S)** | Bereikbaarheid, responstijd, statuscode en tekst op de pagina. | 1.0.0 |
| 🧩 Checks en monitoring | **CPU, RAM, disk en load** | Via de agent, met drempels per check. | 1.0.0 |
| 🧩 Checks en monitoring | **Services met diepere controle** | Draait de service echt: poorten, sockets, een URL of een eigen test. | 1.11.0 |
| 🧩 Checks en monitoring | **SSL-certificaat** | Dagen tot het verloopt, ook bestanden op de server via de agent. | 1.7.0 |
| 🧩 Checks en monitoring | **Containers (Podman en Docker)** | Status, healthcheck, herstarts, CPU, RAM en disk per container. | 1.9.0 |
| 🧩 Checks en monitoring | **Logbestand doorzoeken** | Telt nieuwe regels met een tekst of patroon. | 1.16.0 |
| 🧩 Checks en monitoring | **Commando-checks** | De agent voert een commando uit; RzSense beoordeelt de uitkomst. | 1.31.0 |
| 🧩 Checks en monitoring | **SNMP** | Switches, routers, access points en NAS via SNMP v2c of v3, met een algemene instelling. | 1.47.0 |
| 🧩 Checks en monitoring | **Heartbeat (passief)** | Een script of cronjob meldt zich zelf; blijft het stil, dan volgt een melding. | 1.77.0 |
| 🧩 Checks en monitoring | **DNS, NTP, SMART, HTTP+JSON, MariaDB en UPS** | Extra checktypes voor diensten en hardware. | 1.138.0 |
| 🧩 Checks en monitoring | **Internet** | Ping, responstijd en snelheidstest van je internetverbinding. | 1.157.0 |
| 🧩 Checks en monitoring | **Domein, MQTT en VPN** | Verloopdatum van je domein (RDAP), een MQTT-rondgang en Gluetun/WireGuard. | 1.170.0 |
| 🧩 Checks en monitoring | **Pad-check (traceroute)** | Per hop vertraging en verlies, switches, linksnelheid en doorvoer met iperf3. | 1.177.0 |
| 🧩 Checks en monitoring | **Slimme drempels** | RzSense leert per check wat normaal is, per uur van de week. | 1.107.0 |
| 🧩 Checks en monitoring | **Sjablonen** | Een set checks in één keer op een server zetten. | 1.94.0 |
| 🧩 Checks en monitoring | **Databases van binnen** | MariaDB/MySQL en PostgreSQL: verbindingen, query's, cache, replicatie, grootte en wraparound, via de agent zonder wachtwoord of via het netwerk. | 1.200.0 |
| 🧩 Checks en monitoring | **Nog niet bewaakt** | Wat de agent ziet maar nog geen check heeft, met één klik toevoegen. | 1.95.0 |
| 🧩 Checks en monitoring | **Beschikbaarheid en SLA** | Per server en groep: online, %, storingen, trend en minst betrouwbaar. | 1.169.0 |
| 🧩 Checks en monitoring | **Grafieken en diskvoorspelling** | Van 6 uur tot 30 dagen, en wanneer een disk vol is. | 1.7.0 |
| 🧩 Checks en monitoring | **Popup per check** | Status, grafiek, geschiedenis, instellingen en acties van één check. | 1.37.0 |
| 🧩 Checks en monitoring | **Opschoonadvies** | Checks die al dagen onbekend zijn of nooit iets melden. | 1.96.0 |
| 🖥️ Servers en agents | **Linux-agent** | Eén installatiecommando; rapporteert elke 30 s tot 5 min. | 1.4.0 |
| 🖥️ Servers en agents | **Agent Manager** | Per server alles van de agent op één pagina, met schakelaars. | 1.121.0 |
| 🖥️ Servers en agents | **Agent zelftest** | Een echte test vanuit RzSense en op de server, in een eigen popup. | 1.178.0 |
| 🖥️ Servers en agents | **Agents bijwerken vanuit RzSense** | Met ondertekende updates en een reservekopie. | 1.19.0 |
| 🖥️ Servers en agents | **Versleutelde agents** | Rapporten versleuteld, met een eigen sleutel per agent. | 1.57.0 |
| 🖥️ Servers en agents | **Agents via https** | Ook lokaal zonder proxy, met een vastgezet certificaat en een reserve-adres. | 1.72.0 |
| 🖥️ Servers en agents | **Standaardinstellingen voor agents** | Tik, onderdelen en meldingen voor nieuwe agents. | 1.92.0 |
| 🖥️ Servers en agents | **Realtime-watcher** | Meldt meteen als een service of container stopt. | 1.43.0 |
| 🖥️ Servers en agents | **Soort apparaat** | Server, router, switch, access point, NAS, camera … | 1.75.0 |
| 🖥️ Servers en agents | **Proxmox VE** | VM's, containers en opslag van een Proxmox-host. | 1.105.0 |
| 🖥️ Servers en agents | **TrueNAS SCALE** | Meldingen, taken, apps, updates en temperaturen via midclt. | 1.174.0 |
| 🖥️ Servers en agents | **Home Assistant-add-on** | De agent als add-on: HA Core, Supervisor, add-ons en HA OS. | 1.24.0 |
| 🖥️ Servers en agents | **Logs verzamelen** | Logs per server gecomprimeerd en versleuteld naar RzSense, gemaskeerd en gecomprimeerd bewaard, met daglimiet en bewaartermijn. | 1.210.0 |
| 🖥️ Servers en agents | **Logs vinden op de server** | De agent zoekt welke logs er zijn (mappen in LOG_DIRS, containers, services, Home Assistant); je vinkt ze aan in de Agent Manager. | 1.213.0 |
| 🖥️ Servers en agents | **Vaste zoekacties op de logs** | Een zoekopdracht als melding: te veel treffers (≥ N in X minuten) of geen treffers, per server of samen, met rustperiode, herstel en een proef op de laatste 24 uur. | 1.212.0 |
| 🖥️ Servers en agents | **Logs zoeken** | Zoeken in de logs van al je servers: woorden, zinnen, uitsluiten, server, bron, niveau en regex, met een grafiek, de regels eromheen en CSV. | 1.211.0 |
| 🖥️ Servers en agents | **Store-and-forward** | De agent bewaart rapporten als RzSense even weg is. | 1.137.0 |
| 🖥️ Servers en agents | **Waakhond** | De agent meldt via Pushover als RzSense of cron stilstaat. | 1.107.0 |
| 🧭 Dashboard en weergave | **Dashboard: zo staat het ervoor** | Totaalscore, Nu doen, servers die aandacht vragen en grafieken. | 1.134.0 |
| 🧭 Dashboard en weergave | **Dashboard van Bloxzz** | Het live dashboard van de lay-out Bloxzz, met indeling naar keuze en TV-modus. | 1.154.0 |
| 🧭 Dashboard en weergave | **Lay-outs L1nes en Bloxzz** | Per account te kiezen. | 1.158.0 |
| 🔐 Toegang en beveiliging | **Bevestigen bij acties** | Per rol een vinkje in plaats van je wachtwoord, binnen een zelf ingesteld aantal minuten na het inloggen. | 1.206.0 |
| 🖥️ Servers en agents | **Agents wachten tijdens een update** | Tijdens een update van RzSense houden agents hun metingen vast en geven ze geen alarm, tot de vrijgave. | 1.205.0 |
| 🧭 Dashboard en weergave | **Nieuw update-venster** | Bijwerken met wat er nieuw is als kaartjes, een voortgangsring, tijdlijn per stap en duidelijke fouten. | 1.204.0 |
| 🧭 Dashboard en weergave | **Zijbalk vanzelf inklappen** | Smaller slepen of een smal venster = alleen icoontjes; je eigen keuze blijft. | 1.203.0 |
| 🧭 Dashboard en weergave | **Laadteller per pagina** | Duurt een pagina lang, dan een teller, de pols-animatie per onderdeel en na 8 s Opnieuw. | 1.202.0 |
| 🧭 Dashboard en weergave | **Liquid Glass** | Kaarten, menu's en vensters als glas, met licht dat er zacht achter beweegt, per account. | 1.201.0 |
| 🧭 Dashboard en weergave | **Laadanimatie** | Bij het openen lichten je servers een voor een op; overal waar je wacht een hartslag. | 1.201.0 |
| 🧭 Dashboard en weergave | **Thema's** | Licht, donker en meer, per account. | 1.135.0 |
| 🧭 Dashboard en weergave | **Weergave voor nieuwe accounts** | De standaard IDS-weergave en beweging. | 1.181.0 |
| 🧭 Dashboard en weergave | **Echt live** | Wijzigingen binnen een paar seconden op je scherm. | 1.109.0 |
| 🧭 Dashboard en weergave | **Kruimelpad** | Boven elke pagina waar je bent, elk stuk klikbaar, met de filters die aan staan. | 1.199.0 |
| 🧭 Dashboard en weergave | **CPU, RAM en disk in de tellerbalk** | De hoogste waarde met de server, de top 3 en sorteren met één klik. | 1.199.0 |
| 🧭 Dashboard en weergave | **Snelzoeken (Ctrl+K)** | Servers, checks, pagina's en instellingen zoeken. | 1.107.0 |
| 🧭 Dashboard en weergave | **Filters op elke pagina** | Per onderdeel meerdere waarden, onthouden per account. | 1.71.0 |
| 🧭 Dashboard en weergave | **Nederlands en Engels** | De hele interface en de meldingen. | 1.50.0 |
| 🧭 Dashboard en weergave | **Statuspagina** | Een openbare pagina met een geheime link. | 1.107.0 |
| 🧭 Dashboard en weergave | **mDS (Mobile device summary)** | Op een telefoon de startpagina: één oordeel, wat nu aandacht vraagt (met bevestigen, onderhoud, stil en nu checken), beveiliging, netwerk en de servers. | 1.194.0 |
| 🧭 Dashboard en weergave | **Rz//Sense AI: nieuwe naam en logo** | Schild met signaalbogen, één tegel voor licht en donker, een ronde rand om de inhoud en #monitoring in het adres. | 1.214.0 |
| 🧭 Dashboard en weergave | **Bewegende inlogpagina** | Een HUD-raster met horizon en scanbalk, ook op een telefoon; toont of de verbinding versleuteld is. | 1.215.0 |
| 🧭 Dashboard en weergave | **Statuslampje en paginakop** | Een levend lampje in de zijbalk en bovenaan elke pagina een klikbaar pad met titel. | 1.216.0 |
| 🛡️ IDS en endpoint-bewaking | **Incidenten snel afhandelen** | Meteen, in bundels of een keuze, per server, en voorstellen voor wat vaak terugkomt. | 1.217.0 |
| 🔔 Meldingen | **Meldingskaart met advies** | Leesbare samenvatting, drempels in de grafiek en een voorstel als een check flapt. | 1.218.0 |
| 🧭 Dashboard en weergave | **RzSense-kern** | CPU, RAM, disk en cron van de server waar RzSense op draait, als futuristische meters met één oordeel. | 1.219.0 |
| 🛡️ IDS en endpoint-bewaking | **Live begrijpelijk** | Oordeel, soorten, alleen wat aandacht vraagt, gewone taal, herhalingen samengevoegd en uitleg bij een treffer. | 1.219.0 |
| 🧭 Dashboard en weergave | **mDS per soort, met netwerk en ping** | Servers en apparaten per soort; Net bij servers en de ping bij apparaten zonder CPU/RAM/disk. | 1.220.0 |
| 🔔 Meldingen | **Pushover** | Met prioriteit per status en bevestigen vanuit de app. | 1.0.0 |
| 🔔 Meldingen | **E-mail** | SMTP met STARTTLS of SSL/TLS. | 1.7.0 |
| 🔔 Meldingen | **ntfy** | Gratis pushmeldingen via ntfy.sh of je eigen server. | 1.140.0 |
| 🔔 Meldingen | **Home Assistant-webhook** | Elke melding als webhook naar Home Assistant. | 1.2.0 |
| 🔔 Meldingen | **Melden na N mislukte checks** | Algemeen en per check. | 1.48.0 |
| 🔔 Meldingen | **Status bevestigen** | Pas warning/critical (of weer OK) na N controles achter elkaar; algemeen en per check. | 1.185.0 |
| 🔔 Meldingen | **Anti-flapping** | Eén melding als een check steeds wisselt. | 1.26.0 |
| 🔔 Meldingen | **Stille uren** | Periodes zonder meldingen, behalve critical als je wilt. | 1.28.0 |
| 🔔 Meldingen | **Stille-urenprofielen** | Profielen met een naam (ook een periode zoals een vakantie), algemeen en per groep, soort, server of check; met Nu stil en critical die door mag. | 1.189.0 |
| 🔔 Meldingen | **Bundelen per server** | Eén melding als meerdere checks tegelijk misgaan. | 1.25.0 |
| 🔔 Meldingen | **Escaleren** | Een volgende stap als niemand reageert. | 1.108.0 |
| 🔔 Meldingen | **Info-meldingen** | Gebeurtenissen die geen storing zijn: herstart, ingelogd, bijgewerkt … | 1.52.0 |
| 🔔 Meldingen | **Meldingenpagina per storing** | Een storing en zijn herstel op één regel met de duur, Nu open bovenaan, kleur en golf zoals op Monitoring, en een zijpaneel met grafiek en acties. | 1.207.0 |
| 🔔 Meldingen | **Oorzaak erbij** | Bij een melding wat er net daarvoor gebeurde. | 1.108.0 |
| 🔧 Storingen en onderhoud | **Storingen bevestigen** | In het dashboard, via een link of in de Pushover-app. | 1.3.0 |
| 🔧 Storingen en onderhoud | **Onderhoudsmodus** | Voor alles, per server of per check, ook met vaste vensters. | 1.2.0 |
| 🔧 Storingen en onderhoud | **Automatisch onderhoud** | Na herhaalde meldingen, tot het opgelost is. | 1.13.0 |
| 🔧 Storingen en onderhoud | **Afhankelijkheden** | Eén melding als een router of switch weg is. | 1.2.0 |
| 🔧 Storingen en onderhoud | **Storingen bundelen** | Een gemeenschappelijke oorzaak herkennen. | 1.107.0 |
| 🔧 Storingen en onderhoud | **Zelf herstellen** | Een service of container herstarten, of een eigen herstelcommando. | 1.107.0 |
| 🔧 Storingen en onderhoud | **Incidenten** | Eén lijst uit monitoring en IDS, met eigenaar en status. | 1.142.0 |
| 🔧 Storingen en onderhoud | **Urgentie en risico** | Eén getal van 0–100 per incident en per server. | 1.144.0 |
| 🛡️ IDS en endpoint-bewaking | **IDS (inbraakdetectie)** | Mislukte logins, scans en aanvallen, in de modus Leren of Blokkeren. | 1.35.0 |
| 🛡️ IDS en endpoint-bewaking | **Beschermingen** | Per bescherming aan of uit, met de drempels. | 1.181.0 |
| 🛡️ IDS en endpoint-bewaking | **Bescherming (overzicht)** | Wat elke bescherming de laatste 7 dagen deed. | 1.115.0 |
| 🛡️ IDS en endpoint-bewaking | **Honeypot** | Lokaas-gebruikers en een lokaas-poort: dat doen alleen aanvallers. | 1.81.0 |
| 🛡️ IDS en endpoint-bewaking | **Bekende aanvallers vooraf blokkeren** | Spamhaus DROP, FireHOL en Feodo in de firewall van de agents. | 1.82.0 |
| 🛡️ IDS en endpoint-bewaking | **GeoIP** | Logins uit een ander land opvallen of blokkeren. | 1.82.0 |
| 🛡️ IDS en endpoint-bewaking | **Gedeelde blokkades** | Een blokkade op één server geldt op alle servers. | 1.81.0 |
| 🛡️ IDS en endpoint-bewaking | **Bestanden bewaken** | Wijzigingen in gevoelige paden. | 1.40.0 |
| 🛡️ IDS en endpoint-bewaking | **Webshell en persistentie** | Een webserver die een shell start, nieuwe cronjobs en services. | 1.87.0 |
| 🛡️ IDS en endpoint-bewaking | **Hardening-score en isoleren** | Score per server met advies, en een server van het netwerk halen. | 1.88.0 |
| 🛡️ IDS en endpoint-bewaking | **Logs van webdiensten** | Reverse proxy (nginx), Home Assistant en Vaultwarden. | 1.89.0 |
| 🛡️ IDS en endpoint-bewaking | **Pad van een webaanval** | Aanvaller → reverse proxy → site → dienst, met een oordeel; aanvallen per site. | 1.222.0 |
| 🛡️ IDS en endpoint-bewaking | **Endpoint-bewaking (EDR)** | Processen, verbindingen en bestanden via auditd, met Sigma-regels. | 1.110.0 |
| 🛡️ IDS en endpoint-bewaking | **Afwijkingen en aanvalsketens** | 14 dagen leren wat normaal is; ketens met MITRE ATT&CK. | 1.111.0 |
| 🛡️ IDS en endpoint-bewaking | **Dreigingsinformatie** | AbuseIPDB, GreyNoise, Shodan, abuse.ch, Tor en CIRCL. | 1.112.0 |
| 🛡️ IDS en endpoint-bewaking | **DNS-controle via AdGuard** | Verdachte domeinen in de query-log. | 1.113.0 |
| 🛡️ IDS en endpoint-bewaking | **Suricata en YARA** | Netwerk-IDS en bestandsscans via de agent. | 1.113.0 |
| 🛡️ IDS en endpoint-bewaking | **Uitzonderingen** | Wat je vertrouwt, uitsluiten van IDS en EDR. | 1.132.0 |
| 🛡️ IDS en endpoint-bewaking | **Live Attack Flow** | Wie nu aanvalt, op welke server en poort, live. | 1.153.0 |
| 🛡️ IDS en endpoint-bewaking | **IDS per server** | Per server aan/uit, en of de IDS mag blokkeren. | 1.110.0 |
| 🔐 Toegang en beveiliging | **Gebruikers en rollen** | Beheerder, gebruiker en alleen lezen. | 1.14.0 |
| 🔐 Toegang en beveiliging | **Tweestapsverificatie (2FA)** | Met een authenticator-app. | 1.7.0 |
| 🔐 Toegang en beveiliging | **Passkeys** | Inloggen met vingerafdruk, gezicht of beveiligingssleutel. | 1.86.0 |
| 🔐 Toegang en beveiliging | **Wachtwoord vergeten** | Een link per e-mail, 30 minuten geldig. | 1.22.0 |
| 🔐 Toegang en beveiliging | **Inlogbeveiliging** | Account of IP blokkeren na te veel mislukte pogingen. | 1.58.0 |
| 🔐 Toegang en beveiliging | **Ingelogde apparaten** | Elke sessie met apparaat en IP, op afstand uitloggen. | 1.79.0 |
| 🔐 Toegang en beveiliging | **Uitloggen zonder activiteit** | Na een ingestelde tijd. | 1.85.0 |
| 🔐 Toegang en beveiliging | **Beheer via https** | Https verplichten, HSTS, eigen certificaat en controle. | 1.68.0 |
| 🔐 Toegang en beveiliging | **Let's Encrypt-certificaat** | Zelf aanvragen, koppelen en vernieuwen, zonder reverse proxy. | 1.358.0 |
| 🔐 Toegang en beveiliging | **Onzichtbaar van buiten** | Alleen toegestane apparaten en landen zien RzSense. | 1.86.0 |
| 🔐 Toegang en beveiliging | **Blokkades en whitelist** | Eén overzicht van alle blokkades, met uitzonderingen. | 1.83.0 |
| 🔐 Toegang en beveiliging | **Beveiligingsheaders** | Content-Security-Policy en meer op elke pagina. | 1.143.0 |
| 🔐 Toegang en beveiliging | **Inloggen op het lokale netwerk** | Zonder wachtwoord binnen als alleen lezen. | 1.14.0 |
| 🐞 Kwetsbaarheden en datalekken | **Kwetsbaarheden** | Software per server met de bekende CVE's (OSV, KEV, EPSS). | 1.54.0 |
| 🐞 Kwetsbaarheden en datalekken | **Risicoscore per pakket** | CVSS, actief misbruikt, kans op misbruik en bereikbaarheid. | 1.141.0 |
| 🐞 Kwetsbaarheden en datalekken | **Rugbaarheid per CVE** | Exploits en hoeveel erover gepraat wordt, met een melding. | 1.179.0 |
| 🐞 Kwetsbaarheden en datalekken | **Eén update, veel opgelost** | Welke update de meeste kwetsbaarheden oplost. | 1.179.0 |
| 🐞 Kwetsbaarheden en datalekken | **Datalekken (Have I Been Pwned)** | E-mailadressen en je domein controleren op lekken. | 1.69.0 |
| 🌐 Netwerk | **Netwerkscan** | Welke apparaten er op je netwerk zitten, en wat nieuw is. | 1.103.0 |
| 🌐 Netwerk | **Netwerkkaart** | Internet → router → switches en access points → apparaten, met tellers per soort en per netwerk. | 1.184.0 |
| 🌐 Netwerk | **Verkeer per apparaat** | Download en upload per apparaat (UniFi): top 10, per uur en per dag, en een melding bij ongewoon veel of nachtelijk verkeer. | 1.186.0 |
| 🌐 Netwerk | **Verkeer zonder UniFi** | Verkeer via SNMP (switchpoort met één apparaat) en de netwerkkaarten van servers met de agent; UniFi gaat voor. | 1.188.0 |
| 🌐 Netwerk | **Internetlijn per maand** | Verkeer van je internetlijn per dag (UniFi-gateway), met een datalimiet en een verwachting voor de maand. | 1.187.0 |
| 🌐 Netwerk | **Diensten per apparaat (DPI)** | Welke soort verkeer een apparaat doet (streaming, updates, sociale media …), uit UniFi. | 1.187.0 |
| 🧩 Checks en monitoring | **Check Verkeer** | Warning of critical als een apparaat te veel verkeer doet (nu of vandaag). | 1.187.0 |
| 🧩 Checks en monitoring | **Shelly's** | Stroom en oververhitting van je Shelly's, rechtstreeks uitgelezen, met afwijkingen van het normale verbruik. | 1.321.0 |
| 🌐 Netwerk | **Ontdekken** | Nieuwe apparaten, veranderingen (IP, poorten, domeinen, weg of terug), voorstellen met één klik en aanwezigheid per uur. | 1.183.0 |
| 🌐 Netwerk | **Apparaten groeperen** | Per soort, fabrikant of verbinding (wifi-netwerk of bedraad). | 1.183.0 |
| 🌐 Netwerk | **UniFi** | Namen, wifi, access point, verkeer en weren uit je UniFi-controller. | 1.104.0 |
| 🌐 Netwerk | **AdGuard Home** | Namen, DNS-verzoeken en DNS-blokkeren uit AdGuard Home. | 1.104.0 |
| 🌐 Netwerk | **Poortscan** | Open poorten per server, met één klik een check. | 1.8.0 |
| 🌐 Netwerk | **Aanvalspad en impact** | Wat een aanvaller via een server kan bereiken. | 1.146.0 |
| 🌐 Netwerk | **Netwerkpaden** | Alle pad-checks als netwerkkaart. | 1.177.0 |
| 🤖 Analyse, advies en AI | **Analyse en advies** | Score, adviezen en analyses over langere tijd. | 1.155.0 |
| 🤖 Analyse, advies en AI | **Trends** | Wat langzaam verandert over 90 dagen (accu, SSD, responstijd, geheugen, aanvallen), met een datum waarop het misgaat. | 1.224.0 |
| 🤖 Analyse, advies en AI | **Advies voor checks** | Voorstellen voor melden na N, onderhoudsvensters en afhankelijkheden. | 1.108.0 |
| 🤖 Analyse, advies en AI | **Slimme oordelen bij IDS** | Per soort, regels en jouw keuze eerst; de AI alleen als het nog onzeker is. | 1.223.0 |
| 🤖 Analyse, advies en AI | **AI-advies** | Uitleg en een oplossing met Claude, ChatGPT, Gemini, Grok of Ollama. | 1.171.0 |
| 🤖 Analyse, advies en AI | **AI: kosten en budget** | Verbruik, tegoed en een maandbudget. | 1.173.0 |
| 🤖 Analyse, advies en AI | **Raakt dit jou echt?** | AI-oordeel per CVE voor jouw servers. | 1.179.0 |
| 📬 Rapporten | **Rapporten op één plek** | IDS, AI, maand, kwetsbaarheden, beschikbaarheid, back-ups en datalekken. | 1.180.0 |
| 📬 Rapporten | **Maandoverzicht alles-in-één** | Alle rapporten samen in één mail. | 1.180.0 |
| 📬 Rapporten | **Naar mij** | Een rapport naar je eigen e-mailadres sturen. | 1.180.0 |
| 🔗 Koppelingen | **Home Assistant (MQTT)** | Elke server als apparaat in Home Assistant, met sensoren. | 1.7.0 |
| 🔗 Koppelingen | **REST API** | Status lezen en acties, met rechten per API-sleutel. | 1.27.0 |
| 🔗 Koppelingen | **Home Assistant-netwerkkaart** | Een kaart voor je HA-dashboard met het netwerk in het kort, zoals mDS: oordeel, internet, verbruik, apparaten, paden, IDS en de servers. | 1.197.0 |
| ⚙️ Beheer | **RzSense bijwerken** | Vanuit de webinterface, ook automatisch en naar een gekozen versie. | 1.29.0 |
| ⚙️ Beheer | **Back-up en herstel** | Automatisch of met de hand, met terugzetten. | 1.18.0 |
| ⚙️ Beheer | **Wijzigingslogboek** | Wie veranderde wat en wanneer, met terugdraaien (sinds 1.235.0 in het Logboek). | 1.97.0 |
| 🛡️ IDS en endpoint-bewaking | **Ransomware herkennen** | Lokaasbestanden, massale wijzigingen en versleutelde bestanden in je mappen; meteen een snapshot en eventueel stoppen. | 1.228.0 |
| 🛡️ IDS en endpoint-bewaking | **Onbekende programma's** | Welke programma's er draaien, met vingerafdruk en pakket; na een leerperiode valt een onbekend programma op. Toestaan of blokkeren. | 1.229.0 |
| 🛡️ IDS en endpoint-bewaking | **Jagen op sporen (IOC)** | Zoek IP-adressen, domeinen, vingerafdrukken en paden in alles wat RzSense weet, en op de servers zelf; bewaakte sporen geven een melding. | 1.229.0 |
| 🛡️ IDS en endpoint-bewaking | **AdGuard-blokkadelijst** | Een lijst voor AdGuard Home met malwaredomeinen, je bewaakte sporen en eigen domeinen, zodat ze in je hele netwerk geblokkeerd worden. | 1.231.0 |
| 🛡️ IDS en endpoint-bewaking | **Nieuw geregistreerde domeinen** | Een apparaat dat een gloednieuw domein opvraagt (jonger dan 30 dagen, via RDAP): vaak phishing of malware. | 1.231.0 |
| 🔐 Toegang en beveiliging | **Tijdelijk sudo** | Een gebruiker voor korte tijd sudo geven op één server, met je wachtwoord en 2FA; gaat vanzelf weer weg. | 1.231.0 |
| 🔐 Toegang en beveiliging | **Reverse proxy beschermen** | Per site adminpagina's alleen vanaf je eigen netwerk, slechte bots weren en te veel verzoeken afremmen; de agent op de proxy (SWAG of Nginx Proxy Manager) voert het door, test het en zet het terug als een site niet meer antwoordt. | 1.254.0 |
| 🔐 Toegang en beveiliging | **WAF op de reverse proxy** | CrowdSec AppSec bekijkt elk verzoek naar je apps op aanvallen; met een slider van alleen melden naar blokkeren (alleen zeker of streng), per site alleen melden of uit, en uitzonderingen met één klik. | 1.255.0 |
| 🔐 Toegang en beveiliging | **AI-advies voor de reverse proxy** | De AI stelt per site het beleid voor (wie mag erbij, rate limit, bots, WAF) met een reden; met een slider zelf beheren of automatisch, en zelf ingestelde sites blijven van jou. | 1.256.0 |
| 🔐 Toegang en beveiliging | **Uploads scannen op malware (ClamAV)** | ClamAV op de reverse proxy scant elke minuut de nieuwe bestanden in de uploadmappen van je apps; melden en verplaatsen naar de quarantaine elk aan en uit, terugzetten vanuit RzSense. | 1.257.0 |
| 🔐 Toegang en beveiliging | **Suricata op het proxynetwerk** | Suricata kijkt mee op het onversleutelde verkeer tussen de reverse proxy en je apps: aanvallen op je apps en malware die naar buiten belt; met een regelprofiel en een drempel. | 1.260.0 |
| 🔐 Toegang en beveiliging | **NIS2-overzicht** | De tien maatregelen uit artikel 21 met wat RzSense ziet en je notities, de meldtermijnen (24 uur, 72 uur, een maand) en een rapport. | 1.232.0 |
| 🛡️ IDS en endpoint-bewaking | **CIS-hardening** | 25 extra controles uit de CIS Benchmarks per server (SSH, kernel, rechten, wachtwoorden, audit), gegroepeerd per hoofdstuk. | 1.232.0 |
| 🐞 Kwetsbaarheden en datalekken | **E-mailbeveiliging van je domein** | SPF, DKIM, DMARC en MTA-STS: kan iemand mailen namens jouw domein? Met het record om te plakken. | 1.227.0 |
| 🐞 Kwetsbaarheden en datalekken | **End-of-life** | Besturingssysteem en software die geen updates meer krijgen, of binnenkort niet (endoflife.date). | 1.227.0 |
| 🐞 Kwetsbaarheden en datalekken | **Updates installeren** | Welke updates er klaarstaan, en ze installeren: met de hand, in het onderhoudsvenster of elke dag; eerst een snapshot, Home Assistant met een back-up. | 1.230.0 |
| 🖥️ Servers en agents | **Logboek** | Alles wat de agent, RzSense en beheerders deden en wat er veranderde, per server of alles samen, met terugzetten. | 1.226.0 |
| ⚙️ Beheer | **Exporteren en importeren** | Checks en instellingen naar een andere RzSense. | 1.97.0 |
| ⚙️ Beheer | **Database en server** | Systeemcheck, grootte, groei en bewaartermijnen. | 1.92.0 |
| ⚙️ Beheer | **Prestaties en fouten** | Snelheid, cron-rondes, traagste checks en PHP-fouten. | 1.151.0 |
| ⚙️ Beheer | **Status van RzSense** | Wat er nog aandacht nodig heeft, met een knop om het te regelen. | 1.62.0 |
| ⚙️ Beheer | **Functies** | Dit overzicht: alle functies met hun status. | 1.182.0 |
<!-- functies:end -->

<!-- De tabel hierboven wordt gemaakt uit src/Features.php met: php tools/features-table.php -->
