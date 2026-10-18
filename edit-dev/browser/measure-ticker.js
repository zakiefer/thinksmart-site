// Measures the scrolling band's phrase widths in Chromium and WebKit to calibrate the repeat rule.
'use strict';
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const { serve } = require('./serve');
(async () => {
  const root = process.argv[2];
  const server = await serve(root);
  const url = 'http://127.0.0.1:' + server.address().port + '/Home.dc.html';
  for (const name of ['chromium', 'webkit']) {
    const browser = await pw[name].launch();
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector('[data-ref="ticker"] span');
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(500);
    const r = await page.evaluate(() => {
      const half = document.querySelector('[data-ref="ticker"]').firstElementChild;
      const spans = Array.from(half.children);
      const words = spans.filter((s, i) => i % 2 === 0).map((s) => ({ t: s.textContent, w: s.getBoundingClientRect().width }));
      const star = spans[1].getBoundingClientRect().width;
      return { half: half.getBoundingClientRect().width, star, words: words.slice(0, 9) };
    });
    console.log(name, JSON.stringify(r));
    await browser.close();
  }
  server.close();
})();
