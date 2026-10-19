// The editor app: loads everything, draws the header and the "Not live yet" bar, routes
// between screens, puts changes live (and keeps going if the connection drops), and keeps up
// with changes the other person put live.
import { S, h, icon, clear, api, on, emit, route, render, go, back, replaceHash, currentOps, loadDraft, rebase, flushDraft, saveDraft, toast, sheet, keyOf, parseKey, getAt, schemaPath, field, newId, timeOf, ApiError, currentScreen, applyOps, changed, joinList } from './core.js';
import { startScreen, tileScreen, pagesScreen, pageScreen, moreScreen, groupScreen, noticeScreen, noticeStatus, reveal } from './screens.js';
import { listEditor, itemScreen } from './lists.js';
import { photosScreen, photoScreen, contractScreen, contractsCheckScreen } from './media.js';
import { trayScreen, historyScreen, historyEntryScreen, verify, checkScreen, pageFor, changeCount } from './live.js';
import { previewScreen, fixScreen, searchScreen } from './preview.js';
import { peopleScreen, wordsScreen, helpScreen, helpCard } from './extras.js';
import { leadsScreen, leadScreen } from './leads.js';

const app = document.getElementById('app');
const bar = h('header.bar');
const main = h('main.screen', { id: 'main', tabIndex: -1 });
const tray = h('div.traybar', { hidden: true });
const saveNote = h('span.save-state', { 'aria-live': 'polite' });

function shell() {
  clear(app).append(bar, main, tray);
}

// while she types, the bottom bar steps aside for the keyboard (app.css: body.typing)
const TYPING = 'input:not([type=checkbox]):not([type=radio]):not([type=file]), textarea, [contenteditable=true]';
document.addEventListener('focusin', (e) => { if (e.target instanceof Element && e.target.matches(TYPING)) document.body.classList.add('typing'); });
document.addEventListener('focusout', () => {
  setTimeout(() => { const a = document.activeElement; if (!(a instanceof Element && a.matches(TYPING))) document.body.classList.remove('typing'); }, 100);
});
// iPhone Safari shows the :active tap feedback only when a touch listener exists
document.addEventListener('touchstart', () => {}, { passive: true });

function drawBar(screen) {
  clear(bar);
  if (!screen.home) {
    bar.append(h('button.back', { type: 'button', 'aria-label': 'Back', onclick: () => back(screen.back || '#/') }, icon('back'), h('span', { text: 'Back' })));
  } else {
    bar.append(h('span.brand', h('span.dot'), 'Think Smart'));
  }
  bar.append(h('h1', { text: screen.title || '' }));
  const right = h('div.bar-right', saveNote);
  if (screen.action) right.append(h('a.bar-action', { href: screen.action.href, text: screen.action.label }));
  bar.append(right);
  drawSave();
}

function drawSave() {
  const st = S.saveState;
  saveNote.textContent = st === 'saving' ? 'Saving…' : st === 'offline' ? 'Saved on this phone' : st === 'unsaved' ? 'Saving…' : currentOps().length ? 'Saved' : '';
  saveNote.className = 'save-state ' + st;
}

function drawTray() {
  const n = changeCount(currentOps());
  const hash = (currentScreen() || {}).hash || '';
  // the notice screen has its own button at the bottom
  tray.hidden = n === 0 || hash.startsWith('#/tray') || hash === '#/notice';
  if (tray.hidden) return;
  clear(tray).append(
    h('a.tray-link', { href: '#/tray' }, h('span.tray-count', { text: String(n) }), h('span', { text: 'Not live yet' })),
    h('a.btn.primary', { href: '#/tray', text: 'Put it live…' }));
}

