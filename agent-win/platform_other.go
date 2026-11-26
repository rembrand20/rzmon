//go:build !windows

package main

// Alleen om de algemene code (versleuteling, testlijst, bijwerken) ook op Linux te kunnen testen en controleren.
// De echte agent is er alleen voor Windows.

import (
	"crypto/x509"
	"errors"
	"fmt"
)

var console = true

func protect(b []byte) ([]byte, error) { return append([]byte("test:"), b...), nil }
func unprotect(b []byte) ([]byte, error) {
	if len(b) < 5 || string(b[:5]) != "test:" {
		return nil, errors.New("geen testsleutel")
	}
	return b[5:], nil
}
func lockDown(string) error        { return nil }
func logf(format string, a ...any) { fmt.Printf(format+"\n", a...) }
func restartForUpdate()            {}
func collectBasics() map[string]any {
	return map[string]any{"hostname": "test", "os_info": map[string]any{}}
}
func collectPorts() []map[string]any           { return nil }
func wifiInfo() map[string]any                 { return nil }
func serviceStates([]string) map[string]string { return map[string]string{} }
func serviceList() [][]any                     { return [][]any{} }
func processInfo([]procWant) ([][]any, map[string]any) {
	return [][]any{}, map[string]any{}
}

type noEvents struct{}

func (noEvents) read(string, string, uint64, int, bool) ([]winEvent, uint64, error) {
	return nil, 0, nil
}
func (noEvents) close()                                   {}
func newEventReader() eventReader                         { return noEvents{} }
func channelInfo(string) (uint64, bool)                   { return 0, false }
func storeCerts(string) []*x509.Certificate               { return nil }
func windowsUpdate() map[string]any                       { return nil }
func collectIDS() []map[string]any                        { return nil }
func syncBlocks([]blockWant, []string) map[string]any     { return map[string]any{"state": "ok"} }
func applyIsolation(*Config, *isolateWant) map[string]any { return nil }
func defenderStatus() map[string]any                      { return nil }
func installSysmon(string) error                          { return errors.New("alleen op Windows") }
func enableAudit() error                                  { return errors.New("alleen op Windows") }
func runService() error                                   { return errors.New("alleen op Windows") }
func isService() bool                                     { return false }
func install(*Config, string) error                       { return errors.New("alleen op Windows") }
func uninstall(bool) error                                { return errors.New("alleen op Windows") }
func installWindowsUpdates(c *Config, id, mode string)    {}
