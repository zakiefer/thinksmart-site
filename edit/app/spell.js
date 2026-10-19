// The "Check your changes" spelling check: only words she changed, against the built-in word
// list (Webster's 2nd + modern, insurance and building words + the website's own words) and the
// words she taught. It warns but never blocks.
import { S, api, getAt, keyOf, schemaPath, field, plain } from './core.js';
import { cut } from './lists.js';

let list = null;
async function words() {
  if (list) return list;
  const res = await fetch('/edit/app/words.json', { credentials: 'same-origin' });
  const j = await res.json();
  list = new Set(j.words.split('\n'));
  return list;
}

const TEXT = new Set(['text', 'para', 'rich', 'alt', 'name']);

/** Is this word in the list, allowing plurals, -ed, -ing, -er, -est, -ly and common prefixes? */
export function known(w, W) {
  if (w.length < 3 || W.has(w)) return true;
  const t = [];
  const end = (s) => w.endsWith(s);
  const base = (n, add = '') => t.push(w.slice(0, w.length - n) + add);
  if (end("'s") || end("s'")) base(2);
  if (end('s')) base(1);
  if (end('es')) base(2);
  if (end('ies')) base(3, 'y');
  if (end('ed')) { base(2); base(1); if (end('ied')) base(3, 'y'); if (/(.)\1ed$/.test(w)) base(3); }
  if (end('ing')) { base(3); base(3, 'e'); if (/(.)\1ing$/.test(w)) base(4); }
  if (end('er') || end('ers')) { base(end('ers') ? 3 : 2); base(end('ers') ? 2 : 1); if (end('ier')) base(3, 'y'); }
  if (end('est')) { base(3); base(2); if (end('iest')) base(4, 'y'); }
  if (end('ly')) { base(2); if (end('ily')) base(3, 'y'); if (end('ally')) base(4); if (end('ically')) base(4); }
  if (end('ness')) base(4);
  if (end('ment') || end('ments')) base(end('ments') ? 5 : 4);
  if (end('able')) { base(4); base(4, 'e'); }
  for (const p of ['un', 're', 'pre', 'non', 'over', 'under', 'mis', 'out', 'self-', 'co']) if (w.startsWith(p) && w.length - p.length > 3) t.push(w.slice(p.length));
  return t.some((x) => x.length > 1 && (W.has(x) || W.has(x.replace(/-$/, ''))));
}

function tokens(text) {
  return (text.match(/[A-Za-z][A-Za-z'’-]*[A-Za-z]|[A-Za-z]/g) || []).map((x) => x.replace(/’/g, "'"));
}

/** Words in her changes that the list doesn't know: [{word, key, path, label}] */
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
    for (const raw of tokens(plain(value))) {
      const low = raw.toLowerCase();
      if (before.has(low) || taught.has(low) || seen.has(low)) continue;
      if (/^[A-Z]{2,4}$/.test(raw)) continue; // short capitals: ACV, RCV, LLC, state codes
      const parts = low.split('-').filter(Boolean);
      if (parts.every((p) => known(p, W) || taught.has(p)) || known(low, W)) continue;
      seen.add(low);
      out.push({ word: raw, key: keyOf(path), path, label: cut(f.label, 60) });
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
