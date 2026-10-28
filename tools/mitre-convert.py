#!/usr/bin/env python3
"""MITRE ATT&CK (enterprise, STIX) -> rules/mitre.json: tactieken in volgorde en alle technieken (naam en tactieken).

Gebruik:  python3 -I tools/mitre-convert.py enterprise-attack.json > rules/mitre.json
Bron:     https://github.com/mitre-attack/attack-stix-data (enterprise-attack/enterprise-attack.json)
"""
import json
import sys


def ext_id(o):
    for r in o.get('external_references', []):
        if r.get('source_name') == 'mitre-attack':
            return r.get('external_id')
    return None


def main(path):
    objs = json.load(open(path, encoding='utf-8'))['objects']
    live = [o for o in objs if not o.get('revoked') and not o.get('x_mitre_deprecated')]
    version = next((o.get('x_mitre_version') for o in objs if o.get('type') == 'x-mitre-collection'), '')
    tactics = {o['x_mitre_shortname']: {'id': ext_id(o), 'name': o['name']} for o in live if o['type'] == 'x-mitre-tactic'}
    matrix = next(o for o in live if o['type'] == 'x-mitre-matrix')
    by_ref = {o['id']: o for o in live if o['type'] == 'x-mitre-tactic'}
    order = [by_ref[r]['x_mitre_shortname'] for r in matrix['tactic_refs'] if r in by_ref]
    techs = {}
    for o in live:
        if o['type'] != 'attack-pattern':
            continue
        tid = ext_id(o)
        if not tid:
            continue
        techs[tid] = {'name': o['name'], 'tactics': [p['phase_name'] for p in o.get('kill_chain_phases', []) if p.get('kill_chain_name') == 'mitre-attack']}
    out = {
        'source': 'MITRE ATT&CK Enterprise ' + str(version),
        'license': '© The MITRE Corporation. This work is reproduced and distributed with the permission of The MITRE Corporation (https://attack.mitre.org/resources/legal-and-branding/terms-of-use/).',
        'tactics': [{'slug': s, 'id': tactics[s]['id'], 'name': tactics[s]['name']} for s in order],
        'techniques': dict(sorted(techs.items())),
    }
    json.dump(out, sys.stdout, ensure_ascii=False, separators=(',', ':'))
    sys.stdout.write('\n')


if __name__ == '__main__':
    main(sys.argv[1])
