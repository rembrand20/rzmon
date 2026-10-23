<?php
declare(strict_types=1);

/**
 * Taal van de interface: Nederlands (de brontaal) of Engels.
 *
 * - Teksten staan in het Nederlands in de code, met t(). lang/en.php vertaalt ze; ontbreekt een vertaling,
 *   dan blijft het Nederlands (er breekt dus nooit iets).
 * - Variabele delen als {naam}: t('{n} min geleden', ['n' => 5]).
 * - Welke taal: die van de gebruiker (users.lang), anders de keuze op dit apparaat (cookie), anders de browser.
 * - tools/check-i18n.php meldt welke teksten nog geen Engelse vertaling hebben.
 */
final class I18n
{
    public const LANGS = ['nl', 'en'];
    public const COOKIE = 'rzmon_lang';

    private static string $lang = 'nl';
    private static ?array $dict = null;

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function set(string $lang): void
    {
        self::$lang = in_array($lang, self::LANGS, true) ? $lang : 'nl';
    }

    /** Taal kiezen voor dit verzoek: gebruiker, dan cookie, dan browser */
    public static function init(?array $user): string
    {
        $lang = null;
        if ($user && (int) ($user['id'] ?? 0) > 0) {
            try {
                $lang = q('SELECT lang FROM users WHERE id = ?', [(int) $user['id']])->fetchColumn() ?: null;
            } catch (Throwable) {
                $lang = null;   // kolom bestaat nog niet (vlak voor de database-update)
            }
        }
        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        self::set($lang ?? (in_array($cookie, self::LANGS, true) ? $cookie : self::browser()));
        return self::$lang;
    }

    /** Taal van de browser: Nederlands als die vóór Engels komt, anders Engels */
    public static function browser(): string
    {
        foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
            $code = substr(trim($part), 0, 2);
            if ($code === 'nl') {
                return 'nl';
            }
            if ($code !== '') {
                return 'en';
            }
        }
        return 'nl';
    }

    /** Engelse vertalingen (Nederlands => Engels) */
    public static function dict(): array
    {
        return self::$dict ??= (is_file(SM_ROOT . '/lang/en.php') ? (array) require SM_ROOT . '/lang/en.php' : []);
    }

    /** Keuze op dit apparaat onthouden (ook zonder account, bv. alleen lezen op het LAN) */
    public static function remember(string $lang): void
    {
        setcookie(self::COOKIE, $lang, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => false,
            'secure' => Https::isHttps()]);
    }

    /** Taal van meldingen en checkberichten (Instellingen → 🔔 Meldingen); die gaan naar iedereen tegelijk */
    public static function notifyLang(): string
    {
        $l = (string) setting('notify_lang', 'nl');
        return in_array($l, self::LANGS, true) ? $l : 'nl';
    }

    /** $fn uitvoeren in de taal van meldingen en checkberichten, daarna weer de taal van de gebruiker */
    public static function forNotify(callable $fn): mixed
    {
        $prev = self::$lang;
        self::set(self::notifyLang());
        try {
            return $fn();
        } finally {
            self::set($prev);
        }
    }

    /** $fn uitvoeren in de taal van een account (bv. voor een mail aan die gebruiker), daarna weer de huidige taal */
    public static function forUser(int $id, callable $fn): mixed
    {
        $prev = self::$lang;
        self::init(['id' => $id]);
        try {
            return $fn();
        } finally {
            self::set($prev);
        }
    }

    /** Decimaalteken: komma in het Nederlands, punt in het Engels */
    public static function dec(string $v): string
    {
        return self::$lang === 'en' ? $v : str_replace('.', ',', $v);
    }

    /** Locale voor datums en getallen in de browser */
    public static function locale(): string
    {
        return self::$lang === 'en' ? 'en-GB' : 'nl-NL';
    }
}

/** Tekst in de taal van de gebruiker; {naam} wordt vervangen door $vars['naam'] */
function t(string $nl, array $vars = []): string
{
    $s = I18n::lang() === 'en' ? (I18n::dict()[$nl] ?? $nl) : $nl;
    if ($vars) {
        $s = strtr($s, array_combine(array_map(static fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars))));
    }
    return $s;
}

/** t() voor in HTML */
function th(string $nl, array $vars = []): string
{
    return htmlspecialchars(t($nl, $vars), ENT_QUOTES);
}
