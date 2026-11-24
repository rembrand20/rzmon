# Windows-agent

Wat staat hier: hoe je een Windows-server door RzSense laat bewaken met de Windows-agent. Je leest hoe je hem op één
server installeert of naar veel servers tegelijk uitrolt, wat hij meet en meldt, hoe IDS, blokkeren en Windows Update
werken, en wat je op de server zelf kunt doen.

## Wat de agent doet

De Windows-agent geeft een Windows-server dezelfde bewaking als een Linux-server met de
[Linux-agent](05-linux-agent.md). Het is één programma, `rzsense-agent.exe`, dat als Windows-service **RzSense Agent**
draait. Hij werkt op **Windows Server 2019, 2022 en 2025** (64-bit).

De agent stuurt hetzelfde soort rapport als de Linux-agent. Checks, dashboard, meldingen en de Agent Manager werken dus
zoals je gewend bent. De Windows-agent heeft een eigen versienummer; de Agent Manager vergelijkt hem met de nieuwste
Windows-agent.

## Installeren op één server

Voor één of een paar servers gebruik je een **koppelcode**. Dat is de veiligste manier: de code werkt één keer en is
15 minuten geldig.

1. Voeg in RzSense de server toe met het type *Remote met agent*, of open een bestaande server.
2. Ga naar *Agent Manager → Installeren of opnieuw koppelen → ⊞ Windows Server* en klik op **🔗 Koppelcode maken**.
3. Open op de Windows-server **PowerShell als beheerder** en plak de twee regels die RzSense toont:
   - de eerste haalt de agent op bij je eigen RzSense, met `curl.exe` (dat zit in Windows Server 2019 en nieuwer).
     Heeft RzSense een eigen certificaat, dan wordt dat vastgezet;
   - de tweede installeert de agent, koppelt hem en start de service:
   ```powershell
   & "$env:TEMP\rzsense-agent.exe" install --url "https://rzsense.voorbeeld" --pair "CODE"
   ```
4. Binnen een minuut staat de server in RzSense. Voeg checks toe met **＋**, bijvoorbeeld:
   - *Service*: `W3SVC`, `MSSQLSERVER` of `MSSQL$SQLEXPRESS`;
   - *Diskruimte*: `C:`;
   - CPU, RAM, *Proces* (bv. `sqlservr.exe`) en *Herstart*.

De agent kopieert zichzelf naar `C:\Program Files\RzSense`. Zijn instellingen staan in `C:\ProgramData\RzSense`.

**Opties bij `install`:**

| Optie | Betekenis |
| --- | --- |
| `--url` | Adres van RzSense (verplicht) |
| `--pair CODE` | Koppelcode, voor één server |
| `--enroll CODE` | Uitrolcode, voor veel servers (zie hieronder) |
| `--pin sha256//…` | Vingerafdruk van het certificaat van RzSense, bij een eigen (self-signed) certificaat |
| `--fallback URL` | Reserve-adres van RzSense, bv. het LAN-adres |
| `--no-remote-update` | Niet bijwerken vanuit RzSense |

**Windows Defender of SmartScreen:** de `.exe` heeft geen Windows-handtekening (Authenticode). Installeer je met de
PowerShell-regels, dan vraagt Windows meestal niets. Download je hem met een browser, dan kan SmartScreen één keer vragen
of je hem wilt starten.

## Uitrollen naar veel servers

Voor veel servers tegelijk (groepsbeleid, Intune, SCCM, PDQ …) gebruik je een **uitrolcode**. Servers melden zich daarmee
zelf aan bij RzSense.

### Een uitrolcode maken

1. Ga naar *Agent Manager → Installeren of opnieuw koppelen → ⊞ Windows Server* en klik op **Uitrolcode en MSI**.
2. Vul in:
   - **Naam**, bv. *Servers kantoor*;
   - **Hoeveel servers** zich met deze code mogen aanmelden;
   - **Dagen geldig** (hooguit 90);
   - **Alleen vanaf**: je eigen netwerken, bv. `10.0.0.0/8` (leeg = overal vandaan);
   - **Groep in RzSense** (optioneel).
