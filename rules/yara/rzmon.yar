/*
 * YARA-regels van RzMon (1.113.0) voor nieuwe bestanden die de agent ziet (tijdelijke mappen en webmappen).
 * Eigen regels, gericht op wat op Linux-servers en webservers het meest voorkomt: webshells, cryptominers,
 * reverse shells, IoT-botnets (Mirai/Gafgyt), Kinsing en LD_PRELOAD-rootkits. Regels die met "rz_info_" beginnen
 * zijn alleen een aanwijzing (warning); de rest is critical.
 * Licentie: dezelfde als RzMon.
 */

rule rz_php_webshell_eval
{
    meta:
        desc = "PHP-webshell: voert code uit die via de URL of een formulier binnenkomt"
    strings:
        $in1 = "$_POST" nocase
        $in2 = "$_GET" nocase
        $in3 = "$_REQUEST" nocase
        $in4 = "$_COOKIE" nocase
        $in5 = "$_SERVER['HTTP_" nocase
        $ex1 = /\b(eval|assert|system|passthru|shell_exec|exec|proc_open|popen|pcntl_exec)\s*\(\s*(base64_decode\s*\(\s*)?\$_(POST|GET|REQUEST|COOKIE|SERVER)/ nocase
        $ex2 = /\b(eval|assert)\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev)\s*\(/ nocase
        $ex3 = /\bpreg_replace\s*\(\s*['"][^'"]{1,40}\/e['"]/ nocase
        $ex4 = /\bcreate_function\s*\(\s*['"]['"]\s*,\s*\$_(POST|GET|REQUEST)/ nocase
    condition:
        filesize < 2MB and (($ex1) or ($ex2 and any of ($in*)) or $ex3 or $ex4)
}

rule rz_php_webshell_known
{
    meta:
        desc = "Bekende PHP-webshell (c99, r57, WSO, b374k, FilesMan, Alfa, P.A.S.)"
    strings:
        $a1 = "c99shell" nocase
        $a2 = "r57shell" nocase
        $a3 = "FilesMan" fullword
        $a4 = "b374k" nocase
        $a5 = "WSO " fullword
        $a6 = "AlfaTeam" nocase
        $a7 = "P.A.S. Fork" nocase
        $a8 = "uname -a" nocase
        $php = "<?php" nocase
    condition:
        filesize < 5MB and $php and 2 of ($a*)
}

rule rz_jsp_webshell
{
    meta:
        desc = "JSP-webshell: voert een opdracht uit die via een verzoek binnenkomt"
    strings:
        $r = "Runtime.getRuntime().exec(" nocase
        $p = "request.getParameter(" nocase
        $b = "ProcessBuilder(" nocase
    condition:
        filesize < 1MB and $p and ($r or $b)
}

rule rz_cryptominer
{
    meta:
        desc = "Cryptominer (XMRig en dergelijke): gebruikt de server om cryptomunten te maken"
    strings:
        $s1 = "stratum+tcp://" nocase
        $s2 = "stratum+ssl://" nocase
        $s3 = "xmrig" nocase
        $s4 = "cryptonight" nocase
        $s5 = "randomx" nocase
        $s6 = "--donate-level" nocase
        $s7 = "\"pools\"" nocase
        $s8 = "nicehash" nocase
        $s9 = "minexmr" nocase
    condition:
        filesize < 30MB and 2 of them
}

rule rz_reverse_shell_script
{
    meta:
        desc = "Script met een reverse shell: geeft iemand van buiten een shell op deze server"
    strings:
        $b1 = /bash\s+-i\s+>&\s*\/dev\/tcp\// nocase
        $b2 = /\/dev\/tcp\/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\/[0-9]{2,5}/
        $n1 = /\bnc(at)?\s+(-[a-z]*e|.*-e)\s+\/bin\/(ba)?sh/ nocase
        $p1 = "socket.socket(" nocase
        $p2 = "pty.spawn(" nocase
        $p3 = "os.dup2(" nocase
        $s1 = /socat\s+.*exec:['"]?\/bin\/(ba)?sh/ nocase
        $pl = /use\s+Socket;.*exec\s*\(\s*["']\/bin\/sh/ nocase
        $ph = /fsockopen\s*\(.*(proc_open|shell_exec|exec)\s*\(\s*["']\/bin\/sh/ nocase
    condition:
        filesize < 1MB and ($b1 or $b2 or $n1 or $s1 or $pl or $ph or ($p1 and $p3 and $p2))
}

rule rz_iot_botnet
{
    meta:
        desc = "IoT-botnet (Mirai, Gafgyt/Bashlite, Tsunami): het apparaat doet mee aan DDoS-aanvallen"
    strings:
        $m1 = "/bin/busybox MIRAI" nocase
        $m2 = "TSource Engine Query"
        $m3 = "LOLNOGTFO"
        $m4 = "HTTPFLOOD" nocase
        $m5 = "UDPRAW" nocase
        $m6 = "GETLOCALIP" nocase
        $m7 = "KILLATTK" nocase
        $m8 = "/bin/busybox ECCHI" nocase
        $m9 = "npxXoudifFeEgGaACScs"
        $irc1 = "PRIVMSG %s :" nocase
        $irc2 = "NICK %s" nocase
    condition:
        uint32(0) == 0x464c457f and filesize < 10MB and (2 of ($m*) or (all of ($irc*) and 1 of ($m*)))
}

rule rz_kinsing
{
    meta:
        desc = "Kinsing/kdevtmpfsi: bekende malware op Linux-servers (cryptominer met achterdeur)"
    strings:
        $k1 = "kinsing" nocase
        $k2 = "kdevtmpfsi" nocase
        $k3 = "/tmp/kdevtmpfsi"
        $k4 = "libsystem.so"
    condition:
        filesize < 30MB and 2 of them
}

rule rz_ld_preload_rootkit
{
    meta:
        desc = "Rootkit via LD_PRELOAD: verbergt processen en bestanden door systeemfuncties te vervangen"
    strings:
        $p = "/etc/ld.so.preload"
        $h1 = "readdir64" fullword
        $h2 = "readdir" fullword
        $h3 = "fopen64" fullword
        $h4 = "dlsym" fullword
        $h5 = "RTLD_NEXT"
    condition:
        uint32(0) == 0x464c457f and filesize < 5MB and $p and $h4 and 2 of ($h1, $h2, $h3, $h5)
}

rule rz_downloader_script
{
    meta:
        desc = "Script dat iets binnenhaalt, uitvoerbaar maakt en start (zo komen miners en botnets binnen)"
    strings:
        $d1 = /(curl|wget)\s+[^\n]{0,200}(http|ftp)s?:\/\/[^\n]{0,200}/ nocase
        $c1 = /chmod\s+(\+x|[0-7]?7[0-7][0-7])\s+/ nocase
        $t1 = "/tmp/" nocase
        $t2 = "/dev/shm/" nocase
        $t3 = "/var/tmp/" nocase
        $k1 = /(pkill|killall)\s+[^\n]{0,40}(xmrig|kdevtmpfsi|kinsing|minerd|cryptonight)/ nocase
        $k2 = "crontab -r" nocase
        $k3 = "history -c" nocase
    condition:
        filesize < 1MB and $d1 and $c1 and any of ($t*) and any of ($k*)
}

rule rz_info_upx_packed
{
    meta:
        desc = "Ingepakt programma (UPX): vaak gebruikt om malware te verbergen, soms ook gewoon software"
    strings:
        $u1 = "UPX!"
        $u2 = "$Info: This file is packed with the UPX"
    condition:
        uint32(0) == 0x464c457f and filesize < 30MB and any of them
}

rule rz_info_php_obfuscated
{
    meta:
        desc = "Sterk versleutelde PHP-code (lange base64 of hex): vaak een webshell, soms een licentiesysteem"
    strings:
        $b64 = /['"][A-Za-z0-9+\/]{3000,}={0,2}['"]/
        $hex = /(\\x[0-9a-fA-F]{2}){200,}/
        $php = "<?php" nocase
    condition:
        filesize < 2MB and $php and ($b64 or $hex)
}
