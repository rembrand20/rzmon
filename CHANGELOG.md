# Changelog

Alle wijzigingen aan RzMon, per versie. Nieuwste bovenaan.
Versienummers volgen [Semantic Versioning](https://semver.org/lang/nl/):
`MAJOR.MINOR.PATCH`. MINOR gaat omhoog bij nieuwe functies, PATCH bij reparaties,
en MAJOR bij wijzigingen waarvoor je zelf iets moet aanpassen.

Het versienummer staat in [`VERSION`](VERSION). Verhogen gaat met `tools/bump-version.sh` (zie [CLAUDE.md](CLAUDE.md)).

## 1.7.0 – 2026-10-20
### Nieuw
- **Gebruikers en tweestapsverificatie (2FA):** onder *Instellingen → Gebruikers* maak je accounts aan, met of zonder 2FA. Je kunt 2FA instellen met een QR-code voor elke authenticator-app, 2FA resetten, wachtwoorden opnieuw instellen en gebruikers verwijderen. Bij inloggen met 2FA volgt na het wachtwoord een 6-cijferige code. Een code werkt maar één keer, en na 5 foute codes begin je opnieuw. Telefoon kwijt? `php install/install.php --reset-2fa <naam>` zet 2FA uit.
- **E-mailmeldingen:** SMTP met STARTTLS, SSL/TLS of zonder versleuteling, en met inloggen. Je kiest *altijd* (naast Pushover) of *alleen als Pushover uit staat of mislukt*. De mail bevat ook de bevestig-link. Er is een testknop, en in het meldingenlog staat 📧.
- **MQTT discovery voor Home Assistant:** elke server verschijnt in Home Assistant als apparaat. Je krijgt sensoren voor de status en de meetwaarde van elke check, een probleem-sensor per server, en een overzicht van RzMon zelf. Server, poort, TLS, gebruikersnaam en wachtwoord stel je in onder Instellingen. Verwijderde checks verdwijnen ook uit HA. Stopt RzMon, dan worden de sensoren na 10 minuten *niet beschikbaar*.
- **Checktype SSL-certificaat:** laat zien hoeveel dagen een certificaat nog geldig is, met eigen drempels (standaard warning onder 21 dagen, critical onder 7). Werkt op elke TLS-poort. Per check kun je kiezen of het certificaat gecontroleerd wordt; uitzetten is handig bij self-signed certificaten, zoals van Proxmox of een NAS. Die keuze geldt nu ook voor HTTP(S)-checks.
- **Beschikbaarheid per server** over 24 uur, 7 dagen en 30 dagen, op de tegel en in de REST API (`uptime`).
- **Minigrafieken** van de laatste 24 uur bij elke check. Klik erop voor de grote grafiek, die nu ook minimum, gemiddelde en maximum toont.
- **Diskvoorspelling:** RzMon berekent hoe snel een disk volloopt ("vol over ~12 dagen") en geeft een WARNING als dat binnen 7 dagen is. Dat aantal dagen stel je in.
### Opgelost
- De agent-log wordt nu elk uur opgeruimd, en niet alleen als er een agent geweigerd wordt.
- Opruimen van oude meetgegevens gebeurt nu ook als de cron-run van precies :00 is overgeslagen.

## 1.6.0 – 2026-10-19
### Nieuw
- **Versienummers:** `VERSION` en deze changelog. De versie staat in de menubalk, op de login- en setup-pagina, in de REST API (`/v1.php/status`, `api.php?action=ping`) en in de agent.
- **Agentversie per server:** RzMon laat zien welke agentversie elke server draait, en markeert verouderde agents met ⬆, met het commando om bij te werken.
- **Database bijwerken gaat automatisch:** na een update werkt RzMon de tabellen zelf bij, bij het openen van het dashboard of bij de eerstvolgende cron-run. *Setup → Tabellen bijwerken* is niet meer nodig.
- `rzmon-agent.sh --version` en `rzmon-agent-setup --version`.
- `install-rocky.sh` meldt bij een update van welke naar welke versie er is bijgewerkt.
- `tools/bump-version.sh` en `tools/check-version.sh`, plus een GitHub-controle die alle versienummers gelijk houdt.

## 1.5.0 – 2026-10-19
### Nieuw
- **Verbindingstest voor de agent:** `sudo rzmon-agent-setup --test` controleert stap voor stap de planning, het adres, DNS, het netwerk, of daar RzMon draait, het token en een proefrapport.
- **Live agentstatus** in *Agent installeren*: laatste rapport en IP, en geweigerde pogingen met reden.
### Opgelost
- Met de hand gestart las de agent zijn instellingen niet in.
- Het installatiescript sloeg cron over als `/etc/cron.d` al bestond zonder cron-dienst.

## 1.4.0 – 2026-10-19
### Nieuw
- **Agent installeren met één commando** (`agent/install-agent.sh`). RzMon biedt het script zelf aan. Het gebruikt een systemd-timer of cron, stelt services voor en heeft `--update` en `--uninstall`.
- **Dashboard met server-tegels:** algehele status per server; klik klapt uit naar de checks.

## 1.3.0 – 2026-10-19
### Nieuw
- **Storingen bevestigen:** in het dashboard, via een link in Pushover, of in de Pushover-app (Emergency). Daarna komen er geen meldingen meer tot herstel.

## 1.2.1 – 2026-10-19
### Opgelost
- `install-rocky.sh --repo ~/rzmon`: installeren vanaf een lokale clone, bijvoorbeeld van een privé-repository.

## 1.2.0 – 2026-10-19
### Nieuw
- **Onderhoudsmodus:** voor alles, per server of per check.
- **Afhankelijkheden:** één melding als een router of switch onbereikbaar is, in plaats van één per server erachter.
- **Home Assistant-webhook** als extra meldkanaal.

## 1.1.0 – 2026-10-19
### Nieuw
- **Rocky Linux 10:** installatiehandleiding en `install/install-rocky.sh`.
### Opgelost
- REST API achter Apache met PHP-FPM gaf altijd 401 (`CGIPassAuth`).
- REST API achter Nginx gaf bij `/v1.php/checks/3` de status terug.
- Na de database-setup zonder beheerder kwam je op een loginscherm zonder uitweg.
- Cron kon blijven crashen door een lockbestand van root.

## 1.0.0 – 2026-10-19
Eerste versie:
- **Checks:** ping, TCP, HTTP(S) met SSL-controle, diskruimte, services, CPU en RAM.
- **Meldingen** via Pushover.
- **Interface:** dashboard, grafieken, meldingenlog, setup-pagina en een REST API met API-sleutels.
- **Toegang en extra's:** login zonder wachtwoord op je LAN, poortscan.
- **Agents:** Linux-agent en Home Assistant-add-on.
