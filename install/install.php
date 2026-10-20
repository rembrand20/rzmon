<?php
// Gebruik: php install/install.php <gebruikersnaam>
//         php install/install.php --upgrade   (alleen database bijwerken)
//         php install/install.php --reset-2fa <gebruikersnaam>   (telefoon kwijt: 2FA uitzetten)
//         sudo php install/install.php --rotate-db-password     (nieuw databasewachtwoord, overal opgeslagen)
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
if (($argv[1] ?? '') === '--rotate-db-password') {
    // Nieuw databasewachtwoord (als root: alle accounts + /root/rzmon-install.txt).
    // --helper: aangeroepen door de hulpdienst na de knop in RzMon (zie install/install-helper.sh).
    $helper = in_array('--helper', $argv, true);
    $nonce = '';
    if ($helper) {
        $req = Installer::HELPER_DIR . '/rotate.request';
        $nonce = substr(preg_replace('/[^a-f0-9]/', '', (string) @file_get_contents($req)), 0, 64);
        @unlink($req);
        if ($nonce === '') {
            exit(0);
        }
    }
    $r = Installer::rotateDbPassword();
    if ($helper) {
        // Resultaat voor de webpagina: alleen leesbaar voor root en de webserver, wordt na het tonen verwijderd
        $file = Installer::HELPER_DIR . '/rotate.result';
        $grp = @filegroup(Installer::HELPER_DIR);
        file_put_contents($file, json_encode($r + ['nonce' => $nonce, 'at' => time()]));
        @chmod($file, 0640);
        if ($grp !== false) {
            @chgrp($file, $grp);
        }
        exit($r['ok'] ? 0 : 1);
    }
    if (!$r['ok']) {
        fwrite(STDERR, "Mislukt: {$r['error']}\n");
        exit(1);
    }
    echo "Nieuw databasewachtwoord (wordt maar één keer getoond):\n\n    {$r['password']}\n\n";
    echo "Opgeslagen in config.php" . (is_writable('/root/rzmon-install.txt') ? ' en /root/rzmon-install.txt' : '') . ". RzMon werkt gewoon door.\n";
    foreach ($r['warnings'] as $w) {
        echo "Let op: $w\n";
    }
    exit(0);
}
if (($argv[1] ?? '') === '--reset-2fa') {
    Installer::ensureSchema();
    $name = (string) ($argv[2] ?? '');
    $n = q('UPDATE users SET totp_secret = NULL, totp_last_step = NULL WHERE username = ?', [$name])->rowCount();
    exit($n ? "Tweestapsverificatie van '$name' is uitgezet. Log in met alleen het wachtwoord.\n" : "Gebruiker '$name' niet gevonden (of 2FA stond al uit).\n");
}
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
