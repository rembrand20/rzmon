//go:build windows

package main

import (
	"context"
	"os/exec"
	"syscall"
	"time"
)

// wifiInfo: de eigen wifi-verbinding (netsh), of nil zonder wifi
func wifiInfo() map[string]any {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "netsh", "wlan", "show", "interfaces")
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.Output()
	if err != nil {
		return nil
	}
	return parseNetshWlan(string(out))
}
