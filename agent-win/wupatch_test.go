package main

import (
	"encoding/json"
	"testing"
)

func TestSplitWU(t *testing.T) {
	var wu map[string]any
	json.Unmarshal([]byte(`{"at":1700000000,"n":2,"crit":1,"imp":0,"rb":true,"list":[{"t":"a","s":"Critical","kb":"KB5034441"}],
		"all":[{"kb":"KB5034441","t":"2026-11 Cumulatieve update","s":"Critical","sec":true,"cat":"Security Updates","cve":["CVE-2026-1234","x"]},
		{"kb":"KB890830","t":"Hulpprogramma voor het verwijderen van schadelijke software","s":"","sec":false,"cat":"Update Rollups","cve":"CVE-2026-9"},
		{"kb":"rm -rf","t":"x"}]}`), &wu)
	short, patch := splitWU(wu)
	if _, ok := short["all"]; ok || short["n"] == nil {
		t.Fatalf("kort rapport: %v", short)
	}
	l := patch["list"].([][]any)
	if patch["mgr"] != "wu" || patch["avail"] != 2 || patch["sec"] != 1 || patch["rb"] != true || l[0][0] != "KB5034441" || l[0][2] != true {
		t.Fatalf("patch: %v", patch)
	}
	c := wuCVEs(wu)
	if len(c) != 1 || c[0][0] != "KB5034441" || len(c[0][3].([]string)) != 1 {
		t.Fatalf("cve's: %v", c)
	}
	if s, p := splitWU(map[string]any{"at": 1.0, "error": "x"}); s == nil || p != nil {
		t.Fatal("fout: alleen het korte rapport")
	}
}
