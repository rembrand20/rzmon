//go:build linux

package main

// De waakhond (0.22.0), zoals de bash-agent (agent/src/95-waakhond.sh). In RzSense per server aan te zetten. Lukt het
// rapport een tijd niet (standaard 5 minuten), dan stuurt de agent zelf een pushbericht (Pushover en/of ntfy), en een
// herstelbericht als RzSense terug is. Bereikt de agent ook die dienst niet, dan ligt het aan zijn eigen netwerk: dan
// geen bericht, wel een notitie voor RzSense. Draait RzSense wel, maar staan de checks stil (cron), dan meldt hij dat ook.
//
// De gegevens van Pushover en ntfy komen versleuteld van RzSense, in de testlijst; ze staan alleen in een bestand dat
// alleen root leest. De agent praat alleen met die twee adressen, alleen als de waakhond voor deze server aan staat, en
// controleert het certificaat altijd. WATCHDOG=0 in de instellingen van de agent zet het uit.
//
// Ook hier: tijdens een aangekondigde update van RzSense (rz_hold) geeft de waakhond geen alarm tot de tijd die RzSense
// meegaf voorbij is. De stand (sinds wanneer onbereikbaar, of er al gemeld is) staat in hetzelfde bestand als bij de
// bash-agent, zodat er bij overnemen en teruggaan geen tweede alarm komt.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var (
	ntfyTopicRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	ntfySrvRe   = regexp.MustCompile(`^https?://[^[:space:]]+$`)
	holdIDRe    = regexp.MustCompile(`^[0-9a-f]{8,32}$`)
	wdStateRe   = regexp.MustCompile(`^wd_(down|sent|self|cron)=([0-9]+)$`)
	wdGapRe     = regexp.MustCompile(`^\{"down":[0-9]+,"up":[0-9]+,"sent":[01],"self":[01]\}$`)
)

type wdConf struct {
	min                     int
	user, token             string
	prio                    int
	ntfySrv, ntfyTop, ntfyT string
	api                     string
}

// wdParse: de regel "0 <tab> watchdog <tab> minuten <tab> gebruiker <tab> token <tab> prioriteit <tab> ntfy-server
// <tab> onderwerp <tab> ntfy-token" uit de testlijst, of nil
func wdParse(tests string, conf map[string]string) *wdConf {
	for _, l := range strings.Split(tests, "\n") {
		f := strings.Split(l, "\t")
		if len(f) < 3 || f[0] != "0" || f[1] != "watchdog" {
			continue
		}
		for len(f) < 9 {
			f = append(f, "")
		}
		w := &wdConf{min: 5, user: f[3], token: f[4], prio: 1, ntfySrv: f[6], ntfyTop: f[7], ntfyT: f[8], api: conf["PUSHOVER_API"]}
		if reDigits.MatchString(f[2]) {
			w.min = atoiDef(f[2], 5)
		}
		if p, err := strconv.Atoi(f[5]); err == nil && p >= -2 && p <= 2 && regexp.MustCompile(`^-?[0-2]$`).MatchString(f[5]) {
			w.prio = p
		}
		if !ntfySrvRe.MatchString(w.ntfySrv) || !ntfyTopicRe.MatchString(w.ntfyTop) {
			w.ntfySrv = "-" // geen (geldige) ntfy-server of een ongeldig onderwerp: ntfy uit
		}
		if w.api == "" {
			w.api = "https://api.pushover.net/1/messages.json"
		}
		return w
	}
	return nil
}

var wdHTTP = &http.Client{Timeout: 15 * time.Second}

