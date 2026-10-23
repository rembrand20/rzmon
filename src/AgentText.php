<?php
declare(strict_types=1);

/**
 * Vaste teksten die de agent zelf meestuurt (diepere servicecontrole, extra tests, sockets, logbestanden, commando's,
 * Podman, certificaten, Home Assistant, bijwerken), vertaald in RzMon. Zo hoeft de agent niet bijgewerkt te worden.
 * Herkent alleen hele zinnen; al het andere (uitvoer van commando's, systeemmeldingen) blijft zoals het is.
 */
final class AgentText
{
    /** Patroon (hele tekst) => Nederlandse tekst met {a}, {b}, {c} voor de stukken tussen haakjes */
    private const PATTERNS = [
        // diepere servicecontrole
        '/^DNS antwoordt \((.*)\)$/' => 'DNS antwoordt ({a})',
        '/^DNS geeft geen antwoord$/' => 'DNS geeft geen antwoord',
        '/^HTTP (\d+) in (\d+) ms \(serverfout\)$/' => 'HTTP {a} in {b} ms (serverfout)',
        '/^IMAP antwoordt$/' => 'IMAP antwoordt',
        '/^antwoordt op PING$/' => 'antwoordt op PING',
        '/^database antwoordt \(ping\)$/' => 'database antwoordt (ping)',
        '/^database antwoordt niet op ping$/' => 'database antwoordt niet op ping',
        '/^database antwoordt niet$/' => 'database antwoordt niet',
        '/^database geeft zijn begroeting$/' => 'database geeft zijn begroeting',
        '/^database neemt verbindingen aan$/' => 'database neemt verbindingen aan',
        '/^database start op of weigert: (.*)$/s' => 'database start op of weigert: {a}',
        '/^geen HTTP-antwoord op (\S+)$/' => 'geen HTTP-antwoord op {a}',
        '/^geen IMAP-begroeting$/' => 'geen IMAP-begroeting',
        '/^geen SMTP-begroeting op poort (\d+)$/' => 'geen SMTP-begroeting op poort {a}',
        '/^geen SSH-begroeting op poort (\d+)$/' => 'geen SSH-begroeting op poort {a}',
        '/^geen antwoord op PING$/' => 'geen antwoord op PING',
        '/^geen begroeting op poort (\d+)$/' => 'geen begroeting op poort {a}',
        '/^luistert op geen enkele poort$/' => 'luistert op geen enkele poort',
        '/^mailserver antwoordt \(SMTP 220\)$/' => 'mailserver antwoordt (SMTP 220)',
        '/^tijd is gesynchroniseerd$/' => 'tijd is gesynchroniseerd',
        '/^tijd is niet gesynchroniseerd$/' => 'tijd is niet gesynchroniseerd',
        // sockets en eigen test
        '/^(.+): reageert niet binnen 3 seconden$/' => '{a}: reageert niet binnen 3 seconden',
        '/^(.+): neemt geen verbindingen aan$/' => '{a}: neemt geen verbindingen aan',
        '/^(.+): bestaat niet$/' => '{a}: bestaat niet',
        '/^eigen test duurde langer dan 20 seconden$/' => 'eigen test duurde langer dan 20 seconden',
        '/^eigen test (.+) genegeerd: moet van root zijn en mag niet door anderen te wijzigen zijn$/' => 'eigen test {a} genegeerd: moet van root zijn en mag niet door anderen te wijzigen zijn',
        // extra tests (URL of poort)
        '/^geen antwoord van (.+)$/' => 'geen antwoord van {a}',
        '/^HTTP (\d+) van (.+)$/' => 'HTTP {a} van {b}',
        '/^HTTP (\d+), maar "(.*)" staat niet in het antwoord$/s' => 'HTTP {a}, maar "{b}" staat niet in het antwoord',
        '/^HTTP (\d+) in (\d+) ms, bevat "(.*)"$/s' => 'HTTP {a} in {b} ms, bevat "{c}"',
        '/^poort (\d+) op (.+) antwoordt niet$/' => 'poort {a} op {b} antwoordt niet',
        '/^poort (\d+) op (.+) antwoordt$/' => 'poort {a} op {b} antwoordt',
        // commando-checks
        '/^overgeslagen: de andere commando\'s duurden samen te lang$/' => 'overgeslagen: de andere commando\'s duurden samen te lang',
        '/^ongeldig commando ontvangen$/' => 'ongeldig commando ontvangen',
        '/^kan niet als nobody uitvoeren \(runuser ontbreekt\)$/' => 'kan niet als nobody uitvoeren (runuser ontbreekt)',
        // logbestanden
        '/^journalctl ontbreekt op deze server$/' => 'journalctl ontbreekt op deze server',
        '/^(.+) valt buiten de toegestane mappen \((.*)\); voeg de map toe aan LOG_DIRS in \/etc\/default\/rzmon-agent$/' => '{a} valt buiten de toegestane mappen ({b}); voeg de map toe aan LOG_DIRS in /etc/default/rzmon-agent',
        '/^(.+) bestaat niet of is niet leesbaar$/' => '{a} bestaat niet of is niet leesbaar',
        '/^(.+) kan alleen in de Home Assistant-add-on$/' => '{a} kan alleen in de Home Assistant-add-on',
        '/^onbekende bron: (.*)$/' => 'onbekende bron: {a}',
        '/^log van (.+) niet beschikbaar \(heeft de add-on de rol manager\? Werk de add-on bij\)$/' => 'log van {a} niet beschikbaar (heeft de add-on de rol manager? Werk de add-on bij)',
        '/^ongeldig zoekpatroon: (.*)$/s' => 'ongeldig zoekpatroon: {a}',
        // Podman
        '/^home-map is alleen-lezen voor de agent; los op met: sudo rzmon-agent-setup --update(.*)$/s' => 'home-map is alleen-lezen voor de agent; los op met: sudo rzmon-agent-setup --update{a}',
        '/^podman gaf binnen 60 s geen antwoord$/' => 'podman gaf binnen 60 s geen antwoord',
        '/^podman stopte met code (\d+)$/' => 'podman stopte met code {a}',
        // certificaten
        '/^bestand niet gevonden of niet leesbaar$/' => 'bestand niet gevonden of niet leesbaar',
        '/^geen geldig certificaat \(PEM\) in dit bestand$/' => 'geen geldig certificaat (PEM) in dit bestand',
        '/^openssl ontbreekt op deze server \(installeer het pakket openssl\)$/' => 'openssl ontbreekt op deze server (installeer het pakket openssl)',
        '/^naam (.+) onbekend \(DNS\)$/' => 'naam {a} onbekend (DNS)',
        '/^op deze poort draait geen TLS$/' => 'op deze poort draait geen TLS',
        '/^geen antwoord binnen 15 seconden$/' => 'geen antwoord binnen 15 seconden',
        // Home Assistant
        '/^integratielijst niet beschikbaar voor de add-on \((.*)\)$/' => 'integratielijst niet beschikbaar voor de add-on ({a})',
        // pakketten bijwerken (Kwetsbaarheden)
        '/^geblokkeerd op de server \(COMMANDS=(.*) in \/etc\/default\/rzmon-agent\)$/' => 'geblokkeerd op de server (COMMANDS={a} in /etc/default/rzmon-agent)',
        '/^de agent draait niet als root$/' => 'de agent draait niet als root',
        '/^geen geldige pakketnamen ontvangen$/' => 'geen geldige pakketnamen ontvangen',
        '/^systemd-run mislukt$/' => 'systemd-run mislukt',
        // bijwerken
        '/^downloaden van de nieuwe versie bij RzMon mislukt$/' => 'downloaden van de nieuwe versie bij RzMon mislukt',
        '/^controlegetal \(SHA-256\) klopt niet: download onvolledig of onderweg gewijzigd$/' => 'controlegetal (SHA-256) klopt niet: download onvolledig of onderweg gewijzigd',
        '/^de download is geen geldige RzMon-agent (.*)$/' => 'de download is geen geldige RzMon-agent {a}',
        '/^systemd-run mislukt; werk bij met: sudo rzmon-agent-setup --update$/' => 'systemd-run mislukt; werk bij met: sudo rzmon-agent-setup --update',
    ];

    /** Tekst van de agent in de huidige taal; onbekende tekst blijft ongewijzigd */
    public static function tr(string $s): string
    {
        if ($s === '' || I18n::lang() === 'nl') {
            return $s;
        }
        if (preg_match('/^geen TLS-verbinding: (.*)$/s', $s, $m)) {   // met de reden er weer achter
            return t('geen TLS-verbinding: {a}', ['a' => self::tr($m[1])]);
        }
        foreach (self::PATTERNS as $re => $nl) {
            if (preg_match($re, $s, $m)) {
                return t($nl, array_combine(array_slice(['a', 'b', 'c'], 0, count($m) - 1), array_slice($m, 1)));
            }
        }
        return $s;
    }
}
