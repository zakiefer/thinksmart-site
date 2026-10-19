// The start screen, the tiles and every screen in the feature map that is made of boxes:
// Business details, States & contracts, Reviews, FAQ, Promises/prices/seal, Easier to read,
// Every page, More, and the storm / closed notice.
import { S, h, icon, clear, getAt, setAt, keyOf, parseKey, schemaPath, field, isList, order, splitLabel, changed, plain, go, api, toast, sheet, currentOps, whenOf, centralToEpoch, timeOf, on, emit, visibleStates } from './core.js';
import { fieldBox } from './fields.js';
import { listEditor, SIMPLE_LISTS, cut } from './lists.js';
import { healthCard, leadAddresses, repairEditor } from './leads.js';

const P = (sp) => sp.split('.');
const screenDef = (id) => S.schema.screens.find((s) => s.id === id);
const HIDE_TYPES = new Set(['focus', 'keep', 'pdfv']);

/** Fields on a page or in a group: by prefix, not inside lists, in site order. */
export function fieldsFor(prefixes) {
  return Object.keys(S.schema.fields).filter((sp) => {
    const f = field(sp);
    if (sp.includes('[]') || HIDE_TYPES.has(f.type) || f.editorOnly) return false;
    if (/\.video\.(poster|photo)$/.test(sp)) return false; // shown with their video
    return prefixes.some((p) => (p.endsWith('.') ? sp.startsWith(p) : sp === p || sp.startsWith(p + '.')));
  }).filter((sp) => !Object.keys(S.schema.fields).some((other) => other !== sp && isList(other) && !other.includes('[]') && sp.startsWith(other + '.')))
    .sort((a, b) => order(a) - order(b));
}

/**
 * Boxes and lists, grouped into sections by the first part of their labels ("Top of Home: …").
 * A list without a section name joins the section before it; "Top of Home small line" joins
 * "Top of Home".
 */
export function sectioned(sps) {
  const secs = new Map();
  let current = null;
  for (const sp of sps) {
    let [s] = splitLabel(field(sp).label);
    if (!s) s = current || 'Other';
    else s = [...secs.keys()].find((k) => s === k || s.startsWith(k + ' ')) || s;
    current = s;
    if (!secs.has(s)) secs.set(s, []);
    secs.get(s).push(sp);
  }
  const out = h('div.sections');
  for (const [name, list] of secs) {
    // a peek at the section's first words, so she can tell the sections apart while closed
    const first = list.find((sp) => !isList(sp) && ['text', 'para', 'rich'].includes(field(sp).type) && plain(getAt(S.draft, P(sp))).trim() !== '');
    const peek = first ? cut(plain(getAt(S.draft, P(first))), 40) : '';
    const sec = h('details.section', h('summary', h('span.section-text', h('span.section-name', { text: name }), peek ? h('span.section-peek', { text: peek }) : null)));
    sec.dataset.sec = name;
    for (const sp of list) {
      const node = isList(sp) ? listEditor(P(sp), { short: true }) : fieldBox(P(sp), { short: true });
      if (node) sec.append(node);
    }
    if (sec.querySelector('.field-box.is-changed, .badges .pill.new, .badges .pill.changed')) sec.querySelector('summary').append(h('span.pill.changed', { text: 'Changed' }));
    out.append(sec);
  }
  if (secs.size <= 2 && out.firstChild) out.firstChild.open = true;
  return out;
}

// ------------------------------------------------------------------ start screen

