// One box per editable spot, by kind: words (with Highlight and Bold where the design allows),
// phone, email, link, switch, choice, date, stars, address… Every box has the right phone
// keyboard: no autocorrect or auto-capitals in phone, email, link and name boxes.
import { S, h, icon, clear, getAt, setAt, keyOf, schemaPath, field, changed, plain, ref, go, splitLabel, sheet, toast, emit, clone, api } from './core.js';

// keyboard settings per kind of box
const KB = {
  text: { autocapitalize: 'sentences', autocorrect: 'on', spellcheck: true },
  caps: { autocapitalize: 'characters', autocorrect: 'on', spellcheck: true },
  name: { autocapitalize: 'words', autocorrect: 'off', spellcheck: false, autocomplete: 'off' },
  phone: { type: 'tel', inputmode: 'tel', autocapitalize: 'off', autocorrect: 'off', spellcheck: false, autocomplete: 'off' },
  email: { type: 'email', inputmode: 'email', autocapitalize: 'off', autocorrect: 'off', spellcheck: false, autocomplete: 'off' },
  url: { type: 'url', inputmode: 'url', autocapitalize: 'off', autocorrect: 'off', spellcheck: false, autocomplete: 'off' },
  number: { inputmode: 'numeric', pattern: '[0-9]*', autocapitalize: 'off', autocorrect: 'off', spellcheck: false, autocomplete: 'off' },
  code: { autocapitalize: 'characters', autocorrect: 'off', spellcheck: false, autocomplete: 'off' },
};
export function kb(kind) { return { ...KB[kind] }; }

// spots that only show after a visitor does something
const AFTER = [
  [/^forms\.claim\.(done|another|whileYouWait|photos(Sent|Partial|Failed|Sending))/, 'Appears after a visitor sends the claim form.'],
  [/^forms\.contact\.(done|another)/, 'Appears after a visitor sends the message form.'],
  [/^forms\.(claim|contact)\.(error|sending)/, 'Appears only if sending fails or while it sends.'],
  [/^forms\.(required|phoneInvalid)/, 'Appears when a box in a form is left wrong.'],
  [/^forms\.noscript/, 'Appears only in browsers with JavaScript turned off.'],
  [/^chat\.(doneNoContact|doneWithContact|fail|followUp|askContact|askAgain)/, 'Appears in the chat after a visitor writes.'],
];
export function afterNote(sp) { for (const [re, t] of AFTER) if (re.test(sp)) return t; return ''; }

// ------------------------------------------------------------------ the box around every spot

/**
 * A labelled box for one spot. opts.short drops the section from the label ("Top of Home: ")
 * when the screen already shows it. Changes go straight into the draft.
 */
export function fieldBox(path, opts = {}) {
  const sp = schemaPath(path);
  const f = field(sp);
  if (!f) return null;
  const key = keyOf(path);
  let label = opts.label || (opts.short ? splitLabel(f.label)[1] : f.label);
  if (sp === 'states[].licensed') label = `${((S.draft.site || {}).adjuster || {}).name || 'The adjuster'} is licensed here (required)`;
  if (sp === 'home.reviews.items[].real') label = 'Real customer, copied word for word (required)';
  const box = h('section.field-box', { dataset: { key } });
  const pill = h('span.pill.changed', { text: 'Changed', hidden: true });
  const undo = h('button.linkish', { type: 'button', text: 'Undo my change', hidden: true });
  const head = h('div.field-head', h('label.field-label', { text: label }), pill);
  box.append(head);
  if (f.help) box.append(h('p.help', { text: f.help }));
  const note = afterNote(sp);
  if (note) box.append(h('p.help.after', { text: note }));
  const meta = h('div.field-meta');
  const ctl = control(path, f, () => refresh());
  if (ctl.labelFor && ctl.labelFor.id === '') { ctl.labelFor.id = 'f-' + key.replace(/[^A-Za-z0-9]/g, '-'); head.querySelector('label').htmlFor = ctl.labelFor.id; }
  box.append(ctl.el, meta);
  const conflictBox = h('div');
  box.append(conflictBox, h('div.field-foot', undo));
  undo.addEventListener('click', () => {
    const live = getAt(S.live.content, path);
    setAt(S.draft, path, live === undefined ? undefined : clone(live));
    changed();
    const fresh = fieldBox(path, opts);
    box.replaceWith(fresh);
  });
  function refresh() {
    const now = getAt(S.draft, path);
    const was = getAt(S.live.content, path);
    const diff = JSON.stringify(now === undefined ? null : now) !== JSON.stringify(was === undefined ? null : was);
    pill.hidden = !diff;
    undo.hidden = !diff || opts.noUndo;
    box.classList.toggle('is-changed', diff);
    clear(meta);
    if (ctl.meta) ctl.meta(meta);
    if (f.fit && ['text', 'para', 'rich', 'alt'].includes(f.type)) meta.append(fitMeter(plain(now), f));
    clear(conflictBox);
    const c = S.conflicts.find((x) => x.key === key);
    if (c && !S.resolve[key]) conflictBox.append(conflictCard(c, path, () => { box.replaceWith(fieldBox(path, opts)); }));
  }
  refresh();
  if (sp === 'seo.home.title') box.append(titleChoices(path, () => box.replaceWith(fieldBox(path, opts))));
  box.focusControl = () => { if (ctl.focus) ctl.focus(); };
  return box;
}

