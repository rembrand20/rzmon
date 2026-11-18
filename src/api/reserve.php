<?php
declare(strict_types=1);

// Onderdeel van public/api.php (1.286.0): verzoeken van een reserve-RzSense aan deze hoofdserver; zonder sessie.
// Ondertekend en versleuteld met de sleutel van het koppelen (of de koppelcode). Klopt iets niet: een gewone 404.
/**
 * @var string $action
 */
defined('RZ_API') || exit;

if ($action === 'agent_reserve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_configured()) {
        Stealth::notFound();
    }
    // 1.288.0: een deel van de geschiedenis mag groot zijn (hooguit 50 MB, versleuteld iets meer); de rest is klein
    $body = (string) file_get_contents('php://input', false, null, 0, ($_GET['op'] ?? '') === 'hist' ? ReserveHistory::MAX_PART + 4096 : 64 * 1024);
    $h = [];
    foreach (['ts', 'nonce', 'sig'] as $k) {
        $h["x-rz-reserve-$k"] = (string) ($_SERVER['HTTP_X_RZ_RESERVE_' . strtoupper($k)] ?? '');
    }
    session_write_close();
    $res = Reserve::handle((string) ($_GET['op'] ?? ''), $body, $h);
    if ($res === null) {
        Stealth::notFound();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($res));
    echo $res;
    exit;
}
