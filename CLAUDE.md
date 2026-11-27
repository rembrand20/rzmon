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
4. Bij een **minor** of **major** van RzMon: werk de **Highlights** bovenaan `CHANGELOG.md` bij (de laatste 5 versies, één zin per versie; de oudste valt eraf).

- `rzmon` werkt `VERSION` bij. `agent` werkt `agent/VERSION`, `AGENT_VERSION` in `agent/src/00-kop.sh` (en daarmee de gebouwde agent), de agent-kopie in `ha-addon/rzmon_agent/` en `version` in `ha-addon/rzmon_agent/config.yaml` in één keer bij. Pas die nooit los aan.
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
- **Documentatie** staat in `docs/` (hoofdstukken, inhoudsopgave in `docs/README.md`); `README.md` is kort en `HANDLEIDING.md` is alleen een wegwijzer voor oude verwijzingen. Werk bij een nieuwe of veranderde functie het juiste hoofdstuk bij, in begrijpelijk Nederlands, met de menupaden zoals de interface ze noemt. Geen versienummers in de documentatie ("vanaf 1.x"): die horen in de CHANGELOG.
- **De interface wijzig je in `public/assets/src/*.js`** (modules, op volgorde van hun nummer), nooit in `public/assets/app.js`: dat bestand wordt gebouwd met `tools/build-js.sh`. `tools/check-version.sh` en CI controleren dat het gebouwde bestand klopt, en dat er geen twee functies, `const` of `let` met dezelfde naam zijn. Een venster (`modal()`) ruimt de afhandelingen en polls van het vorige op: gebruik `addEventListener(…, { signal: modalSignal() })` op `#modal-form`/`#modal-body`, en stop een poll als `modalGen` veranderd is.
- **De API** staat in `public/api.php` (inloggen, rechten, CSRF, de lijst `API_GET` met acties die met GET mogen) en `src/api/*.php`: `functies.php` (hulpfuncties), `agent.php`, `login.php`, en de acties per onderwerp (`10-monitoring.php` … `80-systeem.php`, elk een eigen `switch`). Een nieuwe actie zet je in het bestand van zijn onderwerp; elke actie eindigt met `out()`. Mag hij met GET (alleen lezen), zet hem dan ook in `API_GET`.
- **De agent wijzig je in `agent/src/*.sh`** (modules, op volgorde van hun nummer), nooit in `agent/rzmon-agent.sh`: dat bestand wordt gebouwd met `tools/build-agent.sh` (ook door `tools/bump-version.sh sync`). `tools/check-version.sh` en CI controleren dat het gebouwde bestand klopt. Shellcheck draait per module en per script, naast elkaar: `tools/shellcheck.sh` (ook in CI); het gebouwde bestand krijgt alleen `bash -n`.
- **De Go-agent voor Linux** (1.339.0, proef naast de bash-agent) is dezelfde code als de Windows-agent in `agent-win/`: bestanden op `_linux.go` (het commando `rzsense`: `cli_linux.go`, de metingen: `core_linux.go`) naast die op `_windows.go`. In de proef leest hij de instellingen en de sleutel van de bash-agent alleen (nooit schrijven) en stuurt hij proefrondes naar de actie `agent_shadow` (`src/AgentShadow.php`). De bestanden `agent-win/dist/rzsense-linux-*` bouwt en ondertekent dezelfde GitHub Action; zet ze nooit zelf in git. Test: `tests/agent-go-shadow.sh` (tegen een draaiende RzSense). Het installatiescript is `agent/install-rzsense.sh`. Overnemen per server (1.344.0, `src/AgentTakeover.php`, `agent-win/takeover_linux.go`): alleen na een goede proef (60 rondes, 98% gelijk) en als de server niets gebruikt wat de Go-agent nog niet meet (`LINES_OK`, `CHECKS_BLOCK`); leert de Go-agent iets nieuws, haal het daar dan uit de blokkades. Dan schrijft hij wél in de sleutelmap van de bash-agent (nieuwe sleutel, `url.pins`), zodat teruggaan altijd werkt; `hosts.os` is dan `linux-go`.
- **De Windows-agent** (1.313.0) staat in `agent-win/` (Go, één `.exe`). Eigen versienummer: `tools/bump-version.sh agentwin patch|minor|major` (werkt `agent-win/VERSION` en `Version` in `agent-win/agent.go` bij), changelog in `agent-win/CHANGELOG.md`. De `.exe` in `agent-win/dist/` bouwt en ondertekent GitHub Actions (*Windows-agent bouwen*, met `AGENT_SIGN_KEY`); zet die nooit zelf in git. Controleer voor een commit `gofmt -l`, `go vet ./...` (ook met `GOOS=windows`) en `go test ./...` in `agent-win`. Een nieuwe Windows-agent krijgt ook een patch-versie van RzSense, net als de Linux-agent. RzSense vergelijkt een agent met `agent_latest($os)` (Windows: `AGENT_WIN_LATEST`), niet rechtstreeks met `AGENT_LATEST`.
- `ha-addon/rzmon_agent/rzmon-agent.sh` en `ha-addon/rzmon_agent/CHANGELOG.md` zijn kopieën van `agent/rzmon-agent.sh` en `agent/CHANGELOG.md`. Wijzig altijd het origineel en kopieer daarna met `tools/bump-version.sh sync`.
- Nieuwe databasekolommen gaan via `Installer::COLUMNS` (`src/Installer.php`), niet met `ADD COLUMN IF NOT EXISTS` (dat werkt niet op MySQL). Nieuwe tabellen gaan in `install/schema.sql` met `CREATE TABLE IF NOT EXISTS`. De database wordt automatisch bijgewerkt zodra `VERSION` (van RzMon) verandert; een wijziging aan de database vraagt dus altijd een nieuwe RzMon-versie.
- `config.php` bevat wachtwoorden en wordt nooit gecommit (`.gitignore`). `tools/check-secrets.sh` (ook in CI) zoekt naar geheimen in de repo.
- Geen inline scripts of `onclick=` e.d.: de Content-Security-Policy blokkeert ze. Een eigen inline `<script>` krijgt `nonce="<?= csp_nonce() ?>"`.
- PHPStan draait in CI op niveau 6 met `phpstan-baseline.neon` (de oude meldingen van niveau 6; nieuwe code moet de typen van arrays aangeven, bv. `@param array<string, mixed> $x`). Los je iets uit de baseline op, maak hem dan opnieuw met de workflow *PHPStan-baseline maken* (het log bevat de nieuwe baseline).
- Licentie: GPL-3.0 (`LICENSE`).
- Controleer voor een commit:
  - `php -l` op de PHP-bestanden;
  - `node --check public/assets/*.js`;
  - `tools/shellcheck.sh` (shellcheck per module en script).