/** Once the states list grows, the Home page's name on Google gets long: offer shorter ones. */
const NUMBER_WORDS = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve'];
function titleChoices(path, redraw) {
  const n = (S.draft.states || []).filter((x) => !x.hidden && x.code).length;
  if (n <= 5) return null;
  const name = ((S.draft.site || {}).businessName || 'Think Smart Public Adjusting').replace(/\s+LLC$/i, '');
  const choices = [`${name} | Public Adjusters in ${NUMBER_WORDS[n] || n} States`, `Licensed Public Adjusters for Homeowners | ${name}`, `${name} | Insurance Claim Help for Policyholders`];
  return h('div.callout.info', h('p', { text: `With ${n} states, the name on Google gets cut off. Pick a shorter one:` }),
    h('div.stack', choices.map((c) => h('button.btn.small', { type: 'button', text: c, onclick: () => { setVal(path, c); redraw(); } }))));
}

/** "John changed this 5 minutes ago. Keep his / Use mine" */
export function conflictCard(c, path, done) {
  const ago = c.at ? Math.max(1, Math.round((Date.now() / 1000 - c.at) / 60)) : null;
  const when = ago === null ? 'meanwhile' : ago < 60 ? `${ago} minute${ago === 1 ? '' : 's'} ago` : ago < 1440 ? `${Math.round(ago / 60)} hour${Math.round(ago / 60) === 1 ? '' : 's'} ago` : 'earlier';
  const his = c.by === S.me.id ? 'yours' : 'theirs';
  if (c.why === 'gone') {
    return h('div.callout.warn', h('p', { text: `${c.byName || 'Someone'} deleted this ${when}, so your change to it can't go live.` }),
      h('button.btn.small', { type: 'button', text: 'Drop my change', onclick: () => { S.resolve[c.key] = 'theirs'; changed(); done(); } }));
  }
  return h('div.callout.warn',
    h('p', { text: `${c.byName || 'Someone'} changed this ${when}.` }),
    c.theirs ? h('p.small', h('strong', { text: 'Now on the website: ' }), c.theirs) : null,
    h('div.row',
      h('button.btn.small', { type: 'button', text: his === 'yours' ? 'Keep what\'s live' : `Keep ${c.byName ? c.byName + '\'s' : 'theirs'}`, onclick: () => {
        const live = getAt(S.live.content, path);
        setAt(S.draft, path, live === undefined ? undefined : clone(live));
        S.resolve[c.key] = 'theirs';
        changed();
        done();
      } }),
      h('button.btn.small.primary', { type: 'button', text: 'Use mine', onclick: () => { S.resolve[c.key] = 'mine'; changed(); done(); } })));
}

export function fitMeter(text, f) {
  const n = [...(text || '')].length;
  const [fits, long] = f.fit;
  const state = n <= fits ? 'fits' : n <= long ? 'long' : 'too';
  const words = { fits: 'Fits', long: 'Getting long', too: 'Too long' }[state];
  return h('span.fit.' + state, { title: `${n} letters` }, h('span.fit-bar', h('span', { style: { width: Math.min(100, Math.round((n / long) * 100)) + '%' } })), words);
}

// ------------------------------------------------------------------ controls

function setVal(path, v) { setAt(S.draft, path, v); changed(); }
function input(kind, attrs) { return h('input.input', { ...kb(kind), ...attrs }); }

/** Grows with its text (no scrolling inside a box on a phone). */
export function autoGrow(ta) {
  const fit = () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 2 + 'px'; };
  ta.addEventListener('input', fit);
  requestAnimationFrame(fit);
  return ta;
}

