<?php
// Gebruik: php install/install.php <gebruikersnaam>
//         php install/install.php --upgrade   (alleen database bijwerken)
//         php install/install.php --reset-2fa <gebruikersnaam>   (telefoon kwijt: 2FA uitzetten)
//         php install/install.php --reset-password <gebruikersnaam>   (wachtwoord kwijt en geen mail)
//         sudo php install/install.php --rotate-db-password     (nieuw databasewachtwoord, overal opgeslagen)
//         sudo php install/install.php --backup [--zonder-historie]   (back-up maken)
//         sudo php install/install.php --list-backups
//         sudo php install/install.php --unblock-all   (alle blokkades opheffen, bv. als je jezelf buitensloot)
//         sudo php install/install.php --unlock <gebruikersnaam>   (account deblokkeren na te veel mislukte logins)
//         sudo php install/install.php --https-off   (beheer weer via http: "alleen https" en "inloggen via http weigeren" uit)
//         sudo php install/install.php --renew-cert   (eigen certificaat van RzMon op poort 443 vernieuwen, 2 jaar geldig;
//                                                      met vastgezette agents eerst klaarzetten, --activate wisselt, --now meteen)
//         sudo php install/install.php --restore <zip> [--with-config] [--password=…]
//              (database terugzetten; met --with-config, of op een nieuwe server zonder config.php,
//               ook config.php plus de databasegebruiker)
// Tip: alles kan ook via de browser op /setup.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../src/bootstrap.php';

