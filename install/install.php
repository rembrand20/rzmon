<?php
// Gebruik: php install/install.php <gebruikersnaam>
//         php install/install.php --upgrade   (alleen database bijwerken)
// Tip: alles kan ook via de browser op /setup.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../src/bootstrap.php';

if (!is_configured()) {
    exit("config.php ontbreekt. Kopieer config.sample.php naar config.php of gebruik setup.php in de browser.\n");
}

$upgrade = in_array('--upgrade', $argv, true);
$user = $argv[1] ?? 'admin';

echo "Database schema laden...\n";
Installer::runSchema();
if ($upgrade) {
    exit("Database bijgewerkt.\n");
}

echo "Wachtwoord voor '$user': ";
system('stty -echo 2>/dev/null');
$pass = trim((string) fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

try {
    Installer::saveUser($user, $pass);
} catch (InvalidArgumentException $e) {
    exit($e->getMessage() . "\n");
}
Installer::seedLocalHost();

echo "Klaar! Log in als '$user'.\n";
