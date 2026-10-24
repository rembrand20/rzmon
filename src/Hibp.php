<?php
declare(strict_types=1);

/**
 * Datalekken via Have I Been Pwned (1.69.0), met een hoofdschakelaar (standaard uit):
 * - wachtwoorden (Pwned Passwords, gratis): bij het instellen een waarschuwing, bij inloggen met een gelekt wachtwoord
 *   eerst een nieuw kiezen. Alleen de eerste 5 tekens van de SHA-1-hash gaan naar HIBP (k-anonimiteit, met opvulling);
 * - e-mailadressen (API-sleutel): van gebruikers met de controle aan, een eigen lijst en een geverifieerd domein,
 *   dagelijks; ⚠️ bij een nieuw lek met wachtwoorden, anders ℹ️ (Systeeminfo → Datalek); de eerste controle van een adres
 *   als één ℹ️-samenvatting. Per account uit te zetten (users.hibp; nieuwe accounts: aan).
 */
final class Hibp
{
    private const PWNED_URL = 'https://api.pwnedpasswords.com';
    private const API_URL = 'https://haveibeenpwned.com/api/v3';
    private const RECHECK = 86400;   // e-mailadressen en domein: dagelijks

    public static function enabled(): bool
    {
        return setting('hibp_enabled', '0') === '1';
    }

    public static function hasKey(): bool
    {
        return trim((string) setting('hibp_key', '')) !== '';
    }

    public static function threshold(): int
    {
        return max(1, (int) setting('hibp_threshold', '1'));
    }

    private static function pwnedUrl(): string
    {
        return rtrim((string) config('hibp_pwned_url', self::PWNED_URL), '/');   // config: alleen om te testen
    }

    private static function apiUrl(): string
    {
        return rtrim((string) config('hibp_api_url', self::API_URL), '/');
    }

