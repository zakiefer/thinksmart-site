// Step 6 content-variant matrix: every variant site (from variants.php) at every width in
// Chromium and WebKit, with automatic checks and screenshots of the spots each variant changes.
//
//   php edit-dev/step6/variants.php <outDir>
//   node edit-dev/step6/matrix.js <outDir> [browsers=chromium,webkit] [variants=a,b|all] [pages=…|all] [widths=…|all] [workers per browser=2]
//
// ONLY=<browser>/<variant>/<Page>-<width>,… re-checks just those loads (results go to
// <outDir>/results-recheck.jsonl and summary-recheck.json instead).
// Serves <outDir>/sites/<variant>/ itself on port 8795 (http://127.0.0.1:8795/v/<variant>/Home.dc.html).
// Pages a variant doesn't change (byte-identical to the seed build) aren't re-checked for it.
// Writes <outDir>/results.jsonl (one line per page load), <outDir>/summary.json, and
// screenshots under <outDir>/shots/ (JPEG): top/ (first screen of every load), spots/
// (the parts each variant changes), fail/ (full page, only when a check failed).
// Same capture settings as edit-dev/browser/capture.js: reduced motion, fonts ready, network
// idle, every image decoded, 1.3 s settle; 375px runs as a phone (touch, coarse pointer, no
// scrollbar), the other widths as desktop windows. Only the site itself and Google Fonts load
// (fonts are fetched once per run and served from memory afterwards). Sizes are checked
// against the layout width (WebKit desktop draws Home's styled 10px scrollbar inside the window).
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');

const PORT = 8795;
const PAGES = ['Home', 'About', 'Services', 'FAQ', 'Contact', 'Claim', '404'];
const SIZES = { 375: 812, 768: 1024, 1024: 768, 1280: 800, 1366: 768, 1440: 900, 1920: 1080 };
const fileOf = (p) => (p === '404' ? '404.html' : p + '.dc.html');

const [outArg, browsersArg = 'chromium,webkit', variantsArg = 'all', pagesArg = 'all', widthsArg = 'all', workersArg = '2'] = process.argv.slice(2);
if (!outArg) { console.error('usage: node matrix.js <outDir> [browsers] [variants] [pages] [widths] [workers]'); process.exit(2); }
const OUT = path.resolve(outArg);
const index = JSON.parse(fs.readFileSync(path.join(OUT, 'variants.json'), 'utf8'));
const browsers = browsersArg.split(',');
const variants = variantsArg === 'all' ? Object.keys(index) : variantsArg.split(',');
const pagesWanted = pagesArg === 'all' ? PAGES : pagesArg.split(',');
const widths = widthsArg === 'all' ? Object.keys(SIZES).map(Number) : widthsArg.split(',').map(Number);
const WORKERS = Math.max(1, Number(workersArg) || 1);
const ONLY = process.env.ONLY ? new Set(process.env.ONLY.split(',').map((x) => x.trim()).filter(Boolean)) : null;
const RESULTS = ONLY ? 'results-recheck.jsonl' : 'results.jsonl';
const SUMMARY = ONLY ? 'summary-recheck.json' : 'summary.json';

// ------------------------------------------------------------------ static server (all variants)

