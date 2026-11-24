<?php
declare(strict_types=1);

/**
 * 1.320.0: het beveiligingskanaal. Een apart kanaal voor wat nu je aandacht nodig heeft: een isolatie die op jouw keuze
 * wacht, ransomware of malware, een webshell of verdacht proces, een login of nieuwe beheerder van buiten, een aanval die
 * niet tegengehouden is, of een lek dat je echt draait. Het komt harder binnen dan een gewone melding:
 * - Pushover als noodmelding (prioriteit 2: herhaalt elke minuut tot je bevestigt, hooguit een uur);
 * - Web Push die blijft staan tot je hem wegtikt, en ntfy op urgent;
 * - Home Assistant-app als kritieke melding (gaat door Niet storen en het stille knopje heen, iOS en Android);
 * - e-mail als vangnet.
 * Het gaat door stille uren heen. Web Push en Home Assistant herhalen elke 5 minuten tot iemand bevestigt (hooguit een
 * uur). Bevestigen ("Ik kijk ernaar") kan in RzSense of in de Pushover-app; dan stopt het overal. De knop in de melding
 * opent alleen RzSense: isoleren of iets anders doen gaat altijd in RzSense zelf.
 * Wat niet via dit kanaal gaat, blijft bij de gewone IDS-meldingen. Aan/uit: sec_ch_on (standaard uit).
 */
final class SecChannel
{
    /** Soorten: isolatie, ransomware en malware, webshell en verdacht proces, login of beheerder, niet tegengehouden, eigen lek */
    public const KINDS = ['iso', 'malware', 'edr', 'login', 'unhandled', 'cve'];
    private const TYPES = [
        'malware' => ['ransom', 'win_malware', 'ti_malware', 'av'],
        'edr' => ['webshell', 'revshell', 'proc_suspicious', 'edr', 'win_ps', 'chain'],
        'login' => ['breakin', 'rootlogin', 'newip', 'group_add', 'user_new', 'win_group', 'win_user', 'win_logclear', 'win_av_off'],
    ];
    /** 's Nachts (met sec_ch_night) alleen dit; de rest gaat als gewone melding */
    private const NIGHT_KINDS = ['iso', 'malware'];
    private const REPEAT = 300;
    private const MAX_AGE = 3600;

    public static function on(): bool
    {
        return setting('sec_ch_on', '0') === '1';
    }

    /** @return list<string> de soorten die aan staan */
    public static function kinds(): array
    {
        return array_values(array_intersect(self::KINDS, explode(',', (string) setting('sec_ch_kinds', 'iso,malware,edr,login,unhandled'))));
    }

    /**
     * Welke soort is dit incident voor het kanaal (null = niet via dit kanaal)?
     * @param array<string, mixed> $i een rij uit ids_incidents
     */
    public static function kindOf(array $i): ?string
    {
        if ((int) ($i['severity'] ?? 0) !== 2) {
            return null;
        }
        $type = (string) $i['type'];
        foreach (self::TYPES as $k => $types) {
            if (in_array($type, $types, true)) {
                return $k;
            }
        }
        if (in_array($type, IdsPush::NET_TYPES, true)) {
            if (IdsPush::cveMine($i) !== []) {
                return 'cve';
            }
            return IdsPush::needsAction($i) ? 'unhandled' : (setting('sec_ch_mode', 'action') === 'all' ? 'unhandled' : null);
        }
        return setting('sec_ch_mode', 'action') === 'all' ? 'unhandled' : null;
    }

    /** Gaat deze soort nu via het kanaal? ('s nachts met sec_ch_night alleen isolatie en malware) */
    public static function wants(string $kind, ?int $hour = null): bool
    {
        if (!self::on() || !in_array($kind, self::kinds(), true)) {
            return false;
        }
        $hour ??= (int) date('G');
        if (setting('sec_ch_night', '0') === '1' && ($hour >= 23 || $hour < 7)) {
            return in_array($kind, self::NIGHT_KINDS, true);
        }
        return true;
    }

    /**
     * Een nieuw critical IDS-incident: via het kanaal als het daarvoor is. @return bool true = verstuurd (dan niet ook gewoon)
     */
    public static function incident(int $incidentId, string $title, string $text): bool
    {
        $i = q('SELECT * FROM ids_incidents WHERE id = ?', [$incidentId])->fetch();
        if (!$i || ($kind = self::kindOf($i)) === null || !self::wants($kind)) {
            return false;
        }
        self::raise($kind, $title, $text, $incidentId, null, (int) $i['host_id']);
        return true;
    }