export function startScreen() {
  const ops = currentOps();
  const live = S.live.content;
  const stateN = visibleStates().length;
  // what visitors see now; "not live yet" when her draft has changes there
  const reviews = (((live.home || {}).reviews || {}).items || []).filter((r) => !r.hidden).length;
  const faqN = (((live.faq || {}).items) || []).filter((x) => !x.hidden).length;
  const pending = (pre) => ops.some((op) => keyOf(op.path).startsWith(pre)) ? ' · changes not live yet' : '';
  const tiles = [
    ['business', 'Business details', 'Phones, email, address, adjuster, social links', 'phone'],
    ['states', 'States & contracts', `${stateN} state${stateN === 1 ? '' : 's'} · e-sign links · contract PDFs`, 'map'],
    ['reviews', 'Reviews', (reviews ? `${reviews} review${reviews === 1 ? '' : 's'} on Home` : 'None on Home yet') + pending('home.reviews.items'), 'star'],
    ['faq', 'FAQ', `${faqN} questions · 3 on Home` + pending('faq.items'), 'chat'],
    ['photos', 'Photos', 'Every photo and video on the website', 'photo'],
    ['promises', 'Promises, prices & the Paid-or-Free seal', 'Callback promise, $' + (S.draft.site.price || 0) + ', guarantee, numbers, seal', 'tag'],
    ['brightness', 'Easier to read', 'Paragraph text: ' + ({ standard: 'Standard', brighter: 'Brighter', brightest: 'Brightest' }[S.draft.site.brightness] || 'Standard'), 'sun'],
    ['pages', 'Every page', 'All the words, page by page', 'pages'],
    ['more', 'More', 'Form and chat wording, Google and sharing, people', 'more'],
  ];
  // the notice tile says what visitors see, not what the draft says
  const ns = noticeStatus(live.notice);
  const noticeSub = ns.startsWith('Showing now') ? 'On now' + ns.slice('Showing now'.length) : ns.startsWith('Ended') ? 'Off' : ns;
  const noticeNote = ops.some((op) => keyOf(op.path).startsWith('notice')) ? h('span.big-note', { text: 'Change not live yet' }) : null;
  const big = [
    ['#/fix', 'Fix a word I saw', 'Tap it in the preview', 'word', '', null],
    ['#/help', 'How do I…?', 'Picture guides', 'help', '', null],
    ['#/history', 'Undo a change', 'Changes already live', 'undo', '', null],
    ['#/notice', 'Storm / closed notice', noticeSub, 'storm', ns.startsWith('Showing') ? 'on' : '', noticeNote],
  ];
  const st = S.boot.status;
  let seen = null;
  let published = false;
  try { seen = localStorage.getItem('ts-seen-live'); published = localStorage.getItem('ts-has-published') === '1'; } catch (e) { /* private mode */ }
  const wentLive = st && st.state === 'done' && st.wentLive && (Date.now() / 1000 - st.wentLive < 3600) && st.id !== seen;
  return {
    title: 'Your website',
    home: true,
    body: h('div.stack',
      !ops.length && !published ? h('p.callout.info', { text: 'Change anything you like. Nothing reaches the website until you tap "Put it live", and every change can be undone.' }) : null,
      healthCard(),
      wentLive ? h('p.callout.ok', { text: 'Your change went live at ' + timeOf(st.wentLive) + '.' }) : null,
      h('div.bigs', big.map(([href, title, sub, ic, cls, note]) => h('a.big' + (cls ? '.' + cls : ''), { href }, icon(ic), h('span.big-title', { text: title }), h('span.big-sub', { text: sub }), note))),
      h('button.searchbar', { type: 'button', onclick: () => go('#/search') }, icon('search'), h('span', { text: 'Find words on the website…' })),
      h('nav.tiles', tiles.map(([id, title, sub, ic]) => h('a.tile', { href: id === 'pages' ? '#/pages' : id === 'more' ? '#/more' : id === 'photos' ? '#/photos' : '#/s/' + id },
        icon(ic), h('span.tile-text', h('span.tile-title', { text: title }), h('span.small', { text: sub })), icon('chev'))))),
  };
}

// ------------------------------------------------------------------ the tiles

