<?php
declare(strict_types=1);

/**
 * E-mailbeveiliging van een domein (1.227.0): kan iemand anders mail versturen uit naam van jouw domein?
 * Een keuze bij de Domein-check (metric "mail"). RzSense kijkt in DNS naar:
 * - SPF   (v=spf1 …): bestaat het, eindigt het streng (-all/~all), niet meer dan 10 DNS-opzoekingen;
 * - DMARC (_dmarc): bestaat het, beleid none/quarantine/reject, pct, rapporten (rua);
 * - DKIM  (<selector>._domainkey): de selectors die je opgeeft en de bekendste, met de sleutellengte;
 * - MX, MTA-STS (_mta-sts + het beleid op https://mta-sts.<domein>/.well-known/mta-sts.txt) en TLS-RPT (_smtp._tls);
 * - optioneel: doen de mailservers STARTTLS (poort 25; vaak dicht bij een thuisverbinding, daarom standaard uit).
 * Verstuurt het domein geen mail (geen MX, of zo ingesteld), dan hoort er v=spf1 -all en DMARC p=reject te staan.
 * Uitkomst per punt: ok / warn / crit / info, met uitleg en het DNS-record om te plakken.
 * Opzoeken (lookup) en beoordelen (evaluate) zijn los, zodat het beoordelen zonder netwerk te testen is.
 * Hooguit eens per 3 uur opnieuw opgezocht (options._mail). Uit te zetten: mailsec_on (Instellingen → Functies).
 */
