// One shared list editor for every list: + Add, Edit, Move up / Move down, Hide for now and
// Delete. Limits explain themselves in plain words ("This spot holds up to 12. Hide or delete
// one first.") instead of greyed-out buttons. Deleted items come back from History.
import { S, h, icon, clear, getAt, setAt, keyOf, schemaPath, field, itemFields, changed, plain, go, sheet, toast, newId, clone, isList, splitLabel, emit } from './core.js';
import { fieldBox, shortName, kb } from './fields.js';

/** A short name for an item ("How long do I have…", "Kentucky", "Michael R."). */
export function itemTitle(item, listSp) {
  if (!item) return '';
  if (item.code !== undefined) return S.boot.states[item.code] || 'New state (pick one)';
  if (listSp === 'home.reviews.items') return (item.name ? shortName(item.name) : 'New review') + (item.text ? ': “' + cut(item.text, 50) + '”' : '');
  if (listSp === 'site.social') return (item.platform || 'Link') + (item.url ? ' · ' + item.url.replace(/^https:\/\/(www\.)?/, '') : '');
  for (const k of ['q', 'name', 'title', 'label', 'text', 'tag']) {
    if (item[k] !== undefined && item[k] !== '') { const t = plain(item[k]); if (t) return cut(t, 64); }
  }
  if (item.a && item.a[0]) return cut(plain(item.a[0].text), 64);
  return 'New item';
}
export function cut(s, n) { s = String(s || '').replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s; }
function kindOf(f) { return (f.add || 'Add an item').replace(/^Add\s*/i, '') || 'item'; }

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

export const SIMPLE_LISTS = new Set(['about.bio', 'about.tags', 'claim.damageChoices', 'services.items[].points', 'faq.items[].a', 'services.items[].tags']);
const isOther = (listSp, it) => listSp === 'claim.damageChoices' && /^other$/i.test(plain(it.name).trim());
const visibleOf = (items, f) => items.filter((it) => !it.hidden && (f.type !== 'checklist' || it.on !== false));

