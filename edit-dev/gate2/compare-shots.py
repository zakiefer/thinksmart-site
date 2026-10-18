"""Gate 2 comparison: today's site (baseline) vs the built site (candidate).

For every screenshot pair: identical, or how many pixels differ, where, and whether the page
height changed; a highlighted diff image for changed ones. DOM dumps are diffed as text.
Writes <out>/summary.json and <out>/diffs/*.

    python3 -I compare-shots.py <baselineDir> <candidateDir> <outDir>
"""
import difflib
import json
import os
import sys

from PIL import Image, ImageChops

base, cand, out = sys.argv[1:4]
os.makedirs(os.path.join(out, 'diffs'), exist_ok=True)
names = sorted(f[:-4] for f in os.listdir(base) if f.endswith('.png'))
rows = []
for name in names:
    a_path, b_path = os.path.join(base, name + '.png'), os.path.join(cand, name + '.png')
    if not os.path.exists(b_path):
        rows.append({'name': name, 'status': 'missing'})
        continue
    a, b = Image.open(a_path).convert('RGB'), Image.open(b_path).convert('RGB')
    row = {'name': name, 'size': [a.size, b.size]}
    h = min(a.height, b.height)
    w = min(a.width, b.width)
    da = a.crop((0, 0, w, h))
    db = b.crop((0, 0, w, h))
    diff = ImageChops.difference(da, db).convert('L').point(lambda p: 255 if p > 6 else 0)
    hist = diff.histogram()
    changed = hist[255]
    box = diff.getbbox()
    row['pixels'] = changed
    row['box'] = box
    row['height'] = [a.height, b.height]
    if changed == 0 and a.size == b.size:
        row['status'] = 'identical'
    else:
        row['status'] = 'changed'
        # red where pixels differ, over a dimmed candidate
        over = Image.blend(db, Image.new('RGB', db.size, (0, 0, 0)), 0.55)
        red = Image.new('RGB', db.size, (255, 40, 40))
        over.paste(red, (0, 0), diff)
        over.save(os.path.join(out, 'diffs', name + '.diff.png'))
    rows.append(row)

# DOM text diffs (one per page/browser at 1440 and 375: hidden parts differ by width)
doms = []
for name in names:
    if not (name.endswith('-1440') or name.endswith('-375')):
        continue
    a = open(os.path.join(base, name + '.dom.txt'), encoding='utf-8').read().splitlines()
    bp = os.path.join(cand, name + '.dom.txt')
    if not os.path.exists(bp):
        continue
    b = open(bp, encoding='utf-8').read().splitlines()
    d = list(difflib.unified_diff(a, b, 'today', 'built', n=1, lineterm=''))
    if d:
        open(os.path.join(out, 'diffs', name + '.dom.diff'), 'w', encoding='utf-8').write('\n'.join(d) + '\n')
    doms.append({'name': name, 'changedLines': sum(1 for x in d if x[:1] in '+-' and x[:3] not in ('+++', '---'))})

summary = {
    'shots': rows,
    'dom': doms,
    'identical': sum(1 for r in rows if r['status'] == 'identical'),
    'changed': sum(1 for r in rows if r['status'] == 'changed'),
    'missing': sum(1 for r in rows if r['status'] == 'missing'),
}
json.dump(summary, open(os.path.join(out, 'summary.json'), 'w'), indent=1)
print('%d identical, %d changed, %d missing' % (summary['identical'], summary['changed'], summary['missing']))
for r in rows:
    if r['status'] == 'changed':
        print('  %-28s %8d px  box=%s  height %s' % (r['name'], r['pixels'], r['box'], r['height']))
for d in doms:
    if d['changedLines']:
        print('  DOM %-24s %d lines' % (d['name'], d['changedLines']))
