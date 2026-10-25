<?php
declare(strict_types=1);

/**
 * PHP-sessies in de database (1.85.0) in plaats van losse bestanden. De opruimtaak van het systeem (Debian:
 * sessionclean, na session.gc_maxlifetime = 24 minuten) gooit ze dan niet meer weg; hoe lang een sessie zonder
 * activiteit blijft bestaan, bepaalt RzMon zelf (Instellingen → Toegang: "Uitloggen na … zonder activiteit").
 * Net als bij bestanden wacht een tweede verzoek van dezelfde sessie tot het eerste klaar is (GET_LOCK).
 */
final class SessionStore implements SessionHandlerInterface
{
    private ?string $lock = null;

    /** Gebruiken als de tabel er is; anders (setup, vlak voor een update) gewoon de bestanden van PHP */
    public static function register(): void
    {
        try {
            db()->query('SELECT 1 FROM php_sessions LIMIT 0');
        } catch (Throwable) {
            return;
        }
        ini_set('session.gc_probability', '0');   // opruimen doet de cron van RzMon
        session_set_save_handler(new self(), true);
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->lock !== null) {
            q('SELECT RELEASE_LOCK(?)', [$this->lock]);
            $this->lock = null;
        }
        return true;
    }

    public function read(string $id): string|false
    {
        $this->lock = 'rzs_' . sha1($id);
        q('SELECT GET_LOCK(?, 10)', [$this->lock]);
        $data = q('SELECT data FROM php_sessions WHERE id = ? AND updated_at >= ?', [$id, time() - session_ttl()])->fetchColumn();
        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        q('REPLACE INTO php_sessions (id, data, updated_at) VALUES (?, ?, ?)', [$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        q('DELETE FROM php_sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return self::purge();
    }

    /** Verlopen sessies weg (elke minuut via de cron) */
    public static function purge(): int
    {
        return q('DELETE FROM php_sessions WHERE updated_at < ?', [time() - session_ttl()])->rowCount();
    }
}
