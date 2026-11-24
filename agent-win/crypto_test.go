package main

import (
	"strings"
	"testing"
	"time"
)

// Testwaarden gemaakt met de echte PHP-code van RzSense (src/AgentCrypto.php): zo past de Windows-agent gegarandeerd.
const (
	tKey    = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
	tKeyID  = "9af342b3e5c45b85"
	tPair   = "ca873d3eefbdf010865924975c70ccd5495c160a31374e392e32f42e2d573ec2"
	tNonce  = "abababababababababababababababab"
	tEnv    = `{"rzenc":1,"t":1795000000,"n":"abababababababababababababababab","iv":"cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd","d":"h22n6/99KEd9fFUIyU8RMr17/w35DyuV3hBmK1gGj/YQSkm37j649xxB7vn0xsu8","m":"0ec8f3dcfec12d5facd7e1e0c1ba3b8550c2f95719a3fb0d5b96356c64e473e8"}`
	tReply  = `{"rzenc":1,"t":1795503254,"rn":"abababababababababababababababab","iv":"58c2451167a84960b9345527ec11d5a2","d":"JokkFNokucB35CUBgg0nE/6eGW+7HRCTwu1M6H72Py0=","m":"861ab6868883876f125933e0b7d9863dcedf88ef577ec0cc63de732c522f5f98"}`
	tGetSig = "474f7df37554ff9994c40c428f44f4fadbd6f76774de10c10dd09a527c104fd0"
)

func TestKeyIDAndPair(t *testing.T) {
	if got := keyID(tKey); got != tKeyID {
		t.Fatalf("keyID = %s, verwacht %s", got, tKeyID)
	}
	if got := pairKey("k7qf-m2xa 9rtd"); got != tPair {
		t.Fatalf("pairKey = %s, verwacht %s", got, tPair)
	}
}

func TestSealMatchesPHP(t *testing.T) {
	got, n, err := sealWith([]byte(`{"agent_version":"0.1.0","hostname":"WIN-SRV"}`), tKey, 1795000000, tNonce, strings.Repeat("cd", 16))
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != tEnv || n != tNonce {
		t.Fatalf("bericht wijkt af van PHP:\n%s\n%s", got, tEnv)
	}
}

func TestOpenReplyFromPHP(t *testing.T) {
	plain, err := openReply([]byte(tReply), tKey, tNonce, time.Unix(1795503254, 0))
	if err != nil {
		t.Fatal(err)
	}
	if string(plain) != `{"ok":true,"host":"WIN-SRV"}` {
		t.Fatalf("uitgepakt: %s", plain)
	}
	// Andere nonce, andere sleutel, te oud, of aangepast: weigeren
	if _, err := openReply([]byte(tReply), tKey, strings.Repeat("00", 16), time.Unix(1795503254, 0)); err == nil {
		t.Fatal("antwoord op een ander verzoek geaccepteerd")
	}
	if _, err := openReply([]byte(tReply), strings.Repeat("1", 64), tNonce, time.Unix(1795503254, 0)); err == nil {
		t.Fatal("antwoord met een andere sleutel geaccepteerd")
	}
	if _, err := openReply([]byte(tReply), tKey, tNonce, time.Unix(1795503254+3600, 0)); err == nil {
		t.Fatal("oud antwoord geaccepteerd")
	}
	bad := strings.Replace(tReply, `"d":"J`, `"d":"K`, 1)
	if _, err := openReply([]byte(bad), tKey, tNonce, time.Unix(1795503254, 0)); err == nil {
		t.Fatal("aangepast antwoord geaccepteerd")
	}
}

func TestGetAuthFormat(t *testing.T) {
	h, n, err := getAuth(tKey, "agent_tests", time.Unix(1795000000, 0))
	if err != nil {
		t.Fatal(err)
	}
	p := strings.Split(h, ":")
	if len(p) != 4 || p[0] != tKeyID || p[1] != "1795000000" || p[2] != n || len(p[3]) != 64 {
		t.Fatalf("X-Agent-Auth onjuist: %s", h)
	}
	km, _ := sub(tKey, "mac")
	want, _ := hmacHex(km, "rzget1|agent_tests|1795000000|"+tNonce)
	if want != tGetSig {
		t.Fatal("HMAC wijkt af van PHP")
	}
}

func TestSealRoundTrip(t *testing.T) {
	body, n, err := seal([]byte("hallo"), tKey, time.Now())
	if err != nil || !isSealed(body) || len(n) != 32 {
		t.Fatalf("seal: %v %s", err, body)
	}
}
