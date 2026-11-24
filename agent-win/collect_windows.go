//go:build windows

package main

import (
	"fmt"
	"os"
	"regexp"
	"runtime"
	"strings"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

var (
	kernel32          = windows.NewLazySystemDLL("kernel32.dll")
	procGetSystemTime = kernel32.NewProc("GetSystemTimes")
	procMemStatus     = kernel32.NewProc("GlobalMemoryStatusEx")
	procTick64        = kernel32.NewProc("GetTickCount64")
	iphlpapi          = windows.NewLazySystemDLL("iphlpapi.dll")
	procTcpTable      = iphlpapi.NewProc("GetExtendedTcpTable")
)

func ft(f windows.Filetime) uint64 { return uint64(f.HighDateTime)<<32 | uint64(f.LowDateTime) }

func sysTimes() (idle, total uint64, ok bool) {
	var i, k, u windows.Filetime
	r, _, _ := procGetSystemTime.Call(uintptr(unsafe.Pointer(&i)), uintptr(unsafe.Pointer(&k)), uintptr(unsafe.Pointer(&u)))
	if r == 0 {
		return 0, 0, false
	}
	return ft(i), ft(k) + ft(u), true // kernel-tijd bevat de idle-tijd al
}

type memStatusEx struct {
	Length, MemoryLoad                     uint32
	TotalPhys, AvailPhys                   uint64
	TotalPageFile, AvailPageFile           uint64
	TotalVirtual, AvailVirtual, AvailExtra uint64
}

// collectBasics: naam, Windows-versie, opstarttijd, CPU (over 2 seconden, zoals Linux), geheugen en schijven
func collectBasics() map[string]any {
	out := map[string]any{}
	host, _ := os.Hostname()
	out["hostname"] = host
	out["os_info"] = osInfo()
	if r, _, _ := procTick64.Call(); r != 0 {
		out["boot"] = time.Now().Add(-time.Duration(r) * time.Millisecond).Unix()
	}
	cpu := map[string]any{"cores": numCPU(), "load": []float64{}}
	if i1, t1, ok := sysTimes(); ok {
		time.Sleep(2 * time.Second)
		if i2, t2, ok := sysTimes(); ok && t2 > t1 {
			cpu["pct"] = roundTo((1-float64(i2-i1)/float64(t2-t1))*100, 1)
		}
	}
	out["cpu"] = cpu
	m := memStatusEx{Length: uint32(unsafe.Sizeof(memStatusEx{}))}
	if r, _, _ := procMemStatus.Call(uintptr(unsafe.Pointer(&m))); r != 0 {
		// Het wisselbestand telt als swap: wat het "commit"-totaal boven het geheugen uitkomt
		swapTotal, swapFree := uint64(0), uint64(0)
		if m.TotalPageFile > m.TotalPhys {
			swapTotal = m.TotalPageFile - m.TotalPhys
			if m.AvailPageFile > m.AvailPhys {
				swapFree = min(m.AvailPageFile-m.AvailPhys, swapTotal)
			}
		}
		out["mem"] = map[string]any{"total": m.TotalPhys, "avail": m.AvailPhys, "swap_total": swapTotal, "swap_free": swapFree}
	}
	out["disks"] = disks()
	if rebootPending() {
		out["reboot_required"] = 1
	}
	return out
}

func numCPU() int { return runtime.NumCPU() }

func roundTo(v float64, d int) float64 {
	p := 1.0
	for i := 0; i < d; i++ {
		p *= 10
	}
	return float64(int64(v*p+0.5)) / p
}

// disks: elke vaste schijf (C:, D: …) met totaal en vrij, in bytes
func disks() []map[string]any {
	var out []map[string]any
	mask, err := windows.GetLogicalDrives()
	if err != nil {
		return out
	}
	for i := 0; i < 26; i++ {
		if mask&(1<<uint(i)) == 0 {
			continue
		}
		root := fmt.Sprintf("%c:\\", 'A'+i)
		p, _ := windows.UTF16PtrFromString(root)
		if windows.GetDriveType(p) != windows.DRIVE_FIXED {
			continue
		}
		var free, total, totalFree uint64
		if err := windows.GetDiskFreeSpaceEx(p, &free, &total, &totalFree); err != nil || total == 0 {
			continue
		}
		out = append(out, map[string]any{"mount": root[:2], "total": total, "avail": free})
	}
	return out
}

// osInfo: productnaam, versie en build uit het register (bv. Windows Server 2022 Standard, 21H2, 20348)
func osInfo() map[string]any {
	k, err := registry.OpenKey(registry.LOCAL_MACHINE, `SOFTWARE\Microsoft\Windows NT\CurrentVersion`, registry.QUERY_VALUE)
	if err != nil {
		return map[string]any{}
	}
	defer k.Close()
	get := func(n string) string { v, _, _ := k.GetStringValue(n); return v }
	ubr, _, _ := k.GetIntegerValue("UBR")
	build := get("CurrentBuild")
	name := get("ProductName")
	// Windows Server 2025 noemt zichzelf in het register soms nog 2022: de build zegt het zeker
	if b := atoiDef(build, 0); b >= 26100 && strings.Contains(name, "Server 2022") {
		name = strings.Replace(name, "2022", "2025", 1)
	}
	return map[string]any{"name": name, "version": get("DisplayVersion"), "build": build, "ubr": ubr, "edition": get("EditionID"), "install_type": get("InstallationType")}
}

// rebootPending: wacht Windows op een herstart (Windows Update, onderdelen, hernoemde bestanden)?
func rebootPending() bool {
	for _, p := range []string{
		`SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired`,
		`SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending`,
	} {
		if k, err := registry.OpenKey(registry.LOCAL_MACHINE, p, registry.QUERY_VALUE); err == nil {
			k.Close()
			return true
		}
	}
	return false
}

// ---------------------------------------------------------------- services

// serviceStates: status van de bewaakte services, in de woorden die RzSense van Linux kent (active, inactive …)
func serviceStates(names []string) map[string]string {
	out := map[string]string{}
	if len(names) == 0 {
		return out
	}
	m, err := mgr.Connect()
	if err != nil {
		return out
	}
	defer m.Disconnect()
	for _, n := range names {
		s, err := m.OpenService(n)
		if err != nil {
			out[n] = "not-found"
			continue
		}
		st, err := s.Query()
		s.Close()
		if err != nil {
			out[n] = "unknown"
			continue
		}
		out[n] = stateWord(st.State)
	}
	return out
}

func stateWord(s svc.State) string {
	switch s {
	case svc.Running:
		return "active"
	case svc.StartPending, svc.ContinuePending:
		return "activating"
	case svc.StopPending, svc.PausePending:
		return "deactivating"
	case svc.Paused:
		return "inactive"
	default:
		return "inactive"
	}
}

// serviceList: [naam, status, automatisch starten] van services die automatisch starten of draaien (zoals Linux)
func serviceList() [][]any {
	out := [][]any{}
	m, err := mgr.Connect()
	if err != nil {
		return out
	}
	defer m.Disconnect()
	names, err := m.ListServices()
	if err != nil {
		return out
	}
	for _, n := range names {
		if !svcNameRe.MatchString(n) {
			continue
		}
		s, err := m.OpenService(n)
		if err != nil {
			continue
		}
		st, err1 := s.Query()
		cfg, err2 := s.Config()
		s.Close()
		if err1 != nil || err2 != nil {
			continue
		}
		auto := cfg.StartType == mgr.StartAutomatic
		if auto || st.State == svc.Running {
			out = append(out, []any{n, stateWord(st.State), boolInt(auto)})
		}
		if len(out) >= 1000 {
			break
		}
	}
	return out
}

func boolInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

// ---------------------------------------------------------------- processen

type proc struct {
	pid  uint32
	name string
}

func processes() []proc {
	var out []proc
	snap, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return out
	}
	defer windows.CloseHandle(snap)
	var e windows.ProcessEntry32
	e.Size = uint32(unsafe.Sizeof(e))
	for err = windows.Process32First(snap, &e); err == nil; err = windows.Process32Next(snap, &e) {
		out = append(out, proc{pid: e.ProcessID, name: windows.UTF16ToString(e.ExeFile[:])})
	}
	return out
}

