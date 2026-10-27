<?php
// Uitvoeren via cron, elke minuut:
// * * * * * www-data /usr/bin/php /var/www/rzmon/cron/run.php >/dev/null 2>&1
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../src/bootstrap.php';

if (!is_configured()) {
    exit("RzMon is nog niet ingesteld (open setup.php in je browser)\n");
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
$n = Monitor::runDue($force);
Monitor::pollReceipts();
save_setting('cron_last_run', now());
if (setting('mqtt_enabled', '0') === '1') {
    $r = Mqtt::publishAll();
    Tasks::mqttResult($r['ok'], (string) ($r['error'] ?? ''));
    if (!$r['ok']) {
        fwrite(STDERR, "MQTT: {$r['error']}\n");
    }
}
// Verlopen blokkades (inlogbeveiliging) opheffen, met een ℹ️-melding
try {
    Security::expire();
    SessionStore::purge();   // sessies die te lang stil waren (1.85.0)
} catch (Throwable $e) {
    fwrite(STDERR, 'Blokkades: ' . $e->getMessage() . "\n");
}
// Eén keer per uur opruimen (ook als de run van precies :00 is overgeslagen)
if (strtotime((string) setting('purge_at', '')) < time() - 3600) {
    save_setting('purge_at', now());
    Monitor::purge();
    NetScan::purge();
}
echo date('c') . " $n check(s) uitgevoerd\n";

// Automatische back-up (Instellingen → Back-up). Eerst het lock van deze run vrijgeven:
// een grote back-up mag de checks van de volgende minuut niet ophouden.
if ($lock !== false) {
    flock($lock, LOCK_UN);
}
if ($msg = Backup::runScheduled()) {
    echo date('c') . " $msg\n";
}
// Dagelijks kijken of er een nieuwe versie is; eventueel automatisch bijwerken (Instellingen → RzMon bijwerken)
if ($msg = Updater::cron()) {
    echo date('c') . " $msg\n";
}
// Certificaat van het beheeradres: dagelijks (Instellingen → Beveiliging → Beheer via https)
try {
    if ($msg = Https::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Certificaat: ' . $e->getMessage() . "\n");
}
// Agents via hun reserve-adres: één samengevatte melding na 5 minuten; klaargezet certificaat wisselen (1.78.0)
try {
    if ($msg = AgentCrypto::fallbackCron()) {
        echo date('c') . " $msg\n";
    }
    if ($msg = OwnCert::cron()) {
        echo date('c') . " $msg\n";
    }
    if ($msg = Ids::weeklyCron()) {
        echo date('c') . " $msg\n";
    }
    if ($msg = IpIntel::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Agents/certificaat: ' . $e->getMessage() . "\n");
}
// Datalekken (Have I Been Pwned): domein en e-mailadressen, dagelijks, gespreid (Instellingen → Beveiliging → Datalekken)
try {
    if ($msg = Hibp::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Datalekken: ' . $e->getMessage() . "\n");
}
// Kwetsbaarheden: dagelijkse scan en servers waarvan de softwarelijst veranderde (Instellingen → 🐞 Kwetsbaarheden)
try {
    if ($msg = Vulns::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Kwetsbaarheden: ' . $e->getMessage() . "\n");
}
// Netwerkscan (1.103.0): elk uur een ronde, een paar keer per dag dieper (Instellingen → Beveiliging → Netwerkscan)
try {
    if ($msg = NetScan::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Netwerkscan: ' . $e->getMessage() . "\n");
}
// Analyse (1.106.0): dagtotalen elk uur bijwerken, en het maandrapport op de 1e van de maand
try {
    if ($msg = Analytics::cron()) {
        echo date('c') . " $msg\n";
    }
    if ($msg = Analytics::monthlyCron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Analyse: ' . $e->getMessage() . "\n");
}
// Slimme drempels (1.107.0): elke nacht vanaf 4:00 opnieuw leren, een paar checks per minuut
try {
    Smart::cron();
} catch (Throwable $e) {
    fwrite(STDERR, 'Slimme drempels: ' . $e->getMessage() . "\n");
}
// Achtergrondtaken die te lang niet lukken: één melding, en een ✅ als het weer lukt (Instellingen → Systeem → Verbindingen en taken)
try {
    if ($msg = Tasks::watch()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Taken: ' . $e->getMessage() . "\n");
}
// Gezondheid van RzMon zelf (1.96.0): dagelijks de grootte van de database, en hoe lang deze ronde duurde
try {
    SelfCheck::daily();
    SelfCheck::cronDone(microtime(true) - $cronStart);
} catch (Throwable $e) {
    fwrite(STDERR, 'Systeemcheck: ' . $e->getMessage() . "\n");
}