// push: het bericht versturen; true = verstuurd via minstens één dienst
func (w *wdConf) push(title, msg string, prio int) bool {
	ok := false
	if w.user != "-" && w.token != "-" && w.user != "" {
		var body bytes.Buffer
		mw := multipart.NewWriter(&body)
		_ = mw.WriteField("token", strings.ReplaceAll(w.token, `"`, ""))
		_ = mw.WriteField("user", strings.ReplaceAll(w.user, `"`, ""))
		_ = mw.WriteField("title", title)
		_ = mw.WriteField("message", msg)
		_ = mw.WriteField("priority", strconv.Itoa(prio))
		if prio == 2 {
			_ = mw.WriteField("retry", "120")
			_ = mw.WriteField("expire", "3600")
		}
		_ = mw.Close()
		if req, err := http.NewRequest("POST", w.api, &body); err == nil {
			req.Header.Set("Content-Type", mw.FormDataContentType())
			if resp, err := wdHTTP.Do(req); err == nil {
				resp.Body.Close()
				ok = ok || (resp.StatusCode >= 200 && resp.StatusCode < 300)
			}
		}
	}
	if w.ntfySrv != "-" {
		one := func(s string) string {
			return strings.NewReplacer("\n", " ", "\t", " ", "\r", " ").Replace(cleanText(s))
		}
		b, _ := json.Marshal(map[string]any{"topic": w.ntfyTop, "title": one(title), "message": one(msg), "priority": prio + 3})
		if req, err := http.NewRequest("POST", strings.TrimSuffix(w.ntfySrv, "/"), bytes.NewReader(b)); err == nil {
			req.Header.Set("Content-Type", "application/json")
			if t := strings.ReplaceAll(w.ntfyT, `"`, ""); t != "" && t != "-" {
				if u, p, isBasic := strings.Cut(t, ":"); isBasic {
					req.SetBasicAuth(u, p)
				} else {
					req.Header.Set("Authorization", "Bearer "+t)
				}
			}
			if resp, err := wdHTTP.Do(req); err == nil {
				resp.Body.Close()
				ok = ok || (resp.StatusCode >= 200 && resp.StatusCode < 300)
			}
		}
	}
	return ok
}

// stateFile: de map met de stand van de bash-agent (gedeeld), of de eigen map als daar niet te schrijven valt
func wdStateFile() string {
	base := filepath.Dir(authStatePath())
	if p := os.Getenv("RZSENSE_BASH_STATE"); p != "" { // alleen voor de tests
		base = p
	}
	if writable(base) {
		return filepath.Join(base, "watchdog")
	}
	return filepath.Join(dataDir(), "watchdog")
}

// ---------------------------------------------------------------- aangekondigde update van RzSense (rz_hold)

type rzHold struct {
	id           string
	until, since int64
}

func holdFile(c *Config) string {
	if k := c.extra["key_dir"]; k != "" {
		return filepath.Join(k, "rzhold")
	}
	return filepath.Join(dataDir(), "rzhold")
}

// readHold: de wachtstand zoals de bash-agent hem bewaart ("id tot sinds"), of nil
func readHold(c *Config) *rzHold {
	f := strings.Fields(readText(holdFile(c)))
	if len(f) < 3 || !holdIDRe.MatchString(f[0]) || !reDigits.MatchString(f[1]) || !reDigits.MatchString(f[2]) {
		return nil
	}
	h := &rzHold{id: f[0]}
	h.until, _ = strconv.ParseInt(f[1], 10, 64)
	h.since, _ = strconv.ParseInt(f[2], 10, 64)
	return h
}

func writeHold(c *Config, h *rzHold) {
	tmp := holdFile(c) + ".rzsense"
	if os.WriteFile(tmp, []byte(fmt.Sprintf("%s %d %d\n", h.id, h.until, h.since)), 0o600) == nil {
		_ = os.Rename(tmp, holdFile(c))
	}
}

// holdAfter: de wachtstand bijwerken na een ronde. ok: het rapport kwam aan; id/max: wat RzSense in het antwoord meegaf.
// Geeft de wachtstand die nu geldt (nil = geen) en of hij net is vrijgegeven.
func holdAfter(c *Config, ok bool, id string, max int) (cur *rzHold, released bool) {
	now := time.Now().Unix()
	h := readHold(c)
	if ok {
		if holdIDRe.MatchString(id) && max > 0 {
			if max > 3600 {
				max = 3600
			}
			n := &rzHold{id: id, until: now + int64(max)}
			if h != nil && h.id == id {
				n.since = h.since
			}
			writeHold(c, n)
			return n, false
		}
		if h != nil {
			_ = os.Remove(holdFile(c))
			return nil, h.since > 0
		}
		return nil, false
	}
	if h != nil && now < h.until {
		if h.since == 0 {
			h.since = now
			writeHold(c, h)
		}
		return h, false
	}
	return nil, false
}

// ---------------------------------------------------------------- de waakhond zelf