on('change', () => { drawSave(); drawTray(); });
on('save', drawSave);
on('screen', (screen) => {
  drawBar(screen);
  clear(main).append(screen.body);
  main.dataset.hash = screen.hash;
  main.classList.toggle('wide', !!screen.wide);
  drawTray();
  if (S.reveal) { const k = S.reveal; S.reveal = null; requestAnimationFrame(() => setTimeout(() => reveal(k), 60)); }
});
on('choice', (p) => {
  const scr = currentScreen();
  if (scr && scr.redrawOnChoice && keyOf(p).startsWith(scr.redrawOnChoice)) render();
});
on('signedout', () => {
  S.saveState = 'offline';
  drawSave();
  // after signing in again she comes back to this screen
  try { sessionStorage.setItem('ts-return', location.hash); } catch (e) { /* private mode */ }
  sheet('You\'re signed out', 'Your changes are kept. Sign in again to carry on.', [{ label: 'Sign in', value: true, kind: 'primary' }]).then(() => { location.href = '/edit/'; });
});
on('stale', () => { location.reload(); });

// ------------------------------------------------------------------ putting changes live

function progress(text) {
  let back = document.querySelector('.busy');
  if (!back) { back = h('div.busy', h('div.busy-box', h('span.spinner'), h('p'))); document.body.append(back); }
  back.querySelector('p').textContent = text;
  return {
    close: () => back.remove(),
    say: (t) => { back.querySelector('p').textContent = t; },
    button: (label, fn) => { if (back.isConnected && !back.querySelector('.btn')) back.querySelector('.busy-box').append(h('button.btn.small', { type: 'button', text: label, onclick: fn })); },
  };
}

async function waitFor(id, ms = 120000) {
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    await new Promise((r) => setTimeout(r, 3000));
    try {
      const st = await api('state');
      if (st.status && st.status.id === id) {
        if (st.status.state === 'done') return 'done';
        if (['failed', 'stopped', 'nothing'].includes(st.status.state)) return st.status.state;
      }
    } catch (e) { /* still offline: keep waiting */ }
  }
  return null;
}

async function refreshLive() {
  const r = await api('live');
  rebase(r.live, r.draft);
  S.base = r.live.rev;
  S.conflicts = r.draft.conflicts || [];
}

/** She saw this one go live: the start screen needn't say it again. */
function seenLive(id) {
  try { localStorage.setItem('ts-seen-live', id); localStorage.setItem('ts-has-published', '1'); } catch (e) { /* private mode */ }
}

const doneTitle = (ops) => { const n = changeCount(ops); return n > 1 ? `Done. Your ${n} changes are live.` : 'Done. Your change is live.'; };
const OK = [{ label: 'OK', value: true, kind: 'primary' }];

/**
 * A new phone, email or address went live: the places the website can't change still show the
 * old one. Remembered (prefs) so Business details keeps the list until she ticks it off.
 */
function alsoChange(published) {
  const keys = published.map((op) => keyOf(op.path));
  const what = [];
  if (keys.some((k) => k === 'site.phone' || k === 'site.phone2')) what.push('phone');
  if (keys.includes('site.email')) what.push('email');
  if (keys.some((k) => k.startsWith('site.address'))) what.push('address');
  if (!what.length) return null;
  const alsoWhat = joinList(what, ' and ');
  const alsoOld = published.filter((op) => ['site.phone', 'site.phone2', 'site.email'].includes(keyOf(op.path)) && typeof op.old === 'string' && op.old.trim())
    .map((op) => op.old).join(', ').slice(0, 190);
  const set = { alsoWhat, alsoOld, alsoAt: Math.floor(Date.now() / 1000) };
  Object.assign(S.prefs, set);
  api('prefs', { set }).then((r) => { if (r && r.prefs) S.prefs = r.prefs; }, () => {});
  return alsoWhat;
}

