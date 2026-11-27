<?php
declare(strict_types=1);

/**
 * RzSense in een container (1.281.0): voorbereiden bij het opstarten, als www-data, vóór de webserver start.
 *  1. config.php in het datavolume (/var/lib/rzmon) aanmaken bij de eerste start (database uit de omgeving);
 *  2. wachten tot de database er is;
 *  3. eerste start: tabellen en de eerste beheerder; een nieuwe versie: eerst een snelle back-up, dan de database bijwerken;
 *  4. het certificaat van de nooddeur aanmaken of vernieuwen;
 *  5. Let's Encrypt: het nginx-blok voor het domein terugzetten (1.358.0).
 * Wachtwoorden komen uit RZMON_DB_PASS / RZMON_ADMIN_PASS of uit een bestand (…_FILE, zoals Podman-secrets).
 */

define('DATA', rtrim((string) (getenv('RZMON_DATA') ?: '/var/lib/rzmon'), '/'));   // vast in de image; instelbaar om te testen

function env(string $k, string $default = ''): string
{
    $f = getenv($k . '_FILE');
    if (is_string($f) && $f !== '' && is_readable($f)) {
        return trim((string) file_get_contents($f));
    }
    $v = getenv($k);
    return is_string($v) && $v !== '' ? $v : $default;
}

function say(string $m): void
{
    fwrite(STDOUT, '[rzsense] ' . $m . "\n");
}

$root = dirname(__DIR__);
$cfgFile = getenv('RZMON_CONFIG') ?: DATA . '/config.php';

// 1. config.php
if (!is_file($cfgFile)) {
    $pass = env('RZMON_DB_PASS');
    if ($pass === '') {
        say('RZMON_DB_PASS (of RZMON_DB_PASS_FILE) ontbreekt: geef het databasewachtwoord mee bij de eerste start.');
        exit(1);
    }
    require $root . '/src/Installer.php';
    $cfg = array_replace_recursive(Installer::CONFIG_DEFAULTS, [
        'db' => ['host' => env('RZMON_DB_HOST', '127.0.0.1'), 'port' => (int) env('RZMON_DB_PORT', '3306'), 'name' => env('RZMON_DB_NAME', 'rzmon'),
            'user' => env('RZMON_DB_USER', 'rzmon'), 'pass' => $pass],
        'timezone' => env('TZ', 'Europe/Amsterdam'),
        'base_url' => rtrim(env('RZMON_BASE_URL'), '/'),
        'ssl_cert' => DATA . '/door/door.crt',
        'ssl_key' => DATA . '/door/door.key',
    ]);
    $tmp = $cfgFile . '.tmp';
    file_put_contents($tmp, Installer::renderConfig($cfg), LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, $cfgFile);
    say("config.php aangemaakt in $cfgFile");
}

require $root . '/src/bootstrap.php';

// 2. wachten op de database (MariaDB start soms trager)
$db = (array) config('db');
for ($i = 0; ; $i++) {
    try {
        Installer::connect($db);
        break;
    } catch (Throwable $e) {
        if ($i >= 60) {
            say('Database niet bereikbaar na 2 minuten: ' . $e->getMessage());
            exit(1);
        }
        if ($i % 10 === 0) {
            say('wachten op de database (' . $db['host'] . ':' . $db['port'] . ')…');
        }
        sleep(2);
    }
}

// 3. tabellen, eerste beheerder, of bijwerken na een nieuwe image
$marker = DATA . '/.image-version';
$was = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
$hasUsers = false;
try {
    $hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (Throwable) {
    // nog geen tabellen
}
if (!$hasUsers) {
    say('eerste start: tabellen aanmaken');
    Installer::runSchema();
    $user = env('RZMON_ADMIN_USER', 'admin');
    $pw = env('RZMON_ADMIN_PASS');
    $generated = $pw === '';
    if ($generated) {
        $pw = rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'Kx'), '=');
    }
    Installer::saveUser($user, $pw);
    Installer::seedLocalHost();
    say("beheerder '$user' aangemaakt" . ($generated ? ". Tijdelijk wachtwoord (verander het na het inloggen): $pw" : ''));
} elseif ($was !== RZMON_VERSION) {
    if ($was !== '') {
        say("nieuwe versie: $was → " . RZMON_VERSION . '; eerst een snelle back-up');
        $r = Backup::create('update', false, true);
        say($r['ok'] ? 'back-up: ' . $r['file'] : 'back-up mislukt (' . $r['error'] . '); ik werk de database toch bij');
    }
    Installer::runSchema();
    say('database bijgewerkt naar ' . RZMON_VERSION);
}
file_put_contents($marker, RZMON_VERSION . "\n");

// 4. certificaat van de nooddeur: aanmaken, of vernieuwen als het binnen 30 dagen verloopt
$dir = DATA . '/door';
@mkdir($dir, 0700, true);
$crt = "$dir/door.crt";
$key = "$dir/door.key";
$left = is_file($crt) && ($x = openssl_x509_parse((string) file_get_contents($crt))) ? (int) $x['validTo_time_t'] - time() : -1;
if ($left < 30 * 86400) {
    $names = array_values(array_unique(array_filter(['DNS:localhost', 'DNS:' . (string) gethostname(), 'IP:127.0.0.1',
        ...array_map(static fn($ip) => 'IP:' . $ip, OwnCert::serverIps())])));
    $ext = tempnam('/tmp', 'rzext');
    file_put_contents($ext, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=" . implode(',', $names) . "\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature\nextendedKeyUsage=serverAuth\n");
    $pk = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => 'RzSense nooddeur'], $pk, ['digest_alg' => 'sha256', 'config' => $ext, 'req_extensions' => 'v3']);
    $cert = openssl_csr_sign($csr, null, $pk, OwnCert::DAYS, ['digest_alg' => 'sha256', 'config' => $ext, 'x509_extensions' => 'v3'], random_int(1, PHP_INT_MAX));
    @unlink($ext);
    openssl_pkey_export($pk, $pem);
    file_put_contents("$key.tmp", $pem);
    chmod("$key.tmp", 0600);
    openssl_x509_export($cert, $out);
    file_put_contents("$crt.tmp", $out);
    rename("$key.tmp", $key);
    rename("$crt.tmp", $crt);
    say('certificaat van de nooddeur aangemaakt (' . implode(', ', $names) . ')');
}

// 5. Let's Encrypt (1.358.0): de map voor de controlebestanden, en het nginx-blok voor het domein als er een certificaat is
@mkdir(DATA . '/acme/.well-known/acme-challenge', 0755, true);
try {
    if ($msg = LetsEncrypt::boot()) {
        say($msg);
    }
} catch (Throwable $e) {
    say("Let's Encrypt niet gekoppeld: " . $e->getMessage());
}