const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.webp': 'image/webp', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.mp4': 'video/mp4', '.pdf': 'application/pdf', '.xml': 'application/xml', '.json': 'application/json', '.css': 'text/css' };
const REPO = path.resolve(__dirname, '../..');
function startServer() {
  const sites = path.join(OUT, 'sites');
  const server = http.createServer((req, res) => {
    let p = decodeURIComponent(new URL(req.url, 'http://x').pathname);
    if (p.endsWith('/send-lead.php')) { res.writeHead(403); res.end('not in tests'); return; } // never reached: no form is sent
    let file;
    const m = /^\/v\/([a-z0-9-]+)(\/.*)$/.exec(p);
    if (m) file = path.join(sites, m[1], path.normalize(m[2] === '/' ? '/Home.dc.html' : m[2]));
    else if (/^\/(media|docs|vendor)\//.test(p) || p === '/support.js') file = path.join(REPO, path.normalize(p)); // root-absolute asset links
    else { res.writeHead(404); res.end('not found'); return; }
    if (!file.startsWith(sites) && !file.startsWith(REPO)) { res.writeHead(403); res.end(); return; }
    fs.readFile(file, (err, data) => {
      if (err) { res.writeHead(404); res.end('not found'); return; }
      res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
      res.end(data);
    });
  });
  return new Promise((ok, bad) => { server.once('error', bad); server.listen(PORT, '127.0.0.1', () => ok(server)); });
}
const BASE = `http://127.0.0.1:${PORT}/v/`;

// ------------------------------------------------------------------ Google Fonts, fetched once

const fontCache = new Map();
let fontFetches = 0;
async function routeFonts(route, which) {
  const url = route.request().url();
  const key = which + ' ' + url;
  try {
    if (!fontCache.has(key)) {
      fontCache.set(key, (async () => {
        fontFetches++;
        const r = await route.fetch();
        return { status: r.status(), headers: r.headers(), body: await r.body() };
      })());
    }
    const hit = await fontCache.get(key);
    await route.fulfill({ status: hit.status, headers: hit.headers, body: hit.body });
  } catch (e) {
    fontCache.delete(key);
    await route.abort().catch(() => {});
  }
}

// ------------------------------------------------------------------ settle (as capture.js)

async function settle(page) {
  await page.waitForLoadState('load');
  await page.waitForFunction(() => {
    const r = document.getElementById('dc-root');
    return !document.querySelector('x-dc') || (r && r.querySelector('footer, main, form, [data-ref]'));
  }, null, { timeout: 20000 }).catch(() => {});
  await page.evaluate(() => document.fonts && document.fonts.ready);
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(700); // support.js's second pass re-renders from the raw file
  await page.evaluate(() => Promise.all(Array.from(document.images).map((i) => (i.decode ? i.decode().catch(() => {}) : null))));
  await page.waitForTimeout(1300); // WebKit's high-quality image scaling pass
}

// ------------------------------------------------------------------ checks run inside the page

/* eslint-disable no-undef */
function pageChecks(opts) {
  // the layout width: WebKit in desktop mode draws Home's styled 10px scrollbar inside the window
  const vw = Math.min(window.innerWidth, document.documentElement.clientWidth);
  const vh = window.innerHeight;
  const R = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: +r.left.toFixed(1), y: +r.top.toFixed(1), w: +r.width.toFixed(1), h: +r.height.toFixed(1), r: +r.right.toFixed(1), b: +r.bottom.toFixed(1) }; };
  const where = (el) => {
    const bits = [];
    for (let a = el; a && a !== document.body && bits.length < 3; a = a.parentElement) {
      const lab = a.getAttribute && (a.getAttribute('data-ref') || a.getAttribute('data-screen-label') || a.id);
      if (lab) bits.push(lab);
    }
    const t = (el.innerText || el.getAttribute('src') || '').replace(/\s+/g, ' ').trim().slice(0, 50);
    return el.tagName.toLowerCase() + (bits.length ? ' in ' + bits.join(' < ') : '') + (t ? ' "' + t + '"' : '');
  };
  const shown = (el) => { if (!el) return false; const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); return cs.display !== 'none' && cs.visibility !== 'hidden' && +cs.opacity > 0.05 && r.width > 0 && r.height > 0; };
  const lines = (el) => { const cs = getComputedStyle(el); const lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.2; return Math.max(1, Math.round(el.getBoundingClientRect().height / lh)); };
  const res = { problems: [], notes: [], m: {} };
  const bad = (check, msg) => res.problems.push({ check, msg });
  const note = (check, msg) => res.notes.push({ check, msg });
  const se = document.scrollingElement || document.documentElement;

  // horizontal scrolling
  res.m.scrollWidth = se.scrollWidth;
  res.m.layoutWidth = vw;
  if (se.scrollWidth > vw) {
    const offenders = [];
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (!r.width || !r.height || r.right <= vw + 1) continue;
      let clipped = false;
      for (let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
        const cs = getComputedStyle(a);
        if (/hidden|clip|auto|scroll/.test(cs.overflowX) || /hidden|clip/.test(cs.overflow)) { const ar = a.getBoundingClientRect(); if (ar.right <= vw + 1 && ar.left >= -1) { clipped = true; break; } }
      }
      if (!clipped) offenders.push(where(el) + ` right=${Math.round(r.right)}`);
      if (offenders.length >= 6) break;
    }
    bad('overflow-x', `page scrolls sideways: scrollWidth ${se.scrollWidth} > ${vw}` + (offenders.length ? ` (${offenders.join(' | ')})` : ''));
  }

  // images
  const broken = Array.from(document.images).filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.getAttribute('src'));
  if (broken.length) bad('images', 'not loaded: ' + broken.join(', '));
  res.m.images = document.images.length;

  // web fonts really in use (fallback fonts would make every size check meaningless)
  if (document.fonts) {
    const want = opts.page === '404' ? ['800 40px "Big Shoulders Display"'] : ['800 40px "Big Shoulders Display"', '400 12px "IBM Plex Mono"', '400 16px "Archivo"'];
    const missing = want.filter((f) => !document.fonts.check(f));
    if (missing.length) bad('fonts', 'web fonts not loaded: ' + missing.join(', '));
  }

  // header + notice bar at the top of the page
  const nav = document.querySelector('header[data-ref="nav"]');
  const bar = document.querySelector('[data-ref="notice"]');
  res.m.nav = R(nav);
  if (opts.notice && opts.page !== '404') {
    if (!bar || !shown(bar)) bad('notice', 'notice bar is switched on but not showing');
    else {
      const b = R(bar);
      const n = R(nav);
      res.m.bar = b;
      res.m.barText = bar.innerText.replace(/\s+/g, ' ').trim();
      res.m.barLines = lines(bar.querySelector('span') || bar);
      if (n && b.y < n.b - 1) bad('notice', `bar (top ${b.y}) is partly behind the header (bottom ${n.b})`);
      if (n && b.y > n.b + 1) note('notice', `gap of ${(b.y - n.b).toFixed(1)}px between the header and the bar`);
      if (bar.scrollWidth > bar.clientWidth + 1) bad('notice', 'bar text is cut off at the side');
      // the first words of the page and its headline must not sit under the bar
      const main = document.querySelector('main');
      const h1 = main && main.querySelector('h1');
      if (h1) {
        const hr = R(h1);
        res.m.h1 = hr;
        if (hr.y < b.b - 1 && hr.b > 0) bad('notice', `bar (bottom ${b.b}) covers the top of the page headline (top ${hr.y})`);
        // the small line above the headline (first text in the headline's block)
        const sec = h1.closest('section');
        const walker = document.createTreeWalker(sec || main, NodeFilter.SHOW_TEXT);
        let first = null;
        for (let t = walker.nextNode(); t; t = walker.nextNode()) {
          if (!t.nodeValue.trim()) continue;
          const el = t.parentElement;
          if (!shown(el)) continue;
          const r = el.getBoundingClientRect();
          if (r.bottom <= 0 || r.top >= vh) continue;
          first = el; break;
        }
        if (first && first !== h1 && !h1.contains(first)) {
          const fr = R(first);
          res.m.firstText = fr;
          if (fr.y < b.b - 1 && fr.b > b.y) bad('notice', `bar (bottom ${b.b}) covers the small line above the headline ("${first.innerText.trim().slice(0, 40)}", top ${fr.y}, ${Math.round(Math.min(fr.b, b.b) - fr.y)}px hidden)`);
          else if (fr.y - b.b < 8) note('notice-tight', `the small line above the headline ("${first.innerText.trim().slice(0, 30)}") sits ${(fr.y - b.b).toFixed(1)}px under the bar`);
        }
      }
    }
  } else if (bar && shown(bar)) bad('notice', 'a notice bar shows although the notice is off');

  // state cards: full rows, names not clipped
  const grid = (sel, kind) => {
    const g = document.querySelector(sel);
    if (!g) return null;
    const cs = getComputedStyle(g);
    const gr = g.getBoundingClientRect();
    const left = gr.left + parseFloat(cs.borderLeftWidth) + parseFloat(cs.paddingLeft);
    const right = gr.right - parseFloat(cs.borderRightWidth) - parseFloat(cs.paddingRight);
    const kids = Array.from(g.children).filter((k) => getComputedStyle(k).display !== 'none');
    const rows = new Map();
    kids.forEach((k) => { const r = k.getBoundingClientRect(); const key = Math.round(r.top); if (!rows.has(key)) rows.set(key, []); rows.get(key).push(r); });
    const cols = cs.gridTemplateColumns.split(' ').filter(Boolean).length;
    const out = { cards: kids.length, cols, rows: rows.size, perRow: Array.from(rows.values()).map((r) => r.length) };
    let i = 0;
    for (const rs of rows.values()) {
      i++;
      const l = Math.min(...rs.map((r) => r.left));
      const rr = Math.max(...rs.map((r) => r.right));
      if (l > left + 1.5 || rr < right - 1.5) bad(kind, `row ${i} of ${rows.size} isn't full (${rs.length} card${rs.length > 1 ? 's' : ''}, ${Math.round(right - rr)}px empty at the end) — ${kids.length} cards in ${cols} columns`);
    }
    return { el: g, kids, info: out };
  };
  const sg = grid('[data-grid="states"]', 'state-grid');
  if (sg) {
    res.m.states = sg.info;
    const names = [];
    sg.kids.forEach((card) => {
      const spans = card.querySelectorAll(':scope > span');
      if (spans.length < 3) return;
      const [tag, name, btn] = spans;
      const cr = card.getBoundingClientRect();
      const ccs = getComputedStyle(card);
      const innerR = cr.right - parseFloat(ccs.paddingRight);
      const t = name.innerText.trim();
      const nr = name.getBoundingClientRect();
      // the widest word, measured on its own
      const probe = document.createElement('span');
      probe.style.cssText = 'position:absolute; visibility:hidden; white-space:nowrap; font:' + getComputedStyle(name).font + '; text-transform:uppercase; letter-spacing:' + getComputedStyle(name).letterSpacing;
      document.body.appendChild(probe);
      let widest = 0;
      for (const w of t.split(/\s+/)) { probe.textContent = w; widest = Math.max(widest, probe.getBoundingClientRect().width); }
      probe.remove();
      const room = innerR - nr.left;
      names.push({ name: t, font: getComputedStyle(name).fontSize, lines: lines(name), widest: +widest.toFixed(1), room: +room.toFixed(1), btnLines: lines(btn), tag: tag.innerText.trim() });
      if (name.scrollWidth > name.clientWidth + 1 || widest > room + 0.5) bad('state-names', `"${t}" is cut off (needs ${widest.toFixed(1)}px, card has ${room.toFixed(1)}px)`);
      const isState = card.tagName === 'A';
      if (btn.scrollWidth > btn.clientWidth + 1 || btn.getBoundingClientRect().right > innerR + 1) bad('state-names', `"${btn.innerText.trim().slice(0, 40)}" sticks out of the ${t} card`);
      else if (isState && lines(btn) > 1) note('state-names', `"${btn.innerText.trim()}" wraps onto ${lines(btn)} lines in the ${t} card`);
      if (lines(name) > 2) note('state-names', `"${t}" takes ${lines(name)} lines`);
    });
    res.m.stateCards = names;
  }

  // reviews
  const rg = grid('[data-grid="reviews"]', 'reviews');
  if (rg) {
    res.m.reviews = rg.info;
    rg.kids.forEach((fig, k) => {
      for (const el of fig.querySelectorAll('blockquote, figcaption span, span[role="img"]')) {
        if (el.scrollWidth > el.clientWidth + 1) bad('reviews', `review ${k + 1}: "${el.innerText.trim().slice(0, 30)}…" is cut off at the side`);
      }
      if (fig.scrollHeight > fig.clientHeight + 1) bad('reviews', `review ${k + 1} is taller than its card`);
    });
    const hs = rg.kids.map((f) => f.getBoundingClientRect().height);
    res.m.reviewHeights = [Math.round(Math.min(...hs)), Math.round(Math.max(...hs))];
    if (Math.max(...hs) > vh * 1.6) note('reviews', `tallest review card is ${Math.round(Math.max(...hs))}px (${(Math.max(...hs) / vh).toFixed(1)} screens)`);
  }
  if (opts.page === 'Home') {
    const sec = document.getElementById('reviews');
    if (opts.reviews > 0 && !sec) bad('reviews', `${opts.reviews} reviews in the content but no reviews section`);
    if (opts.reviews === 0 && sec) bad('reviews', 'reviews section shows with no reviews');
    if (rg && rg.kids.length !== opts.reviews) bad('reviews', `${rg.kids.length} review cards for ${opts.reviews} reviews`);
  }

  // scrolling words band
  const tk = document.querySelector('[data-ref="ticker"]');
  if (tk) {
    const halves = Array.from(tk.children).map((x) => x.getBoundingClientRect().width);
    const band = tk.parentElement.getBoundingClientRect();
    const words = tk.firstElementChild ? Math.floor(tk.firstElementChild.children.length / 2) : 0;
    res.m.ticker = { halves: halves.map((x) => Math.round(x)), words, duration: getComputedStyle(tk).animationDuration, band: [Math.round(band.left), Math.round(band.width)] };
    if (halves.length !== 2) bad('ticker', `expected 2 halves, found ${halves.length}`);
    // the loop moves by one half: each half must be at least a screen wide or an empty stretch shows
    else if (Math.min(...halves) < vw) bad('ticker', `each half is ${Math.round(Math.min(...halves))}px, narrower than the ${vw}px screen: an empty stretch scrolls into view`);
    if (Math.abs(halves[0] - halves[1]) > 1) bad('ticker', `halves differ (${halves.map(Math.round).join(' vs ')}): the loop jumps`);
    if (band.left > 0.5 || band.width < vw - 0.5) bad('ticker', `band is ${Math.round(band.width)}px wide at x=${Math.round(band.left)}`);
  }

  // the "Paid or Free" seal
  const seals = Array.from(document.querySelectorAll('img[src*="seal"]'));
  res.m.seals = seals.length;
  if (!opts.seal && seals.length) bad('seal', `seal switched off but ${seals.length} seal image(s) remain`);
  if (opts.seal && ['Home', 'Services', 'Claim'].includes(opts.page) && !seals.length) bad('seal', 'seal switched on but missing');
  const spots = [];
  if (opts.page === 'Home') spots.push(document.getElementById('guarantee'));
  if (opts.page === 'Services') spots.push(document.querySelector('[data-screen-label="Services CTA"]'));
  if (opts.page === 'Claim') { const a = Array.from(document.querySelectorAll('main a')).find((x) => /SUBMIT PHOTOS/i.test(x.innerText)); if (a) spots.push(a.parentElement); }
  for (const s of spots.filter(Boolean)) {
    // an empty box: no words, no picture, but big enough to see and drawn (background, border, shadow, filter)
    for (const el of [s, ...s.querySelectorAll('*')]) {
      if (el.innerText && el.innerText.trim()) continue;
      if (el.matches('img, svg, video, canvas, picture') || el.querySelector('img, svg, video, canvas, picture')) continue;
      const r = el.getBoundingClientRect();
      if (r.width < 8 || r.height < 8) continue;
      const cs = getComputedStyle(el);
      const drawn = (cs.backgroundColor && !/rgba\(0, 0, 0, 0\)|transparent/.test(cs.backgroundColor)) || parseFloat(cs.borderTopWidth) > 0 || cs.boxShadow !== 'none' || cs.filter !== 'none' || cs.backgroundImage !== 'none';
      if (drawn) bad('seal', `empty box left where the seal was: ${where(el)} ${Math.round(r.width)}×${Math.round(r.height)}`);
    }
  }

  // top of Home: photo instead of the video
  if (opts.page === 'Home') {
    const hv = document.querySelector('[data-ref="heroVid"]');
    const img = hv && hv.querySelector('img');
    const vid = hv && hv.querySelector('video');
    if (opts.topPhoto) {
      if (!img || !(img.complete && img.naturalWidth > 0)) bad('top-photo', 'the photo at the top of Home did not load');
      else {
        const r = img.getBoundingClientRect();
        res.m.topPhoto = { natural: [img.naturalWidth, img.naturalHeight], shown: [Math.round(r.width), Math.round(r.height)], scale: +(Math.max(r.width / img.naturalWidth, r.height / img.naturalHeight)).toFixed(2) };
        if (res.m.topPhoto.scale > 1.5) note('top-photo', `photo is ${img.naturalWidth}×${img.naturalHeight} shown at ${Math.round(r.width)}×${Math.round(r.height)} (${res.m.topPhoto.scale}× enlarged: soft)`);
      }
      if (vid) bad('top-photo', 'the storm video is still there');
    } else if (!vid) bad('top-photo', 'storm video missing');
  }

  // the chat button sits inside the screen
  const chat = document.querySelector('[data-ref="chatLauncher"]');
  if (opts.page !== '404') {
    if (!chat || !shown(chat)) bad('chat', 'chat button not showing');
    else { const c = R(chat); if (c.r > vw + 0.5 || c.b > vh + 0.5 || c.x < 0) bad('chat', 'chat button is off the screen'); }
  }
  return res;
}
/* eslint-enable no-undef */