// processInfo: de lijst om uit te kiezen ([naam, aantal, gebruiker, opdracht]) en de metingen per proces-check
func processInfo(want []procWant) ([][]any, map[string]any) {
	ps := processes()
	count := map[string]int{}
	for _, p := range ps {
		if p.pid > 4 && p.name != "" {
			count[p.name]++
		}
	}
	list := make([][]any, 0, len(count))
	for n, c := range count {
		list = append(list, []any{n, c, "", ""})
		if len(list) >= 300 {
			break
		}
	}
	checks := map[string]any{}
	for _, w := range want {
		var re *regexp.Regexp
		if w.Pattern {
			re, _ = regexp.Compile("(?i)" + w.Value)
		}
		n, pids := 0, []string{}
		for _, p := range ps {
			hit := false
			if re != nil {
				hit = re.MatchString(p.name)
			} else {
				hit = strings.EqualFold(p.name, w.Value) || strings.EqualFold(strings.TrimSuffix(p.name, ".exe"), w.Value)
			}
			if hit {
				n++
				if len(pids) < 20 {
					pids = append(pids, fmt.Sprint(p.pid))
				}
			}
		}
		checks[w.ID] = map[string]any{"n": n, "pids": strings.Join(pids, ","), "cpu": 0, "rss": 0}
	}
	return list, checks
}

