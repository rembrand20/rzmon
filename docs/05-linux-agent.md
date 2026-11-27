# De Linux-agent

De agent is een klein bash-script op je Linux-servers. Hij meet wat RzSense van buitenaf niet kan zien (CPU, RAM, disks, services, containers, logins, logs) en stuurt dat versleuteld naar RzSense. In dit hoofdstuk lees je hoe je hem installeert en beheert, wat je op de server zelf kunt instellen, wat je ermee kunt bewaken, hoe hij veilig bijwerkt en hoe servers in een ander netwerk via een relay rapporteren. De Windows-agent staat in [Windows-agent](06-windows-agent.md), de add-on voor Home Assistant in [Home Assistant](07-home-assistant.md).

## Wat de agent doet

- **Hij meldt zich zelf.** De agent stuurt zijn rapport naar RzSense. Op de bewaakte server hoeft dus geen poort open; de server moet RzSense wel kunnen bereiken.
- **Geen daemon.** Een systemd-timer start de agent elke 30 seconden. Hij maakt een rapport en stopt weer. Zo kan hij niet vastlopen of geheugen lekken, en is een update meteen actief. Zonder systemd (en op TrueNAS) start cron hem elke minuut.
- **Wat hij standaard meldt:** CPU, RAM, alle echte mounts, de luisterende poorten, de services die je in RzSense kiest, certificaten en (als ze er zijn) containers van Podman of Docker.
- **Als root.** De agent draait als root, om bijvoorbeeld de procesnamen bij poorten te zien. De systemd-service is afgeschermd (`ProtectSystem`, `NoNewPrivileges`).
- **Versleuteld.** Elke agent heeft een eigen sleutel. Rapporten zijn versleuteld en ondertekend (AES-256 en HMAC-SHA256), elk bericht is uniek en 5 minuten geldig.

## Installeren en koppelen

### Op een gewone Linux-server

Werkt op Rocky, Alma, RHEL, Fedora, Debian, Ubuntu, Raspberry Pi OS en Proxmox VE.

1. Voeg in RzSense een server toe met type **Remote met agent**.
2. Open de pagina *Servers* en klik op de server: dat is de **Agent Manager**. Kies bij *Installeren of opnieuw koppelen* het tabblad **🐧 Linux-server** en klik op **🔗 Koppelcode maken**.
3. Klik op **Kopieer** bij het commando. Het adres van RzSense, een **koppelcode** (eenmalig, 15 minuten geldig) en de controlegetallen staan er al in:
   ```bash
   curl -fsSL 'http://192.168.1.10/api.php?action=agent_install' -o rzmon-install.sh \
     && echo '<sha256>  rzmon-install.sh' | sha256sum -c - \
     && sudo bash rzmon-install.sh --url 'http://192.168.1.10' --pair 'K7QF-M2XA-9RTD-HC4P-WE8N' --agent-sha '<sha256>'
   ```
4. Plak het op de server. Onder het venster wacht RzSense op de agent en meldt **✔ Gekoppeld**.
5. Voeg checks toe met **＋ Check**. Mounts en services kies je uit de lijst van wat de agent meldde. Of klik in de Agent Manager op **＋ Aanbevolen checks**.