/** Wachtwoord vragen zonder het te tonen. */
function ask_hidden(string $prompt): string
{
    echo $prompt;
    system('stty -echo 2>/dev/null');
    $v = trim((string) fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
    return $v;
}

if (($argv[1] ?? '') === '--restore') {
    $file = (string) ($argv[2] ?? '');
    if (!is_file($file)) {
        exit("Gebruik: php install/install.php --restore <back-up.zip> [--with-config] [--password=…]\n");
    }
    if (!class_exists('ZipArchive')) {
        exit("De PHP-extensie zip ontbreekt: " . Backup::fixCommand() . "\n");
    }
    $password = null;
    foreach ($argv as $a) {
        if (str_starts_with($a, '--password=')) {
            $password = substr($a, 11);
        }
    }
    $wasConfigured = is_configured();
    $withConfig = in_array('--with-config', $argv, true) || !$wasConfigured;
    $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;

    if ($withConfig) {
        // config.php uit de zip: eerst naar een tijdelijk bestand, dan controleren en plaatsen
        $tmp = tempnam(sys_get_temp_dir(), 'rzmon-cfg');
        $r = Backup::extract($file, 'config.php', $tmp, $password);
        if (!$r['ok'] && !empty($r['need_password']) && $password === null) {
            $password = ask_hidden('Wachtwoord van de zip: ');
            $r = Backup::extract($file, 'config.php', $tmp, $password);
        }
        if (!$r['ok']) {
            @unlink($tmp);
            exit("config.php terugzetten mislukt: {$r['error']}\n");
        }
        $cfg = Backup::readConfig($tmp);
        @unlink($tmp);
        if ($cfg === null) {
            exit("config.php in de back-up is ongeldig of bevat code; niet teruggezet.\n");
        }
        if (!$isRoot) {
            exit("config.php terugzetten kan alleen als root (sudo).\n");
        }
        // Database en gebruiker (met het wachtwoord uit de back-up) aanmaken of bijwerken
        $admin = Installer::rootPdo();
        if ($admin) {
            try {
                Installer::createDatabase($cfg['db'], '', '', $admin);
                echo "Database '{$cfg['db']['name']}' en gebruiker '{$cfg['db']['user']}' klaargezet.\n";
            } catch (Throwable $e) {
                exit("Database klaarzetten mislukt: {$e->getMessage()}\n");
            }
        }
        $stat = @stat(SM_CONFIG_FILE);
        if (file_put_contents(SM_CONFIG_FILE . '.tmp', Installer::renderConfig($cfg), LOCK_EX) === false) {
            exit("Kan config.php niet schrijven.\n");
        }
        chmod(SM_CONFIG_FILE . '.tmp', $stat ? $stat['mode'] & 0777 : 0640);
        $grp = $stat['gid'] ?? null;
        if ($grp === null) {
            foreach (['apache', 'www-data', 'nginx', 'http'] as $g) {
                if ($info = posix_getgrnam($g)) {
                    $grp = $info['gid'];
                    break;
                }
            }
        }
        if ($grp !== null) {
            chgrp(SM_CONFIG_FILE . '.tmp', $grp);
        }
        rename(SM_CONFIG_FILE . '.tmp', SM_CONFIG_FILE);
        $GLOBALS['sm_config'] = $cfg;
        echo "config.php teruggezet.\n";
    }
    if (!is_configured()) {
        exit("config.php ontbreekt.\n");
    }
    try {
        db()->query('SELECT 1 FROM settings LIMIT 1');
        $safety = true;
    } catch (Throwable) {
        $safety = false;   // lege database: geen veiligheidsback-up nodig
    }
    echo "Database terugzetten" . ($safety ? ' (eerst een veiligheidsback-up)' : '') . "...\n";
    $r = Backup::restore($file, $password, $safety);
    if (!$r['ok'] && !empty($r['need_password']) && $password === null) {
        $r = Backup::restore($file, ask_hidden('Wachtwoord van de zip: '), $safety);
    }
    if (!$r['ok']) {
        exit("Mislukt: {$r['error']}\n");
    }
    echo "Klaar! De database is teruggezet" . ($r['safety'] ? ". Veiligheidsback-up van daarvoor: {$r['safety']}" : '') . ".\n";
    exit(0);
}

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
if (($argv[1] ?? '') === '--backup') {
    Installer::ensureSchema();
    $r = Backup::create('manual', in_array('--zonder-historie', $argv, true) ? false : null);
    if (!$r['ok']) {
        fwrite(STDERR, "Mislukt: {$r['error']}\n");
        exit(1);
    }
    printf("Back-up gemaakt: %s/%s (%.1f MB)%s\n", Backup::dir(), $r['file'], $r['size'] / 1048576,
        (string) setting('backup_pass', '') === '' ? "\nLet op: niet versleuteld (geen back-upwachtwoord ingesteld)" : '');
    exit(0);
}
if (($argv[1] ?? '') === '--list-backups') {
    if ($p = Backup::problem()) {
        exit("$p\n");
    }
    foreach (Backup::list() as $b) {
        printf("%s  %s  %7.1f MB  %-13s %s%s\n", $b['at'], $b['file'], $b['size'] / 1048576, $b['type_label'],
            $b['encrypted'] ? '🔒' : '  ', $b['history'] ? '' : ' (zonder historie)');
    }
    exit(0);
}
if (($argv[1] ?? '') === '--reset-password') {
    // Wachtwoord kwijt en "wachtwoord vergeten" werkt niet (bv. de enige beheerder, geen SMTP)
    Installer::ensureSchema();
    $name = (string) ($argv[2] ?? '');
    $id = q('SELECT id FROM users WHERE username = ?', [$name])->fetchColumn();
    if (!$id) {
        exit("Gebruiker '$name' niet gevonden. Gebruik: php install/install.php --reset-password <gebruikersnaam>\n");
    }
    $pass = ask_hidden("Nieuw wachtwoord voor '$name' (min. 8 tekens): ");
    if (mb_strlen($pass) < 8) {
        exit("Te kort; er is niets gewijzigd.\n");
    }
    if (ask_hidden('Nog een keer: ') !== $pass) {
        exit("De wachtwoorden zijn niet gelijk; er is niets gewijzigd.\n");
    }
    q('UPDATE users SET password_hash = ?, pw_changed_at = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), now(), $id]);
    q('DELETE FROM remember_tokens WHERE user_id = ?', [$id]);
    PasswordReset::log((int) $id, $name, 'wachtwoord hersteld via de terminal');
    exit("Wachtwoord van '$name' gewijzigd. Onthouden apparaten en open sessies zijn uitgelogd.\n");
}
if (($argv[1] ?? '') === '--unlock') {
    Installer::ensureSchema();
    $name = (string) ($argv[2] ?? '');
    $id = q('SELECT id FROM users WHERE username = ?', [$name])->fetchColumn();
    if (!$id) {
        exit("Gebruiker '$name' niet gevonden. Gebruik: php install/install.php --unlock <gebruikersnaam>\n");
    }
    Security::unlockUser((int) $id, 'terminal');
    exit("Account '$name' is gedeblokkeerd.\n");
}
if (($argv[1] ?? '') === '--unblock-all') {
    Installer::ensureSchema();
    $ids = q('SELECT id FROM rz_blocks')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
        Security::unblock((int) $bid, 'terminal');   // elk met een ℹ️-melding
    }
    $n = count($ids);
    exit($n ? "$n blokkade(s) opgeheven (Instellingen → ⛔ Blokkades).\n" : "Er stond niets op de blokkadelijst.\n");
}
if (($argv[1] ?? '') === '--https-off') {
    // Noodcommando: buitengesloten door "Beheer alleen via https" (bv. certificaat verlopen)
    Installer::ensureSchema();
    save_setting('https_only', '0');
    save_setting('http_login_block', '0');
    AgentCommands::audit('terminal', 'Beheer via https', 'https_only = 0, http_login_block = 0');
    exit("\"Beheer alleen via https\" en \"Inloggen via http weigeren\" staan uit. Open RzMon via http://<IP-adres van deze server>/.\n"
        . "Heeft je browser HSTS onthouden voor het beheeradres? Gebruik dan het IP-adres, of wis de HSTS-gegevens van die site in je browser.\n");
}
if (($argv[1] ?? '') === '--renew-cert') {
    // Eigen (self-signed) certificaat vernieuwen. --helper: aangeroepen door de hulpdienst na de knop in RzMon.
    Installer::ensureSchema();
    I18n::set(I18n::notifyLang());
    $helper = in_array('--helper', $argv, true);
    $by = 'terminal';
    $nonce = '';
    // Wat te doen (1.78.0): renew (meteen), prepare (klaarzetten; de agents krijgen eerst de vingerafdruk) of activate
    $what = in_array('--activate', $argv, true) ? 'activate' : (in_array('--now', $argv, true) || !OwnCert::pinnedAgents() ? 'renew' : 'prepare');
    if ($helper) {
        $req = Installer::HELPER_DIR . '/cert.request';
        $d = json_decode((string) @file_get_contents($req), true) ?: [];
        @unlink($req);
        $nonce = preg_replace('/[^a-f0-9]/', '', (string) ($d['nonce'] ?? ''));
        if ($nonce === '') {
            exit(0);
        }
        $by = mb_substr((string) preg_replace('/[^\w.@-]/u', '', (string) ($d['by'] ?? '')), 0, 64) ?: 'webinterface';
        $what = in_array($d['action'] ?? '', ['renew', 'prepare', 'activate'], true) ? $d['action'] : 'renew';
    }
    $r = $what === 'activate' ? OwnCert::activate($by) : ($what === 'prepare' ? OwnCert::prepare($by) : OwnCert::renew($by));
    if ($helper) {
        $file = Installer::HELPER_DIR . '/cert.result';
        file_put_contents($file, json_encode($r + ['nonce' => $nonce]));
        @chmod($file, 0640);
        if (($grp = @filegroup(Installer::HELPER_DIR)) !== false) {
            @chgrp($file, $grp);
        }
        exit($r['ok'] ? 0 : 1);
    }
    if (!$r['ok']) {
        fwrite(STDERR, "Mislukt: {$r['error']}\n");
        exit(1);
    }
    if (!empty($r['prepared'])) {
        exit('Nieuw certificaat klaargezet (geldig tot ' . date('d-m-Y', $r['to']) . ') voor: ' . implode(', ', $r['names']) . ".\n"
            . "De agents krijgen eerst de nieuwe vingerafdruk. RzMon meldt wanneer ze die allemaal hebben (uiterlijk " . date('d-m-Y', $r['deadline']) . ");\n"
            . "wissel dan met: sudo php install/install.php --renew-cert --activate  (meteen wisselen: --now)\n");
    }
    exit('Nieuw certificaat, geldig tot ' . date('d-m-Y', $r['to']) . ' voor: ' . implode(', ', $r['names']) . ".\n"
        . "Agents met een vastgezet certificaat krijgen de nieuwe vingerafdruk vanzelf. Je browser vraagt bij https://<IP> opnieuw om \"toch doorgaan\".\n");
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
