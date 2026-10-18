// Measures every state name (longest single word) in the state cards' font, in Chromium and WebKit.
'use strict';
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const { serve } = require('./serve');
const NAMES = JSON.parse(process.argv[3]);
(async () => {
  const server = await serve(process.argv[2]);
  const url = 'http://127.0.0.1:' + server.address().port + '/Claim.dc.html';
  const out = {};
  for (const name of ['chromium', 'webkit']) {
    const browser = await pw[name].launch();
    const page = await browser.newPage({ viewport: { width: 1372, height: 900 } });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector('[data-grid="states"] a');
    await page.evaluate(() => document.fonts.ready);
    const r = await page.evaluate((names) => {
      const card = document.querySelector('[data-grid="states"] a');
      const ref = card.querySelectorAll('span')[1];
      const cs = getComputedStyle(card);
      const inner = card.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
      const probe = ref.cloneNode(false);
      probe.style.position = 'absolute'; probe.style.whiteSpace = 'nowrap'; probe.style.visibility = 'hidden';
      document.body.appendChild(probe);
      const res = {};
      for (const [code, n] of Object.entries(names)) {
        let widest = 0;
        for (const w of n.toUpperCase().split(' ')) { probe.textContent = w; widest = Math.max(widest, probe.getBoundingClientRect().width); }
        res[code] = Math.round(widest * 10) / 10;
      }
      return { inner: Math.round(inner * 10) / 10, font: getComputedStyle(ref).fontFamily, widths: res };
    }, NAMES);
    out[name] = r;
    await browser.close();
  }
  server.close();
  console.log(JSON.stringify(out));
})();
