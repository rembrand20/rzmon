// Versleuteling, precies zoals de Linux-agent en RzSense (src/AgentCrypto.php) het doen:
//   - afgeleide sleutels: HMAC-SHA256(label, sleutel) voor "id", "enc" en "mac";
//   - een bericht: {"rzenc":1,"t":…,"n":…,"iv":…,"d":…,"m":…} met AES-256-CBC en een HMAC over "rzenc1|t|n|iv|d";
//   - het antwoord: {"rzenc":1,"t":…,"rn":…,"iv":…,"d":…,"m":…}, gebonden aan onze nonce ("rzenc1r|t|rn|iv|d");
//   - een GET-verzoek: X-Agent-Auth = kenmerk:tijd:nonce:HMAC("rzget1|actie|tijd|nonce");
//   - een koppelcode: SHA-256 over "rzpair1|CODE" (hoofdletters, alleen letters en cijfers).
package main

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"
)

// maxAge: hoe ver de klok van een antwoord van de onze mag afwijken (zoals RzSense, AgentCrypto::MAX_AGE)
const maxAge = 300 * time.Second

func hexKey(s string) ([]byte, error) {
	if len(s) != 64 {
		return nil, errors.New("sleutel heeft niet de juiste lengte")
	}
	return hex.DecodeString(s)
}

// sub: afgeleide sleutel (hex), zoals AgentCrypto::sub
func sub(keyHex, label string) (string, error) {
	k, err := hexKey(keyHex)
	if err != nil {
		return "", err
	}
	m := hmac.New(sha256.New, k)
	m.Write([]byte(label))
	return hex.EncodeToString(m.Sum(nil)), nil
}

// keyID: kort kenmerk van een sleutel (geen geheim)
func keyID(keyHex string) string {
	s, err := sub(keyHex, "id")
	if err != nil {
		return ""
	}
	return s[:16]
}

// pairKey: sleutel bij een koppelcode
func pairKey(code string) string {
	var b strings.Builder
	for _, r := range strings.ToUpper(code) {
		if (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') {
			b.WriteRune(r)
		}
	}
	h := sha256.Sum256([]byte("rzpair1|" + b.String()))
	return hex.EncodeToString(h[:])
}

func hmacHex(keyHex, msg string) (string, error) {
	k, err := hex.DecodeString(keyHex)
	if err != nil {
		return "", err
	}
	m := hmac.New(sha256.New, k)
	m.Write([]byte(msg))
	return hex.EncodeToString(m.Sum(nil)), nil
}

func randHex(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err) // zonder toeval geen veilige versleuteling: liever stoppen
	}
	return hex.EncodeToString(b)
}

func pkcs7Pad(b []byte) []byte {
	p := aes.BlockSize - len(b)%aes.BlockSize
	return append(b, bytes.Repeat([]byte{byte(p)}, p)...)
}

func pkcs7Unpad(b []byte) ([]byte, error) {
	if len(b) == 0 || len(b)%aes.BlockSize != 0 {
		return nil, errors.New("verkeerde lengte")
	}
	p := int(b[len(b)-1])
	if p == 0 || p > aes.BlockSize || p > len(b) {
		return nil, errors.New("verkeerde opvulling")
	}
	for _, c := range b[len(b)-p:] {
		if int(c) != p {
			return nil, errors.New("verkeerde opvulling")
		}
	}
	return b[:len(b)-p], nil
}

// seal: bericht versleutelen met keyHex; geeft het bericht en de nonce (om het antwoord te controleren)
func seal(plain []byte, keyHex string, now time.Time) ([]byte, string, error) {
	return sealWith(plain, keyHex, now.Unix(), randHex(16), randHex(16))
}

