<?php
declare(strict_types=1);

/**
 * Signature detection (1.110.0): regels in het formaat van Sigma (de open standaard voor detectieregels) tegen een
 * gebeurtenis van een server houden. Twee bronnen, zelfde vorm:
 * - de Linux-regels van SigmaHQ (rules/sigma-linux.json, omgezet met tools/sigma-convert.py; licentie DRL 1.1: bij elke
 *   treffer staan de titel, de auteur en de bron);
 * - de eigen regels van RzMon (EdrRules), met Nederlandse uitleg en een automatische reactie.
 *
 * Een regel: 'sel' (benoemde selecties: een lijst van AND-groepen, OR ertussen) en 'cond' (boom met ref/and/or/not).
 * Een voorwaarde: veld, bewerking (eq, glob, contains, startswith, endswith, re, cidr, exists, null), waarden, all.
 * Tekst vergelijken gaat zonder hoofdlettergevoeligheid, zoals Sigma voorschrijft; reguliere expressies wel.
 */
final class Sigma
{
    private static ?array $rules = null;

    /** De Sigma-regels van SigmaHQ (leeg als het bestand ontbreekt) */
    public static function rules(): array
    {
        if (self::$rules === null) {
            $d = json_decode((string) @file_get_contents(SM_ROOT . '/rules/sigma-linux.json'), true);
            self::$rules = is_array($d['rules'] ?? null) ? $d['rules'] : [];
        }
        return self::$rules;
    }

    public static function match(array $rule, array $ev): bool
    {
        return self::cond($rule['cond'] ?? [], $rule['sel'] ?? [], $ev);
    }

    private static function cond(array $c, array $sel, array $ev): bool
    {
        if (isset($c['ref'])) {
            foreach ($sel[$c['ref']] ?? [] as $group) {   // OR
                if (self::group($group, $ev)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($c['not'])) {
            return !self::cond($c['not'], $sel, $ev);
        }
        if (isset($c['and'])) {
            foreach ($c['and'] as $x) {
                if (!self::cond($x, $sel, $ev)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($c['or'])) {
            foreach ($c['or'] as $x) {
                if (self::cond($x, $sel, $ev)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function group(array $group, array $ev): bool
    {
        foreach ($group as $t) {   // AND
            if (!self::test($t, $ev)) {
                return false;
            }
        }
        return true;
    }

    private static function test(array $t, array $ev): bool
    {
        $v = $ev[$t['f']] ?? null;
        $vals = $v === null ? [] : array_map('strval', is_array($v) ? $v : [$v]);
        $vals = array_values(array_filter($vals, static fn($x) => $x !== ''));
        if ($t['op'] === 'exists') {
            return $vals !== [];
        }
        if ($t['op'] === 'null') {
            return $vals === [];
        }
        if ($vals === []) {
            return false;
        }
        $want = (array) ($t['v'] ?? []);
        $one = static function (string $w) use ($vals, $t): bool {
            foreach ($vals as $x) {
                if (self::op((string) $t['op'], $x, $w)) {
                    return true;
                }
            }
            return false;
        };
        if (!empty($t['all'])) {
            foreach ($want as $w) {
                if (!$one((string) $w)) {
                    return false;
                }
            }
            return true;
        }
        foreach ($want as $w) {
            if ($one((string) $w)) {
                return true;
            }
        }
        return false;
    }

    private static function op(string $op, string $x, string $w): bool
    {
        return match ($op) {
            'eq' => strcasecmp($x, $w) === 0,
            'glob' => fnmatch($w, $x, FNM_CASEFOLD | FNM_NOESCAPE),
            'contains' => stripos($x, $w) !== false,
            'startswith' => strncasecmp($x, $w, strlen($w)) === 0,
            'endswith' => $w === '' || strcasecmp(substr($x, -strlen($w)), $w) === 0,
            're' => @preg_match("\x01" . $w . "\x01", $x) === 1,
            'cidr' => @inet_pton($x) !== false && ip_in_list($x, [$w]),
            default => false,
        };
    }
}
