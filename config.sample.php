<?php
// Kopieer naar config.php en pas aan — of gebruik de setup-pagina (setup.php) in je browser.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'rzmon',
        'user' => 'rzmon',
        'pass' => 'VERANDER_MIJ',
    ],
    // Tijdzone voor weergave en logging
    'timezone' => 'Europe/Amsterdam',
    // Pad naar ping binary (moet ICMP mogen sturen; standaard op Debian/Ubuntu OK)
    'ping_bin' => '/bin/ping',
    // Basis-URL waarop de app bereikbaar is (gebruikt in agent-installatie-instructies)
    'base_url' => 'http://rzmon.example.lan',
    // PHP-instellingen die RzMon zelf toepast (leeg = php.ini-waarde)
    'php' => [
        'memory_limit' => '',
        'max_execution_time' => '',
        'display_errors' => '0',
    ],
];
