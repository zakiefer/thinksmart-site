"""Step 6 contact sheets: the most important content variants side by side, for a person to look at.

    python3 -I edit-dev/step6/sheets.py <outDir> [browser=webkit]

Reads the screenshots matrix.js saved under <outDir>/shots/ and writes
<outDir>/sheets/step6-sheet-1-states-reviews-band.jpg   (Claim state cards, Home reviews, scrolling band)
<outDir>/sheets/step6-sheet-2-notice-seal-photo.jpg      (notice bar on phones, seal off, top photo, brightness)
"""
import os
import sys

sys.path.append('/Users/program/Library/Python/3.9/lib/python/site-packages')
from PIL import Image, ImageDraw, ImageFont  # noqa: E402

OUT = os.path.abspath(sys.argv[1])
B = sys.argv[2] if len(sys.argv) > 2 else 'webkit'
BG = (24, 26, 31)
INK = (235, 229, 216)
MUTED = (150, 150, 150)
RED = (230, 80, 70)


def font(size):
    for f in ('/System/Library/Fonts/Helvetica.ttc', '/System/Library/Fonts/Supplemental/Arial.ttf', '/Library/Fonts/Arial.ttf'):
        if os.path.exists(f):
            try:
                return ImageFont.truetype(f, size)
            except OSError:
                pass
    return ImageFont.load_default()


F_TITLE, F_HEAD, F_LAB = font(34), font(24), font(17)


def shot(kind, variant, name, browser=B):
    p = os.path.join(OUT, 'shots', kind, browser, variant, name + '.jpg')
    return p if os.path.exists(p) else None


def fit(path, w, h, crop_h=None, crop_top=0, box=None):
    """The screenshot (or the box (x0, y0, x1, y1) of it) scaled down (never up, unless it is a thin band) into w x h."""
    if not path:
        im = Image.new('RGB', (w, 60), BG)
        ImageDraw.Draw(im).text((8, 20), '(no screenshot)', font=F_LAB, fill=RED)
        return im
    im = Image.open(path).convert('RGB')
    if box:
        im = im.crop(box)
    if crop_h:
        im = im.crop((0, crop_top, im.width, min(im.height, crop_top + crop_h)))
    k = min(w / im.width, h / im.height)
    if im.height < 140:  # bands: allow enlarging up to 2x
        k = min(k, 2.0)
    else:
        k = min(k, 1.0)
    return im.resize((max(1, int(im.width * k)), max(1, int(im.height * k))), Image.LANCZOS)


class Sheet:
    def __init__(self, width, title, note):
        self.w = width
        self.parts = []
        self.y = 0
        self.head(title, F_TITLE, 70)
        self.text(note, MUTED)

    def head(self, text, f=F_HEAD, h=54):
        im = Image.new('RGB', (self.w, h), BG)
        ImageDraw.Draw(im).text((20, h - f.size - 12), text, font=f, fill=INK)
        self.parts.append(im)

    def text(self, text, color=INK, h=34):
        im = Image.new('RGB', (self.w, h), BG)
        ImageDraw.Draw(im).text((20, 8), text, font=F_LAB, fill=color)
        self.parts.append(im)

    def grid(self, rows, col_labels, cell_w, cell_h, label_w=190, gap=14):
        """rows: [(row label, [image or None, ...]), ...]; each column as wide as its widest picture."""
        n = max([len(ims) for _, ims in rows] + [len(col_labels)])
        col_w = [max([ims[i].width for _, ims in rows if i < len(ims) and ims[i] is not None] + [min(cell_w, 120)]) for i in range(n)]
        xs = [label_w + sum(col_w[:i]) + gap * i for i in range(n)]
        if col_labels:
            hdr = Image.new('RGB', (self.w, 30), BG)
            d = ImageDraw.Draw(hdr)
            for i, c in enumerate(col_labels):
                d.text((xs[i], 6), c, font=F_LAB, fill=MUTED)
            self.parts.append(hdr)
        for lab, ims in rows:
            hh = max([im.height for im in ims if im is not None] + [40])
            row = Image.new('RGB', (self.w, hh + gap), BG)
            d = ImageDraw.Draw(row)
            for k, line in enumerate(lab.split('\n')):
                d.text((20, 4 + k * 22), line, font=F_LAB, fill=INK if k == 0 else MUTED)
            for i, im in enumerate(ims):
                if im is None or xs[i] >= self.w:
                    continue
                row.paste(im, (xs[i], 0))
                d.rectangle([xs[i] - 1, 0, xs[i] + im.width, im.height], outline=(70, 74, 82))
            self.parts.append(row)

    def save(self, name):
        h = sum(p.height for p in self.parts) + 30
        out = Image.new('RGB', (self.w, h), BG)
        y = 0
        for p in self.parts:
            out.paste(p, (0, y))
            y += p.height
        os.makedirs(os.path.join(OUT, 'sheets'), exist_ok=True)
        path = os.path.join(OUT, 'sheets', name)
        out.save(path, quality=82)
        print(path, out.size)


# ------------------------------------------------------------------ sheet 1

s = Sheet(2560, f'Step 6 · sheet 1 · Claim state cards, Home reviews, scrolling band ({B})',
          'Test content only. Each picture is the part of the page the variant changes; widths are the browser window widths. Scaled down to fit.')
