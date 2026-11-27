//go:build linux

package main

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestDnfList(t *testing.T) {
	all := "\nopenssl-libs.x86_64   1:3.2.2-6.el9_5.1   baseos\nkernel.x86_64  5.14.0-503.21.1.el9_5  baseos\ntzdata.noarch 2025a-1.el9 baseos\nObsoleting Packages\nLast metadata expiration check: 0:10:00 ago\nnaam zonder-versie repo\n"
	sec := "openssl-libs.x86_64   1:3.2.2-6.el9_5.1   baseos\n"
	b, _ := json.Marshal(dnfList(all, sec))
	if string(b) != `[["kernel","5.14.0-503.21.1.el9_5",0],["openssl-libs","1:3.2.2-6.el9_5.1",1],["tzdata","2025a-1.el9",0]]` {
		t.Fatalf("dnf: %s", b)
	}
}

func TestAptList(t *testing.T) {
	out := "Reading package lists...\nInst libssl3 [3.0.13-0ubuntu3.4] (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-security [amd64])\nInst tzdata [2024a-3] (2025a-0ubuntu0.24.04 Ubuntu:24.04/noble-updates [all])\nInst tzdata [2024a-3] (2025a-0ubuntu0.24.04 Ubuntu:24.04/noble-updates [all])\nConf libssl3 (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-security [amd64])\n"
	b, _ := json.Marshal(aptList(out))
	if string(b) != `[["libssl3","3.0.13-0ubuntu3.5",1],["tzdata","2025a-0ubuntu0.24.04",0]]` {
		t.Fatalf("apt: %s", b)
	}
}

func TestUpgradeArgs(t *testing.T) {
	conf := map[string]string{}
	mode, names, _ := upgradeArgs(pkgOrder{ID: "abcdef01", Pkgs: []string{"openssl", "; rm -rf /", "--installroot=/x", "lib+x.y_z:amd64~1"}}, conf)
	if mode != "upgrade" || strings.Join(names, " ") != "openssl lib+x.y_z:amd64~1" {
		t.Fatalf("alleen gewone pakketnamen horen door te komen: %s %v", mode, names)
	}
	if _, _, why := upgradeArgs(pkgOrder{ID: "abcdef01", Pkgs: []string{"-y"}}, conf); !strings.Contains(why, "geen geldige pakketnamen") && !strings.Contains(why, "root") {
		t.Fatalf("zonder geldige naam hoort er niets te gebeuren: %q", why)
	}
	if _, _, why := upgradeArgs(pkgOrder{ID: "abcdef01", Pkgs: []string{"yara"}, Mode: "install"}, conf); !strings.Contains(why, "geen nieuwe pakketten") {
		t.Fatalf("nieuwe pakketten installeren hoort geweigerd te worden: %q", why)
	}
	if _, _, why := upgradeArgs(pkgOrder{ID: "abcdef01", Mode: "all"}, map[string]string{"COMMANDS": "off"}); why == "" {
		t.Fatal("met COMMANDS=off hoort bijwerken geblokkeerd te zijn")
	}
}

func TestDnfProgressLines(t *testing.T) {
	if m := dnfDownload.FindStringSubmatch("(3/7): openssl-libs-3.2.2.rpm   1.2 MB/s"); m == nil || m[1] != "3" || m[2] != "7" || m[3] != "openssl-libs-3.2.2.rpm" {
		t.Fatalf("downloadregel: %v", m)
	}
	if m := dnfStep.FindStringSubmatch("  Upgrading        : openssl-libs-1:3.2.2-6.el9_5.1.x86_64    2/14"); m == nil || m[1] != "Upgrading" || m[4] != "2" || m[5] != "14" {
		t.Fatalf("installatieregel: %v", m)
	}
	if m := dnfStep.FindStringSubmatch("  Running scriptlet: tzdata-2025a-1.el9.noarch    1/4"); m == nil || m[1] != "Running scriptlet" {
		t.Fatalf("scriptlet: %v", m)
	}
	if m := dnf5Step.FindStringSubmatch("[ 3/14] Upgrading openssl"); m == nil || m[3] != "Upgrading openssl" {
		t.Fatalf("dnf5: %v", m)
	}
}
