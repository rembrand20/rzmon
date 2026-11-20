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

1.292.0 (agent 1.114.0): RzSense haalt op (bijvoorbeeld in een DMZ). Staat er een sleutel in "pull.key", dan belt de
relay nooit zelf naar RzSense:

    agent --https--> deze relay (bewaart)  <--https-- RzSense haalt op (poort 8445) en legt de antwoorden terug

- Elk verzoek van een agent gaat in de wachtrij (map q/, hooguit RELAY_Q_MB en RELAY_Q_HOURS). De agent krijgt meteen het
  antwoord dat RzSense de vorige keer voor hem klaarlegde (map r/), of "in de wachtrij".
- RzSense haalt op met POST /rz/pull en legt antwoorden terug met POST /rz/push, op een eigen poort (RELAY_PULL_PORT).
  Elk verzoek is ondertekend (HMAC-SHA256 met de sleutel uit pull.key, met tijd en nonce); het antwoord ook, gebonden
  aan het verzoek. Zonder geldige handtekening: niets.
- De agent, het installatiescript, hun handtekeningen en ping levert de relay zelf uit (map files/, van RzSense).
"""

import base64
import hashlib
import hmac
import ipaddress
import json
import os
import re
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
# 1.292.0: RzSense haalt op
PULL_PORT = int(os.environ.get("RELAY_PULL_PORT", "8445"))
Q_MAX = int(os.environ.get("RELAY_Q_MB", "200")) * 1024 * 1024       # wachtrij hooguit zo groot
Q_AGE = int(os.environ.get("RELAY_Q_HOURS", "24")) * 3600            # en zo oud
R_MAX = 10                                                            # antwoorden per agent en actie
FILE_ACTIONS = {"ping", "agent_script", "agent_script_sig", "agent_install", "agent_install_sig"}
PULL_SKEW = 300

lock = threading.Lock()
qlock = threading.Lock()   # wachtrij en antwoorden
stats = {"req": 0, "ok": 0, "err": 0, "deny": 0, "up_err": 0, "busy": 0, "up": "", "pulled": 0, "q": 0, "dropped": 0, "pull_at": 0, "pull_deny": 0}
pull_nonces = {}   # nonce -> tijd (tegen opnieuw afspelen)
idents = {}        # agent -> laatste keer (voor "agents")
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
            for k in [k for k, t in idents.items() if now - t > 3600]:
                idents.pop(k, None)
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
        if pull_key() and ip.is_loopback:
            nets = nets + [ipaddress.ip_network("127.0.0.0/8"), ipaddress.ip_network("::1/128")]   # de agent van de relay zelf
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
        if pull_key():
            return self.pull_mode(method, u, action, body, ip, t0)
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

    def pull_mode(self, method, u, action, body, ip, t0):
        """1.292.0: RzSense haalt op. Bestanden zelf uitleveren; al het andere in de wachtrij, met het klaarliggende antwoord."""
        if action in FILE_ACTIONS:
            try:
                with open(os.path.join(DIR, "files", action), "rb") as f:
                    data = f.read()
            except OSError:
                return self.deny(503, "dit bestand heeft de relay nog niet van RzSense")
            ctype = "application/json" if action == "ping" else "text/x-shellscript; charset=utf-8" if action in ("agent_script", "agent_install") else "text/plain; charset=utf-8"
            with lock:
                stats["ok"] += 1
            return self.reply(200, data, ctype)
        hdrs = {h: self.headers[h] for h in PASS_HEADERS if self.headers.get(h) and "\n" not in self.headers[h] and "\r" not in self.headers[h]}
        ident = ident_of(hdrs, str(ip))
        item = {"id": os.urandom(8).hex(), "rts": int(t0), "ip": str(ip), "ident": ident, "action": action, "method": method,
                "target": "api.php" + (f"?{u.query}" if u.query else ""), "headers": hdrs, "beat": self.headers.get("X-Agent-Beat") == "1",
                "body": base64.b64encode(body).decode()}
        try:
            enqueue(item)
            out = take_reply(ident, action)
        except OSError as e:
            log(f"wachtrij: {e}")
            return self.deny(503, "wachtrij niet beschikbaar")
        with lock:
            stats["ok"] += 1
            idents[ident] = t0
        log(f"{ip} {action} in de wachtrij{' (+antwoord)' if out else ''}")
        self.reply(200, out or json.dumps({"rzpull": 1, "queued": 1}, separators=(",", ":")))

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


# ---------------------------------------------------------------- 1.292.0: RzSense haalt op

def pull_key():
    """De gedeelde sleutel met RzSense (pull.key, 64 hex), of None: dan stuurt de relay door zoals altijd."""
    txt = (read_file("pull.key", 30) or "").strip()
    return bytes.fromhex(txt) if re.fullmatch(r"[0-9a-f]{64}", txt) else None


def ident_of(h, ip):
    """Wie is de agent: het kenmerk van zijn sleutel, van de koppelcode, het token (gehasht) of anders zijn adres."""
    k = h.get("X-Agent-Key", "")
    if re.fullmatch(r"[0-9a-f]{16}", k):
        return "k:" + k
    a = h.get("X-Agent-Auth", "")
    if re.match(r"[0-9a-f]{16}:", a):
        return "k:" + a[:16]
    p = h.get("X-Agent-Pair", "")
    if re.fullmatch(r"[0-9a-f]{16}", p):
        return "p:" + p
    t = h.get("X-Agent-Token", "")
    if t:
        return "t:" + hashlib.sha256(t.encode()).hexdigest()[:16]
    return "ip:" + ip


def qdir(name):
    d = os.path.join(DIR, name)
    os.makedirs(d, mode=0o700, exist_ok=True)
    return d


def put(path, data):
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(path))
    with os.fdopen(fd, "wb") as f:
        f.write(data)
    os.replace(tmp, path)


def queue_files():
    """(naam, pad, grootte, tijd) van de wachtrij, oudste eerst; te oude weg."""
    d = qdir("q")
    out = []
    now = time.time()
    for n in sorted(os.listdir(d)):
        if not n.endswith(".json"):
            continue
        p = os.path.join(d, n)
        try:
            st = os.stat(p)
        except OSError:
            continue
        if now - st.st_mtime > Q_AGE:
            try:
                os.unlink(p)
                stats["dropped"] += 1
            except OSError:
                pass
            continue
        out.append((n, p, st.st_size))
    return out


def enqueue(item):
    data = json.dumps(item, separators=(",", ":")).encode()
    with qlock:
        files = queue_files()
        total = sum(f[2] for f in files) + len(data)
        while files and total > Q_MAX:   # vol: de oudste eruit
            n, p, size = files.pop(0)
            try:
                os.unlink(p)
            except OSError:
                pass
            total -= size
            stats["dropped"] += 1
        put(os.path.join(qdir("q"), f"{int(time.time() * 1000):015d}-{item['id']}.json"), data)


def reply_dir(ident, action):
    return qdir(os.path.join("r", hashlib.sha256(f"{ident}|{action}".encode()).hexdigest()[:24]))


def take_reply(ident, action):
    """Het oudste antwoord dat RzSense voor deze agent (en actie) klaarlegde, en dan weg."""
    with qlock:
        d = reply_dir(ident, action)
        for n in sorted(os.listdir(d)):
            p = os.path.join(d, n)
            try:
                with open(p, "rb") as f:
                    data = f.read()
                os.unlink(p)
            except OSError:
                continue
            if time.time() - int(n.split("-")[0]) / 1000 > Q_AGE:
                continue
            return data
    return None


def pull_items(maximum):
    """Verzoeken voor RzSense, oudste eerst. sup = er staat een nieuwer rapport van dezelfde agent achter (dit telt alleen
    voor de metingen); alleen als het al een paar minuten ligt."""
    items = []
    size = 0
    with qlock:
        files = queue_files()
        latest = {}
        for n, p, _ in files:
            try:
                with open(p, encoding="utf-8") as f:
                    it = json.load(f)
            except (OSError, ValueError):
                continue
            if it.get("action") == "agent" and not it.get("beat"):
                latest[it.get("ident")] = it.get("id")
            items.append(it)
    out = []
    now = time.time()
    for it in items:
        if len(out) >= maximum or size > 16 * 1024 * 1024:
            break
        it["sup"] = it.get("action") == "agent" and not it.get("beat") and latest.get(it.get("ident")) != it.get("id") and now - it.get("rts", 0) > 120
        size += len(it.get("body", ""))
        out.append(it)
    return out, len(files), sum(f[2] for f in files)


def done_items(done):
    """Antwoorden van RzSense: verzoek uit de wachtrij, antwoord klaarleggen voor de agent."""
    ids = {}
    for d in done:
        if isinstance(d, dict) and re.fullmatch(r"[0-9a-f]{8,40}", str(d.get("id", ""))):
            ids[d["id"]] = d
    with qlock:
        qd = qdir("q")
        for n in os.listdir(qd):
            m = re.fullmatch(r"\d{15}-([0-9a-f]{8,40})\.json", n)
            if m and m.group(1) in ids:
                try:
                    os.unlink(os.path.join(qd, n))
                except OSError:
                    pass
        for i, d in ids.items():
            body = d.get("body")
            ident, action = str(d.get("ident", "")), str(d.get("action", ""))
            if body is None or not re.fullmatch(r"[A-Za-z0-9:._-]{1,80}", ident) or not re.fullmatch(r"agent[a-z_]{0,30}", action):
                continue
            try:
                code = int(d.get("code", 200))
                base64.b64decode(body, validate=True)
            except (ValueError, TypeError):
                continue
            rd = reply_dir(ident, action)
            put(os.path.join(rd, f"{int(time.time() * 1000):015d}-{i}.json"),
                json.dumps({"rzpull": 1, "code": code, "b64": body}, separators=(",", ":")).encode())
            old = sorted(os.listdir(rd))
            for n in old[:-R_MAX]:
                try:
                    os.unlink(os.path.join(rd, n))
                except OSError:
                    pass
    return len(ids)


def file_shas():
    out = {}
    d = qdir("files")
    for n in FILE_ACTIONS:
        try:
            with open(os.path.join(d, n), "rb") as f:
                out[n] = hashlib.sha256(f.read()).hexdigest()
        except OSError:
            pass
    return out


def pull_from_ok(ip):
    allow = os.environ.get("RELAY_PULL_FROM", "").replace(",", " ").split()
    if not allow:
        return True
    for a in allow:
        try:
            if ip in ipaddress.ip_network(a, strict=False):
                return True
        except ValueError:
            pass
    return False


class PullHandler(BaseHTTPRequestHandler):
    """Het ophaalpunt voor RzSense: POST /rz/pull en /rz/push, alleen met een geldige handtekening."""
    server_version = "RzSense-Relay"
    sys_version = ""
    protocol_version = "HTTP/1.0"
    timeout = 60

    def log_message(self, fmt, *args):
        pass

    def answer(self, code, data, key=None, nonce=""):
        body = json.dumps(data, separators=(",", ":")).encode()
        try:
            self.send_response(code)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            if key is not None:
                sig = hmac.new(key, b"rzpull1r\n" + nonce.encode() + b"\n" + hashlib.sha256(body).hexdigest().encode(), hashlib.sha256).hexdigest()
                self.send_header("X-Rz-Pull-Sig", sig)
            self.end_headers()
            self.wfile.write(body)
        except OSError:
            pass

    def refuse(self, code, why):
        with lock:
            stats["pull_deny"] += 1
        log(f"ophalen geweigerd ({self.client_address[0]}): {why}")
        self.answer(code, {"error": why})

    def do_GET(self):
        self.refuse(404, "alleen voor RzSense")

    def do_POST(self):
        try:
            ip = client_ip(self.client_address[0])
        except ValueError:
            return self.refuse(403, "onbekend adres")
        if not pull_from_ok(ip):
            return self.refuse(403, "dit adres mag niet ophalen (RELAY_PULL_FROM)")
        key = pull_key()
        if key is None:
            return self.refuse(503, "geen sleutel (pull.key)")
        path = urlsplit(self.path).path
        if path not in ("/rz/pull", "/rz/push"):
            return self.refuse(404, "onbekend")
        try:
            n = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            return self.refuse(400, "ongeldige lengte")
        if n < 0 or n > 64 * 1024 * 1024:
            return self.refuse(413, "te groot")
        body = self.rfile.read(n) if n else b""
        ts, nonce, sig = self.headers.get("X-Rz-Pull-Ts", ""), self.headers.get("X-Rz-Pull-Nonce", ""), self.headers.get("X-Rz-Pull-Sig", "")
        if not re.fullmatch(r"\d{9,11}", ts) or not re.fullmatch(r"[0-9a-f]{32}", nonce) or abs(time.time() - int(ts)) > PULL_SKEW:
            return self.refuse(401, "handtekening onvolledig of tijd klopt niet")
        want = hmac.new(key, "\n".join(["rzpull1", ts, nonce, path, hashlib.sha256(body).hexdigest()]).encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(want, sig):
            return self.refuse(401, "handtekening klopt niet")
        with lock:
            now = time.time()
            for k in [k for k, t in pull_nonces.items() if now - t > 2 * PULL_SKEW]:
                pull_nonces.pop(k, None)
            if nonce in pull_nonces:
                stats["pull_deny"] += 1
                return self.answer(409, {"error": "opnieuw afgespeeld"}, key, nonce)
            pull_nonces[nonce] = now
        try:
            req = json.loads(body or b"{}")
        except ValueError:
            return self.answer(400, {"error": "ongeldige JSON"}, key, nonce)
        if path == "/rz/pull":
            items, n_q, size = pull_items(max(1, min(200, int(req.get("max", 50)))))
            with lock:
                stats["pull_at"] = int(time.time())
                active = sum(1 for t in idents.values() if time.time() - t < 600)
            return self.answer(200, {"items": items, "files": file_shas(), "ver": VERSION,
                                     "q": {"n": n_q, "bytes": size, "dropped": stats["dropped"], "agents": active}}, key, nonce)
        n_done = done_items(req.get("done") or [])
        for name, b64 in (req.get("files") or {}).items():
            if name in FILE_ACTIONS and isinstance(b64, str) and len(b64) < 8 * 1024 * 1024:
                try:
                    put(os.path.join(qdir("files"), name), base64.b64decode(b64, validate=True))
                except (ValueError, OSError) as e:
                    log(f"bestand {name} niet bewaard: {e}")
        with lock:
            stats["pulled"] += n_done
        log(f"RzSense: {n_done} verwerkt")
        return self.answer(200, {"ok": True, "n": n_done}, key, nonce)


class Server(ThreadingHTTPServer):
    daemon_threads = True
    request_queue_size = 64


def listen(port, handler, ctx):
    srv = None
    if socket.has_ipv6 and os.path.exists("/proc/net/if_inet6"):
        try:
            cls = type("Server6", (Server,), {"address_family": socket.AF_INET6})
            srv = cls(("::", port), handler, bind_and_activate=False)
            srv.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 0)
            srv.server_bind()
            srv.server_activate()
        except OSError:
            srv = None
    if srv is None:
        cls = type("Server4", (Server,), {"address_family": socket.AF_INET})
        srv = cls(("0.0.0.0", port), handler)
    # Handdruk pas in de eigen draad van het verzoek, zodat één trage afzender de rest niet ophoudt
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True, do_handshake_on_connect=False)
    return srv


def main():
    os.makedirs(DIR, exist_ok=True)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.load_cert_chain(os.path.join(DIR, "tls.crt"), os.path.join(DIR, "tls.key"))
    srv = listen(PORT, Handler, ctx)
    threading.Thread(target=write_stats, daemon=True).start()
    if pull_key():
        psrv = listen(PULL_PORT, PullHandler, ctx)
        threading.Thread(target=psrv.serve_forever, daemon=True).start()
        log(f"RzSense Relay {VERSION}: RzSense haalt op (poort {PULL_PORT}); agents op poort {PORT} (https)")
    else:
        log(f"RzSense Relay {VERSION}: luistert op poort {PORT} (https)")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
