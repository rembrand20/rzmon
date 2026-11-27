//go:build linux

package main

// Overnemen (0.13.0): de Go-agent doet het werk van de bash-agent op deze server, als RzSense daarom vraagt.
//
// Zo gaat het:
//   - RzSense vraagt het in het antwoord op een proefronde (alleen als de proef lang genoeg gelijk was en de server niets
//     gebruikt wat de Go-agent nog niet kan).
//   - De agent zet de timer van de bash-agent en zijn bewaker uit (niet weg), haalt zelf de testlijst op en stuurt het
//     echte rapport. Pas als RzSense dat aanneemt, geldt de overname.
//   - Lukt het rapport vijf keer achter elkaar niet, of vraagt RzSense het terug, dan gaat de bash-agent weer aan en is de
//     Go-agent weer een proef. Een los bewakertje (rzsense-fallback, een shellscript) doet hetzelfde als de Go-agent zelf
//     helemaal niet meer draait.
//
// De bash-agent blijft geïnstalleerd, met dezelfde instellingen en dezelfde sleutel. Een nieuwe sleutel van RzSense
// schrijft de Go-agent in het sleutelbestand van de bash-agent, zodat teruggaan altijd werkt.

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"golang.org/x/sys/unix"
)

const (
	bashTimer  = "rzmon-agent.timer"
	bashGuard  = "rzmon-agent-guard.timer"
	fallbackOK = "/etc/systemd/system/rzsense-fallback.timer"
	maxFails   = 5
)

func modeActive() bool { return readState("mode") == "active" }

// takeoverReady: wat er op deze server nodig is om veilig over te nemen (gaat mee met elke proefronde)
func takeoverReady(c *Config) map[string]any {
	timer, known := systemctlActive(bashTimer)
	fb := fallbackOK
	if p := os.Getenv("RZSENSE_FALLBACK_UNIT"); p != "" { // alleen voor de tests
		fb = p
	}
	_, fbErr := os.Stat(fb)
	keyDir := c.extra["key_dir"]
	return map[string]any{
		"systemd":     known,
		"bash_timer":  timer || modeActive(), // na het overnemen staat hij bewust uit
		"fallback":    fbErr == nil,
		"key_write":   keyDir != "" && unix.Access(keyDir, unix.W_OK) == nil,
		"cache_write": unix.Access(filepath.Dir(authStatePath()), unix.W_OK) == nil, // 0.14.0: de stand van de logins delen met de bash-agent
		"mode":        map[bool]string{true: "active", false: "shadow"}[modeActive()],
		"note":        readState("takeover.last"),
	}
}

func systemctlDo(args ...string) error {
	out, code := run(30*time.Second, "systemctl", args...)
	if code != 0 {
		return fmt.Errorf("systemctl %s: %s", strings.Join(args, " "), oneLine(out))
	}
	return nil
}

// backToBash: de bash-agent weer aan het werk zetten en zelf weer proef worden
func backToBash(reason string, hold bool) {
	stamp := time.Now().Format("2006-01-02 15:04:05")
	err := systemctlDo("enable", "--now", bashTimer)
	_ = systemctlDo("enable", "--now", bashGuard) // bestaat pas vanaf bash-agent 1.118.0
	watchStop()
	if readState("bash.watch") == "1" { // 0.30.0: de watcher van de bash-agent weer aan
		_ = systemctlDo("enable", "--now", bashWatch)
		dropState("bash.watch")
	}
	writeState("mode", "shadow")
	dropState("fails")
	if hold {
		writeState("takeover.hold", fmt.Sprint(time.Now().Unix())) // een uur niet opnieuw overnemen (geen heen en weer)
	} else {
		dropState("takeover.hold")
	}
	msg := stamp + " terug naar de bash-agent: " + reason
	if err != nil {
		msg += " (LET OP: de timer van de bash-agent start niet: " + err.Error() + ")"
	}
	writeState("takeover.last", msg)
}

