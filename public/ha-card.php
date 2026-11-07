<?php
/**
 * 1.197.0 (servers erbij in 1.198.0): de RzSense netwerkkaart voor Home Assistant (assets/rzmon-network-card.js).
 *
 * Home Assistant laadt een kaart als JavaScript-module. Komt die van een ander adres dan Home Assistant zelf,
 * dan wil de browser een CORS-kop zien; een gewoon bestand in assets/ krijgt die van de webserver meestal niet.
 * Daarom geeft dit script hetzelfde bestand met die kop. Er staat geen geheim in: de URL van RzSense en de
 * API-sleutel zet je in de kaart zelf.
 *
 * In Home Assistant: Instellingen → Dashboards → ⋮ → Bronnen → Bron toevoegen, URL https://<rzmon>/ha-card.php, JavaScript-module.
 */
declare(strict_types=1);

$file = __DIR__ . '/assets/rzmon-network-card.js';
$etag = '"' . md5((string) filemtime($file) . (string) filesize($file)) . '"';
header('Content-Type: text/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
readfile($file);
