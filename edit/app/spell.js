// The "Check your changes" spelling check: only words she changed, against the built-in word
// list (Webster's 2nd + modern, insurance and building words + the website's own words) and the
// words she taught. It warns but never blocks.
import { S, api, getAt, keyOf, schemaPath, field, plain } from './core.js';
import { cut } from './lists.js';

// in the big list, but on this website they're almost always a typo ("realy", "untill", "pubic")
const NEVER = new Set(['rea', 'pubic', 'clame', 'shure', 'wether', 'untill', 'truely']);

let list = null;
async function words() {
  if (list) return list;
  const res = await fetch('/edit/app/words.json', { credentials: 'same-origin' });
  const j = await res.json();
  list = new Set(j.words.split('\n'));
  for (const w of NEVER) list.delete(w);
  return list;
}

const TEXT = new Set(['text', 'para', 'rich', 'alt', 'name']);

/** Is this word in the list, allowing plurals, -ed, -ing, -er, -est, -ly and common prefixes? */
export function known(w, W) {
  if (NEVER.has(w)) return false;
  if (w.length < 3 || W.has(w)) return true;
  const t = [];
  const end = (s) => w.endsWith(s);
  const base = (n, add = '') => t.push(w.slice(0, w.length - n) + add);
  // "bigest", "hoter": short words like big, hot, stop double their last letter first
  const SHORT = /^[^aeiou]*[aeiou][^aeiouwxy]$/;
  const bare = (n) => { const b = w.slice(0, w.length - n); if (!SHORT.test(b)) t.push(b); };
  if (end("'s") || end("s'")) base(2);
  if (end('s')) base(1);
  if (end('es')) base(2);
  if (end('ies')) base(3, 'y');
  if (end('ed')) { bare(2); base(1); if (end('ied')) base(3, 'y'); if (/(.)\1ed$/.test(w)) base(3); }
  if (end('ing')) { bare(3); base(3, 'e'); if (/(.)\1ing$/.test(w)) base(4); }
  if (end('er') || end('ers')) { bare(end('ers') ? 3 : 2); base(end('ers') ? 2 : 1); if (end('ier')) base(3, 'y'); if (/(.)\1ers?$/.test(w)) base(end('ers') ? 4 : 3); }
  if (end('est')) { bare(3); base(2); if (end('iest')) base(4, 'y'); if (/(.)\1est$/.test(w)) base(4); }
  if (end('ly')) { base(2); if (end('ily')) base(3, 'y'); if (end('ally')) base(4); if (end('ically')) base(4); }
  if (end('ness')) base(4);
  if (end('ment') || end('ments')) base(end('ments') ? 5 : 4);
  if (end('able')) { base(4); base(4, 'e'); }
  for (const p of ['un', 're', 'pre', 'non', 'over', 'under', 'mis', 'out', 'self-', 'co']) if (w.startsWith(p) && w.length - p.length > 3) t.push(w.slice(p.length));
  return t.some((x) => x.length > 1 && !NEVER.has(x) && (W.has(x) || W.has(x.replace(/-$/, ''))));
}

const WORD = /[A-Za-z][A-Za-z'’-]*[A-Za-z]|[A-Za-z]/g;
function tokens(text) {
  return (text.match(WORD) || []).map((x) => x.replace(/’/g, "'"));
}

/** The words around the first place `at` starts: up to 30 letters each side, whole words. */
function around(text, at, len) {
  let before = text.slice(Math.max(0, at - 30), at);
  const cutBefore = at > 30;
  if (cutBefore) before = before.replace(/^\S*\s+/, '');
  let after = text.slice(at + len, at + len + 30);
  const cutAfter = at + len + 30 < text.length;
  if (cutAfter) after = after.replace(/\s+\S*$/, '');
  return { before, after, cutBefore, cutAfter };
}

/** Words in her changes that the list doesn't know: [{word, key, path, label, ctx}] */
export async function spellCheck(ops) {
  let W;
  try { W = await words(); } catch (e) { return []; }
  const taught = new Set([...S.words].map((x) => x.toLowerCase()));
  const out = [];
  const seen = new Set();
  const look = (path, value, oldValue) => {
    const sp = schemaPath(path);
    const f = field(sp);
    if (!f || !TEXT.has(f.type)) return;
    const before = new Set(tokens(plain(oldValue, S.live.content)).map((x) => x.toLowerCase()));
    const text = plain(value);
    for (const m of text.matchAll(WORD)) {
      const raw = m[0].replace(/’/g, "'");
      const low = raw.toLowerCase();
      if (before.has(low) || taught.has(low) || seen.has(low)) continue;
      if (/^[A-Z]{2,4}$/.test(raw)) continue; // short capitals: ACV, RCV, LLC, state codes
      const parts = low.split('-').filter(Boolean);
      if (parts.every((p) => known(p, W) || taught.has(p)) || known(low, W)) continue;
      seen.add(low);
      out.push({ word: raw, key: keyOf(path), path, label: cut(f.label, 60), ctx: { word: raw, ...around(text, m.index, m[0].length) } });
    }
  };
  const walkItem = (path, item) => {
    for (const [k, v] of Object.entries(item || {})) {
      if (k === '_id') continue;
      const p = path.concat([k]);
      if (Array.isArray(v) && v.length && v[0] && typeof v[0] === 'object' && v[0]._id) v.forEach((it) => walkItem(p.concat([{ id: it._id }]), it));
      else look(p, v, null);
    }
  };
  for (const op of ops) {
    if (op.op === 'set') look(op.path, op.value, op.old);
    else for (const it of Object.values(op.add || {})) walkItem(op.path.concat([{ id: it._id }]), getAt(S.draft, op.path.concat([{ id: it._id }])) || it);
  }
  return out;
}

export async function teach(word) {
  const r = await api('words-add', { word });
  S.words = new Set(r.words);
}