// fetchTests: de testlijst zelf ophalen (de bash-agent ververst hem niet meer) en bewaren in de eigen map
func fetchTests(c *Config) (string, error) {
	b, err := signedGet(c, "agent_tests", 20*time.Second)
	if err != nil {
		return "", err
	}
	tmp := filepath.Join(dataDir(), "tests.cache.tmp")
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return "", err
	}
	if err := os.Rename(tmp, filepath.Join(dataDir(), "tests.cache")); err != nil {
		return "", err
	}
	return parseTests(string(b)).Rev, nil
}

type runReply struct {
	OK       bool   `json:"ok"`
	Error    string `json:"error"`
	Gz       int    `json:"gz"`
	TestsRev string `json:"tests_rev"`
	AgentKey string `json:"agent_key"`
	CronAge  *int   `json:"cron_age"` // 0.22.0: hoe lang de checks in RzSense al niet draaiden (voor de waakhond)
	RzHold   *struct {
		ID  string `json:"id"`
		Max int    `json:"max"`
	} `json:"rz_hold"` // RzSense wordt zo bijgewerkt
	HealRun        [][]any           `json:"heal_run"`        // 0.21.0: herstelcommando's uitvoeren: [[check-id, naam] …]
	HbUnknown      []string          `json:"hb_unknown"`      // 0.20.0: heartbeats zonder check in RzSense
	PackagesOK     bool              `json:"packages_ok"`     // 0.16.0: RzSense heeft de softwarelijst
	PackagesResend bool              `json:"packages_resend"` // RzSense wil de hele lijst opnieuw
	PkgUpgrade     *pkgOrder         `json:"pkg_upgrade"`     // 0.15.0: updates installeren
	GoBack         bool              `json:"go_back"`
	URLPins        map[string]string `json:"url_pins"`
	EdrYara        string            `json:"edr_yara"` // 0.30.0: nieuwe YARA-regels (base64)
	EdrKill        [][]int64         `json:"edr_kill"` // processen die RzSense meteen wil laten stoppen: [[pid, starttijd] …]
	GoUpdate       *struct {
		Version string `json:"version"`
		SHA     string `json:"sha256"`
	} `json:"go_update"`
}

// saveKey: een nieuwe eigen sleutel van RzSense in het sleutelbestand van de bash-agent (in één keer, alleen voor root)
func saveKey(c *Config, key string) error {
	dir := c.extra["key_dir"]
	if dir == "" || !hexKeyRe.MatchString(key) {
		return fmt.Errorf("geen sleutelmap")
	}
	tmp := filepath.Join(dir, ".agent.key.rzsense")
	if err := os.WriteFile(tmp, []byte(key+"\n"), 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(dir, "agent.key"))
}

// savePins: hoe elk adres gecontroleerd wordt (url.pins van de bash-agent: "adres <spatie> sha256//… of system"), zodat
// een nieuw certificaat van RzSense ook na het overnemen geen storing geeft. Andere adressen in het bestand blijven staan.
func savePins(c *Config, pins map[string]string) {
	dir := c.extra["key_dir"]
	if dir == "" || len(pins) == 0 {
		return
	}
	file := filepath.Join(dir, "url.pins")
	old := readText(file)
	var lines []string
	for _, l := range strings.Split(old, "\n") {
		if f := strings.Fields(l); len(f) == 2 && pins[f[0]] == "" {
			lines = append(lines, l)
		}
	}
	for _, u := range []string{c.URL, c.URLFallback} {
		spec := pins[u]
		if u == "" || !(spec == "system" || pinSpecRe.MatchString(spec)) {
			continue
		}
		lines = append(lines, u+" "+spec)
		if u == c.URL {
			c.Pin = map[bool]string{true: "", false: spec}[spec == "system"]
		}
	}
	text := strings.Join(lines, "\n") + "\n"
	if text == old || len(lines) > 6 {
		return
	}
	tmp := filepath.Join(dir, ".url.pins.rzsense")
	if os.WriteFile(tmp, []byte(text), 0o600) == nil {
		_ = os.Rename(tmp, file)
	}
}

