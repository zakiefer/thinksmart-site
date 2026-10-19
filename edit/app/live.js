// "Not live yet": the tray of changes, "Check your changes", putting them live (all, or only
// the ticked ones), the check that each changed page really drew, and History: undo a change,
// bring back a deleted item, put the whole website back to a moment.
import { S, h, icon, clear, getAt, setAt, keyOf, schemaPath, field, itemFields, changed, plain, go, api, toast, sheet, currentOps, flushDraft, whenOf, timeOf, clone, emit, rebase, splitLabel } from './core.js';
import { itemTitle, cut } from './lists.js';
import { spellCheck, teach } from './spell.js';
import { screenFor } from './screens.js';

// ------------------------------------------------------------------ the tray

/** One line per change: what it is, before and after. */
export function describe(op) {
  const sp = schemaPath(op.path);
  const f = field(sp) || { label: sp, type: 'text' };
  let where = '';
  for (let i = 0; i < op.path.length; i++) {
    if (typeof op.path[i] !== 'object') continue;
    const lsp = schemaPath(op.path.slice(0, i));
    const it = getAt(S.draft, op.path.slice(0, i + 1)) || getAt(S.live.content, op.path.slice(0, i + 1));
    if (it && i === op.path.length - 1) where = '';
    else if (it) where = itemTitle(it, lsp);
  }
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
    if (v === null || v === undefined || v === '') return '(empty)';
    if (['tick', 'switch', 'star'].includes(type)) return v ? 'On' : 'Off';
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
  if (type === 'focus') return { label: f.label + (where ? ' (' + where + ')' : ''), what: 'moved' };
  return { label: f.label + (where ? ' (' + where + ')' : ''), before: show(op.old), after: show(op.value), photo: type === 'photo' ? op.value : null };
}

/** Changes that aren't finished get unticked with the reason. */
function unfinished(op) {
  if (op.op === 'list' && schemaPath(op.path) === 'home.reviews.items') {
    for (const it of Object.values(op.add || {})) {
      const cur = getAt(S.draft, op.path.concat([{ id: it._id }])) || it;
      if (!cur.real) return 'This review needs the "Real customer, copied word for word" tick.';
      if (!plain(cur.text).trim() || !plain(cur.name).trim()) return 'This review needs the words and the name.';
    }
  }
  if (op.op === 'list') {
    const listSp = schemaPath(op.path);
    for (const it of Object.values(op.add || {})) {
      const cur = getAt(S.draft, op.path.concat([{ id: it._id }])) || it;
      if (cur.photo === '') return 'Add a photo first.';
      if (cur.code === '') return 'Pick the state first.';
      for (const sp of itemFields(listSp)) {
        const f = field(sp);
        const k = sp.slice(listSp.length + 3);
        if (!['text', 'para', 'rich', 'name'].includes(f.type) || f.optional) continue;
        if (listSp === 'contact.rows' && k === 'text' && cur.type !== 'text') continue;
        if (!plain(cur[k]).trim()) return `Not finished: "${splitLabel(f.label)[1]}" is empty.`;
      }
    }
  }
  return '';
}

/** Put a change back the way it is on the website (only in the draft). */
function discard(op) {
  if (op.op === 'set') {
    const live = getAt(S.live.content, op.path);
    setAt(S.draft, op.path, live === undefined ? undefined : clone(live));
  } else {
    const live = getAt(S.live.content, op.path) || [];
    const draft = getAt(S.draft, op.path) || [];
    setAt(S.draft, op.path, live.map((it) => clone(draft.find((d) => d._id === it._id) || it)));
  }
  changed();
}

