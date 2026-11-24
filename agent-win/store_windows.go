//go:build windows

package main

import (
	"crypto/x509"
	"unsafe"

	"golang.org/x/sys/windows"
)

// storeCerts: certificaten uit een opslag van de computer (LocalMachine), alleen lezen
func storeCerts(name string) []*x509.Certificate {
	p, _ := windows.UTF16PtrFromString(name)
	st, err := windows.CertOpenStore(windows.CERT_STORE_PROV_SYSTEM, 0, 0,
		windows.CERT_SYSTEM_STORE_LOCAL_MACHINE|windows.CERT_STORE_READONLY_FLAG|windows.CERT_STORE_OPEN_EXISTING_FLAG, uintptr(unsafe.Pointer(p)))
	if err != nil {
		return nil
	}
	defer windows.CertCloseStore(st, 0)
	var out []*x509.Certificate
	var ctx *windows.CertContext
	for {
		ctx, err = windows.CertEnumCertificatesInStore(st, ctx)
		if err != nil || ctx == nil {
			break
		}
		raw := append([]byte(nil), unsafe.Slice(ctx.EncodedCert, ctx.Length)...)
		if c, err := x509.ParseCertificate(raw); err == nil {
			out = append(out, c)
		}
	}
	return out
}

func newEventReader() eventReader          { return newEvtReader() }
func channelInfo(ch string) (uint64, bool) { return channelRecords(ch) }
