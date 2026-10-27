<?php
/**
 * PHP-tests (GitHub Actions, met een lege MariaDB): de rekenkant van slimme drempels, bundelen, zelf herstellen,
 * de statuspagina en Analyse, en dat een update geen instellingen verandert.
 * Gebruik: php tests/php/run.php   (op een testdatabase: de tests voegen checks en meetwaarden toe)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Alleen via CLI\n");
}
require __DIR__ . '/../../src/bootstrap.php';
I18n::set('nl');

$fails = 0;
$n = 0;
function ok(bool $cond, string $what): void
{
    global $fails, $n;
    $n++;
    if ($cond) {
        echo "  ✔ $what\n";
    } else {
        $fails++;
        echo "  ✘ $what\n";
    }
}
function section(string $s): void
{
    echo "\n$s\n";
}

section('Database en update');
Installer::runSchema();
save_setting('thresh_default', 'manual');
Installer::runSchema();
ok(setting('thresh_default') === 'manual' || q("SELECT value FROM settings WHERE name = 'thresh_default'")->fetchColumn() === 'manual', 'een update laat een gewijzigde instelling staan');
q("INSERT INTO hosts (name, address, mode) VALUES ('ci-a', '10.0.0.1', 'agent'), ('ci-b', '10.0.0.2', 'agent'), ('ci-gw', '10.0.0.254', 'remote')");
$ha = (int) q("SELECT id FROM hosts WHERE name = 'ci-a'")->fetchColumn();
$hb = (int) q("SELECT id FROM hosts WHERE name = 'ci-b'")->fetchColumn();
$gw = (int) q("SELECT id FROM hosts WHERE name = 'ci-gw'")->fetchColumn();
q('INSERT INTO checks (host_id, type, name, warn_threshold, crit_threshold) VALUES (?, ?, ?, 85, 95)', [$ha, 'cpu', 'CPU']);
$cpu = (int) db()->lastInsertId();
ok(q('SELECT thresh_mode FROM checks WHERE id = ?', [$cpu])->fetchColumn() === 'auto', 'een nieuwe check begint op "auto"');

section('Slimme drempels');
mt_srand(7);
$rows = [];
for ($t = time() - 28 * 86400; $t < time(); $t += 600) {
    $h = (int) date('G', $t);
    $v = 20 + 15 * sin(($h - 6) / 24 * M_PI) + mt_rand(0, 80) / 10;
    if ($h === 3) {
        $v = 92 + mt_rand(0, 70) / 10;   // back-up 's nachts
    }
    if (time() - $t < 7 * 86400) {
        $v += 12;                        // sluipende stijging
    }
    $rows[] = sprintf('(%d, 0, %.2f, %s)', $cpu, $v, db()->quote(date('Y-m-d H:i:s', $t)));
}
foreach (array_chunk($rows, 1000) as $ch) {
    q('INSERT INTO results (check_id, status, value, created_at) VALUES ' . implode(',', $ch));
}
q("UPDATE checks SET thresh_mode = 'manual' WHERE id = ?", [$cpu]);
$m = Smart::learn(q('SELECT * FROM checks WHERE id = ?', [$cpu])->fetch());
$mon3 = 3;          // maandag 3:00
$mon14 = 14;        // maandag 14:00
ok($m !== null && $m['days'] >= 7, 'leert uit 28 dagen meetwaarden');
ok($m['w'][$mon3] > 100, 'de back-up om 3:00 is normaal: warning boven 100% (' . round($m['w'][$mon3]) . ')');
ok($m['w'][$mon14] >= 50 && $m['w'][$mon14] < 85, 'overdag een warning tussen 50 en 85% (' . round($m['w'][$mon14]) . ')');
ok(($m['sim']['fixed'] ?? 0) > ($m['sim']['smart'] ?? 99), 'slim geeft minder dagen met een warning dan vast (' . $m['sim']['fixed'] . ' → ' . $m['sim']['smart'] . ')');
ok($m['drift'] !== null, 'de sluipende stijging wordt gezien');
$row = q('SELECT c.*, h.mode FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$cpu])->fetch();
$row['thresh_mode'] = 'guard';
$row['guard_threshold'] = 90;
$eff = Smart::apply($row);
ok((float) $eff['crit_threshold'] <= 90, 'het vangnet geldt altijd (critical ≤ 90)');
ok(!Smart::eligible(['type' => 'disk']), 'disks leren niet');
ok(Smart::eligible(['type' => 'snmp', 'metric' => 'temp']) && !Smart::eligible(['type' => 'snmp', 'metric' => 'uptime']), 'SNMP: temperatuur wel, uptime niet');
ok(!Smart::eligible(['type' => 'ping', 'reverse_ok' => 1]), 'omgekeerde checks leren niet');
q("UPDATE checks SET thresh_mode = 'auto' WHERE id = ?", [$cpu]);
save_setting('thresh_default', 'guard');
$r2 = Smart::apply(q('SELECT * FROM checks WHERE id = ?', [$cpu])->fetch() + ['hid' => $ha]);
ok(q('SELECT thresh_mode FROM checks WHERE id = ?', [$cpu])->fetchColumn() !== 'auto', '"auto" wordt bij de eerste run de standaard');

section('Bundelen');
q('UPDATE hosts SET parent_id = ? WHERE id IN (?, ?)', [$gw, $ha, $hb]);
$items = [];
foreach ([$ha, $hb] as $h) {
    $items[] = ['item' => ['status' => STATUS_CRIT], 'check' => ['hid' => $h]];
}
$cause = Bundle::cause($items);
ok($cause !== null && str_contains($cause, 'ci-gw'), 'gemeenschappelijke oorzaak: dezelfde server erboven');
ok(str_contains(Bundle::title($items, $cause), '2'), 'titel noemt het aantal servers');
q('UPDATE hosts SET parent_id = NULL WHERE id IN (?, ?)', [$ha, $hb]);
ok(Bundle::cause([$items[0]]) === null, 'één server is geen bundel');

section('Zelf herstellen en waakhond');
ok(Heal::clean(['type' => 'service'], 'restart') === 'restart', 'herstarten mag bij een service');
ok(Heal::clean(['type' => 'ping'], 'restart') === null, 'herstarten mag niet bij een ping-check');
ok(Heal::clean(['type' => 'ping'], 'cmd:fix-db') === 'cmd:fix-db', 'een herstelcommando op naam');
ok(Heal::clean(['type' => 'ping'], 'cmd:../../bin/sh') === null, 'geen paden in de naam van een herstelcommando');
save_setting('pushover_enabled', '1');
save_setting('pushover_user', 'ci-user');
save_setting('pushover_token', 'ci-token');
q('UPDATE hosts SET watchdog = 1, watchdog_min = 3 WHERE id = ?', [$ha]);
$plain = implode("\n", Heal::testLines($ha, false));
$secure = implode("\n", Heal::testLines($ha, true));
ok(str_contains($secure, 'ci-token') && !str_contains($plain, 'ci-token'), 'Pushover-gegevens alleen in een versleutelde lijst');

section('Statuspagina');
StatusPage::save(['on' => true, 'title' => 'Thuis', 'items' => [['label' => 'CPU', 'checks' => [$cpu]], ['label' => '', 'checks' => [1]]]]);
ok(count(StatusPage::items()) === 1, 'onderdelen zonder naam vallen weg');
ok(StatusPage::on() && preg_match('/^[a-f0-9]{32}$/', StatusPage::token()) === 1, 'aan, met een geheime sleutel');
$d = StatusPage::data();
ok($d['title'] === 'Thuis' && count($d['components'][0]['days']) === StatusPage::DAYS, 'pagina met 90 dagen per onderdeel');

section('Analyse');
Analytics::cron();
$rep = Analytics::report('7d');
ok(isset($rep['kpi'], $rep['todo'], $rep['reliability']), 'rapport over 7 dagen');

echo "\n" . ($fails ? "✘ $fails van $n mislukt\n" : "✔ alle $n tests geslaagd\n");
exit($fails ? 1 : 0);
