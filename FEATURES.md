# ✨ RzSense – overzicht van alle features

Dit is een overzicht van wat RzSense en de agent kunnen, in het kort. Hoe je alles instelt en gebruikt, staat in de [HANDLEIDING.md](HANDLEIDING.md) (de §-nummers hieronder verwijzen daarnaar). Wat er per versie veranderd is, lees je in de [CHANGELOG.md](CHANGELOG.md).

**Inhoud**
1. [Checks](#1-checks)
2. [Soorten servers](#2-soorten-servers)
3. [Dashboard](#3-dashboard)
4. [Meldingen](#4-meldingen)
5. [Storingen, onderhoud en afhankelijkheden](#5-storingen-onderhoud-en-afhankelijkheden)
6. [Home Assistant](#6-home-assistant)
7. [REST API](#7-rest-api)
8. [Beheer: installeren, bijwerken en back-up](#8-beheer-installeren-bijwerken-en-back-up)
9. [Toegang en beveiliging](#9-toegang-en-beveiliging)
10. [De agent (Linux)](#10-de-agent-linux)
11. [De agent op TrueNAS SCALE](#11-de-agent-op-truenas-scale)
12. [De Home Assistant-add-on](#12-de-home-assistant-add-on)

## 📋 Alle functies in één tabel
Dezelfde tabel staat in RzSense bij *Instellingen → Overzicht → Functies*, met daar ook de status van nu (aan, uit, niet ingesteld) en een knop naar de plek waar je hem instelt. De tabel wordt gemaakt met `php tools/features-table.php` uit `src/Features.php`.

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
| 🧭 Dashboard en weergave | **Command center** | Een tweede dashboard met indeling naar keuze en TV-modus. | 1.154.0 |
| 🧭 Dashboard en weergave | **Lay-outs Blox, L1nes, Compact en CC** | Per account te kiezen. | 1.158.0 |
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
| 🛡️ IDS en endpoint-bewaking | **Logs van webdiensten** | SWAG, Home Assistant en Vaultwarden. | 1.89.0 |
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
| 🌐 Netwerk | **Ontdekken** | Nieuwe apparaten, veranderingen (IP, poorten, domeinen, weg of terug), voorstellen met één klik en aanwezigheid per uur. | 1.183.0 |
| 🌐 Netwerk | **Apparaten groeperen** | Per soort, fabrikant of verbinding (wifi-netwerk of bedraad). | 1.183.0 |
| 🌐 Netwerk | **UniFi en AdGuard Home** | Namen, wifi en access point uit UniFi; DNS uit AdGuard. | 1.104.0 |
| 🌐 Netwerk | **Poortscan** | Open poorten per server, met één klik een check. | 1.8.0 |
| 🌐 Netwerk | **Aanvalspad en impact** | Wat een aanvaller via een server kan bereiken. | 1.146.0 |
| 🌐 Netwerk | **Netwerkpaden** | Alle pad-checks als netwerkkaart. | 1.177.0 |
| 🤖 Analyse, advies en AI | **Analyse en advies** | Score, adviezen en analyses over langere tijd. | 1.155.0 |
| 🤖 Analyse, advies en AI | **Advies voor checks** | Voorstellen voor melden na N, onderhoudsvensters en afhankelijkheden. | 1.108.0 |
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
| ⚙️ Beheer | **Wijzigingslogboek** | Wie veranderde wat en wanneer, met terugdraaien. | 1.97.0 |
| ⚙️ Beheer | **Exporteren en importeren** | Checks en instellingen naar een andere RzSense. | 1.97.0 |
| ⚙️ Beheer | **Database en server** | Systeemcheck, grootte, groei en bewaartermijnen. | 1.92.0 |
| ⚙️ Beheer | **Prestaties en fouten** | Snelheid, cron-rondes, traagste checks en PHP-fouten. | 1.151.0 |
| ⚙️ Beheer | **Status van RzSense** | Wat er nog aandacht nodig heeft, met een knop om het te regelen. | 1.62.0 |
| ⚙️ Beheer | **Functies** | Dit overzicht: alle functies met hun status. | 1.182.0 |
<!-- functies:end -->

---

## 1. Checks

| Check | Wat het controleert | Werkt op |
|---|---|---|
| **Ping** | Reageert de server (3 pings); gemiddelde responstijd, pakketverlies | Alle servers |
| **TCP-poort** | Staat een dienst open (SSH, MariaDB, MQTT…), met verbindingstijd | Alle servers |
| **HTTP(S)** | Website of API bereikbaar, statuscode, responstijd; waarschuwt ook als het certificaat bijna verloopt | Alle servers |
| **SSL-certificaat** | Hoeveel dagen een certificaat nog geldig is: elke TLS-poort in je netwerk of op internet, of via de agent certificaatbestanden (die hij zelf zoekt, om uit te kiezen) en `localhost:poort`; bij een keten telt het certificaat dat het eerst verloopt | Alle servers |
| **Proces** | Draait een proces (op naam of patroon in de opdrachtregel, eventueel van één gebruiker), met aantal, CPU en RAM; een herstart geeft een ℹ️ info-melding. Kiezen uit wat er draait | Lokaal + agent |
| **Diskruimte** | % gebruikt per mount, plus een **voorspelling** wanneer de disk vol is | Lokaal, agent |
| **CPU** | Gebruik over 2 seconden en load average | Lokaal, agent |
| **RAM** | Geheugengebruik (cache telt niet mee) en swap | Lokaal, agent |
| **Service** | Is een systemd-service actief? Via de agent ook de **diepere controle**: luistert hij op zijn poorten, antwoordt hij (HTTP, database, SSH, mail, DNS…), herstarts, fouten in het log, een extra URL- of poorttest of een eigen testscript (§8.7) | Lokaal, agent |
| **Logbestand** | Treffers van een zoekpatroon in een logbestand, het systemlog of kernelmeldingen. Met voorinstellingen voor mislukte logins (SSH, sudo), disk- en hardwarefouten, SMART, geheugen vol (OOM) en crashes (§8.8) | Agent |
| **Podman** | Overzicht: gestopte, ongezonde of gepauzeerde containers en pods | Agent met Podman |
| **Container** | Eén check per container: status (healthcheck, herstarts), plus naar keuze CPU, RAM en disk, elk met eigen drempels; ook rootless containers (§8.6) | Agent met Podman |
| **Home Assistant** | Laatste automatische back-up, integraties die niet geladen zijn, systeemstatus van de Supervisor | HA-add-on |
| **📡 SNMP** | RzSense leest het apparaat zelf uit (v2c of v3): uptime en herstart, CPU, geheugen, load, temperatuur, interfaces (up/down, verkeer, bezetting), opslag, UPS (status, lading, looptijd, belasting, accutemperatuur), Synology (systeem, volumes, schijven), printers (toner, inkt) en UniFi access points (isolated, firmware met ℹ️-melding, clients per band en per SSID, kanaalbezetting, SSID uitgezonden, verkeer en kwaliteit per SSID). Met **📡 SNMP-checks** (het apparaat doorzoeken en aanvinken) en **eigen OID's** als getal, teller of tekst (§7.7) | Alle servers met SNMP aan |
| **🖥️ Commando** (met 21 voorinstellingen voor TrueNAS, Proxmox en Linux, en *＋ Aanbevolen checks*) | De agent voert een commando uit; RzSense vergelijkt de uitvoer met het verwachte antwoord (*gelijk aan*, *bevat*, *regex* of *exitcode 0*), met time-out. Bijvoorbeeld `zpool status -x` → `all pools are healthy` (§8.10) | Agent |
| **🌐 Domein, 📨 MQTT en 🔐 VPN** (vanaf 1.170.0) | **Domein**: verloopdatum via RDAP (en status, ook bij .nl zonder verloopdatum). **MQTT**: inloggen, een testbericht sturen en terug ontvangen, en een topic bekijken (bv. Zigbee2MQTT online). **VPN**: Gluetun via de control server (draait hij, welk IP, lekt hij niet) of WireGuard via de agent (laatste handshake per peer) | Alle servers (WireGuard: servers met de agent) |
| **Heartbeat** (passieve check) | Een script, back-up of cronjob meldt zich zelf: met `rzmon-hb NAAM ok` (versleuteld via de agent) of een geheime URL (alleen LAN, per check ook van buiten). Schema *elke …*, *dagelijks om*, *wekelijks* of cron, met speling: te laat = WARNING, 2× de speling = CRITICAL. *fail* = CRITICAL, *start* meet de duur (te lang = *hangt het?*), een meegestuurde waarde met eigen drempels. Voorbeelden voor Proxmox, rsync, restic, borg, Synology, MariaDB, certbot en Home Assistant (§7.8) | Alle servers |

Elke check heeft eigen drempels voor warning en critical, een eigen interval en een minigrafiek van de laatste 24 uur.

**📋 Sjablonen** (1.94.0): sets checks die je zelf maakt (of maakt van een bestaande server) en aan servers koppelt, met `{adres}` en `{naam}` per server. Pas je het sjabloon aan, dan gaan alle gekoppelde servers mee, behalve checks die je op een server zelf hebt aangepast (terug te zetten). Startsjablonen: *Linux-server*, *Website*, *Netwerkapparaat*.

**💡 Nog niet bewaakt** (1.95.0): wat de agent ziet maar nog geen check heeft (disks, services, containers, certificaten, open poorten) staat bij de server als *💡 N nieuw gevonden*: met één klik toevoegen of negeren. **🧪 Nu proberen** in het checkformulier toont het resultaat vóór je opslaat.

**🧹 Opschoonadvies** (1.96.0): checks die al dagen onbekend zijn, ruismakers, flappers, al lang in warning, net onder de drempel of al lang uit, elk met een voorstel en een knop (melden na 3, uitzetten, verwijderen, niet meer tonen).

**☑️ Alle checks** (1.94.0): één lijst met zoeken en filters; vink checks aan en pas ze tegelijk aan (interval, drempels, melden, onderhoud, aan/uit), kopieer ze naar andere servers of verwijder ze.

**⇄ Omgekeerde status** (ping, poort, HTTP, service, container, Podman, commando, Home Assistant): kies zelf de status als de check slaagt (OK, warning of critical) en als hij mislukt. Voor iets dat juist níet mag draaien of bereikbaar mag zijn, zoals telnet of een oude server. Telt niet mee voor de bereikbaarheid van een server.

## 2. Soorten servers
- **Lokaal**: de RzSense-server zelf; alles wordt direct uitgelezen.
- **Remote met agent**: een Linux-server, TrueNAS SCALE of Home Assistant die zelf gegevens doorstuurt. Stopt de agent, dan worden de checks na 5 minuten (instelbaar) critical.
- **Remote (alleen netwerk)**: routers, NAS, switches, printers en websites, met ping, TCP, HTTP(S) en certificaten.
- **Poortscan**: zoek open TCP-poorten (bekende poorten, 1–1024 of een eigen lijst) en maak er met één klik een check van. Bij agent-servers zie je ook welke poorten lokaal luisteren, met procesnaam.

## 3. Dashboard
- **Ondertekende agent-updates** (vanaf 1.139.0, agent 1.71.0): elke agent wordt in GitHub ondertekend (ed25519); agents installeren een update alleen met een kloppende handtekening, ook als RzSense zelf zou zijn overgenomen.
- **Nieuwe checks** (vanaf 1.138.0, agent 1.70.0): **DNS**, **Tijd (NTP)**, **Schijfgezondheid (SMART/NVMe)**, **UPS (NUT)** en **MariaDB/MySQL**; **HTTP** met methode, body en een verwachte tekst of JSON-waarde, en alleen veilige adressen. Schijven, UPS'en en de klok worden vanzelf voorgesteld bij *💡 Nog niet bewaakt*.
- **Snellere agents** (vanaf 1.137.0, agent 1.69.0): commando-checks op de achtergrond, een hartslag tijdens een lange run (geen onterechte *agent stil*), een wachtrij die metingen inhaalt na een storing, de duur per onderdeel in de Agent Manager, en **Docker** naast Podman.
- **Bewaartermijnen** (vanaf 1.136.0): elke meting 30 dagen, daarna uurgemiddelden 180 dagen en daggegevens 2 jaar, alles in te stellen; grafieken tot 180 dagen terug; groei van de database in beeld en een waarschuwing bij ongewone groei.
- **Zijbalk en thema's** (vanaf 1.135.0): menu links op een breed scherm (met Instellingen), donker thema in marineblauw, en de themakeuze (auto, licht, donker) per gebruiker op elk apparaat. Op het Dashboard tegels voor Hosts, Meldingen, Kritieke CVE en Agents, een grafiek van CPU en RAM over 24 uur, recente incidenten, en *onbekend* in grijs (nooit groen).
- **🛰️ Command center** (vanaf 1.154.0): een tweede dashboard naast het klassieke, per gebruiker te kiezen: SSH-logins (met nieuwe IP-adressen), mislukte pogingen, SSH- en IDS-blokkades, sudo/su, webaanvallen en uitgaand verkeer in één rij; de toppers (top SSH-blokkade, top IDS-blokkade, grootste aanvaller, meest aangevallen server, top land); wie er inlogt; een kleine Live Attack Flow; de monitoring (checks uitgevoerd, fout gegaan, gelukt, meldingen, de checks die het vaakst fout gingen en de meeste meldingen gaven); servers met ringen; *sinds je laatste bezoek*; zelf in te delen (verslepen, verbergen) en met een **TV-modus**. Volgt het thema, net als de Live Attack Flow.
- **🧭 Dashboard: zo staat het ervoor** (vanaf 1.134.0, de startpagina): totaalscore, tegels voor Monitoring, IDS, Kwetsbaarheden en Netwerk (met status en een lijntje), *Nu aandacht nodig* uit alle onderdelen, een netwerkkaart (internet → netwerkapparaten → per soort apparaat), servers met CPU/RAM/disk, grafieken van beschikbaarheid, IDS en CVE's, netwerk en de laatste gebeurtenissen; over 7, 30 of 90 dagen. Het vorige dashboard heet nu **Monitoring**.
- **Nederlands of Engels** 🌐: in het accountmenu rechtsboven (NL of EN), per gebruiker onthouden. De hele interface is vertaald. Meldingen (Pushover, e-mail, Home Assistant, MQTT), checkberichten en de REST API volgen een eigen instelling: *Taal van meldingen en checkberichten*.
- **⚲ Filters op elke pagina** (Dashboard, Meldingen, IDS, Toegang, Blokkades, Kwetsbaarheden, Datalekken): per onderdeel meerdere waarden met aantallen, een periode, actieve filters als chips, per gebruiker bewaard, **opgeslagen filters met een naam**, en de filters in het adres om te delen.
- **Bij openen alles dicht** (of *slim openen*, per gebruiker): wat je openklapt blijft open tot je herlaadt; zoeken of filteren klapt de treffers open; *Alles openen / sluiten* op elke pagina. Op *Kwetsbaarheden* en *Datalekken* (1.93.0) onthoudt RzSense per account welke blokken je open had; de rest is dicht.
- **Vandaag altijd open** op Meldingen, IDS-incidenten en Logins (gaat weer open bij iets nieuws), met een lege balk *Vandaag · geen meldingen 👌* als het rustig is.
- **Tellers onder de header** (OK, warning, critical, onbekend, onderhoud), klikbaar als filter, blijven staan bij scrollen. Ook de pagina's **Meldingen**, **IDS** en **Kwetsbaarheden** hebben die opbouw: tellers, kerncijfers, werkbalk met chips, compacte rijen, en herhalingen of gelijke incidenten gebundeld (*5×*); *niet verzonden* als rood icoon met de reden.
- **Kerncijfers als tegels**: de algehele status (🟢 *Alles in orde*, 🟠 *Aandacht nodig*, 🔴 *Storing*) met een **score van 0–100%** als ring, servers bereikbaar, checks OK, open problemen met het **langst openstaande**, en de beschikbaarheid over 24 uur met een lijntje; 🌙 *stil tot …*, flappende checks en onderhoud.
- **⚠️ Nu aandacht nodig**: alle open problemen in één lijst, critical eerst, met ✔ bevestigen en 🔧 onderhoud per regel.
- **Werkbalk** met zoeken, filterchips (problemen, onderhoud, en per **soort apparaat**: servers, netwerk, opslag, stroom, smart home), sorteren en een menu ⋯; servers met problemen als kaart met **mini-grafiekjes van CPU, RAM en disk over 24 uur**, servers waar alles OK is compact eronder.
- **Twee lay-outs**, per apparaat te kiezen (*Mijn account → Weergave*), voor het dashboard en de meldingen:
  - **Blox**: tegels, in **Small** (compact, meer servers in beeld) of **Large**;
  - **L1nes**: rijen, in **Small** of **Large**.
- **Tabbladen in één balk** (Dashboard, Meldingen, IDS, Kwetsbaarheden) met eigen lijn-iconen en een vlak dat naar het actieve tabblad schuift; de bovenbalk blijft staan als je scrolt. Op de telefoon een **tabbalk onderaan het scherm**, zoals in een app.
- **Tellers op de tabbladen** Dashboard (nieuwe problemen, in de kleur van het ergste) en Meldingen (nieuwe meldingen), sinds je laatste bezoek; per gebruiker onthouden en ook in de titel van het browsertabblad.
- **Per server:** de algehele status, de checks met problemen en mini-meters voor CPU, RAM en disk. Uitklappen toont alle checks in **groepen** (📊 Systeem met CPU, RAM en disks, 🌐 Bereikbaarheid, ⚙️ Services, 📦 Containers, 🖥️ Commando's, 📜 Logbestanden, 🔒 Certificaten, 🏠 Home Assistant), elke groep in en uit te klappen. Problemen staan bovenaan in de groep **⚠️ Problemen**, per status (critical, warning, onbekend, bevestigd); een nieuw probleem klapt die groep vanzelf open.
- **Popup per check** (klik op een check): status en bericht, instellingen, beschikbaarheid en de **laatste 5 statuswijzigingen** met het bericht dat ze veroorzaakte en of en via welke kanalen er gemeld is (of waarom niet).
- **Grafieken** van 6 uur tot 30 dagen, met minimum, gemiddelde en maximum, en bij disks de trend.
- **Beschikbaarheid** per server over 24 uur, 7 dagen en 30 dagen.
- **Zoeken** en filterchips (*Problemen*, *Onderhoud*, per soort apparaat).
- **🖥️ Servers en 🌐 netwerk apart** (soort apparaat): vanzelf herkend via de agent en SNMP (access point, switch, router, firewall, NAS, UPS, printer, camera) of zelf gekozen; **📷 camera's** ook aan de naam, met aanbevolen checks (ping, RTSP, webpagina); bij *Alle* een blok per groep, met één groep gekozen volgen ook de tellers en tegels.
- **Overig:** licht en donker thema, werkt op je telefoon, ververst elke 20 seconden, en **↻ Alles nu checken**.
- **Meldingenoverzicht per dag:** elke verstuurde melding met de kanalen waarlangs hij ging, gegroepeerd per dag met tellers. Vandaag staat open. De bewaartermijn stel je zelf in. **Sorteren** op tijd, status, server, check, bericht of kanalen (klik op de kolomkop; in Blox een keuzelijst), en klik op een melding voor de popup van de check.
- **🎯 Urgentie en risico** (vanaf 1.144.0): één urgentie (0–100: ernst, duur, bereikbaar, afhankelijke servers, beveiliging) voor Incidenten en *Nu doen*; een risico per server (0–100: open incidenten, bereikbare CVE's, hardening, agent, afwijkingen) op het dashboard, in de REST API en als MQTT-sensor. Het dashboard begint met een statuszin, *Nu doen* en de servers die aandacht vragen.
- **⚠ Incidenten** (vanaf 1.142.0; samenvoegen vanaf 1.144.0: zelfde IP of een aanvalsketen wordt één incident met stappen en een pad): één lijst uit monitoring (critical ≥ 5 min), IDS (critical) en kwetsbaarheden (actief misbruikt), met status (open, in behandeling, opgelost), eigenaar, notities en een tijdlijn; vanzelf opgelost als de bron herstelt.
- **🎯 Aanvalspad en impact** per server (vanaf 1.146.0): open poorten met pakket en ergste kwetsbaarheid, open incidenten en afhankelijke servers (blast radius).
- **🎯 Doelen (SLO) en "na de actie"** op Analyse (vanaf 1.146.0): melding binnen 5 minuten, agents rapporteren, slagingspercentage van zelf herstellen, onterechte automatische blokkades en slimme drempels die meer ruis gaven.
- **📄 Rapport als PDF** (vanaf 1.142.0; eerste pagina *In één oogopslag* met MTTD/MTTR, SLO's en de 5 grootste risico's vanaf 1.146.0): afdrukbaar rapport over een periode (samenvatting, incidenten, beschikbaarheid, lastigste checks, beveiliging, kwetsbaarheden, wat nu aanpakken).
- **🔌 Verkeer zonder UniFi** (vanaf 1.188.0, agent 1.80.0): verkeer via SNMP op switches (poort met één apparaat) en via de netwerkkaarten van servers met de agent; UniFi gaat voor, zonder sprong bij een andere bron.
- **🌐 Internetlijn, diensten, check Verkeer en rapport** (vanaf 1.187.0): internetverkeer per dag met datalimiet (melding bij 80% en 100%) en verwachting, diensten per apparaat (UniFi DPI), checktype *Verkeer* (snelheid of hoeveelheid vandaag), rapport *Netwerkverkeer* met de top 10, en ℹ️-meldingen bij ongewoon verkeer.
- **📊 Verkeer per apparaat** (vanaf 1.186.0, UniFi): download en upload per apparaat per uur en per dag, top 10 van vandaag, grafieken in het venster, heatmap van aanwezigheid en verkeer, en een melding bij ongewoon veel of nachtelijk verkeer.
- **⏳ Status bevestigen** (vanaf 1.185.0): een check wordt pas warning of critical (en eventueel weer OK) na N controles achter elkaar, met het label ⏳ 2/3 zolang het nog niet bevestigd is; algemeen en per check, leeg = meteen.
- **🗺️ Netwerk → Overzicht** (vanaf 1.184.0): een netwerkkaart (internet → router → switches en access points → apparaten, uit UniFi), met tellers per soort en per netwerk; klik op een soort om die op de kaart te laten oplichten.
- **🔍 Netwerk → Ontdekken** (vanaf 1.183.0): nieuwe en onbekende apparaten, veranderingen (ander IP-adres, nieuwe of gesloten poorten, nieuwe domeinen via AdGuard, al dagen weg, weer terug), voorstellen om met één klik te bewaken (met de checks die bij de open poorten passen) en de aanwezigheid per uur. Op *Apparaten* groeperen per soort, fabrikant of verbinding.
- **📋 Functies** (vanaf 1.182.0): alle functies in één tabel bij *Instellingen → Overzicht → Functies*, met status en een knop naar de instelling (zie de tabel hierboven).
- **🛡️ IDS-beschermingen in de beheerportal** (vanaf 1.181.0): *Instellingen → IDS → Beschermingen* met per bescherming een schakelaar en de drempels. *IDS → Bescherming* is een overzicht met aan/uit en **Instellen**.
- **👁 Beheerportal bekijken voor gebruikers** (vanaf 1.181.0): de rol gebruiker ziet Status, Advies, Rapporten (met **Naar mij**) en het Wijzigingslogboek, alleen om te bekijken. Alleen lezen ziet de beheerportal niet.
- **🎨 Weergave voor nieuwe accounts** (vanaf 1.181.0): de standaard IDS-weergave en beweging, ingesteld in de beheerportal.
- **📬 Rapporten op één plek** (vanaf 1.180.0): *Instellingen → Rapporten* met IDS- en AI-weekoverzicht, maandrapport, kwetsbaarheden, beschikbaarheid, back-ups en datalekken; per rapport aan/uit, week of maand, dag, tijd, e-mail (HTML) en/of push, **Nu versturen**, **Voorbeeld** en **Naar mij**; plus een **Maandoverzicht** met alles in één.
- **🔥 CVE's slimmer** (vanaf 1.179.0): **rugbaarheid** per kwetsbaarheid (0–100: exploits via CISA KEV en SSVC, Exploit-DB, Nuclei en GitHub-PoC's, en praat op Hacker News en Mastodon, plus EPSS), met verloop en een melding als hij veel aandacht krijgt; **Raakt dit jou echt?** met AI (eerst doen, kan wachten, niet van toepassing, per server); en **Eén update, veel opgelost** (de updates die het meeste risico wegnemen). Tokens voor GitHub en Mastodon in de beheerportal.
- **🧰 Agent Manager in kaarten** (vanaf 1.178.0, agent 1.79.0): Rapporteren, Beveiliging, Meldingen, Verbinding, SNMP en Onderhoud, met schakelaars in plaats van keuzelijsten (uit = de algemene instelling, aan = een eigen keuze), en **🧪 Test** in een eigen popup: wat RzSense meteen weet (contact, verbinding, geweigerde rapporten, SNMP) en wat de agent bij zijn volgende ronde over zichzelf meldt (planning, DNS, adres en reserve-adres, klok, ruimte, wachtrij, log).
- **📡 Algemene SNMP-instelling** (vanaf 1.178.0): één SNMP-instelling voor al je apparaten onder *Instellingen → Koppelingen*, met eigen instellingen per apparaat, een overzicht wie wat gebruikt en *Alles testen*.
- **🛣️ Pad-check en Monitoring → Netwerkpaden** (vanaf 1.177.0, agent 1.78.0): het pad van een server naar een doel, per hop vertraging, jitter en verlies (mtr/traceroute), je switches (ping op hun IP, en via UniFi de poort, linksnelheid en uplink), de virtuele laag en de echte doorvoer met iperf3 (kort een ontvanger op een andere server). Meldt bij te veel vertraging of verlies, een switch die wegvalt, een gezakte linksnelheid en lage doorvoer. Als pad in de popup, als netwerkkaart op Monitoring → Netwerkpaden en in het kort bij de server. Vanaf 1.190.0 is dat korte pad een **lichtspoor** dat altijd past: gloeiende punten met label (GW, SW, ISP, DOEL), streepjes voor stille hops en een lichtpuls. Vanaf 1.192.0 installeert de agent iperf3 vanzelf waar de doorvoertest het nodig heeft (niet op Home Assistant of TrueNAS). Vanaf 1.191.0 staat het in CC niet meer in de tegel maar als sectie **Netwerkpad** in de opengeklapte server, met een klik naar Netwerkpaden (pad opgelicht).
- **📦 Monitoring → Containers** (vanaf 1.176.0): alle Podman- en Docker-containers van alle servers in één overzicht: hoeveel er draaien, Failed, Stopped en Paused, CPU, geheugen (met limiet) en schijf, herstarts, 24 uur en of er een check op staat; zoeken, filteren, groeperen per server of stack, details met exitcode en wat die betekent. Container-checks tonen het label **Running / Failed / Stopped / Paused**; netjes gestopt is WARNING, met een fout gestopt of ongezond CRITICAL.
- **🔎 Popup per check** (vanaf 1.176.0): status met meter, grafiek van 24 uur, beschikbaarheid 24 u/7/30 d, storingen en hersteltijd, hoe vaak gecheckt, details per soort, melden (na N, laatst gemeld, escalatie), samenhang, het laatste AI-advies, instellingen en een tijdlijn. In CC is *Nu aandacht nodig* een compacte lijst met filters.
- **📶 Beschikbaarheid per groep** (vanaf 1.169.0): onder Monitoring een tabblad per soort apparaat of per eigen groep (*Thuis*, *Kantoor* …). Per groep zie je wat er nu online is, het percentage over 24 uur, 7, 30 of 90 dagen, het verloop, storingen en downtime, en of het doel (SLA) gehaald is. Klik open voor een tabel per server met een blokje per uur of dag. Ook op de pagina: de minst betrouwbare apparaten en de laatste storingen.
- **📊 Analyse en advies** (vanaf 1.155.0): slimme analyses die gegevens combineren tot advies met bewijs, bijvoorbeeld *open poort → pakket → verouderde versie → CVE (actief misbruikt) → aanvallen van buiten*; veiligheidsscore per onderdeel met verloop; adviezen met risico, moeite, winst, commando's per server en een knop om het meteen te regelen (bijwerken via de agent, blokkeren, IDS op blokkeren …); status gedaan, later of niet van toepassing, en zelf herkennen dat iets is opgelost; nieuwe open poorten, besturingssystemen zonder ondersteuning, verouderde software op apparaten zonder agent (banner van de netwerkscan), verdachte logins; een melding bij wereldwijd bekende, kritieke kwetsbaarheden; analyses van aanvallen (wanneer, gebruikersnamen, werken de blokkades) en het aanvalsoppervlak per server.
- **📊 Analyse** (vanaf 1.106.0, alleen voor beheerders en gebruikers): een eigen tabblad over een periode die je kiest (24 uur, 7, 30, 90 of 365 dagen, of eigen datums), steeds vergeleken met de periode ervoor. Kerncijfers (beschikbaarheid, storingen, hersteltijd, meldingen, IDS, score), **🎯 Wat nu aanpakken** (disks die vollopen, guests zonder back-up, flappende checks, riskante poorten, kwetsbaarheden met een oplossing …), betrouwbaarheid (per server, storingen per weekdag en uur, lastigste checks, responstijden), capaciteit, beveiliging, netwerk (ook UniFi en AdGuard), back-ups en meldingen/ruis. Dagtotalen blijven een jaar bewaard. Een **maandrapport per e-mail** zet je zelf aan of uit.
- **⚡ Live bijwerken** (vanaf 1.107.0): wijzigingen binnen een paar seconden op je scherm; vanaf 1.109.0 **echt live**: alleen wat veranderde wordt bijgewerkt (opengeklapte onderdelen en vensters blijven open), waarden lichten op en tellen mee, een meldinkje bij een nieuw probleem, en het venster van een check werkt live bij. **⌨️ Zoeken en doen (Ctrl+K)**: servers, checks, IP's, tabbladen en instellingen, en met een werkwoord (*onderhoud*, *nu*, *bevestig*, *grafiek*, *blokkeer*) meteen de actie.
- **🟢 Statuspagina** (vanaf 1.107.0): voor huisgenoten of klanten via een geheime link, met de onderdelen die je kiest en 90 dagen per dag. De link met het lokale of het **externe adres** (schakelaar Lokaal/Extern, 1.128.0).

## 4. Meldingen
- **Kanalen:**
  - **Pushover**, met prioriteit en geluid per status, inclusief *Emergency*;
  - **ntfy** (vanaf 1.140.0): via ntfy.sh of een eigen server, naast of in plaats van Pushover, met een knop ✔ Bevestigen; samen met Pushover het kanaal *📲 Push*;
  - **e-mail**: altijd, of als reserve wanneer Pushover faalt;
  - een **Home Assistant-webhook**.
- **Per status** (warning, critical, onbekend, hersteld) stel je in:
  - **wanneer**: nooit, direct of pas na *X* minuten onafgebroken;
  - **herhalen**: elke *Y* minuten;
  - **welke kanalen**.

  Per check kun je dit **overschrijven**.
- **Bundelen per server:** meerdere meldingen binnen 120 seconden (instelbaar) worden één bericht.
- **Melden na N mislukte checks:** een melding pas als een check zoveel keer achter elkaar dezelfde status gaf (per status, onbekend telt mee). Algemeen in te stellen en per check anders; op het dashboard staat **⏳ 2/3** zolang hij nog niet gemeld is.
- **Anti-flapping:** een check die steeds van status wisselt, geeft één melding *"staat te flappen"*. Daarna komt er per status hooguit één melding, tot hij stabiel is. Ook hier geldt *melden na N mislukte checks*: zolang dat aantal niet gehaald wordt, flapt hij stil. De getallen stel je zelf in, algemeen en per check.
- **ℹ️ Info-meldingen** (standaard uit): gebeurtenissen die geen storing zijn: een server is herstart (check **Uptime**, HA OS herstart, SNMP-uptime), een SSL-certificaat is vernieuwd, een agent of RzSense is bijgewerkt. Algemeen en per check in te stellen, stil via Pushover, niet tijdens stille uren of onderhoud; op de pagina Meldingen (kanaal *📋 Meldingenpagina*, standaard aan), met een filter *Alles · Zonder info · ℹ️ Info*.
- **Info-checks:** met de schakelaar *ℹ️ Alleen info-meldingen* geeft een check alleen info-meldingen bij een statuswissel en telt hij nooit als storing (blauwe stip, server blijft groen).
- **🌙 Stille uren:**
  - vensters (van–tot, per dag van de week, ook over middernacht) waarin geen meldingen uitgaan;
  - algemeen, per check eigen vensters, of *nooit stil*;
  - vanaf 1.193.0: **van meting tot melding** zichtbaar in de popup (bevestigen, melden na N, meldregel, wanneer de melding komt), bevestigen **per soort check**, **sneller bevestigen** (elke 20 s) en een knop **Advies toepassen**;
  - vanaf 1.189.0 als **profielen met een naam**, te koppelen aan een eigen groep, soort apparaat, server of check (het fijnste wint), met *critical komt wel door*, een **periode** (vakantie) en **🌙 Nu stil**;
  - na afloop **één bericht** met alles wat dan nog open is.
- **Herstelmelding** zodra het weer goed gaat. Een **nieuwe melding bij escalatie** (warning → critical).
- **Automatisch onderhoud** na herhaalde meldingen voor dezelfde storing, tot hij is opgelost.
- **Testknoppen** per kanaal.
- **◈ Lay-out CC** (vanaf 1.158.0): naast Blox, L1nes en Compact een vierde lay-out, per account: alle pagina's in de futuristische stijl van het Command center (licht en donker), het Command center als dashboard, en kaarten die je per pagina kunt verslepen, smaller of breder maken en verbergen; Meldingen met grafieken per uur en per kanaal; vanaf 1.159.0 ook IDS, Incidenten (geopend/opgelost, per bron), Kwetsbaarheden (per ernst, per server), Netwerk en Analyse als versleepbare kaarten; vanaf 1.160.0 ook de Instellingen (eigen indeling per onderdeel), Mijn account en de Agent Manager; live en TV-modus op elke pagina. Vanaf 1.166.0 staat daaronder ook *Wat het IDS deed (24 u)* en de meest actieve aanvallers, en heeft de grafiek een as en duidelijkere groepen. Vanaf 1.165.0 toont de IDS-pagina de servers als tabel (L1nes) of als *Wat nu aandacht vraagt* met kaarten en chips (Blox en CC), met de modus en het woord Hardening erbij. Vanaf 1.164.0 zijn de servers op Monitoring in CC compacte blokken die met een animatie openklappen tot een tabel met de checks eronder. Vanaf 1.175.0 is dat één raster per groep (zonder aparte sectie met problemen) met tegels met ring, lijntjes en 24 uur, die met een veeranimatie zoals op een Mac openklappen over de volle breedte: meters, *Vraagt aandacht* en *In orde* (ingeklapt na 8), zonder horizontale schuifbalk. Vanaf 1.162.0 gelden alle weergave-keuzes (ook grootte per telefoon/computer, sortering, periode, IDS-weergave en beweging) per account, op al je apparaten.
- **🌍 Internet** (vanaf 1.157.0): bewaakt je internetlijn vanaf de server van RzSense: ping, jitter en verlies (1.1.1.1, 8.8.8.8 en je router, zodat je ziet of het binnen of buiten zit), responstijd (DNS, verbinden, TLS, eerste antwoord) en de snelheid (download en upload via speed.cloudflare.com, op vaste tijden, standaard 4× per dag), vergeleken met je abonnement; met één klik toe te voegen, met een lijn van 30 dagen en een knop om nu te testen.
- **📺 Monitoring live** (vanaf 1.157.0): per server de 3 belangrijkste checks met waarde en lijntje van 24 uur, een balkje met de status per uur, Nu aandacht nodig gebundeld op oorzaak, kerncijfers met trend, een derde lay-out Compact (een blokje per check), een live-lampje en een zachte rode gloed bij critical (uit te zetten per account).
- **🧠 Slimme drempels** (vanaf 1.107.0): RzSense leert per check wat normaal is per uur van de week en zet daar de drempels op (handmatig, slim, of slim met vangnet); elke nacht bijgesteld (hooguit 10%), in het wijzigingslogboek terug te draaien, met een ℹ️ bij een sluipende stijging. Vanaf 1.156.0 ook voor containers (CPU of RAM), DNS, NTP en MySQL, en zet RzSense een handmatige check na 7 dagen zelf op slim met vangnet als dat duidelijk minder warnings geeft (met ℹ️-melding en terug te draaien); *melden na N* stelt hij ook zelf in.
- **🔗 Bundelen over servers heen** (vanaf 1.107.0): meerdere servers tegelijk mis = één bericht met de waarschijnlijke oorzaak.
- **🩹 Zelf herstellen** (vanaf 1.107.0, agent 1.62.0): een service of container herstarten, of een eigen herstelcommando van de server; melden pas als het niet helpt.
- **🐕 Waakhond** (vanaf 1.107.0, agent 1.62.0): een agent meldt via Pushover (of ntfy, vanaf agent 1.72.0) als RzSense onbereikbaar is of de checks stilstaan.
- **⏫ Escaleren** (vanaf 1.108.0, standaard uit): een storing die niemand bevestigt, na een instelbare tijd nog een keer melden, een stap hoger (warning → critical, critical → nood); niet tijdens de stille uren.
- **💡 Slimme voorstellen** (vanaf 1.108.0): elke nacht voorstellen voor *melden na N*, vaste onderhoudsvensters en afhankelijkheden, uit de geschiedenis; jij klikt op Toepassen of Negeren.
- **⏱️ Slim interval** (vanaf 1.108.0, standaard uit): bij een probleem elke 20 seconden checken, als alles een uur goed is trager.
- **📈 Afwijkingen zonder drempels, 💾 disk groeit hard, 🕵️ oorzaak erbij, 📥 heartbeat-ritme** (vanaf 1.108.0): een ℹ️ bij een ongewone waarde of een snel vollopende disk, in de melding wat er vlak ervoor gebeurde, en een warning als een heartbeat later is dan normaal of een vreemde waarde meestuurt.

## 5. Storingen, onderhoud en afhankelijkheden
- **✔ Bevestigen:**
  - per check of per server, met een opmerking;
  - ook via de link in de melding, of in de Pushover-app (*Emergency*);
  - daarna komen er geen meldingen meer voor die storing;
  - na herstel vervalt de bevestiging vanzelf.
- **🔧 Onderhoud:**
  - voor alles, per server of per check;
  - met een duur of een eindtijd, en een reden;
  - checks blijven draaien; wat na afloop nog mis is, wordt alsnog gemeld.
- **🔗 Afhankelijkheden:** valt een router, switch, VPN of hypervisor uit, dan krijg je één melding in plaats van een melding per server erachter.
- **🗓️ Vaste onderhoudsvensters** (vanaf 1.108.0): elke dag of op een weekdag een vaste tijd zonder meldingen, voor een server of één check.

## 6. Home Assistant
- **MQTT discovery:** al je servers en checks verschijnen in Home Assistant als apparaten en sensoren, met de status en de meetwaarden. Er is ook een sensor **Algehele status** en een sensor **Gezondheid** (%). Stopt RzSense, dan worden de sensoren *niet beschikbaar*.
- **Webhook:** elke melding gaat als JSON naar een HA-automatisering, met een bevestig-link.
- **REST-sensoren** via de API (§11).
- **Add-on** voor Home Assistant OS, zie hoofdstuk 12.

- **🤖 AI-advies** (vanaf 1.171.0): uitleg en een oplossing bij storingen, gebundelde storingen, incidenten en IDS-incidenten, van **Claude, ChatGPT, Gemini, Grok** of **Ollama** (lokaal of een eigen server).
  - Op verzoek of automatisch (één advies per storing bij CRITICAL).
  - Daglimiet, en maskeren van namen en adressen voor de cloud.
  - Geheimen gaan er altijd uit.
  - **Diep zoeken** via de agent: vaste commando's die alleen lezen.
  - Optioneel **uitvoeren** van een bestaande herstelactie na bevestiging.
  - Staat het uit, dan is er nergens iets van te zien.
  - **💶 Verbruik en tegoed** (1.173.0): tokens en geschatte kosten per advies, per dag en per maand (prijs per model zelf in te vullen), een bijgehouden tegoed per aanbieder, een **maandbudget** en een melding met een link om op te waarderen als het tegoed op is.
  - **✨ Extra's** (1.173.0), elk met een eigen schakelaar: **geheugen** (👍/👎/*dit loste het op* gaat mee bij dezelfde storing), **niet twee keer betalen**, **terugvallen op Ollama**, een **samenvatting in de melding**, een **weekoverzicht**, een **oordeel bij IDS-incidenten** (alleen een label) en een **verslag** na een opgelost incident.

## 7. REST API
- JSON, met API-sleutels die je in de webinterface beheert.
- **Rechten per sleutel:** 📖 status lezen, 🛡️ IDS lezen, 🐞 kwetsbaarheden lezen, ⛔ IDS blokkeren (met bevestiging) en ✔ checks bedienen; daarnaast optioneel **alleen vanaf** bepaalde IP's of netwerken, en een **geldigheid** van 6, 12 of 24 maanden (verlengen met ✎).
- **IDS:** incidenten, blokkades en logins uitlezen, en IP's blokkeren of deblokkeren, bijvoorbeeld vanuit fail2ban. Je LAN, RzSense zelf en de whitelist weigert de API altijd; in de leerstand wordt een blokkade alleen vastgelegd.
- **Checks bedienen:** bevestigen, onderhoud (check of server) en nu checken.
- Alles wat een sleutel verandert komt in het logboek.
- **Endpoints:**
  - `/status`, met de algehele gezondheid (`health`);
  - `/hosts`, met de beschikbaarheid;
  - `/checks`;
  - `/checks/{id}/history`;
  - `/alerts`.

## 8. Beheer: installeren, bijwerken en back-up
- **Installatie in één commando** op Rocky Linux / RHEL / AlmaLinux 10 (`install-rocky.sh`), inclusief SELinux, firewalld, Apache, PHP-FPM, MariaDB en cron. Debian/Ubuntu kan met de hand of via de setup-pagina.
- **Zijbalk inklappen tot iconen** (1.150.0): op een breed scherm, met de tellers en de naam bij het aanwijzen; per gebruiker onthouden.
- **Monitoring** (1.168.0): servers in drie groepen (open problemen, ✔ bevestigd, in orde); een server met alleen bevestigde problemen is gestippeld en rustig. **config.php opslaan via de hulpdienst** terwijl de map vergrendeld blijft: alleen vaste velden, opnieuw gecontroleerd, database eerst getest, met een reservekopie.
- **Zijbalk** (1.167.0): breedte zelf slepen (per account); in Instellingen en Mijn account wordt de zijbalk het menu van die pagina, met bovenaan **← Terug naar RzSense**.
- **Instellingen** (alleen beheerders): een zijbalk met groepen, één onderdeel tegelijk, alles **meteen opgeslagen** (✔ naast het veld). Begint bij **✅ Hoe staat het ervoor?**: een score en een lijst aandachtspunten (meldkanaal, back-up, 2FA van beheerders, inlogbeveiliging, RzSense-versie, verouderde en onversleutelde agents, HTTPS, kwetsbaarheden, IDS); klik erop en je staat bij de instelling, met een oranje of rood bolletje in de zijbalk. **🔍 Zoeken** door alle instellingen (Enter springt naar het veld en laat het oplichten), uitleg als één regel met **Meer info** (klapt open, altijd dicht bij openen; 1.161.0, op alle pagina's en in de vensters vanaf 1.163.0) of een ⓘ met tooltip, en zelden gebruikte opties onder **▸ Geavanceerd**.
- **Setup in de beheerportal** (1.92.0, alleen beheerders, *Instellingen → Systeem*): **Systeemcheck** (met de cron-regel), **Database en opslag** (verbinding, tabellen, wachtwoord, bewaartermijnen) en **Server en PHP** (tijdzone, basis-URL, ping, PHP-waarden). De losse setup-pagina is er alleen nog voor de installatie (database en eerste beheerder).
- **⬆ RzSense bijwerken vanuit de webinterface:**
  - een dagelijkse controle op GitHub, en **wat er nieuw is**;
  - de knop **Nu bijwerken** (of het label ⬆ bovenin): een venster met de vraag, eventueel eerst een back-up, en daarna een **voortgangsbalk** met waar de installatie mee bezig is;
  - of **automatisch** op een tijdstip naar keuze.
- **Database** wordt vanzelf bijgewerkt bij een nieuwe versie.
- **📜 Wijzigingslogboek** (1.97.0): wie veranderde welke instelling, server, check of welk sjabloon, met oud en nieuw. *↩ Terugzetten* per wijziging, ook voor een verwijderde check of server. Bewaartermijn instelbaar (30 dagen tot 3 jaar); geheimen nooit leesbaar.
- **📦 Exporteren en importeren** (1.97.0): servers, checks, sjablonen en eventueel instellingen als JSON, zonder geheimen. Importeren voegt alleen toe, met eerst een voorbeeld.
- **🧪 Meldketen testen** (1.97.0): één testmelding door alle kanalen, met per kanaal ✓ of ✕.
- **Gezondheid van RzSense** (1.96.0; vanaf 1.151.0 onder *Systeem → Database en server* en *Systeem → Prestaties en fouten*): de grootte en groei van de database (met wanneer de disk vol is), de duur van een cron-ronde, de traagste checks en PHP-fouten van de laatste 24 uur.
- **Niets meer dubbel** (1.122.0): op Status samengevoegde regels voor Agents en Beheer via https; aan/uit van IDS-onderdelen alleen op *IDS → Bescherming*, met de drempels achter ⚙️ op de kaart.
- **Agents in de stijl van het dashboard** (1.121.0): tegels, *Nu aandacht nodig* met bijwerken en opruimen, een kaart per agent met badges, *Standaardinstellingen* apart, en de Agent Manager als pagina met Functies en Verbinding naast elkaar.
- **Beheerportal in een nieuw jasje** (1.120.0): nieuwe indeling in 8 groepen met lijn-iconen (Overzicht, Monitoring, Meldingen, IDS, Beveiliging, Agents, Koppelingen, Systeem), samengevoegde onderdelen (Advies, Wanneer melden, Database en server, Gezondheid), één titel met een korte zin per pagina, lange uitleg als gewone tekst op de kaart (1.148.0; eerder oranje ⓘ-blokken), aandachtspunten in een oranje balk bovenaan met een bolletje bij de kaart waar ze over gaan (1.148.0), schakelaars als rijen en titels zonder emoji.
- **🚀 Snelheid** (1.119.0, *Systeemcheck*): OPcache, compressie en browsercache van de webserver (met een stukje config voor Apache en nginx), de traagste verzoeken, agent-rapporten en cron-onderdelen van de laatste 24 uur, de tabellen met ongebruikte ruimte en **Database optimaliseren**. Daarnaast sneller: code pas laden als hij nodig is, beschikbaarheid uit de dagtotalen, live bijwerken alleen voor wat veranderde, Meldingen en IDS standaard 7 en 14 dagen (ouder op verzoek).
- **🔐 Verbindingscontrole per agent** (1.100.0): eigen sleutel, versleuteld, https, certificaat gecontroleerd, IP-slot, versie, sleutel vernieuwd, geen oud token, instellingen op de server opgeruimd, agent-script ongewijzigd (SHA-256), reservekopie. Te zien in de Agent Manager, de agenttabel en *Hoe staat het ervoor?*. ⚠️/✅-melding als een agent onveilig wordt. **🧹 Opruimen** per agent of allemaal: tokens en de oude algemene sleutel wissen, en de agents schoon opnieuw installeren met een reservekopie. *Alleen agents met een eigen sleutel* (koppelen met een koppelcode blijft kunnen). Op de server: `rzmon-agent-setup --status`, met verbinding, TLS, versleuteling, contacten, fouten en wat er verstuurd wordt.
- **📡 Contact met de agent** (1.99.0): per agent het laatste en het volgende contact, live aftellend, in de agenttabel, de Agent Manager en op de serverkaart; 🟠 te laat, 🔴 offline, ⚡ realtime; het ritme dat de agent werkelijk aanhoudt (⚠️ als hij minder vaak komt dan ingesteld); sorteren op langst geen contact.
- **📡 Verbindingen en taken** (*Instellingen → Overzicht*): wat er bij RzSense binnenkomt (agents met hun ritme en realtime, browsers, API-sleutels, heartbeats) en hoe RzSense's eigen achtergrondtaken lopen (cron, updates zoeken, blocklists, GeoIP, datalekken, kwetsbaarheden, certificaat, MQTT, back-up): laatst gelukt, volgende keer en eventuele fout. Lukt een taak te lang niet, dan volgt een ⚠️-melding via je gewone kanalen (en een ✅ als het weer lukt).
- **💾 Back-up en herstel:**
  - automatisch elke dag, week of maand, of met de hand;
  - een zip met de database en `config.php`, versleuteld met AES-256;
  - downloaden, uploaden en terugzetten in de webinterface, met een veiligheidsback-up vooraf en automatisch terugdraaien als het misgaat;
  - ook via de terminal (`install.php --backup` / `--restore`).
- **Databasewachtwoord vernieuwen** met één knop, via de hulpdienst.
- **Agents bijwerken** met één knop (per server of allemaal), of automatisch.

## 9. Toegang en beveiliging
- **Gebruikers** met rollen (*beheerder*, *gebruiker*, *alleen lezen*) en **tweestapsverificatie** (TOTP).
- **Wachtwoord vergeten:**
  - een herstellink per e-mail, 30 minuten geldig en één keer te gebruiken;
  - bij tweestapsverificatie ook de code nodig;
  - met een logboek.
- **Lokaal netwerk:** optioneel inloggen zonder wachtwoord op je LAN (*alleen lezen* of beheerder); van buitenaf blijft de login nodig.
- **Reverse proxy** (SWAG, Nginx Proxy Manager, Traefik, Cloudflare Tunnel): het echte IP en https worden alleen vertrouwd van proxy's die je zelf opgeeft; proxy-kopteksten van een onbekend adres = bezoeker van buiten, met een ⚠️-melding.
- **🔐 Certificaat voor de agents**: in een venster het adres van de agents, het eigen certificaat van RzSense (namen, geldigheid, vingerafdruk), de vastgezette vingerafdruk en per agent of hij hem kent; **downloaden** (.crt, met je wachtwoord) en **vernieuwen** (nieuwe sleutel, 2 jaar, automatische en eigen namen, via de hulpdienst als root, met reservekopie en terugzetten bij een fout); agents gaan vanzelf mee; dagelijkse controle (ℹ️ 14 dagen vooraf, ⚠️ verlopen).
- **🔓 Datalekken (Have I Been Pwned)**, met een hoofdschakelaar (standaard uit):
  - **wachtwoorden** (gratis, k-anonimiteit: alleen 5 tekens van de hash gaan naar HIBP): waarschuwing bij het instellen, **verplicht een nieuw kiezen** na inloggen met een gelekt wachtwoord (dan wordt een gelekt wachtwoord geweigerd); drempel instelbaar;
  - **e-mailadressen** (API-sleutel): van gebruikers, een eigen lijst en een geverifieerd **domein**, dagelijks en gespreid; ⚠️ bij een nieuw lek met wachtwoorden, anders ℹ️; eerste controle als één samenvatting;
  - tabblad **Datalekken** bij Kwetsbaarheden (tellers, tegels, filters, per adres de lekken met gegevenssoorten, ✔ afgehandeld); MQTT-sensoren; *Hoe staat het ervoor?*;
  - **per account** aan of uit (nieuwe accounts aan).
- **🏠 Alleen lokaal inloggen** per account (bv. een noodaccount zonder 2FA): van buiten geweigerd met een ℹ️-melding (eigen rij in Systeeminfo), ook onthouden apparaten en open sessies; er blijft altijd een beheerder die van buiten mag.
- **📱 Ingelogde apparaten**: elke sessie (wachtwoord, 2FA, onthouden apparaat, LAN) met apparaat, IP (🏠/🌍), hoe, sinds en laatst actief. Gebruikers zien en loggen hun eigen sessies uit; beheerders zien alle sessies van alle gebruikers, met *Uitloggen*, *Alles van deze gebruiker uitloggen* en *Iedereen uitloggen behalve mij*. Uitloggen werkt meteen.
- **🔒 Beheer via https:**
  - een **beheeradres** (bv. via SWAG met Let's Encrypt) en **Beheer alleen via https**: http gaat naar het beheeradres, met **HSTS** (eerst 1 dag, na 7 dagen zonder problemen 1 jaar); agents en de REST API blijven via http werken;
  - de **certificaatkaart**: uitgever, namen, geldigheid, vingerafdruk, vertrouwd, en of de lokale **DNS-rewrite** werkt; dagelijks gecontroleerd, ℹ️ 14 dagen voor het verloopt, ⚠️ als het verlopen of ongeldig is;
  - de **nooddeur** (https op het IP-adres van RzSense, alleen van binnen) en het noodcommando `install.php --https-off`;
  - een **rode balk** op de inlogpagina via http, en **inloggen via http weigeren**;
  - **2FA verplicht van buiten** je interne netwerken (onthouden apparaten alleen als ze met 2FA zijn onthouden).
- **Bescherming:** wachtwoorden met bcrypt, afremmen van inlogpogingen, CSRF-bescherming, veilige cookies, een eigen token per agent, en API-sleutels alleen als hash.
- **🔐 Beveiligde verbinding** (agent 1.50.0+): een **eigen sleutel per agent**, geen token meer over de lijn, elk verzoek versleuteld of ondertekend; nieuwe agents koppelen met een **koppelcode** (eenmalig, 15 minuten), bestaande stappen vanzelf over; sleutels vanzelf vernieuwd (elke 30 dagen); het **certificaat van RzSense vastgezet** bij een eigen certificaat; agents alleen vanaf hun **bekende IP-adres** (nieuw adres = geweigerd tot je het toestaat); vastzetten en het IP-slot ook **per agent** aan of uit; het installatiecommando controleert script en agent met SHA-256.
- **🌍 Agents via https** (agent 1.51.0): het adres van RzSense **vanuit RzSense wijzigen** (per agent of alle tegelijk; de agent test het eerst met zijn eigen sleutel), een **reserve-adres** als de proxy niet bereikbaar is (⚠️/✅), een kolom **Verbinding** (https via proxy, https, http, reserve) en **Agents alleen via https** (algemeen en per agent).
- **🔒 Lokaal via https zonder proxy** (agent 1.53.0): rechtstreeks naar `https://<IP van RzSense>` met het eigen certificaat, **vastgezet** door de agent; de controle geldt **per adres**, zodat een rechtstreeks adres en het proxy-adres (DNS-rewrite) samen gewoon adres en reserve-adres kunnen zijn. Adresvenster met een keuzelijst (IP-adressen, namen, beheeradres of zelf invullen, met **Testen**), http of https naar keuze, reserve-adres uit te zetten. **Certificaat vernieuwen in twee stappen**: eerst klaarzetten, de agents krijgen de nieuwe vingerafdruk, dan pas wisselen (uiterlijk na 7 dagen, of *Nu wisselen*). Reserve-adres: één samengevatte ⚠️-melding na 5 minuten.
- **Hoe staat het ervoor?** wijst op een proxy die via http doorstuurt (met de SWAG-regels om te kopiëren), inloggen via http (knop *Weigeren*), split DNS als tip, en stelt *Agents alleen via https* voor (knop *Aanzetten*).
- **🔐 Versleutelde agents** (agent 1.49.0+): rapport, voortgang en instellingen versleuteld (AES-256 + HMAC, tegen opnieuw afspelen), het antwoord ook; één sleutel met **🎲 genereren** (de vorige nog 24 uur geldig), *uit* / *aan* / *verplicht*, ook **per agent**; 🔒 per server.
- **ℹ️ Systeeminfo per melding:** voor elke melding van RzSense zelf (updates, agents, logins, blokkades, kwetsbaarheden, ⚠️ niet-geautoriseerd) Pushover (stil of met geluid), e-mail, Home Assistant en de meldingenpagina apart aan te vinken, met een 🧪 testknop.
- **🔐 Inlogbeveiliging:** na een in te stellen aantal mislukte pogingen (ook 2FA) binnen een in te stellen tijd het **account** en/of het **IP-adres** (niet van je LAN) blokkeren, voor een in te stellen tijd of tot een beheerder deblokkeert; vanaf je LAN kun je een geblokkeerd account nog gebruiken; ⚠️ + Pushover.
- **⛔ Blokkades** op IP-adres of netwerk voor heel RzSense (webpagina, login, API, agents), ook met één klik vanuit Meldingen; jezelf blokkeren kan niet.
- **🖥️ Proxmox VE** (1.105.0, agent 1.61.0): de agent leest Proxmox zelf uit (guests, opslag, ZFS, cluster, back-ups per guest en de back-uptaken). Checks voor het overzicht van de node, één guest, back-ups (2/7 dagen, per guest aan te passen, ook *in geen back-uptaak*), opslag en ZFS; *＋ Aanbevolen checks*, een overzicht per node met ＋ Check per guest, en losse nodes samen als **cluster**. Eigen tabblad in de Agent Manager.
- **📡 UniFi, AdGuard Home en weren** (1.104.0): namen, wifi-netwerk en access point uit UniFi (ook Network 10; lokaal account of API-sleutel), namen uit AdGuard Home en per apparaat **met welke domeinen het praat**. **⛔ Weren** van een apparaat: van het wifi (UniFi), geen DNS (alle AdGuard-instanties) en geen toegang tot RzSense en je servers, voor 1 dag, 7 dagen of tot je het weer toelaat; RzSense, je router, de controller en je servers kun je niet weren.
- **🔎 Netwerkscan** (1.103.0, standaard uit): alle apparaten op je lokale netwerken op het tabblad **Netwerk**: IP, MAC, fabrikant, naam (DNS, NetBIOS, mDNS, UPnP), open poorten en de soort apparaat. Elk uur een snelle ronde, een paar keer per dag dieper. Riskante poorten (Telnet, FTP, VNC …) in rood met advies, een ℹ️-melding bij een nieuw apparaat, en met één klik toevoegen aan RzSense, bekend markeren of negeren.
- **⏳ Wachtende apparaten** (1.102.0): een browser van buiten die geweigerd wordt (404, alleen toegestane apparaten) komt op een lijst in de beheerportal, met een melding. Toestaan voor één gebruiker (de bezoeker merkt niets tot je het toestaat; daarna het inlogscherm), negeren of het IP blokkeren. Alleen browsers uit je normale landen, geen scanners of bots.
- **🔎 Incidentvenster met advies** (1.101.0): klik op een IDS-incident voor uitleg, hoe erg het is, *Was jij dit?* (ja = afgehandeld, nee = noodstappen), stappen en commando's om te kopiëren, en alles over het IP (DNS-naam, eigenaar via RDAP, abuse-adres, hosting/VPN, bekende aanvaller, tijdlijn; met korte time-outs en 7 dagen bewaard), plus alle knoppen. De hardening-punten klappen open met uitleg, controleren en oplossen.
- **🔁 Versie kiezen bij bijwerken** (1.90.0): elke versie uit de changelog, ook terugzetten naar een oudere (met wachtwoord); automatisch bijwerken pauzeert zolang je niet op de nieuwste zit.
- **📜 Logs van webdiensten** (1.89.0, agent 1.58.0): SWAG (aanvalspaden, scanners, veel 404/401/403, zonder hostnaam), Home Assistant en Vaultwarden (mislukte logins). Logs worden zelf gevonden (of zelf in te vullen), elk scenario en elke bron aan/uit, blokkeren op al je servers, je eigen netwerk nooit. Blokkades werken nu ook vóór Docker.
- **🧰 Hardening, isoleren en tijdlijn** (1.88.0, agent 1.57.0): hardening-score per server met advies per punt (incident als iets slechter wordt), een server met één klik **isoleren** (vervalt vanzelf, ook zonder RzSense; vanaf 1.172.0 een rode **banner** bovenaan IDS, Dashboard en Monitoring met reden, door wie, afteller en opheffen met wachtwoord), een gebruiker op een server vergrendelen, en een **tijdlijn per IP** over al je servers.
- **🕸️ Webshell, persistentie en uitgaand** (1.87.0, agent 1.56.0): melding als een webserver een shell of `curl` start (optioneel het proces stoppen), bij een nieuwe automatisch startende dienst, en bij verbindingen naar bekende aanvallers (critical, optioneel uitgaand blokkeren, ook voor containers) of vreemde landen (warning, aan te leren). Vrijgeven met één klik.
- **🕶️ Onzichtbaar van buiten** (1.86.0): van buiten niet herkenbaar als RzSense (gewone 404, geen versie, agent-script alleen met downloadsleutel). Optioneel de webinterface alleen op **toegestane apparaten** per gebruiker, alleen vanuit je normale landen, met een **noodlink** als je sleutel kwijt is.
- **🗝️ Passkeys** (1.86.0): inloggen met Face ID, vingerafdruk of beveiligingssleutel, phishing-bestendig; wachtwoord met 2FA als reserve.
- **⛔ Blokkades en whitelist** (1.83.0; vanaf 1.92.0 in het tabblad *IDS → Blokkades*): één overzicht van alles wat geblokkeerd is: IP's in RzSense, vergrendelde accounts en IDS-blokkades per server. Er zijn tabbladen *Nu geblokkeerd* en *Eerder*, met de reden, het land, filters en zoeken.
  - Per regel kun je **🔓 deblokkeren** of **✅ op de whitelist** zetten. Dat geldt 1/7/30 dagen of altijd, je bevestigt met je wachtwoord en het komt in het auditlog.
  - De whitelist kent drie soorten: een IP of netwerk (RzSense en het IDS op alle servers), een account (nooit vergrendelen, het IP van de aanvaller wel) en een naam die geen honeypot-naam is.
- **Beveiligingsmeldingen:** ⚠️ + Pushover bij een niet-geautoriseerde verbinding (fout token, verkeerde sleutel, verkeerde API-sleutel, 3 mislukte logins); ℹ️ bij elke login in RzSense en als een agent (opnieuw) verbindt.
- **Gevoelige acties** (back-up downloaden of terugzetten, databasewachtwoord, commando's toestaan) vragen om je wachtwoord.
- **Logboek** van gevoelige wijzigingen.
- **🛡️ IDS (intrusion detection)**, pagina *IDS*:
  - standaard **uit**; met een schakelaar **leren** (alleen melden) of **blokkeren** (met je wachtwoord);
  - een **basismeting** per server (standaard 24 uur), daarna alleen afwijkingen; *Dit is normaal* en *basismeting opnieuw*;
  - meldt gewijzigde gevoelige bestanden, nieuwe gebruikers (en een tweede root), poorten, SUID-programma's en kernelmodules, verdachte processen, reverse shells, brute force, inlog na brute force, root-login, logins vanaf nieuwe IP's en **elke SSH-login van buiten** (met melding); logins van binnen als ℹ️ systeeminfo, en login-incidenten van binnen sluiten na 24 uur vanzelf; **🍯 honeypot** (honeypot-gebruikers en een honeypot-poort: één poging = blokkeren; vanaf je eigen netwerk critical), **groepswijzigingen** (wheel, sudo, docker …), PAM en andere achterdeuren, **automatische blokkades op alle servers** en een **📬 wekelijks overzicht** per e-mail; **☠️ bekende aanvallers** (Spamhaus DROP en FireHOL level 1, vooraf geblokkeerd), **🌍 land per IP** (DB-IP Lite, lokaal; een login uit een ander land is critical) en **open poorten met een actief misbruikte kwetsbaarheid** (KEV);
  - **critical** ook naar Pushover (ook tijdens stille uren), **warning** alleen op de pagina IDS;
  - **blokkeren** op de aangevallen server: automatisch bij brute force (24 uur; bij herhaling binnen 30 dagen 7 dagen; permanent met de hand) of met één klik, en **deblokkeren**; je LAN, RzSense en een eigen lijst nooit automatisch;
  - **📁 bestanden bewaken** (FIM): eigen paden (algemeen en per server, met jokertekens zoals `/var/www/**/*.php`), met hash, grootte, rechten en eigenaar; nieuw, gewijzigd, verwijderd of SUID erbij; elke minuut snel, dagelijks volledig; veel wijzigingen tegelijk als één incident; *＋ Aanbevolen*, *goedkeuren* en *niet meer bewaken*;
  - TrueNAS SCALE standaard alleen detecteren; per server **blokkeren toestaan** (1.174.0, agent 1.77.0);
  - **🛰️ endpoint-bewaking** (1.110.0, agent 1.63.0; algemeen en per server aan/uit): processen, gevoelige paden en kernelmodules (auditd), verbindingen en luisterpoorten, nieuwe bestanden in tijdelijke mappen en webmappen (inotify) en containers, op het moment zelf; getoetst aan **eigen regels met uitleg** en **121 Sigma-regels van SigmaHQ**; RzSense **stopt het proces**, **blokkeert het IP** of **isoleert de server** (elk los aan/uit, alleen in de modus Blokkeren); ontbrekende onderdelen (auditd, inotify-tools) staan onder de servernaam, met **installeren via de agent**; tabbladen **🛰️ Live** en **🔏 Regels**; **🛡️ uitzonderingen** (1.132.0): wat normaal is per server, groep of overal, met voorwaarden (programma, ouder, pad, IP …), **sets met één knop** (systeemonderhoud, containers, Plex, back-up) en **voorstellen uit je eigen meldingen** met het aantal erachter; toegestaan = groene regel in Live; exporteren/importeren;
  - **🧬 afwijkingen** (1.111.0): RzSense leert per server 14 dagen (instelbaar) wat normaal is en meldt daarna wat die server nog nooit deed (een dienst die iets nieuws start, een nieuw programma dat luistert of naar buiten belt, een nieuwe gebruiker, een nieuw land, ongewoon veel processen, actief op een ongewoon tijdstip …), met een score; vanaf een drempel een incident; *Leren afronden* en *Opnieuw leren* per server;
  - **🔗 ketens en combinaties** (1.111.0): webaanval → code op de webserver, inloggen → rechten of achterdeur, binnenhalen → contact naar buiten, meerdere aanvalsstappen, veel afwijkingen tegelijk en een login vanaf een gehackte eigen server; critical met alle stappen in volgorde, en in de modus Blokkeren de aanvaller blokkeren of de server isoleren;
  - **🌐 dreigingsinformatie** (1.112.0): AbuseIPDB (eigen API-sleutel in de beheerportal), GreyNoise, Shodan InternetDB, CIRCL hashlookup, abuse.ch (Feodo Tracker, URLhaus, en met een sleutel MalwareBazaar en ThreatFox) en Tor; een oordeel per IP en bestand bij het incident en het IP-adres; bekende aanvallers blokkeren, een login van een bekende aanvaller critical, onschuldige scanners vanzelf afhandelen, verbindingen met botnet-servers meteen critical, bekende malware, aanvallen op een lek dat de server echt heeft; geblokkeerde aanvallers melden bij AbuseIPDB (optioneel);
  - **🧭 netwerk** (1.113.0): **DNS-controle via AdGuard** voor elk apparaat (malware- en botnetdomeinen van URLhaus/ThreatFox, Safe Browsing, antwoorden naar bekende aanvallers, DNS-tunnels en verzonnen domeinen), **Suricata**-alerts via de agent, en **YARA**-controle van nieuwe bestanden met regels van RzSense (webshells, miners, botnets, rootkits);
  - **🛡️ Bescherming** (1.115.0): alles wat het IDS doet als kaarten, met ernst, actie, schakelaar, cijfers van 7 dagen en aandachtspunten; per incident **Waarom / Trigger / Actie** met de stand van nu (ook in Pushover), en een actielabel in de lijst;
  - **moderne IDS-pagina** (1.117.0): score-tegel met ring (hardening en toestand), *Nu aandacht nodig* met de open critical-incidenten en ketens, serverkaarten met een staafje per dag, een uitklapbare grafiek *Aanvallen per dag* en de werkbalk op één regel; uit te zetten in de instellingen;
  - **gebundelde incidenten doorlopen** (1.116.0, 1.118.0): nummers onderin het incidentvenster voor precies de incidenten van die regel (• open, ✔ afgehandeld), pijltjestoetsen, na afhandelen vanzelf naar het volgende open incident, bolletjes met uitleg bij de muis (blauw = bundel, paars = vaker gezien), en per incident de herhalingen (*Gebeurtenis 1 2 3*);
  - **✔ RzSense zelf toegestaan** (1.114.0): wat de agent, RzSense (web, API, cron) en het verkeer met RzSense doen, wordt nooit tegengehouden of gemeld, maar wel gelogd als groene regel in Live (samengevat per rapport, apart als een regel geraakt zou zijn);
  - **Live Attack Flow** (1.153.0): in de stijl van Stromen, met bewegende deeltjes (snelheid = pogingen per minuut), een schild waar het IDS tegenhoudt en een ring om de server die nu geraakt wordt; bovenaan de **grootste aanvaller nu** met wat het IDS deed, *Nu bezig* en de IDS-acties van het laatste uur; onderaan een lopende band met gebeurtenissen en de **top 10**; de stroom toont **altijd tien aanvallers**: eerst wie nu (15 min) aanvalt, aangevuld met de grootste van de laatste 24 uur (gedimd) (1.172.0); venster *Nu (15 min)* en 1 uur, elke 10 seconden bijgewerkt; de beweging is in te stellen (stil, rustig, vol);
  - **Attack Flow** (1.152.0): aanvaller → doelwit → dienst → afloop, met **waargenomen** (doorgetrokken) en **afgeleid** (stippellijn) uit elkaar, interne aanvalspaden (een eigen server die zelf aanvaller wordt), samenvoegen per IP, live bijwerken en een **tijdlijn om af te spelen**; de popup per IP met *In het kort*, pogingen per minuut, elke gebeurtenis (met bronpoort en wie het zag) en waar het IP wel en niet geblokkeerd is, ook in het venster van elk IDS-incident;
  - **SSH-sessies** (1.152.0, agent 1.74.0): elke geslaagde SSH-login met duur, methode en bronpoort, en elke losse mislukte poging (IDS → Toegang → SSH);
  - **🗺️ Aanvalskaart** (1.149.0): wie valt wat aan, via welke dienst of poort, en is het geblokkeerd. Stromen van bron naar doelwit met bewegende bolletjes in de richting van het verkeer (uitgaand paars en andersom), een wereldkaart (Natural Earth, zonder kaartdienst), filters op periode, server, land, soort en richting, een popup met details en de tijdlijn van het IP, en een kaartje op het dashboard;
  - **🧭 MITRE ATT&CK-overzicht** (1.111.0): alle meldingen per tactiek en techniek over 7, 30 of 90 dagen, met de dekking van de regels;
  - **payload-inspectie** van webverzoeken via SWAG: SQL-injectie, XSS, command injection, path traversal, Log4Shell, SSRF, SSTI en meer, bekende exploits met hun CVE en scanners; het verdachte stuk gedecodeerd en gemarkeerd in het incident (**Inspectie**, met MITRE ATT&CK);
  - drie tabbladen: **Incidenten**, **🔑 Toegang** (logins, sudo en su per dag, met filters, sorteren, 🙈 negeren en ⛔ blokkeren; los aan/uit te zetten) en **Blokkades**, plus een knop **⛔ IP blokkeren** voor een IP-adres naar keuze.
- **🐞 Kwetsbaarheden**, pagina *Kwetsbaarheden* (standaard **uit**):
  - de agents (1.47.0+) sturen hun pakketlijst mee (dpkg of rpm), RzSense leest die van zichzelf; RzSense zoekt de CVE's op bij **OSV.dev** met de adviezen van de distributie (geen vals alarm door backports; alleen pakketnaam, versie en distributie gaan naar OSV);
  - per server: pakket, geïnstalleerde versie, **opgelost in**, CVE's per ernst, **🌐 bereikbaar** (luistert op het netwerk), **⚠️ actief misbruikt** (CISA KEV), **↻ herstart nodig**; tellers, zoeken en filters, en de hele softwarelijst;
  - klik op een CVE: omschrijving, CVSS met uitleg, EPSS, adviezen en links, en per server het commando om bij te werken;
  - **bijwerken via de agent** per pakket of *alle beveiligingsupdates* (beheerders, met wachtwoord, alleen als commando's als root aan staan; anders het commando om zelf uit te voeren), met een **voortgangsbalk** (stap, percentage, pakket en uitvoer; agent 1.48.0), daarna meteen een nieuwe scan en een ℹ️ info-melding;
  - **risicoscore 0–100** per pakket (CVSS, KEV, EPSS, bereikbaar; vanaf 1.141.0), lijst op risico en filter *🔥 Hoog risico*;
  - **gecontroleerd na bijwerken**: wat de volgende scan echt opgelost vond, en *Opgelost (30 dagen)*;
  - **SBOM-export** per server (CycloneDX 1.5 met purl en kwetsbaarheden);
  - **risico accepteren** met een verplichte reden en een einddatum (daarna telt hij weer mee); ℹ️ info bij een nieuwe critical of actief misbruikte CVE; REST API (`/vulnerabilities`) en MQTT-sensoren;
  - ondersteund: Debian, Ubuntu, Rocky Linux, AlmaLinux (en RHEL/CentOS), Alpine.
- **Webbeveiliging** (vanaf 1.143.0): Content-Security-Policy met nonce, nosniff, Referrer- en Permissions-Policy; wachtwoorden met Argon2id (bestaande ongemerkt omgezet bij inloggen); in GitHub Actions PHPStan, een geheimencontrole en een CSP-controle.
- **Hulpdienst als root** voert alleen vaste taken uit. Hij weigert als de RzSense-map niet veilig is (ontgrendeld, of bestanden die niet van root zijn).

## 10. De agent (Linux)
Een bash-script (`rzmon-agent.sh`) dat elke minuut een rapport naar RzSense stuurt. Het werkt op Rocky/RHEL/AlmaLinux/Fedora, Debian/Ubuntu, Raspberry Pi OS en Proxmox VE.
- **Gecomprimeerde rapporten** (agent 1.73.0, RzSense 1.147.0): gzip vóór het versleutelen, pas als RzSense het kan, uit te zetten met `COMPRESS=0`; RzSense weigert een zip-bom.
- **Opgebouwd uit modules** (`agent/src/*.sh`, vanaf agent 1.73.0), samengevoegd tot één bestand met `tools/build-agent.sh`: servers krijgen nog steeds één ondertekend bestand.

**Installeren en beheren**
- **Installatie met één commando** uit de *Agent Manager*:
  - ontbrekende hulpprogramma's installeren;
  - een systemd-timer met afscherming (of cron);
  - een testrapport sturen.
- **`rzmon-agent-setup`:**
  - `--test`: een verbindingstest die stap voor stap zegt wat er mis is;
  - `--update`;
  - `--uninstall`;
  - `--certs`.
- **Bijwerken vanuit RzSense** (knop of automatisch): de agent haalt de nieuwe versie bij je eigen RzSense. Hij controleert het SHA-256-controlegetal, of het script geldig is en of het de juiste versie is, en meldt de uitkomst terug. `REMOTE_UPDATE=0` zet dit uit.
- **Instellingen** in `/etc/default/rzmon-agent`, alleen leesbaar voor root. Eigen regels blijven staan bij opnieuw installeren.
- **⏱ Tik vanuit RzSense:** hoe vaak de agent rapporteert (30 s, 60 s, 2 min, 5 min), standaard in de instellingen en per server in de Agent Manager. Zware onderdelen (diepere controle, extra tests, logbestanden, commando's) volgen het interval van hun check. RzSense beoordeelt agent-checks meteen als het rapport binnenkomt.
- **⚡ Realtime-watcher** (per server aan/uit, standaard uit): de dienst `rzmon-watch` volgt het systemlog (logins, sudo, su, services die stoppen of crashen) en met inotify de 🔴-paden van *Bestanden bewaken*, en start bij een gebeurtenis meteen een rapport. Binnen enkele seconden in RzSense. `REALTIME=0` zet het op een server uit; niet op TrueNAS of via cron. In de Home Assistant-add-on: elke 5 s kijken of add-ons, Core of de boot-tijd veranderen.

**Wat de agent meldt**
- **Systeem:** CPU (gebruik, load, cores), geheugen en swap, disks per mount, luisterende poorten met procesnaam.
- **Services:**
  - de status van de services die je in RzSense kiest; de agent stuurt een lijst van alle services op de server mee voor de **servicekiezer**;
  - de **diepere controle**: poorten, een protocoltest (HTTP, database, SSH, mail, DNS…), herstarts en logfouten, plus een extra URL- of poorttest;
  - een eigen testscript in `/etc/rzmon-agent/tests/`.
- **Certificaten:** certificaatbestanden (ook met wildcards, zoals Let's Encrypt) en `host:poort`, lokaal of op internet. Eén keer per uur uitgelezen.
- **Logbestanden:**
  - alleen de nieuwe regels sinds de vorige keer, met de laatste 5 treffers en de meest voorkomende IP-adressen;
  - het systemlog (journal), per service, en kernelmeldingen;
  - bestanden alleen binnen `LOG_DIRS` (standaard `/var/log`).
- **Podman:**
  - containers van root en van gewone gebruikers (rootless): status, healthcheck, herstarts, CPU, RAM, netwerk en disk (schrijflaag + volumes);
  - pods en opslag.
- **🖥️ Commando-checks:**
  - **standaard uit**; aanzetten in RzSense (algemeen of per server) als `nobody` of als root, met je wachtwoord en een logboek;
  - de agent voert ze uit in een kale omgeving, met time-out en hooguit 4 KB uitvoer;
  - op de server zelf perkt `COMMANDS=0` of `COMMANDS=nobody` het altijd in.
- **🔑 Logins en sudo:** SSH- en console-logins, `sudo` en `su` (wie, wat, als wie, waar vandaan), ook mislukte pogingen, opgeteld per IP per uur. Op de pagina *IDS → Toegang*, met filters per soort en server, sorteren, 🙈 negeren en ⛔ blokkeren; wachtwoorden in commando's gemaskeerd; gebruikers/IP's te negeren; `AUTH_EVENTS=0` zet het op een server uit.
- **🛡️ IDS** (standaard uit): controlegetallen van gevoelige bestanden (sudoers, authorized_keys, passwd, crontabs, sshd-config …), gebruikers, SUID-programma's, kernelmodules, gewijzigde pakketbestanden (`rpm -Va`/`debsums`), processen uit `/tmp` of zonder bestand, reverse shells en verbindingen naar malware-poorten. Bij *blokkeren* een eigen nftables-tabel `inet rzmon` met de geblokkeerde IP's; `IDS_BLOCK=0` zet blokkeren op een server uit. Bestanden bewaken: alleen wijzigingen gaan naar RzSense, de stand wordt pas bewaard als het rapport is aangekomen.
- **✔ Eigen processen** (1.65.0): wat de agent start (systemd-groep `rzmon-*.service`) en RzSense op zijn eigen server worden herkend en als "toegestaan" gemeld; de agent stopt nooit een eigen proces.
- **🧭 Suricata en YARA** (1.64.0): nieuwe alerts uit Suricata's `eve.json` gaan mee met het rapport (`SURICATA=0` zet het uit); met `yara` controleert de agent nieuwe bestanden met de YARA-regels die RzSense versleuteld stuurt.
- **🛰️ Endpoint-bewaking** (1.63.0): processen en gevoelige paden via eigen `auditd`-regels, verbindingen, nieuwe bestanden (inotify) en containers, in de realtime-watcher; een eigen wachtrij die pas leeg gaat als het rapport aankwam; een proces stoppen alleen op versleuteld verzoek van RzSense, na controle van pid en starttijd; onderdelen installeren op verzoek; `EDR=0` zet het op een server uit.
- **Tegen kapotte rapporten:** servicenamen worden gefilterd, en de uitvoer wordt veilig als JSON verstuurd (via een tijdelijk bestand).

## 11. De agent op TrueNAS SCALE
- **Eén commando** (tabblad *🗄️ TrueNAS SCALE* in de Agent Manager):
  - de agent en de instellingen komen op je **pool** (`/mnt/<pool>/rzmon-agent`), omdat `/usr` en `/etc` bij TrueNAS zelf horen;
  - een **cronjob van TrueNAS** wordt via de API aangemaakt (uitvoer verborgen); die blijft staan na een TrueNAS-update;
  - een bestaande handmatige installatie wordt overgenomen.
- **Bijwerken met de knop** in RzSense, net als op andere servers.
- **🗄️ TrueNAS uitlezen** (1.174.0, agent 1.77.0): via `midclt`, zonder API-sleutel. Checktype **TrueNAS** voor de **meldingen** van TrueNAS, **taken** (replicatie, snapshots, cloud sync, rsync), **apps**, een **TrueNAS-update** en de **schijftemperatuur**; bij de server een knop **🗄️ TrueNAS** met een overzicht en ＋ Check per onderdeel.
- **🐞 Kwetsbaarheden** (1.174.0): gescand als de Debian eronder (uit `base-files`); bijwerken alleen via een TrueNAS-update, nooit met apt.
- **🛡️ IDS:** standaard alleen detecteren; met **IDS mag hier blokkeren** (in *🛡️ Hardening*, met je wachtwoord) ook blokkeren en de honeypot-poort (1.174.0, agent 1.77.0).
- **Agents-overzicht:** TrueNAS in een eigen groep (1.174.0).
- **Tip:** bewaak je pools met een commando-check `zpool status -x`.
- **TrueNAS CORE** (FreeBSD) voeg je toe als *Remote* met netwerkchecks.

## 12. De Home Assistant-add-on
- **Installeren** via de add-on winkel (repository `https://github.com/rembrand20/rzmon`).
- **Wat de add-on meldt:**
  - CPU, RAM en de data-partitie (via de Supervisor);
  - als services: Home Assistant Core, de Supervisor, elke add-on (`addon:<slug>`) en beschikbare updates;
  - de **laatste automatische back-up** (HA 2025.2+);
  - **integraties** die niet geladen zijn;
  - de **systeemstatus** van de Supervisor;
  - **onverwachte herstart** van HA OS (critical met melding), **levensduur van de SD-kaart/eMMC**, een **mislukte OS-update** (A/B-terugval) en **USB-apparaten** (Zigbee-/Z-Wave-stick weg = critical);
  - **containers**: Core, Supervisor, plugins en add-ons met CPU en RAM, gecrasht of herstart door de watchdog, in hetzelfde overzicht en met dezelfde checks als Podman;
  - **logbestanden** van HA OS, Core, de Supervisor en add-ons doorzoeken (bv. te lage voeding op een Raspberry Pi);
  - *＋ Aanbevolen checks* voor Home Assistant.
- **Alleen lezen**, met de Supervisor-rol `manager` (statistieken, logs, hardware). Commando-checks zijn in de add-on niet actief.
- **Bijwerken** via de add-on winkel. De add-on heeft hetzelfde versienummer als de agent (los van RzSense), dus er is alleen een update als de agent veranderd is.

## Licentie en beveiliging
- **Open source onder GPL-3.0** (vanaf 1.145.0, zie `LICENSE`).
- **`SECURITY.md`**: lekken privé melden, threat model, de publieke signing-sleutel van de agent met vingerafdruk.
- **Controles in GitHub Actions**: tests (PHP, agent op zes Linux-versies, browser), PHPStan niveau 4, geheimencontrole, CSP-controle en versiecontrole.
