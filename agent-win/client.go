package main

import (
	"bytes"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// Met RzSense praten. Heeft RzSense een eigen (self-signed) certificaat, dan zetten we de vingerafdruk van de publieke
// sleutel vast (PIN = sha256//base64, zoals curl --pinnedpubkey); anders controleert Windows het certificaat gewoon.

const maxBody = 32 << 20

func httpClient(pin string, timeout time.Duration) *http.Client {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.Proxy = http.ProxyFromEnvironment
	if want, ok := strings.CutPrefix(pin, "sha256//"); ok && want != "" {
		tr.TLSClientConfig = &tls.Config{
			InsecureSkipVerify: true, // vervangen door de controle hieronder: alleen dit ene certificaat
			VerifyPeerCertificate: func(raw [][]byte, _ [][]*x509.Certificate) error {
				for _, c := range raw {
					cert, err := x509.ParseCertificate(c)
					if err != nil {
						continue
					}
					sum := sha256.Sum256(cert.RawSubjectPublicKeyInfo)
					if base64.StdEncoding.EncodeToString(sum[:]) == want {
						return nil
					}
				}
				return errPin
			},
			MinVersion: tls.VersionTLS12,
		}
	} else {
		tr.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	}
	return &http.Client{Transport: tr, Timeout: timeout}
}

var errPin = errors.New("certificaat van RzSense past niet bij de vastgezette vingerafdruk (PIN)")

type resp struct {
	Code int
	Body []byte
}

// call: één verzoek naar base/api.php?action=…
func call(base, pin, method, action string, body []byte, hdr map[string]string, timeout time.Duration) (*resp, error) {
	url := strings.TrimRight(base, "/") + "/api.php?action=" + action
	var rd io.Reader
	if body != nil {
		rd = bytes.NewReader(body)
	}
	req, err := http.NewRequest(method, url, rd)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", "rzsense-agent-windows/"+Version)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	r, err := httpClient(pin, timeout).Do(req)
	if err != nil {
		return nil, err
	}
	defer r.Body.Close()
	b, err := io.ReadAll(io.LimitReader(r.Body, maxBody))
	if err != nil {
		return nil, err
	}
	return &resp{Code: r.StatusCode, Body: b}, nil
}

// unreachable: geen verbinding of de proxy ervoor geeft 502-504: dan het reserve-adres proberen (zoals de Linux-agent)
func unreachable(r *resp, err error) bool {
	if err != nil {
		return true
	}
	return r.Code == 502 || r.Code == 503 || r.Code == 504
}

// sealedCall: versleuteld verzoek met onze sleutel; het antwoord moet met dezelfde sleutel ondertekend zijn.
// Lukt het gewone adres niet, dan via het reserve-adres (opnieuw versleuteld, met een nieuwe nonce).
func sealedCall(c *Config, action string, plain []byte, hdr map[string]string, timeout time.Duration) ([]byte, int, error) {
	try := func(base, pin string, extra map[string]string) ([]byte, int, error, bool) {
		env, nonce, err := seal(plain, c.Key, time.Now())
		if err != nil {
			return nil, 0, err, false
		}
		h := map[string]string{"X-Agent-Key": keyID(c.Key)}
		for k, v := range hdr {
			h[k] = v
		}
		for k, v := range extra {
			h[k] = v
		}
		r, err := call(base, pin, "POST", action, env, h, timeout)
		if unreachable(r, err) {
			if err == nil {
				err = fmt.Errorf("HTTP %d", r.Code)
			}
			return nil, 0, err, true
		}
		if !isSealed(r.Body) {
			return r.Body, r.Code, fmt.Errorf("HTTP %d: %s", r.Code, shortErr(r.Body)), false
		}
		out, err := openReply(r.Body, c.Key, nonce, time.Now())
		return out, r.Code, err, false
	}
	out, code, err, down := try(c.URL, c.Pin, nil)
	if down && c.URLFallback != "" {
		logf("RzSense niet bereikbaar via %s (%v); via het reserve-adres %s", c.URL, err, c.URLFallback)
		out, code, err, _ = try(c.URLFallback, c.PinFallback, map[string]string{"X-Agent-Fallback": "1"})
	}
	return out, code, err
}

// signedGet: GET met X-Agent-Auth; het antwoord komt versleuteld terug (bv. de lijst met tests)
func signedGet(c *Config, action string, timeout time.Duration) ([]byte, error) {
	auth, nonce, err := getAuth(c.Key, action, time.Now())
	if err != nil {
		return nil, err
	}
	r, err := call(c.URL, c.Pin, "GET", action, nil, map[string]string{"X-Agent-Auth": auth}, timeout)
	if err != nil {
		return nil, err
	}
	if r.Code != 200 {
		return nil, fmt.Errorf("HTTP %d: %s", r.Code, shortErr(r.Body))
	}
	if !isSealed(r.Body) {
		return nil, errors.New("antwoord niet versleuteld: niet te vertrouwen")
	}
	return openReply(r.Body, c.Key, nonce, time.Now())
}

func shortErr(b []byte) string {
	s := strings.TrimSpace(string(b))
	if i := strings.Index(s, `"error":"`); i >= 0 {
		s = s[i+9:]
		if j := strings.Index(s, `"`); j >= 0 {
			s = s[:j]
		}
	}
	if len(s) > 200 {
		s = s[:200]
	}
	return s
}