var pinSpecRe = regexp.MustCompile(`^sha256//[A-Za-z0-9+/]{43}=(;sha256//[A-Za-z0-9+/]{43}=)*$`)

var sentPkgID string // de uitkomst van het installeren die met dit rapport meeging
var sentIds idsRun   // 0.29.0: wat er voor het IDS na een aangenomen rapport vastgelegd wordt
var sentAct bool     // er gingen regels van het logboek mee
var sentEdr edrRun   // 0.30.0: de wachtrij van de endpoint-sensoren die met dit rapport meeging

// buildRun: het echte rapport, met de namen van de bash-agent. "go": 1 zegt RzSense dat dit de Go-agent is.
func buildRun(c *Config, t0 time.Time) map[string]any {
	runHealLinux() // 0.21.0: zelf herstellen: vóór de metingen, zodat een gelukte herstart meteen als "draait" meetelt
	// 0.20.0: containers: twee metingen met de rest van de ronde ertussen (zo kost het CPU-gebruik per container geen extra tijd)
	var rep map[string]any
	podman, busy := collectContainersLinux(func() { rep = coreCollect(2 * time.Second) })
	if podman != nil {
		rep["podman"] = podman
	}
	if busy != "" {
		rep["ctr_busy"] = busy
	}
	if heal, cmds := healReport(); len(heal) > 0 || len(cmds) > 0 { // 0.21.0: uitkomsten van herstelpogingen en de herstelcommando's hier
		if len(heal) > 0 {
			rep["heal"] = heal
		}
		if len(cmds) > 0 {
			rep["heal_cmds"] = cmds
		}
	}
	if gap := watchdogGap(); gap != nil { // 0.22.0: de waakhond bereikte RzSense een tijd niet
		rep["watchdog"] = gap
	}
	if h := readHold(c); h != nil && h.since > 0 { // hoe lang de agent wachtte tijdens een update van RzSense
		rep["hold"] = map[string]any{"id": h.id, "since": h.since, "q": 0}
	}
	if hb := collectHeartbeatsLinux(); len(hb) > 0 { // heartbeats van rzmon-hb
		rep["hb"] = hb
	}
	rep["agent_version"] = Version
	rep["os"] = "linux"
	rep["go"] = 1
	rep["arch"] = goArch()
	rep["url"] = c.URL
	rep["url_fallback"] = c.URLFallback
	rep["update"] = map[string]any{"via": "go"}
	rep["ready"] = takeoverReady(c)
	rep["update_last"] = readState("update.last")
	if auth := collectAuthLinux(); auth != nil { // 0.14.0: logins, sudo en su
		rep["auth"] = auth
	}
	if p := collectPatchLinux(true); p != nil { // 0.15.0: welke updates er klaarstaan (eens per uur)
		rep["patch"] = p
	}
	if l := collectLogChecksLinux(); l != nil { // 0.19.0: log-checks, logs vinden en een map doorbladeren
		rep["logs"] = l
	}
	if s := logScanLinux(false); s != nil {
		rep["log_scan"] = s
	}
	if b := logBrowseLinux(); b != nil {
		rep["log_browse"] = b
	}
	// 0.25.0: gevonden certificaatbestanden, en wat RzSense één keer vraagt: de zelftest, het meetpunt van buitenaf, de diagnose
	rep["cert_found"] = collectCertFoundLinux()
	if st := collectSelftestLinux(c); st != nil {
		rep["selftest"] = st
	}
	if xp := collectExprobeLinux(); xp != nil {
		rep["exprobe"] = xp
	}
	if dg := collectDiagLinux(); dg != nil {
		rep["diag"] = dg
	}
	if db := collectDbLinux(); db != nil { // 0.24.0: databasechecks (alleen lezen)
		rep["db"] = db
	}
	// 0.31.0: ransomware herkennen (lokaasbestanden, wijzigingen, kenmerken). 0.32.0: buiten de afscherming van de eigen
	// dienst, want de lokaasbestanden staan in je eigen mappen
	if rs := outsideJSON(60*time.Second, func() any { return collectRansomLinux() }, "ransom-test"); rs != nil {
		rep["ransom"] = rs
	}
	// 0.32.0: tijdelijk sudo (een eigen bestand in /etc/sudoers.d: ook buiten de afscherming), Programma's en sporen zoeken
	if sd := outsideJSON(45*time.Second, func() any { return collectSudoLinux() }, "sudo-test"); sd != nil && string(sd) != "[]" {
		rep["sudo"] = sd
	}
	if apps, ioc := collectAppsLinux(); apps != nil || ioc != nil {
		if apps != nil {
			rep["apps"] = apps
		}
		if ioc != nil {
			rep["ioc"] = ioc
		}
	}
	// 0.30.0: Realtime (de stand van de watcher) en de endpoint-sensoren (de wachtrij en de stand)
	ownPidsNote()
	if rt := realtimeReport(); rt != nil {
		rep["realtime"] = rt
	}
	sentEdr = collectEdrLinux()
	if sentEdr.json != nil {
		rep["edr"] = sentEdr.json
	}
	// 0.29.0: het IDS: waarnemen én ingrijpen (firewall, lokaas-poort, proces stoppen, isoleren, account vergrendelen)
	sentIds = collectIdsLinux(c)
	if sentIds.ids != nil {
		rep["ids"] = sentIds.ids
	}
	if sentIds.iso != nil {
		rep["iso"] = sentIds.iso
	}
	if len(sentIds.locks) > 0 {
		rep["userlock"] = sentIds.locks
	}
	if c := collectCmdLinux(); c != nil { // 0.18.0: commando-checks
		rep["cmd"] = c
	}
	paths, iperfs := collectPathLinux(true) // 0.17.0: pad-checks en doorvoer
	if paths != nil {
		rep["path"] = paths
	}
	if iperfs != nil {
		rep["iperfs"] = iperfs
	}
	if sw := collectSoftwareLinux(); sw != nil { // 0.16.0: de softwarelijst (Kwetsbaarheden)
		rep["packages"] = sw
	}
	if r := pkgResult(); r != nil { // de uitkomst van het installeren
		rep["pkg_result"] = r
		sentPkgID = r["id"].(string)
	}
	rep["timing"] = map[string]any{"total": time.Since(t0).Milliseconds()}
	sentAct = false
	if a := actReport(); len(a) > 0 { // 0.29.0: het logboek van wat de agent zelf veranderde
		rep["act"] = a
		sentAct = true
	}
	return rep
}