export function tileScreen(id) {
  const def = screenDef(id);
  if (!def) return null;
  if (id === 'states') return statesScreen();
  if (id === 'notice') { go('#/notice'); return null; }
  const body = h('div.stack');
  if (id === 'reviews') body.append(h('p.help', { text: 'Only the words and the name are needed. Paste the review exactly as the customer wrote it. The reviews section shows on Home once there\'s one.' }));
  for (const sp of def.fields || []) {
    if (id === 'brightness' && sp === 'site.brightness') continue; // the sample cards are the choice
    const node = isList(sp) ? listEditor(P(sp)) : fieldBox(P(sp));
    if (node) body.append(node);
  }
  if (id === 'business') {
    body.insertBefore(leadAddresses(), body.querySelector('[data-key="site.social"]'));
    const also = alsoChangeCard(body);
    if (!also.parentNode) body.append(also);
  }
  if (id === 'promises') { body.append(numbersWarning()); body.append(h('p.help', { text: 'The seal\'s artwork says "PAID OR FREE", so it can only be turned on or off.' })); }
  if (id === 'brightness') body.append(brightnessSamples());
  return { title: def.title, body };
}

const ALSO_ITEMS = ['Google Business Profile (Google Maps listing)', 'The Facebook page\'s About section', 'The e-sign forms in Adobe (ask John)', 'Business cards and flyers', 'Your email signature'];

/**
 * After a phone, email or address change: what she has to change elsewhere herself. Sits
 * under the box she changed, and stays after the change went live until she says she's done.
 */
function alsoChangeCard(body) {
  const wrap = h('div');
  let sig = null;
  const draw = () => {
    const was = S.live.content.site;
    const now = S.draft.site;
    const ch = [];
    const keys = [];
    if ((was.phone || '') !== (now.phone || '')) keys.push('site.phone');
    if ((was.phone2 || '') !== (now.phone2 || '')) keys.push('site.phone2');
    if (keys.length) ch.push('phone');
    if ((was.email || '') !== (now.email || '')) { ch.push('email'); keys.push('site.email'); }
    if (JSON.stringify(was.address) !== JSON.stringify(now.address)) { ch.push('address'); keys.push('site.address'); }
    const after = !ch.length && S.prefs.alsoAt > 0;
    const what = String(S.prefs.alsoWhat || '');
    const at = after ? (/phone/.test(what) || !/email|address/.test(what) ? 'site.phone' : /email/.test(what) ? 'site.email' : 'site.address') : keys[0];
    // redrawn only when it says something new, so her ticks stay while she types
    const now2 = ch.join() + '|' + after + '|' + at;
    if (sig === now2) return;
    sig = now2;
    clear(wrap);
    if (!ch.length && !after) return;
    const text = after
      ? `The website updates everywhere by itself. These other places still show the old ${what || 'details'}${S.prefs.alsoOld ? ` (${S.prefs.alsoOld})` : ''}:`
      : `The website updates everywhere by itself. These other places still show the old ${ch.join(' and ')}:`;
    wrap.append(h('div.callout.info', h('h3', { text: 'Also change these yourself' }),
      h('p.small', { text }),
      h('ul.checklist', ALSO_ITEMS.map((t) => h('li', h('label.check', h('input', { type: 'checkbox' }), ' ' + t)))),
      h('div.row',
        h('button.btn.small', { type: 'button', text: 'Check the contract PDFs for the old details', onclick: () => go('#/contracts-check') }),
        after ? h('button.btn.small.primary', { type: 'button', text: 'I\'ve done these', onclick: () => { S.prefs.alsoAt = 0; api('prefs', { set: { alsoAt: 0 } }).catch(() => {}); draw(); } }) : null)));
    // straight under the first box that changed (after it went live: the box it was about)
    const anchor = body.querySelector(`[data-key="${at}"]`);
    if (anchor && anchor.nextSibling !== wrap) anchor.after(wrap);
  };
  draw();
  on('change', () => { if (wrap.isConnected) draw(); });
  return wrap;
}

function numbersWarning() {
  const wrap = h('div');
  const draw = () => {
    clear(wrap);
    for (const k of ['underpaid', 'higher', 'overturned']) {
      const n = S.draft.numbers[k];
      if (!n || !n.hidden) continue;
      const txt = (n.plus ? '+' : '') + n.value + (n.percent ? '%' : '');
      const q = (S.draft.faq.items || []).find((it) => (it.a || []).some((a) => plain(a.text).includes(txt)));
      if (q) wrap.append(h('p.callout.warn', { text: `The number ${txt} is hidden, but this answer still quotes it: “${plain(q.q)}”.` }));
    }
  };
  draw();
  on('change', () => { if (wrap.isConnected) draw(); });
  return wrap;
}

