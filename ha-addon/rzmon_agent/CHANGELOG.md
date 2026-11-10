# Changelog van de agent

De agent (op Linux-servers en in de Home Assistant-add-on) heeft een eigen versienummer, los van RzMon. Gaat er alleen in RzMon iets veranderen, dan hoef je de agents niet bij te werken.
Deze lijst staat ook in de add-on: Home Assistant toont hem bij een update. Wat er in RzMon zelf verandert, staat in de [CHANGELOG van RzMon](https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md).

Tot en met 1.45.0 liep het nummer gelijk met RzMon. Wat er tot dan aan de agent op Linux-servers veranderde, staat in de changelog van RzMon; hieronder staat tot dan alleen wat de add-on betreft.

## 1.86.0 – 2026-11-10
- **Reverse proxy herkennen** (logs van webdiensten): de agent ziet nu of er **SWAG**, **Nginx Proxy Manager** of een gewone **nginx** draait, en stuurt dat mee.
- **Het hele pad van een webaanval**: staat er een extra logbestand `rzsense.log` naast de access-log (de regel staat in RzSense bij *Instellingen → IDS → Netwerk en aanvallers*), dan leest de agent dat. Daarin staan ook de **site** en de **dienst** erachter. Per aanvaller gaan de sites mee, hoeveel verzoeken een gewoon antwoord kregen, en welke container de dienst is.
- **Nginx Proxy Manager**: de logs per site (`proxy-host-*_access.log`) worden nu goed gelezen, meteen met de site en de dienst.
- Je bestaande access-log blijft zoals hij is, dus fail2ban in SWAG blijft gewoon werken.

## 1.85.0 – 2026-11-07
- RzMon heet nu **RzSense**: de agent, de verbindingstest, de statusweergave en het installatiescript gebruiken de nieuwe naam. De technische naam blijft `rzmon-agent` (service, `/etc/default/rzmon-agent`, add-on): je hoeft niets aan te passen.
- Bijwerken herkent het installatiescript en de agent met de oude én de nieuwe naam.
- De Home Assistant-add-on heet **RzSense Agent** en heeft het nieuwe icoon.

## 1.84.0 – 2026-11-07
- **Logs vinden** (RzMon 1.213.0): één keer per dag, of meteen als je in RzMon op *Opnieuw zoeken* klikt, kijkt de agent welke logs er op de server zijn: bestanden en mappen binnen `LOG_DIRS` (tot 3 diep, zonder gedraaide en binaire bestanden), Docker- en Podman-containers, systemd-services die de afgelopen 24 uur in de journal schreven, en in de add-on de Supervisor-logs en add-ons. Hij stuurt alleen namen, groottes en tijden mee, nooit de inhoud, en alleen als de lijst net gemaakt is. Uit met `LOG_SHIP=0`.

## 1.83.0 – 2026-11-07
- **Logs verzamelen** (RzMon 1.210.0): de agent leest de nieuwe regels van de bronnen die je in RzMon per server kiest (bestanden of mappen met `*` binnen `LOG_DIRS`, de journal of één service, een Docker/Podman-container, of in de add-on de logs van Home Assistant), filtert op het gekozen niveau, en stuurt ze elke paar minuten als één pakketje: gecomprimeerd (gzip) en versleuteld, los van het rapport. Per bestand onthoudt hij waar hij was, ook na logrotatie; een nieuwe bron begint aan het einde, een halve laatste regel komt de volgende keer. Pas als RzMon het pakketje bevestigt, gaat het weg; zonder verbinding blijven ze bewaard (samen hooguit 50 MB, daarboven de oudste eruit). Zonder sleutel wordt niets verstuurd. Bereikt een server de daglimiet in RzMon, dan pauzeert de agent tot middernacht. Uitzetten: `LOG_SHIP=0`. De logbestanden zelf blijven staan.

## 1.82.0 – 2026-11-06
- **Wachten tijdens een update van RzMon** (RzMon 1.205.0): zegt RzMon in een versleuteld antwoord dat hij zo wordt bijgewerkt, dan zet de agent metingen die niet aankomen in de wachtrij en geeft de waakhond geen alarm, tot RzMon weer antwoordt (de vrijgave) of de tijd die RzMon meegaf voorbij is (standaard 5 minuten, hooguit een uur). Na de vrijgave wacht hij 0–24 s en stuurt hij de wachtrij na, met hoe lang hij wachtte. Duurt het te lang: *⚠️ RzMon onbereikbaar tijdens update*. Tijdens het wachten werkt de agent zichzelf niet bij. `rzmon-agent --status` toont het. Alleen uit een versleuteld antwoord: een foutpagina of proxy kan de waakhond niet stilzetten.

## 1.81.0 – 2026-11-06
- **Databases** (RzMon 1.200.0): de agent leest MariaDB/MySQL en PostgreSQL op de server zelf uit, of in een Docker/Podman-container (`docker exec` / `podman exec`). Zonder gebruiker zonder wachtwoord: MariaDB/MySQL als root via de socket (in een container met het wachtwoord uit de container zelf), PostgreSQL als gebruiker `postgres`. Alleen lezen: status, verbindingen, lopende query's, replicatie en grootte (MariaDB/MySQL hooguit eens per half uur). Eén meting per verbinding, ook als er meerdere checks op staan. Een wachtwoord krijgt de agent alleen over een versleutelde verbinding, en het gaat via de omgeving naar de client, nooit op de opdrachtregel. Nodig op de server: de client (`mariadb`/`mysql` of `psql`), of de database in een container.

## 1.80.0 – 2026-11-05
- **Netwerkverkeer** (RzMon 1.188.0): de agent stuurt de bytetellers van de echte netwerkkaarten van de server mee (met een apparaat erachter, plus bond en wifi; de poorten van een bond tellen via de bond, niet dubbel). RzMon gebruikt die voor *Netwerk → Verkeer per apparaat* als UniFi deze server niet ziet. Alleen lezen uit `/sys/class/net`, geen extra programma's nodig.