    /** Isolatie wacht op jouw keuze (IsoAsk). @return bool true = via het kanaal */
    public static function isoAsk(int $askId, int $hostId, string $title, string $text): bool
    {
        if (!self::wants('iso')) {
            return false;
        }
        self::raise('iso', $title, $text, null, $askId, $hostId);
        return true;
    }

    /**
     * Versturen en bewaren (om te herhalen en te bevestigen)
     * @return array{ok: bool, receipt: ?string, errors: list<string>, chat: ?bool}
     */
    public static function raise(string $kind, string $title, string $text, ?int $incidentId, ?int $askId, ?int $hostId, bool $test = false): array
    {
        $title = '🚨 ' . $title;
        $n = self::sendAll($title, $text, true);
        q('INSERT INTO sec_alerts (kind, incident_id, iso_id, host_id, title, body, receipt, created_at, last_sent, sends, test) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [$kind, $incidentId, $askId, $hostId, mb_substr($title, 0, 250), mb_substr($text, 0, 1000), $n['receipt'], now(), now(), $test ? 1 : 0]);
        q("INSERT INTO alerts (check_id, status, title, message, sent_ok, chat_ok, kind) VALUES (NULL, ?, ?, ?, ?, ?, 'ids')",
            [STATUS_CRIT, mb_substr($title, 0, 250), mb_substr($text . Notify::errText($n['errors']), 0, 1000), $n['ok'] ? 1 : 0, Notify::flag($n['chat'])]);
        return $n;
    }

    /**
     * Naar alle kanalen die aan staan. $first: de eerste keer (Pushover-noodmelding, ntfy en e-mail); daarna alleen Web Push
     * en Home Assistant (Pushover herhaalt zelf).
     * @return array{ok: bool, receipt: ?string, errors: list<string>, chat: ?bool}
     */
    public static function sendAll(string $title, string $text, bool $first): array
    {
        $classic = $first && setting('sec_ch_classic', '1') === '1';
        $n = Notify::send($title, $text, ['channels' => ['pushover' => $classic, 'webpush' => setting('sec_ch_web', '1') === '1',
            'email' => $first && setting('sec_ch_mail', '1') === '1', 'webhook' => false, 'chat' => $first], 'status' => STATUS_CRIT, 'event' => 'problem',
            'anchor' => '#ids', 'ids' => true, 'prio' => 2]);
        $ok = $n['push'] === true || $n['email'] === true;
        $errors = $n['errors'];
        if (setting('sec_ch_ha', '0') === '1') {
            $h = self::homeAssistant($title, $text);
            $ok = $ok || $h['ok'];
            if (!$h['ok']) {
                $errors[] = 'Home Assistant: ' . $h['error'];
            }
        }
        return ['ok' => $ok, 'receipt' => $n['receipt'], 'errors' => $errors, 'chat' => $n['chat']];
    }

    /** Geldig adres van Home Assistant (http(s)://naam[:poort], zonder pad) */
    public static function validHaUrl(string $u): bool
    {
        return (bool) preg_match('#^https?://[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?/?$#', $u) || (bool) preg_match('#^https?://\[[0-9a-fA-F:]{2,45}\](:[0-9]{1,5})?/?$#', $u);
    }

    /**
     * Kritieke melding naar de Home Assistant-app (notify.mobile_app_…): op iOS door Niet storen en het stille knopje heen,
     * op Android als alarmkanaal. De knop opent RzSense. Het token blijft op de server.
     * @return array{ok: bool, error: ?string}
     */
    public static function homeAssistant(string $title, string $text): array
    {
        $url = rtrim((string) setting('sec_ch_ha_url', ''), '/');
        $svc = (string) setting('sec_ch_ha_service', '');
        $token = (string) setting('sec_ch_ha_token', '');
        if (!self::validHaUrl($url) || !preg_match('/^mobile_app_[a-z0-9_]{1,64}$/', $svc) || $token === '') {
            return ['ok' => false, 'error' => t('adres, telefoon of token ontbreekt')];
        }
        $link = public_url() !== '' ? public_url() . '/#ids' : '';
        $body = ['title' => mb_substr($title, 0, 120), 'message' => mb_substr($text, 0, 900), 'data' => array_filter([
            'url' => $link, 'clickAction' => $link,
            'push' => ['sound' => ['name' => 'default', 'critical' => 1, 'volume' => 1.0], 'interruption-level' => 'critical'],
            'ttl' => 0, 'priority' => 'high', 'channel' => 'alarm_stream', 'tag' => 'rzsense-security',
        ])];
        $ch = curl_init("$url/api/services/notify/$svc");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) {
            return ['ok' => false, 'error' => t('cURL-fout') . ': ' . $err];
        }
        return $code >= 200 && $code < 300 ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => $code === 401 ? t('token geweigerd (401)') : ($code === 400 ? t('onbekende telefoon (notify.{s})', ['s' => $svc]) : "HTTP $code")];
    }

    /** @return list<array<string, mixed>> meldingen die nog op bevestiging wachten (voor de balk in RzSense) */
    public static function active(): array
    {
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'title' => $r['title'], 'body' => $r['body'], 'host' => $r['host'],
            'incident_id' => $r['incident_id'] !== null ? (int) $r['incident_id'] : null, 'iso_id' => $r['iso_id'] !== null ? (int) $r['iso_id'] : null,
            'at' => $r['created_at'], 'sends' => (int) $r['sends']],
            q('SELECT s.*, h.name AS host FROM sec_alerts s LEFT JOIN hosts h ON h.id = s.host_id WHERE s.ack_at IS NULL AND s.created_at >= ? ORDER BY s.id DESC LIMIT 10',
                [date('Y-m-d H:i:s', time() - self::MAX_AGE)])->fetchAll());
    }

    /** Bevestigen: het herhalen stopt overal (ook de Pushover-noodmelding). @return bool */
    public static function ack(int $id, string $by): bool
    {
        $r = q('SELECT * FROM sec_alerts WHERE id = ? AND ack_at IS NULL', [$id])->fetch();
        if (!$r) {
            return false;
        }
        q('UPDATE sec_alerts SET ack_at = ?, ack_by = ? WHERE id = ?', [now(), mb_substr($by, 0, 64), $id]);
        if (!empty($r['receipt']) && $by !== 'Pushover') {
            Pushover::cancel((string) $r['receipt']);
        }
        if ($r['host_id']) {
            ActionLog::add((int) $r['host_id'], 'rzsense', 'ids', t('Beveiligingsmelding bevestigd'), $r['title'] . ' · ' . t('door {u}', ['u' => $by]), true, '', null, $by);
        }
        return true;
    }

    /** Elke minuut: bevestigd in de Pushover-app? Anders Web Push en Home Assistant elke 5 minuten opnieuw, hooguit een uur. */
    public static function cron(): ?string
    {
        $n = 0;
        foreach (q('SELECT * FROM sec_alerts WHERE ack_at IS NULL AND created_at >= ?', [date('Y-m-d H:i:s', time() - self::MAX_AGE)])->fetchAll() as $r) {
            // Isolatie al beslist, of het incident afgehandeld: dan is er niets meer om naar te kijken
            if (($r['iso_id'] && q("SELECT state FROM iso_ask WHERE id = ?", [$r['iso_id']])->fetchColumn() !== 'open')
                || ($r['incident_id'] && q('SELECT status FROM ids_incidents WHERE id = ?', [$r['incident_id']])->fetchColumn() === 'done')) {
                self::ack((int) $r['id'], 'RzSense');
                continue;
            }
            if (!empty($r['receipt']) && ($rc = Pushover::receipt((string) $r['receipt'])) && !empty($rc['acknowledged'])) {
                self::ack((int) $r['id'], 'Pushover');
                continue;
            }
            if (strtotime((string) $r['last_sent']) <= time() - self::REPEAT) {
                self::sendAll((string) $r['title'], (string) $r['body'] . "\n" . t('Nog niet bevestigd ({n}e keer)', ['n' => (int) $r['sends'] + 1]), false);
                q('UPDATE sec_alerts SET last_sent = ?, sends = sends + 1 WHERE id = ?', [now(), $r['id']]);
                $n++;
            }
        }
        q('DELETE FROM sec_alerts WHERE created_at < NOW() - INTERVAL 90 DAY');
        return $n ? "Beveiligingskanaal: $n herhaald" : null;
    }

    /** @return array<string, mixed> instellingen voor de pagina (het token nooit, alleen of het er is) */
    public static function settings(): array
    {
        $last = q('SELECT s.created_at, s.title, s.ack_at, s.ack_by, h.name AS host FROM sec_alerts s LEFT JOIN hosts h ON h.id = s.host_id WHERE s.test = 0 ORDER BY s.id DESC LIMIT 1')->fetch();
        return ['on' => self::on(), 'mode' => setting('sec_ch_mode', 'action') === 'all' ? 'all' : 'action', 'kinds' => self::kinds(),
            'classic' => setting('sec_ch_classic', '1') === '1', 'web' => setting('sec_ch_web', '1') === '1', 'mail' => setting('sec_ch_mail', '1') === '1',
            'ha' => setting('sec_ch_ha', '0') === '1', 'ha_url' => (string) setting('sec_ch_ha_url', ''), 'ha_service' => (string) setting('sec_ch_ha_service', ''),
            'ha_token' => setting('sec_ch_ha_token', '') !== '', 'night' => setting('sec_ch_night', '0') === '1',
            'pushover' => setting('pushover_enabled', '0') === '1', 'ntfy' => Ntfy::enabled(), 'webpush' => WebPush::enabled(), 'email' => setting('email_enabled', '0') === '1',
            'month' => self::wouldHave(), 'last' => $last ?: null];
    }

    /**
     * Instellingen opslaan. @return string|null fout
     * @param array<string, mixed> $in
     */
    public static function save(array $in): ?string
    {
        $url = rtrim(trim((string) ($in['ha_url'] ?? '')), '/');
        $svc = trim((string) ($in['ha_service'] ?? ''));
        $svc = str_starts_with($svc, 'notify.') ? substr($svc, 7) : $svc;
        if ($url !== '' && !self::validHaUrl($url)) {
            return t('Geen geldig adres van Home Assistant (bv. https://homeassistant.local:8123)');
        }
        if ($svc !== '' && !preg_match('/^mobile_app_[a-z0-9_]{1,64}$/', $svc)) {
            return t('De telefoon heet in Home Assistant notify.mobile_app_… (Ontwikkelaarshulpmiddelen → Acties)');
        }
        if (!empty($in['ha']) && ($url === '' || $svc === '' || (setting('sec_ch_ha_token', '') === '' && trim((string) ($in['ha_token'] ?? '')) === ''))) {
            return t('Voor Home Assistant zijn het adres, de telefoon en een token nodig');
        }
        $kinds = array_values(array_intersect(self::KINDS, array_map('strval', (array) ($in['kinds'] ?? []))));
        foreach (['on', 'classic', 'web', 'mail', 'ha', 'night'] as $k) {
            save_setting("sec_ch_$k", !empty($in[$k]) ? '1' : '0');
        }
        save_setting('sec_ch_mode', ($in['mode'] ?? '') === 'all' ? 'all' : 'action');
        save_setting('sec_ch_kinds', implode(',', $kinds));
        save_setting('sec_ch_ha_url', $url);
        save_setting('sec_ch_ha_service', $svc);
        $tok = trim((string) ($in['ha_token'] ?? ''));
        if ($tok !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{20,1000}$/', $tok)) {
                return t('Dit lijkt geen token van Home Assistant (profiel → Beveiliging → Langlevende toegangstokens)');
            }
            save_setting('sec_ch_ha_token', $tok);
        }
        return null;
    }

    /** Hoe vaak zou het kanaal de afgelopen 30 dagen afgegaan zijn, met de soorten die nu aan staan? */
    public static function wouldHave(): int
    {
        $n = 0;
        foreach (q("SELECT * FROM ids_incidents WHERE severity = 2 AND created_at >= NOW() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 2000")->fetchAll() as $i) {
            $k = self::kindOf($i);
            $n += $k !== null && in_array($k, self::kinds(), true) ? 1 : 0;
        }
        $n += in_array('iso', self::kinds(), true) ? (int) q('SELECT COUNT(*) FROM iso_ask WHERE created_at >= NOW() - INTERVAL 30 DAY')->fetchColumn() : 0;
        return $n;
    }
}
