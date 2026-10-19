"""Step 6 matrix summary: counts per variant and every problem, grouped, with screenshot paths.

    python3 -I edit-dev/step6/summarize.py <outDir>

Reads <outDir>/results.jsonl, and results-recheck.jsonl if present (ONLY=… matrix.js run): a
re-checked load replaces the first result; one that failed first and passed when re-checked is
listed as a one-off. Loads only the re-check did (a variant added later) count as ordinary loads.
"""
import json
import os
import re
import sys
from collections import OrderedDict, defaultdict

OUT = os.path.abspath(sys.argv[1])
PAGE_ORDER = ['Home', 'About', 'Services', 'FAQ', 'Contact', 'Claim', '404']


def load(name):
    p = os.path.join(OUT, name)
    return [json.loads(l) for l in open(p) if l.strip()] if os.path.exists(p) else []


first = OrderedDict((r['tag'], r) for r in load('results.jsonl'))
again = OrderedDict((r['tag'], r) for r in load('results-recheck.jsonl'))
final = OrderedDict(first)
final.update(again)
oneoffs = [(first[t], again[t]) for t in again if t in first and first[t]['problems'] and not again[t]['problems']]
rows = list(final.values())

print(f"{len(rows)} page loads: {sum(1 for r in rows if not r['problems'])} passed, {sum(1 for r in rows if r['problems'])} with problems"
      + (f" ({len(again)} of them re-checked)" if again else ''))
print()
print('variant            loads   ok  problems  pages')
variants = OrderedDict()
for r in rows:
    variants.setdefault(r['variant'], []).append(r)
for v, rs in variants.items():
    pages = sorted({r['page'] for r in rs}, key=PAGE_ORDER.index)
    print(f"{v:18} {len(rs):5} {sum(1 for r in rs if not r['problems']):4} {sum(1 for r in rs if r['problems']):9}  {' '.join(pages)}")


def norm(msg):
    """Problem text without the numbers, to group the same problem across widths."""
    return re.sub(r'-?\d+(\.\d+)?', '#', msg)


groups = defaultdict(list)
for r in rows:
    for p in r['problems']:
        groups[(p['check'], norm(p['msg']))].append((r, p))
print()
print(f'{len(groups)} distinct problems' + (f' (and {len(oneoffs)} one-off failures that passed when re-checked)' if oneoffs else ''))
for (check, _), items in sorted(groups.items(), key=lambda kv: -len(kv[1])):
    print()
    print(f'[{check}] x{len(items)}: {items[0][1]["msg"]}')
    for r, p in items[:40]:
        shot = r['shots'].get('pinned') or r['shots'].get('full') or r['shots'].get('top') or ''
        print(f"   {r['tag']:44} {p['msg'][:150]}")
        if shot:
            print(f"   {'':44} {os.path.join(OUT, shot)}")
if oneoffs:
    print()
    print('One-off failures (passed when re-checked):')
    for r, rr in oneoffs:
        print(f"   {r['tag']:44} {'; '.join(p['check'] + ': ' + p['msg'][:120] for p in r['problems'])}")

notes = defaultdict(list)
for r in rows:
    for n in r['notes']:
        notes[(n['check'], norm(n['msg']))].append((r, n))
print()
print(f'{len(notes)} kinds of notes (not failures)')
for (check, _), items in sorted(notes.items(), key=lambda kv: (kv[0][0], -len(kv[1]))):
    tags = ', '.join(r['tag'] for r, _ in items[:6]) + (' …' if len(items) > 6 else '')
    print(f'  [{check}] x{len(items)}: {items[0][1]["msg"][:160]}')
    print(f'      {tags}')
