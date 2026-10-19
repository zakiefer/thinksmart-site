// Quick look: sign in, open a screen, screenshot it, print page errors.
//   node smoke.js <base> <outDir> [browser] [hash...]
'use strict';
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const people = require('./dev-people.json').people;
(async () => {
  const [base, out, which = 'chromium', ...hashes] = process.argv.slice(2);
  const browser = await pw[which].launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: which === 'chromium', hasTouch: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  await page.goto(base + '/edit/');
  await page.fill('#username', people[0].username);
  await page.fill('#password', people[0].password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.waitForSelector('.bar h1', { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${out}/${which}-start.png`, fullPage: true });
  for (const h of hashes) {
    await page.evaluate((x) => { location.hash = x; }, h);
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${out}/${which}-${h.replace(/[^a-z0-9]+/gi, '_')}.png`, fullPage: true });
  }
  console.log(errors.length ? errors.join('\n') : 'no page errors');
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
