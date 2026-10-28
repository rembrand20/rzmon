<?php
declare(strict_types=1);

/**
 * Eigen regels van RzMon voor de endpoint-sensoren (1.110.0), in dezelfde vorm als Sigma (zie Sigma.php), met:
 * - sev: 1 = warning (op de pagina), 2 = critical (ook Pushover);
 * - act: wat RzMon in de modus Blokkeren zelf doet: kill (proces stoppen), block (IP blokkeren), isolate (server isoleren);
 * - what: uitleg in gewone woorden; tags: MITRE ATT&CK-technieken.
 * Gebeurtenissoorten (kinds): exec (nieuw proces), net (verbinding of luisterpoort), file (nieuw bestand),
 * audit (bewaakt pad of kernelmodule), ctr (container).
 */
final class EdrRules
{
    // Geen node/java: die starten wel vaker legitiem een shell (Jenkins, npm-scripts); die vallen onder de andere regels
    private const WEB = ['nginx', 'apache2', 'httpd', 'lighttpd', 'caddy', 'php-fpm*', 'php-cgi*', 'uwsgi', 'gunicorn*'];
    private const SHELLS = ['sh', 'bash', 'dash', 'ash', 'zsh', 'ksh', 'busybox', 'nc', 'ncat', 'netcat', 'socat', 'telnet', 'curl', 'wget', 'python*', 'perl', 'ruby', 'whoami'];

    private static function c(string $f, string $op, array $v, bool $all = false): array
    {
        return ['f' => $f, 'op' => $op, 'v' => $v, 'all' => $all];
    }

