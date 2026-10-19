// End-to-end tests of the editor in Chromium and WebKit at iPhone size, against a throwaway
// copy of the website (edit-dev/editor/dev-setup.php + router.php). send-lead.php is never
// reached: previews use the editor's stub and the dev router refuses the real one.
//   node flows.js <base> <devDir> [chromium,webkit] [flow,...]
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');
const people = require('./dev-people.json').people;

const [base, devDir, browsersArg = 'chromium,webkit', onlyArg = ''] = process.argv.slice(2);
const repo = path.resolve(__dirname, '../..');
const outDir = path.join(devDir, 'shots');
fs.mkdirSync(outDir, { recursive: true });
const only = onlyArg ? onlyArg.split(',') : null;

let pass = 0;
let fail = 0;
const results = [];
function ok(cond, what) {
  if (cond) { pass++; console.log('  ok    ' + what); } else { fail++; console.log('  FAIL  ' + what); }
  results.push([cond, what]);
}

function reset() {
  execFileSync('php', [path.join(__dirname, 'dev-setup.php'), devDir], { stdio: 'ignore', env: { ...process.env, TS_SITE_URL: base } });
}
const live = (file) => fs.readFileSync(path.join(devDir, 'site', file), 'utf8');
const outbox = () => { const d = path.join(devDir, 'private', 'outbox'); return fs.existsSync(d) ? fs.readdirSync(d).sort().map((f) => JSON.parse(fs.readFileSync(path.join(d, f), 'utf8'))) : []; };
const content = () => JSON.parse(fs.readFileSync(path.join(devDir, 'private', 'content.json'), 'utf8'));