// after scrolling: header and bar move together; pinned panels clear the bar
async function scrolledChecks(page, opts) {
  return page.evaluate(async (o) => {
    const res = { problems: [], notes: [], m: {} };
    const bad = (check, msg) => res.problems.push({ check, msg });
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const frames = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    const R = (el) => { const r = el.getBoundingClientRect(); return { y: +r.top.toFixed(1), b: +r.bottom.toFixed(1), h: +r.height.toFixed(1) }; };
    const nav = document.querySelector('header[data-ref="nav"]');
    const bar = document.querySelector('[data-ref="notice"]');
    window.scrollTo(0, 700);
    await wait(300); await frames();
    if (bar && nav) {
      // the bar follows the header's height a frame later; give it up to 2 s on a busy machine
      let n; let b; const t0 = Date.now();
      do { await frames(); n = R(nav); b = R(bar); } while (Math.abs(b.y - n.b) > 1 && Date.now() - t0 < 2000 && await wait(50) === undefined);
      res.m.scrolled = { nav: n, bar: b, settledMs: Date.now() - t0 };
      if (b.y < n.b - 1) bad('notice', `after scrolling, the bar (top ${b.y}) is partly behind the header (bottom ${n.b})`);
      if (b.y > n.b + 1) bad('notice', `after scrolling, a ${(b.y - n.b).toFixed(1)}px gap stays between the header and the bar`);
    }
    // pinned panels: Home's photo strip, the Claim intake column
    const clearTo = bar ? bar.getBoundingClientRect().bottom : (nav ? nav.getBoundingClientRect().bottom : 0);
    const strip = document.querySelector('[data-ref="stripWrap"]');
    if (strip) {
      window.scrollTo(0, window.scrollY + strip.getBoundingClientRect().top + 40);
      await wait(500); await frames();
      const panel = strip.firstElementChild;
      const head = panel && panel.firstElementChild;
      if (head && getComputedStyle(panel).position === 'sticky') {
        const hr = head.getBoundingClientRect();
        const cl = bar ? bar.getBoundingClientRect().bottom : clearTo;
        res.m.strip = { headTop: Math.round(hr.top), clear: Math.round(cl), scrollY: Math.round(window.scrollY) };
        if (hr.top < cl - 1) bad(bar ? 'notice' : 'pinned', `pinned photo strip: its heading (top ${Math.round(hr.top)}) is under the ${bar ? 'notice bar' : 'header'} (bottom ${Math.round(cl)})`);
      }
    }
    if (o.page === 'Claim') {
      const h1 = document.querySelector('main h1');
      const col = h1 && h1.closest('div[style*="sticky"]');
      if (col) {
        const sec = col.closest('section');
        window.scrollTo(0, window.scrollY + sec.getBoundingClientRect().top + 400);
        await wait(500); await frames();
        const cr = col.getBoundingClientRect();
        const cl = bar ? bar.getBoundingClientRect().bottom : (nav ? nav.getBoundingClientRect().bottom : 0);
        const stickTop = parseFloat(getComputedStyle(col).top) || 0;
        const stuck = Math.abs(cr.top - stickTop) < 2;
        res.m.claimCol = { top: Math.round(cr.top), stickTop: Math.round(stickTop), stuck, clear: Math.round(cl) };
        if (stuck && cr.top < cl - 1) bad(bar ? 'notice' : 'pinned', `pinned intake column (top ${Math.round(cr.top)}) is under the ${bar ? 'notice bar' : 'header'} (bottom ${Math.round(cl)})`);
      }
    }
    window.scrollTo(0, 0);
    await wait(300);
    const se = document.scrollingElement || document.documentElement;
    const lw = Math.min(window.innerWidth, document.documentElement.clientWidth);
    if (se.scrollWidth > lw) bad('overflow-x', `after scrolling through, the page scrolls sideways: scrollWidth ${se.scrollWidth} > ${lw}`);
    return res;
  }, opts);
}