    /** @return array{0:int,1:string,2:array} [HTTP-code, inhoud, kopteksten] */
    private static function get(string $url, array $headers = [], int $timeout = 10): array
    {
        $hdr = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$hdr): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $hdr[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code === 0) {
            throw new RuntimeException($err ?: t('geen verbinding'));
        }
        return [$code, (string) $raw, $hdr];
    }

    // ------------------------------------------------------------------ wachtwoorden

    /**
     * Hoe vaak staat dit wachtwoord in een datalek? null = HIBP niet bereikbaar (dan gaat alles gewoon door).
     * Alleen de eerste 5 tekens van de SHA-1-hash gaan de deur uit; de rest vergelijkt RzMon zelf.
     */
    public static function pwnedCount(string $password): ?int
    {
        $sha = strtoupper(sha1($password));
        try {
            [$code, $body] = self::get(self::pwnedUrl() . '/range/' . substr($sha, 0, 5), ['Add-Padding: true'], 4);
        } catch (Throwable) {
            return null;
        }
        if ($code !== 200) {
            return null;
        }
        $suffix = substr($sha, 5);
        foreach (preg_split('/\r?\n/', $body) as $line) {
            [$s, $n] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (strtoupper($s) === $suffix) {
                return (int) $n;   // opvulregels hebben 0
            }
        }
        return 0;
    }

    /** Geldt de controle voor dit account? $u met kolom hibp */
    public static function forUser(array $u): bool
    {
        return self::enabled() && (int) ($u['hibp'] ?? 0) === 1;
    }

    /**
     * Wachtwoord controleren voor dit account (bij inloggen of instellen) en het resultaat bewaren.
     * @return int|null aantal keer gelekt (0 = niet gevonden, of onder de drempel), null = niet gecontroleerd
     */
    public static function checkUser(int $userId, string $password): ?int
    {
        $u = q('SELECT id, hibp FROM users WHERE id = ?', [$userId])->fetch();
        if (!$u || !self::forUser($u)) {
            return null;
        }
        $n = self::pwnedCount($password);
        if ($n === null) {
            return null;
        }
        $hit = $n >= self::threshold() ? $n : 0;
        q('UPDATE users SET pw_pwned = ?, pw_pwned_at = ? WHERE id = ?', [$hit, now(), $userId]);
        return $hit;
    }

    /** Bij het instellen (geen account of controle uit): alleen het aantal, niets bewaren. null = niet gecontroleerd */
    public static function checkNew(string $password, ?int $userId = null): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        if ($userId !== null && !(int) q('SELECT hibp FROM users WHERE id = ?', [$userId])->fetchColumn()) {
            return null;
        }
        $n = self::pwnedCount($password);
        return $n === null ? null : ($n >= self::threshold() ? $n : 0);
    }

    // ------------------------------------------------------------------ e-mailadressen

    private static function apiHeaders(): array
    {
        return ['hibp-api-key: ' . trim((string) setting('hibp_key', '')), 'Accept: application/json'];
    }

    /** Abonnement bij HIBP (voor de 🧪-knop en hoeveel verzoeken per minuut) */
    public static function subscription(?string $key = null): array
    {
        $h = $key !== null ? ['hibp-api-key: ' . trim($key), 'Accept: application/json'] : self::apiHeaders();
        [$code, $body] = self::get(self::apiUrl() . '/subscription/status', $h);
        if ($code === 401) {
            throw new RuntimeException(t('API-sleutel ongeldig'));
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code");
        }
        $s = json_decode($body, true) ?: [];
        if (!empty($s['Rpm'])) {
            save_setting('hibp_rpm', (string) (int) $s['Rpm']);
        }
        return $s;
    }

    /** Alle adressen die nu gecontroleerd worden: [adres => bron] (gebruiker, lijst, domein) */
    public static function emails(): array
    {
        $out = [];
        foreach (q("SELECT LOWER(email) FROM users WHERE hibp = 1 AND email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $out[$e] = 'user';
        }
        foreach (parse_list((string) setting('hibp_emails', '')) as $e) {
            $e = strtolower($e);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[$e] ??= 'list';
            }
        }
        $domain = self::domain();
        if ($domain !== '') {
            foreach (q('SELECT email FROM hibp_emails WHERE source = ?', ['domain'])->fetchAll(PDO::FETCH_COLUMN) as $e) {
                if (str_ends_with($e, "@$domain")) {
                    $out[$e] ??= 'domain';
                }
            }
        }
        return $out;
    }

    public static function domain(): string
    {
        return strtolower(trim((string) setting('hibp_domain', '')));
    }

    /** Gegevens van een lek bewaren (uit breachedaccount of /breach/{naam}) */
    private static function saveCatalog(array $b): void
    {
        q('INSERT INTO hibp_catalog (name, title, domain, breach_date, added_date, pwn_count, data_classes, verified, is_sensitive, description, fetched_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), domain = VALUES(domain), breach_date = VALUES(breach_date),
           added_date = VALUES(added_date), pwn_count = VALUES(pwn_count), data_classes = VALUES(data_classes), verified = VALUES(verified),
           is_sensitive = VALUES(is_sensitive), description = VALUES(description), fetched_at = VALUES(fetched_at)', [
            mb_substr((string) $b['Name'], 0, 100), mb_substr((string) ($b['Title'] ?? $b['Name']), 0, 200), mb_substr((string) ($b['Domain'] ?? ''), 0, 190),
            !empty($b['BreachDate']) ? substr((string) $b['BreachDate'], 0, 10) : null,
            !empty($b['AddedDate']) ? date('Y-m-d H:i:s', strtotime((string) $b['AddedDate'])) : null,
            (int) ($b['PwnCount'] ?? 0), json_encode(array_values((array) ($b['DataClasses'] ?? [])), JSON_UNESCAPED_UNICODE),
            !empty($b['IsVerified']) ? 1 : 0, !empty($b['IsSensitive']) ? 1 : 0, mb_substr(strip_tags((string) ($b['Description'] ?? '')), 0, 4000), now(),
        ]);
    }

    /** Lek ophalen bij HIBP als we het nog niet kennen (gratis, geen sleutel nodig) */
    private static function ensureCatalog(string $name): void
    {
        if (q('SELECT 1 FROM hibp_catalog WHERE name = ?', [$name])->fetchColumn()) {
            return;
        }
        try {
            [$code, $body] = self::get(self::apiUrl() . '/breach/' . rawurlencode($name), ['Accept: application/json']);
            if ($code === 200 && ($b = json_decode($body, true))) {
                self::saveCatalog($b);
            }
        } catch (Throwable) {
            // later opnieuw
        }
    }

    /** Lekken bij een adres opslaan; @return string[] de namen die nieuw zijn */
    private static function store(string $email, array $names, string $source): array
    {
        $new = [];
        foreach ($names as $n) {
            if (q('INSERT IGNORE INTO hibp_breaches (email, name, source, found_at) VALUES (?, ?, ?, ?)', [$email, mb_substr($n, 0, 100), $source, now()])->rowCount()) {
                $new[] = $n;
            }
        }
        return $new;
    }

    /** Melden: de eerste controle van een adres als één ℹ️; daarna per nieuw lek ⚠️ (met wachtwoorden) of ℹ️ */
    private static function notify(string $email, array $new, bool $first): void
    {
        if (!$new) {
            return;
        }
        $rows = q('SELECT * FROM hibp_catalog WHERE name IN (' . implode(',', array_fill(0, count($new), '?')) . ')', $new)->fetchAll();
        $byName = array_column($rows, null, 'name');
        $label = static fn(string $n) => ($byName[$n]['title'] ?? $n) . (!empty($byName[$n]['breach_date']) ? ' (' . date('d-m-Y', strtotime($byName[$n]['breach_date'])) . ')' : '');
        $hasPw = static fn(string $n) => in_array('Passwords', (array) json_decode((string) ($byName[$n]['data_classes'] ?? '[]'), true), true);
        if ($first) {
            Monitor::sysInfo('breach', null, null, '🔓 ' . t('Datalekken gevonden: {email}', ['email' => $email]),
                fn() => (count($new) === 1 ? t('Eerste controle van {email}: 1 bekend lek: {list}', ['email' => $email, 'list' => $label($new[0])])
                    : t('Eerste controle van {email}: {n} bekende lekken: {list}', ['email' => $email, 'n' => count($new),
                    'list' => implode(', ', array_map($label, array_slice($new, 0, 10))) . (count($new) > 10 ? ' …' : '')]))
                    . ' · ' . t('Bekijk ze bij Kwetsbaarheden → Datalekken.'));
            return;
        }
        foreach ($new as $n) {
            $classes = implode(', ', array_map([self::class, 'dataClass'], array_slice((array) json_decode((string) ($byName[$n]['data_classes'] ?? '[]'), true), 0, 8)));
            if ($hasPw($n)) {
                Monitor::notifySystem('⚠️ ' . t('Nieuw datalek met wachtwoorden: {email}', ['email' => $email]),
                    t('{email} staat in het lek {breach}. Gelekte gegevens: {classes}.', ['email' => $email, 'breach' => $label($n), 'classes' => $classes])
                    . ' ' . t('Wijzig het wachtwoord bij die dienst, en overal waar je hetzelfde wachtwoord gebruikt. Zet 2FA aan waar het kan.'), STATUS_WARN, 'problem');
            } else {
                Monitor::sysInfo('breach', null, null, '🔓 ' . t('Nieuw datalek: {email}', ['email' => $email]),
                    fn() => t('{email} staat in het lek {breach}. Gelekte gegevens: {classes}.', ['email' => $email, 'breach' => $label($n), 'classes' => $classes]));
            }
        }
    }

    /** Eén adres bij HIBP controleren. @return string fout ('' = gelukt) of 'rate' (te snel: stoppen) */
    public static function checkEmail(string $email, string $source): string
    {
        $first = !q('SELECT checked_at FROM hibp_emails WHERE email = ?', [$email])->fetchColumn();
        try {
            [$code, $body, $hdr] = self::get(self::apiUrl() . '/breachedaccount/' . rawurlencode($email) . '?truncateResponse=false&includeUnverified=true', self::apiHeaders());
        } catch (Throwable $e) {
            self::markEmail($email, $source, $e->getMessage());
            return $e->getMessage();
        }
        if ($code === 429) {
            save_setting('hibp_wait_until', (string) (time() + max(2, (int) ($hdr['retry-after'] ?? 10))));
            return 'rate';
        }
        if ($code === 401) {
            save_setting('hibp_last_error', t('API-sleutel ongeldig'));
            return 'rate';
        }
        if ($code !== 200 && $code !== 404) {
            self::markEmail($email, $source, "HTTP $code");
            return "HTTP $code";
        }
        $names = [];
        foreach ($code === 200 ? (array) json_decode($body, true) : [] as $b) {
            if (!empty($b['Name'])) {
                self::saveCatalog($b);
                $names[] = (string) $b['Name'];
            }
        }
        self::notify($email, self::store($email, $names, $source), $first);
        self::markEmail($email, $source, null);
        save_setting('hibp_last_error', '');
        return '';
    }

    private static function markEmail(string $email, string $source, ?string $error): void
    {
        q('INSERT INTO hibp_emails (email, source, checked_at, error) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE source = VALUES(source), checked_at = VALUES(checked_at), error = VALUES(error)',
            [$email, $source, $error === null ? now() : null, $error !== null ? mb_substr($error, 0, 255) : null]);
    }

    /** Domein (geverifieerd bij HIBP): alle adressen op dat domein in lekken. @return string fout of '' */
    public static function checkDomain(): string
    {
        $domain = self::domain();
        if ($domain === '') {
            return '';
        }
        try {
            [$code, $body] = self::get(self::apiUrl() . '/breacheddomain/' . rawurlencode($domain), self::apiHeaders(), 30);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        save_setting('hibp_domain_at', now());
        if ($code === 403) {
            save_setting('hibp_domain_error', t('domein {d} is niet geverifieerd bij HIBP (of je abonnement is te klein)', ['d' => $domain]));
            return (string) setting('hibp_domain_error', '');
        }
        if ($code === 404) {
            save_setting('hibp_domain_error', '');
            return '';
        }
        if ($code !== 200) {
            save_setting('hibp_domain_error', "HTTP $code");
            return "HTTP $code";
        }
        save_setting('hibp_domain_error', '');
        foreach ((array) json_decode($body, true) as $alias => $names) {
            $email = strtolower("$alias@$domain");
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $first = !q('SELECT checked_at FROM hibp_emails WHERE email = ?', [$email])->fetchColumn();
            foreach ((array) $names as $n) {
                self::ensureCatalog((string) $n);
            }
            self::notify($email, self::store($email, array_map('strval', (array) $names), 'domain'), $first);
            q('INSERT INTO hibp_emails (email, source, checked_at, error) VALUES (?, ?, ?, NULL)
               ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), error = NULL', [$email, 'domain', now()]);
        }
        return '';
    }

    /**
     * Vanuit cron (elke minuut, na de checks): het domein dagelijks, en een paar adressen per keer (gespreid binnen het
     * aantal verzoeken per minuut van je abonnement), tot ze allemaal binnen 24 uur gecontroleerd zijn.
     */
    public static function cron(int $budget = 40): ?string
    {
        if (!self::enabled() || !self::hasKey()) {
            return null;
        }
        if ((int) setting('hibp_wait_until', '0') > time()) {
            return null;
        }
        $emails = self::emails();
        self::cleanup($emails);
        $msg = [];
        if (self::domain() !== '' && strtotime((string) setting('hibp_domain_at', '')) < time() - self::RECHECK) {
            $err = self::checkDomain();
            $msg[] = 'domein ' . ($err ?: 'ok');
        }
        $known = array_column(q('SELECT email, checked_at FROM hibp_emails')->fetchAll(), 'checked_at', 'email');
        $due = array_keys(array_filter($emails, static fn($src, $e) => $src !== 'domain'
            && (empty($known[$e]) || strtotime((string) $known[$e]) < time() - self::RECHECK), ARRAY_FILTER_USE_BOTH));
        if (!$due) {
            return $msg ? 'Datalekken: ' . implode(', ', $msg) : null;
        }
        $gap = 60 / max(1, (int) setting('hibp_rpm', '10')) + 0.2;
        $max = max(1, (int) floor($budget / $gap));   // hooguit ~40 seconden per minuut (via de knop: korter)
        $n = 0;
        foreach (array_slice($due, 0, $max) as $i => $e) {
            if ($i > 0) {
                usleep((int) ($gap * 1e6));
            }
            if (self::checkEmail($e, $emails[$e]) === 'rate') {
                break;
            }
            $n++;
        }
        save_setting('hibp_check_at', now());
        return 'Datalekken: ' . implode(', ', array_merge($msg, ["$n adres(sen) gecontroleerd"]));
    }

    /** Adressen die niet meer in de lijst staan (en hun lekken) opruimen */
    private static function cleanup(array $emails): void
    {
        foreach (q('SELECT email FROM hibp_emails')->fetchAll(PDO::FETCH_COLUMN) as $e) {
            if (!isset($emails[$e])) {
                q('DELETE FROM hibp_emails WHERE email = ?', [$e]);
                q('DELETE FROM hibp_breaches WHERE email = ?', [$e]);
            }
        }
    }

    /** Alles voor de pagina Datalekken */
    public static function overview(): array
    {
        $emails = self::emails();
        $state = array_column(q('SELECT email, checked_at, error FROM hibp_emails')->fetchAll(), null, 'email');
        $list = [];
        foreach ($emails as $e => $src) {
            $list[$e] = ['email' => $e, 'source' => $src, 'checked_at' => $state[$e]['checked_at'] ?? null, 'error' => $state[$e]['error'] ?? null, 'breaches' => []];
        }
        foreach (q('SELECT b.email, b.name, b.found_at, b.handled_at, b.handled_by, c.title, c.domain, c.breach_date, c.added_date, c.pwn_count,
                           c.data_classes, c.verified, c.is_sensitive, c.description
                    FROM hibp_breaches b LEFT JOIN hibp_catalog c ON c.name = b.name ORDER BY c.breach_date DESC')->fetchAll() as $b) {
            if (isset($list[$b['email']])) {
                $b['data_classes'] = (array) json_decode((string) $b['data_classes'], true);
                $b['passwords'] = in_array('Passwords', $b['data_classes'], true);
                $b['data_classes_l'] = array_map([self::class, 'dataClass'], $b['data_classes']);
                $list[$b['email']]['breaches'][] = $b;
            }
        }
        $users = q('SELECT id, username, role, local_only, hibp, pw_pwned, pw_pwned_at FROM users ORDER BY username')->fetchAll();
        return [
            'enabled' => self::enabled(), 'has_key' => self::hasKey(), 'threshold' => self::threshold(),
            'emails' => array_values($list), 'users' => $users, 'domain' => self::domain(),
            'domain_error' => (string) setting('hibp_domain_error', '') ?: null, 'domain_at' => setting('hibp_domain_at', '') ?: null,
            'last_error' => (string) setting('hibp_last_error', '') ?: null, 'check_at' => setting('hibp_check_at', '') ?: null,
        ];
    }

    /** Soort gegevens in een lek in de taal van de gebruiker (HIBP geeft Engels) */
    public static function dataClass(string $c): string
    {
        if (I18n::lang() === 'en') {
            return $c;
        }
        return [
            'Email addresses' => 'E-mailadressen', 'Passwords' => 'Wachtwoorden', 'Usernames' => 'Gebruikersnamen', 'Names' => 'Namen',
            'Phone numbers' => 'Telefoonnummers', 'Physical addresses' => 'Adressen', 'Dates of birth' => 'Geboortedata', 'IP addresses' => 'IP-adressen',
            'Genders' => 'Geslacht', 'Geographic locations' => 'Locaties', 'Job titles' => 'Functies', 'Employers' => 'Werkgevers',
            'Password hints' => 'Wachtwoordhints', 'Credit cards' => 'Creditcards', 'Partial credit card data' => 'Deel van creditcardgegevens',
            'Bank account numbers' => 'Bankrekeningnummers', 'Social media profiles' => 'Social-mediaprofielen',
            'Security questions and answers' => 'Beveiligingsvragen en -antwoorden', 'Purchases' => 'Aankopen', 'Website activity' => 'Websiteactiviteit',
            'Device information' => 'Apparaatgegevens', 'Spoken languages' => 'Talen', 'Government issued IDs' => 'Identiteitsbewijzen',
            'Historical passwords' => 'Oude wachtwoorden', 'Auth tokens' => 'Inlogtokens', 'Instant messenger identities' => 'Chat-accounts',
            'Time zones' => 'Tijdzones', 'Browser user agent details' => 'Browsergegevens', 'Salutations' => 'Aanspreekvormen',
        ][$c] ?? $c;
    }

    /** Tellers voor MQTT en "Hoe staat het ervoor?" */
    public static function summary(): array
    {
        $emails = self::emails();
        $open = 0;
        $pw = 0;
        $addr = [];
        foreach (q('SELECT b.email, c.data_classes FROM hibp_breaches b LEFT JOIN hibp_catalog c ON c.name = b.name WHERE b.handled_at IS NULL')->fetchAll() as $b) {
            if (isset($emails[$b['email']])) {
                $open++;
                $addr[$b['email']] = true;
                $pw += in_array('Passwords', (array) json_decode((string) $b['data_classes'], true), true) ? 1 : 0;
            }
        }
        $pwUsers = q('SELECT username FROM users WHERE hibp = 1 AND pw_pwned > 0 ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
        return ['open' => $open, 'with_passwords' => $pw, 'emails' => count($addr), 'checked' => count($emails), 'pw_users' => $pwUsers];
    }
}
