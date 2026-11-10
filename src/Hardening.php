<?php
declare(strict_types=1);

/**
 * Hardening-score per server (1.88.0, agent 1.57.0). De agent stuurt elk uur een paar instellingen die het verschil
 * maken; hier staat wat goed is, hoe zwaar het weegt en hoe je het dichtzet. Per server kun je een punt negeren.
 * Wordt een punt slechter (bv. root mag weer via SSH inloggen), dan volgt een IDS-incident; de rest staat alleen in de score.
 */
final class Hardening
{
    /** item => [gewicht, ernst bij verslechtering] */
    private const ITEMS = [
        'ssh_pw' => [3, 1], 'ssh_root' => [3, 1], 'ssh_empty' => [3, 2], 'firewall' => [3, 1], 'autoupdate' => [2, 1],
        'open_db' => [3, 1], 'docker_sock' => [3, 2], 'empty_pw' => [3, 2], 'sudo_nopw' => [1, 1], 'tmp_noexec' => [1, 1], 'selinux' => [1, 1],
        // 1.232.0 (agent 1.94.0): extra controles uit de CIS Benchmarks voor Linux
        'ssh_maxauth' => [1, 1], 'ssh_x11' => [1, 1], 'ssh_idle' => [1, 1], 'ssh_grace' => [1, 1], 'shadow_perm' => [2, 1], 'passwd_perm' => [1, 1],
        'crontab_perm' => [1, 1], 'pass_max_days' => [1, 1], 'umask' => [1, 1], 'uid0' => [3, 2], 'sys_randomize_va_space' => [2, 1],
        'sys_accept_redirects' => [1, 1], 'sys_tcp_syncookies' => [1, 1], 'sys_rp_filter' => [1, 1], 'sys_dmesg_restrict' => [1, 1],
        'sys_ptrace_scope' => [1, 1], 'sys_suid_dumpable' => [1, 1], 'auditd' => [1, 1], 'time_sync' => [1, 1], 'ww_files' => [2, 1],
        'legacy_svc' => [2, 1], 'faillock' => [1, 1], 'pw_minlen' => [1, 1], 'grub_perm' => [1, 1], 'shm_noexec' => [1, 1],
    ];

    /**
     * Bij welk hoofdstuk van de CIS Benchmark (Linux, o.a. RHEL en Debian) een punt hoort. De nummers binnen een hoofdstuk
     * verschillen per distributie en versie, daarom alleen het hoofdstuk. 0 = geen CIS-punt (wel belangrijk).
     */
    public const CIS = [
        'tmp_noexec' => 1, 'shm_noexec' => 1, 'grub_perm' => 1, 'selinux' => 1, 'autoupdate' => 1, 'sys_randomize_va_space' => 1, 'sys_ptrace_scope' => 1,
        'sys_suid_dumpable' => 1, 'sys_dmesg_restrict' => 1, 'time_sync' => 2, 'legacy_svc' => 2, 'crontab_perm' => 2,
        'sys_accept_redirects' => 3, 'sys_tcp_syncookies' => 3, 'sys_rp_filter' => 3, 'firewall' => 4,
        'ssh_pw' => 5, 'ssh_root' => 5, 'ssh_empty' => 5, 'ssh_maxauth' => 5, 'ssh_x11' => 5, 'ssh_idle' => 5, 'ssh_grace' => 5, 'sudo_nopw' => 5,
        'faillock' => 5, 'pw_minlen' => 5, 'pass_max_days' => 5, 'umask' => 5, 'uid0' => 5, 'empty_pw' => 5, 'auditd' => 6,
        'shadow_perm' => 7, 'passwd_perm' => 7, 'ww_files' => 7, 'open_db' => 0, 'docker_sock' => 0,
    ];

    /** Hoofdstuk van de CIS Benchmark van een punt (0 = geen) */
    public static function cisOf(string $item): int
    {
        return array_key_exists($item, self::CIS) ? self::CIS[$item] : 0;
    }

    /** Namen van de hoofdstukken van de CIS Benchmark */
    public static function cisChapter(int $n): string
    {
        return [0 => t('Extra (geen CIS-punt)'), 1 => t('1 Basisinstallatie'), 2 => t('2 Diensten'), 3 => t('3 Netwerk'), 4 => t('4 Firewall'),
            5 => t('5 Toegang en inloggen'), 6 => t('6 Logging en audit'), 7 => t('7 Systeemonderhoud')][$n] ?? (string) $n;
    }

