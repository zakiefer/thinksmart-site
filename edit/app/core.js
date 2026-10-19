// The editor's core: state, talking to the server, change operations (the same rules as
// edit/lib/Changes.php), saving drafts (to the server every few seconds and to this phone in
// case the connection drops), small DOM helpers and the screen router.

export const S = {
  boot: null, schema: null, me: null, names: {}, csrf: '',
  live: null,            // {rev, content, at, by}
  draft: null,           // the content as she's editing it
  base: 0,               // the live version the draft started from
  lists: {},             // schema paths of stored lists
  conflicts: [],         // spots someone else changed meanwhile
  resolve: {},           // key -> 'mine' | 'theirs'
  ver: 0, saveState: 'saved', savedAt: 0, offline: false,
  words: new Set(),
  prefs: {},
};

// ------------------------------------------------------------------ events

const handlers = {};
export function on(evt, fn) { (handlers[evt] = handlers[evt] || []).push(fn); }
export function emit(evt, arg) { (handlers[evt] || []).forEach((fn) => { try { fn(arg); } catch (e) { console.error(e); } }); }

// ------------------------------------------------------------------ DOM

const PROPS = new Set(['value', 'checked', 'disabled', 'hidden', 'selected', 'readOnly', 'maxLength', 'minLength', 'tabIndex', 'htmlFor', 'contentEditable', 'open', 'draggable', 'rows', 'step', 'multiple']);

