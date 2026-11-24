<?php
// Nagebootste Shelly voor de tests (php -S 127.0.0.1:PORT tests/fixtures/shelly-fake.php). SHELLY_GEN=1|2, SHELLY_PASS = wachtwoord (Gen 1: basic).
declare(strict_types=1);
header('Content-Type: application/json');
$gen = (int) (getenv('SHELLY_GEN') ?: 2);
$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/shelly') {
    echo $gen === 1 ? json_encode(['type' => 'SHSW-PM', 'mac' => 'AABBCCDDEEFF', 'auth' => false, 'fw' => '20230913-112003/v1.14.0'])
        : json_encode(['name' => 'Keuken', 'id' => 'shellyplus1pm-a1', 'model' => 'SNSW-001P16EU', 'gen' => 2, 'ver' => '1.4.4', 'auth_en' => false]);
    exit;
}
if ($gen === 2 && $path === '/rpc/Shelly.GetStatus') {
    echo json_encode(['switch:0' => ['id' => 0, 'output' => true, 'apower' => 412.3, 'voltage' => 230.1, 'current' => 1.79,
        'aenergy' => ['total' => 15234.5], 'temperature' => ['tC' => 78.2, 'tF' => 172.8], 'errors' => ['overtemp']],
        'wifi' => ['rssi' => -58], 'sys' => ['uptime' => 1036800, 'available_updates' => ['stable' => ['version' => '1.5.0']]]]);
    exit;
}
if ($gen === 1 && $path === '/status') {
    echo json_encode(['relays' => [['ison' => false]], 'meters' => [['power' => 18.5, 'total' => 600]], 'temperature' => 52.0, 'overtemperature' => false,
        'wifi_sta' => ['rssi' => -61], 'uptime' => 3600, 'update' => ['has_update' => true, 'new_version' => '1.14.1']]);
    exit;
}
http_response_code(404);
echo '{}';
