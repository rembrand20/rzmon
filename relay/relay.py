#!/usr/bin/env python3
"""RzSense Relay: stuurt het verkeer van agents in dit netwerk door naar RzSense (RzSense 1.282.0, agent 1.109.0).

    agent op server --https--> deze relay --https--> RzSense   (het antwoord dezelfde weg terug)

- Alleen agentverkeer: /api.php met een vaste lijst acties (rapport, koppelen, testlijst, agent ophalen ...).
  Nooit de webinterface of de rest van de API.
- Alleen van afzenders in de netwerken die RzSense voor deze relay heeft ingesteld (bestand "allow", geschreven door
  de agent in deze container). Geen lijst = niemand.
- De rapporten zijn versleuteld van de agent tot RzSense: de relay kan ze niet lezen of veranderen.
- Bij elk doorgestuurd verzoek zet de relay het IP-adres van de agent erbij, ondertekend (HMAC-SHA256) met een sleutel
  die is afgeleid van de eigen sleutel van de agent in deze container. Zo kent RzSense het echte adres (IP-slot).
- Naar RzSense via curl, met dezelfde controle van het certificaat als de agent (vastgezet of gewoon).
Geen afhankelijkheden buiten Python 3 en curl.
"""
import hashlib
import hmac
import ipaddress
import json
import os
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

VERSION = os.environ.get("RELAY_VERSION", "dev")
DIR = os.environ.get("RELAY_DIR", "/data/relay")
PORT = int(os.environ.get("RELAY_PORT", "8444"))
MAX_BODY = int(os.environ.get("RELAY_MAX_BODY", str(32 * 1024 * 1024)))
RATE = int(os.environ.get("RELAY_RATE", "240"))          # verzoeken per minuut per afzender
MAX_BUSY = int(os.environ.get("RELAY_MAX_BUSY", "32"))   # tegelijk doorsturen
ACTIONS = {
    "ping", "agent", "agent_check", "agent_tests", "agent_pair", "agent_logs", "agent_pkg_progress",
    "agent_script", "agent_script_sig", "agent_install", "agent_install_sig",
}
PASS_HEADERS = ("Content-Type", "X-Agent-Token", "X-Agent-Key", "X-Agent-Auth", "X-Agent-Enc", "X-Agent-Pair",
                "X-Agent-Fallback", "X-Agent-Pin-Fail")
RETRY_RC = {5, 6, 7, 28, 35, 51, 52, 55, 56, 58, 60, 90, 91}   # verbinding mislukt: volgende adres proberen

lock = threading.Lock()
stats = {"req": 0, "ok": 0, "err": 0, "deny": 0, "up_err": 0, "busy": 0, "up": ""}
seen = {}       # ip -> laatste keer (voor "clients")
rate = {}       # ip -> [minuut, aantal]
busy = threading.BoundedSemaphore(MAX_BUSY)
_cache = {}


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def read_file(name, ttl=10):
    """Bestand uit RELAY_DIR, hooguit elke ttl seconden opnieuw gelezen."""
    now = time.time()
    hit = _cache.get(name)
    if hit and now - hit[0] < ttl:
        return hit[1]
    try:
        with open(os.path.join(DIR, name), encoding="utf-8") as f:
            val = f.read()
    except OSError:
        val = None
    _cache[name] = (now, val)
    return val


def allowed_nets():
    txt = read_file("allow")
    nets = []
    for part in (txt or "").replace("\n", ",").split(","):
        part = part.strip()
        if part:
            try:
                nets.append(ipaddress.ip_network(part, strict=False))
            except ValueError:
                pass
    return nets


def client_ip(raw):
    ip = ipaddress.ip_address(raw.split("%")[0])
    if ip.version == 6 and ip.ipv4_mapped:
        ip = ip.ipv4_mapped
    return ip