/** h('button.btn.primary', {onclick, text}, ...children). Never parses HTML. */
export function h(sel, attrs, ...kids) {
  const m = /^([a-z0-9-]+)?((?:[.#][\w-]+)*)$/i.exec(sel) || [];
  const el = document.createElement(m[1] || 'div');
  (m[2] || '').replace(/([.#])([\w-]+)/g, (_, k, v) => { if (k === '.') el.classList.add(v); else el.id = v; return ''; });
  if (attrs && (typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs))) { kids.unshift(attrs); attrs = null; }
  for (const [k, v] of Object.entries(attrs || {})) {
    if (k === 'spellcheck') { el.setAttribute('spellcheck', v ? 'true' : 'false'); continue; }
    if (v === undefined || v === null || v === false) continue;
    if (k === 'text') el.textContent = v;
    else if (k === 'class') String(v).split(/\s+/).filter(Boolean).forEach((c) => el.classList.add(c));
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (PROPS.has(k)) el[k] = v;
    // everything else as an attribute: Safari has an "autocorrect" property that turns "off" into on
    else el.setAttribute(k, v === true ? (k === 'spellcheck' ? 'true' : '') : String(v));
  }
  add(el, kids);
  return el;
}
function add(el, kids) {
  for (const k of kids.flat(Infinity)) {
    if (k === null || k === undefined || k === false) continue;
    el.appendChild(k instanceof Node ? k : document.createTextNode(String(k)));
  }
}
export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }

const ICONS = {
  back: 'M15 18l-6-6 6-6', chev: 'M9 6l6 6-6 6', plus: 'M12 5v14M5 12h14', up: 'M6 15l6-6 6 6', down: 'M6 9l6 6 6-6',
  eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12zM12 15a3 3 0 100-6 3 3 0 000 6z',
  eyeoff: 'M3 3l18 18M10.6 5.1A10.8 10.8 0 0112 5c6.4 0 10 7 10 7a17.6 17.6 0 01-3.2 4.1M6.6 6.6A17.7 17.7 0 002 12s3.6 7 10 7a9.9 9.9 0 005.4-1.6M9.9 9.9a3 3 0 004.2 4.2',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3', edit: 'M4 20h4L19 9l-4-4L4 16v4z',
  storm: 'M7 18a4 4 0 01-.6-8A6 6 0 0118 9a4 4 0 01-1 8M12 13l-2 4h4l-2 4',
  word: 'M4 6h16M4 12h10M4 18h7M17 15l2 2 4-4', undo: 'M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3',
  help: 'M12 21a9 9 0 100-18 9 9 0 000 18zM9.5 9a2.5 2.5 0 115 .5c0 1.5-2.5 2-2.5 3.5M12 17h.01',
  phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2',
  map: 'M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15', star: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z',
  chat: 'M4 5h16v11H8l-4 4z', photo: 'M4 6h16v12H4zM8 14l3-3 3 3 2-2 2 2M9 9.5h.01',
  tag: 'M3 12l9-9h8v8l-9 9zM16 8h.01', sun: 'M12 17a5 5 0 100-10 5 5 0 000 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
  pages: 'M6 3h9l4 4v14H6zM14 3v5h5', more: 'M5 12h.01M12 12h.01M19 12h.01', search: 'M11 18a7 7 0 100-14 7 7 0 000 14zM21 21l-5-5',
  check: 'M5 12l5 5 9-10', x: 'M6 6l12 12M18 6L6 18', clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 2', pdf: 'M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6',
  people: 'M9 11a4 4 0 100-8 4 4 0 000 8zM2 21a7 7 0 0114 0M17 11a3 3 0 100-6M22 21a6 6 0 00-5-6', video: 'M3 6h12v12H3zM15 10l6-3v10l-6-3',
  warn: 'M12 3l10 18H2zM12 10v5M12 18h.01', globe: 'M12 21a9 9 0 100-18 9 9 0 000 18zM3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18',
};
export function icon(name, cls = '') {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('class', 'ic ' + cls);
  const p = document.createElementNS(NS, 'path');
  p.setAttribute('d', ICONS[name] || ICONS.more);
  svg.appendChild(p);
  return svg;
}

export function toast(msg, kind = 'ok', ms = 3200) {
  const t = h('div.toast.' + kind, { role: 'status', text: msg });
  document.body.appendChild(t);
  requestAnimationFrame(() => t.classList.add('in'));
  setTimeout(() => { t.classList.remove('in'); setTimeout(() => t.remove(), 300); }, ms);
}

/** A bottom sheet with a message and buttons; resolves with the chosen button's value. */
export function sheet(title, body, buttons) {
  return new Promise((resolve) => {
    const back = h('div.sheet-back');
    const opened = Date.now();
    let vv = null;
    // above the iPhone keyboard: the sheet follows the part of the screen she can see
    const fit = () => {
      back.style.top = vv.offsetTop + 'px';
      back.style.height = vv.height + 'px';
      back.style.bottom = 'auto';
      box.style.maxHeight = Math.max(160, vv.height - 12) + 'px';
    };
    const done = (v) => {
      back.classList.remove('in');
      setTimeout(() => back.remove(), 200);
      document.removeEventListener('keydown', esc);
      if (vv) { vv.removeEventListener('resize', fit); vv.removeEventListener('scroll', fit); }
      resolve(v);
    };
    const esc = (e) => { if (e.key === 'Escape') done(null); };
    const box = h('div.sheet', { role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
      h('h2', { text: title }),
      typeof body === 'string' ? h('p', { text: body }) : body,
      h('div.sheet-buttons', buttons.map((b) => h('button.btn' + (b.kind ? '.' + b.kind : ''), { type: 'button', text: b.label, onclick: () => done(b.value) }))));
    back.appendChild(box);
    // a double tap on the button that opened it mustn't close it again at once
    back.addEventListener('click', (e) => { if (e.target === back && Date.now() - opened > 450) done(null); });
    document.addEventListener('keydown', esc);
    document.body.appendChild(back);
    if (window.visualViewport && box.querySelector('input, textarea')) {
      vv = window.visualViewport;
      vv.addEventListener('resize', fit);
      vv.addEventListener('scroll', fit);
      fit();
    }
    requestAnimationFrame(() => back.classList.add('in'));
    const first = box.querySelector('button');
    if (first) first.focus({ preventScroll: true });
    box.scrollTop = 0; // a tall sheet opens at its question, not scrolled to its buttons
  });
}

// ------------------------------------------------------------------ server

export class ApiError extends Error {
  constructor(msg, status, data) { super(msg); this.status = status; this.data = data || {}; }
}

export async function api(name, data, opt = {}) {
  const post = data !== undefined && data !== null && !opt.get;
  const init = { method: post ? 'POST' : 'GET', headers: { 'X-TS-CSRF': S.csrf }, credentials: 'same-origin', keepalive: !!opt.keepalive, signal: opt.signal };
  let url = '/edit/api/' + name;
  if (post) {
    // the change goes as a file part: the hosting firewall doesn't inspect it as form text
    const fd = new FormData();
    fd.append('data', new Blob([JSON.stringify(data)], { type: 'application/octet-stream' }), 'data.json');
    for (const [k, f] of Object.entries(opt.files || {})) fd.append(k, f.blob, f.name);
    init.body = fd;
  } else if (data) {
    url += '?' + new URLSearchParams(data).toString();
  }
  let res;
  try {
    res = await fetch(url, init);
  } catch (e) {
    if (e && e.name === 'AbortError') throw e;
    // status 0: the request may or may not have reached the website (putting live checks)
    throw new ApiError('No connection right now. Check your Wi-Fi or signal and try again. Nothing was lost.', 0);
  }
  if (res.status === 401) {
    if (!S.signedOut) { S.signedOut = true; emit('signedout'); }
    throw new ApiError("You're signed out.", 401);
  }
  if (res.status === 413) throw new ApiError('That file is too big for the website. Ask John for a smaller copy.', 413);
  let json = null;
  try { json = await res.json(); } catch (e) { json = null; }
  if (!json) throw new ApiError('The website didn\'t answer properly. Try again in a minute.', res.status);
  if (res.status === 403 && json.reload) { emit('stale'); }
  if (!res.ok && !json.conflicts && !json.problems) throw new ApiError(json.error || 'Something went wrong.', res.status, json);
  return json;
}

// ------------------------------------------------------------------ change operations

export const clone = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));
const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

export function schemaPath(path) {
  let s = '';
  for (const seg of path) s += typeof seg === 'object' ? '[]' : (s === '' ? '' : '.') + seg;
  return s;
}
export function keyOf(path) {
  let s = '';
  for (const seg of path) s += typeof seg === 'object' ? '[' + seg.id + ']' : (s === '' ? '' : '.') + seg;
  return s;
}
export function parseKey(key) {
  const out = [];
  for (const part of key.split('.')) {
    const m = /^([^[]*)((?:\[[a-z0-9]+\])*)$/.exec(part);
    if (!m) return null;
    if (m[1] !== '') out.push(m[1]);
    (m[2].match(/\[([a-z0-9]+)\]/g) || []).forEach((x) => out.push({ id: x.slice(1, -1) }));
  }
  return out;
}

export function getAt(obj, path) {
  let cur = obj;
  for (const seg of path) {
    if (typeof seg === 'object') {
      if (!Array.isArray(cur)) return undefined;
      cur = cur.find((it) => it && it._id === seg.id);
    } else {
      if (cur === null || typeof cur !== 'object' || !(seg in cur)) return undefined;
      cur = cur[seg];
    }
    if (cur === undefined) return undefined;
  }
  return cur;
}

/** Set a value in place (null or undefined removes an optional field). */
export function setAt(obj, path, value) {
  let cur = obj;
  for (let i = 0; i < path.length - 1; i++) {
    const seg = path[i];
    let next;
    if (typeof seg === 'object') next = (cur || []).find((it) => it && it._id === seg.id);
    else { if (!isObj(cur[seg]) && !Array.isArray(cur[seg])) cur[seg] = typeof path[i + 1] === 'object' ? [] : {}; next = cur[seg]; }
    if (next === undefined) return false;
    cur = next;
  }
  const last = path[path.length - 1];
  if (typeof last === 'object') {
    const i = cur.findIndex((it) => it && it._id === last.id);
    if (i >= 0) cur[i] = value;
  } else if (value === undefined || value === null) delete cur[last];
  else cur[last] = value;
  return true;
}

export function newId() {
  const a = new Uint8Array(6);
  crypto.getRandomValues(a);
  return Array.from(a, (b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36]).join('');
}

/** Operations that turn `from` into `to` (Changes::diff). */
export function diff(from, to, path = []) {
  const sp = schemaPath(path);
  if (S.lists[sp] === 'lines' && Array.isArray(from) && Array.isArray(to) && from.length === to.length) {
    const ops = [];
    to.forEach((v, i) => diff(from[i], v, path.concat([String(i)])).forEach((o) => ops.push(o)));
    return ops;
  }
  if (S.lists[sp] === true && Array.isArray(from) && Array.isArray(to)) {
    const ops = [];
    const fromIds = from.map((it) => it && it._id);
    const toIds = to.map((it) => it && it._id);
    if (!same(fromIds, toIds)) {
      const addMap = {};
      to.forEach((it) => { if (!fromIds.includes(it._id)) addMap[it._id] = it; });
      ops.push({ op: 'list', path, order: toIds, add: addMap, old: fromIds });
    }
    const byId = {};
    from.forEach((it) => { byId[it._id] = it; });
    to.forEach((it) => { if (byId[it._id]) diff(byId[it._id], it, path.concat([{ id: it._id }])).forEach((o) => ops.push(o)); });
    return ops;
  }
  if (isObj(from) && isObj(to)) {
    const ops = [];
    const keys = Array.from(new Set(Object.keys(from).concat(Object.keys(to))));
    for (const k of keys) {
      if (k === '_id') continue;
      diff(from[k] === undefined ? null : from[k], to[k] === undefined ? null : to[k], path.concat([k])).forEach((o) => ops.push(o));
    }
    return ops;
  }
  const a = from === undefined ? null : from;
  const b = to === undefined ? null : to;
  return same(a, b) ? [] : [{ op: 'set', path, value: b, old: a }];
}

/** Apply operations to (newer) content; spots changed meanwhile become conflicts (Changes::apply). */
export function applyOps(content, ops, resolve = {}) {
  const c = clone(content);
  const conflicts = [];
  const sorted = ops.slice().sort((a, b) => (a.op === 'list' ? 0 : 1) - (b.op === 'list' ? 0 : 1));
  for (const op of sorted) {
    const key = keyOf(op.path);
    if (op.op === 'list') {
      let list = getAt(c, op.path);
      if (!Array.isArray(list)) list = [];
      const cur = {};
      list.forEach((it) => { cur[it._id] = it; });
      const removed = (op.old || []).filter((id) => !op.order.includes(id));
      const out = [];
      op.order.forEach((id) => { if (cur[id]) out.push(cur[id]); else if (op.add && op.add[id]) out.push(clone(op.add[id])); });
      list.forEach((it) => { if (!op.order.includes(it._id) && !removed.includes(it._id)) out.push(it); });
      setAt(c, op.path, out);
      continue;
    }
    const last = op.path.map((s, i) => (typeof s === 'object' ? i : -1)).reduce((a, b) => Math.max(a, b), -1);
    if (last >= 0 && getAt(c, op.path.slice(0, last + 1)) === undefined) { conflicts.push({ key, why: 'gone', op }); continue; }
    const now = getAt(c, op.path);
    const nowV = now === undefined ? null : now;
    if (!same(nowV, op.old === undefined ? null : op.old) && !same(nowV, op.value)) {
      const r = resolve[key];
      if (r === 'theirs') continue;
      if (r !== 'mine') { conflicts.push({ key, why: 'changed', op, theirs: nowV }); continue; }
    }
    setAt(c, op.path, op.value === null ? undefined : clone(op.value));
  }
  return [c, conflicts];
}

let opsCache = { ver: -1, liveRev: -1, ops: [] };
export function currentOps() {
  if (opsCache.ver === S.ver && opsCache.liveRev === S.live.rev) return opsCache.ops;
  opsCache = { ver: S.ver, liveRev: S.live.rev, ops: diff(S.live.content, S.draft) };
  return opsCache.ops;
}

/** Dotted path with list positions ("faq.items.3.q", from a preview tap) -> change path. */
export function pathFromDotted(dotted, content = S.draft) {
  const out = [];
  let cur = content;
  for (const seg of dotted.split('.')) {
    // positions in stored lists become item ids; other arrays (headline lines) keep the number
    if (Array.isArray(cur) && /^\d+$/.test(seg) && S.lists[schemaPath(out)] === true) {
      const it = cur[Number(seg)];
      if (!it || !it._id) return null;
      out.push({ id: it._id });
      cur = it;
    } else {
      out.push(seg);
      cur = cur && typeof cur === 'object' ? cur[seg] : undefined;
    }
  }
  return out;
}

// ------------------------------------------------------------------ schema

export function field(sp) { return (S.schema.fields || {})[sp] || null; }
export function isList(sp) { const f = field(sp); return !!f && (f.type === 'list' || f.type === 'checklist'); }
/** Fields of a list's items, in the order they appear. */
export function itemFields(listSp) {
  const pre = listSp + '[].';
  return Object.keys(S.schema.fields).filter((p) => p.startsWith(pre) && !p.slice(pre.length).includes('[]'))
    .sort((a, b) => order(a) - order(b));
}
export function order(sp) {
  const f = field(sp);
  if (f && f.order && f.order < 99999) return f.order;
  // spots that aren't drawn until used sit beside their neighbours
  let best = 99999;
  let bestLen = 0;
  for (const [p, g] of Object.entries(S.schema.fields)) {
    if (!g.order || g.order >= 99999) continue;
    let n = 0;
    while (n < p.length && n < sp.length && p[n] === sp[n]) n++;
    if (n > bestLen || (n === bestLen && g.order > best && n > 0)) { bestLen = n; best = g.order; }
  }
  return best + 0.5;
}
/** "Top of Home: headline, line 1" -> ["Top of Home", "Headline, line 1"] */
export function splitLabel(label) {
  const i = label.indexOf(': ');
  if (i < 0) return ['', label];
  const rest = label.slice(i + 2);
  return [label.slice(0, i), rest.charAt(0).toUpperCase() + rest.slice(1)];
}

// ------------------------------------------------------------------ chips and plain text

const NUMBER_WORDS = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve'];
export function joinList(items, last) {
  if (items.length === 0) return '';
  if (items.length === 1) return items[0];
  return items.slice(0, -1).join(', ') + last + items[items.length - 1];
}
export function visibleStates(c = S.draft) {
  return (c.states || []).filter((s) => !s.hidden && S.boot.states[s.code]).map((s) => ({ ...s, name: S.boot.states[s.code] }));
}
/** A setting as visitors read it (Site::ref). */
export function ref(name, c = S.draft) {
  const site = c.site || {};
  const a = site.address || {};
  switch (name) {
    case 'phone': return site.phone || '';
    case 'phone2': return (site.phone2 || '').trim() !== '' ? site.phone2 : site.phone || '';
    case 'email': return site.email || '';
    case 'address': return `${a.street}, ${a.city}, ${a.region} ${a.zip}`;
    case 'addressFooter': return `${a.street} · ${a.city}, ${a.region} ${a.zip}`;
    case 'callback': return (S.boot.callback[site.callback || '24h'] || [''])[0];
    case 'callbackShort': return (S.boot.callback[site.callback || '24h'] || ['', ''])[1];
    case 'price': return '$' + (parseInt(site.price, 10) || 0);
    case 'adjuster': return (site.adjuster || {}).name || '';
    case 'adjusterTitle': return (site.adjuster || {}).title || '';
    case 'stateCodes': return joinList(visibleStates(c).map((s) => s.code), ' & ');
    case 'stateNames': return joinList(visibleStates(c).map((s) => s.name), ' and ');
    case 'otherStates': {
      const served = visibleStates(c).map((s) => s.code);
      return (((c.claim || {}).byPhone || {}).otherStates || []).filter((x) => !served.includes(x)).map((x) => S.boot.states[x] || x).join(', ');
    }
    case 'guaranteeShort': return (site.guarantee || {}).short || '';
    case 'guaranteeLong': return (site.guarantee || {}).long || '';
    case 'noticeUntil': return untilText((c.notice || {}).end);
    case 'servicesCount': { const n = ((c.services || {}).items || []).filter((s) => !s.hidden).length; return NUMBER_WORDS[n] || String(n); }
    default: return '';
  }
}
export function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

/** The words as visitors read them (Rich::plain). */
export function plain(v, c = S.draft) {
  if (v === null || v === undefined) return '';
  if (typeof v === 'string') return v;
  if (typeof v === 'number') return String(v);
  if (!Array.isArray(v)) return '';
  return v.map((r) => {
    if (typeof r === 'string') return r;
    if (r.ref) { const t = ref(r.ref, c); return r.cap ? capFirst(t) : t; }
    if (r.hl !== undefined) return r.hl;
    if (r.b !== undefined) return r.b;
    if (r.call !== undefined) return r.call;
    if (r.br) return ' ';
    return '';
  }).join('');
}

// ------------------------------------------------------------------ time (Indiana / Central)

const TZ = 'America/Chicago';
const fmt = (o) => new Intl.DateTimeFormat('en-US', { timeZone: TZ, ...o });
export function timeOf(sec) { return fmt({ hour: 'numeric', minute: '2-digit' }).format(new Date(sec * 1000)).replace(' AM', ' am').replace(' PM', ' pm'); }
export function whenOf(sec) {
  const d = new Date(sec * 1000);
  const day = (x) => fmt({ year: 'numeric', month: '2-digit', day: '2-digit' }).format(x);
  const now = new Date();
  if (day(d) === day(now)) return 'Today ' + timeOf(sec);
  if (day(d) === day(new Date(now.getTime() - 86400000))) return 'Yesterday ' + timeOf(sec);
  if (now - d < 6 * 86400000) return fmt({ weekday: 'short' }).format(d) + ' ' + timeOf(sec);
  return fmt({ month: 'short', day: 'numeric' }).format(d) + ' ' + timeOf(sec);
}
/** "2026-10-26T17:00" (Central) -> "Monday, Oct 26" */
export function untilText(s) {
  if (!s) return 'further notice';
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
  if (!m) return 'further notice';
  const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12));
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', weekday: 'long', month: 'short', day: 'numeric' }).format(d);
}
/** Seconds since 1970 for a Central wall time "2026-10-26T17:00". */
export function centralToEpoch(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(s || '');
  if (!m) return null;
  const guess = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
  // Central is UTC-5 or UTC-6; pick the offset that reads back the same wall time
  for (const off of [5, 6]) {
    const t = guess + off * 3600000;
    const back = fmt({ year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date(t));
    const g = (k) => back.find((p) => p.type === k).value;
    if (`${g('year')}-${g('month')}-${g('day')}T${g('hour')}:${g('minute')}` === s) return t / 1000;
  }
  return (guess + 6 * 3600000) / 1000;
}

// ------------------------------------------------------------------ the draft

const LOCAL = () => 'ts-draft-' + S.me.id;
function readLocal() { try { return JSON.parse(localStorage.getItem(LOCAL()) || 'null'); } catch (e) { return null; } }
function writeLocal(v) { try { localStorage.setItem(LOCAL(), JSON.stringify(v)); } catch (e) { /* private mode: the server copy still saves */ } }

/** What an operation sets, to tell which of her changes the server already has. */
const sig = (op) => keyOf(op.path) + '=' + JSON.stringify(op.op === 'list' ? op.order : (op.value ?? null));
S.synced = new Set();
S.draftV = undefined;    // the server's draft version (none from an older server: no check)

/** Start from the server's live content and her saved draft (or this phone's newer copy). */
export function loadDraft(live, draft, opt = {}) {
  S.live = live;
  S.base = draft.ops && draft.ops.length ? draft.base : live.rev;
  S.draftV = draft.v;
  let ops = draft.ops || [];
  S.synced = new Set(ops.map(sig));
  const local = opt.ignoreLocal ? null : readLocal();
  if (local && !local.synced && local.at / 1000 > (draft.saved || 0) && Array.isArray(local.ops)) {
    ops = local.ops;
    S.base = local.base || S.base;
    setTimeout(() => saveDraft(), 500); // the connection dropped last time: send it now
  }
  const resolve = {};
  ops.forEach((op) => { resolve[keyOf(op.path)] = S.resolve[keyOf(op.path)] || 'mine'; });
  const [c] = applyOps(live.content, ops, resolve);
  S.draft = c;
  S.conflicts = draft.conflicts || [];
  S.ver++;
}

let saveTimer = null;
let saving = false;
let again = false;
let retry = 0;
let merges = 0;          // merges in a row (a stuck server mustn't make it loop)

/** Call after every change to S.draft. */
export function changed() {
  S.ver++;
  S.saveState = 'unsaved';
  writeLocal({ base: S.base, ops: currentOps(), at: Date.now(), synced: false });
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => saveDraft(), 1200);
  emit('change');
}

/**
 * The editor is also open in another tab or on another device, and that one saved since: take
 * the server's draft and put this window's unsaved changes on top, so nothing is wiped.
 */
async function mergeStale() {
  const mine = currentOps().filter((op) => !S.synced.has(sig(op)));
  const r = await api('live');
  loadDraft(r.live, r.draft, { ignoreLocal: true });
  if (mine.length) {
    const resolve = {};
    mine.forEach((op) => { resolve[keyOf(op.path)] = 'mine'; });
    const [c] = applyOps(S.draft, mine, resolve);
    S.draft = c;
  }
  changed();
  toast('This editor is also open in another window. Your changes from both are kept.', 'ok', 7000);
  // show the other window's changes too, unless she's typing (the keyboard would close)
  if (!document.body.classList.contains('typing')) render();
}

export async function saveDraft(opt = {}) {
  clearTimeout(saveTimer);
  if (!S.me) return;
  if (saving) { again = true; return; }
  saving = true;
  S.saveState = 'saving';
  emit('save');
  const ops = currentOps();
  const ver = S.ver;
  let stale = false;
  try {
    const r = await api('draft', { base: S.base, ops, v: S.draftV }, { keepalive: !!opt.keepalive });
    if (r.v !== undefined) S.draftV = r.v;
    S.synced = new Set(ops.map(sig));
    S.offline = false;
    retry = 0;
    merges = 0;
    S.savedAt = Date.now();
    if (S.ver === ver) { S.saveState = 'saved'; writeLocal({ base: S.base, ops, at: Date.now(), synced: true }); }
  } catch (e) {
    if (e.status === 401) { saving = false; return; }
    if (e.status === 409 && e.data && e.data.stale && merges < 3) {
      stale = true;
      merges++;
    } else {
      S.offline = true;
      S.saveState = 'offline';
      merges = 0;
      retry = Math.min(retry + 1, 6);
      saveTimer = setTimeout(() => saveDraft(), [3, 5, 10, 20, 30, 60][retry - 1] * 1000);
    }
  } finally {
    saving = false;
    if (stale) {
      try {
        await mergeStale();
      } catch (e) {
        stale = false;
        S.offline = true;
        S.saveState = 'offline';
        saveTimer = setTimeout(() => saveDraft(), 5000);
      }
    }
    emit('save');
    // after merging, send the result at once (a preview or check is waiting on it)
    if (stale) { again = false; await saveDraft(opt); } else if (again) { again = false; saveDraft(); }
  }
}

/** Make sure the server has her newest draft (before a preview or a check). */
export async function flushDraft() {
  if (S.saveState === 'saved') return true;
  await saveDraft();
  return S.saveState === 'saved';
}

window.addEventListener('online', () => { if (S.saveState !== 'saved') saveDraft(); });
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden' && S.saveState !== 'saved' && S.me) saveDraft({ keepalive: true });
  if (document.visibilityState === 'visible') emit('wake');
});

