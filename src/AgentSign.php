<?php
declare(strict_types=1);

/**
 * Ondertekende agent (1.139.0): klopt de handtekening (agent/*.sig, gemaakt in GitHub) bij de agent en het
 * installatiescript die RzSense uitlevert? Met de publieke sleutel die in de agent zelf staat (AGENT_SIGN_PUB).
 * Voor Status (Instellingen) en de Agent Manager; de agents controleren het zelf bij elke update.
 */
final class AgentSign
{
    /** Kan PHP de handtekening controleren? Daarvoor is de extensie sodium nodig (Rocky/RHEL: pakket php-sodium) */
    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * @return array{signed: bool, valid: bool, files: array<string, string>, nosodium: bool} per bestand: ok, missing, bad
     * of unknown (1.147.2: zonder sodium kan RzSense het niet controleren; dat gaf eerder een fatale fout op Status)
     */
    public static function status(?string $dir = null): array
    {
        $dir ??= SM_ROOT . '/agent';
        $files = [];
        $pub = self::publicKey($dir);
        $can = self::available();
        foreach (['rzmon-agent.sh', 'install-agent.sh'] as $f) {
            $path = "$dir/$f";
            $sig = @file_get_contents("$path.sig");
            if ($sig === false || trim($sig) === '') {
                $files[$f] = 'missing';
                continue;
            }
            if (!$can) {
                $files[$f] = 'unknown';
                continue;
            }
            $raw = base64_decode(trim($sig), true);
            $files[$f] = $pub !== null && $raw !== false && strlen($raw) === 64
                && sodium_crypto_sign_verify_detached($raw, (string) file_get_contents($path), $pub) ? 'ok' : 'bad';
        }
        $signed = !in_array('missing', $files, true);
        return ['signed' => $signed, 'valid' => $signed && $can && !in_array('bad', $files, true), 'files' => $files, 'nosodium' => !$can];
    }

    /** De ruwe ed25519-sleutel (32 bytes) uit AGENT_SIGN_PUB in de agent (DER: 12 bytes kop + 32 bytes sleutel) */
    private static function publicKey(string $dir): ?string
    {
        if (!preg_match('/^AGENT_SIGN_PUB="([A-Za-z0-9+\/=]+)"$/m', (string) @file_get_contents("$dir/rzmon-agent.sh"), $m)) {
            return null;
        }
        $der = base64_decode($m[1], true);
        return $der !== false && strlen($der) === 44 ? substr($der, 12) : null;
    }
}
