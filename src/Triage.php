<?php
declare(strict_types=1);

/**
 * Slimme oordelen bij IDS-incidenten (1.223.0): "lijkt echt", "verdacht" of "lijkt onschuldig", zuinig met de AI.
 *
 * Volgorde (de eerste die iets weet, wint):
 * 1. jouw eigen keuze voor deze soort (👤): "Dit is normaal", "Nee, ik ken dit niet" of het label met de hand veranderd;
 * 2. regels (⚙️): honeypot, reverse shell, webshell, bekende aanvaller (dreigingsinformatie), whitelist, onderzoeksscanner;
 * 3. een eerder oordeel over dezelfde soort (♻️), van de AI of van jou, zolang dat geldig is (AI: 30 dagen);
 * 4. pas dan de AI (🤖): één vraag per soort, niet per incident, met een plafond per dag.
 *
 * Een soort = een vingerafdruk van het incident: soort, regel en titel zonder wisselende delen (IP-adressen, getallen,
 * hashes). Hetzelfde soort incident op een andere server of een andere dag kost zo geen nieuwe vraag.
 * Het oordeel staat bij het incident zelf (tri_v, tri_src, tri_why), dus het blijft zo lang het incident bestaat.
 */
final class Triage
{
    public const VERDICTS = ['benign', 'suspect', 'real'];
    /** Bronnen: u = jij, r = regel, h = eerder oordeel (hergebruikt), a = AI */
    public const SOURCES = ['u', 'r', 'h', 'a'];
    private const AI_DAYS = 30;

    /** Aan? Hoort bij de AI-extra "Oordeel bij IDS-incidenten" */
    public static function on(): bool
    {
        return Ai::on() && setting('ai_triage', '0') === '1';
    }

    /** Vingerafdruk van de soort (16 tekens) */
    public static function fp(array $inc): string
    {
        $norm = static function (string $s): string {
            $s = mb_strtolower($s);
            $s = (string) preg_replace('/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/', 'IP', $s);
            $s = (string) preg_replace('/(?<![\w:])[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}(?![\w:])/i', 'IP', $s);
            $s = (string) preg_replace('/[0-9a-f]{8,}/i', 'H', $s);
            return trim((string) preg_replace(['/\d+/', '/\s+/'], ['#', ' '], $s));
        };
        $rule = explode('|', (string) ($inc['dkey'] ?? ''))[0];
        return substr(hash('sha256', ($inc['type'] ?? '') . '|' . $norm($rule) . '|' . $norm((string) ($inc['title'] ?? ''))), 0, 16);
    }

    /** Soorten die (bijna) altijd een echte aanval zijn, met de reden */
    private static function realTypes(): array
    {
        return [
            'decoy_user' => t('Een honeypot-gebruiker bestaat niet echt: alleen aanvallers proberen die naam.'),
            'decoy_port' => t('Op een honeypot-poort draait niets: alleen scanners en aanvallers komen daar.'),
            'revshell' => t('Een reverse shell is bijna altijd een aanvaller die de server overneemt.'),
            'webshell' => t('Een webserver die een shell start, is meestal een webshell.'),
            'breakin' => t('Eerst veel mislukte logins en dan een gelukte: waarschijnlijk geraden wachtwoord.'),
            'out_bad' => t('De server maakt verbinding met een bekende aanvaller.'),
            'ti_malware' => t('Een bestand dat bekend staat als malware.'),
        ];
    }

    /** Regels, zonder AI: [oordeel, reden] of null */
    public static function rule(array $inc): ?array
    {
        $type = (string) ($inc['type'] ?? '');
        $real = self::realTypes();
        if (isset($real[$type])) {
            return ['real', $real[$type]];
        }
        if ($type === 'edr' && (int) ($inc['severity'] ?? 0) >= 2 && str_starts_with((string) $inc['dkey'], 'rz-')) {
            return ['real', t('Een regel van RzSense met ernst critical (bijvoorbeeld reverse shell, webshell of malware).')];
        }
        $ip = (string) ($inc['ip'] ?? '');
        if ($ip !== '' && @inet_pton($ip) !== false) {
            if (Whitelist::ip($ip)) {
                return ['benign', t('Het IP-adres staat op je whitelist.')];
            }
            $ti = ThreatIntel::on() ? ThreatIntel::ip($ip) : null;
            if ($ti) {
                if ((int) $ti['score'] >= 90 || ($ti['verdict'] ?? '') === 'malicious') {
                    return ['real', t('Bekende aanvaller volgens de dreigingsinformatie (score {s}).', ['s' => (int) $ti['score']])];
                }
                if (($ti['verdict'] ?? '') === 'benign') {
                    return ['benign', t('Een bekende, onschuldige scanner of dienst volgens de dreigingsinformatie.')];
                }
            }
        }
        return null;
    }

