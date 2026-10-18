"""Draws the 51 state outlines (50 states + DC) used on the Claim page's state cards.

Source: US Census Bureau cartographic boundary file (public domain), e.g.
https://www2.census.gov/geo/tiger/GENZ2023/shp/cb_2023_us_state_5m.zip, unzipped.

Each state is drawn on its own equal-area (Albers) projection, fitted to the same 256px box,
filled #F09A2E with a soft anti-aliased edge and the thin light rim of the original five, and
saved as media/states/xx.webp. Alaska's islands across the date line are shifted back next to it
and the far-western Aleutians are left out; Hawaii keeps its eight main islands. Also writes a contact sheet for review.

    python3 -I make-outlines.py <folder with cb_*_us_state_*.shp/.dbf> <media dir> [sheet.png]
"""
import glob
import math
import os
import struct
import sys

from PIL import Image, ImageDraw, ImageFilter

SIZE = 256
BOX = 228            # the outline fits in this square, centered
SS = 4               # supersampling for smooth edges
FILL = (240, 154, 46)
RIM = (235, 242, 244)
CODES = ('AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ '
         'NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY').split()


def read_dbf(path):
    with open(path, 'rb') as f:
        data = f.read()
    n, hlen, rlen = struct.unpack('<IHH', data[4:12])
    fields = []
    pos = 32
    while data[pos] != 0x0D:
        name = data[pos:pos + 11].split(b'\0')[0].decode('ascii')
        flen = data[pos + 16]
        fields.append((name, flen))
        pos += 32
    rows = []
    for i in range(n):
        rec = data[hlen + i * rlen + 1: hlen + (i + 1) * rlen]
        row, off = {}, 0
        for name, flen in fields:
            row[name] = rec[off:off + flen].decode('latin-1').strip()
            off += flen
        rows.append(row)
    return rows


def read_shp(path):
    with open(path, 'rb') as f:
        data = f.read()
    pos = 100
    shapes = []
    while pos < len(data):
        _, clen = struct.unpack('>ii', data[pos:pos + 8])
        body = data[pos + 8: pos + 8 + clen * 2]
        pos += 8 + clen * 2
        stype = struct.unpack('<i', body[:4])[0]
        if stype != 5:
            shapes.append([])
            continue
        nparts, npts = struct.unpack('<ii', body[36:44])
        parts = list(struct.unpack('<%di' % nparts, body[44:44 + 4 * nparts])) + [npts]
        pts = struct.unpack('<%dd' % (2 * npts), body[44 + 4 * nparts: 44 + 4 * nparts + 16 * npts])
        rings = [[(pts[2 * k], pts[2 * k + 1]) for k in range(parts[j], parts[j + 1])] for j in range(nparts)]
        shapes.append(rings)
    return shapes


def albers(lon0, lat0, p1, p2):
    r = math.radians
    n = (math.sin(r(p1)) + math.sin(r(p2))) / 2
    c = math.cos(r(p1)) ** 2 + 2 * n * math.sin(r(p1))
    rho0 = math.sqrt(c - 2 * n * math.sin(r(lat0))) / n

    def f(lon, lat):
        rho = math.sqrt(max(0.0, c - 2 * n * math.sin(r(lat)))) / n
        t = n * r(lon - lon0)
        return rho * math.sin(t), rho0 - rho * math.cos(t)
    return f


def signed_area(ring):
    return sum(x1 * y2 - x2 * y1 for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1])) / 2


def draw(code, rings):
    if code == 'AK':
        # islands across the date line move next to Alaska; the far-western Aleutians are left
        # out so the mainland isn't shrunk to fit them
        rings = [[(x - 360 if x > 0 else x, y) for x, y in ring] for ring in rings]
        rings = [ring for ring in rings if min(x for x, _ in ring) > -172]
    if code == 'HI':
        rings = [ring for ring in rings if min(x for x, _ in ring) > -161]
    lons = [x for ring in rings for x, _ in ring]
    lats = [y for ring in rings for _, y in ring]
    lo, hi = min(lats), max(lats)
    proj = albers((min(lons) + max(lons)) / 2, (lo + hi) / 2, lo + (hi - lo) / 6, hi - (hi - lo) / 6)
    xy = [[proj(x, y) for x, y in ring] for ring in rings]
    xs = [p[0] for ring in xy for p in ring]
    ys = [p[1] for ring in xy for p in ring]
    w, h = max(xs) - min(xs), max(ys) - min(ys)
    k = BOX * SS / max(w, h)
    ox = (SIZE * SS - w * k) / 2
    oy = (SIZE * SS - h * k) / 2
    mask = Image.new('L', (SIZE * SS, SIZE * SS), 0)
    d = ImageDraw.Draw(mask)
    # outer rings first, then holes (shapefile: holes run the other way round)
    for ring, src in sorted(zip(xy, rings), key=lambda t: signed_area(t[1]) > 0):
        pts = [(ox + (x - min(xs)) * k, oy + (max(ys) - y) * k) for x, y in ring]
        d.polygon(pts, fill=0 if signed_area(src) > 0 else 255)
    alpha = mask.resize((SIZE, SIZE), Image.LANCZOS).filter(ImageFilter.GaussianBlur(0.45))
    img = Image.new('RGBA', (SIZE, SIZE), FILL + (0,))
    px, ap = img.load(), alpha.load()
    for yy in range(SIZE):
        for xx in range(SIZE):
            a = ap[xx, yy]
            if a == 0:
                continue
            t = 0.0 if a >= 250 else (1 - a / 255) ** 0.6   # light rim on the soft edge
            px[xx, yy] = tuple(round(FILL[i] + (RIM[i] - FILL[i]) * t) for i in range(3)) + (a,)
    return img


def main():
    src, media = sys.argv[1], sys.argv[2]
    sheet_path = sys.argv[3] if len(sys.argv) > 3 else None
    shp = glob.glob(os.path.join(src, '*.shp'))[0]
    rows = read_dbf(shp[:-4] + '.dbf')
    shapes = read_shp(shp)
    by_code = {row['STUSPS']: shapes[i] for i, row in enumerate(rows)}
    missing = [c for c in CODES if c not in by_code]
    if missing:
        sys.exit('missing in source: ' + ' '.join(missing))
    sheet = Image.new('RGBA', (SIZE * 9 // 2, SIZE * 6 // 2), (11, 14, 19, 255))
    for i, code in enumerate(CODES):
        img = draw(code, by_code[code])
        img.save(os.path.join(media, '%s.webp' % code.lower()), 'WEBP', quality=90, method=6)
        small = img.resize((SIZE // 2, SIZE // 2), Image.LANCZOS)
        sheet.alpha_composite(small, ((i % 9) * SIZE // 2, (i // 9) * SIZE // 2))
    if sheet_path:
        sheet.save(sheet_path)
    print('drew %d outlines into %s' % (len(CODES), media))


main()
