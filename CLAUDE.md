# RzMon – afspraken voor wijzigingen

RzMon is een monitoringtool in PHP 8 en MariaDB, met een Linux-agent (bash) en een Home Assistant-add-on.
Documentatie en interface zijn in het **Nederlands**.

## Versienummers: bij elke wijziging bijwerken
RzMon en de agent hebben **elk een eigen versienummer**. Verhoog alleen het nummer van wat je veranderd hebt (allebei als je allebei verandert):

1. Kies de soort wijziging en verhoog het nummer:
   - `tools/bump-version.sh rzmon|agent patch`: alleen reparaties;
   - `tools/bump-version.sh rzmon|agent minor`: nieuwe functie;
   - `tools/bump-version.sh rzmon|agent major`: de gebruiker moet zelf iets aanpassen.
2. Vul de nieuwe sectie in, in begrijpelijk Nederlands:
   - RzMon: bovenaan `CHANGELOG.md` (`### Nieuw` / `### Opgelost`);
   - agent: bovenaan `agent/CHANGELOG.md`, daarna `tools/bump-version.sh sync` (kopieert de agent en zijn changelog naar de add-on).
3. Controleer met `tools/check-version.sh`; dat moet overal ✔ geven. GitHub Actions voert dezelfde controle uit.

- `rzmon` werkt `VERSION` bij. `agent` werkt `agent/VERSION`, `AGENT_VERSION` in `agent/rzmon-agent.sh`, de agent-kopie in `ha-addon/rzmon_agent/` en `version` in `ha-addon/rzmon_agent/config.yaml` in één keer bij. Pas die nooit los aan.
- De HA-add-on heeft het nummer van de agent. RzMon vergelijkt agents met `AGENT_LATEST` (uit `agent/VERSION`), niet met de versie van RzMon.
- Heeft iets in RzMon een nieuwere agent nodig, zet dat in `CHANGELOG.md` ("agent 1.x.y nodig").
- Een nieuwe agent krijgt ook een nieuwe **patch-versie van RzMon** (met een regel "Nieuwe agent 1.x.y" in `CHANGELOG.md`). RzMon levert de agent aan de servers en zoekt updates alleen op basis van `VERSION`; zonder RzMon-versie komt de nieuwe agent nooit bij de gebruiker.

## Updates veranderen geen instellingen
- Een update van RzMon laat `config.php` en bestaande rijen in `settings` ongemoeid: `Installer::runSchema` zet elke bestaande instelling terug die het schema of een migratie zou veranderen. Nieuwe standaardwaarden gaan met `INSERT IGNORE`.
- `install-agent.sh` schrijft `/etc/default/rzmon-agent` alleen bij een nieuwe installatie. Bij een bestaande installatie past het alleen de regels aan die je zelf meegeeft (`--url`, `--pair` …). Uitzondering (agent 1.60.0, op verzoek): `--update` en `--cleanup` maken eerst een reservekopie en halen dan alleen regels weg die niet meer nodig zijn (`cleanup_conf`); wat de gebruiker zelf instelde blijft.

## Teksten in de interface (Nederlands en Engels)
- Schrijf teksten in het **Nederlands** in de code, via `t('…')` / `th('…')` (PHP, `th` = met HTML-escaping) of `L('…')` (JavaScript). Variabele delen als `{naam}`: `L('{n} meldingen', { n })`.
- Zet de Engelse vertaling in `lang/en.php` (Nederlands => Engels). Ontbreekt die, dan blijft de tekst Nederlands.
- `php tools/check-i18n.php --list` toont teksten zonder vertaling; `tools/check-version.sh` meldt het aantal.
- Datums en getallen in JavaScript met `LOCALE` (niet `'nl-NL'`), en decimalen met `dec()`.

## Overige afspraken
- `ha-addon/rzmon_agent/rzmon-agent.sh` en `ha-addon/rzmon_agent/CHANGELOG.md` zijn kopieën van `agent/rzmon-agent.sh` en `agent/CHANGELOG.md`. Wijzig altijd het origineel en kopieer daarna met `tools/bump-version.sh sync`.
- Nieuwe databasekolommen gaan via `Installer::COLUMNS` (`src/Installer.php`), niet met `ADD COLUMN IF NOT EXISTS` (dat werkt niet op MySQL). Nieuwe tabellen gaan in `install/schema.sql` met `CREATE TABLE IF NOT EXISTS`. De database wordt automatisch bijgewerkt zodra `VERSION` (van RzMon) verandert; een wijziging aan de database vraagt dus altijd een nieuwe RzMon-versie.
- `config.php` bevat wachtwoorden en wordt nooit gecommit (`.gitignore`).
- Controleer voor een commit:
  - `php -l` op de PHP-bestanden;
  - `node --check public/assets/*.js`;
  - `shellcheck -S warning` op de shellscripts.