async function afterLive(r, p, doneMsg, published = [], kind = '') {
  rebase(r.live, r.draft);
  S.base = r.live.rev;
  S.conflicts = (r.draft && r.draft.conflicts) || [];
  const went = new Set(published.map((op) => keyOf(op.path)));
  for (const k of Object.keys(S.resolve)) if (went.has(k)) delete S.resolve[k];
  saveDraft();
  const v = await verify(r, (t) => p.say(t));
  p.close();
  if (v === 'ok') {
    let title = doneMsg || doneTitle(published);
    let text = 'Went live at ' + timeOf(r.entry ? r.entry.at : Date.now() / 1000) + '. Visitors see it now.';
    if (kind === 'notice' || (published.length && published.every((op) => keyOf(op.path).startsWith('notice')))) {
      const st = noticeStatus(S.live.content.notice);
      if (st.startsWith('Showing now')) { title = 'Done. The notice is showing now.'; text = `It shows at the top of every page${st.slice('Showing now'.length)}.`; }
      else if (st.startsWith('Starts')) { title = 'Done. The notice is set.'; text = `It ${st.charAt(0).toLowerCase() + st.slice(1)} (Indiana time). The website turns it on and off by itself.`; }
      else { title = 'Done. The notice is off.'; text = 'Visitors don\'t see it now.'; }
    }
    // one link: the page with her change on it
    const pages = Object.keys(r.pages || {}).filter((f) => (f.endsWith('.dc.html') && f !== 'ChatWidget.dc.html') || f === '404.html');
    const page = published.length ? pageFor(keyOf(published[0].path)) : pages[0] || 'Home.dc.html';
    const body = h('div.stack', h('p', { text }), h('a.btn.small', { href: '/' + page, target: '_blank', rel: 'noopener', text: 'See it on the website' }));
    const buttons = OK.slice();
    const what = alsoChange(published);
    if (what) {
      body.append(h('div.callout.info', h('p', { text: `Also change these yourself: Google Business Profile, the Facebook page, business cards and your email signature still show the old ${what}. The list stays on Business details until you tick it off.` })));
      buttons.push({ label: 'Check the contract PDFs', value: 'contracts', kind: 'ghost' });
    }
    if (await sheet(title, body, buttons) === 'contracts') {
      const scr = currentScreen();
      if (scr && (scr.hash || '').startsWith('#/tray') && !currentOps().length) replaceHash('#/contracts-check'); else go('#/contracts-check');
      return;
    }
  } else if (v.state === 'undone') {
    await refreshLive();
    // the website went back: her changes go back into "Not live yet"
    if (published.length) {
      const resolve = {};
      published.forEach((op) => { resolve[keyOf(op.path)] = 'mine'; });
      const [c] = applyOps(S.draft, published, resolve);
      S.draft = c;
      changed();
    }
    await sheet('Put back automatically', `After the change, the ${v.page} page didn't draw properly, so the website was put back the way it was. Visitors weren't shown a broken page. Your changes are still in "Not live yet".`, OK);
    render();
    return;
  } else {
    await sheet('Couldn\'t confirm', `It went live, but the ${v.page} page couldn't be checked from here. Have a look at it.`, OK);
  }
  // from an emptied "Not live yet", back to the start (Back then doesn't return to it)
  const scr = currentScreen();
  if (scr && (scr.hash || '').startsWith('#/tray') && !currentOps().length) replaceHash('#/');
  else render();
}

/** What a finished publish found when the phone had lost the answer, said plainly. */
const NOT_LIVE = {
  failed: 'Something in this change stopped the website from building, so nothing went live. Your changes are still in "Not live yet". Try again.',
  stopped: 'It didn\'t go live yet: something needs a look first. Open "Not live yet" to see what.',
};

