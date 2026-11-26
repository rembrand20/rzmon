package main

import "testing"

func TestParseNetshWlan(t *testing.T) {
	en := "\r\nThere is 1 interface on the system:\r\n\r\n    Name                   : Wi-Fi\r\n    Description            : Intel(R) Wi-Fi 6 AX201 160MHz\r\n" +
		"    State                  : connected\r\n    SSID                   : Thuis\r\n    AP BSSID               : 3c:84:6a:11:22:31\r\n    Band                   : 5 GHz\r\n" +
		"    Channel                : 36\r\n    Link Quality           : 80%\r\n    Radio type             : 802.11ax\r\n    Receive rate (Mbps)    : 1201\r\n" +
		"    Transmit rate (Mbps)   : 960.5\r\n    Signal                 : 96%\r\n    Profile                : Thuis\r\n"
	w := parseNetshWlan(en)
	if w == nil || w["ssid"] != "Thuis" || w["bssid"] != "3c:84:6a:11:22:31" || w["band"] != "5" || w["chan"] != 36 || w["std"] != "ax" ||
		w["rx"] != 1201.0 || w["tx"] != 960.5 || w["sig"] != -52 || w["if"] != "Wi-Fi" {
		t.Fatalf("Engels: %v", w)
	}
	nl := "    Naam                   : Wi-Fi\n    Status                 : verbonden\n    SSID                   : Huis\n    BSSID                  : aa-bb-cc-dd-ee-ff\n" +
		"    Radiotype              : 802.11n\n    Kanaal                 : 6\n    Ontvangstsnelheid (Mbps)  : 72,2\n    Verzendsnelheid (Mbps) : 65\n    Signaal                : 40%\n"
	w = parseNetshWlan(nl)
	if w == nil || w["ssid"] != "Huis" || w["bssid"] != "aa:bb:cc:dd:ee:ff" || w["chan"] != 6 || w["std"] != "n" || w["rx"] != 72.2 || w["tx"] != 65.0 || w["sig"] != -80 {
		t.Fatalf("Nederlands: %v", w)
	}
	// Twee kaarten, de eerste niet verbonden
	two := "    Name : Wi-Fi 2\n    State : disconnected\n\n    Name : Wi-Fi\n    SSID : X\n    BSSID : 00:11:22:33:44:55\n    Signal : 50%\n"
	if w = parseNetshWlan(two); w == nil || w["if"] != "Wi-Fi" || w["sig"] != -75 {
		t.Fatalf("twee kaarten: %v", w)
	}
	if parseNetshWlan("The Wireless AutoConfig Service (wlansvc) is not running.") != nil || parseNetshWlan("") != nil {
		t.Fatal("zonder wifi hoort nil")
	}
}