async function newPhone(browser, which) {
  const opts = which === 'webkit' ? { ...pw.devices['iPhone 13'] } : { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
  const ctx = await browser.newContext(opts);
  const page = await ctx.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('console', (m) => {
    if (m.type() !== 'error' || /Failed to load resource/.test(m.text())) return;
    // Playwright's WebKit screenshots add a style tag, which the editor's security policy refuses
    if (/Refused to apply a stylesheet/.test(m.text()) && Date.now() - (page.shotAt || 0) < 3000) return;
    page.errors.push(m.text());
  });
  page.on('dialog', (d) => d.dismiss());
  return { ctx, page };
}

async function signIn(page, who = 0) {
  await page.goto(base + '/edit/');
  await page.fill('#username', people[who].username);
  await page.fill('#password', people[who].password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.waitForSelector('.bar h1');
}

async function to(page, hash) {
  await page.evaluate((x) => { location.hash = x; }, hash);
  await onScreen(page, hash);
  await page.waitForTimeout(250);
}
async function onScreen(page, prefix) {
  await page.waitForFunction((p) => { const m = document.querySelector('main.screen'); return m && (m.dataset.hash || '').startsWith(p); }, prefix, { timeout: 15000 });
}
/** Click a sheet button that leads to a new sheet: wait for the first one to go. */
async function sheetThen(page, label) {
  const old = await page.locator('.sheet').first().elementHandle();
  await sheetButton(page, label);
  if (old) await old.waitForElementState('hidden').catch(() => {});
  await page.waitForFunction(() => !document.querySelector('.sheet-back:not(.in)'), null, { timeout: 5000 }).catch(() => {});
}
async function saved(page) {
  // wait until the change is in "Not live yet" and the server has it
  await page.waitForFunction(() => { const s = document.querySelector('.save-state'); const t = document.querySelector('.traybar'); return s && s.textContent === 'Saved' && t && !t.hidden; }, null, { timeout: 15000 });
}
async function sheetButton(page, label) {
  const b = page.locator('.sheet button', { hasText: label }).first();
  await b.waitFor({ timeout: 20000 });
  await b.click();
}
/** Tap "Put it live" in "Not live yet": a checked, clean change goes straight live; anything
 *  to look at opens "Check your changes" first, where "Put it live" is tapped too. */
async function tapPutLive(page) {
  await page.waitForSelector('.tray-status .callout, .tray-status p', { timeout: 20000 });
  await page.click('.sticky-actions .btn.primary');
  await page.waitForSelector('.sheet h2', { timeout: 90000 });
  if (await page.textContent('.sheet h2') === 'Check your changes') {
    await page.waitForSelector('.sheet button:has-text("Put it live"):not([disabled])', { timeout: 20000 });
    await sheetButton(page, 'Put it live');
    await page.waitForFunction(() => { const t = document.querySelector('.sheet h2'); return t && t.textContent !== 'Check your changes'; }, null, { timeout: 90000 });
  }
}
async function putLive(page) {
  await to(page, '#/tray');
  await tapPutLive(page);
  const title = await page.textContent('.sheet h2');
  await sheetButton(page, 'OK');
  return title;
}
async function shot(page, name) { page.shotAt = Date.now(); await page.screenshot({ path: path.join(outDir, name + '.png'), fullPage: false, caret: 'initial' }); page.shotAt = Date.now(); }

// ------------------------------------------------------------------ flows

const FLOWS = {
  async signin(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await page.goto(base + '/edit/');
    ok(await page.getAttribute('#username', 'autocomplete') === 'username' && await page.getAttribute('#password', 'autocomplete') === 'current-password', `${which}: sign-in boxes are set up for iPhone Passwords autofill`);
    ok(await page.getAttribute('#username', 'autocapitalize') === 'none' && await page.getAttribute('#username', 'autocorrect') === 'off', `${which}: no auto-capitals or autocorrect in the username`);
    await page.fill('#username', 'john');
    await page.fill('#password', 'wrong-password-1');
    await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
    ok((await page.textContent('.msg')).includes("don't match"), `${which}: a wrong password says so plainly`);
    const csp = (await (await page.request.get(base + '/edit/')).headers())['content-security-policy'] || '';
    ok(/script-src 'self'/.test(csp) && /frame-ancestors 'none'/.test(csp) && !/unsafe-inline/.test(csp), `${which}: strict security policy (no inline scripts, no framing)`);
    await signIn(page);
    ok(await page.textContent('.bar h1') === 'Your website', `${which}: signed in to the start screen`);
    ok(await page.locator('.big').count() === 4 && await page.locator('.tile').count() === 9, `${which}: 4 task buttons and 9 tiles`);
    const cookies = await ctx.cookies();
    const s = cookies.find((c) => c.name === 'ts_s');
    ok(s && s.httpOnly && s.path === '/edit' && s.sameSite === 'Lax' && s.expires > Date.now() / 1000 + 89 * 86400, `${which}: session cookie is HTTP-only, /edit, lasts 90 days`);
    await shot(page, which + '-start');
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async phone(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/business');
    const box = page.locator('[data-key="site.phone"] input');
    ok(await box.getAttribute('type') === 'tel' && await box.getAttribute('autocorrect') === 'off', `${which}: phone box has the phone keyboard, no autocorrect`);
    ok(await page.locator('[data-key="site.email"] input').getAttribute('type') === 'email', `${which}: email box has the email keyboard`);
    await box.fill('8127745050');
    await box.blur();
    ok(await box.inputValue() === '812-774-5050', `${which}: the number is shown back as visitors see it`);
    await saved(page);
    ok(await page.isVisible('.traybar') && (await page.textContent('.traybar')).includes('1'), `${which}: "Not live yet" shows 1 change`);
    ok((await page.textContent('body')).includes('Also change these yourself'), `${which}: "Also change these yourself" checklist appears`);
    await shot(page, which + '-business-changed');
    await to(page, '#/tray');
    const checked = await page.waitForFunction(() => /nothing to fix/.test((document.querySelector('.tray-status') || {}).textContent || ''), null, { timeout: 20000 }).then(() => true, () => false);
    ok(checked, `${which}: "Not live yet" checks it right there ("${((await page.textContent('.tray-status').catch(() => '')) || '').trim().slice(0, 60)}")`);
    await shot(page, which + '-tray');
    await page.click('.sticky-actions .btn.primary');
    await page.waitForSelector('.sheet h2', { timeout: 60000 });
    const t = await page.textContent('.sheet h2');
    ok(t !== 'Check your changes' && /Done/.test(t), `${which}: one tap puts it live and the page check passed ("${t}")`);
    await shot(page, which + '-done');
    await sheetButton(page, 'OK');
    ok(live('Contact.dc.html').includes('812-774-5050') && !live('Home.dc.html').includes('812-774-5049'), `${which}: the live pages have the new number`);
    const mail = outbox().filter((m) => /changed the website/.test(m.subject));
    ok(mail.length >= 1 && mail[mail.length - 1].text.includes('812-774-5050') && mail[mail.length - 1].html.includes('/edit/undo?t='), `${which}: John was emailed with an Undo button`);
    ok(!(await page.isVisible('.traybar')), `${which}: nothing left in "Not live yet"`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },
};

// a small real PDF with words on page 1
function makePdf(text) {
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    null,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  const stream = `BT /F1 20 Tf 72 700 Td (${text}) Tj ET`;
  objs[3] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
  let out = '%PDF-1.4\n';
  const offs = [];
  objs.forEach((o, i) => { offs.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const x = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${x}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

async function typeRich(page, sel, text) {
  const ed = page.locator(sel).first();
  await ed.click();
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A');
  await page.keyboard.type(text);
}

Object.assign(FLOWS, {
  async leads(browser, which) {
    // a visitor sends the message form on the real Contact page (mail goes to the test outbox)
    const v = await newPhone(browser, which);
    await v.page.goto(base + '/Contact.dc.html');
    const form = v.page.locator('form').filter({ has: v.page.locator('textarea') }).first();
    await form.locator('input[name="name"]').waitFor({ timeout: 20000 });
    await form.locator('input[name="name"]').fill('Dana Visitor');
    await form.locator('input[type="tel"]').fill('812-555-0142');
    await form.locator('textarea').fill('Hail damage on the north side of the roof. Please call after 5.');
    const sent = v.page.waitForResponse((r) => r.url().endsWith('/send-lead.php'), { timeout: 20000 });
    await form.locator('button[type="submit"]').click();
    const res = await (await sent).json().catch(() => ({}));
    ok(res.success === true, `${which}: the visitor's message is saved by the website's own mailer`);
    await v.ctx.close();
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await page.waitForSelector('.lead-line');
    await page.waitForFunction(() => /Last lead received Today/.test(document.querySelector('.lead-line').textContent), null, { timeout: 15000 })
      .then(() => ok(true, `${which}: the start screen says "Last lead received Today …"`), () => ok(false, `${which}: the start screen says "Last lead received Today …"`));
    await shot(page, which + '-start-lead');
    await page.click('.lead-line');
    await page.waitForSelector('.lead-item');
    ok((await page.textContent('.lead-item')).includes('Dana Visitor') && (await page.textContent('.lead-item')).includes('Emailed'), `${which}: the Leads screen lists it, marked "Emailed"`);
    await shot(page, which + '-leads');
    await page.click('.lead-item');
    await page.waitForSelector('.lead-fields');
    const txt = await page.textContent('.lead-fields');
    ok(txt.includes('Hail damage on the north side') && await page.locator('a.btn[href^="tel:"]').count() === 1, `${which}: a lead opens with its words and a Call button`);
    await shot(page, which + '-lead');
    // a new address for leads, confirmed from the test lead's link
    await to(page, '#/s/business');
    await page.fill('[data-key="leadTo"] input', 'claims@example.test');
    await page.click('[data-key="leadTo"] button:has-text("Add")');
    await page.waitForFunction(() => /Waiting: tap the link/.test(document.querySelector('[data-key="leadTo"]').textContent), null, { timeout: 10000 });
    ok(true, `${which}: a new lead address waits for its confirmation link`);
    const m = outbox().filter((x) => /please confirm/.test(x.subject)).pop();
    const link = /href="([^"]*\/edit\/confirm\?t=[^"]+)"/.exec(m.html)[1].replace(/&amp;/g, '&');
    const other = await browser.newContext();
    const p2 = await other.newPage();
    await p2.goto(link);
    await Promise.all([p2.waitForLoadState('load'), p2.click('button[type=submit]')]);
    await p2.waitForSelector('h1');
    ok((await p2.textContent('h1')).includes('Confirmed'), `${which}: the link in the test lead confirms it`);
    await other.close();
    await page.reload();
    await to(page, '#/s/business');
    await page.waitForSelector('[data-key="leadTo"]');
    ok((await page.textContent('[data-key="leadTo"]')).includes('Confirmed'), `${which}: the address now shows "Confirmed"`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async rich(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/page/Home');
    await page.locator('summary', { hasText: 'Case closed' }).click();
    const ed = page.locator('[data-key="home.closed.title"] .rich');
    await ed.click();
    // select the first word, then tap "Orange": the selection must survive the tap
    await ed.evaluate((el) => {
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      const t = walker.nextNode();
      const r = document.createRange();
      r.setStart(t, 0);
      r.setEnd(t, t.nodeValue.indexOf(' ') > 0 ? t.nodeValue.indexOf(' ') : t.nodeValue.length);
      const sel = getSelection();
      sel.removeAllRanges();
      sel.addRange(r);
    });
    await page.locator('[data-key="home.closed.title"] .tool.hl').click();
    await saved(page);
    const marks = await ed.locator('mark').count();
    const d = JSON.parse(fs.readFileSync(path.join(devDir, 'private/drafts', fs.readdirSync(path.join(devDir, 'private/drafts'))[0]), 'utf8'));
    const op = d.ops.find((o) => o.path.join('.') === 'home.closed.title');
    ok(marks >= 1 && op && JSON.stringify(op.value).includes('"hl"'), `${which}: "Orange" on selected words makes them orange (${JSON.stringify(op && op.value).slice(0, 120)})`);
    // a second is fine; a third orange phrase is refused with the reason
    const pick = () => ed.evaluate((el) => {
      const texts = [];
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) if (!n.parentElement.closest('mark') && n.nodeValue.trim().length > 3) texts.push(n);
      const t = texts[texts.length - 1];
      const r = document.createRange();
      r.setStart(t, 0);
      r.setEnd(t, Math.min(3, t.nodeValue.length));
      getSelection().removeAllRanges();
      getSelection().addRange(r);
    });
    await pick();
    await page.locator('[data-key="home.closed.title"] .tool.hl').click();
    await page.waitForTimeout(200);
    const two = await ed.locator('mark').count();
    await pick();
    await page.locator('[data-key="home.closed.title"] .tool.hl').click();
    await page.waitForTimeout(300);
    const toastText = (await page.locator('.toast').allTextContents()).join(' ');
    const three = await ed.locator('mark').count();
    ok(two === 2 && three === 2 && /at most 2/.test(toastText), `${which}: at most two orange phrases, and it says why (${two}, ${three}; "${toastText}")`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async autoundo(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    page.on('console', (m) => { if (m.text().startsWith('CHECK')) console.log('    ' + m.text()); });
    await signIn(page);
    await to(page, '#/s/promises');
    await page.fill('[data-key="site.price"] input', '325');
    await saved(page);
    // the Services page "fails to draw" after the change
    await page.route('**/edit/p/live/Services.dc.html*', async (route) => {
      const res = await route.fetch();
      const body = (await res.text()).replace('</head>', '<script>console.error("[dc-runtime] render error in <Services>: test")</script></head>');
      await route.fulfill({ response: res, body });
    });
    await to(page, '#/tray');
    await tapPutLive(page);
    const t = await page.textContent('.sheet h2');
    ok(/Put back automatically/.test(t), `${which}: a page that doesn't draw after a change puts the website back ("${t}")`);
    await sheetButton(page, 'OK');
    ok(content().site.price === 299, `${which}: the live price is back to $299`);
    await page.waitForTimeout(500);
    await page.waitForSelector('.tray-item', { timeout: 10000 }).catch(() => {});
    ok((await page.locator('.tray-item').count()) === 1 && (await page.textContent('.tray-list')).includes('$325'), `${which}: her change is still in "Not live yet"`);
    const hist = JSON.parse(fs.readFileSync(path.join(devDir, 'private/history/index.json'), 'utf8'));
    ok(hist[hist.length - 1].kind === 'autoundo', `${which}: History shows "Put back automatically"`);
    await ctx.close();
  },

  async fix(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/fix');
    const frame = page.frameLocator('iframe.preview-frame');
    await frame.locator('[data-edit="home.top.title.0"]').first().waitFor({ timeout: 20000 });
    await shot(page, which + '-fix');
    await frame.locator('[data-edit="home.top.title.0"]').first().evaluate((el) => el.click());
    await page.waitForFunction(() => location.hash.startsWith('#/page/Home'), null, { timeout: 20000 });
    await page.waitForTimeout(800);
    const focused = await page.evaluate(() => { const b = document.querySelector('[data-key="home.top.title.0"]'); return !!b && b.closest('details').open && b.getBoundingClientRect().top < innerHeight; });
    ok(focused, `${which}: tapping the headline in the preview opens its box`);
    await typeRich(page, '[data-key="home.top.title.0"] .rich', 'Storms happen.');
    await saved(page);
    const stored = JSON.parse(fs.readFileSync(path.join(devDir, 'private/drafts', fs.readdirSync(path.join(devDir, 'private/drafts'))[0]), 'utf8'));
    ok(JSON.stringify(stored.ops).includes('Storms happen.'), `${which}: typed headline reached the draft (${JSON.stringify(stored.ops).slice(0, 160)})`);
    const c = await page.evaluate(() => JSON.stringify(window.__S ? 1 : 0));
    await to(page, '#/preview/Home.dc.html');
    const pv = page.frameLocator('iframe.preview-frame');
    await pv.locator('text=Storms happen.').first().waitFor({ timeout: 20000 }).then(() => ok(true, `${which}: the preview shows the new headline`), () => ok(false, `${which}: the preview shows the new headline`));
    await shot(page, which + '-preview');
    // a list item: the state cards point to their state
    await to(page, '#/fix/Claim.dc.html');
    await page.frameLocator('iframe.preview-frame').locator('[data-edit^="states."]').first().waitFor({ timeout: 20000 });
    await page.frameLocator('iframe.preview-frame').locator('[data-edit^="states."]').first().evaluate((el) => el.click());
    await page.waitForFunction(() => location.hash.startsWith('#/item/states/'), null, { timeout: 20000 }).then(() => ok(true, `${which}: tapping a state card opens that state`), () => ok(false, `${which}: tapping a state card opens that state`));
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async faq(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/faq');
    await page.click('.list-box .btn.add');
    await onScreen(page, '#/item/faq.items/');
    await page.fill('[data-key$=".q"] input, [data-key$=".q"] textarea', 'Do you handle hail damage?');
    await typeRich(page, '.list-box .rich', 'Yes. Hail is one of the claims we see most.');
    await saved(page);
    await to(page, '#/s/faq');
    const rows = page.locator('.list-box > .list-items > .list-item');
    const n = await rows.count();
    ok(n === 11, `${which}: the new question is in the list (${n})`);
    await rows.nth(n - 1).locator('.star-btn').click();
    await page.waitForSelector('.sheet');
    await page.locator('.sheet .sheet-buttons button').first().click();
    await page.waitForTimeout(300);
    ok(await page.locator('.star-btn[aria-pressed="true"]').count() === 3, `${which}: starring a 4th swaps one out (still 3 on Home)`);
    // limits explain themselves
    await rows.nth(0).locator('.item-open').click();
    await onScreen(page, '#/item/faq.items/');
    await page.click('button:has-text("Delete this question")');
    await sheetButton(page, 'Delete');
    await onScreen(page, '#/s/faq');
    await page.waitForTimeout(300);
    ok(await rows.count() === 10, `${which}: deleted a question`);
    const t = await putLive(page);
    ok(/Done/.test(t), `${which}: FAQ changes went live ("${t}")`);
    ok(live('FAQ.dc.html').includes('Do you handle hail damage?') && live('Home.dc.html').includes('Do you handle hail damage?'), `${which}: the new question is on FAQ and Home`);
    // bring the deleted one back from History
    await to(page, '#/history');
    await page.locator('.history-item').first().click();
    await page.waitForSelector('.ba-card');
    const bring = page.locator('button', { hasText: 'Bring back' }).first();
    ok(await bring.count() === 1, `${which}: History offers "Bring back" for the deleted question`);
    await bring.click();
    await page.waitForSelector('.sheet h2', { timeout: 60000 });
    const said = (await page.textContent('.sheet')).replace(/\s+/g, ' ');
    await sheetButton(page, 'OK');
    ok(content().faq.items.length === 11, `${which}: brought back (11 questions live) [${said.slice(0, 120)}]`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async review(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/reviews');
    await page.click('.list-box .btn.add');
    await onScreen(page, '#/item/home.reviews.items/');
    const labels = await page.locator('.field-label').allTextContents();
    ok(labels[0].startsWith('Their words') && labels[1].startsWith('Their name'), `${which}: review boxes in order (words, then name): ${labels.slice(0, 3).join(' / ')}`);
    await page.fill('[data-key$=".text"] textarea', 'They got our roof claim paid in full after the insurer said no. Honest and fast.');
    const name = page.locator('[data-key$=".name"] input');
    ok(await name.getAttribute('autocorrect') === 'off' && await name.getAttribute('autocapitalize') === 'words', `${which}: name box: no autocorrect, capital first letters`);
    await name.fill('Michael Rodriguez');
    ok((await page.textContent('[data-key$=".name"]')).includes('Shows as: Michael R.'), `${which}: shows the name as "Michael R."`);
    // the stars sit under "More about the review (optional)"
    const star = page.locator('.stars button[data-n="5"]');
    if (!(await star.isVisible())) await star.evaluate((b) => { const d = b.closest('details'); if (d) d.open = true; });
    await star.click();
    await saved(page);
    await to(page, '#/tray');
    const why = await page.textContent('.tray-list');
    ok(/Real customer/.test(why) && !(await page.locator('.tray-check input').first().isChecked()), `${which}: an unfinished review is held back with the reason`);
    await page.goBack();
    await page.waitForTimeout(400);
    await page.locator('[data-key$=".real"] .switch').click();
    await saved(page);
    await to(page, '#/tray');
    await page.waitForTimeout(300);
    ok(await page.locator('.tray-check input').first().isChecked(), `${which}: with the tick it's ready to go`);
    const t = await putLive(page);
    ok(/Done/.test(t) && live('Home.dc.html').includes('Michael R.') && live('Home.dc.html').includes('id="reviews"'), `${which}: the review section appears on Home`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async photo(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/photos');
    await page.locator('a.photo-cell', { hasText: 'Case closed' }).click();
    await page.waitForSelector('button:has-text("Choose a new photo")');
    const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('button:has-text("Choose a new photo")')]);
    ok((await chooser.element().getAttribute('accept')) === 'image/jpeg,image/png,image/webp', `${which}: the photo picker asks for JPEG, PNG or WebP (iPhone converts its photos)`);
    await chooser.setFiles(path.join(repo, 'media', 'og-cover.jpg'));
    await page.waitForSelector('button:has-text("Use this photo")', { timeout: 15000 });
    ok((await page.textContent('body')).includes('Match the site'), `${which}: "Match the site's look" darkening is offered where words sit on the photo`);
    ok((await page.textContent('body')).includes('describe it here'), `${which}: asks to describe the new photo`);
    await page.locator('.focus-pick img').click({ position: { x: 30, y: 30 } });
    await shot(page, which + '-photo');
    await page.click('button:has-text("Use this photo")');
    await page.waitForFunction(() => !location.hash.startsWith('#/photo/'), null, { timeout: 15000 });
    await page.waitForTimeout(500);
    const t = await putLive(page);
    const html = live('Home.dc.html');
    const m = /src="(media\/u\/home-closed-photo-[a-f0-9]{12}\.jpg)"/.exec(html);
    ok(/Done/.test(t) && !!m, `${which}: the new photo is live under a new name (${m ? m[1] : 'missing'})`);
    ok(/object-position:\d+% \d+%/.test(html), `${which}: the important part sets the crop`);
    if (m) {
      const buf = fs.readFileSync(path.join(devDir, 'site', m[1]));
      ok(buf[0] === 0xff && buf[1] === 0xd8 && buf.indexOf('Exif') < 0, `${which}: it's a JPEG without camera details`);
    }
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async video(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/photo/home.top.video.video');
    await page.waitForSelector('button:has-text("Use my photo instead")');
    await page.click('button:has-text("Use my photo instead")');
    const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('button:has-text("Choose a new photo")')]);
    await chooser.setFiles(path.join(repo, 'media', 'og-cover.jpg'));
    await page.click('button:has-text("Use this photo")', { timeout: 15000 });
    await page.waitForTimeout(600);
    const t = await putLive(page);
    const html = live('Home.dc.html');
    ok(/Done/.test(t) && html.includes('src="media/u/home-top-video-photo-') && !html.includes('data-ref="rain"') && !html.includes('hero-storm-aerial.mp4'), `${which}: storm video swapped for a photo, rain and lightning off`);
    await to(page, '#/photo/home.top.video.video');
    await page.click('button:has-text("Put the video back")');
    await page.waitForTimeout(300);
    const t2 = await putLive(page);
    ok(/Done/.test(t2) && live('Home.dc.html').includes('hero-storm-aerial.mp4') && live('Home.dc.html').includes('data-ref="rain"'), `${which}: "Put the video back" brings video and rain back`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async contract(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    const ky = content().states.find((s) => s.code === 'KY');
    await to(page, '#/contract/' + ky._id);
    await page.waitForSelector('.help-card');
    ok((await page.textContent('.help-card')).includes('Save to Files'), `${which}: the iPhone "save a PDF from an email" help is there`);
    const pdf = path.join(devDir, 'ky-test.pdf');
    fs.writeFileSync(pdf, makePdf('Kentucky Public Adjuster Agreement - blank'));
    const docx = path.join(devDir, 'contract.docx');
    fs.writeFileSync(docx, Buffer.from('PK\x03\x04word file', 'latin1'));
    let [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('button:has-text("Replace with a new PDF")')]);
    await chooser.setFiles(docx);
    await page.waitForSelector('.sheet');
    ok((await page.textContent('.sheet')).includes('This is a Word file. Ask John to send it as a PDF.'), `${which}: a Word file gets the plain answer`);
    await sheetButton(page, 'OK');
    [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('button:has-text("Replace with a new PDF")')]);
    await chooser.setFiles(pdf);
    await page.waitForSelector('.sheet h2', { timeout: 30000 });
    ok((await page.textContent('.sheet h2')).includes('BLANK Kentucky contract'), `${which}: PDF.js read it and asks "Is this the BLANK Kentucky contract?"`);
    ok(await page.locator('.sheet .pdf-page canvas').count() === 1, `${which}: shows its first page`);
    await shot(page, which + '-contract-check');
    await sheetButton(page, "Yes, it's blank");
    await page.waitForSelector('.sheet h2:has-text("e-sign")', { timeout: 20000 });
    await sheetButton(page, 'No');
    await page.waitForTimeout(500);
    const t = await putLive(page);
    ok(/Done/.test(t) && fs.readFileSync(path.join(devDir, 'site/docs/think-smart-agreement-kentucky.pdf')).equals(fs.readFileSync(pdf)), `${which}: the new contract is live at its permanent address`);
    await to(page, '#/contract/' + ky._id);
    await page.waitForSelector('.versions li', { timeout: 10000 });
    ok(await page.locator('.versions li').count() === 2, `${which}: earlier contracts are listed with dates`);
    // another state named in the PDF
    fs.writeFileSync(pdf, makePdf('Ohio Public Adjuster Agreement'));
    [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('button:has-text("Replace with a new PDF")')]);
    await chooser.setFiles(pdf);
    await page.waitForSelector('.sheet h2', { timeout: 30000 });
    ok((await page.textContent('.sheet')).includes('mentions Ohio, not Kentucky'), `${which}: warns when the PDF names another state`);
    await sheetButton(page, 'No, pick another');
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async conflict(browser, which) {
    const a = await newPhone(browser, which);
    const b = await newPhone(browser, which);
    await signIn(a.page, 0);
    await signIn(b.page, 1);
    for (const x of [a, b]) await to(x.page, '#/s/promises');
    await a.page.fill('[data-key="site.price"] input', '349');
    await saved(a.page);
    await b.page.fill('[data-key="site.price"] input', '399');
    await saved(b.page);
    ok(/Done/.test(await putLive(b.page)), `${which}: Muneerah puts $399 live first`);
    await to(a.page, '#/tray');
    await a.page.click('.sticky-actions .btn.primary');
    await a.page.waitForSelector('.sheet h3:has-text("Someone else changed")', { timeout: 20000 });
    const txt = await a.page.textContent('.sheet');
    ok(/Muneerah changed it/.test(txt) && /Theirs: \$399/.test(txt), `${which}: John sees "Muneerah changed it … Theirs: $399"`);
    await shot(a.page, which + '-conflict');
    await a.page.locator('.sheet button', { hasText: 'Use mine' }).click();
    await a.page.waitForSelector('.sheet button:has-text("Put it live"):not([disabled])', { timeout: 20000 });
    await sheetButton(a.page, 'Put it live');
    await a.page.waitForSelector('.sheet h2', { timeout: 60000 });
    ok(/Done/.test(await a.page.textContent('.sheet h2')) && content().site.price === 349, `${which}: "Use mine" goes live ($349)`);
    await a.ctx.close();
    await b.ctx.close();
  },

  async offline(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/promises');
    await ctx.setOffline(true);
    await page.fill('[data-key="site.price"] input', '275');
    await page.waitForFunction(() => /phone/.test(document.querySelector('.save-state').textContent), null, { timeout: 15000 }).then(() => ok(true, `${which}: offline: "Saved on this phone"`), () => ok(false, `${which}: offline: "Saved on this phone"`));
    await ctx.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await saved(page);
    const d = JSON.parse(fs.readFileSync(path.join(devDir, 'private/drafts', fs.readdirSync(path.join(devDir, 'private/drafts'))[0]), 'utf8'));
    ok(d.ops.some((o) => o.path.join('.') === 'site.price' && o.value === 275), `${which}: back online, the draft reached the server`);
    // the connection drops while putting it live: the website finishes the job
    // the request reaches the website, then the phone loses the answer (as when it locks)
    await page.evaluate(() => {
      const orig = window.fetch;
      window.fetch = async (url, init) => {
        if (String(url).includes('/edit/api/publish')) { window.__reached = (await orig(url, init)).status; throw new TypeError('Load failed'); }
        return orig(url, init);
      };
    });
    await to(page, '#/tray');
    await page.waitForSelector('.tray-status .callout, .tray-status p', { timeout: 20000 });
    await page.click('.sticky-actions .btn.primary');
    await page.waitForSelector('.sheet h2', { timeout: 150000 }).catch(() => {});
    if (await page.textContent('.sheet h2').catch(() => '') === 'Check your changes') {
      await sheetButton(page, 'Put it live');
      await page.waitForFunction(() => { const x = document.querySelector('.sheet h2'); return x && x.textContent !== 'Check your changes'; }, null, { timeout: 150000 }).catch(() => {});
    }
    const said = await page.locator('.sheet h2').first().textContent().catch(() => '(no sheet)');
    const reached = await page.evaluate(() => window.__reached);
    ok(/Done/.test(said) && content().site.price === 275, `${which}: dropped connection mid-save: it still went live and she's told (server answered ${reached}; "${said}")`);
    await sheetButton(page, 'OK');
    ok(!(await page.isVisible('.traybar')), `${which}: nothing left in "Not live yet"`);
    await ctx.close();
  },

  async notice(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await page.click('a.big[href="#/notice"]');
    await page.waitForSelector('.notice-kinds');
    await page.locator('[data-key="notice.on"] .switch').click();
    await page.locator('.notice-kind', { hasText: 'Office closed' }).click();
    await page.waitForTimeout(300);
    const btn = page.locator('button:has-text("Put the notice live now")');
    ok(await btn.isEnabled(), `${which}: "Put the notice live now" is ready`);
    await shot(page, which + '-notice');
    await btn.click();
    await page.waitForSelector('.sheet h2', { timeout: 60000 });
    const nt = await page.textContent('.sheet h2');
    ok(nt.startsWith('Done.'), `${which}: notice went live ("${nt}")`);
    await sheetButton(page, 'OK');
    ok(live('Home.dc.html').includes('Our office is closed today'), `${which}: the notice is on the pages`);
    await to(page, '#/');
    ok((await page.textContent('a.big[href="#/notice"]')).includes('On now'), `${which}: the start screen shows the notice "On now"`);
    await ctx.close();
  },

  async history(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/business');
    await page.fill('[data-key="site.email"] input', 'claims@example.test');
    await saved(page);
    await putLive(page);
    await page.click('a.big[href="#/history"]').catch(() => to(page, '#/history'));
    await to(page, '#/history');
    await page.waitForSelector('.history-item');
    const first = await page.textContent('.history-item');
    ok(/You changed email shown on the site from/.test(first), `${which}: History in plain words: "${first.replace(/\s+/g, ' ').slice(0, 90)}"`);
    await shot(page, which + '-history');
    await page.locator('.history-item').first().click();
    await page.click('button:has-text("Undo this change")');
    await page.waitForSelector('.sheet h2', { timeout: 60000 });
    await sheetButton(page, 'OK');
    ok(content().site.email === 'thinksmartpublicadjusting@gmail.com', `${which}: Undo put the email back`);
    await to(page, '#/history');
    await page.waitForSelector('.history-item');
    await page.locator('.history-item').last().click();
    await page.click('button:has-text("Put the whole website back")');
    await page.waitForSelector('.sheet');
    await sheetThen(page, 'Put it back');
    await page.waitForSelector('.sheet h2:not(:has-text("Put the whole website back?"))', { timeout: 60000 });
    const t = await page.textContent('.sheet h2');
    await sheetButton(page, 'OK');
    ok(/back to|Done|live/.test(t), `${which}: whole website put back to launch day ("${t}")`);
    // the email Undo link, opened without being signed in
    reset();
    await signIn(page);
    await to(page, '#/s/business');
    await page.fill('[data-key="site.phone"] input', '812-774-5051');
    await saved(page);
    await putLive(page);
    const mail = outbox().filter((m) => /changed the website/.test(m.subject)).pop();
    const link = /href="([^"]*\/edit\/undo\?t=[^"]+)"/.exec(mail.html)[1].replace(/&amp;/g, '&');
    const other = await browser.newContext();
    const p2 = await other.newPage();
    await p2.goto(link);
    ok((await p2.textContent('h1')).includes('Undo this change'), `${which}: the email's Undo link asks first (scanners can't trigger it)`);
    await Promise.all([p2.waitForLoadState('load'), p2.click('button[type=submit]')]);
    await p2.waitForSelector('h1');
    ok((await p2.textContent('h1')).includes('Undone') && content().site.phone === '812-774-5049', `${which}: John's Undo from the email worked`);
    await other.close();
    await ctx.close();
  },

  async search(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await page.click('.searchbar');
    await page.fill('input.search', 'ONLY POLICYHOLDERS');
    await page.waitForSelector('.result');
    ok(await page.locator('.result').count() >= 1, `${which}: search ignores capitals (${await page.locator('.result').count()} found)`);
    await page.locator('.result').first().click();
    await page.waitForTimeout(800);
    ok(await page.evaluate(() => !!document.querySelector('.flash')), `${which}: a result opens its box`);
    await ctx.close();
  },

  async spelling(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/page/Home');
    await page.locator('summary', { hasText: 'Case closed' }).click();
    await typeRich(page, '[data-key="home.closed.title"] .rich', 'Paid in fulll by Xactimate.');
    await saved(page);
    await to(page, '#/tray');
    await page.click('.sticky-actions .btn.primary');
    await page.waitForSelector('.sheet h3:has-text("spelling")', { timeout: 20000 });
    const words = await page.locator('.spell-row strong').allTextContents();
    ok(words.includes('fulll') && !words.includes('Xactimate'), `${which}: the spelling check flags "fulll" but knows "Xactimate" (${words.join(', ')})`);
    await page.locator('.spell-row', { hasText: 'fulll' }).locator('button', { hasText: 'Spelled right' }).click();
    await page.waitForTimeout(500);
    const taught = JSON.parse(fs.readFileSync(path.join(devDir, 'private/words.json'), 'utf8'));
    ok('fulll' in taught, `${which}: "Spelled right" teaches the word`);
    ok(await page.locator('.sheet button:has-text("Put it live")').isEnabled(), `${which}: spelling warns but never blocks`);
    await sheetButton(page, 'Not yet');
    await to(page, '#/words');
    await page.waitForSelector('.words li');
    ok((await page.textContent('.words')).includes('fulll'), `${which}: "Words I taught" lists it`);
    await page.locator('.words li', { hasText: 'fulll' }).locator('button').click();
    await page.waitForTimeout(400);
    ok(!('fulll' in JSON.parse(fs.readFileSync(path.join(devDir, 'private/words.json'), 'utf8'))), `${which}: and can remove it again`);
    await ctx.close();
  },

  async leadstub(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    const seen = [];
    page.on('request', (r) => { if (/send-lead|formsubmit|supabase/.test(r.url())) seen.push(r.url()); });
    await to(page, '#/preview/Contact.dc.html');
    const f = page.frameLocator('iframe.preview-frame');
    await f.locator('form input[name="name"]').first().waitFor({ timeout: 20000 });
    await f.locator('form input[name="name"]').first().fill('Test Person');
    await f.locator('form input[type="tel"]').first().fill('8125550100');
    await f.locator('form textarea').first().fill('Testing the preview form.');
    const resp = page.waitForResponse((r) => r.url().includes('/edit/p/send-lead.php'), { timeout: 20000 });
    await f.locator('form button[type="submit"]').first().evaluate((b) => b.click());
    const r = await resp.then((x) => x.json()).catch(() => null);
    ok(r && r.preview === true, `${which}: the preview's form goes to the stub, not the real mailer`);
    await page.waitForTimeout(1500);
    ok(!seen.some((u) => /\/send-lead\.php/.test(u) && !u.includes('/edit/p/')) && !seen.some((u) => /formsubmit|supabase/.test(u) && !u.includes('blocked')), `${which}: no real lead left the preview (${seen.join(' ')})`);
    await ctx.close();
  },

  async forgot(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await page.goto(base + '/edit/?forgot');
    await page.fill('input[name=login]', 'muneerah');
    await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
    ok((await page.textContent('h1')).includes('Check your email'), `${which}: "Check your email" after asking for a code`);
    const m = outbox().filter((x) => /Your code/.test(x.subject)).pop();
    const code = /(\d{6})/.exec(m.subject)[1];
    ok(await page.getAttribute('input[name=code]', 'autocomplete') === 'one-time-code' && await page.getAttribute('input[name=code]', 'inputmode') === 'numeric', `${which}: code box: number pad and one-time-code autofill`);
    await page.fill('input[name=code]', code);
    await page.fill('input[name=password]', 'brand-new-pass-2026');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.waitForSelector('.bar h1');
    ok(await page.textContent('.bar h1') === 'Your website', `${which}: new password set with the emailed code, signed in`);
    await ctx.close();
  },

  async people(browser, which) {
    const a = await newPhone(browser, which);
    const b = await newPhone(browser, which);
    await signIn(a.page, 0);
    await signIn(b.page, 1);
    await to(a.page, '#/people');
    await a.page.waitForSelector('.person');
    await a.page.click("button:has-text(\"Reset Muneerah's password\")");
    await a.page.waitForSelector('.sheet .code-big');
    const code = (await a.page.textContent('.sheet .code-big')).replace(/\s/g, '');
    ok(/^\d{6}$/.test(code), `${which}: John gets a 6-digit code to reset Muneerah's password`);
    await sheetButton(a.page, 'Done');
    await a.page.click('button:has-text("Remove Muneerah")');
    await sheetButton(a.page, 'Remove');
    await a.page.waitForTimeout(500);
    const st = await b.page.evaluate(async () => (await fetch('/edit/api/state')).status);
    ok(st === 401, `${which}: "Remove person" signs her out everywhere at once`);
    ok(outbox().some((m) => /was removed/.test(m.subject) && /Let Muneerah back in/.test(m.html)), `${which}: John is emailed, with a "Let her back in" button`);
    await a.page.click('button:has-text("Let them back in")');
    await a.page.waitForTimeout(400);
    await a.page.click('button:has-text("Make new rescue codes")');
    await sheetButton(a.page, 'Make new codes');
    await a.page.waitForSelector('.sheet .code-big');
    ok(await a.page.locator('.sheet .code-big').count() === 2, `${which}: two new rescue codes, shown once`);
    await a.ctx.close();
    await b.ctx.close();
  },

  async back(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    const ky = content().states.find((s) => s.code === 'KY');
    // a link straight to a state, opened fresh: Back goes up, never in a loop
    const p2 = await ctx.newPage();
    p2.errors = [];
    p2.on('pageerror', (e) => p2.errors.push(e.message));
    await p2.goto(base + '/edit/#/item/states/' + ky._id);
    await onScreen(p2, '#/item/states/');
    await p2.click('.bar .back');
    const up = await p2.waitForFunction(() => location.hash === '#/s/states' && document.querySelector('main.screen').dataset.hash === '#/s/states', null, { timeout: 10000 }).then(() => true, () => false);
    ok(up, `${which}: Back from a fresh link to Kentucky goes to States & contracts (${await p2.evaluate(() => location.hash)})`);
    await p2.click('.bar .back');
    const home = await p2.waitForFunction(() => location.hash === '#/' && document.querySelector('main.screen').dataset.hash === '#/', null, { timeout: 10000 }).then(() => true, () => false);
    ok(home, `${which}: Back again goes to the start (${await p2.evaluate(() => location.hash)})`);
    ok(p2.errors.length === 0, `${which}: no page errors (${p2.errors.join(' | ')})`);
    await ctx.close();
  },

  async emptyitem(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/reviews');
    await page.click('.list-box .btn.add');
    await onScreen(page, '#/item/home.reviews.items/');
    await page.click('.bar .back');
    await onScreen(page, '#/s/reviews');
    await page.waitForTimeout(600);
    const n = await page.locator('.list-box .list-item').count();
    ok(n === 0 && !(await page.isVisible('.traybar')), `${which}: Back from an empty new review leaves nothing behind (${n} items, bar ${await page.isVisible('.traybar') ? 'showing' : 'hidden'})`);
    ok(page.errors.length === 0, `${which}: no page errors (${page.errors.join(' | ')})`);
    await ctx.close();
  },

  async states(browser, which) {
    const { ctx, page } = await newPhone(browser, which);
    await signIn(page);
    await to(page, '#/s/states');
    await page.click('.list-box .btn.add');
    await onScreen(page, '#/item/states/');
    await page.selectOption('[data-key$=".code"] select', 'MI');
    await page.fill('[data-key$=".esign"] input', 'https://na2.documents.adobe.com/public/esignWidget?wid=CBFCIBAA3AAABLblqZhTESTMICHIGAN*');
    ok((await page.textContent('[data-key$=".licensed"]')).includes('Jonathan Nelson is licensed here'), `${which}: the licensed tick names Jonathan Nelson`);
    await page.locator('[data-key$=".licensed"] .switch').click();
    await saved(page);
    await to(page, '#/s/states');
    await page.waitForSelector('.beforeafter li', { timeout: 20000 });
    const also = await page.textContent('.beforeafter');
    ok(/Michigan/.test(also), `${which}: "This will also change…" shows the lines with Michigan`);
    await shot(page, which + '-states');
    const t = await putLive(page);
    ok(/Done/.test(t) && live('Claim.dc.html').includes('media/states/mi.webp') && !/Michigan/.test(live('Claim.dc.html').split('By phone')[1] || ''), `${which}: Michigan card is live with its outline`);
    await ctx.close();
  },
});

(async () => {
  for (const which of browsersArg.split(',')) {
    console.log(`\n${which}`);
    const browser = await pw[which].launch();
    for (const [name, fn] of Object.entries(FLOWS)) {
      if (only && !only.includes(name)) continue;
      reset();
      console.log(` ${name}`);
      try { await fn(browser, which); } catch (e) { ok(false, `${which} ${name}: ${e.message.split('\n')[0]}`); }
    }
    await browser.close();
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
