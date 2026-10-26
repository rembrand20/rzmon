<?php
/**
 * Heartbeat ontvangen (1.77.0): een script, cronjob of apparaat meldt zich via de geheime URL van een heartbeat-check.
 *
 *   GET|POST /hb.php/<token>          gelukt (ok)
 *   GET|POST /hb.php/<token>/start    begonnen (voor de duur en "hangt het?")
 *   GET|POST /hb.php/<token>/fail     mislukt; de reden in de body (POST) of ?msg=
 *   ?value=42                         een waarde meesturen (met drempels als de check een eenheid heeft)
 * Zonder PATH_INFO: hb.php?t=<token>&s=start|ok|fail.
 * Standaard alleen vanaf het lokale netwerk (Instellingen → Toegang); per check "ook van buiten" aan te zetten.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function hb_out(string $text, int $code = 200): never
{
    if ($code >= 400) {
        Stealth::denyOutside();   // niet herkenbaar van buiten (1.86.0)
    }
    http_response_code($code);
    echo $text . "\n";
    exit;
}

if (!is_configured()) {
    hb_out('RzMon is not configured', 503);
}
I18n::set(I18n::notifyLang());
$parts = explode('/', trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/'));
$token = strtolower((string) ($parts[0] !== '' ? $parts[0] : ($_GET['t'] ?? '')));
$kind = strtolower((string) ($parts[1] ?? ($_GET['s'] ?? 'ok')));
if ($kind === '' || $kind === 'success') {
    $kind = 'ok';
}
if (!preg_match('/^[a-f0-9]{32}$/', $token) || !in_array($kind, Heartbeat::KINDS, true)) {
    hb_out('Usage: hb.php/<token>[/start|/fail]', 400);
}
$row = q("SELECT c.id, c.name, c.options, h.name AS host_name FROM checks c JOIN hosts h ON h.id = c.host_id
          WHERE c.type = 'heartbeat' AND c.hb_token = ?", [$token])->fetch();
if (!$row) {
    Security::unauthorized('hb', t('onbekende heartbeat-URL'));
    usleep(300000);
    hb_out('Unknown heartbeat', 404);
}
$o = json_decode((string) $row['options'], true) ?: [];
// Alleen vanaf het eigen netwerk, tenzij de check "ook van buiten" heeft. Zijn er (nog) geen lokale netwerken
// ingesteld, dan gelden de privé-adresreeksen (192.168.x, 10.x …).
$lan = trim((string) setting('trusted_networks', '')) !== '' ? Security::fromLan() : is_private_client();
if (empty($o['ext']) && !$lan) {
    Security::unauthorized('hb', t('heartbeat van buiten het lokale netwerk'), [t('check') => $row['host_name'] . ' – ' . $row['name']]);
    hb_out('Only from the local network (enable "also from outside" on the check)', 403);
}
$msg = (string) ($_GET['msg'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msg === '') {
    $msg = isset($_POST['msg']) ? (string) $_POST['msg'] : (string) file_get_contents('php://input', false, null, 0, 2000);
}
$value = $_GET['value'] ?? $_POST['value'] ?? null;
Heartbeat::event((int) $row['id'], $kind, time(), is_numeric($value) ? (float) $value : null, $msg);
Monitor::runCheckById((int) $row['id']);
hb_out('OK');
