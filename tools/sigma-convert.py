#!/usr/bin/env python3
"""
Sigma-regels (SigmaHQ, Linux) omzetten naar rules/sigma-linux.json, het formaat dat RzMon zelf leest (src/Sigma.php).
RzMon heeft zo geen YAML-module in PHP nodig; de regels gaan mee met elke versie.

Gebruik:  python3 tools/sigma-convert.py PAD/NAAR/sigma > rules/sigma-linux.json
          (PAD = een kopie van https://github.com/SigmaHQ/sigma; de regels staan onder rules/linux)

Alleen wat RzMon kan zien, gaat mee: processen (process_creation en auditd EXECVE/SYSCALL), uitgaande verbindingen
(network_connection), nieuwe bestanden (file_event) en bewaakte paden (auditd PATH). Regels met velden of modifiers die
RzMon niet kent, regels met tellingen (| count) en regels van niveau low/informational vallen af.
Licentie van de regels: Detection Rule License (DRL) 1.1; RzMon toont bij elke treffer de titel, de auteur en de bron.
"""
import json
import os
import re
import sys

import yaml

FIELDS = {
    'exec': {'Image', 'CommandLine', 'ParentImage', 'ParentCommandLine', 'User', 'CurrentDirectory', 'type', 'SYSCALL', 'exe', 'comm',
             'uid', 'euid', 'auid', 'gid', 'egid'} | {f'a{i}' for i in range(10)},
    'net': {'Image', 'DestinationIp', 'DestinationPort', 'Initiated', 'User', 'SourcePort'},
    'file': {'TargetFilename'},
    'audit': {'type', 'name', 'exe', 'comm', 'SYSCALL', 'uid', 'euid', 'auid', 'key', 'nametype'},
}
MODS = {'contains', 'startswith', 'endswith', 're', 'cidr', 'all', 'exists'}
LEVELS = {'medium': 1, 'high': 2, 'critical': 3}


class Skip(Exception):
    pass


def kinds_for(ls):
    cat, svc = ls.get('category'), ls.get('service')
    if ls.get('product') != 'linux':
        raise Skip('niet linux')
    if cat == 'process_creation':
        return ['exec']
    if cat == 'network_connection':
        return ['net']
    if cat == 'file_event':
        return ['file']
    if svc == 'auditd' and not cat:
        return ['exec', 'audit']
    raise Skip(f'logsource {cat or svc}')


def conds_of(sel, kinds):
    """Eén selectie: lijst van AND-groepen (OR ertussen). Een lijst met teksten = trefwoorden (ergens in de gebeurtenis)."""
    if isinstance(sel, list) and all(isinstance(x, (str, int)) for x in sel):
        return [[{'f': '_raw', 'op': 'contains', 'v': [str(x) for x in sel], 'all': False}]]
    if isinstance(sel, dict):
        sel = [sel]
    if not isinstance(sel, list):
        raise Skip('selectie')
    groups = []
    for d in sel:
        if not isinstance(d, dict):
            raise Skip('selectie in lijst')
        group = []
        for key, val in d.items():
            parts = key.split('|')
            field, mods = parts[0], parts[1:]
            if not field:   # "|contains" zonder veld = trefwoord
                field = '_raw'
            if field != '_raw' and not any(field in FIELDS[k] for k in kinds):
                raise Skip(f'veld {field}')
            if any(m not in MODS for m in mods):
                raise Skip(f'modifier {mods}')
            vals = val if isinstance(val, list) else [val]
            if 'exists' in mods:
                group.append({'f': field, 'op': 'exists' if val else 'null', 'v': [], 'all': False})
                continue
            if val is None or vals == [None]:
                group.append({'f': field, 'op': 'null', 'v': [], 'all': False})
                continue
            if any(isinstance(v, (dict, list)) or v is None for v in vals):
                raise Skip('waarde')
            vals = ['true' if v is True else 'false' if v is False else str(v) for v in vals]
            op = next((m for m in mods if m in ('contains', 'startswith', 'endswith', 're', 'cidr')), 'eq')
            if op == 'eq' and any(re.search(r'(?<!\\)[*?]', v) for v in vals):
                op = 'glob'
            if op == 're':
                for v in vals:
                    try:
                        re.compile(v)
                    except re.error:
                        raise Skip('regex')
            group.append({'f': field, 'op': op, 'v': vals, 'all': 'all' in mods})
        groups.append(group)
    return groups


