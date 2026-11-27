//go:build !linux

package main

// cliLinux: het commando "rzsense" bestaat alleen op Linux; elders doet main() het werk
func cliLinux([]string) bool { return false }