export function trayScreen(publishSome) {
  const body = h('div.stack');
  const ticks = new Map();
  const draw = () => {
    clear(body);
    const ops = currentOps();
    if (!ops.length) {
      body.append(h('div.empty', icon('check'), h('p', { text: 'Everything you changed is live.' }), h('a.btn', { href: '#/', text: 'Back to the start' })));
      return;
    }
    body.append(h('p.help', { text: 'These are saved, but visitors don\'t see them yet. Untick anything that isn\'t ready.' }));
    const list = h('ul.tray-list');
    for (const op of ops) {
      const key = keyOf(op.path);
      const d = describe(op);
      const why = unfinished(op);
      if (!ticks.has(key)) ticks.set(key, !why);
      const cb = h('input', { type: 'checkbox', checked: ticks.get(key), 'aria-label': 'Include: ' + d.label });
      cb.addEventListener('change', () => { ticks.set(key, cb.checked); updateButtons(); });
      const conflict = S.conflicts.find((c) => c.key === key && !S.resolve[key]);
      list.append(h('li.tray-item',
        h('label.tray-check', cb),
        h('div.tray-text',
          h('button.linkish.tray-label', { type: 'button', text: d.label, onclick: () => { S.reveal = key; go(screenFor(op.path)); } }),
          d.what !== undefined && d.what !== '' ? h('span.small', { text: d.what }) : null,
          d.before !== undefined ? h('span.ba', h('span.was', { text: d.before }), h('span.arrow', { text: ' → ' }), h('span.now', { text: d.after })) : null,
          d.photo ? h('img.tray-thumb', { src: '/' + d.photo, alt: '' }) : null,
          why ? h('span.warn-text.small', { text: why }) : null,
          conflict ? h('span.warn-text.small', { text: `${conflict.byName || 'Someone'} changed this too. Open it to choose.` }) : null),
        h('button.icon-btn', { type: 'button', 'aria-label': 'Discard this change', title: 'Discard', onclick: async () => {
          const ok = await sheet('Discard this change?', `“${d.label}” goes back to what's on the website now.`, [{ label: 'Discard', value: true, kind: 'danger' }, { label: 'Keep it', value: false, kind: 'ghost' }]);
          if (ok) { discard(op); draw(); }
        } }, icon('x'))));
    }
    body.append(list);
    const pv = h('a.btn', { href: '#/preview/Home.dc.html' }, icon('eye'), 'Preview');
    const goBtn = h('button.btn.primary.big', { type: 'button', onclick: () => {
      const chosen = currentOps().filter((op) => ticks.get(keyOf(op.path)));
      if (!chosen.length) { toast('Tick at least one change.', 'warn'); return; }
      checkScreen(chosen, publishSome);
    } });
    const updateButtons = () => {
      const n = currentOps().filter((op) => ticks.get(keyOf(op.path))).length;
      goBtn.textContent = n === ops.length ? `Check and put live (${n})` : `Check and put live (${n} of ${ops.length})`;
      goBtn.disabled = n === 0;
    };
    updateButtons();
    body.append(h('div.row.end.sticky-actions', pv, goBtn));
  };
  draw();
  return { title: 'Not live yet', body };
}

// ------------------------------------------------------------------ check, then put live

