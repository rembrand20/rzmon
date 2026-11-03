<?php
declare(strict_types=1);

/**
 * Rapport (1.142.0): een nette, afdrukbare pagina over een periode, om als PDF te bewaren (Afdrukken → Opslaan als PDF).
 * Gemaakt op de server als losse HTML (eigen opmaak, geen scripts), zodat hij er in elke browser en op papier hetzelfde uitziet.
 * Inhoud: samenvatting, incidenten, beschikbaarheid per server, lastigste checks, beveiliging, kwetsbaarheden en wat nu aanpakken.
 */
final class Report
{
    public static function html(string $period): string
    {
        $r = Analytics::report($period);
        $k = $r['kpi'];
        $inc = Incidents::period($r['from'], $r['to']);
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $pct = static fn(?float $v, int $d = 2) => $v === null ? '–' : I18n::dec((string) round($v, $d)) . '%';
        $dur = static fn(?int $s) => $s === null ? '–' : ($s >= 86400 ? I18n::dec((string) round($s / 86400, 1)) . ' ' . t('dagen')
            : ($s >= 3600 ? I18n::dec((string) round($s / 3600, 1)) . ' ' . t('uur') : max(1, (int) round($s / 60)) . ' min'));
        $date = static fn(string $d) => date('d-m-Y', strtotime($d));
        $delta = static function (?float $now, ?float $prev, bool $lowerBetter = false) {
            if ($now === null || $prev === null || abs($now - $prev) < 0.005) {
                return '';
            }
            $good = $lowerBetter ? $now < $prev : $now > $prev;
            return ' <span class="' . ($good ? 'good' : 'bad') . '">' . ($now > $prev ? '▲' : '▼') . '</span>';
        };
        $tile = static fn(string $label, string $val, string $sub = '') => "<div class=\"tile\"><div class=\"lbl\">$label</div><div class=\"val\">$val</div>"
            . ($sub !== '' ? "<div class=\"sub\">$sub</div>" : '') . '</div>';

        $h = [];
        $h[] = '<header><div><h1>' . $e(t('RzMon-rapport')) . '</h1><p>' . $e(t('{from} t/m {to}', ['from' => $date($r['from']), 'to' => $date($r['to'])]))
            . ' · ' . $e(t('{n} dagen', ['n' => $r['days']])) . '</p></div><p class="meta">' . $e(t('Gemaakt op {t}', ['t' => date('d-m-Y H:i')])) . '<br>RzMon ' . $e(RZMON_VERSION) . '</p></header>';
        if ($r['since'] && $r['since'] > $r['from']) {
            $h[] = '<p class="note">' . $e(t('Gegevens vanaf {d}: over de dagen ervoor weet RzMon nog niets.', ['d' => $date((string) $r['since'])])) . '</p>';
        }
        // Samenvatting
        $h[] = '<h2>' . $e(t('Samenvatting')) . '</h2><div class="tiles">'
            . $tile($e(t('Score')), (int) $k['score'] . '<small>/100</small>')
            . $tile($e(t('Beschikbaarheid')), $pct($k['avail'][0]) . $delta($k['avail'][0], $k['avail'][1]), $e(t('vorige periode {p}', ['p' => $pct($k['avail'][1])])))
            . $tile($e(t('Storingen')), (string) (int) $k['incidents'][0] . $delta((float) $k['incidents'][0], (float) $k['incidents'][1], true), $e(t('naar critical')))
            . $tile($e(t('Hersteltijd')), $e($dur($k['mttr'][0] !== null ? (int) $k['mttr'][0] : null)), $e(t('gemiddeld')))
            . $tile($e(t('Meldingen')), (string) (int) $k['alerts'][0])
            . $tile($e(t('IDS critical')), (string) (int) $k['security'][0])
            . '</div>';
        // Incidenten
        $src = ['check' => t('Monitoring'), 'ids' => 'IDS', 'vuln' => t('Kwetsbaarheden')];
        $h[] = '<h2>' . $e(t('Incidenten')) . '</h2><p>' . $e(t('{n} incidenten geopend: {list}. Nog open: {open}. Gemiddeld opgelost na {t}.', [
            'n' => $inc['total'], 'list' => implode(', ', array_map(fn($s, $n) => $n . '× ' . $src[$s], array_keys(array_filter($inc['by_source'])), array_filter($inc['by_source']))) ?: '–',
            'open' => $inc['open'], 't' => $dur($inc['mttr'])])) . '</p>';
        if ($inc['rows']) {
            $st = ['open' => t('open'), 'ack' => t('in behandeling'), 'resolved' => t('opgelost')];
            $h[] = '<table><thead><tr><th>' . $e(t('Geopend')) . '</th><th>' . $e(t('Incident')) . '</th><th>' . $e(t('Status')) . '</th><th>' . $e(t('Eigenaar'))
                . '</th><th class="num">' . $e(t('Duur')) . '</th></tr></thead><tbody>' . implode('', array_map(fn($i) => '<tr><td class="nowrap">' . $e(date('d-m H:i', strtotime((string) $i['opened_at'])))
                . '</td><td>' . $e($i['title']) . '</td><td>' . $e($st[$i['status']] ?? $i['status']) . '</td><td>' . $e($i['owner'] ?? '–') . '</td><td class="num">'
                . $e($dur((int) $i['dur'])) . '</td></tr>', $inc['rows'])) . '</tbody></table>';
        }
        // Beschikbaarheid per server
        $hosts = $r['reliability']['hosts'];
        if ($hosts) {
            $h[] = '<h2>' . $e(t('Beschikbaarheid per server')) . '</h2><table><thead><tr><th>' . $e(t('Server')) . '</th><th class="num">' . $e(t('Beschikbaar'))
                . '</th><th class="num">' . $e(t('Onbereikbaar')) . '</th><th class="num">' . $e(t('Hersteltijd')) . '</th></tr></thead><tbody>'
                . implode('', array_map(fn($x) => '<tr><td>' . $e($x['name']) . '</td><td class="num ' . ($x['pct'] < 99 ? 'bad' : ($x['pct'] < 99.9 ? 'warn' : '')) . '">' . $pct((float) $x['pct'], 3)
                    . '</td><td class="num">' . ($x['down_min'] > 0 ? '~' . $e($dur($x['down_min'] * 60)) : '–') . '</td><td class="num">' . $e($dur($x['mttr'] !== null ? (int) $x['mttr'] : null)) . '</td></tr>', $hosts))
                . '</tbody></table>';
        }
        // Lastigste checks
        if ($r['reliability']['top']) {
            $h[] = '<h2>' . $e(t('Lastigste checks')) . '</h2><table><thead><tr><th>' . $e(t('Check')) . '</th><th class="num">' . $e(t('Wisselingen'))
                . '</th><th class="num">' . $e(t('Naar critical')) . '</th><th class="num">' . $e(t('Niet OK')) . '</th></tr></thead><tbody>'
                . implode('', array_map(fn($c) => '<tr><td>' . $e("{$c['host']} – {$c['name']}") . '</td><td class="num">' . (int) $c['chg'] . '</td><td class="num">' . (int) $c['crit']
                    . '</td><td class="num">' . $pct((float) $c['bad_pct'], 1) . '</td></tr>', array_slice($r['reliability']['top'], 0, 10))) . '</tbody></table>';
        }
        // Beveiliging
        $s = $r['security'];
        $h[] = '<h2>' . $e(t('Beveiliging')) . '</h2><p>' . $e(t('IDS-incidenten: {c} critical. Geblokkeerde IP-adressen: {b} (waarvan {a} automatisch).', [
            'c' => (int) $k['security'][0], 'b' => (int) ($s['blk_total'] ?? 0), 'a' => (int) ($s['blk_auto'] ?? 0)])) . '</p>';
        // Kwetsbaarheden (van nu)
        if (Vulns::enabled()) {
            $vs = Vulns::summary();
            if ($vs) {
                $h[] = '<h2>' . $e(t('Kwetsbaarheden (nu)')) . '</h2><table><thead><tr><th>' . $e(t('Server')) . '</th><th class="num">Critical</th><th class="num">High</th>'
                    . '<th class="num">Medium</th><th class="num">' . $e(t('Actief misbruikt')) . '</th><th class="num">' . $e(t('Met oplossing')) . '</th></tr></thead><tbody>'
                    . implode('', array_map(fn($v) => '<tr><td>' . $e($v['host']) . '</td><td class="num ' . ($v['critical'] ? 'bad' : '') . '">' . $v['critical'] . '</td><td class="num">' . $v['high']
                        . '</td><td class="num">' . $v['medium'] . '</td><td class="num ' . ($v['exploited'] ? 'bad' : '') . '">' . $v['exploited'] . '</td><td class="num">' . $v['fixable'] . '</td></tr>', $vs))
                    . '</tbody></table>';
            }
        }
        // Wat nu aanpakken
        $h[] = '<h2>' . $e(t('Wat nu aanpakken')) . '</h2>' . ($r['todo']
            ? '<ul class="todo">' . implode('', array_map(fn($t) => '<li class="s' . (int) $t['sev'] . '">' . $e($t['text']) . '</li>', array_slice($r['todo'], 0, 15))) . '</ul>'
            : '<p>✔ ' . $e(t('niets dringends')) . '</p>');

        $title = $e(t('RzMon-rapport')) . ' ' . $e($r['from']) . ' – ' . $e($r['to']);
        return '<!doctype html><html lang="' . $e(I18n::lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title><style>'
            . self::css() . '</style></head><body><main>' . implode("\n", $h) . '<footer>' . $e(t('Bewaren als PDF: Afdrukken (Ctrl+P) → Opslaan als PDF.')) . '</footer></main></body></html>';
    }

    private static function css(): string
    {
        return <<<'CSS'
:root { --ink: #1c2433; --muted: #5f6b7f; --line: #dde3ec; --bad: #c62f2f; --warn: #b8740a; --good: #1a8a50; --bg: #fff; }
* { box-sizing: border-box; }
body { margin: 0; background: #eef1f6; color: var(--ink); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 900px; margin: 24px auto; background: var(--bg); padding: 32px 40px; border-radius: 10px; box-shadow: 0 4px 20px rgba(20,30,50,.08); }
header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 3px solid var(--ink); padding-bottom: 12px; margin-bottom: 8px; }
h1 { margin: 0; font-size: 26px; } header p { margin: 4px 0 0; color: var(--muted); } .meta { text-align: right; font-size: 12px; }
h2 { font-size: 17px; margin: 26px 0 8px; padding-bottom: 4px; border-bottom: 1px solid var(--line); break-after: avoid; }
.note { background: #fff7e6; border: 1px solid #f3d9a4; padding: 6px 10px; border-radius: 6px; font-size: 13px; }
.tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.tile { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; break-inside: avoid; }
.tile .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; }
.tile .val { font-size: 24px; font-weight: 700; } .tile .val small { font-size: 13px; color: var(--muted); } .tile .sub { font-size: 12px; color: var(--muted); }
table { width: 100%; border-collapse: collapse; font-size: 13px; } th, td { text-align: left; padding: 5px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); } tr { break-inside: avoid; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; } .nowrap { white-space: nowrap; }
.bad { color: var(--bad); font-weight: 600; } .warn { color: var(--warn); font-weight: 600; } .good { color: var(--good); }
.todo { padding-left: 18px; } .todo li { margin: 3px 0; } .todo li.s2::marker { color: var(--bad); } .todo li.s1::marker { color: var(--warn); }
footer { margin-top: 28px; font-size: 12px; color: var(--muted); text-align: center; }
@media (max-width: 640px) { main { margin: 0; padding: 16px; border-radius: 0; } .tiles { grid-template-columns: repeat(2, 1fr); } }
@media print { body { background: #fff; } main { margin: 0; max-width: none; box-shadow: none; padding: 0; } footer { display: none; } @page { margin: 15mm; } }
CSS;
    }
}
