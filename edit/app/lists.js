// One shared list editor for every list. Lists whose items have their own screen show calm
// rows (tap one to open it; "Change the order" shows Up and Down); Hide for now and Delete sit
// at the bottom of the item's screen. Short lists (paragraphs, tags, ticks) keep labelled Up,
// Down, Hide and Delete on each row. Limits explain themselves in plain words instead of
// greyed-out buttons. Deleted items come back from History.
import { S, h, icon, clear, getAt, setAt, keyOf, schemaPath, field, itemFields, changed, plain, go, back, render, sheet, toast, newId, clone, isList, splitLabel, emit, on } from './core.js';
import { fieldBox, shortName, kb } from './fields.js';
import { screenFor } from './screens.js';

/** A short name for an item ("How long do I have…", "Kentucky", "Michael R."). */
export function itemTitle(item, listSp) {
  if (!item) return '';
  if (item.code !== undefined) return S.boot.states[item.code] || 'New state (pick one)';
  if (listSp === 'home.reviews.items') return (item.name ? shortName(item.name) : 'New review') + (item.text ? ': “' + cut(item.text, 50) + '”' : '');
  if (listSp === 'site.social') return (item.platform || 'Link') + (item.url ? ' · ' + item.url.replace(/^https:\/\/(www\.)?/, '') : '');
  for (const k of ['q', 'name', 'title', 'label', 'text', 'tag']) {
    if (item[k] !== undefined && item[k] !== '') { const t = plain(item[k]); if (t) return cut(t, 64); }
  }
  if (item.a && item.a[0]) { const t = cut(plain(item.a[0].text), 64); if (t) return t; }
  return listSp === 'faq.items' ? 'New question' : 'New ' + nounOf(listSp);
}
export function cut(s, n) { s = String(s || '').replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s; }
function kindOf(f) { return (f.add || 'Add an item').replace(/^Add\s*/i, '') || 'item'; }
/** What one item is called ("question", "review", "social link"). */
export function nounOf(listSp) { const f = listSp ? field(listSp) : null; return (f ? kindOf(f) : '').replace(/^an?\s+/i, '') || 'item'; }

/** A new item with every field empty (lists inside get their minimum of empty entries). */
export function blankItem(listSp) {
  const it = { _id: newId() };
  for (const sp of itemFields(listSp)) {
    const f = field(sp);
    const k = sp.slice(listSp.length + 3);
    switch (f.type) {
      case 'tick': case 'switch': case 'star': it[k] = false; break;
      case 'choice': it[k] = Array.isArray(f.options) ? f.options[0] : Object.keys(f.options || {})[0]; break;
      case 'list': case 'checklist': it[k] = Array.from({ length: f.min || 0 }, () => blankItem(sp)); break;
      case 'icon': it[k] = (f.options || [])[0]; break;
      case 'focus': case 'pdf': case 'pdfv': case 'keep': case 'stars': case 'month': case 'video': break;
      default: if (!f.optional) it[k] = '';
    }
  }
  if (listSp === 'home.marquee.phrases') it.on = true;
  if (listSp === 'home.reviews.items') it.source = S.prefs.reviewSource || 'Google';
  if (listSp === 'states') { it.licensed = false; delete it.pdf; delete it.esign; }
  if (listSp === 'contact.rows') { it.type = 'text'; it.label = ''; it.text = ''; delete it.which; }
  return it;
}

// the kinds of boxes that count as "she filled something in"
const FILLED = new Set(['text', 'para', 'rich', 'name', 'alt', 'link', 'esign', 'phone', 'email', 'state', 'photo', 'pdf', 'stars', 'month']);
/** Nothing typed, picked or uploaded in this item (the review's "where it was posted" is filled in for her). */
export function isBlank(listSp, it) {
  if (!it) return true;
  if (it.pdfV) return false;
  for (const p of itemFields(listSp)) {
    if (p === 'home.reviews.items[].source') continue;
    const f = field(p);
    const v = it[p.slice(listSp.length + 3)];
    if (f.type === 'list' || f.type === 'checklist') { if ((v || []).some((x) => !isBlank(p, x))) return false; continue; }
    if (!FILLED.has(f.type) || v === undefined || v === null || v === 0) continue;
    if (plain(v).trim() !== '') return false;
  }
  return true;
}

