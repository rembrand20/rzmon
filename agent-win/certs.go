package main

import (
	"crypto/sha1"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"net"
	"regexp"
	"strings"
	"time"
)

// Certificaten: wat RzSense wil weten (bron host:poort, gezien vanaf deze server, of een certificaat uit de Windows-
// certificaatopslag: cert:LocalMachine\My\<vingerafdruk>), in het formaat van de Linux-agent.

var storeSpecRe = regexp.MustCompile(`^cert:LocalMachine\\(My|WebHosting|Remote Desktop)\\([0-9A-F]{40})$`)

func thumb(c *x509.Certificate) string {
	s := sha1.Sum(c.Raw)
	return strings.ToUpper(hex.EncodeToString(s[:]))
}

func certInfo(source string, chain []*x509.Certificate, verify string) map[string]any {
	leaf := chain[0]
	out := map[string]any{"source": source, "cn": leaf.Subject.CommonName, "issuer": leaf.Issuer.CommonName, "not_after": leaf.NotAfter.Unix(), "chain_n": len(chain)}
	if verify != "" {
		out["verify"] = verify
	}
	if len(chain) > 1 {
		min := chain[1]
		for _, c := range chain[1:] {
			if c.NotAfter.Before(min.NotAfter) {
				min = c
			}
		}
		out["chain_min"], out["chain_cn"] = min.NotAfter.Unix(), min.Subject.CommonName
	}
	return out
}

// collectCerts: per bron uit de testlijst
func collectCerts(sources []string) []map[string]any {
	out := []map[string]any{}
	for _, src := range sources {
		if m := storeSpecRe.FindStringSubmatch(src); m != nil {
			c := storeCert(m[1], m[2])
			if c == nil {
				out = append(out, map[string]any{"source": src, "error": "niet (meer) in de certificaatopslag"})
				continue
			}
			out = append(out, certInfo(src, []*x509.Certificate{c}, ""))
			continue
		}
		host, port, err := net.SplitHostPort(src)
		if err != nil {
			out = append(out, map[string]any{"source": src, "error": "bron niet te lezen op Windows"})
			continue
		}
		d := &net.Dialer{Timeout: 8 * time.Second}
		conn, err := tls.DialWithDialer(d, "tcp", net.JoinHostPort(host, port), &tls.Config{InsecureSkipVerify: true, ServerName: host}) // we lezen alleen het certificaat; geldig of niet zegt "verify"
		if err != nil {
			out = append(out, map[string]any{"source": src, "error": err.Error()})
			continue
		}
		chain := conn.ConnectionState().PeerCertificates
		conn.Close()
		if len(chain) == 0 {
			out = append(out, map[string]any{"source": src, "error": "geen certificaat"})
			continue
		}
		verify := "ok"
		inter := x509.NewCertPool()
		for _, c := range chain[1:] {
			inter.AddCert(c)
		}
		if _, err := chain[0].Verify(x509.VerifyOptions{DNSName: host, Intermediates: inter}); err != nil {
			verify = oneLine(err.Error())
		}
		out = append(out, certInfo(src, chain, verify))
		if len(out) >= 100 {
			break
		}
	}
	return out
}

// certFound: certificaten in de opslag van de computer (Persoonlijk, Webhosting, Extern bureaublad), om uit te kiezen
func certFound() []map[string]any {
	out := []map[string]any{}
	for _, store := range []string{"My", "WebHosting", "Remote Desktop"} {
		for _, c := range storeCerts(store) {
			if c.IsCA {
				continue
			}
			out = append(out, map[string]any{"path": `cert:LocalMachine\` + store + `\` + thumb(c), "cn": c.Subject.CommonName, "issuer": c.Issuer.CommonName, "not_after": c.NotAfter.Unix(), "n": 1})
			if len(out) >= 300 {
				return out
			}
		}
	}
	return out
}

func storeCert(store, tp string) *x509.Certificate {
	for _, c := range storeCerts(store) {
		if thumb(c) == tp {
			return c
		}
	}
	return nil
}
