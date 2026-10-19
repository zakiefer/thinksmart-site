// The editor app: loads everything, draws the header and the "Not live yet" bar, routes
// between screens, puts changes live (and keeps going if the connection drops), and keeps up
// with changes the other person put live.
import { S, h, icon, clear, api, on, emit, route, render, go, currentOps, loadDraft, rebase, flushDraft, saveDraft, toast, sheet, keyOf, parseKey, getAt, schemaPath, field, newId, timeOf, ApiError, currentScreen, applyOps, changed } from './core.js';
import { startScreen, tileScreen, pagesScreen, pageScreen, moreScreen, groupScreen, noticeScreen, reveal } from './screens.js';
import { listEditor, itemScreen } from './lists.js';
import { photosScreen, photoScreen, contractScreen, contractsCheckScreen } from './media.js';
import { trayScreen, historyScreen, historyEntryScreen, verify, checkScreen } from './live.js';
import { previewScreen, fixScreen, searchScreen } from './preview.js';
import { peopleScreen, wordsScreen, helpScreen, helpCard } from './extras.js';

const app = document.getElementById('app');
const bar = h('header.bar');
const main = h('main.screen', { id: 'main', tabIndex: -1 });
const tray = h('div.traybar', { hidden: true });
const saveNote = h('span.save-state', { 'aria-live': 'polite' });

function shell() {
  clear(app).append(bar, main, tray);
}

function drawBar(screen) {
  clear(bar);
  if (!screen.home) {
    bar.append(h('button.back', { type: 'button', 'aria-label': 'Back', onclick: () => { if (S.navCount > 0) history.back(); else go(screen.back || '#/'); } }, icon('back'), h('span', { text: 'Back' })));
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
  const n = currentOps().length;
  const scr = currentScreen();
  tray.hidden = n === 0 || (scr && (scr.hash || '').startsWith('#/tray'));
  if (tray.hidden) return;
  clear(tray).append(
    h('a.tray-link', { href: '#/tray' }, h('span.tray-count', { text: String(n) }), h('span', { text: n === 1 ? 'change not live yet' : 'changes not live yet' })),
    h('a.btn.primary', { href: '#/tray', text: 'Review & put live' }));
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
  sheet('You\'re signed out', 'Your changes are kept. Sign in again to carry on.', [{ label: 'Sign in', value: true, kind: 'primary' }]).then(() => { location.href = '/edit/'; });
});
on('stale', () => { location.reload(); });

// ------------------------------------------------------------------ putting changes live

function progress(text) {
  let back = document.querySelector('.busy');
  if (!back) { back = h('div.busy', h('div.busy-box', h('span.spinner'), h('p'))); document.body.append(back); }
  back.querySelector('p').textContent = text;
  return { close: () => back.remove(), say: (t) => { back.querySelector('p').textContent = t; } };
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

async function afterLive(r, p, doneMsg, published = []) {
  rebase(r.live, r.draft);
  S.base = r.live.rev;
  S.conflicts = (r.draft && r.draft.conflicts) || [];
  const went = new Set(published.map((op) => keyOf(op.path)));
  for (const k of Object.keys(S.resolve)) if (went.has(k)) delete S.resolve[k];
  saveDraft();
  const v = await verify(r, (t) => p.say(t));
  p.close();
  const pageLinks = Object.keys(r.pages || {}).filter((f) => f.endsWith('.dc.html') && f !== 'ChatWidget.dc.html');
  if (v === 'ok') {
    await sheet(doneMsg || 'Done. Your change is live.', h('div.stack', h('p', { text: 'Went live at ' + timeOf(r.entry ? r.entry.at : Date.now() / 1000) + '. Visitors see it now.' }),
      pageLinks.length ? h('div.row.wrap', pageLinks.slice(0, 4).map((f) => h('a.btn.small', { href: '/' + f, target: '_blank', rel: 'noopener', text: 'View ' + f.replace('.dc.html', '').replace('Claim', 'File a Claim') }))) : null), [{ label: 'OK', value: true, kind: 'primary' }]);
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
    await sheet('Put back automatically', `After the change, the ${v.page} page didn't draw properly, so the website was put back the way it was. Visitors weren't shown a broken page. Your changes are still in "Not live yet".`, [{ label: 'OK', value: true, kind: 'primary' }]);
  } else {
    await sheet('Couldn\'t confirm', `It went live, but the ${v.page} page couldn't be checked from here. Have a look at it.`, [{ label: 'OK', value: true, kind: 'primary' }]);
  }
  render();
}

/** Put these changes live (all of "Not live yet", or only the ticked ones). */
async function publishSome(ops) {
  const p = progress('Putting it live…');
  await flushDraft();
  const id = 'p' + newId() + Date.now().toString(36);
  let r;
  try {
    r = await api('publish', { ops, base: S.base, resolve: S.resolve, id });
  } catch (e) {
    if (e instanceof ApiError && e.status !== 0 && e.data && e.data.error) { p.close(); await sheet('Not put live', e.message, [{ label: 'OK', value: true, kind: 'primary' }]); return; }
    // the connection dropped: the website finishes the job by itself
    p.say('Still working… If your phone loses the connection, the website finishes the job.');
    const st = await waitFor(id);
    p.close();
    if (st === 'done') { await refreshLive(); await sheet('Done', 'Your change went live.', [{ label: 'OK', value: true, kind: 'primary' }]); render(); return; }
    await sheet('Not sure it went live', 'The connection dropped. Open "Undo a change" in a minute to see whether it\'s there; your changes are kept either way.', [{ label: 'OK', value: true, kind: 'primary' }]);
    return;
  }
  if (!r.ok) {
    p.close();
    if (r.conflicts || r.problems) { S.conflicts = r.conflicts || S.conflicts; checkScreen(ops, publishSome); return; }
    await sheet('Not put live', r.error || 'Something went wrong. Nothing was changed.', [{ label: 'OK', value: true, kind: 'primary' }]);
    return;
  }
  if (r.nothing) { p.close(); toast('Nothing to put live: it\'s already that way.'); rebase(r.live, r.draft); render(); return; }
  await afterLive(r, p, null, ops);
}

/** Undo / bring back / restore from History. */
async function publishWith(action, data, doneMsg) {
  let p = progress('Working…');
  const send = (extra = {}) => api(action, { ...data, ...extra, id: 'h' + newId() + Date.now().toString(36) });
  const fail = async (msg) => { p.close(); await sheet('Not done', msg, [{ label: 'OK', value: true, kind: 'primary' }]); };
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
route(/^#\/preview\/(.+)$/, (f) => previewScreen(f));
route(/^#\/fix(?:\/(.+))?$/, (f) => fixScreen(f || 'Home.dc.html'));
route(/^#\/search$/, () => searchScreen());
route(/^#\/help$/, () => helpScreen());
route(/^#\/help\/([a-z]+)$/, (id) => helpCard(id));
route(/^#\/people$/, () => peopleScreen());
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
  if (b.status && b.status.state === 'done' && b.status.wentLive && Date.now() / 1000 - b.status.wentLive < 600) toast('Your change went live at ' + timeOf(b.status.wentLive) + '.', 'ok', 6000);
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
