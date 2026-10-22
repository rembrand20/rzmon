<?php
declare(strict_types=1);

/**
 * Welke teksten hebben nog geen Engelse vertaling in lang/en.php?
 *
 *   php tools/check-i18n.php            # overzicht; altijd exitcode 0 (de vertaling groeit nog)
 *   php tools/check-i18n.php --list     # alle ontbrekende teksten, één per regel (om te vertalen)
 *   php tools/check-i18n.php --strict   # exitcode 1 als er iets ontbreekt
 *
 * Zoekt t('…') en th('…') in PHP en L('…') in public/assets/*.js, alleen met letterlijke tekst.
 */

$root = dirname(__DIR__);
$dict = is_file("$root/lang/en.php") ? (array) require "$root/lang/en.php" : [];

/** Letterlijke tekst uit een PHP- of JS-string tussen quotes */
function unquote(string $q, string $body): string
{
    if ($q === '"' || $q === '`') {
        return stripcslashes($body);
    }
    return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
}

$found = [];
$files = array_merge(glob("$root/public/*.php") ?: [], glob("$root/src/*.php") ?: []);
foreach ($files as $f) {
    preg_match_all('/\bth?\(\s*(\')((?:[^\'\\\\]|\\\\.)*)\'/s', (string) file_get_contents($f), $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        $found[unquote($x[1], $x[2])][] = basename($f);
    }
}
// Vaste agentteksten (AgentText::PATTERNS: patroon => Nederlandse tekst)
preg_match_all('/=>\s*\'((?:[^\'\\\\]|\\\\.)*)\',/', (string) file_get_contents("$root/src/AgentText.php"), $m, PREG_SET_ORDER);
foreach ($m as $x) {
    $found[unquote("'", $x[1])][] = 'AgentText.php';
}
foreach (glob("$root/public/assets/*.js") ?: [] as $f) {
    preg_match_all('/\bL\(\s*([\'"`])((?:(?!\1)[^\\\\]|\\\\.)*)\1/s', (string) file_get_contents($f), $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        if ($x[1] === '`' && str_contains($x[2], '${')) {
            continue;   // met ${…} is het geen vaste tekst
        }
        $found[unquote($x[1], $x[2])][] = basename($f);
    }
}

$missing = array_values(array_filter(array_keys($found), static fn($k) => !isset($dict[$k])));
$args = array_slice($argv, 1);
if (in_array('--list', $args, true)) {
    foreach ($missing as $k) {
        echo $k, "\n";
    }
    exit(0);
}
$total = count($found);
if ($missing) {
    printf("  ! %d van %d teksten nog zonder Engelse vertaling (php tools/check-i18n.php --list)\n", count($missing), $total);
} else {
    printf("  ✔ alle %d teksten hebben een Engelse vertaling\n", $total);
}
exit($missing && in_array('--strict', $args, true) ? 1 : 0);
