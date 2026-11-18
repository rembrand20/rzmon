# RzSense Relay

Vanaf **RzSense 1.282.0** (agent 1.109.0). Met een relay rapporteren servers in een netwerk **zonder verbinding met RzSense** toch:

```
agent op server ──▶ relay ──▶ RzSense
                ◀──       ◀──   (het antwoord dezelfde weg terug)
```

De relay is een kleine container (`ghcr.io/rembrand20/rzsense-relay`) op een machine in dat andere netwerk. Er draaien twee dingen in:

- **Het doorstuurprogramma** (`relay.py`, Python 3): https op poort **8444**, met een eigen certificaat. Het stuurt verkeer van de agents door naar RzSense.
- **De agent** (dezelfde als op je servers, in relaymodus). Hij koppelt de relay met RzSense, geeft het doorstuurprogramma zijn instellingen door en meldt hoe het gaat.

## Veilig

- **Versleuteld van agent tot RzSense:** de relay ziet alleen dichte enveloppen en kan ze niet lezen of veranderen.
- **Alleen agentverkeer:** `/api.php` met een vaste lijst acties (rapport, koppelen, testlijst, agent ophalen …). Nooit de webinterface of de rest van de API.
- **Alleen de netwerken die je instelt.** Elk netwerk dat in RzSense via deze relay gaat, mag erdoor (*Beheer → Agents en koppelingen → Netwerken en relays*). Zonder netwerk laat de relay niemand door.
- **Het echte IP-adres:** de relay geeft het adres van de agent door, ondertekend met een sleutel die is afgeleid van zijn eigen agentsleutel. Alleen dan gebruikt RzSense dat adres (IP-slot, blokkades, logboek).
- **Certificaat vastgezet:** de agents achter de relay accepteren alleen het certificaat van die relay. De relay controleert RzSense zoals een agent dat doet.
- **Zonder root:** de container draait als `rzrelay`, alleen-lezen, met alleen `NET_RAW` (voor ping), en kent een limiet per afzender en op de grootte van een bericht.

## Installeren

Het makkelijkst gaat het in RzSense: *Beheer → Agents en koppelingen → Netwerken en relays* → **＋ Relay toevoegen** → **📦 Installeren**. Daar staat het commando met de koppelcode al ingevuld. Met de hand:

```bash
sudo mkdir -p /etc/rzsense && sudo chmod 700 /etc/rzsense
curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/relay/relay.env.example | sudo tee /etc/rzsense/relay.env >/dev/null
sudo chmod 600 /etc/rzsense/relay.env
sudo nano /etc/rzsense/relay.env      # MONITOR_URL, PAIR_CODE (en PIN bij een eigen certificaat)
sudo curl -fsSL https://raw.githubusercontent.com/rembrand20/rzmon/main/relay/quadlet/rzsense-relay.container \
  -o /etc/containers/systemd/rzsense-relay.container
sudo systemctl daemon-reload && sudo systemctl start rzsense-relay
sudo podman logs -f rzsense-relay     # "agent meldt zich bij RzSense"
```

Zet in de firewall van die machine poort 8444 open voor de agents in dat netwerk. Met Docker of compose: zie `compose.yaml` hier, of het Docker-commando in RzSense.

**MONITOR_URL** is het adres waarop de relay RzSense bereikt: via internet (SWAG), via een VPN of via een eigen route. Met `MONITOR_URL_FALLBACK` geef je een tweede adres op.

## Daarna, in RzSense

1. **🌐 Adres** van de relay: waarop de agents in dat netwerk hem bereiken, bijvoorbeeld `https://192.168.50.10:8444`. De relay meldt zelf zijn adressen; je kiest er een.
2. **＋ Netwerk toevoegen:** de adresreeks (bijvoorbeeld `192.168.50.0/24`), via deze relay, en eventueel een reserve-relay.
3. **Servers:** bestaande agents in dat netwerk vallen er vanzelf in (op IP-adres), of je zet ze er zelf in. Klik op **↪ Adressen toepassen**. Elke agent test de relay eerst; zijn rechtstreekse adres wordt het reserve-adres.
4. **Nieuwe servers** in dat netwerk koppel je meteen via de relay: Agent Manager → Installeren → *Verbinden via* de relay.

## Valt de relay weg?

Dan krijg je **één melding** (*Agent stil* van de relay, met hoeveel agents erachter zitten). De meldingen van de agents erachter worden onderdrukt. Die agents bewaren hun metingen en sturen ze na als de relay terug is.

## Bijwerken

```bash
sudo podman pull ghcr.io/rembrand20/rzsense-relay:latest && sudo systemctl restart rzsense-relay
```

De relay heeft het versienummer van de agent erin. De agent in de container werkt zichzelf niet bij; dat gaat met een nieuwe image.

## Zelf bouwen

```bash
podman build -f relay/Containerfile -t localhost/rzsense-relay .
```

Home Assistant en TrueNAS als app, en checks die de relay zelf uitvoert in zijn netwerk (ping, HTTP, SNMP …), volgen in een volgende versie.
