<?php
declare(strict_types=1);

/**
 * RzMon bijwerken vanuit de webinterface (Instellingen → ⬆ RzMon bijwerken).
 *
 * - Eén keer per dag (of met "Nu controleren") het nieuwste versienummer en de changelog van GitHub halen.
 * - Bijwerken: de webpagina zet alleen /var/lib/rzmon/update.request neer; de hulpdienst (root) voert dan
 *   install/rzmon-update.sh uit (back-up, git pull, install-rocky.sh). Zie install/install-helper.sh.
 * - Automatisch bijwerken: elke dag op een vast tijdstip, als er een nieuwere versie is.
 */
final class Updater
{
    private const DIR = Installer::HELPER_DIR;
    private const CHECK_EVERY = 86400;

    /** GitHub-repository en branch van deze installatie (uit .git), anders de standaard. */
    public static function source(): array
    {
        $repo = 'rembrand20/rzmon';
        $branch = 'main';
        $git = SM_ROOT . '/.git';
        if (preg_match('#url\s*=\s*(?:https://github\.com/|git@github\.com:)([\w.-]+/[\w.-]+?)(?:\.git)?\s*$#mi', (string) @file_get_contents("$git/config"), $m)) {
            $repo = $m[1];
        }
        if (preg_match('#^ref: refs/heads/([\w./-]+)#', (string) @file_get_contents("$git/HEAD"), $m)) {
            $branch = $m[1];
        }
        return [$repo, $branch];
    }

    /** Versie en changelog op GitHub ophalen (hooguit één keer per dag, tenzij $force). */
    public static function check(bool $force = false): array
    {
        $cache = json_decode((string) setting('update_cache', ''), true) ?: [];
        if (!$force && ($cache['checked_at'] ?? 0) > time() - self::CHECK_EVERY) {
            return $cache;
        }
        [$repo, $branch] = self::source();
        $base = "https://raw.githubusercontent.com/$repo/$branch";
        $version = self::fetch("$base/VERSION");
        $cache = ['checked_at' => time(), 'latest' => null, 'notes' => [], 'error' => null];
        if ($version === null || !preg_match('/^\d+\.\d+\.\d+$/', trim($version))) {
            $cache['error'] = t('Kan de nieuwste versie niet ophalen van {url}', ['url' => "github.com/$repo"]);
        } else {
            $cache['latest'] = trim($version);
            if (version_compare($cache['latest'], RZMON_VERSION, '>')) {
                $cache['notes'] = self::notes((string) self::fetch("$base/CHANGELOG.md"));
            }
        }
        save_setting('update_cache', json_encode($cache, JSON_UNESCAPED_UNICODE));
        return $cache;
    }

    private static function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'RzMon/' . RZMON_VERSION]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($body) && $code === 200 ? $body : null;
    }

    /** Changelog-secties van versies nieuwer dan deze (hooguit 15, nieuwste eerst). */
    private static function notes(string $md): array
    {
        $out = [];
        foreach (preg_split('/^## /m', $md) as $sec) {
            if (!preg_match('/^(\d+\.\d+\.\d+)\s*(?:[–-]\s*(\S+))?\s*\n(.*)$/su', $sec, $m) || !version_compare($m[1], RZMON_VERSION, '>')) {
                continue;
            }
            $out[] = ['version' => $m[1], 'date' => $m[2] ?? '', 'text' => mb_substr(trim($m[3]), 0, 4000)];
            if (count($out) >= 15) {
                break;
            }
        }
        return $out;
    }

    /** null = bijwerken vanuit RzMon kan; anders de reden. */
    public static function unavailable(): ?string
    {
        if (!is_dir(SM_ROOT . '/.git')) {
            return t('RzMon is niet met install-rocky.sh (git) geïnstalleerd; werk handmatig bij');
        }
        if (!is_file(self::DIR . '/update.installed')) {
            return t('De hulpdienst voor bijwerken is nog niet geïnstalleerd');
        }
        if (!is_writable(self::DIR)) {
            return t('RzMon kan niet schrijven in {dir}', ['dir' => self::DIR]);
        }
        return null;
    }

    /** Voortgang van de laatste update (uit update.status), of null. */
    public static function status(): ?array
    {
        $st = json_decode((string) @file_get_contents(self::DIR . '/update.status'), true);
        if (is_file(self::DIR . '/update.request')) {
            // Verzoek staat klaar; de hulpdienst heeft het nog niet opgepakt
            $at = (int) @filemtime(self::DIR . '/update.request');
            return ['state' => 'queued', 'step' => 'queued', 'message' => t('Wachten op de hulpdienst…'), 'at' => $at,
                'stuck' => $at < time() - 120];
        }
        return is_array($st) ? $st : null;
    }

    public static function running(): bool
    {
        $st = self::status();
        // Een update die al een half uur "bezig" is, is waarschijnlijk afgebroken
        return $st && in_array($st['state'], ['queued', 'running'], true) && ($st['state'] === 'queued' || (int) $st['at'] > time() - 1800);
    }

    /** Update aanvragen. @return true|string */
    public static function request(bool $backup, string $by): true|string
    {
        if ($why = self::unavailable()) {
            return $why;
        }
        if (self::running()) {
            return t('Er loopt al een update');
        }
        $tmp = self::DIR . '/update.request.tmp';
        if (file_put_contents($tmp, json_encode(['backup' => $backup, 'by' => $by, 'at' => time()])) === false || !rename($tmp, self::DIR . '/update.request')) {
            return t('Kan geen verzoek neerzetten in {dir}', ['dir' => self::DIR]);
        }
        save_setting('update_requested', json_encode(['from' => RZMON_VERSION, 'by' => $by, 'at' => time()]));
        return true;
    }

    /** Vanuit cron (elke minuut): dagelijks controleren, en eventueel automatisch bijwerken. */
    public static function cron(): ?string
    {
        $cache = self::check();
        if (setting('update_auto', '0') !== '1' || empty($cache['latest']) || !version_compare($cache['latest'], RZMON_VERSION, '>')) {
            return null;
        }
        $time = (string) setting('update_time', '04:00');
        $due = strtotime(date('Y-m-d') . ' ' . (preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '04:00'));
        // Eén poging per dag, in het eerste uur na het ingestelde tijdstip
        if (time() < $due || time() > $due + 3600 || setting('update_auto_day', '') === date('Y-m-d')) {
            return null;
        }
        save_setting('update_auto_day', date('Y-m-d'));
        $r = self::request(setting('update_backup', '1') === '1', 'automatisch');
        return $r === true ? t('Automatische update naar {v} gestart', ['v' => $cache['latest']]) : t('Automatische update niet gestart') . ": $r";
    }
}
