<?php
/**
 * RzMon setup: database, applicatie- en PHP-instellingen, eerste beheerder.
 *
 * Toegang:
 *  - Nog niet geïnstalleerd (of database onbereikbaar): alleen vanaf een privé-netwerk (LAN/localhost).
 *  - Geïnstalleerd: door naar de beheerportal (Instellingen → Systeem, 1.92.0); daar staat alles van deze pagina.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';

if (is_configured()) {
    try {
        Stealth::gate();   // van buiten alleen op een toegestaan apparaat (1.86.0)
    } catch (Throwable) {
    }
}
start_session();
I18n::init(null);

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
    $setupUser = current_user();
    I18n::init($setupUser);
    if (!$setupUser) {
        header('Location: index.php');
        exit;
    }
    if (!has_role($setupUser, 'admin')) {
        http_response_code(403);
        exit(t('Setup is alleen voor beheerders.'));
    }
    header('Location: index.php#settings/syscheck');
    exit;
} elseif (!is_private_client()) {
    http_response_code(403);
    exit(t('Setup is alleen bereikbaar vanaf het lokale netwerk zolang RzMon niet volledig is ingesteld.'));
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
        $send(['error' => t('Ongeldig verzoek (CSRF)')], 403);
    }
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];

    try {
        switch ($_GET['action']) {
            case 'db_test':
                $send(Setup::dbTest($in));

            case 'db_save':
                $send(Setup::dbSave($in, !empty($in['create'])));

            case 'schema':
                Installer::runSchema();
                $send(['ok' => true, 'message' => t('Tabellen bijgewerkt')]);

            case 'app_save':
                $send(Setup::appSave($in));

            case 'admin_create':
                if (Installer::userCount() > 0) {
                    $send(['error' => t('Er bestaat al een beheerder')], 409);
                }
                Installer::saveUser(trim((string) ($in['username'] ?? '')), (string) ($in['password'] ?? ''));
                Installer::seedLocalHost();
                login(trim((string) $in['username']), (string) $in['password']);
                $send(['ok' => true]);

            default:
                $send(['error' => t('Onbekende actie')], 404);
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
$checks = Setup::checks($installed, $dbError);
$phpInfo = Setup::phpInfo();
$cfg = Installer::mergedConfig([]);
$csrf = csrf_token();
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$v = filemtime(__DIR__ . '/assets/setup.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="<?= I18n::lang() ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $e($csrf) ?>">
  <title>RzMon Setup</title>
  <?= Brand::head() ?>
  <link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
  <script>try{const t=localStorage.getItem('rzmon-theme');if(t)document.documentElement.dataset.theme=t}catch(e){}</script>
</head>
<body>
  <header class="topbar">
    <div class="brand"><?= Brand::logo() ?> <span class="version">v<?= htmlspecialchars(RZMON_VERSION) ?></span> <span class="badge">setup</span></div>
  </header>

  <main class="container setup">
      <div class="card intro">
        <h2><?= th('Welkom bij RzMon 👋') ?></h2>
        <p class="muted"><?= th('Loop de stappen hieronder door: database koppelen, instellingen kiezen en een beheerder aanmaken. Deze pagina is alleen bereikbaar vanaf je lokale netwerk zolang de installatie niet klaar is.') ?></p>
        <?php if (is_configured() && $dbError): ?><p class="form-error"><?= th('Database niet bereikbaar:') ?> <?= $e($dbError) ?></p><?php endif; ?>
      </div>

    <section class="card">
      <h2><?= th('1. Systeemcheck') ?></h2>
      <ul class="checklist">
        <?php foreach ($checks as [$label, $ok, $detail]): ?>
          <li class="<?= $ok === true ? 'ok' : ($ok === null ? 'warn' : 'bad') ?>">
            <span class="ic"><?= $ok === true ? '✓' : ($ok === null ? '!' : '✕') ?></span>
            <span><strong><?= $e($label) ?></strong><br><span class="muted small"><?= $e($detail) ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <details class="small api-docs">
        <summary><?= th('PHP-omgeving') ?></summary>
        <table class="kv">
          <?php foreach ($phpInfo as $k => $val): ?><tr><th><?= $e($k) ?></th><td class="mono"><?= $e($val) ?></td></tr><?php endforeach; ?>
        </table>
      </details>
    </section>

    <form class="card" id="db-form">
      <h2><?= th('2. Database (MariaDB)') ?></h2>
      <div class="row3">
        <label><?= th('Host') ?><input name="db_host" value="<?= $e($cfg['db']['host']) ?>" required></label>
        <label><?= th('Poort') ?><input name="db_port" type="number" value="<?= $e($cfg['db']['port'] ?? 3306) ?>" min="1" max="65535"></label>
        <label><?= th('Database') ?><input name="db_name" value="<?= $e($cfg['db']['name']) ?>" required pattern="\w+"></label>
      </div>
      <div class="row2">
        <label><?= th('Gebruiker') ?><input name="db_user" value="<?= $e($cfg['db']['user']) ?>" required autocomplete="off"></label>
        <label><?= th('Wachtwoord') ?> <span class="hint"><?= ($cfg['db']['pass'] ?? '') !== '' ? th('(leeg = ongewijzigd)') : '' ?></span>
          <input name="db_pass" type="password" autocomplete="new-password"></label>
      </div>
      <label class="switch"><input type="checkbox" name="create" id="db-create"><span></span> <?= th('Database en gebruiker voor mij aanmaken') ?></label>
      <div class="row2" id="db-admin" hidden>
        <label><?= th('Beheerder (bv. root)') ?><input name="admin_user" value="root" autocomplete="off"></label>
        <label><?= th('Wachtwoord beheerder') ?><input name="admin_pass" type="password" autocomplete="off"></label>
        <p class="hint" style="grid-column:1/-1;margin-top:-.4rem"><?= t('Wordt alleen eenmalig gebruikt en niet opgeslagen. Werkt root bij jou via <code>unix_socket</code> (standaard op Debian)? Maak de database dan via de terminal aan — zie de README.') ?></p>
      </div>
      <p class="form-error" hidden></p>
      <div class="btn-row">
        <button type="button" class="btn" data-action="db_test"><?= th('🔌 Verbinding testen') ?></button>
        <button class="btn primary"><?= t('Opslaan &amp; tabellen installeren') ?></button>
        <?php if ($dbError === null && is_configured()): ?><button type="button" class="btn" data-action="schema"><?= th('↻ Tabellen bijwerken') ?></button><?php endif; ?>
      </div>
    </form>

    <form class="card" id="app-form">
      <h2><?= t('3. Applicatie &amp; PHP') ?></h2>
      <div class="row2">
        <label><?= th('Tijdzone') ?>
          <select name="timezone"><?php foreach (timezone_identifiers_list() as $tz): ?><option<?= $tz === $cfg['timezone'] ? ' selected' : '' ?>><?= $e($tz) ?></option><?php endforeach; ?></select>
        </label>
        <label><?= th('Basis-URL') ?> <span class="hint"><?= th('(voor agent-instructies)') ?></span><input name="base_url" type="url" value="<?= $e($cfg['base_url']) ?>" placeholder="http://rzmon.lan"></label>
      </div>
      <label><?= th('Pad naar ping') ?><input name="ping_bin" value="<?= $e($cfg['ping_bin']) ?>" class="mono"></label>
      <div class="row2">
        <label>memory_limit <span class="hint"><?= th('nu') ?> <?= $e(ini_get('memory_limit')) ?></span><input name="memory_limit" value="<?= $e($cfg['php']['memory_limit'] ?? '') ?>" placeholder="<?= th('bv. 256M') ?>"></label>
        <label>max_execution_time <span class="hint"><?= th('sec') ?></span><input name="max_execution_time" value="<?= $e($cfg['php']['max_execution_time'] ?? '') ?>" placeholder="<?= th('bv. 120') ?>"></label>
      </div>
      <label class="switch"><input type="checkbox" name="display_errors" <?= ($cfg['php']['display_errors'] ?? '0') === '1' ? 'checked' : '' ?>><span></span> <?= th('display_errors (PHP-fouten op het scherm tonen)') ?></label>
      <p class="hint"><?= t('Deze PHP-waarden past RzMon zelf toe (via <code>ini_set</code>). Leeg = waarde uit php.ini. Zet display_errors alleen aan bij het debuggen.') ?></p>
      <p class="form-error" hidden></p>
      <button class="btn primary"><?= th('Opslaan') ?></button>
    </form>

    <?php $step = 4; if ($dbError === null && is_configured() && $userCount === 0): $step = 5; ?>
      <form class="card" id="admin-form">
        <h2><?= th('4. Beheerder aanmaken') ?></h2>
        <div class="row2">
          <label><?= th('Gebruikersnaam') ?><input name="username" value="admin" required autocomplete="username"></label>
          <label><?= th('Wachtwoord') ?><input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
        </div>
        <p class="form-error" hidden></p>
        <button class="btn primary"><?= t('Aanmaken &amp; naar dashboard') ?></button>
      </form>
    <?php endif; ?>

    <section class="card">
      <h2><?= $step ?>. Cron</h2>
      <p class="muted small"><?= th('De checks draaien elke minuut via cron. Plaats deze regel op de server (als root):') ?></p>
      <pre class="code" id="cron-line"><?= $e(Setup::cronLine()) ?></pre>
      <button type="button" class="btn" data-copy="#cron-line"><?= th('Kopieer') ?></button>
    </section>
  </main>

  <dialog id="modal">
    <form method="dialog" class="modal-inner">
      <header><h3 id="modal-title"></h3><button class="icon-btn" value="cancel">✕</button></header>
      <div id="modal-body"></div>
      <footer><button class="btn primary" value="ok"><?= th('Sluiten') ?></button></footer>
    </form>
  </dialog>
  <div id="toasts"></div>
  <script>window.I18N = <?= I18n::lang() === 'en' ? json_encode(I18n::dict(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) : '{}' ?>;</script>
  <script src="assets/setup.js?v=<?= $v ?>"></script>
</body>
</html>