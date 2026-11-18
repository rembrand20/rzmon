<?php
declare(strict_types=1);

/**
 * 1.284.0: meldingen naar Discord, Slack en Microsoft Teams, via hun webhook (een geheime URL per kanaal).
 *
 * - Een chatkanaal volgt de beslissing voor push (Pushover/ntfy): stille uren, 🔕 altijd stil, melden na N keer, flappen,
 *   escaleren en bundelen gelden dus vanzelf. Per kanaal komt er nog een filter bij: vanaf warning of alleen critical,
 *   ook herstel, ook info en systeemmeldingen, ook IDS.
 * - De webhook-URL is een geheim (SECRET_SETTINGS): hij gaat nooit naar de browser. RzSense stuurt alleen naar de
 *   echte adressen van de dienst (https, eigen domein), zonder doorverwijzingen te volgen, en met een gecontroleerd
 *   certificaat.
 * - Bevestigen: de link in het bericht (de bevestig-link van een storing, anders RzSense zelf). Een knop in de chat
 *   zelf zou betekenen dat Discord, Slack of Microsoft RzSense van buiten moeten kunnen bereiken; dat doen we niet.
 */
final class Chat
{
    public const KINDS = ['discord' => 'Discord', 'slack' => 'Slack', 'teams' => 'Microsoft Teams'];
    /** Toegestane hosts per dienst: exact, of met een punt ervoor: elk subdomein */
    private const HOSTS = [
        'discord' => ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'],
        'slack' => ['hooks.slack.com'],
        'teams' => ['.logic.azure.com', '.powerplatform.com', '.webhook.office.com'],
    ];
    private const COLOR = [STATUS_OK => 0x16a34a, STATUS_WARN => 0xd97706, STATUS_CRIT => 0xdc2626, STATUS_UNKNOWN => 0x6b7280];

