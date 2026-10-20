<?php
declare(strict_types=1);

/**
 * Wachtwoord vergeten: een herstellink per e-mail (30 minuten geldig, één keer te gebruiken).
 * In de database staat alleen de hash van de link. Heeft het account tweestapsverificatie,
 * dan is bij het herstellen ook de code uit de app nodig: alleen de mailbox is niet genoeg.
 */
final class PasswordReset
{
    public const VALID_MINUTES = 30;
    private const MAX_PER_ACCOUNT = 3;   // aanvragen per account per uur
    private const MAX_PER_IP = 10;       // aanvragen per IP-adres per uur
    private const MAX_TRIES = 5;         // foute 2FA-codes per link

    /** Kan RzMon herstelmails sturen? null = ja, anders de reden. */
    public static function unavailable(): ?string
    {
        if (trim((string) setting('smtp_host', '')) === '') {
            return 'Er is geen SMTP-server ingesteld (Instellingen → E-mail)';
        }
        if (!preg_match('#^https?://#i', (string) config('base_url', ''))) {
            return 'Er is geen Basis-URL ingesteld (Setup); die is nodig voor de link in de mail';
        }
        return null;
    }

    /**
     * Aanvraag vanaf het loginscherm. Geeft nooit prijs of het account bestaat.
     * @return callable|null mail die nog verstuurd moet worden (na het antwoord aan de browser)
     */
    public static function request(string $ident): ?callable
    {
        $ident = mb_substr(trim($ident), 0, 190);
        $ip = mb_substr(client_ip(), 0, 45);
        if ($ident === '') {
            return null;
        }
        $perIp = (int) q('SELECT COUNT(*) FROM password_reset_log WHERE ip = ? AND created_at >= NOW() - INTERVAL 1 HOUR', [$ip])->fetchColumn();
        $u = q('SELECT id, username, email FROM users WHERE username = ? OR (email IS NOT NULL AND LOWER(email) = LOWER(?)) LIMIT 1', [$ident, $ident])->fetch();
        if ($perIp >= self::MAX_PER_IP) {
            self::log($u ? (int) $u['id'] : null, $ident, 'te veel aanvragen vanaf dit IP');
            return null;
        }
        if (!$u) {
            self::log(null, $ident, 'onbekend account');
            return null;
        }
        if (!filter_var((string) $u['email'], FILTER_VALIDATE_EMAIL)) {
            self::log((int) $u['id'], $ident, 'geen e-mailadres ingesteld');
            return null;
        }
        $perAccount = (int) q("SELECT COUNT(*) FROM password_reset_log WHERE user_id = ? AND event = 'link verstuurd' AND created_at >= NOW() - INTERVAL 1 HOUR",
            [$u['id']])->fetchColumn();
        if ($perAccount >= self::MAX_PER_ACCOUNT) {
            self::log((int) $u['id'], $ident, 'te veel aanvragen voor dit account');
            return null;
        }
        if ($why = self::unavailable()) {
            self::log((int) $u['id'], $ident, 'niet verstuurd: ' . $why);
            return null;
        }

        $token = bin2hex(random_bytes(32));
        q('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, ?, ?)', [
            $u['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + self::VALID_MINUTES * 60), $ip,
        ]);
        self::log((int) $u['id'], $ident, 'link verstuurd');
        $link = rtrim((string) config('base_url'), '/') . '/?reset=' . $token;
        $body = "Hallo {$u['username']},\n\n"
            . "Iemand (hopelijk jij) heeft gevraagd om het wachtwoord van je RzMon-account opnieuw in te stellen.\n\n"
            . "Kies een nieuw wachtwoord via deze link (" . self::VALID_MINUTES . " minuten geldig, één keer te gebruiken):\n$link\n\n"
            . "Heb je dit niet gevraagd? Dan hoef je niets te doen: je wachtwoord blijft hetzelfde.\n\n"
            . "Aangevraagd vanaf IP-adres $ip op " . date('d-m-Y H:i') . '.';
        return static function () use ($u, $body): void {
            $r = Mailer::send('RzMon: wachtwoord opnieuw instellen', $body, null, (string) $u['email']);
            if (!$r['ok']) {
                self::log((int) $u['id'], (string) $u['username'], 'mail mislukt: ' . mb_substr((string) $r['error'], 0, 150));
            }
        };
    }

    /** Geldige, ongebruikte link → de rij met de gebruiker, anders null. */
    private static function find(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $r = q('SELECT p.id, p.user_id, p.tries, u.username, u.email, u.totp_secret, u.totp_last_step
                FROM password_resets p JOIN users u ON u.id = p.user_id
                WHERE p.token_hash = ? AND p.used_at IS NULL AND p.expires_at > NOW() AND p.tries < ?',
            [hash('sha256', $token), self::MAX_TRIES])->fetch();
        return $r ?: null;
    }

    /** @return array{username: string, totp: bool}|null */
    public static function check(string $token): ?array
    {
        $r = self::find($token);
        return $r ? ['username' => $r['username'], 'totp' => $r['totp_secret'] !== null] : null;
    }

    /**
     * Nieuw wachtwoord zetten.
     * @return true|string true, of een foutmelding
     */
    public static function reset(string $token, string $password, string $code): true|string
    {
        $r = self::find($token);
        if (!$r) {
            usleep(300000);
            return 'Deze link is verlopen of al gebruikt. Vraag een nieuwe aan via "Wachtwoord vergeten?".';
        }
        if (mb_strlen($password) < 8) {
            return 'Het wachtwoord moet minstens 8 tekens hebben';
        }
        if ($r['totp_secret'] !== null) {
            $step = Totp::verify($r['totp_secret'], $code, $r['totp_last_step'] !== null ? (int) $r['totp_last_step'] : null);
            if ($step === null) {
                q('UPDATE password_resets SET tries = tries + 1 WHERE id = ?', [$r['id']]);
                usleep(500000);
                $left = self::MAX_TRIES - (int) $r['tries'] - 1;
                return 'Onjuiste code uit je authenticator-app' . ($left > 0 ? " (nog $left poging" . ($left === 1 ? '' : 'en') . ')' : '; deze link werkt niet meer');
            }
            q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $r['user_id']]);
        }
        $uid = (int) $r['user_id'];
        q('UPDATE users SET password_hash = ?, pw_changed_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), now(), $uid]);
        q('UPDATE password_resets SET used_at = ? WHERE id = ?', [now(), $r['id']]);
        q('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$uid]);   // andere openstaande links ongeldig
        q('DELETE FROM remember_tokens WHERE user_id = ?', [$uid]);                        // onthouden apparaten vergeten
        self::log($uid, $r['username'], 'wachtwoord hersteld');
        if (filter_var((string) $r['email'], FILTER_VALIDATE_EMAIL)) {
            Mailer::send('RzMon: je wachtwoord is gewijzigd',
                "Hallo {$r['username']},\n\nHet wachtwoord van je RzMon-account is zojuist gewijzigd via \"Wachtwoord vergeten\" (IP-adres "
                . client_ip() . ', ' . date('d-m-Y H:i') . ").\nApparaten waarop je ingelogd bleef, zijn uitgelogd.\n\n"
                . 'Was jij dit niet? Neem dan direct contact op met je beheerder.', null, (string) $r['email']);
        }
        return true;
    }

    public static function log(?int $userId, string $ident, string $event): void
    {
        q('INSERT INTO password_reset_log (user_id, ident, ip, event) VALUES (?, ?, ?, ?)',
            [$userId, mb_substr($ident, 0, 190), mb_substr(client_ip(), 0, 45), mb_substr($event, 0, 200)]);
    }

    /** Opruimen (vanuit de purge in cron): verlopen links en logregels ouder dan 90 dagen. */
    public static function purge(): void
    {
        q('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY');
        q('DELETE FROM password_reset_log WHERE created_at < NOW() - INTERVAL 90 DAY');
    }
}
