//go:build linux

package main

// Certificaten op Linux (0.10.0), met dezelfde velden als de bash-agent (die openssl gebruikt):
//   - een bestand (PEM, ook met jokertekens): onderwerp, uitgever, vervaldatum en de hele keten in het bestand;
//   - host:poort: het certificaat dat de server laat zien, of het vertrouwd wordt, en de keten die hij meestuurt.
// De bronnen komen uit CERTS in de instellingen van de bash-agent en uit zijn testlijst (regels "0 <tab> cert <tab> bron").
// De uitkomst wordt een uur bewaard (CERT_INTERVAL), net als bij de bash-agent: niet elke minuut een verbinding.

import (
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"hash/crc32"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// certIssuerLinux: de organisatie van de uitgever, en anders zijn naam (zoals de bash-agent)
func certIssuerLinux(n pkix.Name) string {
	if len(n.Organization) > 0 && n.Organization[0] != "" {
		return n.Organization[0]
	}
	return n.CommonName
}

// certLineLinux: één regel in het formaat van de bash-agent (alle velden altijd aanwezig)
func certLineLinux(source string, chain []*x509.Certificate, verify any, errText string) map[string]any {
	out := map[string]any{"source": source, "cn": "", "issuer": "", "not_after": nil, "verify": verify, "error": errText, "chain_n": 0, "chain_min": nil, "chain_cn": ""}
	if len(chain) == 0 {
		return out
	}
	leaf := chain[0]
	out["cn"], out["issuer"], out["not_after"] = leaf.Subject.CommonName, certIssuerLinux(leaf.Issuer), leaf.NotAfter.Unix()
	// De hele keten, met het certificaat zelf: hoeveel, en welk het eerst verloopt
	first := chain[0]
	for _, c := range chain {
		if c.NotAfter.Before(first.NotAfter) {
			first = c
		}
	}
	out["chain_n"], out["chain_min"], out["chain_cn"] = len(chain), first.NotAfter.Unix(), first.Subject.CommonName
	return out
}

// pemCerts: alle certificaten in een PEM-tekst (hooguit 30), in volgorde
func pemCerts(data []byte) []*x509.Certificate {
	var out []*x509.Certificate
	for len(out) < 30 {
		var b *pem.Block
		b, data = pem.Decode(data)
		if b == nil {
			break
		}
		if b.Type != "CERTIFICATE" {
			continue
		}
		if c, err := x509.ParseCertificate(b.Bytes); err == nil {
			out = append(out, c)
		}
	}
	return out
}

// certHostPort: "host:poort" uit een bron (zonder schema en pad; zonder poort 443; [IPv6]:poort mag)
func certHostPort(entry string) (host, port string) {
	if i := strings.Index(entry, "://"); i >= 0 {
		entry = entry[i+3:]
	}
	entry, _, _ = strings.Cut(entry, "/")
	switch {
	case strings.HasPrefix(entry, "[") && strings.Contains(entry, "]:"):
		i := strings.LastIndex(entry, "]:")
		return entry[1:i], entry[i+2:]
	case strings.Count(entry, ":") == 1:
		h, p, _ := strings.Cut(entry, ":")
		return h, p
	}
	return strings.TrimSuffix(strings.TrimPrefix(entry, "["), "]"), "443"
}

// tlsErrText: waarom er geen TLS-verbinding kwam, in dezelfde woorden als de bash-agent
func tlsErrText(host string, err error) string {
	s := err.Error()
	var dnsErr *net.DNSError
	switch {
	case errors.As(err, &dnsErr):
		return "naam " + host + " onbekend (DNS)"
	case strings.Contains(s, "connection refused"):
		return "poort dicht (verbinding geweigerd)"
	case strings.Contains(s, "no route") || strings.Contains(s, "unreachable"):
		return "server onbereikbaar"
	case strings.Contains(s, "first record does not look like a TLS handshake") || strings.Contains(s, "oversized record"):
		return "op deze poort draait geen TLS"
	case strings.Contains(s, "timeout") || strings.Contains(s, "deadline"):
		return "geen antwoord binnen 15 seconden"
	}
	if len(s) > 120 {
		s = s[:120]
	}
	return s
}

func certFromHost(entry string) map[string]any {
	host, port := certHostPort(entry)
	src := host + ":" + port
	if strings.Contains(host, ":") {
		src = "[" + host + "]:" + port
	}
	d := &net.Dialer{Timeout: 15 * time.Second}
	// We lezen alleen het certificaat; of het vertrouwd wordt, rekenen we hieronder zelf uit en melden we in "verify"
	conn, err := tls.DialWithDialer(d, "tcp", net.JoinHostPort(host, port), &tls.Config{InsecureSkipVerify: true, ServerName: host})
	if err != nil {
		return certLineLinux(src, nil, nil, "geen TLS-verbinding: "+tlsErrText(host, err))
	}
	chain := conn.ConnectionState().PeerCertificates
	conn.Close()
	if len(chain) == 0 {
		return certLineLinux(src, nil, nil, "geen TLS-verbinding: geen certificaat")
	}
	verify := "ok"
	inter := x509.NewCertPool()
	for _, c := range chain[1:] {
		inter.AddCert(c)
	}
	if _, err := chain[0].Verify(x509.VerifyOptions{DNSName: host, Intermediates: inter}); err != nil {
		verify = oneLine(err.Error())
	}
	return certLineLinux(src, chain, verify, "")
}

func certFromFile(path string) map[string]any {
	data, err := os.ReadFile(path)
	if err != nil {
		return certLineLinux(path, nil, nil, "bestand niet gevonden of niet leesbaar")
	}
	chain := pemCerts(data)
	if len(chain) == 0 {
		return certLineLinux(path, nil, nil, "geen geldig certificaat (PEM) in dit bestand")
	}
	return certLineLinux(path, chain, nil, "")
}

// wantedCerts: CERTS uit de instellingen, plus de bronnen uit de testlijst; in die volgorde, hooguit 200 uit de lijst
func wantedCerts(conf map[string]string, testsCache string) []string {
	out := strings.Fields(conf["CERTS"])
	n := 0
	for _, l := range strings.Split(testsCache, "\n") {
		f := strings.Split(l, "\t")
		if len(f) >= 3 && f[0] == "0" && f[1] == "cert" && f[2] != "" && !strings.ContainsAny(f[2], " \t") {
			out = append(out, f[2])
			if n++; n >= 200 {
				break
			}
		}
	}
	return out
}

// collectCertsNow: alle bronnen uitlezen (bestanden en jokertekens, of host:poort)
func collectCertsNow(sources []string) []map[string]any {
	out := []map[string]any{}
	for _, entry := range sources {
		if strings.HasPrefix(entry, "/") {
			files := []string{entry}
			if strings.ContainsAny(entry, "*?[") {
				files, _ = filepath.Glob(entry) // een jokerteken zonder treffers is geen fout
			}
			for _, f := range files {
				out = append(out, certFromFile(f))
			}
			continue
		}
		out = append(out, certFromHost(entry))
	}
	return out
}

// collectCertsLinux: de certificaten uit de lijst, hooguit eens per CERT_INTERVAL opnieuw (in de eigen map van de Go-agent)
func collectCertsLinux() []map[string]any {
	conf, _ := readShellConf(bashConfPath())
	if conf == nil {
		conf = map[string]string{}
	}
	sources := wantedCerts(conf, readText(filepath.Join(bashCachePath(), "tests.cache")))
	if len(sources) == 0 {
		return []map[string]any{}
	}
	interval := int64(atoiDef(conf["CERT_INTERVAL"], 3600))
	key := strconv.FormatUint(uint64(crc32.ChecksumIEEE([]byte(strings.Join(sources, " ")))), 10)
	cachePath := filepath.Join(dataDir(), "certs.cache")
	if raw, err := os.ReadFile(cachePath); err == nil {
		var c struct {
			Key   string           `json:"key"`
			At    int64            `json:"at"`
			Certs []map[string]any `json:"certs"`
		}
		if json.Unmarshal(raw, &c) == nil && c.Key == key && time.Now().Unix()-c.At < interval && c.Certs != nil {
			return c.Certs
		}
	}
	certs := collectCertsNow(sources)
	if b, err := json.Marshal(map[string]any{"key": key, "at": time.Now().Unix(), "certs": certs}); err == nil {
		_ = os.WriteFile(cachePath, b, 0o600)
	}
	return certs
}
