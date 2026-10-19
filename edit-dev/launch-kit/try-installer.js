// Clicks through the installer in Chromium and WebKit at iPhone size, the way John and
// Muneerah would on the screen-share: setup code, checks, private folder, people, leads, set
// it up, the set-your-password code used on "their phone", the emergency sheet (printed),
// the locks, then "Reinstall and keep my content". Uses a throwaway hosting-like copy
// (try-install.php) served by php -S with TS_INSTALL_DEV=1, so every email stays in the outbox.
//   node edit-dev/launch-kit/try-installer.js <base> <dir> [chromium,webkit] [--pictures]
// --pictures also remakes edit-dev/launch-kit/pictures/*.jpg (the guide's screens, from WebKit).
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');

const [base, dir, browsersArg = 'chromium,webkit', ...flags] = process.argv.slice(2);
if (!base || !dir) { console.error('usage: node try-installer.js <base> <dir> [chromium,webkit] [--pictures]'); process.exit(2); }
const pictures = flags.includes('--pictures');
const CODE = 'TRY-CODE-2026-HAIL';
const here = __dirname;
const picDir = path.join(here, 'pictures');
const shots = path.join(dir, 'shots');
fs.mkdirSync(shots, { recursive: true });
const priv = path.join(dir, 'thinksmart-private');

let pass = 0;
let fail = 0;
function ok(cond, what) {
  if (cond) { pass++; console.log('  ok    ' + what); } else { fail++; console.log('  FAIL  ' + what); }
}
const reset = () => execFileSync('php', [path.join(here, 'try-install.php'), dir, CODE], { stdio: 'ignore' });
const outbox = () => { const d = path.join(priv, 'outbox'); return fs.existsSync(d) ? fs.readdirSync(d).sort().map((f) => JSON.parse(fs.readFileSync(path.join(d, f), 'utf8'))) : []; };
const json = (f) => JSON.parse(fs.readFileSync(path.join(priv, f), 'utf8'));

async function newPhone(browser, which) {
  const opts = which === 'webkit' ? { ...pw.devices['iPhone 13'] } : { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
  const ctx = await browser.newContext(opts);
  await ctx.addInitScript(() => { window.__printed = 0; window.print = () => { window.__printed++; }; });
  const page = await ctx.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('console', (m) => {
    if (m.type() !== 'error' || /Failed to load resource/.test(m.text())) return;
    // Playwright's WebKit screenshots add a style tag, which the strict security policy refuses
    if (/Refused to apply a stylesheet|Refused to apply inline style/.test(m.text()) && Date.now() - (page.shotAt || 0) < 3000) return;
    page.errors.push(m.text());
  });
  return { ctx, page };
}

// The guide's pictures show the hosting, not this computer: the few test-only lines are swapped
// for the exact words the installer shows on the real hosting (InstallChecks), and the address.
const REAL = [
  ['Testing on this computer, where https isn\'t needed.', 'This page opened with https:// at the start.'],
  [/^Testing on this computer \(.*\)\.$/, 'https://www.thinksmartpublicadjusting.com (links in the editor\'s emails use it).'],
  ['Testing on this computer.', 'The website is in /home/thinksmart/public_html, the hosting\'s main website folder.'],
  [/http:\/\/localhost:8792/g, 'https://www.thinksmartpublicadjusting.com'],
  [/localhost:8792/g, 'www.thinksmartpublicadjusting.com'],
];
async function asOnTheHosting(page) {
  await page.evaluate((rules) => {
    const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n = walk.nextNode(); n; n = walk.nextNode()) {
      let t = n.nodeValue;
      for (const [kind, from, to, flags] of rules) t = kind === 're' ? t.replace(new RegExp(from, flags), to) : (t.trim() === from ? t.replace(from, to) : t);
      n.nodeValue = t;
    }
  }, REAL.map(([f, t]) => (f instanceof RegExp ? ['re', f.source, t, f.flags] : ['text', f, t, ''])));
}

