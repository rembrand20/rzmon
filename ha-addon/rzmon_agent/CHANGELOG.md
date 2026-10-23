# Changelog van de agent

De agent (op Linux-servers en in de Home Assistant-add-on) heeft een eigen versienummer, los van RzMon. Gaat er alleen in RzMon iets veranderen, dan hoef je de agents niet bij te werken.
Deze lijst staat ook in de add-on: Home Assistant toont hem bij een update. Wat er in RzMon zelf verandert, staat in de [CHANGELOG van RzMon](https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md).

Tot en met 1.45.0 liep het nummer gelijk met RzMon. Wat er tot dan aan de agent op Linux-servers veranderde, staat in de changelog van RzMon; hieronder staat tot dan alleen wat de add-on betreft.

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