export const SIMPLE_LISTS = new Set(['about.bio', 'about.tags', 'claim.damageChoices', 'services.items[].points', 'faq.items[].a', 'services.items[].tags']);
const isOther = (listSp, it) => listSp === 'claim.damageChoices' && /^other$/i.test(plain(it.name).trim());
const visibleOf = (items, f) => items.filter((it) => !it.hidden && (f.type !== 'checklist' || it.on !== false));
const say = (where, text) => { clear(where); where.append(h('p.callout.warn', { role: 'alert', text })); };
const limitText = (f) => `This list holds up to ${f.max}, and hidden ones count too. Delete one first (it can come back from History).`;
const isNewItem = (path, id) => !(getAt(S.live.content, path) || []).some((x) => x._id === id);

/** Hide an item for now, or show it again (a list keeps its minimum showing). False when it can't. */
function toggleHidden(path, id, warn) {
  const sp = schemaPath(path);
  const f = field(sp);
  const list = clone(getAt(S.draft, path));
  const x = list.find((y) => y._id === id);
  if (!x) return false;
  if (!x.hidden && visibleOf(list, f).length <= (f.min || 0)) { warn(`This needs at least ${f.min} showing. Add another one first.`); return false; }
  if (x.hidden) delete x.hidden; else x.hidden = true;
  setAt(S.draft, path, list);
  changed();
  emit('list', sp);
  if (x.hidden && sp === 'faq.items' && x.home) toast('That question was on Home. Pick another one for Home, so it shows 3.', 'warn', 6000);
  else if (x.hidden) toast('Hidden. It goes away from the website when you put it live.');
  return true;
}

/** Delete an item after asking (it comes back from History once it was live). False when not deleted. */
async function deleteItem(path, id, warn) {
  const sp = schemaPath(path);
  const f = field(sp);
  const list = clone(getAt(S.draft, path));
  const it = list.find((y) => y._id === id);
  if (!it) return false;
  if (!it.hidden && visibleOf(list, f).length <= (f.min || 0)) { warn(`This needs at least ${f.min} showing. Add another one first.`); return false; }
  const ok = await sheet(`Delete “${itemTitle(it, sp)}”?`, isNewItem(path, id) ? 'It isn\'t live yet, so it just goes away.' : 'It stays on the website until you put your changes live. You can bring it back from History.', [{ label: 'Delete', value: true, kind: 'danger' }, { label: 'Keep it', value: false, kind: 'ghost' }]);
  if (!ok) return false;
  setAt(S.draft, path, list.filter((y) => y._id !== id));
  changed();
  emit('list', sp);
  return true;
}

/** Show a question on Home. Home shows exactly 3, so a 4th asks which one it replaces. True when done. */
export async function starFaq(path, id, warn) {
  const sp = schemaPath(path);
  const list = clone(getAt(S.draft, path));
  const x = list.find((y) => y._id === id);
  if (!x) return false;
  const starred = list.filter((y) => y.home && !y.hidden);
  if (x.home) { warn('Home shows exactly 3 questions. Pick another one for Home first; you\'ll be asked which one it replaces.'); return false; }
  if (starred.length >= 3) {
    const pick = await sheet('Home shows 3 questions', `Swap “${itemTitle(x, sp)}” in for which one?`, starred.map((y) => ({ label: itemTitle(y, sp), value: y._id })).concat([{ label: 'Cancel', value: null, kind: 'ghost' }]));
    if (!pick) return false;
    list.find((y) => y._id === pick).home = false;
  }
  x.home = true;
  if (x.hidden) delete x.hidden;
  setAt(S.draft, path, list);
  changed();
  emit('list', sp);
  return true;
}

