// Step 6 editor sweep: signs in and visits every editor screen at phone (390x844) and desktop
// (1280x800) sizes in Chromium and WebKit. Checks each screen draws, doesn't scroll sideways and
// raises no page errors; saves a screenshot of each.
//
//   node edit-dev/step6/editor-sweep.js <base> <devDir> [browsers=chromium,webkit] [outDir=<devDir>/sweep] [--setup]
//
//   --setup  make a fresh throwaway site in <devDir> (edit-dev/editor/dev-setup.php: it wipes
//            <devDir>/site and <devDir>/private first) and serve it with edit-dev/editor/router.php
//            on <base>'s port for the run (stopped at the end).
//            Without it, <base> must already serve <devDir> (edit/README.md, "Trying the editor locally").
//
// Screens are found, not listed: every literal route in edit/app/*.js, plus every "#/…" link and
// the editor's own navigation buttons (open an item, a photo, a contract) on every screen reached,
// starting from the start screen. Screens of the same kind with different data (items of one list,
// photos, contracts, history entries, leads…) are sampled (2–3 of each); pages, groups, tiles, help
// cards and previews are all visited. The sign-in pages and an expired email link are checked too.
// Read-only: it never saves, puts anything live, signs anyone out or sends a form, and
// send-lead.php is never reached (the dev router refuses it anyway).
// Writes <outDir>/sweep.json and <outDir>/shots/<browser>-<size>/NN-<screen>.jpg; exits 1 on any problem.
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const pw = require('/Users/program/Documents/joe/frontend/node_modules/playwright-core');

const REPO = path.resolve(__dirname, '../..');
const people = require(path.join(REPO, 'edit-dev/editor/dev-people.json')).people;
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const flags = new Set(process.argv.slice(2).filter((a) => a.startsWith('--')));
const [base, devDirArg, browsersArg = 'chromium,webkit', outArg] = args;
if (!base || !devDirArg) { console.error('usage: node editor-sweep.js <base> <devDir> [browsers] [outDir] [--setup]'); process.exit(2); }
const devDir = path.resolve(devDirArg);
const OUT = path.resolve(outArg || path.join(devDir, 'sweep'));
const SIZES = { phone: { width: 390, height: 844 }, desktop: { width: 1280, height: 800 } };
// kinds of screen where every one is visited; other kinds with a parameter are sampled
const ALL_OF = new Set(['page', 'group', 's', 'list', 'help', 'preview']);
const SAMPLE = { item: 2, photo: 3 };
const SAMPLE_OTHER = 2;
// public pages (no sign-in): the sign-in screens and an email link that has expired
const PUBLIC = [['signin', '/edit/'], ['forgot', '/edit/?forgot'], ['code', '/edit/?code'], ['rescue', '/edit/?rescue'], ['expired-link', '/edit/undo?t=step6-not-a-real-token']];

// ------------------------------------------------------------------ server (with --setup)

let server = null;
async function setup() {
  execFileSync('php', [path.join(REPO, 'edit-dev/editor/dev-setup.php'), devDir], { stdio: 'inherit', env: { ...process.env, TS_SITE_URL: base } });
  const port = new URL(base).port || '80';
  server = spawn('php', ['-S', `${new URL(base).hostname}:${port}`, '-t', path.join(devDir, 'site'), path.join(REPO, 'edit-dev/editor/router.php')], {
    env: { ...process.env, TS_WEBROOT: path.join(devDir, 'site'), TS_PRIVATE_DIR: path.join(devDir, 'private'), PHP_CLI_SERVER_WORKERS: '4' },
    stdio: ['ignore', 'ignore', fs.openSync(path.join(devDir, 'server.log'), 'a')],
    detached: true, // its own process group: PHP's worker processes are stopped with it
  });
  for (let i = 0; i < 50; i++) {
    try { const r = await fetch(base + '/edit/'); if (r.status < 500) return; } catch (e) { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error('the editor server did not start on ' + base);
}
function stopServer() {
  if (!server) return;
  try { process.kill(-server.pid, 'SIGTERM'); } catch (e) { try { server.kill(); } catch (e2) { /* gone */ } }
  server = null;
}
process.on('SIGINT', () => { stopServer(); process.exit(130); });

// ------------------------------------------------------------------ helpers

async function newContext(browser, which, size) {
  const vp = SIZES[size];
  const opts = size === 'phone'
    ? (which === 'webkit' ? { ...pw.devices['iPhone 13'], viewport: vp } : { viewport: vp, deviceScaleFactor: 2, isMobile: true, hasTouch: true })
    : { viewport: vp, deviceScaleFactor: 1 };
  const ctx = await browser.newContext(opts);
  // nothing but the editor's own site (the preview's security policy blocks other hosts anyway)
  const origin = new URL(base).origin;
  await ctx.route('**/*', (r) => (r.request().url().startsWith(origin) ? r.continue() : r.abort()));
  const page = await ctx.newPage();
  page.errors = [];
  page.failed = [];
  page.on('pageerror', (e) => page.errors.push('page error: ' + String(e.message || e).split('\n')[0]));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/Failed to load resource/.test(t)) return; // listed from the responses instead
    // Playwright's WebKit screenshots add a style tag, which the editor's security policy refuses
    if (/Refused to apply a stylesheet|Refused to apply inline style/.test(t) && Date.now() - (page.shotAt || 0) < 3000) return;
    page.errors.push('console: ' + t.slice(0, 300));
  });
  page.on('response', (r) => { if (r.status() >= 400 && r.url().startsWith(origin) && !/\/edit\/undo\?t=step6/.test(r.url())) page.failed.push(`${r.status()} ${r.url().replace(origin, '')}`); });
  page.on('dialog', (d) => d.dismiss());
  return { ctx, page };
}

