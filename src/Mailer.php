<?php
declare(strict_types=1);

/**
 * Meldingen per e-mail via SMTP (zonder extra bibliotheken).
 * Ondersteunt STARTTLS (poort 587), SSL/TLS (poort 465) en onversleuteld (poort 25, alleen intern).
 */
final class Mailer
{
    /** @var resource|null */
    private $fp = null;

    /**
     * @param array $cfg optioneel andere instellingen (voor de testknop), anders uit de database
     * @return array{ok: bool, error: ?string}
     */
    public static function send(string $subject, string $body, ?array $cfg = null, ?string $to = null): array
    {
        $cfg ??= self::config();
        $to ??= (string) $cfg['mail_to'];
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $to))));
        if ($cfg['smtp_host'] === '') {
            return ['ok' => false, 'error' => 'Geen SMTP-server ingesteld'];
        }
        if (!$recipients) {
            return ['ok' => false, 'error' => 'Geen ontvanger ingesteld'];
        }
        foreach ($recipients as $r) {
            if (!filter_var($r, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => "Ongeldig e-mailadres: $r"];
            }
        }
        $from = $cfg['mail_from'];
        if ($from === '') {
            // Geen afzender ingesteld: de login als die een e-mailadres is, anders rzmon@<servernaam>
            $from = filter_var($cfg['smtp_user'], FILTER_VALIDATE_EMAIL) ? $cfg['smtp_user'] : 'rzmon@' . (gethostname() ?: 'localhost');
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $from = 'rzmon@localhost.localdomain';
            }
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => "Ongeldig afzenderadres: $from"];
        }

        $m = new self();
        try {
            $m->connect($cfg);
            $m->cmd('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $r) {
                $m->cmd('RCPT TO:<' . $r . '>', [250, 251]);
            }
            $m->cmd('DATA', [354]);
            $m->cmd(self::message($from, $recipients, $subject, $body) . "\r\n.", [250]);
            $m->cmd('QUIT', [221], false);
            return ['ok' => true, 'error' => null];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if ($m->fp) {
                fclose($m->fp);
            }
        }
    }

    public static function config(): array
    {
        $keys = ['smtp_host' => '', 'smtp_port' => '587', 'smtp_security' => 'starttls', 'smtp_user' => '', 'smtp_pass' => '',
            'smtp_verify_ssl' => '1', 'mail_from' => '', 'mail_to' => ''];
        $out = [];
        foreach ($keys as $k => $default) {
            $out[$k] = trim((string) setting($k, $default));
        }
        return $out;
    }

    private function connect(array $cfg): void
    {
        $security = $cfg['smtp_security'];
        $port = (int) $cfg['smtp_port'] ?: ($security === 'ssl' ? 465 : 587);
        $verify = $cfg['smtp_verify_ssl'] !== '0';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
            'peer_name' => $cfg['smtp_host'], 'SNI_enabled' => true,
        ]]);
        $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['smtp_host'] . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("Kan geen verbinding maken met {$cfg['smtp_host']}:$port"
                . ($errstr ? " ($errstr)" : '') . ($security === 'ssl' ? ' — klopt de poort (465) en het certificaat?' : ''));
        }
        $this->fp = $fp;
        stream_set_timeout($fp, 15);
        $this->expect([220]);
        $ehlo = 'EHLO ' . (gethostname() ?: 'rzmon');
        $caps = $this->cmd($ehlo, [250]);

        if ($security === 'starttls') {
            if (!preg_match('/STARTTLS/i', $caps)) {
                throw new RuntimeException('De server ondersteunt geen STARTTLS; kies "SSL/TLS" (poort 465) of "geen"');
            }
            $this->cmd('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('TLS-handshake mislukt' . ($verify ? ' (self-signed certificaat? zet certificaatcontrole uit)' : ''));
            }
            $caps = $this->cmd($ehlo, [250]);
        }

        if ($cfg['smtp_user'] !== '') {
            if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $caps)) {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0{$cfg['smtp_user']}\0{$cfg['smtp_pass']}"), [235]);
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($cfg['smtp_user']), [334]);
                $this->cmd(base64_encode($cfg['smtp_pass']), [235]);
            }
        }
    }

    /** Stuurt een regel en controleert de antwoordcode. Geeft het volledige antwoord terug. */
    private function cmd(string $line, array $ok, bool $strict = true): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($ok, $strict);
    }

    private function expect(array $ok, bool $strict = true): string
    {
        $resp = '';
        while (($l = fgets($this->fp, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($resp, 0, 3);
        if ($strict && !in_array($code, $ok, true)) {
            $msg = trim($resp) !== '' ? trim(preg_replace('/\s+/', ' ', $resp)) : 'geen antwoord (time-out) — klopt de beveiliging? 465 = SSL/TLS, 587 = STARTTLS';
            $hint = match (true) {
                $code === 535 || $code === 534 => ' — gebruikersnaam/wachtwoord onjuist (bij Gmail/Outlook: gebruik een app-wachtwoord)',
                $code === 530 => ' — de server wil eerst inloggen of TLS',
                $code === 550 || $code === 553 || $code === 554 => ' — adres geweigerd; mag dit account met dit afzenderadres mailen?',
                default => '',
            };
            throw new RuntimeException('SMTP: ' . mb_substr($msg, 0, 200) . $hint);
        }
        return $resp;
    }

    private static function encodeHeader(string $v): string
    {
        return preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
    }

    private static function message(string $from, array $to, string $subject, string $body): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . self::encodeHeader('RzMon') . " <$from>",
            'To: ' . implode(', ', $to),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@rzmon>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: RzMon ' . RZMON_VERSION,
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
    }
}
