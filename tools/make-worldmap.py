"""Maakt public/assets/world.json: vereenvoudigde wereldkaart (Natural Earth 110m via world-atlas, ISC/publiek domein)
met per land (ISO alpha-2) een SVG-pad en een middelpunt. Middelpunten ook voor kleine landen (uit 50m).
Gebruik (1.149.0; de bronnen: npm-pakketten world-atlas@2.0.2 en i18n-iso-countries@7.11.0, uitgepakt in een lege map):
  python3 -I tools/make-worldmap.py <world-atlas-dir> <codes.json> <uit.json>"""
import json
import sys

wa, codes_p, out_p = sys.argv[1], sys.argv[2], sys.argv[3]
codes = json.load(open(codes_p))
num2a2 = {c[2]: c[0] for c in codes if len(c) >= 3}

W, H = 1000.0, 470.0          # lon -180..180, lat 84..-60 (zonder Antarctica)
LAT_TOP, LAT_BOT = 84.0, -60.0


def proj(lon, lat):
    lat = max(min(lat, LAT_TOP), LAT_BOT)
    return (lon + 180.0) / 360.0 * W, (LAT_TOP - lat) / (LAT_TOP - LAT_BOT) * H


def decode(topo):
    sx, sy = topo['transform']['scale']
    tx, ty = topo['transform']['translate']
    arcs = []
    for arc in topo['arcs']:
        x = y = 0
        pts = []
        for dx, dy in arc:
            x += dx
            y += dy
            pts.append((x * sx + tx, y * sy + ty))
        arcs.append(pts)
    return arcs


def ring(arcs, idx):
    pts = []
    for i in idx:
        a = arcs[i] if i >= 0 else list(reversed(arcs[~i]))
        pts.extend(a[1:] if pts else a)
    return pts


def polys(arcs, g):
    if g['type'] == 'Polygon':
        return [[ring(arcs, r) for r in g['arcs']]]
    if g['type'] == 'MultiPolygon':
        return [[ring(arcs, r) for r in p] for p in g['arcs']]
    return []


def area_centroid(pts):
    a = cx = cy = 0.0
    for (x1, y1), (x2, y2) in zip(pts, pts[1:] + pts[:1]):
        f = x1 * y2 - x2 * y1
        a += f
        cx += (x1 + x2) * f
        cy += (y1 + y2) * f
    if abs(a) < 1e-12:
        return 0.0, pts[0][0], pts[0][1]
    return abs(a / 2), cx / (3 * a), cy / (3 * a)


def load(name):
    topo = json.load(open(f'{wa}/{name}'))
    return topo, decode(topo)


def centroids(name):
    topo, arcs = load(name)
    out = {}
    for g in topo['objects']['countries']['geometries']:
        a2 = num2a2.get(str(g.get('id', '')))
        if not a2:
            continue
        best = None
        for p in polys(arcs, g):
            ring0 = p[0]
            # Over de datumgrens (Rusland, Fiji): lengtegraden doorlopend maken, anders ligt het middelpunt ernaast
            if max(x for x, _ in ring0) - min(x for x, _ in ring0) > 180:
                ring0 = [((x + 360) if x < 0 else x, y) for x, y in ring0]
            a, cx, cy = area_centroid(ring0)
            if cx > 180:
                cx -= 360
            if best is None or a > best[0]:
                best = (a, cx, cy)
        if best:
            x, y = proj(best[1], best[2])
            out[a2] = [round(x, 1), round(y, 1)]
    return out


topo, arcs = load('countries-110m.json')
paths = {}
for g in topo['objects']['countries']['geometries']:
    a2 = num2a2.get(str(g.get('id', '')))
    if not a2 or a2 == 'AQ':
        continue
    d = []
    for p in polys(arcs, g):
        for r in p:
            prev = None
            seg = []
            # Ring over de datumgrens (het oosten van Rusland, Fiji): het stuk aan de andere kant tegen de rand leggen
            if max(x for x, _ in r) - min(x for x, _ in r) > 180:
                r = [(180.0 if x < 0 else x, y) for x, y in r]
            for lon, lat in r:
                x, y = proj(lon, lat)
                q = (round(x, 1), round(y, 1))
                if q != prev:
                    seg.append(q)
                    prev = q
            # Ringen die over de datumgrens springen niet als streep over de hele kaart tekenen
            if len(seg) < 3:
                continue
            parts = [f'M{seg[0][0]:g} {seg[0][1]:g}']
            for (x0, _), (x, y) in zip(seg, seg[1:]):
                parts.append(('M' if abs(x - x0) > W / 2 else 'L') + f'{x:g} {y:g}')
            d.append(''.join(parts) + 'Z')
    paths[a2] = ''.join(d)

cent = centroids('countries-50m.json')
cent.update({k: v for k, v in centroids('countries-110m.json').items() if k not in cent})
json.dump({'w': W, 'h': H, 'src': 'Natural Earth 1:110m (publiek domein) via world-atlas 2.0.2 (ISC)',
           'p': paths, 'c': cent}, open(out_p, 'w'), separators=(',', ':'), sort_keys=True)
print(len(paths), 'landen,', len(cent), 'middelpunten')