// watchdogStep: na elke ronde. ok: het rapport kwam aan; errTxt: waarom niet; cronAge: hoe lang de checks in RzSense al
// niet draaiden (nil = niet meegegeven); hold: een aangekondigde update van RzSense.
func watchdogStep(ok bool, errTxt string, cronAge *int, hold *rzHold, released bool) {
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	w := wdParse(readText(filepath.Join(bashCachePath(), "tests.cache")), conf)
	if w == nil || conf["WATCHDOG"] == "0" {
		return
	}
	file := wdStateFile()
	st := map[string]int64{"down": 0, "sent": 0, "self": 0, "cron": 0}
	for _, l := range strings.Split(readText(file), "\n") {
		if m := wdStateRe.FindStringSubmatch(l); m != nil {
			st[m[1]], _ = strconv.ParseInt(m[2], 10, 64)
		}
	}
	host, _ := os.Hostname()
	now := time.Now().Unix()
	hm := func(t int64) string { return time.Unix(t, 0).Format("15:04") }
	if !ok {
		if st["down"] <= 0 {
			st["down"] = now
		}
		title := "⚠️ RzSense onbereikbaar"
		extra := ""
		if errTxt != "" {
			extra = " (" + errTxt + ")"
		}
		msg := fmt.Sprintf("RzSense is vanaf %s niet bereikbaar sinds %s%s. Gemeld door de waakhond op %s.", host, hm(st["down"]), extra, host)
		if hold != nil {
			since := hold.since
			if since == 0 {
				since = st["down"]
			}
			title = "⚠️ RzSense onbereikbaar tijdens update"
			msg = fmt.Sprintf("De update van RzSense begon om %s en RzSense is nog niet terug. Gemeld door de waakhond op %s.", hm(since), host)
		}
		if st["sent"] == 0 && now-st["down"] >= int64(w.min)*60 && (hold == nil || now >= hold.until) {
			if w.push(title, msg, w.prio) {
				st["sent"], st["self"] = 1, 0
			} else {
				st["self"] = 1 // Pushover/ntfy ook niet bereikbaar (of geen gegevens): eerder het netwerk van deze server
			}
		}
	} else {
		if st["down"] > 0 {
			if st["sent"] == 1 {
				w.push("✅ RzSense weer bereikbaar", fmt.Sprintf("RzSense is vanaf %s weer bereikbaar (%d min weg).", host, (now-st["down"]+59)/60), -1)
			}
			// Voor RzSense (ℹ️ in het volgende rapport): alleen als het langer duurde dan de ingestelde tijd
			if now-st["down"] >= int64(w.min)*60 && !released {
				_ = os.WriteFile(file+".gap", []byte(fmt.Sprintf(`{"down":%d,"up":%d,"sent":%d,"self":%d}`+"\n", st["down"], now, st["sent"], st["self"])), 0o600)
			}
			st["down"], st["sent"], st["self"] = 0, 0, 0
		}
		// RzSense antwoordt, maar staan de checks stil (cron)?
		if cronAge != nil {
			if *cronAge >= w.min*60 && st["cron"] == 0 {
				if w.push("⚠️ RzSense: checks staan stil", fmt.Sprintf("RzSense antwoordt, maar de checks hebben al %d min niet gedraaid (cron). Gemeld door de waakhond op %s.", *cronAge/60, host), w.prio) {
					st["cron"] = 1
				}
			} else if *cronAge < 300 && st["cron"] == 1 {
				w.push("✅ RzSense: checks draaien weer", fmt.Sprintf("De checks in RzSense draaien weer (gezien vanaf %s).", host), -1)
				st["cron"] = 0
			}
		}
	}
	_ = os.WriteFile(file, []byte(fmt.Sprintf("wd_down=%d\nwd_sent=%d\nwd_self=%d\nwd_cron=%d\n", st["down"], st["sent"], st["self"], st["cron"])), 0o600)
}

// watchdogGap: een periode waarin de waakhond RzSense niet bereikte, voor het rapport ("watchdog"), of nil
func watchdogGap() json.RawMessage {
	first, _, _ := strings.Cut(readText(wdStateFile()+".gap"), "\n")
	if wdGapRe.MatchString(first) {
		return json.RawMessage(first)
	}
	return nil
}

// watchdogGapSent: RzSense heeft het rapport: de notitie mag weg
func watchdogGapSent() { _ = os.Remove(wdStateFile() + ".gap") }