/** Someone else put a change live: move her draft onto it. */
export function rebase(live, serverDraft) {
  const ops = currentOps();
  S.live = live;
  const resolve = {};
  ops.forEach((op) => { resolve[keyOf(op.path)] = S.resolve[keyOf(op.path)] || 'mine'; });
  const [c] = applyOps(live.content, ops, resolve);
  S.draft = c;
  S.conflicts = (serverDraft && serverDraft.conflicts) || S.conflicts;
  if (serverDraft && serverDraft.v !== undefined) S.draftV = serverDraft.v;
  if (serverDraft && Array.isArray(serverDraft.ops)) S.synced = new Set(serverDraft.ops.map(sig));
  S.ver++;
  emit('change');
}

// ------------------------------------------------------------------ router

const routes = [];
const scrolls = {};
const opened = {};       // hash -> the sections she had open there
let current = null;
// How many screens deep she is in this tab, kept with each history entry, so Back never leaves
// the editor or bounces between two screens (also after a reload or a fresh deep link).
const stamp = (d) => { try { history.replaceState({ d }, ''); } catch (e) { /* Safari's limit on rapid calls */ } };
if (!history.state || typeof history.state.d !== 'number') stamp(0);
let depth = history.state && typeof history.state.d === 'number' ? history.state.d : 0;
let replacing = false;
history.scrollRestoration = 'manual';

