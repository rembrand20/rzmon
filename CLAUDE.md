# RzMon – afspraken voor wijzigingen

RzMon is een monitoringtool in PHP 8 en MariaDB, met een Linux-agent (bash) en een Home Assistant-add-on.
Documentatie en interface zijn in het **Nederlands**.

## Versienummer: bij elke wijziging bijwerken
Elke wijziging die naar `main` gaat, krijgt een nieuw versienummer en een changelog-regel:

1. Kies de soort wijziging en verhoog het nummer:
   - `tools/bump-version.sh patch`: alleen reparaties;
   - `tools/bump-version.sh minor`: nieuwe functie;
   - `tools/bump-version.sh major`: de gebruiker moet zelf iets aanpassen.
2. Vul de nieuwe sectie bovenaan `CHANGELOG.md` in (`### Nieuw` / `### Opgelost`), in begrijpelijk Nederlands.
3. Controleer met `tools/check-version.sh`; dat moet overal ✔ geven. GitHub Actions voert dezelfde controle uit.

`bump-version.sh` werkt `VERSION`, `AGENT_VERSION` in `agent/rzmon-agent.sh`, de agent-kopie in `ha-addon/rzmon_agent/` en `version` in `ha-addon/rzmon_agent/config.yaml` in één keer bij. Pas die nooit los aan.

## Overige afspraken
- `ha-addon/rzmon_agent/rzmon-agent.sh` is een kopie van `agent/rzmon-agent.sh`. Wijzig altijd het origineel en kopieer daarna; `bump-version.sh` doet dat ook.
- Nieuwe databasekolommen gaan via `Installer::COLUMNS` (`src/Installer.php`), niet met `ADD COLUMN IF NOT EXISTS` (dat werkt niet op MySQL). Nieuwe tabellen gaan in `install/schema.sql` met `CREATE TABLE IF NOT EXISTS`. De database wordt automatisch bijgewerkt zodra `VERSION` verandert.
- `config.php` bevat wachtwoorden en wordt nooit gecommit (`.gitignore`).
- Controleer voor een commit:
  - `php -l` op de PHP-bestanden;
  - `node --check public/assets/*.js`;
  - `shellcheck -S warning` op de shellscripts.
