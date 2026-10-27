<?php
declare(strict_types=1);

/**
 * SNMP: apparaten uitlezen vanaf RzMon zelf (switches, routers, access points, NAS, UPS, printers, Proxmox…).
 *
 * - Per server aan te zetten: hosts.snmp (JSON) met versie (2c of 3), poort, community of v3-gebruiker.
 * - Checks van type "snmp": metric = wat (zie METRICS), target = index uit de discovery (interface, schijf…)
 *   of, bij "oid", de OID zelf. Tellers (verkeer, eigen OID als teller) bewaren hun vorige meting in snmp_state.
 * - Vereist de PHP-extensie snmp (Rocky: dnf install php-snmp; install-rocky.sh doet dat).
 * - Geeft een apparaat geen antwoord, dan wordt de check UNKNOWN; of het apparaat bereikbaar is, bepaalt de ping.
 */
final class SnmpMonitor
{
    /** @var array<int, true> servers waarvan de soort in deze run al is uitgezocht */
    private static array $kindDone = [];
    public const MASK = '••••••••';
    public const SECRETS = ['community', 'auth_pass', 'priv_pass'];
    public const AUTH = ['SHA', 'SHA256', 'SHA512', 'MD5'];
    public const PRIV = ['AES', 'DES'];
    public const LEVELS = ['noAuthNoPriv', 'authNoPriv', 'authPriv'];

    /** Wat een SNMP-check kan bewaken: label, index nodig (target), lager = slechter, standaarddrempels. */
    public const METRICS = [
        'uptime' => ['label' => 'Uptime', 'index' => false, 'low' => true, 'warn' => 1, 'crit' => null],
        'if' => ['label' => 'Interface', 'index' => true, 'low' => false, 'warn' => 80, 'crit' => 95],
        'cpu' => ['label' => 'CPU', 'index' => false, 'low' => false, 'warn' => 85, 'crit' => 95],
        'mem' => ['label' => 'Geheugen', 'index' => false, 'low' => false, 'warn' => 85, 'crit' => 95],
        'storage' => ['label' => 'Opslag', 'index' => true, 'low' => false, 'warn' => 80, 'crit' => 90],
        'load' => ['label' => 'Load', 'index' => false, 'low' => false, 'warn' => 4, 'crit' => 8],
        'temp' => ['label' => 'Temperatuur', 'index' => false, 'low' => false, 'warn' => 60, 'crit' => 75],
        'ups_status' => ['label' => 'UPS status', 'index' => false, 'low' => false, 'warn' => null, 'crit' => null],
        'ups_charge' => ['label' => 'UPS batterij', 'index' => false, 'low' => true, 'warn' => 50, 'crit' => 20],
        'ups_runtime' => ['label' => 'UPS looptijd', 'index' => false, 'low' => true, 'warn' => 15, 'crit' => 5],
        'ups_load' => ['label' => 'UPS belasting', 'index' => false, 'low' => false, 'warn' => 80, 'crit' => 95],
        'ups_temp' => ['label' => 'UPS batterijtemperatuur', 'index' => false, 'low' => false, 'warn' => 35, 'crit' => 45],
        'syno_system' => ['label' => 'Synology systeem', 'index' => false, 'low' => false, 'warn' => 60, 'crit' => 70],
        'syno_raid' => ['label' => 'Synology volume', 'index' => true, 'low' => false, 'warn' => 80, 'crit' => 90],
        'syno_disk' => ['label' => 'Synology schijf', 'index' => true, 'low' => false, 'warn' => 50, 'crit' => 60],
        'printer' => ['label' => 'Printer', 'index' => true, 'low' => true, 'warn' => 20, 'crit' => 5],
        // UniFi (1.74.0): 'index' => 'name' = een tekst als doel (band of SSID), leeg mag bij clients (= totaal) en AP
        'unifi_clients' => ['label' => 'UniFi clients', 'index' => 'name', 'low' => false, 'warn' => null, 'crit' => null],
        'unifi_ap' => ['label' => 'UniFi AP', 'index' => false, 'low' => false, 'warn' => null, 'crit' => null],
        'unifi_cu' => ['label' => 'UniFi kanaalbezetting', 'index' => 'name', 'low' => false, 'warn' => 60, 'crit' => 80],
        'unifi_ssid' => ['label' => 'UniFi SSID', 'index' => 'name', 'low' => false, 'warn' => null, 'crit' => null],
        'unifi_traffic' => ['label' => 'UniFi verkeer', 'index' => 'name', 'low' => false, 'warn' => null, 'crit' => null],
        'unifi_quality' => ['label' => 'UniFi kwaliteit', 'index' => 'name', 'low' => false, 'warn' => 15, 'crit' => 30],
        'oid' => ['label' => 'OID', 'index' => true, 'low' => false, 'warn' => null, 'crit' => null],
    ];

    // Vaste OID's (zonder punt vooraan)
    private const SYS = ['descr' => '1.3.6.1.2.1.1.1.0', 'object' => '1.3.6.1.2.1.1.2.0', 'uptime' => '1.3.6.1.2.1.1.3.0',
        'name' => '1.3.6.1.2.1.1.5.0', 'location' => '1.3.6.1.2.1.1.6.0'];
    private const HR_UPTIME = '1.3.6.1.2.1.25.1.1.0';               // hrSystemUptime: van het systeem zelf (sysUpTime is bij net-snmp die van snmpd)
    private const HR_CPU = '1.3.6.1.2.1.25.3.3.1.2';                 // hrProcessorLoad (per core, %)
    private const HR_STORAGE = '1.3.6.1.2.1.25.2.3.1';               // hrStorageEntry: 2 type, 3 descr, 4 units, 5 size, 6 used
    private const HR_RAM = '.1.3.6.1.2.1.25.2.1.2';
    private const HR_DISK = ['.1.3.6.1.2.1.25.2.1.4', '.1.3.6.1.2.1.25.2.1.10'];   // vaste schijf, netwerkschijf
    private const UCD_CPU_IDLE = '1.3.6.1.4.1.2021.11.11.0';
    private const UCD_MEM = ['total' => '1.3.6.1.4.1.2021.4.5.0', 'avail' => '1.3.6.1.4.1.2021.4.6.0',
        'buffer' => '1.3.6.1.4.1.2021.4.14.0', 'cached' => '1.3.6.1.4.1.2021.4.15.0'];
    private const UCD_LOAD = '1.3.6.1.4.1.2021.10.1.3';              // .1/.2/.3 = 1/5/15 minuten
    private const UCD_SENSORS = '1.3.6.1.4.1.2021.13.16.2.1';        // lm-sensors: 2 naam, 3 temperatuur (m°C)
    private const TPL_CPU = '1.3.6.1.4.1.11863.6.4.1.1.1.1.3';      // TP-Link: CPU 1 minuut (%)
    private const TPL_MEM = '1.3.6.1.4.1.11863.6.4.1.2.1.1.2';      // TP-Link: geheugen (%)
    private const MT_TEMP = '1.3.6.1.4.1.14988.1.1.3.10.0';         // MikroTik: temperatuur (×10 °C)
    private const UNIFI_STATIONS = '1.3.6.1.4.1.41112.1.6.1.2.1.8'; // UniFi AP: clients per radio/SSID
    // UBNT-UniFi-MIB: radio's (1 index, 2 naam, 3 radio "ng"/"na"/"6e", 6 kanaalbezetting %, 7/8 eigen ontvangst/zenden %)
    private const UNIFI_RADIO = '1.3.6.1.4.1.41112.1.6.1.1.1';
    // VAP = een SSID op één radio: 3 CCQ, 4 kanaal, 6 SSID, 8 clients, 9 radio, 10/16 bytes in/uit, 19 pakketten uit, 20 herhalingen, 22 aan (1/2)
    private const UNIFI_VAP = '1.3.6.1.4.1.41112.1.6.1.2.1';
    // Het AP zelf: 1 IP, 2 isolated (1 = geen bekabelde verbinding), 3 model, 4 uplink, 6 firmware
    private const UNIFI_SYS = '1.3.6.1.4.1.41112.1.6.3';
    private const IF = '1.3.6.1.2.1.2.2.1';                          // ifEntry: 2 descr, 3 type, 5 speed, 7 admin, 8 oper, 10/16 octets, 13/19 discards, 14/20 errors
    private const IFX = '1.3.6.1.2.1.31.1.1.1';                      // ifXEntry: 1 name, 6/10 HC octets, 15 highspeed, 18 alias
    private const UPS = '1.3.6.1.2.1.33.1';                          // UPS-MIB (RFC 1628): Eaton, CyberPower, Riello…
    private const APC = '1.3.6.1.4.1.318.1.1.1';                     // APC PowerNet
    private const SYNO = '1.3.6.1.4.1.6574';
    private const PRT = '1.3.6.1.2.1.43.11.1.1';                     // printer-supplies: 6 naam, 8 max, 9 niveau

    /** Fabrikant aan het enterprise-nummer in sysObjectID */
    private const VENDORS = [8072 => 'Linux (net-snmp)', 41112 => 'Ubiquiti UniFi', 11863 => 'TP-Link', 6574 => 'Synology', 24681 => 'QNAP',
        318 => 'APC', 534 => 'Eaton', 3808 => 'CyberPower', 14988 => 'MikroTik', 9 => 'Cisco', 4526 => 'Netgear', 11 => 'HP', 674 => 'Dell',
        2636 => 'Juniper', 12356 => 'Fortinet', 25461 => 'Palo Alto', 171 => 'D-Link', 2011 => 'Huawei', 30065 => 'Arista', 4413 => 'Ubiquiti EdgeSwitch',
        10002 => 'Ubiquiti', 1588 => 'Brocade', 6876 => 'VMware', 311 => 'Microsoft', 2435 => 'Brother', 367 => 'Ricoh', 1602 => 'Canon', 18334 => 'Konica Minolta',
        1347 => 'Kyocera', 253 => 'Xerox', 641 => 'Lexmark', 1248 => 'Epson'];

