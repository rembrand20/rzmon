package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestIisWeblog(t *testing.T) {
	t.Setenv("RZSENSE_DATA", t.TempDir())
	root := t.TempDir()
	iisRoot = root
	d := filepath.Join(root, "W3SVC1")
	os.MkdirAll(d, 0o700)
	f := filepath.Join(d, "u_ex261124.log")
	head := "#Software: Microsoft Internet Information Services 10.0\n#Fields: date time s-ip cs-method cs-uri-stem cs-uri-query s-port cs-username c-ip cs(User-Agent) cs(Referer) sc-status sc-substatus sc-win32-status time-taken\n"
	os.WriteFile(f, []byte(head), 0o600)
	if r := iisWeblog("auto"); len(r["ips"].(map[string][]any)) != 0 {
		t.Fatalf("eerste keer vanaf het eind: %v", r)
	}
	fh, _ := os.OpenFile(f, os.O_APPEND|os.O_WRONLY, 0o600)
	fh.WriteString("2026-11-24 10:00:00 10.0.0.5 GET /.env token=geheim 443 - 203.0.113.9 Mozilla/5.0+(sqlmap/1.7) - 404 0 2 5\n" +
		"2026-11-24 10:00:01 10.0.0.5 GET /index.html - 443 - 203.0.113.9 Mozilla/5.0 - 200 0 0 5\n" +
		"2026-11-24 10:00:02 10.0.0.5 GET / - 443 - 198.51.100.1 Mozilla/5.0 - 200 0 0 5\n")
	fh.Close()
	r := iisWeblog("auto")
	ips := r["ips"].(map[string][]any)
	v, ok := ips["203.0.113.9"]
	if !ok || len(ips) != 1 || v[0] != 2 || v[1] != 1 || v[4] != "sqlmap" || v[5].([]string)[0] != "/.env" || r["kind"] != "iis" {
		t.Fatalf("samenvatting: %v", ips)
	}
	if r := iisWeblog("auto"); len(r["ips"].(map[string][]any)) != 0 {
		t.Fatalf("niets dubbel: %v", r)
	}
}
