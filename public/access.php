<?php
/**
 * 1.327.0: Toegang aanvragen van buiten. De reverse proxy laat de site van RzSense alleen toe vanaf je eigen netwerk of
 * een toegestaan apparaat; een onbekend apparaat krijgt daar een 403 met de knop "Toegang aanvragen", die hierheen gaat (dit
 * ene pad laat de proxy altijd door). Hier vult de aanvrager zijn naam in; RzSense zet het apparaat op de lijst Wachtende
 * apparaten en stuurt een melding. De pagina wacht daarna tot een beheerder het apparaat toestaat en opent dan RzSense.
 * Niets op deze pagina noemt RzSense: van buiten blijft onherkenbaar wat hier draait.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/auth.php';
security_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: same-origin');   // zo stuurt de browser bij het formulier het echte Origin mee (bij no-referrer: "null")

$cookieLang = (string) ($_COOKIE[I18n::COOKIE] ?? '');
I18n::set(in_array($cookieLang, I18n::LANGS, true) ? $cookieLang : I18n::browser());
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
if (!is_configured()) {
    http_response_code(404);
    exit;
}

// Status voor de wachtpagina (elke 10 s): wachtend, toegestaan (dan ook de proxy-sleutel), afgewezen
if (isset($_GET['st'])) {
    header('Content-Type: application/json');
    $r = Stealth::myRequest();
    $st = $r ? (string) $r['status'] : 'none';
    $ready = false;
    if ($st === 'approved' && ($dev = Stealth::device())) {
        if (ProxyGuard::enabled()) {
            ProxyOpen::deviceSeen($dev);
        }
        $ready = !ProxyOpen::behindProxy() || ProxyOpen::synced();
    }
    echo json_encode(['state' => $st === 'ignored' ? 'denied' : $st, 'ready' => $ready]);
    exit;
}

$inside = !Stealth::outside();
$err = '';
if (!$inside && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Alleen vanaf deze pagina zelf (geen formulier van een andere site)
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $sameSite = ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'same-origin'
        && ($origin === '' || strtolower((string) parse_url($origin, PHP_URL_HOST)) === strtolower((string) parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)));
    if (!$sameSite) {
        $err = t('Deze aanvraag kwam niet van deze pagina.');
    } else {
        $info = ['tz' => (string) ($_POST['tz'] ?? ''), 'screen' => (string) ($_POST['screen'] ?? ''), 'platform' => (string) ($_POST['platform'] ?? ''), 'touch' => (string) ($_POST['touch'] ?? '')];
        $err = Stealth::requestAccess((string) ($_POST['name'] ?? ''), $info) ?? '';
        if ($err === '') {
            header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/access.php'), '?'), true, 303);
            exit;
        }
    }
}

$req = $inside ? null : Stealth::myRequest();
$state = $inside ? 'inside' : (Stealth::device() ? 'allowed' : ($req ? ($req['status'] === 'ignored' ? 'denied' : (string) $req['status']) : 'form'));
if ($state === 'allowed') {
    header('Location: ./');
    exit;
}
if ($state === 'form' && setting('stealth_requests', '1') !== '1') {
    $state = 'off';
}
if ($state === 'form' && !Stealth::requestCountryOk()) {
    $state = 'country';
}
$nonce = csp_nonce();
?><!doctype html>
<html lang="<?= $e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e(t('Toegang aanvragen')) ?></title>
<style>
body{font:16px/1.5 system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6fa;color:#1f2633}
main{max-width:32rem;width:calc(100% - 2rem);box-sizing:border-box;margin:1rem;padding:2rem;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08)}
h1{font-size:1.3rem;margin:0 0 .6rem}p{margin:.4rem 0}.m{color:#5b6475}
label{display:block;font-weight:600;margin:1rem 0 .3rem}
input[type=text]{font:inherit;width:100%;box-sizing:border-box;padding:.65rem .8rem;border:1px solid #cdd3de;border-radius:10px;background:#fff;color:inherit}
button,.b{font:inherit;font-weight:600;display:inline-block;margin-top:1rem;padding:.7rem 1.2rem;border:0;border-radius:10px;background:#2f6fed;color:#fff;cursor:pointer;text-decoration:none}
.err{color:#b42b2b;font-weight:600}.k{font-size:.85rem;color:#5b6475;margin-top:1.4rem}
.spin{display:inline-block;width:14px;height:14px;border:2px solid #2f6fed;border-right-color:transparent;border-radius:50%;animation:s 1s linear infinite;vertical-align:-2px;margin-right:.4rem}
@keyframes s{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.spin{animation:none}}
@media(prefers-color-scheme:dark){body{background:#12151c;color:#e6e9ef}main{background:#1b2029}.m,.k{color:#9aa3b2}input[type=text]{background:#12151c;border-color:#323a48}}
</style>
</head>
<body><main>
<?php if ($state === 'inside'): ?>
  <h1>&#127968; <?= $e(t('Je bent op je eigen netwerk')) ?></h1>
  <p class="m"><?= $e(t('Hier heb je geen aanvraag nodig.')) ?></p>
  <a class="b" href="./"><?= $e(t('Verder')) ?></a>
<?php elseif ($state === 'pending'): ?>
  <h1>&#9203; <?= $e(t('Je aanvraag is verstuurd')) ?></h1>
  <p><?= $e(t('Een beheerder krijgt een melding en moet dit apparaat toestaan.')) ?></p>
  <p class="m" id="st"><span class="spin" aria-hidden="true"></span><?= $e(t('Deze pagina gaat vanzelf verder zodra dat gebeurd is.')) ?></p>
  <p class="k"><?= $e(t('Aangevraagd als {name}', ['name' => (string) $req['name']])) ?> &middot; <?= $e(client_ip()) ?></p>
  <script nonce="<?= $e($nonce) ?>">
  (function () {
    var st = document.getElementById('st');
    var msg = { approved: <?= json_encode(t('Toegestaan! Nog even geduld, de toegang wordt doorgevoerd…')) ?>, denied: <?= json_encode(t('Je aanvraag is afgewezen.')) ?> };
    function poll() {
      fetch('?st=1', { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.state === 'approved' && d.ready) { location.href = './'; return; }
        if (msg[d.state]) st.textContent = msg[d.state];
        if (d.state !== 'denied') setTimeout(poll, 10000);
      }).catch(function () { setTimeout(poll, 15000); });
    }
    setTimeout(poll, 5000);
  })();
  </script>
<?php elseif ($state === 'approved'): ?>
  <h1>&#9989; <?= $e(t('Toegestaan')) ?></h1>
  <p class="m"><?= $e(t('Je apparaat is toegestaan. Open de pagina opnieuw om in te loggen.')) ?></p>
  <a class="b" href="./"><?= $e(t('Verder')) ?></a>
<?php elseif ($state === 'denied'): ?>
  <h1>&#128683; <?= $e(t('Aanvraag afgewezen')) ?></h1>
  <p class="m"><?= $e(t('Dit apparaat heeft geen toegang gekregen.')) ?></p>
<?php elseif ($state === 'off' || $state === 'country'): ?>
  <h1>&#128274; <?= $e(t('Geen toegang')) ?></h1>
  <p class="m"><?= $e($state === 'off' ? t('Toegang aanvragen staat uit.') : t('Vanaf deze plek kun je geen toegang aanvragen.')) ?></p>
<?php else: ?>
  <h1>&#128274; <?= $e(t('Toegang aanvragen')) ?></h1>
  <p class="m"><?= $e(t('Dit apparaat heeft nog geen toegang. Vul je naam in; een beheerder krijgt een melding en kan dit apparaat toestaan.')) ?></p>
  <?php if ($err !== ''): ?><p class="err" role="alert"><?= $e($err) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="n"><?= $e(t('Je naam')) ?></label>
    <input type="text" id="n" name="name" required minlength="2" maxlength="60" placeholder="<?= $e(t('bv. Sanne, laptop thuis')) ?>">
    <input type="hidden" name="tz" id="tz"><input type="hidden" name="screen" id="sc"><input type="hidden" name="platform" id="pf"><input type="hidden" name="touch" id="to">
    <button type="submit"><?= $e(t('Toegang aanvragen')) ?></button>
  </form>
  <p class="k"><?= $e(t('Je IP-adres en browser gaan mee met de aanvraag.')) ?> &middot; <?= $e(client_ip()) ?></p>
  <script nonce="<?= $e($nonce) ?>">
  (function () {
    try { document.getElementById('tz').value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
    document.getElementById('sc').value = screen.width + '×' + screen.height;
    document.getElementById('pf').value = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
    document.getElementById('to').value = navigator.maxTouchPoints > 0 ? '1' : '';
  })();
  </script>
<?php endif; ?>
</main></body></html>