function control(path, f, refresh) {
  const v = getAt(S.draft, path);
  const sp = schemaPath(path);
  const type = f.type;
  if (Array.isArray(v) && ['text', 'para', 'alt', 'rich'].includes(type)) return richControl(path, f, refresh);
  switch (type) {
    case 'rich': return richControl(path, f, refresh);
    case 'text': case 'para': case 'alt': case 'name': {
      const multi = type === 'para' || type === 'alt' || (f.fit && f.fit[1] > 90);
      const kind = type === 'name' ? 'name' : f.caps ? 'caps' : 'text';
      const el = multi ? autoGrow(h('textarea.input', { rows: 2, ...kb(kind) })) : input(kind, {});
      el.value = typeof v === 'string' ? v : '';
      if (f.caps) el.classList.add('caps');
      if (f.hard) el.maxLength = f.hard;
      el.addEventListener('input', () => {
        let t = el.value.replace(/[\r\n]+/g, ' ');
        if (t !== el.value) el.value = t;
        setVal(path, t);
        refresh();
      });
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el.blur(); } });
      if (sp === 'home.reviews.items[].source') {
        // remembers the last choice for the next review
        const dl = h('datalist', { id: 'sources' }, ['Google', 'Facebook', 'Yelp', 'Nextdoor', 'BBB', 'Angi', 'Email', 'Letter'].map((x) => h('option', { value: x })));
        el.setAttribute('list', 'sources');
        el.addEventListener('change', () => { const v = el.value.trim(); if (v) { S.prefs.reviewSource = v; api('prefs', { set: { reviewSource: v } }).catch(() => {}); } });
        return { el: h('div', el, dl), labelFor: el, focus: () => el.focus() };
      }
      return { el, labelFor: el, focus: () => el.focus(), meta: type === 'name' && sp.endsWith('.name') && sp.includes('reviews') ? (m) => m.append(h('span.small', { text: 'Shows as: ' + shortName(el.value || '') })) : null };
    }
    case 'phone': {
      const el = input('phone', { placeholder: '812-774-5049' });
      el.value = v || '';
      el.addEventListener('input', () => { setVal(path, el.value.trim() === '' && f.optional ? '' : el.value); refresh(); });
      el.addEventListener('blur', () => { const d = el.value.replace(/\D/g, '').replace(/^1(?=\d{10}$)/, ''); if (d.length === 10) { el.value = `${d.slice(0, 3)}-${d.slice(3, 6)}-${d.slice(6)}`; setVal(path, el.value); refresh(); } });
      return {
        el, labelFor: el, focus: () => el.focus(),
        meta: (m) => {
          const d = (el.value || '').replace(/\D/g, '');
          if (el.value.trim() === '') { m.append(h('span.small', { text: f.optional ? 'Empty: the main number is used instead.' : 'Needs a phone number.' })); return; }
          if (d.length !== 10) { m.append(h('span.bad-text', { text: 'A phone number has 10 digits, like 812-774-5049.' })); return; }
          m.append(h('span.small', { text: `Visitors see ${d.slice(0, 3)}-${d.slice(3, 6)}-${d.slice(6)}. ` }), h('a.linkish', { href: 'tel:' + d, text: 'Test call' }));
        },
      };
    }
    case 'email': {
      const el = input('email', { placeholder: 'name@example.com' });
      el.value = v || '';
      el.addEventListener('input', () => { setVal(path, el.value.trim()); refresh(); });
      return { el, labelFor: el, focus: () => el.focus(), meta: (m) => { if (!/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/.test(el.value.trim())) m.append(h('span.bad-text', { text: 'That doesn\'t look like an email address yet.' })); else m.append(h('span.small', { text: 'Visitors see ' + el.value.trim() })); } };
    }
    case 'link': return linkControl(path, f, refresh, v);
    case 'esign': return esignControl(path, f, refresh, v);
    case 'money': {
      const el = input('number', { placeholder: '299' });
      el.value = v === undefined || v === null ? '' : String(v);
      el.addEventListener('input', () => { const n = parseInt(el.value.replace(/\D/g, ''), 10); setVal(path, Number.isFinite(n) ? n : 0); refresh(); });
      return { el: h('div.money', h('span', { text: '$' }), el), labelFor: el, focus: () => el.focus() };
    }
    case 'tick': case 'switch': case 'star': return switchControl(path, f, refresh, v);
    case 'choice': return choiceControl(path, f, refresh, v);
    case 'address': return partsControl(path, f, refresh, v, { street: 'text', city: 'name', region: 'code', zip: 'number' });
    case 'person': return partsControl(path, f, refresh, v, { name: 'name', title: 'text' });
    case 'guarantee': return partsControl(path, f, refresh, v, { short: 'para', long: 'para' });
    case 'number-quote': return numberControl(path, f, refresh, v);
    case 'datetime': {
      const el = h('input.input', { type: 'datetime-local', step: 900 });
      el.value = v || '';
      const clearBtn = h('button.btn.small.ghost', { type: 'button', text: 'No date', onclick: () => { el.value = ''; setVal(path, ''); refresh(); } });
      el.addEventListener('change', () => { setVal(path, el.value ? el.value.slice(0, 16) : ''); refresh(); });
      return { el: h('div.row', el, clearBtn), labelFor: el, focus: () => el.focus() };
    }
    case 'month': return monthControl(path, f, refresh, v);
    case 'stars': return starsControl(path, f, refresh, v);
    case 'state': {
      const used = (S.draft.states || []).map((s) => s.code);
      const el = h('select.input', h('option', { value: '', text: 'Pick a state' }),
        Object.entries(S.boot.states).map(([c, n]) => h('option', { value: c, text: n, disabled: c !== v && used.includes(c) })));
      el.value = v || '';
      el.addEventListener('change', () => { setVal(path, el.value); refresh(); emit('states'); });
      return { el, labelFor: el, focus: () => el.focus() };
    }
    case 'states': return statesControl(path, f, refresh, v);
    case 'icon': {
      const wrap = h('div.icon-choices', { role: 'radiogroup' });
      (f.options || []).forEach((src) => {
        const b = h('button.icon-choice', { type: 'button', role: 'radio', 'aria-checked': String(src === v), 'aria-label': 'Drawing ' + src.replace(/^.*picto-|\.webp$/g, '').replace(/-/g, ' ') }, h('img', { src: '/' + src, alt: '' }));
        b.addEventListener('click', () => { setVal(path, src); wrap.querySelectorAll('button').forEach((x) => x.setAttribute('aria-checked', String(x === b))); refresh(); });
        wrap.append(b);
      });
      return { el: wrap };
    }
    case 'slug': return { el: h('p.small', { text: v ? 'Link address: Services.dc.html#' + v + ' (set once, so old links keep working)' : 'Set automatically from the name.' }) };
    case 'photo': case 'video': return { el: mediaThumb(path, f, v) };
    case 'pdf': {
      const item = getAt(S.draft, path.slice(0, -1)) || {};
      const pending = item.pdfV && item.pdfV !== (getAt(S.live.content, path.slice(0, -1)) || {}).pdfV;
      return { el: h('div.row', h('span.small', { text: v ? (pending ? 'New contract, not live yet' : 'Contract PDF on the website') : 'No contract PDF yet.' }),
        h('button.btn.small', { type: 'button', text: v ? 'Replace or view' : 'Add the PDF', onclick: () => go('#/contract/' + path[path.length - 2].id) })) };
    }
    case 'list': case 'checklist': return { el: h('p.small', { text: 'List' }) };
    default: return { el: h('span') };
  }
}

