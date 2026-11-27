# Maakt agent-win/proxytpl_linux.go uit de sjablonen in agent/src/69-proxy.sh, zodat de tekst letterlijk dezelfde is.
import re
src = open('agent/src/69-proxy.sh').read()
def grab(var):
    m = re.search(r'\n            ' + var + r'="(.*?)\n"\n', src, re.S)
    assert m, var
    t = m.group(1) + "\n"
    out, i = [], 0
    while i < len(t):   # bash tussen dubbele aanhalingstekens: \$ \" \\ \` worden het teken zelf
        if t[i] == '\\' and i + 1 < len(t) and t[i + 1] in '$"\\`':
            out.append(t[i + 1]); i += 2
        else:
            out.append(t[i]); i += 1
    return ''.join(out)
http, srv = grab('px_conf_http'), grab('px_conf_srv')
assert '${px_geo}' in http and 'limit_req_status 429;' in srv and '`' not in http + srv
go = '''//go:build linux

package main

// Gemaakt door tools/gen-proxy-tpl.py uit agent/src/69-proxy.sh: de nginx-sjablonen van "Reverse proxy beschermen",
// letterlijk dezelfde tekst als de bash-agent schrijft. Niet met de hand wijzigen; ${px_…} wordt bij het schrijven ingevuld.

const proxyTplVersion = "3"

const proxyTplHTTP = `%s`

const proxyTplSrv = `%s`
''' % (http, srv)
open('agent-win/proxytpl_linux.go', 'w').write(go)
print(len(http), len(srv))