/** Put these changes live (all of "Not live yet", or only the ticked ones). */
async function publishSome(ops, kind) {
  const p = progress('Putting it live…');
  await flushDraft();
  const id = 'p' + newId() + Date.now().toString(36);
  const offlineBefore = navigator.onLine === false;
  let r;
  try {
    r = await api('publish', { ops, base: S.base, resolve: S.resolve, id });
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) { p.close(); return; } // the "signed out" sheet says what to do
    if (e instanceof ApiError && e.status !== 0 && ((e.data && e.data.error) || e.status === 413)) { p.close(); await sheet('Not put live', e.message, OK); return; }
    if (e instanceof ApiError && e.status === 0 && offlineBefore && navigator.onLine === false) {
      // no signal at all: the request never left the phone
      p.close();
      const again = await sheet('No connection', 'Nothing went live yet. Your changes are saved on this phone. Tap "Try again" when you have signal.',
        [{ label: 'Try again', value: true, kind: 'primary' }, { label: 'OK', value: false, kind: 'ghost' }]);
      if (again) publishSome(ops, kind);
      return;
    }
    // the connection dropped: the website finishes the job by itself
    p.say('Still working… If your phone loses the connection, the website finishes the job.');
    let away = false;
    const later = setTimeout(() => p.button('Keep working', () => { away = true; p.close(); }), 15000);
    const st = await waitFor(id);
    clearTimeout(later);
    if (st === 'done') { seenLive(id); alsoChange(ops); try { await refreshLive(); } catch (x) { /* the next check catches up */ } }
    if (away) {
      // she carried on: a short note instead of a sheet in the middle of her typing
      if (st === 'done') toast('Your change went live.', 'ok', 6000);
      else if (st === 'nothing') toast('Nothing to put live: it\'s already that way.');
      else if (st) toast(NOT_LIVE[st] || NOT_LIVE.failed, 'bad', 9000);
      else toast('Still finishing. In a minute, look at the "Not live yet" bar: if it\'s gone, it worked.', 'warn', 9000);
      const scr = currentScreen();
      if (st === 'done' && scr && (scr.home || (scr.hash || '').startsWith('#/tray'))) render();
      return;
    }
    p.close();
    if (st === 'done') { await sheet(doneTitle(ops), 'Visitors see it now.', OK); render(); return; }
    if (st === 'nothing') { toast('Nothing to put live: it\'s already that way.'); render(); return; }
    if (st) { await sheet('Not put live', NOT_LIVE[st] || NOT_LIVE.failed, OK); render(); return; }
    await sheet('Still finishing', 'Your phone lost the connection, but the website finishes the job by itself. In a minute, look at the bottom of the start screen: if the "Not live yet" bar is gone, it worked. Your changes are kept either way.', OK);
    return;
  }
  if (!r.ok) {
    p.close();
    if (r.conflicts || r.problems) { S.conflicts = r.conflicts || S.conflicts; checkScreen(ops, publishSome); return; }
    await sheet('Not put live', r.error || 'Something went wrong. Nothing was changed.', OK);
    return;
  }
  if (r.nothing) { p.close(); toast('Nothing to put live: it\'s already that way.'); rebase(r.live, r.draft); render(); return; }
  seenLive(id);
  await afterLive(r, p, null, ops, kind);
}