def upstreams():
    out = []
    for line in (read_file("upstream") or "").splitlines():
        parts = line.split()
        if len(parts) >= 1 and parts[0].startswith(("https://", "http://")):
            out.append((parts[0].rstrip("/"), parts[1] if len(parts) > 1 else "-"))
    return out[:3]


def sign_key():
    parts = (read_file("sign.key") or "").split()
    if len(parts) == 2 and len(parts[0]) == 16 and len(parts[1]) == 64:
        return parts[0], bytes.fromhex(parts[1])
    return None, None


def write_stats():
    while True:
        time.sleep(15)
        now = time.time()
        with lock:
            for ip in [k for k, t in seen.items() if now - t > 600]:
                seen.pop(ip, None)
            for ip in [k for k, v in rate.items() if v[0] < int(now // 60) - 1]:
                rate.pop(ip, None)
            data = dict(stats, clients=len(seen), at=int(now), ver=VERSION)
        try:
            fd, tmp = tempfile.mkstemp(dir=DIR)
            with os.fdopen(fd, "w") as f:
                json.dump(data, f)
            os.replace(tmp, os.path.join(DIR, "stats.json"))
        except OSError as e:
            log(f"stats niet bewaard: {e}")


class Handler(BaseHTTPRequestHandler):
    server_version = "RzSense-Relay"
    sys_version = ""
    protocol_version = "HTTP/1.0"
    timeout = 30

    def log_message(self, fmt, *args):   # geen standaardlog (zonder kenmerken of sleutels): zie log() hieronder
        pass

    def reply(self, code, body, ctype="application/json; charset=utf-8"):
        if isinstance(body, str):
            body = body.encode()
        try:
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(body)
        except OSError:
            pass

    def deny(self, code, why):
        with lock:
            stats["deny"] += 1
        self.reply(code, json.dumps({"error": f"Relay: {why}", "relay": True}))
        return None

    def do_GET(self):
        self.handle_any("GET")

    def do_POST(self):
        self.handle_any("POST")

    def handle_any(self, method):
        t0 = time.time()
        try:
            ip = client_ip(self.client_address[0])
        except ValueError:
            return self.deny(403, "onbekend adres")
        with lock:
            stats["req"] += 1
        nets = allowed_nets()
        if not any(ip in n for n in nets):
            log(f"{ip} geweigerd: niet in de toegestane netwerken")
            return self.deny(403, "dit adres hoort niet bij de netwerken van deze relay" if nets else "nog geen netwerken ingesteld in RzSense")
        minute = int(t0 // 60)
        with lock:
            r = rate.get(str(ip))
            if not r or r[0] != minute:
                r = rate[str(ip)] = [minute, 0]
            r[1] += 1
            seen[str(ip)] = t0
            too_many = r[1] > RATE
        if too_many:
            return self.deny(429, "te veel verzoeken")
        u = urlsplit(self.path)
        action = (parse_qs(u.query).get("action") or [""])[0]
        if u.path != "/api.php" or action not in ACTIONS:
            return self.deny(404, "alleen agentverkeer")
        body = b""
        if method == "POST":
            if self.headers.get("Transfer-Encoding"):
                return self.deny(411, "lengte nodig")
            try:
                n = int(self.headers.get("Content-Length", "0"))
            except ValueError:
                return self.deny(400, "ongeldige lengte")
            if n < 0 or n > MAX_BODY:
                return self.deny(413, "bericht te groot")
            body = self.rfile.read(n) if n else b""
            if len(body) != n:
                return self.deny(400, "bericht onvolledig")
        if not busy.acquire(blocking=False):
            return self.deny(503, "druk, probeer zo opnieuw")
        with lock:
            stats["busy"] += 1
        try:
            code, ctype, out, up = self.forward(method, u, body, str(ip))
        finally:
            busy.release()
            with lock:
                stats["busy"] -= 1
        with lock:
            stats["ok" if code and code < 500 else "err"] += 1
            if up:
                stats["up"] = up
        log(f"{ip} {action} {code or 502} {int((time.time() - t0) * 1000)} ms")
        if code is None:
            return self.reply(502, json.dumps({"error": "Relay: RzSense niet bereikbaar", "relay": True}))
        self.reply(code, out, ctype or "application/octet-stream")

    def forward(self, method, u, body, ip):
        kid, key = sign_key()
        target = "api.php" + (f"?{u.query}" if u.query else "")
        ts = str(int(time.time()))
        extra = []
        if kid:
            msg = "\n".join(["rzsense-relay", ts, ip, method, target, hashlib.sha256(body).hexdigest()]).encode()
            sig = hmac.new(key, msg, hashlib.sha256).hexdigest()
            extra = [f"X-Rz-Relay: {kid}", f"X-Rz-Relay-For: {ip}", f"X-Rz-Relay-Ts: {ts}", f"X-Rz-Relay-Sig: {sig}"]
        hdrs = [f"{h}: {self.headers[h]}" for h in PASS_HEADERS if self.headers.get(h) and "\n" not in self.headers[h] and "\r" not in self.headers[h]]
        for base, spec in upstreams():
            with tempfile.TemporaryDirectory(dir=DIR) as td:
                cfg = os.path.join(td, "curl.cfg")
                hfile = os.path.join(td, "h")
                bfile = os.path.join(td, "b")
                # Kopteksten via een configbestand: zo staan kenmerken en handtekeningen niet in de proceslijst
                with open(cfg, "w", encoding="utf-8") as f:
                    for h in hdrs + extra + ["Expect:"]:
                        f.write('header = "' + h.replace("\\", "\\\\").replace('"', '\\"') + '"\n')
                cmd = ["curl", "-sS", "-m", "60", "--connect-timeout", "10", "-X", method, "-K", cfg, "-D", hfile, "-o", bfile,
                       "-w", "%{http_code}"]
                if base.startswith("https://") and spec.startswith("sha256//"):
                    cmd += ["-k", "--pinnedpubkey", spec]
                if method == "POST":
                    cmd += ["--data-binary", "@-"]
                cmd.append(f"{base}/{target}")
                try:
                    p = subprocess.run(cmd, input=body, capture_output=True, timeout=75)
                except subprocess.TimeoutExpired:
                    p = None
                if p is None or p.returncode in RETRY_RC or not p.stdout.strip().isdigit() or p.stdout.strip() == b"000":
                    with lock:
                        stats["up_err"] += 1
                    log(f"RzSense via {base} niet bereikbaar ({'timeout' if p is None else 'curl ' + str(p.returncode)})")
                    continue
                code = int(p.stdout.strip())
                ctype = ""
                try:
                    with open(hfile, encoding="latin-1") as f:
                        for line in f:
                            if line.lower().startswith("content-type:"):
                                ctype = line.split(":", 1)[1].strip()
                    with open(bfile, "rb") as f:
                        out = f.read()
                except OSError:
                    out = b""
                return code, ctype, out, base
        return None, "", b"", ""


class Server(ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 64


def main():
    os.makedirs(DIR, exist_ok=True)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.load_cert_chain(os.path.join(DIR, "tls.crt"), os.path.join(DIR, "tls.key"))
    srv = None
    if socket.has_ipv6 and os.path.exists("/proc/net/if_inet6"):
        try:
            Server.address_family = socket.AF_INET6
            srv = Server(("::", PORT), Handler, bind_and_activate=False)
            srv.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
            srv.server_bind()
            srv.server_activate()
        except OSError:
            srv = None
    if srv is None:
        Server.address_family = socket.AF_INET
        srv = Server(("0.0.0.0", PORT), Handler)
    # Handdruk pas in de eigen draad van het verzoek, zodat één trage afzender de rest niet ophoudt
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True, do_handshake_on_connect=False)
    threading.Thread(target=write_stats, daemon=True).start()
    log(f"RzSense Relay {VERSION}: luistert op poort {PORT} (https)")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