3. Klik op **🔑 Uitrolcode maken** en kopieer de code meteen. Je ziet hem maar één keer.

Een server die zich aanmeldt, komt in RzSense met zijn **computernaam** (en de groep die je opgaf). Hij krijgt meteen zijn
eigen sleutel, net als na een koppelcode. Elke aanmelding staat in het logboek en geeft de melding
*Nieuwe server aangemeld*.

### Met de MSI

In hetzelfde venster download je `rzsense-agent.msi`. De MSI installeert de agent in `C:\Program Files\RzSense` en meldt de
server aan:

```
msiexec /i "\\domein\NETLOGON\RzSense\rzsense-agent.msi" /qn URL="https://rzsense.voorbeeld" ENROLL="CODE" PIN="sha256//…"
```

`PIN` is alleen nodig bij een eigen certificaat van RzSense.

1. Zet de MSI in een gedeelde map die de computers kunnen lezen, bv. `\\domein\NETLOGON\RzSense`.
2. Software-installatie in groepsbeleid kan geen eigenschappen meegeven. Gebruik daarom een **opstartscript**
   (*Computerconfiguratie → Beleid → Windows-instellingen → Scripts → Opstarten*) met de regel hierboven, of je eigen
   beheerprogramma.

Goed om te weten:
- Staat de agent er al, dan werkt de MSI hem alleen bij en gebruikt hij de code niet.
- De uitrolcode komt niet in de logboeken van Windows Installer.
- Verwijderen via *Programma's en onderdelen* (of `msiexec /x`) verwijdert ook de service. De instellingen in
  `C:\ProgramData\RzSense` blijven staan; `rzsense-agent.exe uninstall --purge` haalt ze weg.

Zonder MSI kan het ook met de `.exe`:

```powershell
rzsense-agent.exe install --url "https://rzsense.voorbeeld" --enroll "CODE"
```

### Veilig uitrollen

- **Een uitrolcode is een geheim.** Wie hem heeft, kan servers aanmelden, tot het maximum. Iedereen in het domein kan een
  opstartscript lezen, dus ook de code.
- Beperk de code daarom tot **je eigen netwerken** en het aantal servers dat je uitrolt.
- **Trek hem in** zodra de uitrol klaar is (in het venster *Uitrolcode en MSI*). Servers die al aangemeld zijn, blijven
  werken.
- **Een bestaande servernaam wordt nooit overgenomen.** Staat er al een server met die naam, dan wordt de aanmelding
  geweigerd. Koppel die server met een koppelcode. Zo kan niemand met de code een bestaande server "kapen".
- RzSense bewaart de code zelf niet, alleen een sleutel die eruit is afgeleid.

## Wat de agent meet en meldt

| Onderdeel | Op Windows |
| --- | --- |
| CPU | Gebruik over 2 seconden en het aantal kernen. Windows kent geen *load* |
| Geheugen | Totaal en beschikbaar; het wisselbestand telt als swap |
| Schijven | Elke vaste schijf (`C:`, `D:` …) |
| Herstart | Opstarttijd. De check *Herstart* geeft ook een **warning** als Windows op een herstart wacht (na updates of een installatie) |
| Services | Status van de bewaakte Windows-services (*active*, *inactive*, *not-found* …), en de lijst om uit te kiezen |
| Processen | Proces-checks (aantal), en de lijst om uit te kiezen |
| Poorten | Luisterende TCP-poorten (IPv4 en IPv6) met het proces |
| Windows-versie | Naam, versie en build, bv. *Windows Server 2022 Standard*, build 20348 |
| Logins | Gelukte console- en RDP-logins, en mislukte logins (console, RDP en via het netwerk, bv. SMB), per IP-adres opgeteld |
| Certificaten | Via `host:poort` vanaf de server zelf, of uit de certificaatopslag van de computer |
| Windows Update | Hoeveel updates er klaarstaan, hoeveel daarvan kritiek of belangrijk, met de lijst |
| Microsoft Defender | Of de realtime-bescherming aan staat en hoe oud de definities zijn |

**Logins** zie je op de pagina Meldingen, zoals SSH-logins bij Linux. Computeraccounts (`naam$`) en systeemaccounts slaat
de agent over.

