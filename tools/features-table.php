<?php
declare(strict_types=1);

/**
 * 1.182.0: zet de tabel met alle functies (uit src/Features.php) bovenaan FEATURES.md,
 * tussen <!-- functies:begin --> en <!-- functies:end -->.
 *   php tools/features-table.php          bijwerken
 *   php tools/features-table.php --check  alleen controleren (exitcode 1 als de tabel niet klopt)
 */
require __DIR__ . '/../src/Features.php';

$file = __DIR__ . '/../FEATURES.md';
$md = (string) file_get_contents($file);
$begin = '<!-- functies:begin -->';
$end = '<!-- functies:end -->';
$block = $begin . "\n" . Features::markdown() . $end;
$i = strpos($md, $begin);
$j = strpos($md, $end);
if ($i === false || $j === false || $j < $i) {
    fwrite(STDERR, "FEATURES.md: markeringen $begin / $end ontbreken\n");
    exit(1);
}
$new = substr($md, 0, $i) . $block . substr($md, $j + strlen($end));
if (in_array('--check', $argv, true)) {
    if ($new !== $md) {
        fwrite(STDERR, "FEATURES.md: de functietabel loopt achter; draai php tools/features-table.php\n");
        exit(1);
    }
    echo "FEATURES.md: functietabel klopt (" . count(Features::LIST) . " functies)\n";
    exit(0);
}
file_put_contents($file, $new);
echo "FEATURES.md: functietabel bijgewerkt (" . count(Features::LIST) . " functies)\n";