export function shortName(name) {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length < 2) return parts[0] || '';
  const last = parts.pop();
  return parts.join(' ') + ' ' + [...last][0].toUpperCase() + '.';
}

function switchControl(path, f, refresh, v) {
  const on = !!v;
  const b = h('button.switch', { type: 'button', role: 'switch', 'aria-checked': String(on) }, h('span.knob'), h('span.switch-text', { text: on ? 'On' : 'Off' }));
  b.addEventListener('click', () => {
    const now = b.getAttribute('aria-checked') !== 'true';
    b.setAttribute('aria-checked', String(now));
    b.querySelector('.switch-text').textContent = now ? 'On' : 'Off';
    setVal(path, now);
    refresh();
  });
  return { el: b, focus: () => b.focus() };
}

function choiceControl(path, f, refresh, v) {
  const opts = Array.isArray(f.options) ? f.options.map((o) => [o, o]) : Object.entries(f.options || {});
  if (opts.length <= 4 && opts.every(([, l]) => l.length < 28)) {
    const wrap = h('div.seg', { role: 'radiogroup' });
    opts.forEach(([val, lab]) => {
      const b = h('button', { type: 'button', role: 'radio', 'aria-checked': String(val === v), text: lab });
      b.addEventListener('click', () => { setVal(path, val); wrap.querySelectorAll('button').forEach((x) => x.setAttribute('aria-checked', String(x === b))); refresh(); emit('choice', path); });
      wrap.append(b);
    });
    return { el: wrap };
  }
  const el = h('select.input', opts.map(([val, lab]) => h('option', { value: val, text: lab })));
  el.value = v || opts[0][0];
  el.addEventListener('change', () => { setVal(path, el.value); refresh(); emit('choice', path); });
  return { el, labelFor: el, focus: () => el.focus() };
}

