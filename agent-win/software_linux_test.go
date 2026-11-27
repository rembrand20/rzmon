//go:build linux

package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestClockParse(t *testing.T) {
	sync, off := parseChrony("Reference ID    : A9FEA97B (169.254.169.123)\nSystem time     : 0.000123456 seconds slow of NTP time\nLeap status     : Normal\n")
	if !sync || off != -0.123 {
		t.Fatalf("chrony: %v %v", sync, off)
	}
	if sync, off := parseChrony("System time     : 0.002000000 seconds fast of NTP time\nLeap status     : Not synchronised\n"); sync || off != 2.0 {
		t.Fatalf("chrony niet gesynchroniseerd: %v %v", sync, off)
	}
	for in, want := range map[string]float64{"        Offset: +1.234ms": 1.234, "  Offset: -250us": -0.25, "  Offset: +2s": 2000} {
		if got := parseTimesyncOffset(in); got != want {
			t.Fatalf("timesyncd %q: %v", in, got)
		}
	}
	if parseTimesyncOffset("Server: x") != nil {
		t.Fatal("zonder Offset hoort er geen afwijking te zijn")
	}
}

func TestPackageRows(t *testing.T) {
	b, _ := json.Marshal(dpkgRows("ii \topenssl\t3.0.13-0ubuntu3.5\tamd64\topenssl\t3.0.13-0ubuntu3.5\nrc \tweg\t1\tamd64\tweg\t1\nii \tlibssl3\t3.0.13\tamd64\topenssl\t3.0.13\n"))
	if string(b) != `[["openssl","3.0.13-0ubuntu3.5","amd64","openssl","3.0.13-0ubuntu3.5"],["libssl3","3.0.13","amd64","openssl","3.0.13"]]` {
		t.Fatalf("dpkg: %s", b)
	}
	b, _ = json.Marshal(rpmRows("openssl-libs\t1:3.2.2-6.el9_5.1\tx86_64\topenssl-3.2.2-6.el9_5.1.src.rpm\nbash\t0:5.1.8-9.el9\tx86_64\tbash-5.1.8-9.el9.src.rpm\ngpg-pubkey\t0:fd431d51-4ae0493b\t(none)\t(none)\n"))
	if string(b) != `[["openssl-libs","1:3.2.2-6.el9_5.1","x86_64","openssl","1:3.2.2-6.el9_5.1"],["bash","5.1.8-9.el9","x86_64","bash","5.1.8-9.el9"]]` {
		t.Fatalf("rpm: %s", b)
	}
}

func TestLatestKernel(t *testing.T) {
	got := latestKernel([]string{"vmlinuz-5.14.0-503.9.1.el9_5.x86_64", "vmlinuz-5.14.0-503.21.1.el9_5.x86_64", "vmlinuz-0-rescue-abc", "initramfs-5.14.0-999.img", "vmlinuz-5.14.0-70.el9.x86_64"})
	if got != "5.14.0-503.21.1.el9_5.x86_64" {
		t.Fatalf("nieuwste kernel: %q (9 hoort vóór 21 te komen)", got)
	}
}

func TestExposedFromSs(t *testing.T) {
	out := `tcp   LISTEN 0 128 0.0.0.0:22 0.0.0.0:* users:(("sshd",pid=812,fd=3))
tcp   LISTEN 0 128 127.0.0.1:631 0.0.0.0:* users:(("cupsd",pid=900,fd=7))
tcp   LISTEN 0 511 [::]:443 [::]:* users:(("nginx",pid=1200,fd=6),("nginx",pid=1201,fd=6))
udp   UNCONN 0 0 127.0.0.53%lo:53 0.0.0.0:* users:(("systemd-resolve",pid=600,fd=13))
udp   UNCONN 0 0 0.0.0.0:123 0.0.0.0:* users:(("chronyd",pid=700,fd=5))
tcp   LISTEN 0 128 [::1]:25 [::]:* users:(("master",pid=950,fd=13))
tcp   LISTEN 0 128 *:9100 *:*`
	got := exposedFromSs(out)
	b, _ := json.Marshal(func() (r [][]any) {
		for _, p := range got {
			r = append(r, []any{p.proto, p.port, p.pids})
		}
		return
	}())
	if string(b) != `[["tcp",22,["812"]],["tcp",443,["1200","1201"]],["udp",123,["700"]]]` {
		t.Fatalf("bereikbaar: %s", b)
	}
}

func TestDpkgOwners(t *testing.T) {
	dir := t.TempDir()
	_ = os.WriteFile(filepath.Join(dir, "openssh-server.list"), []byte("/.\n/usr/sbin/sshd\n"), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "nginx-core:amd64.list"), []byte("/usr/sbin/nginx\n"), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "coreutils.list"), []byte("/bin/ls\n"), 0o600)
	got := dpkgOwners([]string{"/usr/sbin/sshd", "/sbin/nginx", "/usr/bin/ls", "/opt/eigen/app"}, dir)
	if got["/usr/sbin/sshd"] != "openssh-server" || got["/sbin/nginx"] != "nginx-core" || got["/usr/bin/ls"] != "coreutils" || got["/opt/eigen/app"] != "" {
		t.Fatalf("eigenaren: %v", got)
	}
}