    /** Is dit een geldige webhook-URL voor deze dienst? */
    public static function validUrl(string $kind, string $url): bool
    {
        $p = parse_url($url);
        if (!isset(self::HOSTS[$kind]) || !is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])
            || (isset($p['port']) && (int) $p['port'] !== 443) || empty($p['path']) || strlen($url) > 1000) {
            return false;
        }
        $host = strtolower((string) ($p['host'] ?? ''));
        foreach (self::HOSTS[$kind] as $h) {
            if ($h[0] === '.' ? str_ends_with($host, $h) && strlen($host) > strlen($h) : $host === $h) {
                return true;
            }
        }
        return false;
    }

    public static function on(string $kind): bool
    {
        return setting("chat_{$kind}_on", '0') === '1' && self::validUrl($kind, (string) setting("chat_{$kind}_url", ''));
    }

    public static function enabled(): bool
    {
        foreach (array_keys(self::KINDS) as $k) {
            if (self::on($k)) {
                return true;
            }
        }
        return false;
    }

    /** Wil dit kanaal deze melding? (bovenop de beslissing voor push) */
    public static function wants(string $kind, int $status, string $event, bool $ids = false): bool
    {
        if ($ids) {
            return setting("chat_{$kind}_ids", '0') === '1' && ($status === STATUS_CRIT || setting("chat_{$kind}_min", 'warn') === 'warn');
        }
        if ($event === 'recovery' || ($event === 'stable' && $status === STATUS_OK)) {
            return setting("chat_{$kind}_recovery", '1') === '1';
        }
        if ($status === STATUS_OK || in_array($event, ['info', 'system', 'unauthorized'], true)) {
            return setting("chat_{$kind}_info", '0') === '1';
        }
        return $status === STATUS_CRIT || setting("chat_{$kind}_min", 'warn') === 'warn';
    }

    /**
     * Naar elk kanaal dat aan staat en de melding wil. $url = de link in het bericht (bevestigen of RzSense zelf).
     * @return array{ok: ?bool, errors: list<string>} ok: null = geen kanaal gebruikt, anders of alles lukte
     */
    public static function send(string $title, string $message, int $status, string $event, string $url, ?string $host = null, bool $ids = false, ?array $only = null): array
    {
        $ok = null;
        $errors = [];
        foreach (self::KINDS as $kind => $label) {
            if (($only !== null && !in_array($kind, $only, true)) || !self::on($kind) || ($only === null && !self::wants($kind, $status, $event, $ids))) {
                continue;
            }
            $r = self::post((string) setting("chat_{$kind}_url", ''), self::payload($kind, $title, $message, $status, $url, $host));
            $ok = ($ok ?? true) && $r['ok'];
            if (!$r['ok']) {
                $errors[] = "$label: " . $r['error'];
            }
        }
        return ['ok' => $ok, 'errors' => $errors];
    }

    /** Het bericht in de vorm van de dienst */
    public static function payload(string $kind, string $title, string $message, int $status, string $url, ?string $host): array
    {
        $title = mb_substr(trim($title), 0, 240);
        $message = mb_substr(trim($message), 0, 2800);
        $statusText = (string) (STATUS_LABELS[$status] ?? '');
        $linkText = str_contains($url, 'ack') ? t('Bevestigen') : t('Open RzSense');
        if ($kind === 'discord') {
            $embed = ['title' => $title, 'description' => $message, 'color' => self::COLOR[$status] ?? self::COLOR[STATUS_UNKNOWN], 'timestamp' => date('c'),
                'footer' => ['text' => 'RzSense'], 'fields' => array_values(array_filter([
                    $host ? ['name' => t('Server'), 'value' => mb_substr($host, 0, 100), 'inline' => true] : null,
                    $statusText !== '' ? ['name' => t('Status'), 'value' => $statusText, 'inline' => true] : null]))];
            if ($url !== '') {
                $embed['url'] = $url;
                $embed['fields'][] = ['name' => "\u{200B}", 'value' => "[$linkText]($url)", 'inline' => false];
            }
            // Nooit @everyone of @rollen laten werken vanuit de tekst van een melding
            return ['username' => 'RzSense', 'embeds' => [$embed], 'allowed_mentions' => ['parse' => []]];
        }
        if ($kind === 'slack') {
            $esc = static fn(string $s): string => strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
            $ctx = trim(($host ? "🖥 {$esc($host)} · " : '') . ($statusText !== '' ? "$statusText · " : '') . date('d-m-Y H:i:s'));
            $blocks = [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_substr($title, 0, 150), 'emoji' => true]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $esc($message) ?: ' ']],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $ctx]]],
            ];
            if ($url !== '') {
                $blocks[] = ['type' => 'actions', 'elements' => [['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $linkText], 'url' => $url]]];
            }
            return ['text' => mb_substr("$title: $message", 0, 300), 'blocks' => $blocks, 'unfurl_links' => false];
        }
        // Teams (Workflows-webhook): een Adaptive Card
        $color = [STATUS_OK => 'Good', STATUS_WARN => 'Warning', STATUS_CRIT => 'Attention'][$status] ?? 'Default';
        $facts = array_values(array_filter([$host ? ['title' => t('Server'), 'value' => $host] : null, $statusText !== '' ? ['title' => t('Status'), 'value' => $statusText] : null,
            ['title' => t('Tijd'), 'value' => date('d-m-Y H:i:s')]]));
        $card = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => [
            ['type' => 'TextBlock', 'text' => $title, 'weight' => 'Bolder', 'size' => 'Medium', 'color' => $color, 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $message, 'wrap' => true],
            ['type' => 'FactSet', 'facts' => $facts]]];
        if ($url !== '') {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => $linkText, 'url' => $url]];
        }
        return ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]]];
    }

    /** @return array{ok: bool, error: ?string} */
    private static function post(string $url, array $payload): array
    {
        if (!preg_match('#^https://#i', $url)) {
            return ['ok' => false, 'error' => t('Geen geldige webhook-URL ingesteld')];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'RzSense/' . RZMON_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'error' => t('cURL-fout') . ": $err"];
        }
        return $code >= 200 && $code < 300 ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => "HTTP $code" . ($code === 429 ? ' (' . t('te veel berichten, de dienst remt af') . ')' : '')];
    }

    /** Testbericht naar één kanaal, met de (nog niet opgeslagen) URL uit het formulier, of de opgeslagen */
    public static function test(string $kind, string $url): array
    {
        if (!isset(self::KINDS[$kind])) {
            return ['ok' => false, 'error' => t('Onbekend kanaal')];
        }
        $url = $url === '' || $url === '••••••••' ? (string) setting("chat_{$kind}_url", '') : trim($url);
        if (!self::validUrl($kind, $url)) {
            return ['ok' => false, 'error' => t('Geen geldige webhook-URL voor {k} (https, adres van de dienst zelf)', ['k' => self::KINDS[$kind]])];
        }
        $base = public_url();
        return self::post($url, self::payload($kind, '🧪 RzSense test', t('Als je dit leest werkt {k}! 🎉', ['k' => self::KINDS[$kind]]), STATUS_OK, $base, null));
    }
}