const sideways = [];
async function shot(page, which, name, pic) {
  page.shotAt = Date.now();
  await page.screenshot({ path: path.join(shots, `${which}-${name}.png`), fullPage: true });
  // with the real (longer) address in place, nothing may stick out sideways at iPhone width
  await asOnTheHosting(page);
  const over = await page.evaluate(() => {
    let worst = document.documentElement.scrollWidth - window.innerWidth;
    for (const el of document.querySelectorAll('main *')) {
      const box = el.closest('.signin-card, .inst-sheet, .inst-tools');
      if (box && box !== el) worst = Math.max(worst, el.getBoundingClientRect().right - box.getBoundingClientRect().right);
    }
    return Math.round(worst);
  });
  if (over > 0) sideways.push(`${which} ${name} (${over}px past its card)`);
  if (pictures && pic && which === 'webkit') {
    page.shotAt = Date.now();
    await page.screenshot({ path: path.join(picDir, pic), type: 'jpeg', quality: 80, scale: 'css' });
  }
}

async function submit(page, buttonText, urlPart, timeout = 20000) {
  await Promise.all([page.waitForURL((u) => u.pathname.includes(urlPart), { timeout }), page.click(`button[type=submit]:has-text("${buttonText}")`)]);
}

async function run(which) {
  console.log(`\n${which}`);
  reset();
  const browser = await pw[which].launch();
  const { ctx, page } = await newPhone(browser, which);
  try {
    // ------------------------------------------------ the setup code
    let res = await page.goto(base + '/edit/install');
    ok(res.status() === 200 && (await page.textContent('h1')) === 'Set up the website editor', 'the setup page opens and asks for the setup code');
    const csp = res.headers()['content-security-policy'] || '';
    ok(/script-src 'self'/.test(csp) && /frame-ancestors 'none'/.test(csp), 'with the strict security policy');
    ok(await page.evaluate(() => document.activeElement && document.activeElement.id === 'code'), 'the cursor is in the setup code box');
    await shot(page, which, '1-code', '1-code.jpg');
    await page.fill('#code', 'WRONG-CODE-1');
    await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
    ok((await page.textContent('.msg.bad')).includes('isn\'t right'), 'a wrong code: "That setup code isn\'t right"');
    ok(!fs.existsSync(priv), 'and nothing is made');
    await page.fill('#code', 'try code 2026 hail');
    await submit(page, 'Start', '/edit/install/checks');
    // ------------------------------------------------ checks
    const okRows = await page.locator('.inst-check.is-ok').count();
    ok(okRows === 7 && (await page.locator('.inst-check.is-stop').count()) === 0, `checks: ${okRows} fine, nothing red`);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    ok(overflow <= 0, 'nothing sticks out sideways at iPhone width');
    await shot(page, which, '2-checks', '2-checks.jpg');
    await submit(page, 'Next: make the private folder', '/edit/install/people');
    ok(fs.existsSync(priv) && (fs.statSync(priv).mode & 0o777) === 0o700, 'the private folder is made, 0700');
    // ------------------------------------------------ people
    ok((await page.inputValue('input[name="p[0][name]"]')) === 'John' && (await page.inputValue('input[name="p[1][name]"]')) === 'Muneerah', 'John and Muneerah are filled in');
    ok((await page.getAttribute('input[name="p[0][username]"]', 'autocapitalize')) === 'none' && (await page.getAttribute('input[name="p[0][username]"]', 'autocorrect')) === 'off', 'usernames: no auto-capitals, no autocorrect');
    await page.fill('input[name="p[0][username]"]', 'jnelson-77');
    await page.fill('input[name="p[0][email]"]', 'john@example.test');
    await page.fill('input[name="p[1][username]"]', 'mun-2026');
    await page.fill('input[name="p[1][email]"]', 'muneerah@example.test');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await shot(page, which, '3-people', '3-people.jpg');
    await page.fill('input[name="p[0][username]"]', 'john');
    await Promise.all([page.waitForLoadState('load'), page.click('button[type=submit]')]);
    const marked = await page.$$eval('[aria-invalid="true"]', (els) => els.map((e) => e.name));
    const errColor = await page.$eval('.bad-text', (e) => getComputedStyle(e).color);
    ok(marked.join() === 'p[0][username]' && (await page.textContent('.bad-text')).includes('easy to guess') && errColor !== 'rgb(91, 98, 112)',
      'a guessable username is marked, in red, right under its box');
    await shot(page, which, '3-people-error');
    await page.fill('input[name="p[0][username]"]', 'jnelson-77');
    await submit(page, 'Next', '/edit/install/leads');
    // ------------------------------------------------ leads
    ok((await page.inputValue('input[name=lead]')) === 'thinksmartpublicadjusting@gmail.com', 'leads go to the business Gmail unless changed');
    ok((await page.textContent('.inst-note')).includes('The 4 from the last 90 days'), 'the old lead copies are counted');
    await shot(page, which, '4-leads', '4-leads.jpg');
    await submit(page, 'Next', '/edit/install/finish');
    ok((await page.textContent('.inst-summary')).includes('jnelson-77') && (await page.textContent('.inst-summary')).includes('think-smart-launch-kit-2026-10-18.zip'), 'the summary lists the people and the zip to delete');
    await shot(page, which, '5-finish');
    await submit(page, 'Set it up', '/edit/install/done', 90000);
    // ------------------------------------------------ passwords
    const codes = await page.$$eval('.code-big', (els) => els.map((e) => e.textContent.replace(/\s/g, '')));
    ok(codes.length === 2 && codes.every((c) => /^\d{6}$/.test(c)), 'a 6-digit code for each person');
    const mails = outbox();
    const codeMails = mails.filter((m) => m.subject === 'Set your password for the website editor');
    ok(codeMails.length === 2 && codeMails.some((m) => m.to[0] === 'john@example.test' && m.text.includes(codes[0])), 'and the same codes are emailed');
    ok(mails.some((m) => /Test lead/.test(m.subject) && m.to[0] === 'thinksmartpublicadjusting@gmail.com'), 'the test lead goes to the lead address');
    ok(!fs.existsSync(path.join(dir, 'thinksmart-leads')) && !fs.existsSync(path.join(dir, 'public_html', 'think-smart-launch-kit-2026-10-18.zip')), 'the old lead logs and the leftover zip are gone');
    ok(fs.existsSync(path.join(priv, 'install.lock')) && fs.existsSync(path.join(dir, 'public_html', 'edit', '.installed')), 'both locks are there');
    await shot(page, which, '6-passwords', '5-passwords.jpg');
    // ------------------------------------------------ "on John's phone": /edit/?code
    const phone = await newPhone(browser, which);
    await phone.page.goto(base + '/edit/?code');
    await phone.page.fill('input[name=login]', 'jnelson-77');
    await phone.page.fill('input[name=code]', codes[0]);
    await phone.page.fill('input[name=password]', 'Hail-Storm-Tree-42-blue');
    ok((await phone.page.getAttribute('input[name=password]', 'autocomplete')) === 'new-password' && (await phone.page.getAttribute('input[name=login]', 'autocomplete')) === 'username', 'the code page is made for iPhone Passwords (username + new-password)');
    await shot(phone.page, which, '7-phone', '6-phone.jpg');
    await Promise.all([phone.page.waitForURL((u) => u.pathname === '/edit/'), phone.page.click('button[type=submit]')]);
    await phone.page.waitForSelector('.bar h1', { timeout: 20000 });
    ok(true, 'John sets his password with the code and the editor opens');
    // ------------------------------------------------ the emergency sheet
    await Promise.all([page.waitForURL((u) => u.pathname.endsWith('/sheet')), page.click('a:has-text("Next: the emergency sheet")')]);
    const rows = await page.$$eval('.inst-table tbody tr', (trs) => trs.map((tr) => tr.textContent));
    ok(rows.length === 2 && rows[0].includes('jnelson-77') && /[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}/.test(rows[0]), 'the sheet: each person\'s username and a rescue code');
    ok((await page.locator('.inst-second li').count()) === 2, 'the second rescue codes, for the Passwords app');
    await shot(page, which, '8-sheet', '7-sheet.jpg');
    await page.click('[data-print]');
    ok((await page.evaluate(() => window.__printed)) === 1, 'Print opens the print dialog (install.js, no inline script)');
    await page.emulateMedia({ media: 'print' });
    const printed = await page.evaluate(() => ({ tools: getComputedStyle(document.querySelector('.inst-tools')).display, sheet: getComputedStyle(document.querySelector('.inst-sheet')).display, bg: getComputedStyle(document.body).backgroundColor }));
    ok(printed.tools === 'none' && printed.sheet !== 'none', 'on paper only the sheet shows (no buttons, no second codes)');
    page.shotAt = Date.now();
    await page.screenshot({ path: path.join(shots, `${which}-8-sheet-print.png`), fullPage: true });
    if (which === 'chromium') {
      const pdf = await page.pdf({ format: 'Letter', printBackground: true });
      fs.writeFileSync(path.join(shots, 'sheet.pdf'), pdf);
      const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
      ok(pages === 1, `the emergency sheet prints on one Letter page (${pages})`);
    }
    await page.emulateMedia({ media: 'screen' });
    await submit(page, 'Done', '/edit/install/sheet');
    ok((await page.textContent('h1')) === 'All set', '"Done": All set');
    res = await page.goto(base + '/edit/install');
    ok(res.status() === 404, '/edit/install answers 404 now');
    res = await page.goto(base + '/edit/install/checks');
    ok(res.status() === 404, 'and so does every step');
    // ------------------------------------------------ reinstall and keep my content
    const before = json('users.json').people.map((p) => p.id).join();
    const secret = json('config.json').secret;
    await page.goto(base + '/edit/install/reinstall');
    ok((await page.locator('#code').count()) === 0 && (await page.textContent('main')).includes('Extract'), 'once set up, a reinstall first asks for the launch kit to be unzipped again');
    // the kit unzipped again (cPanel → File Manager → Extract rewrites edit/install-code.hash)
    const past = new Date(Date.now() - 120000);
    for (const f of [path.join(priv, 'install.lock'), path.join(dir, 'public_html', 'edit', '.installed')]) if (fs.existsSync(f)) fs.utimesSync(f, past, past);
    const hashFile = path.join(dir, 'public_html', 'edit', 'install-code.hash');
    fs.writeFileSync(hashFile, fs.readFileSync(hashFile));
    await page.goto(base + '/edit/install/reinstall');
    ok((await page.textContent('h1')) === 'Reinstall and keep my content', 'Reinstall and keep my content asks for the setup code');
    await page.fill('#code', CODE);
    await submit(page, 'Start', '/edit/install/checks');
    const alerts = outbox().filter((m) => m.kind === 'alert' && m.subject === 'The website editor is being set up again');
    ok(alerts.length === 1, 'John gets an email when a reinstall starts');
    await submit(page, 'Next: make the private folder', '/edit/install/people');
    ok((await page.textContent('.inst-list')).includes('same password as before'), 'the people are kept');
    await page.check('input[name=newRescue]');
    await submit(page, 'Next', '/edit/install/finish');
    ok((await page.textContent('.inst-summary')).includes('Kept as it is'), 'the content is kept');
    await submit(page, 'Set it up', '/edit/install/done', 90000);
    ok((await page.locator('.code-big').count()) === 1 && (await page.textContent('main')).includes('John signs in with the same password as before'), 'only Muneerah (no password yet) gets a new code');
    ok(json('users.json').people.map((p) => p.id).join() === before && json('config.json').secret !== secret, 'same logins, a new secret');
    await phone.page.goto(base + '/edit/');
    ok(await phone.page.locator('#username').count() === 1, 'so John signs in again (the old session is gone)');
    await page.click('a:has-text("Next: the emergency sheet")');
    await page.waitForURL((u) => u.pathname.endsWith('/sheet'));
    await submit(page, 'Done', '/edit/install/sheet');
    res = await page.goto(base + '/edit/install');
    ok(res.status() === 404, 'locked again');
    ok(page.errors.length === 0 && phone.page.errors.length === 0, 'no page errors' + (page.errors.length + phone.page.errors.length ? ': ' + page.errors.concat(phone.page.errors).join(' | ') : ''));
    await phone.ctx.close();
  } catch (e) {
    ok(false, 'flow stopped: ' + e.message.split('\n')[0]);
    page.shotAt = Date.now();
    await page.screenshot({ path: path.join(shots, `${which}-stopped.png`), fullPage: true }).catch(() => {});
  } finally {
    await ctx.close();
    await browser.close();
  }
}

(async () => {
  if (pictures) fs.mkdirSync(picDir, { recursive: true });
  for (const which of browsersArg.split(',')) await run(which);
  console.log('');
  ok(sideways.length === 0, 'no page sticks out sideways at iPhone width, with the real address' + (sideways.length ? ': ' + sideways.join(', ') : ''));
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