    /** Eerder oordeel over deze soort (jij altijd; de AI zolang het geldig is): [oordeel, reden, bron] of null */
    public static function known(string $fp): ?array
    {
        $r = q('SELECT verdict, why, src FROM ids_triage WHERE fp = ? AND (expires_at IS NULL OR expires_at > NOW())', [$fp])->fetch();
        if (!$r) {
            return null;
        }
        q('UPDATE ids_triage SET n = n + 1, last_used = NOW() WHERE fp = ?', [$fp]);
        return [(string) $r['verdict'], (string) $r['why'], (string) $r['src']];
    }

    /** Oordeel bij een incident zetten */
    public static function set(int $id, string $verdict, string $src, string $why, string $fp): void
    {
        if (!in_array($verdict, self::VERDICTS, true) || !in_array($src, self::SOURCES, true)) {
            return;
        }
        q('UPDATE ids_incidents SET tri_v = ?, tri_src = ?, tri_why = ?, tri_fp = ?, tri_at = NOW() WHERE id = ?', [$verdict, $src, mb_substr($why, 0, 255), $fp, $id]);
    }

    /** Onthouden voor deze soort; jouw keuze verloopt niet, die van de AI na 30 dagen */
    public static function remember(string $fp, string $verdict, string $src, string $why, ?string $by = null): void
    {
        q('INSERT INTO ids_triage (fp, verdict, src, why, by_user, created_at, expires_at, n, last_used) VALUES (?, ?, ?, ?, ?, NOW(), ?, 0, NOW())
           ON DUPLICATE KEY UPDATE verdict = IF(src = \'u\' AND VALUES(src) <> \'u\', verdict, VALUES(verdict)),
             why = IF(src = \'u\' AND VALUES(src) <> \'u\', why, VALUES(why)), by_user = IF(src = \'u\' AND VALUES(src) <> \'u\', by_user, VALUES(by_user)),
             expires_at = IF(src = \'u\' AND VALUES(src) <> \'u\', expires_at, VALUES(expires_at)), src = IF(src = \'u\', src, VALUES(src)), created_at = NOW()',
            [$fp, $verdict, $src, mb_substr($why, 0, 255), $by, $src === 'u' ? null : date('Y-m-d H:i:s', time() + self::AI_DAYS * 86400)]);
    }

    /**
     * Elke minuut (cron): nieuwe open incidenten zonder oordeel beoordelen met regels en wat al bekend is; wat dan nog
     * onzeker is, gaat naar de AI: één incident per soort, hooguit ai_triage_max per dag, critical eerst.
     * @return int aantal nieuwe AI-vragen
     */
    public static function cron(): int
    {
        if (!self::on()) {
            return 0;
        }
        $rows = q("SELECT id, type, dkey, title, ip, severity FROM ids_incidents WHERE status = 'open' AND tri_v IS NULL AND created_at > NOW() - INTERVAL 2 DAY
                   ORDER BY severity DESC, id DESC LIMIT 300")->fetchAll();
        $todo = [];
        foreach ($rows as $inc) {
            $fp = self::fp($inc);
            if (($k = self::known($fp)) !== null) {
                self::set((int) $inc['id'], $k[0], $k[2] === 'u' ? 'u' : 'h', $k[1], $fp);
                continue;
            }
            if (($r = self::rule($inc)) !== null) {
                self::set((int) $inc['id'], $r[0], 'r', $r[1], $fp);
                continue;
            }
            $todo[$fp] ??= (int) $inc['id'];   // één per soort
        }
        if (!$todo) {
            return 0;
        }
        // Al gevraagd en nog bezig? Dan niet nog eens
        $busy = array_flip(array_map('intval', q("SELECT ref_id FROM ai_advice WHERE kind = 'triage' AND (status IN ('pending', 'running', 'diag') OR (status = 'done' AND created_at > NOW() - INTERVAL 1 HOUR))")->fetchAll(PDO::FETCH_COLUMN)));
        $max = max(0, min(500, (int) setting('ai_triage_max', '20')));
        $used = (int) q("SELECT COUNT(*) FROM ai_advice WHERE kind = 'triage' AND created_at >= CURDATE()")->fetchColumn();
        $asked = 0;
        foreach ($todo as $id) {
            if ($used + $asked >= $max || $asked >= 3) {
                break;   // plafond per dag, en hooguit 3 per minuut
            }
            if (isset($busy[$id])) {
                continue;
            }
            try {
                Ai::ask('triage', $id, 'auto', false, true);
                $asked++;
            } catch (RuntimeException) {
                break;   // daglimiet of geen configuratie
            }
        }
        return $asked;
    }

    /** Antwoord van de AI binnen: bij het incident, onthouden voor de soort, en meteen bij de andere open incidenten van die soort */
    public static function fromAi(int $incId, ?string $verdict, string $why): void
    {
        if ($verdict === null || !in_array($verdict, self::VERDICTS, true)) {
            return;
        }
        $inc = q('SELECT id, type, dkey, title, tri_src FROM ids_incidents WHERE id = ?', [$incId])->fetch();
        if (!$inc) {
            return;
        }
        $fp = self::fp($inc);
        if ($inc['tri_src'] !== 'u') {   // jouw oordeel bij dit incident blijft staan
            self::set($incId, $verdict, 'a', $why, $fp);
        }
        self::remember($fp, $verdict, 'a', $why);
        foreach (q("SELECT id, type, dkey, title FROM ids_incidents WHERE status = 'open' AND tri_v IS NULL AND id <> ? AND type = ? AND created_at > NOW() - INTERVAL 2 DAY", [$incId, $inc['type']])->fetchAll() as $o) {
            if (self::fp($o) === $fp) {
                self::set((int) $o['id'], $verdict, 'h', $why, $fp);
            }
        }
    }

    /**
     * Jouw keuze: geldt voor deze soort, voortaan en voor de open incidenten van die soort.
     * "Dit is normaal" = onschuldig, "Nee, ik ken dit niet" = echt, of het label met de hand.
     */
    public static function fromUser(array $inc, string $verdict, string $user): void
    {
        if (!in_array($verdict, self::VERDICTS, true)) {
            return;
        }
        $fp = self::fp($inc);
        $why = match ($verdict) {
            'benign' => t('{user} vond dit normaal.', ['user' => $user]),
            'real' => t('{user} kende dit niet: behandelen als echt.', ['user' => $user]),
            default => t('{user} vond dit verdacht.', ['user' => $user]),
        };
        self::remember($fp, $verdict, 'u', $why, $user);
        self::set((int) $inc['id'], $verdict, 'u', $why, $fp);
        foreach (q("SELECT id, type, dkey, title FROM ids_incidents WHERE status = 'open' AND id <> ? AND type = ? AND (tri_src IS NULL OR tri_src <> 'u')", [$inc['id'], $inc['type']])->fetchAll() as $o) {
            if (self::fp($o) === $fp) {
                self::set((int) $o['id'], $verdict, 'u', $why, $fp);
            }
        }
    }

    /** Voor de browser: [incident-id => [oordeel, reden, bron]] (de laatste 30 dagen) */
    public static function map(): array
    {
        $out = [];
        foreach (q('SELECT id, tri_v, tri_why, tri_src FROM ids_incidents WHERE tri_v IS NOT NULL AND created_at > NOW() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 3000') as $r) {
            $out[(int) $r['id']] = [(string) $r['tri_v'], (string) $r['tri_why'], (string) $r['tri_src']];
        }
        return $out;
    }

    /** Teller voor de instellingen: vandaag */
    public static function stats(): array
    {
        $by = array_fill_keys(self::SOURCES, 0);
        foreach (q('SELECT tri_src, COUNT(*) AS n FROM ids_incidents WHERE tri_at >= CURDATE() GROUP BY tri_src') as $r) {
            $by[(string) $r['tri_src']] = (int) $r['n'];
        }
        return ['ai' => (int) q("SELECT COUNT(*) FROM ai_advice WHERE kind = 'triage' AND created_at >= CURDATE()")->fetchColumn(), 'by' => $by,
            'max' => max(0, (int) setting('ai_triage_max', '20')), 'kinds' => (int) q('SELECT COUNT(*) FROM ids_triage')->fetchColumn()];
    }

    /**
     * Bij de update (1.223.0): oordelen die de AI al gaf, bij de incidenten zetten en per soort onthouden.
     * Daarna doet het niets meer (alleen incidenten zonder oordeel).
     */
    public static function migrate(PDO $pdo): void
    {
        $rows = $pdo->query("SELECT a.ref_id, a.verdict, a.answer, i.type, i.dkey, i.title FROM ai_advice a JOIN ids_incidents i ON i.id = a.ref_id
                             WHERE a.kind = 'triage' AND a.status = 'done' AND a.verdict IS NOT NULL AND i.tri_v IS NULL ORDER BY a.id DESC LIMIT 5000")->fetchAll();
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['ref_id']]) || !in_array($r['verdict'], self::VERDICTS, true)) {
                continue;
            }
            $seen[$r['ref_id']] = true;
            $fp = self::fp($r);
            $why = mb_substr(Ai::reason((string) $r['answer']), 0, 255);
            self::set((int) $r['ref_id'], (string) $r['verdict'], 'a', $why, $fp);
            if (!$pdo->query('SELECT 1 FROM ids_triage WHERE fp = ' . $pdo->quote($fp))->fetchColumn()) {
                self::remember($fp, (string) $r['verdict'], 'a', $why);
            }
        }
    }

    public static function purge(): void
    {
        q("DELETE FROM ids_triage WHERE src <> 'u' AND expires_at < NOW() - INTERVAL 30 DAY");
    }
}