// ---------------------------------------------------------------- poorten

const tcpTableOwnerPidListener = 3

// collectPorts: luisterende TCP-poorten met het proces erbij (IPv4 en IPv6)
func collectPorts() []map[string]any {
	names := map[uint32]string{}
	for _, p := range processes() {
		names[p.pid] = p.name
	}
	seen := map[string]bool{}
	var out []map[string]any
	for _, af := range []uint32{windows.AF_INET, windows.AF_INET6} {
		var size uint32
		procTcpTable.Call(0, uintptr(unsafe.Pointer(&size)), 0, uintptr(af), tcpTableOwnerPidListener, 0)
		if size == 0 {
			continue
		}
		buf := make([]byte, size+1024)
		size = uint32(len(buf))
		if r, _, _ := procTcpTable.Call(uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&size)), 0, uintptr(af), tcpTableOwnerPidListener, 0); r != 0 {
			continue
		}
		n := *(*uint32)(unsafe.Pointer(&buf[0]))
		rowSize := 24
		if af == windows.AF_INET6 {
			rowSize = 56
		}
		for i := 0; i < int(n) && 4+(i+1)*rowSize <= len(buf); i++ {
			row := buf[4+i*rowSize:]
			var addr string
			var port, pid uint32
			if af == windows.AF_INET {
				a := row[4:8]
				addr = fmt.Sprintf("%d.%d.%d.%d", a[0], a[1], a[2], a[3])
				port = *(*uint32)(unsafe.Pointer(&row[8]))
				pid = *(*uint32)(unsafe.Pointer(&row[20]))
			} else {
				a := row[0:16]
				addr = ipv6(a)
				port = *(*uint32)(unsafe.Pointer(&row[20]))
				pid = *(*uint32)(unsafe.Pointer(&row[52]))
			}
			p := int(port&0xff)<<8 | int(port>>8&0xff)
			key := fmt.Sprintf("%s|%d", addr, p)
			if p == 0 || seen[key] {
				continue
			}
			seen[key] = true
			out = append(out, map[string]any{"port": p, "addr": addr, "proc": strings.TrimSuffix(names[pid], ".exe")})
			if len(out) >= 500 {
				return out
			}
		}
	}
	return out
}

func ipv6(a []byte) string {
	allZero := true
	for _, b := range a {
		if b != 0 {
			allZero = false
			break
		}
	}
	if allZero {
		return "::"
	}
	parts := make([]string, 8)
	for i := 0; i < 8; i++ {
		parts[i] = fmt.Sprintf("%x", uint16(a[2*i])<<8|uint16(a[2*i+1]))
	}
	return strings.Join(parts, ":")
}
