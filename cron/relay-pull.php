<?php
// 1.292.0: ophalen bij de relays waar RzSense ophaalt (DMZ). cron/run.php start dit elke minuut als er zo'n relay is; het
// loopt bijna een minuut en haalt bij elke relay op met zijn eigen interval (standaard elke 15 seconden). Eén tegelijk.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../src/bootstrap.php';
if (!is_configured()) {
    exit(0);
}
$uid = function_exists('posix_geteuid') ? posix_geteuid() : get_current_user();
$lock = @fopen(sys_get_temp_dir() . "/rzmon-relay-pull-$uid.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // de vorige loopt nog
}
I18n::set(I18n::notifyLang());
$end = time() + 57;
while (time() < $end) {
    if (Reserve::standby()) {
        break;   // een reserve in stand-by haalt niet op; pas als hij het werk overneemt
    }
    foreach (RelayPull::due() as $r) {
        try {
            $err = RelayPull::run($r);
            if ($err !== null) {
                fwrite(STDERR, date('c') . " relay {$r['name']}: $err\n");
            }
        } catch (Throwable $e) {
            fwrite(STDERR, date('c') . " relay {$r['name']}: {$e->getMessage()}\n");
            try {
                SelfCheck::log('error', 'relay-pull: ' . $e->getMessage(), $e->getFile(), $e->getLine());
            } catch (Throwable) {
            }
        }
    }
    $wait = min(RelayPull::nextIn(), $end - time());
    if ($wait > 0) {
        sleep($wait);
    }
}