    /** Servers waarvan SNMP in deze run al niet antwoordde: andere checks daarvan niet ook laten wachten */
    private static array $down = [];

    public static function available(): bool
    {
        return class_exists('SNMP');
    }

    /** Instellingen van een server (hosts.snmp), of null als SNMP uit staat */
    public static function config(?string $json): ?array
    {
        $c = json_decode((string) $json, true);
        return is_array($c) && in_array($c['v'] ?? '', ['2c', '3'], true) ? $c : null;
    }

    /** Voor de browser: zonder community en wachtwoorden (alleen of ze ingevuld zijn) */
    public static function masked(?string $json): ?array
    {
        $c = self::config($json);
        if ($c === null) {
            return null;
        }
        foreach (self::SECRETS as $k) {
            $c[$k] = ($c[$k] ?? '') !== '' ? self::MASK : '';
        }
        return $c;
    }

    /**
     * Instellingen uit het formulier controleren. Gemaskeerde geheimen ($old) blijven staan.
     * @return array|string|null de instellingen, een foutmelding, of null (SNMP uit)
     */
    public static function clean(mixed $in, ?array $old): array|string|null
    {
        if (!is_array($in) || empty($in['on'])) {
            return null;
        }
        $v = in_array($in['v'] ?? '', ['2c', '3'], true) ? $in['v'] : '2c';
        $port = (int) ($in['port'] ?? 161);
        if ($port < 1 || $port > 65535) {
            return t('SNMP: kies een poort van 1 tot 65535 (standaard 161)');
        }
        $c = ['v' => $v, 'port' => $port];
        $keep = static fn(string $k): string => ($in[$k] ?? '') === self::MASK ? (string) ($old[$k] ?? '') : (string) ($in[$k] ?? '');
        if ($v === '2c') {
            $c['community'] = $keep('community');
            if ($c['community'] === '' || mb_strlen($c['community']) > 100) {
                return t('SNMP: vul de community in (vaak "public"; liever een eigen naam)');
            }
            return $c;
        }
        $c['user'] = trim((string) ($in['user'] ?? ''));
        if (!preg_match('/^[\w.@-]{1,64}$/u', $c['user'])) {
            return t('SNMP v3: vul de gebruikersnaam in (letters, cijfers, . _ - @)');
        }
        $c['level'] = in_array($in['level'] ?? '', self::LEVELS, true) ? $in['level'] : 'authPriv';
        if ($c['level'] !== 'noAuthNoPriv') {
            $c['auth_proto'] = in_array($in['auth_proto'] ?? '', self::AUTH, true) ? $in['auth_proto'] : 'SHA';
            $c['auth_pass'] = $keep('auth_pass');
            if (strlen($c['auth_pass']) < 8) {
                return t('SNMP v3: het auth-wachtwoord moet minstens 8 tekens hebben');
            }
        }
        if ($c['level'] === 'authPriv') {
            $c['priv_proto'] = in_array($in['priv_proto'] ?? '', self::PRIV, true) ? $in['priv_proto'] : 'AES';
            $c['priv_pass'] = $keep('priv_pass');
            if (strlen($c['priv_pass']) < 8) {
                return t('SNMP v3: het privacy-wachtwoord moet minstens 8 tekens hebben');
            }
        }
        return $c;
    }

    // ------------------------------------------------------------------ verbinding

    private static function session(array $cfg, string $address): SnmpConn
    {
        if (!self::available()) {
            throw new RuntimeException(t('De PHP-extensie snmp ontbreekt op de RzMon-server. Rocky Linux: sudo dnf install php-snmp en herstart PHP (of voer install-rocky.sh opnieuw uit)'));
        }
        $addr = trim($address, '[]');
        $peer = (str_contains($addr, ':') ? "udp6:[$addr]" : $addr) . ':' . (int) ($cfg['port'] ?? 161);
        if (($cfg['v'] ?? '2c') === '3') {
            $s = new SNMP(SNMP::VERSION_3, $peer, (string) ($cfg['user'] ?? ''), 1500000, 1);
            $level = $cfg['level'] ?? 'authPriv';
            $ok = @$s->setSecurity($level,
                $level === 'noAuthNoPriv' ? '' : (string) ($cfg['auth_proto'] ?? 'SHA'), $level === 'noAuthNoPriv' ? '' : (string) ($cfg['auth_pass'] ?? ''),
                $level === 'authPriv' ? (string) ($cfg['priv_proto'] ?? 'AES') : '', $level === 'authPriv' ? (string) ($cfg['priv_pass'] ?? '') : '');
            if ($ok === false) {
                throw new RuntimeException(t('SNMP v3: deze combinatie van beveiliging wordt niet ondersteund') . ' (' . $s->getError() . ')');
            }
        } else {
            $s = new SNMP(SNMP::VERSION_2c, $peer, (string) ($cfg['community'] ?? ''), 1500000, 1);
        }
        $s->valueretrieval = SNMP_VALUE_PLAIN;
        $s->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $s->quick_print = true;
        return new SnmpConn($s, $peer);
    }

    /** Systeeminformatie (voor 🔍 Test SNMP): naam, beschrijving, uptime, fabrikant en de herkende soort apparaat */
    public static function test(array $cfg, string $address): array
    {
        $c = self::session($cfg, $address);
        $sys = $c->get(array_values(self::SYS));
        if (!$sys) {
            throw new RuntimeException(t('Het apparaat antwoordt, maar geeft geen systeeminformatie (SNMPv2-MIB)'));
        }
        $info = self::sysInfo($sys);
        return $info + ['kind' => self::kindOf($c, $info)];
    }