s.head('Claim: state cards ("Call to sign" = no e-sign link), the last card is "By phone"')
# one width per column rule: 1 across under 520px, up to 3 from 760px, up to 6 from 1372px
cells = [(375, 330, 1100), (1024, 760, 760), (1440, 1250, 640)]
rows = []
for v, lab in [('seed', 'Seed: 5 states'), ('states-1', '1 state\nIN'), ('states-6', '6 states\n+MA (call)'), ('states-9', '9 states\n+MA PA NC DC'),
               ('states-12', '12 states\n+MI WA CT'), ('states-5-call', '5 states\nOH, TN call')]:
    rows.append((lab, [fit(shot('spots', v, f'Claim-states-{w}'), cw, ch) for w, cw, ch in cells]))
s.grid(rows, ['375px (1 across)', '1024px (up to 3 across)', '1440px (up to 6 across)'], 330, 760)
s.head('Home: 12 reviews (71–594 characters; phone shows the first 3)')
rows = [('12 reviews', [fit(shot('spots', 'reviews-12', 'Home-reviews-375'), 375, 1500, crop_h=2300),
                        fit(shot('spots', 'reviews-12', 'Home-reviews-768'), 600, 1500, crop_h=2600),
                        fit(shot('spots', 'reviews-12', 'Home-reviews-1440'), 1150, 1500)])]
s.grid(rows, ['375px', '768px', '1440px'], 600, 1500, gap=20)
# the 375 cell is narrower than 600: fine
s.head('Home: scrolling words band (3, 9 = seed, 20 phrases ticked)')
for v, lab in [('phrases-3', '3 phrases'), ('seed', '9 phrases'), ('phrases-20', '20 phrases')]:
    s.grid([(lab + '\n375 / 1920', [fit(shot('spots', v, 'Home-ticker-375'), 750, 120), fit(shot('spots', v, 'Home-ticker-1920'), 1600, 120)])], [], 750, 120)
s.save('step6-sheet-1-states-reviews-band.jpg')

# ------------------------------------------------------------------ sheet 2

s = Sheet(2560, f'Step 6 · sheet 2 · notice bar, seal off, top photo, brightness ({B})',
          'Test content only. Notice rows show the top 360px of each page on a 375px phone, as a visitor first sees it.')
pages = ['Home', 'About', 'Services', 'FAQ', 'Contact', 'Claim']
s.head('Notice bar on a phone (375px): does it cover the small line above the headline?')
rows = []
for v, lab in [('seed', 'Notice off'), ('notice-storm', 'Storm'), ('notice-closed', 'Closed'), ('notice-away', 'Away\n(longest)')]:
    rows.append((lab, [fit(shot('top', v, f'{p}-375'), 375, 360, crop_h=360) for p in pages]))
s.grid(rows, pages, 375, 360, gap=18)
s.head('Notice bar, 768px tablet and 1440px laptop (away)')
rows = [('Away, 768px', [fit(shot('top', 'notice-away', f'{p}-768'), 400, 300, crop_h=380) for p in pages]),
        ('Away, 1440px', [fit(shot('top', 'notice-away', f'{p}-1440'), 400, 300, crop_h=380) for p in pages])]
s.grid(rows, pages, 400, 300, gap=18)
s.head('"Paid or Free" seal: on (seed) vs off')
spots = [('Home', 'Home-seal-1440', 'Home guarantee 1440'), ('Services', 'Services-seal-1440', 'Services bottom 1440'),
         ('Claim', 'Claim-seal-1440', 'Claim intake 1440'), ('Claim', 'Claim-confirm-1440', 'Claim confirmation 1440'),
         ('Claim', 'Claim-seal-375', 'Claim intake 375'), ('Claim', 'Claim-confirm-375', 'Claim confirmation 375')]
rows = []
for v, lab in [('seed', 'Seal on'), ('seal-off', 'Seal off')]:
    rows.append((lab, [fit(shot('spots', v, n), 380, 520) for _, n, _ in spots]))
s.grid(rows, [t for _, _, t in spots], 380, 520, gap=14)
s.head('Top of Home: storm video (seed) vs a photo (media/og-cover.jpg)')
rows = [('Video (seed)', [fit(shot('top', 'seed', 'Home-375'), 300, 650), fit(shot('top', 'seed', 'Home-1440'), 1000, 650)]),
        ('Photo', [fit(shot('top', 'top-photo', 'Home-375'), 300, 650), fit(shot('top', 'top-photo', 'Home-1440'), 1000, 650)])]
s.grid(rows, ['375px', '1440px'], 300, 650, gap=20)
s.head('Brightness: Standard / Brighter / Brightest, actual size (body text changes; headings, small lines and buttons stay)')
names = [('seed', 'Standard'), ('bright-brighter', 'Brighter'), ('bright-brightest', 'Brightest')]
s.grid([('FAQ, 1440px', [fit(shot('top', v, 'FAQ-1440'), 760, 460, box=(40, 160, 780, 600)) for v, _ in names])], [n for _, n in names], 760, 460, gap=16)
s.grid([('Claim, 375px', [fit(shot('top', v, 'Claim-375'), 375, 660, box=(0, 140, 375, 800)) for v, _ in names])], [n for _, n in names], 375, 660, gap=16)
s.save('step6-sheet-2-notice-seal-photo.jpg')
