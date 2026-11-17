# RzSense als container (Podman op Rocky Linux)

Vanaf **1.281.0** draait RzSense ook als container, **naast** de gewone serverinstallatie (`install/install-rocky.sh`, die blijft bestaan).
Twee containers:

| Container | Image | Wat |
|---|---|---|
| `rzsense` | `ghcr.io/rembrand20/rzsense` | RzSense zelf: nginx, PHP-FPM en de planner (elke minuut `cron/run.php`) |
| `rzsense-db` | `docker.io/library/mariadb:11.4` | de database; werk je los bij |

- **Netwerk:** het netwerk van de host (`Network=host`). Ping, SNMP, mtr, ARP-ontdekking en LLDP werken dan zoals bij een gewone installatie, en de agents bereiken RzSense op het adres van de server.
- **Poorten:** **8080** (http) voor je reverse proxy, zoals SWAG, en **8443** voor de **nooddeur** (https met een eigen certificaat, alleen vanaf je eigen netwerk). Beide zijn in te stellen.
- **Gegevens:** het volume `rzsense-data` (`/var/lib/rzmon`: `config.php`, back-ups, certificaat, sleutels) en `rzsense-db` (de database).
- **Veilig:** RzSense draait als `www-data`, niet als root. Het bestandssysteem is alleen-lezen, behalve `/var/lib/rzmon` en `/tmp`. De enige extra capability is `NET_RAW`, voor ping. Er wordt geen Podman-socket aangekoppeld.

## Installeren (Podman met quadlets)

```bash
sudo dnf -y install podman
sudo mkdir -p /etc/rzsense && sudo chmod 700 /etc/rzsense
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/container/rzsense.env.example | sudo tee /etc/rzsense/rzsense.env >/dev/null
sudo chmod 600 /etc/rzsense/rzsense.env
sudo nano /etc/rzsense/rzsense.env        # wachtwoorden invullen (openssl rand -base64 24)

for f in rzsense-db.container rzsense.container; do
  curl -fsSL "https://raw.githubusercontent.com/rembrand20/rzmon/main/container/quadlet/$f" | sudo tee "/etc/containers/systemd/$f" >/dev/null
done
sudo systemctl daemon-reload
sudo systemctl start rzsense-db rzsense
sudo podman logs -f rzsense               # "klaar: web op poort 8080 …"
```

Firewall: open alleen wat nodig is. 8080 voor de reverse proxy, en 8443 als je de nooddeur wilt gebruiken. Bijvoorbeeld, met het adres van je SWAG-server:

```bash
sudo firewall-cmd --permanent --add-rich-rule='rule family=ipv4 source address=<IP van SWAG> port port=8080 protocol=tcp accept'
sudo firewall-cmd --permanent --add-port=8443/tcp && sudo firewall-cmd --reload
```

**Eerste beheerder:** `RZMON_ADMIN_USER` / `RZMON_ADMIN_PASS` in `rzsense.env`. Laat je het wachtwoord leeg, dan maakt RzSense er een en zet het één keer in de log (`sudo podman logs rzsense`). Verander het na het inloggen.

**Achter SWAG:** laat je SWAG-site doorsturen naar `http://<server>:8080`. Zet daarna in RzSense *Instellingen → Toegang tot RzSense* het beheeradres, zoals je dat bij de gewone installatie doet.

## Bijwerken

RzSense kijkt elke dag of er een nieuwe versie is en meldt dat **in de app** (het teken bij Instellingen, en *Instellingen → Systeem → Bijwerken*). Daar staan ook de commando's. Bijwerken gaat nooit vanzelf:

```bash
sudo podman pull ghcr.io/rembrand20/rzsense:latest && sudo systemctl restart rzsense
```

Bij het starten van een nieuwe versie maakt RzSense eerst een **snelle back-up** (in het datavolume) en werkt daarna de database bij. **MariaDB** werk je los bij: `sudo podman pull docker.io/library/mariadb:11.4 && sudo systemctl restart rzsense-db`.

Een bepaalde versie vastzetten: zet in `rzsense.container` `Image=ghcr.io/rembrand20/rzsense:1.281.0`.

## Verhuizen van een gewone installatie

1. In je huidige RzSense: *Instellingen → Systeem → Back-up* → **Nu back-up maken** → **⬇ Downloaden**. Onthoud het zip-wachtwoord.
2. Start de containers zoals hierboven (met een nieuw databasewachtwoord) en log in.
3. *Instellingen → Systeem → Back-up* → **⬆ Zip uploaden** → **↺ Terugzetten**. `config.php` uit de zip wordt in een container niet overgenomen: de container houdt zijn eigen database-instellingen.
4. Werkt alles, zet dan de oude installatie uit. Wijzigt het adres van RzSense, zet dan bij de agents het nieuwe adres (*Servers → adres wisselen*).

## Wat in een container anders is

- **Geen hulpdienst en geen git-updates:** bijwerken gaat met een nieuwe image. `config.php` staat in het datavolume en RzSense mag het zelf aanpassen.
- **CPU, RAM en schijf van de server zelf:** de container ziet vooral zichzelf. Installeer de **agent op de host** voor de echte cijfers van de server.
- **Het certificaat van de nooddeur** wordt bij het starten aangemaakt, en vernieuwd als het binnen 30 dagen verloopt (2 jaar geldig).
- **Zonder host-netwerk** (bijvoorbeeld `-p 8080:8080`) werken UniFi, ping en SNMP nog, maar ARP-ontdekking en LLDP niet.

## Compose

Liever compose? `container/compose.yaml`, met `rzsense.env.example` als `.env` ernaast:

```bash
podman compose up -d
```

## Zelf bouwen

```bash
podman build -f container/Containerfile -t localhost/rzsense .
```
