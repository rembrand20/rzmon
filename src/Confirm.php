<?php
declare(strict_types=1);

/**
 * 1.206.0: bevestigen bij acties. Per rol (Instellingen → Gebruikers → Bevestigen bij acties) kan een vinkje
 * "Ik weet het zeker" het wachtwoord vervangen, maar alleen zolang je de laatste N minuten (standaard 60) je wachtwoord
 * hebt ingevuld: bij het inloggen of bij een eerdere bevestiging. Zaken rond je account vragen altijd je wachtwoord.
 * Op het lokale netwerk (automatisch ingelogd, zonder wachtwoord) vervangt het vinkje het getypte woord.
 */
final class Confirm
{
    /** Wat de browser stuurt als het vinkje aan staat */
    public const SURE = '__sure__';
    /** Altijd met wachtwoord: passkey, sleutels, back-up/certificaat downloaden, databasewachtwoord en -gegevens, iedereen uitloggen */
    public const ALWAYS = ['PASSKEY', 'SLEUTEL', 'DOWNLOADEN', 'VERNIEUWEN', 'UITLOGGEN', 'DATABASE', 'ACCOUNT', 'SUDO'];   // 1.231.0: SUDO = tijdelijk sudo geven   // 1.224.1: ACCOUNT = e-mailadres en 2FA
    public const ROLES = ['admin' => '1', 'user' => '0', 'readonly' => '0'];   // standaard: alleen beheerders

    public static function graceMin(): int
    {
        return max(1, min(1440, (int) setting('confirm_grace_min', '60')));
    }

    /** Mag deze rol met een vinkje bevestigen (de schakelaar)? */
    public static function roleSkips(array $user): bool
    {
        $r = (string) ($user['role'] ?? '');
        return isset(self::ROLES[$r]) && setting("confirm_skip_$r", self::ROLES[$r]) === '1';
    }

    /** Tot wanneer het vinkje geldt (0 = nu je wachtwoord nodig) */
    public static function until(): int
    {
        $at = (int) ($_SESSION['pw_at'] ?? 0);
        return $at > 0 ? $at + self::graceMin() * 60 : 0;
    }

    public static function canSkip(array $user, string $word): bool
    {
        if (in_array($word, self::ALWAYS, true) || !self::roleSkips($user)) {
            return false;
        }
        return is_lan_session() || time() < self::until();
    }

    /** Je wachtwoord is net ingevuld (inloggen of bevestigen): het vinkje geldt weer N minuten */
    public static function passwordEntered(): void
    {
        $_SESSION['pw_at'] = time();
    }

    /** Voor de browser: welk veld confirmField() laat zien */
    public static function state(array $user): array
    {
        return ['skip' => self::roleSkips($user), 'lan' => is_lan_session(), 'left' => max(0, self::until() - time()), 'grace' => self::graceMin(), 'always' => self::ALWAYS];
    }

    /** Het vinkje werd gestuurd, maar geldt (nu) niet: waarom, en wat in plaats daarvan */
    public static function refusal(array $user, string $word): string
    {
        if (is_lan_session()) {
            return t('Typ {word} om te bevestigen', ['word' => t($word)]);
        }
        if (self::roleSkips($user) && !in_array($word, self::ALWAYS, true)) {
            return t('Bevestig nog één keer met je wachtwoord: je hebt het langer dan {n} min geleden ingevuld', ['n' => self::graceMin()]);
        }
        return t('Bevestig met je eigen wachtwoord');
    }
}
