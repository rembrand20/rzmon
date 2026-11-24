# Beveiliging van RzSense

*English: please report security issues privately via GitHub → Security → "Report a vulnerability". Do not open a public issue.*

## Een lek melden

1. Meld het **privé** via GitHub: tabblad **Security → Report a vulnerability** van deze repository. Maak er geen gewoon
   issue van: dat kan iedereen lezen.
2. Zet erin: welke versie (RzSense, Linux-agent of Windows-agent), wat je deed, wat er gebeurde, en zo mogelijk hoe het
   te herhalen is.
3. Je krijgt binnen een paar dagen antwoord. De reparatie komt als nieuwe versie. In `CHANGELOG.md` staat dan onder
   *Opgelost* wat er gerepareerd is, zonder details die helpen het lek te misbruiken tot de meeste installaties
   bijgewerkt zijn.

## Ondersteunde versies

Alleen de **nieuwste versie** van RzSense en van de agents krijgt reparaties. RzSense werkt zichzelf bij via
*Instellingen → Systeem → Bijwerken*, en de agents via RzSense.

## Wat RzSense doet om veilig te zijn

**Wat er te beschermen is:** de beheeromgeving (wie daar binnen is, kan checks, herstelacties en updates op je servers
laten uitvoeren), de servers met een agent (die draait meestal als root), en geheimen zoals wachtwoorden, 2FA-sleutels,
tokens, agentsleutels en back-ups.

| Grens | Aanvaller | Maatregelen |
|---|---|---|
| Browser → RzSense | iemand op internet of je netwerk | https (ook *alleen https* met HSTS); login met afremmen en blokkeren; 2FA en passkeys; wachtwoorden met Argon2id; CSRF-token; sessiecookies `HttpOnly` en `SameSite`; Content-Security-Policy met nonce; van buiten onzichtbaar (een gewone 404, optioneel alleen toegestane apparaten); IP-blokkades |
| Agent → RzSense | iemand die meekijkt of zich voordoet als agent | alleen uitgaande verbindingen vanaf de server; https met vastgezet certificaat; elk bericht versleuteld en ondertekend (AES-256 + HMAC) met tijd en eenmalige nonce; eigen sleutel per agent, regelmatig vernieuwd; koppelen met een eenmalige code van 15 minuten; optioneel IP-slot |
| RzSense → agent (opdrachten) | een overgenomen RzSense | root-commando's alleen als dat per server aan staat, en op de server altijd te blokkeren (`COMMANDS=0`, `SUDO_JIT=0`, `HEAL=0` in `/etc/default/rzmon-agent`); vaste taken (geen vrije shell) en gecontroleerde pakketnamen; alles in het logboek |
| RzSense → agent (updates) | een overgenomen RzSense of downloadadres | de agents en installatiescripts zijn **ondertekend** (zie hieronder): een agent installeert alleen een update met een geldige handtekening, ook als RzSense zelf is overgenomen |
| RzSense → het netwerk (checks) | misbruik van checks om metadata-adressen te bereiken (SSRF) | HTTP-checks alleen met `http://` en `https://`, nooit naar link-local of metadata-adressen (169.254.0.0/16, fe80::/10, multicast), ook niet via een redirect; TLS-controle standaard aan. Interne adressen mogen wel: daar is een monitor voor. Wie checks mag aanmaken (rol *gebruiker*), kan dus vanaf RzSense interne webadressen opvragen. |
| Back-ups | wie een back-upbestand in handen krijgt | zip optioneel versleuteld met AES-256; `config.php` staat nooit in git |

**Buiten dit model:**

- een aanvaller die al **root** is op de RzSense-server of op een server met een agent;
- een gelekt wachtwoord **zonder** 2FA: zet 2FA of een passkey aan voor elk account dat van buiten mag inloggen;
- lekken in PHP, MariaDB, de webserver of OpenSSL: houd die bij met de updates van je distributie.

Hoe je RzSense zelf veilig instelt, staat in [Toegang en beheer](docs/11-toegang-en-beheer.md), met een
beveiligingschecklist.

## De ondertekensleutel

GitHub Actions ondertekent bij elke wijziging met **Ed25519**:

- de Linux-agent (`agent/rzmon-agent.sh`) en de installatiescripts (`agent/install-agent.sh`, `agent/install-relay.sh`),
  en de relay (`relay/`): de handtekening staat ernaast als `.sig` (base64), workflow *Agent ondertekenen*;
- de Windows-agent (`.exe` en `.msi` in `agent-win/dist/`), workflow *Windows-agent bouwen*.

**Publieke sleutel** (ingebouwd in de agents als `AGENT_SIGN_PUB`):

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

Een installatiescript dat je bij RzSense ophaalt, bevat de downloadsleutel in de regel `DL_KEY='…'`. Zet die regel eerst
terug naar `DL_KEY=''` voordat je controleert (de agent doet dat zelf ook).

**De privésleutel** staat alleen als geheim `AGENT_SIGN_KEY` in GitHub (*Settings → Secrets and variables → Actions*),
nooit in de repository of ergens anders online. De workflows controleren na het ondertekenen dat de handtekening klopt
met de publieke sleutel.

### De sleutel vervangen

Bijvoorbeeld als de privésleutel gelekt is:

1. Maak een nieuw sleutelpaar: `openssl genpkey -algorithm ed25519 -out sign.key` en `openssl pkey -in sign.key -pubout`.
2. Zet de nieuwe publieke sleutel overal waar `AGENT_SIGN_PUB` staat: `agent/src/80-bijwerken.sh`,
   `agent/install-agent.sh`, `agent/install-relay.sh` en `agentSignPub` in `agent-win/update.go`. Verhoog de versies van
   de agents en van RzSense.
3. Vervang het geheim `AGENT_SIGN_KEY` in GitHub door de nieuwe privésleutel, en verwijder `sign.key` daarna overal.
4. Agents met de oude sleutel vertrouwen de nieuwe handtekening niet en werken zichzelf dus niet bij. Installeer ze één
   keer opnieuw met het installatiecommando uit de Agent Manager. Doe dat alleen als je RzSense zelf vertrouwt.

## Controles in GitHub Actions

- **Tests:** PHP-tests met een echte MariaDB, de agent op meerdere Linux-versies, de Windows-agent, en een rondje in de
  browser.
- **Beveiliging:** PHPStan (niveau 6, met `phpstan-baseline.neon`), een zoektocht naar geheimen in de repository
  (`tools/check-secrets.sh`), en een controle op inline scripts die de Content-Security-Policy zouden breken.
- **Versies:** versienummers en changelogs kloppen overal.

## Licentie

RzSense valt onder de **GNU General Public License v3.0** (zie `LICENSE`).