    /** Hoe je het zelf controleert, voor de punten zonder uitgebreide uitleg */
    private const CHECK = [
        'ssh_maxauth' => 'sudo sshd -T | grep -i maxauthtries', 'ssh_x11' => 'sudo sshd -T | grep -i x11forwarding', 'ssh_idle' => 'sudo sshd -T | grep -i clientaliveinterval',
        'ssh_grace' => 'sudo sshd -T | grep -i logingracetime', 'shadow_perm' => 'stat -c "%a %U" /etc/shadow', 'passwd_perm' => 'stat -c "%a %U" /etc/passwd',
        'crontab_perm' => 'stat -c "%a %U" /etc/crontab', 'pass_max_days' => 'grep ^PASS_MAX_DAYS /etc/login.defs', 'umask' => 'grep ^UMASK /etc/login.defs',
        'uid0' => "awk -F: '\$3 == 0' /etc/passwd", 'sys_randomize_va_space' => 'sysctl kernel.randomize_va_space', 'sys_accept_redirects' => 'sysctl net.ipv4.conf.all.accept_redirects',
        'sys_tcp_syncookies' => 'sysctl net.ipv4.tcp_syncookies', 'sys_rp_filter' => 'sysctl net.ipv4.conf.all.rp_filter', 'sys_dmesg_restrict' => 'sysctl kernel.dmesg_restrict',
        'sys_ptrace_scope' => 'sysctl kernel.yama.ptrace_scope', 'sys_suid_dumpable' => 'sysctl fs.suid_dumpable', 'auditd' => 'systemctl is-active auditd',
        'time_sync' => 'timedatectl', 'ww_files' => 'sudo find /etc /usr/bin /usr/sbin -xdev -type f -perm -0002', 'legacy_svc' => 'sudo ss -tulpn | grep -E ":(21|23|69|512|513|514) "',
        'faillock' => 'grep -l pam_faillock /etc/pam.d/*', 'pw_minlen' => 'grep minlen /etc/security/pwquality.conf', 'grub_perm' => 'stat -c %a /boot/grub*/grub.cfg',
        'shm_noexec' => 'findmnt /dev/shm',
    ];

    public static function enabled(): bool
    {
        return setting('ids_harden', '1') === '1';
    }

    /** @return bool|null goed, fout, of null (onbekend op deze server) */
    private static function ok(string $item, ?string $v): ?bool
    {
        if ($v === null) {
            return null;
        }
        return match ($item) {
            'ssh_pw', 'ssh_empty' => $v === '' ? null : $v !== 'yes',
            'ssh_root' => $v === '' ? null : $v !== 'yes',
            'firewall', 'autoupdate' => $v !== 'none',
            'open_db', 'empty_pw', 'sudo_nopw' => $v === '',
            'docker_sock' => $v === 'ok',
            'tmp_noexec' => $v === 'yes',
            'selinux' => $v === '' ? null : strtolower($v) !== 'disabled',
            'ssh_maxauth' => $v === '' ? null : (int) $v <= 4,
            'ssh_x11' => $v === '' ? null : $v === 'no',
            'ssh_idle' => $v === '' ? null : (int) $v > 0 && (int) $v <= 900,
            'ssh_grace' => $v === '' ? null : (int) $v > 0 && (int) $v <= 60,
            'shadow_perm', 'grub_perm', 'crontab_perm', 'passwd_perm' => self::permOk($item, $v),
            'pass_max_days' => $v === '' ? null : (int) $v > 0 && (int) $v <= 365,
            'umask' => $v === '' ? null : (octdec($v) & 027) === 027,
            'uid0', 'ww_files', 'legacy_svc' => $v === '',
            'sys_randomize_va_space' => $v === '2', 'sys_accept_redirects' => $v === '0', 'sys_tcp_syncookies' => $v === '1',
            'sys_rp_filter' => in_array($v, ['1', '2'], true), 'sys_dmesg_restrict' => $v === '1', 'sys_ptrace_scope' => (int) $v >= 1, 'sys_suid_dumpable' => $v === '0',
            'auditd', 'faillock', 'shm_noexec' => $v === 'yes',
            'time_sync' => $v !== 'none',
            'pw_minlen' => (int) $v >= 14,
            default => null,
        };
    }