/** The list editor for the list at `path` (a change path). */
export function listEditor(path, opts = {}) {
  const sp = schemaPath(path);
  const f = field(sp);
  const wrap = h('section.list-box', { dataset: { key: keyOf(path) } });
  const draw = () => {
    const items = getAt(S.draft, path) || [];
    clear(wrap);
    const shown = visibleOf(items, f);
    const head = h('div.list-head', h('h3', { text: opts.label || (opts.short ? splitLabel(f.label)[1] : f.label) }),
      h('span.small', { text: `${shown.length} showing` + (items.length > shown.length ? `, ${items.length - shown.length} hidden` : '') }));
    wrap.append(head);
    if (f.rule) wrap.append(h('p.help', { text: f.rule === 'exactly 3 shown on Home' ? 'Star exactly 3 to show on Home.' : f.rule }));
    const ul = h('ol.list-items');
    items.forEach((it, i) => ul.append(row(it, i, items)));
    wrap.append(ul);
    const msg = h('div');
    const addBtn = h('button.btn.add', { type: 'button', onclick: () => add(msg) }, icon('plus'), f.add || 'Add');
    if (f.type === 'checklist') wrap.append(phraseAdder(msg));
    else wrap.append(addBtn);
    wrap.append(msg);
  };
  const setItems = (items) => { setAt(S.draft, path, items); changed(); draw(); emit('list', sp); };
  const say = (where, text) => { clear(where); where.append(h('p.callout.warn', { role: 'alert', text })); };

  function row(it, i, items) {
    const ipath = path.concat([{ id: it._id }]);
    const isNew = !(getAt(S.live.content, path) || []).some((x) => x._id === it._id);
    const was = (getAt(S.live.content, path) || []).find((x) => x._id === it._id);
    const changedItem = !isNew && JSON.stringify(was) !== JSON.stringify(it);
    const pinned = isOther(sp, it);
    const li = h('li.list-item' + (it.hidden ? '.is-hidden' : ''), { dataset: { key: keyOf(ipath) } });
    const msg = h('div');
    const ctrls = h('div.item-ctrls',
      pinned ? h('span.small', { text: 'Stays last' }) : [
        h('button.icon-btn', { type: 'button', 'aria-label': 'Move up', disabled: i === 0, onclick: () => move(i, -1) }, icon('up')),
        h('button.icon-btn', { type: 'button', 'aria-label': 'Move down', disabled: i === items.length - 1 || isOther(sp, items[i + 1] || {}), onclick: () => move(i, 1) }, icon('down')),
      ],
      f.type !== 'checklist' ? h('button.icon-btn', { type: 'button', 'aria-label': it.hidden ? 'Show again' : 'Hide for now', title: it.hidden ? 'Show again' : 'Hide for now', onclick: () => hide(it, msg) }, icon(it.hidden ? 'eyeoff' : 'eye')) : null,
      pinned ? null : h('button.icon-btn.danger', { type: 'button', 'aria-label': 'Delete', onclick: () => del(it, msg) }, icon('trash')));
    const badges = h('span.badges', isNew ? h('span.pill.new', { text: 'New' }) : changedItem ? h('span.pill.changed', { text: 'Changed' }) : null, it.hidden ? h('span.pill', { text: 'Hidden' }) : null);
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
      li.append(h('label.check-row', cb, h('span.caps', { text: plain(it.text) })), badges, ctrls, msg);
      return li;
    }
    if (SIMPLE_LISTS.has(sp)) {
      const fs = itemFields(sp).filter((p) => !['focus', 'keep', 'pdfv'].includes(field(p).type));
      li.append(h('div.simple-item', fs.map((p) => fieldBox(ipath.concat([p.slice(sp.length + 3)]), { label: field(p).type === 'tick' ? field(p).label : `${i + 1}.` }))), h('div.item-bar', badges, ctrls), msg);
      return li;
    }
    const thumb = it.photo ? h('img.item-thumb', { src: '/' + it.photo, alt: '', loading: 'lazy' }) : null;
    const star = sp === 'faq.items' ? h('button.star-btn', { type: 'button', 'aria-pressed': String(!!it.home), 'aria-label': it.home ? 'Shown on Home' : 'Show on Home', title: 'Show on Home', onclick: () => starFaq(it, msg) }, icon('star')) : null;
    li.append(h('div.item-main', star, thumb,
      h('button.item-open', { type: 'button', onclick: () => go(`#/item/${encodeURIComponent(keyOf(path))}/${it._id}`) },
        h('span.item-title', { text: itemTitle(it, sp) }), h('span.small', { text: summaryOf(it, sp) }), icon('chev'))),
      h('div.item-bar', badges, ctrls), msg);
    return li;
  }

  function move(i, d) {
    const list = clone(getAt(S.draft, path));
    const j = i + d;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    setItems(list);
  }
  function hide(it, msg) {
    const list = clone(getAt(S.draft, path));
    const x = list.find((y) => y._id === it._id);
    if (!x.hidden && visibleOf(list, f).length <= (f.min || 0)) { say(msg, `This needs at least ${f.min} showing. Add another one first.`); return; }
    if (x.hidden) delete x.hidden; else x.hidden = true;
    setItems(list);
    if (x.hidden && sp === 'faq.items' && x.home) toast('That question was on Home. Star another one so Home shows 3.', 'warn', 6000);
  }
  async function del(it, msg) {
    const list = clone(getAt(S.draft, path));
    if (!it.hidden && visibleOf(list, f).length <= (f.min || 0)) { say(msg, `This needs at least ${f.min} showing. Add another one first.`); return; }
    const isNew = !(getAt(S.live.content, path) || []).some((x) => x._id === it._id);
    const ok = await sheet(`Delete “${itemTitle(it, sp)}”?`, isNew ? 'It isn\'t live yet, so it just goes away.' : 'It stays on the website until you put your changes live. You can bring it back from History.', [{ label: 'Delete', value: true, kind: 'danger' }, { label: 'Keep it', value: false, kind: 'ghost' }]);
    if (!ok) return;
    setItems(list.filter((y) => y._id !== it._id));
  }
  function add(msg) {
    const list = clone(getAt(S.draft, path) || []);
    if (list.length >= f.max) { say(msg, `This spot holds up to ${f.max}. Hide or delete one first.`); return null; }
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
  async function starFaq(it, msg) {
    const list = clone(getAt(S.draft, path));
    const x = list.find((y) => y._id === it._id);
    const starred = list.filter((y) => y.home && !y.hidden);
    if (x.home) { say(msg, 'Home shows exactly 3 questions. Star another one first; you\'ll be asked which one it replaces.'); return; }
    if (starred.length >= 3) {
      const pick = await sheet('Home shows 3 questions', `Swap “${itemTitle(x, sp)}” in for which one?`, starred.map((y) => ({ label: itemTitle(y, sp), value: y._id })).concat([{ label: 'Cancel', value: null, kind: 'ghost' }]));
      if (!pick) return;
      list.find((y) => y._id === pick).home = false;
    }
    x.home = true;
    if (x.hidden) delete x.hidden;
    setItems(list);
  }
  function phraseAdder(msg) {
    const max = f.hard || 36;
    const inp = h('input.input.caps', { ...kb('caps'), maxLength: max, placeholder: 'NEW PHRASE', enterkeyhint: 'done' });
    const go2 = () => {
      const t = inp.value.replace(/\s+/g, ' ').trim().toUpperCase();
      if (!t) { inp.focus(); return; }
      const list = clone(getAt(S.draft, path) || []);
      if (list.length >= f.max) { say(msg, `This holds up to ${f.max}. Delete one first.`); return; }
      if (list.some((y) => plain(y.text).toUpperCase() === t)) { say(msg, 'That one is already in the list.'); return; }
      const it = { _id: newId(), text: t };
      if (itemFields(sp).some((p) => p.endsWith('[].on'))) it.on = true;
      list.push(it);
      inp.value = '';
      setItems(list);
    };
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go2(); } });
    return h('div.phrase-add', h('label.small', { text: (f.add || 'Add') + ` (up to ${max} letters, capitals are automatic)` }), h('div.row', inp, h('button.btn', { type: 'button', text: 'Add', onclick: go2 })));
  }
  draw();
  wrap.addItem = () => add(wrap.lastChild);
  return wrap;
}