    /** Alle eigen regels (titels en uitleg in het Nederlands; vertaald bij het tonen) */
    public static function all(): array
    {
        $tmpDirs = ['/tmp/', '/var/tmp/', '/dev/shm/', '/run/shm/'];
        $globs = static fn(array $names) => array_merge(...array_map(static fn($n) => ["*/$n"], $names));
        return [
            [
                'id' => 'rz-webshell', 'kinds' => ['exec'], 'sev' => 2, 'act' => ['kill'], 'tags' => ['T1505.003', 'T1059'],
                'title' => 'Webserver start een shell of netwerkprogramma',
                'what' => 'Een webserver (nginx, PHP, Java …) hoort geen shell, curl of netcat te starten. Meestal is via een lek in een website een opdracht uitgevoerd (webshell).',
                'sel' => ['s' => [[self::c('ParentImage', 'glob', $globs(self::WEB)), self::c('Image', 'glob', $globs(self::SHELLS))]],
                    'u' => [[self::c('User', 'eq', ['www-data', 'apache', 'nginx', 'http', 'wwwrun', 'lighttpd']), self::c('Image', 'glob', $globs(self::SHELLS))]]],
                'cond' => ['or' => [['ref' => 's'], ['ref' => 'u']]],
            ],
            [
                'id' => 'rz-revshell', 'kinds' => ['exec'], 'sev' => 2, 'act' => ['kill', 'isolate'], 'tags' => ['T1059.004', 'T1571'],
                'title' => 'Reverse shell',
                'what' => 'Een opdracht die een shell naar buiten opent, zodat iemand van buiten op deze server kan typen (bash -i >& /dev/tcp/…, nc -e, socat exec, python/perl/php met een socket).',
                'sel' => ['s' => [[self::c('CommandLine', 're', ['(?i)(/dev/(tcp|udp)/[0-9a-z.\-]+/\d+|\bnc(at)?\b.*\s-[a-z]*e\s*/bin/(ba|z|da)?sh|\bsocat\b.*\bexec:|mkfifo\s+\S+.*\|\s*(ba)?sh.*\|\s*nc\b|python[0-9.]*\s+-c\s+.*socket.*(subprocess|pty\.spawn|dup2)|perl\s+-e\s+.*socket.*exec|php\s+-r\s+.*fsockopen|ruby\s+-rsocket)'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-dlexec', 'kinds' => ['exec'], 'sev' => 2, 'act' => ['kill'], 'tags' => ['T1105', 'T1059.004'],
                'title' => 'Downloaden en meteen uitvoeren',
                'what' => 'Iets van internet halen en direct laten draaien (curl … | sh, wget -O- | bash, base64 -d | sh). Zo zetten aanvallers hun eigen programma op een server.',
                'sel' => ['s' => [[self::c('CommandLine', 're', ['(?i)((curl|wget|fetch)\b[^|;]*\|\s*(sudo\s+)?(ba|z|da)?sh\b|base64\s+(-d|--decode)[^|;]*\|\s*(ba)?sh\b|(curl|wget)\b.*(/tmp/|/dev/shm/)\S*\s*(;|&&)\s*chmod\s+\+?[0-7x]+)'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-shm-exec', 'kinds' => ['exec'], 'sev' => 2, 'act' => ['kill'], 'tags' => ['T1036', 'T1059'],
                'title' => 'Programma gestart vanuit /dev/shm',
                'what' => 'Programma\'s horen niet in het geheugen-bestandssysteem /dev/shm te staan. Malware zet zich daar neer omdat het na een herstart weg is.',
                'sel' => ['s' => [[self::c('Image', 'startswith', ['/dev/shm/', '/run/shm/'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-tmp-exec', 'kinds' => ['exec'], 'sev' => 1, 'act' => [], 'tags' => ['T1036'],
                'title' => 'Programma gestart vanuit /tmp',
                'what' => 'Een programma uit /tmp of /var/tmp. Soms is dat een installatiescript, maar het is ook een bekende plek voor malware.',
                'sel' => ['s' => [[self::c('Image', 'startswith', ['/tmp/', '/var/tmp/'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-hidden-exec', 'kinds' => ['exec'], 'sev' => 2, 'act' => [], 'tags' => ['T1564.001'],
                'title' => 'Verborgen programma gestart',
                'what' => 'Een programma waarvan de naam met een punt begint (verborgen bestand). Gewone software doet dat niet; malware wel, om niet op te vallen.',
                'sel' => ['s' => [[self::c('Image', 're', ['/\.[^/]+$'])]], 'ok' => [[self::c('Image', 'contains', ['/.cache/', '/.local/', '/.vscode-server/', '/.nvm/', '/.cargo/', '/.rustup/', '/.pyenv/'])]]],
                'cond' => ['and' => [['ref' => 's'], ['not' => ['ref' => 'ok']]]],
            ],
            [
                'id' => 'rz-miner', 'kinds' => ['exec', 'net'], 'sev' => 2, 'act' => ['kill', 'isolate'], 'tags' => ['T1496'],
                'title' => 'Cryptominer',
                'what' => 'Een programma dat cryptomunten delft (xmrig en dergelijke). Bijna altijd door een inbraak: het kost stroom en rekenkracht, en de inbreker zit nog op de server.',
                'sel' => ['s' => [[self::c('Image', 'glob', ['*/xmrig*', '*/minerd', '*/cpuminer*', '*/kdevtmpfsi', '*/kinsing'])]],
                    'c' => [[self::c('CommandLine', 'contains', ['stratum+tcp://', 'stratum+ssl://', '--donate-level', ' --cpu-max-threads-hint', 'pool.minexmr', 'xmr.pool', 'nanopool.org'])]],
                    'p' => [[self::c('DestinationPort', 'eq', ['3333', '5555', '7777', '14433', '14444', '45560', '45700'])]]],
                'cond' => ['or' => [['ref' => 's'], ['ref' => 'c'], ['ref' => 'p']]],
            ],
            [
                'id' => 'rz-history', 'kinds' => ['exec'], 'sev' => 1, 'act' => [], 'tags' => ['T1070.003'],
                'title' => 'Sporen wissen: geschiedenis of logs',
                'what' => 'De shellgeschiedenis of logbestanden worden gewist of uitgezet. Een inbreker doet dat om zijn sporen te verbergen; soms is het gewoon onderhoud.',
                'sel' => ['s' => [[self::c('CommandLine', 're', ['(?i)(\bhistory\s+-c\b|unset\s+HISTFILE|HISTFILE=/dev/null|(rm|shred)\s+.*\.(bash|zsh|sh)_history|ln\s+-sf?\s+/dev/null\s+\S*_history|(rm|shred|truncate\s+-s\s*0)\s+.*/var/log/(auth|secure|syslog|messages|wtmp|btmp|lastlog))'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-setuid', 'kinds' => ['exec'], 'sev' => 1, 'act' => [], 'tags' => ['T1548.001'],
                'title' => 'Setuid-bit gezet',
                'what' => 'Een bestand krijgt het setuid-bit: wie het start, wordt eigenaar (vaak root). Een klassieke manier om root te blijven.',
                'sel' => ['s' => [[self::c('Image', 'endswith', ['/chmod']), self::c('CommandLine', 're', ['(\s[ugoa]*\+[rwx]*s|\s[0-7]?[4-7][0-7]{3}\s)'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-sshkey', 'kinds' => ['exec'], 'sev' => 2, 'act' => [], 'tags' => ['T1098.004'],
                'title' => 'SSH-sleutel toegevoegd via de opdrachtregel',
                'what' => 'Er wordt een sleutel aan authorized_keys toegevoegd met echo of tee. Daarmee kan iemand later zonder wachtwoord inloggen.',
                'sel' => ['s' => [[self::c('CommandLine', 're', ['(?i)(>>?|tee\s+(-a\s+)?)\s*\S*authorized_keys'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-escape', 'kinds' => ['exec'], 'sev' => 2, 'act' => [], 'tags' => ['T1611'],
                'title' => 'Uitbreken uit een container',
                'what' => 'Een opdracht die vanuit een container de server zelf probeert over te nemen (nsenter naar proces 1, chroot naar /host, een container met de hele schijf erin).',
                'sel' => ['s' => [[self::c('CommandLine', 're', ['(?i)(nsenter\s+.*(-t|--target)\s+1\b|chroot\s+/host\b|(docker|podman)\s+run\s+.*--privileged.*-v\s+/:/)'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-kmod', 'kinds' => ['exec', 'audit'], 'sev' => 2, 'act' => [], 'tags' => ['T1547.006'],
                'title' => 'Kernelmodule geladen vanuit een vreemde plek',
                'what' => 'Een kernelmodule uit /tmp, /dev/shm of een thuismap. Zo installeren rootkits zich; gewone modules komen uit /lib/modules.',
                'sel' => ['s' => [[self::c('Image', 'glob', ['*/insmod', '*/modprobe', '*/kmod']), self::c('CommandLine', 're', ['(/tmp/|/dev/shm/|/var/tmp/|/home/|/root/)\S*\.ko\b'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-preload', 'kinds' => ['audit', 'file'], 'sev' => 2, 'act' => [], 'tags' => ['T1574.006'],
                'title' => '/etc/ld.so.preload gewijzigd',
                'what' => 'Via /etc/ld.so.preload wordt code in élk programma geladen. Dat gebruiken bijna alleen rootkits.',
                'sel' => ['s' => [[self::c('name', 'eq', ['/etc/ld.so.preload'])]], 'f' => [[self::c('TargetFilename', 'eq', ['/etc/ld.so.preload'])]]],
                'cond' => ['or' => [['ref' => 's'], ['ref' => 'f']]],
            ],
            [
                'id' => 'rz-persist', 'kinds' => ['audit'], 'sev' => 1, 'act' => [], 'tags' => ['T1053.003', 'T1543.002', 'T1098'],
                'title' => 'Wijziging aan accounts, sudo, cron of systemd',
                'what' => 'Een bewaakt bestand werd gewijzigd: gebruikers, sudo-rechten, geplande taken of systemd-diensten. Zo nestelt een inbreker zich; bij onderhoud is het normaal.',
                'sel' => ['s' => [[self::c('key', 'eq', ['rzmon_id', 'rzmon_persist'])]],
                    'pm' => [[self::c('exe', 'glob', ['*/dpkg', '*/rpm', '*/dnf*', '*/yum', '*/apt*', '*/unattended-upgr*', '*/systemd*', '*/useradd', '*/usermod', '*/passwd', '*/chpasswd', '*/vipw', '*/visudo', '*/crontab', '*/python3*'])]]],
                'cond' => ['and' => [['ref' => 's'], ['not' => ['ref' => 'pm']]]],
            ],
            [
                'id' => 'rz-net-shell', 'kinds' => ['net'], 'sev' => 2, 'act' => ['kill'], 'tags' => ['T1571', 'T1059.004'],
                'title' => 'Shell of netcat maakt een verbinding naar buiten',
                'what' => 'Een shell, netcat of socat met een eigen verbinding naar internet. Dat is precies hoe een reverse shell eruitziet.',
                'sel' => ['s' => [[self::c('Image', 'glob', $globs(['sh', 'bash', 'dash', 'ash', 'zsh', 'nc', 'ncat', 'netcat', 'socat', 'telnet'])), self::c('Initiated', 'eq', ['true'])]],
                    'lan' => [[self::c('DestinationIp', 'cidr', PRIVATE_NETWORKS)]]],
                'cond' => ['and' => [['ref' => 's'], ['not' => ['ref' => 'lan']]]],
            ],
            [
                'id' => 'rz-net-port', 'kinds' => ['net'], 'sev' => 2, 'act' => ['kill'], 'tags' => ['T1571'],
                'title' => 'Verbinding naar een typische aanvalspoort',
                'what' => 'Een programma maakt verbinding met een poort die vooral door aanvalsgereedschap wordt gebruikt (4444, 1337, 31337 …).',
                'sel' => ['s' => [[self::c('DestinationPort', 'eq', ['1337', '4444', '4445', '6666', '6667', '12345', '31337']), self::c('Initiated', 'eq', ['true'])]],
                    'lan' => [[self::c('DestinationIp', 'cidr', PRIVATE_NETWORKS)]]],
                'cond' => ['and' => [['ref' => 's'], ['not' => ['ref' => 'lan']]]],
            ],
            [
                'id' => 'rz-yara', 'kinds' => ['file'], 'sev' => 2, 'act' => [], 'tags' => ['T1105', 'T1505.003'],
                'title' => 'YARA: bekende malware of webshell',
                'what' => 'Een nieuw bestand lijkt volgens de YARA-regels van RzMon op malware: een webshell, cryptominer, reverse shell, IoT-botnet, Kinsing of een rootkit. De naam van de regel staat bij het bewijs.',
                'sel' => ['s' => [[self::c('yara', 're', ['(^|,)(?!rz_info_)[A-Za-z0-9_]+'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-yara-info', 'kinds' => ['file'], 'sev' => 1, 'act' => [], 'tags' => ['T1027.002'],
                'title' => 'YARA: verdacht bestand',
                'what' => 'Een nieuw bestand is ingepakt of sterk versleuteld (UPX, lange base64). Malware doet dat vaak om niet op te vallen, maar sommige gewone software ook.',
                'sel' => ['s' => [[self::c('yara', 'exists', [])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-drop-hidden', 'kinds' => ['file'], 'sev' => 2, 'act' => [], 'tags' => ['T1105', 'T1564.001'],
                'title' => 'Verborgen programma neergezet',
                'what' => 'Er staat ineens een programma (ELF) in /dev/shm of in een verborgen map in /tmp (bv. /tmp/.x/). Gewone software doet dat niet; malware wel, om niet op te vallen.',
                'sel' => ['s' => [[self::c('TargetFilename', 'startswith', $tmpDirs), self::c('TargetFilename', 're', ['^/(dev|run)/shm/|/\\.[^/]+/']), self::c('elf', 'eq', ['1'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-drop-elf', 'kinds' => ['file'], 'sev' => 1, 'act' => [], 'tags' => ['T1105'],
                'title' => 'Nieuw programma in een tijdelijke map',
                'what' => 'Er staat ineens een uitvoerbaar programma (ELF) in /tmp of /var/tmp. Soms een installatieprogramma, maar ook de eerste stap van een inbraak: het gereedschap neerzetten.',
                'sel' => ['s' => [[self::c('TargetFilename', 'startswith', $tmpDirs), self::c('elf', 'eq', ['1'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-drop-webshell', 'kinds' => ['file'], 'sev' => 2, 'act' => [], 'tags' => ['T1505.003'],
                'title' => 'Webshell neergezet',
                'what' => 'Een nieuw script in een webmap met code die opdrachten uitvoert (eval, system, base64_decode …). Dat is het kenmerk van een webshell.',
                'sel' => ['s' => [[self::c('susp', 'exists', [])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-ctr-priv', 'kinds' => ['ctr'], 'sev' => 1, 'act' => [], 'tags' => ['T1610'],
                'title' => 'Container gestart met volledige rechten',
                'what' => 'Een container met --privileged of met de hele schijf van de server erin kan de server overnemen. Soms nodig, vaak niet.',
                'sel' => ['s' => [[self::c('priv', 'eq', ['1'])]]],
                'cond' => ['ref' => 's'],
            ],
            [
                'id' => 'rz-ctr-exec', 'kinds' => ['ctr'], 'sev' => 1, 'act' => [], 'tags' => ['T1609'],
                'title' => 'Iemand opent een shell in een container',
                'what' => 'Er wordt een opdracht in een draaiende container uitgevoerd (podman/docker exec). Meestal jijzelf; buiten je eigen onderhoud is het verdacht.',
                'sel' => ['s' => [[self::c('act', 'eq', ['exec'])]]],
                'cond' => ['ref' => 's'],
            ],
        ];
    }
}
