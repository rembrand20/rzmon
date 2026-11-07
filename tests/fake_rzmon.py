#!/usr/bin/env python3
"""Nep-RzMon voor de agenttest (tests/agent-json.sh): geeft de agent een minimale instellingenlijst en bewaart zijn
rapport in <map>/payload.json. Gebruik: fake_rzmon.py <poort> <map>"""
import gzip
import json
import os
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse

PORT, OUT = int(sys.argv[1]), sys.argv[2]


class Handler(BaseHTTPRequestHandler):
    def _send(self, body, ctype='application/json'):
        data = body.encode()
        self.send_response(200)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def _action(self):
        return parse_qs(urlparse(self.path).query).get('action', [''])[0]

    def do_GET(self):
        if self._action() == 'agent_tests':
            # Tik, en een service-check zodat ook dat deel van het rapport gevuld wordt
            # 1.63.0: met endpoint-bewaking aan (zonder auditd in de container: "missing" in edr.info)
            # Agent 1.79.0: ook een zelftest (🧪 Test in de Agent Manager)
            # Agent 1.83.0: een logbron (alleen warning en erger), elke run een pakketje (logship 0)
            self._send('0\ttick\t60\n1\tsvc\tcron\n0\tedr\t1\n0\tselftest\t42\n0\tlogship\t0\n7\tlogsrc\tfile\t%s/logs/*.log\t2\n' % OUT, 'text/plain')
        else:
            self._send(json.dumps({'ok': True, 'app': 'RzMon'}))

    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length)
        if self._action() == 'agent':
            # Agent 1.73.0: gecomprimeerd (gzip) als wij "gz":1 zeiden; dan uitpakken en de grootte bewaren
            if body[:2] == b'\x1f\x8b':
                with open(os.path.join(OUT, 'gz.size'), 'w') as f:
                    f.write('%d %d' % (len(body), len(gzip.decompress(body))))
                body = gzip.decompress(body)
            with open(os.path.join(OUT, 'payload.json'), 'wb') as f:
                f.write(body)
            self._send(json.dumps({'ok': True, 'host': 'ci', 'rzmon_version': '0.0.0', 'gz': 1}))
        else:
            self._send(json.dumps({'ok': True}))

    def log_message(self, *args):
        pass


HTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