function summaryOf(it, sp) {
  switch (sp) {
    case 'states': return [it.esign ? 'E-sign link' : 'Call to sign', it.pdf || it.pdfV ? 'Contract PDF' : 'No PDF', it.licensed ? 'Licensed ✓' : 'Needs the licensed tick'].join(' · ');
    case 'home.reviews.items': return [it.stars ? '★'.repeat(it.stars) : '', it.source || 'Google', it.real ? 'Real customer ✓' : 'Needs the "real customer" tick'].filter(Boolean).join(' · ');
    case 'faq.items': return cut(plain(((it.a || [])[0] || {}).text), 70);
    case 'contact.rows': return it.type === 'phone' ? 'A phone number' : it.type === 'email' ? 'The email' : it.type === 'states' ? 'Automatic from your states' : cut(plain(it.text), 60);
    case 'services.items': return cut(plain(it.summary), 70);
    case 'home.damage.cards': return (it.tag ? plain(it.tag) + ' · ' : '') + cut(plain(it.text), 60);
    default: return cut(plain(it.text || it.intro || ''), 70);
  }
}

/** The screen for one item of a list (#/item/<list key>/<id>). */
export function itemScreen(listKey, id, parsePath) {
  const path = parsePath(listKey);
  const sp = schemaPath(path);
  const f = field(sp);
  const ipath = path.concat([{ id }]);
  const it = getAt(S.draft, ipath);
  if (!it) return null;
  const body = h('div.stack');
  const fs = itemFields(sp).filter((p) => !['focus', 'keep', 'pdfv'].includes(field(p).type));
  const isNew = !(getAt(S.live.content, path) || []).some((x) => x._id === id);
  for (const p of fs) {
    const k = p.slice(sp.length + 3);
    const fp = ipath.concat([k]);
    const ff = field(p);
    if (sp === 'services.items' && k === 'id') {
      // the link address is made once from the name
      if (isNew && !it.id) body.append(h('p.help', { text: 'The link address is made from the name when you first type it.' }));
      continue;
    }
    if (sp === 'contact.rows' && k === 'which' && it.type !== 'phone') continue;
    if (sp === 'contact.rows' && k === 'text' && it.type !== 'text') continue;
    if (sp === 'faq.items' && k === 'home') continue;
    if (isList(p)) { body.append(listEditor(fp, { short: true })); continue; }
    const box = fieldBox(fp, { short: true });
    if (box) body.append(box);
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
  return {
    redrawOnChoice: sp === 'contact.rows' ? keyOf(ipath) : null,
    title: itemTitle(it, sp) || 'Item',
    back: '#/list/' + encodeURIComponent(listKey),
    body: h('div.stack', h('p.crumb', { text: f.label }), body,
      h('div.row.end', h('button.btn.primary', { type: 'button', text: 'Done', onclick: () => history.back() }))),
  };
}
