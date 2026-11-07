# Logo en icoon van Rz//Sense AI

Sinds 1.214.0 heet RzMon **Rz//Sense AI** (kort: RzSense), met de slogan *Watching your servers, so you don't have to.* De technische naam blijft `rzmon` (map, database, service, add-on); daarom heten de bestanden hier ook nog `rzmon-…`.

| Bestand | Waarvoor |
|---|---|
| `rzmon-logo.svg` | icoon + woordmerk, donkere letters (lichte achtergrond, README) |
| `rzmon-logo-dark.svg` | icoon + woordmerk, lichte letters (donkere achtergrond) |
| `rzmon-icon.svg` | icoon: schild met "Rz" en signaalbogen op een donkere tegel |
| `pushover-icon-72.png` | icoon voor je Pushover-applicatie (pushover.net → *Your Applications* → *Edit* → *Icon*) |

Elders in de repository:
- `public/favicon.svg` (klein icoon: één boog, dikkere lijnen), `public/favicon.ico`, `public/apple-touch-icon.png`, `public/assets/icons/` (tabblad, beginscherm, `manifest.webmanifest`). Niet in `public/icons/`: die map is in Apache al van Apache zelf (`Alias /icons/`);
- `ha-addon/rzmon_agent/icon.png` (128×128) en `logo.png` (250×100);
- in de pagina's staat het logo inline via `Brand::logo()` (`src/Brand.php`); de kleuren van de letters staan in `app.css` (`--rz-name`, `--rz-acc`, `--rz-slogan`).

**Tegel:** verloop `#1c3170` → `#0e1838`, rand en schild `#2f6bff` → `#22e3ff`, bogen `#22e3ff`. De tegel is in het lichte en het donkere thema hetzelfde.
**Woordmerk:** Orbitron (SIL Open Font License, `public/assets/fonts/OFL.txt`), "//" en "AI" oranje: `#f0a429` op donker, `#b45309` op licht. Naam `#e6ecf7` op donker, `#0b1120` op licht.
**Letters in het icoon:** "Rz" is een pad (Orbitron ExtraBold), zodat het icoon zonder lettertype werkt.
