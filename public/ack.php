<?php
/**
 * Storing bevestigen via de link in een Pushover-melding (geen login nodig).
 * De link bevat een HMAC-token dat alleen geldt voor deze check en deze storing.
 * Bevestigen gebeurt pas na een klik (POST): linkvoorbeelden in chat-apps openen
 * links soms automatisch en mogen niets bevestigen.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$id = (int) ($_REQUEST['c'] ?? 0);
$token = (string) ($_REQUEST['t'] ?? '');

$check = null;
$state = 'invalid';
if (is_configured() && $id > 0 && $token !== '') {
    $check = q('SELECT c.id, c.name, c.status, c.last_message, c.problem_since, c.acked_at, c.acked_by, c.ack_comment, h.name AS host_name
                FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$id])->fetch() ?: null;
    // Na herstel is problem_since leeg, dus elke oude link is dan ongeldig. Zonder geldig
    // token tonen we niets over de check (anders zijn servernamen te raden via ?c=1,2,3…).
    $expected = $check ? Monitor::ackToken((int) $check['id'], $check['problem_since']) : null;
    if ($check && $expected !== null && hash_equals($expected, $token)) {
        $state = $check['acked_at'] ? 'already' : 'ask';
        if ($state === 'ask' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            Monitor::acknowledge((int) $check['id'], 'link', (string) ($_POST['comment'] ?? ''));
            $state = 'done';
        }
    }
}
if ($state === 'invalid') {
    $check = null;
    usleep(300000);
    http_response_code(404);
}
$labels = ['OK', 'WARNING', 'CRITICAL', 'ONBEKEND'];
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Storing bevestigen – RzMon</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📡</text></svg>">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <main class="login-wrap">
    <div class="card login-card ack-card">
      <div class="brand big"><span class="logo">📡</span> RzMon</div>
      <?php if ($check && $state !== 'invalid'): ?>
        <p class="ack-check s<?= (int) $check['status'] ?>"><span class="dot"></span>
          <span><strong><?= $e($check['host_name']) ?> – <?= $e($check['name']) ?></strong><br>
          <span class="muted small"><?= $e($labels[(int) $check['status']] ?? '') ?>: <?= $e($check['last_message']) ?></span></span></p>
      <?php endif; ?>

      <?php if ($state === 'ask'): ?>
        <form method="post">
          <input type="hidden" name="c" value="<?= (int) $id ?>">
          <input type="hidden" name="t" value="<?= $e($token) ?>">
          <label>Opmerking <span class="hint">(optioneel)</span><input name="comment" maxlength="200" placeholder="bv. ik kijk ernaar"></label>
          <button class="btn primary block">✔ Bevestigen</button>
          <p class="muted small" style="margin-bottom:0">Na bevestigen krijg je voor deze storing geen meldingen meer. Is het weer goed en gaat het later opnieuw mis, dan krijg je wel weer een melding.</p>
        </form>
      <?php elseif ($state === 'done'): ?>
        <p class="ack-ok">✔ Bevestigd. Je krijgt voor deze storing geen meldingen meer.</p>
      <?php elseif ($state === 'already'): ?>
        <p class="ack-ok">✔ Al bevestigd<?= $check['acked_by'] ? ' door ' . $e($check['acked_by']) : '' ?> op <?= $e(date('d-m-Y H:i', strtotime($check['acked_at']))) ?><?= $check['ack_comment'] ? ': ' . $e($check['ack_comment']) : '' ?>.</p>
      <?php else: ?>
        <p class="form-error">Deze link werkt niet (meer). Waarschijnlijk is de storing al opgelost: een bevestig-link geldt alleen zolang de storing waarvoor hij is verstuurd nog bezig is.</p>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
