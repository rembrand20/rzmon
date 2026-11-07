# Beveiliging van RzSense

*English: please report security issues privately via GitHub → Security → "Report a vulnerability". Do not open a public issue.*

## Een lek melden
- Meld het **privé** via GitHub: tabblad **Security → Report a vulnerability** van deze repository. Maak er geen gewoon issue van: dat kan iedereen lezen.
- Zet erin: welke versie (RzSense en/of agent), wat je deed, wat er gebeurde, en zo mogelijk hoe het te herhalen is.
- Je krijgt binnen een paar dagen antwoord. Een reparatie komt als nieuwe versie; in `CHANGELOG.md` staat dan onder *Opgelost* wat er gerepareerd is (zonder details die anderen helpen het lek te misbruiken, tot de meeste installaties bijgewerkt zijn).

## Ondersteunde versies
Alleen de **nieuwste versie** van RzSense en van de agent krijgt reparaties. RzSense kan zichzelf en de agents bijwerken (*Instellingen → Updates*, en *Agents bijwerken*).

## Threat model (op één pagina)

**Wat er te beschermen is**
- De beheeromgeving van RzSense (wie daar binnen is, kan checks, herstelacties en pakketupdates op je servers laten uitvoeren).
- De servers met een agent (de agent draait meestal als root).
- Geheimen: wachtwoorden, 2FA-sleutels, tokens van meldkanalen en diensten, de sleutels van de agents, back-ups.

**Grenzen en aanvallers**

| Grens | Mogelijke aanvaller | Maatregelen |
|---|---|---|
| Browser ↔ RzSense | iemand op internet of het netwerk | https (ook alleen-https met HSTS), login met afremmen en blokkeren, 2FA en passkeys, wachtwoorden met Argon2id, CSRF-token, sessie-cookies `HttpOnly` + `SameSite`, Content-Security-Policy met nonce, verbergen van buiten (alleen toegestane apparaten), IP-blokkades |
| Agent → RzSense | iemand die meekijkt of zich voordoet als agent | alleen uitgaand vanaf de server; https met vastgepind certificaat per adres; versleuteld bericht (AES-256 + HMAC) met tijd en eenmalige nonce (geen replay); eigen sleutel per agent, eens per zoveel dagen vernieuwd; koppelen met een eenmalige code (15 min); optioneel IP-slot |
| RzSense → agent (opdrachten) | een overgenomen RzSense | root-commando's alleen als dat per server aan staat, en op de server altijd te blokkeren (`COMMANDS=0`); vaste taken (geen vrije shell) en gecontroleerde pakketnamen; alles in het logboek |
| RzSense → agent (updates) | een overgenomen RzSense of update-adres | agent en installatiescript zijn **ondertekend** (Ed25519, zie hieronder): een agent installeert alleen een update met een geldige handtekening van de ontwikkelaar, ook als RzSense zelf is overgenomen |
| RzSense → het netwerk (checks) | misbruik van checks om de cloud-metadata of andere niet-routeerbare adressen te bereiken (SSRF) | HTTP-checks: alleen `http://` en `https://`, geen link-local/metadata-adressen (169.254.0.0/16, fe80::/10, multicast), ook niet via een redirect (RzSense volgt redirects zelf en controleert elke stap); TLS-controle staat standaard aan. Interne adressen (bv. 192.168.x.x) mogen wél: daar is een monitor voor. Wie checks mag aanmaken (rol *gebruiker*), kan dus vanaf RzSense interne webadressen opvragen. |
| Back-ups | wie een back-upbestand in handen krijgt | back-ups optioneel versleuteld met een wachtwoord; `config.php` (met de databasegegevens) staat nooit in git |

**Wat buiten dit model valt**
- Een aanvaller die al **root is op de RzSense-server** of op een server met een agent: die kan alles wat RzSense of de agent kan.
- Een gelekt wachtwoord **zonder** 2FA. Zet 2FA aan (of een passkey) voor elk account dat van buiten mag inloggen.
- Fouten in de software waar RzSense op draait (PHP, MariaDB, webserver, OpenSSL). Houd die bij met de updates van je distributie; RzSense meldt bekende lekken op je servers op de pagina *Kwetsbaarheden*.

## Ondertekende agent-updates

De agent (`agent/rzmon-agent.sh`) en het installatiescript (`agent/install-agent.sh`) worden bij elke wijziging in GitHub Actions ondertekend (workflow *Agent ondertekenen*). De handtekeningen staan ernaast als `.sig` (base64).

**Publieke sleutel** (Ed25519, ook ingebouwd in de agent als `AGENT_SIGN_PUB`):
```
-----BEGIN PUBLIC KEY-----
MCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM=
-----END PUBLIC KEY-----
```
Vingerafdruk (SHA-256 van de sleutel): `122e6958703d74842a8de0e8d5338abe2e427c1099d714cae130918b0354e239`

**Zelf controleren** (OpenSSL 3.0 of nieuwer):
```bash
printf -- '-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAKayiZIruYIYwZQ97qndO4lmnMjp/EEnVDNBIhZ07sSM=\n-----END PUBLIC KEY-----\n' > rzmon-sign.pub
base64 -d rzmon-agent.sh.sig > rzmon-agent.sh.sig.bin
openssl pkeyutl -verify -pubin -inkey rzmon-sign.pub -rawin -in rzmon-agent.sh -sigfile rzmon-agent.sh.sig.bin
# "Signature Verified Successfully" = ongewijzigd en van de ontwikkelaar
```
Let op: een geïnstalleerde agent heeft zijn eigen download-sleutel in de regel `DL_KEY='…'`. Zet die regel eerst terug naar `DL_KEY=''` voordat je controleert (de agent doet dat zelf ook).

**De privésleutel** staat **alleen** als geheim `AGENT_SIGN_KEY` in GitHub (*Settings → Secrets and variables → Actions*), nooit in de repository of ergens anders online. De workflow controleert na het ondertekenen dat de handtekening klopt met de publieke sleutel in de agent.

**Sleutel vervangen** (bijvoorbeeld als de privésleutel gelekt is):
1. Maak een nieuw sleutelpaar: `openssl genpkey -algorithm ed25519 -out sign.key` en `openssl pkey -in sign.key -pubout`.
2. Zet de nieuwe publieke sleutel in `AGENT_SIGN_PUB` in `agent/rzmon-agent.sh` én `agent/install-agent.sh`, verhoog de agentversie en de versie van RzSense.
3. Vervang het geheim `AGENT_SIGN_KEY` in GitHub door de nieuwe privésleutel, en verwijder `sign.key` daarna overal.
4. Agents met de oude sleutel vertrouwen de nieuwe handtekening niet en werken zichzelf dus niet bij. Installeer ze één keer opnieuw met het installatiecommando uit de Agent Manager: dat haalt het nieuwe installatiescript (met de nieuwe sleutel) via https bij RzSense, en dat controleert de nieuwe agent. Zet bij die herinstallatie alleen in als je RzSense zelf vertrouwt.

## Controles in GitHub Actions
- **Tests**: PHP-tests (met een echte MariaDB), de agent op zes Linux-versies, en een rondje in de browser.
- **Beveiliging**: PHPStan (niveau 4; bestaande meldingen in `phpstan-baseline.neon`), een controle op geheimen in de repository (`tools/check-secrets.sh`) en op inline scripts die de Content-Security-Policy zouden breken.
- **Versiecontrole**: versienummers en changelogs kloppen overal.

## Licentie
RzSense valt onder de **GNU General Public License v3.0** (zie `LICENSE`).