**Certificaten:** maak een SSL-check met als doel `host:poort`, of kies een certificaat uit de opslag van de computer
(*Persoonlijk*, *Webhosting*, *Extern bureaublad*). Zo'n certificaat heet `cert:LocalMachine\My\<vingerafdruk>`. Bij
*Opnieuw zoeken* toont RzSense de certificaten die er zijn.

**Windows Update** bevraagt de agent op de achtergrond, eens per 6 uur. Je ziet het in de Agent Manager.

## Logs en logbestanden

### Windows-logboeken

1. Open de *Agent Manager* van de server en ga naar *Logs verzamelen → Gevonden op deze server*.
2. Kies het tabblad **⊞ Windows-logboeken**: System, Application, Security, en wat er verder is (Sysmon, Windows Defender,
   PowerShell, Extern bureaublad, Taakplanner, Windows Update, DNS Server …).
3. Kies met het niveau of je alles wilt, of alleen waarschuwingen of fouten.

Elke regel krijgt de tijd, het niveau, de bron en het gebeurtenisnummer, met de tekst zoals Logboeken hem toont.
Bijvoorbeeld: `ERROR Service Control Manager[7034]: …`. De agent onthoudt per logboek waar hij was: niets komt dubbel, en
lukt versturen niet, dan gaat hij de volgende keer verder op dezelfde plek.

### Een log-check op een logboek

Maak een check *Log* met als doel de naam van het logboek (bv. `System` of `Application`) en een zoekpatroon
(bv. `onverwacht beëindigd`). De check telt de nieuwe treffers en toont de laatste regels.

### Windows-logbestanden

In de *Agent Manager → Logs verzamelen*, tabblad **📄 Windows-logbestanden**, kies je logbestanden van:
- IIS (per site);
- de Windows Firewall (`pfirewall.log`, als logging aan staat);
- SMTP en DNS.

Let op: IIS schrijft zijn tijden in **UTC**.

## IDS en EDR

Zet het IDS aan zoals bij Linux (zie [Beveiliging](08-beveiliging.md)). De Windows-agent doet dan vanzelf mee. Hij leest de
belangrijke beveiligingsgebeurtenissen uit de Windows-logboeken en stuurt alleen wat ertoe doet. RzSense beoordeelt ze en
maakt de incidenten.

| Wat | Bron in Windows | Incident |
| --- | --- | --- |
| Nieuwe service | System 7045 | warning |
| Nieuwe geplande taak | Security 4698 | warning |
| Nieuwe of aangezette gebruiker | Security 4720, 4722 | warning |
| Lid van een groep | Security 4728, 4732, 4756 | warning; **critical** bij een beheerdersgroep (Administrators, Domain Admins, Remote Desktop Users …) |
| Logboek gewist | Security 1102, System 104 | critical |
| Malware gevonden | Defender 1116, 1117 | critical |
| Defender uitgezet of definities te oud | Defender 5001 en de status van Defender | critical; warning bij definities ouder dan 7 dagen |
| Verdacht PowerShell-script (versleuteld, gedownload, Mimikatz, AMSI omzeilen …) | PowerShell 4104 | critical |
| Webserver of database start een shell (bv. `w3wp.exe → cmd.exe`, een webshell) | Sysmon 1 of Security 4688 | critical |
| Brute force | Mislukte logins via RDP of het netwerk (SMB) | warning; blokkeren in de modus *blokkeren* |
| Webaanval | IIS-logs: aanvalspaden (`/.env`, `wp-login.php` …), scanners, veel 404 of 401/403 | warning; blokkeren als *blokkeren* aan staat bij de webdiensten |

### Meer zien met Sysmon en procesaudit

Zonder deze twee ziet de agent **geen nieuwe processen en geen PowerShell-scripts**. Zet ze daarom aan. Open PowerShell
als beheerder in `C:\Program Files\RzSense`:

```powershell
.\rzsense-agent.exe audit
.\rzsense-agent.exe sysmon C:\pad\Sysmon64.exe
```

