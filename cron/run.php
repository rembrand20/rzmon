<?php
// Uitvoeren via cron, elke minuut:
// * * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../src/bootstrap.php';

/** 1.224.3: een fout in een stap van cron ook bewaren (Instellingen → Prestaties en fouten), niet alleen op STDERR: de crontab gooit die weg */
function cron_err(string $label, Throwable $e): void
{
    fwrite(STDERR, $label . ': ' . $e->getMessage() . "\n");
    try {
        SelfCheck::log('error', 'cron, ' . $label . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
    } catch (Throwable) {
    }
}

if (!is_configured()) {
    exit("RzSense is nog niet ingesteld (open setup.php in je browser)\n");
}

// Voorkom overlappende runs. Lockbestand per gebruiker: een handmatige run als root
// mag de cron van de webserver-gebruiker niet blokkeren (root-eigen lockbestand).
$uid = function_exists('posix_geteuid') ? posix_geteuid() : get_current_user();
$lockFile = sys_get_temp_dir() . "/rzmon-$uid.lock";
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    fwrite(STDERR, "Kan lockbestand $lockFile niet openen; ga door zonder overlapbeveiliging\n");
} elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_configured()) {
        try {
            save_setting('cron_overlap_at', now());   // de vorige ronde duurde langer dan een minuut (systeemcheck)
        } catch (Throwable) {
        }
    }
    exit("Vorige run is nog bezig\n");
}
$cronStart = microtime(true);

if ($err = Installer::ensureSchema()) {
    fwrite(STDERR, "$err\n");
}
// Geen gebruiker: berichten (checks, meldingen, back-ups, IDS) in de taal van meldingen
I18n::set(I18n::notifyLang());

$force = in_array('--force', $argv, true);
Perf::lap('start');   // snelheidsmeter (1.119.0): de duur van elk onderdeel
$n = Monitor::runDue($force);
Perf::lap('checks');
Monitor::pollReceipts();
// 1.171.0: AI-advies: automatisch vragen bij nieuwe storingen; wat klaarstaat gaat naar cron/ai.php (eigen proces)
try {
    Ai::cron();
} catch (Throwable $e) {
    cron_err('AI', $e);
}
save_setting('cron_last_run', now());
if (setting('mqtt_enabled', '0') === '1') {
    $r = Mqtt::publishAll();
    Tasks::mqttResult($r['ok'], (string) ($r['error'] ?? ''));
    if (!$r['ok']) {
        fwrite(STDERR, "MQTT: {$r['error']}\n");
    }
    Perf::lap('mqtt');
}
// 1.212.0: vaste zoekacties op de logs: herstel en "geen treffers"
try {
    LogWatch::cron();
} catch (Throwable $e) {
    cron_err('Logs (vaste zoekacties)', $e);
}
// Verlopen blokkades (inlogbeveiliging) opheffen, met een ℹ️-melding
try {
    Security::expire();
    SessionStore::purge();   // sessies die te lang stil waren (1.85.0)
} catch (Throwable $e) {
    cron_err('Blokkades', $e);
}
// Eén keer per uur opruimen (ook als de run van precies :00 is overgeslagen)
if (strtotime((string) setting('purge_at', '')) < time() - 3600) {
    // 1.224.3: elke stap apart: één fout (bv. een slot op een grote tabel) slaat de rest niet meer over
    foreach (['Monitor', 'NetScan', 'Perf', 'Incidents', 'Quality', 'Internet'] as $cls) {
        try {
            $cls::purge();
        } catch (Throwable $e) {
            cron_err("Opruimen ($cls)", $e);
        }
    }
    save_setting('purge_at', now());
    Perf::lap('opruimen');
}
echo date('c') . " $n check(s) uitgevoerd\n";

