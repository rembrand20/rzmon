<?php
/**
 * Statuspagina (1.107.0): /status.php?k=<geheime sleutel> (Instellingen → Koppelingen → Statuspagina).
 * Werkt ook van buiten, maar alleen met de juiste sleutel; anders een gewone 404 (niet herkenbaar als RzMon).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$key = strtolower((string) ($_GET['k'] ?? ''));
if (!is_configured() || !StatusPage::on() || !preg_match('/^[a-f0-9]{32}$/', $key) || !hash_equals((string) setting('status_token', ''), $key)) {
    Stealth::denyOutside();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}
I18n::set(I18n::notifyLang());
$d = StatusPage::data();
$word = ['ok' => t('Werkt'), 'warn' => t('Verstoord'), 'crit' => t('Storing'), 'maint' => t('Onderhoud'), 'unknown' => t('Onbekend')];
$head = ['ok' => t('Alles werkt'), 'warn' => t('Gedeeltelijk verstoord'), 'crit' => t('Er is een storing'), 'maint' => t('Onderhoud'), 'unknown' => t('Status onbekend')];
$icon = ['ok' => '✓', 'warn' => '!', 'crit' => '✕', 'maint' => '🔧', 'unknown' => '?'];
$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$dayCls = static fn($v) => $v === null ? 'none' : ($v >= 0.999 ? 'ok' : ($v >= 0.99 ? 'ok2' : ($v >= 0.95 ? 'warn' : 'crit')));
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="<?= $e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="60">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($d['title']) ?></title>
<style>
:root { --bg: #f5f6f8; --card: #fff; --text: #1d2330; --muted: #6b7280; --border: #e3e6eb; --ok: #1a9e5c; --ok2: #7cc59c; --warn: #d08a00; --crit: #d43c3c; --none: #e3e6eb; --maint: #5b6ee1; }
@media (prefers-color-scheme: dark) { :root { --bg: #0f1218; --card: #171b23; --text: #e6e9ef; --muted: #8b93a3; --border: #262c38; --ok: #34c27a; --ok2: #2f7d55; --warn: #e3a52b; --crit: #ef5b5b; --none: #262c38; --maint: #7c8cf0; } }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 760px; margin: 0 auto; padding: 32px 16px 48px; }
h1 { font-size: 1.5rem; margin: 0 0 1rem; }
.overall { display: flex; align-items: center; gap: .8rem; padding: 1rem 1.2rem; border-radius: 12px; color: #fff; font-weight: 600; font-size: 1.1rem; margin-bottom: 1rem; }
.overall.ok { background: var(--ok); } .overall.warn { background: var(--warn); } .overall.crit { background: var(--crit); } .overall.maint, .overall.unknown { background: var(--maint); }
.overall .ic { width: 1.8rem; height: 1.8rem; border-radius: 50%; background: rgba(255, 255, 255, .25); display: grid; place-items: center; }
.note { background: var(--card); border: 1px solid var(--border); border-left: 4px solid var(--maint); border-radius: 10px; padding: .8rem 1rem; margin-bottom: 1rem; white-space: pre-line; }
.comp { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1rem 1.2rem; margin-bottom: .8rem; }
.comp-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.comp-name { font-weight: 600; }
.st { font-size: .9rem; font-weight: 600; } .st.ok { color: var(--ok); } .st.warn { color: var(--warn); } .st.crit { color: var(--crit); } .st.maint, .st.unknown { color: var(--maint); }
.bars { display: flex; gap: 2px; height: 32px; margin: .7rem 0 .3rem; }
.bars i { flex: 1; border-radius: 2px; background: var(--none); min-width: 2px; }
.bars i.ok { background: var(--ok); } .bars i.ok2 { background: var(--ok2); } .bars i.warn { background: var(--warn); } .bars i.crit { background: var(--crit); }
.legend { display: flex; justify-content: space-between; font-size: .75rem; color: var(--muted); }
footer { color: var(--muted); font-size: .8rem; text-align: center; margin-top: 1.5rem; }
@media (max-width: 520px) { .bars i:nth-child(-n+60) { display: none; } }
</style>
</head>
<body>
<main>
  <h1><?= $e($d['title']) ?></h1>
  <div class="overall <?= $e($d['overall']) ?>"><span class="ic"><?= $icon[$d['overall']] ?></span><?= $e($head[$d['overall']]) ?></div>
  <?php if ($d['note'] !== ''): ?><div class="note"><?= $e($d['note']) ?></div><?php endif; ?>
  <?php foreach ($d['components'] as $c): ?>
  <section class="comp">
    <div class="comp-head"><span class="comp-name"><?= $e($c['label']) ?></span><span class="st <?= $e($c['now']) ?>"><?= $e($word[$c['now']]) ?></span></div>
    <div class="bars" role="img" aria-label="<?= $e(t('Beschikbaarheid per dag, laatste {n} dagen', ['n' => StatusPage::DAYS])) ?>">
      <?php foreach ($c['days'] as $day => $v): ?><i class="<?= $dayCls($v) ?>" title="<?= $e(date('d-m-Y', strtotime($day)) . ' · ' . ($v === null ? t('geen gegevens') : I18n::dec((string) round($v * 100, 2)) . '%')) ?>"></i><?php endforeach; ?>
    </div>
    <div class="legend"><span class="d90"><?= $e(t('{n} dagen geleden', ['n' => StatusPage::DAYS])) ?></span><span><?= $c['pct'] === null ? '' : $e(I18n::dec((string) $c['pct']) . '% ' . t('beschikbaar')) ?></span><span><?= $e(t('vandaag')) ?></span></div>
  </section>
  <?php endforeach; ?>
  <?php if (!$d['components']): ?><p class="note"><?= $e(t('Er zijn nog geen onderdelen ingesteld.')) ?></p><?php endif; ?>
  <footer><?= $e(t('Bijgewerkt {t}', ['t' => date('d-m-Y H:i', strtotime($d['updated']))])) ?></footer>
</main>
</body>
</html>