    /**
     * Soort apparaat (1.75.0) uit wat het apparaat via SNMP vertelt: eerst de eigen MIB's (UniFi, Synology, UPS,
     * printer), dan het enterprise-nummer en de beschrijving. Onbekend = "other".
     */
    public static function kindOf(SnmpConn $c, array $sys): string
    {
        $d = strtolower($sys['descr'] . ' ' . $sys['name']);
        $ent = preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)/', (string) $sys['object'], $m) ? (int) $m[1] : 0;
        $has = static fn(string ...$w) => (bool) array_filter($w, fn($x) => str_contains($d, $x));
        if ($c->walk(self::UNIFI_VAP . '.6') || $c->walk(self::UNIFI_STATIONS)) {
            return 'ap';
        }
        // Camera's (1.76.0): Axis, Hikvision, Dahua, Hanwha, of de beschrijving
        if (in_array($ent, [368, 39165, 1004849, 36849], true) || $has('camera', 'ipcam', 'hikvision', 'dahua', 'reolink', 'amcrest', 'axis ', 'nvr')) {
            return 'camera';
        }
        if ($ent === 6574 || $ent === 24681 || $c->one(self::SYNO . '.1.1.0') !== null || $has('synology', 'qnap', 'truenas', 'freenas', 'unraid')) {
            return 'nas';
        }
        if (in_array($ent, [318, 534, 3808, 935], true) || $c->one(self::UPS . '.1.1.0') !== null) {
            return 'ups';
        }
        if (in_array($ent, [2435, 367, 1602, 18334, 1347, 253, 1248, 641], true) || $c->walk(self::PRT . '.6.1')) {
            return 'printer';
        }
        if (in_array($ent, [12356, 25461, 2620, 21067], true) || $has('pfsense', 'opnsense', 'fortigate', 'sophos', 'firewall')) {
            return 'firewall';
        }
        if ($has('unifi ap', 'uap-', 'u6-', 'u7-', 'eap', 'access point', 'cap ac', 'wap ')) {
            return 'ap';
        }
        if ($has('usw', 'switch', 'crs3', 'crs1', 'css', 'jetstream', 'procurve', 'catalyst', 'gs1', 'gs7', 'xgs', 'edgeswitch')) {
            return 'switch';
        }
        if ($ent === 14988 || $has('router', 'routeros', 'edgeos', 'edgerouter', 'udm', 'dream machine', 'usg', 'uxg', 'gateway', 'fritz', 'vyos', 'openwrt')) {
            return 'router';
        }
        if (in_array($ent, [9, 4526, 11, 2636, 171, 2011, 30065, 4413, 1588, 47196, 890, 11863, 41112, 10002], true)) {
            return 'switch';
        }
        if (in_array($ent, [8072, 311, 6876, 674, 2021], true) || $has('linux', 'windows', 'freebsd', 'pve', 'proxmox', 'esxi', 'ubuntu', 'debian')) {
            return 'server';
        }
        return 'other';
    }

    private static function sysInfo(array $sys): array
    {
        $object = (string) ($sys[self::SYS['object']] ?? '');
        $ent = preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)/', $object, $m) ? (int) $m[1] : 0;
        $descr = trim((string) ($sys[self::SYS['descr']] ?? ''));
        $vendor = self::VENDORS[$ent] ?? ($ent ? "enterprise $ent" : '');
        if (preg_match('/-pve\b/', $descr)) {
            $vendor = 'Proxmox VE';
        }
        return ['name' => (string) ($sys[self::SYS['name']] ?? ''), 'descr' => mb_substr($descr, 0, 300), 'location' => (string) ($sys[self::SYS['location']] ?? ''),
            'uptime' => isset($sys[self::SYS['uptime']]) ? (int) floor((int) $sys[self::SYS['uptime']] / 100) : null, 'object' => $object, 'vendor' => $vendor];
    }

    // ------------------------------------------------------------------ check uitvoeren

    public static function check(array $check, array $host): array
    {
        $cfg = self::config($host['snmp'] ?? null);
        if ($cfg === null) {
            return self::res(STATUS_UNKNOWN, null, t('SNMP staat uit voor deze server (✎ Server bewerken → 📡 SNMP)'));
        }
        if (isset(self::$down[$host['id']])) {
            return self::res(STATUS_UNKNOWN, null, self::$down[$host['id']]);
        }
        try {
            $c = self::session($cfg, (string) $host['address']);
            // Soort apparaat nog niet herkend (bv. van vóór 1.75.0): één keer per run uitzoeken
            if (empty($host['kind_auto']) && !isset(self::$kindDone[$host['id']])) {
                self::$kindDone[$host['id']] = true;
                try {
                    HostKind::setAuto((int) $host['id'], self::kindOf($c, self::sysInfo($c->get(array_values(self::SYS)))));
                } catch (Throwable) {
                    // niet erg: volgende keer opnieuw
                }
            }
            $m = (string) $check['metric'];
            return match ($m) {
                'uptime' => self::uptime($c, $check),
                'if' => self::iface($c, $check),
                'cpu' => self::cpu($c, $check),
                'mem' => self::mem($c, $check),
                'storage' => self::storage($c, $check),
                'load' => self::load($c, $check),
                'temp' => self::temp($c, $check),
                'ups_status' => self::upsStatus($c),
                'ups_charge', 'ups_runtime', 'ups_load', 'ups_temp' => self::upsValue($c, $check, $m),
                'syno_system' => self::synoSystem($c, $check),
                'syno_raid' => self::synoRaid($c, $check),
                'syno_disk' => self::synoDisk($c, $check),
                'printer' => self::printer($c, $check),
                'unifi_clients' => self::unifi($c, $check),
                'unifi_ap' => self::unifiAp($c, $check),
                'unifi_cu' => self::unifiCu($c, $check),
                'unifi_ssid' => self::unifiSsid($c, $check),
                'unifi_traffic' => self::unifiTraffic($c, $check),
                'unifi_quality' => self::unifiQuality($c, $check),
                'oid' => self::oid($c, $check),
                default => self::res(STATUS_UNKNOWN, null, 'Onbekende SNMP-meting'),
            };
        } catch (SnmpDown $e) {
            self::$down[$host['id']] = $e->getMessage();
            return self::res(STATUS_UNKNOWN, null, $e->getMessage());
        }
    }

    private static function res(int $status, ?float $value, string $message): array
    {
        return ['status' => $status, 'value' => $value, 'message' => $message];
    }

    /** Status volgens de drempels; bij "low" is lager slechter */
    private static function level(?float $v, array $check, bool $low): int
    {
        if ($v === null) {
            return STATUS_OK;
        }
        $w = $check['warn_threshold'];
        $cr = $check['crit_threshold'];
        if ($low) {
            return $cr !== null && $v < (float) $cr ? STATUS_CRIT : ($w !== null && $v < (float) $w ? STATUS_WARN : STATUS_OK);
        }
        return $cr !== null && $v >= (float) $cr ? STATUS_CRIT : ($w !== null && $v >= (float) $w ? STATUS_WARN : STATUS_OK);
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function missing(string $what): array
    {
        return self::res(STATUS_UNKNOWN, null, t($what) . ': ' . t('het apparaat geeft deze gegevens niet (meer) via SNMP'));
    }

    private static function uptime(SnmpConn $c, array $check): array
    {
        $r = $c->get([self::HR_UPTIME, self::SYS['uptime']]);
        $t = self::num($r[self::HR_UPTIME] ?? $r[self::SYS['uptime']] ?? null);
        if ($t === null) {
            return self::missing('Uptime');
        }
        $s = (int) floor($t / 100);
        $h = round($s / 3600, 1);
        $st = self::level($h, $check, true);
        $res = self::res($st, $h, ($st !== STATUS_OK ? t('Net herstart') . ' · ' : '') . 'uptime ' . self::duration($s)
            . ($s > 497 * 86400 * 0.95 ? ' (' . t('de teller loopt na 497 dagen over: dat is geen herstart') . ')' : ''));
        // Korter dan de vorige keer = herstart (info); niet als de teller net is overgelopen
        return ($info = Checks::restartInfo($check, $h, 497 * 24.0)) ? $res + ['info' => $info] : $res;
    }

    private static function iface(SnmpConn $c, array $check): array
    {
        $i = (string) $check['target'];
        $o = [
            'descr' => self::IF . ".2.$i", 'speed' => self::IF . ".5.$i", 'admin' => self::IF . ".7.$i", 'oper' => self::IF . ".8.$i",
            'in' => self::IF . ".10.$i", 'out' => self::IF . ".16.$i", 'indisc' => self::IF . ".13.$i", 'inerr' => self::IF . ".14.$i",
            'outdisc' => self::IF . ".19.$i", 'outerr' => self::IF . ".20.$i",
            'name' => self::IFX . ".1.$i", 'hcin' => self::IFX . ".6.$i", 'hcout' => self::IFX . ".10.$i", 'high' => self::IFX . ".15.$i", 'alias' => self::IFX . ".18.$i",
        ];
        $r = $c->get(array_values($o));
        $g = static fn(string $k) => $r[$o[$k]] ?? null;
        if ($g('oper') === null) {
            return self::res(STATUS_UNKNOWN, null, t('Interface {i} bestaat niet (meer) op dit apparaat; kies hem opnieuw via ＋ SNMP-checks', ['i' => $i]));
        }
        $name = (string) ($g('name') ?: $g('descr') ?: "interface $i");
        $alias = trim((string) $g('alias'));
        $label = $name . ($alias !== '' && $alias !== $name ? " ($alias)" : '');
        if ((int) $g('admin') === 2) {
            return self::res(STATUS_OK, null, t('{name} is uitgeschakeld (admin down)', ['name' => $label]));
        }
        $oper = (int) $g('oper');
        $operTxt = [1 => 'up', 2 => 'down', 3 => 'testing', 4 => t('onbekend'), 5 => 'dormant', 6 => t('niet aanwezig'), 7 => 'lower layer down'][$oper] ?? (string) $oper;
        if (!in_array($oper, [1, 5], true)) {
            return self::res(STATUS_CRIT, null, t('{name} is {state}', ['name' => $label, 'state' => $operTxt]));
        }
        $speed = (float) $g('high') > 0 ? (float) $g('high') * 1e6 : (float) ($g('speed') ?? 0);
        $hc = $g('hcin') !== null && $g('hcout') !== null;
        $now = microtime(true);
        $cur = ['t' => $now, 'in' => (float) ($hc ? $g('hcin') : $g('in')), 'out' => (float) ($hc ? $g('hcout') : $g('out')), 'hc' => $hc,
            'err' => (float) $g('inerr') + (float) $g('outerr') + (float) $g('indisc') + (float) $g('outdisc')];
        $prev = self::state((int) $check['id']);
        self::saveState((int) $check['id'], $cur);
        $msg = "$label $operTxt" . ($speed > 0 ? ' · ' . self::bits($speed) : '');
        if (!$prev || ($prev['hc'] ?? null) !== $hc || $now - (float) $prev['t'] < 1) {
            return self::res(STATUS_OK, null, "$msg · " . t('verkeer volgt na de volgende meting'));
        }
        $dt = $now - (float) $prev['t'];
        $rate = static function (float $a, float $b) use ($hc, $dt): ?float {
            $d = $a - $b;
            if ($d < 0) {
                if ($hc) {
                    return null;   // teller opnieuw begonnen (herstart)
                }
                $d += 4294967296;   // 32-bits teller liep over
            }
            return $d * 8 / $dt;
        };
        $in = $rate($cur['in'], (float) $prev['in']);
        $out = $rate($cur['out'], (float) $prev['out']);
        if ($in === null || $out === null) {
            return self::res(STATUS_OK, null, "$msg · " . t('tellers opnieuw begonnen (herstart?)'));
        }
        $util = $speed > 0 ? round(max($in, $out) / $speed * 100, 1) : null;
        $errs = max(0, $cur['err'] - (float) ($prev['err'] ?? 0));
        $msg .= ' · ↓ ' . self::bits($in) . ' · ↑ ' . self::bits($out) . ($util !== null ? ' · ' . t('{n}% bezet', ['n' => $util]) : '')
            . ($errs > 0 ? ' · ' . t('{n} fout(en)/verloren', ['n' => (int) $errs]) : '');
        return self::res(self::level($util, $check, false), $util, $msg);
    }

    private static function cpu(SnmpConn $c, array $check): array
    {
        [$pct, $info] = self::cpuValue($c);
        if ($pct === null) {
            return self::missing('CPU');
        }
        return self::res(self::level($pct, $check, false), $pct, "CPU $pct%$info");
    }

    /** CPU in %: HOST-RESOURCES (per core), TP-Link, of net-snmp (100 − idle) */
    private static function cpuValue(SnmpConn $c): array
    {
        $avg = static fn(array $a) => $a ? round(array_sum(array_map('floatval', $a)) / count($a), 1) : null;
        $hr = array_filter($c->walk(self::HR_CPU), 'is_numeric');
        if ($hr) {
            return [$avg($hr), count($hr) > 1 ? ' (' . count($hr) . ' cores)' : ''];
        }
        $tp = array_filter($c->walk(self::TPL_CPU), 'is_numeric');
        if ($tp) {
            return [$avg($tp), ' (1 min)'];
        }
        $idle = self::num($c->one(self::UCD_CPU_IDLE));
        return $idle !== null ? [round(100 - $idle, 1), ''] : [null, ''];
    }

    private static function mem(SnmpConn $c, array $check): array
    {
        [$pct, $info] = self::memValue($c);
        if ($pct === null) {
            return self::missing('Geheugen');
        }
        return self::res(self::level($pct, $check, false), $pct, t('Geheugen {pct}% in gebruik', ['pct' => $pct]) . $info);
    }

    /** Geheugen in %: net-snmp (zonder buffers/cache), TP-Link, of HOST-RESOURCES (RAM-regel) */
    private static function memValue(SnmpConn $c): array
    {
        $u = $c->get(array_values(self::UCD_MEM));
        $total = self::num($u[self::UCD_MEM['total']] ?? null);
        if ($total > 0) {
            $used = $total - (float) ($u[self::UCD_MEM['avail']] ?? 0) - (float) ($u[self::UCD_MEM['buffer']] ?? 0) - (float) ($u[self::UCD_MEM['cached']] ?? 0);
            return [round(max(0, $used) / $total * 100, 1), ' ' . t('van {size} (zonder buffers/cache)', ['size' => self::bytes($total * 1024)])];
        }
        $tp = array_filter($c->walk(self::TPL_MEM), 'is_numeric');
        if ($tp) {
            return [round(array_sum(array_map('floatval', $tp)) / count($tp), 1), ''];
        }
        foreach ($c->walk(self::HR_STORAGE . '.2') as $i => $type) {
            if ($type === self::HR_RAM) {
                $s = self::hrStorage($c, (string) $i);
                if ($s && $s['size'] > 0) {
                    return [round($s['used'] / $s['size'] * 100, 1), ' ' . t('van {size}', ['size' => self::bytes($s['size'])])];
                }
            }
        }
        return [null, ''];
    }

    private static function hrStorage(SnmpConn $c, string $i): ?array
    {
        $o = ['descr' => self::HR_STORAGE . ".3.$i", 'units' => self::HR_STORAGE . ".4.$i", 'size' => self::HR_STORAGE . ".5.$i", 'used' => self::HR_STORAGE . ".6.$i"];
        $r = $c->get(array_values($o));
        if (!isset($r[$o['size']])) {
            return null;
        }
        $units = max(1, (float) ($r[$o['units']] ?? 1));
        // Teller van 32 bits: grote volumes kunnen negatief overlopen
        $fix = static fn(float $v) => $v < 0 ? $v + 4294967296 : $v;
        return ['descr' => (string) ($r[$o['descr']] ?? ''), 'size' => $fix((float) $r[$o['size']]) * $units, 'used' => $fix((float) ($r[$o['used']] ?? 0)) * $units];
    }

    private static function storage(SnmpConn $c, array $check): array
    {
        $s = self::hrStorage($c, (string) $check['target']);
        if (!$s) {
            return self::res(STATUS_UNKNOWN, null, t('Opslag {i} bestaat niet (meer) op dit apparaat; kies hem opnieuw via ＋ SNMP-checks', ['i' => $check['target']]));
        }
        if ($s['size'] <= 0) {
            return self::res(STATUS_UNKNOWN, null, $s['descr'] . ': ' . t('grootte onbekend'));
        }
        $pct = round($s['used'] / $s['size'] * 100, 1);
        return self::res(self::level($pct, $check, false), $pct,
            sprintf(t('%s %s%% van %s in gebruik (vrij %s)'), $s['descr'], $pct, self::bytes($s['size']), self::bytes($s['size'] - $s['used'])));
    }

    private static function load(SnmpConn $c, array $check): array
    {
        $r = $c->get([self::UCD_LOAD . '.1', self::UCD_LOAD . '.2', self::UCD_LOAD . '.3']);
        $l5 = self::num($r[self::UCD_LOAD . '.2'] ?? null);
        if ($l5 === null) {
            return self::missing('Load');
        }
        return self::res(self::level($l5, $check, false), $l5, sprintf(t('Load %s (5 min) · %s %s %s'),
            $l5, $r[self::UCD_LOAD . '.1'] ?? '?', $l5, $r[self::UCD_LOAD . '.3'] ?? '?'));
    }

    private static function temp(SnmpConn $c, array $check): array
    {
        [$t, $src] = self::tempValue($c);
        if ($t === null) {
            return self::missing('Temperatuur');
        }
        return self::res(self::level($t, $check, false), $t, t('Temperatuur') . " $t °C$src");
    }

    /** Temperatuur: MikroTik, of de warmste sensor van lm-sensors (Linux/Proxmox met net-snmp) */
    private static function tempValue(SnmpConn $c): array
    {
        $mt = self::num($c->one(self::MT_TEMP));
        if ($mt !== null) {
            return [round($mt / 10, 1), ''];
        }
        $vals = array_filter($c->walk(self::UCD_SENSORS . '.3'), 'is_numeric');
        if ($vals) {
            $i = array_keys($vals, max($vals))[0];
            $name = (string) $c->one(self::UCD_SENSORS . ".2.$i");
            return [round(max($vals) / 1000, 1), $name !== '' ? ' (' . t('{name}, warmste sensor', ['name' => $name]) . ')' : ''];
        }
        return [null, ''];
    }

    // ------------------------------------------------------------------ UPS

    /** Status van de UPS: op batterij, batterij bijna leeg, vervangen, alarmen (UPS-MIB of APC) */
    private static function upsStatus(SnmpConn $c): array
    {
        $u = $c->get([self::UPS . '.2.1.0', self::UPS . '.2.2.0', self::UPS . '.4.1.0', self::UPS . '.6.1.0']);
        if (isset($u[self::UPS . '.2.1.0']) || isset($u[self::UPS . '.4.1.0'])) {
            $bat = (int) ($u[self::UPS . '.2.1.0'] ?? 2);
            $src = (int) ($u[self::UPS . '.4.1.0'] ?? 3);
            $alarms = (int) ($u[self::UPS . '.6.1.0'] ?? 0);
            $st = STATUS_OK;
            $msg = [];
            if ($src === 5) {
                $st = STATUS_WARN;
                $secs = (int) ($u[self::UPS . '.2.2.0'] ?? 0);
                $msg[] = t('op batterij') . ($secs > 0 ? ' ' . t('sinds {t}', ['t' => self::duration($secs)]) : '');
            } elseif ($src === 4) {
                $st = STATUS_WARN;
                $msg[] = t('op bypass (niet beschermd)');
            } elseif ($src === 2) {
                $st = STATUS_CRIT;
                $msg[] = t('geen uitgangsspanning');
            } else {
                $msg[] = t([5 => 'op batterij', 6 => 'boost (lage netspanning)', 7 => 'buck (hoge netspanning)'][$src] ?? 'op net');
            }
            if ($bat === 3 || $bat === 4) {
                $st = STATUS_CRIT;
                $msg[] = $bat === 3 ? t('batterij bijna leeg') : t('batterij leeg');
            }
            if ($alarms > 0) {
                $st = max($st, STATUS_WARN);
                $msg[] = $alarms === 1 ? t('1 alarm actief') : t('{n} alarmen actief', ['n' => $alarms]);
            }
            return self::res($st, null, 'UPS ' . implode(' · ', $msg));
        }
        $a = $c->get([self::APC . '.4.1.1.0', self::APC . '.2.1.1.0', self::APC . '.2.2.4.0']);
        if (!$a) {
            return self::missing('UPS');
        }
        $out = (int) ($a[self::APC . '.4.1.1.0'] ?? 2);
        $bat = (int) ($a[self::APC . '.2.1.1.0'] ?? 2);
        $st = STATUS_OK;
        $msg = [[2 => t('op net'), 3 => t('op batterij'), 4 => 'smart boost', 5 => t('uit (gepland)'), 6 => 'software bypass', 7 => t('uit'),
            9 => 'hardware bypass', 10 => t('uit (slaapstand)'), 12 => 'smart trim'][$out] ?? "status $out"];
        if (in_array($out, [3, 6, 9], true)) {
            $st = STATUS_WARN;
        } elseif (in_array($out, [5, 7, 10], true)) {
            $st = STATUS_CRIT;
        }
        if ($bat === 3 || $bat === 4) {
            $st = STATUS_CRIT;
            $msg[] = $bat === 3 ? t('batterij bijna leeg') : t('batterijfout');
        }
        if ((int) ($a[self::APC . '.2.2.4.0'] ?? 1) === 2) {
            $st = max($st, STATUS_WARN);
            $msg[] = t('batterij moet vervangen worden');
        }
        return self::res($st, null, 'UPS ' . implode(' · ', $msg));
    }

    private static function upsValue(SnmpConn $c, array $check, string $m): array
    {
        $map = [   // UPS-MIB, APC, omrekening APC
            'ups_charge' => [self::UPS . '.2.4.0', self::APC . '.2.2.1.0', 1, '%', 'Batterij %s%% geladen'],
            'ups_runtime' => [self::UPS . '.2.3.0', self::APC . '.2.2.3.0', 1 / 6000, 'min', 'Nog %s min op batterij'],
            'ups_load' => [self::UPS . '.4.4.1.5.1', self::APC . '.4.2.3.0', 1, '%', 'Belasting %s%%'],
            'ups_temp' => [self::UPS . '.2.7.0', self::APC . '.2.2.2.0', 1, '°C', 'Batterijtemperatuur %s °C'],
        ][$m];
        $r = $c->get([$map[0], $map[1]]);
        $v = self::num($r[$map[0]] ?? null);
        if ($v === null && ($apc = self::num($r[$map[1]] ?? null)) !== null) {
            $v = $apc * $map[2];
        }
        if ($v === null) {
            return self::missing(self::METRICS[$m]['label']);
        }
        $v = round($v, 1);
        $msg = sprintf(t($map[4]), $v);
        if ($m === 'ups_temp' && $v > 25) {
            $msg .= ' (' . t('warmer dan 25 °C verkort de levensduur van de accu') . ')';
        }
        return self::res(self::level($v, $check, self::METRICS[$m]['low']), $v, $msg);
    }

    // ------------------------------------------------------------------ Synology

    private static function synoSystem(SnmpConn $c, array $check): array
    {
        $o = ['status' => self::SYNO . '.1.1.0', 'temp' => self::SYNO . '.1.2.0', 'power' => self::SYNO . '.1.3.0', 'fan' => self::SYNO . '.1.4.1.0',
            'cpufan' => self::SYNO . '.1.4.2.0', 'model' => self::SYNO . '.1.5.1.0', 'version' => self::SYNO . '.1.5.3.0', 'upgrade' => self::SYNO . '.1.5.4.0'];
        $r = $c->get(array_values($o));
        if (!isset($r[$o['status']])) {
            return self::missing('Synology');
        }
        $bad = [];
        foreach (['status' => 'systeem', 'power' => 'voeding', 'fan' => 'systeemventilator', 'cpufan' => 'CPU-ventilator'] as $k => $l) {
            if ((int) ($r[$o[$k]] ?? 1) === 2) {
                $bad[] = t('{part} defect', ['part' => t($l)]);
            }
        }
        $t = self::num($r[$o['temp']] ?? null);
        $st = $bad ? STATUS_CRIT : self::level($t, $check, false);
        $msg = trim(($r[$o['model']] ?? 'Synology') . ' ' . ($r[$o['version']] ?? ''))
            . ($bad ? ' · ' . implode(', ', $bad) : ' · ' . t('systeem, voeding en ventilatoren in orde'))
            . ($t !== null ? " · $t °C" : '') . ((int) ($r[$o['upgrade']] ?? 2) === 1 ? ' · ' . t('DSM-update beschikbaar') : '');
        return self::res($st, $t, $msg);
    }

    private static function synoRaid(SnmpConn $c, array $check): array
    {
        $i = (string) $check['target'];
        $o = ['name' => self::SYNO . ".3.1.1.2.$i", 'status' => self::SYNO . ".3.1.1.3.$i", 'free' => self::SYNO . ".3.1.1.4.$i", 'total' => self::SYNO . ".3.1.1.5.$i"];
        $r = $c->get(array_values($o));
        if (!isset($r[$o['status']])) {
            return self::res(STATUS_UNKNOWN, null, t('Volume {i} bestaat niet (meer); kies hem opnieuw via ＋ SNMP-checks', ['i' => $i]));
        }
        $s = (int) $r[$o['status']];
        $names = array_map('t', [1 => 'normaal', 2 => 'wordt hersteld', 3 => 'wordt gemigreerd', 4 => 'wordt uitgebreid', 5 => 'wordt verwijderd', 6 => 'wordt aangemaakt',
            7 => 'synchroniseert', 8 => 'pariteitscontrole', 9 => 'wordt samengesteld', 10 => 'wordt geannuleerd', 11 => 'DEGRADED (schijf defect of ontbreekt)',
            12 => 'CRASHED', 13 => 'data scrubbing', 21 => 'status onbekend']);
        $st = match (true) {
            in_array($s, [11, 12], true) => STATUS_CRIT,
            $s === 2 => STATUS_WARN,
            $s === 21 => STATUS_UNKNOWN,
            default => STATUS_OK,
        };
        $total = (float) ($r[$o['total']] ?? 0);
        $pct = $total > 0 ? round(($total - (float) ($r[$o['free']] ?? 0)) / $total * 100, 1) : null;
        $st = max($st, self::level($pct, $check, false));
        return self::res($st, $pct, ($r[$o['name']] ?? "Volume $i") . ' ' . ($names[$s] ?? "status $s")
            . ($pct !== null ? ' · ' . t('{pct}% van {size} in gebruik', ['pct' => $pct, 'size' => self::bytes($total)]) : ''));
    }

    private static function synoDisk(SnmpConn $c, array $check): array
    {
        $i = (string) $check['target'];
        $o = ['id' => self::SYNO . ".2.1.1.2.$i", 'model' => self::SYNO . ".2.1.1.3.$i", 'status' => self::SYNO . ".2.1.1.5.$i",
            'temp' => self::SYNO . ".2.1.1.6.$i", 'health' => self::SYNO . ".2.1.1.13.$i"];
        $r = $c->get(array_values($o));
        if (!isset($r[$o['status']])) {
            return self::res(STATUS_UNKNOWN, null, t('Schijf {i} bestaat niet (meer); kies hem opnieuw via ＋ SNMP-checks', ['i' => $i]));
        }
        $s = (int) $r[$o['status']];
        $h = isset($r[$o['health']]) ? (int) $r[$o['health']] : null;
        $st = in_array($s, [4, 5], true) || in_array($h, [3, 4], true) ? STATUS_CRIT : ($h === 2 || $s === 3 ? STATUS_WARN : STATUS_OK);
        $t = self::num($r[$o['temp']] ?? null);
        $st = max($st, self::level($t, $check, false));
        $txt = t([1 => 'normaal', 2 => 'geïnitialiseerd', 3 => 'niet geïnitialiseerd', 4 => 'systeempartitie defect', 5 => 'CRASHED'][$s] ?? "status $s");
        $htxt = isset([2 => 1, 3 => 1, 4 => 1][$h]) ? t([2 => 'gezondheid: waarschuwing', 3 => 'gezondheid: kritiek', 4 => 'gezondheid: falend'][$h]) : '';
        return self::res($st, $t, trim(($r[$o['id']] ?? t('Schijf {i}', ['i' => $i])) . ' ' . ($r[$o['model']] ?? '')) . " · $txt" . ($htxt ? " · $htxt" : '') . ($t !== null ? " · $t °C" : ''));
    }

    // ------------------------------------------------------------------ overig

    private static function printer(SnmpConn $c, array $check): array
    {
        $i = (string) $check['target'];
        $o = ['name' => self::PRT . ".6.1.$i", 'max' => self::PRT . ".8.1.$i", 'level' => self::PRT . ".9.1.$i"];
        $r = $c->get(array_values($o));
        if (!isset($r[$o['level']])) {
            return self::res(STATUS_UNKNOWN, null, t('Printervoorraad {i} bestaat niet (meer); kies hem opnieuw via ＋ SNMP-checks', ['i' => $i]));
        }
        $name = trim((string) ($r[$o['name']] ?? t('Voorraad {i}', ['i' => $i])));
        $lvl = (float) $r[$o['level']];
        $max = (float) ($r[$o['max']] ?? -2);
        if ($lvl === -3.0) {
            return self::res(STATUS_OK, null, "$name: " . t('nog voldoende (de printer geeft geen percentage)'));
        }
        if ($lvl < 0) {
            return self::res(STATUS_UNKNOWN, null, "$name: " . t('niveau onbekend'));
        }
        $pct = $max > 0 ? round($lvl / $max * 100, 1) : round($lvl, 1);
        return self::res(self::level($pct, $check, true), $pct, "$name: " . t('{pct}% over', ['pct' => $pct]));
    }

    /** Band van een UniFi-radio: "ng" = 2,4 GHz, "na" = 5 GHz, "6e" = 6 GHz; anders op het kanaal */
    private static function band(string $radio, int $channel = 0): string
    {
        $r = strtolower(trim($radio));
        return match (true) {
            str_contains($r, '6') => '6g',
            $r === 'ng' || $r === 'g' || $r === 'b' || str_starts_with($r, 'ng') => '2g',
            $r === 'na' || $r === 'a' || str_starts_with($r, 'na') || str_starts_with($r, 'ac') => '5g',
            $channel > 0 && $channel <= 14 => '2g',
            $channel >= 32 => '5g',
            default => '?',
        };
    }

    private static function bandLabel(string $b): string
    {
        return ['2g' => '2,4 GHz', '5g' => '5 GHz', '6g' => '6 GHz'][$b] ?? t('onbekende band');
    }

    /** Alle VAP's (SSID per radio) met SSID, band, clients en aan/uit */
    private static function vaps(SnmpConn $c, bool $more = false): array
    {
        $ssid = $c->walk(self::UNIFI_VAP . '.6');
        if (!$ssid) {
            return [];
        }
        $cols = ['ch' => 4, 'sta' => 8, 'radio' => 9, 'up' => 22] + ($more ? ['ccq' => 3, 'rx' => 10, 'tx' => 16, 'txp' => 19, 'retry' => 20] : []);
        $data = array_map(fn(int $col) => $c->walk(self::UNIFI_VAP . ".$col"), $cols);
        $out = [];
        foreach ($ssid as $i => $s) {
            $v = ['ssid' => (string) $s];
            foreach ($cols as $k => $_) {
                $v[$k] = $data[$k][$i] ?? null;
            }
            $v['band'] = self::band((string) ($v['radio'] ?? ''), (int) ($v['ch'] ?? 0));
            $out[$i] = $v;
        }
        return $out;
    }

    /** UniFi clients: totaal (doel leeg), per band ("band:5g") of per SSID ("ssid:Naam") */
    private static function unifi(SnmpConn $c, array $check): array
    {
        $target = (string) $check['target'];
        $vaps = self::vaps($c);
        if (!$vaps) {
            // Oudere manier (vóór 1.74.0): alleen de clients-kolom
            $vals = array_filter($c->walk(self::UNIFI_STATIONS), 'is_numeric');
            if (!$vals || $target !== '') {
                return self::missing('UniFi clients');
            }
            $n = (float) array_sum(array_map('intval', $vals));
            return self::res(self::level($n, $check, false), $n, sprintf(t('%d client(s) verbonden (%d SSID/radio\'s)'), $n, count($vals)));
        }
        [$kind, $want] = str_contains($target, ':') ? explode(':', $target, 2) : ['', ''];
        $sel = array_filter($vaps, fn($v) => $kind === '' || ($kind === 'band' && $v['band'] === $want) || ($kind === 'ssid' && $v['ssid'] === $want));
        if (!$sel) {
            return self::res(STATUS_UNKNOWN, null, $kind === 'band'
                ? t('Geen radio op {band} gevonden', ['band' => self::bandLabel($want)])
                : t('SSID "{ssid}" wordt niet (meer) uitgezonden', ['ssid' => $want]));
        }
        $n = (float) array_sum(array_map(fn($v) => (int) $v['sta'], $sel));
        // Uitsplitsen: bij een band per SSID, bij een SSID per band, bij het totaal per band
        $parts = [];
        foreach ($sel as $v) {
            $k = $kind === 'band' ? $v['ssid'] : self::bandLabel($v['band']);
            $parts[$k] = ($parts[$k] ?? 0) + (int) $v['sta'];
        }
        $split = implode(' · ', array_map(fn($k, $x) => "$k: $x", array_keys($parts), $parts));
        return self::res(self::level($n, $check, false), $n, sprintf(t('%d client(s) verbonden'), $n) . ($split !== '' ? " ($split)" : ''));
    }

    /** Het AP zelf: isolated (geen bekabelde verbinding) = CRITICAL; model, firmware en uplink; ℹ️ bij nieuwe firmware */
    private static function unifiAp(SnmpConn $c, array $check): array
    {
        $o = ['ip' => self::UNIFI_SYS . '.1.0', 'iso' => self::UNIFI_SYS . '.2.0', 'model' => self::UNIFI_SYS . '.3.0', 'uplink' => self::UNIFI_SYS . '.4.0',
            'fw' => self::UNIFI_SYS . '.6.0'];
        $r = $c->get(array_values($o));
        $g = static fn(string $k) => trim((string) ($r[$o[$k]] ?? ''));
        if ($g('model') === '' && $g('fw') === '' && $g('iso') === '') {
            return self::missing('UniFi AP');
        }
        $iso = (int) $g('iso') === 1;
        $msg = trim($g('model') . ($g('fw') !== '' ? ' · firmware ' . $g('fw') : ''), ' ·')
            . ($g('uplink') !== '' ? ' · uplink ' . $g('uplink') : '')
            . ' · ' . ($iso ? t('ISOLATED: geen bekabelde verbinding (meer)') : t('verbonden'));
        $res = self::res($iso ? STATUS_CRIT : STATUS_OK, null, $msg);
        $prev = self::state((int) $check['id']);
        if ($g('fw') !== '') {
            self::saveState((int) $check['id'], ['fw' => $g('fw')]);
            if ($prev && ($prev['fw'] ?? '') !== '' && $prev['fw'] !== $g('fw')) {
                $res['info'] = t('Firmware bijgewerkt van {old} naar {new}', ['old' => $prev['fw'], 'new' => $g('fw')]) . ($g('model') !== '' ? ' (' . $g('model') . ')' : '');
            }
        }
        return $res;
    }

    /** Kanaalbezetting van één radio (band): hoe vol de lucht is, ook door buren */
    private static function unifiCu(SnmpConn $c, array $check): array
    {
        $want = (string) $check['target'];
        $radio = $c->walk(self::UNIFI_RADIO . '.3');
        if (!$radio) {
            return self::missing('UniFi kanaalbezetting');
        }
        $ch = [];
        foreach (self::vaps($c) as $v) {
            if ((int) $v['ch'] > 0) {
                $ch[$v['band']] = (int) $v['ch'];
            }
        }
        foreach ($radio as $i => $r) {
            if (self::band((string) $r) !== $want) {
                continue;
            }
            $x = $c->get([self::UNIFI_RADIO . ".6.$i", self::UNIFI_RADIO . ".7.$i", self::UNIFI_RADIO . ".8.$i"]);
            $cu = self::num($x[self::UNIFI_RADIO . ".6.$i"] ?? null);
            if ($cu === null) {
                return self::missing('UniFi kanaalbezetting');
            }
            $rx = self::num($x[self::UNIFI_RADIO . ".7.$i"] ?? null);
            $tx = self::num($x[self::UNIFI_RADIO . ".8.$i"] ?? null);
            $msg = self::bandLabel($want) . ': ' . t('{n}% bezet', ['n' => $cu])
                . ($rx !== null && $tx !== null ? ' (' . t('eigen verkeer {n}%', ['n' => $rx + $tx]) . ')' : '')
                . (isset($ch[$want]) ? ' · ' . t('kanaal {n}', ['n' => $ch[$want]]) : '');
            return self::res(self::level($cu, $check, false), $cu, $msg);
        }
        return self::res(STATUS_UNKNOWN, null, t('Geen radio op {band} gevonden', ['band' => self::bandLabel($want)]));
    }

    /** SSID: wordt hij uitgezonden, op welke banden? Nergens = CRITICAL, op een band uit = WARNING */
    private static function unifiSsid(SnmpConn $c, array $check): array
    {
        $want = (string) $check['target'];
        $vaps = self::vaps($c);
        if (!$vaps) {
            return self::missing('UniFi SSID');
        }
        $sel = array_filter($vaps, fn($v) => $v['ssid'] === $want);
        $on = array_filter($sel, fn($v) => (int) ($v['up'] ?? 1) !== 2);
        if (!$on) {
            return self::res(STATUS_CRIT, null, t('SSID "{ssid}" wordt niet uitgezonden', ['ssid' => $want]));
        }
        $bands = fn(array $l) => implode(', ', array_unique(array_map(fn($v) => self::bandLabel($v['band']), $l)));
        $off = array_diff_key($sel, $on);
        return self::res($off ? STATUS_WARN : STATUS_OK, null, t('"{ssid}" uitgezonden op {bands}', ['ssid' => $want, 'bands' => $bands($on)])
            . ($off ? ' · ' . t('uit op {bands}', ['bands' => $bands($off)]) : ''));
    }

    /** Teller-verschil met overloop (32 bits) of herstart (null) */
    private static function delta(float $now, float $prev): ?float
    {
        $d = $now - $prev;
        if ($d < 0) {
            return $prev < 4294967296 ? $d + 4294967296 : null;
        }
        return $d;
    }

    /** Verkeer van een SSID (alle radio's samen), in en uit */
    private static function unifiTraffic(SnmpConn $c, array $check): array
    {
        $want = (string) $check['target'];
        $sel = array_filter(self::vaps($c, true), fn($v) => $v['ssid'] === $want);
        if (!$sel) {
            return self::res(STATUS_UNKNOWN, null, t('SSID "{ssid}" wordt niet (meer) uitgezonden', ['ssid' => $want]));
        }
        $now = microtime(true);
        $cur = ['t' => $now, 'rx' => array_sum(array_map(fn($v) => (float) $v['rx'], $sel)), 'tx' => array_sum(array_map(fn($v) => (float) $v['tx'], $sel)), 'n' => count($sel)];
        $prev = self::state((int) $check['id']);
        self::saveState((int) $check['id'], $cur);
        if (!$prev || (int) ($prev['n'] ?? 0) !== $cur['n'] || $now - (float) $prev['t'] < 1) {
            return self::res(STATUS_OK, null, "\"$want\" · " . t('verkeer volgt na de volgende meting'));
        }
        $dt = $now - (float) $prev['t'];
        $rx = self::delta($cur['rx'], (float) $prev['rx']);
        $tx = self::delta($cur['tx'], (float) $prev['tx']);
        if ($rx === null || $tx === null) {
            return self::res(STATUS_OK, null, "\"$want\" · " . t('tellers opnieuw begonnen (herstart?)'));
        }
        // Gezien vanaf de clients: wat het AP ontvangt is upload, wat het zendt is download
        $down = $tx * 8 / $dt;
        $up = $rx * 8 / $dt;
        $mbit = round(($down + $up) / 1e6, 2);
        return self::res(self::level($mbit, $check, false), $mbit, "\"$want\" · ↓ " . self::bits($down) . ' · ↑ ' . self::bits($up));
    }

    /** Kwaliteit van een SSID: het aandeel herhaald verzonden pakketten (%) en de CCQ */
    private static function unifiQuality(SnmpConn $c, array $check): array
    {
        $want = (string) $check['target'];
        $sel = array_filter(self::vaps($c, true), fn($v) => $v['ssid'] === $want);
        if (!$sel) {
            return self::res(STATUS_UNKNOWN, null, t('SSID "{ssid}" wordt niet (meer) uitgezonden', ['ssid' => $want]));
        }
        // CCQ (0-100 %, sommige firmware in promille), gewogen naar het aantal clients
        $w = 0;
        $sum = 0.0;
        foreach ($sel as $v) {
            if (is_numeric($v['ccq']) && (int) $v['sta'] > 0) {
                $q = (float) $v['ccq'];
                $sum += ($q > 100 ? $q / 10 : $q) * (int) $v['sta'];
                $w += (int) $v['sta'];
            }
        }
        $ccq = $w > 0 ? round($sum / $w) : null;
        $cur = ['p' => array_sum(array_map(fn($v) => (float) $v['txp'], $sel)), 'r' => array_sum(array_map(fn($v) => (float) $v['retry'], $sel)), 'n' => count($sel)];
        $prev = self::state((int) $check['id']);
        self::saveState((int) $check['id'], $cur);
        $ccqTxt = $ccq !== null ? ' · CCQ ' . $ccq . '%' : ($w === 0 ? ' · ' . t('geen clients') : '');
        if (!$prev || (int) ($prev['n'] ?? 0) !== $cur['n']) {
            return self::res(STATUS_OK, null, "\"$want\"$ccqTxt · " . t('herhalingen volgen na de volgende meting'));
        }
        $p = self::delta($cur['p'], (float) $prev['p']);
        $r = self::delta($cur['r'], (float) $prev['r']);
        if ($p === null || $r === null) {
            return self::res(STATUS_OK, null, "\"$want\"$ccqTxt · " . t('tellers opnieuw begonnen (herstart?)'));
        }
        if ($p < 50) {
            return self::res(STATUS_OK, null, "\"$want\"$ccqTxt · " . t('te weinig verkeer om herhalingen te meten'));
        }
        $pct = round(min(100, $r / $p * 100), 1);
        return self::res(self::level($pct, $check, false), $pct, "\"$want\" · " . t('{n}% herhaald verzonden', ['n' => $pct]) . $ccqTxt);
    }

    /** Eigen OID: getal (met drempels), teller (per seconde) of tekst (vergelijken) */
    private static function oid(SnmpConn $c, array $check): array
    {
        $oid = ltrim((string) $check['target'], '.');
        $o = json_decode((string) $check['options'], true) ?: [];
        $raw = $c->one($oid);
        if ($raw === null) {
            return self::res(STATUS_UNKNOWN, null, "OID $oid: " . t('het apparaat kent deze OID niet (of geeft hem niet met deze community/gebruiker)'));
        }
        $unit = (string) ($o['unit'] ?? '');
        $mode = $o['mode'] ?? 'gauge';
        if ($mode === 'text') {
            $expect = (string) ($o['expect'] ?? '');
            $ok = match ($o['match'] ?? 'equals') {
                'contains' => $expect === '' || stripos($raw, $expect) !== false,
                'not_contains' => $expect !== '' && stripos($raw, $expect) === false,
                'regex' => @preg_match('~' . str_replace('~', '\\~', $expect) . '~i', $raw) === 1,
                default => trim($raw) === trim($expect),
            };
            $fail = ($o['fail'] ?? 'crit') === 'warn' ? STATUS_WARN : STATUS_CRIT;
            return self::res($ok ? STATUS_OK : $fail, null, t('Waarde') . ': "' . mb_substr($raw, 0, 200) . '"' . ($ok ? '' : ' (' . t('verwacht') . ': ' . self::matchText($o) . ')'));
        }
        if (!is_numeric($raw)) {
            return self::res(STATUS_UNKNOWN, null, t('Waarde is geen getal') . ': "' . mb_substr($raw, 0, 100) . '" (' . t('kies "tekst vergelijken"') . ')');
        }
        $scale = (float) ($o['scale'] ?? 1) ?: 1.0;
        if ($mode === 'counter') {
            $now = microtime(true);
            $prev = self::state((int) $check['id']);
            self::saveState((int) $check['id'], ['t' => $now, 'v' => (float) $raw]);
            if (!$prev || $now - (float) $prev['t'] < 1) {
                return self::res(STATUS_OK, null, t('Teller') . ' ' . $raw . ' · ' . t('waarde per seconde volgt na de volgende meting'));
            }
            $d = (float) $raw - (float) $prev['v'];
            if ($d < 0) {
                return self::res(STATUS_OK, null, t('Teller opnieuw begonnen (herstart of overloop)'));
            }
            $v = round($d / ($now - (float) $prev['t']) * $scale, 3);
            return self::res(self::level($v, $check, !empty($o['low'])), $v, "$v" . ($unit !== '' ? " $unit" : '') . '/s');
        }
        $v = round((float) $raw * $scale, 3);
        return self::res(self::level($v, $check, !empty($o['low'])), $v, "$v" . ($unit !== '' ? " $unit" : ''));
    }

    private static function matchText(array $o): string
    {
        $e = '"' . ($o['expect'] ?? '') . '"';
        return (['contains' => t('bevat'), 'not_contains' => t('bevat niet'), 'regex' => t('past op')][$o['match'] ?? ''] ?? t('precies')) . " $e";
    }

    // ------------------------------------------------------------------ discovery

    /**
     * Wat er op dit apparaat te bewaken valt (＋ SNMP-checks): systeeminfo en een lijst voorstellen
     * [metric, target, name, info, checked, group].
     */
    public static function discover(array $cfg, string $address): array
    {
        $c = self::session($cfg, $address);
        $sys = self::sysInfo($c->get(array_values(self::SYS)));
        $sys['kind'] = self::kindOf($c, $sys);
        $items = [];
        $add = static function (string $group, string $metric, string $target, string $name, string $info, bool $checked) use (&$items): void {
            [$group, $name] = [t($group), t($name)];   // vaste namen vertalen; namen van het apparaat blijven zoals ze zijn
            $items[] = compact('group', 'metric', 'target', 'name', 'info', 'checked');
        };
        $add('Systeem', 'uptime', '', 'Uptime', t('WARNING als het apparaat net herstart is'), true);
        if (($cpu = self::cpuValue($c))[0] !== null) {
            $add('Systeem', 'cpu', '', 'CPU', t('nu') . " {$cpu[0]}%{$cpu[1]}", true);
        }
        if (($mem = self::memValue($c))[0] !== null) {
            $add('Systeem', 'mem', '', 'Geheugen', t('nu') . " {$mem[0]}%{$mem[1]}", true);
        }
        if (($l = self::num($c->one(self::UCD_LOAD . '.2'))) !== null) {
            $add('Systeem', 'load', '', 'Load', t('nu') . " $l (5 min)", false);
        }
        if (($t = self::tempValue($c))[0] !== null) {
            $add('Systeem', 'temp', '', 'Temperatuur', t('nu') . " {$t[0]} °C{$t[1]}", true);
        }

        // Opslag (HOST-RESOURCES): vaste schijven en netwerkschijven; systeemmappen standaard niet aangevinkt
        $types = $c->walk(self::HR_STORAGE . '.2');
        foreach ($types as $i => $type) {
            if (!in_array($type, self::HR_DISK, true) || !($s = self::hrStorage($c, (string) $i)) || $s['size'] <= 0) {
                continue;
            }
            $sysPath = (bool) preg_match('#^/(run|dev|sys|proc|snap|boot|var/lib/docker|tmp)(/|$)#', $s['descr']);
            $add('Opslag', 'storage', (string) $i, $s['descr'], t('{pct}% van {size}', ['pct' => round($s['used'] / $s['size'] * 100), 'size' => self::bytes($s['size'])]), !$sysPath);
        }

        // Interfaces: alle behalve loopback; aangevinkt als ze up zijn en geen virtuele interface
        $descr = $c->walk(self::IF . '.2');
        if ($descr) {
            $name = $c->walk(self::IFX . '.1');
            $alias = $c->walk(self::IFX . '.18');
            $type = $c->walk(self::IF . '.3');
            $oper = $c->walk(self::IF . '.8');
            $admin = $c->walk(self::IF . '.7');
            $high = $c->walk(self::IFX . '.15');
            foreach ($descr as $i => $d) {
                if ((int) ($type[$i] ?? 0) === 24) {
                    continue;   // loopback
                }
                $n = (string) (($name[$i] ?? '') !== '' ? $name[$i] : $d);
                $up = (int) ($oper[$i] ?? 2) === 1;
                $virtual = (bool) preg_match('/^(veth|docker|br-|virbr|tap|fwbr|fwpr|fwln|cali|flannel|cni|vnet|ifb|dummy|tun|wg|sit|ip6tnl|gre|bond_slave)/i', $n);
                $speed = (float) ($high[$i] ?? 0);
                $info = ($up ? 'up' : ((int) ($admin[$i] ?? 1) === 2 ? t('uitgeschakeld') : 'down')) . ($speed > 0 ? ' · ' . self::bits($speed * 1e6) : '')
                    . (($alias[$i] ?? '') !== '' ? ' · ' . $alias[$i] : '');
                $add('Interfaces', 'if', (string) $i, $n, $info, $up && !$virtual);
            }
        }

        // UPS (UPS-MIB of APC)
        $ups = $c->get([self::UPS . '.2.1.0', self::UPS . '.2.4.0', self::UPS . '.2.7.0', self::UPS . '.4.4.1.5.1']);
        $apc = $ups ? [] : $c->get([self::APC . '.2.2.1.0', self::APC . '.2.2.2.0', self::APC . '.4.2.3.0']);
        if ($ups || $apc) {
            $add('UPS', 'ups_status', '', 'UPS status', t('op batterij, batterij bijna leeg, vervangen, alarmen'), true);
            $add('UPS', 'ups_charge', '', 'UPS batterij', t('lading in %'), true);
            $add('UPS', 'ups_runtime', '', 'UPS looptijd', t('resterende minuten op batterij'), true);
            if (isset($ups[self::UPS . '.4.4.1.5.1']) || isset($apc[self::APC . '.4.2.3.0'])) {
                $add('UPS', 'ups_load', '', 'UPS belasting', t('belasting van de uitgang in %'), false);
            }
            if (isset($ups[self::UPS . '.2.7.0']) || isset($apc[self::APC . '.2.2.2.0'])) {
                $add('UPS', 'ups_temp', '', 'UPS batterijtemperatuur', t('warmte verkort de levensduur van de accu'), true);
            }
        }

        // Synology
        if ($c->one(self::SYNO . '.1.1.0') !== null) {
            $add('Synology', 'syno_system', '', 'Synology systeem', t('systeem, voeding, ventilatoren en temperatuur'), true);
            foreach ($c->walk(self::SYNO . '.3.1.1.2') as $i => $n) {
                $add('Synology', 'syno_raid', (string) $i, (string) $n, t('status (degraded/crashed) en vulling'), true);
            }
            $models = $c->walk(self::SYNO . '.2.1.1.3');
            foreach ($c->walk(self::SYNO . '.2.1.1.2') as $i => $n) {
                $add('Synology', 'syno_disk', (string) $i, (string) $n, trim((string) ($models[$i] ?? '')) . ' · ' . t('status, gezondheid en temperatuur'), true);
            }
        }

        // Printer: toner, inkt, drum…
        foreach ($c->walk(self::PRT . '.6.1') as $i => $n) {
            $add('Printer', 'printer', (string) $i, trim((string) $n) ?: t('Voorraad {i}', ['i' => $i]), t('niveau in %'), true);
        }

        // UniFi access point (UBNT-UniFi-MIB)
        $vaps = self::vaps($c);
        if ($vaps || $c->walk(self::UNIFI_STATIONS)) {
            $ap = $c->get([self::UNIFI_SYS . '.3.0', self::UNIFI_SYS . '.6.0', self::UNIFI_SYS . '.2.0']);
            if ($ap) {
                $model = trim((string) ($ap[self::UNIFI_SYS . '.3.0'] ?? '') . ' ' . (string) ($ap[self::UNIFI_SYS . '.6.0'] ?? ''));
                $add('UniFi', 'unifi_ap', '', 'UniFi AP', ($model !== '' ? "$model · " : '') . t('isolated (geen bekabelde verbinding) en ℹ️ bij nieuwe firmware'), true);
            }
            $sum = array_sum(array_map(fn($v) => (int) $v['sta'], $vaps)) ?: array_sum(array_map('intval', array_filter($c->walk(self::UNIFI_STATIONS), 'is_numeric')));
            $add('UniFi', 'unifi_clients', '', 'UniFi clients', t('totaal: nu {n}', ['n' => $sum]), false);
            $bands = [];
            foreach ($vaps as $v) {
                $bands[$v['band']] = ($bands[$v['band']] ?? 0) + (int) $v['sta'];
            }
            $cu = [];
            foreach ($c->walk(self::UNIFI_RADIO . '.3') as $i => $r) {
                $cu[self::band((string) $r)] = self::num($c->one(self::UNIFI_RADIO . ".6.$i"));
            }
            ksort($bands);
            foreach ($bands as $b => $n) {
                if ($b === '?') {
                    continue;
                }
                $add('UniFi', 'unifi_clients', "band:$b", t('Clients {x}', ['x' => self::bandLabel($b)]), t('nu {n}', ['n' => $n]), true);
                if (($cu[$b] ?? null) !== null) {
                    $add('UniFi', 'unifi_cu', $b, t('Kanaalbezetting {band}', ['band' => self::bandLabel($b)]), t('nu {n}%', ['n' => $cu[$b]]), true);
                }
            }
            $ssids = [];
            foreach ($vaps as $v) {
                $ssids[$v['ssid']] ??= [];
                if ((int) ($v['up'] ?? 1) !== 2) {
                    $ssids[$v['ssid']][] = self::bandLabel($v['band']);
                }
            }
            foreach ($ssids as $s => $bl) {
                if ($s === '') {
                    continue;
                }
                $n = array_sum(array_map(fn($v) => (int) $v['sta'], array_filter($vaps, fn($v) => $v['ssid'] === $s)));
                $on = implode(', ', array_unique($bl));
                $add('UniFi', 'unifi_ssid', $s, "SSID $s", $on !== '' ? t('uitgezonden op {bands}', ['bands' => $on]) : t('wordt nu niet uitgezonden'), true);
                $add('UniFi', 'unifi_clients', "ssid:$s", t('Clients {x}', ['x' => $s]), t('nu {n}', ['n' => $n]), true);
                $add('UniFi', 'unifi_traffic', $s, t('Verkeer {ssid}', ['ssid' => $s]), t('doorvoer in en uit'), false);
                $add('UniFi', 'unifi_quality', $s, t('Kwaliteit {ssid}', ['ssid' => $s]), t('herhaald verzonden pakketten en CCQ'), false);
            }
        }
        return ['sys' => $sys, 'items' => $items];
    }

    // ------------------------------------------------------------------ hulpjes

    private static function state(int $checkId): ?array
    {
        $v = q('SELECT data FROM snmp_state WHERE check_id = ?', [$checkId])->fetchColumn();
        return $v !== false ? (json_decode((string) $v, true) ?: null) : null;
    }

    private static function saveState(int $checkId, array $data): void
    {
        q('INSERT INTO snmp_state (check_id, data, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = NOW()',
            [$checkId, json_encode($data)]);
    }

    private static function bits(float $bps): string
    {
        foreach ([1e9 => 'Gbit/s', 1e6 => 'Mbit/s', 1e3 => 'kbit/s'] as $f => $u) {
            if ($bps >= $f) {
                return round($bps / $f, 1) . " $u";
            }
        }
        return round($bps) . ' bit/s';
    }

    private static function bytes(float $b): string
    {
        return Checks::bytes($b);
    }

    public static function duration(int $s): string
    {
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        $u = t('u');
        return $d > 0 ? "{$d} d {$h} $u" : ($h > 0 ? "{$h} $u {$m} min" : "{$m} min");
    }
}