/** The list editor for the list at `path` (a change path). */
export function listEditor(path, opts = {}) {
  const sp = schemaPath(path);
  const f = field(sp);
  const wrap = h('section.list-box', { dataset: { key: keyOf(path) } });
  const own = !SIMPLE_LISTS.has(sp) && f.type !== 'checklist'; // items open their own screen
  let reordering = false;
  const draw = (focus) => {
    const items = getAt(S.draft, path) || [];
    clear(wrap);
    if (items.length < 2) reordering = false;
    wrap.classList.toggle('reordering', reordering);
    const shown = visibleOf(items, f);
    const head = h('div.list-head', h('h3', { text: opts.label || (opts.short ? splitLabel(f.label)[1] : f.label) }),
      h('span.small', { text: `${shown.length} showing` + (items.length > shown.length ? `, ${items.length - shown.length} hidden` : '') }),
      own && items.length >= 2 ? h('button.btn.small', { type: 'button', 'aria-pressed': String(reordering), text: reordering ? 'Done' : 'Change the order', onclick: () => { reordering = !reordering; draw('.list-head .btn'); } }) : null);
    wrap.append(head);
    if (f.rule) wrap.append(h('p.help', { text: f.rule === 'exactly 3 shown on Home' ? 'Star exactly 3 to show on Home.' : f.rule }));
    const ul = h('ol.list-items');
    items.forEach((it, i) => ul.append(row(it, i, items)));
    wrap.append(ul);
    const msg = h('div');
    const addBtn = h('button.btn.add', { type: 'button', onclick: () => add(msg) }, icon('plus'), f.add || 'Add');
    if (f.type === 'checklist') wrap.append(phraseAdder(msg));
    else if (!reordering) wrap.append(addBtn);
    wrap.append(msg);
    if (focus) { const el = wrap.querySelector(focus); if (el) el.focus(); }
  };

  function row(it, i, items) {
    const ipath = path.concat([{ id: it._id }]);
    const isNew = isNewItem(path, it._id);
    const was = (getAt(S.live.content, path) || []).find((x) => x._id === it._id);
    const changedItem = !isNew && JSON.stringify(was) !== JSON.stringify(it);
    const pinned = isOther(sp, it);
    const li = h('li.list-item' + (it.hidden ? '.is-hidden' : ''), { dataset: { key: keyOf(ipath) } });
    const msg = h('div');
    const rowSel = `[data-key="${CSS.escape(keyOf(ipath))}"]`;
    const upDown = () => [
      h('button.btn.small', { type: 'button', 'aria-label': 'Move up', dataset: { mv: 'up' }, text: 'Up', onclick: () => move(i, -1, msg) }),
      h('button.btn.small', { type: 'button', 'aria-label': 'Move down', dataset: { mv: 'down' }, text: 'Down', onclick: () => move(i, 1, msg) }),
    ];
    const badges = h('span.badges', isNew ? h('span.pill.new', { text: 'New' }) : changedItem ? h('span.pill.changed', { text: 'Changed' }) : null, it.hidden ? h('span.pill', { text: 'Hidden' }) : null);
    if (!own) {
      // short lists: labelled buttons on each row
      const ctrls = h('div.item-ctrls',
        pinned ? h('span.small', { text: 'Stays last' }) : upDown(),
        f.type !== 'checklist' ? h('button.btn.small', { type: 'button', 'aria-label': it.hidden ? 'Show again' : 'Hide for now', text: it.hidden ? 'Show' : 'Hide', onclick: () => { if (toggleHidden(path, it._id, (t) => say(msg, t))) draw(`${rowSel} .item-ctrls .btn:not(.danger):not([data-mv])`); } }) : null,
        pinned ? null : h('button.btn.small.danger', { type: 'button', 'aria-label': 'Delete', text: 'Delete', onclick: async () => { if (await deleteItem(path, it._id, (t) => say(msg, t))) draw(); } }));
      if (f.type === 'checklist') {
        const onKey = itemFields(sp).some((p) => p.endsWith('[].on')) ? 'on' : null;
        const ticked = onKey ? it.on !== false : !it.hidden;
        const cb = h('input', { type: 'checkbox', checked: ticked, 'aria-label': 'Show ' + plain(it.text) });
        cb.addEventListener('change', () => {
          const list = clone(getAt(S.draft, path));
          const x = list.find((y) => y._id === it._id);
          if (!cb.checked && visibleOf(list, f).length <= (f.min || 0)) { cb.checked = true; say(msg, `Keep at least ${f.min} ticked.`); return; }
          if (onKey) x.on = cb.checked; else if (cb.checked) delete x.hidden; else x.hidden = true;
          setItems(list);
        });
        li.append(h('label.check-row', cb, h('span.caps', { text: plain(it.text) })), h('div.item-bar', badges, ctrls), msg);
        return li;
      }
      const fs = itemFields(sp).filter((p) => !['focus', 'keep', 'pdfv'].includes(field(p).type));
      li.append(h('div.simple-item', fs.map((p) => fieldBox(ipath.concat([p.slice(sp.length + 3)]), { label: field(p).type === 'tick' ? field(p).label : `${i + 1}.` }))), h('div.item-bar', badges, ctrls), msg);
      return li;
    }
    // items with their own screen: a calm row to tap; Up and Down only while changing the order
    const thumb = it.photo ? h('img.item-thumb', { src: '/' + it.photo, alt: '', loading: 'lazy' }) : null;
    const star = sp === 'faq.items' ? h('button.star-btn', { type: 'button', 'aria-pressed': String(!!it.home), 'aria-label': it.home ? 'Shown on Home' : 'Show on Home', title: 'Show on Home', onclick: async () => { if (await starFaq(path, it._id, (t) => say(msg, t))) draw(`${rowSel} .star-btn`); } }, icon('star')) : null;
    li.append(h('div.item-main', star, thumb,
      h('button.item-open', { type: 'button', onclick: () => go(`#/item/${encodeURIComponent(keyOf(path))}/${it._id}`) },
        h('span.item-title', { text: itemTitle(it, sp) }), h('span.small', { text: summaryOf(it, sp) }), icon('chev'))));
    const bar = h('div.item-bar', badges.firstChild ? badges : null, reordering ? h('div.reorder-ctrls', upDown()) : null);
    if (bar.firstChild) li.append(bar);
    li.append(msg);
    return li;
  }

  const setItems = (items) => { setAt(S.draft, path, items); changed(); draw(); emit('list', sp); };
  function move(i, d, msg) {
    const list = clone(getAt(S.draft, path));
    const j = i + d;
    if (j < 0) { say(msg, 'Already at the top.'); return; }
    if (j >= list.length) { say(msg, 'Already at the bottom.'); return; }
    if (isOther(sp, list[j])) { say(msg, 'The "Other" choice stays last.'); return; }
    [list[i], list[j]] = [list[j], list[i]];
    setAt(S.draft, path, list);
    changed();
    emit('list', sp);
    // the same button on the moved row, so tapping again keeps moving it
    draw(`[data-key="${CSS.escape(keyOf(path.concat([{ id: list[j]._id }])))}"] [data-mv="${d < 0 ? 'up' : 'down'}"]`);
  }
  function add(msg) {
    const list = clone(getAt(S.draft, path) || []);
    if (list.length >= f.max) { say(msg, limitText(f)); return null; }
    const it = blankItem(sp);
    const other = list.findIndex((y) => isOther(sp, y));
    if (other >= 0) list.splice(other, 0, it); else list.push(it);
    setItems(list);
    if (SIMPLE_LISTS.has(sp)) {
      requestAnimationFrame(() => { const el = wrap.querySelector(`[data-key="${CSS.escape(keyOf(path.concat([{ id: it._id }])))}"] .input`); if (el) el.focus(); });
    } else {
      go(`#/item/${encodeURIComponent(keyOf(path))}/${it._id}`);
    }
    return it;
  }
  function phraseAdder(msg) {
    const max = f.hard || 36;
    const inp = h('input.input.caps', { ...kb('caps'), placeholder: 'NEW PHRASE', enterkeyhint: 'done' });
    const count = h('span.small');
    const len = () => [...inp.value.replace(/\s+/g, ' ').trim()].length;
    const sync = () => {
      const n = len();
      count.className = n > max ? 'bad-text' : 'small';
      count.textContent = n > max ? `Too long: ${n - max} letters over. Shorten it to fit.` : `${n} of ${max} letters`;
    };
    const go2 = () => {
      const t = inp.value.replace(/\s+/g, ' ').trim().toUpperCase();
      if (!t) { inp.focus(); return; }
      if (len() > max) { say(msg, `Too long: ${len() - max} letters over. Shorten it to fit.`); return; }
      const list = clone(getAt(S.draft, path) || []);
      if (list.length >= f.max) { say(msg, limitText(f)); return; }
      if (list.some((y) => plain(y.text).toUpperCase() === t)) { say(msg, 'That one is already in the list.'); return; }
      const it = { _id: newId(), text: t };
      if (itemFields(sp).some((p) => p.endsWith('[].on'))) it.on = true;
      list.push(it);
      inp.value = '';
      setItems(list);
    };
    inp.addEventListener('input', sync);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go2(); } });
    sync();
    return h('div.phrase-add', h('label.small', { text: (f.add || 'Add') + ' (capitals are automatic)' }), h('div.row', inp, h('button.btn', { type: 'button', text: 'Add', onclick: go2 })), count);
  }
  draw();
  wrap.addItem = () => add(wrap.lastChild);
  return wrap;
}