## 1.79.0 – 2026-11-05
- **Zelftest** (RzMon 1.178.0): met **🧪 Test** in de Agent Manager test de agent zichzelf bij zijn volgende ronde: de planning (timer of cron), de rechten van het instellingenbestand, DNS, het adres en het reserve-adres van RzMon, de klok, de vrije ruimte, de wachtrij, fouten in zijn log van het laatste uur en de hulpprogramma's die hij nodig heeft. Alleen vaste controles die lezen, één keer per vraag.

## 1.78.0 – 2026-11-05
- **Pad-check** (RzMon 1.177.0): de agent meet het pad naar een doel: per hop verlies en vertraging (met `mtr`, anders `traceroute`), een ping naar de tussenliggende apparaten die je in RzMon opgeeft (switches zie je niet in een traceroute), de snelheid van de netwerkkaart, of de server virtueel draait, en met `lldpd` op welke switch en poort hij zit. Standaard elke 5 minuten.
- **Doorvoertest met iperf3**: op aanvraag van RzMon meet de agent 3 seconden de echte doorvoer naar een andere server; daar start de agent dan kort een iperf3-ontvanger die na één meting zelf stopt (hooguit 2 minuten). Alleen als `iperf3` is geïnstalleerd.
- Nieuw in de lijst met wat de agent kan: `mtr`, `traceroute`, `iperf3` en `lldpctl`.

## 1.77.0 – 2026-11-05
- **TrueNAS SCALE uitlezen** (RzMon 1.174.0): als root vraagt de agent TrueNAS zelf met `midclt`, zonder API-sleutel:
  - de meldingen van TrueNAS, de taken (replicatie, snapshots, cloud sync, rsync) en de apps, elke 5 minuten;
  - de schijftemperaturen, elk kwartier;
  - of er een TrueNAS-update klaarstaat, elke 6 uur.

  `TRUENAS=0` in de instellingen zet het uit.
- **TrueNAS: blokkeren door het IDS als keuze.** Op TrueNAS detecteerde het IDS alleen. Zet je in RzMon voor deze server *IDS mag hier blokkeren* aan, dan blokkeert de agent aanvallers en werkt de honeypot-poort, net als op andere servers (eigen firewalltabel, elke minuut teruggezet). Staat het uit, dan blijft het bij detecteren.

## 1.76.0 – 2026-11-04
- **Diagnose voor AI-advies** (RzMon 1.171.0): vraagt RzMon erom bij een storing, dan voert de agent eenmalig een **vaste lijst commando's uit die alleen lezen**, en stuurt de uitvoer mee.
  - Per soort: `systemctl status` en `journalctl -u` voor een service, status en logs voor een container, `df` en `du` voor een disk, de zwaarste processen en OOM-meldingen bij CPU en RAM, en wie er op een poort luistert.
  - Altijd: uptime en de laatste fouten in het journal en dmesg.
  - RzMon stuurt alleen de soort en het doel (naam, mount of poort), nooit een commando; de agent controleert het doel opnieuw.
  - Hooguit 20 seconden en 6 kB per onderdeel.
  - Staat in RzMon apart aan; `COMMANDS=0` blokkeert het altijd. Niet in de Home Assistant-add-on.

## 1.75.0 – 2026-11-04
- **WireGuard** (RzMon 1.170.0): draait de agent als root en staat `wg` op de server, dan stuurt hij per peer de laatste handshake, het verkeer en het endpoint mee (hooguit 64 peers; de sleutel alleen als korte, ingekorte naam). Daarmee bewaakt de nieuwe VPN-check in RzMon of je tunnels nog leven. Niet in de Home Assistant-add-on.

## 1.74.0 – 2026-11-03
- **SSH in detail** (RzMon 1.152.0): elke mislukte SSH-poging gaat los mee (tijd, IP, gebruiker, bronpoort; hooguit 200 per rapport), een geslaagde login met de bronpoort, en het einde van een sessie ("Disconnected from user …"). Daarmee toont RzMon de aanvalskaart, de tijdlijn en de duur van elke SSH-sessie. De opgetelde pogingen gaan ook nog mee, zoals altijd.
- Achter de schermen: het lezen van de logins staat in een eigen module (`agent/src/17-logins-lezen.sh`), met een testmodus `--auth-parse` voor GitHub Actions.

## 1.73.1 – 2026-11-03
- De grens voor comprimeren (standaard 2 kB) is in te stellen met `COMPRESS_MIN` (bytes). Voor de test in GitHub Actions; voor je servers verandert er niets.

