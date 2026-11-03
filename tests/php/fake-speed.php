<?php
// Nep-testserver voor de snelheidstest (tests/php/run.php): zelfde adressen als speed.cloudflare.com.
// Start met: PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:PORT tests/php/fake-speed.php
declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('cf-ray: 0123456789abcdef-AMS');
if ($path === '/__down') {
    $n = max(0, min(50000000, (int) ($_GET['bytes'] ?? 0)));
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $n);
    $block = str_repeat("\0", 65536);
    while ($n > 0) {
        $k = min($n, 65536);
        echo substr($block, 0, $k);
        $n -= $k;
    }
    return;
}
if ($path === '/__up') {
    $in = fopen('php://input', 'rb');
    $got = 0;
    while ($in && !feof($in)) {
        $got += strlen((string) fread($in, 65536));
    }
    echo $got;
    return;
}
http_response_code(404);
