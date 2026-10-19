// The preview (the real page with her changes, at phone or computer size), "Fix a word I
// saw" (tap a word in the preview to jump to its box) and the search (capitals and line
// breaks don't matter).
import { S, h, icon, clear, getAt, keyOf, parseKey, schemaPath, field, plain, go, toast, sheet, flushDraft, pathFromDotted, splitLabel } from './core.js';
import { screenFor } from './screens.js';
import { instances } from './media.js';
import { itemTitle, cut } from './lists.js';

export const PAGES = [['Home.dc.html', 'Home'], ['About.dc.html', 'About'], ['Services.dc.html', 'Services'], ['FAQ.dc.html', 'FAQ'], ['Contact.dc.html', 'Contact'], ['Claim.dc.html', 'File a Claim'], ['404.html', 'Page not found']];
let mode = 'phone';
// the orange dotted outline for words she can tap (and the one she's looking for)
const FIX_STYLE = '[data-edit]{cursor:pointer}[data-edit]:hover,[data-edit].ts-hit{outline:2px dashed #F09A2E!important;outline-offset:3px}';

function frame(file, onLoad) {
  const W = mode === 'phone' ? 390 : 1280;
  const H = mode === 'phone' ? 780 : 800;
  const ifr = h('iframe.preview-frame', { src: `/edit/p/${file}?v=${S.ver}-${Date.now()}`, title: 'Preview of the page' });
  ifr.style.width = W + 'px';
  ifr.style.height = H + 'px';
  const holder = h('div.preview-holder', ifr);
  const fit = () => {
    if (!holder.isConnected) { window.removeEventListener('resize', fit); return; }
    const avail = holder.parentElement.clientWidth;
    const k = Math.min(1, avail / W);
    // no taller than the screen, so she scrolls the page inside, not a page inside a page
    const hh = Math.min(Math.round(H * k), Math.max(320, window.innerHeight - 220));
    ifr.style.transform = `scale(${k})`;
    ifr.style.height = Math.round(hh / k) + 'px';
    holder.style.height = hh + 'px';
    holder.style.width = Math.round(W * k) + 'px';
  };
  window.addEventListener('resize', fit);
  requestAnimationFrame(fit);
  if (onLoad) {
    // as soon as the page exists (not after every image has loaded), and again on load
    ifr.addEventListener('load', () => onLoad(ifr));
    const early = setInterval(() => {
      if (!holder.isConnected) { clearInterval(early); return; }
      let doc = null;
      try { doc = ifr.contentDocument; } catch (e) { /* */ }
      if (doc && doc.URL.includes('/edit/p/') && doc.head) { clearInterval(early); onLoad(ifr); }
    }, 40);
  }
  return holder;
}

function controls(file, base, redraw) {
  const pages = h('div.seg', { role: 'radiogroup', 'aria-label': 'Page' },
    PAGES.map(([f, n]) => h('button', { type: 'button', role: 'radio', 'aria-checked': String(f === file), text: n, onclick: () => go(base + f) })));
  const seg = h('div.seg', { role: 'radiogroup', 'aria-label': 'Size' },
    [['phone', 'Phone'], ['computer', 'Computer']].map(([m, l]) => h('button', { type: 'button', role: 'radio', 'aria-checked': String(m === mode), text: l, onclick: () => { mode = m; redraw(); } })));
  return h('div.preview-controls', pages, seg);
}

/** A change's key ("faq.items[ab12].q") as the preview marks it ("faq.items.3.q"): list positions instead of ids. */
function dottedOf(key) {
  const path = parseKey(key);
  if (!path) return null;
  const out = [];
  let cur = S.draft;
  for (const seg of path) {
    if (typeof seg === 'object') {
      const i = Array.isArray(cur) ? cur.findIndex((it) => it && it._id === seg.id) : -1;
      if (i < 0) return out.length ? out : null;
      out.push(String(i));
      cur = cur[i];
    } else {
      out.push(seg);
      cur = cur && typeof cur === 'object' ? cur[seg] : undefined;
    }
  }
  return out;
}

