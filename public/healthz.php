<?php
declare(strict_types=1);

// 1.281.0: gezondheidscontrole voor de container (en load balancers): alleen "ok" of "fout", zonder verdere informatie.
// Werkt PHP en is de database bereikbaar? Dan 200, anders 503.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
try {
    require __DIR__ . '/../src/bootstrap.php';
    db()->query('SELECT 1')->fetchColumn();
    echo "ok\n";
} catch (Throwable) {
    http_response_code(503);
    echo "fout\n";
}