// runOnce: één echte ronde. Geeft het antwoord van RzSense, of een fout.
func runOnce(c *Config) (*runReply, error) {
	t0 := time.Now()
	if _, err := os.Stat(filepath.Join(dataDir(), "tests.cache")); err != nil || readState("tests.rev") == "" {
		rev, err := fetchTests(c)
		if err != nil {
			return nil, fmt.Errorf("testlijst niet opgehaald: %w", err)
		}
		writeState("tests.rev", rev)
	}
	plain, err := json.Marshal(buildRun(c, t0))
	if err != nil {
		return nil, err
	}
	if readState("gz") == "1" {
		var buf bytes.Buffer
		zw := gzip.NewWriter(&buf)
		_, _ = zw.Write(plain)
		_ = zw.Close()
		plain = buf.Bytes()
	}
	body, code, err := sealedCall(c, "agent", plain, nil, 50*time.Second)
	if err != nil {
		healSent(false)
		return nil, fmt.Errorf("rapport niet verstuurd: %w", err)
	}
	var r runReply
	if code != 200 || json.Unmarshal(body, &r) != nil || !r.OK {
		healSent(false)
		return nil, fmt.Errorf("rapport niet aangenomen (HTTP %d): %s", code, shortErr(body))
	}
	healSent(true)
	return &r, nil
}