// show the Claim confirmation without sending anything: set the page's own state
async function showConfirmation(page) {
  return page.evaluate(() => {
    for (const el of document.querySelectorAll('form, main *')) {
      const key = Object.keys(el).find((k) => k.startsWith('__reactFiber$'));
      if (!key) continue;
      for (let f = el[key]; f; f = f.return) {
        const sn = f.stateNode;
        if (sn && sn.logic && sn.logic.state && 'submitted' in sn.logic.state) { sn.logic.setState({ submitted: true, caseNo: 'TS-STEP6-TEST' }); return true; }
      }
    }
    return false;
  });
}

// ------------------------------------------------------------------ one page load

function variantOpts(name, page) {
  const c = JSON.parse(fs.readFileSync(path.join(OUT, 'content', name + '.json'), 'utf8'));
  const reviews = (c.home.reviews.items || []).filter((r) => !r.hidden && r.real && String(r.text || '').trim() && String(r.name || '').trim()).length;
  return { page, notice: !!c.notice.on, seal: c.site.seal !== false, reviews, topPhoto: !!(c.home.top.video && c.home.top.video.photo), brightness: c.site.brightness };
}

const OVERLAYS_OFF = 'header[data-ref="nav"], [data-ref="notice"], [data-ref="chatLauncher"], [data-ref="chatPanel"] { visibility: hidden !important; }';
async function jpeg(target, file, opts = {}) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await target.screenshot({ path: file, type: 'jpeg', quality: 72, ...opts });
  return path.relative(OUT, file);
}