func sealWith(plain []byte, keyHex string, t int64, nonce, ivHex string) ([]byte, string, error) {
	ke, err := sub(keyHex, "enc")
	if err != nil {
		return nil, "", err
	}
	km, _ := sub(keyHex, "mac")
	encKey, _ := hex.DecodeString(ke)
	iv, err := hex.DecodeString(ivHex)
	if err != nil || len(iv) != aes.BlockSize {
		return nil, "", errors.New("ongeldige iv")
	}
	block, err := aes.NewCipher(encKey)
	if err != nil {
		return nil, "", err
	}
	data := pkcs7Pad(append([]byte(nil), plain...))
	cipher.NewCBCEncrypter(block, iv).CryptBlocks(data, data)
	d := base64.StdEncoding.EncodeToString(data)
	m, err := hmacHex(km, fmt.Sprintf("rzenc1|%d|%s|%s|%s", t, nonce, ivHex, d))
	if err != nil {
		return nil, "", err
	}
	// Zelfde volgorde als de Linux-agent: RzSense herkent een bericht aan het begin ({"rzenc":)
	out := fmt.Sprintf(`{"rzenc":1,"t":%d,"n":"%s","iv":"%s","d":"%s","m":"%s"}`, t, nonce, ivHex, d, m)
	return []byte(out), nonce, nil
}

type sealedReply struct {
	Rzenc int    `json:"rzenc"`
	T     int64  `json:"t"`
	RN    string `json:"rn"`
	IV    string `json:"iv"`
	D     string `json:"d"`
	M     string `json:"m"`
}

func isSealed(b []byte) bool {
	return bytes.HasPrefix(bytes.TrimLeft(b, " \t\r\n"), []byte(`{"rzenc":`))
}

// openReply: antwoord van RzSense controleren (handtekening, onze nonce, tijd) en uitpakken
func openReply(body []byte, keyHex, nonce string, now time.Time) ([]byte, error) {
	var r sealedReply
	if err := json.Unmarshal(body, &r); err != nil || r.Rzenc != 1 {
		return nil, errors.New("antwoord is geen versleuteld bericht")
	}
	if nonce == "" || r.RN != nonce {
		return nil, errors.New("antwoord hoort niet bij ons verzoek")
	}
	if d := now.Sub(time.Unix(r.T, 0)); d > maxAge || d < -maxAge {
		return nil, errors.New("antwoord is te oud of komt uit de toekomst (klopt de klok?)")
	}
	km, err := sub(keyHex, "mac")
	if err != nil {
		return nil, err
	}
	want, _ := hmacHex(km, fmt.Sprintf("rzenc1r|%d|%s|%s|%s", r.T, r.RN, r.IV, r.D))
	if !hmac.Equal([]byte(want), []byte(r.M)) {
		return nil, errors.New("handtekening van het antwoord klopt niet")
	}
	ke, _ := sub(keyHex, "enc")
	encKey, _ := hex.DecodeString(ke)
	iv, err := hex.DecodeString(r.IV)
	if err != nil || len(iv) != aes.BlockSize {
		return nil, errors.New("ongeldige iv")
	}
	data, err := base64.StdEncoding.DecodeString(r.D)
	if err != nil || len(data) == 0 || len(data)%aes.BlockSize != 0 {
		return nil, errors.New("antwoord is beschadigd")
	}
	block, err := aes.NewCipher(encKey)
	if err != nil {
		return nil, err
	}
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(data, data)
	return pkcs7Unpad(data)
}

// getAuth: kop X-Agent-Auth voor een GET-verzoek; geeft ook de nonce (voor het versleutelde antwoord)
func getAuth(keyHex, action string, now time.Time) (string, string, error) {
	km, err := sub(keyHex, "mac")
	if err != nil {
		return "", "", err
	}
	t := now.Unix()
	n := randHex(16)
	s, _ := hmacHex(km, fmt.Sprintf("rzget1|%s|%d|%s", action, t, n))
	return fmt.Sprintf("%s:%d:%s:%s", keyID(keyHex), t, n, s), n, nil
}
