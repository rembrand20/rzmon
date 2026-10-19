<?php
/**
 * RzMon setup: database, applicatie- en PHP-instellingen, eerste beheerder.
 *
 * Toegang:
 *  - Nog niet geïnstalleerd (of database onbereikbaar): alleen vanaf een privé-netwerk (LAN/localhost).
 *  - Geïnstalleerd: alleen voor ingelogde gebruikers (of automatische LAN-login).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

start_session();

// ---------- Status bepalen ----------
$dbError = null;
$userCount = 0;
if (is_configured()) {
    try {
        db()->query('SELECT 1');
        $userCount = Installer::userCount();
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}
$installed = is_configured() && $dbError === null && $userCount > 0;

if ($installed) {
    if (!current_user()) {
        header('Location: index.php');
        exit;
    }
} elseif (!is_private_client()) {
    http_response_code(403);
    exit('Setup is alleen bereikbaar vanaf het lokale netwerk zolang RzMon niet volledig is ingesteld.');
}

// ---------- JSON acties ----------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $send = static function ($data, int $code = 200): never {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        $send(['error' => 'Ongeldig verzoek (CSRF)'], 403);
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];

    $dbFromInput = static function (array $in): array {
        $cur = config('db', Installer::CONFIG_DEFAULTS['db']);
        return [
            'host' => trim((string) ($in['db_host'] ?? '')) ?: '127.0.0.1',
            'port' => (int) ($in['db_port'] ?? 3306) ?: 3306,
            'name' => trim((string) ($in['db_name'] ?? '')),
            'user' => trim((string) ($in['db_user'] ?? '')),
            // Leeg wachtwoordveld = huidig wachtwoord behouden
            'pass' => (string) ($in['db_pass'] ?? '') !== '' ? (string) $in['db_pass'] : (string) ($cur['pass'] ?? ''),
        ];
    };
    $saveConfig = static function (array $changes) use ($send): array {
        $cfg = Installer::mergedConfig($changes);
        if (Installer::writeConfig($cfg)) {
            return ['ok' => true, 'written' => true];
        }
        return ['ok' => true, 'written' => false, 'path' => realpath(dirname(SM_CONFIG_FILE)) . '/config.php',
            'user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www-data') : get_current_user(),
            'content' => Installer::renderConfig($cfg)];
    };

    try {
        switch ($_GET['action']) {
            case 'db_test':
                $db = $dbFromInput($in);
                $pdo = Installer::connect($db, false);
                $version = $pdo->query('SELECT VERSION()')->fetchColumn();
                $exists = (bool) $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($db['name']))->fetchColumn();
                $send(['ok' => true, 'version' => $version, 'db_exists' => $exists]);

            case 'db_save':
                $db = $dbFromInput($in);
                if ($db['name'] === '' || $db['user'] === '') {
                    $send(['error' => 'Databasenaam en gebruiker zijn verplicht'], 422);
                }
                if (!empty($in['create'])) {
                    Installer::createDatabase($db, (string) ($in['admin_user'] ?? 'root'), (string) ($in['admin_pass'] ?? ''));
                }
                $pdo = Installer::connect($db);
                Installer::runSchema($pdo);
                $send($saveConfig(['db' => $db]) + ['message' => 'Verbinding OK, tabellen aangemaakt/bijgewerkt']);

            case 'schema':
                Installer::runSchema();
                $send(['ok' => true, 'message' => 'Tabellen bijgewerkt']);

            case 'app_save':
                $tz = (string) ($in['timezone'] ?? 'Europe/Amsterdam');
                if (!in_array($tz, timezone_identifiers_list(), true)) {
                    $send(['error' => 'Ongeldige tijdzone'], 422);
                }
                $ping = trim((string) ($in['ping_bin'] ?? '/bin/ping'));
                if ($ping !== '' && !preg_match('#^/[\w./-]+$#', $ping)) {
                    $send(['error' => 'Ping-pad moet een absoluut pad zijn'], 422);
                }
                $base = rtrim(trim((string) ($in['base_url'] ?? '')), '/');
                if ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) {
                    $send(['error' => 'Basis-URL is geen geldige URL'], 422);
                }
                $mem = trim((string) ($in['memory_limit'] ?? ''));
                if ($mem !== '' && !preg_match('/^(-1|\d+[KMG]?)$/i', $mem)) {
                    $send(['error' => 'memory_limit: bv. 256M of -1'], 422);
                }
                $met = trim((string) ($in['max_execution_time'] ?? ''));
                if ($met !== '' && !ctype_digit($met)) {
                    $send(['error' => 'max_execution_time moet een getal (seconden) zijn'], 422);
                }
                $send($saveConfig([
                    'timezone' => $tz,
                    'ping_bin' => $ping ?: '/bin/ping',
                    'base_url' => $base,
                    'php' => [
                        'memory_limit' => $mem,
                        'max_execution_time' => $met,
                        'display_errors' => !empty($in['display_errors']) ? '1' : '0',
                    ],
                ]));

            case 'admin_create':
                if (Installer::userCount() > 0) {
                    $send(['error' => 'Er bestaat al een beheerder'], 409);
                }
                Installer::saveUser(trim((string) ($in['username'] ?? '')), (string) ($in['password'] ?? ''));
                Installer::seedLocalHost();
                login(trim((string) $in['username']), (string) $in['password']);
                $send(['ok' => true]);

            default:
                $send(['error' => 'Onbekende actie'], 404);
        }
    } catch (InvalidArgumentException $e) {
        $send(['error' => $e->getMessage()], 422);
    } catch (PDOException $e) {
        $send(['error' => 'Database: ' . $e->getMessage()], 422);
    } catch (Throwable $e) {
        $send(['error' => $e->getMessage()], 500);
    }
}

// ---------- Systeemcheck ----------
function fn_enabled(string $fn): bool
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return function_exists($fn) && !in_array($fn, $disabled, true);
}

$pingBin = (string) config('ping_bin', '/bin/ping');
$systemctl = fn_enabled('shell_exec') ? trim((string) shell_exec('command -v systemctl 2>/dev/null')) : '';
$cronLast = null;
if ($dbError === null && is_configured()) {
    try {
        $cronLast = setting('cron_last_run');
    } catch (Throwable) {
    }
}
$runAs = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();

// [label, ok (true/false/null=waarschuwing), detail]
$checks = [
    ['PHP-versie ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION . ' (' . PHP_SAPI . ')'],
    ['Extensie pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'Geladen' : 'apt install php-mysql'],
    ['Extensie curl', extension_loaded('curl'), extension_loaded('curl') ? 'Geladen' : 'apt install php-curl (nodig voor HTTP-checks en Pushover)'],
    ['Extensie mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'Geladen' : 'apt install php-mbstring'],
    ['exec() / shell_exec() toegestaan', fn_enabled('exec') && fn_enabled('shell_exec'),
        fn_enabled('exec') && fn_enabled('shell_exec') ? 'Toegestaan' : 'Nodig voor ping en services; verwijder uit disable_functions'],
    ['Ping beschikbaar', is_executable($pingBin) ?: null, $pingBin . (is_executable($pingBin) ? '' : ' niet gevonden — apt install iputils-ping')],
    ['systemctl beschikbaar', $systemctl !== '' ?: null, $systemctl ?: 'Niet gevonden: lokale service-checks werken niet'],
    // Vóór de installatie is schrijven handig; daarna hoort de map weer dicht
    $installed
        ? ['Installatiemap vergrendeld', !Installer::configWritable() ?: null,
            Installer::configWritable() ? 'De webserver mag nog schrijven in ' . realpath(SM_ROOT) . '. Vergrendel na de setup: sudo chown root ' . realpath(SM_ROOT) . ' (Rocky Linux: sudo bash ' . realpath(SM_ROOT) . '/install/install-rocky.sh --lock)' : 'OK']
        : ['config.php schrijfbaar', Installer::configWritable() ?: null,
            Installer::configWritable() ? realpath(dirname(SM_CONFIG_FILE)) . '/config.php' : 'Niet schrijfbaar: je krijgt de inhoud om zelf te plaatsen'],
    ['Database verbinding', is_configured() ? ($dbError === null) : null, is_configured() ? ($dbError ?? 'OK') : 'Nog niet ingesteld'],
    ['Cron draait', $cronLast ? (strtotime($cronLast) > time() - 300 ?: null) : null,
        $cronLast ? "Laatste run: $cronLast" : 'Nog geen run gezien (zie stap Cron)'],
];
$phpInfo = [
    'php.ini' => php_ini_loaded_file() ?: '(geen)',
    'Draait als gebruiker' => $runAs,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time') . ' s',
    'display_errors' => ini_get('display_errors') ?: '0',
    'Tijdzone' => date_default_timezone_get() . ' (' . date('H:i') . ')',
];

$cfg = Installer::mergedConfig([]);
$csrf = csrf_token();
$root = realpath(SM_ROOT);
// Onder php-fpm/mod_php is PHP_BINARY niet de CLI; dan het standaardpad gebruiken
$phpBin = PHP_BINARY && preg_match('/^php[\d.]*$/', basename(PHP_BINARY)) ? PHP_BINARY : '/usr/bin/php';
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$v = filemtime(__DIR__ . '/assets/setup.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $e($csrf) ?>">
  <title>RzMon Setup</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📡</text></svg>">
  <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
  <script>try{const t=localStorage.getItem('rzmon-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body>
  <header class="topbar">
    <div class="brand"><span class="logo">📡</span> RzMon <span class="badge">setup</span></div>
    <?php if ($installed): ?>
      <nav class="tabs">
        <a class="tab" href="index.php#dashboard">Dashboard</a>
        <a class="tab" href="index.php#settings">Instellingen</a>
        <a class="tab active" href="setup.php">Setup</a>
      </nav>
    <?php endif; ?>
  </header>

  <main class="container setup">
    <?php if (!$installed): ?>
      <div class="card intro">
        <h2>Welkom bij RzMon 👋</h2>
        <p class="muted">Loop de stappen hieronder door: database koppelen, instellingen kiezen en een beheerder aanmaken. Deze pagina is alleen bereikbaar vanaf je lokale netwerk zolang de installatie niet klaar is.</p>
        <?php if (is_configured() && $dbError): ?><p class="form-error">Database niet bereikbaar: <?= $e($dbError) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>

    <section class="card">
      <h2>1. Systeemcheck</h2>
      <ul class="checklist">
        <?php foreach ($checks as [$label, $ok, $detail]): ?>
          <li class="<?= $ok === true ? 'ok' : ($ok === null ? 'warn' : 'bad') ?>">
            <span class="ic"><?= $ok === true ? '✓' : ($ok === null ? '!' : '✕') ?></span>
            <span><strong><?= $e($label) ?></strong><br><span class="muted small"><?= $e($detail) ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <details class="small api-docs">
        <summary>PHP-omgeving</summary>
        <table class="kv">
          <?php foreach ($phpInfo as $k => $val): ?><tr><th><?= $e($k) ?></th><td class="mono"><?= $e($val) ?></td></tr><?php endforeach; ?>
        </table>
      </details>
    </section>

    <form class="card" id="db-form">
      <h2>2. Database (MariaDB)</h2>
      <div class="row3">
        <label>Host<input name="db_host" value="<?= $e($cfg['db']['host']) ?>" required></label>
        <label>Poort<input name="db_port" type="number" value="<?= $e($cfg['db']['port'] ?? 3306) ?>" min="1" max="65535"></label>
        <label>Database<input name="db_name" value="<?= $e($cfg['db']['name']) ?>" required pattern="\w+"></label>
      </div>
      <div class="row2">
        <label>Gebruiker<input name="db_user" value="<?= $e($cfg['db']['user']) ?>" required autocomplete="off"></label>
        <label>Wachtwoord <span class="hint"><?= ($cfg['db']['pass'] ?? '') !== '' ? '(leeg = ongewijzigd)' : '' ?></span>
          <input name="db_pass" type="password" autocomplete="new-password"></label>
      </div>
      <label class="switch"><input type="checkbox" name="create" id="db-create"><span></span> Database en gebruiker voor mij aanmaken</label>
      <div class="row2" id="db-admin" hidden>
        <label>Beheerder (bv. root)<input name="admin_user" value="root" autocomplete="off"></label>
        <label>Wachtwoord beheerder<input name="admin_pass" type="password" autocomplete="off"></label>
        <p class="hint" style="grid-column:1/-1;margin-top:-.4rem">Wordt alleen eenmalig gebruikt en niet opgeslagen. Werkt root bij jou via <code>unix_socket</code> (standaard op Debian)? Maak de database dan via de terminal aan — zie de README.</p>
      </div>
      <p class="form-error" hidden></p>
      <div class="btn-row">
        <button type="button" class="btn" data-action="db_test">🔌 Verbinding testen</button>
        <button class="btn primary">Opslaan &amp; tabellen installeren</button>
        <?php if ($dbError === null && is_configured()): ?><button type="button" class="btn" data-action="schema">↻ Tabellen bijwerken</button><?php endif; ?>
      </div>
    </form>

    <form class="card" id="app-form">
      <h2>3. Applicatie &amp; PHP</h2>
      <div class="row2">
        <label>Tijdzone
          <select name="timezone"><?php foreach (timezone_identifiers_list() as $tz): ?><option<?= $tz === $cfg['timezone'] ? ' selected' : '' ?>><?= $e($tz) ?></option><?php endforeach; ?></select>
        </label>
        <label>Basis-URL <span class="hint">(voor agent-instructies)</span><input name="base_url" type="url" value="<?= $e($cfg['base_url']) ?>" placeholder="http://rzmon.lan"></label>
      </div>
      <label>Pad naar ping<input name="ping_bin" value="<?= $e($cfg['ping_bin']) ?>" class="mono"></label>
      <div class="row2">
        <label>memory_limit <span class="hint">nu <?= $e(ini_get('memory_limit')) ?></span><input name="memory_limit" value="<?= $e($cfg['php']['memory_limit'] ?? '') ?>" placeholder="bv. 256M"></label>
        <label>max_execution_time <span class="hint">sec</span><input name="max_execution_time" value="<?= $e($cfg['php']['max_execution_time'] ?? '') ?>" placeholder="bv. 120"></label>
      </div>
      <label class="switch"><input type="checkbox" name="display_errors" <?= ($cfg['php']['display_errors'] ?? '0') === '1' ? 'checked' : '' ?>><span></span> display_errors (PHP-fouten op het scherm tonen)</label>
      <p class="hint">Deze PHP-waarden past RzMon zelf toe (via <code>ini_set</code>). Leeg = waarde uit php.ini. Zet display_errors alleen aan bij het debuggen.</p>
      <p class="form-error" hidden></p>
      <button class="btn primary">Opslaan</button>
    </form>

    <?php $step = 4; if ($dbError === null && is_configured() && $userCount === 0): $step = 5; ?>
      <form class="card" id="admin-form">
        <h2>4. Beheerder aanmaken</h2>
        <div class="row2">
          <label>Gebruikersnaam<input name="username" value="admin" required autocomplete="username"></label>
          <label>Wachtwoord<input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
        </div>
        <p class="form-error" hidden></p>
        <button class="btn primary">Aanmaken &amp; naar dashboard</button>
      </form>
    <?php endif; ?>

    <section class="card">
      <h2><?= $step ?>. Cron</h2>
      <p class="muted small">De checks draaien elke minuut via cron. Plaats deze regel op de server (als root):</p>
      <pre class="code" id="cron-line">echo '* * * * * <?= $e($runAs !== '?' ? $runAs : 'www-data') ?> <?= $e($phpBin) ?> <?= $e($root) ?>/cron/run.php &gt;/dev/null 2&gt;&amp;1' | sudo tee /etc/cron.d/rzmon</pre>
      <button type="button" class="btn" data-copy="#cron-line">Kopieer</button>
    </section>
  </main>

  <dialog id="modal">
    <form method="dialog" class="modal-inner">
      <header><h3 id="modal-title"></h3><button class="icon-btn" value="cancel">✕</button></header>
      <div id="modal-body"></div>
      <footer><button class="btn primary" value="ok">Sluiten</button></footer>
    </form>
  </dialog>
  <div id="toasts"></div>
  <script src="assets/setup.js?v=<?= $v ?>"></script>
</body>
</html>
