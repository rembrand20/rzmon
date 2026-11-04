<?php
// 1.171.0: AI-adviezen afmaken die klaarstaan (gestart door cron/run.php). Een model kan lang nadenken; daarom
// in een eigen proces met een eigen slot, zodat de checks van de volgende minuut niet hoeven te wachten.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../src/bootstrap.php';

if (!is_configured()) {
    exit(0);
}
$uid = function_exists('posix_geteuid') ? posix_geteuid() : get_current_user();
$lock = @fopen(sys_get_temp_dir() . "/rzmon-ai-$uid.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // er draait er al een
}
I18n::set(I18n::notifyLang());
Ai::runQueue(240);