function summaryOf(it, sp) {
  switch (sp) {
    case 'states': return [it.esign ? 'E-sign link' : 'Call to sign', it.pdf || it.pdfV ? 'Contract PDF' : 'No PDF', it.licensed ? 'Licensed ✓' : 'Not marked licensed yet'].join(' · ');
    case 'home.reviews.items': return [it.stars ? '★'.repeat(it.stars) : '', it.source || 'Google', it.real ? 'Real customer ✓' : 'Not marked "real customer" yet'].filter(Boolean).join(' · ');
    case 'faq.items': return cut(plain(((it.a || [])[0] || {}).text), 70);
    case 'contact.rows': return it.type === 'phone' ? 'A phone number' : it.type === 'email' ? 'The email' : it.type === 'states' ? 'Automatic from your states' : cut(plain(it.text), 60);
    case 'services.items': return cut(plain(it.summary), 70);
    case 'home.damage.cards': return (it.tag ? plain(it.tag) + ' · ' : '') + cut(plain(it.text), 60);
    default: return cut(plain(it.text || it.intro || ''), 70);
  }
}

// a review needs only its words, the name and the tick; the rest waits in "More about the review"
const REVIEW_MORE = new Set(['stars', 'source', 'town', 'state', 'claimType', 'month', 'trimmed']);

