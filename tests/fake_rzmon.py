#!/usr/bin/env python3
"""Nep-RzMon voor de agenttest (tests/agent-json.sh): geeft de agent een minimale instellingenlijst en bewaart zijn
rapport in <map>/payload.json. Gebruik: fake_rzmon.py <poort> <map>"""
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
            self._send('0\ttick\t60\n1\tsvc\tcron\n', 'text/plain')
        else:
            self._send(json.dumps({'ok': True, 'app': 'RzMon'}))

    def do_POST(self):
        length = int(self.headers.get('Content-Length') or 0)
        body = self.rfile.read(length)
        if self._action() == 'agent':
            with open(os.path.join(OUT, 'payload.json'), 'wb') as f:
                f.write(body)
            self._send(json.dumps({'ok': True, 'host': 'ci', 'rzmon_version': '0.0.0'}))
        else:
            self._send(json.dumps({'ok': True}))

    def log_message(self, *args):
        pass


HTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
