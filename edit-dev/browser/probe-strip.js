// Measures the Home photo strip's section height a few times in a browser, today's vs built.
'use strict';
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const { serve } = require('./serve');
(async () => {
  const [b, w, h, ...roots] = process.argv.slice(2);
  for (const root of roots) {
    const server = await serve(root);
    const url = 'http://127.0.0.1:' + server.address().port + '/Home.dc.html';
    const browser = await pw[b].launch();
    const res = [];
    for (let k = 0; k < 3; k++) {
      const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await page.goto(url, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(2500);
      res.push(await page.evaluate(() => { const s = document.querySelector('[data-ref="stripWrap"]'); const c = document.querySelector('[data-tilt]'); return s.style.height + ' zoom=' + (c ? c.style.zoom : '') + ' doc=' + document.documentElement.scrollHeight; }));
      await ctx.close();
    }
    console.log(root.split('/').pop(), res.join(' | '));
    await browser.close();
    server.close();
  }
})();
