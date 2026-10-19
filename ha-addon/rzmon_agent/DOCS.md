# RzMon Agent

Laat [RzMon](https://github.com/JOUW-NAAM/rzmon) je Home Assistant-systeem bewaken, met Pushover-meldingen als er iets misgaat.

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

Valt Home Assistant helemaal uit, dan stopt de agent ook met rapporteren. RzMon zet de checks dan na een paar minuten op **CRITICAL**: precies het moment waarop je een melding wilt die *niet* via Home Assistant zelf loopt.

## Installatie

1. **In RzMon:** voeg een server toe met type **Remote met agent** en als adres het IP van je Home Assistant (bv. `192.168.1.20`). Kopieer het **token** uit het venster *Agent installeren*.
2. **In Home Assistant:** zet deze add-on in de map `/addons` (zie hieronder), ga naar *Instellingen → Add-ons → Add-on winkel*, klik rechtsboven op ⋮ → **Controleren op updates** en installeer **RzMon Agent** onder *Lokale add-ons*.
3. Tabblad **Configuratie**: vul `monitor_url` (bv. `http://192.168.1.10`) en `token` in en sla op.
4. **Start** de add-on. Het logboek moet `Verbonden met RzMon ✔` tonen.
5. **In RzMon:** klik bij de server op **＋** en voeg checks toe. Bij *Diskruimte* en *Service* kun je kiezen uit wat de agent meldt.

### Add-on in `/addons` zetten

- Met de **Samba share** add-on: kopieer de map `rzmon_agent` naar de share `addons`.
- Met de **Terminal & SSH** of **Advanced SSH** add-on: `scp -r rzmon_agent root@homeassistant.local:/addons/`

## Opties

| Optie | Uitleg |
|---|---|
| `monitor_url` | Adres van RzMon, zonder `/api.php` |
| `token` | Agent-token van deze server in RzMon |
| `interval` | Seconden tussen rapportages (30-3600, standaard 60) |
| `verify_ssl` | Uitzetten bij een self-signed certificaat |

## Tips

- Zet in RzMon ook een **ping-check** en een **TCP-check op poort 8123** op dezelfde server. Die werken onafhankelijk van de agent.
- Een add-on die je bewust uit hebt staan geeft alleen een melding als je er een check voor aanmaakt.
- Rechten: de add-on gebruikt de Supervisor-rol `default` (alleen lezen) en host-netwerk (voor de poortlijst). Hij kan niets aan je installatie veranderen.