// afterRun: het antwoord verwerken: comprimeren, een nieuwe sleutel, een veranderde testlijst, bijwerken, of terug naar bash
func afterRun(c *Config, r *runReply) {
	writeState("gz", fmt.Sprint(r.Gz))
	writeState("last_ok", fmt.Sprint(time.Now().Unix()))
	dropState("fails")
	// 0.22.0: de waakhond en een aangekondigde update van RzSense (rz_hold)
	watchdogGapSent()
	holdID, holdMax := "", 0
	if r.RzHold != nil && lastVia != "reserve" {
		holdID, holdMax = r.RzHold.ID, r.RzHold.Max
	}
	hold, released := holdAfter(c, true, holdID, holdMax)
	watchdogStep(true, "", r.CronAge, hold, released)
	if lastVia == "reserve" {
		return // een reserve-RzSense verandert geen sleutel en geeft geen opdrachten
	}
	if hexKeyRe.MatchString(r.AgentKey) && r.AgentKey != c.Key {
		if err := saveKey(c, r.AgentKey); err != nil {
			writeState("takeover.last", time.Now().Format("2006-01-02 15:04:05")+" nieuwe sleutel niet bewaard: "+err.Error())
		} else {
			c.Key = r.AgentKey
		}
	}
	savePins(c, r.URLPins)
	if r.TestsRev != "" && r.TestsRev != readState("tests.rev") {
		dropState("tests.rev") // de volgende ronde haalt de lijst opnieuw op
	}
	if sentEdr.commit != nil && sentEdr.json != nil { // de verstuurde gebeurtenissen uit de wachtrij
		sentEdr.commit()
		conf, _ := readShellConf(bashConfPath())
		edrYaraUpdate(r.EdrYara)
		edrKillNow(r.EdrKill, conf)
	}
	watchEnsure()              // de watcher aan of uit, naar wat RzSense voor deze server wil
	if sentIds.commit != nil { // de nieuwe stand van bewaakte bestanden bewaren, lokaas-treffers uit de set
		sentIds.commit()
	}
	if sentAct {
		actSent()
	}
	if sentPkgID != "" { // de uitkomst is aangekomen
		if p := pkgResult(); p != nil && p["id"] == sentPkgID {
			_ = os.Remove(upgradeResultFile())
		}
	}
	if r.GoBack {
		backToBash("gevraagd vanuit RzSense", false)
		return
	}
	heartbeatsSent() // 0.20.0: RzSense heeft ze
	if len(r.HbUnknown) > 0 {
		writeState("hb.unknown", time.Now().Format("2006-01-02 15:04:05")+" RzSense heeft geen heartbeat-check met de naam "+strings.Join(r.HbUnknown, ", ")+" op deze server (＋ Check → Heartbeat)")
	}
	runHealCommands(r.HealRun) // 0.21.0: alleen een bestand uit HEAL_DIR op de server zelf
	shipLogsLinux(c)           // 0.19.0: logs verzamelen: los van het rapport, in pakketjes
	softwareReply(r.PackagesOK, r.PackagesResend)
	if r.PkgUpgrade != nil {
		startUpgrade(*r.PkgUpgrade)
	}
	if r.GoUpdate != nil && hold == nil { // niet tijdens een update van RzSense
		selfUpdateLinux(c, r.GoUpdate.Version, r.GoUpdate.SHA)
	}
}

