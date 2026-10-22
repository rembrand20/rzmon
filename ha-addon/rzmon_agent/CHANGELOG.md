# Changelog

Deze add-on heeft hetzelfde versienummer als RzMon. Het volledige overzicht staat in de [CHANGELOG van RzMon](https://github.com/rembrand20/rzmon/blob/main/CHANGELOG.md).

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
