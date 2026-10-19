// Makes the "How do I…?" pictures from the real editor screens (iPhone size, the thing to tap
// outlined in orange) into edit/app/help/*.jpg. Uses a throwaway site (dev-setup.php).
//   node help-pictures.js <base> <devDir>
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const people = require('./dev-people.json').people;

const [base, devDir] = process.argv.slice(2);
const repo = path.resolve(__dirname, '../..');
const out = path.join(repo, 'edit/app/help');
const tmp = path.join(devDir, 'help-png');
fs.mkdirSync(out, { recursive: true });
fs.mkdirSync(tmp, { recursive: true });

(async () => {
  execFileSync('php', [path.join(__dirname, 'dev-setup.php'), devDir], { stdio: 'ignore', env: { ...process.env, TS_SITE_URL: base } });
  const browser = await pw.webkit.launch();
  const ctx = await browser.newContext({ ...pw.devices['iPhone 13'] });
  const page = await ctx.newPage();
  await page.goto(base + '/edit/');
  await page.fill('#username', people[1].username);
  await page.fill('#password', people[1].password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.waitForSelector('.bar h1');

  const go = async (hash) => {
    await page.evaluate((x) => { location.hash = x; }, hash);
    await page.waitForFunction((p) => (document.querySelector('main.screen').dataset.hash || '').startsWith(p), hash);
    await page.waitForTimeout(500);
  };
  /** Outline `sel`, scroll it into view, and photograph the screen around it. */
  const pic = async (name, sel, opt = {}) => {
    const el = page.locator(sel).first();
    await el.scrollIntoViewIfNeeded();
    await page.evaluate((s) => window.scrollBy(0, -140), sel);
    await el.evaluate((e) => { e.style.outline = '4px solid #F09A2E'; e.style.outlineOffset = e.closest('.tiles') ? '-5px' : '3px'; });
    await page.waitForTimeout(250);
    const box = await el.boundingBox();
    const vw = page.viewportSize().width;
    const top = Math.max(0, Math.round(box.y - (opt.above ?? 120)));
    const height = Math.min(page.viewportSize().height - top, Math.round(box.height + (opt.above ?? 120) + (opt.below ?? 120)));
    const file = path.join(tmp, name + '.png');
    await page.screenshot({ path: file, clip: { x: 0, y: top, width: vw, height }, caret: 'initial' });
    await el.evaluate((e) => { e.style.outline = ''; e.style.outlineOffset = ''; });
    console.log('  ' + name);
  };

  // a lead from a visitor first, so the Leads pictures have something to show
  const vctx = await browser.newContext({ ...pw.devices['iPhone 13'] });
  const v = await vctx.newPage();
  await v.goto(base + '/Contact.dc.html');
  const form = v.locator('form').filter({ has: v.locator('textarea') }).first();
  await form.locator('input[name="name"]').waitFor({ timeout: 20000 });
  await form.locator('input[name="name"]').fill('Dana Visitor');
  await form.locator('input[type="tel"]').fill('812-555-0142');
  await form.locator('textarea').fill('Hail damage on the north side of the roof. Please call after 5.');
  await Promise.all([v.waitForResponse((r) => r.url().endsWith('/send-lead.php')), form.locator('button[type="submit"]').click()]);
  await vctx.close();

  await go('#/');
  await page.reload();
  await page.waitForSelector('.lead-line');
  await page.waitForTimeout(800);
  await pic('start-lead', '.lead-line', { above: 140, below: 120 });
  await pic('start-fix', 'a.big[href="#/fix"]', { above: 200, below: 200 });
  await pic('start-notice', 'a.big[href="#/notice"]', { above: 160, below: 240 });
  await pic('start-undo', 'a.big[href="#/history"]', { above: 300, below: 120 });
  await pic('phone-1', 'a.tile[href="#/s/business"]', { above: 260, below: 160 });

  await go('#/s/business');
  await page.fill('[data-key="site.phone"] input', '812-774-5050');
  await page.locator('[data-key="site.phone"] input').blur();
  await page.waitForTimeout(400);
  await pic('phone-2', '[data-key="site.phone"]', { above: 70, below: 60 });
  await pic('business-1', '[data-key="site.social"] .btn.add', { above: 260, below: 80 });

  await go('#/tray');
  await pic('tray', '.sticky-actions .btn.primary', { above: 300, below: 40 });
  // put it live, so History has an entry to show
  await page.click('.sticky-actions .btn.primary');
  await page.locator('.sheet button', { hasText: 'Put it live' }).click({ timeout: 20000 });
  await page.waitForSelector('.sheet h2:has-text("Done")', { timeout: 60000 });
  await page.locator('.sheet button', { hasText: 'OK' }).click();

  await go('#/s/states');
  await pic('states-1', '.list-box .list-item:nth-child(3)', { above: 180, below: 120 });
  await pic('states-add', '.list-box .btn.add', { above: 300, below: 60 });
  const ky = JSON.parse(fs.readFileSync(path.join(devDir, 'private/content.json'), 'utf8')).states.find((s) => s.code === 'KY');
  await go('#/item/states/' + ky._id);
  await pic('contract-1', '[data-key$=".pdf"] button', { above: 140, below: 160 });

  await go('#/leads');
  await page.waitForSelector('.lead-item');
  await pic('leads-1', '.lead-item', { above: 160, below: 120 });

  await go('#/s/reviews');
  await pic('reviews-1', '.list-box .btn.add', { above: 200, below: 200 });

  await go('#/photos');
  await pic('photos-1', 'a.photo-cell:has-text("Top of Home")', { above: 120, below: 160 });

  await go('#/fix');
  await page.frameLocator('iframe.preview-frame').locator('[data-edit="home.top.title.0"]').first().waitFor({ timeout: 20000 });
  await page.waitForTimeout(2500);
  await pic('fix-1', '.preview-holder', { above: 120, below: 0 });

  await go('#/notice');
  await pic('notice-1', '[data-key="notice.on"]', { above: 60, below: 380 });

  await go('#/history');
  await page.waitForSelector('.history-item');
  await page.locator('.history-item').first().click();
  await page.waitForSelector('button:has-text("Undo this change")');
  await pic('history-1', 'button:has-text("Undo this change")', { above: 330, below: 80 });

  await browser.close();
  // smaller JPEGs for the phone (Pillow; Python run isolated, reading only our own screenshots)
  execFileSync('python3', ['-I', '-c', `
import sys, os
sys.path.append('/Users/program/Library/Python/3.9/lib/python/site-packages')
from PIL import Image
src, dst = sys.argv[1], sys.argv[2]
for f in sorted(os.listdir(src)):
    if not f.endswith('.png'): continue
    im = Image.open(os.path.join(src, f)).convert('RGB')
    w = 600
    im = im.resize((w, round(im.height * w / im.width)), Image.LANCZOS)
    im.save(os.path.join(dst, f[:-4] + '.jpg'), 'JPEG', quality=74, optimize=True, progressive=True)
    print('   ', f[:-4] + '.jpg', os.path.getsize(os.path.join(dst, f[:-4] + '.jpg')) // 1024, 'KB')
`, tmp, out], { stdio: 'inherit' });
})().catch((e) => { console.error(e); process.exit(1); });