let checkHost = null;
export async function checkScreen(ops, publishSome) {
  const back = h('div.sheet-back.in');
  const box = h('div.sheet.tall', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Check your changes' });
  back.append(box);
  document.body.append(back);
  checkHost = back;
  const close = () => { back.remove(); checkHost = null; };
  const draw = async () => {
    clear(box);
    box.append(h('h2', { text: 'Check your changes' }), h('p.small', { text: 'Checking…' }));
    await flushDraft();
    let r;
    try {
      r = await api('check', { ops, base: S.base, resolve: S.resolve });
    } catch (e) {
      clear(box);
      box.append(h('h2', { text: 'Not connected' }), h('p', { text: 'The check needs a connection. Your changes are saved on this phone.' }), h('div.sheet-buttons', h('button.btn', { type: 'button', text: 'Close', onclick: close })));
      return;
    }
    clear(box);
    box.append(h('h2', { text: 'Check your changes' }));
    const blocking = (r.problems || []).length > 0;
    const confl = (r.conflicts || []).filter((c) => !S.resolve[c.key]);
    if (confl.length) {
      box.append(h('h3', { text: 'Someone else changed these too' }));
      for (const c of confl) {
        box.append(h('div.callout.warn',
          h('p', h('strong', { text: c.label }), ` — ${c.byName} changed it ${c.at ? whenOf(c.at) : 'meanwhile'}.`),
          c.why === 'gone' ? h('p.small', { text: 'It was deleted, so your change to it can\'t go live.' }) : h('p.small', { text: `Theirs: ${c.theirs || '(empty)'} · Yours: ${c.mine || '(empty)'}` }),
          h('div.row',
            h('button.btn.small', { type: 'button', text: c.why === 'gone' ? 'Drop my change' : `Keep ${c.byName}'s`, onclick: () => {
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
      box.append(h('h3', { text: 'Fix before it can go live' }));
      for (const p of r.problems) box.append(h('div.callout.bad', h('p', { text: p.msg }), p.key ? h('button.btn.small', { type: 'button', text: 'Fix it', onclick: () => { close(); S.reveal = p.key; go(screenFor(p.path)); } }) : null));
    }
    if ((r.warnings || []).length) {
      box.append(h('h3', { text: 'Have a look' }));
      for (const w of r.warnings) box.append(h('div.callout.warn', h('p', { text: w.msg }), h('button.btn.small.ghost', { type: 'button', text: 'Look', onclick: () => { close(); S.reveal = w.key; go(screenFor(w.path)); } })));
    }
    const words = await spellCheck(ops);
    if (words.length) {
      box.append(h('h3', { text: 'Check the spelling' }), h('p.small', { text: 'These words aren\'t in the word list. If one is right, tap "Spelled right" and it won\'t be flagged again.' }));
      for (const w of words) {
        const row = h('div.spell-row', h('strong', { text: w.word }), h('span.small', { text: ' in ' + w.label }),
          h('div.row', h('button.btn.small', { type: 'button', text: 'Spelled right', onclick: async () => { await teach(w.word); row.remove(); } }),
            h('button.btn.small.ghost', { type: 'button', text: 'Fix it', onclick: () => { close(); S.reveal = w.key; go(screenFor(w.path)); } })));
        box.append(row);
      }
    }
    if ((r.also || []).length) {
      box.append(h('h3', { text: 'This will also change' }),
        h('ul.beforeafter', r.also.map((x) => h('li', h('strong', { text: x.what }), h('span.was', { text: x.before || '(empty)' }), h('span.arrow', { text: '→' }), h('span.now', { text: x.after || '(empty)' })))));
    }
    if (!confl.length && !blocking && !(r.warnings || []).length && !words.length) box.append(h('p.callout.ok', { text: 'All good: nothing to look at.' }));
    box.append(h('div.sheet-buttons',
      h('button.btn.primary.big', { type: 'button', disabled: blocking || confl.length > 0, text: blocking ? 'Fix the problems first' : confl.length ? 'Choose above first' : 'Put it live', onclick: () => { close(); publishSome(ops); } }),
      h('button.btn.ghost', { type: 'button', text: 'Not yet', onclick: close })));
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

const KIND = { launch: 'Launch day', undo: 'Undo', bringback: 'Brought back', restore: 'Whole website put back', autoundo: 'Put back automatically' };

export async function historyScreen() {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  (async () => {
    let r;
    try { r = await api('history'); } catch (e) { clear(body).append(h('p.callout.warn', { text: 'History needs a connection.' })); return; }
    clear(body);
    body.append(h('p.help', { text: 'Every change that went live. Tap one to see it and undo it.' }));
    const ul = h('ul.history');
    for (const e of r.entries) {
      const who = e.by === null ? '' : e.by === S.me.id ? 'You' : (r.names[e.by] || 'Someone');
      ul.append(h('li', h('a.history-item', { href: '#/history/' + e.rev },
        h('span.when', { text: e.when }),
        KIND[e.kind] ? h('span.pill', { text: KIND[e.kind] }) : null,
        h('span.lines', e.lines.slice(0, 3).map((l, i) => h('span.line', { text: (i === 0 && who ? who + ' ' : '') + l + '.' })), e.lines.length > 3 ? h('span.small', { text: `and ${e.lines.length - 3} more` }) : null),
        icon('chev'))));
    }
    body.append(ul);
  })();
  return { title: 'Undo a change', body };
}

export async function historyEntryScreen(rev, publishWith) {
  const body = h('div.stack', h('p.small', { text: 'Loading…' }));
  (async () => {
    let r;
    try { r = await api('history-entry', { rev }, { get: true }); } catch (e) { clear(body).append(h('p.callout.warn', { text: e.message })); return; }
    const e = r.entry;
    const who = e.by === null ? '' : e.by === S.me.id ? 'You' : (S.names[e.by] || 'Someone');
    clear(body);
    body.append(h('p.crumb', { text: e.when + (KIND[e.kind] ? ' · ' + KIND[e.kind] : '') }));
    body.append(h('ul.lines-big', e.lines.map((l) => h('li', { text: (who ? who + ' ' : '') + l + '.' }))));
    const rows = h('div.stack');
    for (const row of r.rows) {
      if (row.deleted) {
        rows.append(h('div.ba-card', h('strong', { text: row.label }), h('p', { text: 'Deleted: ' + row.deleted }),
          h('button.btn.small', { type: 'button', text: 'Bring back “' + cut(row.deleted, 40) + '”', onclick: () => publishWith('bringback', { rev: e.rev, item: row.item }, 'Brought back “' + cut(row.deleted, 40) + '”.') })));
      } else if (row.added) {
        rows.append(h('div.ba-card', h('strong', { text: row.label }), h('p', { text: 'Added: ' + row.added })));
      } else {
        rows.append(h('div.ba-card', h('strong', { text: row.label }),
          row.type === 'photo' ? h('div.row', row.beforeRaw ? h('img.ba-photo', { src: '/' + row.beforeRaw, alt: 'Before' }) : null, h('span.arrow', { text: '→' }), row.afterRaw ? h('img.ba-photo', { src: '/' + row.afterRaw, alt: 'After' }) : null)
            : h('div.ba', h('div', h('span.small', { text: 'Before' }), h('p.was', { text: row.before || '(empty)' })), h('div', h('span.small', { text: 'After' }), h('p.now', { text: row.after || '(empty)' })))));
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
  return { title: 'Change', back: '#/history', body };
}