export function route(re, fn) { routes.push([re, fn]); }
export function go(hash) { if (location.hash === hash) render(); else location.hash = hash; }
/** Go to `hash` in place of this screen (Back then skips this one). */
export function replaceHash(hash) {
  if (location.hash === hash) render();
  else { replacing = true; location.replace(hash); }
}
export function back(fallback = '#/') { if (depth > 0) history.back(); else replaceHash(fallback); }

export async function render() {
  const hash = location.hash || '#/';
  if (current) {
    scrolls[current.hash] = window.scrollY;
    opened[current.hash] = [...document.querySelectorAll('main.screen details[open]')].map((d) => d.dataset.sec || ((d.querySelector('summary') || {}).textContent || ''));
    if (current.leave) try { current.leave(); } catch (e) { /* */ }
  }
  for (const [re, fn] of routes) {
    const m = re.exec(hash);
    if (!m) continue;
    const screen = await fn(...m.slice(1).map((x) => (x === undefined ? x : decodeURIComponent(x))));
    if (!screen) return;
    screen.hash = hash;
    current = screen;
    const revealing = !!S.reveal; // a jump to one box scrolls by itself
    emit('screen', screen);
    if (!revealing) {
      // the same place as before: the same sections open, the same scroll
      const restore = () => {
        const want = opened[hash] || [];
        if (want.length) {
          for (const d of document.querySelectorAll('main.screen details')) {
            if (want.includes(d.dataset.sec || ((d.querySelector('summary') || {}).textContent || ''))) d.open = true;
          }
        }
        requestAnimationFrame(() => window.scrollTo(0, screen.scrollTo ?? scrolls[hash] ?? 0));
      };
      restore();
      // screens that fill in later (History, Leads…) are restored again once they have
      if (screen.ready) Promise.resolve(screen.ready).then(() => { if (current === screen) restore(); }, () => {});
    }
    return;
  }
  replaceHash('#/');
}
window.addEventListener('hashchange', () => {
  if (replacing) { replacing = false; stamp(depth); }
  else if (history.state && typeof history.state.d === 'number') depth = history.state.d;
  else { depth += 1; stamp(depth); }
  render();
});
export function currentScreen() { return current; }
