<?php
declare(strict_types=1);

/**
 * 1.275.0: wanneer een IDS-incident een pushmelding krijgt, en de dagelijkse samenvatting.
 *
 * Standaard (stand "action"): alleen pushen als je iets moet doen. Een aanval van buiten op het netwerk (Suricata,
 * webaanval, brute force, honeypot, bekende aanvaller) die al automatisch geblokkeerd of tegengehouden is, krijgt geen
 * push; hij staat gewoon bij IDS en komt in de samenvatting van 20:00. Wel meteen een push als:
 * - hij niet geblokkeerd is (beschermd IP, IDS op leren of blokkeren uit, blokkeren mislukt);
 * - de app een gewoon antwoord gaf (2xx/3xx: misschien gelukt);
 * - het lek (CVE) bij software past die je echt draait (Kwetsbaarheden);
 * - het iets op een server zelf is (webshell, proces, bestand, login, malware …): die soorten altijd.
 * Dezelfde aanvaller binnen 10 minuten: één push.
 * Stand "all" = zoals voor 1.275.0 (elke critical), "digest" = alleen de samenvatting (servers zelf blijven pushen).
 */
final class IdsPush
{
    public const MODES = ['all', 'action', 'digest'];
    /** Aanvallen van buiten op het netwerk: die mogen stil blijven als ze al afgehandeld zijn */
    public const NET_TYPES = ['suricata', 'web_attack', 'web_cve', 'web_payload', 'bruteforce', 'decoy_port', 'ti_ip'];
    private const HANDLED = ['blocked', 'stopped', 'isolated'];
    private const BUNDLE = 600;

    public static function mode(): string
    {
        $m = (string) setting('ids_push_mode', 'action');
        return in_array($m, self::MODES, true) ? $m : 'action';
    }

    /** Moet dit nieuwe critical incident nu naar Pushover? */
    public static function now(int $incidentId): bool
    {
        $i = q('SELECT * FROM ids_incidents WHERE id = ?', [$incidentId])->fetch();
        if (!$i) {
            return true;
        }
        $mode = self::mode();
        if ($mode === 'all' || !in_array((string) $i['type'], self::NET_TYPES, true)) {
            return true;   // iets op een server zelf: altijd
        }
        if ($mode === 'digest' || !self::needsAction($i)) {
            return false;
        }
        // Dezelfde aanvaller kort na elkaar: één push (de rest staat bij IDS en in de samenvatting)
        $ip = (string) ($i['ip'] ?? '');
        return $ip === '' || Once::claimFor('ids_push_ip', $ip, self::BUNDLE);
    }

    /** Moet je iets doen? Niet afgehandeld, misschien gelukt, of het lek zit in je eigen software */
    public static function needsAction(array $i): bool
    {
        $o = Protect::describe($i)['outcome'];
        if (!in_array($o['k'], self::HANDLED, true) || ($o['warn'] ?? null)) {
            return true;
        }
        return self::cveMine($i) !== [];
    }