function partsControl(path, f, refresh, v, kinds) {
  const wrap = h('div.parts');
  const val = { ...(v || {}) };
  Object.entries(f.parts || {}).forEach(([k, lab]) => {
    const kind = kinds[k] || 'text';
    const el = kind === 'para' ? autoGrow(h('textarea.input', { rows: 2, ...kb('text') })) : input(kind === 'code' ? 'code' : kind, k === 'region' ? { maxLength: 2 } : {});
    el.value = val[k] === undefined ? '' : String(val[k]);
    el.addEventListener('input', () => {
      let t = el.value.replace(/[\r\n]+/g, ' ');
      if (k === 'region') t = t.toUpperCase();
      val[k] = t;
      setVal(path, { ...val });
      refresh();
    });
    wrap.append(h('label.part', h('span', { text: lab }), el));
  });
  return { el: wrap };
}

function numberControl(path, f, refresh, v) {
  const val = { ...(v || {}) };
  const save = () => { setVal(path, { ...val }); refresh(); };
  const num = input('number', {});
  num.value = val.value === undefined ? '' : String(val.value);
  num.addEventListener('input', () => { val.value = parseInt(num.value.replace(/\D/g, ''), 10) || 0; save(); });
  const plus = h('label.check', h('input', { type: 'checkbox', checked: !!val.plus, onchange: (e) => { val.plus = e.target.checked; save(); } }), ' Starts with +');
  const pct = h('label.check', h('input', { type: 'checkbox', checked: !!val.percent, onchange: (e) => { val.percent = e.target.checked; save(); } }), ' Ends with %');
  const cap = autoGrow(h('textarea.input', { rows: 2, ...kb('text') }));
  cap.value = val.caption || '';
  cap.addEventListener('input', () => { val.caption = cap.value.replace(/[\r\n]+/g, ' '); save(); });
  const shown = h('label.check', h('input', { type: 'checkbox', checked: !val.hidden, onchange: (e) => { if (e.target.checked) delete val.hidden; else val.hidden = true; save(); emit('numbers'); } }), ' Show this number');
  return {
    el: h('div.parts', h('div.row', h('label.part.narrow', h('span', { text: 'Number' }), num), plus, pct), h('label.part', h('span', { text: 'Caption' }), cap), shown),
    meta: (m) => m.append(h('span.small', { text: 'Shows as ' + (val.plus ? '+' : '') + (val.value || 0) + (val.percent ? '%' : '') })),
  };
}

function monthControl(path, f, refresh, v) {
  const [y0, m0] = (v || '').split('-');
  const months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const mSel = h('select.input', months.map((m, i) => h('option', { value: i ? String(i).padStart(2, '0') : '', text: i ? m : 'Month' })));
  const yNow = new Date().getFullYear();
  const ySel = h('select.input', h('option', { value: '', text: 'Year' }), Array.from({ length: 12 }, (_, i) => String(yNow - i)).map((y) => h('option', { value: y, text: y })));
  mSel.value = m0 || '';
  ySel.value = y0 || '';
  const save = () => { setVal(path, mSel.value && ySel.value ? `${ySel.value}-${mSel.value}` : ''); refresh(); };
  mSel.addEventListener('change', save);
  ySel.addEventListener('change', save);
  return { el: h('div.row', mSel, ySel) };
}

function starsControl(path, f, refresh, v) {
  const wrap = h('div.stars', { role: 'radiogroup', 'aria-label': 'Stars' });
  const paint = (n) => wrap.querySelectorAll('button[data-n]').forEach((b) => b.setAttribute('aria-checked', String(Number(b.dataset.n) <= n && n > 0)));
  for (let n = 1; n <= 5; n++) {
    wrap.append(h('button', { type: 'button', role: 'radio', dataset: { n: String(n) }, 'aria-label': n + ' stars', text: '★', onclick: () => { setVal(path, n); paint(n); refresh(); } }));
  }
  wrap.append(h('button.btn.small.ghost', { type: 'button', text: 'No stars', onclick: () => { setVal(path, undefined); paint(0); refresh(); } }));
  paint(v || 0);
  return { el: wrap };
}

function statesControl(path, f, refresh, v) {
  const val = new Set(v || []);
  const served = new Set((S.draft.states || []).filter((s) => !s.hidden).map((s) => s.code));
  const wrap = h('div.state-picks');
  Object.entries(S.boot.states).forEach(([c, n]) => {
    if (served.has(c)) return;
    const cb = h('input', { type: 'checkbox', checked: val.has(c) });
    cb.addEventListener('change', () => { if (cb.checked) val.add(c); else val.delete(c); setVal(path, Object.keys(S.boot.states).filter((x) => val.has(x))); refresh(); });
    wrap.append(h('label.check', cb, ' ' + n));
  });
  return { el: wrap, meta: (m) => m.append(h('span.small', { text: 'Reads: ' + (ref('otherStates') || '(none)') })) };
}

