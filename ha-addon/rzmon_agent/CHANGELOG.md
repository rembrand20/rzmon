# Changelog van de agent

De agent (op Linux-servers en in de Home Assistant-add-on) heeft een eigen versienummer, los van RzMon. Gaat er alleen in RzMon iets veranderen, dan hoef je de agents niet bij te werken.
Deze lijst staat ook in de add-on: Home Assistant toont hem bij een update. Wat er in RzMon zelf verandert, staat in de [CHANGELOG van RzMon](https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md).

Tot en met 1.45.0 liep het nummer gelijk met RzMon. Wat er tot dan aan de agent op Linux-servers veranderde, staat in de changelog van RzMon; hieronder staat tot dan alleen wat de add-on betreft.

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