/** Geen antwoord van het apparaat (time-out, verkeerde community/gebruiker) */
final class SnmpDown extends RuntimeException
{
}

/** Eén SNMP-verbinding, met OID's zonder punt vooraan; ontbrekende OID's worden overgeslagen */
final class SnmpConn
{
    public function __construct(private SNMP $s, private string $peer)
    {
    }

    /** @return array<string,string> OID => waarde (alleen wat het apparaat kent) */
    public function get(array $oids): array
    {
        $out = [];
        foreach (array_chunk($oids, 20) as $part) {
            $r = @$this->s->get($part);
            if ($r === false) {
                $this->check();
                // Bij SNMPv1 laat één onbekende OID de hele vraag mislukken: dan één voor één
                if (count($part) > 1) {
                    foreach ($part as $o) {
                        $one = @$this->s->get($o);
                        if ($one !== false) {
                            $out[$o] = (string) $one;
                        }
                    }
                }
                continue;
            }
            foreach ((array) $r as $k => $v) {
                $out[ltrim((string) $k, '.')] = (string) $v;
            }
        }
        return $out;
    }

    public function one(string $oid): ?string
    {
        return $this->get([$oid])[$oid] ?? null;
    }

    /** @return array<string,string> index (achter de basis-OID) => waarde */
    public function walk(string $base): array
    {
        $r = @$this->s->walk($base, true, 50);
        if ($r === false) {
            $this->check();
            return [];
        }
        $out = [];
        foreach ($r as $k => $v) {
            $out[ltrim((string) $k, '.')] = (string) $v;
        }
        return $out;
    }