const BRIGHT = { standard: 'Standard', brighter: 'Brighter', brightest: 'Brightest' };

function brightnessSamples() {
  // a typical paragraph (65% white on the dark background) under each preset (Brightness::alpha)
  const presets = { standard: 0.65, brighter: 0.77, brightest: 0.86 };
  const wrap = h('div.stack');
  const draw = () => {
    clear(wrap);
    const cur = S.draft.site.brightness || 'standard';
    const was = S.live.content.site.brightness || 'standard';
    wrap.append(h('h3', { text: 'Tap the one that\'s easiest to read' }));
    if (cur !== was) wrap.append(h('div', h('span.pill.changed', { text: 'Not live yet' })));
    wrap.append(h('div.bright-samples', { role: 'radiogroup', 'aria-label': 'Paragraph text brightness' }, Object.entries(presets).map(([k, a]) => h('button.bright-sample' + (k === cur ? '.on' : ''),
      { type: 'button', role: 'radio', 'aria-checked': String(k === cur), onclick: () => { setAt(S.draft, ['site', 'brightness'], k); changed(); draw(); wrap.querySelector('[aria-checked="true"]').focus(); } },
      h('span.small', { text: BRIGHT[k] }),
      h('p', { style: { color: `rgba(235,229,216,${a})` }, text: 'Paragraphs, descriptions and fine print look like this. Headings, orange words, buttons and menus stay the same.' })))));
    wrap.append(h('p.help', { text: 'One setting for the whole website. Open the preview to see it on a real page.' }));
  };
  draw();
  return wrap;
}

// ------------------------------------------------------------------ states & contracts

function statesScreen() {
  const body = h('div.stack');
  body.append(h('p.help', { text: 'Pick a state and its outline comes automatically. Without an e-sign link, the state shows a "Call to sign" card.' }));
  body.append(listEditor(['states']));
  body.append(fieldBox(['claim', 'byPhone', 'otherStates']));
  const also = h('div');
  body.append(also);
  let t = null;
  const check = async () => {
    const ops = currentOps().filter((op) => keyOf(op.path).startsWith('states') || keyOf(op.path).startsWith('claim.byPhone') || keyOf(op.path).startsWith('site.adjuster'));
    clear(also);
    if (!ops.length) return;
    try {
      const r = await api('check', { ops, base: S.base, resolve: S.resolve });
      clear(also);
      if (r.also && r.also.length) {
        also.append(h('div.callout.info', h('h3', { text: 'This will also change…' }),
          h('ul.beforeafter', r.also.map((x) => h('li', h('strong', { text: x.what }), h('span.was', { text: x.before || '(empty)' }), h('span.arrow', { text: '→' }), h('span.now', { text: x.after || '(empty)' }))))));
      }
    } catch (e) { /* offline: shown again later */ }
  };
  check();
  on('change', () => { if (!also.isConnected) return; clearTimeout(t); t = setTimeout(check, 700); });
  return { title: 'States & contracts', body };
}

// ------------------------------------------------------------------ every page, more

export function pagesScreen() {
  const def = screenDef('pages');
  return {
    title: 'Every page',
    body: h('nav.tiles', Object.keys(def.pages).map((name) => h('a.tile', { href: '#/page/' + encodeURIComponent(name) }, icon('pages'), h('span.tile-text', h('span.tile-title', { text: name })), icon('chev')))),
  };
}

export function pageScreen(name) {
  const def = screenDef('pages');
  const prefixes = def.pages[name];
  if (!prefixes) return null;
  const file = { Home: 'Home.dc.html', About: 'About.dc.html', Services: 'Services.dc.html', FAQ: 'FAQ.dc.html', Contact: 'Contact.dc.html', 'File a Claim': 'Claim.dc.html', 'Bottom of every page': 'Home.dc.html', 'Page not found': '404.html' }[name];
  return {
    title: name,
    action: file ? { label: 'Preview', href: '#/preview/' + file } : null,
    body: h('div.stack', name === 'Bottom of every page' ? h('p.help', { text: 'The bottom part and the menu are the same on every page. The logo, page names and column headings stay as they are.' }) : null, sectioned(fieldsFor(prefixes))),
  };
}

