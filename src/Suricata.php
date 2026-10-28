<?php
declare(strict_types=1);

/**
 * Suricata (1.113.0, agent 1.64.0): draait Suricata op een server (bv. op een router of naast de firewall), dan stuurt
 * de agent de nieuwe alerts uit eve.json mee. Elk alert wordt een IDS-incident (per regel en bron), met de stap van
 * een aanval (MITRE) erbij voor de ketens. Ernst 1 van Suricata is critical, 2 warning; ernst 3 (en lager) alleen als
 * signaal op de pagina's Afwijkingen en MITRE. In de modus Blokkeren blokkeert RzMon een aanvaller van buiten bij
 * ernst 1 (suricata_block).
 */
final class Suricata
{
    /** Categorie van Suricata/Emerging Threats → [tactiek, techniek] */
    private const CATS = [
        'trojan' => ['command-and-control', 'T1071'], 'command and control' => ['command-and-control', 'T1071'], 'malware' => ['command-and-control', 'T1071'],
        'web application attack' => ['initial-access', 'T1190'], 'attempted administrator' => ['privilege-escalation', 'T1068'], 'attempted user privilege' => ['privilege-escalation', 'T1068'],
        'exploit' => ['initial-access', 'T1190'], 'network scan' => ['reconnaissance', 'T1595'], 'information leak' => ['discovery', 'T1046'],
        'denial of service' => ['impact', 'T1498'], 'credential' => ['credential-access', 'T1110'], 'policy' => ['', ''], 'coin mining' => ['impact', 'T1496'],
        'crypto currency mining' => ['impact', 'T1496'], 'shellcode' => ['execution', 'T1203'], 'suspicious' => ['', ''],
    ];

    public static function on(): bool
    {
        return Ids::enabled() && setting('suricata_on', '1') === '1';
    }

    /** @param array $list [[tijd, bron, poort, doel, poort, protocol, sid, melding, categorie, ernst, actie], …] */
    public static function process(array $host, array $list): void
    {
        if (!self::on()) {
            return;
        }
        $seen = [];
        foreach (array_slice($list, 0, 200) as $a) {
            if (!is_array($a) || count($a) < 10) {
                continue;
            }
            [$ts, $src, $sport, $dst, $dport, $proto, $sid, $sig, $cat, $sev] = array_slice($a, 0, 10);
            $act = (string) ($a[10] ?? '');
            $src = (string) $src;
            $dst = (string) $dst;
            if (@inet_pton($src) === false || @inet_pton($dst) === false) {
                continue;
            }
            $sig = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string) $sig), 0, 200);
            $cat = mb_substr((string) $cat, 0, 80);
            $sev = max(1, min(4, (int) $sev));
            [$tactic, $tech] = self::map($sig . ' ' . $cat);
            // De "aanvaller": de kant van buiten (bij een verbinding naar buiten, zoals C2, is dat het doel)
            $ext = !Ids::internalIp($src) ? $src : (!Ids::internalIp($dst) ? $dst : null);
            $key = (int) $sid . '|' . ($ext ?? $src);
            if (isset($seen[$key])) {
                continue;   // in dit rapport al gezien: één keer optellen is genoeg
            }
            $seen[$key] = true;
            $title = t('Suricata: {sig}', ['sig' => $sig]) . ($ext ? ' · ' . $ext : '');
            $detail = "$src:" . (int) $sport . " → $dst:" . (int) $dport . ' ' . mb_substr((string) $proto, 0, 8) . ' · ' . ($cat !== '' ? $cat . ' · ' : '')
                . t('ernst {n}', ['n' => $sev]) . ($act === 'blocked' ? ' · ' . t('Suricata blokkeerde het') : '') . ' · sid ' . (int) $sid;
            if ($sev >= 3) {
                Chain::signal($host, 'ids', 'suricata', $tactic !== '' ? [$tactic] : [], $tech !== '' ? [$tech] : [], $title, 20, $ext, null, "sur|$key", null, 60);
                continue;
            }
            if ($sev === 1 && $ext !== null && $ext === $src && setting('suricata_block', '1') === '1' && ($b = Ids::autoBlock($host, $ext, t('Suricata: {sig}', ['sig' => mb_substr($sig, 0, 80)])))) {
                $detail .= " · ⛔ $ext $b";
            }
            $id = Ids::raise($host, $sev === 1 ? 2 : 1, 'suricata', $key, $title, $detail, $ext);
            Edr::evidence($id, 'sur', ['ts' => (string) $ts, 'src' => $src, 'sport' => (int) $sport, 'dst' => $dst, 'dport' => (int) $dport, 'proto' => (string) $proto,
                'sid' => (int) $sid, 'sig' => $sig, 'cat' => $cat, 'sev' => $sev, 'act' => $act], 'suricata', ['title' => $sig, 'src' => 'suricata', 'tags' => $tech !== '' ? [$tech] : [],
                'author' => '', 'url' => '', 'what' => $cat, 'fp' => []]);
            Chain::signal($host, 'ids', 'suricata', $tactic !== '' ? [$tactic] : [], $tech !== '' ? [$tech] : [], $title, $sev === 1 ? 60 : 35, $ext, $id, "sur|$key", null, 10);
        }
    }

    /** @return array{0: string, 1: string} tactiek en techniek */
    private static function map(string $text): array
    {
        $t = mb_strtolower($text);
        foreach (self::CATS as $k => $v) {
            if (str_contains($t, $k)) {
                return $v;
            }
        }
        return ['', ''];
    }
}