async function onePage(page, which, variant, pageName, w, sink) {
  const t0 = Date.now();
  const opts = variantOpts(variant, pageName);
  const tag = `${which}/${variant}/${pageName}-${w}`;
  sink.errors.length = 0; sink.warnings.length = 0; sink.failed.length = 0;
  await page.goto(BASE + variant + '/' + fileOf(pageName), { waitUntil: 'domcontentloaded' });
  await settle(page);
  const r = await page.evaluate(pageChecks, opts);
  const shots = {};
  shots.top = await jpeg(page, path.join(OUT, 'shots/top', which, variant, `${pageName}-${w}.jpg`));
  const spot = async (key, sel, before) => {
    const loc = typeof sel === 'string' ? page.locator(sel).first() : sel();
    if (!(await loc.count())) return;
    if (before) await before();
    await loc.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(450);
    // the fixed header, notice bar and chat button would sit on top of the part being shot
    shots[key] = await jpeg(loc, path.join(OUT, 'shots/spots', which, variant, `${pageName}-${key}-${w}.jpg`), { style: OVERLAYS_OFF }).catch((e) => { r.notes.push({ check: 'shot', msg: key + ': ' + e.message.split('\n')[0] }); });
  };
  if (pageName === 'Claim' && /^(seed|states|max)/.test(variant)) await spot('states', '[data-grid="states"]');
  if (pageName === 'Home' && /^(reviews|max)/.test(variant)) await spot('reviews', '#reviews');
  if (pageName === 'Home' && /^(seed|phrases|max)/.test(variant)) await spot('ticker', '[data-screen-label="Ticker"]');
  if (/^(seed|seal)/.test(variant)) {
    if (pageName === 'Home') await spot('seal', '#guarantee');
    if (pageName === 'Services') await spot('seal', '[data-screen-label="Services CTA"]');
    if (pageName === 'Claim') await spot('seal', () => page.locator('main a', { hasText: 'SUBMIT PHOTOS' }).first().locator('xpath=../..'));
  }
  if (variant === 'states-12-auto') {
    if (pageName === 'Contact') await spot('rows', '[data-screen-label="Message Form"]');
    await spot('footer', 'footer');
  }
  // after-scroll checks (header + bar, pinned panels) for every load
  const s = await scrolledChecks(page, opts);
  r.problems.push(...s.problems); r.notes.push(...s.notes); Object.assign(r.m, s.m);
  if (s.problems.some((p) => /pinned photo strip/.test(p.msg)) && s.m.strip) {
    await page.evaluate((y) => window.scrollTo(0, y), s.m.strip.scrollY);
    await page.waitForTimeout(500);
    shots.pinned = await jpeg(page, path.join(OUT, 'shots/fail', which, variant, `${pageName}-${w}-pinned.jpg`));
    await page.evaluate(() => window.scrollTo(0, 0));
  }
  if (opts.notice && pageName !== '404') shots.scrolled = await (async () => { await page.evaluate(() => window.scrollTo(0, 700)); await page.waitForTimeout(400); const f = await jpeg(page, path.join(OUT, 'shots/spots', which, variant, `${pageName}-scrolled-${w}.jpg`), { clip: { x: 0, y: 0, width: w, height: Math.min(SIZES[w], 420) } }); await page.evaluate(() => window.scrollTo(0, 0)); return f; })();
  // the Claim confirmation (its seal spot), shown by setting the page's state; nothing is sent
  if (pageName === 'Claim' && /^(seed|seal)/.test(variant)) {
    if (await showConfirmation(page)) {
      await page.waitForSelector('[data-ref="caseConfirm"]', { timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(500);
      const c = await page.evaluate((o) => {
        const box = document.querySelector('[data-ref="caseConfirm"]');
        if (!box) return { problems: [{ check: 'seal', msg: 'confirmation did not show' }] };
        const seals = box.querySelectorAll('img[src*="seal"]').length;
        const p = [];
        if (o.seal && !seals) p.push({ check: 'seal', msg: 'confirmation: seal missing' });
        if (!o.seal && seals) p.push({ check: 'seal', msg: 'confirmation: seal still shows' });
        // room kept for the seal: an empty band at the bottom of the card when the seal is off
        const kids = Array.from(box.children).filter((k) => getComputedStyle(k).position !== 'absolute');
        const last = kids[kids.length - 1];
        if (last) {
          const band = box.getBoundingClientRect().bottom - last.getBoundingClientRect().bottom;
          const pt = parseFloat(getComputedStyle(box).paddingTop);
          if (!o.seal && band > pt + 40) p.push({ check: 'seal', msg: `confirmation: ${Math.round(band)}px of empty space at the bottom of the card (padding kept for the seal; top padding is ${Math.round(pt)}px)` });
        }
        for (const el of box.querySelectorAll('*')) {
          if ((el.innerText || '').trim() || el.matches('img, svg') || el.querySelector('img, svg')) continue;
          const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
          if (r.width >= 8 && r.height >= 8 && (cs.filter !== 'none' || cs.boxShadow !== 'none' || parseFloat(cs.borderTopWidth) > 0)) p.push({ check: 'seal', msg: `confirmation: empty box ${el.tagName.toLowerCase()} ${Math.round(r.width)}×${Math.round(r.height)}` });
        }
        return { problems: p };
      }, opts);
      r.problems.push(...c.problems);
      await spot('confirm', '[data-ref="caseConfirm"]');
    } else r.notes.push({ check: 'seal', msg: 'could not show the confirmation (page state not found)' });
  }
  // page errors and failed requests
  for (const e of sink.errors) r.problems.push({ check: 'errors', msg: e });
  for (const e of sink.warnings) r.notes.push({ check: 'runtime-warning', msg: e });
  for (const f of sink.failed) r.problems.push({ check: 'requests', msg: f });
  if (r.problems.length) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(300);
    shots.full = await jpeg(page, path.join(OUT, 'shots/fail', which, variant, `${pageName}-${w}.jpg`), { fullPage: true, quality: 60 }).catch((e) => { r.notes.push({ check: 'shot', msg: 'full page: ' + e.message.split('\n')[0] }); });
  }
  return { tag, browser: which, variant, page: pageName, width: w, height: SIZES[w], ms: Date.now() - t0, problems: r.problems, notes: r.notes, m: r.m, shots };
}

// ------------------------------------------------------------------ workers

async function worker(which, queue, results, log) {
  const browser = await pw[which].launch();
  try {
    for (let g = queue.shift(); g; g = queue.shift()) {
      const phone = g.w < 600;
      const ctx = await browser.newContext({ viewport: { width: g.w, height: SIZES[g.w] }, reducedMotion: 'reduce', deviceScaleFactor: 1, isMobile: phone, hasTouch: phone });
      await ctx.route('**/*', (route) => {
        const u = route.request().url();
        if (u.startsWith(`http://127.0.0.1:${PORT}/`)) return route.continue();
        if (u.includes('fonts.googleapis.com') || u.includes('fonts.gstatic.com')) return routeFonts(route, which);
        return route.abort();
      });
      const page = await ctx.newPage();
      const sink = { errors: [], warnings: [], failed: [] };
      page.on('pageerror', (e) => sink.errors.push('page error: ' + String(e.message || e).split('\n')[0]));
      page.on('console', (m) => {
        const t = m.text();
        if (m.type() === 'error') { if (!/Failed to load resource/.test(t)) sink.errors.push('console error: ' + t.slice(0, 300)); }
        else if (m.type() === 'warning' && /\[dc-runtime\]/.test(t)) sink.warnings.push(t.slice(0, 300));
      });
      page.on('response', (resp) => { if (resp.status() >= 400 && resp.url().startsWith(`http://127.0.0.1:${PORT}/`)) sink.failed.push(`${resp.status()} ${resp.url().replace(BASE, '')}`); });
      page.on('requestfailed', (req) => { const u = req.url(); if (u.startsWith(`http://127.0.0.1:${PORT}/`)) sink.failed.push(`failed ${u.replace(BASE, '')}: ${req.failure() && req.failure().errorText}`); else if (!/fonts\.g/.test(u)) sink.failed.push(`blocked outside request ${u.slice(0, 120)}`); });
      for (const p of g.pages) {
        let row;
        try { row = await onePage(page, which, g.variant, p, g.w, sink); } catch (e) {
          row = { tag: `${which}/${g.variant}/${p}-${g.w}`, browser: which, variant: g.variant, page: p, width: g.w, problems: [{ check: 'run', msg: 'check run failed: ' + e.message.split('\n')[0] }], notes: [], m: {}, shots: {} };
        }
        results.push(row);
        fs.appendFileSync(path.join(OUT, RESULTS), JSON.stringify(row) + '\n');
        log(row);
      }
      await ctx.close();
    }
  } finally {
    await browser.close();
  }
}

(async () => {
  const server = await startServer();
  fs.writeFileSync(path.join(OUT, RESULTS), '');
  const queues = {};
  let total = 0;
  for (const b of browsers) {
    queues[b] = [];
    for (const v of variants) {
      if (!index[v]) { console.error('unknown variant ' + v); process.exit(2); }
      const pages = (v === 'seed' ? PAGES : PAGES.filter((p) => index[v].differs.includes(fileOf(p)))).filter((p) => pagesWanted.includes(p));
      if (!pages.length) continue;
      for (const w of widths) {
        const ps = ONLY ? pages.filter((p) => ONLY.has(`${b}/${v}/${p}-${w}`)) : pages;
        if (ps.length) { queues[b].push({ variant: v, w, pages: ps }); total += ps.length; }
      }
    }
  }
  const results = [];
  const t0 = Date.now();
  let done = 0;
  const log = (row) => {
    done++;
    const st = row.problems.length ? `FAIL ${row.problems.length}` : 'ok';
    console.log(`[${done}/${total} ${Math.round((Date.now() - t0) / 1000)}s] ${row.tag} ${st}${row.problems.length ? ': ' + row.problems.map((p) => p.check + ': ' + p.msg).join(' || ').slice(0, 300) : ''}`);
  };
  const jobs = [];
  for (const b of browsers) for (let i = 0; i < WORKERS; i++) jobs.push(worker(b, queues[b], results, log));
  await Promise.all(jobs);
  server.close();
  const summary = {
    loads: results.length, passed: results.filter((r) => !r.problems.length).length, failed: results.filter((r) => r.problems.length).length,
    fontFetches, seconds: Math.round((Date.now() - t0) / 1000),
    byCheck: {},
  };
  for (const r of results) for (const p of r.problems) { summary.byCheck[p.check] = (summary.byCheck[p.check] || 0) + 1; }
  fs.writeFileSync(path.join(OUT, SUMMARY), JSON.stringify(summary, null, 1));
  console.log(JSON.stringify(summary));
})().catch((e) => { console.error(e); process.exit(1); });
