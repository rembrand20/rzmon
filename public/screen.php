<?php
/**
 * Schermlink (1.337.0): /screen.php?k=<geheime sleutel> (Instellingen → Systeem → Statuspagina, kaart Schermlink).
 * Een pagina voor een scherm aan de muur: Monitoring, Netwerk en IDS om de beurt, zonder inloggen en alleen lezen.
 * Standaard alleen vanaf het eigen netwerk; met een verkeerde sleutel een gewone 404 (niet herkenbaar als RzSense).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
security_headers();

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$key = strtolower((string) ($_GET['k'] ?? ''));
if (!is_configured() || !Screen::allowed($key)) {
    Stealth::denyOutside();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}
I18n::set(I18n::notifyLang());
$d = Screen::data();
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$num = static fn($v) => $v === null ? '–' : (string) round((float) $v);

/** De drie schermen als HTML (ook los op te halen met &part=1, om bij te werken zonder de pagina te herladen) */
ob_start();
?>
<header class="top" data-crit="<?= (int) $d['n']['crit'] ?>" data-rotate="<?= (int) $d['rotate'] ?>">
  <b class="brand">RzSense</b>
  <span class="pill crit<?= $d['n']['crit'] ? '' : ' zero' ?>"><?= (int) $d['n']['crit'] ?> <?= $e($d['n']['crit'] === 1 ? t('storing') : t('storingen')) ?></span>
  <span class="pill warn<?= $d['n']['warn'] ? '' : ' zero' ?>"><?= (int) $d['n']['warn'] ?> <?= $e(t('aandacht')) ?></span>
  <span class="pill ok"><?= (int) $d['n']['ok'] ?> <?= $e(t('in orde')) ?></span>
  <?php if ($d['n']['unk']): ?><span class="pill unk"><?= (int) $d['n']['unk'] ?> <?= $e(t('onbekend')) ?></span><?php endif; ?>
  <span class="fill"></span>
  <span class="muted" id="age" data-at="<?= time() ?>"></span>
  <b class="clock" id="clock"></b>
</header>
<section class="view" data-v="mon">
  <h1><?= $e(t('Monitoring')) ?></h1>
  <?php if (!$d['hosts']): ?><p class="muted big"><?= $e(t('Nog geen servers')) ?></p><?php endif; ?>
  <div class="grid">
    <?php foreach ($d['hosts'] as $h): ?>
    <div class="tile <?= $e($h['st']) ?>"><span class="dot" aria-hidden="true"></span><div><b><?= $e($h['name']) ?></b><?php if ($h['text'] !== ''): ?><small><?= $e($h['text']) ?></small><?php endif; ?></div></div>
    <?php endforeach; ?>
  </div>
</section>
<?php $tl = $d['net']['tiles'] ?? null; if (in_array('net', $d['views'], true) && $tl && ($tl['down'] !== null || $tl['tp'] !== null || $tl['ms'] !== null)): ?>
<section class="view" data-v="net">
  <h1><?= $e(t('Netwerk')) ?></h1>
  <div class="nums">
    <?php if ($tl['down'] !== null): ?>
    <div class="num<?= $tl['plan_down'] && $tl['down'] / $tl['plan_down'] * 100 < $tl['warn_pct'] ? ' warn' : '' ?>"><span><?= $e(t('Internet omlaag')) ?></span><b><?= $e($num($tl['down'])) ?></b><small>Mbit/s<?= $tl['plan_down'] ? ' · ' . $e(t('abonnement {n}', ['n' => $tl['plan_down']])) : '' ?></small></div>
    <div class="num<?= $tl['plan_up'] && $tl['up'] !== null && $tl['up'] / $tl['plan_up'] * 100 < $tl['warn_pct'] ? ' warn' : '' ?>"><span><?= $e(t('Internet omhoog')) ?></span><b><?= $e($num($tl['up'])) ?></b><small>Mbit/s<?= $tl['plan_up'] ? ' · ' . $e(t('abonnement {n}', ['n' => $tl['plan_up']])) : '' ?></small></div>
    <?php endif; ?>
    <?php if ($tl['ms'] !== null): ?><div class="num"><span><?= $e(t('Vertraging')) ?></span><b><?= $e(I18n::dec((string) round((float) $tl['ms'], 1))) ?></b><small>ms<?= $tl['loss'] ? ' · ' . $e(t('{n}% verlies', ['n' => I18n::dec((string) round((float) $tl['loss'], 1))])) : '' ?></small></div><?php endif; ?>
    <?php if ($tl['tp'] !== null): ?><div class="num"><span><?= $e(t('Doorvoer in je netwerk')) ?></span><b><?= $e($num($tl['tp'])) ?></b><small>Mbit/s</small></div><?php endif; ?>
  </div>
  <p class="big <?= $d['net']['problems'] ? 'warn-txt' : 'ok-txt' ?>"><?= $e($d['net']['problems'] ? ($d['net']['problems'] === 1 ? t('1 schakel vraagt aandacht') : t('{n} schakels vragen aandacht', ['n' => $d['net']['problems']])) : t('Geen schakel valt terug')) ?></p>
