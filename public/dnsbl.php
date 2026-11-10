<?php
/**
 * Blokkadelijst voor AdGuard Home (1.231.0): GET /dnsbl.php?t=<token>. Alleen vanaf het lokale netwerk (je AdGuard staat
 * thuis), en alleen als de lijst aan staat (Instellingen → IDS → Dreigingsinformatie → AdGuard-lijst).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$deny = static function (int $code, string $msg): never {
    Stealth::denyOutside();
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . "\n";
    exit;
};

if (!is_configured()) {
    $deny(503, 'RzSense is not configured');
}
$lan = trim((string) setting('trusted_networks', '')) !== '' ? Security::fromLan() : is_private_client();
if (!$lan) {
    $deny(403, 'Only from the local network');
}
$t = (string) ($_GET['t'] ?? '');
if (!DnsList::enabled() || !preg_match('/^[0-9a-f]{40}$/', $t) || !hash_equals(DnsList::token(), $t)) {
    usleep(300000);
    $deny(404, 'Not found');
}
save_setting('dnsbl_fetched', date('Y-m-d H:i:s'));
save_setting('dnsbl_fetched_by', client_ip());
header('Content-Type: text/plain; charset=utf-8');
echo DnsList::render();