    /** Rechten van een bestand ("640 root"): eigenaar root, en niet te ruim */
    private static function permOk(string $item, string $v): ?bool
    {
        if (!preg_match('/^([0-7]{3,4})(?: (\S+))?$/', $v, $m)) {
            return null;
        }
        $mode = octdec($m[1]);
        $owner = $m[2] ?? 'root';
        $mask = ['shadow_perm' => 007, 'passwd_perm' => 022, 'crontab_perm' => 077, 'grub_perm' => 077][$item] ?? 077;
        return ($mode & $mask) === 0 && $owner === 'root';
    }

    /** [titel, uitleg en hoe je het dichtzet] */
    public static function text(string $item): array
    {
        return match ($item) {
            'ssh_pw' => [t('SSH: inloggen met een wachtwoord staat uit'), t('Met alleen sleutels heeft wachtwoorden raden geen zin. In /etc/ssh/sshd_config: PasswordAuthentication no, dan systemctl reload sshd. Test eerst of je met je sleutel binnenkomt.')],
            'ssh_root' => [t('SSH: root kan niet direct inloggen'), t('In /etc/ssh/sshd_config: PermitRootLogin no (of prohibit-password), dan systemctl reload sshd. Log in als gewone gebruiker en gebruik sudo.')],
            'ssh_empty' => [t('SSH: geen lege wachtwoorden'), t('In /etc/ssh/sshd_config: PermitEmptyPasswords no.')],
            'firewall' => [t('Firewall actief'), t('Rocky/RHEL: systemctl enable --now firewalld. Debian/Ubuntu: ufw allow ssh && ufw enable. Alleen de poorten open zetten die echt nodig zijn.')],
            'autoupdate' => [t('Automatische beveiligingsupdates'), t('Rocky/RHEL: dnf install dnf-automatic && systemctl enable --now dnf-automatic-install.timer. Debian/Ubuntu: apt install unattended-upgrades && dpkg-reconfigure unattended-upgrades.')],
            'open_db' => [t('Geen database open op alle netwerken'), t('Laat MySQL/MariaDB, PostgreSQL, Redis, MongoDB of Elasticsearch alleen op 127.0.0.1 luisteren (bind-address), of zet de poort dicht in de firewall. Open databases worden binnen uren gevonden.')],
            'docker_sock' => [t('Docker-socket afgeschermd'), t('Wie de Docker-socket kan gebruiken, is root. Niet voor iedereen schrijfbaar maken (chmod 660) en Docker nooit via tcp (poort 2375) zonder TLS.')],
            'empty_pw' => [t('Geen accounts zonder wachtwoord'), t('Een account met een leeg wachtwoord kan zonder iets binnen. Zet het op slot: passwd -l <naam>.')],
            'sudo_nopw' => [t('sudo vraagt altijd een wachtwoord'), t('NOPASSWD in /etc/sudoers(.d) betekent: wie die gebruiker overneemt, is meteen root. Alleen gebruiken voor één vaste opdracht.')],
            'tmp_noexec' => [t('/tmp zonder uitvoeren (noexec)'), t('Veel malware wordt in /tmp gezet en daar gestart. Mount /tmp met noexec,nosuid,nodev (bv. systemctl enable tmp.mount en de opties in /etc/fstab).')],
            'selinux' => [t('SELinux aan'), t('SELinux beperkt wat een gehackte dienst kan. Zet SELINUX=enforcing in /etc/selinux/config en herstart (eerst permissive om te testen).')],
            'ssh_maxauth' => [t('SSH: hooguit 4 pogingen per verbinding'), t('Minder pogingen per verbinding maakt raden trager. In sshd_config: MaxAuthTries 4.')],
            'ssh_x11' => [t('SSH: geen X11-doorsturen'), t('Op een server is X11 zelden nodig, en het opent een extra weg naar je computer. In sshd_config: X11Forwarding no.')],
            'ssh_idle' => [t('SSH: stille sessies gaan dicht'), t('Een vergeten open sessie is een open deur. In sshd_config: ClientAliveInterval 300 en ClientAliveCountMax 3.')],
            'ssh_grace' => [t('SSH: hooguit 60 seconden om in te loggen'), t('Een korte tijd om in te loggen voorkomt dat aanvallers veel halve verbindingen openhouden. In sshd_config: LoginGraceTime 60.')],
            'shadow_perm' => [t('/etc/shadow alleen voor root'), t('Hierin staan de wachtwoord-hashes. chown root:root /etc/shadow (Debian: root:shadow) en chmod 640 /etc/shadow (of 600).')],
            'passwd_perm' => [t('/etc/passwd niet schrijfbaar voor anderen'), t('chown root:root /etc/passwd && chmod 644 /etc/passwd.')],
            'crontab_perm' => [t('/etc/crontab alleen voor root'), t('Wie de crontab kan lezen of wijzigen, ziet of plant taken als root. chown root:root /etc/crontab && chmod 600 /etc/crontab.')],
            'pass_max_days' => [t('Wachtwoorden verlopen (hooguit 365 dagen)'), t('In /etc/login.defs: PASS_MAX_DAYS 365 (geldt voor nieuwe accounts; bestaande met chage --maxdays 365 NAAM). Met alleen SSH-sleutels is dit minder belangrijk.')],
            'umask' => [t('Standaard umask 027'), t('Nieuwe bestanden zijn dan niet leesbaar voor iedereen. In /etc/login.defs: UMASK 027.')],
            'uid0' => [t('Alleen root heeft UID 0'), t('Een tweede account met UID 0 is een verborgen root, vaak een achterdeur. Kijk wie het is en haal het weg (userdel) of geef het een gewone UID.')],
            'sys_randomize_va_space' => [t('Geheugen op willekeurige plekken (ASLR)'), t('Maakt het misbruiken van geheugenfouten veel moeilijker. sysctl -w kernel.randomize_va_space=2 en zet het in /etc/sysctl.d/60-rzsense.conf.')],
            'sys_accept_redirects' => [t('Geen ICMP-redirects aannemen'), t('Een aanvaller in je netwerk kan je verkeer dan niet omleiden. net.ipv4.conf.all.accept_redirects = 0 (en default) in /etc/sysctl.d.')],
            'sys_tcp_syncookies' => [t('SYN-cookies aan'), t('Beschermt tegen het overspoelen met halve verbindingen (SYN flood). net.ipv4.tcp_syncookies = 1 in /etc/sysctl.d.')],
            'sys_rp_filter' => [t('Controle op vervalste afzenders (rp_filter)'), t('Pakketten met een onmogelijk afzenderadres worden dan weggegooid. net.ipv4.conf.all.rp_filter = 1 in /etc/sysctl.d (2 bij asymmetrische routes).')],
            'sys_dmesg_restrict' => [t('Kernelmeldingen alleen voor root'), t('In de kernelmeldingen staan adressen die helpen bij een aanval. kernel.dmesg_restrict = 1 in /etc/sysctl.d.')],
            'sys_ptrace_scope' => [t('Processen kunnen elkaar niet zomaar uitlezen'), t('Met ptrace kan een gehackt proces het geheugen van een ander uitlezen (wachtwoorden). kernel.yama.ptrace_scope = 1 in /etc/sysctl.d.')],
            'sys_suid_dumpable' => [t('Geen core dumps van programma\'s met setuid'), t('Een core dump kan wachtwoorden of sleutels bevatten. fs.suid_dumpable = 0 in /etc/sysctl.d, en * hard core 0 in /etc/security/limits.conf.')],
            'auditd' => [t('Audit-logboek (auditd) draait'), t('auditd legt vast wie wat deed (ook de endpoint-bewaking gebruikt het). Rocky/RHEL: dnf install audit; Debian/Ubuntu: apt install auditd; daarna systemctl enable --now auditd.')],
            'time_sync' => [t('Tijd wordt gesynchroniseerd'), t('Zonder juiste tijd kloppen logboeken en certificaten niet. systemctl enable --now chronyd (of systemd-timesyncd).')],
            'ww_files' => [t('Geen systeembestanden die iedereen kan wijzigen'), t('Een bestand in /etc of /usr/bin dat iedereen kan wijzigen, is een snelle weg naar root. chmod o-w op de genoemde bestanden.')],
            'legacy_svc' => [t('Geen oude, onversleutelde diensten'), t('FTP, telnet, TFTP en rsh sturen wachtwoorden leesbaar over het netwerk. Zet ze uit en gebruik SSH/SFTP.')],
            'faillock' => [t('Account op slot na mislukte pogingen'), t('Met pam_faillock gaat een account na een paar fouten even op slot. Rocky/RHEL: authselect enable-feature with-faillock; Debian: pam_faillock in /etc/pam.d/common-auth.')],
            'pw_minlen' => [t('Wachtwoorden minstens 14 tekens'), t('In /etc/security/pwquality.conf: minlen = 14 (pakket libpwquality / libpam-pwquality).')],
            'grub_perm' => [t('Opstartconfiguratie alleen voor root'), t('chown root:root /boot/grub*/grub.cfg && chmod 600 /boot/grub*/grub.cfg.')],
            'shm_noexec' => [t('/dev/shm zonder uitvoeren (noexec)'), t('Net als /tmp een plek waar iedereen mag schrijven. In /etc/fstab: tmpfs /dev/shm tmpfs defaults,noexec,nosuid,nodev 0 0, daarna mount -o remount /dev/shm.')],
            default => [$item, ''],
        };
    }