/** Undo / bring back / restore from History. */
async function publishWith(action, data, doneMsg) {
  let p = progress('Working…');
  let id = '';
  const send = (extra = {}) => { id = 'h' + newId() + Date.now().toString(36); return api(action, { ...data, ...extra, id }); };
  const fail = async (msg) => { p.close(); await sheet('Not done', msg, OK); };
  let r;
  try { r = await send(); } catch (e) { await fail(e.message); return; }
  if (!r.ok && r.conflicts) {
    p.close();
    const later = r.conflicts.map((c) => `${c.label} (changed again by ${c.byName}${c.at ? ', ' + timeOf(c.at) : ''})`);
    const ok = await sheet('Changed again since', h('div.stack', h('p', { text: 'Some of these spots were changed again later:' }), h('ul', later.map((t) => h('li', { text: t }))), h('p', { text: 'Do it anyway? Those later changes to the same spots go too.' })), [{ label: 'Do it anyway', value: true, kind: 'danger' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
    if (!ok) return;
    const resolve = {};
    r.conflicts.forEach((c) => { resolve[c.key] = 'mine'; });
    p = progress('Working…');
    try { r = await send({ resolve }); } catch (e) { await fail(e.message); return; }
  }
  if (!r.ok) { await fail(r.error || (r.problems ? r.problems.map((x) => x.msg).join(' ') : 'It couldn\'t be done.')); return; }
  seenLive(id);
  p.say('Checking the pages…');
  await afterLive(r, p, doneMsg);
  go('#/history');
}

// ------------------------------------------------------------------ screens

route(/^#\/?$/, () => startScreen());
route(/^#\/s\/([a-z]+)$/, (id) => tileScreen(id));
route(/^#\/pages$/, () => pagesScreen());
route(/^#\/page\/(.+)$/, (n) => pageScreen(n));
route(/^#\/more$/, () => moreScreen());
route(/^#\/group\/(.+)$/, (n) => groupScreen(n));
route(/^#\/notice$/, () => noticeScreen(publishSome));
route(/^#\/list\/(.+)$/, (k) => { const p = parseKey(k); const f = p && field(schemaPath(p)); return f ? { title: f.label, body: h('div.stack', listEditor(p)) } : null; });
route(/^#\/item\/([^/]+)\/([a-z0-9]+)$/, (k, id) => itemScreen(k, id, parseKey));
route(/^#\/photos$/, () => photosScreen());
route(/^#\/photo\/(.+)$/, (k) => photoScreen(k));
route(/^#\/contract\/([a-z0-9]+)$/, (id) => contractScreen(id));
route(/^#\/contracts-check$/, () => contractsCheckScreen());
route(/^#\/tray$/, () => trayScreen(publishSome));
route(/^#\/history$/, () => historyScreen());
route(/^#\/history\/(\d+)$/, (rev) => historyEntryScreen(Number(rev), publishWith));
route(/^#\/preview\/([^/]+)(?:\/(.+))?$/, (f, k) => previewScreen(f, k));
route(/^#\/fix(?:\/(.+))?$/, (f) => fixScreen(f));
route(/^#\/search$/, () => searchScreen());
route(/^#\/help$/, () => helpScreen());
route(/^#\/help\/([a-z]+)$/, (id) => helpCard(id));
route(/^#\/people$/, () => peopleScreen());
route(/^#\/leads$/, () => leadsScreen());
route(/^#\/lead\/(\d{8}-\d{6}-[a-f0-9]{6})$/, (id) => leadScreen(id));
route(/^#\/words$/, () => wordsScreen());

// ------------------------------------------------------------------ start

async function boot() {
  let b;
  try {
    b = await (await fetch('/edit/api/boot', { credentials: 'same-origin' })).json();
  } catch (e) {
    clear(app).append(h('div.signin-card', h('h1', { text: 'Can\'t reach the website' }), h('p', { text: 'Check the connection and try again.' }), h('button.btn.primary', { type: 'button', text: 'Try again', onclick: () => location.reload() })));
    return;
  }
  if (b.signin || b.error) { location.href = '/edit/'; return; }
  S.boot = b;
  S.schema = b.schema;
  S.me = b.me;
  S.names = b.names;
  S.csrf = b.csrf;
  S.words = new Set(b.words || []);
  S.prefs = b.prefs || {};
  // stored lists (items with ids), and arrays whose entries are separate spots (headline lines)
  S.lists = {};
  for (const [sp, f] of Object.entries(S.schema.fields)) if (f.type === 'list' || f.type === 'checklist') S.lists[sp] = true;
  for (const sp of Object.keys(S.schema.fields)) { const m = /^(.+)\.\d+$/.exec(sp); if (m && !S.lists[m[1]]) S.lists[m[1]] = 'lines'; }
  loadDraft(b.live, b.draft);
  S.saveState = currentOps().length ? 'saved' : 'saved';
  shell();
  await render();
  // signed out mid-way: back to the screen she was on
  let ret = null;
  try { ret = sessionStorage.getItem('ts-return'); sessionStorage.removeItem('ts-return'); } catch (e) { /* private mode */ }
  if (ret && ret !== '#/' && ret.startsWith('#/')) replaceHash(ret);
  if (S.conflicts.length) toast('Someone else changed a spot you\'re also changing. Look in "Not live yet".', 'warn', 7000);
  setInterval(poll, 60000);
}

/** Keep up with changes the other person put live. */
async function poll() {
  if (document.visibilityState !== 'visible' || !S.live) return;
  try {
    const st = await api('state');
    if (st.rev !== S.live.rev) {
      await flushDraft();
      await refreshLive();
      const by = st.by && st.by !== S.me.id ? (S.names[st.by] || 'Someone') : null;
      if (by) toast(`${by} just put a change live.` + (S.conflicts.length ? ' One of them is a spot you\'re changing too: look in "Not live yet".' : ''), S.conflicts.length ? 'warn' : 'ok', 6000);
      const scr = currentScreen();
      if (scr && (scr.home || (scr.hash || '').startsWith('#/tray'))) render();
    }
  } catch (e) { /* offline: try next time */ }
}
on('wake', poll);

boot();