async function signIn(page, who = 0) {
  await page.goto(base + '/edit/');
  await page.fill('#username', people[who].username);
  await page.fill('#password', people[who].password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.waitForSelector('.bar h1', { timeout: 20000 });
}

/** Go to a hash; resolves with the hash of the screen the editor actually shows. */
async function open(page, hash) {
  const now = () => page.evaluate(() => { const m = document.querySelector('main.screen'); return m ? m.dataset.hash || '' : ''; });
  const before = await now();
  await page.evaluate((x) => { if (location.hash === x) window.dispatchEvent(new HashChangeEvent('hashchange')); else location.hash = x; }, hash);
  const t0 = Date.now();
  while (Date.now() - t0 < 10000) {
    const h = await now();
    if (h === hash) return h;
    if (h && h !== before && Date.now() - t0 > 600) return h; // another screen drew instead (unknown route -> start)
    await page.waitForTimeout(120);
  }
  return now();
}

async function settle(page, hash) {
  await page.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => {});
  if (/^#\/(preview|fix)/.test(hash)) {
    await page.waitForFunction(() => { const f = document.querySelector('iframe'); return !f || (f.contentDocument && f.contentDocument.readyState === 'complete'); }, null, { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await page.waitForTimeout(400);
}

/* eslint-disable no-undef */
function screenChecks() {
  const vw = Math.min(window.innerWidth, document.documentElement.clientWidth);
  const se = document.scrollingElement || document.documentElement;
  const res = { problems: [], m: {} };
  const where = (el) => {
    const cls = typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    const key = el.closest('[data-key]');
    const t = (el.innerText || el.value || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return el.tagName.toLowerCase() + cls + (key ? ` [${key.getAttribute('data-key')}]` : '') + (t ? ` "${t}"` : '');
  };
  res.m.scrollWidth = se.scrollWidth;
  res.m.width = vw;
  res.m.height = se.scrollHeight;
  const offenders = [];
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height || r.right <= vw + 1) continue;
    const shut = el.closest('details:not([open])');
    if (shut && !el.closest('summary')) continue; // inside a closed section: not on screen
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.position === 'fixed' && r.left >= vw) continue;
    let clipped = false;
    for (let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
      const acs = getComputedStyle(a);
      if (/hidden|clip|auto|scroll/.test(acs.overflowX)) { const ar = a.getBoundingClientRect(); if (ar.right <= vw + 1 && ar.left >= -1) { clipped = true; break; } }
    }
    if (!clipped) offenders.push(where(el) + ` (${Math.round(r.left)}…${Math.round(r.right)})`);
    if (offenders.length >= 5) break;
  }
  if (se.scrollWidth > vw + 0.5) res.problems.push({ check: 'overflow-x', msg: `the screen scrolls sideways: ${se.scrollWidth}px of content in ${vw}px` + (offenders.length ? ` (${offenders.join(' | ')})` : '') });
  else if (offenders.length) res.problems.push({ check: 'overflow-x', msg: 'parts stick out past the right edge (clipped by the page): ' + offenders.join(' | ') });
  const title = document.querySelector('.bar h1, main h1, h1');
  res.m.title = title ? title.textContent.trim() : '';
  if (!res.m.title) res.problems.push({ check: 'drawn', msg: 'no screen title' });
  const err = Array.from(document.querySelectorAll('.toast.bad, .toast.err, .callout.bad')).map((t) => t.textContent.trim()).filter(Boolean);
  if (err.length) res.problems.push({ check: 'message', msg: 'error message on screen: ' + err.join(' | ').slice(0, 200) });
  return res;
}

/** Hashes reachable from the current screen: links, plus navigation buttons (clicked, then back). */
async function linksFrom(page, here) {
  const hrefs = await page.evaluate((h) => {
    const out = Array.from(document.querySelectorAll('a[href^="#/"]')).map((a) => a.getAttribute('href'));
    // the page picker on the preview and "Fix a word I saw" screens
    const m = /^#\/(preview|fix)\b/.exec(h);
    if (m) document.querySelectorAll('.preview-controls select option').forEach((o) => out.push(`#/${m[1]}/${o.value}`));
    return out;
  }, here);
  const buttons = await page.evaluate(() => {
    const out = [];
    const seen = {};
    const all = Array.from(document.querySelectorAll('button.item-open, button.media-thumb, button'));
    all.forEach((b, i) => {
      const kind = b.matches('button.item-open') ? 'item' : b.matches('button.media-thumb') ? 'photo' : /^(Replace or view|Add the PDF)$/.test((b.textContent || '').trim()) ? 'contract' : null;
      if (!kind) return;
      const holder = b.closest('[data-key]');
      const group = kind + ':' + (kind === 'item' && holder ? holder.getAttribute('data-key').replace(/\[[a-z0-9]+\][^[]*$/, '') : '');
      seen[group] = (seen[group] || 0) + 1;
      if (seen[group] <= (kind === 'photo' ? 3 : 2)) out.push(i);
    });
    return out;
  });
  const found = [...hrefs];
  for (const i of buttons) {
    const to = await page.evaluate(async (k) => {
      const b = Array.from(document.querySelectorAll('button.item-open, button.media-thumb, button'))[k];
      if (!b) return null;
      const before = location.hash;
      b.click();
      await new Promise((r) => setTimeout(r, 80));
      return location.hash !== before ? location.hash : null;
    }, i).catch(() => null);
    if (to) { found.push(to); await open(page, here); await page.waitForTimeout(150); }
  }
  return found;
}

function kindOf(hash) {
  const m = /^#\/([a-z-]*)(?:\/(.*))?$/.exec(hash);
  if (!m) return { kind: hash, cap: 1 };
  const [, seg, rest] = m;
  if (rest === undefined || rest === '') return { kind: seg || 'start', cap: Infinity };
  if (ALL_OF.has(seg)) return { kind: seg + ':' + rest, cap: Infinity };
  if (seg === 'item') return { kind: 'item:' + decodeURIComponent(rest.split('/')[0]).replace(/\[[a-z0-9]+\]/g, '[]'), cap: SAMPLE.item };
  return { kind: seg + '/*', cap: SAMPLE[seg] || SAMPLE_OTHER };
}

function literalRoutes() {
  const out = [];
  for (const f of fs.readdirSync(path.join(REPO, 'edit/app')).filter((x) => x.endsWith('.js'))) {
    const src = fs.readFileSync(path.join(REPO, 'edit/app', f), 'utf8');
    for (const m of src.matchAll(/route\(\/\^(.+?)\$\/\s*,/g)) {
      let s = m[1].replace(/\(\?:[^()]*\([^()]*\)\)\?/g, '').replace(/\\\/\?/g, '/');
      if (/[()[\]+*?|]/.test(s.replace(/\\\//g, ''))) continue; // has a parameter
      out.push(s.replace(/\\\//g, '/'));
    }
  }
  return out;
}

// ------------------------------------------------------------------ discovery

async function discover(browser, which) {
  const { ctx, page } = await newContext(browser, which, 'phone');
  await signIn(page);
  const order = [];
  const counts = {};
  const queue = ['#/', ...literalRoutes()];
  const seen = new Set();
  const take = (h) => {
    if (seen.has(h)) return;
    const { kind, cap } = kindOf(h);
    if ((counts[kind] || 0) >= cap) return;
    seen.add(h);
    counts[kind] = (counts[kind] || 0) + 1;
    queue.push(h);
  };
  queue.splice(0).forEach(take);
  while (queue.length) {
    const h = queue.shift();
    const shown = await open(page, h);
    if (shown !== h) { order.push({ hash: h, drawn: shown }); continue; }
    await settle(page, h);
    order.push({ hash: h, drawn: h });
    for (const x of await linksFrom(page, h)) take(x);
  }
  await ctx.close();
  return order;
}

// ------------------------------------------------------------------ the sweep

(async () => {
  let exit = 0;
  try {
    if (flags.has('--setup')) await setup();
    fs.mkdirSync(OUT, { recursive: true });
    const browsers = browsersArg.split(',');
    const t0 = Date.now();
    const first = await pw[browsers[0]].launch();
    const screens = await discover(first, browsers[0]);
    await first.close();
    const reachable = screens.filter((s) => s.drawn === s.hash).map((s) => s.hash);
    const notDrawn = screens.filter((s) => s.drawn !== s.hash);
    console.log(`found ${reachable.length} screens (${Math.round((Date.now() - t0) / 1000)}s)` + (notDrawn.length ? `; not drawn: ${notDrawn.map((s) => s.hash + ' -> ' + (s.drawn || 'nothing')).join(', ')}` : ''));
    const visits = [];
    for (const which of browsers) {
      const browser = await pw[which].launch();
      for (const size of Object.keys(SIZES)) {
        const dir = path.join(OUT, 'shots', `${which}-${size}`);
        fs.rmSync(dir, { recursive: true, force: true });
        fs.mkdirSync(dir, { recursive: true });
        const { ctx, page } = await newContext(browser, which, size);
        let n = 0;
        const shot = async (name, full = true) => {
          const file = path.join(dir, `${String(++n).padStart(2, '0')}-${name.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '').slice(0, 60) || 'start'}.jpg`);
          page.shotAt = Date.now();
          await page.screenshot({ path: file, fullPage: full, type: 'jpeg', quality: 70 }).catch(() => {});
          page.shotAt = Date.now();
          return path.relative(OUT, file);
        };
        const record = async (screen, extra = {}) => {
          const c = await page.evaluate(screenChecks);
          const problems = [...c.problems, ...extra.problems || []];
          // the same screen with every closed section opened (what she sees after tapping them)
          let openedShot = null;
          const opened = await page.evaluate(() => { const d = Array.from(document.querySelectorAll('details:not([open])')); d.forEach((x) => { x.open = true; x.dataset.sweepOpened = '1'; }); return d.length; });
          if (opened) {
            await page.waitForTimeout(200);
            const o = await page.evaluate(screenChecks);
            const known = new Set(c.problems.map((p) => p.msg));
            const extraOpen = o.problems.filter((p) => !known.has(p.msg) && p.check === 'overflow-x');
            for (const p of extraOpen) problems.push({ check: p.check, msg: p.msg + ` (with its ${opened} closed sections opened)` });
            if (extraOpen.length) openedShot = await shot(screen + '-opened');
            await page.evaluate(() => document.querySelectorAll('details[data-sweep-opened]').forEach((x) => { x.open = false; delete x.dataset.sweepOpened; }));
          }
          for (const e of page.errors.splice(0)) problems.push({ check: 'errors', msg: e });
          for (const f of page.failed.splice(0)) problems.push({ check: 'requests', msg: f });
          const row = { browser: which, size, screen, title: c.m.title, width: c.m.width, scrollWidth: c.m.scrollWidth, height: c.m.height, problems, shot: await shot(screen, !/^#\/(preview|fix)/.test(screen)), openedShot };
          visits.push(row);
          console.log(`${which} ${size} ${screen.padEnd(34)} ${problems.length ? 'FAIL ' + problems.map((p) => p.check + ': ' + p.msg).join(' || ').slice(0, 260) : 'ok'}`);
        };
        for (const [name, url] of PUBLIC) {
          await page.goto(base + url);
          await page.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => {});
          await page.waitForTimeout(300);
          await record('public:' + name);
        }
        await signIn(page);
        for (const h of reachable) {
          page.errors.length = 0;
          page.failed.length = 0;
          const shown = await open(page, h);
          if (shown !== h) { await record(h, { problems: [{ check: 'drawn', msg: `did not draw (shows ${shown || 'nothing'})` }] }); continue; }
          await settle(page, h);
          await record(h);
        }
        await ctx.close();
      }
      await browser.close();
    }
    const bad = visits.filter((v) => v.problems.length);
    const summary = { base, screens: reachable, notDrawn, visits: visits.length, passed: visits.length - bad.length, failed: bad.length, seconds: Math.round((Date.now() - t0) / 1000) };
    fs.writeFileSync(path.join(OUT, 'sweep.json'), JSON.stringify({ summary, visits }, null, 1));
    console.log(`\n${summary.visits} screen visits (${reachable.length} screens + ${PUBLIC.length} sign-in pages, ${browsers.join(' + ')}, phone + desktop): ${summary.passed} ok, ${summary.failed} with problems. ${path.join(OUT, 'sweep.json')}`);
    exit = bad.length || notDrawn.length ? 1 : 0;
  } catch (e) {
    console.error(e);
    exit = 2;
  } finally {
    stopServer();
  }
  process.exit(exit);
})();
