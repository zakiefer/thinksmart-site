"""Builds the Gate 2 review page: every intended difference with its evidence, every changed
screenshot cropped to where it changed (today | built | changed pixels in red), and the
new pieces switched on (default build | variant).

    python3 review.py <gate2/out> <INTENDED.md> <review dir>
"""
import html
import json
import os
import re
import sys

from PIL import Image, ImageChops

out, intended_md, dest = sys.argv[1:4]
os.makedirs(os.path.join(dest, 'img'), exist_ok=True)


def crop_pair(a_path, b_path, tag):
    a = Image.open(a_path).convert('RGB')
    b = Image.open(b_path).convert('RGB')
    w, h = min(a.width, b.width), min(a.height, b.height)
    diff = ImageChops.difference(a.crop((0, 0, w, h)), b.crop((0, 0, w, h))).convert('L').point(lambda p: 255 if p > 6 else 0)
    box = diff.getbbox()
    if box is None:
        if a.size == b.size:
            return None
        box = (0, h - 200, w, h)  # only the height changed: show the end
    x0, y0, x1, y1 = box
    pad = 90
    x0, y0, x1, y1 = 0, max(0, y0 - pad), w, min(max(a.height, b.height), y1 + pad)
    if y1 - y0 > 1500:
        y1 = y0 + 1500
    tiles = []
    for im in (a, b):
        c = Image.new('RGB', (w, y1 - y0), (11, 14, 19))
        c.paste(im.crop((0, y0, w, min(y1, im.height))), (0, 0))
        tiles.append(c)
    red = Image.blend(tiles[1], Image.new('RGB', tiles[1].size, (0, 0, 0)), 0.55)
    m = Image.new('L', tiles[1].size, 0)
    m.paste(diff.crop((0, y0, w, min(y1, h))), (0, 0))
    red.paste(Image.new('RGB', red.size, (255, 40, 40)), (0, 0), m)
    tiles.append(red)
    scale = min(1.0, 420 / w)
    names = []
    for i, t in enumerate(tiles):
        t = t.resize((max(1, int(t.width * scale)), max(1, int(t.height * scale))))
        n = '%s-%d.jpg' % (tag, i)
        t.save(os.path.join(dest, 'img', n), quality=82)
        names.append(n)
    return {'names': names, 'y': (y0, y1), 'height': (a.height, b.height)}


def section(title, intro, pairs, labels):
    s = ['<h2>%s</h2><p>%s</p>' % (html.escape(title), intro)]
    for name, a, b in pairs:
        r = crop_pair(a, b, re.sub(r'[^a-z0-9-]', '-', (title + '-' + name).lower()))
        if r is None:
            continue
        hh = '' if r['height'][0] == r['height'][1] else ' · page height %d → %dpx' % r['height']
        s.append('<figure><figcaption><b>%s</b> · rows %d–%d%s</figcaption><div class="row">%s</div></figure>' % (
            html.escape(name), r['y'][0], r['y'][1], hh,
            ''.join('<div><span>%s</span><img loading="lazy" src="img/%s"></div>' % (l, n) for l, n in zip(labels, r['names']))))
    return '\n'.join(s)


md = open(intended_md, encoding='utf-8').read()
summary = json.load(open(os.path.join(out, 'compare', 'summary.json')))
parts = ['<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gate 2 review</title>',
         '<style>body{margin:0;padding:24px;background:#0b0e13;color:#ebe5d8;font:15px/1.6 -apple-system,system-ui,sans-serif}'
         'h1,h2{font-weight:700}h2{margin-top:48px;border-top:1px solid #333;padding-top:20px}pre{white-space:pre-wrap;background:#10141b;padding:16px;border-radius:4px;font:13px/1.55 ui-monospace,monospace}'
         'figure{margin:22px 0}.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start}.row div{display:flex;flex-direction:column;gap:4px}'
         '.row span{font:12px ui-monospace,monospace;color:#f09a2e}.row img{border:1px solid #333;max-width:420px}figcaption{font-size:13px;color:#aaa;margin-bottom:6px}</style>',
         '<h1>Gate 2 review</h1>',
         '<p>Today\'s site (at c5efbf7) against the site built from the templates, in Chromium and WebKit at 375, 768, 1024, 1280, 1366, 1440 and 1920px, reduced motion. '
         '%d screenshots identical, %d changed.</p>' % (summary['identical'], summary['changed']),
         '<h2>Intended differences</h2><pre>%s</pre>' % html.escape(md)]
changed = [r['name'] for r in summary['shots'] if r['status'] == 'changed']
parts.append(section('Today vs. built', 'Every screenshot that changed, cropped to where it changed.',
                     [(n, os.path.join(out, 'baseline', n + '.png'), os.path.join(out, 'candidate', n + '.png')) for n in changed],
                     ['today', 'built', 'changed']))
for v in sorted(os.listdir(os.path.join(out, 'variants'))):
    vd = os.path.join(out, 'variants', v)
    pairs = [(f[:-4], os.path.join(out, 'candidate', f), os.path.join(vd, f)) for f in sorted(os.listdir(vd)) if f.endswith('.png') and os.path.exists(os.path.join(out, 'candidate', f))]
    parts.append(section('New piece: ' + v, 'Default build against the same build with this switched on (test content only).', pairs, ['default', v, 'changed']))
open(os.path.join(dest, 'index.html'), 'w', encoding='utf-8').write('\n'.join(parts))
print('review page:', os.path.join(dest, 'index.html'))