/** Social links: pick the platform, paste the link or just "@name"; tracking junk is removed. */
const SOCIAL = {
  Facebook: 'https://www.facebook.com/', Instagram: 'https://www.instagram.com/', TikTok: 'https://www.tiktok.com/@', X: 'https://x.com/',
  YouTube: 'https://www.youtube.com/@', LinkedIn: 'https://www.linkedin.com/company/',
};
export function cleanLink(raw, platform) {
  let t = (raw || '').trim();
  if (/^@?[A-Za-z0-9._-]{2,60}$/.test(t) && !/\./.test(t.replace(/^@/, '').slice(-4)) && SOCIAL[platform]) t = SOCIAL[platform] + t.replace(/^@/, '');
  if (/^www\./i.test(t)) t = 'https://' + t;
  try {
    const u = new URL(t);
    if (u.protocol === 'http:') u.protocol = 'https:';
    [...u.searchParams.keys()].forEach((k) => { if (/^(utm_|fbclid|gclid|igshid|igsh|mibextid|si|ref|refsrc|_rdr|hl|rdid|share_id)/i.test(k)) u.searchParams.delete(k); });
    u.hash = '';
    return u.toString();
  } catch (e) {
    return t;
  }
}
function linkControl(path, f, refresh, v) {
  const el = input('url', { placeholder: 'Paste the link, or @name' });
  el.value = v || '';
  const item = getAt(S.draft, path.slice(0, -1)) || {};
  el.addEventListener('input', () => { setVal(path, el.value.trim()); refresh(); });
  el.addEventListener('blur', () => { const c = cleanLink(el.value, item.platform); if (c !== el.value) { el.value = c; setVal(path, c); refresh(); } });
  return {
    el, labelFor: el, focus: () => el.focus(),
    meta: (m) => {
      const t = el.value.trim();
      if (!t) return;
      if (!/^https:\/\//.test(t)) { m.append(h('span.bad-text', { text: 'A link starts with https://' })); return; }
      m.append(h('span.small', { text: 'Shows as "' + (item.platform || 'Link').toUpperCase() + ' ↗". ' }), h('a.linkish', { href: t, target: '_blank', rel: 'noopener noreferrer', text: 'Test it' }));
    },
  };
}
function esignControl(path, f, refresh, v) {
  const el = input('url', { placeholder: 'https://…adobe.com/public/esignWidget?wid=…' });
  el.value = v || '';
  el.addEventListener('input', () => { setVal(path, el.value.trim()); refresh(); });
  const item = getAt(S.draft, path.slice(0, -1)) || {};
  const state = S.boot.states[item.code] || 'this state\'s';
  return {
    el, labelFor: el, focus: () => el.focus(),
    meta: (m) => {
      const t = el.value.trim();
      if (!t) { m.append(h('span.small', { text: 'No link: the state shows a "Call to sign" card.' })); return; }
      const w = esignWarning(t);
      if (w) m.append(h('span.bad-text', { text: w + ' ' }));
      m.append(h('button.btn.small', { type: 'button', text: 'Test', onclick: async () => {
        window.open(t, '_blank', 'noopener');
        const r = await sheet('Did it open?', `Do you see the blank ${state} agreement, with nobody's details filled in?`, [{ label: 'Yes, it\'s blank', value: 'y', kind: 'primary' }, { label: 'No', value: 'n' }]);
        if (r === 'n') toast('Ask John for the public web form link for ' + state + '.', 'warn', 6000);
      } }));
    },
  };
}
export function esignWarning(url) {
  if (!/^https:\/\//.test(url)) return 'The link must start with https://';
  let host = '';
  try { host = new URL(url).hostname.toLowerCase(); } catch (e) { return 'That isn\'t a complete link.'; }
  const adobe = /(^|\.)(adobe\.com|adobesign\.com|echosign\.com)$/.test(host);
  if (adobe && /\/public\/esignWidget\?wid=[A-Za-z0-9*_.-]+/.test(url)) return '';
  if (adobe) return 'This looks like one person\'s agreement, not the public web form. Ask John for the web form link.';
  return 'This isn\'t an Adobe web form link. Test it to be sure.';
}

function mediaThumb(path, f, v) {
  const key = keyOf(path);
  const sp = schemaPath(path);
  let src = v;
  let what = 'Photo';
  if (f.type === 'video') {
    const parent = getAt(S.draft, path.slice(0, -1)) || {};
    if (parent.photo) { src = parent.photo; what = 'Shows your photo instead of the video'; } else { src = parent.poster || ''; what = parent.video ? 'Shows the video' : 'Shows a photo'; }
  }
  return h('button.media-thumb', { type: 'button', onclick: () => go('#/photo/' + encodeURIComponent(key)) },
    src ? h('img', { src: '/' + src, alt: '', loading: 'lazy' }) : h('span.noimg', { text: 'No photo' }),
    h('span', h('strong', { text: f.type === 'video' ? 'Video' : 'Photo' }), h('span.small', { text: f.type === 'video' ? what : 'Change photo' })), icon('chev'));
}

// ------------------------------------------------------------------ words with marks

/**
 * Words with Highlight (orange) and Bold where the design allows, locked chips for settings
 * ("Main phone: 812-774-5049"), and fixed line breaks in headlines.
 */
function richControl(path, f, refresh) {
  const marks = f.marks || [];
  const multiline = marks.includes('br');
  const ed = h('div.rich.input', { contentEditable: 'true', role: 'textbox', 'aria-multiline': String(multiline), spellcheck: true, autocapitalize: f.caps ? 'characters' : 'sentences', autocorrect: 'on', tabIndex: 0 });
  if (f.caps) ed.classList.add('caps');
  const draw = () => { clear(ed); runsToDom(getAt(S.draft, path), ed); };
  draw();
  const read = () => {
    const runs = domToRuns(ed);
    setVal(path, runs);
    refresh();
    syncTools();
  };
  ed.addEventListener('input', read);
  ed.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    if (multiline) { insertNode(ed, document.createElement('br')); read(); }
  });
  ed.addEventListener('paste', (e) => {
    e.preventDefault();
    const t = (e.clipboardData || window.clipboardData).getData('text/plain').replace(/[\u0000-\u0008\u000B-\u001F\u007F]/g, '').replace(/\s*[\r\n]+\s*/g, ' ');
    insertNode(ed, document.createTextNode(t));
    read();
  });
  ed.addEventListener('drop', (e) => e.preventDefault());

  const tools = h('div.rich-tools');
  const btns = {};
  const keepSel = (b) => { b.addEventListener('pointerdown', (e) => e.preventDefault()); b.addEventListener('mousedown', (e) => e.preventDefault()); return b; };
  if (marks.includes('hl')) tools.append(btns.hl = keepSel(h('button.tool.hl', { type: 'button', text: 'Orange', 'aria-pressed': 'false', onclick: () => toggleMark('hl') })));
  if (marks.includes('b')) tools.append(btns.b = keepSel(h('button.tool.b', { type: 'button', text: 'Bold', 'aria-pressed': 'false', onclick: () => toggleMark('b') })));
  if (marks.includes('call')) tools.append(btns.call = keepSel(h('button.tool', { type: 'button', text: 'Call link', 'aria-pressed': 'false', onclick: () => toggleMark('call') })));
  if (multiline) tools.append(keepSel(h('button.tool', { type: 'button', text: 'New line', onclick: () => { ed.focus(); insertNode(ed, document.createElement('br')); read(); } })));
  const chips = f.chips || [];
  if (chips.length) {
    tools.append(keepSel(h('button.tool', { type: 'button', text: 'Insert a setting', onclick: async () => {
      const sel = saveSel(ed);
      const pick = await sheet('Insert a setting', 'It updates by itself when the setting changes.', chips.map((c) => ({ label: chipText(c), value: c })).concat([{ label: 'Cancel', value: null, kind: 'ghost' }]));
      if (!pick) return;
      restoreSel(ed, sel);
      insertNode(ed, chipNode({ ref: pick }));
      read();
    } })));
  }
  function toggleMark(mark) {
    const sel = window.getSelection();
    if (!sel.rangeCount || !ed.contains(sel.anchorNode)) { toast('Select the words first.', 'warn'); return; }
    const range = sel.getRangeAt(0);
    const inMark = closestMark(sel.anchorNode, ed, mark);
    if (inMark) { unwrap(inMark); read(); return; }
    if (range.collapsed) { toast('Select the words first.', 'warn'); return; }
    if (mark === 'hl') {
      const count = ed.querySelectorAll('mark').length;
      const max = Math.max(2, ...[getAt(S.live.content, path)].filter(Array.isArray).map((r) => r.filter((x) => x && x.hl !== undefined).length));
      if (count >= max) { toast(`This spot can have at most ${max} orange phrases. Remove one first.`, 'warn', 5000); return; }
    }
    const wrapEl = document.createElement(mark === 'hl' ? 'mark' : mark === 'b' ? 'strong' : 'span');
    if (mark === 'call') wrapEl.className = 'call';
    const frag = range.extractContents();
    // no marks inside marks
    frag.querySelectorAll && frag.querySelectorAll('mark,strong,b,span.call').forEach(unwrap);
    wrapEl.appendChild(frag);
    range.insertNode(wrapEl);
    sel.removeAllRanges();
    const r = document.createRange();
    r.selectNodeContents(wrapEl);
    sel.addRange(r);
    read();
  }
  function syncTools() {
    if (!ed.isConnected) { if (ed.dataset.seen) document.removeEventListener('selectionchange', syncTools); return; }
    ed.dataset.seen = '1';
    const sel = window.getSelection();
    const inside = sel.rangeCount && ed.contains(sel.anchorNode);
    for (const [m, b] of Object.entries(btns)) b.setAttribute('aria-pressed', String(!!(inside && closestMark(sel.anchorNode, ed, m))));
  }
  document.addEventListener('selectionchange', syncTools);
  return { el: h('div.rich-wrap', tools.childNodes.length ? tools : null, ed), focus: () => ed.focus(), labelFor: null };
}