    /**
     * Uitgebreid advies (1.101.0): wat is het, waarom belangrijk, hoe controleer je het, hoe zet je het dicht (commando's per
     * systeem) en waar je op moet letten.
     * @return array{what: string, why: string, check: string, fix: string, cmds: list<array{0: string, 1: string}>, caution: string}
     */
    public static function help(string $item): array
    {
        [$title, $short] = self::text($item);
        $h = match ($item) {
            'ssh_pw' => [t('Met SSH kun je inloggen met alleen een wachtwoord.'), t('Wachtwoorden worden op internet de hele dag geraden. Met alleen SSH-sleutels heeft raden geen zin: zonder de sleutel kom je niet binnen.'),
                'sudo sshd -T | grep -i passwordauthentication',
                [[t('1. Sleutel maken (op je eigen computer) en naar de server zetten'), 'ssh-keygen -t ed25519 && ssh-copy-id gebruiker@server'],
                 [t('2. Wachtwoorden uitzetten'), "echo 'PasswordAuthentication no' | sudo tee /etc/ssh/sshd_config.d/10-rzmon.conf && { sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd; }"]],
                t('Test in een tweede venster of je met je sleutel binnenkomt vóór je het oude venster sluit, anders sluit je jezelf buiten.')],
            'ssh_root' => [t('root kan direct inloggen via SSH.'), t('root bestaat op elke server, dus aanvallers hoeven alleen het wachtwoord te raden. Wie als root binnenkomt, kan alles.'),
                'sudo sshd -T | grep -i permitrootlogin',
                [[t('Root-login uitzetten'), "echo 'PermitRootLogin no' | sudo tee /etc/ssh/sshd_config.d/11-rzmon.conf && { sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd; }"]],
                t('Zorg eerst dat je een gewone gebruiker hebt die sudo mag (sudo -v werkt).')],
            'ssh_empty' => [t('SSH staat inloggen met een leeg wachtwoord toe.'), t('Een account zonder wachtwoord is dan van buiten bereikbaar zonder dat iemand iets hoeft te raden.'),
                'sudo sshd -T | grep -i permitemptypasswords',
                [[t('Uitzetten'), "echo 'PermitEmptyPasswords no' | sudo tee /etc/ssh/sshd_config.d/12-rzmon.conf && { sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd; }"]], ''],
            'firewall' => [t('Er draait geen firewall.'), t('Elke dienst die per ongeluk op alle netwerken luistert, is dan meteen bereikbaar. Een firewall laat alleen door wat je openzet.'),
                'sudo ufw status 2>/dev/null; sudo firewall-cmd --state 2>/dev/null; sudo nft list ruleset | head',
                [[t('Debian/Ubuntu'), 'sudo ufw allow OpenSSH && sudo ufw enable'], [t('Rocky/RHEL'), 'sudo systemctl enable --now firewalld && sudo firewall-cmd --list-all']],
                t('Zet SSH (en wat je verder nodig hebt, zoals 80/443) eerst open, anders kom je er niet meer bij. Proxmox heeft een eigen firewall in de webinterface.')],
            'autoupdate' => [t('Beveiligingsupdates worden niet automatisch geïnstalleerd.'), t('De meeste hacks gebruiken lekken waarvoor al een update is. Automatisch bijwerken sluit die binnen een dag.'),
                'systemctl list-timers | grep -E "apt|dnf"',
                [[t('Debian/Ubuntu'), 'sudo apt install -y unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades'],
                 [t('Rocky/RHEL'), "sudo dnf install -y dnf-automatic && sudo sed -i 's/^apply_updates.*/apply_updates = yes/' /etc/dnf/automatic.conf && sudo systemctl enable --now dnf-automatic.timer"]],
                t('Op Proxmox liever met de hand bijwerken (of alleen beveiligingsupdates), en altijd na een back-up.')],
            'open_db' => [t('Een database luistert op alle netwerken.'), t('Databases op internet worden binnen uren gevonden en leeggehaald of versleuteld voor losgeld.'),
                'sudo ss -tlpn | grep -E ":(3306|5432|6379|27017|9200)\\b"',
                [[t('MariaDB/MySQL alleen lokaal'), "echo -e '[mysqld]\\nbind-address = 127.0.0.1' | sudo tee /etc/mysql/conf.d/rzmon-bind.cnf && sudo systemctl restart mariadb"],
                 [t('Redis alleen lokaal'), "sudo sed -i 's/^bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf && sudo systemctl restart redis"]],
                t('Hebben andere servers de database nodig: zet de poort alleen voor hun IP-adressen open in de firewall.')],
            'docker_sock' => [t('De Docker-socket is te ruim toegankelijk.'), t('Wie de Docker-socket kan gebruiken, kan een container starten met de hele schijf erin: dat is root.'),
                'ls -l /var/run/docker.sock; sudo ss -tlpn | grep -E ":(2375|2376)\\b"',
                [[t('Rechten herstellen'), 'sudo chmod 660 /var/run/docker.sock && sudo chown root:docker /var/run/docker.sock']],
                t('Docker nooit via tcp (2375) zonder TLS; en alleen vertrouwde gebruikers in de groep docker.')],
            'empty_pw' => [t('Er is een account zonder wachtwoord.'), t('Zo\'n account kan (lokaal, of via diensten die wachtwoorden gebruiken) zonder iets binnen.'),
                "sudo awk -F: '(\$2 == \"\") {print \$1}' /etc/shadow",
                [[t('Account op slot zetten'), 'sudo passwd -l NAAM']], t('Vervang NAAM door de naam uit de controle hierboven.')],
            'sudo_nopw' => [t('Een gebruiker mag sudo zonder wachtwoord.'), t('Wie die gebruiker overneemt (bv. via een gestolen SSH-sleutel) is meteen root, zonder nog iets te hoeven weten.'),
                'sudo grep -rn NOPASSWD /etc/sudoers /etc/sudoers.d/',
                [[t('Bewerken (veilig, met controle)'), 'sudo visudo -f /etc/sudoers.d/BESTAND']],
                t('Laat NOPASSWD alleen staan voor één vaste opdracht, bv. voor een back-upscript.')],
            'tmp_noexec' => [t('/tmp mag programma\'s uitvoeren.'), t('Malware wordt vaak in /tmp gezet (daar mag iedereen schrijven) en daar gestart. Met noexec lukt dat niet.'),
                'findmnt /tmp',
                [[t('/tmp als tmpfs met noexec'), "echo 'tmpfs /tmp tmpfs defaults,noexec,nosuid,nodev,size=1G 0 0' | sudo tee -a /etc/fstab && sudo mount -o remount /tmp 2>/dev/null || sudo mount /tmp"]],
                t('Sommige installers draaien vanuit /tmp; zet het dan even terug (mount -o remount,exec /tmp).')],
            'selinux' => [t('SELinux staat uit.'), t('SELinux beperkt wat een dienst mag, ook als hij gehackt is: een gehackte webserver kan dan niet zomaar overal bij.'),
                'getenforce; sestatus',
                [[t('Eerst testen (permissive), daarna afdwingen'), "sudo sed -i 's/^SELINUX=.*/SELINUX=permissive/' /etc/selinux/config && sudo touch /.autorelabel && sudo reboot"]],
                t('Na de herstart: kijk een paar dagen in de logboeken (ausearch -m avc), en zet het daarna op enforcing.')],
            default => [$short, '', self::CHECK[$item] ?? '', [], ''],
        };
        return ['title' => $title, 'what' => $h[0], 'why' => $h[1], 'check' => $h[2], 'fix' => $short, 'cmds' => $h[3], 'caution' => $h[4]];
    }