export function moreScreen() {
  const def = screenDef('more');
  return {
    title: 'More',
    body: h('div.stack',
      h('nav.tiles', Object.keys(def.groups).map((g) => h('a.tile', { href: '#/group/' + encodeURIComponent(g) }, icon(g.startsWith('Google') ? 'globe' : 'chat'), h('span.tile-text', h('span.tile-title', { text: g })), icon('chev')))),
      h('nav.tiles',
        h('a.tile', { href: '#/leads' }, icon('chat'), h('span.tile-text', h('span.tile-title', { text: 'Leads' }), h('span.small', { text: 'Every claim, message and chat, kept 90 days' })), icon('chev')),
        h('a.tile', { href: '#/people' }, icon('people'), h('span.tile-text', h('span.tile-title', { text: 'People' }), h('span.small', { text: 'Who can edit, passwords, rescue codes' })), icon('chev')),
        h('a.tile', { href: '#/words' }, icon('word'), h('span.tile-text', h('span.tile-title', { text: 'Words I taught' }), h('span.small', { text: 'Words the spelling check now knows' })), icon('chev')),
        h('a.tile', { href: '#/search' }, icon('search'), h('span.tile-text', h('span.tile-title', { text: 'Find words' })), icon('chev')),
        h('a.tile', { href: '/edit/api/download', download: '' }, icon('pages'), h('span.tile-text', h('span.tile-title', { text: 'Download a copy' }), h('span.small', { text: 'Words, photos and contracts, to keep safe' })), icon('chev'))),
      h('button.btn.ghost', { type: 'button', text: 'Something looks wrong? Fix it', onclick: repairEditor }),
      h('form.signout', { method: 'post', action: '/edit/' }, h('input', { type: 'hidden', name: 'do', value: 'signout' }), h('button.btn.ghost', { type: 'submit', text: 'Sign out of this phone' }))),
  };
}

export function groupScreen(name) {
  const def = screenDef('more');
  const prefixes = def.groups[name];
  if (!prefixes) return null;
  const sps = fieldsFor(prefixes);
  const body = h('div.stack');
  if (name === 'Google and sharing') body.append(h('p.help', { text: 'What Google shows for each page. "Name on Google" works best under 60 letters, the description under 160.' }));
  body.append(sectioned(sps));
  return { title: name, body };
}

// ------------------------------------------------------------------ the storm / closed notice