function closestMark(node, root, mark) {
  const tag = { hl: 'MARK', b: 'STRONG' }[mark];
  for (let n = node; n && n !== root; n = n.parentNode) {
    if (n.nodeType === 1 && (n.tagName === tag || (mark === 'b' && n.tagName === 'B') || (mark === 'call' && n.classList.contains('call')))) return n;
  }
  return null;
}
function unwrap(el) { const p = el.parentNode; while (el.firstChild) p.insertBefore(el.firstChild, el); p.removeChild(el); }
function insertNode(ed, node) {
  const sel = window.getSelection();
  if (!sel.rangeCount || !ed.contains(sel.anchorNode)) { ed.appendChild(node); return; }
  const r = sel.getRangeAt(0);
  r.deleteContents();
  r.insertNode(node);
  r.setStartAfter(node);
  r.collapse(true);
  sel.removeAllRanges();
  sel.addRange(r);
}
function saveSel(ed) { const s = window.getSelection(); return s.rangeCount && ed.contains(s.anchorNode) ? s.getRangeAt(0).cloneRange() : null; }
function restoreSel(ed, r) { ed.focus(); if (!r) return; const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }

export function chipText(name) {
  const label = (S.schema.chips || {})[name] || name;
  const v = ref(name);
  return v ? `${label}: ${v}` : label;
}
function chipNode(r) {
  const el = h('span.chip', { contentEditable: 'false', dataset: { ref: r.ref, cap: r.cap ? '1' : '' }, title: 'Changes with the setting. Tap the setting to change it.', text: chipText(r.ref) });
  if (r.hl === true) { const m = document.createElement('mark'); m.appendChild(el); return m; }
  if (r.b === true) { const m = document.createElement('strong'); m.appendChild(el); return m; }
  return el;
}
export function runsToDom(v, el) {
  if (v === null || v === undefined) return;
  const runs = typeof v === 'string' ? [v] : Array.isArray(v) ? v : [String(v)];
  for (const r of runs) {
    if (typeof r === 'string') el.appendChild(document.createTextNode(r));
    else if (r.ref) el.appendChild(chipNode(r));
    else if (r.hl !== undefined) el.appendChild(h('mark', { text: r.hl }));
    else if (r.b !== undefined) el.appendChild(h('strong', { text: r.b }));
    else if (r.call !== undefined) el.appendChild(h('span.call', { text: r.call }));
    else if (r.br) el.appendChild(document.createElement('br'));
  }
}
/** The editor's DOM back to runs (merging neighbours, dropping empty bits). */
export function domToRuns(ed) {
  const out = [];
  const push = (mark, text) => {
    text = text.replace(/ /g, ' ').replace(/[​﻿]/g, '');
    if (text === '') return;
    const last = out[out.length - 1];
    if (mark === '' && typeof last === 'string') { out[out.length - 1] = last + text; return; }
    if (mark !== '' && last && typeof last === 'object' && last[mark] !== undefined && typeof last[mark] === 'string') { last[mark] += text; return; }
    out.push(mark === '' ? text : { [mark]: text });
  };
  const walk = (node, mark) => {
    for (const n of node.childNodes) {
      if (n.nodeType === 3) { push(mark, n.nodeValue); continue; }
      if (n.nodeType !== 1) continue;
      if (n.classList.contains('chip')) {
        const r = { ref: n.dataset.ref };
        if (n.dataset.cap) r.cap = true;
        if (mark === 'hl' || mark === 'b') r[mark] = true;
        out.push(r);
        continue;
      }
      if (n.tagName === 'BR') { if (out.length) out.push({ br: true }); continue; }
      if ((n.tagName === 'DIV' || n.tagName === 'P') && out.length) out.push({ br: true });
      const m = n.tagName === 'MARK' ? 'hl' : (n.tagName === 'STRONG' || n.tagName === 'B') ? 'b' : n.classList.contains('call') ? 'call' : mark;
      walk(n, mark && m !== mark && m !== '' ? mark : m);
    }
  };
  walk(ed, '');
  while (out.length && out[out.length - 1].br) out.pop();
  if (out.length === 0) return '';
  if (out.length === 1 && typeof out[0] === 'string') return out[0];
  return out;
}
