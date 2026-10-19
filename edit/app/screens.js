// The start screen, the tiles and every screen in the feature map that is made of boxes:
// Business details, States & contracts, Reviews, FAQ, Promises/prices/seal, Easier to read,
// Every page, More, and the storm / closed notice.
import { S, h, icon, clear, getAt, setAt, keyOf, parseKey, schemaPath, field, isList, order, splitLabel, changed, plain, go, api, toast, sheet, currentOps, whenOf, centralToEpoch, timeOf, on, emit, visibleStates } from './core.js';
import { fieldBox } from './fields.js';
import { listEditor, SIMPLE_LISTS } from './lists.js';
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
    const sec = h('details.section', h('summary', h('span', { text: name }), h('span.count', { text: String(list.length) })));
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
  const n = S.draft.notice || {};
  const ops = currentOps();
  const stateN = visibleStates().length;
  const reviews = ((S.draft.home.reviews || {}).items || []).filter((r) => !r.hidden).length;
  const faq = (S.draft.faq.items || []).filter((x) => !x.hidden);
  const tiles = [
    ['business', 'Business details', 'Phones, email, address, adjuster, social links', 'phone'],
    ['states', 'States & contracts', `${stateN} state${stateN === 1 ? '' : 's'} · e-sign links · contract PDFs`, 'map'],
    ['reviews', 'Reviews', reviews ? `${reviews} review${reviews === 1 ? '' : 's'} on Home` : 'None yet (the section shows after the first)', 'star'],
    ['faq', 'FAQ', `${faq.length} questions · 3 on Home`, 'chat'],
    ['photos', 'Photos', 'Every photo and video on the website', 'photo'],
    ['promises', 'Promises, prices & the Paid-or-Free seal', 'Callback promise, $' + (S.draft.site.price || 0) + ', guarantee, numbers, seal', 'tag'],
    ['brightness', 'Easier to read', 'Body text: ' + ({ standard: 'Standard', brighter: 'Brighter', brightest: 'Brightest' }[S.draft.site.brightness] || 'Standard'), 'sun'],
    ['pages', 'Every page', 'All the words, page by page', 'pages'],
    ['more', 'More', 'Form and chat wording, Google and sharing, people', 'more'],
  ];
  const big = [
    ['#/notice', 'Storm / closed notice', n.on ? 'On' : 'Off', 'storm', n.on ? 'on' : ''],
    ['#/fix', 'Fix a word I saw', 'Tap it in the preview', 'word', ''],
    ['#/history', 'Undo a change', 'History', 'undo', ''],
    ['#/help', 'How do I…?', 'Picture guides', 'help', ''],
  ];
  return {
    title: 'Your website',
    home: true,
    body: h('div.stack',
      healthCard(),
      S.boot.status && S.boot.status.state === 'done' && S.boot.status.wentLive && (Date.now() / 1000 - S.boot.status.wentLive < 3600)
        ? h('p.callout.ok', { text: 'Your change went live at ' + timeOf(S.boot.status.wentLive) + '.' }) : null,
      h('div.bigs', big.map(([href, title, sub, ic, st]) => h('a.big' + (st ? '.' + st : ''), { href }, icon(ic), h('span.big-title', { text: title }), h('span.big-sub', { text: sub })))),
      h('button.searchbar', { type: 'button', onclick: () => go('#/search') }, icon('search'), h('span', { text: 'Find words on the website…' })),
      h('nav.tiles', tiles.map(([id, title, sub, ic]) => h('a.tile', { href: id === 'pages' ? '#/pages' : id === 'more' ? '#/more' : id === 'photos' ? '#/photos' : '#/s/' + id },
        icon(ic), h('span.tile-text', h('span.tile-title', { text: title }), h('span.small', { text: sub })), icon('chev')))),
      ops.length ? null : h('p.small.center', { text: 'Change anything, check the preview, then tap "Put it live".' })),
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
    const node = isList(sp) ? listEditor(P(sp)) : fieldBox(P(sp));
    if (node) body.append(node);
  }
  if (id === 'business') { body.insertBefore(leadAddresses(), body.querySelector('[data-key="site.social"]')); body.append(alsoChangeCard()); }
  if (id === 'promises') { body.append(numbersWarning()); body.append(h('p.help', { text: 'The seal\'s artwork says "PAID OR FREE", so it can only be turned on or off.' })); }
  if (id === 'brightness') body.append(brightnessSamples());
  return { title: def.title, body };
}

