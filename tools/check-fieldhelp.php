<?php
declare(strict_types=1);

/**
 * 1.276.0: heeft elk instelveld in public/index.php een eigen uitleg achter de ? (FH() of FH_SHORT() in
 * public/assets/src/18-veldhulp.js, of een title/toelichting bij het veld)? Velden op de inlogpagina en in
 * het eigen-wachtwoordformulier tellen niet mee. Gebruik: php tools/check-fieldhelp.php [--list]
 */
$root = dirname(__DIR__);
$html = (string) file_get_contents("$root/public/index.php");
$js = (string) file_get_contents("$root/public/assets/src/18-veldhulp.js");
preg_match_all('/^\s{2,4}([a-z0-9_]+):\s*(?:\{|L\()/m', $js, $m);
$known = array_flip($m[1]);
$skip = array_flip(['password', 'password2', 'code', 'username', 'remember', 'ident', 'current', 'new']);
$missing = [];
preg_match_all('~<label[^>]*>(.*?)</label>~s', $html, $labels);
foreach ($labels[0] as $lab) {
    preg_match_all('~<(?:input|select|textarea)\b[^>]*\bname="([a-z0-9_]+)"[^>]*>~', $lab, $f, PREG_SET_ORDER);
    foreach ($f as [$tag, $name]) {
        if (str_contains($tag, 'type="hidden"') || isset($skip[$name]) || isset($known[$name]) || str_contains($lab, 'title=')
            || preg_match('~class="(?:hint|muted small)"~', $lab)) {
            continue;
        }
        $missing[$name] = true;
    }
}
if (in_array('--list', $argv, true)) {
    echo implode("\n", array_keys($missing)), $missing ? "\n" : '';
    exit(0);
}
echo $missing ? '  ✘ ' . count($missing) . " instelveld(en) zonder eigen uitleg achter de ? (php tools/check-fieldhelp.php --list)\n" : "  ✔ elk instelveld heeft uitleg achter de ?\n";
exit($missing ? 1 : 0);