export function noticeStatus(n) {
  if (!n || !n.on) return 'Off';
  const now = Date.now() / 1000;
  const from = centralToEpoch(n.start || '') || 0;
  const until = centralToEpoch(n.end || '') || Infinity;
  const day = (t) => new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', weekday: 'short' }).format(new Date(t * 1000));
  const f = (t) => (Math.abs(t - now) < 6 * 86400 ? day(t) + ' ' : new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', month: 'short', day: 'numeric' }).format(new Date(t * 1000)) + ', ') + timeOf(t);
  if (now < from) return until < Infinity ? `Starts ${f(from)}, ends ${f(until)}` : 'Starts ' + f(from);
  if (now >= until) return 'Ended ' + f(until);
  return 'Showing now' + (until < Infinity ? ', until ' + f(until) : '');
}

/** Today in Indiana (Central) time: year, month, day, weekday (Sun = 0) and minutes past midnight. */
function centralToday() {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
  const g = (k) => (parts.find((x) => x.type === k) || {}).value;
  return { y: +g('year'), m: +g('month'), d: +g('day'), wd: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(g('weekday')), mins: (+g('hour') % 24) * 60 + +g('minute') };
}
/** "2026-10-26T17:00": `k` days after today, at `hm` (Central wall time, as the date boxes store it). */
function centralDay(c, k, hm) {
  const t = new Date(Date.UTC(c.y, c.m - 1, c.d + k));
  const p2 = (x) => String(x).padStart(2, '0');
  return `${t.getUTCFullYear()}-${p2(t.getUTCMonth() + 1)}-${p2(t.getUTCDate())}T${hm}`;
}
/** The quick choices under "When": [id, label, start, end]. */
function whenPresets() {
  const c = centralToday();
  // this Friday 5 pm, or straight away once the weekend has started
  const fri = (c.wd >= 1 && c.wd <= 4) || (c.wd === 5 && c.mins < 17 * 60) ? centralDay(c, 5 - c.wd, '17:00') : '';
  return [
    ['always', 'Until I turn it off', '', ''],
    ['today', 'Today only', '', centralDay(c, 0, '23:45')],
    ['weekend', 'This weekend', fri, centralDay(c, (7 - c.wd) % 7, '23:45')],
  ];
}

/** What stops the notice from going live as she set it up, in plain words ('' when nothing). */
function noticeProblem(n) {
  if (plain((n[n.kind] || {}).text).trim() === '') return 'Type the notice\'s words first.';
  const from = centralToEpoch(n.start || '');
  const until = centralToEpoch(n.end || '');
  if (from && until && until <= from) return 'The end is before the start, so the notice would never show.';
  if (until && until <= Date.now() / 1000) return 'This end time has passed, so visitors won\'t see the notice. Clear "Ends" or pick a later time.';
  return '';
}

export function noticeScreen(publishSome) {
  const body = h('div.stack');
  let pickDates = false;
  let chosen = null; // on a Sunday "Today only" and "This weekend" are the same times: keep the one she tapped
  const noticeOps = () => currentOps().filter((op) => keyOf(op.path).startsWith('notice'));
  const set = (k, v) => setAt(S.draft, ['notice', k], v);
  const draw = (focusSel) => {
    clear(body);
    const n = S.draft.notice;
    const status = h('p.status-card', h('span.dot'), h('strong', { style: { flex: '1 1 200px' } })); // long lines wrap beside the dot
    body.append(status);
    body.append(fieldBox(['notice', 'on'], { label: 'Show a notice at the top of every page' }));
    const kinds = { storm: 'Storm response', closed: 'Office closed', away: 'John is out until…' };
    const pick = h('div.notice-kinds', { role: 'radiogroup' });
    for (const [k, lab] of Object.entries(kinds)) {
      // picking a notice also switches it on: that's what she means by picking one
      pick.append(h('button.notice-kind', { type: 'button', role: 'radio', 'aria-checked': String(n.kind === k), dataset: { kind: k }, onclick: () => { set('kind', k); set('on', true); changed(); draw(`.notice-kind[data-kind="${k}"]`); } },
        h('strong', { text: lab }), h('span.small', { text: plain(n[k].text) })));
    }
    body.append(h('h3', { text: 'Which notice' }), pick);
    body.append(fieldBox(['notice', n.kind, 'text'], { label: 'Words on the notice' }));
    body.append(fieldBox(['notice', n.kind, 'link'], { label: 'Button on the notice' }));
    const presets = whenPresets();
    const fits = ([, , a, b]) => (n.start || '') === a && (n.end || '') === b;
    const match = presets.find((x) => x[0] === chosen && fits(x)) || presets.find(fits);
    const dates = pickDates || !match || /end time has passed/.test(n.on ? noticeProblem(n) : '');
    const when = h('div.seg.notice-when', { role: 'radiogroup', 'aria-label': 'When' },
      presets.map(([id, lab, a, b]) => h('button', { type: 'button', role: 'radio', 'aria-checked': String(!dates && match[0] === id), dataset: { when: id }, text: lab, onclick: () => { pickDates = false; chosen = id; set('start', a); set('end', b); changed(); draw(`[data-when="${id}"]`); } })),
      h('button', { type: 'button', role: 'radio', 'aria-checked': String(dates), dataset: { when: 'pick' }, text: 'Pick dates…', onclick: () => { pickDates = true; draw('[data-when="pick"]'); } }));
    body.append(h('h3', { text: 'When (Indiana time)' }), when);
    if (dates) body.append(h('p.help', { text: 'The website switches it on and off by itself at these times.' }), fieldBox(['notice', 'start'], { label: 'Starts' }), fieldBox(['notice', 'end'], { label: 'Ends' }));
    const act = h('div');
    body.append(act);
    body.update = () => {
      const live = S.live.content.notice || {};
      const d = S.draft.notice;
      const ops = noticeOps();
      const ls = noticeStatus(live);
      // the card tells what visitors see; a draft is never "showing now"
      let text = ls;
      if (ops.length && !live.on && d.on) text = 'Not on the website yet. Tap "Put the notice live now" to show it.';
      else if (ops.length && live.on && !d.on) text = ls.startsWith('Showing') ? 'Still showing on the website until you tap "Take the notice down now".' : `${ls} · your changes aren't live yet`;
      else if (ops.length) text = `${ls} · your changes aren't live yet`;
      status.querySelector('.dot').classList.toggle('on', ls.startsWith('Showing'));
      status.querySelector('strong').textContent = text;
      clear(act);
      if (!ops.length) { act.append(h('p.small', { text: 'Nothing to put live yet.' })); return; }
      const problem = d.on ? noticeProblem(d) : '';
      if (problem) { act.append(h('p.callout.bad', { role: 'alert', text: problem })); return; }
      act.append(h('div.row.end.sticky-actions', h('button.btn.primary.big', { type: 'button', text: !d.on && live.on ? 'Take the notice down now' : 'Put the notice live now', onclick: () => publishSome(noticeOps(), 'notice') })));
    };
    body.update();
    if (focusSel) { const el = body.querySelector(focusSel); if (el) el.focus(); }
  };
  draw();
  on('choice', (p) => { if (body.isConnected && keyOf(p).startsWith('notice') && !/\.link$/.test(keyOf(p))) draw(); });
  on('change', () => { if (body.isConnected && body.update) body.update(); });
  return { title: 'Storm / closed notice', body };
}

// ------------------------------------------------------------------ jumping to a box

/** Where a spot is edited: the screen to open for a change path. */
export function screenFor(path) {
  const sp = schemaPath(path);
  // inside a list item that has its own screen
  for (let i = 0; i < path.length; i++) {
    if (typeof path[i] !== 'object') continue;
    const listPath = path.slice(0, i);
    const lsp = schemaPath(listPath);
    if (!SIMPLE_LISTS.has(lsp) && field(lsp) && field(lsp).type !== 'checklist') return `#/item/${encodeURIComponent(keyOf(listPath))}/${path[i].id}`;
    break;
  }
  const top = sp.replace(/\[\].*$/, '');
  for (const s of S.schema.screens) {
    if ((s.fields || []).some((f) => top === f || top.startsWith(f + '.') || f.startsWith(top + '.'))) return s.id === 'notice' ? '#/notice' : '#/s/' + s.id;
  }
  const pages = screenDef('pages').pages;
  for (const [name, pre] of Object.entries(pages)) if (pre.some((p) => (p.endsWith('.') ? top.startsWith(p) : top === p || top.startsWith(p + '.')))) return '#/page/' + encodeURIComponent(name);
  const groups = screenDef('more').groups;
  for (const [name, pre] of Object.entries(groups)) if (pre.some((p) => (p.endsWith('.') ? top.startsWith(p) : top === p || top.startsWith(p + '.')))) return '#/group/' + encodeURIComponent(name);
  if (sp.startsWith('notice')) return '#/notice';
  return '#/';
}

/** After a screen opened: show the box for `key`, open its section, flash it and focus it. */
export function reveal(key) {
  let el = null;
  let k = key;
  while (k && !(el = document.querySelector(`[data-key="${CSS.escape(k)}"]`))) {
    const cut = Math.max(k.lastIndexOf('.'), k.lastIndexOf('['));
    k = cut > 0 ? k.slice(0, cut) : '';
  }
  if (!el) return false;
  const det = el.closest('details');
  if (det) det.open = true;
  el.scrollIntoView({ block: 'center' });
  el.classList.add('flash');
  setTimeout(() => el.classList.remove('flash'), 2400);
  if (el.focusControl) setTimeout(() => el.focusControl(), 300);
  return true;
}
