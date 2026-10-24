# Logo en icoon van RzMon

| Bestand | Waarvoor |
|---|---|
| `rzmon-logo.svg` | woordmerk, donkere letters (lichte achtergrond, README) |
| `rzmon-logo-dark.svg` | woordmerk, witte letters (donkere achtergrond) |
| `rzmon-icon.svg` | icoon: oranje schild met de z op een donkere tegel |
| `pushover-icon-72.png` | icoon voor je Pushover-applicatie (pushover.net → *Your Applications* → *Edit* → *Icon*) |

Elders in de repository:
- `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png`, `public/assets/icons/` (tabblad, beginscherm, `manifest.webmanifest`). Niet in `public/icons/`: die map is in Apache al van Apache zelf (`Alias /icons/`);
- `ha-addon/rzmon_agent/icon.png` (128×128) en `logo.png` (250×100);
- in de pagina's staat het logo inline via `Brand::logo()` (`src/Brand.php`), zodat de letters de tekstkleur van het thema krijgen.

**Kleuren:** donker `#151B2E` (in het donkere thema van RzMon is de tegel `#283150`), oranje verloop `#FFCB3D` → `#FF8A00`.
**Letters:** getekend als lijnen van 12 dik met ronde uiteinden (geen lettertype nodig).