</section>
<?php endif; ?>
<?php if (in_array('ids', $d['views'], true) && $d['ids']): ?>
<section class="view" data-v="ids">
  <h1>IDS</h1>
  <div class="nums">
    <div class="num"><span><?= $e(t('Geblokkeerd vandaag')) ?></span><b><?= (int) $d['ids']['blk_today'] ?></b><small><?= $e(t('{n} nu geblokkeerd', ['n' => (int) $d['ids']['blk_now']])) ?></small></div>
    <div class="num<?= $d['ids']['open'] ? ' warn' : '' ?>"><span><?= $e(t('Open incidenten')) ?></span><b><?= (int) $d['ids']['open'] ?></b><small><?= $e($d['ids']['open'] ? t('nog te bekijken') : t('alles afgehandeld')) ?></small></div>
  </div>
  <div class="list">
    <?php foreach ($d['ids']['last'] as $i): ?>
    <div class="row <?= $i['sev'] === 2 ? 'crit' : 'warn' ?>"><span class="dot" aria-hidden="true"></span><b><?= $e($i['title']) ?></b><small><?= $e(date('d-m H:i', strtotime($i['at']))) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
if (!empty($_GET['part'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo $body;
    exit;
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="<?= $e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<?= Brand::icons() ?>
<title>RzSense</title>
<style>
:root { --bg: #0d1117; --card: #161b24; --text: #e8ecf3; --muted: #8d97a8; --border: #252c3a; --ok: #34c27a; --warn: #e6a52e; --crit: #f05d5d; --unk: #7c8698; }
@media (prefers-color-scheme: light) { :root { --bg: #f4f6f9; --card: #fff; --text: #1b2230; --muted: #667085; --border: #e1e5ec; --ok: #17995a; --warn: #c98600; --crit: #d43c3c; --unk: #8a93a3; } }
* { box-sizing: border-box; }
html, body { height: 100%; }
body { margin: 0; background: var(--bg); color: var(--text); font: clamp(15px, 1.25vw, 24px)/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; overflow: hidden; }
#app { height: 100%; display: flex; flex-direction: column; padding: 1.2em 1.6em 2.6em; gap: 1em; }
.top { display: flex; align-items: center; gap: .7em; flex-wrap: wrap; }
.brand { font-size: 1.15em; margin-right: .4em; }
.fill { flex: 1 1 auto; }
.pill { padding: .2em .8em; border-radius: 99px; font-weight: 600; border: 1px solid var(--border); background: var(--card); }
.pill.crit { color: #fff; background: var(--crit); border-color: var(--crit); }
.pill.warn { color: #1b1300; background: var(--warn); border-color: var(--warn); }
.pill.ok { color: var(--ok); }
.pill.zero { color: var(--muted); background: var(--card); border-color: var(--border); font-weight: 400; }
.clock { font-size: 1.3em; font-variant-numeric: tabular-nums; }
.muted { color: var(--muted); }
.view { flex: 1 1 auto; min-height: 0; display: none; flex-direction: column; gap: .9em; }
.view.on { display: flex; }
h1 { margin: 0; font-size: 1.05em; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(15em, 1fr)); gap: .7em; overflow: hidden; align-content: start; }
.tile, .row { background: var(--card); border: 1px solid var(--border); border-radius: .7em; padding: .75em .95em; display: flex; align-items: center; gap: .7em; min-width: 0; }
.tile div { min-width: 0; display: flex; flex-direction: column; }
.tile b, .row b { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tile small, .row small, .num small, .num span { color: var(--muted); font-size: .8em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dot { width: .8em; height: .8em; border-radius: 50%; background: var(--ok); flex: none; }
.warn .dot { background: var(--warn); border-radius: 2px; }
.crit .dot { background: var(--crit); border-radius: 0; clip-path: polygon(50% 0, 100% 100%, 0 100%); }
.unk .dot { background: none; border: 2px solid var(--unk); }
.tile.crit { border-color: var(--crit); background: color-mix(in srgb, var(--crit) 16%, var(--card)); font-size: 1.15em; grid-column: span 2; }
.tile.warn { border-color: var(--warn); background: color-mix(in srgb, var(--warn) 10%, var(--card)); }
.nums { display: grid; grid-template-columns: repeat(auto-fit, minmax(12em, 1fr)); gap: .8em; }
.num { background: var(--card); border: 1px solid var(--border); border-radius: .8em; padding: 1em 1.2em; display: flex; flex-direction: column; gap: .15em; }
.num b { font-size: 3.2em; line-height: 1.05; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
.num.warn b { color: var(--warn); }
.big { font-size: 1.3em; margin: 0; }
.ok-txt { color: var(--ok); } .warn-txt { color: var(--warn); }
.list { display: grid; gap: .5em; overflow: hidden; }
.row b { flex: 1 1 auto; }
.foot { position: fixed; left: 0; right: 0; bottom: 0; padding: .6em 1.6em; color: var(--muted); font-size: .75em; display: flex; gap: 1.2em; align-items: center; }
.foot i { flex: 1 1 auto; height: 3px; background: var(--border); border-radius: 2px; overflow: hidden; }
.foot i u { display: block; height: 100%; width: 0; background: var(--muted); }
.stale .top { opacity: .55; }
@media (max-width: 700px) { body { overflow: auto; } #app { height: auto; } .tile.crit { grid-column: auto; } }
</style>
</head>
<body>
<div id="app"><?= $body ?></div>
<div class="foot"><span id="rot"></span><i><u id="bar"></u></i><span id="stale" hidden><?= $e(t('geen verbinding met RzSense')) ?></span></div>
<script nonce="<?= $e(csp_nonce()) ?>">
(function () {
  var app = document.getElementById('app'), idx = 0, since = Date.now(), crit = -1, lock = null, fails = 0;
  var L = { rot: <?= json_encode(t('wisselt elke {n} s'), JSON_HEX_TAG | JSON_HEX_AMP) ?>, ago: <?= json_encode(t('laatste meting {n} s geleden'), JSON_HEX_TAG | JSON_HEX_AMP) ?> };
  function views() { return Array.prototype.slice.call(app.querySelectorAll('.view')); }
  function rotate() { var t = app.querySelector('.top'); return t ? +t.dataset.rotate : 0; }
  function show(i) {
    var v = views();
    if (!v.length) return;
    idx = (i % v.length + v.length) % v.length;
    v.forEach(function (s, n) { s.classList.toggle('on', n === idx); });
    since = Date.now();
    document.getElementById('rot').textContent = v.length > 1 && rotate() ? '⟳ ' + L.rot.replace('{n}', rotate()) + ': ' + v.map(function (s) { return s.querySelector('h1').textContent; }).join(' · ') : '';
  }
  function tick() {
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; }, c = document.getElementById('clock'), a = document.getElementById('age');
    if (c) c.textContent = p(d.getHours()) + ':' + p(d.getMinutes());
    if (a) a.textContent = L.ago.replace('{n}', Math.max(0, Math.round(Date.now() / 1000 - a.dataset.at)));
    var r = rotate(), n = views().length;
    document.getElementById('bar').style.width = r && n > 1 ? Math.min(100, (Date.now() - since) / (r * 10)) + '%' : '0';
    if (r && n > 1 && Date.now() - since >= r * 1000) show(idx + 1);
  }
  function load() {
    fetch(location.pathname + location.search + '&part=1', { cache: 'no-store', credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); }).then(function (html) {
      fails = 0;
      document.body.classList.remove('stale'); document.getElementById('stale').hidden = true;
      var cur = views()[idx], name = cur ? cur.dataset.v : 'mon';
      app.innerHTML = html;
      var v = views(), i = Math.max(0, v.findIndex(function (s) { return s.dataset.v === name; })), t = app.querySelector('.top'), now = t ? +t.dataset.crit : 0, keep = since;
      // een nieuwe storing: meteen naar Monitoring
      if (crit >= 0 && now > crit) { show(0); } else { show(i); since = keep; }
      crit = now;
    }).catch(function () { if (++fails >= 2) { document.body.classList.add('stale'); document.getElementById('stale').hidden = false; } });
  }
  function wake() { try { if (navigator.wakeLock && document.visibilityState === 'visible') navigator.wakeLock.request('screen').then(function (l) { lock = l; }).catch(function () {}); } catch (e) { /* niet beschikbaar */ } }
  var t0 = app.querySelector('.top');
  crit = t0 ? +t0.dataset.crit : 0;
  show(0); tick(); wake();
  setInterval(tick, 1000);
  setInterval(load, 15000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { wake(); load(); } });
})();
</script>
</body>
</html>
