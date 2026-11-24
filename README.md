<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="brand/rzmon-logo-dark.svg">
    <img src="brand/rzmon-logo.svg" alt="RzSense" height="64">
  </picture>
</h1>

**Watching your servers, so you don't have to.**

RzSense bewaakt je servers, netwerk en Home Assistant, en beschermt ze tegen aanvallen. Je draait het zelf, op je eigen
server: geen cloud, geen abonnement. Het controleert elke minuut of alles draait, laat je weten als er iets mis is, en
houdt aanvallers buiten.

## Wat het doet

- **Monitoring** — ping, poorten, websites, certificaten, CPU, geheugen, schijven, services, containers, databases,
  SNMP-apparaten en back-ups die zich zelf melden. Met een dashboard, grafieken, trends en voorspellingen.
- **Meldingen** — via Pushover, ntfy, Web Push (zonder app), e-mail, Home Assistant, MQTT, Discord, Slack en Teams.
  Gebundeld, zonder flapperen, met stille uren en bevestigen. Voor echte noodgevallen een beveiligingskanaal dat door
  Niet storen heen gaat.
- **Beveiliging** — inbraakdetectie (IDS) en endpoint-bewaking (EDR) op je servers: webshells, ransomware, malware,
  brute force, verdachte logins en processen. Automatisch blokkeren of een server isoleren. Een beschermde reverse
  proxy met WAF, virusscanner en Suricata.
- **Kwetsbaarheden en updates** — welke bekende lekken er op je servers zitten, wat end-of-life is, en updates
  installeren in een onderhoudsvenster.
- **Agents** — een lichte agent voor Linux (ook Proxmox, TrueNAS en Raspberry Pi), Windows Server 2019–2025 en
  Home Assistant OS. Alles versleuteld en ondertekend, met een eigen sleutel per server.
- **Netwerk** — wat er op je netwerk zit, wat nieuw is, en wat er van buiten bereikbaar is.

Nederlands en Engels. Licentie: [GPL-3.0](LICENSE).

## Installeren

Op een verse **Rocky Linux, RHEL of AlmaLinux 10** gaat het in één keer:

```bash
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/install/install-rocky.sh -o install-rocky.sh
sudo bash install-rocky.sh
```

Het script installeert de webserver, PHP en MariaDB, regelt SELinux en de firewall, en zet de planner aan. Daarna
open je RzSense in je browser en loop je de setup door.

Liever in een **container** (Podman of Docker), of op **Debian / Ubuntu**? Dat staat in de
[installatiehandleiding](docs/01-installatie.md).

## Documentatie

| | |
|---|---|
| [Installatie](docs/01-installatie.md) | Installeren, HTTPS, bijwerken en problemen oplossen |
| [De interface](docs/02-de-interface.md) | Een rondleiding: dashboard, monitoring, incidenten, analyse |
| [Meldingen](docs/03-meldingen.md) | Kanalen, wanneer melden, stille uren, het beveiligingskanaal |
| [Servers en checks](docs/04-servers-en-checks.md) | Servers toevoegen en alle soorten checks |
| [Linux-agent](docs/05-linux-agent.md) | De agent voor Linux, Proxmox en TrueNAS |
| [Windows-agent](docs/06-windows-agent.md) | De agent voor Windows Server, ook uitrollen met groepsbeleid |
| [Home Assistant](docs/07-home-assistant.md) | De add-on, sensoren en de netwerkkaart |
| [Beveiliging](docs/08-beveiliging.md) | IDS, EDR, ransomware, reverse proxy en meer |
| [Kwetsbaarheden en updates](docs/09-kwetsbaarheden-en-updates.md) | Lekken, end-of-life en updates installeren |
| [Netwerk](docs/10-netwerk.md) | Netwerkscan en apparaten |
| [Toegang en beheer](docs/11-toegang-en-beheer.md) | Inloggen, gebruikers, back-up, reserve-RzSense en de REST API |

Verder: [alle functies op een rij](FEATURES.md) · [wat er per versie veranderd is](CHANGELOG.md) ·
[beveiliging en lekken melden](SECURITY.md)