TOKEN = re.compile(r'\s*(\(|\)|\bnot\b|\band\b|\bor\b|\b1 of\b|\ball of\b|[A-Za-z0-9_*]+)')


def parse_cond(text, names):
    if '|' in text:
        raise Skip('telling')
    toks = []
    pos = 0
    text = text.strip()
    while pos < len(text):
        m = TOKEN.match(text, pos)
        if not m:
            raise Skip(f'conditie {text}')
        toks.append(m.group(1))
        pos = m.end()
    i = 0

    def peek():
        return toks[i] if i < len(toks) else None

    def take():
        nonlocal i
        i += 1
        return toks[i - 1]

    def expand(pat):
        if pat == 'them':
            return sorted(names)
        rx = re.compile('^' + re.escape(pat).replace(r'\*', '.*') + '$')
        hit = sorted(n for n in names if rx.match(n))
        if not hit:
            raise Skip(f'onbekend {pat}')
        return hit

    def atom():
        t = take()
        if t == '(':
            e = expr()
            if take() != ')':
                raise Skip('haakjes')
            return e
        if t == 'not':
            return {'not': atom()}
        if t in ('1 of', 'all of'):
            refs = [{'ref': n} for n in expand(take())]
            return {'or' if t == '1 of' else 'and': refs} if len(refs) > 1 else refs[0]
        if t in names:
            return {'ref': t}
        raise Skip(f'conditie-woord {t}')

    def conj():
        parts = [atom()]
        while peek() == 'and':
            take()
            parts.append(atom())
        return parts[0] if len(parts) == 1 else {'and': parts}

    def expr():
        parts = [conj()]
        while peek() == 'or':
            take()
            parts.append(conj())
        return parts[0] if len(parts) == 1 else {'or': parts}

    e = expr()
    if i != len(toks):
        raise Skip('conditie-rest')
    return e


def convert(path, base):
    with open(path, encoding='utf-8') as f:
        r = yaml.safe_load(f)
    level = LEVELS.get(r.get('level'))
    if not level:
        raise Skip('niveau')
    if r.get('status') in ('deprecated', 'unsupported'):
        raise Skip('status')
    kinds = kinds_for(r.get('logsource') or {})
    det = dict(r['detection'])
    cond = det.pop('condition')
    if isinstance(cond, list):
        if len(cond) != 1:
            raise Skip('meer condities')
        cond = cond[0]
    det.pop('timeframe', None)
    sels = {name: conds_of(sel, kinds) for name, sel in det.items()}
    tags = [t[7:].upper() for t in r.get('tags') or [] if re.match(r'^attack\.t\d{4}', t)]
    return {
        'id': r['id'], 'title': r['title'], 'level': level, 'author': r.get('author', ''),
        'src': 'https://github.com/SigmaHQ/sigma/blob/master/' + os.path.relpath(path, base).replace(os.sep, '/'),
        'tags': tags, 'kinds': kinds, 'desc': ' '.join(str(r.get('description', '')).split())[:400],
        'fp': [str(x) for x in (r.get('falsepositives') or [])][:3], 'sel': sels, 'cond': parse_cond(str(cond), set(sels)),
    }


def main():
    base = sys.argv[1]
    out, skipped = [], {}
    for root, _, files in os.walk(os.path.join(base, 'rules', 'linux')):
        for fn in sorted(files):
            if not fn.endswith('.yml'):
                continue
            try:
                out.append(convert(os.path.join(root, fn), base))
            except Skip as e:
                skipped[str(e).split(' ')[0]] = skipped.get(str(e).split(' ')[0], 0) + 1
            except (KeyError, TypeError, yaml.YAMLError):
                skipped['fout'] = skipped.get('fout', 0) + 1
    out.sort(key=lambda r: r['id'])
    json.dump({'source': 'SigmaHQ (https://github.com/SigmaHQ/sigma)', 'license': 'Detection Rule License (DRL) 1.1',
               'rules': out}, sys.stdout, ensure_ascii=False, indent=0, separators=(',', ':'))
    sys.stdout.write('\n')
    print(f'{len(out)} regels; overgeslagen: {skipped}', file=sys.stderr)


if __name__ == '__main__':
    main()
