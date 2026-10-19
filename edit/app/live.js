// "Not live yet": the tray of changes, "Check your changes", putting them live (all, or only
// the ticked ones), the check that each changed page really drew, and History: undo a change,
// bring back a deleted item, put the whole website back to a moment.
import { S, h, icon, clear, getAt, setAt, keyOf, schemaPath, field, itemFields, changed, plain, go, api, toast, sheet, currentOps, flushDraft, whenOf, timeOf, clone, emit, rebase, splitLabel, capFirst, render } from './core.js';
import { itemTitle, cut } from './lists.js';
import { spellCheck, teach } from './spell.js';
import { screenFor } from './screens.js';

// ------------------------------------------------------------------ the tray

/** The page a change shows on (for "Preview" and "See it on the website"). */
export function pageFor(key) {
  const k = String(key || '');
  let top = k.split(/[.[]/)[0];
  if (top === 'seo') top = k.split('.')[1] || ''; // a page's own words on Google
  if (top === 'forms') return k.startsWith('forms.claim') ? 'Claim.dc.html' : k.startsWith('forms.contact') ? 'Contact.dc.html' : 'Home.dc.html';
  return { home: 'Home.dc.html', about: 'About.dc.html', services: 'Services.dc.html', faq: 'FAQ.dc.html', contact: 'Contact.dc.html', claim: 'Claim.dc.html', states: 'Claim.dc.html', notfound: '404.html' }[top] || 'Home.dc.html';
}

/**
 * Only the words that changed, with a little of the words around them:
 * {before, after} as span.diff nodes, the taken-out part in <del>, the put-in part in <mark>.
 */
export function diffParts(a, b, ctx = 25) {
  a = String(a ?? '');
  b = String(b ?? '');
  const max = Math.min(a.length, b.length);
  let p = 0;
  while (p < max && a[p] === b[p]) p++;
  let s = 0;
  while (s < max - p && a[a.length - 1 - s] === b[b.length - 1 - s]) s++;
  // whole words: a change inside a word shows the whole word
  const W = (c) => c !== undefined && /[\p{L}\p{N}'’]/u.test(c);
  while (p > 0 && W(a[p - 1]) && (W(a[p]) || W(b[p]))) p--;
  while (s > 0 && W(a[a.length - s]) && (W(a[a.length - s - 1]) || W(b[b.length - s - 1]))) s--;
  const head = a.slice(0, p);
  const tail = a.slice(a.length - s);
  let left = head;
  if (head.length > ctx) { left = head.slice(-ctx); const sp = left.indexOf(' '); if (sp >= 0 && sp < left.length - 1) left = left.slice(sp + 1); left = '…' + left; }
  let right = tail;
  if (tail.length > ctx) { right = tail.slice(0, ctx); const sp = right.lastIndexOf(' '); if (sp > 0) right = right.slice(0, sp); right += '…'; }
  const mid = (m) => (m.length > 120 ? m.slice(0, 56).trimEnd() + ' … ' + m.slice(-56).trimStart() : m);
  const part = (m, tag) => h('span.diff', left, m ? h(tag, { text: mid(m) }) : null, right);
  return { before: part(a.slice(p, a.length - s), 'del'), after: part(b.slice(p, b.length - s), 'mark') };
}

// the parts of an address, a person, the guarantee and a number, in plain words
const PART = { street: 'street', city: 'town', region: 'state', zip: 'ZIP code', name: 'name', title: 'title', short: 'short version', long: 'long version', value: 'number', caption: 'caption', plus: '+ sign', percent: '% sign', hidden: 'hidden' };
const DIFF_TYPES = new Set(['text', 'para', 'rich', 'alt', 'name']);

/** One line per change: what it is, before and after. */
export function describe(op) {
  const sp = schemaPath(op.path);
  let f = field(sp);
  if (!f && op.path.length > 1) {
    const parent = field(schemaPath(op.path.slice(0, -1)));
    const part = op.path[op.path.length - 1];
    if (parent && ['address', 'person', 'guarantee', 'number-quote'].includes(parent.type) && PART[part]) {
      f = { label: `${parent.label}: ${PART[part]}`, type: ['plus', 'percent', 'hidden'].includes(part) ? 'switch' : 'text' };
    }
  }
  f = f || { label: sp, type: 'text' };
  let where = '';
  let inItem = false;
  for (let i = 0; i < op.path.length; i++) {
    if (typeof op.path[i] !== 'object') continue;
    const lsp = schemaPath(op.path.slice(0, i));
    const it = getAt(S.draft, op.path.slice(0, i + 1)) || getAt(S.live.content, op.path.slice(0, i + 1));
    if (it && i === op.path.length - 1) { where = ''; inItem = false; } else if (it) { where = itemTitle(it, lsp) || 'New item'; inItem = true; }
  }
  // inside a list item: "How long do I have… · Answer"
  const label = inItem && where ? `${cut(where, 40)} · ${splitLabel(f.label)[1].replace(' (optional)', '')}` : f.label + (where ? ' (' + where + ')' : '');
  if (op.op === 'list') {
    const live = getAt(S.live.content, op.path) || [];
    const added = Object.values(op.add || {}).map((it) => '+ ' + itemTitle(getAt(S.draft, op.path.concat([{ id: it._id }])) || it, sp));
    const gone = (op.old || []).filter((id) => !op.order.includes(id)).map((id) => '− ' + itemTitle(live.find((x) => x._id === id), sp));
    const kept = op.order.filter((id) => (op.old || []).includes(id));
    const moved = JSON.stringify(kept) !== JSON.stringify((op.old || []).filter((id) => kept.includes(id)));
    return { label: f.label, what: added.concat(gone).concat(moved ? ['New order'] : []).join(' · ') };
  }
  const type = f.type;
  const show = (v) => {
    if (['tick', 'switch', 'star'].includes(type)) return v ? 'On' : 'Off';
    if (v === null || v === undefined || v === '') return '(empty)';
    if (type === 'choice') return (Array.isArray(f.options) ? v : (f.options || {})[v]) || String(v);
    if (type === 'photo' || type === 'video') return 'a photo';
    if (type === 'state') return S.boot.states[v] || v;
    if (type === 'states') return (v || []).map((c) => S.boot.states[c] || c).join(', ') || '(none)';
    if (type === 'address') return [v.street, v.city, v.region, v.zip].filter(Boolean).join(', ');
    if (type === 'person') return [v.name, v.title].filter(Boolean).join(', ');
    if (type === 'guarantee') return cut(v.short || '', 60);
    if (type === 'number-quote') return (v.plus ? '+' : '') + v.value + (v.percent ? '%' : '') + (v.hidden ? ' (hidden)' : '');
    if (type === 'money') return '$' + v;
    if (typeof v === 'object' && !Array.isArray(v)) return '';
    return cut(plain(v), 80);
  };
  if (sp.endsWith('[].hidden')) return { label: (op.value ? 'Hidden: ' : 'Shown again: ') + itemTitle(getAt(S.draft, op.path.slice(0, -1)), schemaPath(op.path.slice(0, -2))), what: '' };
  if (sp === 'states[].pdfV') return { label: 'New contract' + (where ? ' (' + where + ')' : ''), what: '' };
  if (type === 'focus') return { label, what: 'moved' };
  if (DIFF_TYPES.has(type)) {
    const a = plain(op.old, S.live.content);
    const b = plain(op.value);
    if (a.trim() && b.trim() && a !== b) { const d = diffParts(a, b); return { label, diff: true, before: d.before, after: d.after }; }
    if (a && a === b) return { label, what: 'Same words, with different orange or bold words' };
  }
  return { label, before: show(op.old), after: show(op.value), photo: type === 'photo' ? op.value : null };
}

const WORDY = new Set(['text', 'para', 'rich', 'name']);
/** [has text boxes, has words in them] for an item and the lists inside it. */
function wordsIn(item, listSp) {
  let boxes = false;
  let words = false;
  for (const sp of itemFields(listSp)) {
    const f = field(sp);
    const v = (item || {})[sp.slice(listSp.length + 3)];
    if (WORDY.has(f.type)) { boxes = true; if (plain(v).trim()) words = true; } else if ((f.type === 'list' || f.type === 'checklist') && Array.isArray(v)) {
      for (const sub of v) {
        if (typeof sub === 'string') { boxes = true; if (sub.trim()) words = true; continue; }
        const [b, w] = wordsIn(sub, sp);
        boxes = boxes || b;
        words = words || w;
      }
    }
  }
  return [boxes, words];
}

/** Changes that aren't finished get unticked with the reason, and the box to fix: {msg, path}. */
function unfinished(op) {
  if (op.op !== 'list') return null;
  const listSp = schemaPath(op.path);
  for (const it of Object.values(op.add || {})) {
    const ip = op.path.concat([{ id: it._id }]);
    const cur = getAt(S.draft, ip) || it;
    const [boxes, words] = wordsIn(cur, listSp);
    if (boxes && !words) return { msg: 'Empty: tap ✕ to remove it.' };
    if (listSp === 'home.reviews.items') {
      if (!cur.real) return { msg: 'Turn on "Real customer, copied word for word" first.', path: ip.concat(['real']) };
      if (!plain(cur.text).trim()) return { msg: 'This review needs the words and the name.', path: ip.concat(['text']) };
      if (!plain(cur.name).trim()) return { msg: 'This review needs the words and the name.', path: ip.concat(['name']) };
    }
    if (cur.photo === '') return { msg: 'Add a photo first.', path: ip.concat(['photo']) };
    if (cur.code === '') return { msg: 'Pick the state first.', path: ip.concat(['code']) };
    for (const sp of itemFields(listSp)) {
      const f = field(sp);
      const k = sp.slice(listSp.length + 3);
      if (!['text', 'para', 'rich', 'name'].includes(f.type) || f.optional) continue;
      if (listSp === 'contact.rows' && k === 'text' && cur.type !== 'text') continue;
      if (!plain(cur[k]).trim()) return { msg: `Not finished: "${splitLabel(f.label)[1]}" is empty.`, path: ip.concat([k]) };
    }
  }
  return null;
}

/** Put a change back the way it is on the website (only in the draft). */
function discard(op, quiet = false) {
  if (op.op === 'set') {
    const live = getAt(S.live.content, op.path);
    setAt(S.draft, op.path, live === undefined ? undefined : clone(live));
  } else {
    const live = getAt(S.live.content, op.path) || [];
    const draft = getAt(S.draft, op.path) || [];
    setAt(S.draft, op.path, live.map((it) => clone(draft.find((d) => d._id === it._id) || it)));
  }
  if (!quiet) changed();
}

/**
 * The tray's rows: a new photo, its description and its important part are one change for her
 * ({op: the one that names it, ops: all of them, keys}).
 */
export function trayRows(ops) {
  const rows = [];
  const byGroup = new Map();
  for (const op of ops) {
    const last = op.path[op.path.length - 1];
    const m = op.op === 'set' && typeof last === 'string' ? /^(photo|poster)(Alt|Focus)?$/.exec(last) : null;
    const g = m ? keyOf(op.path.slice(0, -1)) + '|' + m[1] : null;
    if (g && byGroup.has(g)) {
      const row = byGroup.get(g);
      row.ops.push(op);
      row.keys.push(keyOf(op.path));
      if (!m[2]) row.op = op;
      continue;
    }
    const row = { op, ops: [op], keys: [keyOf(op.path)] };
    if (g) byGroup.set(g, row);
    rows.push(row);
  }
  return rows;
}
/** How many changes she sees (a photo with its description counts once). */
export function changeCount(ops) { return trayRows(ops).length; }

const alsoList = (also) => h('ul.beforeafter', also.map((x) => h('li', h('strong', { text: x.what }), h('span.was', { text: x.before || '(empty)' }), h('span.arrow', { text: '→' }), h('span.now', { text: x.after || '(empty)' }))));

export function trayScreen(publishSome) {
  const body = h('div.stack');
  const ticks = new Map();
  const status = h('div.tray-status');
  let lastCheck = null;
  let checkTimer = null;
  let checkRun = 0;
  const chosenOps = () => currentOps().filter((op) => ticks.get(keyOf(op.path)));
  const sigOf = (ops) => ops.map((op) => keyOf(op.path)).join('|');
  /** The check runs right here, so a change with nothing to look at goes live in one tap. */
  const runCheck = (delay) => {
    clearTimeout(checkTimer);
    lastCheck = null;
    const chosen = chosenOps();
    if (!chosen.length) { clear(status).append(h('p.small', { text: 'Nothing is ticked yet. Tick a change above to put it live.' })); return; }
    clear(status).append(h('div.small', { text: 'Checking your changes…' }));
    checkTimer = setTimeout(async () => {
      const run = ++checkRun;
      let r;
      let words;
      try {
        await flushDraft();
        r = await api('check', { ops: chosen, base: S.base, resolve: S.resolve });
        words = await spellCheck(chosen);
      } catch (e) {
        if (run === checkRun && status.isConnected) clear(status).append(h('p.small', { text: 'Couldn\'t check right now (no connection).' }));
        return;
      }
      if (run !== checkRun || !status.isConnected) return;
      const unresolved = (r.conflicts || []).filter((c) => !S.resolve[c.key]);
      const n = (r.problems || []).length + unresolved.length + (r.warnings || []).length + words.length;
      lastCheck = { sig: sigOf(chosen), clean: n === 0 };
      clear(status);
      if (n === 0) {
        status.append(h('p.callout.ok', { text: 'Checked: nothing to fix.' }));
        if ((r.also || []).length) status.append(h('p.small', { text: 'This will also change:' }), alsoList(r.also));
      } else {
        status.append(h('p.callout.warn', { text: `${n} thing${n === 1 ? '' : 's'} to look at before it goes live.` }));
      }
    }, delay);
  };
  const draw = () => {
    clear(body);
    const ops = currentOps();
    if (!ops.length) {
      body.append(h('div.empty', icon('check'), h('p', { text: 'Everything you changed is live.' }), h('a.btn', { href: '#/', text: 'Back to the start' })));
      return;
    }
    body.append(h('p.help', { text: 'These are saved, but visitors don\'t see them yet. Untick anything that isn\'t ready.' }));
    const rows = trayRows(ops);
    const list = h('ul.tray-list');
    for (const row of rows) {
      const { op, keys } = row;
      const key = keyOf(op.path);
      const d = describe(op);
      const why = unfinished(op);
      const on = ticks.has(key) ? ticks.get(key) : !why;
      keys.forEach((k) => ticks.set(k, on));
      const cb = h('input', { type: 'checkbox', checked: ticks.get(key), 'aria-label': 'Include: ' + d.label });
      cb.addEventListener('change', () => { keys.forEach((k) => ticks.set(k, cb.checked)); updateButtons(); runCheck(400); });
      const conflict = S.conflicts.find((c) => keys.includes(c.key) && !S.resolve[c.key]);
      const photoOp = row.ops.find((o) => /^(photo|poster)$/.test(o.path[o.path.length - 1]));
      list.append(h('li.tray-item',
        h('label.tray-check', cb),
        h('div.tray-text',
          h('button.linkish.tray-label', { type: 'button', onclick: () => { S.reveal = key; go(screenFor(op.path)); } }, d.label, icon('chev')),
          d.what !== undefined && d.what !== '' ? h('span.small', { text: d.what }) : null,
          photoOp && photoOp.value
            ? h('div.tray-thumbs', photoOp.old ? h('img', { src: '/' + photoOp.old, alt: 'Before' }) : null, h('span.arrow', { text: '→' }), h('img', { src: '/' + photoOp.value, alt: 'After' }))
            : d.before !== undefined ? h('span.ba', d.diff ? d.before : h('span.was', { text: d.before }), h('span.arrow', { text: ' → ' }), d.diff ? d.after : h('span.now', { text: d.after })) : null,
          why ? h('span.warn-text.small', { text: why.msg }) : null,
          why && why.path ? h('button.btn.small', { type: 'button', text: 'Fix it', onclick: () => { S.reveal = keyOf(why.path); go(screenFor(why.path)); } }) : null,
          conflict ? h('span.warn-text.small', { text: `${conflict.byName || 'Someone'} changed this too. Open it to choose.` }) : null),
        h('button.icon-btn', { type: 'button', 'aria-label': 'Throw away this change', title: 'Throw away', onclick: async () => {
          const ok = await sheet('Throw away this change?', `“${d.label}” goes back to what visitors see now.`, [{ label: 'Throw it away', value: true, kind: 'danger' }, { label: 'Keep it', value: false, kind: 'ghost' }]);
          if (!ok) return;
          // a photo's description and important part go with it: undo them together
          row.ops.forEach((o, i) => discard(o, i < row.ops.length - 1));
          if (currentOps().length) draw(); else render();
        } }, icon('x'))));
    }
    body.append(list, status);
    const pv = h('a.btn', { href: '#/preview/Home.dc.html' }, icon('eye'), 'Preview');
    const goBtn = h('button.btn.primary.big', { type: 'button', onclick: () => {
      const chosen = chosenOps();
      if (!chosen.length) return;
      if (lastCheck && lastCheck.clean && lastCheck.sig === sigOf(chosen)) publishSome(chosen);
      else checkScreen(chosen, publishSome);
    } });
    const updateButtons = () => {
      const picked = rows.filter((r) => ticks.get(keyOf(r.op.path)));
      goBtn.textContent = picked.length === rows.length ? `Put it live (${picked.length})` : `Put it live (${picked.length} of ${rows.length})`;
      // never a greyed-out button: with nothing ticked it goes, and the reason shows above
      goBtn.hidden = picked.length === 0;
      const first = (picked[0] || rows[0]).op;
      pv.href = `#/preview/${pageFor(keyOf(first.path))}/${encodeURIComponent(keyOf(first.path))}`;
    };
    updateButtons();
    body.append(h('div.row.end.sticky-actions', pv, goBtn));
    runCheck(0);
  };
  draw();
  return { title: currentOps().length ? 'Not live yet' : 'All live', body };
}

// ------------------------------------------------------------------ check, then put live

let checkHost = null;
export async function checkScreen(ops, publishSome) {
  const back = h('div.sheet-back.in');
  const box = h('div.sheet.tall', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Check your changes' });
  back.append(box);
  document.body.append(back);
  checkHost = back;
  const opened = Date.now();
  const close = () => { back.remove(); checkHost = null; document.removeEventListener('keydown', esc); };
  const esc = (e) => { if (e.key === 'Escape') close(); };
  document.addEventListener('keydown', esc);
  // a double tap on "Put it live" mustn't close it again at once
  back.addEventListener('click', (e) => { if (e.target === back && Date.now() - opened > 450) close(); });
  const head = (title) => h('div.sheet-head', h('h2', { text: title }), h('button.icon-btn', { type: 'button', 'aria-label': 'Close', onclick: close }, icon('x')));
  const draw = async () => {
    clear(box);
    box.append(head('Check your changes'), h('div.sheet-scroll', h('p.small', { text: 'Checking…' })));
    await flushDraft();
    let r;
    let words;
    try {
      r = await api('check', { ops, base: S.base, resolve: S.resolve });
      words = await spellCheck(ops);
    } catch (e) {
      clear(box);
      box.append(head('Not connected'), h('div.sheet-scroll', h('p', { text: 'The check needs a connection. Your changes are saved on this phone.' })),
        h('div.sheet-foot', h('button.btn.primary', { type: 'button', text: 'Try again', onclick: draw }), h('button.btn.ghost', { type: 'button', text: 'Close', onclick: close })));
      return;
    }
    clear(box);
    const scroll = h('div.sheet-scroll');
    const summary = h('p');
    box.append(head('Check your changes'), scroll);
    const problems = r.problems || [];
    const warnings = r.warnings || [];
    const blocking = problems.length > 0;
    const confl = (r.conflicts || []).filter((c) => !S.resolve[c.key]);
    scroll.append(summary);
    if (confl.length) {
      scroll.append(h('h3', { text: 'Someone else changed these too' }));
      for (const c of confl) {
        scroll.append(h('div.callout.warn',
          h('p', h('strong', { text: c.label }), ` — ${c.byName} changed it ${c.at ? whenOf(c.at) : 'meanwhile'}.`),
          c.why === 'gone' ? h('p.small', { text: 'It was deleted, so your change to it can\'t go live.' }) : h('p.small', { text: `Theirs: ${c.theirs || '(empty)'} · Yours: ${c.mine || '(empty)'}` }),
          h('div.row',
            h('button.btn.small', { type: 'button', text: c.why === 'gone' ? 'Throw away my change' : `Keep ${c.byName}'s`, onclick: () => {
              const live = getAt(S.live.content, c.path);
              if (c.why !== 'gone') setAt(S.draft, c.path, live === undefined ? undefined : clone(live));
              S.resolve[c.key] = 'theirs';
              changed();
              ops = ops.filter((o) => keyOf(o.path) !== c.key);
              draw();
            } }),
            c.why === 'gone' ? null : h('button.btn.small.primary', { type: 'button', text: 'Use mine', onclick: () => { S.resolve[c.key] = 'mine'; changed(); draw(); } }))));
      }
    }
    if (blocking) {
      scroll.append(h('h3', { text: 'Fix before it can go live' }));
      for (const p of problems) scroll.append(h('div.callout.bad', h('p', { text: p.msg }), p.key ? h('button.btn.small', { type: 'button', text: 'Fix it', onclick: () => { close(); S.reveal = p.key; go(screenFor(p.path)); } }) : null));
    }
    if (warnings.length) {
      scroll.append(h('h3', { text: 'Have a look' }));
      for (const w of warnings) scroll.append(h('div.callout.warn', h('p', { text: w.msg }), h('button.btn.small.ghost', { type: 'button', text: 'Look', onclick: () => { close(); S.reveal = w.key; go(screenFor(w.path)); } })));
    }
    let spelling = words.length;
    if (words.length) {
      scroll.append(h('h3', { text: 'Check the spelling' }), h('p.small', { text: 'These words aren\'t in the word list. If one is right, tap "Spelled right" and it won\'t be flagged again.' }));
      for (const w of words) {
        const c = w.ctx || {};
        const row = h('div.spell-row',
          h('span.spell-ctx', c.before ? (c.cutBefore ? '…' : '') + c.before : '', h('strong', { text: w.word }), c.after ? c.after + (c.cutAfter ? '…' : '') : ''),
          h('span.small', { text: 'in ' + w.label }),
          h('div.row',
            h('button.btn.small.primary', { type: 'button', text: 'Fix it', onclick: () => { close(); S.reveal = w.key; go(screenFor(w.path)); } }),
            h('button.btn.small.ghost', { type: 'button', text: 'Spelled right', onclick: async () => {
              try { await teach(w.word); } catch (e) { toast(e.message, 'warn'); return; }
              row.remove();
              spelling--;
              update();
            } })));
        scroll.append(row);
      }
    }
    if ((r.also || []).length) scroll.append(h('h3', { text: 'This will also change' }), alsoList(r.also));
    const goBtn = h('button.btn.primary.big', { type: 'button', disabled: blocking || confl.length > 0, onclick: () => { close(); publishSome(ops); } });
    const update = () => {
      const k = confl.length + problems.length + warnings.length + spelling;
      summary.className = k ? '' : 'callout ok';
      summary.textContent = k ? `${k} thing${k === 1 ? '' : 's'} to look at.` + (!blocking && !confl.length ? ' Nothing here stops it going live.' : '') : 'Everything looks fine.';
      goBtn.textContent = blocking ? 'Fix the problems first' : confl.length ? 'Choose above first' : warnings.length || spelling ? 'Put it live anyway' : 'Put it live';
    };
    update();
    box.append(h('div.sheet-foot', goBtn, h('button.btn.ghost', { type: 'button', text: 'Not yet', onclick: close })));
    scroll.scrollTop = 0;
  };
  draw();
}

/** The live page, loaded off-screen: did it draw, without page errors, with the new words? */
export function checkPage(file, words, ms = 20000) {
  return new Promise((resolve) => {
    const ifr = h('iframe.checker', { src: '/edit/p/live/' + file + '?c=' + Date.now(), 'aria-hidden': 'true', tabIndex: -1, title: 'Checking ' + file });
    let done = false;
    const t0 = Date.now();
    const norm = (s) => (s || '').toLowerCase().replace(/[\s ​]+/g, '').replace(/[’‘]/g, "'").replace(/[“”]/g, '"');
    const finish = (state, why = '') => { if (done) return; done = true; ifr.remove(); resolve({ file, state, why }); };
    const tick = () => {
      if (done) return;
      let win = null;
      let doc = null;
      try { win = ifr.contentWindow; doc = ifr.contentDocument; } catch (e) { /* */ }
      const errs = (win && win.__tsCheck && win.__tsCheck.errors) || [];
      if (errs.length) return finish('fail', errs.join(' | ').slice(0, 400));
      const root = doc && doc.getElementById('dc-root');
      if (root && root.childElementCount > 0 && Date.now() - t0 > 1500) {
        const text = norm(doc.body.textContent);
        const missing = words.filter((w) => !text.includes(norm(w)));
        if (!missing.length) return finish('ok');
        if (Date.now() - t0 > 9000) return finish('unsure', 'not found: ' + missing.join(', '));
      }
      if (Date.now() - t0 > ms) return finish('unsure', 'the page didn\'t finish drawing');
      setTimeout(tick, 300);
    };
    ifr.addEventListener('load', () => setTimeout(tick, 300));
    setTimeout(() => finish('unsure', 'timed out'), ms + 3000);
    document.body.append(ifr);
  });
}

/** After a change went live: check every changed page; put it back only on a definite failure. */
export async function verify(r, show) {
  const pages = Object.entries(r.pages || {});
  if (!pages.length) return 'ok';
  const names = { 'Home.dc.html': 'Home', 'About.dc.html': 'About', 'Services.dc.html': 'Services', 'FAQ.dc.html': 'FAQ', 'Contact.dc.html': 'Contact', 'Claim.dc.html': 'File a Claim', 'ChatWidget.dc.html': 'the chat' };
  // two pages at a time
  const results = [];
  let next = 0;
  let done = 0;
  show(`Checking the pages… 0 of ${pages.length}`);
  const worker = async () => {
    while (next < pages.length) {
      const [file, words] = pages[next++];
      results.push(await checkPage(file, words));
      show(`Checking the pages… ${++done} of ${pages.length}`);
    }
  };
  await Promise.all([worker(), worker()]);
  const bad = results.filter((x) => x.state === 'fail');
  if (bad.length) {
    try {
      await api('autoundo', { rev: r.entry.rev, id: 'auto-' + r.entry.rev + '-' + Date.now(), why: bad.map((b) => b.file + ': ' + b.why).join(' ; ') });
      return { state: 'undone', page: names[bad[0].file] || bad[0].file };
    } catch (e) {
      return { state: 'bad', page: names[bad[0].file] || bad[0].file };
    }
  }
  const unsure = results.filter((x) => x.state === 'unsure');
  if (unsure.length) return { state: 'unsure', files: unsure.map((u) => u.file), page: names[unsure[0].file] || unsure[0].file };
  return 'ok';
}

// ------------------------------------------------------------------ history

const KIND = { launch: 'Launch day', undo: 'Undo', bringback: 'Brought back', restore: 'Whole website put back', autoundo: 'Put back automatically', repair: 'Fixed automatically' };
const retryCard = (e) => h('div.callout.warn', h('p', { text: e.message }), h('button.btn', { type: 'button', text: 'Try again', onclick: () => render() }));

export async function historyScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const ready = (async () => {
    let r;
    try { r = await api('history'); } catch (e) { clear(body).append(retryCard(e)); return; }
    clear(body);
    body.append(h('p.help', { text: 'Every change that went live. Tap one to see it and undo it.' }));
    const ul = h('ul.history');
    // the website's own repairs aren't her changes (John is emailed about those)
    for (const e of r.entries.filter((x) => x.kind !== 'repair')) {
      const who = e.by === null ? '' : e.by === S.me.id ? 'You' : (r.names[e.by] || 'Someone');
      ul.append(h('li', h('a.history-item', { href: '#/history/' + e.rev },
        h('span.when', { text: e.when }),
        KIND[e.kind] ? h('span.pill', { text: KIND[e.kind] }) : null,
        h('span.lines', e.lines.slice(0, 3).map((l, i) => h('span.line', { text: capFirst((i === 0 && who ? who + ' ' : '') + l + '.') })), e.lines.length > 3 ? h('span.small', { text: `and ${e.lines.length - 3} more` }) : null),
        icon('chev'))));
    }
    body.append(ul);
  })();
  return { title: 'Undo a change', body, ready };
}

export async function historyEntryScreen(rev, publishWith) {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  const onOff = (t) => (t === 'on' ? 'On' : t === 'off' ? 'Off' : t);
  const ready = (async () => {
    let r;
    try { r = await api('history-entry', { rev }, { get: true }); } catch (e) { clear(body).append(retryCard(e)); return; }
    const e = r.entry;
    const who = e.by === null ? '' : e.by === S.me.id ? 'You' : (S.names[e.by] || 'Someone');
    clear(body);
    body.append(h('p.crumb', { text: e.when + (KIND[e.kind] ? ' · ' + KIND[e.kind] : '') }));
    body.append(h('ul.lines-big', e.lines.map((l) => h('li', { text: capFirst((who ? who + ' ' : '') + l + '.') }))));
    const rows = h('div.stack');
    for (const row of r.rows) {
      if (row.deleted) {
        rows.append(h('div.ba-card', h('strong', { text: row.label }), h('p', { text: 'Deleted: ' + row.deleted }),
          h('button.btn.small', { type: 'button', text: 'Bring back “' + cut(row.deleted, 40) + '”', onclick: () => publishWith('bringback', { rev: e.rev, item: row.item }, 'Brought back “' + cut(row.deleted, 40) + '”.') })));
      } else if (row.added) {
        rows.append(h('div.ba-card', h('strong', { text: row.label }), h('p', { text: 'Added: ' + row.added })));
      } else if (row.type === 'photo') {
        rows.append(h('div.ba-card', h('strong', { text: row.label }),
          h('div.row', row.beforeRaw ? h('img.ba-photo', { src: '/' + row.beforeRaw, alt: 'Before' }) : null, h('span.arrow', { text: '→' }), row.afterRaw ? h('img.ba-photo', { src: '/' + row.afterRaw, alt: 'After' }) : null)));
      } else if (row.beforeFull && row.afterFull) {
        // long words: only the part that changed, with a little around it
        const d = diffParts(row.beforeFull, row.afterFull);
        rows.append(h('div.ba-card', h('strong', { text: row.label }),
          h('div.ba', h('div', h('span.small', { text: 'Before' }), h('p', d.before)), h('div', h('span.small', { text: 'After' }), h('p', d.after)))));
      } else {
        rows.append(h('div.ba-card', h('strong', { text: row.label }),
          h('div.ba', h('div', h('span.small', { text: 'Before' }), h('p.was', { text: onOff(row.before) || '(empty)' })), h('div', h('span.small', { text: 'After' }), h('p.now', { text: onOff(row.after) || '(empty)' })))));
      }
    }
    body.append(rows);
    const acts = h('div.stack');
    if (r.canUndo) acts.append(h('button.btn.primary.big', { type: 'button', text: 'Undo this change', onclick: () => publishWith('undo', { rev: e.rev }, 'Undone.') }));
    acts.append(h('button.btn.ghost', { type: 'button', text: 'Put the whole website back to this moment', onclick: async () => {
      let later = [];
      try { later = (await api('restore-preview', { rev: e.rev }, { get: true })).later; } catch (x) { /* */ }
      const list = h('div', h('p', { text: 'Every word, photo and list goes back to how it was ' + e.when + '. Logins, passwords and lead addresses are never touched. Undo works on this too.' }),
        later.length ? h('div.callout.warn', h('p', { text: 'It also removes these later changes by others:' }), h('ul', later.map((l) => h('li', { text: `${l.when}, ${l.byName}: ${l.lines.join('; ')}` })))) : null);
      const ok = await sheet('Put the whole website back?', list, [{ label: 'Put it back', value: true, kind: 'danger' }, { label: 'Cancel', value: false, kind: 'ghost' }]);
      if (ok) publishWith('restore', { rev: e.rev }, 'The website is back to ' + e.when + '.');
    } }));
    body.append(acts);
  })();
  return { title: 'Change', back: '#/history', body, ready };
}
