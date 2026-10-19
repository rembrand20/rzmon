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

// Voorkom overlappende runs
$lock = fopen(sys_get_temp_dir() . '/rzmon.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Vorige run is nog bezig\n");
}

$force = in_array('--force', $argv, true);
$n = Monitor::runDue($force);
save_setting('cron_last_run', now());
if (date('i') === '00') {
    Monitor::purge();
}
echo date('c') . " $n check(s) uitgevoerd\n";
