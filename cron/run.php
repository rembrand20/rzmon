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
    exit("Vorige run is nog bezig\n");
}

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
    if (!$r['ok']) {
        fwrite(STDERR, "MQTT: {$r['error']}\n");
    }
}
// Verlopen blokkades (inlogbeveiliging) opheffen, met een ℹ️-melding
try {
    Security::expire();
} catch (Throwable $e) {
    fwrite(STDERR, 'Blokkades: ' . $e->getMessage() . "\n");
}
// Eén keer per uur opruimen (ook als de run van precies :00 is overgeslagen)
if (strtotime((string) setting('purge_at', '')) < time() - 3600) {
    save_setting('purge_at', now());
    Monitor::purge();
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
// Kwetsbaarheden: dagelijkse scan en servers waarvan de softwarelijst veranderde (Instellingen → 🐞 Kwetsbaarheden)
try {
    if ($msg = Vulns::cron()) {
        echo date('c') . " $msg\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Kwetsbaarheden: ' . $e->getMessage() . "\n");
}
