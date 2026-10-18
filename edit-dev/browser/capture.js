// Gate 2 capture: full-page screenshots + a readable DOM dump of every page, at every width,
// in Chromium and WebKit, with reduced motion so nothing moves between runs.
//   node capture.js <siteRoot> <outDir> [browsers=chromium,webkit] [pages=…] [widths=…]
'use strict';
const fs = require('fs');
const path = require('path');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const { serve } = require('./serve');

const PAGES = ['Home', 'About', 'Services', 'FAQ', 'Contact', 'Claim', '404'];
const SIZES = { 375: 812, 768: 1024, 1024: 768, 1280: 800, 1366: 768, 1440: 900, 1920: 1080 };

// Elements and attributes that describe what a visitor gets; styles are left to the screenshots.
const DUMP = () => {
  const keep = ['href', 'src', 'poster', 'alt', 'aria-label', 'role', 'data-ref', 'id', 'placeholder', 'type', 'name', 'value', 'data-chip', 'data-rv', 'data-grid', 'data-count', 'data-w', 'target'];
  const lines = [];
  const walk = (n, d) => {
    for (const c of n.childNodes) {
      if (c.nodeType === 3) { const t = c.nodeValue.replace(/\s+/g, ' ').trim(); if (t) lines.push('  '.repeat(d) + JSON.stringify(t)); continue; }
      if (c.nodeType !== 1) continue;
      const tag = c.tagName.toLowerCase();
      if (tag === 'script' || tag === 'style') continue;
      const attrs = keep.filter((a) => c.hasAttribute(a)).map((a) => a + '=' + JSON.stringify(c.getAttribute(a))).join(' ');
      const hidden = getComputedStyle(c).display === 'none' ? ' (hidden)' : '';
      lines.push('  '.repeat(d) + '<' + tag + (attrs ? ' ' + attrs : '') + '>' + hidden);
      walk(c, d + 1);
    }
  };
  walk(document.getElementById('dc-root') || document.body, 0);
  return { title: document.title, lines };
};

async function settle(page) {
  await page.waitForLoadState('load');
  await page.waitForFunction(() => {
    const r = document.getElementById('dc-root');
    return !document.querySelector('x-dc') || (r && r.querySelector('footer, main, form, [data-ref]'));
  }, null, { timeout: 15000 }).catch(() => {});
  await page.evaluate(() => document.fonts && document.fonts.ready);
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(700); // support.js's second pass re-renders from the raw file
  // every image decoded, then time for WebKit's high-quality image scaling pass
  await page.evaluate(() => Promise.all(Array.from(document.images).map((i) => (i.decode ? i.decode().catch(() => {}) : null))));
  await page.waitForTimeout(1300);
}

(async () => {
  const [root, out, browsersArg, pagesArg, widthsArg] = process.argv.slice(2);
  const browsers = (browsersArg || 'chromium,webkit').split(',');
  const pages = pagesArg ? pagesArg.split(',') : PAGES;
  const widths = widthsArg ? widthsArg.split(',').map(Number) : Object.keys(SIZES).map(Number);
  const server = await serve(path.resolve(root));
  const base = 'http://127.0.0.1:' + server.address().port + '/';
  fs.mkdirSync(out, { recursive: true });
  const log = [];
  for (const b of browsers) {
    const browser = await pw[b].launch();
    for (const w of widths) {
      const ctx = await browser.newContext({ viewport: { width: w, height: SIZES[w] }, reducedMotion: 'reduce', deviceScaleFactor: 1 });
      // only the site itself and Google Fonts
      await ctx.route('**/*', (r) => {
        const u = r.request().url();
        return (u.startsWith(base) || u.includes('fonts.googleapis.com') || u.includes('fonts.gstatic.com')) ? r.continue() : r.abort();
      });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e.message || e)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      for (const p of pages) {
        errors.length = 0;
        const file = p === '404' ? '404.html' : p + '.dc.html';
        await page.goto(base + file, { waitUntil: 'domcontentloaded' });
        await settle(page);
        const name = `${b}-${p}-${w}`;
        await page.screenshot({ path: path.join(out, name + '.png'), fullPage: true });
        const dom = await page.evaluate(DUMP);
        fs.writeFileSync(path.join(out, name + '.dom.txt'), dom.title + '\n' + dom.lines.join('\n') + '\n');
        log.push({ name, errors: errors.filter((e) => !/Failed to load resource|net::ERR_FAILED/.test(e)) });
      }
      await ctx.close();
    }
    await browser.close();
  }
  server.close();
  fs.writeFileSync(path.join(out, 'capture-log.json'), JSON.stringify(log, null, 1));
  const bad = log.filter((l) => l.errors.length);
  console.log(`captured ${log.length} pages into ${out}; ${bad.length} with page errors`);
  for (const l of bad) console.log('  ' + l.name + ': ' + l.errors.join(' | '));
})();