// takeOver: de overname zelf. Pas na een aangenomen echt rapport is het rond; anders gaat alles terug zoals het was.
func takeOver(c *Config) error {
	if d := time.Now().Unix() - int64(atoiDef(readState("takeover.hold"), 0)); d >= 0 && d < 3600 { // een tijd in de toekomst (klok verzet) telt niet
		return fmt.Errorf("de Go-agent viel minder dan een uur geleden terug op de bash-agent; hij probeert het daarna opnieuw")
	}
	ready := takeoverReady(c)
	for _, k := range []string{"systemd", "bash_timer", "fallback", "key_write", "cache_write"} {
		if ready[k] != true {
			return fmt.Errorf("deze server is er niet klaar voor (%s); voer het installatiecommando uit RzSense opnieuw uit", k)
		}
	}
	dropState("tests.rev")
	if _, err := fetchTests(c); err != nil {
		return fmt.Errorf("testlijst niet opgehaald: %w", err)
	}
	// Eerst de bewaker uit (die zou de timer weer starten), dan de timer zelf; een lopende ronde mag uitlopen
	_ = systemctlDo("disable", "--now", bashGuard)
	if err := systemctlDo("disable", "--now", bashTimer); err != nil {
		_ = systemctlDo("enable", "--now", bashGuard)
		return err
	}
	// 0.30.0: de watcher van de bash-agent uit (die zou anders rondes van de bash-agent blijven starten); de Go-agent start
	// zijn eigen watcher als Realtime of de endpoint-sensoren aan staan. Bij teruggaan komt die van de bash-agent terug.
	if out, _ := run(5*time.Second, "systemctl", "is-enabled", bashWatch); strings.TrimSpace(out) == "enabled" {
		writeState("bash.watch", "1")
		_ = systemctlDo("disable", "--now", bashWatch)
	} else if active, _ := systemctlActive(bashWatch); active {
		writeState("bash.watch", "1")
		_ = systemctlDo("stop", bashWatch)
	}
	for i := 0; i < 30; i++ { // tot een minuut wachten op een ronde van de bash-agent die nog loopt
		if out, _ := run(5*time.Second, "systemctl", "is-active", "rzmon-agent.service"); strings.TrimSpace(out) != "activating" && strings.TrimSpace(out) != "active" {
			break
		}
		time.Sleep(2 * time.Second)
	}
	writeState("mode", "active")
	writeState("last_ok", fmt.Sprint(time.Now().Unix())) // het bewakertje telt vanaf nu
	r, err := runOnce(c)
	if err != nil {
		backToBash("het eerste rapport lukte niet: "+err.Error(), true)
		return err
	}
	writeState("takeover.last", time.Now().Format("2006-01-02 15:04:05")+" overgenomen: de Go-agent doet het werk")
	afterRun(c, r)
	return nil
}

// cmdRun: de ronde van elke minuut als de Go-agent het werk doet
func cmdRun(quiet bool) int {
	c, err := loadBashConfig()
	if err != nil || c.Key == "" || c.URL == "" {
		backToBash("geen instellingen of sleutel meer gevonden", true)
		return 1
	}
	r, err := runOnce(c)
	if err != nil {
		// 0.22.0: tijdens een aangekondigde update van RzSense telt een mislukt rapport niet mee voor de terugval, en de
		// waakhond wacht tot de tijd die RzSense meegaf voorbij is
		hold, _ := holdAfter(c, false, "", 0)
		watchdogStep(false, cutRunes(oneLine(err.Error()), 120), nil, hold, false)
		if hold != nil {
			fmt.Fprintln(os.Stderr, "RzSense wordt bijgewerkt; de Go-agent wacht tot", time.Unix(hold.until, 0).Format("15:04"))
			return 1
		}
		n := atoiDef(readState("fails"), 0) + 1
		writeState("fails", fmt.Sprint(n))
		fmt.Fprintln(os.Stderr, "✘", err)
		if n >= maxFails {
			backToBash(fmt.Sprintf("%d rapporten achter elkaar mislukt: %s", n, err.Error()), true)
		}
		return 1
	}
	afterRun(c, r)
	if !quiet {
		fmt.Println("✔ Rapport verstuurd en door RzSense aangenomen (de Go-agent doet het werk)")
	}
	return 0
}

// cmdBack: met de hand terug naar de bash-agent (rzsense back)
func cmdBack() int {
	if _, err := exec.LookPath("systemctl"); err != nil {
		fmt.Fprintln(os.Stderr, "✘ systemctl ontbreekt")
		return 1
	}
	backToBash("met de hand (rzsense back); zet het overnemen in RzSense ook uit, anders neemt de Go-agent het na een uur weer over", true)
	fmt.Println("✔", readState("takeover.last"))
	return 0
}
