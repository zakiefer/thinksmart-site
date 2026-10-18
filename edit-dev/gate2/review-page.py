"""Builds the Gate 2 review page (one self-contained HTML file, images inlined) from the
captures: each intended difference with before/after crops and an accept / send back choice.

    python3 review-page.py <gate2/out> <page.html>
"""
import base64
import html
import io
import json
import os
import sys

from PIL import Image, ImageChops

out, page = sys.argv[1:3]
summary = json.load(open(os.path.join(out, 'compare', 'summary.json')))
shots = {r['name']: r for r in summary['shots']}


def crop(a_path, b_path, band=None, width=440):
    """[before, after, changed] JPEG data URIs cropped to where the pair differs (or to band)."""
    a = Image.open(a_path).convert('RGB')
    b = Image.open(b_path).convert('RGB')
    w, h = min(a.width, b.width), min(a.height, b.height)
    d = ImageChops.difference(a.crop((0, 0, w, h)), b.crop((0, 0, w, h))).convert('L').point(lambda p: 255 if p > 6 else 0)
    if band:
        y0, y1 = band
    else:
        box = d.getbbox() or (0, h - 120, w, h)
        y0, y1 = max(0, box[1] - 70), min(h, box[3] + 70)
        y1 = min(y1, y0 + 900)
    out_imgs = []
    tiles = [a.crop((0, y0, w, y1)), b.crop((0, y0, w, y1))]
    red = Image.blend(tiles[1], Image.new('RGB', tiles[1].size, (0, 0, 0)), 0.6)
    red.paste(Image.new('RGB', red.size, (255, 50, 40)), (0, 0), d.crop((0, y0, w, y1)))
    tiles.append(red)
    for t in tiles:
        k = min(1.0, width / t.width)
        t = t.resize((max(1, int(t.width * k)), max(1, int(t.height * k))), Image.LANCZOS)
        buf = io.BytesIO()
        t.save(buf, 'JPEG', quality=72, optimize=True)
        out_imgs.append('data:image/jpeg;base64,' + base64.b64encode(buf.getvalue()).decode())
    return out_imgs


def ev(kind, name, caption, band=None):
    if kind == 'diff':
        a, b = os.path.join(out, 'baseline', name + '.png'), os.path.join(out, 'candidate', name + '.png')
        labels = ('Today', 'Built', 'What changed')
    else:
        a, b = os.path.join(out, 'candidate', name + '.png'), os.path.join(out, 'variants', kind, name + '.png')
        labels = ('Default', 'Switched on', 'What changed')
    if not (os.path.exists(a) and os.path.exists(b)):
        return ''
    imgs = crop(a, b, band)
    browser, pg, width = name.split('-')
    figs = ''.join('<figure><img src="%s" alt="%s, %s"><figcaption>%s</figcaption></figure>' % (src, html.escape(caption), l, l) for src, l in zip(imgs, labels))
    return '<div class="ev"><p class="evcap">%s <span>%s · %s · %spx</span></p><div class="trio">%s</div></div>' % (
        html.escape(caption), pg, browser.capitalize(), width, figs)