final class MailSec
{
    public const SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1', 'k2', 'mail', 'dkim', 's1', 's2', 'smtp', 'mx',
        'protonmail', 'protonmail2', 'protonmail3', 'fm1', 'fm2', 'fm3', 'zoho', 'mxvault', 'everlytickey1', 'sendgrid', 'mandrill', 'resend', 'ovh', 'transip', 'strato'];
    private const CACHE_SEC = 10800;

    public static function enabled(): bool
    {
        return setting('mailsec_on', '1') === '1';
    }

    /** De check (vanuit NetChecks::domain) */
    public static function check(array $check, array $o, string $name): array
    {
        if (!self::enabled()) {
            return Checks::result(STATUS_UNKNOWN, null, t('E-mailbeveiliging staat uit (Instellingen → Overzicht → Functies)'));
        }
        $sel = array_values(array_filter((array) ($o['dkim'] ?? []), static fn($s) => is_string($s) && preg_match('/^[a-z0-9._-]{1,63}$/i', $s)));
        $cache = is_array($o['_mail'] ?? null) && ($o['_mail']['name'] ?? '') === $name ? $o['_mail'] : null;
        if (!$cache || (int) ($cache['at'] ?? 0) < time() - self::CACHE_SEC) {
            $fresh = self::lookup($name, $sel, !empty($o['starttls']));
            if (empty($fresh['resolved'])) {
                // 1.247.1: een servernaam (bv. mail.voorbeeld.nl: alleen een A-record, geen MX/SPF/NS/SOA) → het domein erboven
                // controleren; STARTTLS dan op die server zelf
                $org = self::orgDomain($name);
                if ($org !== null) {
                    $fresh = self::lookup($org, $sel, !empty($o['starttls']), self::hasAddress($name) ? $name : null);
                    $fresh['domain'] = $org;
                }
            }
            if (empty($fresh['resolved'])) {   // DNS werkt niet of het domein bestaat niet: geen oordeel (en niets onthouden)
                return Checks::result(STATUS_UNKNOWN, null, self::hasAddress($name)
                    ? t('{d} is een servernaam zonder eigen e-mailrecords, en het domein erboven is niet te vinden in DNS. Vul het domein in (bv. het deel na de eerste punt).', ['d' => $name])
                    : t('Kon {d} niet opzoeken in DNS (geen NS- of SOA-record): bestaat het domein, en werkt DNS op de server van RzSense?', ['d' => $name]));
            }
            $cache = $fresh + ['name' => $name, 'at' => time()];
            $o['_mail'] = $cache;
            if ((int) ($check['id'] ?? 0) > 0) {
                q('UPDATE checks SET options = ? WHERE id = ?', [json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $check['id']]);
            }
        }
        $dom = (string) ($cache['domain'] ?? $name);
        $r = self::evaluate($dom, $cache, !empty($o['nomail']));
        $st = match ($r['worst']) { 'crit' => STATUS_CRIT, 'warn' => STATUS_WARN, default => STATUS_OK };
        $bad = array_values(array_filter($r['items'], static fn($i) => in_array($i['st'], ['crit', 'warn'], true)));
        $msg = $bad ? implode(' · ', array_map(static fn($i) => ($i['st'] === 'crit' ? '✘ ' : '⚠ ') . $i['title'], array_slice($bad, 0, 4)))
            : t('SPF, DKIM en DMARC in orde');
        return Checks::result($st, (float) $r['score'], t('E-mail {d}', ['d' => $dom]) . ($dom !== $name ? ' (' . t('{h} is een servernaam; het domein gecontroleerd', ['h' => $name]) . ')' : '') . ': ' . $msg . ' · ' . t('score {n}/100', ['n' => $r['score']]));
    }

    /** TXT-records van een naam (lege lijst als er niets is) */
    private static function txt(string $name): array
    {
        $r = @dns_get_record($name, DNS_TXT);
        if (!is_array($r)) {
            return [];
        }
        return array_values(array_map(static fn($x) => (string) ($x['txt'] ?? implode('', (array) ($x['entries'] ?? []))), $r));
    }

    /**
     * Alles opzoeken in DNS (en het MTA-STS-beleid via https).
     * @param list<string> $selectors
     * @return array<string, mixed>
     */
    /** Heeft deze naam een IP-adres (A of AAAA)? */
    private static function hasAddress(string $name): bool
    {
        return (bool) @dns_get_record($name, DNS_A) || (bool) @dns_get_record($name, DNS_AAAA);
    }

    /** Het domein boven een servernaam: de eerste naam erboven met een NS-, SOA- of MX-record (niet het topdomein zelf) */
    private static function orgDomain(string $name): ?string
    {
        $parts = explode('.', $name);
        for ($i = 1; count($parts) - $i >= 2; $i++) {
            $p = implode('.', array_slice($parts, $i));
            if (@dns_get_record($p, DNS_NS) || @dns_get_record($p, DNS_SOA) || @dns_get_record($p, DNS_MX)) {
                return $p;
            }
        }
        return null;
    }

    /** @param string|null $tlsHost STARTTLS op deze server testen in plaats van op de eerste MX (1.247.1) */
    public static function lookup(string $d, array $selectors, bool $starttls = false, ?string $tlsHost = null): array
    {
        $mx = [];
        foreach ((array) @dns_get_record($d, DNS_MX) as $r) {
            if (is_array($r) && isset($r['target'])) {
                $mx[] = [(int) ($r['pri'] ?? 0), strtolower(rtrim((string) $r['target'], '.'))];
            }
        }
        usort($mx, static fn($a, $b) => $a[0] <=> $b[0]);
        $spf = array_values(array_filter(self::txt($d), static fn($t) => (bool) preg_match('/^v=spf1(\s|$)/i', trim($t))));
        $dmarc = array_values(array_filter(self::txt("_dmarc.$d"), static fn($t) => (bool) preg_match('/^v=DMARC1/i', trim($t))));
        $dkim = [];
        foreach (array_unique([...$selectors, ...self::SELECTORS]) as $s) {
            foreach (self::txt("$s._domainkey.$d") as $t) {
                if (preg_match('/(^|;)\s*(v=DKIM1|k=|p=)/i', $t)) {
                    $b = self::keyBits($t);   // 1.247.3: alleen de lengte onthouden, niet de hele sleutel
                    $dkim[$s] = $b === null ? substr($t, 0, 80) : 'bits=' . $b . (preg_match('/k=ed25519/i', $t) ? '; k=ed25519' : '');
                    break;
                }
            }
            if (count($dkim) >= 5) {
                break;
            }
        }
        $sts = array_values(array_filter(self::txt("_mta-sts.$d"), static fn($t) => (bool) preg_match('/^v=STSv1/i', trim($t))));
        $policy = null;
        if ($sts) {
            $policy = self::stsPolicy($d);
        }
        $rpt = array_values(array_filter(self::txt("_smtp._tls.$d"), static fn($t) => (bool) preg_match('/^v=TLSRPTv1/i', trim($t))));
        $tls = null;
        if ($starttls && $tlsHost !== null) {
            $tls = self::starttls($tlsHost);
        } elseif ($starttls && $mx && $mx[0][1] !== '' && $mx[0][1] !== '.') {
            $tls = self::starttls($mx[0][1]);
        }
        $resolved = $mx || $spf || (bool) @dns_get_record($d, DNS_NS) || (bool) @dns_get_record($d, DNS_SOA);
        return ['resolved' => $resolved, 'mx' => $mx, 'spf' => $spf, 'spf_lookups' => $spf ? self::spfLookups($spf[0], 0, []) : 0, 'dmarc' => $dmarc, 'dkim' => $dkim,
            'sts' => $sts, 'sts_policy' => $policy, 'tlsrpt' => $rpt, 'starttls' => $tls];
    }

    /** Aantal DNS-opzoekingen van een SPF-record, ook die van include: en redirect= (de grens is 10) */
    private static function spfLookups(string $spf, int $depth, array $seen): int
    {
        if ($depth > 6) {
            return 0;
        }
        $n = 0;
        foreach (preg_split('/\s+/', strtolower(trim($spf))) ?: [] as $term) {
            $t = ltrim($term, '+-~?');
            if (preg_match('/^(a|mx|ptr|exists)([:\/]|$)/', $t)) {
                $n++;
            } elseif (preg_match('/^(include:|redirect=)(.+)$/', $t, $m)) {
                $n++;
                $dom = rtrim($m[2], '.');
                if (!isset($seen[$dom]) && count($seen) < 20) {
                    $seen[$dom] = true;
                    $sub = array_values(array_filter(self::txt($dom), static fn($x) => (bool) preg_match('/^v=spf1(\s|$)/i', trim($x))));
                    if ($sub) {
                        $n += self::spfLookups($sub[0], $depth + 1, $seen);
                    }
                }
            }
        }
        return $n;
    }

    /** Het MTA-STS-beleid (mode, max_age, mx) van https://mta-sts.<domein>/.well-known/mta-sts.txt */
    private static function stsPolicy(string $d): array
    {
        $url = "https://mta-sts.$d/.well-known/mta-sts.txt";
        if (ExtraChecks::urlBlocked($url) !== null) {
            return ['error' => 'blocked'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $code !== 200) {
            return ['error' => $err !== '' ? mb_substr($err, 0, 120) : "HTTP $code"];
        }
        $p = ['mx' => []];
        foreach (preg_split('/\R/', mb_substr($body, 0, 4000)) ?: [] as $line) {
            if (preg_match('/^\s*(version|mode|max_age|mx)\s*:\s*(\S+)/i', $line, $m)) {
                $k = strtolower($m[1]);
                if ($k === 'mx') {
                    $p['mx'][] = strtolower($m[2]);
                } else {
                    $p[$k] = strtolower($m[2]);
                }
            }
        }
        return $p;
    }

    /** Doet de mailserver STARTTLS? (EHLO op poort 25) true/false, of null als poort 25 niet bereikbaar is */
    private static function starttls(string $host): ?bool
    {
        $s = @stream_socket_client("tcp://$host:25", $no, $str, 6);
        if (!$s) {
            return null;
        }
        stream_set_timeout($s, 6);
        $read = static function () use ($s): string {
            $out = '';
            while (($l = fgets($s, 1024)) !== false) {
                $out .= $l;
                if (strlen($l) < 4 || $l[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $read();
        fwrite($s, "EHLO rzsense.local\r\n");
        $ehlo = $read();
        fwrite($s, "QUIT\r\n");
        fclose($s);
        return $ehlo === '' ? null : (bool) preg_match('/^250[- ]STARTTLS/mi', $ehlo);
    }

    /** Lengte van een DKIM-sleutel (RSA) in bits, of null */
    public static function keyBits(string $txt): ?int
    {
        if (!preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=\s]*)/', $txt, $m)) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
        if ($der === false || $der === '') {
            return 0;   // lege sleutel: ingetrokken
        }
        if (preg_match('/k=ed25519/i', $txt)) {
            return 256;
        }
        if (function_exists('openssl_pkey_get_public')) {
            $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
            $k = @openssl_pkey_get_public($pem);
            $det = $k !== false ? openssl_pkey_get_details($k) : false;
            if (is_array($det)) {
                $bits = (int) ($det['bits'] ?? 0);
                return $bits ?: null;
            }
        }
        return (int) round(strlen($der) * 8 / 1.1 / 256) * 256;   // ruwe schatting
    }

    /**
     * Het oordeel. $nomail: dit domein verstuurt bewust geen mail.
     * @return array{items: list<array{key: string, st: string, title: string, detail: string, fix: string}>, worst: string, score: int, sends: bool}
     */
    public static function evaluate(string $d, array $x, bool $nomail = false): array
    {
        $items = [];
        $add = static function (string $key, string $st, string $title, string $detail = '', string $fix = '') use (&$items): void {
            $items[] = ['key' => $key, 'st' => $st, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
        };
        $mx = (array) ($x['mx'] ?? []);
        $nullMx = count($mx) === 1 && in_array((string) ($mx[0][1] ?? ''), ['', '.'], true);
        $sends = !$nomail && $mx && !$nullMx;
        $spf = (array) ($x['spf'] ?? []);
        $dmarc = (array) ($x['dmarc'] ?? []);

        // MX
        if ($sends) {
            $add('mx', 'ok', t('Mailservers (MX)'), implode(', ', array_map(static fn($m) => $m[1], array_slice($mx, 0, 4))));
        } else {
            $add('mx', 'info', t('Dit domein ontvangt en verstuurt geen e-mail'), $nomail ? t('zo ingesteld bij deze check') : t('geen mailservers (MX) gevonden'),
                $nullMx ? '' : "$d. MX 0 .");
        }

        // SPF
        if (!$spf) {
            $add('spf', 'crit', t('Geen SPF-record'), t('Iedereen kan mail versturen die van {d} lijkt te komen.', ['d' => $d]),
                $sends ? "$d. TXT \"v=spf1 mx -all\"" : "$d. TXT \"v=spf1 -all\"");
        } elseif (count($spf) > 1) {
            $add('spf', 'crit', t('Meer dan één SPF-record'), t('Mailservers zien dat als een fout (permerror) en negeren SPF dan. Voeg ze samen tot één record.'));
        } else {
            $s = strtolower(trim((string) $spf[0]));
            $n = (int) ($x['spf_lookups'] ?? 0);
            if (preg_match('/(^|\s)\+?all(\s|$)/', $s)) {
                $add('spf', 'crit', t('SPF staat alles toe (+all)'), t('Met +all mag elke server mailen namens {d}: dat is hetzelfde als geen SPF.', ['d' => $d]), "$d. TXT \"" . preg_replace('/\+?all$/', '-all', $s) . '"');
            } elseif (!preg_match('/(\s)[-~?]all(\s|$)/', ' ' . $s) && !str_contains($s, 'redirect=')) {
                $add('spf', 'warn', t('SPF eindigt niet met -all of ~all'), t('Zonder "all" aan het eind is niet duidelijk wat er met andere servers moet gebeuren.'), "$d. TXT \"$s -all\"");
            } elseif (preg_match('/\?all(\s|$)/', $s)) {
                $add('spf', 'warn', t('SPF is neutraal (?all)'), t('Andere servers worden niet afgewezen. Gebruik ~all of -all.'));
            } elseif (!$sends && !preg_match('/^v=spf1\s+-all$/', $s)) {
                $add('spf', 'warn', t('SPF voor een domein zonder mail'), t('Verstuurt dit domein geen mail, gebruik dan alleen "v=spf1 -all".'), "$d. TXT \"v=spf1 -all\"");
            } else {
                $add('spf', 'ok', t('SPF in orde'), $s);
            }
            if ($n > 10) {
                $add('spf_n', 'crit', t('SPF heeft te veel DNS-opzoekingen ({n} van 10)', ['n' => $n]), t('Boven de 10 is SPF ongeldig. Haal includes weg die je niet gebruikt, of gebruik IP-adressen (ip4:/ip6:).'));
            } elseif ($n > 8) {
                $add('spf_n', 'warn', t('SPF zit bijna aan de grens ({n} van 10 DNS-opzoekingen)', ['n' => $n]), t('Een extra include of een wijziging bij je provider kan hem ongeldig maken.'));
            }
            if (str_contains($s, 'ptr')) {
                $add('spf_ptr', 'warn', t('SPF gebruikt ptr'), t('ptr is verouderd en traag; veel ontvangers negeren het. Gebruik ip4:/ip6: of include:.'));
            }
        }

        // DMARC
        if (!$dmarc) {
            $add('dmarc', 'crit', t('Geen DMARC-record'), t('Zonder DMARC weten ontvangers niet wat ze met vervalste mail moeten doen, en krijg je geen rapporten.'),
                "_dmarc.$d. TXT \"v=DMARC1; p=" . ($sends ? 'quarantine' : 'reject') . "; rua=mailto:dmarc@$d\"");
        } else {
            $tags = [];
            foreach (explode(';', (string) $dmarc[0]) as $part) {
                if (str_contains($part, '=')) {
                    [$k, $v] = array_map('trim', explode('=', $part, 2));
                    $tags[strtolower($k)] = $v;
                }
            }
            $p = strtolower($tags['p'] ?? '');
            $pct = isset($tags['pct']) ? (int) $tags['pct'] : 100;
            if (!in_array($p, ['none', 'quarantine', 'reject'], true)) {
                $add('dmarc', 'crit', t('DMARC-record zonder geldig beleid (p=)'), (string) $dmarc[0]);
            } elseif ($p === 'none') {
                $add('dmarc', 'warn', t('DMARC alleen kijken (p=none)'), t('Vervalste mail wordt nog gewoon afgeleverd. Kijk een paar weken in de rapporten en zet het daarna op quarantine of reject.'),
                    "_dmarc.$d. TXT \"" . preg_replace('/p=none/i', 'p=quarantine', (string) $dmarc[0]) . '"');
            } elseif (!$sends && $p !== 'reject') {
                $add('dmarc', 'warn', t('DMARC voor een domein zonder mail: gebruik p=reject'), (string) $dmarc[0], "_dmarc.$d. TXT \"v=DMARC1; p=reject\"");
            } else {
                $add('dmarc', 'ok', t('DMARC beschermt ({p})', ['p' => $p]), (string) $dmarc[0]);
            }
            if ($p !== 'none' && $pct < 100) {
                $add('dmarc_pct', 'warn', t('DMARC geldt maar voor {n}% van de mail', ['n' => $pct]), t('Zet pct op 100 (of haal het weg) als alles goed gaat.'));
            }
            if (empty($tags['rua']) && $sends) {
                $add('dmarc_rua', 'info', t('Geen DMARC-rapporten (rua)'), t('Met rua=mailto:… krijg je elke dag een overzicht van wie er namens je domein mailt.'));
            }
        }

        // DKIM
        if ($sends) {
            $dk = (array) ($x['dkim'] ?? []);
            if (!$dk) {
                $add('dkim', 'warn', t('Geen DKIM-sleutel gevonden'), t('RzSense probeerde de bekendste selectors en die je bij deze check opgaf. Vul bij de check de selector van je mailprovider in (bv. google, selector1).'));
            } else {
                foreach ($dk as $sel => $txt) {
                    $bits = preg_match('/^bits=(\d+)/', (string) $txt, $bm) ? (int) $bm[1] : self::keyBits((string) $txt);
                    if ($bits === 0) {
                        $add('dkim', 'info', t('DKIM-sleutel {s} is ingetrokken', ['s' => $sel]), t('Een lege sleutel (p=) betekent: deze selector wordt niet meer gebruikt.'));
                    } elseif ($bits !== null && $bits < 2048 && $bits !== 256) {
                        $add('dkim', 'warn', t('DKIM-sleutel {s} is kort ({b} bits)', ['s' => $sel, 'b' => $bits]), t('1024 bits geldt als te zwak; vraag je provider om een sleutel van 2048 bits.'));
                    } else {
                        $add('dkim', 'ok', t('DKIM-sleutel {s}', ['s' => $sel]), $bits ? t('{b} bits', ['b' => $bits]) : '');
                    }
                }
            }
        }

        // MTA-STS en TLS-RPT (aanrader, geen fout)
        if ($sends) {
            $pol = (array) ($x['sts_policy'] ?? []);
            if (!($x['sts'] ?? [])) {
                $add('sts', 'info', t('Geen MTA-STS'), t('Met MTA-STS dwing je af dat mail naar jou versleuteld (TLS) aankomt. Een aanrader, geen must.'));
            } elseif (!$pol || isset($pol['error'])) {
                $add('sts', 'warn', t('MTA-STS-beleid niet te lezen'), t('Het record _mta-sts staat er, maar https://mta-sts.{d}/.well-known/mta-sts.txt gaf: {e}', ['d' => $d, 'e' => (string) ($pol['error'] ?? '?')]));
            } else {
                $mode = (string) ($pol['mode'] ?? '');
                $add('sts', $mode === 'enforce' ? 'ok' : 'info', t('MTA-STS: {m}', ['m' => $mode ?: '?']), $mode === 'testing' ? t('Testmodus: alles gaat goed? Zet mode op enforce.') : '');
            }
            if (!($x['tlsrpt'] ?? [])) {
                $add('tlsrpt', 'info', t('Geen TLS-rapporten (TLS-RPT)'), t('Met _smtp._tls krijg je een melding als versleuteld afleveren mislukt.'), "_smtp._tls.$d. TXT \"v=TLSRPTv1; rua=mailto:tls@$d\"");
            }
            if (array_key_exists('starttls', $x) && $x['starttls'] !== null) {
                $add('starttls', $x['starttls'] ? 'ok' : 'crit', $x['starttls'] ? t('Mailserver doet STARTTLS') : t('Mailserver doet geen STARTTLS'),
                    (string) ($mx[0][1] ?? ''));
            }
        }

        $rank = ['ok' => 0, 'info' => 0, 'warn' => 1, 'crit' => 2];
        $worst = 'ok';
        $score = 100;
        foreach ($items as $i) {
            $score -= ['crit' => 30, 'warn' => 10, 'info' => 2, 'ok' => 0][$i['st']];
            if ($rank[$i['st']] > $rank[$worst]) {
                $worst = $i['st'];
            }
        }
        return ['items' => $items, 'worst' => $worst, 'score' => max(0, $score), 'sends' => $sends];
    }
}