    /** CVE's uit het incident die ook bij Kwetsbaarheden op een van je servers staan */
    public static function cveMine(array $i): array
    {
        if (!preg_match_all('/CVE-\d{4}-\d{4,7}/i', $i['title'] . ' ' . $i['detail'], $m)) {
            return [];
        }
        $cves = array_values(array_unique(array_map('strtoupper', $m[0])));
        $in = implode(',', array_fill(0, count($cves), '?'));
        return array_map('strval', q("SELECT DISTINCT cve FROM vuln_findings WHERE cve IN ($in)", $cves)->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------ samenvatting

    /** De samenvatting van de afgelopen 24 uur (null = niets gebeurd) */
    public static function digest(): ?array
    {
        $rows = q('SELECT * FROM ids_incidents WHERE created_at >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 5000')->fetchAll();
        $net = array_values(array_filter($rows, static fn($r) => in_array((string) $r['type'], self::NET_TYPES, true)));
        // Andere meldingen (op de servers zelf: uitgaande verbindingen, processen, bestanden …) die nog open staan
        $other = count(array_filter($rows, static fn($r) => !in_array((string) $r['type'], self::NET_TYPES, true) && $r['status'] === 'open'));
        if (!$net && !$other) {
            return null;
        }
        $d = Protect::describeAll($net);
        $handled = 0;
        $open = 0;
        $sigs = [];
        $ips = [];
        foreach ($net as $r) {
            $k = $d[(int) $r['id']]['o']['k'] ?? '';
            if (in_array($k, self::HANDLED, true)) {
                $handled++;
            } elseif ($r['status'] === 'open' && !in_array($k, ['allowed', 'unblocked'], true)) {
                $open++;
            }
            // Titel zonder het IP en zonder "Suricata: " / "ET …": de soort aanval
            $sig = preg_replace(['/ · [0-9a-fA-F:.]+$/', '/^(Suricata: )?(ET [A-Z_]+ )?/'], '', (string) $r['title']) ?? (string) $r['title'];
            $sigs[$sig] = ($sigs[$sig] ?? 0) + (int) $r['hits'];
            if ((string) $r['ip'] !== '') {
                $ips[(string) $r['ip']] = true;
            }
        }
        arsort($sigs);
        return ['n' => count($net), 'handled' => $handled, 'open' => $open, 'ips' => count($ips), 'other' => $other,
            'top' => array_slice($sigs, 0, 3, true), 'waf_tip' => self::wafTip()];
    }

    public static function digestText(array $g): string
    {
        $lines = [];
        if ($g['n']) {
            $lines[] = t('{n} aanval(len) van buiten in de afgelopen 24 uur, {b} automatisch geblokkeerd of tegengehouden, van {i} verschillende adressen.', ['n' => $g['n'], 'b' => $g['handled'], 'i' => $g['ips']]);
            $lines[] = $g['open'] ? '⚠️ ' . t('{n} aanval(len) nog niet afgehandeld: kijk bij IDS.', ['n' => $g['open']]) : '✅ ' . t('Niets doorgekomen; je hoeft niets te doen.');
        }
        if ($g['top']) {
            $lines[] = t('Meest gezien') . ': ' . implode(' · ', array_map(static fn($s, $n) => mb_substr((string) $s, 0, 70) . " ({$n}×)", array_keys($g['top']), $g['top']));
        }
        if ($g['other']) {
            $lines[] = '👁 ' . t('{n} andere melding(en) op je servers staan nog open (bv. uitgaande verbindingen of nieuwe programma\'s).', ['n' => $g['other']]);
        }
        if ($g['waf_tip']) {
            $lines[] = '💡 ' . $g['waf_tip'];
        }
        return implode("\n", $lines);
    }

    /** Tip: de WAF staat al twee weken op detecteren; met "alleen zeker" komen bekende lekken niet meer langs de proxy */
    public static function wafTip(): ?string
    {
        if (setting('waf_on', '0') !== '1' || Waf::mode() !== 'detect') {
            return null;
        }
        $first = q('SELECT MIN(at) FROM waf_events')->fetchColumn();
        if (!$first || time() - (int) strtotime((string) $first) < 14 * 86400) {
            return null;
        }
        return t('De WAF staat al twee weken op detecteren. Zet hem op "alleen zeker" (Beheerportal → Beveiliging → Reverse proxy): dan houdt de proxy bekende lekken zoals log4j al tegen.');
    }

    /** In de cron: één keer per dag op het ingestelde tijdstip */
    public static function cron(?int $now = null): ?string
    {
        $now ??= time();
        if (setting('ids_digest_on', '1') !== '1') {
            return null;
        }
        $at = (string) setting('ids_digest_time', '20:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) || date('H:i', $now) < $at || !Once::claim('ids_digest', 'd', date('Y-m-d', $now))) {
            return null;
        }
        $g = self::digest();
        if ($g === null) {
            return null;   // rustige dag: geen melding
        }
        $title = '🛡️ ' . t('IDS: samenvatting van vandaag');
        $text = self::digestText($g);
        $n = Notify::send($title, $text, ['channels' => ['pushover' => true, 'email' => false, 'webhook' => false], 'status' => $g['open'] || $g['other'] ? STATUS_WARN : STATUS_OK,
            'event' => 'info', 'anchor' => '#ids', 'prio' => -1]);
        q("INSERT INTO alerts (check_id, status, title, message, sent_ok, kind) VALUES (NULL, ?, ?, ?, ?, 'ids')",
            [$g['open'] || $g['other'] ? STATUS_WARN : STATUS_OK, mb_substr($title, 0, 250), mb_substr($text, 0, 1000), $n['push'] ? 1 : 0]);
        return "IDS-samenvatting verstuurd ({$g['n']} incidenten)";
    }
}