    /** Rapport van de agent verwerken: opslaan, en bij een verslechtering een incident (het eerste rapport is stil) */
    public static function store(array $host, array $hard, callable $incident): void
    {
        $hid = (int) $host['id'];
        $prev = [];
        foreach (q('SELECT item, ok, ignored FROM ids_hardening WHERE host_id = ?', [$hid])->fetchAll() as $r) {
            $prev[$r['item']] = $r;
        }
        $up = db()->prepare('INSERT INTO ids_hardening (host_id, item, value, ok, updated_at) VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE value = VALUES(value), ok = VALUES(ok), updated_at = VALUES(updated_at)');
        if (!$hard) {
            return;   // niets gemeten (bv. de lijst kon niet worden opgeslagen)
        }
        foreach (self::ITEMS as $item => [, $sev]) {
            $v = array_key_exists($item, $hard) ? mb_substr((string) $hard[$item], 0, 200) : null;
            $ok = self::ok($item, $v);
            $up->execute([$hid, $item, $v, $ok === null ? null : (int) $ok, now()]);
            $was = $prev[$item] ?? null;
            if ($ok === false && $was && $was['ok'] !== null && (int) $was['ok'] === 1 && !(int) $was['ignored']) {
                [$title, $advice] = self::text($item);
                $incident($sev, 'harden', $item, t('Beveiliging verslechterd: {what}', ['what' => $title]) . ($v !== '' ? " ($v)" : ''), $advice);
            }
        }
    }

