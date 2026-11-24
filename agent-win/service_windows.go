//go:build windows

package main

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/eventlog"
	"golang.org/x/sys/windows/svc/mgr"
)

const (
	serviceName = "RzSenseAgent"
	serviceDesc = "Stuurt metingen van deze server naar RzSense (monitoring, logs en beveiliging)."
	installDir  = `C:\Program Files\RzSense`
)

// ---------------------------------------------------------------- sleutel beschermen (DPAPI) en rechten

func protect(b []byte) ([]byte, error) {
	in := windows.DataBlob{Size: uint32(len(b)), Data: &b[0]}
	var out windows.DataBlob
	// Aan de computer gebonden: de service (SYSTEM) en een beheerder kunnen hem lezen; op een andere computer niet
	if err := windows.CryptProtectData(&in, nil, nil, 0, nil, windows.CRYPTPROTECT_LOCAL_MACHINE|windows.CRYPTPROTECT_UI_FORBIDDEN, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	return append([]byte(nil), unsafe.Slice(out.Data, out.Size)...), nil
}

func unprotect(b []byte) ([]byte, error) {
	if len(b) == 0 {
		return nil, errors.New("leeg")
	}
	in := windows.DataBlob{Size: uint32(len(b)), Data: &b[0]}
	var out windows.DataBlob
	if err := windows.CryptUnprotectData(&in, nil, nil, 0, nil, windows.CRYPTPROTECT_UI_FORBIDDEN, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	return append([]byte(nil), unsafe.Slice(out.Data, out.Size)...), nil
}

// lockDown: alleen SYSTEM en Administrators mogen bij het bestand of de map (geen overerving)
func lockDown(path string) error {
	sd, err := windows.SecurityDescriptorFromString("D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)")
	if err != nil {
		return err
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return err
	}
	return windows.SetNamedSecurityInfo(path, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, dacl, nil)
}

// ---------------------------------------------------------------- logboek

var (
	logMu   sync.Mutex
	evlog   *eventlog.Log
	console bool
)

// logf: naar de Windows Event Log (Toepassing, bron RzSenseAgent) en naar agent.log in de datamap (hooguit 1 MB)
func logf(format string, a ...any) {
	msg := fmt.Sprintf(format, a...)
	logMu.Lock()
	defer logMu.Unlock()
	if console {
		fmt.Println(msg)
	}
	if evlog != nil {
		_ = evlog.Info(1, msg)
	}
	p := filepath.Join(dataDir(), "agent.log")
	if st, err := os.Stat(p); err == nil && st.Size() > 1<<20 {
		_ = os.Rename(p, p+".1")
	}
	if f, err := os.OpenFile(p, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600); err == nil {
		fmt.Fprintf(f, "%s %s\r\n", time.Now().Format("2006-01-02 15:04:05"), msg)
		f.Close()
	}
}

// ---------------------------------------------------------------- de service

type agentService struct{ restart chan struct{} }

var updateRestart = make(chan struct{}, 1)

// restartForUpdate: de service stopt met een foutcode; Windows start hem opnieuw (herstelopties) met de nieuwe .exe
func restartForUpdate() {
	select {
	case updateRestart <- struct{}{}:
	default:
	}
}

func (a *agentService) Execute(_ []string, req <-chan svc.ChangeRequest, st chan<- svc.Status) (bool, uint32) {
	st <- svc.Status{State: svc.StartPending}
	st <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
	logf("RzSense-agent %s gestart", Version)
	next := time.NewTimer(5 * time.Second)
	done := make(chan int, 1)
	busy := false
	force := true
	for {
		select {
		case <-next.C:
			if busy {
				next.Reset(30 * time.Second)
				continue
			}
			busy = true
			go func(f bool) {
				defer func() {
					if r := recover(); r != nil {
						logf("Fout in de ronde: %v", r)
						done <- 60
					}
				}()
				c, err := loadConfig()
				if err != nil || c.URL == "" || c.Key == "" {
					logf("Niet ingesteld of niet gekoppeld (%s): rzsense-agent.exe install --url … --pair …", configPath())
					done <- 300
					return
				}
				tick, err := round(c, f)
				if err != nil {
					logf("%v", err)
				}
				done <- tick
			}(force)
			force = false
		case tick := <-done:
			busy = false
			next.Reset(time.Duration(tick) * time.Second)
		case <-updateRestart:
			st <- svc.Status{State: svc.StopPending}
			return true, 2 // met een fout stoppen: Windows start de service opnieuw, nu met de nieuwe .exe
		case c := <-req:
			switch c.Cmd {
			case svc.Interrogate:
				st <- c.CurrentStatus
			case svc.Stop, svc.Shutdown:
				st <- svc.Status{State: svc.StopPending}
				logf("RzSense-agent gestopt")
				return false, 0
			}
		}
	}
}

func runService() error {
	if l, err := eventlog.Open(serviceName); err == nil {
		evlog = l
		defer l.Close()
	}
	return svc.Run(serviceName, &agentService{})
}

func isService() bool {
	ok, err := svc.IsWindowsService()
	return err == nil && ok
}

// ---------------------------------------------------------------- installeren en verwijderen

func copyFile(src, dst string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.Create(dst + ".tmp")
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	out.Close()
	return os.Rename(dst+".tmp", dst)
}

// install: .exe naar Program Files, koppelen, service aanmaken met herstelopties, starten
func install(c *Config, code string) error {
	self, err := os.Executable()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(installDir, 0o755); err != nil {
		return err
	}
	target := filepath.Join(installDir, "rzsense-agent.exe")
	m, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("Service Control Manager (als beheerder starten?): %w", err)
	}
	defer m.Disconnect()
	// Bestaat de service al (opnieuw installeren of bijwerken met de hand): eerst stoppen
	if s, err := m.OpenService(serviceName); err == nil {
		_, _ = s.Control(svc.Stop)
		waitStopped(s)
		s.Close()
	}
	if !strings.EqualFold(filepath.Clean(self), filepath.Clean(target)) {
		if err := copyFile(self, target); err != nil {
			return fmt.Errorf("naar %s kopiëren: %w", target, err)
		}
	}
	if err := os.MkdirAll(dataDir(), 0o700); err != nil {
		return err
	}
	_ = lockDown(dataDir())
	if code != "" {
		host, err := pair(c, code)
		if err != nil {
			return err
		}
		fmt.Printf("🔑 Gekoppeld met RzSense (server: %s): eigen sleutel ontvangen\n", host)
	} else if err := c.save(); err != nil {
		return err
	}
	_ = eventlog.InstallAsEventCreate(serviceName, eventlog.Error|eventlog.Warning|eventlog.Info)
	s, err := m.OpenService(serviceName)
	if err != nil {
		s, err = m.CreateService(serviceName, target, mgr.Config{
			DisplayName: "RzSense Agent", Description: serviceDesc, StartType: mgr.StartAutomatic, DelayedAutoStart: true,
		}, "run")
		if err != nil {
			return fmt.Errorf("service aanmaken: %w", err)
		}
	}
	defer s.Close()
	// Herstarten bij een fout (ook bij een bewuste stop met een foutcode, zo werkt bijwerken): na 30 s, 60 s, 120 s
	_ = s.SetRecoveryActions([]mgr.RecoveryAction{
		{Type: mgr.ServiceRestart, Delay: 30 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 60 * time.Second},
		{Type: mgr.ServiceRestart, Delay: 120 * time.Second},
	}, 86400)
	_ = s.SetRecoveryActionsOnNonCrashFailures(true)
	if err := s.Start(); err != nil {
		return fmt.Errorf("service starten: %w", err)
	}
	return nil
}

func waitStopped(s *mgr.Service) {
	for i := 0; i < 30; i++ {
		st, err := s.Query()
		if err != nil || st.State == svc.Stopped {
			return
		}
		time.Sleep(time.Second)
	}
}

// uninstall: service stoppen en verwijderen, de firewallregels van RzSense weg; de datamap alleen met --purge
func uninstall(purge bool) error {
	m, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer m.Disconnect()
	if s, err := m.OpenService(serviceName); err == nil {
		_, _ = s.Control(svc.Stop)
		waitStopped(s)
		if err := s.Delete(); err != nil {
			s.Close()
			return err
		}
		s.Close()
	}
	_ = eventlog.Remove(serviceName)
	_ = exec.Command("netsh", "advfirewall", "firewall", "delete", "rule", "name=all", "group=RzSense").Run()
	if purge {
		_ = os.RemoveAll(dataDir())
	}
	// De .exe zelf kan niet weg terwijl hij draait: Windows ruimt hem op bij de volgende herstart
	self, _ := os.Executable()
	if p, err := windows.UTF16PtrFromString(filepath.Join(installDir, "rzsense-agent.exe")); err == nil && !strings.EqualFold(self, filepath.Join(installDir, "rzsense-agent.exe")) {
		_ = windows.DeleteFile(p)
	} else if err == nil {
		_ = windows.MoveFileEx(p, nil, windows.MOVEFILE_DELAY_UNTIL_REBOOT)
	}
	return nil
}