/** After a phone, email or address change: what she has to change elsewhere herself. */
function alsoChangeCard() {
  const wrap = h('div');
  const draw = () => {
    clear(wrap);
    const was = S.live.content.site;
    const now = S.draft.site;
    const ch = [];
    if (was.phone !== now.phone || (was.phone2 || '') !== (now.phone2 || '')) ch.push('phone');
    if (was.email !== now.email) ch.push('email');
    if (JSON.stringify(was.address) !== JSON.stringify(now.address)) ch.push('address');
    if (!ch.length) return;
    const items = ['Google Business Profile (Google Maps listing)', 'The Facebook page\'s About section', 'The e-sign forms in Adobe (ask John)', 'Business cards and flyers', 'Your email signature'];
    wrap.append(h('div.callout.info', h('h3', { text: 'Also change these yourself' }),
      h('p.small', { text: `The website updates everywhere by itself. These other places still show the old ${ch.join(' and ')}:` }),
      h('ul.checklist', items.map((t) => h('li', h('label.check', h('input', { type: 'checkbox' }), ' ' + t)))),
      h('button.btn.small', { type: 'button', text: 'Check the contract PDFs for the old details', onclick: () => go('#/contracts-check') })));
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

function brightnessSamples() {
  // a typical paragraph (65% white on the dark background) under each preset (Brightness::alpha)
  const presets = { standard: [0.65], brighter: [0.77], brightest: [0.86] };
  const cur = S.draft.site.brightness || 'standard';
  return h('div.bright-samples', Object.entries(presets).map(([k, [a]]) => h('div.bright-sample' + (k === cur ? '.on' : ''),
    h('span.small', { text: { standard: 'Standard', brighter: 'Brighter', brightest: 'Brightest' }[k] }),
    h('p', { style: { color: `rgba(235,229,216,${a})` }, text: 'Paragraphs, descriptions and fine print look like this. Headings, orange words, buttons and menus stay the same.' }))),
    h('p.help', { text: 'One setting for the whole website. Open the preview to see it on a real page.' }));
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
      h('button.btn.ghost', { type: 'button', text: 'Repair the editor', onclick: repairEditor }),
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
  if (now < from) return 'Starts ' + f(from);
  if (now >= until) return 'Ended ' + f(until);
  return 'Showing now' + (until < Infinity ? ', until ' + f(until) : '');
}

export function noticeScreen(publishSome) {
  const body = h('div.stack');
  const draw = () => {
    clear(body);
    const n = S.draft.notice;
    body.append(h('p.status-card', h('span.dot' + (noticeStatus(n).startsWith('Showing') ? '.on' : '')), h('strong', { text: noticeStatus(n) })));
    body.append(fieldBox(['notice', 'on'], { label: 'Show a notice at the top of every page' }));
    const kinds = { storm: 'Storm response', closed: 'Office closed', away: 'John is out until…' };
    const pick = h('div.notice-kinds', { role: 'radiogroup' });
    for (const [k, lab] of Object.entries(kinds)) {
      pick.append(h('button.notice-kind', { type: 'button', role: 'radio', 'aria-checked': String(n.kind === k), onclick: () => { setAt(S.draft, ['notice', 'kind'], k); changed(); draw(); } },
        h('strong', { text: lab }), h('span.small', { text: plain(n[k].text) })));
    }
    body.append(h('h3', { text: 'Which notice' }), pick);
    body.append(fieldBox(['notice', n.kind, 'text'], { label: 'Its words' }));
    body.append(fieldBox(['notice', n.kind, 'link'], { label: 'Its button' }));
    body.append(h('h3', { text: 'When (Indiana time)' }), h('p.help', { text: 'Leave both empty to show it until you turn it off. The website switches it on and off by itself at these times.' }));
    body.append(fieldBox(['notice', 'start'], { label: 'Starts' }), fieldBox(['notice', 'end'], { label: 'Ends' }));
    const btn = h('button.btn.primary.big', { type: 'button', onclick: () => publishSome(currentOps().filter((op) => keyOf(op.path).startsWith('notice')), 'notice') });
    const update = () => {
      const n = currentOps().filter((op) => keyOf(op.path).startsWith('notice')).length;
      btn.disabled = !n;
      btn.textContent = n ? 'Put the notice live now' : 'No notice changes yet';
      body.querySelector('.status-card strong').textContent = noticeStatus(S.draft.notice) + (n ? ' (after you put it live)' : '');
    };
    body.append(h('div.row.end', btn));
    body.update = update;
    update();
  };
  draw();
  on('choice', (p) => { if (body.isConnected && keyOf(p).startsWith('notice')) draw(); });
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
