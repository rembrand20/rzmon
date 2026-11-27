//go:build linux

package main

// Suricata op het proxynetwerk (0.36.0), zoals de bash-agent (agent/src/69-proxy.sh, het vierde deel). Tussen de reverse
// proxy en je apps loopt het verkeer onversleuteld over de netwerkbrug van Podman of Docker. Daar kijkt een eigen
// Suricata-container (rzsense-suricata, --network host, alleen meekijken) naar aanvallen en malware.
//   0 <tab> nids <tab> on|off <tab> web|balanced|full <tab> auto|interface,interface
// De regels werkt de agent elke dag bij (suricata-update, op de achtergrond). De meldingen gaan naar RzSense zoals die van
// een gewone Suricata. Dezelfde opdrachten aan de engine en dezelfde map als de bash-agent.

import (
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
	"time"
)

var (
	ndProfRe  = regexp.MustCompile(`^(web|balanced|full)$`)
	ndCtrRe   = regexp.MustCompile(`^rzsense-suricata(-[0-9]+)?$`)
	ndIfRe    = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,15}$`)
	ndNetIfRe = regexp.MustCompile(`"network_interface": *"([^"]*)"`)
	ndMntRe   = regexp.MustCompile(`/[A-Za-z0-9_./-]*/overlay/[0-9a-f]{64}/merged`)
)

const ndAllGroups = "activex adware_pup attack_response chat coinminer current_events dns dos exploit exploit_kit files ftp games hunting icmp icmp_info imap inappropriate info ja3 malware misc mobile_malware netbios p2p phishing policy pop3 rpc scada scan shellcode smb smtp snmp sql telnet tftp user_agents voip web_client web_server web_specific_apps worm"

func sysNet(ifn string) bool {
	_, err := os.Stat("/sys/class/net/" + ifn)
	return err == nil
}

// detached: een opdracht op de achtergrond die doorloopt als deze ronde klaar is (onder systemd in een eigen eenheid)
func detached(unit string, args ...string) {
	if underSystemd() {
		_, _ = run(15*time.Second, "systemd-run", append([]string{"--quiet", "--collect", fmt.Sprintf("--unit=%s-%d", unit, time.Now().Unix())}, args...)...)
		return
	}
	cmd := exec.Command(args[0], args[1:]...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if cmd.Start() == nil {
		go func() { _ = cmd.Wait() }()
	}
}

func newerThan(file string, d time.Duration) bool {
	st, err := os.Stat(file)
	return err == nil && time.Since(st.ModTime()) < d
}

func touchNow(file string) {
	if fh, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY, 0o600); err == nil {
		fh.Close()
	}
	now := time.Now()
	_ = os.Chtimes(file, now, now)
}

// stoppedText: de laatste twee regels uit het log van een gestopte container, op één regel
func stoppedText(c *ctrHelper) string {
	out, _ := c.xAll(60*time.Second, "logs", "--tail", "4", c.ctr)
	keep := []string{}
	for _, l := range strings.Split(out, "\n") {
		if strings.TrimSpace(l) != "" {
			keep = append(keep, l)
		}
	}
	if len(keep) > 2 {
		keep = keep[len(keep)-2:]
	}
	if len(keep) == 0 {
		return ""
	}
	return cutRunes(strings.Join(keep, " ")+" ", 220)
}