Het installatiescript:
- controleert zichzelf en daarna de agent (SHA-256). Is er onderweg iets veranderd, dan stopt het;
- installeert wat ontbreekt (`curl`, `ss`, `openssl`, `inotify-tools`);
- haalt de agent op bij **je eigen RzSense** (niet bij GitHub);
- zet de instellingen in `/etc/default/rzmon-agent` (alleen leesbaar voor root);
- haalt met de koppelcode een **eigen sleutel** op. Er gaat geen sleutel leesbaar over de lijn;
- maakt de systemd-timer `rzmon-agent.timer`, de realtime-watcher `rzmon-watch` en de bewaker `rzmon-agent-guard` (zie [De bewaker en "agent stil"](#de-bewaker-en-agent-stil));
- stuurt een **testrapport** en controleert of RzSense het accepteert;
- zet het commando `rzmon-hb` klaar voor heartbeat-checks (zie [Servers en checks](04-servers-en-checks.md)).

Daarna blijft het script op de server staan als `rzmon-agent-setup` (in `/usr/local/sbin`). Op Rocky en RHEL zoekt `sudo` daar niet; daarom komt er ook een koppeling in `/usr/sbin`.

### Opties van het installatiescript

Meestal heb je alleen het gekopieerde commando nodig. Extra opties:

| Optie | Uitleg |
|---|---|
| `--url URL` | Adres van RzSense |
| `--pair CODE` | Koppelcode uit de Agent Manager (eenmalig, 15 minuten geldig) |
| `--fallback URL` | Reserve-adres als `--url` niet bereikbaar is, bv. `--url https://rzsense.example.nl --fallback http://192.168.1.10` |
| `--pin VINGERAFDRUK` | Alleen het certificaat van RzSense met deze vingerafdruk accepteren (bij een eigen, self-signed certificaat). Staat vanzelf in het commando |
| `--agent-sha SHA256` | De gedownloade agent hiermee controleren. Staat vanzelf in het commando |
| `--certs "…"` | Welke certificaten de agent bewaakt (zie [Certificaten](#certificaten)) |
| `--insecure` | Een self-signed certificaat van RzSense accepteren zonder controle. Met `--pin` niet nodig, en niet aan te raden |
| `--interval SEC` | Tik als RzSense er geen doorgeeft (30–3600). Normaal stel je de tik in RzSense in |
| `--cron` | Cron gebruiken in plaats van een systemd-timer |
| `--no-epel` | Rocky/Alma/RHEL/Oracle: EPEL niet installeren (bewaard als `EPEL=0`). Dan geen bestanden in realtime |
| `--truenas`, `--pool`, `--dir` | TrueNAS SCALE (zie [TrueNAS SCALE](#truenas-scale)) |
| `--file PAD` | Een lokale `rzmon-agent.sh` gebruiken in plaats van downloaden |
| `-y` | Niets vragen (handig voor Ansible) |

### Opnieuw koppelen

Werkt de sleutel niet meer, of verhuist RzSense? Maak in de Agent Manager een nieuwe koppelcode en voer op de server uit:
```bash
sudo rzmon-agent-setup --pair 'ABCD-EFGH-…'
```

## Beheren

### Commando's op de server

| Wat | Commando |
|---|---|
| Alles over de agent | `sudo rzmon-agent-setup --status` |
| Verbindingstest met proefrapport | `sudo rzmon-agent-setup --test` |
| Bijwerken | `sudo rzmon-agent-setup --update` (of de knop in RzSense) |
| Alleen de instellingen opruimen | `sudo rzmon-agent-setup --cleanup` |
| Nieuwe koppelcode | `sudo rzmon-agent-setup --pair 'CODE'` |
| Certificaten wijzigen | `sudo rzmon-agent-setup --certs "/etc/letsencrypt/live/*/cert.pem localhost:8443"` |
| Versie tonen | `sudo rzmon-agent-setup --version` |
| Verwijderen | `sudo rzmon-agent-setup --uninstall` |
| Logboek | `systemctl status rzmon-agent.timer` en `journalctl -u rzmon-agent -n 20` |

Opnieuw uitvoeren is altijd veilig. Een bestaand instellingenbestand blijft zoals het is; geef je zelf iets mee, zoals `--pair` of `--certs`, dan wordt alleen die regel aangepast.

### Status: `--status`

`sudo rzmon-agent-setup --status` geeft alles in één overzicht:

| Onderdeel | Wat je ziet |
|---|---|
| Agent | Versie en controlegetal, hoe hij gestart wordt (timer, cron, TrueNAS), de realtime-watcher, de rechten van het instellingenbestand en welke regels overbodig zijn, de reservekopieën |
| Verbinding | Adres en reserve-adres, hoe het certificaat van RzSense gecontroleerd wordt, of RzSense nu bereikbaar is (met de tijd voor DNS, verbinden en TLS), de TLS-versie en het certificaat van RzSense |
| Versleuteling | De eigen sleutel (kenmerk, sinds wanneer) of een waarschuwing als er nog een oud token is |
| Contact | Het laatste rapport (tijd, duur, grootte, gelukt of de fout, gecomprimeerd), wat er meeging, het ritme, de laatste 10 contacten en de fouten van de laatste 24 uur |
| Beveiligingscontrole | De uitkomst van de verbindingscontrole van RzSense (hieronder) |

### Bijwerken

Er zijn drie manieren:
- **Per server:** Agent Manager → **⬆ Bijwerken naar …**. Daar zie je ook hoe het afliep.
- **Alle tegelijk:** pagina *Servers* → **⬆ Alle verouderde agents bijwerken**.
- **Automatisch:** *Instellingen → Agents → Standaard voor alle agents* → **Agents automatisch bijwerken**. Een verouderde agent werkt zichzelf dan bij, met hooguit één poging per 6 uur.

Zo gaat het:
1. RzSense geeft de opdracht mee in het antwoord op het volgende rapport. RzSense hoeft de server dus niet te kunnen bereiken.
2. De agent haalt de nieuwe agent en `rzmon-agent-setup` op bij je eigen RzSense en controleert de **handtekening van de ontwikkelaar**, het controlegetal, of het een geldig script is en of het de gevraagde versie is. Klopt er iets niet, dan installeert hij niets en meldt hij de reden.
3. `rzmon-agent-setup --update` doet de rest. Bij het volgende rapport zie je **✔ bijgewerkt van … naar …**, of **✘** met de reden.

**Bijwerken is schoon installeren.** `--update` en de knop maken eerst een **reservekopie** van de agent en van `/etc/default/rzmon-agent` in `/var/lib/rzmon-agent/backup` (de laatste 5 blijven bewaard). Daarna komt de nieuwe agent erop, met opgeruimde instellingen:
- **Weg:** regels die niet meer nodig zijn, zoals een oud `TOKEN`, `ENC_KEY`, `PAIR_CODE`, `INTERVAL` (de tik komt uit RzSense), `PIN` (de agent bewaart die zelf), `INSECURE` (als het certificaat vastgezet is) en `SERVICES` zodra elke service een check in RzSense heeft.
- **Blijft:** alles wat je zelf instelde, zoals het adres, het reserve-adres, `CERTS`, `CERT_DIRS`, `COMMANDS`, `REMOTE_UPDATE`, en regels die RzSense niet kent.
- **Terugzetten:** `sudo cp /var/lib/rzmon-agent/backup/conf-<datum> /etc/default/rzmon-agent`. De oude agent staat ernaast als `agent-<versie>-<datum>.sh`.

Goed om te weten:
- **Alleen met de hand bijwerken:** zet `REMOTE_UPDATE=0` in `/etc/default/rzmon-agent`.
- **Mislukt?** Kijk met `journalctl -u "rzmon-agent-update-*"`, of voer `sudo rzmon-agent-setup --update` uit; dan zie je alle stappen.
- **Veiligheid:** RzSense kan alleen het agentscript van RzSense zelf laten installeren, en alleen met een geldige handtekening. Wie beheerder is in RzSense, bepaalt wel welke versie er draait. Bescherm beheerdersaccounts dus goed (sterk wachtwoord, 2FA).

### Hoe vaak rapporteert de agent?

De timer start de agent elke 30 seconden, maar hoe vaak er echt een rapport uitgaat (de **tik**), stel je in RzSense in:
- **Voor alle agents:** *Instellingen → Agents → Standaard voor alle agents* → *Agents rapporteren standaard elke …* (30 s, 60 s, 2 min of 5 min).
- **Per server:** Agent Manager → *Rapporteren* → *Elke …*. Met cron of op TrueNAS is één minuut het minimum.
- **Agent offline** volgt de tik: een agent geldt pas als stil na minstens 3 gemiste tikken (*Agent offline na … minuten zonder rapport*).

Zware onderdelen (de diepere service-controle, logbestanden doorzoeken, commando-checks) draaien volgens het *Interval* van hun check. Tussendoor gaat het vorige resultaat mee. Of een service draait, CPU, RAM, disks en poorten worden elke tik gemeten. RzSense beoordeelt de agent-checks zodra het rapport binnen is.

**Contact zien:** op de pagina *Servers* (kolom **Contact**), in de Agent Manager en op de serverkaart staat wanneer de agent zich het laatst meldde en wanneer het volgende contact verwacht wordt. 🟠 *te laat* betekent dat het contact er al had moeten zijn, 🔴 *offline sinds …* dat de offline-tijd voorbij is. Komt de agent duidelijk minder vaak dan ingesteld, dan staat er bijvoorbeeld ⚠️ *werkelijk elke 1 min (ingesteld 30 s)*: meestal draait hij dan via cron, of duurt een ronde te lang.

### Realtime

Normaal ziet RzSense een gebeurtenis bij de volgende tik. Met **realtime** weet RzSense het binnen een paar seconden:

| Wat | Hoe |
|---|---|
| SSH-login of mislukte poging, sudo, su, console | het systemlog live volgen |
| Een bewaakte service stopt, crasht of herstart | meldingen van systemd |
| Nieuw of gewijzigd bestand onder een 🔴-pad van *Bestanden bewaken* (IDS) | inotify |

- **Aanzetten:** Agent Manager → *Beveiliging* → **⚡ Realtime**. Standaard uit.
- **Hoe:** de dienst `rzmon-watch` analyseert zelf niets. Bij een gebeurtenis start hij meteen een gewone agent-run, hooguit één extra per 10 seconden.
- **inotify-tools** (voor bestanden) installeert de setup zelf. Op Rocky, Alma, RHEL en Oracle Linux staat het in **EPEL**; de setup zet EPEL zo nodig erbij. Wil je geen EPEL: `--no-epel` of `EPEL=0`. Zonder inotify-tools werkt realtime voor logins en services gewoon.
- **Op een server altijd uit:** `REALTIME=0`.
- Werkt alleen met systemd, niet via cron of op TrueNAS.

### De verbindingscontrole in RzSense

Per agent staat in de Agent Manager een lijst met ✅ of ⚠️; op de pagina *Servers* staat dezelfde uitkomst in de kolom 🔐. Gecontroleerd wordt: eigen sleutel, versleuteld, via https, certificaat van RzSense gecontroleerd, IP-slot, nieuwste versie, sleutel op tijd vernieuwd, geen oud token meer, instellingen opgeruimd met rechten 600, **agent ongewijzigd** (dezelfde SHA-256 als de agent die RzSense levert) en een reservekopie.

- **🧹 Opruimen** (Agent Manager): de agent installeert zichzelf opnieuw bij zijn volgende rapport, met reservekopie en opgeruimde instellingen.
- **🌐 Naar https:** de agent test het nieuwe adres eerst en gaat pas over als het werkt.
- **Melding:** verbindt een agent niet meer veilig (geen eigen sleutel, onversleuteld, script gewijzigd, certificaat niet gecontroleerd), dan krijg je een ⚠️. De kanalen kies je bij *Instellingen → Meldingen en rapporten → Systeemmeldingen* → *Agent verbindt niet veilig*.

Meer over sleutels en blokkades staat in [Toegang en beheer](11-toegang-en-beheer.md).

## Instellingen op de server

Bijna alles stel je in RzSense in. In `/etc/default/rzmon-agent` (op TrueNAS: `agent.conf` op de pool) kun je per server iets uitzetten of inperken. Wat hier staat, wint van RzSense, en blijft staan bij bijwerken.

| Regel | Wat het doet |
|---|---|
| `GUARD=0` | Geen bewaker op deze server |
| `GUARD_MIN=6` | Na hoeveel minuten zonder rapport de bewaker ingrijpt (standaard 6, minimaal 5) |
| `WATCHDOG=0` | Deze server doet nooit mee als waakhond |
| `REMOTE_UPDATE=0` | Alleen met de hand bijwerken |
| `COMMANDS=0` / `COMMANDS=nobody` | Nooit commando's uitvoeren / nooit als root |
| `HEAL=0` | Nooit zelf herstellen (services of containers herstarten) |
| `IDS_BLOCK=0` | Het IDS blokkeert hier nooit (detecteren gaat door) |
| `AUTH_EVENTS=0` | Logins en sudo niet melden |
| `AUTH_LOGS="…"`, `AUTH_SOURCE=file` | Ander logbestand voor logins; ook gebruiken als er een journal is |
| `REALTIME=0` | Realtime altijd uit |
| `EPEL=0` | Nooit EPEL installeren |
| `SERVICE_DEEP=0` | Geen diepere service-controle |
| `CERTS="…"`, `CERT_DIRS="…"` | Welke certificaten, en waar de agent naar certificaatbestanden zoekt |
| `PODMAN=0`, `DOCKER=0`, `PODMAN_USERS="…"` | Containers uit, of welke gebruikers rootless containers hebben |
| `LOG_DIRS="…"`, `LOG_DIRS_MAX="…"` | Waar de agent logs mag lezen (zie [Logs verzamelen](#logs-verzamelen)) |
| `LOG_SHIP=0` | Geen logs verzamelen |
| `PVE=0`, `TRUENAS=0` | Proxmox of TrueNAS niet uitlezen |
| `COMPRESS=0` | Rapporten niet comprimeren |
| `UPDATE_UNSIGNED=1`, `UPDATE_OLD_OPENSSL=1` | Noodgevallen bij bijwerken (zie [Veilig bijwerken](#veilig-bijwerken)) |
| `PUSHOVER_API=…` | Een Pushover-compatibele dienst voor de waakhond |

Aanpassen met `sudo nano /etc/default/rzmon-agent`. De agent leest het bestand bij elke ronde opnieuw.

## De bewaker en "agent stil"

### Snel en betrouwbaar rapporteren

Een rapport mag niet worden opgehouden door één traag onderdeel:
- **Commando-checks op de achtergrond:** de agent stuurt het vorige resultaat mee en meet op de achtergrond opnieuw.
- **Hartslag:** duurt een ronde langer dan de tik, dan meldt de agent elke tik "ik ben bezig". Dat telt als contact; in de Agent Manager staat *⏳ bezig sinds …*. Na een kwartier telt dat niet meer.
- **Wachtrij:** lukt versturen niet, dan bewaart de agent de metingen van CPU, RAM en disks (hooguit een dag) en stuurt ze later na. Ze komen op hun eigen tijd in de grafieken.
- **Gecomprimeerd:** rapporten groter dan 2 kB gaan met gzip, meestal 50–85% kleiner.
- **Podman of Docker antwoordt niet** (bijvoorbeeld 's nachts tijdens `podman-auto-update`): dan slaat de agent de containeronderdelen die ronde over. De rest gaat door.
- **Vangnet:** een ronde die toch blijft hangen, stopt systemd na 10 minuten.

In de Agent Manager staat bij *⏱ Laatste run* hoe lang de ronde duurde en welke onderdelen het traagst waren. Onder **🌙 Stiltes (7 dagen)** staat elke stilte van de laatste week met de reden: een traag onderdeel, *podman antwoordde niet*, *metingen nagestuurd* (de agent liep wel, maar kon RzSense niet bereiken) of *geen reden* (de server stond uit). Staat podman elke nacht op hetzelfde tijdstip stil, kijk dan met `systemctl list-timers` welke timer dan loopt.

### De bewaker

De bewaker is een aparte systemd-timer (`rzmon-agent-guard`) die **elke minuut** kijkt of de agent nog rapporteert. Hij staat los van de gewone ronde, dus ook als de agent zelf vastzit, ziet de bewaker het.

**Levensteken.** Blijft het rapport langer dan 3 minuten uit, dan stuurt de bewaker elke minuut een klein, versleuteld levensteken naar RzSense: de server leeft, of er een ronde loopt en hoe lang al, de laatste fout en wat de bewaker deed. Het levensteken telt niet als rapport: de checks blijven *onbekend* tot het echte rapport komt.

**Ingrijpen.** Is het laatste goede rapport ouder dan 6 minuten (`GUARD_MIN`), dan:
- stopt hij een ronde die al meer dan 10 minuten loopt;
- zet hij de timer van de agent weer aan als die stilstaat;
- start hij een ronde als er al 5 minuten geen is geweest;
- lopen de rondes gewoon, maar komt het rapport niet aan (netwerk, reverse proxy, RzSense weigert), dan helpt herstarten niet. Dan schrijft hij alleen een notitie met de reden (één per uur).

Hij grijpt hooguit **3 keer per uur** in. Elke ingreep staat in het **Logboek** van RzSense, bijvoorbeeld *Bewaker: ronde hing, gestopt*. Uitzetten op een server: `GUARD=0`. De bewaker werkt alleen met systemd, niet met cron of op TrueNAS.

### "Agent stil": één melding, met de reden

Stuurt een agent te lang niets, dan meldt **één** check van die server het als critical (bij voorkeur de CPU-check): *Agent stil sinds …*, met erachter wat RzSense weet:
- **met levensteken:** bijvoorbeeld *de server leeft · er loopt al 12 min een ronde (meestal traag: containers 95 s) · laatste fout … · bewaker: ronde hing, gestopt*. De server draait dus; het ligt aan de agent of aan de weg naar RzSense;
- *rapporten geweigerd (812× sinds 00:38): nieuw IP-adres …*: de agent probeert het wel, maar RzSense laat hem niet toe;
- *RzSense kreeg geen rapporten* en **geen levensteken**: de server zelf of het netwerk is weg.

De andere checks van die server worden *onbekend* (*geen gegevens: de agent is stil*), zonder eigen melding. Zo krijg je één bericht en één herstelbericht.

**Nachtlogboek.** Lukt versturen langer dan 2 minuten niet, dan houdt de agent bij sinds wanneer, hoe vaak en waarom. Is hij terug, dan staat in het Logboek één regel als *Geen contact met RzSense van 00:38 tot 07:12*, met de reden (bijvoorbeeld *geen verbinding* of *RzSense weigerde het rapport (HTTP 403)*).

### De waakhond: als RzSense zelf uitvalt

Valt RzSense uit, dan krijg je van RzSense niets meer. Daarom kan een agent RzSense bewaken. Zet in de Agent Manager van 1 of 2 servers *Meldingen* → **🐕 Waakhond** aan en kies na hoeveel minuten (standaard 5).
- Lukt het rapport zo lang niet, dan stuurt de agent **zelf** een pushbericht via Pushover en/of ntfy (*⚠️ RzSense onbereikbaar*), en een herstelbericht als RzSense terug is.
- Draait RzSense wel, maar staan de checks stil (de cron loopt niet), dan meldt de waakhond *⚠️ RzSense: checks staan stil*.
- Kan de agent ook Pushover en ntfy niet bereiken, dan lag het aan zijn eigen netwerk en stuurt hij niets. Je ziet het achteraf als systeemmelding.
- **Nodig:** Pushover of ntfy aan (*Instellingen → Meldingen en rapporten → Kanalen*) en een versleutelde verbinding. Met `WATCHDOG=0` doet een server nooit mee.

**Tijdens een update van RzSense** wachten de agents rustig. RzSense laat ze eerst weten dat er een update komt. Lukt een rapport dan niet, dan gaat de meting in de wachtrij en geeft de waakhond geen alarm. Is RzSense terug, dan sturen de agents de wachtrij na (met een paar seconden willekeurige spreiding). Duurt de update langer dan *Agents wachten tijdens een update hooguit … minuten* (*Instellingen → Systeem → Bijwerken*, 5–60, standaard 5), dan geeft de waakhond alsnog alarm. De agent gelooft "update bezig" alleen uit een versleuteld antwoord van RzSense zelf.

## Komt er niets binnen?

**In RzSense:** open de Agent Manager. Bovenaan staat een live-status:
- ✔ *Laatste rapport 12 s geleden vanaf 192.168.1.20*: de agent werkt;
- ⏳ *Nog geen rapport ontvangen*: er komt niets binnen;
- **geweigerde pogingen**, met IP-adres en reden, bijvoorbeeld een nieuw IP-adres dat op toestemming wacht.

**Op de server:**
```bash
sudo rzmon-agent-setup --test
```
De test loopt stap voor stap na waar het misgaat, en stopt bij de eerste echte fout met een oplossing:

| Stap | Wat wordt gecontroleerd | Typische oplossing |
|---|---|---|
| Planning | Draait de timer of cron? Hoe liep de laatste ronde? | `sudo systemctl enable --now rzmon-agent.timer` |
| Instellingen | Is het adres ingevuld, en is er een sleutel? | Test met `sudo`; opnieuw koppelen |
| Adres | Wijst het naar de server zelf (`localhost`)? | Het IP-adres van RzSense gebruiken |
| DNS | Is de naam van RzSense op te zoeken? | IP-adres gebruiken, of `/etc/hosts` |
| Netwerk | Is de poort van RzSense bereikbaar? | Firewall op RzSense, VLAN of router |
| RzSense | Draait daar echt RzSense, of een doorverwijzing, 404 of een reverse proxy die weigert? | Juiste URL (http/https, zonder `/public`); de proxy de server laten toelaten |
| Sleutel | Kent RzSense deze agent? | Koppelcode maken en het commando opnieuw uitvoeren |
| Gegevens | CPU, disks, services, poorten, certificaten, commando-checks | Bv. `iproute` installeren voor poorten |
| Rapport | Proefrapport versturen | – |

Zonder het installatiescript kan het ook: `sudo /usr/local/bin/rzmon-agent.sh --test`.

Foutmeldingen bij het installeren:
- **403 / geweigerd:** de koppelcode is verlopen of al gebruikt, of de server staat niet op type *Remote met agent*. Maak een nieuwe koppelcode;
- **kan niet downloaden:** het adres klopt niet, of RzSense is niet bereikbaar vanaf die server;
- **certificaat:** kopieer het commando opnieuw uit RzSense (met `--pin`). Is het certificaat van RzSense vernieuwd, dan past de oude vingerafdruk niet meer.

## Wat de agent kan bewaken

### Services en de diepere controle

Welke services de agent bewaakt, kies je in RzSense: **＋ Check → Service**. Onder *Servicenaam* staat een lijst van de services op die server met hun status (🟢 actief, 🔴 mislukt, ⚪ uit). Staat hij er niet bij, typ dan de naam. Binnen ongeveer 2 minuten bewaakt de agent hem. De agent leest alleen de status; RzSense kan niets starten of stoppen (behalve met *zelf herstellen*, zie [Servers en checks](04-servers-en-checks.md)).

Een service-check kijkt niet alleen of de service "active" is, maar ook of hij echt werkt:
> nginx draait · luistert op 80, 443 · HTTP 200 in 12 ms · geen fouten in log · draait sinds 20-10 08:15

| Laag | Wat | Bij een probleem |
|---|---|---|
| Proces | Draait het hoofdproces echt? | WARNING |
| Poorten | Luistert de service, en nemen de poorten verbindingen aan? | CRITICAL |
| Antwoordt hij? | Een test die bij de dienst past (tabel hieronder) | CRITICAL (WARNING bij bv. HTTP 5xx) |
| Herstarts | Automatische herstarts in het afgelopen uur | WARNING vanaf 3 |
| Log | Foutregels (`journalctl -p err`) van het laatste kwartier | WARNING, met de laatste foutregel |

| Service | Test |
|---|---|
| nginx, httpd, apache2, caddy, lighttpd, traefik, haproxy | HTTP-verzoek naar de eigen poort; 5xx = WARNING |
| mariadb, mysql | `mariadb-admin ping` |
| postgresql | `pg_isready` |
| redis, valkey | `PING` → `PONG` |
| ssh, sshd | SSH-begroeting |
| postfix, exim, sendmail | SMTP-begroeting |
| dovecot | IMAP-begroeting |
| named, bind9, unbound, pihole-FTL, dnsmasq | DNS-vraag krijgt antwoord |
| chronyd, systemd-timesyncd | Tijd is gesynchroniseerd |
| Overige | Alleen poorten, herstarts en log |

**Zelf aanvullen** (bewerk de service-check):
- **Eigen poort(en) of socket:** bijvoorbeeld `8080`, `127.0.0.1:9000` of `/run/php-fpm/www.sock` (meerdere met een komma, hooguit 10). Ze worden altijd getest, en de test van de dienst gebruikt ze. Werkt er een niet, dan is de check CRITICAL.
- **Extra test:** *URL moet antwoorden* (bijvoorbeeld `http://localhost:8080/health`, eventueel met een tekst die erin moet staan) of *Poort moet antwoorden*. De test draait op de server zelf, dus `localhost` werkt.
- **Eigen test-script:** een uitvoerbaar script `/etc/rzmon-agent/tests/<servicenaam>`. De eerste regel uitvoer is het bericht, de exitcode de status (0 = OK, 1 = WARNING, 2 = CRITICAL), net als Nagios-plugins:
  ```bash
  sudo mkdir -p /etc/rzmon-agent/tests
  sudo tee /etc/rzmon-agent/tests/myapp >/dev/null <<'EOF'
  #!/bin/sh
  n=$(ls /var/spool/myapp | wc -l)
  [ "$n" -gt 1000 ] && { echo "wachtrij vol: $n"; exit 2; }
  echo "wachtrij: $n"
  EOF
  sudo chmod 755 /etc/rzmon-agent/tests/myapp
  ```
  Het script moet van root zijn en mag niet door anderen te wijzigen zijn. Het mag hooguit 20 seconden duren.

Goed om te weten:
- Nieuwe of gewijzigde poorten en tests pikt de agent binnen 10 minuten op.
- Uitzetten: per check *Diepere controle* uit, of voor de hele server `SERVICE_DEEP=0`.
- Eenmalige services (`Type=oneshot`) die "exited" zijn, gelden als goed.
- Op de RzSense-server zelf (type *Lokaal*) wordt alleen de status gecontroleerd. Wil je daar de diepere controle, installeer dan de agent en maak een server van type *Remote met agent*.

### Certificaten

Certificaten bewaken kan zonder agent (RzSense maakt zelf verbinding) of via de agent. De agent is handig voor certificaat**bestanden** (zoals Let's Encrypt, ook als de dienst plat ligt) en voor diensten die alleen lokaal luisteren.

1. Klik bij de server op **＋ Check** en kies *SSL-certificaat*.
2. Onder het veld staat de lijst die de agent vond, met pad, naam, uitgever en vervaldatum (🟢, 🟠 binnen 21 dagen, 🔴 binnen 7 dagen of verlopen). Klik om te kiezen, of typ zelf een pad of `localhost:8443`.
3. Bij *Certificaat lezen* kies je *Automatisch* (bestanden en `localhost` via de agent, de rest vanaf RzSense), *Op de server zelf (agent)* of *Vanaf RzSense*.

Goed om te weten:
- De agent zoekt eens per dag naar `.crt`, `.pem`, `.cer` en `.cert` in de bekende mappen (`/etc/ssl`, `/etc/pki`, `/etc/letsencrypt`, `/etc/nginx`, `/opt`, `/srv`, Docker-volumes …). CA-lijsten van het systeem en CA-bundels slaat hij over. Met **🔄 Opnieuw zoeken** zoekt hij meteen.
- **Extra mappen:** *Instellingen → Agents → Standaard voor alle agents* → *Ook zoeken naar certificaten in*, of per server `CERT_DIRS` (vervangt de standaardmappen).
- **Hele keten:** verloopt een tussencertificaat eerder dan het certificaat zelf, dan telt die datum.
- **`CERTS` op de server** geeft een vaste lijst, met spaties ertussen. Wildcards mogen; `host:poort` zonder poort wordt 443. Zonder `CERTS` meldt de agent alle Let's Encrypt-certificaten, met `CERTS=""` geen:
  ```bash
  CERTS="/etc/letsencrypt/live/*/cert.pem localhost:8443 mail.example.com:993"
  ```
- De agent leest certificaten elk uur; RzSense rekent zelf het aantal dagen uit.
- Met *Certificaat controleren* aan wordt een certificaat dat de agent niet vertrouwt (zoals self-signed) CRITICAL. Bij bestanden telt alleen de vervaldatum.
- `--test` toont per certificaat bijvoorbeeld `localhost:8443: nas.lan, nog 63 dagen geldig`.

### Processen

Met de check **Proces** kijk je of een programma draait dat geen systemd-service is, zoals een Python-script, een Java-app of een game-server.
- **Zoeken op naam** zoals `ps` hem toont (`nginx`, `python3`), of **op patroon**: een regex in de hele opdrachtregel, bv. `python3 .*main\.py`. Eventueel alleen van één gebruiker.
- Onder het veld staat wat er draait, met aantal, gebruiker en opdrachtregel.
- Draait het niet: CRITICAL. Optioneel een minimaal of maximaal aantal (WARNING) en drempels voor CPU (100% = één core) en RAM (MB) van alle gevonden processen samen.
- Een herstart geeft een ℹ️ info-melding.

### Pad-check (traceroute)

Met **＋ Check → Pad (traceroute)** meet de agent het pad van die server naar een doel, bijvoorbeeld `1.1.1.1` of je eigen website.

| Wat | Hoe |
|---|---|
| Elke hop | vertraging, jitter en verlies, met `mtr` (of `traceroute`) |
| Switches | een switch zie je niet in een traceroute; vul hun IP-adressen in bij *Tussenliggende apparaten*, dan pingt RzSense ze en zet ze in het pad |
| UniFi | met de UniFi-koppeling: op welke switchpoort de server zit, de snelheid en de uplink |
| Virtueel | is de server een VM, dan staat de host (bij *Afhankelijk van*) als laag in het pad |
| Doorvoer | iperf3: de echte snelheid in Mbit/s naar een andere server met de agent |

- **Doorvoer:** RzSense kiest zelf een ontvanger met de agent in hetzelfde netwerk (of de server die je kiest). Daar start de agent kort een ontvanger die na één meting stopt. Per server zet je dat uit met *Mag doorvoertest ontvangen* in het formulier van de server. Standaard elk uur. Mist iperf3, dan installeert de agent het zelf (niet op TrueNAS of Home Assistant, en niet als commando's als root uit staan).
- **Installeren:** `sudo apt install mtr-tiny iperf3` (Debian/Ubuntu) of `sudo dnf install mtr iperf3` (Rocky/RHEL).
- **Melding bij:** te veel vertraging of verlies naar het doel, een tussenliggend apparaat dat niet reageert (CRITICAL), een linksnelheid die zakt (vaak een slechte kabel) en doorvoer onder de grens. Verlies bij een tussenhop van je provider telt niet zolang de hops erna niets verliezen.
- **Zien:** in de popup van de check en op *Netwerk → Paden* (zie [Netwerk](10-netwerk.md)).
- **Netwerksnelheid:** een route op *Netwerk → Netwerksnelheid* vanaf deze server maakt zelf zo'n pad-check (alleen informatief). Heeft de server wifi, dan meldt de agent ook zijn wifi (ontbreekt `iw`, dan laat RzSense het installeren als commando's als root aan staan): band, kanaal, signaal en verbindingssnelheid (zie [Netwerk](10-netwerk.md#netwerksnelheid)).

### Databases

De check **Database** bewaakt MariaDB/MySQL en PostgreSQL van binnen.

1. *＋ Check → Database*. Kies de database en wat je bewaakt: het **Overzicht** (alles in één check) of één waarde met eigen drempels en grafiek.
2. Bij *Meten*: **Via de agent (lokaal, aanbevolen)**, op de server zelf of in een container (via `docker exec` of `podman exec`). De database hoeft dan niet open op het netwerk. Of **RzSense maakt verbinding (netwerk)**.
3. Via de agent is **geen gebruiker nodig**: de agent leest MariaDB/MySQL via de socket en PostgreSQL als `postgres`; in een container gebruikt hij het root-wachtwoord uit de container. Een wachtwoord dat je toch opgeeft, gaat alleen naar een versleutelde agent en nooit op de opdrachtregel.

Via het netwerk is een gebruiker met alleen leesrechten nodig:
```sql
-- MariaDB / MySQL
CREATE USER 'rzmon_check'@'%' IDENTIFIED BY '…';
GRANT PROCESS, REPLICATION CLIENT ON *.* TO 'rzmon_check'@'%';
-- PostgreSQL
CREATE ROLE rzmon_check LOGIN PASSWORD '…' IN ROLE pg_monitor;
```

**Het overzicht** meldt het ergste van:

| Wat | WARNING | CRITICAL |
|---|---|---|
| Verbinding | | geen verbinding of inloggen mislukt |
| Verbindingen in gebruik | vanaf 90% van `max_connections` | vanaf 98% |
| Replica | 5 minuten achter | replicatie stil, of 30 minuten achter |
| Galera-cluster | | cluster niet *Primary* |
| Lange query | langer dan de ingestelde tijd (standaard 300 s) | |
| Idle in transaction (PostgreSQL) | langer dan 5 minuten | |
| Wraparound (PostgreSQL) | 50% | 75% |
| WAL-archivering (PostgreSQL) | mislukt | |
| Duur van de meting | 500 ms | 2000 ms |

**Losse waarden** (met standaard warning / critical): verbindingen in gebruik (80 / 95%), actieve query's (20 / 50), query's per seconde (voor de grafiek), trage query's per minuut (5 / 20, MariaDB/MySQL), cache-hit (onder 95 / 90%), replicatie-achterstand (60 / 300 s), grootte, langste query (60 / 300 s), deadlocks (1 / 10), tijdelijke tabellen op schijf (25 / 50%, MariaDB/MySQL), wraparound (50 / 75%, PostgreSQL) en idle in transaction (1 / 5, PostgreSQL).

Goed om te weten:
- Alles is **alleen lezen** (`SHOW GLOBAL STATUS`, `information_schema`, `pg_stat_*`); de agent leest nooit je tabellen.
- Checks op dezelfde database delen één meting.
- Een herstart van de database geeft een ℹ️-melding.
- *Nog niet bewaakt* stelt vanzelf een databasecheck voor bij een draaiende database-service of een container met een database-image.

### Containers: Podman en Docker

Staat Podman of Docker op de server, dan meldt de agent vanzelf alle containers. Er hoeft niets ingesteld te worden.

| | Wat de agent meldt |
|---|---|
| Algemeen | versie, aantal containers en pods, ruimte van images, containers en volumes, en wat je kunt opruimen |
| Per container | status en exitcode, healthcheck, automatische herstarts, image en pod, CPU (100% = één core), geheugen en limiet, netwerk- en disk-I/O, schijf (schrijflaag plus volumes) |
| Per pod | status en aantal containers (compose-projecten van Docker verschijnen als pods) |

**Bewaken:** klap de server open en klik op **📦 Containers**. Klik bij een container op **＋ Bewaken**; de status wordt altijd bewaakt, en je vinkt CPU, RAM en/of disk aan met eigen drempels. Bovenaan staan ook **＋ Podman-check** (algemene status) en **＋ Alle containers bewaken**. Per container krijg je één rij en één melding. Alle containers van alle servers samen zie je op *Monitoring → Containers*.

| Status | Wanneer | Check |
|---|---|---|
| Running | draait | OK, of WARNING als hij het afgelopen kwartier automatisch herstartte (crash) |
| Failed | gestopt met een foutcode (bv. 137 = geheugen op), vastgelopen of ongezond | CRITICAL |
| Stopped | netjes gestopt (exitcode 0) of nooit gestart | WARNING |
| Paused | gepauzeerd | WARNING |

Goed om te weten:
- **Rootless containers:** de agent zoekt zelf gebruikers met containers (in `~/.local/share/containers`); ze staan in RzSense als `gebruiker/naam`. Een vaste lijst: `PODMAN_USERS="jan piet"`, alleen root: `PODMAN_USERS=""`. CPU en RAM van rootless containers vragen cgroups v2 (standaard op Rocky/RHEL 9+, Debian 11+, Ubuntu 22.04+). Zet *lingering* aan, anders stoppen de containers als de gebruiker uitlogt: `sudo loginctl enable-linger jan`.
- **Een eenmalige taak** die met exitcode 0 stopt, is voor de algemene check geen probleem; zet op zo'n container geen status-check.
- `podman restart` met de hand telt niet als crash; alleen herstarts door de restart-policy.
- Uitzetten: `PODMAN=0` of `DOCKER=0`.

### Logbestanden doorzoeken

De agent kan logbestanden tellen op een zoekpatroon, bijvoorbeeld mislukte logins of hardwarefouten.

1. Klik bij de server op **＋ Check** en kies *Logbestand (zoeken)*.
2. Kies een **voorinstelling** of *Zelf instellen*.
3. **Zoek naar:** een regex, hoofdletters maken niet uit, `|` betekent "of". Gebruik `[0-9]` voor een cijfer (`\d` werkt niet).
4. **Bron:** een logbestand (`/var/log/secure`, `/var/log/auth.log`), `journal` (het hele systemlog), `journal:sshd` (één service) of `kernel`.
5. **Drempels:** het aantal treffers in de periode, bijvoorbeeld warning bij 5 en critical bij 20.

De periode is standaard 15 minuten. Je stelt hem in bij *Instellingen → Meldingen en rapporten → Wanneer melden* → *Alarmgedrag* → *Geavanceerd* (5 minuten tot 24 uur).

| Voorinstelling | Bron | Zoekt naar |
|---|---|---|
| Mislukte logins (SSH) | journal | `Failed password`, `Invalid user` |
| Mislukte sudo / su | journal | `incorrect password attempt`, `FAILED SU` |
| Diskfouten | kernel | `I/O error`, ATA-, EXT4/XFS/BTRFS- en NVMe-fouten |
| Hardwarefouten | kernel | `Machine check`, `EDAC`, `PCIe Bus Error`, temperatuur |
| SMART-waarschuwingen | journal:smartd | `Prefailure`, `failed self-test` |
| Geheugen vol | kernel | `Out of memory`, `oom-kill` |
| Programma's gecrasht | kernel | `segfault`, `core dumped` |
| Mislukte services | journal | `Failed to start` |
| Firewall-blokkades | kernel | `REJECT`, `DROP` |
| Fail2ban-bans | /var/log/fail2ban.log | ` Ban ` |
| Webserver 5xx / 404 / foutlog | /var/log/nginx/… | HTTP 5xx, 404, `[error]` |
| Mail: mislukte logins | journal | `SASL … authentication failed` |
| Database: fouten | journal:mariadb | `[ERROR]`, `Access denied` |

Gebruik je Apache op Rocky (httpd), kies dan `/var/log/httpd/access_log` of `error_log` als bron.

Goed om te weten:
- De agent leest alleen de **nieuwe** regels en onthoudt waar hij was, ook na logrotatie.
- Er gaan alleen gevonden regels mee (hooguit 5, ingekort), nooit hele logbestanden. Met 📈 zie je de grafiek en de laatste 5 regels.
- De agent leest alleen bestanden binnen `LOG_DIRS` (standaard `/var/log`) en de logmappen die je in RzSense kiest (zie hieronder). Een map vast toestaan op de server:
  ```bash
  echo 'LOG_DIRS="/var/log /opt/mijnapp/logs"' | sudo tee -a /etc/default/rzmon-agent
  ```

### Logs verzamelen

De agent kan ook hele logs naar RzSense sturen, waar je ze doorzoekt op de pagina **📜 Logs**.

**Bronnen kiezen:** Agent Manager → **📜 Logs**. Voeg een bron toe:
- **Bestand of map**, met `*` voor meerdere bestanden, bv. `/var/log/nginx/*.log` (hooguit 20 bestanden per bron; `.gz` wordt overgeslagen);
- **Journal** (alles) of **Journal van één service**;
- **Container** (Docker of Podman, ook de apps op TrueNAS SCALE);
- **Niveau**: alles, info en erger, warning en erger, of alleen errors. De agent filtert al op de server.

**Gevonden op deze server:** in dezelfde kaart staat wat de agent vond, in tabbladen *Logmappen*, *Containers* en *Services*. Aanbevolen logs (auth.log, secure, syslog, fail2ban, webservers, sshd) staan bovenaan en zijn al aangevinkt; klik op **＋ N toevoegen**. De agent zoekt één keer per dag, of meteen met **🔄 Opnieuw zoeken**. Hij stuurt alleen namen, groottes en tijden, nooit de inhoud.

**Logmappen voor deze server:** staat een log in een andere map, zoals `/opt/mijnapp/logs`, voeg die dan toe met **＋ Map toevoegen** of **📂 Bladeren…**. Per map zie je *✔ actief*, *⏳ wacht op de agent* of *✘ geweigerd* met de reden.

**Het plafond op de server.** RzSense kan alleen mappen kiezen binnen `LOG_DIRS_MAX`:
```bash
LOG_DIRS_MAX="/var/log /opt /srv /home /var/lib/docker /data"   # standaard
LOG_DIRS_MAX=""                                                 # RzSense mag geen mappen toevoegen
```
In die mappen leest de agent alleen bestanden die op een log lijken (`.log`, `.out`, `.err`, `.txt` of "log" in de naam). Nooit sleutels, certificaten, wachtwoorden, `.env`, instellingen (`.conf`, `.yaml`, `.php`), databases of verborgen bestanden. Zo kan ook iemand die RzSense zou overnemen geen andere bestanden van je server opvragen. Wat in `LOG_DIRS` staat, leest de agent altijd: dat koos je op de server zelf.

**Hoe het werkt:**
- Een nieuwe bron begint aan het einde: oude regels worden niet ingelezen.
- Elke 5 minuten (instelbaar) gaan de nieuwe regels als één pakketje naar RzSense, **versleuteld en gecomprimeerd**. Is RzSense niet bereikbaar, dan bewaart de agent de pakketjes (tot 50 MB).
- **Maskeren** (standaard aan): wachtwoorden, tokens, API-sleutels en e-mailadressen worden `••••` vóór het opslaan.
- De logbestanden op de server blijven staan.
- Uitzetten op een server: `LOG_SHIP=0`.

**Instellingen:** *Instellingen → Monitoring → Logs verzamelen*: de hoofdschakelaar, versturen elke 5 minuten (1–60), bewaren 14 dagen, maximaal 5 GB in totaal en 200 MB per server per dag. Haalt een server de daglimiet, dan pauzeert hij tot middernacht en krijg je een systeemmelding.

**Zoeken op de pagina Logs:**
- **Zoekbalk:** losse woorden, `"exacte zin"`, `-uitsluiten`, `server:naam`, `bron:naam`, `niveau:error`, `/regex/` en `OR` (`sshd OR sudo`).
- **Velden:** RzSense haalt velden uit de regels, zoals programma, ip, gebruiker en de status van een HTTP-verzoek. Zoek met `veld=waarde`, bijvoorbeeld `status=404` of `ip=203.0.113.*`.
- **Tijdlijn:** klik of sleep over de balken om een stuk te kiezen, en zoom in.
- **Tabbladen:** *Logregels*, *Patronen* (regels die alleen in getallen, IP-adressen of namen verschillen, samen; *Zeldzaamst* laat zien wat opvalt) en *Statistiek*.
- **Context** bij een regel toont de regels ervoor en erna, en wat andere bronnen van die server in dezelfde minuut schreven.
- **⤓ CSV** exporteert tot 5000 treffers.

**Vaste zoekacties** geven een melding:
- Klik op **🔔 Bewaren als melding**, of op het tabblad **🔔 Vaste zoekacties** op **＋ Nieuwe zoekactie**, of kies uit de **📚 Bibliotheek** met kant-en-klare zoekacties (inloggen, systeem, webserver, database, mail, Axigen, beveiliging en *moet juist wél gebeuren*).
- **Melden als:** *te veel treffers* (bijvoorbeeld `"failed password"` 10 keer in 5 minuten) of *geen treffers* (handig voor `"backup completed"`).
- Per server apart of alle servers samen, met ernst, rustperiode en herstelmelding.
- De **proef** laat vooraf zien hoe vaak de zoekactie de afgelopen 24 uur had gemeld.
- Hooguit 50 vaste zoekacties. Een zoekactie die te zwaar is, zet RzSense uit (met een melding).

### Commando-checks

Met een **Commando**-check voert de agent een commando uit, en vergelijkt RzSense de uitvoer met wat je verwacht.

| Commando | Goed als de uitvoer | Verwacht |
|---|---|---|
| `zpool status -x` | is gelijk aan | `all pools are healthy` |
| `systemctl is-active nginx` | is gelijk aan | `active` |
| `! test -f /var/run/reboot-required` | exitcode is 0 | – |
| `curl -s localhost:8080/health` | bevat | `"status":"up"` |

**Eerst aanzetten.** Standaard voert geen agent commando's uit. Zet het aan bij *Instellingen → Agents → Commando-checks*: *Uit*, *Toestaan, als gebruiker nobody* (genoeg voor de meeste commando's) of *Toestaan, als root*. Per server kan het anders: Agent Manager → *Commando-checks*. Ruimer maken vraagt je wachtwoord en komt in het logboek. Op de server zelf kun je het altijd inperken met `COMMANDS=0` (nooit) of `COMMANDS=nobody` (nooit als root).

**Toevoegen:** *＋ Check → Commando*. Vul het commando in, hoe het vergeleken wordt (*is gelijk aan*, *bevat*, *komt overeen met (regex)* of *exitcode is 0*), het verwachte antwoord, critical of warning, en de time-out (1–60 s, standaard 10). Bij *Voorinstelling* staan kant-en-klare checks voor TrueNAS/ZFS, Proxmox en Linux (mislukte services, herstart nodig, updates, klok, inodes, temperatuur, RAID, SMART). Voorinstellingen met *(root)* werken alleen als root mag.

Goed om te weten:
- De agent draait het commando in `bash`, in een kale omgeving, zonder invoer en met een time-out. Hij stuurt de uitvoer (hooguit 4 KB) en de exitcode terug; RzSense vergelijkt. Alle commando's samen hooguit ongeveer 40 seconden per rapport.
- Een time-out, *commando niet gevonden* en *geblokkeerd op de server* worden duidelijk gemeld. `--test` toont per check de exitcode, de tijd en als wie hij draaide.
- **Gebruik https naar RzSense.** De commando's gaan van RzSense naar de agent; over gewone http kan iemand op je netwerk ze onderweg aanpassen.

### Logins en sudo

De agent meldt wie er inlogt (SSH en console) en wie `sudo` of `su` gebruikt. Je ziet het op de pagina **🛡️ IDS**, tabblad **🔑 Toegang**. Er gaat geen pushbericht uit.

| Gebeurtenis | Voorbeeld |
|---|---|
| SSH-login | 🔑 *SSH · jan* op web01, vanaf 192.168.2.10 (sleutel) |
| sudo | 🔑 *sudo · jan* op web01: als root `systemctl restart nginx` |
| su | 🔑 *su · jan → root* op web01 |
| Mislukte SSH-login | ❌ *37× mislukte SSH-login* op web01, vanaf 45.1.2.3 · gebruikers: root, admin |
| Mislukte sudo of su | ❌ *mislukte sudo · gast*: 3 incorrect password attempts |

Goed om te weten:
- **Aan/uit:** *Instellingen → IDS en EDR → IDS algemeen* → *Toegang bijhouden* (standaard aan, ook als het IDS uit staat). Op een server altijd uit: `AUTH_EVENTS=0`.
- **Negeren:** klik 🙈 bij een regel, of vul gebruikers en IP-adressen of netwerken in die niet getoond worden.
- **⛔ Blokkeer** bij een regel met een IP-adres opent het venster *IP blokkeren* (zie [Beveiliging](08-beveiliging.md)).
- Mislukte pogingen worden per uur opgeteld (*37×*), zodat de pagina niet volloopt bij een aanval.
- Wachtwoorden in sudo-commando's worden al op de server gemaskeerd.
- **Bron:** het systemlog, of zonder journald `/var/log/secure` of `/var/log/auth.log`. Een ander bestand: `AUTH_LOGS="/pad/naar/log"`; met `AUTH_SOURCE=file` ook als er een journal is.
- De eerste keer begint de agent bij *nu*: oude logins komen er niet in.

## Speciale systemen

### TrueNAS SCALE

Op TrueNAS SCALE horen `/usr` en `/etc` bij TrueNAS zelf; wat je daar zet, verdwijnt bij een update. De agent komt daarom op je **pool** en draait via een **cronjob van TrueNAS**.

1. Voeg een server toe met type **Remote met agent**.
2. Open de Agent Manager, tabblad **🗄️ TrueNAS SCALE**, en klik op **🔗 Koppelcode maken**. Kopieer het commando.
3. Voer het uit in TrueNAS onder *System → Shell* (of via SSH). Het script:
   - zet alles in `/mnt/<pool>/rzmon-agent` op de eerste pool (niet `boot-pool`). Een andere pool: `--pool naam`, een eigen map: `--dir /mnt/…`;
   - schrijft de instellingen in `agent.conf` (alleen leesbaar voor root);
   - maakt de cronjob **RzSense agent** (als root, elke minuut). Je ziet hem onder *System → Advanced → Cron Jobs*. Lukt dat niet via de API, dan toont het script het commando om hem zelf te maken;
   - stuurt een testrapport.

**Beheren:** bijwerken met de knop in RzSense. Met de hand: `bash /mnt/<pool>/rzmon-agent/rzmon-agent-setup --update`, `--test` of `--uninstall` (haalt ook de cronjob weg).

**TrueNAS uitlezen.** De agent vraagt TrueNAS zelf met `midclt`, zonder API-sleutel. Bij de server staat de knop **🗄️ TrueNAS** met een overzicht, of kies bij *＋ Check* het type **TrueNAS**:

| Bewaken | Wat | Hoe vaak |
|---|---|---|
| Meldingen | de meldingen van TrueNAS zelf (WARNING = WARNING, ERROR of erger = CRITICAL) | elke 5 min |
| Taken | replicatie, snapshots, cloud sync en rsync: laatste keer mislukt = CRITICAL | elke 5 min |
| Apps | vastgelopen app = CRITICAL; gestopte apps en updates staan in de melding | elke 5 min |
| TrueNAS-update | WARNING bij een nieuwe versie, of als TrueNAS op een herstart wacht | elke 6 uur |
| Schijftemperatuur | de warmste schijf, standaard 50 / 60 °C (uit `midclt`, anders uit SMART) | elk kwartier |

Goed om te weten:
- **Pools:** een diskcheck op `/mnt/tank`, en een commando-check `zpool status -x` (voorinstelling *ZFS-pools gezond*).
- **Apps:** de agent ziet de Docker-containers van je apps, net als op andere servers.
- **Kwetsbaarheden:** RzSense scant TrueNAS als Debian, maar bijwerken gaat nooit met apt (dat kan TrueNAS kapotmaken). Werk bij met *System → Update* in TrueNAS.
- **IDS:** standaard alleen detecteren. Wil je ook blokkeren, zet dan bij de server onder **🛡️ Hardening** *IDS mag hier blokkeren* aan. De agent gebruikt dan een eigen firewalltabel (`inet rzmon`) en zet die elke minuut terug, ook na een TrueNAS-update.
- Geen realtime en geen bewaker (er is geen eigen systemd-dienst mogelijk).
- `TRUENAS=0` in `agent.conf` zet het uitlezen uit.
- **TrueNAS CORE** (FreeBSD) kan de agent niet draaien. Voeg hem toe als *Remote* met ping, poorten (445, 2049, 22) en HTTP(S).

### Proxmox VE

Proxmox VE is Debian: de gewone agent draait er, als root op de host (niet in een VM of container). De agent leest Proxmox zelf uit met `pvesh`, zonder API-token.

**Installeren:** Agent Manager → tabblad **🖥️ Proxmox VE** → **🔗 Koppelcode maken**, en voer het commando uit in de shell van de node (*Datacenter → node → Shell*).

**Wat de agent meldt:**
- **Guests** (VM's en containers): status, CPU, RAM, uptime, lock (bv. `backup`), of hij automatisch start en of de QEMU guest agent antwoordt. Elke 2 minuten.
- **Opslag** en **ZFS-pools:** beschikbaar, hoe vol, gezondheid en laatste scrub.
- **Back-ups:** de nieuwste back-up per guest (vzdump en Proxmox Backup Server), de back-uptaken en de laatste vzdump-taken. Elk kwartier.
- **Cluster:** nodes en quorum.

**Checks (type Proxmox VE)**, het snelst met **＋ Aanbevolen checks**:

| Bewaken | Wat | Standaard |
|---|---|---|
| Overzicht van de node | CRITICAL: guest die automatisch moet starten staat uit, opslag weg, ZFS niet gezond, geen quorum. WARNING: gepauzeerde of vergrendelde guest, mislukte back-uptaak | – |
| Eén guest (VMID) | uit = CRITICAL; RAM met drempels; WARNING als de guest agent niet antwoordt | 90 / 95% |
| Back-ups | leeftijd van de nieuwste back-up; nooit = CRITICAL; guest in geen enkele back-uptaak = WARNING. Per guest anders: `101=7/14 105=uit` | 2 / 7 dagen |
| Opslag | hoe vol; niet beschikbaar = CRITICAL | 85 / 95% |
| ZFS-pools | niet ONLINE = CRITICAL; vulling; scrub ouder dan 35 dagen = WARNING | 80 / 90% |

Bij de server staat **🖥️ Proxmox** met alle guests, hun laatste back-up en per guest **＋ Check**. Losse nodes zie je samen als je ze in dat overzicht dezelfde clusternaam geeft. Uitzetten op een node: `PVE=0`.

## Veilig bijwerken

Elke nieuwe agent is **ondertekend** door de ontwikkelaar, met een geheime sleutel die niet op je RzSense staat. In de agent zit de bijbehorende publieke sleutel. Bij bijwerken controleert de agent (en `rzmon-agent-setup` bij installeren en `--update`) de handtekening, en installeert alleen als die klopt. Zo kan ook iemand die je RzSense zou overnemen geen eigen code op je servers zetten.

- Je werkt gewoon bij met de knop. In de Agent Manager staat daarna *🔏 Laatste update: handtekening van de ontwikkelaar gecontroleerd*.
- Klopt de handtekening niet, dan weigert de agent de update (*✘ Laatste update geweigerd*) en blijft de oude versie werken.
- Een oudere versie dan de huidige wordt nooit geïnstalleerd.
- **OpenSSL ouder dan 3.0** (bijvoorbeeld RHEL/Rocky 8) kan de handtekening niet controleren. Dan werkt de agent niet vanzelf bij, tenzij je `UPDATE_OLD_OPENSSL=1` zet (dan alleen met het controlegetal van RzSense).
- **Noodgeval:** `UPDATE_UNSIGNED=1` werkt bij zonder handtekening. Niet aan te raden.

## Netwerken en relays

Heb je servers in een netwerk **zonder verbinding met RzSense**, zoals een tweede vestiging, een apart VLAN of een klant? Zet daar dan een **relay** neer:

```
agent op server ──▶ relay ──▶ RzSense   (en het antwoord dezelfde weg terug)
```

De relay draait op een machine in dat netwerk en stuurt de rapporten door naar RzSense, via internet, een VPN of een eigen route. Er zijn twee soorten:
- **Losse agent** (`rzmon-agent-relay`): op een Linux-server met systemd en Python 3.7 of nieuwer. Hij draait als gebruiker `rzrelay` (niet als root), naast een gewone agent. In de Agent Manager van die server staat hij op de kaart **🛰️ Relay op deze server**. Bijwerken gaat met de knop of automatisch, met een geldige handtekening. Beheer op de server: `sudo rzmon-agent-relay-setup --status`, `--update` of `--uninstall`.
- **Container** (`ghcr.io/rembrand20/rzsense-relay`): met Podman of Docker, voor amd64 en arm64 (zoals een Raspberry Pi 4 of 5 met een 64-bits systeem). Bijwerken met een nieuwe image:
  ```bash
  sudo podman pull ghcr.io/rembrand20/rzsense-relay:latest && sudo systemctl restart rzsense-relay
  ```

### Een relay instellen

Ga naar *Instellingen → Agents → Netwerken en relays*.
1. Klik op **＋ Relay toevoegen** (bij een losse agent kies je *Op welke server*), of gebruik in de Agent Manager **Relay op deze server installeren**. Klik op **📦 Installeren** en voer het commando (losse agent, Podman, Docker of compose) uit op de machine in dat netwerk. Binnen een minuut staat de relay op 🟢 *online*.
2. Kies het **🌐 Adres** waarop de agents de relay bereiken, bijvoorbeeld `https://192.168.50.10:8444`. Zet poort 8444 open in de firewall van die machine.
3. Klik op **＋ Netwerk toevoegen**: een naam, de adresreeks(en) (bijvoorbeeld `192.168.50.0/24`), *Via* de relay, en eventueel een reserve-relay.
4. Servers met een adres in die reeks vallen vanzelf in het netwerk. Vink aan wie moet overstappen en klik op **↪ Adressen toepassen**. De agent test de relay eerst en stapt pas over als dat lukt; zijn rechtstreekse adres wordt het reserve-adres.
5. **Nieuwe servers** in dat netwerk: kies in de Agent Manager bij het installeren *Verbinden via* de relay.

**Veilig:**
- Rapporten zijn versleuteld van de agent tot RzSense; de relay kan ze niet lezen of veranderen.
- De relay stuurt alleen agentverkeer door, en alleen van afzenders in de netwerken die via hem gaan. Zonder netwerk laat hij niemand door.
- Het echte IP-adres van de agent geeft de relay ondertekend door, dus het IP-slot blijft werken.
- De agents accepteren alleen het certificaat van hun relay.

**Valt een relay weg,** dan krijg je één melding: *Agent stil* van de relay, met hoeveel agents erachter zitten. Hun meldingen worden onderdrukt; ze bewaren hun metingen en sturen ze na. Haal je een relay weg, dan gaan zijn netwerken over op de reserve-relay of rechtstreeks.

### RzSense haalt op (DMZ)

In een DMZ mag het netwerk meestal niet naar binnen bellen, maar je eigen netwerk wel naar de DMZ. Kies dan bij *＋ Relay toevoegen* voor **RzSense haalt op**:

```
            je netwerk              │                DMZ
 RzSense ── haalt op (elke 15 s) ───┼──▶ relay ◀── agent A
         ◀─ rapporten ──────────────┼───        ◀── agent B
         ── antwoorden ─────────────┼──▶   (de relay belt nooit zelf naar RzSense)
```

- De agents leveren hun rapport af bij de relay. De relay bewaart het en geeft de agent het antwoord dat RzSense de vorige keer klaarlegde. RzSense haalt elke 15 seconden (5–300) alles op en legt de antwoorden terug. Een antwoord komt dus één ronde later bij de agent.
- **Instellen:**
  1. *＋ Relay toevoegen* → *RzSense haalt op*, en vul het **ophaaladres** in, bijvoorbeeld `https://10.0.5.10:8445`.
  2. **📦 Installeren.** De relay kan RzSense niet bereiken, dus je neemt de bestanden mee. Losse agent: **⬇ Bundel downloaden**, kopieer hem naar de relay en voer daar `sudo bash install-relay.sh --src . --pull-key … --pair …` uit. Container: haal de image op een machine met internet (`podman save`), zet hem op de relay (`podman load`) en vul `RELAY_PULL_KEY` en `PAIR_CODE` in `relay.env` in.
  3. Zodra RzSense de eerste keer ophaalt, koppelt de relay zich.
  4. Zet het adres voor de agents (poort 8444) en maak een netwerk via deze relay, zoals hierboven.
- **Firewall:** poort 8445 alleen vanaf RzSense, poort 8444 voor de agents in de DMZ. Met `RELAY_PULL_FROM=10.0.0.5` laat de relay zelf ook alleen dat adres ophalen.
- **Veilig:** elk verzoek en antwoord tussen RzSense en de relay is ondertekend met een eigen sleutel per relay. Het certificaat van de relay wordt vastgezet. De sleutel staat in het installatiecommando: houd dat geheim.
- **Wachtrij:** de relay bewaart hooguit 24 uur en 200 MB (`RELAY_Q_HOURS`, `RELAY_Q_MB`). Haalde RzSense een tijd niet op, dan verwerkt het van elke agent het nieuwste rapport volledig en van de oudere alleen de metingen. Lukt ophalen niet, dan wordt de relay *agent stil*.
- De relay levert de agent en het installatiescript zelf uit, dus agents installeren en bijwerken in de DMZ werkt gewoon. De relay zelf werk je bij met een nieuwe bundel (`sudo bash install-relay.sh --src . --update`) of een nieuwe image.

Meer over de relay, ook handmatig installeren als container, staat in [relay/README.md](../relay/README.md).

## De Go-agent (`rzsense`)

RzSense stapt over op één agent in Go, dezelfde als op Windows: één bestand zonder afhankelijkheden, ondertekend, met
één commando om hem te bedienen. Dat gaat in stappen. De Go-agent begint als **proef naast de bash-agent**: de bash-agent
doet al het werk, de Go-agent meet mee en RzSense vergelijkt de uitkomsten. Was de proef goed, dan kun je de Go-agent
per server **het werk laten overnemen** (zie [Overnemen](#overnemen-de-go-agent-doet-het-werk)).

### Wat de proef doet

- Elke minuut meet de Go-agent de hostnaam, de opstarttijd, CPU, geheugen, schijven, de luisterende poorten (poort,
  adres en proces), de status van de diensten waar je een check op hebt, de certificaten (bestanden en `host:poort`),
  de proces-checks en de lijst met processen, de details per dienst (poorten, een test die bij de dienst past, fouten in
  het log), de extra tests bij een service-check en de lijst met diensten, op dezelfde manier als de bash-agent, en
  stuurt dat versleuteld naar RzSense. Een eigen testscript bij een dienst voert hij in de proef niet uit. Certificaten leest hij hooguit eens per uur opnieuw, net als de bash-agent.
- RzSense legt het naast wat de bash-agent in de laatste 3 minuten meldde. *Gelijk* betekent: exact waar het exact hoort
  (grootte van geheugen en schijven, aantal kernen, de koppelpunten, de poorten met hun adres en proces, de diensten en
  hun status, de naam, uitgever, vervaldatum en keten van een certificaat, het aantal en de PID's van een proces-check)
  en binnen een marge waar de twee op een ander moment meten (CPU-gebruik, vrije ruimte, geheugen van een proces). Bij
  een certificaat tellen *vertrouwd* en *fout* als ja of nee, omdat de tekst per programma verschilt. De lijst met
  processen mag een paar namen verschillen: processen komen en gaan.
- Elke ronde vergelijkt RzSense ook de klok (bron, gesynchroniseerd, afwijking). Staat Kwetsbaarheden of End-of-life
  aan, dan legt RzSense de softwarelijst van de Go-agent naast die van de bash-agent, kort nadat die opnieuw gemaakt is:
  de pakketten met hun versie, het systeem en de kernel, en welke pakketten via het netwerk bereikbaar zijn.
- Ook schijfgezondheid, UPS en pad-checks meet de Go-agent in de proef mee (zonder iperf3). Bij een pad-check telt het
  pad zelf: netwerkkaart, adres, gateway, snelheid en of het doel bereikbaar is. De vertraging per hop verschilt per
  meting en telt niet.
- Eens per 10 minuten leest de Go-agent ook de containers mee: welke er zijn, en per container de status, health, image,
  pod en het aantal herstarts.
- Eens per uur, vlak nadat de bash-agent gekeken heeft, kijkt de Go-agent welke updates er klaarstaan (zonder zelf iets
  te verversen) en vergelijkt RzSense de twee lijsten: pakket, versie, beveiliging ja of nee, en *herstart nodig*.
- De Go-agent leest de instellingen (`/etc/default/rzmon-agent`), de sleutel en de testlijst van de bash-agent, maar
  schrijft er niets bij. RzSense bewaart een proefronde apart: de gegevens, de sleutel en het contactmoment van de server veranderen niet.

### Starten en weghalen

Open de **Agent Manager** van de server en zoek het blok **🧪 Go-agent**. Kopieer het commando en voer het uit op
de server. Het script:

1. kiest het bestand voor de processor (amd64, arm64 of armv7; op een andere processor stopt het en blijft de server op
   de bash-agent);
2. controleert de **handtekening van de ontwikkelaar** en installeert niets als die niet klopt;
3. zet `/usr/local/bin/rzsense` neer en een timer (`rzsense-shadow.timer`, zonder systemd een cronjob) die elke minuut
   een proefronde draait;
4. zet een bewakertje neer (`rzsense-fallback.timer`) voor de terugval op de bash-agent. Dat doet pas iets als de
   Go-agent het werk heeft overgenomen.

Het tweede commando in het blok (met `--remove`) haalt alles weer weg. Deed de Go-agent het werk, dan gaat eerst de
bash-agent weer aan. De server is daarna precies zoals hij was.

Voorwaarden: de bash-agent is geïnstalleerd en gekoppeld met een koppelcode (een eigen sleutel), en OpenSSL 3 of nieuwer
voor de handtekening. Met een oudere OpenSSL stopt het script; wil je toch door, voeg dan `--old-openssl` toe.

### De uitkomst lezen

In het blok staat hoeveel proefrondes gelijk waren, de laatste ronde per meting (bash-agent naast Go-agent, met ≠ waar
het verschilt) en waar het vaakst een verschil zat. Een ronde zonder verse meting van de bash-agent telt niet mee. Met
*Opnieuw beginnen te tellen* zet je de tellers op nul.

**Bijwerken.** De Go-agent werkt zichzelf bij: heeft RzSense een nieuwere versie, dan haalt hij die bij de volgende
proefronde op. Hij vervangt zichzelf alleen als de handtekening van de ontwikkelaar klopt, de versie echt nieuwer is en
het nieuwe bestand op de server kan draaien; anders blijft de oude versie staan en staat de reden in het blok en in
`rzsense status`. Met `REMOTE_UPDATE=0` in de instellingen van de agent gebeurt dit niet. Een heel oude Go-agent (van
vóór het zelf bijwerken) werk je één keer bij door het installatiecommando opnieuw uit te voeren. Een oudere Go-agent
wordt alleen vergeleken op wat hij meet.

### Overnemen: de Go-agent doet het werk

In het blok **🧪 Go-agent** staat de knop **Overnemen** zodra het veilig kan. RzSense staat het toe als:

- de proef minstens 60 vergeleken rondes liep (een uur) en minstens 98% daarvan gelijk was aan de bash-agent;
- de Go-agent op de server de nieuwste versie is en er nog proefrondes binnenkomen;
- de bash-agent via systemd draait, het bewakertje voor de terugval er staat en de Go-agent in de sleutelmap en de
  cachemap van de bash-agent mag schrijven (anders: voer het installatiecommando opnieuw uit);
- de server **niets gebruikt wat de Go-agent nog niet meet**. Anders zou dat onderdeel stilvallen.

Kan het nog niet, dan staat in het blok precies wat het tegenhoudt. De Go-agent meet CPU, geheugen, schijven, de
opstarttijd, poorten, diensten (met details en extra tests), certificaten en processen, en leest logins, sudo en su
(met dezelfde bladwijzer in het log als de bash-agent, zodat er bij het wisselen niets verloren gaat of dubbel komt).
Ook Updates doet hij: eens per uur welke updates er klaarstaan, en installeren als je daar in *Updates* om vraagt
(alleen het vaste commando van de pakketbeheerder, nooit een herstart, nooit op TrueNAS, niet met `COMMANDS=0`).
Verder stuurt hij de softwarelijst voor *Kwetsbaarheden* (één keer per dag, of als er iets geïnstalleerd is), de
stand van de klok voor de check *Tijd*, de schijfgezondheid (SMART), de UPS en de metingen van pad-checks (met de
doorvoertest via iperf3). Commando-checks voert hij uit zoals de bash-agent (kale omgeving, time-out, standaard als
`nobody`; `COMMANDS=0` op de server blokkeert ze), maar pas als hij het werk doet: in de proef niet, anders zou elk
commando twee keer lopen. Hetzelfde geldt voor logs: log-checks, logs verzamelen, logs vinden en een map doorbladeren
doet hij met dezelfde regels als de bash-agent (alleen binnen `LOG_DIRS`, of logs in een map uit RzSense binnen
`LOG_DIRS_MAX`; nooit sleutels, wachtwoorden of instellingen) en met dezelfde bladwijzers en wachtrij, zodat er bij het
wisselen geen regel verloren gaat of dubbel telt. Containers (Podman en Docker, ook rootless) leest hij zoals de
bash-agent, en heartbeats van `rzmon-hb` stuurt hij door. *Zelf herstellen* doet hij ook: een dienst herstarten, een
container starten of een eigen herstelcommando uit `HEAL_DIR` uitvoeren, met dezelfde grenzen (één poging per 10 minuten,
3 per dag per check; `HEAL=0` zet het uit). Ook de waakhond werkt: een bericht via Pushover of ntfy als RzSense een
tijd onbereikbaar is of als de checks stilstaan (`WATCHDOG=0` zet het uit). Tijdens een aangekondigde update van RzSense
wacht de Go-agent: geen alarm en geen terugval op de bash-agent. Anders dan de bash-agent bewaart hij in die minuten
geen metingen in een wachtrij.
Dit houdt overnemen tegen zolang het voor de server aan staat: logs van webdiensten (met het IDS aan, en alleen
op een server waar zo'n log gevonden is); IDS en endpoint-sensoren;
Kwetsbaarheden; Realtime; checks op Proxmox, TrueNAS, databases, VPN en netwerkverkeer; de relay; en vaker dan elke minuut rapporteren.

**Wat er gebeurt.** Bij de volgende proefronde zet de Go-agent de timer van de bash-agent en zijn bewaker uit (de
bash-agent blijft geïnstalleerd, met zijn instellingen en sleutel), haalt de testlijst op en stuurt het echte rapport.
Pas als RzSense dat aanneemt, geldt de overname; lukt het niet, dan gaat de bash-agent meteen weer aan. In het
logboek van de server en bij de meldingen staat wanneer de Go-agent het overnam.

**Terug naar de bash-agent** gaat op vier manieren, en altijd zonder dat de bewaking stilvalt:

- met de knop **Terug naar de bash-agent** in het blok (binnen een minuut);
- vanzelf, als je later iets aanzet wat de Go-agent nog niet kan (RzSense meldt welk onderdeel);
- vanzelf, als het rapporteren 5 keer achter elkaar mislukt, of als er 10 minuten geen rapport aankwam (het bewakertje,
  ook als de Go-agent zelf niet meer start). De Go-agent probeert het dan na een uur opnieuw; de reden staat in het blok
  en in `rzsense status`;
- op de server zelf met `sudo rzsense back`. Zet het overnemen dan ook in RzSense uit, anders neemt de Go-agent het na
  een uur weer over.

Zolang de Go-agent het werk doet, komt er van die server geen netwerkverkeer per netwerkkaart binnen, en werken 🧪 Test en *Opnieuw zoeken* naar certificaten niet.

### Het commando `rzsense`

| Commando | Wat het doet |
|---|---|
| `rzsense status` | de versies, het adres van RzSense, wie het werk doet, of de proef loopt en de laatste proefronde |
| `rzsense test` | kan deze server RzSense bereiken, en herkent RzSense hem? |
| `rzsense doctor` | loopt alles na: instellingen, adres, sleutel, DNS, verbinding, of RzSense de server herkent en de planning van de bash-agent. Bij elk probleem staat hoe je het oplost |
| `rzsense collect` | laat zien wat de Go-agent meet, zonder iets te versturen |
| `rzsense shadow` | één ronde: een proefronde met de uitkomst van de vergelijking, of het echte rapport als de Go-agent het werk doet |
| `rzsense back` | terug naar de bash-agent, als de Go-agent het werk had overgenomen |

Start ze met `sudo`: de sleutel van de agent is alleen voor root leesbaar.