## 1.73.0 – 2026-11-03
- **Gecomprimeerde rapporten** (RzMon 1.147.0): een rapport groter dan 2 kB gaat met gzip, vóór het versleutelen; meestal 50–85% kleiner. Alleen als RzMon laat weten dat hij het kan; weigert hij het, dan gaat het volgende weer gewoon. Uitzetten: `COMPRESS=0`.
- **Opgelost**: processen op de achtergrond (de hartslag, commando's, pakketupdates, zelf bijwerken) hielden de vergrendeling van de agent vast. De volgende run wachtte dan en sloeg zichzelf soms stil over; bij een lange pakketupdate zonder systemd (bv. de Home Assistant-add-on) kon de agent zo minuten "stil" lijken.
- **Opgelost**: sinds 1.68.0 verdwenen alle foutmeldingen van de agent (na het koppelen) naar het niets, ook in het logboek. Ze zijn weer te zien; een paar onschuldige meldingen over bestanden die nog niet bestaan, zijn nu stil.
- Achter de schermen: de agent bestaat nu uit modules (`agent/src`), samengevoegd tot hetzelfde ene bestand. Voor je servers verandert er niets.

## 1.72.0 – 2026-11-02
- **Waakhond via ntfy** (RzMon 1.140.0): staat ntfy aan in RzMon, dan stuurt de waakhond zijn bericht ook (of alleen) naar ntfy als RzMon onbereikbaar is of de checks stilstaan. De gegevens komen, net als bij Pushover, alleen versleuteld van RzMon.

## 1.71.0 – 2026-11-02
- **Ondertekende updates** (RzMon 1.139.0): in de agent staat de publieke sleutel van de ontwikkelaar. Bij bijwerken controleert de agent de handtekening van de nieuwe agent en het installatiescript (gemaakt in GitHub); klopt die niet of ontbreekt hij, dan wordt er niet bijgewerkt. Ook `rzmon-agent-setup` controleert hem. Met OpenSSL ouder dan 3.0 kan het niet; dan geldt alleen het controlegetal (en meldt de agent dat). `UPDATE_UNSIGNED=1` zet het uit (niet aangeraden).


## 1.70.0 – 2026-11-02
- **Klok**: gesynchroniseerd en hoeveel ms hij afwijkt (chrony of systemd-timesyncd), voor de check *Tijd (NTP)* (RzMon 1.138.0).
- **Schijfgezondheid**: met smartctl (smartmontools) per schijf de gezondheidstest, temperatuur, slijtage (SSD/NVMe) en vervangen of twijfelachtige sectoren; elk half uur, en schijven in slaapstand worden niet wakker gemaakt.
- **UPS**: via NUT (`upsc`) de status (op het net of op de batterij), lading, resterende tijd en belasting.


## 1.69.0 – 2026-11-02
- **Sneller en nooit meer onterecht "stil"** (RzMon 1.137.0):
  - **Commando-checks op de achtergrond**: is er al een vorig resultaat, dan gaat dat meteen mee en meet de agent opnieuw op de achtergrond. Een trage check houdt het rapport niet meer op (die mochten samen tot 40 seconden duren). De eerste keer meet hij nog direct.
  - **Hartslag tijdens een lange run**: duurt een run langer dan de tik, dan meldt de agent elke tik "ik ben bezig". RzMon telt dat als contact; zijn gegevens blijven staan tot het rapport komt. Een run die al een kwartier bezig is, telt niet meer.
  - **Wachtrij**: lukt versturen niet (RzMon of het netwerk even weg), dan bewaart de agent de metingen van CPU, RAM en disks (hooguit een dag) en stuurt ze met het volgende rapport mee. RzMon zet ze op hun echte tijd in de grafieken, zonder dubbele punten.
  - **Duur per onderdeel**: elk rapport vertelt hoe lang de run duurde en welk onderdeel het traagst was (te zien in de Agent Manager).
- **Docker**: containers van Docker net als die van Podman: dezelfde checks, compose-projecten als pods, opslag en volumes, en herstarten bij zelf herstellen. Alleen als de Docker-daemon draait; `DOCKER=0` in `/etc/default/rzmon-agent` zet het uit.
- **Wat de agent kan**: hij meldt welke hulpmiddelen er op de server zijn (podman, docker, zpool, smartctl, nft …), voor de Agent Manager.


## 1.68.0 – 2026-10-31
- **Installeren met een koppelcode gaf soms *Rapport mislukt: invalid or expired pairing code*** (terwijl de agent wél gekoppeld was). De timer startte in stap 4 al een run die met de eenmalige code koppelde, terwijl het testrapport van stap 5 dat tegelijk ook deed. Nu gaat de timer pas aan na het testrapport, en koppelt er altijd maar één run tegelijk: een tweede wacht en gebruikt de sleutel die de eerste kreeg.
- De verbindingstest (`--test`) wacht even op een run die al bezig is; geen `head: cannot open …/cmdstate/…` en `mv: cannot stat …/ids/….tmp` meer.
- Nieuwe installatie: lege regels en de gebruikte koppelcode gaan na het koppelen meteen uit `/etc/default/rzmon-agent`. RzMon meldt dan geen *overbodig: TOKEN, ENC_KEY, PAIR_CODE …* meer.

## 1.67.0 – 2026-10-30
- **Eigen processen betrouwbaarder herkend** (RzMon 1.132.0): wat RzMon zelf start, heeft een geheim kenmerk in de opdracht; de agent herkent dat, en alles wat eruit voortkomt, als RzMon. Ook de cron van RzMon (`crond → sh -c php …/cron/run.php`) en wat een run van de agent start, ook als die run al klaar is wanneer de gebeurtenis binnenkomt (de laatste 8 PID's staan in `own.pids`). Voorheen gaven het rapport van de agent, zijn hardening-check en het zoeken naar bestanden soms een Sigma-melding, en RzMon op zijn eigen server een webshell-melding.


## 1.66.1 – 2026-10-29
- **`rzmon-agent-setup --update` werkt weer met een eigen (self-signed) certificaat van RzMon.** Na `--cleanup` stond de vingerafdruk (`PIN`) niet meer in `/etc/default/rzmon-agent` (de agent bewaart hem zelf in `/var/lib/rzmon-agent`), en daarna stopte `--update` met *Certificaatprobleem … Voeg --insecure toe*. Het installatiescript gebruikt nu dezelfde vastgezette vingerafdruk als de agent. Dat was ook de reden dat *bijwerken vanuit RzMon* niet lukte.
- **Langer wachten op RzMon**: 50 seconden in plaats van 20. Was RzMon trager, dan gaf de agent het op en kreeg hij het antwoord (opdrachten, instellingen, de bevestiging van de softwarelijst) nooit, terwijl RzMon het rapport wel verwerkte.
- **Softwarelijst niet meer elke minuut opnieuw**: kwam er geen antwoord, dan stuurt de agent de volgende keer eerst alleen het controlegetal. Vaak heeft RzMon de lijst dan al; zo niet, dan vraagt RzMon hem opnieuw. Voorheen stuurde de agent elke minuut de hele lijst (honderden KB), wat RzMon nog trager maakte.
- `--test` toont bij certificaten weer de juiste geldigheid (*nog 628 dagen geldig*), in plaats van een negatief getal.
- Duidelijkere foutmelding als het certificaat niet bij de vingerafdruk past.

## 1.66.0 – 2026-10-29
- **Eigen pakketnamen bij installeren** (RzMon 1.125.0): kies je in de Agent Manager zelf de pakketten voor de endpoint-bewaking (bv. `audit audispd-plugins`), dan installeert de agent die met `dnf`/`apt` in plaats van alleen zijn vaste lijst. Alleen gewone pakketnamen (geen opties of paden), hooguit 10, en net als altijd te blokkeren met `COMMANDS=0`.

## 1.65.0 – 2026-10-28
- **Eigen processen herkennen** (RzMon 1.114.0): wat de agent start (systemd-groep `rzmon-*.service`, of als root vanuit de agent) en RzMon op zijn eigen server (PHP in de map van RzMon) gaan als "van RzMon" mee, samen met verbindingen met RzMon. RzMon logt dat als toegestaan en grijpt er nooit op in. Eigen processen tellen niet mee voor de grens van 200 per minuut.
- De agent stopt nooit een proces van zichzelf of van RzMon, ook niet op verzoek.
- Opgelost: een programma van een gewone gebruiker met de naam "rzmon-agent" werd overgeslagen; nu alleen de echte agent.

## 1.64.0 – 2026-10-28
- **YARA** (RzMon 1.113.0): staat `yara` op de server, dan controleert de watcher elk nieuw bestand in `/tmp`, `/var/tmp` en `/dev/shm` en elk nieuw script in de webmappen met de YARA-regels van RzMon (webshells, miners, reverse shells, botnets, rootkits). De regels komen versleuteld van RzMon; de agent vervangt ze alleen als `yara` ze kan lezen. YARA kan ook via RzMon geïnstalleerd worden.
- **Suricata**: nieuwe alerts uit `/var/log/suricata/eve.json` gaan mee met het rapport (hooguit 200 per keer; de positie wordt pas bewaard als het rapport aankwam). Ander pad: `SURICATA_EVE=…`; uit: `SURICATA=0`.

## 1.63.0 – 2026-10-28
- **🛰️ Endpoint-bewaking** (RzMon 1.110.0, per server aan of uit in de Agent Manager): de realtime-watcher kijkt op het moment zelf mee naar nieuwe processen en gevoelige paden (eigen `auditd`-regels, sleutels `rzmon_*`, alleen zolang het aan staat), verbindingen en luisterpoorten, nieuwe bestanden in `/tmp`, `/var/tmp`, `/dev/shm` en de webmappen (met `inotify-tools`: grootte, ELF, SHA-256, verdachte code) en containers (Docker en Podman). Alles gaat via een eigen wachtrij (hooguit 2 MB) met het volgende rapport naar RzMon; die gaat pas leeg als het rapport aankwam. Dubbele gebeurtenissen hooguit één keer per 10 minuten, hooguit 200 per minuut. `EDR=0` zet het op een server uit.
- **Proces stoppen**: alleen op een versleuteld verzoek van RzMon, in de modus Blokkeren en als root; de agent controleert eerst dat het nog hetzelfde proces is (pid en starttijd) en meldt het resultaat bij het volgende rapport.
- **Onderdelen installeren** op verzoek van RzMon (`audit`/`auditd`, `inotify-tools`, op Rocky/Alma eerst EPEL); daarna zet de agent `auditd` aan. Alleen als commando's als root aan staan.
- **Webverzoeken van SWAG**: verdachte verzoeken gaan volledig mee (IP, tijd, methode, pad, status, user-agent), met wachtwoorden en tokens weggelakt.
- De realtime-watcher (`rzmon-watch`) draait nu met `PrivateTmp=no`, zodat hij `/tmp` ziet. Gaat vanzelf bij het bijwerken (knop in de Agent Manager of `sudo rzmon-agent-setup --update`).

## 1.62.0 – 2026-10-27
- **🩹 Zelf herstellen** (RzMon 1.107.0, per check aan te zetten): een service of container die niet draait, herstart de agent zelf, vóór zijn rapport. Lukt dat, dan blijft de check OK en komt er een ℹ️-melding; lukt het niet, de gewone melding. Een eigen **herstelcommando** zet je op de server in `/etc/rzmon-agent/heal.d/` (uitvoerbaar, van root, niet door anderen te wijzigen); de agent voert het alleen uit op verzoek van RzMon, in een versleuteld antwoord, als die check misgaat. Hooguit één poging per 10 minuten en 3 per dag per check. `HEAL=0` zet het op een server helemaal uit.
- **🐕 Waakhond** (per agent aan te zetten in de Agent Manager): bereikt de agent RzMon een tijd niet (standaard 5 minuten), dan stuurt hij zelf een Pushover-bericht, en een herstelbericht als RzMon terug is. Bereikt hij ook Pushover niet, dan lag het aan zijn eigen netwerk; dat hoort RzMon achteraf. Draait RzMon wel maar staan de checks stil (cron), dan meldt hij dat ook. De Pushover-gegevens krijgt de agent alleen versleuteld. `WATCHDOG=0` zet het op een server uit.

## 1.61.1 – 2026-10-27
- **Opgelost: "Het laatste rapport was deels onleesbaar" (proc_list, Syntax error).** Een backslash in de opdrachtregel van een proces (bijvoorbeeld `grep -E 'sshd\[[0-9]+\]'`) kwam niet goed in het rapport, waardoor RzMon de proceslijst weggooide. Dat gebeurde met de awk van Debian, dus ook op Proxmox. Dezelfde reparatie geldt voor de SUID-lijst, de pakketwijzigingen en de logins.

## 1.61.0 – 2026-10-27
- **Proxmox VE:** op een Proxmox-host (als root) leest de agent ook Proxmox zelf uit met `pvesh`, zonder API-token en zonder `jq`:
  - guests (VM's en containers): status, CPU, RAM, disk, uptime, lock, sjabloon, automatisch starten en of de QEMU guest agent antwoordt;
  - opslag, ZFS-pools (gezondheid, vulling, laatste scrub) en de cluster;
  - de nieuwste back-up per guest (vzdump en Proxmox Backup Server), de back-uptaken en de laatste vzdump-taken.
  - Guests en opslag elke 2 minuten, back-ups en taken elk kwartier. Uitzetten met `PVE=0`. `--status` toont een regel *Proxmox VE*.
- Werkt ook met `mawk` (Debian/Proxmox).

## 1.60.0 – 2026-10-27
- **`rzmon-agent-setup --status`**: alles over deze agent in één overzicht:
  - versie en controlegetal van het script;
  - hoe hij gestart wordt (timer of cron, met de volgende start) en de realtime-watcher;
  - de instellingen (met wat er overbodig is) en de reservekopieën;
  - het adres van RzMon, of het nu bereikbaar is (met DNS-, verbind- en TLS-tijd), de TLS-versie, het certificaat van RzMon en of de vingerafdruk klopt;
  - de eigen sleutel (kenmerk, sinds wanneer) en hoe er versleuteld wordt;
  - het laatste rapport (tijd, duur, grootte, versleuteld, gewone of reserve-adres), wat RzMon ontving, het ritme en wanneer het volgende rapport komt;
  - de laatste 10 contacten, de fouten van de laatste 24 uur en wat RzMon laat bewaken;
  - de beveiligingscontrole van RzMon.
- **Bijwerken = schoon installeren**: `--update` (ook vanuit RzMon) maakt eerst een reservekopie van de huidige agent en instellingen in `/var/lib/rzmon-agent/backup` (de laatste 5). Daarna installeert hij de nieuwe agent met opgeruimde instellingen.
  - Weg gaan: `TOKEN`, `ENC_KEY` en `PAIR_CODE` (met een eigen sleutel niet meer nodig), `INTERVAL` (de tik komt uit RzMon), `PIN` (bewaard naast de sleutel), `INSECURE` (als het certificaat vastgezet is), lege regels, en `SERVICES` zodra RzMon voor elke service een check heeft.
  - Wat je zelf instelde, blijft staan: adres, certificaten, commando's en dergelijke.
- **`rzmon-agent-setup --cleanup`**: alleen de instellingen opruimen, met een reservekopie (terugzetten: `cp` van de kopie, het commando staat erbij).
- **De agent meldt zijn instellingen aan RzMon**: welke regels overbodig zijn (alleen de namen, nooit de waarden), de rechten van het bestand, de SHA-256 van het script (zo ziet RzMon of het script ongewijzigd is) en de reservekopieën.
- **`--pair`** blijft: daarmee vul je een nieuwe koppelcode in.
- **Opgelost**:
  - `--update` stopte bij een agent die met een koppelcode was gekoppeld ("Geef de koppelcode mee"), ook bij bijwerken vanuit RzMon.
  - Het testrapport na het installeren bleef leeg als de agent net had gerapporteerd.

## 1.59.2 – 2026-10-27
- **Opgelost: proceslijst kon het rapport nog steeds onleesbaar maken**: `ps` en `awk` draaien voor de processen nu met `LC_ALL=C`: altijd een punt als decimaalteken (niet `0,4` bij een Nederlandse of Duitse landinstelling) en alleen gewone tekens.

## 1.59.1 – 2026-10-27
- **Opgelost: agent meldt zich niet meer** (vooral Proxmox/Debian): de proceslijst knipte opdrachtregels af op 120 bytes; viel die knip midden in een teken als é, dan was het hele rapport ongeldige UTF-8 en weigerde RzMon het, elke keer weer. De agent verstuurt nu alleen geldige UTF-8 (`iconv`).

## 1.59.0 – 2026-10-27
- **Certificaatbestanden zoeken** (RzMon 1.98.0): eens per dag (of meteen als je in RzMon op 🔄 Opnieuw zoeken klikt) zoekt de agent naar `.crt`, `.pem`, `.cer` en `.cert` in de bekende mappen (`/etc/ssl`, `/etc/pki`, `/etc/letsencrypt`, `/etc/nginx`, `/opt`, `/srv` …) plus de mappen die je in RzMon of met `CERT_DIRS` opgeeft. De CA-lijsten van het systeem en CA-bundels worden overgeslagen.
- **Certificaten op de server zelf**: een bestand of `localhost:poort` uit een SSL-check leest de agent zelf uit, ook op poorten die alleen lokaal open staan.
- **Hele keten**: bij een bestand met meerdere certificaten of een server die een keten stuurt, meldt de agent ook welk certificaat in de keten het eerst verloopt.
- **Processen**: voor een proces-check telt de agent hoeveel processen er draaien (op naam of op een patroon in de opdrachtregel, eventueel van één gebruiker), met CPU, geheugen en starttijd. Plus een lijst van wat er draait, om in RzMon uit te kiezen.

## 1.58.0 – 2026-10-26
- **Logs van webdiensten** (RzMon 1.89.0): de nieuwe regels van SWAG (nginx access-log), Home Assistant (Core-log, via de Supervisor) en Vaultwarden (add-on, container of logbestand), per IP samengevat. De agent zoekt de logs zelf als RzMon geen pad geeft.
- **Blokkades ook vóór Docker**: een extra chain `pre` (prerouting) in de tabel `inet rzmon`, zodat geblokkeerde IP's ook containers (SWAG) niet meer bereiken.

## 1.57.0 – 2026-10-26
- **Hardening** (RzMon 1.88.0): elk uur de instellingen voor de score (sshd, firewall, automatische updates, open databases, Docker-socket, lege wachtwoorden, sudo NOPASSWD, /tmp, SELinux).
- **Isoleren**: eigen firewalltabel `inet rzmon_iso`. Alleen RzMon, de toegestane netwerken en de DNS-servers zijn nog bereikbaar. Vervalt vanzelf op de tijd, ook zonder RzMon.
- **Gebruiker vergrendelen of vrijgeven** als RzMon erom vraagt (`usermod -L/-U`, processen van die gebruiker stoppen; nooit root).

## 1.56.0 – 2026-10-26
- **Webshell** (RzMon 1.87.0): stuurt mee welke shells of netwerkprogramma's (`sh`, `curl`, `wget`, `nc`, `python` …) door een webserver zijn gestart of als webgebruiker draaien. PHP-mail via `sendmail` telt niet.
- **Proces stoppen** in de modus blokkeren, als RzMon erom vraagt: alleen als nummer én starttijd kloppen.
- Elke 10 minuten de lijst met ingeschakelde systemd-services en -timers.
- **Uitgaande verbindingen** naar adressen buiten je netwerk, per programma (hooguit 100).
- **Uitgaand blokkeren** naar bekende aanvallers (als RzMon het vraagt), ook voor containers: extra chains `output` en `out_fw` in de tabel `inet rzmon`.

## 1.55.1 – 2026-10-26
- Het installatiescript haalt de agent op met de **downloadsleutel** die RzMon erin zet (RzMon 1.86.0). Is RzMon van buiten *niet herkenbaar*, dan levert het het script en de agent alleen met die sleutel, of aan een server die al als agent bekend is. Bestaande agents hoeven niet bij te werken: hun bekende IP-adres is genoeg.

## 1.55.0 – 2026-10-25
- **Bekende aanvallers** (RzMon 1.82.0): in de modus blokkeren zet de agent de netwerken van Spamhaus DROP en FireHOL level 1 (zo'n 4.500) in een eigen set in zijn firewalltabel `inet rzmon`. Ze worden alleen opnieuw opgebouwd als de lijst verandert (dagelijks).

## 1.54.0 – 2026-10-25
- **IDS-lokaas-poort** (RzMon 1.81.0): de agent zet in zijn eigen firewalltabel (`inet rzmon`) een regel voor de lokaas-poort (standaard 2323). Een verbinding wordt stil weggegooid en het IP gemeld. Dat werkt ook in de modus leren; dan staat alleen die regel in de tabel. Is de poort op de server in gebruik, dan meldt de agent dat.
- **Meer achterdeuren bewaakt**: `/etc/pam.d/*`, `/etc/hosts`, `/etc/resolv.conf`, `/etc/environment`, `at`-jobs, de shell-bestanden van root en systemd-gebruikersunits.
- **Groepsleden** van `wheel`, `sudo`, `docker`, `root`, `adm` en `lxd` gaan mee, zodat RzMon ziet wie erbij komt.
- Blokkades van andere servers (*automatische blokkades op alle servers*) staan maar één keer in de tabel.

## 1.53.0 – 2026-10-25
- **Controle per adres** (RzMon 1.78.0): de agent onthoudt per adres van RzMon hoe hij het certificaat controleert: 📌 alleen het eigen certificaat van RzMon (vastgezet) of 🔒 gewone controle (bv. Let's Encrypt via je proxy). Daardoor kunnen het gewone adres en het reserve-adres elk een ander soort certificaat hebben, bv. `https://192.168.1.10` (eigen certificaat) en `https://rzmon.jouwdomein.nl` (via de proxy). Het staat in `url.pins` naast de sleutel.
- Bij een overstap naar een ander adres test de agent meteen met de juiste controle; zo kan hij ook rechtstreeks naar https op RzMon zelf.
- Een klaargezet nieuw certificaat van RzMon wordt vooraf geaccepteerd (twee vingerafdrukken), zodat het wisselen zonder onderbreking gaat.
- `--test` toont per adres hoe het gecontroleerd wordt.

## 1.52.0 – 2026-10-25
- Nieuw commando **`rzmon-hb`** voor heartbeat-checks (RzMon 1.77.0): `rzmon-hb NAAM [ok|start|fail] [bericht] [--value GETAL]`. Scripts en cronjobs melden zich zo bij RzMon; de melding gaat versleuteld mee met het volgende rapport van de agent, en blijft bewaard als RzMon even niet bereikbaar is. Het installatiescript zet `rzmon-hb` in `/usr/local/bin` (op TrueNAS: `rzmon-agent --hb NAAM ok`).
- In de Home Assistant-add-on is er geen `rzmon-hb`; gebruik daar de geheime URL van de check (zie de handleiding).

## 1.51.1 – 2026-10-24
- Bij een overstap naar een ander adres stuurt de agent mee met welk IP hij verbond. RzMon ziet daaraan of de server via internet gaat in plaats van via je eigen netwerk (bv. een DNS-rewrite die op die server niet werkt), en zegt dat erbij (RzMon 1.72.1).

## 1.51.0 – 2026-10-24
- **Ander adres van RzMon vanuit RzMon** (RzMon 1.72.0 nodig): RzMon kan de agent een nieuw adres geven, bijvoorbeeld `https://` via je reverse proxy. De agent **test het eerst** (verbinding, certificaat en een antwoord dat met zijn eigen sleutel klopt) en gebruikt het pas dan; anders blijft het oude adres. Het adres staat in de map met de sleutel (`url`); pas je `MONITOR_URL` zelf aan, dan geldt weer dat.
- **Reserve-adres**: `MONITOR_URL_FALLBACK` (of `--fallback` bij het installeren, en in de add-on *Reserve-adres*). Is het gewone adres niet bereikbaar (geen verbinding, certificaat, of de proxy geeft 502–504), dan gaat het rapport via het reserve-adres. RzMon meldt dat met een ⚠️.
- De agent stuurt zijn adres en reserve-adres mee, zodat RzMon per agent laat zien hoe hij verbindt.
- `rzmon-agent-setup --test` toont het adres dat RzMon heeft ingesteld en het reserve-adres.

## 1.50.0 – 2026-10-23
- **Eigen sleutel** (RzMon 1.64.0 nodig): de agent krijgt een eigen sleutel, vanzelf (als hij al versleutelde) of met een **koppelcode**. Daarna stuurt hij geen token meer mee en is elk verzoek versleuteld of ondertekend, ook het ophalen van zijn instellingen. De sleutel staat in `/var/lib/rzmon-agent` (add-on: `/data/rzmon-keys`, TrueNAS: de map van de agent), niet meer in de cache. Vernieuwt RzMon de sleutel, dan neemt de agent hem vanzelf over.
- **Koppelen**: `install-agent.sh --pair CODE` (of `PAIR_CODE=` in de instellingen, of `pair_code` in de add-on). Het eerste bericht is versleuteld met een sleutel uit de code; de agent krijgt zijn eigen sleutel versleuteld terug. Een code wordt maar één keer gebruikt.
- **Certificaat van RzMon vastzetten** bij een eigen (self-signed) certificaat: de agent accepteert alleen dat certificaat (`--pinnedpubkey`, curl 7.44+). De vingerafdruk komt uit het installatiecommando (`--pin`, of `pin` in de add-on) en daarna versleuteld van RzMon. Bij een nieuw certificaat controleert de agent het met zijn sleutel en neemt hij de nieuwe vingerafdruk over.
- **Strenger**: een onversleuteld antwoord op een versleuteld verzoek wordt genegeerd (geen update, geen nieuwe sleutel). De eigen sleutel gooit de agent nooit weg. Het logboek toont geen sleutels meer.
- **Installatiescript**: `--pair`, `--pin` en `--agent-sha` (de gedownloade agent wordt met SHA-256 gecontroleerd); een token is met een koppelcode niet meer nodig. `--uninstall` verwijdert ook de sleutel.
- **Verbindingstest** (`--test`): controleert de eigen sleutel (het antwoord van RzMon moet ermee ondertekend zijn) en het vastgezette certificaat.
- **Add-on**: nieuwe opties `pair_code` en `pin`; `token` is niet meer verplicht.

## 1.49.1 – 2026-10-23
- **Pakketten bijwerken (pagina 🐞 Kwetsbaarheden) mislukte meteen** met *_: line 34: : command not found* (agent 1.48.0 en 1.49.0, op servers met systemd). `systemd-run` vult zelf alles in wat op `${…}` lijkt in de opdrachtregel, en het bijwerk-script stond daar als tekst in; zo werd het dnf- of apt-commando leeg. Het script staat nu in een bestand (alleen leesbaar voor root) en `systemd-run` start alleen dat bestand.
- Hetzelfde voor de IDS-taken op de achtergrond (SUID-bestanden, pakketcontrole, bestanden opnieuw hashen).

## 1.49.0 – 2026-10-23
- **Versleuteld versturen** (RzMon 1.57.0 of nieuwer): met een sleutel gaan het rapport, de voortgang van pakketten bijwerken en het ophalen van de instellingen versleuteld naar RzMon (AES-256-CBC met een HMAC-SHA256-handtekening, via `openssl`), en de agent controleert de handtekening van het antwoord voordat hij er iets mee doet. Klopt die niet, dan negeert hij het antwoord.
- De sleutel komt van RzMon (bewaard in `enc.key`, alleen leesbaar voor root) of uit `ENC_KEY=` in `/etc/default/rzmon-agent`; nieuw installeren met `--key`. Een nieuwe sleutel in RzMon neemt de agent vanzelf over. Zet je versleutelen in RzMon uit voor deze server, dan stopt de agent ermee.
- Geweigerd met een verouderde sleutel (3 keer achter elkaar)? Dan gooit de agent die weg en haalt hij de juiste op.
- `rzmon-agent.sh --test` laat zien of er versleuteld verstuurd is (🔒).

## 1.48.0 – 2026-10-23
- **Voortgang bij pakketten bijwerken** (pagina 🐞 Kwetsbaarheden, RzMon 1.56.0 of nieuwer): terwijl apt of dnf draait, meldt de agent elke paar seconden aan RzMon in welke stap hij is (pakketlijst ophalen, downloaden, installeren, controleren), hoeveel procent, met welk pakket hij bezig is en de laatste regels van de uitvoer. Bij apt komt het percentage van apt zelf; bij dnf telt de agent de regels zoals *Upgrading 3/14*. De uitkomst komt meteen binnen, niet pas bij het volgende rapport.
- Adres en token voor die meldingen staan even in een bestand dat alleen root kan lezen (niet als argument, zodat ze niet in `ps` te zien zijn) en worden direct na het starten verwijderd.

## 1.47.0 – 2026-10-23
- Voor de nieuwe pagina **🐞 Kwetsbaarheden** in RzMon (1.54.0 of nieuwer) stuurt de agent zijn **softwarelijst** mee: de pakketten (dpkg of rpm) en de distributie. De hele lijst één keer per dag, of eerder als er pakketten veranderen; anders alleen een controlegetal. Alleen als *Kwetsbaarheden* in RzMon aan staat, wordt de lijst bewaard.
- Ook meegestuurd: welke pakketten op het netwerk luisteren (🌐 bereikbaar), en of een herstart nodig is (nieuwere kernel geïnstalleerd, of services met oude bestanden via `needs-restarting` of `needrestart`).
- Nieuwe opdracht uit RzMon: **pakketten bijwerken** (`dnf upgrade` of `apt-get install --only-upgrade`) van precies de meegestuurde pakketnamen, alleen als root en als commando's als root zijn toegestaan. Op de server zelf blokkeer je het met `COMMANDS=0` of `COMMANDS=nobody`. Er wordt niets herstart.
- Home Assistant-add-on: geen merkbare wijzigingen (stuurt geen softwarelijst).

## 1.46.0 – 2026-10-22
- De agent stuurt zijn **opstarttijd** mee. Daarmee ziet RzMon (1.52.0 of nieuwer) met de nieuwe check *🔄 Herstart* wanneer de server opnieuw is opgestart, en kan het een ℹ️ info-melding sturen.
- Home Assistant-add-on: stuurt ook de opstarttijd mee; verder geen merkbare wijzigingen.

## 1.45.2 – 2026-10-22
- **Home Assistant-add-on rapporteerde niet meer (sinds 1.43.0).** In de add-on is `flock` de BusyBox-versie, en die kent de optie om te wachten (`-w`) niet. Daardoor stopte de agent elke keer meteen zonder rapport, en stond Home Assistant in RzMon op *onbereikbaar (agent stil)*. Nu probeert de agent het dan zonder te wachten. Hetzelfde gold voor servers met Alpine Linux.
- Add-on: in het log staat *Verbonden met RzMon ✔* pas als er echt een rapport is aangekomen, niet meer ook als de agent zijn beurt overslaat.

## 1.45.1 – 2026-10-22
- Eerste versie met een eigen nummer, los van RzMon (RzMon 1.46.0).
- Bijwerken of opnieuw installeren verandert niets meer aan `/etc/default/rzmon-agent`. Geef je zelf een optie mee, zoals `--token`, dan wordt alleen die regel aangepast.
- De agent bewaart de instellingen van RzMon alleen als die volledig binnenkomen. Een onvolledig antwoord, bijvoorbeeld tijdens een update van RzMon, laat de vorige lijst staan, zodat IDS-blokkades niet ineens verdwijnen.
- `--test` vergelijkt de agent met de nieuwste agent, niet meer met de versie van RzMon.
- Home Assistant-add-on: geen merkbare wijzigingen.

## 1.45.0
- Geen wijzigingen aan de agent (RzMon: rechten per API-sleutel en IDS in de API).

## 1.44.0
- Geen wijzigingen voor de add-on (de agent meldt nu op servers of EPEL/inotify-tools ontbreekt).

## 1.43.0
- Realtime (aan/uit in RzMon, Agent Manager): de add-on kijkt elke 5 s of er iets verandert aan de add-ons, Core of de boot-tijd van HA OS, en rapporteert dan meteen.

## 1.42.0
- Nieuw: onverwachte herstart van HA OS, levensduur van de SD-kaart/eMMC, mislukte OS-update, USB-apparaten, containers (Core, Supervisor, plugins, add-ons) met CPU/RAM en herstarts, plugins, en logs van HA OS, Core, Supervisor en add-ons.
- De add-on heeft nu de Supervisor-rol `manager` (alleen lezen) en de pakketten `gawk` en `sed`.

## 1.41.0
- De add-on start de agent elke 30 s; hoe vaak hij echt rapporteert, stel je in RzMon in (Agent Manager → Rapporteren elke …). De optie `interval` geldt alleen nog voor oudere RzMon-versies.

## 1.40.0
- Nieuw in de agent: IDS, bestanden bewaken. In de add-on niet actief; voor de add-on verandert er niets.

## 1.39.0
- Geen wijzigingen aan de agent.

## 1.38.0
- Geen wijzigingen aan de agent.

## 1.37.0
- Geen wijzigingen aan de agent.

## 1.36.0
- Geen wijzigingen aan de agent.

## 1.35.1
- Geen wijzigingen aan de agent.

## 1.35.0
- Nieuw in de agent: IDS (inbraakdetectie en blokkeren). In de add-on niet actief; voor de add-on verandert er niets.

## 1.34.0
- Geen wijzigingen aan de agent.

## 1.33.0
- Nieuw in de agent: logins en sudo melden. In de add-on niet actief; voor de add-on verandert er niets.

## 1.32.0
- Geen wijzigingen aan de agent.

## 1.31.1
- Geen wijzigingen aan de agent.

## 1.31.0
- Nieuw in de agent: commando-checks en TrueNAS-ondersteuning. In de add-on zijn commando-checks niet actief; voor de add-on verandert er niets.

## 1.30.0
- Geen wijzigingen aan de agent.

## 1.29.0
- Geen wijzigingen aan de agent.

## 1.28.0
- Geen wijzigingen aan de agent.

## 1.27.1
- Het rapport gaat nu via een tijdelijk bestand naar RzMon in plaats van als lange tekst (oplossing voor TrueNAS; voor de add-on verandert er niets merkbaars).

## 1.27.0
- Geen wijzigingen aan de agent.

## 1.26.0
- Geen wijzigingen aan de agent.

## 1.25.0
- Geen wijzigingen aan de agent.

## 1.24.0
- Nieuw: laatste back-up (via de back-upsensoren van HA 2025.2+), integraties die niet geladen zijn, en de systeemstatus van de Supervisor. Voeg ze in RzMon toe met *＋ Check → Home Assistant*.
- Services (Core, Supervisor, add-ons, Updates) staan in RzMon nu in de servicekiezer.
- Geen extra rechten nodig; de add-on leest alleen.

## 1.23.0
- Geen wijzigingen aan de agent.

## 1.22.0
- Geen wijzigingen aan de agent.

## 1.21.2
- Tekst: verwijzingen naar "Agent installeren" heten nu "Agent Manager".

## 1.21.1
- Geen wijzigingen voor Home Assistant (podman geldt voor Linux-servers).

## 1.21.0
- Geen wijzigingen aan de agent.

## 1.20.4
- Geen wijzigingen voor Home Assistant.

## 1.20.3
- Geen wijzigingen voor Home Assistant.

## 1.20.2
- Geen wijzigingen voor Home Assistant.

## 1.20.1
- Geen wijzigingen aan de agent.

## 1.20.0
- Geen wijzigingen voor Home Assistant (services kiezen in RzMon geldt voor Linux-servers).

## 1.19.0
- De agent meldt aan RzMon dat hij een add-on is. Bijwerken gaat via de add-on winkel; RzMon toont dat nu bij de knop *Bijwerken*.

## 1.18.0
- Geen wijzigingen aan de agent.

## 1.17.0
- Geen wijzigingen aan de agent.

## 1.16.0
- Geen wijzigingen voor Home Assistant (logbestanden doorzoeken geldt voor Linux-servers).

## 1.15.0
- Geen wijzigingen aan de agent.

## 1.14.0
- Geen wijzigingen aan de agent.

## 1.13.0
- Geen wijzigingen aan de agent.

## 1.12.0
- Geen wijzigingen voor Home Assistant.

## 1.11.0
- Geen wijzigingen voor Home Assistant (de diepere service-controle geldt voor Linux-servers).

## 1.10.0
- Geen wijzigingen aan de agent; zelfde versienummer als RzMon 1.10.0.

## 1.9.0
- Geen wijzigingen voor Home Assistant (de nieuwe Podman-bewaking geldt alleen voor Linux-servers).

## 1.8.0
- Nieuwe optie `certs`: vervaldatum van certificaten in `/ssl` (standaard `/ssl/fullchain.pem`) en/of `host:poort` doorgeven aan RzMon.

## 1.7.0
- Geen wijzigingen aan de agent; zelfde versienummer als RzMon 1.7.0.

## 1.6.0
- De agent geeft zijn versie door aan RzMon; die is te zien op de servertegel.
- Verbindingstest (`--test`) en duidelijkere foutmeldingen.

## 1.0.0
- Eerste versie: CPU, RAM, data-partitie, luisterende poorten, Home Assistant Core, Supervisor, add-ons en updates.