    /** Score en details per server */
    public static function detail(int $hostId): array
    {
        $rows = q('SELECT item, value, ok, ignored, updated_at FROM ids_hardening WHERE host_id = ?', [$hostId])->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            if (!isset(self::ITEMS[$r['item']])) {
                continue;
            }
            [$title, $advice] = self::text($r['item']);
            $out[] = ['item' => $r['item'], 'title' => $title, 'advice' => $advice, 'help' => self::help($r['item']), 'value' => $r['value'], 'ok' => $r['ok'] === null ? null : (bool) $r['ok'],
                'cis' => self::cisOf((string) $r['item']), 'cis_name' => self::cisChapter(self::cisOf((string) $r['item'])),
                'ignored' => (bool) $r['ignored'], 'weight' => self::ITEMS[$r['item']][0], 'updated_at' => $r['updated_at']];
        }
        usort($out, static fn($a, $b) => [$a['ok'] === false && !$a['ignored'] ? 0 : 1, -$a['weight']] <=> [$b['ok'] === false && !$b['ignored'] ? 0 : 1, -$b['weight']]);
        return ['score' => self::score($out), 'items' => $out];
    }

    /** 0–100, of null als er nog niets bekend is */
    public static function score(array $items): ?int
    {
        $all = $good = 0;
        foreach ($items as $i) {
            if ($i['ok'] === null || $i['ignored']) {
                continue;
            }
            $all += $i['weight'];
            $good += $i['ok'] ? $i['weight'] : 0;
        }
        return $all ? (int) round($good / $all * 100) : null;
    }

    /** Scores van alle servers: host_id => [score, aantal punten fout] */
    public static function scores(): array
    {
        $by = [];
        foreach (q('SELECT host_id, item, ok, ignored FROM ids_hardening')->fetchAll() as $r) {
            if (isset(self::ITEMS[$r['item']])) {
                $by[(int) $r['host_id']][] = ['ok' => $r['ok'] === null ? null : (bool) $r['ok'], 'ignored' => (bool) $r['ignored'], 'weight' => self::ITEMS[$r['item']][0]];
            }
        }
        return array_map(static fn($items) => ['score' => self::score($items), 'bad' => count(array_filter($items, static fn($i) => $i['ok'] === false && !$i['ignored']))], $by);
    }

    public static function ignore(int $hostId, string $item, bool $on): bool
    {
        return isset(self::ITEMS[$item]) && q('UPDATE ids_hardening SET ignored = ? WHERE host_id = ? AND item = ?', [$on ? 1 : 0, $hostId, $item])->rowCount() >= 0;
    }
}