    /** Geen antwoord of geweigerd: de hele check (en de andere van deze server) stoppen */
    private function check(): void
    {
        $no = $this->s->getErrno();
        if ($no === SNMP::ERRNO_TIMEOUT) {
            throw new SnmpDown(t('Geen antwoord via SNMP van {peer}: controleer of SNMP aan staat op het apparaat, en de versie, community of v3-gebruiker en of RzMon toegang heeft', ['peer' => $this->peer]));
        }
        if ($no === SNMP::ERRNO_GENERIC) {
            $err = $this->s->getError();
            // 1.105.2: "sendto (Operation not permitted)" = de firewall van de RzMon-server zelf houdt het pakket tegen
            if (stripos($err, 'not permitted') !== false) {
                $iso = q('SELECT name FROM hosts WHERE isolate_until > NOW()')->fetchAll(PDO::FETCH_COLUMN);
                throw new SnmpDown(t('De RzMon-server zelf mag niets naar {peer} sturen: een firewallregel op deze server (nftables/firewalld) houdt het tegen, nog vóór het netwerk', ['peer' => $this->peer])
                    . ($iso ? '. ' . t('Geïsoleerd: {list}; is dat de RzMon-server? Hef de isolatie op of zet dit netwerk bij Lokale netwerken.', ['list' => implode(', ', $iso)])
                        : '. ' . t('Kijk op de RzMon-server met: sudo nft list ruleset'))
                    . ' (' . $err . ')');
            }
            throw new SnmpDown(t('SNMP-fout') . ': ' . $err);
        }
    }
}