// collectNidsLinux: het onderdeel "nids" van het rapport
func collectNidsLinux() map[string]any {
	conf, _ := readShellConf(bashConfPath())
	tests := readText(filepath.Join(bashCachePath(), "tests.cache"))
	if !isRoot() || conf["PROXY_GUARD"] == "0" || !hasLinePrefix(tests, "0\tnids\t") {
		return nil
	}
	on, prof, ifWant := "", "", ""
	for _, l := range strings.Split(tests, "\n") {
		if f := strings.Split(l, "\t"); len(f) >= 2 && f[0] == "0" && f[1] == "nids" {
			g := tabRead(strings.Join(f[2:], "\t"), 3)
			on, prof, ifWant = g[0], g[1], g[2]
			break
		}
	}
	if !ndProfRe.MatchString(prof) {
		prof = "balanced"
	}
	dir := conf["NIDS_DIR"]
	if dir == "" {
		dir = "/var/lib/rzsense-suricata"
	}
	fp := func(n string) string { return filepath.Join(dir, n) }
	ctrName := strings.TrimSpace(readText(fp("ctr.name"))) // de naam kan uitwijken (rzsense-suricata-2 …) als de vaste naam vastzit in Podman
	if !ndCtrRe.MatchString(ctrName) {
		ctrName = "rzsense-suricata"
	}
	img := conf["NIDS_IMAGE"]
	if img == "" {
		img = "docker.io/jasonish/suricata:7.0"
	}
	minMB := atoiDef(conf["NIDS_MIN_MB"], 800)
	mem := 0
	for _, l := range strings.Split(readText("/proc/meminfo"), "\n") {
		if f := strings.Fields(l); len(f) >= 2 && f[0] == "MemAvailable:" {
			mem = atoiDef(f[1], 0) / 1024
		}
	}
	px := &proxyGuard{conf: conf, tests: tests, state: proxyStateDir()}
	if hasLinePrefix(tests, "0\tproxy\t") {
		px.detect()
	}
	eng := px.eng
	if eng == "" {
		for _, e := range []string{"podman", "docker"} {
			if haveCmd(e) {
				eng = e
				break
			}
		}
	}
	c := &ctrHelper{eng: eng, ctr: ctrName, timeout: 60 * time.Second}
	state, msg, rules := "error", "", 0
	found, use := []string{}, []string{}
	var alerts json.RawMessage = json.RawMessage("[]")
	orMsg := func(def string) {
		if msg == "" {
			msg = def
		}
	}
	result := func() map[string]any {
		return map[string]any{"state": state, "msg": cleanText(msg), "mem": mem, "image": cleanText(img), "profile": prof, "rules": rules, "ifaces": use, "found": found, "alerts": alerts}
	}
	if on != "on" {
		if c.exists() {
			c.x("rm", "-f", ctrName)
			actLog("proxy", 1, "Suricata (proxynetwerk) uit", "container "+ctrName+" weggehaald")
		}
		_ = os.Remove(fp("env.hash"))
		state = "off"
		return result()
	}
	if eng == "" || px.ctr == "" {
		state, msg = "unsupported", "geen reverse proxy in Podman of Docker gevonden"
		return result()
	}
	// 1. Welke netwerkbruggen? (de netwerken van de proxycontainer, behalve dat van de WAF)
	nets, _ := c.x("inspect", "-f", "{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}", px.ctr)
	for _, net := range strings.Fields(nets) {
		if net == "rzsense" {
			continue
		}
		ifn := ""
		if eng == "podman" {
			out, _ := c.x("network", "inspect", "-f", "{{.NetworkInterface}}", net)
			if ifn = strings.TrimSpace(firstLineOf(out)); ifn == "" || ifn == "<no value>" {
				ifn = ""
				full, _ := c.x("network", "inspect", net)
				if m := ndNetIfRe.FindStringSubmatch(full); m != nil {
					ifn = m[1]
				}
			}
		} else {
			out, _ := c.x("network", "inspect", "-f", `{{index .Options "com.docker.network.bridge.name"}}`, net)
			if ifn = strings.TrimSpace(firstLineOf(out)); ifn == "" || ifn == "<no value>" {
				if net == "bridge" {
					ifn = "docker0"
				} else {
					id, _ := c.x("network", "inspect", "-f", "{{.Id}}", net)
					ifn = "br-" + cutBytes(strings.TrimSpace(id), 12)
				}
			}
		}
		if ndIfRe.MatchString(ifn) && sysNet(ifn) {
			found = append(found, ifn)
		}
	}
	if ifWant == "auto" || ifWant == "" {
		use = append(use, found...)
	} else {
		for _, ifn := range strings.Split(ifWant, ",") {
			if ndIfRe.MatchString(ifn) && sysNet(ifn) {
				use = append(use, ifn)
			}
		}
	}
	if len(use) > 4 {
		use = use[:4]
	}
	if len(use) == 0 {
		msg = "geen netwerkbrug gevonden (stel de interface in RzSense in)"
		return result()
	}
	for _, d := range []string{"log", "lib", "etc"} {
		_ = os.MkdirAll(fp(d), 0o755)
	}
	_ = os.Chmod(dir, 0o700)
	// 2. Regelprofiel: welke groepen van Emerging Threats Open uit (suricata-update leest disable.conf)
	keep := " web_server web_specific_apps exploit sql attack_response shellcode scan current_events "
	if prof != "web" {
		keep += "malware mobile_malware coinminer exploit_kit phishing dns worm dos user_agents "
	}
	dis := ""
	if prof != "full" {
		for _, g := range strings.Fields(ndAllGroups) {
			if !strings.Contains(keep, " "+g+" ") {
				dis += "group:emerging-" + g + ".rules\n"
			}
		}
	}
	disConf := "# RzSense: beheerd door de RzSense-agent (profiel " + prof + ")\n" + dis
	_ = os.WriteFile(fp("etc/disable.conf"), []byte(disConf), 0o644)
	// "m3" = offloading uit; een andere waarde maakt een bestaande container één keer opnieuw
	ehash := sha12(img + "|m3|" + strings.Join(use, "|") + "|")
	rsum := sha1.Sum([]byte(disConf))
	rhash := hex.EncodeToString(rsum[:])[:12]
	// 3. Container: (opnieuw) als hij er niet is of de interfaces veranderden; eerst genoeg geheugen?
	if exists := c.exists(); !exists || strings.TrimSpace(readText(fp("env.hash"))) != ehash {
		if !exists && mem < minMB {
			state, msg = "lowmem", fmt.Sprintf("te weinig vrij geheugen: %d MB, nodig %d MB", mem, minMB)
		} else {
			c.x("rm", "-f", ctrName)
			start := func() (string, int) {
				args := []string{"run", "-d", "--name", ctrName, "--restart", "unless-stopped", "--network", "host", "--cap-add", "net_admin", "--cap-add", "net_raw", "--cap-add", "sys_nice",
					"-v", dir + "/log:/var/log/suricata:Z", "-v", dir + "/lib:/var/lib/suricata:Z", "-v", dir + "/etc/disable.conf:/etc/suricata/disable.conf:Z", img}
				for _, ifn := range use {
					args = append(args, "-i", ifn)
				}
				// offloading (GRO/TSO) op de brug uit zolang Suricata draait; anders worden pakketten samengeplakt en afgekapt
				return c.xAll(240*time.Second, append(args, "--set", "capture.disable-offloading=true")...)
			}
			out, rc := start()
			// de naam nog in gebruik door een restant in de opslag van Podman: opruimen en één keer opnieuw
			if rc != 0 && eng == "podman" && strings.Contains(out, "already in use") {
				rm1, _ := c.xAll(60*time.Second, "rm", "-f", ctrName)
				rm2, _ := c.xAll(60*time.Second, "rm", "--storage", "--force", ctrName)
				if mnt := ndMntRe.FindString(rm1 + rm2); mnt != "" && !strings.Contains(mnt, "..") {
					_, _ = run(20*time.Second, "umount", "-l", mnt)
					c.x("rm", "--storage", "--force", ctrName)
				}
				actLog("proxy", 1, "Suricata (proxynetwerk): achtergebleven container opgeruimd", ctrName)
				if out, rc = start(); rc != 0 && strings.Contains(out, "already in use") {
					// zit de naam nog steeds vast (pas weg na een herstart van de server): een andere naam gebruiken
					n := 2
					if i := strings.LastIndex(ctrName, "-"); i >= 0 && digitsRe.MatchString(ctrName[i+1:]) {
						n = atoiDef(ctrName[i+1:], 1) + 1
					}
					ctrName = fmt.Sprintf("rzsense-suricata-%d", n)
					c.ctr = ctrName
					_ = os.WriteFile(fp("ctr.name"), []byte(ctrName+"\n"), 0o600)
					actLog("proxy", 1, "Suricata (proxynetwerk): andere containernaam", ctrName+" (de vorige naam zit vast in Podman tot een herstart)")
					c.x("rm", "-f", ctrName)
					out, rc = start()
				}
			}
			if rc == 0 {
				_ = os.WriteFile(fp("env.hash"), []byte(ehash+"\n"), 0o600)
				_ = os.Remove(fp("rules.hash"))
				actLog("proxy", 1, "Suricata (proxynetwerk) gestart", strings.Join(use, " ")+", profiel "+prof)
			} else {
				msg = "starten mislukt: " + tail2(out)
			}
		}
	}
	runningNow := func() bool {
		out, _ := c.x("inspect", "-f", "{{.State.Running}}", ctrName)
		return strings.TrimSpace(out) == "true"
	}
	switch {
	case runningNow():
		// 4. Regels bijwerken (nieuw profiel of ouder dan een dag), op de achtergrond; daarna herstarten om ze te laden
		rf := fp("lib/rules/suricata.rules")
		if isFile(fp("update.rc")) {
			if strings.TrimSpace(readText(fp("update.rc"))) == "0" {
				touchNow(fp("update.at"))
				if data, err := os.ReadFile(rf); err == nil {
					sum := sha1.Sum(data)
					if now := hex.EncodeToString(sum[:]); now != strings.TrimSpace(readText(fp("rules.sum"))) {
						_ = os.WriteFile(fp("rules.sum"), []byte(now+"\n"), 0o600)
						if _, rc := c.x("restart", ctrName); rc == 0 { // herstarten alleen als de regels echt veranderden
							actLog("proxy", 1, "Suricata (proxynetwerk): regels bijgewerkt", "profiel "+prof)
						}
					}
				}
			} else {
				l := lines(readText(fp("update.out")))
				last := ""
				if len(l) > 0 {
					last = l[len(l)-1]
				}
				msg = "regels bijwerken mislukt: " + cutRunes(last, 150)
				touchNow(fp("update.at")) // niet elke ronde opnieuw proberen: over een dag weer (of bij een ander profiel)
			}
			_ = os.Remove(fp("update.rc"))
		} else if st, err := os.Stat(rf); !isFile(fp("update.run")) && (((err != nil || st.Size() == 0) && !newerThan(fp("update.at"), 60*time.Minute)) ||
			strings.TrimSpace(readText(fp("rules.hash"))) != rhash || !newerThan(fp("update.at"), 1440*time.Minute)) {
			_ = os.WriteFile(fp("rules.hash"), []byte(rhash+"\n"), 0o600)
			touchNow(fp("update.run"))
			detached("rzsense-nids-update", "sh", "-c", `timeout 600 "$0" exec "$1" suricata-update --no-test --no-reload >"$2/update.out" 2>&1; echo $? >"$2/update.rc"; rm -f "$2/update.run"`, eng, ctrName, dir)
		}
		for _, l := range strings.Split(readText(rf), "\n") {
			if strings.HasPrefix(l, "alert") {
				rules++
			}
		}
		if isFile(fp("log/eve.json")) || isFile(fp("log/suricata.log")) {
			state = "ok"
			if rules == 0 {
				orMsg("regels worden opgehaald (de eerste keer een paar minuten)")
			}
		} else {
			state = "starting"
			orMsg("Suricata start nog")
		}
		// 5. Nieuwe meldingen uit eve.json (zelfde verwerking als een gewone Suricata); groot bestand = leegmaken
		eve := fp("log/eve.json")
		if st, err := os.Stat(eve); err == nil && readable(eve) {
			size := st.Size()
			pos := int64(-1)
			if p := strings.TrimSpace(readText(fp("eve.pos"))); digitsRe.MatchString(p) {
				pos = int64(atoiDef(p, -1))
			}
			if pos > size {
				pos = 0
			}
			if pos >= 0 {
				if fh, err := os.Open(eve); err == nil {
					if _, err := fh.Seek(pos, io.SeekStart); err == nil {
						b, _ := io.ReadAll(io.LimitReader(fh, size-pos))
						l := strings.Split(strings.TrimSuffix(string(b), "\n"), "\n")
						if len(l) > 50000 {
							l = l[len(l)-50000:]
						}
						alerts = json.RawMessage("[" + eveAlerts(strings.Join(l, "\n")) + "]")
					}
					fh.Close()
				}
			}
			if size > int64(atoiDef(conf["NIDS_EVE_MB"], 64))*1048576 {
				_ = os.Truncate(eve, 0)
				size = 0
			}
			_ = os.WriteFile(fp("eve.pos"), []byte(fmt.Sprintf("%d\n", size)), 0o600)
		}
	case state != "lowmem" && c.exists():
		// gestopt: de reden en de laatste logregels tonen, en hooguit elke 10 minuten opnieuw starten
		stOut, _ := c.x("inspect", "-f", "{{.State.Status}} {{.State.ExitCode}}", ctrName)
		was := strings.TrimSpace(stOut)
		if was == "" {
			was = "onbekend"
		}
		logTxt := stoppedText(c)
		if !newerThan(fp("start.at"), 10*time.Minute) {
			touchNow(fp("start.at"))
			if _, rc := c.x("start", ctrName); rc == 0 {
				if os.Getenv("RZSENSE_TEST_FAST") != "1" {
					time.Sleep(3 * time.Second)
				}
				if runningNow() {
					state, msg = "starting", "opnieuw gestart"
					actLog("proxy", 1, "Suricata (proxynetwerk) opnieuw gestart", "was: "+was)
				}
			}
		}
		if state != "starting" {
			def := "de container draait niet (" + was
			if logTxt != "" {
				def += ": " + logTxt
			}
			orMsg(def + "); bekijk: " + eng + " logs --tail 30 " + ctrName)
		}
	case state != "lowmem":
		orMsg("de container draait niet")
	}
	return result()
}