- `audit` zet *procesaudit met opdrachtregel* (gebeurtenis 4688) en *PowerShell-scriptlogging* (4104) aan.
- `sysmon` installeert **Sysmon** met een ingebouwde, rustige instelling: alleen nieuwe processen, zonder de bekende
  Windows-ruis. Download Sysmon zelf bij Microsoft (Sysinternals); de agent haalt het nooit zelf op. Staat Sysmon er al,
  dan krijgt het alleen de nieuwe instelling.

**In een domein** zet je procesaudit en PowerShell-logging liever met groepsbeleid:
- *Computerconfiguratie → Beleid → Windows-instellingen → Beveiligingsinstellingen → Geavanceerde controlebeleidsconfiguratie
  → Gedetailleerde tracering → Procescreatie controleren*;
- *Beheersjablonen → Windows-onderdelen → Windows PowerShell → Logboekregistratie van PowerShell-scriptblokken
  inschakelen*.

Op een domeincontroller ziet de agent ook nieuwe domeingebruikers en nieuwe leden van *Domain Admins*.

### Webaanvallen in IIS

De agent leest de logs in `C:\inetpub\logs\LogFiles\W3SVC*`. Hij leest alleen nieuwe regels en houdt per site bij waar hij
was. Per IP-adres stuurt hij een samenvatting, net als de reverse proxy op Linux. Alleen het pad gaat mee, nooit de
querystring (daar kunnen wachtwoorden of tokens in staan).

Staat IIS achter een load balancer, dan ziet IIS alleen het adres van de load balancer. Voeg in IIS het logveld
*X-Forwarded-For* toe om het echte adres te zien. RzSense blokkeert je eigen netwerk nooit.

## Blokkeren en isoleren

**Blokkeren** gaat via de **Windows Firewall**, met regels in de groep **RzSense** (inkomend en uitgaand). Haal je een
blokkade weg in RzSense, dan verdwijnt de regel ook.

**Isoleren** zet regels in de groep **RzSense-isolatie**. Alles gaat dicht, behalve:
- RzSense zelf (het gewone en het reserve-adres);
- loopback;
- de netwerken die je toestaat.

Kan de agent het adres van RzSense niet vinden, dan isoleert hij niet; anders zou hij zichzelf buitensluiten. Hef je de
isolatie op in RzSense, dan verdwijnen de regels. In nood haal je ze als beheerder zelf weg:

```powershell
Remove-NetFirewallRule -Group RzSense-isolatie
```

Meer over isoleren lees je in [Beveiliging](08-beveiliging.md).

## Windows Update en kwetsbaarheden

### Updates installeren

Een Windows-server staat op de pagina *Kwetsbaarheden → Updates* (en per server in de Agent Manager) als **⊞ Windows**, met
de klaarstaande updates (KB-nummers) en hoeveel daarvan beveiligingsupdates zijn.

Kies, net als bij Linux:
- **wanneer**: met de hand, in het vaste onderhoudsvenster van de server, of elke dag om een tijd;
- **wat**: alleen beveiligingsupdates (en kritieke updates), of alles.

De agent installeert via de Windows Update-API, op de achtergrond, met een vast script. Licenties van updates worden
geaccepteerd. Daarna zoekt hij meteen opnieuw naar updates.

**De agent herstart nooit.** Vraagt een update om een herstart, dan zie je *↻ herstart nodig*. Herstart de server dan zelf,
op een moment dat het uitkomt.

Gebruik je **WSUS** of **Windows Update for Business**, dan ziet en installeert de agent wat die beschikbaar stellen.

### Kwetsbaarheden

Windows Update zegt per ontbrekende beveiligingsupdate welke CVE's hij oplost. Die staan bij *Kwetsbaarheden*, met het
KB-nummer als pakket en als oplossing, en met de ernst van Microsoft (*Critical*, *Important* …). Daarna volgen CVSS, EPSS
en KEV zoals bij Linux. Is de update geïnstalleerd, dan staan ze bij *opgelost*.

Meer in [Kwetsbaarheden en updates](09-kwetsbaarheden-en-updates.md).

## Adressen, relay en reserve-RzSense

Net als bij de Linux-agent:

- **Reserve-adres** (`--fallback` bij `install`, bv. het LAN-adres van RzSense): de agent gebruikt het als het gewone
  adres niet bereikbaar is.
- **Reserve-RzSense**: heb je een reserve-RzSense ingesteld, dan krijgt de agent dat adres vanzelf. Zijn het gewone adres
  en het reserve-adres allebei onbereikbaar, dan rapporteert hij daarheen. Een antwoord van de reserve verandert geen
  sleutels, adressen of opdrachten. Zie [Toegang en beheer](11-toegang-en-beheer.md).
- **Adres van RzSense wijzigen** (*Agent Manager → Adres van RzSense*): de agent test het nieuwe adres eerst met een
  ondertekend verzoek, en stapt alleen over als het antwoord klopt. De uitkomst zie je in de Agent Manager.
- **Nieuw certificaat:** bij een vastgezet certificaat kan een adres twee vingerafdrukken hebben (`sha256//A;sha256//B`):
  het huidige en een klaargezet nieuw certificaat.
- **Via een relay:** kies in het installatievenster bij *Verbinden via* de relay. De opdracht krijgt dan het adres en de
  vingerafdruk van de relay. Zie [Linux-agent](05-linux-agent.md) voor relays.

## Veiligheid

- Alles tussen agent en RzSense gaat **versleuteld en ondertekend** (AES-256 en HMAC-SHA256), precies zoals bij de
  Linux-agent. Met de koppelcode haalt de agent zijn eigen sleutel op; er gaat geen sleutel leesbaar over de lijn.
- De eigen sleutel staat versleuteld met **DPAPI** (aan deze computer gebonden) in `C:\ProgramData\RzSense\agent.conf`.
  Alleen SYSTEM en Administrators mogen bij dat bestand.
- **Bijwerken** gaat met de knop in de Agent Manager, of vanzelf als dat aan staat. De agent haalt de nieuwe `.exe` bij
  RzSense en gebruikt hem alleen als de **ed25519-handtekening** klopt (dezelfde sleutel als bij de Linux-agent). Daarna
  start Windows de service opnieuw.
- Wil je een server alleen met de hand bijwerken, installeer dan met `--no-remote-update`, of zet `REMOTE_UPDATE=0` in
  `C:\ProgramData\RzSense\agent.conf`.
- De service draait als **LocalSystem** en herstart vanzelf na een fout (na 30, 60 en 120 seconden, via de herstelopties
  van de service). Dat vervangt de bewaker van de Linux-agent.

## Op de server zelf

Open PowerShell als beheerder in `C:\Program Files\RzSense`.

| Opdracht | Wat hij doet |
| --- | --- |
| `rzsense-agent.exe once` | Verstuurt één rapport en zegt wat er misgaat. Met `--show` zie je ook wat er verstuurd wordt |
| `rzsense-agent.exe collect` | Laat zien wat de agent meet, zonder iets te versturen |
| `rzsense-agent.exe status` | Toont de instellingen en de laatste 10 meldingen |
| `rzsense-agent.exe uninstall` | Verwijdert de service; met `--purge` ook de instellingen en de sleutel |
| `rzsense-agent.exe audit` | Zet procesaudit en PowerShell-scriptlogging aan |
| `rzsense-agent.exe sysmon <pad>` | Installeert Sysmon met de instelling van RzSense |
| `rzsense-agent.exe version` | Toont de versie |

**Logboek van de agent:** *Logboeken → Windows-logboeken → Toepassing*, bron **RzSenseAgent**, of het bestand
`C:\ProgramData\RzSense\agent.log` (hooguit 1 MB).

## Beperkingen

- Alleen Windows **Server** 2019, 2022 en 2025, 64-bit.
- Kwetsbaarheden ziet RzSense alleen via Windows Update. Software van anderen (bv. 7-Zip of een browser) ziet hij op
  Windows nog niet.
- Een relay waar RzSense zelf ophaalt (in een DMZ) werkt nog niet voor Windows-servers.
- De `.exe` heeft geen Authenticode-handtekening (zie SmartScreen hierboven). Bijwerken is wel altijd beveiligd met de
  ed25519-handtekening.
