# RzMon Agent

Laat [RzMon](https://github.com/rembrand20/rzmon) je Home Assistant-systeem bewaken, met Pushover-meldingen als er iets misgaat.

## Wat wordt er gerapporteerd?

| In RzMon | Wat |
|---|---|
| **CPU** | CPU-gebruik van de host (gemeten over 2 sec) + load average |
| **RAM** | Geheugengebruik van de host + swap |
| **Disk `/data`** | De data-partitie van Home Assistant OS (configuratie, database, back-ups, media) |
| **Service `homeassistant`** | Draait Home Assistant Core en reageert de API? |
| **Service `supervisor`** | Is de Supervisor *healthy*? |
| **Service `addon:<slug>`** | Draait een add-on? (bv. `addon:core_mosquitto`, `addon:a0d7b954_nodered`) |
| **Service `updates`** | WARNING als er een update is voor Core, Supervisor, OS of een add-on |
| **Poortscan** | Luisterende poorten van de host (zonder procesnamen) |
| **SSL-certificaat** | Vervaldatum van de certificaten uit de optie `certs`, bv. `/ssl/fullchain.pem` of `mijndomein.duckdns.org:443` |
| **Home Assistant → Laatste back-up** | Dagen sinds de laatste geslaagde automatische back-up; WARNING als de laatste poging mislukt is (HA 2025.2+, automatische back-ups aan) |
| **Home Assistant → Integraties** | Integraties die niet geladen zijn, met hun namen |
| **Home Assistant → Systeemstatus** | Reparaties van de Supervisor: niet gezond, niet ondersteund, of een melding zoals weinig vrije ruimte |
| **Home Assistant → Onverwachte herstart** | CRITICAL (met melding) als HA OS opnieuw opstart zonder OS-update |
| **Home Assistant → Levensduur SD-kaart / eMMC** | Slijtage van de opslag in %, als de hardware dat meldt |
| **Home Assistant → OS-update mislukt** | CRITICAL als HA OS na een update is teruggevallen op de vorige versie |
| **Home Assistant → USB-apparaat** | CRITICAL als je Zigbee-/Z-Wave-stick of Bluetooth-adapter weg is |
| **Containers** | Core, Supervisor, plugins en add-ons: draait hij, CPU, RAM, gecrasht of herstart door de watchdog |
| **Logbestand** | Zoeken in de logs van HA OS (`ha:host`), Core, de Supervisor en add-ons |

**⚡ Realtime** (aan/uit in RzMon, *Agent Manager*): de add-on kijkt dan elke 5 seconden of er iets verandert aan de add-ons, Core of de boot-tijd, en rapporteert meteen.

De add-on gebruikt de Supervisor-rol **manager** om de statistieken, logs en hardware te kunnen lezen. Hij verandert niets.

Valt Home Assistant helemaal uit, dan stopt de agent ook met rapporteren. RzMon zet de checks dan na een paar minuten op **CRITICAL**: precies het moment waarop je een melding wilt die *niet* via Home Assistant zelf loopt.

## Installatie

1. **In RzMon:** voeg een server toe met type **Remote met agent** en als adres het IP van je Home Assistant (bv. `192.168.1.20`). Klik in het venster *Agent Manager* op **🏠 Home Assistant OS → 🔗 Koppelcode maken** (eenmalig, 15 minuten geldig).
2. **In Home Assistant:** zet deze add-on in de map `/addons` (zie hieronder), ga naar *Instellingen → Add-ons → Add-on winkel*, klik rechtsboven op ⋮ → **Controleren op updates** en installeer **RzMon Agent** onder *Lokale add-ons*.
3. Tabblad **Configuratie**: vul `monitor_url` (bv. `http://192.168.1.10`) en `pair_code` in en sla op. De add-on haalt daarmee zijn eigen sleutel op (in het logboek: *Gekoppeld met RzMon*); daarna is alles versleuteld en ondertekend.
4. **Start** de add-on. Het logboek moet `Verbonden met RzMon ✔` tonen.
5. **In RzMon:** klik bij de server op **＋** en voeg checks toe. Bij *Diskruimte* en *Service* kun je kiezen uit wat de agent meldt.

### Add-on in `/addons` zetten

- Met de **Samba share** add-on: kopieer de map `rzmon_agent` naar de share `addons`.
- Met de **Terminal & SSH** of **Advanced SSH** add-on: `scp -r rzmon_agent root@homeassistant.local:/addons/`

## Opties

| Optie | Uitleg |
|---|---|
| `monitor_url` | Adres van RzMon, zonder `/api.php` |
| `monitor_url_fallback` | Reserve-adres als `monitor_url` niet bereikbaar is (agent 1.51.0), bv. `monitor_url` = `https://rzmon.example.nl` en reserve `http://192.168.1.10`. RzMon kan het adres ook zelf wijzigen (*Beveiligde verbinding → 🌐*); dat staat dan in `/data/rzmon-keys/url` |
| `pair_code` | Koppelcode van deze server in RzMon (Agent Manager → 🔗 Koppelcode) |
| `pin` | Alleen bij een eigen (self-signed) certificaat van RzMon: de vingerafdruk (staat bij de koppelcode) |
| `token` | Oude manier: agent-token, alleen zonder koppelcode |
| `interval` | Alleen voor oudere RzMon-versies. Vanaf RzMon 1.41.0 kies je in RzMon hoe vaak de add-on rapporteert (*Agent Manager → Rapporteren elke …*; standaard 60 s) |
| `verify_ssl` | Uitzetten bij een self-signed certificaat |
| `certs` | Certificaten waarvan RzMon de vervaldatum bewaakt: bestanden in `/ssl` (standaard `/ssl/fullchain.pem`, van de Let's Encrypt- of DuckDNS-add-on) en/of `host:poort`, ook op internet, bv. `mijndomein.duckdns.org:443`. Kies ze in RzMon bij een check van het type *SSL-certificaat* |

## Tips

- Zet in RzMon ook een **ping-check** en een **TCP-check op poort 8123** op dezelfde server. Die werken onafhankelijk van de agent.
- Een add-on die je bewust uit hebt staan geeft alleen een melding als je er een check voor aanmaakt.
- Rechten: de add-on gebruikt de Supervisor-rol `default` (alleen lezen) en host-netwerk (voor de poortlijst). Hij kan niets aan je installatie veranderen.