/** The marked element for a change in the preview: the spot itself, or the nearest part around it. */
function findMarked(doc, parts) {
  const q = (sel) => { try { return doc.querySelector(sel); } catch (e) { return null; } };
  const esc = (t) => t.replace(/["\\]/g, '\\$&');
  const full = parts.join('.');
  // the spot, or its lines (a headline's "home.top.title.0")
  let el = q(`[data-edit~="${esc(full)}"]`) || q(`[data-edit^="${esc(full)}."], [data-edit*=" ${esc(full)}."]`);
  for (let n = parts.length - 1; !el && n >= 2; n--) el = q(`[data-edit~="${esc(parts.slice(0, n).join('.'))}"]`);
  return el;
}

/** The real page with her changes. With `key`, the changed spot is outlined in orange. */
export function previewScreen(file, key) {
  // "#/preview/FAQ.dc.html/faq.items[ab12].q" works too
  if (key === undefined && file.includes('/')) { const i = file.indexOf('/'); key = file.slice(i + 1) || undefined; file = file.slice(0, i); }
  const body = h('div.stack');
  const draw = async () => {
    clear(body).append(h('p.small', { text: 'Getting the preview ready…' }));
    const ok = await flushDraft();
    clear(body);
    body.append(controls(file, '#/preview/', draw));
    if (!ok) { body.append(h('p.callout.warn', { text: 'The preview needs a connection. Your changes are saved on this phone.' })); return; }
    body.append(h('p.help', { text: 'This is the real page with your changes. Visitors don\'t see them until you put them live.' }));
    const found = h('p.help', { text: 'Your change is outlined in orange.', hidden: true });
    body.append(found);
    const parts = key ? dottedOf(key) : null;
    let started = false;
    body.append(frame(file, parts ? (ifr) => { if (!started) { started = true; outline(ifr, parts, found); } } : null));
  };
  draw();
  return { title: 'Preview', body, wide: true };
}

/** Wait for the page to draw the spot, scroll it to the middle and outline it for 3 seconds. */
function outline(ifr, parts, found) {
  const t0 = Date.now();
  let el = null;
  let at = 0;
  const tick = () => {
    if (!ifr.isConnected) return;
    let doc = null;
    try { doc = ifr.contentDocument; } catch (e) { return; }
    if (doc && doc.head && !doc.__tsHitStyle) {
      doc.__tsHitStyle = true;
      const st = doc.createElement('style');
      st.textContent = FIX_STYLE;
      doc.head.appendChild(st);
    }
    // the page draws itself (twice), so the spot can be replaced: find it again until it settles
    const now = doc && doc.head ? findMarked(doc, parts) : null;
    if (now && now !== el) {
      el = now;
      at = at || Date.now();
      el.classList.add('ts-hit');
      const win = doc.defaultView;
      const r = el.getBoundingClientRect();
      win.scrollTo(0, Math.max(0, r.top + win.scrollY - (win.innerHeight - Math.min(r.height, win.innerHeight)) / 2));
      found.hidden = false;
    }
    if (at && Date.now() - at > 3000) { if (el) el.classList.remove('ts-hit'); return; }
    if (at || Date.now() - t0 < 12000) setTimeout(tick, 200);
  };
  tick();
}

/** "Fix a word I saw": the preview, but a tap opens the box for those words. */
export function fixScreen(file) {
  // without a page in the address, the page she looked at last
  if (file === undefined || /^#\/fix\/?$/.test(location.hash)) {
    let saved = null;
    try { saved = sessionStorage.getItem('ts-fix-page'); } catch (e) { /* */ }
    file = PAGES.some(([f]) => f === saved) ? saved : 'Home.dc.html';
  }
  try { sessionStorage.setItem('ts-fix-page', file); } catch (e) { /* */ }
  const body = h('div.stack');
  const draw = async () => {
    clear(body).append(h('p.small', { text: 'Getting the page ready…' }));
    const ok = await flushDraft();
    clear(body);
    body.append(controls(file, '#/fix/', draw));
    if (!ok) { body.append(h('p.callout.warn', { text: 'This needs a connection. You can also use "Find words" from the start screen.' })); return; }
    body.append(h('p.callout.info', { text: 'Tap the words or photo you want to change (they have an orange dotted line).' }));
    body.append(frame(file, (ifr) => attach(ifr)));
    body.append(h('button.btn.ghost', { type: 'button', onclick: () => go('#/search') }, icon('search'), 'Or search for the words'));
  };
  const attach = (ifr) => {
    let doc;
    try { doc = ifr.contentDocument; } catch (e) { return; }
    if (!doc || doc.__tsFix) return;
    doc.__tsFix = true;
    const st = doc.createElement('style');
    // on a phone there's no hover, so the dotted line is always there (fainter)
    st.textContent = FIX_STYLE + '@media (hover: none){[data-edit]{outline:1px dashed rgba(240,154,46,.5);outline-offset:2px}}';
    doc.head.appendChild(st);
    // the page's floating buttons (chat, call) would cover words and can't be changed here
    const hideFloating = () => {
      try {
        for (const b of doc.querySelectorAll('button')) if (doc.defaultView.getComputedStyle(b).position === 'fixed') b.style.setProperty('display', 'none', 'important');
      } catch (e) { /* the page went away */ }
    };
    hideFloating();
    setTimeout(hideFloating, 1000);
    const stop = (e) => { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); };
    doc.addEventListener('submit', stop, true);
    doc.addEventListener('click', async (e) => {
      stop(e);
      const el = e.target && e.target.closest ? e.target.closest('[data-edit]') : null;
      if (!el) { toast('That part can\'t be changed here. Tap words or a photo.', 'warn'); return; }
      el.classList.add('ts-hit');
      setTimeout(() => el.classList.remove('ts-hit'), 800);
      const paths = el.getAttribute('data-edit').split(' ').map((d) => pathFromDotted(d)).filter(Boolean);
      if (!paths.length) return;
      let path = paths[0];
      if (paths.length > 1) {
        const pick = await sheet('Which words?', 'This part has more than one box:', paths.map((p, i) => ({ label: describeSpot(p), value: i })).concat([{ label: 'Cancel', value: null, kind: 'ghost' }]));
        if (pick === null) return;
        path = paths[pick];
      }
      jump(path);
    }, true);
  };
  draw();
  return { title: 'Fix a word I saw', body, wide: true };
}

function describeSpot(path) {
  const sp = schemaPath(path);
  const f = field(sp);
  const v = getAt(S.draft, path);
  if (!f) {
    // a list item ("states.2")
    const it = v || {};
    return itemTitle(it, sp.replace(/\[\]$/, ''));
  }
  const t = plain(v);
  return f.label + (t && typeof v !== 'boolean' ? ': “' + cut(t, 40) + '”' : '');
}

export function jump(path) {
  const last = path[path.length - 1];
  if (typeof last === 'object') {
    // a whole list item: open it
    const listPath = path.slice(0, -1);
    S.reveal = null;
    go(`#/item/${encodeURIComponent(keyOf(listPath))}/${last.id}`);
    return;
  }
  const f = field(schemaPath(path));
  if (f && (f.type === 'photo' || f.type === 'video')) { go('#/photo/' + encodeURIComponent(keyOf(path))); return; }
  S.reveal = keyOf(path);
  go(screenFor(path));
}

// ------------------------------------------------------------------ search

const SEARCHABLE = new Set(['text', 'para', 'rich', 'alt', 'name', 'phone', 'email', 'link', 'esign']);
const norm = (s) => String(s || '').toLowerCase().replace(/[’‘]/g, "'").replace(/[“”]/g, '"').replace(/[–—]/g, '-').replace(/\s+/g, ' ').trim();

function index() {
  const out = [];
  for (const [sp, f] of Object.entries(S.schema.fields)) {
    if (f.editorOnly) continue;
    const isPart = ['guarantee', 'address', 'person', 'number-quote'].includes(f.type);
    if (!SEARCHABLE.has(f.type) && !isPart) continue;
    for (const [path, v] of instances(S.draft, sp)) {
      if (v === undefined || v === null) continue;
      let text = '';
      if (f.type === 'guarantee') text = [v.short, v.long].join(' · ');
      else if (f.type === 'address') text = [v.street, v.city, v.region, v.zip].join(' ');
      else if (f.type === 'person') text = [v.name, v.title].join(', ');
      else if (f.type === 'number-quote') text = (v.plus ? '+' : '') + v.value + (v.percent ? '%' : '') + ' ' + (v.caption || '');
      else text = plain(v);
      if (!text) continue;
      let label = f.label;
      const ii = path.map((x) => typeof x).lastIndexOf('object');
      if (ii >= 0) label += ' (' + itemTitle(getAt(S.draft, path.slice(0, ii + 1)), schemaPath(path.slice(0, ii))) + ')';
      out.push({ path, label, text, n: norm(text) });
    }
  }
  return out;
}

export function searchScreen() {
  const inp = h('input.input.search', { type: 'search', placeholder: 'Type some words you saw', autocapitalize: 'off', autocorrect: 'off', spellcheck: false, enterkeyhint: 'search', 'aria-label': 'Find words' });
  const results = h('ul.results');
  let idx = null;
  let t = null;
  const run = () => {
    const q = norm(inp.value);
    clear(results);
    if (q.length < 2) return;
    idx = idx || index();
    const hits = idx.filter((x) => x.n.includes(q)).slice(0, 60);
    if (!hits.length) { results.append(h('li.small', { text: 'Nothing found. Try fewer words.' })); return; }
    for (const x of hits) {
      const flat = x.text.replace(/\s+/g, ' ');
      const i = norm(flat).indexOf(q);
      const start = Math.max(0, i - 40);
      const snippet = h('span.snippet', start > 0 ? '…' : '', flat.slice(start, i), h('mark', { text: flat.slice(i, i + q.length) }), flat.slice(i + q.length, i + q.length + 60), i + q.length + 60 < flat.length ? '…' : '');
      results.append(h('li', h('button.result', { type: 'button', onclick: () => jump(x.path) }, h('span.result-label', { text: x.label }), snippet)));
    }
  };
  inp.addEventListener('input', () => { clearTimeout(t); t = setTimeout(run, 150); });
  setTimeout(() => inp.focus(), 50);
  return { title: 'Find words', body: h('div.stack', inp, h('p.small', { text: 'Capitals and line breaks don\'t matter.' }), results) };
}