ITEMS = [
    ('D1', 'One state order everywhere', 'The Home page\'s name on Google, short description, share title and browser tab now list the states in the States list order (the Claim cards\' order): "IN, OH, KY, IL & TN". Today they say "IN, KY, OH, IL & TN". Adding or reordering a state updates them.', 'Not visible on the page itself; shown in the text diff (Home head and tab title).', []),
    ('D2', 'Two guarantee lengths', 'The guarantee is stored once, short and long. The short one now also starts the Paid-or-Free paragraph on Home: "If your claim isn\'t paid, our service is free. No retainers…" (today: "If your claim doesn\'t get paid, our work costs you nothing. No retainers…"). The FAQ cost answer, Home description, Home cost question and Services fine print read as today.', '', [('diff', 'chromium-Home-1440', 'Paid-or-Free paragraph'), ('diff', 'webkit-Home-375', 'Paid-or-Free paragraph on a phone')]),
    ('D3', 'Grids always fill their rows', 'Home "What we take on" uses 2 columns from 547px and 4 from 1093px (today 820–1092px shows 3 cards and a lone 4th). Contact "ways to reach us" uses 2 from 612px and 4 from 1223px (today 3 + 1 at 917–1222px). Services "What we cover" uses 2 from 656px and 3 from 983px (today 4 + 2 empty cells from 1310px). Claim state cards keep today\'s rules.', '', [('diff', 'chromium-Home-1024', 'Home services row at 1024px'), ('diff', 'chromium-Contact-1024', 'Contact cards at 1024px'), ('diff', 'webkit-Services-1440', 'What we cover at 1440px')]),
    ('D10', 'New state outlines', 'All 51 (50 states + DC) are drawn from the US Census Bureau\'s 2023 boundaries (public domain), each fitted to the same box with the originals\' soft edge, in the exact brand orange. The Claim cards use them from a new media/states/ folder; today\'s five files are untouched and can be deleted once the built site is live. Alaska leaves out the far-western Aleutians; Hawaii shows its eight main islands.', 'The outlines sit at 22% opacity behind each state card.', [('diff', 'webkit-Claim-375', 'State cards on a phone'), ('diff', 'chromium-Claim-1440', 'State cards at 1440px')]),
    ('D4', 'Reveal delays capped at 200ms', 'FAQ questions 7–10 fade in after 200ms instead of 240–360ms; Home step 4 after 200ms instead of 240ms. Longer lists never wait longer.', 'Timing only; screenshots use reduced motion. Shown in the text diff.', []),
    ('D5', 'Copyright year from the visitor\'s browser', '"© 2026" is now worked out by the page itself, so it turns to 2027 on New Year\'s Day with no rebuild. It goes through the page\'s own values, so both render passes keep it.', 'The year is drawn as its own piece of text, which moves 18 to 60 pixels of anti-aliasing in Chromium; WebKit draws it identically.', [('diff', 'chromium-FAQ-1440', 'Bottom line of the page')]),
    ('D6', 'Position rules moved out of page scripts', 'Damage cards stagger by odd/even position (same for today\'s 5). The pinned photo strip keeps the design\'s 92px clearance, and with a notice bar showing it clears down to the bar\'s real bottom edge, read from the page.', 'No visible change today.', []),
    ('D7', 'Adjuster title in the photo description', 'Jonathan\'s photo description is built from the name and title settings: "Jonathan Nelson, President & CEO of Think Smart Public Adjusting" (today "President and CEO"). Screen readers read "&" as "and".', 'Not visible; text diff only.', []),
    ('D8', 'Leftover quirks dropped', 'An internal label on the service sections uses the service name; a redundant order:0 and an unused transition are gone.', 'Not visible; text diff only.', []),
    ('D9', 'Chat box hint can be edited', 'Its phone font-size rule now targets the box itself instead of matching the hint text.', 'Not visible; text diff only.', []),
]
PIECES = [
    ('seal-off', '"Paid or Free" seal switched off', 'All 4 seals disappear and each spot still looks finished.', [('webkit-Claim-375', 'Claim, next to the photos link'), ('chromium-Home-1440', 'Home, Paid-or-Free section'), ('chromium-Services-1440', 'Services, orange band')]),
    ('notice-on', 'Storm notice switched on', 'Under the header on every page, height read from the page; pinned sections clear it.', [('webkit-Home-375', 'Home on a phone'), ('chromium-Claim-1440', 'File a Claim')]),
    ('reviews', 'Reviews with test content', 'Absent until the first confirmed review. A 4th test review without the "real customer" tick stays hidden.', [('chromium-Home-1440', 'Home, after Case closed'), ('webkit-Home-375', 'On a phone')]),
    ('brighter', 'Easier to read: Brighter', 'Body text moves toward full brightness; headings, labels, buttons and the page bottom stay as they are.', [('chromium-Home-1440', 'Home'), ('chromium-Services-1440', 'Services')]),
    ('brightest', 'Easier to read: Brightest', '', [('chromium-Home-1440', 'Home'), ('webkit-FAQ-375', 'FAQ on a phone')]),
    ('states-12', '13 states, one without an e-sign link', 'Grid fill rule, smaller Massachusetts and Pennsylvania names, a "Call to sign" card for Georgia, and Texas and Michigan dropped from the "By phone" sentence automatically.', [('chromium-Claim-1366', 'Claim at 1366px'), ('chromium-Claim-1440', 'Claim at 1440px'), ('webkit-Claim-375', 'Claim on a phone')]),
    ('phrases-3', 'Scrolling band with 3 phrases', 'Repeats until wider than any screen, at today\'s speed.', [('chromium-Home-1920', 'Band at 1920px')]),
    ('phrases-20', 'Scrolling band with 20 phrases', '', [('chromium-Home-1920', 'Band at 1920px')]),
]

body = []
for did, title, text, note, evs in ITEMS:
    evhtml = ''.join(ev(*e) for e in evs)
    body.append('''<section class="item" id="%s">
  <header><span class="id">%s</span><h3>%s</h3></header>
  <p>%s</p>%s%s
  <fieldset class="choice"><legend class="vh">%s: your answer</legend>
    <label><input type="radio" name="%s" value="accept" id="%s-a"> Accept</label>
    <label><input type="radio" name="%s" value="back" id="%s-b"> Send back</label>
  </fieldset>
</section>''' % (did, did, html.escape(title), html.escape(text), '<p class="note">%s</p>' % html.escape(note) if note else '', evhtml, did, did, did, did, did))
pieces = []
for v, title, text, evs in PIECES:
    evhtml = ''.join(ev(v, n, c) for n, c in evs)
    pieces.append('<section class="piece"><h3>%s</h3>%s%s</section>' % (html.escape(title), '<p>%s</p>' % html.escape(text) if text else '', evhtml))

ident, changed = summary['identical'], summary['changed']
doc = open(os.path.join(os.path.dirname(__file__), 'review-page.tpl.html'), encoding='utf-8').read()
doc = doc.replace('{{IDENT}}', str(ident)).replace('{{CHANGED}}', str(changed)).replace('{{TOTAL}}', str(ident + changed))
doc = doc.replace('{{ITEMS}}', '\n'.join(body)).replace('{{PIECES}}', '\n'.join(pieces))
open(page, 'w', encoding='utf-8').write(doc)
print('review page %s (%.1f MB)' % (page, os.path.getsize(page) / 1e6))