// Automatische back-up (Instellingen → Back-up). Eerst het lock van deze run vrijgeven:
// een grote back-up mag de checks van de volgende minuut niet ophouden.
if ($lock !== false) {
    flock($lock, LOCK_UN);
}
// 1.224.3: het tweede deel (back-up, internet, dreigingsinformatie, kwetsbaarheden, analyse, incidenten, slimme drempels)
// heeft een eigen slot: loopt de vorige ronde daar nog, dan niet nog eens tegelijk (dubbele incidenten, dubbel werk)
$tail = @fopen(sys_get_temp_dir() . "/rzmon-tail-$uid.lock", 'c');
if ($tail !== false && !flock($tail, LOCK_EX | LOCK_NB)) {
    save_setting('cron_overlap_at', now());
    echo date('c') . " het tweede deel van de vorige ronde loopt nog; nu overgeslagen\n";
    exit(0);
}
Perf::lap('blokkades');
if ($msg = Backup::runScheduled()) {
    echo date('c') . " $msg\n";
}
Perf::lap('backup');
// Internet (1.157.0): de snelheidstest op de ingestelde tijden (standaard 4× per dag)
try {
    if ($msg = Internet::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Snelheidstest', $e);
}
Perf::lap('internet');
// Dagelijks kijken of er een nieuwe versie is; eventueel automatisch bijwerken (Instellingen → RzSense bijwerken)
if ($msg = Updater::cron()) {
    echo date('c') . " $msg\n";
}
// 1.205.0: update aangekondigd aan de agents: starten, samenvatting na afloop, of vrijgeven als het misging
try {
    if ($msg = UpdateHold::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Update-aankondiging', $e);
}
// Certificaat van het beheeradres: dagelijks (Instellingen → Beveiliging → Beheer via https)
try {
    if ($msg = Https::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Certificaat', $e);
}
// Agents via hun reserve-adres: één samengevatte melding na 5 minuten; klaargezet certificaat wisselen (1.78.0)
try {
    if ($msg = AgentCrypto::fallbackCron()) {
        echo date('c') . " $msg\n";
    }
    if ($msg = OwnCert::cron()) {
        echo date('c') . " $msg\n";
    }
    if ($msg = Reports::cron()) {   // 1.180.0: alle rapporten (IDS-, AI-weekoverzicht, maandrapport, maandoverzicht …)
        echo date('c') . " $msg\n";
    }
    Perf::lap('certificaten');
    if ($msg = IpIntel::cron()) {
        echo date('c') . " $msg\n";
    }
    Perf::lap('blocklists');
    // 1.112.0: dreigingsinformatie (AbuseIPDB, GreyNoise, Shodan, abuse.ch, Tor): lijsten en de wachtrij
    if ($msg = ThreatIntel::cron()) {
        echo date('c') . " $msg\n";
    }
    Perf::lap('dreigingsinfo');
    // 1.113.0: DNS-controle via AdGuard (verdachte domeinen, DNS-tunnel, verzonnen domeinen)
    if ($msg = DnsWatch::cron()) {
        echo date('c') . " $msg\n";
    }
    if ($n = DnsWatch::nrdCron()) {   // 1.231.0: nieuw geregistreerde domeinen (RDAP, standaard uit)
        echo date('c') . " DNS: $n nieuw geregistreerde domeinen\n";
    }
    Sudo::cron();   // 1.231.0: verlopen tijdelijk sudo afsluiten
    Perf::lap('dns');
    if ($msg = Ioc::cron()) {   // 1.229.0: bewaakte sporen (elke 10 minuten)
        echo date('c') . " $msg\n";
    }
    if ($msg = Patch::cron()) {   // 1.230.0: updates in het onderhoudsvenster of elke dag
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Agents/certificaat', $e);
}
// Datalekken (Have I Been Pwned): domein en e-mailadressen, dagelijks, gespreid (Instellingen → Beveiliging → Datalekken)
try {
    if ($msg = Hibp::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Datalekken', $e);
}
Perf::lap('datalekken');
// Kwetsbaarheden: dagelijkse scan en servers waarvan de softwarelijst veranderde (Instellingen → 🐞 Kwetsbaarheden)
try {
    if ($msg = Vulns::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Kwetsbaarheden', $e);
}
Perf::lap('kwetsbaarheden');
// 1.227.0: end-of-life (endoflife.date), eens per dag
try {
    Eol::daily();
} catch (Throwable $e) {
    cron_err('End-of-life', $e);
}
// Netwerkscan (1.103.0): elk uur een ronde, een paar keer per dag dieper (Instellingen → Beveiliging → Netwerkscan)
try {
    if ($msg = NetScan::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Netwerkscan', $e);
}
Perf::lap('netwerkscan');
// 1.186.0: netwerkverkeer per apparaat (UniFi), elke 5 minuten
try {
    if ($msg = NetTraffic::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Verkeer', $e);
}
Perf::lap('verkeer');
// Analyse (1.106.0): dagtotalen elk uur bijwerken, en het maandrapport op de 1e van de maand
try {
    // 1.155.0: slimme analyses (elke 15 minuten), kritieke CVE's melden, de score per dag
    try {
        if ($msg = Insights::cron()) {
            echo date('c') . " $msg\n";
        }
    } catch (Throwable $e) {
        cron_err('Analyse en advies', $e);
    }
    if ($msg = Analytics::cron()) {
        echo date('c') . " $msg\n";
    }
    // 1.224.0: Trends (dagwaarden elk uur, de analyse en meldingen één keer per dag)
    try {
        if ($msg = Trends::cron()) {
            echo date('c') . " $msg\n";
        }
    } catch (Throwable $e) {
        cron_err('Trends', $e);
    }
} catch (Throwable $e) {
    cron_err('Analyse', $e);
}
Perf::lap('analyse');
// Incidenten (1.142.0): één lijst uit monitoring, IDS en kwetsbaarheden bijwerken
try {
    Incidents::sync();
} catch (Throwable $e) {
    cron_err('Incidenten', $e);
}
Perf::lap('incidenten');
// Slimme drempels (1.107.0): elke nacht vanaf 4:00 opnieuw leren, een paar checks per minuut
try {
    Smart::cron();
} catch (Throwable $e) {
    cron_err('Slimme drempels', $e);
}
// Slimme voorstellen (1.108.0): één keer per nacht vanaf 4:30
try {
    if ($n = Advice::cron()) {
        echo date('c') . " $n nieuwe slimme voorstellen\n";
    }
} catch (Throwable $e) {
    cron_err('Slimme voorstellen', $e);
}
Perf::lap('slim');
// Achtergrondtaken die te lang niet lukken: één melding, en een ✅ als het weer lukt (Instellingen → Systeem → Verbindingen en taken)
try {
    if ($msg = Tasks::watch()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    cron_err('Taken', $e);
}
Perf::lap('taken');
// Gezondheid van RzSense zelf (1.96.0): dagelijks de grootte van de database, en hoe lang deze ronde duurde
try {
    SelfCheck::daily();
    SelfCheck::cronDone(microtime(true) - $cronStart);
} catch (Throwable $e) {
    cron_err('Systeemcheck', $e);
}
// Slim interval (1.108.0): checks met een probleem tussendoor opnieuw, tot vlak voor de volgende cron-minuut
if (!$force) {
    try {
        Monitor::runFastLoop($cronStart + 50);
    } catch (Throwable $e) {
        cron_err('Slim interval', $e);
    }
}