/** The screen for one item of a list (#/item/<list key>/<id>). */
export function itemScreen(listKey, id, parsePath) {
  const path = parsePath(listKey);
  if (!path) return null;
  const sp = schemaPath(path);
  const f = field(sp);
  if (!f) return null;
  const ipath = path.concat([{ id }]);
  const it = getAt(S.draft, ipath);
  if (!it) return null;
  const noun = nounOf(sp);
  const home = screenFor(path);
  const body = h('div.stack');
  const fs = itemFields(sp).filter((p) => !['focus', 'keep', 'pdfv'].includes(field(p).type));
  const isNew = isNewItem(path, id);
  if (sp === 'faq.items') body.append(homeSwitch(path, id, it));
  let more = null;
  let homeHead = false;
  for (const p of fs) {
    const k = p.slice(sp.length + 3);
    const fp = ipath.concat([k]);
    if (sp === 'services.items' && k === 'id') {
      // the link address is made once from the name
      if (isNew && !it.id) body.append(h('p.help', { text: 'The link address is made from the name when you first type it.' }));
      continue;
    }
    if (sp === 'contact.rows' && k === 'which' && it.type !== 'phone') continue;
    if (sp === 'contact.rows' && k === 'text' && it.type !== 'text') continue;
    if (sp === 'faq.items' && k === 'home') continue;
    if (sp === 'faq.items' && (k === 'homeQ' || k === 'homeA')) {
      if (!it.home) continue;
      if (!homeHead) { body.append(h('h3', { text: 'How it reads on Home (optional)' })); homeHead = true; }
    }
    let target = body;
    if (sp === 'home.reviews.items' && REVIEW_MORE.has(k)) {
      if (!more) {
        more = h('details.section', h('summary', h('span.section-text', h('span.section-name', { text: 'More about the review (optional)' }))));
        more.dataset.sec = 'More about the review';
        body.append(more);
      }
      target = more;
    }
    if (isList(p)) { target.append(listEditor(fp, { short: true })); continue; }
    const box = fieldBox(fp, { short: true });
    if (box) target.append(box);
  }
  if (sp === 'services.items' && isNew) {
    body.addEventListener('input', () => {
      const cur = getAt(S.draft, ipath);
      if (!cur || (getAt(S.live.content, path) || []).some((x) => x._id === id)) return;
      const slug = plain(cur.name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 30) || 'service';
      const taken = (getAt(S.draft, path) || []).filter((x) => x._id !== id).map((x) => x.id);
      let s = slug; let n = 2;
      while (taken.includes(s)) s = `${slug}-${n++}`;
      if (cur.id !== s) { setAt(S.draft, ipath.concat(['id']), s); changed(); }
    });
  }
  // Hide for now and Delete live here, away from the list's rows
  const actMsg = h('div');
  const hideBtn = h('button.btn', { type: 'button', text: it.hidden ? 'Show again' : 'Hide for now', onclick: () => {
    if (!toggleHidden(path, id, (t) => say(actMsg, t))) return;
    clear(actMsg);
    hideBtn.textContent = (getAt(S.draft, ipath) || {}).hidden ? 'Show again' : 'Hide for now';
  } });
  const delBtn = h('button.btn.danger', { type: 'button', text: `Delete this ${noun}`, onclick: async () => { if (await deleteItem(path, id, (t) => say(actMsg, t))) back(home); } });
  const screenBody = h('div.stack',
    h('div.row', h('p.crumb', { text: f.label }), isNew ? h('span.pill.new', { text: 'New · not on the website yet' }) : null),
    body,
    h('div.row.end', h('button.btn.primary', { type: 'button', text: 'Done', onclick: () => back(home) })),
    h('div.item-actions', hideBtn, delBtn),
    actMsg);
  // the title follows what she types
  on('change', () => {
    if (!screenBody.isConnected) return;
    const t = document.querySelector('.bar h1');
    const cur = getAt(S.draft, ipath);
    if (t && cur) t.textContent = itemTitle(cur, sp) || 'Item';
  });
  return {
    redrawOnChoice: sp === 'contact.rows' || sp === 'site.social' ? keyOf(ipath) : null,
    title: itemTitle(it, sp) || 'Item',
    back: home,
    body: screenBody,
    // a new item she left without typing anything doesn't stay behind as an empty one
    leave: () => {
      if (location.hash.includes(id)) return;
      const cur = getAt(S.draft, ipath);
      if (!cur || !isNewItem(path, id) || !isBlank(sp, cur)) return;
      setAt(S.draft, path, (getAt(S.draft, path) || []).filter((x) => x._id !== id));
      changed();
      toast(`Nothing was typed, so the empty ${noun} was left out.`);
    },
  };
}

/** FAQ: "Show on Home" at the top of a question (the same rules as the star in the list). */
function homeSwitch(path, id, it) {
  const shown = !!it.home;
  const note = h('div');
  const sw = h('button.switch', { type: 'button', role: 'switch', 'aria-checked': String(shown), 'aria-label': 'Show on Home' }, h('span.knob'), h('span.switch-text', { text: shown ? 'On' : 'Off' }));
  sw.addEventListener('click', async () => { if (await starFaq(path, id, (t) => say(note, t))) render(); });
  return h('section.field-box', h('div.field-head', h('span.field-label', { text: 'Show on Home' })), h('p.help', { text: 'Home shows 3 of the questions.' }), sw, note);
}
